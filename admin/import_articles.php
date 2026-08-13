<?php
declare(strict_types=1);

require dirname(__DIR__).'/src/bootstrap.php';

use Asyura\Auth;
use Asyura\Security;
use Asyura\UrlNormalizer;
use Asyura\View;

Auth::requireLogin($config['app_url']);
if($_SERVER['REQUEST_METHOD']!=='POST'||!Security::verifyCsrf($_POST['csrf_token']??null)){http_response_code(403);exit('Invalid request');}
$siteId=(int)($_POST['site_id']??0);$site=$db->prepare('SELECT id FROM sites WHERE id=?');$site->execute([$siteId]);
if(!$site->fetchColumn()){View::flash('登録先サイトが正しくありません。','error');redirect(app_url('admin/?page=rotation'));}
$file=$_FILES['csv_file']??null;
if(!$file||$file['error']!==UPLOAD_ERR_OK||$file['size']>5242880){View::flash('CSVファイルを確認してください（最大5MB）。','error');redirect(app_url('admin/?page=rotation'));}
$handle=fopen($file['tmp_name'],'rb');
if(!$handle){View::flash('CSVを読み込めません。','error');redirect(app_url('admin/?page=rotation'));}
$header=fgetcsv($handle);if($header)$header[0]=preg_replace('/^\xEF\xBB\xBF/','',(string)$header[0]);
if(!$header||array_diff(['title','url'],$header)){fclose($handle);View::flash('CSVの1行目にtitleとurlが必要です。','error');redirect(app_url('admin/?page=rotation'));}
$map=array_flip($header);$column=static fn(array $row,string $name):string=>isset($map[$name])?(string)($row[$map[$name]]??''):'';
$stmt=$db->prepare('INSERT INTO article_archive (site_id,feed_id,url_hash,title,url,description,category,image_url,original_published_at) VALUES (?,NULL,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE title=VALUES(title),description=VALUES(description),category=VALUES(category),image_url=COALESCE(VALUES(image_url),image_url),last_seen_at=NOW()');
$count=0;$db->beginTransaction();
try{
    while(($row=fgetcsv($handle))!==false&&$count<20000){
        $title=Security::cleanText($column($row,'title'),1000);$url=Security::safeUrl($column($row,'url'));if($title===''||$url==='')continue;
        $description=Security::cleanText($column($row,'description'),5000)?:null;$category=Security::cleanText($column($row,'category'),255)?:null;$image=Security::safeUrl($column($row,'image_url'))?:null;
        $publishedRaw=trim($column($row,'published_at'));$published=$publishedRaw&&strtotime($publishedRaw)!==false?date('Y-m-d H:i:s',strtotime($publishedRaw)):null;
        $stmt->execute([$siteId,hash('sha256',UrlNormalizer::normalize($url)),$title,$url,$description,$category,$image,$published]);$count++;
    }
    $db->commit();
}catch(Throwable){$db->rollBack();fclose($handle);View::flash('CSV取り込み中にエラーが発生しました。','error');redirect(app_url('admin/?page=rotation'));}
fclose($handle);View::flash($count.'件の過去記事を取り込みました。');redirect(app_url('admin/?page=rotation'));
