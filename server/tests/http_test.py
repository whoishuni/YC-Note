#!/usr/bin/env python3
"""Exercise the actual PHP API and admin forms against isolated fixture data."""
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
def admin_action(action,**fields):
    html=request('?page=users')[2]
    csrf=re.search(rb'name="csrf" value="([a-f0-9]+)"',html).group(1).decode()
    return request('?page=users',method='POST',body=urlencode({'action':action,'csrf':csrf,**fields}).encode(),headers={'Content-Type':'application/x-www-form-urlencoded'})
try:
    for _ in range(100):
        try:
            status,headers,html=request()
            break
        except OSError: time.sleep(.05)
    else: raise RuntimeError('PHP preview failed to start')
    check(status==200,'admin sign-in loads under /yc_note/')
    check(b'YC Note' in html,'branded title')
    check('frame-ancestors' in headers.get('Content-Security-Policy',''),'frame restrictions')
    check('path=/yc_note/' in headers.get('Set-Cookie',''),'cookie scoped away from /rent')
    check('HttpOnly' in headers.get('Set-Cookie',''),'session cookie is HttpOnly')
    check(request('icon.svg')[0]==200,'icon available')
    check(request('style.css')[0]==200,'stylesheet available')
    check(api('manifest')[0]==401,'anonymous manifest denied')
    check(api('login')[0]==405,'method enforcement')
    check(api('unknown')[0]==404,'unknown operation')
    check(api('login',method='POST',body=b'{broken')[0]==422,'malformed JSON rejected')
    check(api('login',method='POST',body=b'x'*8193)[0]==413,'oversized auth body rejected')
    pending=enroll()
    check(pending['status']=='pending','new device pending')
    check(api('manifest',token=pending['token'])[0]==403,'pending cannot list notes')
    check(api('session',token=pending['token'])[0]==200,'pending can poll')
    check(request(method='POST',body=urlencode({'action':'approve','device_id':pending['device_id']}).encode(),headers={'Content-Type':'application/x-www-form-urlencoded'})[0]==403,'CSRF required')
    html=request()[2]
    csrf=re.search(rb'name="csrf" value="([a-f0-9]+)"',html).group(1).decode()
    form={'action':'login','username':'owner','password':fixture['password'],'csrf':csrf}
    status,headers,html=request(method='POST',body=urlencode(form).encode(),headers={'Content-Type':'application/x-www-form-urlencoded'})
    check(status==200 and '安全記錄'.encode() in html,'admin authenticated')
    csrf=re.search(rb'name="csrf" value="([a-f0-9]+)"',html).group(1).decode()
    form={'action':'approve','device_id':pending['device_id'],'csrf':csrf}
    check(request(method='POST',body=urlencode(form).encode(),headers={'Content-Type':'application/x-www-form-urlencoded'})[0]==200,'admin approval form works')
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
    check(request('?'+urlencode({'download':path}))[2]==b'notebook-v1','website downloads own notes')
    html=request()[2]
    csrf=re.search(rb'name="csrf" value="([a-f0-9]+)"',html).group(1).decode()
    form={'action':'revoke','device_id':pending['device_id'],'csrf':csrf}
    check(request(method='POST',body=urlencode(form).encode(),headers={'Content-Type':'application/x-www-form-urlencoded'})[0]==200,'admin revocation form works')
    check(api('manifest',token=token)[0]==401,'revocation blocks existing token')
    local_password='N7!qR2@vK8#pS6$m'
    status,headers,html=admin_action('create_user',name='http-note-user',new_password=local_password,role='user')
    check(status==200 and b'http-note-user' in html,'create note-only account through website')
    local=enroll('http-note-user','e'*64,local_password)
    uid=local['user_id']
    check(uid<0 and local['yc_role']=='user','note-only identity remains separate')
    check(admin_action('approve',device_id=local['device_id'])[0]==200,'approve local account device')
    local_token=local['token']
    local_url='api.php?'+urlencode({'action':'upload','path':'/local.sbn2'})
    local_download='api.php?'+urlencode({'action':'download','path':'/local.sbn2'})
    local_delete='api.php?'+urlencode({'action':'delete','path':'/local.sbn2'})
    local_restore='api.php?'+urlencode({'action':'restore','path':'/local.sbn2'})
    check(request(local_url,method='PUT',body=b'local notebook',token=local_token,headers={'X-YC-Base-Revision':'0'})[0]==200,'note-only account uploads')
    check(admin_action('set_access',user_id=uid,can_write='0',role='user')[0]==200,'website removes edit permission')
    check(json.loads(api('session',token=local_token)[2])['can_write'] is False,'existing token receives read-only permission')
    check(request(local_download,token=local_token)[2]==b'local notebook','read-only account still downloads notes')
    check(request(local_url,method='PUT',body=b'changed',token=local_token,headers={'X-YC-Base-Revision':'1'})[0]==403,'read-only upload denied over HTTP')
    check(request(local_delete,method='DELETE',token=local_token,headers={'X-YC-Base-Revision':'1'})[0]==403,'read-only deletion denied over HTTP')
    check(request(local_restore,method='POST',body={'revision':1,'base_revision':1},token=local_token)[0]==403,'read-only restoration denied over HTTP')
    check(admin_action('remove_user',user_id=uid)[0]==200,'website removes note access')
    check(api('manifest',token=local_token)[0]==401,'removal invalidates existing token')
    check(admin_action('restore_user',user_id=uid,can_write='0')[0]==200,'website restores note access')
    restored=enroll('http-note-user','e'*64,local_password)
    check(restored['can_write'] is False and restored['status']=='approved','restored account defaults to read-only')
    check(request(local_download,token=restored['token'])[2]==b'local notebook','removal and restoration preserve notebook')
    other_client=build_opener(HTTPCookieProcessor(http.cookiejar.CookieJar()))
    html=request(client=other_client)[2]
    csrf=re.search(rb'name="csrf" value="([a-f0-9]+)"',html).group(1).decode()
    form={'action':'login','username':'writer','password':fixture['password'],'csrf':csrf}
    check(request(method='POST',body=urlencode(form).encode(),headers={'Content-Type':'application/x-www-form-urlencoded'},client=other_client)[0]==403,'regular users cannot enter admin site')
    print(f'PASS: {count} HTTP/admin integration checks')
    if os.environ.get('YC_KEEP_PREVIEW')=='1':
        print('Fixture preview: '+base,flush=True)
        print('Fixture config: '+fixture['config'],flush=True)
        while True: time.sleep(1)
finally:
    server.terminate()
    server.wait(timeout=10)
    log.close()
    if os.environ.get('YC_KEEP_PREVIEW')!='1': shutil.rmtree(work)
