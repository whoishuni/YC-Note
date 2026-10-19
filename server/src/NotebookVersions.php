<?php
declare(strict_types=1);
namespace YcNote;

use PDO;
use RuntimeException;
use Throwable;

/** Persist complete source generations independently of expiring sync receipts. */
trait NotebookVersions
{
    private static function notebookKey(string $path): ?string
    {
        return preg_match('/^(.+\.sbn2?)(?:\.(?:[0-9]+|p|pdf|pdf\.json))?$/D',$path,$m)?$m[1]:null;
    }

    private static function notebookGroups(array $heads): array
    {
        $groups=[];
        foreach ($heads as $head) {
            $key=self::notebookKey($head['path']);
            if ($key!==null) $groups[$key][$head['path']]=$head;
        }
        foreach ($groups as &$group) ksort($group,SORT_STRING);
        return $groups;
    }

    private static function validNotebookPath(string $path): void
    {
        self::validPath($path);
        if (self::notebookKey($path)!==$path) throw new ApiError(422,'無效的筆記。');
    }

    private function migrateNotebookVersions(): void
    {
        $sqlite=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';
        $text=$sqlite?'TEXT':'LONGTEXT';
        $suffix=$sqlite?'':' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin';
        $this->db->exec("CREATE TABLE IF NOT EXISTS notebook_versions (user_id BIGINT NOT NULL, path_hash VARCHAR(64) NOT NULL, version BIGINT NOT NULL, path VARCHAR(1024) NOT NULL, content_hash VARCHAR(64) NOT NULL, state $text NOT NULL, deleted INTEGER NOT NULL, size BIGINT NOT NULL, files_count INTEGER NOT NULL, created_at BIGINT NOT NULL, device_id VARCHAR(64) NOT NULL, reason VARCHAR(16) NOT NULL, PRIMARY KEY(user_id,path_hash,version))".$suffix);
        // Old per-file history cannot establish past attachment relationships.
        // Capture only the current stored state; never invent older generations.
        foreach ($this->query('SELECT DISTINCT user_id FROM files ORDER BY user_id') as $owner) {
            $uid=(int)$owner['user_id'];
            $this->db->beginTransaction();
            try {
                $this->lockOwner($uid);
                foreach (self::notebookGroups($this->manifest($uid)) as $path=>$group) {
                    if (!$this->query('SELECT 1 FROM notebook_versions WHERE user_id=? AND path_hash=? LIMIT 1',[$uid,hash('sha256',$path)])) $this->recordNotebookGeneration($uid,$path,$group,'baseline','migration');
                }
                $this->db->commit();
            } catch (Throwable $e) { $this->db->rollBack(); throw $e; }
        }
    }

    private static function sourceState(string $path,array $heads): ?array
    {
        $main=$heads[$path]??null;
        if (!$main) return null;
        $files=[];
        if (!$main['deleted']) {
            foreach ($heads as $name=>$head) {
                if (!$head['deleted'] && ($name===$path || preg_match('/^'.preg_quote($path,'/').'\.[0-9]+$/D',$name))) {
                    $files[$name]=['path'=>$name,'revision'=>(int)$head['revision'],'blob_hash'=>$head['blob_hash'],'size'=>(int)$head['size'],'deleted'=>0];
                }
            }
        }
        ksort($files,SORT_STRING);
        return ['v'=>1,'path'=>$path,'deleted'=>(bool)$main['deleted'],'files'=>$files];
    }

    private static function sourceStateHash(array $state): string
    {
        $content=[];
        foreach ($state['files'] as $path=>$head) $content[]=[$path,$head['blob_hash'],$head['size']];
        return hash('sha256',json_encode([$state['path'],$state['deleted'],$content],JSON_THROW_ON_ERROR));
    }

