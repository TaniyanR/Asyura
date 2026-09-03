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

$allowed = ['dashboard','access','links','ranking','site_info','settings'];
if (!in_array($page, $allowed, true)) $page = 'dashboard';
if ($asyuraCurrentSite === null && $page !== 'dashboard') $page = 'dashboard';

$report = (string) ($_GET['report'] ?? 'traffic');
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
$titles = [
    'dashboard'=>'ダッシュボード',
    'access'=>$accessTitles[$report] ?? $accessTitles['traffic'],
    'links'=>'相互リンクサイト登録',
    'ranking'=>'逆アクセスランキング',
    'site_info'=>'サイト情報',
    'settings'=>'個人設定',
];

View::header($titles[$page], $page, $asyuraSites, $asyuraCurrentSite);

if ($page === 'dashboard' && $asyuraCurrentSite === null) {
    echo '<section class="global-dashboard">';
    echo '<div class="dashboard-intro"><h2>登録サイト</h2><p>各サイトの今日のアクセスと申請状況を確認できます。</p></div>';

    if ($asyuraSites === []) {
        echo '<div class="empty-state"><strong>まだサイトが登録されていません。</strong><p>左の「サイト登録」から登録してください。</p></div>';
    } else {
        $stmt = $db->prepare("SELECT s.id,s.name,s.url,COALESCE(d.pv,0) pv,COALESCE(d.uu,0) uu,(SELECT COUNT(DISTINCT re.session_hash) FROM raw_events re WHERE re.site_id=s.id AND re.event_type='pageview' AND re.is_bot=0 AND DATE(re.occurred_at)=CURDATE() AND re.session_hash IS NOT NULL) sessions,(SELECT COUNT(*) FROM link_requests lr WHERE lr.site_id=s.id AND lr.status='new') pending_requests FROM sites s LEFT JOIN daily_stats d ON d.site_id=s.id AND d.stat_date=CURDATE() ORDER BY s.id");
        $stmt->execute();

        echo '<div class="site-card-list">';
        foreach ($stmt->fetchAll() as $row) {
            echo '<a class="site-summary-card" href="' . e(app_url('admin/?page=dashboard&site=' . (int) $row['id'])) . '">';
            echo '<div class="site-card-head"><strong>' . e((string) $row['name']) . '</strong><small>' . e((string) $row['url']) . '</small></div>';
            echo '<div class="site-card-stats">';
            echo '<div class="site-card-stat"><span>PV</span><b>' . number_format((int) $row['pv']) . '</b></div>';
            echo '<div class="site-card-stat"><span>セッション</span><b>' . number_format((int) $row['sessions']) . '</b></div>';
            echo '<div class="site-card-stat"><span>UU</span><b>' . number_format((int) $row['uu']) . '</b></div>';
            echo '<div class="site-card-stat"><span>保留中の申請</span><b>' . number_format((int) $row['pending_requests']) . '</b></div>';
            echo '</div><div class="site-card-foot">このサイトを開く →</div></a>';
        }
        echo '</div>';
    }
    echo '</section>';
} elseif ($page === 'dashboard' && $asyuraCurrentSite !== null) {
    echo '<section class="site-home"><h2>' . e((string) $asyuraCurrentSite['name']) . '</h2><p>左のメニューから、このサイトの管理項目を選択してください。</p></section>';
} elseif ($page === 'access') {
    require __DIR__ . '/access-shell.php';
} elseif ($page === 'site_info') {
    require __DIR__ . '/site-info-shell.php';
} elseif ($page === 'links') {
    $_GET['view'] = 'new';
    asyura_page_links($db, $config);
} else {
    call_user_func('asyura_page_' . $page, $db, $config);
}

View::footer();
