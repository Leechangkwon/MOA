#!/usr/bin/env python3
"""Browser checks of real MCP UI markup/JS/CSS with synthetic API responses.

PHP is not executed: the connect partial's two constant Http::url outputs and
the shared member shell are rendered explicitly below. Tests use the real
member.js session/CSRF helper. This is not OAuth/DB/ChatGPT or handset proof.
Screenshots contain only synthetic accounts and public example URLs.
"""
import copy
import json
import os
from datetime import datetime, timedelta, timezone
from pathlib import Path
from urllib.parse import parse_qs, urlparse

from playwright.sync_api import expect, sync_playwright

ROOT = Path(__file__).resolve().parents[2]
ORIGIN = 'https://moa.test'
PENDING = 'P' * 43
CSRF = 'synthetic-csrf-for-ui-test'
GRANT = '11111111-1111-4111-8111-111111111111'
SCOPES = ['memories.read', 'photos.read', 'memories.write', 'travel.read', 'travel.write']
SCREENSHOTS = Path(os.environ.get('MCP_UI_SCREENSHOTS', '/tmp/qaredi-remote-mcp-ui'))
SCREENSHOTS.mkdir(parents=True, exist_ok=True)
PARTIAL = (ROOT / 'src/mcp/handlers/connect_page.php').read_text().split('?>', 1)[1]
PARTIAL = PARTIAL.replace("<?= Http::url('/login') ?>?to=<?= rawurlencode(Http::url('/mcp-connect')) ?>", '/tbox/login?to=%2Ftbox%2Fmcp-connect')
PARTIAL = PARTIAL.replace("<?= Http::url('/settings') ?>", '/tbox/settings')
assert '<?' not in PARTIAL, 'Update explicit PHP partial substitutions.'
checks = []
errors = []
stalled_routes = {}


def check(label, condition=True):
    assert condition, label
    checks.append(label)
    print('PASS', label, flush=True)


def timestamp(delta=0):
    return (datetime.now(timezone.utc) + timedelta(seconds=delta)).isoformat()


def close_context(context):
    # Finish intentionally held routes before closing Playwright's event loop.
    for request in stalled_routes.pop(context, []):
        request.abort('aborted')
    context.close()


def payload(pending=False):
    return {
        'enabled': True, 'configured': True, 'status': 'ready',
        'resource': ORIGIN + '/mcp', 'issuer': ORIGIN,
        'metadata': {'protected_resource_url': ORIGIN + '/.well-known/oauth-protected-resource/mcp',
                     'authorization_server_url': ORIGIN + '/.well-known/oauth-authorization-server'},
        'user': {'username': 'synthetic_member', 'display_name': '합성 회원'},
        'csrf_token': CSRF, 'available_scopes': SCOPES,
        'connections': [{'id': GRANT, 'client_name': '합성 ChatGPT 연결', 'scopes': ['memories.read'],
                         'created_at': timestamp(-600), 'expires_at': timestamp(86400), 'last_used_at': None}],
        'pending': {'id': PENDING, 'client_name': '합성 ChatGPT', 'requested_scopes': SCOPES,
                    'expires_at': timestamp(600)} if pending else None,
    }


