<?php
declare(strict_types=1);

namespace Asyura;

use PDO;

final class Auth
{
    public function __construct(private PDO $db, private array $config)
    {
    }

    public function attempt(string $username, string $password): bool
    {
        Security::startSession();
        $now = time();
        $attempts = $_SESSION['login_attempts'] ?? [];
        $attempts = array_values(array_filter($attempts, static fn (int $time): bool => $time > $now - 900));
        if (count($attempts) >= 5) {
            $this->logLoginSecurity('admin_login_rate_limit', '同じセッションから15分以内に5回を超えるログイン操作を拒否しました。');
            return false;
        }

        $ipHash=Security::ipHash($this->config['app_key'],'login');
        $rate=$this->db->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip_hash=? AND attempted_at>=NOW()-INTERVAL 15 MINUTE');
        $rate->execute([$ipHash]);
        if((int)$rate->fetchColumn()>=5){$this->logLoginSecurity('admin_login_rate_limit', '同じ送信元から15分以内に5回を超えるログイン操作を拒否しました。');return false;}

        $stmt = $this->db->prepare('SELECT id, username, password_hash FROM admins WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $admin = $stmt->fetch();
        if (!$admin || !password_verify($password, $admin['password_hash'])) {
            $attempts[] = $now;
            $_SESSION['login_attempts'] = $attempts;
            $this->db->prepare('INSERT INTO login_attempts (ip_hash,username) VALUES (?,?)')->execute([$ipHash,mb_substr($username,0,100)]);
            $this->logLoginSecurity('admin_login_failed', '管理画面へのログイン失敗を検知しました。ユーザー名: '.Security::cleanText($username,100));
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['admin_id'] = (int) $admin['id'];
        $_SESSION['admin_username'] = $admin['username'];
        $_SESSION['login_attempts'] = [];
        $_SESSION['last_activity'] = time();
        $this->db->prepare('DELETE FROM login_attempts WHERE ip_hash=?')->execute([$ipHash]);
        $this->db->prepare('UPDATE admins SET last_login_at = NOW() WHERE id = ?')->execute([$admin['id']]);
        return true;
    }

    private function logLoginSecurity(string $code,string $details):void
    {
        try{(new Tracker($this->db,$this->config))->logSecurity(null,$code,'critical',$details,(string)($_SERVER['HTTP_ORIGIN']??($_SERVER['HTTP_REFERER']??'')));}catch(\Throwable $e){error_log('[Asyura auth security log] '.$e->getMessage());}
    }

    public static function check(): bool
    {
        Security::startSession();
        if(empty($_SESSION['admin_id']))return false;
        if((int)($_SESSION['last_activity']??0)<time()-7200){self::logout();return false;}
        $_SESSION['last_activity']=time();
        return true;
    }

    public static function requireLogin(string $baseUrl): void
    {
        if (!self::check()) {
            header('Location: ' . rtrim($baseUrl, '/') . '/login.php');
            exit;
        }
    }

    public static function logout(): void
    {
        Security::startSession();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', $params['secure'], $params['httponly']);
        }
        session_destroy();
    }
}
