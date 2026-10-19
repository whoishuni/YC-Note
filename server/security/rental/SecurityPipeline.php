<?php
/**
 * SecurityPipeline.php — 資安防護系統
 * 
 * 使用本機字典規則對輸入進行評分記錄，不依賴外部 AI 服務。
 * 
 * 流程：
 * 1. 收集 GET/POST 輸入
 * 2. DictionaryDetector 字典偵測
 * 3. 計算最終分數，只記錄達警示門檻的 security_scores
 * 4. 依分數決定操作：pass / log / block / block_ban
 * 
 * @version 1.0
 */

class SecurityPipeline {
    
    // 操作閾值
    private static $logThreshold = 30;    // ≥ 30 記錄警告
    private static $blockThreshold = 60;  // ≥ 60 阻擋
    private static $banThreshold = 80;    // ≥ 80 阻擋 + 禁用

    // 使用者自動封禁總開關（預設關閉，避免誤鎖）
    private static $autoBanEnabled = false;
    
    // 不需要檢查的路徑
    private static $skipPaths = [
        '/admin/ai_chat.php',
        '/includes/security_monitor_api.php',
        '/includes/log_security_event.php',
        '/cron/',
    ];
    
    // 管理員角色（僅做簡單監控）
    private static $adminTypes = ['admin', 'super_admin'];
    
    /**
     * 處理所有輸入 — 統一入口
     */
    public static function process() {
        // 非 HTTP 環境不處理
        if (php_sapi_name() === 'cli') return;
        
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        
        // 跳過不需要檢查的路徑
        foreach (self::$skipPaths as $skip) {
            if (strpos($requestUri, $skip) !== false) return;
        }
        
        // 收集輸入
        $allInputs = [];
        foreach ($_GET as $key => $value) {
            $allInputs["GET_{$key}"] = $value;
        }
        foreach ($_POST as $key => $value) {
            $allInputs["POST_{$key}"] = $value;
        }
        
        // 沒有輸入且不是 POST 請求 → 跳過
        if (empty($allInputs) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }
        
        // 取得用戶資訊
        $userId = $_SESSION['user_id'] ?? null;
        $userType = $_SESSION['user_type'] ?? ($_SESSION['type'] ?? null);
        $isAdmin = $userType && in_array($userType, self::$adminTypes);
        
        // ========== Stage 1: 字典偵測 ==========
        $dictResult = DictionaryDetector::detect($allInputs);

        // 完全沒有命中規則的正常請求立即結束，不查閾值、不產生 UUID、不寫資料庫。
        if ((int) ($dictResult['score'] ?? 0) <= 0) {
            return;
        }

        // 只有命中本機規則時才從資料庫載入可調整閾值。
        self::loadThresholds();
        
        // 外部 AI 已停用；本機規則分數即為最終分數。
        $aiResult = null;

        // ========== Stage 2: 計算最終分數 ==========
        $finalScore = self::calculateFinalScore($dictResult, $aiResult);
        
        // 管理員分數上限設為 log 等級（不會被阻擋或禁用）
        if ($isAdmin && $userType === 'super_admin') {
            $finalScore = min($finalScore, self::$logThreshold - 1);
        }
        
        // ========== Stage 4: 決定操作 ==========
        $action = self::determineAction($finalScore, $isAdmin, $dictResult);
        
        // ========== Stage 5: 記錄有意義的評分 ==========
        // 正常請求不應成為資料庫寫入；共享主機只保留真正需要稽核的警示與攔截。
        if ($action !== 'pass' && $finalScore >= self::$logThreshold) {
            $requestId = self::generateUUID();
            self::recordScore($requestId, $userId, $dictResult, $aiResult, $finalScore, $action);
        }
        
        // ========== Stage 6: 執行操作 ==========
        self::executeAction($action, $dictResult, $aiResult, $finalScore, $userId, $userType);
    }
    
