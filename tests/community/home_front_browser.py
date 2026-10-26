#!/usr/bin/env python3
"""사이트 Q:F-15 S2 「앞세우기 · 접기」 수락 시험 (실제 PHP · PostgreSQL + Chromium · 격리 틀 memories_harness.py 만)

  python3 tests/community/memories_harness.py --port 8768 --suite home_front_browser.py --output <결과 폴더>

합성 DB · 임시 복사본 · 로컬 php -S 만 친다(운영 · tbox_re · 시드 계정 0). 계정은 fixture 의 claude_ 와 UA tbox-review/<날짜>
(가입 시험의 새 계정만 초대로 만든 합성 아이디 s2_joined01 — claude_ 접두는 가입에 쓸 수 없다).
결과 파일에는 단언 이름 · 참/거짓 · 측정값(폭 · 수 · 좌표 · 경로)만 — 세션 · CSRF · 응답 본문 0. 스크린샷은 결과 폴더 shots/.
지시서 docs/qf15-s2-front-directive.md 「수락 시험」 ①~⑯ · DESIGN 2.31 · 2.30 · 2.19 ⑧ ⑨ · 2.32 ⑤ · 5장.

  [static] ① 기본 바로가기 넷 · 설정 목록 값 집합 = ProductSpaces 허용 목록 ② Apps::all() 순서 · group · 임시 사본 group 'x' 예외(원본 무변경)
           ③ 새 마이그레이션 모양(BEGIN/COMMIT 한 묶음 · UPDATE 대상 하나 · DDL · 권한 · DELETE · TRUNCATE 0) ④ 바뀐 줄(기준 판 BASE_REF 대비)에 #색값 · innerHTML · style= · 역할 이름 0
           ⑯s dashboard-widgets.js 의 새 글자 · 옛 글자 0
  [db]     ⑤ 구성 셋(shortcuts 둘 · 없음 하나) → 마이그레이션 두 번 → shortcuts 0 · revision +1(두 번째 그대로) · 다른 칸 무변경
           ⑥ 그 계정 홈 = 기본 넷 + ＋ · 옛 revision PATCH → 409
  [home]   ⑦ 바로가기(구성 없음 = 기본 넷 · 저장한 구성 = 그대로) ⑧ 과업 카드 쿠키(첫 HTML open · 스크립트 끈 요청 · 다른 값 · 값 출력 0)
           ⑨ 줄(직원 · 관리자 · 손님 · 역할로 보기 · 메신저 창) ⑩ 첫 화면 · 짧은 화면 · 요청 0 ⑪ 대시보드(카드 둘 · 숨김 · 위젯 · 시작 안내 · 저장 뒤 공간 위젯 남음 · 기본 위젯)
  [grid]   1440 · 1280 · 412 · 360 × 밝게 · 어둡게(+ 820 밝게 · 고대비 1440 · 360 · 강제 색 1440) — 카드 접힘 · 펼침: 넘침 0 · 누름 자리 44 · 대비 4.5 · 2열/1열 · 콘솔 0
  [apps]   ⑫ 앱 판 순서 · 이름 없는 구분줄 하나 · aria-current (홈 · /tbox/ · /community/ · /community/cafe · /community/memories × 1440 · 412 + 관리자)
  [work]   ⑬ 「더보기」 펼쳐도 「문서 AI」 줄 안 보임 · /community/ai 상태 그대로
  [side]   ⑭ 사이드바 첫 방문 펼침 · ☰ 접기 → collapsed · 새로 고침 · 펼치기 → open · 옛 open · 959 서랍 · 960 이야기 통계 한 줄
  [join]   ⑮ 초대로 가입 → 주소 / (모아 홈 · 카드 접힘) · /api/register redirect '/'
  [words]  ⑯ 홈(대시보드 · 홈 편집 · 위젯 고르기) · 로그인 전 첫 화면에 「자료」 단독 0 · 새 글자 보임
"""
from pathlib import Path
exec(compile(Path(__file__).with_name('memories_http.py').read_text().split('try:\n for u in users:')[0], __file__, 'exec'))
import re, secrets, shutil, tempfile, html as htmllib
from urllib.parse import urlsplit
from playwright.sync_api import sync_playwright
assert str(root).startswith('/tmp/qaredi-stage13-') and manifest['database'].startswith('tbox_memories_test_')
WORKTREE = Path(__file__).resolve().parents[2]
# 이 단계가 갈라져 나온 main 판(지시서 · DESIGN 이 고쳐진 뒤 · 코드는 그대로) — ④ 「바뀐 줄」과 ⑬ 「고치기 전과 같다」의 기준
BASE_REF = '310bbc3'
host = urlsplit(site).hostname
UA = 'tbox-review/' + time.strftime('%Y-%m-%d')
ONLY = set(filter(None, os.environ.get('HOMEFRONT_ONLY', '').split(',')))
shots = out / 'shots'
shots.mkdir(exist_ok=True)
metrics = {}
DEFAULT = ['work', 'library', 'messages', 'spaces']
SLOGAN = '우리 회사의 일과 자료를 한곳에 모아.'
SPACE_ONLY = ['공간 자료', '연결된 자료가 없습니다.', '선택한 공간의 자료']   # 공간 위젯 전용 글자(E 로 화면에 없음 — 나와도 예외)
# Q:F-15 S3 (지시서 「기대값만 바꿀 옛 시험」) — 쓰기 줄 주소에 ?guide=<여정> · 공지 줄은 관리자만(S3-1 A) · 「동료에게 한마디」 는 업무 홈 경유(S3-2 A)
EMP_ROWS = [('업무 홈', '맡은 일 보기 · 할 일 적기', '/community/?guide=task-add', 'work'), ('자료실', '파일 올려서 동료에게 보여 주기', '/tbox/?guide=file-share', 'library'),
            ('업무 홈 › 문서', '회의록 쓰기', '/community/workspace?guide=minutes-write', 'work'),
            ('메신저', '동료에게 한마디', '/community/?guide=message-send', None)]
ADMIN_ROWS = EMP_ROWS[:3] + [('업무 홈 › 게시판', '공지 올리기', '/community/?guide=notice-post', 'work'), EMP_ROWS[3], ('계정 관리', '동료 초대하기', '/community/?guide=invite-colleague', None)]
GUEST_ROWS = [('업무 홈', '맡은 일 보기', '/community/', 'work'), ('자료실', '파일 찾기', '/tbox/', 'library')]
ACTING_ROWS = EMP_ROWS[:3]   # 관리자가 직원으로 보기 — 쓰기 · 올리기 자격은 직원 값 · 공지 0(직원) · 메신저 0(역할로 보기) · 계정 관리 0(직원)
GUEST_NOT = ['할 일 적기', '올려서', '회의록', '공지', '한마디', '초대']
# 첫 화면이 부르는 것(대시보드 접힘) — 계정 확인 · 홈 구성 · 메신저(안 읽은 수) · 사용 안내(home_h1 과 같은 목록 — 새 주소 0)
FIRST_OK_RE = re.compile(r'^(/tbox/api/me|/tbox/api/me/home-dashboard|/tbox/api/messages(/.*)?|/tbox/api/member(/.*)?|/tbox/api/me/usage-guide(/.*)?|/community/api/usage-guide(/.*)?)$')
APPS_EMP = ['모아 홈', '업무', '자료실', '우리 공간', '|', '이야기']


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


def new_session(key, act_role=None):
    token = secrets.token_hex(32)
    digest = hashlib.sha256(token.encode()).hexdigest()
    if act_role:
        sql("INSERT INTO sessions(id,user_id,expires_at,ip,user_agent,act_role,act_started_at,act_last_seen_at) VALUES('" + digest + "'," + str(uid(key))
            + ",clock_timestamp()+interval '8 hours','127.0.0.1','" + UA + "','" + act_role + "',now(),now())")
    else:
        sql("INSERT INTO sessions(id,user_id,expires_at,ip,user_agent) VALUES('" + digest + "'," + str(uid(key)) + ",clock_timestamp()+interval '8 hours','127.0.0.1','" + UA + "')")
    return token


def get_html(path, key=None, cookies=None, session=None, act_role=None):
    jar = []
    if key or session:
        jar.append('__Host-tbox_session=' + (session or new_session(key, act_role)))
    for name, value in (cookies or {}).items():
        jar.append(name + '=' + value)
    h = {'User-Agent': UA}
    if jar:
        h['Cookie'] = '; '.join(jar)
    return requests.get(site + path, headers=h, timeout=30, allow_redirects=False)


def tbox_api(method, path, key, body=None):
    h = {'User-Agent': UA, 'Cookie': '__Host-tbox_session=' + users[key]['session'], 'X-CSRF-Token': users[key]['csrf']}
    return requests.request(method, site + '/tbox' + path, json=body, headers=h, timeout=30)


def psql_file(path):
    env = {k: v for k, v in os.environ.items() if not k.startswith('PG')}
    env['PGPASSFILE'] = str(Path.home() / '.config/qaredi/pgpass-testdb')
    r = subprocess.run(['psql', '-h', '127.0.0.1', '-U', 'tbox_testdb', '-w', '-X', '-d', manifest['database'], '-v', 'ON_ERROR_STOP=1', '-f', str(path)],
                       env=env, capture_output=True, text=True, check=True)
    return r.stdout


def code_console(lines):
    return [t for t, _ in lines if not t.startswith('Failed to load resource') and not t.startswith('[DOM] ')]


def task_card(text):
    """서버 HTML 의 과업 카드 — (open 여부, summary HTML, 줄 [(가는 곳, 글자, href, data-service, 메신저 창)])"""
    m = re.search(r'<details id="home-tasks" class="home-tasks"( open)?>(.*?)</details>', text, re.S)
    if not m:
        return None
    summ = re.search(r'<summary>(.*?)</summary>', m.group(2), re.S)
    nav = re.search(r'<nav class="home-tasks__list" aria-label="하고 싶은 일">(.*?)</nav>', m.group(2), re.S)
    rows = []
    for a in re.finditer(r'<a([^>]*)><span>([^<]*)</span><strong>([^<]*) <span aria-hidden="true">↗</span></strong></a>', nav.group(1) if nav else ''):
        attrs = a.group(1)
        href = re.search(r' href="([^"]*)"', attrs)
        svc = re.search(r' data-service="([^"]*)"', attrs)
        rows.append((htmllib.unescape(a.group(2)), htmllib.unescape(a.group(3)), htmllib.unescape(href.group(1)) if href else None, svc.group(1) if svc else None,
                     'data-messenger-open aria-haspopup="dialog" aria-controls="messenger-window" aria-expanded="false"' in attrs))
    return {'open': m.group(1) is not None, 'summary': summ.group(1) if summ else '', 'rows': rows,
            'navCount': len(re.findall(r'<nav class="home-tasks__list"', m.group(2))), 'aCount': len(re.findall(r'<a[ >]', nav.group(1) if nav else ''))}


