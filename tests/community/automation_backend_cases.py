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
    for user in users:call('GET','/api/me/space',user=user)
    bound=page('button bound'); first=template(page('original A'),'template A'); second=template(page('original B'),'template B')
    d=rule({'template':first},None,bound);a=make(d);enable(a);made=run(a,bound);pid=made['created_pages'][0]['id']
    d['actions'][0]['source']={'template':second};call('PATCH','/api/automations/'+a,d)
    info=call('GET','/api/pages/'+pid)['repeat'];check(info['source']['id']==first,'historical source remains A after rule changes to B')
    call('DELETE','/api/templates/'+first)
    check(call('GET','/api/pages/'+pid)['repeat']['source'] is None,'deleted source title and link withheld')
    check(sql("SELECT steps->0->'source'->>'template' FROM community.automation_runs WHERE public_id='"+made['id']+"'")==first,'immutable run records source UUID')

    # Database templates retain row identity and filter values against current schema.
    db_result=call('POST','/api/pages',{'title':'automation database','database':True},expected=201)['page'];dbid=db_result['database']['id'];dbpage=db_result['id']
    schema=call('PATCH','/api/databases/'+dbid+'/schema',{'add':{'name':'kept','type':'text'}})['database']['schema'];k1=schema[0]['key']
    schema=call('PATCH','/api/databases/'+dbid+'/schema',{'add':{'name':'removed','type':'number'}})['database']['schema'];k2=schema[1]['key']
    row=page('source row',parent_id=dbpage,properties={k1:'kept value',k2:9});tpl=template(row,'row repeat','database')
    call('PATCH','/api/databases/'+dbid+'/schema',{'remove':{'key':k2}})
    db_rule=rule({'template':tpl},None,bound);dbauto=make(db_rule);enable(dbauto);dbmade=run(dbauto,bound)['created_pages'][0]['id']
    saved=json.loads(sql("SELECT json_build_object('parent',pp.public_id,'db',d.public_id,'props',p.properties) FROM community.pages p JOIN community.pages pp ON pp.id=p.parent_page_id JOIN community.databases d ON d.id=p.database_id WHERE p.public_id='"+dbmade+"'"))
    check(saved=={'parent':dbpage,'db':dbid,'props':{k1:'kept value'}},'database template chooses bound DB and removes stale property')
    wrong=copy.deepcopy(db_rule);wrong['actions'][0]['parent']=bound;call('POST','/api/automations',wrong,expected=400)
    personal=make(rule({'template':second},None,kind='recurring'));enable(personal)
    call('DELETE','/api/templates/'+second)
    for day in range(3):worker((TEST_SLOT+timedelta(days=day)).isoformat())
    disabled=call('GET','/api/automations/'+personal)['automation'];check(not disabled['is_enabled'] and disabled['denied_streak']==3,'C:T-404 third source denial disables unscoped template rule')
    check(int(sql("SELECT count(*) FROM community.notifications WHERE kind='automation_disabled' AND page_id IS NULL"))>=1,'K9-5 safe owner notice without readable source page')
    call('POST','/api/automation-runs/'+made['id']+'/undo',{},user='other',expected=404)
    sql("UPDATE community.automation_runs SET started_at=now()-interval '8 days' WHERE public_id='"+made['id']+"'")
    call('POST','/api/automation-runs/'+made['id']+'/undo',{},expected=400)

    # Editor buttons use the clicker's permission, never the rule owner's private template.
    shared=page('shared procedure');call('PATCH','/api/pages/'+shared+'/properties',{'kind':'procedure'})
    call('PUT','/api/pages/'+shared+'/shares/claude_employee02',{'level':'edit'})
    editors=rule({'page':shared},shared,shared);editors['press_scope']='editors';ed=make(editors);enable(ed)
    result=run(ed,shared,'other');check(result['status']=='ok' and len(result['created_pages'])==1,'C:T-441 editors button creates with clicker authority')
    private=template(page('private template body'),'private template')
    deny_rule=rule({'template':private},None,shared);deny_rule['press_scope']='editors';deny=make(deny_rule);enable(deny)
    result=run(deny,shared,'other');check(result['status']=='denied' and result['created_pages']==[],'C:T-442 private template not read using rule owner authority')
    # Another person's edit prevents undo.
    ownrun=run(ed,shared);ownpage=ownrun['created_pages'][0]['id'];view=call('GET','/api/pages/'+ownpage,user='other')['page']
    doc={'schema':1,'blocks':[{'id':str(uuid.uuid4()),'type':'paragraph','text':[{'t':'edited by other','m':{}}]}]}
    call('PATCH','/api/pages/'+ownpage,{'doc':doc,'doc_version':view['doc_version']},user='other')
    undo=call('POST','/api/automation-runs/'+ownrun['id']+'/undo',{})
    check(undo['deleted_pages']==[] and undo['skipped'][0]['id']==ownpage,'C:T-407 another editor page preserved by undo')
    # Inactive owner stops immediately and receives a generic status notification.
    inactive=make(rule({'page':shared},shared,kind='recurring'));enable(inactive)
    sql("UPDATE public.users SET is_active=false WHERE username='claude_employee01'");worker()
    check(sql("SELECT is_enabled FROM community.automations WHERE public_id='"+inactive+"'")=='f','C:T-404 inactive owner immediately disabled')
    sql("UPDATE public.users SET is_active=true WHERE username='claude_employee01'")
    # Read scope must precede the expiry cap: twenty old-team pages cannot hide one current page.
    call('GET','/api/spaces');call('GET','/api/spaces',user='other')
    own_team=int(sql("SELECT s.id FROM community.spaces s JOIN users u ON u.team_id=s.team_id WHERE s.kind='team' AND u.username='claude_employee01'"))
    other_team=int(sql("SELECT s.id FROM community.spaces s JOIN users u ON u.team_id=s.team_id WHERE s.kind='team' AND u.username='claude_employee02'"))
    current=page('current expiry');call('PATCH','/api/pages/'+current+'/properties',{'verify':30})
    sql("UPDATE community.pages SET verified_at=now()-interval '40 days',verify_expires_at=now()-interval '10 days' WHERE public_id='"+current+"'")
    sql("INSERT INTO community.pages(space_id,title,doc,plain_text,block_count,author_id,last_edited_by,verified_by,verified_at,verify_expires_at) SELECT "+str(other_team)+",'hidden expiry '||g,doc,plain_text,block_count,author_id,last_edited_by,verified_by,verified_at-interval '10 days',verify_expires_at-interval '10 days' FROM community.pages CROSS JOIN generate_series(1,20) g WHERE public_id='"+current+"'")
    call('GET','/api/notifications')
    check(sql("SELECT count(*) FROM community.notifications n JOIN community.pages p ON p.id=n.page_id WHERE n.kind='verify_expired' AND p.public_id='"+current+"'")=='1','expiry ordinary-read SQL applied before LIMIT 20')
    check(sql("SELECT count(*) FROM community.notifications n JOIN community.pages p ON p.id=n.page_id WHERE n.kind='verify_expired' AND p.title LIKE 'hidden expiry %'")=='0','old team expiry sends no restricted notifications')
    team_parent=page('same team parent',space_id=own_team);team_tpl=template(team_parent,'team source','team')
    wrong_parent=page('other team parent',user='other',space_id=other_team)
    call('POST','/api/automations',rule({'template':team_tpl},wrong_parent,kind='recurring'),expected=400)
    same=make(rule({'template':team_tpl},team_parent,kind='recurring'));call('POST','/api/automations/'+same+'/dry-run',{})
    personal_team=make(rule({'template':team_tpl},None,kind='recurring'));call('POST','/api/automations/'+personal_team+'/dry-run',{})
    check(True,'C:T-405 other team denied while same team and own personal allowed')
    # Explicitly isolated fixture bulk setup bypasses per-person cap ONLY to exercise global batch bounds.
    sql('UPDATE community.automations SET is_enabled=false')
    bulk=make(rule({'page':shared},shared,kind='recurring'))
    sql("INSERT INTO community.automations(public_id,owner_user_id,kind,name,scope_page_id,trigger,actions,is_enabled,last_dry_run_at,created_at) SELECT gen_random_uuid(),owner_user_id,kind,'batch-201-'||g,scope_page_id,trigger,actions,true,now(),now()-interval '2 days' FROM community.automations CROSS JOIN generate_series(1,201) g WHERE public_id='"+bulk+"'")
    worker();check(sql("SELECT count(*) FROM community.automation_runs r JOIN community.automations a ON a.id=r.automation_id WHERE a.name LIKE 'batch-201-%' AND NOT r.dry_run")=='200','K9-10 first invocation stops at 200 work')
    worker();check(sql("SELECT count(*) FROM community.automation_runs r JOIN community.automations a ON a.id=r.automation_id WHERE a.name LIKE 'batch-201-%' AND NOT r.dry_run")=='201','K9-10 durable cursor reaches rule 201 next invocation')
    worker();check(sql("SELECT count(*) FROM community.automation_runs r JOIN community.automations a ON a.id=r.automation_id WHERE a.name LIKE 'batch-201-%' AND NOT r.dry_run")=='201','same scheduled slot remains idempotent after cursor wrap')
    check(int(sql("SELECT count(*) FROM public.audit_logs WHERE action='page.create' AND actor_name='claude_employee01' AND user_agent LIKE 'qaredi-automation/%' AND detail ? 'automation_id' AND detail ? 'run_id'"))>=201,'C:T-410 CLI page audit carries current actor, automation UA and run IDs')
    sql('UPDATE community.automations SET is_enabled=false')
    five=rule({'page':shared},shared,kind='recurring');five['actions']*=5;prototype=make(five)
    sql("INSERT INTO community.automations(public_id,owner_user_id,kind,name,scope_page_id,trigger,actions,is_enabled,last_dry_run_at,created_at) SELECT gen_random_uuid(),owner_user_id,kind,'batch-505-'||g,scope_page_id,trigger,actions,true,now(),now()-interval '2 days' FROM community.automations CROSS JOIN generate_series(1,101) g WHERE public_id='"+prototype+"'")
    worker();check(sql("SELECT coalesce(sum(cardinality(r.created_page_ids)),0) FROM community.automation_runs r JOIN community.automations a ON a.id=r.automation_id WHERE a.name LIKE 'batch-505-%'")=='500','K9-10 500 created pages cap before next whole rule')
    worker();check(sql("SELECT coalesce(sum(cardinality(r.created_page_ids)),0) FROM community.automation_runs r JOIN community.automations a ON a.id=r.automation_id WHERE a.name LIKE 'batch-505-%'")=='505','K9-10 remaining rule deferred without losing actions')
    sql('UPDATE community.automations SET is_enabled=false')
    sql("UPDATE community.automations SET is_enabled=true WHERE id IN (SELECT id FROM community.automations WHERE name LIKE 'batch-201-%' ORDER BY id LIMIT 20)")
    cap=make(rule({'page':shared},shared,kind='recurring'));call('POST','/api/automations/'+cap+'/dry-run',{});call('PATCH','/api/automations/'+cap,{'is_enabled':True},expected=400)
    check(True,'C:T-400 21st enabled rule rejected')
finally:
    (output/'backend-results.json').write_text(json.dumps(checks,ensure_ascii=False,indent=2))
    print(f'BACKEND_CHECKS={len(checks)} PASS={sum(c["pass"] for c in checks)} FAIL={sum(not c["pass"] for c in checks)}',flush=True)
