#!/usr/bin/env python3
"""이야기 S1 — 스레드 화면 정리 수락 시험 (실제 PHP · PostgreSQL + Chromium · 격리 틀 memories_harness.py 만)

  python3 tests/community/memories_harness.py --port 8768 --suite story_s1_browser.py --output <결과 폴더>

합성 DB · 임시 복사본 · 로컬 php -S 만(운영 · 시드 계정 0) · fixture claude_ 계정 · UA tbox-review/<날짜>. 결과에는 단언 · 측정값만.
지시서 docs/story-s1-directive.md 「S1」 완료 기준 · DESIGN 2.36.

  [grid]  1440 · 1280 · 960 · 412 · 360 × 밝게 · 어둡게 · 고대비 × 팔로잉 · 나의 스레드 · 발견 · 남의 프로필 · 공유 스레드 공개 화면:
          .profile-cover 0 · .journal-aside 0 · 「A NOTE TO MYSELF」 「MEMORY STUDIO」 「YOUR DAYS」 글자 0(화면 · 창 밖 마크업) ·
          영문 eyebrow 0(창 밖) · 프로필 높이 ≤ 96(1440) · 숫자 다섯(공유 화면은 지금처럼 셋) 한 줄 · 내 것 단추 둘 · 남의 것 팔로우 ·
          화면 머리 · 탭 · 글쓰기 칸 · 프로필 · 피드 머리 · 도구 · 피드 카드의 왼끝 · 폭이 같은 열(≤ 680 · 가운데) · 넘침 0 · 대비 4.5 · 콘솔 0
  [act]   글쓰기 · 사진 이야기(사진 고르기 → 미리보기 → 저장) · AI로 꾸미기 창 · 좋아요 · 댓글 · 공유 · 팔로우 동작 그대로
  [shot]  나의 스레드 1440 밝게 · 어둡게 · 412
  [s1b]   댓글 — 창 없이 카드 안에서(DESIGN 2.36 · 지시서 S1b): dialog#comments-dialog 0(모든 [grid] 화면) · 댓글 아이콘 → 그 카드 아래 펼침
          (다른 카드 그대로) · 다시 → 접힘 · 최근 3 + 「이전 댓글 N개 더 보기」(불러 둔 것 → 커서 창구) · 빈 입력 「게시」 흐림 · 게시 → 끝에 붙음 ·
          입력 비움 · 초점 · 댓글 수 갱신 · Ctrl+Enter 게시 · 한글 조합 중 Enter 보내지 않음 · 실패 .msg · 읽기 전용 안내 · 폰 초점 0 ·
          공유 공개 화면 창 0(공개 댓글 창구 없음 — 지금과 같음) · 1440 · 412 넘침 0 · 콘솔 0
"""
from pathlib import Path
exec(compile(Path(__file__).with_name('memories_http.py').read_text().split('try:\n for u in users:')[0], __file__, 'exec'))
import secrets
from urllib.parse import urlsplit
from playwright.sync_api import sync_playwright
assert str(root).startswith('/tmp/qaredi-stage13-') and manifest['database'].startswith('tbox_memories_test_')
host = urlsplit(site).hostname
UA = 'tbox-review/' + time.strftime('%Y-%m-%d')
shots = out / 'shots'
shots.mkdir(exist_ok=True)
metrics = {}
WIDTHS = (1440, 1280, 960, 412, 360)
MODES = ('light', 'dark', 'hc')
BANNED = ('A NOTE TO MYSELF', 'MEMORY STUDIO', 'YOUR DAYS')


def check(ok, name):  # noqa: F811 — 실패해도 계속 재고 끝에 모아 알린다
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
            metrics['unclean-' + tag] = {'errors': [e[:200] for e in self.errors], 'bad': self.bad, 'console': [c[0][:200] for c in self.console]}
        return ok


def until(page, js, timeout=15000):
    end = time.time() + timeout / 1000
    while True:
        if page.evaluate(js):
            return True
        if time.time() > end:
            return False
        page.wait_for_timeout(100)


