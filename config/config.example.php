<?php
declare(strict_types=1);

return [
    'app_url' => 'https://badminton.example.at', // No trailing slash. A subdirectory is supported.
    'app_key' => '', // Generate with: php bin/console.php key
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'badminton_crm',
        'username' => 'badminton_crm',
        'password' => '',
    ],
    'timezone' => 'Europe/Vienna',
    'secure_cookies' => true, // false ONLY for a local HTTP test.
    'session_idle_minutes' => 120,
    // For deployments with separate release folders, point to one shared file.
    'maintenance_file' => __DIR__ . '/../storage/maintenance.flag',
];
