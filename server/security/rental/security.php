<?php
/**
 * 安全輔助函數
 * 密碼雜湊、輸入過濾等
 */

require_once __DIR__ . '/EmojiPolicy.php';

/**
 * 密碼雜湊
 * @param string $password 明文密碼
 * @return string 雜湊後的密碼
 */
function hash_password($password) {
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}

/**
 * 驗證密碼
 * @param string $password 明文密碼
 * @param string $hash 資料庫中的雜湊值
 * @return bool 是否匹配
 */
function verify_password($password, $hash) {
    $hash = (string) $hash;

    if ($hash === '') {
        return false;
    }

    $info = password_get_info($hash);
    if (!empty($info['algo'])) {
        return password_verify($password, $hash);
    }

    // 舊資料相容：允許一次性明文驗證，登入成功後應立即升級為 bcrypt。
    return hash_equals($hash, (string) $password);
}

/**
 * 判斷目前密碼雜湊是否需要升級
 *
 * @param string $hash
 * @return bool
 */
function password_hash_needs_upgrade($hash) {
    $hash = (string) $hash;
    if ($hash === '') {
        return true;
    }

    $info = password_get_info($hash);
    if (empty($info['algo'])) {
        return true;
    }

    return password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 12]);
}

/**
 * 建立密碼禁用片段清單
 *
 * @param array $values
 * @return array<int, string>
 */
function build_password_forbidden_fragments(array $values) {
    $fragments = [];

    foreach ($values as $value) {
        $value = trim((string) $value);
        if ($value === '') {
            continue;
        }

        $fragments[] = $value;

        if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $local = strstr($value, '@', true);
            if ($local !== false && $local !== '') {
                $fragments[] = $local;
            }
        }
    }

    return array_values(array_unique(array_filter($fragments, static function ($fragment) {
        return mb_strlen($fragment, 'UTF-8') >= 4;
    })));
}

/**
 * 驗證密碼強度
 *
 * @param string $password
 * @param array<int, string> $forbiddenFragments
 * @return array<int, string>
 */
function validate_password_strength($password, array $forbiddenFragments = []) {
    $password = (string) $password;
    $errors = [];

    if (strlen($password) < 10) {
        $errors[] = '密碼至少需要 10 個字元';
    }
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = '密碼需包含至少一個小寫英文字母';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = '密碼需包含至少一個大寫英文字母';
    }
    if (!preg_match('/\d/', $password)) {
        $errors[] = '密碼需包含至少一個數字';
    }

    $normalizedPassword = mb_strtolower($password, 'UTF-8');
    foreach ($forbiddenFragments as $fragment) {
        $fragment = trim((string) $fragment);
        if ($fragment === '') {
            continue;
        }

        $normalizedFragment = mb_strtolower($fragment, 'UTF-8');
        if ($normalizedFragment !== '' && mb_strpos($normalizedPassword, $normalizedFragment, 0, 'UTF-8') !== false) {
            $errors[] = '密碼不可包含帳號、姓名、Email 或身分證等易猜資訊';
            break;
        }
    }

    return $errors;
}

/**
 * 清理使用者輸入
 * @param string $input 使用者輸入
 * @return string 清理後的輸入
 */
function normalize_text_input($input) {
    $input = EmojiPolicy::remove(trim((string) $input));
    return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $input) ?? '';
}

/**
 * 舊函式名稱相容；HTML 編碼必須在輸出位置依 HTML、屬性、JSON 或 URL 情境處理。
 */
function sanitize_input($input) {
    return normalize_text_input($input);
}

/**
 * 驗證並清理整數輸入
 * @param mixed $input 輸入
 * @return int 整數
 */
function sanitize_int($input) {
    return (int) filter_var($input, FILTER_SANITIZE_NUMBER_INT);
}

/**
 * 限制操作訊息可使用的介面狀態，避免 Session 或服務回傳值成為任意 CSS 類別。
 */
function ui_alert_type($type) {
    $type = strtolower(trim((string) $type));
    if ($type === 'error') $type = 'danger';
    return in_array($type, ['success', 'danger', 'warning', 'info'], true)
        ? $type
        : 'info';
}

/**
 * 驗證 Email
 * @param string $email Email 地址
 * @return bool 是否有效
 */
