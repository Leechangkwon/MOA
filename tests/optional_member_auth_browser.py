"""Actual welcome/login markup and JavaScript, synthetic API and WebAuthn.

No PHP/database or real credentials. The PHP/API integration suites must also run
on the isolated server before deployment; these checks cover browser choices.
"""
import json
import os
import re
from pathlib import Path
from playwright.sync_api import sync_playwright, expect

ROOT = Path(__file__).resolve().parents[1]
ORIGIN = 'http://localhost:8317'
checks = 0
errors = []


def check(label, result):
    global checks
    assert result, label
    checks += 1
    print('PASS ' + label)


def markup(name):
    source = (ROOT / ('src/handlers/' + name + '.php')).read_text()
    if name == 'member_welcome_page':
        source = source[source.index('<ol class="moa-steps"'):]
    elif name == 'member_join_page':
        source = source[source.index('<div class="moa-onboarding"'): ]
    else:
        source = source[source.index('<form id="login-form"'):source.index('</form>') + len('</form>')]
    return re.sub(r'<\?(?:php|=).*?\?>', '', source, flags=re.S)


MOCK = """() => {
  window.__creates = []; window.__gets = [];
  window.PublicKeyCredential = function() {};
  PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable = async () => true;
  PublicKeyCredential.isConditionalMediationAvailable = async () => false;
  Object.defineProperty(navigator, 'credentials', {value: {
    create: async (options) => {
      window.__creates.push({uv: options.publicKey.authenticatorSelection.userVerification, rp: options.publicKey.rp.id});
      if(window.__cancel) throw new DOMException('cancel', 'NotAllowedError');
      return {id:'AQID', rawId:new Uint8Array([1,2,3]).buffer, type:'public-key', authenticatorAttachment:'platform',
        response:{clientDataJSON:new Uint8Array([1]).buffer, attestationObject:new Uint8Array([2]).buffer, getTransports:()=>['internal']}};
    },
    get: async (options) => {window.__gets.push(options.mediation || 'optional'); throw new DOMException('cancel','NotAllowedError');}
  }});
}"""


