#!/usr/bin/env python3
"""사이트 Q:F-12 지시서 C-3 — 셸 · 앱 나누기 (실제 PHP·PostgreSQL + Chromium · 격리 틀 memories_harness.py 만)

  python3 tests/community/memories_harness.py --suite qf12_shell_browser.py --output <결과 폴더>

합성 DB · 임시 복사본 · 로컬 php -S 만 친다(운영 8082 · tbox_re · 시드 계정 0). 계정은 fixture 의 claude_ 다섯과 UA tbox-review/<날짜>.
결과 파일에는 단언 이름 · 참/거짓 · 측정값(색 · 대비 · 순서 · 폭)만 — 세션 · CSRF · 응답 본문 0. 스크린샷은 결과 폴더 shots/.

  Q:T-38 앱 목록    Apps::validate 통과 · 순서 자료실 · 업무 · 이야기 · 이야기 = /community/memories — 런처 타일(로그인 전·뒤) · 앱 메뉴 · 아이콘 줄이 같은 순서·링크
  Q:T-39 옛 주소    /community/?view=mine(전처럼 스레드 화면 — 이제 이야기 셸) · 공개 공유 /community/memories/s/{token}(로그인 없이 200 · 셸 0) ·
                    돌아갈 주소 /community/memories…(미로그인 302 → 로그인 → 같은 주소) · /community/today 308 Location /community/my-tasks
  Q:T-40 앱 격자    (U2 · DESIGN 2.18 — 옛 아이콘 줄 단언을 바꿈) 자료실 /tbox/ · 업무 /community/ · 이야기 /community/memories × 1440 · 1280 · 960:
                    nav.app-rail 0 · div.shell-tools 1(main 첫 자식 · 오른쪽 정렬) · details.appbar__apps 문서에 하나 · 닫힘 기본 · 누르면 판(폭 300 ±8 · 단추 오른쪽 아래) ·
                    타일 순서 · aria-current 하나 · Esc · 바깥 누름 / 959 · 820 · 412 · 360: shell-tools display none · #appbar-apps → 같은 details(전체 폭) ·
                    가로 넘침 0 · 테마 여섯 × 밝게 · 어둡게 · 고대비(밝게 · 어둡게) 타일 글자 대비 ≥ 4.5 계산값 원문
  C-2 셸           업무 · 이야기 사이드바 항목 순서(DESIGN 2.19) · 「오늘 할 일」 0 · 「내 휴지통」 은 「더보기」 안 · nav.moa-space-nav 0 ·
                    「＋ 새로 만들기 → 문서」 가 새 페이지를 만든다 · 「＋ 새 이야기」 가 쓰기 창 · 「여행 계획」 이 여행 창 · 손님 · 역할로 보기 쓰는 단추 0 ·
                    런처 · /tbox/welcome · /tbox/messages(모아 머리 화면)에는 격자 줄 0 · 이야기 셸은 editor.js 0 · 메신저 여는 단추는 격자에 하나(사이드바 0)
  더해서           scripts/ui_snapshot.py(AUDIT_JS) 를 이 인스턴스에서 1440 · 1280 — 화면마다 결함 수(기준 판과 대조는 결과 폴더 두 벌로)
"""
from pathlib import Path
exec(compile(Path(__file__).with_name('memories_http.py').read_text().split('try:\n for u in users:')[0], __file__, 'exec'))
import re, secrets, subprocess, sys
from urllib.parse import urlsplit, unquote
from playwright.sync_api import sync_playwright, expect
assert str(root).startswith('/tmp/qaredi-stage13-') and manifest['database'].startswith('tbox_memories_test_')
host = urlsplit(site).hostname
UA = 'tbox-review/' + time.strftime('%Y-%m-%d')
ONLY = set(filter(None, os.environ.get('QF12C_ONLY', '').split(',')))
shots = out / 'shots'
shots.mkdir(exist_ok=True)
metrics = {}


def check(ok, name):
    """단언 — 실패해도 끝까지 돈다(마지막에 실패 수로 끝낸다). 이름·참거짓만 남긴다."""
    checks.append({'pass': bool(ok), 'name': name})
    print(('PASS ' if ok else 'FAIL ') + name, flush=True)
    return bool(ok)


def want(group):
    return not ONLY or group in ONLY


def guard(name, fn):
    """화면 흐름 하나 — 예외(시간 초과 등)가 나도 다음 흐름은 돈다. 예외는 실패 한 줄로 남긴다(본문 0)."""
    try:
        fn()
    except Exception as error:   # noqa: BLE001 — 시험 흐름의 어떤 예외든 실패로 센다
        check(False, name + ' raised ' + type(error).__name__ + ': ' + str(error).splitlines()[0][:200])


def uid(key):
    return int(users[key]['id'])


def session_hash(token):
    return hashlib.sha256(token.encode()).hexdigest()


def new_session(key, act_role=None):
    token = secrets.token_hex(32)
    if act_role:
        sql("INSERT INTO sessions(id,user_id,expires_at,ip,user_agent,act_role,act_started_at,act_last_seen_at) VALUES('" + session_hash(token) + "'," + str(uid(key)) + ",clock_timestamp()+interval '8 hours','127.0.0.1','" + UA + "','" + act_role + "',now(),now())")
    else:
        sql("INSERT INTO sessions(id,user_id,expires_at,ip,user_agent) VALUES('" + session_hash(token) + "'," + str(uid(key)) + ",clock_timestamp()+interval '8 hours','127.0.0.1','" + UA + "')")
    return token


def cookie(token, extra=None):
    jar = [{'name': '__Host-tbox_session', 'value': token, 'domain': host, 'path': '/', 'secure': True, 'httpOnly': True, 'sameSite': 'Lax'}]
    jar.append({'name': 'qaredi_sidebar', 'value': 'open', 'domain': host, 'path': '/'})   # 카페 C5 — 펼친 셸을 잰다(쿠키 없으면 접힘이 기본)
    for name, value in (extra or {}).items():
        jar.append({'name': name, 'value': value, 'domain': host, 'path': '/'})
    return jar


def get(path, token=None, follow=False):
    h = {'User-Agent': UA}
    if token:
        h['Cookie'] = '__Host-tbox_session=' + token
    return requests.get(site + path, headers=h, timeout=35, allow_redirects=follow)


CONSOLE_OK = {('404', '/favicon.ico'), ('404', '/tbox/assets/moa/lake.jpg'), ('404', '/tbox/assets/moa/forest.jpg'), ('404', '/tbox/assets/moa/ocean.jpg')}


def code_console(lines):
    return [t for t, _ in lines if not t.startswith('Failed to load resource') and not t.startswith('[DOM] ')]


class Tab:
    """한 탭 — 콘솔 · 페이지 오류 · 5xx 를 모은다."""

    def __init__(self, ctx):
        self.page = ctx.new_page()
        self.console = []
        self.errors = []
        self.bad = []
        p = self.page
        p.on('console', lambda m: self.console.append((m.text, urlsplit(m.location.get('url', '')).path)))
        p.on('pageerror', lambda e: self.errors.append(str(e)))
        p.on('response', lambda r: self.bad.append(urlsplit(r.url).path) if r.status >= 500 else None)

    def go(self, path):
        self.page.goto(site + path, wait_until='networkidle')
        self.page.wait_for_timeout(250)

    def clean(self, tag=''):
        ok = not self.errors and not self.bad and code_console(self.console) == []
        if not ok:
            metrics['unclean-' + tag + '-' + str(len(metrics))] = {'errors': [e[:200] for e in self.errors], 'bad': self.bad,
                                                                   'console': [c[:200] for c in code_console(self.console)]}
        return ok


