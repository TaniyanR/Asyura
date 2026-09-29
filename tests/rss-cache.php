<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/RssService.php';
class RssCacheDb extends PDO {
    public function __construct(){parent::__construct('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);}
    public function prepare(string $query,array $options=[]):PDOStatement|false{return parent::prepare(str_replace('NOW()','CURRENT_TIMESTAMP',$query),$options);}
}
$db=new RssCacheDb();
$db->exec('CREATE TABLE rss_items(feed_id INTEGER,site_id INTEGER); CREATE TABLE rss_feeds(id INTEGER,last_fetched_at TEXT,last_success_at TEXT,last_error TEXT); INSERT INTO rss_feeds(id) VALUES(1)');
$calls=[];
$service=new Asyura\RssService($db,static function($url,$etag,$modified)use(&$calls){$calls[]=[$etag,$modified];return ['status'=>304];});
$feed=['id'=>1,'site_id'=>2,'feed_url'=>'https://example.test/feed','etag'=>'saved-etag','last_modified'=>'saved-date'];
$service->fetchOne($feed);
if($calls[0]!==['',''])throw new RuntimeException('Missing cache must request full feed');
$db->exec('INSERT INTO rss_items VALUES(1,999)');
$service->fetchOne($feed);
if($calls[1]!==['',''])throw new RuntimeException('Another site must not count as cached');
$db->exec('INSERT INTO rss_items VALUES(1,2)');
$service->fetchOne($feed);
if($calls[2]!==['saved-etag','saved-date'])throw new RuntimeException('Existing cache should retain conditional requests');
echo "RSS cache recovery tests passed\n";
