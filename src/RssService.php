<?php
declare(strict_types=1);

namespace Asyura;

use PDO;

final class RssService
{
    private const MAX_BYTES = 2097152;

    public function __construct(private PDO $db)
    {
    }

    public function fetchDue(): array
    {
        $feeds=$this->db->query("SELECT f.* FROM rss_feeds f JOIN sites s ON s.id=f.site_id WHERE f.active=1 AND s.active=1 AND (f.last_fetched_at IS NULL OR f.last_fetched_at<=NOW()-INTERVAL 25 MINUTE)")->fetchAll();
        $result=['success'=>0,'failed'=>0];
        foreach($feeds as $feed){try{$this->fetchOne($feed);$result['success']++;}catch(\Throwable $e){$result['failed']++;$this->db->prepare('UPDATE rss_feeds SET last_fetched_at=NOW(),last_error=? WHERE id=?')->execute([mb_substr($e->getMessage(),0,1000),$feed['id']]);}}
        return $result;
    }

    public function fetchOne(array $feed): int
    {
        $response=$this->download((string)$feed['feed_url'],(string)($feed['etag']??''),(string)($feed['last_modified']??''));
        if($response['status']===304){$this->db->prepare('UPDATE rss_feeds SET last_fetched_at=NOW(),last_success_at=NOW(),last_error=NULL WHERE id=?')->execute([$feed['id']]);return 0;}
        libxml_use_internal_errors(true);
        $xml=simplexml_load_string($response['body'],'SimpleXMLElement',LIBXML_NONET|LIBXML_NOCDATA|LIBXML_COMPACT);
        if(!$xml){throw new \RuntimeException('RSS/XMLを解析できません。');}
        $items=[];
        if(isset($xml->channel->item)){foreach($xml->channel->item as $item)$items[]=$this->parseRssItem($item);}
        else{$namespaces=$xml->getNamespaces(true);$atom=$xml;if(isset($namespaces['']))$atom=$xml->children($namespaces['']);if(isset($atom->entry)){foreach($atom->entry as $item)$items[]=$this->parseAtomItem($item);}else{throw new \RuntimeException('RSS2またはAtom形式ではありません。');}}
        $count=0;$this->db->beginTransaction();try{$itemStmt=$this->db->prepare('INSERT INTO rss_items (feed_id,site_id,guid_hash,title,url,normalized_url,description,category,image_url,published_at,fetched_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE title=VALUES(title),url=VALUES(url),description=VALUES(description),category=VALUES(category),image_url=VALUES(image_url),published_at=VALUES(published_at),fetched_at=NOW()');$archive=$this->db->prepare('INSERT INTO article_archive (site_id,feed_id,url_hash,title,url,description,category,image_url,original_published_at) VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE title=VALUES(title),description=VALUES(description),category=VALUES(category),image_url=COALESCE(VALUES(image_url),image_url),last_seen_at=NOW()');foreach(array_slice($items,0,200) as $item){if($item['url']===''||$item['title']==='')continue;$guidHash=hash('sha256',$item['guid']?:$item['url']);$urlHash=hash('sha256',UrlNormalizer::normalize($item['url']));$itemStmt->execute([$feed['id'],$feed['site_id'],$guidHash,$item['title'],$item['url'],UrlNormalizer::normalize($item['url']),$item['description']?:null,$item['category']?:null,$item['image']?:null,$item['published']]);$archive->execute([$feed['site_id'],$feed['id'],$urlHash,$item['title'],$item['url'],$item['description']?:null,$item['category']?:null,$item['image']?:null,$item['published']]);$count++;}$this->db->prepare('UPDATE rss_feeds SET last_fetched_at=NOW(),last_success_at=NOW(),last_error=NULL,etag=?,last_modified=? WHERE id=?')->execute([$response['etag']?:null,$response['last_modified']?:null,$feed['id']]);$this->db->commit();}catch(\Throwable $e){$this->db->rollBack();throw $e;}return $count;
    }

    private function parseRssItem(\SimpleXMLElement $item): array
    {
        $contentNs=$item->children('http://purl.org/rss/1.0/modules/content/');$media=$item->children('http://search.yahoo.com/mrss/');$content=(string)($contentNs->encoded??'');$description=(string)($item->description??'');$image='';
        if(isset($media->thumbnail))$image=(string)$media->thumbnail['url'];
        if($image===''&&isset($media->content))$image=(string)$media->content['url'];
        if($image===''&&isset($item->enclosure)&&str_starts_with((string)$item->enclosure['type'],'image/'))$image=(string)$item->enclosure['url'];
        if($image==='')$image=$this->imageFromHtml($content?:$description);
        $date=strtotime((string)($item->pubDate??''));
        return ['title'=>Security::cleanText((string)$item->title,1000),'url'=>Security::safeUrl((string)$item->link),'guid'=>Security::cleanText((string)($item->guid??''),2048),'description'=>Security::cleanText($description?:$content,5000),'category'=>Security::cleanText((string)($item->category??''),255),'image'=>Security::safeUrl($image),'published'=>$date?date('Y-m-d H:i:s',$date):null];
    }

