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
from urllib.parse import quote
try:
    for user in users:call('GET','/api/me/space',user=user)
    db=call('POST','/api/pages',{'title':'Limits DB','database':True},status=201).json()['page'];did=db['database']['id'];pid=db['id']
    s=call('PATCH','/api/databases/'+did+'/schema',{'add':{'name':'When','type':'date'}}).json()['database']['schema'];day=s[-1]['key']
    amount=call('PATCH','/api/databases/'+did+'/schema',{'add':{'name':'Cost','type':'number'}}).json()['database']['schema'][-1]['key']
    def view(**kw):return call('PATCH','/api/databases/'+did+'/views',{'add':kw}).json()['views'][-1]['id']
    cal=view(name='Calendar',type='calendar',date_prop=day)
    chart=view(name='Averages',type='chart',kind='line',group_by=day,bucket='day',aggregate='avg',on_prop=amount)
    frm=view(name='Internal',type='form',questions=[{'prop':'title','required':True}],audience='internal',open=True)
    # Synthetic SQL bulk setup only. No production records or persisted real files are used.
    sql("INSERT INTO community.pages(space_id,parent_page_id,title,doc,doc_version,plain_text,search_tsv,block_count,author_id,last_edited_by,visibility,position,database_id,properties) SELECT p.space_id,p.id,'SYNTHETIC_BULK_'||n,p.doc,1,'',strip(to_tsvector('simple','')),1,p.author_id,p.author_id,'private',n,d.id,jsonb_build_object('"+day+"',jsonb_build_object('start',to_char(date '2026-01-01'+((n-1)%60),'YYYY-MM-DD')),'"+amount+"',n) FROM community.pages p JOIN community.databases d ON d.page_id=p.id CROSS JOIN generate_series(1,501)n WHERE p.public_id='"+pid+"'")
    data=call('GET','/api/databases/'+did+'/rows?view='+cal+'&from=2026-01-01&to=2026-03-30').json();check(len(data['rows'])==500 and data['total']==501 and data['truncated'],'500 rows capped with truthful truncation')
    data=call('GET','/api/databases/'+did+'/chart?view='+chart).json();check(len(data['chart']['groups'])==51,'sixty groups become fifty plus remainder')
    remaining=next(g for g in data['chart']['groups'] if g['label']=='그 밖');expected=sql("SELECT avg((properties->>'"+amount+"')::numeric) FROM community.pages WHERE database_id=(SELECT id FROM community.databases WHERE public_id='"+did+"') AND properties->'"+day+"'->>'start'>'2026-02-19'");check(abs(remaining['value']-float(expected))<1e-8,'remainder average uses weighted samples')
    # Parent visibility never exposes private responses through shared read.
    call('PUT','/api/pages/'+pid+'/shares/claude_manager01',{'level':'view'})
    form='/api/forms/'+did+'/'+frm
    response=call('POST',form+'/submit',{'values':{'title':'PRIVATE_RESPONSE_MARKER'}},user='other',status=201).json()
    data=call('GET','/api/databases/'+did+'/rows',user='manager').text;check('PRIVATE_RESPONSE_MARKER' not in data,'DB view-sharing does not inherit private form response')
    data=call('GET','/api/search?q=PRIVATE_RESPONSE_MARKER',user='manager').json();check(data['results']==[],'search excludes private form response')
    count=view(name='Visible count',type='chart',kind='number',aggregate='count')
    for i in range(2):call('POST',form+'/submit',{'values':{'title':'OWNER_PRIVATE_'+str(i)}},status=201)
    own=call('GET','/api/databases/'+did+'/chart?view='+count).json()['chart']['groups'][0]['value']
    shared=call('GET','/api/databases/'+did+'/chart?view='+count,user='manager').json()['chart']['groups'][0]['value']
    check(own-shared==3,'SQL aggregate excludes exactly three private responses for read-shared user')
    relation=call('PATCH','/api/databases/'+did+'/schema',{'add':{'name':'Dependency','type':'relation','target':did,'depends':True}}).json()['database']['schema'][-1]['key']
    visible=call('GET','/api/databases/'+did+'/rows',user='manager').json()['rows'][0]['id']
    hidden=sql("SELECT public_id FROM community.pages WHERE title='PRIVATE_RESPONSE_MARKER' AND database_id=(SELECT id FROM community.databases WHERE public_id='"+did+"')")
    call('PATCH','/api/pages/'+visible+'/values',{'values':{relation:[hidden]}})
    check(hidden not in call('GET','/api/databases/'+did+'/rows',user='manager').text,'relation payload omits unreadable dependency UUID')
    source=call('GET','/api/pages/'+visible,user='manager').json()
    check(hidden not in json.dumps(source),'page detail omits unreadable relation UUID')
    call('PATCH','/api/pages/'+visible+'/values',{'values':{amount:999}})
    stored=sql("SELECT properties->>'"+relation+"' FROM community.pages WHERE public_id='"+visible+"'")
    check(hidden in stored,'unrelated edit preserves stored unreadable relation')
    source=call('GET','/api/pages/'+visible,user='manager').json()
    check(hidden not in json.dumps(source),'page detail continues filtering relation after unrelated edit')
    call('PUT','/api/pages/'+hidden+'/shares/claude_manager01',{'level':'view'})
    source=call('GET','/api/pages/'+visible,user='manager').json()
    check(hidden in json.dumps(source),'page detail reveals relation after target is shared')

    for i in range(29):call('POST',form+'/submit',{'values':{'title':'Rate '+str(i)}},user='other',status=201)
    call('POST',form+'/submit',{'values':{'title':'31st'}},user='other',status=429)
    readers=view(name='Readers',type='form',questions=[{'prop':'title','required':True}],audience='readers',open=True)
    call('GET','/api/forms/'+did+'/'+readers,user='admin',status=404)
    call('GET','/api/forms/'+did+'/'+readers,user='other',status=404)
    call('GET','/api/forms/'+did+'/'+readers,user='manager')
    call('GET','/api/databases/'+did+'/rows?view='+cal+'&from='+quote("'; DROP TABLE users; --")+'&to=2026-12-31',status=400)
    # Read-only role cannot move existing dates even if it knows the row UUID.
    first=call('GET','/api/databases/'+did+'/rows',user='manager').json()['rows'][0]['id']
    call('PATCH','/api/pages/'+first+'/values',{'values':{day:{'start':'2026-10-01'}}},user='manager',status=403)
finally:
    result={'passed':sum(x['pass'] for x in checks),'failed':max(int(sys.exc_info()[0] is not None),sum(not x['pass'] for x in checks)),'completed':sys.exc_info()[0] is None,'checks':checks}
    (output/'limits-results.json').write_text(json.dumps(result,ensure_ascii=False,indent=2)+'\n');print(json.dumps({'passed':result['passed'],'failed':result['failed']}))
