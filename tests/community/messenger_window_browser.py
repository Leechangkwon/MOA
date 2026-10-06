#!/usr/bin/env python3
"""Q:F-11 지시서 B-5·B-6 — 떠 있는 메신저 창 (실제 PHP·PostgreSQL + Chromium). tests/community/messenger_browser.py(커뮤니티 모달 · 모의 HTTP)를 대체한다.

memories_harness.py 만 부른다(합성 DB · 임시 복사본 · 로컬 php -S, 끝나면 지운다). 운영·시드 계정 0 — fixture 의 claude_ 계정과
합성 대화 상대 둘(synthetic_peer_a/b — 로그인 없음, 사람 목록 SQL 이 claude_ 를 빼므로)만. AI 제공자는 임시 복사본의 Fetch.php 에서만 합성 응답(외부 요청 0).
결과 파일에는 단언 이름과 참/거짓·수치만(세션·CSRF·응답 본문 0). 스크린샷은 결과 폴더에.

  Q:T-11 단추      t-box 셸(직원: 홈·설정·활동·내 휴지통 / 관리자: 위키·관리 허브) · 커뮤니티 9 · /welcome · 런처 — 같은 id 의 단추 1개 · 창 1개 ·
                   (Q:F-12 C — 옛 「오늘」 /community/today 는 308 넘김이 되어 커뮤니티 셸 대표 화면을 /community/procedures · /community/workspace 로 바꿨다 ·
                    C-6 사이드바는 이야기 셸 /community/memories 도 보고, 옛 nav.moa-space-nav 대신 아이콘 줄 목록을 적는다)
                   /messages 는 창 0 · 손님·역할로 보기는 단추·창·스크립트 0(그래도 POST /api/messages/{n} 403) · 미로그인 런처 창·스크립트 0 · Set-Cookie 0
  Q:T-12 이동 복원  커뮤니티 글 → 자료실 홈 → 설정 → 런처: 창 열림·상대·입력·스크롤 같음 · AI 스레드도 · 복원 때 초점은 창 밖
                   보정 D-1: 이어 열 상대가 없으면(404) 번호를 잊는다 — 화면 둘을 더 옮겨도 404 요청 1회 · 상태에 그 번호 0 · 입력 칸 잠김
  Q:T-13 여러 탭    전송 → 다른 탭 초안 비고 목록에 뜸 · 배지 같음 · 같은 UUID 동시 전송 1행 · 대표 탭(navigator.locks)을 닫으면 다른 탭이 잇는다
  Q:T-14·15        로그아웃(셸 단추) → 접두·상태 0 · B 로그인 → 창 닫힘·초안 없음
  Q:T-16 폭·테마    390 · 412(터치·마우스) · 820 · 1440 × 밝게·어둡게 × 고대비: 넘침 0 · 콘솔 0 · <720 아래 시트 · ≥720 사용 안내 단추와 겹침 0 · 누름 자리 44
  Q:T-17 비모달     뒤 화면 클릭·스크롤 · 창 밖 클릭에 안 닫힘 · Esc 는 초점이 창 안일 때만 · 재인증·확인·AI 도구 창이 창 위
  Q:T-18 폴링       닫힘 30초 1회(X-Qaredi-Background) · 열림 8초 · 숨김 0 · 다시 보이면 곧바로 · sessions.last_seen_at 그대로
  Q:T-19 대체       커뮤니티 옛 창 0 · 사람 대화 라우트 6개 404 · 휴면 GPT 두 갈래 동작 그대로 · 화면 글자에 「GPT」「OpenAI」 0
  Q:T-20 꾸미기     「모아 AI 에게 묻기」 → 창의 모아 AI 칸에 요청문(전송 0) → 답변 「꾸미기 화면으로」 → #studio-result · 다른 답변엔 단추 없음
  더해서            dbg_u2_ui.py 의 창 바닥 규칙(MODAL_RULE)·ui_snapshot.py 의 결함 감사(AUDIT_JS)를 같은 글자 그대로 이 인스턴스에서 · /messages CSP 헤더 불변
  지시서 C          C-3 사용 안내: 창 안 「? 이 창 사용 안내」 → 떠 있는 창 단계(목록·검색·모아 AI·입력·크게 보기·닫기, 「GPT」 0) · 떠 있는 창이 열려 있어도
                   자동 제안·화면 안내가 뜬다 · 메신저 안내를 이어 볼 때 창이 닫혀 있으면 여는 단추로 연다(링크 단추여도 이동 0) · 안내는 보내기·닫기·글 변경 0
                   C-4 스레드 도움말(떠 있는 창 · 모아 AI Google 고지 · 「GPT」 0) · C-5 AI 도구 창 MODAL_RULE 0(닫힘·열림 네 상태 · 1440·390) · 바닥 「닫기」 ·
                   C-6 사이드바 /messages 링크 0 · 「메신저」 글자 1(창 단추) · 전체 화면은 창 머리 「크게 보기」
"""
from pathlib import Path
exec(compile(Path(__file__).with_name('memories_http.py').read_text().split('try:\n for u in users:')[0],__file__,'exec'))
import re,secrets,struct,zlib
from playwright.sync_api import sync_playwright,expect
from urllib.parse import urlsplit
assert str(root).startswith('/tmp/qaredi-stage13-') and manifest['database'].startswith('tbox_memories_test_')
host=urlsplit(site).hostname
UA='tbox-review/'+time.strftime('%Y-%m-%d')
TB=site+'/tbox'
ONLY=set(filter(None,os.environ.get('QF11B_ONLY','').split(',')))
shots=out/'shots';shots.mkdir(exist_ok=True)
metrics={}


def check(ok,name):
 """단언 — 실패해도 멈추지 않고 끝까지 돈다(마지막에 실패 수로 끝낸다). 이름·참거짓만 남긴다."""
 checks.append({'pass':bool(ok),'name':name});print(('PASS ' if ok else 'FAIL ')+name,flush=True)
 return bool(ok)


def want(group):return not ONLY or group in ONLY


def tb(method,path,body=None,user='employee',csrf=True,session=None):
 h={'User-Agent':UA}
 if user or session:
  h['Cookie']='__Host-tbox_session='+(session or users[user]['session'])
  if csrf and user:h['X-CSRF-Token']=users[user]['csrf']
 return requests.request(method,TB+path,json=body,headers=h,timeout=35,allow_redirects=False)


def png64():
 raw_=b''.join(b'\x00'+bytes([90,140,200])*64 for _ in range(64))
 def chunk(t,d):return struct.pack('>I',len(d))+t+d+struct.pack('>I',zlib.crc32(t+d)&0xffffffff)
 return b'\x89PNG\r\n\x1a\n'+chunk(b'IHDR',struct.pack('>IIBBBBB',64,64,8,2,0,0,0))+chunk(b'IDAT',zlib.compress(raw_))+chunk(b'IEND',b'')


# 합성 AI 제공자 — 이 임시 복사본에서만(저장소 파일 변경 0 · messenger_store_browser.py 와 같은 방식)
transport=root/'src/Fetch.php';original=transport.read_text()
needle="        $host = 'generativelanguage.googleapis.com';"
assert original.count(needle)==1
transport.write_text(original.replace(needle,"        return require __DIR__ . '/messenger-test-transport.php';\n"+needle))
(root/'src/messenger-test-transport.php').write_text("""<?php
// Disposable messenger window browser test only; never a production transport.
if (!str_starts_with(Env::get('DB_NAME'), 'tbox_memories_test_')) throw new LogicException('Test DB required');
if ($image) return ['status'=>'completed','steps'=>[['type'=>'model_output','content'=>[['type'=>'image','mime_type'=>'image/png','data'=>'"""+base64.b64encode(png64()).decode()+"""']]]],'usage'=>['total_tokens'=>10]];
return ['candidates'=>[['finishReason'=>'STOP','content'=>['parts'=>[['text'=>'합성 꾸미기 답변입니다.']]]]],'usageMetadata'=>['totalTokenCount'=>10]];
""")
(root.parent/'tmp').mkdir(mode=0o700,exist_ok=True)
keydir=root.parent/'private-messenger-ai';keydir.mkdir(mode=0o700,exist_ok=True)
(keydir/'gemini.key').write_text('synthetic-key-for-disposable-test-only');(keydir/'gemini.key').chmod(0o600)


def uid(key):return int(users[key]['id'])
def session_hash(token):return hashlib.sha256(token.encode()).hexdigest()
def new_session(key,act_role=None):
 token=secrets.token_hex(32)
 if act_role:
  sql("INSERT INTO sessions(id,user_id,expires_at,ip,user_agent,act_role,act_started_at,act_last_seen_at) VALUES('"+session_hash(token)+"',"+str(uid(key))+",clock_timestamp()+interval '8 hours','127.0.0.1','"+UA+"','"+act_role+"',now(),now())")
 else:
  sql("INSERT INTO sessions(id,user_id,expires_at,ip,user_agent) VALUES('"+session_hash(token)+"',"+str(uid(key))+",clock_timestamp()+interval '8 hours','127.0.0.1','"+UA+"')")
 return token
def cookie(token):return [{'name':'__Host-tbox_session','value':token,'domain':host,'path':'/','secure':True,'httpOnly':True,'sameSite':'Lax'}]

CONSOLE_OK={('404','/favicon.ico'),('404','/tbox/assets/moa/lake.jpg'),('404','/tbox/assets/moa/forest.jpg'),('404','/tbox/assets/moa/ocean.jpg')}
# 브라우저가 스스로 남기는 줄(자원 상태 · 「[DOM] … autocomplete」 같은 Chromium 안내 — 설정 화면의 비밀번호 칸)은 화면 코드의 출력이 아니다
def code_console(lines):return [t for t,_ in lines if not t.startswith('Failed to load resource') and not t.startswith('[DOM] ')]
def resource_lines(lines):return {(re.search(r'status of ([0-9]{3})',t).group(1) if re.search(r'status of ([0-9]{3})',t) else '?',p) for t,p in lines if t.startswith('Failed to load resource')}


