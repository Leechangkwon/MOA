#!/usr/bin/env python3
"""Versioned assets with an old public JS cache: real shell, lazy editing and retry.

Called by stale_cache_browser.py with an empty synthetic fixture. The legacy shell
can still call this file, but its production-record-copying setup must not be run.
SMOKE_E1/OLD_DIR/APP_VER are environment-only inputs; credentials are never logged.
"""
import json
import os
from pathlib import Path
import re
import sys
from urllib.parse import parse_qs, urlsplit

from playwright.sync_api import expect, sync_playwright

SITE = sys.argv[1].rstrip('/')
UA = sys.argv[2]
E1 = os.environ['SMOKE_E1']
OLD_DIR = Path(os.environ['OLD_DIR'])
APP_VER = os.environ['APP_VER']
COMMUNITY = SITE + '/community'
ASSET_PREFIX = '/tbox/assets/community/'
PAGE_RE = re.compile(r'/community/p/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})')
OLD = {name: (OLD_DIR / name).read_text() for name in ('doc-render.js', 'database.js', 'templates.js', 'editor-schema.js')}
TEXT = '캐시 검증 첫 한글 입력'
checks = []
diagnostics = {}


def step(name, ok, detail=''):
    checks.append({'name': name, 'pass': bool(ok)})
    print('  %s %s%s' % ('✔' if ok else '✖', name, (' — ' + detail) if detail else ''), flush=True)


