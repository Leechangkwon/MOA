"""Real PHP views and assets, synthetic source responses. Never uses production data."""
import json,os,subprocess
from pathlib import Path
from urllib.parse import urlsplit,parse_qs
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[1]
OUT=Path(os.environ.get('COMPACT_RESULTS','/tmp/compact-home-checks'));OUT.mkdir(parents=True,exist_ok=True)
SID='11111111-1111-4111-8111-111111111111'
TYPES=['my_tasks','briefing','recent','favorites','space_notice','space_event','space_task']
checks=[];calls=[];revision=2;conflict=False;deny=False;canonical=False;expired=False;hold=False;held=[]
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
    if expired:r.fulfill(status=401,json={'error':'expired'});return
    if conflict:r.fulfill(status=409,json={'error':'conflict'});return
    check(r.request.post_data_json['revision']==revision,'server revision transmitted');layout=r.request.post_data_json['layout'];revision+=1
    if canonical:layout['shortcuts']=['account','library']
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
  if p.startswith('/tbox/api/files/'):
   if hold:held.append(r);return
   r.fulfill(json={'files':[{'id':'11111111-1111-4111-8111-111111111111','name':'합성 비공개 레거시 자료','last_used_at':'2026-10-11'}]});return
  if p=='/tbox/api/folders/favorites':r.fulfill(json={'folders':[{'id':7,'name':'즐겨찾기 폴더'}]});return
  if p.startswith('/community/api/me/'):r.fulfill(json={'pages':[{'id':'page'+str(i),'title':'실제 문서 '+str(i),'last_viewed_at':'2026-10-10','space':{'name':'개인'}}for i in range(8)]});return
  r.fulfill(json={})
 page.route('**/*',route)
 def visit(width):
  global layout
  layout=config(['recent','space_task']);page.set_viewport_size({'width':width,'height':900 if width>=1100 else 844 if width==390 else 640});page.goto('https://moa.example/',wait_until='networkidle')
 def choose(id='widget-0'):page.locator('[data-dashboard-settings="'+id+'"]').click()
 def shortcuts():return page.locator('#dashboard-services a').evaluate_all('(nodes)=>nodes.map(n=>n.dataset.homeShortcut)')
 def settings():
  page.locator('[data-dashboard-settings]').first.click();page.locator('.home-shortcut-settings summary').click()
 def finish(label='취소'):
  if page.locator('.dashboard-inspector-dialog[open]').count():page.locator('.dashboard-inspector-close:visible').click()
  page.get_by_role('button',name=label,exact=True).click();page.wait_for_timeout(100)
 default=['spaces','work','library','messages','assistant']   # Q:F-12 U5-3 — 기본 바로가기에서 「내 작업」 뺌
 layout=config(['legacy_recent']);page.goto('https://moa.example/',wait_until='networkidle');page.wait_for_function('()=>document.querySelector("#q-start-list").textContent.includes("합성 비공개")');check('합성 비공개'in page.locator('#q-start-list').inner_text(),'legacy source loaded before auth loss');hold=True;page.evaluate("document.querySelector('#q-start').dispatchEvent(new Event('home-layout-change'))");page.wait_for_timeout(50);check(bool(held),'legacy refresh response held pending');page.locator('#home-edit').click();expired=True;page.get_by_role('button',name='배치 저장·완료',exact=True).click();page.wait_for_timeout(100);check(page.locator('#home-edit').is_disabled()and page.locator('.dashboard-editor').count()==0,'legacy401 blocks and removes editor');check('합성 비공개'not in page.locator('body').inner_text()and page.locator('#q-start').count()==0,'legacy401 clears and detaches private nodes');check(page.locator('#dashboard-services a').count()==0,'legacy401 clears shortcut preferences');
 for pending in held:pending.fulfill(json={'files':[{'id':'11111111-1111-4111-8111-111111111111','name':'늦은 비공개 레거시 응답','last_used_at':'2026-10-11'}]})
 page.wait_for_timeout(100);check('비공개'not in page.locator('body').inner_text(),'late legacy response cannot repopulate revoked home');check(not errors,'legacy auth no JavaScript errors');browser.close();(OUT/'legacy-auth-results.json').write_text(json.dumps({'passed':len(checks),'checks':checks},ensure_ascii=False,indent=2));print(str(len(checks))+' checks passed')
