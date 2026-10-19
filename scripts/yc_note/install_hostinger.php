<?php
declare(strict_types=1);
// A short-lived authenticated installer. The deployment helper replaces this
// digest, uploads under a random name, then this file removes itself.
header('Content-Type: application/json');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD']??'')!=='POST' || !hash_equals('__INSTALL_TOKEN_HASH__',hash('sha256',(string)($_SERVER['HTTP_X_YC_INSTALL']??'')))) { http_response_code(404); exit; }
$published=[]; $publicPublished=[]; $previousActive=null; $changedPointers=false;
$previousManifest=$previousPublicRoot=null;
$publish=static function(string $target,string $bytes,int $mode):void {
    $temp=tempnam(dirname($target),'.yc-release-');
    try {
        if (!$temp || file_put_contents($temp,$bytes,LOCK_EX)!==strlen($bytes) || !chmod($temp,$mode) || !rename($temp,$target)) throw new RuntimeException('pointer_publish');
    } finally { if ($temp && is_file($temp)) unlink($temp); }
};
try {
    if (($_SERVER['HTTPS']??'')!=='on') throw new RuntimeException('https_required');
    $body=file_get_contents('php://input',false,null,0,2*1024*1024+1);
    if ($body===false||strlen($body)>2*1024*1024) throw new RuntimeException('request_size');
    $data=json_decode($body,true,flags:JSON_THROW_ON_ERROR);
    if (($data['action']??'')==='cleanup') { unlink(__FILE__); echo '{"removed":true}'; exit; }
    $docroot=__DIR__;
    if ($docroot!=='/home/u805049489/domains/yckuo.com/public_html') throw new RuntimeException('unexpected_root');
    if (!class_exists(ZipArchive::class)) throw new RuntimeException('zip_unavailable');
    $root='/home/u805049489/yc-note';
    $public=$docroot.'/yc_note';
    if (is_dir($public)&&!is_file($root.'/.yc-note')) throw new RuntimeException('destination_not_owned');
    if (!is_dir($root)&&!mkdir($root,0700,true)) throw new RuntimeException('private_directory');
    chmod($root,0700);
    if (!is_file($root.'/.yc-note')) file_put_contents($root.'/.yc-note','YC Note deployment');
    $allowed=['src/bootstrap.php','src/Service.php','src/SyncTransactions.php','src/NotebookVersions.php','src/Notebooks.php','src/SecurityBridge.php','public/index.php','public/api.php','public/boot.php','public/style.css','public/icon.svg','public/.htaccess'];
    $release=$root.'/releases/'.gmdate('YmdHis').'-'.bin2hex(random_bytes(4));
    mkdir($release,0700,true);
    foreach ($allowed as $relative) {
        $bytes=base64_decode($data['files'][$relative]??'',true);
        if ($bytes===false||$bytes==='') throw new RuntimeException('missing_source');
        $target=$release.'/'.$relative;
        if (!is_dir(dirname($target))) mkdir(dirname($target),0700,true);
        if (file_put_contents($target,$bytes,LOCK_EX)!==strlen($bytes)) throw new RuntimeException('source_write');
        chmod($target,0600);
    }
    // Patch only anchored additions, keeping unrelated live rental changes.
    $nativeRoot=$docroot.'/rent';
    $nativeAllowed=['admin/notes.php','includes/YcNoteModule.php','includes/yc_note_view.php','assets/css/yc-note.css','yc_note/api.php'];
    $patchAllowed=['config/features.php','includes/admin_header.php','includes/MaintenanceMode.php','.htaccess'];
    $pending=[];
    foreach ($nativeAllowed as $name) {
        $bytes=base64_decode($data['rental_files'][$name]??'',true);
        if ($bytes===false || $bytes==='') throw new RuntimeException('native_source_missing');
        $pending[$name]=$bytes;
    }
    foreach ($patchAllowed as $name) {
        $bytes=file_get_contents($nativeRoot.'/'.$name);
        if ($bytes===false || empty($data['rental_patches'][$name])) throw new RuntimeException('native_patch_missing');
        foreach ($data['rental_patches'][$name] as $patch) {
            $before=base64_decode($patch['before']??'',true); $after=base64_decode($patch['after']??'',true);
            if (!$before || !$after) throw new RuntimeException('native_patch_invalid');
            if (str_contains($bytes,$after)) continue;
            if (substr_count($bytes,$before)!==1) throw new RuntimeException('native_patch_conflict');
            $bytes=str_replace($before,$after,$bytes);
        }
        $pending[$name]=$bytes;
    }
    $backup=$release.'/rental-backup';
    mkdir($backup,0700,true);
    foreach ($pending as $name=>$bytes) {
        $old=$nativeRoot.'/'.$name;
        if (is_file($old)) {
            if (in_array($name,$nativeAllowed,true) && !is_file($root.'/rental-installed.json')) throw new RuntimeException('native_destination_exists');
            if (in_array($name,$nativeAllowed,true)) {
                $prior=json_decode(file_get_contents($root.'/rental-installed.json'),true,flags:JSON_THROW_ON_ERROR);
                $hash=hash_file('sha256',$old);
                if ($hash!==($prior[$name]??'') && $hash!==hash('sha256',$bytes)) throw new RuntimeException('native_destination_modified');
            }
            if (!is_dir(dirname($backup.'/'.$name))) mkdir(dirname($backup.'/'.$name),0700,true);
            if (!copy($old,$backup.'/'.$name)) throw new RuntimeException('native_backup');
            chmod($backup.'/'.$name,0600);
        }
    }
    $rent=require $docroot.'/rent/config/database.php';
    $identityConfig=['dsn'=>'mysql:host='.$rent['host'].';port='.(int)($rent['port']??3306).';dbname='.$rent['database'].';charset=utf8mb4','user'=>$rent['username'],'password'=>$rent['password']];
    $identity=new PDO($identityConfig['dsn'],$identityConfig['user'],$identityConfig['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $q=$identity->prepare('SELECT TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?');
    $q->execute([$rent['database'],'yc_note_identity']);
    if (!$q->fetchColumn()) $identity->exec("CREATE VIEW yc_note_identity AS SELECT id,name,password,type,is_banned,first_login FROM users WHERE type IN ('super_admin','admin','manager')");
    $identity->query('SELECT id,name,password,type,is_banned,first_login FROM yc_note_identity LIMIT 0');
    $identity->query('SELECT ip_address,blocked_until FROM ip_blacklist LIMIT 0');
    foreach (['DictionaryDetector.php','SecurityPipeline.php','HttpSecurityPolicy.php','security.php','csrf.php','GeoBlocker.php','UploadSecurityScanner.php'] as $module) if (!is_file($docroot.'/rent/includes/'.$module)) throw new RuntimeException('shared_security_module_missing');
    if (!is_file($root.'/config.local.php')) {
        $password=$data['db_password']??'';
        if (!is_string($password)||strlen($password)<30) throw new RuntimeException('database_credential');
        $config=[
            'base_path'=>'/yc_note','production'=>true,
            'database'=>['dsn'=>'mysql:host=127.0.0.1;dbname=u805049489_ycnote;charset=utf8mb4','user'=>'u805049489_ycnote','password'=>$password],
            'identity_database'=>$identityConfig,'identity_table'=>'yc_note_identity',
            'rental_includes_path'=>$docroot.'/rent/includes',
            'rental_bootstrap_path'=>$docroot.'/rent/config/bootstrap.php',
            'share_ip_blacklist'=>true,
            'storage_path'=>$root.'/blobs','max_file_bytes'=>50*1024*1024,
            'quota_bytes'=>2*1024*1024*1024,'token_lifetime_seconds'=>30*86400,'offline_lease_seconds'=>86400,
        ];
        file_put_contents($root.'/config.local.php',"<?php\nreturn ".var_export($config,true).";\n",LOCK_EX);
        chmod($root.'/config.local.php',0600);
    }
    copy($root.'/config.local.php',$release.'/config.local.php');
    chmod($release.'/config.local.php',0600);
    require $release.'/src/bootstrap.php';
    $service=yc_service();
    $service->migrate();
    $tableCount=(int)$service->db->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn();
    $sharedCount=(int)$identity->query("SELECT COUNT(*) FROM yc_note_identity WHERE type='super_admin' AND COALESCE(is_banned,0)=0 AND COALESCE(first_login,0)=0")->fetchColumn();
    if ($sharedCount<1) throw new RuntimeException('no_active_super_admin');
    if (!is_dir($root.'/blobs')) mkdir($root.'/blobs',0700,true);
    $previousActive=is_file($root.'/active-release')?file_get_contents($root.'/active-release'):null;
    foreach ($pending as $name=>$bytes) {
        $target=$nativeRoot.'/'.$name;
        if (!is_dir(dirname($target))) mkdir(dirname($target),0755,true);
        $temp=tempnam(dirname($target),'.yc-note-');
        if (!$temp || file_put_contents($temp,$bytes,LOCK_EX)!==strlen($bytes) || !chmod($temp,0644) || !rename($temp,$target)) throw new RuntimeException('native_publish');
        $published[]=$name;
    }
    $previousManifest=is_file($root.'/rental-installed.json')?file_get_contents($root.'/rental-installed.json'):null;
    $previousPublicRoot=is_file($public.'/.local-root.php')?file_get_contents($public.'/.local-root.php'):null;
    $changedPointers=true;
    $publish($root.'/rental-installed.json',json_encode(array_map(static fn($b)=>hash('sha256',$b),$pending),JSON_THROW_ON_ERROR),0600);
    $publish($root.'/active-release',$release,0600);
    if (!is_dir($public)) mkdir($public,0755,true);
    foreach ($allowed as $relative) {
        if (!str_starts_with($relative,'public/')) continue;
        $name=substr($relative,7);
        $old=$public.'/'.$name;
        if (is_file($old)) {
            if (!is_dir($release.'/public-backup')) mkdir($release.'/public-backup',0700,true);
            copy($old,$release.'/public-backup/'.$name);chmod($release.'/public-backup/'.$name,0600);
        }
        $temp=$public.'/.deploy-'.bin2hex(random_bytes(6));
        if (!copy($release.'/'.$relative,$temp)) throw new RuntimeException('public_copy');
        chmod($temp,0644);
        if (!rename($temp,$public.'/'.$name)) throw new RuntimeException('public_activate');
        $publicPublished[]=$name;
    }
    $publish($public.'/.local-root.php',"<?php return ".var_export($release,true).";\n",0600);
    $removed=unlink(__FILE__);
    echo json_encode(['installed'=>true,'database'=>'u805049489_ycnote','tables'=>$tableCount,'super_admin_count'=>$sharedCount,'base_url'=>'https://yckuo.com/rent/admin/notes.php','legacy_url'=>'https://yckuo.com/yc_note/','rental_files'=>count($pending),'private_root'=>$root,'public_root'=>$public,'installer_removed'=>$removed],JSON_THROW_ON_ERROR);
 } catch (Throwable $e) {
    // Undo only files this installer published, from the private release backup.
    foreach (array_reverse($publicPublished) as $name) {
        $saved=$release.'/public-backup/'.$name;
        if (is_file($saved)) {copy($saved,$public.'/'.$name);chmod($public.'/'.$name,0644);}
        else @unlink($public.'/'.$name);
    }
    foreach (array_reverse($published) as $name) {
        $saved=$backup.'/'.$name;
        if (is_file($saved)) {copy($saved,$nativeRoot.'/'.$name);chmod($nativeRoot.'/'.$name,0644);}
        else @unlink($nativeRoot.'/'.$name);
    }
    if ($changedPointers) {
        if ($previousActive!==null) $publish($root.'/active-release',$previousActive,0600);
        if ($previousManifest!==null) $publish($root.'/rental-installed.json',$previousManifest,0600);
        else @unlink($root.'/rental-installed.json');
        if ($previousPublicRoot!==null) $publish($public.'/.local-root.php',$previousPublicRoot,0600);
    }
    error_log('[YC Note installer] type='.get_class($e).' code='.(int)$e->getCode().' stage='.$e->getMessage());
    http_response_code(500);
    echo json_encode(['error'=>'installation_failed','type'=>get_class($e),'code'=>(int)$e->getCode(),'reason'=>$e instanceof Error?substr($e->getMessage(),0,200):null],JSON_THROW_ON_ERROR);
}
