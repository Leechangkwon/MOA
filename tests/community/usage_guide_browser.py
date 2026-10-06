#!/usr/bin/env python3
"""Chromium + shipped tour assets/work home markup, synthetic HTTP only (not PHP/DB coverage).

Q:F-12 D — 옛 둘러보기(overview_page.php)를 없애고 `/community/` 가 업무 홈(work_home_page.php)이 되어 그 뼈대로 안내를 시험한다(기대값 바꿈)."""
import copy
import json
import os
from pathlib import Path
import re
from urllib.parse import urlparse
from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[2]
ORIGIN = 'http://127.0.0.1:8769'


def fixture(mode='overview', theme='light'):
    if mode == 'overview':
        source = (ROOT / 'src/community/handlers/work_home_page.php').read_text()
        source = source[source.index('<div id="work-home"'):source.index("<?php\n$pageMsgTop")]
        source = re.sub(r"<\?= Http::communityUrl\('([^']*)'\) \?>", lambda m: '/community' + m[1], source)
        source = re.sub(r"<\?= Http::url\('([^']*)'\) \?>", lambda m: '/tbox' + m[1], source)
        source = re.sub(r'<\?php.*?\?>', '', source, flags=re.S)
        source = re.sub(r'<\?=.*?\?>', '', source, flags=re.S)
    else:
        markup = (ROOT / 'src/community/handlers/memories_page.php').read_text()
        composer = markup[markup.index('<dialog id="compose-dialog"'):markup.index('<dialog id="studio-dialog"')]
        composer = re.sub(r'<\?php.*?\?>', '', composer, flags=re.S)
        travel = (ROOT / 'src/community/handlers/travel_plans_panel.php').read_text()
        travel = re.sub(r'<\?php.*?\?>', '', travel, flags=re.S)
        source = '<section id="community-threads" class="community-threads"><div class="threads-tabs">스레드</div>' + composer + travel + '</section>'
    return f'''<!doctype html><html lang="ko" data-theme="{theme}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width">
    <link rel="stylesheet" href="/tbox/assets/app.css"><link rel="stylesheet" href="/tbox/assets/community/memories.css"><link rel="stylesheet" href="/tbox/assets/community/travel-plans.css"><link rel="stylesheet" href="/tbox/assets/usage-guide.css">
    </head><body>{source}<div id="usage-guide-root" data-base="/community"><button class="usage-guide-launch" type="button" data-usage-guide-open>사용 안내</button><p id="usage-guide-notice" role="status" hidden></p></div>
    <script defer src="/tbox/assets/usage-guide.js"></script></body></html>'''


