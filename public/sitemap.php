<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
header('Content-Type: application/xml; charset=utf-8');$sites=$db->query('SELECT public_id,updated_at,links_enabled FROM sites WHERE active=1')->fetchAll();echo '<?xml version="1.0" encoding="UTF-8"?>';
?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><?php foreach($sites as $site):?><url><loc><?=e(app_url('public/notices.php?site='.rawurlencode($site['public_id'])))?></loc><lastmod><?=e(substr($site['updated_at'],0,10))?></lastmod></url><url><loc><?=e(app_url('public/inquiry.php?site='.rawurlencode($site['public_id'])))?></loc><lastmod><?=e(substr($site['updated_at'],0,10))?></lastmod></url><?php if($site['links_enabled']):?><url><loc><?=e(app_url('public/contact.php?site='.rawurlencode($site['public_id'])))?></loc><lastmod><?=e(substr($site['updated_at'],0,10))?></lastmod></url><?php endif?><?php endforeach?></urlset>
