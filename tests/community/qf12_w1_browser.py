#!/usr/bin/env python3
"""Q:F-12 W1 — 화면 수락 시험: 달력의 작업 기간(예정 · 실제 막대) · 작업 칸 「시작 예정」 (실제 PHP · PostgreSQL + Chromium · 격리 틀 memories_harness.py 만)

  python3 tests/community/memories_harness.py --suite qf12_w1_browser.py --output <결과 폴더>

합성 DB · 임시 복사본 · 로컬 php -S 만 친다(운영 8082 · tbox_re · 시드 계정 0). 계정은 fixture 의 claude_ 와 UA tbox-review/<날짜>.
결과 파일에는 단언 이름 · 참/거짓 · 측정값(클래스 · 수 · 좌표 · 대비)만 — 세션 · CSRF · 응답 본문 0. 스크린샷은 결과 폴더 shots/.
지시서 docs/qf12-w1-directive.md 「완료 기준 — 브라우저」 · DESIGN 2.34 · 기획 work-ux-plan.md 19.2.
날짜는 서울 오늘 기준 상대값(사건 시각은 합성 — SQL 로 맞춘다). 달 경계에 걸린 조각은 기대값도 같은 규칙으로 잘라 센다.

  [pane]    1440 밝게: 작업 칸 「마감」 앞 「시작 예정」 칸 → 바꿔 저장 → 칸을 다시 열어도 그 값 · 실제 시작 줄 「실제 시작 M월 D일 — 진행 중으로 바꾼 날」 ·
            시작 전 작업 「아직 시작 전」 · 끝난 작업 「끝난 날 M월 D일」 · 시작 예정 > 마감 = 칸 아래 알림 「시작 예정일이 기한보다 늦어요」 + 값 되돌림 · 활동 문장
  [cal]     내 작업 달력 1440 · 412 × 밝게 · 어둡게 · 고대비: 예정 막대(점선 · 시작 예정 ~ 마감) · 실제 막대(첫 doing ~ 오늘 · 진한 칠) · 기한 넘김 조각(경고 칠) ·
            기한 깃발 · 주 줄 넘김(앞 줄 --more · 다음 줄 첫 칸 --cont) · 층(예정 위 · 실제 바로 아래) · +N · 범례 셋 · 막대 누름 → 오른쪽 칸 · aria-label 문장 ·
            기한만 있는 작업 = 기한 하루 · 412 막대 글자 0 · 넘침 0 · 대비 4.5(막대 글자 · 범례) · 인라인 style 0 · 콘솔 0 · 스크린샷
  [project] 프로젝트 달력(데이터베이스 달력 보기 — 같은 calendar.js): 끝난 작업 실제 막대(첫 doing ~ 끝난 날) · 막대 누름 → 오른쪽 칸 · 끝난 날 줄
  [menu]    W1b(DESIGN 2.15 「창 안에 머묾」) — 1440 · 1280 · 960 · 412 × 작업 칸 · 넓게 보기(1200 이상) · 전체 화면 작업 · 프로젝트 머리 · 화면 머리의 「더보기」:
            열린 목록 상자가 창 안(왼 ≥ 8 · 오른 ≤ 폭-8 · 아래 ≤ 높이-8 또는 위로 펼침) · 항목 글자 잘림 0 · 열린 동안 풍선 0 · Esc · 밖 누름 닫힘 · 스크린샷(칸 메뉴 1440)
  [doc]     W1c(DESIGN 2.35) — 1440 · 1280 · 960 · 412: 문서 · 업무 게시판 글 · 카페 글 · 익명 글 · 작업 전체 화면이 같은 열(x · 폭 같음 · ≤ 960 · 본문 왼끝 = 제목 왼끝) ·
            읽기 본문 테두리 · 바탕 · 그림자 0 · 댓글 같은 열 · 상자 0 · 빈 문서 안내 누름 → 편집(도구 줄 상자 · 편집 칸 = 열 폭 · 16rem 이상 · 「편집 마침」 본문 머리 오른쪽 ·
            제목 줄 「편집」 숨김) → 마침 → 저장 · 읽기 흐름 · 작업 전체 화면 편집 칸과 같은 폭 · 보기만 하는 사람 「아직 내용이 없어요」 · 편집 0 ·
            종이 모드(A4)를 고른 사람 전과 같은 폭 · 데이터베이스 · 프로젝트 화면 폭 그대로 · 넘침 0 · 콘솔 0 · 스크린샷(카페 글 · 문서 읽기 · 편집 1440)
"""
from pathlib import Path
exec(compile(Path(__file__).with_name('memories_http.py').read_text().split('try:\n for u in users:')[0], __file__, 'exec'))
import re, secrets, datetime
from zoneinfo import ZoneInfo
from urllib.parse import urlsplit
from playwright.sync_api import sync_playwright
assert str(root).startswith('/tmp/qaredi-stage13-') and manifest['database'].startswith('tbox_memories_test_')
host = urlsplit(site).hostname
UA = 'tbox-review/' + time.strftime('%Y-%m-%d')
ONLY = set(filter(None, os.environ.get('QF12W1_ONLY', '').split(',')))
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
def pk(public_id): return int(sql('SELECT id FROM community.pages WHERE public_id=' + lit(public_id)))


def new_session(key):
    token = secrets.token_hex(32)
    sql("INSERT INTO sessions(id,user_id,expires_at,ip,user_agent) VALUES('" + hashlib.sha256(token.encode()).hexdigest() + "'," + str(uid(key))
        + ",clock_timestamp()+interval '8 hours','127.0.0.1','" + UA + "')")
    return token


def code_console(lines):
    # ProseMirror 의 CSS 안내(편집기를 처음 만들 때 한 번 — 옛 판에도 같다 · 이 묶음 밖)는 센다에서 뺀다 — 보고서 「남은 것」
    return [t for t, _ in lines if not t.startswith('Failed to load resource') and not t.startswith('[DOM] ') and not t.startswith('ProseMirror expects the CSS white-space')]


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

