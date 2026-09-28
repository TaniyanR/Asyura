<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/ConfigRecovery.php';
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); echo "OK $message\n"; }
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
function rejected(PDO $db, bool $consent): bool {
    try { Asyura\ConfigRecovery::validate($db, $consent); return false; }
    catch (InvalidArgumentException) { return true; }
}
check(rejected($db, true), 'empty database rejected without creating tables');
check((int)$db->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table'")->fetchColumn() === 0, 'no schema written');
$db->exec('CREATE TABLE admins(id INTEGER, password_hash TEXT); CREATE TABLE sites(id INTEGER, name TEXT)');
check(rejected($db, true), 'database without existing administrator rejected');
$db->exec("INSERT INTO admins VALUES(1,'existing-hash'); INSERT INTO sites VALUES(1,'existing-site')");
check(rejected($db, false), 'new key requires explicit acknowledgement');
Asyura\ConfigRecovery::validate($db, true);
check($db->query('SELECT password_hash FROM admins')->fetchColumn() === 'existing-hash', 'existing login preserved');
check($db->query('SELECT name FROM sites')->fetchColumn() === 'existing-site', 'existing site preserved');
echo "Config recovery tests passed\n";
