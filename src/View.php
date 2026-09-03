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
        $view = (string) ($_GET['view'] ?? '');
        $displayTitle = $currentSite ? $title . '｜' . (string) $currentSite['name'] : $title;

        $groups = [
            ['label'=>'アクセス','icon'=>'◉','pages'=>['analytics','ranking','urls','tracking'],'items'=>[
                ['analytics','アクセス解析',''],['ranking','逆アクセスランキング',''],['urls','URL統一・除外',''],['tracking','計測タグ',''],
            ]],
            ['label'=>'相互リンク','icon'=>'↔','pages'=>['links','requests'],'items'=>[
                ['requests','申請一覧',''],['links','登録済みリンク','list'],['links','新規登録','new'],
            ]],
            ['label'=>'相互RSS','icon'=>'◆','pages'=>['rss'],'items'=>[
                ['rss','RSS登録','new'],['rss','RSS一覧','list'],['rss','配分・設定','settings'],
            ]],
            ['label'=>'コンテンツ','icon'=>'▣','pages'=>['rotation','notices'],'items'=>[
                ['rotation','過去記事再配信',''],['notices','お知らせ',''],
            ]],
            ['label'=>'管理','icon'=>'⚙','pages'=>['management_links','data','settings'],'items'=>[
                ['management_links','管理リンク',''],['data','データ管理',''],['settings','設定',''],
            ]],
        ];

        echo '<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow,noarchive,nosnippet,noimageindex"><title>' . e($displayTitle) . ' ‹ 阿修羅</title>';
        echo '<link rel="stylesheet" href="' . e(app_url('assets/admin.css')) . '">';
        echo '<link rel="stylesheet" href="' . e(app_url('assets/admin-clean.css')) . '"></head><body>';

        echo '<header class="asyura-bar">';
        echo '<a class="asyura-logo" href="' . e(app_url('admin/?page=dashboard')) . '">阿修羅</a>';
        echo '<div class="asyura-site-select" data-site-menu>';
        echo '<button type="button" class="asyura-site-button" data-site-menu-toggle aria-expanded="false"><span>' . ($currentSite ? e($currentSite['name']) : 'サイト登録') . '</span><b>⌄</b></button>';
        echo '<div class="asyura-site-dropdown">';
        echo '<a class="asyura-register-link" href="' . e(app_url('admin/?page=sites&new=1')) . '">＋ サイト登録</a>';
        if ($sites !== []) {
            echo '<div class="asyura-site-separator"></div>';
            foreach ($sites as $site) {
                echo '<a class="asyura-site-option' . ($siteId === (int) $site['id'] ? ' is-current' : '') . '" href="' . e(app_url('admin/?page=dashboard&site=' . (int) $site['id'])) . '"><strong>' . e($site['name']) . '</strong><small>' . e($site['url']) . '</small></a>';
            }
        }
        echo '</div></div>';
        echo '<span class="asyura-bar-spacer"></span>';
        echo '<button type="button" class="asyura-mobile-toggle" data-mobile-menu-toggle aria-label="サイドメニューを開く" aria-expanded="false"><span></span><span></span><span></span></button>';
        echo '<a class="asyura-logout" href="' . e(app_url('logout.php')) . '">ログアウト</a>';
        echo '</header>';

        echo '<div class="asyura-sidebar-overlay" data-sidebar-overlay></div>';
        echo '<aside class="asyura-sidebar" data-sidebar><nav class="asyura-nav">';

        echo self::rootLink('dashboard', '▦', 'ダッシュボード', $active, $siteQuery);
        if ($currentSite === null) {
            echo self::rootLink('sites', '＋', 'サイト登録', $active, '&new=1');
        } else {
            foreach ($groups as $group) {
                $open = in_array($active, $group['pages'], true);
                echo '<section class="asyura-nav-group' . ($open ? ' is-open' : '') . '" data-nav-group>';
                echo '<button type="button" class="asyura-nav-parent" data-nav-toggle aria-expanded="' . ($open ? 'true' : 'false') . '"><span class="asyura-nav-icon">' . e($group['icon']) . '</span><span>' . e($group['label']) . '</span><b>⌄</b></button>';
                echo '<div class="asyura-nav-children">';
                foreach ($group['items'] as [$page,$label,$itemView]) {
                    $isActive = $active === $page && ($itemView === '' || $view === $itemView || ($view === '' && $itemView === 'list'));
                    $query = $siteQuery . ($itemView !== '' ? '&view=' . rawurlencode($itemView) : '');
                    echo '<a class="asyura-nav-child' . ($isActive ? ' active' : '') . '" href="' . e(app_url('admin/?page=' . $page . $query)) . '"><span>›</span>' . e($label) . '</a>';
                }
                echo '</div></section>';
            }
            echo '<a class="asyura-nav-root asyura-nav-logout" href="' . e(app_url('logout.php')) . '"><span class="asyura-nav-icon">⇥</span><span>ログアウト</span></a>';
        }

        echo '</nav></aside>';
        echo '<main class="asyura-main"><div class="asyura-page-title"><h1>' . e($title) . '</h1>';
        if ($currentSite) echo '<span>' . e($currentSite['name']) . '</span>';
        echo '</div>';

        if (!empty($_SESSION['flash'])) {
            echo '<div class="notice ' . e($_SESSION['flash']['type'] ?? 'success') . '">' . e($_SESSION['flash']['message']) . '</div>';
            unset($_SESSION['flash']);
        }
    }

    private static function rootLink(string $page, string $icon, string $label, string $active, string $query = ''): string
    {
        return '<a class="asyura-nav-root' . ($active === $page ? ' active' : '') . '" href="' . e(app_url('admin/?page=' . $page . $query)) . '"><span class="asyura-nav-icon">' . e($icon) . '</span><span>' . e($label) . '</span></a>';
    }

    public static function footer(): void
    {
        echo '</main><script src="' . e(app_url('assets/admin.js')) . '"></script></body></html>';
    }

    public static function flash(string $message, string $type = 'success'): void
    {
        Security::startSession();
        $_SESSION['flash'] = ['message'=>$message,'type'=>$type];
    }
}
