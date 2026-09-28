<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use Asyura\Security;
$method=$_SERVER['REQUEST_METHOD']??'GET';
if(!in_array($method,['GET','POST'],true)){header('Allow: GET, POST');http_response_code(405);exit;}
header('Cache-Control: no-store');
$wid=(int)($_GET['wid']??0);$encoded=(string)($_GET['u']??'');$sig=(string)($_GET['sig']??'');$expected=hash_hmac('sha256',$wid.'|'.$encoded,$config['app_key']);
if($wid<1||!hash_equals($expected,$sig)){http_response_code(403);exit('Invalid link');}
$padded=strtr($encoded,'-_','+/');$padded.=str_repeat('=',(4-strlen($padded)%4)%4);$url=Security::safeUrl(base64_decode($padded,true)?:'');if($url===''){http_response_code(400);exit('Invalid URL');}
$stmt=$db->prepare('SELECT site_id FROM widgets WHERE id=? AND enabled=1');$stmt->execute([$wid]);$siteId=(int)$stmt->fetchColumn();if(!$siteId){http_response_code(404);exit;}
$hash=Security::ipHash($config['app_key'],date('Y-m-d'));$ua=mb_substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,500);$isBot=Security::isBotUserAgent($ua);$isSuspicious=$isBot||$ua===''||strlen($ua)<12;
$insert=$db->prepare("INSERT INTO raw_events (site_id,event_type,visitor_hash,page_url,normalized_page_url,target_url,widget_id,user_agent,is_bot,is_suspicious) VALUES (?,'widget_click',?,'widget://asyura','widget://asyura',?,?,?,?,?)");$insert->execute([$siteId,$hash,$url,$wid,$ua,$isBot?1:0,$isSuspicious?1:0]);
if(!$isSuspicious){$db->prepare('INSERT INTO daily_stats (site_id,stat_date,widget_clicks) VALUES (?,CURDATE(),1) ON DUPLICATE KEY UPDATE widget_clicks=widget_clicks+1')->execute([$siteId]);$normalized=\Asyura\UrlNormalizer::normalize($url);$db->prepare('INSERT INTO daily_link_stats (site_id,stat_date,target_hash,target_url,target_host,widget_clicks) VALUES (?,CURDATE(),?,?,?,1) ON DUPLICATE KEY UPDATE widget_clicks=widget_clicks+1,target_url=VALUES(target_url)')->execute([$siteId,hash('sha256',$normalized),$url,\Asyura\UrlNormalizer::host($url)]);}
// POST is measurement only; old GET links remain usable.
if($method==='POST'){http_response_code(204);exit;}
header('Location: '.$url, true, 302);header('X-Robots-Tag: noindex,nofollow');exit;
