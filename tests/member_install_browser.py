#!/usr/bin/env python3
"""Real install UI/JS with synthetic browser install events; no actual installation.

This fixture only substitutes the partial's constant community URL. It does not
execute PHP, prove browser install eligibility, or test a physical phone/Windows.
"""
import os
import re
from pathlib import Path
from urllib.parse import urlparse

from playwright.sync_api import sync_playwright, expect

ROOT = Path(__file__).resolve().parents[1]
ORIGIN = 'https://moa.test'
PARTIAL = (ROOT / 'src/views/member_install.php').read_text()
PARTIAL = PARTIAL.replace("<?= Http::communityUrl('/') ?>", '/community/')
PARTIAL = re.sub(r'<\?php declare\(strict_types=1\); \?>', '', PARTIAL)
assert '<?' not in PARTIAL, 'Update explicit view fixture for new PHP output.'
checks = []
errors = []
requests = []


def check(label, ok=True):
    assert ok, label
    checks.append(label)
    print('PASS', label, flush=True)


def route(request):
    path = urlparse(request.request.url).path
    if path == '/welcome':
        css = ''.join(f'<link rel="stylesheet" href="/assets/{name}.css">' for name in ['app', 'design', 'moa'])
        request.fulfill(content_type='text/html', body=f'<!doctype html><html lang="ko" data-design="moa" data-theme="dark"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">{css}</head><body class="moa-site"><main class="moa-member-main"><section class="moa-setup-card">{PARTIAL}</section></main><script src="/assets/member-install.js"></script></body></html>')
    elif path.startswith('/assets/') and (ROOT / 'public' / path.lstrip('/')).is_file():
        request.fulfill(path=str(ROOT / 'public' / path.lstrip('/')))
    else:
        requests.append(path)
        request.abort()


with sync_playwright() as pw:
    browser = pw.chromium.launch(executable_path=os.environ.get('CHROMIUM_EXECUTABLE', '/usr/bin/chromium'), args=['--no-sandbox'])

    def setup(ua='Mozilla/5.0 (Linux; Android 15) Chrome/140.0 Mobile', standalone=False):
        context = browser.new_context(viewport={'width': 390, 'height': 844}, user_agent=ua)
        context.route('**/*', route)
        if standalone:
            context.add_init_script('Object.defineProperty(navigator, "standalone", {value: true});')
        page = context.new_page()
        page.on('pageerror', lambda error: errors.append(str(error)))
        page.goto(ORIGIN + '/welcome')
        return context, page

    def prompt(page, outcome='accepted', fails=False):
        page.evaluate('''({outcome, fails}) => {
          const event = new Event('beforeinstallprompt', {cancelable:true});
          window.installCalls = 0;
          event.prompt = async () => { window.installCalls++; if (fails) throw new Error('unavailable'); };
          event.userChoice = Promise.resolve({outcome});
          window.dispatchEvent(event);
        }''', {'outcome': outcome, 'fails': fails})

    ctx, page = setup()
    expect(page.locator('#member-install-help')).to_be_hidden()
    check('No installation or connection token is required to start', page.locator('a[href="/community/"]').count() == 1)
    page.locator('#member-install-start').click()
    expect(page.locator('#member-install-android')).to_be_visible()
    expect(page.locator('#member-install-device')).to_be_focused()
    page.locator('#member-install-device').select_option('ios')
    expect(page.locator('#member-install-ios')).to_be_visible()
    expect(page.locator('#member-install-android')).to_be_hidden()
    check('Unavailable native prompt has accessible device-specific fallback')
    check('Mobile install guide stays within viewport', page.evaluate('document.documentElement.scrollWidth <= innerWidth'))
    ctx.close()

    for ua, device in [('Mozilla/5.0 (iPhone; CPU iPhone OS 18_0) Safari/605.1', 'ios'), ('Mozilla/5.0 (Windows NT 10.0) Chrome/140.0', 'desktop')]:
        ctx, page = setup(ua)
        page.locator('#member-install-start').click()
        expect(page.locator('#member-install-device')).to_have_value(device)
        expect(page.locator('#member-install-' + device)).to_be_visible()
        check(device + ' installation instructions selected from device')
        ctx.close()

    ctx, page = setup('Mozilla/5.0 (Linux; Android 15) KAKAOTALK 12.0')
    page.locator('#member-install-start').click()
    expect(page.locator('#member-install-browser-note')).to_be_visible()
    check('In-app browser explains opening supported browser without forced navigation')
    ctx.close()

    ctx, page = setup()
    prompt(page)
    expect(page.locator('#member-install-start')).to_have_text('모아 앱 설치하기')
    check('Native prompt is deferred until user clicks', page.evaluate('window.installCalls') == 0)
    page.locator('#member-install-start').click()
    expect(page.locator('#member-install-status')).to_contain_text('설치를 요청했어요')
    check('Accepted prompt is not incorrectly reported as completed installation', '설치되어' not in page.locator('#member-install-status').inner_text())
    page.evaluate("window.dispatchEvent(new Event('appinstalled'))")
    expect(page.locator('#member-install-status')).to_contain_text('설치되어 있어요')
    expect(page.locator('#member-install-start')).to_have_text('다른 기기 설치 안내')
    check('Installation completion waits for browser event')
    check('Install creates no tokens or persistent credentials', page.evaluate('localStorage.length === 0 && sessionStorage.length === 0') and not requests)
    ctx.close()

    ctx, page = setup()
    prompt(page, 'dismissed')
    page.locator('#member-install-start').click()
    expect(page.locator('#member-install-status')).to_contain_text('건너뛰었어요')
    expect(page.locator('#member-install-start')).to_be_enabled()
    page.locator('#member-install-start').click()
    expect(page.locator('#member-install-help')).to_be_visible()
    check('Dismissed installation can continue in browser or reopen guide')
    ctx.close()

    ctx, page = setup()
    prompt(page, fails=True)
    page.locator('#member-install-start').click()
    expect(page.locator('#member-install-status')).to_contain_text('설치 창을 열지 못했어요')
    expect(page.locator('#member-install-help')).to_be_visible()
    expect(page.locator('#member-install-start')).to_be_enabled()
    check('Failed install prompt recovers to instructions')
    ctx.close()

    ctx, page = setup(standalone=True)
    expect(page.locator('#member-install-status')).to_contain_text('설치되어 있어요')
    page.locator('#member-install-start').click()
    expect(page.locator('#member-install-help')).to_be_visible()
    check('Standalone app shows existing installation and other-device guidance')
    ctx.close()
    check('No browser runtime errors', not errors)
    browser.close()

print(f'RESULT {len(checks)} passed; native install, PHP and physical devices remain unverified')
