<?php
declare(strict_types=1);

namespace Asyura;

final class Security
{
    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        session_name('asyura_session');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public static function csrfToken(): string
    {
        self::startSession();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function verifyCsrf(?string $token): bool
    {
        self::startSession();
        return is_string($token)
            && isset($_SESSION['csrf_token'])
            && hash_equals($_SESSION['csrf_token'], $token);
    }

    public static function headers(string $policy = 'private'): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        if ($policy === 'private') {
            header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet, noimageindex');
            header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https:; style-src 'self' 'unsafe-inline'; script-src 'self'; frame-ancestors 'self'");
        }
    }

    public static function randomToken(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }

    public static function clientIp(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public static function ipHash(string $appKey, string $scope = ''): string
    {
        return hash_hmac('sha256', self::clientIp() . '|' . $scope, $appKey);
    }

    public static function cleanText(?string $value, int $max = 255): string
    {
        $value = trim(strip_tags((string) $value));
        return mb_substr($value, 0, $max);
    }

    public static function safeUrl(?string $value): string
    {
        $url = trim((string) $value);
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return '';
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if(!in_array($scheme, ['http', 'https'], true)||parse_url($url,PHP_URL_USER)!==null||parse_url($url,PHP_URL_PASS)!==null)return '';
        return $url;
    }
}
