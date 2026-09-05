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
$redirectUri = app_url('admin/search-console-callback.php');

try {
    redirect($service->authorizationUrl($redirectUri, $state));
} catch (Throwable $e) {
    $_SESSION['flash'] = ['type'=>'error','message'=>$e->getMessage()];
    redirect(app_url('admin/?page=search_console'));
}
