#!/usr/bin/env python3
"""Q:F-12 U4 — 화면 수락 시험 (실제 PHP · PostgreSQL + Chromium · 격리 틀 memories_harness.py 만)

  python3 tests/community/memories_harness.py --suite qf12_u4_browser.py --output <결과 폴더>

합성 DB · 임시 복사본 · 로컬 php -S 만 친다(운영 8082 · tbox_re · 시드 계정 0). 계정은 fixture 의 claude_ 다섯과 UA tbox-review/<날짜>.
결과 파일에는 단언 이름 · 참/거짓 · 측정값(폭 · 수 · 좌표 · 클래스)만 — 세션 · CSRF · 응답 본문 0. 스크린샷은 결과 폴더 shots/(필요한 것만).

  [first]   1440 · 412 — 프로젝트 · 작업 첫 그리기에 일반 문서 부품 0: 페이지 창구를 막은 채(스크립트가 화면을 바꾸지 못하는 상태) 연 직후 DOM + 스크린샷 ·
            보통으로 열 때 매 프레임 관찰(requestAnimationFrame)에 일반 문서 부품이 한 번도 보이지 않음 · 일반 문서는 그대로 보임(대조)
  [full]    작업 전체 화면 = 칸 부품(속성 이름표 · 맡김 안내 · 설명 · 하위 작업 · 파일 첨부 · 댓글 · 모든 활동 — 오른쪽 칸과 같은 지문) · ?task= 0 ·
            data-task-open 0 · ✕ · 전체 화면 · 폭 손잡이 0 · 폭 ≤ 960 가운데 · 「‹ 프로젝트」 → 프로젝트 화면 · 「문서로 보기」 → 옛 모양
  [image]   편집 중 그림 블록이 img(자료실 tbox: · 첨부 att: 둘 다 · 칸 편집기 · 전체 편집기) · 검사 중 상자 · 손잡이 끌기 → 폭 클래스 → 저장 →
            다시 열어도 같은 폭(읽기 · 편집) · Alt+→ 5% · 손잡이 aria · 인라인 style 0
  [mention] 댓글(전체 화면 댓글 · 작업 칸 · 작업 전체 화면): @ → 후보 목록(= 창구 빈 q 답) · 글자로 좁힘(= 창구 답) · ↑↓ Enter · 확인된 아이디 파랑
            (--accent-soft) · 없는 아이디 빨강(--danger-soft) · 아래 줄 글자 · 보내기 옆 안내 · 토큰 안 캐럿 → 목록 다시 · 고르면 바꿈 · 보낸 뒤 비움
  [readers] 보기 공유 직원 · 손님: 손잡이 0 · 편집기 0 · 댓글 칸 = 창구 can_comment(손님 0)
  [matrix]  360 · 412(터치) · 820 · 1199 · 1280 · 1440 × 밝게 · 어둡게 · 고대비 — 작업 전체 화면(읽기 · @ 목록 · 그림 고른 편집) 가로 넘침 0 · 누름 자리 < 44 0 ·
            인라인 style 0 · 콘솔 0
"""
from pathlib import Path
exec(compile(Path(__file__).with_name('memories_http.py').read_text().split('try:\n for u in users:')[0], __file__, 'exec'))
import re, secrets, struct, zlib
from urllib.parse import urlsplit
from playwright.sync_api import sync_playwright
assert str(root).startswith('/tmp/qaredi-stage13-') and manifest['database'].startswith('tbox_memories_test_')
host = urlsplit(site).hostname
UA = 'tbox-review/' + time.strftime('%Y-%m-%d')
ONLY = set(filter(None, os.environ.get('QF12U4_ONLY', '').split(',')))
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
            raise TimeoutError('until: ' + js[:80])
        page.wait_for_timeout(100)


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

    def go(self, path):
        self.page.goto(site + path, wait_until='networkidle')
        self.page.wait_for_timeout(300)

    def clean(self, tag=''):
        ok = not self.errors and not self.bad and code_console(self.console) == []
        if not ok:
            metrics['unclean-' + tag + '-' + str(len(metrics))] = {'errors': [e[:200] for e in self.errors], 'bad': self.bad, 'console': [c[0][:200] for c in self.console]}
        return ok


AUDIT_JS = r"""(touch) => {
  const vis = el => { if (!el.isConnected) return false; const r = el.getBoundingClientRect(); const s = getComputedStyle(el);
    return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && s.display !== 'none' && !el.closest('[hidden]:not(#sidebar)'); };
  const out = {overflow: document.documentElement.scrollWidth - window.innerWidth,
               inline: [...document.querySelectorAll('[style]')].filter(e => !e.matches('.progress') && (e.getAttribute('style') || '').trim() !== '').map(e => e.tagName + '.' + e.className + '#' + e.id + '[' + (e.getAttribute('type') || '') + '] ' + (e.getAttribute('style') || '').slice(0, 60) + ' in ' + ((e.parentElement || {}).className || '')).slice(0, 5),
               wide: [...document.querySelectorAll('body *')].filter(e => { const r = e.getBoundingClientRect(); return r.width > 0 && r.right > window.innerWidth + 1; })
                 .slice(0, 6).map(e => e.tagName + '#' + e.id + '.' + (typeof e.className === 'string' ? e.className : '') + ' ' + Math.round(e.getBoundingClientRect().right))};
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
    // 그림 손잡이(span · role=slider) — 누름 자리 = ::before
    for (const el of document.querySelectorAll('.doc-image__resize')) {
      if (!vis(el)) continue;
      const r = el.getBoundingClientRect(); const b = getComputedStyle(el, '::before'); const px = v => parseFloat(v) || 0;
      const w = r.width - px(b.left) - px(b.right), h = r.height - px(b.top) - px(b.bottom);
      if (w < 43.5 || h < 43.5) small.push('doc-image__resize ' + Math.round(w) + 'x' + Math.round(h));
    }
  }
  out.small = small.slice(0, 12);
  out.smallCount = small.length;
  return out;
}"""

DOC_PARTS = ['header.page-head:not(#project-head)', '#page-props', '#page-props-summary', '#row-props-wrap', '#page-doc', '#page-children-wrap', '#page-comments-wrap']
PARTS_JS = r"""(sels) => sels.map(s => [s, [...document.querySelectorAll(s)].some(el => { const r = el.getBoundingClientRect(); const c = getComputedStyle(el);
  return r.width > 0 && r.height > 0 && c.display !== 'none' && c.visibility !== 'hidden'; })])"""
# 매 프레임 관찰 — 일반 문서 부품이 한 번이라도 보였나(화면이 작업 · 프로젝트로 바뀌기 전 깜빡임)
WATCH_JS = r"""(() => { const sels = %s; const seen = {}; let frames = 0; const t0 = performance.now();
  const tick = () => { frames++; for (const s of sels) { for (const el of document.querySelectorAll(s)) { const r = el.getBoundingClientRect(); const c = getComputedStyle(el);
      if (r.width > 0 && r.height > 0 && c.display !== 'none' && c.visibility !== 'hidden') { seen[s] = (seen[s] || 0) + 1; } } }
    window.__u4watch = {seen: seen, frames: frames, kind: document.body ? document.body.getAttribute('data-page-kind') : null};
    if (performance.now() - t0 < 4000) requestAnimationFrame(tick); };
  requestAnimationFrame(tick); })();""" % json.dumps(DOC_PARTS)
