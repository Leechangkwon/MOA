"""Actual PHP home templates/destination handlers and production JS; synthetic readonly API."""
import hashlib,json,os,subprocess
from pathlib import Path
from urllib.parse import urlsplit
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[1]
OUT=Path(os.environ.get('HOME_SHORTCUT_RESULTS','/tmp/home-shortcuts'));OUT.mkdir(parents=True,exist_ok=True)
checks=[];calls=[];mode='signed';layout={}
ICONS=json.loads(subprocess.check_output(['php','-r','require "src/Apps.php";echo json_encode(Apps::ICON_PATHS);'],cwd=ROOT,text=True))
DEFAULT=json.loads(subprocess.check_output(['php','-r','require "src/site/ProductSpaces.php";echo json_encode(ProductSpaces::defaultWidgetLayout());'],cwd=ROOT,text=True))
def render(value):return subprocess.check_output(['php',str(ROOT/'tests/fixtures/home_shortcuts_render.php'),value],text=True)
def check(ok,name):
 assert ok,name
 checks.append(name);print('PASS '+name,flush=True)
with sync_playwright()as pw:
 b=pw.chromium.launch();p=b.new_page();errors=[];p.on('pageerror',lambda e:errors.append(str(e)))
 def route(r):
  path=urlsplit(r.request.url).path;calls.append((r.request.method,path))
  if path.startswith('/tbox/assets/'):
   f=ROOT/'public/assets'/path.removeprefix('/tbox/assets/');r.fulfill(body=f.read_bytes(),content_type={'.js':'text/javascript','.css':'text/css','.jpg':'image/jpeg'}.get(f.suffix,'application/octet-stream'));return
  if path in ['/','/community/','/community/my-tasks']:
   r.fulfill(body=render(mode if path=='/'else'work'if path=='/community/'else'tasks'),content_type='text/html',headers={'Content-Security-Policy':"default-src 'self';script-src 'self';style-src 'self';img-src 'self' data:"});return
  if path=='/tbox/api/me':r.fulfill(json={'member_id':1001,'username':'synthetic','csrf_token':'synthetic','can_community_write':False});return
  if path=='/tbox/api/me/home-dashboard':r.fulfill(json={'layout':layout,'revision':7});return
  if path=='/community/api/my-tasks':r.fulfill(json={'buckets':[],'today':'2026-10-12'});return
  if path=='/community/api/briefing':r.fulfill(json={'top':[],'missed':[],'others':[]});return
  r.fulfill(json={'items':[],'pages':[],'spaces':[],'files':[],'folders':[],'projects':[],'integrations':[],'automations':[],'procedures':[]})
 p.route('**/*',route)
 for width in [360,390,1280]:
  p.set_viewport_size({'width':width,'height':900})
  for version in [1,3]:
   layout=json.loads(json.dumps(DEFAULT))if version==3 else{'version':1,'widgets':[{'id':i,'visible':True,'size':'wide'}for i in ['recent','services','tools','stories']],'source':'all','view':'recent'}
   if version==3:layout['shortcuts']=['tasks','work','account'];layout['widgets'][0]['locked']=True;layout['widgets'][0]['source']={'kind':'pages'}
   original=json.dumps(layout,sort_keys=True);mode='signed';calls.clear();p.goto('https://shortcut.example/',wait_until='networkidle');tag=f'v{version}-{width}'
   # Q:F-12 U5-3(사용자 결정 2026-10-13 「업무 홈과 내 작업은 같은 기능」) — 「업무 홈」 하나 · 「내 작업」 0. 저장된 옛 구성의 tasks 는 건너뛴다(오류 · 빈 칸 0)
   wanted=['work','account']if version==3 else['spaces','work','library','messages','assistant']
   check(p.locator('#dashboard-services a').evaluate_all('(ns)=>ns.map(n=>n.dataset.homeShortcut)')==wanted,'saved/default IDs and order preserved · old tasks skipped '+tag)
   work=p.locator('[data-home-shortcut=work]');tasks=p.locator('[data-home-shortcut=tasks]')
   check(work.get_attribute('href')=='/community/'and tasks.count()==0 and p.locator('#home-shortcut-template [data-shortcut=tasks]').count()==0,'one 업무 홈 route · 내 작업 tile 0 '+tag)
   check(work.inner_text().strip()=='업무 홈'and p.locator('#dashboard-services').inner_text().count('내 작업')==0,'업무 홈 name once · 내 작업 0 '+tag)
   check('프로젝트'in work.get_attribute('title')and '내 할 일'in work.get_attribute('title'),'one description covers both purposes '+tag)
   check(work.locator('svg path').get_attribute('d')==ICONS['work'],'업무 홈 uses the work icon '+tag)
   check(p.evaluate('document.documentElement.scrollWidth<=innerWidth'),'home no overflow '+tag)
   # 사이트 H1 (DESIGN 2.31 「머리」) — 「전체 서비스」 메뉴는 없어졌다(오른쪽 위 = 셸과 같은 div.shell-tools). 옛 단언 「all-services seven routes」를 바꿈
   check(p.locator('#home-all-services,.home-services-menu').count()==0 and p.locator('body > header div.shell-tools').count()==1,'H1 all-services menu replaced by the shared top-right tools '+tag)
   if version==3 and width in [390,1280]:p.screenshot(path=str(OUT/f'home-shortcuts-{width}.png'))
   work.click();p.wait_for_url('https://shortcut.example/community/');p.wait_for_selector('#work-home')
   check(p.locator('#work-home').count()==1 and p.locator('#work-my-tasks').count()==0,'actual click loads overall work home '+tag)
   check(p.locator('#work-home-projects').count()>0 or '프로젝트'in p.locator('#work-home').inner_text(),'overall home retains projects/summary '+tag)
   p.goto('https://shortcut.example/',wait_until='networkidle')
   check(json.dumps(layout,sort_keys=True)==original and not any(m not in ['GET','HEAD']for m,_ in calls),'preferences/geometry/lock/source unchanged and no writes '+tag)
 mode='anon';p.goto('https://shortcut.example/',wait_until='networkidle')
 check(p.locator('.moa-home-route, .moa-service-card').count()==0 and '업무' in p.locator('.landing-features').inner_text(),'H2 public quick-start · service cards replaced by 「가입하면 할 수 있는 일」 (DESIGN 2.32 ② — links 0 before login)')   # 사이트 H2 — 옛 두 단언(.moa-home-route 「내 작업」 · .moa-service-card 「업무 홈」)은 로그인 전 갈래에서 빠졌다
 check(p.locator('#home-edit,[data-home-shortcut]').count()==0,'anonymous private dashboard remains absent')
 mode='consent';p.goto('https://shortcut.example/',wait_until='networkidle');check(p.locator('#home-open-dashboard').get_attribute('href')=='/tbox/consent','mandatory consent gate retained')
 check(not errors,'production browser JS no errors '+str(errors));b.close()
(OUT/'results.json').write_text(json.dumps({'passed':len(checks),'checks':checks,'launcher_sha256':hashlib.sha256((ROOT/'src/views/launcher.php').read_bytes()).hexdigest()},ensure_ascii=False,indent=2));print(len(checks),'checks passed')
