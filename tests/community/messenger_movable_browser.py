"""Actual PHP floating window + production JS; synthetic HTTP, no sent messages or DB."""
import base64
import hashlib
import json
import os
import subprocess
from pathlib import Path
from urllib.parse import urlsplit
from playwright.sync_api import sync_playwright, expect

ROOT = Path(__file__).resolve().parents[2]
OUT = Path(os.environ.get('MESSENGER_MOVABLE_RESULTS', '/tmp/messenger-movable'))
OUT.mkdir(parents=True, exist_ok=True)
HTML = subprocess.check_output(['php','tests/fixtures/messenger_movable_render.php'],cwd=ROOT,text=True)
BASE = 'https://movable.example'
OWNER = 'synthetic_owner'
KEY = 'qaredi-messenger-position:v1:' + OWNER
STATE = 'qaredi-messenger:v1:' + OWNER
checks, errors, writes, observations = [], [], [], []
session = {'owner':OWNER,'allowed':True,'acting':False,'status':200}

def check(ok, label):
    assert ok, label
    checks.append(label)
    print('PASS ' + label, flush=True)

with sync_playwright() as pw:
    browser = pw.chromium.launch()
    def context(blocked=False):
        ctx = browser.new_context(viewport={'width':1280,'height':900},reduced_motion='reduce',user_agent='tbox-review/2026-10-12')
        ctx.add_init_script("""window.positionWrites=[];const old=Storage.prototype.setItem;Storage.prototype.setItem=function(k,v){if(k.startsWith('qaredi-messenger-position:'))positionWrites.push([k,v]);return old.call(this,k,v)};""")
        if blocked: ctx.add_init_script("Object.defineProperty(window,'sessionStorage',{get(){throw new Error('synthetic storage denied')}})")
        def route(r):
            path = urlsplit(r.request.url).path
            if path.startswith('/tbox/assets/'):
                file=ROOT/'public/assets'/path.removeprefix('/tbox/assets/')
                r.fulfill(body=file.read_bytes(),content_type='text/javascript' if file.suffix=='.js' else 'text/css');return
            if r.request.method not in ['GET','HEAD']:
                writes.append({'method':r.request.method,'path':path});r.fulfill(status=403,json={'error':'synthetic_write_blocked'});return
            if path=='/tbox/api/me':
                r.fulfill(json={'username':session['owner'],'member_id':1,'csrf_token':'synthetic','can_community_write':session['allowed'],'acting':session['acting']});return
            if path=='/community/api/me/draft-key':
                r.fulfill(json={'v':1,'key':base64.urlsafe_b64encode(bytes(range(32))).decode().rstrip('=')});return
            if path=='/tbox/api/messages':
                if session['status'] != 200:r.fulfill(status=session['status'],json={'error':'합성 권한 종료'});return
                r.fulfill(json={'people':[{'id':2,'username':'synthetic_peer','display_name':'합성 동료','unread':2}]});return
            if path=='/tbox/api/messages/2':
                r.fulfill(json={'peer':{'id':2,'username':'synthetic_peer','display_name':'합성 동료'},'messages':[],'has_more':False});return
            r.fulfill(body=HTML,content_type='text/html',headers={'Content-Security-Policy':"default-src 'self';script-src 'self';style-src 'self';img-src 'self' data:"})
        ctx.route('**/*',route)
        p=ctx.new_page()
        p.on('pageerror',lambda e:errors.append(str(e)))
        p.on('console',lambda m:errors.append(m.text) if m.type=='error' and not m.text.startswith('Failed to load resource') else None)
        return ctx,p
    ctx,page=context()
    def load(path='/tbox/'):
        page.goto(BASE+path,wait_until='networkidle')
        page.wait_for_function('()=>window.MoaMessenger !== undefined')
    def opened():
        if not page.locator('#messenger-window').evaluate('n=>n.open'): page.locator('#fixture-open').click()
        expect(page.locator('#message-people')).to_contain_text('합성 동료')
    def rect():return page.locator('#messenger-window').evaluate('n=>{const r=n.getBoundingClientRect();return {x:r.x,y:r.y,w:r.width,h:r.height,right:r.right,bottom:r.bottom}}')
    def stored():return page.evaluate('k=>JSON.parse(sessionStorage.getItem(k))',KEY)
    def begin(dx=-110,dy=-50):
        b=page.locator('#messenger-move-handle').bounding_box()
        page.mouse.move(b['x']+b['width']/2,b['y']+b['height']/2);page.mouse.down();page.mouse.move(b['x']+b['width']/2+dx,b['y']+b['height']/2+dy,steps=5)
    def near(a,b):return abs(a['x']-b['x'])<=1 and abs(a['y']-b['y'])<=1
    def check_zoom():
        cdp=ctx.new_cdp_session(page)
        for scale in [1,2,3,4,1]:
            cdp.send('Emulation.setPageScaleFactor',{'pageScaleFactor':scale});page.wait_for_timeout(200)
            g=page.evaluate("""()=>{const d=document.querySelector('#messenger-window'),r=d.getBoundingClientRect(),v=visualViewport;return {scale:v.scale,width:v.width,height:v.height,rect:{x:r.x,y:r.y,right:r.right,bottom:r.bottom},controls:[...d.querySelectorAll('.modal__head>*')].filter(n=>n.checkVisibility()).map(n=>{const b=n.getBoundingClientRect();return {id:n.id||n.tagName,left:b.left,top:b.top,right:b.right,bottom:b.bottom}})}}""")
            cs=g['controls'];r=g['rect'];observations.append({'zoom':scale,**g})
            check(r['x']>=15 and r['y']>=15 and r['right']<=g['width']-15 and r['bottom']<=g['height']-15 and all(c['left']>=r['x'] and c['right']<=r['right'] and c['bottom']<=r['bottom'] for c in cs),'visualViewport '+str(scale)+'x keeps window and controls inside')
            check(all(min(a['right'],b['right'])<=max(a['left'],b['left'])+1 or min(a['bottom'],b['bottom'])<=max(a['top'],b['top'])+1 for i,a in enumerate(cs) for b in cs[i+1:]),'visualViewport '+str(scale)+'x header controls do not overlap')
        cdp.detach()
    load();opened()
    check(page.locator('#messenger-window').evaluate('n=>!n.matches(":modal")'),'existing nonmodal behavior remains')
    default=rect()
    check(default['w']==400 and default['right']==1264 and default['bottom']==828,'initial right/bottom position remains')
    check(stored() is None,'GET/open does not write a position')
    begin();moving=rect()
    check(not near(moving,default) and stored() is None,'real pointer moves without per-move storage writes')
    page.mouse.up();saved=stored()
    check(near(rect(),moving) and set(saved)=={'v','x','y'} and saved['v']==1 and all(type(saved[n]) is int for n in ['x','y']),'pointerup commits numeric position without lostcapture rollback')
    check(page.evaluate('positionWrites.length')==1,'one storage write per completed drag')
    page.locator('#messenger-move-handle').focus();page.keyboard.press('ArrowLeft');page.keyboard.press('Shift+ArrowUp')
    check(stored()['x']==saved['x']-10 and stored()['y']==saved['y']-40,'keyboard 10px and Shift 40px movement')
    saved=stored()
    page.locator('#messenger-position-reset').click()
    check(stored() is None and near(rect(),default),'reset removes key and returns default')
    begin();page.mouse.up();saved=stored()
    page.locator('[data-messenger-close]').first.click();opened()
    check(near(rect(),saved),'close and reopen keeps position')
    # Actual encrypted draft and exact old v1 state shape survive navigation with position.
    page.locator('#message-people button').click();expect(page.locator('#message-text')).to_be_enabled()
    page.locator('#message-text').fill('합성 비밀 초안')
    page.wait_for_function("()=>Object.keys(localStorage).some(k=>k.includes(':message:2'))")
    raw=page.evaluate('Object.values(localStorage).join(" ")')
    check('합성 비밀 초안' not in raw and 'ct' in raw,'draft remains encrypted in localStorage')
    oldstate=page.evaluate('k=>JSON.parse(sessionStorage.getItem(k))',STATE)
    check(set(oldstate)=={'v','open','kind','peer','thread','scroll','at'},'existing v1 conversation state shape unchanged')
    load('/community/')
    expect(page.locator('#messenger-window')).to_be_visible();expect(page.locator('#message-text')).to_have_value('합성 비밀 초안')
    check(near(rect(),saved) and page.evaluate('!document.querySelector("#messenger-window").contains(document.activeElement)'),'same-tab cross-app restore retains position/draft without stealing focus')
    # Every cancellation restores the committed position and releases drag state.
    for event in ['pointercancel','lostpointercapture','blur','modal','close','pagehide']:
        opened();before=rect();count=page.evaluate('positionWrites.length');begin(-60,-30)
        if event in ['pointercancel','lostpointercapture']:
            page.locator('#messenger-window>.modal__head').dispatch_event(event,{'pointerId':1})
        elif event=='modal':page.evaluate('document.querySelector("#fixture-modal").showModal()')
        elif event=='close':page.evaluate('document.querySelector("#messenger-window").close()')
        else:page.evaluate('(e)=>window.dispatchEvent(new Event(e))',event)
        page.wait_for_timeout(30);page.mouse.up()
        if event=='modal':page.keyboard.press('Escape')
        if event=='close':opened()
        check(near(rect(),before) and not page.locator('#messenger-window').evaluate('n=>n.classList.contains("is-moving")') and page.evaluate('positionWrites.length')==count,event+' cancels movement and does not save')
    # Input, content, controls and IME keep their existing behavior.
    before=rect();page.locator('#message-text').focus()
    page.locator('#message-text').dispatch_event('keydown',{'key':'Escape','isComposing':True})
    check(page.locator('#messenger-window').evaluate('n=>n.open') and near(rect(),before),'IME Escape does not close or move')
    page.locator('#message-text').dispatch_event('keydown',{'key':'ArrowLeft'})
    check(near(rect(),before),'composer arrow keys do not move window')
    page.keyboard.press('Escape');check(not page.locator('#messenger-window').evaluate('n=>n.open'),'ordinary Escape still closes')
    opened();page.locator('#messenger-back').click()
    check(page.locator('#messenger-window').get_attribute('data-view')=='list' and near(rect(),before),'list button preserves click action without dragging')
    check(page.locator('#messenger-window>.modal__head a').get_attribute('href')=='/tbox/messages','full-page link preserved')
    # Keyboard/pointer clamp; temporary viewport reductions retain the desktop preference.
    page.locator('#messenger-position-reset').click();savedDefault=rect()
    begin(-3000,-3000);page.mouse.up()
    check(rect()['x']==16 and rect()['y']==16,'left/top edge clamp')
    begin(4000,4000);page.mouse.up()
    check(rect()['right']<=1264 and rect()['bottom']<=884,'right/bottom edge clamp')
    saved=stored();page.set_viewport_size({'width':820,'height':600});page.wait_for_timeout(50)
    check(rect()['right']<=804 and rect()['bottom']<=584 and stored()==saved,'resize clamps view without replacing desktop preference')
    page.set_viewport_size({'width':390,'height':844});page.wait_for_timeout(50)
    mobile=rect()
    check(abs(mobile['h']-844*.88)<2 and mobile['x']==0 and mobile['w']==390 and mobile['bottom']==844,'mobile keeps existing 88dvh full-width bottom sheet')
    check(not page.locator('#messenger-position-reset').is_visible() and page.locator('#messenger-move-handle').get_attribute('tabindex')=='-1','mobile hides reset and removes movement keyboard control')
    page.set_viewport_size({'width':1280,'height':900});page.wait_for_timeout(50)
    check(near(rect(),saved) and stored()==saved,'desktop return restores saved coordinates')
    page.locator('#messenger-position-reset').click()
    # Conversation header includes back, reset, full-page and close controls.
    page.locator('#message-people button').click()
    # Actual headers and controls stay inside 400px window across designs/brightness/viewports.
    for design in ['moa','claude','astra','aurora','bloom','nova']:
        for theme in ['light','dark']:
            for width in [360,390,412,719,720,820,1280]:
                page.evaluate('v=>{document.documentElement.dataset.design=v[0];document.documentElement.dataset.theme=v[1]}',[design,theme])
                page.set_viewport_size({'width':width,'height':900});page.wait_for_timeout(150)
                tag=f'{design}-{theme}-{width}'
                g=page.evaluate('''()=>{const d=document.querySelector('#messenger-window'),r=d.getBoundingClientRect();return {page:document.documentElement.scrollWidth,width:innerWidth,rect:{x:r.x,y:r.y,right:r.right,bottom:r.bottom},controls:[...d.querySelectorAll('.modal__head>*,.modal__head .messenger-move-handle')].filter(n=>n.checkVisibility()).map(n=>{const b=n.getBoundingClientRect();return {id:n.id||n.tagName,left:b.left,right:b.right}})}}''')
                observations.append({'tag':tag,**g})
                (OUT/'geometry.json').write_text(json.dumps(observations,ensure_ascii=False,indent=2))
                check(all(a['right']<=b['left']+1 for a,b in zip([c for c in g['controls'] if c['id']!='messenger-move-handle'],[c for c in g['controls'] if c['id']!='messenger-move-handle'][1:])),'header controls do not overlap '+tag)
                check(g['page']<=width and g['rect']['x']>=0 and g['rect']['right']<=width and g['rect']['bottom']<=900 and all(c['left']>=g['rect']['x'] and c['right']<=g['rect']['right'] for c in g['controls']),'window and header controls fit '+tag)
                if design=='moa' and width in [390,1280]:page.screenshot(path=str(OUT/f'{tag}.png'))
    page.set_viewport_size({'width':1280,'height':900})
    check_zoom()
    # A modal/permission response during movement must release capture and not save.
    before=rect();begin(-30,-20);session['status']=403
    page.locator('#message-search').evaluate("n=>{n.value='x';n.dispatchEvent(new Event('input',{bubbles:true}))}")
    page.wait_for_timeout(450);page.mouse.up()
    check(near(rect(),before) and not page.locator('#messenger-window').evaluate('n=>n.classList.contains("is-moving")'),'permission response ends active drag')
    session['status']=200;page.locator('#message-search').evaluate("n=>{n.value='';n.dispatchEvent(new Event('input',{bubbles:true}))}");page.wait_for_timeout(300)
    begin();page.mouse.up();check(stored() is not None,'position available before logout')
    begin(-40,-20);page.evaluate('document.dispatchEvent(new Event("qaredi:logout"))');page.mouse.up()
    page.set_viewport_size({'width':820,'height':700});page.dispatch_event('#messenger-move-handle','keydown',{'key':'ArrowRight'})
    check(stored() is None and not page.locator('#messenger-window').evaluate('n=>n.open'),'logout clears position and no late movement rewrites it')
    # New owner removes old position without reading content; denied owner never mounts.
    page.evaluate('(k)=>sessionStorage.setItem(k,JSON.stringify({v:1,x:100,y:80}))',KEY)
    session['owner']='synthetic_other';load()
    check(page.evaluate('k=>sessionStorage.getItem(k)',KEY) is None,'account transition clears previous owner position')
    session['owner']=OWNER;session['allowed']=False
    page.evaluate('(k)=>sessionStorage.setItem(k,JSON.stringify({v:1,x:100,y:80}))',KEY);load()
    check(stored() is None and not page.locator('#messenger-window').evaluate('n=>n.open'),'denied permission clears own position and does not open')
    session['allowed']=True
    # Untrusted storage is data only; malformed/out-of-range shapes are removed.
    for value in ['invalid','null','[]','{"v":1,"x":"10","y":10}','{"v":1,"x":true,"y":10}','{"v":1,"x":1.5,"y":10}','{"v":1,"x":-1,"y":10}','{"v":1,"x":1e999,"y":10}','{"v":1,"x":100001,"y":10}','{"v":1,"x":10,"y":10,"extra":1}']:
        page.evaluate('(v)=>sessionStorage.setItem(v[0],v[1])',[KEY,value]);load()
        check(stored() is None,'invalid position discarded '+value)
    ctx.close()
    ctx,page=context(blocked=True);load();opened();before=rect();begin();page.mouse.up();moved=rect()
    check(not near(before,moved),'blocked storage keeps drag functional in memory')
    page.locator('[data-messenger-close]').first.click();opened();check(near(rect(),moved),'blocked storage keeps same-page reopen position')
    ctx.close();browser.close()
check(not errors,'no production JavaScript or strict CSP errors')
check(not writes,'no messages or business writes')
result={'passed':len(checks),'failed':0,'checks':checks,'errors':errors,'writes':writes,'observations':observations,'sha256':{f:hashlib.sha256((ROOT/f).read_bytes()).hexdigest() for f in ['public/assets/messenger.js','src/views/messenger_window.php','public/assets/app.css','public/assets/app.js']}}
(OUT/'results.json').write_text(json.dumps(result,ensure_ascii=False,indent=2))
print(str(len(checks))+' checks passed')