with sync_playwright() as pw:
    browser = pw.chromium.launch(executable_path=os.environ.get('CHROMIUM_EXECUTABLE', '/usr/bin/chromium'), args=['--no-sandbox'])

    def setup(data=None, pending=False, width=390, theme='dark', get_status=200,
              get_error=None, delete_status=200, post_status=200, redirect=None, clipboard=True, me_status=200, stall=None):
        data = copy.deepcopy(data if data is not None else payload(pending))
        writes = []
        reads = []
        ctx = browser.new_context(viewport={'width': width, 'height': 844}, is_mobile=width < 600, has_touch=width < 600)
        stalled_routes[ctx] = []
        ctx.add_init_script('''window.copied = []; Object.defineProperty(navigator, 'clipboard', {value: {writeText: async value => {if(!''' + json.dumps(clipboard) + ''') throw Error('blocked'); window.copied.push(value);}}});''')

        def route(request):
            url = urlparse(request.request.url)
            path = url.path
            method = request.request.method
            if path == '/tbox/mcp-connect':
                css = ''.join(f'<link rel="stylesheet" href="/tbox/assets/{name}.css">' for name in ['app', 'design', 'moa', 'mcp-connect'])
                header = '<header class="moa-nav"><a class="moa-logo" href="/">모아</a><nav aria-label="주 메뉴"><a href="/">둘러보기</a><a href="/community/">커뮤니티</a><a href="/tbox/messages">메신저</a><a href="/tbox/settings">내 계정</a></nav></header>'
                html = f'<!doctype html><html lang="ko" data-design="moa" data-theme="{theme}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">{css}<script defer src="/tbox/assets/mcp-connect.js"></script><script defer src="/tbox/assets/member.js"></script></head><body class="moa-site" data-member-page="mcp-connect" data-base="/tbox">{header}<main id="member-main" class="moa-member-main">{PARTIAL}'
                request.fulfill(content_type='text/html', body=html)
            elif path.startswith('/tbox/assets/'):
                file = ROOT / 'public/assets' / path.removeprefix('/tbox/assets/')
                assert file.is_file(), path
                request.fulfill(path=str(file))
            elif path == '/tbox/api/me':
                if path == stall:
                    stalled_routes[ctx].append(request)
                    return
                request.fulfill(status=me_status, json={**data['user'], 'csrf_token': CSRF, 'next': None} if me_status == 200 else {'error': '로그인이 만료되었습니다.'})
            elif path == '/tbox/api/mcp/connections' and method == 'GET':
                reads.append(parse_qs(url.query))
                if path == stall:
                    stalled_routes[ctx].append(request)
                    return
                request.fulfill(status=get_status, json=get_error or data)
            elif path == '/tbox/api/mcp/connections/' + GRANT and method == 'DELETE':
                writes.append({'method': method, 'path': path, 'body': request.request.post_data_json, 'csrf': request.request.headers.get('x-csrf-token')})
                request.fulfill(status=delete_status, json={'ok': True} if delete_status == 200 else {'error': '연결을 해제할 수 없습니다.'})
            elif path == '/tbox/oauth/authorize' and method == 'POST':
                writes.append({'method': method, 'path': path, 'body': request.request.post_data_json, 'csrf': request.request.headers.get('x-csrf-token')})
                if path == stall:
                    stalled_routes[ctx].append(request)
                    return
                request.fulfill(status=post_status, json={'redirect_url': redirect or 'https://chatgpt.test/callback?code=synthetic-single-use-code&state=synthetic'} if post_status == 200 else {'error': '승인 요청이 만료되었습니다.'})
            elif url.hostname == 'chatgpt.test':
                request.fulfill(content_type='text/html', body='Synthetic callback received')
            else:
                raise AssertionError('Unexpected request: ' + path)

        ctx.route('**/*', route)
        page = ctx.new_page()
        if stall:
            page.clock.install()
        page.on('pageerror', lambda value: errors.append(str(value)))
        address = ORIGIN + '/tbox/mcp-connect' + ('?pending=' + PENDING if pending else '')
        if stall and stall != '/tbox/oauth/authorize':
            with page.expect_request(ORIGIN + stall):
                page.goto(address)
        else:
            page.goto(address)
        if stall and stall != '/tbox/oauth/authorize':
            pass
        elif me_status == 200:
            expect(page.locator('#mcp-refresh')).to_be_enabled()
        else:
            expect(page.locator('#mcp-readiness')).to_have_text('확인 실패')
        return ctx, page, writes, reads

    for width, theme in [(390, 'dark'), (1280, 'light')]:
        ctx, page, writes, reads = setup(pending=True, width=width, theme=theme)
        expect(page.locator('#mcp-account')).to_have_text('합성 회원 (synthetic_member)')
        expect(page.locator('#mcp-readiness')).to_have_text('준비됨')
        expect(page.locator('#mcp-consent')).to_be_visible()
        expect(page.locator('#mcp-scope-memories-read')).to_be_checked()
        for scope in SCOPES[1:]:
            expect(page.locator('#mcp-scope-' + scope.replace('.', '-'))).not_to_be_checked()
        check(f'{width}/{theme}: own account and read-only default; photo/write/travel unchecked')
        check(f'{width}/{theme}: no writes on load', not writes)
        check(f'{width}/{theme}: no horizontal overflow', page.evaluate('document.documentElement.scrollWidth <= innerWidth'))
        check(f'{width}/{theme}: no duplicate IDs', page.evaluate('new Set([...document.querySelectorAll("[id]")].map(e => e.id)).size === document.querySelectorAll("[id]").length'))
        check(f'{width}/{theme}: all checkbox inputs have labels', page.locator('input[type=checkbox]').evaluate_all('(items) => items.every(e => e.labels.length > 0)'))
        if width == 390:
            check('Mobile checkbox labels provide at least 44px touch height', page.locator('#mcp-scopes label').evaluate_all('(items) => items.every(e => e.getBoundingClientRect().height >= 44)'))
        check(f'{width}/{theme}: no secrets or consent in persistent storage', page.evaluate('localStorage.length === 0 && sessionStorage.length === 0'))
        page.screenshot(path=str(SCREENSHOTS / f'connect-{width}-{theme}.png'), full_page=True)
        page.locator('#mcp-copy').click()
        check(f'{width}/{theme}: clipboard contains only configured public endpoint', page.evaluate('window.copied') == [ORIGIN + '/mcp'])
        expect(page.locator('#mcp-resource-metadata')).to_have_attribute('href', ORIGIN + '/.well-known/oauth-protected-resource/mcp')
        expect(page.locator('#mcp-oauth-metadata')).to_have_attribute('href', ORIGIN + '/.well-known/oauth-authorization-server')
        check(f'{width}/{theme}: metadata addresses shown as unverified external reachability')
        page.locator('#mcp-scope-travel-write').check()
        expect(page.locator('#mcp-scope-travel-read')).to_be_checked()
        expect(page.locator('#mcp-write-note')).to_be_visible()
        page.locator('#mcp-scope-travel-read').uncheck()
        expect(page.locator('#mcp-scope-travel-write')).not_to_be_checked()
        expect(page.locator('#mcp-write-note')).to_be_hidden()
        check(f'{width}/{theme}: travel scope dependency and write warning')
        if width == 390:
            page.locator('#mcp-scope-photos-read').check()
            page.locator('#mcp-scope-memories-write').check()
            page.locator('#mcp-approve').click()
            page.wait_for_url('https://chatgpt.test/**')
            check('Explicit approval carries selected scopes and CSRF via existing helper', writes == [{'method': 'POST', 'path': '/tbox/oauth/authorize', 'body': {'pending': PENDING, 'approve': True, 'scopes': ['memories.read', 'photos.read', 'memories.write']}, 'csrf': CSRF}])
        close_context(ctx)

    for status in ['disabled', 'unconfigured']:
        data = payload()
        data.update(enabled=False, configured=status == 'disabled', status=status, resource='' if status == 'unconfigured' else ORIGIN + '/mcp')
        ctx, page, writes, _ = setup(data=data)
        expect(page.locator('#mcp-readiness')).to_have_text('기능 꺼짐' if status == 'disabled' else '서버 설정 대기')
        expect(page.locator('#mcp-copy')).to_be_disabled()
        expect(page.locator('#mcp-consent')).to_be_hidden()
        check(status + ': unavailable setup cannot be copied/approved and still lists grants', page.locator('#mcp-connections > li').count() == 1 and not writes)
        close_context(ctx)

    ctx, page, writes, _ = setup(pending=True)
    page.locator('#mcp-deny').click()
    page.wait_for_url('https://chatgpt.test/**')
    check('Denial is explicit and sends no scopes', writes[0]['body'] == {'pending': PENDING, 'approve': False, 'scopes': []} and writes[0]['csrf'] == CSRF)
    close_context(ctx)

    ctx, page, writes, _ = setup(pending=True)
    page.locator('#mcp-approve').click()
    page.wait_for_url('https://chatgpt.test/**')
    check('Default approval grants only reading', writes[0]['body']['scopes'] == ['memories.read'])
    close_context(ctx)

    ctx, page, writes, reads = setup(me_status=401)
    expect(page.locator('#mcp-login-help')).to_be_visible()
    expect(page.locator('#mcp-copy')).to_be_disabled()
    expect(page.locator('#mcp-login-help a')).to_have_attribute('href', '/tbox/login?to=%2Ftbox%2Fmcp-connect')
    check('Expired initial session gives a usable login path with no API mutations', not writes and not reads)
    close_context(ctx)

    ctx, page, writes, _ = setup()
    page.get_by_role('button', name='합성 ChatGPT 연결 연결 해제하기').click()
    expect(page.get_by_role('button', name='취소하기')).to_be_focused()
    check('Revoke requires confirmation; opening does not write', not writes)
    page.keyboard.press('Escape')
    expect(page.get_by_role('button', name='합성 ChatGPT 연결 연결 해제하기')).to_be_focused()
    check('Escape cancels revocation and restores focus', not writes)
    page.get_by_role('button', name='합성 ChatGPT 연결 연결 해제하기').click()
    page.get_by_role('button', name='이 연결 해제하기', exact=True).click()
    expect(page.locator('#mcp-connections-empty')).to_be_visible()
    expect(page.locator('#mcp-refresh')).to_be_focused()
    check('Revoke updates grant list with CSRF and preserves content', writes == [{'method': 'DELETE', 'path': '/tbox/api/mcp/connections/' + GRANT, 'body': {}, 'csrf': CSRF}])
    close_context(ctx)

    ctx, page, writes, _ = setup(delete_status=403)
    page.get_by_role('button', name='합성 ChatGPT 연결 연결 해제하기').click()
    page.get_by_role('button', name='이 연결 해제하기', exact=True).click()
    expect(page.locator('#mcp-error')).to_have_text('연결을 해제할 수 없습니다.')
    check('Failed revoke keeps connection visible', page.locator('#mcp-connections > li').count() == 1)
    close_context(ctx)

    for status in [401, 403, 503]:
        ctx, page, writes, _ = setup(get_status=status, get_error={'error': '합성 오류 <img src=x onerror=alert(1)>'})
        expect(page.locator('#mcp-error')).to_have_text('합성 오류 <img src=x onerror=alert(1)>')
        expect(page.locator('#mcp-copy')).to_be_disabled()
        expect(page.locator('#mcp-readiness')).to_have_text('확인 실패')
        check(f'{status} error remains text and cannot enable consent', page.locator('#mcp-error img').count() == 0 and not writes)
        close_context(ctx)

    data = payload(True)
    data['pending']['expires_at'] = timestamp(-1)
    ctx, page, writes, _ = setup(data=data, pending=True)
    expect(page.locator('#mcp-approve')).to_be_disabled()
    expect(page.locator('#mcp-error')).to_contain_text('유효기간')
    check('Expired consent cannot be submitted', not writes)
    close_context(ctx)

    data = payload(True)
    data['pending']['requested_scopes'] = ['memories.read']
    data['pending']['client_name'] = '<img src=x onerror=alert(1)>'
    ctx, page, writes, _ = setup(data=data, pending=True)
    check('Consent offers only requested scopes; client name is text', page.locator('#mcp-scopes input').count() == 1 and page.locator('#mcp-client img').count() == 0)
    close_context(ctx)

    ctx, page, writes, _ = setup(pending=True, post_status=400)
    page.locator('#mcp-approve').click()
    expect(page.locator('#mcp-error')).to_have_text('승인 요청이 만료되었습니다.')
    expect(page.locator('#mcp-approve')).to_be_disabled()
    check('Consent failure is not automatically replayed', len(writes) == 1)
    close_context(ctx)

    ctx, page, writes, _ = setup(pending=True, redirect='javascript:alert(1)')
    page.locator('#mcp-approve').click()
    expect(page.locator('#mcp-error')).to_contain_text('이동 주소')
    check('Unsafe callback scheme is never followed', page.url.startswith(ORIGIN) and len(writes) == 1)
    close_context(ctx)

    data = payload()
    data['resource'] = ORIGIN + '/mcp?token=not-a-real-token'
    ctx, page, writes, _ = setup(data=data)
    expect(page.locator('#mcp-copy')).to_be_disabled()
    expect(page.locator('#mcp-resource')).to_have_value('')
    check('Endpoint with a credential-like query cannot enter the clipboard')
    close_context(ctx)

    ctx, page, writes, _ = setup(clipboard=False)
    page.locator('#mcp-copy').click()
    expect(page.locator('#member-status')).to_contain_text('직접 복사')
    expect(page.locator('#mcp-resource')).to_be_focused()
    check('Clipboard permission failure offers manual selection')
    close_context(ctx)

    for code in ['invalid_request', 'invalid_scope', 'temporarily_unavailable', 'access_denied', 'login_required', 'not_found']:
        ctx, page, writes, _ = setup(get_status=400, get_error={'error': code})
        expect(page.locator('#mcp-error')).to_be_visible()
        check('OAuth error translated: ' + code, code not in page.locator('#mcp-error').inner_text())
        close_context(ctx)

    for path in ['/tbox/api/me', '/tbox/api/mcp/connections']:
        ctx, page, writes, reads = setup(stall=path)
        page.clock.fast_forward(31000)
        expect(page.locator('#mcp-error')).to_contain_text('응답 시간이 지났습니다')
        expect(page.locator('#mcp-copy')).to_be_disabled()
        expect(page.locator('#mcp-refresh')).to_be_enabled()
        check('Stalled ' + path + ' times out safely with no writes', not writes)
        close_context(ctx)

    ctx, page, writes, _ = setup(pending=True, stall='/tbox/oauth/authorize')
    with page.expect_request('**/oauth/authorize'):
        page.locator('#mcp-approve').click()
    page.clock.fast_forward(31000)
    expect(page.locator('#mcp-error')).to_contain_text('응답 시간이 지났습니다')
    expect(page.locator('#mcp-approve')).to_be_disabled()
    expect(page.locator('#mcp-refresh')).to_be_enabled()
    page.clock.fast_forward(60000)
    check('Stalled consent unlocks recovery but cannot be automatically replayed', len(writes) == 1)
    close_context(ctx)

    check('No browser JS errors', not errors)
    browser.close()

print(f'{len(checks)} passed; synthetic partial/API only. PHP/DB/OAuth/ChatGPT/physical phones UNVERIFIED.')
print('Screenshots:', SCREENSHOTS)