def bad_jaryo(text):
    t = text.replace(SLOGAN, '')
    for ex in SPACE_ONLY:
        t = t.replace(ex, '')
    return [t[max(0, m.start() - 14):m.end() + 6].replace('\n', ' ') for m in re.finditer(r'자료(?!실)', t)]


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
  const fg = over(parse(getComputedStyle(el).color), bg); const l1 = lum(fg), l2 = lum(bg);
  return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
}"""

# 과업 카드 · 바로가기 · 대시보드 단추 상태(보이는 것만 — 닫힌 details 안은 checkVisibility 가 거짓)
CARD_JS = r"""() => {
  const vis = el => { if (!el || !el.isConnected) return false; if (el.checkVisibility && !el.checkVisibility()) return false; const r = el.getBoundingClientRect(); return r.width > 0 && r.height > 0; };
  const box = el => { if (!el) return null; const r = el.getBoundingClientRect(); return {l: r.left, r: r.right, t: r.top, b: r.bottom, w: r.width, h: r.height}; };
  const card = document.getElementById('home-tasks'); const sum = card ? card.querySelector(':scope > summary') : null;
  const rows = card ? [...card.querySelectorAll('.home-tasks__list > a')] : [];
  const nav = document.getElementById('dashboard-services'); const items = nav ? [...nav.children].filter(vis) : [];
  const tops = []; for (const it of items) { const t = Math.round(it.getBoundingClientRect().top); if (!tops.includes(t)) tops.push(t); }
  const dsum = document.querySelector('details.home-dashboard > summary');
  const search = document.querySelector('form.home-search');
  const shown = sum ? [...sum.children].filter(vis).map(e => e.textContent.replace(/\s+/g, ' ').trim()) : [];
  return {
    exists: !!card, open: card ? card.open : null, count: document.querySelectorAll('#home-tasks').length, inHero: !!(card && card.closest('section.home-hero')),
    afterTemplate: !!(card && card.previousElementSibling && card.previousElementSibling.id === 'home-shortcut-template'),
    card: box(card), sum: box(sum), sumText: sum ? sum.innerText.replace(/\s+/g, ' ').trim() : null, sumParts: shown,
    marker: sum ? getComputedStyle(sum).display : null, listStyle: sum ? getComputedStyle(sum).listStyleType : null,
    titleFs: sum ? getComputedStyle(sum.children[0]).fontSize : null, titleFw: sum ? getComputedStyle(sum.children[0]).fontWeight : null,
    whenFs: sum ? [...sum.querySelectorAll('[data-when]')].filter(vis).map(e => getComputedStyle(e).fontSize) : [],
    rows: rows.map(a => { const s = a.querySelector(':scope > span'); const st = a.querySelector(':scope > strong'); const ar = st ? st.querySelector(':scope > span[aria-hidden="true"]') : null;
      return {place: s ? s.textContent : null, text: st ? st.firstChild.textContent.trim() : null, href: a.getAttribute('href'), svc: a.getAttribute('data-service'),
              arrow: ar ? ar.textContent : null, msg: a.hasAttribute('data-messenger-open'), vis: vis(a), box: box(a),
              spanFs: s ? getComputedStyle(s).fontSize : null, strongFs: st ? getComputedStyle(st).fontSize : null, strongFw: st ? getComputedStyle(st).fontWeight : null,
              strongColor: st ? getComputedStyle(st).color : null, bt: parseFloat(getComputedStyle(a).borderTopWidth) || 0}; }),
    navLabel: card && card.querySelector('.home-tasks__list') ? card.querySelector('.home-tasks__list').getAttribute('aria-label') : null,
    titleColor: sum ? getComputedStyle(sum.children[0]).color : null,
    shortcuts: items.map(it => it.dataset.homeShortcut || (it.hasAttribute('data-home-shortcut-add') ? '+' : '?')),
    shortcutRows: tops.map(t => items.filter(it => Math.round(it.getBoundingClientRect().top) === t).length), shortcutBottom: items.length ? Math.max(...items.map(i => i.getBoundingClientRect().bottom)) : null,
    search: box(search), dashSum: box(dsum), dashOpen: document.querySelector('details.home-dashboard') ? document.querySelector('details.home-dashboard').open : null,
    overflow: document.documentElement.scrollWidth - innerWidth, inner: innerHeight,
    border: card ? {style: getComputedStyle(card).borderTopStyle, width: getComputedStyle(card).borderTopWidth, color: getComputedStyle(card).borderTopColor} : null,
    inline: [...document.querySelectorAll('#home-tasks [style], #home-tasks')].filter(e => e.hasAttribute('style')).length
  }; }"""

FOOT_JS = r"""() => { const f = document.querySelector('body > footer.moa-footer'); if (!f) return null; const r = f.getBoundingClientRect(); const m = document.querySelector('body > main');
  return {bottom: r.bottom + scrollY, top: r.top + scrollY, scrollH: document.documentElement.scrollHeight, inner: innerHeight,
          mainBottom: m ? m.getBoundingClientRect().bottom + scrollY : null, overflow: document.documentElement.scrollWidth - innerWidth}; }"""

APPS_JS = r"""() => { const d = document.querySelector('details.appbar__apps'); const ul = d ? d.querySelector('.app-menu__apps') : null;
  const vis = el => !!el && el.getClientRects().length > 0 && getComputedStyle(el).display !== 'none';
  const lis = ul ? [...ul.children].filter(vis) : [];
  const sep = ul ? [...ul.querySelectorAll('li.app-menu__sep')] : [];
  const ur = ul ? ul.getBoundingClientRect() : null;
  return {open: d ? d.open : null, uls: d ? d.querySelectorAll('ul.app-menu__apps').length : 0,
          order: lis.map(li => li.classList.contains('app-menu__sep') ? '|' : (li.querySelector('.app-menu__name') || {}).textContent),
          current: [...(ul ? ul.querySelectorAll('.app-menu__app[aria-current="page"]') : [])].map(e => e.querySelector('.app-menu__name').textContent),
          sep: sep.map(s => { const r = s.getBoundingClientRect(); const c = getComputedStyle(s); return {role: s.getAttribute('role'), text: s.textContent, kids: s.children.length,
                attrs: [...s.attributes].map(a => a.name).sort(), h: r.height, w: r.width, ulW: ur ? ur.width : null, l: r.left, ulL: ur ? ur.left : null, bg: c.backgroundColor,
                mt: c.marginTop, mb: c.marginBottom, focusable: s.tabIndex >= 0 || !!s.querySelector('a,button,[tabindex]')}; }),
          tileAttrs: lis.filter(li => !li.classList.contains('app-menu__sep')).map(li => li.attributes.length),
          lineColor: getComputedStyle(document.documentElement).getPropertyValue('--line').trim(), overflow: document.documentElement.scrollWidth - innerWidth}; }"""


try:
    for u in users:
        users[u]['username'] = sql('SELECT username FROM users WHERE id=' + str(users[u]['id']))
        call('GET', '/api/me/space', user=u)
    emp, adm = uid('employee'), uid('admin')
    # 사용 안내가 화면을 덮지 않게(이 DB 의 모든 사람 — 이미 닫음)
    sql("INSERT INTO community.usage_guides(user_id,version,status,context) SELECT id,1,'dismissed',c FROM users, unnest(ARRAY['overview','threads']) c ON CONFLICT DO NOTHING")
    # 공간 하나 — 직원(구성 없음 · ⑪ 기본 위젯) · 둘째 직원(공간 위젯 저장 · ⑪) 이 든다
    SPACE = sql("INSERT INTO product_spaces(name,kind,created_by) VALUES('S2 합성 공간','company'," + str(emp) + ") RETURNING id").splitlines()[0]
    sql("INSERT INTO product_space_members(space_id,user_id,role) VALUES(" + lit(SPACE) + "," + str(emp) + ",'owner'),(" + lit(SPACE) + "," + str(uid('other')) + ",'member')")
    BASE_LAYOUT = json.loads(subprocess.run(['php', '-r', 'require "src/site/ProductSpaces.php"; echo json_encode(ProductSpaces::defaultWidgetLayout());'],
                                            cwd=root, check=True, capture_output=True, text=True).stdout)

    # ─── [static] ①~④ · ⑯s ────────────────────────────────────────────────────────────────
    if want('static'):
        def static():
            summary = {}
            hd = (root / 'public/assets/home-dashboard.js').read_text()
            d = re.search(r"shortcutDefaults=\[([^\]]*)\]", hd)
            i = re.search(r"shortcutIds=\[([^\]]*)\]", hd)
            ps = (root / 'src/site/ProductSpaces.php').read_text()
            a = re.search(r"\$allowed=\[([^\]]*)\]", ps)
            defaults = re.findall(r"'([a-z_]+)'", d.group(1)) if d else None
            ids = re.findall(r"'([a-z_]+)'", i.group(1)) if i else []
            allowed = re.findall(r"'([a-z_]+)'", a.group(1)) if a else []
            summary['shortcuts'] = {'defaults': defaults, 'ids': ids, 'allowed': allowed}
            check(defaults == DEFAULT, '① home-dashboard.js shortcutDefaults = %s' % defaults)
            check(len(ids) == len(set(ids)) and set(ids) == set(allowed) and len(allowed) == 8, '① shortcutIds value set = ProductSpaces $allowed (order only changed) %s' % ids)
            check(ids[:4] == DEFAULT, '① settings list starts with the default four %s' % ids[:4])
            # ② Apps::all()
            prog = "require 'src/Apps.php'; echo json_encode(array_map(fn($a)=>[$a['key'],$a['name'],$a['group']??null],Apps::all()), JSON_UNESCAPED_UNICODE);"
            r = subprocess.run(['php', '-r', prog], cwd=root, capture_output=True, text=True)
            apps = json.loads(r.stdout) if r.returncode == 0 else []
            summary['apps'] = apps
            check(r.returncode == 0 and [x[0] for x in apps] == ['community', 'tbox', 'spaces', 'story'], '② Apps::all() key order community · tbox · spaces · story %s' % [x[0] for x in apps])
            check([x[1] for x in apps] == ['업무', '자료실', '우리 공간', '이야기'] and [x[0] for x in apps if x[2] == 'more'] == ['story'] and all(x[2] in (None, 'more') for x in apps),
                  '② names 업무 · 자료실 · 우리 공간 · 이야기 · only story has group more')
            before = {p: hashlib.sha256((base_dir / 'config/apps.json').read_bytes()).hexdigest() for p, base_dir in (('inst', root), ('tree', WORKTREE))}
            tmp = Path(tempfile.mkdtemp(prefix='qf15s2-apps-', dir=str(root.parent)))
            try:
                (tmp / 'src').mkdir()
                (tmp / 'config').mkdir()
                shutil.copy2(root / 'src/Apps.php', tmp / 'src/Apps.php')
                data = json.loads((root / 'config/apps.json').read_text())
                for row in data:
                    if row['key'] == 'story':
                        row['group'] = 'x'
                (tmp / 'config/apps.json').write_text(json.dumps(data, ensure_ascii=False))
                bad = subprocess.run(['php', '-r', "require 'src/Apps.php'; try { Apps::all(); echo 'NO_EXCEPTION'; } catch (RuntimeException $e) { echo 'EXC:' . $e->getMessage(); }"],
                                     cwd=tmp, capture_output=True, text=True)
                summary['group_x'] = bad.stdout[:200]
                check(bad.stdout.startswith('EXC:') and 'group' in bad.stdout, "② temp copy with group 'x' → exception (%s)" % bad.stdout[:80])
            finally:
                shutil.rmtree(tmp)
            after = {p: hashlib.sha256((base_dir / 'config/apps.json').read_bytes()).hexdigest() for p, base_dir in (('inst', root), ('tree', WORKTREE))}
            check(before == after, '② original config/apps.json unchanged (instance and worktree sha256)')
            # ③ 마이그레이션 모양
            mig = (root / 'db/migrations/2026-10-26_qf15_s2_shortcuts.sql').read_text()
            code = '\n'.join(line.split('--', 1)[0] for line in mig.splitlines()).strip()
            stmts = [s.strip() for s in code.split(';') if s.strip()]
            upper = [re.sub(r'\s+', ' ', s).upper() for s in stmts]
            summary['migration'] = upper
            check(upper[:1] == ['BEGIN'] and upper[-1:] == ['COMMIT'] and upper.count('BEGIN') == 1 and upper.count('COMMIT') == 1 and "SET LOCAL LOCK_TIMEOUT = '3S'" in upper,
                  '③ migration: one BEGIN … COMMIT block with SET LOCAL lock_timeout 3s')
            targets = re.findall(r'\bUPDATE\s+([A-Za-z_.]+)', code, re.I)
            check(targets == ['public.product_personal_dashboards'], '③ migration: the only UPDATE target is public.product_personal_dashboards %s' % targets)
            ddl = re.findall(r'\b(CREATE|ALTER|DROP|GRANT|REVOKE|DELETE|TRUNCATE|INSERT|COPY|OWNER|COMMENT)\b', code, re.I)
            check(ddl == [] and 'product_space_dashboards' not in code, '③ migration: DDL · GRANT · REVOKE · DELETE · TRUNCATE · INSERT 0 · other tables 0 %s' % ddl)
            check(re.search(r"layout\s*=\s*layout\s*-\s*'shortcuts'", code) and re.search(r'revision\s*=\s*revision\s*\+\s*1', code) and re.search(r"WHERE\s+layout\s*\?\s*'shortcuts'", code),
                  "③ migration: layout - 'shortcuts' · revision + 1 · only rows with layout ? 'shortcuts'")
            harness = (root / 'tests/community/memories_harness.py').read_text()
            lst = re.search(r"migrations \+= \[(.*?)\]\n", harness, re.S)
            names = re.findall(r"'([0-9a-z_.-]+\.sql)'", lst.group(1)) if lst else []
            check(names[-1:] == ['2026-10-26_qf15_s2_shortcuts.sql'], '③ harness: the new migration is last in the twice-applied list')
            # ④ 바뀐 줄 — 기준 판 대비 더해진 줄만
            def added(files):
                diff = subprocess.run(['git', '-C', str(WORKTREE), 'diff', BASE_REF, '--'] + files, capture_output=True, text=True, check=True).stdout
                return [ln[1:] for ln in diff.splitlines() if ln.startswith('+') and not ln.startswith('+++')]
            css = added(['public/assets/moa.css', 'public/assets/app.css'])
            code_lines = added(['src/views/launcher.php', 'src/views/shell_tools.php', 'src/views/shell_top.php', 'src/Apps.php', 'src/site/ProductSpaces.php',
                                'src/handlers/member_register.php', 'public/assets/home-dashboard.js', 'public/assets/dashboard-widgets.js', 'public/assets/member.js', 'public/assets/app.js'])
            hexes = [ln.strip()[:80] for ln in css if re.search(r'#(?:[0-9a-fA-F]{8}|[0-9a-fA-F]{6}|[0-9a-fA-F]{3,4})\b', ln) or re.search(r'\b(?:rgba?|hsla?|hwb|lab|lch|oklab|oklch)\(', ln)]
            summary['added'] = {'css': len(css), 'code': len(code_lines)}
            check(len(css) > 0 and hexes == [], '④ changed CSS lines (%d): new colour values 0 %s' % (len(css), hexes))
            risky = [ln.strip()[:80] for ln in code_lines if re.search(r'innerHTML|outerHTML|insertAdjacentHTML|style=|\.style\b|setAttribute\(\s*[\'"]style', ln)]
            roles = [ln.strip()[:80] for ln in code_lines if re.search(r'[\'"](Admin|Manager|Employee|Guest)[\'"]', ln)]
            check(len(code_lines) > 0 and risky == [], '④ changed PHP/JS lines (%d): innerHTML · style= · .style 0 %s' % (len(code_lines), risky))
            check(roles == [], '④ changed PHP/JS lines: role-name strings (Admin · Manager · Employee · Guest) 0 %s' % roles)
            cookie_reads = [ln.strip()[:120] for ln in code_lines if '$_COOKIE' in ln and not ln.lstrip().startswith(('*', '//', '/*'))]
            check(cookie_reads == ["$homeTasksOpen=isset($_COOKIE['qaredi_home_tasks'])&&$_COOKIE['qaredi_home_tasks']==='open';",
                                   "$shellSidebarOpen = !(isset($_COOKIE['qaredi_sidebar']) && $_COOKIE['qaredi_sidebar'] === 'collapsed');"],
                  '④ request values: only the two exact cookie comparisons (qaredi_home_tasks === open · qaredi_sidebar === collapsed) %s' % cookie_reads)
            # ⑯s 위젯 글자(I 표 · Q5) — 화면에 잘 나오지 않는 자리(오류 · 민감 표시)는 파일로
            dw = (root / 'public/assets/dashboard-widgets.js').read_text()
            new = ["recent:'최근 파일 · 문서'", "'모든 출처를 불러오지 못했습니다.'", "'민감 파일'", "'최근 30일 동안 연 파일 · 문서가 없습니다.'", "'파일이나 문서를 즐겨찾기에 담아 보세요.'",
                   "'최근 사용한 파일과 문서 · 소 최대 2개, 중 최대 4개, 대 최대 8개'", "'즐겨찾기에 담은 파일과 문서 · 소 최대 2개, 중 최대 4개, 대 최대 8개'"]
            old = ['최근 자료', '모든 자료 출처', '민감 자료', '연 자료가', '자료나 문서', '사용한 자료와', '담은 자료와']
            check(all(n in dw for n in new) and not any(o in dw for o in old), '⑯s dashboard-widgets.js: new words present · old 「자료」 words 0 %s' % [o for o in old if o in dw])
            check("'최근 작업에 보일 것'" in hd and "selectField(source,'출처'" in hd and hd.count("'파일과 문서'") == 2 and '자료와 문서' not in hd and '자료 출처' not in hd,
                  '⑯s home-dashboard.js pickers: 최근 작업에 보일 것 · 출처 · 파일과 문서')
            metrics['static'] = summary
        guard('static', static)

    # ─── [db] ⑤ ⑥ — 마이그레이션 두 번 ────────────────────────────────────────────────────────
    if want('db'):
        def db():
            summary = {}
            rows = {'manager': (dict(BASE_LAYOUT, shortcuts=['assistant', 'story'], guide='later'), 4),
                    'guest': (dict(BASE_LAYOUT, shortcuts=['spaces', 'work', 'library'], source='files', view='favorites'), 2),
                    'admin': (dict(BASE_LAYOUT, guide='done'), 3)}
            for key, (layout, rev) in rows.items():
                sql('INSERT INTO product_personal_dashboards(user_id,layout,revision) VALUES(' + str(uid(key)) + ',CAST(' + lit(json.dumps(layout, ensure_ascii=False)) + ' AS jsonb),' + str(rev)
                    + ') ON CONFLICT(user_id) DO UPDATE SET layout=EXCLUDED.layout,revision=EXCLUDED.revision')
            before = {k: (json.loads(sql('SELECT layout::text FROM product_personal_dashboards WHERE user_id=' + str(uid(k)))),
                          int(sql('SELECT revision FROM product_personal_dashboards WHERE user_id=' + str(uid(k))))) for k in rows}
            with_sc = int(sql("SELECT count(*) FROM product_personal_dashboards WHERE layout ? 'shortcuts'"))
            space_dash = sql("SELECT count(*) || ':' || coalesce(max(revision),0) FROM product_space_dashboards")
            first = psql_file(root / 'db/migrations/2026-10-26_qf15_s2_shortcuts.sql')
            mid = {k: (json.loads(sql('SELECT layout::text FROM product_personal_dashboards WHERE user_id=' + str(uid(k)))),
                       int(sql('SELECT revision FROM product_personal_dashboards WHERE user_id=' + str(uid(k))))) for k in rows}
            second = psql_file(root / 'db/migrations/2026-10-26_qf15_s2_shortcuts.sql')
            after = {k: (json.loads(sql('SELECT layout::text FROM product_personal_dashboards WHERE user_id=' + str(uid(k)))),
                         int(sql('SELECT revision FROM product_personal_dashboards WHERE user_id=' + str(uid(k))))) for k in rows}
            n1 = re.findall(r'^UPDATE (\d+)$', first, re.M)
            n2 = re.findall(r'^UPDATE (\d+)$', second, re.M)
            summary.update(first=n1, second=n2, with_shortcuts_before=with_sc, revisions={k: [before[k][1], mid[k][1], after[k][1]] for k in rows})
            check(with_sc == 2 and n1 == ['2'] and n2 == ['0'], '⑤ migration twice: first UPDATE %s (rows with shortcuts %d) · second UPDATE %s' % (n1, with_sc, n2))
            check(sql("SELECT count(*) FROM product_personal_dashboards WHERE layout ? 'shortcuts'") == '0', '⑤ rows with shortcuts after: 0')
            check(mid['manager'][1] == before['manager'][1] + 1 and mid['guest'][1] == before['guest'][1] + 1 and after['manager'][1] == mid['manager'][1] and after['guest'][1] == mid['guest'][1],
                  '⑤ the two rows: revision exactly +1 · unchanged by the second run %s' % summary['revisions'])
            check(after['admin'][1] == before['admin'][1] and after['admin'][0] == before['admin'][0], '⑤ the row without shortcuts: layout and revision unchanged')
            same = all({kk: vv for kk, vv in before[k][0].items() if kk != 'shortcuts'} == after[k][0] for k in rows)
            keys = all(before[k][0][kk] == after[k][0][kk] for k in rows for kk in ('widgets', 'source', 'view', 'guide', 'version'))
            check(same and keys and all('shortcuts' not in after[k][0] for k in rows), '⑤ widgets · source · view · guide unchanged (jsonb minus shortcuts equal)')
            check(sql("SELECT count(*) || ':' || coalesce(max(revision),0) FROM product_space_dashboards") == space_dash, '⑤ product_space_dashboards untouched')
            metrics['db'] = summary
            # ⑥ 그 계정 홈 · 옛 revision PATCH → 409
            r = tbox_api('PATCH', '/api/me/home-dashboard', 'manager', {'layout': before['manager'][0], 'revision': before['manager'][1]})
            code = None
            try:
                code = r.json().get('code') or r.json().get('error')
            except ValueError:
                pass
            check(r.status_code == 409 and 'revision_conflict' in r.text, '⑥ PATCH with the pre-migration layout · old revision → 409 revision_conflict (%d %s)' % (r.status_code, str(code)[:40]))
            check(sql("SELECT (layout ? 'shortcuts')::text || ':' || revision FROM product_personal_dashboards WHERE user_id=" + str(uid('manager'))) == 'false:' + str(before['manager'][1] + 1),
                  '⑥ the stale save changed nothing (shortcuts still absent · revision as after the migration)')
        guard('db', db)

    with sync_playwright() as pw:
        browser = pw.chromium.launch(executable_path=os.environ.get('CHROMIUM_EXECUTABLE'))

        def context(key, width, theme='light', height=None, touch=None, session=None, act_role=None, extra=None, js=True, forced=False):
            touch = (width < 720) if touch is None else touch
            opts = dict(viewport={'width': width, 'height': height or 900}, has_touch=touch, is_mobile=touch, user_agent=UA, locale='ko-KR',
                        reduced_motion='reduce', color_scheme='dark' if theme == 'dark' else 'light', java_script_enabled=js)
            if forced:
                opts['forced_colors'] = 'active'
            ctx = browser.new_context(**opts)
            jar = [{'name': 'qaredi_motion', 'value': 'reduce', 'domain': host, 'path': '/'}, {'name': 'tbox_theme', 'value': 'dark' if theme == 'dark' else 'light', 'domain': host, 'path': '/'}]
            if key or session:
                jar.append({'name': '__Host-tbox_session', 'value': session or new_session(key, act_role), 'domain': host, 'path': '/', 'secure': True, 'httpOnly': True, 'sameSite': 'Lax'})
            for name, value in (extra or {}).items():
                jar.append({'name': name, 'value': value, 'domain': host, 'path': '/'})
            ctx.add_cookies(jar)
            return ctx

        def home(t):
            resp = t.page.goto(site + '/', wait_until='networkidle')
            t.page.wait_for_selector('#dashboard-services [data-home-shortcut-add]', timeout=15000)
            t.page.wait_for_timeout(250)
            return resp

        def contrast(p, sel):
            return [round(hh.evaluate(CONTRAST_JS), 2) for hh in p.query_selector_all(sel) if hh.is_visible()]

        def cookie(ctx, name):
            return next((c for c in ctx.cookies() if c['name'] == name), None)

        def open_dash(p):
            p.locator('details.home-dashboard > summary').click()
            p.wait_for_selector('#home-widgets > :not([hidden])', timeout=15000)
            p.wait_for_load_state('networkidle')
            p.wait_for_timeout(600)

        # ─── [db] ⑥ 화면 — 마이그레이션 뒤 그 계정의 홈은 기본 넷 ─────────────────────────────────
        if want('db'):
            def db_home():
                for key in ('manager', 'guest'):
                    ctx = context(key, 1440)
                    t = Tab(ctx)
                    home(t)
                    st = t.page.evaluate(CARD_JS)
                    check(st['shortcuts'] == DEFAULT + ['+'], '⑥ %s (shortcuts removed by the migration): home shortcuts 업무 홈 · 자료실 · 메신저 · 우리 공간 · ＋ %s' % (key, st['shortcuts']))
                    check(t.clean('db-home-' + key), '⑥ %s console 0' % key)
                    ctx.close()
            guard('db-home', db_home)

        # ─── [home] ⑦ 바로가기 ─────────────────────────────────────────────────────────────────
        if want('home'):
            def shortcuts():
                summary = {}
                check(sql('SELECT count(*) FROM product_personal_dashboards WHERE user_id=' + str(emp)) == '0', '⑦ fixture: the employee has no saved layout')
                for width, rows in ((1440, [5]), (412, [4, 1])):
                    ctx = context('employee', width)
                    t = Tab(ctx)
                    home(t)
                    st = t.page.evaluate(CARD_JS)
                    summary[str(width)] = {'ids': st['shortcuts'], 'rows': st['shortcutRows']}
                    check(st['shortcuts'] == DEFAULT + ['+'] and st['shortcutRows'] == rows, '⑦ %d no saved layout: 업무 홈 · 자료실 · 메신저 · 우리 공간 · ＋ rows %s (want %s)' % (width, st['shortcutRows'], rows))
                    check(t.clean('short-%d' % width), '⑦ %d console 0' % width)
                    ctx.close()
                # 저장한 구성(반영 뒤 홈 편집에서 저장한 바로가기) — 그대로. ⑪ 이 같은 계정을 쓴다(서비스 카드 · 최근 작업 · 공간 위젯)
                layout = {'version': 3, 'source': 'all', 'view': 'recent', 'guide': 'pending', 'shortcuts': ['story', 'messages'], 'widgets': [
                    {'id': 's2-services', 'type': 'legacy_services', 'source': {}, 'visible': True, 'locked': False,
                     'desktop': {'x': 0, 'y': 0, 'w': 1200, 'h': 640, 'size': 'l'}, 'mobile': {'x': 0, 'y': 0, 'w': 360, 'h': 900, 'size': 'l'}},
                    {'id': 's2-recent', 'type': 'legacy_recent', 'source': {}, 'visible': True, 'locked': False,
                     'desktop': {'x': 0, 'y': 664, 'w': 1200, 'h': 420, 'size': 'm'}, 'mobile': {'x': 0, 'y': 916, 'w': 360, 'h': 420, 'size': 'm'}},
                    {'id': 's2-files', 'type': 'recent', 'source': {'kind': 'all'}, 'visible': True, 'locked': False,
                     'desktop': {'x': 0, 'y': 1108, 'w': 480, 'h': 420, 'size': 'm'}, 'mobile': {'x': 0, 'y': 1352, 'w': 360, 'h': 420, 'size': 'm'}},
                    {'id': 's2-space', 'type': 'space_notice', 'source': {'space_id': SPACE, 'scope': 'all'}, 'visible': True, 'locked': False,
                     'desktop': {'x': 504, 'y': 1108, 'w': 480, 'h': 420, 'size': 'm'}, 'mobile': {'x': 0, 'y': 1788, 'w': 360, 'h': 420, 'size': 'm'}}]}
                r = tbox_api('PATCH', '/api/me/home-dashboard', 'other', {'layout': layout, 'revision': 0})
                check(r.status_code == 200, '⑦ fixture: second employee saves shortcuts story · messages + services · recent · space widgets (%d)' % r.status_code)
                ctx = context('other', 1440)
                t = Tab(ctx)
                home(t)
                st = t.page.evaluate(CARD_JS)
                summary['saved'] = st['shortcuts']
                check(st['shortcuts'] == ['story', 'messages', '+'], '⑦ saved shortcuts kept as saved %s' % st['shortcuts'])
                check(t.clean('short-saved'), '⑦ saved console 0')
                ctx.close()
                metrics['shortcuts'] = summary
            guard('shortcuts', shortcuts)

            # ─── ⑧ 과업 카드 쿠키 ───────────────────────────────────────────────────────────────
            def card_cookie():
                summary = {}
                r = get_html('/', 'employee')
                c = task_card(r.text)
                summary['first'] = {'status': r.status_code, 'open': c and c['open']}
                check(r.status_code == 200 and c is not None and not c['open'], '⑧ no cookie: response HTML has <details id="home-tasks"> without open')
                check(c and '<span>무엇을 하고 싶으세요?</span>' in c['summary'] and '<span data-when="closed">펼치기 <span aria-hidden="true">▾</span></span>' in c['summary']
                      and '<span data-when="open">접기 <span aria-hidden="true">▴</span></span>' in c['summary'], '⑧ summary 「무엇을 하고 싶으세요?」 · 「펼치기 ▾」 / 「접기 ▴」 (data-when)')
                check(r.text.count('id="home-tasks"') == 1 and re.findall(r'\?guide=([a-z-]+)', r.text) == ['task-add', 'file-share', 'minutes-write', 'message-send'], '⑧ one #home-tasks · ?guide= only on the write rows (Q:F-15 S3)')
                ctx = context('employee', 1440)
                t = Tab(ctx)
                p = t.page
                home(t)
                st = p.evaluate(CARD_JS)
                summary['closed'] = {k: st[k] for k in ('open', 'sumText', 'sumParts', 'card', 'sum', 'marker', 'listStyle', 'titleFs', 'titleFw', 'whenFs')}
                check(st['exists'] and st['inHero'] and st['afterTemplate'] and st['open'] is False and st['sumParts'] == ['무엇을 하고 싶으세요?', '펼치기 ▾'],
                      '⑧ browser: card in section.home-hero right after the shortcut template · collapsed · 「무엇을 하고 싶으세요?」 「펼치기 ▾」 %s' % st['sumParts'])
                check(st['sum']['h'] >= 52 and st['titleFs'] == '16px' and st['titleFw'] == '600' and st['whenFs'] == ['13px'] and st['marker'] == 'flex' and st['listStyle'] == 'none',
                      '⑧ summary ≥ 52 (%.1f) · title 16/600 · 13px state text · no browser triangle' % st['sum']['h'])
                n0 = len(t.reqs)
                p.locator('#home-tasks > summary').click()
                p.wait_for_timeout(300)
                n1 = len(t.reqs)
                ck = cookie(ctx, 'qaredi_home_tasks')
                st = p.evaluate(CARD_JS)
                summary['opened'] = {'cookie': ck and {k: ck[k] for k in ('value', 'path', 'sameSite', 'httpOnly')}, 'sumParts': st['sumParts'], 'requests': n1 - n0}
                check(st['open'] and st['sumParts'] == ['무엇을 하고 싶으세요?', '접기 ▴'], '⑧ click → open · 「접기 ▴」')
                check(ck is not None and ck['value'] == 'open' and ck['path'] == '/' and ck['sameSite'] == 'Lax' and not ck['httpOnly'] and ck['expires'] > time.time() + 300 * 86400,
                      '⑧ cookie qaredi_home_tasks=open · Path=/ · SameSite=Lax · 1 year · not HttpOnly %s' % summary['opened']['cookie'])
                check(n1 == n0, '⑩ opening the card sends no request (%d)' % (n1 - n0))
                resp = p.reload(wait_until='networkidle')
                p.wait_for_selector('#dashboard-services [data-home-shortcut-add]', timeout=15000)
                rc = task_card(resp.text())
                check(rc is not None and rc['open'] and p.evaluate("document.getElementById('home-tasks').open"), '⑧ reload: response HTML has <details … open> (first HTML) · open in the page')
                n2 = len(t.reqs)
                p.locator('#home-tasks > summary').click()
                p.wait_for_timeout(300)
                n3 = len(t.reqs)
                check(cookie(ctx, 'qaredi_home_tasks') is None and not p.evaluate("document.getElementById('home-tasks').open"), '⑧ close → the cookie is gone (Max-Age=0)')
                check(n3 == n2, '⑩ closing the card sends no request (%d)' % (n3 - n2))
                resp = p.reload(wait_until='networkidle')
                rc = task_card(resp.text())
                check(rc is not None and not rc['open'], '⑧ reload after closing: response HTML without open')
                check(t.clean('card-cookie'), '⑧ console 0')
                ctx.close()
                # 스크립트 끈 요청 — requests(스크립트 0) + 스크립트를 끈 브라우저
                r = get_html('/', 'employee', cookies={'qaredi_home_tasks': 'open'})
                c = task_card(r.text)
                check(c is not None and c['open'], '⑧ plain request (no script) with qaredi_home_tasks=open → <details … open>')
                ctx = context('employee', 1440, js=False, extra={'qaredi_home_tasks': 'open'})
                p = ctx.new_page()
                p.goto(site + '/', wait_until='load')
                a = p.locator('#home-tasks').get_attribute('open')
                p.locator('#home-tasks > summary').click()
                b = p.locator('#home-tasks').get_attribute('open')
                p.locator('#home-tasks > summary').click()
                c2 = p.locator('#home-tasks').get_attribute('open')
                summary['nojs'] = [a, b, c2]
                check(a is not None and b is None and c2 is not None, '⑧ JavaScript disabled: starts open from the cookie · the <details> still closes and opens (only memory is lost)')
                ctx.close()
                # 다른 값 — open 0 · 응답에 그 값 0
                probe = {}
                for value in ('<script>qf15s2probe</script>', 'Open', 'open1', '1', 'true', 'qf15s2probe'):
                    r = get_html('/', 'employee', cookies={'qaredi_home_tasks': value})
                    c = task_card(r.text)
                    probe[value] = {'open': c and c['open'], 'echo': ('qf15s2probe' in value and 'qf15s2probe' in r.text) or ('qf15s2probe' not in value and False)}
                check(all(v['open'] is False for v in probe.values()), '⑧ cookie values other than exactly open (<script> · Open · open1 · 1 · true) → no open %s' % {k: v['open'] for k, v in probe.items()})
                check(not any(v['echo'] for v in probe.values()), '⑧ the cookie value is never written into the response')
                metrics['card_cookie'] = summary
            guard('card_cookie', card_cookie)

            # ─── ⑨ 줄 — 판정 함수 결과 ───────────────────────────────────────────────────────────
            def card_rows():
                summary = {}
                acting = new_session('admin', act_role='Employee')
                cases = (('employee', 'employee', None, EMP_ROWS), ('admin', 'admin', None, ADMIN_ROWS), ('guest', 'guest', None, GUEST_ROWS), ('acting', None, acting, ACTING_ROWS))
                for tag, key, session, want_rows in cases:
                    r = get_html('/', key, session=session)
                    c = task_card(r.text)
                    rows = [x[:4] for x in (c['rows'] if c else [])]
                    summary['html-' + tag] = rows
                    check(c is not None and rows == want_rows and c['aCount'] == len(want_rows) and c['navCount'] == 1,
                          '⑨ %s: server HTML rows = %d (place · text · href · data-service in order) %s' % (tag, len(want_rows), json.dumps(rows, ensure_ascii=False)))
                    msg_rows = [x[0] for x in (c['rows'] if c else []) if x[4]]
                    check(msg_rows == [], '⑨ %s: no task row carries the messenger-window opener attributes (Q:F-15 S3-2 A — the 「메신저」 shortcut still does) %s' % (tag, msg_rows))
                    if tag == 'guest':
                        m = re.search(r'<details id="home-tasks".*?</details>', r.text, re.S)
                        txt = htmllib.unescape(re.sub(r'<[^>]+>', ' ', m.group(0))) if m else ''
                        hits = [w for w in GUEST_NOT if w in txt]
                        check(hits == [], '⑨ guest card text: 할 일 적기 · 올려서 · 회의록 · 공지 · 한마디 · 초대 0 %s' % hits)
                    ctx = context(key, 1440, session=session, extra={'qaredi_home_tasks': 'open'})
                    t = Tab(ctx)
                    p = t.page
                    home(t)
                    st = p.evaluate(CARD_JS)
                    dom = [(x['place'], x['text'], x['href'], x['svc']) for x in st['rows'] if x['vis']]
                    summary['dom-' + tag] = dom
                    check(st['open'] and dom == want_rows and all(x['arrow'] == '↗' for x in st['rows']) and st['navLabel'] == '하고 싶은 일',
                          '⑨ %s: page rows in order · 「↗」 aria-hidden on each · nav aria-label 「하고 싶은 일」' % tag)
                    if tag == 'employee':
                        # Q:F-15 S3-2 A — 「동료에게 한마디」 는 업무 홈으로 가서 길잡이가 시작한다(주소 꼬리는 길잡이가 지움 · 그 화면에 진행 카드)
                        p.locator('#home-tasks a[href$="?guide=message-send"]').click()
                        p.wait_for_selector('section.usage-guide-float:not([hidden])', timeout=10000)
                        m = p.evaluate("({url: location.pathname, search: location.search, card: !!document.querySelector('section.usage-guide-float:not([hidden])')})")
                        check(m['url'] == '/community/' and m['search'] == '' and m['card'], '⑨ employee: 「동료에게 한마디」 → /community/ (tail removed by the guide) · progress card %s' % json.dumps(m))
                        sql('DELETE FROM community.guide_journeys WHERE user_id=' + str(uid('employee')))   # 뒤 단언의 홈 줄에 「하던 일 이어 하기」 가 끼지 않게
                    if tag == 'guest':
                        p.screenshot(path=str(shots / 'home-guest-1440-open.png'))
                    if tag == 'admin':
                        p.screenshot(path=str(shots / 'home-admin-1440-open.png'))
                    check(st['overflow'] <= 0 and t.clean('rows-' + tag), '⑨ %s: overflow 0 · console 0' % tag)
                    ctx.close()
                metrics['card_rows'] = summary
            guard('card_rows', card_rows)

            # ─── ⑩ 첫 화면 · 짧은 화면 · 첫 로드 요청 ────────────────────────────────────────────
            def first_screen():
                summary = {}
                ctx = context('employee', 1440, height=900)
                t = Tab(ctx)
                p = t.page
                home(t)
                p.wait_for_timeout(500)
                st = p.evaluate(CARD_JS)
                api = sorted(set(a for a in t.api() if '/api/' in a))
                odd = [a for a in api if not FIRST_OK_RE.match(a)]
                news = [path for _, path in t.reqs if re.search(r'product-spaces|product-home|home-tasks|qaredi_home_tasks', path)]
                summary['1440x900'] = {'dashSum': st['dashSum'], 'card': st['card'], 'api': api}
                check(st['open'] is False and st['dashOpen'] is False and st['dashSum'] and st['dashSum']['b'] <= 900, '⑩ 1440×900 light · card collapsed: 「내 대시보드 ▾」 bottom %.1f ≤ 900' % (st['dashSum']['b'] if st['dashSum'] else -1))
                check(odd == [] and news == [], '⑩ first load (dashboard collapsed): API calls only account · layout · messenger · usage guide — new addresses 0 %s %s' % (odd, news))
                gap = st['card']['t'] - st['shortcutBottom']
                check(abs(gap - 24) <= 1 and abs(st['card']['w'] - st['search']['w']) <= 1 and abs(st['card']['l'] - st['search']['l']) <= 1,
                      '⑩ card 24 below the shortcuts (%.1f) · same width and left as the search box (%.1f / %.1f)' % (gap, st['card']['w'], st['search']['w']))
                p.screenshot(path=str(shots / 'home-1440-light-collapsed.png'))
                check(t.clean('first-1440'), '⑩ console 0')
                ctx.close()
                for (w, h) in ((1440, 900), (1920, 1080), (412, 900)):
                    ctx = context('employee', w, height=h)
                    t = Tab(ctx)
                    p = t.page
                    home(t)
                    f = p.evaluate(FOOT_JS)
                    summary['foot-%dx%d' % (w, h)] = f
                    short = f and f['scrollH'] <= f['inner'] + 1
                    check(short and abs(f['bottom'] - f['inner']) <= 1 and f['overflow'] <= 0, '⑩ %d×%d card collapsed: short page — footer at the window bottom (%s / %s · page %s)' % (w, h, f and round(f['bottom']), f and f['inner'], f and f['scrollH']))
                    check(t.clean('foot-%dx%d' % (w, h)), '⑩ %d×%d console 0' % (w, h))
                    ctx.close()
                metrics['first_screen'] = summary
            guard('first_screen', first_screen)

            # ─── ⑪ 대시보드 펼침 ────────────────────────────────────────────────────────────────
            def dashboard():
                summary = {}
                ctx = context('other', 1440)
                t = Tab(ctx)
                p = t.page
                home(t)
                open_dash(p)
                st = p.evaluate("""() => { const vis = el => !!el && el.isConnected && (!el.checkVisibility || el.checkVisibility()) && el.getBoundingClientRect().width > 0;
                    const cards = [...document.querySelectorAll('#services .moa-service-card')]; const shown = cards.filter(vis); const nav = document.querySelector('#services .moa-services');
                    const nr = nav ? nav.getBoundingClientRect() : null;
                    return {all: cards.length, shown: shown.map(c => c.querySelector('.moa-service-label').textContent), hidden: cards.filter(c => c.hidden).map(c => c.dataset.service),
                            h3: shown.map(c => c.querySelector('h3').textContent), boxes: shown.map(c => { const r = c.getBoundingClientRect(); return [Math.round(r.left - nr.left), Math.round(nr.right - r.right), Math.round(r.top), Math.round(r.width)]; }),
                            navW: nr ? Math.round(nr.width) : null, productHome: vis(document.getElementById('product-home')), productHidden: document.getElementById('product-home').hidden,
                            spaceWidgets: [...document.querySelectorAll('#home-widgets [data-widget-type^="space_"]')].filter(vis).length,
                            titles: [...document.querySelectorAll('#home-widgets .dashboard-widget-head h2')].filter(vis).map(h => h.textContent),
                            overflow: document.documentElement.scrollWidth - innerWidth}; }""")
                summary['other'] = st
                check(st['all'] == 4 and st['shown'] == ['업무 홈', '자료실'] and sorted(st['hidden']) == ['assistant', 'story'] and st['h3'][1:] == ['팀 파일을 함께 써요'],
                      '⑪ service cards visible = 업무 홈 · 자료실 (PC 비서 · 이야기 hidden, still in the HTML) · 「팀 파일을 함께 써요」 %s' % json.dumps(st['shown'], ensure_ascii=False))
                b = st['boxes']
                check(len(b) == 2 and b[0][2] == b[1][2] and b[0][0] <= 1 and b[1][1] <= 1 and abs(b[0][3] - b[1][3]) <= 1, '⑪ the two cards fill the widget in two columns %s (nav %s)' % (b, st['navW']))
                check(not st['productHome'] and st['productHidden'] and st['spaceWidgets'] == 0, '⑪ #product-home hidden · space_* widgets not drawn')
                check('최근 파일 · 문서' in st['titles'], '⑪ widget name 「최근 파일 · 문서」 %s' % json.dumps(st['titles'], ensure_ascii=False))
                p.evaluate("document.querySelector('.dashboard-guide').open = true")
                p.wait_for_timeout(150)
                g = p.evaluate("""() => { const d = document.querySelector('.dashboard-guide'); return {summary: d.querySelector('summary').textContent, ps: [...d.querySelectorAll('p')].map(x => x.textContent),
                    links: [...d.querySelectorAll('a')].map(a => [a.textContent, a.getAttribute('href')]), buttons: [...d.querySelectorAll('button')].map(b => b.textContent)}; }""")
                summary['guide'] = g
                check(g['summary'] == '모아 홈 시작 안내' and all('공간' not in x for x in g['ps']) and '첫 파일을 올리고, 필요한 위젯을 골라 보세요.' in g['ps'],
                      '⑪ start guide: sentences without 「공간」 · 「첫 파일을 올리고, 필요한 위젯을 골라 보세요.」 %s' % json.dumps(g['ps'], ensure_ascii=False))
                check(g['links'] == [['우리 공간', '/community/cafe'], ['첫 파일 올리기', '/tbox/']] and g['buttons'] == ['나중에', '안내 완료'],
                      '⑪ guide links 「우리 공간」 → /community/cafe · 「첫 파일 올리기」 → /tbox/ · 나중에 · 안내 완료 %s' % json.dumps(g['links'], ensure_ascii=False))
                # 홈 편집 → 위젯 추가 목록 → 저장 → 서버 구성에 공간 위젯 그대로
                p.locator('#home-edit').click()
                p.wait_for_selector('.dashboard-editing', timeout=10000)
                p.get_by_role('button', name='새 위젯 추가', exact=True).click()
                p.wait_for_selector('dialog.dashboard-widget-picker[open]', timeout=10000)
                opts = p.evaluate("[...document.querySelectorAll('dialog.dashboard-widget-picker[open] select')[0].options].map(o => [o.value, o.textContent])")
                summary['chooser'] = opts
                check(opts and not any(v.startswith('space_') for v, _ in opts) and ['recent', '최근 파일 · 문서'] in opts, '⑪ widget picker: space_* types 0 %s' % [v for v, _ in opts])
                p.locator('dialog.dashboard-widget-picker[open]').get_by_role('button', name='취소', exact=True).click()
                p.wait_for_timeout(200)
                p.locator('button.dashboard-save').click()
                p.wait_for_function("() => !document.querySelector('.dashboard-editing')", timeout=15000)
                p.wait_for_timeout(300)
                saved = tbox_api('GET', '/api/me/home-dashboard', 'other').json()
                ws = {w['id']: w for w in saved['layout']['widgets']}
                summary['after_save'] = {'ids': list(ws), 'revision': saved['revision'], 'shortcuts': saved['layout'].get('shortcuts')}
                check(saved['revision'] == 2 and 's2-space' in ws and ws['s2-space']['type'] == 'space_notice' and ws['s2-space']['source'] == {'space_id': SPACE, 'scope': 'all'}
                      and saved['layout'].get('shortcuts') == ['story', 'messages'],
                      '⑪ 홈 편집 → 저장: GET /tbox/api/me/home-dashboard still has the space widget (same id · source) · shortcuts kept · revision 2 %s' % list(ws))
                st2 = p.evaluate("[...document.querySelectorAll('#home-widgets [data-widget-type^=\"space_\"]')].filter(e => e.getClientRects().length).length")
                check(st2 == 0, '⑪ after saving the space widget is still not drawn')
                api = [path for _, path in t.reqs]
                check(not any('/api/product-spaces' in a for a in api) and not any(a.endswith('/product-home.js') for a in api),
                      '⑪ requests: /api/product-spaces 0 · product-home.js 0 (whole session)')
                check(st['overflow'] <= 0 and t.clean('dash-other'), '⑪ overflow 0 · console 0')
                ctx.close()
                # 공간 하나에 든 새 계정(구성 없음) — 기본 위젯 셋
                d = tbox_api('GET', '/api/me/home-dashboard', 'employee').json()
                types = [w['type'] for w in d['layout']['widgets']]
                check(d['revision'] == 0 and types == ['recent', 'favorites', 'briefing'] and sql('SELECT count(*) FROM product_space_members WHERE active AND user_id=' + str(emp)) == '1',
                      '⑪ member of one space · no saved layout: default widgets recent · favorites · briefing %s' % types)
                ctx = context('employee', 1440)
                t = Tab(ctx)
                p = t.page
                home(t)
                open_dash(p)
                dt = p.evaluate("[...document.querySelectorAll('#home-widgets [data-widget-type]')].filter(e => e.getClientRects().length).map(e => e.dataset.widgetType)")
                check(dt == ['recent', 'favorites', 'briefing'], '⑪ the page draws recent · favorites · briefing %s' % dt)
                p.screenshot(path=str(shots / 'home-1440-light-dashboard-open.png'), full_page=True)
                check(not any('/api/product-spaces' in a for _, a in t.reqs) and t.clean('dash-emp'), '⑪ employee: /api/product-spaces 0 · console 0')
                ctx.close()
                metrics['dashboard'] = summary
            guard('dashboard', dashboard)

        # ─── [grid] 넘침 · 누름 자리 · 대비 · 2열/1열 ──────────────────────────────────────────────
        if want('grid'):
            def grid():
                summary = {}
                runs = [(w, m) for m in ('light', 'dark') for w in (1440, 1280, 412, 360)] + [(820, 'light'), (1440, 'hc'), (360, 'hc')]   # 820 — DESIGN 6장 체크리스트 폭
                for width, mode in runs:
                    tag = '%d-%s' % (width, mode)
                    sql('UPDATE users SET high_contrast=' + ('true' if mode == 'hc' else 'false') + ' WHERE id=' + str(emp))
                    ctx = context('employee', width, 'dark' if mode == 'dark' else 'light')
                    t = Tab(ctx)
                    p = t.page
                    home(t)
                    closed = p.evaluate(CARD_JS)
                    cr0 = contrast(p, '#home-tasks > summary > span:first-child, #home-tasks > summary > [data-when="closed"]')
                    p.locator('#home-tasks > summary').click()
                    p.wait_for_timeout(200)
                    st = p.evaluate(CARD_JS)
                    rows = [x for x in st['rows'] if x['vis']]
                    small = [(x['text'], round(x['box']['w']), round(x['box']['h'])) for x in rows if x['box']['h'] < 44 or x['box']['w'] < 44]
                    cr = contrast(p, '#home-tasks > summary > span:first-child, #home-tasks > summary > [data-when="open"], #home-tasks .home-tasks__list > a > span, #home-tasks .home-tasks__list > a > strong')
                    lefts = sorted(set(round(x['box']['l']) for x in rows))
                    tops = [round(x['box']['t']) for x in rows]
                    two = width >= 720
                    cols_ok = (len(lefts) == 2 and tops[0] == tops[1] and abs((lefts[1] - lefts[0]) - (rows[0]['box']['w'] + 28)) <= 1.5) if two else (len(lefts) == 1 and len(set(tops)) == len(tops))
                    summary[tag] = {'closedOverflow': closed['overflow'], 'overflow': st['overflow'], 'small': small, 'contrast': min(cr0 + cr) if cr0 + cr else None,
                                    'cols': len(lefts), 'card': st['card'], 'sumH': st['sum']['h']}
                    check(closed['overflow'] <= 0 and st['overflow'] <= 0, '[grid] %s: horizontal overflow 0 (card collapsed · open)' % tag)
                    check(closed['sum']['h'] >= 52 and small == [] and len(rows) == len(EMP_ROWS), '[grid] %s: summary ≥ 52 · four rows (Q:F-15 S3-1 A) · each row ≥ 44 %s' % (tag, small))
                    check(cols_ok, '[grid] %s: %s %s' % (tag, 'two columns (gap 28)' if two else 'one column', lefts))
                    # 2026-10-26 독립 검토 — 줄 사이 구분선: 첫 줄(맨 위 한 줄)만 위 선 0, 그 아래 줄은 모두 위 선 1(폰 1열에서 셋째 줄부터 선이 빠지던 결함)
                    lines_ok = all((x['bt'] >= 1) == (round(x['box']['t']) > min(tops)) for x in rows)
                    check(lines_ok, '[grid] %s: row dividers — every row below the first has a top line %s' % (tag, [(x['text'], x['bt']) for x in rows]))
                    check(cr0 and cr and min(cr0 + cr) >= 4.5, '[grid] %s: contrast ≥ 4.5 (card head · state · row place · row text) min %.2f' % (tag, min(cr0 + cr) if cr0 + cr else -1))
                    check(st['inline'] == 0, '[grid] %s: inline style 0 in the card' % tag)
                    if mode == 'hc':
                        hc = [x['strongColor'] == st['titleColor'] for x in rows if x['svc']]
                        check(hc and all(hc), '[grid] %s: high contrast — [data-service] rows take --fg (same as the card head colour)' % tag)
                    if (width, mode) == (412, 'dark'):
                        p.screenshot(path=str(shots / 'home-412-dark-open.png'), full_page=True)
                    if (width, mode) == (1440, 'light'):
                        p.screenshot(path=str(shots / 'home-1440-light-open.png'))
                    check(t.clean('grid-' + tag), '[grid] %s: console 0 · page errors 0 · 5xx 0' % tag)
                    ctx.close()
                sql('UPDATE users SET high_contrast=false WHERE id=' + str(emp))
                # 강제 색(Windows 고대비) — 카드 테두리가 보인다
                ctx = context('employee', 1440, forced=True, extra={'qaredi_home_tasks': 'open'})
                t = Tab(ctx)
                p = t.page
                home(t)
                b = p.evaluate(CARD_JS)['border']
                summary['forced'] = b
                check(b and b['style'] == 'solid' and b['width'] == '1px' and b['color'] not in ('rgba(0, 0, 0, 0)', 'transparent'), '[grid] forced colors: the card border is visible %s' % b)
                check(t.clean('grid-forced'), '[grid] forced colors console 0')
                ctx.close()
                metrics['grid'] = summary
            guard('grid', grid)

        # ─── [apps] ⑫ 앱 판 ───────────────────────────────────────────────────────────────────
        if want('apps'):
            def apps():
                summary = {}
                for path in ('/', '/tbox/', '/community/'):
                    r = get_html(path, 'employee')
                    m = re.search(r'<details class="appbar__apps" id="app-grid">(.*?)</details>', r.text, re.S)
                    block = m.group(1) if m else ''
                    lis = re.findall(r'<li(\s[^>]*)?>', block)
                    check(block.count('<ul class="app-menu__apps">') == 1 and block.count('<li class="app-menu__sep" role="separator"></li>') == 1 and [x for x in lis if x] == [' class="app-menu__sep" role="separator"'],
                          '⑫ server HTML %s: one ul.app-menu__apps · one <li class="app-menu__sep" role="separator"></li> · tile <li> without attributes' % path)
                current = {'/': '모아 홈', '/tbox/': '자료실', '/community/': '업무', '/community/cafe': '우리 공간', '/community/memories': '이야기'}
                for width in (1440, 412):
                    for path, name in current.items():
                        tag = '%d %s' % (width, path)
                        ctx = context('employee', width)
                        t = Tab(ctx)
                        p = t.page
                        p.goto(site + path, wait_until='networkidle')
                        p.wait_for_timeout(300)
                        if p.locator('#appbar-apps').count() and p.locator('#appbar-apps').is_visible():
                            p.locator('#appbar-apps').click()
                        else:
                            p.locator('.shell-tools > .appbar__apps > summary').click()
                        p.wait_for_timeout(200)
                        st = p.evaluate(APPS_JS)
                        summary[tag] = {k: st[k] for k in ('order', 'current', 'sep', 'tileAttrs')}
                        s = st['sep'][0] if len(st['sep']) == 1 else {}
                        check(st['open'] and st['uls'] == 1 and st['order'] == APPS_EMP, '⑫ %s: tiles 모아 홈 · 업무 · 자료실 · 우리 공간 · ── · 이야기 %s' % (tag, json.dumps(st['order'], ensure_ascii=False)))
                        check(len(st['sep']) == 1 and s.get('role') == 'separator' and s.get('text') == '' and s.get('kids') == 0 and s.get('attrs') == ['class', 'role'] and not s.get('focusable')
                              and abs(s.get('h', 0) - 1) <= 0.5 and abs(s.get('w', 0) - s.get('ulW', -9)) <= 1 and abs(s.get('l', 0) - s.get('ulL', -9)) <= 1 and s.get('mt') == '6px' and s.get('mb') == '6px'
                              and s.get('bg') not in ('rgba(0, 0, 0, 0)', 'transparent'),
                              '⑫ %s: one li.app-menu__sep[role=separator] · no text · full grid row · height 1 · margin 6 · coloured (--line) %s' % (tag, json.dumps({k: s.get(k) for k in ('h', 'w', 'ulW', 'bg')})))
                        check(st['current'] == [name] and all(n == 0 for n in st['tileAttrs']), '⑫ %s: aria-current only 「%s」 · tile li attributes 0' % (tag, name))
                        check(st['overflow'] <= 0 and t.clean('apps-' + tag.replace(' ', '').replace('/', '_')), '⑫ %s: overflow 0 · console 0' % tag)
                        ctx.close()
                # 관리자 — 관리자 페이지는 구분줄 아래 이야기 다음
                ctx = context('admin', 1440)
                t = Tab(ctx)
                p = t.page
                home(t)
                p.wait_for_timeout(300)
                p.locator('.shell-tools > .appbar__apps > summary').click()
                p.wait_for_timeout(200)
                st = p.evaluate(APPS_JS)
                summary['admin'] = st['order']
                check(st['order'] == APPS_EMP + ['관리자 페이지'], '⑫ admin: … · ── · 이야기 · 관리자 페이지 %s' % json.dumps(st['order'], ensure_ascii=False))
                p.screenshot(path=str(shots / 'apps-1440-admin.png'))
                check(t.clean('apps-admin'), '⑫ admin console 0')
                ctx.close()
                metrics['apps'] = summary
            guard('apps', apps)

        # ─── [work] ⑬ 「문서 AI」 줄 숨김 ────────────────────────────────────────────────────────
        if want('work'):
            def work():
                summary = {}
                for key in ('employee', 'admin'):
                    ctx = context(key, 1440)
                    t = Tab(ctx)
                    p = t.page
                    p.goto(site + '/community/', wait_until='networkidle')
                    p.wait_for_selector('#nav-community-more-box > summary', timeout=15000)
                    p.locator('#nav-community-more-box > summary').click()
                    p.wait_for_timeout(200)
                    st = p.evaluate("""() => { const box = document.getElementById('nav-community-more-box'); const vis = el => !!el && el.getClientRects().length > 0 && getComputedStyle(el).display !== 'none';
                        const ai = box.querySelector('a.nav__item[href$="/ai"]');
                        return {open: box.open, shown: [...box.querySelectorAll('a.nav__item')].filter(vis).map(a => a.textContent.trim()), ai: !!ai, aiHidden: ai ? ai.hidden : null, aiVisible: vis(ai),
                                text: box.innerText}; }""")
                    summary[key] = st
                    check(st['open'] and st['ai'] and st['aiHidden'] and not st['aiVisible'] and '문서 AI' not in st['text'] and st['shown'] == ['자동 작업', '내 제안', '공유·연동 설정', '내 휴지통'],
                          '⑬ %s: 「더보기」 open — 「문서 AI」 row stays in the HTML with hidden · not visible · others 자동 작업 · 내 제안 · 공유·연동 설정 · 내 휴지통 %s' % (key, json.dumps(st['shown'], ensure_ascii=False)))
                    check(t.clean('work-' + key), '⑬ %s console 0' % key)
                    ctx.close()
                    r = get_html('/community/ai', key)
                    summary['ai-' + key] = r.status_code
                    check(r.status_code == 200, '⑬ %s: GET /community/ai still answers 200' % key)
                same = subprocess.run(['git', '-C', str(WORKTREE), 'diff', '--quiet', BASE_REF, '--', 'public/index.php', 'src/community/routes.php', 'src/community/handlers/ai_page.php'])
                check(same.returncode == 0, '⑬ route table · community routes · ai_page.php unchanged since %s (the /community/ai answer is the same as before)' % BASE_REF)
                metrics['work'] = summary
            guard('work', work)

        # ─── [side] ⑭ 사이드바 첫 방문 펼침 ─────────────────────────────────────────────────────
        if want('side'):
            def side():
                summary = {}
                SIDE_JS = "() => ({attr: document.body.getAttribute('data-sidebar'), w: Math.round(document.getElementById('sidebar').getBoundingClientRect().width), exp: document.getElementById('sidebar-collapse').getAttribute('aria-expanded'), label: document.getElementById('sidebar-collapse').getAttribute('aria-label'), overflow: document.documentElement.scrollWidth - innerWidth})"
                for name, path in (('업무', '/community/'), ('자료실', '/tbox/'), ('이야기', '/community/memories'), ('카페', '/community/cafe')):
                    r = get_html(path, 'employee')
                    body = re.search(r'<body[^>]*>', r.text)
                    check(r.status_code == 200 and body and 'data-sidebar=' not in body.group(0) and 'aria-expanded="true" aria-label="메뉴 접기"' in r.text,
                          '⑭ %s: no cookie → response HTML <body> without data-sidebar · ☰ aria-expanded true 「메뉴 접기」' % name)
                    ctx = context('employee', 1280 if name == '업무' else 1440)
                    t = Tab(ctx)
                    p = t.page
                    p.goto(site + path, wait_until='networkidle')
                    p.wait_for_timeout(300)
                    a = p.evaluate(SIDE_JS)
                    if name == '업무':
                        p.screenshot(path=str(shots / 'work-first-visit-1280.png'))
                    p.locator('#sidebar-collapse').click()
                    p.wait_for_timeout(300)
                    b = p.evaluate(SIDE_JS)
                    c1 = cookie(ctx, 'qaredi_sidebar')
                    resp = p.reload(wait_until='networkidle')
                    p.wait_for_timeout(300)
                    rbody = re.search(r'<body[^>]*>', resp.text())
                    c = p.evaluate(SIDE_JS)
                    p.locator('#sidebar-collapse').click()
                    p.wait_for_timeout(300)
                    d = p.evaluate(SIDE_JS)
                    c2 = cookie(ctx, 'qaredi_sidebar')
                    summary[name] = {'first': a, 'collapsed': b, 'cookie1': c1 and c1['value'], 'reload': c, 'expanded': d, 'cookie2': c2 and c2['value']}
                    check(a['attr'] is None and a['w'] == 240 and a['exp'] == 'true', '⑭ %s: first visit expanded · width 240 %s' % (name, json.dumps(a)))
                    check(b['attr'] == 'collapsed' and b['w'] == 64 and c1 and c1['value'] == 'collapsed' and c1['path'] == '/' and c1['sameSite'] == 'Lax' and c1['expires'] > time.time() + 300 * 86400,
                          '⑭ %s: ☰ → collapsed (64) · cookie qaredi_sidebar=collapsed · Path=/ · Lax · 1 year' % name)
                    check(rbody and 'data-sidebar="collapsed"' in rbody.group(0) and c['attr'] == 'collapsed' and c['w'] == 64, '⑭ %s: reload → response HTML <body data-sidebar="collapsed">' % name)
                    check(d['attr'] is None and d['w'] == 240 and c2 and c2['value'] == 'open', '⑭ %s: ☰ → expanded · cookie open (kept, not deleted)' % name)
                    check(max(a['overflow'], b['overflow'], d['overflow']) <= 0 and t.clean('side-' + name), '⑭ %s: overflow 0 · console 0' % name)
                    ctx.close()
                # 옛 쿠키 open → 펼침 · 다른 값 → 펼침
                for value in ('open', 'x'):
                    r = get_html('/community/', 'employee', cookies={'qaredi_sidebar': value})
                    body = re.search(r'<body[^>]*>', r.text)
                    check(body and 'data-sidebar=' not in body.group(0), '⑭ cookie qaredi_sidebar=%s → expanded (no data-sidebar)' % value)
                # 959 이하 — 서랍 그대로(쿠키 collapsed 여도 CSS 가 무시)
                ctx = context('employee', 959, touch=False, extra={'qaredi_sidebar': 'collapsed'})
                t = Tab(ctx)
                p = t.page
                p.goto(site + '/community/', wait_until='networkidle')
                p.wait_for_timeout(300)
                st = p.evaluate("() => ({collapse: !!document.getElementById('sidebar-collapse').getClientRects().length, toggle: !!document.getElementById('nav-toggle').getClientRects().length, sidebar: document.getElementById('sidebar').getBoundingClientRect().right})")
                p.locator('#nav-toggle').click()
                p.wait_for_timeout(400)
                st2 = p.evaluate("() => ({w: Math.round(document.getElementById('sidebar').getBoundingClientRect().width), right: Math.round(document.getElementById('sidebar').getBoundingClientRect().right), overflow: document.documentElement.scrollWidth - innerWidth})")
                summary['959'] = [st, st2]
                check(not st['collapse'] and st['toggle'] and st['sidebar'] <= 0 and st2['w'] > 64 and st2['right'] > 64 and st2['overflow'] <= 0,
                      '⑭ 959: ☰ collapse hidden · drawer button shown · drawer opens full (%d) even with the collapsed cookie' % st2['w'])
                check(t.clean('side-959'), '⑭ 959 console 0')
                ctx.close()
                # 960 — 펼친 240 사이드바에서 이야기 「내 기록」 한 줄 통계(story_s1 [grid] 의 제약)
                ctx = context('employee', 960, touch=False)
                t = Tab(ctx)
                p = t.page
                p.goto(site + '/community/memories?view=mine', wait_until='networkidle')
                p.wait_for_timeout(800)
                st = p.evaluate("() => ({attr: document.body.getAttribute('data-sidebar'), w: Math.round(document.getElementById('sidebar').getBoundingClientRect().width), stats: [...document.querySelectorAll('#profile-stats > *')].filter(e => e.getClientRects().length).map(e => Math.round(e.getBoundingClientRect().top)), overflow: document.documentElement.scrollWidth - innerWidth})")
                summary['960-story'] = st
                check(st['attr'] is None and st['w'] == 240 and len(st['stats']) >= 3 and len(set(st['stats'])) == 1 and st['overflow'] <= 0,
                      '⑭ 960 story 「내 기록」 with the expanded 240 sidebar: stats on one line · overflow 0 %s' % json.dumps(st))
                check(t.clean('side-960'), '⑭ 960 console 0')
                ctx.close()
                metrics['side'] = summary
            guard('side', side)

        # ─── [join] ⑮ 가입 직후 모아 홈 ─────────────────────────────────────────────────────────
        if want('join'):
            def join():
                summary = {}
                code = secrets.token_hex(32)
                sql("INSERT INTO member_invites(code_hash,label,team_id,created_by,expires_at,max_uses) VALUES(" + lit(hashlib.sha256(code.encode()).hexdigest())
                    + ",'S2 합성 초대',NULL," + str(adm) + ",now()+interval '1 day',1)")
                ctx = context(None, 1280)
                t = Tab(ctx)
                p = t.page
                p.goto(site + '/tbox/join#invite=' + code, wait_until='networkidle')
                p.wait_for_timeout(300)
                p.fill('#join-name', 'S2 합성 가입자')
                p.fill('#join-username', 's2_joined01')
                p.fill('#join-password', 'S2-front-synthetic-only-2026!')
                p.fill('#join-confirm', 'S2-front-synthetic-only-2026!')
                p.check('#join-terms')
                p.check('#join-age14')
                with p.expect_response(lambda r: urlsplit(r.url).path == '/tbox/api/register', timeout=20000) as info:
                    p.locator('#join-form [type=submit]').click()
                resp = info.value
                p.wait_for_url(lambda u: urlsplit(u).path == '/', timeout=20000)
                p.wait_for_selector('#home-tasks', timeout=15000)
                p.wait_for_load_state('networkidle')
                p.wait_for_timeout(400)
                st = p.evaluate("() => ({path: location.pathname, signed: document.body.dataset.signed, open: document.getElementById('home-tasks').open, hero: !!document.querySelector('section.home-hero')})")
                summary.update(status=resp.status, page=st)
                check(resp.status == 201 and st['path'] == '/' and st['signed'] == 'yes' and st['hero'] and st['open'] is False,
                      '⑮ join in the browser: /api/register 201 → lands on 「/」 — 모아 홈 with the card collapsed %s' % json.dumps(st))
                check(sql("SELECT role || ':' || must_change_password || ':' || mfa_setup_required FROM users WHERE username='s2_joined01'") == 'Employee:false:false',
                      '⑮ the new account: Employee · no forced step (2-step not required in this setup)')
                p.screenshot(path=str(shots / 'join-after-1280.png'))
                # 가입 화면의 아이디 칸 pattern(member_join_page.php — 이 단계 밖 · 손대지 않음)은 글자를 넣으면 Chromium 이 v 깃발로 읽어 경고 한 줄을 낸다(원래 있던 것).
                # 그 한 줄만 따로 세어 결과에 남기고(보고서) 나머지 콘솔은 0 이어야 한다
                known = [c for c, _ in t.console if c.startswith('Pattern attribute value [a-z0-9_.-]{3,64} is not a valid regular expression')]
                t.console = [c for c in t.console if not c[0].startswith('Pattern attribute value [a-z0-9_.-]{3,64} is not a valid regular expression')]
                summary['known_console_outside_stage'] = len(known)
                check(t.clean('join'), '⑮ console 0 (apart from the existing join-page pattern warning — counted %d, outside this stage)' % len(known))
                ctx.close()
                # 응답 본문 — 브라우저는 곧바로 다른 주소로 가서 본문을 읽을 수 없으므로, 같은 창구를 두 번째 시험 초대로 한 번 더(가입 화면과 같은 이중 제출 토큰)
                code2 = secrets.token_hex(32)
                sql("INSERT INTO member_invites(code_hash,label,team_id,created_by,expires_at,max_uses) VALUES(" + lit(hashlib.sha256(code2.encode()).hexdigest())
                    + ",'S2 합성 초대 2',NULL," + str(adm) + ",now()+interval '1 day',1)")
                join_token = secrets.token_hex(32)
                r = requests.post(site + '/tbox/api/register', json={'invite': code2, 'username': 's2_joined02', 'display_name': 'S2 합성 가입자 둘', 'password': 'S2-front-synthetic-only-2026!',
                                                                    'agree_terms': True, 'age14': True},
                                  headers={'User-Agent': UA, 'Cookie': '__Host-moa_join=' + join_token, 'X-Join-CSRF': join_token}, timeout=30)
                data = r.json() if r.headers.get('content-type', '').startswith('application/json') else {}
                summary.update(api={'status': r.status_code, 'redirect': data.get('redirect'), 'next': data.get('next', 'missing')})
                check(r.status_code == 201 and data.get('redirect') == '/' and 'next' in data and data.get('next') is None, '⑮ /api/register 201 · redirect 「/」 · next null (%d %s)' % (r.status_code, data.get('redirect')))
                # 2단계 인증 필수 갈래(/tbox/welcome) — 가입한 직원에게 필수 단계를 켤 설정이 이 틀(역할 상수 · DB 기본값)에 없어 화면 흐름은 시험하지 않는다(보고서 「미검증」)
                metrics['join'] = summary
            guard('join', join)

        # ─── [words] ⑯ 「자료」 단독 ─────────────────────────────────────────────────────────────
        if want('words'):
            def words():
                summary = {}
                TEXT_JS = """() => { const vis = e => e.getClientRects().length > 0;
                    const opts = [...document.querySelectorAll('select')].filter(vis).flatMap(s => [...s.options].map(o => o.textContent));
                    return {text: document.body.innerText, labels: [...document.querySelectorAll('[aria-label]')].map(e => e.getAttribute('aria-label')), options: opts}; }"""

                def scan(p, tag):
                    d = p.evaluate(TEXT_JS)
                    bad = bad_jaryo(d['text']) + bad_jaryo('\n'.join(d['labels'])) + bad_jaryo('\n'.join(d['options']))
                    summary[tag] = {'bad': bad}
                    check(bad == [], '⑯ %s: 「자료」 not followed by 「실」 — 0 (slogan · space-widget-only words excepted) %s' % (tag, bad[:6]))
                    return d
                for key in ('employee', 'other'):
                    ctx = context(key, 1440)
                    t = Tab(ctx)
                    p = t.page
                    home(t)
                    open_dash(p)
                    p.evaluate("document.querySelector('.dashboard-guide').open = true")
                    p.wait_for_timeout(200)
                    d = scan(p, key + ' home + dashboard + guide')
                    if key == 'employee':
                        empty = p.evaluate("""() => [...document.querySelectorAll('#home-widgets [data-widget-type="recent"], #home-widgets [data-widget-type="favorites"]')].map(w => ({type: w.dataset.widgetType,
                            rows: w.querySelectorAll('.dashboard-widget-row').length, help: [...w.querySelectorAll(':scope > p.help')].map(x => x.textContent)}))""")
                        summary['empty'] = empty
                        want_empty = {'recent': '최근 30일 동안 연 파일 · 문서가 없습니다.', 'favorites': '파일이나 문서를 즐겨찾기에 담아 보세요.'}
                        check('최근 파일 · 문서' in d['text'] and all(e['rows'] > 0 or want_empty[e['type']] in e['help'] for e in empty) and len(empty) == 2,
                              '⑯ employee: 「최근 파일 · 문서」 · empty texts 「…연 파일 · 문서가 없습니다.」 · 「파일이나 문서를…」 (when empty) %s' % json.dumps(empty, ensure_ascii=False))
                    else:
                        check('팀 파일을 함께 써요' in d['text'], '⑯ other: service card 「팀 파일을 함께 써요」')
                    # 홈 편집 창(옆 칸 · 배치 옵션 펼침)
                    p.locator('#home-edit').click()
                    p.wait_for_selector('.dashboard-editing', timeout=10000)
                    p.wait_for_timeout(300)
                    p.evaluate("document.querySelectorAll('.dashboard-batch, .home-shortcut-settings').forEach(d => d.open = true)")
                    if key == 'employee':
                        p.locator('[data-dashboard-widget="widget-00000000-0000-4000-8000-000000000001"] .dashboard-move').focus()
                    p.wait_for_timeout(200)
                    d = scan(p, key + ' home edit')
                    if key == 'employee':
                        check('최근 사용한 파일과 문서 · 소 최대 2개, 중 최대 4개, 대 최대 8개' in d['text'], '⑯ employee home edit: widget description 「최근 사용한 파일과 문서 · …」')
                    else:
                        check('최근 작업에 보일 것' in d['text'] and '파일과 문서' in d['options'] and '자료와 문서' not in d['options'], '⑯ other home edit: picker 「최근 작업에 보일 것」 · option 「파일과 문서」')
                    # 위젯 고르기 창
                    p.get_by_role('button', name='새 위젯 추가', exact=True).click()
                    p.wait_for_selector('dialog.dashboard-widget-picker[open]', timeout=10000)
                    p.wait_for_timeout(200)
                    d = scan(p, key + ' widget picker')
                    pick = p.evaluate("[...document.querySelectorAll('dialog.dashboard-widget-picker[open] label.field > span')].map(s => s.textContent)")
                    check('출처' in pick and '파일과 문서' in d['options'] and '최근 파일 · 문서' in d['options'], '⑯ %s widget picker: 「출처」 · 「파일과 문서」 · 「최근 파일 · 문서」 %s' % (key, json.dumps(pick, ensure_ascii=False)))
                    if key == 'employee':
                        p.screenshot(path=str(shots / 'home-1440-widget-picker.png'))
                    p.locator('dialog.dashboard-widget-picker[open]').get_by_role('button', name='취소', exact=True).click()
                    p.wait_for_timeout(200)
                    p.locator('.dashboard-editor').get_by_role('button', name='취소', exact=True).click()
                    p.wait_for_timeout(200)
                    check(t.clean('words-' + key), '⑯ %s console 0' % key)
                    ctx.close()
                # 로그인 전 첫 화면 — 자주 묻는 질문 · 읽을거리 펼침
                ctx = context(None, 1440)
                t = Tab(ctx)
                p = t.page
                p.goto(site + '/', wait_until='networkidle')
                p.evaluate("document.querySelectorAll('.landing-faq details, #home-stories').forEach(d => d.open = true)")
                p.wait_for_timeout(300)
                d = scan(p, 'signed-out landing')
                check('내 파일과 글은 누가 보나요?' in d['text'] and '함께 쓰는 글과 파일은 로그인 후에 볼 수 있어요.' in d['text'] and SLOGAN in d['text'],
                      '⑯ landing: 「내 파일과 글은 누가 보나요?」 · 「함께 쓰는 글과 파일은 로그인 후에 볼 수 있어요.」 · slogan kept')
                check(t.clean('words-landing'), '⑯ landing console 0')
                ctx.close()
                metrics['words'] = summary
            guard('words', words)

        browser.close()
finally:
    (out / 'home-front-browser-metrics.json').write_text(json.dumps(metrics, ensure_ascii=False, indent=1, default=str))
    (out / 'home-front-browser-results.json').write_text(json.dumps(checks, ensure_ascii=False, indent=2))
    print('RESULT pass=' + str(sum(x['pass'] for x in checks)) + ' fail=' + str(sum(not x['pass'] for x in checks)), flush=True)
if any(not x['pass'] for x in checks):
    raise SystemExit(1)