def main():
    checks = 0
    def check(condition, message):
        nonlocal checks
        assert condition, message
        checks += 1

    with sync_playwright() as p:
        browser = p.chromium.launch(executable_path=os.environ.get('CHROMIUM_EXECUTABLE', '/usr/bin/chromium'), args=['--no-sandbox'])
        for viewport, theme in [({'width':390,'height':844}, 'dark'), ({'width':1280,'height':900}, 'light')]:
            context = browser.new_context(viewport=viewport, reduced_motion='reduce')
            state = {'version':1,'status':'new','context':'overview','step':0,'offer':True}
            writes, unexpected, errors = [], [], []
            mode = ['overview']
            fail_writes = [False]

            def route_handler(route):
                req, path = route.request, urlparse(route.request.url).path
                if path.startswith('/tbox/assets/'):
                    file = ROOT / 'public/assets' / path.removeprefix('/tbox/assets/')
                    if file.exists():
                        return route.fulfill(body=file.read_bytes(), content_type='application/javascript' if file.suffix == '.js' else 'text/css')
                if path == '/community/api/me/guide':
                    if req.method == 'PATCH':
                        if fail_writes[0]: return route.fulfill(status=503, json={'error':'synthetic outage'})
                        check(req.headers.get('x-csrf-token') == 'synthetic-csrf', 'preference mutation needs session CSRF')
                        payload = req.post_data_json
                        check(set(payload) == {'status','context','step'}, 'no account ID or application data in guide write')
                        writes.append(copy.deepcopy(payload)); state.update(payload); state['offer'] = False
                    return route.fulfill(json={'guide':state,'csrf_token':'synthetic-csrf'})
                if req.method != 'GET': unexpected.append(path)
                return route.fulfill(body=fixture(mode[0], theme), content_type='text/html', headers={'Content-Security-Policy':"default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; object-src 'none'"})

            context.route('**/*', route_handler)
            page = context.new_page()
            page.on('pageerror', lambda err: errors.append(str(err)))
            page.goto(ORIGIN + '/community/')
            page.get_by_role('heading', name='모아 사용 방법을 안내해 드릴까요?').wait_for()
            check(not writes, 'first prompt does not record consent or modify content')
            page.get_by_role('button', name='나중에 볼게요').click()
            page.wait_for_function("!document.querySelector('.usage-guide-dialog')")
            page.wait_for_timeout(100)
            check(state['status'] == 'dismissed', 'skip saved for account')
            page.reload(); page.wait_for_timeout(900)
            check(page.locator('.usage-guide-dialog').count() == 0, 'skipped guide does not reappear on reload')
            page.locator('.usage-guide-launch').click()
            page.get_by_role('heading', name='업무 홈', exact=True).wait_for()
            check(page.locator('#usage-guide-outline').get_attribute('width') != '0', 'target has highlight rectangle under strict CSP')
            page.get_by_role('button', name='다음', exact=True).click()
            page.get_by_role('heading', name='내 작업 요약').wait_for()
            page.wait_for_timeout(100)
            page.reload()
            page.get_by_role('heading', name='내 작업 요약').wait_for()
            check(state['step'] == 1, 'consented guide resumes saved step')
            page.keyboard.press('ArrowLeft')
            page.get_by_role('heading', name='업무 홈', exact=True).wait_for()
            page.keyboard.press('Tab')
            check(page.evaluate("document.activeElement.closest('.usage-guide-dialog') !== null"), 'focus remains within native modal')
            # 사이드바 단계(새로 만들기 · 내 작업 · 받은 알림)와 숨은 공지 한 줄은 이 합성 뼈대에 없어 건너뛴다 — 보이는 단계만 센다
            for _ in range(10):
                if page.get_by_role('button', name='안내 마치기').count():
                    break
                page.get_by_role('button', name='다음', exact=True).click()
                card = page.locator('.usage-guide-card').bounding_box()
                check(card['x'] >= 0 and card['x'] + card['width'] <= viewport['width'] + 1, 'tour card fits viewport')
            check(page.locator('.usage-guide-progress').inner_text().startswith('4 / 4'), 'work home tour: visible steps only (4 in this skeleton)')
            page.get_by_role('button', name='안내 마치기').click()
            page.wait_for_timeout(120)
            check(state['status'] == 'completed', 'completion persists')
            check(page.locator('#work-home-docs').get_attribute('href') == '/community/workspace', 'documents stay a first-class destination (내 문서)')
            check(page.locator('#work-home-tasks a.btn').get_attribute('href') == '/community/my-tasks', 'home links the full 내 작업 screen')
            check(page.locator('#overview-memories, #overview-files, #community-overview').count() == 0, 'old overview cards are gone (Q:F-12 D)')
            check(page.evaluate("document.documentElement.scrollWidth <= innerWidth"), 'no horizontal overflow')

            # Opening a guide over an existing composer must never submit, modify,
            # dismiss or replace that application's modal and its unsaved draft.
            mode[0] = 'threads'; state.update(status='completed', offer=False)
            page.goto(ORIGIN + '/community/memories')
            page.evaluate("document.querySelector('#caption').value='작성 중인 소중한 원문'; document.querySelector('#compose-dialog').showModal(); document.querySelector('#compose-form').addEventListener('submit', e => { e.preventDefault(); window.submitted = true; });")
            page.locator('#compose-dialog [data-usage-guide-open]').click()
            page.get_by_role('heading', name='사진과 짧은 메모부터').wait_for()
            page.get_by_role('button', name='다음', exact=True).click()
            page.get_by_role('heading', name='사진·영상 첨부').wait_for()
            check(page.locator('#usage-guide-outline').get_attribute('width') != '0', 'actual camera/gallery button is highlighted')
            page.get_by_role('button', name='다음', exact=True).click()
            page.get_by_role('heading', name='오늘의 한 줄').wait_for()
            check(page.locator('#caption').input_value() == '작성 중인 소중한 원문', 'guide retains draft text')
            page.keyboard.press('Escape')
            check(page.locator('#compose-dialog').evaluate('(d) => d.open'), 'closing guide retains application dialog')
            check(page.locator('#compose-dialog').evaluate('(d) => d.scrollWidth <= d.clientWidth + 1'), 'guide opener does not make actual composer overflow')
            check(not page.evaluate('Boolean(window.submitted)'), 'tour never submits underlying form')
            check(page.evaluate("document.activeElement.matches('#compose-dialog [data-usage-guide-open]')"), 'focus returns to originating modal button')

            page.evaluate("document.querySelector('#compose-dialog').close(); document.querySelector('#travel-dialog').showModal(); document.querySelector('#travel-form').hidden=false;")
            page.locator('#travel-dialog [data-usage-guide-open]').click()
            page.get_by_role('heading', name='계획을 기록으로 이어가기').wait_for()
            page.get_by_role('button', name='다음', exact=True).click()
            page.get_by_role('heading', name='새 여행 계획', exact=True).wait_for()
            page.get_by_role('button', name='다음', exact=True).click()
            page.get_by_role('button', name='다음', exact=True).click()
            page.get_by_role('heading', name='일정·장소 추가').wait_for()
            check(page.locator('#usage-guide-outline').get_attribute('width') != '0', 'actual travel itinerary action is highlighted')
            page.keyboard.press('Escape')
            page.evaluate("document.querySelector('#travel-dialog').close(); document.querySelector('#compose-dialog').showModal();")

            fail_writes[0] = True
            page.locator('#compose-dialog [data-usage-guide-open]').click()
            page.locator('#usage-guide-notice').wait_for(state='visible')
            check('저장하지 못했어요' in page.locator('#usage-guide-notice').inner_text(), 'save failure is visible rather than falsely persisted')
            check(page.locator('.usage-guide-dialog .usage-guide-error').is_visible(), 'write failure is in the top-layer guide rather than hidden behind it')
            page.keyboard.press('Escape')
            check(not unexpected, 'only tour preference endpoints receive writes')
            check(not errors, f'no JavaScript errors: {errors}')
            context.close()
        browser.close()
    print(json.dumps({'suite':'usage_guide_browser','passed':checks,'http':'synthetic','php_database':'not executed'}, ensure_ascii=False))


if __name__ == '__main__':
    main()
