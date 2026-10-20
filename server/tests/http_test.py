#!/usr/bin/env python3
"""Exercise the actual PHP API against isolated fixture data."""
from pathlib import Path
from urllib.request import Request, build_opener, HTTPCookieProcessor
from urllib.error import HTTPError
from urllib.parse import urlencode
import http.cookiejar, json, os, re, shutil, socket, subprocess, tempfile, time

ROOT = Path(__file__).resolve().parents[1]
fixture_output = subprocess.check_output(['php', str(ROOT/'tests/integration.php'), '--fixture'], text=True)
fixture = json.loads(fixture_output.strip().splitlines()[-1])
work = Path(fixture['directory'])
www = work/'www'
(www/'yc_note').mkdir(parents=True)
(www/'yc_note').rmdir()
(www/'yc_note').symlink_to(ROOT/'public', target_is_directory=True)
with socket.socket() as sock:
    sock.bind(('127.0.0.1',0))
    port=sock.getsockname()[1]
base=f'http://127.0.0.1:{port}/yc_note/'
env={**os.environ, 'YC_NOTE_CONFIG':fixture['config']}
log=open(work/'php.log','w')
server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(www)],env=env,stdout=log,stderr=log)
count=0
opener=build_opener(HTTPCookieProcessor(http.cookiejar.CookieJar()))
def request(path='', method='GET', body=None, token=None, headers=None, client=None):
    h=dict(headers or {})
    if token: h['Authorization']='Bearer '+token
    data=body
    if isinstance(body,dict): data=json.dumps(body).encode(); h['Content-Type']='application/json'
    req=Request(base+path,data=data,method=method,headers=h)
    try:
        with (client or opener).open(req,timeout=10) as response: return response.status,dict(response.headers),response.read()
    except HTTPError as e: return e.code,dict(e.headers),e.read()
def check(ok,label):
    global count
    if not ok: raise AssertionError(label)
    count+=1
def api(action,**kwargs): return request('api.php?'+urlencode({'action':action}),**kwargs)
def enroll(name='owner',installation='c'*64,password=None):
    status,headers,body=api('login',method='POST',body={'username':name,'password':password or fixture['password'],'installation_id':installation,'device_name':'HTTP test iPad','platform':'ios'})
    check(status==200,'enroll via HTTP')
    return json.loads(body)
def control(action,**fields):
    code = "require getenv('YC_NOTE_SOURCE').'/src/bootstrap.php'; $s=yc_service(); $x=json_decode(stream_get_contents(STDIN),true); if($x['action']==='device') $s->setDeviceStatus(1,$x['device_id'],$x['status']); elseif($x['action']==='access') $s->setAccess(1,$x['user_id'],$x['write'],$x['removed']??false);"
    subprocess.run(['php','-r',code],input=json.dumps({'action':action,**fields}),text=True,check=True,env={**env,'YC_NOTE_SOURCE':str(ROOT)})
