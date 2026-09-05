<?php
declare(strict_types=1);

namespace Asyura;

final class View
{
    public static function header(
        string $title,
        string $active = 'dashboard',
        array $sites = [],
        ?array $currentSite = null
    ): void {
        Security::headers();

        $siteId = (int) ($currentSite['id'] ?? 0);
        $siteQuery = $siteId > 0 ? '&site=' . $siteId : '';
        $report = (string) ($_GET['report'] ?? 'traffic');

        $displayTitle = $currentSite
            ? $title . '｜' . (string) $currentSite['name']
            : $title;

        $accessItems = [
            ['traffic', 'アクセス数（PV・セッション・UU）'],
            ['channels', '流入経路（チャネル）'],
            ['pages', 'ページ別アクセス状況'],
            ['engagement', '直帰率・エンゲージメント率'],
            ['conversions', 'コンバージョン数（CV）'],
            ['duration', '滞在時間'],
            ['keywords', '流入キーワード'],
            ['audience', 'ユーザー属性'],
        ];

        $switcherLabel = 'サイト登録';
        if ($currentSite !== null) {
            $switcherLabel = (string) $currentSite['name'];
        } elseif (count($sites) === 1) {
            $switcherLabel = (string) $sites[0]['name'];
        } elseif ($sites !== []) {
            $switcherLabel = 'サイトを選択';
        }

        echo '<!doctype html>';
        echo '<html lang="ja">';
        echo '<head>';
        echo '<meta charset="utf-8">';
        echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<meta name="robots" content="noindex,nofollow,noarchive,nosnippet,noimageindex">';
        echo '<title>' . e($displayTitle) . ' ‹ 阿修羅</title>';
        echo '<link rel="stylesheet" href="' . e(app_url('assets/admin-shell.css')) . '">';
        echo '</head>';
        echo '<body>';

        /*
        |--------------------------------------------------------------------------
        | ヘッダー
        |--------------------------------------------------------------------------
        */
        echo '<header class="adminbar">';

        echo '<button
            type="button"
            class="mobile-nav-toggle"
            data-mobile-menu-toggle
            aria-label="メニューを開く"
            aria-expanded="false"
        >☰</button>';

        echo '<a
            class="brand"
            href="' . e(app_url('admin/?page=dashboard')) . '"
        >阿修羅</a>';

        /*
        |--------------------------------------------------------------------------
        | サイト切り替え
        |--------------------------------------------------------------------------
        */
        echo '<details class="site-switcher">';

        echo '<summary>';

        echo '<strong class="site-switcher-label">' . e($switcherLabel) . '</strong>';
        echo '<span>▼</span>';
        echo '</summary>';

        echo '<div class="site-switcher-menu">';

        echo '<a
            class="site-register"
            href="' . e(app_url('admin/?page=sites&new=1')) . '"
        >＋ サイト登録</a>';

        foreach ($sites as $site) {
            $isCurrent = $siteId === (int) $site['id'];

            echo '<a
                class="site-choice' . ($isCurrent ? ' current' : '') . '"
                href="' . e(
                    app_url(
                        'admin/?page=dashboard&site=' . (int) $site['id']
                    )
                ) . '"
            >';

            echo '<strong>' . e((string) $site['name']) . '</strong>';
            echo '<small>' . e((string) $site['url']) . '</small>';

            echo '</a>';
        }

        echo '</div>';
        echo '</details>';

        echo '<a
            class="top-logout"
            href="' . e(app_url('logout.php')) . '"
        >ログアウト</a>';

        echo '</header>';

        /*
        |--------------------------------------------------------------------------
        | スマホ用オーバーレイ
        |--------------------------------------------------------------------------
        */
        echo '<div
            class="sidebar-overlay"
            data-sidebar-overlay
        ></div>';

        /*
        |--------------------------------------------------------------------------
        | サイドバー
        |--------------------------------------------------------------------------
        */
        echo '<aside class="sidebar" data-sidebar>';
        echo '<div class="sidebar-mobile-head">';
        echo '<strong>メニュー</strong>';
        echo '<button type="button" data-mobile-menu-close aria-label="メニューを閉じる">×</button>';
        echo '</div>';
        echo '<nav class="sidebar-nav">';

        /*
        | ダッシュボード
        */
        echo self::navLink(
            'dashboard',
            '▦',
            'ダッシュボード',
            $active,
            $siteQuery
        );

        /*
        |--------------------------------------------------------------------------
        | 全体メニュー
        |--------------------------------------------------------------------------
        */
        if ($currentSite === null) {

            echo self::navLink(
                'sites',
                '＋',
                'サイト登録',
                $active,
                '&new=1'
            );

        /*
        |--------------------------------------------------------------------------
        | サイト個別メニュー
        |--------------------------------------------------------------------------
        */
        } else {

            /*
            |--------------------------------------------------------------------------
            | アクセス
            |--------------------------------------------------------------------------
            */
            $accessOpen = in_array($active, ['access', 'tracking_tag'], true);

            echo '<details
                class="nav-group"
                ' . ($accessOpen ? 'open' : '') . '
            >';

            echo '<summary>';
            echo '<span class="nav-icon">◉</span>';
            echo '<span>アクセス</span>';
            echo '<b>▾</b>';
            echo '</summary>';

            echo '<div class="nav-children">';

            foreach ($accessItems as [$key, $label]) {
                $isActive =
                    $active === 'access'
                    && $report === $key;

                echo '<a
                    class="nav-child' . ($isActive ? ' active' : '') . '"
                    href="' . e(
                        app_url(
                            'admin/?page=access'
                            . $siteQuery
                            . '&report='
                            . rawurlencode($key)
                        )
                    ) . '"
                >› ' . e($label) . '</a>';
            }

