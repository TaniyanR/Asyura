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
if (!is_file($configFile)) {
    if (basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'install.php') {
        header('Location: ' . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/') . '/install.php');
        exit;
    }
    return;
}

$config = require $configFile;
date_default_timezone_set($config['timezone'] ?? 'Asia/Tokyo');
$db = \Asyura\Database::connect($config);
require ASYURA_ROOT . '/src/Helpers.php';
