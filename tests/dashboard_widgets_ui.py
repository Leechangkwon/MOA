"""Real PHP views and assets, synthetic source responses. Never uses production data."""
import json,os,subprocess
from pathlib import Path
from urllib.parse import urlsplit,parse_qs
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[1]
OUT=Path(os.environ.get('WIDGET_RESULTS','/tmp/dashboard-widgets-checks'));OUT.mkdir(parents=True,exist_ok=True)
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
 check(len(page.locator('.dashboard-widget').all())==7,'seven actual widget types mounted')
 check(not any(p.endswith('/start.js')or p.endswith('/product-home.js')for _,p,_ in calls),'new layout never starts legacy data scripts')
 check(page.locator('[data-widget-type=my_tasks] .dashboard-widget-row').count()in [3,4],'medium tasks fit bounded actual rows')
 check('중복 기한'not in page.locator('[data-widget-type=briefing]').inner_text(),'displayed task due reason deduplicated')
 check('검토 요청'in page.locator('[data-widget-type=briefing]').inner_text(),'same task review reason preserved')
 check(page.locator('[data-widget-type=briefing] .dashboard-widget-all').get_attribute('href')=='/community/today','briefing whole page correct route')
 check('민감 자료'in page.locator('[data-widget-type=recent]').inner_text(),'file warnings preserved')
 check(page.locator('[data-widget-type=recent] a.dashboard-widget-title').first.get_attribute('href')=='/tbox/?file=file0','exact UUID file preview')
 check(page.locator('[data-widget-type=space_task] button').count()==2,'only capable rows expose direct completion')
 check('실제 담당자'in page.locator('[data-widget-type=space_task]').inner_text()and '담당 없음'in page.locator('[data-widget-type=space_task]').inner_text(),'task assignee actual names and unassigned label');check('종료 시각 미정'in page.locator('[data-widget-type=space_event]').inner_text(),'server as_of and null end mark unknown end');check(all(q.get('view')==['dashboard']and q.get('limit')==['8']for _,p,q in calls if '/items' in p),'space widgets filtered dashboard projection')
 page.screenshot(path=str(OUT/'personal-seven-light-1280.png'),full_page=True)
 page.locator('#home-edit').click();check(page.locator('.dashboard-editor').is_visible(),'in-page editor begins')
 check(page.locator('[data-widget-type=recent] a[href]').count()==0,'original navigation suspended in edit')
 check(page.locator('[data-widget-type=space_task] button:enabled').count()==0,'completion disabled during edit and source controls in inspector')
 page.locator('[data-dashboard-move="widget-0"]').focus();page.get_by_role('button',name='내 작업 소 크기',exact=True).click();check(page.locator('[data-widget-type=my_tasks] .dashboard-widget-row').count()==1,'small task one row')
 page.get_by_role('button',name='내 작업 대 크기',exact=True).click();page.wait_for_timeout(150);check(page.locator('[data-widget-type=my_tasks] .dashboard-widget-row').count()in [6,7,8],'large task more actual rows')
 page.get_by_role('button',name='배치 저장·완료',exact=True).click();page.wait_for_timeout(80);check(layout['widgets'][0]['desktop']['size']=='l','size stored with whole layout')
 page.reload(wait_until='networkidle');check(page.locator('[data-widget-type=my_tasks] .dashboard-widget-row').count()in [6,7,8],'size content survives reload')
 conflict=True;page.locator('#home-edit').click();page.locator('[data-dashboard-move="widget-0"]').focus();page.get_by_role('button',name='내 작업 소 크기',exact=True).click();page.get_by_role('button',name='배치 저장·완료',exact=True).click();page.wait_for_timeout(80);check(page.locator('.dashboard-editor').is_visible(),'409 retains draft');page.get_by_role('button',name='취소',exact=True).click();check(page.locator('[data-widget-type=my_tasks] .dashboard-widget-row').count()in [6,7,8],'cancel restores large content');conflict=False
 page.set_viewport_size({'width':390,'height':900});page.wait_for_timeout(80);check(page.evaluate('document.documentElement.scrollWidth<=innerWidth'),'mobile no document overflow');page.screenshot(path=str(OUT/'personal-seven-light-390.png'),full_page=True)
 page.evaluate("document.documentElement.dataset.theme='dark'");page.screenshot(path=str(OUT/'personal-seven-dark-390.png'),full_page=True)
 check(not errors,'no JavaScript errors under strict CSP')
 for width in [390,1280]:
  page.set_viewport_size({'width':width,'height':900})
  for t in TYPES:
   previous=0
   for size,height in [('s',220),('m',420),('l',640)]:
    layout=config([t]);layout['widgets'][0]['desktop'].update(h=height,size=size);layout['widgets'][0]['mobile'].update(h=height,size=size);page.reload(wait_until='networkidle');page.wait_for_timeout(50)
    body=page.locator('.dashboard-card-body');rows=page.locator('.dashboard-widget-row').count()
    cap={'s':2,'m':4,'l':8}[size]if t in ['recent','favorites']else{'s':1,'m':3,'l':6}[size]if t=='space_notice'else 6 if width==390 and t=='my_tasks'and size=='l'else{'s':1,'m':4,'l':8}[size]
    check(0<rows<=cap,t+' '+size+' bounded actual rows '+str(width))
    check(body.evaluate('(n)=>n.scrollHeight<=n.clientHeight+2'),t+' '+size+' complete rows fit card '+str(width))
    check(page.locator('.dashboard-widget-all').is_visible(),t+' '+size+' whole page always accessible '+str(width))
    if t in ['recent','space_event','space_task']:page.locator('.dashboard-card').screenshot(path=str(OUT/(t+'-'+size+'-'+str(width)+'.png')))
 layout=config(['space_task','space_task']);page.reload(wait_until='networkidle');deny=True;page.get_by_role('button',name='완료하기',exact=True).first.click();page.wait_for_timeout(100)
 check('task 실제 제목'not in page.locator('#home-widgets').inner_text(),'mutation403 purges every same-source instance')
 deny=False;layout=config(['recent']);layout['widgets'][0]['visible']=False;calls.clear();page.reload(wait_until='networkidle')
 check(not any(p.startswith('/tbox/api/files/')or p.startswith('/community/api/me/')for _,p,_ in calls),'hidden new widget fetches no source')
 layout={'version':1,'widgets':[{'id':i,'visible':i in ['services','tools'],'size':'wide'}for i in ['recent','services','tools','stories']],'source':'all','view':'recent'};calls.clear();page.reload(wait_until='networkidle')
 check(not any(p.endswith('/start.js')or p.endswith('/product-home.js')for _,p,_ in calls),'services/tools-only legacy sends no recent script or source request')
 page.get_by_text('안내 옵션',exact=True).click();page.get_by_role('button',name='추천 구성 미리보기',exact=True).click();check(page.locator('[data-widget-type=recent]').is_visible(),'recommended composition preview renders new content');page.get_by_role('button',name='취소',exact=True).click()
 check(page.locator('[data-home-widget=services]').is_visible()and page.locator('[data-widget-type=recent]').count()==0,'preview cancel restores legacy DOM and geometry')
 layout=config(['recent','favorites','briefing']);page.goto('https://moa.example/',wait_until='networkidle');page.screenshot(path=str(OUT/'personal-default-three-1280.png'),full_page=True)
 check(page.locator('.dashboard-widget').count()==3,'new generic composition three widgets')
 page.set_viewport_size({'width':390,'height':900});page.screenshot(path=str(OUT/'personal-default-three-390.png'),full_page=True)
 layout=config(['space_notice','space_task','space_event','space_file','space_story']);page.goto('https://moa.example/tbox/spaces',wait_until='networkidle')
 check(page.locator('.dashboard-widget').count()==5,'shared five existing content types use common renderer')
 check(page.locator('[data-widget-type=space_file] a.dashboard-widget-title').first.get_attribute('href').endswith('/download'),'shared file gated exact item download')
 for width in [390,1280]:
  page.set_viewport_size({'width':width,'height':900});page.wait_for_timeout(60);check(page.evaluate('document.documentElement.scrollWidth<=innerWidth'),'shared content no overflow '+str(width));page.screenshot(path=str(OUT/('shared-five-'+str(width)+'.png')),full_page=True)
 shared_medium_rows=page.locator('[data-widget-type=space_task] .dashboard-widget-row').count();page.locator('#space-menu').click();page.get_by_role('button',name='대시보드 편집',exact=True).click();check(page.locator('.dashboard-editor').is_visible(),'shared v3 actual edit integrates reusable canvas')
 page.locator('[data-dashboard-move=widget-1]').focus();page.get_by_role('button',name='할 일 소 크기',exact=True).click();check(page.locator('[data-widget-type=space_task] .dashboard-widget-row').count()==1,'shared small content reacts to preset')
 page.get_by_role('button',name='취소',exact=True).click();check(page.locator('[data-widget-type=space_task] .dashboard-widget-row').count()==shared_medium_rows,'shared cancel restores medium content')
 page.locator('#space-menu').click();page.get_by_role('button',name='대시보드 편집',exact=True).click();deny=True;page.get_by_role('button',name='배치 저장·완료',exact=True).click();page.wait_for_timeout(100)
 check(page.locator('#space-cards').inner_text()==''and page.locator('.dashboard-card').count()==0,'v3 shared save403 purges mounted and editing private nodes')
 check(page.locator('#space-picker option').count()==0,'shared revocation clears cached memberships')
 check(not errors,'expanded matrix no JavaScript errors');browser.close()
(OUT/'results.json').write_text(json.dumps({'passed':len(checks),'checks':checks},ensure_ascii=False,indent=2));print(str(len(checks))+' checks passed')
