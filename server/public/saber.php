<?php
declare(strict_types=1);
require __DIR__.'/boot.php';

use YcNote\ApiError;
use YcNote\SecurityBridge;
use YcNote\Service;

header('Content-Type: application/json; charset=utf-8');
try {
    $s=yc_service();SecurityBridge::headers((bool)$s->config['production']);SecurityBridge::check($s);
    if ($s->config['production'] && ($_SERVER['HTTPS']??'')!=='on') throw new ApiError(400,'必須使用 HTTPS。');
    $base=(string)parse_url($s->saberBaseUrl(),PHP_URL_PATH);
    $requestPath=parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH);
    if (!is_string($requestPath) || !str_starts_with($requestPath,$base.'/') || preg_match('/%(?:2f|5c|00|25)/i',$requestPath)) throw new ApiError(404,'找不到此功能。');
    $route=substr($requestPath,strlen($base));$method=$_SERVER['REQUEST_METHOD']??'';
    $ip=get_client_ip();
    $body=static function(int $max):string {
        if ((int)($_SERVER['CONTENT_LENGTH']??0)>$max) throw new ApiError(413,'請求過大。');
        if (!in_array(strtolower($_SERVER['HTTP_CONTENT_ENCODING']??'identity'),['','identity'],true)) throw new ApiError(415,'不支援此內容編碼。');
        $b=file_get_contents('php://input',false,null,0,$max+1);
        if ($b===false || strlen($b)>$max) throw new ApiError(413,'請求過大。');
        return $b;
    };
    $json=static function(mixed $data,int $status=200):never { http_response_code($status);echo json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);exit; };
    $allow=static function(array $methods) use ($method):void {
        if (!in_array($method,$methods,true)) { header('Allow: '.implode(', ',$methods));throw new ApiError(405,'無效的請求方式。'); }
    };
    if ($route==='/index.php/login/v2') {
        $allow(['POST']);SecurityBridge::maintenance($s);$s->rateLimit('saber-init|'.$ip,12,900);$body(1024);
        $json($s->startSaberLogin((string)($_SERVER['HTTP_USER_AGENT']??'')));
    }
    if ($route==='/index.php/login/v2/poll') {
        $allow(['POST']);SecurityBridge::maintenance($s);$s->rateLimit('saber-poll|'.$ip,240,60);
        $raw=$body(2048);
        if (str_starts_with(strtolower($_SERVER['CONTENT_TYPE']??''),'application/json')) {
            try { $input=json_decode($raw,true,flags:JSON_THROW_ON_ERROR); } catch (JsonException) { throw new ApiError(422,'無效的請求。'); }
        } else parse_str($raw,$input);
        if (!is_array($input) || !is_string($input['token']??null)) throw new ApiError(422,'無效的請求。');
        $json($s->pollSaberLogin($input['token']));
    }
    if ($route==='/status.php') {
        $allow(['GET','HEAD']);
        $json(['installed'=>true,'maintenance'=>false,'productname'=>'YC Note','version'=>'1.0.0.0','versionstring'=>'1.0.0','extendedSupport'=>false]);
    }
    $dav=str_starts_with($route,'/remote.php/webdav/') || $route==='/remote.php/webdav';
    $userRoute=in_array($route,['/ocs/v2.php/cloud/user','/ocs/v1.php/cloud/user'],true);
    $logout=$route==='/ocs/v2.php/core/apppassword';
    $avatar=(bool)preg_match('~^/index\.php/avatar/(rent-[1-9][0-9]*)/(64|128|256|512|1024)$~D',$route,$avatarMatch);
    if (!$dav && !$userRoute && !$logout && !$avatar) throw new ApiError(404,'找不到此功能。');
    $allow($dav?['OPTIONS','GET','HEAD','PROPFIND','MKCOL','PUT','DELETE']:($logout?['DELETE']:['GET']));
    $authorization=$_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'';
    $name=$_SERVER['PHP_AUTH_USER']??'';$password=$_SERVER['PHP_AUTH_PW']??'';
    if (str_starts_with($authorization,'Basic ')) {
        $decoded=base64_decode(substr($authorization,6),true);
        if ($decoded!==false && str_contains($decoded,':')) [$name,$password]=explode(':',$decoded,2);
    }
    try { $actor=str_starts_with($authorization,'Bearer ')?$s->authenticateSaber('',substr($authorization,7),true):$s->authenticateSaber($name,$password); }
    catch (ApiError $e) { $s->rateLimit('saber-auth-failed|'.$ip,30,900);throw $e; }
    SecurityBridge::maintenance($s,$actor['yc_role']);
    $s->rateLimit('saber-api|'.$actor['device_id'],1200,60);
    $uid=(int)$actor['user_id'];
    if ($avatar) {
        if (!hash_equals('rent-'.$uid,$avatarMatch[1])) throw new ApiError(404,'找不到此頭像。');
        $png=__DIR__.'/avatar.png';
        header('Content-Type: image/png');header('Content-Length: '.filesize($png));header('X-NC-IsCustomAvatar: 0');readfile($png);exit;
    }
    if ($userRoute || $logout) {
        if ($logout) { $s->logoutSaber($actor);$data=[]; }
        else {
            $usage=$s->storageUsage($uid);$used=$usage['retained_bytes'];$quota=$usage['quota_bytes'];
            // Required fields of nextcloud.dart 9's UserDetails serializer. No
            // rental privileges, password, other accounts or private paths leak.
            $data=array_fill_keys(['address','biography','fediverse','headline','manager','organisation','phone','role','twitter','website'],'');
            $data+=['id'=>'rent-'.$uid,'display-name'=>$actor['username'],'displayname'=>$actor['username'],'enabled'=>true,'backend'=>'YC Note','backendCapabilities'=>['setDisplayName'=>false,'setPassword'=>false],'groups'=>[],'subadmin'=>[],'language'=>'zh_TW','locale'=>'zh_TW','lastLogin'=>0,'profile_enabled'=>'0','quota'=>['free'=>max(0,$quota-$used),'quota'=>$quota,'relative'=>$quota?100*$used/$quota:0,'total'=>$quota,'used'=>$used]];
        }
        $json(['ocs'=>['meta'=>['status'=>'ok','statuscode'=>200,'message'=>'OK'],'data'=>$data]]);
    }
    $path=rawurldecode(ltrim(substr($route,strlen('/remote.php/webdav')),'/'));
    // Do not normalize double separators or dot segments into an allowed path.
    if (str_contains(substr($route,strlen('/remote.php/webdav')),'//')) throw new ApiError(403,'無效的路徑。');
    $path=Service::saberPath($path);
    if ($method==='OPTIONS') { header('Allow: OPTIONS, GET, HEAD, PROPFIND, MKCOL, PUT, DELETE');header('DAV: 1');http_response_code(200);exit; }
    if (in_array($method,['PUT','DELETE','MKCOL'],true)) {
        if (!$actor['can_write']) throw new ApiError(403,'目前為雲端唯讀模式。');
        $bytes=$method==='DELETE'?null:($method==='MKCOL'?'':$body((int)$s->config['max_file_bytes']));
        if ($method==='MKCOL' && $body(8192)!=='') throw new ApiError(415,'不支援自訂資料夾屬性。');
        $mtime=null;
        if (isset($_SERVER['HTTP_X_OC_MTIME'])) { if (!preg_match('/^[0-9]{1,10}$/D',$_SERVER['HTTP_X_OC_MTIME'])) throw new ApiError(422,'無效的修改時間。');$mtime=(int)$_SERVER['HTTP_X_OC_MTIME']; }
        $result=$s->writeSaber($actor,$path,$bytes,$method==='MKCOL',$mtime,(string)($_SERVER['HTTP_IF_MATCH']??''),(string)($_SERVER['HTTP_IF_NONE_MATCH']??''));
        header('ETag: '.Service::saberEtag($result));if ($mtime!==null) header('X-OC-MTime: accepted');
        http_response_code($method==='DELETE'?204:($result['created']?201:204));exit;
    }
    if ($method==='PROPFIND') {
        $body(16384);$depth=strtolower($_SERVER['HTTP_DEPTH']??'1');
        if (!in_array($depth,['0','1','infinity'],true)) throw new ApiError(400,'無效的查詢深度。');
        $nodes=$s->saberListing($uid,$path,$depth!=='0');
        $x=static fn(string $v)=>htmlspecialchars($v,ENT_XML1|ENT_QUOTES,'UTF-8');
        header('Content-Type: application/xml; charset=utf-8');http_response_code(207);
        echo '<?xml version="1.0" encoding="utf-8"?><d:multistatus xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">';
        foreach ($nodes as $node) {
            $href=$base.'/remote.php/webdav/'.implode('/',array_map('rawurlencode',explode('/',$node['path']))).($node['is_collection'] && $node['path']!==''?'/':'');
            echo '<d:response><d:href>'.$x($href).'</d:href><d:propstat><d:prop><d:resourcetype>'.($node['is_collection']?'<d:collection/>':'').'</d:resourcetype><d:getcontentlength>'.(int)$node['size'].'</d:getcontentlength><d:getlastmodified>'.gmdate('D, d M Y H:i:s',(int)$node['mtime']).' GMT</d:getlastmodified><d:getetag>'.$x(Service::saberEtag($node)).'</d:getetag><d:getcontenttype>'.($node['is_collection']?'httpd/unix-directory':'application/octet-stream').'</d:getcontenttype></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>';
        }
        echo '</d:multistatus>';exit;
    }
    $file=$s->downloadSaber($uid,$path);$etag=Service::saberEtag($file);
    header('ETag: '.$etag);header('Last-Modified: '.gmdate('D, d M Y H:i:s',(int)$file['mtime']).' GMT');
    if (($_SERVER['HTTP_IF_NONE_MATCH']??'')===$etag) { http_response_code(304);exit; }
    header('Content-Type: application/octet-stream');header('Content-Length: '.$file['size']);
    if ($method!=='HEAD') readfile($file['file']);
} catch (ApiError $e) {
    http_response_code($e->status);
    if ($e->status===401) header('WWW-Authenticate: Basic realm="YC Note", charset="UTF-8"');
    echo json_encode(['error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[YC Note Saber] type='.get_class($e).' code='.(int)$e->getCode());
    http_response_code(503);echo json_encode(['error'=>'筆記服務暫時無法使用。'],JSON_UNESCAPED_UNICODE);
}
