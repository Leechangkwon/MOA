#!/usr/bin/env python3
"""Ten-image story, ownership, strict structure, persistence and actual mobile editor."""
from pathlib import Path
exec(compile(Path(__file__).with_name('memory_ai_http.py').read_text().split('try:\n for u in users:')[0],__file__,'exec'))
try:
 for u in users:call('GET','/api/me/space',user=u)
 ids=[upload(name='scene-'+str(i)+'.png')['id'] for i in range(10)]
 data=dict(payload(),media_ids=ids);pid=save(data);before=post(pid)
 for _ in range(2):sql((root/'db/migrations/2026-10-03_memory_story.sql').read_text())
 check(post(pid)==before,'story migration reapplication preserves existing populated records')
 keydir=root.parent/'private-messenger-ai';keydir.mkdir(mode=0o700)
 (keydir/'gemini.key').write_text('synthetic-key-for-disposable-test-only');(keydir/'gemini.key').chmod(0o600)
 sql('UPDATE member_ai_settings SET enabled=true');mode()
 request=dict(body(before),format='story');answer=generate(pid,request)
 story=answer['story'];suggestion=answer['suggested_story']
 check([i for s in story['scenes'] for i in s['media_ids']]==ids,'all ten photos appear once in registered order')
 check(len(story['scenes'])==5 and bool(suggestion) and bool(answer['order_reason']),'scene grouping and optional order proposal are separate')
 sent=captures()[-1]['contents'][0]['parts']
 check(len([p for p in sent if 'inlineData' in p])==10 and [p['text'] for p in sent[1:] if 'text' in p]==['사진 '+str(i)+' / 10' for i in range(1,11)],'all ten verified image inputs have explicit sequence labels')
 check(post(pid)==before,'story generation and suggestion never modify original post')
 for bad in ['missing','duplicate']:
  mode(bad=bad);generate(pid,dict(request,request_id=str(uuid.uuid4())),422)
  check(post(pid)==before,'malformed provider '+bad+' preserves post')
 mode();edit={k:before[k] for k in ['caption','location','trip','taken_on','visibility','revision']};edit.update(media_ids=ids,story=story)
 for corrupt in [dict(story,url='https://invalid/'),dict(story,scenes=[dict(story['scenes'][0],media_ids=[str(uuid.uuid4())])]),dict(story,scenes=story['scenes']+[story['scenes'][0]])]:
  call('PATCH','/api/memories/'+pid,dict(edit,story=corrupt),expected=409)
 check(post(pid)==before,'invalid story fields, foreign IDs and duplicate photos rejected')
 call('PATCH','/api/memories/'+pid,edit);saved=post(pid)
 check(saved['story']==story and saved['caption']==before['caption'] and saved['original_caption']==before['original_caption'] and saved['media']==before['media'],'structured save preserves caption and originals')
 legacy=dict(edit,revision=saved['revision']);del legacy['story'];call('PATCH','/api/memories/'+pid,legacy)
 check(post(pid)['story']==story,'old client omission preserves saved story')
 legacy['revision']=post(pid)['revision'];call('PATCH','/api/memories/'+pid,dict(legacy,media_ids=ids[1:]),expected=409)
 # Browser edits/reorders the generated proposal and explicitly saves it.
 reset_rate()
 with sync_playwright() as pw:
  browser=pw.chromium.launch();ctx=browser.new_context(viewport={'width':390,'height':844},user_agent='tbox-review/'+time.strftime('%Y-%m-%d'))
  ctx.add_cookies([{'name':'__Host-tbox_session','value':users['employee']['session'],'domain':urlsplit(site).hostname,'path':'/','secure':True,'httpOnly':True}])
  tab=ctx.new_page();errors=[];tab.on('pageerror',lambda e:errors.append(str(e)))
  tab.goto(base+'/',wait_until='networkidle');expect(tab.locator('.post .story-scene')).to_have_count(5)
  check(tab.locator('.post .photo-story img').count()==10 and tab.locator('.post .photo-story script').count()==0,'saved story displays all ten photos and escapes text')
  tab.get_by_role('button',name='AI로 꾸미기',exact=True).click();expect(tab.locator('#gemini-generate')).to_be_enabled()
  check(tab.locator('#gemini-photos input:checked').count()==10,'all ten photos selected by default')
  tab.locator('#gemini-generate').click();expect(tab.locator('#studio-story-preview .story-scene')).to_have_count(5)
  current=post(pid);check(current['story']==story,'preview stays in registered order without saving')
  expect(tab.locator('#studio-order-toggle')).to_be_visible();tab.locator('#studio-order-toggle').click()
  expect(tab.locator('#studio-story-preview h3').first).to_have_text('장면 4')
  tab.locator('#studio-story-section details summary').click()
  title=tab.locator('#studio-story-editor textarea').first;title.fill('직접 고친 제목')
  tab.locator('#studio-order-toggle').click();tab.locator('#studio-order-toggle').click();expect(title).to_have_value('직접 고친 제목')
  check(True,'proposal toggle preserves edits and never silently changes registered default')
  tab.locator('#studio-append').click();expect(tab.locator('#compose-story-section')).to_be_visible()
  expect(tab.locator('#caption')).to_have_value(before['caption']);tab.locator('#publish').click();expect(tab.locator('#compose-dialog')).not_to_be_visible()
  saved=post(pid);expected=[i for s in suggestion['scenes'] for i in s['media_ids']]
  check([m['id'] for m in saved['media']]==expected and saved['story']['title']=='직접 고친 제목','explicit save applies selected story order and edited text')
  check(saved['caption']==before['caption'] and saved['original_caption']==before['original_caption'] and saved['original_media']==before['original_media'] and saved['visibility']==before['visibility'],'all original text, original media and visibility preserved')
  check(tab.evaluate('document.documentElement.scrollWidth<=innerWidth+1') and not errors,'390px story page fits with no JS error')
  tab.screenshot(path=str(out/'story-mobile.png'),full_page=True)
  tab.set_viewport_size({'width':1280,'height':1000});tab.screenshot(path=str(out/'story-desktop.png'),full_page=True)
  reset_rate();tab.get_by_role('button',name='AI로 꾸미기',exact=True).click();expect(tab.locator('#gemini-generate')).to_be_enabled()
  tab.locator('#gemini-photos input').first.uncheck();tab.locator('#gemini-generate').click();expect(tab.locator('#studio-story-preview .story-scene')).to_have_count(5)
  tab.locator('#studio-append').click();tab.locator('#publish').click();expect(tab.locator('#compose-dialog')).not_to_be_visible()
  saved=post(pid);check([m['id'] for m in saved['media']]==expected,'default subset story does not reorder any existing photo')
  check([i for s in saved['story']['scenes'] for i in s['media_ids']]==expected[1:],'subset story references only selected photos in their existing order')
  browser.close()
 edit={k:saved[k] for k in ['caption','location','trip','taken_on','visibility','revision']};edit.update(media_ids=expected,visibility='shared')
 call('PATCH','/api/memories/'+pid,edit)
 link=call('POST','/api/memories/links',{'post_id':pid},expected=201)['path'].removeprefix('/community')
 public=call('GET',link+'/feed',user=None)['posts'][0]
 check(public['story']==saved['story'] and 'original_caption' not in public and 'original_media' not in public,'anonymous sharing gets only published story and current media')
 edit['revision']=post(pid)['revision'];call('PATCH','/api/memories/'+pid,dict(edit,media_ids=expected[1:],story=None))
 check(post(pid)['story'] is None,'explicitly clearing structure allows photo edits')
finally:
 (out/'results.json').write_text(json.dumps(checks,ensure_ascii=False,indent=2));print('RESULT pass='+str(sum(x['pass'] for x in checks))+' fail='+str(sum(not x['pass'] for x in checks)),flush=True)
