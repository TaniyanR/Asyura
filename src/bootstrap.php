<?php
declare(strict_types=1);

define('ASYURA_ROOT', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    $prefix = 'Asyura\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = ASYURA_ROOT . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

$scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/'));
$scriptDir = str_replace('\\', '/', dirname($scriptName));
$knownSubdirs = ['admin', 'api', 'cron', 'feed', 'public'];
if (in_array(basename($scriptDir), $knownSubdirs, true)) {
    $scriptDir = str_replace('\\', '/', dirname($scriptDir));
}
$basePath = $scriptDir === '/' || $scriptDir === '.' ? '' : rtrim($scriptDir, '/');
$installUrl = $basePath . '/install.php';

$configFile = ASYURA_ROOT . '/config/config.php';
if (!is_file($configFile)) {
    if (basename($scriptName) !== 'install.php') {
        header('Location: ' . $installUrl, true, 302);
        exit;
    }
    return;
}

$config = require $configFile;
date_default_timezone_set($config['timezone'] ?? 'Asia/Tokyo');

try {
    $db = \Asyura\Database::connect($config);
    \Asyura\Migration::upgrade($db);
} catch (\Throwable $e) {
    if (basename($scriptName) !== 'install.php') {
        header('Location: ' . $installUrl . '?db_error=1', true, 302);
        exit;
    }
    throw $e;
}

require ASYURA_ROOT . '/src/Helpers.php';
