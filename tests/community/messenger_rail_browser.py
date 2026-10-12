"""Actual PHP shell/production assets, synthetic readonly API; never sends a message.
Q:F-12 U2 RV-1 — the left app rail (nav.app-rail) is gone; the messenger entry now lives as a tile in the top-right app grid
(div.shell-tools > details.appbar__apps#app-grid, DESIGN 2.18). Same properties as before, measured at the new place."""
import json,os,subprocess,hashlib
from pathlib import Path
from urllib.parse import urlsplit,parse_qs
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[2]
OUT=Path(os.environ.get('MESSENGER_RAIL_RESULTS','/tmp/messenger-rail'));OUT.mkdir(parents=True,exist_ok=True)
checks=[];failed=[];requests=[];kind='employee';module='files';next_step=None;metrics={}
htmls={}
def check(ok,name):
 # 실패는 적고 계속 잰다(한 단언이 뒤 단언을 가리지 않게) — 끝에 실패가 하나라도 있으면 종료 코드 1
 if ok:checks.append(name);print('PASS '+name,flush=True)
 else:failed.append(name);print('FAIL '+name,flush=True)
def render(k,m):
 if (k,m)not in htmls:htmls[k,m]=subprocess.check_output(['php',str(ROOT/'tests/community/messenger_rail_fixture.php'),k,m],text=True)
 return htmls[k,m]
