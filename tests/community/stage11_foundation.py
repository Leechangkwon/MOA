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
    page=call('POST','/api/pages',{'title':'합성11단계 일반페이지'},status=201).json()['page']['id']
    call('GET','/api/pages/'+page)
    db=call('POST','/api/pages',{'title':'합성11단계 DB','database':True},status=201).json()['page']
    row=call('POST','/api/pages',{'title':'일반행','parent_id':db['id']},status=201).json()['page']['id']
    listing=call('GET','/api/databases/'+db['database']['id']+'/rows').json()
    check(any(x['id']==row for x in listing['rows']),'ordinary row remains listed')
    check(sql("SELECT count(*) FROM information_schema.columns WHERE table_schema='community' AND table_name='pages' AND column_name IN ('form_view_id','form_anonymous')")=='2','form columns present')
    check('dashboard' in sql("SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conrelid='community.pages'::regclass AND conname='pages_page_kind_check'"),'dashboard constraint extended')
    kinds=sql("SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conrelid='community.notifications'::regclass AND conname='notifications_kind_check'")
    check('form_response' in kinds and 'automation_disabled' in kinds,'new and existing notifications preserved')
    check(sql("SELECT count(*) FROM community.page_blocks b JOIN community.pages p ON p.id=b.page_id WHERE p.public_id='"+row+"'")=='1','shared insertion rebuilds block index')
    check(sql("SELECT count(*) FROM audit_logs WHERE action='page.create' AND result='allow'")=='3','shared insertion records page create audit')
finally:
    result={'passed':sum(x['pass'] for x in checks),'failed':max(int(sys.exc_info()[0] is not None),sum(not x['pass'] for x in checks)),'completed':sys.exc_info()[0] is None,'checks':checks}
    (output/'foundation-results.json').write_text(json.dumps(result,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps(result))
