#!/usr/bin/env python3
"""상위 항목 이동: 합성 DB/local PHP에서만 실행하는 기능 수락 시험."""
from pathlib import Path
exec(compile(Path(__file__).with_name('memories_http.py').read_text().split('try:\n for u in users:')[0], __file__, 'exec'))
from urllib.parse import urlsplit
from playwright.sync_api import sync_playwright, expect
assert str(root).startswith('/tmp/qaredi-stage13-')
UA = 'tbox-review/' + time.strftime('%Y-%m-%d')


def create(title, parent=None, project=False):
    body = {'title': title}
    if parent: body['parent_id'] = parent
    if project: body.update(database=True, preset='project')
    pid = call('POST', '/api/pages', body, expected=201)['page']['id']
    if not project: call('PATCH', '/api/pages/' + pid + '/properties', {'status': 'todo'})
    return pid


def wait_title(p, title):
    expect(p.locator('#task-pane-title')).to_have_text(title)


try:
    for u in ('employee', 'other', 'guest'):
        users[u]['username'] = sql('SELECT username FROM users WHERE id=' + str(users[u]['id']))
        call('GET', '/api/me/space', user=u)
    sql("INSERT INTO community.usage_guides(user_id,version,status,context) SELECT id,1,'dismissed','overview' FROM users ON CONFLICT DO NOTHING")
    proj = create('상위 이동 시험 프로젝트', project=True)
    title = ('상위 <script>문자</script> ' + '긴 작업 제목 ' * 16).strip()
    parent = create(title, proj)
    child = create('하위 작업', parent)
    grand = create('손자 작업', child)
    root_task = create('독립 작업')
    hidden = create('공유하지 않은 비공개 부모')
    shared = create('이 항목만 공유', hidden)
    for pid, who in ((parent, 'guest'), (shared, 'other')):
        raw('PUT', '/api/pages/' + pid + '/shares/' + users[who]['username'], {'level': 'view'}) .raise_for_status()
    check(call('GET', '/api/pages/' + shared, user='other')['page']['parent'] is None, 'API hides unreadable parent')
    check(call('GET', '/api/pages/' + child, user='guest')['page']['parent']['id'] == parent, 'API returns readable parent to view-only guest')

    with sync_playwright() as pw:
        browser = pw.chromium.launch(executable_path=os.environ.get('CHROMIUM_EXECUTABLE'))
        for width in (() if os.environ.get('TASK_PARENT_ONLY') == 'save' else (1440, 390)):
            ctx = browser.new_context(viewport={'width': width, 'height': 900}, has_touch=width < 720, is_mobile=width < 720, user_agent=UA)
            ctx.add_cookies([{'name': '__Host-tbox_session', 'value': users['employee']['session'], 'domain': urlsplit(site).hostname, 'path': '/', 'secure': True}])
            p = ctx.new_page(); errors = []
            p.on('pageerror', lambda e: errors.append(str(e)))
            project_url = site + '/community/p/' + proj
            p.goto(project_url + '?task=' + child, wait_until='networkidle')
            wait_title(p, '하위 작업')
            link = p.locator('[data-task-parent]')
            expect(link).to_be_visible()
            check(link.get_attribute('href').endswith('?task=' + parent), f'{width}: direct link points to actual parent')
            check(link.locator('.task-pane__parent-title').text_content() == title and link.locator('script').count() == 0, f'{width}: parent title is literal text')
            box = link.bounding_box()
            check(box['height'] >= 44 and box['x'] >= 0 and box['x'] + box['width'] <= width + 1, f'{width}: long title and 44px touch target fit viewport')
            p.screenshot(path=str(out / f'parent-{width}.png'))
            link.focus(); p.keyboard.press('Enter'); wait_title(p, title)
            check(urlsplit(p.url).path.endswith(proj), f'{width}: parent opens in same project pane')
            p.go_back(); wait_title(p, '하위 작업')
            p.go_forward(); wait_title(p, title)
            check(True, f'{width}: keyboard parent navigation and browser back/forward')
            p.goto(project_url + '?task=' + grand, wait_until='networkidle')
            p.reload(wait_until='networkidle'); wait_title(p, '손자 작업')
            p.locator('[data-task-parent]').click(); wait_title(p, '하위 작업')
            p.locator('[data-task-parent]').click(); wait_title(p, title)
            check(True, f'{width}: refreshed deep link climbs two levels')
            check(p.locator('[data-task-parent]').count() == 0, f'{width}: project row uses existing project link')
            p.goto(site + '/community/p/' + child, wait_until='networkidle')
            crumb = p.locator('#page-crumb a[href="/community/p/' + parent + '"]'); expect(crumb).to_be_visible()
            check(crumb.get_attribute('href') == '/community/p/' + parent, f'{width}: full screen parent has canonical URL')
            with ctx.expect_page() as popup:
                crumb.click(modifiers=['Control'])
            other_tab = popup.value; other_tab.wait_for_load_state('networkidle'); wait_title(other_tab, title); other_tab.close()
            expect(crumb).to_be_visible()
            crumb.click(); wait_title(p, title)
            check(urlsplit(p.url).path.endswith(parent) and 'task=' not in p.url, f'{width}: full screen parent and modified click')
            p.goto(project_url + '?task=' + root_task, wait_until='networkidle'); wait_title(p, '독립 작업')
            check(p.locator('[data-task-parent]').count() == 0, f'{width}: root has no invented parent')
            check(not errors, f'{width}: no JS errors')
            ctx.close()

        for who, pid, expected_parent in (() if os.environ.get('TASK_PARENT_ONLY') == 'save' else (('guest', child, parent), ('other', shared, None))):
            ctx = browser.new_context(user_agent=UA)
            ctx.add_cookies([{'name': '__Host-tbox_session', 'value': users[who]['session'], 'domain': urlsplit(site).hostname, 'path': '/', 'secure': True}])
            p = ctx.new_page(); p.goto(site + '/community/my-tasks?task=' + pid, wait_until='networkidle')
            wait_title(p, '하위 작업' if who == 'guest' else '이 항목만 공유')
            check(p.locator('[data-task-parent]').count() == (1 if expected_parent else 0), who + ': parent visibility follows API')
            if expected_parent:
                p.locator('[data-task-parent]').click(); wait_title(p, title)
                check(p.locator('input[aria-label="마감 날짜"]').count() == 0, 'view-only reader returns to parent without edit controls')
            ctx.close()

        ctx = browser.new_context(user_agent=UA)
        ctx.add_cookies([{'name': '__Host-tbox_session', 'value': users['employee']['session'], 'domain': urlsplit(site).hostname, 'path': '/', 'secure': True}])
        p = ctx.new_page()
        for full in (False,):
            address = site + '/community/p/' + child if full else site + '/community/p/' + proj + '?task=' + child
            p.goto(address, wait_until='networkidle'); wait_title(p, '하위 작업')
            p.locator('.task-pane__doc--editable').click()
            field = p.locator('.task-pane .ProseMirror'); expect(field).to_be_visible()
            saved_text = '상위 이동 전 저장 ' + str(full)
            field.fill(saved_text)
            p.locator('[data-task-parent]').click(); wait_title(p, title)
            blocks = call('GET', '/api/pages/' + child)['page']['doc']['blocks']
            check(saved_text in json.dumps(blocks, ensure_ascii=False), f'full={full}: parent navigation awaits pending save')
            p.goto(address, wait_until='networkidle'); wait_title(p, '하위 작업')
            p.locator('.task-pane__doc--editable').click(); field = p.locator('.task-pane .ProseMirror'); expect(field).to_be_visible()
            field.fill('저장 충돌 때 남아야 할 글')
            def conflict(route):
                if route.request.method == 'PATCH': route.fulfill(status=409, json={'error': 'conflict'})
                else: route.continue_()
            p.route('**/community/api/pages/' + child, conflict)
            p.locator('[data-task-parent]').click()
            expect(p.locator('.task-pane > .msg[role="alert"]')).to_contain_text('다른 곳에서 먼저 저장')
            wait_title(p, '하위 작업'); expect(field).to_have_text('저장 충돌 때 남아야 할 글')
            check(True, f'full={full}: failed save keeps URL, editor and draft')
            p.unroute('**/community/api/pages/' + child, conflict)
        ctx.close(); browser.close()
except BaseException as error:
    checks.append({'pass': False, 'name': 'suite raised ' + type(error).__name__})
    raise
finally:
    (out / 'task-parent-results.json').write_text(json.dumps(checks, ensure_ascii=False, indent=2))
    print('RESULT pass=' + str(sum(c['pass'] for c in checks)) + ' fail=' + str(sum(not c['pass'] for c in checks)), flush=True)
