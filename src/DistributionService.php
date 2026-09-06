<?php
declare(strict_types=1);

namespace Asyura;

use PDO;

final class DistributionService
{
    private const SPECIAL_PERCENT = 4.0;

    public function __construct(private PDO $db) {}

    public function calculate(int $targetSiteId): array
    {
        $rows = $this->buildRows($targetSiteId);
        $batchId=Security::randomToken(8);$this->db->beginTransaction();
        try{
            $this->db->prepare('INSERT INTO rss_distribution_batches (batch_id,target_site_id) VALUES (?,?)')->execute([$batchId,$targetSiteId]);
            $history=$this->db->prepare('INSERT INTO reciprocal_rss_distribution_history (target_site_id,reciprocal_link_id,batch_id,inbound,multiplier,base_weight,final_percent,allocation_type) VALUES (?,?,?,?,?,?,?,?)');
            foreach($rows as $row)$history->execute([$targetSiteId,$row['reciprocal_link_id'],$batchId,$row['inbound'],$row['multiplier'],$row['remaining_accesses'],$row['final_percent'],$row['allocation_type']]);
            $this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
        return $rows;
    }

    private function buildRows(int $targetSiteId): array
    {
        $hours = max(1, (int) setting('distribution_window_hours', 24));
        $days = max(1, (int) ceil($hours / 24));
        $stmt = $this->db->prepare("SELECT l.* FROM reciprocal_links l
            WHERE l.site_id=? AND l.status='approved' AND l.reciprocal_rss_enabled=1
              AND l.allocation_type<>'excluded' AND l.rss_url IS NOT NULL AND l.rss_url<>''
              AND EXISTS (SELECT 1 FROM rss_feeds f WHERE f.reciprocal_link_id=l.id AND f.site_id=l.site_id AND f.active=1)
            ORDER BY l.id");
        $stmt->execute([$targetSiteId]);
        $inboundStmt = $this->db->prepare("SELECT COUNT(*) FROM raw_events
            WHERE site_id=? AND event_type='pageview' AND is_bot=0 AND is_suspicious=0
              AND referrer_host=? AND occurred_at>=CURDATE()-INTERVAL ? DAY");
        $outboundStmt = $this->db->prepare("SELECT COALESCE(SUM(outbound_clicks+widget_clicks),0) FROM daily_link_stats
            WHERE site_id=? AND target_host=? AND stat_date>=CURDATE()-INTERVAL ? DAY");
        $totalOutboundStmt = $this->db->prepare("SELECT COALESCE(SUM(outbound_clicks+widget_clicks),0) FROM daily_link_stats
            WHERE site_id=? AND stat_date>=CURDATE()-INTERVAL ? DAY");
        $totalOutboundStmt->execute([$targetSiteId, $days - 1]);
        $totalOutbound = (int) $totalOutboundStmt->fetchColumn();
        $rows = [];
        foreach ($stmt->fetchAll() as $partner) {
            $host = UrlNormalizer::host((string) $partner['partner_url']);
            if ($host === '' || $this->isExcludedReferrer($host)) continue;
            $inboundStmt->execute([$targetSiteId, $host, $days - 1]);
            $inbound = (int) $inboundStmt->fetchColumn();
            $outboundStmt->execute([$targetSiteId, $host, $days - 1]);
            $outbound = (int) $outboundStmt->fetchColumn();
            $type = (string) $partner['allocation_type'];
            $multiplier = match ($type) {
                'priority_120' => 1.20,
                'priority_150' => 1.50,
                'priority_200' => 2.00,
                default => 1.00,
            };
            $targetAccesses = match ($type) {
                'special' => max(1, (int) ceil($totalOutbound * (self::SPECIAL_PERCENT / 100))),
                'rescue' => $this->rescueQuota((int) $partner['id']),
                default => (int) ceil($inbound * $multiplier),
            };
            if ($type === 'rescue') {
                $todayOutbound = $this->todayOutbound($targetSiteId, $host);
                $outbound = $todayOutbound;
            }
            $remaining = max(0, $targetAccesses - $outbound);
            $rows[] = [
                'reciprocal_link_id'=>(int)$partner['id'], 'partner_name'=>(string)$partner['partner_name'],
                'partner_url'=>(string)$partner['partner_url'], 'allocation_type'=>$type, 'inbound'=>$inbound,
                'multiplier'=>$multiplier,
                'outbound'=>$outbound, 'target_accesses'=>$targetAccesses, 'remaining_accesses'=>$remaining,
                'base_weight'=>(float)$remaining,
                'final_percent'=>0.0,
            ];
        }
        $totalRemaining=array_sum(array_column($rows,'remaining_accesses'));
        if($totalRemaining>0){foreach($rows as &$row)$row['final_percent']=((float)$row['remaining_accesses']/$totalRemaining)*100;unset($row);}
        return $rows;
    }

    public function latest(int $targetSiteId): array
    {
        return $this->buildRows($targetSiteId);
    }

    public function chooseItems(int $targetSiteId,int $limit,bool $imageRequired=false,array $allowedFeedIds=[]):array
    {
        $limit=max(1,min(100,$limit));$allowedFeedIds=$this->allowedFeedIds($targetSiteId,$allowedFeedIds);$rows=[];$used=[];
        $weights=array_values(array_filter($this->buildRows($targetSiteId),static fn(array $row):bool=>(int)$row['remaining_accesses']>0));
        while(count($rows)<$limit&&$weights){$partner=$this->weightedPartner($weights);if(!$partner)break;$linkId=(int)$partner['reciprocal_link_id'];$item=$this->itemForPartner($targetSiteId,$linkId,$imageRequired,$allowedFeedIds,$used);if(!$item){$weights=array_values(array_filter($weights,static fn(array $row):bool=>(int)$row['reciprocal_link_id']!==$linkId));continue;}$rows[]=$item;$used[]=(int)$item['id'];}
        return $rows;
    }

    private function rescueQuota(int $linkId):int{return 1+(abs(crc32(date('Y-m-d').'|'.$linkId))%3);}
    private function todayOutbound(int $targetSiteId,string $host):int
    {
        $stmt=$this->db->prepare('SELECT COALESCE(SUM(outbound_clicks+widget_clicks),0) FROM daily_link_stats WHERE site_id=? AND target_host=? AND stat_date=CURDATE()');
        $stmt->execute([$targetSiteId,$host]);return (int)$stmt->fetchColumn();
    }
    private function itemForPartner(int $targetSiteId,int $linkId,bool $imageRequired,array $feedIds,array $used):?array
    {
        $where=['f.site_id=?','f.reciprocal_link_id=?','f.active=1'];$args=[$targetSiteId,$linkId];if($imageRequired)$where[]="i.image_url IS NOT NULL AND i.image_url<>''";
        if($feedIds){$where[]='f.id IN ('.implode(',',array_fill(0,count($feedIds),'?')).')';$args=array_merge($args,$feedIds);}if($used){$where[]='i.id NOT IN ('.implode(',',array_fill(0,count($used),'?')).')';$args=array_merge($args,$used);}
        $stmt=$this->db->prepare('SELECT i.*,l.partner_name site_name,f.name rss_name FROM rss_items i JOIN rss_feeds f ON f.id=i.feed_id JOIN reciprocal_links l ON l.id=f.reciprocal_link_id AND l.site_id=f.site_id WHERE '.implode(' AND ',$where).' ORDER BY i.published_at DESC,i.id DESC LIMIT 50');$stmt->execute($args);$items=$stmt->fetchAll();return$items?$items[random_int(0,count($items)-1)]:null;
    }
    private function allowedFeedIds(int $targetSiteId,array $feedIds):array
    {
        $feedIds=array_values(array_unique(array_filter(array_map('intval',$feedIds),static fn(int $id):bool=>$id>0)));if(!$feedIds)return[];$stmt=$this->db->prepare('SELECT id FROM rss_feeds WHERE site_id=? AND reciprocal_link_id IS NOT NULL AND id IN ('.implode(',',array_fill(0,count($feedIds),'?')).')');$stmt->execute(array_merge([$targetSiteId],$feedIds));return array_map('intval',$stmt->fetchAll(PDO::FETCH_COLUMN));
    }
    private function weightedPartner(array $weights):?array
    {
        $total=array_sum(array_map(static fn(array $row):float=>(float)$row['final_percent'],$weights));if($total<=0)return null;$pick=(random_int(0,1000000)/1000000)*$total;foreach($weights as $weight){$pick-=(float)$weight['final_percent'];if($pick<=0)return$weight;}return end($weights)?:null;
    }
    private function isExcludedReferrer(string $host):bool
    {
        foreach($this->db->query('SELECT pattern,match_type FROM excluded_referrers WHERE active=1') as $row){$pattern=mb_strtolower((string)$row['pattern']);$candidate=mb_strtolower($host);$matches=match($row['match_type']){'exact'=>$candidate===$pattern,'contains'=>str_contains($candidate,$pattern),default=>$candidate===$pattern||str_ends_with($candidate,'.'.$pattern)};if($matches)return true;}return false;
    }
}
