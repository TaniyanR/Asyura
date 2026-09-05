<?php
declare(strict_types=1);

require dirname(__DIR__).'/src/bootstrap.php';

use Asyura\Security;
use Asyura\Tracker;

$publicId=(string)($_GET['site']??$_POST['site']??'');
$stmt=$db->prepare('SELECT * FROM sites WHERE public_id=? AND active=1 LIMIT 1');
$stmt->execute([$publicId]);
$site=$stmt->fetch();
if(!$site){http_response_code(404);exit('フォームが見つかりません。');}

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; frame-ancestors *; form-action 'self'; base-uri 'none'");

$logSecurity=static function(string $code,string $severity,string $details)use($db,$config,$site):void{
    try{(new Tracker($db,$config))->logSecurity((int)$site['id'],$code,$severity,$details,(string)($_SERVER['HTTP_ORIGIN']??($_SERVER['HTTP_REFERER']??'')));}catch(Throwable $e){error_log('[Asyura inquiry security log] '.$e->getMessage());}
};
$makeToken=static function(array $site,array $config):array{
    $a=random_int(1,9);$b=random_int(1,9);
    $data=['site'=>(int)$site['id'],'exp'=>time()+1800,'a'=>$a,'b'=>$b,'nonce'=>Security::randomToken(16)];
    $payload=rtrim(strtr(base64_encode(json_encode($data,JSON_THROW_ON_ERROR)),'+/','-_'),'=');
    return[$payload.'.'.hash_hmac('sha256',$payload,(string)$config['app_key']),[$a,$b]];
};

[$formToken,$math]=$makeToken($site,$config);$error='';$complete=false;$receipt='';
$old=['sender_name'=>'','sender_email'=>'','subject'=>'','message'=>''];

