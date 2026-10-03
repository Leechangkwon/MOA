#!/usr/bin/env python3
"""Real PHP/SQL/media acceptance in a fresh fixture. Never contacts an AI provider."""
import os,json,uuid,requests,subprocess,time,base64,hashlib,stat
from pathlib import Path
from concurrent.futures import ThreadPoolExecutor
manifest=json.loads(Path(os.environ['FILE_RENAME_MANIFEST']).read_text());assert manifest['database'].startswith('tbox_memories_test_')
root=Path(manifest['instance']);out=Path(os.environ['FILE_RENAME_OUTPUT']);users=json.loads(Path(manifest['credentials_file']).read_text())['users'];site=manifest['base_url'].removesuffix('/tbox');base=site+'/community';checks=[]
def check(ok,name):
 checks.append({'pass':bool(ok),'name':name});print(('PASS ' if ok else 'FAIL ')+name,flush=True)
 if not ok:raise AssertionError(name)
def raw(method,path,body=None,user='employee',csrf=True,headers=None,files=None,port=None):
 h={'User-Agent':'tbox-review/'+time.strftime('%Y-%m-%d')}
 if user:
  h['Cookie']='__Host-tbox_session='+users[user]['session']
  if csrf:h['X-CSRF-Token']=users[user]['csrf']
 h.update(headers or {});url=base if port is None else base.replace(':8768',':'+str(port))
 return requests.request(method,url+path,json=body,headers=h,files=files,timeout=35)
def call(method,path,body=None,user='employee',expected=200,**kw):
 r=raw(method,path,body,user,**kw)
 if r.status_code!=expected:print('SAFE_ERROR='+r.text[:400],flush=True)
 check(r.status_code==expected,f'{method} {path.split("/memories/s/")[0]} {r.status_code}/{expected}')
 try:return r.json()
 except ValueError:return r
def sql(text):
 env={k:v for k,v in os.environ.items() if not k.startswith('PG')};env['PGPASSFILE']=str(Path.home()/'.config/qaredi/pgpass-testdb')
 r=subprocess.run(['psql','-h','127.0.0.1','-U','tbox_testdb','-w','-X','-At','-d',manifest['database'],'-v','ON_ERROR_STOP=1'],env=env,input=text.encode(),capture_output=True,check=True);return r.stdout.decode().strip()
