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
import uuid
try:
    for user in users:call('GET','/api/me/space',user=user)
    db=call('POST','/api/pages',{'title':'Views fixture','database':True},status=201).json()['page']; did=db['database']['id']; pid=db['id']
    def prop(name,type,**kw):return call('PATCH','/api/databases/'+did+'/schema',{'add':{'name':name,'type':type,**kw}}).json()['database']['schema'][-1]['key']
    day=prop('When','date'); amount=prop('Cost','number'); category=prop('Category','select',options=['A','B']); depends=prop('Depends','relation',target=did,depends=True)
    def view(**kw):return call('PATCH','/api/databases/'+did+'/views',{'add':kw}).json()['views'][-1]['id']
    cal=view(name='Calendar',type='calendar',date_prop=day,mode='month')
    timeline=view(name='Timeline',type='timeline',date_prop=day,depends_prop=depends,scale='week')
    chart=view(name='Totals',type='chart',kind='bar',group_by=category,aggregate='sum',on_prop=amount)
    a=call('POST','/api/pages',{'title':'A','parent_id':pid},status=201).json()['page']['id']; b=call('POST','/api/pages',{'title':'B','parent_id':pid},status=201).json()['page']['id']
    call('PATCH','/api/pages/'+a+'/values',{'values':{day:{'start':'2026-09-29','end':'2026-10-03'},amount:20,category:'A'}})
    call('PATCH','/api/pages/'+b+'/values',{'values':{day:{'start':'2026-11-01'},amount:30,category:'B',depends:[a]}})
    data=call('GET','/api/databases/'+did+'/rows?view='+cal+'&from=2026-10-01&to=2026-10-31').json();check([r['id'] for r in data['rows']]==[a],'range overlap includes spanning row and excludes outside row')
    call('GET','/api/databases/'+did+'/rows?view='+cal+'&from=2026-02-30&to=2026-10-31',status=400)
    call('GET','/api/databases/'+did+'/rows?view='+cal+'&from=2026-01-01&to=2027-02-05',status=400)
    call('PATCH','/api/pages/'+a+'/values',{'values':{depends:[b]}},status=400)
    call('PATCH','/api/pages/'+a+'/values',{'values':{depends:[a]}},status=400)
    call('PATCH','/api/pages/'+a+'/values',{'values':{'due':'2026-10-12'}})
    data=call('GET','/api/databases/'+did+'/chart?view='+chart).json();check(data['chart']['groups']==[{'label':'A','value':20},{'label':'B','value':30}],'SQL group sum')
    data=call('GET','/api/databases/'+did+'/chart?view='+chart+'&from=2026-10-01&to=2026-10-31').json();check(data['filter_applied'] and data['chart']['groups']==[{'label':'A','value':20}],'dashboard period uses matching date property independently of grouping')
    call('GET','/api/databases/'+did+'/chart?view='+chart,user='other',status=403)
    call('PATCH','/api/databases/'+did+'/views',{'add':{'name':'attack','type':'chart','kind':'bar','group_by':"title); DROP TABLE users; --",'aggregate':'count'}},status=400)
    dashboard=call('POST','/api/pages',{'title':'Dashboard'},status=201).json()['page']['id']
    call('PATCH','/api/pages/'+dashboard+'/properties',{'kind':'dashboard'})
    blocks=[{'id':str(uuid.uuid4()),'type':'db_view','database_id':did,'view_id':chart} for _ in range(12)]
    call('PATCH','/api/pages/'+dashboard,{'doc_version':1,'title':'Dashboard','doc':{'schema':1,'blocks':blocks}})
    page=call('GET','/api/pages/'+dashboard).json();check(len(page['db_views'])==12,'twelve widgets accepted')
    blocks.append({'id':str(uuid.uuid4()),'type':'db_view','database_id':did,'view_id':chart})
    call('PATCH','/api/pages/'+dashboard,{'doc_version':page['page']['doc_version'],'title':'Dashboard','doc':{'schema':1,'blocks':blocks}},status=400)
    call('PUT','/api/pages/'+dashboard+'/shares/claude_employee02',{'level':'view'})
    hidden=call('GET','/api/pages/'+dashboard,user='other').json()['db_views'];check(all(v is None for v in hidden.values()),'shared dashboard discloses no source title or count')
finally:
    result={'passed':sum(x['pass'] for x in checks),'failed':max(int(sys.exc_info()[0] is not None),sum(not x['pass'] for x in checks)),'completed':sys.exc_info()[0] is None,'checks':checks}
    (output/'views-results.json').write_text(json.dumps(result,ensure_ascii=False,indent=2)+'\n');print(json.dumps({'passed':result['passed'],'failed':result['failed']}))
