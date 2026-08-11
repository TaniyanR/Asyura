<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Asyura\WidgetRenderer;

$type=$widgetType??'';$renderer=new WidgetRenderer($db,$config);$widget=$renderer->find((string)($_GET['id']??''),$type);
header('X-Content-Type-Options: nosniff');header('X-Robots-Tag: noindex,nofollow,noarchive');header("Content-Security-Policy: default-src 'none'; img-src https: data:; style-src 'unsafe-inline'; frame-ancestors *; base-uri 'none'");
if(!$widget){http_response_code(404);exit;}
$items=match($type){'ranking'=>$renderer->ranking($widget),'links'=>$renderer->links($widget),'rss'=>$renderer->rss($widget),'notices'=>$renderer->notices($widget),default=>[]};
?><!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><style>html,body{margin:0;padding:0;background:transparent}*{box-sizing:border-box}<?= $widget['custom_css'] ?></style></head><body><div class="asyura-<?= e($type) ?>" data-asyura-widget="<?= (int)$widget['id'] ?>"><?= $renderer->render($widget,$items) ?></div></body></html>
