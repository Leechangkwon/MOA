#!/usr/bin/env python3
"""FB UI acceptance on real HTML/PHP/DB; only synthetic accounts and records."""
from pathlib import Path
exec(compile(Path(__file__).with_name('memories_http.py').read_text().split('try:\n for u in users:')[0],__file__,'exec'))
from playwright.sync_api import sync_playwright,expect
from urllib.parse import urlsplit
try:
 for u in users: call('GET','/api/me/space',user=u)
 sql("INSERT INTO community.usage_guides(user_id,version,status,context) SELECT id,1,'dismissed','overview' FROM users ON CONFLICT DO NOTHING")
 peer=int(sql("INSERT INTO users(username,password_hash,role,display_name,expires_at) VALUES('synthetic_feedback_peer','!no-login','Employee','피드백 동료',clock_timestamp()+interval '14 days') RETURNING id" ).splitlines()[0])
 pid=save(dict(payload(),visibility='community'))
 sql("INSERT INTO community.memory_comments(id,post_id,user_id,body) SELECT md5('feedback-ui-'||g)::uuid,'"+pid+"',"+str(users['other']['id'])+",repeat('긴 댓글 내용 ',20)||g FROM generate_series(1,105) g")
 with sync_playwright() as pw:
  browser=pw.chromium.launch(executable_path=os.environ.get('CHROMIUM_EXECUTABLE'))
  for width in (390,1440):
   for user in ('guest','employee'):
    ctx=browser.new_context(viewport={'width':width,'height':844},locale='ko-KR',user_agent='tbox-review/'+time.strftime('%Y-%m-%d'))
    ctx.add_cookies([{'name':'__Host-tbox_session','value':users[user]['session'],'domain':urlsplit(site).hostname,'path':'/','secure':True,'httpOnly':True,'sameSite':'Lax'}])
    page=ctx.new_page();errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
    if user=='guest':
     for location in ('/','/tbox/','/tbox/welcome'):
      page.goto(site+location,wait_until='networkidle');start=page.url
      link=page.locator('a[aria-controls="messenger-unavailable"]').first
      if not link.is_visible() and page.locator('#appbar-apps').is_visible(): page.locator('#appbar-apps').click()
      link.click()
      expect(page.locator('#messenger-unavailable')).to_be_visible();check(page.url==start,'denial stays on page '+location+' '+str(width))
      page.keyboard.press('Escape');expect(link).to_be_focused()
     page.goto(base+'/memories',wait_until='networkidle');expect(page.locator('#reader-notice')).to_be_visible()
     page.locator('#find-people').click();page.locator('#social-search').fill('피드백 동료')
     page.locator('#social-list button').first.click();expect(page.locator('#social-dialog #memory-toast')).to_be_visible()
     check(page.locator('#memory-toast').evaluate('(n)=>n.closest("dialog").matches(":modal")'),'toast belongs to modal top layer '+str(width))
     page.screenshot(path=str(out/f'guest-notice-{width}.png'));page.keyboard.press('Escape')
    page.goto(base+'/memories/p/'+pid,wait_until='networkidle')
    # S1b — 댓글은 창 없이 카드 아래 펼침: 최근 3 → 「이전 댓글 N개 더 보기」(불러 둔 30 → 커서로 60) · 창 안 고정 입력 줄 단언은 창이 없어져 뺐다
    page.locator('[data-comment-post]').first.click();expect(page.locator('.post-comments .comment')).to_have_count(3)
    if user=='employee':
     page.locator('.post-comments > .text-button').click();expect(page.locator('.post-comments .comment')).to_have_count(30)
     page.locator('.post-comments > .text-button').click();expect(page.locator('.post-comments .comment')).to_have_count(60)
     page.locator('.post-comments textarea').fill('새 댓글 입력 검증');page.locator('.post-comments [type="submit"]').click()
     expect(page.locator('.post-comments textarea')).to_have_value('')
     if width>700:expect(page.locator('.post-comments textarea')).to_be_focused()
     expect(page.locator('.post-comments')).to_contain_text('새 댓글 입력 검증')
    else: expect(page.locator('.post-comments .comments-readonly')).to_be_visible()
    page.screenshot(path=str(out/f'comments-{user}-{width}.png'));page.keyboard.press('Escape')
    if user=='employee':
     page.locator('.threads-quick [data-messenger-open]').click()
     page.locator('#message-search').fill('피드백 동료');page.locator('#message-people button').first.click()
     expect(page.locator('#message-text')).to_be_enabled();expect(page.locator('#message-send')).to_be_enabled()
     for action in ('click','enter'):
      expect(page.locator('#message-send')).to_be_enabled()
      page.locator('#message-text').fill('초점 시험 '+action)
      if action=='click':page.locator('#message-send').click()
      else:page.locator('#message-text').press('Enter')
      expect(page.locator('#message-text')).to_have_value('');expect(page.locator('#message-send')).to_be_enabled();expect(page.locator('#message-text')).to_be_focused()
      check(True,'send restores composer '+str(width)+' '+action)
     # Hold the real POST while the user intentionally moves to another control.
     pending=[]
     def hold(route):pending.append(route)
     page.route('**/tbox/api/messages/'+str(peer),hold)
     page.locator('#message-text').fill('초점 이동 보존');page.locator('#message-send').click()
     page.wait_for_timeout(100);page.locator('#messenger-back').focus()
     for route in pending:route.continue_()
     page.unroute('**/tbox/api/messages/'+str(peer),hold)
     expect(page.locator('#message-text')).to_have_value('');expect(page.locator('#messenger-back')).to_be_focused()
     check(True,'send does not steal deliberately moved focus '+str(width))
     size=page.locator('#message-send').bounding_box();check(size['height']>=44,'send target at least 44px '+str(width))
     page.screenshot(path=str(out/f'messenger-{width}.png'))
    check(not errors,'no uncaught JS errors '+user+' '+str(width));ctx.close()
  browser.close()
finally:
 (out/'results.json').write_text(json.dumps(checks,ensure_ascii=False,indent=2))
 print('RESULT pass='+str(sum(c['pass'] for c in checks))+' fail='+str(sum(not c['pass'] for c in checks)))
