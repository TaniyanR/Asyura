<?php
declare(strict_types=1);

require dirname(__DIR__).'/src/bootstrap.php';

use Asyura\Security;

$publicId=(string)($_GET['site']??$_POST['site']??'');
$stmt=$db->prepare('SELECT * FROM sites WHERE public_id=? AND active=1 AND links_enabled=1 LIMIT 1');
$stmt->execute([$publicId]);
$site=$stmt->fetch();
if(!$site){http_response_code(404);exit('フォームが見つかりません。');}

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; frame-ancestors *; form-action 'self'; base-uri 'none'");

$makeToken=static function(array $site,array $config):array{
    $a=random_int(1,9);$b=random_int(1,9);
    $data=['site'=>(int)$site['id'],'exp'=>time()+1800,'a'=>$a,'b'=>$b,'nonce'=>Security::randomToken(16)];
    $payload=rtrim(strtr(base64_encode(json_encode($data,JSON_THROW_ON_ERROR)),'+/','-_'),'=');
    return [$payload.'.'.hash_hmac('sha256',$payload,$config['app_key']),[$a,$b]];
};
[$formToken,$math]=$makeToken($site,$config);$error='';$complete=null;

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        $parts=explode('.',(string)($_POST['form_token']??''),2);
        if(count($parts)!==2||!hash_equals(hash_hmac('sha256',$parts[0],$config['app_key']),$parts[1]))throw new RuntimeException('画面の有効期限が切れました。');
        $padded=strtr($parts[0],'-_','+/');$padded.=str_repeat('=',(4-strlen($padded)%4)%4);
        $tokenData=json_decode(base64_decode($padded,true)?:'',true);
        if(!is_array($tokenData)||(int)($tokenData['site']??0)!==(int)$site['id']||(int)($tokenData['exp']??0)<time())throw new RuntimeException('画面の有効期限が切れました。');
        if(trim((string)($_POST['website_confirm']??''))!=='')throw new RuntimeException('送信できませんでした。');
        if(($_POST['consent']??'')!=='1')throw new RuntimeException('入力内容の送信に同意してください。');
        if((int)($_POST['captcha']??-1)!==((int)$tokenData['a']+(int)$tokenData['b']))throw new RuntimeException('画像認証の答えが違います。');

        $ipHash=Security::ipHash($config['app_key'],'contact');
        $rate=$db->prepare('SELECT COUNT(*) FROM link_requests WHERE site_id=? AND ip_hash=? AND created_at>=NOW()-INTERVAL 1 HOUR');
        $rate->execute([$site['id'],$ipHash]);
        if((int)$rate->fetchColumn()>=3)throw new RuntimeException('送信回数が上限に達しました。時間を置いてお試しください。');

        $siteName=Security::cleanText($_POST['site_name']??'',255);
        $siteUrl=Security::safeUrl($_POST['site_url']??'');
        $email=filter_var($_POST['email']??'',FILTER_VALIDATE_EMAIL);
        if($siteName===''||$siteUrl===''||!$email)throw new RuntimeException('サイト名、URL、メールアドレスを確認してください。');

        $receipt='ASY'.date('ymd').strtoupper(substr(Security::randomToken(5),0,10));
        $token=Security::randomToken(24);$tokenHash=hash('sha256',$token);
        $slots=array_values(array_intersect((array)($_POST['slots']??[]),range('A','E')));
        $insert=$db->prepare('INSERT INTO link_requests (site_id,receipt_no,status_token_hash,form_nonce_hash,site_name,site_url,manager_name,email,category,backlink_url,requested_slots,message,ip_hash) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $insert->execute([$site['id'],$receipt,$tokenHash,hash('sha256',(string)$tokenData['nonce']),$siteName,$siteUrl,Security::cleanText($_POST['manager_name']??'',255)?:null,$email,Security::cleanText($_POST['category']??'',100)?:null,Security::safeUrl($_POST['backlink_url']??'')?:null,implode(',',$slots),Security::cleanText($_POST['message']??'',5000)?:null,$ipHash]);

        if($site['admin_email']){
            $subject='[阿修羅] 相互リンク依頼 '.$receipt;
            $body="相互リンク依頼を受け付けました。\n受付番号: {$receipt}\nサイト: {$siteName}\nURL: {$siteUrl}\n";
            $from=str_replace(["\r","\n"],'',(string)($config['mail']['from']??'noreply@localhost'));
            @mail($site['admin_email'],$subject,$body,'From: '.$from."\r\nContent-Type: text/plain; charset=UTF-8");
        }
        $complete=['receipt'=>$receipt,'url'=>app_url('public/status.php?receipt='.rawurlencode($receipt).'&token='.rawurlencode($token))];
        header('X-Robots-Tag: noindex,nofollow,noarchive');
    }catch(Throwable $e){
        $error=$e instanceof PDOException&&$e->getCode()==='23000'?'同じフォームは送信済みです。':$e->getMessage();
        [$formToken,$math]=$makeToken($site,$config);
    }
}
$canonical=app_url('public/contact.php?site='.rawurlencode($site['public_id']));
?><!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>相互リンク依頼 | <?=e($site['name'])?></title><meta name="description" content="<?=e($site['name'])?>への相互リンク依頼フォームです。"><link rel="canonical" href="<?=e($canonical)?>"><style>body{margin:0;background:#f6f7f7;color:#1d2327;font:15px/1.65 -apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans JP",sans-serif}.wrap{max-width:720px;margin:20px auto;padding:24px;background:#fff;border:1px solid #dcdcde}h1{font-size:24px}.guide{padding:12px;margin:0 0 10px;background:#fff8e5;border-left:4px solid #dba617}.notice{padding:12px;border-left:4px solid #2271b1;background:#f0f6fc;margin:15px 0}.error{border-color:#d63638;background:#fcf0f1}label{display:block;font-weight:600;margin:14px 0}input,textarea{width:100%;box-sizing:border-box;padding:9px;border:1px solid #8c8f94;border-radius:3px;font:inherit}textarea{min-height:120px}.checks{display:flex;gap:12px;flex-wrap:wrap}.checks label{font-weight:400}.checks input{width:auto}.hidden-field{position:absolute;left:-9999px}.button{border:0;border-radius:3px;background:#2271b1;color:#fff;padding:10px 18px;font-weight:700;cursor:pointer}</style></head><body><main class="wrap"><h1>相互リンク依頼</h1>
<?php if($site['contact_ads_notice']):?><p class="guide">現在、広告は募集しておりません。</p><?php endif?><?php if($site['contact_links_notice']):?><p class="guide">現在、広告や相互リンクは募集しておりません。</p><?php endif?><?php if($site['contact_custom_enabled']&&$site['contact_custom_text']):?><p class="guide"><?=nl2br(e($site['contact_custom_text']))?></p><?php endif?>
<?php if($complete):?><div class="notice"><h2>受け付けました</h2><p>受付番号：<strong><?=e($complete['receipt'])?></strong></p><p><a href="<?=e($complete['url'])?>">申請状況を確認する</a></p><p>確認URLは再表示できないため、保存してください。</p></div><?php else:?>
<?php if($error):?><div class="notice error"><?=e($error)?></div><?php endif?>
<form method="post" autocomplete="on"><input type="hidden" name="site" value="<?=e($site['public_id'])?>"><input type="hidden" name="form_token" value="<?=e($formToken)?>"><label class="hidden-field">空欄にしてください<input name="website_confirm" tabindex="-1" autocomplete="off"></label><label>サイト名<input name="site_name" maxlength="255" required></label><label>サイトURL<input type="url" name="site_url" maxlength="2048" required></label><label>管理者名<input name="manager_name" maxlength="255"></label><label>メールアドレス<input type="email" name="email" maxlength="255" required></label><label>カテゴリー<input name="category" maxlength="100"></label><label>リンク設置ページURL<input type="url" name="backlink_url" maxlength="2048"></label><div class="checks"><?php foreach(range('A','E') as $slot):?><label><input type="checkbox" name="slots[]" value="<?=$slot?>"><?=$slot?>を希望</label><?php endforeach?></div><label>メッセージ<textarea name="message" maxlength="5000"></textarea></label><label>確認：<?=e($math[0])?> ＋ <?=e($math[1])?> ＝ <input type="number" name="captcha" required></label><div class="checks"><label><input type="checkbox" name="consent" value="1" required>入力内容の送信に同意します</label></div><button class="button">相互リンクを依頼する</button></form><?php endif?></main></body></html>
