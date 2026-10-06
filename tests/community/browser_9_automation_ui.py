#!/usr/bin/env python3
"""Real synthetic HTTP and real dialog interactions for the automation UI."""
import json
import os
from pathlib import Path
import time
import uuid
from urllib.parse import urlsplit
import requests
from playwright.sync_api import sync_playwright, expect

manifest = json.loads(Path(os.environ['COMMUNITY_EXPORT_MANIFEST']).read_text())
assert manifest['database'].startswith('tbox_export_test_')
data = json.loads(Path(manifest['credentials_file']).read_text())
output = Path(os.environ['COMMUNITY_EXPORT_OUTPUT'])
site = manifest['base_url'].removesuffix('/tbox')
host = urlsplit(site).hostname
user = data['users']['employee']
headers = {'User-Agent': 'tbox-review/' + time.strftime('%Y-%m-%d'), 'Cookie': '__Host-tbox_session=' + user['session'], 'X-CSRF-Token': user['csrf']}
checks = []


def check(ok, name):
    checks.append({'pass': bool(ok), 'name': name})
    print(('PASS ' if ok else 'FAIL ') + name, flush=True)
    if not ok:
        raise AssertionError(name)


def api(method, path, body=None):
    r = requests.request(method, site + '/community' + path, json=body, headers=headers, timeout=20)
    assert r.ok, (method, path, r.status_code)
    return r.json()


