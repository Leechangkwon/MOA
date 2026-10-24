"""Workflow regression against rendered PHP and real app/design/start JS; APIs mocked, no DB writes."""
import json, os, socket, subprocess, time
from pathlib import Path
from urllib.parse import urlparse, parse_qs
from playwright.sync_api import sync_playwright, expect
ROOT = Path(__file__).resolve().parents[1]
OUT = Path(os.environ.get('UX_RESULTS', '/tmp/qaredi-ux-results')); OUT.mkdir(parents=True, exist_ok=True)
with socket.socket() as sock:
    sock.bind(('127.0.0.1',0)); port=sock.getsockname()[1]
BASE=f'http://127.0.0.1:{port}'
server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(ROOT/'public'),str(ROOT/'tests/design_fixture.php')],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
time.sleep(.4)
checks=0; failures=[]; errors=[]; writes=[]; requests=[]
def check(label,value):
    global checks
    checks+=1
    if not value: failures.append(label)
def file(n, **extra):
    item = dict(id=f'10000000-0000-4000-8000-{n:012d}',name=f'{n:02d}_QAREDI_분기별_협업_프로젝트_진행현황_최종검토본.xlsx',size_bytes=456789,access_level='internal',uploaded_by='검토 담당자',created_at='2026-09-30T12:00:00Z',description='제품 기획과 디자인 검토 자료',team_name='제품 디자인팀',preview_kind='none',scan_status='clean',exec_content=False,can_edit=True,can_delete=True)
    item.update(extra); return item
files=[file(1),file(2,can_edit=False,can_delete=False),file(3,scan_status='pending'),file(4,exec_content=True),file(5,can_edit=False,can_delete=True)]
files[1]['name']='<img src=x onerror=alert(1)>_검토.pdf'
model={'empty':False,'upload':True,'gate':None,'personal':'normal','denied':False,'me_status':200}
def api(route):
    parsed=urlparse(route.request.url); path=parsed.path; query=parse_qs(parsed.query)
    requests.append((route.request.method,path))
    if route.request.method != 'GET':
        writes.append(path); route.fulfill(status=403,json={'error':'시험에서 쓰기를 차단했습니다.'}); return
    if path.endswith('/api/me'):
        data={'csrf_token':'a'*64,'username':'design_review','role':'Employee','next':model['gate'],'mfa_enabled':True,'mfa_required':False,'upload_levels':['internal'] if model['upload'] else [],'can_fetch':False,'fetch_enabled':False,'can_community_write':True,'can_admin':False}
        route.fulfill(status=model['me_status'],json=data); return
    if path.endswith('/api/files'):
        result=[] if model['empty'] or query.get('q') == ['없는검색어'] else files
        route.fulfill(json={'files':result,'total':len(result),'page':1,'per_page':20,'scope':'all' if query.get('q',[''])[0] else 'folder','folder':{'id':0,'breadcrumb':[]}}); return
    if path.endswith('/api/folders') or path.endswith('/api/folders/favorites'):
        route.fulfill(json={'folders':[]}); return
    if path.endswith('/api/teams'): route.fulfill(json={'teams':[]}); return
    if path.endswith('/preview'):
        route.fulfill(json={'kind':'none','name':files[0]['name'],'size_bytes':456789}); return
    if path.endswith('/recent') or path.endswith('/favorites'):
        community=path.startswith('/community/'); favorite=path.endswith('/favorites')
        if model['personal']=='invalid': data={'pages':[None,{'id':'javascript:alert(1)','title':'unsafe'},{'id':files[0]['id'],'title':'<svg onload=alert(1)>'}]} if community else {'files':[None,{'id':'../x','name':'unsafe'},files[0]]}
        elif model['personal']=='many': data={'pages':[]} if community else {'files':[file(n) for n in range(1,10)]}
        elif model['personal']=='empty' or (favorite and model['personal']=='normal'): data={'pages':[]} if community else {'files':[]}
        elif community: data={'pages':[{'id':'20000000-0000-4000-8000-000000000001','title':'팀 디자인 원칙','last_viewed_at':'2026-10-01T03:00:00Z'}]}
        else: data={'files':[dict(files[0],last_used_at='2026-10-01T02:00:00Z')]}
        route.fulfill(status=403 if model['denied'] and community else 200,json=data); return
    route.fulfill(json={})
