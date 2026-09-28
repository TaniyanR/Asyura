<?php
declare(strict_types=1);
namespace Asyura;

use PDO;
use InvalidArgumentException;

final class ConfigRecovery
{
    /** Read-only validation: recovery must never create an empty replacement database. */
    public static function validate(PDO $db, bool $acceptNewKey): void
    {
        try {
            $admins = (int)$db->query('SELECT COUNT(*) FROM admins')->fetchColumn();
            $db->query('SELECT id FROM sites LIMIT 1');
        } catch (\PDOException $e) {
            throw new InvalidArgumentException('既存の阿修羅DBを確認できません。以前使用していたDB名を確認してください。');
        }
        if ($admins < 1) {
            throw new InvalidArgumentException('既存の管理者が見つかりません。以前使用していたDBを指定してください。');
        }
        if (!$acceptNewKey) {
            throw new InvalidArgumentException('元の設定ファイルがない場合は「Google認証を再設定する」にチェックしてください。');
        }
    }
}