# 지금 초점이 어느 요소인지 — 시험 결과에 남긴다(검토용 · 값은 합성 화면의 태그·id·클래스뿐)
FOCUS_JS='()=>{const a=document.activeElement;return {tag:a?a.tagName:"",id:a?a.id:"",cls:a&&typeof a.className==="string"?a.className:"",in_tools:!!(a&&a.closest&&a.closest(".shell-tools")),body:a===document.body}}'
with sync_playwright()as pw:
 browser=pw.chromium.launch();page=browser.new_page();errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
 def route(r):
  u=urlsplit(r.request.url);p=u.path;requests.append((r.request.method,p))
  if u.hostname!='rail.example':r.abort();return
  if p.startswith('/tbox/assets/'):
   f=ROOT/'public/assets'/p.removeprefix('/tbox/assets/');r.fulfill(body=f.read_bytes(),content_type={'.js':'text/javascript','.css':'text/css','.jpg':'image/jpeg','.png':'image/png'}.get(f.suffix,'application/octet-stream'));return
  if p=='/':r.fulfill(body=render(kind,module),content_type='text/html',headers={'Content-Security-Policy':"default-src 'self';script-src 'self';style-src 'self';img-src 'self' data:"});return
  if p=='/tbox/api/me':r.fulfill(json={'member_id':1001,'username':'synthetic_rail','display_name':'검토 사용자','csrf_token':'synthetic','role':'Guest'if kind=='guest'else'Employee','acting':kind=='acting','can_community_write':kind!='guest','next':next_step,'mfa_enabled':True});return
  if p=='/tbox/api/messages':r.fulfill(json={'people':[{'id':1002,'username':'synthetic_peer','display_name':'합성 상대','unread':3}]});return
  if p=='/tbox/messages':r.fulfill(body='<html lang="ko"><body><p>합성 기존 이용 안내</p></body></html>',content_type='text/html');return
  if r.request.method not in ['GET','HEAD']:r.fulfill(status=403,json={'error':'synthetic writes forbidden'});return
  r.fulfill(json={'items':[],'people':[],'pages':[],'threads':[],'folders':[],'files':[],'guides':[]})
 page.route('**/*',route)
 for module in ['files','community','story']:
  for kind in ['employee','guest','acting']:
   for width in [1280,960,959,390]:
    requests.clear();page.set_viewport_size({'width':width,'height':900});page.goto('https://rail.example/',wait_until='networkidle');tag=f'{module}-{kind}-{width}'
    wide=width>=960;summary=page.locator('#app-grid > summary')
    check(page.locator('#sidebar [data-messenger-open],#sidebar nav[aria-label="메신저"]').count()==0,'no inner duplicate '+tag)
    check(page.locator('nav.app-rail,.app-rail__messenger').count()==0,'old app rail gone (U2) '+tag)
    check(page.locator('div.shell-tools > details.appbar__apps#app-grid').count()==1 and page.locator('details.appbar__apps').count()==1,'one app grid in the top-right tool row '+tag)
    # 넓은 화면은 ⋮⋮⋮(격자 summary), 폰은 윗줄 말풍선 — 보이는 공통 입구는 늘 하나
    check(page.locator('#app-grid > summary:visible,#appbar-messenger:visible').count()==1 and page.locator(('#app-grid > summary' if wide else '#appbar-messenger')+':visible').count()==1,'one visible common entry '+tag)
    names=page.locator('#app-grid .app-menu__apps .app-menu__name').evaluate_all('(ns)=>ns.map(n=>n.textContent.trim())')
    metrics['tiles-'+tag]=names
    check(names.count('메신저')==1 and '이야기' in names and names.index('메신저')==names.index('이야기')+1,'grid has one messenger tile right after the app tiles '+tag+' '+json.dumps(names,ensure_ascii=False))
    if not page.evaluate('document.documentElement.scrollWidth<=innerWidth'):
     page.screenshot(path=str(OUT/('overflow-'+tag+'.png')));print('OVERFLOW',page.evaluate('''()=>[...document.querySelectorAll('body *')].filter(n=>{const r=n.getBoundingClientRect();return r.width>0&&r.right>innerWidth+1}).map(n=>[n.tagName,n.id,n.className,n.getBoundingClientRect().right]).slice(0,12)'''),flush=True)
    check(page.evaluate('document.documentElement.scrollWidth<=innerWidth'),'no page overflow '+tag)
    check(page.locator('#messenger-open').count()==(1 if kind=='employee'else 0),'permitted opener ID remains unique '+tag)
    if kind=='employee':
     tile=page.locator('#app-grid button#messenger-open[data-messenger-open]')
     check(tile.count()==1,'grid messenger tile is the window opener button (page context kept) '+tag)
     badges=page.locator('#app-grid [data-message-unread]')
     check(badges.count()==2 and tile.locator('[data-message-unread]').count()==1 and summary.locator('[data-message-unread]').count()==1,'unread badge on the tile and on the ⋮⋮⋮ summary '+tag)
     if wide:summary.click();page.wait_for_selector('#messenger-open:visible');trigger=tile
     else:trigger=page.locator('#appbar-messenger')
     trigger.click();page.wait_for_selector('#messenger-window:visible')
     check(page.locator('#messenger-window').evaluate('(n)=>n.open'),'production messenger opens '+tag)
     if wide:check(not page.locator('#app-grid').evaluate('(n)=>n.open'),'tile press closes the grid '+tag)
     check(page.locator('#synthetic-context').input_value()=='검색·폴더 상태'and urlsplit(page.url).path=='/','folder/search context unchanged '+tag)
     check(trigger.get_attribute('aria-expanded')=='true' and tile.get_attribute('aria-expanded')=='true','opener expanded state '+tag)
     unread=badges.evaluate_all('(ns)=>ns.map(n=>n.textContent)')
     check(unread==['3','3'],'existing unread observer paints tile and ⋮⋮⋮ badges '+tag+' '+json.dumps(unread))
     if not wide:check(trigger.locator('[data-message-unread]').inner_text()=='3','existing unread observer paints mobile badge '+tag)
     check(not page.locator('#messenger-window').evaluate('(n)=>n.matches(":modal")'),'existing nonmodal window/sheet contract '+tag)
     page.keyboard.press('Escape');page.wait_for_timeout(100)
     check(not page.locator('#messenger-window').evaluate('(n)=>n.open'),'Escape closes '+tag)
     focus=page.evaluate(FOCUS_JS);metrics['focus-after-close-'+tag]=focus
     # 넓은 화면: 연 타일은 격자가 닫혀 보이지 않는다 → messenger.js 가 본문 첫 초점(격자 줄)으로 돌린다. 폰: 윗줄 말풍선 그대로
     if wide:check(focus['in_tools'],'focus returns to the grid tool row '+tag+' '+json.dumps(focus))
     else:check(trigger.evaluate('(n)=>document.activeElement===n'),'focus returns to common opener '+tag)
    else:
     tile=page.locator('#app-grid a.app-menu__app[href="/tbox/messages"]')
     check(tile.count()==1,'restricted grid tile retains existing information route '+tag)
     check(tile.locator('small.messenger-access-note').count()==1 and tile.locator('small.messenger-access-note').text_content()=='이용 제한','restricted grid tile shows the 「이용 제한」 note '+tag)
     check(page.locator('#appbar-messenger .messenger-access-note').count()==0,'icon-only mobile entry gets no note '+tag)
     check(page.locator('#messenger-window').count()==0,'restricted account has no floating window '+tag)
     check(not any(p.startswith('/tbox/api/messages')for _,p in requests),'restricted account sends no messaging API requests '+tag)
     if width<960:check(page.locator('#appbar-messenger').get_attribute('href')=='/tbox/messages','mobile restricted information entry retained '+tag)
     if wide:summary.click();page.wait_for_selector('#app-grid a.app-menu__app[href="/tbox/messages"]:visible');trigger=tile
     else:trigger=page.locator('#appbar-messenger')
     trigger.click();page.wait_for_selector('#messenger-unavailable:visible')
     check(page.locator('#messenger-unavailable').evaluate('(n)=>n.matches(":modal")'),'restricted entry opens existing information dialog '+tag)
     check(urlsplit(page.url).path=='/','restricted information retains current context '+tag)
     page.keyboard.press('Escape');page.wait_for_timeout(100)
     focus=page.evaluate(FOCUS_JS);metrics['restricted-focus-after-close-'+tag]=focus
     if wide:check(focus['in_tools'],'restricted information focus returns to the grid tool row '+tag+' '+json.dumps(focus))
     else:check(trigger.evaluate('(n)=>document.activeElement===n'),'restricted information focus returns '+tag)
    check(not any(method not in ['GET','HEAD']for method,_ in requests),'no message or other API write '+tag)
    if module=='files'and kind=='employee'and width in [1280,390]:page.screenshot(path=str(OUT/f'rail-{width}.png'))
 kind='employee';module='files';next_step='mfa_setup';requests.clear();page.goto('https://rail.example/',wait_until='networkidle')
 check(not any(p.startswith('/tbox/api/messages')for _,p in requests),'first-login MFA gate prevents messenger activation')
 check(not errors,'production JS no errors '+str(errors));browser.close()
(OUT/'results.json').write_text(json.dumps({'passed':len(checks),'failed':len(failed),'checks':checks,'failures':failed,'metrics':metrics,'shell_sha256':hashlib.sha256((ROOT/'src/views/shell_top.php').read_bytes()).hexdigest()},ensure_ascii=False,indent=2));print(len(checks),'checks passed',len(failed),'failed');print(f'RESULT pass={len(checks)} fail={len(failed)}')
if failed:raise SystemExit(1)
