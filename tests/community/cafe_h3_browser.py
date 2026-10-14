#!/usr/bin/env python3
"""사이트 H3 — 「우리 공간」 = 카페 수락 시험 (실제 PHP · PostgreSQL + Chromium · 격리 틀 memories_harness.py 만)

  python3 tests/community/memories_harness.py --suite cafe_h3_browser.py --output <결과 폴더>

합성 DB · 임시 복사본 · 로컬 php -S 만(운영 · 시드 계정 0) · fixture claude_ 계정 · UA tbox-review/<날짜>. 결과에는 단언 · 측정값만.
지시서 docs/invite-request-directive.md 「H3」 완료 기준 · DESIGN 2.29 「「우리 공간」 = 카페」.

  [links]  앱 판(모아 홈 · 자료실 · 업무 · 카페 화면) · 홈 바로가기 · 카페 사이드바 「카페 홈」 · 셸 브랜드의 「우리 공간」 → /community/cafe
  [cafe]   /community/cafe = 카페 셸(body[data-area=cafe] · 카페 사이드바 · 「카페 홈」 aria-current · 앱 판 「우리 공간」 aria-current) ·
           머리 h1 「우리 공간」 · 카페 홈 카드 · 새 글 · 인기 · 「게시판 만들어 주세요」(직원 · 관리자 보임 · 손님 0) · 「내 공간」 · 공간 고르개 0
  [old]    /tbox/spaces → 302 /community/cafe · ?space=<읽을 수 있는 공간> → 그 공간 화면(카페 묶음 0 · 머리 「공간」 → 공간 이름) ·
           못 보는 공간 → 공간 창구 거부 그대로(화면에 공간 자료 0) · 대시보드 위젯 「전체 보기」 링크 모양(/tbox/spaces?space=) 그대로 열림
  [grid]   손님 · 직원 · 관리자 × 1440 · 412 × 밝게 · 어둡게 · 고대비 — /community/cafe 넘침 0 · 콘솔 0
"""
from pathlib import Path
exec(compile(Path(__file__).with_name('memories_http.py').read_text().split('try:\n for u in users:')[0], __file__, 'exec'))
import re, secrets
from urllib.parse import urlsplit
from playwright.sync_api import sync_playwright
assert str(root).startswith('/tmp/qaredi-stage13-') and manifest['database'].startswith('tbox_memories_test_')
host = urlsplit(site).hostname
UA = 'tbox-review/' + time.strftime('%Y-%m-%d')
TB = site + '/tbox'
shots = out / 'shots'
shots.mkdir(exist_ok=True)
metrics = {}


def check(ok, name):
    checks.append({'pass': bool(ok), 'name': name})
    print(('PASS ' if ok else 'FAIL ') + name, flush=True)
    return bool(ok)


def guard(name, fn):
    try:
        fn()
    except Exception as error:  # noqa: BLE001
        check(False, name + ' raised ' + type(error).__name__ + ': ' + str(error).splitlines()[0][:200])


def uid(key): return int(users[key]['id'])


def new_session(key):
    token = secrets.token_hex(32)
    sql("INSERT INTO sessions(id,user_id,expires_at,ip,user_agent) VALUES('" + hashlib.sha256(token.encode()).hexdigest() + "'," + str(uid(key))
        + ",clock_timestamp()+interval '8 hours','127.0.0.1','" + UA + "')")
    return token


def tb(method, path, body=None, user='employee'):
    h = {'User-Agent': UA, 'Cookie': '__Host-tbox_session=' + users[user]['session'], 'X-CSRF-Token': users[user]['csrf']}
    return requests.request(method, TB + path, json=body, headers=h, timeout=40, allow_redirects=False)


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


def until(page, js, timeout=15000):
    end = time.time() + timeout / 1000
    while True:
        if page.evaluate(js):
            return True
        if time.time() > end:
            return False
        page.wait_for_timeout(100)


PANEL_JS = r"""() => { const d = document.querySelector('.shell-tools .appbar__apps'); if (!d) return null;
  return [...d.querySelectorAll('.app-menu__app')].filter(e => e.getClientRects().length > 0 || e.closest('details')).map(e => ({name: (e.querySelector('.app-menu__name') || {}).textContent,
    href: e.getAttribute('href'), current: e.getAttribute('aria-current')})); }"""

