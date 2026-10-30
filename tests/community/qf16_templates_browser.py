#!/usr/bin/env python3
"""사이트 Q:F-16 1차 — 업무 프로젝트 「작업 추가 ▾」 템플릿 고르기 · 화면 수락 (실제 PHP · PostgreSQL + Chromium · 격리 틀 memories_harness.py 만)

  python3 tests/community/memories_harness.py --port 8768 --suite qf16_templates_browser.py --output <결과>/browser

합성 DB · 임시 복사본 · 로컬 php -S 만(운영 · 시드 계정 0). 계정은 fixture 의 claude_ 다섯 · UA tbox-review/<날짜>.
준비는 qf16_templates_http.py 의 prepare() 한 벌. 결과 파일에는 단언 이름 · 참거짓 · 측정값만. 스크린샷은 결과 폴더 shots/.

  [pick]   Q:T-44 머리 · 섹션 끝 꺾쇠 · 열기 전 목록 요청 0 · 열면 둘 · 다시 열어도 +0 · 순서 · 미리보기 · 고르면 그 자리에 만들고 칸이 열림 ·
                  「빈 작업」 = 지금 그 자리 입력 줄 · 꺾쇠 없는 「작업 추가」 그대로 · 빈 상태 · 만들기 실패 문장 = 서버 문장
  [share]  Q:T-45 보기 공유 · 손님 — 꺾쇠 0 · 목록 요청 0 · 칸 더보기 「템플릿으로 저장」 0
  [save]   Q:T-48 작업 칸 더보기 「템플릿으로 저장」 — 범위 = 창구 template_scopes · 저장 뒤 「이 템플릿으로 반복 만들기」 · 꺾쇠 목록 다시 받음 ·
                  팀장 · 전체 화면 작업 · 업무 홈 · 내 작업(항목 0)
  [repeat] Q:T-49 미리보기 바닥 「이 템플릿으로 반복 만들기」 → 기존 반복 창 · 「만들 자리」 기본값(행 = 이 템플릿의 데이터베이스 · 일반 = 이 프로젝트)
  [layout] Q:T-50 360 · 412(터치 · 마우스) · 820 · 960 · 1280 · 1440 × 밝게 · 어둡게 + 고대비 — 시트 · 두 칸 · 넘침 0 · 콘솔 0 · 누름 자리 44 ·
                  보이는 상자 어디를 눌러도 자기 자신 · 인라인 style 0 · 메뉴 안 검정 0
"""
from pathlib import Path
_HTTP = Path(__file__).with_name('qf16_templates_http.py')
exec(compile(_HTTP.read_text().split('\n# ═══ HTTP 단언 ═══')[0], str(_HTTP), 'exec'))
from urllib.parse import urlsplit
from playwright.sync_api import sync_playwright
host = urlsplit(site).hostname
UA = 'tbox-review/' + time.strftime('%Y-%m-%d')
shots = out / 'shots'
shots.mkdir(exist_ok=True)
HEAD = '#project-add-task-pick'
HEAD_SUM = HEAD + ' > summary'

# 메뉴 안 측정 — 보이는 것만 · 시트/두 칸 · 누름 자리(터치) · 자기 자신 누름 · 인라인 style · 검정
MENU_JS = r"""([sel, touch]) => {
  const m = document.querySelector(sel); if (!m) return null;
  const list = m.querySelector('.menu__list'); const vis = el => { const r = el.getBoundingClientRect(); const s = getComputedStyle(el); return r.width > 0 && r.height > 0 && s.display !== 'none' && s.visibility !== 'hidden' && !el.closest('[hidden]'); };
  const box = el => { if (!el || !vis(el)) return null; const r = el.getBoundingClientRect(); return {l: r.left, t: r.top, r: r.right, b: r.bottom, w: r.width, h: r.height}; };
  const col = list.querySelector(':scope > div:not(.menu__sheet-head)'); const pv = list.querySelector('.menu__preview'); const sh = list.querySelector('.menu__sheet-head');
  const ctrls = [...list.querySelectorAll('button')].filter(vis);
  const small = []; const notSelf = [];
  for (const el of ctrls) {
    const r = el.getBoundingClientRect(); let w = r.width, h = r.height;
    const b = getComputedStyle(el, '::before');
    if (b.content && b.content !== 'none' && b.position === 'absolute') { const px = v => { const n = parseFloat(v); return isNaN(n) ? 0 : n; }; w = r.width - px(b.left) - px(b.right); h = r.height - px(b.top) - px(b.bottom); }
    if (touch && (w < 43.5 || h < 43.5)) small.push((el.textContent || el.getAttribute('aria-label') || '').trim().slice(0, 30) + ' ' + Math.round(w) + 'x' + Math.round(h));
    if (r.top < 0 || r.bottom > window.innerHeight || r.left < 0 || r.right > window.innerWidth) continue;
    const pts = [[r.left + r.width / 2, r.top + r.height / 2], [r.left + 2, r.top + r.height / 2], [r.right - 2, r.top + r.height / 2]];
    for (const [x, y] of pts) { const hit = document.elementFromPoint(x, y); const own = hit && hit.closest('button'); if (own !== el) { notSelf.push((el.textContent || el.getAttribute('aria-label') || '').trim().slice(0, 30) + ' ← ' + (hit ? (hit.id || hit.className || hit.tagName).toString().slice(0, 40) : 'null')); break; } }
  }
  const cs = getComputedStyle(list);
  return {position: cs.position, list: box(list), col: box(col), preview: box(pv), sheetHead: box(sh), vw: window.innerWidth, vh: window.innerHeight,
          overflow: document.documentElement.scrollWidth - window.innerWidth, small: small.slice(0, 8), smallCount: small.length, notSelf: notSelf.slice(0, 8),
          inline: m.querySelectorAll('[style]').length, black: [...list.querySelectorAll('.btn')].filter(b => vis(b) && b.matches(':not(.btn--quiet):not(.btn--plain):not(.btn--icon)')).length};
}"""
HEADS_JS = r"""() => { const a = document.querySelector('#project-head .page-head__actions'); if (!a) return null;
  const vis = el => { const r = el.getBoundingClientRect(); return r.width > 0 && r.height > 0 && !el.closest('[hidden]'); };
  const btns = [...a.children].filter(vis);
  return {count: btns.filter(b => b.matches('.btn, details')).length,
          black: btns.filter(b => b.matches('.btn:not(.btn--quiet):not(.btn--plain):not(.btn--icon):not(.btn--danger)') || (b.matches('details') && b.querySelector('summary.btn:not(.btn--quiet):not(.btn--plain):not(.btn--icon)'))).length}; }"""


