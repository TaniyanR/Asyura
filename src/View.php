<?php
declare(strict_types=1);

namespace Asyura;

final class View
{
    public static function header(string $title, string $active = 'dashboard'): void
    {
        Security::headers();
        $menus = [
            'dashboard' => 'ダッシュボード', 'sites' => 'サイト管理', 'analytics' => 'アクセス解析',
            'ranking' => '逆アクセスランキング', 'links' => '相互リンク', 'requests' => '相互リンク依頼',
            'rss' => '相互RSS', 'rotation' => '過去記事再配信', 'notices' => 'お知らせ',
            'urls' => 'URL統一・除外', 'data' => 'データ管理', 'settings' => '設定',
        ];
        echo '<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . e($title) . ' ‹ 阿修羅</title><link rel="stylesheet" href="' . e(app_url('assets/admin.css')) . '"></head><body>';
        echo '<div class="topbar"><a href="' . e(app_url('admin/')) . '">阿修羅</a><span class="spacer"></span><span>' . e($_SESSION['admin_username'] ?? 'admin') . '　</span><a href="' . e(app_url('logout.php')) . '">ログアウト</a></div>';
        echo '<aside class="sidebar"><div class="logo">阿修羅</div><nav>';
        foreach ($menus as $key => $label) {
            echo '<a class="' . ($key === $active ? 'active' : '') . '" href="' . e(app_url('admin/?page=' . $key)) . '">' . e($label) . '</a>';
        }
        echo '</nav></aside><main class="content"><h1 class="page-title">' . e($title) . '</h1>';
        if (!empty($_SESSION['flash'])) {
            echo '<div class="notice ' . e($_SESSION['flash']['type'] ?? 'success') . '">' . e($_SESSION['flash']['message']) . '</div>';
            unset($_SESSION['flash']);
        }
    }

    public static function footer(): void
    {
        echo '<p class="footer-note">阿修羅（Asyura）</p></main><script src="' . e(app_url('assets/admin.js')) . '"></script></body></html>';
    }

    public static function flash(string $message, string $type = 'success'): void
    {
        Security::startSession();
        $_SESSION['flash'] = ['message' => $message, 'type' => $type];
    }
}