    /** The caller holds the owner lock, in the same transaction as file heads. */
    private function recordNotebookGeneration(int $uid,string $path,array $group,string $reason,string $device): void
    {
        if (!$this->db->inTransaction()) throw new RuntimeException('Version capture needs a transaction');
        $state=self::sourceState($path,$group);
        if ($state===null) return;
        $hash=self::sourceStateHash($state);
        $key=[$uid,hash('sha256',$path)];
        $last=$this->query('SELECT version,content_hash FROM notebook_versions WHERE user_id=? AND path_hash=? ORDER BY version DESC LIMIT 1',$key)[0]??null;
        if ($last && hash_equals($last['content_hash'],$hash)) return;
        $version=(int)($last['version']??0)+1;
        $this->query('INSERT INTO notebook_versions (user_id,path_hash,version,path,content_hash,state,deleted,size,files_count,created_at,device_id,reason) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',[
            ...$key,$version,$path,$hash,json_encode($state,JSON_THROW_ON_ERROR),(int)$state['deleted'],array_sum(array_column($state['files'],'size')),count($state['files']),time(),$device,$reason,
        ]);
    }

    private function recordNotebookChanges(int $uid,array $before,array $after,array $paths,string $reason,string $device): void
    {
        $keys=[];
        foreach ($paths as $path) { $key=self::notebookKey($path); if ($key!==null) $keys[$key]=true; }
        $beforeGroups=self::notebookGroups($before);$afterGroups=self::notebookGroups($after);
        foreach (array_keys($keys) as $path) {
            $this->recordNotebookGeneration($uid,$path,$beforeGroups[$path]??[],'baseline',$device);
            $this->recordNotebookGeneration($uid,$path,$afterGroups[$path]??[],$reason,$device);
        }
    }

    private function notebookGroupForUser(int $uid,string $path): array
    {
        $this->rentalUser($uid);
        self::validNotebookPath($path);
        $group=self::notebookGroups($this->manifest($uid))[$path]??[];
        if (!isset($group[$path])) throw new ApiError(404,'找不到筆記。');
        return $group;
    }

    public function notebookFingerprint(int $uid,string $path): string
    {
        return self::snapshotHash(array_values($this->notebookGroupForUser($uid,$path)));
    }

    public function notebookHistory(int $uid,string $path): array
    {
        $group=$this->notebookGroupForUser($uid,$path);
        $current=self::sourceStateHash(self::sourceState($path,$group));
        $rows=$this->query('SELECT content_hash,version,size,files_count,deleted,created_at,device_id,reason FROM notebook_versions WHERE user_id=? AND path_hash=? ORDER BY version DESC LIMIT 100',[$uid,hash('sha256',$path)]);
        foreach ($rows as &$row) {
            foreach (['version','size','files_count','deleted','created_at'] as $key) $row[$key]=(int)$row[$key];
            $row['current']=$row['version']===(int)$rows[0]['version'] && hash_equals($row['content_hash'],$current);
            unset($row['content_hash']);
        }
        return $rows;
    }

    private function notebookVersionState(int $uid,string $path,int $version): array
    {
        self::validNotebookPath($path);
        if ($version<1) throw new ApiError(422,'無效的版本。');
        $row=$this->query('SELECT state,content_hash FROM notebook_versions WHERE user_id=? AND path_hash=? AND version=?',[$uid,hash('sha256',$path),$version])[0]??null;
        if (!$row) throw new ApiError(404,'找不到這個版本。');
        $state=json_decode($row['state'],true,flags:JSON_THROW_ON_ERROR);
        if (!is_array($state) || ($state['v']??null)!==1 || ($state['path']??null)!==$path || !is_bool($state['deleted']??null) || !is_array($state['files']??null)) throw new RuntimeException('Invalid notebook version');
        foreach ($state['files'] as $name=>$head) {
            if (!is_string($name) || !is_array($head) || ($head['path']??null)!==$name || ($name!==$path && !preg_match('/^'.preg_quote($path,'/').'\.[0-9]+$/D',$name)) || !is_int($head['revision']??null) || $head['revision']<1 || !is_int($head['size']??null) || $head['size']<0 || !is_string($head['blob_hash']??null) || !preg_match('/^[a-f0-9]{64}$/D',$head['blob_hash']) || ($head['deleted']??null)!==0) throw new RuntimeException('Invalid notebook version entry');
        }
        ksort($state['files'],SORT_STRING);
        if (($state['deleted'] && $state['files']) || (!$state['deleted'] && !isset($state['files'][$path])) || !hash_equals($row['content_hash'],self::sourceStateHash($state))) throw new RuntimeException('Notebook version checksum mismatch');
        return $state;
    }

    public function notebookVersionArchive(int $uid,string $path,int $version): array
    {
        $this->rentalUser($uid);
        $state=$this->notebookVersionState($uid,$path,$version);
        if ($state['deleted']) throw new ApiError(409,'這個版本已移至垃圾桶。');
        return $this->archiveNotebookSources($uid,$path,$state['files']);
    }

