"""Actual pointer, keyboard and touch interactions with rendered PHP and strict CSP.
Synthetic preferences only; no production service or records are contacted.
"""
import copy,json,os,subprocess
from pathlib import Path
from urllib.parse import urlsplit
from playwright.sync_api import sync_playwright,expect
ROOT=Path(__file__).resolve().parents[1];OUT=Path(os.environ.get('FREEFORM_RESULTS',str(ROOT.parent.parent/'outputs/freeform-dashboard-checks')));OUT.mkdir(parents=True,exist_ok=True)
checks=[];state={};conflict=False;denied=False;calls=[]
def check(ok,name):
 checks.append({'name':name,'passed':bool(ok)});print(('PASS 'if ok else'FAIL ')+name,flush=True);assert ok,name
def layout(ids):return {'version':2,'widgets':[{'id':i,'visible':True,'desktop':{'x':0,'y':n*316,'w':600,'h':300},'mobile':{'x':0,'y':n*316,'w':280,'h':300}}for n,i in enumerate(ids)],'source':'all','view':'recent'}
state={'personal':{'layout':layout(['recent','services','tools','stories']),'revision':4},'space':{'layout':layout(['notice','task','event','file','story']),'revision':5}}
SPACE='11111111-1111-4111-8111-111111111111';OTHER='22222222-2222-4222-8222-222222222222'
spaces=[{'id':SPACE,'name':'회사 공간','kind':'company','status':'active','role':'owner','can_manage':True,'can_edit_home':True,'can_write':True},{'id':OTHER,'name':'읽기 공간','kind':'group','status':'active','role':'viewer','can_manage':False,'can_edit_home':False,'can_write':False}]
CSP="default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'"
with sync_playwright()as pw:
 browser=pw.chromium.launch();errors=[]
 def route(r):
  global state
  p=urlsplit(r.request.url).path;m=r.request.method
  if p in ['/','/tbox/spaces']:
   args=['php',str(ROOT/'tests/fixtures/service_home_render.php'),'home','signed']if p=='/'else['php',str(ROOT/'tests/fixtures/space_home_render.php')]
   body=subprocess.check_output(args,text=True)
   if p=='/':body=body.replace('</header>','<a href="#services">옛 서비스 소개</a></header>',1)   # 사이트 H1b — 모아 홈 머리에 「#services」 링크가 없어졌다(DESIGN 2.31): 옛 저장 구성의 링크 처리는 home_dashboard.py 처럼 고정 링크로 시험
   r.fulfill(body=body,content_type='text/html',headers={'Content-Security-Policy':CSP});return
  if p.startswith('/tbox/assets/'):
   f=ROOT/'public/assets'/p.removeprefix('/tbox/assets/');r.fulfill(body=f.read_bytes(),content_type='text/javascript'if f.suffix=='.js'else'text/css');return
  if p=='/tbox/api/me':r.fulfill(json={'member_id':1001,'csrf_token':'synthetic','can_community_write':True});return
  if p=='/tbox/api/product-spaces':r.fulfill(json={'spaces':spaces});return
  if p.endswith('/members'):r.fulfill(json={'me':1001,'members':[{'user_id':1001,'role':'owner','display_name':'소유자'}]});return
  kind='personal'if p=='/tbox/api/me/home-dashboard'else'space'if p.endswith('/dashboard')else None
  if kind:
   if m=='PATCH':
    body=r.request.post_data_json;calls.append((kind,copy.deepcopy(body)));check(r.request.headers.get('x-csrf-token')=='synthetic',kind+' CSRF header preserved');check(r.request.frame.page.locator('.dashboard-editor button').evaluate_all('(nodes)=>nodes.every(n=>n.disabled)'),kind+' save locks cancel/default/duplicate')
    if denied:r.fulfill(status=403,json={'error':'권한이 없습니다.'});return
    if conflict:r.fulfill(status=409,json={'error':'conflict'});return
    check(body['revision']==state[kind]['revision'],kind+' revision preserved');state[kind]={'layout':body['layout'],'revision':state[kind]['revision']+1}
   r.fulfill(json=state[kind]);return
  if p.endswith('/home'):
   if denied:r.fulfill(status=403,json={'error':'권한이 없습니다.'});return
   s=spaces[1]if OTHER in p else spaces[0];r.fulfill(json={'space':s,**state['space'],'widgets':{i:[{'id':i+'1','title':'실제 계약 합성 '+i,'body':'긴 내용 '*100,'can_edit':s['can_write'],'can_delete':s['can_write'],'revision':1}]for i in ['notice','task','event','file','story']}});return
  r.fulfill(json={'files':[],'pages':[],'boards':[],'items':[],'widgets':{}})
 def new_page(mobile=False):
  context=browser.new_context(viewport={'width':390 if mobile else 1280,'height':900},has_touch=mobile,is_mobile=mobile);context.route('**/*',route);context.add_init_script("document.addEventListener('readystatechange',()=>{const d=document.querySelector('details.home-dashboard');if(document.readyState==='interactive'&&d)d.open=true})");page=context.new_page();page.on('pageerror',lambda e:errors.append(str(e)));return context,page  # 사이트 H1b — 모아 홈 대시보드 기본 접힘(DESIGN 2.31): defer 스크립트 전에 details 를 연다
 def visit(page,kind):page.goto('https://moa.example/'+('tbox/spaces'if kind=='space'else''),wait_until='networkidle')
 def edit(page,kind):
  if kind=='personal':page.locator('#home-edit').click()
  else:page.locator('#space-menu').click();page.get_by_role('button',name='대시보드 편집',exact=True).click()
  check(page.locator('.dashboard-editor').is_visible(),kind+' edits actual page')
 def geometry(page,id):return page.locator('[data-dashboard-widget='+id+']').evaluate('(n)=>{const r=n.getBoundingClientRect(),p=n.parentElement.getBoundingClientRect();return{x:r.x-p.x,y:r.y-p.y,w:r.width,h:r.height}}')
 def drag(page,selector,dx,dy):
  handle=page.locator(selector);handle.evaluate("n=>n.scrollIntoView({block:'center'})");page.wait_for_timeout(30);r=handle.bounding_box();x=r['x']+r['width']/2;y=r['y']+r['height']/2;page.mouse.move(x,y);page.mouse.down();page.mouse.move(x+dx,y+dy,steps=8);page.mouse.up()
 for kind,first in [('personal','recent'),('space','notice')]:
  ctx,page=new_page();visit(page,kind);check(page.locator('.dashboard-card-chrome:visible').count()==0,kind+' chrome absent normally');before=geometry(page,first);desktop_before=copy.deepcopy(state[kind]['layout']['widgets'][0]['desktop']);mobile_before=copy.deepcopy(state[kind]['layout']['widgets'][0]['mobile']);edit(page,kind)
  drag(page,'[data-dashboard-move='+first+']',83,57);moved=geometry(page,first);check(moved['x']>before['x']+60 and moved['y']>before['y']+40,kind+' mouse moves arbitrary x and y')
  drag(page,'[data-dashboard-resize='+first+']',71,63);resized=geometry(page,first);check(resized['w']>moved['w']+50 and resized['h']>moved['h']+50,kind+' corner resizes both dimensions')
  check(page.locator('[data-dashboard-widget='+first+'] .dashboard-card-body').evaluate('(n)=>n.scrollHeight<=n.clientHeight+1'),kind+' sized content avoids internal scroll')if kind=='space'else None
  page.get_by_role('button',name='배치 저장·완료',exact=True).click();page.wait_for_timeout(100);check(state[kind]['layout']['widgets'][0]['desktop']!=desktop_before,kind+' actual geometry persisted');check({k:state[kind]['layout']['widgets'][0]['mobile'][k] for k in mobile_before}==mobile_before,kind+' desktop editing preserves mobile');check(page.locator('.dashboard-card-chrome:visible').count()==0,kind+' save removes editor chrome');saved=geometry(page,first);page.reload(wait_until='networkidle');check(abs(geometry(page,first)['x']-saved['x'])<1,kind+' reload restores geometry')
  edit(page,kind);drag(page,'[data-dashboard-move='+first+']',-31,25);page.keyboard.press('Escape');check(abs(geometry(page,first)['x']-saved['x'])<1,kind+' Escape restores saved position')
  edit(page,kind);page.locator('[data-dashboard-move='+first+']').focus();page.keyboard.press('Shift+ArrowDown');keyboard=geometry(page,first);check(keyboard['y']>=saved['y']+49,kind+' keyboard Shift acceleration');page.locator('[data-dashboard-resize='+first+']').focus();page.keyboard.press('ArrowLeft');check(geometry(page,first)['w']<keyboard['w'],kind+' keyboard resizes width');page.get_by_role('button',name='취소',exact=True).click()
  edit(page,kind);page.locator('[data-dashboard-settings='+first+']').click();page.locator('.dashboard-selection .dashboard-more').evaluate('n=>n.open=true');page.get_by_role('button',name='숨기기',exact=True).click();check(not page.locator('[data-dashboard-widget='+first+']').is_visible(),kind+' hide works after prior gestures');page.locator('.dashboard-batch').evaluate('n=>n.open=true');page.get_by_label('위젯 추가',exact=True).select_option(first);check(page.locator('[data-dashboard-widget='+first+']').is_visible(),kind+' hidden widget restored');page.get_by_role('button',name='취소',exact=True).click()
  conflict=True;old=copy.deepcopy(state[kind]);edit(page,kind);drag(page,'[data-dashboard-move='+first+']',19,29);draft=geometry(page,first);page.get_by_role('button',name='배치 저장·완료',exact=True).click();page.wait_for_timeout(100);check(page.locator('.dashboard-editor').is_visible()and geometry(page,first)==draft,kind+' 409 preserves geometry draft');check(state[kind]==old,kind+' 409 leaves server unchanged');page.get_by_role('button',name='취소',exact=True).click();conflict=False
  edit(page,kind);page.get_by_role('button',name='배치 저장·완료',exact=True).click();page.wait_for_timeout(100);check(page.locator('.dashboard-editor').count()==0,kind+' second edit saves with updated revision')
  for width in [360,390,759,760,1280]:
   page.set_viewport_size({'width':width,'height':900});check(page.evaluate('document.documentElement.scrollWidth<=innerWidth'),kind+' no page overflow '+str(width));check(page.locator('.dashboard-card-body').evaluate_all('(nodes)=>nodes.every(n=>n.scrollWidth<=n.clientWidth+1)'),kind+' no card body overflow '+str(width))
  page.screenshot(path=str(OUT/(kind+'-desktop.png')),full_page=True);edit(page,kind);page.locator('[data-dashboard-resize='+first+']').focus();page.keyboard.press('Shift+ArrowLeft');page.screenshot(path=str(OUT/(kind+'-editing-desktop.png')),full_page=True);page.keyboard.press('Escape');ctx.close()
  ctx,page=new_page(True);visit(page,kind);desktop=copy.deepcopy(state[kind]['layout']['widgets'][0]['desktop']);edit(page,kind);handle=page.locator('[data-dashboard-move='+first+']');handle.scroll_into_view_if_needed();r=handle.bounding_box();x=r['x']+r['width']/2;y=r['y']+r['height']/2;before=geometry(page,first);cdp=ctx.new_cdp_session(page)
  cdp.send('Input.dispatchTouchEvent',{'type':'touchStart','touchPoints':[{'x':x,'y':y}]});cdp.send('Input.dispatchTouchEvent',{'type':'touchMove','touchPoints':[{'x':x+23,'y':y+42}]});cdp.send('Input.dispatchTouchEvent',{'type':'touchEnd','touchPoints':[]});check(geometry(page,first)['x']>before['x']+15 and geometry(page,first)['y']>before['y']+30,kind+' actual touch drag')
  page.screenshot(path=str(OUT/(kind+'-editing-mobile.png')),full_page=True);page.get_by_role('button',name='배치 저장·완료',exact=True).click();page.wait_for_timeout(100);check({k:state[kind]['layout']['widgets'][0]['desktop'][k] for k in desktop}==desktop,kind+' mobile save preserves desktop');page.screenshot(path=str(OUT/(kind+'-mobile.png')),full_page=True);ctx.close()
 # Other clients may submit overlapping but bounds-valid geometry; reading does not write.
 baseline=copy.deepcopy(state['personal']);state['personal']['layout']=layout(['recent','services','tools','stories'])
 for w in state['personal']['layout']['widgets']:w['desktop']['y']=0
 ctx,page=new_page();count=len(calls);visit(page,'personal');rects=[geometry(page,i)for i in ['recent','services','tools','stories']];check(all(rects[n+1]['y']>=rects[n]['y']+rects[n]['h']for n in range(3)),'server overlaps normalized readably');check(len(calls)==count,'reading overlap sends no implicit PATCH');ctx.close()
 for w in state['personal']['layout']['widgets']:w['desktop'].update(y=18000,h=2000)
 ctx,page=new_page();visit(page,'personal');check(page.locator('.dashboard-flow').count()==1 and page.locator('.dashboard-card:visible').count()==4,'impossible max-height overlap retains all cards in readable flow');edit(page,'personal');page.locator('.dashboard-batch').evaluate('n=>n.open=true');page.get_by_role('button',name='기본 배치',exact=True).click();check(page.locator('.dashboard-flow').count()==0,'default recovers impossible placement');page.keyboard.press('Escape');ctx.close()
 state['personal']=baseline
 ctx,page=new_page();visit(page,'personal');edit(page,'personal');handle=page.locator('[data-dashboard-move=recent]');handle.scroll_into_view_if_needed();r=handle.bounding_box();before=geometry(page,'recent');page.mouse.move(r['x']+10,r['y']+10);page.mouse.down();page.mouse.move(r['x']+30,r['y']+40);page.set_viewport_size({'width':390,'height':900});page.wait_for_timeout(50);page.mouse.up();page.set_viewport_size({'width':1280,'height':900});page.wait_for_timeout(50);check(geometry(page,'recent')==before,'breakpoint change rolls back active gesture');page.keyboard.press('Escape');ctx.close()
 # Hidden services navigation temporarily resolves collisions without changing preferences.
 state['personal']=copy.deepcopy(baseline);state['personal']['layout']['widgets'][1]['visible']=False;state['personal']['layout']['widgets'][1]['desktop'].update(x=0,y=0)
 ctx,page=new_page();visit(page,'personal');count=len(calls);page.locator('header a[href="#services"]').click();first=geometry(page,'recent');second=geometry(page,'services');check(second['y']+1>=first['y']+first['h']or first['y']+1>=second['y']+second['h']or second['x']+1>=first['x']+first['w']or first['x']+1>=second['x']+second['w'],'temporary services reveal safely resolves overlap');check(not state['personal']['layout']['widgets'][1]['visible']and len(calls)==count,'temporary reveal does not persist');edit(page,'personal');check(not page.locator('[data-dashboard-widget=services]').is_visible(),'edit clears temporary reveal');page.keyboard.press('Escape');ctx.close()
 # Actual mouse positions two widgets on the same row, saves and reloads them.
 state['personal']['layout']=layout(['recent','services','tools','stories'])
 ctx,page=new_page();visit(page,'personal');edit(page,'personal');drag(page,'[data-dashboard-resize=recent]',-160,0);drag(page,'[data-dashboard-resize=services]',-160,0);drag(page,'[data-dashboard-move=services]',620,-316);a=geometry(page,'recent');b=geometry(page,'services');check(abs(a['y']-b['y'])<1 and a['x']+a['w']<=b['x']+1,'mouse creates adjacent irregular-width widgets on same row');page.screenshot(path=str(OUT/'personal-irregular-editing-desktop.png'),full_page=True);page.get_by_role('button',name='배치 저장·완료',exact=True).click();page.wait_for_timeout(100);page.reload(wait_until='networkidle');check(abs(geometry(page,'recent')['y']-geometry(page,'services')['y'])<1,'side-by-side positions survive save and reload');ctx.close()
 for kind,ids in [('personal',['recent','services','tools','stories']),('space',['notice','task','event','file','story'])]:
  state[kind]['layout']=layout(ids)
  for w in state[kind]['layout']['widgets']:
   w['desktop']['w']=240;w['desktop']['h']=160;w['mobile']['w']=160;w['mobile']['h']=160
  ctx,page=new_page();visit(page,kind)
  for width in [360,390,760,1280]:
   page.set_viewport_size({'width':width,'height':900});check(page.locator('.dashboard-card-body').evaluate_all('(nodes)=>nodes.every(n=>n.scrollWidth<=n.clientWidth+1)'),kind+' minimum cards no overflow '+str(width));edit(page,kind);check(page.locator('.dashboard-card-body').evaluate_all('(nodes)=>nodes.every(n=>n.scrollWidth<=n.clientWidth+1)'),kind+' minimum editing no overflow '+str(width));page.keyboard.press('Escape')
  ctx.close()
 for operation in ['save','refresh']:
  denied=False;ctx,page=new_page();visit(page,'space');denied=True
  if operation=='save':edit(page,'space');page.get_by_role('button',name='배치 저장·완료',exact=True).click()
  else:page.locator('#space-menu').click();page.get_by_role('button',name='새로 불러오기',exact=True).click()
  expect(page.locator('#space-picker option')).to_have_count(0);expect(page.locator('#space-cards>*')).to_have_count(0);check(page.locator('#space-cards').inner_text()==''and page.locator('#space-picker option').count()==0,'v2 '+operation+' 403 fully clears restored canvas content');check(page.locator('.dashboard-editor').count()==0,'v2 '+operation+' 403 removes editor');ctx.close()
 denied=False
 check(not errors,'no JavaScript errors');browser.close()
(OUT/'results.json').write_text(json.dumps(checks,ensure_ascii=False,indent=2));print(str(len(checks))+' checks passed')
