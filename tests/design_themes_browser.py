"""Rendered production views: responsive themes, keyboard/default persistence, contrast and motion."""
import json, os, socket, subprocess, time
from pathlib import Path
from playwright.sync_api import sync_playwright, expect

ROOT = Path(__file__).resolve().parents[1]
OUT = Path(os.environ.get('DESIGN_RESULTS', '/tmp/qaredi-design-results'))
OUT.mkdir(parents=True, exist_ok=True)
with socket.socket() as sock:
    sock.bind(('127.0.0.1', 0))
    port = sock.getsockname()[1]
BASE = f'http://127.0.0.1:{port}'
server = subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(ROOT/'public'),str(ROOT/'tests/design_fixture.php')], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
time.sleep(.5)
failures, console, checks = [], [], 0
def check(label, value):
    global checks
    checks += 1
    if not value: failures.append(label)

def luminance(rgb):
    values = [float(v)/255 for v in rgb]
    values = [v/12.92 if v<=.04045 else ((v+.055)/1.055)**2.4 for v in values]
    return sum(v*w for v,w in zip(values,[.2126,.7152,.0722]))
def ratio(a,b):
    a,b=sorted([luminance(a),luminance(b)])
    return (b+.05)/(a+.05)

try:
 with sync_playwright() as p:
    browser=p.chromium.launch()
    context=browser.new_context(user_agent='tbox-review/2026-10-01')
    page=context.new_page()
    page.on('pageerror',lambda e:console.append(str(e)))
    # Production JS owns auth/API behavior; this isolated test exercises rendered views and new design JS only.
    context.route('**/assets/app.js?*',lambda r:r.fulfill(content_type='text/javascript',body=''))
    context.route('**/assets/search.js?*',lambda r:r.fulfill(content_type='text/javascript',body=''))
    context.route('**/assets/passkey.js?*',lambda r:r.fulfill(content_type='text/javascript',body=''))
    for script in ['moa','start','product-home','home-dashboard','dashboard-canvas','dashboard-widgets','messenger-window','member']:
      context.route('**/assets/'+script+'.js?*',lambda r:r.fulfill(content_type='text/javascript',body=''))
    for width in [360,412,820,1440]:
      page.set_viewport_size({'width':width,'height':960})
      for theme in ['moa','claude','astra','aurora','bloom','nova']:
       for brightness in ['light','dark']:
        context.clear_cookies()
        context.add_cookies([{'name':'qaredi_design','value':theme,'url':BASE},{'name':'tbox_theme','value':brightness,'url':BASE}])
        for screen in ['launcher','anonymous','login','files','settings','community']:
          tag=f'{width}/{theme}/{brightness}/{screen}'
          response=page.goto(BASE+'/?screen='+screen)
          check(tag+' HTTP',response.status==200)
          expect(page.locator('html')).to_have_attribute('data-design',theme)
          check(tag+' overflow',page.evaluate('document.documentElement.scrollWidth <= innerWidth'))
          check(tag+' duplicate IDs',page.evaluate('(()=>{const ids=[...document.querySelectorAll("[id]")].map(x=>x.id);return ids.length===new Set(ids).size})()'))
          check(tag+' CSP markup',page.locator('[style],script:not([src]),[onclick]').count()==0)
          if screen=='anonymous':
            check(tag+' personal dashboard and private loaders absent',page.locator('#home-widgets,#dashboard-services,script[src*="home-dashboard.js"],script[src*="start.js"],script[src*="product-home.js"]').count()==0)
            # R1(2026-10-15) — 사이트 H2(DESIGN 2.32 ⑥) 뒤 로그인 전 첫 화면은 「지금 하려는 일에 맞게」 카드 넷 대신 ①~⑤ 절(가입하면 할 수 있는 일 카드 넷 · 시작 · 안전 · 질문)이 서비스를 설명한다
            check(tag+' public service descriptions remain',page.locator('.moa-service-card').count()==0
                  and all(page.locator('section.'+c).count()==1 for c in ['landing-hero','landing-features','landing-steps','landing-trust','landing-faq'])
                  and page.locator('.landing-features__grid > *').count()==4)
          if screen=='launcher' and width==1440 and brightness=='light':
            page.screenshot(path=str(OUT/f'{theme}-launcher.png'),full_page=True)
          if screen=='login' and theme=='nova' and width in [360,1440] and brightness=='light':
            page.screenshot(path=str(OUT/f'nova-login-{width}.png'),full_page=True)
          if screen=='launcher':
            colors=page.evaluate('''()=>{const s=getComputedStyle(document.documentElement);const get=k=>{let d=document.createElement('div');d.style.color=s.getPropertyValue(k);document.body.append(d);let c=getComputedStyle(d).color.match(/[\\d.]+/g).map(Number);d.remove();return c.slice(0,3)};return {fg:get('--fg'),surface:get('--surface'),muted:get('--muted'),primary:get('--primary'),pfg:get('--primary-fg')}}''')
            check(tag+' text contrast',ratio(colors['fg'],colors['surface'])>=4.5 and ratio(colors['muted'],colors['surface'])>=4.5)
            check(tag+' button contrast',ratio(colors['primary'],colors['pfg'])>=4.5)
    # Actual control flow: cancel, Escape/focus, save, navigation, shared setting, cookie shape.
    page.set_viewport_size({'width':1440,'height':1000})
    context.clear_cookies()
    # R1(2026-10-15) — 사이트 H1(DESIGN 2.31 「테마」) 뒤 런처의 테마 입구는 내 계정 판의 「테마」 타일(.app-menu__app — 판은 app.js 가 연다)뿐이다
    #   (옛 런처 인사 줄의 「화면 꾸미기」 단추는 로그인한 런처에서 빠졌다). 이 시험은 app.js 를 비워 두므로 판을 열 수 없다 —
    #   런처에는 그 타일과 같은 창이 실렸는지만 보고, 창 흐름(취소 · Esc · 초점 · 저장)은 보이는 입구가 있는 설정 화면에서 돈다.
    page.goto(BASE+'/?screen=launcher')
    check('launcher theme entry = account-menu tile + same dialog',page.locator('.app-menu [data-design-open]').count()==1 and page.locator('[data-design-open]').count()==1 and page.locator('#q-design-dialog').count()==1)
    page.goto(BASE+'/?screen=settings')
    opener=page.locator('[data-design-open]:visible').first
    opener.click(); page.locator('[data-design-choice="nova"]').click()
    expect(page.locator('html')).to_have_attribute('data-design','nova')
    page.keyboard.press('Escape')
    expect(page.locator('html')).to_have_attribute('data-design','moa')
    check('cancel restores focus',opener.evaluate('(e)=>e===document.activeElement'))
    opener.click(); page.locator('[data-design-choice="nova"]').click(); page.locator('#q-motion-reduce').check(); page.locator('#q-design-save').click()
    expect(page.locator('#q-design-status')).to_contain_text('저장했습니다')
    page.locator('[data-design-close]').click()
    page.goto(BASE+'/?screen=launcher')
    expect(page.locator('html')).to_have_attribute('data-design','nova')
    expect(page.locator('html')).to_have_attribute('data-motion','reduce')
    page.goto(BASE+'/?screen=settings')
    expect(page.locator('html')).to_have_attribute('data-design','nova')
    # 설정 화면의 입구 셋(H1 2.31 — 사이드바 바닥 단추 · 내 계정 판 「테마」 타일 · 설정 화면 「테마 고르기」, 모두 같은 창 q-design-dialog). 옛 기대 2 는 H1 앞
    check('global theme three settings entry points',page.locator('[data-design-open]').count()==3 and page.locator('.app-menu [data-design-open]').count()==1 and page.locator('[data-design-open][aria-controls="q-design-dialog"]').count()==3)
    check('motion override',page.locator('.q-design-status, .ok-note').first.evaluate('(e)=>getComputedStyle(e).animationName')=='none')   # 사이트 H5 — 셸 머리 글자(.brand__name — 움직이던 것) 0 → 같은 「움직임 줄이기」 규칙(*)이 거는 다른 요소
    check('cookie scope',all(c['path']=='/' and c['sameSite']=='Lax' and c['expires']>time.time()+30000000 for c in context.cookies() if c['name'].startswith('qaredi_')))
    page.goto(BASE+'/?screen=launcher&hc=1')
    check('high contrast keeps original primary',page.evaluate('getComputedStyle(document.documentElement).getPropertyValue("--primary").trim()') in ['#000000','#18181b','#09090b','#ffffff','#fafafa'])
    check('high contrast clears large service decoration',page.locator('.moa-service-card').first.evaluate('(e)=>getComputedStyle(e).backgroundImage')=='none')
    context.clear_cookies(); context.add_cookies([{'name':'qaredi_design','value':'nova','url':BASE}]); page.emulate_media(reduced_motion='reduce',color_scheme='dark')
    page.goto(BASE+'/?screen=settings')
    page.locator('[data-design-open]:visible').first.click()
    check('OS motion preference',page.locator('.q-theme-option').first.evaluate('(e)=>getComputedStyle(e).transitionDuration')=='0s')
    page.keyboard.press('Escape')
    check('OS dark color',page.evaluate('getComputedStyle(document.documentElement).getPropertyValue("--surface").trim()')=='#222427')
    page.emulate_media(reduced_motion='no-preference',color_scheme='light')
    page.goto(BASE+'/?screen=launcher')
    page.wait_for_timeout(4700)
    check('automatic motion stops',page.evaluate('document.getAnimations().filter(a=>a.playState==="running").length')==0)
    check('browser exceptions',not console)
    browser.close()
finally:
 server.terminate(); server.wait()
result={'checks':checks,'failures':failures,'console':console,'scope':'isolated rendered production views; original auth/API scripts excluded; live regression separate'}
(OUT/'results.json').write_text(json.dumps(result,ensure_ascii=False,indent=2))
print(json.dumps(result,ensure_ascii=False))
print('RESULT pass=%d fail=%d skip=0' % (checks-len(failures), max(len(failures), 1 if console else 0)))
raise SystemExit(bool(failures or console))
