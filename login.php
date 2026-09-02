<?php
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

use Asyura\Auth;
use Asyura\Security;

Security::headers();
Security::startSession();
if (Auth::check()) {
    redirect(app_url('admin/'));
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCsrf($_POST['csrf_token'] ?? null)) {
        $error = '画面の有効期限が切れました。';
    } else {
        $auth = new Auth($db, $config);
        if ($auth->attempt(Security::cleanText($_POST['username'] ?? '', 100), (string) ($_POST['password'] ?? ''))) {
            redirect(app_url('admin/'));
        }
        $error = 'ログイン情報が正しくないか、試行回数が上限に達しました。';
    }
}
?>
<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>ログイン ‹ 阿修羅</title><link rel="stylesheet" href="<?= e(app_url('assets/admin.css')) ?>"><link rel="stylesheet" href="<?= e(app_url('assets/admin-refined.css')) ?>"></head>
<body class="login-body"><main class="login-card"><div class="brand-mark">阿</div><h1>阿修羅</h1><p class="muted">管理画面へログイン</p>
<?php if (isset($_GET['installed'])): ?><div class="notice success">インストールが完了しました。設定した管理者アカウントでログインしてください。</div><?php endif; ?>
<?php if (isset($_GET['db_repaired'])): ?><div class="notice success">データベース接続情報を更新しました。</div><?php endif; ?>
<?php if ($error): ?><div class="notice error"><?= e($error) ?></div><?php endif; ?>
<form method="post"><?= csrf_field() ?><label>ユーザー名<input name="username" autocomplete="username" required autofocus></label><label>パスワード<input name="password" type="password" autocomplete="current-password" required></label><button class="button primary wide" type="submit">ログイン</button></form></main></body></html>
