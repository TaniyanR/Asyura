<?php
declare(strict_types=1);

use Asyura\WidgetDesign;
use Asyura\WidgetRenderer;

function asyura_widget_design_editor(PDO $db, array $widget): void
{
    global $config;
    $widget = WidgetDesign::withDefaults($widget);
    $type = (string) $widget['type'];
    $siteId = (int) $widget['site_id'];
    $imageOnly = $type === 'rss' && str_starts_with((string)$widget['slot_code'], 'IMAGE-');
    $widgetConfig = json_decode((string)$widget['config_json'], true) ?: [];
    $renderer = new WidgetRenderer($db, $config);
    $items = match ($type) {
        'rss' => $renderer->rss($widget),
        'ranking' => $renderer->ranking($widget),
        'links' => $renderer->links($widget),
        'notices' => $renderer->notices($widget),
        default => [],
    };
    // Preview never emits signed outbound URLs or records a click.
    foreach ($items as &$item) $item['url'] = '#';
    unset($item);
    $preview = ['type'=>$type, 'samples'=>WidgetDesign::samples($type), 'items'=>$items];
    echo '<form method="post" class="widget-design-form'.($type === 'links' ? ' link-widget-design-form' : '').'" data-widget-editor><input type="hidden" name="action" value="save_widget"><input type="hidden" name="id" value="'.(int)$widget['id'].'">'.asyura_context_field().csrf_field();
    echo '<div class="panel"><h2>'.e($widget['site_name'].' / '.$widget['name']).'</h2><div class="panel-body"><label class="widget-name-field">名称<input name="name" value="'.e($widget['name']).'"></label><div class="checks"><label><input type="checkbox" name="enabled" '.($widget['enabled'] ? 'checked' : '').'>表示する</label>';
    if ($type === 'rss' && !$imageOnly) echo '<label><input type="checkbox" name="image_required" '.(!empty($widgetConfig['image_required']) ? 'checked' : '').'>画像がある記事だけ</label>';
    echo '</div>';
    if ($type === 'rss') echo '<div class="notice info">登録完了の相互リンク・相互RSSサイトに紐づく、有効なRSSをすべて配分対象にします。表示場所ごとのRSS選択は不要です。変換率・返還済みアクセス・除外設定をもとに記事を選びます。'.($imageOnly ? '画像RSSには使用可能な画像がある記事だけを表示します。' : '').'</div>';
    if ($type === 'links') {
        echo '<div class="notice info link-display-count-note">この場所に設定されている相互リンクをすべて表示します。表示件数の設定は不要です。</div>';
    } else {
        echo '<label>表示件数<input type="number" min="1" max="100" name="item_limit" value="'.(int)$widget['item_limit'].'"></label>';
    }
    echo '<p class="description">横幅と高さはCSSで設定します（例：.asyura-'.e($type).'{width:100%;height:auto}）。新しい設置タグでは内容の高さに合わせて表示枠が自動調整されます。</p>';
    echo '<label>表示のひな型（HTML）<textarea name="template_html">'.e($widget['template_html']).'</textarea></label><label>色・大きさ（CSS）<textarea name="custom_css">'.e($widget['custom_css']).'</textarea></label><p class="description">使用可能：{title} {url} {description} {category} {rank} {in_count} {out_count} {site_name} {rss_name} {image} {image_tag} {published_at} {rel} {target}</p>';
    echo '<div class="widget-preview-panel"><h3>デザインプレビュー</h3><div class="actions"><label>確認データ<select data-preview-source><option value="samples">サンプル</option><option value="items">実際の配信候補</option></select></label><label>表示幅<select data-preview-width><option value="100%">パソコン</option><option value="390px">スマートフォン</option></select></label></div><p class="description" data-preview-status aria-live="polite">HTML・CSSの編集内容をすぐに反映します。サンプルは公開サイトには表示されません。</p><iframe title="デザインプレビュー" sandbox="" data-widget-preview></iframe></div>';
    echo '<div class="actions widget-save-actions"><button class="button primary">デザインを保存</button><a class="button" href="'.e(app_url('admin/?page='.$type.'&site='.$siteId.($type === 'links' ? '&widgets=1' : ''))).'">一覧へ戻る</a></div></div></div></form>';
    echo '<script type="application/json" id="widget-preview-data">'.json_encode($preview, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE).'</script>';
    echo '<script src="'.e(app_url('assets/widget-preview.js?v='.filemtime(dirname(__DIR__).'/assets/widget-preview.js'))).'" defer></script>';
    echo '<div class="panel embed-code-section"><h2>サイトへの設置タグ</h2><div class="panel-body"><p class="description">表示したい場所へ貼り付けてください。以前のiframeタグを使っている場合は、このタグへ差し替えると高さの自動調整が有効になります。</p><pre class="codebox" id="widget-tag">'.e(WidgetDesign::embed($widget)).'</pre><button type="button" class="button" data-copy="#widget-tag">タグをコピー</button></div></div>';
}
