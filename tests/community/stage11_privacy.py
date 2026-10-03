#!/usr/bin/env python3
"""Stage11 isolated schema/row-creation foundation acceptance."""
import json, os, subprocess, time, sys
from pathlib import Path
import requests
m=json.loads(Path(os.environ['FILE_RENAME_MANIFEST']).read_text())
assert m['database'].startswith('tbox_stage11_test_')
root=Path(m['instance']); output=Path(os.environ['FILE_RENAME_OUTPUT'])
users=json.loads(Path(m['credentials_file']).read_text())['users']; checks=[]
def check(ok,name):
    checks.append({'pass':bool(ok),'name':name});print(('PASS ' if ok else 'FAIL ')+name,flush=True)
    if not ok:raise AssertionError(name)
def call(method,path,body=None,user='employee',status=200):
    u=users[user]
    resp=requests.request(method,m['base_url'].removesuffix('/tbox')+'/community'+path,json=body,
        headers={'User-Agent':'tbox-review/'+time.strftime('%Y-%m-%d'),'Cookie':'__Host-tbox_session='+u['session'],'X-CSRF-Token':u['csrf']},timeout=20)
    with (output/'http-responses.jsonl').open('a') as log: log.write(json.dumps({'method':method,'path':path,'user':user,'status':resp.status_code,'body':resp.text},ensure_ascii=False)+'\n')
    if resp.status_code!=status: print(resp.text,flush=True)
    check(resp.status_code==status,method+' '+path+' status '+str(resp.status_code))
    return resp

def sql(query):
    env={k:v for k,v in os.environ.items() if not k.startswith('PG')};env['PGPASSFILE']=str(Path.home()/'.config/qaredi/pgpass-testdb')
    done=subprocess.run(['psql','-h','127.0.0.1','-U','tbox_testdb','-w','-X','-At','-d',m['database'],'-v','ON_ERROR_STOP=1'],env=env,input=query.encode(),check=True,capture_output=True)
    return done.stdout.decode().strip()
try:
    for user in users:call('GET','/api/me/space',user=user)
    sql("UPDATE users SET display_name='PRIVATE_SUBMITTER_DISPLAY' WHERE username='claude_employee02'")
    team=next(s['id'] for s in call('GET','/api/spaces').json()['spaces'] if s['kind']=='team')
    db=call('POST','/api/pages',{'title':'Team Forms','database':True,'space_id':team},status=201).json()['page'];did=db['database']['id'];pid=db['id']
    v=call('PATCH','/api/databases/'+did+'/views',{'add':{'name':'Anonymous','type':'form','questions':[{'prop':'title','required':True}],'audience':'internal','anonymous':True,'open':True}}).json()['views'][-1];endpoint='/api/forms/'+did+'/'+v['id']
    call('POST',endpoint+'/submit',{'values':{'title':'ANON_RESPONSE'}},user='other',status=201)
    rid=sql("SELECT public_id FROM community.pages WHERE form_anonymous ORDER BY id DESC LIMIT 1")
    check(sql("SELECT count(*) FROM community.page_shares sh JOIN community.pages p ON p.id=sh.page_id WHERE p.public_id='"+rid+"'")=='0','anonymous response has no automatic share')
    check(sql("SELECT count(*) FROM community.notifications n JOIN community.pages p ON p.id=n.page_id WHERE p.public_id='"+rid+"' AND kind='form_response'")=='1','new response notifies responsible once')
    # Inject a synthetic teammate notification to verify defensive read filtering, not delivery policy.
    sql("INSERT INTO community.notifications(user_id,kind,page_id,actor_id) SELECT u.id,'form_response',p.id,p.author_id FROM community.pages p CROSS JOIN users u WHERE p.public_id='"+rid+"' AND u.username='claude_manager01'")
    call('PATCH','/api/pages/'+rid+'/properties',{'kind':'procedure'})
    records=[]
    paths=['/api/procedures?q=ANON_RESPONSE','/api/databases/'+did+'/rows','/api/pages/'+rid,'/api/pages/'+rid+'/versions','/api/pages/'+rid+'/versions/1','/api/pages/'+rid+'/history','/api/notifications','/api/search?q=ANON_RESPONSE','/api/pages?space='+str(team),'/api/pages/'+rid+'/export?format=md']
    for path in paths:
        text=call('GET',path,user='manager').text
        hits=text.count('claude_employee02')+text.count('PRIVATE_SUBMITTER_DISPLAY');records.append({'endpoint':path,'identity_occurrences':hits});check(hits==0,'team anonymous raw identity absent '+path)
    for viewer in ['employee','admin']:
        response=call('GET','/api/pages/'+rid,user=viewer).text;check('claude_employee02' in response and 'PRIVATE_SUBMITTER_DISPLAY' in response,'responsible or Admin sees respondent '+viewer)
    check(sql("SELECT count(*) FROM audit_logs WHERE actor_name='claude_employee02' AND action='page.create' AND target_id=(SELECT id FROM community.pages WHERE public_id='"+rid+"')")=='1','audit retains actual respondent')
    call('PATCH','/api/databases/'+did+'/views',{'update':{'id':v['id'],'anonymous':False,'notify':False}})
    call('POST',endpoint+'/submit',{'values':{'title':'NO_NOTIFICATION'}},user='other',status=201)
    check(sql("SELECT count(*) FROM community.notifications n JOIN community.pages p ON p.id=n.page_id WHERE p.title='NO_NOTIFICATION'")=='0','notify off creates no response notification')
    check('claude_employee02' not in call('GET','/api/pages/'+rid+'/versions',user='manager').text,'fixed anonymous survives settings update')
    (output/'anonymous-raw-response-matrix.json').write_text(json.dumps(records,ensure_ascii=False,indent=2)+'\n')
finally:
    result={'passed':sum(x['pass'] for x in checks),'failed':max(int(sys.exc_info()[0] is not None),sum(not x['pass'] for x in checks)),'completed':sys.exc_info()[0] is None,'checks':checks}
    (output/'privacy-results.json').write_text(json.dumps(result,ensure_ascii=False,indent=2)+'\n');print(json.dumps({'passed':result['passed'],'failed':result['failed']}))
