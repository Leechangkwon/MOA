#!/usr/bin/env python3
"""F-67 real HTTP authorization and actual F-65 upload; synthetic scan evidence, no production writes."""
from pathlib import Path
import base64, hashlib, hmac, io, json, os, signal, socket, struct, subprocess, time, zipfile
import requests
m=json.loads(Path(os.environ['ASSISTANT_TEST_MANIFEST']).read_text()); assert m['database'].startswith('tbox_assistant_test_')
root=Path(m['instance']); out=Path(os.environ['ASSISTANT_TEST_OUTPUT']); base=m['base_url']
auth=json.loads(Path(m['credentials_file']).read_text()); users=auth['users']; checks=[]
config=root/'.env'; oldconfig=config.read_text(); config.write_text(oldconfig.replace('SCAN_ENABLED=0','SCAN_ENABLED=1'))
def check(ok,name):
    checks.append({'ok':bool(ok),'name':name}); print(('PASS ' if ok else 'FAIL ')+name,flush=True)
    if not ok: raise AssertionError(name)
def fixture(action,**kw):
    p=subprocess.run(['php','tests/assistant/program_fixture.php'],cwd=root,input=json.dumps(dict(action=action,**kw)).encode(),stdout=subprocess.PIPE,check=True);return p.stdout.decode()
