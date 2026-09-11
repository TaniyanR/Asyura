<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Asyura\AdminController;
use Asyura\Auth;
use Asyura\Security;
use Asyura\SimpleSiteService;
use Asyura\View;

Auth::requireLogin($config['app_url']);

$page = (string) ($_GET['page'] ?? 'dashboard');

if ($page === 'sites') {
    require __DIR__ . '/site-simple.php';
    exit;
}

/*
|--------------------------------------------------------------------------
| 登録サイト取得
|--------------------------------------------------------------------------
*/
$asyuraAllSites = $db->query(
    'SELECT * FROM sites ORDER BY sort_order, id'
)->fetchAll();
$asyuraSitePartition = SimpleSiteService::partitionByUrl($asyuraAllSites);
$asyuraSites = $asyuraSitePartition['visible'];

/*
|--------------------------------------------------------------------------
| 現在のサイト
|--------------------------------------------------------------------------
*/
$requestedSiteId = (int) (
    $_GET['site']
    ?? $_POST['context_site_id']
    ?? 0
);

$asyuraCurrentSite = null;

if ($requestedSiteId > 0) {
    foreach ($asyuraAllSites as $candidate) {
        if ((int) $candidate['id'] === $requestedSiteId) {
            $asyuraCurrentSite = $candidate;
            break;
        }
    }
}

if ($asyuraCurrentSite !== null) {
    $_SESSION['admin_site_id'] = (int)$asyuraCurrentSite['id'];
} else {
    unset($_SESSION['admin_site_id']);
}

/*
|--------------------------------------------------------------------------
| 管理画面POST処理
|--------------------------------------------------------------------------
*/
(new AdminController($db, $config))->handle();

require __DIR__ . '/pages.php';

/*
|--------------------------------------------------------------------------
| 使用可能ページ
|--------------------------------------------------------------------------
*/
$allowed = [
    'dashboard',
    'access',
    'tracking_tag',
    'links',
    'ranking',
    'site_info',
    'settings',
    'search_console',
    'notices',
    'contact',
    'inquiries',
    'requests',
    'rss',
    'data',
];

if (!in_array($page, $allowed, true)) {
    $page = 'dashboard';
}

/*
|--------------------------------------------------------------------------
| サイト未選択時
|--------------------------------------------------------------------------
*/
if ($asyuraCurrentSite === null && !in_array($page, ['dashboard','search_console','data'], true)) {
    $page = 'dashboard';
}

/*
|--------------------------------------------------------------------------
| アクセス解析ページ
|--------------------------------------------------------------------------
*/
$report = (string) ($_GET['report'] ?? 'traffic');

$accessTitles = [
    'traffic'     => 'アクセス数（PV・セッション・UU）',
    'channels'    => '流入経路（チャネル）',
    'pages'       => 'ページ別アクセス状況',
    'engagement'  => '直帰率・エンゲージメント率',
    'conversions' => 'コンバージョン数（CV）',
    'duration'    => '滞在時間',
    'keywords'    => '流入キーワード',
    'audience'    => 'ユーザー属性',
    'security'    => '不正・疑わしいアクセス',
];

/*
|--------------------------------------------------------------------------
| ページタイトル
|--------------------------------------------------------------------------
*/
$titles = [
    'dashboard' => 'ダッシュボード',
    'access'    => $accessTitles[$report] ?? $accessTitles['traffic'],
    'tracking_tag' => '計測タグ',
    'links'     => '相互リンクサイト登録',
    'ranking'   => '逆アクセスランキング',
    'site_info' => 'サイト情報',
    'settings'  => '個人設定',
    'search_console' => 'Google Search Console',
    'notices' => 'お知らせ',
    'contact' => 'お問い合わせ',
    'inquiries' => 'お問い合わせ受信一覧',
    'requests' => '相互リンク申請一覧',
    'rss' => '相互RSS設定',
    'data' => 'データ管理',
];

View::header(
    $titles[$page],
    $page,
    $asyuraSites,
    $asyuraCurrentSite
);

