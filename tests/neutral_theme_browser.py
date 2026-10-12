"""Neutral surface regression on actual PHP views, with no session or database.

Use NEUTRAL_BASELINE_CSS for a frozen pre-change CSS file to compare preserved
brand, semantic, document, Claude and high-contrast colors against that version.
Production design.js remains active; unrelated business scripts are excluded.
"""
import hashlib
import json
import os
import re
import socket
import subprocess
import time
from pathlib import Path
from urllib.parse import urlsplit
from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[1]
OUT = Path(os.environ.get('NEUTRAL_RESULTS', '/tmp/neutral-theme'))
OUT.mkdir(parents=True, exist_ok=True)
BASELINE = os.environ.get('NEUTRAL_BASELINE_CSS')
with socket.socket() as sock:
    sock.bind(('127.0.0.1', 0))
    port = sock.getsockname()[1]
BASE = f'http://127.0.0.1:{port}'
log = (OUT / 'php-server.log').open('w')
server = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', '-t', str(ROOT/'public'), str(ROOT/'tests/neutral_theme_fixture.php')], stdout=log, stderr=log)
time.sleep(.4)
checks, failures, errors, writes, observations = [], [], [], [], []

def check(name, ok):
    checks.append(name)
    if not ok:
        failures.append(name)
        print('FAIL ' + name, flush=True)

def rgb(value):
    return [float(v) for v in re.findall(r'[\d.]+', value)[:3]]

def ratio(a, b):
    def lum(value):
        values = [n/255 for n in rgb(value)]
        return sum((n/12.92 if n <= .04045 else ((n+.055)/1.055)**2.4)*w for n, w in zip(values, [.2126, .7152, .0722]))
    a, b = sorted([lum(a), lum(b)])
    return (b+.05)/(a+.05)

