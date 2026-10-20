<?php
declare(strict_types=1);
namespace YcNote;

use Throwable;
use RuntimeException;

/** The subset of Login Flow V2 and WebDAV used by upstream Saber 1.36.2.
 * Cloud credentials never authenticate the legacy API. Ciphertext stays opaque.
 */
trait SaberCloud
{
    private function migrateSaber(): void
    {
        $suffix=$this->db->getAttribute(\PDO::ATTR_DRIVER_NAME)==='sqlite'?'':' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin';
        foreach ([
            'CREATE TABLE IF NOT EXISTS saber_login_flows (id VARCHAR(32) PRIMARY KEY, poll_hash VARCHAR(64) NOT NULL UNIQUE, credential_hash VARCHAR(64) NOT NULL, user_id BIGINT, identity_hash VARCHAR(64), device_id VARCHAR(64), agent VARCHAR(200) NOT NULL, expires_at BIGINT NOT NULL, consumed INTEGER NOT NULL DEFAULT 0, created_at BIGINT NOT NULL)',
            'CREATE TABLE IF NOT EXISTS saber_credentials (token_hash VARCHAR(64) PRIMARY KEY, user_id BIGINT NOT NULL, device_id VARCHAR(64) NOT NULL, identity_hash VARCHAR(64) NOT NULL, expires_at BIGINT NOT NULL)',
            'CREATE TABLE IF NOT EXISTS saber_heads (user_id BIGINT PRIMARY KEY, generation BIGINT NOT NULL DEFAULT 0)',
            'CREATE TABLE IF NOT EXISTS saber_nodes (user_id BIGINT NOT NULL, path_hash VARCHAR(64) NOT NULL, path VARCHAR(1024) NOT NULL, is_collection INTEGER NOT NULL, revision BIGINT NOT NULL, generation BIGINT NOT NULL, blob_hash VARCHAR(64), size BIGINT NOT NULL, deleted INTEGER NOT NULL, mtime BIGINT NOT NULL, updated_at BIGINT NOT NULL, device_id VARCHAR(64) NOT NULL, PRIMARY KEY(user_id,path_hash))',
            'CREATE TABLE IF NOT EXISTS saber_revisions (user_id BIGINT NOT NULL, path_hash VARCHAR(64) NOT NULL, path VARCHAR(1024) NOT NULL, is_collection INTEGER NOT NULL, revision BIGINT NOT NULL, generation BIGINT NOT NULL, blob_hash VARCHAR(64), size BIGINT NOT NULL, deleted INTEGER NOT NULL, mtime BIGINT NOT NULL, updated_at BIGINT NOT NULL, device_id VARCHAR(64) NOT NULL, PRIMARY KEY(user_id,path_hash,revision))',
        ] as $sql) $this->db->exec($sql.$suffix);
    }

    public function saberBaseUrl(): string
    {
        $url=rtrim((string)($this->config['saber_base_url']??'https://yckuo.com/rent/yc_note/saber'),'/');
        $parts=parse_url($url);
        if (!$parts || !isset($parts['host'],$parts['scheme']) || (isset($parts['user']) || isset($parts['pass'])) || isset($parts['query']) || isset($parts['fragment']) || ($this->config['production'] && $parts['scheme']!=='https') || !in_array($parts['scheme'],['http','https'],true)) throw new RuntimeException('Invalid canonical cloud URL');
        return $url;
    }

    public function saberConnectUrl(): string
    {
        $parts=parse_url($this->saberBaseUrl());
        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port'])?':'.$parts['port']:'').'/rent/admin/notes_connect.php';
    }

    private static function saberPassword(string $poll): string
    {
        return hash_hmac('sha256','YC Note Saber credential v1',$poll);
    }

    public function startSaberLogin(string $agent): array
    {
        // Expiry pruning does not touch approved credentials or stored notes.
        $this->query('DELETE FROM saber_login_flows WHERE expires_at<?',[time()]);
        $id=bin2hex(random_bytes(16));$poll=bin2hex(random_bytes(32));
        $agent=mb_substr(preg_replace('/[\x00-\x1f\x7f]/u','',mb_convert_encoding($agent,'UTF-8','UTF-8'))??'',0,200);
        $this->query('INSERT INTO saber_login_flows (id,poll_hash,credential_hash,agent,expires_at,created_at) VALUES (?,?,?,?,?,?)',[$id,hash('sha256',$poll),hash('sha256',self::saberPassword($poll)),$agent,time()+1200,time()]);
        return ['poll'=>['token'=>$poll,'endpoint'=>$this->saberBaseUrl().'/index.php/login/v2/poll'],'login'=>$this->saberConnectUrl().'?flow='.$id];
    }

