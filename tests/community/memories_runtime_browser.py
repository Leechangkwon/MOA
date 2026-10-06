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
 # 대화 상대 — 사람 목록 SQL 이 claude_ 아이디를 빼므로 이 합성 DB 에만 로그인 없는 구성원 하나(messenger_window_browser.py 와 같은 방식)
 sql("INSERT INTO users(username,password_hash,role,display_name,expires_at) VALUES('synthetic_runtime_peer','!synthetic-no-login','Employee','합성 대화 상대',clock_timestamp()+interval '14 days')")
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
   if width == 1280:
    tab.get_by_role('button',name='나중에 볼게요',exact=True).click()
    expect(tab.locator('.usage-guide-dialog')).to_have_count(0)
   # 보정 D-2 — 방금 떠난 /tbox/messages(붙박이)가 open:true 를 남겨 떠 있는 창이 이어 열려 있다(B-3). 휴대폰 폭은 아래 시트가 화면을 덮으니 닫고 시작한다
   if tab.locator('#messenger-window').is_visible():tab.locator('#messenger-window [data-messenger-close].btn--icon').click();expect(tab.locator('#messenger-window')).not_to_be_visible()
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
   # Q:F-11 보정 D-2 — 커뮤니티 대화 창은 떠 있는 모아 메신저(#messenger-window)로 대체됐다: 스레드의 메신저 단추가 창을 제자리에서 연다
   tab.locator('.threads-quick [data-messenger-open]').click();expect(tab.locator('#messenger-window')).to_be_visible();check(tab.locator('#community-messenger').count()==0,'old community messenger markup gone '+str(width))
   # 앞서 들른 /tbox/messages(붙박이)가 떠날 때 open:true 를 남겨 창이 모아 AI 대화로 이어 열려 있을 수 있다(B-3) — 목록으로
   if tab.locator('#messenger-back').is_visible():tab.locator('#messenger-back').click()
   tab.locator('#message-search').fill('합성 대화 상대');tab.locator('#message-people button').filter(has_text='합성 대화 상대').first.click();expect(tab.locator('#message-peer-name')).to_contain_text('합성 대화 상대')
   tab.locator('#message-text').fill('실제 창 전송 '+str(width));tab.locator('#message-send').click();expect(tab.locator('#message-list')).to_contain_text('실제 창 전송 '+str(width))
   check(tab.url==base+'/memories?view=mine' and sql("SELECT count(*) FROM member_messages WHERE body='실제 창 전송 "+str(width)+"'")=='1','floating window stays on the saved personal feed, one row sent '+str(width))
   tab.locator('#messenger-back').click();tab.locator('#message-ai').click();expect(tab.locator('#message-peer-name')).to_have_text('모아 AI')
   expect(tab.locator('#messenger-window-status')).to_contain_text('Gemini 키');expect(tab.locator('#message-send')).to_be_disabled()
   tab.screenshot(path=str(out/f'messenger-real-{width}.png'),full_page=True);tab.locator('#messenger-window [data-messenger-close].btn--icon').click();expect(tab.locator('#messenger-window')).not_to_be_visible()
   tab.screenshot(path=str(out/f'threads-real-{width}.png'),full_page=True)
   tab.goto(base+'/workspace',wait_until='networkidle');tab.locator('[data-messenger-open]:visible').first.click();expect(tab.locator('#messenger-window')).to_be_visible()
   tab.locator('#messenger-window [data-messenger-close].btn--icon').click();expect(tab.locator('#messenger-window')).not_to_be_visible()
   check(not errors and not bad,'real common shell no JS/500 errors '+str(width));ctx.close()
  browser.close()
finally:
 (out/'browser-results.json').write_text(json.dumps(checks,ensure_ascii=False,indent=2));print('RESULT pass='+str(sum(x['pass'] for x in checks))+' fail='+str(sum(not x['pass'] for x in checks)),flush=True)
