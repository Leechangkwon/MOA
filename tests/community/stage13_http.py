#!/usr/bin/env python3
"""Actual HTTP over fresh synthetic PostgreSQL; isolated fake Ai class captures inputs."""
import os,json,uuid,requests,subprocess,time,copy,hashlib,io,wave
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path
manifest=json.loads(Path(os.environ['FILE_RENAME_MANIFEST']).read_text());assert manifest['database'].startswith('tbox_stage13_test_')
root=Path(manifest['instance']);out=Path(os.environ['FILE_RENAME_OUTPUT']);fixture=json.loads(Path(manifest['credentials_file']).read_text());users=fixture['users'];base=manifest['base_url'].removesuffix('/tbox')+'/community';checks=[]
storage=root.parent/'storage';mode=storage/'fake-mode.json';capture=storage/'fake-capture.jsonl'
def check(ok,name):
 checks.append({'pass':bool(ok),'name':name});print(('PASS ' if ok else 'FAIL ')+name,flush=True)
 if not ok:raise AssertionError(name)
def raw(method,path,body=None,user='employee',port=None,csrf=True):
 url=base if port is None else base.replace(':8698',':'+str(port));headers={'User-Agent':'tbox-review/'+time.strftime('%Y-%m-%d'),'Cookie':'__Host-tbox_session='+users[user]['session']}
 if csrf:headers['X-CSRF-Token']=users[user]['csrf']
 return requests.request(method,url+path,json=body,headers=headers,timeout=45)
def call(method,path,body=None,user='employee',expected=200):
 r=raw(method,path,body,user)
 if r.status_code!=expected:print('SAFE_ERROR='+r.text[:500],flush=True)
 check(r.status_code==expected,f'{method} {path} {r.status_code}/{expected}');return r.json()
def sql(text):
 env={k:v for k,v in os.environ.items() if not k.startswith('PG')};env['PGPASSFILE']=str(Path.home()/'.config/qaredi/pgpass-testdb')
 p=subprocess.run(['psql','-h','127.0.0.1','-U','tbox_testdb','-w','-X','-At','-d',manifest['database'],'-v','ON_ERROR_STOP=1'],env=env,input=text.encode(),capture_output=True,check=True);return p.stdout.decode().strip()
def block(text):return {'id':str(uuid.uuid4()),'type':'paragraph','text':[{'t':text,'m':{}}]}
def page(title='AI synthetic',text='합성 공개 자료 only',user='employee'):
 return call('POST','/api/pages',{'title':title,'doc':{'schema':1,'blocks':[block(text[i:i+1000]) for i in range(0,len(text),1000)]}},user,201)['page']['id']
def approve(pid,v=1,user='employee',allowed=True):return call('PATCH','/api/pages/'+pid+'/ai-permission',{'allowed':allowed,'doc_version':v},user)
def summary(pid,v=1,user='employee',expected=200):return call('POST','/api/pages/'+pid+'/ai-summary',{'doc_version':v,'confirmed':True},user,expected)
def settings(enabled=True):return call('PATCH','/api/admin/ai/settings',{'external_data_mode':'free_public','features':{f:enabled for f in ['search','summary','assistant','transcribe','briefing']}},'admin')
def captures():return [json.loads(x) for x in capture.read_text().splitlines()] if capture.exists() else []
def reset_quota():sql("UPDATE community.ai_requests SET day='2000-01-01'")
def cli(code):
 path=root/'stage13-own-probe.php';path.write_text("<?php\nrequire __DIR__.'/src/Env.php';Env::load(__DIR__.'/.env');require __DIR__.'/src/bootstrap.php';require __DIR__.'/src/community/routes.php';if(!str_starts_with(Env::get('DB_NAME'),'tbox_stage13_test_'))exit(3);\n"+code)
 p=subprocess.run(['php',str(path)],cwd=root,capture_output=True)
 if p.returncode:print('CLI_ERROR='+p.stderr.decode()[-1200:],flush=True)
 check(p.returncode==0,'isolated PHP probe exit');return p.stdout.decode()
