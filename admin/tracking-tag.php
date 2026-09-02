<?php
declare(strict_types=1);

if (!isset($asyuraCurrentSite) || !is_array($asyuraCurrentSite)) {
    echo '<div class="empty-state"><strong>サイトが選択されていません。</strong><p>ヘッダーから管理するサイトを選択してください。</p></div>';
    return;
}

$tag = '<script src="' . app_url('assets/tracker.js') . '" data-site-id="' . $asyuraCurrentSite['public_id'] . '" data-site-key="' . $asyuraCurrentSite['site_key'] . '" async></script>';

echo '<div class="panel">';
echo '<h2>計測タグ</h2>';
echo '<div class="panel-body">';
echo '<p><strong>' . e($asyuraCurrentSite['name']) . '</strong> の全ページに、次のタグを設置してください。</p>';
echo '<pre id="tracking-tag" class="codebox">' . e($tag) . '</pre>';
echo '<div class="actions"><button type="button" class="button primary" data-copy="#tracking-tag">計測タグをコピー</button></div>';
echo '</div></div>';
