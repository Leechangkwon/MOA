#!/usr/bin/env python3
import os,json,uuid,requests,subprocess,time,copy
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path
manifest=json.loads(Path(os.environ['FILE_RENAME_MANIFEST']).read_text());assert manifest['database'].startswith('tbox_stage10_test_')
root=Path(manifest['instance']);out=Path(os.environ['FILE_RENAME_OUTPUT']);users=json.loads(Path(manifest['credentials_file']).read_text())['users'];base=manifest['base_url'].removesuffix('/tbox')+'/community';checks=[]
def check(ok,name):
 checks.append({'pass':bool(ok),'name':name});print(('PASS ' if ok else 'FAIL ')+name,flush=True)
 if not ok:raise AssertionError(name)
def call(method,path,body=None,user='employee',expected=200):
 r=requests.request(method,base+path,json=body,headers={'User-Agent':'tbox-review/'+time.strftime('%Y-%m-%d'),'Cookie':'__Host-tbox_session='+users[user]['session'],'X-CSRF-Token':users[user]['csrf']},timeout=30)
 if r.status_code!=expected:print('SAFE_ERROR='+r.text[:500],flush=True)
 check(r.status_code==expected,f'{method} {path} {r.status_code}/{expected}');return r.json()
def sql(text):
 env={k:v for k,v in os.environ.items() if not k.startswith('PG')};env['PGPASSFILE']=str(Path.home()/'.config/qaredi/pgpass-testdb')
 p=subprocess.run(['psql','-h','127.0.0.1','-U','tbox_testdb','-w','-X','-At','-d',manifest['database'],'-v','ON_ERROR_STOP=1'],env=env,input=text.encode(),capture_output=True,check=True);return p.stdout.decode().strip()
def block(text):return {'id':str(uuid.uuid4()),'type':'paragraph','text':[{'t':text,'m':{}}]}
def page(title,**extra):return call('POST','/api/pages',dict(title=title,**extra),expected=201)['page']['id']
def shared(title='suggestion test',blocks=None):
 blocks=blocks or [block('original'),block('untouched')]
 pid=page(title,doc={'schema':1,'blocks':blocks})
 call('PUT','/api/pages/'+pid+'/shares/claude_employee02',{'level':'view'})
 return pid,blocks

def propose(pid,b,new='proposed',user='other',expected=201,version=1,**extra):
 got=call('POST','/api/pages/'+pid+'/suggestions',dict(base_version=version,ops=[{'op':'replace','block_id':b['id'],'block':dict(b,text=[{'t':new,'m':{}}])}],**extra),user,expected)
 return got.get('id',got)

def getpage(pid,user='employee'):return call('GET','/api/pages/'+pid,user=user)['page']
def accept(sid,version=1,user='employee',expected=200):return call('POST','/api/suggestions/'+sid+'/accept',{'doc_version':version},user,expected)
def update(pid,blocks,version=1):return call('PATCH','/api/pages/'+pid,{'doc_version':version,'doc':{'schema':1,'blocks':blocks}})
def status(sid):return sql("SELECT status FROM community.page_suggestions WHERE public_id='"+sid+"'")
def raw(method,path,body,user='employee',port=None):
 url=base if port is None else base.replace(':8658',':'+str(port))
 return requests.request(method,url+path,json=body,headers={'User-Agent':'tbox-review/'+time.strftime('%Y-%m-%d'),'Cookie':'__Host-tbox_session='+users[user]['session'],'X-CSRF-Token':users[user]['csrf']},timeout=30)
