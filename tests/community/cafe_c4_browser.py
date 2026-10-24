#!/usr/bin/env python3
"""카페 묶음 C4 · C5 · C6 — 사용자 지적 셋의 수락 시험 (실제 PHP · PostgreSQL + Chromium · 격리 틀 memories_harness.py 만)

  python3 tests/community/memories_harness.py --suite cafe_c4_browser.py --output <결과 폴더>
  (묶음만 고르기: CAFE_C4_ONLY=http,image,mention,c5,c6)

지시서 docs/cafe-directive.md 「C4」 · 「C5」 · 「C6」 · DESIGN 2.26 「검사 중 미리보기」 · 2.27 「남긴 댓글의 멘션」 · 2.19 ⑨ · 2.30 · 커뮤니티 ARCHITECTURE 24장.
합성 DB · 임시 복사본 · 로컬 php -S 만 친다(운영 · 운영 DB · 시드 계정 0). 계정은 fixture 의 claude_ 다섯 · UA tbox-review/<날짜>.
  W = claude_employee01(팀 A — 문서 주인 · 그림 올리는 사람) · A = claude_manager01(팀 A — 보기 공유 받음) · E = claude_employee02(팀 B — 못 봄) ·
  M = claude_admin01(관리자) · G = claude_guest01(손님)
결과 파일에는 단언 이름 · 참/거짓 · 측정값만 — 세션 · CSRF · 비밀번호 0. 스크린샷은 shots/(필요한 것만).

  [http]     C4 창구 — GET /api/pages/{id}/attachments(볼 수 있는 사람 200 · 지도 한 칸 · 못 보는 사람 · 손님 · 특권 404 · 다른 문서 첨부 0) ·
             comments_list mention_status(있고 볼 수 있음 ok+이름 · 없음 · 못 봄 · 손님 · 특권 ok:false 이름 0 · `@아이디.te` 서버 규칙 · 익명게시판 {} · 손님 시점 {} ·
             질의 수 댓글 3 = 30)
  [image]    C4 편집 중 그림 — 1440 · 412: 붙여넣기 · 끌어놓기 · 「이 문서에 올리기」 → 즉시 img(data:) + 「검사 중 · 나만 보여요」 · 손잡이로 폭 →
             clean 이 되면 5초 안에 서버 img · 배지 0 · 폭 그대로 · 저장 뒤 다시 열어도 그림 · 새로 고침 뒤 pending 이면 상자 → clean 이면 바뀜 ·
             다른 사람 화면은 clean 전 상자 · 작업 칸 편집기도 같은 길 · 콘솔 0
  [mention]  C4 남긴 댓글 — 전체 화면 · 작업 칸: 확인됨 파랑(.mention-mark--ok + aria-label 「… 알림이 갔어요」) · 못 찾음 빨강 + 물결 · `@아이디.te` 한 토큰 ·
             대비 ≥ 4.5(밝게 · 어둡게 · 고대비) · 넘침 0
  [c5]       C5 접힌 사이드바 — 업무 · 이야기 · 자료실 × 1440 · 1280 · 960: 쿠키 없음 → 첫 HTML(서버 응답 원문) data-sidebar="collapsed" · ☰ aria 첫 값 ·
             첫 프레임부터 접힘 · 레일 64 / ☰ 펼침 → 쿠키 open → 다른 화면 · 새로 고침 펼침(첫 HTML 속성 0) · 다시 접기 → 쿠키 지움 → 접힘 /
             접힌 「＋」: 올리기 · 초점 때 단추 안 글자 0 · 오른쪽 풍선 「새로 만들기」(「홈」 풍선과 같은 자리 규칙 — CDP 로 ::after 상자) · 누르면 메뉴 /
             펼침 240 에서 「＋ 새로 만들기」 글자 · 412 서랍 그대로(쿠키 무관) · 옛 localStorage 키 지움 · 넘침 0 · 콘솔 0
  [c6]       C6 오른쪽 위 셋 — 1440 · 1280 · 960 × 밝게 · 어둡게 · 고대비: 순서 메신저 · 앱 · 칩 · 높이 44 · 사이 8 · 메신저 단추 열고 닫기(aria-expanded) · Esc 초점 ·
             안 읽은 수 배지 = 메신저 단추(앱 단추 0) · 앱 판 = 앱과 도구만(메신저 · 내 계정 · 내 활동 · 로그아웃 0 · 관리자 타일은 관리자만) ·
             칩 → 내 계정 판(머리 이름 · 아이디 · 역할 · 내 계정 · 내 활동 · 로그아웃 · 앱 판과 같은 폭 · 같은 클래스) · 두 판 동시 0 · 바깥 · Esc 닫힘 ·
             타일 이동 · /tbox/settings aria-current · 로그아웃 그대로 / 412: 윗줄 메신저 · 아바타 → 판 전체 폭 · 서랍 · 앱 판 · 계정 판 하나만 /
             손님 · 직원 · 관리자 · 대비 4.5 · 넘침 0 · 콘솔 0 · 누름 자리 < 44 0(검정 단추 「댓글 달기」 포함 — design.css 반짝 조각 보정)
"""
from pathlib import Path
exec(compile(Path(__file__).with_name('memories_http.py').read_text().split('try:\n for u in users:')[0], __file__, 'exec'))
import re, secrets, struct, zlib
from urllib.parse import urlsplit
from playwright.sync_api import sync_playwright
assert str(root).startswith('/tmp/qaredi-stage13-') and manifest['database'].startswith('tbox_memories_test_')
host = urlsplit(site).hostname
UA = 'tbox-review/' + time.strftime('%Y-%m-%d')
ONLY = set(filter(None, os.environ.get('CAFE_C4_ONLY', '').split(',')))
shots = out / 'shots'
shots.mkdir(exist_ok=True)
metrics = {}
MIGRATION = root / 'db/migrations/2026-10-14_community_cafe.sql'
KEY = {'W': 'employee', 'A': 'manager', 'E': 'other', 'M': 'admin', 'G': 'guest'}
PENDING_MINE = '검사 중 · 나만 보여요'


def check(ok, name):
    checks.append({'pass': bool(ok), 'name': name})
    print(('PASS ' if ok else 'FAIL ') + name, flush=True)
    return bool(ok)


def want(group): return not ONLY or group in ONLY


def guard(name, fn):
    try:
        fn()
    except Exception as error:   # noqa: BLE001 — 시험 흐름의 어떤 예외든 실패로 센다
        check(False, name + ' raised ' + type(error).__name__ + ': ' + str(error).splitlines()[0][:200])


def uid(k): return int(users[KEY[k]]['id'])
def lit(v): return "'" + str(v).replace("'", "''") + "'"
def api(method, path, body=None, who='W', expected=200): return call(method, path, body, user=KEY[who], expected=expected)
def rawk(method, path, body=None, who='W', **kw): return raw(method, path, body, KEY[who], **kw)


def psql_file(path):
    env = {k: v for k, v in os.environ.items() if not k.startswith('PG')}
    env['PGPASSFILE'] = str(Path.home() / '.config/qaredi/pgpass-testdb')
    return subprocess.run(['psql', '-h', '127.0.0.1', '-U', 'tbox_testdb', '-w', '-X', '-At', '-d', manifest['database'],
                           '-v', 'ON_ERROR_STOP=1', '-f', str(path)], env=env, capture_output=True, text=True)


def php(code):
    prelude = ("require 'src/Env.php'; Env::load(" + repr(str(root / '.env')) + "); require 'src/bootstrap.php';"
               "require_once 'src/community/routes.php';")
    done = subprocess.run(['php', '-r', prelude + code], cwd=root, capture_output=True, text=True, timeout=120)
    if done.returncode != 0:
        print('PHP_ERR=' + done.stderr[-600:], flush=True)
    return done.stdout.strip()


def new_session(k):
    token = secrets.token_hex(32)
    sql("INSERT INTO sessions(id,user_id,expires_at,ip,user_agent) VALUES('" + hashlib.sha256(token.encode()).hexdigest() + "'," + str(uid(k))
        + ",clock_timestamp()+interval '8 hours','127.0.0.1','" + UA + "')")
    return token


def cookie(token, extra=None):
    jar = [{'name': '__Host-tbox_session', 'value': token, 'domain': host, 'path': '/', 'secure': True, 'httpOnly': True, 'sameSite': 'Lax'}]
    for name, value in (extra or {}).items():
        jar.append({'name': name, 'value': value, 'domain': host, 'path': '/'})
    return jar


def until(page, js, timeout=10000):
    """CSP(unsafe-eval 0) 아래에서 기다리기 — wait_for_function 대신 evaluate 를 되풀이한다."""
    end = time.time() + timeout / 1000
    while True:
        if page.evaluate(js):
            return True
        if time.time() > end:
            raise TimeoutError('until: ' + js[:90])
        page.wait_for_timeout(100)


def code_console(lines):
    return [t for t, _ in lines if not t.startswith('Failed to load resource') and not t.startswith('[DOM] ')]


class Tab:
    def __init__(self, ctx):
        self.page = ctx.new_page()
        self.console, self.errors, self.bad, self.requests = [], [], [], []
        p = self.page
        p.on('console', lambda m: self.console.append((m.text, urlsplit(m.location.get('url', '')).path)) if m.type in ('error', 'warning') else None)
        p.on('pageerror', lambda e: self.errors.append(str(e)))
        p.on('response', lambda r: self.bad.append(urlsplit(r.url).path) if r.status >= 500 else None)
        p.on('request', lambda r: self.requests.append(urlsplit(r.url).path))

    def go(self, path, settle=400):
        r = self.page.goto(site + path, wait_until='networkidle')
        self.page.wait_for_timeout(settle)
        return r

    def clean(self, tag=''):
        ok = not self.errors and not self.bad and code_console(self.console) == []
        if not ok:
            metrics['unclean-' + tag + '-' + str(len(metrics))] = {'errors': [e[:200] for e in self.errors], 'bad': self.bad, 'console': [c[0][:200] for c in self.console]}
        return ok


