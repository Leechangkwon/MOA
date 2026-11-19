#!/usr/bin/env python3
"""Integrated shipped PHP/SQL/browser acceptance, only inside memories_harness.py.

No production content or credentials are recorded. This suite checks lazy loading,
first editing/upload actions, durable text, opt-in numeric diagnostics and layout.
It deliberately does not label browser Event Timing or local whiteboard updates INP.
"""
from pathlib import Path
exec(compile(Path(__file__).with_name('memories_http.py').read_text().split('try:\n for u in users:')[0], __file__, 'exec'))
from urllib.parse import urlsplit
from playwright.sync_api import sync_playwright

assert str(root).startswith('/tmp/qaredi-stage13-')
UA = 'tbox-review/' + time.strftime('%Y-%m-%d')
shots = out / 'shots'
shots.mkdir(exist_ok=True)
metrics = {}
ONLY = os.environ.get('MODERN_WEB_ONLY', '')


def wait(page, expression, timeout=15000):
    deadline = time.monotonic() + timeout / 1000
    while time.monotonic() < deadline:
        if page.evaluate(expression):
            return
        page.wait_for_timeout(100)
    raise TimeoutError(expression[:100])


def context(browser, user='employee', width=1280):
    ctx = browser.new_context(viewport={'width': width, 'height': 900}, user_agent=UA,
                              reduced_motion='reduce', service_workers='block')
    ctx.add_cookies([{'name': '__Host-tbox_session', 'value': users[user]['session'],
                      'domain': urlsplit(site).hostname, 'path': '/', 'secure': True,
                      'httpOnly': True, 'sameSite': 'Lax'}])
    return ctx


def para(text):
    return {'id': str(uuid.uuid4()), 'type': 'paragraph', 'text': [{'t': text, 'm': {}}]}


