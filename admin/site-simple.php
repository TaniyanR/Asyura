<?php
declare(strict_types=1);

use Asyura\Security;
use Asyura\SimpleSiteService;
use Asyura\View;

$service = new SimpleSiteService($db);
$service->ensureSchema();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCsrf($_POST['csrf_token'] ?? null)) {
        View::flash('画面の有効期限が切れました。もう一度お試しください。', 'error');
    } else {
        try {
            if (($_POST['action'] ?? '') === 'delete_site_simple') {
                if (($_POST['confirm_delete'] ?? '') !== 'yes') throw new InvalidArgumentException('削除確認で「はい」を選択してください。');
                $service->delete((int) ($_POST['id'] ?? 0));
                View::flash('サイトを削除しました。');
                redirect(app_url('admin/?page=dashboard'));
            }
            $id = $service->save($_POST);
            View::flash('サイト情報を保存しました。');
            redirect(app_url('admin/?page=dashboard&site=' . $id));
        } catch (Throwable $e) {
            View::flash($e instanceof InvalidArgumentException ? $e->getMessage() : '保存中にエラーが発生しました。', 'error');
        }
    }
}

$sites = $db->query('SELECT * FROM sites ORDER BY id')->fetchAll();
$editId = (int) ($_GET['edit'] ?? 0);
$site = null;
if ($editId > 0) {
    $stmt = $db->prepare('SELECT * FROM sites WHERE id=?');
    $stmt->execute([$editId]);
    $site = $stmt->fetch() ?: null;
}

View::header('サイト登録', 'sites', $sites, null);
$site = $site ?: ['id'=>0,'name'=>'','url'=>'','rss_url'=>'','login_url'=>'','admin_email'=>'','description'=>''];

echo '<div class="panel simple-site-form"><h2>基本情報</h2><div class="panel-body">';
echo '<form method="post">' . csrf_field() . '<input type="hidden" name="id" value="' . (int) $site['id'] . '"><div class="form-grid">';
echo '<label>サイト名<input name="name" value="' . e($site['name']) . '" required></label>';
echo '<label>サイトURL<input type="url" name="url" value="' . e($site['url']) . '" placeholder="https://example.com/" required></label>';
echo '<label>サイトRSS<input type="url" name="rss_url" value="' . e($site['rss_url'] ?? '') . '" placeholder="https://example.com/feed/"></label>';
echo '<label>ログインURL<input type="url" name="login_url" value="' . e($site['login_url'] ?? '') . '" placeholder="https://example.com/admin/"></label>';
echo '<label class="span-2">管理メールアドレス<input type="email" name="admin_email" value="' . e($site['admin_email'] ?? '') . '"></label>';
echo '<label class="span-2">説明<textarea name="description" rows="6">' . e($site['description'] ?? '') . '</textarea></label>';
echo '</div><div class="actions"><button class="button primary" type="submit">保存</button><a class="button" href="' . e(app_url('admin/?page=dashboard')) . '">戻る</a></div></form></div></div>';

if ((int) $site['id'] > 0) {
    echo '<div class="danger-zone" style="max-width:960px"><h2>サイトを完全削除</h2><p>関連データも削除され、元に戻せません。</p><form method="post" data-confirm="本当にサイトを削除しますか？">' . csrf_field() . '<input type="hidden" name="action" value="delete_site_simple"><input type="hidden" name="id" value="' . (int) $site['id'] . '"><label><input type="radio" name="confirm_delete" value="no" checked>いいえ</label><label><input type="radio" name="confirm_delete" value="yes">はい</label><button class="button danger" type="submit">削除</button></form></div>';
}

echo '<section class="registered-sites panel">';
echo '<h2>登録済みサイト</h2>';
echo '<div class="panel-body">';

if ($sites === []) {
    echo '<p class="registered-sites-empty">登録済みのサイトはありません。</p>';
} else {
    echo '<div class="registered-site-list">';
    foreach ($sites as $registeredSite) {
        $registeredSiteId = (int) $registeredSite['id'];
        echo '<article class="registered-site-card">';
        echo '<div class="registered-site-main">';
        echo '<strong>' . e((string) $registeredSite['name']) . '</strong>';
        echo '<a href="' . e((string) $registeredSite['url']) . '" target="_blank" rel="noopener noreferrer">' . e((string) $registeredSite['url']) . '</a>';
        echo '</div>';
        echo '<div class="registered-site-actions">';
        echo '<a class="button primary" href="' . e(app_url('admin/?page=dashboard&site=' . $registeredSiteId)) . '">管理画面</a>';
        echo '<a class="button" href="' . e(app_url('admin/?page=sites&edit=' . $registeredSiteId)) . '">編集</a>';
        echo '</div>';
        echo '</article>';
    }
    echo '</div>';
}

echo '</div>';
echo '</section>';

View::footer();
