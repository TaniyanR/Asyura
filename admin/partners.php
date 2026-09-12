<?php
declare(strict_types=1);

function asyura_health_datetime(?string $value): string
{
    return $value ? date('Y/m/d H:i', strtotime($value)) : '未確認';
}

function asyura_site_health_badge(array $link): string
{
    $status=(string)($link['site_check_status']??'');$http=(int)($link['site_http_status']??0);
    [$class,$label]=match($status){
        'ok'=>['success','正常'],
        'redirected'=>['info','正常（転送あり）'],
        'restricted'=>['warning','存在確認・閲覧制限'],
        'missing'=>['danger','ページなし'],
        'error'=>['danger','接続エラー'],
        default=>['muted','未確認'],
    };
    return '<span class="health-badge '.$class.'">'.e($label.($http>0?'（HTTP '.$http.'）':'')).'</span>';
}

function asyura_rss_health(array $feed, bool $isFetchTarget = true): array
{
    $latest=(string)($feed['latest_article_at']??'');$lastSuccess=(string)($feed['last_success_at']??'');$error=trim((string)($feed['last_error']??''));
    if(!$isFetchTarget)return['muted','取得対象外'];
    if(empty($feed['active']))return['muted','停止中'];
    if($error!==''&&$lastSuccess==='')return['danger','取得エラー'];
    if($latest==='')return[$error!==''?'danger':'warning',$error!==''?'取得エラー':'記事未取得'];
    $days=max(0,(int)floor((time()-strtotime($latest))/86400));
    if($days>=30)return['danger','30日以上更新なし'];
    if($days>=7)return['warning','7日以上更新なし'];
    if($error!=='')return['warning','前回取得エラー'];
    return['success','更新中'];
}

