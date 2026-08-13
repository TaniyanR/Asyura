<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Asyura\AdminController;
use Asyura\Auth;
use Asyura\View;

Auth::requireLogin($config['app_url']);
(new AdminController($db, $config))->handle();

require __DIR__ . '/pages.php';

$page = (string) ($_GET['page'] ?? 'dashboard');
$allowed = ['dashboard','sites','analytics','ranking','links','requests','rss','rotation','notices','urls','data','settings'];
if (!in_array($page, $allowed, true)) {
    $page = 'dashboard';
}
$titles = ['dashboard'=>'ダッシュボード','sites'=>'サイト管理','analytics'=>'アクセス解析','ranking'=>'逆アクセスランキング','links'=>'相互リンク','requests'=>'相互リンク依頼','rss'=>'相互RSS','rotation'=>'過去記事再配信','notices'=>'お知らせ','urls'=>'URL統一・除外','data'=>'データ管理','settings'=>'設定'];
View::header($titles[$page], $page);
call_user_func('asyura_page_' . $page, $db, $config);
View::footer();
