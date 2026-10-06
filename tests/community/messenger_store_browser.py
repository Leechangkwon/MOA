#!/usr/bin/env python3
"""Q:F-11 지시서 A-5 — /messages 의 창 상태·쓰던 글 암호화 저장·삭제 규칙 (실제 PHP·PostgreSQL + Chromium, 1440·390).

memories_harness.py 만 부른다(합성 DB · 임시 복사본 · 로컬 php -S, 끝나면 지운다). 운영·시드 계정 0.
AI 제공자는 임시 복사본 안에서만 합성 응답으로 바꾼다(Fetch.php 의 Google 주소 앞 — memory_ai_http.py 방식). 외부 요청 0.
사람 목록 SQL 이 claude_ 아이디를 빼므로 대화 상대로 합성 구성원 둘(synthetic_peer_a/b — 로그인하지 않는다)을 이 DB 에만 만든다.
결과 파일에는 단언 이름과 참/거짓만.

  ① 상대 A 에 「ㄱㄴㄷ」 → 새로고침 → 복원 · localStorage 값에 평문 0 · sessionStorage 값에 본문 0
  ② 전송 201 뒤 그 항목 삭제      ③ 상대를 바꾸면 각자 초안      ④ AI 스레드 초안도 같게
  ⑤ 로그아웃 — `qaredi:logout` 이벤트(이 화면엔 app.js 가 없다)와 커뮤니티 홈의 로그아웃 단추(app.js) 둘 다 → 그 사람 접두 0 · 상태 0
  ⑥ 같은 탭에서 B 로 로그인 → A 의 초안·상태가 보이지 않고 A 의 메신저 항목 삭제
  ⑦ 열쇠 창구 503(APP_SECRET 을 짧게 한 두 번째 인스턴스) → 「임시저장 못 함」 · 저장 0
  ⑧ 콘솔 0 · 가로 넘침 0           ⑨ Gemini 대화 · AI 도구 · 이미지 첨부(복원 포함) · 번역 · PC 도우미 단추가 전과 같이
  더해서: 모듈(type=module)이 member.js(defer) 보다 먼저 돈다 · 스크롤 위치 이어 열기
"""
from pathlib import Path
exec(compile(Path(__file__).with_name('memories_http.py').read_text().split('try:\n for u in users:')[0],__file__,'exec'))
import re,secrets,shutil,signal,socket,struct,zlib
from playwright.sync_api import sync_playwright,expect
from urllib.parse import urlsplit
assert str(root).startswith('/tmp/qaredi-stage13-') and manifest['database'].startswith('tbox_memories_test_')
host=urlsplit(site).hostname
UA='tbox-review/'+time.strftime('%Y-%m-%d')
SHORT_PORT=8770
SHORT=site.replace(':8768',':'+str(SHORT_PORT))
out_shots=out


def png64():
 raw=b''.join(b'\x00'+bytes([200,120,80])*64 for _ in range(64))
 def chunk(t,d):return struct.pack('>I',len(d))+t+d+struct.pack('>I',zlib.crc32(t+d)&0xffffffff)
 return b'\x89PNG\r\n\x1a\n'+chunk(b'IHDR',struct.pack('>IIBBBBB',64,64,8,2,0,0,0))+chunk(b'IDAT',zlib.compress(raw))+chunk(b'IEND',b'')


# 합성 AI 제공자 — 이 임시 복사본에서만(운영 코드·저장소 파일 변경 0)
transport=root/'src/Fetch.php';original=transport.read_text()
needle="        $host = 'generativelanguage.googleapis.com';"
assert original.count(needle)==1
transport.write_text(original.replace(needle,"        return require __DIR__ . '/messenger-test-transport.php';\n"+needle))
(root/'src/messenger-test-transport.php').write_text("""<?php
// Disposable messenger browser test only; never a production transport.
if (!str_starts_with(Env::get('DB_NAME'), 'tbox_memories_test_')) throw new LogicException('Test DB required');
if ($image) return ['status'=>'completed','steps'=>[['type'=>'model_output','content'=>[['type'=>'image','mime_type'=>'image/png','data'=>'"""+base64.b64encode(png64()).decode()+"""']]]],'usage'=>['total_tokens'=>10]];
return ['candidates'=>[['finishReason'=>'STOP','content'=>['parts'=>[['text'=>'합성 응답입니다. <b>글자</b>']]]]],'usageMetadata'=>['totalTokenCount'=>10]];
""")
(root.parent/'tmp').mkdir(mode=0o700,exist_ok=True)  # Storage::tmpDir() — 생성 이미지를 저장소로 옮기기 전 자리(이 임시 작업 폴더 안)
keydir=root.parent/'private-messenger-ai';keydir.mkdir(mode=0o700,exist_ok=True)
(keydir/'gemini.key').write_text('synthetic-key-for-disposable-test-only');(keydir/'gemini.key').chmod(0o600)


