<?php
declare(strict_types=1);

require dirname(__DIR__).'/src/bootstrap.php';

use Asyura\Auth;
use Asyura\Security;
use Asyura\SiteMetadataService;

Auth::requireLogin($config['app_url']);
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Security::verifyCsrf($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'message'=>'画面の有効期限が切れました。再読み込みしてください。'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $result = (new SiteMetadataService())->fetchName((string) ($_POST['url'] ?? ''));
    echo json_encode(['ok'=>true,'name'=>$result['name'],'source'=>$result['source']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code($e instanceof InvalidArgumentException ? 422 : 502);
    echo json_encode(['ok'=>false,'message'=>$e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
