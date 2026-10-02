#!/usr/bin/env python3
"""Real modal/components in Chromium; mocked HTTP. Backend authorization is NOT simulated as proof."""
import json
from pathlib import Path
from urllib.parse import urlparse, parse_qs
from playwright.sync_api import sync_playwright
from memories_browser import ROOT, ORIGIN, Mock, record

CID = '33333333-3333-4333-8333-333333333333'
AT = '2026-10-03T06:00:00Z'

class MessengerMock(Mock):
    def __init__(self):
        super().__init__()
        self.messages = [{'id':'1', 'body':'여행 잘 다녀와! <script>window.bad=1</script>', 'mine':False, 'created_at':AT}]
        self.turns = []
        self.sent = []
        self.gpt_requests = []
        self.fail = False
        self.enabled = True
        self.hold_gpt = False
        self.held = None
        self.reads = []
        self.posts = [record()]
    def route(self, route):
        req = route.request; parsed=urlparse(req.url); path=parsed.path; query=parse_qs(parsed.query)
        if not path.startswith('/community/api/messenger'):
            return super().route(route)
        def send(data, status=200): route.fulfill(status=status, content_type='application/json', body=json.dumps(data,ensure_ascii=False))
        if req.method=='POST': assert req.headers.get('x-csrf-token')=='fixture-csrf'
        if path.endswith('/api/messenger'):
            send({'conversations':[{'id':CID,'username':'friend','display_name':'친구','unread':1,'preview':self.messages[-1]['body']}], 'csrf_token':'fixture-csrf'}); return
        if path.endswith('/members'):
            send({'members':[{'username':'friend','display_name':'친구'}] if '친구' in query.get('q',[''])[0] else []}); return
        if path.endswith('/conversations'):
            assert req.post_data_json=={'username':'friend'}; send({'id':CID}); return
        if path.endswith('/gpt'):
            if req.method=='GET': send({'enabled':self.enabled,'turns':self.turns,'csrf_token':'fixture-csrf'}); return
            body=req.post_data_json; self.gpt_requests.append(body)
            if self.hold_gpt: self.held=route; return
            turn={'id':str(len(self.turns)+1),'prompt':body['body'],'reply':'바람이 머문 바닷가에 오늘의 마음을 남겼다.','created_at':AT}; self.turns.append(turn); send({'turn':turn}); return
        if path.endswith('/messages'):
            self.sent.append(req.post_data_json)
            if self.fail: send({'error':'연결이 끊겼어요.'},503); return
            m={'id':str(len(self.messages)+1),'body':req.post_data_json['body'],'mine':True,'created_at':AT};self.messages.append(m);send({'message':m},201);return
        if path.endswith('/read'): self.reads.append(req.post_data_json); send({'ok':True}); return
        if path.endswith(CID):
            send({'messages':self.messages,'has_more':False,'peer':{'username':'friend','display_name':'친구'}});return
        send({'error':'not found'},404)


