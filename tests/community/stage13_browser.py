#!/usr/bin/env python3
"""Actual app/DB/browser against isolated synthetic AI fixture; no outside calls."""
from pathlib import Path
exec(compile(Path(__file__).with_name('stage13_http.py').read_text().split('try:\n for u in users:')[0],__file__,'exec'))
from urllib.parse import urlsplit
from playwright.sync_api import sync_playwright,expect
site=manifest['base_url'].removesuffix('/tbox');host=urlsplit(site).hostname
try:
 for u in users:call('GET','/api/me/space',user=u)
 sql('UPDATE sessions SET reauth_at=now()')
 with sync_playwright() as pw:
  browser=pw.chromium.launch()
  for width in (1280,390):
   ctx=browser.new_context(viewport={'width':width,'height':950},locale='ko-KR',user_agent='tbox-review/'+time.strftime('%Y-%m-%d'))
   ctx.add_cookies([{'name':'__Host-tbox_session','value':users['employee']['session'],'domain':host,'path':'/','secure':True,'httpOnly':True,'sameSite':'Lax'}])
   tab=ctx.new_page();errors=[];tab.on('pageerror',lambda e:errors.append(str(e)))
   tab.goto(base+'/ai',wait_until='networkidle');print('BROWSER_ERRORS='+str(errors),flush=True);expect(tab.get_by_role('heading',name='문서 AI',exact=True)).to_be_visible()
   expect(tab.get_by_role('button',name='뜻으로 찾기',exact=True)).to_be_disabled()
   check(tab.get_by_role('heading',name='AI 관리자 설정').count()==0,'employee hides admin settings '+str(width))
   check(tab.locator('body').evaluate('(e)=>e.scrollWidth<=innerWidth+1'),'AI center fits viewport '+str(width))
   tab.get_by_role('button',name='내 기록 보기',exact=True).click();expect(tab.get_by_text('아직 AI 기록이 없습니다.',exact=True)).to_be_visible()
   check(not errors,'AI center runtime clean '+str(width));tab.screenshot(path=str(out/f'ai-center-{width}.png'),full_page=True);ctx.close()
  check(captures()==[],'off page makes no inference calls')
  call('POST','/api/admin/ai/key',{'key':'K'*39},user='admin')
  call('PATCH','/api/admin/ai/settings',{'external_data_mode':'paid_private','features':{k:True for k in ['search','summary','assistant','briefing','transcribe']}},user='admin')
  pid=page('AI 브라우저 합성 문서');ctx=browser.new_context(viewport={'width':1280,'height':1000},locale='ko-KR')
  ctx.add_cookies([{'name':'__Host-tbox_session','value':users['employee']['session'],'domain':host,'path':'/','secure':True,'httpOnly':True,'sameSite':'Lax'}])
  tab=ctx.new_page();errors=[];tab.on('pageerror',lambda e:errors.append(str(e)));tab.goto(base+'/p/'+pid,wait_until='networkidle')
  def ai_dialog():
   tab.locator('#page-more > summary').click();tab.get_by_role('button',name='문서 AI · 요약과 사용 허용',exact=True).click();d=tab.get_by_role('dialog',name='문서 AI',exact=True);expect(d).to_be_visible();return d
  d=ai_dialog();expect(d.get_by_role('button',name='요약 만들기',exact=True)).to_be_disabled()
  d.get_by_role('button',name='현재 판 외부 AI 사용 허용',exact=True).click();expect(d).to_contain_text('자료 전송 조건을 확인해 주세요.')
  check(captures()==[],'no document transmission without consent')
  d.get_by_role('checkbox').check();d.get_by_role('button',name='현재 판 외부 AI 사용 허용',exact=True).click();expect(d).not_to_be_visible()
  d=ai_dialog();d.get_by_role('checkbox').check();d.get_by_role('button',name='요약 만들기',exact=True).click();expect(d).to_contain_text('아직 문서에 저장되지 않았습니다.')
  check(int(sql("SELECT doc_version FROM community.pages WHERE public_id='"+pid+"'"))==1,'summary preview does not save')
  expect(d.get_by_role('button',name='이 초안을 문서 끝에 넣기',exact=True)).to_be_visible()
  d.get_by_role('button',name='이 초안을 문서 끝에 넣기',exact=True).click();expect(d).not_to_be_visible()
  check(int(sql("SELECT doc_version FROM community.pages WHERE public_id='"+pid+"'"))==2,'explicit insert saves exactly one version')
  check(not errors,'summary browser runtime clean');ctx.close()
  ctx=browser.new_context();ctx.add_cookies([{'name':'__Host-tbox_session','value':users['admin']['session'],'domain':host,'path':'/','secure':True,'httpOnly':True,'sameSite':'Lax'}]);tab=ctx.new_page();tab.goto(base+'/ai',wait_until='networkidle');expect(tab.get_by_role('heading',name='AI 관리자 설정',exact=True)).to_be_visible()
  check(tab.get_by_label('Google AI Studio API 키',exact=True).input_value()=='','admin key never prefilled');browser.close()
finally:
 (out/'browser-results.json').write_text(json.dumps(checks,ensure_ascii=False,indent=2));print('RESULT pass='+str(sum(x['pass'] for x in checks))+' fail='+str(sum(not x['pass'] for x in checks)),flush=True)
