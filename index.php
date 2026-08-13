<?php
declare(strict_types=1);

if (!is_file(__DIR__ . '/config/config.php')) {
    header('Location: install.php');
} else {
    header('Location: admin/');
}
exit;
