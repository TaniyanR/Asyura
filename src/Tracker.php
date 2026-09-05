<?php
declare(strict_types=1);

namespace Asyura;

use PDO;

final class Tracker
{
    private const EVENTS = ['pageview','internal_click','outbound','widget_click','engagement'];

    public function __construct(private PDO $db, private array $config) {}

    public function findSite(string $publicId, string $key): ?array
    {
        $stmt=$this->db->prepare('SELECT * FROM sites WHERE public_id=? AND active=1 LIMIT 1');
        $stmt->execute([mb_substr($publicId,0,32)]);$site=$stmt->fetch();
        return $site&&hash_equals((string)$site['site_key'],$key)?$site:null;
    }

    public function originAllowed(array $site,string $origin):bool
    {
        $host=UrlNormalizer::host($origin);if($host==='')return false;
        if($host===UrlNormalizer::host((string)$site['url']))return true;
        $stmt=$this->db->prepare('SELECT alias_url FROM site_aliases WHERE site_id=? AND allow_tracking_origin=1');$stmt->execute([(int)$site['id']]);
        foreach($stmt as $alias)if($host===UrlNormalizer::host((string)$alias['alias_url']))return true;
        return false;
    }

    public function enforceRateLimit(array $site):void
    {
        $hash=$this->requestHash();$window=date('Y-m-d H:i:00');
        $this->db->prepare('INSERT INTO tracking_rate_limits (site_id,request_hash,window_start,hits) VALUES (?,?,?,1) ON DUPLICATE KEY UPDATE hits=hits+1')->execute([(int)$site['id'],$hash,$window]);
        $q=$this->db->prepare('SELECT hits FROM tracking_rate_limits WHERE site_id=? AND request_hash=? AND window_start=?');$q->execute([(int)$site['id'],$hash,$window]);
        if((int)$q->fetchColumn()>120){$this->logSecurity((int)$site['id'],'rate_limit','critical','1分間の計測上限を超えました。',(string)($_SERVER['HTTP_ORIGIN']??''));throw new TrackingRejectedException('Rate limit exceeded.',429,'rate_limit');}
    }

