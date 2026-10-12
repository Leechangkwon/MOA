"""Actual PHP/production JS, synthetic list/delete/save API; never uses live data."""
import json, os, subprocess
from pathlib import Path
from urllib.parse import urlsplit, parse_qs
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[2]
OUT=Path(os.environ.get('DOCUMENT_TRASH_RESULTS','/tmp/document-trash-checks'));OUT.mkdir(parents=True,exist_ok=True)
checks=[]
def check(ok,name):
 assert ok,name
 checks.append(name);print('PASS '+name,flush=True)
def uid(n):return f'00000000-0000-4000-8000-{n:012d}'
def sample(n,title,parent=None,allowed=True):return {'id':uid(n),'title':title,'icon':'','parent_id':parent,'can_delete':allowed,'visibility':'private','status':None,'updated_at':'2026-10-12T00:00:00Z','excerpt':'합성 내용'}
original=[sample(1,'합성 부모'),sample(2,'합성 자식',uid(1)),sample(3,'합성 손자',uid(2)),sample(4,'권한 없는 문서',allowed=False),sample(5,'별도 문서'),sample(6,'부모 없는 검색 결과',uid(99))]
mode='home';guest=False;data=original.copy();deleted=[];writes=[];deny={};save_status=200;saved={'schema':1,'blocks':[{'id':uid(101),'type':'paragraph','text':[{'t':'쓰던 합성 내용','m':{}}]}]};revision=1
htmls={}
def html():
 key=(mode,guest)
 if key not in htmls:htmls[key]=subprocess.check_output(['php',str(ROOT/'tests/fixtures/document_trash_render.php'),mode,'guest'if guest else'employee'],text=True)
 return htmls[key]
