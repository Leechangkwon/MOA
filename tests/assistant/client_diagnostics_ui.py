import json,os,signal,socket,subprocess,time
from pathlib import Path
import requests
from playwright.sync_api import sync_playwright,TimeoutError as BrowserTimeout
m=json.loads(Path(os.environ['ASSISTANT_TEST_MANIFEST']).read_text());assert m['database'].startswith('tbox_assistant_test_')
root=Path(m['instance']);out=Path(os.environ['ASSISTANT_TEST_OUTPUT']);users=json.loads(Path(m['credentials_file']).read_text())['users'];u=users['admin'];checks=[]
def check(ok,name):
    checks.append({'ok':bool(ok),'name':name});print(('PASS ' if ok else 'FAIL ')+name,flush=True);assert ok,name
h={'Cookie':'__Host-tbox_session='+u['session'],'X-CSRF-Token':u['csrf'],'User-Agent':'tbox-review/'+time.strftime('%Y-%m-%d')}
r=requests.post(m['base_url']+'/api/me/reauth',headers=h,json={'password':u['password']},timeout=15);check(r.status_code==200,'admin normal password reauthentication')
port=8498 if ':8497/' in m['base_url'] else 8497
with socket.socket() as sock:assert sock.connect_ex(('127.0.0.1',port))!=0
server=subprocess.Popen(['php','-S','127.0.0.1:'+str(port),'-t','public','tests/community/export_router.php'],cwd=root,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,start_new_session=True,env={k:v for k,v in os.environ.items() if k!='PHP_CLI_SERVER_WORKERS'})
try:
    time.sleep(.2)
    with sync_playwright() as p:
        browser=p.chromium.launch();context=browser.new_context(user_agent=h['User-Agent'],locale='ko-KR')
        context.add_cookies([{'name':'__Host-tbox_session','value':u['session'],'domain':'127.0.0.1','path':'/','secure':True,'httpOnly':True,'sameSite':'Lax'}]);page=context.new_page();page.set_default_timeout(8000);errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
        page.add_locator_handler(page.locator('dialog.usage-guide-dialog[open]'),lambda dialog:dialog.get_by_role('button',name='나중에 볼게요',exact=True).click())
        for width in (390,1280):
            page.set_viewport_size({'width':width,'height':900});page.goto('http://127.0.0.1:'+str(port)+'/tbox/admin/client-diagnostics',wait_until='networkidle')
            dismiss=page.get_by_role('button',name='나중에 볼게요',exact=True)
            try:
                dismiss.wait_for(state='visible',timeout=2000);dismiss.click();dismiss.wait_for(state='hidden')
            except BrowserTimeout:pass
            check(page.locator('#client-diagnostics-policy').is_visible(),'policy form at '+str(width))
            check(page.evaluate('document.documentElement.scrollWidth<=innerWidth'),'no horizontal overflow at '+str(width))
            page.screenshot(path=str(out/('client-diagnostics-'+str(width)+'.png')),full_page=True)
        page.locator('input[name=retention_days]').fill('14');page.locator('#client-diagnostics-policy button').click()
        page.wait_for_function("document.querySelector('#client-diagnostics-status').textContent.length>0")
        check('저장' in page.locator('#client-diagnostics-status').inner_text(),'policy saves through real CSRF fetch')
        page.reload(wait_until='networkidle');check(page.locator('input[name=retention_days]').input_value()=='14','saved policy reads back')
        check(not errors,'no browser JavaScript errors');browser.close()
finally:
    os.killpg(server.pid,signal.SIGTERM);server.wait(timeout=5)
    (out/'client-diagnostics-ui.json').write_text(json.dumps(checks,ensure_ascii=False,indent=2))
print('CLIENT_UI_PASS='+str(len(checks)),flush=True)
