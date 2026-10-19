<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';
yc_service()->migrate();
echo "YC Note schema ready. Identity database was not changed.\n";
