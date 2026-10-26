#!/usr/bin/env python3
"""사이트 H1 — 모아 홈 구글 첫 화면 방식 수락 시험 (실제 PHP · PostgreSQL + Chromium · 격리 틀 memories_harness.py 만)

  python3 tests/community/memories_harness.py --suite home_h1_browser.py --output <결과 폴더>

합성 DB · 임시 복사본 · 로컬 php -S 만 친다(운영 · tbox_re · 시드 계정 0). 계정은 fixture 의 claude_ 와 UA tbox-review/<날짜>.
결과 파일에는 단언 이름 · 참/거짓 · 측정값(폭 · 수 · 좌표 · 경로)만 — 세션 · CSRF · 응답 본문 0. 스크린샷은 결과 폴더 shots/.
지시서 docs/home-google-directive.md 완료 기준 ①~⑤ · DESIGN 2.31 (+ 2.30 「테마」 타일).

  [grid]   1440 · 1280 · 960 · 720 · 412 · 360 × 밝게 · 어둡게 · 고대비 — ① 머리(「전체 서비스」 · 「테마」 글자 단추 · 「m. 모아」 로고 0 · div.shell-tools 하나 ·
           메신저 · 앱 · 칩 순서) ② 「Qaredi」 64(<720 은 44) · h1 화면 읽기 「모아 홈」 · 검색 칸 52 · 반경 26 · 폭 ≤ 720 · 자동 초점 0 ③ 원 56 · 아이콘 24 · 이름 13 ·
           기본 다섯 + 「＋ 바로가기」 · 한 줄 최대 8(<720 은 4) ④ 첫 화면 접힘 · 접힌 동안 위젯 창구 요청 0 ⑤ 넘침 0 · 대비 4.5 · 콘솔 0 · 누름 자리 < 44 0
  [tools]  1440 — 셸과 같은 마크업(서버 HTML 의 div.shell-tools 를 / 와 /tbox/ 에서 견줌 · aria-current 만 다름) · 앱 판 「모아 홈」 aria-current · 칩 → 내 계정 판
           (내 계정 · 내 활동 · 테마 · 로그아웃) · 하나만 열림 · 「테마」 → 테마 창 · /tbox/ · /community/ 의 내 계정 판에도 「테마」(누르면 같은 창) · 관리자 타일 관리자만 · 412 판 전체 폭
  [search] 글자 + Enter → 공통 검색 창이 그 글자로 열리고 결과(페이지 · 파일 묶음) · `/` 키 초점 · 칸 안에서 친 `/` 는 글자
  [short]  「메신저」 → 메신저 창(주소 그대로) · 「＋ 바로가기」 → 대시보드 펼침 + 바로가기 설정(1440 옆 칸 · 412 창) · 설정 순서대로 · 여덟 개 한 줄
  [dash]   펼침 → 위젯 · 홈 편집 · 내용 새로고침 · 펼친 뒤에만 위젯 창구 · 새로 고침 뒤 펼침 기억(localStorage 키) · 편집 중 접기 막힘 · 접고 새로 고침 → 접힘
  [before] 로그인 전 · 동의 전 런처: 서버 HTML 이 이 갈래가 마지막으로 의도해 바뀐 launcher.php(2afc533 · v2.74.0 — 상수 LAUNCHER_SNAPSHOT_REF)와 글자 그대로 같음 · 머리 · 소개 화면 · shell-tools 0 · 넘침 0 · 콘솔 0
  [logout] 모아 홈의 내 계정 판 「로그아웃」 → 로그인 화면(셸과 같은 함수)
"""
from pathlib import Path
exec(compile(Path(__file__).with_name('memories_http.py').read_text().split('try:\n for u in users:')[0], __file__, 'exec'))
import re, secrets
from urllib.parse import urlsplit
from playwright.sync_api import sync_playwright
assert str(root).startswith('/tmp/qaredi-stage13-') and manifest['database'].startswith('tbox_memories_test_')
WORKTREE = Path(__file__).resolve().parents[2]
host = urlsplit(site).hostname
UA = 'tbox-review/' + time.strftime('%Y-%m-%d')
ONLY = set(filter(None, os.environ.get('HOMEH1_ONLY', '').split(',')))
shots = out / 'shots'
shots.mkdir(exist_ok=True)
metrics = {}
WIDTHS = [1440, 1280, 960, 720, 412, 360]
MODES = ['light', 'dark', 'hc']
DEFAULT = ['work', 'library', 'messages', 'spaces']
# 위젯 자료 창구(접힌 동안 0) — 공간 목록 · 위젯 창구 · 최근 작업(start.js) · 공간 활동(product-home.js) · 이야기 게시판
WIDGET_RE = re.compile(r'(/api/product-spaces|/api/files/(recent|favorites)|/api/folders/favorites|/community/api/me/(recent|favorites)|/community/api/my-tasks'
                       r'|/community/api/briefing|/community/api/boards|/api/me/product-home|/assets/start\.js|/assets/product-home\.js)')
# 첫 화면이 부르는 것(접혀 있어도) — 계정 확인 · 홈 구성(바로가기 순서) · 메신저(안 읽은 수 한 벌)
FIRST_OK_RE = re.compile(r'^(/tbox/api/me|/tbox/api/me/home-dashboard|/tbox/api/messages(/.*)?|/tbox/api/member(/.*)?|/tbox/api/me/usage-guide(/.*)?|/community/api/usage-guide(/.*)?)$')


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
        self.console, self.errors, self.bad, self.reqs = [], [], [], []
        p = self.page
        p.on('console', lambda m: self.console.append((m.text, urlsplit(m.location.get('url', '')).path)) if m.type in ('error', 'warning') else None)
        p.on('pageerror', lambda e: self.errors.append(str(e)))
        p.on('response', lambda r: self.bad.append(urlsplit(r.url).path) if r.status >= 500 else None)
        p.on('request', lambda r: self.reqs.append((r.method, urlsplit(r.url).path)))

    def clean(self, tag=''):
        ok = not self.errors and not self.bad and code_console(self.console) == []
        if not ok:
            metrics['unclean-' + tag + '-' + str(len(metrics))] = {'errors': [e[:200] for e in self.errors], 'bad': self.bad, 'console': [c[0][:200] for c in self.console]}
        return ok

    def api(self):
        return [path for _, path in self.reqs if '/api/' in path or path.endswith('.js')]


