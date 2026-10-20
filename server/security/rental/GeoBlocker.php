<?php
/**
 * IP 封鎖與選用的地區限制。
 * 手動黑名單永遠生效；地區限制必須由部署設定明確啟用。
 */

class GeoBlocker {
    // 是否啟用地理封鎖
    private static $enabled = true;
    
    // 允許的國家代碼
    private static $allowedCountries = ['TW'];
    
    // 允許境外訪問的頁面（不封鎖但標記為境外訪客）
    private static $foreignAllowedPages = [
        'about_developer.php',
    ];
    
    // 白名單 IP（本地開發、內部網路）
    private static $whitelist = [
        '127.0.0.1',
        '::1',
        'localhost',
    ];
    
    // 白名單 IP 範圍
    private static $whitelistRanges = [
        '192.168.',   // 內部網路
        '10.',        // 內部網路
        '172.16.',    // 內部網路
        '172.17.',
        '172.18.',
        '172.19.',
        '172.20.',
        '172.21.',
        '172.22.',
        '172.23.',
        '172.24.',
        '172.25.',
        '172.26.',
        '172.27.',
        '172.28.',
        '172.29.',
        '172.30.',
        '172.31.',
    ];
    
    // 已封禁的 IP/裝置指紋快取
    private static $bannedCache = null;
    
