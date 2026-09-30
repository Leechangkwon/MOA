"""Local preview only: responsive/theme matrix and real user interactions."""
import json
import sys
import threading
from functools import partial
from http.server import ThreadingHTTPServer, SimpleHTTPRequestHandler
from pathlib import Path
from urllib.parse import urlparse
from playwright.sync_api import sync_playwright, expect

ROOT = Path(__file__).resolve().parents[1]
server = None
if len(sys.argv) > 1:
    BASE = sys.argv[1]
else:
    class QuietHandler(SimpleHTTPRequestHandler):
        def log_message(self, *args):
            pass
    server = ThreadingHTTPServer(('127.0.0.1', 0), partial(QuietHandler, directory=str(ROOT / 'public')))
    threading.Thread(target=server.serve_forever, daemon=True).start()
    BASE = f'http://127.0.0.1:{server.server_port}/assets/design-preview/'
SHOTS = ROOT / 'screenshots'
SHOTS.mkdir(exist_ok=True)
errors, bad_requests, external, failures = [], [], [], []
checks = 0
contrasts = []

def contrast(a, b):
    def luminance(value):
        value = value.strip().lstrip('#')
        rgb = [int(value[i:i + 2], 16) / 255 for i in (0,2,4)]
        linear = [v / 12.92 if v <= 0.04045 else ((v + 0.055) / 1.055) ** 2.4 for v in rgb]
        return sum(v * factor for v,factor in zip(linear,[.2126,.7152,.0722]))
    values = sorted([luminance(a),luminance(b)])
    return (values[1] + .05) / (values[0] + .05)

def check(label, value, detail=''):
    global checks
    checks += 1
    if not value:
        failures.append(label + ': ' + str(detail))
        print('FAIL', label, detail, flush=True)

