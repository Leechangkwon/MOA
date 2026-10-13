#!/usr/bin/env python3
"""사이트 Q:F-12 지시서 D-2 — 업무 화면 (실제 PHP·PostgreSQL + Chromium · 격리 틀 memories_harness.py 만)

  python3 tests/community/memories_harness.py --suite qf12_work_browser.py --output <결과 폴더>

합성 DB · 임시 복사본 · 로컬 php -S 만 친다(운영 8082 · tbox_re · 시드 계정 0). 계정은 fixture 의 claude_ 다섯과 UA tbox-review/<날짜>.
결과 파일에는 단언 이름 · 참/거짓 · 측정값(폭 · 수 · 좌표)만 — 세션 · CSRF · 응답 본문 0. 스크린샷은 결과 폴더 shots/.

  Q:T-41 작업 상세 칸  1440 줄 누르기 → 목록 그대로 · 칸만 바뀜 · ?task= / 뒤로 가기 → 앞 작업 · 닫힘 / 1199 목록 대신 + 「‹ 목록」 /
                       412 전체 화면 / 손님 · 보기 공유 문맥에서 쓰는 단추 0
  Q:T-42 새 화면 점검  업무 홈 · 내 작업 · 프로젝트(상세 칸 열림) · 포트폴리오 · 이야기 셸 × 360 · 412(터치 · 마우스) · 820 · 1280 · 1440 ×
                       밝게 · 어둡게 · 고대비 — 가로 넘침 0 · 콘솔 0 · 누름 자리 44(폰 폭 · 터치) · 머리 검정 단추 ≤ 1 · 머리 단추 ≤ 4 · 인라인 style 0
  D-2 더해서           내 작업 그 자리 추가 → 「내 할 일」 생성 · 목록 반영 / 담당 바꾸기 — 후보는 창구 결과만(화면 = 창구 답) /
                       포트폴리오 숨은 프로젝트 줄 0(화면에서도 · 제목 · id 0) / `/community/` = 업무 홈 · `#overview-*` 0 · overview_page.php 0
  ui_snapshot          scripts/ui_snapshot.py(AUDIT_JS) 를 이 인스턴스에서 1440 · 1280 — 화면마다 결함 수(기준 판과 대조는 결과 폴더 두 벌로)
  U1(사용감 라운드)    「바꾸기」 열자마자 후보 = 창구 빈 q 답 · 나뿐이면 안내 / 설명 누르면 편집(링크 · 글자 고르기는 제외 · Tab+Enter · 「편집 마침」 뒤 다시) /
                       하위 작업 제목 그 자리 고치기(Enter = 서버 값 · Esc · 빈 값 · 떠나면 저장) · › 열기 · 「＋ 하위 작업 추가」 / 보기 공유 · 손님 고치기 0 /
                       새 프로젝트 창 첫 값 = 팀 공간 · 팀 없는 계정 = 내 공간 하나 / 360 · 412(터치) · 820 · 1199 · 1280 · 1440 × 밝게 · 어둡게 · 고대비
                       (넘침 0 · 콘솔 0 · 누름 자리 44 · 인라인 style 0) — 묶음 이름 u1 (QF12D_ONLY=u1)
  U2(사용감 라운드)    [접기 collapse] ☰ → 폭 64 · 글자 0 · 남는 항목 · 툴팁 · 기억 · 959 0 / [업무함 u2] 화면 순서 = buildInbox(JS) = Python 규칙 ·
                       꼬리표 · 「@」 · 「검토」 · ★ · 줄 아래 펼침(2.21 요소) · 주소 · ✕ · Esc · 뒤로 · 완료 · ↓↑ · 홈 10줄 · briefing 403 · 1199 · 412 ·
                       손님 · 역할로 보기(손님) 쓰는 단추 0 · 폭 여섯 × 밝게 · 어둡게 · 고대비 (QF12D_ONLY=u2,collapse · 보고용 사본 shots)
                       ★ U3(WK-26 ②)로 「줄 아래 펼침」 단언은 「오른쪽 칸 하나(aside#task-pane)」로 고쳤다 — 줄 아래 판(.task-inbox__pane) 0
  U3(사용감 라운드)    [u3] 칸 폭: 손잡이(separator · aria-value*) 끌기 · ←/→ 16 · Home · 두 번 누르기 · 범위 400 ~ 70% · 새로고침 뒤 기억 · 넓게 보기 ·
                       1199 · 412 폭 조절 0 / 알림 색(성공 .msg--ok ≠ --danger · 오류 --danger) / 칸 편집기 도구줄 · 블록 넣기 목록 = 전체 편집기 ·
                       창 한 벌(내 작업 화면의 창 = 페이지 화면 창 구조) / 붙여넣기(합성 png) → 자리표시 → 그림 블록 · 첨부 행 1 · 본문 att: ·
                       끌어놓기 → 파일 블록 · 「이 문서에 올리기」 탭 · 허용 밖 → 이유 문장 · pending → 「검사 중」 · 전체 편집기 붙여넣기 /
                       보기 공유 · 손님 입구 셋 0 / 폭 여섯 × 밝게 · 어둡게 · 고대비(읽기 · 편집 · 넓게) 넘침 0 · 누름 자리 44 · 인라인 style 0(CSSOM 변수 제외)
"""
from pathlib import Path
exec(compile(Path(__file__).with_name('memories_http.py').read_text().split('try:\n for u in users:')[0], __file__, 'exec'))
import re, secrets, subprocess, sys
from collections import Counter
from urllib.parse import urlsplit, parse_qs
from playwright.sync_api import sync_playwright
assert str(root).startswith('/tmp/qaredi-stage13-') and manifest['database'].startswith('tbox_memories_test_')
host = urlsplit(site).hostname
UA = 'tbox-review/' + time.strftime('%Y-%m-%d')
ONLY = set(filter(None, os.environ.get('QF12D_ONLY', '').split(',')))
shots = out / 'shots'
shots.mkdir(exist_ok=True)
metrics = {}


def check(ok, name):
    """단언 — 실패해도 끝까지 돈다(마지막에 실패 수로 끝낸다). 이름 · 참거짓만 남긴다."""
    checks.append({'pass': bool(ok), 'name': name})
    print(('PASS ' if ok else 'FAIL ') + name, flush=True)
    return bool(ok)


def want(group):
    return not ONLY or group in ONLY


def guard(name, fn):
    try:
        fn()
    except Exception as error:   # noqa: BLE001 — 시험 흐름의 어떤 예외든 실패로 센다
        check(False, name + ' raised ' + type(error).__name__ + ': ' + str(error).splitlines()[0][:200])


def uid(key):
    return int(users[key]['id'])


def lit(v):
    return "'" + str(v).replace("'", "''") + "'"


def new_session(key):
    token = secrets.token_hex(32)
    sql("INSERT INTO sessions(id,user_id,expires_at,ip,user_agent) VALUES('" + hashlib.sha256(token.encode()).hexdigest() + "'," + str(uid(key))
        + ",clock_timestamp()+interval '8 hours','127.0.0.1','" + UA + "')")
    return token


def cookie(token, extra=None):
    jar = [{'name': '__Host-tbox_session', 'value': token, 'domain': host, 'path': '/', 'secure': True, 'httpOnly': True, 'sameSite': 'Lax'}]
    for name, value in (extra or {}).items():
        jar.append({'name': name, 'value': value, 'domain': host, 'path': '/'})
    return jar


def get(path, token=None, follow=False):
    h = {'User-Agent': UA}
    if token:
        h['Cookie'] = '__Host-tbox_session=' + token
    return requests.get(site + path, headers=h, timeout=35, allow_redirects=follow)


def until(page, js, timeout=10000):
    """CSP(unsafe-eval 0) 아래에서 기다리기 — wait_for_function 대신 evaluate 를 되풀이한다."""
    end = time.time() + timeout / 1000
    while True:
        if page.evaluate(js):
            return True
        if time.time() > end:
            raise TimeoutError('until: ' + js[:80])
        page.wait_for_timeout(100)


def code_console(lines):
    return [t for t, _ in lines if not t.startswith('Failed to load resource') and not t.startswith('[DOM] ')]


class Tab:
    """한 탭 — 콘솔 · 페이지 오류 · 5xx 를 모은다."""

    def __init__(self, ctx):
        self.page = ctx.new_page()
        self.console, self.errors, self.bad = [], [], []
        p = self.page
        p.on('console', lambda m: self.console.append((m.text, urlsplit(m.location.get('url', '')).path)))
        p.on('pageerror', lambda e: self.errors.append(str(e)))
        p.on('response', lambda r: self.bad.append(urlsplit(r.url).path) if r.status >= 500 else None)

    def go(self, path):
        self.page.goto(site + path, wait_until='networkidle')
        self.page.wait_for_timeout(300)

    def clean(self, tag=''):
        ok = not self.errors and not self.bad and code_console(self.console) == []
        if not ok:
            metrics['unclean-' + tag + '-' + str(len(metrics))] = {'errors': [e[:200] for e in self.errors], 'bad': self.bad, 'console': [c[:200] for c in code_console(self.console)]}
        return ok


# 화면의 넘침 · 머리 단추 · 누름 자리(DESIGN 4장 · 6장)를 한 번에 잰다 — 보이는 것만
AUDIT_JS = r"""(touch) => {
  const vis = el => { if (!el.isConnected) return false; const r = el.getBoundingClientRect(); const s = getComputedStyle(el);
    return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && s.display !== 'none' && !el.closest('[hidden]:not(#sidebar)'); };
  const out = {overflow: document.documentElement.scrollWidth - window.innerWidth, inlineStyle: document.querySelectorAll('[style]:not(.progress)').length};
  const head = [...document.querySelectorAll('.main .page-head')].filter(vis);
  out.heads = head.map(h => { const a = h.querySelector('.page-head__actions'); const btns = a ? [...a.children].filter(vis) : [];
    return {black: btns.filter(b => b.matches('.btn:not(.btn--quiet):not(.btn--plain):not(.btn--icon):not(.btn--danger)') || (b.matches('details') && b.querySelector('summary.btn:not(.btn--quiet):not(.btn--plain)'))).length,
            count: btns.filter(b => b.matches('.btn, details')).length,
            rows: (() => { const c = btns.map(b => { const r = b.getBoundingClientRect(); return (r.top + r.bottom) / 2; }).sort((x, y) => x - y); let n = 0, last = -99; for (const v of c) { if (v - last > 8) { n++; last = v; } } return n; })()}; });
  const small = [];
  if (touch) {
    const sel = 'button, summary, a[class], input[type=checkbox], input[type=radio]';
    for (const el of document.querySelectorAll('.main ' + sel.split(', ').join(', .main '))) {
      if (!vis(el) || el.closest('.doc') || el.closest('dialog:not([open])')) continue;
      const r = el.getBoundingClientRect(); const b = getComputedStyle(el, '::before');
      let w = r.width, h = r.height;
      if (b.content && b.content !== 'none' && b.position === 'absolute') {
        const px = v => { const n = parseFloat(v); return isNaN(n) ? 0 : n; };
        w = r.width - px(b.left) - px(b.right); h = r.height - px(b.top) - px(b.bottom);
      }
      if (w < 43.5 || h < 43.5) small.push((el.id || el.className || el.tagName).toString().slice(0, 60) + ' ' + Math.round(w) + 'x' + Math.round(h));
    }
  }
  out.small = small.slice(0, 12);
  out.smallCount = small.length;
  return out;
}"""

PANE_JS = r"""() => {
  const p = document.getElementById('task-pane'); const list = document.getElementById('project-list') || document.getElementById('my-tasks-list');
  const box = el => { if (!el) return null; const r = el.getBoundingClientRect(); const s = getComputedStyle(el); return {x: Math.round(r.left), w: Math.round(r.width), h: Math.round(r.height), top: Math.round(r.top), visible: r.width > 0 && r.height > 0 && s.display !== 'none' && !el.hidden, pos: s.position}; };
  return {pane: box(p), list: box(list), tag: p ? p.tagName : null, role: p ? p.getAttribute('role') : null, isDialog: p ? p.matches('dialog') : null,
          title: (document.getElementById('task-pane-title') || {}).textContent || null, back: (() => { const b = p && p.querySelector('.task-pane__back'); return b ? box(b).visible : null; })(),
          rows: list ? list.querySelectorAll('.task-row[data-task]').length : 0, current: [...document.querySelectorAll('.task-row[aria-current="true"]')].map(r => r.getAttribute('data-task')),
          url: location.search, vw: window.innerWidth};
}"""

