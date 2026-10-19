<?php
declare(strict_types=1);
namespace YcNote;

use PDO;
use RuntimeException;
use Throwable;

final class ApiError extends RuntimeException
{
    public function __construct(public readonly int $status, string $message) { parent::__construct($message); }
}

final class Service
{
    public function __construct(public readonly PDO $db, private PDO $identity, public readonly array $config) {}

    private function query(string $sql, array $params = []): array
    {
        $q = $this->db->prepare($sql);
        $q->execute($params);
        return $q->columnCount() ? $q->fetchAll() : [];
    }

    public function migrate(): void
    {
        $sqlite = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        $id = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT PRIMARY KEY AUTO_INCREMENT';
        $text = $sqlite ? 'TEXT' : 'LONGTEXT';
        foreach ([
            "CREATE TABLE IF NOT EXISTS owners (user_id BIGINT PRIMARY KEY, lock_version BIGINT NOT NULL DEFAULT 0)",
            "CREATE TABLE IF NOT EXISTS devices (id VARCHAR(64) PRIMARY KEY, user_id BIGINT NOT NULL, installation_hash VARCHAR(64) NOT NULL, name VARCHAR(100) NOT NULL, platform VARCHAR(32) NOT NULL, status VARCHAR(16) NOT NULL, created_at BIGINT NOT NULL, last_seen_at BIGINT NOT NULL, UNIQUE(user_id, installation_hash))",
            "CREATE TABLE IF NOT EXISTS tokens (token_hash VARCHAR(64) PRIMARY KEY, device_id VARCHAR(64) NOT NULL, user_id BIGINT NOT NULL, identity_hash VARCHAR(64) NOT NULL, expires_at BIGINT NOT NULL)",
            "CREATE TABLE IF NOT EXISTS files (user_id BIGINT NOT NULL, path_hash VARCHAR(64) NOT NULL, path VARCHAR(1024) NOT NULL, revision BIGINT NOT NULL, blob_hash VARCHAR(64), size BIGINT NOT NULL, deleted INTEGER NOT NULL, updated_at BIGINT NOT NULL, device_id VARCHAR(64) NOT NULL, PRIMARY KEY(user_id, path_hash))",
            "CREATE TABLE IF NOT EXISTS revisions (user_id BIGINT NOT NULL, path_hash VARCHAR(64) NOT NULL, revision BIGINT NOT NULL, blob_hash VARCHAR(64), size BIGINT NOT NULL, deleted INTEGER NOT NULL, created_at BIGINT NOT NULL, device_id VARCHAR(64) NOT NULL, PRIMARY KEY(user_id, path_hash, revision))",
            "CREATE TABLE IF NOT EXISTS audit (id $id, actor_id BIGINT NOT NULL, action VARCHAR(64) NOT NULL, detail $text NOT NULL, created_at BIGINT NOT NULL)",
            "CREATE TABLE IF NOT EXISTS rate_limits (bucket VARCHAR(64) PRIMARY KEY, attempts INTEGER NOT NULL, reset_at BIGINT NOT NULL)",
            "CREATE TABLE IF NOT EXISTS note_accounts (id $id, name VARCHAR(100) NOT NULL UNIQUE, password VARCHAR(255) NOT NULL, type VARCHAR(30) NOT NULL, is_banned INTEGER NOT NULL DEFAULT 0, first_login INTEGER NOT NULL DEFAULT 0, created_at BIGINT NOT NULL)",
            "CREATE TABLE IF NOT EXISTS access_rules (user_id BIGINT PRIMARY KEY, can_write INTEGER NOT NULL DEFAULT 1, removed INTEGER NOT NULL DEFAULT 0, updated_at BIGINT NOT NULL)",
        ] as $sql) {
            $this->db->exec($sql . ($sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin'));
        }
    }

    private function audit(int $actor, string $action, array $detail): void
    {
        $this->query('INSERT INTO audit (actor_id,action,detail,created_at) VALUES (?,?,?,?)', [$actor, $action, json_encode($detail, JSON_THROW_ON_ERROR), time()]);
    }

    public function securityEvent(string $event, array $detail): void { $this->audit(0, 'security.' . $event, $detail); }

    public function ipBlocked(string $ip): bool
    {
        if (empty($this->config['share_ip_blacklist'])) return false;
        $q = $this->identity->prepare('SELECT 1 FROM ip_blacklist WHERE ip_address=? AND (blocked_until IS NULL OR blocked_until>CURRENT_TIMESTAMP) LIMIT 1');
        $q->execute([$ip]);
        return (bool) $q->fetchColumn();
    }

    public function rateLimit(string $key, int $limit = 10, int $seconds = 900): void
    {
        $bucket = hash('sha256', $key . '|' . (int) floor(time() / $seconds));
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $this->query('INSERT INTO rate_limits (bucket,attempts,reset_at) VALUES (?,1,?) ON CONFLICT(bucket) DO UPDATE SET attempts=attempts+1', [$bucket, time() + $seconds]);
        } else {
            $this->query('INSERT INTO rate_limits (bucket,attempts,reset_at) VALUES (?,1,?) ON DUPLICATE KEY UPDATE attempts=attempts+1', [$bucket, time() + $seconds]);
        }
        $attempts = (int) $this->query('SELECT attempts FROM rate_limits WHERE bucket=?', [$bucket])[0]['attempts'];
        if ($attempts > $limit) throw new ApiError(429, '嘗試次數過多，請稍後再試。');
        $this->query('DELETE FROM rate_limits WHERE reset_at<?', [time()]);
    }

    private function identityRow(string $column, string|int $value): ?array
    {
        if ($column === 'id' && (int) $value < 0) {
            $row = $this->query('SELECT id,name,password,type,is_banned,first_login FROM note_accounts WHERE id=?', [-(int) $value])[0] ?? null;
            if ($row) $row['id'] = -(int) $row['id'];
            return $row;
        }
        $table = $this->config['identity_table'];
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $table)) throw new RuntimeException('Invalid identity view name.');
        $q = $this->identity->prepare("SELECT id,name,password,type,is_banned,first_login FROM `$table` WHERE `$column`=? LIMIT 1");
        $q->execute([$value]);
        $row = $q->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$row && $column === 'name') {
            $row = $this->query('SELECT id,name,password,type,is_banned,first_login FROM note_accounts WHERE name=?', [$value])[0] ?? null;
            if ($row) $row['id'] = -(int) $row['id'];
        }
        return $row;
    }

    private function eligible(?array $user): bool
    {
        return $user !== null && empty($user['is_banned']) && empty($user['first_login'])
            && in_array($user['type'], (int) $user['id'] < 0 ? ['super_admin', 'user'] : ['super_admin', 'admin', 'manager'], true)
            && !$this->access((int) $user['id'])['removed']
            && !empty(password_get_info((string) $user['password'])['algo']);
    }

    public function verifyPassword(string $name, string $password): array
    {
        if (strlen($name) > 100 || strlen($password) > 1024) throw new ApiError(401, '帳號或密碼不正確，或帳號尚未完成啟用。');
        $user = $this->identityRow('name', trim($name));
        // Unknown accounts still perform a password derivation. No plaintext fallback.
        $hash = $user['password'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
        $ok = password_verify($password, $hash);
        if (!$ok || !$this->eligible($user)) throw new ApiError(401, '帳號或密碼不正確，或帳號尚未完成啟用。');
        return $user;
    }

    public function identityHash(array $user): string
    {
        return hash('sha256', json_encode([$user['id'], $user['password'], $user['type']], JSON_THROW_ON_ERROR));
    }

    public function webUser(int $id, string $hash, bool $admin = false): array
    {
        $user = $this->identityRow('id', $id);
        if (!$this->eligible($user) || !hash_equals($this->identityHash($user), $hash)) throw new ApiError(401, '登入已失效，請重新登入。');
        if ($admin && $user['type'] !== 'super_admin') throw new ApiError(403, '沒有管理權限。');
        return $user;
    }

    public function isAdmin(int $id): bool
    {
        $user = $this->identityRow('id', $id);
        return $this->eligible($user) && $user['type'] === 'super_admin';
    }

    public function access(int $id): array
    {
        $rule = $this->query('SELECT can_write,removed FROM access_rules WHERE user_id=?', [$id])[0] ?? ['can_write'=>1, 'removed'=>0];
        return ['can_write'=>(bool) $rule['can_write'], 'removed'=>(bool) $rule['removed']];
    }

    public function members(int $adminId): array
    {
        if (!$this->isAdmin($adminId)) throw new ApiError(403, '沒有管理權限。');
        $table = $this->config['identity_table'];
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $table)) throw new RuntimeException('Invalid identity view name.');
        $users = $this->identity->query("SELECT id,name,type,is_banned,first_login FROM `$table` WHERE type IN ('super_admin','admin','manager') ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($users as &$user) {
            $user['id'] = (int) $user['id'];
            $user['source'] = 'shared';
            $user['role'] = $user['type'] === 'super_admin' ? 'super_admin' : 'user';
            $user += $this->access($user['id']);
        }
        unset($user);
        foreach ($this->query('SELECT id,name,type,is_banned,first_login FROM note_accounts ORDER BY id') as $user) {
            $user['id'] = -(int) $user['id'];
            $user['source'] = 'local';
            $user['role'] = $user['type'];
            $users[] = $user + $this->access($user['id']);
        }
        return $users;
    }

    public function createAccount(int $adminId, string $name, string $password, string $role = 'user'): int
    {
        if (!$this->isAdmin($adminId)) throw new ApiError(403, '沒有管理權限。');
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.@-]{2,99}$/D', $name) || !in_array($role, ['user','super_admin'], true)) throw new ApiError(422, '帳號格式或角色不正確。');
        $errors = function_exists('validate_password_strength') ? \validate_password_strength($password, [$name]) : [];
        if ($errors || strlen($password) < 10 || strlen($password) > 72 || !preg_match('/[a-z]/', $password) || !preg_match('/[A-Z]/', $password) || !preg_match('/[0-9]/', $password) || !preg_match('/[^A-Za-z0-9]/', $password)) throw new ApiError(422, '密碼需為 10–72 字元，包含大小寫字母、數字與符號。');
        if ($this->identityRow('name', $name)) throw new ApiError(409, '帳號已存在。');
        try {
            $this->query('INSERT INTO note_accounts (name,password,type,created_at) VALUES (?,?,?,?)', [$name, password_hash($password, PASSWORD_BCRYPT, ['cost'=>12]), $role, time()]);
        } catch (\PDOException $e) { if (str_starts_with((string) $e->getCode(), '23')) throw new ApiError(409, '帳號已存在。'); throw $e; }
        $id = -(int) $this->db->lastInsertId();
        $this->audit($adminId, 'user.created', ['user_id'=>$id,'role'=>$role]);
        return $id;
    }

    public function setAccess(int $adminId, int $target, bool $canWrite, bool $removed = false, ?string $role = null): void
    {
        if (!$this->isAdmin($adminId)) throw new ApiError(403, '沒有管理權限。');
        $targetUser = $this->identityRow('id', $target);
        if ($role !== null && ($targetUser['type'] ?? null) === $role) $role = null;
        if ($target === $adminId && ($removed || $role !== null)) throw new ApiError(409, '不能刪除或變更自己的管理角色。');
        if (!in_array($role, [null,'user','super_admin'], true) || ($target > 0 && $role !== null)) throw new ApiError(422, '共用帳號的角色由原系統決定。');
        if (!$this->identityRow('id', $target)) throw new ApiError(404, '找不到使用者。');
        $this->db->beginTransaction();
        try {
            $this->lockOwner($target);
            $existing = $this->query('SELECT user_id FROM access_rules WHERE user_id=?', [$target]);
            if ($existing) $this->query('UPDATE access_rules SET can_write=?,removed=?,updated_at=? WHERE user_id=?', [(int)$canWrite,(int)$removed,time(),$target]);
            else $this->query('INSERT INTO access_rules (can_write,removed,updated_at,user_id) VALUES (?,?,?,?)', [(int)$canWrite,(int)$removed,time(),$target]);
            if ($role !== null) $this->query('UPDATE note_accounts SET type=? WHERE id=?', [$role,-$target]);
            if ($removed || $role !== null) $this->query('DELETE FROM tokens WHERE user_id=?', [$target]);
            $this->audit($adminId, $removed ? 'user.removed' : 'user.permissions', ['user_id'=>$target,'can_write'=>$canWrite,'role'=>$role]);
            $this->db->commit();
        } catch (Throwable $e) { $this->db->rollBack(); throw $e; }
    }

    public function enroll(string $name, string $password, string $installation, string $deviceName, string $platform): array
    {
        try { $user = $this->verifyPassword($name, $password); }
        catch (ApiError $e) { $this->securityEvent('login_failed',['account_hash'=>hash('sha256',mb_strtolower(trim($name)))]); throw $e; }
        if (!preg_match('/^[0-9a-f]{64}$/D', $installation) || mb_strlen($deviceName) < 1 || mb_strlen($deviceName) > 100 || !preg_match('/^[a-zA-Z0-9_-]{1,32}$/D', $platform)) {
            throw new ApiError(422, '裝置資料不完整。');
        }
        $uid = (int) $user['id'];
        $this->db->beginTransaction();
        try {
            $this->lockOwner($uid);
            $device = $this->query('SELECT * FROM devices WHERE user_id=? AND installation_hash=?', [$uid, hash('sha256', $installation)])[0] ?? null;
            if ($device && $device['status'] === 'revoked') throw new ApiError(403, '這台裝置已被撤銷，請聯絡管理者。');
            $id = $device['id'] ?? bin2hex(random_bytes(16));
            if (!$device) {
                $count = (int) $this->query('SELECT COUNT(*) AS n FROM devices WHERE user_id=?', [$uid])[0]['n'];
                if ($count >= 30) throw new ApiError(409, '裝置數量已達上限，請聯絡管理者。');
                $this->query('INSERT INTO devices (id,user_id,installation_hash,name,platform,status,created_at,last_seen_at) VALUES (?,?,?,?,?,?,?,?)', [$id, $uid, hash('sha256', $installation), $deviceName, $platform, 'pending', time(), time()]);
                $this->audit($uid, 'device.requested', ['device_id' => $id]);
            }
            $token = bin2hex(random_bytes(32));
            $this->query('DELETE FROM tokens WHERE device_id=?', [$id]);
            $this->query('INSERT INTO tokens (token_hash,device_id,user_id,identity_hash,expires_at) VALUES (?,?,?,?,?)', [hash('sha256', $token), $id, $uid, $this->identityHash($user), time() + $this->config['token_lifetime_seconds']]);
            $this->audit($uid,'login.success',['device_id'=>$id]);
            $this->db->commit();
        } catch (Throwable $e) { $this->db->rollBack(); throw $e; }
        return ['token' => $token] + $this->session($token);
    }

    public function authenticate(string $token, bool $approved = true): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) throw new ApiError(401, '請先登入。');
        $row = $this->query('SELECT t.*, d.status, d.name AS device_name, d.platform FROM tokens t JOIN devices d ON d.id=t.device_id AND d.user_id=t.user_id WHERE t.token_hash=? AND t.expires_at>?', [hash('sha256', $token), time()])[0] ?? null;
        if (!$row) throw new ApiError(401, '登入已過期，請重新登入。');
        $user = $this->webUser((int) $row['user_id'], $row['identity_hash']);
        if ($row['status'] === 'revoked') throw new ApiError(403, '這台裝置已被撤銷。');
        if ($approved && $row['status'] !== 'approved') throw new ApiError(403, '請先由管理者核准這台裝置。');
        $row['username'] = $user['name'];
        $row['yc_role'] = $user['type'] === 'super_admin' ? 'super_admin' : 'user';
        $row['can_write'] = $this->access((int)$row['user_id'])['can_write'];
        return $row;
    }

    public function session(string $token): array
    {
        $a = $this->authenticate($token, false);
        $this->query('UPDATE devices SET last_seen_at=? WHERE id=?', [time(), $a['device_id']]);
        return ['user_id' => (int) $a['user_id'], 'username' => $a['username'], 'yc_role' => $a['yc_role'], 'can_write'=>$a['can_write'], 'device_id' => $a['device_id'], 'status' => $a['status'], 'expires_at' => (int) $a['expires_at'], 'offline_lease_seconds' => (int) $this->config['offline_lease_seconds']];
    }

    public function logout(string $token): void { $this->query('DELETE FROM tokens WHERE token_hash=?', [hash('sha256', $token)]); }

    public function setDeviceStatus(int $adminId, string $id, string $status): void
    {
        if (!$this->isAdmin($adminId)) throw new ApiError(403, '沒有管理權限。');
        if (!in_array($status, ['approved', 'revoked'], true)) throw new ApiError(422, '無效的認證狀態。');
        $this->db->beginTransaction();
        try {
            $device = $this->query('SELECT * FROM devices WHERE id=?', [$id])[0] ?? null;
            if (!$device) throw new ApiError(404, '找不到裝置。');
            $this->lockOwner((int) $device['user_id']);
            $this->query('UPDATE devices SET status=? WHERE id=?', [$status, $id]);
            if ($status === 'revoked') $this->query('DELETE FROM tokens WHERE device_id=?', [$id]);
            $this->audit($adminId, 'device.' . $status, ['device_id' => $id, 'user_id' => (int) $device['user_id']]);
            $this->db->commit();
        } catch (Throwable $e) { $this->db->rollBack(); throw $e; }
    }

    private function lockOwner(int $uid): void
    {
        $sqlite = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        $this->query($sqlite ? 'INSERT OR IGNORE INTO owners (user_id) VALUES (?)' : 'INSERT IGNORE INTO owners (user_id) VALUES (?)', [$uid]);
        $this->query('UPDATE owners SET lock_version=lock_version+1 WHERE user_id=?', [$uid]);
    }

    public static function validPath(string $path): string
    {
        if (strlen($path) > 1000 || !mb_check_encoding($path, 'UTF-8') || $path === '' || $path[0] !== '/' || preg_match('/[\\\\\x00-\x1f\x7f]/', $path)) throw new ApiError(422, '無效的檔案路徑。');
        foreach (explode('/', substr($path, 1)) as $part) {
            if ($part === '' || $part === '.' || $part === '..' || $part[0] === '.') throw new ApiError(422, '無效的檔案路徑。');
        }
        return $path;
    }

    public function manifest(int $uid): array
    {
        $files = $this->query('SELECT path,revision,blob_hash,size,deleted,updated_at,device_id FROM files WHERE user_id=? ORDER BY path', [$uid]);
        foreach ($files as &$f) foreach (['revision','size','deleted','updated_at'] as $key) $f[$key] = (int) $f[$key];
        return $files;
    }

    private function blobPath(int $uid, string $hash): string { return $this->config['storage_path'] . '/' . $uid . '/' . $hash; }

    public function write(array $actor, string $path, int $baseRevision, ?string $bytes): array
    {
        self::validPath($path);
        if ($baseRevision < 0) throw new ApiError(422, '無效的版本。');
        if ($bytes !== null && strlen($bytes) > $this->config['max_file_bytes']) throw new ApiError(413, '單一檔案超過大小上限。');
        if (!$this->access((int)$actor['user_id'])['can_write']) throw new ApiError(403, '目前為唯讀模式。');
        if ($bytes !== null && class_exists(SecurityBridge::class)) SecurityBridge::scan($bytes, $path);
        $uid = (int) $actor['user_id'];
        $this->db->beginTransaction();
        try {
            $this->lockOwner($uid);
            if (!$this->access($uid)['can_write'] || $this->access($uid)['removed']) throw new ApiError(403, '目前為唯讀模式。');
            // Recheck authorization after taking the same lock as revocation.
            $d = $this->query('SELECT status FROM devices WHERE id=? AND user_id=?', [$actor['device_id'], $uid])[0] ?? null;
            if (!$d || $d['status'] !== 'approved') throw new ApiError(403, '裝置未通過認證。');
            $pathHash = hash('sha256', $path);
            $old = $this->query('SELECT * FROM files WHERE user_id=? AND path_hash=?', [$uid, $pathHash])[0] ?? null;
            if ((int) ($old['revision'] ?? 0) !== $baseRevision) throw new ApiError(409, '此筆記已被其他裝置更新，請先同步。');
            $size = $bytes === null ? 0 : strlen($bytes);
            $used = (int) $this->query('SELECT COALESCE(SUM(size),0) AS n FROM revisions WHERE user_id=?', [$uid])[0]['n'];
            if ($used + $size > $this->config['quota_bytes']) throw new ApiError(507, '儲存空間不足，請聯絡管理者。');
            $hash = $bytes === null ? null : hash('sha256', $bytes);
            if ($hash !== null) {
                $target = $this->blobPath($uid, $hash);
                if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0700, true) && !is_dir(dirname($target))) throw new RuntimeException('Cannot create blob directory.');
                if (!is_file($target)) {
                    $temp = tempnam(dirname($target), '.upload-');
                    try {
                        if ($temp === false || file_put_contents($temp, $bytes, LOCK_EX) !== $size || !chmod($temp, 0600) || !rename($temp, $target)) throw new RuntimeException('Cannot store blob.');
                    } finally { if ($temp && is_file($temp)) unlink($temp); }
                }
            }
            $revision = $baseRevision + 1;
            $values = [$revision, $hash, $size, (int) ($bytes === null), time(), $actor['device_id'], $uid, $pathHash];
            if ($old) {
                $this->query('UPDATE files SET revision=?,blob_hash=?,size=?,deleted=?,updated_at=?,device_id=? WHERE user_id=? AND path_hash=?', $values);
            } else {
                $this->query('INSERT INTO files (revision,blob_hash,size,deleted,updated_at,device_id,user_id,path_hash,path) VALUES (?,?,?,?,?,?,?,?,?)', [...$values, $path]);
            }
            $this->query('INSERT INTO revisions (revision,blob_hash,size,deleted,created_at,device_id,user_id,path_hash) VALUES (?,?,?,?,?,?,?,?)', $values);
            $this->audit($uid, $bytes === null ? 'file.deleted' : 'file.written', ['path_hash' => $pathHash, 'revision' => $revision, 'device_id' => $actor['device_id']]);
            $this->db->commit();
            return ['revision' => $revision, 'blob_hash' => $hash, 'size' => $size, 'deleted' => (int) ($bytes === null)];
        } catch (Throwable $e) { $this->db->rollBack(); throw $e; }
    }

    public function download(int $uid, string $path, ?int $revision = null): array
    {
        self::validPath($path);
        $row = $revision === null
            ? ($this->query('SELECT * FROM files WHERE user_id=? AND path_hash=?', [$uid, hash('sha256', $path)])[0] ?? null)
            : ($this->query('SELECT * FROM revisions WHERE user_id=? AND path_hash=? AND revision=?', [$uid, hash('sha256', $path), $revision])[0] ?? null);
        if (!$row || (int) $row['deleted'] === 1 || !$row['blob_hash']) throw new ApiError(404, '找不到檔案。');
        $file = $this->blobPath($uid, $row['blob_hash']);
        if (!is_file($file)) throw new RuntimeException('Stored blob is missing.');
        return ['file' => $file, 'revision' => (int) $row['revision'], 'size' => (int) $row['size'], 'blob_hash' => $row['blob_hash']];
    }

    public function history(int $uid, string $path): array
    {
        self::validPath($path);
        return $this->query('SELECT revision,size,deleted,created_at,device_id FROM revisions WHERE user_id=? AND path_hash=? ORDER BY revision DESC LIMIT 100', [$uid, hash('sha256', $path)]);
    }

    public function devices(int $uid, bool $all = false): array
    {
        if ($all && !$this->isAdmin($uid)) throw new ApiError(403, '沒有管理權限。');
        return $this->query('SELECT id,user_id,name,platform,status,created_at,last_seen_at FROM devices' . ($all ? '' : ' WHERE user_id=?') . ' ORDER BY created_at DESC', $all ? [] : [$uid]);
    }

    public function auditLog(int $adminId): array
    {
        if (!$this->isAdmin($adminId)) throw new ApiError(403, '沒有管理權限。');
        return $this->query('SELECT actor_id,action,detail,created_at FROM audit ORDER BY id DESC LIMIT 100');
    }
}
