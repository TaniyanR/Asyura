<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Asyura\Auth;
use Asyura\Security;
use Asyura\SettingsTransferService;
use Asyura\View;

Auth::requireLogin($config['app_url']);
if($_SERVER['REQUEST_METHOD']!=='POST'||!Security::verifyCsrf($_POST['csrf_token']??null)){
    http_response_code(403);
    exit('Invalid request');
}
$targetSiteId=(int)($_POST['target_site_id']??0);
$file=$_FILES['reciprocal_file']??null;
if(!is_array($file)||(int)($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||(int)($file['size']??0)<1||(int)$file['size']>2097152){
    View::flash('相互設定ファイルを確認してください（JSON・最大2MB）。','error');
    redirect(app_url('admin/?page=data'));
}
try{
    $contents=file_get_contents((string)$file['tmp_name']);
    if($contents===false)throw new RuntimeException('ファイルを読み込めません。');
    $backup=json_decode($contents,true,128,JSON_THROW_ON_ERROR);
    if(!is_array($backup))throw new RuntimeException('JSONが正しくありません。');
    $counts=(new SettingsTransferService($db))->importReciprocal($targetSiteId,$backup);
    View::flash('相互設定を取り込みました（相互先'.$counts['links'].'件、RSS'.$counts['feeds'].'件）。同じURLの相互先は重複せず更新しています。');
}catch(Throwable $e){
    error_log('[Asyura reciprocal import] '.$e->getMessage());
    View::flash('相互設定を取り込めませんでした。阿修羅の相互設定エクスポートか確認してください。既存データは変更していません。','error');
}
redirect(app_url('admin/?page=data'));
