"""Opt-in diagnostics and release descriptor: real app auth and HTTP, disposable DB only."""
import copy,json,os,subprocess,time,uuid
from pathlib import Path
import requests
m=json.loads(Path(os.environ['ASSISTANT_TEST_MANIFEST']).read_text());assert m['database'].startswith('tbox_assistant_test_')
root=Path(m['instance']);out=Path(os.environ['ASSISTANT_TEST_OUTPUT']);base=m['base_url']
auth=json.loads(Path(m['credentials_file']).read_text());users=auth['users'];checks=[]
def check(ok,name):
    checks.append({'ok':bool(ok),'name':name});print(('PASS ' if ok else 'FAIL ')+name,flush=True)
    if not ok:raise AssertionError(name)
def fixture(action,**kw):
    r=subprocess.run(['php','tests/assistant/client_diagnostics_fixture.php'],cwd=root,input=json.dumps(dict(action=action,**kw)).encode(),stdout=subprocess.PIPE,stderr=subprocess.PIPE,check=True)
    return json.loads(r.stdout)
def token(user,scope):
    p=subprocess.run(['php','tests/assistant/fixture.php','token'],cwd=root,input=json.dumps({'scope':scope,'user_id':users[user]['id']}).encode(),stdout=subprocess.PIPE,stderr=subprocess.PIPE,check=True)
    return json.loads(p.stdout)['token']
def call(method,path,user='employee',body=None,status=200,csrf=True,session=True,tok=None):
    h={'User-Agent':'tbox-review/'+time.strftime('%Y-%m-%d')}
    if session:h['Cookie']='__Host-tbox_session='+users[user]['session']
    if csrf:h['X-CSRF-Token']=users[user]['csrf']
    if tok:h['X-API-Token']=tok
    r=requests.request(method,base+path,headers=h,json=body,timeout=20,allow_redirects=False)
    if r.status_code!=status: print('HTTP_ERROR_CODE='+str(r.json().get('code','')) if r.headers.get('Content-Type','').startswith('application/json') else 'HTTP_NON_JSON',flush=True)
    check(r.status_code==status,method+' '+path.split('?')[0]+' expected '+str(status)+' got '+str(r.status_code));return r

iid=str(uuid.uuid4());rid=str(uuid.uuid4());attempt=str(uuid.uuid4())
def event(kind='run_started',stage='prepare',status='running',**kw):
    value=dict(event_id=str(uuid.uuid4()),installation_id=iid,run_id=rid,attempt_id=attempt,event=kind,stage=stage,status=status,error_code='none',cache='none',version='2026.10.17.3',code_id='a'*64,at=int(time.time()),elapsed_ms=10,attempt_index=1,packet_index=0,retry_count=0,item_count=9,request_id=None,missing_fields={},attempt_kind='initial')
    value.update(kw);return value
