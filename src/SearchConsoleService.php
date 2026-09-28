<?php
declare(strict_types=1);

namespace Asyura;

use PDO;
use RuntimeException;

final class SearchConsoleService
{
    private const SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const SITES_URL = 'https://www.googleapis.com/webmasters/v3/sites';

    public function __construct(private PDO $db, private array $config, private ?\Closure $transport = null) {}

    public function ensureSchema(): void
    {
        $this->db->exec("CREATE TABLE IF NOT EXISTS search_console_auth (
            id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
            client_id VARCHAR(255) NULL,
            client_secret_enc TEXT NULL,
            access_token_enc LONGTEXT NULL,
            refresh_token_enc LONGTEXT NULL,
            token_expires_at DATETIME NULL,
            connected_at DATETIME NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->db->exec("INSERT IGNORE INTO search_console_auth (id) VALUES (1)");

        $stmt = $this->db->query("SHOW COLUMNS FROM sites LIKE 'search_console_property'");
        if (!$stmt->fetch()) {
            $this->db->exec("ALTER TABLE sites ADD COLUMN search_console_property VARCHAR(2048) NULL AFTER rss_url");
        }
    }

    public function status(): array
    {
        $this->ensureSchema();
        $row = $this->db->query('SELECT client_id,client_secret_enc,refresh_token_enc,connected_at FROM search_console_auth WHERE id=1')->fetch() ?: [];
        $hasClientSecret = !empty($row['client_secret_enc']);
        $hasRefreshToken = !empty($row['refresh_token_enc']);
        $clientSecretReadable = $this->canDecrypt((string)($row['client_secret_enc'] ?? ''));
        $refreshTokenReadable = $this->canDecrypt((string)($row['refresh_token_enc'] ?? ''));
        $reconnectRequired = ($hasClientSecret && !$clientSecretReadable) || ($hasRefreshToken && !$refreshTokenReadable);
        return [
            'client_id' => (string) ($row['client_id'] ?? ''),
            'has_client_secret' => $hasClientSecret,
            'client_secret_readable' => $clientSecretReadable,
            'connected' => $hasRefreshToken && $clientSecretReadable && $refreshTokenReadable,
            'reconnect_required' => $reconnectRequired,
            'connected_at' => $row['connected_at'] ?? null,
            'recovery_message' => !$reconnectRequired ? '' : (
                !function_exists('openssl_decrypt') ? 'サーバーのOpenSSL拡張が無効です。有効にしてください。保存情報は削除していません。' : (
                    !$hasClientSecret ? 'クライアントシークレットが未保存です。入力して保存し、Googleアカウントを再接続してください。' : (!$clientSecretReadable
                        ? 'シークレットは保存されていますが復号できません。config/config.php の app_key が以前と同じか確認してください。元の設定を復元できない場合は、クライアントシークレットを再入力してください。その後Googleアカウントを再接続してください。'
                        : 'シークレットは保存済みです。接続トークンを復号できないため、Googleアカウントを再接続してください。シークレットの再入力は不要です。')
                )
            ),
        ];
    }

    public function saveCredentials(string $clientId, ?string $clientSecret): void
    {
        $this->ensureSchema();
        $clientId = trim($clientId);
        if ($clientId === '') throw new \InvalidArgumentException('Google OAuth クライアントIDを入力してください。');
        $current = $this->db->query('SELECT client_id,client_secret_enc FROM search_console_auth WHERE id=1')->fetch() ?: [];
        $currentClientId = trim((string)($current['client_id'] ?? ''));
        $currentSecret = (string)($current['client_secret_enc'] ?? '');
        if ($clientSecret !== null && trim($clientSecret) !== '') {
            // Browser autofill or re-saving identical credentials must not disconnect Google.
            if (hash_equals($currentClientId, $clientId) && $this->canDecrypt($currentSecret)
                && hash_equals($this->decrypt($currentSecret), trim($clientSecret))) return;
            $enc = $this->encrypt(trim($clientSecret));
            $stmt = $this->db->prepare('UPDATE search_console_auth SET client_id=?,client_secret_enc=?,access_token_enc=NULL,refresh_token_enc=NULL,token_expires_at=NULL,connected_at=NULL WHERE id=1');
            $stmt->execute([$clientId,$enc]);
        } else {
            if ($currentSecret === '') {
                throw new \InvalidArgumentException('Google OAuth クライアントシークレットを入力してください。');
            }
            if (!$this->canDecrypt($currentSecret)) {
                throw new \InvalidArgumentException('保存済みのクライアントシークレットを読み込めません。クライアントシークレットを再入力してください。');
            }
            if ($currentClientId !== '' && !hash_equals($currentClientId,$clientId)) {
                throw new \InvalidArgumentException('クライアントIDを変更する場合は、クライアントシークレットも再入力してください。');
            }
            $stmt = $this->db->prepare('UPDATE search_console_auth SET client_id=? WHERE id=1');
            $stmt->execute([$clientId]);
        }
    }

    public function disconnect(): void
    {
        $this->ensureSchema();
        $this->db->exec('UPDATE search_console_auth SET access_token_enc=NULL,refresh_token_enc=NULL,token_expires_at=NULL,connected_at=NULL WHERE id=1');
    }

    public function authorizationUrl(string $redirectUri, string $state): string
    {
        $row = $this->credentials();
        if ($row['client_id'] === '' || $row['client_secret'] === '') {
            throw new RuntimeException('先にGoogle OAuthのクライアントIDとクライアントシークレットを保存してください。');
        }
        return self::AUTH_URL . '?' . http_build_query([
            'client_id' => $row['client_id'],
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchangeCode(string $code, string $redirectUri): void
    {
        $row = $this->credentials();
        $json = $this->request('POST', self::TOKEN_URL, [
            'client_id' => $row['client_id'],
            'client_secret' => $row['client_secret'],
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri,
        ], null, true);
        if (empty($json['access_token'])) throw new RuntimeException('Googleからアクセストークンを取得できませんでした。');
        $refresh = (string) ($json['refresh_token'] ?? '');
        if ($refresh === '') {
            $existing = $this->db->query('SELECT refresh_token_enc FROM search_console_auth WHERE id=1')->fetchColumn();
            if (!is_string($existing) || $existing === '' || !$this->canDecrypt($existing)) {
                throw new RuntimeException('Googleから再接続用の認証情報を取得できませんでした。もう一度Googleアカウントへ接続してください。');
            }
            $refreshEnc = $existing ?: null;
        } else {
            $refreshEnc = $this->encrypt($refresh);
        }
        $expires = date('Y-m-d H:i:s', time() + max(60, (int) ($json['expires_in'] ?? 3600)) - 60);
        $stmt = $this->db->prepare('UPDATE search_console_auth SET access_token_enc=?,refresh_token_enc=?,token_expires_at=?,connected_at=NOW() WHERE id=1');
        $stmt->execute([$this->encrypt((string)$json['access_token']),$refreshEnc,$expires]);
    }

    public function listProperties(): array
    {
        $json = $this->authorizedRequest('GET', self::SITES_URL);
        $items = [];
        foreach (($json['siteEntry'] ?? []) as $entry) {
            if (!is_array($entry) || empty($entry['siteUrl'])) continue;
            $items[] = [
                'siteUrl' => (string) $entry['siteUrl'],
                'permissionLevel' => (string) ($entry['permissionLevel'] ?? ''),
            ];
        }
        usort($items, static fn(array $a,array $b): int => strcmp($a['siteUrl'],$b['siteUrl']));
        return $items;
    }

    public function queryKeywords(string $property, string $startDate, string $endDate): array
    {
        $property = trim($property);
        if ($property === '') throw new RuntimeException('このサイトにSearch Consoleプロパティが設定されていません。');
        $url = 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode($property) . '/searchAnalytics/query';
        $json = $this->authorizedRequest('POST', $url, [
            'startDate' => $startDate,
            'endDate' => $endDate,
            'dimensions' => ['query','page'],
            'rowLimit' => 250,
            'dataState' => 'final',
        ]);
        return is_array($json['rows'] ?? null) ? $json['rows'] : [];
    }

    private function authorizedRequest(string $method, string $url, ?array $body = null): array
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $token = $this->accessToken($attempt > 0);
            try {
                return $body === null ? $this->request($method, $url, [], $token)
                    : $this->requestJson($method, $url, $body, $token);
            } catch (RuntimeException $e) {
                // Only an API 401 warrants one refresh; permission/transport errors do not.
                if ($attempt > 0 || $e->getCode() !== 401) throw $e;
            }
        }
        throw new RuntimeException('Google APIの認証に失敗しました。Googleアカウントを再接続してください。');
    }

    private function accessToken(bool $forceRefresh = false): string
    {
        $this->ensureSchema();
        $row = $this->db->query('SELECT client_id,client_secret_enc,access_token_enc,refresh_token_enc,token_expires_at FROM search_console_auth WHERE id=1')->fetch() ?: [];
        if (empty($row['refresh_token_enc'])) throw new RuntimeException('Google Search Consoleが未接続です。');
        if (!$forceRefresh && !empty($row['access_token_enc']) && !empty($row['token_expires_at']) && strtotime((string)$row['token_expires_at']) > time() && $this->canDecrypt((string)$row['access_token_enc'])) {
            return $this->decrypt((string)$row['access_token_enc']);
        }
        $clientSecret = $this->decrypt((string)($row['client_secret_enc'] ?? ''));
        $refreshToken = $this->decrypt((string)$row['refresh_token_enc']);
        $json = $this->request('POST', self::TOKEN_URL, [
            'client_id' => (string)($row['client_id'] ?? ''),
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ], null, true);
        if (empty($json['access_token'])) throw new RuntimeException('Google Search Consoleのアクセストークン更新に失敗しました。');
        $expires = date('Y-m-d H:i:s', time() + max(60,(int)($json['expires_in'] ?? 3600)) - 60);
        $refreshEnc = !empty($json['refresh_token']) ? $this->encrypt((string)$json['refresh_token']) : $row['refresh_token_enc'];
        // Do not resurrect a connection disconnected or changed while the request was in flight.
        $stmt = $this->db->prepare('UPDATE search_console_auth SET access_token_enc=?,refresh_token_enc=?,token_expires_at=? WHERE id=1 AND client_id=? AND client_secret_enc=? AND refresh_token_enc=?');
        $stmt->execute([$this->encrypt((string)$json['access_token']),$refreshEnc,$expires,$row['client_id'],$row['client_secret_enc'],$row['refresh_token_enc']]);
        if ($stmt->rowCount() === 0) throw new RuntimeException('Google接続設定が更新されました。画面を再読み込みしてください。');
        return (string)$json['access_token'];
    }

    private function credentials(): array
    {
        $this->ensureSchema();
        $row = $this->db->query('SELECT client_id,client_secret_enc FROM search_console_auth WHERE id=1')->fetch() ?: [];
        return [
            'client_id' => trim((string)($row['client_id'] ?? '')),
            'client_secret' => !empty($row['client_secret_enc']) ? $this->decrypt((string)$row['client_secret_enc']) : '',
        ];
    }

    private function requestJson(string $method, string $url, array $body, string $token): array
    {
        $headers = ['Authorization: Bearer ' . $token, 'Content-Type: application/json'];
        return $this->rawRequest($method,$url,json_encode($body,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$headers);
    }

    private function request(string $method, string $url, array $params = [], ?string $token = null, bool $form = false): array
    {
        $headers = [];
        if ($token) $headers[] = 'Authorization: Bearer ' . $token;
        $body = null;
        if ($method === 'POST') {
            $body = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        } elseif ($params !== []) {
            $url .= '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        }
        return $this->rawRequest($method,$url,$body,$headers);
    }

    private function rawRequest(string $method, string $url, ?string $body, array $headers): array
    {
        if ($this->transport !== null) {
            [$status, $response] = ($this->transport)($method, $url, $body, $headers);
        } elseif (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers]);
            if ($body !== null) curl_setopt($ch,CURLOPT_POSTFIELDS,$body);
            $response = curl_exec($ch);
            $status = (int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            if ($response === false) throw new RuntimeException('Google API通信エラー: ' . $error);
        } else {
            $context = stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",$headers),'content'=>$body ?? '','timeout'=>20,'ignore_errors'=>true]]);
            $response = @file_get_contents($url,false,$context);
            $status = 0;
            if (!empty($http_response_header[0]) && preg_match('/\s(\d{3})\s/',$http_response_header[0],$m)) $status = (int)$m[1];
            if ($response === false) throw new RuntimeException('Google APIへ接続できませんでした。');
        }
        $json = json_decode((string)$response,true);
        if (!is_array($json)) $json = [];
        if ($status < 200 || $status >= 300) {
            $error = $json['error'] ?? null;
            $message = match (is_string($error) ? $error : '') {
                'invalid_grant' => 'Googleの接続許可が失効しています。Googleアカウントを再接続してください。約7日で切れる場合はGoogle CloudのOAuth同意画面が「テスト」になっていないか確認してください。シークレットは削除していません。',
                'invalid_client' => 'GoogleがクライアントIDまたはシークレットを拒否しました。Google Cloudの設定と照合してください。保存情報は削除していません。',
                default => 'Google APIへの接続に失敗しました（HTTP ' . $status . '）。時間をおいて再試行し、続く場合はGoogle Cloudの権限とAPI設定を確認してください。保存情報は削除していません。',
            };
            throw new RuntimeException($message, (int)$status);
        }
        return $json;
    }

    private function encryptionKey(): string
    {
        $key = (string)($this->config['app_key'] ?? '');
        if (trim($key) === '') throw new RuntimeException('config/config.php の app_key がありません。以前の設定ファイルを復元してください。');
        return hash('sha256', $key, true);
    }

    private function encrypt(string $plain): string
    {
        if ($plain === '') return '';
        if (!function_exists('openssl_encrypt')) throw new RuntimeException('OpenSSL拡張が必要です。');
        $key = $this->encryptionKey();
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag);
        if ($cipher === false) throw new RuntimeException('認証情報を暗号化できませんでした。');
        return base64_encode($iv.$tag.$cipher);
    }

    private function decrypt(string $payload): string
    {
        if ($payload === '') return '';
        if (!function_exists('openssl_decrypt')) throw new RuntimeException('OpenSSL拡張が必要です。');
        $raw = base64_decode($payload,true);
        if ($raw === false || strlen($raw) < 29) throw new RuntimeException('保存済み認証情報を読み込めません。');
        $iv = substr($raw,0,12);
        $tag = substr($raw,12,16);
        $cipher = substr($raw,28);
        $key = $this->encryptionKey();
        $plain = openssl_decrypt($cipher,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag);
        if ($plain === false) throw new RuntimeException('保存済み認証情報を復号できません。');
        return $plain;
    }

    private function canDecrypt(string $payload): bool
    {
        if ($payload === '') return false;
        try {
            return $this->decrypt($payload) !== '';
        } catch (\Throwable) {
            return false;
        }
    }
}
