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
        $report = (string) ($_GET['report'] ?? 'traffic');
        $displayTitle = $currentSite ? $title . '｜' . (string) $currentSite['name'] : $title;

        $accessItems = [
            ['traffic','アクセス数（PV・セッション・UU）'],
            ['channels','流入経路（チャネル）'],
            ['pages','ページ別アクセス状況'],
            ['engagement','直帰率・エンゲージメント率'],
            ['conversions','コンバージョン数（CV）'],
            ['duration','滞在時間'],
            ['keywords','流入キーワード'],
            ['audience','ユーザー属性'],
        ];

        echo '<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow,noarchive,nosnippet,noimageindex"><title>' . e($displayTitle) . ' ‹ 阿修羅</title>';
        echo '<link rel="stylesheet" href="' . e(app_url('assets/admin.css')) . '"><link rel="stylesheet" href="' . e(app_url('assets/admin-clean.css')) . '"></head><body>';

        echo '<header class="asyura-bar">';
        echo '<a class="asyura-logo" href="' . e(app_url('admin/?page=dashboard')) . '">阿修羅</a>';
        echo '<details class="asyura-site-select">';
        echo '<summary class="asyura-site-button"><span>' . ($currentSite ? e($currentSite['name']) : 'サイト登録') . '</span><b>⌄</b></summary>';
        echo '<div class="asyura-site-dropdown">';
        echo '<a class="asyura-register-link" href="' . e(app_url('admin/?page=sites&new=1')) . '">＋ サイト登録</a>';
        if ($sites !== []) {
            echo '<div class="asyura-site-separator"></div>';
            foreach ($sites as $site) {
                echo '<a class="asyura-site-option' . ($siteId === (int)$site['id'] ? ' is-current' : '') . '" href="' . e(app_url('admin/?page=dashboard&site=' . (int)$site['id'])) . '"><strong>' . e($site['name']) . '</strong><small>' . e($site['url']) . '</small></a>';
            }
        }
        echo '</div></details><span class="asyura-bar-spacer"></span>';
        echo '<button type="button" class="asyura-mobile-toggle" data-mobile-menu-toggle aria-label="サイドメニューを開く" aria-expanded="false"><span></span><span></span><span></span></button>';
        echo '<a class="asyura-logout" href="' . e(app_url('logout.php')) . '">ログアウト</a></header>';

        echo '<div class="asyura-sidebar-overlay" data-sidebar-overlay></div><aside class="asyura-sidebar" data-sidebar><nav class="asyura-nav">';
        echo self::rootLink('dashboard','▦','ダッシュボード',$active,$siteQuery);

        if ($currentSite === null) {
            echo self::rootLink('sites','＋','サイト登録',$active,'&new=1');
        } else {
            echo '<details class="asyura-nav-group"' . ($active === 'access' ? ' open' : '') . '><summary class="asyura-nav-parent"><span class="asyura-nav-icon">◉</span><span>アクセス</span><b>⌄</b></summary><div class="asyura-nav-children">';
            foreach ($accessItems as [$key,$label]) {
                echo '<a class="asyura-nav-child' . ($active === 'access' && $report === $key ? ' active' : '') . '" href="' . e(app_url('admin/?page=access&site=' . $siteId . '&report=' . $key)) . '"><span>›</span>' . e($label) . '</a>';
            }
            echo '</div></details>';

            echo '<details class="asyura-nav-group"' . (in_array($active,['links','ranking'],true) ? ' open' : '') . '><summary class="asyura-nav-parent"><span class="asyura-nav-icon">↔</span><span>相互リンク</span><b>⌄</b></summary><div class="asyura-nav-children">';
            echo '<a class="asyura-nav-child' . ($active === 'links' ? ' active' : '') . '" href="' . e(app_url('admin/?page=links&site=' . $siteId . '&view=new')) . '"><span>›</span>相互リンクサイト登録</a>';
            echo '<a class="asyura-nav-child' . ($active === 'ranking' ? ' active' : '') . '" href="' . e(app_url('admin/?page=ranking&site=' . $siteId)) . '"><span>›</span>逆アクセスランキング</a>';
            echo '</div></details>';

            echo '<details class="asyura-nav-group"' . (in_array($active,['site_info','settings'],true) ? ' open' : '') . '><summary class="asyura-nav-parent"><span class="asyura-nav-icon">⚙</span><span>設定</span><b>⌄</b></summary><div class="asyura-nav-children">';
            echo '<a class="asyura-nav-child' . ($active === 'site_info' ? ' active' : '') . '" href="' . e(app_url('admin/?page=site_info&site=' . $siteId)) . '"><span>›</span>サイト情報</a>';
            echo '<a class="asyura-nav-child' . ($active === 'settings' ? ' active' : '') . '" href="' . e(app_url('admin/?page=settings&site=' . $siteId)) . '"><span>›</span>個人設定</a>';
            echo '</div></details>';

            echo '<a class="asyura-nav-root asyura-nav-logout" href="' . e(app_url('logout.php')) . '"><span class="asyura-nav-icon">⇥</span><span>ログアウト</span></a>';
        }

        echo '</nav></aside><main class="asyura-main"><div class="asyura-page-title"><h1>' . e($title) . '</h1>';
        if ($currentSite) echo '<span>' . e($currentSite['name']) . '</span>';
        echo '</div>';
        if (!empty($_SESSION['flash'])) {
            echo '<div class="notice ' . e($_SESSION['flash']['type'] ?? 'success') . '">' . e($_SESSION['flash']['message']) . '</div>';
            unset($_SESSION['flash']);
        }
    }

    private static function rootLink(string $page,string $icon,string $label,string $active,string $query=''): string
    {
        return '<a class="asyura-nav-root' . ($active === $page ? ' active' : '') . '" href="' . e(app_url('admin/?page=' . $page . $query)) . '"><span class="asyura-nav-icon">' . e($icon) . '</span><span>' . e($label) . '</span></a>';
    }

    public static function footer(): void
    {
        echo '</main><script src="' . e(app_url('assets/admin.js')) . '"></script></body></html>';
    }

    public static function flash(string $message,string $type='success'): void
    {
        Security::startSession();
        $_SESSION['flash']=['message'=>$message,'type'=>$type];
    }
}
