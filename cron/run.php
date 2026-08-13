<?php
declare(strict_types=1);

require dirname(__DIR__).'/src/bootstrap.php';

use Asyura\DistributionService;
use Asyura\RssService;

$cli=PHP_SAPI==='cli';if(!$cli){header('Content-Type: application/json; charset=utf-8');header('X-Robots-Tag: noindex,nofollow,noarchive');if(!hash_equals((string)$config['cron_key'],(string)($_GET['key']??''))){http_response_code(403);echo json_encode(['ok'=>false]);exit;}}
$lock=(int)$db->query("SELECT GET_LOCK('asyura_cron',0)")->fetchColumn();if(!$lock){$out=['ok'=>false,'message'=>'cron is already running'];echo $cli?json_encode($out,JSON_UNESCAPED_UNICODE).PHP_EOL:json_encode($out,JSON_UNESCAPED_UNICODE);exit;}
$db->prepare("INSERT INTO cron_runs (task_name,status) VALUES ('all','running')")->execute();$runId=(int)$db->lastInsertId();
try{$rss=(new RssService($db))->fetchDue();$distribution=new DistributionService($db);$siteIds=$db->query('SELECT id FROM sites WHERE active=1 AND rss_enabled=1')->fetchAll(PDO::FETCH_COLUMN);foreach($siteIds as $siteId)$distribution->calculate((int)$siteId);
    $rawDays=(int)setting('raw_retention_days',180);$distDays=(int)setting('distribution_retention_days',180);$aggDays=(int)setting('aggregate_retention_days',1095);$rssDays=(int)setting('rss_item_retention_days',3);
    $db->exec("DELETE FROM raw_events WHERE occurred_at < NOW()-INTERVAL {$rawDays} DAY");$db->exec("DELETE FROM daily_visitors WHERE visit_date < CURDATE()-INTERVAL 3 DAY");$db->exec("DELETE FROM daily_referrer_visitors WHERE visit_date < CURDATE()-INTERVAL 3 DAY");$db->exec("DELETE FROM rss_distribution_history WHERE calculated_at < NOW()-INTERVAL {$distDays} DAY");$db->exec("DELETE FROM rss_distribution_batches WHERE calculated_at < NOW()-INTERVAL {$distDays} DAY");$db->exec("DELETE FROM daily_stats WHERE stat_date < CURDATE()-INTERVAL {$aggDays} DAY");$db->exec("DELETE FROM daily_link_stats WHERE stat_date < CURDATE()-INTERVAL {$aggDays} DAY");$db->exec("DELETE FROM referrer_stats WHERE stat_date < CURDATE()-INTERVAL {$aggDays} DAY");$db->exec("DELETE FROM rss_items WHERE fetched_at < NOW()-INTERVAL {$rssDays} DAY");$db->exec("DELETE FROM login_attempts WHERE attempted_at < NOW()-INTERVAL 1 DAY");$db->exec("DELETE FROM cron_runs WHERE started_at < NOW()-INTERVAL 365 DAY");
    $message='RSS成功 '.$rss['success'].' / 失敗 '.$rss['failed'].' / 配分 '.count($siteIds).'サイト';$db->prepare("UPDATE cron_runs SET status='success',message=?,finished_at=NOW() WHERE id=?")->execute([$message,$runId]);$out=['ok'=>true,'message'=>$message];
}catch(Throwable $e){$db->prepare("UPDATE cron_runs SET status='failed',message=?,finished_at=NOW() WHERE id=?")->execute([mb_substr($e->getMessage(),0,5000),$runId]);$out=['ok'=>false,'message'=>'cron failed'];if($cli)fwrite(STDERR,$e->getMessage().PHP_EOL);}
$db->query("SELECT RELEASE_LOCK('asyura_cron')");echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).($cli?PHP_EOL:'');
