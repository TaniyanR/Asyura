<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Asyura\Auth;
use Asyura\Security;
use Asyura\SettingsTransferService;

Auth::requireLogin($config['app_url']);
if($_SERVER['REQUEST_METHOD']!=='POST'||!Security::verifyCsrf($_POST['csrf_token']??null)){
    http_response_code(403);
    exit('Invalid request');
}
$siteId=(int)($_POST['site_id']??0);
try{
    $service=new SettingsTransferService($db);
    $payload=$service->exportReciprocal($siteId);
    $json=json_encode($payload,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $siteName=preg_replace('/[^A-Za-z0-9_-]+/u','-',(string)($payload['source_site']['name']??'site'));
    $siteName=trim((string)$siteName,'-')?:'site';
    $fileName='asyura-reciprocal-'.$siteId.'-'.$siteName.'-'.date('Ymd-His').'.json';
    Security::headers();
    header('Content-Type: application/json; charset=UTF-8');
    header('Content-Disposition: attachment; filename="'.$fileName.'"');
    header('Content-Length: '.strlen($json));
    echo $json;
}catch(Throwable $e){
    http_response_code(400);
    exit('相互設定をエクスポートできませんでした。');
}