# 한 화면을 한 번에 잰다 — 열 · 프로필 · 글자 · 대비 · 넘침
MEASURE_JS = r"""(banned) => {
  const s = document.getElementById('community-threads');
  const vis = (e) => !!e && e.getClientRects().length > 0 && getComputedStyle(e).visibility !== 'hidden';
  const box = (e) => { const r = e.getBoundingClientRect(); return {l: Math.round(r.left * 10) / 10, w: Math.round(r.width * 10) / 10, t: Math.round(r.top), h: Math.round(r.height * 10) / 10}; };
  const outside = s.cloneNode(true); outside.querySelectorAll('dialog, .symbol-library').forEach((d) => d.remove());
  const screenText = outside.textContent;
  const shown = document.body.innerText;
  const eyebrows = [...s.querySelectorAll('.eyebrow')].filter((e) => !e.closest('dialog')).map((e) => e.textContent);
  const parts = {};
  for (const [k, sel] of [['hero', '#hero'], ['tabs', '.threads-tabs'], ['quick', '.threads-quick'], ['write', '.threads-write'], ['profile', '#profile-panel'],
                          ['head', '.feed-head'], ['tools', '.feed-tools'], ['card', '#feed .post'], ['column', '.main-columns']]) {
    const e = s.querySelector(sel); if (vis(e)) parts[k] = box(e);
  }
  const sec = s.getBoundingClientRect();
  const stats = [...document.querySelectorAll('#profile-stats > *')].filter(vis).map((e) => ({t: Math.round(e.getBoundingClientRect().top), text: e.textContent}));
  const btn = (id) => vis(document.getElementById(id));
  // 대비 — 캔버스로 색을 정규화하고, 바탕은 위로 올라가며 겹친다
  const cv = document.createElement('canvas').getContext('2d');
  const rgba = (c) => { cv.fillStyle = '#000'; cv.fillStyle = c; const v = cv.fillStyle; if (v[0] === '#') return [parseInt(v.slice(1, 3), 16), parseInt(v.slice(3, 5), 16), parseInt(v.slice(5, 7), 16), 1];
    const m = v.match(/[\d.]+/g).map(Number); return [m[0], m[1], m[2], m.length > 3 ? m[3] : 1]; };
  const over = (top, bot) => [0, 1, 2].map((i) => top[i] * top[3] + bot[i] * (1 - top[3])).concat([1]);
  const bgOf = (e) => { const layers = []; for (let n = e; n; n = n.parentElement) { const c = rgba(getComputedStyle(n).backgroundColor); if (c[3] > 0) layers.push(c); if (c[3] >= 1) break; }
    let acc = [255, 255, 255, 1]; for (const c of layers.reverse()) acc = over(c, acc); return acc; };
  const lum = (c) => { const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }; return 0.2126 * f(c[0]) + 0.7152 * f(c[1]) + 0.0722 * f(c[2]); };
  const ratio = (e) => { const b = bgOf(e), f = over(rgba(getComputedStyle(e).color), b); const a = lum(f), c = lum(b); return (Math.max(a, c) + 0.05) / (Math.min(a, c) + 0.05); };
  const contrast = [];
  for (const sel of ['#hero h1', '#hero .hero-sub', '#today', '.threads-tabs button', '.threads-write > span:nth-child(2)', '#profile-title', '#profile-description',
                     '#profile-stats > *', '#profile-stats strong', '.profile-actions .secondary', '#feed-title', '.feed-tools .chip']) {
    for (const e of s.querySelectorAll(sel)) if (vis(e) && !e.closest('dialog')) contrast.push({sel, r: Math.round(ratio(e) * 100) / 100});
  }
  return {sec: {l: sec.left, w: sec.width}, parts, stats, eyebrows, banned: banned.filter((b) => screenText.includes(b) || shown.includes(b)),
    commentsDialog: document.querySelectorAll('#comments-dialog').length, cover: document.querySelectorAll('.profile-cover').length, aside: document.querySelectorAll('.journal-aside').length,
    btns: {follow: btn('follow-profile'), settings: btn('profile-settings'), travel: [...document.querySelectorAll('#profile-panel [data-travel-open]')].some(vis)},
    title: (document.getElementById('profile-title') || {}).textContent, minContrast: contrast.length ? Math.min(...contrast.map((c) => c.r)) : null,
    lowContrast: contrast.filter((c) => c.r < 4.5), overflow: document.documentElement.scrollWidth - innerWidth,
    hc: document.documentElement.getAttribute('data-contrast'), dark: document.documentElement.getAttribute('data-theme')};
}"""