    /**
     * 計算最終分數
     */
    private static function calculateFinalScore($dictResult, $aiResult) {
        $dictScore = $dictResult['score'] ?? 0;
        
        if ($aiResult === null) {
            return $dictScore;
        }

        // 舊資料相容；目前執行路徑不會再傳入 AI 結果。
        return max($dictScore, (int) ($aiResult['score'] ?? 0));
    }

    /**
     * 供登入等獨立入口共用同一套阻擋決策，避免各頁只看分數而誤鎖。
     */
    public static function classifyDictionaryResult(array $dictResult, $isAdmin = false) {
        $finalScore = (int) ($dictResult['score'] ?? 0);
        return self::determineAction($finalScore, (bool) $isAdmin, $dictResult);
    }
    
    /**
     * 決定操作
     */
    private static function determineAction($finalScore, $isAdmin, array $dictResult) {
        // 管理員不會被阻擋或禁用
        if ($isAdmin) {
            return $finalScore >= self::$logThreshold ? 'log' : 'pass';
        }

        $threats = array_values(array_filter($dictResult['threats'] ?? [], 'is_string'));
        $confirmedAttack = self::hasConfirmedAttack($threats);
        $effectiveBlockThreshold = max(60, self::$blockThreshold);
        $multipleSignals = count($threats) >= 2 && $finalScore >= $effectiveBlockThreshold;

        // 單一低／中信心訊號只記錄。即使規則名稱屬於高風險類別，仍需達到
        // 阻擋門檻，或由多項獨立訊號共同佐證，避免單一片段造成正常流程誤擋。
        if ($finalScore < $effectiveBlockThreshold || (!$confirmedAttack && !$multipleSignals)) {
            return $finalScore >= self::$logThreshold ? 'log' : 'pass';
        }

        if ($finalScore >= self::$banThreshold && self::$autoBanEnabled) {
            return 'block_ban';
        }
        return 'block';
    }