    public function logSecurity(?int $siteId,string $code,string $severity,string $details,string $origin=''):void
    {
        $requestHash=$this->requestHash();$originHost=UrlNormalizer::host($origin)?:null;
        $fingerprint=hash('sha256',($siteId??0).'|'.$code.'|'.$requestHash.'|'.$originHost.'|'.date('Y-m-d H:i'));
        $stmt=$this->db->prepare('INSERT INTO tracking_security_events (site_id,fingerprint,event_code,severity,request_hash,origin_host,user_agent,details) VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE hit_count=hit_count+1,last_seen_at=NOW(),details=VALUES(details)');
        $stmt->execute([$siteId,$fingerprint,$code,in_array($severity,['info','warning','critical'],true)?$severity:'warning',$requestHash,$originHost,mb_substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,500),mb_substr($details,0,1000)]);
    }

    public function record(array $site,array $payload):void
    {
        $eventType=(string)($payload['event_type']??'');
        if(!in_array($eventType,self::EVENTS,true))throw new TrackingRejectedException('Invalid event type.',400,'invalid_payload');
        $pageUrl=Security::safeUrl($payload['page_url']??'');
        if($pageUrl===''||!$this->originAllowed($site,$pageUrl))throw new TrackingRejectedException('Page URL is not registered.',403,'invalid_page');
        $eventId=$this->identifier((string)($payload['event_id']??''),36);$pageviewId=$this->identifier((string)($payload['pageview_id']??''),36);
        if($eventId===''||$pageviewId==='')throw new TrackingRejectedException('Event identifiers are missing.',400,'invalid_payload');

        $ua=mb_substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,500);$isBot=$this->isBot($ua);$isSuspicious=$isBot||$ua===''||strlen($ua)<12;
        if($isSuspicious)$this->logSecurity((int)$site['id'],'automation_detected','warning','Botまたは自動化されたアクセスを検知しました。',(string)($_SERVER['HTTP_ORIGIN']??''));
        $visitorToken=$this->identifier((string)($payload['visitor_id']??''));$sessionToken=$this->identifier((string)($payload['session_id']??''));
        $visitorSource=$visitorToken!==''?$visitorToken:Security::clientIp().'|'.$ua.'|'.date('Y-m-d');
        $sessionSource=$sessionToken!==''?$sessionToken:Security::clientIp().'|'.$ua.'|'.floor(time()/1800);
        $scope=(string)$site['id'].'|';$visitorHash=hash_hmac('sha256',$scope.$visitorSource,(string)$this->config['app_key']);$sessionHash=hash_hmac('sha256',$scope.$sessionSource,(string)$this->config['app_key']);
        $normalized=UrlNormalizer::normalize($pageUrl);
        if($eventType==='engagement'){$this->recordEngagement((int)$site['id'],$pageviewId,$sessionHash,$payload);return;}

        $referrer=Security::safeUrl($payload['referrer']??'');$referrerHost=$referrer!==''?$this->resolveReferrerHost($referrer,UrlNormalizer::host($referrer)):'';
        if($referrerHost===UrlNormalizer::host((string)$site['url']))$referrerHost='';
        $channel=$this->channel($referrerHost);$target=Security::safeUrl($payload['target_url']??'');$widgetId=ctype_digit((string)($payload['widget_id']??''))?(int)$payload['widget_id']:null;
        $device=preg_match('/mobile|android|iphone|ipad/i',$ua)?'mobile':'desktop';$browser=$this->browser($ua);$os=$this->os($ua);$today=date('Y-m-d');
        $this->db->beginTransaction();
        try{
            $raw=$this->db->prepare('INSERT IGNORE INTO raw_events (event_id,pageview_id,site_id,event_type,visitor_hash,session_hash,page_url,normalized_page_url,referrer_url,referrer_host,channel,target_url,widget_id,page_title,user_agent,device,browser,os,is_bot,is_suspicious) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $raw->execute([$eventId,$pageviewId,(int)$site['id'],$eventType,$visitorHash,$sessionHash,$pageUrl,$normalized,$referrer?:null,$referrerHost?:null,$channel,$target?:null,$widgetId,Security::cleanText($payload['title']??'',500),$ua,$device,$browser,$os,$isBot?1:0,$isSuspicious?1:0]);
            if($raw->rowCount()===0){$this->db->rollBack();return;}
            $unique=false;$uniqueReferrer=false;
            if($eventType==='pageview'){
                $page=$this->db->prepare('INSERT IGNORE INTO analytics_pageviews (site_id,pageview_id,session_hash,visitor_hash,page_url,normalized_page_url,page_title,started_at,last_seen_at,is_bot,is_suspicious) VALUES (?,?,?,?,?,?,?,NOW(),NOW(),?,?)');$page->execute([(int)$site['id'],$pageviewId,$sessionHash,$visitorHash,$pageUrl,$normalized,Security::cleanText($payload['title']??'',500),$isBot?1:0,$isSuspicious?1:0]);
                $session=$this->db->prepare('INSERT INTO analytics_sessions (site_id,session_hash,visitor_hash,started_at,last_seen_at,pageviews,channel,referrer_host,landing_page,exit_page,device,browser,os,is_bot,is_suspicious) VALUES (?,?,?,NOW(),NOW(),1,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE last_seen_at=NOW(),pageviews=pageviews+1,exit_page=VALUES(exit_page),is_suspicious=GREATEST(is_suspicious,VALUES(is_suspicious))');
                $session->execute([(int)$site['id'],$sessionHash,$visitorHash,$channel,$referrerHost?:null,$normalized,$normalized,$device,$browser,$os,$isBot?1:0,$isSuspicious?1:0]);
                if(!$isBot){$check=$this->db->prepare('INSERT IGNORE INTO daily_visitors (site_id,visit_date,visitor_hash) VALUES (?,?,?)');$check->execute([(int)$site['id'],$today,$visitorHash]);$unique=$check->rowCount()===1;if($referrerHost!==''&&!$this->excludedReferrer($referrerHost)){$refCheck=$this->db->prepare('INSERT IGNORE INTO daily_referrer_visitors (site_id,visit_date,referrer_host,visitor_hash) VALUES (?,?,?,?)');$refCheck->execute([(int)$site['id'],$today,$referrerHost,$visitorHash]);$uniqueReferrer=$refCheck->rowCount()===1;}$this->recordConversions((int)$site['id'],$pageviewId,$sessionHash,$visitorHash,$normalized);}
            }
            if(!$isBot){$pv=$eventType==='pageview'?1:0;$out=$eventType==='outbound'?1:0;$internal=$eventType==='internal_click'?1:0;$click=$eventType==='widget_click'?1:0;$daily=$this->db->prepare('INSERT INTO daily_stats (site_id,stat_date,pv,uu,outbound,internal_clicks,widget_clicks) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE pv=pv+VALUES(pv),uu=uu+VALUES(uu),outbound=outbound+VALUES(outbound),internal_clicks=internal_clicks+VALUES(internal_clicks),widget_clicks=widget_clicks+VALUES(widget_clicks)');$daily->execute([(int)$site['id'],$today,$pv,$unique?1:0,$out,$internal,$click]);if($eventType!=='pageview'&&$target!==''){$n=UrlNormalizer::normalize($target);$link=$this->db->prepare('INSERT INTO daily_link_stats (site_id,stat_date,target_hash,target_url,target_host,internal_clicks,outbound_clicks,widget_clicks) VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE internal_clicks=internal_clicks+VALUES(internal_clicks),outbound_clicks=outbound_clicks+VALUES(outbound_clicks),widget_clicks=widget_clicks+VALUES(widget_clicks),target_url=VALUES(target_url)');$link->execute([(int)$site['id'],$today,hash('sha256',$n),$target,UrlNormalizer::host($target),$internal,$out,$click]);}if($eventType==='pageview'&&$referrerHost!==''&&!$this->excludedReferrer($referrerHost)){$ref=$this->db->prepare('INSERT INTO referrer_stats (site_id,stat_date,referrer_host,inbound,unique_inbound) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE inbound=inbound+1,unique_inbound=unique_inbound+VALUES(unique_inbound)');$ref->execute([(int)$site['id'],$today,$referrerHost,1,$uniqueReferrer?1:0]);}}
            $this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    private function recordEngagement(int $siteId,string $pageviewId,string $sessionHash,array $payload):void
    {
        $ms=min(43200000,max(0,(int)($payload['engagement_ms']??0)));$scroll=min(100,max(0,(int)($payload['scroll_depth']??0)));$this->db->beginTransaction();
        try{$q=$this->db->prepare('SELECT engagement_ms FROM analytics_pageviews WHERE site_id=? AND pageview_id=? AND session_hash=? FOR UPDATE');$q->execute([$siteId,$pageviewId,$sessionHash]);$old=$q->fetchColumn();if($old!==false){$delta=max(0,$ms-(int)$old);$this->db->prepare('UPDATE analytics_pageviews SET engagement_ms=GREATEST(engagement_ms,?),scroll_depth=GREATEST(scroll_depth,?),last_seen_at=NOW() WHERE site_id=? AND pageview_id=? AND session_hash=?')->execute([$ms,$scroll,$siteId,$pageviewId,$sessionHash]);if($delta>0)$this->db->prepare('UPDATE analytics_sessions SET engagement_ms=engagement_ms+?,last_seen_at=NOW() WHERE site_id=? AND session_hash=?')->execute([$delta,$siteId,$sessionHash]);}$this->db->commit();}catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    private function recordConversions(int $siteId,string $pageviewId,string $sessionHash,string $visitorHash,string $page):void
    {
        $q=$this->db->prepare('SELECT id,url_pattern,match_type FROM conversion_rules WHERE site_id=? AND active=1');$q->execute([$siteId]);foreach($q as $r){$p=(string)$r['url_pattern'];$match=match($r['match_type']){'exact'=>$page===$p,'prefix'=>str_starts_with($page,$p),default=>str_contains($page,$p)};if(!$match)continue;$i=$this->db->prepare('INSERT IGNORE INTO conversion_events (site_id,rule_id,pageview_id,session_hash,visitor_hash,page_url) VALUES (?,?,?,?,?,?)');$i->execute([$siteId,(int)$r['id'],$pageviewId,$sessionHash,$visitorHash,$page]);if($i->rowCount()===1)$this->db->prepare('UPDATE analytics_sessions SET conversion_count=conversion_count+1 WHERE site_id=? AND session_hash=?')->execute([$siteId,$sessionHash]);}
    }

    private function identifier(string $v,int $max=64):string{return strlen($v)>=16&&strlen($v)<=$max&&preg_match('/^[A-Za-z0-9_-]+$/',$v)===1?$v:'';}
    private function requestHash():string{return hash_hmac('sha256',Security::clientIp(),(string)$this->config['app_key']);}
    private function isBot(string $ua):bool{return preg_match('/bot|crawler|spider|slurp|headless|preview|facebookexternalhit|bingpreview|curl|wget|python-requests|httpclient|phantomjs|selenium|playwright|puppeteer|scrapy|semrush|ahrefs|mj12bot|bytespider|petalbot/i',$ua)===1;}
    private function channel(string $host):string{if($host==='')return'direct';if(preg_match('/google\.|bing\.|yahoo\.|duckduckgo\.|baidu\.|yandex\./i',$host))return'search';if(preg_match('/(^|\.)(x\.com|twitter\.com|facebook\.com|instagram\.com|tiktok\.com|youtube\.com|line\.me)$/i',$host))return'social';return'referral';}
    private function excludedReferrer(string $host):bool{$q=$this->db->query('SELECT pattern,match_type FROM excluded_referrers WHERE active=1');foreach($q as $r){$p=mb_strtolower((string)$r['pattern']);$h=mb_strtolower($host);$m=match($r['match_type']){'exact'=>$h===$p,'contains'=>str_contains($h,$p),default=>$h===$p||str_ends_with($h,'.'.$p)};if($m)return true;}return false;}
    private function browser(string $ua):string{return match(true){str_contains($ua,'Edg/')=>'Edge',str_contains($ua,'OPR/')=>'Opera',str_contains($ua,'Chrome/')=>'Chrome',str_contains($ua,'Firefox/')=>'Firefox',str_contains($ua,'Safari/')=>'Safari',default=>'Other'};}
    private function os(string $ua):string{return match(true){preg_match('/iPhone|iPad/',$ua)===1=>'iOS',str_contains($ua,'Android')=>'Android',str_contains($ua,'Windows')=>'Windows',str_contains($ua,'Mac OS')=>'macOS',str_contains($ua,'Linux')=>'Linux',default=>'Other'};}
    private function resolveReferrerHost(string $url,string $host):string{$n=UrlNormalizer::normalize($url);$q=$this->db->query('SELECT a.alias_url,a.normalized_url,a.match_type,s.url site_url FROM site_aliases a JOIN sites s ON s.id=a.site_id WHERE s.active=1');foreach($q as $a){$m=match($a['match_type']){'exact'=>$n===$a['normalized_url'],'prefix'=>str_starts_with($n,$a['normalized_url']),'contains'=>str_contains($n,$a['normalized_url'])||str_contains($url,$a['alias_url']),default=>$host===UrlNormalizer::host($a['alias_url'])};if($m)return UrlNormalizer::host($a['site_url']);}return$host;}
}