try:
    for _ in range(100):
        try:
            status,headers,html=request()
            break
        except OSError: time.sleep(.05)
    else: raise RuntimeError('PHP preview failed to start')
    check(status==404,'old website redirects to native rental controller')
    check(api('manifest')[0]==401,'anonymous manifest denied')
    check(api('login')[0]==405,'method enforcement')
    check(api('unknown')[0]==404,'unknown operation')
    check(api('login',method='POST',body=b'{broken')[0]==422,'malformed JSON rejected')
    check(api('login',method='POST',body=b'x'*8193)[0]==413,'oversized auth body rejected')
    pending=enroll()
    check(pending['status']=='pending','new device pending')
    check(api('manifest',token=pending['token'])[0]==403,'pending cannot list notes')
    check(api('session',token=pending['token'])[0]==200,'pending can poll')
    control('device',device_id=pending['device_id'],status='approved')
    check(json.loads(api('session',token=pending['token'])[2])['status']=='approved','approval immediately visible')
    token=pending['token']
    path='/測試/HTTP.sbn2'
    url='api.php?'+urlencode({'action':'upload','path':path})
    status,headers,body=request(url,method='PUT',body=b'notebook-v1',token=token,headers={'X-YC-Base-Revision':'0'})
    check(status==200 and json.loads(body)['revision']==1,'binary upload')
    check(request(url,method='PUT',body=b'stale',token=token,headers={'X-YC-Base-Revision':'0'})[0]==409,'HTTP CAS conflict')
    download='api.php?'+urlencode({'action':'download','path':path})
    status,headers,body=request(download,token=token)
    check(status==200 and body==b'notebook-v1','binary download integrity')
    check(headers.get('X-YC-Revision')=='1','download revision header')
    check(headers.get('Content-Type')=='application/octet-stream','uploads never execute')
    check(request(url,method='PUT',body=b'x'*1025,token=token,headers={'X-YC-Base-Revision':'1'})[0]==413,'HTTP upload bound')
    check(request('api.php?'+urlencode({'action':'download','path':'/../config.local.php'}),token=token)[0]==422,'path traversal rejected')
    check(request('api.php?action=download&path[]=x',token=token)[0]==422,'array parameter rejected')
    deletion='api.php?'+urlencode({'action':'delete','path':path})
    check(request(deletion,method='DELETE',token=token)[0]==422,'deletion needs base revision')
    check(request(deletion,method='DELETE',token=token,headers={'X-YC-Base-Revision':'1'})[0]==200,'HTTP tombstone')
    check(request(download,token=token)[0]==404,'deleted head unavailable')
    check(request(download+'&revision=1',token=token)[2]==b'notebook-v1','deleted version recoverable')
    restore='api.php?'+urlencode({'action':'restore','path':path})
    check(request(restore,method='POST',token=token,body={'revision':1,'base_revision':2})[0]==200,'HTTP restore')
    check(request(download,token=token)[2]==b'notebook-v1','restore preserved bytes')
    control('device',device_id=pending['device_id'],status='revoked')
    check(api('manifest',token=token)[0]==401,'revocation blocks existing token')
    control('access',user_id=2,write=True)
    writer=enroll('writer','9'*64)
    control('device',device_id=writer['device_id'],status='approved')
    token=writer['token']
    url='api.php?'+urlencode({'action':'upload','path':'/writer.sbn2'})
    download='api.php?'+urlencode({'action':'download','path':'/writer.sbn2'})
    check(request(url,method='PUT',body=b'writer v2',token=token,headers={'X-YC-Base-Revision':'1'})[0]==200,'rental admin upload')
    control('access',user_id=2,write=False)
    check(json.loads(api('session',token=token)[2])['can_write'] is False,'existing session becomes read-only')
    check(request(download,token=token)[2]==b'writer v2','read-only account downloads')
    check(request(url,method='PUT',body=b'edit',token=token,headers={'X-YC-Base-Revision':'2'})[0]==403,'read-only upload denied')
    check(request('api.php?'+urlencode({'action':'delete','path':'/writer.sbn2'}),method='DELETE',token=token,headers={'X-YC-Base-Revision':'2'})[0]==403,'read-only deletion denied')
    check(request('api.php?'+urlencode({'action':'restore','path':'/writer.sbn2'}),method='POST',body={'revision':1,'base_revision':2},token=token)[0]==403,'read-only restore denied')
    control('access',user_id=2,write=False,removed=True)
    check(api('manifest',token=token)[0]==401,'full removal invalidates token')
    control('access',user_id=2,write=False)
    writer=enroll('writer','9'*64)
    check(writer['status']=='approved' and writer['can_write'] is False,'reenroll retains approval and read-only access')
    check(request(download,token=writer['token'])[2]==b'writer v2','account restoration preserves notes')
    check(api('login',method='POST',body={'username':'note_only','password':fixture['password'],'installation_id':'f'*64,'device_name':'Old iPad','platform':'ios'})[0]==401,'legacy note-only account rejected')
    print(f'PASS: {count} API HTTP integration checks')
    if os.environ.get('YC_KEEP_PREVIEW')=='1':
        print('Fixture preview: '+base,flush=True)
        print('Fixture config: '+fixture['config'],flush=True)
        while True: time.sleep(1)
finally:
    server.terminate()
    server.wait(timeout=10)
    log.close()
    if os.environ.get('YC_KEEP_PREVIEW')!='1': shutil.rmtree(work)