def uid(key):return int(users[key]['id'])
def new_session(key):
 token=secrets.token_hex(32);digest=hashlib.sha256(token.encode()).hexdigest()
 sql("INSERT INTO sessions(id,user_id,expires_at,ip,user_agent) VALUES('"+digest+"',"+str(uid(key))+",clock_timestamp()+interval '8 hours','127.0.0.1','"+UA+"')")
 users[key]['session']=token
def cookie(key):return [{'name':'__Host-tbox_session','value':users[key]['session'],'domain':host,'path':'/','secure':True,'httpOnly':True,'sameSite':'Lax'}]

ORDER_PROBE="""(() => {
  window.__order = [];
  const original = window.fetch;
  window.fetch = function (input, init) {
    try { if (String(input && input.url ? input.url : input).endsWith('/tbox/api/me')) window.__order.push('me'); } catch (e) {}
    return original.apply(this, arguments);
  };
  Object.defineProperty(window, 'MoaMessenger', {configurable: true, get() { return undefined; },
    set(v) { window.__order.push('module'); Object.defineProperty(window, 'MoaMessenger', {value: v, writable: false, configurable: true}); }});
})();"""

def code_console(lines):return [t for t,_ in lines if not t.startswith('Failed to load resource')]
def resource_lines(lines):return {(re.search(r'status of ([0-9]{3})',t).group(1),path) for t,path in lines if t.startswith('Failed to load resource')}

