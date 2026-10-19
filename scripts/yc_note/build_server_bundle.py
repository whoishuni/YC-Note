#!/usr/bin/env python3
"""Package only deployable YC Note files; never include runtime credentials."""
from pathlib import Path
import argparse,difflib,hashlib,json,zipfile
ROOT=Path(__file__).resolve().parents[2]
parser=argparse.ArgumentParser()
parser.add_argument('--rental-root',required=True,type=Path)
args=parser.parse_args()
output=ROOT/'dist/YC-Note-server-20261019.zip'
output.parent.mkdir(exist_ok=True)
manifest={}
with zipfile.ZipFile(output,'w',zipfile.ZIP_DEFLATED) as archive:
    for path in sorted((ROOT/'server/public').iterdir()):
        if not path.is_file() or path.name=='.local-root.php': continue
        target='public_html/yc_note/'+path.name
        archive.write(path,target);manifest[target]=hashlib.sha256(path.read_bytes()).hexdigest()
    for folder in ['src','security','bin']:
        for path in sorted((ROOT/'server'/folder).rglob('*')):
            if not path.is_file():continue
            target='private/yc-note-server/'+str(path.relative_to(ROOT/'server'))
            archive.write(path,target);manifest[target]=hashlib.sha256(path.read_bytes()).hexdigest()
    archive.write(ROOT/'server/config.example.php','private/yc-note-server/config.example.php')
    archive.write(ROOT/'server/sql/identity-view.example.sql','private/yc-note-server/sql/identity-view.example.sql')
    archive.write(ROOT/'docs/DEPLOYMENT.md','DEPLOYMENT.md')
    for name in ['admin/notes.php','includes/YcNoteModule.php','includes/yc_note_view.php','assets/css/yc-note.css','yc_note/api.php']:
        path=args.rental_root/name
        target='public_html/rent/'+name
        archive.write(path,target);manifest[target]=hashlib.sha256(path.read_bytes()).hexdigest()
    patches=json.loads((ROOT/'scripts/yc_note/rental-patches.json').read_text())
    patch=''
    for name,edits in patches.items():
        current=(args.rental_root/name).read_bytes().decode()
        original=current
        for edit in reversed(edits):
            if original.count(edit['after'])!=1:raise RuntimeError('Rental patch context does not match source')
            original=original.replace(edit['after'],edit['before'],1)
        patch+=''.join(difflib.unified_diff(original.splitlines(keepends=True),current.splitlines(keepends=True),fromfile='a/'+name,tofile='b/'+name))
    archive.writestr('rental-integration.patch',patch)
    archive.write(ROOT/'scripts/yc_note/rental-patches.json','rental-patches.json')
    archive.writestr('public_html/yc_note/.local-root.example.php',"<?php return '/home/ACCOUNT/yc-note-server';\n")
    archive.writestr('manifest.json',json.dumps(manifest,indent=2)+'\n')
print(output)
