<?php
declare(strict_types=1);

if (!isset($asyuraCurrentSite) || !is_array($asyuraCurrentSite)) {
    echo '<div class="empty-state"><strong>サイトが選択されていません。</strong></div>';
    return;
}

$siteId = (int) $asyuraCurrentSite['id'];
$report = (string) ($_GET['report'] ?? 'traffic');

if ($report === 'traffic') {
    $stmt = $db->prepare("SELECT COALESCE(SUM(pv),0) pv,COALESCE(SUM(uu),0) uu FROM daily_stats WHERE site_id=? AND stat_date=CURDATE()");
    $stmt->execute([$siteId]);
    $today = $stmt->fetch() ?: ['pv'=>0,'uu'=>0];
    $sessionsStmt = $db->prepare("SELECT COUNT(DISTINCT session_hash) FROM raw_events WHERE site_id=? AND event_type='pageview' AND is_bot=0 AND DATE(occurred_at)=CURDATE() AND session_hash IS NOT NULL");
    $sessionsStmt->execute([$siteId]);
    echo '<div class="cards">';
    echo '<div class="card"><div class="number">' . number_format((int) $today['pv']) . '</div><div class="label">今日のPV</div></div>';
    echo '<div class="card"><div class="number">' . number_format((int) $sessionsStmt->fetchColumn()) . '</div><div class="label">今日のセッション</div></div>';
    echo '<div class="card"><div class="number">' . number_format((int) $today['uu']) . '</div><div class="label">今日のUU</div></div>';
    echo '</div>';
    return;
}

if ($report === 'channels') {
    $stmt = $db->prepare("SELECT CASE WHEN referrer_host IS NULL OR referrer_host='' THEN '直接・不明' WHEN referrer_host LIKE '%google.%' OR referrer_host LIKE '%bing.%' OR referrer_host LIKE '%yahoo.%' THEN '検索' WHEN referrer_host LIKE '%twitter.com%' OR referrer_host LIKE '%x.com%' OR referrer_host LIKE '%facebook.com%' OR referrer_host LIKE '%instagram.com%' THEN 'SNS' ELSE '参照サイト' END channel,COUNT(*) visits FROM raw_events WHERE site_id=? AND event_type='pageview' AND is_bot=0 AND occurred_at>=NOW()-INTERVAL 30 DAY GROUP BY channel ORDER BY visits DESC");
    $stmt->execute([$siteId]);
    echo '<div class="panel"><h2>過去30日の流入経路</h2><div class="panel-body"><table class="wp-list"><thead><tr><th>チャネル</th><th>アクセス</th></tr></thead><tbody>';
    foreach ($stmt->fetchAll() as $row) echo '<tr><td>' . e((string) $row['channel']) . '</td><td>' . number_format((int) $row['visits']) . '</td></tr>';
    echo '</tbody></table></div></div>';
    return;
}

if ($report === 'pages') {
    $stmt = $db->prepare("SELECT normalized_page_url,COUNT(*) pv,COUNT(DISTINCT visitor_hash) uu FROM raw_events WHERE site_id=? AND event_type='pageview' AND is_bot=0 AND occurred_at>=NOW()-INTERVAL 30 DAY GROUP BY normalized_page_url ORDER BY pv DESC LIMIT 100");
    $stmt->execute([$siteId]);
    echo '<div class="panel"><h2>過去30日のページ別アクセス</h2><div class="panel-body"><table class="wp-list"><thead><tr><th>ページ</th><th>PV</th><th>UU</th></tr></thead><tbody>';
    foreach ($stmt->fetchAll() as $row) echo '<tr><td>' . e((string) $row['normalized_page_url']) . '</td><td>' . number_format((int) $row['pv']) . '</td><td>' . number_format((int) $row['uu']) . '</td></tr>';
    echo '</tbody></table></div></div>';
    return;
}

if ($report === 'audience') {
    $stmt = $db->prepare("SELECT COALESCE(device,'不明') device,COUNT(*) views FROM raw_events WHERE site_id=? AND event_type='pageview' AND is_bot=0 AND occurred_at>=NOW()-INTERVAL 30 DAY GROUP BY device ORDER BY views DESC");
    $stmt->execute([$siteId]);
    echo '<div class="panel"><h2>過去30日のユーザー属性</h2><div class="panel-body"><table class="wp-list"><thead><tr><th>デバイス</th><th>PV</th></tr></thead><tbody>';
    foreach ($stmt->fetchAll() as $row) echo '<tr><td>' . e((string) $row['device']) . '</td><td>' . number_format((int) $row['views']) . '</td></tr>';
    echo '</tbody></table></div></div>';
    return;
}

$messages = [
    'engagement'=>'直帰率・エンゲージメント率は、正確な追加計測を実装後に表示します。',
    'conversions'=>'コンバージョンは、CV条件の設定機能を作成後に表示します。',
    'duration'=>'滞在時間は、滞在イベントの追加計測を実装後に表示します。',
    'keywords'=>'流入キーワードは、Google Search Console連携を画面構成確定後に追加します。',
];
echo '<div class="panel"><div class="panel-body"><p>' . e($messages[$report] ?? 'この項目は準備中です。') . '</p></div></div>';
