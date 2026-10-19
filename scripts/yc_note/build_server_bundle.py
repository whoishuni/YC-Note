#!/usr/bin/env python3
"""Package only deployable YC Note files; never include runtime credentials."""
from pathlib import Path
import hashlib,json,zipfile
ROOT=Path(__file__).resolve().parents[2]
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
    archive.writestr('public_html/yc_note/.local-root.example.php',"<?php return '/home/ACCOUNT/yc-note-server';\n")
    archive.writestr('manifest.json',json.dumps(manifest,indent=2)+'\n')
print(output)
