#!/usr/bin/env python3
"""Q:F-12 U5 — 화면 수락 시험 (실제 PHP · PostgreSQL + Chromium · 격리 틀 memories_harness.py 만)

  python3 tests/community/memories_harness.py --suite qf12_u5_browser.py --output <결과 폴더>

합성 DB · 임시 복사본 · 로컬 php -S 만 친다(운영 8082 · tbox_re · 시드 계정 0). 계정은 fixture 의 claude_ 와 UA tbox-review/<날짜>.
결과 파일에는 단언 이름 · 참/거짓 · 측정값(폭 · 수 · 좌표)만 — 세션 · CSRF · 응답 본문 0. 스크린샷은 결과 폴더 shots/.
지시서 docs/qf12-u5-directive.md 완료 기준 ①~③ (④ 정적 검사는 따로 — 보고서) · DESIGN 2.28 · 기획 work-ux-plan.md 17.2.

  [first]  1440 · 412 × 밝게 · 어둡게 — 프로젝트 · 작업 페이지: load 직후부터 화면이 설 때까지 매 프레임(requestAnimationFrame) `#db-add-view`
           (「폼 · 일정 · 차트 추가」) · 일반 표 · 빈 위젯 상자 · 일반 문서 부품이 보이는 프레임 0 · 뼈대(`.skeleton`)가 보였다가 사라짐(끝에 DOM 0) ·
           행 목록 창구(프로젝트) · 페이지 창구(작업)를 막은 채 = 화면이 서기 전 상태: 뼈대만 · 일반 부품 0 · 일반 문서 페이지는 뼈대 0(HTML · 프레임)
  [shell]  1440 × 밝게 · 어둡게 — div.shell-tools: 앱 단추 글자 「앱」 · 높이 40 · 아이콘 20 · 사용자 칩(아바타 32 + 표시 이름 + 「님」 · 높이 40) ·
           누르면 /tbox/settings · 긴 이름 말줄임 · 넘침 0 / 412: 줄 숨김 · 윗줄 앱 이름 ▾ 가 같은 격자(지금 그대로)
  [home]   1440 · 412 × 밝게 · 어둡게 — 모아 홈(/): 바로가기 줄 가운데(왼쪽 · 오른쪽 여백 차 ≤ 2px) · 「내 작업」 타일 0 · 「업무 홈」 하나 + work 아이콘 ·
           「우리 공간」 spaces 아이콘 · 넘침 0 · 전체 서비스 메뉴에도 「내 작업」 0 · 홈 편집의 바로가기 설정이 열리고 「내 작업」 줄 0 · 「메신저 앞으로」 첫 누름이 곧바로 순서를 바꿈
"""
from pathlib import Path
exec(compile(Path(__file__).with_name('memories_http.py').read_text().split('try:\n for u in users:')[0], __file__, 'exec'))
import re, secrets
from urllib.parse import urlsplit
from playwright.sync_api import sync_playwright
assert str(root).startswith('/tmp/qaredi-stage13-') and manifest['database'].startswith('tbox_memories_test_')
host = urlsplit(site).hostname
UA = 'tbox-review/' + time.strftime('%Y-%m-%d')
ONLY = set(filter(None, os.environ.get('QF12U5_ONLY', '').split(',')))
shots = out / 'shots'
shots.mkdir(exist_ok=True)
metrics = {}


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


def uid(key): return int(users[key]['id'])
def lit(v): return "'" + str(v).replace("'", "''") + "'"


def new_session(key):
    token = secrets.token_hex(32)
    sql("INSERT INTO sessions(id,user_id,expires_at,ip,user_agent) VALUES('" + hashlib.sha256(token.encode()).hexdigest() + "'," + str(uid(key))
        + ",clock_timestamp()+interval '8 hours','127.0.0.1','" + UA + "')")
    return token


def code_console(lines):
    return [t for t, _ in lines if not t.startswith('Failed to load resource') and not t.startswith('[DOM] ')]


