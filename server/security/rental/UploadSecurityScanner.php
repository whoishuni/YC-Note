<?php

/**
 * 上傳檔案的共用安全掃描。
 *
 * 格式、MIME、容器結構與基本惡意特徵永遠檢查。ClamAV 是可選的
 * 額外檢查；若正式環境設定必須使用，未取得可信結果時才維持隔離。
 */
final class UploadSecurityScanner
{
    private const CHUNK_BYTES = 1048576;
    private const OVERLAP_BYTES = 256;
    private static $engineStatusCache = null;

    /**
     * 將檢查結果轉成下載閘門狀態。不接受只有 clean 文字、
     * 但沒有可接受引擎來源的結果。
     */
    public static function reviewStatusForScan($scanStatus, $scanEngine = '')
    {
        return self::isReleaseableResult($scanStatus, $scanEngine) ? 'approved' : 'pending';
    }

    public static function isReleaseableResult($scanStatus, $scanEngine)
    {
        if (strtolower(trim((string) $scanStatus)) !== 'clean') {
            return false;
        }
        $engine = strtolower(trim((string) $scanEngine));
        if ($engine === 'local-static+clamav') {
            return true;
        }
        return !self::trustedAntivirusRequired() && $engine === 'local-validation';
    }

    public static function scan($path, $originalName = '', $mimeType = '')
    {
        $startedAt = microtime(true);
        $path = (string) $path;
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            return self::withDuration(
                self::blocked('無法讀取暫存檔案，已拒絕上傳。', 'local-static'),
                $startedAt
            );
        }

        $local = self::staticScan($path, (string) $originalName, (string) $mimeType);
        if (empty($local['safe'])) {
            return self::withDuration($local, $startedAt);
        }

        $clam = self::clamAvScan($path);
        if ($clam !== null) {
            if (empty($clam['safe']) || (string) ($clam['status'] ?? '') === 'clean') {
                return self::withDuration($clam, $startedAt);
            }
            if (self::trustedAntivirusRequired()) {
                return self::withDuration($clam, $startedAt);
            }
            return self::withDuration(self::localValidationResult($clam), $startedAt);
        }

