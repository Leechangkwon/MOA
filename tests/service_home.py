"""Rendered PHP + browser navigation and color checks, with synthetic readonly API responses."""
import json,os,subprocess
from pathlib import Path
from urllib.parse import urlsplit,parse_qs
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[1]
OUT=Path(os.environ.get('HOME_RESULTS','/tmp/service-home'));OUT.mkdir(parents=True,exist_ok=True)
checks=[]
def check(ok,label):
 checks.append(dict(name=label,passed=bool(ok)));print(('PASS ' if ok else 'FAIL ')+label,flush=True)
 assert ok,label
def render(view,arg='signed'):return subprocess.check_output(['php',str(ROOT/'tests/fixtures/service_home_render.php'),view,arg],text=True)
with sync_playwright() as pw:
 b=pw.chromium.launch();ctx=b.new_context();p=ctx.new_page();errors=[];calls=[]
 p.on('pageerror',lambda e:errors.append(str(e)))
 def route(r):
  u=urlsplit(r.request.url);calls.append(u.path)
  if u.hostname!='moa.example':r.abort();return
  if u.path=='/':r.fulfill(body=render('home','anon' if u.query=='anon' else 'signed'),content_type='text/html');return
  if u.path=='/tbox/login':r.fulfill(body=render('login',parse_qs(u.query).get('to',['default'])[0]),content_type='text/html');return
  if u.path.startswith('/tbox/assets/'):
   f=ROOT/'public/assets'/u.path.removeprefix('/tbox/assets/');r.fulfill(body=f.read_bytes(),content_type={'.js':'text/javascript','.css':'text/css','.jpg':'image/jpeg'}.get(f.suffix,'application/octet-stream'));return
  if u.path=='/tbox/api/login':r.fulfill(json={'ok':True});return
  if u.path=='/tbox/api/me':r.fulfill(json={'member_id':1001,'next':None,'csrf_token':'synthetic','mfa_enabled':True});return
  if u.path=='/community/api/boards':r.fulfill(json={'boards':[]});return
  r.fulfill(json={'pages':[],'files':[]})
 ctx.route('**/*',route)
 for to,want in [('default','/'),('home','/'),('tbox','/tbox/'),('community','/community/'),('/community/memories','/community/memories'),('/tbox/?q=hello','/tbox/?q=hello'),('/tbox/welcome?section=pc-assistant','/tbox/welcome?section=pc-assistant'),('/tbox/welcome?section=bad','/'),('/community/my-tasks','/community/my-tasks'),('https://evil.example','/'),('//evil.example','/'),('array','/')]:
  from urllib.parse import quote
  p.goto('https://moa.example/tbox/login?to='+quote(to,safe=''),wait_until='networkidle')
  check(p.evaluate('loginDestination()')==want,'login target '+to)
  # Passkey success calls the same exported function and really navigates.
  p.evaluate('window.qarediLoginGo()');p.wait_for_url('https://moa.example'+want)
  check(urlsplit(p.url).path==urlsplit(want).path,'shared success navigation '+to)
 p.goto('https://moa.example/tbox/login',wait_until='networkidle')
 p.locator('input[name=username]').fill('synthetic');p.locator('input[name=password]').fill('synthetic-test-password')
 p.locator('#login-form button[type=submit]').click();p.wait_for_url('https://moa.example/')
 check(p.locator('.moa-services a').count()==4,'password login arrives at service home')
 for width in (360,390,820,1280):
  for theme in ('light','dark'):
   p.set_viewport_size(dict(width=width,height=900));p.goto('https://moa.example/',wait_until='networkidle');p.evaluate('(t)=>document.documentElement.dataset.theme=t',theme)
   check(p.evaluate('document.documentElement.scrollWidth<=innerWidth'),'home no overflow '+str(width)+theme)
   check(not p.locator('#home-stories').get_attribute('open'),'stories initially collapsed '+str(width)+theme)
   check(p.locator('#q-start').is_visible(),'recent work visible '+str(width)+theme)
   check(p.locator('.moa-service-card').evaluate_all('(els)=>new Set(els.map(e=>getComputedStyle(e).borderTopColor)).size')==4,'four distinct service colors '+str(width)+theme)
   p.screenshot(path=str(OUT/f'home-{width}-{theme}.png'),full_page=True)
 calls.clear();p.reload(wait_until='networkidle');check('/community/api/boards' not in calls,'no feed request while collapsed')
 p.locator('#home-stories>summary').click();p.wait_for_timeout(100);check('/community/api/boards' in calls,'existing feed loads on expansion')
 p.locator('[data-story=lake]').click();check(p.locator('#moa-story-dialog').is_visible(),'reading dialog preserved');p.keyboard.press('Escape')
 p.goto('https://moa.example/?anon',wait_until='networkidle');check(p.locator('#q-start,#moa-feed').count()==0,'anonymous personal data absent')
 check(p.locator('a[href="/tbox/login"]').count()==1,'general login has no community target')
 for service in ('work','library','assistant','story'):
  p.evaluate('(s)=>document.body.dataset.service=s',service)
  check(p.evaluate('getComputedStyle(document.body).getPropertyValue("--primary").trim()')!='','shell service token '+service)
 p.evaluate('document.documentElement.dataset.contrast="high"');check(p.locator('.moa-service-card').first.evaluate('(e)=>getComputedStyle(e).backgroundImage')=='none','high contrast removes decorative backgrounds')
 check(not errors,'no browser errors '+str(errors))
 check(json.loads(render('manifest'))['start_url']=='/','installed app starts at home')
 check(json.loads(render('manifest'))['id']=='/community/','installed app identity retained')
 b.close()
(OUT/'checks.json').write_text(json.dumps(checks,ensure_ascii=False,indent=2))
print('PASS',len(checks))