with sync_playwright() as pw:
 browser=pw.chromium.launch();context=browser.new_context(viewport={'width':1280,'height':960});page=context.new_page();errors=[];page.on('pageerror',lambda e:(errors.append(str(e)),print('JSERROR',str(e),flush=True)))
 def route(r):
  global data,deleted,revision,saved
  p=urlsplit(r.request.url).path;q=parse_qs(urlsplit(r.request.url).query);method=r.request.method
  if p.startswith('/tbox/assets/'):
   f=ROOT/'public/assets'/p.removeprefix('/tbox/assets/');r.fulfill(body=f.read_bytes(),content_type='text/javascript'if f.suffix=='.js'else'text/css');return
  if p=='/tbox/api/me':r.fulfill(json={'username':'synthetic_trash','display_name':'합성 검토','csrf_token':'synthetic','can_community_write':not guest,'role':'Guest'if guest else'Employee'});return
  if p=='/community/api/pages':
   visible=data
   if q.get('view')==['projects']:r.fulfill(json={'pages':[dict(x,purpose='project',progress=None)for x in data if x['id']==uid(1)],'more':False});return
   if q.get('shared')==['me']:visible=[]
   elif q.get('q'):visible=[x for x in data if q['q'][0] in x['title']]
   elif q.get('status'):visible=[x for x in data if x['id'] in [uid(2),uid(6)]]
   r.fulfill(json={'pages':visible,'total':len(visible),'space':{'id':1,'kind':'personal','name':'합성 공간'}});return
  if p in ['/community/api/me/favorites','/community/api/me/recent']:r.fulfill(json={'pages':[x for x in data if x['id']in [uid(1),uid(5)] ]});return
  if p in ['/community/api/spaces','/community/api/me/space']:r.fulfill(json={'spaces':[{'id':1,'kind':'personal','name':'합성 공간','can_write':not guest}],'space':{'id':1,'kind':'personal','name':'합성 공간'}});return
  if p.startswith('/community/api/pages/'):
   pid=p.split('/')[4]
   if p.endswith('/restore'):
    writes.append((method,p));status=deny.get('restore',200)
    if status!=200:r.fulfill(status=status,json={'error':'합성 복원 세션 오류'});return
    targets={pid};changed=True
    while changed:
     changed=False
     for x in deleted:
      if x['parent_id']in targets and x['id']not in targets:targets.add(x['id']);changed=True
    data.extend(x for x in deleted if x['id']in targets);deleted=[x for x in deleted if x['id']not in targets];r.fulfill(json={'restored':len(targets)});return
   if method=='DELETE':
    writes.append((method,p));status=deny.get(pid,200)
    if status!=200:r.fulfill(status=403 if status=='reauth' else status,json={'error':'합성 권한 또는 세션 오류',**({'next':'reauth'}if status=='reauth'else{})});return
    targets={pid};changed=True
    while changed:
     changed=False
     for x in data:
      if x['parent_id']in targets and x['id']not in targets:targets.add(x['id']);changed=True
    deleted.extend(x for x in data if x['id']in targets);data=[x for x in data if x['id']not in targets];r.fulfill(json={'deleted':len(targets)});return
   if method=='PATCH':
    writes.append((method,p));payload=r.request.post_data_json
    if save_status==200:saved=payload.get('doc',saved);revision+=1;r.fulfill(json={'doc_version':revision,'version':revision,'updated_at':'2026-10-12 01:00:00','title':payload.get('title','합성 자식')})
    else:r.fulfill(status=save_status,json={'error':'합성 저장 실패','current_version':99})
    return
   if p== '/community/api/pages/'+uid(2):
    r.fulfill(json={'page':{'id':uid(2),'title':'합성 자식','doc':saved,'version':revision,'visibility':'private','status':None},'doc':saved,'version':revision,'title':'합성 자식','can_edit':True,'can_manage':True,'children':[],'breadcrumb':[{'id':uid(1),'title':'합성 부모'},{'id':uid(2),'title':'합성 자식'}],'space':{'id':1,'kind':'personal','name':'합성 공간'}});return
  if p.startswith('/community/api/')or p.startswith('/tbox/api/'):
   r.fulfill(json={'items':[],'pages':[],'boards':[],'notifications':[],'templates':[],'reads':[],'users':[],'teams':[],'comments':[],'count':0,'unread':0});return
  r.fulfill(body=html(),content_type='text/html',headers={'Content-Security-Policy':"default-src 'self';script-src 'self';style-src 'self';img-src 'self' data:;frame-src 'self'"})
 page.route('**/*',route)
 def enter():
  page.goto('https://trash.example/community/'+('p/'+uid(2) if mode=='page'else'workspace'),wait_until='networkidle');page.wait_for_timeout(250)
 def select_home():page.click('#community-select')
 def confirm():page.click('#document-trash-confirm');page.wait_for_selector('#document-trash-summary:not(:empty)')
 def close_results():page.click('#document-trash-close');page.wait_for_timeout(200)
 enter();page.wait_for_selector('[data-home-document-trash]')
 check(not errors,'production boot has no JS errors '+str(errors))
 check(all(not page.locator('#'+x).evaluate('(n)=>n.open')for x in ['nav-community-recent-group','nav-work-projects-group','nav-community-documents-box']),'three outer groups default closed')
 check(page.locator('[data-home-document-trash="'+uid(4)+'"]').count()==0,'no row trash for denied capability')
 check(page.locator('.page-tree__link[href$="'+uid(6)+'"]').count()==1,'orphan visible as current-list root')
 page.fill('#community-search','검색');page.wait_for_timeout(450);select_home();check(page.input_value('#community-search')=='검색','selection preserves search query')
 check(page.locator('#community-list input[data-bulk-page]').count()==1,'search selection check rendered')
 page.click('#community-bulk-all');check(page.inner_text('#community-bulk-count')=='1개 선택됨','search current list all selects orphan')
 page.click('#community-bulk-trash');check(uid(6)not in page.inner_text('#document-trash-items'),'confirmation displays title, not UUID')
 before=len(writes);page.keyboard.press('Escape');check(len(writes)==before,'Escape confirmation sends no DELETE')
 page.click('#community-bulk-trash');confirm();check([p for m,p in writes if m=='DELETE']==['/community/api/pages/'+uid(6)],'orphan DELETE exactly once')
 check(page.input_value('#community-search')=='검색','delete refresh retains query')
 page.click('#document-trash-undo');page.wait_for_timeout(300);check(any(x['id']==uid(6)for x in data),'successful root undo restores orphan');close_results()
 data=original.copy();deleted=[];writes.clear();enter();select_home();page.click('#community-bulk-all')
 check(not page.locator('input[data-bulk-page="'+uid(4)+'"]').is_disabled(),'home multipurpose selection retains favorite support for read-only row')
 check(page.inner_text('#community-bulk-count')=='6개 선택됨','home all retains all readable current items')
 page.click('#community-bulk-trash');confirm();check([p for m,p in writes if m=='DELETE'].count('/community/api/pages/'+uid(1))==1 and not any(p.endswith(uid(2))or p.endswith(uid(3))for m,p in writes if m=='DELETE'),'selected ancestor cascades with no child duplicate requests')
 page.click('#document-trash-undo');page.wait_for_timeout(300);check(len(data)==len(original),'undo restores root subtrees only');close_results()
 data=original.copy();writes.clear();enter();page.locator('#nav-community-documents-box>summary').click();page.click('#sidebar-document-select')
 matching=page.locator('[data-sidebar-document-check="'+uid(1)+'"]:visible');matching.first.check();check(all(page.locator('[data-sidebar-document-check="'+uid(1)+'"]').nth(i).is_checked()for i in range(page.locator('[data-sidebar-document-check="'+uid(1)+'"]').count())),'duplicate UUID selection synchronized')
 page.click('#sidebar-document-all');check(not page.locator('[data-sidebar-document-check="'+uid(3)+'"]').first.is_checked(),'collapsed sidebar descendants excluded from all')
 page.click('#sidebar-document-clear');check(page.inner_text('#sidebar-document-count')=='0개 선택','sidebar clear explicit')
 page.locator('[data-document-trash="'+uid(5)+'"]:visible').first.click();check(page.locator('#document-trash-dialog').evaluate('(n)=>n.matches(":modal")'),'single sidebar row opens native confirmation');page.click('#document-trash-cancel');check(not writes,'cancel single sidebar no write')
 # Account preference must survive mobile auto-collapse and return.
 key='qaredi-sidebar-folds:v1:synthetic_trash';page.wait_for_timeout(80);check(page.evaluate('(k)=>JSON.parse(localStorage.getItem(k)).groups["nav-community-documents-box"]',key),'direct desktop fold saved')
 page.set_viewport_size({'width':390,'height':900});page.wait_for_timeout(100);page.set_viewport_size({'width':1280,'height':960});page.wait_for_timeout(100);check(page.locator('#nav-community-documents-box').evaluate('(n)=>n.open'),'mobile roundtrip preserves desktop choice')
 # Partial rejection is not counted as success or offered for undo.
 deny={uid(5):403};page.locator('[data-document-trash="'+uid(5)+'"]:visible').first.click();confirm();check(page.inner_text('#document-trash-summary').startswith('0개 완료 · 1개 못 함'),'403 retains explicit failure');check(page.locator('#document-trash-undo').is_hidden(),'failed root never offered undo');close_results();deny={}
 # Paused DELETE Escape cancels only unsent remainder and frees the controller.
 deny={uid(5):401};page.locator('[data-document-trash="'+uid(5)+'"]:visible').first.click();page.click('#document-trash-confirm');page.wait_for_selector('#document-trash-stop:visible');check(page.locator('#document-trash-results').evaluate('(n)=>n.open'),'401 pause retains results and remaining work')
 before=len(writes);page.keyboard.press('Escape');page.wait_for_timeout(100);check(len(writes)==before,'paused Escape sends no further request')
 deny={};page.locator('[data-document-trash="'+uid(5)+'"]:visible').first.click();check(page.locator('#document-trash-dialog').evaluate('(n)=>n.open'),'paused Escape releases controller for new work');confirm()
 check(page.locator('#nav-work-new-project').count()==1,'project creation action retained after refresh')
 deny={'restore':401};page.click('#document-trash-undo');page.wait_for_selector('#document-trash-stop:visible');page.keyboard.press('Escape');page.wait_for_timeout(100);check(not page.locator('#document-trash-results').evaluate('(n)=>n.open'),'undo current-job pause Escape closes correctly')
 deny={};data=original.copy();enter();page.locator('#nav-community-documents-box>summary').click() if not page.locator('#nav-community-documents-box').evaluate('(n)=>n.open') else None
 page.evaluate('window.qarediHandleReauth=(res,data,cb)=>{window.syntheticReauth=cb}')
 deny={uid(5):'reauth'};page.locator('[data-document-trash="'+uid(5)+'"]:visible').first.click();page.click('#document-trash-confirm');page.wait_for_function('()=>typeof window.syntheticReauth==="function"');check(not page.locator('#document-trash-results').evaluate('(n)=>n.open'),'reauth temporarily closes result modal')
 deny={};page.evaluate('window.syntheticReauth()');page.wait_for_selector('#document-trash-summary:not(:empty)');check(page.inner_text('#document-trash-summary').startswith('1개 완료'),'reauth callback resumes existing job');check(page.locator('#document-trash-results').evaluate('(n)=>n.open'),'reauth reopens result modal');close_results()
 # 100 limit shown truthfully.
 data=[sample(i,'합성 목록 '+str(i))for i in range(1,106)];enter();select_home();page.click('#community-bulk-all');check(page.inner_text('#community-bulk-count')=='100개 선택됨'and'105개 중 100개' in page.inner_text('#community-bulk-note'),'105 current allowed capped at100 with truthful notice')
 # Filter roots and immediate selection invalidation.
 page.select_option('#community-status','todo');page.wait_for_timeout(200);check(page.inner_text('#community-bulk-count')=='0개 선택됨','filter change clears selection');check(page.locator('.page-tree__link').count()==2,'filtered children rendered without absent parent')
 data=original.copy();mode='page';writes.clear();enter();page.wait_for_selector('#page-edit:visible');page.click('#page-edit');page.wait_for_selector('#page-editor .ProseMirror');page.locator('#page-editor .ProseMirror').click();page.keyboard.press('End');page.keyboard.insert_text(' 정상저장');caret=page.locator('#page-editor .ProseMirror').inner_text()
 page.locator('#nav-community-documents-box>summary').click() if not page.locator('#nav-community-documents-box').evaluate('(n)=>n.open') else None
 page.locator('[data-document-trash="'+uid(5)+'"]:visible').first.click();confirm();check([m for m,p in writes][:2]==['PATCH','DELETE'],'dirty editor flushed successfully before unrelated DELETE');check(any('정상저장' in span['t']for block in saved['blocks']for span in block.get('text',[])),'actual saved document contains latest text');check(page.locator('#page-editor .ProseMirror').inner_text()==caret,'unrelated delete preserves live editor content');close_results()
 data=original.copy();writes.clear();enter();page.wait_for_selector('#page-edit:visible');page.locator('#nav-community-documents-box>summary').click() if not page.locator('#nav-community-documents-box').evaluate('(n)=>n.open')else None
 page.locator('[data-document-trash="'+uid(1)+'"]:visible').first.click();confirm();check(page.locator('#page-doc').inner_text()=='','deleted ancestor clears current read body immediately');check('/p/'+uid(2)in page.url and page.locator('#document-trash-undo').is_visible(),'current cascade retains undo window before navigation');page.click('#document-trash-undo');page.wait_for_timeout(500);check(any(x['id']==uid(2)for x in data),'current ancestor undo restores child before return')
 data=original.copy();mode='page';writes.clear();enter();page.wait_for_selector('#page-edit:visible');page.click('#page-edit');page.wait_for_selector('#page-editor .ProseMirror');page.locator('#page-editor .ProseMirror').click();page.keyboard.press('End');page.keyboard.insert_text(' 고침')
 page.locator('#nav-community-documents-box>summary').click() if not page.locator('#nav-community-documents-box').evaluate('(n)=>n.open')else None
 save_status=409;page.locator('[data-document-trash="'+uid(5)+'"]:visible').first.click();page.click('#document-trash-confirm');page.wait_for_timeout(250)
 check(not any(m=='DELETE'for m,p in writes),'dirty409 blocks sidebar deletion')
 check('고침' in page.locator('#page-editor .ProseMirror').inner_text(),'409 blocked deletion preserves editor text')
 check(page.locator('#document-trash-dialog').evaluate('(n)=>n.open'),'save failure confirmation remains open with explanation')
 check('충돌' in page.inner_text('#document-trash-msg')or'저장' in page.inner_text('#document-trash-msg'),'save conflict has visible blocking reason')
 check(not errors,'all production interactions no JS errors '+str(errors))
 page.screenshot(path=str(OUT/'dirty-conflict-1280.png'))
 # Fresh mobile home: confirmation above native navigation, complete focus path.
 mode='home';data=original.copy();save_status=200;page.set_viewport_size({'width':390,'height':900});enter();page.click('#nav-toggle');page.wait_for_selector('#mobile-nav-dialog[open]');page.locator('#nav-community-documents-box>summary').click();page.locator('[data-document-trash="'+uid(5)+'"]:visible').first.click();check(page.locator('#document-trash-dialog').evaluate('(n)=>n.matches(":modal")'),'mobile confirmation above native drawer');page.screenshot(path=str(OUT/'mobile-confirm-390.png'));page.keyboard.press('Escape');check(page.locator('#mobile-nav-dialog').evaluate('(n)=>n.open'),'Escape closes only confirmation, preserves navigation drawer')
 page.set_viewport_size({'width':360,'height':900});check(page.evaluate('document.documentElement.scrollWidth<=innerWidth'),'mobile360 no document overflow')
 # A real touch context verifies actual hit areas, not only a narrow desktop viewport.
 touch_context=browser.new_context(viewport={'width':360,'height':900},is_mobile=True,has_touch=True);touch=touch_context.new_page();touch.route('**/*',route);touch.goto('https://trash.example/community/workspace',wait_until='networkidle');touch.click('#nav-toggle');touch.locator('#nav-community-documents-box>summary').click();touch.click('#sidebar-document-select')
 geometry=touch.locator('#nav-community-tree .nav-tree__row').first.evaluate("""n=>{const box=e=>{const r=e.getBoundingClientRect();return{x:r.x,y:r.y,w:r.width,h:r.height,right:r.right}};return{check:box(n.querySelector('.document-select-hit')),star:box(n.querySelector('[data-fav-toggle]')),trash:box(n.querySelector('[data-document-trash]'))}}""")
 check(geometry['check']['w']>=44 and geometry['check']['h']>=44 and geometry['trash']['w']>=44 and geometry['trash']['h']>=44,'touch checkbox and trash actual44px targets')
 check(geometry['check']['right']<=geometry['star']['x']and geometry['star']['right']<=geometry['trash']['x'],'touch targets do not overlap')
 touch.screenshot(path=str(OUT/'sidebar-selection-360.png'));touch_context.close()
 check(not errors,'final no production errors')
 browser.close()
(OUT/'results.json').write_text(json.dumps({'passed':len(checks),'failed':0,'checks':checks},ensure_ascii=False,indent=2));print(len(checks),'checks passed')
