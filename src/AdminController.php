<?php
declare(strict_types=1);

namespace Asyura;

use PDO;

final class AdminController
{
    public function __construct(private PDO $db, private array $config)
    {
    }

    public function handle(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }
        if (!Security::verifyCsrf($_POST['csrf_token'] ?? null)) {
            View::flash('画面の有効期限が切れました。もう一度お試しください。', 'error');
            redirect(app_url('admin/?page=' . urlencode((string) ($_GET['page'] ?? 'dashboard'))));
        }

        $action = (string) ($_POST['action'] ?? '');
        try {
            match ($action) {
                'save_site' => $this->saveSite(),
                'delete_site' => $this->deleteSite(),
                'save_feed' => $this->saveFeed(),
                'delete_feed' => $this->deleteById('rss_feeds'),
                'save_link' => $this->saveLink(),
                'delete_link' => $this->deleteById('reciprocal_links'),
                'save_widget' => $this->saveWidget(),
                'save_notice' => $this->saveNotice(),
                'delete_notice' => $this->deleteById('notices'),
                'save_alias' => $this->saveAlias(),
                'delete_alias' => $this->deleteById('site_aliases'),
                'save_excluded_referrer' => $this->saveExcludedReferrer(),
                'delete_excluded_referrer' => $this->deleteById('excluded_referrers'),
                'save_rotation' => $this->saveRotation(),
                'delete_rotation' => $this->deleteById('rotation_feeds'),
                'update_request' => $this->updateRequest(),
                'save_settings' => $this->saveSettings(),
                'change_password' => $this->changePassword(),
                default => throw new \InvalidArgumentException('操作が正しくありません。'),
            };
            View::flash('保存しました。');
        } catch (\InvalidArgumentException $e) {
            View::flash($e->getMessage(), 'error');
        } catch (\Throwable $e) {
            error_log('[Asyura admin] '.$e->getMessage());
            View::flash('処理中にエラーが発生しました。入力内容とサーバーログを確認してください。', 'error');
        }
        redirect(app_url('admin/?page=' . urlencode((string) ($_GET['page'] ?? 'dashboard'))));
    }

    private function saveSite(): void
    {
        $service = new SiteService($this->db);
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $service->update($id, $_POST);
        } else {
            $service->create($_POST);
        }
    }

    private function deleteSite(): void
    {
        if (($_POST['confirm_delete'] ?? '') !== 'yes') {
            throw new \InvalidArgumentException('「はい、削除します」を選択していないため、削除しませんでした。');
        }
        (new SiteService($this->db))->deletePermanently((int) ($_POST['id'] ?? 0));
    }

    private function saveFeed(): void
    {
        $url = Security::safeUrl($_POST['feed_url'] ?? '');
        if ($url === '') {
            throw new \InvalidArgumentException('RSS URLが正しくありません。');
        }
        $id = (int) ($_POST['id'] ?? 0);
        $values = [(int) $_POST['site_id'], Security::cleanText($_POST['name'] ?? '', 255), $url, isset($_POST['active']) ? 1 : 0];
        if ($id > 0) {
            $stmt = $this->db->prepare('UPDATE rss_feeds SET site_id=?,name=?,feed_url=?,active=? WHERE id=?');
            $values[] = $id;
        } else {
            $stmt = $this->db->prepare('INSERT INTO rss_feeds (site_id,name,feed_url,active) VALUES (?,?,?,?)');
        }
        $stmt->execute($values);
    }

    private function saveLink(): void
    {
        $url = Security::safeUrl($_POST['partner_url'] ?? '');
        if ($url === '') {
            throw new \InvalidArgumentException('相互リンク先URLが正しくありません。');
        }
        $slots = array_values(array_intersect((array) ($_POST['slots'] ?? []), range('A', 'E')));
        $id = (int) ($_POST['id'] ?? 0);
        $values = [
            (int) $_POST['site_id'], Security::cleanText($_POST['partner_name'] ?? '', 255), $url,
            UrlNormalizer::normalize($url), Security::cleanText($_POST['description'] ?? '', 2000) ?: null,
            Security::cleanText($_POST['category'] ?? '', 100) ?: null, implode(',', $slots),
            in_array($_POST['status'] ?? '', ['pending','approved','paused','rejected','removed'], true) ? $_POST['status'] : 'pending',
            in_array($_POST['rel_type'] ?? '', ['follow','nofollow','sponsored','ugc'], true) ? $_POST['rel_type'] : 'follow',
            isset($_POST['open_new_tab']) ? 1 : 0, isset($_POST['is_priority']) ? 1 : 0,
            isset($_POST['is_special']) ? 1 : 0, isset($_POST['is_rescue']) ? 1 : 0, isset($_POST['is_excluded']) ? 1 : 0,
        ];
        if ($id > 0) {
            $stmt = $this->db->prepare('UPDATE reciprocal_links SET site_id=?,partner_name=?,partner_url=?,normalized_url=?,description=?,category=?,slots=?,status=?,rel_type=?,open_new_tab=?,is_priority=?,is_special=?,is_rescue=?,is_excluded=? WHERE id=?');
            $values[] = $id;
        } else {
            $stmt = $this->db->prepare('INSERT INTO reciprocal_links (site_id,partner_name,partner_url,normalized_url,description,category,slots,status,rel_type,open_new_tab,is_priority,is_special,is_rescue,is_excluded) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        }
        $stmt->execute($values);
    }

    private function saveWidget(): void
    {
        $id = (int) ($_POST['id'] ?? 0);
        $feedIds=array_values(array_filter(array_map('intval',(array)($_POST['feed_ids']??[])),static fn(int $id):bool=>$id>0));
        $configJson=json_encode(['image_required'=>isset($_POST['image_required']),'feed_ids'=>$feedIds],JSON_UNESCAPED_UNICODE);
        $stmt = $this->db->prepare('UPDATE widgets SET name=?,enabled=?,item_limit=?,width=?,height=?,template_html=?,custom_css=?,config_json=? WHERE id=?');
        $stmt->execute([
            Security::cleanText($_POST['name'] ?? '', 255), isset($_POST['enabled']) ? 1 : 0,
            min(100, max(1, (int) ($_POST['item_limit'] ?? 10))),
            Security::cleanText($_POST['width'] ?? '100%', 30), Security::cleanText($_POST['height'] ?? 'auto', 30),
            $this->sanitizeTemplate((string) ($_POST['template_html'] ?? '')),
            $this->sanitizeCss((string) ($_POST['custom_css'] ?? '')), $configJson, $id,
        ]);
    }

    private function saveNotice(): void
    {
        $siteId = (int) ($_POST['site_id'] ?? 0) ?: null;
        $stmt = $this->db->prepare('INSERT INTO notices (site_id,title,body,notice_type,is_public,is_pinned,starts_at,ends_at) VALUES (?,?,?,?,?,?,?,?)');
        $stmt->execute([
            $siteId, Security::cleanText($_POST['title'] ?? '', 255), Security::cleanText($_POST['body'] ?? '', 10000),
            in_array($_POST['notice_type'] ?? '', ['normal','important','maintenance','registered','removed'], true) ? $_POST['notice_type'] : 'normal',
            isset($_POST['is_public']) ? 1 : 0, isset($_POST['is_pinned']) ? 1 : 0,
            $this->dateOrNull($_POST['starts_at'] ?? ''), $this->dateOrNull($_POST['ends_at'] ?? ''),
        ]);
    }

    private function saveAlias(): void
    {
        $url = Security::safeUrl($_POST['alias_url'] ?? '');
        if ($url === '') {
            throw new \InvalidArgumentException('別URLが正しくありません。');
        }
        $type = in_array($_POST['match_type'] ?? '', ['host','prefix','contains','exact'], true) ? $_POST['match_type'] : 'host';
        $stmt = $this->db->prepare('INSERT INTO site_aliases (site_id,alias_url,normalized_url,match_type,allow_tracking_origin) VALUES (?,?,?,?,?)');
        $stmt->execute([(int) $_POST['site_id'], $url, UrlNormalizer::normalize($url), $type, isset($_POST['allow_tracking_origin'])?1:0]);
    }

    private function saveRotation(): void
    {
        $intervals = [10,20,30,60,180,360,720,1440];
        $interval = (int) ($_POST['interval_minutes'] ?? 60);
        if (!in_array($interval, $intervals, true)) {
            $interval = 60;
        }
        $slug = trim(preg_replace('/[^a-z0-9-]/', '-', strtolower((string) ($_POST['slug'] ?? 'random-post'))), '-');
        if ($slug === '') {
            $slug = 'random-post';
        }
        $feedIds=array_values(array_filter(array_map('intval',(array)($_POST['feed_ids']??[])),static fn(int $id):bool=>$id>0));
        $stmt = $this->db->prepare('INSERT INTO rotation_feeds (site_id,slug,category,interval_minutes,image_required,feed_ids_json,active) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE interval_minutes=VALUES(interval_minutes),image_required=VALUES(image_required),feed_ids_json=VALUES(feed_ids_json),active=VALUES(active)');
        $stmt->execute([(int) $_POST['site_id'], $slug, Security::cleanText($_POST['category'] ?? '', 255), $interval, isset($_POST['image_required']) ? 1 : 0, json_encode($feedIds), isset($_POST['active']) ? 1 : 0]);
    }

    private function saveExcludedReferrer(): void
    {
        $pattern=Security::cleanText($_POST['pattern']??'',500);if($pattern==='')throw new \InvalidArgumentException('除外パターンを入力してください。');$type=in_array($_POST['match_type']??'',['host','contains','exact'],true)?$_POST['match_type']:'host';$this->db->prepare('INSERT INTO excluded_referrers (label,pattern,match_type,active) VALUES (?,?,?,1)')->execute([Security::cleanText($_POST['label']??'',255)?:$pattern,$pattern,$type]);
    }

    private function updateRequest(): void
    {
        $status = in_array($_POST['status'] ?? '', ['new','reviewing','approved','rejected','registered','removed'], true) ? $_POST['status'] : 'new';
        $stmt = $this->db->prepare('UPDATE link_requests SET status=?,public_message=?,removal_reason=?,notify_status_page=?,notify_email=?,publish_notice=? WHERE id=?');
        $stmt->execute([$status, Security::cleanText($_POST['public_message'] ?? '', 3000) ?: null, Security::cleanText($_POST['removal_reason'] ?? '', 3000) ?: null, isset($_POST['notify_status_page']) ? 1 : 0, isset($_POST['notify_email']) ? 1 : 0, isset($_POST['publish_notice']) ? 1 : 0, (int) $_POST['id']]);
        $q=$this->db->prepare('SELECT r.*,s.name target_name,s.id target_id FROM link_requests r JOIN sites s ON s.id=r.site_id WHERE r.id=?');$q->execute([(int)$_POST['id']]);$request=$q->fetch();
        $labels=['new'=>'受付完了','reviewing'=>'確認中','approved'=>'承認','rejected'=>'見送り','registered'=>'登録完了','removed'=>'解除完了'];
        if($request&&$request['notify_email']){$subject='[阿修羅] '.$labels[$status].' - '.$request['receipt_no'];$body=$request['site_name']." 様\n\n相互リンク依頼の状態が「".$labels[$status]."」に更新されました。\n";if($request['public_message'])$body.="\n".$request['public_message']."\n";if($status==='removed'&&$request['removal_reason'])$body.="\n解除理由：".$request['removal_reason']."\n";$from=str_replace(["\r","\n"],'',(string)($this->config['mail']['from']??'noreply@localhost'));@mail($request['email'],$subject,$body,'From: '.$from."\r\nContent-Type: text/plain; charset=UTF-8");}
        if($request&&$request['publish_notice']&&in_array($status,['registered','removed'],true)){$title=$status==='registered'?'相互リンク登録完了':'相互リンク解除完了';$body=$request['site_name'].'：'.$labels[$status];$this->db->prepare('INSERT INTO notices (site_id,title,body,notice_type,is_public) VALUES (?,?,?,?,1)')->execute([$request['target_id'],$title,$body,$status==='registered'?'registered':'removed']);}
    }

    private function saveSettings(): void
    {
        $allowed = [
            'ranking_period_days' => [1, 30], 'distribution_window_hours' => [1, 168],
            'raw_retention_days' => [30, 3650], 'distribution_retention_days' => [30, 3650],
            'aggregate_retention_days' => [365, 3650], 'rss_item_retention_days' => [1, 30],
        ];
        $stmt = $this->db->prepare('INSERT INTO settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
        foreach ($allowed as $key => [$min, $max]) {
            $stmt->execute([$key, (string) min($max, max($min, (int) ($_POST[$key] ?? $min)))]);
        }
    }

    private function changePassword(): void
    {
        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        $username=Security::cleanText($_POST['new_username']??'',100);
        if($username==='')throw new \InvalidArgumentException('ユーザー名を入力してください。');
        if ($new!==''&&strlen($new) < 10) {
            throw new \InvalidArgumentException('新しいパスワードは10文字以上にしてください。');
        }
        $stmt = $this->db->prepare('SELECT password_hash FROM admins WHERE id = ?');
        $stmt->execute([(int) $_SESSION['admin_id']]);
        if (!password_verify($current, (string) $stmt->fetchColumn())) {
            throw new \InvalidArgumentException('現在のパスワードが違います。');
        }
        if($new!=='')$this->db->prepare('UPDATE admins SET username=?,password_hash=? WHERE id=?')->execute([$username,password_hash($new, PASSWORD_DEFAULT), (int) $_SESSION['admin_id']]);else $this->db->prepare('UPDATE admins SET username=? WHERE id=?')->execute([$username,(int) $_SESSION['admin_id']]);$_SESSION['admin_username']=$username;
    }

    private function deleteById(string $table): void
    {
        $allowed = ['rss_feeds','reciprocal_links','notices','site_aliases','rotation_feeds','excluded_referrers'];
        if (!in_array($table, $allowed, true)) {
            throw new \InvalidArgumentException('削除対象が正しくありません。');
        }
        $this->db->prepare("DELETE FROM {$table} WHERE id = ?")->execute([(int) ($_POST['id'] ?? 0)]);
    }

    private function sanitizeTemplate(string $html): string
    {
        $html = preg_replace('~<\s*(script|iframe|object|embed|form)[^>]*>.*?<\s*/\s*\1\s*>~is', '', $html);
        $html = preg_replace('~</?\s*(script|iframe|object|embed|form|link|meta|base)[^>]*>~i', '', $html);
        $html = preg_replace('/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
        $html = preg_replace('/javascript\s*:/i', '', $html);
        return mb_substr($html, 0, 30000);
    }

    private function sanitizeCss(string $css): string
    {
        $css = preg_replace('/@import|expression\s*\(|javascript\s*:|behavior\s*:/i', '', $css);
        return mb_substr($css, 0, 30000);
    }

    private function dateOrNull(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        $time = strtotime($value);
        return $time === false ? null : date('Y-m-d H:i:s', $time);
    }
}
