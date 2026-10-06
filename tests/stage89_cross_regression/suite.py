#!/usr/bin/env python3
"""Actual HTTP/browser semantic regression, not a claim that legacy common8 scripts passed."""
import base64, hashlib, hmac, json, os, re, struct, subprocess, sys, time, uuid
from datetime import datetime, timezone
from http.cookies import SimpleCookie
from pathlib import Path
from urllib.parse import urlparse
import requests

manifest=json.loads(Path(os.environ['FILE_RENAME_MANIFEST']).read_text())
assert manifest['database'].startswith('tbox_stage89_test_cross_')
base=manifest['base_url']; origin=base.removesuffix('/tbox')
assert urlparse(origin).hostname=='127.0.0.1' and urlparse(origin).port in (8548,8558)
root=Path(manifest['instance']);output=Path(os.environ['FILE_RENAME_OUTPUT'])
fixture=json.loads(Path(manifest['credentials_file']).read_text());users=fixture['users'];files=fixture['files']
checks=[];http=requests.Session();http.trust_env=False
ua='tbox-review/'+time.strftime('%Y-%m-%d')

def check(ok,name,**evidence):
    checks.append(dict(name=name,passed=bool(ok),**evidence))
    print(('PASS ' if ok else 'FAIL ')+name,flush=True)
    if not ok:raise AssertionError(name)

def call(method,path,body=None,user='employee',token=None,expected=None,csrf=True,form=None,upload=None):
    assert path.startswith('/') and not path.startswith('//')
    headers={'User-Agent':ua}
    if user is not None:
        headers['Cookie']='__Host-tbox_session='+users[user]['session']
        if csrf:headers['X-CSRF-Token']=users[user]['csrf']
    if token is not None:headers['X-API-Token']=token
    # The test deliberately never follows a redirect to another address.
    response=http.request(method,origin+path,headers=headers,json=body,data=form,files=upload,timeout=25,allow_redirects=False)
    if expected is not None:
        allowed={expected} if isinstance(expected,int) else set(expected)
        check(response.status_code in allowed,method+' '+path+' status',actual=response.status_code,expected=sorted(allowed))
    return response

def api(method,path,**kwargs):return call(method,'/tbox'+path,**kwargs)
def sql(query):
    env={k:v for k,v in os.environ.items() if not k.startswith('PG')};env['PGPASSFILE']=str(Path.home()/'.config/qaredi/pgpass-testdb')
    ret=subprocess.run(['psql','-h','127.0.0.1','-U','tbox_testdb','-w','-X','-At','-d',manifest['database'],'-v','ON_ERROR_STOP=1'],
        input=query.encode(),stdout=subprocess.PIPE,stderr=subprocess.PIPE,env=env,check=True)
    return ret.stdout.decode().strip()
