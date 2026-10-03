#!/usr/bin/env python3
"""Actual PHP/DB and 390px append/save; only provider transport is replaced in the disposable copy."""
from pathlib import Path
exec(compile(Path(__file__).with_name('memories_http.py').read_text().split('try:\n for u in users:')[0],__file__,'exec'))
from playwright.sync_api import sync_playwright,expect
from urllib.parse import urlsplit
assert str(root).startswith('/tmp/qaredi-stage13-')
transport=root/'src/Fetch.php'
original=transport.read_text()
needle="        $host = 'generativelanguage.googleapis.com';"
assert original.count(needle)==1
transport.write_text(original.replace(needle,"        return require __DIR__ . '/memory-ai-test-transport.php';\n"+needle))
(root/'src/memory-ai-test-transport.php').write_bytes((root/'tests/community/memory_ai_transport.php.fixture').read_bytes())
storage=root.parent/'storage';capture=storage/'transport-capture.jsonl'
def mode(**kw): (storage/'transport-mode.json').write_text(json.dumps(kw))
def reset_rate(): sql("UPDATE member_rate_limits SET window_start=now()-interval '2 minutes'")
def captures():return [json.loads(x) for x in capture.read_text().splitlines()] if capture.exists() else []
def body(post):return dict(consent=True,request_id=str(uuid.uuid4()),revision=post['revision'],media_ids=[x['id'] for x in post['media']],tone='담백하고 따뜻한',wish='사진을 참고한 추가 문장')
def post(pid):return call('GET','/api/memories/'+pid)['post']
def generate(pid,b,expected=200):reset_rate();return call('POST','/api/memories/'+pid+'/caption',b,expected=expected)
try:
 for u in users:call('GET','/api/me/space',user=u)
 media=upload();pid=save(dict(payload(),media_ids=[media['id']]))
 before=post(pid);request=body(before)
 check(call('GET','/api/memories/ai')['ready'] is False,'missing Gemini setting is not ready')
 generate(pid,request,503);check(not captures(),'disabled provider makes no outbound request')
 keydir=root.parent/'private-messenger-ai';keydir.mkdir(mode=0o700)
 (keydir/'gemini.key').write_text('synthetic-key-for-disposable-test-only');(keydir/'gemini.key').chmod(0o600)
 sql('UPDATE member_ai_settings SET enabled=true');mode()
 check(call('GET','/api/memories/ai')['ready'] is True,'existing Gemini settings and key enable caption route')
 got=generate(pid,request)
 check(got['state']=='complete' and got['photo_count']==1,'real PHP ledger completes photo preview')
 sent=captures()[0]['contents'][0]['parts']
 check(base64.b64decode(sent[1]['inlineData']['data'])==png and json.loads(sent[0]['text'])['현재 메모']==before['caption'],'actual verified photo bytes and current caption reach transport')
 check(post(pid)==before,'generation preserves all post fields and original media')
 count=len(captures());check(generate(pid,request)['text']==got['text'] and len(captures())==count,'same request returns existing answer without a second provider call')
 generate(pid,dict(request,wish='changed'),409)
 for error in (429,504):
  mode(error=error);failed=generate(pid,dict(request,request_id=str(uuid.uuid4())))
  check(failed['state']=='failed' and failed['text']=='' and bool(failed['error']),'provider error '+str(error)+' becomes recoverable failed preview')
  check(post(pid)==before,'provider failure preserves original '+str(error))
 mode();sql('UPDATE member_ai_settings SET user_daily=1')
 generate(pid,dict(request,request_id=str(uuid.uuid4())),429)
 check(len(captures())==3,'shared daily budget blocks extra provider use')
 sql('UPDATE member_ai_settings SET user_daily=40')
 # Race on a second PHP worker: provider has started, then record changes.
 for mutation in ('revision','deleted_at'):
  fresh=post(pid);b=body(fresh);mode(delay=True);reset_rate();count=len(captures())
  with ThreadPoolExecutor(max_workers=1) as pool:
   future=pool.submit(raw,'POST','/api/memories/'+pid+'/caption',b)
   for _ in range(100):
    if len(captures())>count:break
    time.sleep(.03)
   check(len(captures())>count,'provider started before '+mutation+' race')
   sql("UPDATE community.memory_posts SET "+('revision=revision+1' if mutation=='revision' else 'deleted_at=now()')+" WHERE id='"+pid+"'")
   response=future.result()
  check(response.status_code==(409 if mutation=='revision' else 404),'late answer rejected after '+mutation)
  if mutation=='deleted_at':sql("UPDATE community.memory_posts SET deleted_at=NULL WHERE id='"+pid+"'")
 mode();reset_rate()
 # Ordinary AI chat keeps the text-only route and shared ledger.
 headers={'User-Agent':'tbox-review/'+time.strftime('%Y-%m-%d'),'Cookie':'__Host-tbox_session='+users['employee']['session'],'X-CSRF-Token':users['employee']['csrf']}
 r=requests.post(site+'/tbox/api/messages/ai',headers=headers,json={'thread_id':None,'text':'일반 대화 시험','request_id':str(uuid.uuid4())},timeout=10)
 check(r.status_code==200 and r.json()['turn']['state']=='complete','ordinary Gemini chat still completes')
 check(all('inlineData' not in p for p in captures()[-1]['contents'][-1]['parts']),'ordinary Gemini chat remains text only')
 for error in (None,429):
  mode(**({'error':error} if error else {}));reset_rate()
  r=requests.post(site+'/tbox/api/messages/assist',headers=headers,json={'action':'polish','aspect':'1:1','language':'ko','peer_id':int(users['other']['id']),'request_id':str(uuid.uuid4()),'source_id':None,'text':'기존 문장 도구 시험'},timeout=10)
  check(r.status_code==200 and r.json()['job']['state']==('failed' if error else 'complete'),'ordinary Gemini writing tool '+('provider failure' if error else 'success'))
 check(sql("SELECT count(*) FROM audit_logs WHERE action IN ('member.ai.send','member.ai.assist') AND result='deny' AND reason IN ('provider_limit','provider_unavailable')")=='3','provider failures retain reason-coded audit records')
 mode()
 reset_rate();before=post(pid)
 with sync_playwright() as pw:
  browser=pw.chromium.launch();ctx=browser.new_context(viewport={'width':390,'height':844},user_agent=headers['User-Agent'])
  ctx.add_cookies([{'name':'__Host-tbox_session','value':users['employee']['session'],'domain':urlsplit(site).hostname,'path':'/','secure':True,'httpOnly':True}])
  tab=ctx.new_page();tab.goto(base+'/',wait_until='networkidle')
  tab.get_by_role('button',name='AI로 꾸미기',exact=True).click();expect(tab.locator('#gemini-generate')).to_be_enabled()
  tab.locator('#gemini-photos input').uncheck();tab.locator('#gemini-generate').click();expect(tab.locator('#studio-result')).to_have_value('합성 사진을 참고한 추가 초안입니다.')
  check(post(pid)==before,'390px actual PHP preview never autosaves')
  tab.locator('#studio-append').click();expect(tab.locator('#caption')).to_have_value(before['caption']+'\n\n합성 사진을 참고한 추가 초안입니다.')
  expect(tab.locator('#media-preview img')).to_have_count(1)
  tab.locator('#publish').click();expect(tab.locator('#compose-dialog')).not_to_be_visible()
  after=post(pid)
  check(after['caption']==before['caption']+'\n\n합성 사진을 참고한 추가 초안입니다.','390px append and explicit save persist combined caption')
  check(after['media']==before['media'] and after['original_media']==before['original_media'] and after['original_caption']==before['original_caption'] and after['visibility']==before['visibility'],'photos, original caption, original media and visibility survive actual save')
  tab.screenshot(path=str(out/'gemini-saved-mobile.png'));browser.close()
finally:
 (out/'results.json').write_text(json.dumps(checks,ensure_ascii=False,indent=2));print('RESULT pass='+str(sum(x['pass'] for x in checks))+' fail='+str(sum(not x['pass'] for x in checks)),flush=True)
