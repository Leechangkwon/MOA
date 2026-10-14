#!/usr/bin/env python3
"""사이트 H2 — 로그인 전 첫 화면 · 초대 요청 수락 시험 · 브라우저 (실제 PHP · PostgreSQL + Chromium · 격리 틀 memories_harness.py 만)

  python3 tests/community/memories_harness.py --suite invite_request_browser.py --output <결과 폴더>

합성 DB · 임시 복사본 · 로컬 php -S 만 친다(운영 · tbox_re · 시드 계정 0). 계정은 fixture 의 claude_ · UA tbox-review/<날짜>.
결과 파일에는 단언 이름 · 참/거짓 · 측정값만 — 세션 · CSRF · 확인 코드 0. 스크린샷은 결과 폴더 shots/.
지시서 docs/invite-request-directive.md 완료 기준 「브라우저」 · DESIGN 2.32.

  [same]    동의 전 · 로그인 뒤 런처의 서버 HTML 이 main 의 launcher.php 와 바이트 같음(같은 틀에서 둘 다 그림)
  [grid]    1440 · 1200 · 720 · 412 · 360 × 밝게 · 어둡게 · 고대비 — 로그인 전 첫 화면 ①~⑥ · 옛 블록 0 · 초대 요청 화면 · 요청 확인 화면 · 관리자 「초대 요청」 표:
            넘침 0 · 대비 4.5 · 콘솔 0 · 누름 자리 < 44 0 · 인라인 style 0
  [flow]    1440 · 412: 폼 → 완료 패널 · 복사(클립보드 = 주소) · 확인 화면(대기 · 반려 · 초대됨 → 가입하기 · 세 번 받음 · 주소 틀림)
  [admin]   1440 · 412: 표(열린 수 배지 · 이유 60자 → 누르면 전체) · 승인 창(팀 고르개) → 승인 · 반려 창(메모) → 반려 · 창 Esc · 초점 복귀
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
TB = site + '/tbox'
ONLY = set(filter(None, os.environ.get('H2_ONLY', '').split(',')))
shots = out / 'shots'
shots.mkdir(exist_ok=True)
metrics = {}
WIDTHS = [1440, 1200, 720, 412, 360]
MODES = ['light', 'dark', 'hc']


def check(ok, name):
    checks.append({'pass': bool(ok), 'name': name})
    print(('PASS ' if ok else 'FAIL ') + name, flush=True)
    return bool(ok)


def want(group): return not ONLY or group in ONLY


def guard(name, fn):
    try:
        fn()
    except Exception as error:  # noqa: BLE001
        check(False, name + ' raised ' + type(error).__name__ + ': ' + str(error).splitlines()[0][:200])


def uid(key): return int(users[key]['id'])
def lit(v): return "'" + str(v).replace("'", "''") + "'"


def new_session(key):
    token = secrets.token_hex(32)
    sql("INSERT INTO sessions(id,user_id,expires_at,ip,user_agent,reauth_at) VALUES('" + hashlib.sha256(token.encode()).hexdigest() + "'," + str(uid(key))
        + ",clock_timestamp()+interval '8 hours','127.0.0.1','" + UA + "',now())")
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

# 넘침 · 누름 자리 · 인라인 style — scope 안의 보이는 누르는 것(글 속 링크 = 문단 안 클래스 없는 a 는 4장대로 뺀다)
COMMON_JS = r"""(scope) => {
  const vis = el => { if (!el || !el.isConnected) return false; if (el.checkVisibility && !el.checkVisibility()) return false; const r = el.getBoundingClientRect(); return r.width > 0 && r.height > 0; };
  const root = document.querySelector(scope) || document.body;
  const inText = el => el.tagName === 'A' && !el.className && el.closest('p') && !el.closest('.help, .landing-hero');  // 글 속 링크(4장 — 누름 자리 규칙 밖)
  const hits = [...root.querySelectorAll('a, button, summary, input[type="checkbox"], select')].filter(vis).filter(el => !inText(el) && !el.closest('[data-trap]'))
    .map(el => { const t = el.matches('input[type="checkbox"]') ? (el.closest('.field__label') || el) : el; const r = t.getBoundingClientRect();
                 return {n: (el.getAttribute('aria-label') || el.textContent || el.id || el.tagName).trim().slice(0, 24), w: Math.round(r.width), h: Math.round(r.height)}; })
    .filter(x => x.w < 44 || x.h < 44);
  return {overflow: document.documentElement.scrollWidth - innerWidth, small: hits, inline: root.querySelectorAll('[style]').length};
}"""

LANDING_JS = r"""() => {
  const q = s => document.querySelector(s), qa = s => [...document.querySelectorAll(s)];
  const vis = el => { if (!el || !el.isConnected) return false; if (el.checkVisibility && !el.checkVisibility()) return false; const r = el.getBoundingClientRect(); return r.width > 0 && r.height > 0; };
  const top = el => el ? Math.round(el.getBoundingClientRect().top + scrollY) : null;
  const hdr = q('body > header'); const navLinks = hdr ? [...hdr.querySelectorAll('nav a')].map(a => ({t: a.textContent.trim(), href: a.getAttribute('href'), h: Math.round(a.getBoundingClientRect().height), bg: getComputedStyle(a).backgroundColor})) : [];
  const hero = q('.landing-hero'); const word = q('.landing-hero .home-wordmark'); const h1 = q('.landing-hero h1');
  const acts = hero ? [...hero.querySelectorAll('.landing-hero__actions a')].map(a => ({t: a.textContent.trim(), href: a.getAttribute('href'), cls: a.className, h: Math.round(a.getBoundingClientRect().height)})) : [];
  const joinLink = hero ? hero.querySelector('.help a') : null;
  const cards = qa('.landing-features .card'); const ctops = cards.map(c => Math.round(c.getBoundingClientRect().top));
  const perRow = ctops.filter(t => t === ctops[0]).length;
  const rowsH = {}; cards.forEach((c, i) => { (rowsH[ctops[i]] = rowsH[ctops[i]] || []).push(Math.round(c.getBoundingClientRect().height)); });
  const steps = qa('.landing-steps li'); const stops = steps.map(s => Math.round(s.getBoundingClientRect().top));
  const nums = qa('.landing-steps__num').map(n => [Math.round(n.getBoundingClientRect().width), Math.round(n.getBoundingClientRect().height)]);
  const trust = qa('.landing-trust li'); const ttops = trust.map(t => Math.round(t.getBoundingClientRect().top));
  const ticons = qa('.landing-trust li > svg').map(s => Math.round(s.getBoundingClientRect().width));
  const faq = qa('.landing-faq details');
  const stories = q('#home-stories');
  return {
    old: qa('.moa-service-hero, .moa-home-route, .moa-choose, .moa-home-tools, .moa-greeting, .moa-theme-button, #home-open-dashboard, .home-hero, .shell-tools').length,
    oldText: ['할 일부터 필요한 자료까지', '어디서 시작할지 고민이라면', '지금 하려는 일에 맞게', '처음 오셨나요', '화면 꾸미기', 'PC 비서 연결'].filter(t => document.body.innerText.includes(t)),
    navLinks, word: word ? {t: word.textContent, hidden: word.getAttribute('aria-hidden'), fs: getComputedStyle(word).fontSize} : null,
    h1: h1 ? {t: h1.textContent.trim(), fs: getComputedStyle(h1).fontSize, fw: getComputedStyle(h1).fontWeight, n: qa('h1').length} : null,
    lead: (q('.landing-hero__lead') || {}).textContent, leadFs: q('.landing-hero__lead') ? getComputedStyle(q('.landing-hero__lead')).fontSize : null, acts,
    join: joinLink ? {t: joinLink.textContent.trim(), href: joinLink.getAttribute('href'), h: Math.round(joinLink.getBoundingClientRect().height)} : null,
    featH2: (q('.landing-features h2') || {}).textContent, cards: cards.map(c => c.querySelector('h3').textContent), perRow, rowsH: Object.values(rowsH),
    cardIcons: qa('.landing-features__icon').map(i => [Math.round(i.getBoundingClientRect().width), Math.round(i.getBoundingClientRect().height)]),
    cardLinks: qa('.landing-features .card a').length, uses: cards.map(c => c.querySelectorAll('li').length),
    stepsH2: (q('.landing-steps h2') || {}).textContent, steps: steps.map(s => s.querySelector('h3').textContent), stepsRow: stops.filter(t => t === stops[0]).length, nums,
    trustH2: (q('.landing-trust h2') || {}).textContent, trust: trust.length, trustRow: ttops.filter(t => t === ttops[0]).length, ticons,
    faqH2: (q('.landing-faq h2') || {}).textContent, faq: faq.map(d => d.querySelector('summary').textContent.trim()), faqOpen: faq.filter(d => d.open).length,
    stories: stories ? {open: stories.open, sum: stories.querySelector('summary').textContent.trim().slice(0, 20)} : null, footer: !!q('footer.moa-footer .legal-links'),
    order: [top(hero), top(q('.landing-features')), top(q('.landing-steps')), top(q('.landing-trust')), top(q('.landing-faq')), top(stories)],
    signed: document.body.dataset.signed
  }; }"""


try:
    for u in users:
        users[u]['username'] = sql('SELECT username FROM users WHERE id=' + str(users[u]['id']))
    team_a = int(sql('SELECT team_id FROM users WHERE id=' + str(uid('employee'))))
    sql("INSERT INTO community.usage_guides(user_id,version,status,context) SELECT id,1,'dismissed',c FROM users, unnest(ARRAY['overview','threads']) c ON CONFLICT DO NOTHING")
    for u in users:
        call('GET', '/api/me/space', user=u)

    # ── 요청 시드 — 대기 · 반려 · 승인 · 세 번 받음 (확인 화면 상태 넷) · 긴 이유(60자 넘음) ─────────────────
    def seed(tag, reason, status_sql=None):
        code = secrets.token_hex(32)
        rid = int(sql("INSERT INTO invite_requests(name,org,reason,status_hash,ip) VALUES(" + lit('H2화면' + tag) + "," + lit('합성소속 ' + tag) + "," + lit(reason) + ","
                      + lit(hashlib.sha256(code.encode()).hexdigest()) + ",'10.7.0.1') RETURNING id").splitlines()[0])
        if status_sql:
            sql('UPDATE invite_requests SET ' + status_sql + ' WHERE id=' + str(rid))
        return code, rid
    LONG = '자료실과 업무 화면을 팀에서 함께 쓰려고 합니다. 매주 회의 자료를 올리고 작업을 나눠 맡을 예정이에요. 메신저로 바로 묻고 답하고 싶습니다.'
    C_OPEN, R_OPEN = seed('대기', LONG)
    C_OPEN2, R_OPEN2 = seed('대기2', '짧은 이유')
    C_REJ, R_REJ = seed('반려', '이유', "status='rejected', decided_by=" + str(uid('admin')) + ", decided_at=now(), decision_note='합성 메모'")
    C_APP, R_APP = seed('승인', '이유', "status='approved', decided_by=" + str(uid('admin')) + ", decided_at=now(), team_id=" + str(team_a))
    C_MAX, R_MAX = seed('세번', '이유', "status='approved', decided_by=" + str(uid('admin')) + ", decided_at=now(), issued_count=3")

    with sync_playwright() as pw:
        browser = pw.chromium.launch(executable_path=os.environ.get('CHROMIUM_EXECUTABLE'))

        def context(key, width, theme='light', session=None, clip=False):
            touch = width < 720
            ctx = browser.new_context(viewport={'width': width, 'height': 900 if width > 800 else 860}, has_touch=touch, is_mobile=touch, user_agent=UA,
                                      reduced_motion='reduce', color_scheme='dark' if theme == 'dark' else 'light')
            if clip:
                ctx.grant_permissions(['clipboard-read', 'clipboard-write'], origin=site)
            jar = []
            if key:
                jar.append({'name': '__Host-tbox_session', 'value': session or new_session(key), 'domain': host, 'path': '/', 'secure': True, 'httpOnly': True, 'sameSite': 'Lax'})
            for name, value in (('qaredi_motion', 'reduce'), ('tbox_theme', 'dark' if theme == 'dark' else 'light')):
                jar.append({'name': name, 'value': value, 'domain': host, 'path': '/'})
            ctx.add_cookies(jar)
            return ctx

        def contrast(p, sel, tag=''):
            vals = [(round(hh.evaluate(CONTRAST_JS), 2), hh) for hh in p.query_selector_all(sel) if hh.is_visible()]
            if vals:
                worst = min(vals, key=lambda x: x[0])
                if worst[0] < 4.5:
                    metrics['low-contrast-' + tag + '-' + str(len(metrics))] = {'ratio': worst[0], 'el': worst[1].evaluate("e => e.outerHTML.slice(0, 160)")}
            return [v for v, _ in vals]

        def hc(p, mode):
            # 로그인 전 화면에는 계정 취향이 없다 — 고대비는 셸과 같은 html 속성(DESIGN 2.16)을 직접 켜서 잰다
            if mode == 'hc':
                p.evaluate("document.documentElement.setAttribute('data-contrast','high')")
                p.wait_for_timeout(50)

        # ─── [same] 동의 전 · 로그인 뒤 런처의 서버 HTML = main ──────────────────────────────
        if want('same'):
            def same():
                base_src = subprocess.run(['git', '-C', str(WORKTREE), 'show', 'main:src/views/launcher.php'], check=True, capture_output=True).stdout
                mid = uid('manager')
                row = sql("INSERT INTO user_consents(user_id,doc,version,action,ip) VALUES(" + str(mid) + ",'terms','2000-01-01','agree','127.0.0.1') RETURNING id").splitlines()[0]
                try:
                    sess = {'live': new_session('employee'), 'consent': new_session('manager')}

                    def fetch():
                        outp = {}
                        for k, tok in sess.items():
                            r = requests.get(site + '/', headers={'User-Agent': UA, 'Cookie': '__Host-tbox_session=' + tok}, timeout=30)
                            outp[k] = r.content
                        r = requests.get(site + '/', headers={'User-Agent': UA}, timeout=30)
                        outp['out'] = r.content
                        return outp
                    new = fetch()
                    target = root / 'src/views/launcher.php'
                    mine = target.read_bytes()
                    target.write_bytes(base_src)
                    try:
                        old = fetch()
                    finally:
                        target.write_bytes(mine)
                    again = fetch()
                    # 뒤 묶음이 일부러 바꾼 로그인 뒤 갈래의 글자(그 묶음의 수락 시험이 잰다) — 견주기 전에 옛 쪽을 같은 글자로 바꾼다
                    LATER = [(b'href="/tbox/spaces"', b'href="/community/cafe"')]   # H3 — 홈 바로가기 「우리 공간」 = 카페
                    for k in ('live', 'consent'):
                        for a_, b_ in LATER:
                            old[k] = old[k].replace(a_, b_)
                    for k in ('live', 'consent'):
                        check(len(new[k]) > 3000 and new[k] == old[k] == again[k], '[same] %s launcher: server HTML byte-identical to main launcher.php (%d bytes)' % (k, len(new[k])))
                    check(new['out'] != old['out'] and b'landing-hero' in new['out'] and b'landing-hero' not in old['out'], '[same] signed-out launcher changed (landing) — %d → %d bytes' % (len(old['out']), len(new['out'])))
                    metrics['same'] = {k: [len(old[k]), len(new[k])] for k in new}
                finally:
                    sql('DELETE FROM user_consents WHERE id=' + row)
            guard('same', same)

        # ─── [grid] 로그인 전 첫 화면 · 초대 요청 · 요청 확인 · 관리자 표 ────────────────────
        if want('grid'):
            def grid():
                summary = {}
                for mode in MODES:
                    sql('UPDATE users SET high_contrast=' + ('true' if mode == 'hc' else 'false') + ' WHERE id=' + str(uid('admin')))
                    for width in WIDTHS:
                        tag = '%d-%s' % (width, mode)
                        phone = width < 720
                        ctx = context(None, width, 'dark' if mode == 'dark' else 'light')
                        t = Tab(ctx)
                        p = t.page
                        # 로그인 전 첫 화면
                        p.goto(site + '/', wait_until='networkidle')
                        hc(p, mode)
                        st = p.evaluate(LANDING_JS)
                        cm = p.evaluate(COMMON_JS, 'main')
                        summary['landing-' + tag] = {k: st[k] for k in ('perRow', 'rowsH', 'stepsRow', 'trustRow', 'order')}
                        summary['landing-' + tag].update(cm)
                        check(st['old'] == 0 and st['oldText'] == [] and st['signed'] == 'no', '⑥ %s: old blocks 0 · old copy 0 %s' % (tag, st['oldText']))
                        nl = st['navLinks']
                        check(len(nl) == 1 and nl[0]['t'] == '로그인' and nl[0]['href'] == '/tbox/login' and nl[0]['h'] == 44, '머리 %s: one 「로그인」 (44) %s' % (tag, json.dumps(nl, ensure_ascii=False)))
                        w = st['word'] or {}
                        check(w.get('t') == 'Qaredi' and w.get('hidden') == 'true' and w.get('fs') == ('44px' if phone else '64px'), '① %s: wordmark 「Qaredi」 %s' % (tag, w.get('fs')))
                        h1 = st['h1'] or {}
                        check(h1.get('t') == '우리 회사의 일과 자료를 한곳에서' and h1.get('fs') == ('28px' if phone else '40px') and h1.get('fw') == '700' and h1.get('n') == 1,
                              '① %s: h1 「우리 회사의 일과 자료를 한곳에서」 %s 700' % (tag, h1.get('fs')))
                        check(st['lead'] == '업무 · 자료실 · 메신저 · 우리 공간을 계정 하나로 — 초대받은 동료만 들어올 수 있어요.' and st['leadFs'] == '17px', '① %s: lead line 17px' % tag)
                        a = st['acts']
                        check(len(a) == 2 and a[0]['t'] == '로그인' and a[0]['href'] == '/tbox/login' and 'btn--quiet' not in a[0]['cls'] and a[1]['t'] == '초대 요청하기'
                              and a[1]['href'] == '/tbox/request-invite' and 'btn--quiet' in a[1]['cls'] and all(x['h'] == 44 for x in a), '① %s: 「로그인」(black) · 「초대 요청하기」(quiet) 44' % tag)
                        j = st['join'] or {}
                        check(j.get('href') == '/tbox/join' and j.get('t', '').startswith('초대 링크를 받았나요? 가입하기') and j.get('h', 0) >= 44, '① %s: small 「초대 링크를 받았나요? 가입하기 →」 /tbox/join' % tag)
                        want_cols = 4 if width >= 1200 else 2 if width >= 720 else 1
                        check(st['featH2'] == '가입하면 할 수 있는 일' and st['cards'] == ['업무', '자료실', '메신저', '우리 공간'] and st['perRow'] == want_cols
                              and all(len(set(r)) == 1 for r in st['rowsH']) and st['cardLinks'] == 0 and all(n >= 2 for n in st['uses']) and all(x == [40, 40] for x in st['cardIcons']),
                              '② %s: four cards · %d per row · equal heights · icon 40 · uses ≥2 · links 0 %s' % (tag, st['perRow'], st['rowsH']))
                        check(st['stepsH2'] == '이렇게 시작해요' and st['steps'] == ['초대 링크 받기', '가입하기', '내 홈에서 시작'] and st['stepsRow'] == (1 if phone else 3) and all(x == [32, 32] for x in st['nums']),
                              '③ %s: three steps %s · number circle 32' % (tag, 'vertical' if phone else 'in a row'))
                        check(st['trustH2'] == '안전하게 써요' and st['trust'] == 4 and st['trustRow'] == (1 if phone else 2) and all(x == 20 for x in st['ticons']),
                              '④ %s: four trust lines · %d columns · icon 20' % (tag, 1 if phone else 2))
                        check(st['faqH2'] == '자주 묻는 질문' and st['faq'] == ['초대 없이 가입할 수 있나요?', '회사 밖 사람도 쓸 수 있나요?', '내 자료는 누가 보나요?', 'AI 에 보내는 내용은?'] and st['faqOpen'] == 0,
                              '⑤ %s: four questions (closed)' % tag)
                        o = st['order']
                        check(st['stories'] and st['stories']['open'] is False and st['footer'] and all(o[i] is not None and o[i] < o[i + 1] for i in range(5)),
                              '⑥ %s: stories details collapsed below ①~⑤ · footer' % tag)
                        check(cm['overflow'] <= 0 and cm['small'] == [] and cm['inline'] == 0, '%s landing: overflow 0 · targets < 44 none · inline style 0 %s' % (tag, json.dumps(cm['small'], ensure_ascii=False)))
                        cr = contrast(p, 'body > header .moa-nav-join, .landing-hero h1, .landing-hero__lead, .landing-hero__actions a, .landing-hero .help a, .landing-features h2, .landing-features h3, '
                                         '.landing-features .help, .landing-features__label, .landing-features li, .landing-steps h3, .landing-steps p, .landing-steps__num, .landing-trust li span, '
                                         '.landing-faq summary, .landing-features > .help', tag)
                        summary['landing-' + tag]['contrast'] = min(cr) if cr else None
                        check(cr and min(cr) >= 4.5, '%s landing: contrast ≥ 4.5 (min %.2f)' % (tag, min(cr) if cr else -1))
                        if (width, mode) in ((1440, 'light'), (412, 'light'), (1440, 'dark'), (360, 'hc')):
                            p.screenshot(path=str(shots / ('landing-%s.png' % tag)), full_page=True)
                        # FAQ 하나 펼치면 답이 보인다
                        p.locator('.landing-faq summary').first.click()
                        check(p.evaluate("document.querySelector('.landing-faq details').open && document.querySelector('.landing-faq details > p').checkVisibility()"), '⑤ %s: a question opens to its answer' % tag)
                        # 초대 요청 화면
                        p.goto(TB + '/request-invite', wait_until='networkidle')
                        hc(p, mode)
                        fm = p.evaluate("""() => { const s = document.querySelector('.invite-request'); const r = s.getBoundingClientRect(); const trap = document.querySelector('[data-trap]');
                          const tr = trap.getBoundingClientRect();
                          return {w: Math.round(r.width), labels: [...s.querySelectorAll('.field__label')].map(x => x.textContent.trim()), trapVisible: tr.right > 0 && tr.bottom > 0 && tr.width > 2,
                                  trapHidden: trap.getAttribute('aria-hidden'), trapTab: document.getElementById('invite-request-website').tabIndex, trapAc: document.getElementById('invite-request-website').autocomplete,
                                  count: document.getElementById('invite-request-count').textContent, title: document.querySelector('h1').textContent}; }""")
                        cm = p.evaluate(COMMON_JS, 'main')
                        summary['form-' + tag] = dict(fm, **cm)
                        check(fm['w'] <= 480 and fm['title'] == '초대 요청' and fm['labels'][:3] == ['이름 (필수)', '소속 — 회사 · 팀 (필수)', '쓰려는 이유 (필수)'] and '(필수)' in fm['labels'][3] and fm['count'] == '0 / 500자',
                              'form %s: panel ≤ 480 · three fields + agree · counter' % tag)
                        check(not fm['trapVisible'] and fm['trapHidden'] == 'true' and fm['trapTab'] == -1 and fm['trapAc'] == 'off', 'form %s: honeypot off-screen · aria-hidden · tabindex -1 · autocomplete off' % tag)
                        check(cm['overflow'] <= 0 and cm['small'] == [] and cm['inline'] == 0, 'form %s: overflow 0 · targets < 44 none · inline 0 %s' % (tag, json.dumps(cm['small'], ensure_ascii=False)))
                        cr = contrast(p, '.invite-request h1, .invite-request .panel__sub, .invite-request .field__label, .invite-request .help, .invite-request .btn', tag)
                        check(cr and min(cr) >= 4.5, 'form %s: contrast ≥ 4.5 (min %.2f)' % (tag, min(cr) if cr else -1))
                        if (width, mode) in ((1440, 'light'), (412, 'light'), (412, 'dark')):
                            p.screenshot(path=str(shots / ('form-%s.png' % tag)), full_page=True)
                        # 요청 확인 화면(대기)
                        p.goto(TB + '/request-invite/status#code=' + C_OPEN2, wait_until='networkidle')
                        p.wait_for_function("document.getElementById('invite-status-text').textContent.startsWith('검토 중')", timeout=10000)
                        hc(p, mode)
                        cm = p.evaluate(COMMON_JS, 'main')
                        check(cm['overflow'] <= 0 and cm['small'] == [], 'status %s: overflow 0 · targets < 44 none %s' % (tag, json.dumps(cm['small'], ensure_ascii=False)))
                        cr = contrast(p, '#invite-status-text, .invite-request h1, .invite-request .panel__sub, .invite-request .help a', tag)
                        check(cr and min(cr) >= 4.5, 'status %s: contrast ≥ 4.5 (min %.2f)' % (tag, min(cr) if cr else -1))
                        check(t.clean('grid-out-' + tag), '%s signed-out pages: console 0 · 5xx 0' % tag)
                        ctx.close()
                        sql('DELETE FROM member_rate_limits')
                        # 관리자 표
                        ctx = context('admin', width, 'dark' if mode == 'dark' else 'light')
                        t = Tab(ctx)
                        p = t.page
                        p.goto(TB + '/users', wait_until='networkidle')
                        p.wait_for_selector('#invite-requests-body tr', timeout=15000)
                        p.wait_for_timeout(200)
                        ad = p.evaluate("""() => { const vis = el => el && el.checkVisibility && el.checkVisibility();
                          const panel = document.getElementById('invite-requests'); const inv = document.getElementById('member-invites');
                          const acts = [...document.querySelectorAll('#invite-requests-body td.actions .btn')].filter(vis).map(b => Math.round(b.getBoundingClientRect().right));
                          return {before: !!(inv && (panel.compareDocumentPosition(inv) & Node.DOCUMENT_POSITION_FOLLOWING)), badge: document.getElementById('invite-requests-open').textContent, rows: document.querySelectorAll('#invite-requests-body > tr').length,
                                  maxRight: acts.length ? Math.max(...acts) : 0, n: acts.length, contrastAttr: document.documentElement.getAttribute('data-contrast')}; }""")
                        cm = p.evaluate(COMMON_JS, '#invite-requests')
                        summary['admin-' + tag] = dict(ad, **cm)
                        check(ad['before'] and ad['badge'].startswith('열린 요청') and ad['rows'] >= 5 and ad['n'] >= 4 and ad['maxRight'] <= width and cm['overflow'] <= 0,
                              'admin %s: 「초대 요청」 above 「초대 링크」 · open badge · actions inside the screen · overflow 0' % tag)
                        check(cm['small'] == [] or not phone, 'admin %s: targets < 44 none on phones %s' % (tag, json.dumps(cm['small'], ensure_ascii=False)))
                        check(ad['contrastAttr'] == ('high' if mode == 'hc' else None), 'admin %s: data-contrast %s' % (tag, ad['contrastAttr']))
                        cr = contrast(p, '#invite-requests .panel__title, #invite-requests .panel__sub, #invite-requests-body td, #invite-requests-body .badge, #invite-requests-body .btn', tag)
                        check(cr and min(cr) >= 4.5, 'admin %s: contrast ≥ 4.5 (min %.2f)' % (tag, min(cr) if cr else -1))
                        if (width, mode) in ((1440, 'light'), (412, 'light')):
                            p.locator('#invite-requests').screenshot(path=str(shots / ('admin-%s.png' % tag)))
                        check(t.clean('grid-admin-' + tag), 'admin %s: console 0 · 5xx 0' % tag)
                        ctx.close()
                sql('UPDATE users SET high_contrast=false WHERE id=' + str(uid('admin')))
                metrics['grid'] = summary
            guard('grid', grid)

        # ─── [flow] 폼 → 완료 · 복사 · 확인 화면 상태 ──────────────────────────────────────────
        if want('flow'):
            def flow():
                for width in (1440, 412):
                    sql("UPDATE invite_requests SET created_at = created_at - interval '25 hours' WHERE ip='127.0.0.1'")
                    ctx = context(None, width, clip=True)
                    t = Tab(ctx)
                    p = t.page
                    p.goto(TB + '/request-invite', wait_until='networkidle')
                    p.locator('#invite-request-form button[type=submit]').click()
                    check(p.locator('#invite-request-msg').inner_text().startswith('이름'), 'flow %d: empty submit → message (no request)' % width)
                    p.fill('#invite-request-name', 'H2브라우저')
                    p.fill('#invite-request-org', '합성 회사 · 합성 팀')
                    p.fill('#invite-request-reason', '팀 자료를 함께 쓰려고요')
                    check(p.locator('#invite-request-count').inner_text() == '%d / 500자' % len('팀 자료를 함께 쓰려고요'), 'flow %d: counter follows typing' % width)
                    p.locator('#invite-request-form button[type=submit]').click()
                    check('처리방침' in p.locator('#invite-request-msg').inner_text(), 'flow %d: agree unchecked → message' % width)
                    p.check('#invite-request-agree')
                    p.locator('#invite-request-form button[type=submit]').click()
                    p.wait_for_selector('#invite-request-done:not([hidden])', timeout=10000)
                    url = p.input_value('#invite-request-url')
                    m = re.fullmatch(re.escape(site) + r'/tbox/request-invite/status#code=([0-9a-f]{64})', url)
                    check(m is not None and p.locator('#invite-request-form').is_hidden() and p.locator('#invite-request-done .ok-note').inner_text() == '요청했어요'
                          and '다시 보여 드릴 수 없어요' in p.locator('#invite-request-done .notice').inner_text(), 'flow %d: done panel 「요청했어요」 · address field · notice' % width)
                    check(sql("SELECT count(*) FROM invite_requests WHERE name='H2브라우저' AND org='합성 회사 · 합성 팀'") == str(1 if width == 1440 else 2), 'flow %d: one row stored' % width)
                    p.screenshot(path=str(shots / ('done-%d.png' % width)), full_page=True)
                    p.locator('#invite-request-copy').click()
                    p.wait_for_timeout(200)
                    clip = p.evaluate('navigator.clipboard.readText()')
                    check(clip == url and p.locator('#invite-request-copied').inner_text() == '복사했어요.', 'flow %d: 「복사」 → clipboard = the address' % width)
                    cm = p.evaluate(COMMON_JS, 'main')
                    check(cm['overflow'] <= 0 and cm['small'] == [], 'flow %d done: overflow 0 · targets < 44 none %s' % (width, cm['small']))
                    # 확인 화면 — 대기 · 반려 · 초대됨 · 세 번 · 틀림
                    states = [(url.split('#')[1], '검토 중이에요'), ('code=' + C_REJ, '이번에는 초대해 드리기 어려워요'), ('code=' + C_APP, '초대됐어요'),
                              ('code=' + C_MAX, '초대 링크를 이미 세 번 받았어요'), ('code=' + secrets.token_hex(32), '요청 확인 주소가 올바르지 않아요'), ('', '요청 확인 주소가 올바르지 않아요')]
                    for frag, text in states:
                        p.goto('about:blank')
                        p.goto(TB + '/request-invite/status' + ('#' + frag if frag else ''), wait_until='networkidle')
                        p.wait_for_function("!document.getElementById('invite-status-text').textContent.startsWith('확인하고')", timeout=10000)
                        got = p.locator('#invite-status-text').inner_text()
                        join = p.locator('#invite-status-join')
                        ok = got.startswith(text)
                        if text == '초대됐어요':
                            href = join.get_attribute('href') or ''
                            ok = ok and join.is_visible() and re.fullmatch(r'/tbox/join#invite=[0-9a-f]{64}', href) is not None
                            p.screenshot(path=str(shots / ('status-approved-%d.png' % width)), full_page=True)
                            join.click()
                            p.wait_for_url(re.compile(r'.*/tbox/join.*'), timeout=10000)
                            p.wait_for_timeout(300)
                            ok = ok and len(p.input_value('#join-invite')) == 64
                        elif text == '검토 중이에요':
                            p.screenshot(path=str(shots / ('status-open-%d.png' % width)), full_page=True)
                            ok = ok and join.is_hidden()
                        else:
                            ok = ok and join.is_hidden()
                        check(ok, 'flow %d status: %s → 「%s」' % (width, frag[:9] or '(no code)', text))
                    check(t.clean('flow-%d' % width), 'flow %d: console 0 · 5xx 0' % width)
                    ctx.close()
                    sql('DELETE FROM member_rate_limits')
            guard('flow', flow)

        # ─── [admin] 표 · 승인 창 · 반려 창 ─────────────────────────────────────────────────
        if want('admin'):
            def admin():
                for width in (412, 1440):   # 412 은 보기 · 창 열고 닫기만(결정 0) — 1440 이 승인 · 반려
                    ctx = context('admin', width)
                    t = Tab(ctx)
                    p = t.page
                    p.goto(TB + '/users', wait_until='networkidle')
                    p.wait_for_selector('#invite-requests-body tr', timeout=15000)
                    rows = p.locator('#invite-requests-body > tr')
                    long_row = p.locator('#invite-requests-body > tr').filter(has=p.locator('td:first-child', has_text=re.compile(r'^H2화면대기$')))
                    more = long_row.locator('button.btn--plain')
                    check(more.count() == 1 and len(more.inner_text()) == 61 and more.inner_text().endswith('…'), 'admin %d: long reason shows first 60 chars + …' % width)
                    more.click()
                    full = p.locator('#invite-requests-body > tr[data-invite-reason-row]')
                    check(full.count() == 1 and full.inner_text().strip() == LONG and more.get_attribute('aria-expanded') == 'true', 'admin %d: click → full reason in the row below' % width)
                    more.click()
                    check(full.count() == 0, 'admin %d: click again → collapsed' % width)
                    if width == 412:
                        cm = p.evaluate(COMMON_JS, '#invite-requests')
                        check(cm['overflow'] <= 0, 'admin 412: stacked rows overflow 0')
                        p.locator('#invite-requests').screenshot(path=str(shots / 'admin-412-stacked.png'))
                        # 창 열고 Esc · 초점 복귀
                        btn = long_row.locator('td.actions button', has_text='승인')
                        btn.click()
                        p.wait_for_timeout(150)
                        box = p.evaluate("(() => { const d = document.getElementById('invite-approve-dialog'); const r = d.getBoundingClientRect(); return {open: d.open, l: r.left, r: r.right}; })()")
                        check(box['open'] and box['l'] >= 0 and box['r'] <= width, 'admin 412: approve dialog inside the screen')
                        p.keyboard.press('Escape')
                        p.wait_for_timeout(150)
                        check(p.evaluate("!document.getElementById('invite-approve-dialog').open && document.activeElement && document.activeElement.textContent === '승인'"), 'admin 412: Esc closes · focus back to 「승인」')
                        check(t.clean('admin-412'), 'admin 412: console 0')
                        ctx.close()
                        continue
                    # 승인 — 팀 고르개
                    long_row.locator('td.actions button', has_text='승인').click()
                    p.wait_for_selector('#invite-approve-dialog[open]')
                    opts = p.evaluate("[...document.querySelectorAll('#invite-approve-team option')].map(o => o.textContent)")
                    check(opts[0] == '팀 없음' and '검증팀 A' in opts and '검증팀 B' in opts, 'admin 1440: approve dialog team picker %s' % opts)
                    check('H2화면대기' in p.locator('#invite-approve-lead').inner_text(), 'admin 1440: approve dialog names the request')
                    p.screenshot(path=str(shots / 'admin-approve-1440.png'))
                    p.select_option('#invite-approve-team', str(team_a))
                    p.locator('#invite-approve-form button[type=submit]').click()
                    p.wait_for_selector('#invite-requests-ok:not([hidden])', timeout=10000)
                    check(not p.evaluate("document.getElementById('invite-approve-dialog').open") and '승인했어요' in p.locator('#invite-requests-ok').inner_text(), 'admin 1440: approve → dialog closes · ok note')
                    check(sql('SELECT status || \'|\' || team_id FROM invite_requests WHERE id=' + str(R_OPEN)) == 'approved|%d' % team_a, 'admin 1440: request approved with the chosen team')
                    # 반려 — 메모
                    row2 = p.locator('#invite-requests-body > tr').filter(has=p.locator('td:first-child', has_text=re.compile(r'^H2화면대기2$')))
                    row2.locator('td.actions button', has_text='반려').click()
                    p.wait_for_selector('#invite-reject-dialog[open]')
                    p.fill('#invite-reject-note', '합성 반려 메모')
                    p.screenshot(path=str(shots / 'admin-reject-1440.png'))
                    p.locator('#invite-reject-form button[type=submit]').click()
                    p.wait_for_selector('#invite-requests-ok:has-text("반려했어요.")', timeout=10000)
                    check(sql("SELECT status || '|' || decision_note FROM invite_requests WHERE id=" + str(R_OPEN2)) == 'rejected|합성 반려 메모', 'admin 1440: reject with note stored')
                    p.wait_for_timeout(200)
                    st = p.evaluate("""() => { const tr = [...document.querySelectorAll('#invite-requests-body > tr')]; const f = t => tr.find(r => r.cells[0] && r.cells[0].textContent === t);
                       return {app: f('H2화면대기') && f('H2화면대기').querySelector('.badge').textContent, rej: f('H2화면대기2') && f('H2화면대기2').querySelector('.badge').textContent,
                               note: f('H2화면대기2') && f('H2화면대기2').textContent.includes('합성 반려 메모'), acts: f('H2화면대기2') && f('H2화면대기2').querySelectorAll('td.actions button').length}; }""")
                    check(st['rej'] == '반려' and st['note'] and st['acts'] == 0, 'admin 1440: list repainted · 「반려」 chip · note shown to the admin · actions gone %s' % json.dumps(st, ensure_ascii=False))
                    check(t.clean('admin-1440'), 'admin 1440: console 0')
                    ctx.close()
            guard('admin', admin)

        browser.close()
finally:
    (out / 'invite-request-browser-metrics.json').write_text(json.dumps(metrics, ensure_ascii=False, indent=1))
    (out / 'invite-request-browser-results.json').write_text(json.dumps(checks, ensure_ascii=False, indent=2))
    print('RESULT pass=' + str(sum(x['pass'] for x in checks)) + ' fail=' + str(sum(not x['pass'] for x in checks)), flush=True)
if any(not x['pass'] for x in checks):
    raise SystemExit(1)