def token(user='employee',scope='read'):return users[user][scope]['token']
def otp(user):
    secret=users[user]['mfa_secret'];key=base64.b32decode(secret+'='*((8-len(secret)%8)%8))
    digest=hmac.new(key,struct.pack('>Q',int(time.time())//30),hashlib.sha1).digest();offset=digest[-1]&15
    return str((struct.unpack('>I',digest[offset:offset+4])[0]&0x7fffffff)%1000000).zfill(6)
def err(response,label):
    body=response.json();check(set(body)=={'error'} and isinstance(body['error'],str),label+' error projection')
    return body['error']
def reason(code):
    # The response wording contract has one source; the HTTP and audit behavior are independently exercised.
    program="require 'src/Http.php'; echo Http::reasonMessage($argv[1]);"
    return subprocess.check_output(['php','-r',program,code],cwd=root,text=True)
def folder(name,level='internal',parent=None,user='employee',tok=None):
    body={'name':name,'access_level':level}
    if parent is not None:body['parent_id']=parent
    return api('POST','/api/folders',body=body,user=user,token=tok,expected=201).json()['folder']['id']

try:
    # Q launcher: anonymous disclosure, authenticated tiles, method/legacy-path handling.
    public=call('GET','/',user=None,expected=200)
    check('Set-Cookie' not in public.headers,'anonymous launcher creates no session')
    check('data-signed="no"' in public.text and 'id="moa-feed"' not in public.text and 'id="q-start-list"' not in public.text and '/assets/start.js' not in public.text,'anonymous Moa home hides member feed and recent work')
    check('/tbox/login' in public.text and 'no-store' in public.headers.get('Cache-Control',''),'anonymous launcher login and no-store')
    call('POST','/',user=None,expected=405)
    for path in ['/api/health?x=1','/login','/settings']:
        res=call('GET',path,user=None,expected=308)
        check(res.headers.get('Location')=='/tbox'+path,'legacy path preserves base and query '+path)
    css=call('GET','/tbox/assets/app.css',user=None,expected=200)
    check('text/css' in css.headers.get('Content-Type',''),'test static mapping serves app CSS')
    mark=int(sql('SELECT COALESCE(max(id),0) FROM audit_logs'))
    old=call('POST','/api/login',body={'username':'never-executed','password':'synthetic-only'},user=None,expected=308)
    check(sql(f'SELECT count(*) FROM audit_logs WHERE id>{mark}')=='0' and 'Set-Cookie' not in old.headers,'legacy POST redirects without authentication side effects')
    call('GET','/community/api/pages',user=None,expected=401)
    call('GET','/tbox/settings',user=None,expected=302)
    api('GET','/api/me',user=None,expected=401)

    # Password + TOTP use synthetic account secrets only, and prove real login cookies.
    guest=users['guest']
    first=api('POST','/api/login',user=None,body={'username':guest['username'],'password':guest['password']},expected=401)
    check(first.json().get('mfa_required') is True and 'Set-Cookie' not in first.headers,'password-only login requires TOTP and creates no session')
    logged=api('POST','/api/login',user=None,body={'username':guest['username'],'password':guest['password'],'otp':otp('guest')},expected=200)
    cookie=logged.headers.get('Set-Cookie',''); parsed=SimpleCookie();parsed.load(cookie)
    check('__Host-tbox_session' in parsed and all(x in cookie.lower() for x in ['path=/','secure','httponly','samesite=lax']) and 'domain=' not in cookie.lower(),'real login __Host cookie attributes')
    guest['session']=parsed['__Host-tbox_session'].value
    guest['csrf']=api('GET','/api/me',user='guest',expected=200).json()['csrf_token']
    for key,role in [('admin','Admin'),('manager','Manager'),('employee','Employee'),('other','Employee'),('guest','Guest')]:
        me=api('GET','/api/me',user=key,expected=200).json()
        check(me['role']==role and me['can_admin']==(key=='admin'),'session role/capability '+key)
    page=call('GET','/',expected=200)
    check('href="/tbox/"' in page.text and 'href="/community/"' in page.text,'authenticated launcher application links')
    check('no-store' in page.headers.get('Cache-Control',''),'authenticated launcher private no-store')

    # API/session separation and whoami projection.
    missing=api('GET','/api/whoami',expected=401)
    check(err(missing,'token required')=='X-API-Token 헤더가 없습니다','cookie never replaces whoami token')
    api('GET','/api/whoami',token='invalid-synthetic-token',expected=401)
    api('GET','/api/users',user=None,token=token(),expected=401)
    who=api('GET','/api/whoami',user=None,token=token(),expected=200).json()
    check(set(who)=={'username','role','team_id','upload_levels','token_scope','token_scopes_held'},'whoami fixed minimal projection including explicit token scope')
    me=api('GET','/api/me',expected=200).json()
    check(who['username']==users['employee']['username'] and who['upload_levels']==me['upload_levels'],'token and session upload capabilities agree')
    api('GET','/api/files',user=None,token=token(scope='community.write'),expected=403)
    api('GET','/api/files',user=None,token=token(scope='session.open'),expected=403)

    # Token listing/download filters, injection, and actual returned file bytes.
    listed=api('GET',f"/api/files?limit=100&folder={fixture['folders']['a']['id']}",user=None,token=token(),expected=200).json()
    check(isinstance(listed,list) and all(set(x)=={'id','name','level','size','uploaded_at','exec_content'} for x in listed),'file API minimal projection')
    ids={x['id'] for x in listed}
    check(files['main']['id'] in ids and files['admin']['id'] not in ids and files['hidden']['id'] not in ids,'file API filters admin-only and other team')
    for q in ["%27%20OR%201%3D1--",'%25','%23']:
        res=api('GET','/api/files?q='+q,user=None,token=token(),expected=200)
        check(files['admin']['id'] not in res.text and 'SQLSTATE' not in res.text,'search injection does not widen visibility '+q)
    for limit in ['abc','9999']:
        res=api('GET','/api/files?limit='+limit,user=None,token=token(),expected=200)
        check(len(res.json())<=100,'invalid list limit bounded '+limit)
    raw=api('GET','/api/files/'+files['main']['id']+'/download',user=None,token=token(),expected=200)
    check(raw.content==b'SYNTHETIC FIXTURE ONLY\n' and 'attachment;' in raw.headers.get('Content-Disposition',''),'download returns exact synthetic bytes and attachment')
    forbidden=api('GET','/api/files/'+files['admin']['id']+'/download',user=None,token=token(),expected=403)
    check(err(forbidden,'forbidden download')=='권한이 없습니다','hidden download uniform denial')
    api('GET','/api/files/'+str(uuid.uuid4())+'/download',user=None,token=token(),expected=404)
    api('GET','/api/files/not-a-uuid/download',user=None,token=token(),expected=404)
    api('DELETE','/api/files/'+files['main']['id'],user=None,token=token(),expected=403)

    # Folder hierarchy and CSRF, API upload/move/delete; all resources are disposable.
    before=api('GET','/api/folders',expected=200).json()
    same=api('GET','/api/folders',user=None,token=token(),expected=200).json()
    check(before==same,'folder token and session projections agree')
    api('POST','/api/folders',body={'name':'No-CSRF','access_level':'internal'},csrf=False,expected=403)
    api('POST','/api/folders',body={'name':'Read-cannot-create','access_level':'internal'},user=None,token=token(),expected=403)
    a=folder('Cross-parent',tok=token(scope='read+upload'),user=None)
    b=folder('Cross-child',parent=a,tok=token(scope='read+upload'),user=None)
    api('POST','/api/folders',body={'name':'Cross-parent','access_level':'internal'},user=None,token=token(scope='read+upload'),expected=409)
    cyc=api('PATCH',f'/api/folders/{a}',body={'parent_id':b},user=None,token=token(scope='read+upload'),expected=400)
    check(err(cyc,'cycle')==reason('cycle'),'F68 cycle human reason')
    check(sql("SELECT reason FROM audit_logs WHERE action='folder.update' ORDER BY id DESC LIMIT 1")=='cycle','cycle audit retains machine reason')
    api('PATCH',f'/api/folders/{a}',body={'name':'Moved','cascade':False},user=None,token=token(scope='read+upload'),expected=400)
    api('PATCH',f'/api/folders/{b}',body={'name':'Cross-renamed'},user=None,token=token(scope='read+upload'),expected=200)
    api('DELETE',f'/api/folders/{a}',expected=400)
    api('DELETE',f'/api/folders/{b}',expected=200)
    api('DELETE',f'/api/folders/{a}',expected=200)
    api('DELETE',f"/api/folders/{fixture['folders']['a']['id']}",expected=400)
    upload=api('POST','/api/upload',user=None,token=token(scope='read+upload'),form={'level':'internal'},upload={'file':('cross-upload.txt',b'cross-upload-bytes\n','text/plain')},expected=201).json()
    uid=upload['id'];destination=folder('Cross-move')
    api('PATCH','/api/files/'+uid,user=None,token=token(scope='read+upload'),body={'folder_id':destination},expected=200)
    check(uid in api('GET',f'/api/files?folder={destination}',expected=200).text,'moved file appears under destination')
    api('DELETE','/api/files/'+uid,user=None,token=token(scope='read+upload'),expected=403)
    api('DELETE','/api/files/'+uid,user=None,token=token(scope='read+upload+delete'),expected=200)
    api('GET','/api/files/'+uid+'/download',user=None,token=token(),expected=404)

    # Stage26 management and role-view contracts; no operating role rows are touched.
    api('POST','/api/me/act',body={'role':'Employee','team_id':users['employee']['team_id']},user='admin',expected=403)
    api('POST','/api/me/act',body={'role':'Guest'},expected=403)
    call('GET','/tbox/admin',user='admin',expected=302)
    call('GET','/tbox/admin',user='employee',expected=(302,403))
    api('POST','/api/me/reauth',body={'password':users['admin']['password']},user='admin',expected=200)
    call('GET','/tbox/admin',user='admin',expected=200)
    api('POST','/api/me/act',body={'role':'Admin'},user='admin',expected=400)
    api('POST','/api/me/act',body={'role':'Employee','team_id':users['employee']['team_id']},user='admin',expected=200)
    acting=api('GET','/api/me',user='admin',expected=200).json()
    check(acting['acting'] and acting['role']=='Employee' and acting['real_role']=='Admin' and not acting['can_admin'] and acting['can_stop_acting'],'role view uses effective role and preserves true actor')
    check(files['admin']['id'] not in api('GET',f"/api/files?folder={fixture['folders']['a']['id']}",user='admin',expected=200).text,'acting admin cannot list admin-only file')
    api('DELETE','/api/files/'+files['manager']['id'],user='admin',expected=403)
    actingfolder=folder('Cross-acting',user='admin')
    row=sql("SELECT actor_role||'|'||(detail->'acting_as'->>'role') FROM audit_logs WHERE action='folder.create' ORDER BY id DESC LIMIT 1")
    check(row=='Admin|Employee','acting audit records real and effective role')
    api('DELETE','/api/me/act',user='admin',expected=200)
    check(api('GET','/api/me',user='admin',expected=200).json()['role']=='Admin','stop acting restores Admin')
    api('POST','/api/me/act',body={'role':'Employee','team_id':users['employee']['team_id']},user='admin',expected=200)
    sid=hashlib.sha256(users['admin']['session'].encode()).hexdigest()
    sql(f"UPDATE sessions SET act_last_seen_at=now()-interval '31 minutes' WHERE id='{sid}'")
    check(not api('GET','/api/me',user='admin',expected=200).json()['acting'],'idle role view expires using current clock')
    check(sql("SELECT detail->>'reason' FROM audit_logs WHERE action='act.expire' ORDER BY id DESC LIMIT 1")=='idle','idle expiry audited')

    # A PC-token session must reauthenticate before changing private sharing.
    esid=hashlib.sha256(users['employee']['session'].encode()).hexdigest()
    link_token=users['employee']['session.open']['id']
    sql(f"UPDATE sessions SET origin='token',token_id={int(link_token)},reauth_at=NULL WHERE id='{esid}'")
    private_routes=[('POST',f"/api/files/{files['main']['id']}/grants"),
                    ('DELETE',f"/api/files/{files['main']['id']}/grants/999999"),
                    ('POST',f"/api/folders/{fixture['folders']['a']['id']}/grants"),
                    ('DELETE',f"/api/folders/{fixture['folders']['a']['id']}/grants/999999"),
                    ('POST','/api/access-requests/999999')]
    for method,path in private_routes:
        denied=api(method,path,body={},expected=403)
        check(denied.json().get('next')=='reauth','private sharing requires PC-token session reauthentication '+method+' '+path)
    check(sql('SELECT count(*) FROM access_grants')=='0','blocked token sessions created no private grants')
    sql(f"UPDATE sessions SET origin='login',token_id=NULL,reauth_at=NULL WHERE id='{esid}'")

    # F66 real issuance+OTP reuse, permissions, redaction and revocation.
    api('DELETE',f"/api/me/api-tokens/{users['employee']['session.open']['id']}",expected=200)
    payload={'name':'Cross-new-token','purpose':'Synthetic regression only','scope':'read','password':users['employee']['password'],'otp':otp('employee')}
    issued=api('POST','/api/me/api-tokens',body=payload,expected=201).json()
    issued_token=issued['token'];tokens=api('GET','/api/me/api-tokens',expected=200).json()['tokens']
    record=next(t for t in tokens if t['name']=='Cross-new-token')
    check(re.fullmatch(r'[A-Za-z0-9]{48}',issued_token) is not None and record['hint']==issued_token[:6],'issued token random shape and hint')
    expiry_text=re.sub(r'([+-]\d{2})$',r'\1:00',record['expires_at'].replace('Z','+00:00'))
    expiry=datetime.fromisoformat(expiry_text)
    check(abs((expiry-datetime.now(timezone.utc)).total_seconds()-180*86400)<90,'issued token expires in 180 days')
    check(all('token' not in t and 'token_hash' not in t for t in tokens),'self token list never exposes token/hash')
    retry=api('POST','/api/me/api-tokens',body=payload,expected=400)
    check(err(retry,'OTP reuse')=='잘못된 요청입니다','OTP reuse response remains uniform')
    check(sql("SELECT reason FROM audit_logs WHERE action='api_token.issue' ORDER BY id DESC LIMIT 1")=='code_reused','OTP reuse preserved in audit only')
    api('GET','/api/whoami',user=None,token=issued_token,expected=200)
    api('DELETE',f"/api/me/api-tokens/{record['id']}",user='other',expected=404)
    api('DELETE',f"/api/me/api-tokens/{record['id']}",expected=200)
    api('GET','/api/whoami',user=None,token=issued_token,expected=401)
    api('POST',f"/api/users/{users['employee']['id']}/api-tokens",body={},user='admin',expected=405)
    bad=api('PATCH','/api/files/'+files['main']['id'],body={'description':'x'*501},expected=400)
    check(err(bad,'long description')==reason('description_too_long'),'F68 description reason')
    bad=api('PATCH','/api/files/'+files['main']['id'],body={'unknown':'x'},expected=400)
    check(err(bad,'unknown field')=='잘못된 요청입니다','unknown field generic reason')
    audit=sql('SELECT COALESCE(json_agg(a)::text,\'[]\') FROM audit_logs a')
    check(all(secret not in audit for secret in [issued_token,payload['password'],payload['otp']]),'audit contains no issued token/password/OTP')

    # Actual help UI on current source. PHP test router substitutes only static path serving.
    from playwright.sync_api import sync_playwright, expect
    with sync_playwright() as playwright:
        browser=playwright.chromium.launch()
        ctx=browser.new_context(viewport={'width':1280,'height':900},user_agent=ua)
        ctx.add_cookies([{'name':'__Host-tbox_session','value':users['employee']['session'],'domain':'127.0.0.1','path':'/','secure':True,'httpOnly':True,'sameSite':'Lax'}])
        page=ctx.new_page();errors=[];page.on('pageerror',lambda error:errors.append(str(error)))
        # Q:F-12 C — 옛 「오늘」(/community/today)은 308 /community/my-tasks 로 넘긴다(화면은 D). 도움말 화면은 이야기 셸(/community/memories)로.
        moved=call('GET','/community/today',expected=308);check(moved.headers.get('Location')=='/community/my-tasks','Q:F-12 /community/today 308 Location /community/my-tasks')
        for route in ['/tbox/','/tbox/settings','/community/','/community/memories']:
            response=page.goto(origin+route);check(response.status==200,'browser route '+route)
            if route == '/community/':
                page.locator('#overview-welcome [data-usage-guide-open]').click()
                guide=page.locator('.usage-guide-dialog');expect(guide).to_be_visible()
                check(len(guide.inner_text().strip())>25,'visible overview guided help text')
                with page.expect_response(lambda r: r.url.endswith('/community/api/me/guide') and r.request.method=='PATCH'):
                    page.keyboard.press('Escape')
                expect(guide).to_have_count(0)
                state=call('GET','/community/api/me/guide',expected=200).json()
                check(state['guide']['status']=='dismissed','overview guide dismissal persists for member')
                continue
            opener=page.locator('[data-help-open]');expect(opener).to_have_count(1);opener.click()
            dialog=page.locator('#help-dialog');expect(dialog).to_be_visible()
            check(len(dialog.inner_text().strip())>25,'visible help text '+route)
            page.keyboard.press('Escape');expect(dialog).not_to_be_visible()
        page.goto(origin+'/community/memories');page.locator('[data-help-open]').click()
        page.screenshot(path=str(output/'help-threads-1280-light.png'),full_page=True)
        page.keyboard.press('Escape');page.set_viewport_size({'width':390,'height':844})
        page.evaluate("document.documentElement.setAttribute('data-theme','dark')")
        page.locator('[data-help-open]').click();page.screenshot(path=str(output/'help-threads-390-dark.png'),full_page=True)
        check(page.evaluate('document.documentElement.scrollWidth <= innerWidth'),'390px help has no page overflow')
        check(not errors,'current source browser has no uncaught JS errors',error_count=len(errors))
        browser.close()
    api('GET','/api/health',user=None,expected=200)
finally:
    if sys.exc_info()[0] is not None and (not checks or checks[-1]['passed']):
        checks.append({'name':'suite completed without unexpected exception','passed':False,'error_type':sys.exc_info()[0].__name__})
    summary={'passed':sum(x['passed'] for x in checks),'failed':sum(not x['passed'] for x in checks),'checks':checks,
      'scope':'Synthetic actual HTTP/browser semantic adapter; legacy common8 scripts were not executed',
      'limitations':['Operational nginx/FPM/TLS/rate-limit and old static asset redirects not exercised','Legacy stage26 108 and help hardcoded count13 are not claimed','F66 full expiry/cap/failed-auth matrix not covered','F68 all15 reason branches, executable and macro upload vectors not covered','Original scripts with production DB/secret paths were never run']}
    (output/'results.json').write_text(json.dumps(summary,ensure_ascii=False,indent=2)+'\n')
    print(f"PASS={summary['passed']} FAIL={summary['failed']} LEGACY_COMMON8_PASSED=not_claimed",flush=True)
