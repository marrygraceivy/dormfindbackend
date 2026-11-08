"""python tests/read_only_smoke.py http://localhost:8000 (no database writes)."""
import json,sys,urllib.request,urllib.error
base=(sys.argv[1] if len(sys.argv)>1 else 'http://localhost:8000').rstrip('/')
for path in ['/api/config','/api/health','/api/properties']:
 try:
  with urllib.request.urlopen(base+path,timeout=30) as r: data=json.load(r)['data']
 except urllib.error.HTTPError as e: raise SystemExit(e.read().decode())
 if path.endswith('/health'): assert data['ready'], data
 if path.endswith('/properties'): assert isinstance(data,list)
 print('PASS',path)
try: urllib.request.urlopen(urllib.request.Request(base+'/api/properties/1',method='DELETE'))
except urllib.error.HTTPError as e: assert e.code==401
else: raise AssertionError('Unauthenticated delete was accepted')
print('PASS unauthenticated mutation blocked')