def run(browser, mode):
    seen = {key: [] for key in ('pageerrors', 'console', 'served_old', 'js', 'document_js', 'posts', 'trash_gets', 'http_bad', 'module_errors')}
    seen.update(created='', trash_open=False, edit_ready=False, typed=False, saved=False, flow_error='',
                initial_pm=[], message='', aborted=0, retry_ready=False, buffer_preserved=False, retry_fetches=0,
                flow_detail='', retry_error_cleared=False)
    ctx = browser.new_context(user_agent=UA, viewport={'width': 390, 'height': 844}, service_workers='block')
    ctx.add_cookies([{'name': '__Host-tbox_session', 'value': E1, 'domain': urlsplit(SITE).hostname,
                     'path': '/', 'httpOnly': True, 'secure': True, 'sameSite': 'Lax'}])

    def on_asset(route, request):
        parts = urlsplit(request.url)
        rel = parts.path[len(ASSET_PREFIX):]
        ver = (parse_qs(parts.query).get('v') or [''])[0]
        name = rel + ('?' + parts.query if parts.query else '')
        seen['js'].append(name)
        if PAGE_RE.search(request.frame.url):
            seen['document_js'].append(name)
        if mode == 'retry' and rel == 'editor-runtime.js':
            seen['retry_fetches'] += 1
            if seen['aborted'] == 0:
                seen['aborted'] += 1
                route.abort('failed')
                return
        if rel in OLD and (mode == 'control' or ver != APP_VER):
            seen['served_old'].append({'module': rel, 'version': ver})
            route.fulfill(status=200, content_type='text/javascript; charset=utf-8', body=OLD[rel])
        else:
            route.continue_()

    ctx.route(re.compile(r'.*/tbox/assets/community/.*\.js(\?.*)?$'), on_asset)
    page = ctx.new_page()
    page.on('pageerror', lambda error: seen['pageerrors'].append(str(error)))
    page.on('console', lambda message: seen['console'].append(message.text)
            if message.type == 'error' and 'inline style' not in message.text and 'Failed to load resource' not in message.text else None)
    page.on('request', lambda request: seen['posts'].append(request.url)
            if request.method == 'POST' and request.url.endswith('/community/api/pages') else None)
    page.on('request', lambda request: seen['trash_gets'].append(request.url)
            if request.method == 'GET' and '/community/api/me/trash' in request.url else None)
    page.on('response', lambda response: seen['http_bad'].append(response.status)
            if response.status >= 400 and urlsplit(response.url).hostname == urlsplit(SITE).hostname else None)
    try:
        # The former document home now lives at /workspace; work home has a different header.
        page.goto(COMMUNITY + '/workspace', wait_until='networkidle')
        page.wait_for_selector('#community-new')
        if not page.locator('#community-more').evaluate('(element) => element.open'):
            page.click('#community-more > summary')
        with page.expect_response(lambda response: '/community/api/me/trash' in response.url and response.request.method == 'GET'):
            page.click('#community-trash')
        page.wait_for_selector('#trash-dialog[open]')
        seen['trash_open'] = True
        page.locator('[data-trash-close]').first.click()
        seen['initial_pm'] = [name for name in seen['js'] if name.startswith('vendor/pm/') or name.split('?')[0] in ('editor-runtime.js', 'editor-schema.js', 'table-editor.js')]
        page.click('#community-new')
        page.wait_for_url(PAGE_RE, timeout=15000)
        match = PAGE_RE.search(page.url)
        seen['created'] = match.group(1) if match else ''
        if mode == 'fixed':
            page.wait_for_selector('#page-editor .ProseMirror[contenteditable=true]', timeout=20000)
            seen['edit_ready'] = True
            editor = page.locator('#page-editor .ProseMirror[contenteditable=true]')
            editor.fill(TEXT)
            seen['typed'] = TEXT in editor.inner_text()
            page.click('#page-done')
            page.wait_for_selector('#page-doc:not([hidden])')
            seen['saved'] = page.evaluate('''async ({id,text}) => {
                const base=document.body.dataset.communityBase;
                const response=await fetch(base+'/api/pages/'+id,{credentials:'same-origin',cache:'no-store'});
                const result=await response.json();
                return response.ok && JSON.stringify(result.page.doc).includes(text);
            }''', {'id': seen['created'], 'text': TEXT})
        elif mode == 'control':
            # Lazy-module errors are caught and reported by the page/loader, so pageerror may remain empty.
            # Locator assertions poll without evaluating a string under script-src 'self'.
            expect(page.locator('#msg')).to_have_text(re.compile(r'\S'), timeout=15000)
            seen['message'] = page.locator('#msg').inner_text().strip()
            seen['edit_ready'] = page.locator('#page-editor .ProseMirror[contenteditable=true]').count() > 0
            seen['module_errors'] = page.evaluate('''async version => {
                const prefix=document.body.dataset.base+'/assets/community/';
                const failures=[];
                for (const name of ['page.js','editor-runtime.js']) {
                    try { await import(prefix+name+'?v='+version); }
                    catch (error) { failures.push({module:name,message:String(error.message)}); }
                }
                return failures;
            }''', APP_VER)
        else:
            buffer = page.locator('#page-editor textarea[aria-label="편집기를 불러오는 동안 입력"]')
            buffer.wait_for(state='visible')
            buffer.fill(TEXT)
            retry = page.locator('#page-editor').get_by_role('button', name='다시 불러오기', exact=True)
            retry.wait_for(state='visible')
            seen['buffer_preserved'] = buffer.input_value() == TEXT
            retry.click()
            try:
                page.wait_for_selector('#page-editor .ProseMirror[contenteditable=true]', timeout=12000)
                seen['retry_ready'] = True
                seen['typed'] = TEXT in page.locator('#page-editor .ProseMirror').inner_text()
                seen['retry_error_cleared'] = not page.locator('#msg').inner_text().strip()
                page.click('#page-done')
                page.wait_for_selector('#page-doc:not([hidden])')
                seen['saved'] = page.evaluate('''async ({id,text}) => {
                    const response=await fetch(document.body.dataset.communityBase+'/api/pages/'+id,{credentials:'same-origin',cache:'no-store'});
                    const result=await response.json();
                    return response.ok && JSON.stringify(result.page.doc).includes(text);
                }''', {'id': seen['created'], 'text': TEXT})
            except Exception as error:
                seen['flow_error'] = type(error).__name__
                seen['flow_detail'] = str(error).splitlines()[0][:300]
                seen['buffer_preserved'] = buffer.count() > 0 and buffer.input_value() == TEXT
                seen['message'] = page.locator('#msg').inner_text().strip()
    except Exception as error:
        seen['flow_error'] = type(error).__name__
        seen['flow_detail'] = str(error).splitlines()[0][:300]
    finally:
        ctx.close()
    diagnostics[mode] = {key: seen[key] for key in ('flow_error', 'flow_detail', 'module_errors', 'served_old', 'js', 'document_js', 'aborted', 'retry_fetches', 'buffer_preserved', 'retry_ready', 'retry_error_cleared', 'typed', 'saved')}
    return seen


def shell_checks(prefix, seen):
    step(prefix + ' 문서 목록의 휴지통 GET과 대화상자가 동작한다', bool(seen['trash_gets']) and seen['trash_open'])
    step(prefix + ' 새 문서 POST와 실제 문서 이동이 동작한다', len(seen['posts']) == 1 and bool(seen['created']))
    if seen['created']:
        print('CREATED=' + seen['created'], flush=True)