            echo '<a
                class="nav-child' . ($active === 'tracking_tag' ? ' active' : '') . '"
                href="' . e(
                    app_url(
                        'admin/?page=tracking_tag'
                        . $siteQuery
                    )
                ) . '"
            >› 計測タグ</a>';

            echo '</div>';
            echo '</details>';

            /*
            |--------------------------------------------------------------------------
            | 相互リンク
            |--------------------------------------------------------------------------
            */
            $linkOpen = in_array(
                $active,
                ['links', 'ranking'],
                true
            );

            echo '<details
                class="nav-group"
                ' . ($linkOpen ? 'open' : '') . '
            >';

            echo '<summary>';
            echo '<span class="nav-icon">↔</span>';
            echo '<span>相互リンク</span>';
            echo '<b>▾</b>';
            echo '</summary>';

            echo '<div class="nav-children">';

            echo '<a
                class="nav-child' . ($active === 'links' ? ' active' : '') . '"
                href="' . e(
                    app_url(
                        'admin/?page=links'
                        . $siteQuery
                        . '&view=new'
                    )
                ) . '"
            >› 相互リンクサイト登録</a>';

            echo '<a
                class="nav-child' . ($active === 'ranking' ? ' active' : '') . '"
                href="' . e(
                    app_url(
                        'admin/?page=ranking'
                        . $siteQuery
                    )
                ) . '"
            >› 逆アクセスランキング</a>';

            echo '</div>';
            echo '</details>';

            /*
            |--------------------------------------------------------------------------
            | 設定
            |--------------------------------------------------------------------------
            */
            $settingsOpen = in_array(
                $active,
                ['site_info', 'settings'],
                true
            );

            echo '<details
                class="nav-group"
                ' . ($settingsOpen ? 'open' : '') . '
            >';

            echo '<summary>';
            echo '<span class="nav-icon">⚙</span>';
            echo '<span>設定</span>';
            echo '<b>▾</b>';
            echo '</summary>';

            echo '<div class="nav-children">';

            echo '<a
                class="nav-child' . ($active === 'site_info' ? ' active' : '') . '"
                href="' . e(
                    app_url(
                        'admin/?page=site_info'
                        . $siteQuery
                    )
                ) . '"
            >› サイト情報</a>';

            echo '<a
                class="nav-child' . ($active === 'settings' ? ' active' : '') . '"
                href="' . e(
                    app_url(
                        'admin/?page=settings'
                        . $siteQuery
                    )
                ) . '"
            >› 個人設定</a>';

            echo '</div>';
            echo '</details>';

            /*
            |--------------------------------------------------------------------------
            | ログアウト
            |--------------------------------------------------------------------------
            */
            echo '<a
                class="nav-root logout-link"
                href="' . e(app_url('logout.php')) . '"
            >';

            echo '<span class="nav-icon">⇥</span>';
            echo '<span>ログアウト</span>';

            echo '</a>';
        }

        echo '</nav>';
        echo '</aside>';

        /*
        |--------------------------------------------------------------------------
        | メインコンテンツ
        |--------------------------------------------------------------------------
        */
        echo '<main class="content">';

        echo '<div class="page-heading">';
        echo '<h1>' . e($title) . '</h1>';
        echo '</div>';

        /*
        |--------------------------------------------------------------------------
        | フラッシュメッセージ
        |--------------------------------------------------------------------------
        */
        if (!empty($_SESSION['flash'])) {
            $type = (string) (
                $_SESSION['flash']['type']
                ?? 'success'
            );

            $message = (string) (
                $_SESSION['flash']['message']
                ?? ''
            );

            echo '<div class="notice ' . e($type) . '">';
            echo e($message);
            echo '</div>';

            unset($_SESSION['flash']);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | サイドバー通常リンク
    |--------------------------------------------------------------------------
    */
    private static function navLink(
        string $page,
        string $icon,
        string $label,
        string $active,
        string $query = ''
    ): string {
        $class = 'nav-root';

        if ($active === $page) {
            $class .= ' active';
        }

        return
            '<a class="' . e($class) . '" href="'
            . e(app_url('admin/?page=' . $page . $query))
            . '">'
            . '<span class="nav-icon">'
            . e($icon)
            . '</span>'
            . '<span>'
            . e($label)
            . '</span>'
            . '</a>';
    }

    /*
    |--------------------------------------------------------------------------
    | フッター
    |--------------------------------------------------------------------------
    */
    public static function footer(): void
    {
        echo '</main>';

        echo '<script src="'
            . e(app_url('assets/admin.js'))
            . '"></script>';

        echo '</body>';
        echo '</html>';
    }

    /*
    |--------------------------------------------------------------------------
    | フラッシュメッセージ登録
    |--------------------------------------------------------------------------
    */
    public static function flash(
        string $message,
        string $type = 'success'
    ): void {
        Security::startSession();

        $_SESSION['flash'] = [
            'message' => $message,
            'type' => $type,
        ];
    }
}
