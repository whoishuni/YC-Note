<?php
declare(strict_types=1);

// Copy to config.local.php OUTSIDE public/. Never commit real credentials.
return [
    'base_path' => '/yc_note',
    'production' => true,
    // Canonical external URL, never inferred from an untrusted Host header.
    'saber_base_url' => 'https://yckuo.com/rent/yc_note/saber',
    'database' => [
        'dsn' => 'mysql:host=localhost;dbname=yc_note;charset=utf8mb4',
        'user' => 'yc_note', 'password' => '',
    ],
    // A separate credential with SELECT on the identity view ONLY.
    'identity_database' => [
        'dsn' => 'mysql:host=localhost;dbname=rental;charset=utf8mb4',
        'user' => 'yc_note_identity_reader', 'password' => '',
    ],
    'identity_table' => 'yc_note_identity',
    'rental_includes_path' => '/home/ACCOUNT/domains/yckuo.com/public_html/rent/includes',
    'rental_bootstrap_path' => '/home/ACCOUNT/domains/yckuo.com/public_html/rent/config/bootstrap.php',
    'share_ip_blacklist' => true,
    'storage_path' => __DIR__ . '/var/blobs', // Never under the web document root.
    'max_file_bytes' => 50 * 1024 * 1024,
    'quota_bytes' => 2 * 1024 * 1024 * 1024, // Includes revision history.
    'token_lifetime_seconds' => 30 * 86400, // Pending enrollment only.
    'approved_token_idle_seconds' => 90 * 86400, // Sliding, after identity/device checks.
    'offline_lease_seconds' => 86400,
];
