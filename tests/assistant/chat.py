#!/usr/bin/env python3
"""PC chat acceptance, synthetic database and copy-only provider stub; no Google calls."""
from pathlib import Path
import json, os, subprocess, time, uuid
import requests
m=json.loads(Path(os.environ['ASSISTANT_TEST_MANIFEST']).read_text())
assert m['database'].startswith('tbox_assistant_test_')
root=Path(m['instance']); out=Path(os.environ['ASSISTANT_TEST_OUTPUT'])
u=json.loads(Path(m['credentials_file']).read_text())['users']
base=m['base_url']; checks=[]
def check(ok,name):
    checks.append({'ok':bool(ok),'name':name}); print(('PASS ' if ok else 'FAIL ')+name,flush=True)
    if not ok: raise AssertionError(name)
def fix(action,**kwargs):
    p=subprocess.run(['php','tests/assistant/chat_fixture.php'],input=json.dumps(dict(action=action,**kwargs)).encode(),cwd=root,check=True,stdout=subprocess.PIPE)
    return p.stdout.decode()
def call(method='GET',body=None,user='employee',status=200,query='',token=True,headers=None):
    h={'User-Agent':'tbox-review/'+time.strftime('%Y-%m-%d')}
    if token: h['X-API-Token']=u[user]['assistant.run']['token']
    h.update(headers or {})
    r=requests.request(method,base+'/api/assistant/chat'+query,json=body,headers=h,timeout=20,allow_redirects=False)
    check(r.status_code==status,f'{method} chat {status} got {r.status_code}')
    return r.json()
def body(text='합성 질문',thread=None): return dict(request_id=str(uuid.uuid4()),text=text,thread_id=thread)
transport=root/'src/Fetch.php'; original=transport.read_text(); needle="        $host = 'generativelanguage.googleapis.com';"
assert original.count(needle)==1
transport.write_text(original.replace(needle,"        return require __DIR__ . '/chat-test-transport.php';\n"+needle))
(root/'src/chat-test-transport.php').write_text('''<?php
if (!str_starts_with(Env::get('DB_NAME'),'tbox_assistant_test_')) throw new LogicException('Test DB required');
file_put_contents(dirname(__DIR__).'/provider-calls.txt', "call\\n", FILE_APPEND);
if (str_contains(json_encode($payload), 'BLOCK_ME')) return ['candidates'=>[['finishReason'=>'SAFETY']]];
return ['candidates'=>[['finishReason'=>'STOP','content'=>['parts'=>[['text'=>'합성 응답']]]]]];
''')
k=root.parent/'private-messenger-ai';k.mkdir(mode=0o700);(k/'gemini.key').write_text('synthetic-chat-key-no-real-provider');(k/'gemini.key').chmod(0o600)
def count():
    p=root/'provider-calls.txt'; return len(p.read_text().splitlines()) if p.exists() else 0
try:
    call(status=401,token=False)
    call(status=403,headers={'X-API-Token':u['employee']['read']['token']})
    call(status=401,token=False,headers={'Cookie':'__Host-tbox_session='+u['employee']['session']})
    call(status=403,user='guest')
    p=call();check(p['provider']=='gemini' and p['model']=='gemini-3.6-flash' and not p['ready'],'disabled status truthful')
    call('POST',body(),status=503)
    fix('enable');p=call();check(p['ready'] and p['user_id']==u['employee']['id'],'status bound to own identity')
    call(query='?thread=1',status=400);call(query='?request_id[]=bad',status=400)
    for bad in ({},dict(body(),extra=1),body(' '),body('x'*4001),body('x',0),dict(body(),request_id='invalid')):
        call('POST',bad,status=400)
    fix('consent',uid=u['employee']['id'],agree=False)
    check(call()['consent_required'],'consent visible')
    call('POST',body(),status=409);check(count()==0,'no provider before consent')
    fix('consent',uid=u['employee']['id'],agree=True);fix('reset_rate')
    first=body();p=call('POST',first);turn=p['turn']
    check(turn['state']=='complete' and turn['answer']=='합성 응답' and turn['error_code'] is None,'complete answer returned')
    check(count()==1,'single provider call')
    same=call('POST',first);check(same==p and count()==1,'UUID replay does not generate twice')
    check(call(query='?request_id='+first['request_id'])==p,'timeout recovery own UUID')
    call(query='?request_id='+first['request_id'],user='other',status=404)
    call('POST',body(thread=turn['thread_id']),user='other',status=404)
    call('POST',dict(first,text='changed'),status=409)
    fix('reset_rate');reply=call('POST',body('follow up',turn['thread_id']))
    check(reply['turn']['thread_id']==turn['thread_id'],'explicit continuation')
    fix('reset_rate');failed=call('POST',body('BLOCK_ME'))
    check(failed['turn']['state']=='failed' and failed['turn']['error_code']=='blocked','failed turn distinguished from successful HTTP')
    fix('limit');call('POST',body(),status=429)
    before=count();fix('stale',id=first['request_id']);p=call(query='?request_id='+first['request_id'])
    check(p['turn']['state']=='failed' and p['turn']['error_code']=='interrupted' and count()==before,'stale pending recovery without regeneration')
    fix('enable');fix('reset_rate')
finally:
    transport.write_text(original)
    (out/'chat-checks.json').write_text(json.dumps(checks,ensure_ascii=False,indent=2))
print('CHAT_PASS='+str(len(checks)),flush=True)
