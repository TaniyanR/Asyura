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
if (!in_array($page, $allowed, true)) {
    $page = 'dashboard';
}
if ($asyuraCurrentSite === null && $page !== 'dashboard') {
    $page = 'dashboard';
}

$titles = [
    'dashboard'=>'ダッシュボード',
    'analytics'=>'アクセス解析',
    'ranking'=>'逆アクセスランキング',
    'urls'=>'URL統一・除外',
    'tracking'=>'計測タグ',
    'links'=>'相互リンク',
    'requests'=>'申請一覧',
    'rss'=>'相互RSS',
    'rotation'=>'過去記事再配信',
    'notices'=>'お知らせ',
    'management_links'=>'管理リンク',
    'data'=>'データ管理',
    'settings'=>'設定',
];

View::header($titles[$page], $page, $asyuraSites, $asyuraCurrentSite);

if ($page === 'dashboard' && $asyuraCurrentSite === null) {
    echo '<section class="global-dashboard">';
    if ($asyuraSites === []) {
        echo '<div class="empty-state"><strong>まだサイトが登録されていません。</strong><p>サイドメニューまたはヘッダーからサイトを登録してください。</p></div>';
    } else {
        $today = date('Y-m-d');
        $stmt = $db->prepare('SELECT s.id,s.name,s.url,COALESCE(d.pv,0) pv,COALESCE(d.uu,0) uu FROM sites s LEFT JOIN daily_stats d ON d.site_id=s.id AND d.stat_date=? ORDER BY s.id');
        $stmt->execute([$today]);
        $summaries = $stmt->fetchAll();

        echo '<div class="dashboard-summary-head"><div><h2>各サイトの今日のアクセス</h2><p>' . e($today) . ' の簡易表示です。</p></div></div>';
        echo '<div class="site-access-grid">';
        foreach ($summaries as $row) {
            echo '<a class="site-access-card" href="' . e(app_url('admin/?page=dashboard&site=' . (int) $row['id'])) . '">';
            echo '<div class="site-access-name"><strong>' . e($row['name']) . '</strong><small>' . e($row['url']) . '</small></div>';
            echo '<div class="site-access-numbers"><span><small>今日のPV</small><b>' . number_format((int) $row['pv']) . '</b></span><span><small>今日のUU</small><b>' . number_format((int) $row['uu']) . '</b></span></div>';
            echo '<div class="site-access-open">このサイトを管理 →</div>';
            echo '</a>';
        }
        echo '</div>';
    }
    echo '</section>';
} elseif ($page === 'dashboard' && $asyuraCurrentSite !== null) {
    $siteId = (int) $asyuraCurrentSite['id'];

    echo '<div class="panel"><h2>直近14日間のアクセス</h2><div class="panel-body">';
    $rowStmt = $db->prepare("SELECT stat_date,pv FROM daily_stats WHERE site_id=? AND stat_date>=CURDATE()-INTERVAL 13 DAY ORDER BY stat_date");
    $rowStmt->execute([$siteId]);
    $rows = $rowStmt->fetchAll();
    $max = max(1, ...array_map(static fn($r)=>(int)$r['pv'], $rows ?: [['pv'=>1]]));
    if (!$rows) {
        echo '<div class="empty">まだアクセスデータがありません。</div>';
    } else {
        echo '<div class="chart-bars">';
        foreach ($rows as $r) {
            echo '<span title="' . e($r['stat_date'] . '：' . $r['pv'] . ' PV') . '" style="height:' . max(3, round(((int) $r['pv'] / $max) * 100)) . '%"></span>';
        }
        echo '</div>';
    }
    echo '</div></div>';

    $errorStmt = $db->prepare("SELECT COUNT(*) FROM rss_feeds WHERE site_id=? AND active=1 AND last_error IS NOT NULL");
    $errorStmt->execute([$siteId]);
    $feedErrors = (int) $errorStmt->fetchColumn();
    echo '<div class="cards compact"><div class="card ' . ($feedErrors ? 'orange' : 'green') . '"><div class="number">' . $feedErrors . '</div><div class="label">このサイトのRSS取得エラー</div></div></div>';

    echo '<div class="panel"><h2>設定状況</h2><div class="panel-body"><p>cron URL：<code>' . e(app_url('cron/run.php?key=' . $config['cron_key'])) . '</code></p><p class="description">30分ごとにcronを実行してください。CLIからは <code>php ' . e(ASYURA_ROOT . '/cron/run.php') . '</code> でも実行できます。</p></div></div>';
} elseif ($page === 'tracking') {
    require __DIR__ . '/tracking-tag.php';
} else {
    call_user_func('asyura_page_' . $page, $db, $config);
}

View::footer();