    /** Bind a server-side rental Session to its validated password and role. */
    public function notebookWebActor(int $uid,string $passwordFingerprint,string $role): array
    {
        $user=$this->identityRow('id',$uid);
        if (!$this->eligible($user) || !hash_equals((function_exists('auth_password_fingerprint')?\auth_password_fingerprint($user['password']):hash('sha256','rental-auth-session-v1|'.(string)$user['password'])),$passwordFingerprint) || ($user['type']==='manager'?'admin':$user['type'])!==$role) throw new ApiError(401,'登入已失效，請重新登入。');
        if (!$this->canWrite($user)) throw new ApiError(403,'目前為唯讀模式。');
        return ['user_id'=>$uid,'identity_hash'=>$this->identityHash($user),'device_id'=>'website'];
    }

    /** Only the native controller creates this actor; no client can choose it. */
    public function changeNotebook(array $actor,string $path,string $expected,string $operation,?int $version=null): array
    {
        self::validNotebookPath($path);
        if (!in_array($operation,['trash','restore'],true) || !preg_match('/^[a-f0-9]{64}$/D',$expected)) throw new ApiError(422,'無效的筆記操作。');
        $uid=(int)($actor['user_id']??0);
        $user=$this->webUser($uid,$actor['identity_hash']??'');
        if (!$this->canWrite($user)) throw new ApiError(403,'目前為唯讀模式。');
        $this->db->beginTransaction();
        try {
            $this->lockOwner($uid);
            $user=$this->webUser($uid,$actor['identity_hash']??'');
            if (!$this->canWrite($user)) throw new ApiError(403,'目前為唯讀模式。');
            $heads=array_column($this->manifest($uid),null,'path');
            $group=self::notebookGroups($heads)[$path]??[];
            if (!isset($group[$path])) throw new ApiError(404,'找不到筆記。');
            if (!hash_equals(self::snapshotHash(array_values($group)),$expected)) throw new ApiError(409,'筆記已更新，請重新整理後再試。');
            $target=[];
            if ($operation==='restore') {
                $state=$this->notebookVersionState($uid,$path,$version??0);
                if ($state['deleted']) throw new ApiError(409,'請選擇刪除前的版本。');
                foreach ($state['files'] as $name=>$head) { $this->checkedBlob($uid,$name,$head); $target[$name]=$head; }
                $current=self::sourceState($path,$group);
                if (self::sourceStateHash($current)===self::sourceStateHash($state)) { $this->db->commit(); return ['changed'=>false]; }
            } elseif ($group[$path]['deleted']) throw new ApiError(409,'筆記已在垃圾桶。');
            $changes=[];$added=0;
            foreach (array_unique([...array_keys($group),...array_keys($target)]) as $name) {
                $old=$group[$name]??null;$file=$target[$name]??null;
                if ($file!==null && $old && !$old['deleted'] && $old['blob_hash']===$file['blob_hash'] && (int)$old['size']===$file['size']) continue;
                if ($file===null && (!$old || $old['deleted'])) continue;
                $changes[]=['path'=>$name,'base_revision'=>(int)($old['revision']??0),'blob_hash'=>$file['blob_hash']??null,'size'=>(int)($file['size']??0),'deleted'=>$file===null];
                $added+=(int)($file['size']??0);
            }
            $used=(int)$this->query('SELECT COALESCE(SUM(size),0) AS n FROM revisions WHERE user_id=?',[$uid])[0]['n'];
            if ($added>0 && $used+$added>$this->config['quota_bytes']) throw new ApiError(507,'儲存空間不足，請聯絡管理者。');
            $result=$this->publishSyncChanges($uid,'website',$changes,$heads);
            $after=array_replace($heads,array_column($result,null,'path'));
            $this->recordNotebookChanges($uid,$heads,$after,array_column($changes,'path'),$operation,'website');
            $this->query('UPDATE owners SET sync_version=1 WHERE user_id=?',[$uid]);
            $this->audit($uid,'notebook.'.$operation,['path_hash'=>hash('sha256',$path),'version'=>$version,'files'=>count($changes)]);
            $this->db->commit();
            return ['changed'=>true];
        } catch (Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }
}
