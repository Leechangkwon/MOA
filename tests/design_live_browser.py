"""Live original scripts/API included; readonly navigation and visual preferences only."""
import json, os, sys
from pathlib import Path
from playwright.sync_api import sync_playwright, expect

SITE='http://127.0.0.1:8082'
OUT=Path(os.environ.get('DESIGN_LIVE_RESULTS','/tmp/qaredi-design-live')); OUT.mkdir(parents=True,exist_ok=True)
cookies=sys.stdin.readline().strip()
errors, failures, requests, checks=[],[],[],0
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
 page=context.new_page();page.on('pageerror',lambda e:errors.append(str(e)))
 page.on('console',lambda m:errors.append(m.text) if m.type=='error' else None)
 page.on('response',lambda r:requests.append(r.url) if r.status>=500 else None)
 for theme in ['claude','astra','aurora','bloom','nova']:
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
 # Real live controls persist through navigation, including original brightness toggle.
 page.set_viewport_size({'width':1440,'height':1000});page.goto(SITE+'/tbox/settings',wait_until='networkidle')
 page.locator('[data-design-open]').first.click();page.locator('[data-design-choice="astra"]').click();page.locator('#q-design-save').click();page.locator('[data-design-close]').click()
 page.goto(SITE+'/community/',wait_until='networkidle');expect(page.locator('html')).to_have_attribute('data-design','astra')
 check('community unchanged script boot',page.locator('.brand__name').inner_text()=='커뮤니티')
 # Theme popup is not subject to other dialogs' IDs or app.js dispatch.
 page.locator('[data-design-open]').first.click();check('actual popup visible',page.locator('#q-design-dialog').is_visible());page.screenshot(path=str(OUT/'theme-picker.png'));page.keyboard.press('Escape')
 anon=browser.new_context(user_agent='tbox-review/2026-10-01');ap=anon.new_page()
 for width in [360,1440]:
  ap.set_viewport_size({'width':width,'height':1000})
  for path in ['/','/tbox/login']:
   r=ap.goto(SITE+path,wait_until='networkidle');check('anonymous '+path+' HTTP',r.status==200);check('anonymous overflow',ap.evaluate('document.documentElement.scrollWidth<=innerWidth'))
 check('runtime console',not errors);check('no HTTP 5xx',not requests)
 browser.close()
result={'checks':checks,'failures':failures,'console':errors,'http_5xx':requests,'scope':'live original JS/API; reserved review account; readonly GET navigation and preference cookies'}
(OUT/'results.json').write_text(json.dumps(result,ensure_ascii=False,indent=2));print(json.dumps(result,ensure_ascii=False));raise SystemExit(bool(failures or errors or requests))
