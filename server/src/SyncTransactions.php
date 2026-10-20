<?php
declare(strict_types=1);
namespace YcNote;

use PDO;
use RuntimeException;
use Throwable;

/** Immutable transfers followed by one compare-and-swap of the account snapshot. */
trait SyncTransactions
{
    private function migrateSync(): void
    {
        $sqlite=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';
        $suffix=$sqlite?'':' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin';
        $text=$sqlite?'TEXT':'LONGTEXT';
        $this->db->exec("CREATE TABLE IF NOT EXISTS sync_staging (user_id BIGINT NOT NULL, device_id VARCHAR(64) NOT NULL, path_hash VARCHAR(64) NOT NULL, blob_hash VARCHAR(64) NOT NULL, size BIGINT NOT NULL, created_at BIGINT NOT NULL, PRIMARY KEY(user_id,device_id,path_hash,blob_hash))".$suffix);
        $this->db->exec("CREATE TABLE IF NOT EXISTS sync_commits (user_id BIGINT NOT NULL, device_id VARCHAR(64) NOT NULL, commit_id VARCHAR(64) NOT NULL, request_hash VARCHAR(64) NOT NULL, result $text NOT NULL, created_at BIGINT NOT NULL, PRIMARY KEY(user_id,device_id,commit_id))".$suffix);
        $columns=$sqlite?$this->query('PRAGMA table_info(owners)'):$this->query('SHOW COLUMNS FROM owners');
        if (!in_array('sync_version',array_column($columns,$sqlite?'name':'Field'),true)) $this->db->exec('ALTER TABLE owners ADD COLUMN sync_version INTEGER NOT NULL DEFAULT 0');
        foreach (['revisions'=>'user_id,blob_hash','sync_staging'=>'user_id,created_at','sync_commits'=>'user_id,created_at'] as $table=>$columns) {
            $name=$table.'_sync_lookup';
            if ($sqlite) $this->db->exec("CREATE INDEX IF NOT EXISTS $name ON $table ($columns)");
            elseif (!$this->query('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?',[$table,$name])) $this->db->exec("CREATE INDEX $name ON $table ($columns)");
        }
    }

    private function syncWriter(array $actor): int
    {
        $uid=(int)($actor['user_id']??0);
        $user=$this->webUser($uid,$actor['identity_hash']??'');
        if (!$this->canWrite($user)) throw new ApiError(403,'目前為唯讀模式。');
        $device=$this->query('SELECT status FROM devices WHERE id=? AND user_id=?',[$actor['device_id']??'',$uid])[0]??null;
        if (!$device || $device['status']!=='approved') throw new ApiError(403,'裝置未通過認證。');
        if (!$this->query('SELECT 1 FROM tokens WHERE token_hash=? AND user_id=? AND device_id=? AND expires_at>?',[$actor['token_hash']??'',$uid,$actor['device_id'],time()])) throw new ApiError(401,'登入已失效，請重新登入。');
        return $uid;
    }

    private function storeSyncBlob(int $uid,string $hash,string $bytes): void
    {
        $target=$this->blobPath($uid,$hash);
        if (!is_dir(dirname($target)) && !mkdir(dirname($target),0700,true) && !is_dir(dirname($target))) throw new RuntimeException('Blob directory unavailable');
        if (is_file($target) && hash_equals($hash,(string)hash_file('sha256',$target))) return;
        $temp=tempnam(dirname($target),'.upload-');
        try {
            if (!$temp || file_put_contents($temp,$bytes,LOCK_EX)!==strlen($bytes) || !chmod($temp,0600) || !rename($temp,$target)) throw new RuntimeException('Blob storage unavailable');
        } finally { if ($temp && is_file($temp)) unlink($temp); }
    }

    /** Called under the owner lock; never removes referenced history or live transfers. */
    private function expireSyncStages(int $uid): void
    {
        $expired=$this->query('SELECT device_id,path_hash,blob_hash FROM sync_staging WHERE user_id=? AND created_at<? ORDER BY created_at LIMIT 100',[$uid,time()-86400]);
        foreach ($expired as $row) {
            $this->query('DELETE FROM sync_staging WHERE user_id=? AND device_id=? AND path_hash=? AND blob_hash=?',[$uid,$row['device_id'],$row['path_hash'],$row['blob_hash']]);
        }
        foreach (array_unique(array_column($expired,'blob_hash')) as $hash) {
            if ($this->query('SELECT 1 FROM revisions WHERE user_id=? AND blob_hash=? LIMIT 1',[$uid,$hash]) || $this->query('SELECT 1 FROM sync_staging WHERE user_id=? AND blob_hash=? LIMIT 1',[$uid,$hash])) continue;
            $file=$this->blobPath($uid,$hash);
            if (is_file($file) && !unlink($file)) throw new RuntimeException('Expired transfer cleanup failed');
        }
    }