def new_context(browser, user, width, height=900, scheme='light', touch=False, contrast=False):
    c = browser.new_context(viewport={'width': width, 'height': height}, user_agent=UA, color_scheme=scheme, has_touch=touch,
                            forced_colors='active' if contrast else 'none', ignore_https_errors=True)
    c.add_cookies([{'name': '__Host-tbox_session', 'value': users[user]['session'], 'domain': host, 'path': '/', 'secure': True, 'httpOnly': True}])
    return c


class Watch:
    """화면 하나의 요청 · 콘솔 오류를 센다(URL 경로만 — 본문 0)."""
    def __init__(self, page):
        self.urls = []
        self.posts = []
        self.errors = []
        page.on('request', lambda r: self.urls.append((r.method, urlsplit(r.url).path + ('?' + urlsplit(r.url).query if urlsplit(r.url).query else ''))))
        page.on('pageerror', lambda e: self.errors.append(str(e)[:200]))
        page.on('console', lambda m: self.errors.append(m.text[:200]) if m.type == 'error' else None)

    def tpl(self):
        return [u for (m, u) in self.urls if m == 'GET' and '/api/templates' in u]


def goto_project(page, pid):
    page.goto(site + '/community/p/' + pid, wait_until='networkidle')
    page.locator('#project-head').wait_for(state='visible', timeout=15000)


def open_head(page):
    page.locator(HEAD_SUM).click()
    page.locator(HEAD + '[open] .menu__list').wait_for(state='visible')


def wait_rows(page, sel):
    page.wait_for_function("(s) => { const m = document.querySelector(s); return m && m.querySelectorAll('[data-template-id]').length > 0 || (m && m.querySelector('.menu__hint:not([hidden])')); }", arg=sel, timeout=10000)


def close_menus(page):
    page.keyboard.press('Escape')
    page.wait_for_timeout(100)


def rows_in(pid_db):
    return {x['id']: x for x in rows_of(pid_db)['rows']}


