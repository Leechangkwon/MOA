#!/usr/bin/env python3
"""Q:F-11 지시서 A-5 — 모아 문자 메시지 request_id 멱등 · 마이그레이션 · 권한 (실제 PHP·PostgreSQL).

memories_harness.py 만 부른다(합성 DB `tbox_memories_test_*` · 임시 복사본 · 로컬 php -S, 끝나면 지운다).
운영 DB·운영 8082·시드 계정 0. 요청은 fixture 의 claude_ 계정과 UA tbox-review/<날짜> 뿐이다.
결과 파일에는 단언 이름과 참/거짓만 — 응답 본문·세션·CSRF 는 찍지 않는다.

  ① request_id 없음·모양 틀림·남는 키 → 400(행 0)          ② 같은 UUID 2회 → 1행, 두 번째 200 {ok,id} 같은 id
  ③ 같은 UUID 다른 본문 → 409, 행 1                          ④ 같은 UUID 다른 상대 → 409
  ⑤ 동시 8요청 같은 UUID → 행 1 · 서로에게 동시에 보내도 교착 0  ⑥ 재전송은 member_rate_limits 를 늘리지 않음(한도 중에도 200)
  ⑦ 첨부 갈래 ①~③                                           ⑧ 손님·역할로 보기 403 · 로그인 없음 401 · CSRF 403
  ⑨ GET 응답 모양 불변(request_id 를 내지 않는다)              ⑩ 마이그레이션 2회 멱등 · 기존 행 NULL 보존
  ⑪ 감사 member.message.send 는 새 행에만 1건
  ⑫ (지시서 C-1) 전송 시도 상한 — 모든 POST 시도 분당 120(400·404·409·재전송 포함) · 121번째 429 Retry-After 60 · 새 행 30 그대로 · 다른 사람 영향 0
"""
from pathlib import Path
exec(compile(Path(__file__).with_name('memories_http.py').read_text().split('try:\n for u in users:')[0],__file__,'exec'))
import re
assert str(root).startswith('/tmp/qaredi-stage13-') and manifest['database'].startswith('tbox_memories_test_')
TB=site+'/tbox'
# 두 번째 php -S(같은 복사본·같은 DB) — php -S 는 한 번에 한 요청이라, 동시성은 두 창구에 나눠 보내야 실제로 겹친다(remote_http.py 방식).
SECOND_PORT=os.environ.get('STAGE89_SECOND_PORT','')
assert SECOND_PORT=='8769' and ':8768' in site
TB2=site.replace(':8768',':'+SECOND_PORT)+'/tbox'
UA='tbox-review/'+time.strftime('%Y-%m-%d')
CONFLICT='전송 내용을 확인하고 새로 보내 주세요.'


def tb(method,path,body=None,user='employee',csrf=True,headers=None,raw_body=None,second=False):
 """/tbox 창구 한 번 — 응답은 돌려주기만 하고 찍지 않는다."""
 url=(TB2 if second else TB)+path
 h={'User-Agent':UA}
 if user:
  h['Cookie']='__Host-tbox_session='+users[user]['session']
  if csrf:h['X-CSRF-Token']=users[user]['csrf']
 h.update(headers or {})
 if raw_body is not None:
  h['Content-Type']='application/json'
  return requests.request(method,url,data=raw_body,headers=h,timeout=35)
 return requests.request(method,url,json=body,headers=h,timeout=35)


def uid(key):return int(users[key]['id'])
def rid():return str(uuid.uuid4())
def count(where):return int(sql('SELECT count(*) FROM member_messages WHERE '+where))
def by_request(r):return int(sql("SELECT count(*) FROM member_messages WHERE request_id='"+r+"'"))
def audits():return int(sql("SELECT count(*) FROM audit_logs WHERE action='member.message.send'"))
def reset_rate():sql("UPDATE member_rate_limits SET window_start=now()-interval '2 minutes'")
def rate_hits(key):
 h=hashlib.sha256(('message:'+str(uid(key))).encode()).hexdigest()
 v=sql("SELECT coalesce(max(hits),0) FROM member_rate_limits WHERE key_hash='"+h+"'");return int(v)
def send(peer,body,user='employee',second=False):return tb('POST','/api/messages/'+str(uid(peer)),body,user,second=second)