def body(events):return dict(schema=1,policy_version='2026-10-17',ack=True,events=events)
try:
    before=fixture('snapshot')
    call('GET','/api/client/release',session=False,status=401)
    call('GET','/api/client/release',session=False,tok=users['employee']['read']['token'])
    call('GET','/api/client/diagnostics',session=False,status=401)
    call('GET','/api/client/diagnostics',session=False,tok=users['employee']['read']['token'],status=403)
    call('GET','/api/client/diagnostics',user='guest',status=403)
    call('GET','/api/admin/client-diagnostics',status=403)
    call('GET','/admin/client-diagnostics',status=403)
    call('GET','/api/admin/client-diagnostics',user='manager',status=403)
    diag=token('employee','read+diagnostics.write');other=token('other','diagnostics.write');guest=token('guest','diagnostics.write')
    call('GET','/api/client/diagnostics',session=False,tok=guest,status=403)
    policy=call('GET','/api/client/diagnostics',session=False,tok=diag).json()['policy']
    check(policy['retention_days']==30 and policy['scope']=='diagnostics.write','documented optional scope and retention')
    call('POST','/api/client/diagnostics',body=body([event()]),csrf=False,status=403)
    bad=body([event()]);bad['ack']=False
    call('POST','/api/client/diagnostics',session=False,tok=diag,body=bad,status=400)
    e=event();accepted=call('POST','/api/client/diagnostics',session=False,tok=diag,body=body([e]),status=201).json()
    check(accepted['accepted_ids']==[e['event_id']] and accepted['stored']==1,'exact acknowledgment')
    again=call('POST','/api/client/diagnostics',session=False,tok=diag,body=body([e,e]),status=201).json()
    check(again['stored']==0 and again['duplicate']==1,'uncertain delivery and repeated payload deduplicate')
    conflict=copy.deepcopy(e);conflict['item_count']=99
    call('POST','/api/client/diagnostics',session=False,tok=diag,body=body([conflict]),status=409)
    for update in [{'raw_prompt':'SENSITIVE SYNTHETIC'}, {'missing_fields':{'patient':1}}, {'request_id':str(uuid.uuid1())}, {'at':int(time.time())+1000}, {'stage':'save'}, {'item_count':True}]:
        bad=event();bad.update(update);call('POST','/api/client/diagnostics',session=False,tok=diag,body=body([bad]),status=400)
    call('POST','/api/client/diagnostics',session=False,tok=diag,body=body([event() for _ in range(101)]),status=400)
    failed=event('run_failed','validate','failure',error_code='source_numbers_invalid')
    resumed_id=str(uuid.uuid4());common=dict(attempt_id=resumed_id,attempt_index=2,attempt_kind='resume')
    values=[failed,event(**common),event('checkpoint_reused','validate','success',cache='checkpoint',missing_fields={'tax':1},**common),event('result_saved','save','success',**common),event('run_completed','complete','success',**common),event('run_completed','complete','success',run_id=str(uuid.uuid4()))]
    call('POST','/api/client/diagnostics',session=False,tok=diag,body=body(values),status=201)
    call('GET','/api/admin/client-diagnostics',user='admin',status=403)
    call('POST','/api/me/reauth',user='admin',body={'password':users['admin']['password']})
    summary=call('GET','/api/admin/client-diagnostics',user='admin').json()['summary']
    metrics=summary['metrics'];check(metrics['observed_runs']==1 and metrics['completed_runs']==1 and metrics['censored_runs']==1,'unobserved starts excluded from completion denominator')
    check(metrics['first_completion_rate']==0 and metrics['resume_success_rate']==1 and metrics['cache_reuses']==1,'first completion resume and cache semantics')
    check(summary['improvement_candidates'][0]['test_target']=='test_closing_missing_values' and summary['cost'] is None,'fixed reproduction target without invented cost')
    encoded=json.dumps(summary);check('SENSITIVE' not in encoded and users['employee']['username'] not in encoded and 'owner_id' not in encoded,'admin receives aggregated privacy-safe records')
    other_event=event();call('POST','/api/client/diagnostics',session=False,tok=other,body=body([other_event]),status=201)
    call('DELETE','/api/client/diagnostics',session=False,tok=other,body={'installation_id':iid})
    check(call('GET','/api/client/diagnostics',session=False,tok=diag).json()['events']==7,'delete cannot affect another account even same installation ID')
    policybody=dict(retention_days=30,daily_enabled=True,weekly_enabled=True,daily_hour=2,weekly_weekday=1)
    call('POST','/api/admin/client-diagnostics',user='admin',body=policybody,csrf=False,status=403)
    call('POST','/api/admin/client-diagnostics',user='admin',body=dict(policybody,retention_days=31),status=400)
    call('POST','/api/admin/client-diagnostics',user='admin',body=policybody)
    aged=event();call('POST','/api/client/diagnostics',session=False,tok=diag,body=body([aged]),status=201);fixture('age',id=aged['event_id'])
    check(fixture('rollup',now='2026-10-19T01:00:00+09:00')['created']==[],'schedule does not run before configured hour')
    check(set(fixture('rollup',now='2026-10-19T03:00:00+09:00')['created'])=={'day','week'},'daily and weekly candidate generation')
    check(fixture('rollup',now='2026-10-19T03:05:00+09:00')['created']==[],'periodic summaries idempotent')
    check(call('GET','/api/client/diagnostics',session=False,tok=diag).json()['events']==7,'retention deletes only expired diagnostic record')
    page=call('GET','/admin/client-diagnostics',user='admin');check('수신 기록이 없는 PC의 상태는 알 수 없습니다' in page.content.decode('utf-8') and 'diagnostics-policy' in page.text,'admin page explains observation boundary')
    fixture('quota',uid=users['employee']['id'])
    call('POST','/api/client/diagnostics',session=False,tok=diag,body=body([event()]),status=429)
    call('POST','/api/client/diagnostics',session=False,tok=diag,body=body([e]),status=201)
    call('DELETE','/api/client/diagnostics',session=False,tok=diag,body={'installation_id':iid})
    check(call('GET','/api/client/diagnostics',session=False,tok=diag).json()['events']==0,'user removes own installation diagnostic rows')
    after=fixture('snapshot');check(after['files']==before['files'],'original files byte/row metadata preserved')
    old={r['id']:r for r in before['scopes']};new={r['id']:r for r in after['scopes']}
    check(all(new[k]==v for k,v in old.items()),'existing token scopes and hashes preserved')
finally:(out/'client-diagnostics-checks.json').write_text(json.dumps(checks,ensure_ascii=False,indent=2))
print('CLIENT_DIAGNOSTICS_PASS='+str(len(checks)),flush=True)