CONTRAST_JS = r"""(el) => {
  const parse = (c) => { const s = String(c); let m = s.match(/rgba?\(([^)]+)\)/);
    if (m) { const v = m[1].split(/[ ,/]+/).filter(Boolean).map(Number); return [v[0], v[1], v[2], v.length > 3 ? v[3] : 1]; }
    m = s.match(/color\(srgb ([^)]+)\)/);
    if (m) { const v = m[1].split(/[ /]+/).filter(Boolean).map(Number); return [v[0] * 255, v[1] * 255, v[2] * 255, v.length > 3 ? v[3] : 1]; }
    return [0, 0, 0, 0]; };
  const lum = (rgb) => { const f = (x) => { x /= 255; return x <= 0.03928 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4); }; return 0.2126 * f(rgb[0]) + 0.7152 * f(rgb[1]) + 0.0722 * f(rgb[2]); };
  const over = (top, under) => { const a = top[3]; return [0, 1, 2].map(i => top[i] * a + under[i] * (1 - a)).concat(1); };
  const layers = []; for (let n = el; n && n.nodeType === 1; n = n.parentElement) { const b = parse(getComputedStyle(n).backgroundColor); if (b[3] > 0) layers.push(b); if (b[3] >= 1) break; }
  let bg = [255, 255, 255, 1]; for (let i = layers.length - 1; i >= 0; i--) bg = over(layers[i], bg);
  const pseudo = el.tagName === 'INPUT' ? '::placeholder' : null;
  const fg = over(parse(getComputedStyle(el, pseudo).color), bg); const l1 = lum(fg), l2 = lum(bg);
  return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
}"""

STATE_JS = r"""() => {
  // 닫힌 details 안(content-visibility: hidden 칸)은 상자를 재도 크기가 나오므로 checkVisibility 를 먼저 본다
  const vis = el => { if (!el || !el.isConnected) return false; if (el.checkVisibility && !el.checkVisibility()) return false; const r = el.getBoundingClientRect(); const c = getComputedStyle(el); return r.width > 0 && r.height > 0 && c.display !== 'none' && c.visibility !== 'hidden'; };
  const box = el => { if (!el) return null; const r = el.getBoundingClientRect(); return {l: r.left, r: r.right, t: r.top, b: r.bottom, w: r.width, h: r.height}; };
  const header = document.querySelector('body > header'); const tools = [...document.querySelectorAll('div.shell-tools')];
  const kids = tools[0] ? [...tools[0].children].map(e => e.tagName.toLowerCase() + '.' + [...e.classList].filter(c => ['shell-tools__messenger', 'appbar__apps', 'shell-account'].includes(c)).join('')) : [];
  const word = document.querySelector('.home-wordmark'); const h1 = document.querySelector('h1');
  const form = document.querySelector('form.home-search'); const input = document.getElementById('home-search-input'); const ic = form ? form.querySelector('.icon svg') : null;
  const nav = document.getElementById('dashboard-services'); const items = nav ? [...nav.children].filter(vis) : [];
  const tops = []; for (const it of items) { const t = Math.round(it.getBoundingClientRect().top); if (!tops.includes(t)) tops.push(t); }
  const rows = tops.map(t => items.filter(it => Math.round(it.getBoundingClientRect().top) === t).length);
  const circles = items.map(it => box(it.querySelector('.home-shortcut-icon')));
  const svgs = items.map(it => { const s = it.querySelector('.home-shortcut-icon svg'); return s ? box(s) : null; }).filter(Boolean);
  const labels = items.map(it => { const s = it.querySelector('span:last-child'); return s ? getComputedStyle(s).fontSize : null; });
  const dash = document.querySelector('details.home-dashboard'); const sum = dash ? dash.querySelector('summary') : null;
  // 누름 자리 — 보이는 머리 · 가운데 · 대시보드 머리의 누르는 것(클래스 없는 글 속 링크 · 입력 칸 제외 — DESIGN 4장)
  const hits = [...document.querySelectorAll('body > header :is(a, button, summary), .home-hero :is(a, button), details.home-dashboard > summary')].filter(vis)
    .map(el => { const r = el.getBoundingClientRect(); return {n: (el.getAttribute('aria-label') || el.textContent || el.tagName).trim().slice(0, 24), w: Math.round(r.width), h: Math.round(r.height)}; })
    .filter(x => x.w < 44 || x.h < 44);
  return {
    headerTools: !!(header && tools[0] && header.contains(tools[0])), toolsCount: tools.length, kids: kids,
    toolsH: tools[0] ? [...tools[0].children].filter(vis).map(e => Math.round((e.matches('details') ? e.querySelector('summary') : e).getBoundingClientRect().height)) : [],
    headerLogo: header ? header.querySelectorAll('.moa-logo, .moa-symbol').length : -1, themeButtons: document.querySelectorAll('.moa-theme-button').length,
    allServices: (header ? header.innerText : '').includes('전체 서비스') || !!document.querySelector('.home-services-menu, #home-all-services'),
    headerTheme: header ? [...header.querySelectorAll('button, a')].filter(vis).some(b => b.textContent.trim().startsWith('테마')) : null,
    oldHeading: !!document.querySelector('.home-heading, .home-nav') || [...document.querySelectorAll('h1')].some(h => h.textContent.trim() === '내 홈'),
    word: word ? {text: word.textContent, hidden: word.getAttribute('aria-hidden'), fs: getComputedStyle(word).fontSize, fw: getComputedStyle(word).fontWeight, vis: vis(word)} : null,
    h1: h1 ? {text: h1.textContent.trim(), w: Math.round(h1.getBoundingClientRect().width), count: document.querySelectorAll('h1').length} : null,
    search: form ? {w: box(form).w, h: box(form).h, radius: getComputedStyle(form).borderTopLeftRadius, icon: ic ? Math.round(box(ic).w) : 0, ph: input.placeholder,
                    focused: document.activeElement === input, autofocus: input.hasAttribute('autofocus')} : null,
    wrapW: (() => { const m = document.getElementById('moa-main'); const s = getComputedStyle(m); return m.clientWidth - parseFloat(s.paddingLeft) - parseFloat(s.paddingRight); })(),
    ids: items.map(it => it.dataset.homeShortcut || (it.hasAttribute('data-home-shortcut-add') ? '+' : '?')), rows: rows, navMax: nav ? getComputedStyle(nav).maxWidth : null,
    circles: circles.map(c => c ? [Math.round(c.w), Math.round(c.h)] : null), svgs: svgs.map(s => [Math.round(s.w), Math.round(s.h)]), labels: labels,
    dashOpen: dash ? dash.open : null, sumH: sum ? Math.round(sum.getBoundingClientRect().height) : null, sumText: sum ? sum.innerText.replace(/\s+/g, ' ').trim() : null,
    editVisible: vis(document.getElementById('home-edit')), overflow: document.documentElement.scrollWidth - window.innerWidth,
    contrastAttr: document.documentElement.getAttribute('data-contrast'), small: hits,
    inline: [...document.querySelectorAll('body > header [style], .home-hero [style], details.home-dashboard > summary[style]')].length
  }; }"""

