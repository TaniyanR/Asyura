<?php
declare(strict_types=1);

namespace Asyura;

use PDO;

final class SimpleSiteService
{
    public function __construct(private PDO $db) {}

    public function ensureSchema(): void
    {
        $stmt = $this->db->query("SHOW COLUMNS FROM sites LIKE 'rss_url'");
        if (!$stmt->fetch()) {
            $this->db->exec("ALTER TABLE sites ADD COLUMN rss_url VARCHAR(2048) NULL AFTER url");
        }
    }

    public function save(array $data): int
    {
        $this->ensureSchema();
        $id = (int) ($data['id'] ?? 0);
        $name = Security::cleanText($data['name'] ?? '', 255);
        $url = Security::safeUrl($data['url'] ?? '');
        if ($name === '' || $url === '') throw new \InvalidArgumentException('サイト名とサイトURLを確認してください。');

        $rssUrl = self::optionalUrl($data['rss_url'] ?? null, 'サイトRSS');
        $loginUrl = self::optionalUrl($data['login_url'] ?? null, 'ログインURL');
        $email = trim((string) ($data['admin_email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('管理メールアドレスが正しくありません。');
        $description = Security::cleanText($data['description'] ?? '', 2000) ?: null;

        if ($id > 0) {
            $stmt = $this->db->prepare('UPDATE sites SET name=?,url=?,rss_url=?,login_url=?,normalized_url=?,description=?,admin_email=? WHERE id=?');
            $stmt->execute([$name,$url,$rssUrl,$loginUrl,UrlNormalizer::normalize($url),$description,$email ?: null,$id]);
            return $id;
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('INSERT INTO sites (public_id,site_key,name,url,rss_url,login_url,normalized_url,description,admin_email,active,ranking_enabled,links_enabled,rss_enabled,rotation_enabled) VALUES (?,?,?,?,?,?,?,?,?,1,1,1,1,1)');
            $stmt->execute([Security::randomToken(8),Security::randomToken(24),$name,$url,$rssUrl,$loginUrl,UrlNormalizer::normalize($url),$description,$email ?: null]);
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

    private function createDefaultWidgets(int $siteId, string $siteName): void
    {
        $stmt = $this->db->prepare('INSERT INTO widgets (site_id,public_id,type,slot_code,name,item_limit,template_html,custom_css) VALUES (?,?,?,?,?,?,?,?)');
        $stmt->execute([$siteId,Security::randomToken(8),'ranking','A',$siteName . ' 逆アクセスランキング',10,'<a href="{url}" rel="nofollow">{rank}. {title}</a> <span>{in_count}</span>','.asyura-ranking{font-size:13px;background:#fff;border:1px solid #ddd;padding:8px}']);
        foreach (range('A','E') as $slot) $stmt->execute([$siteId,Security::randomToken(8),'links',$slot,"相互リンク {$slot}",20,'<a href="{url}" rel="{rel}" target="{target}">{title}</a>','.asyura-links{font-size:14px}']);
        foreach (range('A','J') as $slot) $stmt->execute([$siteId,Security::randomToken(8),'rss',$slot,"相互RSS {$slot}",10,'<article><a href="{url}">{image_tag}<span>{title}</span></a></article>','.asyura-rss article{margin:0 0 8px}.asyura-rss img{width:80px;height:60px;object-fit:cover;margin-right:8px}']);
        $stmt->execute([$siteId,Security::randomToken(8),'notices','A','お知らせ',5,'<article><time>{published_at}</time><strong>{title}</strong><p>{description}</p></article>','.asyura-notices article{padding:8px;border-bottom:1px solid #ddd}']);
    }
}