def payload(**kw):return dict(caption='실제 서버 합성 글 <script>문자</script>',location='합성 장소',trip='검증',taken_on='2026-10-03',visibility='private',media_ids=[],request_id=str(uuid.uuid4()),**kw)
def save(v=None,user='employee'):return call('POST','/api/memories',v or payload(),user,201)['id']
png=base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=')
def upload(user='employee',data=png,name='합성사진.png',expected=201):return call('POST','/api/memories/media',user=user,expected=expected,files={'file':(name,data,'application/octet-stream')})
try:
 for u in users:
  users[u]['username']=sql('SELECT username FROM users WHERE id='+str(users[u]['id']))
  call('GET','/api/me/space',user=u)
 call('GET','/api/memories',user=None,expected=401);call('POST','/api/memories',payload(),user='guest',expected=403)
 call('POST','/api/memories',payload(),csrf=False,expected=403)
 for extra in ({'owner_user_id':users['other']['id']},{'visibility':'unknown'},{'taken_on':'2026-02-30'}):
  b=payload();b.update(extra);call('POST','/api/memories',b,expected=400)
 b=payload();pid=save(b);check(call('POST','/api/memories',b,expected=201)['id']==pid,'same request ID creates one post')
 for u in ('other','guest',None):call('GET','/api/memories/'+pid,user=u,expected=404 if u else 401)
 own=call('GET','/api/memories/'+pid)['post'];check(own['caption']==b['caption'] and own['visibility']=='private','literal text/private default')
 check(not call('GET','/api/memories',user='other')['posts'],'other feed omits private post')
 for method in ['PATCH','DELETE']:call(method,'/api/memories/'+pid,b,user='other',expected=404)
 edit=dict(b,revision=0);call('PATCH','/api/memories/'+pid,edit,expected=409)
 edit.update(revision=1,visibility='community');call('PATCH','/api/memories/'+pid,edit)
 check('original_caption' not in call('GET','/api/memories/'+pid,user='other')['post'],'nonowner excludes original')
 call('POST','/api/memories/'+pid+'/like',{},user='other');call('POST','/api/memories/'+pid+'/like',{},user='other')
 check(call('GET','/api/memories/'+pid)['post']['likes']==1,'likes idempotent')
 call('POST','/api/memories/'+pid+'/comments',{'body':'<img src=x onerror=1> 합성'},user='other');check(call('GET','/api/memories/'+pid+'/comments')['comments'][0]['body'].startswith('<img'),'comment remains literal')
 call('POST','/api/memories/'+pid+'/comments',{'body':'x','user_id':1},expected=400)
 upload(data=b'<?php echo 1;',name='spoof.jpg',expected=400)
 call('POST','/api/memories/media',csrf=False,files={'file':('x.png',png,'image/png')},expected=403)
 upload(user='guest',expected=403)
 media=upload();mid=media['id'];file=root.parent/'storage/memories'/mid
 check(file.read_bytes()==png and stat.S_IMODE(file.stat().st_mode)==0o600 and stat.S_IMODE(file.parent.stat().st_mode)==0o700,'media bytes and private permissions')
 check(sql("SELECT sha256 FROM community.memory_media WHERE id='"+mid+"'")==hashlib.sha256(png).hexdigest(),'stored media SHA256')
 call('GET','/api/memories/media/'+mid,user='other',expected=404)
 call('POST','/api/memories',dict(payload(),media_ids=[mid]),user='other',expected=400)
 pb=dict(payload(),media_ids=[mid],visibility='shared');photo=save(pb)
 call('POST','/api/memories',dict(payload(),media_ids=[mid]),expected=400)
 link=call('POST','/api/memories/links',{'post_id':photo},expected=201)['path'].removeprefix('/community');profile=call('POST','/api/memories/links',{},expected=201)['path'].removeprefix('/community')
 anon=call('GET',link+'/feed',user=None);check([x['id'] for x in anon['posts']]==[photo],'post link returns exact shared post')
 check(all(x['visibility']=='shared' for x in call('GET',profile+'/feed',user=None)['posts']),'profile link returns shared records only')
 publicmedia=link+'/media/'+mid;r=raw('GET',publicmedia,user=None);check(r.status_code==200 and r.content==png,'capability serves exact attached bytes')
 for value,statuscode,content in [('bytes=0-3',206,png[:4]),('bytes=-4',206,png[-4:]),('bytes=99999-',416,None),('bytes=3-1',416,None),('bytes=0-1,3-4',416,None),('bytes=-0',416,None)]:
  r=raw('GET',publicmedia,user=None,headers={'Range':value});check(r.status_code==statuscode and (content is None or r.content==content),'range '+value)
 foreign=upload(user='other');call('GET',link+'/media/'+foreign['id'],user=None,expected=404)
 replaced=upload();call('PATCH','/api/memories/'+photo,dict(pb,revision=1,media_ids=[replaced['id']]))
 call('GET',publicmedia,user=None,expected=404);check(raw('GET','/api/memories/media/'+mid).content==png,'owner retains original media')
 call('GET','/memories/s/'+'x'*43+'/feed',user=None,expected=404)
 call('PATCH','/api/memories/'+photo,dict(pb,revision=2,visibility='private',media_ids=[replaced['id']]))
 call('GET',link+'/feed',user=None,expected=404)
 call('PATCH','/api/memories/'+photo,dict(pb,revision=3,media_ids=[replaced['id']]))
 call('GET',link+'/feed',user=None,expected=404)
 exp=call('POST','/api/memories/links',{'post_id':photo},expected=201)['path'].removeprefix('/community');token=exp.rsplit('/',1)[1]
 sql("UPDATE community.memory_links SET expires_at=now()-interval '1 second' WHERE token_hash='"+hashlib.sha256(token.encode()).hexdigest()+"'");call('GET',exp+'/feed',user=None,expected=404)
 call('DELETE','/api/memories/links',{});call('GET',profile+'/feed',user=None,expected=404)
 check(sql("SELECT has_table_privilege('tbox_backup','community.memory_posts','SELECT') AND has_table_privilege('tbox_backup','community.messages','SELECT')")=='t','backup role reads new tables')
 call('GET','/api/messenger',user=None,expected=401);call('GET','/api/messenger',user='guest',expected=403)
 call('GET','/api/messenger/members?q=x');call('POST','/api/messenger/conversations',{'username':users['other']['username']},csrf=False,expected=403)
 cid=call('POST','/api/messenger/conversations',{'username':users['other']['username']})['id']
 check(call('POST','/api/messenger/conversations',{'username':users['other']['username']})['id']==cid,'conversation unique pair')
 for u in ('manager','guest',None):
  status=404 if u=='manager' else 403 if u=='guest' else 401
  for method,suffix,body in [('GET','',None),('POST','/messages',{'body':'secret','request_id':str(uuid.uuid4())}),('POST','/read',{'last_id':1})]:call(method,'/api/messenger/conversations/'+cid+suffix,body,user=u,expected=status)
 msg={'body':'비공개 합성 메시지 <script>문자</script>','request_id':str(uuid.uuid4())};path='/api/messenger/conversations/'+cid
 sent=call('POST',path+'/messages',msg,expected=201)['message'];check(call('POST',path+'/messages',msg)['message']['id']==sent['id'],'message retry returns same ID')
 call('POST',path+'/messages',dict(msg,body='different'),expected=409);call('POST',path+'/messages',dict(msg,sender_id=1),expected=400)
 call('POST',path+'/messages',msg,csrf=False,expected=403)
 incoming=call('GET',path,user='other')['messages'];check(incoming[0]['body']==msg['body'] and incoming[0]['mine'] is False,'participant reads literal message with sender flag')
 call('POST',path+'/read',{'last_id':int(sent['id'])},user='other');check(call('GET','/api/messenger',user='other')['conversations'][0]['unread']==0,'read cursor clears received only')
 cid2=call('POST','/api/messenger/conversations',{'username':users['manager']['username']})['id'];m2=call('POST','/api/messenger/conversations/'+cid2+'/messages',{'body':'다른 대화','request_id':str(uuid.uuid4())},expected=201)['message']
 before=sql("SELECT low_read||':'||high_read FROM community.conversations WHERE id='"+cid+"'");call('POST',path+'/read',{'last_id':int(m2['id'])},user='other');check(sql("SELECT low_read||':'||high_read FROM community.conversations WHERE id='"+cid+"'")==before,'foreign conversation read ID rejected')
 sql('UPDATE users SET is_active=false WHERE id='+str(users['other']['id']));call('GET',path,expected=404);call('POST',path+'/messages',{'body':'inactive','request_id':str(uuid.uuid4())},expected=404);sql('UPDATE users SET is_active=true WHERE id='+str(users['other']['id']))
 sql("UPDATE users SET expires_at=now()-interval '1 second' WHERE id="+str(users['other']['id']));call('GET',path,expected=404);sql('UPDATE users SET expires_at=NULL WHERE id='+str(users['other']['id']))
 sql("INSERT INTO community.messages(conversation_id,sender_id,request_id,body) SELECT '"+cid+"',"+str(users['employee']['id'])+",gen_random_uuid(),'page'||n FROM generate_series(1,51) n")
 first=call('GET',path);second=call('GET',path+'?before='+first['messages'][0]['id']);check(first['has_more'] and not(set(x['id'] for x in first['messages'])&set(x['id'] for x in second['messages'])),'message pagination no duplicates')
 call('POST',path+'/messages',{'body':'rate','request_id':str(uuid.uuid4())},expected=429)
 g=call('GET','/api/messenger/gpt');check(g['enabled'] is False and g['turns']==[],'GPT remains disabled without server key')
 call('POST','/api/messenger/gpt',{'body':'must not leave server','request_id':str(uuid.uuid4())},expected=503)
 sql("INSERT INTO community.gpt_turns(user_id,request_id,prompt,reply) VALUES("+str(users['employee']['id'])+",gen_random_uuid(),'synthetic own prompt','synthetic own reply')")
 check(call('GET','/api/messenger/gpt',user='other')['turns']==[],'other user cannot see GPT history')
 check(len(call('GET','/api/messenger/gpt')['turns'])==1,'owner reads own GPT history even disabled')
 for url in ['/', '/workspace','/?space=1','/today','/ai']:
  r=raw('GET',url);check(r.status_code==200 and 'id="community-messenger"' in r.text,'common messenger actual PHP '+url)
 private=root.parent/'storage/private-ai';private.mkdir(mode=0o700);(private/'gemini.key').write_text('synthetic-credential-not-an-upload')
 integrity=subprocess.run(['php','scripts/consistency_check.php','--strict','--quiet'],cwd=root,capture_output=True)
 check(integrity.returncode==0,'media integrity check excludes private AI configuration')
 file.write_bytes(b'corrupt-synthetic-photo')
 integrity=subprocess.run(['php','scripts/consistency_check.php','--strict','--quiet'],cwd=root,capture_output=True)
 check(integrity.returncode==2,'media corruption still fails strict integrity')
 file.write_bytes(png)
 audit=root.parent/'logs/audit.log';text=audit.read_text() if audit.exists() else ''
 check(msg['body'] not in text and token not in text and 'synthetic own prompt' not in text,'audit excludes private text and capability')
finally:
 (out/'results.json').write_text(json.dumps(checks,ensure_ascii=False,indent=2));print('RESULT pass='+str(sum(x['pass'] for x in checks))+' fail='+str(sum(not x['pass'] for x in checks)),flush=True)
