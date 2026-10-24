#!/usr/bin/env python3
"""사이트 Q:F-15 S1 — 낱말 · 브랜드 맞추기 수락 시험 (실제 PHP · PostgreSQL + Chromium · 격리 틀 memories_harness.py 만)

  python3 tests/community/memories_harness.py --port 8768 --suite brand_words_browser.py --output <결과 폴더>/accept

지시서 docs/qf15-s1-words-directive.md 「수락 시험」 · DESIGN 5장 낱말 표(Q:F-15) · 「브랜드 이름 3층」 · 2.33 D.
S1b 보충(docs/qf15-s1b-words-directive.md 「수락 시험」) — S1 제외 셋을 풂(EXCLUDE 'home' · 'messages' · LEGAL_NO_ICON) · [static] ⑥ ⑦ · [pages] 더함(아래).
합성 DB · 임시 복사본 · 로컬 php -S 만(운영 8082 · 운영 DB · 시드 계정 0) · fixture claude_ 계정 · UA tbox-review/<날짜>.
결과 파일에는 단언 이름 · 참/거짓 · 측정값(글자 조각 · 수 · 대비)만 — 세션 · CSRF · 비밀번호 0.

  [static] (파일만 읽음 · 서버 0)
    ① Totp::otpauthUri — issuer 모아(%EB%AA%A8%EC%95%84) · 라벨 「모아:아이디」 · 아이디 32자로 Qr::encode 예외 0
    ② me_passkeys_options.php rp name 「모아」 · rp id 식 그대로(git diff 에서 rp_id 줄은 name 값 말고 0 변화)
    ③ push_manifest · alerts-sw.js · totp-generator.html · .webmanifest · site_manifest · Legal::TITLES · search.js 출처 · files_zip 이름 ·
       app.js 기본 zip 이름 — 사람이 보는 글자(주석 뺀 코드)에 Qaredi · t-box 0
    ④ usage-guide.js step 선택자 · modalContexts 키의 id 가 src · public(usage-guide.js 제외)에 정의돼 있다(죽은 선택자 0) ·
       「왼쪽 아이콘 줄」 「세 칸에 모아요」 「일상 기록뿐 아니라」 0
    ⑤ qaredi-icon-192/512.png 크기 그대로 · RGB · 네 귀퉁이 ≈ #6d28d9(±8) · moa-favicon.svg 에 script · href · 외부 주소 0 · 색은 2.33 값만
    ⑥ (S1b) moa.js · member-assist.js · home-dashboard.js · member_messages_page.php · member_invites.php · legal_page.php 의 문자열과
       routes.php community_not_ready() 몸통에 S1b 옛 글자(STATIC6_WORDS) 0(주석 제외) · 지시서 표의 「바꿀 글자」가 각 파일에 있다 · 그대로 둘 셋도 그대로
    ⑦ (S1b) community_not_ready() 몸통: h1 「업무」 · moa-favicon.svg <link> 한 줄 · http_response_code(503) · Http::error(503, …) · exit 있음 ·
       S1b 기준 판(S1B_BASE)과 견준 그 함수의 바뀐 줄이 문자열 셋 + <link> 한 줄뿐(갈래 · 헤더 · exit 무변경). 업무 준비 중 화면은 격리 틀에서 띄우지 않는다 — 이 ⑦ 로 갈음
  [pages]  1280 · 412 × 밝게 · 어둡게 × 직원 · 관리자 · 손님 · 로그인 전 — 화면마다 document.title · body innerText · [aria-label] ·
           [placeholder] · [data-tip] 에 금지 낱말 0 · 머리글 자리 영어 대문자 6자 이상 0 · link[rel=icon] = 모아 파비콘 · 넘침 0 · 콘솔 0
           (런처는 <details> 를 모두 펼친 뒤 한 번 더 · 문서는 편집 모드 「파일」 블록 창도)
  [must]   있어야 할 것 — 업무 사이드바 「업무 홈」 · 카페 사이드바 「우리 공간」 · 앱 판 「모아 홈 — 검색과 바로가기」 · 자료실 사이드바 「PC 비서 받기」 ·
           메신저 「모아 AI」 부제 · 로그인 title · 로그인 전 h1 · 파비콘 200 image/svg+xml · /tbox/app.webmanifest description · 경보 매니페스트 이름
           (S1b) /tbox/users(관리자) 「공급자: Google Gemini」 · /tbox/messages 「모아 AI」 부제 · / 대시보드 시작 안내 「모아 홈 시작 안내」 ·
           「홈 편집」 캔버스 제목 「내 대시보드 편집」(취소 — 저장 요청 0) · 「게시판 새 글」 묶음 글 있을 때 · 없을 때 「이야기」 「커뮤니티」 0
           (그 묶음이 보이는 홈 = 둘째 직원 claude_employee02 의 구성에 legacy_stories 위젯을 창구로 더한 화면 'home-feed') ·
           /tbox/privacy · /tbox/terms 파비콘 · 머리 note 한 줄 소개
  [contrast] 바뀐 글자 자리 대비 ≥ 4.5(밝게 · 어둡게 — 사진 · 그라데이션 바탕은 값만 기록)
  [shots]  로그인 전 1280 · 로그인 화면 412 다크 · 자료실 사이드바 1280 · 설치 아이콘 두 장 전후 · 파비콘

제외(근거 — 지시서 「바꾸지 않는 것」 · 수정 파일 목록 밖): EXCLUDE 표 · LEGAL_NO_ICON 을 본다. 제외한 자리는 결과 metrics 에 그대로 남긴다.
"""
from pathlib import Path
exec(compile(Path(__file__).with_name('memories_http.py').read_text().split('try:\n for u in users:')[0], __file__, 'exec'))
import re, secrets, struct, base64
from urllib.parse import urlsplit
from playwright.sync_api import sync_playwright
assert str(root).startswith('/tmp/qaredi-stage13-') and manifest['database'].startswith('tbox_memories_test_')
REPO = Path(__file__).resolve().parents[2]
host = urlsplit(site).hostname
UA = 'tbox-review/' + time.strftime('%Y-%m-%d')
ONLY = set(filter(None, os.environ.get('QF15_ONLY', '').split(',')))
QUICK = os.environ.get('QF15_QUICK') == '1'   # 고칠 때만 — 1280 밝게 한 벌(수락 실행은 전부)
shots = out / 'shots'
shots.mkdir(exist_ok=True)
metrics = {'excluded': {}, 'contrast': {}, 'hits': {}}
MIGRATION_CAFE = root / 'db/migrations/2026-10-14_community_cafe.sql'

FORBIDDEN = ['Qaredi', 't-box', '티박스', 'AI 비서', '공용 Gemini', '함께하는 일과 일상', '일과 일상을 연결', '업무, 자료, AI 비서', '함께하는 모든 순간',
             '오늘 챙길 일', '카페 홈', '내 홈', '동료에게 연락하기', '우리들의 이야기', '함께 보는 이야기', 'Gemini 3.5', '커뮤니티']
SLOGAN = '우리 회사의 일과 자료를 한곳에 모아.'

