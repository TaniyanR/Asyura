<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Asyura\Auth;
use Asyura\Security;
use Asyura\SettingsTransferService;

Auth::requireLogin($config['app_url']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Security::verifyCsrf($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Invalid request');
}

$json = json_encode((new SettingsTransferService($db))->export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$fileName = 'asyura-settings-' . date('Ymd-His') . '.json';
Security::headers();
header('Content-Type: application/json; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('Content-Length: ' . strlen($json));
echo $json;