if($_SERVER['REQUEST_METHOD']==='POST'){
    foreach($old as$key=>$unused)$old[$key]=Security::cleanText($_POST[$key]??'',$key==='message'?10000:255);
    try{
        if((int)($_SERVER['CONTENT_LENGTH']??0)>32768)throw new InvalidArgumentException('送信内容が大きすぎます。');
        $ipHash=Security::ipHash((string)$config['app_key'],'inquiry');$window=date('Y-m-d H:i:00');$bucket=hash_hmac('sha256','inquiry|'.Security::clientIp(),(string)$config['app_key']);
        $db->prepare('INSERT INTO tracking_rate_limits (site_id,request_hash,window_start,hits) VALUES (?,?,?,1) ON DUPLICATE KEY UPDATE hits=hits+1')->execute([(int)$site['id'],$bucket,$window]);
        $rate=$db->prepare('SELECT hits FROM tracking_rate_limits WHERE site_id=? AND request_hash=? AND window_start=?');$rate->execute([(int)$site['id'],$bucket,$window]);
        if((int)$rate->fetchColumn()>10){$logSecurity('inquiry_rate_limit','critical','一般お問い合わせフォームの1分間の送信試行上限を超えました。');throw new InvalidArgumentException('送信回数が上限に達しました。時間を置いてお試しください。');}

        $parts=explode('.',(string)($_POST['form_token']??''),2);
        if(count($parts)!==2||!hash_equals(hash_hmac('sha256',$parts[0],(string)$config['app_key']),$parts[1]))throw new InvalidArgumentException('画面の有効期限が切れました。');
        $padded=strtr($parts[0],'-_','+/');$padded.=str_repeat('=',(4-strlen($padded)%4)%4);$tokenData=json_decode(base64_decode($padded,true)?:'',true);
        if(!is_array($tokenData)||(int)($tokenData['site']??0)!==(int)$site['id']||(int)($tokenData['exp']??0)<time())throw new InvalidArgumentException('画面の有効期限が切れました。');
        if(trim((string)($_POST['website_confirm']??''))!==''){$logSecurity('inquiry_honeypot','warning','一般お問い合わせフォームの隠し項目への入力を検知しました。');throw new InvalidArgumentException('送信できませんでした。');}
        if(($_POST['consent']??'')!=='1')throw new InvalidArgumentException('入力内容の送信に同意してください。');
        if((int)($_POST['captcha']??-1)!==((int)$tokenData['a']+(int)$tokenData['b']))throw new InvalidArgumentException('確認の答えが違います。');

        $hourly=$db->prepare('SELECT COUNT(*) FROM contact_messages WHERE site_id=? AND ip_hash=? AND created_at>=NOW()-INTERVAL 1 HOUR');$hourly->execute([(int)$site['id'],$ipHash]);
        if((int)$hourly->fetchColumn()>=3){$logSecurity('inquiry_rate_limit','warning','一般お問い合わせフォームの1時間の受付上限を超えました。');throw new InvalidArgumentException('送信回数が上限に達しました。時間を置いてお試しください。');}
        $email=filter_var($_POST['sender_email']??'',FILTER_VALIDATE_EMAIL);
        if($old['sender_name']===''||!is_string($email)||strlen($email)>255||$old['subject']===''||$old['message']==='')throw new InvalidArgumentException('お名前、メールアドレス、件名、お問い合わせ内容を確認してください。');

        $receipt='INQ'.date('ymd').strtoupper(substr(Security::randomToken(5),0,10));
        $insert=$db->prepare('INSERT INTO contact_messages (site_id,receipt_no,form_nonce_hash,status,sender_name,sender_email,subject,message,ip_hash,user_agent) VALUES (?,?,?,\'unread\',?,?,?,?,?,?)');
        $insert->execute([(int)$site['id'],$receipt,hash('sha256',(string)$tokenData['nonce']),$old['sender_name'],$email,$old['subject'],$old['message'],$ipHash,mb_substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,500)]);
        if($site['admin_email']){$subject='[阿修羅] 一般お問い合わせを受信しました';$body="{$site['name']}宛てにお問い合わせを受信しました。\n受付番号: {$receipt}\n件名: {$old['subject']}\n管理画面で内容を確認してください。\n";$from=str_replace(["\r","\n"],'',(string)($config['mail']['from']??'noreply@localhost'));@mail($site['admin_email'],$subject,$body,'From: '.$from."\r\nContent-Type: text/plain; charset=UTF-8");}
        $complete=true;$old=['sender_name'=>'','sender_email'=>'','subject'=>'','message'=>''];header('X-Robots-Tag: noindex,nofollow,noarchive');
    }catch(Throwable $e){
        if($e instanceof PDOException&&$e->getCode()==='23000')$error='このフォームは送信済みです。';elseif($e instanceof InvalidArgumentException)$error=$e->getMessage();else{error_log('[Asyura inquiry] '.$e->getMessage());$error='送信中にエラーが発生しました。時間を置いてお試しください。';}
        [$formToken,$math]=$makeToken($site,$config);
    }
}
$canonical=app_url('public/inquiry.php?site='.rawurlencode((string)$site['public_id']));
?><!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>お問い合わせ | <?=e($site['name'])?></title><meta name="description" content="<?=e($site['name'])?>へのお問い合わせフォームです。"><link rel="canonical" href="<?=e($canonical)?>"><style>body{margin:0;background:#f6f7f7;color:#1d2327;font:15px/1.7 -apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans JP",sans-serif}.wrap{max-width:720px;margin:20px auto;padding:24px;background:#fff;border:1px solid #dcdcde;border-radius:8px;box-sizing:border-box}h1{font-size:25px;margin-top:0}.intro{color:#50575e}.notice{padding:12px;border-left:4px solid #2271b1;background:#f0f6fc;margin:15px 0}.error{border-color:#d63638;background:#fcf0f1}label{display:block;font-weight:600;margin:14px 0}input,textarea{width:100%;box-sizing:border-box;padding:10px;border:1px solid #8c8f94;border-radius:4px;font:inherit}textarea{min-height:180px;resize:vertical}.checks{display:flex;gap:12px;flex-wrap:wrap}.checks label{font-weight:400}.checks input{width:auto}.hidden-field{position:absolute;left:-9999px}.button{border:0;border-radius:4px;background:#2271b1;color:#fff;padding:11px 20px;font-weight:700;cursor:pointer}@media(max-width:560px){body{background:#fff}.wrap{margin:0;padding:18px;border:0;border-radius:0}h1{font-size:22px}}</style></head><body><main class="wrap"><h1><?=e($site['name'])?>へのお問い合わせ</h1><p class="intro">必要事項をご入力ください。内容を確認後、管理者からご連絡します。</p>
<?php if($complete):?><div class="notice"><h2>送信しました</h2><p>お問い合わせを受け付けました。</p><p>受付番号：<strong><?=e($receipt)?></strong></p><p>管理者へ確認するときに、この番号をお伝えください。</p></div><?php else:?>
<?php if($error):?><div class="notice error"><?=e($error)?></div><?php endif?>
<form method="post" autocomplete="on"><input type="hidden" name="site" value="<?=e($site['public_id'])?>"><input type="hidden" name="form_token" value="<?=e($formToken)?>"><label class="hidden-field">空欄にしてください<input name="website_confirm" tabindex="-1" autocomplete="off"></label><label>お名前<input name="sender_name" maxlength="255" value="<?=e($old['sender_name'])?>" required autocomplete="name"></label><label>メールアドレス<input type="email" name="sender_email" maxlength="255" value="<?=e($old['sender_email'])?>" required autocomplete="email"></label><label>件名<input name="subject" maxlength="255" value="<?=e($old['subject'])?>" required></label><label>お問い合わせ内容<textarea name="message" maxlength="10000" required><?=e($old['message'])?></textarea></label><label>確認：<?=e($math[0])?> ＋ <?=e($math[1])?> ＝ <input type="number" name="captcha" required inputmode="numeric"></label><div class="checks"><label><input type="checkbox" name="consent" value="1" required>入力内容の送信に同意します</label></div><button class="button">お問い合わせを送信する</button></form><?php endif?></main></body></html>
