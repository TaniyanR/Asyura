<?php
declare(strict_types=1);
namespace Asyura;
use PDO;
final class RotationService
{
    public function __construct(private PDO $db){}
    public function item(array $feed):?array
    {
        $current=null;$configuredFeedIds=array_values(array_filter(array_map('intval',(array)(json_decode((string)($feed['feed_ids_json']??''),true)?:[]))));if($feed['current_article_id']&&$feed['current_since']&&strtotime($feed['current_since'])>time()-((int)$feed['interval_minutes']*60)){$q=$this->db->prepare('SELECT * FROM article_archive WHERE id=? AND site_id=? AND active=1');$q->execute([$feed['current_article_id'],$feed['site_id']]);$current=$q->fetch()?:null;if($current&&$feed['image_required']&&empty($current['image_url']))$current=null;if($current&&$feed['category']!==''&&$current['category']!==$feed['category'])$current=null;if($current&&$configuredFeedIds&&!in_array((int)$current['feed_id'],$configuredFeedIds,true))$current=null;}
        if($current)return $current;$where=['site_id=?','active=1'];$args=[(int)$feed['site_id']];$feedIds=$configuredFeedIds;if($feedIds){$where[]='feed_id IN ('.implode(',',array_fill(0,count($feedIds),'?')).')';$args=array_merge($args,$feedIds);}if($feed['category']){$where[]='category=?';$args[]=$feed['category'];}if($feed['image_required'])$where[]="image_url IS NOT NULL AND image_url<>''";$sql='SELECT * FROM article_archive WHERE '.implode(' AND ',$where).' ORDER BY COALESCE(last_rotated_at,\'1970-01-01\') ASC,id ASC LIMIT 50';$q=$this->db->prepare($sql);$q->execute($args);$candidates=$q->fetchAll();if(!$candidates)return null;$current=$candidates[random_int(0,count($candidates)-1)];$this->db->prepare('UPDATE rotation_feeds SET current_article_id=?,current_since=NOW() WHERE id=?')->execute([$current['id'],$feed['id']]);$this->db->prepare('UPDATE article_archive SET last_rotated_at=NOW(),rotation_count=rotation_count+1 WHERE id=?')->execute([$current['id']]);return $current;
    }
}
