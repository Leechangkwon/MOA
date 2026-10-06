"""Real PHP/JS views with synthetic API/provider replies. No OpenAI/Gemini calls.

Run: python3 tests/moa_local_assist.py
Requires an already installed Playwright Chromium. Does not touch a database.
"""
import base64
import json
import os
from pathlib import Path
import secrets
import subprocess
from urllib.parse import urlsplit, parse_qs
from playwright.sync_api import sync_playwright, expect

ROOT = Path(__file__).resolve().parents[1]
ARTIFACTS = Path(os.environ.get('MOA_TEST_ARTIFACTS', '/tmp/moa-local-assist-results'))
ARTIFACTS.mkdir(parents=True, exist_ok=True)
checks = []
# Q:F-11 B-7(A 독립 검토 5) — 메신저 모듈이 쓰던 글을 암호화할 때 부르는 열쇠 창구의 합성 답(이 시험 안에서만 · 32바이트 base64url).
FIXTURE_DRAFT_KEY = base64.urlsafe_b64encode(secrets.token_bytes(32)).rstrip(b'=').decode()
# 저장소 점검: 메신저 창 상태(sessionStorage)와 쓰던 글 암호문(localStorage)만 남고, PC 도우미 자격 증명·페어링 코드는 어디에도 없다.
STORE_ITEMS = "() => { const out = []; for (const s of [localStorage, sessionStorage]) for (let i = 0; i < s.length; i++) { const k = s.key(i); out.push([k, s.getItem(k)]); } return out; }"
def no_helper_credential(page):
    items = page.evaluate(STORE_ITEMS)
    named = all(k == 'qaredi-messenger:v1:claude_employee01' or k.startswith('qaredi-draft:v1:claude_employee01:') for k, _ in items)
    clean = all(secret not in (k + (v or '')) for k, v in items for secret in ('c'*43, 'p'*43, 'a'*32))
    return named and clean
def check(name, condition):
    checks.append({'name': name, 'passed': bool(condition)})
    print(('PASS ' if condition else 'FAIL ') + name, flush=True)
    assert condition, name

def render(page='messages', admin=False):
    return subprocess.check_output(['php', str(ROOT/'tests/fixtures/moa_member_render.php'), page, 'admin' if admin else 'employee'], text=True)

