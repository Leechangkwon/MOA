#!/usr/bin/env python3
"""Real Chromium UI, responsive dialogs and static public form; isolated fixture only."""
from pathlib import Path
exec(compile(Path(__file__).with_name('stage12_http.py').read_text().split('try:\n for u in users:')[0],__file__,'exec'))
from urllib.parse import urlsplit
from playwright.sync_api import sync_playwright,expect
site=manifest['base_url'].removesuffix('/tbox');host=urlsplit(site).hostname
try:
 for u in users:call('GET','/api/me/space',user=u)
 sql('UPDATE sessions SET reauth_at=now()')
 p=page('공유·연동 화면 검증');ref=page('참조 표시 검증');source=str(uuid.uuid4())
 docpage(p,{'schema':1,'blocks':[block('synced_source',source_id=source,children=[block('paragraph',text=[{'t':'동기화된 원본 내용','m':{}}])])]})
 docpage(ref,{'schema':1,'blocks':[block('synced_ref',source_id=source)]})
 for x in [p,ref]:call('PUT',f'/api/pages/{x}/shares/claude_employee02',{'level':'view'})
 space=int(sql("SELECT space_id FROM community.pages WHERE public_id='"+p+"'"));call('PATCH',f'/api/spaces/{space}/web-publish',{'allowed':True},'admin')
 pub=call('POST',f'/api/pages/{p}/web-publish',{'confirm_public':True},expected=201).json()
 with sync_playwright() as pw:
  browser=pw.chromium.launch()
  for width,theme in [(1280,'light'),(390,'dark')]:
   ctx=browser.new_context(viewport={'width':width,'height':900},locale='ko-KR',color_scheme=theme,user_agent='tbox-review/'+time.strftime('%Y-%m-%d'))
   ctx.add_cookies([{'name':'__Host-tbox_session','value':users['employee']['session'],'domain':host,'path':'/','secure':True,'httpOnly':True,'sameSite':'Lax'}, {'name':'tbox_theme','value':theme,'domain':host,'path':'/','sameSite':'Lax'}])
   tab=ctx.new_page();errors=[];tab.on('pageerror',lambda error:errors.append(str(error)))
   tab.goto(base+'/integrations',wait_until='networkidle');expect(tab.get_by_role('heading',name='모음',exact=True)).to_be_visible()
   check(tab.get_by_text('등록된 연결이 없습니다.',exact=False).count()==1,'unconfigured messenger guidance '+str(width))
   tab.get_by_role('button',name='모음 만들기',exact=True).click();dialog=tab.get_by_role('dialog',name='모음 만들기');expect(dialog).to_be_visible()
   dialog.get_by_label('이름',exact=True).fill('화면 모음 '+str(width));dialog.get_by_role('button',name='저장',exact=True).click();tab.wait_for_load_state('networkidle')
   expect(tab.locator('#integrations-settings')).to_contain_text('화면 모음 '+str(width))
   check(tab.get_by_text('화면 모음 '+str(width),exact=True).count()>0,'collection save through real dialog '+str(width))
   tab.screenshot(path=str(output/f'collections-{width}.png'),full_page=True)
   tab.goto(base+'/p/'+p,wait_until='networkidle');expect(tab.get_by_text('🌐 웹에 공개 중',exact=False)).to_be_visible()
   tab.locator('#page-edit').click();expect(tab.locator('.ProseMirror .doc-synced')).to_be_visible()
   check(tab.locator('.ProseMirror .doc-synced').count()==1,'source children editable in original page '+str(width))
   tab.locator('#page-more > summary').click();tab.get_by_role('button',name='동기화 블록',exact=True).click();dialog=tab.get_by_role('dialog',name='동기화 블록')
   expect(dialog).to_be_visible();check(dialog.evaluate('(e)=>e.getBoundingClientRect().left>=0&&e.getBoundingClientRect().right<=innerWidth'),'dialog fits viewport '+str(width))
   expect(dialog.get_by_role('link',name='사용 중: 참조 표시 검증')).to_be_visible();tab.screenshot(path=str(output/f'synced-{width}.png'),full_page=True)
   dialog.get_by_role('button',name='닫기',exact=True).click();check(len(errors)==0,'no JS errors '+str(width));ctx.close()
  reader=browser.new_context(user_agent='tbox-review/'+time.strftime('%Y-%m-%d'))
  reader.add_cookies([{'name':'__Host-tbox_session','value':users['other']['session'],'domain':host,'path':'/','secure':True}]);t=reader.new_page();t.goto(base+'/p/'+ref,wait_until='networkidle')
  expect(t.locator('.doc-synced').get_by_text('동기화된 원본 내용',exact=True)).to_be_visible();expect(t.locator('.doc-synced').get_by_role('link',name='원본에서 고치기')).to_be_visible()
  check(True,'reference content and source link render in browser');reader.close()
  anon=browser.new_context(viewport={'width':390,'height':844});t=anon.new_page();requests_seen=[];t.on('request',lambda req:requests_seen.append(req.resource_type));resp=t.goto(site+pub['url'],wait_until='networkidle')
  check(resp.status==200 and t.locator('script,iframe,[style]').count()==0 and not anon.cookies(),'static public page has no script, iframe, inline style or cookies')
  check('script' not in requests_seen,'public browser downloads zero scripts');t.screenshot(path=str(output/'public-mobile.png'),full_page=True)
  check(t.evaluate('document.documentElement.scrollWidth<=innerWidth'),'public page fits mobile');anon.close()
  admin=browser.new_context(viewport={'width':1280,'height':900},user_agent='tbox-review/'+time.strftime('%Y-%m-%d'))
  admin.add_cookies([{'name':'__Host-tbox_session','value':users['admin']['session'],'domain':host,'path':'/','secure':True}]);t=admin.new_page();t.goto(base+'/integrations',wait_until='networkidle');t.get_by_role('button',name='설정 열기',exact=True).click();t.get_by_role('button',name='메신저 등록',exact=True).click();d=t.get_by_role('dialog',name='메신저 연결')
  d.get_by_label('이름',exact=True).fill('연결 대상 대기');d.get_by_label('공개 HTTPS 호스트',exact=True).fill('example.com');check(not d.get_by_label('연결 켜기',exact=True).is_checked(),'admin connection defaults off')
  d.get_by_role('button',name='저장',exact=True).click();expect(d.get_by_role('alert')).to_contain_text('/etc/tbox-v2/hooks/');check(True,'admin UI registers metadata and gives fixed file instructions');t.screenshot(path=str(output/'admin-config.png'),full_page=True)
  check(d.locator('input[type=password]').count()==0,'UI never asks for a secret URL/key');admin.close();browser.close()
 print('BROWSER_ACCEPTANCE_COUNT='+str(len(checks)))
finally:
 (output/'browser.json').write_text(json.dumps(checks,ensure_ascii=False,indent=2))
