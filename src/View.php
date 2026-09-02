<?php
declare(strict_types=1);

namespace Asyura;

final class View
{
    public static function header(string $title, string $active = 'dashboard', array $sites = [], ?array $currentSite = null): void
    {
        Security::headers();

        $siteId = (int) ($currentSite['id'] ?? 0);
        $siteQuery = $siteId > 0 ? '&site=' . $siteId : '';
        $sitePages = ['dashboard','analytics','ranking','links','requests','rss','rotation','notices','urls'];
        $isSitePage = in_array($active, $sitePages, true);
        $displayTitle = $isSitePage && $currentSite ? $title . '｜' . (string) $currentSite['name'] : $title;

        echo '<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex"><title>' . e($displayTitle) . ' ‹ 阿修羅</title><link rel="stylesheet" href="' . e(app_url('assets/admin.css')) . '"></head><body>';

        echo '<header class="topbar">';
        echo '<button type="button" class="mobile-menu-button" data-mobile-menu-toggle aria-label="メニューを開く" aria-expanded="false"><span></span><span></span><span></span></button>';
        echo '<a class="topbar-brand" href="' . e(app_url('admin/?page=dashboard' . $siteQuery)) . '">阿修羅</a>';
        echo '<span class="topbar-current">' . ($currentSite ? e($currentSite['name']) : '管理サイト未登録') . '</span>';
        echo '<span class="spacer"></span><span class="topbar-user">' . e($_SESSION['admin_username'] ?? 'admin') . '</span><a class="topbar-logout" href="' . e(app_url('logout.php')) . '">ログアウト</a></header>';

        echo '<div class="sidebar-overlay" data-sidebar-overlay></div>';
        echo '<aside class="sidebar" data-sidebar>';
        echo '<div class="sidebar-head"><a class="sidebar-brand" href="' . e(app_url('admin/?page=dashboard' . $siteQuery)) . '"><span class="logo-mark">阿</span><span><strong>阿修羅</strong><small>管理画面</small></span></a><button type="button" class="sidebar-close" data-mobile-menu-close aria-label="メニューを閉じる">×</button></div>';
        echo '<nav class="admin-nav">';
        echo '<span class="nav-section-title">基本</span>';
        echo self::menuLink('dashboard', 'ダッシュボード', $active, $siteQuery);
        echo self::menuLink('sites', 'サイト管理', $active, '');
        echo '<span class="nav-section-title">アクセス</span>';
        echo self::menuLink('analytics', 'アクセス解析', $active, $siteQuery);
        echo self::menuLink('ranking', '逆アクセスランキング', $active, $siteQuery);
        echo '<span class="nav-section-title">連携</span>';
        echo self::menuLink('links', '相互リンク', $active, $siteQuery, in_array($active, ['links','requests'], true));
        echo self::menuLink('rss', '相互RSS', $active, $siteQuery);
        echo '<span class="nav-section-title">運用</span>';
        echo self::menuLink('rotation', '過去記事', $active, $siteQuery);
        echo self::menuLink('notices', 'お知らせ', $active, $siteQuery);
        echo '<span class="nav-section-title">システム</span>';
        echo self::menuLink('urls', 'URL統一・除外', $active, $siteQuery);
        echo self::menuLink('management_links', '管理リンク', $active, '');
        echo self::menuLink('data', 'データ管理', $active, '');
        echo self::menuLink('settings', '設定', $active, '');
        echo '<a class="nav-logout" href="' . e(app_url('logout.php')) . '">ログアウト</a>';
        echo '</nav></aside>';

        echo '<main class="content">';

        if ($isSitePage) {
            echo '<section class="site-switch-bar" aria-label="現在の対象サイト">';
            echo '<div class="site-switch-current"><span class="site-switch-label">対象サイト</span><strong>' . ($currentSite ? e($currentSite['name']) : '未登録') . '</strong>';
            if ($currentSite) {
                echo '<span class="site-switch-status ' . (!empty($currentSite['active']) ? 'is-active' : '') . '"><span></span>' . (!empty($currentSite['active']) ? '計測中' : '停止') . '</span>';
            }
            echo '</div>';
            if ($sites !== []) {
                echo '<form method="get" class="site-switch-form"><input type="hidden" name="page" value="' . e($active) . '"><label class="sr-only" for="site-switch">対象サイトを切り替える</label><select id="site-switch" name="site" onchange="this.form.submit()">';
                foreach ($sites as $site) {
                    echo '<option value="' . (int) $site['id'] . '"' . ((int) $site['id'] === $siteId ? ' selected' : '') . '>' . e($site['name']) . '</option>';
                }
                echo '</select></form>';
                if ($currentSite) {
                    echo '<a class="site-switch-link" href="' . e(app_url('admin/?page=sites&edit=' . $siteId)) . '">設定</a>';
                }
            } else {
                echo '<a class="button primary" href="' . e(app_url('admin/?page=sites&new=1')) . '">サイトを登録</a>';
            }
            echo '</section>';
        }

        echo '<div class="page-heading"><div><span class="eyebrow">' . (in_array($active, ['sites','management_links','data','settings'], true) ? '全体管理' : 'サイト別管理') . '</span><h1 class="page-title">' . e($displayTitle) . '</h1></div></div>';

        if (in_array($active, ['links','requests'], true)) {
            echo '<nav class="page-tabs" aria-label="相互リンクメニュー">';
            echo self::tabLink('requests', '申請待ち', $active, $siteQuery);
            echo self::tabLink('links', '登録・一覧', $active, $siteQuery);
            echo '</nav>';
        } elseif ($active === 'rss') {
            echo '<nav class="page-tabs" aria-label="相互RSSメニュー"><span class="page-tab active">RSS一覧・登録</span></nav>';
        }

        if (!empty($_SESSION['flash'])) {
            echo '<div class="notice ' . e($_SESSION['flash']['type'] ?? 'success') . '">' . e($_SESSION['flash']['message']) . '</div>';
            unset($_SESSION['flash']);
        }
    }

    private static function menuLink(string $page, string $label, string $active, string $siteQuery = '', bool $forceActive = false): string
    {
        $isActive = $forceActive || $page === $active;
        return '<a class="nav-link' . ($isActive ? ' active' : '') . '" href="' . e(app_url('admin/?page=' . $page . $siteQuery)) . '"><span>' . e($label) . '</span></a>';
    }

    private static function tabLink(string $page, string $label, string $active, string $siteQuery): string
    {
        return '<a class="page-tab' . ($page === $active ? ' active' : '') . '" href="' . e(app_url('admin/?page=' . $page . $siteQuery)) . '">' . e($label) . '</a>';
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