def otp():
    key=base64.b32decode(users['admin']['secret']+'='*((8-len(users['admin']['secret'])%8)%8));digest=hmac.new(key,struct.pack('>Q',int(time.time()//30)),hashlib.sha1).digest();at=digest[-1]&15
    return '%06d'%((struct.unpack('>I',digest[at:at+4])[0]&0x7fffffff)%1000000)
def call(method,path,user='employee',body=None,status=200,csrf=True,session=True,token=None,**kw):
    h={'User-Agent':'tbox-review/'+time.strftime('%Y-%m-%d')}
    if session:h['Cookie']='__Host-tbox_session='+users[user]['session']
    if csrf:h['X-CSRF-Token']=users[user]['csrf']
    if token:h['X-API-Token']=token
    r=requests.request(method,base+path,headers=h,json=body,timeout=20,allow_redirects=False,**kw)
    if r.status_code != status and r.headers.get('Content-Type','').startswith('application/json'): print('UNEXPECTED_ERROR='+str(r.json().get('error','')),flush=True)
    check(r.status_code==status,f'{method} {path.split("?")[0]} {status} got {r.status_code}');return r

def upload(version):
    z=io.BytesIO()
    with zipfile.ZipFile(z,'w') as f:f.writestr('run.cmd','@echo off\r\necho SYNTHETIC '+version+'\r\n')
    content=z.getvalue(); fixture('otp_reset',uid=users['admin']['id'])
    r=call('POST','/api/files',user='admin',status=201,data={'access_level':'admin-only','folder_id':'','exec_ack':'1','password':users['admin']['password'],'otp':otp()},files={'file':('buddy-test-'+version+'.zip',content,'application/zip')})
    return r.json()['id'],hashlib.sha256(content).hexdigest(),content

def publish(fid,sha,version='1.0.0',level='internal',team=None,**kw):
    fixture('otp_reset',uid=users['admin']['id'])
    return call('POST','/api/programs',user='admin',body=dict(file_id=fid,sha256=sha,version=version,level=level,team_id=team,ack=True,password=users['admin']['password'],otp=otp()),**kw)
try:
    call('GET','/api/programs',session=False,status=401)
    call('GET','/api/programs',session=False,token=users['admin']['read']['token'],status=401)
    r=call('GET','/programs'); check('기존 비서 업데이트를 준비하고' in r.content.decode('utf-8'),'honest empty authenticated distribution page')
    call('POST','/api/programs',body={},status=403)
    call('POST','/api/programs',user='admin',body={},csrf=False,status=403)
    call('POST','/api/programs',user='admin',body={},status=400)
    first,sha,content=upload('1')
    publish(first,sha,status=409);check(call('GET','/api/programs').json()['programs']==[],'pending scan cannot publish')
    fixture('scan',id=first,proof=False);publish(first,sha,status=409)
    fixture('scan',id=first);publish(first,'0'*64,status=409)
    publish(first,sha,status=201)
    call('POST','/api/programs',user='admin',body=dict(file_id=first,sha256=sha,version='1.0.0',level='internal',team_id=None,ack=True,password=users['admin']['password'],otp=otp()),status=400)
    rows=call('GET','/api/programs').json()['programs'];check(len(rows)==1 and rows[0]['sha256']==sha and rows[0]['version']=='1.0.0','published metadata and verified hash')
    check(call('GET','/api/programs',user='guest').json()['programs']==[],'guest cannot list internal program')
    path='/api/programs/'+first+'/download'
    call('POST',path,user='guest',body={'ack':True},status=403)
    call('POST',path,session=False,token=users['employee']['read']['token'],body={'ack':True},status=401)
    call('POST',path,csrf=False,body={'ack':True},status=403)
    call('POST',path,body={'ack':False},status=400)
    r=call('POST',path,body={'ack':True});check(r.content==content and 'attachment' in r.headers['Content-Disposition'],'authorized user gets exact ZIP without OTP')
    from playwright.sync_api import sync_playwright
    # A single-worker static/router server avoids php -S multiworker speculative-connection stalls in Chromium.
    browser_port=8498 if ':8497/' in base else 8497
    with socket.socket() as sock: assert sock.connect_ex(('127.0.0.1',browser_port))!=0, 'Browser test port busy'
    browser_server=subprocess.Popen(['php','-S','127.0.0.1:'+str(browser_port),'-t','public','tests/community/export_router.php'],cwd=root,
        env={k:v for k,v in os.environ.items() if k!='PHP_CLI_SERVER_WORKERS'},stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,start_new_session=True)
    time.sleep(.3)
    with sync_playwright() as pw:
        browser=pw.chromium.launch()
        context=browser.new_context(user_agent='tbox-review/'+time.strftime('%Y-%m-%d'),locale='ko-KR')
        context.add_cookies([{'name':'__Host-tbox_session','value':users['employee']['session'],'domain':'127.0.0.1','path':'/','secure':True,'httpOnly':True,'sameSite':'Lax'}])
        page=context.new_page(); errors=[];page.on('pageerror',lambda error:errors.append(str(error)))
        for width in (390,1280):
            page.set_viewport_size({'width':width,'height':900});page.goto('http://127.0.0.1:'+str(browser_port)+'/tbox/programs',wait_until='load')
            check(page.locator('[data-program-download]').count()==1,'download form visible '+str(width))
            check(page.evaluate('document.documentElement.scrollWidth<=innerWidth'),'program page no horizontal overflow '+str(width))
            page.screenshot(path=str(out/('programs-'+str(width)+'.png')),full_page=True)
        page.locator('[data-program-download] input').check()
        with page.expect_download() as download: page.locator('[data-program-download] button').click()
        saved=out/'synthetic-download.zip';download.value.save_as(saved)
        check(hashlib.sha256(saved.read_bytes()).hexdigest()==sha,'browser warning acknowledgment downloads exact ZIP')
        check(not errors,'program browser no JS errors');browser.close()
    os.killpg(browser_server.pid,signal.SIGTERM);browser_server.wait(timeout=5);browser_server=None

    call('GET','/api/files/'+first+'/download',session=False,token=users['employee']['read']['token'],status=403)
    call('POST','/api/files/'+first+'/exec-download',user='admin',body={'password':'unused','otp':'123456'},status=403)
    call('POST','/api/me/reauth',user='admin',body={'password':users['admin']['password']})
    call('POST','/api/files/'+first+'/share',user='admin',body={},status=400)
    second,sha2,content2=upload('2');fixture('scan',id=second);publish(second,sha2,version='2.0.0',level='team',team=auth['teams']['a'],status=201)
    check(call('GET','/api/programs').json()['programs'][0]['id']==second,'new release replaces listing')
    check(call('GET','/api/programs',user='other').json()['programs']==[],'other team gets no release metadata')
    call('POST',path,body={'ack':True},status=404)
    summary=json.loads(fixture('summary'));check(int(summary['programs'])==2 and int(summary['current'])==1,'previous bytes/records retained, one current')
    path2='/api/programs/'+second+'/download';fixture('infect',id=second)
    call('POST',path2,body={'ack':True},status=423)
    fixture('scan',id=second);fixture('corrupt',id=second)
    call('POST',path2,body={'ack':True},status=500)
finally:
    if 'browser_server' in globals() and browser_server is not None:
        os.killpg(browser_server.pid,signal.SIGTERM);browser_server.wait(timeout=5)
    config.write_text(oldconfig)
    (out/'program-checks.json').write_text(json.dumps(checks,ensure_ascii=False,indent=2))
print('PROGRAM_PASS='+str(len(checks)),flush=True)
