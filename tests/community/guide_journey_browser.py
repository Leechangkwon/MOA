#!/usr/bin/env python3
"""사이트 Q:F-15 S3 과업 길잡이 수락 [browser] (실제 PHP · PostgreSQL + Chromium · 격리 틀 memories_harness.py 만)

  python3 tests/community/memories_harness.py --port 8768 --suite guide_journey_browser.py --output <결과 폴더>/browser

합성 DB · 임시 복사본 · 로컬 php -S 만 친다(운영 · tbox_re · 시드 계정 0). 계정은 fixture 의 claude_ 다섯과 이 시험이 합성 DB 에 만드는
claude_s3_* 둘 · UA tbox-review/<날짜>. 결과 파일에는 단언 이름 · 참/거짓 · 측정값만(세션 · CSRF · 본문 0). 스크린샷은 결과 폴더 shots/.
지시서 docs/qf15-s3-guide-directive.md 「수락 시험」 [static] ① ~ [layout] ⑮ · DESIGN 2.37 · 2.31 「과업 카드」 · 5장 · 6장 · PRD 9.1 Q:T-G4 ~ G14 · G17.

  준비: 관리자 창구로 사이트 템플릿 「회의록」 · 업무 공지 게시판 하나 · 모든 합성 계정 옛 사용 안내 「닫음」(⑫ 는 따로 새 계정)
  [static] ①  [home] ②  [file-share] ③ ④  [task-add] ⑤  [minutes-write] ⑥ ⑦  [notice-post] ⑧  [message-send] ⑨  [invite] ⑩
  [pause] ⑪ ⑫  [guest] ⑬  [missing] ⑭  [layout] ⑮
  GUIDE_ONLY=static,home,file,task,minutes,resume,notice,message,invite,pause,offer,guest,missing,layout 로 묶음만(기본 전부).
"""
from pathlib import Path
exec(compile(Path(__file__).with_name('memories_http.py').read_text().split('try:\n for u in users:')[0], __file__, 'exec'))
import re, secrets, html as htmllib
from urllib.parse import urlsplit
from playwright.sync_api import sync_playwright
assert str(root).startswith('/tmp/qaredi-stage13-') and manifest['database'].startswith('tbox_memories_test_')
WORKTREE = Path(__file__).resolve().parents[2]
BASE_REF = '4997871'   # 이 단계가 갈라져 나온 main 판 — [static] 「새 줄」의 기준
host = urlsplit(site).hostname
UA = 'tbox-review/' + time.strftime('%Y-%m-%d')
ONLY = set(filter(None, os.environ.get('GUIDE_ONLY', '').split(',')))
shots = out / 'shots'
shots.mkdir(exist_ok=True)
metrics = {}
JOURNEYS = ['task-add', 'file-share', 'minutes-write', 'notice-post', 'message-send', 'invite-colleague']
ASK = '무엇을 하고 싶으세요?'
EMP_ROWS = [('업무 홈', '맡은 일 보기 · 할 일 적기', '/community/?guide=task-add', 'work'), ('자료실', '파일 올려서 동료에게 보여 주기', '/tbox/?guide=file-share', 'library'),
            ('업무 홈 › 문서', '회의록 쓰기', '/community/workspace?guide=minutes-write', 'work'), ('메신저', '동료에게 한마디', '/community/?guide=message-send', None)]
ADMIN_ROWS = EMP_ROWS[:3] + [('업무 홈 › 게시판', '공지 올리기', '/community/?guide=notice-post', 'work'), EMP_ROWS[3], ('계정 관리', '동료 초대하기', '/community/?guide=invite-colleague', None)]
GUEST_ROWS = [('업무 홈', '맡은 일 보기', '/community/', 'work'), ('자료실', '파일 찾기', '/tbox/', 'library')]
FIRST_OK_RE = re.compile(r'^(/tbox/api/me|/tbox/api/me/home-dashboard|/tbox/api/messages(/.*)?|/tbox/api/member(/.*)?)$')
FORBIDDEN = ['AI 비서', '업로드', '다운로드', '삭제', '취소', '쉼터', '고급', '가짜', 'Gemini', '왼쪽', '오른쪽', '아래']
# 안내가 앱 요소에 부른 click() · submit() · requestSubmit() · showModal() · show() · close() 를 센다(안내 자신의 질문 카드 · 옛 투어 창 제외)
INSTRUMENT = r"""(() => {
  const log = window.__s3calls = [];
  const fromGuide = () => /usage-guide\.js/.test(String(new Error().stack || ''));
  const wrap = (proto, name) => { const orig = proto[name]; if (!orig) return; proto[name] = function (...a) {
    if (fromGuide() && !(this instanceof Element && this.closest('.usage-guide-dialog'))) log.push(name + ':' + (this.id || this.className || this.tagName));
    return orig.apply(this, a); }; };
  wrap(HTMLElement.prototype, 'click'); wrap(HTMLFormElement.prototype, 'submit'); wrap(HTMLFormElement.prototype, 'requestSubmit');
  wrap(HTMLDialogElement.prototype, 'showModal'); wrap(HTMLDialogElement.prototype, 'show'); wrap(HTMLDialogElement.prototype, 'close');
  window.__s3untrusted = 0;
  document.addEventListener('click', (e) => { if (!e.isTrusted && e.target instanceof Element && e.target.closest('[data-messenger-open]')) window.__s3untrusted++; }, true);
  // 검토 보정 ① — 안내 스크립트가 만든 관찰자의 콜백 수(앱 관찰자는 그대로)
  window.__s3mo = 0;
  const MO = window.MutationObserver;
  window.MutationObserver = class extends MO { constructor(cb) { const guide = fromGuide(); super(guide ? (...a) => { window.__s3mo++; return cb(...a); } : cb); } };
})();"""
STATE_JS = r"""() => {
  const vis = el => !!el && el.isConnected && !el.closest('[hidden]') && el.getClientRects().length > 0 && getComputedStyle(el).display !== 'none' && getComputedStyle(el).visibility !== 'hidden';
  const box = el => { if (!el) return null; const r = el.getBoundingClientRect(); return {l: r.left, t: r.top, r: r.right, b: r.bottom, w: r.width, h: r.height}; };
  const f = document.querySelector('section.usage-guide-float');
  const q = (sel) => f ? f.querySelector(sel) : null;
  const ring = document.querySelector('svg.usage-guide-ring rect');
  const launch = document.querySelector('.usage-guide-launch');
  const ask = document.querySelector('dialog.usage-guide-dialog');
  return {
    float: !!f, tag: f ? f.tagName : null, visible: vis(f), role: f && f.getAttribute('role'), label: f && f.getAttribute('aria-label'),
    progress: q('.usage-guide-progress') ? q('.usage-guide-progress').textContent : null,
    title: q('h2') ? q('h2').textContent : null,
    text: q('p[role=status]:not(.usage-guide-error):not(.usage-guide-progress)') ? q('p[role=status]:not(.usage-guide-error):not(.usage-guide-progress)').textContent : null,
    error: q('.usage-guide-error') ? q('.usage-guide-error').textContent : null,
    buttons: f ? [...f.querySelectorAll('.usage-guide-actions > button, .usage-guide-actions > a')].filter(vis).map(b => b.textContent.trim()) : [],
    primary: f ? [...f.querySelectorAll('.usage-guide-actions > .usage-guide-primary')].filter(vis).map(b => b.textContent.trim()) : [],
    links: f ? [...f.querySelectorAll('.usage-guide-actions > a')].map(a => a.getAttribute('href')) : [],
    chips: f ? [...f.querySelectorAll('.usage-guide-chips button')].filter(vis).map(b => b.textContent.trim()) : [],
    cls: f ? f.className : null, box: box(f),
    inset: [...document.querySelectorAll('.usage-guide-inset')].map(i => ({text: i.textContent, afterHead: !!(i.previousElementSibling && i.previousElementSibling.classList.contains('modal__head')),
      dialog: (i.closest('dialog') || {}).id || null, buttons: i.querySelectorAll('button,a,input,select').length, b: (i.querySelector('b') || {}).textContent || null, role: i.getAttribute('role'), vis: vis(i)})),
    ring: ring ? {x: +ring.getAttribute('x'), y: +ring.getAttribute('y'), w: +ring.getAttribute('width'), h: +ring.getAttribute('height'), pe: getComputedStyle(ring.ownerSVGElement).pointerEvents} : null,
    targets: [...document.querySelectorAll('.usage-guide-target')].map(t => t.id || t.getAttribute('href') || t.className),
    launchHidden: launch ? (launch.hidden || !vis(launch)) : null, launchText: launch ? launch.textContent.replace(/\s+/g, ' ').trim() : null,
    launchPaused: launch ? launch.classList.contains('is-paused') : null, launchLabel: launch ? launch.getAttribute('aria-label') : null,
    dialogs: document.querySelectorAll('dialog').length, open: [...document.querySelectorAll('dialog[open]')].map(d => d.id || d.className),
    ask: ask ? {open: ask.open, title: (ask.querySelector('#usage-guide-title') || {}).textContent || null,
      options: [...ask.querySelectorAll('.usage-guide-options .usage-guide-option')].map(o => [...o.children].map(c => c.textContent.replace(/\s+/g, ' ').trim())),
      foot: [...ask.querySelectorAll('.usage-guide-actions > button')].map(b => b.textContent.trim()), help: !!ask.querySelector('[data-help-open]'),
      optionsHidden: ask.querySelector('.usage-guide-options') ? ask.querySelector('.usage-guide-options').hidden : null} : null,
    overflow: document.documentElement.scrollWidth - innerWidth, w: innerWidth, h: innerHeight,
    active: document.activeElement ? (document.activeElement.id || document.activeElement.className || document.activeElement.tagName) : null,
    activeInCard: !!(f && f.contains(document.activeElement)),
    path: location.pathname, search: location.search, calls: window.__s3calls ? window.__s3calls.slice() : null, untrusted: window.__s3untrusted
  }; }"""
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
TOUCH_JS = r"""(sel) => [...document.querySelectorAll(sel)].filter(e => e.getClientRects().length && !e.closest('[hidden]')).map(e => { const r = e.getBoundingClientRect(); return {t: e.textContent.trim().slice(0, 20), w: Math.round(r.width), h: Math.round(r.height)}; })"""


def check(ok, name):
    checks.append({'pass': bool(ok), 'name': name})
    print(('PASS ' if ok else 'FAIL ') + name, flush=True)
    return bool(ok)


def want(group): return not ONLY or group in ONLY


LAST = {'page': None}


def guard(name, fn):
    try:
        fn()
    except Exception as error:   # noqa: BLE001 — 시험 흐름의 어떤 예외든 실패로 센다
        msg = str(error).splitlines()[0][:220] if str(error) else ''
        check(False, name + ' raised ' + type(error).__name__ + ': ' + msg)
        p = LAST['page']
        try:
            if p is not None and not p.is_closed():
                metrics['fail-' + name] = {k: v for k, v in state(p).items() if k not in ('calls',)}
                p.screenshot(path=str(shots / ('fail-' + name + '.png')))
        except Exception:   # noqa: BLE001
            pass


def uid(key): return int(users[key]['id'])
def lit(v): return "'" + str(v).replace("'", "''") + "'"


def new_session(user_id, act_role=None, reauth=False):
    token = secrets.token_hex(32)
    digest = hashlib.sha256(token.encode()).hexdigest()
    cols, vals = 'id,user_id,expires_at,ip,user_agent', "'" + digest + "'," + str(user_id) + ",clock_timestamp()+interval '8 hours','127.0.0.1'," + lit(UA)
    if act_role:
        cols += ',act_role,act_started_at,act_last_seen_at'
        vals += ',' + lit(act_role) + ',now(),now()'
    if reauth:
        cols += ',reauth_at'
        vals += ',now()'
    sql('INSERT INTO sessions(' + cols + ') VALUES(' + vals + ')')
    return token