try:
 emp,oth,mgr=uid('employee'),uid('other'),uid('manager')
 # 정적 — 메신저 모듈이 부르는 drafts.js 판이 커뮤니티 부품 판과 같아야 옛 부품과 섞이지 않는다(2026-09-24 사고 모양).
 app=subprocess.run(['php','scripts/community_asset_version.php','--print','--root',str(root)],cwd=root,capture_output=True,text=True,check=True).stdout
 app=re.search(r'^app=([0-9a-f]{12})$',app,re.M).group(1)
 module=(root/'public/assets/messenger.js').read_text()
 check(re.findall(r"^import \{ createDrafts \} from '\./community/drafts\.js\?v=([0-9a-f]{12})';$",module,re.M)==[app],'messenger.js imports drafts.js with the current community asset version')
 check(subprocess.run(['php','scripts/community_asset_version.php','--check','--root',str(root)],cwd=root,capture_output=True).returncode==0,'community asset versions are stamped')
 reset_rate()
 a0=audits()

 # ① 모양 — request_id 필수(옛 화면의 요청을 조용히 통과시키지 않는다)
 before=count('true')
 for name,body in [('missing',{'text':'옛 요청'}),('missing with attachment null',{'text':'옛 요청','attachment_id':None}),
                   ('not uuid',{'text':'x','request_id':'not-a-uuid'}),('uppercase',{'text':'x','request_id':rid().upper()}),
                   ('uuid v1',{'text':'x','request_id':'6ba7b810-9dad-11d1-80b4-00c04fd430c8'}),('number',{'text':'x','request_id':123}),
                   ('null',{'text':'x','request_id':None}),('extra key',{'text':'x','request_id':rid(),'role':'Admin'}),
                   ('4001 code points',{'text':'가'*4001,'request_id':rid()}),('control char',{'text':'a\u0007b','request_id':rid()}),
                   ('blank',{'text':'   ','request_id':rid()})]:
  check(send('other',body).status_code==400,'① 400 request '+name)
 check(tb('POST','/api/messages/'+str(oth),user='employee',raw_body='[]').status_code==400,'① 400 non-object body')
 check(count('true')==before and audits()==a0,'① rejected requests create no row or audit')

 # ② 같은 UUID 두 번 → 한 행
 r1=rid();h0=rate_hits('employee')
 first=send('other',{'text':'멱등 첫 글 <b>그대로</b>','request_id':r1})
 check(first.status_code==201 and set(first.json())=={'ok','id'} and first.json()['ok'] is True,'② first send 201 {ok,id}')
 mid=first.json()['id'];h1=rate_hits('employee')
 again=send('other',{'text':'멱등 첫 글 <b>그대로</b>','request_id':r1})
 check(again.status_code==200 and again.json()=={'ok':True,'id':mid},'② same request_id again 200 with the same id')
 check(by_request(r1)==1,'② one row for one request_id')
 # ⑥ 재전송은 한도를 먹지 않는다
 check(h1==h0+1 and rate_hits('employee')==h1,'⑥ new row counts once; resend leaves member_rate_limits unchanged')
 # ⑪ 감사는 새 행에만
 check(audits()==a0+1,'⑪ audit member.message.send once for new row, none for resend')
 # 앞뒤 공백 정리(trim) 뒤 같은 본문이면 같은 요청
 check(send('other',{'text':'  멱등 첫 글 <b>그대로</b>\n','request_id':r1}).status_code==200,'② trimmed-equal body is the same request')

 # ③ 같은 UUID 다른 본문 → 409
 conflict=send('other',{'text':'바뀐 본문','request_id':r1})
 check(conflict.status_code==409 and conflict.json().get('error')==CONFLICT,'③ same request_id with different body 409 and exact notice')
 check(by_request(r1)==1 and count("body='바뀐 본문'")==0,'③ conflict creates no row')
 # ④ 같은 UUID 다른 상대 → 409
 check(send('manager',{'text':'멱등 첫 글 <b>그대로</b>','request_id':r1}).status_code==409,'④ same request_id to different peer 409')
 check(count('recipient_id='+str(mgr))==0 and by_request(r1)==1,'④ conflict to another peer creates no row')
 check(audits()==a0+1,'⑪ conflicts write no send audit')
 # UNIQUE 는 보낸 사람마다 — 다른 사람은 같은 UUID 를 따로 쓴다
 other_use=send('employee',{'text':'다른 사람의 같은 UUID','request_id':r1},user='other')
 check(other_use.status_code==201 and other_use.json()['id']!=mid and by_request(r1)==2,'② request_id uniqueness is per sender')

 # ⑤ 동시 8요청 같은 UUID → 한 행, 하나만 201
 r5=rid();text5='동시 전송 '+r5[:8]
 with ThreadPoolExecutor(8) as pool:
  answers=list(pool.map(lambda i:send('other',{'text':text5,'request_id':r5},second=i%2==1),range(8)))
 codes=sorted(a.status_code for a in answers)
 check(codes==[200]*7+[201],'⑤ concurrent same request_id: one 201 and seven 200')
 check(len({a.json()['id'] for a in answers})==1 and by_request(r5)==1,'⑤ concurrent same request_id: one row, same id')
 check(audits()==a0+3,'⑪ concurrent duplicates write one audit')
 # 서로에게 동시에 보내도 교착(외래 키 FOR KEY SHARE vs 보낸 사람 잠금) 없이 전부 201
 reset_rate()
 def cross(i):
  return send('other',{'text':'교차 '+str(i),'request_id':rid()}) if i%2==0 else send('employee',{'text':'교차 '+str(i),'request_id':rid()},user='other',second=True)
 with ThreadPoolExecutor(2) as pool:
  crossed=list(pool.map(cross,range(40)))
 check(all(c.status_code==201 for c in crossed),'⑤ two members sending to each other concurrently (two servers): all 201, no deadlock or 5xx')
 check(audits()==a0+3+40,'⑪ every concurrent new row audited once')

 # ⑥ 한도 — 1분 30건(새 행만). 한도 중에도 같은 요청 재전송은 200(행이 이미 있다)
 h=hashlib.sha256(('message:'+str(emp)).encode()).hexdigest()
 sql("UPDATE member_rate_limits SET hits=30,window_start=now() WHERE key_hash='"+h+"'")
 limited=send('other',{'text':'한도 넘김','request_id':rid()})
 check(limited.status_code==429 and limited.headers.get('Retry-After')=='60','⑥ 31st new message in a minute is 429 with Retry-After')
 check(count("body='한도 넘김'")==0 and rate_hits('employee')==30,'⑥ limited request creates no row and does not raise the counter')
 check(send('other',{'text':'멱등 첫 글 <b>그대로</b>','request_id':r1}).status_code==200,'⑥ resend of an existing request still answers 200 while limited')
 reset_rate()

 # ⑦ 첨부 갈래 — 합성 완료 이미지(내 것·이 상대). 이미지 파일은 필요 없다(전송만 본다).
 img=str(uuid.uuid4())
 sql("INSERT INTO member_ai_assists(id,user_id,peer_id,request_id,action,input,language,aspect,state,stored_name,image_mime,image_bytes,image_sha256,model,finished_at) "
     "VALUES('"+img+"',"+str(emp)+","+str(oth)+",'"+rid()+"','image','합성 이미지 설명','ko','1:1','complete','"+str(uuid.uuid4())+".png','image/png',68,'"+'0'*64+"','gemini-3.1-flash-lite-image',now())")
 check(send('other',{'text':'','attachment_id':img}).status_code==400,'⑦ attachment without request_id 400')
 check(send('other',{'text':'','attachment_id':img,'request_id':'bad'}).status_code==400,'⑦ attachment with malformed request_id 400')
 r7=rid();sent7=send('other',{'text':'','attachment_id':img,'request_id':r7})
 check(sent7.status_code==201,'⑦ attachment send 201')
 again7=send('other',{'text':'','attachment_id':img,'request_id':r7})
 check(again7.status_code==200 and again7.json()=={'ok':True,'id':sent7.json()['id']},'⑦ attachment same request_id 200 same id')
 check(send('other',{'text':'다른 설명','attachment_id':img,'request_id':r7}).status_code==409,'⑦ attachment same request_id different body 409')
 check(send('other',{'text':'AI로 만든 이미지','attachment_id':None,'request_id':r7}).status_code==409,'⑦ same request_id and body without the attachment 409')
 check(send('other',{'text':'','attachment_id':img,'request_id':rid()}).status_code==200,'⑦ existing image duplicate guard still answers the prior row')
 check(by_request(r7)==1 and count("ai_attachment_id='"+img+"'")==1,'⑦ one row for the attachment')
 check(send('other',{'text':'','attachment_id':str(uuid.uuid4()),'request_id':rid()}).status_code==404,'⑦ unknown attachment 404')
 a7=audits()

 # ⑧ 권한 — 그대로
 check(tb('POST','/api/messages/'+str(emp),{'text':'손님','request_id':rid()},user='guest').status_code==403,'⑧ guest 403')
 check(tb('POST','/api/messages/'+str(oth),{'text':'로그인 없음','request_id':rid()},user=None).status_code==401,'⑧ no session 401')
 check(tb('POST','/api/messages/'+str(oth),{'text':'CSRF 없음','request_id':rid()},csrf=False).status_code==403,'⑧ missing CSRF 403')
 sql("UPDATE sessions SET act_role='Employee',act_started_at=now(),act_last_seen_at=now() WHERE user_id="+str(uid('admin')))
 check(tb('GET','/api/me',user='admin').json().get('acting') is True,'⑧ admin fixture is acting as Employee')
 check(tb('POST','/api/messages/'+str(emp),{'text':'역할로 보기','request_id':rid()},user='admin').status_code==403,'⑧ acting role 403')
 check(tb('GET','/api/messages',user='admin').status_code==403,'⑧ acting role cannot list members')
 sql("UPDATE sessions SET act_role=NULL,act_started_at=NULL,act_last_seen_at=NULL WHERE user_id="+str(uid('admin')))
 check(tb('POST','/api/messages/'+str(emp),{'text':'본인에게','request_id':rid()},user='employee').status_code==400,'⑧ self message 400')
 check(audits()==a7,'⑧ denied sends write no send audit')

 # ⑨ GET 모양 불변 — request_id 를 내지 않는다
 got=tb('GET','/api/messages/'+str(oth))
 data=got.json()
 check(got.status_code==200 and set(data)=={'peer','messages','has_more'} and set(data['peer'])=={'id','username','display_name'},'⑨ conversation response keys unchanged')
 check(all(set(m)=={'id','sender_id','body','sent_at','read_at','ai_attachment_id','image_url'} for m in data['messages']) and len(data['messages'])>0,'⑨ message keys unchanged')
 check('request_id' not in got.text and r1 not in got.text and r5 not in got.text,'⑨ request_id never appears in GET response')
 check(any(m['body']=='멱등 첫 글 <b>그대로</b>' for m in data['messages']),'⑨ stored body is the plain text sent')
 listing=tb('GET','/api/messages?q=')
 check(listing.status_code==200 and 'request_id' not in listing.text,'⑨ member list unchanged and without request_id')

 # ⑩ 마이그레이션 — 열이 없던 DB(옛 모양)에 두 번 적용해도 같다 · 기존 행은 NULL 그대로
 migration=(root/'db/migrations/2026-10-06_member_messages_request_id.sql').read_text()
 statements='\n'.join(l for l in migration.upper().splitlines() if not l.strip().startswith('--'))
 check(not re.search(r'\b(DROP|GRANT|REVOKE|OWNER|DELETE|UPDATE|TRUNCATE)\b',statements),'⑩ migration is additive (no drop, grant, owner or data change)')
 snap="SELECT md5(string_agg(concat_ws('|',id,sender_id,recipient_id,body,sent_at,read_at,ai_attachment_id),',' ORDER BY id)) FROM member_messages"
 rows_before=sql(snap);total=count('true')
 sql('ALTER TABLE public.member_messages DROP COLUMN request_id')
 check(sql("SELECT count(*) FROM information_schema.columns WHERE table_name='member_messages' AND column_name='request_id'")=='0','⑩ simulated pre-migration table without request_id')
 for _ in range(2):sql(migration)
 check(sql("SELECT data_type FROM information_schema.columns WHERE table_name='member_messages' AND column_name='request_id'")=='uuid','⑩ request_id uuid column added')
 index=sql("SELECT indexdef FROM pg_indexes WHERE indexname='member_messages_request_idx'")
 check('UNIQUE' in index and '(sender_id, request_id)' in index and 'request_id IS NOT NULL' in index,'⑩ partial unique index (sender_id, request_id)')
 check(sql(snap)==rows_before and count('true')==total and count('request_id IS NOT NULL')==0,'⑩ existing rows preserved with request_id NULL after two applications')
 r10=rid();n10=send('other',{'text':'마이그레이션 뒤','request_id':r10})
 check(n10.status_code==201 and send('other',{'text':'마이그레이션 뒤','request_id':r10}).json()=={'ok':True,'id':n10.json()['id']},'⑩ idempotent send works after migration')
 sql(migration)
 check(by_request(r10)==1 and count('request_id IS NOT NULL')==1,'⑩ third application keeps new request_id values')
 legacy=send('other',{'text':'옛 요청은 거절'})
 check(legacy.status_code==400,'⑩ old client without request_id still 400 after migration')

 # ⑫ 전송 시도 상한(지시서 C-1 · R-118) — 모든 POST 시도 분당 120(모양 틀림·재전송·충돌·없는 상대·자기 자신 포함, 트랜잭션 밖).
 #    새 행 30 은 그대로(31번째 새 글 429). 121번째 시도는 무엇이든 429 + Retry-After: 60. 다른 사람은 영향 0. 실측 번호를 단언 이름에 남긴다.
 reset_rate()
 def attempt_hits(key):
  h=hashlib.sha256(('message-attempt:'+str(uid(key))).encode()).hexdigest()
  return int(sql("SELECT coalesce(max(hits),0) FROM member_rate_limits WHERE key_hash='"+h+"'"))
 nobody=int(sql('SELECT coalesce(max(id),0)+1000 FROM users'))
 rows12=[];first12=None;t12=time.time()
 def attempt(kind,fn):
  res=fn();rows12.append((len(rows12)+1,kind,res.status_code,(res.json().get('id') if res.status_code in (200,201) else None),res.headers.get('Retry-After')))
  return res
 for i in range(30):
  r=rid();attempt('new',lambda r=r,i=i:send('other',{'text':'시도 상한 '+str(i),'request_id':r}))
  if i==0:first12=(r,rows12[-1][3])
 attempt('new-31st',lambda:send('other',{'text':'시도 상한 31번째 새 글','request_id':rid()}))
 kinds=[('bad-shape',lambda:send('other',{'text':'x','request_id':'not-a-uuid'})),
        ('unknown-peer',lambda:tb('POST','/api/messages/'+str(nobody),{'text':'없는 상대','request_id':rid()})),
        ('self',lambda:send('employee',{'text':'나에게','request_id':rid()})),
        ('conflict',lambda:send('other',{'text':'바뀐 본문','request_id':first12[0]})),
        ('resend',lambda:send('other',{'text':'시도 상한 0','request_id':first12[0]}))]
 while len(rows12)<120:
  k,fn=kinds[len(rows12)%len(kinds)];attempt(k,fn)
 for k,fn in [('resend',kinds[4][1]),('bad-shape',kinds[0][1]),('unknown-peer',kinds[1][1]),('conflict',kinds[3][1])]:attempt(k+'-over',fn)
 elapsed12=round(time.time()-t12,1)
 expect12={'new':201,'new-31st':429,'bad-shape':400,'unknown-peer':404,'self':400,'conflict':409,'resend':200}
 within=[x for x in rows12 if x[0]<=120]
 blocked=[x[0] for x in rows12 if x[2]==429 and x[1]!='new-31st']
 resend_ok=[x[0] for x in rows12 if x[1]=='resend' and x[2]==200]
 check(elapsed12<55,'⑫ 124 attempts finished inside one rate window ('+str(elapsed12)+'s)')
 check(all(x[2]==expect12[x[1]] for x in within),'⑫ attempts 1-120 keep their own answers (201×30 · 429 31st new · 400 · 404 · 409 · 200) — not cut by the attempt limit')
 check(rows12[30][2]==429 and rows12[30][4]=='60' and count("body='시도 상한 31번째 새 글'")==0,'⑫ 31st new message is still 429 with Retry-After 60 (new-row limit 30 unchanged)')
 check(bool(resend_ok) and all(x[3]==first12[1] for x in rows12 if x[1]=='resend' and x[2]==200),'⑫ resends within 120 answer 200 with the same id (last 200 resend at attempt #'+str(max(resend_ok) if resend_ok else 0)+')')
 check(blocked==[121,122,123,124],'⑫ first attempt blocked by the attempt limit is #'+str(blocked[0] if blocked else 0)+' (121st) and every later attempt is 429')
 check(all(x[4]=='60' for x in rows12[120:]),'⑫ attempt-limit 429 carries Retry-After: 60')
 check(attempt_hits('employee')==124 and count("body LIKE '시도 상한 %'")==30,'⑫ attempt counter counts every POST (124) · only 30 new rows written')
 other12=send('employee',{'text':'다른 사람은 영향 없음','request_id':rid()},user='other')
 check(other12.status_code==201 and attempt_hits('other')==1,'⑫ another member is unaffected (201, own counter 1)')
 check(tb('GET','/api/messages/'+str(oth)).status_code==200,'⑫ reading the conversation (GET) is not limited by the attempt counter')
 reset_rate()
 after12=send('other',{'text':'시도 상한 0','request_id':first12[0]})
 check(after12.status_code==200 and after12.json()=={'ok':True,'id':first12[1]},'⑫ after the window passes the same resend answers 200 with the same id again')
finally:
 (out/'messenger-http-results.json').write_text(json.dumps(checks,ensure_ascii=False,indent=2))
 print('RESULT pass='+str(sum(x['pass'] for x in checks))+' fail='+str(sum(not x['pass'] for x in checks)),flush=True)