    private static function hasConfirmedAttack(array $threats) {
        $confirmedPrefixes = [
            'SQL-High:',
            'XSS:',
            'Code:',
            'Path:',
            'SSRF:',
            'OS:',
            'Deser:',
            'Log:',
            'Proto:',
        ];

        foreach ($threats as $threat) {
            foreach ($confirmedPrefixes as $prefix) {
                if (str_starts_with($threat, $prefix)) {
                    return true;
                }
            }
        }

        $confirmedHeaderThreats = [
            'Header: Header 注入 (文字換行)',
            'Header: Host Header 注入',
            'Header: HTTP Header 注入',
            'Header: 文字 backslash-n Header 注入',
            'Header: 敏感 Header 換行注入',
        ];
        foreach ($threats as $threat) {
            if (in_array($threat, $confirmedHeaderThreats, true)) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * 記錄評分到 security_scores 表
     */
    private static function recordScore($requestId, $userId, $dictResult, $aiResult, $finalScore, $action) {
        global $conn;
        if (!$conn) return;
        
        try {
            $ip = function_exists('get_client_ip') ? get_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
            $requestUri = $_SERVER['REQUEST_URI'] ?? '';
            $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
            
            $dictScore = $dictResult['score'] ?? 0;
            $aiScore = $aiResult ? ($aiResult['score'] ?? null) : null;
            $dictThreats = !empty($dictResult['threats']) ? json_encode($dictResult['threats'], JSON_UNESCAPED_UNICODE) : null;
            $aiResultJson = $aiResult ? json_encode($aiResult, JSON_UNESCAPED_UNICODE) : null;
            $fieldScores = !empty($dictResult['field_scores']) ? json_encode($dictResult['field_scores'], JSON_UNESCAPED_UNICODE) : null;
            $scoreType = 'input';
            
            $stmt = $conn->prepare(
                "INSERT INTO security_scores 
                 (request_id, user_id, ip_address, request_uri, request_method, score_type,
                  dict_score, ai_score, final_score, dict_threats, ai_result, suspicious_fields, action_taken) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            
            $stmt->bind_param(
                'sissssiiissss',
                $requestId, $userId, $ip, $requestUri, $requestMethod, $scoreType,
                $dictScore, $aiScore, $finalScore, $dictThreats, $aiResultJson, $fieldScores, $action
            );
            
            $stmt->execute();
            $stmt->close();
        } catch (\Throwable $e) {
            error_log("[SecurityPipeline] Score recording failed: " . $e->getMessage());
        }
    }
    
    /**
     * 執行操作
     */
    private static function executeAction($action, $dictResult, $aiResult, $finalScore, $userId, $userType) {
        if ($action === 'pass') return;

        if ($action === 'block_ban' && !self::$autoBanEnabled) {
            $action = 'block';
        }
        
        $ip = function_exists('get_client_ip') ? get_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        $attackType = $dictResult['attack_type'] ?? 'unknown';
        
        // 記錄到 security_events（兼容舊系統）
        self::logSecurityEvent($action, $attackType, $dictResult, $aiResult, $finalScore, $userId, $ip);
        
        // ========== 累積違規追蹤 ==========
        // 只有實際阻擋才累積違規；一般警告不可造成帳號被誤鎖。
        if (class_exists('ViolationTracker') && in_array($action, ['block', 'block_ban'], true)) {
            $violationDetails = [
                'attack_type' => $attackType,
                'dict_score' => $dictResult['score'] ?? 0,
                'ai_score' => $aiResult['score'] ?? null,
                'final_score' => $finalScore,
                'threats' => $dictResult['threats'] ?? [],
                'action' => $action,
            ];
            
            ViolationTracker::recordViolation(
                $userId, $ip, 
                "pipeline_{$action}",  // e.g. pipeline_log, pipeline_block, pipeline_block_ban
                $finalScore, 
                'security_pipeline', 
                $violationDetails
            );
            
            // 檢查累積分數是否需要封禁（即使單次分數未達 ban 閾值）
            if ($userId && $userType !== 'super_admin' && $action !== 'block_ban') {
                $banResult = ViolationTracker::checkAndBan($userId, $ip);
                if ($banResult['banned']) {
                    // 累積分數觸發封禁 → 將 action 升級為 block_ban
                    $action = 'block_ban';
                    error_log("[SecurityPipeline] User {$userId} auto-banned by cumulative score: {$banResult['score']}");
                }
            }
        }
        
        if ($action === 'log') {
            // 僅記錄警告，不阻擋
            error_log("[SECURITY WARNING] Score: $finalScore | IP: $ip | User: $userId | Type: $attackType | Threats: " . implode(', ', $dictResult['threats'] ?? []));
            return;
        }
        
        // block 或 block_ban
        if ($action === 'block_ban' && $userId && $userType !== 'super_admin') {
            self::banUser($userId, $attackType, $finalScore);
        }
        
        // 發送警報郵件
        self::alertAdmins($attackType, $dictResult, $aiResult, $finalScore, $userId, $ip);
        
        // 阻擋請求
        http_response_code(403);
        
        // AJAX 回應（不洩漏偵測細節）
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false, 
                'error' => ' Your request has been blocked by the security system.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // 一般 HTML 回應
        self::renderBlockPage($attackType, $finalScore, $aiResult);
        exit;
    }
    
    /**
     * 記錄安全事件到 security_events（向後相容）
     */
    private static function logSecurityEvent($action, $attackType, $dictResult, $aiResult, $finalScore, $userId, $ip) {
        global $conn;
        if (!$conn) return;
        
        try {
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $requestUri = $_SERVER['REQUEST_URI'] ?? '';
            
            $eventType = $attackType;
            if ($action === 'log') {
                $eventType = "warning_{$attackType}";
            } elseif ($aiResult && ($aiResult['dangerous'] ?? false)) {
                $severity = $aiResult['severity'] ?? 'high';
                $eventType = "pipeline_blocked_{$severity}";
            }
            
            $requestData = json_encode([
                'source' => 'SecurityPipeline',
                'dict_score' => $dictResult['score'] ?? 0,
                'ai_score' => $aiResult['score'] ?? null,
                'final_score' => $finalScore,
                'risk_score' => $finalScore,
                'threats' => $dictResult['threats'] ?? [],
                'ai_detail' => $aiResult['detail'] ?? null,
                'action' => $action,
            ], JSON_UNESCAPED_UNICODE);
            
            $stmt = $conn->prepare(
                "INSERT INTO security_events (user_id, event_type, request_uri, request_data, ip_address, user_agent) 
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param('isssss', $userId, $eventType, $requestUri, $requestData, $ip, $userAgent);
            $stmt->execute();
            $stmt->close();
        } catch (\Throwable $e) {
            error_log("[SecurityPipeline] Event logging failed: " . $e->getMessage());
        }
    }
    
    /**
     * 禁用用戶帳號
     */
    private static function banUser($userId, $reason, $score) {
        global $conn;
        if (!$conn || !self::$autoBanEnabled) return;
        
        try {
            $banReason = "資安防護系統自動禁用：偵測到 {$reason} (分數: {$score}) - " . date('Y-m-d H:i:s');
            $stmt = $conn->prepare("UPDATE users SET is_banned = 1, banned_reason = ? WHERE id = ?");
            $stmt->bind_param('si', $banReason, $userId);
            $stmt->execute();
            $stmt->close();
            
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_destroy();
            }
        } catch (\Throwable $e) {
            error_log("[SecurityPipeline] Ban user failed: " . $e->getMessage());
        }
    }
    
    /**
     * 發送警報給管理員
     */
    private static function alertAdmins($attackType, $dictResult, $aiResult, $finalScore, $userId, $ip) {
        try {
            if (!file_exists(__DIR__ . '/Mailer.php') || !file_exists(__DIR__ . '/EmailTemplate.php')) return;
            
            require_once __DIR__ . '/Mailer.php';
            require_once __DIR__ . '/EmailTemplate.php';
            
            global $conn;
            if (!$conn) return;
            
            $result = $conn->query("SELECT email, user_name FROM users WHERE type = 'super_admin'");
            if (!$result || $result->num_rows === 0) return;
            
            $typeNames = [
                'sql_injection' => 'SQL 注入攻擊',
                'xss' => 'XSS 跨站腳本攻擊',
                'code_injection' => '惡意代碼注入',
                'path_traversal' => '路徑遍歷攻擊',
                'os_command_injection' => 'OS 命令注入',
                'ssrf' => 'SSRF 攻擊',
                'injection' => '注入攻擊',
                'header_injection' => 'Header 注入',
            ];
            
            $typeName = (string) ($typeNames[$attackType] ?? $attackType);
            $safeTypeName = htmlspecialchars($typeName, ENT_QUOTES, 'UTF-8');
            $time = date('Y-m-d H:i:s');
            $aiDetail = $aiResult ? htmlspecialchars((string) ($aiResult['detail'] ?? ''), ENT_QUOTES, 'UTF-8') : '未經 AI 分析';
            $threatsHtml = htmlspecialchars(implode(', ', $dictResult['threats'] ?? []), ENT_QUOTES, 'UTF-8');
            $safeIp = htmlspecialchars((string) $ip, ENT_QUOTES, 'UTF-8');
            $dictionaryScore = (int) ($dictResult['score'] ?? 0);
            $aiScore = isset($aiResult['score']) ? (int) $aiResult['score'] : 'N/A';
            $safeFinalScore = (int) $finalScore;
            $securityLogUrl = rtrim((string) (defined('BASE_URL') ? BASE_URL : ''), '/') . '/admin/monitoring_security.php?tab=security_events';
            
            $emailContent = "
                <div style='background: #fef2f2; border: 2px solid #ef4444; border-radius: 8px; padding: 20px;'>
                    <h2 style='color: #dc2626; margin: 0 0 10px 0;'>資安防護系統警報：{$safeTypeName}</h2>
                    <p style='margin: 0; color: #991b1b;'>資安防護系統偵測到惡意行為（分數: {$safeFinalScore}/100）</p>
                </div>
                <table style='width: 100%; border-collapse: collapse; margin: 20px 0;'>
                    <tr><td style='padding: 12px; border: 1px solid #e2e8f0; font-weight: 600;'>時間</td><td style='padding: 12px; border: 1px solid #e2e8f0;'>{$time}</td></tr>
                    <tr><td style='padding: 12px; border: 1px solid #e2e8f0; font-weight: 600;'>字典分數</td><td style='padding: 12px; border: 1px solid #e2e8f0;'>{$dictionaryScore}</td></tr>
                    <tr><td style='padding: 12px; border: 1px solid #e2e8f0; font-weight: 600;'>AI 分數</td><td style='padding: 12px; border: 1px solid #e2e8f0;'>{$aiScore}</td></tr>
                    <tr><td style='padding: 12px; border: 1px solid #e2e8f0; font-weight: 600;'>最終分數</td><td style='padding: 12px; border: 1px solid #e2e8f0;'>{$safeFinalScore}</td></tr>
                    <tr><td style='padding: 12px; border: 1px solid #e2e8f0; font-weight: 600;'>威脅特徵</td><td style='padding: 12px; border: 1px solid #e2e8f0;'>{$threatsHtml}</td></tr>
                    <tr><td style='padding: 12px; border: 1px solid #e2e8f0; font-weight: 600;'>AI 說明</td><td style='padding: 12px; border: 1px solid #e2e8f0;'>{$aiDetail}</td></tr>
                    <tr><td style='padding: 12px; border: 1px solid #e2e8f0; font-weight: 600;'>IP</td><td style='padding: 12px; border: 1px solid #e2e8f0;'>{$safeIp}</td></tr>
                </table>
            ";
            
            $emailBody = EmailTemplate::render(
                '資安防護系統警報：' . $typeName,
                $emailContent,
                [['text' => '查看安全日誌', 'url' => $securityLogUrl, 'color' => 'danger']],
                '#dc2626'
            );
            
            while ($admin = $result->fetch_assoc()) {
                $adminEmail = function_exists('safe_decrypt_sensitive') 
                    ? safe_decrypt_sensitive($admin['email']) 
                    : $admin['email'];
                    
                if ($adminEmail && filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
                    try {
                        $mail = Mailer::create();
                        $mail->addAddress($adminEmail);
                        $mail->Subject = " [資安防護系統] {$typeName} (分數:{$finalScore}) - " . date('m/d H:i');
                        $mail->Body = $emailBody;
                        $mail->send();
                    } catch (\Throwable $e) {
                        Mailer::logFailure('資安警報郵件寄送失敗', $e);
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('[SecurityPipeline] alertAdmins failed (' . get_class($e) . ')');
        }
    }
    
    /**
     * 顯示阻擋頁面（最小化資訊，不洩漏偵測細節給攻擊者）
     */
    private static function renderBlockPage($attackType, $finalScore, $aiResult) {
        // Reuse the native page nonce and policy when database bootstrap already
        // sent them. Standalone callers get the same policy before any output.
        $nonce = $GLOBALS['csp_nonce'] ?? null;
        $nativeNonceReady = function_exists('csp_nonce_attr') && function_exists('csp_nonce')
            && is_string($nonce) && preg_match('/^[a-f0-9]{32}$/D', $nonce) === 1;
        if (!$nativeNonceReady) {
            $nonce = bin2hex(random_bytes(16));
            $GLOBALS['csp_nonce'] = $nonce;
            require_once __DIR__ . '/HttpSecurityPolicy.php';
            HttpSecurityPolicy::send(implode('; ', [
                "default-src 'self'", "script-src 'self' 'nonce-{$nonce}'", "script-src-attr 'none'",
                "style-src 'self' 'nonce-{$nonce}'", "style-src-attr 'unsafe-inline'",
                "font-src 'self' data:", "img-src 'self' data: blob:", "connect-src 'self'",
                "frame-ancestors 'none'", "form-action 'self'", "base-uri 'self'", "object-src 'none'",
            ]));
        }
        $nonceAttribute = $nativeNonceReady ? csp_nonce_attr()
            : ' nonce="' . htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') . '"';
        // BASE_URL is application configuration. Never use a Referer or an
        // incoming return coordinate as a redirect out of this error page.
        $home = defined('BASE_URL') ? rtrim((string) BASE_URL, '/') . '/' : '/';
        $home = htmlspecialchars($home, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: private, no-store');
        echo '<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover"><title>無法完成此要求</title>
        <style' . $nonceAttribute . '>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: linear-gradient(135deg, #1e1e1e 0%, #2d2d2d 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; color: white; }
            .container { text-align: center; padding: 40px; max-width: 500px; }
            .icon { font-size: 80px; margin-bottom: 24px; }
            h1 { font-size: 28px; margin-bottom: 16px; color: #ef4444; }
            p { color: #94a3b8; line-height: 1.6; margin-bottom: 12px; }
            .code { background: rgba(239,68,68,0.2); padding: 8px 16px; border-radius: 8px; display: inline-block; color: #fca5a5; font-family: monospace; margin-top: 20px; }
            a, button { display: inline-flex; align-items: center; justify-content: center; min-height: 44px; margin: 4px; padding: 10px 16px; border: 1px solid #818cf8; border-radius: 8px; background: transparent; color: #c7d2fe; font: inherit; text-decoration: none; cursor: pointer; }
            a:focus-visible, button:focus-visible { outline: 3px solid #c7d2fe; outline-offset: 3px; }
            .return-actions { margin-top: 24px; }
        </style></head><body>
        <div class="container">
            <h1>無法完成此要求</h1>
            <p>此要求已被系統安全防護阻擋。</p>
            <p>您可以返回上一頁確認內容；如需協助，請聯絡管理員。</p>
            <div class="code">ERR_SECURITY_BLOCK</div>
            <div class="return-actions"><button type="button" id="security-back" hidden>返回上一頁</button><a href="' . $home . '">返回租務系統</a></div>
        </div><script' . $nonceAttribute . '>
        (() => {
            const back = document.getElementById("security-back");
            if (window.history.length < 2) return;
            back.hidden = false;
            back.addEventListener("click", () => window.history.back());
        })();
        </script></body></html>';
    }
    
    /**
     * 從資料庫載入閾值設定
     */
    private static function loadThresholds() {
        global $conn;
        if (!$conn) return;
        
        $cacheFile = sys_get_temp_dir() . '/security_pipeline_cache.json';
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 300) {
            $cached = @json_decode(@file_get_contents($cacheFile), true);
            if (isset($cached['block_threshold'])) {
                self::$blockThreshold = max(60, min(100, (int)$cached['block_threshold']));
                self::$banThreshold = max(50, min(100, (int)($cached['ban_threshold'] ?? 80)));
                self::$autoBanEnabled = !empty($cached['auto_ban_enabled']);
                return;
            }
        }
        
        try {
            $result = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('security_risk_threshold', 'security_ban_threshold', 'security_user_auto_ban_enabled')");
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    if ($row['setting_key'] === 'security_risk_threshold') {
                        self::$blockThreshold = max(60, min(100, (int)$row['setting_value']));
                    }
                    if ($row['setting_key'] === 'security_ban_threshold') {
                        self::$banThreshold = max(50, min(100, (int)$row['setting_value']));
                    }
                    if ($row['setting_key'] === 'security_user_auto_ban_enabled') {
                        self::$autoBanEnabled = in_array(
                            strtolower(trim((string)$row['setting_value'])),
                            ['1', 'true', 'yes', 'on'],
                            true
                        );
                    }
                }
            }
            @file_put_contents($cacheFile, json_encode([
                'block_threshold' => self::$blockThreshold,
                'ban_threshold' => self::$banThreshold,
                'auto_ban_enabled' => self::$autoBanEnabled,
            ]));
        } catch (\Throwable $e) {
            // 使用預設值
        }
    }
    
    /**
     * 生成 UUID v4
     */
    private static function generateUUID() {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
