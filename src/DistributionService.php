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
        $hours = max(1, (int) setting('distribution_window_hours', 24));
        $stmt = $this->db->prepare("SELECT l.* FROM reciprocal_links l
            WHERE l.site_id=? AND l.status='approved' AND l.reciprocal_rss_enabled=1
              AND l.allocation_type<>'excluded' AND l.rss_url IS NOT NULL AND l.rss_url<>''
              AND EXISTS (SELECT 1 FROM rss_feeds f WHERE f.reciprocal_link_id=l.id AND f.site_id=l.site_id AND f.active=1)
            ORDER BY l.id");
        $stmt->execute([$targetSiteId]);
        $inboundStmt = $this->db->prepare("SELECT COUNT(*) FROM raw_events
            WHERE site_id=? AND event_type='pageview' AND is_bot=0 AND is_suspicious=0
              AND referrer_host=? AND occurred_at>=DATE_SUB(NOW(),INTERVAL ? HOUR)");
        $rows = [];
        foreach ($stmt->fetchAll() as $partner) {
            $host = UrlNormalizer::host((string) $partner['partner_url']);
            if ($host === '' || $this->isExcludedReferrer($host)) continue;
            $inboundStmt->execute([$targetSiteId, $host, $hours]);
            $inbound = (int) $inboundStmt->fetchColumn();
            $type = (string) $partner['allocation_type'];
            $multiplier = match ($type) {
                'priority_120' => 1.20,
                'priority_150' => 1.50,
                'priority_200' => 2.00,
                default => 1.00,
            };
            $rows[] = [
                'reciprocal_link_id'=>(int)$partner['id'], 'partner_name'=>(string)$partner['partner_name'],
                'partner_url'=>(string)$partner['partner_url'], 'allocation_type'=>$type, 'inbound'=>$inbound,
                'multiplier'=>$multiplier,
                'base_weight'=>in_array($type, ['special','rescue'], true) ? 0.0 : $inbound * $multiplier,
                'final_percent'=>0.0,
            ];
        }
        $specialIndexes=[];$normalTotal=0.0;
        foreach($rows as $index=>$row){if($row['allocation_type']==='special')$specialIndexes[]=$index;elseif($row['allocation_type']!=='rescue')$normalTotal+=(float)$row['base_weight'];}
        $specialTotal=min(80.0,count($specialIndexes)*self::SPECIAL_PERCENT);$normalPool=100.0-$specialTotal;
        foreach($rows as &$row){
            if($row['allocation_type']==='special')$row['final_percent']=$specialTotal>0?$specialTotal/count($specialIndexes):0.0;
            elseif($row['allocation_type']==='rescue')$row['final_percent']=0.0;
            elseif($normalTotal>0)$row['final_percent']=((float)$row['base_weight']/$normalTotal)*$normalPool;
        }unset($row);
        $batchId=Security::randomToken(8);$this->db->beginTransaction();
        try{
            $this->db->prepare('INSERT INTO rss_distribution_batches (batch_id,target_site_id) VALUES (?,?)')->execute([$batchId,$targetSiteId]);
            $history=$this->db->prepare('INSERT INTO reciprocal_rss_distribution_history (target_site_id,reciprocal_link_id,batch_id,inbound,multiplier,base_weight,final_percent,allocation_type) VALUES (?,?,?,?,?,?,?,?)');
            foreach($rows as $row)$history->execute([$targetSiteId,$row['reciprocal_link_id'],$batchId,$row['inbound'],$row['multiplier'],$row['base_weight'],$row['final_percent'],$row['allocation_type']]);
            $this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
        return $rows;
    }

    public function latest(int $targetSiteId): array
    {
        $eligible=$this->db->prepare("SELECT COUNT(*) FROM reciprocal_links WHERE site_id=? AND status='approved' AND reciprocal_rss_enabled=1 AND allocation_type<>'excluded' AND rss_url IS NOT NULL AND rss_url<>''");$eligible->execute([$targetSiteId]);
        if((int)$eligible->fetchColumn()===0)return[];
        $batch=$this->db->prepare('SELECT batch_id FROM reciprocal_rss_distribution_history WHERE target_site_id=? ORDER BY id DESC LIMIT 1');$batch->execute([$targetSiteId]);$batchId=$batch->fetchColumn();
        if(!$batchId)return $this->calculate($targetSiteId);
        $stmt=$this->db->prepare('SELECT h.*,l.partner_name,l.partner_url FROM reciprocal_rss_distribution_history h JOIN reciprocal_links l ON l.id=h.reciprocal_link_id AND l.site_id=h.target_site_id WHERE h.target_site_id=? AND h.batch_id=? ORDER BY h.final_percent DESC,h.inbound DESC');
        $stmt->execute([$targetSiteId,$batchId]);return $stmt->fetchAll();
    }

    public function chooseItems(int $targetSiteId,int $limit,bool $imageRequired=false,array $allowedFeedIds=[]):array
    {
        $limit=max(1,min(100,$limit));$allowedFeedIds=$this->allowedFeedIds($targetSiteId,$allowedFeedIds);$rows=[];$used=[];
        foreach($this->rescuePartners($targetSiteId) as $partner){
            if(count($rows)>=$limit)break;$quota=$this->rescueQuota((int)$partner['id']);$shown=(int)$partner['displayed_count'];
            while($shown<$quota&&count($rows)<$limit){$item=$this->itemForPartner($targetSiteId,(int)$partner['id'],$imageRequired,$allowedFeedIds,$used);if(!$item||!$this->claimRescueDisplay($targetSiteId,(int)$partner['id'],$quota))break;$rows[]=$item;$used[]=(int)$item['id'];$shown++;}
        }
        $weights=array_values(array_filter($this->latest($targetSiteId),static fn(array $row):bool=>(float)$row['final_percent']>0));
        while(count($rows)<$limit&&$weights){$partner=$this->weightedPartner($weights);if(!$partner)break;$linkId=(int)$partner['reciprocal_link_id'];$item=$this->itemForPartner($targetSiteId,$linkId,$imageRequired,$allowedFeedIds,$used);if(!$item){$weights=array_values(array_filter($weights,static fn(array $row):bool=>(int)$row['reciprocal_link_id']!==$linkId));continue;}$rows[]=$item;$used[]=(int)$item['id'];}
        return $rows;
    }

    private function rescuePartners(int $targetSiteId):array
    {
        $stmt=$this->db->prepare("SELECT l.id,COALESCE(d.displayed_count,0) displayed_count FROM reciprocal_links l JOIN rss_feeds f ON f.reciprocal_link_id=l.id AND f.site_id=l.site_id AND f.active=1 LEFT JOIN reciprocal_rss_daily_displays d ON d.target_site_id=l.site_id AND d.reciprocal_link_id=l.id AND d.display_date=CURDATE() WHERE l.site_id=? AND l.status='approved' AND l.reciprocal_rss_enabled=1 AND l.allocation_type='rescue' ORDER BY COALESCE(d.displayed_count,0),l.id");$stmt->execute([$targetSiteId]);return$stmt->fetchAll();
    }
    private function rescueQuota(int $linkId):int{return 1+(abs(crc32(date('Y-m-d').'|'.$linkId))%3);}
    private function claimRescueDisplay(int $targetSiteId,int $linkId,int $quota):bool
    {
        $this->db->prepare('INSERT IGNORE INTO reciprocal_rss_daily_displays (target_site_id,reciprocal_link_id,display_date,displayed_count) VALUES (?,?,CURDATE(),0)')->execute([$targetSiteId,$linkId]);
        $stmt=$this->db->prepare('UPDATE reciprocal_rss_daily_displays SET displayed_count=displayed_count+1 WHERE target_site_id=? AND reciprocal_link_id=? AND display_date=CURDATE() AND displayed_count<?');$stmt->execute([$targetSiteId,$linkId,$quota]);return$stmt->rowCount()===1;
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
