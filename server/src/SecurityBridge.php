<?php
declare(strict_types=1);
namespace YcNote;

/** Reuse the rental application's security engines without loading its routes,
 * session, controllers, database bootstrap, or business services. */
final class SecurityBridge
{
    public static function load(array $config): void
    {
        $path = $config['rental_includes_path'] ?? dirname(__DIR__) . '/security/rental';
        if (!empty($config['rental_bootstrap_path'])) require_once $config['rental_bootstrap_path'];
        foreach (['DictionaryDetector.php','SecurityPipeline.php','HttpSecurityPolicy.php','security.php','csrf.php','GeoBlocker.php','UploadSecurityScanner.php','MaintenanceMode.php'] as $file) {
            if (!is_file($path . '/' . $file)) throw new \RuntimeException('Shared security module is unavailable.');
            require_once $path . '/' . $file;
        }
    }

    public static function maintenance(Service $service, string $role = ''): void
    {
        if (!$service->maintenanceAllowed($role, \get_client_ip())) {
            header('Retry-After: 300');
            throw new ApiError(503,'系統維護中，請稍後再試。');
        }
    }

    public static function headers(bool $https): void
    {
        header_remove('X-Powered-By');
        foreach ([
            'X-Frame-Options'=>'DENY', 'X-XSS-Protection'=>'0', 'X-Content-Type-Options'=>'nosniff',
            'Referrer-Policy'=>'no-referrer', 'Permissions-Policy'=>'geolocation=(), camera=(), microphone=()',
            'Cross-Origin-Opener-Policy'=>'same-origin', 'Cross-Origin-Resource-Policy'=>'same-origin',
            'X-Permitted-Cross-Domain-Policies'=>'none', 'Cache-Control'=>'no-store',
        ] as $name=>$value) header($name . ': ' . $value);
        if ($https) header('Strict-Transport-Security: max-age=31536000');
        \HttpSecurityPolicy::send("default-src 'self'; script-src 'none'; style-src 'self'; img-src 'self'; frame-ancestors 'none'; object-src 'none'; base-uri 'none'; form-action 'self'");
    }

    public static function check(Service $service, array $metadata = []): void
    {
        if (function_exists('feature_enabled') && !\feature_enabled('yc_note')) throw new ApiError(503,'筆記功能暫停開放。');
        $ip = \get_client_ip();
        if ($service->ipBlocked($ip)) throw new ApiError(403, '存取被拒絕。');
        \GeoBlocker::check();
        if (!$metadata) return;
        $result = \DictionaryDetector::detect($metadata);
        $action = \SecurityPipeline::classifyDictionaryResult($result);
        if ($action !== 'pass') $service->securityEvent('input.' . $action, ['ip_hash'=>hash('sha256',$ip),'score'=>(int)$result['score'],'types'=>$result['attack_type']]);
        if (in_array($action,['block','block_ban'],true)) throw new ApiError(403, '請求已被拒絕。');
    }

    public static function scan(string $bytes, string $path): void
    {
        $temporary = tempnam(sys_get_temp_dir(), 'yc-note-scan-');
        if ($temporary === false) throw new \RuntimeException('Upload scan storage unavailable.');
        try {
            if (file_put_contents($temporary,$bytes,LOCK_EX)!==strlen($bytes)) throw new \RuntimeException('Upload scan storage failed.');
            $result = \UploadSecurityScanner::scan($temporary,$path,'application/octet-stream');
            if (empty($result['safe'])) throw new ApiError(422, '檔案未通過安全檢查。');
            if (!\UploadSecurityScanner::isReleaseableResult($result['status'] ?? '',$result['engine'] ?? '')) throw new ApiError(503, '檔案掃描服務尚未就緒。');
        } finally { unlink($temporary); }
    }
}