    /**
     * 檢查並封鎖國外 IP
     */
    public static function check() {
        if (!self::$enabled) {
            return true;
        }
        
        $ip = self::getClientIP();
        
        // 明確建立的 IP 黑名單永遠優先。
        if (self::isIPBanned($ip)) {
            http_response_code(403);
            die(self::getBannedPage());
        }
        
        // 檢查白名單
        if (self::isWhitelisted($ip)) {
            return true;
        }

        // 不明確啟用時，不把訪客 IP 傳給第三方定位服務，也不依國家阻擋。
        if (!self::countryRestrictionEnabled()) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                unset(
                    $_SESSION['is_foreign_visitor'],
                    $_SESSION['visitor_country'],
                    $_SESSION['visitor_country_ip']
                );
            }
            return true;
        }
        
        // 同一 session 直接沿用結果，避免共享主機每頁都讀檔或呼叫外部服務。
        $country = null;
        if (
            session_status() === PHP_SESSION_ACTIVE
            && ($_SESSION['visitor_country_ip'] ?? '') === $ip
            && preg_match('/^[A-Z]{2}$/', (string) ($_SESSION['visitor_country'] ?? ''))
        ) {
            $country = $_SESSION['visitor_country'];
        } else {
            $country = self::getCountry($ip);
        }
        
        if ($country === null) {
            // 無法確定國家時，記錄並允許（避免誤封）
            error_log("[GeoBlocker] Cannot determine country for IP: $ip");
            return true;
        }
        
        // 儲存國家資訊到 session
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['visitor_country'] = $country;
            $_SESSION['visitor_country_ip'] = $ip;
        }
        
        if (!in_array($country, self::$allowedCountries)) {
            // 檢查當前頁面是否允許境外訪問
            if (self::isPageAllowedForForeign()) {
                // 標記為境外訪客（不封鎖）
                if (session_status() === PHP_SESSION_ACTIVE) {
                    $_SESSION['is_foreign_visitor'] = true;
                    $_SESSION['visitor_country'] = $country;
                }
                error_log("[GeoBlocker] Foreign IP allowed for page: $ip (Country: $country)");
                return true;
            }
            self::blockAccess($ip, $country);
            return false;
        }
        
        // 台灣 IP
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['is_foreign_visitor'] = false;
        }
        
        return true;
    }
    
    /**
     * 檢查 IP 是否在白名單
     */
    private static function isWhitelisted($ip) {
        // 精確匹配
        if (in_array($ip, self::$whitelist)) {
            return true;
        }
        
        // 範圍匹配
        foreach (self::$whitelistRanges as $range) {
            if (strpos($ip, $range) === 0) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * 判斷目前訪客是否屬於允許國家或白名單 IP
     * 未知國家一律視為不允許，用於較敏感的功能顯示控制
     */
    public static function isAllowedVisitor() {
        if (!self::countryRestrictionEnabled()) {
            return true;
        }

        $ip = self::getClientIP();

        if (self::isWhitelisted($ip)) {
            return true;
        }

        $country = self::getVisitorCountry();
        if (!is_string($country) || $country === '') {
            $country = self::getCountry($ip);
        }

        return is_string($country) && in_array($country, self::$allowedCountries, true);
    }
    
    /**
     * 查詢 IP 所屬國家
     * 使用免費的 ipapi.co HTTPS 服務
     */
    private static function getCountry($ip) {
        // 快取檢查（避免重複請求，快取 7 天）
        $cacheFile = sys_get_temp_dir() . '/geo_cache_' . md5($ip) . '.txt';
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 604800) {
            return file_get_contents($cacheFile);
        }
        
        // 呼叫 HTTPS API（安全傳輸）
        $url = "https://ipapi.co/{$ip}/country/";
        
        $context = stream_context_create([
            'http' => [
                'timeout' => 1.5,
                'ignore_errors' => true,
                'header' => "User-Agent: YC-RentalSystem/1.0\r\n"
            ]
        ]);
        
        $response = @file_get_contents($url, false, $context);
        
        if ($response === false || strlen($response) > 3) {
            return null;
        }
        
        $country = trim($response);
        if (preg_match('/^[A-Z]{2}$/', $country)) {
            // 快取結果
            @file_put_contents($cacheFile, $country);
            return $country;
        }
        
        return null;
    }
    
    /**
     * 封鎖存取並記錄
     */
    private static function blockAccess($ip, $country) {
        global $conn;
        
        // 記錄到安全日誌
        if (isset($conn)) {
            $userAgent = self::safeLogText($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 255);
            $requestPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
            $requestUri = self::safeLogText(is_string($requestPath) && $requestPath !== '' ? $requestPath : '/', 255);
            $requestData = json_encode([
                'source' => 'GEO_BLOCK',
                'country' => $country,
                'ip' => $ip
            ], JSON_UNESCAPED_UNICODE);
            
            try {
                $stmt = $conn->prepare(
                    "INSERT INTO security_events (user_id, event_type, request_uri, request_data, ip_address, user_agent) 
                     VALUES (NULL, 'geo_block', ?, ?, ?, ?)"
                );
                $stmt->bind_param('ssss', $requestUri, $requestData, $ip, $userAgent);
                $stmt->execute();
                $stmt->close();
            } catch (Exception $e) {
                error_log('[GeoBlocker] database operation failed type=' . get_class($e) . ' code=' . (int) $e->getCode());
            }
        }
        
        // 記錄到日誌
        error_log("[GeoBlocker] Blocked foreign IP: $ip (Country: $country)");
        
        // 返回封鎖頁面
        http_response_code(403);
        die(self::getBlockPage($country));
    }
    
    /**
     * 取得用戶端 IP（使用統一的 get_client_ip 函數）
     */
    private static function getClientIP() {
        return function_exists('get_client_ip') ? get_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    private static function safeLogText($value, $limit) {
        $value = str_replace(["\0", "\r", "\n", "\t"], ' ', (string) $value);
        $value = trim(preg_replace('/ +/', ' ', $value) ?? '');
        return function_exists('mb_substr')
            ? mb_substr($value, 0, $limit, 'UTF-8')
            : substr($value, 0, $limit);
    }
    
    /**
     * 封鎖頁面
     */
    private static function getBlockPage($country) {
        return '<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>存取被拒絕 - Access Denied</title>
    <style nonce="' . csp_nonce() . '">
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #f5f7fa;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            color: #172033;
        }
        .container {
            text-align: center;
            width: min(100%, 500px);
            padding: 32px;
            background: #fff;
            border: 1px solid #dbe3ec;
            border-radius: 12px;
        }
        h1 { font-size: 28px; margin-bottom: 14px; color: #172033; }
        p { color: #64748b; line-height: 1.6; margin-bottom: 10px; }
        .code { color: #64748b; font-family: monospace; margin-top: 18px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>存取被拒絕</h1>
        <p>本系統僅限台灣地區使用。</p>
        <p>This service is only available in Taiwan.</p>
        <div class="code">ERR_GEO_BLOCKED (' . htmlspecialchars($country) . ')</div>
    </div>
</body>
</html>';
    }
    
    /**
     * 啟用/停用地理封鎖
     */
    public static function setEnabled($enabled) {
        self::$enabled = $enabled;
    }

    private static function countryRestrictionEnabled()
    {
        return function_exists('config_env_bool')
            && config_env_bool('GEO_RESTRICTION_ENABLED', false);
    }
    
    /**
     * 新增白名單 IP
     */
    public static function addToWhitelist($ip) {
        self::$whitelist[] = $ip;
    }
    
    /**
     * 檢查當前頁面是否允許境外訪問
     */
    private static function isPageAllowedForForeign() {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        $page = basename(parse_url($scriptName, PHP_URL_PATH));
        
        return in_array($page, self::$foreignAllowedPages);
    }
    
    /**
     * 檢查當前訪客是否為境外訪客
     */
    public static function isForeignVisitor() {
        return !empty($_SESSION['is_foreign_visitor']);
    }
    
    /**
     * 取得訪客所屬國家
     */
    public static function getVisitorCountry() {
        return $_SESSION['visitor_country'] ?? null;
    }
    
    /**
     * 檢查 IP 是否被安全系統封禁
     */
    public static function isIPBanned($ip) {
        global $conn;
        if (!$conn) return false;

        $cache = $_SESSION['_ip_ban_cache'] ?? null;
        if (
            is_array($cache)
            && ($cache['ip'] ?? '') === $ip
            && (int) ($cache['expires_at'] ?? 0) >= time()
        ) {
            return !empty($cache['banned']);
        }

        try {
            $stmt = $conn->prepare(
                "SELECT 1
                 FROM ip_blacklist
                 WHERE ip_address = ?
                   AND (blocked_until IS NULL OR blocked_until > NOW())
                 LIMIT 1"
            );
            if (!$stmt) return false;
            $stmt->bind_param('s', $ip);
            $stmt->execute();
            $result = $stmt->get_result();
            $isBanned = $result->num_rows > 0;
            $stmt->close();
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION['_ip_ban_cache'] = [
                    'ip' => $ip,
                    'banned' => $isBanned,
                    'expires_at' => time() + 10,
                ];
            }
            return $isBanned;
        } catch (\Throwable $e) {
            error_log('[GeoBlocker] blacklist check failed type=' . get_class($e) . ' code=' . (int) $e->getCode());
            return false;
        }
    }
    
    /**
     * 封禁指定 IP（管理員 IP 免封禁）
     */
    public static function banIP($ip, $reason = 'Manual block') {
        global $conn;
        if (!$conn) return false;
        
        //  管理員/超級管理員 IP 不封禁
        if (self::isAdminIP($ip)) {
            error_log("[GeoBlocker] Skipped banning admin IP: $ip | Reason: $reason");
            return false;
        }
        
        try {
            $normalizedReason = stripos($reason, 'brute') !== false ? 'brute_force' : 'attack';
            $createdBy = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
            $stmt = $conn->prepare(
                "INSERT INTO ip_blacklist
                 (ip_address, block_reason, block_level, blocked_until, attempt_count, created_by, notes)
                 VALUES (?, ?, 3, NULL, 1, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    block_reason = VALUES(block_reason),
                    block_level = GREATEST(block_level, VALUES(block_level)),
                    blocked_until = NULL,
                    attempt_count = attempt_count + 1,
                    notes = VALUES(notes),
                    updated_at = NOW()"
            );
            $stmt->bind_param('ssis', $ip, $normalizedReason, $createdBy, $reason);
            $stmt->execute();
            $stmt->close();
            unset($_SESSION['_ip_ban_cache']);
            return true;
        } catch (\Throwable $e) {
            error_log('[GeoBlocker] ban failed type=' . get_class($e) . ' code=' . (int) $e->getCode());
            return false;
        }
    }
    
    /**
     * 解禁指定 IP
     */
    public static function unbanIP($ip) {
        global $conn;
        if (!$conn) return false;
        
        try {
            $stmt = $conn->prepare(
                "UPDATE ip_blacklist
                 SET blocked_until = NOW(), notes = CONCAT(COALESCE(notes, ''), '\nUnbanned at ', NOW())
                 WHERE ip_address = ? AND (blocked_until IS NULL OR blocked_until > NOW())"
            );
            $stmt->bind_param('s', $ip);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();

            unset($_SESSION['_ip_ban_cache']);

            return $affected > 0;
        } catch (\Throwable $e) {
            error_log('[GeoBlocker] unban failed type=' . get_class($e) . ' code=' . (int) $e->getCode());
            return false;
        }
    }
    
    /**
     * 檢查 IP 是否曾被管理員/超級管理員成功登入過
     */
    public static function isAdminIP($ip) {
        global $conn;
        if (!$conn) return false;
        
        try {
            $stmt = $conn->prepare(
                "SELECT 1 FROM login_records lr 
                 JOIN users u ON lr.user_id = u.id 
                 WHERE lr.ip_address = ? AND lr.success = 1 
                 AND u.type IN ('manager', 'admin', 'super_admin')
                 LIMIT 1"
            );
            if (!$stmt) return false;
            $stmt->bind_param('s', $ip);
            $stmt->execute();
            $result = $stmt->get_result();
            $isAdmin = $result->num_rows > 0;
            $stmt->close();
            return $isAdmin;
        } catch (\Throwable $e) {
            return false;
        }
    }
    
    /**
     * 被封禁後的頁面
     */
    private static function getBannedPage() {
        return '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Denied</title>
    <style nonce="' . csp_nonce() . '">
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #f5f7fa;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            color: #172033;
        }
        .container {
            text-align: center;
            width: min(100%, 500px);
            padding: 32px;
            background: #fff;
            border: 1px solid #dbe3ec;
            border-radius: 12px;
        }
        h1 { font-size: 28px; margin-bottom: 14px; color: #172033; }
        p { color: #64748b; line-height: 1.6; margin-bottom: 10px; }
        .code { color: #64748b; font-family: monospace; margin-top: 18px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>無法存取</h1>
        <p>此網路位址目前無法使用本系統。</p>
        <div class="code">ERR_IP_BANNED</div>
    </div>
</body>
</html>';
    }
}