# ─── 색 · 대비 (WCAG 2.1 상대 휘도 — tests/lib/contrast.py 와 같은 식, 외부 라이브러리 0) ───────────────
def parse_color(text):
    """getComputedStyle 의 색 글자 → (r, g, b, a) 0..1. rgb()/rgba()/color(srgb …)/#hex 만 — 모르는 모양은 None."""
    t = (text or '').strip()
    m = re.fullmatch(r'rgba?\(\s*([\d.]+)[ ,]+([\d.]+)[ ,]+([\d.]+)(?:\s*[,/]\s*([\d.]+%?))?\s*\)', t)
    if m:
        a = m.group(4)
        alpha = 1.0 if a is None else (float(a[:-1]) / 100 if a.endswith('%') else float(a))
        return (float(m.group(1)) / 255, float(m.group(2)) / 255, float(m.group(3)) / 255, alpha)
    m = re.fullmatch(r'color\(srgb\s+([-\d.e]+)\s+([-\d.e]+)\s+([-\d.e]+)(?:\s*/\s*([\d.]+%?))?\s*\)', t)
    if m:
        a = m.group(4)
        alpha = 1.0 if a is None else (float(a[:-1]) / 100 if a.endswith('%') else float(a))
        return tuple(max(0.0, min(1.0, float(m.group(i)))) for i in (1, 2, 3)) + (alpha,)
    m = re.fullmatch(r'#([0-9a-fA-F]{6})([0-9a-fA-F]{2})?', t)
    if m:
        h = m.group(1)
        return (int(h[0:2], 16) / 255, int(h[2:4], 16) / 255, int(h[4:6], 16) / 255, int(m.group(2), 16) / 255 if m.group(2) else 1.0)
    m = re.fullmatch(r'#([0-9a-fA-F]{3})', t)
    if m:
        h = m.group(1)
        return tuple(int(c * 2, 16) / 255 for c in h) + (1.0,)
    return None


def over(top, below):
    """반투명 색 top 을 불투명 below 위에 겹친 색."""
    a = top[3]
    return tuple(top[i] * a + below[i] * (1 - a) for i in range(3)) + (1.0,)


def lum(c):
    def ch(v):
        return v / 12.92 if v <= 0.04045 else ((v + 0.055) / 1.055) ** 2.4
    return 0.2126 * ch(c[0]) + 0.7152 * ch(c[1]) + 0.0722 * ch(c[2])


def ratio(a, b):
    la, lb = lum(a), lum(b)
    hi, lo = max(la, lb), min(la, lb)
    return round((hi + 0.05) / (lo + 0.05), 2)


def hexof(c):
    return '#' + ''.join('%02x' % round(v * 255) for v in c[:3])


GRID_STATE_JS = r"""() => {
  const main = document.getElementById('main');
  const tools = document.querySelector('div.shell-tools');
  const d = document.querySelector('details.appbar__apps');
  const vis = el => { if (!el) return false; const r = el.getBoundingClientRect(); const s = getComputedStyle(el); return s.display !== 'none' && s.visibility !== 'hidden' && r.width > 0 && r.height > 0; };
  const box = el => { if (!el) return null; const r = el.getBoundingClientRect(); return {l: Math.round(r.left), r: Math.round(r.right), t: Math.round(r.top), b: Math.round(r.bottom), w: Math.round(r.width), vis: vis(el)}; };
  const sum = d ? d.querySelector(':scope > summary') : null;
  const panel = d ? d.querySelector('.app-menu') : null;
  const tiles = d ? [...d.querySelectorAll('.app-menu__apps > li')].filter(li => getComputedStyle(li).display !== 'none').map(li => { const a = li.firstElementChild; const n = a.querySelector('.app-menu__name'); const s = getComputedStyle(n); const ts = getComputedStyle(a);
    return {name: n.textContent.trim(), href: a.getAttribute('href'), current: a.getAttribute('aria-current'), tag: a.tagName, id: a.id, color: s.color, bg: ts.backgroundColor, size: s.fontSize}; }) : [];
  const out = d ? d.querySelector('.app-menu__logout') : null;   // 카페 C6 — 앱 판에는 로그아웃 줄이 없다(내 계정 판으로 옮김) → null
  const ms = getComputedStyle(main);
  const mr = main.getBoundingClientRect();
  const root = getComputedStyle(document.documentElement);
  return {rail: document.querySelectorAll('nav.app-rail').length, toolsCount: document.querySelectorAll('div.shell-tools').length, firstIsTools: !!tools && main.firstElementChild === tools,
          toolsDisplay: tools ? getComputedStyle(tools).display : null, details: document.querySelectorAll('details.appbar__apps').length, open: d ? d.open : null,
          tools: box(tools), mainInnerRight: Math.round(mr.right - parseFloat(ms.paddingRight)), sum: box(sum), panel: box(panel), tiles: tiles,
          panelBg: panel ? getComputedStyle(panel).backgroundColor : null, bodyBg: getComputedStyle(document.body).backgroundColor,
          logout: out ? {text: out.textContent.trim(), color: getComputedStyle(out).color, vis: vis(out), last: out === panel.lastElementChild} : null,
          me: (() => { const m = document.querySelector('.shell-tools__me'); return m ? {href: m.getAttribute('href'), label: m.getAttribute('aria-label'), vis: vis(m)} : null; })(),
          design: document.documentElement.getAttribute('data-design'), theme: document.documentElement.getAttribute('data-theme'), contrast: document.documentElement.getAttribute('data-contrast'),
          overflow: document.documentElement.scrollWidth > window.innerWidth, scrollWidth: document.documentElement.scrollWidth, innerWidth: window.innerWidth,
          appsExpanded: (document.getElementById('appbar-apps') || {getAttribute: () => null}).getAttribute('aria-expanded')};
}"""

SIDEBAR_JS = r"""() => {
  const s = document.getElementById('sidebar');
  const out = [];
  // 사이드바 자신은 hidden 을 지닌 채 넓은 화면에서 CSS 가 펼친다(app.css `.sidebar[hidden]`) — 그 위쪽 hidden 은 보지 않는다
  const visible = el => { const h = el.closest('[hidden]'); return (!h || h === s) && el.getClientRects().length > 0; };
  const spacer = s.querySelector('.sidebar__spacer');
  // 보이는 글자만(숨은 배지의 숫자 0 같은 것은 뺀다)
  const txt = el => { const c = el.cloneNode(true); c.querySelectorAll('[hidden]').forEach(n => n.remove()); return c.textContent.replace(/\s+/g, ' ').trim(); };
  for (const el of s.querySelectorAll('summary.btn, button.btn:not(#sidebar-collapse), .nav__item, p.nav-title, summary.nav-title, .app-menu')) {
    if (spacer && (spacer.compareDocumentPosition(el) & Node.DOCUMENT_POSITION_FOLLOWING)) continue;   // 아래 끝(테마 · 버전 · 사용자)은 셸 공통
    if (el.closest('.app-menu') || el.closest('dialog') || el.closest('.menu__list')) continue;
    if (el.closest('details:not([open])') && !el.matches('summary')) { out.push({t: 'folded', text: txt(el)}); continue; }
    if (!visible(el)) continue;
    out.push({t: el.matches('.nav-title') ? 'title' : 'item', text: txt(el)});
  }
  return out;
}"""


def grid_html(html):
    m = re.search(r'<ul class="app-menu__apps">(.*?)</ul>', html, re.S)
    return m.group(1) if m else None


def grid_tiles(html):
    """앱 격자 타일(HTML 순서) — (이름, href 또는 None, 지금 앱이면 True, id)"""
    out = []
    for li in re.findall(r'<li>(.*?)</li>', grid_html(html) or '', re.S):
        name = re.search(r'<span class="app-menu__name">([^<]+)</span>', li)
        href = re.search(r'^<a [^>]*href="([^"]+)"', li)
        tid = re.search(r'^<(?:a|button|span) id="([^"]+)"', li)
        out.append((name.group(1).strip() if name else None, href.group(1) if href else None, 'aria-current="page"' in li.split('>', 1)[0], tid.group(1) if tid else None))
    return out


