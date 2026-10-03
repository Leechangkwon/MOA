#!/usr/bin/env python3
"""Real browser proposals against owned empty stage10 harness only."""
from pathlib import Path
exec(Path(__file__).with_name('stage10_http.py').read_text().split('try:\n for user')[0])
from playwright.sync_api import sync_playwright
from urllib.parse import urlsplit
site=base.removesuffix('/community');host=urlsplit(site).hostname
errors=[]
try:
 for user in users:call('GET','/api/me/space',user=user)
 with sync_playwright() as pw:
  browser=pw.chromium.launch()
  for width,theme in [(1280,'light'),(390,'light'),(390,'dark')]:
   pid,blocks=shared('제안 화면 검증 '+str(width)+' '+theme)
   def context(user):
    ctx=browser.new_context(viewport={'width':width,'height':900},locale='ko-KR',color_scheme=theme,user_agent='tbox-review/'+time.strftime('%Y-%m-%d'))
    ctx.add_cookies([{'name':'__Host-tbox_session','value':users[user]['session'],'domain':host,'path':'/','secure':True,'httpOnly':True,'sameSite':'Lax'},{'name':'tbox_theme','value':theme,'domain':host,'path':'/','sameSite':'Lax'}]);return ctx
   ctx=context('other');pg=ctx.new_page();pg.on('pageerror',lambda e:errors.append(str(e)));writes=[]
   pg.on('request',lambda req:writes.append((req.method,urlsplit(req.url).path)) if req.method in ('POST','PATCH','PUT','DELETE') else None)
   pg.goto(base+'/p/'+pid,wait_until='networkidle');pg.locator('#page-suggest').click()
   check(not pg.locator('#page-title-field').is_visible(),'proposal hides title '+str(width)+theme)
   check(pg.locator('#page-done').inner_text()=='제안 보내기','proposal send label '+str(width)+theme)
   pg.locator('#page-add-block').click()
   check(pg.locator('[data-block-type="image"]').is_disabled() and pg.locator('[data-block-type="file"]').is_disabled(),'blocked picker types '+str(width)+theme)
   pg.keyboard.press('Escape')
   editor=pg.locator('#page-editor .ProseMirror');editor.click();pg.keyboard.press('Control+Home');pg.keyboard.press('Shift+End');pg.keyboard.insert_text('제안 <script>window.bad=1</script>')
   pg.wait_for_timeout(1900)
   check(not any(m=='PATCH' or path.endswith('/heartbeat') for m,path in writes),'proposal no normal autosave or heartbeat '+str(width)+theme)
   check(getpage(pid)['doc_version']==1,'proposal original version unchanged '+str(width)+theme)
   check(pg.locator('#page-editor .badge').count()>0,'changed block proposal marker '+str(width)+theme)
   pg.screenshot(path=str(out/('proposal-'+str(width)+'-'+theme+'.png')),full_page=True)
   pg.locator('#page-done').click();pg.locator('#suggestion-note').fill('본문을 검토해 주세요');pg.locator('#suggestion-send').click();pg.locator('#suggestion-send-dialog').wait_for(state='detached')
   pg.goto(base+'/suggestions',wait_until='networkidle');check('검토 기다림' in pg.locator('#my-suggestions').inner_text(),'my proposals pending status '+str(width)+theme)
   ctx.close();ctx=context('employee');pg=ctx.new_page();pg.on('pageerror',lambda e:errors.append(str(e)))
   pg.goto(base+'/p/'+pid,wait_until='networkidle');pg.locator('#page-suggestions').click();pg.locator('#suggestion-list .modal__body button').first.click();pg.locator('#suggestion-accept').wait_for(state='visible')
   dialog=pg.locator('#suggestion-comparison');check(dialog.evaluate('(e)=>e.getBoundingClientRect().right<=innerWidth && e.getBoundingClientRect().left>=0'),'comparison fits '+str(width)+theme)
   check(dialog.locator('script').count()==0 and '<script>' in dialog.inner_text(),'literal injection comparison safe '+str(width)+theme)
   pg.screenshot(path=str(out/('comparison-'+str(width)+'-'+theme+'.png')),full_page=True)
   pg.locator('#suggestion-accept').click();dialog.wait_for(state='detached');pg.wait_for_timeout(500);print('ACCEPTED_TEXT='+pg.locator('#page-doc').inner_text(),flush=True);check('<script>window.bad=1</script>' in pg.locator('#page-doc').inner_text() and pg.evaluate('window.bad===undefined'),'accepted read literal injection safe')
   check(getpage(pid)['doc_version']==2,'browser accepted actual page '+str(width)+theme)
   # Reject a new proposal, verify reason in sender list; cancel writes no suggestion.
   current=getpage(pid);sid=propose(pid,current['doc']['blocks'][0],new='두 번째 제안',version=2)
   pg.reload(wait_until='networkidle');pg.locator('#page-suggestions').click();pg.locator('#suggestion-list .modal__body button').first.click();pg.locator('#suggestion-decision-note').fill('근거 보완');pg.locator('#suggestion-reject').click();pg.locator('#suggestion-comparison').wait_for(state='detached')
   ctx.close();ctx=context('other');pg=ctx.new_page();pg.on('pageerror',lambda e:errors.append(str(e)))
   pg.goto(base+'/suggestions',wait_until='networkidle');check('거절 사유: 근거 보완' in pg.locator('#my-suggestions').inner_text(),'rejected reason in my proposals '+str(width)+theme)
   pg.screenshot(path=str(out/('my-suggestions-'+str(width)+'-'+theme+'.png')),full_page=True)
   pg.goto(base+'/p/'+pid,wait_until='networkidle');pg.locator('#page-suggest').click();pg.locator('#page-suggestion-cancel').click();check(getpage(pid)['doc_version']==2,'cancel preserves original '+str(width)+theme)
   ctx.close()
  check(not errors,'browser console/page errors zero')
  browser.close()
except Exception:
 import traceback;traceback.print_exc();checks.append({'pass':False,'name':'uncaught browser exception'})
 try:pg.screenshot(path=str(out/'failure.png'),full_page=True);print('PAGE_BODY='+pg.locator('body').inner_text()[:2400],flush=True)
 except Exception:pass
finally:
 out.joinpath('browser10-results.json').write_text(json.dumps({'checks':checks,'errors':errors},ensure_ascii=False,indent=2));n=sum(x['pass'] for x in checks);print(f'RESULT pass={n} fail={len(checks)-n} skip=0',flush=True);raise SystemExit(0 if n==len(checks) else 1)