    public function stageSync(array $actor,string $path,string $hash,string $bytes): array
    {
        self::validPath($path);
        if (!preg_match('/^[a-f0-9]{64}$/D',$hash) || !hash_equals($hash,hash('sha256',$bytes))) throw new ApiError(422,'上傳內容驗證失敗。');
        $size=strlen($bytes);
        if ($size>$this->config['max_file_bytes']) throw new ApiError(413,'單一檔案超過大小上限。');
        $uid=$this->syncWriter($actor);
        if (class_exists(SecurityBridge::class)) SecurityBridge::scan($bytes,$path);
        $this->db->beginTransaction();
        try {
            $this->lockOwner($uid);
            $this->syncWriter($actor);
            $this->expireSyncStages($uid);
            $staged=$this->query('SELECT blob_hash,MAX(size) AS size FROM sync_staging WHERE user_id=? GROUP BY blob_hash',[$uid]);
            $reserved=array_sum(array_column($staged,'size'));
            $additional=in_array($hash,array_column($staged,'blob_hash'),true)?0:$size;
            $used=$this->totalRetainedBytes($uid);
            if ($used+$reserved+$additional>$this->config['quota_bytes']) throw new ApiError(507,'儲存空間不足，請聯絡管理者。');
            $key=[$uid,$actor['device_id'],hash('sha256',$path),$hash];
            $existing=$this->query('SELECT 1 FROM sync_staging WHERE user_id=? AND device_id=? AND path_hash=? AND blob_hash=?',$key);
            $count=(int)$this->query('SELECT COUNT(*) AS n FROM sync_staging WHERE user_id=?',[$uid])[0]['n'];
            if (!$existing && $count>=10000) throw new ApiError(429,'待提交檔案過多，請稍後再試。');
            $this->storeSyncBlob($uid,$hash,$bytes);
            if ($existing) $this->query('UPDATE sync_staging SET created_at=? WHERE user_id=? AND device_id=? AND path_hash=? AND blob_hash=?',[time(),...$key]);
            else $this->query('INSERT INTO sync_staging (size,created_at,user_id,device_id,path_hash,blob_hash) VALUES (?,?,?,?,?,?)',[$size,time(),...$key]);
            if (!$this->db->commit()) throw new RuntimeException('Transfer commit failed');
            return ['blob_hash'=>$hash,'size'=>$size];
        } catch (Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }

    public static function snapshotHash(array $files): string
    {
        $hash=hash_init('sha256');
        foreach ($files as $file) hash_update($hash,json_encode([$file['path'],(int)$file['revision'],$file['blob_hash'],(int)$file['size'],(int)$file['deleted']],JSON_THROW_ON_ERROR)."\n");
        return hash_final($hash);
    }

    public function snapshot(int $uid): array
    {
        $files=$this->manifest($uid);
        return ['files'=>$files,'snapshot'=>self::snapshotHash($files),'capabilities'=>['atomic_sync_v1']];
    }

    public function commitSync(array $actor,string $id,string $snapshot,array $changes): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D',$id) || !preg_match('/^[a-f0-9]{64}$/D',$snapshot) || !array_is_list($changes) || !$changes || count($changes)>10000) throw new ApiError(422,'無效的同步提交。');
        $seen=[];
        foreach ($changes as $change) {
            if (!is_array($change) || !is_string($change['path']??null) || !is_int($change['base_revision']??null) || $change['base_revision']<0 || !is_int($change['size']??null) || $change['size']<0 || !is_bool($change['deleted']??null)) throw new ApiError(422,'無效的檔案版本。');
            self::validPath($change['path']);
            $pathHash=hash('sha256',$change['path']);
            if (isset($seen[$pathHash])) throw new ApiError(422,'重複的檔案路徑。');
            $seen[$pathHash]=true;
            if ($change['deleted']) {
                if (($change['blob_hash']??null)!==null || $change['size']!==0) throw new ApiError(422,'無效的刪除記錄。');
            } elseif (!is_string($change['blob_hash']??null) || !preg_match('/^[a-f0-9]{64}$/D',$change['blob_hash']) || $change['size']>$this->config['max_file_bytes']) throw new ApiError(422,'無效的檔案內容。');
        }
        $requestHash=hash('sha256',json_encode([$snapshot,$changes],JSON_THROW_ON_ERROR));
        $uid=$this->syncWriter($actor);
        $this->db->beginTransaction();
        try {
            $this->lockOwner($uid);
            $this->syncWriter($actor);
            $receipt=$this->query('SELECT request_hash,result FROM sync_commits WHERE user_id=? AND device_id=? AND commit_id=?',[$uid,$actor['device_id'],$id])[0]??null;
            if ($receipt) {
                if (!hash_equals($receipt['request_hash'],$requestHash)) throw new ApiError(409,'同步提交代碼已被使用。');
                $result=json_decode($receipt['result'],true,flags:JSON_THROW_ON_ERROR);
                $this->db->commit();
                return $result;
            }
            $files=$this->manifest($uid);
            if (!hash_equals(self::snapshotHash($files),$snapshot)) throw new ApiError(409,'筆記已被其他裝置更新，請重新同步。');
            $heads=array_column($files,null,'path');
            $added=0;
            foreach ($changes as $change) {
                if ((int)($heads[$change['path']]['revision']??0)!==$change['base_revision']) throw new ApiError(409,'此檔案已被更新，請重新同步。');
                if ($change['deleted']) continue;
                $stage=$this->query('SELECT size FROM sync_staging WHERE user_id=? AND device_id=? AND path_hash=? AND blob_hash=?',[$uid,$actor['device_id'],hash('sha256',$change['path']),$change['blob_hash']])[0]??null;
                $file=$this->blobPath($uid,$change['blob_hash']);
                if (!$stage || (int)$stage['size']!==$change['size'] || !is_file($file) || filesize($file)!==$change['size'] || !hash_equals($change['blob_hash'],(string)hash_file('sha256',$file))) throw new ApiError(422,'同步檔案尚未完整上傳。');
                $added+=$change['size'];
            }
            $used=$this->totalRetainedBytes($uid);
            if ($added>0 && $used+$added>$this->config['quota_bytes']) throw new ApiError(507,'儲存空間不足，請聯絡管理者。');
            $published=$this->publishSyncChanges($uid,$actor['device_id'],$changes,$heads);
            foreach ($changes as $change) $this->query('DELETE FROM sync_staging WHERE user_id=? AND device_id=? AND path_hash=? AND blob_hash=?',[$uid,$actor['device_id'],hash('sha256',$change['path']),$change['blob_hash']??'']);
            $this->recordNotebookChanges($uid,$heads,array_replace($heads,array_column($published,null,'path')),array_column($changes,'path'),'sync',$actor['device_id']);
            $result=['files'=>$published];
            $result['snapshot']=self::snapshotHash($this->manifest($uid));
            $this->query('UPDATE owners SET sync_version=1 WHERE user_id=?',[$uid]);
            $this->query('INSERT INTO sync_commits (user_id,device_id,commit_id,request_hash,result,created_at) VALUES (?,?,?,?,?,?)',[$uid,$actor['device_id'],$id,$requestHash,json_encode($result,JSON_THROW_ON_ERROR),time()]);
            $this->query('DELETE FROM sync_commits WHERE user_id=? AND created_at<?',[$uid,time()-7*86400]);
            $this->audit($uid,'sync.committed',['commit_id'=>$id,'files'=>count($changes),'device_id'=>$actor['device_id']]);
            if (!$this->db->commit()) throw new RuntimeException('Snapshot commit failed');
            return $result;
        } catch (Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }
    /** Validated immutable bytes are already stored; caller holds the owner lock. */
    private function publishSyncChanges(int $uid,string $device,array $changes,array $heads): array
    {
        if (!$this->db->inTransaction()) throw new RuntimeException('Publication needs a transaction');
        $result=[];
        foreach ($changes as $change) {
            $pathHash=hash('sha256',$change['path']);$revision=$change['base_revision']+1;
            $values=[$revision,$change['blob_hash']??null,$change['size'],(int)$change['deleted'],time(),$device,$uid,$pathHash];
            if (isset($heads[$change['path']])) $this->query('UPDATE files SET revision=?,blob_hash=?,size=?,deleted=?,updated_at=?,device_id=? WHERE user_id=? AND path_hash=?',$values);
            else $this->query('INSERT INTO files (revision,blob_hash,size,deleted,updated_at,device_id,user_id,path_hash,path) VALUES (?,?,?,?,?,?,?,?,?)',[...$values,$change['path']]);
            $this->query('INSERT INTO revisions (revision,blob_hash,size,deleted,created_at,device_id,user_id,path_hash) VALUES (?,?,?,?,?,?,?,?)',$values);
            $result[]=['path'=>$change['path'],'revision'=>$revision,'blob_hash'=>$change['blob_hash']??null,'size'=>$change['size'],'deleted'=>(int)$change['deleted']];
        }
        return $result;
    }

}
