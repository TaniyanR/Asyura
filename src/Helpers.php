<?php
declare(strict_types=1);

use Asyura\Security;

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function app_url(string $path = ''): string
{
    global $config;
    return rtrim($config['app_url'], '/') . '/' . ltrim($path, '/');
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(Security::csrfToken()) . '">';
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function setting(string $key, mixed $default = null): mixed
{
    global $db;
    static $cache = [];
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $stmt = $db->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    $cache[$key] = $value === false ? $default : $value;
    return $cache[$key];
}
