"""Real PHP views and assets, synthetic source responses. Never uses production data."""
import json,os,subprocess
from pathlib import Path
from urllib.parse import urlsplit,parse_qs
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[1]
OUT=Path(os.environ.get('COMPACT_RESULTS','/tmp/compact-home-checks'));OUT.mkdir(parents=True,exist_ok=True)
SID='11111111-1111-4111-8111-111111111111'
TYPES=['my_tasks','briefing','recent','favorites','space_notice','space_event','space_task']
checks=[];calls=[];revision=2;conflict=False;deny=False;canonical=False;expired=False
space={'id':SID,'name':'검토 회사','kind':'company','status':'active','role':'owner','can_manage':True,'can_edit_home':True,'can_write':True}
def config(types=TYPES):
 return {'version':3,'source':'all','view':'recent','guide':'pending','widgets':[{'id':'widget-'+str(j),'type':t,'source':{'space_id':SID,'scope':'all'}if t.startswith('space_')else{'kind':'all'}if t in ['recent','favorites']else{},'visible':True,'locked':False,'desktop':{'x':0,'y':j*444,'w':1200,'h':420,'size':'m'},'mobile':{'x':0,'y':j*436,'w':360,'h':420,'size':'m'}}for j,t in enumerate(types)]}
layout=config()
def check(ok,label):
 assert ok,label;checks.append(label);print('PASS '+label,flush=True)
