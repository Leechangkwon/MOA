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
OUT = Path(os.environ.get('MESSENGER_RESIZE_RESULTS', '/tmp/messenger-resize'))
OUT.mkdir(parents=True, exist_ok=True)
HTML = subprocess.check_output(['php','tests/fixtures/messenger_resize_render.php'],cwd=ROOT,text=True)
BASE = 'https://movable.example'
OWNER = 'synthetic_owner'
KEY = 'qaredi-messenger-position:v1:' + OWNER
STATE = 'qaredi-messenger:v1:' + OWNER
SIZE = 'qaredi-messenger-size:v1:' + OWNER
checks, errors, writes, observations = [], [], [], []
session = {'owner':OWNER,'allowed':True,'acting':False,'status':200}

def check(ok, label):
    assert ok, label
    checks.append(label)
    print('PASS ' + label, flush=True)

with sync_playwright() as pw:
    browser = pw.chromium.launch()
    def context(blocked=False,touch=False):
        ctx = browser.new_context(viewport={'width':1280,'height':900},has_touch=touch,reduced_motion='reduce',user_agent='tbox-review/2026-10-12')
        ctx.add_init_script("""window.positionWrites=[];const old=Storage.prototype.setItem;Storage.prototype.setItem=function(k,v){if(k.startsWith('qaredi-messenger-position:')||k.startsWith('qaredi-messenger-size:'))positionWrites.push([k,v]);return old.call(this,k,v)};""")
        if blocked: ctx.add_init_script("Object.defineProperty(window,'sessionStorage',{get(){throw new Error('synthetic storage denied')}})")
        def route(r):
            path = urlsplit(r.request.url).path
            if path.startswith('/tbox/assets/'):
                file=ROOT/'public/assets'/path.removeprefix('/tbox/assets/')
                r.fulfill(body=file.read_bytes(),content_type='text/javascript' if file.suffix=='.js' else 'text/css');return
            if path=='/community/api/me/guide':
                r.fulfill(json={'csrf_token':'synthetic','guide':{'version':1,'status':'dismissed','context':'messenger','step':0}});return
            if path=='/tbox/api/messages/ai':
                if r.request.method != 'GET':writes.append({'method':r.request.method,'path':path});r.fulfill(status=403,json={'error':'synthetic_write_blocked'});return
                r.fulfill(json={'ready':True,'notice':'','remaining':8,'user_daily':10,'thread':None,'threads':[],'turns':[],'has_more':False});return
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
    def size():return page.evaluate('k=>JSON.parse(sessionStorage.getItem(k))',SIZE)
    def resize(edge,dx,dy,up=True):
        b=page.locator('[data-messenger-resize="'+edge+'"]').bounding_box()
        page.mouse.move(b['x']+b['width']/2,b['y']+b['height']/2);page.mouse.down();page.mouse.move(b['x']+b['width']/2+dx,b['y']+b['height']/2+dy,steps=5)
        if up:page.mouse.up()
    def metrics():return page.evaluate('''()=>{const ids=['messenger-window','message-list','message-text','message-send','message-ai-notice'];return Object.fromEntries(ids.map(id=>{const n=document.getElementById(id),r=n.getBoundingClientRect();return [id,{x:r.x,y:r.y,w:r.width,h:r.height,right:r.right,bottom:r.bottom,scroll:n.scrollHeight,client:n.clientHeight}]}))}''')
    def ai():
        if page.locator('#messenger-window').get_attribute('data-view')!='list':page.locator('#messenger-back').click()
        page.locator('#message-ai').click();expect(page.locator('#message-text')).to_be_enabled()
    def check_tiny_access():
        load();opened();ai();cdp=ctx.new_cdp_session(page)
        def hit(selector):
            n=page.locator(selector);n.scroll_into_view_if_needed();page.wait_for_timeout(80)
            return n.evaluate("n=>{const r=n.getBoundingClientRect(),e=document.elementFromPoint(r.x+r.width/2,r.y+r.height/2),d=document.querySelector('#messenger-window').getBoundingClientRect();return {hit:!!e&&(e===n||n.contains(e)),fits:r.y>=d.y-1&&r.bottom<=d.bottom+1,rect:{y:r.y,b:r.bottom},window:{y:d.y,b:d.bottom}}}")
        for design in ['moa','claude','aurora','nova']:
            page.evaluate('d=>document.documentElement.dataset.design=d',design)
            cdp.send('Emulation.setPageScaleFactor',{'pageScaleFactor':4});page.wait_for_timeout(200)
            for state in [False,True]:
                page.locator('#message-ai-notice').evaluate('(n,v)=>n.open=v',state)
                for selector in ['#message-ai-notice>summary','#message-text','#message-send']+(['#message-ai-notice-details a'] if state else []):
                    g=hit(selector);check(g['hit'] and g['fits'],design+' tiny actual hit '+selector+' open='+str(state)+' '+str(g))
                g=page.locator('#messenger-window').evaluate("d=>{const r=d.getBoundingClientRect();return [...d.querySelectorAll('[data-messenger-resize]')].every(n=>{const b=n.getBoundingClientRect();return b.y>=r.y-1&&b.bottom<=r.bottom+1})}")
                check(g,design+' all resize handles remain on window after scrolling')
            page.locator('#message-ai-notice').evaluate('n=>n.open=false')
            check(hit('#messenger-position-reset')['hit'] and hit('[data-messenger-close].btn--icon')['hit'],design+' tiny default and close reachable')
            cdp.send('Emulation.setPageScaleFactor',{'pageScaleFactor':1});page.wait_for_timeout(200)
            check(page.locator('#messenger-window').evaluate("d=>d.scrollTop===0&&![...d.querySelectorAll('[data-messenger-resize]')].some(n=>n.style.translate)"),design+' returning from tiny clears outer scroll and translated handles')
        # Resize control still works after scroll, including cancel and final cleanup.
        cdp.send('Emulation.setPageScaleFactor',{'pageScaleFactor':4});page.wait_for_timeout(200);hit('#message-text')
        before=rect();el=page.locator('[data-messenger-resize="s"]');el.focus();page.keyboard.press('ArrowDown')
        check(rect()['h']>=before['h'] and rect()['bottom']<=209,'tiny keyboard resize applies minimum/viewport clamp after content scroll '+str(before)+' -> '+str(rect()))
        before=rect();pref=size();resize('s',0,15,False);page.locator('[data-messenger-resize="s"]').dispatch_event('pointercancel',{'pointerId':1});page.mouse.up()
        check(rect()==before and size()==pref,'tiny pointercancel restores confirmed geometry')
        cdp.send('Emulation.setPageScaleFactor',{'pageScaleFactor':1});page.wait_for_timeout(200);page.locator('#messenger-position-reset').click()
        check(rect()['w']==400 and rect()['h']==640 and stored() is None and size() is None,'reset clears resized preferences and transforms')
        page.set_viewport_size({'width':390,'height':250});page.wait_for_timeout(150)
        for selector in ['#message-ai-notice>summary','#message-text','#message-send']:
            g=hit(selector);check(g['hit'] and g['fits'],'mobile250 sequential scroll actual hit '+selector)
        page.evaluate('document.dispatchEvent(new Event("qaredi:logout"))')
        page.evaluate("()=>{const d=document.querySelector('#messenger-window');d.scrollTop=50;d.dispatchEvent(new Event('scroll'))}")
        check(page.locator('#messenger-window').evaluate("d=>![...d.querySelectorAll('[data-messenger-resize]')].some(n=>n.style.translate)"),'destroy removes edge translation and scroll handler')
        check(not errors and not writes,'tiny delta no JS errors or business writes')
        print('TINY_CHECKS_COMPLETE',flush=True)
    load();opened();ai()
    check(size() is None and stored() is None,'opening AI only reads geometry preferences')
    m=metrics();observations.append({'initial':m})
    check(m['messenger-window']['w']==400 and m['messenger-window']['h']==640,'initial desktop size remains400x640')
    check(m['message-list']['h']>=275,'compact AI leaves at least275px conversation at400x640')
    check(page.locator('#messenger-window [data-usage-guide-open]').count()==1 and page.locator('#messenger-window>.modal__head [data-usage-guide-open]').count()==1,'one help action inside header and no duplicate guide row')
    check(not page.locator('#message-ai-notice').evaluate('n=>n.open') and page.locator('#message-ai-notice>summary').is_visible(),'AI warning defaults collapsed with visible Google/privacy summary')
    page.locator('#message-ai-notice>summary').click()
    expect(page.locator('#message-ai-notice-details a')).to_be_visible()
    check(page.locator('#message-ai-notice-details #message-peer-meta').count()==1 and page.locator('#message-peer-meta').count()==1,'same model metadata node and admin settings available in details')
    page.locator('#message-ai-notice>summary').click()
    # The actual guide uses its unchanged delegated action and modal layer.
    page.locator('#messenger-window>.modal__head [data-usage-guide-open]').click();expect(page.locator('.usage-guide-dialog')).to_be_visible()
    page.keyboard.press('Escape');check(page.locator('#messenger-window').evaluate('n=>n.open'),'guide Escape preserves messenger')
    page.locator('#messenger-back').click();page.locator('#message-people button').click()
    check(page.locator('.moa-chat-heading #message-peer-meta').count()==1 and page.locator('#message-ai-notice').is_hidden(),'person mode restores same metadata to original header')
    ai()
    # Make room on all edges, then prove each edge holds its opposite side.
    for edge in ['n','e','s','w','ne','se','sw','nw']:
        page.locator('#messenger-position-reset').click();begin(-440,-75);page.mouse.up();before=rect()
        dx=-45 if 'w' in edge else 45 if 'e' in edge else 0
        dy=-35 if 'n' in edge else 35 if 's' in edge else 0
        count=page.evaluate('positionWrites.length');resize(edge,dx,dy,False)
        check(page.evaluate('positionWrites.length')==count,'resize '+edge+' performs no per-move writes')
        mid=rect();page.mouse.up();after=rect()
        check(after==mid and abs(after['w']-(before['w']+abs(dx)))<=1 and abs(after['h']-(before['h']+abs(dy)))<=1,'actual '+edge+' pointer commits expected dimensions')
        check(abs((after['right'] if 'w' in edge else after['x'])-(before['right'] if 'w' in edge else before['x']))<=1 and abs((after['bottom'] if 'n' in edge else after['y'])-(before['bottom'] if 'n' in edge else before['y']))<=1,'resize '+edge+' holds opposite edges')
        record=size();check(set(record)=={'v','w','h','mobileH'} and all(type(v) is int for v in record.values()) and record['w']==round(after['w']) and record['h']==round(after['h']),'resize '+edge+' exact numeric size payload')
        el=page.locator('[data-messenger-resize="'+edge+'"]');el.focus();before=rect()
        k='ArrowLeft' if 'w' in edge else 'ArrowRight' if 'e' in edge else 'ArrowUp' if edge=='n' else 'ArrowDown'
        page.keyboard.press('Shift+'+k);after=rect()
        check(abs((after['w']-before['w'] if dx else after['h']-before['h'])-40)<=1,'resize '+edge+' keyboard Shift40')
    # Cancel must discard preview, including close/modals/permission.
    for event in ['pointercancel','lostpointercapture','blur','modal','close','pagehide']:
        before=rect();pref=size();pos=stored();resize('se',30,20,False)
        if event in ['pointercancel','lostpointercapture']:page.locator('[data-messenger-resize="se"]').dispatch_event(event,{'pointerId':1})
        elif event=='modal':page.evaluate('document.querySelector("#fixture-modal").showModal()')
        elif event=='close':page.evaluate('document.querySelector("#messenger-window").close()')
        else:page.evaluate('e=>window.dispatchEvent(new Event(e))',event)
        page.wait_for_timeout(40);page.mouse.up()
        if event=='modal':page.keyboard.press('Escape')
        if event=='close':opened()
        check(rect()==before and size()==pref and stored()==pos,'resize '+event+' rolls back and preserves preferences')
    page.locator('#message-text').fill('합성 AI 크기 초안')
    page.wait_for_function("()=>Object.keys(localStorage).some(k=>k.includes(':ai:0'))")
    pref=size();pos=stored();page.goto(BASE+'/community/',wait_until='networkidle');expect(page.locator('#message-text')).to_have_value('합성 AI 크기 초안')
    check(size()==pref and stored()==pos and round(rect()['w'])==pref['w'],'cross-app reload restores dimensions/position/encrypted AI draft')
    check('합성 AI 크기 초안' not in page.evaluate('Object.values(localStorage).join(" ")'),'resizing adds no plaintext draft')
    # Minimum and maximum; no root overflow and composer remains available at minimum.
    resize('se',-4000,-4000);m=metrics()
    check(m['messenger-window']['w']==360 and m['messenger-window']['h']==420,'desktop minimum360x420')
    check(m['message-list']['h']>=60 and m['message-text']['bottom']<=m['messenger-window']['bottom'],'minimum window retains conversation and composer')
    resize('nw',-4000,-4000);resize('se',4000,4000);r=rect()
    check(r['x']>=16 and r['y']>=16 and r['right']<=1264 and r['bottom']<=884,'all resize extremes stay inside desktop margins')
    pref=size();pos=stored();page.set_viewport_size({'width':820,'height':700});page.wait_for_timeout(150)
    check(size()==pref and stored()==pos and rect()['right']<=804 and rect()['bottom']<=684,'temporary shrink clamps display without changing preferred rectangle')
    page.set_viewport_size({'width':390,'height':844});page.wait_for_timeout(150)
    r=rect();check(abs(r['h']-844*.88)<2 and r['w']==390 and r['bottom']==844,'mobile default88dvh full width')
    check(page.locator('[data-messenger-resize]').evaluate_all('ns=>ns.filter(n=>n.checkVisibility()&&!n.disabled).length')==1 and page.locator('#messenger-position-reset').is_visible(),'mobile has one top resize control and visible reset')
    resize('n',0,110);mobile=size();check(mobile['mobileH']==round(rect()['h']) and mobile['w']==pref['w'] and mobile['h']==pref['h'] and stored()==pos,'mobile height save preserves desktop size and position')
    page.locator('[data-messenger-resize="n"]').focus();h=rect()['h'];page.keyboard.press('ArrowUp');check(rect()['h']==h+10,'mobile top keyboard increases height')
    page.locator('#messenger-position-reset').click();check(size()['mobileH']==0 and size()['w']==pref['w'],'mobile reset only clears mobile preference')
    resize('n',0,70);mobile=size();page.set_viewport_size({'width':1280,'height':900});page.wait_for_timeout(150)
    check(size()==mobile and rect()['w']==pref['w'] and rect()['h']==pref['h'] and near(rect(),pos),'desktop return restores both desktop dimensions and position')
    page.locator('#messenger-position-reset').click();check(stored() is None and size()['w']==400 and size()['h']==640 and size()['mobileH']==mobile['mobileH'],'desktop reset preserves mobile preference')
    page.set_viewport_size({'width':820,'height':700});page.wait_for_timeout(150)
    check(rect()['h']==604 and rect()['bottom']==628,'default604 at700 keeps bottom72 help clearance even with mobile preference')
    page.set_viewport_size({'width':390,'height':844});page.wait_for_timeout(150);page.locator('#messenger-position-reset').click();check(size() is None,'all default preferences remove size key')
    # Responsive AI layout: multiple designs/modes, minimum/wide/phone and long human title.
    for design in ['moa','claude','aurora','bloom','astra','nova']:
        for theme in ['light','dark']:
            for width,height in [(360,740),(390,844),(720,700),(1280,900)]:
                page.evaluate('v=>{document.documentElement.dataset.design=v[0];document.documentElement.dataset.theme=v[1]}',[design,theme]);page.set_viewport_size({'width':width,'height':height});page.wait_for_timeout(150)
                m=metrics();r=m['messenger-window'];tag=f'{design}-{theme}-{width}';observations.append({'tag':tag,'metrics':m})
                g=page.evaluate('''()=>{const d=document.querySelector('#messenger-window');return {over:document.documentElement.scrollWidth>innerWidth,cs:[...d.querySelectorAll('.modal__head>*')].filter(n=>n.checkVisibility()).map(n=>{const r=n.getBoundingClientRect();return {x:r.x,y:r.y,right:r.right,bottom:r.bottom}})}}''')
                cs=g['cs'];check(not g['over'] and r['right']<=width and m['message-list']['h']>=100 and m['message-text']['bottom']<=r['bottom'],'AI reading/composer fit '+tag)
                check(all(c['x']>=r['x'] and c['right']<=r['right'] for c in cs) and all(min(a['right'],b['right'])<=max(a['x'],b['x'])+1 or min(a['bottom'],b['bottom'])<=max(a['y'],b['y'])+1 for i,a in enumerate(cs) for b in cs[i+1:]),'header controls fit without overlap '+tag)
                if design=='moa' and width in [390,1280]:page.screenshot(path=str(OUT/(tag+'.png')))
    # Native visualViewport shrink/zoom and a synthetic keyboard viewport event use same clamp path.
    page.set_viewport_size({'width':1280,'height':900});page.wait_for_timeout(150);cdp=ctx.new_cdp_session(page)
    for scale in [2,4,1]:
        cdp.send('Emulation.setPageScaleFactor',{'pageScaleFactor':scale});page.wait_for_timeout(200)
        g=page.evaluate('''()=>{const r=document.querySelector('#messenger-window').getBoundingClientRect(),v=visualViewport;return {x:r.x,y:r.y,right:r.right,bottom:r.bottom,w:v.width,h:v.height}}''')
        check(g['x']>=15 and g['y']>=15 and g['right']<=g['w']-15 and g['bottom']<=g['h']-15,'visualViewport zoom clamp '+str(scale))
    cdp.detach();page.set_viewport_size({'width':390,'height':844});page.wait_for_timeout(150)
    page.evaluate('''()=>{const v=visualViewport;Object.defineProperty(v,'height',{configurable:true,value:330});v.dispatchEvent(new Event('resize'))}''');page.wait_for_timeout(60)
    check(rect()['bottom']<=330 and rect()['h']<=322,'keyboard-sized visualViewport keeps mobile sheet above keyboard')
    page.locator('#message-text').focus();page.locator('#message-text').scroll_into_view_if_needed()
    check(page.locator('#message-text').bounding_box()['y']>=0 and page.locator('#message-text').bounding_box()['y']+page.locator('#message-text').bounding_box()['height']<=330,'short viewport composer is fully visible')
    page.evaluate('''()=>{delete visualViewport.height;visualViewport.dispatchEvent(new Event('resize'))}''')
    # Permission failure cancels a pending resize; logout destroys handlers and both geometry keys.
    page.set_viewport_size({'width':1280,'height':900});page.wait_for_timeout(150);before=rect();resize('w',-30,0,False);session['status']=403
    page.locator('#message-search').evaluate("n=>{n.value='denied';n.dispatchEvent(new Event('input',{bubbles:true}))}");page.wait_for_timeout(450);page.mouse.up()
    check(rect()==before,'403 pending resize cancels without committing')
    session['status']=200;resize('w',-25,0);resize('n',0,-20,False);page.evaluate('document.dispatchEvent(new Event("qaredi:logout"))');page.mouse.up()
    page.dispatch_event('[data-messenger-resize="n"]','keydown',{'key':'ArrowUp'});page.set_viewport_size({'width':820,'height':700})
    check(size() is None and stored() is None and not page.locator('#messenger-window').evaluate('n=>n.open'),'logout clears both geometry keys and no late events recreate them')
    page.evaluate('k=>sessionStorage.setItem(k,JSON.stringify({v:1,w:500,h:600,mobileH:500}))',SIZE);session['owner']='synthetic_other';load();check(size() is None,'account transition removes previous size key')
    session['owner']=OWNER;session['allowed']=False;page.evaluate('k=>sessionStorage.setItem(k,JSON.stringify({v:1,w:500,h:600,mobileH:500}))',SIZE);load();check(size() is None,'denied owner removes own size key')
    session['allowed']=True
    for value in ['invalid','null','[]','{"v":1,"w":0,"h":420,"mobileH":0}','{"v":1,"w":"400","h":420,"mobileH":0}','{"v":1,"w":true,"h":420,"mobileH":0}','{"v":1,"w":400.5,"h":420,"mobileH":0}','{"v":1,"w":400,"h":1e999,"mobileH":0}','{"v":1,"w":400,"h":420,"mobileH":-1}','{"v":1,"w":100001,"h":420,"mobileH":0}','{"v":1,"w":400,"h":420,"mobileH":0,"extra":1}']:
        page.evaluate('v=>sessionStorage.setItem(v[0],v[1])',[SIZE,value]);load();check(size() is None,'invalid size discarded '+value)
    page.set_viewport_size({'width':1280,'height':900})
    page.evaluate('k=>sessionStorage.setItem(k,JSON.stringify({v:1,x:110,y:90}))',KEY);load();opened();check(near(rect(),{'x':110,'y':90}) and size() is None,'legacy position-only v1 restored without size migration write')
    ctx.close();ctx,page=context(touch=True)
    check_tiny_access()
    ctx.close();ctx,page=context(blocked=True);load();opened();before=rect();resize('w',-80,0);after=rect();check(after['w']==before['w']+80,'blocked storage resizing remains functional')
    page.locator('[data-messenger-close]').first.click();opened();check(rect()==after,'blocked storage same-page reopen retains memory dimensions')
    ctx.close();browser.close()
check(not errors,'no production JS or strict CSP errors')
check(not writes,'no message send AI generation or business writes')
result={'passed':len(checks),'failed':0,'checks':checks,'errors':errors,'writes':writes,'observations':observations,'sha256':{f:hashlib.sha256((ROOT/f).read_bytes()).hexdigest() for f in ['public/assets/messenger.js','src/views/messenger_window.php','public/assets/app.css','public/assets/moa.css','public/assets/app.js']}}
(OUT/'results.json').write_text(json.dumps(result,ensure_ascii=False,indent=2));print(str(len(checks))+' checks passed')
