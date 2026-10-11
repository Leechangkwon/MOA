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
 page.route('**/*',route)
 def visit(width):
  global layout
  layout=config(['recent','space_task']);page.set_viewport_size({'width':width,'height':900 if width>=1100 else 844 if width==390 else 640});page.goto('https://moa.example/',wait_until='networkidle')
 def choose(id='widget-0'):page.locator('[data-dashboard-settings="'+id+'"]').click()
 for width in [1280,1440,1920]:
  visit(width);original=json.dumps(layout,sort_keys=True);before=len(calls);page.locator('#home-edit').click();choose()
  check(page.locator('.dashboard-inspector').is_visible(),'PC selected widget inspector visible '+str(width))
  check(page.locator('[data-widget-type=recent] button').count()==0,'card has no duplicate source action '+str(width))
  check(len(calls)==before,'selection and inspector do not fetch new sources '+str(width))
  rectangles=page.locator('#home-widgets').bounding_box(),page.locator('.dashboard-inspector').bounding_box();check(rectangles[0]['x']+rectangles[0]['width']<=rectangles[1]['x'],'PC canvas and inspector do not overlap '+str(width))
  check(json.dumps(layout,sort_keys=True)==original,'showing inspector does not save geometry '+str(width))
  page.get_by_role('button',name='출처 변경',exact=True).click();page.get_by_label('자료 출처',exact=True).select_option('files');page.get_by_role('button',name='출처 적용',exact=True).click();page.wait_for_timeout(50)
  check('출처: 자료실'in page.locator('.dashboard-inspector').inner_text(),'source applied to selected inspector '+str(width))
  check(page.locator('[data-dashboard-settings=widget-0]').evaluate('(n)=>n===document.activeElement'),'source apply restores connected settings trigger '+str(width))
  page.evaluate("document.documentElement.dataset.design='aurora';document.documentElement.dataset.theme='dark'");page.wait_for_timeout(650);page.screenshot(path=str(OUT/('personal-inspector-aurora-dark-'+str(width)+'.png')))
  page.get_by_role('button',name='취소',exact=True).click();check(json.dumps(layout,sort_keys=True)==original,'source change cancel preserves stored layout '+str(width))
 for width in [390,360]:
  visit(width);original=json.dumps(layout,sort_keys=True);page.locator('#home-edit').click()
  check(page.locator('.dashboard-inspector-dialog[open]').count()==0,'mobile ordinary selection does not open settings '+str(width))
  choose();check(page.locator('.dashboard-inspector-dialog[open]').count()==1,'mobile settings explicit native drawer '+str(width))
  check(page.locator('.dashboard-inspector-dialog').evaluate('(n)=>n.scrollWidth<=n.clientWidth'),'mobile inspector Korean no overflow '+str(width))
  page.evaluate("document.documentElement.dataset.design='aurora';document.documentElement.dataset.theme='dark'");page.wait_for_timeout(650);page.screenshot(path=str(OUT/('personal-inspector-aurora-dark-'+str(width)+'.png')))
  page.keyboard.press('Escape');check(page.locator('.dashboard-editor').is_visible(),'drawer Escape preserves edit draft '+str(width));check(page.locator('[data-dashboard-settings=widget-0]').evaluate('(n)=>n===document.activeElement'),'drawer Escape returns selected settings '+str(width))
  choose();page.get_by_role('button',name='출처 변경',exact=True).click();check(page.locator('dialog[open]').count()==1,'source chooser replaces mobile modal '+str(width));page.keyboard.press('Escape');check(page.locator('.dashboard-editor').is_visible(),'source chooser Escape preserves edit draft '+str(width));check(page.locator('[data-dashboard-settings=widget-0]').evaluate('(n)=>n===document.activeElement'),'source chooser Escape stable settings focus '+str(width))
  choose();page.get_by_role('button',name='위젯 제거',exact=True).click();page.locator('.dashboard-inspector-dialog .dashboard-inspector-close').click();check(not page.locator('[data-widget-type=recent]').count(),'selected remove removes draft instance '+str(width));page.get_by_role('button',name='취소',exact=True).click();check(page.locator('[data-widget-type=recent]').is_visible(),'cancel restores removed instance '+str(width));check(json.dumps(layout,sort_keys=True)==original,'mobile edit does not mutate stored layout '+str(width))
 visit(1440);page.locator('#home-edit').click();page.locator('[data-dashboard-settings=widget-1]').click();deny=True;page.evaluate("window.dispatchEvent(new Event('focus'))");page.wait_for_timeout(150)
 check('접근할 수 없는 공간'in page.locator('.dashboard-inspector').inner_text(),'personal revoked source metadata purged from inspector');page.get_by_role('button',name='출처 변경',exact=True).click();check('검토 회사'not in page.locator('.dashboard-widget-picker').inner_text(),'revoked source removed from chooser');page.keyboard.press('Escape');page.get_by_role('button',name='취소',exact=True).click();deny=False
 # Shared source inspector and authorization cleanup.
 layout=config(['space_notice','space_task']);page.set_viewport_size({'width':1440,'height':900});page.goto('https://moa.example/tbox/spaces',wait_until='networkidle');page.locator('#space-menu').click();page.get_by_role('button',name='대시보드 편집',exact=True).click();choose()
 check('출처: 검토 회사'in page.locator('.dashboard-inspector').inner_text(),'shared inspector actual source name');check(page.get_by_role('button',name='출처 변경',exact=True).count()==0,'shared fixed source cannot choose another space');deny=True;page.get_by_role('button',name='배치 저장·완료',exact=True).click();page.wait_for_timeout(100)
 check(page.locator('.dashboard-inspector').count()==0 and page.locator('.dashboard-inspector-dialog').count()==0,'revocation destroys shared inspector and drawer');check(page.locator('#space-cards').inner_text()=='','revocation clears private content');check(not errors,'strict CSP integration no JavaScript errors');browser.close()
 (OUT/'integration-results.json').write_text(json.dumps({'passed':len(checks),'checks':checks},ensure_ascii=False,indent=2))
 print(str(len(checks))+' checks passed')
