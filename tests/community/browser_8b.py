#!/usr/bin/env python3
"""Actual isolated document menu, downloaded bytes, print view, keyboard and widths."""
import json
import os
from pathlib import Path
import time
from urllib.parse import urlsplit
from playwright.sync_api import sync_playwright

manifest = json.loads(Path(os.environ['COMMUNITY_EXPORT_MANIFEST']).read_text())
assert manifest['database'].startswith('tbox_export_test_')
data = json.loads(Path(manifest['credentials_file']).read_text())
output = Path(os.environ['COMMUNITY_EXPORT_OUTPUT'])
ids = json.loads((output/'fixture-ids.json').read_text())
site = manifest['base_url'].removesuffix('/tbox')
host = urlsplit(site).hostname
checks = []


def check(ok, name):
    checks.append({'pass':bool(ok),'name':name})
    print(('PASS ' if ok else 'FAIL ') + name,flush=True)
    if not ok: raise AssertionError(name)


try:
    with sync_playwright() as p:
        browser = p.chromium.launch()
        for width, theme in [(1280,'light'),(390,'light'),(390,'dark'),(360,'light'),(412,'dark'),(820,'light')]:
            ctx = browser.new_context(viewport={'width':width,'height':900},locale='ko-KR',color_scheme=theme,
                       user_agent='tbox-review/'+time.strftime('%Y-%m-%d'),accept_downloads=True)
            ctx.add_cookies([{'name':'__Host-tbox_session','value':data['users']['employee']['session'],
                              'domain':host,'path':'/','secure':True,'httpOnly':True,'sameSite':'Lax'},
                             {'name':'tbox_theme','value':theme,'domain':host,'path':'/','sameSite':'Lax'}])
            page=ctx.new_page(); errors=[]; page.on('pageerror',lambda e:errors.append(str(e)))
            pending=set(); responses=[]
            page.on('request',lambda r:pending.add(urlsplit(r.url).path))
            page.on('requestfinished',lambda r:pending.discard(urlsplit(r.url).path))
            page.on('requestfailed',lambda r:pending.discard(urlsplit(r.url).path))
            page.on('response',lambda r:responses.append([r.status,urlsplit(r.url).path]))
            try:
                page.goto(site+'/community/p/'+ids['own'],wait_until='domcontentloaded')
            except Exception:
                print(json.dumps({'pending_paths':sorted(pending),'responses':responses[-25:],'page_errors':errors},ensure_ascii=False),flush=True)
                try: print('VISIBLE_BODY='+page.locator('body').inner_text(timeout=3000)[:1800],flush=True)
                except Exception: pass
                raise
            page.locator('#page-more > summary').click()
            page.locator('#page-export').click()
            dialog=page.locator('#page-export-dialog'); dialog.wait_for(state='visible')
            check(page.locator('#page-export-format').evaluate('(e) => document.activeElement === e'),'format focused '+str(width)+theme)
            check(dialog.evaluate('(e) => e.getBoundingClientRect().right <= innerWidth && e.getBoundingClientRect().left >= 0'),'dialog fits '+str(width)+theme)
            page.screenshot(path=str(output/('export-'+str(width)+'-'+theme+'.png')),full_page=True)
            if width == 1280:
                with page.expect_download() as download:
                    dialog.get_by_role('button',name='다운로드',exact=True).click()
                got=download.value; target=output/'browser-downloaded.md'; got.save_as(target)
                check('검증용 본문' in target.read_text() and got.suggested_filename.endswith('.md'),'browser download contains server document')
                page.locator('#page-export-format').select_option('print')
                with page.expect_popup() as popup:
                    dialog.get_by_role('link',name='인쇄 화면 열기').click()
                printed=popup.value; printed.wait_for_load_state()
                check(printed.locator('#print-page').count()==1 and printed.locator('iframe').count()==0,'print popup uses dedicated SSR')
                printed.emulate_media(media='print')
                check(not printed.locator('#print-page').is_visible(),'print button absent on paper')
                printed.pdf(path=str(output/'print-document.pdf'),format='A4')
                printed.close()
            page.keyboard.press('Escape')
            check(not dialog.is_visible(),'Escape closes export modal '+str(width)+theme)
            check(not errors,'no JS page error '+str(width)+theme)
            ctx.close()
        # A real admin session can read this private page, but receives no export action.
        ctx=browser.new_context(user_agent='tbox-review/'+time.strftime('%Y-%m-%d'))
        ctx.add_cookies([{'name':'__Host-tbox_session','value':data['users']['admin']['session'],
                         'domain':host,'path':'/','secure':True,'httpOnly':True,'sameSite':'Lax'}])
        page=ctx.new_page(); page.goto(site+'/community/p/'+ids['own'],wait_until='domcontentloaded')
        page.locator('#page-more > summary').click()
        check(not page.locator('#page-export').is_visible(),'privileged-only admin has no export action')
        ctx.close(); browser.close()
finally:
    (output/'browser-results.json').write_text(json.dumps(checks,ensure_ascii=False,indent=2))