    public function saberLoginInfo(string $flow,int $uid): array
    {
        $this->rentalUser($uid);
        if (!preg_match('/^[a-f0-9]{32}$/D',$flow)) throw new ApiError(404,'連線要求已失效，請由 Saber 重新登入。');
        $f=$this->query('SELECT * FROM saber_login_flows WHERE id=? AND expires_at>?',[$flow,time()])[0]??null;
        if (!$f || ($f['user_id']!==null && (int)$f['user_id']!==$uid)) throw new ApiError(404,'連線要求已失效，請由 Saber 重新登入。');
        $device=$f['device_id']?$this->query('SELECT status FROM devices WHERE id=? AND user_id=?',[$f['device_id'],$uid])[0]??null:null;
        return ['agent'=>$f['agent'],'status'=>$f['consumed']?'complete':($device['status']??'new'),'device_id'=>$f['device_id'],'expires_at'=>(int)$f['expires_at']];
    }

    /** Called only by the native rental browser controller after CSRF validation. */
    public function grantSaberLogin(string $flow,int $uid,string $fingerprint,bool $approve): void
    {
        $user=$this->identityRow('id',$uid);
        if (!$this->eligible($user) || !hash_equals(hash('sha256','rental-auth-session-v1|'.(string)$user['password']),$fingerprint)) throw new ApiError(401,'登入已失效，請重新登入。');
        if ($approve && !$this->isAdmin($uid)) throw new ApiError(403,'沒有核准權限。');
        $this->db->beginTransaction();
        try {
            $this->lockOwner($uid);
            $info=$this->saberLoginInfo($flow,$uid);
            $this->webUser($uid,$this->identityHash($user));
            if ($info['status']!=='new') throw new ApiError(409,'此連線已申請。');
            $count=(int)$this->query('SELECT COUNT(*) AS n FROM devices WHERE user_id=?',[$uid])[0]['n'];
            if ($count>=30) throw new ApiError(409,'連線數量已達上限，請聯絡管理者。');
            $device=bin2hex(random_bytes(16));
            $platform=preg_match('/\((ios|macos|android|windows|linux)\)/i',$info['agent'],$m)?strtolower($m[1]):'client';
            $name='Saber · '.(['ios'=>'iPad / iPhone','macos'=>'Mac','android'=>'Android','windows'=>'Windows','linux'=>'Linux'][$platform]??'App');
            $q=$this->db->prepare('UPDATE saber_login_flows SET user_id=?,identity_hash=?,device_id=? WHERE id=? AND user_id IS NULL AND consumed=0 AND expires_at>?');
            $q->execute([$uid,$this->identityHash($user),$device,$flow,time()]);
            if ($q->rowCount()!==1) throw new ApiError(409,'此連線已申請。');
            $this->query('INSERT INTO devices (id,user_id,installation_hash,name,platform,status,created_at,last_seen_at) VALUES (?,?,?,?,?,?,?,?)',[$device,$uid,hash('sha256','saber:'.$flow),$name,'saber_'.$platform,$approve?'approved':'pending',time(),time()]);
            $this->audit($uid,'device.requested',['device_id'=>$device,'client'=>'saber']);
            if ($approve) $this->audit($uid,'device.approved',['device_id'=>$device,'user_id'=>$uid]);
            $this->db->commit();
        } catch (Throwable $e) { $this->db->rollBack();throw $e; }
    }

