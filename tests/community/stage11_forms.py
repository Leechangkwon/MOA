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
    for user in users: call('GET','/api/me/space',user=user)
    db=call('POST','/api/pages',{'title':'PRIVATE_DATABASE_SENTINEL','database':True},status=201).json()['page']
    did=db['database']['id']; pid=db['id']
    s=call('PATCH','/api/databases/'+did+'/schema',{'add':{'name':'Choice','type':'select','options':['?','???']}}).json()['database']['schema']; select=s[-1]['key']
    s=call('PATCH','/api/databases/'+did+'/schema',{'add':{'name':'Amount','type':'number'}}).json()['database']['schema']; amount=s[-1]['key']
    v={'name':'??','type':'form','questions':[{'prop':'title','required':True},{'prop':select,'required':True},{'prop':amount,'required':True,'show_if':{'prop':select,'equals':'?'}}],'intro':'?? ??????','audience':'internal','anonymous':False,'open':True}
    view=call('PATCH','/api/databases/'+did+'/views',{'add':v}).json()['views'][-1]; vid=view['id']; endpoint='/api/forms/'+did+'/'+vid
    definition=call('GET',endpoint,user='other').text
    check('PRIVATE_DATABASE_SENTINEL' not in definition,'internal form does not disclose database title')
    call('GET',endpoint,user='guest',status=404)
    call('POST',endpoint+'/submit',{'values':{'title':'??','junk':1}},user='other',status=400)
    call('POST',endpoint+'/submit',{'values':{'title':'??',select:'?'}},user='other',status=400)
    response=call('POST',endpoint+'/submit',{'values':{'title':'??',select:'???',amount:'discard-hidden-malformed'}},user='other',status=201).json(); rid=response['page_id']
    check(bool(rid),'nonanonymous submitter receives own response link')
    p=call('GET','/api/pages/'+rid,user='other').json();check(not p['can_edit'],'response author receives view-only access')
    vals=sql("SELECT properties::text FROM community.pages WHERE public_id='"+rid+"'");check(amount not in vals,'hidden submitted answer discarded')
    call('PATCH','/api/databases/'+did+'/views',{'update':{'id':vid,'anonymous':True}})
    anon=call('POST',endpoint+'/submit',{'values':{'title':'?? ??',select:'???'}},user='other',status=201).json()
    check(anon['page_id'] is None and anon['url'] is None,'anonymous response confirmation reveals no page id')
    aid=sql("SELECT public_id FROM community.pages WHERE form_anonymous ORDER BY id DESC LIMIT 1")
    call('GET','/api/pages/'+aid,user='other',status=403)
    # DB edit sharing can read rows but cannot manage the form or reveal submitter identity.
    call('PUT','/api/pages/'+pid+'/shares/claude_manager01',{'level':'edit'})
    call('PATCH','/api/databases/'+did+'/views',{'update':{'id':vid,'intro':'forbidden'}},user='manager',status=403)
    for path in ['/api/pages/'+aid,'/api/pages/'+aid+'/versions','/api/pages/'+aid+'/versions/1','/api/pages/'+aid+'/history']:
        text=call('GET',path,user='manager').text;check('claude_employee02' not in text,'anonymous identity absent '+path)
    call('PATCH','/api/databases/'+did+'/views',{'update':{'id':vid,'anonymous':False}})
    check('claude_employee02' not in call('GET','/api/pages/'+aid+'/versions',user='manager').text,'anonymity remains fixed after setting change')
    call('PATCH','/api/databases/'+did+'/views',{'update':{'id':vid,'open':False}})
    call('GET',endpoint,user='other',status=404);call('POST',endpoint+'/submit',{'values':{}},user='other',status=404)
finally:
    result={'passed':sum(x['pass'] for x in checks),'failed':max(int(sys.exc_info()[0] is not None),sum(not x['pass'] for x in checks)),'completed':sys.exc_info()[0] is None,'checks':checks}
    (output/'forms-results.json').write_text(json.dumps(result,ensure_ascii=False,indent=2)+'\n');print(json.dumps(result))