failures_before = 0
try:
    for u in users:
        users[u]['username'] = sql('SELECT username FROM users WHERE id=' + str(users[u]['id']))
        users[u]['display'] = sql('SELECT COALESCE(NULLIF(display_name,\'\'),username) FROM users WHERE id=' + str(users[u]['id']))
        call('GET', '/api/me/space', user=u)
    emp, adm = uid('employee'), uid('admin')
    # 사용 안내가 화면을 덮지 않게(이 DB 의 모든 사람 — 이미 닫음)
    sql("INSERT INTO community.usage_guides(user_id,version,status,context) SELECT id,1,'dismissed','overview' FROM users ON CONFLICT DO NOTHING")
    sql("UPDATE sessions SET reauth_at=now() WHERE user_id=" + str(adm))
    acting = new_session('admin', act_role='Employee')
    # 공개 공유 링크(Q:T-39) — 사진 한 장 기록을 「링크 공유」로 저장하고 링크를 만든다(memories_http.py 와 같은 길)
    media = upload()
    shared_post = save(dict(payload(), media_ids=[media['id']], visibility='shared'))
    share_path = call('POST', '/api/memories/links', {'post_id': shared_post}, expected=201)['path']
    check(share_path.startswith('/community/memories/s/'), 'fixture: share link path shape /community/memories/s/{token}')

    # ─── Q:T-38 앱 목록 — Apps::validate · 런처 · 앱 메뉴 · 아이콘 줄 ───────────────────────────────────────────
    if want('t38'):
        prog = "require 'src/Apps.php'; echo json_encode(array_map(fn($a)=>[$a['key'],$a['name'],$a['desc'],$a['path'],$a['icon'],$a['order']], Apps::all()), JSON_UNESCAPED_UNICODE);"
        r = subprocess.run(['php', '-r', prog], cwd=root, capture_output=True, text=True)
        apps = json.loads(r.stdout) if r.returncode == 0 else []
        metrics['apps_json'] = apps
        check(r.returncode == 0, 'Q:T-38 Apps::all() — config/apps.json passes Apps::validate (no exception)')
        check([a[0] for a in apps] == ['tbox', 'community', 'story'], 'Q:T-38 order tbox · community · story ' + json.dumps([a[0] for a in apps]))
        check([a[1] for a in apps] == ['자료실', '업무', '이야기'], 'Q:T-38 names 자료실 · 업무 · 이야기')
        check(apps and apps[2][3] == '/community/memories' and apps[1][3] == '/community/' and apps[0][3] == '/tbox/', 'Q:T-38 paths /tbox/ · /community/ · /community/memories')
        check(apps and apps[1][4] == 'work' and apps[2][4] == 'community', 'Q:T-38 icons: 업무 work (new) · 이야기 community')
        # 런처(로그인 뒤 · 전)
        for label, token in (('signed-in', users['employee']['session']), ('signed-out', None)):
            r = get('/', token)
            tiles = re.search(r'<nav class="moa-shortcuts"[^>]*>(.*?)</nav>', r.text, re.S)
            names = re.findall(r'<strong>([^<]+?)(?: <span[^>]*>.*?</span>)?</strong>', tiles.group(1)) if tiles else []
            hrefs = re.findall(r'<a (?:id="messenger-open" )?href="([^"]+)"', tiles.group(1)) if tiles else []
            metrics['launcher_tiles_' + label] = list(zip(names, hrefs))
            check(r.status_code == 200 and [n.strip() for n in names][:3] == ['자료실', '업무', '이야기'], 'Q:T-38 launcher (' + label + ') tiles start 자료실 · 업무 · 이야기 ' + json.dumps(names, ensure_ascii=False))
            # 사이트 H1 (DESIGN 2.31 「머리」) — 로그인한 모아 홈의 오른쪽 위는 셸과 **같은** div.shell-tools 하나(shell_tools.php). 로그인 전은 그대로 0. 옛 단언 「no shell-tools grid row」를 바꿈
            check('app-rail' not in r.text and (r.text.count('<div class="shell-tools">') == 1 if token else 'shell-tools' not in r.text),
                  'Q:T-38 launcher (' + label + ') has no rail · shell-tools ' + ('once (H1 — same as the shell)' if token else '0 (signed out)'))
            if token:
                check(hrefs[:3] == ['/tbox/', '/community/', '/community/memories'], 'Q:T-38 launcher signed-in tile links ' + json.dumps(hrefs[:3]))
                # tests/q_launcher.sh(운영) 의 기대값을 이 인스턴스에서 같은 셈으로 — 수만 남긴다(본문 0)
                metrics['launcher_counts_signed_in'] = {'moa-account': r.text.count('class="moa-account"'), 'href=/tbox/': r.text.count('href="/tbox/"'),
                                                        'href=/community/memories': r.text.count('href="/community/memories"'), 'script_lines': sum(1 for l in r.text.splitlines() if '<script' in l),
                                                        'userchip': r.text.count('userchip'), 'soon': r.text.count('준비 중')}
                # 사이트 H1 — 머리의 「주 메뉴」 링크 줄은 셸과 같은 앱 판으로 바뀌었다(옛 단언 「launcher header links 업무 · 이야기」를 앱 판 타일로)
                nav = re.search(r'<details class="appbar__apps" id="app-grid">(.*?)</details>', r.text, re.S)
                nav = nav.group(1) if nav else ''
                check('>업무<' in nav and '>이야기<' in nav and '>커뮤니티<' not in nav, 'Q:T-38 launcher app panel tiles 업무 · 이야기 (커뮤니티 0)')
            else:
                check(hrefs[:3] == ['/tbox/', '/tbox/login?to=community', '/tbox/login?to=%2Fcommunity%2Fmemories'], 'Q:T-38 launcher signed-out tile links (login first) ' + json.dumps(hrefs[:3]))
                lr = get('/tbox/login?to=%2Fcommunity%2Fmemories')
                check(lr.status_code == 200 and 'data-to="/community/memories"' in lr.text, 'Q:T-38 signed-out 이야기 tile → login page keeps the return address /community/memories (returnPath allow-list as before)')
        # 셸의 앱 격자(HTML) — 세 앱 (U2 · DESIGN 2.18 — 옛 아이콘 줄 · 사이드바 앱 메뉴 단언을 격자 단언으로 바꿈)
        TILES = ['모아 홈', '자료실', '업무', '이야기', '관리자 페이지', '검색']   # 카페 C6 (DESIGN 2.30) — 앱 판 = 앱과 도구만(메신저 · 내 계정 · 내 활동은 밖으로)
        for path, here in (('/tbox/', 'tbox'), ('/community/', 'community'), ('/community/memories', 'story')):
            r = get(path, users['employee']['session'])
            tiles = grid_tiles(r.text)
            metrics['grid_html' + path.replace('/', '_')] = tiles
            want_href = {'tbox': '/tbox/', 'community': '/community/', 'story': '/community/memories'}[here]
            check([t_[0] for t_ in tiles] == TILES, 'Q:T-38 app grid ' + path + ' tile order (HTML — 관리자 is hidden until can_admin) ' + json.dumps([t_[0] for t_ in tiles], ensure_ascii=False))
            acct = re.search(r'<details class="shell-account" id="account-menu">(.*?)</details>', r.text, re.S)   # 카페 C6 (DESIGN 2.30) — 내 계정 · 내 활동 · 로그아웃은 내 계정 판에
            check([t_[1] for t_ in tiles[:4]] == ['/', '/tbox/', '/community/', '/community/memories'] and tiles[4][3] == 'menu-admin'
                  and acct is not None and 'id="menu-settings"' in acct.group(1) and 'id="menu-activity"' in acct.group(1),
                  'Q:T-38 app grid ' + path + ' links (Apps::all() order) · id menu-admin kept · menu-settings · menu-activity in the account panel')
            check([t_[1] for t_ in tiles if t_[2]] == [want_href], 'Q:T-38 app grid ' + path + ' aria-current only on the current app = ' + want_href)
            check(re.search(r'<li><span class="app-menu__app[^"]*"[^>]*>|data-search-tile', r.text) is not None and re.search(r'<button id="messenger-open" class="btn btn--quiet shell-tools__messenger"', r.text) is not None,
                  'Q:T-38 ' + path + ' messenger = own button in the tool row (C6) · search tile present')
            check(r.text.count('<details class="appbar__apps"') == 1 and 'app-rail' not in r.text and r.text.count('class="moa-space-nav"') == 0 and 'moa-shell-links' not in r.text,
                  'Q:T-38 ' + path + ' one app menu (moved, not copied) · rail 0 · nav.moa-space-nav 0 · .moa-shell-links 0')
            check(re.search(r'<main id="main" class="main">\s*(?:<!--.*?-->\s*)?<div class="shell-tools">', r.text, re.S) is not None, 'Q:T-38 ' + path + ' div.shell-tools is the first child of <main>')
            check(r.text.count('id="logout"') == 1 and acct is not None and re.search(r'<button id="logout" class="app-menu__logout"', acct.group(1)) is not None, 'Q:T-38 ' + path + ' 로그아웃 one row inside the account panel (C6)')
            check('data-mobile-nav-mode' not in r.text, 'Q:T-38 ' + path + ' drawer 「메뉴 / 앱·계정」 switch removed')
            brand = {'tbox': '모아 자료실', 'community': '모아 업무', 'story': '모아 이야기'}[here]
            check(('aria-label="' + brand + ' — 앱 전환"') in r.text, 'C-2 ' + path + ' phone app-name button aria-label 「' + brand + ' — 앱 전환」')
            check('<summary class="btn btn--quiet" aria-label="앱 — 앱 바꾸기"' in r.text and 'brand__switch' not in r.text, 'C-2 ' + path + ' grid summary = ⋮⋮⋮ + 「앱」 outline button 「앱 — 앱 바꾸기와 계정」 (U5-2 · DESIGN 2.28) · brand is no longer a menu (DESIGN 2.19 ① U2)')
            # 카페 C5 — 쿠키 없는 첫 HTML 은 접힘이 기본(☰ aria 첫 값 = 펼치기)
            check(re.search(r'<button id="sidebar-collapse" class="btn btn--icon btn--plain" type="button" aria-controls="sidebar" aria-expanded="false" aria-label="메뉴 펼치기">', r.text) is not None, 'C-2 ' + path + ' sidebar head ☰ #sidebar-collapse')
            me = re.search(r'<summary class="shell-tools__me" aria-label="([^"]+)">', r.text)   # 카페 C6 (DESIGN 2.30) — 칩은 내 계정 판의 summary
            check(me is not None and me.group(1) == '내 계정 메뉴 — ' + users['employee']['display'], 'Q:T-38 grid row 「내 계정」 chip = account panel summary with display name label')
        # 손님 · 역할로 보기도 같은 목록(앱 안 판정은 앱이 한다)
        for label, token in (('guest', users['guest']['session']), ('acting', acting)):
            for path in ('/tbox/', '/community/', '/community/memories'):
                r = get(path, token)
                check(r.status_code == 200 and [t_[0] for t_ in grid_tiles(r.text)] == TILES, 'Q:T-38 ' + label + ' ' + path + ' grid same list')
                if label == 'guest':
                    check('id="nav-new-menu"' not in r.text and 'id="nav-story-new"' not in r.text and 'id="nav-story-travel"' not in r.text and 'id="nav-community-new"' not in r.text,
                          'C-2 guest ' + path + ' no write buttons (새로 만들기 · 새 이야기 · 여행 계획) — 판정 함수 값(can_community_write)')
                    check('id="messenger-open"' not in r.text, 'C-2 guest ' + path + ' messenger tile is the 이용 안내 link (no window opener)')
        # 모아 머리 화면(member_top) 에는 격자 줄이 없다
        for path in ('/tbox/welcome', '/tbox/messages'):
            r = get(path, users['employee']['session'])
            check(r.status_code == 200 and 'app-rail' not in r.text and 'shell-tools' not in r.text, 'Q:T-38 ' + path + ' (모아 머리 화면) has no rail · no shell-tools')

    # ─── Q:T-39 옛 주소 그대로(WK-15 A) ─────────────────────────────────────────────────────────────────
    if want('t39'):
        r = get('/community/?view=mine', users['employee']['session'])
        check(r.status_code == 200 and 'id="community-threads"' in r.text and 'data-page="community-threads"' in r.text, 'Q:T-39 /community/?view=mine → threads screen 200 (as before)')
        check([t_[1] for t_ in grid_tiles(r.text) if t_[2]] == ['/community/memories'], 'Q:T-39 /community/?view=mine is drawn in the 이야기 shell (grid 이야기 current)')
        r = get('/community/?space=1', users['employee']['session'])
        check(r.status_code == 200 and [t_[1] for t_ in grid_tiles(r.text) if t_[2]] == ['/community/'], 'Q:T-39 /community/?space= (old workspace bookmark) still the workspace in the 업무 shell')
        r = requests.get(site + share_path, headers={'User-Agent': UA}, timeout=35, allow_redirects=False)
        check(r.status_code == 200 and 'threads-public' in r.text and 'shell-tools' not in r.text and 'id="sidebar"' not in r.text, 'Q:T-39 public share link without login: 200 · no shell · no grid')
        check(not [h for h in r.headers if h.lower() == 'set-cookie'], 'Q:T-39 public share link Set-Cookie 0')
        feed = requests.get(site + share_path + '/feed', headers={'User-Agent': UA}, timeout=35)
        check(feed.status_code == 200 and [p['id'] for p in feed.json()['posts']] == [shared_post], 'Q:T-39 public share feed returns exactly the shared post')
        for path in ('/community/memories?view=mine', '/community/memories?travel=1', '/community/memories'):
            r = requests.get(site + path, headers={'User-Agent': UA}, timeout=35, allow_redirects=False)
            loc = r.headers.get('Location', '')
            to = unquote(loc.split('?to=', 1)[1]) if '?to=' in loc else ''
            metrics['return' + path] = {'status': r.status_code, 'location': loc}
            check(r.status_code == 302 and loc.startswith('/tbox/login?to=') and to == path, 'Q:T-39 signed-out ' + path + ' → 302 login with return address ' + loc)
            r2 = get(loc, users['employee']['session'])
            check(r2.status_code == 302 and r2.headers.get('Location') == path, 'Q:T-39 signed-in login?to= → back to ' + path + ' (' + str(r2.status_code) + ' ' + r2.headers.get('Location', '') + ')')
        for bad in ('/community/memories/../workspace', '//evil.example/community/memories', '/community/memories?view=evil'):
            r = get('/tbox/login?to=' + requests.utils.quote(bad, safe=''), users['employee']['session'])
            check(r.status_code == 302 and r.headers.get('Location') in ('/community/', '/tbox/'), 'Q:T-39 return address outside the allow-list is ignored (' + bad + ' → ' + r.headers.get('Location', '') + ')')
        r = get('/community/today', users['employee']['session'])
        check(r.status_code == 308 and r.headers.get('Location') == '/community/my-tasks', 'Q:T-39 /community/today → 308 Location /community/my-tasks (B — kept)')
        r = get('/community/today')
        check(r.status_code == 302 and r.headers.get('Location', '').startswith('/tbox/login'), 'Q:T-39 /community/today signed-out → 302 login (gate first, as before)')

    with sync_playwright() as pw:
        browser = pw.chromium.launch(executable_path=os.environ.get('CHROMIUM_EXECUTABLE'))

        # Mobile drawer: separate navigation/app panel, bounded lists, keyboard and resize.
        if want('mobile'):
            for width in (360, 412, 820):
                ctx = browser.new_context(viewport={'width': width, 'height': 844}, locale='ko-KR', user_agent=UA, has_touch=True)
                ctx.add_cookies(cookie(new_session('admin'), {'qaredi_motion': 'reduce', 'tbox_theme': 'dark' if width == 412 else 'light'}))
                ctx.add_init_script("localStorage.setItem('qaredi-nav-fav-open', '0')")
                t = Tab(ctx); p = t.page
                def mobile_drawer():
                    for path, main_link in (('/community/workspace', '#nav-work-home'), ('/tbox/', '#nav-files'), ('/community/memories', '[data-thread-nav="feed"]')):
                        t.go(path)
                        before=p.locator('#main').bounding_box();before_scroll=p.evaluate('scrollY')
                        p.locator('#nav-toggle').click()
                        after=p.locator('#main').bounding_box()
                        check(before==after and p.evaluate('scrollY')==before_scroll, 'modal %d %s opening does not move or resize main' % (width,path))
                        check(p.locator('#mobile-nav-dialog').evaluate('(e)=>e.matches(":modal")'), 'modal %d %s native top layer' % (width,path))
                        check(p.locator(main_link).is_visible() and not p.locator('.app-menu').is_visible(), 'mobile %d %s navigation only' % (width,path))
                        check(not p.locator('#sidebar .moa-shell-links').is_visible() and not p.locator('#sidebar .moa-brand-home').is_visible(), 'mobile %d %s no duplicate brand links' % (width,path))
                        if path == '/community/workspace':
                            # Lots of synthetic rows reproduce a long sidebar without touching production data.
                            p.evaluate("""() => {const box=document.getElementById('nav-community-tree');for(let i=0;i<35;i++){const a=document.createElement('a');a.className='nav__item';a.href='#';a.textContent='합성 문서 '+i;box.append(a);}}""")
                            check(p.locator('#sidebar details[data-sidebar-group][open]').count()==0, 'mobile %d all secondary groups start closed' % width)
                            docs=p.locator('#nav-community-documents-box')
                            docs.locator('summary').click()
                            check(docs.get_attribute('open') is not None and p.locator('#nav-community-home').is_visible(), 'mobile %d documents expand on demand' % width)
                            p.locator('#nav-work-projects-group > summary').click()
                            expect(docs).not_to_have_attribute('open','')
                            check(p.locator('#sidebar details[data-sidebar-group][open]').count()==1, 'mobile %d only selected group open' % width)
                            dims=p.locator('#sidebar [data-sidebar-group] > summary:visible').evaluate_all('(els)=>els.map(e=>e.getBoundingClientRect().height)')
                            check(dims and min(dims)>=44, 'mobile %d group summary touch targets >=44' % width)
                            p.screenshot(path=str(shots/('mobile-nav-%d.png' % width)))
                        # U2(DESIGN 2.18 「폰」) — 서랍의 「앱·계정」 전환 단추는 없다. 앱 이름 ▾(#appbar-apps)가 본문의 같은 앱 격자 details 를 연다(전체 폭)
                        check(p.locator('[data-mobile-nav-mode]').count() == 0, 'mobile %d %s drawer mode switch removed (☰ = navigation only)' % (width,path))
                        p.locator('#mobile-nav-close').click()
                        p.locator('#appbar-apps').click()
                        p.wait_for_timeout(150)
                        g = p.evaluate(GRID_STATE_JS)
                        check(g['open'] and g['details'] == 1 and g['panel']['vis'] and not p.locator(main_link).is_visible(), 'mobile %d %s app name ▾ opens the same app grid (drawer closed)' % (width,path))
                        check(abs(g['panel']['w'] - width) <= 2 and g['panel']['t'] >= 51, 'mobile %d %s grid panel full width under the top bar %s' % (width,path,json.dumps(g['panel'])))
                        check(p.locator('#appbar-account').is_visible() and p.locator('#menu-admin').is_visible(), 'mobile %d %s account (avatar button — C6)/admin reachable' % (width,path))
                        check(p.locator('#appbar-apps').get_attribute('aria-expanded')=='true' and p.locator('#nav-toggle').get_attribute('aria-expanded')=='false', 'mobile %d %s exclusive expanded state' % (width,path))
                        check(p.evaluate('document.documentElement.scrollWidth<=innerWidth'), 'mobile %d %s no horizontal overflow' % (width,path))
                        p.screenshot(path=str(shots/('mobile-apps-%d-%s.png' % (width,path.split('/')[1]))))
                        p.keyboard.press('Escape')
                        check(not p.evaluate("document.querySelector('details.appbar__apps').open") and p.locator('#appbar-apps').evaluate('(e)=>document.activeElement===e') and p.locator('#appbar-apps').get_attribute('aria-expanded')=='false',
                              'mobile %d %s Escape closes the grid and restores focus to the app name' % (width,path))
                        p.locator('#appbar-apps').click();p.locator('#nav-toggle').click()
                        check(p.locator(main_link).is_visible() and not p.locator('.app-menu').is_visible(), 'mobile %d %s grid → ☰ switches directly to navigation (one panel at a time)' % (width,path))
                        p.locator('#mobile-nav-close').click();p.locator('#nav-toggle').click()
                        check(p.locator('#sidebar').evaluate('(e)=>e.scrollTop')==0 and p.locator('#sidebar [data-sidebar-group][open]').count()==0, 'mobile %d %s reopen starts compact at top' % (width,path))
                    # Only the drawer scrolls; keyboard focus stays in the modal.
                    p.keyboard.press('Tab');p.keyboard.press('Shift+Tab')
                    check(p.evaluate("document.getElementById('mobile-nav-dialog').contains(document.activeElement)"), 'modal %d keyboard focus contained' % width)
                    y=p.evaluate('scrollY');p.mouse.move(width-2,800);p.mouse.wheel(0,500);p.wait_for_timeout(100)
                    check(p.evaluate('scrollY')==y,'modal %d background wheel scroll locked' % width)
                    p.mouse.click(width-2,800)
                    expect(p.locator('#mobile-nav-dialog')).not_to_be_visible()
                    check(not p.locator('#sidebar').is_visible(),'modal %d backdrop click closes' % width)
                    # Test opening at an existing scroll offset rather than only at the page top.
                    t.go('/community/workspace')
                    p.evaluate("() => {let d=document.createElement('div');d.style.height='2000px';document.getElementById('main').append(d);window.scrollTo(0,150);}")
                    before=p.locator('#main').bounding_box();y=p.evaluate('scrollY')
                    p.locator('#nav-toggle').click()
                    check(p.locator('#main').bounding_box()==before and p.evaluate('scrollY')==y,'modal %d scrolled page stays still on open' % width)
                    p.locator('#mobile-nav-close').click()
                    check(p.locator('#main').bounding_box()==before and p.evaluate('scrollY')==y,'modal %d close restores original scroll position' % width)
                    # Search opens after the menu closes, including its keyboard return target.
                    p.locator('#nav-toggle').click();p.locator('#nav-search').click()
                    expect(p.locator('#search-dialog')).to_be_visible()
                    check(not p.locator('#mobile-nav-dialog').is_visible(),'modal %d chosen search action closes menu first' % width)
                    p.keyboard.press('Escape')
                    # A floating messenger hidden behind the drawer must not swallow Escape.
                    p.keyboard.press('Escape')
                    p.locator('#appbar-messenger').click()
                    expect(p.locator('#messenger-window')).to_be_visible()
                    p.locator('#nav-toggle').click()
                    p.keyboard.press('Escape')
                    check(not p.locator('#sidebar').is_visible(), 'mobile %d Escape closes drawer with floating messenger open' % width)
                    t.go('/community/workspace');p.locator('#appbar-apps').click()
                    p.set_viewport_size({'width':1280,'height':900})
                    expect(p.locator('#nav-community-home')).to_be_visible()
                    check(not p.locator('.app-menu').is_visible() and p.locator('#nav-community-home').is_visible(), 'mobile %d resize restores desktop navigation and expanded docs' % width)
                    expect(p.locator('#nav-community-fav-box')).not_to_have_attribute('open','')
                    check(p.evaluate("localStorage.getItem('qaredi-nav-fav-open')")=='0', 'mobile %d desktop favorite preference preserved' % width)
                    p.set_viewport_size({'width':width,'height':844})
                    expect(p.locator('#sidebar')).not_to_be_visible()
                    p.locator('#nav-toggle').click()
                    check(p.locator('#sidebar [data-sidebar-group][open]').count()==0, 'mobile %d resize to mobile resets groups' % width)
                    check(t.clean('mobile-'+str(width)), 'mobile %d no browser errors' % width)
                guard('mobile drawer '+str(width), mobile_drawer)
                ctx.close()

        # ─── Q:T-40 앱 격자 — 자리 · 열고 닫기 · 순서 · 넘침 (U2 · DESIGN 2.18 — 옛 아이콘 줄 단언을 바꿈) ───────────────────────────
        if want('t40'):
            tok = new_session('employee')
            tok_admin = new_session('admin')
            screens = (('/tbox/', '/tbox/', '자료실'), ('/community/', '/community/', '업무'), ('/community/memories', '/community/memories', '이야기'))
            TILES_EMP = ['모아 홈', '자료실', '업무', '이야기', '검색']   # 카페 C6 (DESIGN 2.30)
            for width in (1440, 1280, 960, 959, 820, 412, 360):
                ctx = browser.new_context(viewport={'width': width, 'height': 900}, locale='ko-KR', user_agent=UA, has_touch=width <= 412, is_mobile=width <= 412)
                ctx.add_cookies(cookie(tok, {'qaredi_motion': 'reduce'}))
                t = Tab(ctx)
                p = t.page

                def one_width():
                    for path, current, label in screens:
                        t.go(path)
                        st = p.evaluate(GRID_STATE_JS)
                        key = 'grid-%d%s' % (width, path.replace('/', '_'))
                        metrics[key] = {k: st[k] for k in ('rail', 'toolsCount', 'firstIsTools', 'toolsDisplay', 'details', 'open', 'tools', 'mainInnerRight', 'sum', 'overflow', 'scrollWidth', 'innerWidth')}
                        check(st['rail'] == 0 and st['details'] == 1 and st['toolsCount'] == 1 and st['firstIsTools'], 'Q:T-40 %d %s nav.app-rail 0 · div.shell-tools 1 (first child of main) · details.appbar__apps one in the document' % (width, label))
                        check(st['open'] is False, 'Q:T-40 %d %s grid closed by default' % (width, label))
                        if width >= 960:
                            check(st['toolsDisplay'] == 'flex' and abs(st['tools']['r'] - st['mainInnerRight']) <= 2 and st['sum']['vis'] and st['me']['vis'],
                                  'Q:T-40 %d %s shell-tools row right-aligned (right %s = main inner right %s) · ⋮⋮⋮ + avatar visible' % (width, label, st['tools']['r'], st['mainInnerRight']))
                            p.locator('details.appbar__apps > summary').click()
                            p.wait_for_timeout(150)
                            st = p.evaluate(GRID_STATE_JS)
                            metrics[key]['panel'] = st['panel']
                            metrics[key]['tiles'] = [x['name'] for x in st['tiles']]
                            check(st['open'] and st['panel']['vis'] and abs(st['panel']['w'] - 300) <= 8 and abs(st['panel']['r'] - st['sum']['r']) <= 8 and st['panel']['t'] >= st['sum']['b'],
                                  'Q:T-40 %d %s click → panel opens (width 300 ±8 · right-aligned under the button) %s' % (width, label, json.dumps([st['panel'], st['sum']])))
                            check([x['name'] for x in st['tiles']] == TILES_EMP, 'Q:T-40 %d %s tile order 모아 홈 · 자료실 · 업무 · 이야기 · 검색 (C6 — 관리자 hidden for employee) %s' % (width, label, json.dumps([x['name'] for x in st['tiles']], ensure_ascii=False)))
                            check([x['href'] for x in st['tiles'] if x['current'] == 'page'] == [current], 'Q:T-40 %d %s aria-current = %s only' % (width, label, current))
                            check(st['logout'] is None and p.locator('details.shell-account .app-menu__logout').count() == 1, 'Q:T-40 %d %s 로그아웃 row moved to the account panel (C6)' % (width, label))
                            if width in (1440, 412) or (width == 960 and path == '/community/'):
                                p.screenshot(path=str(shots / ('grid-open-%d%s.png' % (width, path.replace('/', '_')))))
                            p.keyboard.press('Escape')
                            p.wait_for_timeout(100)
                            check(not p.evaluate("document.querySelector('details.appbar__apps').open") and p.evaluate("document.activeElement === document.querySelector('details.appbar__apps > summary')"),
                                  'Q:T-40 %d %s Esc closes · focus back on ⋮⋮⋮' % (width, label))
                            p.locator('details.appbar__apps > summary').click()
                            p.mouse.click(st['tools']['l'] + 20, (st['tools']['t'] + st['tools']['b']) / 2)   # 격자 줄의 빈 왼쪽(누를 것이 없는 자리)
                            p.wait_for_timeout(100)
                            check(not p.evaluate("document.querySelector('details.appbar__apps').open"), 'Q:T-40 %d %s outside click closes' % (width, label))
                        else:
                            check(st['toolsDisplay'] == 'none', 'Q:T-40 %d %s shell-tools display none' % (width, label))
                            p.locator('#appbar-apps').click()
                            p.wait_for_timeout(150)
                            st = p.evaluate(GRID_STATE_JS)
                            check(st['open'] and st['panel']['vis'] and abs(st['panel']['w'] - width) <= 2 and st['appsExpanded'] == 'true' and [x['name'] for x in st['tiles']] == TILES_EMP,
                                  'Q:T-40 %d %s #appbar-apps → the same details opens (full width %s) · aria-expanded true' % (width, label, json.dumps(st['panel'])))
                            if width in (412,):
                                p.screenshot(path=str(shots / ('grid-open-%d%s.png' % (width, path.replace('/', '_')))))
                            p.locator('#appbar-apps').click()
                            p.wait_for_timeout(100)
                            check(not p.evaluate("document.querySelector('details.appbar__apps').open") and p.locator('#appbar-apps').get_attribute('aria-expanded') == 'false', 'Q:T-40 %d %s app name ▾ again closes' % (width, label))
                        check(not st['overflow'], 'Q:T-40 %d %s horizontal overflow 0 (%d ≤ %d)' % (width, label, st['scrollWidth'], st['innerWidth']))
                    if width <= 412:
                        # 폰 — 서랍(☰)은 내비만 · 격자 0 · 넘침 0
                        for path in ('/community/', '/community/memories'):
                            t.go(path)
                            p.click('#nav-toggle')
                            p.wait_for_timeout(300)
                            st = p.evaluate(GRID_STATE_JS)
                            check(not st['open'] and not p.locator('.app-menu').is_visible() and not st['overflow'], 'Q:T-40 %d drawer open %s: navigation only (grid closed) · overflow 0' % (width, path))
                            p.screenshot(path=str(shots / ('drawer-%d%s.png' % (width, path.replace('/', '_')))), full_page=True)
                guard('Q:T-40 %d' % width, one_width)
                check(t.clean('t40-%d' % width), 'Q:T-40 %d no page errors / 5xx / script console output' % width)
                ctx.close()
            # 관리자 — 「관리자 페이지」 타일(can_admin 표시용 그대로)
            ctx = browser.new_context(viewport={'width': 1440, 'height': 900}, locale='ko-KR', user_agent=UA)
            ctx.add_cookies(cookie(tok_admin, {'qaredi_motion': 'reduce'}))
            t = Tab(ctx)
            p = t.page

            def admin_tiles():
                for path in ('/tbox/', '/community/'):
                    t.go(path)
                    p.locator('details.appbar__apps > summary').click()
                    p.wait_for_timeout(150)
                    st = p.evaluate(GRID_STATE_JS)
                    names = [x['name'] for x in st['tiles']]
                    check(names == ['모아 홈', '자료실', '업무', '이야기', '관리자 페이지', '검색'], 'Q:T-40 admin %s tiles include 관리자 페이지 (can_admin) %s' % (path, json.dumps(names, ensure_ascii=False)))
                    p.keyboard.press('Escape')
                # 검색 타일 → 사이드바 「검색」과 같은 창 · 메신저 타일 → 떠 있는 창
                p.locator('details.appbar__apps > summary').click()
                p.locator('details.appbar__apps [data-search-tile]').click()
                p.wait_for_timeout(300)
                check(p.evaluate("document.getElementById('search-dialog').open") and not p.evaluate("document.querySelector('details.appbar__apps').open"), 'Q:T-40 search tile opens the same search dialog (grid closes)')
                p.keyboard.press('Escape')
                p.locator('.shell-tools #messenger-open').click()   # 카페 C6 (DESIGN 2.30) — 메신저는 앱 판 밖의 단추
                p.wait_for_timeout(600)
                check(p.evaluate("document.getElementById('messenger-window').open") and not p.evaluate("document.querySelector('details.appbar__apps').open"), 'Q:T-40 messenger button opens the floating messenger (grid stays closed)')
            guard('Q:T-40 admin tiles', admin_tiles)
            check(t.clean('t40-admin'), 'Q:T-40 admin no page errors / 5xx / script console output')
            ctx.close()

        # ─── Q:T-40 테마 여섯 × 밝게 · 어둡게 · 고대비(밝게 · 어둡게) — 앱 격자 타일 글자 대비 ≥ 4.5 (U2 · DESIGN 2.18 「대비 · 초점」) ─────────
        if want('contrast'):
            tok = new_session('employee')
            contrast = {}
            for themename in ('moa', 'claude', 'astra', 'aurora', 'bloom', 'nova'):
                for mode in ('light', 'dark', 'hc-light', 'hc-dark'):
                    sql("UPDATE users SET high_contrast=" + ('true' if mode.startswith('hc') else 'false') + " WHERE id=" + str(emp))
                    ctx = browser.new_context(viewport={'width': 1440, 'height': 900}, locale='ko-KR', user_agent=UA)
                    ctx.add_cookies(cookie(tok, {'qaredi_design': themename, 'tbox_theme': 'dark' if mode.endswith('dark') else 'light', 'qaredi_motion': 'reduce'}))
                    t = Tab(ctx)
                    p = t.page

                    def one_theme():
                        row = {}
                        for path in ('/community/', '/community/memories', '/tbox/'):
                            t.go(path)
                            p.locator('details.appbar__apps > summary').click()
                            p.mouse.move(5, 895)   # 올림(hover) 바탕이 타일에 남지 않게
                            p.wait_for_timeout(200)
                            st = p.evaluate(GRID_STATE_JS)
                            body_bg = parse_color(st['bodyBg'])
                            panel_bg = parse_color(st['panelBg'])
                            panel_eff = over(panel_bg, body_bg) if panel_bg and body_bg and panel_bg[3] < 1 else panel_bg
                            items = []
                            for it in st['tiles'] + ([{'name': '로그아웃', 'current': None, 'color': st['logout']['color'], 'bg': 'rgba(0, 0, 0, 0)'}] if st['logout'] else []):   # C6 — 로그아웃 줄은 내 계정 판
                                fg = parse_color(it['color'])
                                bg = parse_color(it['bg'])
                                eff = over(bg, panel_eff) if bg and panel_eff else None
                                if fg and fg[3] < 1 and eff:
                                    fg = over(fg, eff)
                                val = ratio(fg, eff) if fg and eff else None
                                items.append({'name': it['name'], 'current': it['current'] == 'page', 'fg': hexof(fg) if fg else it['color'], 'bg': hexof(eff) if eff else it['bg'], 'ratio': val})
                            row[path] = {'design': st['design'], 'theme': st['theme'], 'contrast': st['contrast'], 'panel_bg': hexof(panel_eff) if panel_eff else st['panelBg'], 'items': items}
                            tag = '%s %s %s' % (themename, mode, path)
                            check(st['design'] == themename and (st['contrast'] == 'high') == mode.startswith('hc') and (st['theme'] == 'dark') == mode.endswith('dark'), 'Q:T-40 contrast setup ' + tag + ' (data-design · data-theme · data-contrast as asked)')
                            worst = min([i['ratio'] or 0 for i in items])
                            check(worst >= 4.5, 'Q:T-40 contrast %s: grid tile text ≥ 4.5 (min %.2f · current %s · others %s)' % (
                                tag, worst, [i['ratio'] for i in items if i['current']], sorted({i['ratio'] for i in items if not i['current']})))
                            if path == '/community/' and mode in ('light', 'dark', 'hc-light') and themename in ('moa', 'claude'):
                                p.locator('details.appbar__apps .app-menu').screenshot(path=str(shots / ('grid-%s-%s.png' % (themename, mode))))
                            p.keyboard.press('Escape')
                        contrast['%s/%s' % (themename, mode)] = row
                    guard('Q:T-40 contrast %s %s' % (themename, mode), one_theme)
                    check(t.clean('contrast-%s-%s' % (themename, mode)), 'Q:T-40 contrast %s %s no page errors / 5xx / script console output' % (themename, mode))
                    ctx.close()
            sql("UPDATE users SET high_contrast=false WHERE id=" + str(emp))
            (out / 'qf12-shell-contrast.json').write_text(json.dumps(contrast, ensure_ascii=False, indent=1))
            ratios = [i['ratio'] for v in contrast.values() for r in v.values() for i in r['items'] if i['ratio'] is not None]
            metrics['contrast_min_text'] = min(ratios) if ratios else None
            metrics['contrast_min_current'] = min([i['ratio'] for v in contrast.values() for r in v.values() for i in r['items'] if i['current'] and i['ratio'] is not None] or [None])

        # ─── C-2 사이드바 — 업무 · 이야기 (1440 · 직원) ──────────────────────────────────────────────────
        if want('c2'):
            tok = new_session('employee')
            ctx = browser.new_context(viewport={'width': 1440, 'height': 900}, locale='ko-KR', user_agent=UA)
            ctx.add_cookies(cookie(tok, {'qaredi_motion': 'reduce'}))
            t = Tab(ctx)
            p = t.page

            def work_sidebar():
                t.go('/community/workspace')
                work = p.evaluate(SIDEBAR_JS)
                metrics['sidebar_work'] = work
                seq = [x['text'] for x in work if x['t'] != 'folded']
                top = seq[:6]
                check(top == ['새로 만들기', '홈', '내 작업', '받은 알림', '검색Ctrl K', '프로젝트'], 'C-2 업무 sidebar top: 새로 만들기 → 홈 → 내 작업 → 받은 알림 → 검색 → 프로젝트 ' + json.dumps(top, ensure_ascii=False))
                titles = [x['text'] for x in work if x['t'] == 'title']
                check(titles[-1:] == ['더보기'] and '문서' in titles and titles.index('문서') < titles.index('더보기'), 'C-2 업무 sidebar groups … 문서 → 더보기(last) ' + json.dumps(titles, ensure_ascii=False))
                check(all('오늘 할 일' not in x['text'] for x in work) and p.locator('#sidebar').get_by_text('오늘 할 일').count() == 0, 'C-2 업무 sidebar has no 「오늘 할 일」')
                folded = [x['text'] for x in work if x['t'] == 'folded']
                check(folded == ['자동 작업', '내 제안', '공유·연동 설정', '문서 AI', '내 휴지통'], 'C-2 「더보기」 folded: 자동 작업 · 내 제안 · 공유·연동 설정 · 문서 AI · 내 휴지통 ' + json.dumps(folded, ensure_ascii=False))
                trash = p.evaluate("(()=>{const a=document.getElementById('nav-community-trash');const box=a&&a.closest('details');return {inMore: !!box && box.id==='nav-community-more-box', open: box?box.open:null, last: a===[...a.closest('nav').querySelectorAll(':scope > .nav__item')].pop()};})()")
                check(trash['inMore'] and trash['open'] is False and trash['last'], 'C-2 「내 휴지통」 is the last item inside the folded 「더보기」 ' + json.dumps(trash))
                p.click('#nav-community-more-box > summary')
                p.wait_for_timeout(200)
                check(p.locator('#nav-community-trash').is_visible(), 'C-2 「더보기」 opens and shows 「내 휴지통」')
                check(p.locator('.shell-tools [data-messenger-open]').count() == 1 and p.locator('#sidebar [data-messenger-open]').count() == 0, 'C-2 업무 common grid: one messenger opener (tile), no sidebar duplicate')
                p.screenshot(path=str(shots / 'sidebar-work-1440.png'))
                # 「＋ 새로 만들기」 메뉴 — 작업 · 프로젝트 · 문서(작업·프로젝트는 조각 D 가 연다 — 기대값 바꿈 2026-10-07 D: 전엔 둘이 hidden) · 문서를 누르면 새 페이지
                p.click('#nav-new-menu > summary')
                p.wait_for_timeout(200)
                vis = p.evaluate("[...document.querySelectorAll('#nav-new-menu .menu__item')].map(b=>[b.id,!b.hidden&&b.getClientRects().length>0])")
                check(vis == [['nav-new-task', True], ['nav-new-project', True], ['nav-community-new', True]], 'C-2 새로 만들기 menu: 작업 · 프로젝트 · 문서 visible (D opened 작업 · 프로젝트) ' + json.dumps(vis))
                bg = p.evaluate("(()=>{const e=document.querySelector('#nav-new-menu > summary');const s=getComputedStyle(e);return [s.backgroundColor,s.color,Math.round(e.getBoundingClientRect().width)];})()")
                fgc, bgc = parse_color(bg[1]), parse_color(bg[0])
                check(fgc is not None and bgc is not None and ratio(fgc, bgc) >= 4.5 and bg[2] >= 200, 'C-2 새로 만들기 (open) stays a full-width black button, text contrast ≥ 4.5 ' + json.dumps(bg))
                lst = p.evaluate("(()=>{const l=document.querySelector('#nav-new-menu .menu__list').getBoundingClientRect();return {left:Math.round(l.left),right:Math.round(l.right),top:Math.round(l.top)};})()")
                check(lst['left'] >= 0 and lst['right'] <= 240, 'C-2 새로 만들기 menu list opens inside the sidebar column (U2 — rail gone, sidebar at 0) ' + json.dumps(lst))
                p.screenshot(path=str(shots / 'sidebar-work-new-menu-1440.png'))
                p.click('#nav-community-new')
                p.wait_for_url(re.compile(r'.*/community/p/[0-9a-f-]{36}.*'), timeout=15000)
                check('/community/p/' in p.url, 'C-2 새로 만들기 → 문서 creates a page and opens it (editor.js bindNewPage)')
            guard('C-2 업무 sidebar', work_sidebar)

            def story_sidebar():
                t.go('/community/memories')
                story = p.evaluate(SIDEBAR_JS)
                metrics['sidebar_story'] = story
                seq = [x['text'] for x in story if x['t'] != 'folded']
                check(seq == ['새 이야기', '팔로잉 스레드', '나의 스레드', '새로운 이야기 발견', '여행 계획', '검색Ctrl K'], 'C-2 이야기 sidebar: 새 이야기 → 팔로잉 → 나의 → 발견 → 여행 계획 → 검색 ' + json.dumps(seq, ensure_ascii=False))
                html = p.content()
                check('community/editor.js' not in html and 'id="nav-community-tree"' not in html and 'id="nav-community-bell"' not in html and 'id="appbar-bell"' not in html,
                      'C-2 이야기 shell: no editor.js · no 업무 groups · no bell')
                check(p.locator('.shell-tools [data-messenger-open]').count() == 1 and p.locator('#sidebar [data-messenger-open]').count() == 0, 'C-2 이야기 common grid: one messenger opener (tile), no sidebar duplicate')
                active = p.evaluate("[...document.querySelectorAll('#sidebar [data-thread-nav]')].map(a=>[a.dataset.threadNav,a.classList.contains('is-active')])")
                check(active == [['feed', True], ['mine', False], ['discover', False]], 'C-2 이야기 sidebar: 팔로잉 is-active on /community/memories (memories.js) ' + json.dumps(active))
                p.screenshot(path=str(shots / 'sidebar-story-1440.png'))
                p.click('#nav-story-new')
                p.wait_for_timeout(500)
                check(p.evaluate("document.getElementById('compose-dialog').open"), 'C-2 「＋ 새 이야기」 opens the thread compose dialog (window.MemoryComposer)')
                p.screenshot(path=str(shots / 'story-compose-1440.png'))
                p.keyboard.press('Escape')
                p.wait_for_timeout(300)
                if p.evaluate("document.getElementById('compose-dialog').open"):
                    p.locator('#compose-dialog [data-close="compose-dialog"]').click()
                    p.wait_for_timeout(300)
                p.click('#nav-story-travel')
                p.wait_for_load_state('networkidle')
                p.wait_for_timeout(600)
                check(p.evaluate("(()=>{const d=document.getElementById('travel-dialog');return !!d&&d.open;})()") and p.url.endswith('/community/memories?travel=1'), 'C-2 「여행 계획」 opens the travel dialog (travel-plans.js ?travel=1)')
                p.screenshot(path=str(shots / 'story-travel-1440.png'))
            guard('C-2 이야기 sidebar', story_sidebar)
            check(t.clean('c2'), 'C-2 no page errors / 5xx / script console output')
            ctx.close()

            # 폰 412 — 이야기 서랍의 「새 이야기」 는 서랍을 닫고 쓰기 창
            ctx = browser.new_context(viewport={'width': 412, 'height': 915}, locale='ko-KR', user_agent=UA, has_touch=True, is_mobile=True)
            ctx.add_cookies(cookie(tok, {'qaredi_motion': 'reduce'}))
            t = Tab(ctx)
            p = t.page

            def story_phone():
                t.go('/community/memories')
                p.click('#nav-toggle')
                p.wait_for_timeout(300)
                p.screenshot(path=str(shots / 'story-drawer-412.png'), full_page=True)
                p.click('#nav-story-new')
                p.wait_for_timeout(500)
                st = p.evaluate("({open: document.getElementById('compose-dialog').open, drawer: !document.getElementById('sidebar').hidden})")
                check(st['open'] and not st['drawer'], 'C-2 412: 「새 이야기」 closes the drawer and opens the compose dialog ' + json.dumps(st))
            guard('C-2 412 이야기 서랍', story_phone)
            check(t.clean('c2-412'), 'C-2 412 no page errors / 5xx / script console output')
            ctx.close()

        # ─── 화면 결함 감사 — scripts/ui_snapshot.py 를 이 인스턴스에서(1440 · 1280) ─────────────────────────
        if want('snap'):
            tok = new_session('admin')
            pages = '/tbox/,/tbox/settings,/tbox/activity,/tbox/trash,/community/workspace,/community/memories,/community/'
            for width in (1440, 1280):
                dest = out / ('snap-%d' % width)
                dest.mkdir(exist_ok=True)
                env = dict(os.environ, UI_PAGES=pages)
                launcher = "import runpy,sys,os\nfrom playwright.sync_api import BrowserType\n_l=BrowserType.launch\ndef launch(self,*a,**k):\n k.pop('channel',None);k['executable_path']=os.environ['CHROMIUM_EXECUTABLE'];return _l(self,*a,**k)\nBrowserType.launch=launch\nsys.argv=sys.argv[1:]\nrunpy.run_path(sys.argv[0],run_name='__main__')\n"
                r = subprocess.run([sys.executable, '-c', launcher, str(root / 'scripts/ui_snapshot.py'), site, UA, str(dest), str(width)],
                                   input='__Host-tbox_session=' + tok + '\n', capture_output=True, text=True, env=env, timeout=600)
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
                    rail_defects = [x for k, v in c.items() if isinstance(v, list) for x in v if any(w in x for w in ('app-rail', 'shell-tools', 'app-menu', 'appbar__apps', 'sidebar-collapse', 'nav-new', 'nav-story', 'nav-work', 'nav-community-more'))]
                    check(not rail_defects and not c.get('overflow_x') and not c.get('console_errors'), 'ui_snapshot %d %s: grid/new sidebar adds 0 defects · overflow 0 · console 0 %s' % (width, path, json.dumps(rail_defects, ensure_ascii=False)))
        browser.close()
finally:
    (out / 'qf12-shell-browser-metrics.json').write_text(json.dumps(metrics, ensure_ascii=False, indent=1))
    (out / 'qf12-shell-browser-results.json').write_text(json.dumps(checks, ensure_ascii=False, indent=2))
    print('RESULT pass=' + str(sum(x['pass'] for x in checks)) + ' fail=' + str(sum(not x['pass'] for x in checks)), flush=True)
if any(not x['pass'] for x in checks):
    raise SystemExit(1)