with sync_playwright()as pw:
 browser=pw.chromium.launch();page=browser.new_page(viewport={'width':1280,'height':900});page.add_init_script("document.addEventListener('readystatechange',()=>{const d=document.querySelector('details.home-dashboard');if(document.readyState==='interactive'&&d)d.open=true})");errors=[];page.on('pageerror',lambda e:errors.append(str(e)))  # 사이트 H1b — 모아 홈 대시보드 기본 접힘(DESIGN 2.31): defer 스크립트 전에 details 를 연다
 def route(r):
  global revision,layout
  u=urlsplit(r.request.url);p=u.path;q=parse_qs(u.query);calls.append((r.request.method,p,q))
  if p=='/tbox/spaces':r.fulfill(body=subprocess.check_output(['php',str(ROOT/'tests/fixtures/space_home_render.php')],text=True),content_type='text/html',headers={'Content-Security-Policy':"default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:"});return
  if p=='/':r.fulfill(body=subprocess.check_output(['php',str(ROOT/'tests/fixtures/service_home_render.php'),'home','signed'],text=True),content_type='text/html',headers={'Content-Security-Policy':"default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:"});return
  if p.startswith('/tbox/assets/'):
   f=ROOT/'public/assets'/p.removeprefix('/tbox/assets/');r.fulfill(body=f.read_bytes(),content_type='text/javascript'if f.suffix=='.js'else'text/css');return
  if p=='/tbox/api/me':r.fulfill(json={'member_id':1001,'csrf_token':'synthetic'});return
  if p=='/tbox/api/product-spaces':r.fulfill(json={'spaces':[space]});return
  if p=='/tbox/api/me/home-dashboard':
   if r.request.method=='PATCH':
    if expired:r.fulfill(status=401,json={'error':'expired'});return
    if conflict:r.fulfill(status=409,json={'error':'conflict'});return
    check(r.request.post_data_json['revision']==revision,'server revision transmitted');layout=r.request.post_data_json['layout'];revision+=1
    if canonical:layout['shortcuts']=['account','library']
   r.fulfill(json={'layout':layout,'revision':revision});return
  if p.endswith('/home'):r.fulfill(json={'space':space,'layout':layout,'revision':revision,'widgets':{}});return
  if p.endswith('/members'):r.fulfill(json={'me':1001,'members':[{'user_id':1001,'display_name':'실제 담당자'}]});return
  if p.endswith('/dashboard'):
   if deny:r.fulfill(status=403,json={'error':'denied'});return
   if conflict:r.fulfill(status=409,json={'error':'conflict'});return
   layout=r.request.post_data_json['layout'];revision+=1;r.fulfill(json={'layout':layout,'revision':revision});return
  if '/items' in p:
   if deny:r.fulfill(status=403,json={'error':'denied'});return
   if r.request.method=='PATCH':r.fulfill(json={'item':{}});return
   kind=q.get('kind',['task'])[0];r.fulfill(json={'items':[{'id':str(i),'title':kind+' 실제 제목 '+str(i),'body':'실제 본문 발췌를 보여줍니다.','status':'todo'if kind=='task'else None,'starts_at':'2026-10-12T10:00:00+09:00'if kind=='event'else None,'due_at':'2026-10-12T11:00:00+09:00'if kind=='task'else None,'can_edit':i%2==0,'revision':i+1,'assignee_id':1001 if i%2==0 else None}for i in range(8)],'truncated':True,'as_of':'2026-10-12T10:30:00+09:00'});return
  if p=='/community/api/my-tasks':r.fulfill(json={'buckets':[{'key':'today','name':'오늘','items':[{'public_id':'task'+str(i),'title':'내 작업 '+str(i),'status':'todo','due':'2026-10-11','project':{'title':'실제 프로젝트'}}for i in range(8)]}]});return
  if p=='/community/api/briefing':r.fulfill(json={'top':[{'key':'page:task0','page_id':'task0','title':'중복 기한','url':'/community/p/task0','reasons':[{'kind':'due_today','summary':'오늘 마감','due':'2026-10-11'}]},{'key':'page:task1','page_id':'task1','title':'검토 유지','url':'/community/p/task1','reasons':[{'kind':'due_today','summary':'오늘 마감','due':'2026-10-11'},{'kind':'review','summary':'검토 요청','due':'2026-10-11'}]}],'missed':[],'others':[]});return
  if p.startswith('/tbox/api/files/'):r.fulfill(json={'files':[{'id':'file'+str(i),'name':'실제 파일 '+str(i),'last_used_at':'2026-10-11','scan_status':'pending','sensitive':True,'folder_path':'업무 / 자료'}for i in range(8)]});return
  if p=='/tbox/api/folders/favorites':r.fulfill(json={'folders':[{'id':7,'name':'즐겨찾기 폴더'}]});return
  if p.startswith('/community/api/me/'):r.fulfill(json={'pages':[{'id':'page'+str(i),'title':'실제 문서 '+str(i),'last_viewed_at':'2026-10-10','space':{'name':'개인'}}for i in range(8)]});return
  r.fulfill(json={})
 page.route('**/*',route)
 def visit(width):
  global layout
  layout=config(['recent','space_task']);page.set_viewport_size({'width':width,'height':900 if width>=1100 else 844 if width==390 else 640});page.goto('https://moa.example/',wait_until='networkidle')
 def choose(id='widget-0'):page.locator('[data-dashboard-settings="'+id+'"]').click()
 def shortcuts():return page.locator('#dashboard-services a').evaluate_all('(nodes)=>nodes.map(n=>n.dataset.homeShortcut)')
 def settings():
  page.locator('[data-dashboard-settings]').first.click();page.locator('.home-shortcut-settings summary').click()
 def finish(label='취소'):
  if page.locator('.dashboard-inspector-dialog[open]').count():page.locator('.dashboard-inspector-close:visible').click()
  page.get_by_role('button',name=label,exact=True).click();page.wait_for_timeout(100)
 default=['spaces','work','library','messages','assistant']   # Q:F-12 U5-3 — 「내 작업」(tasks)은 업무 홈과 같은 기능이라 기본에서 뺐다(사용자 결정 2026-10-13)
 for width in [1280,1440,1920,820,390,360]:
  for design in ['moa','aurora']:
   for theme in ['light','dark']:
    visit(width);page.evaluate('(v)=>{document.documentElement.dataset.design=v[0];document.documentElement.dataset.theme=v[1]}',[design,theme]);page.wait_for_timeout(350)
    check(shortcuts()==default,'default five shortcuts (내 작업 0) '+str(width)+design+theme)
    print('GEOMETRY first_widget_y',width,design,theme,page.locator('.dashboard-card').first.bounding_box()['y'],flush=True)
    check(page.locator('.dashboard-card').first.bounding_box()['y']>page.locator('details.home-dashboard > summary').bounding_box()['y'],'H1b first widget inside the opened dashboard below its summary '+str(width)+design+theme)   # 사이트 H1b — 옛 「first widget within compact target(y ≤ 300/340)」: 위젯은 이제 가운데 묶음 아래 대시보드 안(DESIGN 2.31)
    check(not page.locator('.dashboard-guide').evaluate('(n)=>n.open'),'guide closed '+str(width)+design+theme)
    check(page.locator('#home-edit').count()==1 and page.get_by_role('button',name='홈 구성하기').count()==0,'single home edit entry '+str(width)+design+theme)
    check(page.evaluate('document.documentElement.scrollWidth<=innerWidth'),'page no horizontal overflow '+str(width)+design+theme)
    check(page.locator('#dashboard-services a').evaluate_all('(ns)=>ns.every(n=>n.getBoundingClientRect().height>=44&&n.querySelector("svg"))'),'accessible icon touch areas '+str(width)+design+theme)
    check(page.locator('#home-all-services,.home-services-menu').count()==0 and page.locator('body > header div.shell-tools').count()==1,'H1 all services menu replaced by the shared top-right tools '+str(width)+design+theme)   # 사이트 H1 (DESIGN 2.31 「머리」) — 옛 「all services seven real routes」
    if design=='aurora'and theme=='dark':page.screenshot(path=str(OUT/('compact-'+str(width)+'.png')))
 for width in [1280,1440,1920,820,390,360]:
  visit(width);page.evaluate("document.documentElement.dataset.contrast='high'");page.wait_for_timeout(100);check(page.evaluate('document.documentElement.scrollWidth<=innerWidth'),'high contrast no overflow '+str(width));check(page.locator('.dashboard-card').first.bounding_box()['y']>page.locator('details.home-dashboard > summary').bounding_box()['y'],'H1b high contrast first widget below the dashboard summary '+str(width));check(page.locator('.shell-tools__name > span').evaluate('(n)=>getComputedStyle(n).textOverflow==="ellipsis"')and page.locator('.shell-tools__me').get_attribute('aria-label').startswith('내 계정 메뉴 — '),'H1 account chip ellipsis and accessible full name '+str(width))   # 사이트 H1 — 옛 a.moa-account 칩은 셸과 같은 내 계정 칩으로
 visit(1440);original=json.dumps(layout,sort_keys=True);page.locator('#home-edit').click();settings();before=len([x for x in calls if x[0]=='PATCH'])
 page.get_by_label('자료실 앞으로',exact=True).click();check(shortcuts()==['spaces','library','work','messages','assistant'],'draft reorder visible immediately')
 page.get_by_label('이야기',exact=True).focus();page.keyboard.press('Space');check(page.get_by_label('이야기',exact=True).evaluate('(n)=>n===document.activeElement'),'checkbox Space retains keyboard focus');check(shortcuts()[-1]=='story','draft optional shortcut added')
 check(len([x for x in calls if x[0]=='PATCH'])==before,'draft shortcut changes no HTTP write');finish();check(shortcuts()==default and json.dumps(layout,sort_keys=True)==original,'whole cancel restores original server shortcut absence')
 page.locator('#home-edit').click();settings()
 for label in ['우리 공간','업무 홈','자료실','메신저','PC 비서']:page.get_by_label(label,exact=True).uncheck()
 check(shortcuts()==[],'all hidden draft');finish('배치 저장·완료');check(layout['shortcuts']==[],'empty array explicitly saved');page.reload(wait_until='networkidle');check(shortcuts()==[],'all hidden reload');page.locator('#home-all-services').click();check(page.locator('.home-services-menu a').count()==7,'all hidden retains complete service access');page.locator('#home-all-services').click()
 page.locator('#home-edit').click();settings();page.get_by_role('button',name='기본 바로가기 복원',exact=True).click();check(shortcuts()==default,'explicit default restore preview');check(page.get_by_role('button',name='기본 바로가기 복원',exact=True).evaluate('(n)=>n===document.activeElement'),'default restore focus retained');conflict=True;finish('배치 저장·완료');check(page.locator('.dashboard-editor').is_visible()and shortcuts()==default,'409 retains shortcut draft');check(layout['shortcuts']==[],'409 leaves server unchanged');finish();conflict=False
 page.locator('#home-edit').click();settings();page.get_by_label('계정',exact=True).check();page.locator('.dashboard-batch summary').click();page.get_by_role('button',name='추천 구성 미리보기',exact=True).click();check(shortcuts()==['account'],'recommendation retains current draft shortcuts');finish();check(shortcuts()==[],'preview whole cancel restores server shortcut state')
 page.locator('#home-edit').click();settings();page.get_by_role('button',name='기본 바로가기 복원',exact=True).click();canonical=True;finish('배치 저장·완료');check(shortcuts()==['account','library'],'server canonical response adopted');canonical=False;page.reload(wait_until='networkidle');check(shortcuts()==['account','library'],'canonical persisted reload');second=browser.new_page();second.route('**/*',route);second.goto('https://moa.example/',wait_until='networkidle');check(second.locator('#dashboard-services a').evaluate_all('(ns)=>ns.map(n=>n.dataset.homeShortcut)')==['account','library'],'other browser context loads account shortcuts');second.close()
 visit(390);page.locator('#home-edit').click();settings();expired=True;finish('배치 저장·완료');check(page.locator('.dashboard-editor').count()==0 and page.locator('.dashboard-inspector-dialog').count()==0,'PATCH401 destroys editor and drawer');check(shortcuts()==[]and page.locator('#home-edit').is_disabled(),'PATCH401 clears shortcuts and blocks reediting');check(page.locator('[data-widget-type=recent]').inner_text()=='','PATCH401 purges sensitive widget content');expired=False
 layout=config(['recent']);layout['shortcuts']=['bogus'];page.reload(wait_until='networkidle');check(page.locator('#home-edit').is_disabled(),'invalid server layout blocks editing');check('중단'in page.locator('#home-layout-status').inner_text(),'invalid layout visible error');check(not errors,'strict CSP no JavaScript errors');browser.close()
 (OUT/'results.json').write_text(json.dumps({'passed':len(checks),'checks':checks},ensure_ascii=False,indent=2));print(str(len(checks))+' checks passed')
