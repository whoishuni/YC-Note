<?php
declare(strict_types=1);
require __DIR__ . '/boot.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

try {
    $service = yc_service();
    YcNote\SecurityBridge::headers($service->config['production']);
    YcNote\SecurityBridge::check($service);
    if ($service->config['production'] && ($_SERVER['HTTPS'] ?? '') !== 'on') throw new YcNote\ApiError(400, '必須使用 HTTPS。');
    $action = $_GET['action'] ?? 'session';
    $method = $_SERVER['REQUEST_METHOD'];
    $allowed = ['login'=>'POST','session'=>'GET','logout'=>'POST','manifest'=>'GET','download'=>'GET','history'=>'GET','upload'=>'PUT','delete'=>'DELETE','restore'=>'POST'];
    if (!isset($allowed[$action])) throw new YcNote\ApiError(404, '找不到此功能。');
    if ($method !== $allowed[$action]) { header('Allow: ' . $allowed[$action]); throw new YcNote\ApiError(405, '無效的請求方式。'); }
    $readJson = static function (): array {
        $body = file_get_contents('php://input', false, null, 0, 8193);
        if ($body === false || strlen($body) > 8192) throw new YcNote\ApiError(413, '請求過大。');
        try { $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR); }
        catch (JsonException) { throw new YcNote\ApiError(422, '無效的 JSON。'); }
        if (!is_array($data)) throw new YcNote\ApiError(422, '無效的 JSON。');
        return $data;
    };
    $integer = static function (mixed $value): int {
        $v = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($v === false) throw new YcNote\ApiError(422, '無效的版本。');
        return $v;
    };
    if ($action === 'login') {
        YcNote\SecurityBridge::maintenance($service);
        $service->rateLimit('login-ip|' . ($_SERVER['REMOTE_ADDR'] ?? ''), 30);
        $data = $readJson();
        YcNote\SecurityBridge::check($service, $data);
        foreach (['username','password','installation_id','device_name','platform'] as $key) if (!is_string($data[$key] ?? null)) throw new YcNote\ApiError(422, '登入資料不完整。');
        $service->rateLimit('login-account|' . get_client_ip() . '|' . mb_strtolower(trim($data['username'])), 5);
        $result = $service->enroll($data['username'], $data['password'], $data['installation_id'], $data['device_name'], $data['platform']);
    } else {
        $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $token = str_starts_with($authorization, 'Bearer ') ? substr($authorization, 7) : '';
        $actor = $service->authenticate($token, !in_array($action, ['session','logout'], true));
        if ($action !== 'logout') YcNote\SecurityBridge::maintenance($service,$actor['yc_role']);
        if (in_array($action,['upload','delete','restore'],true) && !$actor['can_write']) throw new YcNote\ApiError(403,'目前為唯讀模式。');
        $service->rateLimit('api|' . $actor['device_id'], 300, 60);
        $uid = (int) $actor['user_id'];
        $path = $_GET['path'] ?? '';
        if (!is_string($path)) throw new YcNote\ApiError(422, '無效的路徑。');
        switch ($action) {
            case 'session': $result = $service->session($token); break;
            case 'logout': $service->logout($token); $result = ['ok'=>true]; break;
            case 'manifest': $result = ['files'=>$service->manifest($uid)]; break;
            case 'history': $result = ['revisions'=>$service->history($uid, $path)]; break;
            case 'download':
                $file = $service->download($uid, $path, isset($_GET['revision']) ? $integer($_GET['revision']) : null);
                header('Content-Type: application/octet-stream');
                header('Content-Length: ' . $file['size']);
                header('ETag: "' . $file['blob_hash'] . '"');
                header('X-YC-Revision: ' . $file['revision']);
                header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode(basename($path)));
                readfile($file['file']);
                exit;
            case 'upload':
                $base = $integer($_SERVER['HTTP_X_YC_BASE_REVISION'] ?? null);
                $max = $service->config['max_file_bytes'];
                if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $max) throw new YcNote\ApiError(413, '檔案過大。');
                $bytes = file_get_contents('php://input', false, null, 0, $max + 1);
                if ($bytes === false) throw new RuntimeException('Cannot read upload.');
                $result = $service->write($actor, $path, $base, $bytes);
                break;
            case 'delete':
                $result = $service->write($actor, $path, $integer($_SERVER['HTTP_X_YC_BASE_REVISION'] ?? null), null);
                break;
            case 'restore':
                $data = $readJson();
                $file = $service->download($uid, $path, $integer($data['revision'] ?? null));
                $bytes = file_get_contents($file['file']);
                if ($bytes === false) throw new RuntimeException('Cannot read revision.');
                $result = $service->write($actor, $path, $integer($data['base_revision'] ?? null), $bytes);
                break;
        }
    }
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
} catch (YcNote\ApiError $e) {
    http_response_code($e->status);
    echo json_encode(['error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[YC Note] type=' . get_class($e) . ' code=' . (int)$e->getCode());
    http_response_code(503);
    echo json_encode(['error'=>'服務暫時無法使用，請稍後再試。'], JSON_UNESCAPED_UNICODE);
}