with sync_playwright() as p:
    browser = p.chromium.launch(headless=True)
    ctx = browser.new_context(viewport={'width':1440,'height':1000}, locale='ko-KR', color_scheme='light', reduced_motion='reduce', user_agent='tbox-review/2026-09-30 astra-preview')
    page = ctx.new_page()
    page.on('pageerror', lambda e: errors.append(str(e)))
    page.on('console', lambda m: errors.append(m.text) if m.type == 'error' else None)
    page.on('response', lambda r: bad_requests.append([r.status,r.url]) if r.status >= 400 else None)
    page.on('request', lambda r: external.append(r.url) if urlparse(r.url).netloc != urlparse(BASE).netloc else None)
    page.goto(BASE, wait_until='networkidle')
    check('fresh browser defaults to Claude', page.locator('html').get_attribute('data-design') == 'claude')
    page.locator('[data-mode="astra"]').click()
    check('Astra switch works', page.locator('html').get_attribute('data-design') == 'astra')
    page.reload()
    check('explicit preview URL survives reload', page.locator('html').get_attribute('data-design') == 'astra')
    page.locator('#save-default').click()
    page.goto(BASE)
    check('saved Astra default survives fresh URL', page.locator('html').get_attribute('data-design') == 'astra')
    page.locator('[data-mode="claude"]').click()
    page.goto(BASE)
    check('temporary switch does not overwrite default', page.locator('html').get_attribute('data-design') == 'astra')
    page.locator('#theme-toggle').click()
    page.reload()
    check('dark persists independently of design', page.locator('html').get_attribute('data-theme') == 'dark' and page.locator('html').get_attribute('data-design') == 'astra')

    for mode in ['claude','astra']:
        page.locator('[data-mode="' + mode + '"]').click()
        for theme in ['light','dark']:
            if page.locator('html').get_attribute('data-theme') != theme:
                page.locator('#theme-toggle').click()
            if mode == 'astra':
                pairs = [['--fg','--surface'],['--muted','--surface'],['--muted','--bg'],['--primary-fg','--primary'],['--accent','--accent-bg'],['--mint-fg','--mint'],['--peach-fg','--peach'],['--ok-fg','--ok-bg'],['--violet-fg','--violet-bg'],['--warn-fg','--warn-bg'],['--danger','--danger-soft']]
                for foreground, background in pairs:
                    colors = page.evaluate('(keys) => keys.map(key => getComputedStyle(document.documentElement).getPropertyValue(key).trim())',[foreground,background])
                    ratio = contrast(*colors)
                    contrasts.append({'theme':theme,'foreground':foreground,'background':background,'ratio':round(ratio,2)})
                    check(f'AA text contrast {theme}/{foreground}/{background}',ratio >= 4.5,round(ratio,2))
            for width in [360,412,820,1280,1440]:
                page.set_viewport_size({'width':width,'height':1000})
                for screen in ['home','files','community','document','components','login','settings']:
                    page.evaluate('(screen) => { location.hash = screen; }',screen)
                    expect(page.locator('#breadcrumb')).to_have_text({'home':'Qaredi 홈','files':'t-box 파일','community':'커뮤니티','document':'문서 읽기','components':'디자인 시스템','login':'로그인 화면','settings':'디자인 설정'}[screen])
                    overflow = page.evaluate('document.documentElement.scrollWidth - innerWidth')
                    check(f'no overflow {mode}/{theme}/{width}/{screen}',overflow == 0,overflow)
                    check(f'one main heading {mode}/{theme}/{width}/{screen}',page.locator('main h1').count() == 1)
                    if screen == 'settings':
                        selected = page.locator('[name="appearance"]').evaluate('(e) => ({value:e.value, text:e.selectedOptions[0]?.textContent, color:getComputedStyle(e).color, root:document.documentElement.dataset.appearance})')
                        check(f'appearance has selected label {mode}/{theme}/{width}', bool(selected.get('text')),selected)
                    if mode == 'astra' and theme == 'light' and width in [412,1440]:
                        page.screenshot(path=str(SHOTS / f'astra-{screen}-{width}.png'),full_page=True,animations='disabled')
                    if width == 1440 and screen == 'home':
                        page.screenshot(path=str(SHOTS / f'{mode}-{theme}-home.png'),full_page=True,animations='disabled')
            print('responsive matrix:',mode,theme,'complete',flush=True)

    page.set_viewport_size({'width':1280,'height':900})
    page.goto(BASE + '?design=astra#files')
    page.locator('#file-search').fill('없는파일')
    expect(page.locator('#file-empty')).to_be_visible()
    check('file search empty state',page.locator('#file-rows tr').count() == 0)
    page.locator('#file-search').fill('브랜드')
    check('file search filters fixtures',page.locator('#file-rows tr').count() == 1)
    page.locator('#file-search').fill('')
    page.locator('.filter-tabs [data-filter="private"]').click()
    check('private filter',page.locator('#file-rows tr').count() == 1)
    page.locator('.filter-tabs [data-filter="all"]').click()
    page.locator('#file-rows button').first.click()
    expect(page.locator('#action-dialog')).to_be_visible()
    page.keyboard.press('Escape')
    check('Escape closes dialog',not page.locator('#action-dialog').is_visible())
    check('focus returns to opener',page.locator('#file-rows button').first.evaluate('(e) => document.activeElement === e'))
    page.locator('[data-action="upload"]').click()
    page.locator('[name="demo-file"]').set_input_files({'name':'<img src=x onerror=alert(1)>.txt','mimeType':'text/plain','buffer':b'preview-only'})
    page.locator('#action-submit').click()
    expect(page.locator('#file-rows tr')).to_have_count(5)
    check('filename safely rendered as text',page.locator('#file-rows img').count() == 0 and '<img src=x' in page.locator('#file-rows').inner_text())
    page.keyboard.press('Control+k')
    page.locator('#global-search').fill('미팅')
    expect(page.locator('#search-results a')).to_have_count(1)
    page.locator('#search-results a').click()
    expect(page.locator('#document-title')).to_have_text('주간 미팅 노트')
    check('search navigates to chosen document',not page.locator('#search-dialog').is_visible())
    page.locator('[data-action="write"]').click()
    page.locator('[name="demo-title"]').fill('<script>시연 메모</script>')
    page.locator('[name="demo-body"]').fill('첫 번째 줄\n두 번째 줄')
    page.locator('#action-submit').click()
    expect(page.locator('#document-title')).to_have_text('<script>시연 메모</script>')
    check('document text is safe and retained',page.locator('.article-content script').count() == 0 and '두 번째 줄' in page.locator('.article-content').inner_text())
    page.locator('[data-nav="community"]').click()
    page.locator('.document-card').first.click()
    expect(page.locator('#document-title')).to_have_text('함께 만드는 우리 팀 가이드')
    check('adding a page preserves existing document links',True)
    page.goto(BASE + '#settings')
    page.locator('[name="default-design"][value="claude"]').check()
    page.locator('[name="appearance"]').select_option('system')
    page.locator('[name="reduce-motion"]').check()
    page.locator('[name="high-contrast"]').check()
    page.locator('#settings-form button[type="submit"]').click()
    page.reload()
    check('settings saved together',page.locator('html').get_attribute('data-design') == 'claude' and page.locator('html').get_attribute('data-motion') == 'reduce' and page.locator('html').get_attribute('data-contrast') == 'high')
    page.emulate_media(color_scheme='dark')
    expect(page.locator('html')).to_have_attribute('data-theme','dark')
    check('system theme reacts to OS change',True)
    page.locator('[data-mode="astra"]').click()
    for theme in ['light','dark']:
        page.evaluate('(theme) => { document.documentElement.dataset.theme = theme; }',theme)
        for width in [360,412,820,1280,1440]:
            page.set_viewport_size({'width':width,'height':900})
            for screen in ['home','files','community','document','components','login','settings']:
                page.evaluate('(screen) => { location.hash = screen; }',screen)
                expect(page.locator('#breadcrumb')).to_have_text({'home':'Qaredi 홈','files':'t-box 파일','community':'커뮤니티','document':'문서 읽기','components':'디자인 시스템','login':'로그인 화면','settings':'디자인 설정'}[screen])
                check(f'high contrast no overflow {theme}/{width}/{screen}',page.evaluate('document.documentElement.scrollWidth - innerWidth') == 0)
    page.goto(BASE + '?design=astra#home')
    check('reduced motion suppresses hero animation',page.locator('.feature-art img').evaluate('(e) => getComputedStyle(e).animationName') == 'none')
    page.set_viewport_size({'width':412,'height':900})
    page.locator('[data-nav="login"]').click()
    page.locator('[name="demo-name"]').fill('preview')
    page.locator('[name="demo-password"]').fill('not-a-real-password')
    page.locator('#login-form button[type="submit"]').click()
    check('login demo clears fields',page.locator('[name="demo-password"]').input_value() == '' and '시연을 마쳤어요' in page.locator('#login-status').inner_text())
    page.goto(BASE + '?design=astra#settings')
    page.evaluate("() => { Storage.prototype.setItem = () => { throw new DOMException('disabled', 'SecurityError'); }; }")
    page.locator('#save-default').click()
    expect(page.locator('.toast')).to_contain_text('저장하지 못했어요')
    check('storage failure is reported honestly',True)
    check('no page or console errors',not errors,errors)
    check('no HTTP errors',not bad_requests,bad_requests)
    check('no external requests',not external,external)
    ctx.close()
    blocked = browser.new_context()
    blocked.add_init_script("Object.defineProperty(window, 'localStorage', { get() { throw new DOMException('blocked','SecurityError'); } });")
    blocked_page = blocked.new_page()
    blocked_page.goto(BASE + '?design=astra')
    check('startup with storage blocked',blocked_page.locator('html').get_attribute('data-design') == 'astra' and blocked_page.locator('main h1').count() == 1)
    blocked.close(); browser.close()

result = {'checks':checks,'failures':failures,'contrast_ratios':contrasts,'console_errors':errors,'http_errors':bad_requests,'external_requests':external}
(ROOT / 'tests' / 'astra-results.json').write_text(json.dumps(result,ensure_ascii=False,indent=2))
print(json.dumps(result,ensure_ascii=False,indent=2),flush=True)
if server:
    server.shutdown()
sys.exit(bool(failures))
