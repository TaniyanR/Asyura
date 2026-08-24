<?php
declare(strict_types=1);

namespace Asyura;

use PDO;

final class SiteService
{
    public function __construct(private PDO $db)
    {
    }

    public function create(array $data): int
    {
        $url = Security::safeUrl($data['url'] ?? '');
        if ($url === '') {
            throw new \InvalidArgumentException('サイトURLが正しくありません。');
        }
        $name = Security::cleanText($data['name'] ?? '', 255);
        if ($name === '') {
            throw new \InvalidArgumentException('サイト名を入力してください。');
        }
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('INSERT INTO sites (public_id,site_key,name,url,login_url,github_url,normalized_url,category,description,admin_email,active,ranking_enabled,links_enabled,rss_enabled,rotation_enabled,is_priority,priority_multiplier,is_special,special_points,is_rescue,rescue_min_points,is_excluded,contact_ads_notice,contact_links_notice,contact_custom_enabled,contact_custom_text) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([
                Security::randomToken(8), Security::randomToken(24), $name, $url,
                self::optionalUrl($data['login_url'] ?? null, 'ログインURL'), self::githubUrl($data['github_url'] ?? null),
                UrlNormalizer::normalize($url),
                Security::cleanText($data['category'] ?? '', 100) ?: null,
                Security::cleanText($data['description'] ?? '', 2000) ?: null,
                filter_var($data['admin_email'] ?? '', FILTER_VALIDATE_EMAIL) ?: null,
                isset($data['active']) ? 1 : 0, isset($data['ranking_enabled']) ? 1 : 0,
                isset($data['links_enabled']) ? 1 : 0, isset($data['rss_enabled']) ? 1 : 0,
                isset($data['rotation_enabled']) ? 1 : 0, isset($data['is_priority']) ? 1 : 0,
                max(0, (float) ($data['priority_multiplier'] ?? 1.5)), isset($data['is_special']) ? 1 : 0,
                max(0, (float) ($data['special_points'] ?? 100)), isset($data['is_rescue']) ? 1 : 0,
                max(0, (float) ($data['rescue_min_points'] ?? 1)), isset($data['is_excluded']) ? 1 : 0,
                isset($data['contact_ads_notice']) ? 1 : 0, isset($data['contact_links_notice']) ? 1 : 0,
                isset($data['contact_custom_enabled']) ? 1 : 0,
                Security::cleanText($data['contact_custom_text'] ?? '', 3000) ?: null,
            ]);
            $id = (int) $this->db->lastInsertId();
            $this->createDefaultWidgets($id, $name);
            $this->db->commit();
            return $id;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function update(int $id, array $data): void
    {
        $url = Security::safeUrl($data['url'] ?? '');
        $name = Security::cleanText($data['name'] ?? '', 255);
        if ($id < 1 || $url === '' || $name === '') {
            throw new \InvalidArgumentException('サイト名とURLを確認してください。');
        }
        $stmt = $this->db->prepare('UPDATE sites SET name=?,url=?,login_url=?,github_url=?,normalized_url=?,category=?,description=?,admin_email=?,active=?,ranking_enabled=?,links_enabled=?,rss_enabled=?,rotation_enabled=?,is_priority=?,priority_multiplier=?,is_special=?,special_points=?,is_rescue=?,rescue_min_points=?,is_excluded=?,contact_ads_notice=?,contact_links_notice=?,contact_custom_enabled=?,contact_custom_text=? WHERE id=?');
        $stmt->execute([
            $name, $url, self::optionalUrl($data['login_url'] ?? null, 'ログインURL'), self::githubUrl($data['github_url'] ?? null),
            UrlNormalizer::normalize($url), Security::cleanText($data['category'] ?? '', 100) ?: null,
            Security::cleanText($data['description'] ?? '', 2000) ?: null,
            filter_var($data['admin_email'] ?? '', FILTER_VALIDATE_EMAIL) ?: null,
            isset($data['active']) ? 1 : 0, isset($data['ranking_enabled']) ? 1 : 0,
            isset($data['links_enabled']) ? 1 : 0, isset($data['rss_enabled']) ? 1 : 0,
            isset($data['rotation_enabled']) ? 1 : 0, isset($data['is_priority']) ? 1 : 0,
            max(0, (float) ($data['priority_multiplier'] ?? 1.5)), isset($data['is_special']) ? 1 : 0,
            max(0, (float) ($data['special_points'] ?? 100)), isset($data['is_rescue']) ? 1 : 0,
            max(0, (float) ($data['rescue_min_points'] ?? 1)), isset($data['is_excluded']) ? 1 : 0,
            isset($data['contact_ads_notice']) ? 1 : 0, isset($data['contact_links_notice']) ? 1 : 0,
            isset($data['contact_custom_enabled']) ? 1 : 0, Security::cleanText($data['contact_custom_text'] ?? '', 3000) ?: null, $id,
        ]);
    }

    private static function optionalUrl(mixed $value, string $label): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $url = Security::safeUrl($value);
        if ($url === '') {
            throw new \InvalidArgumentException($label . 'はhttp://またはhttps://から始まる有効なURLを入力してください。');
        }
        return $url;
    }

    private static function githubUrl(mixed $value): ?string
    {
        $url = self::optionalUrl($value, 'GitHub URL');
        if ($url === null) {
            return null;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (!in_array($host, ['github.com', 'www.github.com'], true)) {
            throw new \InvalidArgumentException('GitHub URLにはgithub.comのURLを入力してください。');
        }
        return $url;
    }

    public function deletePermanently(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM sites WHERE id = ?');
        $stmt->execute([$id]);
    }

    private function createDefaultWidgets(int $siteId, string $siteName): void
    {
        $stmt = $this->db->prepare('INSERT INTO widgets (site_id,public_id,type,slot_code,name,item_limit,template_html,custom_css) VALUES (?,?,?,?,?,?,?,?)');
        $stmt->execute([$siteId, Security::randomToken(8), 'ranking', 'A', $siteName . ' 逆アクセスランキング', 10, '<a href="{url}" rel="nofollow">{rank}. {title}</a> <span>{in_count}</span>', '.asyura-ranking{font-size:13px;background:#fff;border:1px solid #ddd;padding:8px}']);
        foreach (range('A', 'E') as $slot) {
            $stmt->execute([$siteId, Security::randomToken(8), 'links', $slot, "相互リンク {$slot}", 20, '<a href="{url}" rel="{rel}" target="{target}">{title}</a>', '.asyura-links{font-size:14px}']);
        }
        foreach (range('A', 'J') as $slot) {
            $stmt->execute([$siteId, Security::randomToken(8), 'rss', $slot, "相互RSS {$slot}", 10, '<article><a href="{url}">{image_tag}<span>{title}</span></a></article>', '.asyura-rss article{margin:0 0 8px}.asyura-rss img{width:80px;height:60px;object-fit:cover;margin-right:8px}']);
        }
        $stmt->execute([$siteId, Security::randomToken(8), 'notices', 'A', 'お知らせ', 5, '<article><time>{published_at}</time><strong>{title}</strong><p>{description}</p></article>', '.asyura-notices article{padding:8px;border-bottom:1px solid #ddd}']);
    }
}
