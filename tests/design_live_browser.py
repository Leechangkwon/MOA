"""Live original scripts/API included; readonly navigation and visual preferences only."""
import json, os, sys
from pathlib import Path
from playwright.sync_api import sync_playwright, expect

SITE='http://127.0.0.1:8082'
OUT=Path(os.environ.get('DESIGN_LIVE_RESULTS','/tmp/qaredi-design-live')); OUT.mkdir(parents=True,exist_ok=True)
cookies=sys.stdin.readline().strip()
errors, failures, requests, mutations, checks=[],[],[],[],0
def check(name,value):
 global checks
 checks+=1
 if not value:failures.append(name)
with sync_playwright() as p:
 browser=p.chromium.launch()
 context=browser.new_context(user_agent='tbox-review/2026-10-01',viewport={'width':1440,'height':1000})
 for part in cookies.split(';'):
  if '=' in part:
   k,v=part.split('=',1);k=k.strip()
   cookie={'name':k,'value':v,'domain':'127.0.0.1','path':'/','secure':True,'httpOnly':True,'sameSite':'Lax'} if k.startswith('__Host-') else {'name':k,'value':v,'url':SITE}
   context.add_cookies([cookie])
 def readonly(route):
  if route.request.method not in ['GET','HEAD']:
   mutations.append(route.request.method+' '+route.request.url.split('?')[0]);route.abort()
  else:route.continue_()
 context.route('**/api/**',readonly)
 page=context.new_page();page.on('pageerror',lambda e:errors.append(str(e)))
 page.on('console',lambda m:errors.append(m.text) if m.type=='error' else None)
 page.on('response',lambda r:requests.append(r.url) if r.status>=500 else None)
 themes=os.environ.get('DESIGN_LIVE_THEMES','claude,astra,aurora,bloom,nova').split(',')
 assert themes and all(t in ['claude','astra','aurora','bloom','nova'] for t in themes)
 for theme in themes:
  for brightness in ['light','dark']:
   context.add_cookies([{'name':'qaredi_design','value':theme,'url':SITE},{'name':'tbox_theme','value':brightness,'url':SITE}])
   for width in [360,1440]:
    page.set_viewport_size({'width':width,'height':1000})
    for path in ['/','/tbox/','/community/','/tbox/settings']:
     tag=f'{theme}/{brightness}/{width}{path}'
     response=page.goto(SITE+path,wait_until='networkidle')
     check(tag+' HTTP',response.status==200)
     expect(page.locator('html')).to_have_attribute('data-design',theme)
     check(tag+' overflow',page.evaluate('document.documentElement.scrollWidth<=innerWidth'))
     check(tag+' no duplicate IDs',page.evaluate('(()=>{let a=[...document.querySelectorAll("[id]")].map(e=>e.id);return a.length===new Set(a).size})()'))
     if theme=='nova' and brightness=='dark' and width==1440 and path in ['/','/tbox/','/community/']:
      page.screenshot(path=str(OUT/('nova-'+{'/':'launcher','/tbox/':'files','/community/':'community'}[path]+'.png')),full_page=True)
     if theme=='nova' and brightness=='light' and width==360 and path=='/':page.screenshot(path=str(OUT/'nova-mobile.png'),full_page=True)
 # Browser fetch retains the same Secure session on loopback; APIRequestContext does not.
 def browser_get(path):
  return page.evaluate("async path=>{let r=await fetch(path,{credentials:'same-origin',cache:'no-store'});return {status:r.status,data:await r.json()}}",SITE+path)
 # Workflow deployment verification: real permissions/responses, no business writes.
 context.add_cookies([{'name':'qaredi_design','value':'astra','url':SITE},{'name':'tbox_theme','value':'light','url':SITE},{'name':'qaredi_sidebar','value':'open','url':SITE}])  # 카페 C5 — 펼친 셸을 잰다(쿠키 qaredi_sidebar 없으면 접힘이 기본)
 page.set_viewport_size({'width':1280,'height':1000});page.goto(SITE+'/tbox/',wait_until='networkidle')
 response=browser_get('/tbox/api/files?folder=0');payload=response['data']
 check('UX real files API',response['status']==200 and isinstance(payload.get('files'),list))
 check('UX actual row count',page.locator('#files-body tr').count()==len(payload.get('files',[])))
 check('UX actual location count',page.locator('#q-location-count').inner_text()==str(payload.get('total',0))+'개의 파일')
 check('UX folder warning folded',page.locator('.q-folder-help').is_visible() and not page.locator('.q-folder-help').evaluate('(e)=>e.open'))
 mores=page.locator('#files-body .q-file-more')
 if mores.count():
  opener=mores.first; opener.click();expect(page.locator('#q-file-actions-dialog')).to_be_visible()
  check('UX dialog allowed actions exist',page.locator('#q-file-actions-list button').count()>0)
  check('UX dialog readable labels',all(t.strip() for t in page.locator('#q-file-actions-list button').all_inner_texts()))
  page.keyboard.press('Escape');check('UX actual Escape focus',opener.evaluate('(e)=>e===document.activeElement'))
 page.screenshot(path=str(OUT/'astra-ux-files.png'),full_page=True)
 for width in [360,1280]:
  page.set_viewport_size({'width':width,'height':1000});page.goto(SITE+'/',wait_until='networkidle')
  check('UX signed launcher scripts',page.locator('script[src]').count()==2)
  check('UX personal visible',page.locator('#q-start').is_visible())
  expect(page.locator('#q-start')).to_have_attribute('aria-busy','false')
  file_result=browser_get('/tbox/api/files/recent');doc_result=browser_get('/community/api/me/recent')
  check('UX both recent endpoints',file_result['status']==200 and doc_result['status']==200)
  personal=file_result['data'].get('files',[])+doc_result['data'].get('pages',[])
  check('UX recent personal count',page.locator('.q-start-item').count()==min(6,len(personal)))
  page.locator('[data-start-view="favorites"]').click();expect(page.locator('#q-start')).to_have_attribute('aria-busy','false')
  favorites=browser_get('/tbox/api/files/favorites')['data'].get('files',[])+browser_get('/community/api/me/favorites')['data'].get('pages',[])
  check('UX favorites personal count',page.locator('.q-start-item').count()==min(6,len(favorites)))
  check('UX personal no overflow',page.evaluate('document.documentElement.scrollWidth<=innerWidth'))
  if width==1280:page.screenshot(path=str(OUT/'astra-ux-start.png'),full_page=True)
 # Real live controls persist through navigation, including original brightness toggle.
 page.set_viewport_size({'width':1440,'height':1000});page.goto(SITE+'/tbox/settings',wait_until='networkidle')
 page.locator('[data-design-open]').first.click();page.locator('[data-design-choice="astra"]').click();page.locator('#q-design-save').click();page.locator('[data-design-close]').click()
 page.goto(SITE+'/community/',wait_until='networkidle');expect(page.locator('html')).to_have_attribute('data-design','astra')
 check('community unchanged script boot',page.locator('.brand__name').inner_text()=='모아 업무')  # 원래 어긋남(「모아 커뮤니티」) → Q:F-12 C 업무 셸 이름
 # Theme popup is not subject to other dialogs' IDs or app.js dispatch.
 page.locator('[data-design-open]').first.click();check('actual popup visible',page.locator('#q-design-dialog').is_visible());page.screenshot(path=str(OUT/'theme-picker.png'));page.keyboard.press('Escape')
 anon=browser.new_context(user_agent='tbox-review/2026-10-01');ap=anon.new_page()
 for width in [360,1440]:
  ap.set_viewport_size({'width':width,'height':1000})
  for path in ['/','/tbox/login']:
   r=ap.goto(SITE+path,wait_until='networkidle');check('anonymous '+path+' HTTP',r.status==200);check('anonymous overflow',ap.evaluate('document.documentElement.scrollWidth<=innerWidth'))
   if path=='/':check('UX anonymous personal absent',ap.locator('#q-start,script[src*="start.js"]').count()==0)
 check('business mutation requests',not mutations);check('runtime console',not errors);check('no HTTP 5xx',not requests)
 browser.close()
result={'checks':checks,'themes':themes,'failures':failures,'console':errors,'http_5xx':requests,'mutations':mutations,'scope':'live original JS/API; reserved review account; readonly GET navigation and preference cookies'}
(OUT/'results.json').write_text(json.dumps(result,ensure_ascii=False,indent=2));print(json.dumps(result,ensure_ascii=False));raise SystemExit(bool(failures or errors or requests or mutations))
