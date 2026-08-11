<?php
declare(strict_types=1);

namespace Asyura;

use PDO;

final class WidgetRenderer
{
    public function __construct(private PDO $db, private array $config)
    {
    }

    public function find(string $publicId, string $type): ?array
    {
        $stmt=$this->db->prepare('SELECT w.*,s.name site_name FROM widgets w JOIN sites s ON s.id=w.site_id WHERE w.public_id=? AND w.type=? AND w.enabled=1 AND s.active=1 LIMIT 1');
        $stmt->execute([$publicId,$type]);
        return $stmt->fetch() ?: null;
    }

    public function ranking(array $widget): array
    {
        $days=(int)setting('ranking_period_days',3);
        $stmt=$this->db->prepare('SELECT referrer_host title,SUM(inbound) in_count,SUM(unique_inbound) unique_inbound FROM referrer_stats WHERE site_id=? AND stat_date>=CURDATE()-INTERVAL ? DAY GROUP BY referrer_host ORDER BY in_count DESC LIMIT ?');
        $stmt->bindValue(1,(int)$widget['site_id'],PDO::PARAM_INT);$stmt->bindValue(2,max(0,$days-1),PDO::PARAM_INT);$stmt->bindValue(3,min(500,(int)$widget['item_limit']*10),PDO::PARAM_INT);$stmt->execute();
        $excluded=$this->db->query('SELECT pattern,match_type FROM excluded_referrers WHERE active=1')->fetchAll();$siteMap=[];foreach($this->db->query('SELECT name,url,is_excluded FROM sites') as $s)$siteMap[UrlNormalizer::host($s['url'])]=['title'=>$s['name'],'url'=>$s['url'],'excluded'=>(bool)$s['is_excluded']];$linkMap=[];$mapStmt=$this->db->prepare("SELECT partner_name,partner_url,is_excluded FROM reciprocal_links WHERE site_id=? AND status='approved'");$mapStmt->execute([$widget['site_id']]);foreach($mapStmt as $l)$linkMap[UrlNormalizer::host($l['partner_url'])]=['title'=>$l['partner_name'],'url'=>$l['partner_url'],'excluded'=>(bool)$l['is_excluded']];$outMap=$this->outboundMap((int)$widget['site_id'],$days);$rows=[];$rank=0;foreach($stmt as $row){$skip=false;foreach($excluded as $rule){$skip=match($rule['match_type']){'exact'=>$row['title']===$rule['pattern'],'contains'=>str_contains($row['title'],$rule['pattern']),default=>$row['title']===$rule['pattern']||str_ends_with($row['title'],'.'.$rule['pattern'])};if($skip)break;}$mapped=$siteMap[$row['title']]??$linkMap[$row['title']]??null;if($mapped&&$mapped['excluded'])$skip=true;if($skip)continue;$rank++;$url=$mapped['url']??'https://'.$row['title'].'/';$title=$mapped['title']??$row['title'];$rows[]=['rank'=>$rank,'title'=>$title,'url'=>$url,'in_count'=>$row['in_count'],'out_count'=>$outMap[$row['title']]??0];if($rank>=(int)$widget['item_limit'])break;}return $rows;
    }

    public function links(array $widget): array
    {
        $slot=$widget['slot_code'];$stmt=$this->db->prepare("SELECT * FROM reciprocal_links WHERE site_id=? AND status='approved' AND is_excluded=0 AND FIND_IN_SET(?,slots)>0 ORDER BY is_special DESC,is_priority DESC,id DESC LIMIT ?");
        $stmt->bindValue(1,(int)$widget['site_id'],PDO::PARAM_INT);$stmt->bindValue(2,$slot);$stmt->bindValue(3,(int)$widget['item_limit'],PDO::PARAM_INT);$stmt->execute();$outMap=$this->outboundMap((int)$widget['site_id'],30);$rows=[];$rank=0;foreach($stmt as $r){$rank++;$host=UrlNormalizer::host($r['partner_url']);$rows[]=['rank'=>$rank,'title'=>$r['partner_name'],'url'=>$r['partner_url'],'description'=>$r['description'],'category'=>$r['category'],'in_count'=>0,'out_count'=>$outMap[$host]??0,'rel'=>$r['rel_type'],'target'=>$r['open_new_tab']?'_blank':'_self'];}return $rows;
    }

    public function rss(array $widget): array
    {
        $config=json_decode((string)$widget['config_json'],true)?:[];
        return (new DistributionService($this->db))->chooseItems((int)$widget['site_id'],(int)$widget['item_limit'],!empty($config['image_required']),array_map('intval',(array)($config['feed_ids']??[])));
    }

    public function notices(array $widget): array
    {
        $stmt=$this->db->prepare('SELECT title,body description,created_at published_at FROM notices WHERE (site_id IS NULL OR site_id=?) AND is_public=1 AND (starts_at IS NULL OR starts_at<=NOW()) AND (ends_at IS NULL OR ends_at>=NOW()) ORDER BY is_pinned DESC,created_at DESC LIMIT ?');
        $stmt->bindValue(1,(int)$widget['site_id'],PDO::PARAM_INT);$stmt->bindValue(2,(int)$widget['item_limit'],PDO::PARAM_INT);$stmt->execute();return $stmt->fetchAll();
    }

    public function render(array $widget, array $items): string
    {
        $out='';$rank=0;foreach($items as $item){$rank++;$url=(string)($item['url']??'');$safeUrl=$this->outUrl((int)$widget['id'],$url);$image=Security::safeUrl($item['image_url']??'');$imageTag=$image?'<img src="'.e($image).'" alt="" loading="lazy">':'';$map=[
            '{rank}'=>(string)($item['rank']??$rank),'{title}'=>e($item['title']??''),'{url}'=>e($safeUrl),
            '{description}'=>e($item['description']??''),'{category}'=>e($item['category']??''),
            '{in_count}'=>number_format((int)($item['in_count']??0)),'{out_count}'=>number_format((int)($item['out_count']??0)),
            '{site_name}'=>e($item['site_name']??''),'{rss_name}'=>e($item['rss_name']??''),
            '{image}'=>e($image),'{image_tag}'=>$imageTag,'{published_at}'=>e($item['published_at']??''),
            '{rel}'=>e($item['rel']??'nofollow'),'{target}'=>e($item['target']??'_blank'),
        ];$out.=strtr((string)$widget['template_html'],$map);}
        return $out;
    }

    private function outUrl(int $widgetId,string $url):string
    {
        $encoded=rtrim(strtr(base64_encode($url),'+/','-_'),'=');$sig=hash_hmac('sha256',$widgetId.'|'.$encoded,$this->config['app_key']);return app_url('api/out.php?wid='.$widgetId.'&u='.rawurlencode($encoded).'&sig='.$sig);
    }

    private function outboundMap(int $siteId,int $days):array
    {
        $stmt=$this->db->prepare('SELECT target_host,SUM(outbound_clicks+widget_clicks) clicks FROM daily_link_stats WHERE site_id=? AND stat_date>=CURDATE()-INTERVAL ? DAY GROUP BY target_host');$stmt->execute([$siteId,max(0,$days-1)]);$map=[];foreach($stmt as $row){if($row['target_host']!=='')$map[$row['target_host']]=(int)$row['clicks'];}return $map;
    }
}
