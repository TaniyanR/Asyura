<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Asyura\Auth;
use Asyura\SearchConsoleService;
use Asyura\Security;

Auth::requireLogin($config['app_url']);
Security::startSession();

$service = new SearchConsoleService($db, $config);
$state = Security::randomToken(24);
$_SESSION['search_console_oauth_state'] = $state;
$_SESSION['search_console_return_site'] = max(0, (int)($_GET['site'] ?? 0));
$redirectUri = app_url('admin/search-console-callback.php');

try {
    redirect($service->authorizationUrl($redirectUri, $state));
} catch (Throwable $e) {
    $_SESSION['flash'] = ['type'=>'error','message'=>$e->getMessage()];
    $siteId = (int)($_SESSION['search_console_return_site'] ?? 0);
    redirect(app_url('admin/?page=settings' . ($siteId > 0 ? '&site=' . $siteId : '')));
}
