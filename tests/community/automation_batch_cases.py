"""Additional stage 9 backend acceptance. Run only from the synthetic stage89 harness."""
import copy
import json
import os
from pathlib import Path
import subprocess
import time
import uuid
import requests
from datetime import datetime,timedelta
from zoneinfo import ZoneInfo

TEST_SLOT=(datetime.now(ZoneInfo("Asia/Seoul"))+timedelta(days=2)).replace(hour=9,minute=0,second=0,microsecond=0)

manifest=json.loads(Path(os.environ['FILE_RENAME_MANIFEST']).read_text())
assert manifest['database'].startswith('tbox_stage89_test_')
root=Path(manifest['instance']); output=Path(os.environ['FILE_RENAME_OUTPUT'])
users=json.loads(Path(manifest['credentials_file']).read_text())['users']
base=manifest['base_url'].removesuffix('/tbox')+'/community'; checks=[]
def check(ok,name):
    checks.append({'pass':bool(ok),'name':name}); print(('PASS ' if ok else 'FAIL ')+name,flush=True)
    if not ok: raise AssertionError(name)
def call(method,path,body=None,user='employee',expected=200):
    r=requests.request(method,base+path,json=body,headers={'User-Agent':'tbox-review/'+time.strftime('%Y-%m-%d'),'Cookie':'__Host-tbox_session='+users[user]['session'],'X-CSRF-Token':users[user]['csrf']},timeout=30)
    check(r.status_code==expected,f'{method} {path} {r.status_code}/{expected}')
    return r.json()
def sql(text):
    env={k:v for k,v in os.environ.items() if not k.startswith('PG')};env['PGPASSFILE']=str(Path.home()/'.config/qaredi/pgpass-testdb')
    p=subprocess.run(['psql','-h','127.0.0.1','-U','tbox_testdb','-w','-X','-At','-d',manifest['database'],'-v','ON_ERROR_STOP=1'],env=env,input=text.encode(),capture_output=True,check=True)
    return p.stdout.decode().strip()
def worker(now=None):
    now=now or TEST_SLOT.isoformat()
    p=subprocess.run(['php','scripts/community_automations.php','--now',now],cwd=root,capture_output=True)
    if p.returncode: print(p.stderr.decode()[-1200:],flush=True)
    check(p.returncode==0,'worker successful '+p.stdout.decode().strip());return p.stdout.decode()
def page(title='backend case',user='employee',**extra):
    return call('POST','/api/pages',dict(title=title,**extra),user,201)['page']['id']
def template(pid,name,scope='personal'):
    return call('POST','/api/templates',{'page_id':pid,'name':name,'scope':scope},expected=201)['template']['id']
def rule(source, parent, bound=None, kind='button', actions=None):
    d={'kind':kind,'name':'backend regression','scope_page_id':bound,'trigger':{'type':'button'} if kind=='button' else {'type':'schedule','freq':'daily','time':'09:00'},'actions':actions or [{'type':'create_page','source':source,'parent':parent,'title_pattern':'{제목} copy'}]}
    if kind=='button':d['press_scope']='owner'
    return d
def make(d):return call('POST','/api/automations',d,expected=201)['automation']['id']
def enable(a):
    result=call('POST','/api/automations/'+a+'/dry-run',{});check(result['status']=='ok','dry-run successful')
    call('PATCH','/api/automations/'+a,{'is_enabled':True})
def run(a,bound,user='employee'):return call('POST','/api/automations/'+a+'/run',{'page_id':bound},user)

