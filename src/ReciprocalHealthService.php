<?php
declare(strict_types=1);

namespace Asyura;

use PDO;

final class ReciprocalHealthService
{
    public function __construct(private PDO $db) {}

    /** @return array{checked:int,ok:int,failed:int} */
    public function checkDue(int $limit = 20): array
    {
        $limit = min(100, max(1, $limit));
        $rows = $this->db->query(
            "SELECT id,site_id FROM reciprocal_links
             WHERE status IN ('pending','approved','paused')
               AND (site_checked_at IS NULL OR site_checked_at<=NOW()-INTERVAL 12 HOUR)
             ORDER BY site_checked_at IS NULL DESC,site_checked_at,id
             LIMIT {$limit}"
        )->fetchAll();
        $result = ['checked'=>0,'ok'=>0,'failed'=>0];
        foreach ($rows as $row) {
            $health = $this->check((int)$row['id'], (int)$row['site_id']);
            $result['checked']++;
            if (in_array($health['status'], ['ok','redirected','restricted'], true)) $result['ok']++;
            else $result['failed']++;
        }
        return $result;
    }

    /** @return array{status:string,http_status:?int,error:?string} */
    public function check(int $linkId, int $siteId): array
    {
        $stmt = $this->db->prepare('SELECT id,partner_url FROM reciprocal_links WHERE id=? AND site_id=?');
        $stmt->execute([$linkId,$siteId]);
        $link = $stmt->fetch();
        if (!$link) throw new \InvalidArgumentException('確認する相互リンク先が見つかりません。');

        $status = 'error';
        $httpStatus = null;
        $error = null;
        try {
            $response = $this->request((string)$link['partner_url']);
            $httpStatus = $response['http_status'];
            $status = match (true) {
                $httpStatus >= 200 && $httpStatus < 300 => $response['redirected'] ? 'redirected' : 'ok',
                in_array($httpStatus, [401,403], true) => 'restricted',
                $httpStatus === 404 || $httpStatus === 410 => 'missing',
                default => 'error',
            };
            if ($status === 'error') $error = 'HTTP '.$httpStatus;
        } catch (\Throwable $e) {
            $error = mb_substr($e->getMessage(), 0, 1000);
        }
        $update = $this->db->prepare('UPDATE reciprocal_links SET site_check_status=?,site_http_status=?,site_check_error=?,site_checked_at=NOW() WHERE id=? AND site_id=?');
        $update->execute([$status,$httpStatus,$error,$linkId,$siteId]);
        return ['status'=>$status,'http_status'=>$httpStatus,'error'=>$error];
    }

    /** @return array{http_status:int,redirected:bool} */
    private function request(string $url): array
    {
        if (!extension_loaded('curl')) throw new \RuntimeException('PHP cURL拡張が必要です。');
        $current = $url;
        $redirected = false;
        for ($redirect=0; $redirect<=3; $redirect++) {
            $resolved = $this->assertPublicUrl($current);
            $responseHeaders = [];
            $ch = curl_init($current);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_NOBODY => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_USERAGENT => 'Asyura Link Checker/1.0',
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP|CURLPROTO_HTTPS,
                CURLOPT_RESOLVE => [$resolved['host'].':'.$resolved['port'].':'.$resolved['ip']],
                CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                    $length = strlen($line);
                    $position = strpos($line, ':');
                    if ($position !== false) $responseHeaders[strtolower(trim(substr($line,0,$position)))] = trim(substr($line,$position+1));
                    return $length;
                },
            ]);
            $ok = curl_exec($ch);
            $httpStatus = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            if ($ok === false) throw new \RuntimeException('サイトへ接続できません：'.$curlError);
            if (in_array($httpStatus, [301,302,303,307,308], true) && isset($responseHeaders['location'])) {
                $current = $this->resolveUrl($current, $responseHeaders['location']);
                $redirected = true;
                continue;
            }
            // HEADを拒否するサイトだけ、小さなGETで存在確認をやり直す。
            if (in_array($httpStatus, [405,501], true)) return ['http_status'=>$this->getStatus($current,$resolved),'redirected'=>$redirected];
            return ['http_status'=>$httpStatus,'redirected'=>$redirected];
        }
        throw new \RuntimeException('サイトの転送回数が上限を超えました。');
    }

    private function getStatus(string $url, array $resolved): int
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER=>false,
            CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_CONNECTTIMEOUT=>5,
            CURLOPT_TIMEOUT=>10,
            CURLOPT_USERAGENT=>'Asyura Link Checker/1.0',
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,
            CURLOPT_RESOLVE=>[$resolved['host'].':'.$resolved['port'].':'.$resolved['ip']],
            CURLOPT_RANGE=>'0-1023',
            CURLOPT_WRITEFUNCTION=>static fn($ch,string $chunk):int=>strlen($chunk),
        ]);
        $ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$error=curl_error($ch);curl_close($ch);
        if($ok===false)throw new \RuntimeException('サイトへ接続できません：'.$error);
        return $status;
    }

    private function assertPublicUrl(string $url): array
    {
        $safe=Security::safeUrl($url);if($safe==='')throw new \RuntimeException('サイトURLが不正です。');
        $host=(string)parse_url($safe,PHP_URL_HOST);$ips=gethostbynamel($host)?:[];
        if(!$ips)throw new \RuntimeException('サイトのホストを確認できません。');
        foreach($ips as$ip){if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))throw new \RuntimeException('内部ネットワークのURLは確認できません。');}
        $scheme=(string)parse_url($safe,PHP_URL_SCHEME);$port=(int)(parse_url($safe,PHP_URL_PORT)?:($scheme==='https'?443:80));
        return ['host'=>$host,'ip'=>$ips[0],'port'=>$port];
    }

    private function resolveUrl(string $base,string $location): string
    {
        if(preg_match('~^https?://~i',$location))return $location;
        $parts=parse_url($base);$origin=$parts['scheme'].'://'.$parts['host'].(isset($parts['port'])?':'.$parts['port']:'');
        if(str_starts_with($location,'/'))return $origin.$location;
        $directory=rtrim(dirname($parts['path']??'/'),'/');return $origin.$directory.'/'.$location;
    }
}