# 화면별 제외 — (선택자, 근거). 근거 없는 제외는 두지 않는다.
EXCLUDE = {
    # 지시서 「바꾸지 않는 것」 — 이야기 공개 범위 고르개의 「커뮤니티」(memories.js:23 등) · 작성 창은 이 단계 밖
    'memories': [('#compose-dialog', '작성 창 — 지시서 수락 시험 「단 /community/memories 의 공개 범위 고르개 · 작성 창은 제외」'),
                 ('#privacy-dialog', '공개 범위 고르개(기본 공개 범위) — 「바꾸지 않는 것」 이야기 공개 범위 낱말'),
                 ('.privacy-badge', '글의 공개 범위 표시(memories.js:23 labels) — 「바꾸지 않는 것」')],
    # (S1b — 'home' 의 .dashboard-guide > summary 제외를 풂: home-dashboard.js 「모아 홈 시작 안내」)
    # 법적 문서 본문(privacy-*.html · terms-*.html)은 손대지 않는다 — 머리 · 제목 · 바닥만 본다
    'privacy': [('article.wiki-article > :not(header.wiki-head):not(p.wiki-meta)', '법적 문서 본문 — 지시서 금지 패턴 「법적 문서 본문 손대기」')],
    'terms': [('article.wiki-article > :not(header.wiki-head):not(p.wiki-meta)', '법적 문서 본문 — 지시서 금지 패턴 「법적 문서 본문 손대기」')],
    # (S1b — 'messages' 의 #message-ai > small 제외를 풂: member_messages_page.php 「회사 공용 AI · 내 대화는 나만 봐요」)
}
# (S1b — 법적 문서 화면도 파비콘을 단다: legal_page.php · 빈 집합 = 모든 화면이 link[rel=icon] 단언)
LEGAL_NO_ICON = set()
# S1b [static] ⑥ — 일곱 자리 파일에 남으면 안 되는 옛 글자 · ⑦ 의 기준 판(S1b 지시서 「main 8a3977b 기준」)
STATIC6_WORDS = ['커뮤니티', '공용 Gemini', 'Gemini 3.5', '내 홈', '이야기를 불러오', '제목 없는 이야기', '첫 이야기', '우리의 이야기가 모이는 곳', '개인 업무 공간']
S1B_BASE = '8a3977b'
FAVICON_PHP = "<link rel=\"icon\" type=\"image/svg+xml\" href=\"<?= Http::url('/assets/moa-favicon.svg') ?>?v=<?= (int) filemtime(__DIR__ . '/../../public/assets/moa-favicon.svg') ?>\">"
FAVICON_ECHO = ("       . '<link rel=\"icon\" type=\"image/svg+xml\" href=\"' . htmlspecialchars(Http::url('/assets/moa-favicon.svg'), ENT_QUOTES, 'UTF-8') . '?v=' . "
                "(int) filemtime(__DIR__ . '/../../public/assets/moa-favicon.svg') . '\">'")


def check(ok, name):
    checks.append({'pass': bool(ok), 'name': name})
    print(('PASS ' if ok else 'FAIL ') + name, flush=True)
    return bool(ok)


def want(group): return not ONLY or group in ONLY


def guard(name, fn):
    try:
        fn()
    except Exception as error:  # noqa: BLE001 — 시험 흐름의 어떤 예외든 실패로 센다
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
            metrics['unclean-' + tag] = {'errors': [e[:200] for e in self.errors], 'bad': self.bad, 'console': [c[0][:200] for c in self.console]}
        return ok


# ── 화면 글자 모으기 — 제외 자리는 innerText 에서 빼고 속성은 건너뛴다 ────────────────────────────────
COLLECT_JS = r"""(excl) => {
  const inEx = (el) => excl.some(s => { try { return !!el.closest(s); } catch (e) { return false; } });
  let text = document.body.innerText;
  const removed = [];
  for (const s of excl) { for (const el of document.querySelectorAll(s)) { const t = el.innerText; if (t && t.trim()) { text = text.replace(t, ' '); removed.push(t.slice(0, 80)); } } }
  const attrs = [];
  for (const el of document.querySelectorAll('[aria-label],[placeholder],[data-tip]')) {
    if (inEx(el)) continue;
    for (const a of ['aria-label', 'placeholder', 'data-tip']) { const v = el.getAttribute(a); if (v) attrs.push(a + '=' + v); }
  }
  // 머리글 자리 — 머리글 클래스 + h1/h2 바로 앞 짧은 줄
  const heads = new Set([...document.querySelectorAll('.moa-kicker, .q-eyebrow, .eyebrow, .moa-overline, .moa-story-meta, [class*="kicker"], [class*="eyebrow"], [class*="overline"]')]);
  for (const h of document.querySelectorAll('h1, h2')) { const prev = h.previousElementSibling; if (prev && (prev.textContent || '').trim().length <= 40) heads.add(prev); }
  const headTexts = [...heads].filter(e => !inEx(e)).map(e => (e.innerText || e.textContent || '').trim()).filter(Boolean);
  const icons = [...document.querySelectorAll('link[rel~="icon"]')].map(l => ({href: l.getAttribute('href'), type: l.getAttribute('type'), abs: l.href}));
  return {title: document.title, text, attrs, headTexts, icons, removed, overflow: document.documentElement.scrollWidth - innerWidth};
}"""

UPPER_RUN = re.compile(r"[A-Z][A-Z\s,&'.!?:/-]*[A-Z]")


def upper_header(t):
    return any(sum(ch.isupper() for ch in m.group(0)) >= 6 for m in UPPER_RUN.finditer(t))


def hits_of(st):
    found = []
    for w in FORBIDDEN:
        if w in st['title']:
            found.append('title:' + w)
        if w in st['text']:
            i = st['text'].index(w)
            found.append('text:' + w + ' «' + st['text'][max(0, i - 30):i + 30].replace('\n', ' ') + '»')
        for a in st['attrs']:
            if w in a:
                found.append('attr:' + a[:90])
    return found


# 대비 — 바뀐 글자 자리(보이는 것만). 바탕은 조상의 바탕색을 겹쳐 계산 · 그림/그라데이션 바탕은 값만 남긴다(skip)
CONTRAST_JS = r"""(sels) => {
  const parse = (c) => { const m = String(c).match(/rgba?\(([^)]+)\)/); if (!m) return [0, 0, 0, 0]; const v = m[1].split(/[ ,/]+/).filter(Boolean).map(Number); return [v[0], v[1], v[2], v.length > 3 ? v[3] : 1]; };
  const lum = (rgb) => { const f = (x) => { x /= 255; return x <= 0.03928 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4); }; return 0.2126 * f(rgb[0]) + 0.7152 * f(rgb[1]) + 0.0722 * f(rgb[2]); };
  const over = (top, under) => { const a = top[3]; return [0, 1, 2].map(i => top[i] * a + under[i] * (1 - a)).concat(1); };
  const out = [];
  for (const sel of sels) {
    for (const el of document.querySelectorAll(sel)) {
      if (!el.getClientRects().length || getComputedStyle(el).visibility === 'hidden' || !(el.textContent || '').trim()) continue;
      const layers = []; let image = false;
      for (let n = el; n && n.nodeType === 1; n = n.parentElement) { const s = getComputedStyle(n); if (s.backgroundImage && s.backgroundImage !== 'none') image = true; const b = parse(s.backgroundColor); if (b[3] > 0) layers.push(b); if (b[3] >= 1) break; }
      let bg = [255, 255, 255, 1]; for (let i = layers.length - 1; i >= 0; i--) bg = over(layers[i], bg);
      const fg = over(parse(getComputedStyle(el).color), bg);
      const l1 = lum(fg), l2 = lum(bg);
      out.push({sel, text: el.textContent.trim().slice(0, 30), ratio: Math.round((Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05) * 100) / 100, image});
    }
  }
  return out;
}"""
CHANGED = ['#nav-work-home > span:not(.icon)', '#nav-cafe-home > span:not(.icon)', '#nav-programs > span:not(.icon)', '.app-menu__desc', '.moa-logo-note',
           '#landing-title', '.moa-kicker', '.q-eyebrow', '.eyebrow', '.moa-footer p:not(.legal-links)', '.login__brand-sub', '.login__lead', '.moa-service-label',
           '.moa-home-tools strong', '.moa-home-tools span', '#moa-feed-title', '.moa-story-meta', '.page-head__title', '#message-ai > small',
           '#moa-hero-title', '.moa-home-extra > summary', '.moa-community-feed .moa-section-head a', '.q-start-kind', '.moa-purpose-step',
           '.dashboard-guide > summary', '#member-ai-settings label.field:has(#ai-settings-enabled) > small']   # S1b — 시작 안내 머리 · 관리자 AI 설정 칸 아래 글