function validate_email($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * 驗證台灣身分證字號
 * @param string $id 身分證字號
 * @return bool 是否有效
 */
function validate_taiwan_id($id) {
    if (!preg_match('/^[A-Z][0-9]{9}$/', $id)) {
        return false;
    }
    
    $letters = [
        'A' => 10, 'B' => 11, 'C' => 12, 'D' => 13, 'E' => 14,
        'F' => 15, 'G' => 16, 'H' => 17, 'I' => 34, 'J' => 18,
        'K' => 19, 'L' => 20, 'M' => 21, 'N' => 22, 'O' => 35,
        'P' => 23, 'Q' => 24, 'R' => 25, 'S' => 26, 'T' => 27,
        'U' => 28, 'V' => 29, 'W' => 32, 'X' => 30, 'Y' => 31, 'Z' => 33
    ];
    
    $letterValue = $letters[$id[0]];
    $n1 = floor($letterValue / 10);
    $n2 = $letterValue % 10;
    
    $sum = $n1 + $n2 * 9;
    $weights = [8, 7, 6, 5, 4, 3, 2, 1, 1];
    
    for ($i = 1; $i <= 9; $i++) {
        $sum += intval($id[$i]) * $weights[$i - 1];
    }
    
    return $sum % 10 === 0;
}

/**
 * 記錄安全事件
 * @param string $event 事件描述
 * @param string $level 等級 (info, warning, error)
 */
function log_security_event($event, $level = 'info') {
    $log_file = __DIR__ . '/../logs/security.log';
    $log_dir = dirname($log_file);

    if (!is_dir($log_dir)) {
        @mkdir($log_dir, 0750, true);
    }

    if (!is_dir($log_dir) || !is_writable($log_dir)) {
        return false;
    }

    $allowedLevels = ['info', 'warning', 'error', 'critical', 'danger'];
    $level = strtolower((string) $level);
    if (!in_array($level, $allowedLevels, true)) {
        $level = 'info';
    }

    $event = preg_replace('/[\r\n\t]+/u', ' ', (string) $event) ?? '';
    $event = preg_replace('/[A-Z][12][0-9]{8}/i', '[id-redacted]', $event) ?? $event;
    $event = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[email-redacted]', $event) ?? $event;
    $event = preg_replace('/\b(password|token|secret|key)\s*[:=]\s*[^\s&]+/i', '$1=[redacted]', $event) ?? $event;
    $event = mb_substr(trim($event), 0, 1000, 'UTF-8');

    clearstatcache(true, $log_file);
    if (is_file($log_file) && (int) @filesize($log_file) >= 2 * 1024 * 1024) {
        $rotated = $log_file . '.1';
        if (is_file($rotated)) {
            @unlink($rotated);
        }
        @rename($log_file, $rotated);
    }

    $timestamp = date('Y-m-d H:i:s');
    $ip = get_client_ip();
    $user_id = $_SESSION['user_id'] ?? 'guest';

    $log_entry = "[$timestamp] [$level] [IP:$ip] [User:$user_id] $event" . PHP_EOL;
    $written = @file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
    if ($written !== false) {
        @chmod($log_file, 0640);
    }
    return $written !== false;
}

/**
 * 取得受信任代理伺服器 CIDR 清單
 * 若未設定，僅信任本機代理。
 *
 * @return array<int, string>
 */
function get_trusted_proxy_ranges_config() {
    if (defined('TRUSTED_PROXY_RANGES') && is_array(TRUSTED_PROXY_RANGES)) {
        return TRUSTED_PROXY_RANGES;
    }

    return ['127.0.0.1/32', '::1/128'];
}

/**
 * 檢查 IP 是否符合 CIDR
 *
 * @param string $ip
 * @param string $cidr
 * @return bool
 */
function ip_matches_cidr($ip, $cidr) {
    if (!is_string($ip) || !is_string($cidr) || $ip === '' || $cidr === '') {
        return false;
    }

    if (strpos($cidr, '/') === false) {
        return hash_equals($cidr, $ip);
    }

    [$subnet, $prefixLength] = explode('/', $cidr, 2);
    $ipBinary = @inet_pton($ip);
    $subnetBinary = @inet_pton($subnet);

    if ($ipBinary === false || $subnetBinary === false || strlen($ipBinary) !== strlen($subnetBinary)) {
        return false;
    }

    $prefixLength = (int) $prefixLength;
    $maxBits = strlen($ipBinary) * 8;
    if ($prefixLength < 0 || $prefixLength > $maxBits) {
        return false;
    }

    $fullBytes = intdiv($prefixLength, 8);
    $remainingBits = $prefixLength % 8;

    if ($fullBytes > 0 && substr($ipBinary, 0, $fullBytes) !== substr($subnetBinary, 0, $fullBytes)) {
        return false;
    }

    if ($remainingBits === 0) {
        return true;
    }

    $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
    return ((ord($ipBinary[$fullBytes]) & $mask) === (ord($subnetBinary[$fullBytes]) & $mask));
}

/**
 * 判斷目前 REMOTE_ADDR 是否為受信任代理
 *
 * @param string $ip
 * @return bool
 */
function is_trusted_proxy_ip($ip) {
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return false;
    }

    foreach (get_trusted_proxy_ranges_config() as $range) {
        if (ip_matches_cidr($ip, $range)) {
            return true;
        }
    }

    return false;
}

/**
 * 從代理標頭中擷取原始用戶端 IP
 *
 * @param string $headerValue
 * @return string|null
 */
function extract_forwarded_client_ip($headerValue) {
    if (!is_string($headerValue) || trim($headerValue) === '') {
        return null;
    }

    $candidates = array_map('trim', explode(',', $headerValue));
    foreach ($candidates as $candidate) {
        if (filter_var($candidate, FILTER_VALIDATE_IP) && !is_trusted_proxy_ip($candidate)) {
            return $candidate;
        }
    }

    return null;
}
/**
 * 統一的用戶端 IP 取得函數
 * 所有模組應統一使用此函數，避免各自實作導致不一致
 * 僅在 REMOTE_ADDR 為受信任代理時，才會採信轉發標頭
 * 
 * @return string 用戶端 IP 地址
 */
function get_client_ip() {
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';

    if (!filter_var($remoteAddr, FILTER_VALIDATE_IP)) {
        return '0.0.0.0';
    }

    if (is_trusted_proxy_ip($remoteAddr)) {
        $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP'];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $forwardedIp = extract_forwarded_client_ip($_SERVER[$header]);
                if ($forwardedIp !== null) {
                    return $forwardedIp;
                }
            }
        }
    }

    return $remoteAddr;
}
?>
