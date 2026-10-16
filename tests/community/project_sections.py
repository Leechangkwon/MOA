#!/usr/bin/env python3
"""Actual PHP/Postgres + Chromium section editing; isolated memories_harness only."""
from pathlib import Path
exec(compile(Path(__file__).with_name('memories_http.py').read_text().split('try:\n for u in users:')[0], __file__, 'exec'))
from playwright.sync_api import sync_playwright
from urllib.parse import urlsplit

assert str(root).startswith('/tmp/qaredi-stage13-')
def rows():return call('GET','/api/databases/'+dbid+'/rows')
def change(action,value,name=None,version=None,**kw):
    arg={'action':action,'value':value}
    if name is not None:arg['name']=name
    data={'section':arg,'version':version or rows()['database']['version']}
    return call('PATCH','/api/databases/'+dbid+'/schema',data,**kw)
def section(data):return next(x for x in data['database']['schema'] if x['key']=='section')
try:
    for u in users:call('GET','/api/me/space',user=u)
    doc={'schema':1,'blocks':[{'id':str(uuid.uuid4()),'type':'paragraph','text':[{'t':'서비스 기획 기준','m':{}}]}]}
    project=call('POST','/api/pages',{'title':'섹션 편집 수락','database':True,'preset':'project','doc':doc},expected=201)['page']
    pid=project['id'];dbid=project['database']['id'];initial=rows();old_version=initial['database']['version']
    card=call('POST','/api/pages',{'title':'보존할 작업','parent_id':pid,'properties':{'section':'00. 준비'}},expected=201)['page']
    original=call('GET','/api/pages/'+card['id'])['page']
    call('PUT','/api/pages/'+pid+'/shares/claude_manager01',{'level':'edit'})
    change('rename','00. 준비','완료된 기능',user='manager',expected=403)
    change('rename','00. 준비','완료된 기능',user='guest',expected=403)
    change('rename','00. 준비','완료된 기능',csrf=False,expected=403)
    change('rename','00. 준비','',expected=400)
    change('rename','없는 값','이름',expected=400)
    change('rename','00. 준비','01. 진행',expected=400)
    renamed=change('rename','00. 준비','완료된 기능')
    check(section(renamed)['option_labels']['00. 준비']=='완료된 기능','section display name saved')
    data=rows();check(data['rows'][0]['values']['section']=='00. 준비','rename preserves stored task membership')
    change('rename','01. 진행','동시 변경',version=old_version,expected=409)
    call('PATCH','/api/databases/'+dbid+'/schema',{'options':{'key':'section','options':['00. 준비','01. 진행','02. 마무리','완료된 기능']}},expected=400)
    with sync_playwright() as p:
        browser=p.chromium.launch()
        for width in [390,1280]:
            context=browser.new_context(viewport={'width':width,'height':900},user_agent='tbox-review/'+time.strftime('%Y-%m-%d'),ignore_https_errors=True)
            context.add_cookies([{'name':'__Host-tbox_session','value':users['employee']['session'],'domain':urlsplit(site).hostname,'path':'/','secure':True,'httpOnly':True}])
            page=context.new_page();errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
            page.goto(site+'/community/p/'+pid,wait_until='networkidle')
            later=page.get_by_role('button',name='나중에 볼게요',exact=True)
            if later.is_visible():later.click()
            group=page.locator('[data-section="00. 준비"]');group.wait_for()
            check(('완료된 기능' if width==390 else '모바일 진행') in group.inner_text() and '보존할 작업' in group.inner_text(),'list renamed section retains task '+str(width))
            group.locator('[data-section-manage]').click();dialog=page.locator('#project-section-dialog')
            check(dialog.is_visible(),'section management dialog opens '+str(width))
            dialog.locator('input').fill('모바일 진행' if width==390 else 'PC 진행')
            page.get_by_role('button',name='이름 저장',exact=True).click()
            page.wait_for_timeout(600)
            check(('모바일 진행' if width==390 else 'PC 진행') in page.locator('[data-section="00. 준비"]').inner_text(),'UI name save '+str(width))
            page.locator('[data-section="00. 준비"] [data-section-manage]').click()
            page.get_by_role('button',name='섹션 삭제',exact=True).click()
            check('작업은 삭제되지 않고' in dialog.inner_text(),'delete confirms task preservation '+str(width))
            page.keyboard.press('Escape');check(not dialog.count(),'Escape cancels without deleting '+str(width))
            check(page.evaluate('document.documentElement.scrollWidth <= innerWidth+1'),'no horizontal overflow '+str(width))
            check(not errors,'no browser JS errors '+str(width))
            page.screenshot(path=str(out/('sections-'+str(width)+'.png')),full_page=True)
            if width==1280:
                page.get_by_role('button',name='보드',exact=True).click();page.wait_for_timeout(500)
                check('PC 진행' in page.locator('.db-board__head').all_text_contents()[1],'board shows section label')
                page.get_by_role('button',name='목록',exact=True).click();page.wait_for_timeout(300)
                page.locator('[data-section="00. 준비"] [data-section-manage]').click()
                page.get_by_role('button',name='섹션 삭제',exact=True).click()
                page.get_by_role('button',name='섹션만 삭제',exact=True).click();page.wait_for_timeout(500)
                check(page.locator('[data-section="00. 준비"]').count()==0 and '보존할 작업' in page.locator('[data-section=""]').inner_text(),'UI deletion keeps task in no-section group')
            page.locator('#project-document').click();page.wait_for_timeout(300)
            check(page.get_by_text('서비스 기획 기준',exact=True).is_visible(),'project document opens from header '+str(width))
            page.locator('#page-as-project').locator('xpath=ancestor::details').locator('summary').click()
            page.locator('#page-as-project').click();page.wait_for_timeout(300)
            check(page.locator('#project-head').is_visible(),'return to project '+str(width))
            context.close()
        browser.close()
    data=rows();check('00. 준비' not in section(data)['options'],'section option removed')
    after=call('GET','/api/pages/'+card['id'])['page']
    check(after['doc_version']==original['doc_version'] and after['doc']==original['doc'],'delete preserves task document and revision')
    check(data['rows'][0]['id']==card['id'] and data['rows'][0]['values']['section']=='00. 준비','deleted section keeps original task and archived membership')
    change('remove','01. 진행');change('remove','02. 마무리')
    check(section(rows())['options']==[],'last default section may be removed')
    call('PATCH','/api/databases/'+dbid+'/schema',{'options':{'key':'section','options':['새 섹션']}})
    check(section(rows())['options']==['새 섹션'],'add after all sections removed')
    check(sql("SELECT count(*) FROM community.page_property_events WHERE page_id=(SELECT id FROM community.pages WHERE public_id='"+card['id']+"')")=='0','metadata edits do not rewrite task events')
except Exception as error:
    checks.append({'pass':False,'name':'suite interrupted: '+type(error).__name__})
    raise
finally:
    (out/'project-sections-results.json').write_text(json.dumps(checks,ensure_ascii=False,indent=2))
    print('PROJECT_SECTIONS pass='+str(sum(x['pass'] for x in checks))+' fail='+str(sum(not x['pass'] for x in checks)),flush=True)
