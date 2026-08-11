<?php
declare(strict_types=1);

namespace Asyura;

use PDO;

final class DistributionService
{
    public function __construct(private PDO $db)
    {
    }

    public function calculate(int $targetSiteId): array
    {
        $hours = (int) setting('distribution_window_hours', 24);
        $sources = $this->db->query('SELECT * FROM sites WHERE active=1 AND rss_enabled=1 AND is_excluded=0')->fetchAll();
        $inStmt = $this->db->prepare("SELECT COUNT(*) FROM raw_events WHERE site_id=? AND event_type='pageview' AND is_bot=0 AND referrer_host=? AND occurred_at>=DATE_SUB(NOW(),INTERVAL ? HOUR)");
        $weights = [];
        foreach ($sources as $source) {
            if ((int) $source['id'] === $targetSiteId) {
                continue;
            }
            $host = UrlNormalizer::host($source['url']);
            $inStmt->execute([$targetSiteId, $host, $hours]);
            $inbound = (int) $inStmt->fetchColumn();
            $weight = !empty($source['is_special']) ? (float) $source['special_points'] : (float) $inbound;
            $reason = !empty($source['is_special']) ? '特別' : '通常';
            if (!empty($source['is_rescue']) && $weight < (float) $source['rescue_min_points']) {
                $weight = (float) $source['rescue_min_points'];
                $reason .= '＋弱小救済';
            }
            if (!empty($source['is_priority'])) {
                $weight *= (float) $source['priority_multiplier'];
                $reason .= '＋優遇';
            }
            if ($weight > 0) {
                $weights[] = ['site_id'=>(int)$source['id'],'inbound'=>$inbound,'weight'=>$weight,'reason'=>$reason];
            }
        }
        $total = array_sum(array_column($weights, 'weight'));
        $this->db->beginTransaction();
        try {
            $batchId=Security::randomToken(8);$this->db->prepare('INSERT INTO rss_distribution_batches (batch_id,target_site_id) VALUES (?,?)')->execute([$batchId,$targetSiteId]);$stmt = $this->db->prepare('INSERT INTO rss_distribution_history (target_site_id,source_site_id,batch_id,inbound,base_weight,final_percent,reason) VALUES (?,?,?,?,?,?,?)');
            foreach ($weights as &$row) {
                $row['percent'] = $total > 0 ? ($row['weight'] / $total) * 100 : 0;
                $stmt->execute([$targetSiteId,$row['site_id'],$batchId,$row['inbound'],$row['weight'],$row['percent'],$row['reason']]);
            }
            unset($row);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $weights;
    }

    public function latest(int $targetSiteId): array
    {
        $batch=$this->db->prepare('SELECT batch_id FROM rss_distribution_batches WHERE target_site_id=? ORDER BY id DESC LIMIT 1');$batch->execute([$targetSiteId]);$batchId=$batch->fetchColumn();if(!$batchId)return $this->calculate($targetSiteId);$stmt = $this->db->prepare('SELECT * FROM rss_distribution_history WHERE target_site_id=? AND batch_id=? ORDER BY final_percent DESC');
        $stmt->execute([$targetSiteId,$batchId]);
        return $stmt->fetchAll();
    }

    public function chooseItems(int $targetSiteId, int $limit, bool $imageRequired = false, array $allowedFeedIds = []): array
    {
        $weights = $this->latest($targetSiteId);
        if (!$weights) {
            return [];
        }
        $result = [];
        $used = [];
        for ($i = 0; $i < $limit; $i++) {
            $source = $this->weightedSite($weights);
            if (!$source) break;
            $sql = 'SELECT i.*,s.name site_name,f.name rss_name FROM rss_items i JOIN sites s ON s.id=i.site_id JOIN rss_feeds f ON f.id=i.feed_id WHERE i.site_id=?';
            if ($imageRequired) $sql .= " AND i.image_url IS NOT NULL AND i.image_url<>''";
            if ($allowedFeedIds) $sql .= ' AND i.feed_id IN (' . implode(',', array_fill(0, count($allowedFeedIds), '?')) . ')';
            if ($used) $sql .= ' AND i.id NOT IN (' . implode(',', array_fill(0, count($used), '?')) . ')';
            $sql .= ' ORDER BY i.published_at DESC,i.id DESC LIMIT 50';
            $stmt = $this->db->prepare($sql);
            $stmt->execute(array_merge([(int)($source['source_site_id'] ?? $source['site_id'])], $allowedFeedIds, $used));
            $candidates = $stmt->fetchAll();
            if (!$candidates) { $weights=array_values(array_filter($weights,fn($w)=>(int)($w['source_site_id']??$w['site_id'])!==(int)($source['source_site_id']??$source['site_id']))); $i--; if(!$weights)break; continue; }
            $item = $candidates[random_int(0, count($candidates)-1)];
            $used[] = (int) $item['id'];
            $result[] = $item;
        }
        return $result;
    }

    private function weightedSite(array $weights): ?array
    {
        $total = array_sum(array_map(static fn($w)=>(float)($w['final_percent'] ?? $w['percent'] ?? $w['weight'] ?? 0),$weights));
        if ($total <= 0) return null;
        $pick = (random_int(0, 1000000) / 1000000) * $total;
        foreach ($weights as $weight) {
            $pick -= (float) ($weight['final_percent'] ?? $weight['percent'] ?? $weight['weight'] ?? 0);
            if ($pick <= 0) return $weight;
        }
        return end($weights) ?: null;
    }
}
