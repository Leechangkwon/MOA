#!/usr/bin/env python3
"""Q:F-13 지시서 H — 개인정보 처리 빈틈 빠른 고침 G-3 · G-6 (실제 PHP·PostgreSQL + Chromium).

memories_harness.py 만 부른다(합성 DB · 임시 복사본 · 로컬 php -S, 끝나면 지운다). 운영·시드 계정 0 — fixture claude_ 만.
외부 요청 0(이 시험은 AI 제공자를 부르지 않는다). 결과 파일에는 단언 이름과 참/거짓만. G-1 은 memory_ai_http.py.

  G-6 ① cron 조각(deploy/cron.d/tbox-v2) 정적 검사 — 칸 수 · 시각 칸 모양 · 04:20 한 줄 · 다른 정리와 같은 실행 계정 · 경로
      ② memories_cleanup.php 를 이 합성 DB 에 dry run(아무것도 지우지 않음) 뒤 --apply —
         7일 지난 붙지 않은 사진만 행·파일이 지워지고 붙은 사진 · 7일 안 된 것은 남는다 · 다시 돌리면 0
  G-3 여행 임시 글·사진(IndexedDB `qaredi-travel-drafts`, 열쇠 = 아이디) —
      ① 커뮤니티 홈(사진 기록 모듈 없음, app.js 만)의 로그아웃 → 그 사람 항목 0
      ② 다시 로그인한 다른 사람(B)에게 이전 임시 글 0 — 「이어 쓰기」 단추 숨김 · A 항목 없음
      ③ 사진 기록 화면 머리(모아 머리)의 로그아웃 — `qaredi:logout` 을 알리는 길 → A 항목 0 · 다른 사람(B) 항목은 그대로(값을 읽지 않는다)
      ④ 글쓰기 창(임시 저장을 쓰는 유일한 때)은 모달이라 그동안 머리의 로그아웃 단추에 닿지 않는다 — 지운 뒤 다시 쓰는 틈이 없다
      ⑤ B 도 로그아웃 → 항목 0 · 임시 글이 없던 브라우저에서 로그아웃해도 빈 저장소를 만들지 않음
      ⑥ 콘솔 0 · 페이지 오류 0 · 5xx 0
"""
from pathlib import Path
exec(compile(Path(__file__).with_name('memories_http.py').read_text().split('try:\n for u in users:')[0],__file__,'exec'))
import re,secrets,struct,zlib
from playwright.sync_api import sync_playwright,expect
from urllib.parse import urlsplit
assert str(root).startswith('/tmp/qaredi-stage13-') and manifest['database'].startswith('tbox_memories_test_')
host=urlsplit(site).hostname
UA='tbox-review/'+time.strftime('%Y-%m-%d')
DB_NAME='qaredi-travel-drafts'


def png64():
 raw=b''.join(b'\x00'+bytes([90,140,200])*64 for _ in range(64))
 def chunk(t,d):return struct.pack('>I',len(d))+t+d+struct.pack('>I',zlib.crc32(t+d)&0xffffffff)
 return b'\x89PNG\r\n\x1a\n'+chunk(b'IHDR',struct.pack('>IIBBBBB',64,64,8,2,0,0,0))+chunk(b'IDAT',zlib.compress(raw))+chunk(b'IEND',b'')


def uid(key):return int(users[key]['id'])
def new_session(key):
 token=secrets.token_hex(32);digest=hashlib.sha256(token.encode()).hexdigest()
 sql("INSERT INTO sessions(id,user_id,expires_at,ip,user_agent) VALUES('"+digest+"',"+str(uid(key))+",clock_timestamp()+interval '8 hours','127.0.0.1','"+UA+"')")
 users[key]['session']=token
def cookie(key):return [{'name':'__Host-tbox_session','value':users[key]['session'],'domain':host,'path':'/','secure':True,'httpOnly':True,'sameSite':'Lax'}]
def code_console(lines):return [t for t,_ in lines if not t.startswith('Failed to load resource')]
def resource_lines(lines):return {(re.search(r'status of ([0-9]{3})',t).group(1),path) for t,path in lines if t.startswith('Failed to load resource')}

