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

        $groups = [
            'access' => [
                'label' => 'アクセス',
                'icon' => '↗',
                'items' => [
                    'analytics' => 'アクセス解析',
                    'ranking' => '逆アクセスランキング',
                    'urls' => 'URL統一・除外',
                ],
            ],
            'links' => [
                'label' => '相互リンク',
                'icon' => '⇄',
                'items' => [
                    'requests' => '申請一覧',
                    'links' => '登録済みリンク・登録',
                ],
            ],
            'rss' => [
                'label' => '相互RSS',
                'icon' => '◫',
                'items' => [
                    'rss' => 'RSS管理',
                ],
            ],
            'content' => [
                'label' => 'コンテンツ',
                'icon' => '▤',
                'items' => [
                    'rotation' => '過去記事再配信',
                    'notices' => 'お知らせ',
                ],
            ],
            'management' => [
                'label' => '管理',
                'icon' => '⚙',
                'items' => [
                    'management_links' => '管理リンク',
                    'data' => 'データ管理',
                    'settings' => '設定',
                ],
            ],
        ];

        $groupForPage = static function (string $page) use ($groups): string {
            foreach ($groups as $key => $group) {
                if (isset($group['items'][$page])) {
                    return $key;
                }
            }
            return '';
        };
        $activeGroup = $groupForPage($active);

        echo '<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex"><title>' . e($displayTitle) . ' ‹ 阿修羅</title><link rel="stylesheet" href="' . e(app_url('assets/admin.css')) . '"></head><body>';
        echo '<div class="topbar"><button type="button" class="mobile-menu-button" data-mobile-menu-toggle aria-label="メニューを開く" aria-expanded="false"><span></span><span></span><span></span></button><a class="topbar-brand" href="' . e(app_url('admin/?page=dashboard' . $siteQuery)) . '">阿修羅</a><span class="topbar-current">' . ($currentSite ? e($currentSite['name']) . ' を管理中' : '管理サイト未登録') . '</span><span class="spacer"></span><span class="topbar-user">' . e($_SESSION['admin_username'] ?? 'admin') . '</span><a class="topbar-logout" href="' . e(app_url('logout.php')) . '">ログアウト</a></div>';

        echo '<div class="sidebar-overlay" data-sidebar-overlay></div>';
        echo '<aside class="sidebar" data-sidebar><div class="sidebar-head"><div class="logo"><span class="logo-mark">阿</span><span><strong>阿修羅</strong><small>Asyura Admin</small></span></div><button type="button" class="sidebar-close" data-mobile-menu-close aria-label="メニューを閉じる">×</button></div><nav class="admin-nav">';
        echo '<div class="nav-section-label">メイン</div>';
        echo self::menuLink('dashboard', 'ダッシュボード', $active, $siteQuery, false, '⌂');
        echo self::menuLink('sites', 'サイト管理', $active, '', false, '◆');
        echo '<div class="nav-section-label">サイト別機能</div>';

        foreach ($groups as $groupKey => $group) {
            $open = $activeGroup === $groupKey;
            echo '<div class="nav-group' . ($open ? ' is-open' : '') . '" data-nav-group>';
            echo '<button type="button" class="nav-parent" data-nav-toggle aria-expanded="' . ($open ? 'true' : 'false') . '"><span class="nav-icon">' . e($group['icon']) . '</span><span class="nav-parent-label">' . e($group['label']) . '</span><span class="nav-chevron" aria-hidden="true">⌄</span></button>';
            echo '<div class="nav-children">';
            foreach ($group['items'] as $page => $label) {
                $global = in_array($page, ['management_links','data','settings'], true);
                echo self::menuLink($page, $label, $active, $global ? '' : $siteQuery, true);
            }
            echo '</div></div>';
        }
        echo '</nav></aside><main class="content">';

        if ($isSitePage) {
            echo '<details class="site-context-card">';
            echo '<summary>';
            echo '<span class="site-context-main">';
            echo '<span class="site-context-kicker">現在の対象サイト</span>';
            echo '<span class="site-context-name">' . ($currentSite ? e($currentSite['name']) : '管理サイトがありません') . '</span>';
            if ($currentSite) {
                echo '<span class="site-context-url">' . e($currentSite['url']) . '</span>';
            }
            echo '</span>';
            echo '<span class="site-context-status-wrap">';
            if ($currentSite) {
                echo '<span class="site-context-status ' . (!empty($currentSite['active']) ? 'is-active' : '') . '"><span class="status-dot"></span>' . (!empty($currentSite['active']) ? '計測中' : '計測停止') . '</span>';
            }
            echo '<span class="site-context-toggle"><span class="desktop-label">サイト詳細・切替</span><span class="mobile-label">詳細</span><span class="site-context-chevron">⌄</span></span>';
            echo '</span>';
            echo '</summary>';
            echo '<div class="site-context-body">';
            if ($currentSite) {
                echo '<div class="site-context-info"><div class="site-info-row"><span>サイト名</span><strong>' . e($currentSite['name']) . '</strong></div><div class="site-info-row"><span>URL</span><a href="' . e($currentSite['url']) . '" target="_blank" rel="noopener noreferrer">' . e($currentSite['url']) . '</a></div><div class="site-info-row"><span>site_id</span><strong>' . $siteId . '</strong></div></div>';
            } else {
                echo '<div class="site-context-info"><strong>管理サイトがありません</strong><span>最初にサイトを登録してください。</span></div>';
            }
            echo '<div class="site-context-actions">';
            if ($sites !== []) {
                echo '<form method="get" class="site-switcher"><input type="hidden" name="page" value="' . e($active) . '"><label for="site-switch">管理サイトを切り替える</label><select id="site-switch" name="site" onchange="this.form.submit()">';
                foreach ($sites as $site) {
                    echo '<option value="' . (int) $site['id'] . '"' . ((int) $site['id'] === $siteId ? ' selected' : '') . '>' . e($site['name']) . '</option>';
                }
                echo '</select></form>';
                if ($currentSite) {
                    echo '<div class="site-context-buttons"><a class="button" href="' . e(app_url('admin/?page=sites&edit=' . $siteId)) . '">サイト設定</a><a class="button" href="' . e($currentSite['url']) . '" target="_blank" rel="noopener noreferrer">公開サイトを開く</a></div>';
                }
            } else {
                echo '<a class="button primary" href="' . e(app_url('admin/?page=sites&new=1')) . '">最初のサイトを登録</a>';
            }
            echo '</div></div></details>';
        }

        echo '<div class="page-heading"><div><span class="eyebrow">' . (in_array($active, ['sites','management_links','data','settings'], true) ? '阿修羅 全体管理' : 'サイト別管理') . '</span><h1 class="page-title">' . e($displayTitle) . '</h1></div>';
        if ($currentSite && $isSitePage) {
            echo '<span class="site-status ' . (!empty($currentSite['active']) ? 'is-active' : '') . '">' . (!empty($currentSite['active']) ? '計測中' : '計測停止') . '</span>';
        }
        echo '</div>';

        if (!empty($_SESSION['flash'])) {
            echo '<div class="notice ' . e($_SESSION['flash']['type'] ?? 'success') . '">' . e($_SESSION['flash']['message']) . '</div>';
            unset($_SESSION['flash']);
        }
    }

    private static function menuLink(string $page, string $label, string $active, string $siteQuery = '', bool $child = false, string $icon = ''): string
    {
        $iconHtml = $child ? '<span class="nav-child-line"></span>' : '<span class="nav-icon">' . e($icon) . '</span>';
        return '<a class="menu-' . e($page) . ' ' . ($child ? 'nav-child ' : 'nav-root ') . ($page === $active ? 'active' : '') . '" href="' . e(app_url('admin/?page=' . $page . $siteQuery)) . '">' . $iconHtml . '<span>' . e($label) . '</span></a>';
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