with sync_playwright() as pw:
    browser = pw.chromium.launch(executable_path=os.environ.get('CHROMIUM_EXECUTABLE', '/usr/bin/chromium'), args=['--no-sandbox'])
    for mode in ['optional', 'otp', 'required', 'recovery', 'cancel']:
        writes = []
        context = browser.new_context(viewport={'width':390, 'height':844})
        context.add_init_script('(' + MOCK + ')()')
        page = context.new_page()
        page.on('pageerror', lambda e: errors.append(str(e)))
        me = dict(csrf_token='synthetic-csrf', mfa_enabled=mode=='otp', can_issue_tokens=mode=='otp',
                  next='mfa_setup' if mode in ['required','recovery'] else None,
                  mfa_recovery_required=mode=='recovery', token_scopes=['read','upload','community.write'],
                  passkey_available=True, passkeys_active=0)

        def route(request):
            path = request.request.url.removeprefix(ORIGIN)
            if path == '/tbox/welcome':
                request.fulfill(content_type='text/html', body='<html><meta charset="utf-8"><body data-base="/tbox" data-member-page="welcome">'+markup('member_welcome_page')+'<script src="/assets/passkey.js"></script><script src="/assets/member.js"></script></body></html>')
            elif path.startswith('/assets/'):
                request.fulfill(content_type='text/javascript', body=(ROOT/'public'/path.lstrip('/')).read_text())
            elif path == '/tbox/api/me':
                request.fulfill(json=me)
            elif path == '/tbox/api/me/passkeys/options':
                writes.append((path,request.request.post_data_json,request.request.headers))
                request.fulfill(json={'name':'내 기기','publicKey':dict(challenge='AQID',rp={'id':'localhost','name':'Moa'},user={'id':'AQID','name':'review','displayName':'review'},pubKeyCredParams=[{'type':'public-key','alg':-7}],authenticatorSelection={'userVerification':'required','residentKey':'required'},excludeCredentials=[],timeout=120000)})
            elif path == '/tbox/api/me/passkeys':
                writes.append((path,request.request.post_data_json,request.request.headers))
                request.fulfill(status=201,json={'ok':True})
            elif path == '/tbox/api/me/mfa/enroll':
                writes.append((path,request.request.post_data_json,request.request.headers))
                request.fulfill(json={'secret':'SYNTHETIC','otpauth':'otpauth://totp/review','qr':{'rows':['10','01']}})
            else:
                request.fulfill(status=404,body='')

        page.route('**/*', route)
        page.goto(ORIGIN+'/tbox/welcome')
        expect(page.locator('#member-status')).not_to_be_empty()
        check(mode+' no automatic enrollment/token issuance', not writes)
        if mode in ['required','recovery']:
            expect(page.locator('#welcome-security-later')).to_be_hidden()
            expect(page.locator('#welcome-buddy')).to_be_hidden()
            expect(page.locator('#welcome-choose-passkey')).to_be_hidden()
            expect(page.locator('#welcome-mfa')).to_be_visible()
            check(mode+' keeps required gate', True)
        else:
            expect(page.locator('#welcome-buddy')).to_be_visible()
            expect(page.locator('#welcome-security-later')).to_be_visible()
            expect(page.locator('#welcome-choose-passkey')).to_be_visible()
            check(mode+' choices available including desktop platform', True)
            check(mode+' token form obeys server eligibility', page.locator('#welcome-token-form').evaluate('el => !el.hidden') == (mode=='otp'))
            page.locator('#welcome-choose-passkey').click()
            check(mode+' OTP field only for enrolled users', page.locator('#welcome-passkey-otp-field').is_visible() == (mode=='otp'))
            page.locator('#welcome-passkey-password').fill('Synthetic-password!')
            if mode=='otp': page.locator('#welcome-passkey-otp').fill('123456')
            if mode=='cancel': page.evaluate('window.__cancel = true')
            page.locator('#welcome-passkey-form button[type=submit]').click()
            expect(page.locator('#welcome-passkey-password')).to_have_value('')
            check(mode+' credential challenge is CSRF protected', writes[0][2].get('x-csrf-token')=='synthetic-csrf')
            check(mode+' OTP is not invented when skipped', ('otp' in writes[0][1]) == (mode=='otp'))
            check(mode+' WebAuthn user verification required', page.evaluate("window.__creates[0].uv === 'required'"))
            if mode=='cancel':
                expect(page.locator('#member-status')).to_contain_text('완료하지')
                check('cancel creates no server credential', len(writes)==1)
                page.locator('#welcome-passkey-cancel').click()
                page.locator('#welcome-choose-otp').click()
                page.locator('#welcome-enroll').click()
                expect(page.locator('#welcome-qr-area')).to_be_visible()
                check('OTP downloads use official stores', page.locator('#welcome-mfa a[href^="https://play.google.com/"]').count()==2 and page.locator('#welcome-mfa a[href^="https://apps.apple.com/"]').count()==2)
            else:
                expect(page.locator('#member-status')).to_contain_text('패스키를 등록했어요')
                check(mode+' registration completed', len(writes)==2)
            check(mode+' no credentials saved in browser storage', page.evaluate("!JSON.stringify({...localStorage,...sessionStorage}).includes('Synthetic-password!')"))
        context.close()

    context = browser.new_context(viewport={'width':1280,'height':800})
    context.add_init_script('('+MOCK+')(); localStorage.setItem("qaredi.passkey-here","1");')
    page = context.new_page()
    page.on('pageerror', lambda e: errors.append(str(e)))

    def login_route(request):
        path=request.request.url.removeprefix(ORIGIN)
        if path=='/tbox/login':
            request.fulfill(content_type='text/html',body='<html><meta charset="utf-8"><body data-base="/tbox" data-page="login">'+markup('login_page')+'<script src="/assets/passkey.js"></script></body></html>')
        elif path=='/assets/passkey.js': request.fulfill(content_type='text/javascript',body=(ROOT/'public/assets/passkey.js').read_text())
        elif path=='/tbox/api/login/passkey/options': request.fulfill(json={'publicKey':{'challenge':'AQID','rpId':'localhost','allowCredentials':[],'timeout':120000}})
        else: request.fulfill(status=404,body='')

    page.route('**/*',login_route)
    page.goto(ORIGIN+'/tbox/login')
    expect(page.locator('#passkey-login-btn')).to_be_visible()
    check('login never opens biometrics automatically despite remembered device', page.evaluate('window.__gets.length===0'))
    page.locator('#passkey-password-open').click()
    expect(page.locator('#password')).to_be_visible()
    page.locator('#passkey-login-btn').click()
    expect(page.locator('#passkey-recovery')).to_be_visible()
    check('biometric cancellation leaves password fallback', page.locator('#password').is_visible())
    check('no JavaScript errors', not errors)
    context.close()
    join_context = browser.new_context()
    page = join_context.new_page()
    page.on('pageerror', lambda e: errors.append(str(e)))
    def join_route(request):
        path = request.request.url.removeprefix(ORIGIN)
        if path == '/tbox/join':
            request.fulfill(content_type='text/html',body='<html><meta charset="utf-8"><body data-base="/tbox" data-member-page="join" data-join-csrf="synthetic">'+markup('member_join_page')+'<script src="/assets/member.js"></script></body></html>')
        elif path == '/assets/member.js': request.fulfill(content_type='text/javascript',body=(ROOT/'public/assets/member.js').read_text())
        elif path == '/tbox/api/register': request.fulfill(status=201,json={'ok':True,'next':'login','redirect':'/tbox/login'})
        elif path == '/tbox/login': request.fulfill(content_type='text/html',body='<p>login</p>')
        else: request.fulfill(status=404,body='')
    page.route('**/*',join_route)
    page.goto(ORIGIN+'/tbox/join')
    for selector,value in [('#join-invite','a'*64),('#join-name','Review'),('#join-username','synthetic_review'),('#join-password','Synthetic-password!'),('#join-confirm','Synthetic-password!')]:
        page.locator(selector).fill(value)
    # 사이트 Q:F-13 P1 — 가입 양식의 필수 체크 둘(이용약관 동의 · 만 14세 이상). 체크하지 않으면 브라우저가 제출을 막는다(서버도 400).
    page.locator('#join-terms').check(); page.locator('#join-age14').check()
    page.locator('#join-form button[type=submit]').click()
    page.wait_for_url(ORIGIN+'/tbox/login')
    check('durable signup without session goes to login', page.url.endswith('/tbox/login'))
    join_context.close()
    browser.close()
print(json.dumps({'passed': checks, 'errors': errors, 'scope':'browser with synthetic API/WebAuthn; PHP/DB unverified'}))
