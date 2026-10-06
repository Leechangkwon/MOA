#!/usr/bin/env python3
"""Q:F-12 — the Today screen moved (B: 308 /community/my-tasks). Browser follows the old address; 업무 sidebar 「내 작업」 (C). Earlier: Today UI checks."""
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
        browser = p.chromium.launch(executable_path=os.environ.get('CHROMIUM_EXECUTABLE'))
        for width, theme in [(1280, 'light'), (390, 'dark')]:
            ctx = browser.new_context(viewport={'width': width, 'height': 900}, locale='ko-KR', color_scheme=theme,
                                      user_agent='tbox-review/' + time.strftime('%Y-%m-%d'))
            ctx.add_cookies([{'name': '__Host-tbox_session', 'value': data['users']['employee']['session'],
                              'domain': host, 'path': '/', 'secure': True, 'httpOnly': True, 'sameSite': 'Lax'},
                             {'name': 'tbox_theme', 'value': theme, 'domain': host, 'path': '/', 'sameSite': 'Lax'}])
            page = ctx.new_page()
            writes = []
            page.on('request', lambda r: writes.append(urlsplit(r.url).path) if r.method not in ('GET', 'HEAD') else None)
            # Q:F-12 (B 308 · C) — 옛 「오늘」 주소는 브라우저에서도 「내 작업」 주소로 넘어간다(그 화면은 D — 여기서는 주소만 본다)
            # D 뒤로 /community/my-tasks 는 실제 업무 화면(editor.js 한 벌)이라 이 틀의 php -S 에서 내 문서 화면처럼 networkidle 에 닿지 않는다 → 「load」(기대값 바꿈)
            page.goto(site + '/community/today', wait_until='load')
            check(urlsplit(page.url).path == '/community/my-tasks', 'old Today address lands on /community/my-tasks ' + str(width) + theme)
            # 업무 사이드바의 「내 작업」이 그 주소이고 「오늘 할 일」 항목은 없다
            # 사이드바는 서버가 그린다 — 「load」 까지만 기다린다(이 틀의 php -S 에서 내 문서 화면은 networkidle 에 닿지 않는다: 기준 판 automation_ui 와 같은 현상)
            page.goto(site + '/community/workspace', wait_until='load')
            link = page.locator('#nav-work-my-tasks')
            check(link.count() == 1 and link.get_attribute('href') == '/community/my-tasks' and '내 작업' in (link.text_content() or ''), '업무 sidebar 「내 작업」 → /community/my-tasks ' + str(width) + theme)
            check(page.locator('#sidebar').get_by_text('오늘 할 일').count() == 0, '업무 sidebar has no 「오늘 할 일」 ' + str(width) + theme)
            check(page.evaluate('document.documentElement.scrollWidth <= innerWidth'), 'no horizontal overflow ' + str(width) + theme)
            check(not writes, 'following the moved address emits no mutation request ' + str(width) + theme)
            page.screenshot(path=str(output / ('moved-today-' + str(width) + '-' + theme + '.png')), full_page=True)
            ctx.close()
        browser.close()
finally:
    (output / 'browser-results.json').write_text(json.dumps(checks, ensure_ascii=False, indent=2))
