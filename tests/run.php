<?php
declare(strict_types=1);

require dirname(__DIR__).'/src/UrlNormalizer.php';
require dirname(__DIR__).'/src/Security.php';

use Asyura\Security;
use Asyura\UrlNormalizer;

$tests=[
    'https://example.com'=>'https://example.com/',
    'http://www.example.com/'=>'https://example.com/',
    'https://example.com/?utm_source=x'=>'https://example.com/',
    'https://example.com/news/?b=2&a=1&utm_medium=social'=>'https://example.com/news?a=1&b=2',
];
$failed=0;
foreach($tests as $input=>$expected){$actual=UrlNormalizer::normalize($input);if($actual!==$expected){fwrite(STDERR,"FAIL {$input}\n expected: {$expected}\n actual:   {$actual}\n");$failed++;}else{echo "OK {$input}\n";}}
$requiredGroups=['Google・SEO','HTML・Web確認','GitHub','AI','その他ツール'];
$adminLinks=require dirname(__DIR__).'/config/admin_links.php';
foreach($requiredGroups as $group){if(!array_key_exists($group,$adminLinks)){fwrite(STDERR,"FAIL missing admin link group: {$group}\n");$failed++;}}
foreach($adminLinks as $group=>$links){foreach($links as $link){if(empty($link['label'])||Security::safeUrl($link['url']??'')===''){fwrite(STDERR,"FAIL invalid admin link in: {$group}\n");$failed++;}}}
$tracker=(string)file_get_contents(dirname(__DIR__).'/assets/tracker.js');
foreach(['__asyuraTrackerLoaded','sendBeacon','fetch(endpoint','pageview_id','engagement_ms'] as $needle){if(!str_contains($tracker,$needle)){fwrite(STDERR,"FAIL tracker feature missing: {$needle}\n");$failed++;}}
$migration=(string)file_get_contents(dirname(__DIR__).'/src/Migration.php');
foreach(['uq_event_dedup','analytics_sessions','analytics_pageviews','conversion_rules','tracking_security_events','tracking_rate_limits'] as $needle){if(!str_contains($migration,$needle)){fwrite(STDERR,"FAIL migration missing: {$needle}\n");$failed++;}}
$adminIndex=(string)file_get_contents(dirname(__DIR__).'/admin/index.php');
foreach(["'search_console'","'notices'","'contact'","'requests'"] as $needle){if(!str_contains($adminIndex,$needle)){fwrite(STDERR,"FAIL admin route missing: {$needle}\n");$failed++;}}
$accessReport=(string)file_get_contents(dirname(__DIR__).'/admin/access-report.php');
foreach(['classified_channel','landing_page','exit_page','NOW()-INTERVAL 30 MINUTE','site_id IS NULL','admin_login_failed'] as $needle){if(!str_contains($accessReport,$needle)){fwrite(STDERR,"FAIL access report missing: {$needle}\n");$failed++;}}
$auth=(string)file_get_contents(dirname(__DIR__).'/src/Auth.php');
foreach(['admin_login_failed','admin_login_rate_limit'] as $needle){if(!str_contains($auth,$needle)){fwrite(STDERR,"FAIL auth security log missing: {$needle}\n");$failed++;}}
$trackerPhp=(string)file_get_contents(dirname(__DIR__).'/src/Tracker.php');
foreach(["'ip|'","'visitor|'",'parse_url($page,PHP_URL_PATH)'] as $needle){if(!str_contains($trackerPhp,$needle)){fwrite(STDERR,"FAIL tracker PHP feature missing: {$needle}\n");$failed++;}}
foreach(['admin/export.php','admin/delete_year.php'] as $relative){$dataCode=(string)file_get_contents(dirname(__DIR__).'/'.$relative);foreach(['analytics_pageviews','analytics_sessions','conversion_events','tracking_security_events','daily_visitors','daily_referrer_visitors'] as $needle){if(!str_contains($dataCode,$needle)){fwrite(STDERR,"FAIL {$relative} missing data table: {$needle}\n");$failed++;}}}
if($failed){exit(1);}echo "All tests passed.\n";
