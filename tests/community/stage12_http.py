#!/usr/bin/env python3
"""Stage 12 synthetic HTTP and DB acceptance. Never connect to an operational destination."""
import json,os,subprocess,time,uuid,re
from pathlib import Path
import requests
manifest=json.loads(Path(os.environ['FILE_RENAME_MANIFEST']).read_text());assert manifest['database'].startswith('tbox_stage12_test_')
root=Path(manifest['instance']);output=Path(os.environ['FILE_RENAME_OUTPUT']);users=json.loads(Path(manifest['credentials_file']).read_text())['users'];base=manifest['base_url'].removesuffix('/tbox')+'/community';checks=[]
def check(ok,name):
 checks.append({'pass':bool(ok),'name':name});print(('PASS ' if ok else 'FAIL ')+name,flush=True)
 if not ok:raise AssertionError(name)
def sql(s):
 env={k:v for k,v in os.environ.items() if not k.startswith('PG')};env['PGPASSFILE']=str(Path.home()/'.config/qaredi/pgpass-testdb')
 r=subprocess.run(['psql','-h','127.0.0.1','-U','tbox_testdb','-w','-X','-At','-d',manifest['database'],'-v','ON_ERROR_STOP=1'],env=env,input=s.encode(),stdout=subprocess.PIPE,stderr=subprocess.PIPE,check=True);return r.stdout.decode().strip()
def call(method,path,body=None,user='employee',expected=200):
 h={'User-Agent':'tbox-review/'+time.strftime('%Y-%m-%d')}
 if user:h.update(Cookie='__Host-tbox_session='+users[user]['session'],**{'X-CSRF-Token':users[user]['csrf']})
 r=requests.request(method,base+path,json=body,headers=h,timeout=30)
 label=re.sub(r'/pub/[A-Za-z0-9_-]{43}', '/pub/[REDACTED]',path)
 if r.status_code!=expected:print('SAFE_ERROR_BODY='+r.text[:500],flush=True)
 check(r.status_code==expected,method+' '+label+' '+str(expected));return r
def page(title,user='employee',**kwargs):return call('POST','/api/pages',dict(title=title,**kwargs),user,201).json()['page']['id']
def docpage(p,doc,user='employee',expected=200):
 v=call('GET','/api/pages/'+p,user=user).json()['page']['doc_version'];return call('PATCH','/api/pages/'+p,{'doc':doc,'doc_version':v},user,expected)
