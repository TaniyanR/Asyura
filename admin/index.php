<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Asyura\AdminController;
use Asyura\Auth;
use Asyura\View;

Auth::requireLogin($config['app_url']);

$page = (string) ($_GET['page'] ?? 'dashboard');
if ($page === 'sites') {
    require __DIR__ . '/site-simple.php';
    exit;
}

$asyuraSites = $db->query('SELECT * FROM sites ORDER BY id')->fetchAll();
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

$allowed = ['dashboard','analytics','ranking','urls','tracking','links','requests','rss','rotation','notices','management_links','data','settings'];
if (!in_array($page, $allowed, true)) $page = 'dashboard';
if ($asyuraCurrentSite === null && $page !== 'dashboard') $page = 'dashboard';

$titles = [
    'dashboard'=>'ダッシュボード','analytics'=>'アクセス解析','ranking'=>'逆アクセスランキング','urls'=>'URL統一・除外','tracking'=>'計測タグ',
    'links'=>'相互リンク','requests'=>'申請一覧','rss'=>'相互RSS','rotation'=>'過去記事再配信','notices'=>'お知らせ',
    'management_links'=>'管理リンク','data'=>'データ管理','settings'=>'設定',
];

View::header($titles[$page], $page, $asyuraSites, $asyuraCurrentSite);

if ($page === 'dashboard' && $asyuraCurrentSite === null) {
    echo '<section class="global-dashboard">';
    echo '<div class="global-dashboard-intro"><h2>各サイトのアクセス</h2><p>今日のPV・UUをサイトごとに確認できます。</p></div>';
    if ($asyuraSites === []) {
        echo '<div class="empty-state"><strong>まだサイトが登録されていません。</strong><p>左メニューの「サイト登録」から登録してください。</p></div>';
    } else {
        $today = date('Y-m-d');
        $stmt = $db->prepare('SELECT s.id,s.name,s.url,COALESCE(d.pv,0) pv,COALESCE(d.uu,0) uu FROM sites s LEFT JOIN daily_stats d ON d.site_id=s.id AND d.stat_date=? ORDER BY s.id');
        $stmt->execute([$today]);
        echo '<div class="global-site-grid">';
        foreach ($stmt->fetchAll() as $row) {
            echo '<a class="global-site-card" href="' . e(app_url('admin/?page=dashboard&site=' . (int) $row['id'])) . '">';
            echo '<div class="global-site-head"><strong>' . e($row['name']) . '</strong><small>' . e($row['url']) . '</small></div>';
            echo '<div class="global-site-stats"><div class="global-site-stat"><span>今日のPV</span><b>' . number_format((int) $row['pv']) . '</b></div><div class="global-site-stat"><span>今日のUU</span><b>' . number_format((int) $row['uu']) . '</b></div></div>';
            echo '<div class="global-site-open">このサイトを管理 →</div></a>';
        }
        echo '</div>';
    }
    echo '</section>';
} elseif ($page === 'dashboard' && $asyuraCurrentSite !== null) {
    echo '<section class="site-dashboard-box"><h2>' . e($asyuraCurrentSite['name']) . '</h2><p>左の管理メニューから、このサイトの機能を選択してください。</p><dl class="site-dashboard-info">';
    echo '<dt>サイトURL</dt><dd><a href="' . e($asyuraCurrentSite['url']) . '" target="_blank" rel="noopener noreferrer">' . e($asyuraCurrentSite['url']) . '</a></dd>';
    if (!empty($asyuraCurrentSite['rss_url'])) echo '<dt>サイトRSS</dt><dd>' . e($asyuraCurrentSite['rss_url']) . '</dd>';
    if (!empty($asyuraCurrentSite['login_url'])) echo '<dt>ログインURL</dt><dd><a href="' . e($asyuraCurrentSite['login_url']) . '" target="_blank" rel="noopener noreferrer">' . e($asyuraCurrentSite['login_url']) . '</a></dd>';
    if (!empty($asyuraCurrentSite['admin_email'])) echo '<dt>管理メール</dt><dd>' . e($asyuraCurrentSite['admin_email']) . '</dd>';
    if (!empty($asyuraCurrentSite['description'])) echo '<dt>説明</dt><dd>' . nl2br(e($asyuraCurrentSite['description'])) . '</dd>';
    echo '</dl></section>';
} elseif ($page === 'tracking') {
    require __DIR__ . '/tracking-tag.php';
} else {
    call_user_func('asyura_page_' . $page, $db, $config);
}

View::footer();
