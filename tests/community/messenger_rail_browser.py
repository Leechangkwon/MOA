"""Actual PHP shell/production assets, synthetic readonly API; never sends a message."""
import json,os,subprocess,hashlib
from pathlib import Path
from urllib.parse import urlsplit,parse_qs
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[2]
OUT=Path(os.environ.get('MESSENGER_RAIL_RESULTS','/tmp/messenger-rail'));OUT.mkdir(parents=True,exist_ok=True)
checks=[];requests=[];kind='employee';module='files';next_step=None
htmls={}
def check(ok,name):
 assert ok,name
 checks.append(name);print('PASS '+name,flush=True)
def render(k,m):
 if (k,m)not in htmls:htmls[k,m]=subprocess.check_output(['php',str(ROOT/'tests/community/messenger_rail_fixture.php'),k,m],text=True)
 return htmls[k,m]
with sync_playwright()as pw:
 browser=pw.chromium.launch();page=browser.new_page();errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
 def route(r):
  u=urlsplit(r.request.url);p=u.path;requests.append((r.request.method,p))
  if u.hostname!='rail.example':r.abort();return
  if p.startswith('/tbox/assets/'):
   f=ROOT/'public/assets'/p.removeprefix('/tbox/assets/');r.fulfill(body=f.read_bytes(),content_type={'.js':'text/javascript','.css':'text/css','.jpg':'image/jpeg','.png':'image/png'}.get(f.suffix,'application/octet-stream'));return
  if p=='/':r.fulfill(body=render(kind,module),content_type='text/html',headers={'Content-Security-Policy':"default-src 'self';script-src 'self';style-src 'self';img-src 'self' data:"});return
  if p=='/tbox/api/me':r.fulfill(json={'member_id':1001,'username':'synthetic_rail','display_name':'검토 사용자','csrf_token':'synthetic','role':'Guest'if kind=='guest'else'Employee','acting':kind=='acting','can_community_write':kind!='guest','next':next_step,'mfa_enabled':True});return
  if p=='/tbox/api/messages':r.fulfill(json={'people':[{'id':1002,'username':'synthetic_peer','display_name':'합성 상대','unread':3}]});return
  if p=='/tbox/messages':r.fulfill(body='<html lang="ko"><body><p>합성 기존 이용 안내</p></body></html>',content_type='text/html');return
  if r.request.method not in ['GET','HEAD']:r.fulfill(status=403,json={'error':'synthetic writes forbidden'});return
  r.fulfill(json={'items':[],'people':[],'pages':[],'threads':[],'folders':[],'files':[],'guides':[]})
 page.route('**/*',route)
 for module in ['files','community','story']:
  for kind in ['employee','guest','acting']:
   for width in [1280,960,959,390]:
    requests.clear();page.set_viewport_size({'width':width,'height':900});page.goto('https://rail.example/',wait_until='networkidle');tag=f'{module}-{kind}-{width}'
    check(page.locator('#sidebar [data-messenger-open],#sidebar nav[aria-label="메신저"]').count()==0,'no inner duplicate '+tag)
    check(page.locator('.app-rail__messenger:visible,#appbar-messenger:visible').count()==1,'one visible common entry '+tag)
    check(page.locator('.app-rail__divider').count()==1,'rail separates communication action '+tag)
    if not page.evaluate('document.documentElement.scrollWidth<=innerWidth'):
     page.screenshot(path=str(OUT/('overflow-'+tag+'.png')));print('OVERFLOW',page.evaluate('''()=>[...document.querySelectorAll('body *')].filter(n=>{const r=n.getBoundingClientRect();return r.width>0&&r.right>innerWidth+1}).map(n=>[n.tagName,n.id,n.className,n.getBoundingClientRect().right]).slice(0,12)'''),flush=True)
    check(page.evaluate('document.documentElement.scrollWidth<=innerWidth'),'no page overflow '+tag)
    check(page.locator('#messenger-open').count()==(1 if kind=='employee'else 0),'permitted opener ID remains unique '+tag)
    if kind=='employee':
     check(page.locator('.app-rail__messenger').evaluate('(n)=>n.tagName==="BUTTON"'),'rail action preserves page context '+tag)
     trigger=page.locator('#messenger-open'if width>=960 else'#appbar-messenger');trigger.click();page.wait_for_selector('#messenger-window:visible')
     check(page.locator('#messenger-window').evaluate('(n)=>n.open'),'production messenger opens '+tag)
     check(page.locator('#synthetic-context').input_value()=='검색·폴더 상태'and urlsplit(page.url).path=='/','folder/search context unchanged '+tag)
     check(trigger.get_attribute('aria-expanded')=='true','opener expanded state '+tag)
     check(trigger.locator('[data-message-unread]').inner_text()=='3','existing unread observer paints rail/mobile badge '+tag)
     check(not page.locator('#messenger-window').evaluate('(n)=>n.matches(":modal")'),'existing nonmodal window/sheet contract '+tag)
     page.keyboard.press('Escape');page.wait_for_timeout(100)
     check(not page.locator('#messenger-window').evaluate('(n)=>n.open'),'Escape closes '+tag)
     check(trigger.evaluate('(n)=>document.activeElement===n'),'focus returns to common opener '+tag)
    else:
     check(page.locator('.app-rail__messenger').get_attribute('href')=='/tbox/messages','restricted rail retains existing information route '+tag)
     check(page.locator('#messenger-window').count()==0,'restricted account has no floating window '+tag)
     check(not any(p.startswith('/tbox/api/messages')for _,p in requests),'restricted account sends no messaging API requests '+tag)
     if width<960:check(page.locator('#appbar-messenger').get_attribute('href')=='/tbox/messages','mobile restricted information entry retained '+tag)
     trigger=page.locator('.app-rail__messenger'if width>=960 else '#appbar-messenger');trigger.click();page.wait_for_selector('#messenger-unavailable:visible')
     check(page.locator('#messenger-unavailable').evaluate('(n)=>n.matches(":modal")'),'restricted entry opens existing information dialog '+tag)
     check(urlsplit(page.url).path=='/','restricted information retains current context '+tag)
     page.keyboard.press('Escape');page.wait_for_timeout(100);check(trigger.evaluate('(n)=>document.activeElement===n'),'restricted information focus returns '+tag)
    check(not any(method not in ['GET','HEAD']for method,_ in requests),'no message or other API write '+tag)
    if module=='files'and kind=='employee'and width in [1280,390]:page.screenshot(path=str(OUT/f'rail-{width}.png'))
 kind='employee';module='files';next_step='mfa_setup';requests.clear();page.goto('https://rail.example/',wait_until='networkidle')
 check(not any(p.startswith('/tbox/api/messages')for _,p in requests),'first-login MFA gate prevents messenger activation')
 check(not errors,'production JS no errors '+str(errors));browser.close()
(OUT/'results.json').write_text(json.dumps({'passed':len(checks),'checks':checks,'shell_sha256':hashlib.sha256((ROOT/'src/views/shell_top.php').read_bytes()).hexdigest()},ensure_ascii=False,indent=2));print(len(checks),'checks passed')