# 달력 한 장의 막대 · 칸 · 범례를 한 번에 잰다
CAL_JS = r"""(sel) => { const root = document.querySelector(sel); if (!root) return null;
  const vis = el => { const r = el.getBoundingClientRect(); const c = getComputedStyle(el); return r.width > 0 && r.height > 0 && c.display !== 'none' && c.visibility !== 'hidden'; };
  const weeks = [...root.querySelectorAll('.cal-week')];
  const bars = []; weeks.forEach((w, wi) => { for (const b of w.querySelectorAll('.cal-bar')) { const c = getComputedStyle(b); const r = b.getBoundingClientRect(); const t = b.querySelector('.cal-bar__text');
    const cls = [...b.classList]; const num = p => { const k = cls.find(x => x.startsWith(p)); return k ? Number(k.slice(p.length)) : null; };
    bars.push({week: wi, kind: (cls.find(x => /^cal-bar--(plan|actual|late|base)$/.test(x)) || '').slice(9), col: num('cal-col-'), span: num('cal-span-'), lane: num('cal-lane-'),
      cont: cls.includes('cal-bar--cont'), more: cls.includes('cal-bar--more'), label: b.getAttribute('aria-label'), href: b.getAttribute('href'),
      text: t ? t.textContent : null, textShown: !!t && vis(t), borderStyle: c.borderTopStyle, bg: c.backgroundColor, w: r.width, h: r.height, l: r.left, r: r.right}); } });
  const cells = weeks.map(w => [...w.querySelectorAll('.db-calendar__day')].map(d => ({day: d.getAttribute('aria-label'), due: !!d.querySelector('.cal-due'),
    flag: (() => { const f = d.querySelector('.cal-due'); if (!f) return null; const fr = f.getBoundingClientRect(), dr = d.getBoundingClientRect(); return {w: fr.width, h: fr.height, right: dr.right - fr.right, top: fr.top - dr.top}; })()})));
  const more = weeks.map((w, wi) => [...w.querySelectorAll('.cal-more')].map(m => ({week: wi, col: Number([...m.classList].find(x => x.startsWith('cal-col-')).slice(8)), text: m.textContent})));
  const legend = [...document.querySelectorAll('.cal-legend .cal-legend__item')].filter(vis).map(i => ({text: i.textContent.trim(), sw: (() => { const s = i.querySelector('.cal-legend__swatch'); const r = s.getBoundingClientRect(); return {w: r.width, h: r.height, style: getComputedStyle(s).borderTopStyle}; })()}));
  const lg = document.querySelector('.cal-legend'); const tb = lg ? lg.parentElement.getBoundingClientRect() : null; const lr = lg ? lg.getBoundingClientRect() : null;
  return {weeks: weeks.length, bars, cells, more: more.flat(), legend, legendRightGap: lg ? tb.right - lr.right : null,
          inline: root.querySelectorAll('[style]').length + (lg ? lg.querySelectorAll('[style]').length : 0),
          overflow: document.documentElement.scrollWidth - window.innerWidth,
          accent: getComputedStyle(document.documentElement).getPropertyValue('--accent').trim()}; }"""

SEOUL = ZoneInfo('Asia/Seoul')
TODAY = datetime.datetime.now(SEOUL).date()
def D(n): return (TODAY + datetime.timedelta(days=n)).isoformat()
def md(day): return '%d월 %d일' % (int(day[5:7]), int(day[8:10]))
def rng(a, b):
    if a == b: return md(a)
    return md(a) + ' ~ ' + (str(int(b[8:10])) + '일' if a[:7] == b[:7] else md(b))
MONTH_FROM = TODAY.replace(day=1)
MONTH_TO = (MONTH_FROM + datetime.timedelta(days=32)).replace(day=1) - datetime.timedelta(days=1)


def segments(a, b):
    """[a, b] 를 이 달 격자의 주 줄로 자른 조각 — (줄 번호, 시작 칸 1~7, 칸 수, cont, more). 달력 그리기와 같은 규칙."""
    a_, b_ = datetime.date.fromisoformat(a), datetime.date.fromisoformat(b)
    lead = (MONTH_FROM.weekday() + 1) % 7          # 일요일 0
    out_ = []
    d = max(a_, MONTH_FROM)
    end = min(b_, MONTH_TO)
    while d <= end:
        idx = lead + (d - MONTH_FROM).days
        week, c0 = idx // 7, idx % 7
        last_in_row = d + datetime.timedelta(days=6 - c0)
        week_last = min(last_in_row, MONTH_TO)
        week_first = d - datetime.timedelta(days=c0) if d - datetime.timedelta(days=c0) >= MONTH_FROM else MONTH_FROM
        seg_end = min(end, week_last)
        out_.append((week, c0 + 1, (seg_end - d).days + 1, max(a_, MONTH_FROM) < d or a_ < week_first, b_ > seg_end))
        d = seg_end + datetime.timedelta(days=1)
    return out_


