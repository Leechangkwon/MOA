#!/usr/bin/env python3
"""Synthetic HTTP acceptance; authentication is read exclusively from private fixture files."""
from concurrent.futures import ThreadPoolExecutor
import json, os, subprocess, time
from pathlib import Path
import requests

manifest = json.loads(Path(os.environ['FILE_RENAME_MANIFEST']).read_text())
assert manifest['database'].startswith('tbox_stage89_test_')
root = Path(manifest['instance'])
output = Path(os.environ['FILE_RENAME_OUTPUT'])
data = json.loads(Path(manifest['credentials_file']).read_text())
users = data['users']
base = manifest['base_url'].removesuffix('/tbox') + '/community'
checks = []
def check(ok, name):
    checks.append({'pass': bool(ok), 'name':name})
    print(('PASS ' if ok else 'FAIL ') + name, flush=True)
    if not ok: raise AssertionError(name)
def call(method, path, body=None, user='employee', expected=None, csrf=True, second=False):
    headers = {'User-Agent':'tbox-review/'+time.strftime('%Y-%m-%d'), 'Cookie':'__Host-tbox_session='+users[user]['session']}
    if csrf: headers['X-CSRF-Token'] = users[user]['csrf']
    r = requests.request(method, (base.replace(':8488',':'+os.environ['STAGE89_SECOND_PORT']) if second else base)+path, json=body, headers=headers, timeout=25)
    if expected is not None and r.status_code != expected:
        print('SAFE_ERROR_REASON='+str(r.json().get('reason',r.json().get('code','unspecified'))),flush=True)
    if expected is not None: check(r.status_code == expected, f'{method} {path} expected {expected} actual {r.status_code}')
    return r
def sql(text):
    env = {k:v for k,v in os.environ.items() if not k.startswith('PG')}
    env['PGPASSFILE'] = str(Path.home()/'.config/qaredi/pgpass-testdb')
    r = subprocess.run(['psql','-h','127.0.0.1','-U','tbox_testdb','-w','-X','-At','-d',manifest['database'],'-v','ON_ERROR_STOP=1'], env=env,input=text.encode(),stdout=subprocess.PIPE,stderr=subprocess.PIPE,check=True)
    return r.stdout.decode().strip()