def main():
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(executable_path=os.environ.get('CHROMIUM_EXECUTABLE'), args=['--no-sandbox'])
        try:
            print('── A. 정상 버전: 목록 → 휴지통 → 새 문서 → 실제 편집/저장', flush=True)
            fixed = run(browser, 'fixed')
            shell_checks('A', fixed)
            app = [name for name in fixed['js'] if not name.startswith('vendor/') and name.split('?')[0] != 'editor.js']
            pm = [name for name in fixed['js'] if name.startswith('vendor/pm/')]
            step('A 현재 부품 요청과 버전 표시가 있다', len(app) >= 5 and all((parse_qs(urlsplit(name).query).get('v') or [''])[0] == APP_VER for name in app))
            step('A 목록 탐색에서는 편집기·PM을 받지 않는다', not fixed['initial_pm'])
            step('A 실제 편집 진입 뒤 PM을 버전 붙여 받는다', bool(pm) and all('?v=' in name for name in pm))
            step('A 옛 파일 주입 0', not fixed['served_old'])
            step('A 실제 편집과 한글 입력이 동작한다', fixed['edit_ready'] and fixed['typed'])
            step('A 기존 저장 창구에 한글이 남는다', fixed['saved'])
            step('A 예외·콘솔·HTTP 실패 0', not fixed['pageerrors'] and not fixed['console'] and not fixed['http_bad'] and not fixed['flow_error'], fixed['flow_error'])

            print('── B. 음성 대조: 현재 버전 URL에 옛 파일 → 문서/편집 모듈 실패', flush=True)
            control = run(browser, 'control')
            shell_checks('B', control)
            step('B 현재 버전 URL에도 옛 파일을 실제 주입했다', any(item['version'] == APP_VER for item in control['served_old']))
            step('B 새 문서의 실제 편집 진입이 실패했다', bool(control['created']) and not control['edit_ready'])
            step('B 잡힌 모듈 오류를 화면 #msg에 알린다', bool(control['message']))
            missing = [item for item in control['module_errors'] if 'does not provide an export' in item['message'] and
                       re.search(r'\b(embedHost|imageWidthPct|renderFileBlock|mentionAllowed|editorDomParser|clipboardDomParser)\b', item['message'])]
            step('B 실제 소유 모듈의 명시적 import.catch가 missing-export를 진단한다', bool(missing), ', '.join(item['module'] for item in missing))
            step('B 오류가 잡힌 뒤에도 HTTP 창구는 실패하지 않는다', not control['http_bad'] and not control['flow_error'], control['flow_detail'])

            print('── C. 첫 runtime 요청 중단 → 한글 보존 → 다시 불러오기', flush=True)
            retry = run(browser, 'retry')
            shell_checks('C', retry)
            step('C 첫 runtime 네트워크 요청만 실제로 중단했다', retry['aborted'] == 1)
            step('C 실패 동안 textarea의 한글이 보존된다', retry['buffer_preserved'])
            step('C 다시 불러오기가 runtime을 다시 요청한다', retry['retry_fetches'] >= 2, 'requests=' + str(retry['retry_fetches']))
            retry_entries = [parse_qs(urlsplit(name).query) for name in retry['js'] if name.split('?')[0] == 'editor-runtime.js']
            retry_deps = [name for name in retry['document_js'] if name.split('?')[0] in ('editor-schema.js', 'doc-render.js', 'table-editor.js') or name.startswith('vendor/pm/')]
            step('C 새 runtime URL만 retry 질의를 쓰고 의존 모듈 identity를 유지한다',
                 len(retry_entries) >= 2 and retry_entries[0] == {'v': [APP_VER]} and
                 retry_entries[1] == {'v': [APP_VER], 'retry': ['1']} and bool(retry_deps) and
                 all('retry' not in parse_qs(urlsplit(name).query) for name in retry_deps) and
                 len(retry_deps) == len(set(retry_deps)))
            step('C 다시 불러온 실제 PM에 한글이 복원된다', retry['retry_ready'] and retry['typed'], retry['flow_error'])
            step('C 편집기 복원 성공 뒤 이전 로딩 오류 문구가 남지 않는다', retry['retry_ready'] and retry['retry_error_cleared'])
            step('C 복원한 한글을 기존 창구에 저장한다', retry['saved'])
            step('C 처리하지 않은 JS 오류 0', not retry['pageerrors'])
        finally:
            browser.close()
    fails = sum(not item['pass'] for item in checks)
    if os.environ.get('STALE_CACHE_RESULT'):
        result = Path(os.environ['STALE_CACHE_RESULT'])
        result.write_text(json.dumps(checks, ensure_ascii=False, indent=2))
        result.with_name('stale-cache-diagnostics.json').write_text(json.dumps(diagnostics, ensure_ascii=False, indent=2))
    print('SMOKE_FAIL=' + str(fails), flush=True)
    print('RESULT pass=' + str(len(checks) - fails) + ' fail=' + str(fails), flush=True)
    return int(bool(fails))


if __name__ == '__main__':
    raise SystemExit(main())
