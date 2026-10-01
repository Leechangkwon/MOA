#!/usr/bin/env python3
"""Actual Today UI: responsive layout, original-document check, stale save rejection."""
import json
import os
from pathlib import Path
import time
from urllib.parse import urlsplit
from playwright.sync_api import sync_playwright, expect

manifest = json.loads(Path(os.environ['COMMUNITY_EXPORT_MANIFEST']).read_text())
assert manifest['database'].startswith('tbox_export_test_')
data = json.loads(Path(manifest['credentials_file']).read_text())
output = Path(os.environ['COMMUNITY_EXPORT_OUTPUT'])
ids = json.loads((output / 'fixture-ids.json').read_text())
site = manifest['base_url'].removesuffix('/tbox')
host = urlsplit(site).hostname
checks = []


def check(ok, name):
    checks.append({'pass': bool(ok), 'name': name})
    print(('PASS ' if ok else 'FAIL ') + name, flush=True)
    if not ok:
        raise AssertionError(name)


try:
    with sync_playwright() as p:
        browser = p.chromium.launch()
        for width, theme in [(1280, 'light'), (390, 'light'), (390, 'dark'), (360, 'light'), (820, 'dark')]:
            ctx = browser.new_context(viewport={'width': width, 'height': 900}, locale='ko-KR', color_scheme=theme,
                                      user_agent='tbox-review/' + time.strftime('%Y-%m-%d'))
            ctx.add_cookies([{'name': '__Host-tbox_session', 'value': data['users']['employee']['session'],
                              'domain': host, 'path': '/', 'secure': True, 'httpOnly': True, 'sameSite': 'Lax'},
                             {'name': 'tbox_theme', 'value': theme, 'domain': host, 'path': '/', 'sameSite': 'Lax'}])
            page = ctx.new_page()
            errors, writes = [], []
            page.on('pageerror', lambda e: errors.append(str(e)))
            page.on('request', lambda r: writes.append(urlsplit(r.url).path) if r.method not in ('GET', 'HEAD') else None)
            page.goto(site + '/community/today', wait_until='networkidle')
            page.locator('#briefing-top a').first.wait_for()
            check(page.locator('#briefing-top a').count() == 3, 'top three rendered ' + str(width) + theme)
            check(not page.locator('#briefing-others-box').get_attribute('open'), 'other tasks initially collapsed ' + str(width) + theme)
            check(page.evaluate('document.documentElement.scrollWidth <= innerWidth'), 'no horizontal overflow ' + str(width) + theme)
            check(not writes, 'viewing Today emits no mutation request ' + str(width) + theme)
            page.screenshot(path=str(output / ('today-' + str(width) + '-' + theme + '.png')), full_page=True)
            if width == 1280:
                page.locator('#briefing-others-box > summary').click()
                control = page.get_by_role('checkbox', name='첨부 근거 확인 완료')
                # Simulate the documented optimistic concurrency response once.
                def stale(route):
                    if route.request.method == 'PATCH':
                        route.fulfill(status=409, content_type='application/json', body='{"error":"conflict"}')
                    else:
                        route.continue_()
                page.route('**/community/api/pages/' + ids['check'], stale)
                control.check()
                expect(page.locator('#briefing-msg')).to_contain_text('다른 곳에서')
                check(not control.is_checked(), '409 reverts checkbox and reloads without overwriting')
                page.unroute('**/community/api/pages/' + ids['check'], stale)
                control.check()
                expect(page.locator('#briefing-msg')).to_contain_text('원본 문서에')
                check(page.get_by_role('checkbox', name='첨부 근거 확인 완료').count() == 0, 'completed check disappears after actual save')
                check(page.locator('#briefing-refresh').evaluate('(e) => e === document.activeElement'), 'focus returns to refresh after removed item')
                check('/community/api/pages/' + ids['check'] in writes, 'checkbox used the ordinary page PATCH API')
            page.locator('#briefing-refresh').click()
            expect(page.locator('#briefing-refresh')).to_be_enabled()
            check(not errors, 'no JS errors ' + str(width) + theme)
            ctx.close()
        browser.close()
finally:
    (output / 'browser-results.json').write_text(json.dumps(checks, ensure_ascii=False, indent=2))
