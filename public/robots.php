<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
header('Content-Type: text/plain; charset=utf-8');$prefix=rtrim((string)parse_url($config['app_url'],PHP_URL_PATH),'/');echo "User-agent: *\nDisallow: {$prefix}/admin/\nDisallow: {$prefix}/api/\nDisallow: {$prefix}/config/\nDisallow: {$prefix}/cron/\nDisallow: {$prefix}/src/\nDisallow: {$prefix}/storage/\nDisallow: {$prefix}/public/status.php\nSitemap: ".app_url('sitemap.xml')."\n";
