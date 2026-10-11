"""Rendered PHP space view with synthetic API contract; no operational data."""
import json,os,subprocess
from pathlib import Path
from urllib.parse import urlsplit
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[1]
OUT=Path(os.environ.get('SPACE_RESULTS','/tmp/space-dashboard-checks'));OUT.mkdir(parents=True,exist_ok=True)
layout={'version':1,'widgets':[{'id':i,'visible':True,'size':'wide'}for i in ['notice','task','event','file','story']]}
spaces=[{'id':'11111111-1111-4111-8111-111111111111','name':'회사 공간','kind':'company','status':'active','role':'owner','can_manage':True,'can_edit_home':True,'can_write':True},{'id':'22222222-2222-4222-8222-222222222222','name':'독서 모임','kind':'group','status':'active','role':'viewer','can_manage':False,'can_edit_home':False,'can_write':False}]
revision=1;conflict=False;denied=False;calls=[];bodies=[];checks=[]
def check(ok,label):
 checks.append({'name':label,'passed':bool(ok)});print(('PASS 'if ok else'FAIL ')+label,flush=True);assert ok,label
with sync_playwright()as pw:
 browser=pw.chromium.launch();page=browser.new_page();errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
 def route(r):
  global layout,revision
  p=urlsplit(r.request.url).path;m=r.request.method;calls.append((m,p));
  if r.request.post_data:bodies.append((p,r.request.post_data_json))
  if p=='/tbox/spaces':r.fulfill(body=subprocess.check_output(['php',str(ROOT/'tests/fixtures/space_home_render.php')],text=True),content_type='text/html');return
  if p.startswith('/tbox/assets/'):
   f=ROOT/'public/assets'/p.removeprefix('/tbox/assets/');r.fulfill(body=f.read_bytes(),content_type='text/javascript'if f.suffix=='.js'else'text/css');return
  if p in ['/tbox/api/files/recent','/tbox/api/files']:r.fulfill(json={'files':[{'id':'44444444-4444-4444-8444-444444444444','name':'회의자료.pdf'}]});return
  if p=='/tbox/api/me':r.fulfill(json={'member_id':1001,'csrf_token':'synthetic','next':None,'can_community_write':True});return
  if p=='/tbox/api/product-spaces':
   if m=='POST':spaces.append({**spaces[0],'id':'33333333-3333-4333-8333-333333333333',**r.request.post_data_json});r.fulfill(json={'space':spaces[-1]});return
   r.fulfill(json={'spaces':spaces});return
  if p.endswith('/home'):
   if denied:r.fulfill(status=403,json={'error':'권한이 없습니다.'});return
   s=next(s for s in spaces if s['id']==p.split('/')[-2]);r.fulfill(json={'space':s,'layout':layout,'revision':revision,'widgets':{'notice':[{'id':'n1','title':'<img src=x onerror=alert(1)>','body':'공지 본문'}]if s['kind']=='company'else[],'task':[],'event':[],'file':[],'story':[]}});return
  if p.endswith('/members'):r.fulfill(json={'members':[{'user_id':1001,'display_name':'공간 소유자','role':'owner'},{'user_id':1002,'display_name':'팀원','role':'member'}]});return
  if p.endswith('/dashboard'):
   if conflict:r.fulfill(status=409,json={'error':'conflict'});return
   layout=r.request.post_data_json['layout'];revision+=1;r.fulfill(json={'layout':layout,'revision':revision});return
  if p.endswith('/invites'):r.fulfill(json={'token':'synthetic-invite','invite':{'id':'i1'},'invites':[]});return
  r.fulfill(json={'ok':True})
 def management(name):
  page.locator('#space-menu').click();page.get_by_role('button',name=name,exact=True).click()
 def register(name):
  page.locator('#space-register').click();page.get_by_role('button',name=name,exact=True).click()
 page.route('**/*',route);page.goto('https://moa.example/tbox/spaces',wait_until='networkidle')
 check(page.locator('#space-title').inner_text()=='회사 공간','first membership rendered')
 check(page.locator('#space-cards img').count()==0,'content is escaped text')
 check(page.locator('#space-cards section').count()==5,'five genuine widget types')
 check(page.get_by_text('아직 등록된 할 일이 없습니다.',exact=True).count()==1,'empty actual content message')
 management('공간 홈 구성하기');page.locator('[name=show-story]').uncheck();page.get_by_role('button',name='저장하기',exact=True).click();page.wait_for_timeout(100)
 check(page.locator('#space-cards section').count()==4,'shared layout save applied')
 check(any(m=='PATCH'and p.endswith('/dashboard')for m,p in calls),'revision PATCH contract used')
 management('공간 홈 구성하기');page.locator('[name=show-story]').check();page.keyboard.press('Escape');check(page.locator('#space-cards section').count()==4,'escape cancels draft')
 conflict=True;management('공간 홈 구성하기');page.get_by_role('button',name='저장하기',exact=True).click();page.wait_for_timeout(100);check(page.locator('#space-dialog').evaluate('(n)=>n.open'),'conflict retains editor');check('다른 사람이'in page.locator('#space-dialog').inner_text(),'conflict actionable message');page.keyboard.press('Escape');conflict=False
 register('공지 등록하기');page.get_by_label('제목',exact=True).fill('새 공지');page.get_by_label('내용',exact=True).fill('공지 내용');page.get_by_role('button',name='저장하기',exact=True).click();page.wait_for_timeout(100);check(any(m=='POST'and p.endswith('/items')for m,p in calls),'scoped content creation')
 register('할 일 등록하기');page.get_by_label('제목',exact=True).fill('준비 작업');page.locator('[name=assignee_id]').select_option('1002');page.get_by_label('마감일',exact=True).fill('2026-10-12T10:00');page.get_by_role('button',name='저장하기',exact=True).click();page.wait_for_timeout(100);check(any(b.get('assignee_id')==1002 and b.get('due_at','').endswith('Z') for _,b in bodies),'task assignee numeric and date explicit timezone')
 register('자료 등록하기');page.wait_for_timeout(100);page.get_by_label('연결할 자료',exact=True).select_option('44444444-4444-4444-8444-444444444444');check(page.get_by_label('제목',exact=True).input_value()=='회의자료.pdf','file chooser displays authorized file name');page.get_by_role('button',name='저장하기',exact=True).click();page.wait_for_timeout(100);check(any(b.get('file_id')=='44444444-4444-4444-8444-444444444444' for _,b in bodies),'existing file linked without user entering identifier')
 management('소유권 이전하기');page.get_by_label('새 소유자',exact=True).select_option('1002');page.get_by_label('본인 비밀번호',exact=True).fill('synthetic-password');page.get_by_label('2단계 인증 코드 (사용하는 계정)',exact=True).fill('123456');page.get_by_role('button',name='저장하기',exact=True).click();page.wait_for_timeout(100);check(any(p.endswith('/transfer') and b.get('password')=='synthetic-password' and b.get('otp')=='123456' for p,b in bodies),'transfer reauthentication and OTP sent only explicitly');check(page.locator('[name=password]').count()==0,'transfer password cleared immediately')
 management('초대 만들기');page.get_by_role('button',name='저장하기',exact=True).click();page.wait_for_timeout(100);check(page.get_by_label('한 번만 표시되는 초대 코드').input_value()=='synthetic-invite','invite token shown after successful create');page.keyboard.press('Escape');check(page.get_by_label('한 번만 표시되는 초대 코드').input_value()=='','invite token cleared when dialog closes')
 for width in [390,1280]:
  page.set_viewport_size({'width':width,'height':900});check(page.evaluate('document.documentElement.scrollWidth<=innerWidth'),'no horizontal overflow '+str(width));page.screenshot(path=str(OUT/f'space-{width}.png'),full_page=True)
 page.locator('#space-picker').select_option(spaces[1]['id']);page.wait_for_timeout(100);check(page.locator('#space-title').inner_text()=='독서 모임','membership switch');check(page.get_by_role('button',name='공간 홈 구성하기',exact=True).count()==0,'viewer cannot edit home');check(page.get_by_role('button',name='공지 등록하기',exact=True).count()==0,'viewer cannot create content');check('<img'not in page.locator('#space-cards').inner_text(),'old space content cleared')
 denied=True;management('새로 불러오기');page.wait_for_timeout(100);check(page.locator('#space-cards').inner_text()=='','revoked membership clears all widgets');check(page.locator('#space-picker option').count()==0,'revoked membership clears cached selection');check(not errors,'no JS errors');browser.close()
(OUT/'results.json').write_text(json.dumps(checks,ensure_ascii=False,indent=2));print(str(len(checks))+' checks passed')
