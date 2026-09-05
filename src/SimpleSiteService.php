<?php
declare(strict_types=1);

namespace Asyura;

use PDO;

final class SimpleSiteService
{
    public function __construct(private PDO $db) {}

    /**
     * Keep one representative per normalized URL for admin navigation while
     * retaining every database row. The oldest registration remains canonical.
     *
     * @return array{visible: array<int,array>, duplicates: array<int,array>}
     */
    public static function partitionByUrl(array $sites): array
    {
        $visible = [];
        $duplicates = [];
        $seen = [];

        foreach ($sites as $site) {
            $normalized = trim((string) ($site['normalized_url'] ?? ''));
            if ($normalized === '') {
                $normalized = UrlNormalizer::normalize((string) ($site['url'] ?? ''));
            }
            $key = $normalized !== '' ? $normalized : 'site-id:' . (int) ($site['id'] ?? 0);
            if (isset($seen[$key])) {
                $site['canonical_site_id'] = $seen[$key];
                $duplicates[] = $site;
                continue;
            }
            $seen[$key] = (int) ($site['id'] ?? 0);
            $visible[] = $site;
        }

        return ['visible' => $visible, 'duplicates' => $duplicates];
    }

    public function ensureSchema(): void
    {
        $stmt = $this->db->query("SHOW COLUMNS FROM sites LIKE 'rss_url'");
        if (!$stmt->fetch()) {
            $this->db->exec("ALTER TABLE sites ADD COLUMN rss_url VARCHAR(2048) NULL AFTER url");
        }
        $stmt = $this->db->query("SHOW COLUMNS FROM sites LIKE 'search_console_property'");
        if (!$stmt->fetch()) {
            $this->db->exec("ALTER TABLE sites ADD COLUMN search_console_property VARCHAR(2048) NULL AFTER rss_url");
        }
    }