try:
    for user in users: call('GET','/api/me/space',user=user,expected=200)
    p = call('POST','/api/pages',{'title':'검증 전용 절차서'},expected=201).json()['page']['id']
    prop = '/api/pages/'+p+'/properties'
    for value in ['90',7,-1,365]: call('PATCH',prop,{'verify':value},expected=400)
    call('PATCH',prop,{'verify':90,'unknown':1},expected=400)
    call('PATCH',prop,{'verify':90},expected=403,csrf=False)
    for user in ['admin','other','guest','manager']: call('PATCH',prop,{'verify':90},user=user,expected=403)
    call('PATCH',prop,{'verify':90,'kind':'procedure'},expected=200)
    view = call('GET','/api/pages/'+p,expected=200).json()
    check(view['verify']['state']=='verified' and view['page_kind']=='procedure' and view['procedures_in_branch']==1, 'C:T-300/305/307 verification and procedure metadata')
    check('id' not in view['verify']['by'], 'verifier response contains display name only')
    call('PATCH',prop,{'verify':90},expected=200)
    check(sql("SELECT count(*) FROM community.page_property_events e JOIN community.pages p ON p.id=e.page_id WHERE p.public_id='"+p+"' AND e.prop='verified'")=='2', 'reverification records a new cycle')
    sql("UPDATE community.pages SET verified_at=now()-interval '2 days',verify_expires_at=now()-interval '1 day' WHERE public_id='"+p+"'")
    check(call('GET','/api/pages/'+p,expected=200).json()['verify']['state']=='expired','C:T-302 expiry computed at read')
    check(p in call('GET','/api/pages?verify=expired',expected=200).text, 'expired list filter')
    for user in ['other','admin']:
        check(p not in call('GET','/api/procedures',user=user,expected=200).text,'C:T-306 no privileged/private procedure enumeration '+user)
    for q in ["verify=%27%3BDROP%20TABLE%20x", "kind=procedure%27%20OR%20%271%27=%271"]:
        call('GET','/api/pages?'+q,expected=200)
    with ThreadPoolExecutor(max_workers=2) as pool:
        list(pool.map(lambda i:call('GET','/api/notifications?unread=1',expected=200,second=bool(i)),range(2)))
    check(sql("SELECT count(*) FROM community.notifications n JOIN community.pages p ON p.id=n.page_id WHERE p.public_id='"+p+"' AND kind='verify_expired'")=='1','C:T-304 concurrent expiry creates one notification')
    call('GET','/api/notifications?unread=1',expected=200)
    check(sql("SELECT count(*) FROM community.notifications WHERE kind='verify_expired'")=='1','second poll creates zero notifications')
    call('PATCH',prop,{'verify':None},expected=200)
    check(call('GET','/api/pages/'+p,expected=200).json()['verify']['state']=='none','clear verification')
    call('PATCH',prop,{'due':'2026-13-01'},expected=400)
    call('PATCH',prop,{'due':'2026-10-02'},expected=200)
    check(call('GET','/api/pages/'+p,expected=200).json()['due']=='2026-10-02','C:T-411 due field')
    # Parallel disjoint property updates must not replace unrequested values.
    with ThreadPoolExecutor(max_workers=3) as pool:
        jobs=[pool.submit(call,'PATCH',prop,b,expected=200,second=bool(i%2)) for i,b in enumerate( [{'status':'doing'},{'due':'2026-10-15'},{'verify':30}])]
        [job.result() for job in jobs]
    v=call('GET','/api/pages/'+p,expected=200).json()
    check(v['page']['status']=='doing' and v['due']=='2026-10-15' and v['verify']['state']=='verified','concurrent status/due/verification preserved')
    rule={'kind':'recurring','name':'synthetic repeat','scope_page_id':p,'trigger':{'type':'schedule','freq':'daily','time':'09:00'},'actions':[{'type':'create_page','source':{'page':p},'parent':p,'title_pattern':'{제목} {YYYY-MM}','status':'todo'},{'type':'notify','to':'owner','text_key':'recurring_created'}]}
    import copy
    for mutate in [lambda r:r['trigger'].update(time='09:05'),lambda r:r.update(extra='x'),lambda r:r['actions'].append({'type':'shell'}),lambda r:r['trigger'].update(freq='monthly',monthday=31)]:
        bad=copy.deepcopy(rule);mutate(bad);call('POST','/api/automations',bad,expected=400)
    a=call('POST','/api/automations',rule,expected=201).json()['automation']['id']
    path='/api/automations/'+a
    call('GET',path,user='other',expected=404)
    call('PATCH',path,{'is_enabled':True},expected=400)
    before=sql("SELECT (SELECT count(*) FROM community.pages)::text||','||(SELECT count(*) FROM community.notifications)||','||(SELECT count(*) FROM community.automation_queue)")
    preview=call('POST',path+'/dry-run',{},expected=200).json()
    check(preview['status']=='ok' and len(preview['would_create'])==1,'C:T-401 real dry-run computes copy plan')
    check(before==sql("SELECT (SELECT count(*) FROM community.pages)::text||','||(SELECT count(*) FROM community.notifications)||','||(SELECT count(*) FROM community.automation_queue)"), 'dry-run does not mutate pages/notifications/queue')
    call('PATCH',path,{'is_enabled':True},expected=200)
    for kind,key in [('on_status','review_requested'),('due_reminder','due_tomorrow')]:
        bad={'kind':kind,'name':'fixed invalid','scope_page_id':p,'trigger':{'type':kind},'actions':rule['actions'][:1],'is_enabled':True}
        if kind=='on_status': bad['trigger']['to']='review'
        call('POST','/api/automations',bad,expected=400)
        bad['actions']=[{'type':'notify','to':'owner','text_key':key}]
        call('POST','/api/automations',bad,expected=201)
    worker=subprocess.run(['php','scripts/community_automations.php','--now','2026-10-03T09:00:00+09:00'],cwd=root,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    check(worker.returncode==0,'C:T-403 isolated worker executes without elevated DB role')
    check(sql("SELECT count(*) FROM community.automation_runs ar JOIN community.automations a ON a.id=ar.automation_id WHERE a.public_id='"+a+"' AND NOT ar.dry_run AND ar.status='ok'")=='1','worker produced one successful immutable run')
    worker=subprocess.run(['php','scripts/community_automations.php','--now','2026-10-03T09:00:00+09:00'],cwd=root,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    check(worker.returncode==0 and sql("SELECT count(*) FROM community.automation_runs ar JOIN community.automations a ON a.id=ar.automation_id WHERE a.public_id='"+a+"' AND NOT ar.dry_run")=='1','same slot executed once')
    runs=call('GET',path+'/runs',expected=200).json()['runs']
    runid=next(r['id'] for r in runs if not r['dry_run'])
    call('POST','/api/automation-runs/'+runid+'/undo',{},user='other',expected=404)
    call('POST','/api/automation-runs/'+runid+'/undo',{},expected=200)

    # 9-C binding and execution run entirely as the actual presser.
    button_rule=copy.deepcopy(rule);button_rule['kind']='button';button_rule['name']='test button';button_rule['trigger']={'type':'button'};button_rule['press_scope']='owner'
    b=call('POST','/api/automations',button_rule,expected=201).json()['automation']['id'];bp='/api/automations/'+b
    call('POST',bp+'/dry-run',{},expected=200);call('PATCH',bp,{'is_enabled':True},expected=200)
    import uuid
    doc={'schema':1,'blocks':[{'id':str(uuid.uuid4()),'type':'button','label':'이번 달 시작 <img>','automation_id':b}]}
    v=call('GET','/api/pages/'+p,expected=200).json()['page']['doc_version']
    call('PATCH','/api/pages/'+p,{'doc':doc,'doc_version':v},expected=200)
    r=call('GET','/api/pages/'+p,expected=200).json()
    check(r['buttons'][b]['can_press'] is True,'C:T-441 server grants owner button press')
    export=call('GET','/api/pages/'+p+'/export?format=md',expected=200)
    check(b not in export.text and '[버튼:' in export.text,'C:T-444 export includes label and excludes execution ID')
    otherpage=call('POST','/api/pages',{'title':'another page'},expected=201).json()['page']['id']
    call('PATCH','/api/pages/'+otherpage,{'doc':doc,'doc_version':1},expected=400)
    result=call('POST',bp+'/run',{'page_id':p},expected=200).json()
    check(result['status']=='ok' and len(result['created_pages'])==1,'9-C real button creates page with public UUID response')
    call('POST',bp+'/run',{'page_id':p},expected=409)
    call('DELETE',bp,expected=200);call('POST',bp+'/run',{'page_id':p},expected=404)
    # Queue creation is atomic with the ordinary property event. No inline notify.
    before=int(sql("SELECT count(*) FROM community.notifications WHERE kind='review_requested'"))
    call('PATCH',prop,{'status':'review'},expected=200)
    check(int(sql("SELECT count(*) FROM community.notifications WHERE kind='review_requested'"))==before,'C:T-421 status request executes no notification')
    check(int(sql('SELECT count(*) FROM community.automation_queue'))==1,'one status queue record')
    worker=subprocess.run(['php','scripts/community_automations.php'],cwd=root,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    check(worker.returncode==0,'9-B status queue worker succeeds')
    check(int(sql("SELECT count(*) FROM community.notifications WHERE kind='review_requested'"))==before,'owner status change generates no self notification')
    call('PATCH',prop,{'status':'done'},expected=200)
    check(call('GET','/api/pages/'+p,expected=200).json()['completed_at'] is not None,'C:T-425 completion derived from append-only event')
    call('PATCH',prop,{'status':'doing'},expected=200)
    check(call('GET','/api/pages/'+p,expected=200).json()['completed_at'] is None,'completion vanishes when reopened')

finally:
    (output/'results.json').write_text(json.dumps(checks,ensure_ascii=False,indent=2))
    print(f"CHECKS={len(checks)} PASS={sum(x['pass'] for x in checks)} FAIL={sum(not x['pass'] for x in checks)}",flush=True)
