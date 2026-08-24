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
if($failed){exit(1);}echo "All tests passed.\n";
