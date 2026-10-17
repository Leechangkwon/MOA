import json,os,subprocess
from pathlib import Path
m=json.loads(Path(os.environ['ASSISTANT_TEST_MANIFEST']).read_text());assert m['database'].startswith('tbox_assistant_test_')
r=subprocess.run(['php','tests/assistant/client_diagnostics_fixture.php'],cwd=m['instance'],input=b'{"action":"grants"}',stdout=subprocess.PIPE,check=True)
v=json.loads(r.stdout);checks=[]
for key in ['backup_events','backup_policy','backup_rollups','backup_sequence','app_insert','backup_write','app_update']:
 ok=bool(v[key])==(key not in ('backup_write','app_update'));checks.append({'name':key,'pass':ok});print(('PASS ' if ok else 'FAIL ')+key,flush=True);assert ok,key
(Path(os.environ['ASSISTANT_TEST_OUTPUT'])/'grants.json').write_text(json.dumps(checks,indent=2))
print('CLIENT_GRANTS_PASS=7',flush=True)