try:
    for u in users:
        users[u]['username'] = sql('SELECT username FROM users WHERE id=' + str(users[u]['id']))
        call('GET', '/api/me/space', user=u)
    sql("INSERT INTO community.usage_guides(user_id,version,status,context) SELECT id,1,'dismissed',c FROM users, unnest(ARRAY['overview','threads']) c ON CONFLICT DO NOTHING")
    # 공간 하나(직원이 소유 — 다른 직원은 멤버 아님)
    r = tb('POST', '/api/product-spaces', {'name': 'H3 합성 공간', 'kind': 'company'})
    check(r.status_code == 201, 'fixture: product space created (%d)' % r.status_code)
    SID = r.json()['space']['id'] if r.status_code == 201 else '00000000-0000-4000-8000-000000000000'

    # ── [old] 옛 주소 ──────────────────────────────────────────────────────────
    def old_http():
        r = tb('GET', '/spaces')
        check(r.status_code == 302 and r.headers.get('Location') == '/community/cafe', '[old] /tbox/spaces → 302 /community/cafe (%d %s)' % (r.status_code, r.headers.get('Location')))
        r = tb('GET', '/spaces?space=' + SID)
        check(r.status_code == 200 and 'id="space-app"' in r.text and 'id="cafe-home"' not in r.text and 'space-mine-title' not in r.text and '<h1 id="space-title">공간</h1>' in r.text,
              '[old] /tbox/spaces?space=<id> → space screen 200 · cafe section 0 · 「내 공간」 0 · head 「공간」')
        r = requests.get(TB + '/spaces', headers={'User-Agent': UA}, allow_redirects=False, timeout=30)
        check(r.status_code == 302 and r.headers.get('Location', '').startswith('/tbox/login'), '[old] signed-out /tbox/spaces → login first (gates unchanged)')
        r = tb('GET', '/api/product-spaces/' + SID + '/home', user='other')
        check(r.status_code in (403, 404), '[old] non-member space API refusal unchanged (%d)' % r.status_code)
        js = (root / 'public/assets/dashboard-widgets.js').read_text()
        check("b+'/spaces?space='" in js or "base+'/spaces?space='" in js, '[old] dashboard widget 「전체 보기」 still links /tbox/spaces?space=<id>')
        r = requests.get(site + '/community/cafe', headers={'User-Agent': UA}, allow_redirects=False, timeout=30)
        check(r.status_code == 302 and '/tbox/login' in r.headers.get('Location', ''), '[cafe] signed-out /community/cafe → login')
    guard('old_http', old_http)

    with sync_playwright() as pw:
        browser = pw.chromium.launch(executable_path=os.environ.get('CHROMIUM_EXECUTABLE'))

        def context(key, width, theme='light'):
            touch = width < 720
            ctx = browser.new_context(viewport={'width': width, 'height': 900 if width > 800 else 915}, has_touch=touch, is_mobile=touch, user_agent=UA,
                                      reduced_motion='reduce', color_scheme='dark' if theme == 'dark' else 'light')
            ctx.add_cookies([{'name': '__Host-tbox_session', 'value': new_session(key), 'domain': host, 'path': '/', 'secure': True, 'httpOnly': True, 'sameSite': 'Lax'},
                             {'name': 'qaredi_motion', 'value': 'reduce', 'domain': host, 'path': '/'},
                             {'name': 'tbox_theme', 'value': 'dark' if theme == 'dark' else 'light', 'domain': host, 'path': '/'}])
            return ctx

        def cafe(t):
            t.page.goto(site + '/community/cafe', wait_until='networkidle')
            until(t.page, "() => !document.getElementById('cafe-feed').hidden || !document.getElementById('cafe-status').textContent.includes('불러오는')")
            t.page.wait_for_timeout(200)

        # ── [links] 「우리 공간」으로 가는 길 ────────────────────────────────────────
        def links():
            ctx = context('employee', 1440)
            t = Tab(ctx)
            p = t.page
            for path in ('/', '/tbox/', '/community/'):
                p.goto(site + path, wait_until='networkidle')
                tiles = p.evaluate(PANEL_JS) or []
                sp = [x for x in tiles if x['name'] == '우리 공간']
                check(len(sp) == 1 and sp[0]['href'] == '/community/cafe' and sp[0]['current'] is None, '[links] %s app panel 「우리 공간」 → /community/cafe' % path)
            p.goto(site + '/', wait_until='networkidle')
            p.wait_for_selector('#dashboard-services [data-home-shortcut]', timeout=15000)
            href = p.evaluate("(document.querySelector('#dashboard-services [data-home-shortcut=spaces]') || {}).getAttribute ? document.querySelector('#dashboard-services [data-home-shortcut=spaces]').getAttribute('href') : null")
            check(href == '/community/cafe', '[links] home shortcut 「우리 공간」 → /community/cafe (%s)' % href)
            p.click('#dashboard-services [data-home-shortcut=spaces]')
            p.wait_for_url(re.compile(r'.*/community/cafe$'), timeout=15000)
            check(urlsplit(p.url).path == '/community/cafe', '[links] clicking the home shortcut opens the cafe')
            cafe(t)
            st = p.evaluate("""() => ({brand: [...document.querySelectorAll('#sidebar .brand a[href]')].map(x => x.getAttribute('href'))[0] || null,
                home: document.getElementById('nav-cafe-home') && document.getElementById('nav-cafe-home').getAttribute('href'),
                homeCur: document.getElementById('nav-cafe-home') && document.getElementById('nav-cafe-home').getAttribute('aria-current')})""")
            metrics['links'] = st
            check(st['brand'] == '/community/cafe' and st['home'] == '/community/cafe' and st['homeCur'] == 'page', '[links] shell brand · sidebar 「카페 홈」 → /community/cafe · 「카페 홈」 aria-current %s' % json.dumps(st))
            tiles = p.evaluate(PANEL_JS) or []
            check([x['name'] for x in tiles if x['current'] == 'page'] == ['우리 공간'], '[links] on the cafe the app panel 「우리 공간」 tile is aria-current')
            # 카페 글 · 게시판 화면의 사이드바 「카페 홈」도 같은 곳
            p.click('#cafe-boards .cafe-board >> nth=0')
            p.wait_for_url(re.compile(r'.*/community/b/\d+'), timeout=15000)
            until(p, "() => !!document.getElementById('nav-cafe-home')")
            check(p.evaluate("document.getElementById('nav-cafe-home').getAttribute('href')") == '/community/cafe', '[links] cafe board sidebar 「카페 홈」 → /community/cafe')
            p.click('#nav-cafe-home')
            p.wait_for_url(re.compile(r'.*/community/cafe$'), timeout=15000)
            check(True, '[links] 「카페 홈」 click returns to /community/cafe')
            check(t.clean('links'), '[links] console 0')
            ctx.close()
        guard('links', links)

        # ── [cafe] 화면 ─────────────────────────────────────────────────────────
        def cafe_screen():
            for who in ('employee', 'admin', 'guest'):
                ctx = context(who, 1440)
                t = Tab(ctx)
                p = t.page
                cafe(t)
                st = p.evaluate("""() => ({area: document.body.getAttribute('data-area'), h1: [...document.querySelectorAll('h1')].map(h => h.textContent.trim()),
                    cards: [...document.querySelectorAll('#cafe-boards .cafe-board .card__title')].map(e => e.textContent), feed: !document.getElementById('cafe-feed').hidden,
                    recent: !!document.getElementById('cafe-recent'), popular: !!document.getElementById('cafe-popular'), request: !document.getElementById('cafe-request').hidden,
                    mine: document.body.innerText.includes('내 공간'), picker: !!document.getElementById('space-picker'), boardsNav: [...document.querySelectorAll('#nav-community-boards a')].map(a => a.textContent.trim()),
                    workTree: !!document.querySelector('#nav-community-tree, #nav-work-projects, #nav-new-menu'), title: document.title, status: document.getElementById('cafe-status').textContent})""")
                metrics['cafe-' + who] = st
                check(st['area'] == 'cafe' and '우리 공간' in st['h1'] and st['feed'] and st['recent'] and st['popular'] and not st['workTree'],
                      '[cafe] %s: cafe shell (data-area=cafe) · h1 「우리 공간」 · 새 글 · 인기 · work sidebar 0' % who)
                # 합성 DB 의 카페 게시판 = 마이그레이션이 만든 「익명게시판」(사내) 하나(「자유게시판」 이름의 옛 게시판이 없어서) — 손님은 사내 등급을 못 본다
                want_cards = [] if who == 'guest' else ['익명게시판']
                check(st['cards'] == want_cards and sorted(st['boardsNav']) == sorted(st['cards']) and (who != 'guest' or '볼 수 있는 카페 게시판이 없어요' in st['status']),
                      '[cafe] %s: cafe home cards = sidebar cafe boards = readable cafe boards %s' % (who, json.dumps(st['cards'], ensure_ascii=False)))
                check(st['request'] == (who != 'guest'), '[cafe] %s: 「게시판 만들어 주세요」 %s' % (who, 'visible' if who != 'guest' else 'hidden'))
                check(not st['mine'] and not st['picker'], '[cafe] %s: 「내 공간」 · space picker 0' % who)
                if who == 'employee':
                    p.click('#cafe-request')
                    p.wait_for_selector('dialog[data-cafe-modal=board-request][open]', timeout=8000)
                    check(True, '[cafe] employee: 「게시판 만들어 주세요」 opens the request dialog (cafe.js — same component)')
                    p.keyboard.press('Escape')
                    p.screenshot(path=str(shots / 'cafe-1440.png'), full_page=True)
                check(t.clean('cafe-' + who), '[cafe] %s: console 0' % who)
                ctx.close()
        guard('cafe', cafe_screen)

        # ── [old] 브라우저 — 공간 화면 · 못 보는 공간 ─────────────────────────────────
        def old_browser():
            ctx = context('employee', 1440)
            t = Tab(ctx)
            p = t.page
            p.goto(TB + '/spaces', wait_until='networkidle')
            check(urlsplit(p.url).path == '/community/cafe', '[old] browser /tbox/spaces lands on /community/cafe')
            p.goto(TB + '/spaces?space=' + SID, wait_until='networkidle')
            until(p, "() => document.getElementById('space-title').textContent !== '공간'")
            st = p.evaluate("({title: document.getElementById('space-title').textContent, cafe: !!document.getElementById('cafe-home'), mine: document.body.innerText.includes('내 공간'), picker: document.getElementById('space-picker').value})")
            check(st['title'] == 'H3 합성 공간' and not st['cafe'] and not st['mine'] and st['picker'] == SID, '[old] ?space=<id> opens that space (title · picker) · cafe 0 · 「내 공간」 0 %s' % json.dumps(st, ensure_ascii=False))
            p.screenshot(path=str(shots / 'space-1440.png'), full_page=True)
            check(t.clean('space'), '[old] space screen console 0')
            ctx.close()
            ctx = context('other', 1440)
            t = Tab(ctx)
            p = t.page
            p.goto(TB + '/spaces?space=' + SID, wait_until='networkidle')
            p.wait_for_timeout(500)
            st = p.evaluate("({title: document.getElementById('space-title').textContent, cards: document.querySelectorAll('#space-cards > *').length, page: !!document.getElementById('space-app')})")
            check(st['page'] and st['title'] != 'H3 합성 공간' and st['cards'] == 0, '[old] non-member ?space=<id> — no space content (refusal unchanged) %s' % json.dumps(st, ensure_ascii=False))
            ctx.close()
        guard('old_browser', old_browser)

        # ── [grid] 넘침 · 콘솔 ───────────────────────────────────────────────────
        def grid():
            for mode in ('light', 'dark', 'hc'):
                sql('UPDATE users SET high_contrast=' + ('true' if mode == 'hc' else 'false') + ' WHERE id IN (%d,%d,%d)' % (uid('employee'), uid('admin'), uid('guest')))
                for who in ('guest', 'employee', 'admin'):
                    for width in (1440, 412):
                        tag = '%s-%d-%s' % (who, width, mode)
                        ctx = context(who, width, 'dark' if mode == 'dark' else 'light')
                        t = Tab(ctx)
                        p = t.page
                        cafe(t)
                        st = p.evaluate("({o: document.documentElement.scrollWidth - innerWidth, hc: document.documentElement.getAttribute('data-contrast'), area: document.body.getAttribute('data-area')})")
                        check(st['o'] <= 0 and st['area'] == 'cafe' and st['hc'] == ('high' if mode == 'hc' else None), '[grid] %s: overflow 0 · cafe shell' % tag)
                        if width == 412 and who == 'employee':
                            p.screenshot(path=str(shots / ('cafe-412-%s.png' % mode)), full_page=True)
                        check(t.clean('grid-' + tag), '[grid] %s: console 0' % tag)
                        ctx.close()
            sql('UPDATE users SET high_contrast=false')
        guard('grid', grid)
        browser.close()
finally:
    (out / 'cafe-h3-metrics.json').write_text(json.dumps(metrics, ensure_ascii=False, indent=1))
    (out / 'cafe-h3-results.json').write_text(json.dumps(checks, ensure_ascii=False, indent=2))
    print('RESULT pass=' + str(sum(x['pass'] for x in checks)) + ' fail=' + str(sum(not x['pass'] for x in checks)), flush=True)
if any(not x['pass'] for x in checks):
    raise SystemExit(1)
