<?php
declare(strict_types=1);

/**
 * 管理リンクに表示する外部サービス。
 * 各セクションへ label / url / description を追加するだけで拡張できます。
 * 「自分のサイト」はこのファイルではなく、sitesテーブルから取得します。
 */
return [
    'Google・SEO'=>[
        ['label'=>'Google Search Console','url'=>'https://search.google.com/search-console/','description'=>'検索パフォーマンスとインデックスを確認'],
        ['label'=>'Google Analytics','url'=>'https://analytics.google.com/','description'=>'アクセス状況を確認'],
        ['label'=>'PageSpeed Insights','url'=>'https://pagespeed.web.dev/','description'=>'ページ速度とCore Web Vitalsを確認'],
        ['label'=>'Google リッチリザルト テスト','url'=>'https://search.google.com/test/rich-results/','description'=>'構造化データを確認'],
        ['label'=>'Chrome Lighthouse','url'=>'https://developer.chrome.com/docs/lighthouse/overview/','description'=>'品質監査ツールを開く'],
    ],
    'HTML・Web確認'=>[
        ['label'=>'W3C HTML Validator','url'=>'https://validator.w3.org/','description'=>'HTMLの構文を確認'],
        ['label'=>'W3C CSS Validator','url'=>'https://jigsaw.w3.org/css-validator/','description'=>'CSSの構文を確認'],
    ],
    'GitHub'=>[
        ['label'=>'GitHubトップ','url'=>'https://github.com/','description'=>'GitHubを開く'],
        ['label'=>'GitHub リポジトリ一覧','url'=>'https://github.com/TaniyanR?tab=repositories','description'=>'TaniyanRのリポジトリを開く'],
    ],
    'AI'=>[
        ['label'=>'ChatGPT','url'=>'https://chatgpt.com/'],
        ['label'=>'Claude','url'=>'https://claude.ai/'],
        ['label'=>'Gemini','url'=>'https://gemini.google.com/'],
        ['label'=>'Perplexity','url'=>'https://www.perplexity.ai/'],
    ],
    'その他ツール'=>[],
];
