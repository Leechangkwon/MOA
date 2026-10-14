#!/usr/bin/env python3
"""사이트 Q:F-12 카페 C2 — 화면 수락 시험 (실제 PHP · PostgreSQL + Chromium · 격리 틀 memories_harness.py 만)

  python3 tests/community/memories_harness.py --suite cafe_c2_browser.py --output <결과 폴더>

지시서 docs/cafe-directive.md 「C2 완료 기준」 · DESIGN 2.29 · 커뮤니티 ARCHITECTURE 25장.
합성 DB · 임시 복사본 · 로컬 php -S 만 친다(운영 8082 · 운영 DB · 시드 계정 0). 계정은 fixture 의 claude_ 다섯 · UA tbox-review/<날짜>.
  W = claude_employee01(팀 A — 익명 글쓴이) · A = claude_manager01(팀 A — 첫 댓글 → 익명1) · E = claude_employee02(팀 B — 둘째 댓글 → 익명2) ·
  M = claude_admin01(관리자 — 신고함 · 작성자 확인 · 게시판 요청) · G = claude_guest01(손님)
결과 파일에는 단언 이름 · 참/거짓 · 측정값(폭 · 수 · 글자 수)만 — 세션 · CSRF · 비밀번호 0. 스크린샷은 shots/(필요한 것만).

  [empty]   「우리 공간」 카페 홈 — 카드(자유 · 익명 + 「익명」 배지 · 업무 게시판 0 · 같은 높이) · 빈 상태 한 줄 · 「게시판 만들어 주세요」(손님 0) · 「내 공간」이 아래 그대로
  [home]    글이 생긴 뒤 — 새 글 · 인기 글 줄(높이 ≥ 44) · 익명 글 쓴 사람 「글쓴이」 · 내 것 「(나)」
  [shell]   카페 게시판 · 글 → body[data-area=cafe] · 「우리 공간」 사이드바(카페 게시판만 · 업무 트리 · 프로젝트 0) · 관리 묶음 = 관리자만 ·
            업무 셸 게시판 묶음에 자유게시판 0 · 못 읽는 카페 게시판은 data-area 0(판정 통과 때만)
  [anon]    익명 글 머리 「글쓴이」(+ 사람 아이콘 · 「(나)」) · 댓글 「익명N」 · 안내 한 줄 · @ 목록 0(후보 창구 요청 0) · 자료실 탭 0(자유게시판은 그대로) ·
            신고 모달(라디오 다섯 · 고르지 않으면 문구 · 201 → 알림 줄 · 409 문구) · 댓글 「…」 신고 · 내 것엔 신고 0
  [matrix]  360 · 412(터치) · 820 · 1199 · 1280 · 1440 × 밝게 · 어둡게 · 고대비 — 카페 홈 · 익명 글 · 신고함(칸 열림) 가로 넘침 0 · 누름 자리 < 44 0 · 인라인 style 0 · 콘솔 0
  [reports] 신고함(관리자만 — 직원 403) · 탭 셋 · 행 → 오른쪽 칸 · 메모 필수 · 숨기기 · 반려 · 작성자 확인(재인증 창 → 사유 20자 → 이름 → 닫으면 DOM 0 · 저장소 · 주소 0)
  [request] 「게시판 만들어 주세요」 → 관리자 「게시판 요청」 만들기 → 카페 홈에 카드 · 반려(메모 필수)
  [apps]    C3 — 오른쪽 위 「앱」 단추(DESIGN 2.28): 1440 · 1280 · 960 × 밝게 · 어둡게 · 고대비 — 높이 44 · 아이콘 24(채움 · 타일 아이콘 20 보다 큼) ·
            바탕 = --accent-soft 계산값 · 글자 대비 ≥ 4.5(닫힘 · 열림) · 이름 칩 44(아바타 34) · 안 읽은 점 자리 · 넘침 0 / 412 summary 0 · 스크린샷 셋
"""
from pathlib import Path
exec(compile(Path(__file__).with_name('memories_http.py').read_text().split('try:\n for u in users:')[0], __file__, 'exec'))
import re, secrets
from urllib.parse import urlsplit
from playwright.sync_api import sync_playwright
assert str(root).startswith('/tmp/qaredi-stage13-') and manifest['database'].startswith('tbox_memories_test_')
host = urlsplit(site).hostname
UA = 'tbox-review/' + time.strftime('%Y-%m-%d')
ONLY = set(filter(None, os.environ.get('CAFE_C2_ONLY', '').split(',')))
shots = out / 'shots'
shots.mkdir(exist_ok=True)
metrics = {}
MIGRATION = root / 'db/migrations/2026-10-14_community_cafe.sql'
OFF_TEXT = '익명게시판에서는 @ 부르기를 쓸 수 없어요'
ANON_NOTE = '익명게시판 — 다른 사람에게 이름이 보이지 않아요. 신고가 들어오면 관리자가 확인할 수 있어요.'
REASONS = ['욕설 · 비하', '명예훼손', '개인정보 노출', '광고 · 도배', '그 밖']
KEY = {'W': 'employee', 'A': 'manager', 'E': 'other', 'M': 'admin', 'G': 'guest'}


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
def rawk(method, path, body=None, who='W'): return raw(method, path, body, KEY[who])


def psql_file(path):
    env = {k: v for k, v in os.environ.items() if not k.startswith('PG')}
    env['PGPASSFILE'] = str(Path.home() / '.config/qaredi/pgpass-testdb')
    return subprocess.run(['psql', '-h', '127.0.0.1', '-U', 'tbox_testdb', '-w', '-X', '-At', '-d', manifest['database'],
                           '-v', 'ON_ERROR_STOP=1', '-f', str(path)], env=env, capture_output=True, text=True)


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


AUDIT_JS = r"""([touch, scope]) => {
  const vis = el => { if (!el.isConnected) return false; const r = el.getBoundingClientRect(); const s = getComputedStyle(el);
    return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && s.display !== 'none' && !el.closest('[hidden]:not(#sidebar)'); };
  const out = {overflow: document.documentElement.scrollWidth - window.innerWidth,
               inline: [...document.querySelectorAll('[style]')].filter(e => !e.matches('.progress') && (e.getAttribute('style') || '').trim() !== '').map(e => e.tagName + '.' + e.className + '#' + e.id + ' ' + (e.getAttribute('style') || '').slice(0, 60)).slice(0, 5),
               wide: [...document.querySelectorAll('body *')].filter(e => { const r = e.getBoundingClientRect(); return r.width > 0 && r.right > window.innerWidth + 1 && !e.closest('dialog:not([open])'); })
                 .slice(0, 6).map(e => e.tagName + '#' + e.id + '.' + (typeof e.className === 'string' ? e.className : '') + ' ' + Math.round(e.getBoundingClientRect().right))};
  const small = [], pre = [];
  if (touch) {
    const sel = ['button', 'summary', 'a[class]', 'input[type=checkbox]', 'input[type=radio]'];
    const roots = [...document.querySelectorAll(scope)];
    for (const root of roots) for (const el of root.querySelectorAll(sel.join(','))) {
      if (!vis(el) || el.closest('.doc') || el.closest('dialog:not([open])')) continue;
      const r = el.getBoundingClientRect(); const b = getComputedStyle(el, '::before');
      let w = r.width, h = r.height;
      if (b.content && b.content !== 'none' && b.position === 'absolute') {
        const px = v => { const n = parseFloat(v); return isNaN(n) ? 0 : n; };
        w = r.width - px(b.left) - px(b.right); h = r.height - px(b.top) - px(b.bottom);
      }
      if ((el.matches('input[type=checkbox], input[type=radio]')) && el.closest('label')) { const lr = el.closest('label').getBoundingClientRect(); w = Math.max(w, lr.width); h = Math.max(h, lr.height); }
      // 지금 있던 한계(이 조각 밖): 테마 design.css 의 검정 단추 「반짝」 ::before(z-index -1 · inset 0)가 터치 넓힘 ::before 를 덮어 검정 단추는 보이는 크기(36) 그대로 — 따로 센다
      const shine = b.content && b.content !== 'none' && b.zIndex === '-1';
      if (w < 43.5 || h < 43.5) (shine ? pre : small).push((el.id || el.className || el.tagName).toString().slice(0, 60) + ' ' + Math.round(w) + 'x' + Math.round(h));
    }
  }
  out.preexisting = pre.slice(0, 6);
  out.small = small.slice(0, 12);
  out.smallCount = small.length;
  return out;
}"""

