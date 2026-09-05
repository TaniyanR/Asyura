<?php
declare(strict_types=1);

if (!isset($asyuraCurrentSite) || !is_array($asyuraCurrentSite)) {
    echo '<div class="empty-state"><strong>サイトが選択されていません。</strong><p>ヘッダーから管理するサイトを選択してください。</p></div>';
    return;
}

$tag = '<script src="' . app_url('assets/tracker.js') . '" data-site-id="' . $asyuraCurrentSite['public_id'] . '" data-site-key="' . $asyuraCurrentSite['site_key'] . '" async></script>';

echo '<div class="panel tracking-panel"><h2>計測タグ</h2><div class="panel-body">';
echo '<p><strong>' . e($asyuraCurrentSite['name']) . '</strong> の全ページに次のタグを設置してください。</p>';
echo '<p class="description">PV・クリックに加え、セッション、実滞在時間、スクロール深度、設定したCVを計測します。既存タグと同じ貼り付け方で更新できます。</p>';
echo '<pre id="tracking-tag" class="codebox">' . e($tag) . '</pre>';
echo '<button type="button" class="button primary" data-copy="#tracking-tag">計測タグをコピー</button>';
echo '</div></div>';
