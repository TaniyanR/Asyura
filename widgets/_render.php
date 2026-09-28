<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Asyura\WidgetRenderer;

$nonce=base64_encode(random_bytes(18));
$type=$widgetType??'';$renderer=new WidgetRenderer($db,$config);$widget=$renderer->find((string)($_GET['id']??''),$type);
header('X-Content-Type-Options: nosniff');header('X-Robots-Tag: noindex,nofollow,noarchive');header("Content-Security-Policy: default-src 'none'; img-src https: data:; style-src 'unsafe-inline'; connect-src 'self'; script-src 'nonce-{$nonce}'; frame-ancestors *; base-uri 'none'");
if(!$widget){http_response_code(404);exit;}
$widget=\Asyura\WidgetDesign::withDefaults($widget);
header('Cache-Control: no-store');
$items=match($type){'ranking'=>$renderer->ranking($widget),'links'=>$renderer->links($widget),'rss'=>$renderer->rss($widget),'notices'=>$renderer->notices($widget),default=>[]};
?><!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><style>html,body{margin:0;padding:0;background:transparent}*{box-sizing:border-box}<?= $widget['custom_css'] ?></style></head><body><div class="asyura-<?= e($type) ?>" data-asyura-widget="<?= (int)$widget['id'] ?>"><?= $renderer->render($widget,$items) ?></div><script type="application/json" id="asyura-click-targets" nonce="<?= e($nonce) ?>"><?= json_encode($renderer->clickTargets($widget,$items), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?></script><script nonce="<?= e($nonce) ?>" src="<?= e(app_url('assets/widget-clicks.js')) ?>"></script><script nonce="<?= e($nonce) ?>" src="<?= e(app_url('assets/widget-resize.js')) ?>"></script></body></html>
