<?php
declare(strict_types=1);

namespace Asyura;

use PDO;

final class AnalyticsService
{
    public function __construct(private PDO $db) {}

    public function summary(int $siteId, int $days): array
    {
        $interval=$this->interval($days);
        $events=$this->db->prepare("SELECT COUNT(*) pv,COUNT(DISTINCT visitor_hash) uu FROM raw_events WHERE site_id=? AND event_type='pageview' AND is_bot=0 AND is_suspicious=0 AND occurred_at>=CURDATE()-INTERVAL {$interval} DAY");
        $events->execute([$siteId]);$row=$events->fetch()?:['pv'=>0,'uu'=>0];
        $sessions=$this->db->prepare("SELECT COUNT(*) FROM analytics_sessions WHERE site_id=? AND is_bot=0 AND is_suspicious=0 AND started_at>=CURDATE()-INTERVAL {$interval} DAY");
        $sessions->execute([$siteId]);$row['sessions']=(int)$sessions->fetchColumn();
        $row['pv']=(int)$row['pv'];$row['uu']=(int)$row['uu'];
        $row['pages_per_session']=$row['sessions']>0?$row['pv']/$row['sessions']:0.0;
        return $row;
    }

    public function daily(int $siteId, int $days): array
    {
        $interval=$this->interval($days);
        $stmt=$this->db->prepare("SELECT DATE(occurred_at) stat_date,COUNT(*) pv,COUNT(DISTINCT visitor_hash) uu FROM raw_events WHERE site_id=? AND event_type='pageview' AND is_bot=0 AND is_suspicious=0 AND occurred_at>=CURDATE()-INTERVAL {$interval} DAY GROUP BY DATE(occurred_at) ORDER BY stat_date");
        $stmt->execute([$siteId]);$indexed=[];
        foreach($stmt->fetchAll() as $row)$indexed[(string)$row['stat_date']]=['stat_date'=>(string)$row['stat_date'],'pv'=>(int)$row['pv'],'uu'=>(int)$row['uu']];
        $today=new \DateTimeImmutable('today',new \DateTimeZone('Asia/Tokyo'));
        $rows=[];
        for($offset=max(0,$days-1);$offset>=0;$offset--){$date=$today->modify('-'.$offset.' days')->format('Y-m-d');$rows[]=$indexed[$date]??['stat_date'=>$date,'pv'=>0,'uu'=>0];}
        return $rows;
    }

    public function channels(int $siteId, int $days): array
    {
        $interval=$this->interval($days);
        $stmt=$this->db->prepare("SELECT COALESCE(NULLIF(channel,''),'direct') channel,COUNT(*) sessions,SUM(pageviews) pv,COUNT(DISTINCT visitor_hash) uu FROM analytics_sessions WHERE site_id=? AND is_bot=0 AND is_suspicious=0 AND started_at>=CURDATE()-INTERVAL {$interval} DAY GROUP BY COALESCE(NULLIF(channel,''),'direct') ORDER BY sessions DESC");
        $stmt->execute([$siteId]);return $stmt->fetchAll();
    }

    public function referrers(int $siteId, int $days): array
    {
        $interval=$this->interval($days);
        $stmt=$this->db->prepare("SELECT COALESCE(NULLIF(referrer_host,''),'直接') referrer_host,COUNT(*) sessions,SUM(pageviews) pv FROM analytics_sessions WHERE site_id=? AND is_bot=0 AND is_suspicious=0 AND started_at>=CURDATE()-INTERVAL {$interval} DAY GROUP BY COALESCE(NULLIF(referrer_host,''),'直接') ORDER BY sessions DESC LIMIT 50");
        $stmt->execute([$siteId]);return $stmt->fetchAll();
    }

    private function interval(int $days): int
    {
        return max(0,min(3650,$days)-1);
    }
}