short_server=None
try:
 emp,oth=uid('employee'),uid('other')
 for name,display in (('synthetic_peer_a','합성 상대 A'),('synthetic_peer_b','합성 상대 B')):
  sql("INSERT INTO users(username,password_hash,role,display_name,expires_at) VALUES('"+name+"','!synthetic-no-login','Employee','"+display+"',clock_timestamp()+interval '14 days')")
 pa=int(sql("SELECT id FROM users WHERE username='synthetic_peer_a'"));pb=int(sql("SELECT id FROM users WHERE username='synthetic_peer_b'"))
 sql("INSERT INTO community.usage_guides(user_id,version,status,context) SELECT id,1,'dismissed','messenger' FROM users ON CONFLICT DO NOTHING")
 sql("UPDATE member_ai_settings SET enabled=true,image_enabled=true")
 # 스크롤이 생길 만큼의 옛 대화(마이그레이션 전 모양 — request_id NULL) + 상대 B 가 보낸 글 하나(번역 단추)
 sql("INSERT INTO member_messages(sender_id,recipient_id,body,sent_at,read_at) SELECT CASE WHEN g%2=0 THEN "+str(emp)+" ELSE "+str(pa)+" END,CASE WHEN g%2=0 THEN "+str(pa)+" ELSE "+str(emp)+" END,'합성 옛 대화 '||g,now()-interval '1 day'+g*interval '1 minute',now() FROM generate_series(1,40) g")
 sql("INSERT INTO member_messages(sender_id,recipient_id,body,read_at) VALUES("+str(pb)+","+str(emp)+",'Hello from synthetic peer B',now())")
 me_a=users['employee'];a_name=sql('SELECT username FROM users WHERE id='+str(emp));b_name=sql('SELECT username FROM users WHERE id='+str(oth))
 A_PREFIX='qaredi-draft:v1:'+a_name+':';A_STATE='qaredi-messenger:v1:'+a_name;B_STATE='qaredi-messenger:v1:'+b_name

 # ⑦ 짧은 APP_SECRET 의 두 번째 인스턴스(같은 DB) — 열쇠 창구만 503 이 된다
 with socket.socket() as s:
  if s.connect_ex(('127.0.0.1',SHORT_PORT))==0:raise SystemExit('Port occupied; nothing stopped')
 short=root.parent/'short-secret-instance'
 shutil.copytree(root,short,symlinks=True)
 env_lines=[l for l in (short/'.env').read_text().splitlines() if not l.startswith(('APP_SECRET=','APP_URL='))]
 (short/'.env').write_text('\n'.join(env_lines+['APP_SECRET='+secrets.token_hex(8),'APP_URL='+SHORT])+'\n');(short/'.env').chmod(0o600)
 short_log=(root.parent/'logs/short-php.log').open('wb')
 short_server=subprocess.Popen(['php','-S','127.0.0.1:'+str(SHORT_PORT),'-t','public','tests/community/export_router.php'],cwd=short,
                               env={k:v for k,v in os.environ.items() if k!='PHP_CLI_SERVER_WORKERS'},stdout=short_log,stderr=short_log,start_new_session=True)
 for _ in range(50):
  with socket.socket() as s:
   if s.connect_ex(('127.0.0.1',SHORT_PORT))==0:break
  time.sleep(0.1)

 with sync_playwright() as pw:
  browser=pw.chromium.launch(executable_path=os.environ.get('CHROMIUM_EXECUTABLE'))
  for width in (1440,390):
   W=str(width)
   if width==390:new_session('employee')
   ctx=browser.new_context(viewport={'width':width,'height':900},locale='ko-KR',user_agent=UA)
   ctx.add_cookies(cookie('employee'))
   tab=ctx.new_page();console=[];errors=[];bad=[];requests_seen=[]
   tab.on('console',lambda m:console.append((m.text,urlsplit(m.location.get('url','')).path)));tab.on('pageerror',lambda e:errors.append(str(e)))
   tab.on('response',lambda r:bad.append(r.url) if r.status>=500 else None);tab.on('request',lambda r:requests_seen.append(r.url))
   tab.add_init_script(ORDER_PROBE)
   def ls():return tab.evaluate('Object.fromEntries(Object.keys(localStorage).map(k=>[k,localStorage.getItem(k)]))')
   def ss():return tab.evaluate('Object.fromEntries(Object.keys(sessionStorage).map(k=>[k,sessionStorage.getItem(k)]))')
   def wait_key(k):tab.wait_for_function('k=>localStorage.getItem(k)!==null',arg=k,timeout=8000)
   def wait_gone(k):tab.wait_for_function('k=>localStorage.getItem(k)===null',arg=k,timeout=8000)
   def person(label):
    tab.locator('#message-people button').filter(has_text=label).click()
    expect(tab.locator('#message-peer-name')).to_contain_text(label)
   def messages():
    tab.goto(site+'/tbox/messages',wait_until='networkidle')
    expect(tab.locator('#message-peer-name')).not_to_have_text('어떤 이야기를 나눌까요?')
    expect(tab.locator('#message-peer-name')).not_to_have_text('대화를 불러오는 중…')
   KA,KB=A_PREFIX+'message:'+str(pa),A_PREFIX+'message:'+str(pb)

   messages()
   expect(tab.locator('#message-peer-name')).to_have_text('모아 AI')
   order=tab.evaluate('window.__order')
   check(order[:2]==['module','me'],'module script runs before member.js (defer) '+W)
   check(tab.evaluate("document.querySelector('script[type=module][src*=\"/assets/messenger.js\"]').compareDocumentPosition(document.querySelector('script[src*=\"/assets/member.js\"]')) & Node.DOCUMENT_POSITION_FOLLOWING")>0,'module tag precedes member.js tag '+W)
   check(not any('/community/api/me/draft-key' in u for u in requests_seen),'no draft key request before writing or saved entries '+W)

   # ① 상대 A 에 「ㄱㄴㄷ」 → 저장(암호문) → 새로고침 → 복원
   person('합성 상대 A');tab.locator('#message-text').type('ㄱㄴㄷ');wait_key(KA)
   stored=ls()[KA];rec=json.loads(stored)
   check(set(rec)=={'v','iv','ct','saved_at'} and 'ㄱㄴㄷ' not in stored and '\\u3131' not in stored,'① localStorage holds ciphertext only (no plaintext) '+W)
   check(base64.b64decode(rec['ct']).find('ㄱㄴㄷ'.encode())==-1,'① ciphertext bytes do not contain the UTF-8 text '+W)
   state=ss().get(A_STATE,'')
   st=json.loads(state)
   check(st['kind']=='person' and st['peer']==pa and st['thread'] is None and set(st)=={'v','open','kind','peer','thread','scroll','at'} and st['open'] is False,'① sessionStorage state has numbers only, open stays false on the page '+W)
   check('ㄱㄴㄷ' not in state and '합성' not in state and 'synthetic' not in state,'① sessionStorage state has no body or names '+W)
   tab.reload(wait_until='networkidle');expect(tab.locator('#message-peer-name')).to_contain_text('합성 상대 A')
   expect(tab.locator('#message-text')).to_have_value('ㄱㄴㄷ')
   check(tab.evaluate("document.activeElement!==document.getElementById('message-text')"),'① restore does not move focus into the composer '+W)
   check(True,'① same peer and draft restored after reload '+W)

   # 스크롤 위치 이어 열기(맨 아래가 아닌 자리)
   tab.evaluate("(()=>{const l=document.getElementById('message-list');l.scrollTop=Math.max(0,l.scrollHeight-l.clientHeight-300);})()")
   tab.wait_for_function("k=>{try{return JSON.parse(sessionStorage.getItem(k)).scroll.fromBottom>=250}catch(e){return false}}",arg=A_STATE,timeout=5000)
   saved_from=json.loads(ss()[A_STATE])['scroll']['fromBottom']
   tab.reload(wait_until='networkidle');expect(tab.locator('#message-text')).to_have_value('ㄱㄴㄷ')
   tab.wait_for_timeout(300)
   now_from=tab.evaluate("(()=>{const l=document.getElementById('message-list');return l.scrollHeight-l.scrollTop-l.clientHeight;})()")
   check(abs(now_from-saved_from)<=4 and now_from>=250,'scroll position (not bottom) restored after reload '+W)

   # ③ 상대를 바꾸면 각자 초안
   person('합성 상대 B');expect(tab.locator('#message-text')).to_have_value('')
   tab.locator('#message-text').type('ㄹㅁㅂ');wait_key(KB)
   person('합성 상대 A');expect(tab.locator('#message-text')).to_have_value('ㄱㄴㄷ')
   person('합성 상대 B');expect(tab.locator('#message-text')).to_have_value('ㄹㅁㅂ')
   tab.reload(wait_until='networkidle');expect(tab.locator('#message-peer-name')).to_contain_text('합성 상대 B')
   expect(tab.locator('#message-text')).to_have_value('ㄹㅁㅂ')
   person('합성 상대 A');expect(tab.locator('#message-text')).to_have_value('ㄱㄴㄷ')
   check(KA in ls() and KB in ls(),'③ each peer keeps its own encrypted draft '+W)

   # ② 보내기 201 → 그 항목만 지움 · DB 한 행(request_id 있음)
   tab.locator('#message-send').click();expect(tab.locator('#message-list')).to_contain_text('ㄱㄴㄷ');wait_gone(KA)
   expect(tab.locator('#message-text')).to_have_value('')
   check(KA not in ls() and KB in ls(),'② sent draft removed, other peer draft kept '+W)
   check(sql("SELECT count(*) FROM member_messages WHERE sender_id="+str(emp)+" AND recipient_id="+str(pa)+" AND body='ㄱㄴㄷ' AND request_id IS NOT NULL")==('1' if width==1440 else '2'),'② exactly one row per send with request_id '+W)
   tab.reload(wait_until='networkidle');expect(tab.locator('#message-text')).to_have_value('')
   check(True,'② no draft comes back after a successful send '+W)

   # ④ AI 스레드 초안
   tab.locator('#message-ai').click();expect(tab.locator('#message-peer-name')).to_have_text('모아 AI');expect(tab.locator('#message-text')).to_be_enabled()
   thread_key=lambda:A_PREFIX+'ai:'+str(tab.evaluate("Number(document.getElementById('message-ai-threads').value)||0"))
   k_ai=thread_key();tab.locator('#message-text').type('AI 초안 ㅅㅇㅈ');wait_key(k_ai)
   check('ㅅㅇㅈ' not in ls()[k_ai],'④ AI draft stored as ciphertext '+W)
   st=json.loads(ss()[A_STATE]);check(st['kind']=='ai' and st['peer'] is None,'④ state switches to AI '+W)
   tab.reload(wait_until='networkidle');expect(tab.locator('#message-peer-name')).to_have_text('모아 AI')
   expect(tab.locator('#message-text')).to_have_value('AI 초안 ㅅㅇㅈ')
   check(True,'④ AI thread and its draft restored after reload '+W)
   if width==1440:
    # ⑨ Gemini 대화(합성 제공자) — 보낸 뒤 그 스레드 초안은 지운다
    tab.locator('#message-send').click();expect(tab.locator('#message-list')).to_contain_text('합성 응답입니다.')
    wait_gone(k_ai);expect(tab.locator('#message-text')).to_have_value('')
    check(tab.locator('#message-list b').count()==0,'⑨ Gemini answer rendered as text (no HTML) ')
    check(k_ai not in ls(),'⑨ AI draft removed after the question is answered')
    tab.locator('#message-ai-new').click();expect(tab.locator('#message-text')).to_be_enabled()
    tab.locator('#message-text').type('새 대화 초안');wait_key(A_PREFIX+'ai:0')
    current=tab.evaluate("[...document.querySelectorAll('#message-ai-threads option')].map(o=>o.value).filter(v=>v!=='0')[0]")
    tab.locator('#message-ai-threads').select_option(current);expect(tab.locator('#message-text')).to_have_value('')
    tab.locator('#message-ai-threads').select_option('0');expect(tab.locator('#message-text')).to_have_value('새 대화 초안')
    check(True,'④ AI drafts follow each thread (existing thread empty, new conversation keeps its draft)')

    # ⑨ AI 도구 — 다듬기 · 이미지 첨부(초안에 attachment_id → 새로고침 뒤 서버 목록으로 확인해 다시 붙음) · 번역 · PC 도우미 단추
    person('합성 상대 B');expect(tab.locator('#message-text')).to_have_value('ㄹㅁㅂ')
    tab.locator('#message-assist').click();expect(tab.locator('#message-assist-dialog')).to_be_visible()
    tab.locator('[data-assist-provider="gemini"]').click();tab.locator('[data-assist-action="polish"]').click()
    expect(tab.locator('#assist-run')).to_be_enabled();tab.locator('#assist-run').click();expect(tab.locator('#assist-result')).to_be_visible()
    tab.locator('#assist-apply').click();expect(tab.locator('#message-text')).to_have_value('합성 응답입니다. <b>글자</b>')
    tab.wait_for_function("k=>localStorage.getItem(k)!==null",arg=KB)
    check(True,'⑨ AI tool polish applies to the composer (draft follows)')
    tab.locator('#message-assist').click();tab.locator('[data-assist-action="image"]').click();tab.locator('#assist-input').fill('합성 감사 카드')
    tab.locator('#assist-run').click();expect(tab.locator('#assist-image')).to_be_visible();tab.locator('#assist-apply').click()
    expect(tab.locator('#message-attachment')).to_be_visible()
    tab.wait_for_timeout(1600)
    tab.reload(wait_until='networkidle');expect(tab.locator('#message-peer-name')).to_contain_text('합성 상대 B')
    expect(tab.locator('#message-attachment')).to_be_visible();tab.wait_for_function("document.getElementById('message-attachment-image').naturalWidth>0")
    check(True,'⑨ pending AI image attachment restored after reload (verified against the server list)')
    tab.locator('#message-send').click();expect(tab.locator('#message-list .moa-message-image img').first).to_be_visible();wait_gone(KB)
    check(sql("SELECT count(*) FROM member_messages WHERE sender_id="+str(emp)+" AND recipient_id="+str(pb)+" AND ai_attachment_id IS NOT NULL AND request_id IS NOT NULL")=='1','⑨ image message sent once with request_id')
    tab.locator('.moa-message-translate').first.click();expect(tab.locator('#message-assist-dialog')).to_be_visible()
    check(tab.locator('#assist-input').input_value()=='Hello from synthetic peer B' and tab.locator('#assist-language').input_value()=='ko','⑨ translate opens with the received text')
    tab.locator('[data-assist-provider="local"]').click();expect(tab.locator('#assist-local')).to_be_visible();expect(tab.locator('#assist-local-pair')).to_be_enabled()
    check(not any('127.0.0.1:43821' in u for u in requests_seen),'⑨ PC helper controls shown without any loopback request before pairing')
    tab.locator('#assist-close').click()

    # ⑦ 열쇠 503 — 짧은 APP_SECRET 인스턴스(다른 포트 = 다른 저장소 출처)
    short_tab=ctx.new_page();short_console=[];short_tab.on('console',lambda m:short_console.append((m.text,urlsplit(m.location.get('url','')).path)));short_seen=[]
    short_tab.add_init_script('window.__order=[]')
    short_tab.on('response',lambda r:short_seen.append((r.url,r.status)))
    short_tab.goto(SHORT+'/tbox/messages',wait_until='networkidle');expect(short_tab.locator('#message-peer-name')).to_have_text('모아 AI')
    short_tab.locator('#message-people button').filter(has_text='합성 상대 A').click();expect(short_tab.locator('#message-peer-name')).to_contain_text('합성 상대 A')
    short_tab.locator('#message-text').type('저장 안 됨');expect(short_tab.locator('[data-draft-off] .badge.badge--off')).to_be_visible()
    expect(short_tab.locator('[data-draft-off] .badge.badge--off')).to_have_text('임시저장 못 함')
    short_tab.wait_for_timeout(1600)
    keys=short_tab.evaluate('Object.keys(localStorage)')
    check(any(u.endswith('/community/api/me/draft-key') and s==503 for u,s in short_seen),'⑦ draft key endpoint answers 503 on the short-secret instance')
    check(not [k for k in keys if k.startswith('qaredi-draft:')],'⑦ nothing stored without a key (no plaintext fallback)')
    check(sum(1 for u,_ in short_seen if u.endswith('/community/api/me/draft-key'))==1,'⑦ key requested once per screen even after failure')
    short_tab.locator('#message-text').type(' 더');short_tab.wait_for_timeout(1300)
    check(sum(1 for u,_ in short_seen if u.endswith('/community/api/me/draft-key'))==1,'⑦ failed key is remembered (no retry)')
    # 화면 코드의 콘솔 출력 0. 브라우저가 스스로 남기는 자원 상태 줄은 열쇠 창구 503 과(이미 있던) /favicon.ico 404 뿐
    check(code_console(short_console)==[] and resource_lines(short_console)<={('503','/community/api/me/draft-key'),('404','/favicon.ico')},'⑦ console: no script output; only the 503 key and favicon resource lines')
    short_tab.screenshot(path=str(out_shots/'store-503-badge-1440.png'));short_tab.close()

   # ⑧ 콘솔 0 · 가로 넘침 0
   person('합성 상대 A');tab.locator('#message-text').type('넘침 확인');wait_key(KA)
   check(tab.evaluate('document.documentElement.scrollWidth<=innerWidth+1'),'⑧ no horizontal overflow '+W)
   tab.screenshot(path=str(out_shots/('messages-'+W+'.png')),full_page=True)

   # ⑥ 같은 탭에서 B 로 로그인 → A 의 상태·메신저 초안이 보이지 않고 지워진다(값은 읽지 않는다)
   check(KA in ls() and A_STATE in ss(),'⑥ precondition: A has a draft and state in this tab '+W)
   page_draft=A_PREFIX+'page:00000000-0000-4000-8000-000000000000'
   tab.evaluate("k=>localStorage.setItem(k,JSON.stringify({v:1,iv:'AAAAAAAAAAAAAAAA',ct:'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',saved_at:Date.now()}))",page_draft)
   ctx.clear_cookies();ctx.add_cookies(cookie('other'))
   messages();expect(tab.locator('#message-peer-name')).to_have_text('모아 AI')
   check(tab.locator('#message-text').input_value()!='넘침 확인' and 'ㄹㅁㅂ' not in tab.locator('#message-text').input_value(),'⑥ B does not see A draft '+W)
   check(A_STATE not in ss() and not [k for k in ls() if k.startswith(A_PREFIX+'message:') or k.startswith(A_PREFIX+'ai:')],'⑥ A messenger state and drafts removed when B mounts '+W)
   check(page_draft in ls(),'⑥ A community page draft untouched (C:R-10 — other person prefix not read or cleared) '+W)
   tab.evaluate('k=>localStorage.removeItem(k)',page_draft)
   ctx.clear_cookies();ctx.add_cookies(cookie('employee'))

   # ⑤-1 `qaredi:logout` 이벤트(이 화면에는 app.js 가 없다) → 접두 0 · 상태 0 · 떠날 때 다시 쓰지 않는다
   messages();person('합성 상대 A');tab.locator('#message-text').type('로그아웃 전 글');wait_key(KA)
   check(A_STATE in ss(),'⑤ precondition: state present before logout event '+W)
   tab.evaluate("document.dispatchEvent(new CustomEvent('qaredi:logout'))")
   check(not [k for k in ls() if k.startswith(A_PREFIX)] and A_STATE not in ss(),'⑤ logout event clears this person prefix and messenger state '+W)
   tab.locator('#message-text').type(' 계속');tab.wait_for_timeout(1500)
   tab.goto(base+'/',wait_until='networkidle')
   check(not [k for k in ls() if k.startswith(A_PREFIX)] and A_STATE not in ss(),'⑤ nothing rewritten after logout event (typing, pagehide) '+W)

   # ⑤-2 app.js 경로 — 커뮤니티 홈의 로그아웃 단추
   messages();person('합성 상대 A');tab.locator('#message-text').type('app.js 로그아웃');wait_key(KA)
   check(A_STATE in ss(),'⑤ precondition: state present before shell logout '+W)
   tab.goto(base+'/',wait_until='networkidle')
   check(KA in ls() and A_STATE in ss(),'⑤ draft and state survive same-tab navigation to community home '+W)
   logout=tab.locator('#logout')
   if not logout.is_visible() and tab.locator('#appbar-apps').is_visible():tab.locator('#appbar-apps').click()  # 좁은 화면: 서랍 + 앱 메뉴
   if not logout.is_visible():tab.locator('details.appbar__apps > summary').click()
   expect(logout).to_be_visible();logout.click();tab.wait_for_url('**/login**',timeout=15000)
   check(not [k for k in ls() if k.startswith(A_PREFIX)] and A_STATE not in ss(),'⑤ shell logout (app.js) clears drafts and messenger state '+W)
   check(not errors and not bad,'⑧ no page errors or 5xx '+W)
   check(code_console(console)==[],'⑧ console output from page scripts 0 '+W)
   # 이미 있던 것만: /favicon.ico(링크 없음) · 로그아웃 뒤 로그인 화면의 lake.jpg(시험 라우터 export_router.php 가 .jpg 를 내주지 않는다 — 운영 nginx 는 낸다)
   check(resource_lines(console)<={('404','/favicon.ico'),('404','/tbox/assets/moa/lake.jpg')},'⑧ only pre-existing browser resource lines (favicon, test-router jpg) '+W)
   ctx.close()
  browser.close()
finally:
 if short_server is not None:
  try:os.killpg(short_server.pid,signal.SIGTERM);short_server.wait(timeout=5)
  except ProcessLookupError:pass
 (out/'messenger-store-browser-results.json').write_text(json.dumps(checks,ensure_ascii=False,indent=2))
 print('RESULT pass='+str(sum(x['pass'] for x in checks))+' fail='+str(sum(not x['pass'] for x in checks)),flush=True)
