<?php
declare(strict_types=1);

namespace Asyura;

use PDO;

final class Tracker
{
    public function __construct(private PDO $db, private array $config)
    {
    }

    public function findSite(string $publicId, string $key): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM sites WHERE public_id = ? AND active = 1 LIMIT 1');
        $stmt->execute([$publicId]);
        $site = $stmt->fetch();
        return $site && hash_equals((string) $site['site_key'], $key) ? $site : null;
    }

    public function originAllowed(array $site, string $origin): bool
    {
        $originHost = UrlNormalizer::host($origin);
        if ($originHost === '') {
            return false;
        }
        if ($originHost === UrlNormalizer::host($site['url'])) {
            return true;
        }
        $stmt = $this->db->prepare('SELECT alias_url FROM site_aliases WHERE site_id = ? AND allow_tracking_origin=1');
        $stmt->execute([$site['id']]);
        foreach ($stmt->fetchAll() as $alias) {
            if ($originHost === UrlNormalizer::host($alias['alias_url'])) {
                return true;
            }
        }
        return false;
    }

    public function record(array $site, array $payload): void
    {
        $eventType = in_array($payload['event_type'] ?? '', ['pageview', 'internal_click', 'outbound', 'widget_click'], true)
            ? $payload['event_type'] : 'pageview';
        $pageUrl = Security::safeUrl($payload['page_url'] ?? '');
        if ($pageUrl === '' || !$this->originAllowed($site, $pageUrl)) {
            throw new \InvalidArgumentException('Page URL is not registered.');
        }

        $ua = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
        $isBot = preg_match('/bot|crawler|spider|slurp|headless|preview|facebookexternalhit|bingpreview/i', $ua) === 1;
        $today = date('Y-m-d');
        $visitorHash = hash_hmac('sha256', Security::clientIp() . '|' . $ua . '|' . $today, $this->config['app_key']);
        $sessionHash = hash_hmac('sha256', Security::clientIp() . '|' . $ua . '|' . floor(time() / 1800), $this->config['app_key']);
        $referrer = Security::safeUrl($payload['referrer'] ?? '');
        $referrerHost = $referrer !== '' ? UrlNormalizer::host($referrer) : '';
        if ($referrer !== '') {
            $referrerHost = $this->resolveReferrerHost($referrer, $referrerHost);
        }
        if ($referrerHost === UrlNormalizer::host($site['url'])) {
            $referrerHost = '';
        }
        $target = Security::safeUrl($payload['target_url'] ?? '');
        $widgetId = ctype_digit((string) ($payload['widget_id'] ?? '')) ? (int) $payload['widget_id'] : null;

        $device = preg_match('/mobile|android|iphone|ipad/i', $ua) ? 'mobile' : 'desktop';
        $browser = $this->browser($ua);
        $os = $this->os($ua);

        $this->db->beginTransaction();
        try {
            $unique = false;$uniqueReferrer=false;
            if ($eventType === 'pageview' && !$isBot) {
                $check=$this->db->prepare('INSERT IGNORE INTO daily_visitors (site_id,visit_date,visitor_hash) VALUES (?,?,?)');$check->execute([$site['id'],$today,$visitorHash]);$unique=$check->rowCount()===1;
                if($referrerHost!==''){$refCheck=$this->db->prepare('INSERT IGNORE INTO daily_referrer_visitors (site_id,visit_date,referrer_host,visitor_hash) VALUES (?,?,?,?)');$refCheck->execute([$site['id'],$today,$referrerHost,$visitorHash]);$uniqueReferrer=$refCheck->rowCount()===1;}
            }

            $stmt = $this->db->prepare('INSERT INTO raw_events (site_id,event_type,visitor_hash,session_hash,page_url,normalized_page_url,referrer_url,referrer_host,target_url,widget_id,page_title,user_agent,device,browser,os,is_bot) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([
                $site['id'], $eventType, $visitorHash, $sessionHash, $pageUrl, UrlNormalizer::normalize($pageUrl),
                $referrer ?: null, $referrerHost ?: null, $target ?: null, $widgetId,
                Security::cleanText($payload['title'] ?? '', 500), $ua, $device, $browser, $os, $isBot ? 1 : 0,
            ]);

            if (!$isBot) {
                $pv = $eventType === 'pageview' ? 1 : 0;
                $out = $eventType === 'outbound' ? 1 : 0;
                $internal = $eventType === 'internal_click' ? 1 : 0;
                $click = $eventType === 'widget_click' ? 1 : 0;
                $daily = $this->db->prepare('INSERT INTO daily_stats (site_id,stat_date,pv,uu,outbound,internal_clicks,widget_clicks) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE pv=pv+VALUES(pv), uu=uu+VALUES(uu), outbound=outbound+VALUES(outbound), internal_clicks=internal_clicks+VALUES(internal_clicks), widget_clicks=widget_clicks+VALUES(widget_clicks)');
                $daily->execute([$site['id'], $today, $pv, $unique ? 1 : 0, $out, $internal, $click]);

                if($eventType!=='pageview'&&$target!==''){$targetNormalized=UrlNormalizer::normalize($target);$linkStat=$this->db->prepare('INSERT INTO daily_link_stats (site_id,stat_date,target_hash,target_url,target_host,internal_clicks,outbound_clicks,widget_clicks) VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE internal_clicks=internal_clicks+VALUES(internal_clicks),outbound_clicks=outbound_clicks+VALUES(outbound_clicks),widget_clicks=widget_clicks+VALUES(widget_clicks),target_url=VALUES(target_url)');$linkStat->execute([$site['id'],$today,hash('sha256',$targetNormalized),$target,UrlNormalizer::host($target),$internal,$out,$click]);}

                if ($eventType === 'pageview' && $referrerHost !== '') {
                    $ref = $this->db->prepare('INSERT INTO referrer_stats (site_id,stat_date,referrer_host,inbound,unique_inbound) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE inbound=inbound+1, unique_inbound=unique_inbound+VALUES(unique_inbound)');
                    $ref->execute([$site['id'], $today, $referrerHost, 1, $uniqueReferrer ? 1 : 0]);
                }
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function browser(string $ua): string
    {
        return match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') => 'Opera',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'Other',
        };
    }

    private function os(string $ua): string
    {
        return match (true) {
            preg_match('/iPhone|iPad/', $ua) === 1 => 'iOS',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS') => 'macOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'Other',
        };
    }

    private function resolveReferrerHost(string $url, string $host): string
    {
        $normalized=UrlNormalizer::normalize($url);$stmt=$this->db->query('SELECT a.alias_url,a.normalized_url,a.match_type,s.url site_url FROM site_aliases a JOIN sites s ON s.id=a.site_id WHERE s.active=1');
        foreach($stmt as $alias){$match=match($alias['match_type']){'exact'=>$normalized===$alias['normalized_url'],'prefix'=>str_starts_with($normalized,$alias['normalized_url']),'contains'=>str_contains($normalized,$alias['normalized_url'])||str_contains($url,$alias['alias_url']),default=>$host===UrlNormalizer::host($alias['alias_url'])};if($match)return UrlNormalizer::host($alias['site_url']);}
        return $host;
    }
}
