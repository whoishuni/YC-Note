<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Service.php';
use YcNote\Service;
use YcNote\ApiError;

$dir=sys_get_temp_dir().'/yc-versions-'.bin2hex(random_bytes(6));mkdir($dir,0700);
$dsn=getenv('YC_VERSION_TEST_DSN')?:'sqlite:'.$dir.'/test.sqlite';
if (!str_starts_with($dsn,'sqlite:') && !str_contains($dsn,'dbname=yc_note_versions_test;')) throw new RuntimeException('Only the disposable version test database is allowed');
$db=new PDO($dsn,getenv('YC_TEST_DB_USER')?:null,getenv('YC_TEST_DB_PASSWORD')?:null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$db->exec('CREATE TABLE users (id BIGINT PRIMARY KEY,name VARCHAR(100),password VARCHAR(255),type VARCHAR(32),is_banned INTEGER DEFAULT 0,first_login INTEGER DEFAULT 0)');
$password='Disposable-Version-Fixture-2026!';$passwordHash=password_hash($password,PASSWORD_BCRYPT,['cost'=>4]);
$q=$db->prepare('INSERT INTO users (id,name,password,type) VALUES (?,?,?,?)');$q->execute([1,'root',$passwordHash,'super_admin']);$q->execute([2,'writer',$passwordHash,'admin']);
$config=['identity_table'=>'users','storage_path'=>$dir.'/blobs','max_file_bytes'=>1024,'quota_bytes'=>65536,'token_lifetime_seconds'=>3600,'offline_lease_seconds'=>86400];
$s=new Service($db,$db,$config);$s->migrate();$s->migrate();
$checks=0;
function ok(bool $v,string $label):void {global $checks;if(!$v)throw new RuntimeException('FAIL: '.$label);$checks++;}
function denied(int $status,callable $f,string $label):void {try{$f();}catch(ApiError $e){ok($e->status===$status,$label.' status '.$e->status);return;}throw new RuntimeException('Not rejected: '.$label);}
function publish(Service $s,array $actor,array $contents):void {
 $snapshot=$s->snapshot((int)$actor['user_id']);$heads=array_column($snapshot['files'],null,'path');$changes=[];
 foreach($contents as $path=>$bytes){$hash=$bytes===null?null:hash('sha256',$bytes);if($bytes!==null)$s->stageSync($actor,$path,$hash,$bytes);$changes[]=['path'=>$path,'base_revision'=>(int)($heads[$path]['revision']??0),'blob_hash'=>$hash,'size'=>$bytes===null?0:strlen($bytes),'deleted'=>$bytes===null];}
 $s->commitSync($actor,bin2hex(random_bytes(32)),$snapshot['snapshot'],$changes);
}
function heads(Service $s,int $uid=1):array{return array_column($s->manifest($uid),null,'path');}
function archiveContent(array $archive):array{$zip=new ZipArchive();$zip->open($archive['file']);$files=[];for($i=0;$i<$zip->numFiles;$i++)$files[$zip->getNameIndex($i)]=$zip->getFromIndex($i);$zip->close();unlink($archive['file']);return $files;}
function fp(string $hash):string{return hash('sha256','rental-auth-session-v1|'.$hash);}
$a=$s->enroll('root',$password,str_repeat('a',64),'Root iPad','ios');$s->setDeviceStatus(1,$a['device_id'],'approved');$actor=$s->authenticate($a['token']);
$s->setAccess(1,2,true);$w=$s->enroll('writer',$password,str_repeat('b',64),'Writer iPad','ios');$s->setDeviceStatus(1,$w['device_id'],'approved');$writer=$s->authenticate($w['token']);
$web=$s->notebookWebActor(1,fp($passwordHash),'super_admin');$writerWeb=$s->notebookWebActor(2,fp($passwordHash),'admin');
denied(401,fn()=>$s->notebookWebActor(1,str_repeat('0',64),'super_admin'),'native actor requires matching session password fingerprint');
denied(401,fn()=>$s->notebookWebActor(1,fp($passwordHash),'admin'),'native actor requires matching session role');
$path='/課程/筆記.sbn2';
publish($s,$actor,[$path=>'main v1',$path.'.0'=>'image v1',$path.'.2'=>'gapped asset']);
$history=$s->notebookHistory(1,$path);ok(count($history)===1 && $history[0]['files_count']===3 && $history[0]['current'],'one complete generation for a multi-file commit');
$pdf="%PDF-1.4\nfixture";publish($s,$actor,[$path.'.pdf'=>$pdf,$path.'.pdf.json'=>'{}',$path.'.p'=>'thumbnail']);
ok(count($s->notebookHistory(1,$path))===1,'derived exports and thumbnails do not create extra notebook generations');
publish($s,$actor,[$path=>'main v2',$path.'.0'=>'image v2',$path.'.2'=>null,$path.'.1'=>'new asset']);
$before=$s->notebookFingerprint(1,$path);$history=$s->notebookHistory(1,$path);
ok(count($history)===2 && $history[0]['version']===2 && $history[0]['current'] && !$history[1]['current'],'second complete generation and current marker');
$files=archiveContent($s->notebookVersionArchive(1,$path,1));
ok($files===['main.sbn2'=>'main v1','main.sbn2.0'=>'image v1','main.sbn2.2'=>'gapped asset'],'historical original contains the exact old attachments, including gaps');
denied(404,fn()=>$s->notebookVersionArchive(2,$path,1),'historical archive ownership');
denied(404,fn()=>$s->notebookHistory(2,$path),'history ownership');
denied(404,fn()=>$s->changeNotebook($writerWeb,$path,$before,'restore',1),'native mutation cannot cross ownership');
$s->changeNotebook($web,$path,$before,'restore',1);$h=heads($s);
ok(file_get_contents($s->download(1,$path)['file'])==='main v1' && file_get_contents($s->download(1,$path.'.0')['file'])==='image v1','restore publishes main and matching attachment');
ok($h[$path.'.1']['deleted']===1 && $h[$path.'.2']['deleted']===0,'restore removes newer attachments and revives old ones');
ok($h[$path.'.pdf']['deleted']===1 && $h[$path.'.pdf.json']['deleted']===1 && $h[$path.'.p']['deleted']===1,'restore invalidates derived PDF and cover');
$history=$s->notebookHistory(1,$path);ok(count($history)===3 && $history[0]['reason']==='restore','restore is another reversible generation');
$revs=(int)$db->query('SELECT COUNT(*) FROM revisions')->fetchColumn();
$result=$s->changeNotebook($web,$path,$s->notebookFingerprint(1,$path),'restore',1);
ok(!$result['changed'] && (int)$db->query('SELECT COUNT(*) FROM revisions')->fetchColumn()===$revs && count($s->notebookHistory(1,$path))===3,'restoring identical content is idempotent');
denied(409,fn()=>$s->changeNotebook($web,$path,$before,'trash'),'stale website action cannot overwrite a newer state');
$s->changeNotebook($web,$path,$s->notebookFingerprint(1,$path),'trash');
$history=$s->notebookHistory(1,$path);ok(count($history)===4 && $history[0]['deleted']===1 && $history[0]['reason']==='trash','trash retains a versioned tombstone');
$book=array_column($s->notebooks(1),null,'path')[$path];ok($book['deleted']===1 && $book['restore_version']===3 && $book['total_size']===strlen('main v1image v1gapped asset'),'trash exposes latest restorable generation and its content size');
ok(archiveContent($s->notebookVersionArchive(1,$path,3))['main.sbn2.2']==='gapped asset','trash retains complete downloadable original');
denied(409,fn()=>$s->notebookVersionArchive(1,$path,4),'deleted generation has no misleading archive');
denied(409,fn()=>$s->changeNotebook($web,$path,$s->notebookFingerprint(1,$path),'restore',4),'cannot restore a deletion as content');
$s->changeNotebook($web,$path,$s->notebookFingerprint(1,$path),'restore',3);
ok(count($s->notebookHistory(1,$path))===5 && file_get_contents($s->download(1,$path.'.2')['file'])==='gapped asset','trash restore recovers the entire notebook');
$stable=$s->snapshot(1);$versions=$s->notebookHistory(1,$path);$audit=(int)$db->query('SELECT COUNT(*) FROM audit')->fetchColumn();
$assetHash=hash('sha256',$path.'.1');$sqlite=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';
$db->exec($sqlite?"CREATE TRIGGER fail_restore BEFORE INSERT ON revisions WHEN NEW.path_hash='$assetHash' BEGIN SELECT RAISE(ABORT,'fixture fault'); END":"CREATE TRIGGER fail_restore BEFORE INSERT ON revisions FOR EACH ROW BEGIN IF NEW.path_hash='$assetHash' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture fault'; END IF; END");
try{$s->changeNotebook($web,$path,$s->notebookFingerprint(1,$path),'restore',2);throw new RuntimeException('Fault did not surface');}catch(PDOException){ok(true,'injected attachment publication failure surfaces');}
ok($s->snapshot(1)===$stable && $s->notebookHistory(1,$path)===$versions && (int)$db->query('SELECT COUNT(*) FROM audit')->fetchColumn()===$audit,'failed restore rolls back heads, notebook versions and audit');$db->exec('DROP TRIGGER fail_restore');
$small=new Service($db,$db,array_replace($config,['quota_bytes'=>1]));
denied(507,fn()=>$small->changeNotebook($web,$path,$s->notebookFingerprint(1,$path),'restore',2),'restoring content enforces retained storage quota');
ok($s->snapshot(1)===$stable,'quota failure preserves all heads');
$s->setAccess(1,1,false);
denied(403,fn()=>$s->changeNotebook($web,$path,$s->notebookFingerprint(1,$path),'trash'),'held web actor cannot write after downgrade');
ok(archiveContent($s->notebookVersionArchive(1,$path,2))['main.sbn2.1']==='new asset','read-only keeps historical original download');
$s->setAccess(1,1,true);$q=$db->prepare('UPDATE users SET password=? WHERE id=1');$q->execute([password_hash('changed-fixture',PASSWORD_BCRYPT,['cost'=>4])]);
denied(401,fn()=>$s->changeNotebook($web,$path,$s->notebookFingerprint(1,$path),'trash'),'held web actor invalid after password change');$q->execute([$passwordHash]);
$blob=$s->download(1,$path.'.1',1)['file'];file_put_contents($blob,'corrupt bytes');
try{$s->changeNotebook($web,$path,$s->notebookFingerprint(1,$path),'restore',2);throw new RuntimeException('Corruption did not fail');}catch(RuntimeException $e){ok($e->getMessage()==='Stored notebook checksum mismatch','historical corruption blocks full restore');}
ok($s->snapshot(1)===$stable,'corrupt historical asset cannot partially restore main');file_put_contents($blob,'new asset');
$small->changeNotebook($web,$path,$s->notebookFingerprint(1,$path),'trash');ok(heads($s)[$path]['deleted']===1,'trash remains possible after quota is reduced below usage');
$s->write($writer,'/legacy.sbn2',0,'legacy current');$s->write($writer,'/legacy.sbn2.0',0,'legacy attachment');
$s->migrate();$s->migrate();$history=$s->notebookHistory(2,'/legacy.sbn2');
ok(count($history)===1 && $history[0]['reason']==='baseline' && $history[0]['files_count']===2,'migration seeds only current stored legacy state, once');
$s->write($writer,'/legacy.sbn2',1,'later legacy');
$beforeLegacy=$s->notebookFingerprint(2,'/legacy.sbn2');
$s->changeNotebook($writerWeb,'/legacy.sbn2',$beforeLegacy,'trash');
$history=$s->notebookHistory(2,'/legacy.sbn2');ok(count($history)===3 && $history[1]['reason']==='baseline','untracked legacy state is preserved before a whole-notebook action');
$s->changeNotebook($writerWeb,'/legacy.sbn2',$s->notebookFingerprint(2,'/legacy.sbn2'),'restore',2);
$restored=archiveContent($s->notebookArchive(2,'/legacy.sbn2'));ok($restored===['main.sbn2'=>'later legacy','main.sbn2.0'=>'legacy attachment'],'legacy trash restore recovers the captured stored generation');
denied(426,fn()=>$s->write($writer,'/legacy.sbn2',4,'old client'),'web mutation fences older per-file writers');
echo "PASS: $checks notebook version checks (".$db->getAttribute(PDO::ATTR_DRIVER_NAME).")\n";