# 저장소를 새로 만들지 않고(처음 여는 것이면 취소) 열쇠 목록과 「이 사람 항목에 글·사진이 있나」만 본다(값 원문을 결과에 싣지 않는다).
PROBE="""async ([name, owner]) => {
  const listed = indexedDB.databases ? (await indexedDB.databases()).some((d) => d.name === name) : null;
  if (listed === false) return {db: false, keys: [], mine: null};
  const db = await new Promise((resolve) => { const r = indexedDB.open(name); r.onupgradeneeded = () => r.transaction.abort();
    r.onsuccess = () => resolve(r.result); r.onerror = (e) => { e.preventDefault(); resolve(null); }; });
  if (!db) return {db: false, keys: [], mine: null};
  if (!db.objectStoreNames.contains('drafts')) { db.close(); return {db: true, keys: [], mine: null}; }
  const [keys, value] = await new Promise((resolve) => { const tx = db.transaction('drafts'); const s = tx.objectStore('drafts');
    const k = s.getAllKeys(); const v = s.get(owner); tx.oncomplete = () => resolve([k.result, v.result]); tx.onerror = () => resolve([null, null]); });
  db.close();
  return {db: true, keys, mine: value ? {caption: String(value.caption || ''), files: (value.media || []).filter((m) => m.file instanceof Blob).length} : null};
}"""