/*
|--------------------------------------------------------------------------
| 全体ダッシュボード
|--------------------------------------------------------------------------
*/
if ($page === 'dashboard' && $asyuraCurrentSite === null) {

    echo '<section class="global-dashboard">';

    echo '<div class="dashboard-intro">';
    echo '<h2>登録サイト</h2>';
    echo '<p>アクセス数は、今日0:00から現在まで（JST）の集計です。Bot・AI・疑わしいアクセスは含みません。</p>';
    echo '</div>';

    if ($asyuraSites === []) {

        echo '<div class="empty-state">';
        echo '<strong>まだサイトが登録されていません。</strong>';
        echo '<p>左の「サイト登録」から登録してください。</p>';
        echo '</div>';

    } else {

        /*
        |--------------------------------------------------------------------------
        | 1サイトにつきカード1枚
        |--------------------------------------------------------------------------
        */
        $stmt = $db->prepare(
            "
            SELECT
                s.id,
                s.name,
                s.url,
                s.login_url,
                s.sort_order,
                (
                    SELECT COUNT(*)
                    FROM raw_events re
                    WHERE re.site_id = s.id
                      AND re.event_type = 'pageview'
                      AND re.is_bot = 0
                      AND re.is_suspicious = 0
                      AND re.occurred_at >= CURDATE()
                      AND re.occurred_at < CURDATE() + INTERVAL 1 DAY
                ) AS pv,
                (
                    SELECT COUNT(DISTINCT re.visitor_hash)
                    FROM raw_events re
                    WHERE re.site_id = s.id
                      AND re.event_type = 'pageview'
                      AND re.is_bot = 0
                      AND re.is_suspicious = 0
                      AND re.occurred_at >= CURDATE()
                      AND re.occurred_at < CURDATE() + INTERVAL 1 DAY
                ) AS uu,

                (
                    SELECT COUNT(*)
                    FROM analytics_sessions re
                    WHERE
                        re.site_id = s.id
                        AND re.is_bot = 0
                        AND re.is_suspicious = 0
                        AND re.started_at >= CURDATE()
                        AND re.started_at < CURDATE() + INTERVAL 1 DAY
                ) AS sessions,

                (
                    SELECT COUNT(*)
                    FROM link_requests lr
                    WHERE
                        lr.site_id = s.id
                        AND lr.status = 'new'
                ) AS pending_requests

            FROM sites s

            WHERE s.id IN (" . implode(',', array_fill(0, count($asyuraSites), '?')) . ")

            ORDER BY s.sort_order, s.id
            "
        );

        $stmt->execute(array_map(static fn(array $site): int => (int) $site['id'], $asyuraSites));

        echo '<div class="site-card-list">';

        $dashboardRows=$stmt->fetchAll();
        foreach ($dashboardRows as $position=>$row) {

            $siteId = (int) $row['id'];

            $siteUrl = app_url(
                'admin/?page=dashboard&site=' . $siteId
            );

            $loginUrl=Security::safeUrl((string)($row['login_url']??''));
            echo '<article class="site-summary-card">';
            echo '<a class="site-card-dashboard-link" href="' . e($siteUrl) . '">';

            /*
            | サイト名
            */
            echo '<div class="site-card-head">';
            echo '<strong>' . e((string) $row['name']) . '</strong>';
            echo '<small>' . e((string) $row['url']) . '</small>';
            echo '</div>';

            /*
            | PV / セッション / UU / 保留中の申請
            */
            echo '<div class="site-card-stats">';

            echo '<div class="site-card-stat">';
            echo '<span>PV（今日）</span>';
            echo '<b>' . number_format((int) $row['pv']) . '</b>';
            echo '</div>';

            echo '<div class="site-card-stat">';
            echo '<span>セッション（今日）</span>';
            echo '<b>' . number_format((int) $row['sessions']) . '</b>';
            echo '</div>';

            echo '<div class="site-card-stat">';
            echo '<span>UU（今日）</span>';
            echo '<b>' . number_format((int) $row['uu']) . '</b>';
            echo '</div>';

            echo '<div class="site-card-stat">';
            echo '<span>保留中の申請</span>';
            echo '<b>' . number_format((int) $row['pending_requests']) . '</b>';
            echo '</div>';

            echo '</div>';

            echo '</a>';
            echo '<div class="site-card-foot">';
            echo '<a class="button primary" href="'.e($siteUrl).'">阿修羅で管理</a>';
            if($loginUrl!=='')echo '<a class="button" href="'.e($loginUrl).'" target="_blank" rel="noopener noreferrer">サイトへログイン ↗</a>';
            else echo '<a class="button" href="'.e(app_url('admin/?page=sites&edit='.$siteId)).'">ログインURLを登録</a>';
            echo '<div class="site-order-actions" aria-label="'.e((string)$row['name']).'の表示順">';
            if($position>0)echo '<form method="post">'.csrf_field().'<input type="hidden" name="action" value="move_dashboard_site"><input type="hidden" name="site_id" value="'.$siteId.'"><input type="hidden" name="direction" value="up"><button class="button" type="submit">↑ 上へ</button></form>';
            if($position<count($dashboardRows)-1)echo '<form method="post">'.csrf_field().'<input type="hidden" name="action" value="move_dashboard_site"><input type="hidden" name="site_id" value="'.$siteId.'"><input type="hidden" name="direction" value="down"><button class="button" type="submit">↓ 下へ</button></form>';
            echo '</div></div>';
            echo '</article>';
        }

        echo '</div>';
    }

    echo '</section>';

/*
|--------------------------------------------------------------------------
| サイト個別ダッシュボード
|--------------------------------------------------------------------------
*/
} elseif ($page === 'dashboard' && $asyuraCurrentSite !== null) {

    echo '<section class="site-home">';

    echo '<h2>';
    echo e((string) $asyuraCurrentSite['name']);
    echo '</h2>';

    echo '<p>';
    echo '左のメニューから、このサイトの管理項目を選択してください。';
    echo '</p>';

    echo '</section>';

/*
|--------------------------------------------------------------------------
| アクセス
|--------------------------------------------------------------------------
*/
} elseif ($page === 'access') {
    try {
        require __DIR__ . '/access-report.php';
    } catch (Throwable $e) {
        error_log('[Asyura analytics] ' . $e->getMessage());
        echo '<div class="notice error"><strong>アクセス解析を表示できませんでした。</strong><br>時間をおいて再読み込みしてください。解消しない場合はサーバーのエラーログを確認してください。</div>';
    }

/*
|--------------------------------------------------------------------------
| 計測タグ
|--------------------------------------------------------------------------
*/
} elseif ($page === 'tracking_tag') {

    require __DIR__ . '/tracking-tag.php';

/*
|--------------------------------------------------------------------------
| サイト情報
|--------------------------------------------------------------------------
*/
} elseif ($page === 'site_info') {

    require __DIR__ . '/site-info.php';

/*
|--------------------------------------------------------------------------
| 個人設定
|--------------------------------------------------------------------------
*/
} elseif ($page === 'settings') {
    asyura_page_settings($db, $config);

} elseif ($page === 'search_console') {
    require __DIR__ . '/personal-settings.php';

} elseif ($page === 'data') {
    asyura_page_data($db, $config);

} elseif ($page === 'contact') {
    require __DIR__ . '/contact-settings.php';

} elseif ($page === 'inquiries') {
    require __DIR__ . '/inquiries.php';

} elseif ($page === 'notices') {
    asyura_page_notices($db, $config);

} elseif ($page === 'requests') {
    asyura_page_requests($db, $config);

/*
|--------------------------------------------------------------------------
| 相互リンクサイト登録
|--------------------------------------------------------------------------
*/
} elseif ($page === 'links') {

    $_GET['view'] = 'new';

    asyura_page_links(
        $db,
        $config
    );

/*
|--------------------------------------------------------------------------
| その他
|--------------------------------------------------------------------------
*/
} else {

    call_user_func(
        'asyura_page_' . $page,
        $db,
        $config
    );
}

View::footer();