class Tab:
 """한 탭 — 콘솔·오류·요청 기록과 자주 쓰는 측정."""
 def __init__(self,ctx):
  self.page=ctx.new_page();self.console=[];self.errors=[];self.requests=[];self.bad=[]
  p=self.page
  p.on('console',lambda m:self.console.append((m.text,urlsplit(m.location.get('url','')).path)))
  p.on('pageerror',lambda e:self.errors.append(str(e)))
  p.on('request',lambda r:self.requests.append((time.time(),r.method,urlsplit(r.url).path+('?'+urlsplit(r.url).query if urlsplit(r.url).query else ''),r.headers.get('x-qaredi-background',''),urlsplit(r.url).netloc)))
  p.on('response',lambda r:self.bad.append(r.url) if r.status>=500 else None)
 def go(self,path):
  self.page.goto(site+path,wait_until='networkidle');self.page.wait_for_timeout(250)
 def is_open(self):return self.page.evaluate("(()=>{const d=document.getElementById('messenger-window');return !!(d&&d.open);})()")
 def open(self):
  p=self.page
  for sel in ('#messenger-open','#appbar-messenger'):
   loc=p.locator(sel)
   if loc.count() and loc.first.is_visible():loc.first.click();break
  else:
   raise AssertionError('no visible messenger opener')
  expect(p.locator('#messenger-window')).to_be_visible()
 def person(self,label):
  p=self.page
  if p.locator('#messenger-back').is_visible():p.locator('#messenger-back').click()
  p.locator('#message-people button').filter(has_text=label).click()
  expect(p.locator('#message-peer-name')).to_contain_text(label)
 def state(self,owner):
  raw_=self.page.evaluate('k=>sessionStorage.getItem(k)','qaredi-messenger:v1:'+owner)
  return json.loads(raw_) if raw_ else None
 def ls(self):return self.page.evaluate('Object.fromEntries(Object.keys(localStorage).map(k=>[k,localStorage.getItem(k)]))')
 def ss(self):return self.page.evaluate('Object.fromEntries(Object.keys(sessionStorage).map(k=>[k,sessionStorage.getItem(k)]))')
 def from_bottom(self):return self.page.evaluate("(()=>{const l=document.getElementById('message-list');return Math.round(l.scrollHeight-l.scrollTop-l.clientHeight);})()")
 def focus_outside(self):return self.page.evaluate("(()=>{const d=document.getElementById('messenger-window');return !d.contains(document.activeElement);})()")
 def clean(self,tag=''):
  ok=not self.errors and not self.bad and code_console(self.console)==[]
  if not ok:metrics['unclean-'+tag+'-'+str(len(metrics))]={'errors':[e[:200] for e in self.errors],'bad':[urlsplit(u).path for u in self.bad],'console':[c[:200] for c in code_console(self.console)]}
  return ok


MODAL_RULE=re.search(r'MODAL_RULE = r"""(.*?)"""',(root/'tests/browser/dbg_u2_ui.py').read_text(),re.S).group(1)
AUDIT_JS=re.search(r'AUDIT_JS = r"""(.*?)"""',(root/'scripts/ui_snapshot.py').read_text(),re.S).group(1)
TOUCH_JS="""(sel)=>{const out=[];for(const el of document.querySelectorAll(sel)){if(el.closest('[hidden]')||!el.getClientRects().length)continue;const cs=getComputedStyle(el);if(cs.display==='none'||cs.visibility==='hidden')continue;
 const r=el.getBoundingClientRect();const b=getComputedStyle(el,'::before');let w=r.width,h=r.height;
 if(b.content&&b.content!=='none'&&b.position==='absolute'){const px=v=>parseFloat(v)||0;w=r.width-Math.min(0,px(b.left))-Math.min(0,px(b.right));h=r.height-Math.min(0,px(b.top))-Math.min(0,px(b.bottom));}
 if(w<43.5||h<43.5)out.push((el.id||el.className||el.tagName)+' '+Math.round(w)+'x'+Math.round(h));}return out;}"""

