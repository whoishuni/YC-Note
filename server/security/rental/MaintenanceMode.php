<?php
/**
 * 全站維護模式。
 *
 * 設定以 system_settings 為主，tmp 內的 JSON 只是跨請求快取，
 * 避免共用主機每個 PHP 請求都查詢資料庫。
 */
class MaintenanceMode
{
    private const SETTING_PREFIX = 'maintenance_';
    private static $requestStatus = null;

    /** Same maintenance decision for the Session-free YC Note bearer API. */
    public static function apiAllowed(PDO $identity, string $role, string $ip): bool
    {
        if ($role === 'super_admin') return true;
        $rows=$identity->query("SELECT setting_key,setting_value FROM system_settings WHERE setting_key LIKE 'maintenance_%'")->fetchAll(PDO::FETCH_ASSOC);
        $status=self::defaultStatus();
        foreach ($rows as $row) {
            $key=substr((string)$row['setting_key'],strlen(self::SETTING_PREFIX));
            if (array_key_exists($key,$status)) $status[$key]=(string)$row['setting_value'];
        }
        $status=self::normalize($status);
        return !$status['active'] || in_array($ip,self::normalizeAllowedIps($status['allowed_ips']),true);
    }

    public static function getStatus($conn, $refresh = false)
    {
        if (!$refresh && is_array(self::$requestStatus)) {
            return self::$requestStatus;
        }

        if (!$refresh) {
            $cached = self::readCache();
            if (is_array($cached)) {
                self::$requestStatus = $cached;
                return $cached;
            }
        }

        $status = self::defaultStatus();
        if ($conn instanceof mysqli && !$conn->connect_errno) {
            $stmt = @mysqli_prepare(
                $conn,
                "SELECT setting_key, setting_value
                 FROM system_settings
                 WHERE setting_key IN (
                    'maintenance_enabled', 'maintenance_message', 'maintenance_started_at',
                    'maintenance_started_by', 'maintenance_updated_at',
                    'maintenance_allowed_ips', 'maintenance_ends_at',
                    'maintenance_cycle_id'
                 )"
            );
            if ($stmt) {
                if (@mysqli_stmt_execute($stmt)) {
                    $result = @mysqli_stmt_get_result($stmt);
                    if ($result) {
                        while ($row = mysqli_fetch_assoc($result)) {
                            $key = substr((string) $row['setting_key'], strlen(self::SETTING_PREFIX));
                            if (array_key_exists($key, $status)) {
                                $status[$key] = (string) ($row['setting_value'] ?? '');
                            }
                        }
                    }
                }
                mysqli_stmt_close($stmt);
            }
        }

        $status = self::normalize($status);
        self::$requestStatus = $status;
        self::writeCache($status);
        return $status;
    }

    public static function setStatus($conn, $enabled, $message, $adminId, array $options = [])
    {
        if (!($conn instanceof mysqli) || $conn->connect_errno) {
            return false;
        }

        try {
            $engine=$conn->query("SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='system_settings' AND TABLE_TYPE='BASE TABLE'");
            if (!$engine instanceof mysqli_result || strcasecmp((string)($engine->fetch_assoc()['engine'] ?? ''),'InnoDB')!==0) {
                return false;
            }
        } catch (Throwable $exception) { return false; }

        $enabled = (bool) $enabled;
        $now = date('Y-m-d H:i:s');
        $current = self::getStatus($conn, true);
        $message = trim((string) $message);
        if ($message === '') {
            $message = '系統正在進行維護，請稍後再試。';
        }
        $message = mb_substr($message, 0, 500);
        $allowedIps = array_key_exists('allowed_ips', $options)
            ? self::normalizeAllowedIps($options['allowed_ips'])
            : self::normalizeAllowedIps($current['allowed_ips'] ?? []);
        $endsAt = $enabled
            ? self::normalizeFutureDateTime($options['ends_at'] ?? ($current['ends_at'] ?? ''))
            : '';
        $continuingActiveCycle = $enabled && !empty($current['active']);
        $startedAt = $enabled
            ? ($continuingActiveCycle && !empty($current['started_at']) ? $current['started_at'] : $now)
            : '';
        $startedBy = $enabled
            ? ($continuingActiveCycle && !empty($current['started_by'])
                ? (string) $current['started_by']
                : (string) ((int) $adminId))
            : '';
        $cycleId = $enabled
            ? ($continuingActiveCycle && !empty($current['cycle_id'])
                ? (string) $current['cycle_id']
                : bin2hex(random_bytes(16)))
            : '';

        $values = [
            'maintenance_enabled' => $enabled ? '1' : '0',
            'maintenance_message' => $message,
            'maintenance_started_at' => $startedAt,
            'maintenance_started_by' => $startedBy,
            'maintenance_updated_at' => $now,
            'maintenance_allowed_ips' => implode(',', $allowedIps),
            'maintenance_ends_at' => $endsAt,
            'maintenance_cycle_id' => $cycleId,
        ];

        try {
            if (!$conn->begin_transaction()) {
                throw new RuntimeException('Unable to begin maintenance setting update.');
            }
            $stmt = mysqli_prepare(
                $conn,
                "INSERT INTO system_settings (setting_key, setting_value, description)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), description = VALUES(description)"
            );
            if (!$stmt) {
                throw new RuntimeException('Unable to prepare maintenance setting update.');
            }

            foreach ($values as $key => $value) {
                $description = '系統維護模式設定';
                mysqli_stmt_bind_param($stmt, 'sss', $key, $value, $description);
                if (!mysqli_stmt_execute($stmt)) {
                    throw new RuntimeException('Unable to update maintenance settings.');
                }
            }
            mysqli_stmt_close($stmt);
            if (!$conn->commit()) {
                throw new RuntimeException('Unable to confirm maintenance setting update.');
            }
        } catch (Throwable $exception) {
            mysqli_rollback($conn);
            error_log('[MaintenanceMode] failed type=' . get_class($exception) . ' code=' . (int) $exception->getCode());
            return false;
        }

        self::$requestStatus = self::normalize([
            'enabled' => $enabled,
            'message' => $message,
            'started_at' => $startedAt,
            'started_by' => $startedBy,
            'updated_at' => $now,
            'allowed_ips' => $allowedIps,
            'ends_at' => $endsAt,
            'cycle_id' => $cycleId,
        ]);
        self::writeCache(self::$requestStatus);
        return true;
    }