try:
    for u in users:
        users[u]['username'] = sql('SELECT username FROM users WHERE id=' + str(users[u]['id']))
        call('GET', '/api/me/space', user=u)
    sql("INSERT INTO community.usage_guides(user_id,version,status,context) SELECT id,1,'dismissed',c FROM users, unnest(ARRAY['overview','threads']) c ON CONFLICT DO NOTHING")
    me, friend = users['employee']['username'], users['other']['username']
    # 합성 기록 — 내 것(링크 공유 + 사진 · 커뮤니티 발견) · 남의 것(커뮤니티 발견 + 사진) · 팔로우 하나
    mine_photo = save(dict(payload(), caption='S1 내 사진 기록', media_ids=[upload()['id']], visibility='shared'))
    mine_text = save(dict(payload(), caption='S1 내 글 기록', visibility='community', discoverable=True))
    other_post = save(dict(payload(), caption='S1 친구 사진 기록', media_ids=[upload(user='other')['id']], visibility='community', discoverable=True), user='other')
    call('POST', '/api/memories/follow', {'username': friend})
    link = call('POST', '/api/memories/links', {}, expected=201)['path']
    SHARED = site + link
    VIEWS = [('feed', base + '/memories', 'employee'), ('mine', base + '/memories?view=mine', 'employee'), ('discover', base + '/memories?view=discover', 'employee'),
             ('other', base + '/memories/u/' + friend, 'employee'), ('shared', SHARED, None)]

    with sync_playwright() as pw:
        browser = pw.chromium.launch(executable_path=os.environ.get('CHROMIUM_EXECUTABLE'))

        def context(key, width, theme='light'):
            touch = width < 720
            ctx = browser.new_context(viewport={'width': width, 'height': 900 if width > 800 else 915}, has_touch=touch, is_mobile=touch, user_agent=UA, locale='ko-KR',
                                      reduced_motion='reduce', color_scheme='dark' if theme == 'dark' else 'light')
            cookies = [{'name': 'qaredi_motion', 'value': 'reduce', 'domain': host, 'path': '/'},
                       {'name': 'tbox_theme', 'value': 'dark' if theme == 'dark' else 'light', 'domain': host, 'path': '/'}]
            if key:
                cookies.append({'name': '__Host-tbox_session', 'value': new_session(key), 'domain': host, 'path': '/', 'secure': True, 'httpOnly': True, 'sameSite': 'Lax'})
            ctx.add_cookies(cookies)
            return ctx

        def open_view(t, url):
            t.page.goto(url, wait_until='networkidle')
            until(t.page, "() => document.getElementById('feed-status').hidden || document.querySelectorAll('#feed .post').length > 0")
            t.page.wait_for_timeout(150)

        # ── [grid] ───────────────────────────────────────────────────────────
        def grid():
            for mode in MODES:
                sql('UPDATE users SET high_contrast=' + ('true' if mode == 'hc' else 'false'))
                for name, url, who in VIEWS:
                    for width in WIDTHS:
                        tag = '%s-%d-%s' % (name, width, mode)
                        ctx = context(who, width, 'dark' if mode == 'dark' else 'light')
                        t = Tab(ctx)
                        p = t.page
                        open_view(t, url)
                        m = p.evaluate(MEASURE_JS, list(BANNED))
                        metrics[tag] = m
                        check(m['cover'] == 0 and m['aside'] == 0 and m['banned'] == [] and m['eyebrows'] == [] and m['commentsDialog'] == 0,
                              '[grid] %s: .profile-cover 0 · .journal-aside 0 · banned text 0 · eyebrow 0 · dialog#comments-dialog 0 %s' % (tag, json.dumps([m['banned'], m['eyebrows']], ensure_ascii=False)))
                        parts = m['parts']
                        col = parts.get('column')
                        want = {'feed': ['hero', 'tabs', 'quick', 'head', 'tools', 'card'], 'discover': ['hero', 'tabs', 'quick', 'head', 'tools', 'card'],
                                'mine': ['tabs', 'quick', 'profile', 'head', 'tools', 'card'], 'other': ['tabs', 'quick', 'profile', 'head', 'tools', 'card'],
                                'shared': ['profile', 'head', 'tools', 'card']}[name]
                        same = col is not None and all(k in parts and abs(parts[k]['l'] - col['l']) <= 1 and abs(parts[k]['w'] - col['w']) <= 1 for k in want) \
                            and 'write' in parts and abs(parts['write']['l'] - col['l']) <= 1
                        centred = col is not None and col['w'] <= 680.5 and abs((col['l'] - m['sec']['l']) - (m['sec']['l'] + m['sec']['w'] - col['l'] - col['w'])) <= 1.5
                        if name == 'shared':
                            same = col is not None and all(k in parts and abs(parts[k]['l'] - col['l']) <= 1 and abs(parts[k]['w'] - col['w']) <= 1 for k in want)
                        check(same and centred, '[grid] %s: one centred column ≤ 680 — %s share left/width %s' % (tag, '·'.join(want), json.dumps({k: parts.get(k) for k in want + ['column', 'write']})))
                        if name in ('mine', 'other', 'shared'):
                            n = 3 if name == 'shared' else 5
                            tops = {s['t'] for s in m['stats']}
                            check(len(m['stats']) == n and len(tops) == 1, '[grid] %s: %d stats on one line %s' % (tag, n, json.dumps(m['stats'], ensure_ascii=False)))
                            h = parts.get('profile', {}).get('h', 999)
                            if width == 1440:
                                check(h <= 96, '[grid] %s: profile height %.1f ≤ 96' % (tag, h))
                            b = m['btns']
                            ok = {'mine': b['settings'] and b['travel'] and not b['follow'], 'other': b['follow'] and not b['settings'] and not b['travel'],
                                  'shared': not any(b.values())}[name]
                            check(ok, '[grid] %s: profile buttons %s' % (tag, json.dumps(b)))
                        check(m['overflow'] <= 0, '[grid] %s: horizontal overflow 0 (%s)' % (tag, m['overflow']))
                        check(m['minContrast'] is not None and not m['lowContrast'], '[grid] %s: text contrast ≥ 4.5 (min %s %s)' % (tag, m['minContrast'], json.dumps(m['lowContrast'])))
                        if name != 'shared':
                            check(m['hc'] == ('high' if mode == 'hc' else None), '[grid] %s: contrast mode applied' % tag)
                        if name == 'mine' and ((width == 1440 and mode in ('light', 'dark')) or (width == 412 and mode == 'light')):
                            p.screenshot(path=str(shots / ('mine-%d-%s.png' % (width, mode))), full_page=True)
                        check(t.clean(tag), '[grid] %s: console 0' % tag)
                        ctx.close()
            sql('UPDATE users SET high_contrast=false')
        guard('grid', grid)

        # ── [act] 기능 그대로 ───────────────────────────────────────────────────
        def actions():
            ctx = context('employee', 1440)
            t = Tab(ctx)
            p = t.page
            open_view(t, base + '/memories?view=mine')
            # 글쓰기
            p.click('.threads-write')
            p.wait_for_selector('#compose-dialog[open]', timeout=8000)
            p.fill('#caption', 'S1 글쓰기 칸으로 남김')
            p.click('#publish')
            p.wait_for_selector('#compose-dialog:not([open])', state='attached', timeout=15000)
            until(p, "() => document.getElementById('feed').textContent.includes('S1 글쓰기 칸으로 남김')")
            check(sql("SELECT count(*) FROM community.memory_posts WHERE caption='S1 글쓰기 칸으로 남김'") == '1' and 'S1 글쓰기 칸으로 남김' in p.text_content('#feed'),
                  '[act] composer: top write box → dialog → saved row · shown in feed')
            # 사진 이야기 — 글쓰기 창에서 사진 고르기 → 미리보기 → 저장 → 카드 사진
            p.click('.threads-write')
            p.wait_for_selector('#compose-dialog[open]', timeout=8000)
            p.fill('#caption', 'S1 사진 이야기')
            p.set_input_files('#media-input', {'name': '합성.png', 'mimeType': 'image/png', 'buffer': png})
            ok_prev = until(p, "() => document.querySelector('#media-preview img')?.naturalWidth > 0")
            p.click('#publish')
            p.wait_for_selector('#compose-dialog:not([open])', state='attached', timeout=15000)
            until(p, "() => [...document.querySelectorAll('#feed .post')].some(c => c.textContent.includes('S1 사진 이야기') && c.querySelector('img'))")
            check(ok_prev and sql("SELECT count(*) FROM community.memory_posts p JOIN community.memory_media m ON m.post_id=p.id WHERE p.caption='S1 사진 이야기'") == '1',
                  '[act] photo story: pick photo → preview → saved with media')
            # AI로 꾸미기 — 내 글의 단추가 꾸미기 창을 연다
            card = p.locator('#feed .post').filter(has_text='S1 사진 이야기').first
            card.get_by_role('button', name='AI로 꾸미기').click()
            p.wait_for_selector('#studio-dialog[open]', timeout=8000)
            check(p.is_visible('#gemini-generate') and p.is_visible('#studio-title'), '[act] AI로 꾸미기 opens the studio dialog')
            p.click('[data-close="studio-dialog"]')
            p.wait_for_selector('#studio-dialog:not([open])', state='attached', timeout=8000)
            # 좋아요
            card = p.locator('#memory-' + mine_photo)
            card.locator('[data-like-post]').click()
            until(p, "() => document.querySelector('#memory-%s [data-like-post]').getAttribute('aria-pressed') === 'true'" % mine_photo)
            check(sql("SELECT count(*) FROM community.memory_likes WHERE post_id='" + mine_photo + "'") == '1' and card.locator('[data-like-count]').text_content() == '좋아요 1개',
                  '[act] like: pressed · row · count')
            # 댓글(S1b — 카드 안 펼침)
            card.locator('[data-comment-post]').click()
            card.locator('.post-comments textarea').fill('S1 댓글')
            card.locator('.post-comments [type="submit"]').click()
            until(p, "() => document.querySelector('#memory-%s .post-comments').textContent.includes('S1 댓글')" % mine_photo)
            check(sql("SELECT count(*) FROM community.memory_comments WHERE post_id='" + mine_photo + "'") == '1', '[act] comment: posted · row · listed')
            card.locator('[data-comment-post]').click()
            # 공유
            card.locator('.share-post').click()
            p.wait_for_selector('#share-dialog[open]', timeout=8000)
            until(p, "() => document.getElementById('share-url').value.includes('/memories/s/')")
            check('/memories/s/' in p.input_value('#share-url'), '[act] share: dialog with link')
            p.click('[data-close="share-dialog"]')
            # 공개 범위 설정 · 여행 계획 단추
            p.click('#profile-settings')
            p.wait_for_selector('#privacy-dialog[open]', timeout=8000)
            check(True, '[act] 「공개 범위 설정」 opens the privacy dialog')
            p.click('[data-close="privacy-dialog"]')
            p.locator('#profile-panel [data-travel-open]').click()
            ok_travel = until(p, "() => !!document.querySelector('dialog.travel-dialog[open]')", 8000)
            check(ok_travel, '[act] 「여행 계획」 opens the travel dialog')
            if ok_travel:
                p.locator('[data-travel-close]').first.click()
            check(t.clean('act-mine'), '[act] mine: console 0')
            # 팔로우 — 남의 프로필(이미 팔로우 중 → 끊고 다시)
            open_view(t, base + '/memories/u/' + friend)
            follow = p.locator('#follow-profile')
            before = follow.text_content()
            follow.click()
            until(p, "() => document.getElementById('follow-profile').textContent !== %s" % json.dumps(before))
            mid_rows = sql("SELECT count(*) FROM community.memory_follows WHERE follower_user_id=%d AND followed_user_id=%d" % (uid('employee'), uid('other')))
            follow.click()
            until(p, "() => document.getElementById('follow-profile').textContent === %s" % json.dumps(before))
            end_rows = sql("SELECT count(*) FROM community.memory_follows WHERE follower_user_id=%d AND followed_user_id=%d" % (uid('employee'), uid('other')))
            check(before == '팔로잉' and mid_rows == '0' and end_rows == '1' and follow.text_content() == '팔로잉', '[act] follow: 팔로잉 → 팔로우 → 팔로잉 · rows 1→0→1')
            check(t.clean('act-other'), '[act] other profile: console 0')
            ctx.close()
        guard('actions', actions)

        # ── [s1b] 댓글 — 창 없이 카드 안에서 ────────────────────────────────────
        SEC = "#memory-%s .post-comments"

        def shown_comments(p, pid):
            return p.evaluate("(sel) => [...document.querySelectorAll(sel + ' .comment p')].map(n => n.textContent)", SEC % pid)

        def n_comments(p, pid, n):
            return until(p, "() => document.querySelectorAll(%s).length === %d" % (json.dumps(SEC % pid + ' .comment'), n))

        def last_is(p, pid, text):
            return until(p, "() => { const c = [...document.querySelectorAll(%s)]; return c.length > 0 && c.at(-1).textContent === %s; }" % (json.dumps(SEC % pid + ' .comment p'), json.dumps(text)))

        SECTION_JS = r"""(id) => { const s = document.querySelector('#memory-' + id + ' .post-comments'), ta = s.querySelector('textarea'), b = s.querySelector('[type=submit]');
            return {open: !s.hidden, others: [...document.querySelectorAll('.post-comments')].filter(n => n !== s && !n.hidden).length, dialogs: document.querySelectorAll('dialog[open]').length,
              inCard: !!s.closest('#memory-' + id), expanded: document.querySelector('#memory-' + id + ' [data-comment-post]').getAttribute('aria-expanded'),
              older: (s.querySelector(':scope > .text-button:not([hidden])') || {}).textContent || null, focus: !!ta && document.activeElement === ta,
              placeholder: ta ? ta.placeholder : null, rows: ta ? ta.rows : null, value: ta ? ta.value : null, post: b ? b.textContent : null, disabled: b ? b.disabled : null,
              label: document.querySelector('#memory-' + id + ' [data-comment-post]').getAttribute('aria-label'),
              count: (document.querySelector('#memory-' + id + ' [data-comment-count]') || {}).textContent || null}; }"""

        def s1b():
            many = save(dict(payload(), caption='S1b 댓글 다섯', visibility='community', discoverable=True))
            lots = save(dict(payload(), caption='S1b 댓글 서른다섯', visibility='community', discoverable=True))
            sql("INSERT INTO community.memory_comments(id,post_id,user_id,body,created_at) SELECT md5('s1b-five-'||g)::uuid,'%s',%d,'다섯 '||g,clock_timestamp()-(10-g)*interval '1 minute' FROM generate_series(1,5) g" % (many, uid('other')))
            sql("INSERT INTO community.memory_comments(id,post_id,user_id,body,created_at) SELECT md5('s1b-lots-'||g)::uuid,'%s',%d,'많이 '||g,clock_timestamp()-(40-g)*interval '1 minute' FROM generate_series(1,35) g" % (lots, uid('other')))

            def rows(pid):
                return sql("SELECT count(*) FROM community.memory_comments WHERE post_id='" + pid + "'")
            ctx = context('employee', 1440)
            t = Tab(ctx)
            p = t.page
            open_view(t, base + '/memories?view=mine')
            card = p.locator('#memory-' + many)
            icon = card.locator('[data-comment-post]')
            check(p.locator('.post-comments:not([hidden])').count() == 0 and icon.get_attribute('aria-expanded') == 'false', '[s1b] cards start collapsed (aria-expanded false)')
            icon.click()
            n_comments(p, many, 3)
            st = p.evaluate(SECTION_JS, many)
            metrics['s1b-open'] = st
            check(st['open'] and st['inCard'] and st['others'] == 0 and st['dialogs'] == 0 and st['expanded'] == 'true',
                  '[s1b] icon → that card expands in place · other cards unchanged · dialog 0 %s' % json.dumps(st, ensure_ascii=False))
            check(shown_comments(p, many) == ['다섯 3', '다섯 4', '다섯 5'] and st['older'] == '이전 댓글 2개 더 보기', '[s1b] latest 3 in order + 「이전 댓글 2개 더 보기」 (%s)' % st['older'])
            check(st['focus'] and st['placeholder'] == '댓글 달기…' and st['rows'] == 1 and st['post'] == '게시' and st['disabled'] is True,
                  '[s1b] one-line 「댓글 달기…」 focused on open (desktop) · 「게시」 dim when empty')
            sec = p.locator(SEC % many)
            sec.locator(':scope > .text-button').click()
            n_comments(p, many, 5)
            check(shown_comments(p, many) == ['다섯 %d' % i for i in range(1, 6)] and sec.locator(':scope > .text-button').is_hidden(), '[s1b] 「더 보기」 shows all 5 in order · button gone')
            ta, btn = sec.locator('textarea'), sec.locator('[type=submit]')
            ta.fill('   ')
            check(btn.is_disabled(), '[s1b] whitespace only → 「게시」 stays disabled')
            ta.press('Enter')
            p.wait_for_timeout(400)
            check(rows(many) == '5', '[s1b] empty Enter posts nothing (rows 5)')
            ta.fill('S1b 게시 단추')
            check(btn.is_enabled(), '[s1b] text → 「게시」 enabled')
            btn.click()
            last_is(p, many, 'S1b 게시 단추')
            st = p.evaluate(SECTION_JS, many)
            check(rows(many) == '6' and shown_comments(p, many)[-1] == 'S1b 게시 단추' and st['value'] == '' and st['focus'] and st['disabled'] is True
                  and st['label'] == '댓글 6개 보기' and st['count'] == '댓글 6개', '[s1b] 「게시」 → appended at the end · input cleared · focus kept · count 6 %s' % json.dumps(st, ensure_ascii=False))
            # 한글 조합 중 Enter — 보내지 않는다
            ta.fill('조합 중인 글')
            ta.evaluate("(n) => n.dispatchEvent(new KeyboardEvent('keydown', {key: 'Enter', code: 'Enter', isComposing: true, keyCode: 229, bubbles: true, cancelable: true}))")
            p.wait_for_timeout(500)
            check(rows(many) == '6' and ta.input_value() == '조합 중인 글', '[s1b] Enter during IME composition does not post')
            ta.press('Control+Enter')
            last_is(p, many, '조합 중인 글')
            check(rows(many) == '7' and ta.input_value() == '', '[s1b] Ctrl+Enter posts · appended last · rows 7')
            ta.fill('첫 줄')
            ta.press('Shift+Enter')
            ta.type('둘째 줄')
            check(rows(many) == '7' and ta.evaluate('(n) => n.rows') == 2, '[s1b] Shift+Enter = new line (grows to 2 rows, no post)')
            ta.fill('')
            # 실패 → 줄 아래 .msg (이 브라우저의 요청 하나만 가로막는다 — 창구는 그대로)
            route = '**/api/memories/%s/comments' % many
            p.route(route, lambda r: r.fulfill(status=429, content_type='application/json', body=json.dumps({'error': '댓글을 잠시 쉬었다가 남겨 주세요.'}, ensure_ascii=False))
                    if r.request.method == 'POST' else r.continue_())
            ta.fill('막힐 댓글')
            btn.click()
            until(p, "() => document.querySelector(%s).textContent !== ''" % json.dumps(SEC % many + ' .msg'))
            check(sec.locator('.msg').text_content() == '댓글을 잠시 쉬었다가 남겨 주세요.' and ta.input_value() == '막힐 댓글' and rows(many) == '7', '[s1b] failure → .msg under the row · text kept')
            p.unroute(route)
            ta.fill('')
            icon.click()
            check(sec.is_hidden() and icon.get_attribute('aria-expanded') == 'false', '[s1b] icon again → collapses')
            # 커서 — 35개: 최근 3 → 불러 둔 30 → 커서 창구로 35
            p.locator('#memory-' + lots + ' [data-comment-post]').click()
            n_comments(p, lots, 3)
            more = p.locator(SEC % lots + ' > .text-button')
            t1 = more.text_content()
            more.click()
            n_comments(p, lots, 30)
            t2 = more.text_content() if more.is_visible() else None
            more.click()
            n_comments(p, lots, 35)
            got = shown_comments(p, lots)
            check(t1 == '이전 댓글 32개 더 보기' and t2 == '이전 댓글 5개 더 보기' and got == ['많이 %d' % i for i in range(1, 36)] and more.is_hidden(),
                  '[s1b] 35 comments: 3 → 30 (loaded) → 35 via cursor endpoint · order kept (%s · %s)' % (t1, t2))
            check(p.evaluate('document.documentElement.scrollWidth - innerWidth') <= 0, '[s1b] 1440 overflow 0 (expanded)')
            p.screenshot(path=str(shots / 's1b-comments-1440.png'), full_page=True)
            check(t.clean('s1b-1440'), '[s1b] 1440 console 0')
            ctx.close()
            # 폰 412(터치) — 펼쳐도 초점 0 · 넘침 0
            ctx = context('employee', 412)
            t = Tab(ctx)
            p = t.page
            open_view(t, base + '/memories?view=mine')
            p.locator('#memory-' + many + ' [data-comment-post]').click()
            n_comments(p, many, 3)
            st = p.evaluate("(sel) => ({focus: document.activeElement === document.querySelector(sel + ' textarea'), o: document.documentElement.scrollWidth - innerWidth, dialogs: document.querySelectorAll('dialog[open]').length})", SEC % many)
            check(not st['focus'] and st['o'] <= 0 and st['dialogs'] == 0, '[s1b] 412 touch: expands · no auto focus · overflow 0 · dialog 0 %s' % json.dumps(st))
            sec = p.locator(SEC % many)
            sec.locator('textarea').tap()
            sec.locator('textarea').fill('폰에서 단 댓글')
            sec.locator('[type=submit]').tap()
            last_is(p, many, '폰에서 단 댓글')
            check(rows(many) == '8', '[s1b] 412: tap → type → 「게시」 posts')
            p.screenshot(path=str(shots / 's1b-comments-412.png'), full_page=True)
            check(t.clean('s1b-412'), '[s1b] 412 console 0')
            ctx.close()
            # 읽기 전용(손님) — 입력 줄 대신 안내 한 줄
            ctx = context('guest', 1440)
            t = Tab(ctx)
            p = t.page
            open_view(t, base + '/memories/p/' + many)
            p.locator('#memory-' + many + ' [data-comment-post]').first.click()
            n_comments(p, many, 3)
            sec = p.locator(SEC % many)
            check(sec.locator('.comments-readonly').is_visible() and sec.locator('textarea').count() == 0 and sec.locator('[type=submit]').count() == 0,
                  '[s1b] read-only account: notice line instead of the input row')
            check(t.clean('s1b-guest'), '[s1b] read-only console 0')
            ctx.close()
            # 공유 공개 화면 — 창 0 · 공개 댓글 창구가 없어 댓글 칸도 없다(지금과 같음)
            ctx = context(None, 1440)
            t = Tab(ctx)
            p = t.page
            open_view(t, SHARED)
            st = p.evaluate("() => ({dialog: document.querySelectorAll('#comments-dialog').length, sections: document.querySelectorAll('.post-comments').length, icons: document.querySelectorAll('[data-comment-post]').length, cards: document.querySelectorAll('#feed .post').length})")
            check(st['dialog'] == 0 and st['sections'] == 0 and st['icons'] == 0 and st['cards'] >= 1, '[s1b] shared public page: comments dialog 0 · comment controls 0 (no public comment endpoint) %s' % json.dumps(st))
            check(t.clean('s1b-shared'), '[s1b] shared console 0')
            ctx.close()
        guard('s1b', s1b)
        browser.close()
finally:
    (out / 'story-s1-metrics.json').write_text(json.dumps(metrics, ensure_ascii=False, indent=1))
    (out / 'story-s1-results.json').write_text(json.dumps(checks, ensure_ascii=False, indent=2))
    print('RESULT pass=' + str(sum(x['pass'] for x in checks)) + ' fail=' + str(sum(not x['pass'] for x in checks)), flush=True)
if any(not x['pass'] for x in checks):
    raise SystemExit(1)
