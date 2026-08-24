<?php
declare(strict_types=1);

namespace Asyura;

final class View
{
    public static function header(string $title, string $active = 'dashboard', array $sites = [], ?array $currentSite = null): void
    {
        Security::headers();
        $menus = [
            'dashboard' => 'ダッシュボード', 'sites' => 'サイト管理', 'analytics' => 'アクセス解析',
            'ranking' => '逆アクセスランキング', 'links' => '相互リンク', 'requests' => '相互リンク依頼',
            'rss' => '相互RSS', 'rotation' => '過去記事再配信', 'notices' => 'お知らせ',
            'urls' => 'URL統一・除外', 'management_links' => '管理リンク', 'data' => 'データ管理', 'settings' => '設定',
        ];
        $siteId = (int) ($currentSite['id'] ?? 0);
        $siteQuery = $siteId > 0 ? '&site=' . $siteId : '';
        echo '<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex"><title>' . e($title) . ' ‹ 阿修羅</title><link rel="stylesheet" href="' . e(app_url('assets/admin.css')) . '"></head><body>';
        echo '<div class="topbar"><a class="topbar-brand" href="' . e(app_url('admin/?page=dashboard' . $siteQuery)) . '">阿修羅</a><span class="topbar-current">' . ($currentSite ? e($currentSite['name']) . ' を管理中' : '管理サイト未登録') . '</span><span class="spacer"></span><span class="topbar-user">' . e($_SESSION['admin_username'] ?? 'admin') . '</span><a href="' . e(app_url('logout.php')) . '">ログアウト</a></div>';
        echo '<aside class="sidebar"><div class="logo"><span class="logo-mark">阿</span><span>阿修羅</span></div><nav>';
        foreach ($menus as $key => $label) {
            $global = in_array($key, ['sites','management_links','data','settings'], true);
            echo '<a class="menu-' . e($key) . ' ' . ($key === $active ? 'active' : '') . '" href="' . e(app_url('admin/?page=' . $key . ($global ? '' : $siteQuery))) . '"><span class="menu-dot"></span>' . e($label) . '</a>';
        }
        echo '</nav></aside><main class="content">';
        echo '<section class="site-context"><div class="site-context-copy"><span class="eyebrow">現在の管理対象</span>';
        if ($currentSite) {
            echo '<strong>' . e($currentSite['name']) . '</strong><a href="' . e($currentSite['url']) . '" target="_blank" rel="noopener">' . e($currentSite['url']) . '</a>';
        } else {
            echo '<strong>管理サイトがありません</strong><span>最初にサイトを登録してください。</span>';
        }
        echo '</div>';
        if ($sites !== []) {
            echo '<form method="get" class="site-switcher"><input type="hidden" name="page" value="' . e($active) . '"><label for="site-switch">管理サイトを切り替える</label><select id="site-switch" name="site" onchange="this.form.submit()">';
            foreach ($sites as $site) {
                echo '<option value="' . (int) $site['id'] . '"' . ((int) $site['id'] === $siteId ? ' selected' : '') . '>' . e($site['name']) . '</option>';
            }
            echo '</select></form>';
        } else {
            echo '<a class="button primary" href="' . e(app_url('admin/?page=sites&new=1')) . '">最初のサイトを登録</a>';
        }
        echo '</section><div class="page-heading"><div><span class="eyebrow">' . (in_array($active, ['sites','management_links','data','settings'], true) ? '阿修羅 全体管理' : 'サイト別管理') . '</span><h1 class="page-title">' . e($title) . '</h1></div>';
        if ($currentSite && !in_array($active, ['sites','management_links','data','settings'], true)) {
            echo '<span class="site-status ' . (!empty($currentSite['active']) ? 'is-active' : '') . '">' . (!empty($currentSite['active']) ? '計測中' : '計測停止') . '</span>';
        }
        echo '</div>';
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