def api(method, url, key, body=None, session=None):
    s = session or users[key]['session']
    h = {'User-Agent': UA, 'Cookie': '__Host-tbox_session=' + s}
    if method != 'GET':
        r0 = requests.get(site + '/community/api/me/guide', headers=h, timeout=30)
        h['X-CSRF-Token'] = r0.json().get('csrf_token', '') if session else users[key]['csrf']
    return requests.request(method, site + url, json=body, headers=h, timeout=40)


def jrow(key, journey, user_id=None):
    v = sql("SELECT status || '|' || page || '|' || step || '|' || extract(epoch FROM started_at)::text FROM community.guide_journeys WHERE user_id="
            + str(user_id or uid(key)) + " AND journey=" + lit(journey))
    if not v:
        return None
    s, p, st, started = v.split('|')
    return {'status': s, 'page': int(p), 'step': int(st), 'started': float(started)}


def reset_journeys(key=None, user_id=None):
    sql("DELETE FROM community.guide_journeys WHERE user_id=" + str(user_id or uid(key)))


def code_console(lines):
    # ProseMirror 의 CSS 확인 경고는 편집기(앱 코드)가 편집 모드에 들 때 내는 것 — qf12_w1_browser.py 와 같은 예외
    return [t for t, _ in lines if not t.startswith('Failed to load resource') and not t.startswith('[DOM] ') and not t.startswith('ProseMirror expects the CSS white-space')]


class Tab:
    def __init__(self, ctx, instrument=True):
        self.page = ctx.new_page()
        LAST['page'] = self.page
        self.console, self.errors, self.bad, self.reqs, self.patches = [], [], [], [], []
        p = self.page
        if instrument:
            p.add_init_script(INSTRUMENT)
        p.on('console', lambda m: self.console.append((m.text, urlsplit(m.location.get('url', '')).path)) if m.type in ('error', 'warning') else None)
        p.on('pageerror', lambda e: self.errors.append(str(e)))
        p.on('response', lambda r: self.bad.append(urlsplit(r.url).path) if r.status >= 500 else None)
        p.on('request', self._req)

    def _req(self, r):
        path = urlsplit(r.url).path
        self.reqs.append((r.method, path))
        if r.method == 'PATCH' and path in ('/community/api/me/journeys', '/community/api/me/guide'):
            try:
                self.patches.append((path, r.post_data_json))
            except Exception:   # noqa: BLE001
                self.patches.append((path, None))

    def jp(self):
        return [b for path, b in self.patches if path == '/community/api/me/journeys']

    def clean(self, tag=''):
        ok = not self.errors and not self.bad and code_console(self.console) == []
        if not ok:
            metrics['unclean-' + tag + '-' + str(len(metrics))] = {'errors': [e[:200] for e in self.errors], 'bad': self.bad, 'console': [c[0][:200] for c in self.console]}
        return ok


def state(p):
    return p.evaluate(STATE_JS)


def wait_title(p, title, timeout=12000):
    p.wait_for_function("t => { const f = document.querySelector('section.usage-guide-float'); const h = f && f.querySelector('h2');"
                        " return !!(h && h.textContent === t && !f.hidden); }", arg=title, timeout=timeout)


def wait_text(p, text, timeout=12000):
    p.wait_for_function("t => { const f = document.querySelector('section.usage-guide-float');"
                        " return !!(f && !f.hidden && f.textContent.includes(t)); }", arg=text, timeout=timeout)


def wait_inset(p, b, timeout=12000):
    p.wait_for_function("b => [...document.querySelectorAll('.usage-guide-inset')].some(i => (i.querySelector('b') || {}).textContent === b)", arg=b, timeout=timeout)


def head(p):
    """진행 카드 머리 글자(.usage-guide-progress) — 숨은 카드면 None"""
    return p.evaluate("(() => { const f = document.querySelector('section.usage-guide-float'); const h = f && !f.hidden && f.querySelector('.usage-guide-progress'); return h ? h.textContent : null; })()")


def press(p, label):
    p.locator('section.usage-guide-float .usage-guide-actions > button', has_text=label).first.click()


