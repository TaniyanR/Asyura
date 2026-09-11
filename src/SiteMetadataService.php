<?php
declare(strict_types=1);

namespace Asyura;

final class SiteMetadataService
{
    private const MAX_BYTES = 524288;

    /** @return array{name:string,source:string} */
    public function fetchName(string $url): array
    {
        $response = $this->fetchHtml($url);
        return self::extractName($response['html'], $response['url']);
    }

    /** @return array{name:string,source:string} */
    public static function extractName(string $html, string $url): array
    {
        $encoding = mb_detect_encoding($html, ['UTF-8','SJIS-win','EUC-JP','ISO-2022-JP'], true);
        if ($encoding !== false && $encoding !== 'UTF-8') $html = mb_convert_encoding($html, 'UTF-8', $encoding);

        $candidates = [];
        if (class_exists(\DOMDocument::class)) {
            $previous = libxml_use_internal_errors(true);
            $document = new \DOMDocument();
            $loaded = $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            if ($loaded) {
                $xpath = new \DOMXPath($document);
                $candidates = [
                    ['og:site_name', self::xpathValue($xpath, "//meta[translate(@property,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz')='og:site_name']/@content")],
                    ['application-name', self::xpathValue($xpath, "//meta[translate(@name,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz')='application-name']/@content")],
                    ['title', self::xpathValue($xpath, '//title')],
                ];
            }
        }
        if (!$candidates) {
            $candidates = [
                ['og:site_name', self::regexMeta($html, 'property', 'og:site_name')],
                ['application-name', self::regexMeta($html, 'name', 'application-name')],
                ['title', preg_match('~<title\b[^>]*>(.*?)</title>~isu', $html, $match) ? $match[1] : ''],
            ];
        }
        foreach ($candidates as [$source,$value]) {
            $name = self::cleanName($value);
            if ($name !== '') return ['name'=>$name,'source'=>$source];
        }
        $host = (string) parse_url($url, PHP_URL_HOST);
        return ['name'=>preg_replace('/^www\./i', '', $host) ?: $host,'source'=>'domain'];
    }

    /** @return array{html:string,url:string} */
    private function fetchHtml(string $url): array
    {
        if (!extension_loaded('curl')) throw new \RuntimeException('PHP cURL拡張が必要です。');
        $current = Security::safeUrl($url);
        if ($current === '') throw new \InvalidArgumentException('サイトURLが正しくありません。');

        for ($redirect=0; $redirect<=3; $redirect++) {
            $resolved = $this->assertPublicUrl($current);
            $headers = [];
            $body = '';
            $tooLarge = false;
            $ch = curl_init($current);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 12,
                CURLOPT_USERAGENT => 'Asyura Site Metadata Fetcher/1.0',
                CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml'],
                CURLOPT_ENCODING => '',
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_RESOLVE => [$resolved['host'].':'.$resolved['port'].':'.$resolved['ip']],
                CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
                    $length = strlen($line);
                    $position = strpos($line, ':');
                    if ($position !== false) $headers[strtolower(trim(substr($line,0,$position)))] = trim(substr($line,$position+1));
                    return $length;
                },
                CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body, &$tooLarge): int {
                    if (strlen($body) + strlen($chunk) > self::MAX_BYTES) {
                        $tooLarge = true;
                        return 0;
                    }
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ]);
            $ok = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $contentType = strtolower((string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
            $error = curl_error($ch);
            curl_close($ch);
            // サイト名は通常head内にあるため、上限へ達した場合は先頭512KBだけを解析する。
            if ($ok === false && !$tooLarge) throw new \RuntimeException('サイトへ接続できません：'.$error);
            if (in_array($status, [301,302,303,307,308], true) && isset($headers['location'])) {
                $current = $this->resolveUrl($current, $headers['location']);
                continue;
            }
            if ($status < 200 || $status >= 300) throw new \RuntimeException('サイトを取得できません（HTTP '.$status.'）。');
            if ($contentType !== '' && !str_contains($contentType, 'text/html') && !str_contains($contentType, 'application/xhtml+xml')) {
                throw new \RuntimeException('HTMLページではないためサイト名を取得できません。');
            }
            return ['html'=>$body,'url'=>$current];
        }
        throw new \RuntimeException('サイトの転送回数が上限を超えました。');
    }

    /** @return array{host:string,ip:string,port:int} */
    private function assertPublicUrl(string $url): array
    {
        $safe = Security::safeUrl($url);
        if ($safe === '') throw new \InvalidArgumentException('サイトURLが正しくありません。');
        $host = (string) parse_url($safe, PHP_URL_HOST);
        $scheme = (string) parse_url($safe, PHP_URL_SCHEME);
        $port = (int) (parse_url($safe, PHP_URL_PORT) ?: ($scheme === 'https' ? 443 : 80));
        if (!in_array($port, [80,443], true)) throw new \InvalidArgumentException('サイトURLのポート番号を確認してください。');
        $ips = gethostbynamel($host) ?: [];
        if (!$ips) throw new \RuntimeException('サイトのホストを確認できません。');
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new \RuntimeException('内部ネットワークのURLにはアクセスできません。');
            }
        }
        return ['host'=>$host,'ip'=>$ips[0],'port'=>$port];
    }

    private function resolveUrl(string $base, string $location): string
    {
        $location = trim($location);
        if (preg_match('~^https?://~i', $location)) return $location;
        $parts = parse_url($base);
        $scheme = (string) ($parts['scheme'] ?? 'https');
        if (str_starts_with($location, '//')) return $scheme.':'.$location;
        $origin = $scheme.'://'.$parts['host'].(isset($parts['port'])?':'.$parts['port']:'');
        if (str_starts_with($location, '/')) return $origin.$location;
        $directory = rtrim(dirname($parts['path'] ?? '/'), '/');
        return $origin.($directory !== '' ? $directory : '').'/'.$location;
    }

    private static function xpathValue(\DOMXPath $xpath, string $query): string
    {
        $nodes = $xpath->query($query);
        return $nodes && $nodes->length > 0 ? (string) $nodes->item(0)?->nodeValue : '';
    }

    private static function regexMeta(string $html, string $attribute, string $expected): string
    {
        if (!preg_match_all('~<meta\b[^>]*>~isu', $html, $tags)) return '';
        foreach ($tags[0] as $tag) {
            if (!preg_match('~\b'.preg_quote($attribute,'~').'\s*=\s*(["\'])(.*?)\1~isu', $tag, $key) || strcasecmp(trim($key[2]), $expected) !== 0) continue;
            if (preg_match('~\bcontent\s*=\s*(["\'])(.*?)\1~isu', $tag, $content)) return $content[2];
        }
        return '';
    }

    private static function cleanName(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? '';
        return Security::cleanText($value, 255);
    }
}