        $engine = self::engineStatus();
        if (!self::trustedAntivirusRequired()) {
            return self::withDuration(self::localValidationResult($engine), $startedAt);
        }
        return self::withDuration([
            'safe' => true,
            'status' => 'limited',
            'engine' => !empty($engine['available'])
                ? 'local-static+clamav-untrusted'
                : 'local-static',
            'summary' => '防毒掃描尚未完成。',
            'engine_version' => $engine['version'] ?? null,
            'signature_version' => $engine['signature_version'] ?? null,
            'signature_date' => $engine['signature_date'] ?? null,
        ], $startedAt);
    }

    /**
     * 快速上傳前檢查，不在 HTTP 請求內啟動耗時的防毒程序。
     * 強制防毒模式由隔離區背景工作完成其餘檢查。
     */
    public static function preflight($path, $originalName = '', $mimeType = '')
    {
        $path = (string) $path;
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            return self::blocked('無法讀取暫存檔案，已拒絕上傳。', 'local-static');
        }
        $result = self::staticScan($path, (string) $originalName, (string) $mimeType);
        if (empty($result['safe'])) {
            return $result;
        }
        if (!self::trustedAntivirusRequired()) {
            return self::localValidationResult();
        }
        return [
            'safe' => true,
            'status' => 'limited',
            'engine' => 'local-static',
            'summary' => '防毒掃描尚未完成。',
        ];
    }

    public static function engineStatus()
    {
        if (is_array(self::$engineStatusCache)) {
            return self::$engineStatusCache;
        }
        $binary = self::clamAvBinary();
        if ($binary === null) {
            return self::$engineStatusCache = [
                'available' => false,
                'trusted' => false,
                'engine' => null,
                'reason' => 'binary_unavailable',
                'version' => null,
                'signature_version' => null,
                'signature_date' => null,
            ];
        }
        if (!function_exists('proc_open')) {
            return self::$engineStatusCache = [
                'available' => false,
                'trusted' => false,
                'engine' => 'ClamAV',
                'reason' => 'process_execution_unavailable',
                'version' => null,
                'signature_version' => null,
                'signature_date' => null,
            ];
        }
        $version = self::clamAvVersion($binary);
        if ($version === null) {
            return self::$engineStatusCache = [
                'available' => false,
                'trusted' => false,
                'engine' => 'ClamAV',
                'reason' => 'engine_self_check_failed',
                'version' => null,
                'signature_version' => null,
                'signature_date' => null,
            ];
        }
        $signatureTimestamp = strtotime((string) ($version['signature_date'] ?? ''));
        // A clock-skewed or forged future timestamp must not make stale
        // signatures look fresh forever. One day allows normal host drift.
        if ($signatureTimestamp !== false && $signatureTimestamp > time() + 86400) {
            $signatureTimestamp = false;
        }
        $signatureAgeDays = $signatureTimestamp === false
            ? null
            : max(0, (time() - $signatureTimestamp) / 86400);
        $maximumSignatureAgeDays = max(
            1,
            min(30, (int) self::env('CLAMAV_MAX_SIGNATURE_AGE_DAYS', 7))
        );
        $trusted = $signatureAgeDays !== null
            && $signatureAgeDays <= $maximumSignatureAgeDays
            && trim((string) ($version['signature_version'] ?? '')) !== '';
        return self::$engineStatusCache = [
            'available' => true,
            'trusted' => $trusted,
            'engine' => 'ClamAV',
            'reason' => $trusted
                ? null
                : ($signatureAgeDays === null
                    ? 'signature_metadata_unavailable'
                    : 'signature_database_stale'),
            'version' => $version['version'],
            'signature_version' => $version['signature_version'],
            'signature_date' => $version['signature_date'],
            'signature_age_days' => $signatureAgeDays !== null ? round($signatureAgeDays, 2) : null,
            'maximum_signature_age_days' => $maximumSignatureAgeDays,
        ];
    }

    private static function staticScan($path, $originalName, $mimeType)
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return self::blocked('無法開啟暫存檔案，已拒絕上傳。', 'local-static');
        }

        $first = (string) fread($handle, 16);
        rewind($handle);
        if (self::hasExecutableMagic($first)) {
            fclose($handle);
            return self::blocked('偵測到可執行程式或腳本檔案，已拒絕上傳。', 'local-static');
        }

        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $normalizedMime = strtolower(trim(explode(';', $mimeType, 2)[0]));
        $textLike = str_starts_with($normalizedMime, 'text/')
            || in_array($normalizedMime, [
                'application/json',
                'application/ld+json',
                'application/xml',
                'application/xhtml+xml',
                'image/svg+xml',
                'application/x-httpd-php',
                'application/x-php',
            ], true)
            || in_array($extension, ['txt', 'csv', 'md', 'json', 'xml', 'svg', 'html', 'htm'], true);
        $patterns = [
            'EICAR-STANDARD-ANTIVIRUS-TEST-FILE' => '偵測到防毒測試或病毒特徵',
        ];
        if ($textLike) {
            $patterns += [
                '<?php' => '偵測到 PHP 程式碼',
                '<?=' => '偵測到 PHP 程式碼',
                '<script' => '偵測到可執行網頁腳本',
                'javascript:' => '偵測到可執行網頁腳本',
                'base64_decode(' => '偵測到常見惡意程式碼特徵',
                'shell_exec(' => '偵測到系統命令執行程式碼',
                'passthru(' => '偵測到系統命令執行程式碼',
                'proc_open(' => '偵測到系統命令執行程式碼',
            ];
        }

        $tail = '';
        while (!feof($handle)) {
            $chunk = fread($handle, self::CHUNK_BYTES);
            if ($chunk === false) {
                fclose($handle);
                return self::blocked('掃描檔案時發生讀取錯誤，已拒絕上傳。', 'local-static');
            }
            $haystack = strtolower($tail . $chunk);
            foreach ($patterns as $needle => $message) {
                if (strpos($haystack, strtolower($needle)) !== false) {
                    fclose($handle);
                    return self::blocked($message . '，已拒絕上傳。', 'local-static');
                }
            }
            $tail = substr($haystack, -self::OVERLAP_BYTES);
        }
        fclose($handle);

        return [
            'safe' => true,
            'status' => 'clean',
            'engine' => 'local-static',
            'summary' => '已通過格式、可執行檔特徵與基本內容規則。',
        ];
    }

    private static function clamAvScan($path)
    {
        $binary = self::clamAvBinary();
        if ($binary === null || !function_exists('proc_open')) {
            return null;
        }
        $engineStatus = self::engineStatus();
        if (empty($engineStatus['available']) || empty($engineStatus['trusted'])) {
            return null;
        }

        $command = [$binary, '--no-summary', '--infected', '--stdout', '--', $path];
        $pipes = [];
        $process = @proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes
        );
        if (!is_resource($process)) {
            return null;
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $output = '';
        $lastStatus = null;
        $startedAt = microtime(true);
        $timeout = max(30, min(900, (int) self::env('UPLOAD_SCAN_TIMEOUT_SECONDS', 120)));
        $timedOut = false;

        do {
            $status = proc_get_status($process);
            $lastStatus = $status;
            $output = substr(
                $output . (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]),
                -65536
            );
            if (!$status['running']) {
                break;
            }
            if ((microtime(true) - $startedAt) > $timeout) {
                $timedOut = true;
                proc_terminate($process);
                break;
            }
            usleep(100000);
        } while (true);

        $output = substr(
            $output . (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]),
            -65536
        );
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closedExitCode = proc_close($process);
        $reportedExitCode = is_array($lastStatus) ? (int) ($lastStatus['exitcode'] ?? -1) : -1;
        $exitCode = $closedExitCode >= 0 ? $closedExitCode : $reportedExitCode;

        if ($timedOut) {
            return [
                'safe' => true,
                'status' => 'limited',
                'engine' => 'local-static+clamav-timeout',
                'summary' => '防毒掃描逾時，檔案仍在隔離區，系統稍後會再試。',
                'engine_version' => $engineStatus['version'] ?? null,
                'signature_version' => $engineStatus['signature_version'] ?? null,
                'signature_date' => $engineStatus['signature_date'] ?? null,
            ];
        }
        if ($exitCode === 1 || stripos($output, ' FOUND') !== false) {
            return array_merge(
                self::blocked('ClamAV 偵測到病毒或惡意內容，已拒絕上傳。', 'clamav'),
                [
                    'engine_version' => $engineStatus['version'] ?? null,
                    'signature_version' => $engineStatus['signature_version'] ?? null,
                    'signature_date' => $engineStatus['signature_date'] ?? null,
                ]
            );
        }
        if ($exitCode !== 0) {
            return [
                'safe' => true,
                'status' => 'limited',
                'engine' => 'local-static+clamav-error',
                'summary' => '檔案檢查尚未完成，系統稍後會再試。',
                'engine_version' => $engineStatus['version'] ?? null,
                'signature_version' => $engineStatus['signature_version'] ?? null,
                'signature_date' => $engineStatus['signature_date'] ?? null,
            ];
        }

        return [
            'safe' => true,
            'status' => 'clean',
            'engine' => 'local-static+clamav',
            'summary' => 'ClamAV 已完成掃描，未偵測到惡意內容。',
            'engine_version' => $engineStatus['version'] ?? null,
            'signature_version' => $engineStatus['signature_version'] ?? null,
            'signature_date' => $engineStatus['signature_date'] ?? null,
        ];
    }

    private static function clamAvBinary()
    {
        $configured = trim((string) self::env('CLAMAV_BINARY', ''));
        $candidates = array_filter([
            $configured,
            '/usr/bin/clamscan',
            '/usr/local/bin/clamscan',
            '/opt/homebrew/bin/clamscan',
        ]);
        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    private static function clamAvVersion($binary)
    {
        $pipes = [];
        $process = @proc_open(
            [$binary, '--version'],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes
        );
        if (!is_resource($process)) {
            return null;
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $output = '';
        $lastStatus = null;
        $startedAt = microtime(true);
        $timedOut = false;
        do {
            $lastStatus = proc_get_status($process);
            $output = substr(
                $output . (string) stream_get_contents($pipes[1])
                    . (string) stream_get_contents($pipes[2]),
                -4096
            );
            if (empty($lastStatus['running'])) {
                break;
            }
            if (microtime(true) - $startedAt > 3.0) {
                $timedOut = true;
                proc_terminate($process);
                break;
            }
            usleep(20000);
        } while (true);

        $output = substr(
            $output . (string) stream_get_contents($pipes[1])
                . (string) stream_get_contents($pipes[2]),
            -4096
        );
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closedExitCode = proc_close($process);
        $reportedExitCode = is_array($lastStatus) ? (int) ($lastStatus['exitcode'] ?? -1) : -1;
        $exitCode = $closedExitCode >= 0 ? $closedExitCode : $reportedExitCode;
        if ($timedOut || $exitCode !== 0) {
            return null;
        }

        $line = trim((string) strtok(str_replace(["\r", "\0"], ['', ''], $output), "\n"));
        if (preg_match('/^ClamAV\s+([^\/\s]+)(?:\/([0-9]+)(?:\/(.+))?)?/i', $line, $matches) !== 1) {
            return null;
        }
        return [
            'version' => substr((string) ($matches[1] ?? ''), 0, 50),
            'signature_version' => isset($matches[2]) ? substr((string) $matches[2], 0, 30) : null,
            'signature_date' => isset($matches[3]) ? substr(trim((string) $matches[3]), 0, 100) : null,
        ];
    }

    private static function hasExecutableMagic($bytes)
    {
        if (str_starts_with($bytes, 'MZ') || str_starts_with($bytes, "\x7fELF") || str_starts_with($bytes, '#!')) {
            return true;
        }
        $magic = bin2hex(substr($bytes, 0, 4));
        return in_array($magic, ['feedface', 'feedfacf', 'cefaedfe', 'cffaedfe', 'cafebabe'], true);
    }

    private static function blocked($summary, $engine)
    {
        return [
            'safe' => false,
            'status' => 'blocked',
            'engine' => $engine,
            'summary' => $summary,
        ];
    }

    private static function localValidationResult(array $antivirus = [])
    {
        return [
            'safe' => true,
            'status' => 'clean',
            'engine' => 'local-validation',
            'summary' => '檔案檢查通過。',
            'engine_version' => $antivirus['version'] ?? $antivirus['engine_version'] ?? null,
            'signature_version' => $antivirus['signature_version'] ?? null,
            'signature_date' => $antivirus['signature_date'] ?? null,
        ];
    }

    private static function trustedAntivirusRequired()
    {
        $value = self::env('UPLOAD_REQUIRE_TRUSTED_ANTIVIRUS', false);
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    private static function withDuration(array $result, $startedAt)
    {
        $result['duration_ms'] = max(
            0,
            (int) round((microtime(true) - (float) $startedAt) * 1000)
        );
        return $result;
    }

    private static function env($key, $default = null)
    {
        if (function_exists('config_env')) {
            return config_env($key, $default);
        }
        $value = getenv($key);
        return $value === false || $value === '' ? $default : $value;
    }
}
