"""Synthetic rendered launcher: configurable home and readonly API security boundaries."""
import json, os, subprocess
from pathlib import Path
from urllib.parse import urlsplit
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[1]
OUT=Path(os.environ.get('HOME_RESULTS','/tmp/group-dashboard-checks'));OUT.mkdir(parents=True,exist_ok=True)
checks=[]
def check(value,label):
 checks.append({'name':label,'passed':bool(value)});print(('PASS ' if value else 'FAIL ')+label,flush=True);assert value,label
def html(mode='signed'):
 return subprocess.check_output(['php',str(ROOT/'tests/fixtures/service_home_render.php'),'home',mode],text=True)
FILE='11111111-1111-4111-8111-111111111111';PAGE='22222222-2222-4222-8222-222222222222'
with sync_playwright() as pw:
 browser=pw.chromium.launch();context=browser.new_context();page=context.new_page();calls=[];errors=[]
 page.on('pageerror',lambda e:errors.append(str(e)))
 def route(r):
  u=urlsplit(r.request.url);calls.append(u.path)
  if u.hostname!='moa.example':r.abort();return
  if u.path=='/':r.fulfill(body=html(u.query or 'signed'),content_type='text/html');return
  if u.path.startswith('/tbox/assets/'):
   f=ROOT/'public/assets'/u.path.removeprefix('/tbox/assets/');r.fulfill(body=f.read_bytes(),content_type={'.js':'text/javascript','.css':'text/css','.jpg':'image/jpeg'}.get(f.suffix,'application/octet-stream'));return
  if u.path=='/tbox/api/me':r.fulfill(json={'member_id':1001,'next':None});return
  if u.path.startswith('/tbox/api/files/'):
   r.fulfill(json={'files':[{'id':FILE,'name':'<img src=x onerror=alert(1)>','last_used_at':'2026-10-11'},{'id':'bad','name':'INVALID'}]});return
  if u.path.startswith('/community/api/me/'):
   r.fulfill(json={'pages':[{'id':PAGE,'title':'테스트 문서','last_viewed_at':'2026-10-10'}]});return
  if u.path=='/community/api/boards':r.fulfill(json={'boards':[]});return
  r.fulfill(json={})
 context.route('**/*',route)
 def visit(mode='signed'):page.goto('https://moa.example/'+('' if mode=='signed' else '?'+mode),wait_until='networkidle')
 def edit():page.locator('#home-edit').click()
 def save():page.locator('#home-editor button[type=submit]').click();page.wait_for_timeout(100)
 visit();check(page.locator('#q-start-list a').count()==2,'authorized synthetic items rendered; malformed ID ignored')
 check(page.locator('#q-start-list img').count()==0,'titles are text without HTML execution')
 check(page.locator('#home-widgets').evaluate('(n)=>n.firstElementChild.dataset.homeWidget')=='recent','recent first by default')
 edit();page.locator('[data-home-toggle=tools]').uncheck();page.get_by_label('서비스 바로가기 카드 폭').select_option('small');page.get_by_label('최근 작업 자료 선택').select_option('files');page.get_by_label('처음 표시할 목록').select_option('favorites');page.get_by_role('button',name='서비스 바로가기 위로',exact=True).click();save()
 check(not page.locator('[data-home-widget=tools]').is_visible(),'hide widget applies')
 check(page.locator('#home-widgets').evaluate('(n)=>n.firstElementChild.dataset.homeWidget')=='services','keyboard compatible reorder applies')
 check(page.locator('#q-start-list a').count()==1,'files source filters endpoints and result')
 check(page.locator('[data-start-view=favorites]').get_attribute('aria-pressed')=='true','configured favorite default')
 stored=page.evaluate('JSON.parse(localStorage.getItem("moa-home:v1:1001"))')
 check(set(stored)=={'version','widgets','source','view'} and '테스트' not in json.dumps(stored),'only layout prefs stored')
 visit();check(not page.locator('[data-home-widget=tools]').is_visible(),'layout persists after reload')
 calls.clear();visit();check('/community/api/me/favorites' not in calls,'unselected source not requested')
 edit();page.locator('[data-home-toggle=tools]').check();page.keyboard.press('Escape');check(not page.locator('[data-home-widget=tools]').is_visible(),'Escape cancels draft')
 check(page.locator('#home-edit').evaluate('(n)=>n===document.activeElement'),'focus returns to opener')
 edit();page.locator('#home-reset').click();page.locator('#home-editor-close').click();check(not page.locator('[data-home-widget=tools]').is_visible(),'reset remains draft until save')
 edit();page.locator('#home-reset').click();save();check(page.locator('[data-home-widget=tools]').is_visible(),'reset save restores widgets')
 for width in [360,390,820,1280]:
  for theme in ['light','dark']:
   page.set_viewport_size({'width':width,'height':900});page.evaluate('(t)=>document.documentElement.dataset.theme=t',theme)
   check(page.evaluate('document.documentElement.scrollWidth<=innerWidth'),'no overflow '+str(width)+theme)
   edit();check(page.locator('#home-editor').is_visible(),'editor visible '+str(width)+theme)
   check(page.locator('#home-editor').evaluate('(n)=>n.scrollWidth<=n.clientWidth'),'editor no horizontal overflow '+str(width)+theme)
   page.keyboard.press('Escape');page.screenshot(path=str(OUT/f'home-{width}-{theme}.png'),full_page=True)
 # Every small widget is exercised, including expanded story content.
 edit()
 for title in ['최근 작업 · 즐겨찾기','서비스 바로가기','연락과 시작 안내','이야기와 읽을거리']:
  page.get_by_label(title+' 카드 폭').select_option('small')
 save();page.locator('#home-stories>summary').click();page.wait_for_timeout(100)
 for width in [390,1280]:
  page.set_viewport_size({'width':width,'height':900})
  check(page.evaluate('document.documentElement.scrollWidth<=innerWidth'),'small widgets no overflow '+str(width))
  page.screenshot(path=str(OUT/f'home-small-{width}.png'),full_page=True)
 page.evaluate('document.documentElement.dataset.contrast="high"');edit()
 check(page.locator('#home-editor').is_visible(),'high contrast editor works');page.keyboard.press('Escape')
 edit()
 for widget in ['recent','services','tools','stories']:page.locator('[data-home-toggle='+widget+']').uncheck()
 save();check('표시할 위젯이 없습니다' in page.locator('.notice').inner_text(),'all hidden empty state')
 calls.clear();visit();check('/tbox/api/me' not in calls,'hidden recent sends no request')
 page.locator('header a[href="#services"]').click();check(page.locator('#services').is_visible(),'service navigation temporarily reveals hidden services')
 check(not page.evaluate('JSON.parse(localStorage.getItem("moa-home:v1:1001")).widgets.find(w=>w.id==="services").visible'),'temporary reveal leaves stored layout unchanged')
 edit();page.locator('#home-reset').click();save()
 # Invalid settings are discarded, not partially used.
 page.evaluate('localStorage.setItem("moa-home:v1:1001",JSON.stringify({version:1,widgets:[{id:"evil"}],source:"evil",view:"bad"}))');visit()
 check(page.locator('#home-layout-status').inner_text().find('기본 홈')>=0,'malformed preferences recover')
 check(page.locator('#q-start-list a').count()==2,'malformed source cannot alter API target')
 visit('other');check(page.locator('[data-home-widget=tools]').is_visible(),'other account has independent default')
 check(page.locator('#home-widgets').get_attribute('data-owner')=='1002','owner from server fixture')
 for mode in ['anon','consent']:
  calls.clear();visit(mode);check(page.locator('#home-edit,#home-editor,#home-widgets').count()==0,'no editor for '+mode)
  check('/tbox/api/me' not in calls,'no personal fetch for '+mode)
 # The first-login server gate remains mandatory.
 context.unroute('**/*',route)
 def gated(r):
  if urlsplit(r.request.url).path=='/tbox/api/me':r.fulfill(json={'next':'/tbox/consent'});return
  route(r)
 context.route('**/*',gated);calls.clear();visit();check(page.locator('#q-start-list a').count()==0,'first login gate blocks items')
 check(not any('/api/files/' in p for p in calls),'gate prevents downstream requests')
 context.unroute('**/*',gated);context.route('**/*',route)
 page.add_init_script("Object.defineProperty(window,'localStorage',{get(){throw new Error('blocked')}})");visit();edit();save()
 check('저장하지 못했습니다' in page.locator('#home-layout-status').inner_text(),'blocked storage honestly reports unsaved state')
 check(not errors,'no uncaught browser errors')
 browser.close()
(OUT/'checks.json').write_text(json.dumps(checks,ensure_ascii=False,indent=2));print('PASS',len(checks))
