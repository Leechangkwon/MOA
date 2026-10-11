"""Real PHP views and assets, synthetic source responses. Never uses production data."""
import json,os,subprocess
from pathlib import Path
from urllib.parse import urlsplit,parse_qs
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[1]
OUT=Path(os.environ.get('INSPECTOR_RESULTS','/tmp/dashboard-inspector-checks'));OUT.mkdir(parents=True,exist_ok=True)
SID='11111111-1111-4111-8111-111111111111'
TYPES=['my_tasks','briefing','recent','favorites','space_notice','space_event','space_task']
checks=[];calls=[];revision=2;conflict=False;deny=False
space={'id':SID,'name':'검토 회사','kind':'company','status':'active','role':'owner','can_manage':True,'can_edit_home':True,'can_write':True}
def config(types=TYPES):
 return {'version':3,'source':'all','view':'recent','guide':'pending','widgets':[{'id':'widget-'+str(j),'type':t,'source':{'space_id':SID,'scope':'all'}if t.startswith('space_')else{'kind':'all'}if t in ['recent','favorites']else{},'visible':True,'locked':False,'desktop':{'x':0,'y':j*444,'w':1200,'h':420,'size':'m'},'mobile':{'x':0,'y':j*436,'w':360,'h':420,'size':'m'}}for j,t in enumerate(types)]}
layout=config()
def check(ok,label):
 assert ok,label;checks.append(label);print('PASS '+label,flush=True)
with sync_playwright()as pw:
 browser=pw.chromium.launch();page=browser.new_page(viewport={'width':1280,'height':900});errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
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
    if conflict:r.fulfill(status=409,json={'error':'conflict'});return
    check(r.request.post_data_json['revision']==revision,'server revision transmitted');layout=r.request.post_data_json['layout'];revision+=1
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
 page.route('**/*',route);page.goto('https://moa.example/',wait_until='networkidle')
 for width,height in [(1280,900),(1440,900),(1920,900),(390,844),(360,640),(1099,900),(1100,900)]:
  page.set_viewport_size({'width':width,'height':height});page.reload(wait_until='networkidle');original=json.loads(json.dumps(layout));normal=page.locator('#home-widgets').bounding_box()['width'];page.locator('#home-edit').click();page.wait_for_timeout(80)
  check(not page.locator('dialog.dashboard-inspector-dialog').evaluate('n=>n.open'),'settings initially closed '+str(width))
  check(page.evaluate('document.documentElement.scrollWidth<=innerWidth'),'document fits '+str(width))
  check(page.get_by_role('button',name='배치 저장·완료',exact=True).is_visible(),'save visible '+str(width))
  if width>=1100:
   host=page.locator('#home-widgets').bounding_box();panel=page.locator('.dashboard-inspector').bounding_box();check(host['x']+host['width']<=panel['x'],'sidebar separated '+str(width))
  else:
   check(page.locator('#home-widgets').bounding_box()['width']==normal,'narrow canvas width unchanged '+str(width));page.get_by_role('button',name='위젯 설정',exact=True).click();check(page.locator('.dashboard-inspector-dialog').evaluate('n=>n.open'),'explicit settings opens '+str(width));check(page.evaluate("getComputedStyle(document.body).overflowY==='hidden'"),'drawer blocks background scrolling '+str(width));page.screenshot(path=str(OUT/('personal-settings-'+str(width)+'.png')),full_page=False);page.keyboard.press('Escape');check(page.locator('.dashboard-editor').is_visible(),'drawer Escape keeps editing '+str(width));check(page.locator('[data-dashboard-settings=widget-0]').evaluate('n=>n===document.activeElement'),'drawer closes to selected settings '+str(width));check(not page.evaluate("document.body.classList.contains('dashboard-inspector-open')"),'drawer restores background scrolling '+str(width))
  page.evaluate("document.documentElement.dataset.design='aurora';document.documentElement.dataset.theme='dark'");page.wait_for_timeout(300);check(page.locator('.dashboard-editor .btn,.dashboard-inspector .btn,.dashboard-card-chrome .btn').evaluate_all("nodes=>{const lum=s=>{const a=s.match(/[\d.]+/g).slice(0,3).map(Number).map(v=>{v/=255;return v<=.04045?v/12.92:Math.pow((v+.055)/1.055,2.4)});return a[0]*.2126+a[1]*.7152+a[2]*.0722};return nodes.filter(n=>!n.disabled&&n.checkVisibility()).every(n=>{const s=getComputedStyle(n),a=lum(s.color),b=lum(s.backgroundColor);return (Math.max(a,b)+.05)/(Math.min(a,b)+.05)>=4.5})}"),'aurora dark enabled button contrast '+str(width));page.screenshot(path=str(OUT/('personal-editing-'+str(width)+'.png')),full_page=False);check(layout==original,'panel never implicitly saves '+str(width));page.get_by_role('button',name='취소',exact=True).click();check(page.locator('#home-widgets').bounding_box()['width']==normal,'cancel restores original width '+str(width));check(page.locator('.dashboard-workspace,.dashboard-inspector-dialog').count()==0,'cancel removes all editor regions '+str(width))
 page.set_viewport_size({'width':390,'height':844});page.reload(wait_until='networkidle');original=json.loads(json.dumps(layout));page.locator('#home-edit').click();page.locator('[data-dashboard-settings=widget-2]').click();check(page.locator('.dashboard-inspector-dialog').evaluate('n=>n.open'),'card settings opens mobile drawer');page.get_by_role('button',name='출처 변경',exact=True).click();check(not page.locator('.dashboard-inspector-dialog').evaluate('n=>n.open'),'source picker closes drawer first');check(page.locator('.dashboard-widget-picker').evaluate('n=>n.open'),'existing source picker opens');page.locator('.dashboard-widget-picker').get_by_role('button',name='취소',exact=True).click();check(page.locator('[data-dashboard-settings=widget-2]').evaluate('n=>n===document.activeElement'),'source cancel returns selected settings');check(layout==original,'source cancel does not save geometry');page.get_by_role('button',name='취소',exact=True).click()
 page.set_viewport_size({'width':1280,'height':900});page.reload(wait_until='networkidle');page.locator('#home-edit').click();page.locator('[data-dashboard-settings=widget-2]').click();source=page.get_by_role('button',name='출처 변경',exact=True);source.focus();page.evaluate("window.dispatchEvent(new Event('resize'))");check(source.evaluate('n=>n===document.activeElement'),'geometry repaint preserves domain action focus');page.get_by_role('button',name='배치 저장·완료',exact=True).click();page.wait_for_timeout(80);check(page.locator('.dashboard-workspace,.dashboard-inspector-dialog,.dashboard-editor').count()==0,'successful save removes every editor region');check(page.locator('[data-dashboard-widget]').first.evaluate("n=>getComputedStyle(n).outlineStyle==='none'"),'normal cards have no selection outline')
 check(not errors,'strict CSP JavaScript clean');browser.close()
(OUT/'inspector-results.json').write_text(json.dumps({'passed':len(checks),'checks':checks},ensure_ascii=False,indent=2));print(str(len(checks))+' checks passed')
