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
if($failed){exit(1);}echo "All tests passed.\n";
