<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Asyura\Security;
use Asyura\Tracker;
use Asyura\TrackingRejectedException;

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
    (new Tracker($db, $config))->logSecurity(null, 'invalid_payload', 'warning', 'JSONとして解釈できない計測データを拒否しました。', (string)($_SERVER['HTTP_ORIGIN'] ?? ''));
    http_response_code(400);
    echo json_encode(['ok' => false]);
    exit;
}

try {
    $tracker = new Tracker($db, $config);
    $site = $tracker->findSite((string) ($payload['site_id'] ?? ''), (string) ($payload['site_key'] ?? ''));
    if (!$site) {
        $tracker->logSecurity(null, 'invalid_site_key', 'critical', '無効なサイトIDまたはサイトキーです。', (string)($_SERVER['HTTP_ORIGIN'] ?? ''));
        throw new TrackingRejectedException('Invalid site.', 403, 'invalid_site_key');
    }
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? ''));
    if (!$tracker->originAllowed($site, $origin)) {
        $tracker->logSecurity((int)$site['id'], 'origin_rejected', 'critical', '登録されていない送信元を拒否しました。', $origin);
        throw new TrackingRejectedException('Origin rejected.', 403, 'origin_rejected');
    }
    header('Access-Control-Allow-Origin: ' . $origin);
    $tracker->enforceRateLimit($site);
    $tracker->record($site, $payload);
    http_response_code(202);
    echo json_encode(['ok' => true]);
} catch (TrackingRejectedException $e) {
    if (isset($tracker) && isset($site) && $site && !in_array($e->eventCode(), ['origin_rejected','rate_limit'], true)) {
        $tracker->logSecurity((int)$site['id'], $e->eventCode(), 'warning', $e->getMessage(), (string)($_SERVER['HTTP_ORIGIN'] ?? ''));
    }
    http_response_code($e->status());
    echo json_encode(['ok' => false]);
} catch (Throwable $e) {
    error_log('[Asyura collect] '.$e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false]);
}
