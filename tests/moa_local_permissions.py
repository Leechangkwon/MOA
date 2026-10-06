"""HTTP authorization regression against an explicitly supplied isolated fixture.
Never accepts the production URL/database or discovers production credentials.
"""
import json
import os
from pathlib import Path
import re
import requests
import uuid

base = os.environ['MOA_TEST_BASE']
assert re.fullmatch(r'http://(?:127\.0\.0\.1|localhost):[0-9]+', base)
fixture = json.loads(Path(os.environ['MOA_TEST_FIXTURE']).read_text())
assert re.fullmatch(r'tbox_moa_local_[a-z0-9_]+', fixture['database'])
checks=[]
def req(path, cookie=None, method='GET', body=None, headers=None):
    h={'User-Agent':'tbox-review/2026-10-02'}
    if cookie: h['Cookie']='__Host-tbox_session='+cookie
    h.update(headers or {})
    return requests.request(method,base+'/tbox'+path,headers=h,json=body,timeout=10,allow_redirects=False)
def check(name, response, status, condition=True):
    ok=response.status_code==status and condition
    checks.append({'name':name,'expected':status,'actual':response.status_code,'passed':ok})
    print(('PASS ' if ok else 'FAIL ')+name+' HTTP '+str(response.status_code),flush=True)
    assert ok,name

employee,other,third,guest,admin=fixture['cookies']
for path in ('/api/messages','/api/messages/assist?peer=2','/api/messages/ai'):
    check('unauthenticated '+path,req(path),401)
for path in ('/messages','/api/messages','/api/messages/assist?peer=1','/api/messages/ai'):
    check('guest denied '+path,req(path,guest),403)
for path in ('/api/messages','/api/messages/assist?peer=2','/api/messages/ai'):
    check('acting role denied '+path,req(path,fixture['acting_cookie']),403)
for cookie in (employee,third):
    r=req('/api/me',cookie);check('synthetic member session',r,200)
    assert r.json()['username'].startswith('claude_')
csrf=req('/api/me',employee).json()['csrf_token']
third_csrf=req('/api/me',third).json()['csrf_token']
check('real messages page includes provider choice',r:=req('/messages',employee),200,'data-assist-provider="local"' in r.text)
check('real welcome includes local guide',r:=req('/welcome',employee),200,'welcome-local-buddy' in r.text)
check('missing CSRF blocks send',req('/api/messages/2',employee,'POST',{'text':'not sent'}),403)
check('wrong CSRF blocks AI tools',req('/api/messages/assist',employee,'POST',{}, {'X-CSRF-Token':'invalid'}),403)
check('wrong CSRF blocks AI chat',req('/api/messages/ai',employee,'POST',{}, {'X-CSRF-Token':'invalid'}),403)
# Q:F-11 B-7(A 독립 검토 4) — request_id 가 유효해야 400 이 「글자 수」 규칙에서 난다(없으면 본문 키 검사에서 먼저 400).
# 본문은 브라우저처럼 UTF-8 그대로 보낸다 — requests 의 json= 은 한글을 \uXXXX(6바이트)로 써서 본문 상한(24000바이트)에 먼저 걸린다.
r=requests.post(base+'/tbox/api/messages/2',data=json.dumps({'text':'가'*4001,'request_id':str(uuid.uuid4())},ensure_ascii=False).encode(),timeout=10,allow_redirects=False,
                headers={'User-Agent':'tbox-review/2026-10-02','Cookie':'__Host-tbox_session='+employee,'X-CSRF-Token':csrf,'Content-Type':'application/json'})
check('4001 code points rejected by real message handler (length rule)',r,400,r.json().get('error')=='메시지를 1~4,000자로 입력해 주세요.')
check('sender sees synthetic own conversation',r:=req('/api/messages/2',employee),200,'Synthetic private message' in r.text)
check('third party cannot read conversation',r:=req('/api/messages/1',third),200,'Synthetic private message' not in r.text)
check('AI history belongs to requester',r:=req('/api/messages/assist?peer=2',employee),200,'Synthetic private result' in r.text)
check('third party cannot read AI history',r:=req('/api/messages/assist?peer=1&request=b8f8454b-19c6-4d79-9b4c-1803dd1e4c31',third),200,'Synthetic private result' not in r.text)
body={'request_id':str(uuid.uuid4()),'peer_id':1,'source_id':1,'action':'translate','text':'ignored','language':'ko','aspect':'1:1'}
check('third party cannot translate another conversation',req('/api/messages/assist',third,'POST',body,{'X-CSRF-Token':third_csrf}),404)
check('synthetic file token is valid for whoami',req('/api/whoami',headers={'X-API-Token':fixture['file_token']}),200)
for path in ('/api/messages','/api/messages/assist?peer=2','/api/messages/ai'):
    r=req(path,headers={'X-API-Token':fixture['file_token']})
    check('file token cannot enter '+path,r,401)
check('image path does not expose unrelated attachment',req('/api/messages/attachments/b8f8454b-19c6-4d79-9b4c-1803dd1e4c31',third),404)
result=Path(os.environ.get('MOA_TEST_AUTH_RESULTS','/tmp/moa-local-auth-results.json'))
result.write_text(json.dumps(checks,ensure_ascii=False,indent=2))
print(f'{len(checks)} checks passed; isolated real PHP/DB, synthetic users/results; no provider calls',flush=True)
