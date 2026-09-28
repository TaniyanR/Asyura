<?php
declare(strict_types=1);
spl_autoload_register(static function(string $class): void {
    if (str_starts_with($class, 'Asyura\\')) require dirname(__DIR__).'/src/'.substr($class, 7).'.php';
});
final class AuthTestDb extends PDO {
    public function __construct() {
        parent::__construct('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        parent::exec('PRAGMA foreign_keys=ON');
        parent::exec('CREATE TABLE sites (id INTEGER PRIMARY KEY, search_console_property TEXT)');
        parent::exec('CREATE TABLE children (site_id INTEGER REFERENCES sites(id) ON DELETE CASCADE)');
        parent::exec('CREATE TABLE search_console_auth (id INTEGER PRIMARY KEY, client_id TEXT, client_secret_enc TEXT, access_token_enc TEXT, refresh_token_enc TEXT, token_expires_at TEXT, connected_at TEXT)');
        parent::exec('INSERT INTO search_console_auth(id) VALUES(1)');
    }
    public function exec(string $statement): int|false {
        if (str_starts_with($statement, 'CREATE TABLE IF NOT EXISTS search_console_auth') || str_starts_with($statement, 'INSERT IGNORE')) return 0;
        return parent::exec($statement);
    }
    public function query(string $query, ?int $fetchMode=null, mixed ...$args): PDOStatement|false {
        if (str_starts_with($query, 'SHOW COLUMNS')) $query='SELECT 1';
        return parent::query($query, $fetchMode, ...$args);
    }
    public function prepare(string $query, array $options=[]): PDOStatement|false {
        return parent::prepare(str_replace('NOW()', 'CURRENT_TIMESTAMP', $query), $options);
    }
}
function check(bool $ok, string $label): void { if (!$ok) throw new RuntimeException($label); echo "OK $label\n"; }
$db=new AuthTestDb(); $config=['app_key'=>'test-only-original-key'];
$responses=[]; $requests=[];
$transport=static function($method,$url,$body,$headers) use (&$responses,&$requests) {
    $requests[]=[$url,$body];
    if (!$responses) throw new RuntimeException('Unexpected HTTP request');
    return array_shift($responses);
};
$s=new Asyura\SearchConsoleService($db,$config,$transport);
$s->saveCredentials('client','secret');
$responses=[[200,json_encode(['access_token'=>'access','refresh_token'=>'refresh','expires_in'=>3600])]];
$s->exchangeCode('code','https://example.test/callback');
$row=static fn()=>$db->query('SELECT * FROM search_console_auth')->fetch();
$original=$row();
$s->saveCredentials('client',null); check($row()===$original,'blank secret preserves credentials and tokens');
$s->saveCredentials('client','secret'); check($row()===$original,'identical credentials preserve connection');
$wrong=new Asyura\SearchConsoleService($db,['app_key'=>'different-key'],$transport);
check($wrong->status()['has_client_secret'] && !$wrong->status()['client_secret_readable'],'changed key distinguished from missing secret');
try { $wrong->saveCredentials('client',null); throw new LogicException('Should reject unreadable secret'); } catch (InvalidArgumentException $e) {}
check($row()===$original,'unreadable secret is never cleared');
$db->exec("UPDATE search_console_auth SET access_token_enc='corrupt' WHERE id=1");
$responses=[[200,json_encode(['access_token'=>'new-access','refresh_token'=>'rotated-refresh','expires_in'=>3600])],[200,'{"siteEntry":[{"siteUrl":"https://example.test/"}]}']];
check(count($s->listProperties())===1,'unreadable cached access token refreshes successfully');
$db->exec("UPDATE search_console_auth SET token_expires_at='2000-01-01' WHERE id=1");
$responses=[[200,'{"access_token":"next-access"}'],[200,'{}']];
$s->listProperties(); parse_str($requests[count($requests)-2][1],$form);
check($form['refresh_token']==='rotated-refresh','rotated refresh token persisted and reused');
$before=$row();
$db->exec("UPDATE search_console_auth SET token_expires_at='2000-01-01' WHERE id=1"); $before=$row();
$responses=[[400,'{"error":"invalid_grant","error_description":"sensitive debug value"}']];
try { $s->listProperties(); throw new LogicException('Expected invalid_grant'); } catch (RuntimeException $e) { check(str_contains($e->getMessage(),'再接続')&&!str_contains($e->getMessage(),'sensitive'),'expired permission has actionable safe error'); }
check($row()===$before,'Google error preserves secret and refresh token');
$responses=[[503,'{}']];
try { $s->listProperties(); throw new LogicException('Expected 503'); } catch (RuntimeException $e) { check($e->getCode()===503,'temporary API error remains distinct'); }
check($row()===$before,'temporary API error preserves connection');
$responses=[[401,'{"error":"invalid_client"}']];
try { $s->listProperties(); throw new LogicException('Expected invalid_client'); } catch (RuntimeException $e) { check(str_contains($e->getMessage(),'照合'),'invalid client has credential-specific guidance'); }
check($row()===$before,'invalid client does not erase stored credentials');
$noKey=new Asyura\SearchConsoleService($db,[], $transport);
try { $noKey->saveCredentials('new','new-secret'); throw new LogicException('Expected missing key rejection'); } catch (RuntimeException $e) { check(str_contains($e->getMessage(),'app_key'),'missing encryption key rejects writes'); }
check($row()===$before,'missing key does not overwrite credentials');
$db->exec("UPDATE search_console_auth SET token_expires_at='2099-01-01' WHERE id=1");
$responses=[[401,'{}'],[200,'{"access_token":"recovered"}'],[200,'{}']];
$s->listProperties(); check($responses===[],'API 401 refreshes and retries once');
$responses=[[403,'{}']];
try { $s->listProperties(); throw new LogicException('Expected 403'); } catch (RuntimeException $e) { check($e->getCode()===403,'API permission error does not refresh'); }
$responses=[[401,'{}'],[200,'{"access_token":"recovered-again"}'],[401,'{}']];
try { $s->listProperties(); throw new LogicException('Expected second 401'); } catch (RuntimeException $e) { check($e->getCode()===401 && $responses===[],'401 retry is bounded'); }
$db->exec("UPDATE search_console_auth SET token_expires_at='2000-01-01' WHERE id=1");
$racing=new Asyura\SearchConsoleService($db,$config,static function() use ($s) {
    $s->disconnect();
    return [200,'{"access_token":"late","refresh_token":"late-refresh"}'];
});
try { $racing->listProperties(); throw new LogicException('Expected concurrent disconnect'); } catch (RuntimeException $e) { check(str_contains($e->getMessage(),'更新されました'),'in-flight refresh cannot restore disconnected tokens'); }
check(!$s->status()['connected'],'disconnect remains effective');
$s->saveCredentials('client','changed-secret');
check(!$s->status()['connected'] && $s->status()['client_secret_readable'],'changed credentials invalidate old tokens');
$db->exec("INSERT INTO sites VALUES(1,'sc-domain:one.test'),(2,'sc-domain:two.test')");
$db->exec('INSERT INTO children VALUES(1),(2)');
$auth=$row();
(new Asyura\SiteService($db))->deletePermanently(1);
check((int)$db->query('SELECT COUNT(*) FROM sites')->fetchColumn()===1,'only selected site removed');
check((int)$db->query('SELECT site_id FROM children')->fetchColumn()===2,'dependent records cascade without affecting other sites');
check($auth===$row(),'site deletion retains global Google credentials');
// Exercise the dashboard action guard directly, without redirecting the test runner.
$controller=new Asyura\AdminController($db,$config);
$delete=new ReflectionMethod($controller,'deleteSite');
$_POST=['id'=>2,'confirm_delete'=>'no'];
try { $delete->invoke($controller); throw new LogicException('Expected confirmation rejection'); } catch (InvalidArgumentException $e) {}
check((int)$db->query('SELECT COUNT(*) FROM sites')->fetchColumn()===1,'server rejects deletion without confirmation');
echo "Search Console and deletion tests passed\n";
