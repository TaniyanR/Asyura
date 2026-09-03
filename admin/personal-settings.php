<?php
declare(strict_types=1);

use Asyura\SearchConsoleService;
use Asyura\Security;
use Asyura\View;

$service = new SearchConsoleService($db, $config);
$service->ensureSchema();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCsrf($_POST['csrf_token'] ?? null)) {
        View::flash('画面の有効期限が切れました。もう一度お試しください。', 'error');
        redirect(app_url('admin/?page=settings' . (!empty($asyuraCurrentSite['id']) ? '&site=' . (int)$asyuraCurrentSite['id'] : '')));
    }
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'save_search_console_credentials') {
            $secret = trim((string)($_POST['client_secret'] ?? ''));
            $service->saveCredentials((string)($_POST['client_id'] ?? ''), $secret !== '' ? $secret : null);
            View::flash('Google OAuth設定を保存しました。');
        } elseif ($action === 'disconnect_search_console') {
            $service->disconnect();
            View::flash('Google Search Consoleとの接続を解除しました。');
        }
    } catch (Throwable $e) {
        View::flash($e->getMessage(), 'error');
    }
    redirect(app_url('admin/?page=settings' . (!empty($asyuraCurrentSite['id']) ? '&site=' . (int)$asyuraCurrentSite['id'] : '')));
}

$status = $service->status();
$callback = app_url('admin/search-console-callback.php');
$siteQuery = !empty($asyuraCurrentSite['id']) ? '?site=' . (int)$asyuraCurrentSite['id'] : '';

echo '<div class="panel"><h2>Google Search Console</h2><div class="panel-body">';
echo '<p>Googleアカウントとの接続は阿修羅全体で1回だけ行います。接続後、各サイトの「サイト情報」でSearch Consoleプロパティを選択します。</p>';
echo '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="save_search_console_credentials">';
echo '<label>Google OAuth クライアントID<input name="client_id" value="' . e($status['client_id']) . '" autocomplete="off" required></label>';
echo '<label>Google OAuth クライアントシークレット<input type="password" name="client_secret" value="" autocomplete="new-password" placeholder="' . ($status['has_client_secret'] ? '保存済み（変更する場合のみ入力）' : 'クライアントシークレットを入力') . '"></label>';
echo '<label>承認済みのリダイレクトURI<input value="' . e($callback) . '" readonly></label>';
echo '<p class="description">Google Cloud側のOAuth 2.0クライアントに、上記リダイレクトURIをそのまま登録してください。読み取り専用権限で接続します。</p>';
echo '<div class="actions"><button type="submit" class="button primary">OAuth設定を保存</button></div></form>';
echo '</div></div>';

echo '<div class="panel"><h2>接続状態</h2><div class="panel-body">';
if ($status['connected']) {
    echo '<p><strong>接続済み</strong>' . (!empty($status['connected_at']) ? '　' . e((string)$status['connected_at']) : '') . '</p>';
    try {
        $properties = $service->listProperties();
        echo '<p>利用可能なSearch Consoleプロパティ：<strong>' . number_format(count($properties)) . '</strong> 件</p>';
    } catch (Throwable $e) {
        echo '<div class="notice error">' . e($e->getMessage()) . '</div>';
    }
    echo '<div class="actions"><a class="button" href="' . e(app_url('admin/search-console-connect.php' . $siteQuery)) . '">Googleアカウントを再接続</a>';
    echo '<form method="post" style="display:inline">' . csrf_field() . '<input type="hidden" name="action" value="disconnect_search_console"><button type="submit" class="button danger">接続解除</button></form></div>';
} else {
    echo '<p>まだGoogle Search Consoleに接続されていません。</p>';
    if ($status['client_id'] !== '' && $status['has_client_secret']) {
        echo '<a class="button primary" href="' . e(app_url('admin/search-console-connect.php' . $siteQuery)) . '">Googleアカウントで接続</a>';
    } else {
        echo '<p class="description">先に上のOAuth設定を保存してください。</p>';
    }
}
echo '</div></div>';
