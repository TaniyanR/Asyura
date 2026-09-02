<?php
declare(strict_types=1);

use Asyura\Migration;
use Asyura\Security;

define('ASYURA_ROOT', __DIR__);
spl_autoload_register(static function (string $class): void {
    $prefix = 'Asyura\\';
    if (str_starts_with($class, $prefix)) {
        $path = ASYURA_ROOT . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require $path;
        }
    }
});

Security::headers();
Security::startSession();

$configPath = __DIR__ . '/config/config.php';
$installed = is_file($configPath);
$existingConfig = $installed ? (require $configPath) : [];
$dbReachable = false;
$error = '';

$scheme = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off' ? 'https' : 'http';
$requestHost = preg_replace('/[\r\n]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
if (!is_string($requestHost) || !preg_match('/^[a-zA-Z0-9.\-\[\]:]+$/', $requestHost)) {
    $requestHost = 'localhost';
}
$scriptDirectory = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/install.php')));
$scriptDirectory = $scriptDirectory === '/' || $scriptDirectory === '.' ? '' : '/' . trim($scriptDirectory, '/');
$appUrl = $scheme . '://' . $requestHost . $scriptDirectory;

if ($installed) {
    try {
        $dbCfg = $existingConfig['db'] ?? [];
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            (string) ($dbCfg['host'] ?? '127.0.0.1'),
            (int) ($dbCfg['port'] ?? 3306),
            (string) ($dbCfg['name'] ?? ''),
            (string) ($dbCfg['charset'] ?? 'utf8mb4')
        );
        $checkDb = new PDO($dsn, (string) ($dbCfg['user'] ?? ''), (string) ($dbCfg['pass'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $checkDb->query('SELECT 1');
        $dbReachable = true;
    } catch (Throwable $e) {
        $dbReachable = false;
    }
}

$repairMode = $installed && !$dbReachable;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!$installed || $repairMode)) {
    if (!Security::verifyCsrf($_POST['csrf_token'] ?? null)) {
        $error = '画面の有効期限が切れました。もう一度お試しください。';
    } else {
        $host = '127.0.0.1';
        $port = 3306;
        $name = preg_replace('/[^a-zA-Z0-9_]/', '', (string) ($_POST['db_name'] ?? 'asyura'));
        $user = Security::cleanText($_POST['db_user'] ?? '', 255);
        $pass = (string) ($_POST['db_pass'] ?? '');

        if ($name === '' || $user === '') {
            $error = 'DB名とDBユーザーを入力してください。';
        } else {
            try {
                $pdoOptions = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false];
                $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name);
                try {
                    $db = new PDO($dsn, $user, $pass, $pdoOptions);
                } catch (PDOException $e) {
                    if ($installed || (int) ($e->errorInfo[1] ?? 0) !== 1049) {
                        throw $e;
                    }
                    $serverDsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port);
                    $server = new PDO($serverDsn, $user, $pass, $pdoOptions);
                    $quotedDb = '`' . str_replace('`', '``', $name) . '`';
                    $server->exec("CREATE DATABASE {$quotedDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                    $db = new PDO($dsn, $user, $pass, $pdoOptions);
                }

                $config = $existingConfig;
                $config['app_url'] = $config['app_url'] ?? $appUrl;
                $config['app_key'] = $config['app_key'] ?? Security::randomToken(32);
                $config['timezone'] = $config['timezone'] ?? 'Asia/Tokyo';
                $config['db'] = ['host' => $host, 'port' => $port, 'name' => $name, 'user' => $user, 'pass' => $pass, 'charset' => 'utf8mb4'];
                $config['cron_key'] = $config['cron_key'] ?? Security::randomToken(24);
                $config['mail'] = $config['mail'] ?? ['from' => 'noreply@localhost', 'from_name' => '阿修羅'];

                Migration::run($db);

                $needsPasswordSetup = false;
                if (!$installed) {
                    $adminCount = (int) $db->query('SELECT COUNT(*) FROM admins')->fetchColumn();
                    if ($adminCount === 0) {
                        $temporaryPassword = Security::randomToken(48);
                        $stmt = $db->prepare('INSERT INTO admins (username, password_hash, email) VALUES (?, ?, ?)');
                        $stmt->execute(['admin', password_hash($temporaryPassword, PASSWORD_DEFAULT), null]);
                        $flag = $db->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
                        $flag->execute(['initial_password_pending', '1']);
                        $needsPasswordSetup = true;
                    }
                }

                $export = var_export($config, true);
                $contents = "<?php\ndeclare(strict_types=1);\n\nreturn {$export};\n";
                $tmp = $configPath . '.tmp';
                if (file_put_contents($tmp, $contents, LOCK_EX) === false || !rename($tmp, $configPath)) {
                    throw new RuntimeException('config/config.phpを書き込めません。');
                }
                @chmod($configPath, 0640);

                if ($repairMode) {
                    header('Location: ' . $appUrl . '/login.php?db_repaired=1');
                } elseif ($needsPasswordSetup) {
                    header('Location: ' . $appUrl . '/setup.php');
                } else {
                    header('Location: ' . $appUrl . '/login.php?installed=1');
                }
                exit;
            } catch (Throwable $e) {
                $error = $installed
                    ? 'データベースへ接続できませんでした。DB情報を確認してください。'
                    : 'インストールできませんでした。DB情報とconfigフォルダの書き込み権限を確認してください。';
            }
        }
    }
}

$defaultDbName = (string) (($existingConfig['db']['name'] ?? '') ?: 'asyura');
$defaultDbUser = (string) (($existingConfig['db']['user'] ?? '') ?: 'root');
?>
<!doctype html>
<html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>阿修羅 インストール</title><link rel="stylesheet" href="assets/admin.css"><link rel="stylesheet" href="assets/admin-refined.css"></head><body class="login-body">
<main class="install-card"><div class="brand-mark">阿</div><h1>阿修羅</h1><p class="muted">アクセス解析・相互管理ツール</p>
<?php if ($installed && $dbReachable): ?>
<div class="notice success">阿修羅はインストール済みです。<a href="login.php">ログイン画面へ</a></div>
<?php else: ?>
<?php if ($repairMode): ?><div class="notice warning">データベースへ接続できません。接続情報を確認して保存してください。既存データは初期化しません。</div><?php endif; ?>
<?php if ($error): ?><div class="notice error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<form method="post" autocomplete="off"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Security::csrfToken(), ENT_QUOTES, 'UTF-8') ?>"><h2>データベース</h2><div class="form-grid"><label>DB名<input name="db_name" value="<?= htmlspecialchars($defaultDbName, ENT_QUOTES, 'UTF-8') ?>" required></label><label>DBユーザー<input name="db_user" value="<?= htmlspecialchars($defaultDbUser, ENT_QUOTES, 'UTF-8') ?>" required></label><label class="span-2">DBパスワード<input name="db_pass" type="password" autocomplete="new-password"></label></div><p class="description"><?= $repairMode ? 'DB接続情報だけを更新します。' : 'インストール後に初回パスワードを設定します。管理者ユーザー名は admin です。' ?></p><button class="button primary" type="submit"><?= $repairMode ? 'DB接続情報を保存' : 'インストールする' ?></button></form>
<?php endif; ?></main></body></html>
