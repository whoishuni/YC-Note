<?php
declare(strict_types=1);
$ycRoot = getenv('YC_NOTE_SERVER_ROOT')
    ?: (is_file(__DIR__ . '/.local-root.php') ? require __DIR__ . '/.local-root.php' : dirname(__DIR__));
require_once $ycRoot . '/src/bootstrap.php';