class Fixture:
    def __init__(self):
        self.posts = []; self.errors = []; self.other_requests = []; self.ready = True; self.images = True
        self.jobs = []; self.turns = []; self.fail_ai = False
        self.local_posts = []; self.local_jobs = {}; self.local_mode = 'completed'
    def route(self, route):
        request = route.request; u = urlsplit(request.url); path = u.path
        if u.netloc == '127.0.0.1:43821':
            body = request.post_data_json if request.method == 'POST' else None
            if body is not None: self.local_posts.append((path, body, request.headers))
            headers = {'Access-Control-Allow-Origin':'https://changkwon.mso.so', 'Access-Control-Allow-Headers':'Authorization, Content-Type'}
            if path == '/v1/pair': data = {'request_id':body['request_id'], 'state':'awaiting_confirmation', 'poll_token':'p'*43}
            elif path.startswith('/v1/pair/'): data = {'request_id':path.rsplit('/',1)[1], 'state':'approved', 'credential':'c'*43, 'expires_in':1800}
            elif path == '/v1/unpair': data = {'state':'unpaired'}
            elif path == '/v1/jobs':
                data = {'request_id':body['request_id'], 'state':self.local_mode, 'provider':'openai_chatgpt_plan', 'model':'synthetic', 'text':'PC 완료 결과 <script>문자</script>', 'error_code': 'eligibility_unconfirmed' if self.local_mode == 'failed' else None}
                self.local_jobs[body['request_id']] = data
            elif path.endswith('/cancel'): data = {'state':'cancelled'}
            else: data = self.local_jobs[path.rsplit('/',1)[1]]
            route.fulfill(json=data, headers=headers); return
        if u.netloc not in ('moa.example', 'changkwon.mso.so'):
            self.other_requests.append(request.url); route.abort(); return
        if path == '/tbox/messages': route.fulfill(content_type='text/html', body=render()); return
        if path == '/tbox/welcome': route.fulfill(content_type='text/html', body=render('welcome')); return
        if path.startswith('/tbox/assets/'):
            file = ROOT/'public/assets'/path.removeprefix('/tbox/assets/')
            if file.is_file():
                route.fulfill(body=file.read_bytes(), content_type={'.js':'text/javascript','.css':'text/css','.jpg':'image/jpeg','.svg':'image/svg+xml'}.get(file.suffix,'application/octet-stream')); return
        if path == '/favicon.ico': route.fulfill(status=204); return
        body = request.post_data_json if request.method in ('POST','PATCH') else None
        if request.method == 'POST': self.posts.append((path, body, request.headers))
        data = {}
        if path == '/tbox/api/me':
            data = {'member_id':1001,'username':'claude_employee01','csrf_token':'fixture-csrf','mfa_enabled':True,'next':None,'token_scopes':['read+upload']}
        elif path == '/tbox/api/messages':
            data = {'people':[{'id':1002,'username':'claude_employee02','display_name':'검토 동료','unread':0},{'id':1003,'username':'claude_manager01','display_name':'다른 동료','unread':0}]}
        elif path in ('/tbox/api/messages/1002','/tbox/api/messages/1003'):
            peer = int(path.rsplit('/',1)[1])
            data = {'peer':{'id':peer,'username':'claude_employee02' if peer == 1002 else 'claude_manager01','display_name':'검토 동료' if peer == 1002 else '다른 동료'},'messages':[{'id':77,'sender_id':peer,'body':'Hello, how are you?','sent_at':'2026-10-02T12:00:00Z','read_at':None,'ai_attachment_id':None}], 'has_more':False}
            if request.method in ('PATCH','POST'): data = {'ok':True, 'id':78}
        elif path == '/tbox/api/messages/assist':
            if request.method == 'POST':
                job = {'id':'b8f8454b-19c6-4d79-9b4c-1803dd1e4c31', 'request_id':body['request_id'], 'state':'complete','input':body['text'],'source_id':body['source_id'],'action':body['action'],'language':body['language'],'aspect':body['aspect'],'result':'Gemini 모의 결과','image_url':None}
                if body['action'] == 'image': job['image_url']='/tbox/api/messages/attachments/'+job['id']
                self.jobs.insert(0,job); data = {'job':job}
            else: data = {'ready':self.ready,'image_enabled':self.images,'remaining':40,'image_remaining':3,'jobs':self.jobs}
        elif path.startswith('/tbox/api/messages/attachments/'):
            import base64
            route.fulfill(content_type='image/png', body=base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=')); return
        elif path == '/community/api/me/draft-key' and request.method == 'GET':
            data = {'v':1, 'key':FIXTURE_DRAFT_KEY}
        elif path == '/community/api/me/guide' and request.method == 'GET':
            # 사용 안내(029f753 이 모아 머리에 넣은 usage_guide.php)가 화면마다 상태를 읽는다 — 이미 닫은 사람의 합성 상태
            data = {'guide':{'version':1,'status':'dismissed','context':'overview','step':0,'offer':False}, 'csrf_token':'fixture-csrf'}
        elif path == '/tbox/api/messages/ai':
            if self.fail_ai: route.fulfill(status=503,json={'error':'fixture unavailable'}); return
            if request.method == 'POST':
                turn={'id':1,'thread_id':1,'request_id':body['request_id'],'state':'complete','prompt':body['text'],'answer':'Gemini 대화 모의 응답','created_at':'2026-10-02T12:00:00Z','finished_at':'2026-10-02T12:00:01Z'}
                self.turns=[turn];data={'turn':turn}
            else: data = {'ready':self.ready,'thread':{'id':1,'title':'검토 대화'} if self.turns else None,'threads':[],'turns':self.turns,'remaining':40,'user_daily':40,'has_more':False,'notice':'Gemini 준비 전'}
        else:
            self.other_requests.append(path); route.fulfill(status=404,json={'error':'Unknown fixture'}); return
        route.fulfill(json=data)

with sync_playwright() as p:
    browser = p.chromium.launch(headless=True)
    context = browser.new_context(viewport={'width':1280,'height':1000}, reduced_motion='reduce')
    f = Fixture(); context.route('**/*', f.route)
    context.add_init_script("""window.fixtureClipboard = []; window.fixtureClipboardFails = false;
      Object.defineProperty(navigator, 'clipboard', {value:{writeText: async text => {
        if(window.fixtureClipboardFails) throw Error('denied'); window.fixtureClipboard.push(text);
      }}});""")
    page = context.new_page(); page.on('pageerror', lambda e: f.errors.append(str(e)))
    page.goto('https://moa.example/tbox/messages')
    expect(page.locator('#message-peer-name')).to_have_text('모아 AI')
    def peer(name='검토 동료'):
        page.locator('#message-people button').filter(has_text=name).click()
        expect(page.locator('#message-peer-name')).to_contain_text(name)
    def open_tool(provider='local', text='  원문을 그대로 복사합니다.\n둘째 줄 😀  '):
        peer(); page.locator('#message-text').fill(text); page.locator('#message-assist').click()
        expect(page.locator('#message-assist-dialog')).to_be_visible()
        page.locator('[data-assist-provider="'+provider+'"]').click()
    def preview(text):
        page.locator('#assist-manual-input').fill(text); page.locator('#assist-manual-preview').click()
    def posts(path): return [item for item in f.posts if item[0] == path]

    open_tool()
    source = page.locator('#assist-input').input_value()
    check('local mode hides Gemini request/quota', not page.locator('#assist-gemini-run').is_visible())
    page.locator('#assist-local-copy').click()
    check('local clipboard contains exact original only', page.evaluate('fixtureClipboard.at(-1)') == source)
    check('copy performs no provider or messenger POST', not f.posts)
    page.evaluate('fixtureClipboardFails = true'); page.locator('#assist-local-copy').click()
    expect(page.locator('#assist-status')).to_contain_text('직접 복사')
    check('clipboard failure selects exact original', page.locator('#assist-input').evaluate('(e)=>e.value.slice(e.selectionStart,e.selectionEnd)') == source)
    page.evaluate('fixtureClipboardFails = false')
    page.locator('#assist-input').fill('수정된 원문'); page.locator('#assist-restore-input').click()
    check('restore input returns opening original', page.locator('#assist-input').input_value() == source)
    for code, label in [('ko','한국어'),('en','영어'),('ja','일본어'),('zh','중국어'),('vi','베트남어'),('es','스페인어')]:
        page.locator('[data-assist-action="translate"]').click(); page.locator('#assist-language').select_option(code)
        check('local translation language '+code, label in page.locator('#assist-local-task').inner_text())
    page.locator('[data-assist-action="polish"]').click()
    payload = '<img src=x onerror="alert(1)"> 다듬은 결과 😀'
    preview(payload)
    check('manual preview preserves text and executes no HTML', page.locator('#assist-output').input_value() == payload and page.locator('#assist-result img[onerror]').count() == 0)
    check('preview makes no POST', not f.posts)
    page.locator('#assist-apply').click()
    check('explicit apply fills composer without sending', page.locator('#message-text').input_value() == payload and not f.posts)
    page.locator('#message-assist-undo').click()
    check('apply undo restores draft', page.locator('#message-text').input_value() == source)

    open_tool(text='😀'*4000)
    page.locator('#assist-local-copy').click()
    check('4000 Unicode code points accepted without truncation', page.evaluate('fixtureClipboard.at(-1)') == '😀'*4000)
    before = page.evaluate('fixtureClipboard.length')
    page.locator('#assist-input').fill('가'*4001);page.locator('#assist-local-copy').click()
    check('4001 source rejected without copying', page.evaluate('fixtureClipboard.length') == before)
    page.locator('#assist-input').fill('가'*8500);page.locator('#assist-local-copy').click()
    check('long paste is preserved for correction, never silently truncated', len(page.locator('#assist-input').input_value()) == 8500 and page.evaluate('fixtureClipboard.length') == before)
    page.locator('#assist-input').fill(' \n\t ');page.locator('#assist-local-copy').click()
    check('whitespace source rejected', page.evaluate('fixtureClipboard.length') == before)
    page.locator('#assist-input').fill('정상 원문');preview('가'*4001)
    check('4001 result rejected', not page.locator('#assist-result').is_visible())
    preview('   ');check('whitespace result rejected', not page.locator('#assist-result').is_visible())
    preview('😀'*4000);check('4000 Unicode result accepted', page.locator('#assist-result').is_visible())
    page.locator('#assist-input').fill('바뀐 원문');check('source edit invalidates result', not page.locator('#assist-result').is_visible())
    preview('결과');page.locator('[data-assist-action="translate"]').click()
    check('action change invalidates result', not page.locator('#assist-result').is_visible())
    preview('result');page.locator('#assist-language').select_option('en')
    check('language change invalidates result', not page.locator('#assist-result').is_visible())
    preview('result');page.locator('#assist-manual-input').fill('새 결과')
    check('pasted result edit requires another preview', not page.locator('#assist-result').is_visible())
    page.locator('#assist-manual-preview').click()
    page.locator('#message-text').evaluate("e=>{e.value='백그라운드에서 바뀐 작성란';e.dispatchEvent(new Event('input',{bubbles:true}));}")
    page.locator('#assist-apply').click()
    check('stale draft cannot be overwritten', page.locator('#message-text').input_value() == '백그라운드에서 바뀐 작성란' and page.locator('#message-assist-dialog').is_visible())
    page.locator('#assist-close').click();peer('다른 동료')
    check('peer switch does not leak draft/result', page.locator('#message-text').input_value() == '')

    open_tool('web','웹용 문장');page.locator('#assist-external-copy').click()
    check('ChatGPT web keeps instruction prompt path', '웹용 문장' in page.evaluate('fixtureClipboard.at(-1)') and '다듬어' in page.evaluate('fixtureClipboard.at(-1)'))
    page.evaluate('fixtureClipboardFails=true');page.locator('#assist-external-copy').click()
    check('external clipboard fallback displays full prompt', page.locator('#assist-external-prompt').is_visible() and '웹용 문장' in page.locator('#assist-external-prompt').input_value())
    page.evaluate('fixtureClipboardFails=false')
    preview('웹에서 가져온 결과');page.locator('[data-assist-provider="local"]').click()
    check('provider change invalidates preview', not page.locator('#assist-result').is_visible())
    page.locator('[data-assist-action="image"]').click()
    check('local image unsupported without auto fallback', page.locator('#assist-local-image-note').is_visible() and not page.locator('#assist-manual-result').is_visible() and not f.posts)
    page.locator('#assist-close').click()

    f.ready=False;open_tool();page.locator('#assist-local-copy').click()
    check('manual path works with Gemini disabled', page.evaluate('fixtureClipboard.at(-1)') == page.locator('#assist-input').input_value())
    page.locator('#assist-close').click();f.ready=True
    peer();page.locator('.moa-message-translate').first.click();page.locator('[data-assist-provider="local"]').click()
    check('received translation defaults Korean and readonly source', page.locator('#assist-language').input_value() == 'ko' and page.locator('#assist-input').evaluate('e=>e.readOnly') and page.locator('#assist-input').input_value() == 'Hello, how are you?')
    check('received translation prevents unrelated source operations', page.locator('[data-assist-action="polish"]').is_disabled())
    page.locator('#assist-local-copy').click();check('received text copy excludes sender and history', page.evaluate('fixtureClipboard.at(-1)') == 'Hello, how are you?')
    page.locator('#assist-close').click()

    open_tool('gemini','Gemini 검토 문장');page.locator('#assist-run').click()
    expect(page.locator('#assist-result')).to_be_visible()
    check('Gemini tool still calls existing API with CSRF', len(posts('/tbox/api/messages/assist')) == 1 and posts('/tbox/api/messages/assist')[0][2].get('x-csrf-token') == 'fixture-csrf')
    page.locator('#assist-apply').click();check('Gemini apply still needs separate send', not posts('/tbox/api/messages/1002'))
    page.locator('#message-send').click();expect(page.locator('#message-text')).to_have_value('')
    check('only explicit Send posts message', len(posts('/tbox/api/messages/1002')) == 1)
    open_tool('gemini','감사 카드');page.locator('[data-assist-action="image"]').click();page.locator('#assist-run').click()
    expect(page.locator('#assist-image')).to_be_visible();page.locator('#assist-apply').click()
    check('Gemini image preview/attachment retained without send', page.locator('#message-attachment').is_visible() and len(posts('/tbox/api/messages/1002')) == 1)
    page.locator('#message-attachment-remove').click()
    page.locator('#message-text').fill('가'*4001);page.locator('#message-send').click()
    check('composer rejects 4001 code points without POST', len(posts('/tbox/api/messages/1002')) == 1)
    page.locator('#message-ai').click();expect(page.locator('#message-peer-name')).to_have_text('모아 AI')
    page.locator('#message-text').fill('Gemini 채팅 확인');page.locator('#message-send').click()
    expect(page.locator('#message-list')).to_contain_text('Gemini 대화 모의 응답')
    check('Gemini private chat retained', len(posts('/tbox/api/messages/ai')) == 1)

    open_tool('local','반응형 검토 문장')
    for width in (360,412,820,1280):
        page.set_viewport_size({'width':width,'height':950})
        for theme in ('light','dark'):
            page.locator('html').evaluate('(e,t)=>e.dataset.theme=t',theme)
            check(f'dialog fits viewport {width}/{theme}', page.locator('#message-assist-dialog').evaluate('(e)=>e.getBoundingClientRect().left>=0 && e.getBoundingClientRect().right<=innerWidth && e.scrollWidth<=e.clientWidth+1'))
        if width == 1280: page.screenshot(path=str(ARTIFACTS/'local-desktop.png'))
        if width == 360: page.screenshot(path=str(ARTIFACTS/'local-mobile.png'))
    page.locator('#assist-close').click();page.goto('https://moa.example/tbox/welcome')
    expect(page.locator('#welcome-buddy')).to_be_visible()
    page.locator('#welcome-local-buddy summary').click()
    guide=page.locator('#welcome-local-buddy')
    # 안내 문구는 029f753(2026-10-03 「가입 선택과 … 사용성 개선」)이 의도적으로 바꿨다 — 「비공개 Git2 저장소」 · 공유 브랜치 링크 → 고정 판 ZIP·README 링크.
    check('welcome explains unconfirmed connection and private repository', '연결 조건 확인 중' in guide.inner_text() and '비공개 Git2 저장소' in guide.inner_text())
    check('correct shared-copy ZIP link, no token input in local guide', 'archive/f7caeb47f56f91720f645e635cb3a384bd64b665.zip' in guide.locator('a').first.get_attribute('href') and guide.locator('input').count()==0)
    page.goto('https://changkwon.mso.so/tbox/messages')
    open_tool('local', '  PC 전송 원문 😀\n둘째 줄  ')
    check('no loopback request before user pairs', not f.local_posts)
    page.locator('#assist-local-code').fill('a'*32); page.locator('#assist-local-pair').click()
    expect(page.locator('#assist-local-state')).to_contain_text('PC 연결됨')
    check('pairing clears code and stores no browser credential', page.locator('#assist-local-code').input_value() == '' and no_helper_credential(page))
    page.locator('#assist-local-run').click(); expect(page.locator('#assist-output')).to_have_value('PC 완료 결과 <script>문자</script>')
    sent = next(item for item in f.local_posts if item[0] == '/v1/jobs')
    check('bridge payload exact fields and original whitespace', set(sent[1]) == {'schema_version','request_id','action','text','target_language'} and sent[1]['text'] == '  PC 전송 원문 😀\n둘째 줄  ')
    check('bridge has isolated credential without site cookie or CSRF', sent[2].get('authorization') == 'MoaLocal '+'c'*43 and 'cookie' not in sent[2] and 'x-csrf-token' not in sent[2])
    count=len(posts('/tbox/api/messages/1002')); page.locator('#assist-apply').click()
    check('PC result only fills draft without sending', page.locator('#message-text').input_value() == 'PC 완료 결과 <script>문자</script>' and len(posts('/tbox/api/messages/1002')) == count)
    open_tool('local','배포 자격 미확인 검사'); f.local_mode='failed'; page.locator('#assist-local-run').click()
    expect(page.locator('#assist-status')).to_contain_text('배포 자격'); check('eligibility failure exposes no result', not page.locator('#assist-result').is_visible())
    f.local_mode='running'; page.locator('#assist-local-run').click(); expect(page.locator('#assist-local-cancel')).to_be_enabled()
    page.locator('#assist-local-cancel').click(); page.wait_for_timeout(1000)
    check('cancel does not paint late result', not page.locator('#assist-result').is_visible() and any(x[0].endswith('/cancel') for x in f.local_posts))
    page.locator('#assist-local-unpair').click(); expect(page.locator('#assist-local-run')).to_be_disabled()
    check('unpair clears tab credential', no_helper_credential(page))
    page.locator('#assist-close').click()
    check('no unexpected/local/OpenAI network requests', not f.other_requests)
    check('no browser runtime errors', not f.errors)
    (ARTIFACTS/'results.json').write_text(json.dumps(checks,ensure_ascii=False,indent=2))
    print(f'{len(checks)} checks passed; synthetic browser/API fixtures only; {ARTIFACTS}',flush=True)
    browser.close()
