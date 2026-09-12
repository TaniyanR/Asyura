<?php
declare(strict_types=1);

namespace Asyura;

/** Shared starter designs; never replace a user-edited HTML/CSS pair. */
final class WidgetDesign
{
    public static function defaults(string $type, string $slot = ''): array
    {
        if ($type === 'ranking') {
            return [
                'template_html' => '<div class="ranking-row"><span class="ranking-position">{rank}</span><a href="{url}" rel="nofollow" target="_blank">{title}</a><span class="ranking-count">{in_count}<small> IN</small></span></div>',
                'custom_css' => '.asyura-ranking{width:100%;height:auto;font:14px/1.5 sans-serif;color:#263445;background:#fff;border:1px solid #dce2e8;border-radius:6px;overflow:hidden}.ranking-row{display:grid;grid-template-columns:32px minmax(0,1fr) auto;align-items:center;gap:10px;padding:10px 12px;border-bottom:1px solid #e8edf2}.ranking-row:last-child{border-bottom:0}.ranking-position{display:grid;place-items:center;width:28px;height:28px;background:#edf1f5;border-radius:4px;font-weight:bold}.ranking-row:nth-child(1) .ranking-position{background:#d3a32d;color:#fff}.ranking-row:nth-child(2) .ranking-position{background:#8496a7;color:#fff}.ranking-row:nth-child(3) .ranking-position{background:#ae7852;color:#fff}.ranking-row a{color:#245787;text-decoration:none;overflow-wrap:anywhere}.ranking-row a:hover{text-decoration:underline}.ranking-count{font-weight:bold;font-variant-numeric:tabular-nums}.ranking-count small{font-size:10px;color:#687787}',
            ];
        }
        if ($type === 'rss' && str_starts_with($slot, 'IMAGE-')) {
            return [
                'template_html' => '<article class="rss-card"><a href="{url}" target="_blank" rel="nofollow noopener">{image_tag}<span class="rss-title">{title}</span></a><small class="rss-site">{site_name}</small></article>',
                'custom_css' => '.asyura-rss{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;width:100%;height:auto;padding:12px;background:#383838;font:14px/1.5 sans-serif}.rss-card{min-width:0;margin:0}.rss-card a{display:block;color:#e1b34d;text-decoration:none;font-weight:bold}.rss-card a:hover{text-decoration:underline}.rss-card img{display:block;width:100%;height:auto;aspect-ratio:4/3;object-fit:cover;border-radius:3px;margin:0 0 8px}.rss-title{display:block;overflow-wrap:anywhere}.rss-site{display:block;color:#c5c5c5;margin-top:5px}@media(max-width:700px){.asyura-rss{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}}@media(max-width:340px){.asyura-rss{grid-template-columns:1fr}}',
            ];
        }
        if ($type === 'rss') {
            return ['template_html'=>'<a href="{url}" target="_blank" rel="nofollow noopener">{title}</a>', 'custom_css'=>'.asyura-rss{width:100%;height:auto;font:14px/1.6 sans-serif}.asyura-rss a{display:block;padding:6px 0;border-bottom:1px solid #eee}'];
        }
        return ['template_html'=>'<a href="{url}" rel="{rel}" target="{target}">{title}</a><br>', 'custom_css'=>'.asyura-links{width:100%;height:auto;font:14px/1.7 sans-serif}'];
    }

    public static function withDefaults(array $widget): array
    {
        $type = (string) $widget['type'];
        $slot = (string) $widget['slot_code'];
        $legacy = null;
        if ($type === 'ranking') {
            $legacy = ['<a href="{url}" rel="nofollow">{rank}. {title}</a> <span>{in_count}</span>', '.asyura-ranking{font-size:13px;background:#fff;border:1px solid #ddd;padding:8px}'];
        } elseif ($type === 'rss' && str_starts_with($slot, 'IMAGE-')) {
            $legacy = ['<article><a href="{url}">{image_tag}<span>{title}</span></a></article>', '.asyura-rss article{display:flex;margin:0 0 10px}.asyura-rss img{width:96px;height:72px;object-fit:cover;margin-right:10px}'];
        }
        $html = trim((string) ($widget['template_html'] ?? ''));
        $css = trim((string) ($widget['custom_css'] ?? ''));
        if ($legacy && (($html === $legacy[0] && $css === $legacy[1]) || ($html === '' && $css === ''))) {
            $widget = array_replace($widget, self::defaults($type, $slot));
        }
        return $widget;
    }

    public static function samples(string $type): array
    {
        $rows = [];
        $titles = ['青空の下で楽しむ週末のお出かけ', '街で見つけた新しい話題を紹介', '毎日の暮らしに役立つ小さな工夫', '季節のおすすめをピックアップ', '休日にゆっくり読みたい記事', '気になるニュースをまとめてチェック', '今日の注目トピックをお届け', 'お気に入りの過ごし方を見つけよう'];
        foreach ($titles as $index => $title) {
            $rows[] = ['title'=>$type === 'ranking' || $type === 'links' ? 'サンプルサイト '.($index+1) : $title, 'url'=>'#', 'image_url'=>app_url('assets/rss-sample.svg'), 'site_name'=>'サンプルサイト '.($index+1), 'rss_name'=>'総合RSS', 'rank'=>$index+1, 'in_count'=>max(1, 256-$index*31), 'out_count'=>max(1, 190-$index*23), 'description'=>'デザイン確認用のサンプルです。', 'category'=>'サンプル', 'published_at'=>'2026-09-12 12:00', 'rel'=>'nofollow', 'target'=>'_blank'];
        }
        return $rows;
    }

    public static function embed(array $widget): string
    {
        $url = app_url('widgets/'.$widget['type'].'.php?id='.$widget['public_id']);
        return '<iframe src="'.e($url).'" title="'.e($widget['name']).'" loading="lazy" data-asyura-embed style="display:block;width:100%;border:0"></iframe>'."\n".'<script src="'.e(app_url('assets/widget-embed.js')).'" defer></script>';
    }
}