class Tab:
    def __init__(self, ctx):
        self.page = ctx.new_page()
        self.console, self.errors, self.bad = [], [], []
        p = self.page
        p.on('console', lambda m: self.console.append((m.text, urlsplit(m.location.get('url', '')).path)) if m.type in ('error', 'warning') else None)
        p.on('pageerror', lambda e: self.errors.append(str(e)))
        p.on('response', lambda r: self.bad.append(urlsplit(r.url).path) if r.status >= 500 else None)

    def clean(self, tag=''):
        ok = not self.errors and not self.bad and code_console(self.console) == []
        if not ok:
            metrics['unclean-' + tag + '-' + str(len(metrics))] = {'errors': [e[:200] for e in self.errors], 'bad': self.bad, 'console': [c[0][:200] for c in self.console]}
        return ok


# 일반 부품 — 프로젝트 · 작업 화면이 서기 전 한순간이라도 보이면 안 되는 것(U4 의 일반 문서 부품 + U5 의 일반 데이터베이스 부품)
DOC_PARTS = ['header.page-head:not(#project-head)', '#page-props', '#page-props-summary', '#row-props-wrap', '#page-doc', '#page-children-wrap', '#page-comments-wrap']
DB_PARTS = ['#db-table-wrap', '#db-table', '#db-wrap > .db-widget', '#db-wrap > .toolbar.db-filter', '#db-add-row', '#db-list']
WATCH_JS = r"""(() => { const doc = %s, dbp = %s; const seen = {}; let frames = 0, skel = 0, skelFirst = -1, skelLast = -1, ready = -1; const t0 = performance.now();
  const vis = el => { if (!el.isConnected) return false; const r = el.getBoundingClientRect(); const c = getComputedStyle(el);
    return r.width > 0 && r.height > 0 && c.display !== 'none' && c.visibility !== 'hidden'; };
  const tick = () => { frames++;
    for (const s of doc.concat(dbp)) { for (const el of document.querySelectorAll(s)) { if (vis(el)) { seen[s] = (seen[s] || 0) + 1; break; } } }
    // 「폼 · 일정 · 차트 추가」 글자 단추 — 프로젝트 화면이 서면 보기 탭 끝 「＋」 아이콘(글자 0)으로 옮겨진다(그건 프로젝트 화면의 부품)
    const add = document.getElementById('db-add-view');
    if (add && vis(add) && (add.textContent || '').indexOf('폼 · 일정 · 차트 추가') !== -1) { seen['#db-add-view(text)'] = (seen['#db-add-view(text)'] || 0) + 1; }
    if (add && vis(add) && add.parentElement && add.parentElement.id === 'db-wrap') { seen['#db-add-view(in #db-wrap)'] = (seen['#db-add-view(in #db-wrap)'] || 0) + 1; }
    const sk = [...document.querySelectorAll('.skeleton')].some(vis);
    if (sk) { skel++; if (skelFirst < 0) skelFirst = frames; skelLast = frames; }
    const up = !!document.querySelector('#project-list .task-row[data-task]') && vis(document.querySelector('#project-list .task-row[data-task]'))
            || !!document.querySelector('#task-full .task-pane__title') && vis(document.querySelector('#task-full .task-pane__title'));
    if (up && ready < 0) ready = frames;
    window.__u5watch = {seen: seen, frames: frames, skel: skel, skelFirst: skelFirst, skelLast: skelLast, ready: ready,
                        kind: document.body ? document.body.getAttribute('data-page-kind') : null};
    if (performance.now() - t0 < 5000) requestAnimationFrame(tick); };
  requestAnimationFrame(tick); })();""" % (json.dumps(DOC_PARTS), json.dumps(DB_PARTS))
PARTS_JS = r"""(sels) => sels.map(s => [s, [...document.querySelectorAll(s)].some(el => { const r = el.getBoundingClientRect(); const c = getComputedStyle(el);
  return r.width > 0 && r.height > 0 && c.display !== 'none' && c.visibility !== 'hidden'; })])"""