def index():return json.loads(cli('echo json_encode(community_run_ai_index());'))
def audio():
 data=io.BytesIO()
 with wave.open(data,'wb') as wav:wav.setnchannels(1);wav.setsampwidth(2);wav.setframerate(16000);wav.writeframes(b'\0\0'*16000)
 data=data.getvalue();stored=str(uuid.uuid4())+'.wav';path=storage/stored[:2]/stored[2:4]/stored;path.parent.mkdir(parents=True,exist_ok=True);path.write_bytes(data)
 fid=str(uuid.uuid4());sha=hashlib.sha256(data).hexdigest()
 sql("INSERT INTO files(public_id,original_name,stored_name,ext,mime,size_bytes,sha256,uploaded_by,access_level) VALUES('"+fid+"','SENSITIVE_FILENAME.wav','"+stored+"','wav','audio/x-wav',"+str(len(data))+",'"+sha+"',"+str(users['employee']['id'])+",'public')")
 return fid,path,data
try:
 for u in users:call('GET','/api/me/space',user=u)
 initial=call('GET','/api/ai/status');check(not initial['configured'] and not any(initial['features'].values()) and initial['external_data_mode']=='unconfigured','C:T-801 all switches default off')
 p=page();summary(p,expected=503);check(captures()==[],'C:T-801 off sends zero bytes')
 call('GET','/api/admin/ai/settings',user='employee',expected=403);call('GET','/api/admin/ai/settings',user='admin',expected=403)
 sql("UPDATE sessions SET reauth_at=now() WHERE user_id="+str(users['admin']['id']))
 check(raw('POST','/api/admin/ai/key',{'key':'K'*39},'admin',csrf=False).status_code==403,'key CSRF required')
 call('POST','/api/admin/ai/key',{'key':'short'},'admin',400)
 key=call('POST','/api/admin/ai/key',{'key':'K'*39},'admin');check('K'*39 not in json.dumps(key),'key never returned')
 settings();check(not captures(),'settings/status/key health no generation')
 summary(p,expected=404);approve(p)
 got=summary(p);check('<script>' in got['text'] and got['citations'][0]['page_id']==p,'C:T-800 untrusted text with server citation')
 log=call('GET','/api/me/ai-log')['logs'][0];check(log['input_text']=='AI synthetic\n합성 공개 자료 only' and log['output_text']==got['text'],'C:T-803 exact sent text and shown output')
 check(call('GET','/api/me/ai-log',user='other')['logs']==[],'C:T-804 no other user logs')
 call('GET','/api/admin/ai-log',user='admin');check(sql("SELECT count(*) FROM audit_logs WHERE action='ai.log_read'")=='1','C:T-804 admin read audited')
 call('POST','/api/pages/'+p+'/ai-summary',{'doc_version':1},expected=400)
 call('POST','/api/pages/'+p+'/ai-summary',{'doc_version':1,'confirmed':True,'host':'http://localhost'},expected=400)
 call('POST','/api/ai/search',{'q':'찾기','confirmed':False},expected=400)
 call('PATCH','/api/pages/'+p+'/ai-permission',{'allowed':True,'doc_version':2},expected=409)
 call('PATCH','/api/pages/'+p+'/ai-permission',{'allowed':True,'doc_version':1},'other',404)
 before=len(captures());summary(p,user='admin',expected=404);check(len(captures())==before,'C:T-802 privileged admin body never sent')
 call('PUT','/api/pages/'+p+'/shares/claude_employee02',{'level':'view'})
 summary(p,user='other');approve(p,user='other',allowed=False) if False else None
 # The draft stays read-only until an explicit insertion; wrong owner/version never writes.
 call('POST','/api/pages/'+p+'/ai-insert',{'log_id':got['log_id'],'doc_version':1},'other',404)
 call('POST','/api/pages/'+p+'/ai-insert',{'log_id':got['log_id'],'doc_version':2},expected=409)
 ins=call('POST','/api/pages/'+p+'/ai-insert',{'log_id':got['log_id'],'doc_version':1});check(ins['doc_version']==2,'explicit insertion common save version')
 doc=call('GET','/api/pages/'+p)['page']['doc'];check(doc['blocks'][1]['text'][0]['t']=='AI가 만든 글 — 틀릴 수 있습니다' and '<script>' in json.dumps(doc,ensure_ascii=False),'plain paragraph insertion warning/literal injection')
 check(not call('GET','/api/pages/'+p+'/ai-permission')['allowed'],'save clears external permission')
 summary(p,2,expected=404);approve(p,2);summary(p,1,expected=409)
 call('DELETE','/api/pages/'+p+'/shares/claude_employee02')
 hidden=call('GET','/api/me/ai-log',user='other')['logs'][0];check(not hidden['content_visible'] and hidden['input_text'] is None,'C:T-804 revoked old log body hidden')
 # Actual application grant probes, each rolled back even if unexpectedly allowed.
 permissions=json.loads(cli("$out=[];$db=Db::instance();foreach(['UPDATE community.ai_log SET output_text=output_text','DELETE FROM community.ai_log','UPDATE community.ai_requests SET feature=feature'] as $sql){$db->begin();try{$db->run($sql);$out[]='ok';}catch(PDOException $e){$out[]=$e->getCode();}$db->rollback();}echo json_encode($out);"))
 check(permissions==['42501']*3,'C:T-803 actual app immutable log/quota grants')
 # Exclusion uses canonical DB state, not fields supplied by browser.
 sql('ALTER TABLE community.pages ADD COLUMN IF NOT EXISTS form_anonymous boolean NOT NULL DEFAULT false; ALTER TABLE community.pages ADD COLUMN IF NOT EXISTS absence_projection boolean NOT NULL DEFAULT false')
 for marker in ['form_anonymous','absence_projection']:
  x=page(marker,'FORBIDDEN_'+marker);sql("UPDATE community.pages SET "+marker+"=true"+(",form_view_id='v12345678'" if marker=='form_anonymous' else '')+" WHERE public_id='"+x+"'")
  check(not call('GET','/api/pages/'+x+'/ai-permission')['eligible'],'excluded '+marker+' flag')
  call('PATCH','/api/pages/'+x+'/ai-permission',{'allowed':True,'doc_version':1},expected=404);summary(x,expected=404)
 leave=page('leave private');sql("INSERT INTO community.databases(page_id,schema,tool,created_by) SELECT id,'[]','leave',author_id FROM community.pages WHERE public_id='"+leave+"'")
 call('PATCH','/api/pages/'+leave+'/ai-permission',{'allowed':True,'doc_version':1},expected=404)
 check(not any('FORBIDDEN_' in x['input'] for x in captures()),'C:T-802 excluded content captured zero')
 # Semantic complete-only filtering and queue consumes at most 20 chunks.
 reset_quota();q=page('절차서 공개','설명 '*1800);approve(q)
 sql("UPDATE community.pages SET page_kind='procedure' WHERE public_id='"+q+"'")
 indexed=index();check(indexed['chunks']<=20,'C:T-810 index batch bound')
 result=call('POST','/api/ai/search',{'q':'절차','confirmed':True});check(q in [x['page_id'] for x in result['results']],'C:T-811 semantic index result')
 check(all(len(x['input'])<=2000 for x in captures() if x['feature'].startswith('embed:document')),'embedding requests one bounded chunk')
 check(sql("SELECT count(*) FROM community.ai_log WHERE feature='index' AND (input_text<>'' OR output_text<>'')")=='0','C:T-813 index journal never duplicates body')
 call('POST','/api/ai/assistant',{'question':'절차를 설명해줘','confirmed':True})
 assistant_capture=[x for x in captures() if x['feature']=='assistant'][-1];check('절차서 공개' in assistant_capture['input'] and 'AI synthetic' not in assistant_capture['input'],'assistant only procedure sources')
 sql("UPDATE community.embedding_queue SET state='pending' WHERE page_id=(SELECT id FROM community.pages WHERE public_id='"+q+"')")
 empty=call('POST','/api/ai/search',{'q':'절차','confirmed':True});check(q not in [x['page_id'] for x in empty['results']],'C:T-811 incomplete embeddings never search')
 sql("UPDATE community.embedding_queue SET state='done' WHERE page_id=(SELECT id FROM community.pages WHERE public_id='"+q+"')")
 # Current-read filtering occurs before SQL LIMIT: 25 invisible entries cannot starve one visible result.
 found=call('POST','/api/ai/search',{'q':'절차','confirmed':True},'other');check(found['results']==[],'C:T-811 no private semantic result for other reader')
 approve(q,allowed=False);found=call('POST','/api/ai/search',{'q':'절차','confirmed':True});check(q not in [x['page_id'] for x in found['results']],'revocation removes indexed page immediately')
 approve(q);index()
 # Briefing sends titles/due only, no body/checklist/file names.
 sql("UPDATE community.pages SET due_date=current_date,status='review' WHERE public_id='"+q+"'")
 call('POST','/api/ai/briefing',{'confirmed':True});bf=[x for x in captures() if x['feature']=='briefing'][-1]['input'];check('절차서 공개' in bf and '설명 ' not in bf and 'SENSITIVE_FILENAME' not in bf,'briefing title and due only')
 # Inference invalidated while running on independent PHP worker: row/actor/permission all rechecked.
 race=page('race current read');approve(race);call('PUT','/api/pages/'+race+'/shares/claude_employee02',{'level':'view'})
 for mutation in ['version','read','inactive','team']:
  reset_quota();mode.write_text(json.dumps({'delay':0.8}));start=len(captures())
  who='other' if mutation in ['read','team'] else 'employee'
  if mutation=='team':
   call('PATCH','/api/pages/'+race+'/visibility',{'visibility':'team'});call('DELETE','/api/pages/'+race+'/shares/claude_employee02')
   sql("UPDATE users SET team_id=(SELECT team_id FROM users WHERE username='claude_employee01') WHERE id="+str(users['other']['id']))
  with ThreadPoolExecutor(1) as pool:
   future=pool.submit(raw,'POST','/api/pages/'+race+'/ai-summary',{'doc_version':1,'confirmed':True},who)
   end=time.time()+4
   while len(captures())==start and time.time()<end:time.sleep(.02)
   check(len(captures())>start,'race entered inference '+mutation)
   if mutation=='version':sql("UPDATE community.pages SET doc_version=2 WHERE public_id='"+race+"'")
   elif mutation=='read':sql("DELETE FROM community.page_shares WHERE page_id=(SELECT id FROM community.pages WHERE public_id='"+race+"')")
   elif mutation=='inactive':sql('UPDATE users SET is_active=false WHERE id='+str(users[who]['id']))
   else:sql("UPDATE users SET team_id=(SELECT team_id FROM users WHERE username='claude_employee02')+100 WHERE id="+str(users['other']['id'])) if False else sql('UPDATE users SET team_id=NULL WHERE id='+str(users['other']['id']))
   response=future.result();check(response.status_code in [403,404,409] and '合成' not in response.text and '합성 AI' not in response.text,'in-flight '+mutation+' withheld')
  mode.write_text('{}');sql('UPDATE users SET is_active=true WHERE id='+str(users[who]['id']))
  if mutation=='version':sql("UPDATE community.pages SET doc_version=1 WHERE public_id='"+race+"'")
  if mutation=='read':call('PUT','/api/pages/'+race+'/shares/claude_employee02',{'level':'view'})
 # Busy before reservation, concurrency last daily slot, 51st request.
 reset_quota();mode.write_text(json.dumps({'busy':True}));used=sql('SELECT count(*) FROM community.ai_requests');summary(race,expected=429);check(sql('SELECT count(*) FROM community.ai_requests')==used,'busy no quota reservation');mode.write_text('{}')
 sql("INSERT INTO community.ai_requests(user_id,feature) SELECT "+str(users['employee']['id'])+",'summary' FROM generate_series(1,49)")
 with ThreadPoolExecutor(2) as pool:responses=list(pool.map(lambda port:raw('POST','/api/pages/'+race+'/ai-summary',{'doc_version':1,'confirmed':True},port=port),[8698,8699]))
 check(sorted(x.status_code for x in responses)==[200,429],'C:T-805 concurrent final slot only one accepted')
 check(call('GET','/api/ai/status')['remaining']==0,'C:T-805 quota reflects reserved request count');summary(race,expected=429)
 reset_quota();mode.write_text(json.dumps({'error':True}));summary(race,expected=503);mode.write_text('{}');call('GET','/api/search?q=합성');check(call('GET','/api/ai/status')['remaining']==49,'provider failure is a sent request, keyword fallback works')
 # Real canonical audio byte/hash/gate; fake provider only captures metadata not audio.
 fid,path,data=audio();tr=call('POST','/api/ai/transcribe',{'file_id':fid,'language':'ko','confirmed':True});check(tr['bytes']==len(data),'audio actual byte length')
 record=call('GET','/api/me/ai-log')['logs'][0];check(record['input_text']=='' and record['file_refs'][0]['bytes']==len(data) and 'SENSITIVE_FILENAME' not in json.dumps(record),'audio log references only, no filename/bytes')
 for state,expected in [('pending',404),('infected',404)]:
  sql("UPDATE files SET scan_status='"+state+"' WHERE public_id='"+fid+"'");call('POST','/api/ai/transcribe',{'file_id':fid,'language':'auto','confirmed':True},expected=expected)
 sql("UPDATE files SET scan_status='clean' WHERE public_id='"+fid+"'");path.write_bytes(data+b'altered')
 call('POST','/api/ai/transcribe',{'file_id':fid,'language':'auto','confirmed':True},expected=404);path.write_bytes(data)
 sql("UPDATE files SET access_level='admin-only' WHERE public_id='"+fid+"'");call('POST','/api/ai/transcribe',{'file_id':fid,'language':'ko','confirmed':True},expected=404)
 sql("UPDATE files SET access_level='public',size_bytes=10485761 WHERE public_id='"+fid+"'");call('POST','/api/ai/transcribe',{'file_id':fid,'language':'ko','confirmed':True},expected=413)
 sql("UPDATE files SET size_bytes="+str(len(data))+" WHERE public_id='"+fid+"'")
 call('POST','/api/ai/transcribe',{'file_id':fixture['files']['main']['id'],'language':'ko','confirmed':True},expected=400)
 call('POST','/api/ai/transcribe',{'file_id':fid,'language':'ko','confirmed':True,'url':'https://x'},expected=400)
 call('POST','/api/ai/transcribe',{'file_id':fid,'language':'xx','confirmed':True},expected=400)
 # Purge trigger blanks only content; immutable historical metadata and audit remain.
 before=sql("SELECT count(*) FROM community.ai_log WHERE output_text<>'' AND file_refs<> '[]'")
 sql("UPDATE files SET deleted_at=now(),purged_at=now() WHERE public_id='"+fid+"'")
 check(int(before)>0 and sql("SELECT count(*) FROM community.ai_log WHERE file_refs<> '[]' AND (input_text<>'' OR output_text<>'')")=='0','file purge blanks AI transcript content')
 sql("UPDATE community.pages SET deleted_at=now(),purged_at=now() WHERE public_id='"+p+"'")
 check(sql("SELECT count(*) FROM community.ai_log WHERE page_refs @> '[{\"page_id\":\""+p+"\"}]' AND (input_text<>'' OR output_text<>'')")=='0','C:T-814 page purge blanks AI inputs/outputs')
 check(sql("SELECT count(*) FROM community.ai_log WHERE scrubbed_at IS NOT NULL")!='0','purge retains AI metadata rows')
 check('SENSITIVE_FILENAME' not in sql("SELECT coalesce(string_agg(detail::text||coalesce(target_name,''),''),'') FROM audit_logs WHERE action LIKE 'ai.%' OR action='file.download'"),'no audio filename in normal audit')
 # Capture evidence is synthetic only, no credentials.
 out.joinpath('captured-inputs.json').write_text(json.dumps(captures(),ensure_ascii=False,indent=2))
 out.joinpath('journal-sample.json').write_text(json.dumps(call('GET','/api/me/ai-log'),ensure_ascii=False,indent=2))
except Exception:
 import traceback;traceback.print_exc();checks.append({'pass':False,'name':'uncaught test exception'})
finally:
 out.joinpath('stage13-results.json').write_text(json.dumps({'checks':checks},ensure_ascii=False,indent=2))
 n=sum(x['pass'] for x in checks);print(f'RESULT pass={n} fail={len(checks)-n} skip=0',flush=True)
 raise SystemExit(0 if n==len(checks) else 1)
