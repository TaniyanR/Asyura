<?php
declare(strict_types=1);

if(!isset($asyuraCurrentSite)||!is_array($asyuraCurrentSite)){echo '<div class="empty-state">サイトが選択されていません。</div>';return;}
$siteId=(int)$asyuraCurrentSite['id'];$q=$db->prepare('SELECT * FROM sites WHERE id=?');$q->execute([$siteId]);$site=$q->fetch()?:$asyuraCurrentSite;
$contactUrl=app_url('public/contact.php?site='.rawurlencode((string)$site['public_id']));
echo '<div class="notice info"><strong>確認結果：</strong>現在のお問い合わせ機能は、一般問い合わせではなく「相互リンク申請フォーム」です。CAPTCHA・ハニーポット・同一送信元の回数制限を備えています。</div>';
echo '<div class="panel"><h2>お問い合わせフォーム</h2><div class="panel-body"><label>公開URL<input value="'.e($contactUrl).'" readonly></label><div class="actions"><a class="button primary" href="'.e($contactUrl).'" target="_blank" rel="noopener">フォームを確認</a><a class="button" href="'.e(app_url('admin/?page=requests&site='.$siteId)).'">受信一覧を開く</a></div></div></div>';
echo '<div class="panel"><h2>フォーム上部の案内</h2><div class="panel-body"><form method="post">'.csrf_field().'<input type="hidden" name="action" value="save_contact_settings"><input type="hidden" name="context_site_id" value="'.$siteId.'"><div class="checks"><label><input type="checkbox" name="contact_ads_notice" '.(!empty($site['contact_ads_notice'])?'checked':'').'>広告は募集していない旨を表示</label><label><input type="checkbox" name="contact_links_notice" '.(!empty($site['contact_links_notice'])?'checked':'').'>広告・相互リンクは募集していない旨を表示</label><label><input type="checkbox" name="contact_custom_enabled" '.(!empty($site['contact_custom_enabled'])?'checked':'').'>自由入力文を表示</label></div><label>自由入力文<textarea name="contact_custom_text" rows="5">'.e($site['contact_custom_text']??'').'</textarea></label><button class="button primary">保存</button></form></div></div>';