failures_before=0
try:
 for u in users:
  users[u]['username']=sql('SELECT username FROM users WHERE id='+str(users[u]['id']))
  call('GET','/api/me/space',user=u)   # 개인 공간을 연다(페이지를 만들기 전에 — memories_http.py 와 같다)
 emp,oth,adm=uid('employee'),uid('other'),uid('admin')
 E=users['employee']['username'];O=users['other']['username']
 for name,display in (('synthetic_peer_a','합성 상대 A'),('synthetic_peer_b','합성 상대 B')):
  sql("INSERT INTO users(username,password_hash,role,display_name,expires_at) VALUES('"+name+"','!synthetic-no-login','Employee','"+display+"',clock_timestamp()+interval '14 days')")
 pa=int(sql("SELECT id FROM users WHERE username='synthetic_peer_a'"));pb=int(sql("SELECT id FROM users WHERE username='synthetic_peer_b'"))
 # 사용 안내가 화면을 덮지 않게(이 DB 의 모든 사람 — 이미 닫음) · AI 켬
 sql("INSERT INTO community.usage_guides(user_id,version,status,context) SELECT id,1,'dismissed','overview' FROM users ON CONFLICT DO NOTHING")
 sql("UPDATE member_ai_settings SET enabled=true,image_enabled=true")
 # 스크롤이 생길 만큼의 옛 대화(직원 ↔ 상대 A, 읽음)
 sql("INSERT INTO member_messages(sender_id,recipient_id,body,sent_at,read_at) SELECT CASE WHEN g%2=0 THEN "+str(emp)+" ELSE "+str(pa)+" END,CASE WHEN g%2=0 THEN "+str(pa)+" ELSE "+str(emp)+" END,'합성 옛 대화 '||g,now()-interval '1 day'+g*interval '1 minute',now() FROM generate_series(1,40) g")
 sql("UPDATE sessions SET reauth_at=now() WHERE user_id="+str(adm))
 # 커뮤니티 화면 셋에 쓸 것(직원의 페이지 · 관리자의 게시판 · 직원의 데이터베이스와 응답 양식)
 page_id=call('POST','/api/pages',{'title':'메신저 창 시험 페이지'},expected=201)['page']['id']
 board_id=call('POST','/api/boards',{'name':'메신저 창 시험 게시판','access_level':'internal','board_kind':'discussion'},user='admin',expected=201)['board']['id']
 dbp=call('POST','/api/pages',{'title':'메신저 창 시험 DB','database':True},expected=201)['page'];did=dbp['database']['id']
 form=call('PATCH','/api/databases/'+did+'/views',{'add':{'name':'시험 접수','type':'form','questions':[{'prop':'title','required':True}],'open':True,'audience':'internal','anonymous':False}})['views'][-1]
 post_id=call('POST','/api/memories',dict(payload(),caption='꾸미기 시험 기록 — 바다에서 본 노을'),expected=201)['id']
 acting=new_session('admin',act_role='Employee')
 A_PREFIX='qaredi-draft:v1:'+E+':';A_STATE='qaredi-messenger:v1:'+E

 # ─── Q:T-11 단추 · Q:T-19 대체(HTML·창구) ───────────────────────────────────────────────
 if want('t11'):
  screens=[('employee','/tbox/','t-box 홈'),('employee','/tbox/settings','설정'),('employee','/tbox/activity','활동'),('employee','/tbox/trash','내 휴지통'),
           ('admin','/tbox/wiki','위키'),('admin','/tbox/admin','관리 허브'),
           ('employee','/community/','업무 홈'),('employee','/community/workspace','내 문서'),('employee','/community/p/'+page_id,'페이지'),
           ('employee','/community/b/'+str(board_id),'게시판'),('employee','/community/f/'+did+'/'+form['id'],'응답 양식'),('employee','/community/procedures','절차서'),
           ('employee','/community/ai','문서 AI'),('employee','/community/integrations','공유·연동'),('employee','/community/memories','스레드'),
           ('employee','/tbox/welcome','시작 안내'),('employee','/','런처')]
  for who,path,label in screens:
   r=requests.get(site+path,headers={'User-Agent':UA,'Cookie':'__Host-tbox_session='+users[who]['session']},timeout=35,allow_redirects=False)
   html=r.text
   check(r.status_code==200,'Q:T-11 '+label+' 200 (real shell, '+who+')')
   check(html.count('id="messenger-open"')==1,'Q:T-11 '+label+': exactly one #messenger-open button')
   check(html.count('id="messenger-window"')==1 and html.count('class="floating"')==1,'Q:T-11 '+label+': one floating window markup')
   check(html.count('/assets/messenger.js')==1 and 'moa-local-bridge.js' not in html,'Q:T-11 '+label+': messenger module once, no PC helper bridge script')
   check('community-messenger' not in html and 'community/messenger.css' not in html and 'community/messenger.js' not in html,'Q:T-19 '+label+': old community messenger markup/assets 0')
   # 「사용 방법」 도움말 창(src/help/*.php — 이 지시서의 수정 파일 밖)은 따로 센다: 남은 문장은 보고 몫(metrics help-gpt-*)
   help_block=re.search(r'<dialog id="help-dialog".*?</dialog>',html,re.S)
   body_text=re.sub(r'<dialog id="help-dialog".*?</dialog>','',re.sub(r'<script[^>]*>|<link[^>]*>','',html),flags=re.S)
   gpt=[m.group(0) for m in re.finditer(r'.{0,40}(?:(?<!Chat)GPT|OpenAI).{0,20}',body_text)]
   if gpt:metrics['gpt-'+path]=gpt
   helpgpt=[m.group(0).strip() for m in re.finditer(r'.{0,40}(?:(?<!Chat)GPT|OpenAI).{0,20}',help_block.group(0) if help_block else '')]
   if helpgpt:metrics['help-gpt-'+path]=helpgpt
   if path=='/community/memories':
    hb=help_block.group(0) if help_block else ''
    check(not helpgpt and '떠 있는 창' in hb and 'Google로 전송' in hb and '크게 보기' in hb,'C-4 스레드 도움말: floating messenger + 모아 AI Google notice, no 「GPT」/「OpenAI」')
   check(not gpt,'Q:T-19 '+label+': no standalone 「GPT」/「OpenAI」 text on the screen (help dialog counted separately)')
  r=requests.get(TB+'/messages',headers={'User-Agent':UA,'Cookie':'__Host-tbox_session='+users['employee']['session']},timeout=35)
  check(r.status_code==200 and 'id="messenger-window"' not in r.text and 'id="messenger-open"' not in r.text and 'id="message-form"' in r.text,'Q:T-11 /messages is the page itself (no floating window, no opener)')
  check(r.headers.get('content-security-policy')=="default-src 'self'; connect-src 'self' http://127.0.0.1:43821; script-src 'self'; style-src 'self'; img-src 'self' data:; object-src 'none'; base-uri 'none'; frame-ancestors 'none'",'Q:T-21 /messages PHP CSP header unchanged (PC helper loopback only here)')
  r2=requests.get(TB+'/',headers={'User-Agent':UA,'Cookie':'__Host-tbox_session='+users['employee']['session']},timeout=35)
  check('43821' not in r2.headers.get('content-security-policy','') and '43821' not in r2.text,'Q:T-11 shell page has no loopback in CSP or markup')
  for who,label,token in (('guest','손님',users['guest']['session']),('admin','역할로 보기',acting)):
   for path in ('/tbox/','/tbox/settings','/community/','/community/memories','/'):
    r=requests.get(site+path,headers={'User-Agent':UA,'Cookie':'__Host-tbox_session='+token},timeout=35,allow_redirects=False)
    check(r.status_code in (200,302,303) and 'data-messenger-open' not in r.text and 'id="messenger-window"' not in r.text and '/assets/messenger.js' not in r.text,
          'Q:T-11 '+label+' '+path+': no opener, no window, no module script ('+str(r.status_code)+')')
  r=tb('POST','/api/messages/'+str(pa),{'text':'손님','request_id':str(uuid.uuid4())},user='guest')
  check(r.status_code==403,'Q:T-11 guest POST /api/messages/{n} still 403')
  r=requests.post(TB+'/api/messages/'+str(pa),json={'text':'보기','request_id':str(uuid.uuid4())},headers={'User-Agent':UA,'Cookie':'__Host-tbox_session='+acting},timeout=35)
  check(r.status_code==403,'Q:T-11 acting-as POST /api/messages/{n} still 403 (CSRF or messenger gate)')
  r=requests.get(site+'/',headers={'User-Agent':UA},timeout=35,allow_redirects=False)
  check(r.status_code==200 and 'messenger-window' not in r.text and '/assets/messenger.js' not in r.text and 'member-ai.js' not in r.text and 'data-messenger-open' not in r.text,'Q:T-11 signed-out launcher: no window markup, no messenger scripts')
  check(not [h for h in r.headers if h.lower()=='set-cookie'],'Q:T-11 signed-out launcher Set-Cookie 0')
  metrics['launcher_script_lines_signed_out']=sum(1 for l in r.text.splitlines() if '<script' in l)
  r=requests.get(site+'/',headers={'User-Agent':UA,'Cookie':'__Host-tbox_session='+users['admin']['session']},timeout=35,allow_redirects=False)
  check(r.status_code==200 and not [h for h in r.headers if h.lower()=='set-cookie'],'Q:T-11 signed-in launcher Set-Cookie 0 (session read only)')
  metrics['launcher_script_lines_signed_in']=sum(1 for l in r.text.splitlines() if '<script' in l)
  metrics['launcher_script_tags_signed_in']=r.text.count('<script')
  # Q:T-19 창구 — 사람 대화 6개 404 · 휴면 GPT 두 갈래는 전과 같다
  fake=str(uuid.uuid4())
  for method,path,body in (('GET','/api/messenger',None),('GET','/api/messenger/members?q=ab',None),('POST','/api/messenger/conversations',{'username':O}),
                           ('GET','/api/messenger/conversations/'+fake,None),('POST','/api/messenger/conversations/'+fake+'/messages',{'body':'x','request_id':str(uuid.uuid4())}),
                           ('POST','/api/messenger/conversations/'+fake+'/read',{'last_id':1})):
   r=raw(method,path,body)
   check(r.status_code==404,'Q:T-19 removed route '+method+' '+path.split('?')[0].replace(fake,'{id}')+' → 404')
  r=raw('GET','/api/messenger/gpt');j=r.json() if r.headers.get('content-type','').startswith('application/json') else {}
  metrics['gpt_get_status']=r.status_code
  check(r.status_code==200 and j.get('enabled') is False,'Q:T-19 dormant GET /community/api/messenger/gpt unchanged (200 enabled:false — no key)')
  r=raw('POST','/api/messenger/gpt',{'body':'휴면 확인','request_id':str(uuid.uuid4())})
  metrics['gpt_post_status']=r.status_code
  check(r.status_code==503,'Q:T-19 dormant POST /community/api/messenger/gpt → 503 (no key)')
  check(raw('GET','/api/messenger/gpt',user='guest').status_code==403,'Q:T-19 dormant GPT routes keep community.write (guest 403)')
  check(int(sql('SELECT count(*) FROM community.gpt_turns'))==0 and sql("SELECT to_regclass('community.conversations') IS NOT NULL AND to_regclass('community.messages') IS NOT NULL")=='t','Q:T-19 tables kept (conversations · messages · gpt_turns)')

 with sync_playwright() as pw:
  browser=pw.chromium.launch(executable_path=os.environ.get('CHROMIUM_EXECUTABLE'))

  # ─── Q:T-11 단추가 창을 연다(셸·시작 안내·런처·개요·스레드) · 화면당 /api/me 요청 수 · 첫 배지 요청 수 ───────────────
  if want('t11'):
   tok=new_session('employee')
   ctx=browser.new_context(viewport={'width':1440,'height':900},locale='ko-KR',user_agent=UA);ctx.add_cookies(cookie(tok))
   t=Tab(ctx);p=t.page
   for path,opener,label,me_max in (('/tbox/settings','#messenger-open','셸 사이드바',1),('/community/workspace','#messenger-open','커뮤니티 셸 사이드바',1),('/tbox/welcome','#messenger-open','시작 안내 nav',2),('/','#messenger-open','런처 타일',2),
                                    ('/community/','#messenger-open','업무 홈 사이드바(Q:F-12 D — 개요 카드 없어짐)',1),('/community/memories','.threads-quick [data-messenger-open]','스레드 단추',1)):
    mark=time.time();t.go(path);p.wait_for_timeout(1200)
    me=[r for r in t.requests if r[0]>=mark and r[2]=='/tbox/api/me'];badge=[r for r in t.requests if r[0]>=mark and r[2]=='/tbox/api/messages?q=']
    metrics['api_me-'+path]=len(me);metrics['first_badge-'+path]=len(badge)
    metrics['community_base-'+path]=p.evaluate("document.body.getAttribute('data-community-base')")   # B-7 2 — 없으면 messenger.js 가 '/community' 를 쓴다
    check(len(me)<=me_max,'Q:T-11 '+label+': /api/me requests on this screen '+str(len(me))+' (≤'+str(me_max)+' — one more at most, shell reuses app.js)')
    check(len(badge)==1 and all(r[3]=='1' for r in badge),'Q:T-11 '+label+': one background badge request on load')
    before=p.url;p.locator(opener).first.click();expect(p.locator('#messenger-window')).to_be_visible();p.wait_for_timeout(300)
    check(p.url==before and t.is_open() and p.locator(opener).first.get_attribute('aria-expanded')=='true','Q:T-11 '+label+': opener opens the floating window in place (no navigation, aria-expanded)')
    p.locator('#messenger-window [data-messenger-close].btn--icon').click();p.wait_for_timeout(200)
    check(not t.is_open(),'Q:T-11 '+label+': ✕ closes')
   check(t.clean('t11b'),'Q:T-11 no page errors / 5xx / script console output')
   ctx.close()

  # ─── Q:T-12 이동 복원 ───────────────────────────────────────────────────────────────
  if want('t12'):
   ctx=browser.new_context(viewport={'width':1440,'height':900},locale='ko-KR',user_agent=UA);ctx.add_cookies(cookie(users['employee']['session']))
   t=Tab(ctx);p=t.page
   t.go('/community/p/'+page_id)
   check(not t.is_open(),'Q:T-12 window starts closed in a fresh tab')
   t.open();check(p.evaluate("document.activeElement.id")=='message-search','Q:T-12 opened by a person: focus moves into the window (search box in list view)')
   t.person('합성 상대 A')
   p.wait_for_function("()=>!!document.activeElement&&document.activeElement.id==='message-text'",timeout=5000)   # 함수 꼴 — 글자 식은 eval 로 돌아 CSP(unsafe-eval 없음)에 막힌다
   check(p.evaluate("document.activeElement.id")=='message-text','Q:T-12 opening a conversation focuses the composer')
   p.locator('#message-text').type('ㄱㄴㄷ');p.wait_for_function('k=>localStorage.getItem(k)!==null',arg=A_PREFIX+'message:'+str(pa),timeout=8000)
   p.evaluate("(()=>{const l=document.getElementById('message-list');l.scrollTop=Math.max(0,l.scrollHeight-l.clientHeight-260);})()")
   p.wait_for_function("k=>{try{return JSON.parse(sessionStorage.getItem(k)).scroll.fromBottom>=200}catch(e){return false}}",arg=A_STATE,timeout=5000)
   saved=t.state(E);fb=saved['scroll']['fromBottom']
   check(saved['open'] is True and saved['kind']=='person' and saved['peer']==pa and 'ㄱㄴㄷ' not in json.dumps(saved,ensure_ascii=False),'Q:T-12 state = open · person · peer number · scroll (no text)')
   for path,label in (('/tbox/','자료실 홈'),('/tbox/settings','설정'),('/','런처')):
    t.go(path)
    expect(p.locator('#message-peer-name')).to_contain_text('합성 상대 A')
    p.wait_for_timeout(400)
    check(t.is_open(),'Q:T-12 '+label+': window restored open')
    check(p.locator('#message-text').input_value()=='ㄱㄴㄷ','Q:T-12 '+label+': draft 「ㄱㄴㄷ」 restored')
    now_fb=t.from_bottom()
    check(abs(now_fb-fb)<=6 and now_fb>=200,'Q:T-12 '+label+': scroll position (not bottom) restored ('+str(now_fb)+'/'+str(fb)+')')
    check(t.focus_outside(),'Q:T-12 '+label+': restore did not move focus into the window')
    if path=='/tbox/':p.screenshot(path=str(shots/'t12-tbox-home-1440.png'))
   # AI 스레드로 바꾼 뒤 이동
   p.locator('#messenger-back').click();p.locator('#message-ai').click();expect(p.locator('#message-peer-name')).to_have_text('모아 AI');expect(p.locator('#message-text')).to_be_enabled()
   p.locator('#message-text').type('AI 이어 열기 질문');p.locator('#message-send').click();expect(p.locator('#message-list')).to_contain_text('합성 꾸미기 답변입니다.')
   thread=t.state(E)['thread'];check(t.state(E)['kind']=='ai' and isinstance(thread,int),'Q:T-12 AI thread number saved')
   p.locator('#message-text').type('AI 초안');p.wait_for_function('k=>localStorage.getItem(k)!==null',arg=A_PREFIX+'ai:'+str(thread),timeout=8000)
   for path,label in (('/community/','업무 홈'),('/tbox/settings','설정')):
    t.go(path);expect(p.locator('#message-peer-name')).to_have_text('모아 AI');expect(p.locator('#message-list')).to_contain_text('AI 이어 열기 질문')
    check(t.is_open() and t.state(E)['thread']==thread and p.locator('#message-ai-threads').input_value()==str(thread),'Q:T-12 '+label+': same AI thread restored')
    check(p.locator('#message-text').input_value()=='AI 초안' and t.focus_outside(),'Q:T-12 '+label+': AI draft restored, focus untouched')
   # 닫으면 다음 화면에서 닫힌 채 · 다시 열면 같은 자리
   p.locator('#message-text').focus();p.keyboard.press('Escape');p.wait_for_timeout(200)
   check(not t.is_open() and t.state(E)['open'] is False,'Q:T-12 Esc (focus inside) closes; state open:false kept')
   t.go('/tbox/');check(not t.is_open(),'Q:T-12 closed window stays closed on the next screen')
   t.open();expect(p.locator('#message-peer-name')).to_have_text('모아 AI')
   expect(p.locator('#message-text')).to_have_value('AI 초안',timeout=8000)   # 제목은 열 때 바로, 입력 칸은 그 스레드를 읽은 뒤에 채워진다
   check(p.locator('#message-text').input_value()=='AI 초안' and t.state(E)['thread']==thread,'Q:T-12 reopening returns to the same AI thread and draft')
   check(t.clean('t12'),'Q:T-12 no page errors / 5xx / script console output')
   ctx.close()
   # 보정 D-1(묶음 검토 ①) — 이어 열 상대가 이제 없으면(404) 그 번호를 잊는다: 화면을 옮겨도 같은 404 를 다시 치지 않는다
   ghost=int(sql('SELECT coalesce(max(id),0)+100000 FROM users'));ghost_path='/tbox/api/messages/'+str(ghost)
   check(tb('GET','/api/messages/'+str(ghost)).status_code==404,'D-1 the stored peer number answers 404 (no such member)')
   ctx=browser.new_context(viewport={'width':1440,'height':900},locale='ko-KR',user_agent=UA);ctx.add_cookies(cookie(new_session('employee')))
   t=Tab(ctx);p=t.page
   p.goto(TB+'/assets/moa.css',wait_until='load')   # 모듈이 없는 같은 출처 주소에서 넣는다(모듈 화면이면 떠날 때 pagehide 가 덮어쓴다)
   p.evaluate('([k,v])=>sessionStorage.setItem(k,v)',[A_STATE,json.dumps({'v':1,'open':True,'kind':'person','peer':ghost,'thread':None,'scroll':{'fromBottom':0},'at':int(time.time()*1000)})])
   def ghost_gets():return len([r for r in t.requests if r[1]=='GET' and r[2]==ghost_path])
   def composer():return p.evaluate("(()=>{const d=document.getElementById('messenger-window'),x=document.getElementById('message-text');return {open:d.open,view:d.getAttribute('data-view'),disabled:x.disabled,value:x.value,send:document.getElementById('message-send').disabled,head:document.getElementById('message-peer-name').textContent,toolbar:document.getElementById('message-assist-toolbar').hidden};})()")
   t.go('/tbox/')
   p.wait_for_function("()=>{const d=document.getElementById('messenger-window');return !!d&&d.open&&d.getAttribute('data-view')==='list'&&document.querySelectorAll('#message-people button').length>0;}",timeout=8000)
   v=composer();st=t.state(E);metrics['d1_restore']=dict(v,gets=ghost_gets(),state=st)
   check(ghost_gets()==1,'D-1 자료실 홈: one GET to the missing peer ('+str(ghost_gets())+')')
   check(v['open'] and v['view']=='list','D-1 자료실 홈: window open on the people list')
   check(v['disabled'] and v['send'] and v['value']=='' and v['toolbar'],'D-1 자료실 홈: composer disabled and empty, send disabled, assist toolbar hidden')
   check('불러오는 중' not in v['head'],'D-1 자료실 홈: conversation head no longer 「불러오는 중…」 ('+v['head']+')')
   check(st is None or st.get('peer')!=ghost,'D-1 자료실 홈: state does not carry the missing peer number')
   for path,label in (('/tbox/settings','설정'),('/community/','업무 홈')):
    t.go(path);p.wait_for_timeout(600)
    st=t.state(E);v=composer();metrics['d1'+path.replace('/','_')]=dict(v,gets=ghost_gets(),state=st)
    check(ghost_gets()==1,'D-1 '+label+': no second 404 GET to that peer (total '+str(ghost_gets())+')')
    check(st is None or st.get('peer')!=ghost,'D-1 '+label+': state does not carry the missing peer number')
    check(v['disabled'],'D-1 '+label+': composer disabled')
   check(t.clean('d1') and resource_lines(t.console)<={('404',ghost_path)}|CONSOLE_OK,'D-1 console 0 (script) — only the one expected 404 resource line, no page errors / 5xx')
   ctx.close()

  # ─── Q:T-13 여러 탭 ────────────────────────────────────────────────────────────────
  if want('t13'):
   tok=new_session('employee');sql("DELETE FROM member_messages WHERE body LIKE '다중%'")
   ctx=browser.new_context(viewport={'width':1440,'height':900},locale='ko-KR',user_agent=UA);ctx.add_cookies(cookie(tok))
   t1=Tab(ctx);t1.go('/tbox/');t1.open();t1.person('합성 상대 B')
   t1.page.locator('#message-text').type('다중 탭 글');t1.page.wait_for_function('k=>localStorage.getItem(k)!==null',arg=A_PREFIX+'message:'+str(pb),timeout=8000)
   t2=Tab(ctx);t2.go('/tbox/settings');t2.open();t2.person('합성 상대 B')
   check(t2.page.locator('#message-text').input_value()=='다중 탭 글','Q:T-13 tab 2 sees the shared (encrypted) draft')
   t1.page.locator('#message-send').click();expect(t1.page.locator('#message-list')).to_contain_text('다중 탭 글')
   t0=time.time()
   expect(t2.page.locator('#message-text')).to_have_value('',timeout=9000)
   expect(t2.page.locator('#message-list')).to_contain_text('다중 탭 글',timeout=9000)
   metrics['t13_other_tab_seconds']=round(time.time()-t0,2)
   check(metrics['t13_other_tab_seconds']<=8.5,'Q:T-13 tab 2 draft cleared and message listed within 8 s ('+str(metrics['t13_other_tab_seconds'])+' s)')
   check(int(sql("SELECT count(*) FROM member_messages WHERE body='다중 탭 글'"))==1,'Q:T-13 one row in DB')
   # 같은 UUID 동시 전송 — 탭 1 의 전송이 네트워크에서 끊겨(결과 불확실) 같은 request_id 를 가진 초안이 남고, 탭 2 가 그 초안을 이어받아 둘이 동시에 보낸다
   t1.page.route('**/tbox/api/messages/'+str(pb),lambda route:route.abort() if route.request.method=='POST' else route.continue_())
   t1.page.locator('#message-text').fill('다중 동시 전송');t1.page.locator('#message-send').click();t1.page.wait_for_timeout(1600)
   check(int(sql("SELECT count(*) FROM member_messages WHERE body='다중 동시 전송'"))==0,'Q:T-13 aborted send created no row')
   t1.page.unroute('**/tbox/api/messages/'+str(pb))
   t2.go('/tbox/settings')
   expect(t2.page.locator('#message-peer-name')).to_contain_text('합성 상대 B');expect(t2.page.locator('#message-text')).to_have_value('다중 동시 전송')
   expect(t1.page.locator('#message-send')).to_be_enabled();expect(t2.page.locator('#message-send')).to_be_enabled()
   t1.page.evaluate("document.getElementById('message-form').requestSubmit()");t2.page.evaluate("document.getElementById('message-form').requestSubmit()")
   t1.page.wait_for_timeout(2500)
   rows=int(sql("SELECT count(*) FROM member_messages WHERE body='다중 동시 전송'"));reqs=int(sql("SELECT count(DISTINCT request_id) FROM member_messages WHERE body='다중 동시 전송'"))
   check(rows==1 and reqs==1,'Q:T-13 two tabs sending the same UUID at once → one row ('+str(rows)+')')
   # 배지 — 둘 다 창을 닫고, 상대 A 가 보낸 새 글 하나 → 대표 탭이 읽어 다른 탭에도 같은 숫자(≤ 30초 + 여유)
   for t in (t1,t2):t.page.locator('#messenger-window [data-messenger-close].btn--icon').click()
   sql("INSERT INTO member_messages(sender_id,recipient_id,body) VALUES("+str(pa)+","+str(emp)+",'다중 배지 확인')")
   mark=time.time()
   for t in (t1,t2):expect(t.page.locator('#messenger-open [data-message-unread]')).to_have_text('1',timeout=40000)
   b1=t1.page.locator('#messenger-open [data-message-unread]').inner_text();b2=t2.page.locator('#messenger-open [data-message-unread]').inner_text()
   check(b1==b2=='1','Q:T-13 unread badge equal in both tabs ('+b1+'/'+b2+')')
   def polls(t,since):return [r for r in t.requests if r[0]>=since and r[1]=='GET' and r[2]=='/tbox/api/messages?q=']
   t1.page.wait_for_timeout(32000)
   p1,p2=polls(t1,mark+1),polls(t2,mark+1)
   leader,follower=(t1,t2) if len(p1)>=len(p2) else (t2,t1)
   metrics['t13_poll_counts_after_close']=[len(p1),len(p2)]
   check((len(p1)>=1)!=(len(p2)>=1),'Q:T-13 only one tab (the navigator.locks leader) polls the closed-window badge '+str(metrics['t13_poll_counts_after_close']))
   path=leader.page.evaluate("(async()=>{const q=await navigator.locks.query();return {held:q.held.length,pending:q.pending.length};})()")
   check(path['held']==1,'Q:T-13 one held lock qaredi-messenger:<id> (Web Locks path) '+json.dumps(path))
   leader.page.close();gone=time.time()
   follower.page.wait_for_timeout(33000)
   took=polls(follower,gone)
   metrics['t13_follower_polls_after_leader_closed']=len(took)
   check(len(took)>=1 and all(r[3]=='1' for r in took),'Q:T-13 after closing the leader tab the other tab takes over polling ('+str(len(took))+' background requests)')
   check(follower.clean('t13'),'Q:T-13 no page errors / 5xx / script console output')
   ctx.close()

  # ─── Q:T-14·15 로그아웃 · 계정 바뀜 ──────────────────────────────────────────────────
  if want('t14'):
   tok=new_session('employee')
   ctx=browser.new_context(viewport={'width':1440,'height':900},locale='ko-KR',user_agent=UA);ctx.add_cookies(cookie(tok))
   t=Tab(ctx);p=t.page
   t.go('/tbox/');t.open();t.person('합성 상대 A');p.locator('#message-text').fill('');p.locator('#message-text').type('로그아웃 전 초안')
   p.wait_for_function('k=>localStorage.getItem(k)!==null',arg=A_PREFIX+'message:'+str(pa),timeout=8000)
   check(A_STATE in t.ss(),'Q:T-14 precondition: state and draft present')
   p.locator('details.appbar__apps > summary').click();p.locator('#logout').click();p.wait_for_url('**/login**',timeout=15000)
   check(not [k for k in t.ls() if k.startswith(A_PREFIX)] and not [k for k in t.ss() if k.startswith('qaredi-messenger:')],'Q:T-14 shell logout: this person draft prefix 0, messenger state 0')
   check(p.locator('#messenger-window').count()==0,'Q:T-14 login screen has no window')
   # Q:T-15 — A 의 초안·상태를 남긴 채(세션 만료 흉내: 쿠키만 바꿈) 같은 탭에서 B 로
   tok=new_session('employee');ctx.clear_cookies();ctx.add_cookies(cookie(tok))
   t.go('/tbox/');t.open();t.person('합성 상대 A');p.locator('#message-text').type('A 의 비밀 초안')
   p.wait_for_function('k=>localStorage.getItem(k)!==null',arg=A_PREFIX+'message:'+str(pa),timeout=8000)
   ctx.clear_cookies();ctx.add_cookies(cookie(new_session('other')))
   t.go('/tbox/')
   check(not t.is_open(),'Q:T-15 B starts with the window closed')
   check(A_STATE not in t.ss() and not [k for k in t.ls() if k.startswith(A_PREFIX+'message:') or k.startswith(A_PREFIX+'ai:')],'Q:T-15 A messenger state and drafts removed when B mounts')
   t.open();p.wait_for_timeout(500)
   check('A 의 비밀 초안' not in p.content(),'Q:T-15 B never sees A draft')
   check(t.clean('t14'),'Q:T-14/15 no page errors / 5xx / script console output')
   ctx.close()

  # ─── Q:T-16 폭 · 테마 · 고대비 ─────────────────────────────────────────────────────────
  if want('t16'):
   tok=new_session('employee')
   combos=[(390,False,'light'),(390,False,'dark'),(412,True,'light'),(412,True,'dark'),(412,False,'light'),(820,False,'light'),(820,False,'dark'),(1440,False,'light'),(1440,False,'dark')]
   for contrast in (False,True):
    sql("UPDATE users SET high_contrast="+('true' if contrast else 'false')+" WHERE id="+str(emp))
    for width,touch,theme in (combos if not contrast else [(390,False,'light'),(1440,False,'dark')]):
     tag=str(width)+('-touch' if touch else '')+'-'+theme+('-hc' if contrast else '')
     ctx=browser.new_context(viewport={'width':width,'height':844 if width<720 else 900},locale='ko-KR',user_agent=UA,color_scheme=theme,has_touch=touch,is_mobile=touch)
     ctx.add_cookies(cookie(tok));ctx.add_cookies([{'name':'tbox_theme','value':theme,'domain':host,'path':'/'}])
     t=Tab(ctx);p=t.page
     for path in ('/tbox/','/community/'):
      t.go(path);t.open()
      if width<720:
       t.person('합성 상대 A')
      geo=p.evaluate("""(()=>{const d=document.getElementById('messenger-window').getBoundingClientRect();const g=document.querySelector('.usage-guide-launch');const gr=g&&g.getClientRects().length?g.getBoundingClientRect():null;
        const z=getComputedStyle(document.getElementById('messenger-window')).zIndex;return {left:d.left,right:d.right,top:d.top,bottom:d.bottom,width:d.width,height:d.height,vw:innerWidth,vh:innerHeight,z:z,
        guide:gr?{left:gr.left,right:gr.right,top:gr.top,bottom:gr.bottom}:null,over:document.documentElement.scrollWidth-document.documentElement.clientWidth};})()""")
      metrics['geo-'+tag+path.replace('/','_')]=geo
      check(geo['over']<=0,'Q:T-16 '+tag+' '+path+': no horizontal overflow')
      if width<720:
       check(abs(geo['bottom']-geo['vh'])<=1 and abs(geo['left'])<=0.5 and abs(geo['width']-geo['vw'])<=1 and abs(geo['height']-0.88*geo['vh'])<=2,'Q:T-16 '+tag+' '+path+': bottom sheet (left 0, full width, 88dvh, touching bottom)')
       touch_bad=p.evaluate(TOUCH_JS,'#messenger-window button, #messenger-window a.btn, #messenger-window summary, #appbar-messenger')
       check(touch_bad==[],'Q:T-16 '+tag+' '+path+': every visible button in the sheet has a 44px tap area '+json.dumps(touch_bad,ensure_ascii=False))
       closes=p.evaluate("[...document.querySelectorAll('#messenger-window [data-messenger-close]')].filter(b=>b.getClientRects().length).map(b=>b.textContent.trim()||b.getAttribute('aria-label'))")
       check(sorted(closes)==sorted(['닫기','닫기']),'Q:T-16 '+tag+' '+path+': sheet has both ✕ (head) and 「닫기」 (bottom)')
       if path=='/tbox/' and not contrast:
        # 시트가 열린 채 ☰(서랍)를 열면 메뉴가 시트 위에 — 로그아웃까지 누를 수 있다
        p.locator('#appbar-apps').click();p.wait_for_timeout(200)
        if not p.locator('#logout').is_visible():p.locator('details.appbar__apps > summary').click()
        p.locator('#logout').scroll_into_view_if_needed();p.wait_for_timeout(200)
        hit=p.evaluate("(()=>{const b=document.getElementById('logout').getBoundingClientRect();const e=document.elementFromPoint(b.left+b.width/2,b.top+b.height/2);const d=document.getElementById('messenger-window');return !!(e&&e.closest('#logout'))&&d.open&&!d.getClientRects().length;})()")
        check(hit,'Q:T-16 '+tag+': opening the drawer menu folds the sheet (logout hit-testable, window state still open)')
        p.locator('#appbar-apps').click();p.wait_for_timeout(300)
        if p.locator('#sidebar').is_visible():p.locator('#nav-toggle').click();p.wait_for_timeout(300)
        check(p.locator('#messenger-window').is_visible() and t.is_open(),'Q:T-16 '+tag+': closing the drawer shows the sheet again')
      else:
       g=geo['guide']
       overlap=0 if g is None else max(0,min(geo['right'],g['right'])-max(geo['left'],g['left']))*max(0,min(geo['bottom'],g['bottom'])-max(geo['top'],g['top']))
       check(g is not None and overlap==0 and geo['bottom']<=g['top'] and abs(geo['vw']-geo['right']-16)<=1 and abs(geo['width']-400)<=1,
             'Q:T-16 '+tag+' '+path+': 400px window at right 16 above the usage-guide button, overlap 0')
       closes=p.evaluate("[...document.querySelectorAll('#messenger-window [data-messenger-close]')].filter(b=>b.getClientRects().length).length")
       check(closes==1,'Q:T-16 '+tag+' '+path+': wide window shows only the head ✕')
      check(geo['z']=='60','Q:T-16 '+tag+' '+path+': z-index from --z-floating (60)')
      if contrast:
       check(p.evaluate("document.documentElement.dataset.contrast")=='high','Q:T-16 '+tag+': high contrast attribute on')
      p.screenshot(path=str(shots/('t16-'+tag+path.replace('/','_')+'.png')))
     check(t.clean('t16-'+tag) and resource_lines(t.console)<=CONSOLE_OK,'Q:T-16 '+tag+': console 0 (script), no page errors / 5xx')
     ctx.close()
   sql("UPDATE users SET high_contrast=false WHERE id="+str(emp))

  # ─── Q:T-17 비모달 · 겹침 ──────────────────────────────────────────────────────────────
  if want('t17'):
   tok=new_session('admin')
   ctx=browser.new_context(viewport={'width':1440,'height':900},locale='ko-KR',user_agent=UA);ctx.add_cookies(cookie(tok))
   t=Tab(ctx);p=t.page
   t.go('/tbox/');t.open();t.person('합성 상대 A')
   # 뒤 화면: 폴더 링크 → 파일 표 · 정렬 바꾸기 · 스크롤 · 창 밖 클릭
   p.locator('.foldertree__item').filter(has_text='synthetic-a').first.click()
   expect(p.locator('#files-body tr').first).to_be_visible(timeout=10000)
   check(t.is_open() and p.locator('#files-body tr').count()>=1,'Q:T-17 opening a folder behind (file table filled) keeps the window open')
   mark=time.time();p.locator('#search-sort').select_option('name');p.locator('#search-form button[type=submit]').click();p.wait_for_timeout(800)
   check(t.is_open() and any('sort=name' in r[2] for r in t.requests if r[0]>=mark),'Q:T-17 sorting the file table behind (sort=name request) keeps the window open')
   # 창 밖의 누를 것 없는 자리(화면 제목)를 누른다 — 고정 좌표(200,450)는 C-6 으로 사이드바 줄이 하나 줄자 내비 링크에 닿아 화면을 옮겼다
   metrics['t17_point_200_450']=p.evaluate("(()=>{const e=document.elementFromPoint(200,450);const a=e&&e.closest('a,button');return e?(a?a.tagName+' '+a.textContent.replace(/\\s+/g,' ').trim().slice(0,20):e.tagName):null;})()")
   url0=p.url;outside=p.locator('main h1').first;metrics['t17_outside_click']=outside.evaluate("e=>e.tagName+' '+e.textContent.trim().slice(0,20)")
   outside.click();p.wait_for_timeout(200)
   check(t.is_open() and p.url==url0,'Q:T-17 clicking outside the window (page title) does not close it')
   p.evaluate("window.scrollTo(0,0)");p.mouse.wheel(0,400);p.wait_for_timeout(300)
   check(t.is_open(),'Q:T-17 page scroll keeps the window open')
   p.locator('#main').focus() if p.locator('#main').count() else None
   p.evaluate("document.activeElement&&document.activeElement.blur()");p.keyboard.press('Escape');p.wait_for_timeout(200)
   check(t.is_open(),'Q:T-17 Esc with focus outside the window does nothing')
   # 확인 창(최상위 층)이 위에
   p.evaluate("window.qarediOpenConfirm({title:'시험 확인',lead:'떠 있는 창 위에 뜨는지',okLabel:'확인'})")
   top=p.evaluate("""(()=>{const c=document.getElementById('confirm-dialog')||[...document.querySelectorAll('dialog[open]')].find(d=>d.id!=='messenger-window');const w=document.getElementById('messenger-window').getBoundingClientRect();
      const r=c.getBoundingClientRect();const x=Math.max(r.left,w.left)+5,y=Math.max(r.top,w.top)+5;const e=document.elementFromPoint(x,y);return {modal:c.matches(':modal'),top:!!(e&&c.contains(e)),window:document.getElementById('messenger-window').open};})()""")
   check(top['modal'] and top['top'] and top['window'],'Q:T-17 confirm dialog (showModal) sits above the floating window '+json.dumps(top))
   p.keyboard.press('Escape');p.wait_for_timeout(200)
   check(t.is_open(),'Q:T-17 closing the confirm dialog leaves the floating window open')
   # AI 도구 창(모달)이 위에
   p.locator('#message-assist').click();expect(p.locator('#message-assist-dialog')).to_be_visible()
   top=p.evaluate("""(()=>{const c=document.getElementById('message-assist-dialog');const w=document.getElementById('messenger-window').getBoundingClientRect();const r=c.getBoundingClientRect();
      const x=Math.max(r.left,w.left)+5,y=Math.max(r.top,w.top)+5;const e=document.elementFromPoint(x,y);return {modal:c.matches(':modal'),top:!!(e&&c.contains(e))};})()""")
   check(top['modal'] and top['top'],'Q:T-17 AI tool dialog sits above the floating window')
   p.locator('[data-assist-provider="local"]').click()
   note=p.locator('#assist-local p.moa-muted').filter(has_text='PC 비서 연결은 메신저 전체 화면에서 할 수 있습니다.')
   check(note.is_visible() and note.locator('a').get_attribute('href')=='/tbox/messages' and not p.locator('#assist-local-text-steps').is_visible(),'Q:T-17 window: 「내 PC의 ChatGPT」 shows the full-screen note + /messages link, no pairing controls')
   metrics['pc_helper_note']=note.inner_text()
   p.locator('#assist-close').click()
   check(not any('43821' in r[4] for r in t.requests),'Q:T-17 no request to the PC helper loopback from the window')
   # 재인증 창 — 관리자 페이지로 가는 링크(재인증 15분 창이 닫힌 세션)
   sql("UPDATE sessions SET reauth_at=NULL WHERE id='"+session_hash(tok)+"'")
   t.go('/tbox/');expect(p.locator('#messenger-window')).to_be_visible()
   adminlink=p.locator('#nav-admin')
   if adminlink.count() and adminlink.is_visible():
    adminlink.click();p.wait_for_timeout(600)
    top=p.evaluate("""(()=>{const c=[...document.querySelectorAll('dialog[open]')].find(d=>d.id!=='messenger-window'&&d.matches(':modal'));if(!c)return {found:false};const w=document.getElementById('messenger-window').getBoundingClientRect();
       const r=c.getBoundingClientRect();const x=Math.max(r.left,w.left)+5,y=Math.max(r.top,w.top)+5;const e=document.elementFromPoint(x,y);return {found:true,reauth:!!c.querySelector('input[type=password]'),top:!!(e&&c.contains(e))};})()""")
    check(top.get('found') and top.get('reauth') and top.get('top'),'Q:T-17 reauth dialog (/tbox/admin) sits above the floating window '+json.dumps(top))
    p.keyboard.press('Escape')
   else:
    check(False,'Q:T-17 admin nav link visible for the reauth check')
   # 창 바닥 규칙(dbg_u2_ui.py 의 MODAL_RULE 글자 그대로) — 떠 있는 창
   rule=p.evaluate(MODAL_RULE,False)
   mine=[b for b in rule['bad'] if b[0]=='messenger-window']
   metrics['modal_rule_bad_all']=rule['bad']
   check(mine==[],'Q:T-22 dbg_u2 MODAL_RULE: floating window footer/head rule passes '+json.dumps(mine,ensure_ascii=False))
   check(t.clean('t17'),'Q:T-17 no page errors / 5xx / script console output')
   ctx.close()

  # ─── Q:T-18 폴링 ─────────────────────────────────────────────────────────────────────
  if want('t18'):
   tok=new_session('employee')
   ctx=browser.new_context(viewport={'width':1440,'height':900},locale='ko-KR',user_agent=UA);ctx.add_cookies(cookie(tok))
   t=Tab(ctx);p=t.page
   t.go('/tbox/settings');p.wait_for_timeout(1500)
   sql("UPDATE sessions SET last_seen_at=now()-interval '10 minutes' WHERE id='"+session_hash(tok)+"'")
   seen=sql("SELECT last_seen_at FROM sessions WHERE id='"+session_hash(tok)+"'")
   start=time.time();p.wait_for_timeout(65000)
   rs=[r for r in t.requests if r[0]>=start]
   polls=[r for r in rs if r[2]=='/tbox/api/messages?q=']
   gaps=[round(b[0]-a[0],1) for a,b in zip(polls,polls[1:])]
   metrics['t18_closed']={'requests':len(rs),'polls':len(polls),'gaps':gaps}
   check(len(polls) in (2,3) and all(28<=g<=33 for g in gaps) and all(r[3]=='1' for r in polls),'Q:T-18 closed: badge poll every 30 s with X-Qaredi-Background '+json.dumps(metrics['t18_closed']))
   check(len(rs)==len(polls),'Q:T-18 closed: no other requests in 65 s')
   check(sql("SELECT last_seen_at FROM sessions WHERE id='"+session_hash(tok)+"'")==seen,'Q:T-18 sessions.last_seen_at unchanged by automatic requests (closed)')
   t.open();t.person('합성 상대 A');p.wait_for_timeout(1500)
   sql("UPDATE sessions SET last_seen_at=now()-interval '10 minutes' WHERE id='"+session_hash(tok)+"'")
   seen=sql("SELECT last_seen_at FROM sessions WHERE id='"+session_hash(tok)+"'")
   start=time.time();p.wait_for_timeout(33000)
   rs=[r for r in t.requests if r[0]>=start]
   conv=[r for r in rs if r[1]=='GET' and r[2]=='/tbox/api/messages/'+str(pa)];ppl=[r for r in rs if r[2]=='/tbox/api/messages?q=']
   gaps=[round(b[0]-a[0],1) for a,b in zip(conv,conv[1:])]
   metrics['t18_open']={'requests':len(rs),'conversation':len(conv),'people':len(ppl),'gaps':gaps}
   check(len(conv) in (4,5) and all(7<=g<=10 for g in gaps) and len(ppl)>=4,'Q:T-18 open: conversation + people every 8 s '+json.dumps(metrics['t18_open']))
   check(all(r[3]=='1' for r in rs if r[2].startswith('/tbox/api/messages')),'Q:T-18 open: every automatic messenger request carries X-Qaredi-Background: 1')
   check(sql("SELECT last_seen_at FROM sessions WHERE id='"+session_hash(tok)+"'")==seen,'Q:T-18 sessions.last_seen_at unchanged by automatic requests (open)')
   p.evaluate("""(()=>{Object.defineProperty(document,'hidden',{configurable:true,get:()=>true});Object.defineProperty(document,'visibilityState',{configurable:true,get:()=>'hidden'});document.dispatchEvent(new Event('visibilitychange'));})()""")
   start=time.time();p.wait_for_timeout(20000)
   hidden=[r for r in t.requests if r[0]>=start and '/api/' in r[2]]
   metrics['t18_hidden_requests']=len(hidden)
   check(hidden==[],'Q:T-18 hidden tab: 0 requests in 20 s')
   p.evaluate("""(()=>{Object.defineProperty(document,'hidden',{configurable:true,get:()=>false});Object.defineProperty(document,'visibilityState',{configurable:true,get:()=>'visible'});document.dispatchEvent(new Event('visibilitychange'));})()""")
   start=time.time();p.wait_for_timeout(2500)
   back=[r for r in t.requests if r[0]>=start and r[2].startswith('/tbox/api/messages')]
   check(len(back)>=1,'Q:T-18 visible again: polls at once ('+str(len(back))+' requests in 2.5 s)')
   check(t.clean('t18'),'Q:T-18 no page errors / 5xx / script console output')
   ctx.close()

  # ─── Q:T-20 꾸미기 → 모아 AI ──────────────────────────────────────────────────────────
  if want('t20'):
   tok=new_session('employee')
   ctx=browser.new_context(viewport={'width':1440,'height':900},locale='ko-KR',user_agent=UA);ctx.add_cookies(cookie(tok))
   t=Tab(ctx);p=t.page
   t.go('/community/memories?view=mine')
   p.locator('button').filter(has_text='AI로 꾸미기').first.click();expect(p.locator('#studio-dialog')).to_be_visible()
   p.locator('#studio-external > summary').click();expect(p.locator('#studio-chat')).to_be_visible()
   metrics['studio_button']=p.locator('#studio-chat').inner_text()
   metrics['studio_hint']=p.locator('#studio-external .field-hint').filter(has_text='모아 AI').inner_text()
   check(metrics['studio_button']=='모아 AI 에게 묻기','Q:T-20 studio button text 「모아 AI 에게 묻기」')
   prompt=p.locator('#studio-prompt').input_value()
   posts_before=[r for r in t.requests if r[1]=='POST' and r[2]=='/tbox/api/messages/ai']
   p.locator('#studio-chat').click()
   expect(p.locator('#messenger-window')).to_be_visible();expect(p.locator('#message-peer-name')).to_have_text('모아 AI')
   expect(p.locator('#message-text')).to_have_value(prompt)
   p.wait_for_timeout(800)
   check(not p.locator('#studio-dialog').is_visible() and p.evaluate("document.activeElement.id")=='message-text','Q:T-20 window opened on 모아 AI with the studio prompt in the composer (focus there)')
   check([r for r in t.requests if r[1]=='POST' and r[2]=='/tbox/api/messages/ai']==posts_before,'Q:T-20 nothing sent automatically')
   # 이 사람의 앞선 AI 대화(같은 합성 답 글자)가 이어 열릴 수 있다 — 개수는 보내기 전 수에서 센다
   answers=p.locator('#message-list .moa-bubble--ai').filter(has_text='합성 꾸미기 답변입니다.');n0=answers.count()
   p.locator('#message-send').click();expect(answers).to_have_count(n0+1,timeout=20000)
   back=p.locator('#message-list button').filter(has_text='꾸미기 화면으로')
   expect(back).to_have_count(1,timeout=5000)
   check(back.count()==1 and answers.last.locator('button').filter(has_text='꾸미기 화면으로').count()==1,'Q:T-20 the studio answer (newest) has one 「꾸미기 화면으로」 button; earlier answers none')
   # 다른 질문(꾸미기에서 오지 않은 것)의 답에는 단추 없음
   p.locator('#message-text').fill('그냥 질문');p.locator('#message-send').click();expect(answers).to_have_count(n0+2,timeout=20000)
   p.wait_for_timeout(500)
   check(back.count()==1 and answers.last.locator('button').count()==0,'Q:T-20 a later ordinary answer gets no button')
   back.first.click()
   expect(p.locator('#studio-dialog')).to_be_visible()
   check(p.locator('#studio-result').input_value()=='합성 꾸미기 답변입니다.' and not t.is_open(),'Q:T-20 「꾸미기 화면으로」 → #studio-result filled, studio reopened, window closed')
   check(t.clean('t20'),'Q:T-20 no page errors / 5xx / script console output')
   ctx.close()

  # ─── 지시서 C-3 사용 안내 — 떠 있는 창 안내 · 떠 있는 창(비모달)은 자동 제안·화면 안내·이어 보기를 막지 않는다 ─────────────
  if want('c3'):
   src_guide=(root/'public/assets/usage-guide.js').read_text()
   check(not re.search(r'(?<!Chat)GPT|OpenAI',src_guide) and 'community-messenger' not in src_guide and "['messenger-window','messenger']" in src_guide,
         'C-3 usage-guide.js: 「GPT」「OpenAI」 0 · old #community-messenger 0 · #messenger-window → messenger tour')
   mgr=uid('manager');M=users['manager']['username'];tok=new_session('manager')
   def guide(status,context='overview',step=0):
    sql('DELETE FROM community.usage_guides WHERE user_id='+str(mgr))
    if status:sql("INSERT INTO community.usage_guides(user_id,version,status,context,step) VALUES("+str(mgr)+",1,'"+status+"','"+context+"',"+str(step)+")")
   def guide_row():return sql("SELECT coalesce(max(status||':'||context||':'||step),'') FROM community.usage_guides WHERE user_id="+str(mgr))
   def sent():return int(sql('SELECT count(*) FROM member_messages WHERE sender_id='+str(mgr)))
   OUTLINE_IN_WINDOW="""(()=>{const o=document.getElementById('usage-guide-outline');const w=document.getElementById('messenger-window').getBoundingClientRect();
     const x=+o.getAttribute('x'),y=+o.getAttribute('y'),wd=+o.getAttribute('width'),h=+o.getAttribute('height');
     return wd>0&&h>0&&x>=w.left-7&&y>=w.top-7&&x+wd<=w.right+7&&y+h<=w.bottom+7;})()"""
   def walk(p):
    """안내를 끝까지 넘기며 제목·본문·강조 자리를 모은다(마지막 「안내 마치기」는 누르지 않는다)."""
    titles,bodies,inside=[],[],[]
    for _ in range(10):
     expect(p.locator('#usage-guide-title')).to_be_visible()
     titles.append(p.locator('#usage-guide-title').inner_text());bodies.append(p.locator('#usage-guide-body').inner_text());inside.append(p.evaluate(OUTLINE_IN_WINDOW))
     primary=p.locator('.usage-guide-actions .usage-guide-primary')
     if primary.inner_text()=='안내 마치기':break
     primary.click();p.wait_for_timeout(120)
    return titles,bodies,inside
   guide('dismissed')
   ctx=browser.new_context(viewport={'width':1440,'height':900},locale='ko-KR',user_agent=UA);ctx.add_cookies(cookie(tok))
   t=Tab(ctx);p=t.page
   sent0=sent()
   # ① 창 안 「? 이 창 사용 안내」(머리 바로 아래 하나) → 메신저 안내 — 목록 보기
   t.go('/tbox/');t.open()
   helpbtn=p.locator('#messenger-window > .modal__head + button[data-usage-guide-open]')
   expect(helpbtn).to_have_count(1)
   metrics['c3_help_button']=helpbtn.inner_text()
   check(p.locator('#messenger-window [data-usage-guide-open]').count()==1,'C-3 one 「'+metrics['c3_help_button']+'」 right under the floating window head')
   helpbtn.click()
   titles,bodies,inside=walk(p)
   metrics['c3_tour_list_view']=[a+' — '+b for a,b in zip(titles,bodies)]
   check(titles==['대화 상대 목록','사람 찾기','모아 AI와 전달되는 내용','크게 보기','닫아도 이어져요'],'C-3 list-view messenger tour steps '+json.dumps(titles,ensure_ascii=False))
   check(all(inside),'C-3 every list-view step highlights a target inside the floating window '+json.dumps(inside))
   check(not any(re.search(r'(?<!Chat)GPT|OpenAI',x) for x in titles+bodies),'C-3 tour titles and texts have no 「GPT」/「OpenAI」')
   check(guide_row().startswith('active:messenger:'),'C-3 progress saved with context messenger ('+guide_row()+')')
   p.locator('.usage-guide-actions .usage-guide-primary').click();p.wait_for_timeout(300)
   check(guide_row().startswith('completed:messenger:') and t.is_open() and p.locator('.usage-guide-dialog').count()==0,'C-3 「안내 마치기」 saves completed; the floating window stays open')
   # ② 대화 보기 — 안내는 쓰던 글·전송·창을 건드리지 않는다
   t.person('합성 상대 A');p.locator('#message-text').fill('');p.locator('#message-text').type('C-3 안내 중 초안')
   p.wait_for_function('k=>localStorage.getItem(k)!==null',arg='qaredi-draft:v1:'+M+':message:'+str(pa),timeout=8000)
   mark=time.time();helpbtn.click()
   titles,bodies,inside=walk(p)
   metrics['c3_tour_conversation_view']=[a+' — '+b for a,b in zip(titles,bodies)]
   check(titles==['대화 상대 목록','작성과 전송','크게 보기','닫아도 이어져요'] and all(inside),'C-3 conversation-view messenger tour steps '+json.dumps(titles,ensure_ascii=False))
   p.keyboard.press('Escape');p.wait_for_timeout(300)
   check(p.locator('.usage-guide-dialog').count()==0 and t.is_open() and p.locator('#message-text').input_value()=='C-3 안내 중 초안','C-3 Esc closes only the tour — window open, draft unchanged')
   check(not [r for r in t.requests if r[0]>=mark and r[1]=='POST' and r[2].startswith('/tbox/api/messages')] and sent()==sent0,'C-3 the tour sends nothing')
   check(guide_row().startswith('dismissed:messenger:'),'C-3 Esc saves dismissed')
   # ③ 화면 안내 — 떠 있는 창이 열려 있어도 그 화면의 단계로(전: 열린 창 안 단계만 골라 「이 화면은 …」 알림만)
   t.go('/community/')
   check(t.is_open(),'C-3 precondition: window restored open on the work home (Q:F-12 D)')
   p.locator('.usage-guide-launch').click()
   expect(p.locator('#usage-guide-title')).to_have_text('업무 홈',timeout=5000)   # Q:F-12 D — 개요 대신 업무 홈 안내(맥락 이름 overview 그대로)
   check(t.is_open() and guide_row().startswith('active:overview:'),'C-3 the page tour (overview) runs while the floating window is open')
   p.keyboard.press('Escape');p.wait_for_timeout(300)
   # ④ 처음 안내 제안 — 떠 있는 창이 열려 있어도 뜬다(전: dialog[open] 하나에 막혔다)
   guide(None)
   g=requests.get(base+'/api/me/guide',headers={'User-Agent':UA,'Cookie':'__Host-tbox_session='+tok},timeout=35)
   check(g.status_code==200 and g.json().get('guide',{}).get('offer') is True,'C-3 precondition: first-time offer is due for the manager fixture')
   t.go('/tbox/settings')
   expect(p.locator('#usage-guide-title')).to_have_text('모아 사용 방법을 안내해 드릴까요?',timeout=6000)
   check(t.is_open(),'C-3 the first-time offer appears while the floating window is open')
   p.get_by_role('button',name='나중에 볼게요').click();p.wait_for_timeout(300)
   check(guide_row().startswith('dismissed:'),'C-3 「나중에 볼게요」 saved')
   # ⑤ 이어 보기 — 메신저 안내가 진행 중인데 창이 닫혀 있으면 창의 여는 단추로 연다(셸 단추)
   p.locator('#messenger-window [data-messenger-close].btn--icon').click();p.wait_for_timeout(200)
   check(not t.is_open() and t.state(M)['open'] is False,'C-3 precondition: window closed (state open:false)')
   guide('active','messenger',1)
   t.go('/tbox/')
   expect(p.locator('#usage-guide-title')).to_have_text('작성과 전송',timeout=10000)
   check(t.is_open() and p.url.endswith('/tbox/') and p.evaluate("document.getElementById('usage-guide-outline').getAttribute('width')")!='0',
         'C-3 resuming the messenger tour opens the closed window (restored conversation, saved step 2 highlighted)')
   check(p.locator('#message-text').input_value()=='C-3 안내 중 초안' and sent()==sent0,'C-3 resume keeps the draft and sends nothing')
   p.keyboard.press('Escape');p.wait_for_timeout(300)
   # ⑥ 링크 단추(시작 안내 머리의 「메신저」 — /messages 링크)여도 안내가 누를 때 /messages 로 옮겨 가지 않는다
   p.locator('#messenger-window [data-messenger-close].btn--icon').click();p.wait_for_timeout(200)
   guide('active','messenger',0)
   t.go('/tbox/welcome')
   expect(p.locator('#usage-guide-title')).to_have_text('대화 상대 목록',timeout=10000)
   check(t.is_open() and p.url.endswith('/tbox/welcome') and p.locator('a#messenger-open[href$="/messages"]').count()==1,'C-3 on /welcome (opener is a /messages link) the tour opens the window in place — no navigation')
   p.keyboard.press('Escape');p.wait_for_timeout(300)
   metrics['c3_guide_after']=guide_row()
   check(t.clean('c3'),'C-3 no page errors / 5xx / script console output')
   ctx.close()
   guide('dismissed')

  # ─── 지시서 C-5 AI 도구 창 — DESIGN 2.7 머리 ✕ · 바닥 줄(MODAL_RULE 글자 그대로) ──────────────────────────────
  if want('c5'):
   tok=new_session('employee')
   for width in (1440,390):
    ctx=browser.new_context(viewport={'width':width,'height':900 if width>=720 else 844},locale='ko-KR',user_agent=UA);ctx.add_cookies(cookie(tok))
    t=Tab(ctx);p=t.page
    t.go('/tbox/')
    mine=[b for b in p.evaluate(MODAL_RULE,False)['bad'] if b[0]=='message-assist-dialog']
    check(mine==[],'C-5 '+str(width)+' AI tool dialog (closed, floating-window screen): MODAL_RULE violations 0 '+json.dumps(mine,ensure_ascii=False))
    t.open();t.person('합성 상대 A');p.locator('#message-text').fill('도구 창 시험 문장')
    p.locator('#message-assist').click();expect(p.locator('#message-assist-dialog')).to_be_visible()
    states={}
    for prov in ('local','web','gemini'):
     p.locator('[data-assist-provider="'+prov+'"]').click();p.wait_for_timeout(150)
     states[prov]=[b for b in p.evaluate(MODAL_RULE,True)['bad'] if b[0]=='message-assist-dialog']
    expect(p.locator('#assist-run')).to_be_enabled(timeout=10000)
    p.locator('#assist-run').click();expect(p.locator('#assist-result')).to_be_visible(timeout=20000)
    states['result']=[b for b in p.evaluate(MODAL_RULE,True)['bad'] if b[0]=='message-assist-dialog']
    metrics['c5_modal_rule_'+str(width)]=states
    check(all(v==[] for v in states.values()),'C-5 '+str(width)+' open AI tool dialog (PC · ChatGPT web · Gemini · result): MODAL_RULE violations 0 '+json.dumps(states,ensure_ascii=False))
    geo=p.evaluate("""(()=>{const d=document.getElementById('message-assist-dialog');const x=d.querySelector(':scope > .modal__head > #assist-close');const f=d.querySelectorAll(':scope > .modal__foot');
      const fb=f.length?[...f[0].querySelectorAll(':scope > button')]:[];const xr=x?x.getBoundingClientRect():null,dr=d.getBoundingClientRect();
      return {x:!!x&&x.matches('.btn--icon')&&x.getAttribute('aria-label')==='닫기',foots:f.length,foot:fb.map(b=>b.textContent.trim()+'|'+b.className),
        xTop:xr?Math.round(xr.top-dr.top):-1,xRight:xr?Math.round(dr.right-xr.right):-1,footBottom:f.length?Math.round(dr.bottom-f[0].getBoundingClientRect().bottom):-1,
        black:[...d.querySelectorAll('.btn')].filter(b=>!b.matches('.btn--quiet,.btn--danger,.btn--plain,.btn--icon')).map(b=>b.id||b.textContent.trim()),
        over:document.documentElement.scrollWidth-document.documentElement.clientWidth,dw:Math.round(dr.width)};})()""")
    metrics['c5_geometry_'+str(width)]=geo
    check(geo['x'] and geo['foots']==1 and geo['foot']==['닫기|btn btn--quiet'] and geo['black']==[],'C-5 '+str(width)+' head ✕ (aria-label 닫기) · one foot with 「닫기」 (outline) · no black button '+json.dumps(geo,ensure_ascii=False))
    check(0<=geo['xTop']<=40 and 0<=geo['xRight']<=40 and 0<=geo['footBottom']<=2 and geo['over']<=0,'C-5 '+str(width)+' ✕ at the head first line right end · foot at the dialog bottom · no horizontal overflow')
    if width<720:
     touch_bad=p.evaluate(TOUCH_JS,'#message-assist-dialog > .modal__head button, #message-assist-dialog > .modal__foot button')
     check(touch_bad==[],'C-5 390 ✕ and 「닫기」 tap areas 44 '+json.dumps(touch_bad,ensure_ascii=False))
    p.screenshot(path=str(shots/('c5-assist-'+str(width)+'.png')))
    # 바닥 「닫기」(form method=dialog — 스크립트 없이) · ✕ · Esc — 떠 있는 창과 쓰던 글은 그대로
    p.locator('#message-assist-dialog > .modal__foot button').click();p.wait_for_timeout(250)
    foot_closed=not p.locator('#message-assist-dialog').is_visible();focus=p.evaluate("document.activeElement&&document.activeElement.id")
    check(foot_closed and t.is_open(),'C-5 '+str(width)+' foot 「닫기」 closes the tool; the floating window stays open (focus → '+str(focus)+')')
    p.locator('#message-assist').click();expect(p.locator('#message-assist-dialog')).to_be_visible();p.locator('#assist-close').click();p.wait_for_timeout(200)
    x_closed=not p.locator('#message-assist-dialog').is_visible()
    p.locator('#message-assist').click();expect(p.locator('#message-assist-dialog')).to_be_visible();p.keyboard.press('Escape');p.wait_for_timeout(200)
    check(x_closed and not p.locator('#message-assist-dialog').is_visible() and t.is_open(),'C-5 '+str(width)+' ✕ and Esc close the tool; the floating window stays open')
    check(p.locator('#message-text').input_value()=='도구 창 시험 문장','C-5 '+str(width)+' closing the tool leaves the composer text as it was')
    if width==1440:
     t.go('/tbox/messages')
     mine=[b for b in p.evaluate(MODAL_RULE,False)['bad'] if b[0]=='message-assist-dialog']
     check(mine==[],'C-5 /messages (page) AI tool dialog: MODAL_RULE violations 0 '+json.dumps(mine,ensure_ascii=False))
    check(t.clean('c5-'+str(width)),'C-5 '+str(width)+' no page errors / 5xx / script console output')
    ctx.close()

  # ─── 지시서 C-6 사이드바 — 옛 /messages 링크 0 · 「메신저」 는 떠 있는 창 단추 하나 ─────────────────────────────
  if want('c6'):
   tok=new_session('employee')
   ctx=browser.new_context(viewport={'width':1440,'height':900},locale='ko-KR',user_agent=UA);ctx.add_cookies(cookie(tok))
   t=Tab(ctx);p=t.page
   for path in ('/tbox/','/community/','/community/memories'):
    t.go(path)
    side=p.evaluate("""(()=>{const s=document.getElementById('sidebar');const links=[...s.querySelectorAll('a[href]')].filter(a=>new URL(a.href,location.href).pathname.replace(/\\/$/,'')==='/tbox/messages').length;
      // Q:F-12 D — 업무 홈(/community/)도 이제 사이드바의 트리 · 최근 · 프로젝트 목록을 채운다(전엔 개요 화면이 채우지 않았다) — 사람이 붙인 제목(「메신저 창 시험 페이지」)은 세지 않는다
      const ui=s.cloneNode(true);ui.querySelectorAll('#nav-community-tree,#nav-community-fav,#nav-community-recent,#nav-work-projects,#nav-community-shared,#nav-community-boards,#nav-community-teams').forEach(n=>n.remove());
      return {links:links,text:(ui.textContent.match(/메신저/g)||[]).length,openers:s.querySelectorAll('[data-messenger-open]').length,rail:[...document.querySelectorAll('nav.app-rail a.app-rail__item')].map(a=>a.textContent.replace(/\\s+/g,' ').trim())};})()""")
    metrics['c6_sidebar'+path.replace('/','_')]=side
    check(side['links']==0,'C-6 '+path+' sidebar: /messages link 0 '+json.dumps(side,ensure_ascii=False))
    check(side['text']==1 and side['openers']==1,'C-6 '+path+' sidebar: 「메신저」 text once — the floating window opener')
   t.open();p.locator('#messenger-window .modal__head a').filter(has_text='크게 보기').click();p.wait_for_url('**/tbox/messages',timeout=10000)
   check(p.url.endswith('/tbox/messages') and p.locator('#message-form').count()==1,'C-6 full screen is reached from the window head 「크게 보기」 (/messages)')
   check(t.clean('c6'),'C-6 no page errors / 5xx / script console output')
   ctx.close()

  # ─── 화면 결함 감사(ui_snapshot.py 의 AUDIT_JS 글자 그대로 · 1440·1280) — 창 닫힘/열림 ───────────
  if want('snap'):
   tok=new_session('admin')
   for width in (1440,1280):
    ctx=browser.new_context(viewport={'width':width,'height':900},locale='ko-KR',user_agent=UA);ctx.add_cookies(cookie(tok))
    t=Tab(ctx);p=t.page
    for path in ('/tbox/','/tbox/settings','/tbox/activity','/tbox/users','/tbox/audit','/tbox/trash','/community/','/community/memories'):
     t.go(path)
     closed=p.evaluate(AUDIT_JS)
     t.open();p.wait_for_timeout(600)
     opened=p.evaluate(AUDIT_JS)
     def n(a):return sum(len(a[k]) for k in ('clipped','overlap','row_height','offscreen','undefined_class','dup_id','stage_text'))+(1 if a['overflow_x'] else 0)
     added={k:[x for x in opened[k] if x not in closed[k]] for k in ('clipped','overlap','row_height','offscreen','undefined_class','dup_id','stage_text')}
     # 감사의 「형제 카드 겹침」은 열린 dialog 도 카드로 센다 — 떠 있는 창이 뒤 화면의 판 위에 뜨는 것은 설계(DESIGN 2.17 「옆에 떠서」)라 따로 적는다
     floating_overlap=[x for x in added['overlap'] if 'dialog#messenger-window' in x];added['overlap']=[x for x in added['overlap'] if x not in floating_overlap]
     metrics['snap-'+str(width)+path.replace('/','_')]={'closed':n(closed),'open':n(opened),'closed_detail':{k:closed[k] for k in closed if isinstance(closed[k],list) and closed[k]},'added':{k:v for k,v in added.items() if v},'floating_overlap':len(floating_overlap)}
     check(not any(added.values()) and not (opened['overflow_x'] and not closed['overflow_x']),'ui_snapshot AUDIT_JS '+str(width)+' '+path+': window adds 0 defects besides floating over the page '+json.dumps({k:v for k,v in added.items() if v},ensure_ascii=False))
     check(not any('messenger' in x or 'message-' in x or 'floating' in x for k in closed if isinstance(closed[k],list) for x in closed[k]),'ui_snapshot AUDIT_JS '+str(width)+' '+path+': closed window markup adds no defect (dup id · undefined class …)')
     p.locator('#messenger-window [data-messenger-close].btn--icon').click()
    check(t.clean('snap'),'ui_snapshot '+str(width)+': console 0 (script)')
    ctx.close()
  browser.close()
finally:
 (out/'messenger-window-browser-metrics.json').write_text(json.dumps(metrics,ensure_ascii=False,indent=1))
 (out/'messenger-window-browser-results.json').write_text(json.dumps(checks,ensure_ascii=False,indent=2))
 print('RESULT pass='+str(sum(x['pass'] for x in checks))+' fail='+str(sum(not x['pass'] for x in checks)),flush=True)
if any(not x['pass'] for x in checks):raise SystemExit(1)