try:
 for u in users:
  users[u]['username']=sql('SELECT username FROM users WHERE id='+str(users[u]['id']))
  call('GET','/api/me/space',user=u)
 A,B=users['employee']['username'],users['other']['username']
 sql("INSERT INTO community.usage_guides(user_id,version,status,context) SELECT id,1,'dismissed','threads' FROM users ON CONFLICT DO NOTHING")

 # ── G-6 ① cron 조각 정적 검사 ─────────────────────────────────────────
 cron=(root/'deploy/cron.d/tbox-v2').read_text()
 lines=[l for l in cron.splitlines() if l.strip() and not l.lstrip().startswith('#')]
 jobs=[l for l in lines if not re.match(r'^[A-Z_]+=',l)]
 check(cron.endswith('\n') and jobs and all(len(l.split())>=7 and all(re.fullmatch(r'[0-9*/,\-]+',f) for f in l.split()[:5]) for l in jobs),
       'G-6 cron.d: every job line has 5 time fields + run-as + command, file ends with newline')
 mine=[l.split() for l in jobs if 'memories_cleanup.php' in l]
 purges=[l.split() for l in jobs if 'purge_trash.php' in l or 'community_purge.php' in l]
 check(len(mine)==1 and mine[0][:5]==['20','4','*','*','*'] and mine[0][6:]==['php','/var/www/qaredi/tbox-v2/scripts/memories_cleanup.php','--apply'],
       'G-6 exactly one daily 04:20 line: php /var/www/qaredi/tbox-v2/scripts/memories_cleanup.php --apply')
 check(len(purges)==2 and all(p[5]==mine[0][5] for p in purges) and mine[0][5]=='root','G-6 run-as is the same account as the other purge jobs (purge_trash, community_purge)')
 check((root/'scripts/memories_cleanup.php').is_file() and not [l for l in jobs if l.split()[:2]==['20','4'] and 'memories_cleanup.php' not in l],
       'G-6 script exists in the repository and no other job shares 04:20')

 # ── G-6 ② 붙지 않은 사진 정리 — dry run 뒤 --apply (이 합성 DB · 임시 저장소만) ──────────
 store=root.parent/'storage/memories'
 old_loose=upload(name='old-loose.png')['id'];old_other=upload(user='other',name='old-other.png')['id']
 old_attached=upload(name='old-attached.png')['id'];fresh_loose=upload(name='fresh-loose.png')['id'];six_days=upload(name='six-days.png')['id']
 save(dict(payload(),media_ids=[old_attached]))
 sql("UPDATE community.memory_media SET created_at=now()-interval '8 days' WHERE id IN ('"+old_loose+"','"+old_other+"','"+old_attached+"')")
 sql("UPDATE community.memory_media SET created_at=now()-interval '6 days 23 hours' WHERE id='"+six_days+"'")
 expected=int(sql("SELECT count(*) FROM community.memory_media WHERE post_id IS NULL AND created_at<now()-interval '7 days'"))
 def present(i):return sql("SELECT count(*) FROM community.memory_media WHERE id='"+i+"'")=='1' and (store/i).is_file()
 def cleanup(*extra):return subprocess.run(['php','scripts/memories_cleanup.php',*extra],cwd=root,capture_output=True,text=True,timeout=60)
 every=[old_loose,old_other,old_attached,fresh_loose,six_days]
 dry=cleanup()
 (out/'g6-cleanup-dry-run.txt').write_text('exit='+str(dry.returncode)+'\n'+dry.stdout+dry.stderr)
 check(expected==2 and dry.returncode==0 and dry.stdout.strip()=='Dry run, unattached uploads older than seven days: 2' and all(present(i) for i in every),
       'G-6 dry run reports the two unattached uploads older than seven days and removes nothing')
 applied=cleanup('--apply')
 (out/'g6-cleanup-apply.txt').write_text('exit='+str(applied.returncode)+'\n'+applied.stdout+applied.stderr)
 check(applied.returncode==0 and applied.stdout.strip()=='Removed: 2' and not present(old_loose) and not (store/old_loose).exists()
       and not present(old_other) and not (store/old_other).exists(),'G-6 --apply removes rows and files of unattached uploads older than seven days (both owners)')
 check(present(old_attached) and present(fresh_loose) and present(six_days),'G-6 --apply keeps attached photos and uploads younger than seven days')
 again=cleanup('--apply')
 check(again.returncode==0 and again.stdout.strip()=='Removed: 0','G-6 second --apply removes nothing')

 # ── G-3 여행 임시 글·사진 — 로그아웃 때 지운다 ───────────────────────────
 photo=png64()
 with sync_playwright() as pw:
  browser=pw.chromium.launch(executable_path=os.environ.get('CHROMIUM_EXECUTABLE'))
  console=[];errors=[];bad=[]
  def watch(page):
   page.on('console',lambda m:console.append((m.text,urlsplit(m.location.get('url','')).path)));page.on('pageerror',lambda e:errors.append(str(e)))
   page.on('response',lambda r:bad.append(r.url) if r.status>=500 else None)
  ctx=browser.new_context(viewport={'width':1280,'height':900},locale='ko-KR',user_agent=UA)
  tab=ctx.new_page();watch(tab)
  def probe(owner):return tab.evaluate(PROBE,[DB_NAME,owner])
  def wait_draft(owner,text):
   for _ in range(80):
    p=probe(owner)
    if p['mine'] and text in p['mine']['caption'] and p['mine']['files']==1:return p
    time.sleep(0.1)
   return probe(owner)
  def as_user(key):ctx.clear_cookies();ctx.add_cookies(cookie(key))
  def memories():
   tab.goto(base+'/memories',wait_until='networkidle');expect(tab.locator('[data-compose]:visible').first).to_be_visible()
  def write_draft(owner,text):
   tab.locator('[data-compose]:visible').first.click();expect(tab.locator('#compose-dialog')).to_be_visible()
   tab.locator('#caption').fill(text)
   tab.locator('#media-input').set_input_files(files=[{'name':'g3-draft.png','mimeType':'image/png','buffer':photo}])
   expect(tab.locator('#media-preview img')).to_have_count(1)
   return wait_draft(owner,text)
  def close_compose():
   tab.keyboard.press('Escape');expect(tab.locator('#compose-dialog')).not_to_be_visible()
  def logout():
   button=tab.locator('#logout')
   if not button.is_visible():tab.locator('details.appbar__apps > summary').click()
   expect(button).to_be_visible();button.click();tab.wait_for_url('**/login**',timeout=15000)

  # ① 커뮤니티 홈(사진 기록 모듈 없음, app.js 만)의 로그아웃
  new_session('employee');as_user('employee');memories()
  p=write_draft(A,'G-3 A 첫 임시 글')
  check(p['mine'] is not None and p['mine']['files']==1,'G-3 precondition: travel draft with text and photo stored under A')
  close_compose()
  tab.goto(base+'/',wait_until='networkidle')
  check(tab.evaluate("!document.querySelector('script[src*=\"/assets/community/memories.js\"]')") and probe(A)['mine'] is not None,
        'G-3 precondition: community home has no memories module and A draft is still there')
  logout()
  p=probe(A)
  check(p['mine'] is None and A not in (p['keys'] or []),'G-3 ① community-home shell logout (app.js) removes A travel draft and photo')

  # ② 다시 로그인한 다른 사람(B)에게 이전 임시 글 0
  new_session('other');as_user('other');memories()
  tab.wait_for_timeout(500)
  p=probe(B)
  check(tab.locator('#resume-draft').is_hidden() and A not in (p['keys'] or []) and p['mine'] is None,'G-3 ② B logging in afterwards sees no previous travel draft (resume hidden, no A entry)')
  p=write_draft(B,'G-3 B 임시 글')
  check(p['mine'] is not None and p['mine']['files']==1,'G-3 precondition: B can keep its own travel draft')
  close_compose()

  # ③·④ 사진 기록 화면 머리(모아 머리)의 로그아웃 — app.js 가 `qaredi:logout` 을 알린 뒤 지운다
  new_session('employee');as_user('employee');memories()
  p=write_draft(A,'G-3 A 두 번째 임시 글')
  check(p['mine'] is not None and B in (p['keys'] or []),'G-3 precondition: A and B drafts both present before A logs out on the memories page')
  menu=tab.locator('details.appbar__apps > summary')
  try:menu.click(trial=True,timeout=1500);reachable=True
  except Exception:reachable=False
  check(tab.evaluate("!!document.querySelector('#compose-dialog:modal')") and not reachable,'G-3 ④ while a draft is being written the compose dialog is modal and the header menu (logout) is not reachable')
  close_compose()
  tab.evaluate("document.addEventListener('qaredi:logout',()=>localStorage.setItem('qf13h-logout-event','seen'))")
  check(tab.evaluate("!!document.querySelector('script[src*=\"/assets/community/memories.js\"]')"),'G-3 precondition: the memories module is on this page')
  logout()
  p=probe(A)
  check(tab.evaluate("localStorage.getItem('qf13h-logout-event')")=='seen','G-3 ③ memories-page header logout goes through the qaredi:logout path')
  tab.evaluate("localStorage.removeItem('qf13h-logout-event')")
  check(p['mine'] is None and A not in (p['keys'] or []),'G-3 ③ memories-page header logout removes A travel draft and photo')
  check(B in (p['keys'] or []),'G-3 ③ another person entry (B) is not touched by A logout')

  # ⑤ B 로그아웃 → B 항목 0
  as_user('other');memories()
  try:expect(tab.locator('#resume-draft')).to_be_visible(timeout=5000);offered=True
  except AssertionError:offered=False
  check(offered,'G-3 precondition: B is offered only its own kept draft')
  logout()
  p=probe(B)
  check(p['mine'] is None and not (p['keys'] or []),'G-3 ⑤ B logout removes B travel draft; no travel draft entries remain')
  check(not errors and not bad,'G-3 ⑥ no page errors or 5xx')
  check(code_console(console)==[],'G-3 ⑥ console output from page scripts 0')
  # 이미 있던 것만: /favicon.ico(링크 없음) · 로그인 화면의 lake.jpg(시험 라우터 export_router.php 가 .jpg 를 내주지 않는다 — 운영 nginx 는 낸다)
  check(resource_lines(console)<={('404','/favicon.ico'),('404','/tbox/assets/moa/lake.jpg')},'G-3 ⑥ only pre-existing browser resource lines (favicon, test-router jpg)')
  tab.screenshot(path=str(out/'qf13h-after-logout.png'))
  ctx.close()

  # ⑤-2 임시 글이 없던 브라우저에서 로그아웃 — 빈 저장소를 만들지 않는다
  ctx=browser.new_context(viewport={'width':390,'height':844},locale='ko-KR',user_agent=UA)
  tab=ctx.new_page();console.clear();errors.clear();bad.clear();watch(tab)
  new_session('other');as_user('other')
  tab.goto(base+'/',wait_until='networkidle')
  check(not tab.evaluate("indexedDB.databases().then((l)=>l.some((d)=>d.name==='"+DB_NAME+"'))"),'G-3 precondition: fresh browser has no travel draft store')
  button=tab.locator('#logout')
  if not button.is_visible() and tab.locator('#appbar-apps').is_visible():tab.locator('#appbar-apps').click()  # 좁은 화면: 서랍 + 앱 메뉴
  if not button.is_visible():tab.locator('details.appbar__apps > summary').click()
  expect(button).to_be_visible();button.click();tab.wait_for_url('**/login**',timeout=15000)
  check(not tab.evaluate("indexedDB.databases().then((l)=>l.some((d)=>d.name==='"+DB_NAME+"'))"),'G-3 ⑤ logout on a browser without drafts does not create an empty travel draft store (390px)')
  check(not errors and not bad and code_console(console)==[],'G-3 ⑥ 390px logout: no page errors, 5xx or console output from page scripts')
  ctx.close();browser.close()
finally:
 (out/'qf13h-privacy-results.json').write_text(json.dumps(checks,ensure_ascii=False,indent=2))
 print('RESULT pass='+str(sum(x['pass'] for x in checks))+' fail='+str(sum(not x['pass'] for x in checks)),flush=True)
