#!/usr/bin/env python3
"""사이트 Q:F-15 S1 — 낱말 · 브랜드 맞추기 수락 시험 (실제 PHP · PostgreSQL + Chromium · 격리 틀 memories_harness.py 만)

  python3 tests/community/memories_harness.py --port 8768 --suite brand_words_browser.py --output <결과 폴더>/accept

지시서 docs/qf15-s1-words-directive.md 「수락 시험」 · DESIGN 5장 낱말 표(Q:F-15) · 「브랜드 이름 3층」 · 2.33 D.
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
  [pages]  1280 · 412 × 밝게 · 어둡게 × 직원 · 관리자 · 손님 · 로그인 전 — 화면마다 document.title · body innerText · [aria-label] ·
           [placeholder] · [data-tip] 에 금지 낱말 0 · 머리글 자리 영어 대문자 6자 이상 0 · link[rel=icon] = 모아 파비콘 · 넘침 0 · 콘솔 0
           (런처는 <details> 를 모두 펼친 뒤 한 번 더 · 문서는 편집 모드 「파일」 블록 창도)
  [must]   있어야 할 것 — 업무 사이드바 「업무 홈」 · 카페 사이드바 「우리 공간」 · 앱 판 「모아 홈 — 검색과 바로가기」 · 자료실 사이드바 「PC 비서 받기」 ·
           메신저 「모아 AI」 부제 · 로그인 title · 로그인 전 h1 · 파비콘 200 image/svg+xml · /tbox/app.webmanifest description · 경보 매니페스트 이름
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
    # 수정 파일 목록 밖 — 모아 홈 대시보드(펼침)의 시작 안내 머리 「내 홈 시작 안내」는 public/assets/home-dashboard.js:51 이 그린다
    'home': [('.dashboard-guide > summary', 'public/assets/home-dashboard.js:51 「내 홈 시작 안내」 — 표에 없는 파일(수정 파일 밖) · 보고서 「못 한 것」')],
    # 법적 문서 본문(privacy-*.html · terms-*.html)은 손대지 않는다 — 머리 · 제목 · 바닥만 본다
    'privacy': [('article.wiki-article > :not(header.wiki-head):not(p.wiki-meta)', '법적 문서 본문 — 지시서 금지 패턴 「법적 문서 본문 손대기」')],
    'terms': [('article.wiki-article > :not(header.wiki-head):not(p.wiki-meta)', '법적 문서 본문 — 지시서 금지 패턴 「법적 문서 본문 손대기」')],
    # 수정 파일 목록 밖(지시서 표 D 는 떠 있는 창 messenger_window.php:39 만) — 전체 화면 /tbox/messages 의 같은 줄은 member_messages_page.php:25
    'messages': [('#message-ai > small', 'src/handlers/member_messages_page.php:25 「공용 Gemini · 나만의 대화 기록」 — 표 D 에 없는 파일(수정 파일 밖) · 보고서 「못 한 것」')],
}
# 파비콘 <link> 를 넣는 파일 목록(표 H) 밖 — 법적 문서 화면은 src/handlers/legal_page.php(수정 파일 밖)
LEGAL_NO_ICON = {'privacy', 'terms'}


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
           '#moa-hero-title', '.moa-home-extra > summary', '.moa-community-feed .moa-section-head a', '.q-start-kind', '.moa-purpose-step']

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
                   ('not-found-out', None, '/tbox/qf15-no-such-screen'),
                   ('work-home', 'employee', '/community/'), ('work-home-guest', 'guest', '/community/'), ('my-tasks', 'employee', '/community/my-tasks'),
                   ('workspace', 'employee', '/community/workspace'), ('cafe', 'employee', '/community/cafe'), ('cafe-guest', 'guest', '/community/cafe'),
                   ('board', 'employee', '/community/b/' + str(QA)), ('cafe-board', 'employee', '/community/b/' + str(FREE)),
                   ('post', 'employee', '/community/p/' + POST), ('doc', 'employee', '/community/p/' + DOC), ('memories', 'employee', '/community/memories')]

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
                            ctx = context(who, width, theme, reauth=(name == 'alerts-app'))
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
                            if name in ('landing', 'home', 'consent-launcher'):
                                p.evaluate("() => document.querySelectorAll('details').forEach(d => { d.open = true; })")
                                p.wait_for_timeout(600)
                                # 게시판 새 글(moa.js)이 다 불러올 때까지 — 「…불러오고 있어요」가 남아 있으면 기다린다(최대 8초 · CSP 라 evaluate 되풀이)
                                for _ in range(80):
                                    if not p.evaluate("(() => { const s = document.getElementById('moa-feed-status'); return !!s && /불러오고/.test(s.textContent); })()"):
                                        break
                                    p.wait_for_timeout(100)
                                metrics.setdefault('feed_status', {})[tag] = p.evaluate("(() => { const s = document.getElementById('moa-feed-status'); return s ? s.textContent : null; })()")
                                look(p, name, tag, ' (details open)')
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
