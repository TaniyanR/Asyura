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

    public function __construct(private PDO $db, private array $config) {}

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
        return [
            'client_id' => (string) ($row['client_id'] ?? ''),
            'has_client_secret' => !empty($row['client_secret_enc']),
            'connected' => !empty($row['refresh_token_enc']),
            'connected_at' => $row['connected_at'] ?? null,
        ];
    }

    public function saveCredentials(string $clientId, ?string $clientSecret): void
    {
        $this->ensureSchema();
        $clientId = trim($clientId);
        if ($clientId === '') throw new \InvalidArgumentException('Google OAuth クライアントIDを入力してください。');
        if ($clientSecret !== null && trim($clientSecret) !== '') {
            $enc = $this->encrypt(trim($clientSecret));
            $stmt = $this->db->prepare('UPDATE search_console_auth SET client_id=?,client_secret_enc=? WHERE id=1');
            $stmt->execute([$clientId,$enc]);
        } else {
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
        $token = $this->accessToken();
        $json = $this->request('GET', self::SITES_URL, [], $token);
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
        $token = $this->accessToken();
        $url = 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode($property) . '/searchAnalytics/query';
        $json = $this->requestJson('POST', $url, [
            'startDate' => $startDate,
            'endDate' => $endDate,
            'dimensions' => ['query','page'],
            'rowLimit' => 250,
            'dataState' => 'final',
        ], $token);
        return is_array($json['rows'] ?? null) ? $json['rows'] : [];
    }

    private function accessToken(): string
    {
        $this->ensureSchema();
        $row = $this->db->query('SELECT client_id,client_secret_enc,access_token_enc,refresh_token_enc,token_expires_at FROM search_console_auth WHERE id=1')->fetch() ?: [];
        if (empty($row['refresh_token_enc'])) throw new RuntimeException('Google Search Consoleが未接続です。');
        if (!empty($row['access_token_enc']) && !empty($row['token_expires_at']) && strtotime((string)$row['token_expires_at']) > time()) {
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
        $stmt = $this->db->prepare('UPDATE search_console_auth SET access_token_enc=?,token_expires_at=? WHERE id=1');
        $stmt->execute([$this->encrypt((string)$json['access_token']),$expires]);
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
        if (function_exists('curl_init')) {
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
            $message = (string)($json['error']['message'] ?? $json['error_description'] ?? 'Google APIでエラーが発生しました。');
            throw new RuntimeException($message);
        }
        return $json;
    }

    private function encrypt(string $plain): string
    {
        if ($plain === '') return '';
        if (!function_exists('openssl_encrypt')) throw new RuntimeException('OpenSSL拡張が必要です。');
        $key = hash('sha256',(string)($this->config['app_key'] ?? ''),true);
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
        $key = hash('sha256',(string)($this->config['app_key'] ?? ''),true);
        $plain = openssl_decrypt($cipher,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag);
        if ($plain === false) throw new RuntimeException('保存済み認証情報を復号できません。');
        return $plain;
    }
}
