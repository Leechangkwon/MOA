#!/usr/bin/env python3
"""Stage11 isolated schema/row-creation foundation acceptance."""
import json, os, subprocess, time, sys
from pathlib import Path
import requests
m=json.loads(Path(os.environ['FILE_RENAME_MANIFEST']).read_text())
assert m['database'].startswith('tbox_stage11_test_')
root=Path(m['instance']); output=Path(os.environ['FILE_RENAME_OUTPUT'])
users=json.loads(Path(m['credentials_file']).read_text())['users']; checks=[]
def check(ok,name):
    checks.append({'pass':bool(ok),'name':name});print(('PASS ' if ok else 'FAIL ')+name,flush=True)
    if not ok:raise AssertionError(name)
def call(method,path,body=None,user='employee',status=200):
    u=users[user]
    resp=requests.request(method,m['base_url'].removesuffix('/tbox')+'/community'+path,json=body,
        headers={'User-Agent':'tbox-review/'+time.strftime('%Y-%m-%d'),'Cookie':'__Host-tbox_session='+u['session'],'X-CSRF-Token':u['csrf']},timeout=20)
    with (output/'http-responses.jsonl').open('a') as log: log.write(json.dumps({'method':method,'path':path,'user':user,'status':resp.status_code,'body':resp.text},ensure_ascii=False)+'\n')
    if resp.status_code!=status: print(resp.text,flush=True)
    check(resp.status_code==status,method+' '+path+' status '+str(resp.status_code))
    return resp

def sql(query):
    env={k:v for k,v in os.environ.items() if not k.startswith('PG')};env['PGPASSFILE']=str(Path.home()/'.config/qaredi/pgpass-testdb')
    done=subprocess.run(['psql','-h','127.0.0.1','-U','tbox_testdb','-w','-X','-At','-d',m['database'],'-v','ON_ERROR_STOP=1'],env=env,input=query.encode(),check=True,capture_output=True)
    return done.stdout.decode().strip()