try:
    F = prepare()
    # 브라우저 전용 준비 — 실패 시험용 내 템플릿 T-tmp · P2 의 작업 하나(보기 공유 칸) · P 의 작업 하나(저장 시험)
    src_tmp = call('POST', '/api/pages', {'title': '지울 틀', 'doc': doc_of(para('곧 지움'))}, expected=201)['page']
    F['T_tmp'] = call('POST', '/api/templates', {'page_id': src_tmp['id'], 'name': '지울 틀', 'scope': 'personal'}, expected=201)['template']['id']
    p2_task = call('POST', '/api/pages', {'parent_id': F['P2'], 'title': '개인 작업 하나', 'properties': {'section': '00. 준비'}}, expected=201)['page']['id']
    save_task = call('POST', '/api/pages', {'parent_id': F['P'], 'title': '주간 회의 준비', 'properties': {'section': '00. 준비'},
                                            'doc': doc_of(todo('안건 모으기'), para('회의 전날까지'))}, expected=201)['page']['id']
    call('PATCH', '/api/pages/' + save_task + '/properties', {'status': 'todo'})
    guest_share = raw('PUT', '/api/pages/' + F['P2'] + '/shares/claude_guest01', {'level': 'view'})
    metrics = {'guest_share_status': guest_share.status_code}

    with sync_playwright() as pw:
        browser = pw.chromium.launch()

        # ── [pick] Q:T-44 ──
        ctx = new_context(browser, 'employee', 1440)
        page = ctx.new_page()
        w = Watch(page)
        goto_project(page, F['P'])
        check(page.locator('#project-add-task').is_visible() and page.locator(HEAD_SUM).is_visible(), 'Q:T-44 ① 머리 「작업 추가」 + 바로 오른쪽 꺾쇠 보임')
        check(page.locator(HEAD_SUM).get_attribute('aria-label') == '템플릿 골라 작업 추가', 'Q:T-44 ① 머리 꺾쇠 aria-label')
        order = page.evaluate("() => { const a = document.getElementById('project-add-task'); const b = document.getElementById('project-add-task-pick'); return a && b && a.nextElementSibling === b; }")
        check(order, 'Q:T-44 ① 꺾쇠는 「작업 추가」 바로 뒤')
        hd = page.evaluate(HEADS_JS)
        metrics['head_1440'] = hd
        check(hd and hd['black'] <= 1 and hd['count'] <= 4, 'Q:T-44 ① 머리 검정 ≤ 1 · 머리 단추 ≤ 4 %s' % json.dumps(hd))
        adds = page.locator('#project-list [data-add-section]').count()
        picks = page.locator('#project-list details.menu--picker[data-add-section-pick]').count()
        check(adds > 0 and adds == picks, 'Q:T-44 ① 섹션 끝마다 꺾쇠 하나 (%d/%d)' % (picks, adds))
        # 지금 데이터베이스 화면(database.js createRowTemplatePicker)이 행을 더할 수 있는 사람에게 부르는 ?database= 한 번은 이 기능 전부터 있다 — 그 위에 꺾쇠가 더하는 요청만 센다
        pre = w.tpl()
        check(all('database=' in u for u in pre) and len(pre) <= 1 and not any(u.endswith('/api/templates') for u in pre),
              'Q:T-44 ① 열기 전 꺾쇠가 부른 /api/templates 0 (전부터 있는 DB 화면 요청 %d)' % len(pre))
        open_head(page)
        wait_rows(page, HEAD)
        t1 = w.tpl()[len(pre):]
        check(len(t1) == 2 and any('database=' in u for u in t1) and any(u.endswith('/api/templates') for u in t1), 'Q:T-44 ① 열면 목록 요청 둘(?database= · 일반) %s' % t1)
        items = page.evaluate("(s) => [...document.querySelectorAll(s + ' .menu__list .menu__item')].filter(e => !e.hidden).map(e => [e.getAttribute('data-template-id') || (e.hasAttribute('data-template-empty') ? 'EMPTY' : e.textContent.trim()), (e.querySelector('.menu__scope') || {}).textContent || ''])", HEAD)
        ids = [x[0] for x in items]
        want_order = ['EMPTY', F['T_row'], F['T_me'], F['T_tmp'], F['T_team']]
        check(ids[:5] == want_order or ids[:5] == ['EMPTY', F['T_row'], F['T_tmp'], F['T_me'], F['T_team']], 'Q:T-44 ① 순서 빈 작업 → 이 프로젝트 → 내 템플릿 → 팀 %s' % json.dumps(items[:6], ensure_ascii=False))
        scopes = dict((x[0], x[1]) for x in items)
        check(scopes.get(F['T_row']) == '이 프로젝트' and scopes.get(F['T_me']) == '내 템플릿' and scopes.get(F['T_team'], '').startswith('팀'),
              'Q:T-44 ① 범위 글자 이 프로젝트 · 내 템플릿 · 팀')
        site_ids = [x[0] for x in items if x[1] == '사이트 기본']
        check(F['T_site'] in site_ids and ids.index(site_ids[0]) > ids.index(F['T_team']), 'Q:T-44 ① 사이트 기본(회의록)은 팀 뒤')
        looks = page.locator(HEAD + ' [data-template-look]')
        check(looks.count() == len([i for i in ids if i not in ('EMPTY', '지금 작업을 템플릿으로 저장')]), 'Q:T-44 ① 줄마다 미리보기 단추')
        check(page.locator(HEAD + ' [data-template-look="' + F['T_row'] + '"]').get_attribute('aria-label') == '주간재료비 보고 미리보기', 'Q:T-44 ① 미리보기 단추 aria-label')
        txt = page.locator(HEAD + ' .menu__list').inner_text()
        check('지우기' not in txt and not any(r in txt for r in ('Admin', 'Manager', 'Employee', '관리자', '팀장', '직원')), 'Q:T-44 ① 메뉴에 지우기 · 역할 이름 0')
        close_menus(page)
        open_head(page)
        page.wait_for_timeout(300)
        check(len(w.tpl()) == len(pre) + 2, 'Q:T-44 ① 다시 열어도 목록 요청 +0')

        # ② 미리보기
        page.locator(HEAD + ' [data-template-id="' + F['T_row'] + '"]').focus()
        page.locator(HEAD + ' .menu__preview').wait_for(state='visible')
        page.wait_for_function("(s) => document.querySelector(s + ' .menu__preview [data-template-make]')", arg=HEAD)
        pv = page.locator(HEAD + ' .menu__preview').inner_text()
        gets = [u for u in w.tpl() if u.endswith('/api/templates/' + F['T_row'])]
        check(len(gets) == 1, 'Q:T-44 ② 미리보기 본문 요청 1')
        check('제목: 주간재료비 보고' in pv, 'Q:T-44 ② 「제목: …」')
        check('미리 채움: 섹션 01. 진행 (섹션은 누른 자리 00. 준비)' in pv, 'Q:T-44 ② 「미리 채움: 섹션 01. 진행 (섹션은 누른 자리 00. 준비)」')
        check('상태 · 담당 · 기한은 만든 뒤 작업 상세에서 정해요.' in pv, 'Q:T-44 ② WT-8 안내 한 줄')
        check(page.locator(HEAD + ' .menu__preview table').count() == 1 and page.locator(HEAD + ' .menu__preview .doc-todo').count() == 3,
              'Q:T-44 ② 본문 = 렌더러(표 1 · 할 일 3) %d/%d' % (page.locator(HEAD + ' .menu__preview table').count(), page.locator(HEAD + ' .menu__preview .doc-todo').count()))
        mk = page.locator(HEAD + ' .menu__preview [data-template-make]')
        rp = page.locator(HEAD + ' .menu__preview [data-template-repeat]')
        check(mk.inner_text().strip() == '이 템플릿으로 만들기' and rp.inner_text().strip() == '이 템플릿으로 반복 만들기'
              and 'btn--quiet' in (mk.get_attribute('class') or '') and 'btn--quiet' in (rp.get_attribute('class') or ''), 'Q:T-44 ② 바닥 단추 둘 테두리')
        check(page.locator(HEAD + ' [data-template-look="' + F['T_row'] + '"]').get_attribute('aria-pressed') == 'true', 'Q:T-44 ② 미리보는 줄 aria-pressed')
        page.locator(HEAD + ' [data-template-id="' + F['T_me'] + '"]').focus()
        page.locator(HEAD + ' [data-template-id="' + F['T_row'] + '"]').focus()
        page.wait_for_timeout(300)
        check(len([u for u in w.tpl() if u.endswith('/api/templates/' + F['T_row'])]) == 1, 'Q:T-44 ② 다시 초점 — 본문 요청 +0')
        page.screenshot(path=str(shots / 'pc-1440-preview.png'))

        # ③ 머리 꺾쇠에서 T-row 고르기
        before = set(rows_in(F['Pdb']))
        with page.expect_request(lambda r: r.method == 'POST' and urlsplit(r.url).path.endswith('/api/pages')) as rq:
            page.locator(HEAD + ' [data-template-id="' + F['T_row'] + '"]').click()
        body = rq.value.post_data_json
        check(body == {'parent_id': F['P'], 'template_id': F['T_row'], 'properties': {'section': '00. 준비'}}, 'Q:T-44 ③ 만들기 요청 본문 = 프로젝트 · 템플릿 · 첫 섹션 %s' % json.dumps(body, ensure_ascii=False))
        check(page.locator(HEAD).get_attribute('open') is None, 'Q:T-44 ③ 고르면 메뉴 닫힘')
        page.wait_for_function("() => new URLSearchParams(location.search).get('task')", timeout=10000)
        page.locator('#task-pane').wait_for(state='visible')
        page.wait_for_timeout(500)
        new = [i for i in rows_in(F['Pdb']) if i not in before]
        check(len(new) == 1, 'Q:T-44 ③ 프로젝트 행 +1')
        if new:
            nid = new[0]
            nr = rows_in(F['Pdb'])[nid]
            check(nr['values'].get('section') == '00. 준비' and nr.get('status') == 'todo', 'Q:T-44 ③ 첫 섹션 · 상태 할 일(PATCH 뒤)')
            check(page.evaluate("() => new URLSearchParams(location.search).get('task')") == nid, 'Q:T-44 ③ 오른쪽 칸에 그 작업(?task=)')
            check(page.locator('#project-list [data-section="00. 준비"] .task-row[data-task="' + nid + '"]').count() == 1, 'Q:T-44 ③ 새 줄이 첫 섹션에')
            check(page.locator('#task-pane table').count() == 1 and page.locator('#task-pane .doc-todo').count() == 3, 'Q:T-44 ③ 칸 설명 = 템플릿 본문(표 1 · 할 일 3) %d/%d' % (page.locator('#task-pane table').count(), page.locator('#task-pane .doc-todo').count()))
            check('할 일' in page.locator('#task-pane').inner_text(), 'Q:T-44 ③ 칸 상태 「할 일」')

        # ④ 섹션 「02. 마무리」 끝 꺾쇠에서 회의록
        page.goto(site + '/community/p/' + F['P'], wait_until='networkidle')
        sec = '#project-list details[data-add-section-pick="02. 마무리"]'
        page.locator(sec + ' > summary').click()
        wait_rows(page, sec)
        check(page.locator(sec + ' > summary').get_attribute('aria-label') == '템플릿 골라 02. 마무리에 작업 추가', 'Q:T-44 ④ 섹션 꺾쇠 aria-label')
        page.locator(sec + ' [data-template-look="' + F['T_site'] + '"]').click()
        page.wait_for_function("(s) => document.querySelector(s + ' .menu__preview [data-template-make]')", arg=sec)
        check('미리 채움' not in page.locator(sec + ' .menu__preview').inner_text(), 'Q:T-44 ④ 일반 템플릿 미리보기에 「미리 채움」 0')
        before = set(rows_in(F['Pdb']))
        with page.expect_request(lambda r: r.method == 'POST' and urlsplit(r.url).path.endswith('/api/pages')) as rq:
            page.locator(sec + ' [data-template-id="' + F['T_site'] + '"]').click()
        check((rq.value.post_data_json or {}).get('properties') == {'section': '02. 마무리'}, 'Q:T-44 ④ properties.section = 02. 마무리')
        page.locator('#task-pane').wait_for(state='visible')
        page.wait_for_timeout(500)
        new = [i for i in rows_in(F['Pdb']) if i not in before]
        check(len(new) == 1 and rows_in(F['Pdb'])[new[0]]['values'].get('section') == '02. 마무리', 'Q:T-47 ④ 회의록 → 02. 마무리 섹션 행')
        check('회의 정보' in page.locator('#task-pane').inner_text(), 'Q:T-47 ④ 칸 설명 = 회의록 본문')

        # ⑤ 「빈 작업」 · 꺾쇠 없는 「작업 추가」
        page.goto(site + '/community/p/' + F['P'], wait_until='networkidle')
        w2 = Watch(page)
        open_head(page)
        wait_rows(page, HEAD)
        n_tpl = len(w2.tpl())
        page.locator(HEAD + ' [data-template-empty]').click()
        inp = page.locator('#project-list .task-add__input')
        inp.wait_for(state='visible')
        check(inp.get_attribute('aria-label') == '00. 준비에 작업 추가', 'Q:T-44 ⑤ 머리 「빈 작업」 = 첫 섹션 그 자리 입력 줄')
        inp.fill('빈 작업으로 적음')
        inp.press('Enter')
        page.wait_for_timeout(1200)
        check(page.locator('#project-list .task-add__input').count() == 1, 'Q:T-44 ⑤ Enter 뒤 같은 칸 다시 열림(2.20)')
        check(any(r['title'] == '빈 작업으로 적음' for r in rows_in(F['Pdb']).values()), 'Q:T-44 ⑤ 빈 작업 저장')
        page.keyboard.press('Escape')
        sec2 = '#project-list details[data-add-section-pick="01. 진행"]'
        page.locator(sec2 + ' > summary').click()
        page.locator(sec2 + ' [data-template-empty]').click()
        inp = page.locator('#project-list .task-add__input')
        inp.wait_for(state='visible')
        check(inp.get_attribute('aria-label') == '01. 진행에 작업 추가', 'Q:T-44 ⑤ 섹션 끝 「빈 작업」 = 그 섹션 입력 줄')
        page.keyboard.press('Escape')
        page.wait_for_timeout(200)
        page.locator('#project-add-task').click()
        inp = page.locator('#project-list .task-add__input')
        inp.wait_for(state='visible')
        check(inp.get_attribute('aria-label') == '00. 준비에 작업 추가', 'Q:T-44 ⑤ 꺾쇠 없는 머리 「작업 추가」 = 지금과 같음')
        page.keyboard.press('Escape')
        page.wait_for_timeout(200)
        page.locator('#project-list [data-add-section="02. 마무리"]').click()
        check(page.locator('#project-list .task-add__input').get_attribute('aria-label') == '02. 마무리에 작업 추가', 'Q:T-44 ⑤ 꺾쇠 없는 섹션 「작업 추가」 = 지금과 같음')
        page.keyboard.press('Escape')
        check(len(w2.tpl()) == n_tpl, 'Q:T-44 ⑤ 빈 작업 · 작업 추가 — 템플릿 요청 +0')

        # ⑦ 만들기 실패 — 목록을 그린 뒤 T-tmp 를 지움 → 줄 누름 → 서버 문장 그대로
        page.goto(site + '/community/p/' + F['P'], wait_until='networkidle')
        open_head(page)
        wait_rows(page, HEAD)
        call('DELETE', '/api/templates/' + F['T_tmp'])
        srv = raw('POST', '/api/pages', {'parent_id': F['P'], 'template_id': F['T_tmp']})
        srv_msg = (srv.json() or {}).get('error', '')
        before = set(rows_in(F['Pdb']))
        page.locator(HEAD + ' [data-template-id="' + F['T_tmp'] + '"]').click()
        page.wait_for_function("(t) => (document.getElementById('db-msg') || {}).textContent === t", arg=srv_msg, timeout=8000)
        check(srv.status_code == 404 and page.locator('#db-msg').inner_text() == srv_msg, 'Q:T-44 ⑦ 지운 템플릿 → 404 서버 문장 그대로 #db-msg')
        check(set(rows_in(F['Pdb'])) == before, 'Q:T-44 ⑦ 행 +0')
        ctx.close()

        # ⑥ 빈 상태 — 템플릿이 하나도 없는 문맥(other 의 새 개인 프로젝트 · 사이트 기본은 합성 DB 에서만 잠시 감춤)
        call('DELETE', '/api/templates/' + F['T_other'], user='other')
        if F['T_other_team']:
            call('DELETE', '/api/templates/' + F['T_other_team'], user='other')
        sql("UPDATE community.templates SET deleted_at = now(), deleted_by = " + str(users['admin']['id']) + " WHERE scope = 'site'")
        po = call('POST', '/api/pages', {'database': True, 'preset': 'project', 'title': '빈 상태 프로젝트'}, user='other', expected=201)['page']
        po_task = call('POST', '/api/pages', {'parent_id': po['id'], 'title': '혼자 할 일'}, user='other', expected=201)['page']['id']
        ctx = new_context(browser, 'other', 1440)
        page = ctx.new_page()
        goto_project(page, po['id'])
        open_head(page)
        wait_rows(page, HEAD)
        hint = page.locator(HEAD + ' .menu__hint')
        check(hint.is_visible() and hint.inner_text() == '자주 쓰는 작업을 템플릿으로 저장하면 여기서 골라 쓸 수 있어요.', 'Q:T-44 ⑥ 빈 상태 안내 한 문장')
        check(page.locator(HEAD + ' [data-template-empty]').is_visible() and page.locator(HEAD + ' [data-template-id]').count() == 0, 'Q:T-44 ⑥ 「빈 작업」만')
        check(not page.locator(HEAD + ' [data-template-save-current]').is_visible(), 'Q:T-44 ⑥ 칸이 닫혀 있으면 「지금 작업을 템플릿으로 저장」 0')
        close_menus(page)
        page.goto(site + '/community/p/' + po['id'] + '?task=' + po_task, wait_until='networkidle')
        page.locator('#task-pane').wait_for(state='visible')
        page.wait_for_timeout(500)
        open_head(page)
        cur = page.locator(HEAD + ' [data-template-save-current]')
        check(cur.is_visible(), 'Q:T-44 ⑥ 칸에 작업을 열면 「지금 작업을 템플릿으로 저장」 보임')
        cur.click()
        page.locator('#template-save-dialog[open]').wait_for(state='visible')
        check(page.locator('#template-save-name').input_value() == '혼자 할 일', 'Q:T-44 ⑥ 저장 창 = 그 작업(이름 기본값)')
        page.locator('#template-save-dialog [data-template-save-close]').first.click()
        ctx.close()
        sql("UPDATE community.templates SET deleted_at = NULL, deleted_by = NULL WHERE scope = 'site'")

        # ── [share] Q:T-45 ──
        for who in ('other', 'guest'):
            if who == 'guest' and guest_share.status_code != 200:
                check(True, 'Q:T-45 ⑧ 손님 — 개인 프로젝트를 손님에게 공유할 수 없음(%d) · 화면 시험 생략' % guest_share.status_code)
                continue
            ctx = new_context(browser, who, 1440)
            page = ctx.new_page()
            w3 = Watch(page)
            page.goto(site + '/community/p/' + F['P2'] + '?task=' + p2_task, wait_until='networkidle')
            page.locator('#project-head').wait_for(state='visible', timeout=15000)
            page.locator('#task-pane').wait_for(state='visible')
            page.wait_for_timeout(500)
            check(not page.locator('#project-add-task').is_visible() and page.locator(HEAD).get_attribute('hidden') is not None, 'Q:T-45 ⑧ %s 보기 공유: 「작업 추가」 · 꺾쇠 0' % who)
            check(page.locator('#project-list details.menu--picker').count() == 0, 'Q:T-45 ⑧ %s 섹션 끝 꺾쇠 0' % who)
            # 보이는지는 창구 값뿐(DESIGN 2.38) — 보기 공유라도 서버가 개인 템플릿 저장을 허락하면(template_scopes) 페이지 더보기와 같이 보인다
            ts = call('GET', '/api/pages/' + p2_task, user=who).get('template_scopes') or []
            itm = page.locator('#task-pane [data-task-save-template]')
            check(itm.count() == 1 and (itm.get_attribute('hidden') is None) == (len(ts) > 0),
                  'Q:T-45 ⑧ %s 칸 더보기 「템플릿으로 저장」 = 창구 template_scopes %s' % (who, ts))
            check(len(w3.tpl()) == 0, 'Q:T-45 ⑧ %s 이 화면 동안 /api/templates 요청 0' % who)
            ctx.close()

        # ── [save] Q:T-48 ──
        ctx = new_context(browser, 'employee', 1440)
        page = ctx.new_page()
        w4 = Watch(page)
        page.goto(site + '/community/p/' + F['P'] + '?task=' + save_task, wait_until='networkidle')
        page.locator('#task-pane').wait_for(state='visible')
        page.wait_for_timeout(500)
        open_head(page)
        wait_rows(page, HEAD)
        close_menus(page)
        n_before = len(w4.tpl())
        more = page.locator('#task-pane .task-pane__tools details.menu').first
        more.locator('summary').click()
        order = page.evaluate("() => [...document.querySelectorAll('#task-pane .task-pane__tools details.menu .menu__list > *')].map(e => e.getAttribute('data-task-save-template') !== null ? 'TPL' : (e.tagName === 'HR' ? 'SEP' : e.textContent.trim()))")
        check('TPL' in order and order.index('TPL') == order.index('하위 작업 더하기') + 1 and order.index('TPL') < order.index('SEP'), 'Q:T-48 ⑨ 「템플릿으로 저장」 = 하위 작업 더하기 아래 · 구분선 위 %s' % json.dumps(order, ensure_ascii=False))
        page.screenshot(path=str(shots / 'pc-1440-task-more.png'))
        page.locator('#task-pane [data-task-save-template]').click()
        page.locator('#template-save-dialog[open]').wait_for(state='visible')
        want_scopes = call('GET', '/api/pages/' + save_task)['template_scopes']
        radios = page.evaluate("() => [...document.querySelectorAll('#template-save-scopes input[type=radio]')].map(r => r.value)")
        check(radios == [s for s in want_scopes if s in ('site', 'team', 'personal', 'database')] and 'site' not in radios, 'Q:T-48 ⑨ 범위 = 창구 template_scopes 같은 순서 %s' % radios)
        check(page.locator('#template-save-name').input_value() == '주간 회의 준비', 'Q:T-48 ⑨ 이름 기본값 = 작업 제목')
        page.locator('#template-save-confirm').click()
        rep_btn = page.locator('#template-save-dialog [data-template-repeat]')
        rep_btn.wait_for(state='visible')
        check('템플릿으로 저장했습니다' in page.locator('#template-save-msg').inner_text() and rep_btn.inner_text().strip() == '이 템플릿으로 반복 만들기',
              'Q:T-48 ⑨ 저장 201 · 알림 · 옆에 「이 템플릿으로 반복 만들기」')
        page.screenshot(path=str(shots / 'pc-1440-saved-repeat.png'))
        rep_btn.click()
        dlg = page.locator('dialog.modal[open]').last
        dlg.wait_for(state='visible')
        check(dlg.get_by_label('만들 자리', exact=True).evaluate("s => s.options[s.selectedIndex].text") == '이 프로젝트 · 주간 업무', 'Q:T-49 ⑩ 저장 직후 반복 창 「만들 자리」 기본 = 이 프로젝트 · 주간 업무')
        page.screenshot(path=str(shots / 'pc-1440-repeat-parent.png'))
        page.keyboard.press('Escape')
        page.wait_for_timeout(300)
        open_head(page)
        wait_rows(page, HEAD)
        names = page.evaluate("(s) => [...document.querySelectorAll(s + ' [data-template-id]')].map(e => e.textContent)", HEAD)
        check(any('주간 회의 준비' in x for x in names) and len(w4.tpl()) == n_before + 2, 'Q:T-48 ⑨ 저장 뒤 꺾쇠 목록에 새 템플릿(요청 다시 둘 — %d)' % (len(w4.tpl()) - n_before))
        close_menus(page)
        ctx.close()
        # 팀장(같은 팀 공간 — 고칠 수 있음)
        ctx = new_context(browser, 'manager', 1440)
        page = ctx.new_page()
        page.goto(site + '/community/p/' + F['P'] + '?task=' + save_task, wait_until='networkidle')
        page.locator('#task-pane').wait_for(state='visible')
        page.wait_for_timeout(500)
        page.locator('#task-pane .task-pane__tools details.menu summary').first.click()
        page.locator('#task-pane [data-task-save-template]').click()
        page.locator('#template-save-dialog[open]').wait_for(state='visible')
        want_m = call('GET', '/api/pages/' + save_task, user='manager')['template_scopes']
        radios = page.evaluate("() => [...document.querySelectorAll('#template-save-scopes input[type=radio]')].map(r => r.value)")
        check(radios == [s for s in want_m if s in ('site', 'team', 'personal', 'database')], 'Q:T-48 ⑨ 팀장 — 범위 = 그 계정의 template_scopes %s' % radios)
        page.locator('#template-save-dialog [data-template-save-close]').first.click()
        ctx.close()
        # 전체 화면 작업 · 업무 홈 · 내 작업
        ctx = new_context(browser, 'employee', 1440)
        page = ctx.new_page()
        page.goto(site + '/community/p/' + save_task, wait_until='networkidle')
        page.locator('#task-full').wait_for(state='visible', timeout=15000)
        page.locator('#task-full .task-pane__tools details.menu summary').first.click()
        item = page.locator('#task-full [data-task-save-template]')
        check(item.is_visible(), 'Q:T-48 ⑨ 전체 화면 작업 더보기에 「템플릿으로 저장」')
        item.click()
        page.locator('#template-save-dialog[open]').wait_for(state='visible')
        check(page.locator('#template-save-name').input_value() == '주간 회의 준비', 'Q:T-48 ⑨ 전체 화면 — 같은 창')
        page.locator('#template-save-dialog [data-template-save-close]').first.click()
        page.goto(site + '/community/my-tasks?task=' + save_task, wait_until='networkidle')
        page.wait_for_timeout(800)
        pane_item = page.locator('#task-pane [data-task-save-template]')
        check(pane_item.count() == 0 or pane_item.get_attribute('hidden') is not None, 'Q:T-48 ⑨ 내 작업 칸 더보기에 항목 0(창 없음)')
        page.goto(site + '/community/?task=' + save_task, wait_until='networkidle')
        page.wait_for_timeout(800)
        pane_item = page.locator('#task-pane [data-task-save-template]')
        check(pane_item.count() == 0 or pane_item.get_attribute('hidden') is not None, 'Q:T-48 ⑨ 업무 홈 칸 더보기에 항목 0(창 없음)')

        # ── [repeat] Q:T-49 ⑩ — 미리보기 바닥에서 반복 창
        goto_project(page, F['P'])
        open_head(page)
        wait_rows(page, HEAD)
        page.locator(HEAD + ' [data-template-look="' + F['T_row'] + '"]').click()
        page.locator(HEAD + ' .menu__preview [data-template-repeat]').wait_for(state='visible')
        page.locator(HEAD + ' .menu__preview [data-template-repeat]').click()
        dlg = page.locator('dialog.modal[open]').last
        dlg.wait_for(state='visible')
        check(page.locator(HEAD).get_attribute('open') is None, 'Q:T-49 ⑩ 메뉴 닫힘')
        check('반복·버튼 작업 만들기' in dlg.locator('.modal__title').inner_text(), 'Q:T-49 ⑩ 기존 반복 창')
        kinds = dlg.get_by_label('작업', exact=True).evaluate("s => [...s.options].map(o => o.text)")
        check(kinds == ['정해진 시각 반복'], 'Q:T-49 ⑩ 「작업」 = 정해진 시각 반복 하나')
        check(dlg.get_by_label('만들 자리', exact=True).evaluate("s => s.options[s.selectedIndex].text") == '이 템플릿의 데이터베이스', 'Q:T-49 ⑩ 행 템플릿 「만들 자리」 = 이 템플릿의 데이터베이스')
        dlg.get_by_role('button', name='시험 실행', exact=True).click()
        dlg.get_by_text('만들 문서:', exact=False).first.wait_for(state='visible', timeout=10000)
        check(dlg.get_by_role('button', name='켜기', exact=True).is_enabled(), 'Q:T-49 ⑩ 시험 실행 → 「만들 문서」 · 켜기 활성')
        page.keyboard.press('Escape')
        page.wait_for_timeout(300)
        open_head(page)
        page.locator(HEAD + ' [data-template-look="' + F['T_me'] + '"]').click()
        page.locator(HEAD + ' .menu__preview [data-template-repeat]').wait_for(state='visible')
        page.locator(HEAD + ' .menu__preview [data-template-repeat]').click()
        dlg = page.locator('dialog.modal[open]').last
        dlg.wait_for(state='visible')
        check(dlg.get_by_label('만들 자리', exact=True).evaluate("s => [s.options[s.selectedIndex].text, s.selectedIndex]") == ['이 프로젝트 · 주간 업무', 0], 'Q:T-49 ⑩ 일반 템플릿 「만들 자리」 기본 = 이 프로젝트 · 주간 업무(첫 항목)')
        page.keyboard.press('Escape')
        ctx.close()

        # ── [layout] Q:T-50 ──
        layouts = [(360, False, 'light'), (360, False, 'dark'), (412, True, 'light'), (412, True, 'dark'), (412, False, 'light'), (820, True, 'light'),
                   (820, False, 'dark'), (960, False, 'light'), (1280, False, 'dark'), (1440, False, 'light'), (1440, False, 'dark')]
        for width, touch, scheme in layouts + [(1440, False, 'contrast')]:
            tag = '%d-%s%s' % (width, scheme, '-touch' if touch else '')
            ctx = new_context(browser, 'employee', width, 820, 'dark' if scheme == 'dark' else 'light', touch, scheme == 'contrast')
            page = ctx.new_page()
            w5 = Watch(page)
            goto_project(page, F['P'])
            if touch:
                page.locator(HEAD_SUM).tap()
            else:
                page.locator(HEAD_SUM).click()
            page.locator(HEAD + '[open] .menu__list').wait_for(state='visible')
            wait_rows(page, HEAD)
            m = page.evaluate(MENU_JS, [HEAD, touch])
            if width < 960:
                ok_sheet = (m['position'] == 'fixed' and abs(m['list']['b'] - m['vh']) <= 1.5 and abs(m['list']['w'] - m['vw']) <= 1.5
                            and m['list']['h'] <= 0.8 * m['vh'] + 1 and m['sheetHead'] is not None and m['preview'] is None)
                check(ok_sheet, 'Q:T-50 %s 시트(fixed · 바닥 · 화면 폭 · ≤80dvh · 머리 보임 · 미리보기 숨김) %s' % (tag, json.dumps({k: m[k] for k in ('position', 'list', 'vw', 'vh')})))
                if width == 360 and scheme == 'light':
                    page.screenshot(path=str(shots / 'phone-360-sheet.png'))
                look = page.locator(HEAD + ' [data-template-look="' + F['T_row'] + '"]')
                if touch:
                    look.tap()
                else:
                    look.click()
                page.locator(HEAD + ' .menu__preview [data-template-make]').wait_for(state='visible')
                m2 = page.evaluate(MENU_JS, [HEAD, touch])
                check(m2['preview'] is not None and m2['col'] is not None and m2['preview']['t'] >= m2['col']['b'] - 1, 'Q:T-50 %s 미리보기 단추 → 목록 아래' % tag)
                if width == 360 and scheme == 'light':
                    page.locator(HEAD + ' .menu__preview').scroll_into_view_if_needed()
                    page.screenshot(path=str(shots / 'phone-360-sheet-preview.png'))
            else:
                check(m['position'] in ('absolute', 'fixed') and m['sheetHead'] is None, 'Q:T-50 %s 앵커 목록 · 시트 머리 숨김' % tag)
                page.locator(HEAD + ' [data-template-id="' + F['T_row'] + '"]').focus()
                page.locator(HEAD + ' .menu__preview [data-template-make]').wait_for(state='visible')
                m2 = page.evaluate(MENU_JS, [HEAD, touch])
                lst = m2['list']
                inside = lst['l'] >= -0.5 and lst['t'] >= -0.5 and lst['r'] <= m2['vw'] + 0.5 and lst['b'] <= m2['vh'] + 0.5
                check(m2['preview'] is not None and m2['preview']['l'] >= m2['col']['r'] - 1 and inside, 'Q:T-50 %s 두 칸(미리보기 오른쪽) · 목록 전체가 화면 안 %s' % (tag, json.dumps(lst)))
                m = m2
            check(m['overflow'] <= 1, 'Q:T-50 %s 가로 넘침 0 (%s)' % (tag, m['overflow']))
            check(m['inline'] == 0 and m['black'] == 0, 'Q:T-50 %s 메뉴 인라인 style 0 · 검정 0' % tag)
            check(not m['notSelf'], 'Q:T-50 %s 보이는 상자 어디를 눌러도 자기 자신 %s' % (tag, json.dumps(m['notSelf'], ensure_ascii=False)))
            if touch:
                check(m['smallCount'] == 0, 'Q:T-50 %s 누름 자리 44 %s' % (tag, json.dumps(m['small'], ensure_ascii=False)))
            check(not w5.errors, 'Q:T-50 %s 콘솔 오류 0 %s' % (tag, json.dumps(w5.errors[:2], ensure_ascii=False)))
            metrics[tag] = {k: m[k] for k in ('position', 'list', 'col', 'preview', 'overflow', 'smallCount')}
            if width == 1440 and scheme == 'dark':
                page.screenshot(path=str(shots / 'pc-1440-dark-preview.png'))
            ctx.close()
        browser.close()
except Exception as error:   # noqa: BLE001
    check(False, 'suite interrupted: ' + type(error).__name__ + ': ' + (str(error).splitlines() or [''])[0][:200])
finally:
    (out / 'qf16-templates-browser-results.json').write_text(json.dumps({'checks': checks, 'metrics': metrics if 'metrics' in dir() else {}}, ensure_ascii=False, indent=2))
    fails = sum(not x['pass'] for x in checks)
    print('QF16_TEMPLATES_BROWSER pass=%d fail=%d' % (sum(x['pass'] for x in checks), fails), flush=True)
raise SystemExit(1 if fails else 0)
