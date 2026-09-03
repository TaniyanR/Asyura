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
        $report = (string) ($_GET['report'] ?? '');
        $displayTitle = $currentSite ? $title . '｜' . (string) $currentSite['name'] : $title;

        echo '<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow,noarchive,nosnippet,noimageindex"><title>' . e($displayTitle) . ' ‹ 阿修羅</title>';
        echo '<link rel="stylesheet" href="' . e(app_url('assets/admin-shell.css')) . '"></head><body>';

        echo '<header class="adminbar">';
        echo '<button type="button" class="mobile-nav-toggle" data-mobile-menu-toggle aria-label="メニューを開く" aria-expanded="false">☰</button>';
        echo '<a class="brand" href="' . e(app_url('admin/?page=dashboard')) . '">阿修羅</a>';
        echo '<details class="site-switcher">';
        echo '<summary>' . ($currentSite ? e((string) $currentSite['name']) : 'サイト登録') . '<span>▼</span></summary>';
        echo '<div class="site-switcher-menu">';
        echo '<a class="site-register" href="' . e(app_url('admin/?page=sites&new=1')) . '">＋ サイト登録</a>';
        foreach ($sites as $site) {
            echo '<a class="site-choice' . ($siteId === (int) $site['id'] ? ' current' : '') . '" href="' . e(app_url('admin/?page=dashboard&site=' . (int) $site['id'])) . '">';
            echo '<strong>' . e((string) $site['name']) . '</strong><small>' . e((string) $site['url']) . '</small></a>';
        }
        echo '</div></details>';
        echo '<a class="top-logout" href="' . e(app_url('logout.php')) . '">ログアウト</a>';
        echo '</header>';

        echo '<div class="sidebar-overlay" data-sidebar-overlay></div>';
        echo '<aside class="sidebar" data-sidebar><nav class="sidebar-nav">';

        echo self::navLink('dashboard', '▦', 'ダッシュボード', $active, $siteQuery);

        if ($currentSite === null) {
            echo self::navLink('sites', '＋', 'サイト登録', $active, '&new=1');
        } else {
            $accessOpen = $active === 'access';
            echo '<details class="nav-group"' . ($accessOpen ? ' open' : '') . '><summary><span class="nav-icon">◉</span><span>アクセス</span><b>▾</b></summary><div class="nav-children">';
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
            foreach ($accessItems as [$key, $label]) {
                $isActive = $active === 'access' && (($report === '' && $key === 'traffic') || $report === $key);
                echo '<a class="nav-child' . ($isActive ? ' active' : '') . '" href="' . e(app_url('admin/?page=access' . $siteQuery . '&report=' . $key)) . '">› ' . e($label) . '</a>';
            }
            echo '</div></details>';

            $linkOpen = in_array($active, ['links','ranking'], true);
            echo '<details class="nav-group"' . ($linkOpen ? ' open' : '') . '><summary><span class="nav-icon">↔</span><span>相互リンク</span><b>▾</b></summary><div class="nav-children">';
            echo '<a class="nav-child' . ($active === 'links' ? ' active' : '') . '" href="' . e(app_url('admin/?page=links' . $siteQuery . '&view=new')) . '">› 相互リンクサイト登録</a>';
            echo '<a class="nav-child' . ($active === 'ranking' ? ' active' : '') . '" href="' . e(app_url('admin/?page=ranking' . $siteQuery)) . '">› 逆アクセスランキング</a>';
            echo '</div></details>';

            $settingsOpen = in_array($active, ['site_info','settings'], true);
            echo '<details class="nav-group"' . ($settingsOpen ? ' open' : '') . '><summary><span class="nav-icon">⚙</span><span>設定</span><b>▾</b></summary><div class="nav-children">';
            echo '<a class="nav-child' . ($active === 'site_info' ? ' active' : '') . '" href="' . e(app_url('admin/?page=site_info' . $siteQuery)) . '">› サイト情報</a>';
            echo '<a class="nav-child' . ($active === 'settings' ? ' active' : '') . '" href="' . e(app_url('admin/?page=settings' . $siteQuery)) . '">› 個人設定</a>';
            echo '</div></details>';

            echo '<a class="nav-root logout-link" href="' . e(app_url('logout.php')) . '"><span class="nav-icon">⇥</span><span>ログアウト</span></a>';
        }

        echo '</nav></aside>';
        echo '<main class="content"><div class="page-heading"><h1>' . e($title) . '</h1></div>';

        if (!empty($_SESSION['flash'])) {
            echo '<div class="notice ' . e($_SESSION['flash']['type'] ?? 'success') . '">' . e($_SESSION['flash']['message']) . '</div>';
            unset($_SESSION['flash']);
        }
    }

    private static function navLink(string $page, string $icon, string $label, string $active, string $query = ''): string
    {
        return '<a class="nav-root' . ($active === $page ? ' active' : '') . '" href="' . e(app_url('admin/?page=' . $page . $query)) . '"><span class="nav-icon">' . e($icon) . '</span><span>' . e($label) . '</span></a>';
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
