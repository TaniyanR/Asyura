<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Asyura\AdminController;
use Asyura\Auth;
use Asyura\View;

Auth::requireLogin($config['app_url']);

$asyuraSites = $db->query('SELECT * FROM sites ORDER BY name,id')->fetchAll();

// トップページとサイト専用ページを明確に分離する。
// サイトはURLの site またはPOSTの context_site_id で明示された場合だけ確定する。
$requestedSiteId = (int) ($_GET['site'] ?? $_POST['context_site_id'] ?? 0);
$asyuraCurrentSite = null;
if ($requestedSiteId > 0) {
    foreach ($asyuraSites as $candidate) {
        if ((int) $candidate['id'] === $requestedSiteId) {
            $asyuraCurrentSite = $candidate;
            break;
        }
    }
}

(new AdminController($db, $config))->handle();

require __DIR__ . '/pages.php';

$page = (string) ($_GET['page'] ?? 'dashboard');
$allowed = ['dashboard','sites','analytics','ranking','links','requests','rss','rotation','notices','urls','management_links','data','settings'];
if (!in_array($page, $allowed, true)) {
    $page = 'dashboard';
}

// サイト未確定のトップ側では、ダッシュボードとサイト登録だけを許可する。
if ($asyuraCurrentSite === null && !in_array($page, ['dashboard','sites'], true)) {
    $page = 'dashboard';
}

$titles = [
    'dashboard'=>'ダッシュボード',
    'sites'=>'サイト登録',
    'analytics'=>'アクセス解析',
    'ranking'=>'逆アクセスランキング',
    'links'=>'相互リンク',
    'requests'=>'申請一覧',
    'rss'=>'相互RSS',
    'rotation'=>'過去記事再配信',
    'notices'=>'お知らせ',
    'urls'=>'URL統一・除外',
    'management_links'=>'管理リンク',
    'data'=>'データ管理',
    'settings'=>'設定',
];

View::header($titles[$page], $page, $asyuraSites, $asyuraCurrentSite);

if ($page === 'dashboard' && $asyuraCurrentSite === null) {
    echo '<section class="site-pick-page">';
    echo '<div class="site-pick-hero"><div><span class="eyebrow">ASYURA</span><h1>ダッシュボード</h1><p>ヘッダーの「サイト登録」から、登録または管理するサイトを選択してください。</p></div><a class="button primary" href="' . e(app_url('admin/?page=sites&new=1')) . '">サイト登録</a></div>';
    if ($asyuraSites === []) {
        echo '<div class="empty-state"><strong>まだサイトが登録されていません。</strong><p>最初にサイトを登録してください。</p><a class="button primary" href="' . e(app_url('admin/?page=sites&new=1')) . '">サイト登録</a></div>';
    } else {
        echo '<div class="panel"><h2>登録済みサイト</h2><div class="panel-body"><p class="description">管理するサイトはヘッダーのサイト名メニューから選択できます。</p></div></div>';
    }
    echo '</section>';
} else {
    call_user_func('asyura_page_' . $page, $db, $config);
}

View::footer();