try:
 with sync_playwright() as p:
    browser=p.chromium.launch(); context=browser.new_context(user_agent='tbox-review/2026-10-01')
    context.route('**/api/**',api)
    context.route('**/assets/search.js?*',lambda r:r.fulfill(content_type='text/javascript',body=''))
    context.route('**/assets/community/editor.js?*',lambda r:r.fulfill(content_type='text/javascript',body=''))
    page=context.new_page(); page.on('pageerror',lambda e:errors.append(str(e))); page.set_default_timeout(7000)
    def theme(name,brightness='light'):
        context.clear_cookies(); context.add_cookies([{'name':'qaredi_design','value':name,'url':BASE},{'name':'tbox_theme','value':brightness,'url':BASE}])
    def open_files():
        page.goto(BASE+'/?screen=files'); expect(page.locator('#files-body tr')).to_have_count(0 if model['empty'] else len(files))
    def screenshot(name): page.mouse.move(0,0); page.evaluate("window.scrollTo(0,0)"); page.wait_for_timeout(700); page.screenshot(path=str(OUT/name),full_page=True)
    theme('astra'); page.set_viewport_size({'width':1440,'height':1000}); open_files()
    check('server count shown',page.locator('#q-location-count').inner_text()=='5개의 파일')
    check('safe filename text',page.locator('.q-file-name').nth(1).inner_text()==files[1]['name'] and page.locator('#files-body img').count()==0)
    check('detail preserved',page.locator('.q-file-name-detail').first.inner_text()=='팀: 제품 디자인팀 · 제품 기획과 디자인 검토 자료')
    check('folder help folded',not page.locator('.q-folder-help').evaluate('(e)=>e.open'))
    page.locator('.q-folder-help summary').click(); check('folder help can open',page.locator('.q-folder-help').evaluate('(e)=>e.open')); page.locator('.q-folder-help summary').click()
    row=page.locator('#files-body tr').first; more=row.locator('.q-file-more'); before=len(requests); more.click()
    expect(page.locator('#q-file-actions-dialog')).to_be_visible()
    check('opening more makes no request',len(requests)==before)
    check('allowed actions only',page.locator('#q-file-actions-list').inner_text().splitlines()==['수정','공유 링크 만들기','폴더로 이동','지우기'])
    check('dialog filename',page.locator('#q-file-actions-title').inner_text()==files[0]['name'])
    page.keyboard.press('Escape'); expect(page.locator('#q-file-actions-dialog')).not_to_be_visible()
    check('Escape restores focus',more.evaluate('(e)=>e===document.activeElement'))
    more.click(); page.locator('#q-file-actions-list button[aria-label="수정"]').click()
    expect(page.locator('#file-edit-dialog')).to_be_visible(); page.keyboard.press('Escape')
    more.click(); page.locator('#q-file-actions-list button[aria-label="지우기"]').click()
    expect(page.locator('dialog[open]')).to_be_visible(); check('delete confirmation before write',len(writes)==0); page.keyboard.press('Escape')
    more.click()
    page.locator('#q-file-actions-list button[aria-label="공유 링크 만들기"]').click()
    expect(page.locator('#share-dialog')).to_be_visible(); check('share opens without write',len(writes)==0); page.keyboard.press('Escape')
    more.click(); page.locator('#q-file-actions-list button[aria-label="폴더로 이동"]').click()
    expect(page.locator('#move-dialog')).to_be_visible(); check('move opens without write',len(writes)==0); page.keyboard.press('Escape')
    check('read-only row lacks more',page.locator('#files-body tr').nth(1).locator('.q-file-more').count()==0)
    check('scan blocks download and preview',page.locator('#files-body tr').nth(2).locator('td.actions > button:disabled').count()==2)
    page.locator('#files-body tr').nth(3).locator('.q-file-more').click()
    check('executable lacks share',page.locator('#q-file-actions-list button[aria-label="공유 링크 만들기"]').count()==0); page.keyboard.press('Escape')
    page.locator('#files-body tr').nth(4).locator('.q-file-more').click()
    check('delete-only role menu',page.locator('#q-file-actions-list').inner_text()=='지우기'); page.keyboard.press('Escape')
    screenshot('astra-files-desktop.png')
    page.locator('#search-q').fill('없는검색어'); page.locator('#search-form').evaluate('(e)=>e.requestSubmit()')
    expect(page.locator('#files-body tr')).to_have_count(0); expect(page.locator('#q-location-title')).to_have_text('검색 결과')
    check('search explicitly global',page.locator('#q-location-count').inner_text()=='전체 폴더에서 0개의 파일')
    expect(page.locator('#q-files-empty-action')).to_have_text('검색 지우기'); page.locator('#q-files-empty-action').click()
    expect(page.locator('#files-body tr')).to_have_count(5); check('clear search',page.locator('#search-q').input_value()=='')
    page.locator('#files-view-favorites').click(); expect(page.locator('#q-location-title')).to_have_text('즐겨찾기')
    expect(page.locator('#q-files-empty-action')).to_have_text('파일 보기'); page.locator('#q-files-empty-action').click(); expect(page.locator('#files-body tr')).to_have_count(5)
    model['empty']=True; open_files(); expect(page.locator('#q-files-empty-action')).to_have_text('파일 올리기'); page.locator('#q-files-empty-action').click(); expect(page.locator('#upload-dialog')).to_be_visible(); page.keyboard.press('Escape')
    screenshot('astra-empty.png')
    model['upload']=False; open_files(); check('no upload permission no empty action',page.locator('#q-files-empty-action').is_hidden())
    model['gate']='password_change'; open_files(); check('first login no new action',page.locator('#q-files-empty-action').is_hidden())
    model.update(empty=False,upload=True,gate=None)
    for width in [360,412,820,1280,1440]:
      page.set_viewport_size({'width':width,'height':960})
      for name in ['claude','astra','aurora','bloom','nova']:
       for brightness in ['light','dark']:
        theme(name,brightness); open_files(); tag=f'{width}/{name}/{brightness}'
        check(tag+' no document overflow',page.evaluate('document.documentElement.scrollWidth<=innerWidth'))
        check(tag+' preserved text',page.locator('.q-file-name').first.inner_text()==files[0]['name'])
        check(tag+' original actions or more',more.is_visible()==(name!='claude') and row.locator('.q-file-secondary').is_visible()==(name=='claude'))
        check(tag+' layout components',page.locator('.q-location').is_visible()==(name!='claude'))
        if width in [360,1280] and name in ['astra','nova'] and brightness=='light': screenshot(f'{name}-files-{width}.png')
        page.goto(BASE+'/?screen=launcher')
        if name!='claude': expect(page.locator('.q-start-item')).to_have_count(2)
        check(tag+' personal layout',page.evaluate('document.documentElement.scrollWidth<=innerWidth'))
    theme('nova'); page.set_viewport_size({'width':1440,'height':1080}); page.goto(BASE+'/?screen=launcher'); expect(page.locator('.q-start-item')).to_have_count(2)
    check('recent items from both modules',page.locator('.q-start-kind').all_inner_texts()==['업무','자료실'])
    check('only validated local href',all(h.startswith(('/?q=','/community/p/')) for h in page.locator('.q-start-item').evaluate_all('(es)=>es.map(e=>e.getAttribute("href"))')))
    screenshot('nova-start.png')
    page.locator('[data-start-view="favorites"]').click(); expect(page.locator('#q-start-status')).to_contain_text('별을 달면'); check('favorite empty',page.locator('.q-start-item').count()==0)
    model['personal']='invalid'; page.locator('[data-start-view="recent"]').click(); expect(page.locator('.q-start-item')).to_have_count(2)
    check('invalid IDs skipped and titles text',page.locator('.q-start-item img,.q-start-item svg').count()==0 and '<svg' in ' '.join(page.locator('.q-start-item').all_inner_texts()))
    model['personal']='many'; page.locator('#q-start-retry').click(); expect(page.locator('.q-start-item')).to_have_count(6)
    check('dashboard caps six', '9개 중 6개' in page.locator('#q-start-status').inner_text())
    model['personal']='normal'; held=[]
    def hold_recent(route): held.append(route)
    page.route('**/api/files/recent',hold_recent); page.reload(); page.wait_for_timeout(100)
    expect(page.locator('#q-start')).to_have_attribute('aria-busy','true')
    page.locator('[data-start-view="favorites"]').click(); expect(page.locator('#q-start-status')).to_contain_text('별을 달면')
    check('old request captured',len(held)==1)
    for route in held: route.fulfill(json={'files':[files[0]]})
    page.wait_for_timeout(100); check('old recent cannot overwrite favorites',page.locator('.q-start-item').count()==0 and page.locator('[data-start-view="favorites"]').get_attribute('aria-pressed')=='true')
    page.unroute('**/api/files/recent',hold_recent)
    model['personal']='invalid'; page.locator('[data-start-view="recent"]').click(); expect(page.locator('.q-start-item')).to_have_count(2)
    model['denied']=True; page.locator('#q-start-retry').click(); expect(page.locator('.q-start-item')).to_have_count(1); expect(page.locator('#q-start-status')).to_contain_text('일부 항목')
    model.update(denied=False,personal='normal',gate='password_change'); requests.clear(); page.reload(); expect(page.locator('#q-start-status')).to_contain_text('계정 설정')
    check('first login no personal API',all(path.endswith('/api/me') for method,path in requests))
    model.update(gate=None,me_status=401); requests.clear(); page.reload(); expect(page.locator('#q-start-status')).to_contain_text('불러오지 못했습니다')
    check('unauthenticated no personal API',all(path.endswith('/api/me') for method,path in requests))
    model['me_status']=200; theme('claude'); requests.clear(); page.reload(); page.wait_for_timeout(100)
    check('Claude personal hidden and requests zero',page.locator('#q-start').is_hidden() and not requests)
    theme('astra'); page.goto(BASE+'/?screen=anonymous'); check('anonymous personal absent',page.locator('#q-start,script[src*="start.js"]').count()==0)
    # Theme preview activates/deactivates readonly dashboard with canceled preview returning to Claude.
    theme('claude'); page.goto(BASE+'/?screen=launcher'); page.locator('[data-design-open]').first.click(); page.locator('[data-design-choice="astra"]').click(); expect(page.locator('.q-start-item')).to_have_count(2)
    page.keyboard.press('Escape'); expect(page.locator('#q-start')).not_to_be_visible(); check('cancel removes personal DOM',page.locator('.q-start-item').count()==0)
    theme('astra'); page.goto(BASE+'/?screen=community-home'); page.locator('#community-empty').evaluate('(e)=>e.hidden=false')
    expect(page.locator('[data-ux-community-start]')).to_be_visible()
    page.evaluate('window.testCommunityClicks=0;document.getElementById("community-new").addEventListener("click",()=>window.testCommunityClicks++)')
    page.locator('[data-ux-community-start]').click(); check('empty community forwards original action',page.evaluate('window.testCommunityClicks')==1)
    page.goto(BASE+'/?screen=community-home&guest=1'); page.locator('#community-empty').evaluate('(e)=>e.hidden=false'); check('guest create action absent',page.locator('[data-ux-community-start]').is_hidden())
    for width in [360,820,1440]:
      page.set_viewport_size({'width':width,'height':960}); page.goto(BASE+'/?screen=community-page')
      page.evaluate('''()=>{document.getElementById('page-title').textContent='함께 일하는 방식, QAREDI 디자인 원칙';let d=document.getElementById('page-doc');d.hidden=false;let h=document.createElement('h2');h.className='doc-h2';h.textContent='기록은 읽기 쉬워야 합니다';let text=document.createElement('p');text.textContent='업무의 맥락을 남기고 다음 사람이 바로 이어 갈 수 있도록 제목과 내용을 정리합니다. '.repeat(8);let content=document.createElement('div');content.className='doc';content.append(h,text);d.append(content);}''')
      check(f'{width} document overflow',page.evaluate('document.documentElement.scrollWidth<=innerWidth'))
      check(f'{width} readable line-height',float(page.locator('#page-doc .doc').evaluate('(e)=>parseFloat(getComputedStyle(e).lineHeight)/parseFloat(getComputedStyle(e).fontSize)'))>=1.75)
      if width==1440: screenshot('astra-document.png')
    check('no mutation request',not writes); check('browser exceptions',not errors); browser.close()
finally:
 server.terminate(); server.wait()
result={'checks':checks,'failures':failures,'errors':errors,'write_requests':writes,'scope':'rendered PHP, actual app/design/start JS with synthetic readonly APIs; layout and interaction, not production authorization'}
(OUT/'results.json').write_text(json.dumps(result,ensure_ascii=False,indent=2)); print(json.dumps(result,ensure_ascii=False)); raise SystemExit(bool(failures or errors))
