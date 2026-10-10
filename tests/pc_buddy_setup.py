"""First-time onboarding with rendered PHP and synthetic APIs; never stores real secrets."""
import json,os,subprocess
from pathlib import Path
from urllib.parse import urlsplit
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[1]
OUT=Path(os.environ.get('SETUP_RESULTS','/tmp/pc-buddy-setup'));OUT.mkdir(parents=True,exist_ok=True)
checks=[]
def check(name,value):
 checks.append(dict(name=name,passed=bool(value)));print(('PASS ' if value else 'FAIL ')+name,flush=True);assert value,name
html=subprocess.check_output(['php',str(ROOT/'tests/fixtures/moa_member_render.php'),'buddy-guide'],text=True)
with sync_playwright() as pw:
 browser=pw.chromium.launch();ctx=browser.new_context(permissions=['clipboard-read','clipboard-write']);p=ctx.new_page();errors=[];external=[];posts=[]
 p.on('pageerror',lambda e:errors.append(str(e)))
 state=dict(mfa_enabled=True,can_issue_tokens=True,token_scopes=['read','upload','assistant.run'],csrf_token='synthetic-csrf',next=None)
 fail=False
 def route(r):
  global fail
  u=urlsplit(r.request.url)
  if u.hostname!='moa.example':external.append(r.request.url);r.abort();return
  if u.path=='/tbox/welcome':r.fulfill(content_type='text/html',body=html);return
  if u.path=='/tbox/api/me':r.fulfill(json=state);return
  if r.request.method=='POST':
   check('mutation carries CSRF',r.request.headers.get('x-csrf-token')=='synthetic-csrf');posts.append((u.path,r.request.post_data_json))
   if u.path.endswith('/mfa/enroll'):r.fulfill(json=dict(secret='SYNTHETICSECRET',qr=dict(rows=['010','111','010'])));return
   if u.path.endswith('/mfa/confirm'):state.update(mfa_enabled=True,can_issue_tokens=True);r.fulfill(json=dict(ok=True));return
   if u.path.endswith('/api-tokens'):
    if fail:r.fulfill(status=400,json=dict(error='잘못된 요청입니다'));return
    r.fulfill(status=201,json=dict(token='SYNTHETIC_TOKEN_ONLY',expires_at='2027-04-01T00:00:00Z'));return
   raise AssertionError('unexpected mutation '+u.path)
  if u.path.startswith('/tbox/assets/'):
   f=ROOT/'public/assets'/u.path.removeprefix('/tbox/assets/')
   if f.is_file():r.fulfill(body=f.read_bytes(),content_type={'.js':'text/javascript','.css':'text/css','.svg':'image/svg+xml'}.get(f.suffix,'application/octet-stream'));return
  r.fulfill(status=204)
 ctx.route('**/*',route)
 def visit():
  p.goto('https://moa.example/tbox/welcome?section=pc-assistant',wait_until='networkidle')
 def issue():
  p.locator('#pc-token-password').fill('synthetic-password');p.locator('#pc-token-otp').fill('123456');p.locator('#pc-token-issue').click();p.wait_for_function("!document.getElementById('pc-mode-files').disabled")
 for width in (360,820,1280):
  p.set_viewport_size(dict(width=width,height=900));visit()
  check('default Gemini issuer '+str(width),p.locator('#pc-token-form').is_visible() and not p.locator('#pc-gpt-guide').is_visible())
  check('no initial mutation '+str(width),not posts)
  check('no horizontal overflow '+str(width),p.evaluate('document.documentElement.scrollWidth<=innerWidth'))
  p.screenshot(path=str(OUT/('setup-'+str(width)+'.png')))
 issue()
 check('AI scope only',posts[-1][1]['scope']=='assistant.run')
 check('credentials cleared',p.locator('#pc-token-password').input_value()==p.locator('#pc-token-otp').input_value()=='')
 check('exact AI paste destination',p.locator('#pc-token-menu').inner_text()=='비서 실행용 토큰 등록')
 check('issued not claimed installed','발급 완료' in p.locator('#pc-setup-status').inner_text() and '자동으로 확인하지 않습니다' in p.locator('#pc-token-result').inner_text())
 check('issuer hidden after success',not p.locator('#pc-token-form').is_visible())
 p.locator('#pc-token-copy').click();check('clipboard copied',p.evaluate('navigator.clipboard.readText()')=='SYNTHETIC_TOKEN_ONLY')
 check('no persisted secrets',p.evaluate("!JSON.stringify([localStorage,sessionStorage]).includes('SYNTHETIC_TOKEN_ONLY')") and 'SYNTHETIC' not in p.url)
 p.locator('#pc-mode-files').click();check('result cleared when switching',p.locator('#pc-token-value').input_value()=='')
 issue();check('file read default',posts[-1][1]['scope']=='read')
 check('exact file paste destination',p.locator('#pc-token-menu').inner_text()=='API 토큰 변경')
 p.locator('#pc-mode-ai').click();p.locator('#pc-mode-files').click();p.locator('#pc-upload').check();issue();check('upload explicit opt in',posts[-1][1]['scope']=='read+upload')
 p.locator('#pc-mode-gpt').click();count=len(posts);check('GPT separate without token form',p.locator('#pc-gpt-guide').is_visible() and not p.locator('#pc-token-setup').is_visible() and len(posts)==count)
 state.update(token_scopes=['read']);visit();check('unavailable AI explained',p.locator('#pc-token-unavailable').is_visible() and not p.locator('#pc-token-form').is_visible())
 p.locator('#pc-mode-files').click();check('restricted account no upload',not p.locator('#pc-upload-field').is_visible())
 state.update(mfa_enabled=False,can_issue_tokens=False,token_scopes=['assistant.run','read']);visit()
 check('OTP registration before issue',p.locator('#pc-otp-setup').is_visible() and not p.locator('#pc-token-form').is_visible())
 p.locator('#pc-otp-start').click();p.locator('#pc-otp-enrollment').wait_for(state='visible');check('inline enrollment',p.locator('#pc-otp-secret').text_content()=='SYNTHETICSECRET')
 check('OTP screen no overflow',p.evaluate('document.documentElement.scrollWidth<=innerWidth'))
 p.locator('#pc-otp-code').fill('654321');p.locator('#pc-otp-form button').click();p.locator('#pc-token-form').wait_for(state='visible')
 check('OTP secret cleared',p.locator('#pc-otp-secret').text_content()=='')
 check('fresh code after enrollment','숫자가 바뀌면' in p.locator('#pc-setup-status').inner_text())
 check('enrollment does not auto issue',posts[-1][0].endswith('/mfa/confirm'))
 fail=True;issue();check('failure visible and retry possible',p.locator('#pc-setup-status').inner_text()=='잘못된 요청입니다' and p.locator('#pc-token-form').is_visible())
 check('failure clears credentials',p.locator('#pc-token-password').input_value()==p.locator('#pc-token-otp').input_value()=='')
 fail=False
 # Delay a response so both repeated submit and switching are tested while pending.
 held=[]
 def hold(r):held.append(r)
 p.route('**/api/me/api-tokens',hold)
 p.locator('#pc-token-password').fill('synthetic-password');p.locator('#pc-token-otp').fill('123456');p.locator('#pc-token-issue').click()
 p.wait_for_timeout(100)
 p.evaluate("document.getElementById('pc-token-form').dispatchEvent(new Event('submit',{cancelable:true}))")
 check('in-flight duplicate prevented',len(held)==1 and p.locator('#pc-mode-gpt').is_disabled())
 held[0].fulfill(status=201,json=dict(token='SYNTHETIC_TOKEN_ONLY',expires_at='2027-04-01T00:00:00Z'))
 p.locator('#pc-token-result').wait_for(state='visible');p.evaluate("dispatchEvent(new PageTransitionEvent('pagehide'))")
 check('page exit clears token and enrollment',p.locator('#pc-token-value').input_value()=='' and p.locator('#pc-otp-secret').text_content()=='')
 check('no JS errors',not errors);check('no external or loopback calls',not external)
 browser.close()
(OUT/'checks.json').write_text(json.dumps(checks,ensure_ascii=False,indent=2))
