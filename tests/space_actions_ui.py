"""Space actions hierarchy: actual PHP view, synthetic transport and role capabilities."""
import json,os,subprocess
from pathlib import Path
from urllib.parse import urlsplit
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[1];OUT=Path(os.environ.get('SPACE_RESULTS','/tmp/space-actions-checks'));OUT.mkdir(parents=True,exist_ok=True)
SID='11111111-1111-4111-8111-111111111111';checks=[];scenario='owner';calls=[]
layout={'version':1,'source':'all','view':'recent','widgets':[{'id':kind,'visible':True,'size':'wide'}for kind in ['notice','task','event','file','story']]}
def check(ok,label):checks.append({'name':label,'passed':bool(ok)});assert ok,label;print('PASS '+label,flush=True)
def space():
 role='owner'if scenario in ['archive','acting']else scenario;active=scenario!='archive';write=active and role!='viewer'and scenario!='acting'
 return {'id':SID,'name':'우리 팀의 공간','kind':'company','status':'active'if active else'archived','role':role,'can_write':write,'can_edit_home':write and role in ['owner','admin','editor'],'can_manage':write and role in ['owner','admin'],'can_restore':role=='owner'and scenario!='acting'}
CONTRAST="""nodes=>nodes.filter(n=>n.getClientRects().length).map(n=>{const rgb=value=>value.match(/[\d.]+/g).slice(0,3).map(Number);const luminance=value=>rgb(value).map(v=>{v/=255;return v<=.04045?v/12.92:Math.pow((v+.055)/1.055,2.4)}).reduce((a,v,i)=>a+v*[.2126,.7152,.0722][i],0);let parent=n,bg;while(parent){bg=getComputedStyle(parent).backgroundColor;if(bg!=='rgba(0, 0, 0, 0)'&&bg!=='transparent')break;parent=parent.parentElement;}const a=luminance(getComputedStyle(n).color),b=luminance(bg||'rgb(255,255,255)');return(Math.max(a,b)+.05)/(Math.min(a,b)+.05);})"""
with sync_playwright()as pw:
 browser=pw.chromium.launch();page=browser.new_page();errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
 def route(r):
  p=urlsplit(r.request.url).path;calls.append((r.request.method,p))
  if p=='/tbox/spaces':r.fulfill(body=subprocess.check_output(['php',str(ROOT/'tests/fixtures/space_home_render.php')],text=True),content_type='text/html');return
  if p.startswith('/tbox/assets/'):
   f=ROOT/'public/assets'/p.removeprefix('/tbox/assets/');r.fulfill(body=f.read_bytes(),content_type='text/javascript'if f.suffix=='.js'else'text/css');return
  if p=='/tbox/api/me':r.fulfill(json={'member_id':1001,'csrf_token':'synthetic','can_community_write':True,'acting':scenario=='acting'});return
  if p=='/tbox/api/product-spaces':r.fulfill(json={'spaces':[]if scenario=='empty'else[space()]});return
  if p.endswith('/home'):
   s=space();r.fulfill(json={'space':s,'layout':layout,'revision':1,'widgets':{'notice':[{'id':'n1','title':'다음 모임 안내','body':'사진과 자료를 함께 준비해요.','can_edit':s['can_edit_home'],'can_delete':s['can_edit_home']}],'task':[{'id':'t1','title':'발표 자료 준비','status':'todo','revision':1,'can_edit':s['can_write'],'can_delete':s['can_write']}]}});return
  if p.endswith('/members'):r.fulfill(json={'me':1001,'members':[{'user_id':1001,'display_name':'검토 사용자','role':space()['role']},{'user_id':1002,'display_name':'팀원','role':'member'}]});return
  if p.endswith('/invites'):r.fulfill(json={'invites':[],'token':'synthetic-invite'});return
  r.fulfill(json={'items':[],'ok':True})
 page.route('**/*',route)
 def visit(value):
  global scenario
  scenario=value;page.goto('https://moa.example/tbox/spaces',wait_until='networkidle')
 def menu():page.locator('#space-menu').click()
 def has(name):return page.get_by_role('button',name=name,exact=True).count()>0
 visit('owner')
 check(page.locator('.space-primary-tools button:visible').count()==2,'owner has two persistent controls')
 check(not has('공간 홈 구성하기')and not has('공지 등록하기'),'management and types absent from main page')
 check(page.locator('.space-breadcrumb .btn').count()==0,'home and library are plain navigation links')
 page.locator('#space-register').focus();page.keyboard.press('Enter');check(has('공지 등록하기')and has('이야기 등록하기'),'keyboard opens registration types');check(page.get_by_role('dialog',name='무엇을 등록할까요?',exact=True).count()==1,'registration menu has actual accessible name');page.get_by_role('button',name='할 일 등록하기',exact=True).click();check(page.locator('[name=title]').evaluate('(n)=>n===document.activeElement'),'type selection focuses title field');check(page.get_by_role('dialog',name='할 일 등록하기',exact=True).count()==1,'registration form has actual accessible name');page.keyboard.press('Escape');check(page.locator('#space-register').evaluate('(n)=>n===document.activeElement'),'registration Escape returns to persistent entry')
 menu();check(page.get_by_role('dialog',name='공간 메뉴',exact=True).count()==1,'space menu has actual accessible name');check(has('공간 홈 구성하기')and has('초대 만들기')and has('소유권 이전하기')and has('공간 보관하기'),'owner permissions preserved behind menu');check(not has('저장하기')and not has('취소하기'),'menu is a selection list without save footer');page.get_by_role('button',name='소유권 이전하기',exact=True).click();page.get_by_label('본인 비밀번호').fill('synthetic-password');page.get_by_label('2단계 인증 코드 (사용하는 계정)').fill('123456');page.keyboard.press('Escape');check(page.locator('[name=password]').input_value()==''and page.locator('[name=otp]').input_value()=='','cancel clears transfer secrets');check(page.locator('#space-menu').evaluate('(n)=>n===document.activeElement'),'nested modal Escape returns to stable space menu')
 menu();page.get_by_role('button',name='초대 만들기',exact=True).click();page.get_by_role('button',name='저장하기',exact=True).click();page.wait_for_timeout(100);check(page.get_by_label('한 번만 표시되는 초대 코드').input_value()=='synthetic-invite','created invite remains visible once');page.keyboard.press('Escape');check(page.get_by_label('한 번만 표시되는 초대 코드').input_value()=='','closing invite clears token');check(page.locator('#space-menu').evaluate('(n)=>n===document.activeElement'),'invite close restores persistent menu trigger')
 check(not has('수정하기')and not has('삭제하기'),'item mutations hidden on main page');page.get_by_role('button',name='다음 모임 안내 더보기',exact=True).click();check(has('수정하기')and has('삭제하기'),'item more preserves authorized edit and delete');page.keyboard.press('Escape');check(page.get_by_role('button',name='다음 모임 안내 더보기',exact=True).evaluate('(n)=>n===document.activeElement'),'item menu focus returns to more trigger');check(has('완료하기'),'task completion remains directly available')
 for design in ['moa','claude','astra','aurora','bloom','nova']:
  for theme in ['light','dark']:
   for contrast in ['normal','high']:
    page.evaluate('(s)=>{document.documentElement.dataset.design=s.design;document.documentElement.dataset.theme=s.theme;if(s.contrast==="high")document.documentElement.dataset.contrast="high";else delete document.documentElement.dataset.contrast}',{'design':design,'theme':theme,'contrast':contrast});page.wait_for_timeout(200)
    check(all(value>=4.5 for value in page.locator('.space-layout .btn--plain').evaluate_all(CONTRAST)),design+' '+theme+' '+contrast+' plain card actions readable');menu();check(all(value>=4.5 for value in page.locator('.space-dialog .btn--plain').evaluate_all(CONTRAST)),design+' '+theme+' '+contrast+' menu actions readable');page.keyboard.press('Escape')
 page.evaluate('document.documentElement.dataset.design="moa";delete document.documentElement.dataset.contrast')
 page.evaluate('document.documentElement.dataset.theme="light"')
 for width in [360,390,1280]:
  page.set_viewport_size({'width':width,'height':900});check(page.evaluate('document.documentElement.scrollWidth<=innerWidth'),'no horizontal overflow '+str(width));top=page.locator('.space-card').first.bounding_box()['y'];check(top<400,'first record card above400px '+str(width));check(page.locator('.space-primary-tools button:visible').count()==2,'two persistent controls '+str(width));page.screenshot(path=str(OUT/f'owner-{width}.png'),full_page=True);menu();check(page.locator('#space-dialog').evaluate('(n)=>n.scrollWidth<=n.clientWidth'),'menu width fits '+str(width));page.screenshot(path=str(OUT/f'owner-menu-{width}.png'),full_page=True);page.keyboard.press('Escape')
 for role in ['admin','member','viewer','archive','acting','empty']:
  visit(role);menu();check(has('새로 불러오기'),role+' retains refresh')
  if role=='admin':check(has('공간 홈 구성하기')and has('초대 만들기')and not has('소유권 이전하기')and not has('공간 보관하기'),'admin role boundary')
  if role=='member':check(has('공간 나가기')and not has('공간 홈 구성하기')and not has('초대 만들기'),'member role boundary')
  if role=='viewer':check(not has('공간 홈 구성하기')and not has('초대 만들기')and not page.locator('#space-register').is_visible(),'viewer cannot edit or register')
  if role=='archive':check(has('공간 다시 열기')and not has('초대 만들기')and not page.locator('#space-register').is_visible(),'archived owner restore boundary')
  if role=='acting':check(not has('새 공간 만들기')and not has('초대로 참여하기')and not has('공간 홈 구성하기'),'acting mode hides mutation entry points')
  if role=='empty':check(has('새 공간 만들기')and has('초대로 참여하기')and not page.locator('#space-register').is_visible(),'empty account can create and join through menu')
  page.screenshot(path=str(OUT/f'{role}-menu.png'),full_page=True);page.keyboard.press('Escape')
 visit('member');page.locator('#space-register').click();check(not has('공지 등록하기')and has('할 일 등록하기')and has('이야기 등록하기'),'member registration types respect notice restriction');page.keyboard.press('Escape')
 visit('empty');menu();page.get_by_role('button',name='새 공간 만들기',exact=True).click();check(page.get_by_label('공간 이름').evaluate('(n)=>n===document.activeElement'),'create from empty menu focuses name');page.keyboard.press('Escape');check(page.locator('#space-menu').evaluate('(n)=>n===document.activeElement'),'empty create cancel returns to menu trigger')
 check(not errors,'no browser errors');browser.close()
(OUT/'actions-results.json').write_text(json.dumps(checks,ensure_ascii=False,indent=2));print(str(len(checks))+' checks passed')
