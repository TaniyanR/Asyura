<?php
declare(strict_types=1);
namespace Asyura;

use PDO;
use InvalidArgumentException;

final class ConfigRecovery
{
    public static function isExisting(PDO $db): bool
    {
        // Only a completely empty, explicitly supplied database is a first installation.
        $tables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        if ($tables === []) return false;
        self::validate($db);
        return true;
    }

    /** Read-only validation: recovery must never create an empty replacement database. */
    public static function validate(PDO $db): void
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

    }
}
