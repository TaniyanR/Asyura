<?php
declare(strict_types=1);

use Asyura\SearchConsoleService;

if (!isset($asyuraCurrentSite) || !is_array($asyuraCurrentSite)) {
    echo '<div class="empty-state"><strong>サイトが選択されていません。</strong></div>';
    return;
}

$siteId = (int) $asyuraCurrentSite['id'];
$report = (string) ($_GET['report'] ?? 'traffic');
$allowedReports = ['traffic','channels','pages','engagement','conversions','duration','keywords','audience'];
if (!in_array($report, $allowedReports, true)) $report = 'traffic';

if ($report === 'traffic') {
    $stmt = $db->prepare("SELECT COALESCE(SUM(pv),0) pv, COALESCE(SUM(uu),0) uu FROM daily_stats WHERE site_id=? AND stat_date=CURDATE()");
    $stmt->execute([$siteId]);
    $today = $stmt->fetch() ?: ['pv'=>0,'uu'=>0];
    $sessionStmt = $db->prepare("SELECT COUNT(DISTINCT session_hash) FROM raw_events WHERE site_id=? AND event_type='pageview' AND is_bot=0 AND DATE(occurred_at)=CURDATE() AND session_hash IS NOT NULL");
    $sessionStmt->execute([$siteId]);
    $sessions = (int) $sessionStmt->fetchColumn();
    echo '<div class="cards"><div class="card"><div><div class="number">'.number_format((int)$today['pv']).'</div><div class="label">今日のPV</div></div></div><div class="card green"><div><div class="number">'.number_format($sessions).'</div><div class="label">今日のセッション</div></div></div><div class="card purple"><div><div class="number">'.number_format((int)$today['uu']).'</div><div class="label">今日のUU</div></div></div></div>';
    return;
}

if ($report === 'channels') {
    $stmt = $db->prepare("SELECT CASE WHEN referrer_host IS NULL OR referrer_host='' THEN '直接・不明' WHEN referrer_host LIKE '%google.%' OR referrer_host LIKE '%bing.%' OR referrer_host LIKE '%yahoo.%' THEN '検索' WHEN referrer_host LIKE '%twitter.com%' OR referrer_host LIKE '%x.com%' OR referrer_host LIKE '%facebook.com%' OR referrer_host LIKE '%instagram.com%' THEN 'SNS' ELSE '参照サイト' END channel, COUNT(*) visits FROM raw_events WHERE site_id=? AND event_type='pageview' AND is_bot=0 AND occurred_at>=NOW()-INTERVAL 30 DAY GROUP BY channel ORDER BY visits DESC");
    $stmt->execute([$siteId]);
    echo '<div class="panel"><h2>過去30日の流入経路</h2><div class="panel-body"><table class="wp-list"><thead><tr><th>チャネル</th><th>アクセス</th></tr></thead><tbody>';
    foreach ($stmt->fetchAll() as $row) echo '<tr><td>'.e($row['channel']).'</td><td>'.number_format((int)$row['visits']).'</td></tr>';
    echo '</tbody></table></div></div>';
    return;
}

if ($report === 'pages') {
    $stmt = $db->prepare("SELECT normalized_page_url, COUNT(*) pv, COUNT(DISTINCT visitor_hash) uu FROM raw_events WHERE site_id=? AND event_type='pageview' AND is_bot=0 AND occurred_at>=NOW()-INTERVAL 30 DAY GROUP BY normalized_page_url ORDER BY pv DESC LIMIT 100");
    $stmt->execute([$siteId]);
    echo '<div class="panel"><h2>過去30日のページ別アクセス</h2><div class="panel-body"><table class="wp-list"><thead><tr><th>ページ</th><th>PV</th><th>UU</th></tr></thead><tbody>';
    foreach ($stmt->fetchAll() as $row) echo '<tr><td>'.e($row['normalized_page_url']).'</td><td>'.number_format((int)$row['pv']).'</td><td>'.number_format((int)$row['uu']).'</td></tr>';
    echo '</tbody></table></div></div>';
    return;
}