TEXTS_JS = "(sel) => [...document.querySelectorAll(sel)].map(e => e.textContent.replace(/\\s+/g, ' ').trim())"


try:
    for k, key in KEY.items():
        users[key]['username'] = sql('SELECT username FROM users WHERE id=' + str(users[key]['id']))
        call('GET', '/api/me/space', user=key)
    UN = {k: users[KEY[k]]['username'] for k in KEY}
    sql("INSERT INTO community.usage_guides(user_id,version,status,context) SELECT id,1,'dismissed','overview' FROM users ON CONFLICT DO NOTHING")
    team_b = int(sql("SELECT team_id FROM users WHERE id=" + str(uid('E'))))

    # ─── 준비 — 첫 게시판 셋(운영 이주가 만든 모양) + 카페 이주를 다시(자유게시판 → cafe · 익명게시판) ─────────────
    sql("INSERT INTO community.spaces (kind,name,description,access_level,notice,board_kind) VALUES "
        "('board','공지사항','합성 공지','public',true,'notice'),('board','자유게시판','자유롭게 이야기해요','internal',false,'discussion'),"
        "('board','질문과 답변','합성 질문','internal',false,'qa')")
    mig = psql_file(MIGRATION)
    check(mig.returncode == 0, 'setup: cafe migration re-run on the populated DB → rc 0')
    FREE = int(sql("SELECT id FROM community.spaces WHERE kind='board' AND name='자유게시판'"))
    ANON = int(sql("SELECT id FROM community.spaces WHERE kind='board' AND board_kind='anonymous'"))
    QA = int(sql("SELECT id FROM community.spaces WHERE kind='board' AND name='질문과 답변'"))
    NOTICE = int(sql("SELECT id FROM community.spaces WHERE kind='board' AND name='공지사항'"))
    check(sql('SELECT area FROM community.spaces WHERE id=' + str(FREE)) == 'cafe' and sql('SELECT area FROM community.spaces WHERE id=' + str(QA)) == 'work',
          'setup: 자유게시판 area=cafe · 질문과 답변 area=work')
    # 관리자 비밀번호 — 재인증 창을 실제로 지나려고 합성 계정에 이 시험만 아는 값(결과 파일에 남기지 않는다)
    ADMIN_PW = 'cafe-c2-' + secrets.token_hex(8)
    pw_hash = subprocess.run(['php', '-r', 'echo password_hash($argv[1], PASSWORD_ARGON2ID);', ADMIN_PW], capture_output=True, text=True, check=True).stdout.strip()
    sql('UPDATE users SET password_hash=' + lit(pw_hash) + ' WHERE id=' + str(uid('M')))

    def reauth_admin(fresh=True):
        sql('UPDATE sessions SET reauth_at=' + ('now()' if fresh else 'NULL') + ' WHERE user_id=' + str(uid('M')))

    with sync_playwright() as pw:
        browser = pw.chromium.launch(executable_path=os.environ.get('CHROMIUM_EXECUTABLE'))

        def context(k, width, height=900, touch=False, extra=None):
            ctx = browser.new_context(viewport={'width': width, 'height': height}, has_touch=touch, is_mobile=touch, user_agent=UA, reduced_motion='reduce')
            ctx.add_cookies(cookie(new_session(k), dict({'qaredi_motion': 'reduce', 'qaredi_sidebar': 'open'}, **(extra or {}))))   # 카페 C5 — 펼친 셸을 잰다(접힘 기본)
            return ctx

        def cafe_home(p):
            p.wait_for_selector('#cafe-feed:not([hidden])', timeout=15000)
            p.wait_for_timeout(200)

        # ─── [empty] 카페 홈 — 글이 없을 때 ────────────────────────────────────────────────
        if want('empty'):
            def empty():
                ctx = context('W', 1440)
                t = Tab(ctx)
                p = t.page
                t.go('/community/cafe')   # 사이트 H3 — 카페 홈은 /community/cafe(DESIGN 2.29 「「우리 공간」 = 카페」)
                cafe_home(p)
                st = p.evaluate("""() => {
                  const cards = [...document.querySelectorAll('#cafe-boards .cafe-board')];
                  const order = ['cafe-home', 'space-mine-title', 'space-picker'].map(id => document.getElementById(id));   // H3 — 「내 공간」 · 공간 고르개는 이 화면에 없다
                  const pos = (a, b) => !!a && !!b && !!(a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING);
                  return {names: cards.map(c => c.querySelector('.card__title').textContent), anon: cards.filter(c => c.querySelector('.badge') && c.querySelector('.badge').textContent === '익명').map(c => c.querySelector('.card__title').textContent),
                          heights: cards.map(c => Math.round(c.getBoundingClientRect().height)), tags: cards.map(c => c.tagName + '.' + c.className),
                          titleFont: cards.length ? [getComputedStyle(cards[0].querySelector('.card__title')).fontSize, getComputedStyle(cards[0].querySelector('.card__title')).fontWeight] : null,
                          descClip: cards.length ? getComputedStyle(cards[0].querySelector('.card__desc')).textOverflow : null,
                          meta: cards.length ? cards[0].querySelector('.card__meta').textContent : null,
                          recentEmpty: !document.getElementById('cafe-recent-empty').hidden && document.getElementById('cafe-recent-empty').textContent,
                          popularEmpty: !document.getElementById('cafe-popular-empty').hidden, rows: document.querySelectorAll('#cafe-feed li').length,
                          request: !document.getElementById('cafe-request').hidden, head: document.querySelector('#cafe-home h1').textContent,
                          mine: order[1] ? order[1].textContent : null, order: !!order[0] && !order[1] && !order[2],
                          picker: !!document.getElementById('space-picker'), cols: getComputedStyle(document.getElementById('cafe-feed')).gridTemplateColumns.split(' ').length}; }""")
                metrics['empty'] = st
                check(set(st['names']) == {'자유게시판', '익명게시판'} and st['anon'] == ['익명게시판'], 'empty: cafe cards = 자유게시판 · 익명게시판(「익명」 badge) · work boards 0 ' + json.dumps(st['names'], ensure_ascii=False))
                check(all(tg.startswith('A.card card--link cafe-board') for tg in st['tags']) and len(set(st['heights'])) == 1 and st['titleFont'] == ['16px', '600'] and st['descClip'] == 'ellipsis',
                      'empty: cards are 2.3 a.card.card--link · same height · name 16/600 · desc one line ellipsis ' + json.dumps(st['heights']))
                check(st['meta'] is not None and '글 0' in st['meta'] and '오늘 새 글 0' in st['meta'], 'empty: card meta 「글 0 · 오늘 새 글 0」 ' + str(st['meta']))
                check(st['recentEmpty'] == '아직 글이 없어요 — 첫 글을 써 보세요' and st['popularEmpty'] and st['rows'] == 0, 'empty: 새 글 · 인기 글 empty help line')
                check(st['request'] and st['head'] == '우리 공간', 'empty: head 「우리 공간」 + 「게시판 만들어 주세요」 for employee')   # H3 — 머리 h1 「우리 공간」(옛 h2 「카페」)
                check(st['mine'] is None and st['order'] and not st['picker'], 'H3 empty: 「내 공간」 · space picker 0 on the cafe home')   # 옛 「「내 공간」 below the cafe home · space picker kept」
                check(st['cols'] == 2, 'empty: 1440 feed two columns')
                p.screenshot(path=str(shots / 'home-empty-1440.png'), full_page=True)
                check(t.clean('empty'), 'empty: console 0 · page errors 0 · 5xx 0')
                ctx.close()
                ctx = context('G', 1440)
                t = Tab(ctx)
                t.go('/community/cafe')   # 사이트 H3 — 카페 홈은 /community/cafe(DESIGN 2.29 「「우리 공간」 = 카페」)
                cafe_home(t.page)
                check(t.page.evaluate("document.getElementById('cafe-request').hidden") is True, 'empty: guest — 「게시판 만들어 주세요」 0 (can_request_board false)')
                ctx.close()
            guard('empty', empty)

        # ─── 글 · 댓글 (창구로 — 화면 시험의 재료) ─────────────────────────────────────────
        P = api('POST', '/api/boards/' + str(ANON) + '/pages', {'title': '익명 시험 글 C2'}, 'W', 201)['page']['id']
        cur = api('GET', '/api/pages/' + P, None, 'W')['page']['doc_version']
        api('PATCH', '/api/pages/' + P, {'doc': {'schema': 1, 'blocks': [{'id': str(uuid.uuid4()), 'type': 'paragraph', 'text': [{'t': '익명 본문입니다', 'm': {}}]}]}, 'doc_version': cur}, 'W')
        cA = api('POST', '/api/pages/' + P + '/comments', {'body': '첫 댓글이에요'}, 'A', 201)['comment']['id']
        cE = api('POST', '/api/pages/' + P + '/comments', {'body': '둘째 댓글이에요'}, 'E', 201)['comment']['id']
        cW = api('POST', '/api/pages/' + P + '/comments', {'body': '글쓴이 답글이에요', 'parent_id': cA}, 'W', 201)['comment']['id']
        P2 = api('POST', '/api/boards/' + str(ANON) + '/pages', {'title': '익명 화면 폭 시험 글'}, 'W', 201)['page']['id']
        api('POST', '/api/pages/' + P2 + '/comments', {'body': '폭 시험 댓글'}, 'A', 201)
        F = api('POST', '/api/boards/' + str(FREE) + '/pages', {'title': '자유 시험 글 C2'}, 'A', 201)['page']['id']
        api('POST', '/api/pages/' + F + '/comments', {'body': '자유 댓글'}, 'W', 201)
        for who in ('A', 'E', 'M'):
            api('GET', '/api/pages/' + P, None, who)

        # ─── [home] 카페 홈 — 글이 생긴 뒤 ───────────────────────────────────────────────
        if want('home'):
            def home():
                for who, mine in (('W', True), ('A', False)):
                    ctx = context(who, 1440)
                    t = Tab(ctx)
                    p = t.page
                    t.go('/community/cafe')   # 사이트 H3 — 카페 홈은 /community/cafe(DESIGN 2.29 「「우리 공간」 = 카페」)
                    cafe_home(p)
                    rows = p.evaluate("""() => [...document.querySelectorAll('#cafe-recent li a')].map(a => ({spans: [...a.children].map(s => s.textContent), h: Math.round(a.getBoundingClientRect().height),
                        href: a.getAttribute('href'), badge: a.children[0].className}))""")
                    pop = p.evaluate("document.querySelectorAll('#cafe-popular li a').length")
                    metrics['home_' + who] = {'rows': rows, 'popular': pop}
                    anon_rows = [r for r in rows if r['href'] == '/community/p/' + P]
                    check(len(rows) >= 3 and all(r['h'] >= 44 for r in rows) and pop >= 1, 'home %s: 새 글 rows %d (h ≥ 44) · 인기 글 rows %d' % (who, len(rows), pop))
                    check(anon_rows and anon_rows[0]['spans'][0] == '익명게시판' and anon_rows[0]['spans'][1] == '익명 시험 글 C2'
                          and anon_rows[0]['spans'][2] == ('글쓴이 (나)' if mine else '글쓴이'),
                          'home %s: anon row = board badge · title · writer 「글쓴이%s」 %s' % (who, ' (나)' if mine else '', json.dumps(anon_rows[:1], ensure_ascii=False)))
                    body_text = p.evaluate("document.getElementById('cafe-home').innerText")
                    check(UN['W'] not in body_text, 'home %s: anon writer username 0 in the cafe home' % who)
                    if who == 'W':
                        free_card = p.evaluate("[...document.querySelectorAll('#cafe-boards .cafe-board')].map(c => c.querySelector('.card__meta').textContent)")
                        check(any('글 1' in m and '오늘 새 글 1' in m for m in free_card), 'home: card meta counts updated ' + json.dumps(free_card, ensure_ascii=False))
                        p.screenshot(path=str(shots / 'home-1440.png'), full_page=True)
                    check(t.clean('home-' + who), 'home %s: console 0' % who)
                    ctx.close()
                ctx = context('W', 412, 915, touch=True)
                t = Tab(ctx)
                t.go('/community/cafe')   # 사이트 H3 — 카페 홈은 /community/cafe(DESIGN 2.29 「「우리 공간」 = 카페」)
                cafe_home(t.page)
                check(t.page.evaluate("getComputedStyle(document.getElementById('cafe-feed')).gridTemplateColumns.split(' ').length") == 1, 'home 412: feed one column')
                t.page.screenshot(path=str(shots / 'home-412.png'), full_page=True)
                ctx.close()
            guard('home', home)

        # ─── [shell] 카페 셸 · 업무 셸 ─────────────────────────────────────────────────────
        if want('shell'):
            def shell():
                WORK_PARTS = ['#nav-community-tree', '#nav-work-projects', '#nav-community-teams', '#nav-new-menu', '#nav-work-home', '#nav-work-my-tasks',
                              '#nav-community-documents-box', '#nav-community-fav-box', '#nav-community-more-box', '.sidebar-document-tools']
                for who, path, label in (('W', '/community/b/' + str(FREE), 'free board'), ('W', '/community/b/' + str(ANON), 'anon board'), ('A', '/community/p/' + P, 'anon post'),
                                         ('M', '/community/b/' + str(FREE), 'free board (admin)')):
                    ctx = context(who, 1440)
                    t = Tab(ctx)
                    p = t.page
                    t.go(path)
                    until(p, "() => !document.getElementById('nav-community-boards').hidden", 10000)
                    st = p.evaluate("""(parts) => ({area: document.body.getAttribute('data-area'), brand: (document.querySelector('#sidebar .moa-brand-home') || {getAttribute: () => null}).getAttribute('aria-label'),
                        home: !!document.getElementById('nav-cafe-home'), boards: [...document.querySelectorAll('#nav-community-boards a')].map(a => a.textContent.trim()),
                        work: parts.filter(s => document.querySelector(s)), admin: !document.getElementById('nav-cafe-admin').hidden,
                        adminLinks: [...document.querySelectorAll('#nav-cafe-admin a')].map(a => a.getAttribute('href'))})""", WORK_PARTS)
                    metrics['shell_' + label] = st
                    check(st['area'] == 'cafe' and st['brand'] == '우리 공간 첫 화면으로' and st['home'],   # 사이트 H5 — 머리 글자 0 · 이름은 링크 aria-label
                           'shell %s (%s): body[data-area=cafe] · brand 「우리 공간」 · 카페 홈' % (label, who))
                    check(set(st['boards']) >= {'자유게시판', '익명게시판'} and '질문과 답변' not in st['boards'] and '공지사항' not in st['boards'],
                          'shell %s: sidebar boards = cafe boards only %s' % (label, json.dumps(st['boards'], ensure_ascii=False)))
                    check(st['work'] == [], 'shell %s: work tree · projects · teams · new menu · documents 0 %s' % (label, json.dumps(st['work'])))
                    check(st['admin'] == (who == 'M') and st['adminLinks'] == ['/community/reports', '/community/board-requests'],
                          'shell %s: 관리(신고함 · 게시판 요청) visible only for admin (%s)' % (label, st['admin']))
                    if label == 'anon board':
                        cells = p.evaluate("[...document.querySelectorAll('#board-body tr td:nth-child(2)')].map(td => td.textContent)")
                        check(cells and all(c == '글쓴이 (나)' for c in cells), 'shell anon board list (W): writer 「글쓴이 (나)」 %s' % json.dumps(cells, ensure_ascii=False))
                        p.screenshot(path=str(shots / 'shell-anon-board-1440.png'))
                    check(t.clean('shell-' + label), 'shell %s: console 0' % label)
                    ctx.close()
                # 업무 셸 — 게시판 묶음은 area=work
                ctx = context('W', 1440)
                t = Tab(ctx)
                p = t.page
                t.go('/community/workspace')
                until(p, "() => !document.getElementById('nav-community-boards').hidden", 10000)
                st = p.evaluate("() => ({area: document.body.getAttribute('data-area'), boards: [...document.querySelectorAll('#nav-community-boards a')].map(a => a.textContent.trim()), tree: !!document.getElementById('nav-community-tree')})")
                metrics['shell_work'] = st
                check(st['area'] is None and st['tree'] and '질문과 답변' in st['boards'] and '공지사항' in st['boards'] and '자유게시판' not in st['boards'] and '익명게시판' not in st['boards'],
                      'shell work: no data-area · work sidebar boards = 공지사항 · 질문과 답변 (자유게시판 0) %s' % json.dumps(st['boards'], ensure_ascii=False))
                check(t.clean('shell-work'), 'shell work: console 0')
                ctx.close()
                # 못 읽는 카페 게시판(다른 팀) — 판정을 지나지 못하면 data-area 0 · 카페 사이드바 0
                reauth_admin()
                tb = api('POST', '/api/boards', {'name': 'B팀 카페 C2', 'access_level': 'team', 'team_id': team_b, 'board_kind': 'discussion', 'area': 'cafe'}, 'M', 201)['board']['id']
                for who, readable in (('W', False), ('E', True)):
                    ctx = context(who, 1440)
                    t = Tab(ctx)
                    t.go('/community/b/' + str(tb))
                    st = t.page.evaluate("() => ({area: document.body.getAttribute('data-area'), cafe: !!document.getElementById('nav-cafe-home')})")
                    check(st == ({'area': 'cafe', 'cafe': True} if readable else {'area': None, 'cafe': False}),
                          'shell team-B cafe board seen by %s: data-area only when the read decision passes %s' % (who, json.dumps(st)))
                    ctx.close()
            guard('shell', shell)

        # ─── [anon] 익명 표시 · 멘션 끔 · 자료실 탭 0 · 신고 ───────────────────────────────────
        if want('anon'):
            def anon():
                ctx = context('A', 1440)
                t = Tab(ctx)
                p = t.page
                t.go('/community/p/' + P)
                p.wait_for_selector('#page-comments .list__item', timeout=15000)
                p.wait_for_timeout(300)
                st = p.evaluate("""() => { const heads = [...document.querySelectorAll('#page-comments .list__item')].map(li => { const s = li.querySelector('.list__head strong');
                    return {id: li.id.replace('comment-', ''), who: s.textContent, avatar: !!s.querySelector('.anon-avatar'), me: !!s.querySelector('.muted'), report: !!li.querySelector('[data-comment-report]')}; });
                  const o = document.getElementById('page-owner-name');
                  return {owner: o.textContent, ownerAvatar: !!o.querySelector('.anon-avatar svg'), note: !document.getElementById('page-anon-note').hidden && document.getElementById('page-anon-note').textContent,
                          heads: heads, text: document.body.innerText, avatarBg: (() => { const a = document.querySelector('.anon-avatar'); return a ? [getComputedStyle(a).backgroundColor, getComputedStyle(a).borderRadius] : null; })(),
                          surface2: getComputedStyle(document.documentElement).getPropertyValue('--surface-2').trim(),
                          reportItem: !document.getElementById('page-report').hidden}; }""")
                byid = {h['id']: h for h in st['heads']}
                metrics['anon_A'] = {k: v for k, v in st.items() if k != 'text'}
                check(st['owner'] == '글쓴이' and st['ownerAvatar'], 'anon (A): head writer 「글쓴이」 + person icon avatar')
                check(st['note'] == ANON_NOTE, 'anon (A): one-line notice under the head')
                check(byid.get(cA, {}).get('who') == '익명1 (나)' and byid[cA]['me'] and byid.get(cE, {}).get('who') == '익명2' and byid.get(cW, {}).get('who') == '글쓴이'
                      and all(h['avatar'] for h in st['heads']), 'anon (A): comments 「익명1 (나)」 · 「익명2」 · 「글쓴이」 with person avatars ' + json.dumps([h['who'] for h in st['heads']], ensure_ascii=False))
                check(UN['W'] not in st['text'] and UN['E'] not in st['text'], 'anon (A): page text has no W / E username')
                check(st['avatarBg'] is not None and st['avatarBg'][1] == '50%', 'anon: .anon-avatar round on --surface-2 ' + json.dumps(st['avatarBg']))
                check(st['reportItem'] and not byid[cA]['report'] and byid[cE]['report'] and byid[cW]['report'], 'anon (A): 「신고」 on the post and others\' comments · none on my comment')
                # 멘션 끔 — @ 를 쳐도 목록 0 · 후보 창구 요청 0 · 안내 한 줄
                before = len([r for r in t.requests if r.endswith('/mention-candidates')])
                p.locator('#comment-input').click()
                p.keyboard.type('@cl')
                p.wait_for_timeout(900)
                ms = p.evaluate("""() => { const f = document.querySelector('#comment-form .mention-field'); const help = f ? f.nextElementSibling : null;
                    return {list: f ? !f.querySelector('.menu__list').hidden : null, help: help && !help.hidden ? help.textContent : null, marks: document.querySelectorAll('#comment-form .mention-mark--ok, #comment-form .mention-mark--miss').length,
                            ph: document.getElementById('comment-input').getAttribute('placeholder')}; }""")
                calls = len([r for r in t.requests if '/mention-candidates' in r]) - before
                metrics['anon_mention'] = dict(ms, calls=calls)
                check(ms['list'] is False and ms['help'] == OFF_TEXT and ms['marks'] == 0 and calls == 0, 'anon: @ list 0 · candidate requests 0 · line 「%s」 %s' % (OFF_TEXT, json.dumps(ms, ensure_ascii=False)))
                p.locator('#comment-input').fill('')
                # 신고 — 글 머리 더보기 › 신고
                p.locator('#page-more > summary').click()
                p.locator('#page-report').click()
                p.wait_for_selector('dialog[data-cafe-modal=report][open]', timeout=5000)
                m = p.evaluate("""() => { const d = document.querySelector('dialog[data-cafe-modal=report]');
                    return {radios: [...d.querySelectorAll('input[type=radio]')].map(r => r.parentElement.textContent.trim()), ta: d.querySelectorAll('textarea[maxlength="500"]').length,
                            foot: [...d.querySelectorAll('.modal__foot button')].map(b => [b.textContent, b.className]), x: d.querySelectorAll('.modal__head [aria-label="닫기"]').length,
                            title: d.querySelector('.modal__title').textContent, w: Math.round(d.getBoundingClientRect().width)}; }""")
                metrics['report_modal'] = m
                check(m['title'] == '신고하기' and m['radios'] == REASONS and m['ta'] == 1, 'report modal: 「신고하기」 · radios five ' + json.dumps(m['radios'], ensure_ascii=False))
                check(m['foot'] == [['닫기', 'btn btn--quiet'], ['신고하기', 'btn']] and m['x'] == 1 and m['w'] <= 520, 'report modal: foot 닫기(quiet) · 신고하기(black) · ✕ · width ≤ 520 ' + json.dumps(m['foot'], ensure_ascii=False))
                dlg = p.locator('dialog[data-cafe-modal=report]')
                dlg.locator('.modal__foot .btn:not(.btn--quiet)').click()
                p.wait_for_timeout(200)
                check(dlg.locator('.msg').inner_text() == '신고하는 까닭을 골라 주세요.', 'report modal: no reason picked → message (request 0)')
                p.screenshot(path=str(shots / 'report-modal-1440.png'))
                dlg.locator('input[type=radio]').nth(0).check()
                dlg.locator('textarea').fill('합성 신고 설명')
                with p.expect_response(lambda r: r.url.endswith('/community/api/reports') and r.request.method == 'POST') as rr:
                    dlg.locator('.modal__foot .btn:not(.btn--quiet)').click()
                p.wait_for_timeout(300)
                done = p.evaluate("() => ({open: document.querySelectorAll('dialog[data-cafe-modal]').length, msg: document.getElementById('msg').textContent, ok: document.getElementById('msg').classList.contains('msg--ok')})")
                check(rr.value.status == 201 and done == {'open': 0, 'msg': '신고했어요 — 관리자가 확인해요', 'ok': True}, 'report: 201 → modal gone · 「신고했어요 — 관리자가 확인해요」 (success line) ' + json.dumps(done, ensure_ascii=False))
                p.locator('#page-more > summary').click()
                p.locator('#page-report').click()
                p.wait_for_selector('dialog[data-cafe-modal=report][open]', timeout=5000)
                dlg = p.locator('dialog[data-cafe-modal=report]')
                dlg.locator('input[type=radio]').nth(1).check()
                with p.expect_response(lambda r: r.url.endswith('/community/api/reports') and r.request.method == 'POST') as rr:
                    dlg.locator('.modal__foot .btn:not(.btn--quiet)').click()
                p.wait_for_timeout(300)
                check(rr.value.status == 409 and dlg.locator('.msg').inner_text() == '이미 신고한 글이에요', 'report again: 409 → 「이미 신고한 글이에요」 in the modal')
                p.keyboard.press('Escape')
                p.wait_for_timeout(200)
                check(p.evaluate("document.querySelectorAll('dialog[data-cafe-modal]').length") == 0, 'report modal: Esc closes and removes the dialog')
                # 댓글 줄 글자 단추 「신고」 (E 의 댓글) — Q:F-12 W1d: 전엔 「…」 메뉴를 먼저 열었다
                li = p.locator('#comment-' + cE)
                li.locator('[data-comment-report]').click()
                p.wait_for_selector('dialog[data-cafe-modal=report][open]', timeout=5000)
                dlg = p.locator('dialog[data-cafe-modal=report]')
                dlg.locator('input[type=radio]').nth(3).check()
                with p.expect_response(lambda r: r.url.endswith('/community/api/reports') and r.request.method == 'POST') as rr:
                    dlg.locator('.modal__foot .btn:not(.btn--quiet)').click()
                p.wait_for_timeout(300)
                check(rr.value.status == 201 and rr.value.request.post_data_json.get('comment_id') == cE and p.locator('#comments-msg').inner_text() == '신고했어요 — 관리자가 확인해요',
                      'comment report: 「신고」 text button → 201 (comment_id) · success line')
                p.screenshot(path=str(shots / 'anon-post-1440.png'), full_page=True)
                check(t.clean('anon-A'), 'anon (A): console 0')
                ctx.close()
                # W — 글쓴이 (나) · 내 글엔 신고 0 · 자료실 탭 0
                ctx = context('W', 1440)
                t = Tab(ctx)
                p = t.page
                t.go('/community/p/' + P)
                p.wait_for_selector('#page-comments .list__item', timeout=15000)
                st = p.evaluate("() => ({owner: document.getElementById('page-owner-name').textContent, report: !document.getElementById('page-report').hidden, mine: [...document.querySelectorAll('#page-comments .list__item')].filter(li => li.querySelector('.list__head strong').textContent === '글쓴이 (나)').length})")
                check(st['owner'] == '글쓴이 (나)' and not st['report'] and st['mine'] == 1, 'anon (W): head 「글쓴이 (나)」 · 신고 0 on my own post · my reply 「글쓴이 (나)」 ' + json.dumps(st, ensure_ascii=False))
                p.locator('#page-edit').click()
                p.wait_for_selector('#page-editor .ProseMirror', timeout=10000)

                def image_tabs():
                    p.locator('.editor-toolbar__row > button', has_text='블록 추가').first.click()
                    p.wait_for_selector('#block-dialog[open]', timeout=5000)
                    p.locator('#block-list .block-pick[data-block-type="image"]').click()
                    p.locator('#block-insert').click()
                    p.wait_for_selector('#atom-dialog[open]', timeout=5000)
                    p.wait_for_timeout(200)
                    return p.evaluate("""() => { const vis = e => !!e && e.getClientRects().length > 0 && !e.closest('[hidden]');
                        return {lib: vis(document.querySelector('[data-atom-tab="library"]')), up: vis(document.querySelector('[data-atom-tab="upload"]')),
                                pick: vis(document.querySelector('[data-atom-upload]')), q: vis(document.getElementById('atom-file-q'))}; }""")
                tabs = image_tabs()
                metrics['anon_image_tabs'] = tabs
                check(not tabs['lib'] and tabs['up'] and tabs['pick'] and not tabs['q'], 'anon (W edit): image dialog — 자료실 탭 0 · 「이 문서에 올리기」 only ' + json.dumps(tabs))
                p.screenshot(path=str(shots / 'anon-image-dialog-1440.png'))
                p.keyboard.press('Escape')
                check(t.clean('anon-W'), 'anon (W): console 0')
                ctx.close()
                # 대조 — 자유게시판 글(A 의 글)은 자료실 탭 그대로 · 이름 그대로 · 멘션 켜짐
                ctx = context('A', 1440)
                t = Tab(ctx)
                p = t.page
                t.go('/community/p/' + F)
                p.wait_for_selector('#page-comments .list__item', timeout=15000)
                st = p.evaluate("() => ({owner: document.getElementById('page-owner-name').textContent, note: document.getElementById('page-anon-note').hidden, area: document.body.getAttribute('data-area'), avatar: document.querySelectorAll('.anon-avatar').length})")
                check(st['owner'] == UN['A'] and st['note'] and st['area'] == 'cafe' and st['avatar'] == 0, 'free (A): real writer name · notice 0 · anon avatar 0 · still cafe shell ' + json.dumps(st, ensure_ascii=False))
                p.locator('#comment-input').click()
                p.keyboard.type('@')
                until(p, "() => !!document.querySelector('#comment-form .mention-field .menu__list:not([hidden])')", 8000)
                check(True, 'free: @ list opens (mention component on)')
                p.keyboard.press('Escape')
                p.locator('#comment-input').fill('')
                p.locator('#page-edit').click()
                p.wait_for_selector('#page-editor .ProseMirror', timeout=10000)
                tabs = image_tabs()
                check(tabs['lib'] and tabs['up'], 'free (A edit): image dialog keeps 자료실 탭 ' + json.dumps(tabs))
                p.keyboard.press('Escape')
                check(t.clean('free-A'), 'free (A): console 0')
                ctx.close()
            guard('anon', anon)

        # ─── [matrix] 폭 여섯 × 밝게 · 어둡게 · 고대비 ──────────────────────────────────────
        if want('matrix'):
            def matrix():
                widths = [(360, False), (412, True), (820, False), (1199, False), (1280, False), (1440, False)]
                only_w = set(filter(None, os.environ.get('CAFE_C2_WIDTHS', '').split(',')))
                widths = [w for w in widths if not only_w or str(w[0]) in only_w]
                summary = {}
                reauth_admin()
                for mode in [m for m in ('light', 'dark', 'hc') if not os.environ.get('CAFE_C2_MODES') or m in os.environ['CAFE_C2_MODES'].split(',')]:
                    sql('UPDATE users SET high_contrast=' + ('true' if mode == 'hc' else 'false') + ' WHERE id IN (' + str(uid('A')) + ',' + str(uid('M')) + ')')
                    for width, touch in widths:
                        tag = '%d%s-%s' % (width, 't' if touch else '', mode)
                        for who, path, scope, label in (('A', '/community/cafe', '#cafe-home', 'home'),   # H3 — 카페 홈 자리
                                                             ('A', '/community/p/' + P2, '.main', 'post'), ('M', '/community/reports', '.main', 'reports')):
                            ctx = context(who, width, 900 if width > 800 else 915, touch=touch, extra={'tbox_theme': 'dark' if mode == 'dark' else 'light'})
                            if who == 'M':
                                reauth_admin()   # 새 세션 — 관리자 화면은 15분 재인증 창 안에서
                            t = Tab(ctx)
                            p = t.page

                            def one():
                                t.go(path)
                                if label == 'home':
                                    cafe_home(p)
                                elif label == 'post':
                                    p.wait_for_selector('#page-comments .list__item', timeout=15000)
                                else:
                                    p.wait_for_selector('#reports-body tr', timeout=15000)
                                    p.locator('#reports-body tr').first.locator('td').nth(1).click()
                                    p.wait_for_selector('#report-pane:not([hidden]) .task-pane__title', timeout=5000)
                                p.wait_for_timeout(300)
                                a = p.evaluate(AUDIT_JS, [touch or width < 720, scope])
                                summary[tag + '-' + label] = {'overflow': a['overflow'], 'small': a['small'], 'inline': a['inline'], 'wide': a['wide'], 'preexisting_black_btn': a['preexisting']}
                                check(a['overflow'] <= 0, 'matrix %s %s horizontal overflow 0 (%d %s)' % (tag, label, a['overflow'], json.dumps(a['wide'] if a['overflow'] > 0 else [])))
                                if touch or width < 720:
                                    check(a['smallCount'] == 0, 'matrix %s %s tap targets ≥ 44 (%s)' % (tag, label, json.dumps(a['small'], ensure_ascii=False)))
                                check(a['inline'] == [], 'matrix %s %s inline style 0 %s' % (tag, label, json.dumps(a['inline'])))
                                if mode != 'hc' and width in (412, 1440):
                                    p.screenshot(path=str(shots / ('matrix-%s-%s.png' % (tag, label))))
                            guard('matrix ' + tag + ' ' + label, one)
                            check(t.clean('matrix-' + tag + '-' + label), 'matrix %s %s console 0 · page errors 0 · 5xx 0' % (tag, label))
                            ctx.close()
                sql('UPDATE users SET high_contrast=false WHERE id IN (' + str(uid('A')) + ',' + str(uid('M')) + ')')
                metrics['matrix'] = summary
            guard('matrix', matrix)

        # ─── [reports] 신고함 · 처리 · 작성자 확인 ─────────────────────────────────────────
        if want('reports'):
            def reports():
                # 직원은 화면째 403
                ctx = context('W', 1440)
                t = Tab(ctx)
                r = t.go('/community/reports')
                check(r.status == 403 and t.page.locator('#reports-body').count() == 0, 'reports: employee → 403 (no table) %d' % r.status)
                ctx.close()
                reauth_admin()
                ctx = context('M', 1440)
                t = Tab(ctx)
                p = t.page
                sql("UPDATE sessions SET reauth_at=now() WHERE user_id=" + str(uid('M')))
                t.go('/community/reports')
                p.wait_for_selector('#reports-body tr', timeout=15000)
                st = p.evaluate("""() => ({tabs: [...document.querySelectorAll('#reports-tabs [role=tab]')].map(b => [b.textContent, b.getAttribute('aria-selected')]),
                    head: [...document.querySelectorAll('table.files thead th')].map(th => th.textContent), rows: document.querySelectorAll('#reports-body tr').length,
                    area: document.body.getAttribute('data-area'), wrap: !!document.querySelector('.table-wrap > table.files #reports-body')})""")
                metrics['reports_list'] = st
                check(st['tabs'] == [['열림', 'true'], ['숨김', 'false'], ['반려', 'false']] and st['head'] == ['대상', '사유', '신고자', '신고 수', '시각', '상태'] and st['wrap'],
                      'reports: tabs 열림 · 숨김 · 반려 · 2.4 table (대상 · 사유 · 신고자 · 신고 수 · 시각 · 상태) ' + json.dumps(st, ensure_ascii=False))
                check(st['rows'] >= 2 and st['area'] == 'cafe', 'reports: open rows %d · cafe shell' % st['rows'])
                page_row = p.locator('#reports-body tr', has_text='익명 시험 글 C2').filter(has_not_text='댓글').first
                page_row.locator('td').nth(1).click()
                p.wait_for_selector('#report-pane:not([hidden]) .task-pane__title', timeout=5000)
                pane = p.evaluate("""() => { const pn = document.getElementById('report-pane'); const dd = [...pn.querySelectorAll('.task-pane__props dt')].map(d => [d.textContent, d.nextElementSibling.textContent]);
                    return {title: pn.querySelector('.task-pane__title').textContent, props: dd, avatar: !!pn.querySelector('.task-pane__props .anon-avatar'), open: document.body.hasAttribute('data-task-open'),
                            list: [...pn.querySelectorAll('.task-pane__activity li')].map(li => li.textContent), reveal: !!pn.querySelector('[data-cafe-reveal]'),
                            hide: !!pn.querySelector('.btn--danger'), memo: pn.querySelectorAll('textarea').length, pos: getComputedStyle(pn).position, w: Math.round(pn.getBoundingClientRect().width), text: pn.innerText}; }""")
                metrics['reports_pane'] = {k: v for k, v in pane.items() if k != 'text'}
                props = dict(pane['props'])
                check(pane['title'] == '익명 시험 글 C2' and props.get('쓴 사람') == '글쓴이' and pane['avatar'] and pane['open'] and pane['pos'] == 'fixed' and 400 <= pane['w'] <= 480,
                      'reports pane: 2.21 aside.task-pane (fixed · 400~480) · title · 쓴 사람 「글쓴이」 + person icon ' + json.dumps(pane['props'], ensure_ascii=False))
                check(pane['list'] and all(UN['A'] in x for x in pane['list']) and pane['memo'] == 1 and pane['hide'] and pane['reveal'] and UN['W'] not in pane['text'],
                      'reports pane: report list (reporter shown to admin) · memo · 숨기기(danger) · 작성자 확인 · real writer 0')
                p.locator('#report-pane .btn--danger').click()
                p.wait_for_timeout(200)
                check(p.locator('#report-pane > .msg').inner_text() == '처리 메모를 적어 주세요.', 'reports pane: 숨기기 without memo → 「처리 메모를 적어 주세요.」 (request 0)')
                p.screenshot(path=str(shots / 'reports-pane-1440.png'))
                # 작성자 확인 — 15분 창이 닫힌 상태 → 공용 재인증 창 → 사유 20자 → 이름 → 닫으면 DOM 0
                reauth_admin(False)
                p.locator('#report-pane [data-cafe-reveal]').click()
                p.wait_for_selector('#reauth-dialog-form input[type=password]', timeout=8000)
                check(p.locator('dialog[data-cafe-modal=reveal]').count() == 0, 'reveal: stale reauth → shared reauth dialog first (reveal dialog not yet)')
                p.locator('#reauth-dialog-form input[type=password]').fill(ADMIN_PW)
                p.locator('#reauth-dialog-form input[type=password]').press('Enter')
                p.wait_for_selector('dialog[data-cafe-modal=reveal][open]', timeout=10000)
                d = p.locator('dialog[data-cafe-modal=reveal]')
                rv = p.evaluate("""() => { const d = document.querySelector('dialog[data-cafe-modal=reveal]'); return {title: d.querySelector('.modal__title').textContent,
                    notice: d.querySelector('.notice').textContent, foot: [...d.querySelectorAll('.modal__foot button')].map(b => b.textContent)}; }""")
                check(rv['title'] == '작성자 확인' and rv['notice'] == '확인한 사실이 감사 기록에 남아요. 신고 처리에 필요할 때만 쓰세요.' and rv['foot'] == ['닫기', '확인'],
                      'reveal: after reauth → 「작성자 확인」 modal · notice · 닫기 · 확인 ' + json.dumps(rv, ensure_ascii=False))
                reveals = []
                p.on('response', lambda resp: reveals.append(resp.status) if resp.url.endswith('/reveal') else None)
                d.locator('textarea').fill('가' * 19)
                check('19자' in d.locator('.help').inner_text(), 'reveal: character count shown (19자)')
                d.locator('.modal__foot .btn:not(.btn--quiet)').click()
                p.wait_for_timeout(300)
                check(d.locator('.msg').inner_text() == '사유를 20자 이상 적어 주세요.' and reveals == [], 'reveal: 19 chars → message · request 0')
                d.locator('textarea').fill('명예훼손 신고 처리를 위해 작성자를 확인합니다')
                d.locator('.modal__foot .btn:not(.btn--quiet)').click()
                until(p, "() => { const r = document.querySelector('dialog[data-cafe-modal=reveal] .ok-note'); return !!r && !r.hidden; }", 8000)
                shown = d.locator('.ok-note').inner_text()
                check(reveals == [200] and UN['W'] in shown, 'reveal: 20+ chars → 200 → real name (아이디) inside the modal only')
                store = p.evaluate("() => ({ls: JSON.stringify(Object.assign({}, localStorage)), ss: JSON.stringify(Object.assign({}, sessionStorage)), url: location.href})")
                check(UN['W'] not in store['ls'] and UN['W'] not in store['ss'] and UN['W'] not in store['url'] and not any(UN['W'] in c[0] for c in t.console),
                      'reveal: name not in localStorage · sessionStorage · URL · console')
                p.screenshot(path=str(shots / 'reveal-modal-1440.png'))
                d.locator('.modal__foot .btn--quiet').click()
                p.wait_for_timeout(300)
                gone = p.evaluate("(name) => ({dialogs: document.querySelectorAll('dialog[data-cafe-modal]').length, html: document.documentElement.outerHTML.includes(name)})", UN['W'])
                check(gone == {'dialogs': 0, 'html': False}, 'reveal: closing removes the dialog — real name in DOM 0 ' + json.dumps(gone))
                # 숨기기(메모) → 열림 탭에서 사라짐 · 숨김 탭에 있음
                p.locator('#report-pane textarea').fill('욕설이 있어 숨겼어요')
                with p.expect_response(lambda resp: resp.url.endswith('/resolve')) as rr:
                    p.locator('#report-pane .btn--danger').click()
                p.wait_for_timeout(500)
                check(rr.value.status == 200 and p.locator('#msg').inner_text() == '숨겼어요' and p.evaluate("document.getElementById('report-pane').hidden")
                      and p.locator('#reports-body tr', has_text='익명 시험 글 C2').filter(has_not_text='댓글').count() == 0, 'reports: 숨기기 with memo → 200 · pane closed · row leaves 열림')
                p.locator('#reports-tabs [data-status=hidden]').click()
                p.wait_for_selector('#reports-body tr', timeout=8000)
                check(p.locator('#reports-body tr', has_text='익명 시험 글 C2').count() >= 1, 'reports: 숨김 tab lists the hidden report')
                # 반려 — 댓글 신고
                p.locator('#reports-tabs [data-status=open]').click()
                p.wait_for_selector('#reports-body tr', timeout=8000)
                p.locator('#reports-body tr', has_text='댓글').first.locator('td').nth(1).click()
                p.wait_for_selector('#report-pane:not([hidden]) textarea', timeout=5000)
                check(p.locator('#report-pane .task-pane__props').inner_text().find('익명2') >= 0, 'reports pane (comment): 쓴 사람 「익명2」')
                p.locator('#report-pane textarea').fill('문제가 없어 반려해요')
                with p.expect_response(lambda resp: resp.url.endswith('/resolve')) as rr:
                    p.locator('#report-pane .btn--quiet', has_text='반려').click()
                p.wait_for_timeout(400)
                p.locator('#reports-tabs [data-status=rejected]').click()
                p.wait_for_selector('#reports-body tr', timeout=8000)
                check(rr.value.status == 200 and p.locator('#reports-body tr', has_text='댓글').count() >= 1, 'reports: 반려 with memo → 반려 tab')
                check(t.clean('reports'), 'reports: console 0')
                ctx.close()
            guard('reports', reports)

        # ─── [request] 게시판 요청 → 관리자 만들기 → 카페 홈 카드 ───────────────────────────────
        if want('request'):
            def request():
                ctx = context('W', 1440)
                t = Tab(ctx)
                p = t.page
                t.go('/community/cafe')   # 사이트 H3 — 카페 홈은 /community/cafe(DESIGN 2.29 「「우리 공간」 = 카페」)
                cafe_home(p)

                def ask(name, purpose, anon=False):
                    p.locator('#cafe-request').click()
                    p.wait_for_selector('dialog[data-cafe-modal=board-request][open]', timeout=8000)
                    d = p.locator('dialog[data-cafe-modal=board-request]')
                    d.locator('input[type=text]').fill(name)
                    d.locator('textarea').fill(purpose)
                    if anon:
                        d.locator('input[type=checkbox]').check()
                    with p.expect_response(lambda resp: resp.url.endswith('/community/api/board-requests')) as rr:
                        d.locator('.modal__foot .btn:not(.btn--quiet)').click()
                    p.wait_for_timeout(300)
                    return rr.value.status

                p.locator('#cafe-request').click()
                p.wait_for_selector('dialog[data-cafe-modal=board-request][open]', timeout=8000)
                d = p.locator('dialog[data-cafe-modal=board-request]')
                fm = p.evaluate("""() => { const d = document.querySelector('dialog[data-cafe-modal=board-request]'); return {name: d.querySelector('input[type=text]').maxLength,
                    purpose: d.querySelector('textarea').maxLength, anon: d.querySelectorAll('input[type=checkbox]').length, foot: [...d.querySelectorAll('.modal__foot button')].map(b => b.textContent)}; }""")
                check(fm == {'name': 40, 'purpose': 500, 'anon': 1, 'foot': ['닫기', '요청하기']}, 'request modal: 이름(40) · 쓰임(500) · 「익명게시판으로」 · 닫기 · 요청하기 ' + json.dumps(fm, ensure_ascii=False))
                d.locator('.modal__foot .btn:not(.btn--quiet)').click()
                p.wait_for_timeout(200)
                check(d.locator('.msg').inner_text() == '이름과 쓰임을 적어 주세요.', 'request modal: empty → message (request 0)')
                p.screenshot(path=str(shots / 'request-modal-1440.png'))
                p.keyboard.press('Escape')
                s1 = ask('독서 모임 C2', '책 이야기를 나눠요')
                check(s1 == 201 and p.locator('#cafe-status').inner_text() == '요청했어요 — 관리자가 확인하면 알림이 와요', 'request: 201 · status line')
                s2 = ask('반려될 게시판', '반려 시험', anon=True)
                check(s2 == 201, 'request: second (anonymous) 201')
                check(t.clean('request-W'), 'request (W): console 0')
                ctx.close()
                reauth_admin()
                ctx = context('M', 1440)
                t = Tab(ctx)
                p = t.page
                sql("UPDATE sessions SET reauth_at=now() WHERE user_id=" + str(uid('M')))
                t.go('/community/board-requests')
                p.wait_for_selector('#requests-body tr', timeout=15000)
                st = p.evaluate("() => ({head: [...document.querySelectorAll('table.files thead th')].map(th => th.textContent), rows: [...document.querySelectorAll('#requests-body tr')].map(tr => tr.firstElementChild.textContent), actions: [...document.querySelectorAll('#requests-body tr:first-child td.actions button')].map(b => b.textContent)})")
                metrics['requests'] = st
                check('독서 모임 C2' in st['rows'] and st['actions'] == ['만들기', '반려'], 'requests: table rows · td.actions 만들기 · 반려 ' + json.dumps(st, ensure_ascii=False))
                row = p.locator('#requests-body tr', has_text='반려될 게시판')
                row.locator('td.actions button', has_text='반려').click()
                p.wait_for_selector('dialog[data-cafe-modal=decide][open]', timeout=5000)
                d = p.locator('dialog[data-cafe-modal=decide]')
                d.locator('.modal__foot .btn:not(.btn--quiet)').click()
                p.wait_for_timeout(200)
                check(d.locator('.msg').inner_text() == '반려하는 까닭을 적어 주세요.', 'requests: 반려 without memo → message')
                d.locator('textarea').fill('비슷한 게시판이 있어요')
                with p.expect_response(lambda resp: resp.url.endswith('/decide')) as rr:
                    d.locator('.modal__foot .btn:not(.btn--quiet)').click()
                p.wait_for_timeout(400)
                check(rr.value.status == 200 and p.locator('#msg').inner_text() == '반려했어요', 'requests: 반려 with memo → 200')
                row = p.locator('#requests-body tr', has_text='독서 모임 C2')
                row.locator('td.actions button', has_text='만들기').click()
                p.wait_for_selector('dialog[data-cafe-modal=decide][open]', timeout=5000)
                d = p.locator('dialog[data-cafe-modal=decide]')
                check(d.locator('select').count() == 1 and d.locator('select').input_value() == 'internal', 'requests: 만들기 modal — 등급 (default 사내)')
                p.screenshot(path=str(shots / 'requests-decide-1440.png'))
                with p.expect_response(lambda resp: resp.url.endswith('/decide')) as rr:
                    d.locator('.modal__foot .btn:not(.btn--quiet)').click()
                p.wait_for_timeout(400)
                check(rr.value.status == 200 and p.locator('#msg').inner_text() == '게시판을 만들었어요' and p.locator('#requests-body tr', has_text='독서 모임 C2').count() == 0,
                      'requests: 만들기 → 200 · row leaves 열림')
                p.locator('#requests-tabs [data-status=created]').click()
                p.wait_for_selector('#requests-body tr', timeout=8000)
                check(p.locator('#requests-body tr', has_text='독서 모임 C2').locator('a', has_text='게시판 열기').count() == 1, 'requests: 만듦 tab · 「게시판 열기」')
                check(t.clean('requests-M'), 'requests (M): console 0')
                ctx.close()
                ctx = context('W', 1440)
                t = Tab(ctx)
                t.go('/community/cafe')   # 사이트 H3 — 카페 홈은 /community/cafe(DESIGN 2.29 「「우리 공간」 = 카페」)
                cafe_home(t.page)
                names = t.page.evaluate("[...document.querySelectorAll('#cafe-boards .card__title')].map(e => e.textContent)")
                check('독서 모임 C2' in names, 'request: created board card appears on the cafe home ' + json.dumps(names, ensure_ascii=False))
                ctx.close()
            guard('request', request)

        # ─── [apps] C3 — 오른쪽 위 「앱」 단추 ───────────────────────────────────────────────
        if want('apps'):
            def apps():
                APPS_JS = r"""() => {
                  const parse = (c) => { const m = c.match(/rgba?\(([^)]+)\)/); if (!m) return null; const v = m[1].split(/[ ,/]+/).filter(Boolean).map(Number); return [v[0], v[1], v[2], v.length > 3 ? v[3] : 1]; };
                  const lum = (rgb) => { const f = (x) => { x /= 255; return x <= 0.03928 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4); }; return 0.2126 * f(rgb[0]) + 0.7152 * f(rgb[1]) + 0.0722 * f(rgb[2]); };
                  const over = (top, under) => { const a = top[3]; return [0, 1, 2].map(i => top[i] * a + under[i] * (1 - a)).concat(1); };
                  const bodyBg = parse(getComputedStyle(document.body).backgroundColor) || [255, 255, 255, 1];
                  const pageBg = bodyBg[3] === 0 ? [255, 255, 255, 1] : bodyBg;
                  const ratio = (fg, bg) => { const b = over(bg, pageBg); const f = over(fg, b); const l1 = lum(f), l2 = lum(b); return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05); };
                  const probe = (token) => { const d = document.createElement('div'); d.style.backgroundColor = 'var(' + token + ')'; document.body.appendChild(d); const v = getComputedStyle(d).backgroundColor; d.remove(); return v; };
                  const s = document.querySelector('.shell-tools > .appbar__apps > summary'); const cs = getComputedStyle(s); const r = s.getBoundingClientRect();
                  const svg = s.querySelector('svg'); const sr = svg ? svg.getBoundingClientRect() : {width: 0, height: 0};
                  const label = [...s.querySelectorAll('span')].find(x => x.textContent === '앱');
                  const me = document.querySelector('.shell-tools__me'); const av = me ? me.querySelector('span').getBoundingClientRect() : {width: 0};
                  return {h: r.height, svgW: sr.width, svgH: sr.height, fill: svg ? getComputedStyle(svg).fill : null, stroke: svg ? getComputedStyle(svg).stroke : null,
                          icon: s.querySelector('[data-icon]') ? s.querySelector('[data-icon]').getAttribute('data-icon') : null,
                          bg: cs.backgroundColor, soft: probe('--accent-soft'), color: cs.color, dark: probe('--accent-dark'), border: cs.borderTopColor, line: probe('--accent-line'),
                          font: label ? [getComputedStyle(label).fontSize, getComputedStyle(label).fontWeight] : [cs.fontSize, cs.fontWeight], pad: [cs.paddingLeft, cs.paddingRight],
                          contrast: ratio(parse(cs.color), parse(cs.backgroundColor)), meH: me ? me.getBoundingClientRect().height : 0, avatar: av.width,
                          overflow: document.documentElement.scrollWidth - window.innerWidth, visible: cs.display !== 'none' && r.width > 0}; }"""
                OPEN_JS = r"""() => {
                  const parse = (c) => { const m = c.match(/rgba?\(([^)]+)\)/); if (!m) return null; const v = m[1].split(/[ ,/]+/).filter(Boolean).map(Number); return [v[0], v[1], v[2], v.length > 3 ? v[3] : 1]; };
                  const lum = (rgb) => { const f = (x) => { x /= 255; return x <= 0.03928 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4); }; return 0.2126 * f(rgb[0]) + 0.7152 * f(rgb[1]) + 0.0722 * f(rgb[2]); };
                  const s = document.querySelector('.shell-tools > .appbar__apps > summary'); const cs = getComputedStyle(s);
                  const probe = (token) => { const d = document.createElement('div'); d.style.backgroundColor = 'var(' + token + ')'; document.body.appendChild(d); const v = getComputedStyle(d).backgroundColor; d.remove(); return v; };
                  const f = parse(cs.color), b = parse(cs.backgroundColor); const l1 = lum(f), l2 = lum(b);
                  const tile = document.querySelector('.app-menu__icon svg'); const tr = tile ? tile.getBoundingClientRect() : {width: 0};
                  return {bg: cs.backgroundColor, accent: probe('--accent'), color: cs.color, fg: probe('--primary-fg'), contrast: (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05), tile: tr.width,
                          icon: s.querySelector('svg').getBoundingClientRect().width, overflow: document.documentElement.scrollWidth - window.innerWidth}; }"""
                # 카페 C6 (DESIGN 2.30) — 안 읽은 수는 앱 단추에서 메신저 단추 오른쪽 위 숫자 배지로 옮겼다(앱 단추 0)
                DOT_JS = r"""() => { const s = document.querySelector('.shell-tools > .appbar__apps > summary'); const m = document.querySelector('.shell-tools > .shell-tools__messenger');
                  const d = m ? m.querySelector('[data-message-unread]') : null; return {onApps: s.querySelectorAll('[data-message-unread]').length, onMessenger: d ? 1 : 0}; }"""
                summary = {}
                for mode in ('light', 'dark', 'hc'):
                    sql('UPDATE users SET high_contrast=' + ('true' if mode == 'hc' else 'false') + ' WHERE id=' + str(uid('W')))
                    for width in (1440, 1280, 960):
                        tag = '%d-%s' % (width, mode)
                        ctx = context('W', width, extra={'tbox_theme': 'dark' if mode == 'dark' else 'light'})
                        t = Tab(ctx)
                        p = t.page

                        def one():
                            t.go('/community/workspace')
                            a = p.evaluate(APPS_JS)
                            dot = p.evaluate(DOT_JS)
                            if width == 1440 and mode in ('light', 'dark'):
                                p.locator('.shell-tools').screenshot(path=str(shots / ('apps-button-%s.png' % mode)))
                            p.locator('.shell-tools > .appbar__apps > summary').click()
                            p.wait_for_selector('.appbar__apps[open] .app-menu', timeout=5000)
                            p.mouse.move(5, 5)
                            p.wait_for_timeout(150)
                            o = p.evaluate(OPEN_JS)
                            if width == 1440 and mode == 'light':
                                p.screenshot(path=str(shots / 'apps-button-open.png'), clip={'x': width - 480, 'y': 0, 'width': 480, 'height': 520})
                            p.keyboard.press('Escape')
                            summary[tag] = {'closed': a, 'open': o, 'dot': dot}
                            check(abs(a['h'] - 44) < 0.6 and abs(a['svgW'] - 24) < 0.6 and abs(a['svgH'] - 24) < 0.6 and a['icon'] == 'apps' and a['stroke'] == 'none' and a['fill'] == a['color'],
                                  'apps %s: summary h 44 · icon apps 24×24 filled currentColor %s' % (tag, json.dumps({k: a[k] for k in ('h', 'svgW', 'icon', 'fill', 'stroke')})))
                            check(a['bg'] == a['soft'] and a['color'] == a['dark'] and a['border'] == a['line'] and a['font'] == ['15px', '600'] and a['pad'] == ['12px', '16px'],
                                  'apps %s: bg = --accent-soft · text --accent-dark · border --accent-line · 15/600 · padding 12/16 %s' % (tag, json.dumps({k: a[k] for k in ('bg', 'soft', 'color', 'dark', 'font', 'pad')})))
                            check(a['contrast'] >= 4.5, 'apps %s: closed text contrast %.2f ≥ 4.5' % (tag, a['contrast']))
                            check(o['bg'] == o['accent'] and o['color'] == o['fg'] and o['contrast'] >= 4.5, 'apps %s: open bg --accent · text --primary-fg · contrast %.2f ≥ 4.5' % (tag, o['contrast']))
                            check(o['icon'] > o['tile'] > 0, 'apps %s: button icon %.0f > tile icon %.0f' % (tag, o['icon'], o['tile']))
                            check(abs(a['meH'] - 44) < 0.6 and abs(a['avatar'] - 34) < 0.6, 'apps %s: name chip h 44 · avatar 34 (%.1f · %.1f)' % (tag, a['meH'], a['avatar']))
                            check(dot['onApps'] == 0 and dot['onMessenger'] == 1, 'apps %s: unread badge moved to the messenger button (app button 0 — C6) %s' % (tag, json.dumps(dot)))
                            check(a['overflow'] <= 0 and o['overflow'] <= 0, 'apps %s: horizontal overflow 0' % tag)
                        guard('apps ' + tag, one)
                        check(t.clean('apps-' + tag), 'apps %s: console 0' % tag)
                        ctx.close()
                sql('UPDATE users SET high_contrast=false WHERE id=' + str(uid('W')))
                ctx = context('W', 412, 915, touch=True)
                t = Tab(ctx)
                t.go('/community/workspace')
                st = t.page.evaluate("() => { const s = document.querySelector('.shell-tools > .appbar__apps > summary'); return {summary: getComputedStyle(s).display, tools: getComputedStyle(document.querySelector('.shell-tools')).display, appbar: getComputedStyle(document.getElementById('appbar-apps')).display, overflow: document.documentElement.scrollWidth - innerWidth}; }")
                summary['412'] = st
                check(st['tools'] == 'none' and st['appbar'] != 'none' and st['overflow'] <= 0, 'apps 412: summary 0 (row hidden · phone app name ▾ as before) %s' % json.dumps(st))
                ctx.close()
                metrics['apps'] = summary
            guard('apps', apps)

        browser.close()
finally:
    (out / 'cafe-c2-browser-metrics.json').write_text(json.dumps(metrics, ensure_ascii=False, indent=1))
    (out / 'cafe-c2-browser-results.json').write_text(json.dumps(checks, ensure_ascii=False, indent=2))
    print('RESULT pass=' + str(sum(x['pass'] for x in checks)) + ' fail=' + str(sum(not x['pass'] for x in checks)), flush=True)
if any(not x['pass'] for x in checks):
    raise SystemExit(1)