SNAPSHOT = '''()=>{
 const root=getComputedStyle(document.documentElement), names=['--q-canvas','--surface','--surface-2','--fg','--fg-2','--muted','--line','--bg','--primary','--primary-hover','--primary-fg','--accent','--accent-soft','--accent-line','--ring','--ring-soft','--danger','--warn-fg','--ok-fg','--nt-blue-bg','--nt-green-bg','--nt-yellow-bg'];
 const tokens={};for(const name of names){const span=document.createElement('span');span.style.color=root.getPropertyValue(name);document.body.append(span);tokens[name]=getComputedStyle(span).color;span.remove();}
 const props=selector=>{const n=document.querySelector(selector);if(!n)return null;const s=getComputedStyle(n),r=n.getBoundingClientRect();return {color:s.color,bg:s.backgroundColor,image:s.backgroundImage,border:s.borderTopColor,font:s.fontSize,line:s.lineHeight,outline:s.outlineColor,outlineWidth:s.outlineWidth,shadow:s.boxShadow,width:r.width,left:r.left,right:r.right,height:r.height};};
 return {tokens,body:props('body'),main:props('.main'),panel:props('#neutral-controls'),doc:props('#page-doc>.doc'),callouts:[...document.querySelectorAll('#page-doc .doc-callout')].map(n=>getComputedStyle(n).backgroundColor),cells:[...document.querySelectorAll('#page-doc th,#page-doc td')].map(n=>getComputedStyle(n).backgroundColor),primary:props('#neutral-primary'),quiet:props('#neutral-quiet'),link:props('#neutral-link'),input:props('#neutral-input'),select:props('#neutral-select'),active:props('#neutral-active'),pageWidth:document.documentElement.scrollWidth,width:innerWidth,mainOverflow:document.querySelector('#main')?document.querySelector('#main').scrollWidth-document.querySelector('#main').clientWidth:0};
}'''
keep = ['--bg','--primary','--primary-hover','--primary-fg','--accent','--accent-soft','--accent-line','--ring','--ring-soft','--danger','--warn-fg','--ok-fg','--nt-blue-bg','--nt-green-bg','--nt-yellow-bg']
designs = ['astra','aurora','bloom','nova','moa','claude']
modes = [('light','dark'),('dark','light'),('system','light'),('system','dark')]
before = {}
try:
 with sync_playwright() as pw:
    browser = pw.chromium.launch()
    context = browser.new_context(user_agent='tbox-review/2026-10-12', reduced_motion='reduce')
    page = context.new_page()
    page.on('pageerror', lambda e: errors.append(str(e)))
    page.on('console', lambda m: errors.append(m.text) if m.type == 'error' else None)
    baseline_active = False
    def route(request):
        path = urlsplit(request.request.url).path
        if request.request.method not in ['GET','HEAD']:
            writes.append(path); request.fulfill(status=403, json={'error':'synthetic_write_blocked'}); return
        if path.endswith('/design.css') and baseline_active:
            request.fulfill(body=Path(BASELINE).read_bytes(), content_type='text/css'); return
        if path.endswith('.js') and not path.endswith('/design.js'):
            request.fulfill(body='', content_type='text/javascript'); return
        if '/api/' in path:
            request.fulfill(json={'items':[]}); return
        request.continue_()
    context.route('**/*', route)
    def load(design, mode, scheme, screen='components', high=False):
        context.clear_cookies()
        cookies=[{'name':'qaredi_design','value':design,'url':BASE}]
        if mode != 'system': cookies.append({'name':'tbox_theme','value':mode,'url':BASE})
        context.add_cookies(cookies)
        page.emulate_media(color_scheme=scheme, reduced_motion='reduce', forced_colors='none')
        response=page.goto(BASE+'/?screen='+screen+('&hc=1' if high else ''), wait_until='networkidle')
        check(f'{design}/{mode}/{scheme}/{screen}/http', response.status == 200)
        if response.status != 200: raise RuntimeError('PHP fixture failed; inspect php-server.log')
    if BASELINE:
        baseline_active = True
        for design in designs:
            for mode, scheme in modes:
                for high in [False, True]:
                    load(design, mode, scheme, high=high)
                    before[(design, mode, scheme, high)] = page.evaluate(SNAPSHOT)
        baseline_active = False
    for design in designs:
        for mode, scheme in modes:
            load(design, mode, scheme)
            dark = mode == 'dark' or (mode == 'system' and scheme == 'dark')
            for width in [360,390,412,820,1280]:
                page.set_viewport_size({'width':width,'height':950})
                tag=f'{design}/{mode}/{scheme}/{width}'
                data=page.evaluate(SNAPSHOT)
                observations.append({'tag':tag, **data})
                t=data['tokens']
                check(tag+' page fits', data['pageWidth'] <= width and data['mainOverflow'] <= 1)
                check(tag+' document transparent', data['doc']['bg'] == 'rgba(0, 0, 0, 0)')
                check(tag+' text and muted contrast', min(ratio(t[a],t[b]) for a in ['--fg','--fg-2','--muted'] for b in ['--surface','--surface-2','--q-canvas']) >= 4.5)
                check(tag+' primary and link contrast', ratio(t['--primary'],t['--primary-fg']) >= 4.5 and ratio(t['--accent'],t['--surface']) >= 4.5)
                check(tag+' controls readable', all(data[n]['right'] <= width and data[n]['left'] >= 0 and data[n]['height'] >= float(data[n]['font'][:-2])+8 for n in ['input','select']))
                if design in designs[:4]:
                    check(tag+' neutral surfaces and text', all(max(rgb(t[k]))-min(rgb(t[k])) <= 12 for k in ['--q-canvas','--surface','--surface-2','--fg','--fg-2','--muted','--line']))
                    check(tag+' explicit/system brightness', t['--surface'] == ('rgb(34, 36, 39)' if dark else 'rgb(255, 255, 255)'))
                    check(tag+' active selection retains brand', data['active']['bg'] == t['--accent-soft'] and data['active']['color'] == t['--primary'])
                    page.locator('#neutral-primary').focus()
                    focus=page.locator('#neutral-primary').evaluate('(n)=>{const s=getComputedStyle(n);return {color:s.outlineColor,width:s.outlineWidth}}')
                    check(tag+' visible brand focus', focus['color'] == t['--ring'] and float(focus['width'][:-2]) >= 2 and ratio(t['--ring'],t['--surface']) >= 3)
                    page.locator('#neutral-primary').blur()
                if BASELINE:
                    old=before[(design,mode,scheme,False)]
                    check(tag+' brand semantic and document tokens preserved', all(t[k] == old['tokens'][k] for k in keep) and data['callouts'] == old['callouts'] and data['cells'][:2] == old['cells'][:2])
                    if design in ['moa','claude']:
                        check(tag+' original exception tokens preserved', t == old['tokens'])
                if design in ['aurora','nova'] and mode in ['light','dark'] and width in [390,1280]:
                    page.locator('#neutral-controls').screenshot(path=str(OUT/f'{design}-{mode}-{width}-controls.png'))
            # Native production theme dialog: same tokens, viewport bounds, keyboard escape/focus.
            opener=page.locator('[data-design-open]').first
            opener.click()
            modal=page.locator('#q-design-dialog')
            box=modal.bounding_box()
            check(tag+' theme dialog fits', box['x'] >= 0 and box['x']+box['width'] <= 1280)
            colors=modal.evaluate('(n)=>{const s=getComputedStyle(n);return [s.color,s.backgroundColor]}')
            check(tag+' dialog contrast', ratio(*colors) >= 4.5)
            page.keyboard.press('Escape')
            check(tag+' dialog Escape restores focus', opener.evaluate('n=>n===document.activeElement'))
            # The actual existing visibility modal is a second production component.
            page.evaluate('document.querySelector("#visibility-dialog").showModal()')
            page.set_viewport_size({'width':390,'height':900})
            native=page.locator('#visibility-dialog').bounding_box()
            check(tag+' form modal mobile fits', native['x'] >= 0 and native['x']+native['width'] <= 390 and native['y']+native['height'] <= 900)
            page.keyboard.press('Escape')
            load(design, mode, scheme, high=True)
            hc=page.evaluate(SNAPSHOT)
            if BASELINE: check(tag+' high contrast untouched', hc['tokens'] == before[(design,mode,scheme,True)]['tokens'])
            page.emulate_media(forced_colors='active')
            check(tag+' forced colors document intact', page.locator('#page-doc>.doc').evaluate('n=>getComputedStyle(n).backgroundColor') == 'rgba(255, 255, 255, 0)' or page.locator('#page-doc>.doc').evaluate('n=>getComputedStyle(n).backgroundColor') == 'rgba(0, 0, 0, 0)')
    # Current server-rendered home, public entry, login and files, including service icons.
    for design in designs[:4]:
        for mode in ['light','dark']:
            for screen in ['launcher','anonymous','login','files']:
                load(design,mode,mode,screen)
                for width in [390,1280]:
                    page.set_viewport_size({'width':width,'height':960})
                    tag=f'actual/{design}/{mode}/{screen}/{width}'
                    check(tag+' page fits', page.evaluate('document.documentElement.scrollWidth<=innerWidth'))
                    if screen in ['launcher','anonymous']:
                        check(tag+' neutral service cards', page.locator('.moa-service-card').evaluate_all('(nodes)=>nodes.every(n=>getComputedStyle(n).backgroundImage==="none")'))
                        icon_colors=page.locator('.moa-service-card .moa-shortcut-icon').evaluate_all('(nodes)=>nodes.map(n=>getComputedStyle(n).color)')
                        check(tag+' distinct service icon colors', len(set(icon_colors)) == 4)
                        if screen == 'launcher':
                            legacy=page.locator('[data-home-widget="recent"]')
                            c=legacy.evaluate('(n)=>{const s=getComputedStyle(n);return {color:s.color,bg:s.backgroundColor,image:s.backgroundImage}}')
                            check(tag+' existing recent widget neutral and readable', c['image']=='none' and max(rgb(c['bg']))-min(rgb(c['bg']))<=11 and ratio(c['color'],c['bg'])>=4.5)
                        if screen == 'anonymous':
                            check(tag+' no private dashboard/loaders', page.locator('#home-widgets,#dashboard-services,script[src*="home-dashboard.js"],script[src*="start.js"]').count()==0)
                    if screen=='login':
                        check(tag+' neutral login panel',page.locator('.login__brand').evaluate('n=>getComputedStyle(n).backgroundImage')=='none')
                        for selector in ['.login__lead','.login__brand-sub','.login__foot','.login__foot a']:
                            c=page.locator(selector).first.evaluate('n=>[getComputedStyle(n).color,getComputedStyle(n.closest(".login__brand")).backgroundColor]')
                            check(tag+selector+' contrast',ratio(*c)>=4.5)
                    if screen in ['launcher','login'] and design in ['aurora','nova']:
                        page.screenshot(path=str(OUT/f'{design}-{mode}-{screen}-{width}.png'),full_page=True)
    check('no document or business writes', not writes)
    check('no JS or CSP errors', not errors)
    browser.close()
finally:
 server.terminate(); server.wait(); log.close()
result={'passed':len(checks)-len(failures),'failed':len(failures),'failures':failures,'errors':errors,'writes':writes,'baseline_compared':bool(BASELINE),'design_css_sha256':hashlib.sha256((ROOT/'public/assets/design.css').read_bytes()).hexdigest(),'observations':observations}
(OUT/'results.json').write_text(json.dumps(result,ensure_ascii=False,indent=2))
print(json.dumps({k:v for k,v in result.items() if k!='observations'},ensure_ascii=False))
raise SystemExit(bool(failures or errors))