if ($report === 'keywords') {
    $searchConsole = new SearchConsoleService($db, $config);
    $searchConsole->ensureSchema();
    $property = trim((string)($asyuraCurrentSite['search_console_property'] ?? ''));
    $status = $searchConsole->status();
    if (!$status['connected']) {
        echo '<div class="panel"><div class="panel-body"><p>Google Search Consoleが未接続です。</p><a class="button primary" href="'.e(app_url('admin/?page=settings&site='.$siteId)).'">個人設定で接続する</a></div></div>';
        return;
    }
    if ($property === '') {
        echo '<div class="panel"><div class="panel-body"><p>このサイトにSearch Consoleプロパティが設定されていません。</p><a class="button primary" href="'.e(app_url('admin/?page=site_info&site='.$siteId)).'">サイト情報で設定する</a></div></div>';
        return;
    }
    $endDate = date('Y-m-d', strtotime('-1 day'));
    $startDate = date('Y-m-d', strtotime($endDate . ' -27 days'));
    try {
        $rows = $searchConsole->queryKeywords($property,$startDate,$endDate);
        echo '<div class="panel"><h2>Google検索キーワード</h2><div class="panel-body">';
        echo '<p class="description">'.e($startDate).' ～ '.e($endDate).' / '.e($property).'。Search Console APIの確定データを表示します。</p>';
        echo '<div class="table-wrap"><table class="wp-list"><thead><tr><th>検索キーワード</th><th>流入先ページ</th><th>クリック</th><th>表示回数</th><th>CTR</th><th>平均順位</th></tr></thead><tbody>';
        if ($rows === []) {
            echo '<tr><td colspan="6" class="empty">この期間のSearch Consoleデータはありません。</td></tr>';
        } else {
            foreach ($rows as $row) {
                $keys = is_array($row['keys'] ?? null) ? $row['keys'] : [];
                $query = (string)($keys[0] ?? '');
                $page = (string)($keys[1] ?? '');
                echo '<tr><td>'.e($query).'</td><td class="sc-page">'.e($page).'</td><td>'.number_format((float)($row['clicks'] ?? 0),0).'</td><td>'.number_format((float)($row['impressions'] ?? 0),0).'</td><td>'.number_format(((float)($row['ctr'] ?? 0))*100,1).'%</td><td>'.number_format((float)($row['position'] ?? 0),1).'</td></tr>';
            }
        }
        echo '</tbody></table></div><p class="description">Search Consoleの仕様上、プライバシー保護などにより全検索語句が表示されるとは限りません。</p></div></div>';
    } catch (Throwable $e) {
        echo '<div class="notice error">'.e($e->getMessage()).'</div>';
    }
    return;
}

$messages = [
    'engagement' => '直帰率・エンゲージメント率を正確に出すには、セッション単位の滞在イベントと離脱判定の追加が必要です。誤差の大きい概算値は表示しません。',
    'conversions' => 'コンバージョンは、購入・問い合わせ・特定URL到達など「何をCVとするか」の設定が必要です。CV定義機能を追加してから計測します。',
    'duration' => '滞在時間はpageviewだけでは正確に測れません。pagehide / visibilitychange等を使った滞在イベントを追加してから表示します。',
];
if (isset($messages[$report])) {
    echo '<div class="panel"><div class="panel-body"><p>'.e($messages[$report]).'</p></div></div>';
    return;
}

if ($report === 'audience') {
    $stmt = $db->prepare("SELECT COALESCE(device,'不明') device, COUNT(*) views FROM raw_events WHERE site_id=? AND event_type='pageview' AND is_bot=0 AND occurred_at>=NOW()-INTERVAL 30 DAY GROUP BY device ORDER BY views DESC");
    $stmt->execute([$siteId]);
    echo '<div class="panel"><h2>過去30日のユーザー属性</h2><div class="panel-body"><table class="wp-list"><thead><tr><th>デバイス</th><th>PV</th></tr></thead><tbody>';
    foreach ($stmt->fetchAll() as $row) echo '<tr><td>'.e($row['device']).'</td><td>'.number_format((int)$row['views']).'</td></tr>';
    echo '</tbody></table><p class="description">現時点では端末種別などの技術属性を表示します。年齢・性別などの個人属性は取得しません。</p></div></div>';
}
