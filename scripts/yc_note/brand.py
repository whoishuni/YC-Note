#!/usr/bin/env python3
"""Render repo-native YC vectors into existing platform icon slots (requires rsvg-convert)."""
from pathlib import Path
import json, struct, subprocess
ROOT = Path(__file__).resolve().parents[2]
GLYPH = '<path d="M254 337 350 486 446 337M350 486v201" fill="none" stroke="white" stroke-width="58" stroke-linecap="round" stroke-linejoin="round"/><path d="M756 377c-33-31-65-43-104-43-91 0-149 76-149 178s58 178 149 178c39 0 71-12 104-43" fill="none" stroke="white" stroke-width="58" stroke-linecap="round"/>'
def svg(rounded=True, foreground=False):
    bg = '' if foreground else '<defs><linearGradient id="yc" x1="0" y1="0" x2="1" y2="1"><stop stop-color="#6366f1"/><stop offset="1" stop-color="#8b5cf6"/></linearGradient></defs><rect width="1024" height="1024" rx="%d" fill="url(#yc)"/>' % (224 if rounded else 0)
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1024 1024">'+bg+GLYPH+'</svg>\n'
icons=ROOT/'assets/icon'
for f in icons.glob('icon*.svg'): f.write_text(svg(rounded=f.stem not in ('icon_opaque','icon_ios_dark','icon_ios_tintable','icon_maskable')))
(ROOT/'server/public/icon.svg').write_text(svg())
for directory in (ROOT/'ios/Runner/Assets.xcassets/AppIcon.appiconset', ROOT/'macos/Runner/Assets.xcassets/AppIcon.appiconset', ROOT/'android/app/src/main/res', icons):
    for f in directory.rglob('*.png'):
        if directory==icons and 'feature_graphic' in f.name: continue
        if directory.name=='res' and not f.name.startswith('ic_launcher'): continue
        header=f.read_bytes()[:24]
        if header[:8] != b'\x89PNG\r\n\x1a\n': continue
        w,h=struct.unpack('>II',header[16:24])
        source=icons/('icon_opaque.svg' if 'ios' in f.parts else 'icon.svg')
        subprocess.run(['rsvg-convert','-w',str(w),'-h',str(h),str(source),'-o',str(f)],check=True)
composer=ROOT/'ios/Runner/AppIcon.icon'
(composer/'Assets/foreground.svg').write_text(svg(foreground=True))
data=json.loads((composer/'icon.json').read_text())
data['fill-specializations']=[{'value':{'solid':'srgb:0.38824,0.40000,0.94510,1.00000'}}]
data['groups']=[{'layers':[{'glass':False,'image-name':'foreground.svg','name':'YC'}],'shadow':{'kind':'neutral','opacity':0.25},'translucency':{'enabled':False,'value':0}}]
(composer/'icon.json').write_text(json.dumps(data,indent=2)+'\n')
subprocess.run(['magick',str(icons/'icon.png'),'-define','icon:auto-resize=256,128,64,48,32,16',str(ROOT/'windows/runner/resources/app_icon.ico')],check=True)
print('Rendered YC Note icons for iOS, macOS, Android, Windows, Linux and web.')