try:
 for user in users:call('GET','/api/me/space',user=user)
 pid,bs=shared();sid=propose(pid,bs[0])
 check(call('GET','/api/pages/'+pid)['suggestions_open']==1,'C:T-503 editor sees count')
 author=call('GET','/api/pages/'+pid,user='other');check('suggestions_open' not in author and author['editors']==[],'C:T-503 author no total/editor leak')
 call('PUT','/api/pages/'+pid+'/shares/claude_manager01',{'level':'view'})
 got=call('GET','/api/pages/'+pid+'/suggestions',user='manager');check(got['suggestions']==[],'C:T-503 unrelated reader empty list')
 check('suggestions_open' not in call('GET','/api/pages/'+pid,user='manager'),'C:T-503 unrelated reader no count key')
 hidden=call('GET','/api/suggestions/'+sid,user='manager',expected=404)
 missing=call('GET','/api/suggestions/'+str(uuid.uuid4()),user='manager',expected=404);check(hidden==missing,'C:T-503 identical hidden/missing response')
 check(len(call('GET','/api/pages/'+pid+'/suggestions',user='other')['suggestions'])==1,'C:T-503 author sees only own proposal')
 check(len(call('GET','/api/pages/'+pid+'/suggestions')['suggestions'])==1,'C:T-503 editor sees all proposals')
 for user in ['employee']:check(propose(pid,bs[0],user=user,expected=400)['reason']=='can_edit','C:T-500 owner cannot propose')
 call('PUT','/api/pages/'+pid+'/shares/claude_manager01',{'level':'edit'})
 check(propose(pid,bs[0],user='manager',expected=400)['reason']=='can_edit','C:T-500 edit share cannot propose')
 call('PUT','/api/pages/'+pid+'/shares/claude_manager01',{'level':'view'})
 propose(pid,bs[0],user='admin',expected=403)
 internal=page('internal readable',doc={'schema':1,'blocks':[bs[0]]});call('PATCH','/api/pages/'+internal+'/visibility',{'visibility':'internal'});propose(internal,bs[0])
 sql("UPDATE public.sessions SET reauth_at=now() WHERE user_id=(SELECT id FROM users WHERE username='claude_admin01')")
 publicboard=call('POST','/api/boards',{'name':'Public suggestions','access_level':'public','board_kind':'discussion'},user='admin',expected=201)['board']['id']
 post=call('POST',f'/api/boards/{publicboard}/pages',{'title':'Public suggestion page'},expected=201)['page']['id']
 update(post,[bs[0]])
 propose(post,bs[0],version=2,user='guest',expected=403)
 # archived board + hidden moderation are synthetic metadata state, no operational board changed.
 sql("UPDATE community.spaces SET is_active=false WHERE id="+str(publicboard))
 check(propose(post,bs[0],version=2,expected=400)['reason']=='board_inactive','C:T-500 archived board rejects proposal')
 sql("UPDATE community.spaces SET is_active=true,access_level='team',team_id=(SELECT team_id FROM users WHERE username='claude_manager01') WHERE id="+str(publicboard))
 call('POST','/api/pages/'+post+'/hide',{},user='admin')
 propose(post,bs[0],version=2,user='manager',expected=403)
 # Structural validation includes blocked atom types, nested blocks and malicious links.
 op={'op':'replace','block_id':bs[0]['id'],'block':dict(bs[0],text=[{'t':'new','m':{}}])}
 invalid=[dict(op,op='execute'),dict(op,block_id=str(uuid.uuid4())),dict(op,block=dict(bs[0],id=str(uuid.uuid4())))]
 for bad in invalid:call('POST','/api/pages/'+pid+'/suggestions',{'base_version':1,'ops':[bad]},user='other',expected=400)
 call('POST','/api/pages/'+pid+'/suggestions',{'base_version':1,'ops':[op,op]},user='other',expected=400)
 for typ in ['image','file','embed','child_page','link_to_page','button']:
  bad={'id':str(uuid.uuid4()),'type':typ}
  got=call('POST','/api/pages/'+pid+'/suggestions',{'base_version':1,'ops':[{'op':'insert_after','block_id':None,'block':bad}]},user='other',expected=400)
  check(got['reason']=='suggestion_block_not_allowed','C:T-501 prohibited '+typ)
 todo=dict(block('todo'),type='to_do',checked=False);tp,tbs=shared('todo validation',[todo])
 changed=dict(todo,checked=True)
 call('POST','/api/pages/'+tp+'/suggestions',{'base_version':1,'ops':[{'op':'replace','block_id':todo['id'],'block':changed}]},user='other',expected=400)
 call('POST','/api/pages/'+tp+'/suggestions',{'base_version':1,'ops':[{'op':'insert_after','block_id':None,'block':changed}]},user='other',expected=400)
 call('POST','/api/pages/'+pid+'/suggestions',{'base_version':1,'ops':[op]*51},user='other',expected=400)
 propose(pid,bs[0],note='가'*501,expected=400);propose(pid,bs[0],version=2,expected=409)
 for modified in [dict(bs[0],unexpected='no'),dict(bs[0],text=[{'t':'bad','m':{'link':{'href':'javascript:alert(1)'}}}])]:
  call('POST','/api/pages/'+pid+'/suggestions',{'base_version':1,'ops':[dict(op,block=modified)]},user='other',expected=400)
 nested=block('deep')
 for _ in range(12):nested={'id':str(uuid.uuid4()),'type':'toggle','text':[{'t':'nested','m':{}}],'children':[nested]}
 call('POST','/api/pages/'+pid+'/suggestions',{'base_version':1,'ops':[{'op':'insert_after','block_id':None,'block':nested}]},user='other',expected=400)
 safe='<script>alert(1)</script><img onerror=alert(2)>'
 xp,xbs=shared('literal injection');xs=propose(xp,xbs[0],new=safe)
 comparison=call('GET','/api/suggestions/'+xs,user='other');check(comparison['comparison'][0]['after']['text'][0]['t']==safe,'C:T-502 comparison preserves literal text')
 accept(xs);check(getpage(xp)['doc']['blocks'][0]['text'][0]['t']==safe,'C:T-502 accept preserves literal text')
 # Accept authorization + optimistic conflict + immutable attribution and forced snapshots.
 accept(sid,user='other',expected=403);accept(sid,user='manager',expected=403);accept(sid,version=7,expected=409)
 result=accept(sid);check(result['doc_version']==2,'C:T-504 accepted document advances')
 check(getpage(pid)['doc']['blocks'][0]['text'][0]['t']=='proposed','C:T-504 applied actual body')
 versions=call('GET','/api/pages/'+pid+'/versions')['versions'];check(versions[0]['suggestion']['author']['username']=='claude_employee02','C:T-504 attribution')
 revised=getpage(pid)['doc']['blocks'];revised[1]['text'][0]['t']='after acceptance';update(pid,revised,2)
 vs=call('GET','/api/pages/'+pid+'/versions')['versions'];check(any(v['version']==2 and v.get('suggestion') for v in vs),'C:T-505 accepted version survives five-minute squash')
 check(call('GET','/api/pages/'+pid+'/history')['suggestions'][0]['version']==2,'C:T-504 history applied version attribution')
 check(accept(sid,version=3,expected=400)['reason']=='already_decided','C:T-507 already accepted')
 # Touched, untouched and missing block freshness.
 for mode in ['touched','untouched','removed']:
  p,b=shared('freshness '+mode);s=propose(p,b[0]);edit=copy.deepcopy(b)
  if mode=='removed':edit=edit[1:]
  else:edit[0 if mode=='touched' else 1]['text'][0]['t']='concurrent change'
  update(p,edit)
  got=call('GET','/api/suggestions/'+s)
  check(got['stale']==(mode!='untouched'),'C:T-506 compare freshness '+mode)
  if mode=='untouched':
   accept(s,2);check(getpage(p)['doc']['blocks'][1]['text'][0]['t']=='concurrent change','C:T-506 untouched edits preserved')
  else:
   check(accept(s,2,expected=409)['reason']=='suggestion_stale','C:T-506 stale result '+mode)
   check(status(s)=='stale' and getpage(p)['doc_version']==2,'C:T-506 stale status and unchanged version '+mode)
   check(sql("SELECT count(*) FROM community.notifications WHERE kind='suggestion_stale' AND page_id=(SELECT id FROM community.pages WHERE public_id='"+p+"')")=='1','C:T-506 stale recipient notification')
 # Reject, withdraw, revoked access and read-gated result notifications.
 p,b=shared('reject withdraw');s=propose(p,b[0]);call('POST','/api/suggestions/'+s+'/reject',{'note':'근거를 다시 확인해 주세요'})
 got=call('GET','/api/suggestions/'+s,user='other')['suggestion'];check(got['decision_note']=='근거를 다시 확인해 주세요' and getpage(p)['doc_version']==1,'C:T-507 rejection reason without page mutation')
 w=propose(p,b[0]);call('POST','/api/suggestions/'+w+'/withdraw',{},user='other');check(status(w)=='withdrawn','C:T-507 author withdraws')
 call('POST','/api/suggestions/'+w+'/withdraw',{},user='other',expected=400)
 p,b=shared('secret after revoke');s=propose(p,b[0],note='private proposal note')
 call('DELETE','/api/pages/'+p+'/shares/claude_employee02')
 own=call('GET','/api/me/suggestions',user='other')['suggestions'];gone=next(x for x in own if x['id']==s)
 check(gone['page_title']=='볼 수 없는 페이지' and gone['page'] is None and 'base_blocks' not in gone and not gone.get('note'),'C:T-508 own list no inaccessible title/body/note')
 call('GET','/api/suggestions/'+s,user='other',expected=404);accept(s)
 check(sql("SELECT count(*) FROM community.notifications WHERE kind='suggestion_accepted' AND page_id=(SELECT id FROM community.pages WHERE public_id='"+p+"')")=='0','C:T-508 no result notification after read revocation')
 # Original attachment survives (no new-file permission check); handover carries next proposal.
 fixtures=json.loads(Path(manifest['credentials_file']).read_text());fileblock={'id':str(uuid.uuid4()),'type':'file','file_ref':'tbox:'+fixtures['files']['main']['id']}
 p,b=shared('retained attachment',[block('body')]);b.append(fileblock);update(p,b);s=propose(p,b[0],version=2);accept(s,2)
 check(getpage(p)['doc']['blocks'][1]==fileblock,'C:T-512 accepted text preserves existing attachment')
 # Proposer receives ownership; a different view-shared reader proposes next.
 call('POST','/api/pages/'+p+'/handover',{'to':'claude_employee02','keep':'view'})
 pg=getpage(p,'other');s2=propose(p,pg['doc']['blocks'][0],user='employee',version=3)
 accept(s2,3,user='other');check(getpage(p,'other')['doc_version']==4,'C:T-512 new owner accepts carried page proposal')
 # Insertion fresh IDs and nested target operation acceptance.
 child=block('nested original');toggle={'id':str(uuid.uuid4()),'type':'toggle','text':[{'t':'toggle','m':{}}],'children':[child]}
 p,b=shared('nested anchors',[toggle,block('keep')]);s=propose(p,child,new='nested accepted');accept(s)
 check(getpage(p)['doc']['blocks'][0]['children'][0]['text'][0]['t']=='nested accepted','nested target accepted')
 ins=block('insert fresh');s=call('POST','/api/pages/'+p+'/suggestions',{'base_version':2,'ops':[{'op':'insert_after','block_id':None,'block':ins}]},user='other',expected=201)['id'];accept(s,2)
 check(getpage(p)['doc']['blocks'][0]['id']!=ins['id'],'insert server fresh IDs')
 # Page and author limits; bulk fixtures are only in this empty owned test database.
 p,b=shared('author page quota')
 for _ in range(5):propose(p,b[0])
 check(propose(p,b[0],expected=400)['reason']=='suggestion_limit','C:T-510 sixth own open proposal rejected')
 q,qb=shared('page quota');seed=propose(q,qb[0])
 sql("INSERT INTO community.page_suggestions(public_id,page_id,author_id,base_version,ops,base_blocks) SELECT md5(random()::text||g::text)::uuid,page_id,"+str(users['employee']['id'])+",base_version,ops,base_blocks FROM community.page_suggestions CROSS JOIN generate_series(1,49) g WHERE public_id='"+seed+"'")
 check(propose(q,qb[0],expected=400)['reason']=='suggestion_limit','C:T-510 page fifty-first open proposal rejected')
 # Two independent PHP workers accept one open proposal: exactly one version/save/decision.
 p,b=shared('accept race');s=propose(p,b[0]);path='/api/suggestions/'+s+'/accept'
 with ThreadPoolExecutor(2) as pool:answers=list(pool.map(lambda port:raw('POST',path,{'doc_version':1},port=port),[8658,8659]))
 check(sorted(x.status_code for x in answers)==[200,400] and getpage(p)['doc_version']==2,'concurrency duplicate accept one commit only')
 # Permanent deletion removes proposal data, retains immutable audit facts.
 p,b=shared('purge proposal');s=propose(p,b[0]);call('DELETE','/api/pages/'+p)
 call('GET','/api/suggestions/'+s,user='other',expected=404)
 sql("UPDATE community.pages SET deleted_at=now()-interval '31 days',purge_after=now()-interval '1 day' WHERE public_id='"+p+"';SELECT * FROM community.purge_trash(false);")
 check(sql("SELECT count(*) FROM community.page_suggestions WHERE public_id='"+s+"'")=='0','C:T-509 purge deletes proposal')
 check(sql("SELECT count(*) FROM audit_logs WHERE action='suggestion.create' AND detail->>'suggestion_id'='"+s+"'")=='1','C:T-509 purge retains audit')
 # Actual application-role privilege probes (database role switch only in synthetic DB).
 check(sql("SELECT has_table_privilege('tbox_app','community.page_suggestions','DELETE')")=='f','C:T-511 DELETE absent')
 check(sql("SELECT has_column_privilege('tbox_app','community.page_suggestions','ops','UPDATE')")=='f','C:T-511 ops immutable')
 check(sql("SELECT has_column_privilege('tbox_app','community.page_suggestions','status','UPDATE')")=='t','C:T-511 decision column permitted')
 # Probe the actual application connection: every attempt rolls back, even unexpected privilege success.
 probe=root/'stage10-privilege-probe.php'
 probe.write_text("""<?php
require __DIR__.'/src/Env.php'; Env::load(__DIR__.'/.env');require __DIR__.'/src/bootstrap.php';
if(!str_starts_with(Env::get('DB_NAME'),'tbox_stage10_test_'))exit(3);
$out=[];$db=Db::instance();
foreach(['DELETE FROM community.page_suggestions WHERE public_id=CAST(:id AS uuid)','UPDATE community.page_suggestions SET ops=ops WHERE public_id=CAST(:id AS uuid)','UPDATE community.page_suggestions SET status=status WHERE public_id=CAST(:id AS uuid)'] as $sql){
$db->begin();try{$db->run($sql,[':id'=>$argv[1]]);$out[]='ok';}catch(PDOException $e){$out[]=$e->getCode();}$db->rollback();}
echo json_encode($out);
""")
 probe_result=json.loads(subprocess.run(['php',str(probe),sid],cwd=root,capture_output=True,check=True).stdout)
 check(probe_result==['42501','42501','ok'],'C:T-511 actual app DELETE/ops UPDATE denied, status UPDATE allowed')
 # Two independent workers creating the fifth/sixth proposal cannot exceed the per-page author quota.
 rp,rb=shared('create quota race')
 for _ in range(4):propose(rp,rb[0])
 racebody={'base_version':1,'ops':[{'op':'replace','block_id':rb[0]['id'],'block':dict(rb[0],text=[{'t':'raced','m':{}}])}]}
 with ThreadPoolExecutor(2) as pool:answers=list(pool.map(lambda port:raw('POST','/api/pages/'+rp+'/suggestions',racebody,user='other',port=port),[8658,8659]))
 check(sorted(x.status_code for x in answers)==[201,400],'concurrency fifth/sixth proposal serialized')
 # Fill remaining global quota with valid five-per-page snapshots in synthetic fixtures only.
 count=int(sql("SELECT count(*) FROM community.page_suggestions WHERE status='open' AND author_id="+str(users['other']['id'])))
 while count<100:
  pp,bb=shared('global quota fixture',blocks=qb)
  n=min(5,100-count)
  sql("INSERT INTO community.page_suggestions(public_id,page_id,author_id,base_version,ops,base_blocks) SELECT md5(random()::text||g::text)::uuid,(SELECT id FROM community.pages WHERE public_id='"+pp+"'),author_id,base_version,ops,base_blocks FROM community.page_suggestions CROSS JOIN generate_series(1,"+str(n)+") g WHERE public_id='"+seed+"'")
  count+=n
 gp,gb=shared('global quota target')
 check(propose(gp,gb[0],expected=400)['reason']=='suggestion_limit','K10-11 author overall 101st proposal rejected on empty target page')
 first=call('GET','/api/me/suggestions',user='other');second=call('GET','/api/me/suggestions?after='+first['next_after'],user='other')
 check(len(first['suggestions'])==100 and len(second['suggestions'])>0 and not ({x['id'] for x in first['suggestions']} & {x['id'] for x in second['suggestions']}),'my suggestions keyset crosses 100 without duplicates')
 out.joinpath('accept-audit.json').write_text(sql("SELECT json_agg(a) FROM (SELECT action,result,detail FROM audit_logs WHERE detail->>'suggestion_id'='"+sid+"' ORDER BY id) a"))
except Exception:
 import traceback;traceback.print_exc();checks.append({'pass':False,'name':'uncaught test exception'})
finally:
 out.joinpath('stage10-results.json').write_text(json.dumps({'checks':checks},ensure_ascii=False,indent=2))
 n=sum(x['pass'] for x in checks);print(f'RESULT pass={n} fail={len(checks)-n} skip=0',flush=True)
 raise SystemExit(0 if n==len(checks) else 1)
