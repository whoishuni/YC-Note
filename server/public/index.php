<?php
declare(strict_types=1);
require __DIR__ . '/boot.php';
$h = static fn(mixed $s): string => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$error = null;
$user = null;
$page = is_string($_GET['page'] ?? null) ? $_GET['page'] : 'devices';
if (!in_array($page,['devices','users','notes','security'],true)) $page='devices';
try {
    $service = yc_service();
    YcNote\SecurityBridge::headers($service->config['production']);
    YcNote\SecurityBridge::check($service);
    $base = rtrim($service->config['base_path'], '/') . '/';
    if ($service->config['production'] && ($_SERVER['HTTPS'] ?? '') !== 'on') throw new YcNote\ApiError(400, '請使用 HTTPS。');
    foreach (['session.use_strict_mode'=>'1','session.use_only_cookies'=>'1','session.use_trans_sid'=>'0','session.gc_maxlifetime'=>'28800'] as $key=>$value) ini_set($key,$value);
    session_name('YCNOTEADMIN');
    session_set_cookie_params(['lifetime'=>0,'path'=>$base,'secure'=>$service->config['production'],'httponly'=>true,'samesite'=>'Strict']);
    session_start();
    if (isset($_SESSION['user_id'])) {
        if (time()-(int)($_SESSION['login_at']??0)>28800 || time()-(int)($_SESSION['last_activity']??0)>1800) { $_SESSION=[]; session_regenerate_id(true); }
        else {
            try { $user=$service->webUser((int)$_SESSION['user_id'],$_SESSION['identity_hash']??'',true); }
            catch (YcNote\ApiError) { $_SESSION=[]; session_regenerate_id(true); }
        }
    }
    if (time()-(int)($_SESSION['rotated_at']??0)>900) { session_regenerate_id(true); $_SESSION['rotated_at']=time(); }
    $_SESSION['last_activity']=time();
    csrf_token();
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        if (!csrf_verify($_POST['csrf']??null)) throw new YcNote\ApiError(403,'頁面已過期，請重新整理。');
        if (!empty($_POST['website_url'])) throw new YcNote\ApiError(403,'請求已被拒絕。');
        YcNote\SecurityBridge::check($service,$_POST);
        $action=$_POST['action']??'';
        if ($action==='login') {
            $service->rateLimit('login-ip|'.get_client_ip(),30);
            if (!is_string($_POST['username']??null)||!is_string($_POST['password']??null)) throw new YcNote\ApiError(422,'請輸入帳號密碼。');
            $service->rateLimit('login-account|'.get_client_ip().'|'.mb_strtolower(trim($_POST['username'])),5);
            try { $u=$service->verifyPassword($_POST['username'],$_POST['password']); }
            catch (YcNote\ApiError $e) { $service->securityEvent('login_failed',['ip_hash'=>hash('sha256',get_client_ip())]); throw $e; }
            if (!$service->isAdmin((int)$u['id'])) throw new YcNote\ApiError(403,'沒有管理權限。');
            session_regenerate_id(true);
            $_SESSION=['user_id'=>(int)$u['id'],'identity_hash'=>$service->identityHash($u),'login_at'=>time(),'last_activity'=>time(),'rotated_at'=>time()];
            csrf_regenerate();
        } elseif ($user) {
            $uid=(int)$user['id'];
            $service->rateLimit('admin-actions|'.$uid,60,60);
            if ($action==='logout') {
                $_SESSION=[]; session_destroy();
                setcookie(session_name(),'', ['expires'=>1,'path'=>$base,'secure'=>$service->config['production'],'httponly'=>true,'samesite'=>'Strict']);
            } elseif (in_array($action,['approve','revoke'],true)&&is_string($_POST['device_id']??null)) {
                $service->setDeviceStatus($uid,$_POST['device_id'],$action==='approve'?'approved':'revoked');
            } elseif ($action==='create_user') {
                foreach (['name','new_password','role'] as $key) if (!is_string($_POST[$key]??null)) throw new YcNote\ApiError(422,'使用者資料不完整。');
                $service->createAccount($uid,trim($_POST['name']),$_POST['new_password'],$_POST['role']);
                $page='users';
            } elseif (in_array($action,['set_access','remove_user','restore_user'],true)) {
                $target=filter_var($_POST['user_id']??null,FILTER_VALIDATE_INT);
                if ($target===false||$target===0) throw new YcNote\ApiError(422,'無效的使用者。');
                $write=($_POST['can_write']??'0')==='1';
                $role=isset($_POST['role'])&&is_string($_POST['role'])?$_POST['role']:null;
                $service->setAccess($uid,$target,$write,$action==='remove_user',$action==='set_access'?$role:null);
                $page='users';
            } else throw new YcNote\ApiError(422,'無效的操作。');
        } else throw new YcNote\ApiError(401,'請重新登入。');
        header('Location: '.$base.'?page='.$page,true,303); exit;
    } elseif (!in_array($_SERVER['REQUEST_METHOD'],['GET','HEAD'],true)) { header('Allow: GET, HEAD, POST'); throw new YcNote\ApiError(405,'無效的請求方式。'); }
    if ($user&&isset($_GET['download'])&&is_string($_GET['download'])) {
        $revision=isset($_GET['revision'])?filter_var($_GET['revision'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]):null;
        if ($revision===false) throw new YcNote\ApiError(422,'無效的版本。');
        $file=$service->download((int)$user['id'],$_GET['download'],$revision);
        header('Content-Type: application/octet-stream'); header('Content-Length: '.$file['size']);
        header("Content-Disposition: attachment; filename*=UTF-8''".rawurlencode(basename($_GET['download'])));
        readfile($file['file']); exit;
    }
    $members=$user?$service->members((int)$user['id']):[];
    $names=array_column($members,'name','id');
    $devices=$user&&$page==='devices'?$service->devices((int)$user['id'],true):[];
    $files=$user&&$page==='notes'?$service->manifest((int)$user['id']):[];
    $audit=$user&&$page==='security'?$service->auditLog((int)$user['id']):[];
} catch (Throwable $e) {
    http_response_code($e instanceof YcNote\ApiError?$e->status:503);
    $error=$e instanceof YcNote\ApiError?$e->getMessage():'服務暫時無法使用。';
    if (!$e instanceof YcNote\ApiError) error_log('[YC Note web] type='.get_class($e).' code='.(int)$e->getCode());
}
$csrf=$h(function_exists('csrf_token')&&session_status()===PHP_SESSION_ACTIVE?csrf_token():'');
$statusLabel=['pending'=>'待核准','approved'=>'已認證','revoked'=>'已撤銷'];
$roleLabel=['super_admin'=>'超級管理員','user'=>'用戶'];
$actionLabel=['device.requested'=>'申請裝置認證','device.approved'=>'核准裝置','device.revoked'=>'撤銷裝置','file.written'=>'同步檔案','file.deleted'=>'刪除檔案','login.success'=>'App 登入','user.created'=>'新增使用者','user.removed'=>'移除使用者','user.permissions'=>'變更權限','security.login_failed'=>'登入失敗','security.input.log'=>'輸入風險記錄','security.input.block'=>'攔截請求'];
?>
<!doctype html><html lang="zh-Hant"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>YC Note</title><link rel="icon" href="icon.svg" type="image/svg+xml"><link rel="stylesheet" href="style.css"></head><body><div class="shell">
<header><a class="brand" href="./"><img src="icon.svg" alt="" width="40" height="40"><span>YC Note</span></a><?php if($user):?><form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="logout"><button class="secondary">登出 <?=$h($user['name'])?></button></form><?php endif?></header>
<?php if($error):?><p class="error" role="alert"><?=$h($error)?></p><?php endif?>
<?php if(!$user):?><main class="login"><form method="post" class="login-card"><h1>登入</h1><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="login"><input class="honeypot" name="website_url" autocomplete="off" tabindex="-1" aria-hidden="true"><label>帳號<input name="username" autocomplete="username" required maxlength="100"></label><label>密碼<input name="password" type="password" autocomplete="current-password" required maxlength="1024"></label><button>登入</button></form></main>
<?php else:?><nav class="tabs"><?php foreach(['devices'=>'裝置','users'=>'使用者','notes'=>'我的筆記','security'=>'安全記錄'] as $key=>$label):?><a <?=$page===$key?'aria-current="page"':''?> href="?page=<?=$key?>"><?=$label?></a><?php endforeach?></nav><main>
<?php if($page==='devices'):?><section><h1>裝置</h1><div class="table-wrap"><table><thead><tr><th>名稱／代碼</th><th>使用者</th><th>狀態</th><th>最近連線</th><th></th></tr></thead><tbody><?php foreach($devices as $d):?><tr><td><b><?=$h($d['name'])?></b><small><?=$h($d['platform'])?> · <?=$h($d['id'])?></small></td><td><?=$h($names[$d['user_id']]??'#'.$d['user_id'])?></td><td><span class="badge <?=$h($d['status'])?>"><?=$h($statusLabel[$d['status']])?></span></td><td><?=$h(gmdate('Y-m-d H:i',(int)$d['last_seen_at']+28800))?></td><td><form method="post" class="actions"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="device_id" value="<?=$h($d['id'])?>"><?php if($d['status']!=='approved'):?><button name="action" value="approve">核准</button><?php endif?><?php if($d['status']!=='revoked'):?><button class="secondary danger" name="action" value="revoke">撤銷</button><?php endif?></form></td></tr><?php endforeach?><?php if(!$devices):?><tr><td colspan="5" class="empty">沒有裝置</td></tr><?php endif?></tbody></table></div></section>
<?php elseif($page==='users'):?><section><div class="section-heading"><h1>使用者</h1><a href="#new-user">新增使用者</a></div><div class="table-wrap"><table><thead><tr><th>帳號</th><th>來源</th><th>角色</th><th>筆記權限</th><th></th></tr></thead><tbody><?php foreach($members as $m):?><tr><td><?=$h($m['name'])?><?php if($m['removed']):?><small>已移除</small><?php elseif($m['is_banned']||$m['first_login']):?><small><?=$m['is_banned']?'已停用':'待啟用'?></small><?php endif?></td><td><?=$m['source']==='shared'?'共用':'YC Note'?></td><td><?=$h($roleLabel[$m['role']])?></td><td><?php if(!$m['removed']):?><form method="post" class="actions"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="set_access"><input type="hidden" name="user_id" value="<?=$h($m['id'])?>"><select name="can_write" aria-label="筆記權限"><option value="1" <?=$m['can_write']?'selected':''?>>可編輯</option><option value="0" <?=!$m['can_write']?'selected':''?>>唯讀</option></select><?php if($m['source']==='local'&&$m['id']!==(int)$user['id']):?><select name="role" aria-label="角色"><option value="user" <?=$m['role']==='user'?'selected':''?>>用戶</option><option value="super_admin" <?=$m['role']==='super_admin'?'selected':''?>>超級管理員</option></select><?php endif?><button class="secondary">儲存</button></form><?php endif?></td><td><?php if($m['removed']):?><form method="post"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="user_id" value="<?=$h($m['id'])?>"><input type="hidden" name="can_write" value="0"><button class="secondary" name="action" value="restore_user">恢復為唯讀</button></form><?php elseif($m['id']!==(int)$user['id']):?><a class="danger" href="?page=users&amp;remove=<?=$h($m['id'])?>">移除</a><?php endif?></td></tr><?php endforeach?></tbody></table></div>
<?php if(isset($_GET['remove'])):?><?php foreach($members as $m):if((string)$m['id']!==(string)$_GET['remove']||$m['id']===(int)$user['id'])continue;?><form method="post" class="confirmation"><strong>移除 <?=$h($m['name'])?>？</strong><p>停用 YC Note 存取，保留筆記資料。</p><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="user_id" value="<?=$h($m['id'])?>"><button class="danger" name="action" value="remove_user">確認移除</button> <a href="?page=users">取消</a></form><?php endforeach?><?php endif?></section>
<section id="new-user"><h2>新增使用者</h2><form method="post" class="user-form"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="action" value="create_user"><label>帳號<input name="name" autocomplete="off" required minlength="3" maxlength="100" pattern="[A-Za-z0-9][A-Za-z0-9_.@-]{2,99}"></label><label>密碼<input name="new_password" type="password" autocomplete="new-password" required minlength="10" maxlength="72"><small>10–72 字元，含大小寫、數字與符號</small></label><label>角色<select name="role"><option value="user">用戶</option><option value="super_admin">超級管理員</option></select></label><button>新增</button></form></section>
<?php elseif($page==='notes'):?><section><h1>我的筆記</h1><div class="table-wrap"><table><thead><tr><th>檔案</th><th>版本</th><th>大小</th><th></th></tr></thead><tbody><?php foreach($files as $f):?><tr><td><?=$h($f['path'])?><?=$f['deleted']?' · 已刪除':''?><details><summary>版本</summary><?php foreach($service->history((int)$user['id'],$f['path']) as $v):?><p>v<?=$h($v['revision'])?> · <?=$h(gmdate('Y-m-d H:i',(int)$v['created_at']+28800))?><?php if(!(int)$v['deleted']):?> <a href="?<?=$h(http_build_query(['download'=>$f['path'],'revision'=>$v['revision']]))?>">下載</a><?php else:?> · 已刪除<?php endif?></p><?php endforeach?></details></td><td>v<?=$h($f['revision'])?></td><td><?=number_format($f['size']/1024,1)?> KB</td><td><?php if(!$f['deleted']):?><a href="?<?=$h(http_build_query(['download'=>$f['path']]))?>">下載</a><?php endif?></td></tr><?php endforeach?><?php if(!$files):?><tr><td colspan="4" class="empty">沒有筆記</td></tr><?php endif?></tbody></table></div></section>
<?php else:?><section><h1>安全記錄</h1><div class="table-wrap"><table><thead><tr><th>時間</th><th>使用者</th><th>操作</th><th>對象</th></tr></thead><tbody><?php foreach($audit as $a):$detail=json_decode($a['detail'],true)?:[];?><tr><td><?=$h(gmdate('Y-m-d H:i',(int)$a['created_at']+28800))?></td><td><?=$h($names[$a['actor_id']]??($a['actor_id']?'#'.$a['actor_id']:'系統'))?></td><td><?=$h($actionLabel[$a['action']]??'安全事件')?></td><td><?=$h(isset($detail['user_id'])?($names[$detail['user_id']]??'#'.$detail['user_id']):($detail['device_id']??''))?><?=isset($detail['revision'])?' · v'.$h($detail['revision']):''?></td></tr><?php endforeach?></tbody></table></div></section><?php endif?></main><?php endif?></div></body></html>
