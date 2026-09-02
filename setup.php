<?php
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

use Asyura\Security;

Security::headers();
Security::startSession();

$pendingStmt = $db->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
$pendingStmt->execute(['initial_password_pending']);
if ($pendingStmt->fetchColumn() !== '1') {
    header('Location: ' . app_url('login.php'));
    exit;
}

$admin = $db->query('SELECT id, username FROM admins ORDER BY id ASC LIMIT 1')->fetch();
if (!$admin) {
    header('Location: ' . app_url('install.php'));
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCsrf($_POST['csrf_token'] ?? null)) {
        $error = '画面の有効期限が切れました。もう一度お試しください。';
    } else {
        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['password_confirm'] ?? '');
        if (strlen($password) < 10) {
            $error = 'パスワードは10文字以上で設定してください。';
        } elseif ($password !== $confirm) {
            $error = 'パスワード確認が一致しません。';
        } else {
            $db->beginTransaction();
            try {
                $stmt = $db->prepare('UPDATE admins SET password_hash = ? WHERE id = ?');
                $stmt->execute([password_hash($password, PASSWORD_DEFAULT), (int) $admin['id']]);
                $flag = $db->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
                $flag->execute(['initial_password_pending', '0']);
                $db->commit();
                header('Location: ' . app_url('login.php?password_set=1'));
                exit;
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $error = 'パスワードを設定できませんでした。もう一度お試しください。';
            }
        }
    }
}
?>
<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>初回パスワード設定 ‹ 阿修羅</title><link rel="stylesheet" href="assets/admin.css"><link rel="stylesheet" href="assets/admin-refined.css"></head>
<body class="login-body"><main class="login-card"><div class="brand-mark">阿</div><h1>阿修羅</h1><p class="muted">初回パスワード設定</p><p class="description">ユーザー名は <strong><?= htmlspecialchars((string) $admin['username'], ENT_QUOTES, 'UTF-8') ?></strong> です。最初にパスワードだけ設定してください。</p>
<?php if ($error): ?><div class="notice error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<form method="post" autocomplete="off"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Security::csrfToken(), ENT_QUOTES, 'UTF-8') ?>"><label>パスワード<input type="password" name="password" minlength="10" autocomplete="new-password" required autofocus></label><label>パスワード確認<input type="password" name="password_confirm" minlength="10" autocomplete="new-password" required></label><button class="button primary wide" type="submit">パスワードを設定する</button></form></main></body></html>
