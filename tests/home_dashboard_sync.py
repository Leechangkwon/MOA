"""Server preference synchronization using rendered PHP and synthetic transport."""
import json,subprocess
from pathlib import Path
from urllib.parse import urlsplit
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[1]
layout={'version':1,'widgets':[{'id':i,'visible':i!='tools','size':'wide'}for i in ['recent','services','tools','stories']],'source':'all','view':'recent'}
checks=0;revision=3;fail=False;calls=[]
def check(value,label):
 global checks
 assert value,label;checks+=1;print('PASS '+label)
with sync_playwright()as pw:
 browser=pw.chromium.launch();page=browser.new_page();errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
 def route(r):
  global layout,revision
  p=urlsplit(r.request.url).path;calls.append((r.request.method,p))
  if p=='/':r.fulfill(body=subprocess.check_output(['php',str(ROOT/'tests/fixtures/service_home_render.php'),'home','signed'],text=True),content_type='text/html');return
  if p.startswith('/tbox/assets/'):
   f=ROOT/'public/assets'/p.removeprefix('/tbox/assets/');r.fulfill(body=f.read_bytes(),content_type='text/javascript'if f.suffix=='.js'else'text/css');return
  if p=='/tbox/api/me':
   if sum(1 for _,path in calls if path=='/tbox/api/me')==1:check(page.locator('#home-edit').is_disabled(),'editor disabled during account synchronization')
   r.fulfill(json={'member_id':1001,'next':None,'csrf_token':'synthetic'});return
  if p=='/tbox/api/me/product-home':r.fulfill(json={'widgets':{'task':[{'space_id':'11111111-1111-4111-8111-111111111111','space_name':'회사 공간','title':'준비 작업','updated_at':'2026-10-11'}],'notice':[],'event':[]}});return
  if p=='/tbox/api/me/home-dashboard':
   if r.request.method=='PATCH':
    if fail:r.fulfill(status=409,json={'error':'conflict'});return
    check(r.request.post_data_json['revision']==revision,'correct revision transmitted');layout=r.request.post_data_json['layout'];revision+=1
   r.fulfill(json={'layout':layout,'revision':revision});return
  r.fulfill(json={'files':[],'pages':[],'boards':[]})
 page.route('**/*',route);page.goto('https://moa.example/',wait_until='networkidle')
 check(not page.locator('[data-home-widget=tools]').is_visible(),'server saved layout applied');check('회사 공간'in page.locator('#product-home').inner_text(),'authorized cross-space activity rendered');check(page.locator('#product-home a').get_attribute('href').endswith('/spaces?space=11111111-1111-4111-8111-111111111111'),'activity opens correct space')
 check('계정에 저장된'in page.locator('#home-layout-status').inner_text(),'account sync clearly identified')
 page.locator('#home-edit').click();page.get_by_label('위젯 추가',exact=True).select_option('tools');page.get_by_role('button',name='저장',exact=True).click();page.wait_for_timeout(100)
 check(page.locator('[data-home-widget=tools]').is_visible(),'account layout save applied')
 check(page.evaluate('localStorage.getItem("moa-home:v1:1001")')is None,'server save does not silently mirror or overwrite browser migration data')
 other=browser.new_page();other.route('**/*',route);other.goto('https://moa.example/',wait_until='networkidle');check(other.locator('[data-home-widget=tools]').is_visible(),'second browser context reads synchronized layout');other.close()
 page.reload(wait_until='networkidle');check(page.locator('[data-home-widget=tools]').is_visible(),'server layout survives reload')
 fail=True;page.locator('#home-edit').click();page.get_by_role('button',name='연락과 시작 안내 숨기기',exact=True).click();page.get_by_role('button',name='저장',exact=True).click();page.wait_for_timeout(100)
 check(page.locator('.dashboard-editor').is_visible(),'conflicting save retains draft')
 check(not page.locator('[data-home-widget=tools]').is_visible(),'failed save keeps live draft visible')
 check('다른 기기'in page.locator('.dashboard-editor [role=status]').inner_text(),'conflict recovery message');check(layout['widgets'][2]['visible'],'conflict leaves server data unchanged')
 check(not errors,'no browser errors');browser.close()
print(str(checks)+' checks passed')
