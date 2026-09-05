<?php
declare(strict_types=1);

use Asyura\SearchConsoleService;

$service=new SearchConsoleService($db,$config);$service->ensureSchema();$status=$service->status();$callback=app_url('admin/search-console-callback.php');$properties=[];$propertyError='';
if($status['connected']){try{$properties=$service->listProperties();}catch(Throwable $e){$propertyError=$e->getMessage();}}
echo '<div class="panel"><h2>Googleアカウント接続（阿修羅全体で1回）</h2><div class="panel-body"><p>同じGoogleアカウントを阿修羅に1回接続し、下の一覧でサイトごとにプロパティを割り当てます。権限は読み取り専用です。</p>';
echo '<form method="post">'.csrf_field().'<input type="hidden" name="action" value="save_search_console_credentials"><label>Google OAuth クライアントID<input name="client_id" value="'.e($status['client_id']).'" autocomplete="off" required></label><label>Google OAuth クライアントシークレット<input type="password" name="client_secret" autocomplete="new-password" placeholder="'.($status['has_client_secret']?'保存済み（変更時のみ入力）':'入力してください').'"></label><label>承認済みリダイレクトURI<input value="'.e($callback).'" readonly></label><div class="actions"><button class="button primary">OAuth設定を保存</button></div></form></div></div>';
echo '<div class="panel"><h2>接続状態</h2><div class="panel-body">';
if($status['connected']){echo '<p><span class="status-pill success">接続済み</span> '.e((string)($status['connected_at']??'')).'</p><div class="actions"><a class="button" href="'.e(app_url('admin/search-console-connect.php')).'">Googleアカウントを再接続</a><form method="post">'.csrf_field().'<input type="hidden" name="action" value="disconnect_search_console"><button class="button danger">接続解除</button></form></div>';}else{echo '<p>未接続です。</p>';if($status['client_id']!==''&&$status['has_client_secret'])echo '<a class="button primary" href="'.e(app_url('admin/search-console-connect.php')).'">Googleアカウントで接続</a>';}
echo '</div></div>';
echo '<div class="panel"><h2>サイト別プロパティ設定</h2><div class="panel-body"><p>アンテナサイトなどSearch Consoleへ登録しないサイトは「使用しない」のままで構いません。</p>';
if($propertyError!=='')echo '<div class="notice error">'.e($propertyError).'</div>';
echo '<form method="post">'.csrf_field().'<input type="hidden" name="action" value="save_search_console_properties"><div class="table-wrap"><table class="wp-list"><thead><tr><th>サイト</th><th>Search Consoleプロパティ</th><th>状態</th></tr></thead><tbody>';
foreach($asyuraSites as $site){$current=(string)($site['search_console_property']??'');echo '<tr><td><strong>'.e($site['name']).'</strong><br><small>'.e($site['url']).'</small></td><td><select name="properties['.(int)$site['id'].']"><option value="">使用しない（アンテナサイト等）</option>';$seen=false;foreach($properties as $property){$value=(string)$property['siteUrl'];if($value===$current)$seen=true;echo '<option value="'.e($value).'"'.($value===$current?' selected':'').'>'.e($value).'</option>';}if($current!==''&&!$seen)echo '<option value="'.e($current).'" selected>'.e($current).'（現在設定・一覧外）</option>';echo '</select></td><td>'.($current!==''?'<span class="status-pill success">設定済み</span>':'<span class="status-pill muted">未使用</span>').'</td></tr>';}
if($asyuraSites===[])echo '<tr><td colspan="3" class="empty">登録サイトがありません。</td></tr>';
echo '</tbody></table></div><div class="actions"><button class="button primary">サイト別設定を保存</button></div></form></div></div>';
