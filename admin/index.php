<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Asyura\AdminController;
use Asyura\Auth;
use Asyura\View;

Auth::requireLogin($config['app_url']);

$asyuraSites = $db->query('SELECT * FROM sites ORDER BY name,id')->fetchAll();

if (isset($_GET['clear_site'])) {
    unset($_SESSION['admin_site_id']);
}

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
if ($asyuraCurrentSite === null && $currentSiteId > 0) {
    unset($_SESSION['admin_site_id']);
}

(new AdminController($db, $config))->handle();

require __DIR__ . '/pages.php';

$page = (string) ($_GET['page'] ?? 'dashboard');
$allowed = ['dashboard','sites','analytics','ranking','links','requests','rss','rotation','notices','urls','management_links','data','settings'];
if (!in_array($page, $allowed, true)) {
    $page = 'dashboard';
}

// サイトが未確定の間は、ダッシュボードとサイト登録以外へ入らない。
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
    echo '<div class="site-pick-hero"><div><span class="eyebrow">SITE SELECT</span><h1>管理するサイトを選択</h1><p>ヘッダーのサイトメニュー、または下の一覧から管理するサイトを選んでください。</p></div><a class="button primary" href="' . e(app_url('admin/?page=sites&new=1')) . '">サイト登録</a></div>';
    if ($asyuraSites === []) {
        echo '<div class="empty-state"><strong>まだサイトが登録されていません。</strong><p>最初にサイトを登録してください。</p><a class="button primary" href="' . e(app_url('admin/?page=sites&new=1')) . '">サイト登録</a></div>';
    } else {
        echo '<div class="site-pick-grid">';
        foreach ($asyuraSites as $site) {
            echo '<a class="site-pick-card" href="' . e(app_url('admin/?page=dashboard&site=' . (int) $site['id'])) . '">';
            echo '<span class="site-pick-state ' . (!empty($site['active']) ? 'is-active' : '') . '">' . (!empty($site['active']) ? '計測中' : '停止') . '</span>';
            echo '<strong>' . e($site['name']) . '</strong><span>' . e($site['url']) . '</span><b>このサイトを管理する →</b></a>';
        }
        echo '</div>';
    }
    echo '</section>';
} else {
    call_user_func('asyura_page_' . $page, $db, $config);
}

View::footer();
