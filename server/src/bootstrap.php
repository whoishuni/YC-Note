<?php
declare(strict_types=1);
require_once __DIR__ . '/Service.php';
require_once __DIR__ . '/SecurityBridge.php';
if (!function_exists('csp_nonce')) {
    function csp_nonce(): string { static $nonce; return $nonce ??= bin2hex(random_bytes(16)); }
}

function yc_service(): YcNote\Service
{
    $file = getenv('YC_NOTE_CONFIG') ?: dirname(__DIR__) . '/config.local.php';
    if (!is_file($file)) {
        throw new RuntimeException('YC Note configuration is missing.');
    }
    $config = require $file;
    YcNote\SecurityBridge::load($config);
    $connect = static function (array $c): PDO {
        $pdo = new PDO($c['dsn'], $c['user'] ?? null, $c['password'] ?? null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $pdo->exec('PRAGMA busy_timeout=10000');
        }
        return $pdo;
    };
    $identity = $connect($config['identity_database']);
    if ($identity->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $identity->exec('SET SESSION TRANSACTION READ ONLY');
    } else if ($identity->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $identity->exec('PRAGMA query_only=ON');
    }
    return new YcNote\Service($connect($config['database']), $identity, $config);
}