    public function save(array $data): int
    {
        $this->ensureSchema();
        $id = (int) ($data['id'] ?? 0);
        $name = Security::cleanText($data['name'] ?? '', 255);
        $url = Security::safeUrl($data['url'] ?? '');
        if ($name === '' || $url === '') throw new \InvalidArgumentException('サイト名とサイトURLを確認してください。');
        $normalizedUrl = UrlNormalizer::normalize($url);

        $rssUrl = self::optionalUrl($data['rss_url'] ?? null, 'サイトRSS');
        $loginUrl = self::optionalUrl($data['login_url'] ?? null, 'ログインURL');
        $hasSearchConsoleProperty = array_key_exists('search_console_property', $data);
        $searchConsoleProperty = $hasSearchConsoleProperty
            ? (trim((string) $data['search_console_property']) ?: null)
            : null;
        if ($hasSearchConsoleProperty && $searchConsoleProperty !== null && !str_starts_with($searchConsoleProperty, 'sc-domain:') && !filter_var($searchConsoleProperty, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('Search Consoleプロパティが正しくありません。');
        }
        $email = trim((string) ($data['admin_email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('管理メールアドレスが正しくありません。');
        $description = Security::cleanText($data['description'] ?? '', 2000) ?: null;

        if ($id > 0) {
            $current = $this->db->prepare('SELECT normalized_url FROM sites WHERE id=?');
            $current->execute([$id]);
            $currentNormalizedUrl = $current->fetchColumn();
            if ($currentNormalizedUrl === false) throw new \InvalidArgumentException('編集するサイトが見つかりません。');
            if ((string) $currentNormalizedUrl !== $normalizedUrl) {
                $this->assertUniqueUrl($normalizedUrl, $id);
            }
            if ($hasSearchConsoleProperty) {
                $stmt = $this->db->prepare('UPDATE sites SET name=?,url=?,rss_url=?,search_console_property=?,login_url=?,normalized_url=?,description=?,admin_email=? WHERE id=?');
                $stmt->execute([$name,$url,$rssUrl,$searchConsoleProperty,$loginUrl,$normalizedUrl,$description,$email ?: null,$id]);
            } else {
                $stmt = $this->db->prepare('UPDATE sites SET name=?,url=?,rss_url=?,login_url=?,normalized_url=?,description=?,admin_email=? WHERE id=?');
                $stmt->execute([$name,$url,$rssUrl,$loginUrl,$normalizedUrl,$description,$email ?: null,$id]);
            }
            return $id;
        }

        $this->db->beginTransaction();
        try {
            $this->assertUniqueUrl($normalizedUrl, 0, true);
            $stmt = $this->db->prepare('INSERT INTO sites (public_id,site_key,name,url,rss_url,search_console_property,login_url,normalized_url,description,admin_email,active,ranking_enabled,links_enabled,rss_enabled,rotation_enabled) VALUES (?,?,?,?,?,?,?,?,?,?,1,1,1,1,1)');
            $stmt->execute([Security::randomToken(8),Security::randomToken(24),$name,$url,$rssUrl,$searchConsoleProperty,$loginUrl,$normalizedUrl,$description,$email ?: null]);
            $id = (int) $this->db->lastInsertId();
            $this->createDefaultWidgets($id, $name);
            $this->db->commit();
            return $id;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function delete(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM sites WHERE id=?');
        $stmt->execute([$id]);
    }

    private static function optionalUrl(mixed $value, string $label): ?string
    {
        $value = trim((string) $value);
        if ($value === '') return null;
        $url = Security::safeUrl($value);
        if ($url === '') throw new \InvalidArgumentException($label . 'はhttp://またはhttps://から始まるURLを入力してください。');
        return $url;
    }

    private function assertUniqueUrl(string $normalizedUrl, int $excludeId = 0, bool $lock = false): void
    {
        $sql = 'SELECT id FROM sites WHERE normalized_url=?' . ($excludeId > 0 ? ' AND id<>?' : '') . ' LIMIT 1' . ($lock ? ' FOR UPDATE' : '');
        $stmt = $this->db->prepare($sql);
        $stmt->execute($excludeId > 0 ? [$normalizedUrl, $excludeId] : [$normalizedUrl]);
        if ($stmt->fetchColumn() !== false) {
            throw new \InvalidArgumentException('このサイトURLはすでに登録されています。登録済みサイトを編集してください。');
        }
    }

    private function createDefaultWidgets(int $siteId, string $siteName): void
    {
        $stmt = $this->db->prepare('INSERT INTO widgets (site_id,public_id,type,slot_code,name,item_limit,template_html,custom_css) VALUES (?,?,?,?,?,?,?,?)');
        $stmt->execute([$siteId,Security::randomToken(8),'ranking','A',$siteName . ' 逆アクセスランキング',10,'<a href="{url}" rel="nofollow">{rank}. {title}</a> <span>{in_count}</span>','.asyura-ranking{font-size:13px;background:#fff;border:1px solid #ddd;padding:8px}']);
        foreach (range('A','E') as $slot) $stmt->execute([$siteId,Security::randomToken(8),'links',$slot,"相互リンク {$slot}",20,'<a href="{url}" rel="{rel}" target="{target}">{title}</a>','.asyura-links{font-size:14px}']);
        foreach (range('A','J') as $slot) $stmt->execute([$siteId,Security::randomToken(8),'rss',$slot,"相互RSS {$slot}",10,'<article><a href="{url}">{image_tag}<span>{title}</span></a></article>','.asyura-rss article{margin:0 0 8px}.asyura-rss img{width:80px;height:60px;object-fit:cover;margin-right:8px}']);
        $stmt->execute([$siteId,Security::randomToken(8),'notices','A','お知らせ',5,'<article><time>{published_at}</time><strong>{title}</strong><p>{description}</p></article>','.asyura-notices article{padding:8px;border-bottom:1px solid #ddd}']);
    }
}
