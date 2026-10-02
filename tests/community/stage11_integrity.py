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
from concurrent.futures import ThreadPoolExecutor
from urllib.parse import urlsplit
import uuid
try:
    for user in users:call('GET','/api/me/space',user=user)
    db=call('POST','/api/pages',{'title':'Integrity','database':True},status=201).json()['page'];did=db['database']['id'];pid=db['id']
    def prop(name,type,**kw):return call('PATCH','/api/databases/'+did+'/schema',{'add':{'name':name,'type':type,**kw}}).json()['database']['schema'][-1]['key']
    person=prop('Person','person');rel=prop('Dependency','relation',target=did);file=prop('File','file');choice=prop('Choice','select',options=['A','B']);number=prop('Number','number')
    base={'name':'Form','type':'form','questions':[{'prop':'title','required':True}],'open':True,'audience':'internal'}
    for key in [person,rel,file]:call('PATCH','/api/databases/'+did+'/views',{'add':dict(base,questions=[{'prop':key,'required':False}])},status=400)
    call('PATCH','/api/databases/'+did+'/views',{'add':dict(base,questions=[{'prop':choice,'required':True,'show_if':{'prop':choice,'equals':'A'}}])},status=400)
    call('PATCH','/api/databases/'+did+'/views',{'add':dict(base,questions=[{'prop':'title','required':True}]*31)},status=400)
    call('PATCH','/api/databases/'+did+'/views',{'add':dict(base,unknown=True)},status=400)
    base['questions']+=[{'prop':choice,'required':True},{'prop':number,'required':True}]
    v=call('PATCH','/api/databases/'+did+'/views',{'add':base}).json()['views'][-1];path='/api/forms/'+did+'/'+v['id']+'/submit'
    for vals in [{'title':' ',choice:'A',number:1},{'title':'title',choice:'C',number:1},{'title':'title',choice:'A',number:'1'}]:call('POST',path,{'values':vals},user='other',status=400)
    u=users['other'];no=requests.post(m['base_url'].removesuffix('/tbox')+'/community'+path,json={'values':{'title':'No CSRF',choice:'A',number:1}},headers={'User-Agent':'tbox-review/'+time.strftime('%Y-%m-%d'),'Cookie':'__Host-tbox_session='+u['session']},timeout=20);check(no.status_code==403,'form submission requires CSRF')
    # Seed 29 owned synthetic submissions without 29 repeated network checks.
    sql("INSERT INTO community.pages(space_id,parent_page_id,title,doc,doc_version,plain_text,search_tsv,block_count,author_id,last_edited_by,visibility,position,database_id,properties,form_view_id,form_anonymous) SELECT p.space_id,p.id,'RATE_SEED_'||n,p.doc,1,'',strip(to_tsvector('simple','')),1,u.id,u.id,'private',n,d.id,'{}'::jsonb,'"+v['id']+"',false FROM community.pages p JOIN community.databases d ON d.page_id=p.id CROSS JOIN users u CROSS JOIN generate_series(1,29)n WHERE p.public_id='"+pid+"' AND u.username='claude_employee02'")
    def submit(port):return requests.post('http://127.0.0.1:'+str(port)+'/community'+path,json={'values':{'title':'Parallel',choice:'A',number:1}},headers={'User-Agent':'tbox-review/'+time.strftime('%Y-%m-%d'),'Cookie':'__Host-tbox_session='+u['session'],'X-CSRF-Token':u['csrf']},timeout=20).status_code
    port=urlsplit(m['base_url']).port
    with ThreadPoolExecutor(2) as pool:result=list(pool.map(submit,[port,int(os.environ['STAGE89_SECOND_PORT'])]))
    check(sorted(result)==[201,429],'concurrent 30th and 31st submissions serialize at form limit')
    daily=call('PATCH','/api/databases/'+did+'/views',{'add':dict(base,name='Daily')}).json()['views'][-1]
    sql("INSERT INTO community.pages(space_id,parent_page_id,title,doc,doc_version,plain_text,search_tsv,block_count,author_id,last_edited_by,visibility,position,database_id,properties,form_view_id,form_anonymous) SELECT p.space_id,p.id,'DAILY_SEED_'||n,p.doc,1,'',strip(to_tsvector('simple','')),1,p.author_id,p.author_id,'private',n,d.id,'{}'::jsonb,'"+daily['id']+"',false FROM community.pages p JOIN community.databases d ON d.page_id=p.id CROSS JOIN generate_series(1,1000)n WHERE p.public_id='"+pid+"'")
    call('POST','/api/forms/'+did+'/'+daily['id']+'/submit',{'values':{'title':'Daily limit',choice:'A',number:1}},user='other',status=429)
    nodes=[call('POST','/api/pages',{'title':'Graph '+str(i),'parent_id':pid},status=201).json()['page']['id'] for i in range(3)]
    for i in range(3):call('PATCH','/api/pages/'+nodes[i]+'/values',{'values':{rel:[nodes[(i+1)%3]]}})
    call('PATCH','/api/databases/'+did+'/schema',{'depends':{'key':rel,'depends':True}},status=400)
    call('PATCH','/api/pages/'+nodes[2]+'/values',{'values':{rel:None}})
    call('PATCH','/api/databases/'+did+'/schema',{'depends':{'key':rel,'depends':True}})
    call('PATCH','/api/pages/'+nodes[2]+'/values',{'values':{rel:[nodes[0]]}},status=400)
    for node in nodes:call('PATCH','/api/pages/'+node+'/values',{'values':{rel:None}})
    def edge(args):i,port=args;u=users['employee'];return requests.patch('http://127.0.0.1:'+str(port)+'/community/api/pages/'+nodes[i]+'/values',json={'values':{rel:[nodes[1-i]]}},headers={'User-Agent':'tbox-review/'+time.strftime('%Y-%m-%d'),'Cookie':'__Host-tbox_session='+u['session'],'X-CSRF-Token':u['csrf']},timeout=20).status_code
    with ThreadPoolExecutor(2) as pool:result=list(pool.map(edge,[(0,port),(1,int(os.environ['STAGE89_SECOND_PORT']))]))
    check(sorted(result)==[200,400],'concurrent opposite dependency edges cannot create a cycle')
    depth=prop('Depth guard','relation',target=did)
    chain=[str(uuid.uuid4()) for _ in range(51)]
    for i,node in enumerate(chain):
        properties=json.dumps({depth:[chain[i+1]] if i+1<len(chain) else []})
        sql("INSERT INTO community.pages(public_id,space_id,parent_page_id,title,doc,doc_version,plain_text,search_tsv,block_count,author_id,last_edited_by,visibility,position,database_id,properties) SELECT '"+node+"',p.space_id,p.id,'DEPTH_SEED',p.doc,1,'',strip(to_tsvector('simple','')),1,p.author_id,p.author_id,'private',0,d.id,'"+properties+"'::jsonb FROM community.pages p JOIN community.databases d ON d.page_id=p.id WHERE p.public_id='"+pid+"'")
    call('PATCH','/api/databases/'+did+'/schema',{'depends':{'key':depth,'depends':True}},status=400)
    check(sql("SELECT count(*) FROM community.pages WHERE public_id IN ('"+"','".join(chain)+"')")=='51','fifty dependency edges reach bounded rejection without writes')
    # Bound all chart identifiers; only numbers leave the endpoint.
    for values in [{'group_by':'missing','kind':'bar','aggregate':'count'},{'kind':'unknown','aggregate':'count'},{'kind':'bar','aggregate':'sum','on_prop':choice}]:call('PATCH','/api/databases/'+did+'/views',{'add':{'name':'invalid','type':'chart',**values}},status=400)
finally:
    result={'passed':sum(x['pass'] for x in checks),'failed':max(int(sys.exc_info()[0] is not None),sum(not x['pass'] for x in checks)),'completed':sys.exc_info()[0] is None,'checks':checks}
    (output/'integrity-results.json').write_text(json.dumps(result,ensure_ascii=False,indent=2)+'\n');print(json.dumps({'passed':result['passed'],'failed':result['failed']}))
