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
$configExists = is_file($configPath);
$currentConfig = $configExists ? require $configPath : [];
$dbAvailable = false;
$error = '';
$scheme = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off' ? 'https' : 'http';
$requestHost = preg_replace('/[\r\n]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
if (!is_string($requestHost) || !preg_match('/^[a-zA-Z0-9.\-\[\]:]+$/', $requestHost)) {
    $requestHost = 'localhost';
}
$scriptDirectory = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/install.php')));
$scriptDirectory = $scriptDirectory === '/' || $scriptDirectory === '.' ? '' : '/' . trim($scriptDirectory, '/');
$appUrl = $scheme . '://' . $requestHost . $scriptDirectory;

if ($configExists && isset($currentConfig['db']) && is_array($currentConfig['db'])) {
    try {
        $dbConfig = $currentConfig['db'];
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $dbConfig['host'] ?? '127.0.0.1',
            (int) ($dbConfig['port'] ?? 3306),
            $dbConfig['name'] ?? '',
            $dbConfig['charset'] ?? 'utf8mb4'
        );
        $checkDb = new PDO($dsn, (string) ($dbConfig['user'] ?? ''), (string) ($dbConfig['pass'] ?? ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $dbAvailable = true;
    } catch (Throwable $e) {
        $dbAvailable = false;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$dbAvailable) {
    if (!Security::verifyCsrf($_POST['csrf_token'] ?? null)) {
        $error = '画面の有効期限が切れました。もう一度お試しください。';
    } else {
        $host = '127.0.0.1';
        $port = 3306;
        $name = preg_replace('/[^a-zA-Z0-9_]/', '', (string) ($_POST['db_name'] ?? 'asyura'));
        $user = Security::cleanText($_POST['db_user'] ?? '', 255);
        $pass = (string) ($_POST['db_pass'] ?? '');
        $adminUser = Security::cleanText($_POST['admin_user'] ?? '', 100);
        $adminPass = (string) ($_POST['admin_pass'] ?? '');
        $adminPassConfirm = (string) ($_POST['admin_pass_confirm'] ?? '');

        if ($name === '' || $user === '' || $adminUser === '' || $adminPass === '') {
            $error = 'DB情報と管理者情報をすべて入力してください。';
        } elseif (strlen($adminPass) < 10) {
            $error = '管理者パスワードは10文字以上で設定してください。';
        } elseif ($adminPass !== $adminPassConfirm) {
            $error = '管理者パスワードの確認入力が一致しません。';
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

                $appKey = (string) ($currentConfig['app_key'] ?? Security::randomToken(32));
                $cronKey = (string) ($currentConfig['cron_key'] ?? Security::randomToken(24));
                $config = [
                    'app_url' => $appUrl,
                    'app_key' => $appKey,
                    'timezone' => $currentConfig['timezone'] ?? 'Asia/Tokyo',
                    'db' => ['host' => $host, 'port' => $port, 'name' => $name, 'user' => $user, 'pass' => $pass, 'charset' => 'utf8mb4'],
                    'cron_key' => $cronKey,
                    'mail' => $currentConfig['mail'] ?? ['from' => 'noreply@localhost', 'from_name' => '阿修羅'],
                ];

                Migration::run($db);
                $stmt = $db->prepare('SELECT id FROM admins WHERE username=? LIMIT 1');
                $stmt->execute([$adminUser]);
                $adminId = (int) $stmt->fetchColumn();
                if ($adminId > 0) {
                    $stmt = $db->prepare('UPDATE admins SET password_hash=? WHERE id=?');
                    $stmt->execute([password_hash($adminPass, PASSWORD_DEFAULT), $adminId]);
                } else {
                    $stmt = $db->prepare('INSERT INTO admins (username, password_hash, email) VALUES (?, ?, ?)');
                    $stmt->execute([$adminUser, password_hash($adminPass, PASSWORD_DEFAULT), null]);
                }

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
                $error = '接続・設定できませんでした。DB情報とconfigフォルダの書き込み権限を確認してください。';
            }
        }
    }
}
?>
<!doctype html>
<html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>阿修羅 インストール</title>
<link rel="stylesheet" href="assets/admin.css"></head><body class="login-body">
<main class="install-card"><div class="brand-mark">阿</div><h1>阿修羅</h1><p class="muted">アクセス解析・相互管理ツール</p>
<?php if ($dbAvailable): ?><div class="notice success">設定済みで、データベースにも正常に接続できています。<a href="login.php">ログイン画面へ</a></div>
<?php else: ?>
<?php if ($configExists || isset($_GET['db'])): ?><div class="notice warning">データベースに接続できません。接続情報を確認して再設定してください。</div><?php endif; ?>
<?php if ($error): ?><div class="notice error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<form method="post" autocomplete="off"><?= '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(Security::csrfToken(), ENT_QUOTES, 'UTF-8') . '">' ?>
<h2>データベース</h2><div class="form-grid">
<label>DB名<input name="db_name" value="<?= htmlspecialchars((string) ($currentConfig['db']['name'] ?? 'asyura'), ENT_QUOTES, 'UTF-8') ?>" required></label><label>DBユーザー<input name="db_user" value="<?= htmlspecialchars((string) ($currentConfig['db']['user'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required></label>
<label class="span-2">DBパスワード<input name="db_pass" type="password" autocomplete="new-password"></label></div>
<h2>管理者アカウント</h2><div class="form-grid">
<label class="span-2">ユーザー名<input name="admin_user" autocomplete="username" required></label>
<label>パスワード<input name="admin_pass" type="password" minlength="10" autocomplete="new-password" required></label>
<label>パスワード確認<input name="admin_pass_confirm" type="password" minlength="10" autocomplete="new-password" required></label>
</div>
<p class="description">管理者のユーザー名とパスワードはここで設定します。固定の初期パスワードは使用しません。</p>
<button class="button primary" type="submit">設定して開始する</button></form><?php endif; ?></main></body></html>
