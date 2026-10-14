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
    cover: document.querySelectorAll('.profile-cover').length, aside: document.querySelectorAll('.journal-aside').length,
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
                        check(m['cover'] == 0 and m['aside'] == 0 and m['banned'] == [] and m['eyebrows'] == [],
                              '[grid] %s: .profile-cover 0 · .journal-aside 0 · banned text 0 · eyebrow 0 %s' % (tag, json.dumps([m['banned'], m['eyebrows']], ensure_ascii=False)))
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
            # 댓글
            card.locator('[data-comment-post]').click()
            p.wait_for_selector('#comments-dialog[open]', timeout=8000)
            p.fill('#comment-body', 'S1 댓글')
            p.click('#comment-form [type="submit"]')
            until(p, "() => document.getElementById('comments-list').textContent.includes('S1 댓글')")
            check(sql("SELECT count(*) FROM community.memory_comments WHERE post_id='" + mine_photo + "'") == '1', '[act] comment: posted · row · listed')
            p.click('[data-close="comments-dialog"]')
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
        browser.close()
finally:
    (out / 'story-s1-metrics.json').write_text(json.dumps(metrics, ensure_ascii=False, indent=1))
    (out / 'story-s1-results.json').write_text(json.dumps(checks, ensure_ascii=False, indent=2))
    print('RESULT pass=' + str(sum(x['pass'] for x in checks)) + ' fail=' + str(sum(not x['pass'] for x in checks)), flush=True)
if any(not x['pass'] for x in checks):
    raise SystemExit(1)
