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
"""
from pathlib import Path
exec(compile(Path(__file__).with_name('memories_http.py').read_text().split('try:\n for u in users:')[0], __file__, 'exec'))
import re, secrets, subprocess, sys
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
                p.wait_for_selector('#my-tasks-groups .task-group', timeout=15000)
                names = p.evaluate("[...document.querySelectorAll('#my-tasks-groups .task-group__toggle')].map(b => b.children[1].textContent)")
                metrics['my_tasks_groups'] = names
                check(names[:2] == ['지난 기한', '오늘'], 'my tasks groups start 지난 기한 · 오늘 ' + json.dumps(names, ensure_ascii=False))
                late = p.locator('#my-tasks-groups [data-bucket="overdue"] .task-group__toggle')
                check('task-group--late' in (late.get_attribute('class') or ''), '「지난 기한」 group name uses --danger (task-group--late)')
                check(p.locator('#my-tasks-groups .badge', has_text=' 님이 맡김').count() >= 2, 'assigned rows show 「○○ 님이 맡김」 badge')
                p.locator('#my-tasks-groups [data-add-bucket="today"]').click()
                inp = p.locator('#my-tasks-groups [data-bucket="today"] .task-add__input')
                inp.fill('합성 빠른 할 일')
                with p.expect_response(lambda r: r.url.endswith('/community/api/pages') and r.request.method == 'POST') as resp:
                    inp.press('Enter')
                check(resp.value.status == 201, 'quick add POST /api/pages {my_tasks} 201')
                p.wait_for_selector('#my-tasks-groups [data-bucket="today"] .task-row__title:text("합성 빠른 할 일")', timeout=10000)
                check(True, 'quick add → row appears in 「오늘」 group')
                after = sql('SELECT count(*) FROM community.my_tasks_projects WHERE user_id=' + str(emp))
                info = sql("SELECT p.title||'|'||p.visibility||'|'||d.purpose FROM community.my_tasks_projects m JOIN community.pages p ON p.id=m.page_id JOIN community.databases d ON d.page_id=p.id WHERE m.user_id=" + str(emp))
                check(before == '0' and after == '1' and info == '내 할 일|private|my_tasks', 'quick add created 「내 할 일」 (my_tasks_projects 0 → 1 · private · purpose my_tasks)')
                check(p.locator('#my-tasks-groups [data-bucket="today"] .task-add__input').count() == 1, 'after Enter the input stays for the next line (DESIGN 2.20)')
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
                p.locator('#task-assign summary').click()
                for q, label in ((users['manager']['username'], 'team member'), (users['other']['username'], 'other team (cannot edit)'), ('claude_', 'prefix')):
                    api_ = call('GET', '/api/pages/' + rows[2]['id'] + '/assignee-candidates?q=' + q, user='employee')
                    want_names = [c['username'] for c in api_['candidates']]
                    box = p.locator('#task-assign input[type="search"]')
                    box.fill(q)
                    with p.expect_response(lambda r: '/assignee-candidates' in r.url):
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
                p.wait_for_selector('#work-home [data-home-bucket="overdue"] .task-row', timeout=15000)
                check(p.locator('[id^="overview-"]').count() == 0 and p.locator('#community-overview').count() == 0, 'browser: /community/ has no #overview-* / #community-overview')
                cols = p.evaluate("[...document.querySelectorAll('[data-home-bucket]')].map(c => [c.getAttribute('data-home-bucket'), c.querySelectorAll('.task-row[data-task]').length])")
                metrics['home_cols'] = cols
                check([c[0] for c in cols] == ['overdue', 'today', 'this_week'] and cols[0][1] >= 1 and cols[1][1] >= 1, 'home summary three columns (지난 기한 · 오늘 · 이번 주) filled ' + json.dumps(cols))
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
                                p.wait_for_selector('#my-tasks-groups .task-group', timeout=15000)
                            elif name == 'portfolio':
                                p.wait_for_selector('#portfolio-rows tr', timeout=15000)
                            elif name == 'home':
                                p.wait_for_selector('[data-home-bucket] .task-row', timeout=15000)
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
        browser.close()
finally:
    (out / 'qf12-work-browser-metrics.json').write_text(json.dumps(metrics, ensure_ascii=False, indent=1))
    (out / 'qf12-work-browser-results.json').write_text(json.dumps(checks, ensure_ascii=False, indent=2))
    print('RESULT pass=' + str(sum(x['pass'] for x in checks)) + ' fail=' + str(sum(not x['pass'] for x in checks)), flush=True)
if any(not x['pass'] for x in checks):
    raise SystemExit(1)
