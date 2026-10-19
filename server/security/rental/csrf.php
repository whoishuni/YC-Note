<?php
/**
 * CSRF Token 防護
 * 防止跨站請求偽造攻擊
 * 每次驗證成功後自動輪換 Token
 */

/**
 * 生成 CSRF Token
 */
function csrf_token() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * 生成隱藏的 CSRF 欄位
 */
function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . csrf_token() . '">';
}

/**
 * 強制重新產生 CSRF Token（登入、重大操作後呼叫）
 */
function csrf_regenerate() {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}

/**
 * 驗證 CSRF Token
 * 驗證成功後自動輪換 Token，防止重複提交
 */
function csrf_verify($token = null, bool $rotate = true) {
    if ($token === null) {
        $token = $_POST['csrf_token'] ?? '';
    }
    
    if (!is_string($token) || !is_string($_SESSION['csrf_token'] ?? null)
        || $_SESSION['csrf_token'] === '' || !hash_equals($_SESSION['csrf_token'], $token)) {
        $requestPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: 'unknown');
        error_log('[CSRF] verification failed for ' . substr($requestPath, 0, 180));
        return false;
    }
    
    // 驗證成功後輪換 Token（防止 Token 重複使用）
    // Session-only autosaves keep the current form usable; business submissions rotate by default.
    if ($rotate) csrf_regenerate();
    return true;
}

/**
 * Limit a post-failure redirect to a local absolute path.
 */
function csrf_safe_redirect_target($target) {
    $target = trim((string) $target);
    if ($target === '' || preg_match('/[\x00-\x1F\x7F]/', $target) || str_contains($target, '\\')) {
        return '/';
    }

    $parts = parse_url($target);
    if (!is_array($parts)
        || isset($parts['scheme'])
        || isset($parts['host'])
        || isset($parts['user'])
        || isset($parts['pass'])) {
        return '/';
    }

    $path = (string) ($parts['path'] ?? '/');
    if (!str_starts_with($path, '/') || str_starts_with($path, '//')) {
        return '/';
    }

    $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
    return $path . $query;
}

/**
 * 驗證 CSRF 並在失敗時終止執行
 */
function csrf_check() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_verify()) {
            $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
                strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

            if ($isAjax) {
                http_response_code(403);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => false,
                    'error' => '安全驗證失敗，請重新整理頁面後再試。'
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $redirectUrl = csrf_safe_redirect_target($_SERVER['REQUEST_URI'] ?? '/');
            header('Location: ' . $redirectUrl, true, 303);
            exit;
        }
    }
}
?>
