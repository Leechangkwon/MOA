#!/usr/bin/env python3
"""Real PHP rendered shared shell, real HTTP and database, synthetic users/media only."""
from pathlib import Path
exec(compile(Path(__file__).with_name('memories_http.py').read_text().split('try:\n for u in users:')[0],__file__,'exec'))
from playwright.sync_api import sync_playwright,expect
from urllib.parse import urlsplit
host=urlsplit(site).hostname
try:
 for u in users:
  users[u]['username']=sql('SELECT username FROM users WHERE id='+str(users[u]['id']));call('GET','/api/me/space',user=u)
 with sync_playwright() as pw:
  browser=pw.chromium.launch(executable_path=os.environ.get('CHROMIUM_EXECUTABLE'))
  for width in (1280,390):
   ctx=browser.new_context(viewport={'width':width,'height':950},locale='ko-KR',user_agent='tbox-review/'+time.strftime('%Y-%m-%d'))
   ctx.add_cookies([{'name':'__Host-tbox_session','value':users['employee']['session'],'domain':host,'path':'/','secure':True,'httpOnly':True,'sameSite':'Lax'}])
   tab=ctx.new_page();errors=[];bad=[];tab.on('pageerror',lambda e:errors.append(str(e)));tab.on('response',lambda r:bad.append(r.url) if r.status>=500 else None)
   resp=tab.goto(site+'/tbox/messages',wait_until='networkidle')
   check(resp.status==200 and 'http://127.0.0.1:43821' in resp.headers.get('content-security-policy',''),'authenticated messenger PHP permits paired loopback '+str(width))
   resp=tab.goto(site+'/',wait_until='networkidle')
   check('http://127.0.0.1:43821' not in resp.headers.get('content-security-policy',''),'other pages retain strict connect policy '+str(width))
   tab.goto(base+'/memories',wait_until='networkidle');expect(tab.locator('.threads-write')).to_be_visible()
   check(tab.locator('body').evaluate('(e)=>e.scrollWidth<=innerWidth+1'),'thread viewport '+str(width))
   tab.locator('.threads-write').click();expect(tab.locator('#compose-dialog')).to_be_visible();tab.locator('#caption').fill('실제 PHP 화면 사진 저장 '+str(width))
   tab.locator('#media-input').set_input_files({'name':'합성.png','mimeType':'image/png','buffer':png});expect(tab.locator('#visibility')).to_have_value('private')
   tab.locator('#publish').click();expect(tab.locator('#compose-dialog')).not_to_be_visible(timeout=15000)
   expect(tab.locator('#feed')).to_contain_text('실제 PHP 화면 사진 저장 '+str(width));tab.locator('#feed img').first.scroll_into_view_if_needed();tab.wait_for_function("document.querySelector('#feed img')?.naturalWidth > 0");check(tab.locator('#feed img').first.evaluate('(e)=>e.complete && e.naturalWidth>0'),'saved media loads from authorized route '+str(width))
   tab.locator('.threads-write').click();tab.locator('#caption').fill('형식 정보 없는 사진 '+str(width))
   tab.evaluate('''bytes => {
     const input=document.querySelector('#media-input'), transfer=new DataTransfer();
     transfer.items.add(new File([new Uint8Array(bytes)], '합성.PNG', {type:''}));
     input.files=transfer.files;input.dispatchEvent(new Event('change',{bubbles:true}));
   }''',list(png))
   tab.wait_for_function("document.querySelector('#media-preview img')?.naturalWidth > 0")
   tab.locator('#publish').click();expect(tab.locator('#compose-dialog')).not_to_be_visible(timeout=15000)
   saved=tab.locator('.post').filter(has_text='형식 정보 없는 사진 '+str(width));expect(saved).to_have_count(1)
   saved.locator('img').scroll_into_view_if_needed();expect(saved.locator('img')).to_have_js_property('naturalWidth',1)
   check(True,'empty MIME photo previews, saves and loads through real PHP '+str(width))
   tab.locator('.threads-quick [data-messenger-open]').click();expect(tab.locator('#community-messenger')).to_be_visible();tab.locator('#messenger-search').fill(users['other']['username'])
   tab.locator('#messenger-members button').first.click();tab.locator('#messenger-body').fill('실제 모달 전송 '+str(width));tab.locator('#messenger-send').click();expect(tab.locator('#messenger-messages')).to_contain_text('실제 모달 전송 '+str(width))
   check(tab.url==base+'/memories?view=mine','modal stays on the saved personal feed '+str(width))
   tab.locator('#messenger-gpt-tab').click();expect(tab.locator('#messenger-gpt-unavailable')).to_be_visible();expect(tab.locator('#messenger-send')).to_be_disabled()
   tab.screenshot(path=str(out/f'messenger-real-{width}.png'),full_page=True);tab.locator('#messenger-close').click()
   tab.screenshot(path=str(out/f'threads-real-{width}.png'),full_page=True)
   tab.goto(base+'/workspace',wait_until='networkidle');tab.locator('[data-messenger-open]:visible').first.click();expect(tab.locator('#community-messenger')).to_be_visible();tab.locator('#messenger-close').click()
   check(not errors and not bad,'real common shell no JS/500 errors '+str(width));ctx.close()
  browser.close()
finally:
 (out/'browser-results.json').write_text(json.dumps(checks,ensure_ascii=False,indent=2));print('RESULT pass='+str(sum(x['pass'] for x in checks))+' fail='+str(sum(not x['pass'] for x in checks)),flush=True)