SKEL_JS = r"""() => { const v = [...document.querySelectorAll('.skeleton')].filter(el => { const r = el.getBoundingClientRect(); return r.width > 0 && r.height > 0 && getComputedStyle(el).display !== 'none'; });
  const bars = v.length ? [...v[0].querySelectorAll('.skeleton__bar')].map(b => { const r = b.getBoundingClientRect(); const c = getComputedStyle(b); return {w: Math.round(r.width), h: Math.round(r.height), bg: c.backgroundColor, anim: c.animationName}; }) : [];
  const add = document.getElementById('db-add-view');
  return {count: document.querySelectorAll('.skeleton').length, visible: v.length, parent: v.length ? v[0].parentElement.id : null, bars: bars,
          surface2: getComputedStyle(document.documentElement).getPropertyValue('--surface-2').trim(), addView: add ? getComputedStyle(add).display : null,
          overflow: document.documentElement.scrollWidth - window.innerWidth}; }"""


try:
    for u in users:
        users[u]['username'] = sql('SELECT username FROM users WHERE id=' + str(users[u]['id']))
        users[u]['display'] = sql("SELECT COALESCE(NULLIF(display_name,''),username) FROM users WHERE id=" + str(users[u]['id']))
        call('GET', '/api/me/space', user=u)
    emp = uid('employee')
    sql("INSERT INTO community.usage_guides(user_id,version,status,context) SELECT id,1,'dismissed','overview' FROM users ON CONFLICT DO NOTHING")
    icons = json.loads(subprocess.run(['php', '-r', 'require "src/Apps.php"; echo json_encode(Apps::ICON_PATHS);'], cwd=str(root), check=True, capture_output=True, text=True).stdout)
    check(isinstance(icons.get('spaces'), str) and icons['spaces'] not in (icons.get('grid'), icons.get('work')), 'fixture: Apps::ICON_PATHS has a distinct fixed spaces path')

    # ─── 합성 준비 — 직원 개인 프로젝트 · 작업 둘 · 일반 문서 ───────────────────────────────────
    proj = call('POST', '/api/pages', {'database': True, 'preset': 'project', 'title': 'U5 합성 프로젝트'}, expected=201)['page']
    task = call('POST', '/api/pages', {'parent_id': proj['id'], 'title': 'U5 합성 작업', 'properties': {'section': '01. 진행'}}, expected=201)['page']
    call('POST', '/api/pages', {'parent_id': proj['id'], 'title': 'U5 합성 작업 둘', 'properties': {'section': '01. 진행'}}, expected=201)
    plain = call('POST', '/api/pages', {'title': 'U5 일반 문서'}, expected=201)['page']
    proj_db = call('GET', '/api/pages/' + proj['id'])['database']['id']

    with sync_playwright() as pw:
        browser = pw.chromium.launch(executable_path=os.environ.get('CHROMIUM_EXECUTABLE'))

        def context(key, width, theme, touch=False):
            ctx = browser.new_context(viewport={'width': width, 'height': 900 if width > 800 else 915}, has_touch=touch, is_mobile=touch, user_agent=UA,
                                      reduced_motion='reduce', color_scheme=theme)
            jar = [{'name': '__Host-tbox_session', 'value': new_session(key), 'domain': host, 'path': '/', 'secure': True, 'httpOnly': True, 'sameSite': 'Lax'}]
            for name, value in (('qaredi_motion', 'reduce'), ('tbox_theme', theme)):
                jar.append({'name': name, 'value': value, 'domain': host, 'path': '/'})
            ctx.add_cookies(jar)
            return ctx

        COMBOS = [(1440, False, 'light'), (1440, False, 'dark'), (412, True, 'light'), (412, True, 'dark')]

        # ─── [first] 프로젝트 · 작업 첫 화면 — 매 프레임 ────────────────────────────────────────────
        if want('first'):
            def first():
                summary = {}
                # 서버 HTML — 뼈대는 project · task 일 때만(일반 문서 0) · 인라인 style 0
                for label, pid, n in (('project', proj['id'], 1), ('task', task['id'], 1), ('doc', plain['id'], 0)):
                    r = raw('GET', '/p/' + pid)
                    html = r.text
                    check(r.status_code == 200 and html.count('class="skeleton"') == n and ' style=' not in html,
                          'first HTML %s: .skeleton %d (want %d) · inline style 0' % (label, html.count('class="skeleton"'), n))
                    if label == 'project':
                        check(re.search(r'<section id="db-wrap" class="panel" aria-labelledby="db-title">\s*<div class="skeleton"', html) is not None,
                              'first HTML project: #db-wrap open from the first paint with the skeleton first')
                    if label == 'task':
                        check(re.search(r'<section id="task-full"[^>]*>\s*(?:<!--.*?-->\s*)?<div class="skeleton"', html, re.S) is not None, 'first HTML task: skeleton inside #task-full')
                for width, touch, theme in COMBOS:
                    tag = '%d-%s' % (width, theme)
                    for label, pid in (('project', proj['id']), ('task', task['id']), ('doc', plain['id'])):
                        # ① 화면이 서기 전 상태를 붙잡는다 — 프로젝트는 행 목록 창구를, 작업은 페이지 창구를 막는다
                        if label != 'doc':
                            ctx = context('employee', width, theme, touch)
                            block = (r'.*/community/api/databases/' + proj_db + r'/rows(\?.*)?$') if label == 'project' else (r'.*/community/api/pages/' + pid + r'(\?.*)?$')
                            ctx.route(re.compile(block), lambda route: route.abort())
                            pg = ctx.new_page()
                            pg.goto(site + '/community/p/' + pid, wait_until='domcontentloaded')
                            pg.wait_for_timeout(900)
                            parts = dict(pg.evaluate(PARTS_JS, DOC_PARTS + DB_PARTS + ['#db-add-view']))
                            sk = pg.evaluate(SKEL_JS)
                            if width == 1440 or theme == 'light':
                                pg.screenshot(path=str(shots / ('first-blocked-%s-%s.png' % (label, tag))))
                            summary['%s-%s-blocked' % (label, tag)] = {'parts': [s for s, v in parts.items() if v], 'skeleton': sk}
                            check(not any(parts.values()), 'first %s %s (screen not up yet): general parts 0 (#db-add-view · table · widget box · document parts) %s'
                                  % (tag, label, json.dumps([s for s, v in parts.items() if v])))
                            check(sk['visible'] == 1 and sk['parent'] == ('db-wrap' if label == 'project' else 'task-full') and len(sk['bars']) == 4
                                  and all(b['h'] > 0 and b['anim'] == 'none' for b in sk['bars']) and sk['overflow'] <= 0,
                                  'first %s %s (screen not up yet): skeleton shown in %s · head + 3 rows · no animation · overflow 0 %s'
                                  % (tag, label, sk['parent'], json.dumps(sk['bars'][:2])))
                            ctx.close()
                        # ② 보통으로 열기 — 매 프레임 관찰
                        ctx = context('employee', width, theme, touch)
                        ctx.add_init_script(WATCH_JS)
                        t = Tab(ctx)
                        t.page.goto(site + '/community/p/' + pid, wait_until='networkidle')
                        if label == 'task':
                            t.page.wait_for_selector('#task-full .task-pane__title', timeout=15000)
                        if label == 'project':
                            t.page.wait_for_selector('#project-list .task-row[data-task]', timeout=15000)
                        t.page.wait_for_timeout(700)
                        w = t.page.evaluate('window.__u5watch || null')
                        end = t.page.evaluate(SKEL_JS)
                        summary['%s-%s-watch' % (label, tag)] = {'watch': w, 'end': {k: end[k] for k in ('count', 'visible', 'addView', 'overflow')}}
                        if width == 1440 and theme == 'light':
                            t.page.screenshot(path=str(shots / ('first-up-%s-%s.png' % (label, tag))))
                        if label == 'doc':
                            check(w is not None and w['skel'] == 0 and end['count'] == 0 and w['seen'].get('#page-doc', 0) > 0,
                                  'first %s doc (control): skeleton 0 in every frame and in DOM · document body shown' % tag)
                        else:
                            check(w is not None and w['frames'] > 5 and w['seen'] == {}, 'first %s %s normal load: #db-add-view · general table · document parts visible in 0 of %s frames %s'
                                  % (tag, label, w['frames'] if w else '?', json.dumps(w['seen'] if w else None)))
                            check(w is not None and w['skel'] >= 1 and w['ready'] > 0 and w['skelLast'] < w['ready'] and end['count'] == 0,
                                  'first %s %s: skeleton seen (%s frames, %s..%s) then gone before the screen stood (frame %s) · DOM .skeleton 0 at the end'
                                  % (tag, label, w and w['skel'], w and w['skelFirst'], w and w['skelLast'], w and w['ready']))
                            check(end['overflow'] <= 0, 'first %s %s: horizontal overflow 0 after the screen stood' % (tag, label))
                        check(t.clean('first-%s-%s' % (label, tag)), 'first %s %s console 0 · page errors 0 · 5xx 0' % (tag, label))
                        ctx.close()
                metrics['first'] = summary
            guard('first', first)

        # ─── [shell] 오른쪽 위 도구 ──────────────────────────────────────────────────────────
        TOOLS_JS = r"""() => { const tools = document.querySelector('div.shell-tools'); const sum = document.querySelector('div.shell-tools > details.appbar__apps > summary');
  const me = document.querySelector('a.shell-tools__me'); const main = document.getElementById('main');
  const box = el => { if (!el) return null; const r = el.getBoundingClientRect(); const c = getComputedStyle(el); return {l: r.left, r: r.right, t: r.top, b: r.bottom, w: r.width, h: r.height, display: c.display, fs: c.fontSize, fw: c.fontWeight}; };
  const svg = sum ? sum.querySelector('svg') : null; const av = me ? me.firstElementChild : null; const nm = me ? me.querySelector('.shell-tools__name') : null; const inner = nm ? nm.firstElementChild : null;
  const ms = main ? getComputedStyle(main) : null; const mr = main ? main.getBoundingClientRect() : null;
  return {tools: box(tools), sum: box(sum), sumText: sum ? sum.innerText.replace(/\s+/g, ' ').trim() : null, sumBorder: sum ? getComputedStyle(sum).borderTopColor : null,
          line: getComputedStyle(document.documentElement).getPropertyValue('--line').trim(), svg: box(svg), me: box(me), meHref: me ? me.getAttribute('href') : null,
          meLabel: me ? me.getAttribute('aria-label') : null, meText: me ? me.innerText.replace(/\s+/g, ' ').trim() : null, av: box(av), avText: av ? av.textContent : null,
          name: inner ? inner.textContent : null, nameCut: inner ? inner.scrollWidth > inner.clientWidth + 1 : null, nameEllipsis: inner ? getComputedStyle(inner).textOverflow : null,
          mainInnerRight: mr ? mr.right - parseFloat(ms.paddingRight) : null, overflow: document.documentElement.scrollWidth - window.innerWidth,
          apps: (() => { const a = document.getElementById('appbar-apps'); return a ? box(a) : null; })(),
          inline: [...document.querySelectorAll('.shell-tools [style]')].length}; }"""
        if want('shell'):
            def shell():
                summary = {}
                for width, touch, theme in COMBOS:
                    tag = '%d-%s' % (width, theme)
                    for path in ('/community/', '/tbox/'):
                        ctx = context('employee', width, theme, touch)
                        t = Tab(ctx)
                        p = t.page
                        p.goto(site + path, wait_until='networkidle')
                        p.wait_for_timeout(300)
                        st = p.evaluate(TOOLS_JS)
                        summary['%s%s' % (tag, path.replace('/', '_'))] = st
                        name = users['employee']['display']
                        if width >= 960:
                            check(st['sumText'] == '앱' and abs(st['sum']['h'] - 40) <= 0.5 and st['sum']['fs'] == '14px' and st['sum']['fw'] == '500'
                                  and st['svg'] and abs(st['svg']['w'] - 20) <= 0.5 and abs(st['svg']['h'] - 20) <= 0.5,
                                  'shell %s %s: app button shows 「앱」 · height 40 · 14px 500 · icon 20 (%s · h %.1f · svg %s)' % (tag, path, st['sumText'], st['sum']['h'], st['svg'] and st['svg']['w']))
                            check(abs(st['tools']['h'] - 40) <= 0.5 and abs(st['tools']['r'] - st['mainInnerRight']) <= 2, 'shell %s %s: shell-tools row height 40 · right-aligned' % (tag, path))
                            check(st['me'] and abs(st['me']['h'] - 40) <= 0.5 and st['av'] and abs(st['av']['w'] - 32) <= 0.5 and abs(st['av']['h'] - 32) <= 0.5
                                  and st['name'] == name and st['meText'].endswith('님') and name in st['meText'] and st['meHref'] == '/tbox/settings'
                                  and st['meLabel'] == '내 계정 — ' + name,
                                  'shell %s %s: user chip = avatar 32 + 「%s 님」 · height 40 · href /tbox/settings · aria-label 「내 계정 — 이름」 (%s)' % (tag, path, name, st['meText']))
                            check(st['me']['r'] <= width and st['sum']['r'] <= st['me']['l'] and st['overflow'] <= 0 and st['inline'] == 0,
                                  'shell %s %s: app button left of the chip · overflow 0 · inline style 0' % (tag, path))
                            if path == '/community/' and theme == 'light':
                                p.screenshot(path=str(shots / ('shell-%s.png' % tag)), clip={'x': width - 520, 'y': 0, 'width': 520, 'height': 90})
                            if path == '/community/':
                                p.locator('a.shell-tools__me').click()
                                p.wait_for_url(re.compile(r'.*/tbox/settings$'), timeout=10000)
                                check(urlsplit(p.url).path == '/tbox/settings', 'shell %s: clicking the chip opens /tbox/settings' % tag)
                        else:
                            check(st['tools']['display'] == 'none' and st['apps'] and st['apps']['w'] > 0, 'shell %s %s: phone — shell-tools hidden · top app-name button shown (unchanged)' % (tag, path))
                            p.locator('#appbar-apps').click()
                            p.wait_for_timeout(200)
                            opened = p.evaluate("(() => { const d = document.querySelector('details.appbar__apps'); const m = d && d.querySelector('.app-menu'); const r = m ? m.getBoundingClientRect() : null; return {open: !!(d && d.open), w: r ? r.width : 0, overflow: document.documentElement.scrollWidth - innerWidth}; })()")
                            check(opened['open'] and opened['w'] >= width - 2 and opened['overflow'] <= 0, 'shell %s %s: phone — app-name button opens the same grid full width · overflow 0' % (tag, path))
                        check(t.clean('shell-%s-%s' % (tag, path)), 'shell %s %s console 0 · page errors 0 · 5xx 0' % (tag, path))
                        ctx.close()
                # 긴 이름 — 말줄임 · 넘침 0 (합성 DB 의 표시 이름만 잠시 바꾼다)
                old = sql('SELECT COALESCE(display_name, \'\') FROM users WHERE id=' + str(emp))
                longname = '아주아주긴표시이름을가진합성검토사용자입니다정말로길어요'
                sql('UPDATE users SET display_name=' + lit(longname) + ' WHERE id=' + str(emp))
                try:
                    for width in (1440, 960):
                        ctx = context('employee', width, 'light')
                        t = Tab(ctx)
                        t.page.goto(site + '/community/', wait_until='networkidle')
                        t.page.wait_for_timeout(300)
                        st = t.page.evaluate(TOOLS_JS)
                        summary['long-%d' % width] = {k: st[k] for k in ('me', 'name', 'nameCut', 'nameEllipsis', 'overflow', 'meText')}
                        if width == 1440:
                            t.page.screenshot(path=str(shots / 'shell-long-1440.png'), clip={'x': 1440 - 560, 'y': 0, 'width': 560, 'height': 90})
                        check(st['name'] == longname and st['nameCut'] and st['nameEllipsis'] == 'ellipsis' and st['meText'].endswith('님') and abs(st['me']['h'] - 40) <= 0.5
                              and st['me']['r'] <= width and st['overflow'] <= 0 and st['meLabel'] == '내 계정 — ' + longname,
                              'shell long name %d: name cut with ellipsis · 「님」 kept · chip height 40 · inside the screen · overflow 0 (chip w %.0f)' % (width, st['me']['w']))
                        check(t.clean('shell-long-%d' % width), 'shell long name %d console 0' % width)
                        ctx.close()
                finally:
                    sql('UPDATE users SET display_name=' + (lit(old) if old != '' else 'NULL') + ' WHERE id=' + str(emp))
                metrics['shell'] = summary
            guard('shell', shell)

        # ─── [home] 모아 홈 바로가기 ─────────────────────────────────────────────────────────
        HOME_JS = r"""() => { const nav = document.getElementById('dashboard-services'); const box = el => { const r = el.getBoundingClientRect(); return {l: r.left, r: r.right, w: r.width, h: r.height}; };
  const vis = el => { const r = el.getBoundingClientRect(); return r.width > 0 && r.height > 0 && getComputedStyle(el).display !== 'none'; };
  const all = nav ? [...nav.querySelectorAll('a')] : []; const shown = all.filter(vis);
  const cont = document.getElementById('moa-main'); const cs = getComputedStyle(cont); const cr = cont.getBoundingClientRect();
  const left = cr.left + parseFloat(cs.paddingLeft), right = cr.right - parseFloat(cs.paddingRight);
  const first = shown.length ? box(shown[0]) : null, last = shown.length ? box(shown[shown.length - 1]) : null;
  return {ids: shown.map(a => a.dataset.homeShortcut), names: shown.map(a => a.innerText.trim()), all: all.map(a => a.dataset.homeShortcut),
          paths: Object.fromEntries(shown.map(a => [a.dataset.homeShortcut, (a.querySelector('svg path') || {getAttribute: () => null}).getAttribute('d')])),
          hrefs: Object.fromEntries(shown.map(a => [a.dataset.homeShortcut, a.getAttribute('href')])),
          nav: nav ? box(nav) : null, navScroll: nav ? nav.scrollWidth - nav.clientWidth : null, left: left, right: right,
          gapL: first ? first.l - left : null, gapR: last ? right - last.r : null, overflow: document.documentElement.scrollWidth - window.innerWidth,
          menu: [...document.querySelectorAll('.home-services-menu a')].map(a => a.textContent.trim()), inline: [...document.querySelectorAll('#dashboard-services [style]')].length}; }"""
        if want('home'):
            def home():
                summary = {}
                for width, touch, theme in COMBOS:
                    tag = '%d-%s' % (width, theme)
                    ctx = context('employee', width, theme, touch)
                    t = Tab(ctx)
                    p = t.page
                    p.goto(site + '/', wait_until='networkidle')
                    p.wait_for_selector('#dashboard-services a[data-home-shortcut="work"]', timeout=15000)
                    p.wait_for_timeout(300)
                    st = p.evaluate(HOME_JS)
                    summary[tag] = {k: st[k] for k in ('ids', 'names', 'all', 'nav', 'navScroll', 'gapL', 'gapR', 'overflow', 'menu')}
                    p.screenshot(path=str(shots / ('home-%s.png' % tag)))
                    check('tasks' not in st['all'] and '내 작업' not in st['names'] and p.locator('#home-shortcut-template [data-shortcut="tasks"]').count() == 0,
                          'home %s: 「내 작업」 tile 0 (not in the row or the template · visible %s)' % (tag, json.dumps(st['names'], ensure_ascii=False)))
                    check(st['ids'] == ['spaces', 'work', 'library', 'messages', 'assistant'], 'home %s: default shortcuts = 우리 공간 · 업무 홈 · 자료실 · 메신저 · AI 비서 %s' % (tag, st['ids']))
                    check(st['names'].count('업무 홈') == 1 and st['paths'].get('work') == icons['work'] and st['hrefs'].get('work') == '/community/',
                          'home %s: one 「업무 홈」 tile with the work icon → /community/' % tag)
                    check('우리 공간' in st['names'] and st['paths'].get('spaces') == icons['spaces'], 'home %s: 「우리 공간」 uses the spaces icon' % tag)
                    check(st['navScroll'] <= 0 and st['gapL'] is not None and abs(st['gapL'] - st['gapR']) <= 2,
                          'home %s: shortcut row centered — left %.1f · right %.1f (diff ≤ 2) · no inner scroll' % (tag, st['gapL'] or -1, st['gapR'] or -1))
                    check(st['overflow'] <= 0 and st['inline'] == 0, 'home %s: horizontal overflow 0 · inline style 0' % tag)
                    check('내 작업' not in st['menu'] and st['menu'].count('업무 홈') == 1, 'home %s: 전체 서비스 menu has 업무 홈 once · 내 작업 0' % tag)
                    if width == 1440 and theme == 'light':
                        # 홈 편집 → 상단 바로가기 설정: 열린다(콘솔 0) · 「내 작업」 줄 0 · 「업무 홈」 줄 하나 · 취소(쓰기 0)
                        writes = []
                        p.on('request', lambda r: writes.append(r.method) if r.method not in ('GET', 'HEAD') else None)
                        p.locator('#home-edit').click()
                        p.wait_for_timeout(300)
                        p.locator('[data-dashboard-settings]').first.click()
                        p.locator('.home-shortcut-settings summary').click()
                        p.wait_for_timeout(200)
                        rows = p.evaluate("[...document.querySelectorAll('.home-shortcut-setting-row')].filter(r => getComputedStyle(r).display !== 'none').map(r => r.querySelector('label').textContent.trim())")
                        summary['settings-rows'] = rows
                        check('내 작업' not in rows and rows.count('업무 홈') == 1 and '우리 공간' in rows and p.locator('[data-shortcut-row="tasks"]').count() == 0,
                              'home settings: shortcut rows show 업무 홈 once · 내 작업 row 0 (not even hidden) %s' % json.dumps(rows, ensure_ascii=False))
                        # 기본 구성에서 「메신저 앞으로」 첫 누름이 곧바로 순서를 바꾼다(숨은 tasks 와 자리를 바꾸느라 한 번 헛도는 일 0)
                        order = lambda: p.locator('#dashboard-services a').evaluate_all('(ns) => ns.map(n => n.dataset.homeShortcut)')
                        before_order = order()
                        p.get_by_label('메신저 앞으로', exact=True).click()
                        p.wait_for_timeout(150)
                        after_order = order()
                        summary['reorder'] = {'before': before_order, 'after': after_order}
                        check(before_order == ['spaces', 'work', 'library', 'messages', 'assistant'] and after_order == ['spaces', 'work', 'messages', 'library', 'assistant'],
                              'home settings: first 「메신저 앞으로」 press moves 메신저 before 자료실 at once %s → %s' % (before_order, after_order))
                        if p.locator('.dashboard-inspector-dialog[open]').count():
                            p.locator('.dashboard-inspector-close:visible').click()
                        p.get_by_role('button', name='취소', exact=True).click()
                        p.wait_for_timeout(200)
                        check(writes == [], 'home settings: open + cancel sends no write')
                    check(t.clean('home-' + tag), 'home %s console 0 · page errors 0 · 5xx 0' % tag)
                    ctx.close()
                metrics['home'] = summary
            guard('home', home)

        browser.close()
finally:
    (out / 'qf12-u5-browser-metrics.json').write_text(json.dumps(metrics, ensure_ascii=False, indent=1))
    (out / 'qf12-u5-browser-results.json').write_text(json.dumps(checks, ensure_ascii=False, indent=2))
    print('RESULT pass=' + str(sum(x['pass'] for x in checks)) + ' fail=' + str(sum(not x['pass'] for x in checks)), flush=True)
if any(not x['pass'] for x in checks):
    raise SystemExit(1)
