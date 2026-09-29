<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/ConfigRecovery.php';
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); echo "OK $message\n"; }
class RecoveryTestDb extends PDO {
    public function __construct() { parent::__construct('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); }
    public function query(string $query, ?int $fetchMode=null, mixed ...$args): PDOStatement|false {
        if ($query === 'SHOW TABLES') $query = "SELECT name FROM sqlite_master WHERE type='table'";
        return parent::query($query, $fetchMode, ...$args);
    }
}
$db = new RecoveryTestDb();
check(!Asyura\ConfigRecovery::isExisting($db), 'empty database detected as first installation');
function rejected(PDO $db): bool {
    try { Asyura\ConfigRecovery::validate($db); return false; }
    catch (InvalidArgumentException) { return true; }
}
check(rejected($db), 'empty database rejected without creating tables');
check((int)$db->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table'")->fetchColumn() === 0, 'no schema written');
$db->exec('CREATE TABLE admins(id INTEGER, password_hash TEXT); CREATE TABLE sites(id INTEGER, name TEXT)');
check(rejected($db), 'database without existing administrator rejected');
$db->exec("INSERT INTO admins VALUES(1,'existing-hash'); INSERT INTO sites VALUES(1,'existing-site')");
check(Asyura\ConfigRecovery::isExisting($db), 'existing installation automatically uses recovery');
Asyura\ConfigRecovery::validate($db);
check($db->query('SELECT password_hash FROM admins')->fetchColumn() === 'existing-hash', 'existing login preserved');
check($db->query('SELECT name FROM sites')->fetchColumn() === 'existing-site', 'existing site preserved');
echo "Config recovery tests passed\n";
