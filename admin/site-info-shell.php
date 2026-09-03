<?php
declare(strict_types=1);

use Asyura\Security;
use Asyura\SimpleSiteService;
use Asyura\View;

if (!isset($asyuraCurrentSite) || !is_array($asyuraCurrentSite)) {
    echo '<div class="empty-state"><strong>サイトが選択されていません。</strong></div>';
    return;
}

$service = new SimpleSiteService($db);
$service->ensureSchema();
$site = $asyuraCurrentSite;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_site_info_shell') {
    if (!Security::verifyCsrf($_POST['csrf_token'] ?? null)) {
        View::flash('画面の有効期限が切れました。もう一度お試しください。', 'error');
    } else {
        try {
            $_POST['id'] = (int) $site['id'];
            $service->save($_POST);
            View::flash('サイト情報を保存しました。');
            redirect(app_url('admin/?page=site_info&site=' . (int) $site['id']));
        } catch (Throwable $e) {
            View::flash($e instanceof InvalidArgumentException ? $e->getMessage() : '保存中にエラーが発生しました。', 'error');
        }
    }
}

$stmt = $db->prepare('SELECT * FROM sites WHERE id=?');
$stmt->execute([(int) $site['id']]);
$site = $stmt->fetch() ?: $site;

echo '<div class="panel"><h2>サイト情報</h2><div class="panel-body">';
echo '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="save_site_info_shell">';
echo '<div class="form-grid">';
echo '<label>サイト名<input name="name" value="' . e((string) $site['name']) . '" required></label>';
echo '<label>サイトURL<input type="url" name="url" value="' . e((string) $site['url']) . '" required></label>';
echo '<label>サイトRSS<input type="url" name="rss_url" value="' . e((string) ($site['rss_url'] ?? '')) . '"></label>';
echo '<label>ログインURL<input type="url" name="login_url" value="' . e((string) ($site['login_url'] ?? '')) . '"></label>';
echo '<label class="span-2">管理メールアドレス<input type="email" name="admin_email" value="' . e((string) ($site['admin_email'] ?? '')) . '"></label>';
echo '<label class="span-2">説明<textarea name="description" rows="6">' . e((string) ($site['description'] ?? '')) . '</textarea></label>';
echo '</div><div class="actions"><button class="button primary" type="submit">保存</button></div></form>';
echo '</div></div>';
