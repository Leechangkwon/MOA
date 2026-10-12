"""Readonly synthetic mobile entry diagnosis: actual PHP views/defaults and production assets."""
import hashlib,json,os,subprocess
from pathlib import Path
from urllib.parse import urlsplit,parse_qs,quote
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[1]
OUT=Path(os.environ.get('NEUTRAL_ENTRY_RESULTS','/tmp/neutral-home-entry'));OUT.mkdir(parents=True,exist_ok=True)
MODE=os.environ.get('NEUTRAL_ENTRY_CSS','current')
DESIGN_CSS=subprocess.check_output(['git','show','HEAD:public/assets/design.css'],cwd=ROOT)if MODE=='baseline'else(ROOT/'public/assets/design.css').read_bytes()
checks=[];observations=[];requests=[];session='signed'
def check(ok,label):
 assert ok,label
 checks.append(label);print('PASS '+label,flush=True)
def render(view='home',arg='signed'):
 return subprocess.check_output(['php',str(ROOT/'tests/fixtures/service_home_render.php'),view,arg],text=True)
layout=json.loads(subprocess.check_output(['php','-r','require "src/site/ProductSpaces.php";echo json_encode(ProductSpaces::defaultWidgetLayout());'],cwd=ROOT,text=True))
manifest=json.loads(render('manifest'))
check(manifest['start_url']=='/','actual manifest starts at root home')
check(manifest['id']=='/community/'and manifest['scope']=='/','existing installed identity and scope retained')
with sync_playwright()as pw:
 browser=pw.chromium.launch();page=browser.new_page(viewport={'width':390,'height':844});errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
 def route(r):
  global session
  u=urlsplit(r.request.url);p=u.path;requests.append((r.request.method,p))
  if u.hostname!='entry.example':r.abort();return
  if p=='/':r.fulfill(body=render(arg=session),content_type='text/html',headers={'Content-Security-Policy':"default-src 'self';script-src 'self';style-src 'self';img-src 'self' data:"});return
  if p=='/tbox/login':r.fulfill(body=render('login',parse_qs(u.query).get('to',['default'])[0]),content_type='text/html',headers={'Content-Security-Policy':"default-src 'self';script-src 'self';style-src 'self';img-src 'self' data:"});return
  if p.startswith('/tbox/assets/'):
   f=ROOT/'public/assets'/p.removeprefix('/tbox/assets/');r.fulfill(body=DESIGN_CSS if f.name=='design.css'else f.read_bytes(),content_type={'.js':'text/javascript','.css':'text/css','.jpg':'image/jpeg','.png':'image/png'}.get(f.suffix,'application/octet-stream'));return
  if p=='/tbox/consent':r.fulfill(body='<html lang="ko"><body><h1>합성 기존 동의 관문</h1></body></html>',content_type='text/html');return
  if p=='/tbox/api/login':session='signed';r.fulfill(json={'ok':True,'next':None});return
  if p=='/tbox/api/me':r.fulfill(json={'member_id':1001,'csrf_token':'synthetic','next':None,'mfa_enabled':True});return
  if p=='/tbox/api/me/home-dashboard':r.fulfill(json={'layout':layout,'revision':0});return
  if p=='/tbox/api/product-spaces':r.fulfill(json={'spaces':[]});return
  if p.startswith('/tbox/api/files/'):r.fulfill(json={'files':[{'id':'synthetic-file','name':'합성 자료','last_used_at':'2026-10-12','scan_status':'clean','folder_path':'검토'}]});return
  if p.startswith('/community/api/me/'):r.fulfill(json={'pages':[]});return
  if p=='/community/api/briefing':r.fulfill(json={'top':[],'missed':[],'others':[]});return
  if p in ['/tbox/','/community/','/community/my-tasks']:
   r.fulfill(body='<html lang="ko"><body><p>합성 복귀 목적지</p></body></html>',content_type='text/html');return
  r.fulfill(json={'items':[],'files':[],'folders':[],'pages':[]})
 page.route('**/*',route)
 for width in [360,390,1280]:
  page.set_viewport_size({'width':width,'height':844 if width<500 else 900})
  for kind in ['anon','signed','consent']:
   session=kind;requests.clear();page.goto('https://entry.example/',wait_until='networkidle')
   check(page.evaluate('document.documentElement.scrollWidth<=innerWidth'),'entry no page overflow '+kind+' '+str(width))
   check(page.locator('#home-edit').count()==(1 if kind=='signed'else 0),'dashboard entry gated by live session '+kind+' '+str(width))
   check(page.locator('.moa-service-hero').count()==(0 if kind=='signed'else 1),'intro shown only without live session '+kind+' '+str(width))
   check(not any(p=='/tbox/api/me/home-dashboard'for _,p in requests)if kind!='signed'else any(p=='/tbox/api/me/home-dashboard'for _,p in requests),'private layout request only authenticated consented '+kind+' '+str(width))
   if kind=='signed':
    check(page.locator('.dashboard-card').count()==3,'actual generic default three widgets '+str(width))
    y=page.locator('.dashboard-card').first.bounding_box()['y'];check(y<=(340 if width<500 else 300),'first default widget near top '+str(width))
    check(page.locator('#dashboard-services a').count()==6,'default shortcuts retained '+str(width))
    check(not page.locator('.dashboard-guide').evaluate('(n)=>n.open'),'default guide closed '+str(width))
   else:y=None
   observations.append({'width':width,'session':kind,'first_widget_y':y,'hero_top':page.locator('.moa-service-hero').bounding_box()['y']if kind!='signed'else None,'body_class':page.locator('body').get_attribute('class')})
   for design in ['aurora','bloom']:
    for theme in ['light','dark']:
     page.evaluate('(v)=>{document.documentElement.dataset.design=v[0];document.documentElement.dataset.theme=v[1]}',[design,theme]);page.wait_for_timeout(300)
     if width in [390,1280]:page.screenshot(path=str(OUT/f'{MODE}-{kind}-{design}-{theme}-{width}.png'))
 original_layout=json.loads(json.dumps(layout));session='signed'
 layout={'version':1,'widgets':[{'id':i,'visible':True,'size':'wide'}for i in ['recent','services','tools','stories']],'source':'all','view':'recent'}
 for width in [390,1280]:
  page.set_viewport_size({'width':width,'height':844 if width<500 else 900});requests.clear();page.goto('https://entry.example/',wait_until='networkidle')
  check(page.locator('.dashboard-card').count()==0,'saved legacy v1 uses preserved original DOM '+str(width))
  check(page.locator('#q-start').is_visible(),'legacy recent module remains visible '+str(width))
  check('최근 작업' in page.locator('#q-start').inner_text(),'legacy module retains original title '+str(width))
  check(not any(method=='PATCH'for method,_ in requests),'legacy read does not migrate preferences '+str(width))
  for design in ['aurora','bloom']:
   for theme in ['light','dark']:
    page.evaluate('(v)=>{document.documentElement.dataset.design=v[0];document.documentElement.dataset.theme=v[1]}',[design,theme]);page.wait_for_timeout(300)
    page.screenshot(path=str(OUT/f'{MODE}-legacy-v1-{design}-{theme}-{width}.png'))
 layout=original_layout
 session='consent';page.goto('https://entry.example/',wait_until='networkidle')
 check(page.locator('#home-open-dashboard').get_attribute('href')=='/tbox/consent','consent CTA preserves mandatory consent route')
 check(page.locator('#home-open-dashboard').inner_text().startswith('동의하고 내 홈 열기'),'consent CTA explains gate')
 page.locator('#home-open-dashboard').click();page.wait_for_url('https://entry.example/tbox/consent')
 check(urlsplit(page.url).path=='/tbox/consent','actual consent CTA enters existing gate')
 session='anon';page.goto('https://entry.example/',wait_until='networkidle')
 check(page.locator('#home-open-dashboard').get_attribute('href')=='/tbox/login?to=home','anonymous primary CTA explicitly requests personal home')
 check('로그인하면 내 위젯과 바로가기가 열려요.'in page.locator('.moa-service-hero').inner_text(),'anonymous CTA explains dashboard after login')
 check(page.locator('.moa-nav a[href="#services"]').count()==1,'public service navigation remains available')
 page.locator('#home-open-dashboard').click();page.wait_for_url('https://entry.example/tbox/login?to=home')
 check(page.evaluate('loginDestination()')=='/','actual anonymous CTA login destination is root')
 session='anon';page.set_viewport_size({'width':390,'height':844});page.goto('https://entry.example/tbox/login',wait_until='networkidle')
 check(page.evaluate('loginDestination()')=='/','mobile default login destination root')
 page.locator('input[name=username]').fill('synthetic');page.locator('input[name=password]').fill('synthetic-password');page.locator('#login-form button[type=submit]').click();page.wait_for_url('https://entry.example/');page.wait_for_selector('.dashboard-card')
 check(page.locator('#home-edit').is_visible(),'actual password flow reaches signed dashboard on mobile')
 for target,want in [('home','/'),('tbox','/tbox/'),('community','/community/'),('/community/my-tasks','/community/my-tasks')]:
  page.goto('https://entry.example/tbox/login?to='+quote(target,safe=''),wait_until='networkidle')
  check(page.evaluate('loginDestination()')==want,'explicit target retained '+target)
  page.evaluate('window.qarediLoginGo()');page.wait_for_url('https://entry.example'+want)
  check(urlsplit(page.url).path==want,'actual shared success target navigation '+target)
 check(not errors,'no production JavaScript errors '+str(errors))
 browser.close()
(OUT/'results.json').write_text(json.dumps({'passed':len(checks),'checks':checks,'observations':observations,'css_mode':MODE,'design_css_sha256':hashlib.sha256(DESIGN_CSS).hexdigest(),'moa_css_sha256':hashlib.sha256((ROOT/'public/assets/moa.css').read_bytes()).hexdigest()},ensure_ascii=False,indent=2));print(len(checks),'checks passed')
