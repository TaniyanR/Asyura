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
$error = '';
$scheme = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off' ? 'https' : 'http';
$requestHost = preg_replace('/[\r\n]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
if (!is_string($requestHost) || !preg_match('/^[a-zA-Z0-9.\-\[\]:]+$/', $requestHost)) {
    $requestHost = 'localhost';
}
$scriptDirectory = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/install.php')));
$scriptDirectory = $scriptDirectory === '/' || $scriptDirectory === '.' ? '' : '/' . trim($scriptDirectory, '/');
$appUrl = $scheme . '://' . $requestHost . $scriptDirectory;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$installed) {
    if (!Security::verifyCsrf($_POST['csrf_token'] ?? null)) {
        $error = '画面の有効期限が切れました。もう一度お試しください。';
    } else {
        $host = '127.0.0.1';
        $port = 3306;
        $name = preg_replace('/[^a-zA-Z0-9_]/', '', (string) ($_POST['db_name'] ?? 'asyura'));
        $user = Security::cleanText($_POST['db_user'] ?? '', 255);
        $pass = (string) ($_POST['db_pass'] ?? '');

        if ($name === '' || $user === '') {
            $error = '必須項目を正しく入力してください。';
        } else {
            try {
                $pdoOptions = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false];
                $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name);
                try {
                    $db = new PDO($dsn, $user, $pass, $pdoOptions);
                } catch (PDOException $e) {
                    if ((int) ($e->errorInfo[1] ?? 0) !== 1049) {
                        throw $e;
                    }
                    $serverDsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port);
                    $server = new PDO($serverDsn, $user, $pass, $pdoOptions);
                    $quotedDb = '`' . str_replace('`', '``', $name) . '`';
                    $server->exec("CREATE DATABASE {$quotedDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                    $db = new PDO($dsn, $user, $pass, $pdoOptions);
                }

                $appKey = Security::randomToken(32);
                $cronKey = Security::randomToken(24);
                $config = [
                    'app_url' => $appUrl,
                    'app_key' => $appKey,
                    'timezone' => 'Asia/Tokyo',
                    'db' => ['host' => $host, 'port' => $port, 'name' => $name, 'user' => $user, 'pass' => $pass, 'charset' => 'utf8mb4'],
                    'cron_key' => $cronKey,
                    'mail' => ['from' => 'noreply@localhost', 'from_name' => '阿修羅'],
                ];

                Migration::run($db);
                $stmt = $db->prepare('INSERT INTO admins (username, password_hash, email) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash),email=VALUES(email)');
                $stmt->execute(['admin', password_hash('password', PASSWORD_DEFAULT), null]);

                $export = var_export($config, true);
                $contents = "<?php\ndeclare(strict_types=1);\n\nreturn {$export};\n";
                $tmp = $configPath . '.tmp';
                if (file_put_contents($tmp, $contents, LOCK_EX) === false || !rename($tmp, $configPath)) {
                    throw new RuntimeException('config/config.phpを書き込めません。configフォルダの書き込み権限を確認してください。');
                }
                @chmod($configPath, 0640);
                header('Location: ' . $appUrl . '/login.php?installed=1');
                exit;
            } catch (Throwable $e) {
                $error = 'インストールできませんでした。DB情報とconfigフォルダの書き込み権限を確認してください。';
            }
        }
    }
}
?>
<!doctype html>
<html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>阿修羅 インストール</title>
<link rel="stylesheet" href="assets/admin.css"></head><body class="login-body">
<main class="install-card"><h1>阿修羅</h1><p class="muted">アクセス解析・相互管理ツール</p>
<?php if ($installed): ?><div class="notice warning">インストール済みです。<a href="login.php">ログイン画面へ</a></div>
<?php else: ?>
<?php if ($error): ?><div class="notice error"><?= $error ?></div><?php endif; ?>
<form method="post" autocomplete="off"><?= '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(Security::csrfToken(), ENT_QUOTES, 'UTF-8') . '">' ?>
<h2>データベース</h2><div class="form-grid">
<label>DB名<input name="db_name" value="asyura" required></label><label>DBユーザー<input name="db_user" value="root" required></label>
<label class="span-2">DBパスワード<input name="db_pass" type="password"></label></div>
<div class="notice info">初期ログインは <strong>admin / password</strong> です。ログイン後に変更できます。</div>
<button class="button primary" type="submit">インストールする</button></form><?php endif; ?></main></body></html>