    public static function enforce($conn)
    {
        if (PHP_SAPI === 'cli' || self::isSuperAdmin() || self::isPublicBypassRoute()) {
            return;
        }

        $status = self::getStatus($conn);
        if (empty($status['active']) || self::isAllowedClient($status['allowed_ips'] ?? [])) {
            return;
        }

        self::renderUnavailable($status);
    }

    private static function isSuperAdmin()
    {
        return isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'super_admin';
    }

    private static function isPublicBypassRoute()
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
        $basename = basename($path);
        if ($basename === '' || in_array($basename, ['index.php', 'logout.php'], true)) {
            return true;
        }

        if (defined('BASE_URL')) {
            $basePath = parse_url((string) BASE_URL, PHP_URL_PATH) ?: '/';
            if (rtrim($path, '/') === rtrim($basePath, '/')) {
                return true;
            }
        }

        return false;
    }

    private static function isAllowedClient($allowedIps)
    {
        $clientIp = function_exists('get_client_ip')
            ? get_client_ip()
            : (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        if (!filter_var($clientIp, FILTER_VALIDATE_IP)) {
            return false;
        }

        foreach (self::normalizeAllowedIps($allowedIps) as $allowedIp) {
            if (hash_equals($allowedIp, $clientIp)) {
                return true;
            }
        }

        return false;
    }

    private static function renderUnavailable($status)
    {
        http_response_code(503);
        $retryAfter = 300;
        if (!empty($status['ends_at'])) {
            $remaining = strtotime((string) $status['ends_at']) - time();
            if ($remaining > 0) {
                $retryAfter = max(60, min(3600, $remaining));
            }
        }
        header('Retry-After: ' . $retryAfter);
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Content-Type: text/html; charset=UTF-8');
        $message = htmlspecialchars((string) $status['message'], ENT_QUOTES, 'UTF-8');
        $updatedAt = htmlspecialchars((string) $status['updated_at'], ENT_QUOTES, 'UTF-8');
        $endsAt = htmlspecialchars((string) ($status['ends_at'] ?? ''), ENT_QUOTES, 'UTF-8');
        $recoveryLabel = $endsAt !== ''
            ? '<div class="recovery">預計恢復時間：' . $endsAt . '</div>'
            : '';
        echo '<!doctype html><html lang="zh-TW"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex,nofollow">'
            . '<title>系統維護中</title><style nonce="' . csp_nonce() . '">'
            . '*{box-sizing:border-box}body{margin:0;min-height:100dvh;display:grid;place-items:center;padding:20px;'
            . 'font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans TC",sans-serif;'
            . 'color:#172033;background:#f5f7fa}'
            . 'main{width:min(620px,100%);background:#fff;border:1px solid #dbe3ec;border-radius:12px;'
            . 'padding:clamp(24px,6vw,42px);box-shadow:0 4px 16px rgba(15,23,42,.06);text-align:center}'
            . 'h1{font-size:clamp(28px,7vw,38px);line-height:1.2;margin:0 0 14px;color:#172033}'
            . 'p{font-size:17px;line-height:1.7;color:#526176;margin:0 auto;max-width:520px}.recovery{margin:20px auto 0;'
            . 'padding:10px 12px;border:1px solid #e3c982;border-radius:8px;background:#fff9e8;color:#76561b;font-weight:700;font-size:14px}'
            . '.meta{margin-top:22px;padding-top:18px;'
            . 'border-top:1px solid #e6e9ef;color:#738095;font-size:13px}.login{display:inline-flex;margin-top:22px;min-height:44px;'
            . 'align-items:center;justify-content:center;padding:10px 18px;border:1px solid #1d4ed8;border-radius:8px;'
            . 'background:#1d4ed8;color:#fff;text-decoration:none;font-weight:700}.login:focus-visible{outline:3px solid #f59e0b;outline-offset:3px}'
            . '</style></head><body><main><h1>系統維護中</h1>'
            . '<p>' . $message . '</p>' . $recoveryLabel
            . '<div class="meta">狀態更新時間：' . ($updatedAt !== '' ? $updatedAt : '剛剛') . '</div>'
            . '<a class="login" href="' . htmlspecialchars(self::loginUrl(), ENT_QUOTES, 'UTF-8') . '">管理員登入</a>'
            . '</main></body></html>';
        exit;
    }

    private static function loginUrl()
    {
        if (defined('BASE_URL') && trim((string) BASE_URL) !== '') {
            return rtrim((string) BASE_URL, '/') . '/index.php';
        }
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $rentPosition = strpos($path, '/rent/');
        return $rentPosition !== false ? substr($path, 0, $rentPosition + 6) . 'index.php' : '/index.php';
    }

    private static function defaultStatus()
    {
        return [
            'enabled' => false,
            'message' => '系統正在進行維護，請稍後再試。',
            'started_at' => '',
            'started_by' => '',
            'updated_at' => '',
            'allowed_ips' => [],
            'ends_at' => '',
            'cycle_id' => '',
            'active' => false,
            'expired' => false,
        ];
    }

    private static function normalize($status)
    {
        $normalized = array_merge(self::defaultStatus(), is_array($status) ? $status : []);
        $normalized['enabled'] = $normalized['enabled'] === true || (string) $normalized['enabled'] === '1';
        $normalized['message'] = mb_substr(trim((string) $normalized['message']), 0, 500);
        if ($normalized['message'] === '') {
            $normalized['message'] = self::defaultStatus()['message'];
        }
        foreach (['started_at', 'started_by', 'updated_at', 'ends_at', 'cycle_id'] as $field) {
            $normalized[$field] = (string) $normalized[$field];
        }
        $normalized['allowed_ips'] = self::normalizeAllowedIps($normalized['allowed_ips']);
        $endsTimestamp = $normalized['ends_at'] !== '' ? strtotime($normalized['ends_at']) : false;
        $normalized['expired'] = $normalized['enabled']
            && $endsTimestamp !== false
            && $endsTimestamp <= time();
        $normalized['active'] = $normalized['enabled'] && !$normalized['expired'];
        return $normalized;
    }

    public static function normalizeAllowedIps($value)
    {
        $parts = is_array($value)
            ? $value
            : preg_split('/[\s,;]+/', trim((string) $value));
        if (!is_array($parts)) {
            return [];
        }

        $normalized = [];
        foreach ($parts as $part) {
            $ip = trim((string) $part);
            if (!filter_var($ip, FILTER_VALIDATE_IP) || in_array($ip, $normalized, true)) {
                continue;
            }
            $normalized[] = $ip;
            if (count($normalized) >= 20) {
                break;
            }
        }
        return $normalized;
    }

    private static function normalizeFutureDateTime($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        $timestamp = strtotime($value);
        if ($timestamp === false || $timestamp <= time()) {
            return '';
        }
        return date('Y-m-d H:i:s', $timestamp);
    }

    private static function cachePath()
    {
        return dirname(__DIR__) . '/tmp/maintenance-status.json';
    }

    private static function readCache()
    {
        $path = self::cachePath();
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $decoded = json_decode((string) @file_get_contents($path), true);
        return is_array($decoded) ? self::normalize($decoded) : null;
    }

    private static function writeCache($status)
    {
        $path = self::cachePath();
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true)) {
            return false;
        }
        $temporary = $path . '.' . getmypid() . '.tmp';
        $json = json_encode(self::normalize($status), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || @file_put_contents($temporary, $json, LOCK_EX) === false) {
            return false;
        }
        @chmod($temporary, 0640);
        return @rename($temporary, $path);
    }
}