def block(t,**kw):return dict(id=str(uuid.uuid4()),type=t,**kw)
try:
 for u in users:call('GET','/api/me/space',user=u)
 sql('UPDATE sessions SET reauth_at=now()')
 p=page('STAGE12 private marker');other=page('STAGE12 reference')
 c=call('POST','/api/collections',{'name':'모음 1'}).json()['id']
 call('GET','/api/collections/'+str(c),user='other',expected=404)
 call('PUT',f'/api/collections/{c}/items/{p}',{})
 check(p in call('GET',f'/api/collections/{c}').text,'C:T-700 owner list')
 c2=call('POST','/api/collections',{'name':'depth2','parent_id':c}).json()['id'];c3=call('POST','/api/collections',{'name':'depth3','parent_id':c2}).json()['id']
 call('POST','/api/collections',{'name':'depth4','parent_id':c3},expected=400);call('PATCH',f'/api/collections/{c}',{'parent_id':c3},expected=400)
 g=call('POST','/api/groups',{'name':'admin private group','members':['claude_employee02','claude_guest01']},'admin').json()['id']
 call('GET','/api/groups/'+g,user='employee',expected=404)
 gm=call('POST','/api/groups',{'name':'manager group','members':['claude_employee01']},'manager').json()['id']
 call('PATCH','/api/groups/'+gm,{'members':['claude_employee02']},'manager',403)
 call('PATCH','/api/groups/'+g,{'members':['claude_employee01','claude_employee02','claude_guest01']},'admin')
 call('PUT',f'/api/pages/{p}/group-shares/{g}',{'level':'edit'})
 call('GET','/api/pages/'+p,user='other');call('PATCH','/api/pages/'+p,{'title':'guest bad','doc_version':1},'guest',403)
 oc=call('POST','/api/collections',{'name':'other collection'},'other').json()['id'];call('PUT',f'/api/collections/{oc}/items/{p}',{},'other')
 call('PATCH','/api/groups/'+g,{'members':['claude_employee01','claude_guest01']},'admin')
 call('GET','/api/pages/'+p,user='other',expected=403);check(p not in call('GET',f'/api/collections/{oc}',user='other').text,'C:T-701 collection permission loss has no UUID/title')
 call('PATCH','/api/groups/'+g,{'members':['claude_employee01','claude_employee02']},'admin');check(p in call('GET',f'/api/collections/{oc}',user='other').text,'C:T-702 regain membership restores collection item')
 check(sql("SELECT has_table_privilege('tbox_app','community.group_member_events','UPDATE') OR has_table_privilege('tbox_app','community.group_member_events','DELETE')")=='f','C:T-716 immutable member events')
 call('DELETE','/api/groups/'+g,user='admin');call('GET','/api/pages/'+p,user='other',expected=403)
 source=str(uuid.uuid4());secret='SOURCE-PRIVATE-SENTENCE-713'
 docpage(p,{'schema':1,'blocks':[block('synced_source',source_id=source,children=[block('paragraph',text=[{'t':secret,'m':{}}])])]})
 docpage(other,{'schema':1,'blocks':[block('synced_ref',source_id=source)]})
 check(secret in call('GET','/api/pages/'+other).text,'source content available to authorized reader')
 call('PUT',f'/api/pages/{other}/shares/claude_employee02',{'level':'view'})
 check(secret not in call('GET','/api/pages/'+other,user='other').text,'C:T-720 source contents absent from unauthorized raw response')
 check(sql("SELECT plain_text FROM community.pages WHERE public_id='"+other+"'")=='','C:T-722 reference search text excludes source')
 docpage(other,{'schema':1,'blocks':[block('synced_ref',source_id=source,children=[block('paragraph',text=[])])]},expected=400)
 docpage(other,{'schema':1,'blocks':[block('synced_source',source_id=str(uuid.uuid4()),children=[block('synced_ref',source_id=source)])]},expected=400)
 call('POST',f'/api/pages/{p}/web-publish',{'confirm_public':True},expected=403)
 space=int(sql("SELECT space_id FROM community.pages WHERE public_id='"+p+"'"));call('PATCH',f'/api/spaces/{space}/web-publish',{'allowed':True},'admin')
 call('POST',f'/api/pages/{p}/web-publish',{'confirm_public':True},'admin',403)
 call('POST',f'/api/pages/{p}/web-publish',{'allow_index':True,'confirm_public':True},expected=403)
 pub=call('POST',f'/api/pages/{p}/web-publish',{'confirm_public':True},expected=201).json();publicpath=pub['url'].removeprefix('/community')
 r=call('GET',publicpath);check('Set-Cookie' not in r.headers and 'csrf' not in r.text.lower() and users['employee']['session'] not in r.text,'C:T-733 public session/cookie isolation')
 check(secret in r.text and '<script' not in r.text and '<iframe' not in r.text and 'style=' not in r.text,'C:T-734/737 public script-free source rendering')
 check(r.headers.get('Cache-Control')=='no-store' and r.headers.get('Referrer-Policy')=='no-referrer' and r.headers.get('X-Robots-Tag')=='noindex, nofollow','C:T-736/737 headers')
 token=publicpath.split('/')[2];check(sql("SELECT count(*) FROM community.web_publications WHERE token_hash='"+token+"'")=='0','C:T-731 token is not persisted raw')
 call('GET',publicpath,user=None);check(sql("SELECT count(*) FROM audit_logs WHERE action='pub.view'")=='1','C:T-741 same IP once per hour')
 call('POST',publicpath,{},None,405)
 child=page('new unpublished child',parent_id=p);call('GET',publicpath+'/p/'+child,user=None,expected=404)
 missing=call('GET','/pub/'+'x'*43,user=None,expected=404)
 call('DELETE',f'/api/pages/{p}/web-publish/{pub["id"]}')
 revoked=call('GET',publicpath,user=None,expected=404);check(revoked.content==missing.content,'C:T-732/742 absent and revoked identical body')
 pub2=call('POST',f'/api/pages/{p}/web-publish',{'confirm_public':True},expected=201).json();path2=pub2['url'].removeprefix('/community')
 sql('UPDATE community.web_publications SET expires_at=now()-interval \'1 second\' WHERE id='+str(pub2['id']));expired=call('GET',path2,user=None,expected=404);check(expired.content==missing.content,'expired identical 404')
 call('GET','/api/admin/messenger-hooks',user='employee',expected=403)
 h=call('POST','/api/admin/messenger-hooks',{'name':'pending synthetic','url_host':'example.com'},'admin').json();check(not h['secret_configured'],'destination unconfigured is normal state')
 call('PUT','/api/me/messenger',{'hook_id':h['id'],'kinds':['comment_on_my_page']})
 call('POST','/api/admin/messenger-hooks',{'name':'bad','url_host':'127.0.0.1'},'admin',400)
 call('POST','/api/admin/messenger-hooks',{'name':'bad','url_host':'example.com','url':'https://example.com/secret'},'admin',400)
 call('GET','/integrations')
 # No external transport is invoked: verify default hooks and absence of secrets in API/DB.
 check('url_path' not in call('GET','/api/admin/messenger-hooks',user='admin').text,'C:T-754 messenger metadata stores host only')
 check(sql("SELECT count(*) FROM community.messenger_hooks WHERE is_active")=='0','registered hooks default to off')
 check(sql("SELECT count(*) FROM audit_logs WHERE action IN ('pub.view','pub.submit') AND actor_id IS NOT NULL")=='0','public audit has no authenticated actor')
 print('ACCEPTANCE_COUNT='+str(len(checks)),flush=True)
finally:
 output.mkdir(exist_ok=True,parents=True);(output/'acceptance.json').write_text(json.dumps(checks,ensure_ascii=False,indent=2));
