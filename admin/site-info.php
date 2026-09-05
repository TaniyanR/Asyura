<?php
declare(strict_types=1);

use Asyura\SimpleSiteService;

if (!isset($asyuraCurrentSite) || !is_array($asyuraCurrentSite)) {
    echo '<div class="empty-state"><strong>サイトが選択されていません。</strong></div>';return;
}
(new SimpleSiteService($db))->ensureSchema();
$stmt=$db->prepare('SELECT * FROM sites WHERE id=?');$stmt->execute([(int)$asyuraCurrentSite['id']]);$site=$stmt->fetch()?:$asyuraCurrentSite;
echo '<div class="simple-site-form panel"><h2>サイト情報</h2><div class="panel-body">';
echo '<p class="description">登録項目は、このサイトの基本情報6項目だけです。</p>';
echo '<form method="post">'.csrf_field().'<input type="hidden" name="action" value="save_site_info"><input type="hidden" name="context_site_id" value="'.(int)$site['id'].'"><div class="form-grid">';
echo '<label>サイト名<input name="name" value="'.e($site['name']).'" required></label>';
echo '<label>サイトURL<input type="url" name="url" value="'.e($site['url']).'" required></label>';
echo '<label>サイトRSS<input type="url" name="rss_url" value="'.e($site['rss_url']??'').'"></label>';
echo '<label>ログインURL<input type="url" name="login_url" value="'.e($site['login_url']??'').'"></label>';
echo '<label class="span-2">管理メールアドレス<input type="email" name="admin_email" value="'.e($site['admin_email']??'').'"></label>';
echo '<label class="span-2">説明<textarea name="description" rows="6">'.e($site['description']??'').'</textarea></label></div>';
echo '<div class="actions"><button class="button primary" type="submit">保存</button></div></form></div></div>';
