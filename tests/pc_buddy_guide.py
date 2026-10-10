"""Rendered guide navigation in Chromium; no DB, installer or provider execution."""
import json,os,subprocess
from pathlib import Path
from urllib.parse import urlsplit
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[1]
OUT=Path(os.environ.get('GUIDE_RESULTS','/tmp/pc-buddy-guide'));OUT.mkdir(parents=True,exist_ok=True)
checks=[]
def check(name,value):
 checks.append(dict(name=name,passed=bool(value)));print(('PASS ' if value else 'FAIL ')+name,flush=True);assert value,name
def render(view):return subprocess.check_output(['php',str(ROOT/'tests/fixtures/moa_member_render.php'),view],text=True)
with sync_playwright() as pw:
 browser=pw.chromium.launch();ctx=browser.new_context();page=ctx.new_page();errors=[];unexpected=[]
 page.on('pageerror',lambda e:errors.append(str(e)))
 def route(r):
  u=urlsplit(r.request.url)
  if u.hostname!='moa.example':unexpected.append(r.request.url);r.abort();return
  if u.path=='/tbox/welcome':r.fulfill(content_type='text/html',body=render('buddy-guide' if u.query=='section=pc-assistant' else 'welcome'));return
  if u.path=='/tbox/api/me':r.fulfill(json=dict(member_id=1001,username='claude_employee01',csrf_token='synthetic',mfa_enabled=True,can_issue_tokens=True,next=None,token_scopes=['assistant.run']));return
  if u.path.startswith('/tbox/assets/'):
   f=ROOT/'public/assets'/u.path.removeprefix('/tbox/assets/')
   if f.is_file():r.fulfill(body=f.read_bytes(),content_type={'.js':'text/javascript','.css':'text/css','.svg':'image/svg+xml'}.get(f.suffix,'application/octet-stream'));return
  if u.path=='/favicon.ico':r.fulfill(status=204);return
  # Existing welcome uses optional setup APIs; keep this synthetic and offline.
  r.fulfill(json={})
 ctx.route('**/*',route)
 for width in (360,820,1280):
  page.set_viewport_size(dict(width=width,height=900));page.goto('https://moa.example/tbox/welcome?section=pc-assistant',wait_until='networkidle')
  check('focused title '+str(width),page.locator('h1').inner_text()=='PC 비서 연결하기')
  check('no security or token form in connection guide '+str(width),page.locator('#welcome-security,#welcome-token-form').count()==0)
  check('three functional choices '+str(width),page.locator('[aria-label="연결할 기능"] button').count()==3)
  page.locator('#pc-mode-gpt').click()
  check('no horizontal overflow '+str(width),page.evaluate('document.documentElement.scrollWidth<=innerWidth'))
  check('real app menu names and success condition '+str(width),'개인 ChatGPT · 텍스트 / 연결 설정' in page.inner_text('main') and 'PC 연결됨' in page.inner_text('main'))
  page.locator('a[href="#pc-connect"]').click();check('web connection step reachable '+str(width),page.locator('#pc-connect').is_visible())
  check('messenger opens keeping guide available '+str(width),page.locator('#pc-connect a[href="/tbox/messages"]').get_attribute('target')=='_blank')
  page.locator('#pc-install-guide > summary').click()
  page.locator('#pc-install summary').filter(has_text='처음 설치').click()
  check('new install exact commands '+str(width),'setup.cmd' in page.locator('#pc-install').inner_text() and '처음 설치할 때는 update.cmd를 쓰지 않습니다' in page.locator('#pc-install').inner_text())
  page.evaluate('scrollTo(0,0)');page.screenshot(path=str(OUT/('guide-'+str(width)+'.png')))
 page.goto('https://moa.example/tbox/welcome');check('existing onboarding remains separate',page.locator('#welcome-token-form').count()==1 and page.locator('#welcome-security').count()==1)
 check('no JS errors',not errors);check('no loopback, installer or provider calls',not unexpected)
 browser.close()
(OUT/'checks.json').write_text(json.dumps(checks,ensure_ascii=False,indent=2))