from urllib.parse import urlsplit
from playwright.sync_api import sync_playwright,expect
import uuid
site=m['base_url'].removesuffix('/tbox')
try:
    call('GET','/api/me/space')
    db=call('POST','/api/pages',{'title':'검토 일정','database':True},status=201).json()['page'];did=db['database']['id'];pid=db['id']
    day=call('PATCH','/api/databases/'+did+'/schema',{'add':{'name':'일정','type':'date'}}).json()['database']['schema'][-1]['key']
    amount=call('PATCH','/api/databases/'+did+'/schema',{'add':{'name':'재료비','type':'number'}}).json()['database']['schema'][-1]['key']
    choice=call('PATCH','/api/databases/'+did+'/schema',{'add':{'name':'추가 입력','type':'select','options':['예','아니오']}}).json()['database']['schema'][-1]['key']
    dependency=call('PATCH','/api/databases/'+did+'/schema',{'add':{'name':'선후행','type':'relation','target':did,'depends':True}}).json()['database']['schema'][-1]['key']
    category=call('PATCH','/api/databases/'+did+'/schema',{'add':{'name':'표시 문자','type':'select','options':['<script>','\"onload=']}}).json()['database']['schema'][-1]['key']
    def view(**kw):return call('PATCH','/api/databases/'+did+'/views',{'add':kw}).json()['views'][-1]
    cal=view(name='월 일정',type='calendar',date_prop=day,mode='month');chart=view(name='합계',type='chart',kind='bar',aggregate='sum',on_prop=amount)
    line=view(name='선 차트',type='chart',kind='line',aggregate='count');donut=view(name='도넛 차트',type='chart',kind='donut',aggregate='count');number=view(name='숫자 차트',type='chart',kind='number',aggregate='count')
    timeline=view(name='선후행 일정',type='timeline',date_prop=day,depends_prop=dependency);attack=view(name='안전한 문자',type='chart',kind='bar',group_by=category,aggregate='count')
    frm=view(name='업무 접수',type='form',questions=[{'prop':'title','required':True},{'prop':choice,'required':True},{'prop':amount,'required':True,'show_if':{'prop':choice,'equals':'예'}}],open=True,audience='internal',anonymous=False)
    row=call('POST','/api/pages',{'title':'월마감 검토 자료','parent_id':pid},status=201).json()['page']['id']
    now=time.strftime('%Y-%m')+'-15';call('PATCH','/api/pages/'+row+'/values',{'values':{day:{'start':now},amount:120000,category:'<script>'}})
    row2=call('POST','/api/pages',{'title':'결과 보고','parent_id':pid},status=201).json()['page']['id'];call('PATCH','/api/pages/'+row2+'/values',{'values':{day:{'start':now[:-2]+'20'},dependency:[row],category:'\"onload='}})
    dash=call('POST','/api/pages',{'title':'재료비 대시보드'},status=201).json()['page']['id'];block={'id':str(uuid.uuid4()),'type':'db_view','database_id':did,'view_id':chart['id']}
    otherdb=call('POST','/api/pages',{'title':'날짜 없는 DB','database':True},status=201).json()['page'];oid=otherdb['database']['id']
    ov=call('PATCH','/api/databases/'+oid+'/views',{'add':{'name':'개수','type':'chart','kind':'number','aggregate':'count'}}).json()['views'][-1]
    call('POST','/api/pages',{'title':'날짜 없음','parent_id':otherdb['id']},status=201)
    blocks=[dict(block,id=str(uuid.uuid4())) for _ in range(6)]+[{'id':str(uuid.uuid4()),'type':'db_view','database_id':oid,'view_id':ov['id']} for _ in range(6)]
    call('PATCH','/api/pages/'+dash,{'doc_version':1,'title':'재료비 대시보드','doc':{'schema':1,'blocks':blocks}})
    with sync_playwright() as p:
        browser=p.chromium.launch()
        for width,theme in [(1280,'light'),(390,'dark')]:
            ctx=browser.new_context(viewport={'width':width,'height':900},locale='ko-KR',color_scheme=theme,user_agent='tbox-review/'+time.strftime('%Y-%m-%d'))
            ctx.add_cookies([{'name':'__Host-tbox_session','value':users['employee']['session'],'domain':urlsplit(site).hostname,'path':'/','secure':True,'httpOnly':True,'sameSite':'Lax'},{'name':'tbox_theme','value':theme,'domain':urlsplit(site).hostname,'path':'/'}])
            page=ctx.new_page();page.on('console',lambda msg:print('CONSOLE '+msg.type+' '+msg.text,flush=True) if msg.type=='error' else None);page.on('response',lambda r:print('HTTP_ERROR '+str(r.status)+' '+r.url,flush=True) if r.status>=400 else None);page.set_default_timeout(10000);errors=[];page.on('pageerror',lambda err:(errors.append(str(err)),print('BROWSER_ERROR '+str(err),flush=True)))
            page.goto(site+'/community/p/'+pid,wait_until='networkidle');page.screenshot(path=str(output/('initial-'+str(width)+'.png')),full_page=True);page.locator('[data-db-view="'+cal['id']+'"]').click();expect(page.locator('.db-calendar')).to_be_visible()
            check(page.locator('.db-calendar__day').count()>=28,'calendar grid rendered '+str(width))
            if width==1280:
                firstMonth=page.locator('.db-calendar__day').first.get_attribute('aria-label')[:7]
                page.get_by_role('button',name='다음',exact=True).click();page.wait_for_timeout(300)
                check(page.locator('.db-calendar__day').first.get_attribute('aria-label')[:7]!=firstMonth,'next month advances calendar month')
                page.get_by_role('button',name='이전',exact=True).click();page.wait_for_timeout(300)
                check(page.locator('.db-calendar__day').first.get_attribute('aria-label')[:7]==firstMonth,'previous month restores calendar month')
                php=json.loads(subprocess.check_output(['php','tests/community/stage11_document.php'],cwd=root))
                parity=page.evaluate('''async()=>{const a=await import('/tbox/assets/community/doc-render.js');const e=await import('/tbox/assets/community/editor-schema.js');const b={type:'db_view',id:'11111111-1111-4111-8111-111111111111',database_id:'22222222-2222-4222-8222-222222222222',view_id:'v12345678'};const doc={schema:1,blocks:[b]};return {html:a.renderDoc(doc,{db_views:{[b.id]:{title:'검토 <script>',name:'합계'}}}).firstElementChild.outerHTML,hidden:a.renderDoc(doc,{}).firstElementChild.outerHTML,roundtrip:e.toDoc(e.fromDoc(doc,{})),doc};}''')
                (output/'renderer-parity.json').write_text(json.dumps({'js':parity,'php':php},ensure_ascii=False,indent=2))
                check(parity['html']==php['card_html'] and parity['hidden']==php['unreadable_html'],'PHP and browser db_view cards match exactly')
                check(parity['roundtrip']==parity['doc'],'db_view ProseMirror roundtrip preserves identifiers')
                card=page.locator('.db-calendar__item').first;card.focus();page.keyboard.press('ArrowRight');page.wait_for_timeout(400)
                values=call('GET','/api/pages/'+row).json()['row']['values'];check(values[day]['start']==now[:-2]+'16','keyboard date move saved through API')
                page.locator('.db-calendar__item').filter(has_text='월마감 검토 자료').first.drag_to(page.locator('.db-calendar__day[aria-label=\"'+now[:-2]+'17\"]'));page.wait_for_timeout(400);check(call('GET','/api/pages/'+row).json()['row']['values'][day]['start']==now[:-2]+'17','drag date move saved through API')
            if width==390:check(page.locator('.db-calendar').evaluate('(e)=>getComputedStyle(e).gridTemplateColumns.trim().split(/\s+/).length===1'),'mobile calendar uses single column list')
            page.screenshot(path=str(output/('calendar-'+str(width)+'-'+theme+'.png')),full_page=True)
            page.locator('[data-db-view="'+chart['id']+'"]').click();expect(page.locator('.db-chart')).to_be_visible();check(page.locator('.db-chart__bar').count()==1,'chart SVG rendered '+str(width))
            for saved,selector in [(line,'.db-chart__point'),(donut,'.db-chart__slice'),(number,'.db-chart__number')]:
                page.locator('[data-db-view="'+saved['id']+'"]').click();expect(page.locator(selector).first).to_be_visible();check(True,'chart '+saved['kind']+' visible '+str(width))
            page.locator('[data-db-view=\"'+timeline['id']+'\"]').click();expect(page.locator('.db-timeline__arrow')).to_be_visible();check(True,'visible dependency arrow '+str(width))
            if width==1280:
                import calendar
                graphic=page.locator('.db-timeline__graphic');bounds=graphic.bounding_box();span=calendar.monthrange(int(now[:4]),int(now[5:7]))[1];targetX=(160+(17.5*550/span))*bounds['width']/720
                page.locator('.db-timeline .db-calendar__item').filter(has_text='월마감 검토 자료').drag_to(graphic,target_position={'x':targetX,'y':20});page.wait_for_timeout(350)
                check(call('GET','/api/pages/'+row).json()['row']['values'][day]['start']==now[:-2]+'18','timeline drag saves target day through values API')
            else:check(page.locator('.db-timeline-canvas').evaluate('(e)=>e.scrollWidth>e.clientWidth'),'mobile timeline scrolls inside its viewport')
            page.locator('[data-db-view=\"'+attack['id']+'\"]').click();expect(page.get_by_role('cell',name='<script>',exact=True)).to_be_visible();check(page.locator('.db-chart').evaluate('(svg)=>![...svg.querySelectorAll(\"*\")].some(e=>[...e.attributes].some(a=>/onload|<script>/.test(a.value)))'),'SVG labels never become attributes '+str(width))
            page.get_by_role('button',name='뷰 설정',exact=True).click();expect(page.get_by_role('dialog')).to_be_visible();check(page.get_by_role('dialog').evaluate('(e)=>e.getBoundingClientRect().right<=innerWidth&&e.getBoundingClientRect().left>=0'),'view settings dialog fits '+str(width));page.keyboard.press('Escape')
            page.goto(site+'/community/f/'+did+'/'+frm['id'],wait_until='networkidle');expect(page.get_by_label('제목 *',exact=True)).to_be_visible();page.get_by_label('제목 *',exact=True).fill('브라우저 응답 '+str(width));expect(page.get_by_label('재료비 *',exact=True)).not_to_be_visible();page.get_by_label('추가 입력 *',exact=True).select_option('예');expect(page.get_by_label('재료비 *',exact=True)).to_be_visible();page.get_by_label('추가 입력 *',exact=True).select_option('아니오');expect(page.get_by_label('재료비 *',exact=True)).not_to_be_visible();page.get_by_label('추가 입력 *',exact=True).select_option('예');page.get_by_label('재료비 *',exact=True).fill('4500');check(True,'conditional question shows and hides '+str(width));page.screenshot(path=str(output/('form-'+str(width)+'-'+theme+'.png')),full_page=True);page.get_by_role('button',name='응답 보내기',exact=True).click();expect(page.locator('#response-status')).to_contain_text('응답을 받았습니다');check(True,'browser form submission '+str(width))
            page.goto(site+'/community/p/'+dash,wait_until='networkidle');expect(page.locator('.db-widget .db-chart').first).to_be_visible();check(page.locator('.db-widget__body').count()==12,'twelve widgets rendered '+str(width));page.get_by_label('시작일',exact=True).fill('2026-10-01');page.get_by_label('종료일',exact=True).fill('2026-10-31');page.get_by_role('button',name='전체 뷰 거르기',exact=True).click();expect(page.get_by_text('거르개 적용 안됨',exact=True).first).to_be_visible();page.screenshot(path=str(output/('dashboard-'+str(width)+'-'+theme+'.png')),full_page=True)
            if width==1280:
                page.goto(site+'/community/p/'+pid,wait_until='networkidle');page.get_by_role('button',name='폼 · 일정 · 차트 추가',exact=True).click();dialog=page.get_by_role('dialog');dialog.get_by_label('뷰 이름',exact=True).fill('브라우저에서 만든 폼');dialog.get_by_label('응답 받기',exact=True).check();dialog.get_by_role('button',name='저장',exact=True).click();expect(dialog).not_to_be_visible();check(any(v['name']=='브라우저에서 만든 폼' for v in call('GET','/api/databases/'+did+'/rows').json()['views']),'form configuration saved from actual dialog')
            check(page.evaluate('document.documentElement.scrollWidth<=innerWidth'),'no page overflow '+str(width));check(not errors,'no uncaught browser errors '+str(width));ctx.close()
        browser.close()
finally:
    result={'passed':sum(x['pass'] for x in checks),'failed':max(int(sys.exc_info()[0] is not None),sum(not x['pass'] for x in checks)),'completed':sys.exc_info()[0] is None,'checks':checks}
    (output/'browser-results.json').write_text(json.dumps(result,ensure_ascii=False,indent=2)+'\n');print(json.dumps({'passed':result['passed'],'failed':result['failed']}))