try:
    for u in users:
        users[u]['username'] = sql('SELECT username FROM users WHERE id=' + str(users[u]['id']))
        call('GET', '/api/me/space', user=u)
    emp = uid('employee')
    sql("INSERT INTO community.usage_guides(user_id,version,status,context) SELECT id,1,'dismissed','overview' FROM users ON CONFLICT DO NOTHING")

    # ─── 합성 준비 — 직원 개인 프로젝트 · 작업들(날짜는 서울 오늘 기준) ───────────────────────────────
    proj = call('POST', '/api/pages', {'database': True, 'preset': 'project', 'title': 'W1 달력 프로젝트'}, expected=201)['page']
    proj_db = call('GET', '/api/pages/' + proj['id'])['database']['id']

    def task(title, **propsv):
        tid = call('POST', '/api/pages', {'parent_id': proj['id'], 'title': title, 'properties': {'section': '01. 진행'}}, expected=201)['page']['id']
        return tid

    def setp(tid, body): return call('PATCH', '/api/pages/' + tid + '/properties', body)

    def status_at(tid, status, day, hour=10):
        setp(tid, {'status': status})
        sql("UPDATE community.page_property_events SET at=" + lit('%s %02d:00:00+09' % (day, hour)) + "::timestamptz WHERE id=(SELECT max(id) FROM community.page_property_events WHERE page_id="
            + str(pk(tid)) + " AND prop='status')")

    T1 = task('디버깅')
    setp(T1, {'start': D(-2), 'due': D(2)})
    status_at(T1, 'todo', D(-3)); status_at(T1, 'doing', D(-1))
    T2 = task('늦은 작업')
    setp(T2, {'start': D(-6), 'due': D(-3)})
    status_at(T2, 'doing', D(-5))
    T3 = task('끝난 작업')
    setp(T3, {'start': D(-10), 'due': D(-8)})
    status_at(T3, 'doing', D(-10)); status_at(T3, 'done', D(-9), 15)
    T4 = task('기한만 작업')
    setp(T4, {'status': 'todo', 'due': D(1)})
    # 주 줄 넘김 — 이번 주(또는 앞 주) 토요일 → 다음 월요일(둘 다 이 달 안)
    sat = TODAY + datetime.timedelta(days=(5 - TODAY.weekday()) % 7)
    if sat + datetime.timedelta(days=2) > MONTH_TO:
        sat -= datetime.timedelta(days=7)
    T5 = task('주 넘김 작업')
    setp(T5, {'status': 'todo', 'start': sat.isoformat(), 'due': (sat + datetime.timedelta(days=2)).isoformat()})
    # +N — 다른 작업이 없는 주의 수요일에 기한 6개
    busy = set()
    for a, b in ((D(-10), D(2)), (sat.isoformat(), (sat + datetime.timedelta(days=2)).isoformat())):
        for s in segments(a, b):
            busy.add(s[0])
    pile_day = None
    for shift in (7, 14, -7, -14, 21, -21):
        cand = TODAY + datetime.timedelta(days=shift)
        cand = cand + datetime.timedelta(days=(2 - cand.weekday()) % 7 - (7 if (2 - cand.weekday()) % 7 > 3 else 0))
        if MONTH_FROM <= cand <= MONTH_TO and segments(cand.isoformat(), cand.isoformat())[0][0] not in busy:
            pile_day = cand.isoformat()
            break
    pile = []
    if pile_day:
        for k in range(6):
            t = task('묶음 %d' % (k + 1))
            setp(t, {'status': 'todo', 'due': pile_day})
            pile.append(t)
    metrics['fixture'] = {'today': TODAY.isoformat(), 'sat': sat.isoformat(), 'pile_day': pile_day}
    check(pile_day is not None, 'fixture: a quiet week for the +N pile (%s)' % pile_day)

    t1_label = '디버깅 — 예정 ' + rng(D(-2), D(2)) + ' · 실제 ' + md(D(-1)) + ' ~ 진행 중'
    t2_label = '늦은 작업 — 예정 ' + rng(D(-6), D(-3)) + ' · 실제 ' + md(D(-5)) + ' ~ 진행 중 · 기한 넘김 3일'

    with sync_playwright() as pw:
        browser = pw.chromium.launch(executable_path=os.environ.get('CHROMIUM_EXECUTABLE'))

        def context(key, width, theme, touch=False):
            ctx = browser.new_context(viewport={'width': width, 'height': 900 if width > 800 else 915}, has_touch=touch, is_mobile=touch, user_agent=UA,
                                      reduced_motion='reduce', color_scheme=theme)
            jar = [{'name': '__Host-tbox_session', 'value': new_session(key), 'domain': host, 'path': '/', 'secure': True, 'httpOnly': True, 'sameSite': 'Lax'}]
            for name, value in (('qaredi_motion', 'reduce'), ('tbox_theme', theme)):
                jar.append({'name': name, 'value': value, 'domain': host, 'path': '/'})
            ctx.add_cookies(jar)
            return ctx

        def open_my_calendar(p):
            p.goto(site + '/community/my-tasks', wait_until='networkidle')
            p.locator('[data-my-view="calendar"]').click()
            p.wait_for_selector('#my-tasks-calendar .cal-week', timeout=15000)
            p.wait_for_timeout(250)

        def pane_title(p):
            p.wait_for_selector('#task-pane .task-pane__title', timeout=15000)
            p.wait_for_timeout(250)
            return p.locator('#task-pane .task-pane__title').inner_text().strip()

        def bar_of(cal, label_prefix, kind=None):
            return [b for b in cal['bars'] if (b['label'] or '').startswith(label_prefix) and (kind is None or b['kind'] == kind)]

        # ─── [cal] 내 작업 달력 ─────────────────────────────────────────────────────────────
        if want('cal'):
            def cal():
                summary = {}
                for mode in ('light', 'dark', 'hc'):
                    sql('UPDATE users SET high_contrast=' + ('true' if mode == 'hc' else 'false') + ' WHERE id=' + str(emp))
                    for width in (1440, 412):
                        tag = '%d-%s' % (width, mode)
                        ctx = context('employee', width, 'dark' if mode == 'dark' else 'light', width < 800)
                        t = Tab(ctx)
                        p = t.page
                        open_my_calendar(p)
                        c = p.evaluate(CAL_JS, '#my-tasks-calendar')
                        summary[tag] = {'bars': len(c['bars']), 'more': c['more'], 'legend': c['legend'], 'overflow': c['overflow']}
                        if width == 1440 and mode in ('light', 'dark') or (width == 412 and mode == 'light'):
                            p.locator('#my-tasks-calendar').screenshot(path=str(shots / ('calendar-my-tasks-%s.png' % tag)))
                        # 예정 · 실제 · 기한 넘김
                        b1p, b1a = bar_of(c, '디버깅 —', 'plan'), bar_of(c, '디버깅 —', 'actual')
                        want_p, want_a = segments(D(-2), D(2)), segments(D(-1), TODAY.isoformat())
                        check([(b['week'], b['col'], b['span']) for b in b1p] == [(s[0], s[1], s[2]) for s in want_p] and all(b['borderStyle'] == 'dashed' for b in b1p),
                              'cal %s: 디버깅 plan bar = start ~ due %s · dashed border' % (tag, [(b['week'], b['col'], b['span']) for b in b1p]))
                        check([(b['week'], b['col'], b['span']) for b in b1a] == [(s[0], s[1], s[2]) for s in want_a] and all(b['borderStyle'] == 'solid' for b in b1a),
                              'cal %s: 디버깅 actual bar = first doing ~ today %s · solid' % (tag, [(b['week'], b['col'], b['span']) for b in b1a]))
                        same_week = [(x, y) for x in b1p for y in b1a if x['week'] == y['week']]
                        check(same_week and all(y['lane'] == x['lane'] + 1 for x, y in same_week), 'cal %s: 디버깅 plan above actual (lanes %s)' % (tag, [(x['lane'], y['lane']) for x, y in same_week]))
                        check(all(b['label'] == t1_label for b in b1p + b1a), 'cal %s: aria-label 「%s」' % (tag, t1_label))
                        b2l = bar_of(c, '늦은 작업 —', 'late')
                        want_l = segments(D(-2), TODAY.isoformat())
                        check([(b['week'], b['col'], b['span']) for b in b2l] == [(s[0], s[1], s[2]) for s in want_l] and all(b['label'] == t2_label for b in bar_of(c, '늦은 작업 —')),
                              'cal %s: 늦은 작업 late segment = due+1 ~ today %s · label 「%s」' % (tag, [(b['week'], b['col'], b['span']) for b in b2l], t2_label))
                        late_bg = p.evaluate("() => { const d = document.createElement('div'); d.style.background = 'var(--danger-soft)'; document.body.append(d); const v = getComputedStyle(d).backgroundColor; d.remove(); return v; }")
                        check(b2l and all(b['bg'] == late_bg for b in b2l), 'cal %s: late segment background = --danger-soft (%s)' % (tag, late_bg))
                        acc_bg = p.evaluate("() => { const d = document.createElement('div'); d.style.background = 'var(--accent)'; document.body.append(d); const v = getComputedStyle(d).backgroundColor; d.remove(); return v; }")
                        check(b1a and all(b['bg'] == acc_bg for b in b1a), 'cal %s: actual bar background = --accent' % tag)
                        # 기한만 — 기한 하루(지금과 같음)
                        b4 = bar_of(c, '기한만 작업 —')
                        want4 = segments(D(1), D(1))
                        check([(b['kind'], b['week'], b['col'], b['span']) for b in b4] == [('base', s[0], s[1], 1) for s in want4]
                              and all(b['label'] == '기한만 작업 — 기한 ' + md(D(1)) for b in b4), 'cal %s: due-only task = one-day bar on the due day' % tag)
                        # 기한 깃발 — 이 달 안의 기한 날 칸마다(그 밖 0)
                        dues = {D(2), D(-3), D(1), (sat + datetime.timedelta(days=2)).isoformat()} | ({pile_day} if pile_day else set())
                        dues = {d for d in dues if MONTH_FROM.isoformat() <= d <= MONTH_TO.isoformat()}
                        flagged = {x['day'] for w in c['cells'] for x in w if x['due']}
                        fl = [x['flag'] for w in c['cells'] for x in w if x['flag']]
                        check(flagged == dues and all(abs(f['w'] - 12) <= 0.5 and abs(f['h'] - 12) <= 0.5 and f['right'] <= 8 and f['top'] <= 8 for f in fl),
                              'cal %s: due flag (12×12, top-right) exactly on due days %s' % (tag, sorted(flagged)))
                        # 주 줄 넘김
                        b5 = bar_of(c, '주 넘김 작업 —')
                        want5 = segments(sat.isoformat(), (sat + datetime.timedelta(days=2)).isoformat())
                        check(len(b5) == 2 and [(b['week'], b['col'], b['span'], b['cont'], b['more']) for b in b5] == [(s[0], s[1], s[2], s[3], s[4]) for s in want5]
                              and b5[1]['col'] == 1 and b5[0]['more'] and b5[1]['cont'], 'cal %s: week wrap — Saturday piece --more, next row starts at column 1 --cont %s'
                              % (tag, [(b['week'], b['col'], b['span'], b['cont'], b['more']) for b in b5]))
                        # 층 · +N
                        if pile_day:
                            ps = segments(pile_day, pile_day)[0]
                            in_col = [b for b in c['bars'] if b['week'] == ps[0] and b['col'] <= ps[1] < b['col'] + b['span']]
                            mr = [m for m in c['more'] if m['week'] == ps[0] and m['col'] == ps[1]]
                            check(len(in_col) == 4 and sorted(b['lane'] for b in in_col) == [1, 2, 3, 4] and [m['text'] for m in mr] == ['+2'],
                                  'cal %s: 6 tasks on one day → lanes 1-4 shown · 「+2」 in that cell (%s · %s)' % (tag, len(in_col), [m['text'] for m in mr]))
                        check([x['text'] for x in c['legend']] == ['예정', '실제', '기한 넘김'] and all(abs(x['sw']['w'] - 16) <= 0.5 and abs(x['sw']['h'] - 10) <= 0.5 for x in c['legend'])
                              and c['legend'][0]['sw']['style'] == 'dashed' and c['legendRightGap'] is not None and c['legendRightGap'] <= 1,
                              'cal %s: legend 예정 · 실제 · 기한 넘김 (swatch 16×10 · plan dashed) at the toolbar right' % tag)
                        check(c['inline'] == 0, 'cal %s: inline style 0 in the calendar' % tag)
                        check(c['overflow'] <= 0, 'cal %s: horizontal overflow 0 (%s)' % (tag, c['overflow']))
                        check(all(b['h'] > 0 and abs(b['h'] - 20) <= 0.5 for b in c['bars']), 'cal %s: every bar height 20' % tag)
                        if width == 412:
                            check(not any(b['textShown'] for b in c['bars']) and all(b['w'] > 0 for b in c['bars']), 'cal 412-%s: bar text 0 (colour bars only) · bars still drawn' % mode)
                        else:
                            shown = [b for b in c['bars'] if b['textShown']]
                            check(shown and all(b['text'] for b in shown), 'cal %s: bar titles shown (%d)' % (tag, len(shown)))
                            cr = [round(h.evaluate(CONTRAST_JS), 2) for h in p.query_selector_all('#my-tasks-calendar .cal-bar__text') if h.is_visible()]
                            cr_l = [round(h.evaluate(CONTRAST_JS), 2) for h in p.query_selector_all('.cal-legend__item') if h.is_visible()]
                            summary[tag]['contrast'] = [min(cr) if cr else None, min(cr_l) if cr_l else None]
                            check(cr and min(cr) >= 4.5 and cr_l and min(cr_l) >= 4.5, 'cal %s: contrast ≥ 4.5 — bar text min %.2f · legend min %.2f' % (tag, min(cr) if cr else -1, min(cr_l) if cr_l else -1))
                        # 막대 누름 → 오른쪽 칸(어느 조각이든)
                        target = '#my-tasks-calendar .cal-bar--actual[aria-label^="디버깅 —"]'
                        p.locator(target).first.click()
                        check(pane_title(p) == '디버깅' and '/my-tasks' in p.url, 'cal %s: clicking the actual bar opens the task in the right pane (stays on my-tasks)' % tag)
                        if width == 1440 and mode == 'light':
                            p.screenshot(path=str(shots / 'calendar-my-tasks-pane-1440-light.png'))
                        check(t.clean('cal-' + tag), 'cal %s console 0 · page errors 0 · 5xx 0' % tag)
                        ctx.close()
                sql('UPDATE users SET high_contrast=false WHERE id=' + str(emp))
                metrics['cal'] = summary
            guard('cal', cal)

        # ─── [pane] 작업 칸 「시작 예정」 ───────────────────────────────────────────────────
        if want('pane'):
            def pane():
                ctx = context('employee', 1440, 'light')
                t = Tab(ctx)
                p = t.page
                open_my_calendar(p)
                p.locator('#my-tasks-calendar .cal-bar--plan[aria-label^="디버깅 —"]').first.click()
                check(pane_title(p) == '디버깅', 'pane: plan bar click opens 디버깅')
                dts = [x.strip() for x in p.locator('#task-pane .task-pane__props dt').all_inner_texts()]
                check('시작 예정' in dts and '마감' in dts and dts.index('시작 예정') == dts.index('마감') - 1, 'pane: 「시작 예정」 right before 「마감」 %s' % dts)
                inp = p.locator('#task-pane input[aria-label="시작 예정 날짜"]')
                check(inp.input_value() == D(-2), 'pane: start input shows %s' % D(-2))
                help_ = p.locator('#task-pane .task-pane__started').inner_text().strip()
                check(help_ == '실제 시작 ' + md(D(-1)) + ' — 진행 중으로 바꾼 날', 'pane: started line 「%s」' % help_)
                check(p.locator('#task-pane .task-pane__finished').count() == 0, 'pane: no finished line while in progress')
                # 바꿔 저장 → 다시 열어도
                inp.fill(D(-3))
                inp.dispatch_event('change')
                p.wait_for_timeout(900)
                check(sql('SELECT start_date FROM community.pages WHERE id=' + str(pk(T1))) == D(-3), 'pane: change saved (DB start_date %s)' % D(-3))
                p.keyboard.press('Escape')
                p.wait_for_timeout(300)
                p.reload(wait_until='networkidle')
                p.locator('[data-my-view="calendar"]').click()
                p.wait_for_selector('#my-tasks-calendar .cal-week', timeout=15000)
                p.locator('#my-tasks-calendar .cal-bar--plan[aria-label^="디버깅 —"]').first.click()
                pane_title(p)
                check(p.locator('#task-pane input[aria-label="시작 예정 날짜"]').input_value() == D(-3), 'pane: reopened after reload → start still %s' % D(-3))
                # 시작 예정 > 마감 — 칸 아래 알림 · 값 되돌림 · 저장 0
                inp = p.locator('#task-pane input[aria-label="시작 예정 날짜"]')
                inp.fill(D(5))
                inp.dispatch_event('change')
                p.wait_for_timeout(900)
                dd_msg = p.locator('#task-pane .task-pane__props dd .msg').first.inner_text().strip()
                check(dd_msg == '시작 예정일이 기한보다 늦어요' and inp.input_value() == D(-3) and sql('SELECT start_date FROM community.pages WHERE id=' + str(pk(T1))) == D(-3),
                      'pane: start > due → 「%s」 under the field · input back to %s · DB unchanged' % (dd_msg, D(-3)))
                p.screenshot(path=str(shots / 'pane-start-1440-light.png'))
                # 활동 문장
                p.get_by_role('tab', name='모든 활동').click()
                p.wait_for_timeout(400)
                acts = p.locator('#task-pane .task-pane__activity').inner_text()
                check(('시작 예정을 ' + D(-2) + '에서 ' + D(-3) + '로 바꿨어요') in acts, 'pane: activity 「…님이 시작 예정을 %s에서 %s로 바꿨어요」' % (D(-2), D(-3)))
                # 시작 전
                p.keyboard.press('Escape')
                p.locator('#my-tasks-calendar .cal-bar[aria-label^="기한만 작업 —"]').first.click()
                pane_title(p)
                check(p.locator('#task-pane .task-pane__started').inner_text().strip() == '아직 시작 전', 'pane: not started → 「아직 시작 전」')
                check(t.clean('pane'), 'pane console 0 · page errors 0 · 5xx 0')
                ctx.close()
            guard('pane', pane)

        # ─── [project] 프로젝트 달력 ────────────────────────────────────────────────────────
        if want('project'):
            def project():
                views = call('GET', '/api/databases/' + proj_db + '/rows')['views']
                cal_view = next(v for v in views if v['type'] == 'calendar')
                for width in (1440, 412):
                    ctx = context('employee', width, 'light', width < 800)
                    t = Tab(ctx)
                    p = t.page
                    p.goto(site + '/community/p/' + proj['id'], wait_until='networkidle')
                    p.wait_for_selector('#project-list .task-row[data-task]', timeout=15000)
                    p.locator('[data-db-view="' + cal_view['id'] + '"]').click()
                    p.wait_for_selector('.db-calendar .cal-week', timeout=15000)
                    p.wait_for_timeout(300)
                    c = p.evaluate(CAL_JS, '.db-calendar')
                    b3 = bar_of(c, '끝난 작업 —', 'actual')
                    lbl3 = '끝난 작업 — 예정 ' + rng(D(-10), D(-8)) + ' · 실제 ' + md(D(-10)) + ' ~ ' + (str(int(D(-9)[8:10])) + '일' if D(-10)[:7] == D(-9)[:7] else md(D(-9)))
                    check([(b['week'], b['col'], b['span']) for b in b3] == [(s[0], s[1], s[2]) for s in segments(D(-10), D(-9))] and all(b['label'] == lbl3 + ' · 좌우 화살표로 하루 이동' for b in b3),
                          'project %d: finished task actual bar = first doing ~ finished day · label 「%s」 + edit hint (owner can move the due)' % (width, lbl3))
                    check(bar_of(c, '디버깅 —', 'plan') and [x['text'] for x in c['legend']] == ['예정', '실제', '기한 넘김'], 'project %d: same renderer (plan bars · legend)' % width)
                    check(c['overflow'] <= 0 and c['inline'] == 0, 'project %d: overflow 0 · inline style 0' % width)
                    if width == 1440:
                        p.locator('.db-calendar').screenshot(path=str(shots / 'calendar-project-1440-light.png'))
                    p.locator('.db-calendar .cal-bar--actual[aria-label^="끝난 작업 —"]').first.click()
                    check(pane_title(p) == '끝난 작업' and proj['id'] in p.url, 'project %d: bar click → task in the right pane (project page stays)' % width)
                    fin = p.locator('#task-pane .task-pane__finished')
                    check(fin.count() == 1 and fin.inner_text().strip() == '끝난 날 ' + md(D(-9)), 'project %d: finished line 「끝난 날 %s」' % (width, md(D(-9))))
                    check(t.clean('project-%d' % width), 'project %d console 0 · page errors 0 · 5xx 0' % width)
                    ctx.close()
            guard('project', project)

        # ─── [menu] W1b — 「더보기」 메뉴가 창 안에 머묾(DESIGN 2.15) ─────────────────────────────────
        MENU_JS = r"""(sel) => { const sum = document.querySelector(sel); if (!sum) return null; const menu = sum.parentElement; const list = menu.querySelector('.menu__list');
  const r = list.getBoundingClientRect(); const sr = sum.getBoundingClientRect();
  const items = [...list.querySelectorAll('.menu__item')].filter(i => !i.hidden && getComputedStyle(i).display !== 'none').map(i => { const b = i.getBoundingClientRect();
    const t = [...i.childNodes].map(n => n.textContent).join('').trim(); return {t, l: b.left, r: b.right, cut: i.scrollWidth > i.clientWidth + 1}; });
  const tip = getComputedStyle(sum, '::after'); return {open: menu.open, l: r.left, r: r.right, t: r.top, b: r.bottom, w: r.width, vw: innerWidth, vh: innerHeight,
    sumL: sr.left, sumR: sr.right, sumB: sr.bottom, sumT: sr.top, items, tip: tip.display, tipContent: tip.content, pos: getComputedStyle(list).position}; }"""
        if want('menu'):
            def menu():
                summary = {}
                doc = call('POST', '/api/pages', {'title': 'W1b 일반 문서'}, expected=201)['page']['id']
                cases = [('pane', '/community/my-tasks?task=' + T4, '#task-pane .task-pane__head details.menu > summary', '#task-pane .task-pane__props dt'),
                         ('wide', '/community/my-tasks?task=' + T4, '#task-pane .task-pane__head details.menu > summary', '#task-pane .task-pane__props dt'),
                         ('full', '/community/p/' + T4, '#task-full .task-pane__head details.menu > summary', '#task-full .task-pane__props dt'),
                         ('project', '/community/p/' + proj['id'], '#project-menu > summary', '#project-title'),
                         ('page', '/community/p/' + doc, '#page-more > summary', '#page-title')]
                for width in (1440, 1280, 960, 412):
                    ctx = context('employee', width, 'light', width < 800)
                    t = Tab(ctx)
                    p = t.page
                    for name, url, sel, outside in cases:
                        if name == 'wide' and width < 1200:
                            continue
                        tag = '%s-%d' % (name, width)
                        p.goto(site + url, wait_until='networkidle')
                        p.wait_for_selector(sel, state='visible', timeout=15000)
                        p.wait_for_timeout(300)
                        if name == 'wide':
                            p.locator('#task-pane [data-pane-wide]').click()
                            p.wait_for_timeout(300)
                        p.locator(sel).click()
                        p.wait_for_timeout(250)
                        m = p.evaluate(MENU_JS, sel)
                        summary[tag] = m
                        if name == 'pane' and width == 1440:
                            p.screenshot(path=str(shots / 'w1b-pane-menu-1440.png'))
                        inside_x = m and m['l'] >= 8 and m['r'] <= m['vw'] - 8
                        inside_y = m and (m['b'] <= m['vh'] - 8 or (m['t'] >= 8 and m['b'] <= m['sumT']))
                        check(m and m['open'] and inside_x and inside_y,
                              'W1b %s: open list inside the window (l %.0f ≥ 8 · r %.0f ≤ %d · t %.0f · b %.0f ≤ %d or flipped up)'
                              % (tag, m['l'], m['r'], m['vw'] - 8, m['t'], m['b'], m['vh'] - 8) if m else 'W1b %s: menu found' % tag)
                        check(m and m['items'] and all(i['l'] >= 8 and i['r'] <= m['vw'] - 8 and not i['cut'] for i in m['items']),
                              'W1b %s: every item fully visible · text not cut %s' % (tag, [i['t'] for i in (m['items'] if m else [])]))
                        check(m and (m['tip'] == 'none' or m['tipContent'] in ('none', 'normal')), 'W1b %s: no tooltip on the button while open (::after %s · content %s)' % (tag, m and m['tip'], m and m['tipContent']))
                        p.keyboard.press('Escape')
                        p.wait_for_timeout(150)
                        check(not p.evaluate('(s) => document.querySelector(s).parentElement.open', sel), 'W1b %s: Esc closes' % tag)
                        p.locator(sel).click()
                        p.wait_for_timeout(150)
                        p.locator(outside).first.click(position={'x': 2, 'y': 2})
                        p.wait_for_timeout(150)
                        check(not p.evaluate('(s) => document.querySelector(s).parentElement.open', sel), 'W1b %s: outside click closes' % tag)
                    check(t.clean('menu-%d' % width), 'W1b %d console 0 · page errors 0 · 5xx 0' % width)
                    ctx.close()
                metrics['menu'] = summary
            guard('menu', menu)

        # ─── [doc] W1c — 글쓰기 한 기준: 작업 전체 화면 모양(DESIGN 2.35) ───────────────────────────────
        COL_JS = r"""(kind) => { const q = s => document.querySelector(s); const R = el => { if (!el) return null; const r = el.getBoundingClientRect(); const c = getComputedStyle(el);
    return {l: r.left, r: r.right, w: r.width, h: r.height, t: r.top, shown: r.width > 0 && r.height > 0 && c.display !== 'none' && c.visibility !== 'hidden',
            bt: c.borderTopWidth, bs: c.borderTopStyle, bg: c.backgroundColor, sh: c.boxShadow}; };
  const main = q('.main'); const ms = getComputedStyle(main); const mr = main.getBoundingClientRect();
  const out = {mainInner: mr.width - parseFloat(ms.paddingLeft) - parseFloat(ms.paddingRight), overflow: document.documentElement.scrollWidth - innerWidth};
  if (kind === 'task') { Object.assign(out, {col: R(q('#task-full')), title: R(q('#task-full .task-pane__title')), body: R(q('#task-full .task-pane__doc')),
      bar: R(q('#task-full .task-pane__doc .editor-toolbar')), editor: R(q('#task-full .task-pane__doc > .panel')), crumb: R(q('#page-crumb'))}); }
  else { Object.assign(out, {col: R(q('header.page-head')), title: R(q('#page-title')), body: R(q('#page-doc')), bar: R(q('#inline-toolbar')), editor: R(q('#page-editor')),
      head: R(q('#page-body-head')), done: R(q('#page-done')), edit: R(q('#page-edit')), comments: R(q('#page-comments-wrap')), crumb: R(q('#page-crumb')),
      empty: (() => { const e = q('#page-doc .page-doc__empty'); return e ? {tag: e.tagName, text: e.textContent.trim()} : null; })(),
      doneText: q('#page-done') ? q('#page-done').textContent.trim() : null, paper: q('#page-editor') ? q('#page-editor').getAttribute('data-paper-mode') : null,
      db: R(q('#db-wrap'))}); }
  return out; }"""
        if want('doc'):
            def docs():
                summary = {}
                def doc_of(text):
                    return {'schema': 1, 'blocks': [{'id': str(uuid.uuid4()), 'type': 'paragraph', 'text': [{'t': text, 'm': {}}]}]}
                def save_doc(pid, text):
                    v = call('GET', '/api/pages/' + pid)['page']['doc_version']
                    call('PATCH', '/api/pages/' + pid, {'doc': doc_of(text), 'doc_version': v})
                metrics['boards'] = sql("SELECT string_agg(id||':'||name||':'||coalesce(area,'')||':'||board_kind||':'||access_level, ' | ') FROM community.spaces WHERE kind='board'")
                def board_id(where, make):
                    got_ = sql("SELECT id FROM community.spaces WHERE kind='board' AND is_active AND " + where + " ORDER BY id LIMIT 1")
                    if not got_:   # 합성 DB 에 그 종류의 게시판이 없으면 같은 모양으로 하나(시드 게시판과 같은 열)
                        got_ = sql("INSERT INTO community.spaces (kind, name, description, access_level, notice, board_kind, area, created_by) VALUES " + make + " RETURNING id").splitlines()[0]
                    return int(got_)
                doc_a = call('POST', '/api/pages', {'title': 'W1c 문서'}, expected=201)['page']['id']
                save_doc(doc_a, 'W1c 문서 본문 한 줄')
                posts = {}
                for key, where, make in (('work', "area='work' AND board_kind IN ('qa','discussion') AND access_level='internal'", "('board','W1c 업무 게시판','합성','internal',false,'discussion','work',NULL)"),
                                         ('cafe', "area='cafe' AND board_kind='discussion' AND access_level='internal'", "('board','W1c 카페 게시판','합성','internal',false,'discussion','cafe',NULL)"),
                                         ('anon', "board_kind='anonymous'", "('board','W1c 익명 게시판','합성','internal',false,'anonymous','cafe',NULL)")):
                    pid = call('POST', '/api/boards/' + str(board_id(where, make)) + '/pages', {'title': 'W1c ' + key + ' 글'}, expected=201)['page']['id']
                    save_doc(pid, 'W1c ' + key + ' 글 본문')
                    posts[key] = pid
                plain_db = call('POST', '/api/pages', {'title': 'W1c 그냥 표', 'database': True}, expected=201)['page']['id']
                views = [('doc', doc_a), ('work', posts['work']), ('cafe', posts['cafe']), ('anon', posts['anon']), ('task', T4)]
                for width in (1440, 1280, 960, 412):
                    ctx = context('employee', width, 'light', width < 800)
                    t = Tab(ctx)
                    p = t.page
                    got = {}
                    for name, pid in views:
                        p.goto(site + '/community/p/' + pid, wait_until='networkidle')
                        p.wait_for_selector('#task-full .task-pane__title' if name == 'task' else '#page-doc > *', timeout=15000)
                        p.wait_for_timeout(250)
                        m = p.evaluate(COL_JS, 'task' if name == 'task' else 'doc')
                        got[name] = m
                        if width == 1440 and name in ('cafe', 'doc'):
                            p.screenshot(path=str(shots / ('w1c-%s-read-1440.png' % name)))
                        check(m['col'] and m['col']['w'] <= 960.5 and abs(m['body']['l'] - m['title']['l']) <= 1,
                              'W1c %s-%d: column ≤ 960 (%.0f) · body left = title left (%.0f / %.0f)' % (name, width, m['col']['w'], m['body']['l'], m['title']['l']))
                        if name != 'task':
                            check(m['body']['bs'] in ('none', 'hidden') or m['body']['bt'] == '0px', 'W1c %s-%d: read body has no border (%s %s)' % (name, width, m['body']['bt'], m['body']['bs']))
                            check(m['body']['bg'] in ('rgba(0, 0, 0, 0)', 'transparent') and m['body']['sh'] == 'none', 'W1c %s-%d: read body background 0 · shadow 0' % (name, width))
                            if m['comments'] and m['comments']['shown']:
                                check(abs(m['comments']['l'] - m['col']['l']) <= 1 and abs(m['comments']['w'] - m['col']['w']) <= 1 and m['comments']['bg'] in ('rgba(0, 0, 0, 0)', 'transparent')
                                      and m['comments']['bs'] in ('none', 'hidden'), 'W1c %s-%d: comments in the same column · no card box' % (name, width))
                            check(m['crumb'] is None or not m['crumb']['shown'] or abs(m['crumb']['l'] - m['col']['l']) <= 1, 'W1c %s-%d: crumb in the column' % (name, width))
                        check(m['overflow'] <= 0, 'W1c %s-%d: horizontal overflow 0' % (name, width))
                    xs = {k: (round(v['col']['l']), round(v['col']['w'])) for k, v in got.items()}
                    summary['cols-%d' % width] = xs
                    check(max(v[0] for v in xs.values()) - min(v[0] for v in xs.values()) <= 1 and max(v[1] for v in xs.values()) - min(v[1] for v in xs.values()) <= 1,
                          'W1c %d: doc · work post · cafe post · anon post · task full share one column (x, width) %s' % (width, xs))
                    # 빈 문서 — 고칠 수 있는 사람: 안내 누름 → 편집(도구 줄 상자 + 편집 칸 + 「편집 마침」). 폭마다 새 빈 문서
                    empty_a = call('POST', '/api/pages', {'title': 'W1c 빈 문서 %d' % width}, expected=201)['page']['id']
                    p.goto(site + '/community/p/' + empty_a, wait_until='networkidle')
                    p.wait_for_selector('#page-doc .page-doc__empty', timeout=15000)
                    m = p.evaluate(COL_JS, 'doc')
                    check(m['empty'] == {'tag': 'BUTTON', 'text': '누르면 내용을 적을 수 있어요'}, 'W1c empty-%d: editor sees 「누르면 내용을 적을 수 있어요」 (button) %s' % (width, m['empty']))
                    p.locator('#page-doc .page-doc__empty').click()
                    p.wait_for_selector('#page-editor .ProseMirror', timeout=15000)
                    p.wait_for_timeout(300)
                    m = p.evaluate(COL_JS, 'doc')
                    summary['edit-%d' % width] = {k: m[k] for k in ('col', 'bar', 'editor', 'head', 'done', 'edit', 'doneText', 'paper')}
                    if width == 1440:
                        p.screenshot(path=str(shots / 'w1c-doc-edit-1440.png'))
                    check(m['bar']['shown'] and abs(m['bar']['l'] - m['col']['l']) <= 1 and abs(m['bar']['w'] - m['col']['w']) <= 1,
                          'W1c edit-%d: toolbar box spans the column (%.0f/%.0f)' % (width, m['bar']['w'], m['col']['w']))
                    check(m['editor']['shown'] and abs(m['editor']['l'] - m['col']['l']) <= 1 and abs(m['editor']['w'] - m['col']['w']) <= 1 and m['editor']['h'] >= 256
                          and m['editor']['bs'] == 'solid' and m['paper'] is None,
                          'W1c edit-%d: editor box = column width · min height 16rem (h %.0f) · no paper mode by default' % (width, m['editor']['h']))
                    check(m['head']['shown'] and m['done']['shown'] and m['doneText'] == '편집 마침' and abs(m['done']['r'] - m['col']['r']) <= 1 and not m['edit']['shown'],
                          'W1c edit-%d: 「편집 마침」 at the right of the body head row · title-row 「편집」 hidden' % width)
                    p.keyboard.type('W1c 새 본문 %d' % width)
                    p.wait_for_timeout(300)
                    p.locator('#page-done').click()
                    p.wait_for_selector('#page-editor', state='hidden', timeout=15000)
                    p.wait_for_timeout(500)
                    m2 = p.evaluate(COL_JS, 'doc')
                    txt = p.locator('#page-doc').inner_text()
                    check(('W1c 새 본문 %d' % width) in txt and ('W1c 새 본문 %d' % width) in sql('SELECT plain_text FROM community.pages WHERE id=' + str(pk(empty_a)))
                          and not m2['head']['shown'] and m2['edit']['shown'], 'W1c edit-%d: 편집 마침 → saved · read flow again · 「편집」 back' % width)
                    # 작업 전체 화면과 같은 편집 칸
                    p.goto(site + '/community/p/' + T4, wait_until='networkidle')
                    p.wait_for_selector('#task-full .task-pane__doc', timeout=15000)
                    p.locator('#task-full .task-pane__doc').click()
                    p.wait_for_selector('#task-full .task-pane__doc .ProseMirror', timeout=15000)
                    p.wait_for_timeout(300)
                    mt = p.evaluate(COL_JS, 'task')
                    ed = summary['edit-%d' % width]
                    check(abs(mt['bar']['w'] - ed['bar']['w']) <= 1 and abs(mt['editor']['w'] - ed['editor']['w']) <= 1 and abs(mt['editor']['l'] - ed['editor']['l']) <= 1,
                          'W1c edit-%d: doc editor = task full editor (toolbar %.0f/%.0f · editor %.0f/%.0f)' % (width, ed['bar']['w'], mt['bar']['w'], ed['editor']['w'], mt['editor']['w']))
                    p.get_by_role('button', name='편집 마침').click()
                    p.wait_for_timeout(400)
                    # 데이터베이스 · 프로젝트 화면 폭 그대로(열 밖)
                    for label, pid in (('database', plain_db), ('project', proj['id'])):
                        p.goto(site + '/community/p/' + pid, wait_until='networkidle')
                        p.wait_for_selector('#db-wrap:not([hidden])', timeout=15000)
                        p.wait_for_timeout(300)
                        md_ = p.evaluate(COL_JS, 'doc')
                        check(abs(md_['db']['w'] - md_['mainInner']) <= 1, 'W1c %s-%d: width unchanged — #db-wrap = main width (%.0f / %.0f)' % (label, width, md_['db']['w'], md_['mainInner']))
                    check(t.clean('doc-%d' % width), 'W1c %d console 0 · page errors 0 · 5xx 0' % width)
                    ctx.close()
                # 보기만 하는 사람 — 편집 칸 0 · 「아직 내용이 없어요」(새 빈 문서)
                empty_b = call('POST', '/api/pages', {'title': 'W1c 빈 문서 둘'}, expected=201)['page']['id']
                call('PUT', '/api/pages/' + empty_b + '/shares/claude_manager01', {'level': 'view'})
                ctx = context('manager', 1440, 'light')
                t = Tab(ctx)
                p = t.page
                p.goto(site + '/community/p/' + empty_b, wait_until='networkidle')
                p.wait_for_selector('#page-doc .page-doc__empty', timeout=15000)
                m = p.evaluate(COL_JS, 'doc')
                check(m['empty'] == {'tag': 'P', 'text': '아직 내용이 없어요'} and not m['edit']['shown'] and not m['editor']['shown'],
                      'W1c view-only (view share): 「아직 내용이 없어요」 (text, not a button) · 편집 0 · editor 0')
                check(t.clean('doc-view'), 'W1c view-only console 0')
                ctx.close()
                # 종이 모드를 고른 사람 — 전과 같은 폭(A4 794 이상 · 열 밖)
                ctx = context('employee', 1440, 'light')
                ctx.add_init_script("try { localStorage.setItem('community-editor-paper', 'a4'); } catch (e) {}")
                t = Tab(ctx)
                p = t.page
                p.goto(site + '/community/p/' + doc_a, wait_until='networkidle')
                p.wait_for_selector('#page-edit:not([hidden])', timeout=15000)
                p.locator('#page-edit').click()
                p.wait_for_selector('#page-editor .ProseMirror', timeout=15000)
                p.wait_for_timeout(300)
                m = p.evaluate(COL_JS, 'doc')
                check(m['paper'] == 'a4' and m['editor']['w'] >= 794 and abs(m['editor']['w'] - m['mainInner']) <= 1 and m['editor']['h'] >= 1123,
                      'W1c paper A4 chosen: editor keeps the paper rule (w %.0f = main · h %.0f ≥ 1123)' % (m['editor']['w'], m['editor']['h']))
                p.locator('#page-done').click()
                p.wait_for_timeout(400)
                check(t.clean('doc-paper'), 'W1c paper console 0')
                ctx.close()
                metrics['doc'] = summary
            guard('doc', docs)

        browser.close()
finally:
    (out / 'qf12-w1-browser-metrics.json').write_text(json.dumps(metrics, ensure_ascii=False, indent=1))
    (out / 'qf12-w1-browser-results.json').write_text(json.dumps(checks, ensure_ascii=False, indent=2))
    print('RESULT pass=' + str(sum(x['pass'] for x in checks)) + ' fail=' + str(sum(not x['pass'] for x in checks)), flush=True)
if any(not x['pass'] for x in checks):
    raise SystemExit(1)