try:
    for u in users:
        users[u]['username'] = sql('SELECT username FROM users WHERE id=' + str(users[u]['id']))
        requests.get(base + '/api/me/space', headers={'User-Agent': UA, 'Cookie': '__Host-tbox_session=' + users[u]['session']}, timeout=30)
    emp, adm = uid('employee'), uid('admin')
    # 옛 사용 안내 제안이 화면을 덮지 않게(이 DB 의 모든 사람 — 이미 닫음 · ⑫ 는 따로 새 계정)
    sql("INSERT INTO community.usage_guides(user_id,version,status,context) SELECT id,1,'dismissed','overview' FROM users ON CONFLICT DO NOTHING")
    # 사이트 템플릿 「회의록」(관리자 창구: 사내 공개 문서 → 사이트 템플릿)
    digest = hashlib.sha256(users['admin']['session'].encode()).hexdigest()
    sql("UPDATE sessions SET reauth_at=now() WHERE id='" + digest + "'")
    tp = api('POST', '/community/api/pages', 'admin', {'title': '회의록'})
    tpid = tp.json()['page']['id'] if tp.status_code == 201 else None
    tv = api('PATCH', '/community/api/pages/' + str(tpid) + '/visibility', 'admin', {'visibility': 'internal'}) if tpid else None
    tt = api('POST', '/community/api/templates', 'admin', {'page_id': tpid, 'name': '회의록', 'scope': 'site'}) if tpid else None
    metrics['prep_template'] = [tp.status_code, tv.status_code if tv is not None else None, tt.status_code if tt is not None else None]
    # 업무 공지 게시판 하나(관리자 창구 · 재인증 창은 합성 세션에 열어 둔다)
    bd = api('POST', '/community/api/boards', 'admin', {'name': 'S3 공지', 'description': '', 'access_level': 'internal', 'board_kind': 'notice', 'area': 'work'})
    BOARD = bd.json()['board']['id'] if bd.status_code == 201 else None
    metrics['prep_board'] = [bd.status_code, BOARD]
    check(tt is not None and tt.status_code == 201 and BOARD is not None, 'prep: site template 「회의록」 and a work notice board through the admin API %s' % metrics['prep_template'] + ' ' + str(metrics['prep_board']))
    # 메신저 대화 상대 — 사람 목록 SQL 이 claude_ 를 빼므로 로그인 없는 합성 구성원 둘(messenger_window_browser.py 와 같은 준비)
    for name, display in (('synthetic_peer_a', '합성 상대 A'), ('synthetic_peer_b', '합성 상대 B')):
        sql("INSERT INTO users(username,password_hash,role,display_name,expires_at) VALUES('" + name + "','!synthetic-no-login','Employee','" + display + "',clock_timestamp()+interval '14 days')")
    # 작업 칸 시험(④)용 작업 하나 · 메신저 대화 상대(사람 목록) — 직원에게 동료 메시지 한 줄
    api('POST', '/community/api/pages', 'employee', {'my_tasks': True, 'title': 'S3 합성 작업'})
    api('POST', '/tbox/api/messages/' + str(uid('other')), 'manager', {'text': 'S3 준비 한 줄', 'request_id': str(uuid.uuid4())})

    # ─── [static] ① ──────────────────────────────────────────────────────────────────────────
    if want('static'):
        def static():
            summary = {}
            lint = subprocess.run(['bash', 'scripts/security_lint.sh'], cwd=WORKTREE, capture_output=True, text=True, timeout=600)
            summary['lint_tail'] = lint.stdout.strip().splitlines()[-3:]
            check(lint.returncode == 0, '① scripts/security_lint.sh exit 0 (%s)' % ' / '.join(summary['lint_tail'])[:200])
            changed = subprocess.run(['git', '-C', str(WORKTREE), 'diff', '--name-only', BASE_REF], capture_output=True, text=True, check=True).stdout.split()
            changed += subprocess.run(['git', '-C', str(WORKTREE), 'ls-files', '--others', '--exclude-standard'], capture_output=True, text=True, check=True).stdout.split()
            php = sorted({f for f in changed if f.endswith('.php') and (WORKTREE / f).exists()})
            js = sorted({f for f in changed if f.endswith('.js') and (WORKTREE / f).exists()})
            bad_php = [f for f in php if subprocess.run(['php', '-l', str(WORKTREE / f)], capture_output=True).returncode != 0]
            bad_js = [f for f in js if subprocess.run(['node', '--check', str(WORKTREE / f)], capture_output=True).returncode != 0]
            summary['php'] = php
            summary['js'] = js
            check(php and not bad_php, '① php -l on every changed PHP (%d) %s' % (len(php), bad_php))
            check(js and not bad_js, '① node --check on every changed JS (%d) %s' % (len(js), bad_js))
            others = [f for f in changed if f.startswith('public/assets/') and f.endswith('.js') and f != 'public/assets/usage-guide.js']
            check(others == [], '① other app JS unchanged (app.js · work.js · editor.js · templates.js · messenger.js · member.js · moa.js …) %s' % others)

            def added(files):
                diff = subprocess.run(['git', '-C', str(WORKTREE), 'diff', BASE_REF, '--'] + files, capture_output=True, text=True, check=True).stdout
                return [ln[1:] for ln in diff.splitlines() if ln.startswith('+') and not ln.startswith('+++')]
            js_new = added(['public/assets/usage-guide.js'])
            css_new = added(['public/assets/usage-guide.css', 'public/assets/app.css'])
            gjs = (WORKTREE / 'public/assets/usage-guide.js').read_text()
            gcss = (WORKTREE / 'public/assets/usage-guide.css').read_text()
            hexes = [ln.strip()[:80] for ln in css_new + js_new if re.search(r'#(?:[0-9a-fA-F]{8}|[0-9a-fA-F]{6}|[0-9a-fA-F]{3,4})\b(?![-\w])', ln.split('//')[0]) and not re.search(r"['\"]#[a-z]", ln)]
            check(css_new and not hexes and not [ln for ln in css_new if re.search(r'\b(?:rgba?|hsla?|hwb|lab|lch|oklab|oklch)\(', ln)],
                  '① new CSS/JS lines: colour values 0 (fallback colours included) %s' % hexes[:3])
            zs = [ln.strip()[:80] for ln in css_new if re.search(r'z-index\s*:\s*-?\d', ln)]
            check(not zs, '① new CSS lines: numeric z-index 0 %s' % zs)
            check(all('var(' in ln or not re.search(r':\s*#', ln) for ln in css_new) and re.search(r'--z-guide:\s*calc\(var\(--z-floating\) \+ 1\);', ''.join(css_new)),
                  '① app.css :root gains --z-guide: calc(var(--z-floating) + 1)')
            risky = [ln.strip()[:80] for ln in js_new if re.search(r'innerHTML|outerHTML|insertAdjacentHTML|document\.write|\.style\b|style=|setAttribute\(\s*[\'"]style', ln)]
            check(not risky and 'innerHTML' not in gjs and 'style=' not in gcss, '① usage-guide.js/.css: innerHTML · inline style 0 %s' % risky)
            urls = re.findall(r'https?://[^\s\'")]+', gjs + gcss)
            check(urls == ['http://www.w3.org/2000/svg', 'http://www.w3.org/2000/svg'] or set(urls) == {'http://www.w3.org/2000/svg'}, '① external URLs 0 (SVG namespace only) %s' % sorted(set(urls)))
            roles = re.findall(r'[\'"](Admin|Manager|Employee|Guest)[\'"]|\.role\b|\[[\'"]role[\'"]\]|\brole\s*[:=]=', gjs)
            check(not roles, "① usage-guide.js: role-name strings ('Admin' · 'Guest' …) · role field reads 0 (ARIA role attributes only) %s" % roles)
            # 여정 데이터 — 블록을 node 로 읽는다
            block = gjs[gjs.index('/* @journeys-begin */'):gjs.index('/* @journeys-end */')]
            literal = block[block.index('const journeys = ') + len('const journeys = '):block.rindex('};') + 1]
            prog = 'let s="";process.stdin.on("data",d=>s+=d).on("end",()=>{process.stdout.write(JSON.stringify(Function("\\"use strict\\";return ("+s+");")()));});'
            data = json.loads(subprocess.run(['node', '-e', prog], input=literal, capture_output=True, text=True, check=True).stdout)
            metrics['journeys'] = {k: [len(p['steps']) for p in v['pages']] for k, v in data.items()}
            gj = (WORKTREE / 'src/community/GuideJourney.php').read_text()
            keys = re.findall(r"'([a-z-]+)'\s*=>\s*(\d)", gj[gj.index('JOURNEYS = ['):gj.index('];', gj.index('JOURNEYS = ['))])
            check(list(data.keys()) == [k for k, _ in keys] and all(len(data[k]['pages']) == int(n) for k, n in keys), '① journeys keys and screen counts = GuideJourney::JOURNEYS %s' % keys)
            texts = []
            for k, v in data.items():
                for p in v['pages']:
                    for s in p['steps']:
                        texts.append((k + ' ' + s['title'], s['title'], 1))
                        texts.append((k + ' ' + s['title'], s['text'], 2))
                        if s.get('alt'):
                            texts.append((k + ' alt', s['alt']['text'], 2))
                        if s.get('empty'):
                            texts.append((k + ' empty', s['empty']['text'], 2))
                        if s.get('fail'):
                            texts.append((k + ' fail', s['fail'], 2))
                texts.append((k + ' done', v['done']['text'], 3))
            words = []
            for where, txt, limit in texts:
                for w in FORBIDDEN:
                    if w in txt:
                        words.append((where, w))
                for m in re.finditer(r'(?<![가-힣])(?<!업무 )(?<!모아 )(자료|홈)(?![가-힣실])', txt):
                    words.append((where, m.group(0)))
            check(not words, '① journey words: forbidden words · 「자료 」 「홈 」 alone · direction words 0 %s' % words[:4])
            long = [(where, len(re.findall(r'[.?!](?=\s|$)', txt))) for where, txt, limit in texts if len(re.findall(r'[.?!](?=\s|$)', txt)) > limit]
            check(not long, '① sentences ≤ 2 per card (done ≤ 3) %s' % long[:4])
            dm = (WORKTREE / 'DESIGN.md').read_text()
            row = re.search(r'\| 안내 카드 단추[^|]*\| ([^|]+) \|', dm)
            design_words = {re.sub(r'\(.*?\)', '', w).strip() for w in row.group(1).split(' · ')} if row else set()
            wm = re.search(r'const WORDS = \{(.*?)\};', gjs, re.S)
            js_words = set(re.findall(r":\s*'([^']+)'", wm.group(1))) if wm else set()
            summary['words'] = {'design': sorted(design_words), 'js': sorted(js_words)}
            check(design_words and js_words == design_words, '① card button words = DESIGN 5장 「안내 카드 단추」 %s' % sorted(js_words ^ design_words))
            em = re.search(r"const ENTRY = \{ tour: '([^']+)', help: '([^']+)' \};", gjs)
            check(em and '「' + em.group(1) + '」' in dm and '「' + em.group(2) + '」' in dm, '① question-card entry buttons 「이 화면 사용 안내」 · 「사용 방법 읽기」 are DESIGN 2.37 words')
            # 모든 anchor 의 id 가 src/ · public/ 에 있다(런타임 work-new-task-* 는 work.js modal('work-new-task' · aria-label 꼴은 templates.js)
            corpus = ''.join(f.read_text(errors='replace') for d in ('src', 'public') for f in (WORKTREE / d).rglob('*') if f.suffix in ('.php', '.js', '.html') and f.name != 'usage-guide.js')
            missing = []
            sels = []
            for k, v in data.items():
                for p in v['pages']:
                    if p['here'].startswith('#'):
                        sels.append(p['here'])
                    for s in p['steps']:
                        sels += s['anchor'] if isinstance(s['anchor'], list) else [s['anchor']]
                        for key in ('until', 'box', 'ahead'):
                            if isinstance(s.get(key), str):
                                sels.append(s[key])
                        if isinstance(s.get('until'), dict) and s['until'].get('submit'):
                            sels.append(s['until']['submit'])
                        if isinstance(s.get('until'), dict) and s['until'].get('text'):
                            sels.append(s['until']['text'])
                        if s.get('go'):
                            sels.append(s['go']['box'])
            for sel in sels:
                for ident in re.findall(r'#([A-Za-z][\w-]*)', sel):
                    if ident.startswith('work-new-task-'):
                        ok = "modal('work-new-task'" in corpus
                    else:
                        ok = ('id="' + ident + '"') in corpus or ("'" + ident + "'") in corpus or ('"' + ident + '"') in corpus
                    if not ok:
                        missing.append(ident)
            aria = re.findall(r'aria-label="([^"]+)"', ' '.join(sels))
            aria_ok = all(a.endswith(' 으로 새 페이지 만들기') for a in aria) and "' 으로 새 페이지 만들기'" in corpus
            summary['anchor_ids'] = sorted({i for s in sels for i in re.findall(r'#([A-Za-z][\w-]*)', s)})
            check(not missing and aria_ok, '① every anchor · until · box id exists in src/ · public/ (%d ids) %s' % (len(summary['anchor_ids']), sorted(set(missing))))
            lp = (WORKTREE / 'src/views/launcher.php').read_text()
            tails = re.findall(r"\?guide=([a-z-]+)'", lp)
            check(sorted(set(tails)) == sorted(JOURNEYS) and len(tails) == 6, '① launcher.php ?guide= values = JOURNEYS keys %s' % tails)
            ca = (WORKTREE / 'src/community/CommunityAuthz.php').read_text()
            notice = re.search(r"board_kind'\] \?\? ''\) === 'notice' && !Authz::canAdmin\(\$u\)", ca)
            gnotice = re.search(r"'notice-post'\s*=>\s*\$write && Authz::canAdmin\(\$u\)", gj)
            check(notice and gnotice, '① CommunityAuthz::decideBoardWrite notice branch = Authz::canAdmin = GuideJourney notice-post (same function)')
            check('COMMUNITY_API_TOKEN_ROUTES' in (WORKTREE / 'src/community/routes.php').read_text() and "'PATCH /api/me/journeys'" not in (WORKTREE / 'src/community/routes.php').read_text(),
                  '① the new PATCH route is not a token route')
            metrics['static'] = summary
        guard('static', static)

    with sync_playwright() as pw:
        browser = pw.chromium.launch(executable_path=os.environ.get('CHROMIUM_EXECUTABLE'))

        def context(key, width, theme='light', height=None, touch=None, session=None, extra=None, contrast=False):
            touch = (width < 720) if touch is None else touch
            ctx = browser.new_context(viewport={'width': width, 'height': height or 900}, has_touch=touch, is_mobile=touch, user_agent=UA, locale='ko-KR',
                                      reduced_motion='reduce', color_scheme='dark' if theme == 'dark' else 'light')
            ctx.grant_permissions(['clipboard-read', 'clipboard-write'], origin=site)
            jar = [{'name': 'qaredi_motion', 'value': 'reduce', 'domain': host, 'path': '/'}, {'name': 'tbox_theme', 'value': 'dark' if theme == 'dark' else 'light', 'domain': host, 'path': '/'}]
            if key or session:
                jar.append({'name': '__Host-tbox_session', 'value': session or users[key]['session'], 'domain': host, 'path': '/', 'secure': True, 'httpOnly': True, 'sameSite': 'Lax'})
            for name, value in (extra or {}).items():
                jar.append({'name': name, 'value': value, 'domain': host, 'path': '/'})
            ctx.add_cookies(jar)
            return ctx

        def go(t, path):
            t.page.goto(site + path, wait_until='networkidle')
            t.page.wait_for_timeout(400)

        # ─── [home] ② ─────────────────────────────────────────────────────────────────────────
        if want('home'):
            def home():
                summary = {}
                acting = new_session(adm, act_role='Employee')
                cases = (('employee', None, EMP_ROWS), ('admin', None, ADMIN_ROWS), ('guest', None, GUEST_ROWS), ('acting', acting, EMP_ROWS[:3]))
                for tag, session, rows in cases:
                    key = None if session else tag
                    ctx = context(key, 1440, session=session, extra={'qaredi_home_tasks': 'open'})
                    t = Tab(ctx)
                    p = t.page
                    resp = p.goto(site + '/', wait_until='networkidle')
                    p.wait_for_timeout(300)
                    got = p.evaluate("""() => [...document.querySelectorAll('#home-tasks .home-tasks__list > a')].map(a => [a.querySelector('span').textContent,
                        a.querySelector('strong').firstChild.textContent.trim(), a.getAttribute('href'), a.getAttribute('data-service'), a.hasAttribute('data-messenger-open')])""")
                    summary[tag] = got
                    check([tuple(r[:4]) for r in got] == rows, '② %s: task card rows (place · text · href · data-service) %s' % (tag, json.dumps([r[:3] for r in got], ensure_ascii=False)))
                    check(not any(r[4] for r in got), '② %s: no task row carries the messenger-window opener attributes (S3-2 A)' % tag)
                    tails = re.findall(r'\?guide=([a-z-]+)', resp.text())
                    check(all(r[2].count('?') <= 1 for r in got) and len(tails) == sum(1 for r in rows if '?guide=' in r[2]),
                          '② %s: ?guide= only on write rows · one tail per row (%d)' % (tag, len(tails)))
                    if tag == 'employee':
                        shortcut = p.locator('#dashboard-services [data-home-shortcut="messages"]')
                        check(shortcut.get_attribute('data-messenger-open') is not None, '② the 「메신저」 shortcut still opens the window (data-messenger-open)')
                        shortcut.click()
                        p.wait_for_selector('#messenger-window[open]', timeout=10000)
                        check(p.evaluate("location.pathname") == '/', '② 「메신저」 shortcut opened the floating window on the home (no navigation)')
                        api_paths = sorted({path for m, path in t.reqs if '/api/' in path})
                        odd = [a for a in api_paths if not FIRST_OK_RE.match(a)]
                        check(not [a for a in api_paths if 'journeys' in a or 'guide' in a] and not odd, '② home first load: no guide/journey requests · new addresses 0 %s' % odd)
                        check(p.evaluate("!document.querySelector('script[src*=\"usage-guide.js\"]')"), '② the home loads no guide script (B.3)')
                    check(t.clean('home-' + tag), '② %s console 0' % tag)
                    if tag == 'admin':
                        p.screenshot(path=str(shots / 'home-admin-1440-tasks.png'))
                    ctx.close()
                # 진행 중(또는 멈춘) 여정 → 맨 위 「하던 일 이어 하기」 줄 · 누르면 저장 단계부터(시작 시각 그대로)
                reset_journeys('employee')
                sql("INSERT INTO community.guide_journeys(user_id,journey,status,page,step) VALUES(" + str(emp) + ",'file-share','paused',0,1)")
                t0 = jrow('employee', 'file-share')['started']
                ctx = context('employee', 1440, extra={'qaredi_home_tasks': 'open'})
                t = Tab(ctx)
                p = t.page
                go(t, '/')
                first = p.evaluate("""() => { const a = document.querySelector('#home-tasks .home-tasks__list > a'); return a && [a.querySelector('span').textContent, a.querySelector('strong').firstChild.textContent.trim(), a.getAttribute('href'), a.getAttribute('data-service')]; }""")
                summary['resume'] = first
                check(first == ['하던 일 이어 하기', '파일 올려서 동료에게 보여 주기', '/tbox/?guide=file-share', 'library'], '② paused journey → top row 「하던 일 이어 하기」 (task text · same ?guide= · data-service) %s' % first)
                p.locator('#home-tasks .home-tasks__list > a').first.click()
                wait_title(p, '「파일 올리기」 누르기')
                r = jrow('employee', 'file-share')
                check(r['status'] == 'active' and r['step'] == 1 and r['started'] == t0, '② the resume row continues from the saved step (2 / 6) · start time unchanged (SQL)')
                check(t.jp()[:1] == [{'journey': 'file-share', 'status': 'active', 'page': 0, 'step': 1}], '② resume PATCH keeps the saved page/step (no restart) %s' % t.jp()[:1])
                press(p, '나중에')
                p.wait_for_timeout(300)
                ctx.close()
                reset_journeys('employee')
                ctx = context('employee', 1440, extra={'qaredi_home_tasks': 'open'})
                t = Tab(ctx)
                go(t, '/')
                check(t.page.evaluate("document.querySelector('#home-tasks .home-tasks__list > a span').textContent") == '업무 홈', '② no journey rows → no resume row')
                # 「동료에게 한마디」 줄 → /community/ (꼬리는 길잡이가 지움) · 진행 카드
                t.page.locator('#home-tasks a[href$="?guide=message-send"]').click()
                wait_title(t.page, '메신저 열기')
                st = state(t.page)
                check(st['path'] == '/community/' and st['search'] == '' and st['visible'], '② 「동료에게 한마디」 → /community/ (tail removed) · progress card shown')
                ctx.close()
                reset_journeys('employee')
                metrics['home'] = summary
            guard('home', home)

        # ─── [file-share] ③ ───────────────────────────────────────────────────────────────────
        if want('file'):
            def file_share():
                summary = {}
                reset_journeys('employee')
                ctx = context('employee', 1440)
                t = Tab(ctx)
                p = t.page
                p.add_init_script("if (location.search.includes('guide=')) history.replaceState({s3probe: 1}, '', location.href)")
                p.goto(site + '/tbox/?guide=file-share', wait_until='domcontentloaded')
                dialogs0 = p.evaluate("document.querySelectorAll('dialog').length")
                wait_title(p, '폴더 고르기')
                p.wait_for_timeout(300)
                st = state(p)
                summary['first'] = {k: st[k] for k in ('progress', 'title', 'buttons', 'path', 'search', 'dialogs')}
                check(t.jp()[:1] == [{'journey': 'file-share', 'status': 'active', 'page': 0, 'step': 0}], '③ /tbox/?guide=file-share: PATCH body exactly {journey, status:active, page:0, step:0} %s' % t.jp()[:1])
                check(st['path'] == '/tbox/' and st['search'] == '' and p.evaluate("JSON.stringify(history.state)") == '{"s3probe":1}', '③ the ?guide= tail is gone · history.state kept')
                check(st['tag'] == 'SECTION' and st['role'] == 'region' and st['label'] == '안내' and st['visible'] and not st['ask'], '③ progress card = section.usage-guide-float[role=region][aria-label=안내] (not a dialog)')
                check(st['progress'] == '1 / 6 · 파일 올려서 동료에게 보여 주기' and st['buttons'] == ['나중에', '다음'], '③ step 1 progress 「1 / 6 · 파일 올려서 동료에게 보여 주기」(같은 화면 — 「다음:」 0) · 「나중에」 「다음」 %s' % st['buttons'])
                check(st['dialogs'] == dialogs0 and st['open'] == [] and st['launchHidden'] is True, '③ dialog count unchanged (%d) · no open dialog · no dim · 「? 사용 안내」 hidden' % st['dialogs'])
                check(st['ring'] and st['ring']['w'] > 0 and st['ring']['pe'] == 'none' and 'folder-pane' in st['targets'], '③ ring rect around the target · pointer-events none · .usage-guide-target on #folder-pane')
                check(st['calls'] == [], '③ the guide called click/submit/showModal/close on app elements 0 times')
                # 폴더 하나를 고른다(기본 등급이 그 폴더 등급이 되게) — 사람 누름
                p.locator('#folder-pane a, #folder-pane button', has_text='synthetic-a').first.click()
                p.wait_for_timeout(800)
                wait_title(p, '폴더 고르기')
                press(p, '다음')
                wait_title(p, '「파일 올리기」 누르기')
                st = state(p)
                hit = p.evaluate("""() => { const b = document.getElementById('upload-toggle').getBoundingClientRect(); const e = document.elementFromPoint(b.left + b.width / 2, b.top + b.height / 2);
                    return !!(e && e.closest('#upload-toggle')); }""")
                check(hit and st['buttons'] == ['나중에', '건너뛰기'], '③ step 2: elementFromPoint(centre of #upload-toggle) is the real button · do-step buttons 「나중에」 「건너뛰기」')
                check(st['primary'] == [] and p.locator('section.usage-guide-float .usage-guide-primary').count() == 0, '③ do step: both buttons outlined — .usage-guide-primary 0 in the card (DESIGN 2.37 「단추」 · 검토 보정 ②)')
                check(st['progress'] == '2 / 6 · 파일 올려서 동료에게 보여 주기', '③ step 2 head 「2 / 6 · 파일 올려서 동료에게 보여 주기」 — same screen next: 「다음:」 0 (%s)' % st['progress'])
                p.screenshot(path=str(shots / 'pc-progress-card-1440.png'))
                p.screenshot(path=str(shots / 'pc-progress-same-screen-1440.png'))
                p.locator('#upload-toggle').click()
                wait_inset(p, '3 / 6')
                st = state(p)
                summary['inset'] = st['inset']
                ins = st['inset']
                check(len(ins) == 1 and ins[0]['afterHead'] and ins[0]['dialog'] == 'upload-dialog' and ins[0]['buttons'] == 0 and ins[0]['role'] == 'status' and not st['visible'],
                      '③ window step: one .usage-guide-inset right after .modal__head in #upload-dialog · 0 buttons · role=status · progress card hidden')
                p.screenshot(path=str(shots / 'pc-window-inset-1440.png'))
                p.set_input_files('#upload-file', files=[{'name': 'S3-길잡이.txt', 'mimeType': 'text/plain', 'buffer': b'S3 guide synthetic'}])
                wait_inset(p, '4 / 6')
                level = p.evaluate("document.getElementById('upload-level').value")
                summary['default_level'] = level
                check(level != '', '③ level step: a default level is already chosen (%s)' % level)
                p.locator('#upload-submit').focus()
                wait_inset(p, '5 / 6', timeout=4000)
                check(True, '③ focus on 「올리기」 with the default level → skips ahead to 「올리기」 (not stuck)')
                p.locator('#upload-submit').click()
                wait_title(p, '목록에서 확인', timeout=20000)
                st = state(p)
                check(st['open'] == [] and st['inset'] == [] and st['buttons'] == ['나중에', '마치기'], '③ uploaded → window closed → list step (read · 「마치기」)')
                press(p, '마치기')
                wait_text(p, '파일을 올렸어요.')
                st = state(p)
                summary['done'] = {k: st[k] for k in ('progress', 'text', 'chips', 'buttons')}
                check(st['text'] == '파일을 올렸어요. 자료실 목록과 검색에서 다시 찾을 수 있어요. 알리려면 메신저로 한 줄 보내요.' and st['chips'] == ['동료에게 한마디'] and st['buttons'] == ['마치기'],
                      '③ done card text · chip 「동료에게 한마디」 · 「마치기」')
                check(st['progress'] == '6 / 6 · 파일 올려서 동료에게 보여 주기' and '다음:' not in st['progress'], '③ done card head 「6 / 6 · …」 — 「다음:」 0 (%s)' % st['progress'])
                p.screenshot(path=str(shots / 'pc-done-card-1440.png'))
                r = jrow('employee', 'file-share')
                check(r['status'] == 'completed', '③ completed row (SQL)')
                check(st['calls'] == [], '③ whole file-share journey: guide click/submit/showModal/close on app elements 0')
                press(p, '마치기')
                p.wait_for_timeout(300)
                check(not state(p)['float'] and state(p)['launchHidden'] is False, '③ 「마치기」 closes the card only (no question card) · 「? 사용 안내」 back')
                t1 = r['started']
                time.sleep(1.0)
                go(t, '/tbox/?guide=file-share')
                wait_title(p, '폴더 고르기')
                r2 = jrow('employee', 'file-share')
                check(r2['status'] == 'active' and r2['started'] > t1, '③ same address again on a completed journey → redo · new start time (SQL)')
                check(t.clean('file-share'), '③ console 0')
                ctx.close()
                metrics['file_share'] = summary
            guard('file_share', file_share)

            def side_effects():
                # ④ 진행 카드가 떠 있어도 — 작업 칸 Esc · 자료실 파일 동작 창 초점 복원
                reset_journeys('employee')
                ctx = context('employee', 1440)
                t = Tab(ctx)
                p = t.page
                go(t, '/community/?guide=task-add')
                wait_title(p, '내 작업')
                p.wait_for_selector('#work-home-rows .task-inbox__line', timeout=10000)
                line = p.locator('#work-home-rows .task-inbox__line').first
                line.click()
                p.wait_for_function("!document.getElementById('task-pane').hidden", timeout=10000)
                line.focus()
                p.keyboard.press('Escape')
                p.wait_for_timeout(400)
                closed = p.evaluate("document.getElementById('task-pane').hidden && !location.search.includes('task=')")
                check(closed and state(p)['visible'], '④ progress card up: task pane Esc closes the pane (work.js) · card still there')
                ctx.close()
                ctx = context('employee', 412, touch=True)
                t = Tab(ctx)
                p = t.page
                folder = sql("SELECT id FROM folders WHERE name='synthetic-a'")
                go(t, '/tbox/?folder=' + folder)   # 파일이 든 폴더(맨 위에는 파일 줄이 없다) — 길잡이는 질문 카드에서 시작
                p.locator('.usage-guide-launch').click()
                p.wait_for_selector('dialog.usage-guide-dialog[open]', timeout=6000)
                p.locator('dialog.usage-guide-dialog .usage-guide-option', has_text='파일 올려서 동료에게 보여 주기').click()
                wait_title(p, '폴더 고르기')
                more = p.locator('#files-body .q-file-more').first
                more.wait_for(timeout=10000)
                more.click()
                p.wait_for_selector('#q-file-actions-dialog[open]', timeout=5000)
                p.locator('#q-file-actions-close').click()
                p.wait_for_timeout(300)
                back = p.evaluate("document.activeElement && document.activeElement.classList.contains('q-file-more')")
                check(back, '④ progress card up: closing the file actions window returns focus to its ··· button (app.js)')
                ctx.close()
                reset_journeys('employee')
            guard('side_effects', side_effects)

        # ─── [task-add] ⑤ ─────────────────────────────────────────────────────────────────────
        if want('task'):
            def task_add():
                reset_journeys('employee')
                ctx = context('employee', 1440)
                t = Tab(ctx)
                p = t.page
                go(t, '/community/?guide=task-add')
                wait_title(p, '내 작업')
                press(p, '다음')
                wait_title(p, '「작업 추가」 누르기')
                check(head(p) == '2 / 4 · 맡은 일 보기 · 할 일 적기', '⑤ task-add step 2 head — same screen next: 「다음:」 0 (%s)' % head(p))
                p.locator('#work-home-add').click()
                wait_inset(p, '3 / 4')
                st = state(p)
                check(len(st['inset']) == 1 and st['inset'][0]['dialog'] == 'work-new-task-dialog' and st['inset'][0]['afterHead'], '⑤ runtime #work-new-task-dialog gets the inset after .modal__head')
                p.locator('#work-new-task-form input[type=text]').fill('S3 길잡이 할 일')
                p.locator('#work-new-task-dialog button[type=submit]').click()
                wait_title(p, '다시 찾기', timeout=15000)
                press(p, '마치기')
                wait_text(p, '할 일 하나를 적었어요.')
                st = state(p)
                check(st['text'] == '할 일 하나를 적었어요. 업무 홈과 「내 작업」 화면에서 다시 보여요.' and st['chips'] == [], '⑤ work:changed + fact → step 4 → done card (no chip)')
                check(jrow('employee', 'task-add')['status'] == 'completed' and st['calls'] == [], '⑤ completed row · guide app calls 0')
                check(t.clean('task-add'), '⑤ console 0')
                ctx.close()
                reset_journeys('employee')
            guard('task_add', task_add)

        # ─── [minutes-write] ⑥ ⑦ ──────────────────────────────────────────────────────────────
        if want('minutes'):
            def minutes():
                summary = {}
                reset_journeys('other')
                ctx = context('other', 1440)
                t = Tab(ctx)
                p = t.page
                go(t, '/community/workspace?guide=minutes-write')
                wait_title(p, '「더보기」 열기')
                heads = [head(p)]
                p.locator('#community-more > summary').click()
                wait_title(p, '「템플릿에서 만들기」')
                heads.append(head(p))
                p.locator('#community-template-new').click()
                wait_inset(p, '3 / 10')
                p.wait_for_timeout(300)
                ins = state(p)['inset']
                summary['heads'] = heads
                check(heads == ['1 / 10 · 회의록 쓰기', '2 / 10 · 회의록 쓰기'], '⑥ minutes-write steps 1 · 2 — same screen next: 「다음:」 0 %s' % heads)
                check(ins and '다음:' not in ins[0]['text'], '⑥ step 3 (「회의록」 만들기 — next is the document screen) is a window step: the window line carries no 「다음:」 (DESIGN 2.37 ③) %s' % (ins[0]['text'][:40] if ins else None))
                saved = [b for b in t.jp()]
                summary['patches_before_make'] = saved
                check(saved and saved[-1] == {'journey': 'minutes-write', 'status': 'active', 'page': 1, 'step': 0}, '⑥ template 「만들기」 step pre-saves {page:1, step:0} before the click %s' % saved[-1:])
                check('template-body' in ' '.join(map(str, state(p)['targets'])) or p.evaluate("!!document.querySelector('#template-body .usage-guide-target')"), '⑥ the 「회의록」 row 「만들기」 button is the target')
                p.locator('#template-body button[aria-label="회의록 으로 새 페이지 만들기"]').click()
                p.wait_for_url(re.compile(r'/community/p/[0-9a-f-]{36}$'), timeout=15000)
                doc = urlsplit(p.url).path.rsplit('/', 1)[1]
                summary['doc'] = doc
                wait_title(p, '「편집」 누르기', timeout=8000)
                check('이 단추가 지금 안 보여요' not in json.dumps(state(p), ensure_ascii=False), '⑥ /p/<id> load → 「편집」 step right away (no 「안 보여요」)')
                p.locator('#page-edit').click()
                wait_title(p, '제목 적기')
                title = p.locator('#page-title-input')
                title.click()
                p.keyboard.press('End')
                p.keyboard.type('x')
                p.wait_for_timeout(300)
                st = state(p)
                check(st['title'] == '제목 적기' and st['active'] == 'page-title-input', '⑥ one character in the title: still the title step after 0.3 s · focus stays in the title field')
                wait_title(p, '칸 채우기', timeout=3000)
                check(state(p)['active'] == 'page-title-input', '⑥ 0.8 s pause → body step · focus not moved (still the title field)')
                p.wait_for_timeout(1200)
                check(state(p)['title'] == '칸 채우기', '⑥ body step does not pass by itself even though 「저장됨」 may show')
                p.locator('#page-editor .ProseMirror').click()
                p.keyboard.type(' 결정 사항 하나')
                wait_title(p, '「편집 마침」', timeout=4000)
                # 카드 안에서 Tab — 갇히지 않는다
                p.locator('section.usage-guide-float .usage-guide-actions > button').last.focus()
                p.keyboard.press('Tab')
                p.wait_for_timeout(100)
                check(not state(p)['activeInCard'], '⑥ Tab from the last card button leaves the card (no trap)')
                p.locator('#page-done').click()
                wait_title(p, '팀과 같이 보기', timeout=8000)
                p.locator('#page-visibility-edit').click()
                wait_inset(p, '9 / 10')
                check(state(p)['inset'][0]['dialog'] == 'visibility-dialog', '⑥ visibility window inset')
                p.locator('#visibility-choices input[value=team]').check()
                wait_inset(p, '10 / 10')
                p.locator('#visibility-save').click()
                wait_text(p, '회의록을 만들었어요.', timeout=15000)
                st = state(p)
                check(st['text'] == '회의록을 만들었어요. 업무 홈 › 내 문서와 검색 칸에서 제목으로 다시 찾아요. 다음 회의 땐 같은 틀로 하나 더 만들어요.', '⑥ 「팀」 → 「바꾸기」 → done card')
                check(jrow('other', 'minutes-write')['status'] == 'completed' and st['calls'] == [], '⑥ completed row · guide app calls 0')
                check(t.clean('minutes'), '⑥ console 0')
                ctx.close()
                metrics['minutes'] = summary
            guard('minutes', minutes)

        if want('resume'):
            def resume():
                summary = {}
                k = 'manager'
                reset_journeys(k)
                api('POST', '/community/api/pages', k, {'title': 'S3 ⑦ 다른 문서'})
                other_doc = api('POST', '/community/api/pages', k, {'title': 'S3 ⑦ 관계없는 문서'}).json()['page']['id']
                time.sleep(1.1)
                sql("INSERT INTO community.guide_journeys(user_id,journey,status,page,step) VALUES(" + str(uid(k)) + ",'minutes-write','active',1,0)")
                ctx = context(k, 1440)
                t = Tab(ctx)
                p = t.page
                go(t, '/community/')
                st = state(p)
                summary['home'] = {k2: st[k2] for k2 in ('float', 'launchText', 'launchPaused', 'launchLabel')}
                check(not st['float'] and st['launchText'] == '? 이어 하기 4/10' and st['launchPaused'], '⑦ saved page 1 · work home (earlier screen): card 0 · launch 「이어 하기 4/10」 (.is-paused) %s' % st['launchText'])
                go(t, '/community/p/' + other_doc)
                check(not state(p)['float'], '⑦ an unrelated document /p/<other id>: card 0')
                go(t, '/community/procedures')
                check(not state(p)['float'], '⑦ /community/procedures (same page frame): not taken as page 0 — card 0')
                p.locator('.usage-guide-launch').click()
                p.wait_for_selector('dialog.usage-guide-dialog[open]', timeout=5000)
                st = state(p)
                summary['ask'] = st['ask']
                check(st['ask']['title'] == ASK and st['ask']['options'][0] == ['하던 일 이어 하기', '회의록 쓰기 (4 / 10)'], '⑦ launch → question card · top row 「하던 일 이어 하기 — 회의록 쓰기 (4 / 10)」 %s' % st['ask']['options'][:1])
                p.locator('dialog.usage-guide-dialog .usage-guide-option').first.click()
                # 아직 사실 없음(시작 뒤 문서 0) → 저장 화면(문서)의 주소가 없어 내 문서 첫 단계로 「이 화면으로 가기」
                p.wait_for_function("() => { const f = document.querySelector('section.usage-guide-float'); return f && !f.hidden; }", timeout=6000)
                st = state(p)
                summary['no_fact'] = {k2: st[k2] for k2 in ('title', 'buttons', 'links')}
                check(st['links'] == ['/community/workspace'] and '이 화면으로 가기 → 내 문서' in st['buttons'], '⑦ resume without a result yet: 「이 화면으로 가기 → 내 문서」 %s' % st['buttons'])
                press(p, '나중에')
                # 사실이 참이면 「결과가 이미 있어요」
                api('POST', '/community/api/pages', k, {'title': 'S3 ⑦ 시작 뒤 문서'})
                go(t, '/community/procedures')
                p.locator('.usage-guide-launch').click()
                p.wait_for_selector('dialog.usage-guide-dialog[open]', timeout=5000)
                p.locator('dialog.usage-guide-dialog .usage-guide-option').first.click()
                wait_title(p, '결과가 이미 있어요')
                st = state(p)
                check(st['buttons'] == ['다시 하기', '나중에', '마치기'], '⑦ the fact is already true → 「결과가 이미 있어요」 (다시 하기 · 나중에 · 마치기) %s' % st['buttons'])
                press(p, '마치기')
                wait_text(p, '회의록을 만들었어요.')
                check(jrow(k, 'minutes-write')['status'] == 'completed', '⑦ 「마치기」 → completed (server re-checked)')
                ctx.close()
                # 「회의록」 템플릿이 없는 문맥 → 3초 뒤 빈 상태
                reset_journeys(k)
                ctx = context(k, 1440)
                t = Tab(ctx)
                p = t.page
                p.route('**/community/api/templates', lambda route: route.fulfill(json={'templates': []}) if route.request.method == 'GET' else route.continue_())
                go(t, '/community/workspace?guide=minutes-write')
                wait_title(p, '「더보기」 열기')
                p.locator('#community-more > summary').click()
                wait_title(p, '「템플릿에서 만들기」')
                p.locator('#community-template-new').click()
                p.wait_for_timeout(1500)
                early = state(p)['inset']
                p.wait_for_function("() => [...document.querySelectorAll('.usage-guide-inset')].some(i => i.textContent.includes('틀이 아직 없어요'))", timeout=6000)
                st = state(p)
                check(not any('틀이 아직 없어요' in i['text'] for i in early) and '「회의록」 틀이 아직 없어요 — 관리자가 만들면 여기 보여요' in st['inset'][0]['text'],
                      '⑦ no 「회의록」 template: empty state only after 3 s — 「「회의록」 틀이 아직 없어요 — 관리자가 만들면 여기 보여요」 (window line)')
                ctx.close()
                # 첫 그림에 비었다가 1초 뒤 채워지는 목록 — 빈 상태 0
                reset_journeys(k)
                ctx = context(k, 1440)
                t = Tab(ctx)
                p = t.page
                p.route('**/community/api/templates', lambda route: (time.sleep(1.0), route.continue_()) if route.request.method == 'GET' else route.continue_())
                go(t, '/community/workspace?guide=minutes-write')
                p.locator('#community-more > summary').click()
                wait_title(p, '「템플릿에서 만들기」')
                p.locator('#community-template-new').click()
                p.wait_for_timeout(3600)
                st = state(p)
                check(st['inset'] and '틀이 아직 없어요' not in st['inset'][0]['text'] and st['inset'][0]['b'] == '3 / 10', '⑭ list filled 1 s after the first paint: no empty-state card (3 / 10 stays)')
                ctx.close()
                reset_journeys(k)
                metrics['resume'] = summary
            guard('resume', resume)

        # ─── [notice-post] ⑧ ──────────────────────────────────────────────────────────────────
        if want('notice'):
            def notice():
                summary = {}
                reset_journeys('admin')
                ctx = context('admin', 1440)
                t = Tab(ctx)
                p = t.page
                go(t, '/community/?guide=notice-post')
                wait_title(p, '공지 게시판 열기')
                p.wait_for_function("() => !!document.querySelector('#nav-community-boards a.usage-guide-target')", timeout=6000)
                st = state(p)
                check(st['text'] == '「S3 공지」 게시판을 누르세요. 공지 게시판 화면이 열려요.' and any(x.endswith('/b/' + str(BOARD)) for x in st['targets']),
                      '⑧ ≥960 open sidebar: the notice board link has the outline · text uses the board name %s' % st['targets'])
                check(t.jp()[-1:] == [{'journey': 'notice-post', 'status': 'active', 'page': 1, 'step': 0}], '⑧ link step pre-saves {page:1, step:0}')
                check(st['progress'] == '1 / 5 · 공지 올리기 · 다음: 「S3 공지」', '⑧ step 1 (next step on the board screen) head 「1 / 5 · 공지 올리기 · 다음: 「S3 공지」」 (%s)' % st['progress'])
                p.screenshot(path=str(shots / 'pc-progress-next-screen-1440.png'))
                p.locator('#nav-community-boards a[href$="/b/' + str(BOARD) + '"]').click()
                p.wait_for_url(re.compile(r'/community/b/%d$' % BOARD), timeout=10000)
                wait_title(p, '「새 글」 누르기')
                check(head(p) == '2 / 5 · 공지 올리기', '⑧ board screen step 2 — next step on the same screen: 「다음:」 0 (%s)' % head(p))
                p.locator('#board-new').click()
                wait_inset(p, '3 / 5')
                p.locator('#board-new-input').fill('S3 길잡이 공지')
                p.locator('#board-new-save').click()
                p.wait_for_url(re.compile(r'/community/p/[0-9a-f-]{36}'), timeout=15000)
                wait_title(p, '본문 적기', timeout=10000)
                p.locator('#page-editor .ProseMirror').click()
                p.keyboard.type('공지 본문')
                wait_title(p, '「편집 마침」', timeout=5000)
                p.locator('#page-done').click()
                wait_text(p, '공지를 올렸어요.', timeout=15000)
                st = state(p)
                check(st['text'] == '공지를 올렸어요. 동료의 업무 홈 공지 줄에 보이고, 읽으면 사라져요.' and st['chips'] == ['동료에게 한마디'], '⑧ 「새 글」 → 「만들고 쓰기」 → /p/<id>?edit=1 → body · 편집 마침 → done card · chip')
                check(st['calls'] == [], '⑧ guide app calls 0')
                ctx.close()
                for tag, width, extra in (('collapsed', 1440, {'qaredi_sidebar': 'collapsed'}), ('412', 412, {})):
                    reset_journeys('admin')
                    ctx = context('admin', width, extra=extra)
                    t = Tab(ctx)
                    p = t.page
                    go(t, '/community/?guide=notice-post')
                    p.wait_for_function("() => { const f = document.querySelector('section.usage-guide-float'); return f && !f.hidden && f.querySelector('a.usage-guide-primary'); }", timeout=3000)
                    st = state(p)
                    summary[tag] = {k2: st[k2] for k2 in ('buttons', 'links', 'progress')}
                    check(st['links'] == ['/community/b/%d' % BOARD] and st['buttons'][-1] == '이 화면으로 가기 → 「S3 공지」' and st['progress'].startswith('1 / 5'),
                          '⑧ %s: no wait — 「이 화면으로 가기 → 「S3 공지」」 href /community/b/<id> %s' % (tag, st['buttons']))
                    check(st['progress'] == '1 / 5 · 공지 올리기 · 다음: 「S3 공지」' and st['progress'].split('다음: ', 1)[1] == st['buttons'][-1].split('→ ', 1)[1],
                          '⑧ %s: go card head 「· 다음: 「S3 공지」」 = the button name (%s)' % (tag, st['progress']))
                    ctx.close()
                # 공지 게시판을 보관한 문맥 → 빈 상태
                reset_journeys('admin')
                sql('UPDATE community.spaces SET is_active=false WHERE id=' + str(BOARD))
                try:
                    ctx = context('admin', 1440)
                    t = Tab(ctx)
                    p = t.page
                    go(t, '/community/?guide=notice-post')
                    wait_text(p, '공지 게시판이 아직 없어요 — 관리자가 만들면 여기 생겨요')
                    check(True, '⑧ notice board archived (is_active false): empty-state card')
                    check(head(p) == '1 / 5 · 공지 올리기', '⑧ empty-state card head — 「다음:」 0 (%s)' % head(p))
                    ctx.close()
                finally:
                    sql('UPDATE community.spaces SET is_active=true WHERE id=' + str(BOARD))
                reset_journeys('admin')
                metrics['notice'] = summary
            guard('notice', notice)

        # ─── [message-send] ⑨ ─────────────────────────────────────────────────────────────────
        if want('message'):
            def message():
                summary = {}
                reset_journeys('employee')
                ctx = context('employee', 1440)
                t = Tab(ctx)
                p = t.page
                go(t, '/community/?guide=message-send')
                wait_title(p, '메신저 열기')
                check(p.evaluate("!document.getElementById('messenger-window').open") and state(p)['untrusted'] == 0, '⑨ step 1 drawn · window closed · the guide pressed nothing yet')
                press(p, '다음')
                wait_inset(p, '2 / 4', timeout=10000)
                st = state(p)
                summary['opened'] = {'untrusted': st['untrusted'], 'calls': st['calls']}
                check(st['untrusted'] == 1 and [c for c in st['calls'] if c.startswith('click:')] and len([c for c in st['calls'] if c.startswith('click:')]) == 1,
                      '⑨ only 「다음」 of step 1 pressed [data-messenger-open] once (isTrusted false click 1) %s' % st['calls'])
                p.locator('#messenger-window #message-people .moa-person').first.click()
                wait_inset(p, '3 / 4')
                p.locator('#messenger-window #message-text').click()
                p.keyboard.type('S3 길잡이 한마디')
                wait_inset(p, '4 / 4', timeout=4000)
                p.locator('#messenger-window #message-send').click()
                wait_text(p, '보냈어요.', timeout=15000)
                st = state(p)
                check(st['text'] == '보냈어요. 창을 닫아도 대화는 남고, 다시 열면 마지막 대화부터 이어져요.', '⑨ pick colleague · one line (0.8 s) · 「보내기」 → done card')
                p.screenshot(path=str(shots / 'pc-messenger-done-1440.png'))
                check(state(p)['untrusted'] == 1, '⑨ still exactly one guide click in the whole journey')
                ctx.close()
                # 창이 이미 열려 있으면 0 · 모아 AI 대화로 복원된 창이면 「‹ 목록」을 가리킴 · 모아 AI 에게만 보내면 「아직 결과가 없어요」
                reset_journeys('employee')
                ctx = context('employee', 1440)
                t = Tab(ctx)
                p = t.page
                go(t, '/community/')
                p.locator('#messenger-open').click()
                p.wait_for_selector('#messenger-window[open]')
                p.locator('#messenger-window #message-ai').click()
                p.wait_for_function("document.getElementById('message-ai').getAttribute('aria-pressed') === 'true'", timeout=8000)
                p.wait_for_timeout(600)
                go(t, '/community/?guide=message-send')
                p.wait_for_selector('#messenger-window[open]', timeout=10000)
                wait_inset(p, '2 / 4', timeout=10000)
                st = state(p)
                summary['ai_restored'] = {'targets': st['targets'], 'untrusted': st['untrusted']}
                check(st['untrusted'] == 0 and 'messenger-back' in st['targets'], '⑨ window already open (restored 모아 AI chat): guide clicks 0 · points at 「‹ 목록」 %s' % st['targets'])
                # 합성 틀은 모아 AI 가 꺼져 있다(준비 중) — 「보내기」가 꺼져 있으면 시험이 사람 대신 그 폼을 제출해(모아 AI 쪽 보내기) 안내가 받는 신호만 같게 만든다
                if p.evaluate("!document.getElementById('message-text').disabled && !document.getElementById('message-send').disabled"):
                    p.locator('#messenger-window #message-text').click()
                    p.keyboard.type('AI 에게만')
                    p.locator('#messenger-window #message-send').click()
                else:
                    p.evaluate("document.getElementById('message-form').requestSubmit()")
                summary['ai_send_by'] = 'button' if p.evaluate("!document.getElementById('message-send').disabled") else 'form'
                p.wait_for_function("() => [...document.querySelectorAll('.usage-guide-inset')].some(i => i.textContent.includes('아직 결과가 없어요'))", timeout=12000)
                st = state(p)
                check('아직 결과가 없어요 — 동료를 골라 보내면 끝나요' in st['inset'][0]['text'] and jrow('employee', 'message-send')['status'] == 'active', '⑨ sent to 모아 AI only → 「아직 결과가 없어요 — 동료를 골라 보내면 끝나요」 · not completed')
                p.screenshot(path=str(shots / 'pc-messenger-beside-noresult-1440.png'))
                ctx.close()
                reset_journeys('employee')
                metrics['message'] = summary
            guard('message', message)

        # ─── [invite] ⑩ ───────────────────────────────────────────────────────────────────────
        if want('invite'):
            def invite():
                reset_journeys('admin')
                session = new_session(adm)   # 재인증 창이 지난(없는) 세션
                known = 'S3-reauth-' + secrets.token_hex(6)
                hashed = subprocess.run(['php', '-r', 'echo password_hash($argv[1], PASSWORD_ARGON2ID);', known], capture_output=True, text=True, check=True).stdout
                old_hash = sql('SELECT password_hash FROM users WHERE id=' + str(adm))
                sql('UPDATE users SET password_hash=' + lit(hashed) + ' WHERE id=' + str(adm))
                try:
                    ctx = context(None, 1440, session=session)
                    t = Tab(ctx)
                    p = t.page
                    go(t, '/community/?guide=invite-colleague')
                    p.wait_for_function("() => { const f = document.querySelector('section.usage-guide-float'); return f && !f.hidden && f.querySelector('a.usage-guide-primary'); }", timeout=8000)
                    st = state(p)
                    check(st['links'] == ['/tbox/users'] and st['buttons'] == ['나중에', '이 화면으로 가기 → 계정 관리'], '⑩ admin /community/?guide=invite-colleague → 「이 화면으로 가기 → 계정 관리」 href /tbox/users %s' % st['buttons'])
                    p.locator('section.usage-guide-float a.usage-guide-primary').click()
                    p.wait_for_url(re.compile(r'/tbox/reauth'), timeout=10000)
                    check(not state(p)['float'], '⑩ stale re-auth → /tbox/reauth (no card over the re-auth screen)')
                    p.locator('#reauth-password').fill(known)
                    p.locator('#reauth-submit').click()
                    p.wait_for_url(re.compile(r'/tbox/users$'), timeout=15000)
                    wait_title(p, '초대 링크 만들기', timeout=10000)
                    check(True, '⑩ passed re-auth → /tbox/users → step 1 resumes')
                    p.locator('#invite-label').fill('S3 길잡이 초대')
                    p.locator('#invite-form button[type=submit], #invite-form button:not([type])').first.click()
                    wait_title(p, '링크 보내기', timeout=10000)
                    p.locator('#invite-copy').click()
                    wait_title(p, '초대 목록', timeout=6000)
                    press(p, '마치기')
                    wait_text(p, '초대 링크를 만들었어요.')
                    check(jrow('admin', 'invite-colleague')['status'] == 'completed' and state(p)['calls'] == [], '⑩ make link → copy → done · completed row · guide app calls 0')
                    check(t.clean('invite'), '⑩ console 0')
                    ctx.close()
                finally:
                    sql('UPDATE users SET password_hash=' + lit(old_hash) + ' WHERE id=' + str(adm))
                reset_journeys('admin')
            guard('invite', invite)

        # ─── [pause] ⑪ ⑫ ──────────────────────────────────────────────────────────────────────
        if want('pause'):
            def pause():
                reset_journeys('employee')
                ctx = context('employee', 1440)
                t = Tab(ctx)
                p = t.page
                go(t, '/tbox/?guide=file-share')
                wait_title(p, '폴더 고르기')
                press(p, '다음')
                wait_title(p, '「파일 올리기」 누르기')
                press(p, '나중에')
                p.wait_for_timeout(400)
                r = jrow('employee', 'file-share')
                check(r['status'] == 'paused' and r['step'] == 1 and not state(p)['float'], '⑪ 「나중에」 → paused · page/step kept (step 1) (SQL) · card gone')
                p.reload(wait_until='networkidle')
                p.wait_for_timeout(1200)
                check(not state(p)['float'] and not state(p)['launchPaused'], '⑪ reload: a paused journey does not open by itself')
                p.locator('.usage-guide-launch').click()
                p.wait_for_selector('dialog.usage-guide-dialog[open]')
                st = state(p)
                check(st['ask']['options'][0] == ['하던 일 이어 하기', '파일 올려서 동료에게 보여 주기 (2 / 6)'], '⑪ question card top row 「하던 일 이어 하기 — 파일 올려서 동료에게 보여 주기 (2 / 6)」')
                p.screenshot(path=str(shots / 'pc-question-card-1440.png'))
                foot = p.evaluate("""() => [...document.querySelectorAll('dialog.usage-guide-dialog .usage-guide-actions > button')].map(b => { const r = b.getBoundingClientRect();
                    return {t: b.textContent.trim(), l: Math.round(r.left), r: Math.round(r.right), top: Math.round(r.top), h: Math.round(r.height), primary: b.classList.contains('usage-guide-primary')}; })""")
                rows = p.evaluate(TOUCH_JS, 'dialog.usage-guide-dialog .usage-guide-option, dialog.usage-guide-dialog .usage-guide-options button, dialog.usage-guide-dialog .usage-guide-actions > button')
                metrics['question_card_foot'] = foot
                check(foot and foot[-1]['t'] == '닫기' and foot[-1]['r'] == max(b['r'] for b in foot) and not any(b['primary'] for b in foot) and all(b['h'] >= 44 for b in foot)
                      and foot[0]['t'] == '이 화면 사용 안내' and foot[0]['l'] == min(b['l'] for b in foot), '⑮ question card foot: 「이 화면 사용 안내」 first (start) · 「닫기」 at the end (DOM order = visual · 2.7) · no black button · 44 high %s' % [b['t'] for b in foot])
                check(all(x['h'] >= 44 for x in rows), '⑮ question card rows · buttons ≥ 44 high %s' % [x for x in rows if x['h'] < 44])
                p.locator('dialog.usage-guide-dialog .usage-guide-option').first.click()
                wait_title(p, '「파일 올리기」 누르기')
                check(t.jp()[-1] == {'journey': 'file-share', 'status': 'active', 'page': 0, 'step': 1}, '⑪ resume row → from the saved step')
                # Esc — 초점이 카드 안일 때
                p.locator('section.usage-guide-float .usage-guide-actions > button').first.focus()
                p.keyboard.press('Escape')
                p.wait_for_timeout(400)
                check(jrow('employee', 'file-share')['status'] == 'paused' and not state(p)['float'], '⑪ Esc with focus in the card = 「나중에」 (paused)')
                p.locator('.usage-guide-launch').click()
                p.wait_for_selector('dialog.usage-guide-dialog[open]')
                p.locator('dialog.usage-guide-dialog .usage-guide-options > div > button:not(.usage-guide-option)').click()
                wait_title(p, '폴더 고르기')
                check(t.jp()[-1] == {'journey': 'file-share', 'status': 'active', 'page': 0, 'step': 0, 'restart': True}, '⑪ 「처음부터」 → PATCH restart:true %s' % t.jp()[-1:])
                press(p, '나중에')
                p.locator('.usage-guide-launch').click()
                p.wait_for_selector('dialog.usage-guide-dialog[open]')
                p.locator('dialog.usage-guide-dialog .usage-guide-option', has_text='회의록 쓰기').click()
                p.wait_for_function("() => { const f = document.querySelector('section.usage-guide-float'); return f && !f.hidden; }", timeout=6000)
                check(jrow('employee', 'file-share')['status'] == 'paused' and jrow('employee', 'minutes-write')['status'] == 'active', '⑪ another task chosen → the first stays paused (row kept)')
                # 질문 카드 「사용 방법 읽기」 — 앱의 위임(app.js initHelpDialog)이 도움말 창을 연다 · 질문 카드는 먼저 닫힌다(창 위에 창 0)
                press(p, '나중에')
                p.wait_for_timeout(300)
                p.locator('.usage-guide-launch').click()
                p.wait_for_selector('dialog.usage-guide-dialog[open]')
                help_there = p.evaluate("!!document.getElementById('help-dialog')")
                if help_there:
                    p.locator('dialog.usage-guide-dialog [data-help-open]').click()
                    p.wait_for_selector('#help-dialog[open]', timeout=5000)
                    st = state(p)
                    check(st['open'] == ['help-dialog'] and not st['ask'] and st['calls'] == [], '⑪ 「사용 방법 읽기」 → app opens the help window · question card closed first (no window over a window · guide app calls 0)')
                else:
                    check(not p.evaluate("!!document.querySelector('dialog.usage-guide-dialog [data-help-open]')"), '⑪ no help window on this screen → no 「사용 방법 읽기」')
                ctx.close()
                reset_journeys('employee')
            guard('pause', pause)

        if want('offer'):
            def offer():
                new_id = int(sql("INSERT INTO users(username,password_hash,role,team_id,mfa_secret,mfa_enabled,expires_at) SELECT 'claude_s3_offer02',password_hash,'Employee',team_id,mfa_secret,true,"
                                 "clock_timestamp()+interval '14 days' FROM users WHERE id=" + str(emp) + " RETURNING id").splitlines()[0])
                sql("INSERT INTO user_consents(user_id,doc,version,action,ip) SELECT " + str(new_id) + ",doc,version,action,ip FROM user_consents WHERE user_id=" + str(emp))
                session = new_session(new_id)
                ctx = context(None, 1440, session=session)
                t = Tab(ctx)
                p = t.page
                go(t, '/community/')
                p.wait_for_selector('dialog.usage-guide-dialog[open]', timeout=6000)
                st = state(p)
                check(st['ask']['title'] == ASK and st['ask']['foot'][-1] == '나중에 볼게요' and len(st['ask']['options']) == 4, '⑫ first-login offer = question card (#usage-guide-title 「무엇을 하고 싶으세요?」 · 「나중에 볼게요」) %s' % st['ask']['foot'])
                p.locator('dialog.usage-guide-dialog .usage-guide-option', has_text='맡은 일 보기 · 할 일 적기').click()
                wait_title(p, '내 작업')
                p.wait_for_timeout(400)
                old = [b for path, b in t.patches if path == '/community/api/me/guide']
                check(old == [{'status': 'dismissed', 'context': 'overview', 'step': 0}], '⑫ choosing a task → progress card · old PATCH dismissed once %s' % old)
                go(t, '/tbox/')
                p.wait_for_timeout(1000)
                check(not state(p)['ask'], '⑫ another screen: no offer again')
                ctx.close()
            guard('offer', offer)

        # ─── [guest] ⑬ ────────────────────────────────────────────────────────────────────────
        if want('guest'):
            def guest():
                ctx = context('guest', 1440)
                t = Tab(ctx)
                p = t.page
                go(t, '/community/?guide=task-add')
                p.wait_for_timeout(800)
                st = state(p)
                check(t.jp() == [] and not st['float'] and st['search'] == '', '⑬ guest /community/?guide=task-add → PATCH 0 · screen unchanged (tail removed)')
                p.locator('.usage-guide-launch').click()
                p.wait_for_selector('dialog.usage-guide-dialog[open]')
                st = state(p)
                check(st['ask']['options'] == [] and st['ask']['optionsHidden'] is True and st['ask']['foot'][-1] == '닫기' and st['ask']['foot'][0] == '이 화면 사용 안내',
                      '⑬ guest question card: options 0 · footer only (이 화면 사용 안내 … 닫기) %s' % st['ask']['foot'])
                p.keyboard.press('Escape')
                p.wait_for_timeout(200)
                check(not state(p)['ask'] and [path for path, b in t.patches] == [], '⑬ Esc closes the question card without any write')
                check(t.clean('guest'), '⑬ console 0')
                ctx.close()
            guard('guest', guest)

        # ─── [missing] ⑭ ──────────────────────────────────────────────────────────────────────
        if want('missing'):
            def missing():
                for delay, label in ((2000, 'late'), (None, 'never')):
                    reset_journeys('employee')
                    ctx = context('employee', 1440)
                    t = Tab(ctx)
                    p = t.page
                    script = """(() => { const delay = %s; const grab = () => { const el = document.getElementById('work-home-tasks'); if (!el) return false;
                        const parent = el.parentNode, next = el.nextSibling; el.remove(); if (delay !== null) setTimeout(() => parent.insertBefore(el, next), delay); return true; };
                        const mo = new MutationObserver(() => { if (grab()) mo.disconnect(); }); mo.observe(document, {childList: true, subtree: true}); })();""" % ('null' if delay is None else str(delay))
                    p.add_init_script(script)
                    go(t, '/community/?guide=task-add')
                    if delay:
                        wait_title(p, '내 작업', timeout=8000)
                        p.wait_for_function("() => !!document.querySelector('#work-home-tasks.usage-guide-target')", timeout=6000)
                        check('이 단추가 지금 안 보여요' not in json.dumps(state(p), ensure_ascii=False), '⑭ target inserted 2 s later → continues on it (no 「안 보여요」)')
                    else:
                        wait_title(p, '이 단추가 지금 안 보여요', timeout=8000)
                        st = state(p)
                        check(st['buttons'][:2] == ['건너뛰기', '나중에'] and st['buttons'][2].startswith('이 화면으로 가기') and st['progress'].startswith('1 / 4'),
                              '⑭ not there within 3 s → 「이 단추가 지금 안 보여요」 (건너뛰기 · 나중에 · 이 화면으로 가기) · n / m kept %s' % st['buttons'])
                        check(st['progress'] == '1 / 4 · 맡은 일 보기 · 할 일 적기', '⑭ 「안 보여요」 card head — 「다음:」 0 (%s)' % st['progress'])
                    ctx.close()
                reset_journeys('employee')
            guard('missing', missing)

        # ─── [layout] ⑮ ───────────────────────────────────────────────────────────────────────
        if want('layout'):
            def layout():
                summary = {}
                grid = [(360, 'light', True), (412, 'light', True), (412, 'dark', False), (820, 'light', False), (960, 'dark', False), (1024, 'light', False), (1280, 'dark', False), (1440, 'light', False),
                        (360, 'dark', True), (820, 'dark', False), (960, 'light', False), (1024, 'dark', False), (1280, 'light', False), (1440, 'dark', False)]
                for width, theme, touch in grid:
                    tag = '%d-%s%s' % (width, theme, '-touch' if touch and width < 720 else '')
                    reset_journeys('employee')
                    ctx = context('employee', width, theme=theme, touch=touch)
                    t = Tab(ctx)
                    p = t.page
                    go(t, '/tbox/?guide=file-share')
                    wait_title(p, '폴더 고르기')
                    st = state(p)
                    r = st['box']
                    m = {'card': r, 'overflow': st['overflow'], 'cls': st['cls']}
                    ok = r and r['l'] >= 0 and r['r'] <= width + 0.5 and st['overflow'] <= 0
                    if width < 720:
                        ok = ok and r['h'] <= 0.3 * st['h'] + 1
                    touch_rows = p.evaluate(TOUCH_JS, 'section.usage-guide-float .usage-guide-actions > *')
                    small = [x for x in touch_rows if x['h'] < 44]
                    contrast = [round(h.evaluate(CONTRAST_JS), 2) for h in p.query_selector_all('section.usage-guide-float .usage-guide-progress, section.usage-guide-float h2, section.usage-guide-float p[role=status], section.usage-guide-float .usage-guide-actions > *') if h.is_visible()]
                    m.update(touch=touch_rows, contrast=contrast)
                    check(ok, '⑮ %s: card inside the screen · overflow 0%s %s' % (tag, ' · height ≤ 30dvh' if width < 720 else '', json.dumps(r)))
                    check(not small and contrast and min(contrast) >= 4.5, '⑮ %s: touch targets ≥ 44 %s · contrast ≥ 4.5 (min %.2f)' % (tag, small, min(contrast) if contrast else 0))
                    tgt = p.evaluate("(() => { const r = document.getElementById('folder-pane').getBoundingClientRect(); return r.height ? (r.top + r.bottom) / 2 : null; })()")
                    if tgt is not None and r:
                        low = tgt > st['h'] / 2
                        check((not low) or 'is-top' in st['cls'], '⑮ %s: target in the lower half → card on top (%s · centre %.0f / %d)' % (tag, st['cls'], tgt, st['h']))
                        bar = p.evaluate("(() => { const els = [...document.querySelectorAll('#nav-toggle, .shell-tools, .appbar')].filter(e => e.getClientRects().length); return els.length ? Math.max(...els.map(e => e.getBoundingClientRect().bottom)) : 0; })()")
                        check(r['t'] >= bar - 0.5, '⑮ %s: the card leaves the top bar free (card top %.0f ≥ bar bottom %.0f)' % (tag, r['t'], bar))
                    if width >= 960:
                        p.locator('#messenger-open').click()
                        p.wait_for_selector('#messenger-window[open]')
                        p.wait_for_timeout(500)
                        area = p.evaluate("""() => { const a = document.querySelector('section.usage-guide-float').getBoundingClientRect(), b = document.getElementById('messenger-window').getBoundingClientRect();
                            return Math.max(0, Math.min(a.right, b.right) - Math.max(a.left, b.left)) * Math.max(0, Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top)); }""")
                        m['messenger_overlap'] = area
                        check(area == 0 and state(p)['visible'], '⑮ %s: messenger open → card ∩ messenger area 0 (%s)' % (tag, state(p)['cls']))
                        if width in (960, 1280, 1440):
                            geo = p.evaluate("""() => { const a = document.querySelector('section.usage-guide-float').getBoundingClientRect(), b = document.getElementById('messenger-window').getBoundingClientRect(),
                                t = document.getElementById('folder-pane').getBoundingClientRect();
                                const ov = (x, y) => Math.max(0, Math.min(x.right, y.right) - Math.max(x.left, y.left)) * Math.max(0, Math.min(x.bottom, y.bottom) - Math.max(x.top, y.top));
                                return {right: a.right, left: a.left, width: a.width, mleft: b.left, messenger: ov(a, b), target: ov(a, t)}; }""")
                            m['beside'] = geo
                            check(abs(geo['right'] - (geo['mleft'] - 16)) <= 1 and geo['messenger'] == 0 and geo['target'] == 0 and abs(geo['width'] - min(540, geo['mleft'] - 32)) <= 1,
                                  '⑮ %s: messenger open → card right edge = messenger left − 16 (%.0f / %.0f) · width min(540, left − 32) · ∩ messenger 0 · ∩ target 0 (검토 보정 ③)' % (tag, geo['right'], geo['mleft'] - 16))
                        if width == 1280 and theme == 'dark':
                            p.screenshot(path=str(shots / 'pc-messenger-beside-card-1280-dark.png'))
                        if width == 1280 and theme == 'light':
                            p.screenshot(path=str(shots / 'pc-messenger-beside-card-1280.png'))
                        p.locator('#messenger-window [data-messenger-close].btn--icon').click()
                        p.wait_for_timeout(200)
                        p.locator('details.appbar__apps > summary').first.click()
                        p.wait_for_timeout(200)
                        check(not state(p)['visible'], '⑮ %s: app panel open → card hidden' % tag)
                        p.keyboard.press('Escape')
                        p.locator('details.appbar__apps > summary').first.click() if p.evaluate("!!document.querySelector('details.appbar__apps[open]')") else None
                        p.wait_for_timeout(200)
                        acct = p.locator('details.shell-account > summary').first
                        if acct.count():
                            acct.click()
                            p.wait_for_timeout(200)
                            check(not state(p)['visible'], '⑮ %s: account panel open → card hidden' % tag)
                            acct.click()
                            p.wait_for_timeout(200)
                    else:
                        tog = p.locator('#nav-toggle')
                        if tog.is_visible():
                            tog.click()
                            p.wait_for_timeout(300)
                            check(p.evaluate("document.documentElement.classList.contains('mobile-nav-open')") and not state(p)['visible'], '⑮ %s: html.mobile-nav-open → card hidden' % tag)
                            p.locator('#mobile-nav-close').click() if p.locator('#mobile-nav-close').is_visible() else p.keyboard.press('Escape')
                            p.wait_for_timeout(300)
                            check(not p.evaluate("document.documentElement.classList.contains('mobile-nav-open')") and state(p)['visible'], '⑮ %s: drawer closed → card back' % tag)
                    # 확인 · 재인증 같은 showModal 창 위에 카드 0 — 화면 머리 「사용 방법」 도움말 창(사람 누름)
                    help_btn = p.locator('.page-head [data-help-open]').first
                    if help_btn.count() and help_btn.is_visible():
                        help_btn.focus()   # 좁은 폭에서 카드가 머리 단추 위에 올 수 있다 — 키보드로 연다(사람의 Enter)
                        mo0 = p.evaluate('window.__s3mo')
                        p.keyboard.press('Enter')
                        p.wait_for_selector('#help-dialog[open]')
                        p.wait_for_timeout(1000)
                        mo = p.evaluate('window.__s3mo') - mo0
                        m['observer_calls_help_1s'] = mo
                        check(mo <= 5, '⑮ %s: card up + an unrelated showModal window (help) open 1 s → guide observer callbacks %d ≤ 5 (no hidden re-write loop · 검토 보정 ①)' % (tag, mo))
                        check(not state(p)['visible'], '⑮ %s: a showModal window (help) open → card hidden' % tag)
                        p.keyboard.press('Escape')
                        p.wait_for_timeout(200)
                        check(state(p)['visible'], '⑮ %s: window closed → card back' % tag)
                    if width < 720:
                        p.locator('#search-q').focus()
                        p.wait_for_timeout(200)
                        st2 = state(p)
                        chip = p.locator('section.usage-guide-float .usage-guide-chips button').first
                        check('is-folded' in st2['cls'] and chip.is_visible() and chip.inner_text().startswith('안내 1/6'), '⑮ %s: typing field focused → folded chip 「안내 1/6 ▾」' % tag)
                        if width == 412 and theme == 'light':
                            p.screenshot(path=str(shots / 'phone-folded-chip-412.png'))
                        chip.click()
                        p.wait_for_timeout(200)
                        check('is-folded' not in state(p)['cls'], '⑮ %s: chip → unfolded' % tag)
                    if width == 1440 and theme == 'light':
                        p.screenshot(path=str(shots / 'pc-progress-card-1440-step1.png'))
                    check(t.clean('layout-' + tag), '⑮ %s console 0' % tag)
                    summary[tag] = m
                    ctx.close()
                # 고대비 한 번 · 폰 메신저 시트 · 폰 작업 칸
                sql('UPDATE users SET high_contrast=true WHERE id=' + str(emp))
                try:
                    for width in (1440, 412):
                        reset_journeys('employee')
                        ctx = context('employee', width)
                        t = Tab(ctx)
                        p = t.page
                        go(t, '/tbox/?guide=file-share')
                        wait_title(p, '폴더 고르기')
                        contrast = [round(h.evaluate(CONTRAST_JS), 2) for h in p.query_selector_all('section.usage-guide-float .usage-guide-progress, section.usage-guide-float h2, section.usage-guide-float p[role=status], section.usage-guide-float .usage-guide-actions > *') if h.is_visible()]
                        hc = p.evaluate("document.documentElement.getAttribute('data-contrast')")
                        check(hc == 'high' and min(contrast) >= 4.5 and state(p)['overflow'] <= 0, '⑮ high contrast %d: contrast ≥ 4.5 (min %.2f) · overflow 0' % (width, min(contrast)))
                        check(t.clean('hc-%d' % width), '⑮ high contrast %d console 0' % width)
                        ctx.close()
                finally:
                    sql('UPDATE users SET high_contrast=false WHERE id=' + str(emp))
                reset_journeys('employee')
                ctx = context('employee', 412, touch=True)
                t = Tab(ctx)
                p = t.page
                go(t, '/community/?guide=message-send')
                wait_title(p, '메신저 열기')
                press(p, '다음')
                wait_inset(p, '2 / 4', timeout=10000)
                st = state(p)
                check(st['inset'][0]['vis'] and st['overflow'] <= 0, '⑮ 412 messenger sheet: window step line visible inside the sheet · overflow 0')
                p.screenshot(path=str(shots / 'phone-messenger-sheet-412.png'))
                ctx.close()
                reset_journeys('employee')
                ctx = context('employee', 412, touch=True)
                t = Tab(ctx)
                p = t.page
                go(t, '/community/?guide=task-add')
                wait_title(p, '내 작업')
                press(p, '다음')
                wait_title(p, '「작업 추가」 누르기')
                st = state(p)
                check(st['box']['h'] <= 0.3 * st['h'] + 1 and st['overflow'] <= 0, '⑮ 412 work home (task step): card ≤ 30dvh · overflow 0')
                p.screenshot(path=str(shots / 'phone-work-task-412.png'))
                p.locator('#work-home-add').click()
                wait_inset(p, '3 / 4')
                p.screenshot(path=str(shots / 'phone-work-task-window-412.png'))
                check(t.clean('phone-task'), '⑮ 412 task console 0')
                ctx.close()
                reset_journeys('employee')
                metrics['layout'] = summary
            guard('layout', layout)

        browser.close()
finally:
    (out / 'guide-journey-browser-metrics.json').write_text(json.dumps(metrics, ensure_ascii=False, indent=1, default=str))
    (out / 'guide-journey-browser-results.json').write_text(json.dumps(checks, ensure_ascii=False, indent=2))
    print('RESULT pass=' + str(sum(x['pass'] for x in checks)) + ' fail=' + str(sum(not x['pass'] for x in checks)), flush=True)
if any(not x['pass'] for x in checks):
    raise SystemExit(1)