def synth_png(w, h):
    """합성 그림(가로 그러데이션) — 스크린샷에서 보이게 1×1 보다 크게. 바깥 자료 0"""
    rows = b''.join(b'\x00' + b''.join(bytes(((x * 255) // w, 120 + (y * 100) // h, 200)) for x in range(w)) for y in range(h))
    chunk = lambda tag, data: struct.pack('>I', len(data)) + tag + data + struct.pack('>I', zlib.crc32(tag + data) & 0xffffffff)
    return b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('>IIBBBBB', w, h, 8, 2, 0, 0, 0)) + chunk(b'IDAT', zlib.compress(rows, 9)) + chunk(b'IEND', b'')


PNG = synth_png(240, 120)
PNG_B64 = base64.b64encode(PNG).decode()

# 대비 — 글자색 · 그 요소부터 위로 바탕을 겹쳐 실제로 보이는 바탕(반투명 토큰 포함)을 만든 뒤 WCAG 비
CONTRAST_JS = r"""(el) => {
  const parse = (c) => { const s = String(c); let m = s.match(/rgba?\(([^)]+)\)/);
    if (m) { const v = m[1].split(/[ ,/]+/).filter(Boolean).map(Number); return [v[0], v[1], v[2], v.length > 3 ? v[3] : 1]; }
    m = s.match(/color\(srgb ([^)]+)\)/);   // color-mix(in srgb …) 의 계산값
    if (m) { const v = m[1].split(/[ /]+/).filter(Boolean).map(Number); return [v[0] * 255, v[1] * 255, v[2] * 255, v.length > 3 ? v[3] : 1]; }
    return [0, 0, 0, 0]; };
  const lum = (rgb) => { const f = (x) => { x /= 255; return x <= 0.03928 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4); }; return 0.2126 * f(rgb[0]) + 0.7152 * f(rgb[1]) + 0.0722 * f(rgb[2]); };
  const over = (top, under) => { const a = top[3]; return [0, 1, 2].map(i => top[i] * a + under[i] * (1 - a)).concat(1); };
  const layers = []; for (let n = el; n && n.nodeType === 1; n = n.parentElement) { const b = parse(getComputedStyle(n).backgroundColor); if (b[3] > 0) layers.push(b); if (b[3] >= 1) break; }
  let bg = [255, 255, 255, 1]; for (let i = layers.length - 1; i >= 0; i--) bg = over(layers[i], bg);
  const fg = over(parse(getComputedStyle(el).color), bg); const l1 = lum(fg), l2 = lum(bg);
  return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
}"""


# 누름 자리(DESIGN 4장 ①) — 보이는 누르는 것의 (자기 상자 ∪ ::before) ≥ 44 (cafe_c2_browser 와 같은 셈 — C6 보정 뒤에는 검정 단추도 따로 세지 않는다)
AUDIT_JS = r"""([touch, scope]) => {
  const vis = el => { if (!el.isConnected) return false; const r = el.getBoundingClientRect(); const s = getComputedStyle(el);
    return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && s.display !== 'none' && !el.closest('[hidden]:not(#sidebar)'); };
  const small = [];
  if (touch) {
    const sel = ['button', 'summary', 'a[class]', 'input[type=checkbox]', 'input[type=radio]'];
    for (const root of document.querySelectorAll(scope)) for (const el of root.querySelectorAll(sel.join(','))) {
      if (!vis(el) || el.closest('.doc') || el.closest('dialog:not([open])')) continue;
      const r = el.getBoundingClientRect(); const b = getComputedStyle(el, '::before');
      let w = r.width, h = r.height;
      if (b.content && b.content !== 'none' && b.position === 'absolute') {
        const px = v => { const n = parseFloat(v); return isNaN(n) ? 0 : n; };
        w = r.width - px(b.left) - px(b.right); h = r.height - px(b.top) - px(b.bottom);
      }
      if ((el.matches('input[type=checkbox], input[type=radio]')) && el.closest('label')) { const lr = el.closest('label').getBoundingClientRect(); w = Math.max(w, lr.width); h = Math.max(h, lr.height); }
      if (w < 43.5 || h < 43.5) small.push((el.id || el.className || el.tagName).toString().slice(0, 60) + ' ' + Math.round(w) + 'x' + Math.round(h));
    }
  }
  return {small: small.slice(0, 12), smallCount: small.length};
}"""

try:
    for k, key in KEY.items():
        users[key]['username'] = sql('SELECT username FROM users WHERE id=' + str(users[key]['id']))
        call('GET', '/api/me/space', user=key)
    UN = {k: users[KEY[k]]['username'] for k in KEY}
    sql("INSERT INTO community.usage_guides(user_id,version,status,context) SELECT id,1,'dismissed','overview' FROM users ON CONFLICT DO NOTHING")
    DN = {k: sql('SELECT coalesce(display_name, \'\') FROM users WHERE id=' + str(uid(k))) for k in KEY}

    # ─── 준비 — 첫 게시판 셋 + 카페 이주 다시(익명게시판 — mention_status {} 대조) ─────────────
    sql("INSERT INTO community.spaces (kind,name,description,access_level,notice,board_kind) VALUES "
        "('board','공지사항','합성 공지','public',true,'notice'),('board','자유게시판','자유롭게 이야기해요','internal',false,'discussion'),"
        "('board','질문과 답변','합성 질문','internal',false,'qa')")
    mig = psql_file(MIGRATION)
    check(mig.returncode == 0, 'setup: cafe migration re-run on the populated DB → rc 0')
    ANON = int(sql("SELECT id FROM community.spaces WHERE kind='board' AND board_kind='anonymous'"))

    def new_page(title, who='W'):
        return api('POST', '/api/pages', {'title': title}, who, 201)['page']['id']

    def share(pid, k, level='view'):
        r = rawk('PUT', '/api/pages/' + pid + '/shares/' + UN[k], {'level': level}, 'W')
        check(r.status_code in (200, 201), 'setup: share %s → %s (%d)' % (level, k, r.status_code))

    def upload(pid, name='그림.png', data=None, who='W', mime='image/png'):
        r = rawk('POST', '/api/pages/' + pid + '/attachments', None, who, files={'file': (name, data if data is not None else PNG, mime)})
        check(r.status_code == 201, 'setup: upload %s → 201 (%d)' % (name, r.status_code))
        return r.json()['id']

    def set_scan(att, status):
        sql("UPDATE community.page_attachments SET scan_status=" + lit(status) + " WHERE public_id=" + lit(att))

    def att_rows(pid):
        out_ = sql("SELECT public_id||'|'||original_name||'|'||scan_status FROM community.page_attachments WHERE page_id=(SELECT id FROM community.pages WHERE public_id="
                   + lit(pid) + ") ORDER BY created_at, id")
        return [x for x in out_.splitlines() if x]

    def blocks(pid):
        return json.loads(sql("SELECT doc::text FROM community.pages WHERE public_id=" + lit(pid)))['blocks']

    # ─── [http] 창구 ───────────────────────────────────────────────────────────────────
    if want('http'):
        def http_part():
            P1 = new_page('C4 첨부 지도 시험')
            P2 = new_page('C4 다른 문서')
            share(P1, 'A')
            a1 = upload(P1, '검사대기.png')
            a2 = upload(P1, '메모.txt', '합성 메모\n'.encode(), mime='text/plain')
            b1 = upload(P2, '다른문서.png')
            set_scan(a1, 'pending')
            r = rawk('GET', '/api/pages/' + P1 + '/attachments', None, 'W')
            j = r.json() if r.status_code == 200 else {}
            m = j.get('attachment_titles', {})
            metrics['http_list_W'] = {'status': r.status_code, 'keys': sorted(j.keys()), 'map': m}
            check(r.status_code == 200 and sorted(j.keys()) == ['attachment_titles'], 'http list: owner W → 200 · body has the one key attachment_titles %s' % json.dumps(sorted(j.keys())))
            check(set(m.keys()) == {a1, a2} and b1 not in m, 'http list: this document\'s attachments only (other document\'s attachment 0)')
            check(m.get(a1) == {'name': '검사대기.png', 'size': len(PNG), 'image': True, 'scan_status': 'pending'} and m.get(a2, {}).get('image') is False
                  and m.get(a2, {}).get('scan_status') == 'clean', 'http list: entry {name,size,image,scan_status} — pending image · clean file %s' % json.dumps(m.get(a1), ensure_ascii=False))
            got = api('GET', '/api/pages/' + P1, None, 'W')
            check(got.get('attachment_titles') == m, 'http list: same map as pages_get attachment_titles')
            ra = rawk('GET', '/api/pages/' + P1 + '/attachments', None, 'A')
            check(ra.status_code == 200 and set(ra.json()['attachment_titles'].keys()) == {a1, a2}, 'http list: view-shared A → 200 (document read rule)')
            for k_, why in (('E', 'other team — cannot read'), ('G', 'guest — cannot read'), ('M', 'admin — privilege-only read')):
                rr = rawk('GET', '/api/pages/' + P1 + '/attachments', None, k_)
                check(rr.status_code == 404 and 'attachment_titles' not in rr.text, 'http list: %s (%s) → 404 · no map' % (k_, why))
            rr = rawk('GET', '/api/pages/' + str(uuid.uuid4()) + '/attachments', None, 'W')
            check(rr.status_code == 404, 'http list: unknown document → 404')
            rr = raw('GET', '/api/pages/' + P1 + '/attachments', None, None)
            check(rr.status_code in (401, 403), 'http list: no session → %d (login required)' % rr.status_code)
            set_scan(a1, 'clean')
            check(rawk('GET', '/api/pages/' + P1 + '/attachments', None, 'W').json()['attachment_titles'][a1]['scan_status'] == 'clean', 'http list: scan → clean is reflected on the next ask')
            # 검사 전 서버 사본은 여전히 내보내지 않는다(raw · download 423)
            set_scan(a1, 'pending')
            for part in ('raw', 'download'):
                rr = rawk('GET', '/api/pages/' + P1 + '/attachments/' + a1 + '/' + part, None, 'W')
                check(rr.status_code == 423, 'http: pending attachment %s → 423 (server copy is not served before the scan)' % part)
            set_scan(a1, 'infected')
            check(rawk('GET', '/api/pages/' + P1 + '/attachments', None, 'W').json()['attachment_titles'][a1]['scan_status'] == 'infected', 'http list: infected reflected')

            # ── mention_status ──
            body1 = ('안녕 @%s 그리고 @%s 또 @nobodyc4zz 그리고 @%s.te 끝 @%s @%s @%s.' % (UN['A'], UN['E'], UN['A'], UN['M'], UN['G'], UN['W']))
            api('POST', '/api/pages/' + P1 + '/comments', {'body': body1}, 'W', 201)
            api('POST', '/api/pages/' + P1 + '/comments', {'body': '짧은 토큰 @ab 은 판단 밖 · 메일 x@' + UN['A'] + ' 도 아님'}, 'A', 201)
            lst = api('GET', '/api/pages/' + P1 + '/comments', None, 'W')
            ms = lst.get('mention_status', {})
            metrics['mention_status_W'] = ms
            want_keys = {UN['A'], UN['E'], 'nobodyc4zz', UN['A'] + '.te', UN['M'], UN['G'], UN['W']}
            check(set(ms.keys()) == want_keys, 'mention_status: only ids appearing in this list (server token rule — `@x.te` one token · `@ab` · `x@y` 0) %s' % json.dumps(sorted(ms.keys())))
            check(ms.get(UN['A']) == {'ok': True, 'display_name': DN['A'] or None} and ms.get(UN['W'], {}).get('ok') is True,
                  'mention_status: existing · can-read ids → ok:true + display_name (A shared · W owner) %s' % json.dumps(ms.get(UN['A']), ensure_ascii=False))
            for name, why in ((UN['E'], 'cannot read the document'), ('nobodyc4zz', 'no such id'), (UN['A'] + '.te', 'glued letters → no such id'),
                              (UN['M'], 'admin privilege only'), (UN['G'], 'guest (not a writer)')):
                check(ms.get(name) == {'ok': False}, 'mention_status: %s → {ok:false} only (no name · existence not revealed)' % why)
            check(api('GET', '/api/pages/' + P1 + '/comments', None, 'A').get('mention_status') == ms, 'mention_status: same map for another reader (A)')
            # 손님 시점 — 볼 수 있는 게시판 글(공지 — public)에서 빈 지도
            NOTICE = int(sql("SELECT id FROM community.spaces WHERE kind='board' AND name='공지사항'"))
            N = api('POST', '/api/boards/' + str(NOTICE) + '/pages', {'title': 'C4 공지'}, 'M', 201)['page']['id']
            api('POST', '/api/pages/' + N + '/comments', {'body': '공지 확인 @' + UN['W']}, 'M', 201)
            rg = rawk('GET', '/api/pages/' + N + '/comments', None, 'G')
            rw = rawk('GET', '/api/pages/' + N + '/comments', None, 'W').json().get('mention_status')
            check(rg.status_code == 200 and rg.json().get('mention_status') == {} and rw == {UN['W']: {'ok': True, 'display_name': DN['W'] or None}},
                  'mention_status: guest viewer → {} (like mention-candidates M-1) · employee viewer → resolved %s' % json.dumps(rw, ensure_ascii=False))
            # 익명게시판 — 빈 지도
            AP = api('POST', '/api/boards/' + str(ANON) + '/pages', {'title': 'C4 익명 글'}, 'W', 201)['page']['id']
            api('POST', '/api/pages/' + AP + '/comments', {'body': '익명 @' + UN['A']}, 'A', 201)
            check(api('GET', '/api/pages/' + AP + '/comments', None, 'W').get('mention_status') == {}, 'mention_status: anonymous board → {} (mentions off)')
            # 질의 수 — 댓글 3 · 30 (같은 아이디 셋을 부르는 댓글)
            QA_, QB_ = new_page('C4 질의 3'), new_page('C4 질의 30')
            share(QA_, 'A')
            share(QB_, 'A')
            line = '@%s @%s @nobodyc4zz 확인' % (UN['A'], UN['E'])
            for i in range(3):
                api('POST', '/api/pages/' + QA_ + '/comments', {'body': line + ' ' + str(i)}, 'W', 201)
            for i in range(30):
                api('POST', '/api/pages/' + QB_ + '/comments', {'body': line + ' ' + str(i)}, 'W', 201)

            def count(pid):
                code = (
                    "final class C4CountStmt extends PDOStatement { public static int $n = 0; protected function __construct() {}"
                    " public function execute(?array $p = null): bool { self::$n++; return parent::execute($p); } }"
                    "$db = Db::instance(); $open = new ReflectionMethod(Db::class, 'open'); $open->setAccessible(true);"
                    "$pdo = $open->invoke($db, false); $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [C4CountStmt::class, []]);"
                    "$prop = new ReflectionProperty(Db::class, 'pdo'); $prop->setAccessible(true); $prop->setValue($db, $pdo);"
                    "$user = community_user_by_id(" + str(uid('W')) + ") + ['reauth_fresh' => false];"
                    "C4CountStmt::$n = 0; $params = [" + repr(pid) + "]; ob_start(); include 'src/community/handlers/comments_list.php'; $out = ob_get_clean();"
                    "$j = json_decode($out, true); echo json_encode(['queries' => C4CountStmt::$n, 'comments' => count($j['comments'] ?? []), 'status' => $j['mention_status'] ?? null]);")
                o = php(code)
                try:
                    return json.loads(o.splitlines()[-1])
                except Exception:   # noqa: BLE001
                    print('COUNT_OUT=' + o[-400:], flush=True)
                    return {'queries': -1}
            c3, c30 = count(QA_), count(QB_)
            metrics['mention_queries'] = {'3': c3, '30': c30}
            check(c3.get('comments') == 3 and c30.get('comments') == 30 and c3.get('queries', -1) == c30.get('queries', -2) and c3.get('queries', 0) > 0,
                  'mention_status: query count fixed — comments 3 → %s · 30 → %s' % (c3.get('queries'), c30.get('queries')))
            check(c30.get('status', {}).get(UN['A'], {}).get('ok') is True and c30.get('status', {}).get(UN['E']) == {'ok': False}, 'mention_status: counted run returns the same judgement')
        guard('http', http_part)

    with sync_playwright() as pw:
        browser = pw.chromium.launch(executable_path=os.environ.get('CHROMIUM_EXECUTABLE'))

        def context(k, width, height=900, touch=False, extra=None, sidebar_open=True):
            ctx = browser.new_context(viewport={'width': width, 'height': height}, has_touch=touch, is_mobile=touch, user_agent=UA, reduced_motion='reduce')
            jar = {'qaredi_motion': 'reduce'}
            if sidebar_open:
                jar['qaredi_sidebar'] = 'open'
            ctx.add_cookies(cookie(new_session(k), dict(jar, **(extra or {}))))
            return ctx

        PASTE_JS = """([sel, b64, name, type]) => { const bin = Uint8Array.from(atob(b64), c => c.charCodeAt(0)); const dt = new DataTransfer();
            dt.items.add(new File([bin], name, {type: type}));
            const ev = new ClipboardEvent('paste', {clipboardData: dt, bubbles: true, cancelable: true});
            document.querySelector(sel).dispatchEvent(ev); return ev.defaultPrevented; }"""
        DROP_JS = """([sel, b64, name, type]) => { const bin = Uint8Array.from(atob(b64), c => c.charCodeAt(0)); const dt = new DataTransfer(); dt.items.add(new File([bin], name, {type: type}));
            const ed = document.querySelector(sel); const r = ed.getBoundingClientRect();
            const ev = new DragEvent('drop', {dataTransfer: dt, clientX: r.left + 20, clientY: r.bottom - 6, bubbles: true, cancelable: true}); ed.dispatchEvent(ev); return ev.defaultPrevented; }"""
        FIGS_JS = r"""(sel) => [...document.querySelectorAll(sel + ' .doc-atom[data-atom="image"]')].map(a => { const f = a.querySelector('figure.doc-image'); const i = a.querySelector('img');
            const b = f ? f.querySelector(':scope > .badge') : null; const up = a.querySelector('.doc-upload__progress');
            return {src: i ? (i.getAttribute('src').startsWith('data:') ? i.getAttribute('src').slice(0, 22) : i.getAttribute('src')) : null, ok: !!i && i.complete && i.naturalWidth > 0,
                    badge: b ? b.textContent : null, preview: !!f && f.hasAttribute('data-preview'), cls: f ? [...f.classList].filter(c => c.startsWith('doc-image--')) : [],
                    box: up ? up.textContent : null, handle: !!a.querySelector('.doc-image__resize'), w: f ? Math.round(f.getBoundingClientRect().width) : 0}; })"""
        READ_JS = r"""(sel) => [...document.querySelectorAll(sel + ' figure.doc-image, ' + sel + ' .doc-file .doc-upload')].map(n => n.matches('figure')
            ? {img: (n.querySelector('img') || {}).getAttribute ? n.querySelector('img').getAttribute('src').slice(0, 80) : null, ok: !!n.querySelector('img') && n.querySelector('img').naturalWidth > 0,
               preview: n.hasAttribute('data-preview'), cls: [...n.classList].filter(c => c.startsWith('doc-image--'))}
            : {box: (n.querySelector('.doc-upload__progress') || {}).textContent || null})"""

        def drag_to(p, sel_atom, frac):
            """그림을 골라 손잡이를 본문 폭의 frac 자리까지 끈다"""
            atom = p.locator(sel_atom)
            atom.locator('img').click()
            p.wait_for_timeout(200)
            h = atom.locator('.doc-image__resize')
            hb = h.bounding_box()
            host_w = atom.evaluate('a => a.clientWidth')
            left = atom.evaluate("a => a.querySelector('figure').getBoundingClientRect().left")
            p.mouse.move(hb['x'] + hb['width'] / 2, hb['y'] + hb['height'] / 2)
            p.mouse.down()
            p.mouse.move(left + host_w * frac, hb['y'] + 10, steps=6)
            p.mouse.up()
            p.wait_for_timeout(400)

        # ─── [image] 편집 중 그림 ──────────────────────────────────────────────────────────
        if want('image'):
            def image():
                env_path = root / '.env'
                env_text = env_path.read_text()
                summary = {}
                try:
                    env_path.write_text(re.sub(r'^SCAN_ENABLED=.*$', 'SCAN_ENABLED=1', env_text, flags=re.M))
                    for width, touch in ((1440, False), (412, True)):
                        tag = str(width)
                        P = new_page('C4 그림 시험 ' + tag)
                        share(P, 'A')
                        ctx = context('W', width, 915 if touch else 900, touch=touch)
                        t = Tab(ctx)
                        p = t.page
                        t.go('/community/p/' + P)
                        p.wait_for_selector('#page-edit:not([hidden])', timeout=15000)
                        p.locator('#page-edit').click()
                        p.wait_for_selector('#page-editor .ProseMirror', timeout=8000)
                        p.locator('#page-editor .ProseMirror').click()
                        p.keyboard.press('End')
                        ED = '#page-editor .ProseMirror'
                        posts = []
                        # ① 붙여넣기
                        with p.expect_response(lambda r: r.url.endswith('/api/pages/' + P + '/attachments') and r.request.method == 'POST', timeout=20000) as up:
                            prevented = p.evaluate(PASTE_JS, [ED, PNG_B64, '붙여넣기.png', 'image/png'])
                        posts.append(up.value.json())
                        until(p, "() => document.querySelectorAll('#page-editor .doc-atom[data-atom=\"image\"]').length === 1", 10000)
                        # ② 끌어놓기
                        with p.expect_response(lambda r: r.url.endswith('/api/pages/' + P + '/attachments') and r.request.method == 'POST', timeout=20000) as up:
                            p.evaluate(DROP_JS, [ED, PNG_B64, '끌어놓기.png', 'image/png'])
                        posts.append(up.value.json())
                        until(p, "() => document.querySelectorAll('#page-editor .doc-atom[data-atom=\"image\"]').length === 2", 10000)
                        # ③ 「이 문서에 올리기」 탭
                        p.locator('#page-add-block').click()
                        p.locator('#block-list .block-pick[data-block-type="image"]').click()
                        p.locator('#block-insert').click()
                        p.wait_for_selector('#atom-dialog[open] [data-atom-tab="upload"]', timeout=5000)
                        with p.expect_response(lambda r: r.url.endswith('/api/pages/' + P + '/attachments') and r.request.method == 'POST', timeout=20000) as up:
                            p.locator('[data-atom-upload-input]').set_input_files(files=[{'name': '올리기.png', 'mimeType': 'image/png', 'buffer': PNG}])
                        posts.append(up.value.json())
                        until(p, "() => document.querySelectorAll('#page-editor .doc-atom[data-atom=\"image\"]').length === 3", 10000)
                        until(p, "() => [...document.querySelectorAll('#page-editor .doc-atom[data-atom=\"image\"] img')].filter(i => i.complete && i.naturalWidth > 0).length === 3", 10000)
                        figs = p.evaluate(FIGS_JS, '#page-editor')
                        summary[tag + '_preview'] = figs
                        check(prevented and all(x.get('scan_status') == 'pending' for x in posts), 'image %s: three uploads (paste · drop · 「이 문서에 올리기」) → 201 pending' % tag)
                        check(len(figs) == 3 and all(f['src'] == 'data:image/png;base64,' and f['ok'] and f['preview'] for f in figs),
                              'image %s: each shows the picture itself at once (img data: · drawn) — not the box %s' % (tag, json.dumps(figs, ensure_ascii=False)))
                        check(all(f['badge'] == PENDING_MINE and f['box'] is None and f['handle'] for f in figs), 'image %s: badge 「%s」 · box 0 · resize handle present' % (tag, PENDING_MINE))
                        bpos = p.evaluate("""() => { const f = document.querySelector('#page-editor figure.doc-image[data-preview]'); const b = f.querySelector(':scope > .badge');
                            const fr = f.getBoundingClientRect(), br = b.getBoundingClientRect(); return {dx: Math.round(br.left - fr.left), dy: Math.round(br.top - fr.top), pos: getComputedStyle(b).position}; }""")
                        check(bpos['pos'] == 'absolute' and 0 <= bpos['dx'] <= 12 and 0 <= bpos['dy'] <= 12, 'image %s: badge at the image top-left %s' % (tag, json.dumps(bpos)))
                        net = [x for x in t.requests if '/attachments/' in x and (x.endswith('/raw') or x.endswith('/download'))]
                        check(net == [], 'image %s: no request for the server copy before the scan (raw · download 0)' % tag)
                        # 손잡이로 폭 — 첫 그림을 40% 로
                        drag_to(p, '#page-editor .doc-atom[data-atom="image"] >> nth=0', 0.41)
                        after = p.evaluate(FIGS_JS, '#page-editor')[0]
                        check(after['cls'] == ['doc-image--p40'] and after['preview'], 'image %s: resize handle works on the local copy → doc-image--p40 %s' % (tag, json.dumps(after['cls'])))
                        if width == 1440:
                            p.screenshot(path=str(shots / 'c4-preview-1440.png'))
                        # clean → 5초 안에 서버 그림
                        ids = [x['id'] for x in posts]
                        t0 = time.time()
                        for a in ids:
                            set_scan(a, 'clean')
                        until(p, "() => [...document.querySelectorAll('#page-editor .doc-atom[data-atom=\"image\"] img')].filter(i => i.getAttribute('src').indexOf('/attachments/') !== -1 && i.complete && i.naturalWidth > 0).length === 3", 9000)
                        took = round(time.time() - t0, 1)
                        figs2 = p.evaluate(FIGS_JS, '#page-editor')
                        summary[tag + '_swapped'] = {'figs': figs2, 'seconds': took}
                        check(took <= 7.0 and sorted(f['src'] for f in figs2) == sorted('/community/api/pages/' + P + '/attachments/' + a + '/raw' for a in ids),
                              'image %s: scan clean → server image (attachment raw) within %.1fs (poll 5s)' % (tag, took))
                        check(all(f['badge'] is None and not f['preview'] for f in figs2) and figs2[0]['cls'] == ['doc-image--p40'], 'image %s: badge 0 · width kept (p40)' % tag)
                        # 저장 → 다시 열어도 그림
                        p.locator('#page-done').click()
                        until(p, "() => !document.querySelector('#page-editor .ProseMirror')", 15000)
                        refs = [(b['type'], b.get('file_ref'), b.get('width_pct')) for b in blocks(P) if b['type'] == 'image']
                        first_ref = 'att:' + figs2[0]['src'].split('/attachments/')[1].split('/')[0]
                        check(sorted(r_[1] for r_ in refs) == sorted('att:' + a for a in ids) and [r_[2] for r_ in refs if r_[1] == first_ref] == [40],
                              'image %s: saved blocks = att: refs (width_pct 40 on the resized one) %s' % (tag, json.dumps(refs)))
                        t.go('/community/p/' + P)
                        until(p, "() => [...document.querySelectorAll('#page-doc img.doc-image__img')].filter(i => i.complete && i.naturalWidth > 0).length === 3", 10000)
                        rd = p.evaluate(READ_JS, '#page-doc')
                        check(len(rd) == 3 and rd[0]['cls'] == ['doc-image--p40'] and not any(x.get('preview') for x in rd), 'image %s: reopened → three server images (read view) %s' % (tag, json.dumps(rd)[:300]))
                        # 새로 고침 뒤 pending — 상자 → clean 이면 바뀜(읽기 화면의 같은 다시 묻기)
                        p.locator('#page-edit').click()
                        p.wait_for_selector('#page-editor .ProseMirror', timeout=8000)
                        p.locator('#page-editor .ProseMirror').click()
                        p.keyboard.press('Control+End')
                        with p.expect_response(lambda r: r.url.endswith('/api/pages/' + P + '/attachments') and r.request.method == 'POST', timeout=20000) as up:
                            p.evaluate(PASTE_JS, [ED, PNG_B64, '새로고침.png', 'image/png'])
                        a4 = up.value.json()['id']
                        until(p, "() => document.querySelectorAll('#page-editor .doc-atom[data-atom=\"image\"]').length === 4", 10000)
                        p.locator('#page-done').click()
                        until(p, "() => !document.querySelector('#page-editor .ProseMirror')", 15000)
                        t.go('/community/p/' + P)
                        until(p, "() => !!document.querySelector('#page-doc .doc-upload')", 8000)
                        rd = p.evaluate(READ_JS, '#page-doc')
                        boxes = [x for x in rd if 'box' in x]
                        check(boxes == [{'box': '검사 중이에요'}] and len(rd) == 4 and not any(x.get('preview') for x in rd),
                              'image %s: after reload (no local copy) → 「검사 중이에요」 box for the pending one · others images %s' % (tag, json.dumps(boxes, ensure_ascii=False)))
                        # 다른 사람(보기 공유 A) — clean 전 상자
                        ctx_a = context('A', width, 915 if touch else 900, touch=touch)
                        ta = Tab(ctx_a)
                        ta.go('/community/p/' + P)
                        until(ta.page, "() => !!document.querySelector('#page-doc .doc-upload')", 8000)
                        ra_ = ta.page.evaluate(READ_JS, '#page-doc')
                        check([x for x in ra_ if 'box' in x] == [{'box': '검사 중이에요'}] and ta.page.evaluate("document.querySelectorAll('img[src^=\"data:\"]').length") == 0,
                              'image %s: another reader (A) before clean → box · no data: image' % tag)
                        t0 = time.time()
                        set_scan(a4, 'clean')
                        until(p, "() => [...document.querySelectorAll('#page-doc img.doc-image__img')].filter(i => i.complete && i.naturalWidth > 0).length === 4", 9000)
                        took = round(time.time() - t0, 1)
                        until(ta.page, "() => [...document.querySelectorAll('#page-doc img.doc-image__img')].filter(i => i.complete && i.naturalWidth > 0).length === 4", 9000)
                        check(took <= 7.0 and p.locator('#page-doc .doc-upload').count() == 0 and ta.page.locator('#page-doc .doc-upload').count() == 0,
                              'image %s: clean → box becomes the server image (uploader %.1fs · reader A too)' % (tag, took))
                        lists = [x for x in t.requests if x.endswith('/api/pages/' + P + '/attachments')]
                        check(len(lists) >= 1, 'image %s: re-asks through GET /api/pages/{id}/attachments (%d asks)' % (tag, len(lists)))
                        ov = p.evaluate("document.documentElement.scrollWidth - innerWidth")
                        check(ov <= 0, 'image %s: horizontal overflow 0 (%d)' % (tag, ov))
                        check(t.clean('image-' + tag) and ta.clean('image-reader-' + tag), 'image %s: console 0 · page errors 0 · 5xx 0 (uploader · reader)' % tag)
                        ctx_a.close()
                        ctx.close()
                    # 작업 칸 편집기 — 같은 길(붙여넣기 → 사본 · clean → 서버 그림 · 칸의 「파일 첨부」 배지도)
                    T = new_page('C4 칸 그림 시험')
                    api('PATCH', '/api/pages/' + T + '/properties', {'status': 'todo'}, 'W')
                    ctx = context('W', 1440)
                    t = Tab(ctx)
                    p = t.page
                    t.go('/community/my-tasks?task=' + T)
                    p.wait_for_selector('#task-pane:not([hidden]) .task-pane__doc--editable', timeout=15000)
                    p.locator('#task-pane .task-pane__doc').click()
                    p.wait_for_selector('#task-pane .ProseMirror', timeout=8000)
                    p.locator('#task-pane .ProseMirror').click()
                    with p.expect_response(lambda r: r.url.endswith('/api/pages/' + T + '/attachments') and r.request.method == 'POST', timeout=20000) as up:
                        p.evaluate(PASTE_JS, ['#task-pane .ProseMirror', PNG_B64, '칸.png', 'image/png'])
                    ta_id = up.value.json()['id']
                    until(p, "() => [...document.querySelectorAll('#task-pane .doc-atom[data-atom=\"image\"] img')].filter(i => i.complete && i.naturalWidth > 0).length === 1", 10000)
                    f1 = p.evaluate(FIGS_JS, '#task-pane')
                    check(len(f1) == 1 and f1[0]['src'] == 'data:image/png;base64,' and f1[0]['badge'] == PENDING_MINE, 'image pane: task pane editor → local copy + badge %s' % json.dumps(f1, ensure_ascii=False))
                    t0 = time.time()
                    set_scan(ta_id, 'clean')
                    until(p, "() => [...document.querySelectorAll('#task-pane .doc-atom[data-atom=\"image\"] img')].filter(i => i.getAttribute('src').indexOf('/raw') !== -1 && i.naturalWidth > 0).length === 1", 9000)
                    check(time.time() - t0 <= 7.0 and p.evaluate(FIGS_JS, '#task-pane')[0]['badge'] is None, 'image pane: clean → server image · badge 0 (%.1fs)' % (time.time() - t0))
                    check(t.clean('image-pane'), 'image pane: console 0')
                    summary['pane'] = f1
                    ctx.close()
                finally:
                    env_path.write_text(env_text)
                metrics['image'] = summary
            guard('image', image)

        # ─── [mention] 남긴 댓글의 멘션 ────────────────────────────────────────────────────
        if want('mention'):
            def mention():
                MP = new_page('C4 멘션 표시 시험')
                share(MP, 'A')
                api('PATCH', '/api/pages/' + MP + '/properties', {'status': 'todo'}, 'W')
                body = '확인 부탁 @%s 그리고 @%s 또 @%s.te 그리고 @nobodyc4zz 끝.' % (UN['A'], UN['E'], UN['A'])
                api('POST', '/api/pages/' + MP + '/comments', {'body': body}, 'W', 201)
                MARKS_JS = r"""(sel) => [...document.querySelectorAll(sel)].map(m => ({cls: m.className, text: m.textContent, label: m.getAttribute('aria-label'), title: m.getAttribute('title'),
                    color: getComputedStyle(m).color, weight: getComputedStyle(m).fontWeight, deco: getComputedStyle(m).textDecorationStyle, line: getComputedStyle(m).textDecorationLine}))"""
                PROBE_JS = "(t) => { const d = document.createElement('div'); d.style.color = 'var(' + t + ')'; document.body.appendChild(d); const v = getComputedStyle(d).color; d.remove(); return v; }"
                res = {}
                for mode in ('light', 'dark', 'hc'):
                    sql('UPDATE users SET high_contrast=' + ('true' if mode == 'hc' else 'false') + ' WHERE id=' + str(uid('W')))
                    for width, touch in ((1440, False), (412, True)):
                        tag = '%d-%s' % (width, mode)
                        ctx = context('W', width, 915 if touch else 900, touch=touch, extra={'tbox_theme': 'dark' if mode == 'dark' else 'light'})
                        t = Tab(ctx)
                        p = t.page

                        def one():
                            t.go('/community/p/' + MP)
                            p.wait_for_selector('#page-comments .list__body .mention-mark--ok', timeout=10000)
                            mk = p.evaluate(MARKS_JS, '#page-comments .list__body [class^="mention-mark"]')
                            cr = [h.evaluate(CONTRAST_JS) for h in p.query_selector_all('#page-comments .list__body [class^="mention-mark"]')]
                            dark = p.evaluate(PROBE_JS, '--accent-dark')
                            res[tag] = {'marks': mk, 'contrast': [round(x, 2) for x in cr]}
                            check([(m['cls'], m['text']) for m in mk] == [('mention-mark--ok', '@' + UN['A']), ('mention-mark--miss', '@' + UN['E']), ('mention-mark--miss', '@' + UN['A'] + '.te'),
                                                                          ('mention-mark--miss', '@nobodyc4zz')],
                                  'mention %s: full-page comment — ok @A · miss @E · miss `@A.te` as one token (server rule) · miss @unknown %s' % (tag, json.dumps([m['text'] for m in mk])))
                            okl = mk[0]
                            check((okl['label'] or '').startswith('@' + UN['A'] + ' — ') and UN['A'] in (okl['title'] or '') and (okl['label'] or '').endswith(' — 알림이 갔어요'),
                                  'mention %s: ok aria-label 「… 알림이 갔어요」 %s' % (tag, okl['label']))
                            check(all((m['label'] or '').endswith('찾을 수 없는 사람 — 알림이 가지 않았어요') for m in mk[1:]), 'mention %s: miss aria-label 「찾을 수 없는 사람 — 알림이 가지 않았어요」' % tag)
                            check(okl['color'] == dark and okl['weight'] == '500' and all(m['deco'] == 'wavy' and 'underline' in m['line'] for m in mk[1:]),
                                  'mention %s: ok text --accent-dark · 500 · miss wavy underline %s' % (tag, json.dumps({'ok': okl['color'], 'dark': dark})))
                            check(min(cr) >= 4.5, 'mention %s: contrast ≥ 4.5 (min %.2f)' % (tag, min(cr)))
                            ov = p.evaluate("document.documentElement.scrollWidth - innerWidth")
                            check(ov <= 0, 'mention %s: horizontal overflow 0' % tag)
                            if width == 1440 and mode == 'light':
                                p.locator('#page-comments').screenshot(path=str(shots / 'c4-mention-light.png'))
                            if mode == 'light':
                                # 작업 칸 댓글 — 같은 함수
                                t.go('/community/my-tasks?task=' + MP)
                                p.wait_for_selector('#task-pane .task-comment .mention-mark--ok', timeout=10000)
                                pk = p.evaluate(MARKS_JS, '#task-pane .task-comment [class^="mention-mark"]')
                                pc = [h.evaluate(CONTRAST_JS) for h in p.query_selector_all('#task-pane .task-comment [class^="mention-mark"]')]
                                check([(m['cls'], m['text']) for m in pk] == [(m['cls'], m['text']) for m in mk] and pk[0]['label'] == okl['label'] and min(pc) >= 4.5,
                                      'mention %s: task pane comment — same marks · same labels · contrast ≥ 4.5 (min %.2f)' % (tag, min(pc)))
                                if width == 1440:
                                    p.locator('#task-pane .task-pane__activity').screenshot(path=str(shots / 'c4-mention-pane.png'))
                        guard('mention ' + tag, one)
                        check(t.clean('mention-' + tag), 'mention %s: console 0' % tag)
                        ctx.close()
                sql('UPDATE users SET high_contrast=false WHERE id=' + str(uid('W')))
                metrics['mention'] = res
            guard('mention', mention)

        # ─── [c5] 접힌 사이드바 「＋」 풍선 · 기본 접힘 ───────────────────────────────────────
        if want('c5'):
            def c5():
                SHELLS = (('work', '/community/workspace'), ('story', '/community/memories'), ('library', '/tbox/'))
                session_w = users[KEY['W']]['session']

                def first_html(path, extra_cookie=''):
                    h = {'User-Agent': UA, 'Cookie': '__Host-tbox_session=' + session_w + extra_cookie}
                    r = requests.get(site + path, headers=h, timeout=35)
                    body_tag = re.search(r'<body[^>]*>', r.text)
                    btn = re.search(r'<button id="sidebar-collapse"[^>]*>', r.text)
                    return r.status_code, body_tag.group(0) if body_tag else '', btn.group(0) if btn else ''

                for name, path in SHELLS:
                    st, body_tag, btn = first_html(path)
                    check(st == 200 and 'data-sidebar="collapsed"' in body_tag and 'aria-expanded="false"' in btn and 'aria-label="메뉴 펼치기"' in btn,
                          'c5 %s: no cookie → first HTML <body data-sidebar="collapsed"> · ☰ aria-expanded false · 「메뉴 펼치기」' % name)
                    st, body_tag, btn = first_html(path, '; qaredi_sidebar=open')
                    check(st == 200 and 'data-sidebar' not in body_tag and 'aria-expanded="true"' in btn and 'aria-label="메뉴 접기"' in btn,
                          'c5 %s: cookie open → first HTML without data-sidebar · ☰ aria-expanded true' % name)
                    st, body_tag, _ = first_html(path, '; qaredi_sidebar=%3Cb%3Eopen')
                    check('data-sidebar="collapsed"' in body_tag and '%3Cb%3E' not in body_tag and '<b>' not in body_tag, 'c5 %s: other cookie value → collapsed · value never echoed' % name)

                FRAMES_JS = """window.__c5frames = []; const tick = () => { const s = document.getElementById('sidebar');
                    window.__c5frames.push(s ? (document.body.getAttribute('data-sidebar') || 'open') : 'no-sidebar'); if (window.__c5frames.length < 40) requestAnimationFrame(tick); };
                    requestAnimationFrame(tick);"""
                RAIL_JS = """() => { const s = document.getElementById('sidebar'); const r = s.getBoundingClientRect();
                    const vis = (e) => { const q = e.getBoundingClientRect(); const cs = getComputedStyle(e); return q.width > 1 && q.height > 1 && cs.display !== 'none' && cs.visibility !== 'hidden'; };
                    const texts = [...s.querySelectorAll('.nav__item > span:not(.icon):not(.badge), summary.btn > span:not(.icon), #sidebar > .btn > span:not(.icon), .brand__name')].filter(vis).map(e => e.textContent.trim());
                    return {attr: document.body.getAttribute('data-sidebar'), w: Math.round(r.width), texts: texts, overflow: document.documentElement.scrollWidth - innerWidth,
                            exp: document.getElementById('sidebar-collapse').getAttribute('aria-expanded')}; }"""

                def pseudo_after(page, selector):
                    cdp = page.context.new_cdp_session(page)
                    try:
                        doc = cdp.send('DOM.getDocument', {'depth': -1, 'pierce': True})
                        nid = cdp.send('DOM.querySelector', {'nodeId': doc['root']['nodeId'], 'selector': selector})['nodeId']
                        node = cdp.send('DOM.describeNode', {'nodeId': nid, 'depth': 1})['node']
                        pe = [x for x in node.get('pseudoElements', []) if x.get('pseudoType') == 'after']
                        if not pe:
                            return None
                        q = cdp.send('DOM.getBoxModel', {'backendNodeId': pe[0]['backendNodeId']})['model']['border']
                        return {'left': round(min(q[0::2]), 1), 'right': round(max(q[0::2]), 1), 'top': round(min(q[1::2]), 1), 'bottom': round(max(q[1::2]), 1)}
                    finally:
                        cdp.detach()

                BOX_JS = "(sel) => { const r = document.querySelector(sel).getBoundingClientRect(); return {left: r.left, right: r.right, top: r.top, bottom: r.bottom}; }"
                INSIDE_JS = """() => { const s = document.querySelector('#nav-new-menu > summary'); const lab = [...s.children].find(e => !e.classList.contains('icon'));
                    const r = s.getBoundingClientRect(); const q = lab.getBoundingClientRect(); const cs = getComputedStyle(s);
                    return {w: Math.round(r.width), h: Math.round(r.height), labW: q.width, labH: q.height, transform: cs.transform, label: lab.textContent}; }"""
                res = {}
                for width in (1440, 1280, 960):
                    for name, path in SHELLS:
                        tag = '%s-%d' % (name, width)
                        ctx = context('W', width, sidebar_open=False)
                        ctx.add_init_script("localStorage.setItem('qaredi-sidebar-collapsed:v1:%s', '0');" % UN['W'] + FRAMES_JS)
                        t = Tab(ctx)
                        p = t.page

                        def one():
                            t.go(path)
                            p.wait_for_selector('#sidebar-collapse', timeout=10000)
                            frames = p.evaluate('window.__c5frames')
                            first = next((f for f in frames if f != 'no-sidebar'), None)
                            rail = p.evaluate(RAIL_JS)
                            old_keys = p.evaluate("Object.keys(localStorage).filter(k => k.startsWith('qaredi-sidebar-collapsed:v1:'))")
                            res[tag] = {'first': first, 'rail': rail}
                            check(first == 'collapsed' and 'open' not in frames and rail['attr'] == 'collapsed' and rail['w'] == 64 and rail['texts'] == [] and rail['exp'] == 'false',
                                  'c5 %s: no cookie → collapsed from the first frame · rail 64 · visible label 0 %s' % (tag, json.dumps({'first': first, 'w': rail['w'], 'texts': rail['texts']}, ensure_ascii=False)))
                            check(old_keys == [], 'c5 %s: old localStorage key removed (not read — a stored 0 did not expand)' % tag)
                            check(rail['overflow'] <= 0, 'c5 %s: horizontal overflow 0 (collapsed)' % tag)
                            if name == 'work':
                                # 접힌 「＋」 — 올리기
                                p.hover('#nav-new-menu > summary')
                                p.wait_for_timeout(250)
                                ins = p.evaluate(INSIDE_JS)
                                tip = pseudo_after(p, '#nav-new-menu > summary')
                                sb = p.evaluate(BOX_JS, '#nav-new-menu > summary')
                                p.hover('#nav-work-home')
                                p.wait_for_timeout(250)
                                home_tip = pseudo_after(p, '#nav-work-home')
                                hb = p.evaluate(BOX_JS, '#nav-work-home')
                                res[tag + '-plus'] = {'inside': ins, 'tip': tip, 'btn': sb, 'home_tip': home_tip, 'home': hb}
                                check(ins['w'] == 40 and ins['h'] == 40 and ins['labW'] <= 1 and ins['labH'] <= 1 and ins['transform'] == 'none',
                                      'c5 %s: hovered 「＋」 stays 40×40 · label text inside 0 (1px clip) · no lift %s' % (tag, json.dumps(ins, ensure_ascii=False)))
                                gap_plus = tip and round(tip['left'] - sb['right'], 1)
                                gap_home = home_tip and round(home_tip['left'] - hb['right'], 1)
                                mid = lambda b: (b['top'] + b['bottom']) / 2
                                check(tip is not None and home_tip is not None and tip['left'] >= sb['right'] and abs(gap_plus - gap_home) <= 1
                                      and abs(mid(tip) - mid(sb)) <= 1.5 and abs(mid(home_tip) - mid(hb)) <= 1.5 and (tip['right'] - tip['left']) > 40,
                                      'c5 %s: 「＋」 hover → bubble on the right outside the button (gap %s = 「홈」 gap %s · centred) %s' % (tag, gap_plus, gap_home, json.dumps(tip)))
                                if width == 1440:
                                    p.hover('#nav-new-menu > summary')
                                    p.wait_for_timeout(250)
                                    p.screenshot(path=str(shots / 'c5-plus-hover-1440.png'), clip={'x': 0, 'y': 0, 'width': 360, 'height': 360})
                                # 초점(키보드) — 같은 풍선 · 글자 0
                                p.mouse.move(width - 5, 880)
                                p.locator('#sidebar-collapse').focus()
                                p.keyboard.press('Tab')
                                p.wait_for_timeout(200)
                                focused = p.evaluate("document.activeElement === document.querySelector('#nav-new-menu > summary')")
                                ins2 = p.evaluate(INSIDE_JS)
                                tip2 = pseudo_after(p, '#nav-new-menu > summary')
                                check(focused and tip2 is not None and tip2['left'] >= sb['right'] and ins2['labW'] <= 1, 'c5 %s: keyboard focus → same right bubble · label inside 0' % tag)
                                # 누르면 메뉴
                                p.locator('#nav-new-menu > summary').click()
                                p.wait_for_timeout(250)
                                mn = p.evaluate("() => { const d = document.getElementById('nav-new-menu'); const l = d.querySelector('.menu__list'); const r = l.getBoundingClientRect(); return {open: d.open, left: r.left, w: r.width, items: [...l.querySelectorAll('.menu__item')].filter(i => !i.hidden).map(i => i.textContent.trim())}; }")
                                check(mn['open'] and mn['left'] >= 64 and mn['w'] > 100 and '문서' in mn['items'], 'c5 %s: click 「＋」 → menu opens to the right %s' % (tag, json.dumps(mn, ensure_ascii=False)))
                                p.keyboard.press('Escape')
                            # ☰ 펼침 → 쿠키 open
                            p.locator('#sidebar-collapse').click()
                            p.wait_for_timeout(250)
                            ck = {c['name']: c['value'] for c in ctx.cookies()}
                            rail = p.evaluate(RAIL_JS)
                            check(rail['attr'] is None and rail['w'] == 240 and rail['exp'] == 'true' and ck.get('qaredi_sidebar') == 'open',
                                  'c5 %s: ☰ → expanded 240 · cookie qaredi_sidebar=open' % tag)
                            if name == 'work':
                                lab = p.evaluate("() => { const s = document.querySelector('#nav-new-menu > summary'); const l = [...s.children].find(e => !e.classList.contains('icon')); return {w: l.getBoundingClientRect().width, t: l.textContent}; }")
                                check(lab['w'] > 30 and lab['t'] == '새로 만들기', 'c5 %s: expanded 240 → 「＋ 새로 만들기」 label shown (%.0f)' % (tag, lab['w']))
                            # 다른 화면 · 새로 고침 — 첫 HTML 에 속성 0
                            other = '/community/my-tasks' if name == 'work' else ('/community/memories' if name == 'story' else '/tbox/settings')
                            st_, bt, btn_ = first_html(other, '; qaredi_sidebar=' + ck.get('qaredi_sidebar', ''))
                            check(st_ == 200 and btn_ != '' and 'data-sidebar' not in bt, 'c5 %s: with the cookie the browser wrote, the next screen\'s first HTML has no data-sidebar' % tag)
                            t.go(other)
                            p.wait_for_selector('#sidebar-collapse', timeout=10000)
                            check(p.evaluate(RAIL_JS)['w'] == 240, 'c5 %s: next screen expanded' % tag)
                            p.reload(wait_until='networkidle')
                            check(p.evaluate(RAIL_JS)['w'] == 240, 'c5 %s: reload expanded' % tag)
                            # 다시 접기 → 쿠키 지움 → 새로 고침 접힘
                            p.locator('#sidebar-collapse').click()
                            p.wait_for_timeout(250)
                            ck = {c['name']: c['value'] for c in ctx.cookies()}
                            p.reload(wait_until='networkidle')
                            rail = p.evaluate(RAIL_JS)
                            check('qaredi_sidebar' not in ck and rail['attr'] == 'collapsed' and rail['w'] == 64, 'c5 %s: ☰ again → cookie removed · reload collapsed' % tag)
                            check(rail['overflow'] <= 0, 'c5 %s: horizontal overflow 0 (after toggles)' % tag)
                            if width == 1440 and name == 'work':
                                p.screenshot(path=str(shots / 'c5-collapsed-1440.png'))
                        guard('c5 ' + tag, one)
                        check(t.clean('c5-' + tag), 'c5 %s: console 0' % tag)
                        ctx.close()
                # 412 — 서랍 그대로(쿠키 무관)
                for cookie_open in (False, True):
                    ctx = context('W', 412, 915, touch=True, sidebar_open=cookie_open)
                    t = Tab(ctx)
                    p = t.page
                    t.go('/community/workspace')
                    p.locator('#nav-toggle').click()
                    p.wait_for_selector('#mobile-nav-dialog[open] #sidebar', timeout=5000)
                    st = p.evaluate("() => { const s = document.getElementById('sidebar'); const h = document.getElementById('nav-work-home'); return {w: Math.round(s.getBoundingClientRect().width), home: h.textContent.trim(), homeW: Math.round(h.getBoundingClientRect().width), collapse: getComputedStyle(document.getElementById('sidebar-collapse')).display, overflow: document.documentElement.scrollWidth - innerWidth}; }")
                    res['412-' + str(cookie_open)] = st
                    check(st['w'] > 200 and st['homeW'] > 100 and st['home'] == '업무 홈' and st['collapse'] == 'none' and st['overflow'] <= 0,
                          'c5 412 (cookie %s): drawer as before — full labels · ☰(collapse) hidden %s' % ('open' if cookie_open else 'none', json.dumps(st, ensure_ascii=False)))
                    check(t.clean('c5-412'), 'c5 412: console 0')
                    ctx.close()
                metrics['c5'] = res
            guard('c5', c5)

        # ─── [c6] 오른쪽 위 셋 — 메신저 · 앱 · 내 계정 ───────────────────────────────────────
        if want('c6'):
            def c6():
                CP = new_page('C6 터치 시험 문서')   # 「댓글 달기」(검정 단추)가 있는 화면
                TOOLS_JS = r"""() => { const row = document.querySelector('.shell-tools'); const kids = [...row.children].filter(e => getComputedStyle(e).display !== 'none');
                    const box = (e) => { const r = e.getBoundingClientRect(); return {l: r.left, r: r.right, t: r.top, h: Math.round(r.height * 10) / 10, w: Math.round(r.width)}; };
                    const m = row.querySelector('.shell-tools__messenger'); const a = row.querySelector('.appbar__apps > summary'); const c = row.querySelector('.shell-account > summary');
                    const ms = m ? getComputedStyle(m) : null; const lab = m ? [...m.children].find(x => !x.classList.contains('icon') && !x.classList.contains('badge')) : null;
                    return {order: kids.map(e => e.tagName.toLowerCase() + '.' + (e.className || '').toString().split(' ').filter(c => ['shell-tools__messenger', 'appbar__apps', 'shell-account'].includes(c)).join('')),
                            m: m ? box(m) : null, a: box(a), c: box(c), gapMA: m ? Math.round(a.getBoundingClientRect().left - m.getBoundingClientRect().right) : null,
                            gapAC: Math.round(c.getBoundingClientRect().left - a.getBoundingClientRect().right),
                            mIcon: m ? Math.round(m.querySelector('svg').getBoundingClientRect().width) : null, mFont: lab ? [getComputedStyle(lab).fontSize, getComputedStyle(lab).fontWeight] : null,
                            mBorder: ms ? ms.borderTopColor : null, mBg: ms ? ms.backgroundColor : null, mTag: m ? m.tagName : null, mLabel: m ? (m.getAttribute('aria-label') || m.textContent.trim()) : null,
                            unreadIn: [...document.querySelectorAll('.shell-tools [data-message-unread]')].map(e => e.parentElement.className),
                            chipLabel: c.getAttribute('aria-label'), chipTag: c.tagName, chevron: !!c.querySelector('[data-icon="chevron"] svg'),
                            overflow: document.documentElement.scrollWidth - innerWidth}; }"""
                PANEL_JS = r"""(sel) => { const d = document.querySelector(sel); const p = d.querySelector('.app-menu'); const r = p.getBoundingClientRect(); const s = d.querySelector('summary').getBoundingClientRect();
                    const vis = (e) => e.getClientRects().length > 0 && getComputedStyle(e).display !== 'none';
                    const head = p.querySelector('.app-menu__head');
                    return {open: d.open, cls: p.className, w: Math.round(r.width), right: Math.round(r.right), sumRight: Math.round(s.right), top: Math.round(r.top), left: Math.round(r.left),
                            tiles: [...p.querySelectorAll('.app-menu__app')].filter(vis).map(e => e.querySelector('.app-menu__name').textContent),
                            current: [...p.querySelectorAll('.app-menu__app[aria-current="page"]')].map(e => e.querySelector('.app-menu__name').textContent),
                            logout: [...p.querySelectorAll('.app-menu__logout')].filter(vis).map(e => e.textContent.trim()),
                            head: head ? {name: head.querySelector('strong').textContent, meta: head.querySelector('strong + span').textContent,
                                          avatar: Math.round(head.firstElementChild.getBoundingClientRect().width), nameFont: [getComputedStyle(head.querySelector('strong')).fontSize, getComputedStyle(head.querySelector('strong')).fontWeight],
                                          metaFont: getComputedStyle(head.querySelector('strong + span')).fontSize} : null}; }"""
                PROBE_JS = "(t) => { const d = document.createElement('div'); d.style.color = 'var(' + t + ')'; document.body.appendChild(d); const v = getComputedStyle(d).color; d.remove(); return v; }"
                ROLE = {'W': '직원', 'M': '관리자', 'G': '손님'}
                res = {}

                def contrast_of(p, sel):
                    return [round(h.evaluate(CONTRAST_JS), 2) for h in p.query_selector_all(sel) if h.is_visible()]

                for mode in ('light', 'dark', 'hc'):
                    for k in ('W', 'M', 'G'):
                        sql('UPDATE users SET high_contrast=' + ('true' if mode == 'hc' else 'false') + ' WHERE id=' + str(uid(k)))
                    for width in (1440, 1280, 960):
                        for k in (('W', 'M', 'G') if (width == 1440 and mode == 'light') else ('W',)):
                            tag = '%s-%d-%s' % (k, width, mode)
                            ctx = context(k, width, extra={'tbox_theme': 'dark' if mode == 'dark' else 'light'})
                            t = Tab(ctx)
                            p = t.page

                            def one():
                                t.go('/community/workspace')
                                p.wait_for_selector('.shell-tools .shell-account > summary', timeout=10000)
                                st = p.evaluate(TOOLS_JS)
                                res[tag] = {'tools': st}
                                check(st['order'] == ['button.shell-tools__messenger' if k != 'G' else 'a.shell-tools__messenger', 'details.appbar__apps', 'details.shell-account'],
                                      'c6 %s: row order messenger · apps · account chip %s' % (tag, json.dumps(st['order'])))
                                check(all(abs(x['h'] - 44) < 0.6 for x in (st['m'], st['a'], st['c'])) and st['gapMA'] == 8 and st['gapAC'] == 8,
                                      'c6 %s: three heights 44 · gaps 8 %s' % (tag, json.dumps([st['m']['h'], st['a']['h'], st['c']['h'], st['gapMA'], st['gapAC']])))
                                quiet = p.evaluate("() => { const b = document.createElement('button'); b.className = 'btn btn--quiet'; b.textContent = 'x'; document.querySelector('.shell-tools').appendChild(b); const s = getComputedStyle(b); const o = [s.borderTopColor, s.backgroundColor]; b.remove(); return o; }")
                                check(st['mIcon'] == 22 and st['mFont'] == ['15px', '500'] and [st['mBorder'], st['mBg']] == quiet,
                                      'c6 %s: messenger button icon 22 · 15px 500 · .btn--quiet look (not the blue app button) %s' % (tag, json.dumps({'icon': st['mIcon'], 'font': st['mFont'], 'look': [st['mBorder'], st['mBg']], 'quiet': quiet})))
                                check(st['unreadIn'] == (['btn btn--quiet shell-tools__messenger'] if k != 'G' else []), 'c6 %s: unread badge only on the messenger button (apps button 0) %s' % (tag, json.dumps(st['unreadIn'])))
                                check(st['chipTag'] == 'SUMMARY' and st['chipLabel'].startswith('내 계정 메뉴 — ') and st['chevron'], 'c6 %s: name chip = summary of details.shell-account · aria-label 「내 계정 메뉴 — 이름」 · ▾' % tag)
                                check(st['overflow'] <= 0, 'c6 %s: horizontal overflow 0' % tag)
                                cr = contrast_of(p, '.shell-tools__messenger > span:not(.icon):not(.badge), .shell-tools__name > span, .shell-tools > .appbar__apps > summary')
                                # 앱 판
                                p.locator('.shell-tools .appbar__apps > summary').click()
                                p.wait_for_timeout(150)
                                ap = p.evaluate(PANEL_JS, '.shell-tools .appbar__apps')
                                want_tiles = ['모아 홈', '자료실', '업무', '이야기'] + (['관리자 페이지'] if k == 'M' else [])   # 사이트 H5 — 「검색」 타일 0
                                check([x for x in ap['tiles'] if x != '우리 공간'] == want_tiles and ap['logout'] == [] and ap['head'] is None,
                                      'c6 %s: app panel = apps and tools only (messenger · 내 계정 · 내 활동 · 로그아웃 0 · admin tile only for admin) %s' % (tag, json.dumps(ap['tiles'], ensure_ascii=False)))
                                # 칩 → 내 계정 판 · 두 판 동시 0
                                p.locator('.shell-tools .shell-account > summary').click()
                                p.wait_for_timeout(150)
                                ac = p.evaluate(PANEL_JS, '.shell-tools .shell-account')
                                ap2 = p.evaluate("document.querySelector('.shell-tools .appbar__apps').open")
                                res[tag]['apps'] = ap
                                res[tag]['account'] = ac
                                check(ac['open'] and not ap2, 'c6 %s: opening the account panel closes the app panel (one at a time)' % tag)
                                check(ac['cls'] == ap['cls'] == 'panel app-menu' and ac['w'] == ap['w'] == 300 and ac['right'] == ac['sumRight'],
                                      'c6 %s: account panel = same component · same class · same width 300 · right-aligned %s' % (tag, json.dumps({k_: ac[k_] for k_ in ('cls', 'w', 'right', 'sumRight')})))
                                h = ac['head'] or {}
                                check(h.get('name') == (DN[k] or UN[k]) and h.get('meta') == UN[k] + ' · ' + ROLE[k] and h.get('avatar') == 40 and h.get('nameFont') == ['16px', '600'] and h.get('metaFont') == '13px',
                                      'c6 %s: account head avatar 40 · name 16/600 · 「아이디 · 역할」 13px %s' % (tag, json.dumps(h, ensure_ascii=False)))
                                check(ac['tiles'] == ['내 계정', '내 활동', '테마'] and ac['logout'] == ['로그아웃'] and ac['current'] == [], 'c6 %s: account tiles 내 계정 · 내 활동 · 테마(H1 — DESIGN 2.30 고침) + 로그아웃 row %s' % (tag, json.dumps(ac['tiles'], ensure_ascii=False)))
                                cr += contrast_of(p, '.shell-account .app-menu__head strong, .shell-account .app-menu__head strong + span, .shell-account .app-menu__name, .shell-account .app-menu__logout > span:not(.icon)')
                                check(min(cr) >= 4.5, 'c6 %s: contrast ≥ 4.5 (messenger · chip · apps button · account panel — min %.2f)' % (tag, min(cr)))
                                if width == 1440 and k == 'W' and mode in ('light', 'dark'):
                                    p.screenshot(path=str(shots / ('c6-account-%s.png' % mode)), clip={'x': width - 560, 'y': 0, 'width': 560, 'height': 420})
                                # 바깥 누름 · Esc
                                p.mouse.click(width / 2, 600)
                                p.wait_for_timeout(150)
                                closed = not p.evaluate("document.querySelector('.shell-tools .shell-account').open")
                                p.locator('.shell-tools .shell-account > summary').click()
                                p.keyboard.press('Escape')
                                p.wait_for_timeout(150)
                                esc = p.evaluate("({open: document.querySelector('.shell-tools .shell-account').open, focus: document.activeElement === document.querySelector('.shell-tools .shell-account > summary')})")
                                check(closed and not esc['open'] and esc['focus'], 'c6 %s: outside click closes · Esc closes and focus returns to the chip' % tag)
                                p.locator('.shell-tools .appbar__apps > summary').click()
                                p.keyboard.press('Escape')
                                p.wait_for_timeout(150)
                                check(p.evaluate("document.activeElement === document.querySelector('.shell-tools .appbar__apps > summary') && !document.querySelector('.shell-tools .appbar__apps').open"),
                                      'c6 %s: app panel Esc → focus back to the app button (same function)' % tag)
                                # 메신저 단추 — 열고 닫기
                                if k != 'G':
                                    p.locator('#messenger-open').click()
                                    p.wait_for_selector('#messenger-window[open]', timeout=8000)
                                    a1 = p.evaluate("document.getElementById('messenger-open').getAttribute('aria-expanded')")
                                    p.wait_for_timeout(300)
                                    p.locator('#messenger-open').click()
                                    p.wait_for_timeout(300)
                                    a2 = p.evaluate("[document.getElementById('messenger-window').open, document.getElementById('messenger-open').getAttribute('aria-expanded')]")
                                    p.locator('#messenger-open').click()
                                    p.wait_for_selector('#messenger-window[open]', timeout=8000)
                                    p.wait_for_timeout(500)
                                    inside = p.evaluate("document.getElementById('messenger-window').contains(document.activeElement)")
                                    p.keyboard.press('Escape')
                                    p.wait_for_timeout(300)
                                    a3 = p.evaluate("[document.getElementById('messenger-window').open, document.activeElement && document.activeElement.id]")
                                    check(a1 == 'true' and a2 == [False, 'false'] and inside and a3 == [False, 'messenger-open'],
                                          'c6 %s: messenger button opens (aria-expanded true) · again closes · Esc from inside → closed · focus on the button %s' % (tag, json.dumps([a1, a2, a3])))
                                    dot = p.evaluate("""() => { const b = document.getElementById('messenger-open'); const d = b.querySelector('[data-message-unread]'); d.hidden = false; d.textContent = '3';
                                        const r = b.getBoundingClientRect(), q = d.getBoundingClientRect(); const out = {top: Math.round(q.top - r.top), right: Math.round(r.right - q.right), h: Math.round(q.height), text: d.textContent};
                                        d.hidden = true; d.textContent = ''; return out; }""")
                                    check(-7 <= dot['top'] <= -4 and -7 <= dot['right'] <= -4 and dot['h'] == 18, 'c6 %s: unread number badge at the messenger button top-right %s' % (tag, json.dumps(dot)))
                                else:
                                    lk = p.evaluate("(() => { const a = document.querySelector('.shell-tools__messenger'); return {href: a.getAttribute('href'), label: a.getAttribute('aria-label'), note: !!a.querySelector('.messenger-access-note')}; })()")
                                    check(lk['href'] == '/tbox/messages' and lk['label'] == '메신저 이용 안내' and not lk['note'], 'c6 %s: guest — 「메신저 이용 안내」 link button (same look) %s' % (tag, json.dumps(lk, ensure_ascii=False)))
                                if width == 1440 and mode == 'light' and k == 'W':
                                    # 타일 이동 · aria-current
                                    p.locator('.shell-tools .shell-account > summary').click()
                                    p.locator('#menu-activity').click()
                                    p.wait_for_url('**/tbox/activity', timeout=10000)
                                    p.wait_for_timeout(300)
                                    p.locator('.shell-tools .shell-account > summary').click()
                                    cur = p.evaluate(PANEL_JS, '.shell-tools .shell-account')['current']
                                    t.go('/tbox/settings')
                                    p.locator('.shell-tools .shell-account > summary').click()
                                    cur2 = p.evaluate(PANEL_JS, '.shell-tools .shell-account')['current']
                                    check(cur == ['내 활동'] and cur2 == ['내 계정'], 'c6 %s: tile navigates · on /tbox/activity 「내 활동」 · on /tbox/settings 「내 계정」 aria-current %s' % (tag, json.dumps([cur, cur2], ensure_ascii=False)))
                            guard('c6 ' + tag, one)
                            check(t.clean('c6-' + tag), 'c6 %s: console 0' % tag)
                            ctx.close()
                for k in ('W', 'M', 'G'):
                    sql('UPDATE users SET high_contrast=false WHERE id=' + str(uid(k)))
                # 로그아웃 — 내 계정 판의 줄이 그대로 로그아웃한다(따로 만든 세션)
                ctx = context('W', 1440)
                t = Tab(ctx)
                p = t.page
                t.go('/community/workspace')
                tok_count = int(sql('SELECT count(*) FROM sessions WHERE user_id=' + str(uid('W'))))
                p.locator('.shell-tools .shell-account > summary').click()
                p.locator('#logout').click()
                p.wait_for_url('**/login**', timeout=10000)
                after = int(sql('SELECT count(*) FROM sessions WHERE user_id=' + str(uid('W'))))
                check(after == tok_count - 1, 'c6: 로그아웃 row logs out as before (→ login · session row removed %d → %d)' % (tok_count, after))
                ctx.close()
                # 412 — 윗줄 메신저 · 아바타 · 판 하나만 · 누름 자리
                for mode in ('light', 'dark', 'hc'):
                    for k in (('W', 'M', 'G') if mode == 'light' else ('W',)):
                        sql('UPDATE users SET high_contrast=' + ('true' if mode == 'hc' else 'false') + ' WHERE id=' + str(uid(k)))
                        tag = '%s-412-%s' % (k, mode)
                        ctx = context(k, 412, 915, touch=True, extra={'tbox_theme': 'dark' if mode == 'dark' else 'light'})
                        t = Tab(ctx)
                        p = t.page

                        def phone():
                            t.go('/community/workspace')
                            p.wait_for_selector('#appbar-account', timeout=10000)
                            top = p.evaluate("""() => ({tools: getComputedStyle(document.querySelector('.shell-tools')).display, msg: !!document.getElementById('appbar-messenger') && document.getElementById('appbar-messenger').getClientRects().length > 0,
                                acct: document.getElementById('appbar-account').getAttribute('aria-controls'), label: document.getElementById('appbar-account').getAttribute('aria-label'),
                                av: Math.round(document.querySelector('#appbar-account > span').getBoundingClientRect().width)})""")
                            check(top['tools'] == 'none' and top['msg'] and top['acct'] == 'account-menu' and top['label'].startswith('내 계정 메뉴 — ') and top['av'] == 32,
                                  'c6 %s: top bar messenger · avatar 32 button (aria-controls account-menu) · tools row hidden %s' % (tag, json.dumps(top, ensure_ascii=False)))
                            p.locator('#appbar-account').click()
                            p.wait_for_timeout(200)
                            ac = p.evaluate(PANEL_JS, '.shell-account')
                            ex = p.evaluate("document.getElementById('appbar-account').getAttribute('aria-expanded')")
                            check(ac['open'] and ac['left'] == 0 and ac['w'] == 412 and ac['top'] == 52 and ac['tiles'] == ['내 계정', '내 활동', '테마'] and ac['logout'] == ['로그아웃'] and ex == 'true',
                                  'c6 %s: avatar → account panel full width under the top bar %s' % (tag, json.dumps({k_: ac[k_] for k_ in ('left', 'w', 'top')})))
                            cr = contrast_of(p, '.shell-account .app-menu__head strong, .shell-account .app-menu__head strong + span, .shell-account .app-menu__name')
                            p.locator('#appbar-apps').click()
                            p.wait_for_timeout(200)
                            one_ = p.evaluate("[document.querySelector('.shell-account').open, document.querySelector('.appbar__apps').open, document.getElementById('appbar-account').getAttribute('aria-expanded')]")
                            p.locator('#nav-toggle').click()
                            p.wait_for_timeout(300)
                            two_ = p.evaluate("[document.querySelector('.shell-account').open, document.querySelector('.appbar__apps').open, !!document.querySelector('#mobile-nav-dialog[open]')]")
                            p.locator('#mobile-nav-close').click()
                            p.wait_for_timeout(200)
                            p.locator('#appbar-account').click()
                            p.wait_for_timeout(200)
                            p.locator('#nav-toggle').click()
                            p.wait_for_timeout(300)
                            three_ = p.evaluate("[document.querySelector('.shell-account').open, !!document.querySelector('#mobile-nav-dialog[open]')]")
                            p.locator('#mobile-nav-close').click()
                            check(one_ == [False, True, 'false'] and two_ == [False, False, True] and three_ == [False, True],
                                  'c6 %s: drawer · app panel · account panel — only one open %s' % (tag, json.dumps([one_, two_, three_])))
                            check(min(cr) >= 4.5, 'c6 %s: account panel contrast ≥ 4.5 (min %.2f)' % (tag, min(cr)))
                            ov = p.evaluate("document.documentElement.scrollWidth - innerWidth")
                            check(ov <= 0, 'c6 %s: horizontal overflow 0' % tag)
                            if mode == 'light' and k == 'W':
                                p.locator('#appbar-account').click()
                                p.wait_for_timeout(200)
                                p.screenshot(path=str(shots / 'c6-account-412.png'))
                                p.keyboard.press('Escape')
                            if k == 'G':
                                return   # 손님은 이 문서를 볼 수 없다(댓글 칸 0) — 누름 자리는 직원 · 관리자 문맥에서
                            # 누름 자리 — 윗줄 · 판 · 검정 단추(「댓글 달기」)
                            t.go('/community/p/' + CP)
                            p.wait_for_selector('#comment-submit', timeout=10000)
                            p.locator('#appbar-account').click()
                            p.wait_for_timeout(200)
                            au = p.evaluate(AUDIT_JS, [True, '.appbar, .shell-tools, #page-comments, .page-head'])
                            p.keyboard.press('Escape')
                            cs = p.evaluate("""() => { const b = document.getElementById('comment-submit'); const r = b.getBoundingClientRect(); const a = getComputedStyle(b, '::before');
                                const px = (v) => { const n = parseFloat(v); return isNaN(n) ? 0 : n; };
                                return {w: Math.round(r.width - px(a.left) - px(a.right)), h: Math.round(r.height - px(a.top) - px(a.bottom)), visH: Math.round(r.height), z: a.zIndex, bg: a.backgroundImage}; }""")
                            res[tag] = {'top': top, 'audit': au, 'comment_submit': cs}
                            check(au['smallCount'] == 0, 'c6 %s: touch targets < 44 = 0 (top bar · panel · comments · head) %s' % (tag, json.dumps(au['small'], ensure_ascii=False)))
                            check(cs['h'] >= 44 and cs['w'] >= 44 and cs['visH'] < 44 and cs['bg'] == 'none',
                                  'c6 %s: black button 「댓글 달기」 touch area %d×%d ≥ 44 (visible height %d kept · shine piece yields) ' % (tag, cs['w'], cs['h'], cs['visH']))
                        guard('c6 ' + tag, phone)
                        check(t.clean('c6-' + tag), 'c6 %s: console 0' % tag)
                        ctx.close()
                for k in ('W', 'M', 'G'):
                    sql('UPDATE users SET high_contrast=false WHERE id=' + str(uid(k)))
                # 넓은 화면 정밀 포인터 — 검정 단추 모양 그대로(반짝 조각 · overflow hidden)
                ctx = context('W', 1440)
                t = Tab(ctx)
                p = t.page
                t.go('/community/p/' + CP)
                p.wait_for_selector('#comment-submit', timeout=10000)
                dk = p.evaluate("() => { const b = document.getElementById('comment-submit'); const a = getComputedStyle(b, '::before'); return {ov: getComputedStyle(b).overflow, z: a.zIndex, bg: a.backgroundImage.slice(0, 15), h: Math.round(b.getBoundingClientRect().height)}; }")
                metrics['c6_desktop_black_btn'] = dk
                check(dk['ov'] == 'hidden' and dk['z'] == '-1' and dk['bg'].startswith('linear-gradient'), 'c6 1440: desktop black button look unchanged (shine piece · overflow hidden) %s' % json.dumps(dk))
                ctx.close()
                metrics['c6'] = res
            guard('c6', c6)

        browser.close()
finally:
    (out / 'cafe-c4-browser-metrics.json').write_text(json.dumps(metrics, ensure_ascii=False, indent=1))
    (out / 'cafe-c4-browser-results.json').write_text(json.dumps(checks, ensure_ascii=False, indent=2))
    print('RESULT pass=' + str(sum(x['pass'] for x in checks)) + ' fail=' + str(sum(not x['pass'] for x in checks)), flush=True)
if any(not x['pass'] for x in checks):
    raise SystemExit(1)