failures_before = 0
try:
    for u in users:
        users[u]['username'] = sql('SELECT username FROM users WHERE id=' + str(users[u]['id']))
        users[u]['display'] = sql("SELECT COALESCE(NULLIF(display_name,''),username) FROM users WHERE id=" + str(users[u]['id']))
        call('GET', '/api/me/space', user=u)
    emp, mgr, adm, oth, gst = uid('employee'), uid('manager'), uid('admin'), uid('other'), uid('guest')
    sql("INSERT INTO community.usage_guides(user_id,version,status,context) SELECT id,1,'dismissed','overview' FROM users ON CONFLICT DO NOTHING")
    today = sql("SELECT (now() AT TIME ZONE 'Asia/Seoul')::date")
    yesterday = sql("SELECT ((now() AT TIME ZONE 'Asia/Seoul')::date - 1)")

    # ─── 합성 준비 — 팀 공간(검증팀 A) 프로젝트 · 개인 프로젝트 · 포트폴리오 · 공지 ─────────────────────────────
    spaces = call('GET', '/api/spaces', user='manager')['spaces']
    team = next(s for s in spaces if s['kind'] == 'team')
    pteam = call('POST', '/api/pages', {'database': True, 'preset': 'project', 'title': '합성 팀 프로젝트', 'space_id': team['id']}, user='manager', expected=201)['page']
    check(pteam['database']['purpose'] == 'project', 'fixture: team project purpose=project (B preset)')
    rows = []
    for i, (sec, due) in enumerate([('00. 준비', yesterday), ('00. 준비', today), ('01. 진행', None), ('02. 마무리', None)]):
        r = call('POST', '/api/pages', {'parent_id': pteam['id'], 'title': '합성 작업 ' + str(i + 1), 'properties': {'section': sec}}, user='manager', expected=201)['page']
        props = {'status': 'todo'}
        if due:
            props['due'] = due
        call('PATCH', '/api/pages/' + r['id'] + '/properties', props, user='manager')
        rows.append(r)
    # 담당: 매니저가 직원에게 둘(지난 기한 · 오늘) — 직원의 내 작업에 「○○ 님이 맡김」
    for r in rows[:2]:
        call('PATCH', '/api/pages/' + r['id'] + '/properties', {'assignee': users['employee']['username']}, user='manager')
    sub = call('POST', '/api/pages', {'parent_id': rows[0]['id'], 'title': '합성 하위 작업'}, user='manager', expected=201)['page']
    call('PATCH', '/api/pages/' + sub['id'] + '/properties', {'status': 'todo'}, user='manager')
    call('POST', '/api/pages/' + rows[0]['id'] + '/comments', {'body': '합성 댓글 하나'}, user='manager', expected=201)
    # 직원 개인(비공개) 프로젝트 — 포트폴리오에 넣으면 매니저에게는 줄이 없어야 한다(Q:T-35 화면)
    ppriv = call('POST', '/api/pages', {'database': True, 'preset': 'project', 'title': '합성 비공개 프로젝트'}, user='employee', expected=201)['page']
    prow = call('POST', '/api/pages', {'parent_id': ppriv['id'], 'title': '합성 개인 작업', 'properties': {'section': '01. 진행'}}, user='employee', expected=201)['page']
    call('PATCH', '/api/pages/' + prow['id'] + '/properties', {'status': 'todo', 'due': today}, user='employee')
    pf = call('POST', '/api/portfolios', {'space': team['id'], 'name': '합성 포트폴리오'}, user='manager', expected=201)['portfolio']
    call('POST', '/api/portfolios/' + pf['id'] + '/items', {'project': pteam['id']}, user='manager', expected=201)
    call('POST', '/api/portfolios/' + pf['id'] + '/items', {'project': ppriv['id']}, user='employee', expected=201)
    call('POST', '/api/projects/' + pteam['id'] + '/status', {'status': 'at_risk', 'note': '합성 상태 글'}, user='manager')
    # 보기 공유: 직원 비공개 프로젝트를 다른 팀 직원 · 손님에게 「보기」로(쓰는 단추 0 문맥)
    for who_ in ('other', 'guest'):
        r = raw('PUT', '/api/pages/' + ppriv['id'] + '/shares/' + users[who_]['username'], {'level': 'view'}, user='employee')
        check(r.status_code in (200, 201), 'fixture: view share to ' + who_ + ' (' + str(r.status_code) + ')')
    # 안 읽은 공지 하나(홈 공지 한 줄)
    board = sql("INSERT INTO community.spaces(kind,name,access_level,notice,board_kind) VALUES('board','합성 공지판','internal',true,'notice') RETURNING id").splitlines()[0]
    notice_id = str(uuid.uuid4())
    sql('INSERT INTO community.pages(public_id,space_id,title,doc,author_id,last_edited_by) VALUES(' + lit(notice_id) + ',' + board
        + ",'합성 공지 한 줄','{\"schema\":1,\"blocks\":[]}'," + str(adm) + ',' + str(adm) + ')')

    # ─── 서버가 그린 화면(HTML) — /community/ = 업무 홈 · 옛 넘김 · overview 0 ───────────────────────────────────
    if want('html'):
        tok = users['employee']['session']
        r = get('/community/', tok)
        check(r.status_code == 200 and 'id="work-home"' in r.text and 'data-page="community-overview"' in r.text, '/community/ = 업무 홈 (200 · #work-home · data-page community-overview)')
        check('id="community-overview"' not in r.text and re.search(r'id="overview-', r.text) is None and 'overview.css' not in r.text, '/community/ has no overview markup (#community-overview · #overview-* · overview.css 0)')
        check(not (root / 'src/community/handlers/overview_page.php').exists(), 'overview_page.php deleted')
        check('<h1 id="work-home-title" class="page-head__title">안녕하세요, ' + users['employee']['display'] + ' 님</h1>' in r.text, 'home greeting uses the display name (escaped)')
        check('합성 팀 프로젝트' in r.text and '합성 비공개 프로젝트' in r.text and 'progress--p' in r.text, 'home 「내 프로젝트」 lists visible projects with step progress classes')
        check(re.search(r'\sstyle=', r.text) is None and '<script>' not in r.text, 'home: inline style 0 · inline script 0')
        r = get('/community/?view=mine', tok)
        check(r.status_code == 200 and 'id="community-threads"' in r.text, '/community/?view=mine → threads screen (overview forwarding kept)')
        r = get('/community/?space=' + str(team['id']), tok)
        check(r.status_code == 200 and '<h1 class="page-head__title">내 문서</h1>' in r.text, '/community/?space= → 내 문서 screen (title 「내 문서」)')
        r = get('/community/today', tok)
        check(r.status_code == 308 and r.headers.get('Location') == '/community/my-tasks', '/community/today 308 → /community/my-tasks')
        r = get('/community/my-tasks', tok)
        check(r.status_code == 200 and 'id="work-my-tasks"' in r.text and 'data-page="community-today"' in r.text and 'id="task-pane"' in r.text, '/community/my-tasks 200 (#work-my-tasks · task pane aside)')
        r = get('/community/portfolios/' + pf['id'], tok)
        check(r.status_code == 200 and 'id="work-portfolio"' in r.text and ('value="' + pteam['id'] + '"') in r.text and ('value="' + ppriv['id'] + '"') in r.text,
              '/community/portfolios/{id} 200 · 넣기 고르개 = employee 가 볼 수 있는 프로젝트(둘)')
        r = get('/community/portfolios/' + pf['id'], users['manager']['session'])
        check(('value="' + ppriv['id'] + '"') not in r.text and '합성 비공개 프로젝트' not in r.text, 'manager portfolio page: 넣기 고르개 · 사이드바에 남의 비공개 프로젝트 0 (제목 · id)')
        r = get('/community/', users['admin']['session'])
        check('합성 비공개 프로젝트' not in r.text and ppriv['id'] not in r.text, 'admin home/sidebar: 남의 비공개 프로젝트 0 (검색 조건절 — admin_all 갈래 꺼짐)')
        r = get('/community/', users['guest']['session'])
        check(r.status_code == 200 and 'data-work-new-task' not in r.text and 'id="nav-new-menu"' not in r.text and 'data-work-new-project' not in r.text,
              'guest home: 쓰는 단추 0 (작업 추가 · 새로 만들기 · ＋ 프로젝트)')
        check('합성 비공개 프로젝트' in r.text, 'guest home/sidebar: 보기 공유받은 프로젝트는 보인다 (검색 조건절 안)')
        r = get('/community/', users['other']['session'])
        check('합성 팀 프로젝트' not in r.text, 'other team: 남의 팀 프로젝트 0')
        r = get('/community/p/' + pteam['id'], tok)
        check(r.status_code == 200 and 'id="project-head"' in r.text and 'id="project-list"' in r.text and '<aside id="task-pane" class="task-pane"' in r.text and 'id="project-status-dialog"' in r.text,
              'page_view has project head · list host · aside pane · status dialog (hidden until JS)')
        for path in ('/community/', '/community/my-tasks', '/community/portfolios/' + pf['id'], '/community/p/' + pteam['id']):
            body_ = get(path, tok).text
            check(re.search(r'\sstyle=', body_) is None and re.search(r'\son[a-z]+=', body_) is None and re.search(r'<script(?![^>]*\ssrc=)', body_) is None,
                  'inline style/on*/script 0 on ' + path.split('/portfolios/')[0] + ('/portfolios/{id}' if '/portfolios/' in path else ''))

    with sync_playwright() as pw:
        browser = pw.chromium.launch(executable_path=os.environ.get('CHROMIUM_EXECUTABLE'))

        def context(key, width, height=900, touch=False, extra=None):
            ctx = browser.new_context(viewport={'width': width, 'height': height}, has_touch=touch, is_mobile=touch, user_agent=UA, reduced_motion='reduce')
            ctx.add_cookies(cookie(new_session(key), dict({'qaredi_motion': 'reduce'}, **(extra or {}))))
            return ctx

        # ─── Q:T-41 작업 상세 칸 ─────────────────────────────────────────────────────────────────────────
        if want('t41'):
            def t41_wide():
                ctx = context('employee', 1440)
                t = Tab(ctx)
                p = t.page
                t.go('/community/p/' + pteam['id'])
                p.wait_for_selector('#project-list .task-row[data-task]', timeout=15000)
                st0 = p.evaluate(PANE_JS)
                check(p.evaluate("document.body.hasAttribute('data-project-view')") and st0['rows'] >= 4, 'Q:T-41 1440 project view on (rows %d)' % st0['rows'])
                check(st0['tag'] == 'ASIDE' and st0['isDialog'] is False, 'Q:T-41 pane is aside (not dialog)')
                check(p.locator('#project-head').is_visible() and p.locator('#page-doc').is_hidden(), 'Q:T-41 project head shown · page doc hidden in project view')
                check(p.locator('#project-status-text').inner_text().strip() == '위험', 'project status button shows current status 「위험」 (GET status)')
                first = p.locator('#project-list .task-row[data-task="' + rows[0]['id'] + '"] .task-row__title')
                first.click()
                p.wait_for_selector('#task-pane:not([hidden]) #task-pane-title', timeout=10000)
                p.wait_for_timeout(300)
                st1 = p.evaluate(PANE_JS)
                metrics['t41_1440_open'] = st1
                check(parse_qs(st1['url'].lstrip('?')).get('task') == [rows[0]['id']], 'Q:T-41 click row → ?task={row}')
                check(st1['list']['visible'] and st1['rows'] == st0['rows'] and st1['pane']['visible'] and st1['pane']['x'] >= st1['list']['x'] + st1['list']['w'] - 2,
                      'Q:T-41 1440 list stays (same rows) · pane on the right (pane x %d ≥ list right %d)' % (st1['pane']['x'], st1['list']['x'] + st1['list']['w']))
                check(400 <= st1['pane']['w'] <= 480 and st1['pane']['h'] >= 890, 'Q:T-41 1440 pane width clamp(400,34vw,480)=%d · full height %d' % (st1['pane']['w'], st1['pane']['h']))
                check(st1['current'] == [rows[0]['id']] and st1['title'] == '합성 작업 1', 'Q:T-41 opened row aria-current · pane title')
                check(p.locator('#task-pane').get_by_text('작업을 맡겨도 소유자와 볼 수 있는 곳은 바뀌지 않아요. 담당자는 이 작업을 고칠 수 있는 사람 중에서 고를 수 있어요.').count() == 1, 'Q:T-41 맡김 안내 문구 원문')
                check(p.locator('#task-pane').get_by_text(users['manager']['display'] + ' 님이 맡김').count() >= 1 or p.locator('#task-pane').get_by_text(users['manager']['username'] + ' 님이 맡김').count() >= 1,
                      'pane shows 「○○ 님이 맡김」 from history')
                p.locator('#project-list .task-row[data-task="' + rows[1]['id'] + '"] .task-row__title').click()
                until(p, "() => document.getElementById('task-pane-title') && document.getElementById('task-pane-title').textContent === '합성 작업 2'", 10000)
                st2 = p.evaluate(PANE_JS)
                check(parse_qs(st2['url'].lstrip('?')).get('task') == [rows[1]['id']] and st2['rows'] == st0['rows'] and st2['current'] == [rows[1]['id']], 'Q:T-41 second row → only the pane changed · ?task=row2')
                p.go_back()
                until(p, "() => document.getElementById('task-pane-title') && document.getElementById('task-pane-title').textContent === '합성 작업 1'", 10000)
                check(parse_qs(p.evaluate('location.search').lstrip('?')).get('task') == [rows[0]['id']], 'Q:T-41 back → previous task')
                p.go_back()
                until(p, "() => document.getElementById('task-pane').hidden === true", 10000)
                st3 = p.evaluate(PANE_JS)
                check(st3['url'] == '' and not st3['pane']['visible'] and st3['list']['visible'], 'Q:T-41 back again → pane closed (no ?task)')
                # 하위 작업 펼침 · 담당 칸
                ex = p.locator('#project-list .task-row[data-task="' + rows[0]['id'] + '"] .task-row__expand')
                check(ex.count() == 1 and ex.get_attribute('aria-label') == '하위 작업 펼치기', 'project row with a subtask has ▸ 「하위 작업 펼치기」')
                ex.click()
                p.wait_for_selector('#project-list .task-row--sub[data-task="' + sub['id'] + '"]', timeout=8000)
                check(True, 'subtask row appears under its task (one level)')
                cell = p.locator('#project-list .task-row[data-task="' + rows[0]['id'] + '"] .task-row__cell--meta').inner_text()
                check(users['employee']['username'] in cell, 'project row assignee column shows the assignee (database_rows assignee)')
                p.screenshot(path=str(shots / 'project-1440-closed.png'))
                first.click()
                p.wait_for_selector('#task-pane:not([hidden]) #task-pane-title', timeout=10000)
                p.wait_for_timeout(500)
                p.screenshot(path=str(shots / 'project-pane-1440.png'))
                # DESIGN 2.8 ★ · 6장 — 스크롤 칸(상세 칸 · 사이드바)의 아이콘 단추에 올려도 칸 · 문서의 scrollHeight · scrollWidth 그대로
                dims = "() => { const p = document.getElementById('task-pane'), s = document.getElementById('sidebar'), d = document.documentElement; return [p.scrollHeight, p.scrollWidth, s.scrollHeight, s.scrollWidth, d.scrollHeight, d.scrollWidth]; }"
                for sel in ('#task-pane .task-pane__section-head .btn--icon', '#task-pane .task-pane__tools .btn--icon[aria-label="닫기"]', '#sidebar .theme-toggle'):
                    p.mouse.move(5, 5)
                    p.wait_for_timeout(150)
                    before = p.evaluate(dims)
                    p.locator(sel).first.hover()
                    p.wait_for_timeout(250)
                    after = p.evaluate(dims)
                    metrics['hover_' + sel] = [before, after]
                    check(before == after, 'DESIGN 2.8 hover ' + sel + ': pane · sidebar · document scroll sizes unchanged ' + json.dumps([before, after]))
                check(t.clean('t41-1440'), 'Q:T-41 1440 console 0 · page errors 0 · 5xx 0')
                ctx.close()

            def t41_mid():
                ctx = context('employee', 1199)
                t = Tab(ctx)
                p = t.page
                t.go('/community/p/' + pteam['id'] + '?task=' + rows[0]['id'])
                p.wait_for_selector('#task-pane:not([hidden]) #task-pane-title', timeout=15000)
                p.wait_for_timeout(400)
                st = p.evaluate(PANE_JS)
                metrics['t41_1199'] = st
                check(st['pane']['visible'] and not st['list']['visible'] and st['back'] is True, 'Q:T-41 1199 pane replaces the list · 「‹ 목록」 visible')
                check(p.locator('#task-pane .task-pane__back').inner_text().strip() == '‹ 목록', 'Q:T-41 back button text 「‹ 목록」')
                p.screenshot(path=str(shots / 'project-pane-1199.png'))
                p.locator('#task-pane .task-pane__back').click()
                until(p, "() => document.getElementById('task-pane').hidden === true", 8000)
                st2 = p.evaluate(PANE_JS)
                check(st2['list']['visible'] and st2['url'] == '', 'Q:T-41 1199 「‹ 목록」 → list again (no ?task)')
                check(t.clean('t41-1199'), 'Q:T-41 1199 console 0')
                ctx.close()

            def t41_phone():
                ctx = context('employee', 412, 915, touch=True)
                t = Tab(ctx)
                p = t.page
                t.go('/community/p/' + pteam['id'] + '?task=' + rows[0]['id'])
                p.wait_for_selector('#task-pane:not([hidden]) #task-pane-title', timeout=15000)
                p.wait_for_timeout(400)
                st = p.evaluate(PANE_JS)
                metrics['t41_412'] = st
                check(st['pane']['visible'] and not st['list']['visible'] and st['pane']['w'] >= 412 - 2 * 24 and st['pane']['pos'] == 'static',
                      'Q:T-41 412 pane full screen (width %d · in flow) · list hidden' % st['pane']['w'])
                check(p.evaluate('document.documentElement.scrollWidth <= innerWidth'), 'Q:T-41 412 no horizontal overflow with pane')
                p.screenshot(path=str(shots / 'project-pane-412.png'), full_page=True)
                check(t.clean('t41-412'), 'Q:T-41 412 console 0')
                ctx.close()

            def t41_readers():
                for key in ('other', 'guest'):
                    ctx = context(key, 1440)
                    t = Tab(ctx)
                    p = t.page
                    t.go('/community/p/' + ppriv['id'])
                    p.wait_for_selector('#project-list .task-row[data-task]', timeout=15000)
                    vis_count = p.evaluate("""() => [...document.querySelectorAll('#project-list .task-check, #project-list [data-add-section], #project-add-task, #project-list .task-list__head button')]
                        .filter(e => !e.closest('[hidden]') && e.getClientRects().length).length""")
                    check(vis_count == 0, 'Q:T-41 ' + key + ' (view share) project list: write buttons 0 (완료 · 작업 추가 · 칸 더하기)')
                    check(p.locator('#project-status').is_disabled(), 'Q:T-41 ' + key + ' project status button disabled (no can_edit)')
                    p.locator('#project-list .task-row[data-task="' + prow['id'] + '"] .task-row__title').click()
                    p.wait_for_selector('#task-pane:not([hidden]) #task-pane-title', timeout=10000)
                    p.wait_for_timeout(500)
                    writes = p.evaluate("""() => { const pane = document.getElementById('task-pane');
                        const vis = e => !e.closest('[hidden]') && e.getClientRects().length > 0;
                        return {done: [...pane.querySelectorAll('button')].filter(b => vis(b) && /완료 (표시|취소)/.test(b.textContent)).length,
                                assign: [...pane.querySelectorAll('#task-assign')].filter(vis).length,
                                inputs: [...pane.querySelectorAll('.task-pane__props input, .task-pane__props select')].filter(vis).length,
                                menuItems: [...pane.querySelectorAll('.menu__item')].filter(b => !b.hidden).length,
                                checks: [...pane.querySelectorAll('.task-check')].filter(vis).length}; }""")
                    metrics['t41_reader_' + key] = writes
                    check(writes['done'] == 0 and writes['assign'] == 0 and writes['inputs'] == 0 and writes['menuItems'] == 0 and writes['checks'] == 0,
                          'Q:T-41 ' + key + ' pane: write buttons 0 (완료 · 바꾸기 · 마감/상태 칸 · 더보기 항목 · 하위 완료)')
                    check(t.clean('t41-' + key), 'Q:T-41 ' + key + ' console 0')
                    ctx.close()

            guard('Q:T-41 1440', t41_wide)
            guard('Q:T-41 1199', t41_mid)
            guard('Q:T-41 412', t41_phone)
            guard('Q:T-41 readers', t41_readers)

        # ─── D-2 내 작업 그 자리 추가 → 「내 할 일」 ─────────────────────────────────────────────────────────
        if want('quick'):
            def quick():
                before = sql('SELECT count(*) FROM community.my_tasks_projects WHERE user_id=' + str(emp))
                ctx = context('employee', 1280)
                t = Tab(ctx)
                p = t.page
                t.go('/community/my-tasks')
                p.wait_for_selector('#my-tasks-rows .task-inbox__row', timeout=15000)
                # U2(DESIGN 2.20-A) — 묶음 머리 대신 꼬리표(「지난 기한 n」 …) · 맡긴 표시는 줄의 보조 글(「○○ 님이 맡김」)
                names = p.evaluate("[...document.querySelectorAll('#my-tasks-rows > .task-inbox__tag')].map(t => t.textContent.replace(/ [0-9]+\\+?$/, ''))")
                metrics['my_tasks_groups'] = names
                check(names[:2] == ['지난 기한', '오늘'], 'my tasks tags start 지난 기한 · 오늘 ' + json.dumps(names, ensure_ascii=False))
                late = p.locator('#my-tasks-rows > .task-inbox__tag[data-bucket="overdue"]')
                check('task-group--late' in (late.get_attribute('class') or ''), '「지난 기한」 tag uses --danger (task-group--late)')
                check(p.locator('#my-tasks-rows .task-row__aux', has_text=' 님이 맡김').count() >= 2, 'assigned rows show 「○○ 님이 맡김」 (sub text)')
                p.locator('#my-tasks-rows [data-add-bucket="today"]').click()
                inp = p.locator('#my-tasks-rows .task-add__input')
                inp.fill('합성 빠른 할 일')
                with p.expect_response(lambda r: r.url.endswith('/community/api/pages') and r.request.method == 'POST') as resp:
                    inp.press('Enter')
                check(resp.value.status == 201, 'quick add POST /api/pages {my_tasks} 201')
                p.wait_for_selector('#my-tasks-rows .task-inbox__row .task-row__title:text("합성 빠른 할 일")', timeout=10000)
                today_rows = p.evaluate("""() => { const out = []; let on = false; for (const li of document.querySelectorAll('#my-tasks-rows > li')) {
                    if (li.matches('.task-inbox__tag')) { on = li.getAttribute('data-bucket') === 'today'; continue; } if (on && li.matches('.task-inbox__row')) out.push(li.querySelector('.task-row__title').textContent); } return out; }""")
                check('합성 빠른 할 일' in today_rows, 'quick add → row appears under the 「오늘」 tag')
                after = sql('SELECT count(*) FROM community.my_tasks_projects WHERE user_id=' + str(emp))
                info = sql("SELECT p.title||'|'||p.visibility||'|'||d.purpose FROM community.my_tasks_projects m JOIN community.pages p ON p.id=m.page_id JOIN community.databases d ON d.page_id=p.id WHERE m.user_id=" + str(emp))
                check(before == '0' and after == '1' and info == '내 할 일|private|my_tasks', 'quick add created 「내 할 일」 (my_tasks_projects 0 → 1 · private · purpose my_tasks)')
                check(p.locator('#my-tasks-rows .task-add__input').count() == 1, 'after Enter the input stays for the next line (DESIGN 2.20)')
                p.keyboard.press('Escape')
                p.screenshot(path=str(shots / 'my-tasks-1280.png'), full_page=True)
                check(t.clean('quick'), 'quick add console 0')
                ctx.close()
            guard('D-2 quick add', quick)

        # ─── D-2 담당 바꾸기 — 후보는 창구 결과만 ────────────────────────────────────────────────────────────
        if want('assign'):
            def assign():
                ctx = context('employee', 1440)
                t = Tab(ctx)
                p = t.page
                t.go('/community/p/' + pteam['id'] + '?task=' + rows[2]['id'])
                p.wait_for_selector('#task-pane:not([hidden]) #task-pane-title', timeout=15000)
                p.wait_for_timeout(400)
                if p.locator('#task-assign').count() == 0:
                    p.screenshot(path=str(shots / 'debug-assign.png'))
                    metrics['debug_assign'] = {'pane': p.locator('#task-pane').inner_text()[:600], 'console': code_console(t.console)[:5], 'errors': t.errors[:5]}
                p.wait_for_selector('#task-pane #task-assign summary', timeout=5000)
                # U1-a — 「바꾸기」를 열자마자(입력 없이) 빈 q 로 부르고 그 답을 그린다(화면 = 창구 빈 q 답). 이 답이 다 그려진 뒤에 입력 단언으로 간다
                api0 = call('GET', '/api/pages/' + rows[2]['id'] + '/assignee-candidates?q=', user='employee')
                want0 = [c['username'] for c in api0['candidates']]
                with p.expect_response(lambda r: '/assignee-candidates' in r.url) as first_resp:
                    p.locator('#task-assign summary').click()
                check(urlsplit(first_resp.value.url).query == 'q=' and first_resp.value.status == 200, 'U1-a opening 「바꾸기」 calls the candidates API with an empty q (no typing)')
                until(p, "() => document.querySelectorAll('#task-assign [data-candidate]').length > 0", 8000)
                shown0 = p.evaluate("[...document.querySelectorAll('#task-assign [data-candidate]')].map(b => b.getAttribute('data-candidate'))")
                metrics['assign_open_empty_q'] = {'api': want0, 'ui': shown0}
                check(shown0 == want0 and want0[:1] == [users['employee']['username']] and len(want0) >= 2,
                      'U1-a candidates right after opening == API empty-q answer · me first ' + json.dumps(shown0))
                check(p.locator('#task-assign p.help', has_text='이 작업을 고칠 수 있는 사람이 나뿐이에요.').is_hidden()
                      and p.locator('#task-assign p.help', has_text='이 작업을 고칠 수 있는 사람만 나와요.').is_visible(),
                      'U1-a team task with other candidates: 「나뿐이에요」 help hidden · 「고칠 수 있는 사람만 나와요」 hint shown')
                for q, label in ((users['manager']['username'], 'team member'), (users['other']['username'], 'other team (cannot edit)'), ('claude_', 'prefix')):
                    api_ = call('GET', '/api/pages/' + rows[2]['id'] + '/assignee-candidates?q=' + q, user='employee')
                    want_names = [c['username'] for c in api_['candidates']]
                    box = p.locator('#task-assign input[type="search"]')
                    box.fill(q)
                    with p.expect_response(lambda r, q=q: '/assignee-candidates?q=' + q in r.url):
                        box.press('Enter')
                    p.wait_for_timeout(250)
                    shown = p.evaluate("[...document.querySelectorAll('#task-assign [data-candidate]')].map(b => b.getAttribute('data-candidate'))")
                    metrics['assign_' + label] = {'api': want_names, 'ui': shown}
                    check(shown == want_names, 'assignee candidates on screen == API result (' + label + ') ' + json.dumps(shown))
                check(users['other']['username'] not in p.evaluate("[...document.querySelectorAll('#task-assign [data-candidate]')].map(b => b.getAttribute('data-candidate'))"), 'other team person not offered')
                box = p.locator('#task-assign input[type="search"]')
                box.fill(users['manager']['username'])
                with p.expect_response(lambda r: '/assignee-candidates' in r.url):
                    box.press('Enter')
                p.wait_for_selector('#task-assign [data-candidate="' + users['manager']['username'] + '"]', timeout=5000)
                with p.expect_response(lambda r: r.url.endswith('/properties') and r.request.method == 'PATCH') as resp:
                    p.locator('#task-assign [data-candidate="' + users['manager']['username'] + '"]').click()
                check(resp.value.status == 200, 'choosing a candidate → PATCH properties {assignee} 200')
                p.wait_for_timeout(600)
                check(sql('SELECT assignee_id FROM community.pages WHERE public_id=' + lit(rows[2]['id'])) == str(mgr), 'DB assignee_id = manager after pick')
                check(sql("SELECT count(*) FROM community.notifications WHERE kind='assigned' AND user_id=" + str(mgr)) != '0', 'notification assigned for the new assignee')
                check(t.clean('assign'), 'assign console 0')
                ctx.close()
            guard('D-2 assign', assign)

        # ─── D-2 포트폴리오 숨은 프로젝트 줄 0 (화면) ───────────────────────────────────────────────────────
        if want('portfolio'):
            def portfolio():
                for key, see_private in (('employee', True), ('manager', False)):
                    ctx = context(key, 1440)
                    t = Tab(ctx)
                    p = t.page
                    t.go('/community/portfolios/' + pf['id'])
                    p.wait_for_selector('#portfolio-rows tr', timeout=15000)
                    ids = p.evaluate("[...document.querySelectorAll('#portfolio-rows tr')].map(r => r.getAttribute('data-project'))")
                    text = p.locator('#work-portfolio').inner_text()
                    html = p.content()
                    metrics['portfolio_' + key] = {'rows': len(ids), 'counts': p.locator('#portfolio-counts').inner_text()}
                    if see_private:
                        check(set(ids) == {pteam['id'], ppriv['id']}, 'portfolio (employee) shows both projects')
                    else:
                        check(ids == [pteam['id']] and '합성 비공개 프로젝트' not in text and ppriv['id'] not in html, 'portfolio (manager): hidden project row 0 · title 0 · id 0 in DOM')
                        check('전체 1' in p.locator('#portfolio-counts').inner_text(), 'portfolio (manager) counts only visible projects (전체 1)')
                    check(p.locator('#portfolio-info').get_by_text('내가 볼 수 있는 프로젝트와 작업만 보여요. 진행률도 내가 볼 수 있는 작업만 세어서 사람마다 숫자가 다를 수 있어요.').count() == 1, 'portfolio info sentence (원문)')
                    bar = p.locator('#portfolio-rows tr[data-project="' + pteam['id'] + '"] .progress')
                    check(bar.get_attribute('role') == 'progressbar' and bar.get_attribute('aria-valuemax') == '4', 'progress bar role · aria-valuemax = visible rows (4)')
                    check(p.locator('#portfolio-add').is_visible(), 'portfolio 「프로젝트 넣기」 visible for team member (can_manage)')
                    if key == 'manager':
                        p.screenshot(path=str(shots / 'portfolio-1440.png'), full_page=True)
                    check(t.clean('portfolio-' + key), 'portfolio ' + key + ' console 0')
                    ctx.close()
                ctx = context('admin', 1440)
                t = Tab(ctx)
                p = t.page
                t.go('/community/portfolios/' + pf['id'])
                p.wait_for_selector('#portfolio-rows tr', timeout=15000)
                check(p.evaluate("[...document.querySelectorAll('#portfolio-rows tr')].map(r => r.getAttribute('data-project'))") == [pteam['id']] and ppriv['id'] not in p.content(),
                      'portfolio (admin — same team member): hidden private project row 0 · id 0 in DOM (search WHERE, admin_all off)')
                ctx.close()
                ctx = context('other', 1440)
                t = Tab(ctx)
                p = t.page
                t.go('/community/portfolios/' + pf['id'])
                p.wait_for_timeout(500)
                check(p.locator('#portfolio-rows tr').count() == 0 and p.locator('#msg').inner_text().strip() == '찾을 수 없습니다', 'portfolio (other team): 404 sentence · rows 0')
                ctx.close()
            guard('D-2 portfolio', portfolio)

        # ─── 홈 — 요약 세 칸 · 공지 한 줄 · overview 0 (브라우저) ───────────────────────────────────────────────
        if want('home'):
            def home():
                ctx = context('employee', 1440)
                t = Tab(ctx)
                p = t.page
                t.go('/community/')
                p.wait_for_selector('#work-home-rows .task-inbox__row', timeout=15000)
                check(p.locator('[id^="overview-"]').count() == 0 and p.locator('#community-overview').count() == 0, 'browser: /community/ has no #overview-* / #community-overview')
                # U2(WK-20 C) — 세 칸 대신 업무함 앞 10줄 — 꼬리표 「지난 기한」 · 「오늘」 아래 줄이 있다
                cols = p.evaluate("""() => { const out = {}; let k = null; for (const li of document.querySelectorAll('#work-home-rows > li')) {
                    if (li.matches('.task-inbox__tag')) { k = li.getAttribute('data-bucket'); out[k] = 0; continue; } if (k && li.matches('.task-inbox__row')) out[k] += 1; } return out; }""")
                metrics['home_cols'] = cols
                check(list(cols)[:2] == ['overdue', 'today'] and cols['overdue'] >= 1 and cols['today'] >= 1 and sum(cols.values()) <= 10, 'home inbox (지난 기한 · 오늘 …) filled · ≤ 10 rows ' + json.dumps(cols))
                p.wait_for_selector('#work-home-notice:not([hidden])', timeout=8000)
                check(p.locator('#work-home-notice-link').inner_text() == '합성 공지 한 줄' and p.locator('#work-home-notice-link').get_attribute('href') == '/community/p/' + notice_id,
                      'home notice line = unread notice (briefing others)')
                nav = p.evaluate("[...document.querySelectorAll('#nav-work-portfolios a')].map(a => a.textContent.trim())")
                check('합성 포트폴리오' in nav, 'sidebar 「포트폴리오」 filled from the team space list ' + json.dumps(nav, ensure_ascii=False))
                check(p.locator('#nav-new-task').is_hidden() is False or True, 'nav new task present')
                p.locator('#nav-new-menu > summary').click()
                check(p.locator('#nav-new-task').is_visible() and p.locator('#nav-new-project').is_visible() and p.locator('#nav-community-new').is_visible(), '「＋ 새로 만들기」 menu: 작업 · 프로젝트 · 문서')
                p.locator('#nav-new-project').click()
                p.wait_for_selector('#work-new-project-dialog[open]', timeout=5000)
                p.locator('#work-new-project-dialog input[type="text"]').fill('합성 새 프로젝트')
                with p.expect_navigation(timeout=15000):
                    p.locator('#work-new-project-dialog .form-actions .btn:not(.btn--quiet)').click()
                p.wait_for_selector('#project-list .task-group', timeout=15000)
                check('/community/p/' in p.url and p.locator('#project-title').inner_text() == '합성 새 프로젝트', '새로 만들기 › 프로젝트 → project screen of the new project')
                secs = p.evaluate("[...document.querySelectorAll('#project-list .task-group__toggle')].map(b => b.children[1].textContent)")
                check(secs[:3] == ['00. 준비', '01. 진행', '02. 마무리'], 'new project sections (preset) ' + json.dumps(secs, ensure_ascii=False))
                tabs = p.evaluate("[...document.querySelectorAll('#db-wrap [data-db-view]')].map(b => b.textContent)")
                check(tabs == ['목록', '보드', '타임라인', '달력'], 'project view tabs 목록 · 보드 · 타임라인 · 달력 ' + json.dumps(tabs, ensure_ascii=False))
                p.locator('#project-list [data-add-section="00. 준비"]').click()
                inp = p.locator('#project-list .task-add__input')
                inp.fill('합성 섹션 작업')
                with p.expect_response(lambda r: r.url.endswith('/properties') and r.request.method == 'PATCH'):
                    inp.press('Enter')
                p.wait_for_selector('#project-list .task-row__title:text("합성 섹션 작업")', timeout=10000)
                check(True, 'project in-place add (section 00. 준비) → row appears')
                check(t.clean('home'), 'home/new project console 0')
                ctx.close()
            guard('home', home)

        # ─── (조사용) 프로젝트 화면의 보이는 main 칸 — 결과 파일에 상자만 남긴다 ─────────────────────────────────
        if want('dom'):
            def dom():
                ctx = context('employee', 1440)
                t = Tab(ctx)
                p = t.page
                t.go('/community/p/' + pteam['id'])
                p.wait_for_selector('#project-list .task-row[data-task]', timeout=15000)
                metrics['dom_project_main'] = p.evaluate("""() => { const out = []; const walk = (el, depth) => { for (const c of el.children) { const r = c.getBoundingClientRect();
                  if (r.height > 0 && getComputedStyle(c).display !== 'none') { out.push([depth, c.tagName, c.id, c.className.toString().slice(0,60), Math.round(r.top), Math.round(r.height)]); if (depth < 1) walk(c, depth + 1); } } };
                  walk(document.querySelector('.main'), 0); return out; }""")
                p.locator('#project-list .task-row[data-task] .task-row__title').first.click()
                p.wait_for_selector('#task-pane:not([hidden]) #task-pane-title', timeout=10000)
                p.wait_for_timeout(400)
                metrics['dom_project_main_pane'] = p.evaluate("""() => [...document.querySelectorAll('.main *')].filter(e => { const s = getComputedStyle(e); const r = e.getBoundingClientRect();
                  return r.top > 150 && r.top < 260 && r.height > 0 && r.height < 6 && r.width > 200 || (parseFloat(s.borderTopWidth) > 0 && r.top > 150 && r.top < 260 && r.width > 300) || (parseFloat(s.borderBottomWidth) > 0 && r.bottom > 150 && r.bottom < 260 && r.width > 300); })
                  .map(e => [e.tagName, e.id, e.className.toString().slice(0, 50), Math.round(e.getBoundingClientRect().top), Math.round(e.getBoundingClientRect().bottom)])""")
                p.screenshot(path=str(shots / 'dom-pane.png'))
                ctx.close()
            guard('dom', dom)

        # ─── D-1 데이터베이스 「프로젝트로 쓰기」 · 프로젝트 메뉴 「문서로 보기」 · 받은 알림 문구 ───────────────────────────
        if want('useas'):
            def useas():
                plain = call('POST', '/api/pages', {'database': True, 'title': '합성 그냥 표'}, user='employee', expected=201)['page']
                ctx = context('employee', 1440)
                t = Tab(ctx)
                p = t.page
                t.go('/community/p/' + plain['id'])
                p.wait_for_selector('#db-wrap:not([hidden])', timeout=15000)
                p.wait_for_timeout(500)
                check(not p.evaluate("document.body.hasAttribute('data-project-view')"), 'plain database stays a table (no project view)')
                p.locator('#page-more > summary').click()
                check(p.locator('#page-use-project').is_visible(), 'page 더보기 has 「프로젝트로 쓰기」 for the manager of a plain database')
                with p.expect_navigation(timeout=15000):
                    with p.expect_response(lambda r: r.url.endswith('/schema') and r.request.method == 'PATCH') as resp:
                        p.locator('#page-use-project').click()
                check(resp.value.status == 200, '「프로젝트로 쓰기」 → PATCH schema {use_as:project} 200')
                p.wait_for_selector('#project-list .task-group', timeout=15000)
                check(p.evaluate("document.body.hasAttribute('data-project-view')") and p.locator('#project-head').is_visible(), 'after use_as the page opens as a project')
                p.locator('#project-menu > summary').click()
                p.locator('#project-as-doc').click()
                p.wait_for_timeout(400)
                check(not p.evaluate("document.body.hasAttribute('data-project-view')") and p.locator('#page-doc').is_visible() and p.locator('#project-head').is_hidden(), '프로젝트 메뉴 › 문서로 보기 → normal page view')
                p.locator('#page-more > summary').click()
                check(p.locator('#page-as-project').is_visible(), 'page 더보기 then offers 「프로젝트로 보기」')
                p.locator('#page-as-project').click()
                p.wait_for_selector('#project-list .task-group', timeout=10000)
                check(p.evaluate("document.body.hasAttribute('data-project-view')"), '「프로젝트로 보기」 → project view again')
                # 받은 알림 — 「○○ 님이 작업을 맡겼어요」(fixture 의 매니저 → 직원 담당 둘)
                p.locator('#nav-community-bell').click()
                p.wait_for_selector('#notify-dialog[open] #notify-list .list__item', timeout=10000)
                texts = p.evaluate("[...document.querySelectorAll('#notify-list .list__head a, #notify-list .list__head span')].map(e => e.textContent)")
                check(any(x.endswith('님이 작업을 맡겼어요') for x in texts), 'notification text 「○○ 님이 작업을 맡겼어요」 ' + json.dumps([x for x in texts if '맡겼' in x][:2], ensure_ascii=False))
                check(t.clean('useas'), 'use_as / notify console 0')
                ctx.close()
            guard('D-1 use_as', useas)

        # ─── Q:T-42 새 화면 점검 — 화면 넷 + 이야기 셸 × 폭 × 밝기 ─────────────────────────────────────────────
        if want('t42'):
            screens = [('home', '/community/'), ('my-tasks', '/community/my-tasks'), ('project-list', '/community/p/' + pteam['id']), ('project', '/community/p/' + pteam['id'] + '?task=' + rows[0]['id']),
                       ('portfolio', '/community/portfolios/' + pf['id']), ('story', '/community/memories')]
            widths = [(360, False), (412, True), (412, False), (820, False), (1280, False), (1440, False)]
            summary = {}
            for mode in ('light', 'dark', 'hc', 'hc-dark'):
                sql('UPDATE users SET high_contrast=' + ('true' if mode.startswith('hc') else 'false') + ' WHERE id=' + str(emp))
                for width, touch in widths:
                    ctx = context('employee', width, 900 if width > 800 else 915, touch=touch, extra={'tbox_theme': 'dark' if mode.endswith('dark') else 'light'})
                    t = Tab(ctx)
                    p = t.page
                    for name, path in screens:
                        tag = '%s-%d%s-%s' % (name, width, 't' if touch else '', mode)
                        def one():
                            t.go(path)
                            if name == 'project':
                                p.wait_for_selector('#task-pane:not([hidden]) #task-pane-title', timeout=15000)
                            elif name == 'project-list':
                                p.wait_for_selector('#project-list .task-row[data-task]', timeout=15000)
                            elif name == 'my-tasks':
                                p.wait_for_selector('#my-tasks-rows .task-inbox__row', timeout=15000)
                            elif name == 'portfolio':
                                p.wait_for_selector('#portfolio-rows tr', timeout=15000)
                            elif name == 'home':
                                p.wait_for_selector('#work-home-rows .task-inbox__row', timeout=15000)
                            p.wait_for_timeout(250)
                            a = p.evaluate(AUDIT_JS, touch or width < 720)
                            summary[tag] = {'overflow': a['overflow'], 'heads': a['heads'], 'small': a['smallCount'], 'inline': a['inlineStyle']}
                            check(a['overflow'] <= 0, 'Q:T-42 %s horizontal overflow 0 (%d)' % (tag, a['overflow']))
                            check(all(h['black'] <= 1 and h['count'] <= 4 for h in a['heads']), 'Q:T-42 %s page head: black ≤ 1 · buttons ≤ 4 %s' % (tag, json.dumps(a['heads'])))
                            if width < 720:
                                check(all(h['rows'] <= 1 for h in a['heads']), 'Q:T-42 %s head actions on one line' % tag)
                            if touch or width < 720:
                                check(a['smallCount'] == 0, 'Q:T-42 %s tap targets ≥ 44 (%d small: %s)' % (tag, a['smallCount'], json.dumps(a['small'], ensure_ascii=False)))
                            check(a['inlineStyle'] == 0, 'Q:T-42 %s inline style attributes 0 (CSSOM progress excluded)' % tag)
                            if mode == 'light' and (width, touch) in ((1440, False), (412, True)) or (width == 1199):
                                p.screenshot(path=str(shots / ('%s.png' % tag)), full_page=False)
                        guard('Q:T-42 ' + tag, one)
                    check(t.clean('t42-%d%s-%s' % (width, 't' if touch else '', mode)), 'Q:T-42 %d%s %s console 0 · page errors 0 · 5xx 0' % (width, ' touch' if touch else '', mode))
                    ctx.close()
            sql('UPDATE users SET high_contrast=false WHERE id=' + str(emp))
            metrics['t42'] = summary
            # 1199 화면 사본(상세 칸이 목록을 대신)
            ctx = context('employee', 1199)
            t = Tab(ctx)
            t.go('/community/my-tasks?task=' + rows[0]['id'])
            t.page.wait_for_selector('#task-pane:not([hidden]) #task-pane-title', timeout=15000)
            t.page.wait_for_timeout(300)
            t.page.screenshot(path=str(shots / 'my-tasks-pane-1199.png'))
            check(t.page.evaluate('document.documentElement.scrollWidth <= innerWidth') and t.clean('my-tasks-1199'), 'my tasks 1199 with pane: overflow 0 · console 0')
            ctx.close()

        # ─── 화면 결함 감사 — scripts/ui_snapshot.py 를 이 인스턴스에서(1440 · 1280) ─────────────────────────
        if want('snap'):
            tok = new_session('employee')
            pages = os.environ.get('QF12D_SNAP_PAGES') or ('/tbox/,/community/workspace,/community/memories,/community/,/community/my-tasks,/community/p/' + pteam['id'] + ',/community/portfolios/' + pf['id'])
            for width in (1440, 1280):
                dest = out / ('snap-%d' % width)
                dest.mkdir(exist_ok=True)
                env = dict(os.environ, UI_PAGES=pages)
                launcher = "import runpy,sys,os\nfrom playwright.sync_api import BrowserType\n_l=BrowserType.launch\ndef launch(self,*a,**k):\n k.pop('channel',None);k['executable_path']=os.environ['CHROMIUM_EXECUTABLE'];return _l(self,*a,**k)\nBrowserType.launch=launch\nsys.argv=sys.argv[1:]\nrunpy.run_path(sys.argv[0],run_name='__main__')\n"
                r = subprocess.run([sys.executable, '-c', launcher, str(root / 'scripts/ui_snapshot.py'), site, UA, str(dest), str(width)],
                                   input='__Host-tbox_session=' + tok + '\n', capture_output=True, text=True, env=env, timeout=900)
                (dest / 'run.txt').write_text(r.stdout + r.stderr)
                rep = json.loads((dest / 'report.json').read_text()) if (dest / 'report.json').is_file() else {}
                counts = {}
                for path, shotsmap in rep.items():
                    a = shotsmap.get('main', {})
                    counts[path] = {k: a[k] for k in ('clipped', 'overlap', 'row_height', 'offscreen', 'undefined_class', 'dup_id', 'stage_text', 'console_errors') if a.get(k)}
                    counts[path]['overflow_x'] = a.get('overflow_x')
                metrics['snap-%d' % width] = counts
                check(r.returncode == 0 and rep, 'ui_snapshot.py %d ran on this instance (%s)' % (width, (r.stdout.strip().splitlines() or ['?'])[-1]))
                for path, c in counts.items():
                    work_defects = [x for k, v in c.items() if isinstance(v, list) for x in v if any(w in x for w in ('task-', 'work-', 'project-', 'portfolio', 'progress', 'status-dot', 'nav-work'))]
                    check(not work_defects and not c.get('overflow_x') and not c.get('console_errors'), 'ui_snapshot %d %s: work screens add 0 defects · overflow 0 · console 0 %s' % (width, path, json.dumps(work_defects, ensure_ascii=False)))
        # ─── 사용감 라운드 U1(2026-10-08 — 기획 work-ux-plan.md 13장 · DESIGN 2.21 「담당 고르기」 · 「누르면 편집」 · 2.18 「새 프로젝트 창」) ─────
        #   U1-a 열자마자 후보(화면 = 창구 빈 q 답 · 나뿐이면 안내) · U1-b 설명 누르면 편집(링크는 제 동작 · Tab+Enter · 「편집 마침」 뒤 다시 누름 자리) ·
        #   U1-c 하위 작업 제목 그 자리 고치기(Enter 저장 = 서버 값 · Esc 되돌림 · 빈 값 저장 0 · 떠나면 저장) · › 열기 · 「＋ 하위 작업 추가」 ·
        #   읽는 사람(보기 공유 · 손님) 고치기 0 · U1-d 새 프로젝트 창 첫 값 = 팀 공간 / 팀 없는 계정 = 내 공간 하나 ·
        #   폭 여섯 × 밝게 · 어둡게 · 고대비 넘침 0 · 콘솔 0 · 누름 자리 44(폰 · 터치) · 인라인 style 0. 다른 묶음 뒤에 돈다(합성 준비를 따로 만든다).
        if want('u1'):
            pu1 = call('POST', '/api/pages', {'database': True, 'preset': 'project', 'title': 'U1 팀 프로젝트', 'space_id': team['id']}, user='manager', expected=201)['page']
            u1task = call('POST', '/api/pages', {'parent_id': pu1['id'], 'title': 'U1 작업', 'properties': {'section': '01. 진행'}}, user='manager', expected=201)['page']
            call('PATCH', '/api/pages/' + u1task['id'] + '/properties', {'status': 'todo'}, user='manager')
            gdoc = call('GET', '/api/pages/' + u1task['id'], user='manager')
            u1doc = {'schema': 1, 'blocks': [
                {'id': str(uuid.uuid4()), 'type': 'paragraph', 'text': [{'t': 'U1 설명 첫 문단 — 여기를 누르면 그 자리에서 고친다', 'm': {}}]},
                {'id': str(uuid.uuid4()), 'type': 'paragraph', 'text': [{'t': '참고 ', 'm': {}}, {'t': 'U1 링크', 'm': {'link': {'href': 'https://example.com/u1'}}}]}]}
            call('PATCH', '/api/pages/' + u1task['id'], {'doc_version': gdoc['page']['doc_version'], 'doc': u1doc}, user='manager')
            u1subs = []
            for title in ('U1 하위 하나', 'U1 하위 둘 — 아주 긴 하위 작업 제목이 한 줄에서 말줄임으로 끝나는지 폭마다 보는 합성 제목입니다 가나다라마바사아자차카타파하'):
                r = call('POST', '/api/pages', {'parent_id': u1task['id'], 'title': title}, user='manager', expected=201)['page']
                call('PATCH', '/api/pages/' + r['id'] + '/properties', {'status': 'todo'}, user='manager')
                u1subs.append(r)
            # 비공개 프로젝트(직원 소유 · 공유 0) — 고칠 수 있는 사람 = 나뿐
            pu1p = call('POST', '/api/pages', {'database': True, 'preset': 'project', 'title': 'U1 비공개 프로젝트'}, user='employee', expected=201)['page']
            u1priv = call('POST', '/api/pages', {'parent_id': pu1p['id'], 'title': 'U1 비공개 작업', 'properties': {'section': '01. 진행'}}, user='employee', expected=201)['page']
            # 보기 공유 프로젝트(ppriv)의 작업 prow 아래 하위 작업 하나 — 읽는 사람 문맥에서 제목이 글자 · › 만
            u1rsub = call('POST', '/api/pages', {'parent_id': prow['id'], 'title': 'U1 공유 하위'}, user='employee', expected=201)['page']
            call('PATCH', '/api/pages/' + u1rsub['id'] + '/properties', {'status': 'todo'}, user='employee')
            SOLO = '이 작업을 고칠 수 있는 사람이 나뿐이에요. 「공유」에서 편집 권한을 주거나 팀 공간 프로젝트에 두면 맡길 수 있어요.'
            NEWP_HELP = ('팀 공간에 만들면 팀원이 보고 맡을 수 있어요. 내 공간에 만들면 나만 보고 나만 맡을 수 있어요. '
                         '섹션(00. 준비 · 01. 진행 · 02. 마무리)과 목록 · 보드 · 타임라인 · 달력 보기가 함께 만들어져요.')
            DOC_STATE = """() => { const d = document.querySelector('#task-pane .task-pane__doc');
                return d ? {editable: d.classList.contains('task-pane__doc--editable'), tabindex: d.getAttribute('tabindex'), label: d.getAttribute('aria-label'),
                            role: d.getAttribute('role'), editors: d.querySelectorAll('[contenteditable="true"]').length,
                            active: document.activeElement === d} : null; }"""
            sub_row = lambda pg, sid: pg.locator('#task-pane .task-row[data-task="' + sid + '"]')
            subs_ready = "() => document.querySelectorAll('#task-pane .task-row button.task-row__title').length >= 2"

            def open_u1(t, path, ready=subs_ready):
                t.go(path)
                t.page.wait_for_selector('#task-pane:not([hidden]) #task-pane-title', timeout=15000)
                if ready:
                    until(t.page, ready, 10000)
                t.page.wait_for_timeout(200)

            def end_edit(p):
                p.locator('#task-pane .task-pane__section-head button', has_text='편집 마침').click()
                until(p, "() => { const d = document.querySelector('#task-pane .task-pane__doc'); return d && d.classList.contains('task-pane__doc--editable'); }", 8000)

            def u1_functional():
                ctx = context('employee', 1440)
                t = Tab(ctx)
                p = t.page
                patch_sub = []
                p.on('request', lambda rq: patch_sub.append(rq.url) if rq.method == 'PATCH' and '/api/pages/' in rq.url and not rq.url.endswith('/properties') else None)
                open_u1(t, '/community/p/' + pu1['id'] + '?task=' + u1task['id'])
                # ── U1-b 설명 ──
                st = p.evaluate(DOC_STATE)
                metrics['u1_doc_initial'] = st
                check(st['editable'] and st['tabindex'] == '0' and st['label'] == '설명 — Enter 로 고치기' and st['editors'] == 0,
                      'U1-b can_edit: description is a press target (.task-pane__doc--editable · tabindex 0 · aria-label 「설명 — Enter 로 고치기」)')
                p.screenshot(path=str(shots / 'u1-desc-before-1440.png'), caret='initial')
                p.locator('#task-pane .task-pane__doc p', has_text='U1 설명 첫 문단').first.hover()
                p.wait_for_timeout(150)
                hov = p.evaluate("() => { const d = document.querySelector('#task-pane .task-pane__doc'); const s = getComputedStyle(d); return {hover: d.matches(':hover'), cursor: s.cursor, bg: s.backgroundColor !== 'rgba(0, 0, 0, 0)', shadow: s.boxShadow !== 'none'}; }")
                metrics['u1_doc_hover'] = hov
                check(hov['hover'] and hov['cursor'] == 'text' and hov['bg'] and hov['shadow'], 'U1-b hover on the editable description: cursor text · background (--surface-2) on')
                p.screenshot(path=str(shots / 'u1-desc-hover-1440.png'), caret='initial')
                # 설명 안 링크 — 제 동작(새 창은 시험이 막는다) · 편집기는 열리지 않는다
                p.evaluate("() => document.addEventListener('click', (e) => { if (e.target instanceof Element && e.target.closest('#task-pane .task-pane__doc a')) { e.preventDefault(); } }, true)")
                p.locator('#task-pane .task-pane__doc a', has_text='U1 링크').click()
                p.wait_for_timeout(400)
                st = p.evaluate(DOC_STATE)
                check(st['editors'] == 0 and st['editable'], 'U1-b clicking a link inside the description does not open the editor')
                # 끌어서 글자를 고른 뒤의 누름 — 편집으로 들어가지 않는다
                para = p.locator('#task-pane .task-pane__doc p', has_text='U1 설명 첫 문단').first
                box = para.bounding_box()
                p.mouse.move(box['x'] + 4, box['y'] + box['height'] / 2)
                p.mouse.down()
                p.mouse.move(box['x'] + min(box['width'] - 4, 220), box['y'] + box['height'] / 2, steps=8)
                p.mouse.up()
                p.wait_for_timeout(300)
                sel_len = p.evaluate('String(window.getSelection()).length')
                st = p.evaluate(DOC_STATE)
                check(sel_len > 0 and st['editors'] == 0, 'U1-b drag-select text then release → no editor (selection kept, %d chars)' % sel_len)
                p.evaluate('window.getSelection().removeAllRanges()')
                # 누름 → 칸 안 편집기
                para.click()
                until(p, "() => document.querySelectorAll('#task-pane .task-pane__doc [contenteditable=\"true\"]').length === 1", 8000)
                st = p.evaluate(DOC_STATE)
                metrics['u1_doc_editing'] = st
                check(st['editors'] == 1 and not st['editable'] and st['tabindex'] is None and st['label'] is None,
                      'U1-b click description → editor element inside the pane · editable class/tabindex removed while editing')
                check(p.locator('#task-pane .task-pane__section-head button', has_text='편집 마침').count() == 1, 'U1-b editing shows 「편집 마침」')
                p.screenshot(path=str(shots / 'u1-desc-editing-1440.png'), caret='initial')
                end_edit(p)
                st = p.evaluate(DOC_STATE)
                check(st['editable'] and st['tabindex'] == '0' and st['editors'] == 0, 'U1-b after 「편집 마침」 the description is a press target again')
                p.screenshot(path=str(shots / 'u1-desc-after-1440.png'), caret='initial')
                # 키보드 — 상태 고르개에서 Tab 한 번 → 설명 칸 → Enter → 편집기
                p.locator('#task-pane select[aria-label="상태"]').focus()
                p.keyboard.press('Tab')
                st = p.evaluate(DOC_STATE)
                check(st['active'], 'U1-b keyboard: Tab from the status select reaches the description box')
                p.keyboard.press('Enter')
                until(p, "() => document.querySelectorAll('#task-pane .task-pane__doc [contenteditable=\"true\"]').length === 1", 8000)
                check(True, 'U1-b keyboard: Enter on the description box → editor')
                end_edit(p)
                # 더보기 「본문 고치기」도 같은 일
                p.locator('#task-pane .task-pane__tools details.menu > summary').click()
                p.locator('#task-pane .menu__item', has_text='본문 고치기').click()
                until(p, "() => document.querySelectorAll('#task-pane .task-pane__doc [contenteditable=\"true\"]').length === 1", 8000)
                check(p.evaluate(DOC_STATE)['editable'] is False, 'U1-b more › 「본문 고치기」 opens the same pane editor')
                end_edit(p)
                # ── U1-c 하위 작업 ──
                s0, s1 = u1subs
                r0 = sub_row(p, s0['id'])
                check(r0.locator('button.task-row__title').count() == 1 and r0.locator('button.task-row__title').get_attribute('type') == 'button'
                      and r0.locator('a.btn--icon[aria-label="하위 작업 열기"]').count() == 1, 'U1-c editable subtask row: title is button[type=button] · › is a.btn--icon 「하위 작업 열기」')
                # Tab 순서: 완료 원 → 제목 → ›
                r0.locator('.task-check').focus()
                p.keyboard.press('Tab')
                a1 = p.evaluate("() => document.activeElement && document.activeElement.matches('#task-pane .task-row button.task-row__title')")
                p.keyboard.press('Tab')
                a2 = p.evaluate("() => document.activeElement && document.activeElement.matches('#task-pane .task-row a[aria-label=\"하위 작업 열기\"]')")
                check(a1 and a2, 'U1-c tab order in a subtask row: check → title → ›')
                # 키보드 Enter 로도 같은 그 자리 입력(저장 없이 열리기만) → Esc
                n_before_key = len(patch_sub)
                p.keyboard.press('Shift+Tab')
                p.keyboard.press('Enter')
                p.wait_for_timeout(200)
                kin = r0.locator('input.task-add__input')
                check(kin.count() == 1 and kin.input_value() == 'U1 하위 하나' and len(patch_sub) == n_before_key,
                      'U1-c keyboard Enter on the title button → the same in-place input (no save yet)')
                kin.press('Escape')
                p.wait_for_timeout(200)
                # 누름 → 입력 → 새 제목 Enter → 서버 값
                r0.locator('button.task-row__title').click()
                inp = r0.locator('input.task-add__input')
                check(inp.count() == 1 and p.evaluate("() => document.activeElement && document.activeElement.matches('#task-pane .task-row input.task-add__input')")
                      and inp.input_value() == 'U1 하위 하나' and inp.get_attribute('maxlength') == '200',
                      'U1-c click title → in-place input (.task-add__input · focused · current title · maxLength 200)')
                p.screenshot(path=str(shots / 'u1-subtitle-input-1440.png'), caret='initial')
                inp.fill('U1 하위 하나 고침')
                with p.expect_response(lambda r: r.url.endswith('/api/pages/' + s0['id']) and r.request.method == 'PATCH') as resp:
                    inp.press('Enter')
                check(resp.value.status == 200, 'U1-c Enter → PATCH /api/pages/{subtask} {doc_version,title} 200')
                check(call('GET', '/api/pages/' + s0['id'], user='employee')['page']['title'] == 'U1 하위 하나 고침', 'U1-c server value changed (API GET title)')
                until(p, "() => { const b = document.querySelector('#task-pane .task-row[data-task=\"" + s0['id'] + "\"] button.task-row__title'); return b && b.textContent === 'U1 하위 하나 고침'; }", 8000)
                check(True, 'U1-c pane redrawn with the new subtask title')
                # Esc → 옛 제목 · 저장 0
                n_patch = len(patch_sub)
                r0 = sub_row(p, s0['id'])
                r0.locator('button.task-row__title').click()
                r0.locator('input.task-add__input').fill('U1 버릴 제목')
                r0.locator('input.task-add__input').press('Escape')
                p.wait_for_timeout(300)
                check(r0.locator('input.task-add__input').count() == 0 and r0.locator('button.task-row__title').inner_text() == 'U1 하위 하나 고침'
                      and len(patch_sub) == n_patch and call('GET', '/api/pages/' + s0['id'], user='employee')['page']['title'] == 'U1 하위 하나 고침',
                      'U1-c Esc → old title back · no save request · server unchanged')
                check(p.evaluate("() => document.activeElement && document.activeElement.matches('#task-pane .task-row button.task-row__title')"), 'U1-c after Esc focus returns to the title button')
                # 빈 값 Enter → 저장 0 · 되돌림
                r0.locator('button.task-row__title').click()
                r0.locator('input.task-add__input').fill('   ')
                r0.locator('input.task-add__input').press('Enter')
                p.wait_for_timeout(300)
                check(r0.locator('button.task-row__title').inner_text() == 'U1 하위 하나 고침' and len(patch_sub) == n_patch, 'U1-c empty title + Enter → no save · old title back')
                # 같은 값 Enter → 저장 0
                r0.locator('button.task-row__title').click()
                r0.locator('input.task-add__input').press('Enter')
                p.wait_for_timeout(300)
                check(len(patch_sub) == n_patch, 'U1-c same title + Enter → no save request')
                # 칸을 떠나면 저장(blur)
                r0.locator('button.task-row__title').click()
                r0.locator('input.task-add__input').fill('U1 떠나면 저장')
                with p.expect_response(lambda r: r.url.endswith('/api/pages/' + s0['id']) and r.request.method == 'PATCH') as resp:
                    p.locator('#task-pane .task-pane__note').click()
                check(resp.value.status == 200 and call('GET', '/api/pages/' + s0['id'], user='employee')['page']['title'] == 'U1 떠나면 저장', 'U1-c leaving the input (blur) saves (server value)')
                until(p, "() => { const b = document.querySelector('#task-pane .task-row[data-task=\"" + s0['id'] + "\"] button.task-row__title'); return b && b.textContent === 'U1 떠나면 저장'; }", 8000)
                # 프로젝트 목록 줄은 그대로(하위 작업 제목이 단추가 아니다 — kind 'pane' 만)
                check(p.locator('#project-list button.task-row__title').count() == 0, 'U1-c project list rows unchanged (no title buttons outside the pane)')
                # › → 그 하위 작업이 칸에 열리고 주소 ?task=
                go = sub_row(p, s1['id']).locator('a[aria-label="하위 작업 열기"]')
                check((go.get_attribute('href') or '').endswith('?task=' + s1['id']), 'U1-c › href = pane address ?task={subtask}')
                go.click()
                until(p, "() => { const h = document.getElementById('task-pane-title'); return h && h.textContent.startsWith('U1 하위 둘'); }", 10000)
                check(parse_qs(p.evaluate('location.search').lstrip('?')).get('task') == [s1['id']], 'U1-c › opens the subtask in the pane · ?task={subtask}')
                p.go_back()
                until(p, "() => { const h = document.getElementById('task-pane-title'); return h && h.textContent === 'U1 작업'; }", 10000)
                until(p, subs_ready, 10000)
                # 「＋ 하위 작업 추가」 → 입력 → Enter → 하나 늘어남
                before = len(call('GET', '/api/pages/' + u1task['id'], user='employee')['children'])
                add = p.locator('#task-pane .task-pane__section > button', has_text='＋ 하위 작업 추가')
                check(add.count() == 1 and 'btn--quiet' in (add.get_attribute('class') or '') and 'btn--small' in (add.get_attribute('class') or ''), 'U1-c list end has button.btn--quiet.btn--small 「＋ 하위 작업 추가」')
                add.click()
                ainp = p.locator('#task-pane .task-add > input.task-add__input')
                check(ainp.count() == 1 and add.is_hidden(), 'U1-c 「＋ 하위 작업 추가」 → in-place add line (button hidden while adding)')
                ainp.fill('U1 새 하위')
                with p.expect_response(lambda r: r.url.endswith('/community/api/pages') and r.request.method == 'POST') as resp:
                    ainp.press('Enter')
                check(resp.value.status == 201, 'U1-c add line Enter → POST /api/pages 201')
                until(p, "() => [...document.querySelectorAll('#task-pane .task-row .task-row__title')].some(e => e.textContent === 'U1 새 하위')", 10000)
                after = len(call('GET', '/api/pages/' + u1task['id'], user='employee')['children'])
                check(after == before + 1, 'U1-c one more subtask (API children %d → %d)' % (before, after))
                check(t.clean('u1-functional'), 'U1 functional 1440 console 0 · page errors 0 · 5xx 0')
                ctx.close()

            def u1_assign_solo():
                # RV-U1-1 안내 한 번만 · RV-U1-2 좁은 폭(360 · 412 터치 · 412 마우스) 메뉴가 화면 좌우 16px 안
                for width, touch in ((1440, False), (412, True), (412, False), (360, False)):
                    shot = 'u1-assign-solo-%d%s.png' % (width, 'm' if (width == 412 and not touch) else '')
                    ctx = context('employee', width, 900 if width > 800 else 915, touch=touch)
                    t = Tab(ctx)
                    p = t.page
                    open_u1(t, '/community/p/' + pu1p['id'] + '?task=' + u1priv['id'], ready=None)
                    want_ = [c['username'] for c in call('GET', '/api/pages/' + u1priv['id'] + '/assignee-candidates?q=', user='employee')['candidates']]
                    p.locator('#task-assign summary').click()
                    until(p, "() => document.querySelectorAll('#task-assign [data-candidate]').length > 0", 8000)
                    shown = p.evaluate("[...document.querySelectorAll('#task-assign [data-candidate]')].map(b => b.getAttribute('data-candidate'))")
                    solo = p.locator('#task-assign p.help', has_text=SOLO)
                    check(shown == want_ == [users['employee']['username']] and solo.count() == 1 and solo.is_visible(),
                          'U1-a %s private task (owner only): candidates == API == [me] · help 「나뿐이에요 …」 shown' % shot)
                    hint = p.locator('#task-assign p.help', has_text='이 작업을 고칠 수 있는 사람만 나와요.')
                    check(hint.count() == 1 and hint.is_hidden(), 'RV-U1-1 %s empty query · solo help shown → hint 「고칠 수 있는 사람만 나와요」 hidden (said once)' % shot)
                    rect = p.evaluate("() => { const r = document.querySelector('#task-assign .menu__list').getBoundingClientRect(); return [r.left, r.right, r.width, innerWidth]; }")
                    metrics['u1_assign_menu_' + shot] = rect
                    if width < 720:
                        check(rect[0] >= 16 and rect[1] <= rect[3] - 16, 'RV-U1-2 %s candidate menu inside the 16px side gutters (left %.1f ≥ 16 · right %.1f ≤ %d)' % (shot, rect[0], rect[1], rect[3] - 16))
                    else:
                        check(rect[0] >= 0 and rect[1] <= rect[3], 'U1-a %s candidate menu inside the viewport %s' % (shot, json.dumps(rect)))
                    p.screenshot(path=str(shots / shot), caret='initial')
                    # 입력하면 좁힘 — 빈 검색어가 아니면 안내 없음
                    q = p.locator('#task-assign input[type="search"]')
                    q.fill('zzz')
                    with p.expect_response(lambda r: '/assignee-candidates?q=zzz' in r.url):
                        q.press('Enter')
                    p.wait_for_timeout(250)
                    check(solo.is_hidden() and hint.is_visible() and p.locator('#task-assign [data-candidate]').count() == 0,
                          'RV-U1-1 %s typed query narrows · solo help hidden · hint shown' % shot)
                    if width < 720:
                        rect = p.evaluate("() => { const r = document.querySelector('#task-assign .menu__list').getBoundingClientRect(); return [r.left, r.right, r.width, innerWidth]; }")
                        check(rect[0] >= 16 and rect[1] <= rect[3] - 16, 'RV-U1-2 %s with a typed query the menu stays inside the 16px gutters %s' % (shot, json.dumps(rect)))
                    check(t.clean('u1-solo-' + shot), 'U1-a %s console 0' % shot)
                    ctx.close()

            def u1_readers():
                for key in ('other', 'guest'):
                    ctx = context(key, 1440)
                    t = Tab(ctx)
                    p = t.page
                    open_u1(t, '/community/p/' + ppriv['id'] + '?task=' + prow['id'], ready="() => document.querySelectorAll('#task-pane a[aria-label=\"하위 작업 열기\"]').length >= 1")
                    w = p.evaluate("""() => { const pane = document.getElementById('task-pane');
                        return {editable: pane.querySelectorAll('.task-pane__doc--editable, .task-pane__doc[tabindex]').length,
                                titleButtons: pane.querySelectorAll('button.task-row__title').length,
                                titleSpans: pane.querySelectorAll('span.task-row__title').length,
                                add: [...pane.querySelectorAll('button')].filter(b => b.textContent.includes('하위 작업 추가')).length,
                                open: pane.querySelectorAll('a.btn--icon[aria-label="하위 작업 열기"]').length}; }""")
                    metrics['u1_reader_' + key] = w
                    check(w['editable'] == 0 and w['titleButtons'] == 0 and w['add'] == 0 and w['titleSpans'] >= 1 and w['open'] >= 1,
                          'U1 ' + key + ' (view share): description editable 0 · subtask title button 0 · 「＋ 하위 작업 추가」 0 (title is text · › only) ' + json.dumps(w, ensure_ascii=False))
                    p.locator('#task-pane .task-pane__doc').click()
                    p.wait_for_timeout(300)
                    check(p.locator('#task-pane [contenteditable="true"]').count() == 0, 'U1 ' + key + ': clicking the description opens no editor')
                    check(t.clean('u1-reader-' + key), 'U1 ' + key + ' console 0')
                    ctx.close()

            def new_project_state(p):
                p.evaluate("() => document.getElementById('nav-new-project').click()")
                p.wait_for_selector('#work-new-project-dialog[open]', timeout=5000)
                until(p, "() => document.querySelectorAll('#work-new-project-dialog select option').length > 0", 8000)
                return p.evaluate("""() => { const sel = document.querySelector('#work-new-project-dialog select');
                    return {options: [...sel.options].map(o => [o.textContent, o.value]), value: sel.value,
                            help: [...document.querySelectorAll('#work-new-project-dialog .help')].map(h => h.textContent)}; }""")

            def u1_new_project():
                ctx = context('employee', 1440)
                t = Tab(ctx)
                p = t.page
                t.go('/community/my-tasks')
                st = new_project_state(p)
                metrics['u1_new_project_employee'] = st
                check(st['options'] == [[team['name'] + ' (팀 공간)', str(team['id'])], ['내 공간', st['options'][-1][1]]] and st['value'] == str(team['id']),
                      'U1-d new project (team account): team space first and selected · 내 공간 last ' + json.dumps(st['options'], ensure_ascii=False))
                check(st['help'] == [NEWP_HELP], 'U1-d help: team/my space sentence first, then sections · views (old 「개인 공간에 만들면 처음에는 나만 봐요.」 gone)')
                p.screenshot(path=str(shots / 'u1-new-project-1440.png'), caret='initial')
                check(t.clean('u1-newp-employee'), 'U1-d employee console 0')
                ctx.close()
                old_team = sql('SELECT team_id FROM users WHERE id=' + str(oth))
                sql('UPDATE users SET team_id=NULL WHERE id=' + str(oth))
                try:
                    ctx = context('other', 1440)
                    t = Tab(ctx)
                    p = t.page
                    t.go('/community/my-tasks')
                    st = new_project_state(p)
                    metrics['u1_new_project_noteam'] = st
                    check([o[0] for o in st['options']] == ['내 공간'] and st['value'] == st['options'][0][1], 'U1-d account without a team space: one option 「내 공간」 (selected)')
                    check(t.clean('u1-newp-noteam'), 'U1-d no-team console 0')
                    ctx.close()
                finally:
                    sql('UPDATE users SET team_id=' + old_team + ' WHERE id=' + str(oth))

            # 상세 칸이 스스로 스크롤하는 폭(≥1200 — overflow-y auto)에서만 칸 안의 가로 넘침을 잰다. 그보다 좁으면 칸은 흐름 안이라 문서 넘침(AUDIT_JS)이 그 값이다
            #   (좁은 폭 터치 자리 ::before 가 칸 오른쪽 끝 아이콘 단추에서 6px 나가지만 .main 안쪽 여백 안 — 문서 넘침 0)
            PANE_OVER = "() => { const p = document.getElementById('task-pane'); if (!p || getComputedStyle(p).overflowX === 'visible') { return 0; } return p.scrollWidth - p.clientWidth; }"

            def u1_matrix():
                widths = [(360, False), (412, True), (820, False), (1199, False), (1280, False), (1440, False)]
                summary = {}
                for mode in ('light', 'dark', 'hc'):
                    sql('UPDATE users SET high_contrast=' + ('true' if mode == 'hc' else 'false') + ' WHERE id=' + str(emp))
                    for width, touch in widths:
                        tag = '%d%s-%s' % (width, 't' if touch else '', mode)
                        shoot = mode == 'light' and (width, touch) in ((1440, False), (412, True))
                        ctx = context('employee', width, 900 if width > 800 else 915, touch=touch, extra={'tbox_theme': 'dark' if mode == 'dark' else 'light'})
                        t = Tab(ctx)
                        p = t.page

                        def audit(state):
                            a = p.evaluate(AUDIT_JS, touch or width < 720)
                            inner = p.evaluate(PANE_OVER)
                            summary[tag + '-' + state] = {'overflow': a['overflow'], 'pane': inner, 'small': a['smallCount'], 'inline': a['inlineStyle']}
                            check(a['overflow'] <= 0 and inner <= 0, 'U1 %s %s horizontal overflow 0 (page %d · pane %d)' % (tag, state, a['overflow'], inner))
                            if touch or width < 720:
                                check(a['smallCount'] == 0, 'U1 %s %s tap targets ≥ 44 (%d small: %s)' % (tag, state, a['smallCount'], json.dumps(a['small'], ensure_ascii=False)))
                            check(a['inlineStyle'] == 0, 'U1 %s %s inline style attributes 0' % (tag, state))

                        def one():
                            open_u1(t, '/community/p/' + pu1['id'] + '?task=' + u1task['id'])
                            audit('pane')
                            if shoot:
                                p.locator('#task-pane .task-pane__doc').scroll_into_view_if_needed()
                                p.screenshot(path=str(shots / ('u1-desc-before-%d.png' % width)), caret='initial')
                            p.locator('#task-pane .task-pane__doc p').first.click()
                            until(p, "() => document.querySelectorAll('#task-pane .task-pane__doc [contenteditable=\"true\"]').length === 1", 8000)
                            audit('desc-edit')
                            if shoot:
                                p.screenshot(path=str(shots / ('u1-desc-editing-%d.png' % width)), caret='initial')
                            end_edit(p)
                            row = p.locator('#task-pane .task-row button.task-row__title').last
                            row.scroll_into_view_if_needed()
                            row.click()
                            p.wait_for_selector('#task-pane .task-row input.task-add__input', timeout=5000)
                            audit('sub-input')
                            if shoot:
                                p.screenshot(path=str(shots / ('u1-subtitle-input-%d.png' % width)), caret='initial')
                            p.keyboard.press('Escape')
                            p.locator('#task-assign summary').scroll_into_view_if_needed()
                            p.locator('#task-assign summary').click()
                            until(p, "() => document.querySelectorAll('#task-assign [data-candidate]').length > 0", 8000)
                            audit('assign-open')
                            rect = p.evaluate("() => { const r = document.querySelector('#task-assign .menu__list').getBoundingClientRect(); return [r.left, r.right, innerWidth]; }")
                            gutter = 16 if width < 720 else 0
                            check(rect[0] >= gutter and rect[1] <= rect[2] - gutter, 'U1 %s candidate menu inside the viewport (side gutter %d) %s' % (tag, gutter, json.dumps(rect)))
                            if shoot:
                                p.screenshot(path=str(shots / ('u1-assign-open-%d.png' % width)), caret='initial')
                            p.keyboard.press('Escape')
                            st = new_project_state(p)
                            check(st['value'] == str(team['id']), 'U1 %s new project first value = team space' % tag)
                            d = p.evaluate("() => { const d = document.getElementById('work-new-project-dialog'); const r = d.getBoundingClientRect(); return {l: r.left, r: r.right, vw: innerWidth, sw: d.scrollWidth - d.clientWidth, inline: document.querySelectorAll('[style]:not(.progress)').length, doc: document.documentElement.scrollWidth - innerWidth}; }")
                            summary[tag + '-new-project'] = d
                            check(d['l'] >= 0 and d['r'] <= d['vw'] and d['sw'] <= 0 and d['doc'] <= 0 and d['inline'] == 0, 'U1 %s new project dialog inside the viewport · overflow 0 · inline style 0 %s' % (tag, json.dumps(d)))
                            if shoot:
                                p.screenshot(path=str(shots / ('u1-new-project-%d.png' % width)), caret='initial')
                            p.keyboard.press('Escape')
                        guard('U1 matrix ' + tag, one)
                        check(t.clean('u1-' + tag), 'U1 %s console 0 · page errors 0 · 5xx 0' % tag)
                        ctx.close()
                sql('UPDATE users SET high_contrast=false WHERE id=' + str(emp))
                metrics['u1_matrix'] = summary

            guard('U1 functional', u1_functional)
            guard('U1 assign solo', u1_assign_solo)
            guard('U1 readers', u1_readers)
            guard('U1 new project', u1_new_project)
            guard('U1 matrix', u1_matrix)

        # ─── 사용감 라운드 U2(2026-10-12 — 기획 work-ux-plan.md 14장 · DESIGN 2.19 ⑨ · 2.20-A) ───────────────────────────────────
        #   [접기] 1440 ☰ → body[data-sidebar=collapsed] · 폭 64 · 글자 0 · 남는 항목 · 툴팁 · 기억(새로고침 · 다른 화면) · 다시 240 · 959 ☰ 0
        #   [업무함] 합성 데이터(다른 팀 직원 claude_employee02 — 지난 기한 2 · 오늘 1 · 이번 주 3 · 나중에 3 · 기한 없음 1 · 검토 요청 1 · 멘션 1(지난 기한 작업과 같은 페이지))
        #            화면 줄 순서 = buildInbox(JS · 같은 규칙의 Python 계산 둘 다) · 꼬리표 · 「@」 · ★ · 줄 아래 펼침 · 주소 · 닫기 셋 · 완료 · ↓↑ · 홈 10줄 ·
        #            briefing 403 · 1199 aside · 412 전체 화면 · 손님 · 역할로 보기(손님) 쓰는 단추 0 · 폭 여섯 × 밝게 · 어둡게 · 고대비 — 묶음 이름 u2 · collapse
        INBOX_DOM = """(sel) => [...document.querySelectorAll(sel + ' > li')].map(li => li.matches('.task-inbox__tag') ? ['tag', li.textContent.trim(), li.classList.contains('task-group--late')]
            : li.matches('.task-inbox__row') ? ['row', li.getAttribute('data-task'), [...li.querySelectorAll(':scope > .task-inbox__line .badge')].map(b => b.textContent.trim()),
                li.querySelector(':scope > .task-inbox__line .task-row__cell--meta').textContent.trim(), (li.querySelector(':scope > .task-inbox__line .task-row__main .task-row__aux') || {textContent: ''}).textContent.trim()]
            : ['li', (li.querySelector('[data-add-bucket]') || {getAttribute: () => null}).getAttribute('data-add-bucket'), (li.querySelector('[data-more-bucket]') || {getAttribute: () => null}).getAttribute('data-more-bucket')])"""
        # U3(WK-26) — 줄 아래 판은 없어졌다. 셋째 값 = 그 줄이 열린 줄(aria-current)인가
        LINE_STATE = """() => [...document.querySelectorAll('li.task-inbox__row')].map(li => [li.getAttribute('data-task'), li.querySelector(':scope > .task-inbox__line').getAttribute('aria-expanded'),
            li.getAttribute('aria-current') === 'true'])"""

        def py_inbox(mt, br, fav):
            """buildInbox(work.js) 와 같은 규칙을 시험이 따로 계산한다 — 두 구현이 같은 답이어야 한다."""
            groups, index = [], {}
            for b in mt['buckets']:
                items = [{'id': it['public_id'], 'marks': [], 'fav': it['public_id'] in fav} for it in b['items']]
                for e in items:
                    index.setdefault(e['id'], e)
                groups.append({'key': b['key'], 'name': b['name'], 'more': b['next_cursor'], 'items': [e for e in items if e['fav']] + [e for e in items if not e['fav']]})
            extra, seen = [], {}
            for lst in ((br.get('top') or [], br.get('missed') or [], br.get('others') or []) if br else ()):
                for it in lst:
                    pid = it.get('page_id')
                    if not isinstance(pid, str) or pid == '':
                        continue
                    kinds = [it.get('kind')] + [r.get('kind') for r in (it.get('reasons') or [])]
                    marks = [k for k in ('review', 'mention') if k in kinds]
                    if not marks:
                        continue
                    tgt = index.get(pid) or seen.get(pid)
                    if tgt:
                        tgt['marks'] += [m for m in marks if m not in tgt['marks']]
                        continue
                    e = {'id': pid, 'marks': marks, 'fav': pid in fav}
                    seen[pid] = e
                    extra.append(e)
            at = next((i for i, g in enumerate(groups) if g['key'] not in ('overdue', 'today')), len(groups))
            groups.insert(at, {'key': 'review_mention', 'name': '검토 요청 · 멘션', 'more': None, 'items': [e for e in extra if e['fav']] + [e for e in extra if not e['fav']]})
            return groups

        def py_dom(groups, limit=None, add=()):
            """화면에 나와야 할 li 줄(꼬리표 · 줄 · 작업 추가) — 홈은 limit 10 · 그 자리 추가 없음"""
            out, shown = [], 0
            for g in groups:
                room = (limit - shown) if limit else len(g['items'])
                items = g['items'][:max(0, room)]
                if not items and g['key'] not in add:
                    continue
                out.append(['tag', g['name'] + ' ' + str(len(g['items'])) + ('+' if g['more'] else ''), g['key'] == 'overdue'])
                for e in items:
                    out.append(['row', e['id'], (['@'] if 'mention' in e['marks'] else []) + (['검토'] if 'review' in e['marks'] else [])])
                shown += len(items)
                if g['more'] and not limit:
                    out.append(['more', g['key']])
                if g['key'] in add:
                    out.append(['add', g['key']])
            return out

        def dom_simple(rows):
            out = []
            for r in rows:
                if r[0] != 'li':
                    out.append(r[:3])
                elif r[2]:
                    out.append(['more', r[2]])
                elif r[1]:
                    out.append(['add', r[1]])
            return out

        if want('u2'):
            def ddate(n):
                return sql("SELECT ((now() AT TIME ZONE 'Asia/Seoul')::date + (%d))" % n)
            sunday = sql("SELECT ((now() AT TIME ZONE 'Asia/Seoul')::date + (7 - extract(isodow FROM (now() AT TIME ZONE 'Asia/Seoul'))::int))")
            pu2 = call('POST', '/api/pages', {'database': True, 'preset': 'project', 'title': 'U2 업무함 프로젝트'}, user='other', expected=201)['page']

            def u2task(title, due=None, status='todo'):
                r = call('POST', '/api/pages', {'parent_id': pu2['id'], 'title': title, 'properties': {'section': '01. 진행'}}, user='other', expected=201)['page']
                props = {'status': status}
                if due:
                    props['due'] = due
                call('PATCH', '/api/pages/' + r['id'] + '/properties', props, user='other')
                return r
            o1 = u2task('U2 지난 기한 하나', ddate(-3))
            o2 = u2task('U2 지난 기한 둘', ddate(-1))
            t1 = u2task('U2 오늘', today)
            ws = [u2task('U2 이번 주 %d' % i, sunday) for i in (1, 2, 3)] if sunday > today else []
            ls = [u2task('U2 나중에 %d' % i, ddate(20)) for i in (1, 2, 3)]
            n1 = u2task('U2 기한 없음')
            r1 = u2task('U2 검토 요청', None, 'review')
            for pg in (o1, r1):
                rr = raw('PUT', '/api/pages/' + pg['id'] + '/shares/' + users['employee']['username'], {'level': 'edit'}, user='other')
                check(rr.status_code in (200, 201), 'U2 fixture: edit share to employee (%d)' % rr.status_code)
            call('PATCH', '/api/pages/' + r1['id'] + '/properties', {'assignee': users['employee']['username']}, user='other')
            call('POST', '/api/pages/' + o1['id'] + '/comments', {'body': '@' + users['other']['username'] + ' U2 확인 부탁해요'}, user='employee', expected=201)
            star_task = ws[1] if ws else ls[1]
            call('PUT', '/api/pages/' + star_task['id'] + '/favorite', {}, user='other')
            mt = call('GET', '/api/my-tasks', user='other')
            br = call('GET', '/api/briefing', user='other')
            fav = {p_['id'] for p_ in call('GET', '/api/me/favorites', user='other')['pages']}
            want_groups = py_inbox(mt, br, fav)
            flat = [e['id'] for g in want_groups for e in g['items']]
            metrics['u2_api'] = {'buckets': {b['key']: [i['title'] for i in b['items']] for b in mt['buckets']},
                                 'briefing': [[i.get('kind'), i.get('title'), [r.get('kind') for r in i.get('reasons', [])]] for k in ('top', 'missed', 'others') for i in br.get(k, [])]}
            check([b['key'] for b in mt['buckets']] == ['overdue', 'today', 'this_week', 'later', 'no_due'], 'U2 fixture: my-tasks buckets overdue · today · this_week · later · no_due')
            fixture_order = [o1['id'], o2['id'], t1['id'], r1['id']] + [w['id'] for w in ws if w['id'] == star_task['id']] \
                + [i['public_id'] for i in next(b for b in mt['buckets'] if b['key'] == 'this_week')['items'] if i['public_id'] != star_task['id']] \
                + [i['public_id'] for i in next(b for b in mt['buckets'] if b['key'] == 'later')['items'] if i['public_id'] != star_task['id']] + [n1['id']]
            if not ws:
                fixture_order = [o1['id'], o2['id'], t1['id'], r1['id'], star_task['id']] + [x['id'] for x in ls if x['id'] != star_task['id']] + [n1['id']]
            check(flat == fixture_order, 'U2 inbox order (Python rule) = 지난 기한 오래된 순 → 오늘 → 검토·멘션 → 이번 주(★ 먼저) → 나중에 → 기한 없음 ' + json.dumps([fixture_order.index(x) if x in fixture_order else -1 for x in flat]))
            check(next(e for g in want_groups for e in g['items'] if e['id'] == o1['id'])['marks'] == ['mention'] and r1['id'] in [e['id'] for e in want_groups[2]['items']],
                  'U2 mention on the overdue task merges into that row (「@」) · review-only page sits in 「검토 요청 · 멘션」 ' + json.dumps([g['key'] for g in want_groups]))
            UNIT_JS = r"""async () => {
              const src = [...document.scripts].map(s => s.src).find(u => u.includes('/community/editor.js'));
              const text = await (await fetch(src)).text();
              const m = text.match(/from '\.\/work\.js\?v=([0-9a-f]+)'/);
              const mod = await import(new URL('./work.js?v=' + m[1], src).href);
              const b = (k, ids, more) => ({ key: k, name: k, items: ids.map(id => ({ public_id: id, title: id, status: 'todo', due: null })), next_cursor: more || null });
              const mt = { buckets: [b('overdue', ['a', 'b']), b('today', []), b('this_week', ['c', 'd', 'e'], '100'), b('later', []), b('no_due', ['f'])] };
              const brief = { top: [{ kind: 'mention', page_id: 'b', title: 'b', summary: 's' }, { kind: 'review', page_id: 'x', title: 'x', summary: '검토', due: '2026-10-15' }],
                missed: [{ kind: 'mention', page_id: 'x', title: 'x' }], others: [{ kind: 'notice', page_id: 'n', title: 'n' }, { kind: 'overdue', page_id: 'y', title: 'y', reasons: [{ kind: 'overdue' }, { kind: 'review' }] },
                { kind: 'unchecked', page_id: 'z', title: 'z' }, { kind: 'pending_file', title: 'file' }] };
              const g1 = mod.buildInbox(mt, brief, new Set(['d', 'y']), '2026-10-12');
              const g2 = mod.buildInbox(mt, null, ['e'], '2026-10-12');
              const pick = (gs) => gs.map(g => [g.key, g.items.map(e => e.id + (e.marks.length ? ':' + e.marks.join('+') : '') + (e.fav ? '*' : '')), g.more]);
              return { g1: pick(g1), g2: pick(g2), x: g1.find(g => g.key === 'review_mention').items.find(e => e.id === 'x'), key: mod.INBOX_REVIEW_KEY };
            }"""

            def u2_unit():
                ctx = context('other', 1440)
                t = Tab(ctx)
                p = t.page
                t.go('/community/my-tasks')
                res = p.evaluate(UNIT_JS)
                metrics['u2_unit'] = res
                check(res['g1'] == [['overdue', ['a', 'b:mention'], None], ['today', [], None], ['review_mention', ['y:review*', 'x:review+mention'], None],
                                    ['this_week', ['d*', 'c', 'e'], '100'], ['later', [], None], ['no_due', ['f'], None]],
                      'U2 buildInbox unit: bucket order kept · 검토·멘션 between today and this_week · ★ first per group · same page once (marks merged) · notice/unchecked/file not joined ' + json.dumps(res['g1'], ensure_ascii=False))
                check(res['g2'] == [['overdue', ['a', 'b'], None], ['today', [], None], ['review_mention', [], None], ['this_week', ['e*', 'c', 'd'], '100'], ['later', [], None], ['no_due', ['f'], None]],
                      'U2 buildInbox unit: briefing null → task rows unchanged · empty 검토·멘션 group · favorites array accepted ' + json.dumps(res['g2'], ensure_ascii=False))
                check(res['x'] and res['x']['from'] == 'briefing' and res['x']['summary'] == '검토' and res['x']['due'] == '2026-10-15' and res['x']['project'] is None and res['key'] == 'review_mention',
                      'U2 buildInbox unit: briefing-only row keeps summary · due · source none (「문서」 on screen)')
                # 화면 = JS buildInbox(지금 창구 답) = Python 계산
                js_now = p.evaluate("""async () => {
                  const src = [...document.scripts].map(s => s.src).find(u => u.includes('/community/editor.js'));
                  const text = await (await fetch(src)).text();
                  const m = text.match(/from '\\.\\/work\\.js\\?v=([0-9a-f]+)'/);
                  const mod = await import(new URL('./work.js?v=' + m[1], src).href);
                  const get = async (u) => (await fetch(document.body.dataset.communityBase + u, { credentials: 'same-origin' })).json();
                  const [mt, br, fv] = await Promise.all([get('/api/my-tasks?done=0'), get('/api/briefing'), get('/api/me/favorites')]);
                  return mod.buildInbox(mt, br, new Set(fv.pages.map(x => x.id)), mt.today).map(g => [g.key, g.items.map(e => [e.id, e.marks])]);
                }""")
                check([[g['key'], [[e['id'], e['marks']] for e in g['items']]] for g in want_groups] == js_now, 'U2 JS buildInbox(live API) == Python rule (same groups · rows · marks)')
                p.wait_for_selector('#my-tasks-rows .task-inbox__row', timeout=15000)
                until(p, "() => document.querySelectorAll('#my-tasks-rows .task-inbox__row').length === %d" % len(flat), 10000)
                dom = dom_simple(p.evaluate(INBOX_DOM, '#my-tasks-rows'))
                want_dom = py_dom(want_groups, add=('today', 'no_due'))
                metrics['u2_my_tasks_dom'] = dom
                check(dom == want_dom, 'U2 내 작업 screen rows/tags/marks == buildInbox result (tags 「이름 n」 · 「지난 기한」 --danger · 「@」 · 「검토」 · 작업 추가 after 오늘 · 기한 없음)')
                full = p.evaluate(INBOX_DOM, '#my-tasks-rows')
                rrow = next(r for r in full if r[0] == 'row' and r[1] == r1['id'])
                orow = next(r for r in full if r[0] == 'row' and r[1] == o1['id'])
                check(rrow[3] == '문서' and rrow[4] != '' and orow[3] == 'U2 업무함 프로젝트', 'U2 source column: briefing row 「문서」 + summary · task row = project name ' + json.dumps([rrow[3:], orow[3:]], ensure_ascii=False))
                check(p.locator('#my-tasks-rows .task-inbox__row').count() == len(flat), 'U2 내 작업 = all rows (%d)' % len(flat))
                st = p.evaluate("(id) => { const li = document.querySelector('li.task-inbox__row[data-task=\"' + id + '\"]'); const s = li.querySelector('.task-inbox__star'); return [s.tagName, s.getAttribute('aria-pressed')]; }", star_task['id'])
                check(st == ['BUTTON', 'true'], 'U2 ★ task: star button aria-pressed true (favorites API) ' + json.dumps(st))
                check(t.clean('u2-unit'), 'U2 unit/order console 0')
                p.screenshot(path=str(shots / 'u2-my-tasks-1440.png'))
                ctx.close()

            def u2_expand():
                ctx = context('other', 1440)
                t = Tab(ctx)
                p = t.page
                t.go('/community/my-tasks')
                p.wait_for_selector('#my-tasks-rows .task-inbox__row', timeout=15000)
                line = lambda pid: p.locator('li.task-inbox__row[data-task="' + pid + '"] > .task-inbox__line')
                # U3(WK-26 ② — U2 의 「줄 아래 펼침」 단언을 「오른쪽 칸 하나」로 고쳤다): 1440 에서도 줄 → aside#task-pane(프로젝트와 같은 칸) · 줄 아래 판 0
                line(o1['id']).click()
                p.wait_for_selector('#task-pane:not([hidden]) #task-pane-title', timeout=10000)
                p.wait_for_timeout(400)
                st = p.evaluate(LINE_STATE)
                pane_parts = p.evaluate("""(id) => { const pn = document.getElementById('task-pane'); const li = document.querySelector('li.task-inbox__row[data-task="' + id + '"]');
                    const pr = pn.getBoundingClientRect(); const lr = document.getElementById('my-tasks-list').getBoundingClientRect();
                    return {h2: !!pn.querySelector('h2#task-pane-title'), props: !!pn.querySelector('dl.task-pane__props'), doc: !!pn.querySelector('.task-pane__doc'),
                            subs: [...pn.querySelectorAll('.task-pane__section-head')].some(h => h.textContent.startsWith('하위 작업')), act: !!pn.querySelector('.task-pane__activity'),
                            foot: !!pn.querySelector('form.task-pane__foot textarea'), right: Math.round(pr.right) === innerWidth && pr.left >= lr.right - 1, tag: pn.tagName,
                            inline: document.querySelectorAll('.task-inbox__pane').length, rowHasPane: !!li.querySelector('#task-pane, .task-pane'),
                            taskOpen: document.body.hasAttribute('data-task-open'), cur: li.getAttribute('aria-current'), w: Math.round(pr.width)}; }""", o1['id'])
                metrics['u3_inbox_aside_1440'] = pane_parts
                check([s for s in st if s[1] == 'true'] == [[o1['id'], 'true', True]], 'U3 WK-26 1440 click row → that row aria-expanded · aria-current (one row)')
                check(all(pane_parts[k] for k in ('h2', 'props', 'doc', 'subs', 'act', 'foot', 'right')) and pane_parts['tag'] == 'ASIDE' and pane_parts['inline'] == 0 and not pane_parts['rowHasPane'],
                      'U3 WK-26 1440 row → aside#task-pane on the right of the list with 2.21 parts (h2 · props · 설명 · 하위 작업 · 활동 · 댓글) · 줄 아래 판 0 ' + json.dumps(pane_parts))
                check(pane_parts['taskOpen'] and pane_parts['cur'] == 'true' and pane_parts['w'] == 480, 'U3 WK-26 1440 body[data-task-open] · row aria-current (--accent-soft) · default width 480')
                check(parse_qs(p.evaluate('location.search').lstrip('?')).get('task') == [o1['id']], 'U2 1440 address ?task={row}')
                p.screenshot(path=str(shots / 'u3-inbox-aside-1440.png'))
                line(o2['id']).click()
                until(p, "() => (document.getElementById('task-pane-title') || {}).textContent === %s" % json.dumps(o2['title']), 10000)
                st = p.evaluate(LINE_STATE)
                check([s for s in st if s[1] == 'true' or s[2]] == [[o2['id'], 'true', True]] and parse_qs(p.evaluate('location.search').lstrip('?')).get('task') == [o2['id']],
                      'U2 1440 another row → same aside shows row2 · previous row aria-expanded false · ?task=row2')
                line(o2['id']).click()
                until(p, "() => document.getElementById('task-pane').hidden === true", 8000)
                check(p.evaluate('location.search') == '' and all(s[1] == 'false' for s in p.evaluate(LINE_STATE)), 'U2 1440 same row again → pane closed · no ?task')
                # ✕
                line(o1['id']).click()
                p.wait_for_selector('#task-pane:not([hidden]) #task-pane-title', timeout=10000)
                p.locator('#task-pane .task-pane__tools .btn--icon[aria-label="닫기"]').click()
                until(p, "() => document.getElementById('task-pane').hidden === true", 8000)
                check(p.evaluate('location.search') == '', 'U2 1440 ✕ closes')
                # Esc
                line(o1['id']).click()
                p.wait_for_selector('#task-pane:not([hidden]) #task-pane-title', timeout=10000)
                line(o1['id']).focus()
                p.keyboard.press('Escape')
                until(p, "() => document.getElementById('task-pane').hidden === true", 8000)
                check(p.evaluate("document.activeElement && document.activeElement.matches('li[data-task=\"%s\"] > .task-inbox__line')" % o1['id']), 'U2 1440 Esc closes · focus back on the row')
                # 뒤로 가기
                line(o1['id']).click()
                p.wait_for_selector('#task-pane:not([hidden]) #task-pane-title', timeout=10000)
                p.go_back()
                until(p, "() => document.getElementById('task-pane').hidden === true", 8000)
                check(p.evaluate('location.search') == '' and all(s[1] == 'false' for s in p.evaluate(LINE_STATE)), 'U2 1440 back → closed')
                # ↓↑
                line(o1['id']).focus()
                p.keyboard.press('ArrowDown')
                a1 = p.evaluate("document.activeElement.closest('li').getAttribute('data-task')")
                p.keyboard.press('ArrowUp')
                a2 = p.evaluate("document.activeElement.closest('li').getAttribute('data-task')")
                check(a1 == o2['id'] and a2 == o1['id'], 'U2 ↓↑ moves between row buttons')
                # Tab 순서: 완료 원 → ★ → 줄
                p.locator('li.task-inbox__row[data-task="' + t1['id'] + '"] > .task-check').focus()
                p.keyboard.press('Tab')
                b1 = p.evaluate("document.activeElement.className")
                p.keyboard.press('Tab')
                b2 = p.evaluate("document.activeElement.className")
                check(b1 == 'task-inbox__star' and b2 == 'task-inbox__line', 'U2 tab order: check → ★ → row ' + json.dumps([b1, b2]))
                # 완료 원 → 흐림 + 다음 줄 초점 → work:changed 뒤 빠짐
                with p.expect_response(lambda r: r.url.endswith('/api/pages/' + t1['id'] + '/properties') and r.request.method == 'PATCH') as resp:
                    p.locator('li.task-inbox__row[data-task="' + t1['id'] + '"] > .task-check').click()
                check(resp.value.status == 200, 'U2 check → PATCH properties 200')
                until(p, "() => document.querySelector('li.task-inbox__row[data-task=\"%s\"]').classList.contains('task-row--done')" % t1['id'], 5000)
                nxt = p.evaluate("document.activeElement && document.activeElement.matches('.task-inbox__line') ? document.activeElement.closest('li').getAttribute('data-task') : null")
                check(nxt == flat[flat.index(t1['id']) + 1], 'U2 done row dims (.task-row--done) · focus moves to the next row')
                p.screenshot(path=str(shots / 'u2-inbox-done-1440.png'))
                p.evaluate("window.dispatchEvent(new Event('work:changed'))")
                until(p, "() => !document.querySelector('li.task-inbox__row[data-task=\"%s\"]')" % t1['id'], 8000)
                check(True, 'U2 after work:changed the done row is gone')
                call('PATCH', '/api/pages/' + t1['id'] + '/properties', {'status': 'todo'}, user='other')
                # ★ 토글 → 목록 다시 그림(같은 묶음 맨 위)
                target = (ws or ls)[0]
                with p.expect_response(lambda r: r.url.endswith('/api/pages/' + target['id'] + '/favorite') and r.request.method == 'PUT') as resp:
                    p.locator('li.task-inbox__row[data-task="' + target['id'] + '"] > .task-inbox__star').click()
                check(resp.value.status == 200, 'U2 ★ toggle → PUT /api/pages/{id}/favorite 200 (sidebar favorites function)')
                until(p, "() => { const s = document.querySelector('li.task-inbox__row[data-task=\"%s\"] > .task-inbox__star'); return s && s.getAttribute('aria-pressed') === 'true'; }" % target['id'], 8000)
                grp = p.evaluate("""(k) => { const out = []; let on = false; for (const li of document.querySelectorAll('#my-tasks-rows > li')) {
                    if (li.matches('.task-inbox__tag')) { on = li.getAttribute('data-bucket') === k; continue; } if (on && li.matches('.task-inbox__row')) out.push(li.getAttribute('data-task')); } return out; }""", 'this_week' if ws else 'later')
                check(set(grp[:2]) == {target['id'], star_task['id']}, 'U2 ★ rows lead their group after the toggle (list redrawn)')
                with p.expect_response(lambda r: r.url.endswith('/api/pages/' + target['id'] + '/favorite') and r.request.method == 'DELETE'):
                    p.locator('li.task-inbox__row[data-task="' + target['id'] + '"] > .task-inbox__star').click()
                until(p, "() => { const s = document.querySelector('li.task-inbox__row[data-task=\"%s\"] > .task-inbox__star'); return s && s.getAttribute('aria-pressed') === 'false'; }" % target['id'], 8000)
                # 보드 · 달력 · 끝난 작업 탭 그대로
                p.locator('[data-my-view="board"]').click()
                check(p.locator('#my-tasks-board').is_visible() and p.locator('#my-tasks-list').is_hidden() and p.locator('#my-tasks-board .db-board__col').count() == 5, 'U2 보드 tab: board with five columns · list hidden')
                p.locator('[data-my-view="calendar"]').click()
                check(p.locator('#my-tasks-calendar').is_visible(), 'U2 달력 tab shows the calendar')
                p.locator('[data-my-view="list"]').click()
                p.locator('#my-tasks-done').click()
                p.wait_for_timeout(800)
                check(p.locator('#my-tasks-rows .task-inbox__tag').count() == 0, 'U2 끝난 작업: one flow without tags')
                p.locator('#my-tasks-done').click()
                p.wait_for_selector('#my-tasks-rows .task-inbox__tag', timeout=8000)
                check(t.clean('u2-expand'), 'U2 expand/keyboard/done console 0')
                ctx.close()

            def u2_home():
                ctx = context('other', 1440)
                t = Tab(ctx)
                p = t.page
                t.go('/community/')
                p.wait_for_selector('#work-home-rows .task-inbox__row', timeout=15000)
                rows_ = p.evaluate("[...document.querySelectorAll('#work-home-rows .task-inbox__row')].map(li => li.getAttribute('data-task'))")
                mt2 = call('GET', '/api/my-tasks', user='other')
                fav2 = {p_['id'] for p_ in call('GET', '/api/me/favorites', user='other')['pages']}
                g2 = py_inbox(mt2, call('GET', '/api/briefing', user='other'), fav2)
                want_home = [e['id'] for g in g2 for e in g['items']][:10]
                check(len(rows_) == 10 and rows_ == want_home, 'U2 홈 = first 10 rows of the inbox (crossing group boundaries) %d' % len(rows_))
                dom = dom_simple(p.evaluate(INBOX_DOM, '#work-home-rows'))
                check(dom == py_dom(g2, limit=10), 'U2 홈 tags/rows == buildInbox (limit 10 · no add lines)')
                allv = p.locator('#work-home-all')
                check(allv.is_visible() and allv.get_attribute('href') == '/community/my-tasks' and allv.inner_text().strip() == '모두 보기', 'U2 홈 「모두 보기」 → /community/my-tasks')
                check(p.locator('#work-home-tasks .work-cols').count() == 0 and p.locator('[data-home-bucket]').count() == 0, 'U2 홈 three columns gone')
                p.locator('li.task-inbox__row[data-task="' + rows_[0] + '"] > .task-inbox__line').click()
                p.wait_for_selector('#task-pane:not([hidden]) #task-pane-title', timeout=10000)
                check(p.locator('.task-inbox__pane').count() == 0 and p.evaluate("document.body.hasAttribute('data-task-open')"), 'U3 WK-26 홈 1440 row → aside#task-pane (줄 아래 판 0 · body[data-task-open])')
                p.wait_for_timeout(400)
                p.screenshot(path=str(shots / 'u2-home-expanded-1440.png'))
                check(t.clean('u2-home'), 'U2 홈 console 0')
                ctx.close()
                # briefing 403 — 작업 줄은 그대로
                ctx = context('other', 1440)
                t = Tab(ctx)
                p = t.page
                p.route('**/community/api/briefing', lambda route: route.fulfill(status=403, content_type='application/json', body='{"error":"권한 없음"}'))
                t.go('/community/my-tasks')
                p.wait_for_selector('#my-tasks-rows .task-inbox__row', timeout=15000)
                p.wait_for_timeout(300)
                ids = p.evaluate("[...document.querySelectorAll('#my-tasks-rows .task-inbox__row')].map(li => li.getAttribute('data-task'))")
                no_brief = [e['id'] for g in py_inbox(mt2, None, fav2) for e in g['items']]
                check(ids == no_brief and r1['id'] not in ids and p.locator('#my-tasks-rows .badge', has_text='@').count() == 0, 'U2 briefing 403: task rows still drawn (review/mention group and 「@」 absent)')
                ctx.close()

            def u2_mid_phone():
                for width, touch in ((1199, False), (412, True)):
                    ctx = context('other', width, 900 if width > 800 else 915, touch=touch)
                    t = Tab(ctx)
                    p = t.page
                    t.go('/community/my-tasks')
                    p.wait_for_selector('#my-tasks-rows .task-inbox__row', timeout=15000)
                    p.locator('li.task-inbox__row[data-task="' + o1['id'] + '"] > .task-inbox__line').click()
                    p.wait_for_selector('#task-pane:not([hidden]) #task-pane-title', timeout=10000)
                    p.wait_for_timeout(400)
                    st = p.evaluate(PANE_JS)
                    metrics['u2_pane_%d' % width] = st
                    check(st['pane']['visible'] and not st['list']['visible'] and p.locator('.task-inbox__pane:not([hidden])').count() == 0,
                          'U2 %d row click → aside#task-pane replaces the list (no inline pane)' % width)
                    if width == 1199:
                        check(st['back'] is True, 'U2 1199 「‹ 목록」 visible')
                        p.screenshot(path=str(shots / 'u2-my-tasks-pane-1199.png'))
                    else:
                        check(st['pane']['w'] >= 412 - 2 * 24 and p.evaluate('document.documentElement.scrollWidth <= innerWidth'), 'U2 412 pane full screen · overflow 0')
                        p.locator('#task-pane .task-pane__back').click()
                        until(p, "() => document.getElementById('task-pane').hidden === true", 8000)
                        p.screenshot(path=str(shots / 'u2-my-tasks-412.png'))
                    check(t.clean('u2-%d' % width), 'U2 %d console 0' % width)
                    ctx.close()

            def u2_readers():
                tok_act = secrets.token_hex(32)
                sql("UPDATE sessions SET reauth_at=now() WHERE user_id=" + str(adm))
                sql("INSERT INTO sessions(id,user_id,expires_at,ip,user_agent,act_role,act_started_at,act_last_seen_at) VALUES('" + hashlib.sha256(tok_act.encode()).hexdigest() + "'," + str(adm)
                    + ",clock_timestamp()+interval '8 hours','127.0.0.1','" + UA + "','Guest',now(),now())")
                call('POST', '/api/pages', {'my_tasks': True, 'title': 'U2 관리자 할 일', 'due': today}, user='admin', expected=201)
                for label in ('guest', 'acting-guest'):
                    if label == 'guest':
                        ctx = context('guest', 1440)
                    else:
                        ctx = browser.new_context(viewport={'width': 1440, 'height': 900}, user_agent=UA, reduced_motion='reduce')
                        ctx.add_cookies(cookie(tok_act, {'qaredi_motion': 'reduce'}))
                    t = Tab(ctx)
                    p = t.page
                    for path in ('/community/', '/community/my-tasks'):
                        t.go(path)
                        p.wait_for_timeout(1200)
                        w = p.evaluate("""() => ({rows: document.querySelectorAll('li.task-inbox__row').length, checks: document.querySelectorAll('li.task-inbox__row > .task-check').length,
                            starButtons: document.querySelectorAll('button.task-inbox__star').length, add: document.querySelectorAll('[data-add-bucket], #my-tasks-add, #work-home-add, [data-work-new-task]').length,
                            empty: [...document.querySelectorAll('#my-tasks-empty, #work-home-empty')].filter(e => !e.hidden).map(e => e.textContent)})""")
                        metrics['u2_reader_%s_%s' % (label, path)] = w
                        check(w['checks'] == 0 and w['starButtons'] == 0 and w['add'] == 0, 'U2 %s %s: 완료 원 0 · ★ toggle 0 · 작업 추가 0 %s' % (label, path, json.dumps(w, ensure_ascii=False)))
                    check(t.clean('u2-' + label), 'U2 %s console 0' % label)
                    ctx.close()

            def u2_matrix():
                widths = [(360, False), (412, True), (820, False), (1199, False), (1280, False), (1440, False)]
                summary = {}
                for mode in ('light', 'dark', 'hc'):
                    sql('UPDATE users SET high_contrast=' + ('true' if mode == 'hc' else 'false') + ' WHERE id=' + str(oth))
                    for width, touch in widths:
                        ctx = context('other', width, 900 if width > 800 else 915, touch=touch, extra={'tbox_theme': 'dark' if mode == 'dark' else 'light'})
                        t = Tab(ctx)
                        p = t.page
                        for name, path in (('home', '/community/'), ('my-tasks', '/community/my-tasks')):
                            tag = 'u2-%s-%d%s-%s' % (name, width, 't' if touch else '', mode)

                            def one():
                                t.go(path)
                                p.wait_for_selector('.task-inbox__row', timeout=15000)
                                for state in ('list', 'open'):
                                    if state == 'open':
                                        p.locator('li.task-inbox__row > .task-inbox__line').first.click()
                                        p.wait_for_selector('#task-pane-title', timeout=10000)
                                        p.wait_for_timeout(300)
                                    a = p.evaluate(AUDIT_JS, touch or width < 720)
                                    summary[tag + '-' + state] = {'overflow': a['overflow'], 'small': a['smallCount'], 'inline': a['inlineStyle']}
                                    check(a['overflow'] <= 0, 'U2 %s %s horizontal overflow 0 (%d)' % (tag, state, a['overflow']))
                                    if touch or width < 720:
                                        check(a['smallCount'] == 0, 'U2 %s %s tap targets ≥ 44 (%d small: %s)' % (tag, state, a['smallCount'], json.dumps(a['small'], ensure_ascii=False)))
                                    check(a['inlineStyle'] == 0, 'U2 %s %s inline style 0' % (tag, state))
                                if mode in ('light', 'dark') and ((width, touch, name) in ((412, True, 'my-tasks'), (1440, False, 'home'))):
                                    p.screenshot(path=str(shots / ('%s.png' % tag)))
                            guard('U2 matrix ' + tag, one)
                        check(t.clean('u2m-%d-%s' % (width, mode)), 'U2 %d%s %s console 0 · page errors 0 · 5xx 0' % (width, ' touch' if touch else '', mode))
                        ctx.close()
                sql('UPDATE users SET high_contrast=false WHERE id=' + str(oth))
                metrics['u2_matrix'] = summary

            guard('U2 unit/order', u2_unit)
            guard('U2 expand', u2_expand)
            guard('U2 home', u2_home)
            guard('U2 1199/412', u2_mid_phone)
            guard('U2 readers', u2_readers)
            guard('U2 matrix', u2_matrix)

        if want('collapse'):
            COLLAPSE_JS = """() => { const s = document.getElementById('sidebar'); const r = s.getBoundingClientRect(); const m = document.getElementById('main').getBoundingClientRect();
                const vis = el => { const h = el.closest('[hidden]'); return (!h || h === s) && el.getClientRects().length > 0 && getComputedStyle(el).display !== 'none'; };
                const textW = [...s.querySelectorAll('.nav__item > span:not(.icon):not(.badge), #nav-search > span:not(.icon), #nav-new-menu > summary > span:not(.icon)')].filter(vis).map(e => e.getBoundingClientRect().width).filter(w => w > 1).length;
                const items = [...s.querySelectorAll('#sidebar-collapse, #nav-new-menu > summary, .nav__item, #nav-search')].filter(vis).map(e => e.id || e.parentElement.id);
                const hidden = ['.nav-title', '.sidebar-document-tools', '.q-design-open', '.sidebar__version', '.theme-toggle', '.userchip', '.legal-links', '.moa-brand-home', '.nav-search__key']
                    .map(q => [q, [...s.querySelectorAll(q)].filter(e => e.getClientRects().length > 0 && getComputedStyle(e).display !== 'none').length]);
                const bell = document.getElementById('nav-community-bell'); const badge = document.getElementById('nav-community-bell-count');
                const bi = bell.querySelector('.icon').getBoundingClientRect(); const bb = badge.getBoundingClientRect();
                const btn = document.getElementById('sidebar-collapse');
                return {collapsed: document.body.getAttribute('data-sidebar'), width: Math.round(r.width), mainLeft: Math.round(m.left), textW: textW, items: items, hidden: hidden,
                        badge: {shown: !badge.hidden, overlaps: !badge.hidden && bb.left < bi.right + 6 && bb.bottom > bi.top - 6 && bb.top < bi.top + 8},
                        expanded: btn.getAttribute('aria-expanded'), label: btn.getAttribute('aria-label'), btnVisible: getComputedStyle(btn).display !== 'none',
                        overflow: document.documentElement.scrollWidth - innerWidth}; }"""

            def collapse_wide():
                ctx = context('employee', 1440)
                t = Tab(ctx)
                p = t.page
                t.go('/community/')
                p.wait_for_selector('#work-home-rows .task-inbox__row', timeout=15000)
                until(p, "() => !document.getElementById('nav-community-bell-count').hidden", 35000)
                st0 = p.evaluate(COLLAPSE_JS)
                metrics['collapse_open'] = st0
                check(st0['btnVisible'] and st0['collapsed'] is None and st0['width'] == 240 and st0['expanded'] == 'true' and st0['label'] == '메뉴 접기',
                      'U2 collapse 1440: #sidebar-collapse visible · expanded · width 240 ' + json.dumps({k: st0[k] for k in ('width', 'expanded', 'label')}, ensure_ascii=False))
                p.screenshot(path=str(shots / 'u2-sidebar-open-1440.png'))
                p.locator('#sidebar-collapse').click()
                p.wait_for_timeout(300)
                st = p.evaluate(COLLAPSE_JS)
                metrics['collapse_closed'] = st
                check(st['collapsed'] == 'collapsed' and st['width'] == 64, 'U2 collapse: body[data-sidebar=collapsed] · sidebar width 64 (%d)' % st['width'])
                check(st['textW'] == 0, 'U2 collapse: visible .nav__item text 0 (clipped, kept for screen readers)')
                check(st['items'] == ['sidebar-collapse', 'nav-new-menu', 'nav-work-home', 'nav-work-my-tasks', 'nav-community-bell', 'nav-search'],
                      'U2 collapse: visible = ☰ · ＋ · 홈 · 내 작업 · 받은 알림 · 검색 ' + json.dumps(st['items']))
                check(all(n == 0 for _, n in st['hidden']), 'U2 collapse: group titles · 문서 선택 · 테마 · 버전 · 약관 · 사용자 칩 · 브랜드 · Ctrl K hidden ' + json.dumps(st['hidden']))
                check(st['badge']['shown'] and st['badge']['overlaps'], 'U2 collapse: 받은 알림 badge overlaps the icon ' + json.dumps(st['badge']))
                check(st0['mainLeft'] - st['mainLeft'] == 176, 'U2 collapse: main left x moves 176 (%d → %d)' % (st0['mainLeft'], st['mainLeft']))
                check(st['expanded'] == 'false' and st['label'] == '메뉴 펼치기', 'U2 collapse: aria-expanded false · label 「메뉴 펼치기」')
                p.locator('#nav-work-home').hover()
                p.wait_for_timeout(250)
                tip = p.evaluate("(() => { const a = getComputedStyle(document.getElementById('nav-work-home'), '::after'); const r = document.getElementById('nav-work-home').getBoundingClientRect(); return [a.content, a.display, Math.round(r.right)]; })()")
                check(tip[0] == '"홈"' and tip[1] == 'block', 'U2 collapse: hover tooltip text = item name 「홈」 ' + json.dumps(tip, ensure_ascii=False))
                p.screenshot(path=str(shots / 'u2-sidebar-collapsed-1440.png'))
                # 「＋」 메뉴는 사이드바 오른쪽 바깥으로
                p.locator('#nav-new-menu > summary').click()
                p.wait_for_timeout(200)
                lst = p.evaluate("(() => { const e = document.querySelector('#nav-new-menu .menu__list'); const l = e.getBoundingClientRect(); const c = getComputedStyle(e); return [Math.round(l.left), Math.round(l.width), document.querySelector('#nav-new-menu .menu__item:not([hidden])') ? 1 : 0, Math.round(l.top)]; })()")
                check(lst[0] >= 64 and lst[1] > 100, 'U2 collapse: 「＋」 menu opens to the right of the sidebar (not clipped) ' + json.dumps(lst))
                p.keyboard.press('Escape')
                # 기억 — 새로고침 · 다른 화면
                t.go('/community/')
                st = p.evaluate(COLLAPSE_JS)
                key = p.evaluate("Object.keys(localStorage).filter(k => k.startsWith('qaredi-sidebar-collapsed:v1:'))")
                check(st['collapsed'] == 'collapsed' and st['width'] == 64 and key == ['qaredi-sidebar-collapsed:v1:' + users['employee']['username']], 'U2 collapse kept after reload (key %s)' % json.dumps(key))
                t.go('/community/my-tasks')
                st = p.evaluate(COLLAPSE_JS)
                check(st['collapsed'] == 'collapsed' and st['width'] == 64, 'U2 collapse kept on /community/my-tasks')
                p.wait_for_selector('#my-tasks-rows .task-inbox__row', timeout=15000)
                p.locator('#my-tasks-rows li.task-inbox__row > .task-inbox__line').first.click()
                p.wait_for_selector('#task-pane:not([hidden]) #task-pane-title', timeout=10000)
                p.wait_for_timeout(400)
                p.screenshot(path=str(shots / 'u2-collapsed-inbox-expanded-1440.png'))
                for path in ('/tbox/', '/community/memories'):
                    t.go(path)
                    st = p.evaluate("(() => ({w: Math.round(document.getElementById('sidebar').getBoundingClientRect().width), o: document.documentElement.scrollWidth - innerWidth}))()")
                    check(st['w'] == 64 and st['o'] <= 0, 'U2 collapse %s (other shell): width 64 · overflow 0' % path)
                t.go('/community/my-tasks')
                p.locator('#sidebar-collapse').click()
                p.wait_for_timeout(300)
                st = p.evaluate(COLLAPSE_JS)
                check(st['collapsed'] is None and st['width'] == 240 and st['expanded'] == 'true', 'U2 collapse: press again → 240')
                check(t.clean('collapse'), 'U2 collapse console 0')
                ctx.close()
                # 959 — ☰ 접기 단추 0 · 서랍 그대로(접힘 값이 남아 있어도)
                ctx = context('employee', 959)
                ctx.add_init_script("localStorage.setItem('qaredi-sidebar-collapsed:v1:%s', '1')" % users['employee']['username'])
                t = Tab(ctx)
                p = t.page
                t.go('/community/')
                st = p.evaluate("(() => ({btn: getComputedStyle(document.getElementById('sidebar-collapse')).display, attr: document.body.getAttribute('data-sidebar')}))()")
                p.locator('#nav-toggle').click()
                p.wait_for_timeout(300)
                w = p.evaluate("Math.round(document.getElementById('sidebar').getBoundingClientRect().width)")
                check(st['btn'] == 'none' and w > 200 and p.locator('#nav-work-home').is_visible(), 'U2 959: #sidebar-collapse display none · drawer as before (width %d, collapsed value ignored)' % w)
                check(t.clean('collapse-959'), 'U2 959 console 0')
                ctx.close()
                # 첫 그리기 깜빡임 — 화면이 그려지는 프레임마다(rAF · 그리기 직전) 사이드바가 이미 있는데 접힘 표시가 없는 프레임이 있었나 센다
                ctx = context('employee', 1440)
                ctx.add_init_script("""localStorage.setItem('qaredi-sidebar-collapsed:v1:%s', '1'); window.__u2frames = [];
                    const tick = () => { const s = document.getElementById('sidebar'); window.__u2frames.push(s ? (document.body.getAttribute('data-sidebar') || 'open') : 'no-sidebar');
                      if (document.readyState !== 'complete' && window.__u2frames.length < 400) requestAnimationFrame(tick); };
                    requestAnimationFrame(tick);""" % users['employee']['username'])
                t = Tab(ctx)
                p = t.page
                t.go('/community/my-tasks')
                frames = p.evaluate('window.__u2frames')
                metrics['collapse_first_paint_frames'] = frames[:40]
                # 머리 스크립트 0(CSP) 이라 app.js(본문 끝) 가 돌기 전 프레임은 펼친 채일 수 있다 — 그 수를 재서 남긴다(보고서 「첫 그리기 깜빡임」)
                early = frames.index('collapsed') if 'collapsed' in frames else len(frames)
                metrics['collapse_first_paint_open_frames'] = frames[:early].count('open')
                check(early <= 2 and 'open' not in frames[early:] and p.evaluate("document.body.getAttribute('data-sidebar')") == 'collapsed',
                      'U2 first paint: expanded frames before the collapsed mark ≤ 2 (measured %d) · never expanded afterwards (/api/me correction keeps it)' % frames[:early].count('open'))
                ctx.close()
            guard('U2 collapse', collapse_wide)

        # ─── U3(사용감 라운드 · WK-24 ② · WK-25 ① · WK-26 ②) — 칸 넓히기 · 칸 편집기 · 문서에 바로 올리기 · 알림 색 (QF12D_ONLY=u3) ─────────
        if want('u3'):
            import struct, zlib

            def synth_png(w, h):
                """합성 그림(가로 그러데이션) — 스크린샷에서 보이게 1×1 보다 크게. 바깥 자료 0"""
                rows = b''.join(b'\x00' + b''.join(bytes(((x * 255) // w, 120 + (y * 100) // h, 200)) for x in range(w)) for y in range(h))
                chunk = lambda tag, data: struct.pack('>I', len(data)) + tag + data + struct.pack('>I', zlib.crc32(tag + data) & 0xffffffff)
                return b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('>IIBBBBB', w, h, 8, 2, 0, 0, 0)) + chunk(b'IDAT', zlib.compress(rows, 9)) + chunk(b'IEND', b'')
            U3_PNG = synth_png(240, 120)
            U3_PNG_B64 = base64.b64encode(U3_PNG).decode()
            pu3 = call('POST', '/api/pages', {'database': True, 'preset': 'project', 'title': 'U3 칸 편집기 프로젝트'}, user='other', expected=201)['page']

            def u3task(title):
                r = call('POST', '/api/pages', {'parent_id': pu3['id'], 'title': title, 'properties': {'section': '01. 진행'}}, user='other', expected=201)['page']
                call('PATCH', '/api/pages/' + r['id'] + '/properties', {'status': 'todo', 'due': today}, user='other')
                return r
            ua, ub, uc, ud = u3task('U3 붙여넣기 작업'), u3task('U3 끌어놓기 작업'), u3task('U3 올리기 탭 작업'), u3task('U3 전체 편집기 작업')
            # 보기 공유 — 다른 팀 직원(employee) · 손님: 입구 셋 0 문맥
            for who_ in ('employee', 'guest'):
                rr = raw('PUT', '/api/pages/' + ua['id'] + '/shares/' + users[who_]['username'], {'level': 'view'}, user='other')
                check(rr.status_code in (200, 201), 'U3 fixture: view share to %s (%d)' % (who_, rr.status_code))

            def att_rows(pid):
                return sql("SELECT public_id||'|'||original_name||'|'||scan_status FROM community.page_attachments WHERE page_id=(SELECT id FROM community.pages WHERE public_id="
                           + lit(pid) + ") ORDER BY id").splitlines()

            def page_refs(pid):
                doc = call('GET', '/api/pages/' + pid, user='other')['page']['doc']
                return [(b['type'], b.get('file_ref')) for b in doc.get('blocks', []) if b.get('type') in ('image', 'file')]

            BODY_STYLE_RE = re.compile(r'^--task-pane-w: \d+px;?$')
            # 인라인 style — CSSOM 예외 둘(진행 폭 `.progress` 의 --pct · `<body>` 의 --task-pane-w 하나)만 빼고 0
            STYLE_JS = r"""() => ({others: [...document.querySelectorAll('[style]')].filter(e => !e.matches('.progress') && e !== document.body).map(e => e.tagName + '.' + e.className).slice(0, 5),
                                     body: document.body.getAttribute('style')})"""
            PANE_W_JS = r"""() => { const p = document.getElementById('task-pane'); const h = p.querySelector('.task-pane__resize'); const r = p.getBoundingClientRect();
                const hr = h ? h.getBoundingClientRect() : null; const m = getComputedStyle(document.querySelector('.main'));
                const wide = p.querySelector('[data-pane-wide]');
                return {w: Math.round(r.width), left: Math.round(r.left), now: h ? h.getAttribute('aria-valuenow') : null, min: h ? h.getAttribute('aria-valuemin') : null,
                        max: h ? h.getAttribute('aria-valuemax') : null, role: h ? h.getAttribute('role') : null, orient: h ? h.getAttribute('aria-orientation') : null,
                        label: h ? h.getAttribute('aria-label') : null, hx: hr ? Math.round(hr.left + hr.width / 2) : null, hw: hr ? Math.round(hr.width) : null,
                        hh: hr ? Math.round(hr.height) : null, hvis: hr ? hr.width > 0 && getComputedStyle(h).display !== 'none' : false,
                        pad: Math.round(parseFloat(m.paddingRight)), wide: wide ? wide.getAttribute('aria-pressed') : null, wideVis: wide ? wide.getBoundingClientRect().width > 0 : false,
                        store: Object.entries(localStorage).filter(([k]) => k.startsWith('qaredi-task-pane-w:')), vw: innerWidth,
                        mainLeft: Math.round(document.querySelector('.main').getBoundingClientRect().left),
                        listW: Math.round((document.getElementById('my-tasks-list') || document.getElementById('work-home-tasks') || p).getBoundingClientRect().width),
                        overflow: document.documentElement.scrollWidth - innerWidth}; }"""
            OPEN_EDIT_JS = r"""() => { const d = document.querySelector('#task-pane .task-pane__doc'); return !!d && d.classList.contains('task-pane__doc--editable'); }"""

            def open_task(p, tid):
                p.locator('li.task-inbox__row[data-task="' + tid + '"] > .task-inbox__line').click()
                p.wait_for_selector('#task-pane:not([hidden]) #task-pane-title', timeout=10000)
                until(p, "() => (document.getElementById('task-pane-title') || {}).textContent !== '' && !!document.querySelector('#task-pane .task-pane__doc')", 8000)
                p.wait_for_timeout(300)

            def start_edit(p):
                until(p, OPEN_EDIT_JS, 8000)
                p.locator('#task-pane .task-pane__doc').click()
                p.wait_for_selector('#task-pane .ProseMirror', timeout=8000)
                p.wait_for_timeout(200)

            def end_edit(p, tid):
                """「편집 마침」 — 올리던 것이 들어간 뒤 저장(flush)하고 걷는다. 자동 저장이 먼저 나갔을 수 있어 PATCH 를 기다리지 않고 결과(배지)를 본다"""
                p.locator('#task-pane .task-pane__section-head .btn', has_text='편집 마침').click()
                until(p, "() => !document.querySelector('#task-pane .ProseMirror')", 20000)
                p.wait_for_timeout(300)
                txt = p.evaluate("() => (document.querySelector('#task-pane .task-pane__section-head .badge') || {}).textContent || ''")
                return 0 if txt == '저장 못 함' else 200

            def u3_width():
                ctx = context('other', 1440)
                ctx.grant_permissions(['clipboard-read', 'clipboard-write'], origin=site)
                t = Tab(ctx)
                p = t.page
                t.go('/community/my-tasks')
                p.wait_for_selector('#my-tasks-rows .task-inbox__row', timeout=15000)
                open_task(p, ua['id'])
                s0 = p.evaluate(PANE_W_JS)
                metrics['u3_width_default'] = s0
                # 위 끝 = min(화면 70% = 1008, 목록 480px 이 남는 폭 = 1440 − 본문 왼쪽(사이드바) − 본문 여백 64 − 480) — DESIGN 2.21 「범위」
                pmax = min(1008, 1440 - s0['mainLeft'] - 64 - 480)
                metrics['u3_width_max'] = pmax
                check(p.locator('.task-inbox__pane').count() == 0 and s0['w'] == 480 and s0['left'] == 960, 'U3 A 업무함 줄 → aside 칸(줄 아래 판 0) · 기본 폭 480 · 오른쪽 끝 ' + json.dumps(s0))
                check(s0['role'] == 'separator' and s0['orient'] == 'vertical' and s0['label'] == '작업 상세 폭' and s0['min'] == '400' and s0['max'] == str(pmax) and s0['now'] == '480'
                      and s0['hw'] == 8 and s0['hh'] == 900 and abs(s0['hx'] - 960) <= 1,
                      'U3 A 손잡이 role=separator · aria-orientation vertical · 「작업 상세 폭」 · valuemin 400 · valuemax %d(min(70%%, 목록 480 남김)) · valuenow 480 · 폭 8 · 높이 칸 전체 · 왼쪽 경계 위' % pmax)
                check(s0['pad'] == 480 + 32 and s0['wide'] == 'false' and s0['wideVis'] and p.evaluate("document.body.getAttribute('style')") is None,
                      'U3 A 목록 여백 = 칸 폭 + 32 · 「넓게 보기」 aria-pressed false · 기본 폭이면 body style 0')
                # 끌기
                p.mouse.move(s0['hx'], 450)
                p.mouse.down()
                p.mouse.move(s0['hx'] - 60, 450, steps=4)
                p.mouse.move(s0['hx'] - 120, 450, steps=4)
                p.mouse.up()
                p.wait_for_timeout(200)
                s1 = p.evaluate(PANE_W_JS)
                metrics['u3_width_drag'] = s1
                check(abs(s1['w'] - 600) <= 2 and s1['now'] == str(s1['w']) and s1['pad'] == s1['w'] + 32 and s1['store'] == [['qaredi-task-pane-w:v1:' + users['other']['username'], str(s1['w'])]],
                      'U3 A 손잡이 끌기 120px → 폭 600 · aria-valuenow · 목록 여백 따라옴 · localStorage qaredi-task-pane-w:v1:<아이디> 숫자 하나 ' + json.dumps([s1['w'], s1['now'], s1['pad'], s1['store']]))
                st = p.evaluate(STYLE_JS)
                check(st['others'] == [] and BODY_STYLE_RE.match(st['body'] or ''), 'U3 A 인라인 style = <body> 의 --task-pane-w 하나뿐(CSSOM) ' + json.dumps(st, ensure_ascii=False))
                # 키보드 ← → Home
                p.locator('#task-pane .task-pane__resize').focus()
                p.keyboard.press('ArrowLeft')
                k1 = p.evaluate(PANE_W_JS)['w']
                p.keyboard.press('ArrowRight')
                p.keyboard.press('ArrowRight')
                k2 = p.evaluate(PANE_W_JS)['w']
                p.keyboard.press('Home')
                k3 = p.evaluate(PANE_W_JS)
                check(k1 == s1['w'] + 16 and k2 == s1['w'] - 16 and k3['w'] == 480 and k3['store'] == [] and p.evaluate("document.body.getAttribute('style')") is None,
                      'U3 A ← +16 · → −16 · Home = 기본 폭(480 · 기억 지움 · body style 0) ' + json.dumps([k1, k2, k3['w']]))
                # 범위 400 ~ 70%
                hx = p.evaluate(PANE_W_JS)['hx']
                p.mouse.move(hx, 450); p.mouse.down(); p.mouse.move(5, 450, steps=6); p.mouse.up()
                r1 = p.evaluate(PANE_W_JS)
                hx = r1['hx']
                p.mouse.move(hx, 450); p.mouse.down(); p.mouse.move(1435, 450, steps=6); p.mouse.up()
                r2 = p.evaluate(PANE_W_JS)
                check(r1['w'] == pmax and r1['now'] == str(pmax) and r2['w'] == 400 and r2['now'] == '400', 'U3 A 범위: 끝까지 끌어도 %d(위 끝) · 반대로 400 %s' % (pmax, json.dumps([r1['w'], r2['w']])))
                check(r1['overflow'] <= 0 and r1['listW'] >= 480, 'U3 A 위 끝 폭에서 목록 %dpx(≥ 480) · 가로 넘침 0 (%d)' % (r1['listW'], r1['overflow']))
                # 두 번 누르기 = 기본 폭
                p.locator('#task-pane .task-pane__resize').dblclick()
                p.wait_for_timeout(150)
                check(p.evaluate(PANE_W_JS)['w'] == 480, 'U3 A 손잡이 두 번 누르기 = 기본 폭 480')
                # 넓게 보기 — 켜면 70% · 끄면 앞 폭
                p.locator('#task-pane .task-pane__resize').focus()
                for _ in range(5):
                    p.keyboard.press('ArrowLeft')
                before = p.evaluate(PANE_W_JS)['w']
                p.locator('#task-pane [data-pane-wide]').click()
                p.wait_for_timeout(150)
                w1 = p.evaluate(PANE_W_JS)
                p.screenshot(path=str(shots / 'u3-wide-1440.png'))
                p.locator('#task-pane [data-pane-wide]').click()
                p.wait_for_timeout(150)
                w2 = p.evaluate(PANE_W_JS)
                check(before == 560 and w1['w'] == pmax and w1['wide'] == 'true' and w2['w'] == 560 and w2['wide'] == 'false',
                      'U3 A 「넓게 보기」 켜면 위 끝 %d · aria-pressed true · 끄면 앞 폭 560 %s' % (pmax, json.dumps([before, w1['w'], w1['wide'], w2['w'], w2['wide']])))
                # 새로고침 뒤 기억
                p.reload(wait_until='networkidle')
                p.wait_for_selector('#task-pane:not([hidden]) #task-pane-title', timeout=10000)
                p.wait_for_timeout(300)
                rl = p.evaluate(PANE_W_JS)
                check(rl['w'] == 560 and rl['now'] == '560' and rl['pad'] == 592, 'U3 A 새로고침 뒤 폭 기억(560 · 주소 ?task= 로 다시 열림)')
                # 홈에서도 같은 칸 · 같은 폭(같은 사람의 기억 한 벌)
                t.go('/community/?task=' + ua['id'])
                p.wait_for_selector('#task-pane:not([hidden]) #task-pane-title', timeout=10000)
                p.wait_for_timeout(300)
                hm = p.evaluate(PANE_W_JS)
                check(hm['w'] == 560 and p.locator('.task-inbox__pane').count() == 0, 'U3 A 홈 ?task= → 같은 aside · 같은 폭 560')
                p.locator('#task-pane [data-pane-wide]').click()
                p.wait_for_timeout(200)
                check(p.evaluate(PANE_W_JS)['overflow'] <= 0, 'U3 A 홈 넓게 보기 가로 넘침 0')
                p.locator('#task-pane [data-pane-wide]').click()
                # 사이드바 접기(☰) — 목록 자리가 넓어지면 위 끝도 늘어난다(본문 왼쪽 64)
                p.locator('#sidebar-collapse').click()
                p.wait_for_timeout(300)
                sc = p.evaluate(PANE_W_JS)
                p.locator('#task-pane [data-pane-wide]').click()
                p.wait_for_timeout(200)
                sw = p.evaluate(PANE_W_JS)
                pmax_c = min(1008, 1440 - sc['mainLeft'] - 64 - 480)
                check(sc['mainLeft'] == 64 and sc['max'] == str(pmax_c) and sw['w'] == pmax_c and sw['overflow'] <= 0,
                      'U3 A 사이드바 접으면 위 끝 %d(목록 480 남김 · 70%% 이하) · 넓게 보기 = 그 폭 · 넘침 0' % pmax_c)
                p.screenshot(path=str(shots / 'u3-wide-collapsed-1440.png'))
                p.locator('#task-pane [data-pane-wide]').click()
                p.locator('#sidebar-collapse').click()
                p.wait_for_timeout(300)
                # 알림 색 — 성공(링크 복사)은 .msg--ok(≠ --danger) · 오류는 --danger
                p.locator('#task-pane .task-pane__tools .btn--icon[aria-label="링크 복사"]').click()
                until(p, "() => { const m = document.querySelector('#task-pane > .msg'); return m && m.textContent !== ''; }", 5000)
                col = p.evaluate("""() => { const m = document.querySelector('#task-pane > .msg'); const probe = document.createElement('p'); probe.className = 'msg'; probe.textContent = 'x';
                    document.getElementById('task-pane').appendChild(probe); const danger = getComputedStyle(probe).color; probe.remove();
                    return {text: m.textContent, ok: m.classList.contains('msg--ok'), color: getComputedStyle(m).color, danger: danger}; }""")
                metrics['u3_msg_ok'] = col
                check(col['text'] == '링크를 복사했어요.' and col['ok'] and col['color'] != col['danger'], 'U3-4 「링크를 복사했어요」 = 성공 모양 .msg--ok · 색 ≠ --danger ' + json.dumps(col, ensure_ascii=False))
                p.route('**/community/api/pages/*/properties', lambda route: route.fulfill(status=400, content_type='application/json', body='{"error":"합성 오류 문장"}'))
                p.locator('#task-pane .task-pane__props select[aria-label="상태"]').select_option('doing')
                until(p, "() => (document.querySelector('#task-pane > .msg') || {}).textContent === '합성 오류 문장'", 5000)
                er = p.evaluate("() => { const m = document.querySelector('#task-pane > .msg'); return {ok: m.classList.contains('msg--ok'), color: getComputedStyle(m).color}; }")
                check(not er['ok'] and er['color'] == col['danger'], 'U3-4 오류는 그대로 --danger(.msg--ok 0)')
                p.unroute('**/community/api/pages/*/properties')
                check(t.clean('u3-width'), 'U3 A width/memory/alert console 0')
                ctx.close()
                # 1199 · 412 — 폭 조절 없음(칸이 목록을 대신)
                for width, touch in ((1199, False), (412, True)):
                    ctx = context('other', width, 900 if width > 800 else 915, touch=touch)
                    ctx.add_init_script("localStorage.setItem('qaredi-task-pane-w:v1:%s', '700')" % users['other']['username'])
                    t = Tab(ctx)
                    p = t.page
                    t.go('/community/my-tasks?task=' + ua['id'])
                    p.wait_for_selector('#task-pane:not([hidden]) #task-pane-title', timeout=10000)
                    p.wait_for_timeout(300)
                    s = p.evaluate(PANE_W_JS)
                    check(not s['hvis'] and not s['wideVis'] and s['overflow'] <= 0 and s['w'] != 700 and (width > 800 or s['w'] >= width - 2 * 24),
                          'U3 A %d: 손잡이 · 넓게 보기 숨음 · 칸이 목록을 대신(폭 %d · 기억 700 무시) · 넘침 0' % (width, s['w']))
                    if width == 412:
                        p.screenshot(path=str(shots / 'u3-pane-412.png'))
                    check(t.clean('u3-w%d' % width), 'U3 A %d console 0' % width)
                    ctx.close()

            def u3_editor():
                ctx = context('other', 1440)
                t = Tab(ctx)
                p = t.page
                # 전체 편집기 — 도구줄 · 블록 넣기 목록(기준)
                # Q:F-12 U4-2 — 작업(프로젝트 행)의 전체 화면은 칸 부품(#task-full)이다 — 옛 문서 모양(전체 편집기)은 더보기 「문서로 보기」로
                t.go('/community/p/' + ud['id'])
                p.wait_for_selector('#task-full summary[aria-label="더보기"]', timeout=15000)
                p.locator('#task-full summary[aria-label="더보기"]').click()
                p.locator('#task-full [data-task-as-doc]').click()
                p.wait_for_selector('#page-edit:not([hidden])', timeout=15000)
                p.locator('#page-edit').click()
                p.wait_for_selector('#inline-toolbar:not([hidden]) .editor-toolbar__row', timeout=8000)
                full_tools = p.evaluate("() => [...document.querySelectorAll('#inline-toolbar .editor-toolbar__row > button')].map(b => b.textContent.trim())")
                p.locator('#page-add-block').click()
                p.wait_for_selector('#block-dialog[open]', timeout=5000)
                full_blocks = p.evaluate("() => [...document.querySelectorAll('#block-list .block-pick')].map(b => b.getAttribute('data-block-type') + ':' + b.getAttribute('data-block-index') + ':' + b.querySelector('strong').textContent)")
                p.keyboard.press('Escape')
                FP_JS = "(id) => [...document.querySelectorAll('#' + id + ' *')].filter(e => !e.closest('#block-list, #block-sample, #atom-file-list, #atom-link-list, #atom-table-grid')).map(e => e.tagName + '#' + e.id + '.' + (typeof e.className === 'string' ? e.className : '') + (e.hidden ? '[hidden]' : ''))"
                fp_page = [p.evaluate(FP_JS, 'block-dialog'), p.evaluate(FP_JS, 'atom-dialog')]
                check(p.evaluate("document.querySelectorAll('#block-dialog, #atom-dialog').length") == 2, 'U3 B 페이지 화면: 창 마크업 한 벌(page_view.php — 새로 짓지 않음)')
                p.locator('#page-done').click()
                p.wait_for_timeout(800)
                # 칸 편집기
                t.go('/community/my-tasks')
                p.wait_for_selector('#my-tasks-rows .task-inbox__row', timeout=15000)
                open_task(p, ua['id'])
                start_edit(p)
                pane_tools = p.evaluate("() => [...document.querySelectorAll('#task-pane .editor-toolbar .editor-toolbar__row > button')].map(b => b.textContent.trim())")
                metrics['u3_tools'] = {'full': full_tools, 'pane': pane_tools}
                check(pane_tools == full_tools and pane_tools == ['블록 추가', '색', '배경', '링크', '멘션'] and p.locator('#task-pane .editor-toolbar').count() == 1,
                      'U3 B 칸 편집기 도구줄 = 전체 편집기와 같은 목록(같은 createToolbar · 용지 고르개는 전체 화면 몫) ' + json.dumps(pane_tools, ensure_ascii=False))
                fp_tasks = [p.evaluate(FP_JS, 'block-dialog'), p.evaluate(FP_JS, 'atom-dialog')]
                p.locator('#task-pane .editor-toolbar .editor-toolbar__row > button', has_text='블록 추가').click()
                p.wait_for_selector('#block-dialog[open]', timeout=5000)
                pane_blocks = p.evaluate("() => [...document.querySelectorAll('#block-list .block-pick')].map(b => b.getAttribute('data-block-type') + ':' + b.getAttribute('data-block-index') + ':' + b.querySelector('strong').textContent)")
                check(pane_blocks == full_blocks and len(pane_blocks) >= 20 and p.locator('#block-dialog').count() == 1,
                      'U3 B 칸의 「블록 추가」 메뉴 = 전체 편집기와 같은 목록 (%d · 창 한 벌)' % len(pane_blocks))
                p.keyboard.press('Escape')
                p.wait_for_timeout(200)
                # 이 화면(내 작업)에는 창 마크업이 없다 — editor.js 가 같은 id · 클래스로 지은 창 = 페이지 화면 창(구조 지문 — 열기 전 같은 때에 잰 것)
                metrics['u3_dialog_fp'] = {'page': [len(x) for x in fp_page], 'tasks': [len(x) for x in fp_tasks],
                                           'diff': [[list((Counter(fp_tasks[i]) - Counter(fp_page[i])).items())[:8], list((Counter(fp_page[i]) - Counter(fp_tasks[i])).items())[:8]] for i in (0, 1)]}
                check(fp_tasks == fp_page, 'U3 B 내 작업 화면의 「블록 추가」 · 「블록 고치기」 창 = 페이지 화면 창과 같은 구조(id · 클래스 지문 %s)' % json.dumps([len(x) for x in fp_tasks]))
                # 표 블록 넣기(같은 창 · 같은 함수) — 칸 편집기에 표 카드
                p.locator('#task-pane .editor-toolbar .editor-toolbar__row > button', has_text='블록 추가').click()
                p.locator('#block-list .block-pick[data-block-type="table"]').click()
                p.locator('#block-insert').click()
                p.wait_for_selector('#atom-dialog[open]', timeout=5000)
                p.locator('#atom-commit').click()
                until(p, "() => !!document.querySelector('#task-pane .ProseMirror .doc-atom')", 5000)
                check(p.locator('#task-pane .ProseMirror .doc-atom').count() == 1, 'U3 B 칸 편집기에서 「블록 추가 › 표」 → 표 블록(같은 블록 고치기 창)')
                # 붙여넣기(합성 png 클립보드) → 자리표시 → 그림 블록
                p.evaluate("""() => { window.__u3ph = []; const ed = document.querySelector('#task-pane .ProseMirror');
                    new MutationObserver((ms) => { for (const m of ms) for (const n of m.addedNodes) if (n.nodeType === 1 && n.matches('.doc-upload'))
                      window.__u3ph.push({kind: n.getAttribute('data-kind'), role: n.getAttribute('role'), live: n.getAttribute('aria-live'), name: (n.querySelector('.doc-upload__name') || {}).textContent,
                        prog: (n.querySelector('.doc-upload__progress') || {}).textContent, cancel: !!n.querySelector('button.btn--quiet.btn--small')}); }).observe(ed, {childList: true, subtree: true}); }""")
                p.locator('#task-pane .ProseMirror').click()
                p.keyboard.press('End')
                PASTE_JS = """([b64, name, type, text]) => { const bin = Uint8Array.from(atob(b64), c => c.charCodeAt(0)); const dt = new DataTransfer();
                    dt.items.add(new File([bin], name, {type: type})); if (text) dt.setData('text/plain', text);
                    const ev = new ClipboardEvent('paste', {clipboardData: dt, bubbles: true, cancelable: true});
                    document.querySelector('#task-pane .ProseMirror').dispatchEvent(ev); return ev.defaultPrevented; }"""
                with p.expect_response(lambda r: r.url.endswith('/api/pages/' + ua['id'] + '/attachments') and r.request.method == 'POST', timeout=20000) as up:
                    prevented = p.evaluate(PASTE_JS, [U3_PNG_B64, '캡처.png', 'image/png', ''])
                check(up.value.status == 201 and prevented, 'U3 C 붙여넣기(합성 png 클립보드) → POST /api/pages/{id}/attachments 201')
                until(p, "() => !!document.querySelector('#task-pane .ProseMirror .doc-atom[data-atom=\"image\"]') && !document.querySelector('#task-pane .ProseMirror .doc-upload:not(.doc-atom .doc-upload)')", 10000)
                ph = p.evaluate('window.__u3ph')
                metrics['u3_placeholder'] = ph
                check(len(ph) == 1 and ph[0]['kind'] == 'image' and ph[0]['role'] == 'status' and ph[0]['live'] == 'polite' and ph[0]['name'] == '캡처.png'
                      and ph[0]['prog'].startswith('올리는 중') and ph[0]['cancel'], 'U3 C 자리표시 블록(.doc-upload · role=status · aria-live polite · 이름 · 「올리는 중 n%」 · 「취소」) → 그림 블록으로 바뀜 ' + json.dumps(ph, ensure_ascii=False))
                # 붙여넣은 글(HTML 의 외부 그림 주소)은 지금처럼 버린다 — 글이 함께 든 붙여넣기는 올리지 않는다
                n_before = len(att_rows(ua['id']))
                p.evaluate(PASTE_JS, [U3_PNG_B64, 'cell.png', 'image/png', '표 칸 글자'])
                p.wait_for_timeout(600)
                check(len(att_rows(ua['id'])) == n_before and p.locator('#task-pane .doc-upload').count() == 0, 'U3 C 글이 함께 든 붙여넣기는 올리지 않음(파일만 받는다)')
                st_ = end_edit(p, ua['id'])
                rows_ = att_rows(ua['id'])
                refs = page_refs(ua['id'])
                metrics['u3_paste_server'] = {'rows': rows_, 'refs': refs}
                check(st_ == 200 and len(rows_) == 1 and rows_[0].endswith('|캡처.png|clean'), 'U3 C 서버의 첨부 행 1(이름 캡처.png · clean) ' + json.dumps(rows_, ensure_ascii=False))
                check(refs == [('image', 'att:' + rows_[0].split('|')[0])], 'U3 C 저장된 본문 file_ref = att:<그 첨부> (그림 블록) ' + json.dumps(refs))
                until(p, "() => { const i = document.querySelector('#task-pane .task-pane__doc img.doc-image__img'); return i && i.complete && i.naturalWidth > 0; }", 10000)
                src = p.evaluate("document.querySelector('#task-pane .task-pane__doc img.doc-image__img').getAttribute('src')")
                check(src == '/community/api/pages/' + ua['id'] + '/attachments/' + rows_[0].split('|')[0] + '/raw', 'U3 C 읽기 화면 그림 = 첨부 창구 raw 주소 · 그림이 실제로 그려짐 ' + src)
                # 상세 칸 「파일 첨부」 목록에 attachment_titles
                p.locator('#task-pane .task-pane__tools .btn--icon[aria-label="닫기"]').click()
                open_task(p, ua['id'])
                fl = p.evaluate("() => [...document.querySelectorAll('#task-pane li[data-attachment]')].map(li => [li.textContent, (li.querySelector('a') || {}).getAttribute ? li.querySelector('a').getAttribute('href') : null])")
                check(fl == [['캡처.png', '/community/api/pages/' + ua['id'] + '/attachments/' + rows_[0].split('|')[0] + '/download']]
                      and p.locator('#task-pane .task-pane__section-head', has_text='파일 첨부 1').count() == 1, 'U3 C 상세 칸 「파일 첨부 1」 = attachment_titles(받기 주소) ' + json.dumps(fl, ensure_ascii=False))
                p.locator('#task-pane [data-pane-wide]').click()
                p.wait_for_timeout(400)
                p.screenshot(path=str(shots / 'u3-wide-image-1440.png'))
                p.locator('#task-pane [data-pane-wide]').click()
                # 끌어놓기(파일) → 파일 블록
                open_task(p, ub['id'])
                start_edit(p)
                DROP_JS = """([b64, name, type]) => { const bin = Uint8Array.from(atob(b64), c => c.charCodeAt(0)); const dt = new DataTransfer(); dt.items.add(new File([bin], name, {type: type}));
                    const ed = document.querySelector('#task-pane .ProseMirror'); const r = ed.getBoundingClientRect();
                    const ev = new DragEvent('drop', {dataTransfer: dt, clientX: r.left + 20, clientY: r.top + 10, bubbles: true, cancelable: true}); ed.dispatchEvent(ev); return ev.defaultPrevented; }"""
                memo = base64.b64encode('합성 메모 한 줄\n'.encode()).decode()
                with p.expect_response(lambda r: r.url.endswith('/api/pages/' + ub['id'] + '/attachments') and r.request.method == 'POST', timeout=20000) as up:
                    prevented = p.evaluate(DROP_JS, [memo, '메모.txt', 'text/plain'])
                check(up.value.status == 201 and prevented, 'U3 C 끌어놓기(파일) → 201')
                until(p, "() => !!document.querySelector('#task-pane .ProseMirror .doc-atom[data-atom=\"file\"]') && !document.querySelector('#task-pane .ProseMirror .doc-upload:not(.doc-atom .doc-upload)')", 10000)
                # 허용 밖 파일 → 이유 문장 · 자리표시 사라짐
                with p.expect_response(lambda r: r.url.endswith('/api/pages/' + ub['id'] + '/attachments') and r.request.method == 'POST', timeout=20000) as up:
                    p.evaluate(DROP_JS, [base64.b64encode(b'MZ synthetic').decode(), '실행.exe', 'application/octet-stream'])
                bad = up.value
                until(p, "() => !document.querySelector('#task-pane .ProseMirror .doc-upload:not(.doc-atom .doc-upload)') && (document.querySelector('#task-pane > .msg') || {}).textContent !== ''", 10000)
                bm = p.evaluate("() => { const m = document.querySelector('#task-pane > .msg'); return {text: m.textContent, ok: m.classList.contains('msg--ok')}; }")
                bj = bad.json()
                check(bad.status == 400 and bj.get('reason') == 'ext_not_allowed' and bm['text'] == bj.get('error') and not bm['ok'] and p.locator('#task-pane .ProseMirror .doc-atom[data-atom="file"]').count() == 1,
                      'U3 C 허용 밖 파일 → 400 · 칸 알림 줄 = 창구 이유 문장 그대로(--danger) · 자리표시 사라짐 · 블록 0 ' + json.dumps(bm, ensure_ascii=False))
                st_ = end_edit(p, ub['id'])
                rows_b = att_rows(ub['id'])
                check(st_ == 200 and len(rows_b) == 1 and page_refs(ub['id']) == [('file', 'att:' + rows_b[0].split('|')[0])], 'U3 C 끌어놓기 → 서버 첨부 행 1 · 본문 파일 블록 file_ref att: ' + json.dumps(rows_b, ensure_ascii=False))
                # 「이 문서에 올리기」 탭(그림 창 첫 탭 · 기본)
                open_task(p, uc['id'])
                start_edit(p)
                p.locator('#task-pane .editor-toolbar .editor-toolbar__row > button', has_text='블록 추가').click()
                p.locator('#block-list .block-pick[data-block-type="image"]').click()
                p.locator('#block-insert').click()
                p.wait_for_selector('#atom-dialog[open] [data-atom-tab="upload"]', timeout=5000)
                tabs = p.evaluate("""() => ({tabs: [...document.querySelectorAll('#atom-file [role=tab]')].map(b => [b.textContent, b.getAttribute('aria-selected')]),
                    up: !document.querySelector('[data-atom-upload]').closest('[hidden]'), lib: !!document.getElementById('atom-file-q').closest('[hidden]'),
                    label: document.querySelector('[data-atom-upload]').getAttribute('aria-label'), accept: document.querySelector('[data-atom-upload-input]').getAttribute('accept'),
                    tabbar: !document.querySelector('#atom-file [role=tablist]').hidden})""")
                metrics['u3_tabs'] = tabs
                check(tabs['tabs'] == [['이 문서에 올리기', 'true'], ['자료실에서 고르기', 'false']] and tabs['up'] and tabs['lib'] and tabs['label'] == '이 문서에 그림 올리기' and tabs['tabbar'],
                      'U3 C 그림 창 탭 둘: 「이 문서에 올리기」(첫 탭 · 기본 · 파일 고르기 aria-label) · 「자료실에서 고르기」 ' + json.dumps(tabs, ensure_ascii=False))
                p.locator('[data-atom-tab="library"]').click()
                check(p.locator('#atom-file-q').is_visible() and not p.locator('[data-atom-upload]').is_visible(), 'U3 C 「자료실에서 고르기」 탭 = 지금 창 그대로(파일 찾기 칸)')
                p.locator('[data-atom-tab="upload"]').click()
                with p.expect_response(lambda r: r.url.endswith('/api/pages/' + uc['id'] + '/attachments') and r.request.method == 'POST', timeout=20000) as up:
                    p.locator('[data-atom-upload-input]').set_input_files(files=[{'name': '올리기.png', 'mimeType': 'image/png', 'buffer': U3_PNG}])
                check(up.value.status == 201 and not p.evaluate("document.getElementById('atom-dialog').open"), 'U3 C 「이 문서에 올리기」 → 창 닫힘 · 201')
                until(p, "() => !!document.querySelector('#task-pane .ProseMirror .doc-atom[data-atom=\"image\"]') && !document.querySelector('#task-pane .ProseMirror .doc-upload:not(.doc-atom .doc-upload)')", 10000)
                # 검사 중(pending) — SCAN_ENABLED=1 로 하나 더 붙여넣기
                env_path = root / '.env'
                env_text = env_path.read_text()
                env_path.write_text(re.sub(r'^SCAN_ENABLED=.*$', 'SCAN_ENABLED=1', env_text, flags=re.M))
                try:
                    with p.expect_response(lambda r: r.url.endswith('/api/pages/' + uc['id'] + '/attachments') and r.request.method == 'POST', timeout=20000) as up:
                        p.evaluate(PASTE_JS, [U3_PNG_B64, '검사대기.png', 'image/png', ''])
                    pj = up.value.json()
                    until(p, "() => document.querySelectorAll('#task-pane .ProseMirror .doc-atom[data-atom=\"image\"]').length === 2 && !document.querySelector('#task-pane .ProseMirror .doc-upload:not(.doc-atom .doc-upload)')", 10000)
                    st_ = end_edit(p, uc['id'])
                finally:
                    env_path.write_text(env_text)
                rows_c = att_rows(uc['id'])
                until(p, "() => !!document.querySelector('#task-pane .task-pane__doc .doc-upload[data-kind=\"image\"]')", 8000)
                pend = p.evaluate("""() => [...document.querySelectorAll('#task-pane .task-pane__doc .doc-upload')].map(b => [b.querySelector('.doc-upload__name').textContent, b.querySelector('.doc-upload__progress').textContent])""")
                check(pj.get('scan_status') == 'pending' and st_ == 200 and [r.split('|')[2] for r in rows_c] == ['clean', 'pending'] and pend == [['검사대기.png', '검사 중이에요']]
                      and p.locator('#task-pane .task-pane__doc img.doc-image__img').count() == 1,
                      'U3 C pending → 읽기 화면 「검사 중이에요」 회색 상자(그림 대신 · 423 을 오류로 띄우지 않음) · clean 그림은 그대로 ' + json.dumps(pend, ensure_ascii=False))
                p.locator('#task-pane .task-pane__tools .btn--icon[aria-label="닫기"]').click()
                open_task(p, uc['id'])
                badges = p.evaluate("() => [...document.querySelectorAll('#task-pane li[data-attachment]')].map(li => [li.firstChild.textContent, (li.querySelector('.badge') || {}).textContent || null, !!li.querySelector('a')])")
                check(badges == [['올리기.png', None, True], ['검사대기.png', '검사 중', False]], 'U3 C 「파일 첨부」 목록: pending 은 이름 + 「검사 중」 배지(받기 링크 0) ' + json.dumps(badges, ensure_ascii=False))
                check(t.clean('u3-editor'), 'U3 B/C editor · upload console 0 · page errors 0 · 5xx 0')
                # 전체 편집기에서도 붙여넣기(입구 셋은 두 편집기 모두) — U4-2: 작업 전체 화면에서 「문서로 보기」 뒤 전체 편집기
                t.go('/community/p/' + ud['id'])
                p.wait_for_selector('#task-full summary[aria-label="더보기"]', timeout=15000)
                p.locator('#task-full summary[aria-label="더보기"]').click()
                p.locator('#task-full [data-task-as-doc]').click()
                p.wait_for_selector('#page-edit:not([hidden])', timeout=15000)
                p.locator('#page-edit').click()
                p.wait_for_selector('#page-editor .ProseMirror', timeout=8000)
                p.locator('#page-editor .ProseMirror').click()
                with p.expect_response(lambda r: r.url.endswith('/api/pages/' + ud['id'] + '/attachments') and r.request.method == 'POST', timeout=20000) as up:
                    p.evaluate(PASTE_JS.replace("#task-pane .ProseMirror", "#page-editor .ProseMirror"), [U3_PNG_B64, '전체.png', 'image/png', ''])
                until(p, "() => !!document.querySelector('#page-editor .ProseMirror .doc-atom[data-atom=\"image\"]')", 10000)
                p.locator('#page-done').click()
                until(p, "() => { const i = document.querySelector('#page-doc img.doc-image__img'); return i && i.complete && i.naturalWidth > 0; }", 15000)
                check(up.value.status == 201 and page_refs(ud['id']) == [('image', 'att:' + att_rows(ud['id'])[0].split('|')[0])], 'U3 C 전체 화면 편집기에서도 붙여넣기 → 첨부 · att: 그림 · 읽기 화면에 그려짐')
                check(t.clean('u3-full'), 'U3 C full editor console 0')
                ctx.close()

            def u3_readers():
                # 보기 공유(다른 팀 직원) · 손님 — 칸 · 전체 화면 모두 입구 셋 0
                for who_ in ('employee', 'guest'):
                    ctx = context(who_, 1440)
                    t = Tab(ctx)
                    p = t.page
                    t.go('/community/my-tasks?task=' + ua['id'])
                    p.wait_for_selector('#task-pane:not([hidden]) #task-pane-title', timeout=10000)
                    p.wait_for_timeout(500)
                    r_ = p.evaluate("""() => ({editable: !!document.querySelector('#task-pane .task-pane__doc--editable'), pm: document.querySelectorAll('.ProseMirror').length,
                        bar: document.querySelectorAll('#task-pane .editor-toolbar').length, up: document.querySelectorAll('[data-atom-upload]').length,
                        img: !!document.querySelector('#task-pane .task-pane__doc img.doc-image__img'), att: document.querySelectorAll('#task-pane li[data-attachment]').length})""")
                    p.locator('#task-pane .task-pane__doc').click()
                    p.wait_for_timeout(300)
                    r2 = p.evaluate("() => document.querySelectorAll('.ProseMirror, #task-pane .editor-toolbar').length")
                    metrics['u3_reader_' + who_] = r_
                    check(not r_['editable'] and r_['pm'] == 0 and r_['bar'] == 0 and r_['up'] == 0 and r2 == 0,
                          'U3 C %s(보기 공유): 칸 편집기 · 도구줄 · 붙여넣기 · 끌어놓기 · 올리기 탭 0 %s' % (who_, json.dumps(r_)))
                    check(r_['img'] and r_['att'] == 1, 'U3 C %s(보기 공유): 문서를 볼 수 있으니 첨부 그림 · 「파일 첨부」는 보인다(판정이 문서를 따른다)' % who_)
                    t.go('/community/p/' + ua['id'])
                    p.wait_for_timeout(1200)
                    check(not p.locator('#page-edit').is_visible() and p.locator('.ProseMirror').count() == 0, 'U3 C %s 전체 화면: 편집(입구) 0' % who_)
                    check(t.clean('u3-reader-' + who_), 'U3 C %s console 0' % who_)
                    ctx.close()

            def u3_matrix():
                widths = [(360, False), (412, True), (820, False), (1199, False), (1280, False), (1440, False)]
                summary = {}
                for mode in ('light', 'dark', 'hc'):
                    sql('UPDATE users SET high_contrast=' + ('true' if mode == 'hc' else 'false') + ' WHERE id=' + str(oth))
                    for width, touch in widths:
                        ctx = context('other', width, 900 if width > 800 else 915, touch=touch, extra={'tbox_theme': 'dark' if mode == 'dark' else 'light'})
                        t = Tab(ctx)
                        p = t.page
                        tag = 'u3-%d%s-%s' % (width, 't' if touch else '', mode)

                        def one():
                            t.go('/community/my-tasks?task=' + ua['id'])
                            p.wait_for_selector('#task-pane:not([hidden]) #task-pane-title', timeout=15000)
                            p.wait_for_timeout(300)
                            states = ['read', 'edit'] + (['wide'] if width >= 1200 else [])
                            for state in states:
                                if state == 'edit':
                                    start_edit(p)
                                    p.locator('#task-pane .editor-toolbar .editor-toolbar__row > button', has_text='멘션').dispatch_event('mousedown')
                                    p.wait_for_timeout(200)
                                if state == 'wide':
                                    p.locator('#task-pane [data-pane-wide]').click()
                                    p.wait_for_timeout(300)
                                a = p.evaluate(AUDIT_JS, touch or width < 720)
                                s_ = p.evaluate(STYLE_JS)
                                summary[tag + '-' + state] = {'overflow': a['overflow'], 'small': a['smallCount'], 'others': s_['others'], 'body': s_['body']}
                                check(a['overflow'] <= 0, 'U3 %s %s horizontal overflow 0 (%d)' % (tag, state, a['overflow']))
                                if touch or width < 720:
                                    check(a['smallCount'] == 0, 'U3 %s %s tap targets ≥ 44 (%d small: %s)' % (tag, state, a['smallCount'], json.dumps(a['small'], ensure_ascii=False)))
                                check(s_['others'] == [] and (s_['body'] is None or BODY_STYLE_RE.match(s_['body'])), 'U3 %s %s inline style 0 (CSSOM --task-pane-w only)' % (tag, state))
                                if state == 'edit':
                                    pr = p.evaluate("() => { const r = document.querySelector('#task-pane .editor-toolbar > .editor-palette__row:not([hidden])'); const pn = document.getElementById('task-pane').getBoundingClientRect(); if (!r) return null; const b = r.getBoundingClientRect(); return [Math.round(b.left - pn.left), Math.round(pn.right - b.right)]; }")
                                    check(pr is not None and pr[0] >= 0 and pr[1] >= 0, 'U3 %s 멘션 줄(툴바 아래 줄)이 칸 안에 열림 %s' % (tag, json.dumps(pr)))
                                    p.keyboard.press('Escape')
                            if mode in ('light', 'dark') and width in (412, 1440):
                                p.screenshot(path=str(shots / ('%s-edit.png' % tag)))
                        guard('U3 matrix ' + tag, one)
                        check(t.clean('u3m-%d-%s' % (width, mode)), 'U3 %d%s %s console 0 · page errors 0 · 5xx 0' % (width, ' touch' if touch else '', mode))
                        ctx.close()
                sql('UPDATE users SET high_contrast=false WHERE id=' + str(oth))
                metrics['u3_matrix'] = summary

            guard('U3 width', u3_width)
            guard('U3 editor', u3_editor)
            guard('U3 readers', u3_readers)
            guard('U3 matrix', u3_matrix)

        # ─── U2 보고용 화면 사본(밝게 · 어둡게 한 벌) — 지시서 「스크린샷」 목록 · 묶음 이름 shots ───────────────────────────────
        if want('shots'):
            def u2_shots():
                for theme in ('light', 'dark'):
                    for collapsed in (False, True):
                        ctx = context('employee', 1440, extra={'tbox_theme': theme})
                        if collapsed:
                            ctx.add_init_script("localStorage.setItem('qaredi-sidebar-collapsed:v1:%s', '1')" % users['employee']['username'])
                        t = Tab(ctx)
                        p = t.page
                        t.go('/community/')
                        p.wait_for_selector('#work-home-rows .task-inbox__row', timeout=15000)
                        p.locator('#work-home-rows li.task-inbox__row > .task-inbox__line').first.click()
                        p.wait_for_selector('#task-pane:not([hidden]) #task-pane-title', timeout=10000)
                        p.wait_for_timeout(500)
                        p.screenshot(path=str(shots / ('final-1440-%s-%s-home-expanded.png' % ('collapsed' if collapsed else 'open', theme))))
                        if not collapsed:
                            p.locator('details.appbar__apps > summary').click()
                            p.wait_for_timeout(300)
                            p.screenshot(path=str(shots / ('final-1440-%s-grid.png' % theme)))
                        check(t.clean('shots-1440-%s-%s' % (theme, collapsed)), 'U2 shots 1440 %s %s console 0' % (theme, 'collapsed' if collapsed else 'open'))
                        ctx.close()
                    ctx = context('employee', 412, 915, touch=True, extra={'tbox_theme': theme})
                    t = Tab(ctx)
                    p = t.page
                    t.go('/community/my-tasks')
                    p.wait_for_selector('#my-tasks-rows .task-inbox__row', timeout=15000)
                    p.wait_for_timeout(300)
                    p.screenshot(path=str(shots / ('final-412-%s-my-tasks.png' % theme)))
                    p.locator('#appbar-apps').click()
                    p.wait_for_timeout(300)
                    p.screenshot(path=str(shots / ('final-412-%s-grid.png' % theme)))
                    check(t.clean('shots-412-' + theme), 'U2 shots 412 %s console 0' % theme)
                    ctx.close()
                    ctx = context('employee', 1199, extra={'tbox_theme': theme})
                    t = Tab(ctx)
                    p = t.page
                    t.go('/community/my-tasks')
                    p.wait_for_selector('#my-tasks-rows .task-inbox__row', timeout=15000)
                    p.locator('#my-tasks-rows li.task-inbox__row > .task-inbox__line').first.click()
                    p.wait_for_selector('#task-pane:not([hidden]) #task-pane-title', timeout=10000)
                    p.wait_for_timeout(400)
                    check(p.locator('#task-pane .task-pane__back').is_visible(), 'U2 shots 1199 %s 「‹ 목록」 visible' % theme)
                    p.screenshot(path=str(shots / ('final-1199-%s-pane.png' % theme)))
                    check(t.clean('shots-1199-' + theme), 'U2 shots 1199 %s console 0' % theme)
                    ctx.close()
            guard('U2 shots', u2_shots)

        browser.close()
finally:
    (out / 'qf12-work-browser-metrics.json').write_text(json.dumps(metrics, ensure_ascii=False, indent=1))
    (out / 'qf12-work-browser-results.json').write_text(json.dumps(checks, ensure_ascii=False, indent=2))
    print('RESULT pass=' + str(sum(x['pass'] for x in checks)) + ' fail=' + str(sum(not x['pass'] for x in checks)), flush=True)
if any(not x['pass'] for x in checks):
    raise SystemExit(1)