    private function parseAtomItem(\SimpleXMLElement $item): array
    {
        $url='';foreach($item->link as $link){$rel=(string)$link['rel'];if($rel===''||$rel==='alternate'){$url=(string)$link['href'];break;}}$content=(string)($item->content??$item->summary??'');$date=strtotime((string)($item->published??$item->updated??''));return ['title'=>Security::cleanText((string)$item->title,1000),'url'=>Security::safeUrl($url),'guid'=>Security::cleanText((string)($item->id??''),2048),'description'=>Security::cleanText($content,5000),'category'=>Security::cleanText((string)($item->category['term']??''),255),'image'=>Security::safeUrl($this->imageFromHtml($content)),'published'=>$date?date('Y-m-d H:i:s',$date):null];
    }

    private function imageFromHtml(string $html): string
    {
        return preg_match('~<img[^>]+src=["\']([^"\']+)["\']~i',$html,$m)?html_entity_decode($m[1],ENT_QUOTES|ENT_HTML5,'UTF-8'):'';
    }

    private function download(string $url,string $etag='',string $modified=''): array
    {
        if(!extension_loaded('curl'))throw new \RuntimeException('PHP cURL拡張が必要です。');$current=$url;
        for($redirect=0;$redirect<=3;$redirect++){$resolved=$this->assertPublicUrl($current);$headers=[];if($etag!=='')$headers[]='If-None-Match: '.str_replace(["\r","\n"],'',$etag);if($modified!=='')$headers[]='If-Modified-Since: '.str_replace(["\r","\n"],'',$modified);$received='';$responseHeaders=[];$ch=curl_init($current);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_USERAGENT=>'Asyura RSS Reader/1.0',CURLOPT_HTTPHEADER=>$headers,CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_RESOLVE=>[$resolved['host'].':'.$resolved['port'].':'.$resolved['ip']],CURLOPT_HEADERFUNCTION=>static function($ch,$line)use(&$responseHeaders){$len=strlen($line);$p=strpos($line,':');if($p!==false)$responseHeaders[strtolower(trim(substr($line,0,$p)))]=trim(substr($line,$p+1));return $len;},CURLOPT_WRITEFUNCTION=>static function($ch,$chunk)use(&$received){if(strlen($received)+strlen($chunk)>self::MAX_BYTES)return 0;$received.=$chunk;return strlen($chunk);}]);$ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$error=curl_error($ch);curl_close($ch);if($ok===false&&strlen($received)<self::MAX_BYTES)throw new \RuntimeException('RSS取得失敗：'.$error);if($status===304)return ['status'=>304,'body'=>'','etag'=>$etag,'last_modified'=>$modified];if(in_array($status,[301,302,303,307,308],true)&&isset($responseHeaders['location'])){$current=$this->resolveUrl($current,$responseHeaders['location']);continue;}if($status<200||$status>=300)throw new \RuntimeException('RSSのHTTP状態：'.$status);return ['status'=>$status,'body'=>$received,'etag'=>str_replace(["\r","\n"],'',$responseHeaders['etag']??''),'last_modified'=>str_replace(["\r","\n"],'',$responseHeaders['last-modified']??'')];}throw new \RuntimeException('RSSの転送回数が上限を超えました。');
    }

    private function assertPublicUrl(string $url): array
    {
        $safe=Security::safeUrl($url);if($safe==='')throw new \RuntimeException('RSS URLが不正です。');$host=(string)parse_url($safe,PHP_URL_HOST);$ips=gethostbynamel($host)?:[];if(!$ips)throw new \RuntimeException('RSSホストを確認できません。');foreach($ips as $ip){if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))throw new \RuntimeException('内部ネットワークのURLは取得できません。');}$scheme=(string)parse_url($safe,PHP_URL_SCHEME);$port=(int)(parse_url($safe,PHP_URL_PORT)?:($scheme==='https'?443:80));return ['host'=>$host,'ip'=>$ips[0],'port'=>$port];
    }

    private function resolveUrl(string $base,string $location): string
    {
        if(preg_match('~^https?://~i',$location))return $location;$p=parse_url($base);if(str_starts_with($location,'/'))return $p['scheme'].'://'.$p['host'].(isset($p['port'])?':'.$p['port']:'').$location;$dir=rtrim(dirname($p['path']??'/'),'/');return $p['scheme'].'://'.$p['host'].(isset($p['port'])?':'.$p['port']:'').$dir.'/'.$location;
    }
}
