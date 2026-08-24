<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Asyura\AdminController;
use Asyura\Auth;
use Asyura\View;

Auth::requireLogin($config['app_url']);

$asyuraSites = $db->query('SELECT * FROM sites ORDER BY name,id')->fetchAll();
$requestedSiteId = (int) ($_GET['site'] ?? $_POST['context_site_id'] ?? 0);
if ($requestedSiteId > 0) {
    foreach ($asyuraSites as $candidate) {
        if ((int) $candidate['id'] === $requestedSiteId) {
            $_SESSION['admin_site_id'] = $requestedSiteId;
            break;
        }
    }
}
$currentSiteId = (int) ($_SESSION['admin_site_id'] ?? 0);
$asyuraCurrentSite = null;
foreach ($asyuraSites as $candidate) {
    if ((int) $candidate['id'] === $currentSiteId) {
        $asyuraCurrentSite = $candidate;
        break;
    }
}
if ($asyuraCurrentSite === null && $asyuraSites !== []) {
    $asyuraCurrentSite = $asyuraSites[0];
    $_SESSION['admin_site_id'] = (int) $asyuraCurrentSite['id'];
}

(new AdminController($db, $config))->handle();

require __DIR__ . '/pages.php';

$page = (string) ($_GET['page'] ?? 'dashboard');
$allowed = ['dashboard','sites','analytics','ranking','links','requests','rss','rotation','notices','urls','management_links','data','settings'];
if (!in_array($page, $allowed, true)) {
    $page = 'dashboard';
}
$titles = ['dashboard'=>'ダッシュボード','sites'=>'サイト管理','analytics'=>'アクセス解析','ranking'=>'逆アクセスランキング','links'=>'相互リンク','requests'=>'相互リンク依頼','rss'=>'相互RSS','rotation'=>'過去記事再配信','notices'=>'お知らせ','urls'=>'URL統一・除外','management_links'=>'管理リンク','data'=>'データ管理','settings'=>'設定'];
View::header($titles[$page], $page, $asyuraSites, $asyuraCurrentSite);
call_user_func('asyura_page_' . $page, $db, $config);
View::footer();
