<?php
declare(strict_types=1);

return [
    'app_url' => 'http://localhost/Asyura',
    'app_key' => 'replace-with-a-random-64-character-value',
    'timezone' => 'Asia/Tokyo',
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'asyura',
        'user' => 'root',
        'pass' => '',
        'charset' => 'utf8mb4',
    ],
    'cron_key' => 'replace-with-a-random-value',
    'mail' => [
        'from' => 'noreply@example.com',
        'from_name' => '阿修羅',
    ],
];
