#!/usr/bin/env python3
"""Deploy only YC Note. Credentials arrive on stdin and are never printed."""
from pathlib import Path
from datetime import date
from urllib.request import Request, build_opener, HTTPRedirectHandler
from urllib.error import HTTPError
import base64, hashlib, json, os, re, secrets

ROOT=Path(__file__).resolve().parents[2]
class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self,req,fp,code,msg,headers,newurl): return None
opener=build_opener(NoRedirect)
print('Ready for deployment JSON on stdin (input is not echoed).',flush=True)
data=json.loads(input())
private=Path.home()/'.config/yc-note'
private.mkdir(mode=0o700,parents=True,exist_ok=True)
credential_file=private/'deploy.json'
if 'db_password' not in data: data['db_password']=json.loads(credential_file.read_text())['db_password']
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
    paths=['src/SaberCloud.php','public/saber.php','public/avatar.png','src/bootstrap.php','src/Service.php','src/SyncTransactions.php','src/NotebookVersions.php','src/Notebooks.php','src/SecurityBridge.php','public/index.php','public/api.php','public/boot.php','public/style.css','public/icon.svg','public/.htaccess']
    rental=Path(data['rental_root']).resolve()
    native=['assets/js/yc-note/.htaccess','assets/vendor/yc-note/.htaccess','admin/notes_cloud.php','assets/js/yc-note/crypto.mjs','assets/js/yc-note/notebook.mjs','assets/js/yc-note/renderer.mjs','assets/js/yc-note/cloud.mjs','assets/vendor/yc-note/deps.mjs','assets/vendor/yc-note/pdf.min.mjs','assets/vendor/yc-note/pdf.worker.min.mjs','assets/vendor/yc-note/LICENSE.txt','assets/vendor/yc-note/Neucha-Regular.ttf','assets/vendor/yc-note/OFL.txt','admin/notes_connect.php','yc_note/saber.php','yc_note/saber/index.php','yc_note/saber/remote.php','yc_note/saber/status.php','yc_note/saber/ocs/v1.php','yc_note/saber/ocs/v2.php','yc_note/saber/.htaccess','admin/notes.php','includes/YcNoteModule.php','includes/yc_note_view.php','assets/css/yc-note.css','yc_note/api.php']
    vendor=json.loads((rental/'assets/vendor/yc-note/manifest.json').read_text())
    for name,digest in vendor.items():
        path=Path(name)
        if not re.fullmatch(r'(?:(?:cmaps|standard_fonts|wasm)/)?[A-Za-z0-9][A-Za-z0-9_.-]*',name):raise RuntimeError('Invalid web asset name')
        if path.suffix[1:].lower() not in ['mjs','js','json','txt','ttf','otf','pfb','bcmap','wasm'] and not re.fullmatch(r'LICENSE(?:_[A-Z0-9_]+)?',path.name):raise RuntimeError('Invalid web asset type')
        if path.is_absolute() or any(p in ['', '.', '..'] for p in path.parts):raise RuntimeError('Invalid web asset path')
        source=rental/'assets/vendor/yc-note'/path
        if hashlib.sha256(source.read_bytes()).hexdigest()!=digest:raise RuntimeError('Web asset hash mismatch')
        native.append('assets/vendor/yc-note/'+name)
    native.append('assets/vendor/yc-note/manifest.json')
    native=list(dict.fromkeys(native))
    patches=json.loads((ROOT/'scripts/yc_note/rental-patches.json').read_text())
    for name,edits in patches.items():
        source=(rental/name).read_bytes()
        if edits[-1]['after'].encode() not in source:raise RuntimeError('Rental source does not match integration patch')
    patches={name:[{k:base64.b64encode(v.encode()).decode() for k,v in edit.items()} for edit in edits] for name,edits in patches.items()}
    payload={'db_password':data['db_password'],'files':{p:base64.b64encode((ROOT/'server'/p).read_bytes()).decode() for p in paths},'rental_files':{p:base64.b64encode((rental/p).read_bytes()).decode() for p in native},'rental_patches':patches,'vendor_manifest':vendor}
    status,body=send(installer,'POST',json.dumps(payload).encode(),{'Content-Type':'application/json','X-YC-Install':token})
    result=json.loads(body)
    if not result.get('installed'): raise RuntimeError('Installation did not complete')
    receipt=ROOT/('docs/deployment-'+date.today().isoformat()+'.json')
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