FEED_CHANGED = ['#moa-feed-status', '.moa-feed-row h3', '.moa-feed-row p']   # S1b — 「게시판 새 글」 묶음(게시판마다 잰다)
FEED_JS = r"""() => { const sec = document.querySelector('section.moa-community-feed'); if (!sec) return null;
  const s = document.getElementById('moa-feed-status');
  return {text: sec.innerText, attrs: [...sec.querySelectorAll('[aria-label]')].map(e => e.getAttribute('aria-label')),
          status: s ? s.textContent : null, rows: document.querySelectorAll('#moa-feed .moa-feed-row').length,
          cards: [...document.querySelectorAll('#moa-feed .moa-feed-row')].map(a => a.innerText.replace(/\s+/g, ' ').trim().slice(0, 120))}; }"""

try:
    for u in users:
        users[u]['username'] = sql('SELECT username FROM users WHERE id=' + str(users[u]['id']))
        call('GET', '/api/me/space', user=u)
    sql("INSERT INTO community.usage_guides(user_id,version,status,context) SELECT id,1,'dismissed','overview' FROM users ON CONFLICT DO NOTHING")
    # 동의 전 계정(팀장 — 지난 판 약관 줄 하나) — 동의 전 런처 · 동의 화면
    sql("INSERT INTO user_consents(user_id,doc,version,action,ip) VALUES(" + str(uid('manager')) + ",'terms','2000-01-01','agree','127.0.0.1')")
    # 게시판(운영 이주가 만든 모양) + 카페 이주 — 자유게시판 → 카페 · 익명게시판
    sql("INSERT INTO community.spaces (kind,name,description,access_level,notice,board_kind) VALUES "
        "('board','공지사항','합성 공지','public',true,'notice'),('board','자유게시판','자유롭게 이야기해요','internal',false,'discussion'),"
        "('board','질문과 답변','합성 질문','internal',false,'qa')")
    env = {k: v for k, v in os.environ.items() if not k.startswith('PG')}
    env['PGPASSFILE'] = str(Path.home() / '.config/qaredi/pgpass-testdb')
    mig = subprocess.run(['psql', '-h', '127.0.0.1', '-U', 'tbox_testdb', '-w', '-X', '-At', '-d', manifest['database'], '-v', 'ON_ERROR_STOP=1', '-f', str(MIGRATION_CAFE)],
                         env=env, capture_output=True, text=True)
    check(mig.returncode == 0, 'setup: cafe migration on the synthetic DB → rc 0')
    FREE = int(sql("SELECT id FROM community.spaces WHERE kind='board' AND name='자유게시판'"))
    QA = int(sql("SELECT id FROM community.spaces WHERE kind='board' AND name='질문과 답변'"))
    POST = call('POST', '/api/boards/' + str(FREE) + '/pages', {'title': 'Q:F-15 합성 게시판 글'}, user='employee', expected=201)['page']['id']
    DOC = call('POST', '/api/pages', {'title': 'Q:F-15 합성 문서'}, user='employee', expected=201)['page']['id']

    # S1b — 「게시판 새 글」 묶음이 보이는 모아 홈: 기본 구성(v3)에는 그 위젯이 없다(#home-stories hidden). 둘째 직원(claude_employee02)의 홈 구성에만
    # 옛 「이야기와 읽을거리」 위젯(legacy_stories — v1 → v3 올림이 만드는 모양 · dashboard-canvas.js upgrade)을 창구(PATCH)로 하나 더한다. 첫째 직원은 기본 그대로
    def tbox_api(method, path, user, body=None):
        h = {'User-Agent': UA, 'Cookie': '__Host-tbox_session=' + users[user]['session']}
        if method != 'GET':
            h['X-CSRF-Token'] = users[user]['csrf']
        return requests.request(method, site + '/tbox' + path, json=body, headers=h, timeout=35)

    def feed_layout():
        r = tbox_api('GET', '/api/me/home-dashboard', 'other')
        d = r.json() if r.status_code == 200 else {}
        lay = d.get('layout') or {}
        ws = lay.get('widgets') or []
        bottom = {k: max([w[k]['y'] + w[k]['h'] for w in ws] or [0]) for k in ('desktop', 'mobile')}
        ws.append({'id': 'widget-qf15-s1b-stories', 'type': 'legacy_stories', 'source': {}, 'visible': True, 'locked': False,
                   'desktop': {'x': 0, 'y': bottom['desktop'] + 24, 'w': 1200, 'h': 2000, 'size': 'l'},
                   'mobile': {'x': 0, 'y': bottom['mobile'] + 16, 'w': 360, 'h': 2000, 'size': 'l'}})
        r2 = tbox_api('PATCH', '/api/me/home-dashboard', 'other', {'layout': lay, 'revision': d.get('revision')})
        got = (r2.json().get('layout') or {}) if r2.status_code == 200 else {}
        check(r.status_code == 200 and lay.get('version') == 3 and r2.status_code == 200 and any(w.get('type') == 'legacy_stories' for w in got.get('widgets', [])),
              'setup: claude_employee02 home layout + legacy_stories widget (GET %s · v%s · PATCH %s)' % (r.status_code, lay.get('version'), r2.status_code))
    guard('setup feed layout', feed_layout)

    # ═══ [static] ═══════════════════════════════════════════════════════════════════
    if want('static'):
        def static_totp():
            php = r'''require "src/Totp.php"; require "src/Qr.php"; $s = Totp::generateSecret(); $u = Totp::otpauthUri("claude_employee01", $s);
                $long = Totp::otpauthUri(str_repeat("a", 32), $s); $ok = 1; try { $q = Qr::encode($long); $v = $q["version"]; } catch (Throwable $e) { $ok = 0; $v = 0; }
                echo json_encode(["uri" => preg_replace("/secret=[A-Z2-7]+/", "secret=X", $u), "qr" => $ok, "ver" => $v, "len" => strlen($long)]);'''
            r = subprocess.run(['php', '-r', php], cwd=root, capture_output=True, text=True)
            d = json.loads(r.stdout or '{}')
            metrics['totp'] = d
            uri = d.get('uri', '')
            label = uri.split('otpauth://totp/', 1)[-1].split('?', 1)[0]
            from urllib.parse import unquote
            check('issuer=%EB%AA%A8%EC%95%84&' in uri and unquote(label) == '모아:claude_employee01' and 't-box' not in uri,
                  '① otpauth issuer 모아(%EB%AA%A8%EC%95%84) · label 「모아:claude_employee01」 · t-box 0 (' + label + ')')
            check(d.get('qr') == 1, '① 32-char username → Qr::encode no exception (len %s · version %s)' % (d.get('len'), d.get('ver')))
        guard('static ①', static_totp)

        def static_passkey():
            src = (root / 'src/handlers/me_passkeys_options.php').read_text(encoding='utf-8')
            check("'rp'                     => ['id' => $cfg['rp_id'], 'name' => '모아']," in src and "'name' => 'Qaredi'" not in src,
                  "② rp {id: $cfg['rp_id'], name: '모아'} · 'Qaredi' 0")
            mb = subprocess.run(['git', '-C', str(REPO), 'merge-base', 'HEAD', 'main'], capture_output=True, text=True)
            base_ref = mb.stdout.strip() or 'HEAD'
            diff = subprocess.run(['git', '-C', str(REPO), 'diff', '--unified=0', base_ref, '--', 'src/handlers/me_passkeys_options.php'], capture_output=True, text=True).stdout
            minus = [l[1:] for l in diff.splitlines() if l.startswith('-') and not l.startswith('---')]
            plus = [l[1:] for l in diff.splitlines() if l.startswith('+') and not l.startswith('+++')]
            norm = lambda ls: sorted(re.sub(r"'name' => '[^']*'", "'name' => N", re.sub(r'name "[^"]*"', 'name N', x)) for x in ls if 'rp_id' in x or 'rp {' in x)
            others = [x for x in minus + plus if 'rp_id' not in x and 'rp {' not in x]
            metrics['passkey_diff'] = {'base': base_ref[:12], 'minus': len(minus), 'plus': len(plus)}
            check(norm(minus) == norm(plus) and others == [], '② git diff (%s): rp id expression unchanged — only the name value changed (%d − / %d +)' % (base_ref[:12], len(minus), len(plus)))
        guard('static ②', static_passkey)

        def strip_code(text, html=False):
            if html:
                text = re.sub(r'<!--.*?-->', ' ', text, flags=re.S)
            text = re.sub(r'/\*.*?\*/', ' ', text, flags=re.S)
            text = re.sub(r'(?m)^\s*(//|#(?!!)).*$', ' ', text)
            text = re.sub(r'(?m)\s//\s.*$', ' ', text)
            return text

        def static_names():
            bad = {}
            for rel, html in (('src/handlers/push_manifest.php', False), ('src/push/alerts-sw.js', False), ('public/assets/totp-generator.html', True),
                              ('src/handlers/site_manifest.php', False)):
                code = strip_code((root / rel).read_text(encoding='utf-8'), html)
                found = [w for w in ('Qaredi', 't-box') if w in code]
                if found:
                    bad[rel] = found
            wm = json.loads((root / 'public/assets/totp-generator.webmanifest').read_text(encoding='utf-8'))
            if any(w in json.dumps(wm, ensure_ascii=False) for w in ('Qaredi', 't-box')):
                bad['totp-generator.webmanifest'] = [wm.get('name'), wm.get('short_name')]
            titles = subprocess.run(['php', '-r', 'require "src/Legal.php"; echo json_encode(Legal::TITLES, JSON_UNESCAPED_UNICODE);'], cwd=root, capture_output=True, text=True).stdout
            metrics['legal_titles'] = titles
            if not titles or any(w in titles for w in ('Qaredi', 't-box')) or '모아 개인정보 처리방침' not in titles:
                bad['Legal::TITLES'] = titles[:120]
            src_search = (root / 'public/assets/search.js').read_text(encoding='utf-8')
            m = re.search(r"var info = \['([^']*)'\];", src_search)
            if not m or m.group(1) != '자료실':
                bad['search.js info'] = m and m.group(1)
            m = re.search(r"\$zipName = '([^']*)'", (root / 'src/handlers/files_zip.php').read_text(encoding='utf-8'))
            if not m or m.group(1) != 'moa-':
                bad['files_zip'] = m and m.group(1)
            m = re.search(r"return m \? m\[1\] : '([^']*)';", (root / 'public/assets/app.js').read_text(encoding='utf-8'))
            if not m or m.group(1) != 'moa.zip':
                bad['app.js zip'] = m and m.group(1)
            metrics['static_names'] = bad
            check(bad == {}, '③ push manifest · alerts-sw · totp generator (html · manifest) · site manifest · Legal::TITLES · search 출처 · zip names: Qaredi · t-box 0 ' + json.dumps(bad, ensure_ascii=False))
        guard('static ③', static_names)

        def static_guide():
            src = (root / 'public/assets/usage-guide.js').read_text(encoding='utf-8')
            sels = re.findall(r"step\('((?:[^'\\]|\\.)*)'", src)
            ids = set()
            for s in sels:
                ids.update(re.findall(r'#([A-Za-z][\w-]*)', s))
            mc = re.search(r'new Map\(\[(.*?)\]\);', src, re.S)
            keys = re.findall(r"\['([a-z0-9-]+)','[a-z]+'\]", mc.group(1) if mc else '') + re.findall(r"modalContexts\.set\('([a-z0-9-]+)'", src)
            ids.update(keys)
            corpus = []
            for d in ('src', 'public'):
                for p in (root / d).rglob('*'):
                    if p.is_file() and p.suffix in ('.php', '.js', '.html') and p.name != 'usage-guide.js' and 'vendor' not in p.parts and 'design-preview' not in p.parts:
                        corpus.append(p.read_text(encoding='utf-8', errors='replace'))
            blob = '\n'.join(corpus)
            dead = []
            for i in sorted(ids):
                e = re.escape(i)
                pat = r"""(?:\bid\s*=\s*["']%s["']|\bid\s*:\s*["']%s["']|\.id\s*=\s*["']%s["']|["']id["']\s*,\s*["']%s["'])""" % (e, e, e, e)
                if not re.search(pat, blob):
                    dead.append(i)
            metrics['guide'] = {'ids': len(ids), 'keys': keys, 'dead': dead}
            check(len(ids) >= 40 and dead == [], '④ usage-guide.js: every step selector id · modalContexts key is defined in src · public (%d ids · dead %s)' % (len(ids), dead))
            old = [w for w in ('왼쪽 아이콘 줄', '세 칸에 모아요', '일상 기록뿐 아니라') if w in src]
            check(old == [], '④ usage-guide.js old guide sentences 0 ' + json.dumps(old, ensure_ascii=False))
        guard('static ④', static_guide)

        def static_icons():
            res = {}
            for size in (192, 512):
                path = root / 'public/assets' / ('qaredi-icon-%d.png' % size)
                head = path.read_bytes()[:32]
                w, h = struct.unpack('>II', head[16:24])
                ctype = head[25]
                px = subprocess.run(['php', '-r', '$im = imagecreatefrompng($argv[1]); $n = imagesx($im) - 1; $o = []; foreach ([[0,0],[$n,0],[0,$n],[$n,$n]] as [$x,$y]) { $c = imagecolorat($im,$x,$y); $o[] = [($c>>16)&255,($c>>8)&255,$c&255]; } echo json_encode($o);',
                                     str(path)], capture_output=True, text=True).stdout
                corners = json.loads(px or '[]')
                ok_c = len(corners) == 4 and all(abs(c[0] - 0x6d) <= 8 and abs(c[1] - 0x28) <= 8 and abs(c[2] - 0xd9) <= 8 for c in corners)
                res[size] = {'w': w, 'h': h, 'color_type': ctype, 'corners': corners}
                check(head[:8] == b'\x89PNG\r\n\x1a\n' and (w, h) == (size, size) and ctype == 2 and ok_c,
                      '⑤ qaredi-icon-%d.png %dx%d · RGB · four corners ≈ #6d28d9 %s' % (size, w, h, corners))
            svg = (root / 'public/assets/moa-favicon.svg').read_text(encoding='utf-8')
            urls = [u for u in re.findall(r'https?://[^"\'\s)]+', svg) if u != 'http://www.w3.org/2000/svg']
            colors = sorted(set(c.lower() for c in re.findall(r'#[0-9a-fA-F]{6}\b', svg)))
            res['svg'] = {'urls': urls, 'colors': colors, 'bytes': len(svg)}
            metrics['icons'] = res
            check('<script' not in svg.lower() and 'href' not in svg.lower() and urls == [] and 'url(' not in svg and '@import' not in svg and 'viewBox="0 0 24 24"' in svg,
                  '⑤ moa-favicon.svg: script 0 · href 0 · outside address 0 · viewBox 24')
            check(colors == ['#6d28d9', '#f59e0b', '#ffffff'], '⑤ moa-favicon.svg colors = 2.33 D only (--wm-d · --wm-d-dot · --wm-on) %s' % colors)
        guard('static ⑤', static_icons)

        # ── S1b ⑥ ⑦ ───────────────────────────────────────────────────────────────
        def not_ready_body(text):
            i = text.index('function community_not_ready(bool $isApi): void')
            return text[i:text.index('\n}\n', i) + 2]

        def static_s1b_words():
            # 파일 → 주석 뺀 코드에 있어야 할 「바꿀 글자」(+ 그대로 둘 글자) — S1b 지시서 「바꿀 글자 표」 #1~#7c 와 「그대로」 셋
            want_new = {
                'src/handlers/member_messages_page.php': ['<small>회사 공용 AI · 내 대화는 나만 봐요</small>'],
                'public/assets/home-dashboard.js': ["pending?'모아 홈 시작 안내':'시작 안내'", "title:'내 대시보드 편집'"],
                'public/assets/moa.js': ['오늘 발견한 작은 초록을 우리 공간에 남겨 보는 건 어떨까요?', "'계정 설정을 마치면 게시판 새 글을 볼 수 있어요.'",
                                         "'게시판 새 글을 불러오지 못했어요. 우리 공간에서 다시 확인해 주세요.'", "status.textContent='게시판 새 글을 불러오고 있어요.'",
                                         "p.title||'제목 없는 글'", "p.excerpt||'글을 열어 보세요.'", "'아직 새 글이 없어요. 우리 공간에서 첫 글을 남겨 보세요.'",
                                         "'아직 열린 게시판이 없어요. 내 공간에서 기록을 시작해 보세요.'"],
                'public/assets/member-assist.js': ["'모아 AI 결과'", "tell('모아 AI 설정을 확인하고 있어요…')", "'관리자가 Gemini 키를 등록하고 AI 사용을 켜면 이용할 수 있습니다.'"],
                'src/views/member_invites.php': ['<small>공급자: Google Gemini</small>', 'Gemini 3.1 Flash Lite Image로 이미지를 만들고'],
                'src/handlers/legal_page.php': [FAVICON_PHP, '<span class="moa-logo-note">우리 회사의 일과 자료를 한곳에 모아.</span>'],
            }
            res = {}
            for rel, needles in want_new.items():
                code = strip_code((root / rel).read_text(encoding='utf-8'), html=rel.endswith('.php'))
                res[rel] = {'old': [w for w in STATIC6_WORDS if w in code], 'missing': [n[:40] for n in needles if n not in code]}
            code = strip_code(not_ready_body((root / 'src/community/routes.php').read_text(encoding='utf-8')))
            res['routes.php community_not_ready()'] = {'old': [w for w in STATIC6_WORDS if w in code],
                                                       'missing': [n[:40] for n in ("Http::error(503, '업무는 아직 준비 중입니다');", "'<h1 class=\"page-head__title\">업무</h1>'",
                                                                                    "'<p class=\"page-head__sub\">작업 · 프로젝트 · 문서</p></div></header>'") if n not in code]}
            # 법적 문서 화면: 파비콘 줄은 app.css 줄 바로 위(member_top.php 와 같은 꼴)
            legal = (root / 'src/handlers/legal_page.php').read_text(encoding='utf-8').splitlines()
            at = [i for i, l in enumerate(legal) if l == FAVICON_PHP]
            ordered = len(at) == 1 and legal[at[0] + 1].startswith('<link rel="stylesheet" href="<?= Http::url(\'/assets/app.css\') ?>')
            res['legal_page.php favicon order'] = {'old': [], 'missing': [] if ordered else ['favicon line right above app.css']}
            metrics['static6'] = res
            bad = {k: v for k, v in res.items() if v['old'] or v['missing']}
            check(bad == {}, '⑥ S1b seven spots: old words %s 0 (comments stripped) · new words present in each file · kept lines kept %s'
                  % ('/'.join(STATIC6_WORDS), json.dumps(bad, ensure_ascii=False)))
        guard('static ⑥', static_s1b_words)

        def static_not_ready():
            import difflib
            lines = not_ready_body((root / 'src/community/routes.php').read_text(encoding='utf-8')).splitlines()
            need = {'h1 업무': "       . '<h1 class=\"page-head__title\">업무</h1>'", 'favicon <link>': FAVICON_ECHO,
                    'http_response_code(503)': '        http_response_code(503);', 'Http::error(503, …)': "        Http::error(503, '업무는 아직 준비 중입니다');", 'exit': '    exit;'}
            missing = [k for k, v in need.items() if lines.count(v) != 1]
            check(missing == [], '⑦ community_not_ready(): h1 「업무」 · moa-favicon <link> line · http_response_code(503) · Http::error(503, …) · exit present (missing %s)' % missing)
            old_src = subprocess.run(['git', '-C', str(REPO), 'show', S1B_BASE + ':src/community/routes.php'], capture_output=True, text=True)
            before = not_ready_body(old_src.stdout).splitlines() if old_src.returncode == 0 else []
            minus, plus = [], []
            for op, a0, a1, b0, b1 in difflib.SequenceMatcher(a=before, b=lines, autojunk=False).get_opcodes():
                if op != 'equal':
                    minus += before[a0:a1]
                    plus += lines[b0:b1]
            want_minus = ["        Http::error(503, '커뮤니티는 아직 준비 중입니다');", "       . '<h1 class=\"page-head__title\">커뮤니티</h1>'",
                          "       . '<p class=\"page-head__sub\">개인 업무 공간</p></div></header>'"]
            want_plus = ["        Http::error(503, '업무는 아직 준비 중입니다');", FAVICON_ECHO, "       . '<h1 class=\"page-head__title\">업무</h1>'",
                         "       . '<p class=\"page-head__sub\">작업 · 프로젝트 · 문서</p></div></header>'"]
            metrics['not_ready_diff'] = {'base': S1B_BASE, 'base_found': bool(before), 'minus': minus, 'plus': plus}
            check(bool(before) and sorted(minus) == sorted(want_minus) and sorted(plus) == sorted(want_plus),
                  '⑦ community_not_ready() vs %s: changed lines = 3 strings + 1 favicon <link> only (−%d / +%d · branch · headers · exit unchanged)' % (S1B_BASE, len(minus), len(plus)))
        guard('static ⑦', static_not_ready)

    # ═══ [must] HTTP — 파비콘 · 매니페스트 ═══════════════════════════════════════════════
    if want('must'):
        def must_http():
            r = requests.get(site + '/tbox/assets/moa-favicon.svg', headers={'User-Agent': UA}, timeout=30)
            check(r.status_code == 200 and r.headers.get('Content-Type', '').startswith('image/svg+xml') and r.content == (root / 'public/assets/moa-favicon.svg').read_bytes(),
                  '[must] /tbox/assets/moa-favicon.svg 200 · image/svg+xml · same bytes (%s %s)' % (r.status_code, r.headers.get('Content-Type')))
            r = requests.get(site + '/tbox/app.webmanifest', headers={'User-Agent': UA}, timeout=30)
            d = r.json() if r.status_code == 200 else {}
            metrics['app_manifest'] = {k: d.get(k) for k in ('name', 'short_name', 'description')}
            check(r.status_code == 200 and d.get('description') == SLOGAN and not any(w in json.dumps(d, ensure_ascii=False) for w in ('Qaredi', 't-box', 'AI 비서')),
                  '[must] /tbox/app.webmanifest description 「%s」 · old names 0 (%s)' % (SLOGAN, d.get('description')))
            tok = new_session('admin', reauth=True)
            r = requests.get(site + '/tbox/alerts-app.webmanifest', headers={'User-Agent': UA, 'Cookie': '__Host-tbox_session=' + tok}, timeout=30)
            d = r.json() if r.status_code == 200 else {}
            metrics['alerts_manifest'] = {k: d.get(k) for k in ('name', 'short_name', 'description')}
            check(r.status_code == 200 and d.get('name') == '모아 관리자 경보' and d.get('short_name') == '모아 경보' and d.get('description') == '관리자 휴대폰으로 모아 경보를 받습니다',
                  '[must] alerts manifest name 「모아 관리자 경보」 · short 「모아 경보」 · description (%s · %s)' % (r.status_code, d.get('name')))
            r = requests.get(site + '/tbox/assets/totp-generator.webmanifest', headers={'User-Agent': UA}, timeout=30)
            d = r.json() if r.status_code == 200 else {}
            check(d.get('name') == '모아 2단계 코드' and d.get('short_name') == '모아 코드', '[must] totp generator manifest 「모아 2단계 코드」 · 「모아 코드」')
        guard('must http', must_http)

    with sync_playwright() as pw:
        browser = pw.chromium.launch(executable_path=os.environ.get('CHROMIUM_EXECUTABLE'))

        def context(key, width, theme='light', reauth=False):
            touch = width < 720
            ctx = browser.new_context(viewport={'width': width, 'height': 900}, has_touch=touch, is_mobile=touch, user_agent=UA,
                                      reduced_motion='reduce', color_scheme='dark' if theme == 'dark' else 'light')
            jar = [{'name': 'qaredi_motion', 'value': 'reduce', 'domain': host, 'path': '/'}, {'name': 'tbox_theme', 'value': theme, 'domain': host, 'path': '/'},
                   {'name': 'qaredi_sidebar', 'value': 'open', 'domain': host, 'path': '/'}]
            if key:
                jar.append({'name': '__Host-tbox_session', 'value': new_session(key, reauth), 'domain': host, 'path': '/', 'secure': True, 'httpOnly': True, 'sameSite': 'Lax'})
            ctx.add_cookies(jar)
            return ctx

        SCREENS = [('landing', None, '/'), ('consent-launcher', 'manager', '/'), ('home', 'employee', '/'), ('home-admin', 'admin', '/'), ('home-guest', 'guest', '/'),
                   ('login', None, '/tbox/login'), ('login-tbox', None, '/tbox/login?to=tbox'), ('join', None, '/tbox/join'), ('request-invite', None, '/tbox/request-invite'),
                   ('consent', 'manager', '/tbox/consent'), ('privacy', None, '/tbox/privacy'), ('terms', None, '/tbox/terms'),
                   ('files', 'employee', '/tbox/'), ('files-admin', 'admin', '/tbox/'), ('files-guest', 'guest', '/tbox/'), ('settings', 'employee', '/tbox/settings'),
                   ('welcome', 'employee', '/tbox/welcome'), ('welcome-pc', 'employee', '/tbox/welcome?section=pc-assistant'), ('programs', 'employee', '/tbox/programs'),
                   ('messages', 'employee', '/tbox/messages'), ('alerts-app', 'admin', '/tbox/alerts-app'), ('not-found', 'employee', '/tbox/qf15-no-such-screen'),
                   ('users', 'admin', '/tbox/users'),   # S1b — 관리자 「모아 AI 설정」(member_invites.php)
                   ('not-found-out', None, '/tbox/qf15-no-such-screen'),
                   ('work-home', 'employee', '/community/'), ('work-home-guest', 'guest', '/community/'), ('my-tasks', 'employee', '/community/my-tasks'),
                   ('workspace', 'employee', '/community/workspace'), ('cafe', 'employee', '/community/cafe'), ('cafe-guest', 'guest', '/community/cafe'),
                   ('board', 'employee', '/community/b/' + str(QA)), ('cafe-board', 'employee', '/community/b/' + str(FREE)),
                   ('post', 'employee', '/community/p/' + POST), ('doc', 'employee', '/community/p/' + DOC), ('memories', 'employee', '/community/memories'),
                   ('home-feed', 'other', '/')]   # S1b — 「게시판 새 글」 묶음(legacy_stories 위젯이 보이는 홈 · 둘째 직원)

        def excl_for(name):
            return EXCLUDE.get(name.split('-')[0] if name.split('-')[0] in EXCLUDE else name, [])

        def look(p, name, tag, stage=''):
            ex = excl_for(name)
            st = p.evaluate(COLLECT_JS, [s for s, _ in ex])
            if st['removed']:
                metrics['excluded'][tag + stage] = {'selectors': [[s, why] for s, why in ex], 'texts': st['removed'][:6]}
            hits = hits_of(st)
            heads = [h for h in st['headTexts'] if upper_header(h)]
            if hits or heads:
                metrics['hits'][tag + stage] = {'words': hits[:12], 'heads': heads[:6]}
            check(hits == [], '[pages] %s%s: forbidden words 0 in title · text · aria-label · placeholder · data-tip %s' % (tag, stage, json.dumps(hits[:4], ensure_ascii=False)))
            check(heads == [], '[pages] %s%s: English upper-case header (≥ 6 letters) 0 %s' % (tag, stage, json.dumps(heads[:3], ensure_ascii=False)))
            return st

        # ── [pages] ───────────────────────────────────────────────────────────────
        if want('pages'):
            def pages():
                summary = {}
                for width in ((1280,) if QUICK else (1280, 412)):
                    for theme in (('light',) if QUICK else ('light', 'dark')):
                        for name, who, path in SCREENS:
                            tag = '%s-%d-%s' % (name, width, theme)
                            ctx = context(who, width, theme, reauth=(name in ('alerts-app', 'users')))   # S1b — /tbox/users 도 비밀번호 다시 확인 뒤 화면
                            t = Tab(ctx)
                            p = t.page
                            resp = p.goto(site + path, wait_until='networkidle')
                            p.wait_for_timeout(250)
                            status = resp.status if resp else None
                            want_status = 404 if name.startswith('not-found') else 200
                            check(status == want_status, '[pages] %s: HTTP %s (want %s)' % (tag, status, want_status))
                            st = look(p, name, tag)
                            icons = st['icons']
                            if name in LEGAL_NO_ICON:
                                metrics['excluded'].setdefault('icon:' + name, 'src/handlers/legal_page.php — 표 H 파비콘 목록 · 수정 파일 밖(보고서 「못 한 것」)')
                            else:
                                ok_icon = len(icons) == 1 and urlsplit(icons[0]['abs']).path == '/tbox/assets/moa-favicon.svg' and icons[0]['type'] == 'image/svg+xml' and re.search(r'\?v=\d+$', icons[0]['href'] or '')
                                check(ok_icon, '[pages] %s: link[rel=icon] → /tbox/assets/moa-favicon.svg?v=… (%s)' % (tag, json.dumps([i['href'] for i in icons])))
                            check(st['overflow'] <= 0, '[pages] %s: horizontal overflow 0 (%s)' % (tag, st['overflow']))
                            # 스크린샷(펼치기 전 — 사람이 처음 보는 모양)
                            if (name, width, theme) in (('landing', 1280, 'light'), ('login', 412, 'dark'), ('files', 1280, 'light'), ('home', 1280, 'light'),
                                                        ('work-home', 1280, 'light'), ('cafe', 1280, 'light'), ('login-tbox', 1280, 'light')):
                                p.screenshot(path=str(shots / ('%s-%d-%s.png' % (name, width, theme))), full_page=(name == 'landing'))
                            # 펼침 — 런처의 <details>(대시보드 · 읽을거리 · 게시판 새 글) · 문서의 편집 모드 「파일」 블록 창
                            if name in ('landing', 'home', 'consent-launcher', 'home-feed'):
                                p.evaluate("() => document.querySelectorAll('details').forEach(d => { d.open = true; })")
                                p.wait_for_timeout(600)
                                # 게시판 새 글(moa.js)이 다 불러올 때까지 — 「…불러오고 있어요」가 남아 있으면 기다린다(최대 8초 · CSP 라 evaluate 되풀이)
                                for _ in range(80):
                                    if not p.evaluate("(() => { const s = document.getElementById('moa-feed-status'); return !!s && /불러오고/.test(s.textContent); })()"):
                                        break
                                    p.wait_for_timeout(100)
                                metrics.setdefault('feed_status', {})[tag] = p.evaluate("(() => { const s = document.getElementById('moa-feed-status'); return s ? s.textContent : null; })()")
                                look(p, name, tag, ' (details open)')
                            if name == 'home-feed':
                                # ── S1b 「게시판 새 글」 묶음 — 게시판(탭)마다 눌러 글 있을 때 · 없을 때 둘 다 본다(상태 · 카드 · aria-label) ──
                                def feed_settle():
                                    for _ in range(80):
                                        if not p.evaluate("(() => { const s = document.getElementById('moa-feed-status'); return !!s && /불러오고/.test(s.textContent); })()"):
                                            return
                                        p.wait_for_timeout(100)
                                seen = []
                                tabs = p.locator('#moa-board-tabs > button')
                                for i in range(tabs.count()):
                                    b = tabs.nth(i)
                                    b.click()
                                    loading = p.evaluate("(() => { const s = document.getElementById('moa-feed-status'); return s ? s.textContent : null; })()")
                                    p.wait_for_timeout(150)
                                    feed_settle()
                                    f = p.evaluate(FEED_JS) or {'text': '', 'attrs': [], 'status': None, 'rows': -1, 'cards': []}
                                    blob = '\n'.join([f['text'] or '', loading or ''] + f['attrs'])
                                    fc = [c for c in p.evaluate(CONTRAST_JS, FEED_CHANGED) if not c['image']]
                                    seen.append({'board': b.inner_text().strip(), 'loading': loading, 'status': f['status'], 'rows': f['rows'], 'cards': f['cards'][:3],
                                                 'words': [w for w in ('이야기', '커뮤니티') if w in blob], 'low': [(c['text'], c['ratio']) for c in fc if c['ratio'] < 4.5], 'rated': len(fc)})
                                metrics.setdefault('feed_boards', {})[tag] = seen
                                brief = json.dumps([(x['board'], x['rows'], (x['status'] or '')[:30], x['words']) for x in seen], ensure_ascii=False)
                                check(len(seen) >= 2 and all(not x['words'] for x in seen), '[pages] %s: 「게시판 새 글」 every board — loading · status · cards · aria-label 「이야기」 「커뮤니티」 0 %s' % (tag, brief))
                                check(any(x['rows'] > 0 and x['status'] == '' and any('Q:F-15 합성 게시판 글' in c for c in x['cards']) for x in seen),
                                      '[pages] %s: 「게시판 새 글」 board with posts — card 「Q:F-15 합성 게시판 글」 shown' % tag)
                                check(any(x['rows'] == 0 and x['status'] == '아직 새 글이 없어요. 우리 공간에서 첫 글을 남겨 보세요.' for x in seen),
                                      '[pages] %s: 「게시판 새 글」 board without posts — 「아직 새 글이 없어요. 우리 공간에서 첫 글을 남겨 보세요.」' % tag)
                                check(all(not x['low'] for x in seen), '[contrast] %s: 「게시판 새 글」 status · cards ≥ 4.5 (low %s)' % (tag, json.dumps([x['low'] for x in seen if x['low']][:2], ensure_ascii=False)))
                                if (width, theme) == (1280, 'light'):
                                    p.locator('section.moa-community-feed').screenshot(path=str(shots / 'home-feed-1280-light.png'))
                            if name == 'home':
                                # ── S1b 대시보드 시작 안내 · 「홈 편집」 캔버스 제목 — 취소로 닫는다(저장 요청 0) ──
                                p.wait_for_selector('.dashboard-guide > summary', timeout=10000)
                                g = p.evaluate("(() => [...document.querySelectorAll('.dashboard-guide > summary')].map(x => x.textContent))()")
                                check(g == ['모아 홈 시작 안내'], '[must] %s: dashboard start guide summary 「모아 홈 시작 안내」 · 「내 홈」 0 (%s)' % (tag, g))
                                writes = []

                                def on_request(r):
                                    if r.method not in ('GET', 'HEAD'):
                                        writes.append(r.method + ' ' + urlsplit(r.url).path)
                                p.on('request', on_request)
                                p.wait_for_selector('#home-edit:not([disabled])', timeout=10000)
                                p.locator('#home-edit').click()
                                p.wait_for_selector('.dashboard-editor > h2', timeout=10000)
                                p.wait_for_timeout(200)
                                ed = p.evaluate("(() => [...document.querySelectorAll('.dashboard-editor')].map(s => ({h2: s.querySelector('h2').textContent, label: s.getAttribute('aria-label')})))()")
                                look(p, name, tag, ' (home edit)')
                                check(ed == [{'h2': '내 대시보드 편집', 'label': '내 대시보드 편집'}], '[must] %s: home edit canvas title 「내 대시보드 편집」 (h2 · aria-label) (%s)' % (tag, ed))
                                ec = [c for c in p.evaluate(CONTRAST_JS, ['.dashboard-editor > h2']) if not c['image']]
                                check(ec != [] and all(c['ratio'] >= 4.5 for c in ec), '[contrast] %s: home edit canvas title ≥ 4.5 %s' % (tag, [(c['text'], c['ratio']) for c in ec]))
                                if (width, theme) == (1280, 'light'):
                                    p.screenshot(path=str(shots / 'home-edit-1280-light.png'))
                                p.locator('.dashboard-editor').get_by_role('button', name='취소', exact=True).click()
                                p.wait_for_timeout(400)
                                closed = p.evaluate("(() => !document.querySelector('.dashboard-editor'))()")
                                p.remove_listener('request', on_request)
                                check(closed and not [w for w in writes if '/api/me/home-dashboard' in w],
                                      '[pages] %s: home edit closed with 「취소」 — home-dashboard save requests 0 (non-GET %s)' % (tag, writes))
                            if name == 'doc' and width == 1280:
                                p.locator('#page-edit').click()
                                p.wait_for_selector('#page-editor .ProseMirror', timeout=10000)
                                if p.locator('#page-add-block').is_visible():
                                    p.locator('#page-add-block').click()
                                else:
                                    p.locator('.editor-toolbar__row > button', has_text='블록 추가').first.click()
                                p.wait_for_selector('#block-dialog[open]', timeout=5000)
                                p.locator('#block-list .block-pick[data-block-type="file"]').click()
                                p.locator('#block-insert').click()
                                p.wait_for_selector('#atom-dialog[open] #atom-file:not([hidden])', timeout=5000)
                                p.wait_for_timeout(300)
                                dlg = p.evaluate("""() => { const d = document.getElementById('atom-dialog'); return {text: d.innerText, label: [...d.querySelectorAll('.field__label')].map(e => e.textContent.trim()),
                                                   up: (document.getElementById('atom-file-upload') || {}).textContent}; }""")
                                metrics.setdefault('file_block', {})[tag] = {'label': dlg['label'][:3], 'up': dlg['up']}
                                look(p, name, tag, ' (edit · file block dialog)')
                                check('내 자료실 파일에서 고르기' in dlg['label'] and (dlg['up'] or '').strip() == '자료실에 올리기',
                                      '[pages] %s: file block dialog 「내 자료실 파일에서 고르기」 · 「자료실에 올리기」' % tag)
                                if theme == 'light':
                                    p.screenshot(path=str(shots / 'doc-file-block-1280.png'))
                                p.keyboard.press('Escape')
                            # 있어야 할 것
                            if name == 'work-home':
                                w = p.evaluate("(() => { const a = document.getElementById('nav-work-home'); return a ? {t: a.querySelector('span:not(.icon)').textContent, tip: a.getAttribute('data-tip')} : null; })()")
                                check(w == {'t': '업무 홈', 'tip': '업무 홈'}, '[must] %s: work sidebar #nav-work-home 「업무 홈」 · data-tip (%s)' % (tag, w))
                            if name == 'cafe':
                                w = p.evaluate("(() => { const a = document.getElementById('nav-cafe-home'); return a ? {t: a.querySelector('span:not(.icon)').textContent, tip: a.getAttribute('data-tip')} : null; })()")
                                check(w == {'t': '우리 공간', 'tip': '우리 공간'}, '[must] %s: cafe sidebar #nav-cafe-home 「우리 공간」 · data-tip (%s)' % (tag, w))
                            if name in ('files', 'work-home', 'home'):
                                a = p.evaluate("""(() => { const a = document.querySelector('.app-menu__app[href="/"]'); return a ? a.querySelector('.app-menu__name').textContent + ' — ' + a.querySelector('.app-menu__desc').textContent : null; })()""")
                                check(a == '모아 홈 — 검색과 바로가기', '[must] %s: app panel 「모아 홈 — 검색과 바로가기」 (%s)' % (tag, a))
                            if name == 'files':
                                g = p.evaluate("(() => { const a = document.getElementById('nav-programs'); return a ? a.querySelector('span:not(.icon)').textContent : null; })()")
                                check(g == 'PC 비서 받기', '[must] %s: library sidebar #nav-programs 「PC 비서 받기」 (%s)' % (tag, g))
                            if name in ('home', 'files'):
                                m = p.evaluate("(() => { const s = document.querySelector('#messenger-window #message-ai > small'); return s ? s.textContent : null; })()")
                                check(m == '회사 공용 AI · 내 대화는 나만 봐요', '[must] %s: messenger 「모아 AI」 subtitle 「회사 공용 AI · 내 대화는 나만 봐요」 (%s)' % (tag, m))
                            if name == 'login':
                                check(st['title'] == '로그인 · 모아', '[must] %s: title 「로그인 · 모아」 (%s)' % (tag, st['title']))
                            if name == 'login-tbox':
                                check(st['title'] == '로그인 · 모아 자료실', '[must] %s: title 「로그인 · 모아 자료실」 (%s)' % (tag, st['title']))
                                ic = p.evaluate("(() => { const m = document.querySelector('.login__mark .icon'); return m ? {icon: m.getAttribute('data-icon'), svg: !!m.querySelector('svg')} : null; })()")
                                check(ic == {'icon': 'library', 'svg': True}, '[must] %s: 「모아 자료실」 pane icon library painted (%s)' % (tag, ic))
                            if name == 'landing':
                                h = p.evaluate("(() => { const h = document.getElementById('landing-title'); return h ? h.textContent : null; })()")
                                check(h == SLOGAN, '[must] %s: signed-out h1 「%s」 (%s)' % (tag, SLOGAN, h))
                            # ── S1b 있어야 할 것 ──
                            if name == 'users':
                                u = p.evaluate("(() => { const s = [...document.querySelectorAll('#member-ai-settings label.field:has(#ai-settings-enabled) > small')]; return s.length === 1 ? {t: s[0].textContent, shown: s[0].getClientRects().length > 0} : s.length; })()")
                                check(u == {'t': '공급자: Google Gemini', 'shown': True} and 'Gemini 3.5' not in st['text'] and 'Gemini 3.5' not in p.content(),
                                      '[must] %s: admin AI settings 「공급자: Google Gemini」 shown · 「Gemini 3.5」 0 (%s)' % (tag, u))
                            if name == 'messages':
                                m = p.evaluate("(() => { const s = [...document.querySelectorAll('#message-ai > small')]; return s.map(x => x.textContent); })()")
                                check(m == ['회사 공용 AI · 내 대화는 나만 봐요'], '[must] %s: full-screen messenger 「모아 AI」 subtitle 「회사 공용 AI · 내 대화는 나만 봐요」 (%s)' % (tag, m))
                            if name in ('privacy', 'terms'):
                                n = p.evaluate("(() => [...document.querySelectorAll('.moa-logo-note')].map(x => x.textContent))()")
                                check(n == [SLOGAN], '[must] %s: header note 「%s」 (%s)' % (tag, SLOGAN, n))
                            # 대비 — 바뀐 글자 자리
                            cs = p.evaluate(CONTRAST_JS, CHANGED)
                            rated = [c for c in cs if not c['image']]
                            low = [c for c in rated if c['ratio'] < 4.5]
                            metrics['contrast'][tag] = {'rated': [(c['sel'], c['text'], c['ratio']) for c in rated][:30], 'image_bg': [(c['sel'], c['text'], c['ratio']) for c in cs if c['image']][:10]}
                            check(low == [], '[contrast] %s: changed text spots ≥ 4.5 (%d rated · low %s)' % (tag, len(rated), json.dumps([(c['text'], c['ratio']) for c in low][:4], ensure_ascii=False)))
                            check(t.clean(tag), '[pages] %s: console errors 0 · page errors 0 · 5xx 0' % tag)
                            summary[tag] = {'title': st['title']}
                            ctx.close()
                metrics['pages'] = summary
            guard('pages', pages)

        # ── [shots] 설치 아이콘 두 장 전후 · 파비콘 ───────────────────────────────────────────
        if want('shots'):
            def icon_shots():
                mb = subprocess.run(['git', '-C', str(REPO), 'merge-base', 'HEAD', 'main'], capture_output=True, text=True)
                base_ref = mb.stdout.strip() or 'HEAD'
                cells = []
                for size in (192, 512):
                    rel = 'public/assets/qaredi-icon-%d.png' % size
                    before = subprocess.run(['git', '-C', str(REPO), 'show', base_ref + ':' + rel], capture_output=True).stdout
                    after = (root / rel).read_bytes()
                    cells.append((size, base64.b64encode(before).decode(), base64.b64encode(after).decode()))
                svg = base64.b64encode((root / 'public/assets/moa-favicon.svg').read_bytes()).decode()
                ctx = browser.new_context(viewport={'width': 1100, 'height': 640}, user_agent=UA)
                p = ctx.new_page()
                p.route('**/*', lambda route: route.abort())   # 외부 요청 0 — set_content 한 장만
                rows = ''.join('<figure><figcaption>전 %d</figcaption><img width="%d" height="%d" src="data:image/png;base64,%s"></figure>'
                               '<figure><figcaption>후 %d</figcaption><img width="%d" height="%d" src="data:image/png;base64,%s"></figure>'
                               % (s, min(s, 256), min(s, 256), b, s, min(s, 256), min(s, 256), a) for s, b, a in cells)
                p.set_content('<!doctype html><body style="margin:16px;font:14px sans-serif;display:flex;flex-wrap:wrap;gap:16px;align-items:end">' + rows
                              + '<figure><figcaption>파비콘 64 · 32 · 16</figcaption><img width="64" height="64" src="data:image/svg+xml;base64,' + svg + '"> '
                              + '<img width="32" height="32" src="data:image/svg+xml;base64,' + svg + '"> <img width="16" height="16" src="data:image/svg+xml;base64,' + svg + '"></figure></body>')
                p.wait_for_timeout(200)
                p.screenshot(path=str(shots / 'icons-before-after.png'))
                check(all(b for _, b, _ in cells), '[shots] install icons before (%s) · after · favicon → shots/icons-before-after.png' % base_ref[:12])
                ctx.close()
            guard('icon shots', icon_shots)

        browser.close()
finally:
    (out / 'brand-words-metrics.json').write_text(json.dumps(metrics, ensure_ascii=False, indent=1))
    (out / 'brand-words-results.json').write_text(json.dumps(checks, ensure_ascii=False, indent=2))
    print('RESULT pass=' + str(sum(x['pass'] for x in checks)) + ' fail=' + str(sum(not x['pass'] for x in checks)), flush=True)
if any(not x['pass'] for x in checks):
    raise SystemExit(1)
