#!/usr/bin/env python3
"""사이트 H4 — 「모아.」 글자 로고 · 바닥 붙이기 수락 시험 (+ H5 사이드바 머리 · 앱 판 검색 타일) — 실제 PHP · PostgreSQL + Chromium · 격리 틀만

  python3 tests/community/memories_harness.py --suite home_h4_browser.py --output <결과 폴더>

합성 DB · 임시 복사본 · 로컬 php -S 만(운영 · 시드 계정 0) · fixture claude_ 계정 · UA tbox-review/<날짜>. 결과에는 단언 · 측정값만.
지시서 docs/invite-request-directive.md 「H4」(+ 「H5」) 완료 기준 · DESIGN 2.33(+ 2.19 ⑩ · 2.30 「앱 판」).

  [places] 모아 홈 · 로그인 전 첫 화면 · 머리 로고 · 바닥 · 로그인 · 가입 · 동의 · 초대 요청 · 법적 문서: 「모아.」(계산된 색 = --brand-wordmark · 점 = --brand-wordmark-dot) ·
           「Qaredi」 글자 0 · 테마 여섯을 바꿔도 색 그대로 · 고대비 --fg · 대비 4.5(밝게 · 어둡게 · 네 갈래 첫 색)
  [pick]   테마 창 「모아 글씨 색」 넷(미리보기) · 고르면 즉시 · 저장 → 새로 고침 · 다른 화면 첫 HTML data-wordmark · 엉뚱한 쿠키 → d · 쿠키 없음 → d ·
           C 두 색 · A · B 그라데이션 · 점은 D 만 · 닫으면(저장 전) 되돌림
  [foot]   1440×900 · 1920×1080 · 412×900: 짧은 화면은 바닥 아래 끝 = 창 아래 끝(±1) · 긴 화면은 내용 뒤 · 넘침 0 · 콘솔 0 · 스크린샷(홈 밝게 · 어둡게 · 1920 · 412)
  [h5]     (H5) 1440 · 1280 · 960 셸 다섯(자료실 · 업무 · 이야기 · 카페 · 관리) 사이드바 머리 보이는 글자 0 · ☰ + 아이콘 36 · 링크 = 그 앱 첫 화면 · aria-label 「… 첫 화면으로」 ·
           풍선 · 접힌 레일 · 412 윗줄 앱 이름 ▾ · 앱 판(셸 · 런처 · 폰) 검색 타일 0 · 사이드바 「검색」 · Ctrl K · 폰 돋보기 · 홈 검색 칸 그대로
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
ONLY = set(filter(None, os.environ.get('H4_ONLY', '').split(',')))
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
    except Exception as error:  # noqa: BLE001
        check(False, name + ' raised ' + type(error).__name__ + ': ' + str(error).splitlines()[0][:200])


def uid(key): return int(users[key]['id'])


def new_session(key, reauth=False):
    token = secrets.token_hex(32)
    sql("INSERT INTO sessions(id,user_id,expires_at,ip,user_agent,reauth_at) VALUES('" + hashlib.sha256(token.encode()).hexdigest() + "'," + str(uid(key))
        + ",clock_timestamp()+interval '8 hours','127.0.0.1','" + UA + "'," + ('now()' if reauth else 'NULL') + ")")
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


# (법적 문서 화면은 본문의 「모아(Qaredi)」 가 문서 이름 그대로라 머리 · 바닥 글자만 본다 — DESIGN 2.33 「Qaredi 글자」)
# 「모아.」 하나하나의 계산된 색 · 토큰 값 · 바탕 대비(첫 색) — 색 비교는 같은 요소에 토큰 색을 칠해 브라우저가 정규화한 값으로 견준다
WM_JS = r"""() => {
  const parse = (c) => { const m = String(c).match(/rgba?\(([^)]+)\)/); if (!m) return [0, 0, 0, 0]; const v = m[1].split(/[ ,/]+/).filter(Boolean).map(Number); return [v[0], v[1], v[2], v.length > 3 ? v[3] : 1]; };
  const lum = (rgb) => { const f = (x) => { x /= 255; return x <= 0.03928 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4); }; return 0.2126 * f(rgb[0]) + 0.7152 * f(rgb[1]) + 0.0722 * f(rgb[2]); };
  const over = (top, under) => { const a = top[3]; return [0, 1, 2].map(i => top[i] * a + under[i] * (1 - a)).concat(1); };
  const bgOf = (el) => { const layers = []; for (let n = el; n && n.nodeType === 1; n = n.parentElement) { const b = parse(getComputedStyle(n).backgroundColor); if (b[3] > 0) layers.push(b); if (b[3] >= 1) break; }
    let bg = [255, 255, 255, 1]; for (let i = layers.length - 1; i >= 0; i--) bg = over(layers[i], bg); return bg; };
  const probe = document.createElement('span'); document.body.appendChild(probe);
  const tok = (el, name) => { probe.style.color = ''; el.appendChild(probe); probe.style.color = 'var(' + name + ')'; const c = getComputedStyle(probe).color; probe.remove(); return c; };
  const out = [...document.querySelectorAll('.moa-wordmark')].filter(w => !w.closest('[data-wordmark-preview]') && w.getClientRects().length > 0).map(w => {
    const mo = w.querySelector('.moa-wordmark__mo'), a = w.querySelector('.moa-wordmark__a'), dot = w.querySelector('.moa-wordmark__dot');
    const first = tok(w, '--brand-wordmark'); const bg = bgOf(w); const l1 = lum(parse(first)), l2 = lum(bg);
    const where = w.closest('footer') ? 'footer' : w.closest('.moa-logo') ? 'logo' : w.closest('.home-wordmark') ? 'hero' : w.closest('.login__brand') ? 'login' : 'other';
    return {where, text: w.textContent, label: w.getAttribute('aria-label'), hidden: w.getAttribute('aria-hidden') || (w.closest('[aria-hidden="true"]') ? 'true' : null),
            mo: getComputedStyle(mo).color, a: getComputedStyle(a).color, moFill: getComputedStyle(mo).webkitTextFillColor, first, tokMo: tok(w, '--brand-wordmark-1'), tokA: tok(w, '--brand-wordmark-2'),
            dot: getComputedStyle(dot).color, dotTok: tok(w, '--brand-wordmark-dot'), dotShown: getComputedStyle(dot).display !== 'none', bgImage: getComputedStyle(w).backgroundImage,
            fg: tok(w, '--fg'), weight: getComputedStyle(w).fontWeight, size: getComputedStyle(w).fontSize, ratio: (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05)}; });
  probe.remove();
  return {marks: out, attr: document.documentElement.getAttribute('data-wordmark'), qaredi: (document.body.dataset.legalDoc ? [...document.querySelectorAll('body > header, body > footer')].map(e => e.innerText).join(' ') : document.body.innerText).includes('Qaredi'), overflow: document.documentElement.scrollWidth - innerWidth};
}"""

FOOT_JS = r"""() => { const f = document.querySelector('body > footer.moa-footer'); if (!f) return null; const r = f.getBoundingClientRect(); const m = document.querySelector('body > main');
  return {bottom: r.bottom + scrollY, top: r.top + scrollY, h: Math.round(r.height), w: Math.round(r.width), scrollH: document.documentElement.scrollHeight, inner: innerHeight,
          mainBottom: m ? m.getBoundingClientRect().bottom + scrollY : null, overflow: document.documentElement.scrollWidth - innerWidth, display: getComputedStyle(document.body).display}; }"""

try:
    for u in users:
        users[u]['username'] = sql('SELECT username FROM users WHERE id=' + str(users[u]['id']))
        call('GET', '/api/me/space', user=u)
    sql("INSERT INTO community.usage_guides(user_id,version,status,context) SELECT id,1,'dismissed',c FROM users, unnest(ARRAY['overview','threads']) c ON CONFLICT DO NOTHING")
    # 동의 전 계정(팀장 — 지난 판 약관 줄 하나)
    sql("INSERT INTO user_consents(user_id,doc,version,action,ip) VALUES(" + str(uid('manager')) + ",'terms','2000-01-01','agree','127.0.0.1')")

    # 서버 첫 HTML 의 data-wordmark — 쿠키 값마다
    def first_html():
        for value, want_v in (('a', 'a'), ('b', 'b'), ('c', 'c'), ('d', 'd'), ('zz', 'd'), ('A', 'd'), ('"><x', 'd'), (None, 'd')):
            got = []
            for path in ('/', '/tbox/join', '/tbox/login', '/tbox/privacy', '/tbox/request-invite'):
                h = {'User-Agent': UA}
                if value is not None:
                    h['Cookie'] = 'qaredi_wordmark=' + value
                r = requests.get(site + path, headers=h, timeout=30, allow_redirects=False)
                m = re.search(r'<html[^>]*\sdata-wordmark="([^"]*)"', r.text)
                got.append(m.group(1) if m else None)
            check(all(g == want_v for g in got), '[pick] first HTML data-wordmark for cookie %r → %s on / · join · login · privacy · request-invite %s' % (value, want_v, got))
        r = requests.get(site + '/', headers={'User-Agent': UA}, timeout=30)
        check('Qaredi' not in re.sub(r'<[^>]+>', ' ', r.text.split('<body', 1)[1]) if '<body' in r.text else False, '[places] signed-out launcher body text has no 「Qaredi」')
    if want('pick'):
        guard('first_html', first_html)

    with sync_playwright() as pw:
        browser = pw.chromium.launch(executable_path=os.environ.get('CHROMIUM_EXECUTABLE'))

        def context(key, width, height=None, theme='light', mark=None, reauth=False):
            touch = width < 720
            ctx = browser.new_context(viewport={'width': width, 'height': height or (900 if width > 800 else 900)}, has_touch=touch, is_mobile=touch, user_agent=UA,
                                      reduced_motion='reduce', color_scheme='dark' if theme == 'dark' else 'light')
            jar = [{'name': 'qaredi_motion', 'value': 'reduce', 'domain': host, 'path': '/'}, {'name': 'tbox_theme', 'value': 'dark' if theme == 'dark' else 'light', 'domain': host, 'path': '/'}]
            if key:
                jar.append({'name': '__Host-tbox_session', 'value': new_session(key, reauth), 'domain': host, 'path': '/', 'secure': True, 'httpOnly': True, 'sameSite': 'Lax'})
            if mark:
                jar.append({'name': 'qaredi_wordmark', 'value': mark, 'domain': host, 'path': '/'})
            ctx.add_cookies(jar)
            return ctx

        PLACES = [('home', 'employee', '/', {'hero', 'footer'}), ('landing', None, '/', {'hero', 'logo', 'footer'}), ('consent-launcher', 'manager', '/', {'logo', 'footer'}),
                  ('login', None, '/tbox/login', {'login'}), ('join', None, '/tbox/join', {'logo', 'footer'}), ('consent', 'manager', '/tbox/consent', {'logo', 'footer'}),
                  ('request', None, '/tbox/request-invite', {'logo', 'footer'}), ('status', None, '/tbox/request-invite/status', {'logo', 'footer'}), ('privacy', None, '/tbox/privacy', {'logo', 'footer'})]

        # ── [places] 자리 · 색 · 테마 · 고대비 · 대비 ────────────────────────────────────
        if want('places'):
            def places():
                summary = {}
                for theme in ('light', 'dark'):
                    for mark in ('d', 'a', 'b', 'c'):
                        for name, who, path, wheres in PLACES:
                            if mark != 'd' and name not in ('home', 'landing', 'login', 'join'):
                                continue
                            tag = '%s-%s-%s' % (name, theme, mark)
                            ctx = context(who, 1440, theme=theme, mark=mark)
                            t = Tab(ctx)
                            p = t.page
                            p.goto(site + path, wait_until='networkidle')
                            p.wait_for_timeout(150)
                            st = p.evaluate(WM_JS)
                            summary[tag] = {'where': [m['where'] for m in st['marks']], 'ratio': [round(m['ratio'], 2) for m in st['marks']], 'attr': st['attr']}
                            marks = st['marks']
                            got = {m['where'] for m in marks}
                            check(st['attr'] == mark and wheres <= got and not st['qaredi'] and st['overflow'] <= 0, '[places] %s: 「모아.」 at %s · 「Qaredi」 0 · overflow 0 (%s)' % (tag, sorted(wheres), sorted(got)))
                            ok_text = all(m['text'] == '모아.' and m['weight'] == '800' for m in marks)
                            ok_label = all((m['label'] == '모아') or m['hidden'] == 'true' for m in marks)
                            if mark in ('d', 'c'):
                                ok_col = all(m['mo'] == m['tokMo'] and m['a'] == m['tokA'] and m['bgImage'] == 'none' for m in marks)
                            else:
                                ok_col = all(m['moFill'] == 'rgba(0, 0, 0, 0)' and 'linear-gradient' in m['bgImage'] for m in marks)
                            ok_dot = all(m['dotShown'] == (mark == 'd') and (mark != 'd' or m['dot'] == m['dotTok']) for m in marks)
                            ok_c = mark != 'c' or all(m['mo'] != m['a'] for m in marks)
                            check(ok_text and ok_label and ok_col and ok_dot and ok_c, '[places] %s: text 「모아.」 800 · label · colors = tokens%s · dot only D' % (tag, ' · C two colors' if mark == 'c' else ' · gradient' if mark in ('a', 'b') else ''))
                            # 로그인 화면의 왼쪽 판은 사진 바탕이라 칠 값으로 잴 수 없다(어둡게 값을 쓴다 — 스크린샷으로 본다).
                            # 메인 검토(2026-10-14): B · C 밝게 값을 대비 4.5 이상으로 고침(DESIGN 2.33) — 네 갈래 모두 단언
                            rated = [m for m in marks if m['where'] != 'login']
                            check(all(m['ratio'] >= 4.5 for m in rated), '[places] %s: first color contrast ≥ 4.5 %s' % (tag, [round(m['ratio'], 2) for m in rated]))
                            if mark == 'd' and theme == 'light' and name in ('home', 'landing', 'join'):
                                # 테마 여섯을 바꿔도 같은 색
                                base_col = [(m['mo'], m['dot']) for m in marks]
                                same = True
                                for design in ('moa', 'claude', 'astra', 'aurora', 'bloom', 'nova'):
                                    p.evaluate('(d) => { document.documentElement.dataset.design = d; }', design)
                                    p.wait_for_timeout(60)
                                    now = [(m['mo'], m['dot']) for m in p.evaluate(WM_JS)['marks']]
                                    same = same and now == base_col
                                check(same, '[places] %s: six themes → wordmark colors unchanged' % tag)
                            check(t.clean('places-' + tag), '[places] %s: console 0' % tag)
                            ctx.close()
                # 고대비 — 셸 · 런처가 그리는 data-contrast(계정 취향)
                sql('UPDATE users SET high_contrast=true WHERE id=' + str(uid('employee')))
                for theme in ('light', 'dark'):
                    for mark in ('d', 'a', 'c'):
                        ctx = context('employee', 1440, theme=theme, mark=mark)
                        t = Tab(ctx)
                        p = t.page
                        p.goto(site + '/', wait_until='networkidle')
                        st = p.evaluate(WM_JS)
                        ok = all(m['mo'] == m['fg'] and m['a'] == m['fg'] and m['bgImage'] == 'none' and (not m['dotShown'] or m['dot'] == m['fg']) for m in st['marks'])
                        check(st['marks'] and ok, '[places] high contrast %s %s: wordmark = --fg single color' % (theme, mark))
                        ctx.close()
                sql('UPDATE users SET high_contrast=false WHERE id=' + str(uid('employee')))
                metrics['places'] = summary
            guard('places', places)

        # ── [pick] 테마 창 「모아 글씨 색」 ─────────────────────────────────────────────
        if want('pick'):
            def pick():
                ctx = context('employee', 1440)
                t = Tab(ctx)
                p = t.page
                p.goto(site + '/', wait_until='networkidle')
                p.locator('.shell-tools .shell-account > summary').click()
                p.locator('.shell-account .app-menu__app[data-design-open]').click()
                p.wait_for_selector('#q-design-dialog[open]')
                g = p.evaluate("""() => { const f = document.querySelector('#q-design-dialog .q-wordmark-picker'); if (!f) return null;
                    return {legend: f.querySelector('legend').textContent, options: [...f.querySelectorAll('input[name=q-wordmark]')].map(i => ({v: i.value, checked: i.checked,
                      text: i.closest('label').textContent.trim(), preview: !!i.closest('label').querySelector('[data-wordmark-preview] .moa-wordmark'),
                      dot: getComputedStyle(i.closest('label').querySelector('.moa-wordmark__dot')).display !== 'none',
                      fill: getComputedStyle(i.closest('label').querySelector('.moa-wordmark')).backgroundImage.includes('gradient')}))}; }""")
                metrics['pick'] = g
                check(g and g['legend'] == '모아 글씨 색' and [o['v'] for o in g['options']] == ['d', 'a', 'b', 'c'] and g['options'][0]['checked'] and all(o['preview'] for o in g['options']),
                      '[pick] theme dialog 「모아 글씨 색」 four radios (d checked) with previews')
                check(g and [o['dot'] for o in g['options']] == [True, False, False, False] and [o['fill'] for o in g['options']] == [False, True, True, False],
                      '[pick] previews: dot only D · gradient A · B (each in its own colors)')
                check(g and '(기본)' in g['options'][0]['text'], '[pick] D marked 「(기본)」')
                p.locator('#q-design-dialog input[name=q-wordmark][value=c]').check()
                now = p.evaluate("document.documentElement.dataset.wordmark")
                st = p.evaluate(WM_JS)
                check(now == 'c' and all(m['mo'] != m['a'] for m in st['marks']), '[pick] choosing C applies immediately (html data-wordmark=c · two colors)')
                p.keyboard.press('Escape')
                p.wait_for_timeout(150)
                check(p.evaluate("document.documentElement.dataset.wordmark") == 'd', '[pick] closing without saving restores D')
                p.locator('.shell-tools .shell-account > summary').click()
                p.locator('.shell-account .app-menu__app[data-design-open]').click()
                p.wait_for_selector('#q-design-dialog[open]')
                p.locator('#q-design-dialog input[name=q-wordmark][value=a]').check()
                p.locator('#q-design-save').click()
                p.wait_for_timeout(150)
                cookie = [c['value'] for c in ctx.cookies() if c['name'] == 'qaredi_wordmark']
                check(cookie == ['a'], '[pick] 「기본으로 저장」 writes cookie qaredi_wordmark=a (%s)' % cookie)
                p.keyboard.press('Escape')
                p.reload(wait_until='networkidle')
                check(p.evaluate("document.documentElement.dataset.wordmark") == 'a', '[pick] after reload html data-wordmark=a')
                for path in ('/tbox/', '/community/', '/tbox/privacy'):
                    r = p.request.get(site + path)
                    m = re.search(r'<html[^>]*\sdata-wordmark="([^"]*)"', r.text())
                    check(m and m.group(1) == 'a', '[pick] another screen %s first HTML data-wordmark=a' % path)
                p.screenshot(path=str(shots / 'home-wordmark-a-1440.png'))
                check(t.clean('pick'), '[pick] console 0')
                ctx.close()
            guard('pick', pick)

        # ── [foot] 바닥 붙이기 ─────────────────────────────────────────────────────────
        if want('foot'):
            def foot():
                summary = {}
                for (w, h) in ((1440, 900), (1920, 1080), (412, 900)):
                    for name, who, path in (('home', 'employee', '/'), ('join', None, '/tbox/join'), ('request', None, '/tbox/request-invite'), ('status', None, '/tbox/request-invite/status'),
                                            ('consent', 'manager', '/tbox/consent'), ('privacy', None, '/tbox/privacy'), ('landing', None, '/'), ('consent-launcher', 'manager', '/')):
                        for theme in (('light', 'dark') if name == 'home' and w == 1440 else ('light',)):
                            tag = '%s-%dx%d-%s' % (name, w, h, theme)
                            ctx = context(who, w, h, theme=theme)
                            t = Tab(ctx)
                            p = t.page
                            p.goto(site + path, wait_until='networkidle')
                            p.wait_for_timeout(250)
                            f = p.evaluate(FOOT_JS)
                            summary[tag] = f
                            if f is None:
                                check(False, '[foot] %s: footer missing' % tag)
                                ctx.close()
                                continue
                            short = f['scrollH'] <= f['inner'] + 1
                            ok = (abs(f['bottom'] - f['inner']) <= 1) if short else (abs(f['bottom'] - f['scrollH']) <= 1)
                            check(ok and f['top'] + 1 >= (f['mainBottom'] or 0) and f['overflow'] <= 0 and f['display'] == 'flex' and f['w'] <= 1200,
                                  '[foot] %s: %s — footer bottom %s · window %s · page %s · width %s · overflow 0' % (tag, 'short page → at the window bottom' if short else 'long page → after the content',
                                                                                                                   round(f['bottom']), f['inner'], f['scrollH'], f['w']))
                            if name == 'home':
                                check(short, '[foot] %s: home with the dashboard collapsed is a short page' % tag)
                                if (w, theme) in ((1440, 'light'), (1440, 'dark'), (1920, 'light'), (412, 'light')):
                                    p.screenshot(path=str(shots / ('home-%dx%d-%s.png' % (w, h, theme))))
                            if name in ('join', 'request') and w == 1440:
                                p.screenshot(path=str(shots / ('%s-%dx%d.png' % (name, w, h))), full_page=True)
                            check(t.clean('foot-' + tag), '[foot] %s: console 0' % tag)
                            ctx.close()
                metrics['foot'] = summary
            guard('foot', foot)

        # ── [h5] 사이트 H5 — 사이드바 머리 글자 0 · 앱 판 검색 타일 0 (DESIGN 2.19 ⑩ · 2.30) ─────────────────
        HEAD_JS = r"""() => { const b = document.querySelector('#sidebar > .brand'); if (!b) return null; const vis = e => e.getClientRects().length > 0 && getComputedStyle(e).display !== 'none' && getComputedStyle(e).visibility !== 'hidden';
          const link = b.querySelector('.moa-brand-home'); const mark = link ? link.querySelector('.brand__mark') : null; const r = b.getBoundingClientRect(); const mr = mark ? mark.getBoundingClientRect() : null;
          const texts = [...b.querySelectorAll('*')].filter(e => vis(e) && [...e.childNodes].some(n => n.nodeType === 3 && n.textContent.trim() !== '')).map(e => e.textContent.trim());
          return {texts, names: b.querySelectorAll('.brand__name, .brand__sub, .brand__text').length, href: link && link.getAttribute('href'), label: link && link.getAttribute('aria-label'),
                  linkVisible: !!link && vis(link), mark: mr ? [Math.round(mr.width), Math.round(mr.height)] : null, h: Math.round(r.height), menu: vis(document.getElementById('sidebar-collapse')),
                  rail: Math.round(document.getElementById('sidebar').getBoundingClientRect().width), collapsed: document.body.getAttribute('data-sidebar'),
                  tile: document.querySelectorAll('[data-search-tile]').length, overflow: document.documentElement.scrollWidth - innerWidth}; }"""
        if want('h5'):
            def h5():
                summary = {}
                SHELLS = [('library', 'employee', '/tbox/', '/tbox/', '자료실'), ('work', 'employee', '/community/', '/community/', '업무'),
                          ('story', 'employee', '/community/memories', '/community/memories', '이야기'), ('cafe', 'employee', '/community/cafe', '/community/cafe', '우리 공간'),
                          ('admin', 'admin', '/tbox/admin', '/tbox/', '자료실')]
                for width in (1440, 1280, 960):
                    for name, who, path, home, app in SHELLS:
                        tag = '%s-%d' % (name, width)
                        ctx = context(who, width, reauth=(who == 'admin'))
                        ctx.add_cookies([{'name': 'qaredi_sidebar', 'value': 'open', 'domain': host, 'path': '/'}])
                        t = Tab(ctx)
                        p = t.page
                        p.goto(site + path, wait_until='networkidle')
                        p.wait_for_timeout(250)
                        st = p.evaluate(HEAD_JS)
                        summary[tag] = st
                        check(st and st['texts'] == [] and st['names'] == 0 and st['menu'] and st['linkVisible'] and st['mark'] == [36, 36] and 52 <= st['h'] <= 60,
                              '[h5] %s: sidebar head = ☰ + app icon 36 · visible text 0 · row %s %s' % (tag, st and st['h'], json.dumps(st and st['texts'], ensure_ascii=False)))
                        check(st and st['href'] == home and st['label'] == app + ' 첫 화면으로', '[h5] %s: icon link → %s · aria-label 「%s 첫 화면으로」 (%s)' % (tag, home, app, st and st['label']))
                        p.hover('#sidebar .moa-brand-home')
                        p.wait_for_timeout(120)
                        tip = p.evaluate("(() => { const s = getComputedStyle(document.querySelector('#sidebar .moa-brand-home'), '::after'); return {c: s.content, d: s.display}; })()")
                        check(tip['d'] == 'block' and tip['c'].strip('"') == app + ' 첫 화면으로', '[h5] %s: hover → tooltip 「%s 첫 화면으로」' % (tag, app))
                        check(st and st['tile'] == 0 and st['overflow'] <= 0, '[h5] %s: app panel search tile 0 · overflow 0' % tag)
                        if width == 1440 and name in ('library', 'work'):
                            p.screenshot(path=str(shots / ('h5-%s-1440.png' % name)))
                        # 검색 길 — 사이드바 「검색」 · Ctrl K
                        p.locator('#nav-search').click()
                        p.wait_for_selector('#search-dialog[open]', timeout=5000)
                        p.keyboard.press('Escape')
                        p.wait_for_timeout(150)
                        p.keyboard.press('Control+k')
                        p.wait_for_timeout(250)
                        ok_k = p.evaluate("document.getElementById('search-dialog').open")
                        p.keyboard.press('Escape')
                        check(ok_k, '[h5] %s: sidebar 「검색」 and Ctrl K open the search dialog' % tag)
                        check(t.clean('h5-' + tag), '[h5] %s: console 0' % tag)
                        ctx.close()
                # 접힌 레일(쿠키 없음 — 기본 접힘) 그대로
                for name, who, path, home, app in SHELLS[:2]:
                    ctx = context(who, 1440)
                    t = Tab(ctx)
                    p = t.page
                    p.goto(site + path, wait_until='networkidle')
                    st = p.evaluate(HEAD_JS)
                    check(st['collapsed'] is None and st['rail'] == 240 and st['menu'] and st['linkVisible'] and st['texts'] == [], '[h5] %s collapsed rail unchanged (☰ only · width 64)' % name)   # Q:F-15 S2 (D-9) — 쿠키 없음 = 펼침(240 · 머리 아이콘 링크 보임)
                    ctx.close()
                # 폰 — 윗줄 앱 이름 ▾ · 돋보기 · 앱 판 검색 타일 0
                for name, who, path, home, app in SHELLS[:4]:
                    ctx = context(who, 412, 900)
                    t = Tab(ctx)
                    p = t.page
                    p.goto(site + path, wait_until='networkidle')
                    p.wait_for_timeout(200)
                    bar = p.evaluate("(() => { const b = document.getElementById('appbar-apps'); return b ? {t: b.textContent.trim(), v: b.getClientRects().length > 0} : null; })()")
                    want_bar = '우리 공간' if name == 'cafe' else '모아 ' + app
                    check(bar and bar['v'] and bar['t'] == want_bar, '[h5] 412 %s: top bar app name ▾ 「%s」 unchanged' % (name, want_bar))
                    p.locator('#appbar-apps').click()
                    p.wait_for_timeout(150)
                    check(p.evaluate("document.querySelectorAll('[data-search-tile]').length") == 0, '[h5] 412 %s: app panel search tile 0' % name)
                    p.keyboard.press('Escape')
                    p.locator('#appbar-search').click()
                    p.wait_for_selector('#search-dialog[open]', timeout=5000)
                    check(True, '[h5] 412 %s: magnifier opens the search dialog' % name)
                    p.keyboard.press('Escape')
                    check(t.clean('h5-412-' + name), '[h5] 412 %s: console 0' % name)
                    ctx.close()
                # 런처(모아 홈) — 앱 판 검색 타일 0 · 홈 검색 칸 그대로
                for width in (1440, 412):
                    ctx = context('employee', width, 900)
                    t = Tab(ctx)
                    p = t.page
                    p.goto(site + '/', wait_until='networkidle')
                    p.locator('.shell-tools > .appbar__apps > summary').click()
                    p.wait_for_timeout(150)
                    tiles = p.evaluate("[...document.querySelectorAll('.shell-tools .appbar__apps .app-menu__name')].map(e => e.textContent)")
                    check('검색' not in tiles and p.evaluate("document.querySelectorAll('[data-search-tile]').length") == 0, '[h5] home %d: app panel tiles without 「검색」 %s' % (width, json.dumps(tiles, ensure_ascii=False)))
                    p.keyboard.press('Escape')
                    p.fill('#home-search-input', 'H5')
                    p.keyboard.press('Enter')
                    p.wait_for_selector('#search-dialog[open]', timeout=5000)
                    check(True, '[h5] home %d: home search box Enter opens the search dialog' % width)
                    p.keyboard.press('Escape')
                    check(t.clean('h5-home-%d' % width), '[h5] home %d: console 0' % width)
                    ctx.close()
                metrics['h5'] = summary
            guard('h5', h5)

        browser.close()
finally:
    (out / 'home-h4-metrics.json').write_text(json.dumps(metrics, ensure_ascii=False, indent=1))
    (out / 'home-h4-results.json').write_text(json.dumps(checks, ensure_ascii=False, indent=2))
    print('RESULT pass=' + str(sum(x['pass'] for x in checks)) + ' fail=' + str(sum(not x['pass'] for x in checks)), flush=True)
if any(not x['pass'] for x in checks):
    raise SystemExit(1)
