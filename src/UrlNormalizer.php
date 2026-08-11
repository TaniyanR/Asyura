<?php
declare(strict_types=1);

namespace Asyura;

final class UrlNormalizer
{
    private const TRACKING_KEYS = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        'gclid', 'fbclid', 'yclid', 'mc_cid', 'mc_eid',
    ];

    public static function normalize(string $url): string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url === '') {
            return '';
        }
        if (!preg_match('~^https?://~i', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            return '';
        }

        $host = strtolower(preg_replace('/^www\./i', '', $parts['host']));
        $path = isset($parts['path']) ? '/' . ltrim(preg_replace('~/+~', '/', $parts['path']), '/') : '/';
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        $query = [];
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);
            foreach (self::TRACKING_KEYS as $key) {
                unset($query[$key]);
            }
            ksort($query);
        }

        $normalized = 'https://' . $host . $path;
        if ($query !== []) {
            $normalized .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        return $normalized;
    }

    public static function host(string $url): string
    {
        $normalized = self::normalize($url);
        return $normalized === '' ? '' : (string) parse_url($normalized, PHP_URL_HOST);
    }
}
