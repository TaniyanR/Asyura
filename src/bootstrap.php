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

$configFile = ASYURA_ROOT . '/config/config.php';
$scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '/');
$basePath = rtrim(str_replace('\\', '/', dirname(dirname($scriptName))), '/');
if (basename($scriptName) === 'login.php' || basename($scriptName) === 'install.php') {
    $basePath = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
}
$installUrl = ($basePath === '' ? '' : $basePath) . '/install.php';

if (!is_file($configFile)) {
    if (basename($scriptName) !== 'install.php') {
        header('Location: ' . $installUrl);
        exit;
    }
    return;
}

$config = require $configFile;
date_default_timezone_set($config['timezone'] ?? 'Asia/Tokyo');

try {
    $db = \Asyura\Database::connect($config);
    \Asyura\Migration::upgrade($db);
} catch (Throwable $e) {
    if (basename($scriptName) !== 'install.php') {
        header('Location: ' . $installUrl . '?db=unavailable');
        exit;
    }
    return;
}

require ASYURA_ROOT . '/src/Helpers.php';