try:
    for user in users:
        users[user]['username'] = sql('SELECT username FROM users WHERE id=' + str(users[user]['id']))
        call('GET', '/api/me/space', user=user)
    # Use the same returning-user state for before/after screenshots; first-use invitation has its own suite.
    sql("INSERT INTO community.usage_guides(user_id,version,status,context) SELECT id,1,'dismissed','overview' FROM users ON CONFLICT DO NOTHING")
    project = call('POST', '/api/pages', {'database': True, 'preset': 'project', 'title': '합성 개선 검증 프로젝트'}, expected=201)['page']
    task = call('POST', '/api/pages', {'parent_id': project['id'], 'title': '업무 설명과 회의 그림을 한곳에서 확인하기'}, expected=201)['page']
    call('PATCH', '/api/pages/' + task['id'] + '/properties', {'status': 'todo'})
    doc = call('POST', '/api/pages', {'title': '합성 개선 검증 문서'}, expected=201)['page']
    call('PATCH', '/api/pages/' + doc['id'], {'doc_version': doc['doc_version'], 'doc': {'schema': 1, 'blocks': [para('이 문서는 화면 검증을 위한 합성 자료입니다.')]}})
    call('PUT', '/api/pages/' + doc['id'] + '/shares/' + users['other']['username'], {'level': 'view'})

    with sync_playwright() as pw:
        browser = pw.chromium.launch(executable_path=os.environ.get('CHROMIUM_EXECUTABLE'), args=['--no-sandbox'])
        # Every initial route is a new document/context, so a prior editor cannot hide imports.
        for name, path in [('home', '/'), ('work', '/community/'), ('tasks', '/community/my-tasks'),
                           ('documents', '/community/workspace'), ('document-read', '/community/p/' + doc['id']),
                           ('task-read', '/community/p/' + task['id']), ('memories', '/community/memories')]:
            if ONLY:
                continue
            ctx = context(browser)
            page = ctx.new_page()
            errors, requests_seen = [], []
            page.on('pageerror', lambda error: errors.append(type(error).__name__))
            page.on('request', lambda request: requests_seen.append(urlsplit(request.url).path))
            response = page.goto(site + path, wait_until='networkidle')
            page.wait_for_timeout(300)
            editing = [p for p in requests_seen if any(s in p for s in ['/vendor/prosemirror', '/editor-runtime.js', '/editor-schema.js', '/table-editor.js'])]
            check(response.status == 200 and not errors, name + ': actual PHP navigation without JS exceptions')
            check(not editing, name + ': no editing runtime, schema, table or ProseMirror requested')
            check(not any(p.endswith('/files-page.js') for p in requests_seen), name + ': no file-manager module requested')
            check(not any(p.endswith('/performance-diagnostics.js') for p in requests_seen), name + ': opt-in diagnostics not requested')
            check(page.evaluate('document.documentElement.scrollWidth <= innerWidth'), name + ': no document horizontal overflow')
            metrics[name] = {'script_requests': len([p for p in requests_seen if p.endswith('.js')]), 'editing_requests': len(editing), 'errors': len(errors)}
            ctx.close()

        if not ONLY:
            # First keystrokes during a delayed real runtime download must reach the actual server.
            ctx = context(browser)
            page = ctx.new_page()
            errors, held = [], []
            page.on('pageerror', lambda error: errors.append(type(error).__name__))
            ctx.route('**/community/editor-runtime.js?*', lambda route: held.append(route))
            page.goto(site + '/community/p/' + doc['id'], wait_until='networkidle')
            page.locator('#page-edit').click()
            page.wait_for_selector('#page-editor textarea[aria-label="편집기를 불러오는 동안 입력"]')
            page.locator('#page-editor textarea').fill('첫 입력 한글 보존 확인')
            check(bool(held), 'edit: runtime request is deferred until the editing action')
            for route in held:
                route.continue_()
            page.wait_for_selector('#page-editor .ProseMirror[contenteditable=true]')
            wait(page, "document.querySelector('#page-editor .ProseMirror').textContent.includes('첫 입력 한글 보존 확인')")
            check(True, 'edit: first buffered Korean text transferred into the real editor')
            deadline = time.monotonic() + 15
            durable = False
            while time.monotonic() < deadline:
                saved = raw('GET', '/api/pages/' + doc['id']).json()['page']['doc']
                if '첫 입력 한글 보존 확인' in json.dumps(saved, ensure_ascii=False):
                    durable = True
                    break
                page.wait_for_timeout(250)
            check(durable and not errors, 'edit: first buffered text durably stored by original autosave API')
            ctx.close()

            # A view-only server response still cannot expose an editor through the new loader.
            ctx = context(browser, user='other')
            page = ctx.new_page()
            seen = []
            page.on('request', lambda request: seen.append(urlsplit(request.url).path))
            page.goto(site + '/community/p/' + doc['id'], wait_until='networkidle')
            check(not page.locator('#page-edit').is_visible() and not page.locator('[contenteditable=true]').count(), 'read permission: edit action and writable DOM absent')
            check(not any(p.endswith('/editor-runtime.js') for p in seen), 'read permission: editing runtime not downloaded')
            ctx.close()

        if ONLY != 'screens':
            # Native XHR sends the same FormData to the existing, CSRF-protected upload API.
            ctx = context(browser)
            page = ctx.new_page()
            held, errors = [], []
            page.on('pageerror', lambda error: errors.append(type(error).__name__))
            ctx.route('**/assets/files-page.js?*', lambda route: held.append(route))
            page.goto(site + '/tbox', wait_until='domcontentloaded')
            wait(page, "document.querySelector('#upload-toggle') && !document.querySelector('#upload-toggle').hidden")
            deadline = time.monotonic() + 10
            while not held and time.monotonic() < deadline:
                page.wait_for_timeout(100)
            page.locator('#upload-toggle').click()
            check(bool(held), 'files: file-manager module loads only on the file-manager route')
            for route in held:
                route.continue_()
            page.wait_for_selector('#upload-dialog[open]')
            check(True, 'files: first upload action during module loading is replayed once')
            check(page.locator('#upload-file').get_attribute('aria-invalid') is None, 'validation: untouched required upload field starts unmarked')
            page.locator('#upload-submit').click()
            check(page.locator('#upload-file').evaluate('(n)=>n===document.activeElement') and page.locator('#upload-file').get_attribute('aria-invalid')=='true', 'validation: native first-invalid focus and programmatic state agree')
            page.evaluate("document.querySelector('#upload-form').reset()")
            wait(page, "!document.querySelector('#upload-file').hasAttribute('aria-invalid')")
            check(True, 'validation: accepted native reset clears helper state')
            page.locator('#upload-file').set_input_files({'name': '합성 업로드.txt', 'mimeType': 'text/plain', 'buffer': ('검증 자료\n' * 65536).encode()})
            page.locator('#upload-level').select_option('public')
            page.evaluate("""() => { window.__uploadStages=[]; const root=document.querySelector('#upload-status-body');
              new MutationObserver(records => { const p=root.querySelector('progress'); const removed=records.flatMap(r=>[...r.removedNodes]).map(n=>n.textContent).join(' '); window.__uploadStages.push({progress:Boolean(p),value:p?.getAttribute('value'),text:root.textContent.includes('서버 확인 중'),completed:removed.includes('검사 상태는 목록에서 확인')}); }).observe(root,{subtree:true,childList:true,characterData:true,attributes:true}); }""")
            statuses = []
            page.on('response', lambda response: statuses.append({'status':response.status,'method':response.request.method}) if urlsplit(response.url).path == '/tbox/api/files' else None)
            page.locator('#upload-submit').click()
            try:
                wait(page, "!document.querySelector('#upload-dialog').open", timeout=30000)
            except Exception:
                metrics['upload-failure'] = {'status':page.locator('#upload-status-body').inner_text(), 'message':page.locator('#upload-msg').inner_text(), 'errors':errors, 'responses':statuses}
                raise
            stages = page.evaluate('window.__uploadStages')
            check(any(s['progress'] for s in stages) and any(s['text'] for s in stages), 'upload: actual native byte progress and separate server-confirmation stage observed')
            check(any(s['completed'] for s in stages), 'upload: byte completion is not presented as scan approval')
            check(sql("SELECT count(*) FROM files WHERE original_name='합성 업로드.txt'") == '1', 'upload: one first action creates exactly one server file')
            check(not errors, 'files: lazy module and upload complete without JS exceptions')
            ctx.close()

            # Explicit opt-in exposes bounded numeric summaries without a telemetry endpoint.
            ctx = context(browser)
            page = ctx.new_page()
            seen = []
            page.on('request', lambda request: seen.append((request.method, urlsplit(request.url).path)))
            page.goto(site + '/community/', wait_until='networkidle')
            before = len(seen)
            awaitable = page.evaluate('async () => { await MoaPerformanceDiagnostics.start(); await new Promise(r=>setTimeout(r,250)); const s=await MoaPerformanceDiagnostics.snapshot(); await MoaPerformanceDiagnostics.stop(); return s; }')
            after = seen[before:]
            check(any(p.endswith('/performance-diagnostics.js') for _, p in after), 'diagnostics: explicit opt-in loads the module')
            check(not any(method != 'GET' for method, _ in after), 'diagnostics: no telemetry write request')
            encoded = json.dumps(awaitable, ensure_ascii=False)
            check(users['employee']['username'] not in encoded and doc['id'] not in encoded and '/community/' not in encoded, 'diagnostics: snapshot excludes user, content identifiers and page URLs')
            metrics['diagnostics'] = awaitable
            ctx.close()

        for width in (390, 1280):
            ctx = context(browser, width=width)
            page = ctx.new_page()
            for name, path in [('documents', '/community/workspace'), ('memories', '/community/memories'), ('task', '/community/p/' + task['id'])]:
                page.goto(site + path, wait_until='networkidle')
                page.wait_for_timeout(300)
                page.screenshot(path=str(shots / (name + '-' + str(width) + '.png')))
                check(page.evaluate('document.documentElement.scrollWidth <= innerWidth'), str(width) + ' ' + name + ': final integrated layout fits')
            ctx.close()
        browser.close()
except Exception as error:
    checks.append({'pass':False,'name':'suite exception: '+type(error).__name__})
    raise
finally:
    (out / 'modern-web-results.json').write_text(json.dumps({'checks': checks, 'metrics': metrics}, ensure_ascii=False, indent=2))
    print('Modern web: %d passed, %d failed' % (sum(c['pass'] for c in checks), sum(not c['pass'] for c in checks)), flush=True)