    public function pollSaberLogin(string $poll): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D',$poll)) throw new ApiError(404,'等待核准或連線已失效。');
        $hash=hash('sha256',$poll);
        $f=$this->query('SELECT * FROM saber_login_flows WHERE poll_hash=? AND expires_at>? AND consumed=0',[$hash,time()])[0]??null;
        if (!$f || !$f['user_id']) throw new ApiError(404,'等待核准或連線已失效。');
        $this->db->beginTransaction();
        try {
            $this->lockOwner((int)$f['user_id']);
            $f=$this->query('SELECT * FROM saber_login_flows WHERE poll_hash=? AND expires_at>? AND consumed=0',[$hash,time()])[0]??null;
            if (!$f) throw new ApiError(404,'等待核准或連線已失效。');
            $this->webUser((int)$f['user_id'],$f['identity_hash']);
            $device=$this->query('SELECT status FROM devices WHERE id=? AND user_id=?',[$f['device_id'],$f['user_id']])[0]??null;
            if (!$device || $device['status']!=='approved') throw new ApiError(404,'等待核准或連線已失效。');
            $this->query('INSERT INTO saber_credentials (token_hash,user_id,device_id,identity_hash,expires_at) VALUES (?,?,?,?,?)',[$f['credential_hash'],$f['user_id'],$f['device_id'],$f['identity_hash'],time()+$this->saberIdleSeconds()]);
            $this->query('UPDATE saber_login_flows SET consumed=1 WHERE id=?',[$f['id']]);
            $this->audit((int)$f['user_id'],'saber.login',['device_id'=>$f['device_id']]);
            $this->db->commit();
            return ['server'=>$this->saberBaseUrl(),'loginName'=>'rent-'.$f['user_id'],'appPassword'=>self::saberPassword($poll)];
        } catch (Throwable $e) { $this->db->rollBack();throw $e; }
    }

    private function saberIdleSeconds(): int { return max(86400,min(180*86400,(int)($this->config['approved_token_idle_seconds']??90*86400))); }

    private function saberCredential(string $hash): array
    {
        $a=$this->query('SELECT c.*,d.status,d.platform FROM saber_credentials c JOIN devices d ON d.id=c.device_id AND d.user_id=c.user_id WHERE c.token_hash=? AND c.expires_at>?',[$hash,time()])[0]??null;
        if (!$a || $a['status']!=='approved') throw new ApiError(401,'雲端連線已失效，請重新登入。');
        $u=$this->webUser((int)$a['user_id'],$a['identity_hash']);
        return $a+['username'=>$u['name'],'yc_role'=>$u['type']==='super_admin'?'super_admin':'user','can_write'=>$this->canWrite($u)];
    }

    public function authenticateSaber(string $login,string $password,bool $bearer=false): array
    {
        if ((!$bearer && !preg_match('/^rent-[1-9][0-9]*$/D',$login)) || !preg_match('/^[a-f0-9]{64}$/D',$password)) throw new ApiError(401,'請由 Saber 登入租屋系統。');
        $hash=hash('sha256',$password);$a=$this->saberCredential($hash);
        if (!$bearer && !hash_equals('rent-'.$a['user_id'],$login)) throw new ApiError(401,'雲端連線已失效。');
        // UPDATE cannot recreate a revoked credential. Expired credentials cannot renew.
        $this->query('UPDATE saber_credentials SET expires_at=? WHERE token_hash=? AND expires_at>?',[time()+$this->saberIdleSeconds(),$hash,time()]);
        $this->query('UPDATE devices SET last_seen_at=? WHERE id=?',[time(),$a['device_id']]);
        return $a;
    }

    private function forgetSaberCredentials(int $uid,?string $device=null): void
    {
        $where=$device===null?'user_id=?':'user_id=? AND device_id=?';$params=$device===null?[$uid]:[$uid,$device];
        $this->query('DELETE FROM saber_credentials WHERE '.$where,$params);
        // Re-approving an old device must not activate an old pending grant.
        $this->query('UPDATE saber_login_flows SET consumed=1 WHERE '.$where,$params);
    }

    public function logoutSaber(array $actor): void
    {
        $this->revokeOwnDevice((int)$actor['user_id'],$actor['device_id']);
    }

    public static function saberPath(string $path): string
    {
        if ($path==='' || $path==='/') return '';
        if ($path==='Saber' || $path==='Saber/') return 'Saber';
        if ($path==='Saber/config.sbc') return $path;
        if (strlen($path)<=1000 && preg_match('~^Saber/(?:[a-fA-F0-9]{2})+\.sbe$~D',$path)) return $path;
        throw new ApiError(403,'無法存取此路徑。');
    }

    public static function saberEtag(array $node): string { return '"'.$node['revision'].'-'.($node['blob_hash']??'collection').'"'; }

    public function saberNode(int $uid,string $path): array
    {
        $path=self::saberPath($path);
        if ($path==='') return ['path'=>'','is_collection'=>1,'size'=>0,'revision'=>0,'mtime'=>0,'blob_hash'=>null];
        $node=$this->query('SELECT * FROM saber_nodes WHERE user_id=? AND path_hash=? AND deleted=0',[$uid,hash('sha256',$path)])[0]??null;
        if (!$node) throw new ApiError(404,'檔案不存在。');
        return $node;
    }

    public function saberListing(int $uid,string $path,bool $children): array
    {
        $root=$this->saberNode($uid,$path);$nodes=[$root];
        if ($children && $root['is_collection']) {
            $rows=$this->query('SELECT * FROM saber_nodes WHERE user_id=? AND deleted=0 ORDER BY path',[$uid]);
            foreach ($rows as $row) if (($path==='' && $row['path']==='Saber') || ($path==='Saber' && $row['path']!=='Saber')) $nodes[]=$row;
        }
        return $nodes;
    }

    /** Includes retained encrypted history, not only current heads. */
    private function saberRetainedBytes(int $uid): int
    {
        return (int)$this->query('SELECT COALESCE(SUM(size),0) AS n FROM saber_revisions WHERE user_id=?',[$uid])[0]['n'];
    }

    private function totalRetainedBytes(int $uid): int
    {
        return $this->saberRetainedBytes($uid)+(int)$this->query('SELECT COALESCE(SUM(size),0) AS n FROM revisions WHERE user_id=?',[$uid])[0]['n'];
    }

    public function saberStats(int $uid): array
    {
        $this->rentalUser($uid);
        $head=$this->query('SELECT COUNT(*) AS files,COALESCE(SUM(size),0) AS bytes,MAX(updated_at) AS updated_at FROM saber_nodes WHERE user_id=? AND deleted=0 AND is_collection=0',[$uid])[0];
        return ['files'=>(int)$head['files'],'bytes'=>(int)$head['bytes'],'retained_bytes'=>$this->saberRetainedBytes($uid),'updated_at'=>(int)$head['updated_at'],'versions'=>(int)$this->query('SELECT COUNT(*) AS n FROM saber_revisions WHERE user_id=? AND is_collection=0',[$uid])[0]['n']];
    }

    /** Stable, paginated ciphertext metadata for the authenticated web reader. */
    public function saberWebManifest(int $uid,?int $expected=null,string $cursor=''): array
    {
        $this->rentalUser($uid);
        if ($cursor!=='' && !preg_match('/^[a-f0-9]{64}$/D',$cursor)) throw new ApiError(422,'無效的分頁。');
        $this->db->beginTransaction();
        try {
            $generation=(int)($this->query('SELECT generation FROM saber_heads WHERE user_id=?',[$uid])[0]['generation']??0);
            if ($expected!==null && $expected!==$generation) throw new ApiError(409,'筆記正在同步，請重新整理。');
            $quiet=max(0,min(10,(int)($this->config['web_sync_quiet_seconds']??(!empty($this->config['production'])?2:0))));
            $latest=(int)($this->query('SELECT MAX(updated_at) AS n FROM saber_nodes WHERE user_id=?',[$uid])[0]['n']??0);
            if ($quiet && $latest>time()-$quiet) throw new ApiError(409,'筆記正在同步，請稍後再試。');
            $columns='path_hash,path,revision,blob_hash,size,mtime';
            $files=$this->query('SELECT '.$columns.' FROM saber_nodes WHERE user_id=? AND is_collection=0 AND deleted=0 AND path_hash>? ORDER BY path_hash LIMIT 501',[$uid,$cursor]);
            $more=count($files)>500;if ($more) array_pop($files);
            $config=$this->query('SELECT '.$columns.' FROM saber_nodes WHERE user_id=? AND path_hash=? AND deleted=0',[$uid,hash('sha256','Saber/config.sbc')])[0]??null;
            $this->db->commit();
            foreach ($files as &$file) foreach (['revision','size','mtime'] as $key) $file[$key]=(int)$file[$key];unset($file);
            if ($config) foreach (['revision','size','mtime'] as $key) $config[$key]=(int)$config[$key];
            return ['owner_id'=>$uid,'generation'=>$generation,'config'=>$config,'files'=>$files,'next_cursor'=>$more?end($files)['path_hash']:null];
        } catch (Throwable $e) { $this->db->rollBack();throw $e; }
    }

    public function saberGeneration(int $uid): int
    {
        $this->rentalUser($uid);
        return (int)($this->query('SELECT generation FROM saber_heads WHERE user_id=?',[$uid])[0]['generation']??0);
    }

    /** Read an immutable owner-bound revision; never accept an owner from HTTP. */
    public function saberWebBlob(int $uid,string $pathHash,int $revision): array
    {
        $this->rentalUser($uid);
        if (!preg_match('/^[a-f0-9]{64}$/D',$pathHash) || $revision<1) throw new ApiError(422,'無效的檔案。');
        $node=$this->query('SELECT blob_hash,size FROM saber_revisions WHERE user_id=? AND path_hash=? AND revision=? AND deleted=0 AND is_collection=0',[$uid,$pathHash,$revision])[0]??null;
        if (!$node || !$node['blob_hash']) throw new ApiError(404,'找不到檔案版本。');
        $file=$this->blobPath($uid,$node['blob_hash']);
        if (!is_file($file) || filesize($file)!==(int)$node['size'] || !hash_equals($node['blob_hash'],hash_file('sha256',$file))) throw new RuntimeException('Cloud revision integrity failure');
        return $node+['file'=>$file];
    }

    /** Mutations share the owner lock with access revocation and legacy quotas. */
    public function writeSaber(array $actor,string $path,?string $bytes,bool $collection=false,?int $mtime=null,string $ifMatch='',string $ifNoneMatch=''): array
    {
        $path=self::saberPath($path);
        if ($path==='' || ($path==='Saber' && (!$collection || $bytes===null)) || ($path!=='Saber' && $collection)) throw new ApiError(409,'無效的雲端檔案操作。');
        if ($bytes!==null && strlen($bytes)>$this->config['max_file_bytes']) throw new ApiError(413,'單一檔案超過大小上限。');
        if ($mtime!==null && ($mtime<0 || $mtime>4102444800)) throw new ApiError(422,'無效的修改時間。');
        if ($bytes!==null && !$collection && $path==='Saber/config.sbc') {
            if (strlen($bytes)>16384) throw new ApiError(413,'加密設定過大。');
            try { $json=json_decode($bytes,true,flags:JSON_THROW_ON_ERROR); } catch (\JsonException) { throw new ApiError(422,'加密設定無效。'); }
            if (!is_array($json) || count($json)>32 || array_filter($json,static fn($v)=>!is_string($v))) throw new ApiError(422,'加密設定無效。');
        }
        $a=$this->saberCredential($actor['token_hash']??'');
        if (!$a['can_write']) throw new ApiError(403,'目前為雲端唯讀模式。');
        if ($bytes!==null && !$collection && class_exists(SecurityBridge::class)) SecurityBridge::scan($bytes,$path);
        $uid=(int)$a['user_id'];
        $this->db->beginTransaction();
        try {
            $this->lockOwner($uid);
            $a=$this->saberCredential($actor['token_hash']??'');
            if (!$a['can_write']) throw new ApiError(403,'目前為雲端唯讀模式。');
            $ph=hash('sha256',$path);
            $old=$this->query('SELECT * FROM saber_nodes WHERE user_id=? AND path_hash=?',[$uid,$ph])[0]??null;
            $exists=$old && !$old['deleted'];
            $etag=$exists?self::saberEtag($old):null;
            if ($ifMatch!=='' && (!$exists || ($ifMatch!=='*' && $ifMatch!==$etag))) throw new ApiError(412,'雲端檔案已更新，請重新同步。');
            if ($ifNoneMatch!=='' && $exists && ($ifNoneMatch==='*' || $ifNoneMatch===$etag)) throw new ApiError(412,'雲端檔案已存在。');
            if ($collection && $exists) throw new ApiError(405,'資料夾已存在。');
            if (!$collection && $path!=='Saber') $this->saberNode($uid,'Saber');
            if ($bytes===null && !$exists) throw new ApiError(404,'檔案不存在。');
            $size=$bytes===null?0:strlen($bytes);$hash=($bytes===null || $collection)?null:hash('sha256',$bytes);
            $mtime??=time();
            if ($exists && !$collection && $bytes!==null && $old['blob_hash']===$hash && (int)$old['mtime']===$mtime) { $this->db->commit();return $old+['created'=>false]; }
            if ($this->totalRetainedBytes($uid)+$size>$this->config['quota_bytes']) throw new ApiError(507,'儲存空間不足，請聯絡管理者。');
            if ((int)$this->query('SELECT COUNT(*) AS n FROM saber_revisions WHERE user_id=?',[$uid])[0]['n']>=100000) throw new ApiError(507,'版本數量已達上限，請聯絡管理者。');
            if ($hash!==null) {
                $target=$this->blobPath($uid,$hash);
                if (!is_dir(dirname($target)) && !mkdir(dirname($target),0700,true) && !is_dir(dirname($target))) throw new RuntimeException('Cannot create cloud storage');
                if (!is_file($target)) {
                    $tmp=tempnam(dirname($target),'.saber-');
                    try { if ($tmp===false || !chmod($tmp,0600) || file_put_contents($tmp,$bytes,LOCK_EX)!==$size || !rename($tmp,$target)) throw new RuntimeException('Cannot store cloud object'); }
                    finally { if ($tmp && is_file($tmp)) unlink($tmp); }
                }
            }
            $head=$this->query('SELECT generation FROM saber_heads WHERE user_id=?',[$uid])[0]??null;$generation=(int)($head['generation']??0)+1;
            if ($head) $this->query('UPDATE saber_heads SET generation=? WHERE user_id=?',[$generation,$uid]);
            else $this->query('INSERT INTO saber_heads (generation,user_id) VALUES (?,?)',[$generation,$uid]);
            $revision=(int)($old['revision']??0)+1;
            $values=[$path,(int)$collection,$revision,$generation,$hash,$size,(int)($bytes===null),$mtime,time(),$a['device_id'],$uid,$ph];
            if ($old) $this->query('UPDATE saber_nodes SET path=?,is_collection=?,revision=?,generation=?,blob_hash=?,size=?,deleted=?,mtime=?,updated_at=?,device_id=? WHERE user_id=? AND path_hash=?',$values);
            else $this->query('INSERT INTO saber_nodes (path,is_collection,revision,generation,blob_hash,size,deleted,mtime,updated_at,device_id,user_id,path_hash) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',$values);
            $this->query('INSERT INTO saber_revisions (path,is_collection,revision,generation,blob_hash,size,deleted,mtime,updated_at,device_id,user_id,path_hash) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',$values);
            $this->audit($uid,$bytes===null?'saber.deleted':'saber.written',['path_hash'=>$ph,'generation'=>$generation,'device_id'=>$a['device_id']]);
            $this->db->commit();
            return ['revision'=>$revision,'blob_hash'=>$hash,'created'=>!$exists];
        } catch (Throwable $e) { $this->db->rollBack();throw $e; }
    }

    public function downloadSaber(int $uid,string $path): array
    {
        $this->rentalUser($uid);$node=$this->saberNode($uid,$path);
        if ($node['is_collection']) throw new ApiError(405,'請使用資料夾列表。');
        $file=$this->blobPath($uid,$node['blob_hash']);
        if (!is_file($file) || filesize($file)!==(int)$node['size']) throw new RuntimeException('Cloud object unavailable');
        return $node+['file'=>$file];
    }

    /** Consistent encrypted account backup. Immutable blobs remain valid after lock release. */
    public function saberArchive(int $uid): array
    {
        $this->rentalUser($uid);
        $this->db->beginTransaction();
        try {
            $this->lockOwner($uid);$this->rentalUser($uid);
            $nodes=$this->query('SELECT path,blob_hash,size FROM saber_nodes WHERE user_id=? AND deleted=0 AND is_collection=0 ORDER BY path',[$uid]);
            if (!$nodes) throw new ApiError(404,'尚無雲端檔案。');
            $generation=(int)$this->query('SELECT generation FROM saber_heads WHERE user_id=?',[$uid])[0]['generation'];
            $this->db->commit();
        } catch (Throwable $e) { $this->db->rollBack();throw $e; }
        $temp=tempnam(sys_get_temp_dir(),'yc-saber-backup-');
        if (!$temp || !chmod($temp,0600)) throw new RuntimeException('Backup storage unavailable');
        try {
            $zip=new \ZipArchive();if ($zip->open($temp,\ZipArchive::OVERWRITE)!==true) throw new RuntimeException('Cannot create backup');
            foreach ($nodes as $node) {
                $file=$this->blobPath($uid,$node['blob_hash']);
                if (!is_file($file) || filesize($file)!==(int)$node['size'] || !hash_equals($node['blob_hash'],hash_file('sha256',$file)) || !$zip->addFile($file,$node['path'])) throw new RuntimeException('Backup object unavailable');
            }
            if (!$zip->addFromString('manifest.json',json_encode(['format'=>'yc-saber-encrypted-backup-v1','generation'=>$generation,'files'=>$nodes],JSON_THROW_ON_ERROR)) || !$zip->close()) throw new RuntimeException('Cannot finalize backup');
            $this->audit($uid,'saber.backup',['generation'=>$generation,'files'=>count($nodes)]);
            return ['file'=>$temp,'size'=>filesize($temp),'generation'=>$generation];
        } catch (Throwable $e) { @unlink($temp);throw $e; }
    }
}
