#!/usr/bin/env python3
"""Deploy only YC Note. Credentials arrive on stdin and are never printed."""
from pathlib import Path
from urllib.request import Request, build_opener, HTTPRedirectHandler
from urllib.error import HTTPError
import base64, hashlib, json, os, secrets

ROOT=Path(__file__).resolve().parents[2]
class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self,req,fp,code,msg,headers,newurl): return None
opener=build_opener(NoRedirect)
print('Ready for deployment JSON on stdin (input is not echoed).',flush=True)
data=json.loads(input())
private=Path.home()/'.config/yc-note'
private.mkdir(mode=0o700,parents=True,exist_ok=True)
credential_file=private/'deploy.json'
fd=os.open(credential_file,os.O_WRONLY|os.O_CREAT|os.O_TRUNC,0o600)
with os.fdopen(fd,'w') as handle: json.dump({'db_password':data['db_password'],'database':'u805049489_ycnote','username':'u805049489','domain':'yckuo.com'},handle)
token=secrets.token_hex(32)
name='yc_note_install_'+secrets.token_hex(12)+'.php'
source=(ROOT/'scripts/yc_note/install_hostinger.php').read_text().replace('__INSTALL_TOKEN_HASH__',hashlib.sha256(token.encode()).hexdigest()).encode()
upload=data['upload']
target=upload['url'].rstrip('/')+'/'+name+'?override=true'
auth={'X-Auth':upload['auth_key'],'X-Auth-Rest':upload['rest_auth_key'],'Tus-Resumable':'1.0.0','Upload-Offset':'0'}
def send(url,method,body=None,headers=None):
    with opener.open(Request(url,data=body,method=method,headers=headers or {}),timeout=90) as response: return response.status,response.read()
installer='https://yckuo.com/'+name
try:
    status,_=send(target,'POST',headers={**auth,'Upload-Length':str(len(source))})
    if status!=201: raise RuntimeError('Unexpected upload creation status '+str(status))
    status,_=send(target,'PATCH',source,{**auth,'Content-Type':'application/offset+octet-stream'})
    if status!=204: raise RuntimeError('Unexpected upload status '+str(status))
    paths=['src/bootstrap.php','src/Service.php','src/SecurityBridge.php','public/index.php','public/api.php','public/boot.php','public/style.css','public/icon.svg','public/.htaccess']
    payload={'db_password':data['db_password'],'files':{p:base64.b64encode((ROOT/'server'/p).read_bytes()).decode() for p in paths}}
    status,body=send(installer,'POST',json.dumps(payload).encode(),{'Content-Type':'application/json','X-YC-Install':token})
    result=json.loads(body)
    if not result.get('installed'): raise RuntimeError('Installation did not complete')
    receipt=ROOT/'docs/deployment-2026-10-19.json'
    receipt.parent.mkdir(exist_ok=True)
    receipt.write_text(json.dumps(result,indent=2)+'\n')
    print(json.dumps(result),flush=True)
except HTTPError as error:
    # Installer responses contain only stable error codes, never credentials.
    print('Deployment HTTP status: '+str(error.code),flush=True)
    if error.url==installer:
        try: print(error.read().decode(),flush=True)
        except Exception: pass
    raise SystemExit(1)
finally:
    try: send(installer,'POST',b'{"action":"cleanup"}',{'Content-Type':'application/json','X-YC-Install':token})
    except Exception: pass
