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

$allowed = ['dashboard','access','ranking','links','site_info','settings'];
if (!in_array($page, $allowed, true)) $page = 'dashboard';
if ($asyuraCurrentSite === null && $page !== 'dashboard') $page = 'dashboard';

$accessTitles = [
    'traffic'=>'アクセス数（PV・セッション・UU）',
    'channels'=>'流入経路（チャネル）',
    'pages'=>'ページ別アクセス状況',
    'engagement'=>'直帰率・エンゲージメント率',
    'conversions'=>'コンバージョン数（CV）',
    'duration'=>'滞在時間',
    'keywords'=>'流入キーワード',
    'audience'=>'ユーザー属性',
];
$report = (string) ($_GET['report'] ?? 'traffic');
$titles = [
    'dashboard'=>'ダッシュボード',
    'access'=>$accessTitles[$report] ?? $accessTitles['traffic'],
    'ranking'=>'逆アクセスランキング',
    'links'=>'相互リンクサイト登録',
    'site_info'=>'サイト情報',
    'settings'=>'個人設定',
];

View::header($titles[$page], $page, $asyuraSites, $asyuraCurrentSite);

if ($page === 'dashboard' && $asyuraCurrentSite === null) {
    echo '<section class="global-dashboard">';
    echo '<div class="global-dashboard-intro"><h2>各サイトのアクセス</h2><p>今日の状況をサイトごとに確認できます。</p></div>';
    if ($asyuraSites === []) {
        echo '<div class="empty-state"><strong>まだサイトが登録されていません。</strong><p>左メニューの「サイト登録」から登録してください。</p></div>';
    } else {
        $stmt = $db->prepare("SELECT s.id,s.name,s.url,COALESCE(d.pv,0) pv,COALESCE(d.uu,0) uu,(SELECT COUNT(DISTINCT re.session_hash) FROM raw_events re WHERE re.site_id=s.id AND re.event_type='pageview' AND re.is_bot=0 AND DATE(re.occurred_at)=CURDATE() AND re.session_hash IS NOT NULL) sessions,(SELECT COUNT(*) FROM link_requests lr WHERE lr.site_id=s.id AND lr.status='new') pending_requests FROM sites s LEFT JOIN daily_stats d ON d.site_id=s.id AND d.stat_date=CURDATE() ORDER BY s.id");
        $stmt->execute();
        echo '<div class="global-site-grid">';
        foreach ($stmt->fetchAll() as $row) {
            echo '<a class="global-site-card" href="' . e(app_url('admin/?page=dashboard&site=' . (int) $row['id'])) . '">';
            echo '<div class="global-site-head"><strong>' . e($row['name']) . '</strong><small>' . e($row['url']) . '</small></div>';
            echo '<div class="global-site-stats four">';
            echo '<div class="global-site-stat"><span>PV</span><b>' . number_format((int) $row['pv']) . '</b></div>';
            echo '<div class="global-site-stat"><span>セッション</span><b>' . number_format((int) $row['sessions']) . '</b></div>';
            echo '<div class="global-site-stat"><span>UU</span><b>' . number_format((int) $row['uu']) . '</b></div>';
            echo '<div class="global-site-stat"><span>保留中の申請</span><b>' . number_format((int) $row['pending_requests']) . '</b></div>';
            echo '</div><div class="global-site-open">このサイトを管理 →</div></a>';
        }
        echo '</div>';
    }
    echo '</section>';
} elseif ($page === 'dashboard' && $asyuraCurrentSite !== null) {
    echo '<section class="site-dashboard-box"><h2>' . e($asyuraCurrentSite['name']) . '</h2><p>左の管理メニューから、このサイトの機能を選択してください。</p></section>';
} elseif ($page === 'access') {
    require __DIR__ . '/access-report.php';
} elseif ($page === 'site_info') {
    require __DIR__ . '/site-info.php';
} elseif ($page === 'links') {
    $_GET['view'] = 'new';
    asyura_page_links($db, $config);
} else {
    call_user_func('asyura_page_' . $page, $db, $config);
}

View::footer();