# 칸 부품 지문 — 오른쪽 칸 · 전체 화면이 같은 부품 한 벌인가
SIG_JS = r"""(sel) => { const p = document.querySelector(sel); if (!p) return null;
  return {dt: [...p.querySelectorAll('.task-pane__props > dt')].map(e => e.textContent.trim()),
          note: (p.querySelector('.task-pane__note') || {}).textContent || null,
          heads: [...p.querySelectorAll('.task-pane__section-head')].map(e => e.firstChild ? e.firstChild.textContent.trim() : ''),
          tabs: [...p.querySelectorAll('[role=tab]')].map(e => e.textContent.trim()),
          title: (p.querySelector('.task-pane__title') || {}).textContent || null,
          foot: !!p.querySelector('.task-pane__foot textarea'), mention: !!p.querySelector('.task-pane__foot .mention-field')}; }"""


def synth_png(w, h):
    rows = b''.join(b'\x00' + b''.join(bytes(((x * 255) // w, 120 + (y * 100) // h, 200)) for x in range(w)) for y in range(h))
    chunk = lambda tag, data: struct.pack('>I', len(data)) + tag + data + struct.pack('>I', zlib.crc32(tag + data) & 0xffffffff)
    return b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('>IIBBBBB', w, h, 8, 2, 0, 0, 0)) + chunk(b'IDAT', zlib.compress(rows, 9)) + chunk(b'IEND', b'')


PNG = synth_png(320, 160)
ASSIGN_NOTE = '작업을 맡겨도 소유자와 볼 수 있는 곳은 바뀌지 않아요. 담당자는 이 작업을 고칠 수 있는 사람 중에서 고를 수 있어요.'


def tbox_upload(key, name, data, level='public'):
    h = {'User-Agent': UA, 'Cookie': '__Host-tbox_session=' + users[key]['session'], 'X-CSRF-Token': users[key]['csrf']}
    return requests.post(site + '/tbox/api/files', headers=h, data={'access_level': level}, files={'file': (name, data, 'application/octet-stream')}, timeout=60)


def blk(kind, **kw):
    b = {'id': str(uuid.uuid4()), 'type': kind}
    b.update(kw)
    return b


def para(t): return blk('paragraph', text=[{'t': t, 'm': {}}])


def save_doc(pid, blocks, user='employee'):
    cur = call('GET', '/api/pages/' + pid, user=user)['page']['doc_version']
    r = raw('PATCH', '/api/pages/' + pid, {'doc': {'schema': 1, 'blocks': blocks}, 'doc_version': cur}, user)
    if r.status_code != 200:
        print('SAFE_ERROR=' + r.text[:300], flush=True)
    return r.status_code


def server_blocks(pid, user='employee'):
    return call('GET', '/api/pages/' + pid, user=user)['page']['doc']['blocks']


try:
    for u in users:
        users[u]['username'] = sql('SELECT username FROM users WHERE id=' + str(users[u]['id']))
        users[u]['display'] = sql("SELECT COALESCE(NULLIF(display_name,''),username) FROM users WHERE id=" + str(users[u]['id']))
        call('GET', '/api/me/space', user=u)
    emp = uid('employee')
    sql("INSERT INTO community.usage_guides(user_id,version,status,context) SELECT id,1,'dismissed','overview' FROM users ON CONFLICT DO NOTHING")
    today = sql("SELECT (now() AT TIME ZONE 'Asia/Seoul')::date")

    # ─── 합성 준비 — 직원 개인 프로젝트 · 작업(그림 셋 · 하위 작업 · 댓글) · 일반 문서 ─────────────────────
    proj = call('POST', '/api/pages', {'database': True, 'preset': 'project', 'title': 'U4 합성 프로젝트'}, expected=201)['page']
    task = call('POST', '/api/pages', {'parent_id': proj['id'], 'title': 'U4 합성 작업', 'properties': {'section': '01. 진행'}}, expected=201)['page']
    call('PATCH', '/api/pages/' + task['id'] + '/properties', {'status': 'todo', 'due': today})
    task2 = call('POST', '/api/pages', {'parent_id': proj['id'], 'title': 'U4 합성 작업 둘', 'properties': {'section': '01. 진행'}}, expected=201)['page']
    sub = call('POST', '/api/pages', {'parent_id': task['id'], 'title': 'U4 하위 작업'}, expected=201)['page']
    call('PATCH', '/api/pages/' + sub['id'] + '/properties', {'status': 'todo'})
    plain = call('POST', '/api/pages', {'title': 'U4 일반 문서'}, expected=201)['page']
    tf = tbox_upload('employee', 'u4-자료실.png', PNG)
    tfile = tf.json().get('id') if tf.status_code in (200, 201) else None
    check(tf.status_code in (200, 201) and isinstance(tfile, str), 'fixture: t-box png (public) %d' % tf.status_code)

    def attach(pid, name):
        r = raw('POST', '/api/pages/' + pid + '/attachments', None, 'employee', files={'file': (name, PNG, 'application/octet-stream')})
        check(r.status_code == 201, 'fixture: attachment ' + name + ' %d' % r.status_code)
        return r.json()['id']
    a_clean = attach(task['id'], 'u4-첨부.png')
    a_pend = attach(task['id'], 'u4-검사대기.png')
    sql("UPDATE community.page_attachments SET scan_status='pending' WHERE public_id=" + lit(a_pend))
    check(save_doc(task['id'], [para('설명 첫 줄 — 그림을 보며 쓰기'), blk('image', file_ref='tbox:' + str(tfile), width='wide'),
                                 blk('image', file_ref='att:' + a_clean, width='normal', width_pct=40), blk('image', file_ref='att:' + a_pend, width='normal'),
                                 para('그림 아래 글')]) == 200, 'fixture: task doc with tbox: · att: clean (width_pct 40) · att: pending images')
    p_att = attach(plain['id'], 'u4-문서첨부.png')
    check(save_doc(plain['id'], [para('일반 문서'), blk('image', file_ref='tbox:' + str(tfile), width='small'), blk('image', file_ref='att:' + p_att, width='normal')]) == 200,
          'fixture: plain doc with tbox: · att: images')
    # 공유 — 매니저(같은 팀) 편집 · 다른 팀 직원 보기 · 손님 보기 (프로젝트 → 작업으로 물려진다)
    for who_, level in (('manager', 'edit'), ('other', 'view'), ('guest', 'view')):
        for pid in (proj['id'], plain['id']):
            r = raw('PUT', '/api/pages/' + pid + '/shares/' + users[who_]['username'], {'level': level}, 'employee')
            check(r.status_code in (200, 201), 'fixture: share %s %s (%d)' % (who_, level, r.status_code))
    call('POST', '/api/pages/' + task['id'] + '/comments', {'body': 'U4 매니저 댓글'}, user='manager', expected=201)

    def cand_api(pid, q, key='employee'):
        return [c['username'] for c in call('GET', '/api/mention-candidates?page=' + pid + '&q=' + q, user=key)['candidates']]

    with sync_playwright() as pw:
        browser = pw.chromium.launch(executable_path=os.environ.get('CHROMIUM_EXECUTABLE'))

        def context(key, width, height=900, touch=False, extra=None):
            ctx = browser.new_context(viewport={'width': width, 'height': height}, has_touch=touch, is_mobile=touch, user_agent=UA, reduced_motion='reduce')
            ctx.add_cookies(cookie(new_session(key), dict({'qaredi_motion': 'reduce', 'qaredi_sidebar': 'open'}, **(extra or {}))))   # 카페 C5 — 펼친 셸(접힘 기본)
            return ctx

        # ─── [first] 첫 화면 종류 ──────────────────────────────────────────────────────────
        if want('first'):
            def first():
                summary = {}
                for width, touch in ((1440, False), (412, True)):
                    for label, pid, kind in (('project', proj['id'], 'project'), ('task', task['id'], 'task'), ('doc', plain['id'], 'doc')):
                        # ① 페이지 창구를 막은 채 — 스크립트가 화면을 바꿀 수 없는 상태 = 서버 HTML + CSS 그대로의 첫 그리기
                        ctx = context('employee', width, 900 if width > 800 else 915, touch=touch)
                        ctx.route(re.compile(r'.*/community/api/pages/' + pid + r'(\?.*)?$'), lambda route: route.abort())
                        pg = ctx.new_page()
                        pg.goto(site + '/community/p/' + pid, wait_until='domcontentloaded')
                        pg.wait_for_timeout(700)
                        parts = dict(pg.evaluate(PARTS_JS, DOC_PARTS))
                        k = pg.evaluate("document.body.getAttribute('data-page-kind')")
                        tf_ = pg.evaluate("(() => { const s = document.getElementById('task-full'); return s ? getComputedStyle(s).display : null; })()")
                        pg.screenshot(path=str(shots / ('first-%s-%d.png' % (label, width))))
                        summary['%s-%d-blocked' % (label, width)] = {'kind': k, 'parts': parts, 'task_full': tf_}
                        if kind == 'doc':
                            # R1(2026-10-15) — W1c(DESIGN 2.35)가 #page-doc 의 상자(.panel)를 걷어 내용이 오기 전에는 높이 0 이다 → 「보임」 대신
                            #   「문서 갈래로 그려져 있음(숨김 0 · display none 0)」을 본다. 머리(header.page-head)는 그대로 보여야 한다.
                            doc_drawn = pg.evaluate("(() => { const d = document.getElementById('page-doc'); return !!d && !d.hidden && getComputedStyle(d).display !== 'none' && !d.closest('[hidden]'); })()")
                            check(k == 'doc' and doc_drawn and parts['header.page-head:not(#project-head)'], 'first %d doc (control): data-page-kind=doc · document parts shown' % width)
                        else:
                            check(k == kind and not any(parts.values()), 'first %d %s (page API blocked = before any script switch): data-page-kind=%s · document parts 0 %s'
                                  % (width, label, kind, json.dumps([s for s, v in parts.items() if v])))
                        if kind == 'task':
                            check(tf_ not in (None, 'none'), 'first %d task: section#task-full rendered from the first paint (%s)' % (width, tf_))
                        ctx.close()
                        # ② 보통으로 열기 — 매 프레임 관찰
                        ctx = context('employee', width, 900 if width > 800 else 915, touch=touch)
                        ctx.add_init_script(WATCH_JS)
                        t = Tab(ctx)
                        t.page.goto(site + '/community/p/' + pid, wait_until='networkidle')
                        if kind == 'task':
                            t.page.wait_for_selector('#task-full .task-pane__title', timeout=15000)
                        if kind == 'project':
                            t.page.wait_for_selector('#project-list .task-row[data-task]', timeout=15000)
                        t.page.wait_for_timeout(600)
                        w = t.page.evaluate('window.__u4watch || null')
                        summary['%s-%d-watch' % (label, width)] = w
                        if kind != 'doc':
                            check(w is not None and w['frames'] > 5 and w['seen'] == {}, 'first %d %s normal load: document parts visible in 0 of %s frames %s'
                                  % (width, label, w['frames'] if w else '?', json.dumps(w['seen'] if w else None)))
                        check(t.clean('first-%s-%d' % (label, width)), 'first %d %s console 0 · page errors 0 · 5xx 0' % (width, label))
                        ctx.close()
                metrics['first'] = summary
            guard('first', first)

        # ─── [full] 작업 전체 화면 ─────────────────────────────────────────────────────────
        if want('full'):
            def full():
                ctx = context('employee', 1440)
                t = Tab(ctx)
                p = t.page
                t.go('/community/p/' + proj['id'] + '?task=' + task['id'])
                p.wait_for_selector('#task-pane:not([hidden]) #task-pane-title', timeout=15000)
                p.wait_for_timeout(800)
                side = p.evaluate(SIG_JS, '#task-pane')
                t.go('/community/p/' + task['id'])
                p.wait_for_selector('#task-full .task-pane__title', timeout=15000)
                p.wait_for_timeout(800)
                fullsig = p.evaluate(SIG_JS, '#task-full')
                metrics['full_sig'] = {'side': side, 'full': fullsig}
                # R1(2026-10-15) — W1(DESIGN 2.34) 이 「마감」 앞에 「시작 예정」 칸을 더했다
                check(fullsig['dt'] == ['담당자', '시작 예정', '마감', '프로젝트', '상태', '소유자', '볼 수 있는 곳'] and fullsig['note'] == ASSIGN_NOTE,
                      'full: properties (담당자 · 시작 예정 · 마감 · 프로젝트 · 상태 · 소유자 · 볼 수 있는 곳) + 맡김 안내 ' + json.dumps(fullsig['dt'], ensure_ascii=False))
                check(fullsig['heads'][:2] == ['설명', '하위 작업'] and any(h.startswith('파일 첨부') for h in fullsig['heads']) and fullsig['tabs'] == ['댓글', '모든 활동'] and fullsig['foot'] and fullsig['mention'],
                      'full: 설명 · 하위 작업 · 파일 첨부 · 댓글/모든 활동 탭 · 댓글 칸(멘션 부품) ' + json.dumps(fullsig, ensure_ascii=False))
                check(fullsig == side, 'full == side pane fingerprint (same draw · same parts)')
                st = p.evaluate("""() => { const s = document.getElementById('task-full'); const r = s.getBoundingClientRect(); const m = document.querySelector('.main').getBoundingClientRect();
                    const pad = parseFloat(getComputedStyle(document.querySelector('.main')).paddingLeft);
                    return {w: Math.round(r.width), l: Math.round(r.left - m.left), r: Math.round(m.right - r.right), pos: getComputedStyle(s).position, url: location.search,
                      open: document.body.hasAttribute('data-task-open'), x: s.querySelectorAll('[aria-label="닫기"]').length, fs: s.querySelectorAll('[aria-label="전체 화면으로 열기"]').length,
                      resize: s.querySelectorAll('.task-pane__resize, [data-pane-wide]').length, up: (s.querySelector('[data-task-up]') || {getAttribute: () => null}).getAttribute('href'),
                      aside: (() => { const a = document.getElementById('task-pane'); return a ? getComputedStyle(a).display : null; })(), editorBtn: !!document.querySelector('#page-edit:not([hidden])') && getComputedStyle(document.getElementById('page-edit')).display !== 'none'}; }""")
                parts = dict(p.evaluate(PARTS_JS, DOC_PARTS))
                metrics['full_box'] = st
                check(st['w'] <= 960 and abs(st['l'] - st['r']) <= 2 and st['pos'] == 'static', 'full: body width ≤ 960 centered · not fixed %s' % json.dumps(st))
                check(st['url'] == '' and not st['open'] and st['x'] == 0 and st['fs'] == 0 and st['resize'] == 0, 'full: ?task= 0 · data-task-open 0 · ✕ 0 · 「전체 화면으로 열기」 0 · 폭 손잡이 · 넓게 보기 0')
                check(st['up'] == '/community/p/' + proj['id'] and not any(parts.values()) and st['aside'] == 'none', 'full: 「‹ 프로젝트」 → project URL · document parts 0 · side aside hidden')
                p.screenshot(path=str(shots / 'full-1440.png'), full_page=True)
                # 모든 활동 탭
                p.locator('#task-full [role=tab]', has_text='모든 활동').click()
                p.wait_for_timeout(300)
                check(p.locator('#task-full .task-pane__activity .task-comment').count() >= 1 and p.locator('#task-full .task-pane__activity .task-event').count() >= 1,
                      'full: 「모든 활동」 = comments + change events in one stream')
                # 하위 작업 › = 그 작업의 전체 화면 주소
                go = p.locator('#task-full .task-row a.btn--icon[aria-label="하위 작업 열기"]').first.get_attribute('href')
                check(go == '/community/p/' + sub['id'], 'full: subtask › → /community/p/{subtask} (no ?task=) ' + str(go))
                # 「‹ 프로젝트」
                p.locator('#task-full [data-task-up]').click()
                p.wait_for_selector('#project-list .task-row[data-task]', timeout=15000)
                check(p.evaluate("location.pathname") == '/community/p/' + proj['id'] and p.evaluate("document.body.hasAttribute('data-project-view')"), 'full: 「‹ 프로젝트」 → project screen')
                # 프로젝트 「문서로 보기」 — 서버가 단 첫 화면 종류(project)도 떼어 옛 모양
                p.locator('#project-menu > summary').click()
                p.locator('#project-as-doc').click()
                p.wait_for_timeout(600)
                pj = p.evaluate("() => ({kind: document.body.getAttribute('data-page-kind'), view: document.body.hasAttribute('data-project-view')})")
                pparts = dict(p.evaluate(PARTS_JS, DOC_PARTS))
                check(pj == {'kind': None, 'view': False} and pparts['header.page-head:not(#project-head)'] and pparts['#page-doc'],
                      'project 「문서로 보기」 → data-page-kind · data-project-view removed · document parts back ' + json.dumps([pj, pparts]))
                # 「문서로 보기」 → 옛 모양
                t.go('/community/p/' + task['id'])
                p.wait_for_selector('#task-full .task-pane__title', timeout=15000)
                p.locator('#task-full summary[aria-label="더보기"]').click()
                p.locator('#task-full [data-task-as-doc]').click()
                p.wait_for_timeout(500)
                parts2 = dict(p.evaluate(PARTS_JS, DOC_PARTS))
                st2 = p.evaluate("() => ({kind: document.body.getAttribute('data-page-kind'), full: getComputedStyle(document.getElementById('task-full')).display, title: document.getElementById('page-title').textContent})")
                metrics['as_doc'] = {'parts': parts2, 'state': st2}
                check(st2['kind'] is None and st2['full'] == 'none' and parts2['header.page-head:not(#project-head)'] and parts2['#page-doc'] and parts2['#row-props-wrap'],
                      'full: 「문서로 보기」 → attribute removed · #task-full hidden · old document parts (head · row props · body) back ' + json.dumps(parts2))
                p.screenshot(path=str(shots / 'full-as-doc-1440.png'))
                check(t.clean('full'), 'full console 0 · page errors 0 · 5xx 0')
                ctx.close()
                # 412(터치) — 같은 모양 · 넘침 0
                ctx = context('employee', 412, 915, touch=True)
                t = Tab(ctx)
                p = t.page
                t.go('/community/p/' + task['id'])
                p.wait_for_selector('#task-full .task-pane__title', timeout=15000)
                p.wait_for_timeout(600)
                s412 = p.evaluate(SIG_JS, '#task-full')
                a = p.evaluate(AUDIT_JS, True)
                check(s412 == fullsig and a['overflow'] <= 0 and a['smallCount'] == 0, 'full 412: same parts · overflow 0 · tap targets ≥ 44 %s' % json.dumps(a['small'], ensure_ascii=False))
                p.screenshot(path=str(shots / 'full-412.png'), full_page=True)
                check(t.clean('full-412'), 'full 412 console 0')
                ctx.close()
            guard('full', full)

        # ─── [image] 편집 중 그림 · 크기 끌기 ────────────────────────────────────────────────
        IMG_JS = r"""(sel) => [...document.querySelectorAll(sel + ' .ProseMirror .doc-atom[data-atom="image"]')].map(d => {
            const i = d.querySelector('img.doc-image__img'); const f = d.querySelector('figure.doc-image'); const u = d.querySelector('.doc-upload');
            return {img: i ? i.getAttribute('src') : null, ok: !!(i && i.complete && i.naturalWidth > 0), cls: f ? [...f.classList].filter(c => c.startsWith('doc-image--')) : [],
                    up: u ? (u.querySelector('.doc-upload__progress') || {}).textContent : null, cap: (d.querySelector('.doc-image__caption, .doc-file__caption') || {}).textContent || null,
                    card: !!d.querySelector('.card__title') && !i && !u}; })"""
        if want('image'):
            def image():
                ctx = context('employee', 1440)
                t = Tab(ctx)
                p = t.page
                t.go('/community/p/' + task['id'])
                p.wait_for_selector('#task-full .task-pane__doc--editable', timeout=15000)
                p.locator('#task-full .task-pane__doc p').first.click()
                p.wait_for_selector('#task-full .ProseMirror', timeout=8000)
                until(p, "() => [...document.querySelectorAll('#task-full .ProseMirror img.doc-image__img')].filter(i => i.complete && i.naturalWidth > 0).length === 2", 10000)
                imgs = p.evaluate(IMG_JS, '#task-full')
                metrics['image_editor'] = imgs
                check(len(imgs) == 3 and imgs[0]['img'] == '/tbox/api/files/' + str(tfile) + '/preview/raw' and imgs[0]['ok'],
                      'image: editing — tbox: image drawn as the picture itself (img · preview/raw) ' + json.dumps(imgs[:1]))
                check(imgs[1]['img'] == '/community/api/pages/' + task['id'] + '/attachments/' + a_clean + '/raw' and imgs[1]['ok'] and imgs[1]['cls'] == ['doc-image--p40'],
                      'image: editing — att: image drawn (attachment raw) with width class p40')
                check(imgs[2]['img'] is None and imgs[2]['up'] == '검사 중이에요', 'image: editing — pending attachment → 「검사 중이에요」 box')
                check(all(i['cap'] == '설명을 더하려면 두 번 누르세요' for i in imgs) and p.locator('#task-full .ProseMirror .doc-atom[data-atom="image"] .card__title').count() == 0,
                      'image: no 「두 번 눌러 고치기」 card · caption hint line under each image')
                check(p.locator('#task-full .doc-image__resize').count() == 2 and p.locator('#task-full .doc-image__resize:visible').count() == 0, 'image: handles exist only on figures · hidden until selected')
                # 고르기 → 손잡이 · 끌기
                fig = p.locator('#task-full .ProseMirror .doc-atom[data-atom="image"]').nth(1)
                fig.locator('img').click()
                p.wait_for_timeout(200)
                h = fig.locator('.doc-image__resize')
                check(h.is_visible() and h.get_attribute('role') == 'slider' and h.get_attribute('aria-valuemin') == '10' and h.get_attribute('aria-valuemax') == '100'
                      and h.get_attribute('aria-valuenow') == '40', 'image: selected → handle visible (role=slider · aria-value 10/100/now 40)')
                hb = h.bounding_box()
                host_w = p.evaluate("() => document.querySelectorAll('#task-full .ProseMirror .doc-atom[data-atom=\"image\"]')[1].clientWidth")
                left = p.evaluate("() => document.querySelectorAll('#task-full .ProseMirror .doc-atom[data-atom=\"image\"]')[1].querySelector('figure').getBoundingClientRect().left")
                p.mouse.move(hb['x'] + hb['width'] / 2, hb['y'] + hb['height'] / 2)
                p.mouse.down()
                p.mouse.move(left + host_w * 0.5, hb['y'] + 10, steps=6)
                mid = p.evaluate("() => [[...document.querySelectorAll('#task-full .ProseMirror .doc-atom[data-atom=\"image\"]')[1].querySelector('figure').classList].filter(c => c.startsWith('doc-image--')), (document.querySelectorAll('#task-full .doc-image__size')[1] || {}).textContent]")
                p.mouse.move(left + host_w * 0.71, hb['y'] + 10, steps=6)
                p.mouse.up()
                p.wait_for_timeout(400)
                after = p.evaluate(IMG_JS, '#task-full')[1]
                metrics['image_drag'] = {'mid': mid, 'after': after, 'host_w': host_w}
                check(mid[0] == ['doc-image--p50'] and mid[1] == '50%', 'image: dragging → width class snaps to 5%% steps (p50 · label 「50%%」) %s' % json.dumps(mid))
                check(after['cls'] == ['doc-image--p70'], 'image: release at 71%% → doc-image--p70 %s' % json.dumps(after['cls']))
                p.keyboard.press('Alt+ArrowRight')
                p.wait_for_timeout(300)
                check(p.evaluate(IMG_JS, '#task-full')[1]['cls'] == ['doc-image--p75'], 'image: Alt+→ → +5%% (p75)')
                st_inline = p.evaluate("() => [...document.querySelectorAll('#task-full [style]')].length")
                check(st_inline == 0, 'image: inline style 0 while resizing (class only)')
                p.locator('#task-full .task-pane__section-head .btn', has_text='편집 마침').click()
                until(p, "() => !document.querySelector('#task-full .ProseMirror')", 20000)
                p.wait_for_timeout(500)
                b = server_blocks(task['id'])
                imgb = [x for x in b if x['type'] == 'image']
                check(imgb[1].get('width_pct') == 75 and 'width_pct' not in imgb[0], 'image: saved through the doc API — width_pct 75 (others untouched) ' + json.dumps([x.get('width_pct') for x in imgb]))
                rd = p.evaluate("() => [...document.querySelectorAll('#task-full .task-pane__doc figure.doc-image')].map(f => [...f.classList].filter(c => c.startsWith('doc-image--')))")
                check(rd[:2] == [['doc-image--wide'], ['doc-image--p75']], 'image: read view after save → p75 ' + json.dumps(rd))
                # 다시 열어도 같은 폭(읽기 · 편집)
                t.go('/community/p/' + task['id'])
                p.wait_for_selector('#task-full .task-pane__doc figure.doc-image', timeout=15000)
                rd2 = p.evaluate("() => [...document.querySelectorAll('#task-full .task-pane__doc figure.doc-image')].map(f => [...f.classList].filter(c => c.startsWith('doc-image--')))")
                p.locator('#task-full .task-pane__doc p').first.click()
                p.wait_for_selector('#task-full .ProseMirror', timeout=8000)
                p.wait_for_timeout(300)
                ed2 = p.evaluate(IMG_JS, '#task-full')
                check(rd2[1] == ['doc-image--p75'] and ed2[1]['cls'] == ['doc-image--p75'], 'image: reopened — same width in read view and editor (p75)')
                # 옛 넷 폭에서 Alt+← — 지금 그려진 폭에서 5% 줄인 값
                p.locator('#task-full .ProseMirror .doc-atom[data-atom="image"]').nth(0).locator('img').click()
                p.wait_for_timeout(150)
                est = p.evaluate("() => { const d = document.querySelectorAll('#task-full .ProseMirror .doc-atom[data-atom=\"image\"]')[0]; return Math.round(d.querySelector('figure').getBoundingClientRect().width / d.clientWidth * 100 / 5) * 5; }")
                p.keyboard.press('Alt+ArrowLeft')
                p.wait_for_timeout(300)
                check(p.evaluate(IMG_JS, '#task-full')[0]['cls'] == ['doc-image--p%d' % max(10, est - 5)], 'image: legacy width (wide) + Alt+← → measured %d%% − 5' % est)
                # 두 번 누르기 = 지금 창(설명 · 폭) — 끌어 맞춘 폭이 첫 항목
                p.locator('#task-full .ProseMirror .doc-atom[data-atom="image"]').nth(1).locator('img').dblclick()
                p.wait_for_selector('#atom-dialog[open]', timeout=5000)
                sel = p.evaluate("() => { const s = document.getElementById('atom-image-width'); return [s.value, s.options[s.selectedIndex].textContent]; }")
                check(sel == ['pct', '끌어서 맞춘 폭 75%'], 'image: double-click → existing dialog · width select keeps the dragged width ' + json.dumps(sel, ensure_ascii=False))
                p.keyboard.press('Escape')
                p.locator('#task-full .task-pane__section-head .btn', has_text='편집 마침').click()
                until(p, "() => !document.querySelector('#task-full .ProseMirror')", 20000)
                p.screenshot(path=str(shots / 'image-full-1440.png'), full_page=True)
                # 전체 편집기(일반 문서)도 같은 부품
                t.go('/community/p/' + plain['id'])
                p.wait_for_selector('#page-edit:not([hidden])', timeout=15000)
                p.locator('#page-edit').click()
                p.wait_for_selector('#page-editor .ProseMirror', timeout=8000)
                until(p, "() => [...document.querySelectorAll('#page-editor .ProseMirror img.doc-image__img')].filter(i => i.complete && i.naturalWidth > 0).length === 2", 10000)
                pe = p.evaluate(IMG_JS, '#page-editor')
                metrics['image_page_editor'] = pe
                check(len(pe) == 2 and pe[0]['img'] == '/tbox/api/files/' + str(tfile) + '/preview/raw' and pe[1]['img'] == '/community/api/pages/' + plain['id'] + '/attachments/' + p_att + '/raw'
                      and pe[0]['cls'] == ['doc-image--small'], 'image: full document editor — tbox: · att: drawn as pictures (same component)')
                p.locator('#page-editor .ProseMirror .doc-atom[data-atom="image"]').nth(1).locator('img').click()
                p.keyboard.press('Alt+ArrowRight')
                p.wait_for_timeout(200)
                c1 = p.evaluate(IMG_JS, '#page-editor')[1]['cls']
                p.screenshot(path=str(shots / 'image-page-editor-1440.png'))
                p.locator('#page-done').click()
                until(p, "() => !document.querySelector('#page-editor .ProseMirror')", 20000)
                p.wait_for_timeout(500)
                pb = [x for x in server_blocks(plain['id']) if x['type'] == 'image']
                check(len(c1) == 1 and c1[0].startswith('doc-image--p') and pb[1].get('width_pct') == int(c1[0][len('doc-image--p'):]), 'image: full editor Alt+→ → saved width_pct ' + json.dumps(c1))
                check(t.clean('image'), 'image console 0 · page errors 0 · 5xx 0')
                ctx.close()
            guard('image', image)

        # ─── [mention] 댓글 멘션 ──────────────────────────────────────────────────────────
        MARK_JS = r"""(sel) => { const f = document.querySelector(sel); if (!f) return null; const field = f.closest('.mention-field');
          const probe = (v) => { const s = document.createElement('span'); s.style.backgroundColor = 'var(' + v + ')'; document.body.appendChild(s); const c = getComputedStyle(s).backgroundColor; s.remove(); return c; };
          const help = field.nextElementSibling;
          return {ok: [...field.querySelectorAll('.mention-backdrop .mention-mark--ok')].map(m => [m.textContent, getComputedStyle(m).backgroundColor]),
                  miss: [...field.querySelectorAll('.mention-backdrop .mention-mark--miss')].map(m => [m.textContent, getComputedStyle(m).backgroundColor]),
                  accent: probe('--accent-soft'), danger: probe('--danger-soft'), help: help && help.matches('.help') && !help.hidden ? help.textContent : null,
                  list: [...field.querySelectorAll('.menu__list:not([hidden]) [data-mention]')].map(b => [b.getAttribute('data-mention'), b.getAttribute('aria-selected')]),
                  listShown: !!field.querySelector('.menu__list:not([hidden])'), empty: (field.querySelector('.menu__list:not([hidden]) > .help') || {}).textContent || null,
                  value: f.value, aligned: (() => { const b = field.querySelector('.mention-backdrop').getBoundingClientRect(); const r = f.getBoundingClientRect();
                    return Math.abs(b.left - r.left) < 1 && Math.abs(b.top - r.top) < 1 && Math.abs(b.width - r.width) < 1 && Math.abs(b.height - r.height) < 1; })()}; }"""

        def mention_flow(p, ta_sel, pid, tag, full_flow):
            res = {}
            ta = p.locator(ta_sel)
            ta.scroll_into_view_if_needed()
            ta.click()
            p.keyboard.type('@')
            until(p, "() => { const f = document.querySelector(%s); return !!f && !!f.closest('.mention-field').querySelector('.menu__list:not([hidden])'); }" % json.dumps(ta_sel), 8000)
            p.wait_for_timeout(200)
            m0 = p.evaluate(MARK_JS, ta_sel)
            api0 = cand_api(pid, '')
            res['open'] = m0['list']
            check([x[0] for x in m0['list']] == api0 and len(api0) >= 1 and m0['list'][0][1] == 'true', '%s: 「@」 → candidate list = server empty-q answer %s' % (tag, json.dumps(api0)))
            # R1(2026-10-15) — W1(2.34) 「시작 예정」 줄이 더해져 작업 칸의 댓글 칸(바닥 고정)이 창 맨 아래로 내려왔다. 아래 자리가 목록보다 작으면
            #   앵커 폴백(community.css `position-try-fallbacks: top span-right …`)이 칸 **바로 위**에 편다 — 창 안에 머묾(2.15). 그때만 위를 받는다.
            lst = p.evaluate("(s) => { const l = document.querySelector(s).closest('.mention-field').querySelector('.menu__list'); const r = l.getBoundingClientRect(); const t = document.querySelector(s).getBoundingClientRect(); return [Math.round(r.top - t.bottom), Math.round(r.left - t.left), getComputedStyle(l).position, l.getAttribute('role'), Math.round(t.top - r.bottom), Math.round(innerHeight - t.bottom - r.height)]; }", ta_sel)
            below = -2 <= lst[0] <= 12
            above = -2 <= lst[4] <= 12 and lst[5] < 12   # 아래 자리(창 바닥 - 칸 아래)가 목록 높이 + 여백보다 작을 때만
            check((below or above) and abs(lst[1]) <= 2 and lst[3] == 'listbox', '%s: floating list right below the field — or right above when the window has no room below (gap %d · above %d · room below - list %d · left %d · %s)' % (tag, lst[0], lst[4], lst[5], lst[1], lst[2]))
            p.keyboard.type('claude_man')
            p.wait_for_timeout(500)
            m1 = p.evaluate(MARK_JS, ta_sel)
            api1 = cand_api(pid, 'claude_man')
            check([x[0] for x in m1['list']] == api1 == [users['manager']['username']], '%s: typing narrows (= server q answer) %s' % (tag, json.dumps(api1)))
            p.keyboard.press('ArrowDown')
            p.keyboard.press('ArrowUp')
            p.keyboard.press('Enter')
            p.wait_for_timeout(400)
            m2 = p.evaluate(MARK_JS, ta_sel)
            check(m2['value'] == '@' + users['manager']['username'] + ' ' and not m2['listShown'], '%s: ↑↓ Enter → 「@claude_manager01 」 inserted · list closed %s' % (tag, json.dumps(m2['value'])))
            p.keyboard.type('안녕하세요 @nobody_u4x 그리고 ')
            p.wait_for_timeout(1600)
            m3 = p.evaluate(MARK_JS, ta_sel)
            res['marks'] = m3
            check(m3['ok'] == [['@' + users['manager']['username'], m3['accent']]] and m3['accent'] not in ('rgba(0, 0, 0, 0)', ''),
                  '%s: confirmed id → blue mark (--accent-soft %s)' % (tag, m3['accent']))
            check(m3['miss'] == [['@nobody_u4x', m3['danger']]] and m3['danger'] != m3['accent'], '%s: unknown id → red mark (--danger-soft %s)' % (tag, m3['danger']))
            label = users['manager']['display'] if users['manager']['display'] != users['manager']['username'] else users['manager']['username']
            check(m3['help'] is not None and '부를 사람: ' in m3['help'] and users['manager']['username'] in m3['help'] and '찾을 수 없음: @nobody_u4x' in m3['help'],
                  '%s: line below says it in words too %s' % (tag, json.dumps(m3['help'], ensure_ascii=False)))
            check(m3['aligned'], '%s: shadow layer exactly under the field (same box)' % tag)
            note = p.evaluate("(s) => { const f = document.querySelector(s); const box = f.closest('form'); return [...box.querySelectorAll('.help')].map(h => h.hidden ? null : h.textContent).filter(Boolean); }", ta_sel)
            check('찾을 수 없는 사람 1명 — 그 이름은 알림이 가지 않아요' in note, '%s: notice beside send 「찾을 수 없는 사람 1명 …」' % tag)
            if full_flow:
                # 토큰 안 캐럿 → 목록 다시 · 고르면 바꿈 — 부분 아이디(@claude_emp)를 고쳐 넣는다
                p.keyboard.type('@claude_emp ')
                p.wait_for_timeout(1600)
                v = p.evaluate("(s) => document.querySelector(s).value", ta_sel)
                pos = v.index('@claude_emp') + 4
                p.evaluate("([s, pos]) => { const f = document.querySelector(s); f.focus(); f.setSelectionRange(pos, pos); }", [ta_sel, pos])
                p.keyboard.press('ArrowRight')
                p.wait_for_timeout(600)
                m4 = p.evaluate(MARK_JS, ta_sel)
                check([x[0] for x in m4['list']] == cand_api(pid, 'claude_emp') and users['other']['username'] in [x[0] for x in m4['list']],
                      '%s: caret inside an @token → list reopens for that token %s' % (tag, json.dumps(m4['list'])))
                p.locator(ta_sel).locator('xpath=..').locator('[data-mention="' + users['other']['username'] + '"]').click()
                p.wait_for_timeout(1600)
                m5 = p.evaluate(MARK_JS, ta_sel)
                res['fix'] = m5
                check(('@' + users['other']['username'] + ' ') in m5['value'] and '@claude_emp ' not in m5['value'] and any(x[0] == '@' + users['other']['username'] for x in m5['ok']),
                      '%s: picking replaces the token → now confirmed (blue) %s' % (tag, json.dumps(m5['value'], ensure_ascii=False)))
                # 아무도 없는 토큰 — 목록 안 안내 문장
                p.evaluate("(s) => { const f = document.querySelector(s); f.focus(); f.setSelectionRange(f.value.indexOf('@nobody_u4x') + 3, f.value.indexOf('@nobody_u4x') + 3); }", ta_sel)
                p.keyboard.press('ArrowLeft')
                p.wait_for_timeout(600)
                m6 = p.evaluate(MARK_JS, ta_sel)
                check(m6['listShown'] and m6['list'] == [] and m6['empty'] == '부를 수 있는 사람이 없어요 — 이 문서를 볼 수 있는 사람만 부를 수 있어요', '%s: no candidate → message in the list' % tag)
                p.keyboard.press('Escape')
                p.wait_for_timeout(200)
                check(not p.evaluate(MARK_JS, ta_sel)['listShown'], '%s: Esc closes the list' % tag)
            return res

        if want('mention'):
            def mention():
                ctx = context('employee', 1440)
                t = Tab(ctx)
                p = t.page
                # ① 전체 화면 댓글(일반 문서 #comment-input — editor.js 가 같은 부품)
                t.go('/community/p/' + plain['id'])
                p.wait_for_selector('#comment-form:not([hidden]) #comment-input', timeout=15000)
                r1 = mention_flow(p, '#comment-input', plain['id'], 'doc comment', True)
                check(p.locator('#comment-mentions[role=listbox]').count() == 1 and p.locator('ul#comment-mentions').count() == 0, 'doc comment: old in-flow list replaced by the floating list (id kept)')
                p.screenshot(path=str(shots / 'mention-doc-1440.png'))
                with p.expect_response(lambda r: r.url.endswith('/api/pages/' + plain['id'] + '/comments') and r.request.method == 'POST', timeout=10000) as sent:
                    p.locator('#comment-submit').click()
                p.wait_for_timeout(800)
                after = p.evaluate(MARK_JS, '#comment-input')
                check(sent.value.status == 201 and after['value'] == '' and after['ok'] == [] and after['miss'] == [] and after['help'] is None,
                      'doc comment: sent (201 · send not blocked by the red token) → field · marks · line cleared')
                # ② 오른쪽 칸(프로젝트 ?task=)
                t.go('/community/p/' + proj['id'] + '?task=' + task2['id'])
                p.wait_for_selector('#task-pane:not([hidden]) .task-pane__foot textarea', timeout=15000)
                mention_flow(p, '#task-pane .task-pane__foot textarea', task2['id'], 'side pane', False)
                p.screenshot(path=str(shots / 'mention-pane-1440.png'))
                # ③ 작업 전체 화면 — 보내면 댓글 탭에
                t.go('/community/p/' + task['id'])
                p.wait_for_selector('#task-full .task-pane__foot textarea', timeout=15000)
                mention_flow(p, '#task-full .task-pane__foot textarea', task['id'], 'task full', True)
                with p.expect_response(lambda r: r.url.endswith('/api/pages/' + task['id'] + '/comments') and r.request.method == 'POST', timeout=10000) as sent:
                    p.locator('#task-full .task-pane__foot button[type=submit]').click()
                p.wait_for_timeout(1000)
                check(sent.value.status == 201 and p.evaluate(MARK_JS, '#task-full .task-pane__foot textarea')['value'] == ''
                      and p.locator('#task-full .task-pane__activity .task-comment', has_text='nobody_u4x').count() == 1, 'task full: comment sent → shown in 「댓글」 · field cleared')
                note_n = int(sql("SELECT count(*) FROM community.notifications WHERE user_id=" + str(uid('manager')) + " AND kind='mention'") or 0)
                check(note_n >= 1, 'mention: server still notifies the exact id (manager mention notifications %d)' % note_n)
                p.screenshot(path=str(shots / 'mention-full-1440.png'), full_page=True)
                check(t.clean('mention'), 'mention console 0 · page errors 0 · 5xx 0')
                ctx.close()
                # 412(터치) — 목록이 화면 안
                ctx = context('employee', 412, 915, touch=True)
                t = Tab(ctx)
                p = t.page
                t.go('/community/p/' + task['id'])
                p.wait_for_selector('#task-full .task-pane__foot textarea', timeout=15000)
                ta = p.locator('#task-full .task-pane__foot textarea')
                ta.scroll_into_view_if_needed()
                ta.tap()
                p.keyboard.type('@')
                until(p, "() => !!document.querySelector('#task-full .mention-field .menu__list:not([hidden]) [data-mention]')", 8000)
                box = p.evaluate("() => { const r = document.querySelector('#task-full .mention-field .menu__list').getBoundingClientRect(); return [r.left, r.right, innerWidth, document.documentElement.scrollWidth - innerWidth]; }")
                a = p.evaluate(AUDIT_JS, True)
                check(box[0] >= 0 and box[1] <= box[2] and box[3] <= 0 and a['smallCount'] == 0, 'mention 412: list inside the viewport · overflow 0 · tap targets ≥ 44 %s %s' % (json.dumps(box), json.dumps(a['small'], ensure_ascii=False)))
                p.screenshot(path=str(shots / 'mention-full-412.png'))
                check(t.clean('mention-412'), 'mention 412 console 0')
                ctx.close()
            guard('mention', mention)

        # ─── [readers] 보기 공유 · 손님 ───────────────────────────────────────────────────
        if want('readers'):
            def readers():
                for who_ in ('other', 'guest'):
                    ctx = context(who_, 1440)
                    t = Tab(ctx)
                    p = t.page
                    t.go('/community/p/' + task['id'])
                    p.wait_for_selector('#task-full .task-pane__title', timeout=15000)
                    p.wait_for_timeout(500)
                    can_c = call('GET', '/api/pages/' + task['id'], user=who_).get('can_comment') is True and who_ != 'guest'
                    p.locator('#task-full .task-pane__doc').click()
                    p.wait_for_timeout(400)
                    st = p.evaluate("() => ({pm: document.querySelectorAll('.ProseMirror').length, handle: document.querySelectorAll('.doc-image__resize').length, editable: document.querySelectorAll('.task-pane__doc--editable').length, foot: document.querySelectorAll('#task-full .task-pane__foot textarea').length, img: document.querySelectorAll('#task-full .task-pane__doc img.doc-image__img').length, kind: document.body.getAttribute('data-page-kind')})")
                    metrics['reader_' + who_] = st
                    check(st['kind'] == 'task' and st['pm'] == 0 and st['handle'] == 0 and st['editable'] == 0 and st['img'] == 2, 'readers %s: task full view · picture shown · editor 0 · handle 0 %s' % (who_, json.dumps(st)))
                    check(st['foot'] == (1 if can_c else 0), 'readers %s: comment field follows the current rule (can_comment=%s → %d)' % (who_, can_c, st['foot']))
                    t.go('/community/p/' + plain['id'])
                    p.wait_for_timeout(1200)
                    st2 = p.evaluate("() => ({edit: !!document.querySelector('#page-edit:not([hidden])'), form: !!document.querySelector('#comment-form:not([hidden])'), handle: document.querySelectorAll('.doc-image__resize').length})")
                    can_p = who_ != 'guest' and call('GET', '/api/pages/' + plain['id'] + '/comments', user=who_).get('can_comment') is True
                    check(not st2['edit'] and st2['handle'] == 0 and st2['form'] == can_p, 'readers %s doc: edit 0 · handle 0 · comment form = can_comment (%s) %s' % (who_, can_p, json.dumps(st2)))
                    check(t.clean('readers-' + who_), 'readers %s console 0' % who_)
                    ctx.close()
            guard('readers', readers)

        # ─── [matrix] 폭 여섯 × 밝게 · 어둡게 · 고대비 ──────────────────────────────────────
        if want('matrix'):
            def matrix():
                widths = [(360, False), (412, True), (820, False), (1199, False), (1280, False), (1440, False)]
                only_w = set(filter(None, os.environ.get('QF12U4_WIDTHS', '').split(',')))   # 고치는 동안 좁혀 돌리기(기본 = 전부)
                widths = [w for w in widths if not only_w or str(w[0]) in only_w]
                summary = {}
                for mode in [m for m in ('light', 'dark', 'hc') if not os.environ.get('QF12U4_MODES') or m in os.environ['QF12U4_MODES'].split(',')]:
                    sql('UPDATE users SET high_contrast=' + ('true' if mode == 'hc' else 'false') + ' WHERE id=' + str(emp))
                    for width, touch in widths:
                        tag = '%d%s-%s' % (width, 't' if touch else '', mode)
                        ctx = context('employee', width, 900 if width > 800 else 915, touch=touch, extra={'tbox_theme': 'dark' if mode == 'dark' else 'light'})
                        t = Tab(ctx)
                        p = t.page

                        def audit(state):
                            a = p.evaluate(AUDIT_JS, touch or width < 720)
                            summary[tag + '-' + state] = {'overflow': a['overflow'], 'small': a['small'], 'inline': a['inline'], 'wide': a['wide']}
                            check(a['overflow'] <= 0, 'matrix %s %s horizontal overflow 0 (%d %s)' % (tag, state, a['overflow'], json.dumps(a['wide'] if a['overflow'] > 0 else [])))
                            if touch or width < 720:
                                check(a['smallCount'] == 0, 'matrix %s %s tap targets ≥ 44 (%s)' % (tag, state, json.dumps(a['small'], ensure_ascii=False)))
                            check(a['inline'] == [], 'matrix %s %s inline style 0 %s' % (tag, state, json.dumps(a['inline'])))

                        def one():
                            t.go('/community/p/' + task['id'])
                            p.wait_for_selector('#task-full .task-pane__title', timeout=15000)
                            p.wait_for_timeout(400)
                            audit('read')
                            ta = p.locator('#task-full .task-pane__foot textarea')
                            ta.scroll_into_view_if_needed()
                            ta.click()
                            p.keyboard.type('@')
                            until(p, "() => !!document.querySelector('#task-full .mention-field .menu__list:not([hidden]) [data-mention]')", 8000)
                            audit('mention')
                            if mode != 'hc' and width in (412, 1440):
                                p.screenshot(path=str(shots / ('matrix-%s-mention.png' % tag)))
                            p.keyboard.press('Escape')
                            p.keyboard.press('Backspace')
                            p.locator('#task-full .task-pane__doc p').first.click()
                            p.wait_for_selector('#task-full .ProseMirror', timeout=8000)
                            p.locator('#task-full .ProseMirror .doc-atom[data-atom="image"]').nth(1).locator('img').click()
                            p.wait_for_timeout(300)
                            audit('edit-image')
                            if mode != 'hc' and width in (412, 1440):
                                p.screenshot(path=str(shots / ('matrix-%s-image.png' % tag)))
                        guard('matrix ' + tag, one)
                        if any((not c['pass']) and c['name'].startswith('matrix ' + tag + ' ') for c in checks):
                            try:
                                p.screenshot(path=str(shots / ('matrix-%s-fail.png' % tag)))
                            except Exception:   # noqa: BLE001 — 실패 사본은 편의
                                pass
                        check(t.clean('matrix-' + tag), 'matrix %s console 0 · page errors 0 · 5xx 0' % tag)
                        ctx.close()
                sql('UPDATE users SET high_contrast=false WHERE id=' + str(emp))
                metrics['matrix'] = summary
            guard('matrix', matrix)

        browser.close()
finally:
    (out / 'qf12-u4-browser-metrics.json').write_text(json.dumps(metrics, ensure_ascii=False, indent=1))
    (out / 'qf12-u4-browser-results.json').write_text(json.dumps(checks, ensure_ascii=False, indent=2))
    print('RESULT pass=' + str(sum(x['pass'] for x in checks)) + ' fail=' + str(sum(not x['pass'] for x in checks)), flush=True)
if any(not x['pass'] for x in checks):
    raise SystemExit(1)
