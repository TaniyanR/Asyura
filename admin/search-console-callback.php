<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Asyura\Auth;
use Asyura\SearchConsoleService;
use Asyura\Security;

Auth::requireLogin($config['app_url']);
Security::startSession();

$siteId = (int)($_SESSION['search_console_return_site'] ?? 0);
$returnUrl = app_url('admin/?page=settings' . ($siteId > 0 ? '&site=' . $siteId : ''));
$expectedState = (string)($_SESSION['search_console_oauth_state'] ?? '');
$state = (string)($_GET['state'] ?? '');
unset($_SESSION['search_console_oauth_state'], $_SESSION['search_console_return_site']);

if ($expectedState === '' || $state === '' || !hash_equals($expectedState, $state)) {
    $_SESSION['flash'] = ['type'=>'error','message'=>'Google認証の確認に失敗しました。もう一度接続してください。'];
    redirect($returnUrl);
}

if (!empty($_GET['error'])) {
    $_SESSION['flash'] = ['type'=>'error','message'=>'Google認証がキャンセルされました。'];
    redirect($returnUrl);
}

$code = (string)($_GET['code'] ?? '');
if ($code === '') {
    $_SESSION['flash'] = ['type'=>'error','message'=>'Googleから認証コードを受け取れませんでした。'];
    redirect($returnUrl);
}

try {
    $service = new SearchConsoleService($db, $config);
    $service->exchangeCode($code, app_url('admin/search-console-callback.php'));
    $_SESSION['flash'] = ['type'=>'success','message'=>'Google Search Consoleと接続しました。'];
} catch (Throwable $e) {
    $_SESSION['flash'] = ['type'=>'error','message'=>$e->getMessage()];
}

redirect($returnUrl);