def main():
    out=Path('/workspace/scratch/memories-preview');out.mkdir(parents=True,exist_ok=True); checks=[]
    def ok(x): checks.append(x);print('PASS',x,flush=True)
    with sync_playwright() as p:
        browser=p.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
        ctx=browser.new_context(viewport={'width':1440,'height':1000});mock=MessengerMock();ctx.route(ORIGIN+'/**',mock.route)
        page=ctx.new_page();errors=[];page.on('pageerror',lambda e:errors.append(str(e)));page.goto(ORIGIN+'/community/');page.locator('.post').wait_for()
        assert page.locator('aside.sidebar [data-thread-nav]').count()==3
        page.locator('[data-view="shared"]').click();page.wait_for_function("() => document.querySelector('#feed-title').textContent==='공유 스레드'")
        assert page.url.endswith('?view=shared')
        page.locator('[data-view="mine"]').click();page.locator('#profile-panel').wait_for(state='visible')
        assert page.locator('aside.sidebar [data-thread-nav=mine]').get_attribute('aria-current')=='page'
        ok('My/shared thread views and existing community navigation stay together')
        url=page.url;page.locator('.threads-quick [data-messenger-open]').click();page.locator('#community-messenger').wait_for(state='visible');page.locator('#messenger-conversations button').wait_for()
        assert page.url==url
        page.locator('#messenger-conversations button').click();page.locator('#messenger-messages .messenger-bubble').wait_for()
        assert page.locator('#messenger-messages script').count()==0
        page.wait_for_function("() => document.querySelector('#messenger-status').textContent===''")
        assert mock.reads
        ok('Messenger is a modal over the current page; member text stays text; visible messages marked read')
        page.locator('#messenger-body').fill('사진 많이 찍어 올게!')
        page.locator('#messenger-gpt-tab').click();page.locator('#messenger-body').fill('나의 GPT 초안')
        page.locator('#messenger-people-tab').click();assert page.locator('#messenger-body').input_value()=='사진 많이 찍어 올게!'
        page.locator('#messenger-close').click();page.locator('.threads-quick [data-messenger-open]').click();assert page.locator('#messenger-body').input_value()=='사진 많이 찍어 올게!'
        ok('Separate member/GPT drafts survive tab changes and modal close/reopen')
        mock.fail=True;page.locator('#messenger-send').click();page.wait_for_function("() => document.querySelector('#messenger-status').textContent.includes('연결이 끊겼어요')")
        assert page.locator('#messenger-body').input_value()=='사진 많이 찍어 올게!'
        request=mock.sent[-1]['request_id'];mock.fail=False;page.locator('#messenger-send').click();page.wait_for_function("() => document.querySelector('#messenger-body').value===''")
        assert mock.sent[-1]['request_id']==request and len(mock.messages)==2
        ok('Failed member send retains draft and retries with the same idempotency key')
        page.locator('#messenger-search').fill('친구');page.locator('#messenger-members button').wait_for();page.locator('#messenger-members button').click();page.wait_for_function("() => document.querySelector('#messenger-peer').textContent==='친구'")
        ok('Member search starts or resumes a conversation without navigation')
        page.locator('#messenger-gpt-tab').click();page.wait_for_function("() => !document.querySelector('#messenger-send').disabled")
        assert page.locator('#messenger-body').input_value()=='나의 GPT 초안'
        mock.hold_gpt=True;page.locator('#messenger-send').click();page.wait_for_timeout(80);assert mock.held
        page.locator('#messenger-people-tab').click();page.locator('#messenger-body').fill('회원에게만 보내는 초안')
        turn={'id':'1','prompt':mock.gpt_requests[-1]['body'],'reply':'차분한 하루의 기록입니다.','created_at':AT};mock.turns.append(turn);mock.held.fulfill(content_type='application/json',body=json.dumps({'turn':turn}));mock.hold_gpt=False
        page.wait_for_timeout(100);assert page.locator('#messenger-body').input_value()=='회원에게만 보내는 초안'
        assert mock.gpt_requests[-1]['body']=='나의 GPT 초안'
        page.locator('#messenger-gpt-tab').click();page.locator('#messenger-gpt-messages .messenger-bubble').first.wait_for();assert page.locator('#messenger-gpt-messages .messenger-bubble').count()==2
        ok('Late GPT response cannot overwrite a member draft; only explicitly submitted GPT text is sent')
        mock.messages[0]['body']='여행 잘 다녀와! 사진도 많이 보여 줘.'
        page.locator('#messenger-close').click();page.locator('button',has_text='GPT로 꾸미기').click();page.locator('#studio-chat').click();page.locator('#community-messenger').wait_for()
        page.wait_for_function("() => document.querySelector('#messenger-body').value.includes('바닷가에 왔다')")
        page.locator('#messenger-send').click();page.wait_for_function("() => document.querySelector('#messenger-gpt-messages').textContent.includes('바람이 머문')")
        page.locator('#messenger-gpt-messages button',has_text='꾸미기 화면으로').last.click();page.locator('#studio-dialog').wait_for();assert page.locator('#studio-result').input_value().startswith('바람이 머문')
        assert not page.locator('#compose-dialog').is_visible()
        page.locator('[data-close="studio-dialog"]').click()
        ok('Thread → GPT modal → edit preview; generated text is never published automatically')
        page.locator('.threads-quick [data-messenger-open]').click();page.locator('#messenger-gpt-tab').click();page.screenshot(path=str(out/'desktop-messenger-gpt.png'))
        page.locator('#messenger-people-tab').click();page.locator('#messenger-conversations button').click();page.screenshot(path=str(out/'desktop-messenger-member.png'))
        page.set_viewport_size({'width':390,'height':844});page.screenshot(path=str(out/'mobile-messenger.png'))
        assert page.evaluate('document.querySelector("#community-messenger").scrollWidth <= innerWidth')
        page.locator('#messenger-back').click();assert page.locator('#messenger-search').is_visible();assert page.locator('#messenger-form').is_hidden()
        page.locator('#messenger-conversations button').click();assert page.locator('#messenger-body').input_value()=='회원에게만 보내는 초안'
        page.keyboard.press('Escape');assert not page.locator('#community-messenger').is_visible()
        ok('390px mobile inbox/chat transition, Escape dismissal and retained draft')
        mock.enabled=False;page.locator('.threads-quick [data-messenger-open]').click();page.locator('#messenger-gpt-tab').click();page.locator('#messenger-gpt-unavailable').wait_for();assert page.locator('#messenger-send').is_disabled()
        ok('Unconfigured GPT is visibly unavailable and cannot fake a reply')
        assert not errors,errors
        browser.close()
    (out/'messenger-results.json').write_text(json.dumps({'coverage':'mocked HTTP; Chromium; no PHP/Postgres/provider calls','checks':checks},ensure_ascii=False,indent=2)+'\n')
    print(f'{len(checks)} messenger checks passed')

if __name__=='__main__':main()