try:
    with sync_playwright() as p:
        browser = p.chromium.launch(executable_path=os.environ.get('CHROMIUM_EXECUTABLE'))
        setup = browser.new_context(user_agent=headers['User-Agent'])
        setup.add_cookies([{'name': '__Host-tbox_session', 'value': user['session'], 'domain': host, 'path': '/', 'secure': True, 'httpOnly': True, 'sameSite': 'Lax'}])
        # Q:F-12 C — 옛 「오늘」 화면은 308 넘김이 되었고 사이드바 「새 문서」는 「＋ 새로 만들기 → 문서」 안으로 옮겼다(DESIGN 2.19 ②)
        initial = setup.new_page(); initial.goto(site + '/community/workspace', wait_until='load')   # 사이드바는 서버가 그린다
        initial.locator('#nav-new-menu > summary').click()
        initial.locator('#nav-community-new').click()
        initial.wait_for_url('**/community/p/*')
        pid = urlsplit(initial.url).path.rsplit('/', 1)[-1]
        check(bool(uuid.UUID(pid)), 'first-time user creates a page from the sidebar 새로 만들기 → 문서 without pre-existing space')
        current = api('GET', '/api/pages/' + pid)
        api('PATCH', '/api/pages/' + pid, {'doc_version': current['page']['doc_version'], 'title': '월마감 절차서 UI 검증', 'doc': {'schema': 1, 'blocks': [
            {'id': str(uuid.uuid4()), 'type': 'to_do', 'checked': False, 'text': [{'t': '첨부파일 확인', 'm': {}}]},
        ]}})
        api('PATCH', '/api/pages/' + pid + '/properties', {'kind': 'procedure'})
        setup.close()
        for width, theme in [(1280, 'light'), (390, 'dark')]:
            ctx = browser.new_context(viewport={'width': width, 'height': 900}, locale='ko-KR', color_scheme=theme,
                                      user_agent=headers['User-Agent'])
            ctx.add_cookies([{'name': '__Host-tbox_session', 'value': user['session'], 'domain': host, 'path': '/', 'secure': True, 'httpOnly': True, 'sameSite': 'Lax'},
                             {'name': 'tbox_theme', 'value': theme, 'domain': host, 'path': '/', 'sameSite': 'Lax'}])
            page = ctx.new_page(); errors = []
            page.on('pageerror', lambda error: errors.append(str(error)))
            page.goto(site + '/community/p/' + pid, wait_until='networkidle')
            page.locator('#page-more > summary').click()
            page.get_by_role('button', name='⟳ 반복·버튼 만들기', exact=True).click()
            dialog = page.get_by_role('dialog', name='반복·버튼 작업 만들기', exact=True)
            expect(dialog).to_be_visible()
            check(not dialog.get_by_label('요일', exact=True).is_visible() and dialog.get_by_label('월 날짜', exact=True).is_visible(), 'monthly fields only ' + str(width))
            dialog.get_by_label('주기', exact=True).select_option('weekly')
            check(dialog.get_by_label('요일', exact=True).is_visible() and not dialog.get_by_label('월 날짜', exact=True).is_visible(), 'weekly fields only ' + str(width))
            dialog.get_by_label('작업', exact=True).select_option('button')
            check(not dialog.get_by_label('주기', exact=True).is_visible() and dialog.get_by_label('버튼을 누르는 사람', exact=True).is_visible(), 'button hides schedule ' + str(width))
            dialog.get_by_label('작업', exact=True).select_option('recurring')
            check(dialog.evaluate('(e) => e.getBoundingClientRect().left >= 0 && e.getBoundingClientRect().right <= innerWidth'), 'dialog fits ' + str(width))
            page.screenshot(path=str(output / ('automation-form-' + str(width) + '-' + theme + '.png')), full_page=True)
            if width == 1280:
                dialog.get_by_role('button', name='시험 실행', exact=True).click()
                expect(dialog.get_by_role('button', name='켜기', exact=True)).to_be_enabled(timeout=15000)
                check('만들 문서:' in dialog.inner_text() and 'create_page' not in dialog.inner_text() and 'would_create' not in dialog.inner_text(), 'preview uses actual titles and Korean steps, no JSON')
                before = api('GET', '/api/automations')['automations']
                check(len(before) == 1 and not before[0]['is_enabled'], 'dry run leaves one disabled rule')
                dialog.get_by_label('작업 이름', exact=True).fill('월마감 반복 수정')
                check(dialog.get_by_role('button', name='켜기', exact=True).is_disabled(), 'changed inputs require fresh dry run')
                dialog.get_by_role('button', name='시험 실행', exact=True).click()
                expect(dialog.get_by_role('button', name='켜기', exact=True)).to_be_enabled(timeout=15000)
                dialog.get_by_role('button', name='켜기', exact=True).click()
                expect(dialog).not_to_be_visible()
                after = api('GET', '/api/automations')['automations']
                check(len(after) == 1 and after[0]['is_enabled'], 'edited dry run reuses rule and enable persists')
                # Repeated Save must update the same rule, including before modal is closed.
                if page.locator('#page-more').get_attribute('open') is None:
                    page.locator('#page-more > summary').click()
                page.get_by_role('button', name='상태 알림', exact=True).click()
                fixed = page.get_by_role('dialog', name='고정 알림', exact=True)
                on = fixed.get_by_label('검토 상태가 되면 담당자에게 알림', exact=True); expect(on).to_be_visible()
                on.check()
                save = fixed.get_by_role('button', name='검토 상태가 되면 담당자에게 알림 저장', exact=True)
                save.click(); expect(fixed.get_by_role('status')).to_have_text('저장했습니다.')
                on.uncheck(); save.click(); expect(fixed.get_by_role('status')).to_have_text('저장했습니다.')
                rules = [r for r in api('GET', '/api/automations')['automations'] if r['kind'] == 'on_status']
                check(len(rules) == 1 and not rules[0]['is_enabled'], 'repeated fixed-rule save updates returned ID, no duplicate POST')
                fixed.get_by_role('button', name='닫기', exact=True).click()
            else:
                page.keyboard.press('Escape'); expect(dialog).not_to_be_visible()
            check(not errors, 'no unhandled browser error ' + str(width))
            ctx.close()
        template = api('POST', '/api/templates', {'page_id': pid, 'name': '월마감 반복 템플릿 검증', 'scope': 'personal'})['template']
        ctx = browser.new_context(viewport={'width': 390, 'height': 900}, locale='ko-KR', color_scheme='dark', user_agent=headers['User-Agent'])
        ctx.add_cookies([{'name': '__Host-tbox_session', 'value': user['session'], 'domain': host, 'path': '/', 'secure': True, 'httpOnly': True, 'sameSite': 'Lax'}])
        page = ctx.new_page(); errors = []
        page.on('pageerror', lambda error: errors.append(str(error)))
        page.goto(site + '/community/?template=' + template['id'], wait_until='networkidle')
        repeat = page.get_by_role('button', name='이 템플릿으로 반복 만들기', exact=True)
        expect(repeat).to_be_visible(); repeat.click()
        dialog = page.get_by_role('dialog', name='반복·버튼 작업 만들기', exact=True)
        expect(dialog).to_be_visible()
        expect(dialog.get_by_role('button', name='시험 실행', exact=True)).to_be_enabled(timeout=10000)
        check(dialog.get_by_label('작업', exact=True).locator('option').count() == 1, 'template has recurring option and no unbound button')
        check(dialog.get_by_label('만들 자리', exact=True).locator('option').count() > 1, 'template parent picker loads writable pages from spaces')
        dialog.get_by_role('button', name='시험 실행', exact=True).click()
        expect(dialog.get_by_role('button', name='켜기', exact=True)).to_be_enabled(timeout=15000)
        check('만들 문서:' in dialog.inner_text(), 'personal template dry run previews real new page')
        page.screenshot(path=str(output / 'automation-template-390-dark.png'), full_page=True)
        dialog.get_by_role('button', name='켜기', exact=True).click(); expect(dialog).not_to_be_visible()
        page.goto(site + '/community/automations', wait_until='networkidle')
        expect(page.get_by_text('월마감 반복 템플릿 검증 반복', exact=True)).to_be_visible()
        check('다음 실행' in page.locator('main').inner_text(), 'enabled template lists next execution')
        check(not errors, 'template entry and automation list have no unhandled errors')
        page.goto(site + '/community/', wait_until='networkidle')
        page.get_by_role('button', name='공간 상태 알림', exact=True).click()
        fixed = page.get_by_role('dialog', name='공간 상태 알림', exact=True)
        on = fixed.get_by_label('기한 하루 전 담당자에게 알림', exact=True)
        expect(on).to_be_visible(); on.check()
        fixed.get_by_role('button', name='기한 하루 전 담당자에게 알림 저장', exact=True).click()
        expect(fixed.get_by_role('status')).to_have_text('저장했습니다.')
        rules = [r for r in api('GET', '/api/automations')['automations'] if r['kind'] == 'due_reminder']
        check(len(rules) == 1 and rules[0]['scope_space_id'] and not rules[0]['scope_page_id'], 'space notification saves a space-scoped rule')
        fixed.get_by_role('button', name='닫기', exact=True).click()
        button_rule = api('POST', '/api/automations', {'kind': 'button', 'name': 'UI 버튼 검증', 'scope_page_id': pid,
            'trigger': {'type': 'button'}, 'press_scope': 'owner', 'actions': [
                {'type': 'create_page', 'source': {'page': pid}, 'parent': pid, 'title_pattern': '단추로 만든 {YYYY-MM}'}]})['automation']
        api('POST', '/api/automations/' + button_rule['id'] + '/dry-run', {})
        api('PATCH', '/api/automations/' + button_rule['id'], {'is_enabled': True})
        page.goto(site + '/community/p/' + pid, wait_until='networkidle')
        page.locator('#page-edit').click()
        page.locator('#page-add-block').click()
        page.locator('#block-list .block-pick').filter(has_text='버튼').first.click()
        page.locator('#block-insert').click()
        atom = page.locator('#atom-dialog'); expect(atom).to_be_visible()
        atom.get_by_label('단추 문구', exact=True).fill('이번 달 정산 시작')
        atom.get_by_label('이 페이지에 묶인 내 버튼 작업', exact=True).select_option(button_rule['id'])
        atom.locator('#atom-commit').click()
        expect(page.locator('#page-save-state')).to_have_text('저장됨', timeout=15000)
        doc = api('GET', '/api/pages/' + pid)['page']['doc']
        check(any(b['type'] == 'button' and b['automation_id'] == button_rule['id'] and b['label'] == '이번 달 정산 시작' for b in doc['blocks']), 'button editor serializes label and binding to actual saved document')
        page.locator('#page-done').click()
        press = page.locator('#page-doc').get_by_role('button', name='이번 달 정산 시작', exact=True)
        expect(press).to_be_enabled(); page.screenshot(path=str(output / 'automation-button-390-dark.png'), full_page=True)
        press.click(); page.wait_for_url(lambda url: '/community/p/' in url and not url.endswith(pid))
        created = api('GET', '/api/pages/' + page.url.rsplit('/', 1)[-1])
        check(created['page']['title'].startswith('단추로 만든'), 'read-mode button invokes actual executor and opens created page')
        check(not errors, 'space alert and button editor have no unhandled errors')
        page.goto(site + '/community/p/' + pid, wait_until='networkidle')
        page.locator('#page-more > summary').click()
        page.get_by_role('button', name='이 문서 확인함', exact=True).click()
        trust = page.get_by_role('dialog', name='이 문서 확인함', exact=True)
        expect(trust.get_by_label('검증 유효기간', exact=True)).to_have_value('90')
        trust.get_by_role('button', name='확인함', exact=True).click(); expect(trust).not_to_be_visible()
        check(api('GET', '/api/pages/' + pid)['verify']['state'] == 'verified', 'verification dialog saves default 90-day trust through real API')
        page.goto(site + '/community/procedures', wait_until='networkidle')
        page.get_by_label('검증 상태', exact=True).select_option('verified')
        expect(page.locator('main').get_by_role('link', name='월마감 절차서 UI 검증', exact=False)).to_be_visible()
        check('검증됨' in page.locator('main').inner_text(), 'procedure collection filters verified procedure')
        dbpage = api('POST', '/api/pages', {'title': '반복 행 검증', 'database': True})['page']['id']
        rowid = api('POST', '/api/pages', {'title': '월마감 행', 'parent_id': dbpage})['page']['id']
        rowtemplate = api('POST', '/api/templates', {'page_id': rowid, 'name': '정산 행 템플릿', 'scope': 'database'})['template']
        page.goto(site + '/community/p/' + dbpage, wait_until='networkidle')
        page.locator('#db-add-row-template').select_option(rowtemplate['id'])
        page.get_by_role('button', name='행 템플릿으로 반복 만들기', exact=True).click()
        dialog = page.get_by_role('dialog', name='반복·버튼 작업 만들기', exact=True)
        check(dialog.get_by_label('만들 자리', exact=True).inner_text() == '이 템플릿의 데이터베이스', 'row template repeat remains bound to its database')
        dialog.get_by_role('button', name='시험 실행', exact=True).click()
        expect(dialog.get_by_role('button', name='켜기', exact=True)).to_be_enabled(timeout=15000)
        check('만들 문서:' in dialog.inner_text(), 'database template actual dry run succeeds from row picker')
        dialog.get_by_role('button', name='켜기', exact=True).click(); expect(dialog).not_to_be_visible()
        private = api('POST', '/api/templates', {'page_id': pid, 'name': '주인 전용 원본', 'scope': 'personal'})['template']
        denied_rule = api('POST', '/api/automations', {'kind': 'button', 'name': '권한 거부 검증', 'scope_page_id': pid,
            'trigger': {'type': 'button'}, 'press_scope': 'editors', 'actions': [
                {'type': 'create_page', 'source': {'template': private['id']}, 'parent': pid, 'title_pattern': '만들면 안 되는 문서'}]})['automation']
        api('POST', '/api/automations/' + denied_rule['id'] + '/dry-run', {})
        api('PATCH', '/api/automations/' + denied_rule['id'], {'is_enabled': True})
        before = api('GET', '/api/pages/' + pid)['page']
        api('PATCH', '/api/pages/' + pid, {'doc_version': before['doc_version'], 'doc': {'schema': 1, 'blocks': [
            {'id': str(uuid.uuid4()), 'type': 'button', 'label': '비공개 원본 실행', 'automation_id': denied_rule['id']} ]}})
        api('PUT', '/api/pages/' + pid + '/shares/claude_employee02', {'level': 'edit'})
        other = data['users']['other']
        denied_ctx = browser.new_context(viewport={'width': 390, 'height': 900}, locale='ko-KR', user_agent=headers['User-Agent'])
        denied_ctx.add_cookies([{'name': '__Host-tbox_session', 'value': other['session'], 'domain': host, 'path': '/', 'secure': True, 'httpOnly': True, 'sameSite': 'Lax'}])
        denied_page = denied_ctx.new_page()
        denied_page.goto(site + '/community/p/' + pid, wait_until='networkidle')
        denied_page.get_by_role('button', name='비공개 원본 실행', exact=True).click()
        expect(denied_page.locator('#msg')).to_have_text('작업을 실행하지 못했습니다. 원본과 현재 권한을 확인해 주세요.')
        check(denied_page.url.endswith(pid) and '실행했습니다.' not in denied_page.locator('#msg').inner_text(), 'HTTP200 denied shows failure and never navigates or claims success')
        denied_page.screenshot(path=str(output / 'automation-denied-390.png')), denied_ctx.close()
        check(not errors, 'database repeat has no unhandled browser errors')
        ctx.close()
        browser.close()
finally:
    (output / 'automation-ui-results.json').write_text(json.dumps(checks, ensure_ascii=False, indent=2))
