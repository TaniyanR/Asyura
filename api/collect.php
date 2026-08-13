<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Asyura\Security;
use Asyura\Tracker;

Security::headers('api');
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Vary: Origin');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false]);
    exit;
}

$raw = file_get_contents('php://input', false, null, 0, 32768);
$payload = json_decode($raw ?: '', true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false]);
    exit;
}

try {
    $tracker = new Tracker($db, $config);
    $site = $tracker->findSite((string) ($payload['site_id'] ?? ''), (string) ($payload['site_key'] ?? ''));
    if (!$site) {
        throw new RuntimeException('Invalid site.');
    }
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? ''));
    if (!$tracker->originAllowed($site, $origin)) {
        throw new RuntimeException('Origin rejected.');
    }
    header('Access-Control-Allow-Origin: ' . $origin);
    $tracker->record($site, $payload);
    http_response_code(202);
    echo json_encode(['ok' => true]);
} catch (Throwable) {
    http_response_code(403);
    echo json_encode(['ok' => false]);
}
