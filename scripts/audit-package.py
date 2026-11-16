#!/usr/bin/env python3
"""Audit archives and local documentation without contacting external services."""
import hashlib, io, json, os, re, zipfile
from pathlib import Path
from html.parser import HTMLParser
root=Path(__file__).resolve().parents[1]
manifest=json.loads((root/'dist/checksums.json').read_text())
forbidden={'.git','.runtime','node_modules','vendor','__pycache__','.env'}
# Optional known local credentials supplied only by environment, never written.
secrets=[v.encode() for k,v in os.environ.items() if k.startswith('LUDORA_AUDIT_SECRET_') and v]
counts={}
def inspect(data,name):
 with zipfile.ZipFile(io.BytesIO(data)) as archive:
  assert archive.testzip() is None,name
  for info in archive.infolist():
   path=Path(info.filename)
   assert not path.is_absolute() and '..' not in path.parts,name
   assert not forbidden.intersection(path.parts),str(path)
   body=archive.read(info)
   assert not any(secret in body for secret in secrets),f'Credential found in {path.name}'
   if info.filename.endswith('.zip'):inspect(body,info.filename)
  return len(archive.infolist())
for name,entry in manifest.items():
 data=(root/'dist'/name).read_bytes();assert hashlib.sha256(data).hexdigest()==entry['sha256'],name;counts[name]=inspect(data,name)
with zipfile.ZipFile(root/'dist/ludora.zip') as archive:assert archive.read('ludora/plugins/ludora-booking.zip')==(root/'dist/ludora-booking.zip').read_bytes()
class Links(HTMLParser):
 def __init__(self):super().__init__();self.paths=[]
 def handle_starttag(self,tag,attrs):
  for key,value in attrs:
   if key in ('href','src') and value and not re.match(r'^(?:[a-z]+:|#|/)',value):self.paths.append(value.split('#')[0].split('?')[0])
for page in (root/'docs/guides').glob('*.html'):
 parser=Links();parser.feed(page.read_text())
 for path in parser.paths:assert (page.parent/path).is_file(),f'Broken offline documentation link: {page.name}: {path}'
report={'archives':counts,'zipIntegrity':'PASS','secretsAndExcludedDirectories':'PASS','companionBytesMatch':'PASS','offlineDocumentationLinks':'PASS'}
(root/'tests/artifacts/package-audit.json').write_text(json.dumps(report,indent=2)+'\n')
print(json.dumps(report,indent=2))
