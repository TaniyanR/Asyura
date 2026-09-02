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
        $displayTitle = $currentSite && $active !== 'sites' ? $title . '｜' . (string) $currentSite['name'] : $title;
        $view = (string) ($_GET['view'] ?? '');

        $groups = [
            ['label'=>'アクセス','mark'=>'●','class'=>'nav-access','pages'=>['analytics','ranking','urls','tracking'],'items'=>[
                ['analytics','アクセス解析',''],['ranking','逆アクセスランキング',''],['urls','URL統一・除外',''],['tracking','計測タグ',''],
            ]],
            ['label'=>'相互リンク','mark'=>'↔','class'=>'nav-links','pages'=>['links','requests'],'items'=>[
                ['requests','申請一覧',''],['links','登録済みリンク','list'],['links','新規登録','new'],
            ]],
            ['label'=>'相互RSS','mark'=>'◆','class'=>'nav-rss','pages'=>['rss'],'items'=>[
                ['rss','RSS登録','new'],['rss','RSS一覧','list'],['rss','配分・設定','settings'],
            ]],
            ['label'=>'コンテンツ','mark'=>'▣','class'=>'nav-content','pages'=>['rotation','notices'],'items'=>[
                ['rotation','過去記事再配信',''],['notices','お知らせ',''],
            ]],
            ['label'=>'管理','mark'=>'⚙','class'=>'nav-management','pages'=>['management_links','data','settings'],'items'=>[
                ['management_links','管理リンク',''],['data','データ管理',''],['settings','設定',''],
            ]],
        ];

        echo '<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex"><title>' . e($displayTitle) . ' ‹ 阿修羅</title><link rel="stylesheet" href="' . e(app_url('assets/admin.css')) . '"><link rel="stylesheet" href="' . e(app_url('assets/admin-refined.css')) . '"><link rel="stylesheet" href="' . e(app_url('assets/admin-site-layout.css')) . '"></head><body>';

        echo '<header class="topbar asyura-topbar">';
        echo '<button type="button" class="mobile-menu-button" data-mobile-menu-toggle aria-label="メニューを開く" aria-expanded="false"><span></span><span></span><span></span></button>';
        echo '<a class="topbar-brand asyura-brand" href="' . e(app_url('admin/?page=dashboard')) . '">阿修羅</a>';
        echo '<div class="header-site-menu" data-site-menu>';
        echo '<button type="button" class="header-site-trigger" data-site-menu-toggle aria-expanded="false"><span class="site-trigger-label">' . ($currentSite ? e($currentSite['name']) : 'サイト登録') . '</span><span class="site-trigger-arrow">▼</span></button>';
        echo '<div class="header-site-dropdown" data-site-menu-panel>';
        echo '<a class="site-menu-register" href="' . e(app_url('admin/?page=sites&new=1')) . '">サイト登録</a>';
        if ($sites !== []) {
            echo '<div class="site-menu-divider"></div>';
            foreach ($sites as $site) {
                $selected = $siteId === (int) $site['id'];
                echo '<a class="site-menu-item' . ($selected ? ' is-selected' : '') . '" href="' . e(app_url('admin/?page=dashboard&site=' . (int) $site['id'])) . '"><span>' . e($site['name']) . '</span><small>' . e($site['url']) . '</small></a>';
            }
        }
        echo '</div></div><span class="spacer"></span><a class="topbar-logout" href="' . e(app_url('logout.php')) . '">ログアウト</a></header>';

        echo '<div class="sidebar-overlay" data-sidebar-overlay></div>';
        echo '<aside class="sidebar asyura-sidebar" data-sidebar><nav class="admin-nav asyura-nav">';

        if ($currentSite === null) {
            echo self::rootLink('dashboard', 'ダッシュボード', '■', $active, '', 'nav-dashboard');
            echo '<a class="nav-root nav-register' . ($active === 'sites' ? ' active' : '') . '" href="' . e(app_url('admin/?page=sites&new=1')) . '"><span class="nav-mark">＋</span><span>サイト登録</span></a>';
        } else {
            echo self::rootLink('dashboard', 'ダッシュボード', '■', $active, $siteQuery, 'nav-dashboard');
            foreach ($groups as $group) {
                $open = in_array($active, $group['pages'], true);
                echo '<div class="nav-group ' . e($group['class']) . ($open ? ' is-open' : '') . '" data-nav-group>';
                echo '<button type="button" class="nav-parent" data-nav-toggle aria-expanded="' . ($open ? 'true' : 'false') . '"><span class="nav-label-wrap"><span class="nav-mark">' . e($group['mark']) . '</span><span class="nav-parent-label">' . e($group['label']) . '</span></span><span class="nav-chevron">⌄</span></button>';
                echo '<div class="nav-children">';
                foreach ($group['items'] as [$page, $label, $itemView]) {
                    $isActive = $page === $active && ($itemView === '' || $view === $itemView || ($view === '' && $itemView === 'list'));
                    $query = $siteQuery . ($itemView !== '' ? '&view=' . rawurlencode($itemView) : '');
                    echo '<a class="nav-child' . ($isActive ? ' active' : '') . '" href="' . e(app_url('admin/?page=' . $page . $query)) . '"><span class="nav-mark">›</span><span>' . e($label) . '</span></a>';
                }
                echo '</div></div>';
            }
            echo '<a class="nav-root nav-logout" href="' . e(app_url('logout.php')) . '"><span class="nav-mark">⇥</span><span>ログアウト</span></a>';
        }

        echo '</nav></aside><main class="content asyura-content">';
        echo '<div class="page-heading"><h1 class="page-title">' . e($displayTitle) . '</h1></div>';

        if (!empty($_SESSION['flash'])) {
            echo '<div class="notice ' . e($_SESSION['flash']['type'] ?? 'success') . '">' . e($_SESSION['flash']['message']) . '</div>';
            unset($_SESSION['flash']);
        }
    }

    private static function rootLink(string $page, string $label, string $mark, string $active, string $siteQuery, string $class = ''): string
    {
        return '<a class="nav-root ' . e($class) . ($page === $active ? ' active' : '') . '" href="' . e(app_url('admin/?page=' . $page . $siteQuery)) . '"><span class="nav-mark">' . e($mark) . '</span><span>' . e($label) . '</span></a>';
    }

    public static function footer(): void
    {
        echo '</main><script src="' . e(app_url('assets/admin.js')) . '"></script></body></html>';
    }

    public static function flash(string $message, string $type = 'success'): void
    {
        Security::startSession();
        $_SESSION['flash'] = ['message' => $message, 'type' => $type];
    }
}
