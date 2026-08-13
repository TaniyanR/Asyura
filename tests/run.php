<?php
declare(strict_types=1);

require dirname(__DIR__).'/src/UrlNormalizer.php';

use Asyura\UrlNormalizer;

$tests=[
    'https://example.com'=>'https://example.com/',
    'http://www.example.com/'=>'https://example.com/',
    'https://example.com/?utm_source=x'=>'https://example.com/',
    'https://example.com/news/?b=2&a=1&utm_medium=social'=>'https://example.com/news?a=1&b=2',
];
$failed=0;
foreach($tests as $input=>$expected){$actual=UrlNormalizer::normalize($input);if($actual!==$expected){fwrite(STDERR,"FAIL {$input}\n expected: {$expected}\n actual:   {$actual}\n");$failed++;}else{echo "OK {$input}\n";}}
if($failed){exit(1);}echo "All tests passed.\n";