try:
    from datetime import datetime
    from zoneinfo import ZoneInfo
    for user in users:call('GET','/api/me/space',user=user)
    source=page('batch procedure');call('PATCH','/api/pages/'+source+'/properties',{'kind':'procedure'})
    call('PUT','/api/pages/'+source+'/shares/claude_employee02',{'level':'edit'})
    proto=make(rule({'page':source},source,kind='recurring'))
    fixed={'kind':'on_status','name':'queued fairness','scope_page_id':source,'trigger':{'type':'on_status','to':'review'},'actions':[{'type':'notify','to':'owner','text_key':'review_requested'}],'is_enabled':True}
    queued=make(fixed);call('PATCH','/api/pages/'+source+'/properties',{'status':'review'},user='other')
    sql("WITH events AS (INSERT INTO community.page_property_events(page_id,prop,from_value,to_value,by_user_id) SELECT e.page_id,e.prop,e.from_value,e.to_value,e.by_user_id FROM community.page_property_events e JOIN community.automation_queue q ON q.event_id=e.id CROSS JOIN generate_series(1,200) g WHERE q.id=(SELECT min(id) FROM community.automation_queue) RETURNING id,page_id) INSERT INTO community.automation_queue(automation_id,page_id,event_id) SELECT (SELECT automation_id FROM community.automation_queue ORDER BY id LIMIT 1),page_id,id FROM events")
    sql("INSERT INTO community.automations(public_id,owner_user_id,kind,name,scope_page_id,trigger,actions,is_enabled,last_dry_run_at,created_at) SELECT gen_random_uuid(),owner_user_id,kind,'fairness-'||g,scope_page_id,trigger,actions,true,now(),now()-interval '2 days' FROM community.automations CROSS JOIN generate_series(1,201) g WHERE public_id='"+proto+"'")
    worker();check(sql('SELECT count(*) FROM community.automation_queue WHERE taken_at IS NOT NULL')=='0','scheduled work consumes first bounded turn')
    worker();check(sql('SELECT count(*) FROM community.automation_queue WHERE taken_at IS NOT NULL')=='200','queue has next-turn priority despite scheduled backlog')
    worker();check(sql('SELECT count(*) FROM community.automation_queue WHERE taken_at IS NOT NULL')=='201','remaining queue item finishes')
    check(sql("SELECT count(*) FROM community.automation_runs r JOIN community.automations a ON a.id=r.automation_id WHERE a.name LIKE 'fairness-%'")=='201','recurring tail also finishes after queue priority')
    sql('UPDATE community.automations SET is_enabled=false')
    myspace=int(sql("SELECT s.id FROM community.spaces s JOIN users u ON u.id=s.owner_user_id WHERE s.kind='personal' AND u.username='claude_employee01'"))
    now=datetime.now(ZoneInfo('Asia/Seoul')).replace(hour=9,minute=0,second=0,microsecond=0).isoformat()
    sql("INSERT INTO community.pages(space_id,title,doc,plain_text,block_count,author_id,last_edited_by,due_date) SELECT space_id,'due fairness '||g,doc,plain_text,block_count,author_id,last_edited_by,(now() AT TIME ZONE 'Asia/Seoul')::date+1 FROM community.pages CROSS JOIN generate_series(1,201) g WHERE public_id='"+source+"'")
    due={'kind':'due_reminder','name':'due fairness','scope_space_id':myspace,'trigger':{'type':'due_reminder'},'actions':[{'type':'notify','to':'owner','text_key':'due_tomorrow'}],'is_enabled':True}
    make(due);worker(now)
    check(sql("SELECT count(*) FROM community.notifications WHERE kind='due_tomorrow'")=='200','due work shares 200 cap')
    worker(now);check(sql("SELECT count(*) FROM community.notifications WHERE kind='due_tomorrow'")=='201','due scan excludes delivered pages before its limit')
    worker(now);check(sql("SELECT count(*) FROM community.notifications WHERE kind='due_tomorrow'")=='201','due repeated scan sends no duplicate')
    sql('UPDATE community.automations SET is_enabled=false');call('GET','/api/spaces')
    team=int(sql("SELECT s.id FROM community.spaces s JOIN users u ON u.team_id=s.team_id WHERE s.kind='team' AND u.username='claude_employee01'"))
    sql("INSERT INTO community.pages(space_id,title,doc,plain_text,block_count,author_id,last_edited_by,due_date) SELECT "+str(team)+",'denied due '||g,doc,plain_text,block_count,(SELECT id FROM users WHERE username='claude_employee02'),last_edited_by,(now() AT TIME ZONE 'Asia/Seoul')::date+1 FROM community.pages CROSS JOIN generate_series(1,5) g WHERE public_id='"+source+"'")
    due['name']='denied recipients';due['scope_space_id']=team;denied=make(due)
    sql("UPDATE users SET is_active=false WHERE username='claude_employee02'");worker(now)
    check(sql("SELECT is_enabled FROM community.automations WHERE public_id='"+denied+"'")=='f','due loop stops after third denial disables rule without crashing')
    sql('UPDATE community.automations SET is_enabled=false')
    sql("UPDATE users SET is_active=true WHERE username='claude_employee02'")
    changed=make(fixed)
    call('PATCH','/api/pages/'+source+'/properties',{'status':'doing'},user='other')
    call('PATCH','/api/pages/'+source+'/properties',{'status':'review'},user='other')
    changed_rule=rule({'page':source},source,source,kind='recurring');changed_rule['trigger']['time']='23:50'
    call('PATCH','/api/automations/'+changed,changed_rule);enable(changed)
    before=sql('SELECT count(*) FROM community.pages');worker(now)
    check(sql('SELECT count(*) FROM community.pages')==before,'queued status event never invokes replacement recurring create action')
    check(sql('SELECT count(*) FROM community.automation_queue WHERE taken_at IS NULL')=='0','obsolete trigger queue entry consumed safely')
finally:
    (output/'batch-results.json').write_text(json.dumps(checks,ensure_ascii=False,indent=2))
    print(f'BATCH_CHECKS={len(checks)} PASS={sum(c["pass"] for c in checks)} FAIL={sum(not c["pass"] for c in checks)}',flush=True)