$siteId=asyura_require_current_site();if(!$siteId)return;
$stmt=$db->prepare('SELECT l.*,
    (SELECT COUNT(*) FROM rss_feeds f WHERE f.site_id=l.site_id AND f.reciprocal_link_id=l.id) feed_count,
    (SELECT COUNT(*) FROM article_archive a JOIN rss_feeds f2 ON f2.id=a.feed_id AND f2.site_id=a.site_id WHERE f2.site_id=l.site_id AND f2.reciprocal_link_id=l.id) article_count,
    (SELECT MAX(COALESCE(a.original_published_at,a.first_seen_at)) FROM article_archive a JOIN rss_feeds f2 ON f2.id=a.feed_id AND f2.site_id=a.site_id WHERE f2.site_id=l.site_id AND f2.reciprocal_link_id=l.id) latest_article_at
    FROM reciprocal_links l WHERE l.site_id=? ORDER BY l.partner_name,l.id');
$stmt->execute([$siteId]);$links=$stmt->fetchAll();
$feedStmt=$db->prepare('SELECT f.*,
    (SELECT COUNT(*) FROM article_archive a WHERE a.site_id=f.site_id AND a.feed_id=f.id) article_count,
    (SELECT MAX(COALESCE(a.original_published_at,a.first_seen_at)) FROM article_archive a WHERE a.site_id=f.site_id AND a.feed_id=f.id) latest_article_at
    FROM rss_feeds f WHERE f.site_id=? AND f.reciprocal_link_id=? ORDER BY f.id');
$labels=asyura_allocation_labels();

echo '<div class="section-intro reciprocal-health-intro"><div><h2>'.e(asyura_current_site()['name']).'の相互リンク一覧</h2><p>相手サイトが表示できるか、RSSを取得できるか、記事更新が続いているかを確認できます。</p></div><span class="count-badge">'.count($links).'件</span></div>';
echo '<div class="notice info"><strong>自動確認：</strong>サイトの存在確認は12時間ごと、RSS取得は約25分ごとにcronで行います。「今すぐ確認」では、そのサイトと登録済みRSSをすぐに再確認します。</div>';
if(!$links){echo '<div class="empty-state"><strong>相互リンク先はまだ登録されていません。</strong><p>「相互リンクサイト登録」から追加してください。</p></div>';return;}
echo '<div class="partner-health-list">';
foreach($links as$link){
    $feedStmt->execute([$siteId,(int)$link['id']]);$feeds=$feedStmt->fetchAll();$hasWarning=false;
    $isFetchTarget=(!empty($link['reciprocal_link_enabled'])||!empty($link['reciprocal_rss_enabled']))&&empty($link['is_excluded'])&&$link['status']==='approved'&&$link['allocation_type']!=='excluded';
    foreach($feeds as$feed){[$feedClass]=asyura_rss_health($feed,$isFetchTarget);if(in_array($feedClass,['warning','danger'],true))$hasWarning=true;}
    if(in_array((string)($link['site_check_status']??''),['missing','error'],true))$hasWarning=true;
    $features=[];if(!empty($link['is_excluded']))$features[]='除外中';if(!empty($link['reciprocal_link_enabled']))$features[]='相互リンク';if(!empty($link['reciprocal_rss_enabled']))$features[]='相互RSS';
    echo '<article class="partner-health-card'.($hasWarning?' has-warning':'').'">';
    echo '<header><div><h3>'.e($link['partner_name']).'</h3><a href="'.e($link['partner_url']).'" target="_blank" rel="noopener noreferrer">'.e($link['partner_url']).'</a></div>'.asyura_site_health_badge($link).'</header>';
    $latestLabel=!empty($link['latest_article_at'])?asyura_health_datetime($link['latest_article_at']):(empty($link['article_count'])?'記事未取得':'記事日時なし');
    echo '<div class="partner-health-summary"><div><span>利用機能</span><strong>'.e($features?implode('・',$features):'停止中').'</strong></div><div><span>登録状態</span><strong>'.e(['pending'=>'確認中','approved'=>'登録完了','paused'=>'一時停止','rejected'=>'見送り','removed'=>'解除'][$link['status']]??$link['status']).'</strong></div><div><span>変換率</span><strong>'.e($labels[$link['allocation_type']][0]??$link['allocation_type']).'</strong></div><div><span>サイト最終確認</span><strong>'.e(asyura_health_datetime($link['site_checked_at']??null)).'</strong></div><div><span>最新記事</span><strong>'.e($latestLabel).'</strong></div></div>';
    if(!empty($link['site_check_error']))echo '<p class="health-error">'.e($link['site_check_error']).'</p>';
    echo '<div class="partner-health-actions"><form method="post"><input type="hidden" name="action" value="check_reciprocal_health"><input type="hidden" name="id" value="'.(int)$link['id'].'">'.asyura_context_field().csrf_field().'<button class="button primary">今すぐ確認</button></form><a class="button" href="'.e(app_url('admin/?page=links&site='.$siteId.'&edit='.(int)$link['id'])).'">設定を編集</a></div>';
    echo '<details class="partner-feed-details"'.($hasWarning?' open':'').'><summary>登録RSS（'.count($feeds).'件）を確認</summary>';
    if(!$feeds)echo '<p class="empty">RSSは登録されていません。</p>';else{echo '<div class="table-wrap"><table class="wp-list responsive-table"><thead><tr><th>RSS</th><th>状態</th><th>最新記事</th><th>最終取得成功</th><th>取得結果</th></tr></thead><tbody>';foreach($feeds as$feed){[$feedClass,$feedLabel]=asyura_rss_health($feed,$isFetchTarget);$feedLatest=!empty($feed['latest_article_at'])?asyura_health_datetime($feed['latest_article_at']):(empty($feed['article_count'])?'記事未取得':'記事日時なし');echo '<tr><td data-label="RSS"><strong>'.e($feed['name']).'</strong><br><small>'.e($feed['feed_url']).'</small></td><td data-label="状態"><span class="health-badge '.$feedClass.'">'.e($feedLabel).'</span></td><td data-label="最新記事">'.e($feedLatest).'</td><td data-label="最終取得成功">'.e(asyura_health_datetime($feed['last_success_at']??null)).'</td><td data-label="取得結果">'.(!empty($feed['last_error'])?'<span class="health-error-inline">'.e($feed['last_error']).'</span>':'正常').'</td></tr>';}echo '</tbody></table></div>';}
    echo '</details></article>';
}
echo '</div>';