PANEL_JS = r"""(sel) => { const d = document.querySelector(sel); const p = d.querySelector('.app-menu'); const r = p.getBoundingClientRect();
  const vis = (e) => e.getClientRects().length > 0 && getComputedStyle(e).display !== 'none';
  return {open: d.open, cls: p.className, w: Math.round(r.width), left: Math.round(r.left), right: Math.round(r.right), top: Math.round(r.top),
          tiles: [...p.querySelectorAll('.app-menu__app')].filter(vis).map(e => e.querySelector('.app-menu__name').textContent),
          current: [...p.querySelectorAll('.app-menu__app[aria-current="page"]')].map(e => e.querySelector('.app-menu__name').textContent),
          logout: [...p.querySelectorAll('.app-menu__logout')].filter(vis).map(e => e.textContent.trim()), overflow: document.documentElement.scrollWidth - innerWidth}; }"""


def tools_html(text):
    m = re.search(r'<div class="shell-tools">.*?</details>\s*</div>', text, re.S)
    if not m:
        return None
    return re.sub(r'\s+', ' ', m.group(0).replace(' aria-current="page"', '')).strip()


try:
    for u in users:
        users[u]['username'] = sql('SELECT username FROM users WHERE id=' + str(users[u]['id']))
        users[u]['display'] = sql("SELECT COALESCE(NULLIF(display_name,''),username) FROM users WHERE id=" + str(users[u]['id']))
        call('GET', '/api/me/space', user=u)
    emp = uid('employee')
    sql("INSERT INTO community.usage_guides(user_id,version,status,context) SELECT id,1,'dismissed','overview' FROM users ON CONFLICT DO NOTHING")
    WORD = 'H1검색합성'
    page = call('POST', '/api/pages', {'title': WORD + ' 문서'}, expected=201)['page']
    h = {'User-Agent': UA, 'Cookie': '__Host-tbox_session=' + users['employee']['session'], 'X-CSRF-Token': users['employee']['csrf']}
    up = requests.post(site + '/tbox/api/files', headers=h, data={'access_level': 'internal'}, files={'file': (WORD + '-자료.txt', b'H1 synthetic', 'application/octet-stream')}, timeout=60)
    check(up.status_code in (200, 201), 'fixture: synthetic page + t-box file for the search check (file %d)' % up.status_code)
    check(sql("SELECT count(*) FROM files WHERE scan_status='clean' AND original_name=" + lit(WORD + '-자료.txt')) == '1', 'fixture: the synthetic file is clean (SCAN_ENABLED=0 in the harness)')
    KEY = 'qaredi-home-dashboard:v1:' + users['employee']['username']

    with sync_playwright() as pw:
        browser = pw.chromium.launch(executable_path=os.environ.get('CHROMIUM_EXECUTABLE'))

        def context(key, width, theme='light', touch=None, session=None):
            touch = (width < 720) if touch is None else touch
            ctx = browser.new_context(viewport={'width': width, 'height': 900 if width > 800 else 860}, has_touch=touch, is_mobile=touch, user_agent=UA,
                                      reduced_motion='reduce', color_scheme='dark' if theme == 'dark' else 'light')
            jar = []
            if key:
                jar.append({'name': '__Host-tbox_session', 'value': session or new_session(key), 'domain': host, 'path': '/', 'secure': True, 'httpOnly': True, 'sameSite': 'Lax'})
            for name, value in (('qaredi_motion', 'reduce'), ('tbox_theme', 'dark' if theme == 'dark' else 'light')):
                jar.append({'name': name, 'value': value, 'domain': host, 'path': '/'})
            ctx.add_cookies(jar)
            return ctx

        def home(t):
            t.page.goto(site + '/', wait_until='networkidle')
            t.page.wait_for_selector('#dashboard-services [data-home-shortcut-add]', timeout=15000)
            t.page.wait_for_timeout(250)

        def contrast(p, sel):
            return [round(hh.evaluate(CONTRAST_JS), 2) for hh in p.query_selector_all(sel) if hh.is_visible()]

        # ─── [grid] 폭 여섯 × 밝게 · 어둡게 · 고대비 ───────────────────────────────────────────────
        if want('grid'):
            def grid():
                summary = {}
                for mode in MODES:
                    sql('UPDATE users SET high_contrast=' + ('true' if mode == 'hc' else 'false') + ' WHERE id=' + str(emp))
                    for width in WIDTHS:
                        tag = '%d-%s' % (width, mode)
                        ctx = context('employee', width, 'dark' if mode == 'dark' else 'light')
                        t = Tab(ctx)
                        p = t.page
                        home(t)
                        st = p.evaluate(STATE_JS)
                        api = t.api()
                        summary[tag] = {k: st[k] for k in ('kids', 'toolsH', 'word', 'h1', 'search', 'wrapW', 'ids', 'rows', 'circles', 'labels', 'dashOpen', 'sumH', 'sumText', 'editVisible', 'overflow', 'small')}
                        summary[tag]['api'] = sorted(set(api))
                        phone = width < 720
                        # ① 머리
                        check(st['toolsCount'] == 1 and st['headerTools'] and st['kids'] == ['button.shell-tools__messenger', 'details.appbar__apps', 'details.shell-account'],
                              '① %s: one div.shell-tools in the header · order messenger · apps · account %s' % (tag, st['kids']))
                        check(st['headerLogo'] == 0 and st['themeButtons'] == 0 and not st['allServices'] and st['headerTheme'] is False and not st['oldHeading'],
                              '① %s: 「m. 모아」 logo · 「전체 서비스」 · 「테마」 text button · 「내 홈」 heading 0' % tag)
                        check(st['toolsH'] and all(x == 44 for x in st['toolsH']), '① %s: messenger · apps · chip heights 44 %s' % (tag, st['toolsH']))
                        # ② 가운데
                        w = st['word'] or {}
                        check(w.get('text') == '모아.' and w.get('hidden') == 'true' and w.get('fs') == ('44px' if phone else '64px') and w.get('vis'),   # 사이트 H4 — 「Qaredi」 → 「모아.」(DESIGN 2.33 · 글자 굵기는 부품 .moa-wordmark 800 — home_h4_browser 가 잰다)
                              '② %s: wordmark 「모아.」 %s · aria-hidden %s' % (tag, '44px' if phone else '64px', json.dumps(w, ensure_ascii=False)))
                        check(st['h1'] and st['h1']['text'] == '모아 홈' and st['h1']['w'] <= 1 and st['h1']['count'] == 1, '② %s: h1 「모아 홈」 for screen readers only (w %s)' % (tag, st['h1'] and st['h1']['w']))
                        s = st['search'] or {}
                        check(s and abs(s['h'] - 52) <= 0.5 and s['radius'] == '26px' and s['w'] <= 720.5 and abs(s['w'] - min(720, st['wrapW'])) <= 1 and s['icon'] == 20,
                              '② %s: search box 52 · radius 26 · width %.0f = min(720, %.0f) · icon 20' % (tag, s.get('w', -1), st['wrapW']))
                        check(not s.get('focused') and not s.get('autofocus') and s.get('ph') == '작업 · 문서 · 파일 검색', '② %s: no autofocus · placeholder 「작업 · 문서 · 파일 검색」' % tag)
                        # ③ 바로가기
                        check(st['ids'] == DEFAULT + ['+'], '③ %s: default five shortcuts + 「＋ 바로가기」 %s' % (tag, st['ids']))
                        check(all(c == [56, 56] for c in st['circles']) and st['svgs'] and all(v == [24, 24] for v in st['svgs']) and all(x == '13px' for x in st['labels']),
                              '③ %s: circles 56 · icons 24 · labels 13px' % tag)
                        want_rows = [4, 1] if phone else [5]
                        check(st['rows'] == want_rows and st['navMax'] == '824px', '③ %s: rows %s (want %s · max 8 per row = 824px)' % (tag, st['rows'], want_rows))
                        # ④ 대시보드 접힘 · 접힌 동안 위젯 창구 0
                        widget_calls = [a for a in api if WIDGET_RE.search(a)]
                        odd = sorted(set(a for a in api if '/api/' in a and not FIRST_OK_RE.match(a)))
                        check(st['dashOpen'] is False and st['sumH'] == 44 and st['sumText'] == '내 대시보드 ▾' and not st['editVisible'],
                              '④ %s: dashboard collapsed on the first screen · summary 「내 대시보드 ▾」 44 · 홈 편집 hidden' % tag)
                        check(widget_calls == [] and odd == [], '④ %s: while collapsed widget requests 0 · only account/layout/messenger calls %s %s' % (tag, widget_calls, odd))
                        # ⑤ 넘침 · 누름 자리 · 대비 · 콘솔
                        check(st['overflow'] <= 0 and st['small'] == [] and st['inline'] == 0, '⑤ %s: overflow 0 · targets < 44 none · inline style 0 %s' % (tag, json.dumps(st['small'], ensure_ascii=False)))
                        check(st['contrastAttr'] == ('high' if mode == 'hc' else None), '⑤ %s: html data-contrast %s' % (tag, st['contrastAttr']))
                        cr = contrast(p, '.home-wordmark, #dashboard-services > * > span:last-child, details.home-dashboard > summary [data-when="closed"], #home-search-input, '
                                         '.shell-tools__messenger > span:not(.icon):not(.badge), .shell-tools > .appbar__apps > summary > span[aria-hidden="true"], .shell-tools__name > span')
                        summary[tag]['contrast'] = min(cr) if cr else None
                        check(cr and min(cr) >= 4.5, '⑤ %s: contrast ≥ 4.5 (wordmark · shortcut names · dashboard summary · placeholder · header) min %.2f' % (tag, min(cr) if cr else -1))
                        if (width, mode) in ((1440, 'light'), (1440, 'dark'), (412, 'light'), (412, 'dark'), (360, 'hc'), (720, 'light')):
                            p.screenshot(path=str(shots / ('home-%s.png' % tag)))
                        check(t.clean('grid-' + tag), '⑤ %s: console 0 · page errors 0 · 5xx 0' % tag)
                        ctx.close()
                sql('UPDATE users SET high_contrast=false WHERE id=' + str(emp))
                metrics['grid'] = summary
            guard('grid', grid)

        # ─── [tools] 셸과 같은 오른쪽 위 셋 · 판 · 테마 ─────────────────────────────────────────────
        if want('tools'):
            def tools():
                summary = {}
                # 서버 HTML — 같은 마크업(한 파일) · aria-current 만 다름
                tok = new_session('employee')
                hh = {'User-Agent': UA, 'Cookie': '__Host-tbox_session=' + tok}
                r_home = requests.get(site + '/', headers=hh, timeout=30)
                r_tbox = requests.get(site + '/tbox/', headers=hh, timeout=30)
                r_comm = requests.get(site + '/community/', headers=hh, timeout=30)
                a, b, c = tools_html(r_home.text), tools_html(r_tbox.text), tools_html(r_comm.text)
                check(a is not None and a == b == c, '① server HTML: div.shell-tools on / equals /tbox/ and /community/ (aria-current removed) — one component')
                check(r_home.text.count('id="search-dialog"') == 1 and r_tbox.text.count('id="search-dialog"') == 1 and r_home.text.count('id="messenger-open"') == 1,
                      '① server HTML: one search dialog · one #messenger-open on the home (no duplicates)')
                cur = re.search(r'<a class="app-menu__app" href="/" aria-current="page">', r_home.text) is not None
                check(cur and re.search(r'<a class="app-menu__app" href="/" aria-current', r_tbox.text) is None, '① server HTML: 「모아 홈」 tile aria-current only on the home')
                for width in (1440, 412):
                    ctx = context('employee', width)
                    t = Tab(ctx)
                    p = t.page
                    home(t)
                    p.locator('.shell-tools > .appbar__apps > summary').click()
                    p.wait_for_timeout(150)
                    ap = p.evaluate(PANEL_JS, '.shell-tools .appbar__apps')
                    p.locator('.shell-tools .shell-account > summary').click()
                    p.wait_for_timeout(150)
                    ac = p.evaluate(PANEL_JS, '.shell-tools .shell-account')
                    ap_open = p.evaluate("document.querySelector('.shell-tools .appbar__apps').open")
                    summary['%d' % width] = {'apps': ap, 'account': ac}
                    check(ap['current'] == ['모아 홈'] and ap['tiles'][:4] == ['모아 홈', '업무', '자료실', '우리 공간'] and '검색' not in ap['tiles'] and '관리자 페이지' not in ap['tiles'],
                          '① %d: app panel 「모아 홈」 aria-current · apps and tools · admin tile hidden for an employee %s' % (width, json.dumps(ap['tiles'], ensure_ascii=False)))
                    check(ac['open'] and not ap_open and ac['tiles'] == ['내 계정', '내 활동', '테마'] and ac['logout'] == ['로그아웃'] and ac['current'] == [],
                          '① %d: chip → account panel 내 계정 · 내 활동 · 테마 + 로그아웃 · app panel closed (one at a time) %s' % (width, json.dumps(ac['tiles'], ensure_ascii=False)))
                    if width == 1440:
                        check(ac['cls'] == ap['cls'] == 'panel app-menu' and ac['w'] == ap['w'] == 300 and ac['overflow'] <= 0, '① 1440: both panels = same component · width 300 · overflow 0')
                        p.screenshot(path=str(shots / 'tools-account-1440.png'))
                    else:
                        check(ac['left'] == 0 and ac['w'] == width and ac['top'] == 64 and ac['overflow'] <= 0, '① %d: phone — account panel full width under the header %s' % (width, json.dumps({k: ac[k] for k in ('left', 'w', 'top')})))
                        p.screenshot(path=str(shots / ('tools-account-%d.png' % width)))
                    p.locator('.shell-account .app-menu__app[data-design-open]').click()
                    p.wait_for_timeout(200)
                    dlg = p.evaluate("({open: document.getElementById('q-design-dialog').open, acct: document.querySelector('.shell-tools .shell-account').open})")
                    check(dlg['open'] and not dlg['acct'], '① %d: 「테마」 tile closes the panel and opens the theme dialog' % width)
                    p.keyboard.press('Escape')
                    p.wait_for_timeout(150)
                    back = p.evaluate("({open: document.getElementById('q-design-dialog').open, chip: document.activeElement === document.querySelector('.shell-tools .shell-account > summary'), tag: document.activeElement && document.activeElement.tagName})")
                    check(not back['open'] and back['chip'], 'H1b %d: closing the theme dialog opened from the 「테마」 tile returns focus to the account chip summary %s' % (width, json.dumps(back)))
                    # Esc · 바깥 누름으로 닫힘(셸과 같은 함수)
                    p.locator('.shell-tools > .appbar__apps > summary').click()
                    p.keyboard.press('Escape')
                    p.wait_for_timeout(100)
                    esc = p.evaluate("({open: document.querySelector('.shell-tools .appbar__apps').open, focus: document.activeElement === document.querySelector('.shell-tools > .appbar__apps > summary')})")
                    check(not esc['open'] and esc['focus'], '① %d: Esc closes the app panel and focus returns to its button (same function as the shell)' % width)
                    check(t.clean('tools-%d' % width), '① %d tools console 0' % width)
                    ctx.close()
                # 셸 화면의 내 계정 판에도 「테마」 — 누르면 같은 테마 창
                for path in ('/tbox/', '/community/'):
                    ctx = context('employee', 1440)
                    t = Tab(ctx)
                    p = t.page
                    p.goto(site + path, wait_until='networkidle')
                    p.wait_for_selector('.shell-tools .shell-account > summary', timeout=15000)
                    p.locator('.shell-tools .shell-account > summary').click()
                    p.wait_for_timeout(150)
                    ac = p.evaluate(PANEL_JS, '.shell-tools .shell-account')
                    check(ac['tiles'] == ['내 계정', '내 활동', '테마'] and ac['logout'] == ['로그아웃'], '① shell %s: account panel has 「테마」 too %s' % (path, json.dumps(ac['tiles'], ensure_ascii=False)))
                    p.locator('.shell-account .app-menu__app[data-design-open]').click()
                    p.wait_for_timeout(200)
                    check(p.evaluate("document.getElementById('q-design-dialog').open"), '① shell %s: 「테마」 tile opens the same theme dialog' % path)
                    p.keyboard.press('Escape')
                    p.wait_for_timeout(150)
                    back = p.evaluate("({open: document.getElementById('q-design-dialog').open, chip: document.activeElement === document.querySelector('.shell-tools .shell-account > summary')})")
                    check(not back['open'] and back['chip'], 'H1b shell %s: closing the theme dialog returns focus to the account chip summary' % path)
                    check(t.clean('tools-shell' + path.replace('/', '_')), '① shell %s console 0' % path)
                    ctx.close()
                # 관리자 — 앱 판의 「관리자 페이지」 타일(can_admin 표시용 — 셸과 같은 값)
                ctx = context('admin', 1440)
                t = Tab(ctx)
                p = t.page
                home(t)
                p.wait_for_timeout(300)
                p.locator('.shell-tools > .appbar__apps > summary').click()
                ap = p.evaluate(PANEL_JS, '.shell-tools .appbar__apps')
                check('관리자 페이지' in ap['tiles'], '① admin: 「관리자 페이지」 tile shown on the home (same can_admin rule as the shell)')
                check(t.clean('tools-admin'), '① admin console 0')
                ctx.close()
                metrics['tools'] = summary
            guard('tools', tools)

        # ─── [search] 큰 검색 칸 → 공통 검색 창 · `/` 키 ───────────────────────────────────────────
        if want('search'):
            def search():
                summary = {}
                for width in (1440, 412):
                    ctx = context('employee', width)
                    t = Tab(ctx)
                    p = t.page
                    home(t)
                    p.locator('body').press('/')
                    p.wait_for_timeout(100)
                    focus = p.evaluate("document.activeElement && document.activeElement.id")
                    check(focus == 'home-search-input', 'search %d: 「/」 key focuses the big search box (%s)' % (width, focus))
                    p.keyboard.type('a/b')
                    check(p.evaluate("document.getElementById('home-search-input').value") == 'a/b', 'search %d: 「/」 typed inside the box stays a character' % width)
                    p.fill('#home-search-input', WORD)
                    p.keyboard.press('Enter')
                    p.wait_for_selector('#search-dialog[open]', timeout=5000)
                    p.wait_for_function("() => /파일/.test(document.getElementById('search-note').textContent) || /없습니다/.test(document.getElementById('search-note').textContent)", timeout=15000)
                    p.wait_for_timeout(300)
                    res = p.evaluate("""() => ({value: document.getElementById('search-input').value, heads: [...document.querySelectorAll('#search-results > li.nav-title')].map(l => l.textContent),
                        hits: [...document.querySelectorAll('#search-results .search-hit__title')].map(e => e.textContent), note: document.getElementById('search-note').textContent,
                        dialogs: document.querySelectorAll('#search-dialog').length, url: location.pathname})""")
                    summary[str(width)] = res
                    check(res['value'] == WORD and res['dialogs'] == 1 and res['url'] == '/', 'search %d: Enter opens the shared search dialog with that text (one dialog · page stays)' % width)
                    check('페이지' in res['heads'] and '파일' in res['heads'] and any(WORD in x for x in res['hits']),
                          'search %d: results in the page and file groups %s' % (width, json.dumps(res['heads'], ensure_ascii=False)))
                    if width == 1440:
                        p.screenshot(path=str(shots / 'search-1440.png'))
                    p.keyboard.press('Escape')
                    p.wait_for_timeout(150)
                    check(not p.evaluate("document.getElementById('search-dialog').open"), 'search %d: Esc closes the dialog' % width)
                    # 사이트 H5 — 앱 판 「검색」 타일은 없다(DESIGN 2.30 · 옛 「app panel 「검색」 tile opens the same dialog」)
                    check(p.locator('.shell-tools [data-search-tile]').count() == 0, 'H5 search %d: app panel 「검색」 tile 0 on the home' % width)
                    check(t.clean('search-%d' % width), 'search %d console 0' % width)
                    ctx.close()
                metrics['search'] = summary
            guard('search', search)

        # ─── [short] 메신저 바로가기 · 「＋ 바로가기」 · 설정 순서 · 여덟 개 한 줄 ───────────────────────
        if want('short'):
            def short():
                summary = {}
                for width in (1440, 412):
                    ctx = context('employee', width)
                    t = Tab(ctx)
                    p = t.page
                    home(t)
                    writes = []
                    p.on('request', lambda r: writes.append(r.method + ' ' + urlsplit(r.url).path) if r.method not in ('GET', 'HEAD') else None)
                    p.locator('#dashboard-services [data-home-shortcut="messages"]').click()
                    p.wait_for_selector('#messenger-window[open]', timeout=10000)
                    m = p.evaluate("({open: document.getElementById('messenger-window').open, url: location.pathname, exp: document.querySelector('#dashboard-services [data-home-shortcut=messages]').getAttribute('aria-expanded')})")
                    check(m['open'] and m['url'] == '/', 'short %d: 「메신저」 shortcut opens the messenger window (no navigation) %s' % (width, json.dumps(m)))
                    p.locator('#messenger-window [data-messenger-close]').first.click()
                    p.wait_for_timeout(200)
                    before = len(t.api())
                    p.locator('#dashboard-services [data-home-shortcut-add]').click()
                    p.wait_for_selector('.home-shortcut-settings[open]', timeout=15000)
                    p.wait_for_timeout(300)
                    st = p.evaluate("""() => { const s = document.querySelector('.home-shortcut-settings'); const r = s.getBoundingClientRect();
                        return {dash: document.querySelector('details.home-dashboard').open, editing: !!document.querySelector('.dashboard-editing'), open: s.open,
                                inDrawer: !!s.closest('dialog.dashboard-inspector-dialog[open]'), visible: r.width > 0 && r.height > 0 && r.top < innerHeight && r.bottom > 0,
                                focus: document.activeElement === s.querySelector('summary'), sumDisabled: document.querySelector('details.home-dashboard > summary').getAttribute('aria-disabled')}; }""")
                    summary['add-%d' % width] = st
                    check(st['dash'] and st['editing'] and st['open'] and st['visible'] and st['focus'] and st['inDrawer'] == (width < 1100),
                          'short %d: 「＋ 바로가기」 → dashboard opens · home edit · 「상단 바로가기」 settings open and focused (%s) %s' % (width, 'drawer' if width < 1100 else 'side panel', json.dumps(st)))
                    check(st['sumDisabled'] == 'true', 'short %d: summary aria-disabled while editing' % width)
                    if width == 1440:
                        # 설정 순서대로 · 여덟 개(일곱 + 「＋」) 한 줄
                        p.get_by_label('메신저 앞으로', exact=True).click()
                        p.wait_for_timeout(120)
                        order = p.evaluate("[...document.querySelectorAll('#dashboard-services > *')].map(n => n.dataset.homeShortcut || '+')")
                        check(order == ['work', 'messages', 'library', 'spaces', '+'], 'short 1440: hero row follows the settings order at once %s' % order)
                        for label in ('이야기', '계정'):
                            p.get_by_label(label, exact=True).check()
                            p.wait_for_timeout(80)
                        st8 = p.evaluate(STATE_JS)
                        summary['eight'] = {'ids': st8['ids'], 'rows': st8['rows']}
                        check(len(st8['ids']) == 7 and st8['rows'] == [7], 'short 1440: seven shortcuts + 「＋」 = eight in one row %s' % st8['rows'])
                        p.screenshot(path=str(shots / 'short-settings-1440.png'))
                    else:
                        p.screenshot(path=str(shots / ('short-settings-%d.png' % width)))
                    # 편집 중 접기 막힘
                    if p.locator('.dashboard-inspector-dialog[open]').count():
                        p.locator('.dashboard-inspector-dialog[open] .dashboard-inspector-close').click()
                        p.wait_for_timeout(150)
                    p.locator('details.home-dashboard > summary').click(force=True)
                    p.wait_for_timeout(200)
                    check(p.evaluate("document.querySelector('details.home-dashboard').open"), 'short %d: dashboard cannot be collapsed while editing' % width)
                    p.get_by_role('button', name='취소', exact=True).click()
                    p.wait_for_timeout(250)
                    after = p.evaluate("({editing: !!document.querySelector('.dashboard-editing'), ids: [...document.querySelectorAll('#dashboard-services > *')].map(n => n.dataset.homeShortcut || '+'), dis: document.querySelector('details.home-dashboard > summary').getAttribute('aria-disabled')})")
                    check(not after['editing'] and after['ids'] == DEFAULT + ['+'] and after['dis'] == 'false', 'short %d: cancel → editing off · default row back · summary enabled' % width)
                    check(writes == [], 'short %d: opening settings + cancel sends no write %s' % (width, writes))
                    check(t.clean('short-%d' % width), 'short %d console 0' % width)
                    ctx.close()
                metrics['short'] = summary
            guard('short', short)

        # ─── [dash] 펼침 · 처음 펼칠 때 부름 · 기억 · 편집 중 접기 막힘 ─────────────────────────────────
        if want('dash'):
            def dash():
                summary = {}
                for width in (1440, 412):
                    tag = str(width)
                    ctx = context('employee', width)
                    t = Tab(ctx)
                    p = t.page
                    home(t)
                    p.wait_for_timeout(600)
                    collapsed_widget = [a for a in t.api() if WIDGET_RE.search(a)]
                    p.locator('details.home-dashboard > summary').click()
                    p.wait_for_selector('#home-widgets > :not([hidden])', timeout=15000)
                    p.wait_for_load_state('networkidle')
                    p.wait_for_timeout(500)
                    st = p.evaluate("""() => { const vis = el => !!el && el.getClientRects().length > 0 && getComputedStyle(el).display !== 'none';
                        return {open: document.querySelector('details.home-dashboard').open, sum: document.querySelector('details.home-dashboard > summary').innerText.replace(/\\s+/g, ' ').trim(),
                                head: (document.querySelector('details.home-dashboard > .moa-section-head h2') || {}).textContent, edit: vis(document.getElementById('home-edit')),
                                refresh: [...document.querySelectorAll('#home-controls button')].filter(vis).map(b => b.textContent.trim()),
                                widgets: [...document.querySelectorAll('#home-widgets > *')].filter(vis).length, guide: vis(document.querySelector('.dashboard-guide')),
                                stored: (() => { try { return localStorage.getItem(%s); } catch (e) { return 'blocked'; } })(), overflow: document.documentElement.scrollWidth - innerWidth}; }""" % json.dumps(KEY))
                    opened_widget = [a for a in t.api() if WIDGET_RE.search(a)]
                    summary[tag] = dict(st, collapsedWidget=collapsed_widget, openedWidget=sorted(set(opened_widget)))
                    check(collapsed_widget == [] and len(opened_widget) > 0, '④ %s: widget requests only after the first open (collapsed %d · opened %d)' % (tag, len(collapsed_widget), len(opened_widget)))
                    check(st['open'] and st['sum'] == '접기 ▴' and st['head'] == '내 대시보드' and st['edit'] and '내용 새로고침' in st['refresh'] and st['widgets'] >= 1 and st['guide'],
                          '④ %s: open → 「접기 ▴」 · 내 대시보드 head · 홈 편집 · 내용 새로고침 · widgets · start guide %s' % (tag, json.dumps(st, ensure_ascii=False)))
                    check(st['stored'] == 'true' and st['overflow'] <= 0, '④ %s: open state stored in localStorage %s · overflow 0' % (tag, KEY))
                    if width == 1440:
                        p.screenshot(path=str(shots / 'dashboard-open-1440.png'), full_page=True)
                    else:
                        p.screenshot(path=str(shots / 'dashboard-open-412.png'))
                    # 새로 고침 → 펼침 기억 · 위젯 곧바로
                    t.reqs.clear()
                    p.reload(wait_until='networkidle')
                    p.wait_for_selector('#home-widgets > :not([hidden])', timeout=15000)
                    again = p.evaluate("document.querySelector('details.home-dashboard').open")
                    check(again and any(WIDGET_RE.search(a) for a in t.api()), '④ %s: after reload the dashboard stays open and loads its widgets' % tag)
                    # 편집 중 접기 막힘
                    p.locator('#home-edit').click()
                    p.wait_for_timeout(300)
                    p.locator('details.home-dashboard > summary').click(force=True)
                    p.wait_for_timeout(200)
                    blocked = p.evaluate("({open: document.querySelector('details.home-dashboard').open, note: document.getElementById('home-layout-status').textContent})")
                    check(blocked['open'] and '홈 편집을 마친 뒤' in blocked['note'], '④ %s: collapsing is blocked while 홈 편집 is on' % tag)
                    if p.locator('.dashboard-inspector-dialog[open]').count():
                        p.keyboard.press('Escape')
                    p.get_by_role('button', name='취소', exact=True).click()
                    p.wait_for_timeout(250)
                    p.locator('details.home-dashboard > summary').click()
                    p.wait_for_timeout(150)
                    closed = p.evaluate("({open: document.querySelector('details.home-dashboard').open, stored: localStorage.getItem(%s)})" % json.dumps(KEY))
                    p.reload(wait_until='networkidle')
                    p.wait_for_selector('#dashboard-services [data-home-shortcut-add]', timeout=15000)
                    reloaded = p.evaluate("document.querySelector('details.home-dashboard').open")
                    check(not closed['open'] and closed['stored'] == 'false' and reloaded is False, '④ %s: after editing ends it collapses · remembered collapsed after reload' % tag)
                    check(t.clean('dash-' + tag), '④ %s console 0' % tag)
                    ctx.close()
                metrics['dash'] = summary
            guard('dash', dash)

        # ─── [before] 로그인 전 · 동의 전 런처는 전과 같다 ─────────────────────────────────────────
        if want('before'):
            def before():
                summary = {}
                # 이 갈래가 마지막으로 의도해 바뀐 발행(v2.74.0 · Q:F-15 S1 — 그 전 기준 34cfd3d · H4 정규화는 이 판이 이미 H4 뒤라 필요 없음). 앞으로 이 갈래 글자를 일부러 바꾸는 발행이 나오면 이 값을 그 발행 커밋으로 옮긴다
                LAUNCHER_SNAPSHOT_REF = 'c2c78d0'
                base_src = subprocess.run(['git', '-C', str(WORKTREE), 'show', LAUNCHER_SNAPSHOT_REF + ':src/views/launcher.php'], check=True, capture_output=True).stdout
                (root / 'src/views/launcher_h1_baseline.php').write_bytes(base_src)
                stub = root / 'tests/fixtures/home_h1_render.php'
                stub.write_text("""<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('TBOX_BASE','/tbox'); define('COMMUNITY_BASE','/community'); define('TBOX_HOME_PATH','/');
require dirname(__DIR__,2).'/src/Http.php';
final class Auth { public static function currentUser(): ?array { return null; } }
final class Authz { public static function canCommunityWrite(array $u): bool { return false; } public static function canManageUsers(array $u): bool { return false; } }
final class Legal { public static function needsConsent(int $id): bool { return true; } }
$_COOKIE['tbox_theme']='light';
$launcherUser=($argv[2]??'')==='consent'?['id'=>1001,'username'=>'claude_manager01','display_name'=>'','role'=>'Manager']:null;
$launcherApps=[];
require dirname(__DIR__,2).'/src/views/'.($argv[1]==='base'?'launcher_h1_baseline.php':'launcher.php');
""")
                for who in ('consent',):   # 사이트 H2 — 로그인 전 갈래는 2.32 첫 화면으로 바뀌었다(그 갈래는 invite_request_browser.py [grid] 가 잰다) · 동의 전만 그대로
                    a = subprocess.run(['php', str(stub), 'base', who], cwd=str(root), check=True, capture_output=True).stdout
                    b = subprocess.run(['php', str(stub), 'new', who], cwd=str(root), check=True, capture_output=True).stdout
                    summary['html-' + who] = {'base': len(a), 'new': len(b), 'ref': LAUNCHER_SNAPSHOT_REF, 'sha_equal': hashlib.sha256(a).hexdigest() == hashlib.sha256(b).hexdigest()}
                    check(len(a) > 1000 and a == b,
                          '⑤ %s launcher: server HTML byte-identical to the last intended launcher (2afc533 · v2.74.0 Q:F-15 S1) — %d bytes' % ('signed-out' if who == 'out' else 'consent-needed', len(b)))
                (root / 'src/views/launcher_h1_baseline.php').unlink()
                stub.unlink()
                # 실제 서버 — 로그인 전 · 동의 전(관리자 아닌 팀장 계정에 지난 판 동의 줄 하나 — 끝나면 지운다)
                mid = uid('manager')
                row = sql("INSERT INTO user_consents(user_id,doc,version,action,ip) VALUES(" + str(mid) + ",'terms','2000-01-01','agree','127.0.0.1') RETURNING id").splitlines()[0]
                try:
                    for who, key in (('out', None), ('consent', 'manager')):
                        for width in (1440, 412):
                            tag = '%s-%d' % (who, width)
                            ctx = context(key, width)
                            t = Tab(ctx)
                            p = t.page
                            p.goto(site + '/', wait_until='networkidle')
                            p.wait_for_timeout(300)
                            st = p.evaluate("""() => ({tools: document.querySelectorAll('.shell-tools').length, hero: !!document.querySelector('.home-hero, .home-dashboard, .home-search'),
                                logo: !!document.querySelector('body > header .moa-logo'), intro: !!document.querySelector('.moa-service-hero'), landing: !!document.querySelector('.landing-hero'), cta: (document.getElementById('home-open-dashboard') || {}).textContent,
                                overflow: document.documentElement.scrollWidth - innerWidth, signed: document.body.dataset.signed})""")
                            summary[tag] = st
                            check(st['tools'] == 0 and not st['hero'] and st['logo'] and (st['intro'] if who == 'consent' else st['landing']) and st['signed'] == 'no' and st['overflow'] <= 0,   # H2 — 로그인 전은 .landing-hero
                                  '⑤ %s: header (logo) · intro screen as before · shell-tools 0 · H1 parts 0 · overflow 0 (%s)' % (tag, (st['cta'] or '').strip()))
                            if who == 'consent':
                                check((st['cta'] or '').startswith('동의하고 모아 홈 열기'), '⑤ %s: consent CTA unchanged' % tag)
                            p.screenshot(path=str(shots / ('before-%s.png' % tag)))
                            check(t.clean('before-' + tag), '⑤ %s console 0' % tag)
                            ctx.close()
                finally:
                    sql('DELETE FROM user_consents WHERE id=' + row)
                metrics['before'] = summary
            guard('before', before)

        # ─── [logout] 모아 홈의 로그아웃 — 셸과 같은 함수 ──────────────────────────────────────────
        if want('logout'):
            def logout():
                ctx = context('other', 1440)
                t = Tab(ctx)
                p = t.page
                home(t)
                p.wait_for_timeout(400)
                p.locator('.shell-tools .shell-account > summary').click()
                p.locator('#logout').click()
                p.wait_for_url(re.compile(r'.*/tbox/login.*'), timeout=10000)
                check('/tbox/login' in p.url, 'logout: 「로그아웃」 on the home ends the session and goes to the login screen')
                check(t.clean('logout'), 'logout console 0')
                ctx.close()
            guard('logout', logout)

        browser.close()
finally:
    (out / 'home-h1-browser-metrics.json').write_text(json.dumps(metrics, ensure_ascii=False, indent=1))
    (out / 'home-h1-browser-results.json').write_text(json.dumps(checks, ensure_ascii=False, indent=2))
    print('RESULT pass=' + str(sum(x['pass'] for x in checks)) + ' fail=' + str(sum(not x['pass'] for x in checks)), flush=True)
if any(not x['pass'] for x in checks):
    raise SystemExit(1)
