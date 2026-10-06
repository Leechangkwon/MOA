import os,requests,subprocess,json,re,time,base64,hmac,hashlib,struct,secrets,uuid
from concurrent.futures import ThreadPoolExecutor
DB=os.environ['MOA_TEST_DB'];ROOT=os.environ['MOA_TEST_BASE'];
assert re.fullmatch(r'tbox_moa_[a-z0-9_]+',DB) and ROOT.startswith('http://localhost:'), 'isolated Moa review database and localhost are required'
PW='Moa-review-only-2026!';SECRET='JBSWY3DPEHPK3PXP';checks=[]
def sql(query):
 return subprocess.run(['sudo','-n','-u','postgres','psql','-X','-At','-v','ON_ERROR_STOP=1','-d',DB],input=query,text=True,capture_output=True,check=True).stdout.strip()
def check(name,condition):
 checks.append((name,bool(condition)));print(('PASS ' if condition else 'FAIL ')+name,flush=True)
def otp():
 count=int(time.time())//30;digest=hmac.new(base64.b32decode(SECRET),struct.pack('>Q',count),hashlib.sha1).digest();offset=digest[-1]&15
 return str((struct.unpack('>I',digest[offset:offset+4])[0]&0x7fffffff)%1000000).zfill(6)
def session():
 s=requests.Session();s.headers['User-Agent']='tbox-review/2026-10-02';return s
def req(s,path,method='GET',body=None,headers=None):
 for c in s.cookies:c.secure=False
 return s.request(method,ROOT+'/tbox'+path,json=body,headers=headers or {},timeout=20)
def login(name):
 sql("UPDATE users SET mfa_last_step=NULL,failed_count=0,locked_until=NULL WHERE username='"+name+"';")
 s=session();r=req(s,'/api/login','POST',{'username':name,'password':PW,'otp':otp()});check('login '+name,r.status_code==200);
 if r.status_code!=200: print('login status',r.status_code,r.text)
 m=req(s,'/api/me').json();s.headers['X-CSRF-Token']=m.get('csrf_token','');return s,m
hash_=subprocess.run(['php','-r','echo password_hash($argv[1],PASSWORD_ARGON2ID);',PW],text=True,capture_output=True,check=True).stdout
sql("UPDATE users SET password_hash='"+hash_+"',mfa_secret='"+SECRET+"',mfa_last_step=NULL,failed_count=0,locked_until=NULL,must_change_password=false,is_active=true WHERE username LIKE 'claude_%';")
admin,am=login('claude_admin01');emp,em=login('claude_employee01');other,om=login('claude_employee02');guest,gm=login('claude_guest01')
check('admin invitation reauthentication enforced',req(admin,'/api/invites').status_code==403)
r=req(admin,'/api/me/reauth','POST',{'password':PW});check('admin reauth',r.status_code==200)
m=req(admin,'/api/me').json();admin.headers['X-CSRF-Token']=m['csrf_token']
check('employee cannot invite',req(emp,'/api/invites','POST',{'label':'no','max_uses':1,'days':7}).status_code==403)
r=req(admin,'/api/invites','POST',{'label':'Moa isolated review','team_id':None,'max_uses':1,'days':7});check('create invitation',r.status_code==201)
if r.status_code!=201:print(r.text);raise SystemExit(1)
invite=r.json();code=invite['path'].split('=')[-1]
r=req(admin,'/api/invites');check('list has no invite code',code not in r.text and 'code_hash' not in r.text)
fresh=session();page=req(fresh,'/join');csrf=re.search('data-join-csrf="([a-f0-9]+)"',page.text).group(1)
name='moareview_'+secrets.token_hex(3);body={'invite':code,'username':name,'display_name':'모아 가입 검토','password':PW,'agree_terms':True,'age14':True}  # Q:F-13 P1 — 필수 동의 둘
check('registration CSRF rejected',req(fresh,'/api/register','POST',body).status_code==403)
fresh.headers['X-Join-CSRF']=csrf
check('role injection rejected',req(fresh,'/api/register','POST',body|{'role':'Admin'}).status_code==400)
check('weak password rejected',req(fresh,'/api/register','POST',body|{'password':'abc'}).status_code==400)
check('reserved account prefix rejected',req(fresh,'/api/register','POST',body|{'username':'claude_unknown'}).status_code==409)
check('invalid invite rejected',req(fresh,'/api/register','POST',body|{'invite':'a'*64}).status_code==410)
r=req(fresh,'/api/register','POST',body);check('self registration and session',r.status_code==201)
if r.status_code!=201:print(r.text);raise SystemExit(1)
nm=req(fresh,'/api/me').json();fresh.headers['X-CSRF-Token']=nm['csrf_token'];newid=nm['member_id']
check('fixed employee role',nm['role']=='Employee')
check('invitation OTP is optional',nm['next'] is None and not nm['mfa_required'] and not nm['mfa_enabled'])
check('files accessible without OTP',req(fresh,'/api/files').status_code==200)
check('messages accessible without OTP',req(fresh,'/api/messages').status_code==200)
check('token stays protected without OTP',req(fresh,'/api/me/api-tokens','POST',{'name':'optional auth','purpose':'isolated review','scope':'read','password':PW,'otp':'123456'}).status_code==403)
check('passkey challenge without OTP allowed',req(fresh,'/api/me/passkeys/options','POST',{'name':'test device','password':PW}).status_code==200)
check('passkey password still required',req(fresh,'/api/me/passkeys/options','POST',{'name':'test device','password':'incorrect'}).status_code==400)
check('welcome accessible before OTP',req(fresh,'/welcome').status_code==200)
r=req(fresh,'/api/me/mfa/enroll','POST',{});check('OTP enrollment',r.status_code==200);enroll=r.json();check('QR matrix available',bool(enroll.get('qr',{}).get('rows')))
SECRET=enroll['secret'];r=req(fresh,'/api/me/mfa/confirm','POST',{'otp':otp()});check('OTP confirmation',r.status_code==200)
nm=req(fresh,'/api/me').json();check('OTP gate cleared',nm['next'] is None and nm['mfa_enabled'])
check('welcome accessible after OTP',req(fresh,'/welcome').status_code==200)
check('files accessible after OTP',req(fresh,'/api/files').status_code==200)
probe=req(session(),'/api/login','POST',{'username':name,'password':PW})
check('enabled OTP still protects password login',probe.status_code==401 and probe.json().get('mfa_required') is True)
check('enabled OTP still protects additional passkey enrollment',req(fresh,'/api/me/passkeys/options','POST',{'name':'another device','password':PW}).status_code==400)
again=session();p=req(again,'/join');again.headers['X-Join-CSRF']=re.search('data-join-csrf="([a-f0-9]+)"',p.text).group(1)
check('one-use invitation exhausted',req(again,'/api/register','POST',body|{'username':name+'b'}).status_code==410)
check('guest cannot use messenger',req(guest,'/api/messages').status_code==403)
check('member list',req(emp,'/api/messages').status_code==200)
check('self message rejected',req(emp,'/api/messages/'+str(em['member_id']),'POST',{'text':'self','request_id':str(uuid.uuid4())}).status_code==400)
check('blank message rejected',req(emp,'/api/messages/'+str(om['member_id']),'POST',{'text':' ','request_id':str(uuid.uuid4())}).status_code==400)
text='안녕 <script>alert(1)</script> & <b>모아</b>'
r=req(emp,'/api/messages/'+str(om['member_id']),'POST',{'text':text,'request_id':str(uuid.uuid4())});check('message sent',r.status_code==201);mid=r.json().get('id')
r=req(other,'/api/messages/'+str(em['member_id']));data=r.json();check('recipient can read plain text',any(m['body']==text for m in data.get('messages',[])))
r=req(fresh,'/api/messages/'+str(em['member_id']));check('third party cannot see other conversation',text not in r.text)
r=req(other,'/api/messages/'+str(em['member_id']),'PATCH',{'through':mid});check('read receipt',r.status_code==200)
r=req(emp,'/api/messages/'+str(om['member_id']));check('sender sees receipt',any(m['id']==mid and m['read_at'] for m in r.json()['messages']))
r=req(emp,'/api/messages/'+str(om['member_id']),'POST',{'text':'x'*4001,'request_id':str(uuid.uuid4())});check('message length enforced',r.status_code==400)
r=req(emp,'/api/messages/'+str(om['member_id']),'POST',{'text':'x','request_id':str(uuid.uuid4())},headers={'X-CSRF-Token':'wrong'});check('message CSRF enforced',r.status_code==403)
r=req(admin,'/api/member-installer','POST',{'url':'javascript:alert(1)'});check('unsafe installer rejected',r.status_code==400)
r=req(admin,'/api/member-installer','POST',{'url':'https://example.com/buddy.exe'});check('installer saved',r.status_code==200)
check('installer displayed', 'https://example.com/buddy.exe' in req(fresh,'/welcome').text)
r=req(admin,'/api/invites','POST',{'label':'revoke review','days':1,'max_uses':1});rev=r.json();check('second invitation',r.status_code==201)
r=req(admin,'/api/invites/'+str(rev['id']),'DELETE',{});check('invitation revoked',r.status_code==200)
r=req(again,'/api/register','POST',body|{'invite':rev['path'].split('=')[-1],'username':name+'c'});check('revoked link cannot join',r.status_code==410)
sql('UPDATE users SET mfa_last_step=NULL WHERE id='+str(newid)+';')
r=req(fresh,'/api/me/api-tokens','POST',{'name':'review buddy','purpose':'isolated review','scope':'read+upload','password':PW,'otp':otp()});check('buddy token issue',r.status_code==201)
if r.status_code==201:
 token=r.json()['token'];check('token omitted in list',token not in req(fresh,'/api/me/api-tokens').text)
 check('token can identify owner',req(session(),'/api/whoami',headers={'X-API-Token':token}).status_code==200)
check('public home has no private posts','moa-feed"' not in requests.get(ROOT).text)
# Browser fixtures contain only isolated test credentials, never production secrets.
open('/tmp/moa-review/fixture.json','w').write(json.dumps({'user':name,'password':PW,'secret':SECRET,'employee':em['member_id'],'other':om['member_id'],'admin_cookies':requests.utils.dict_from_cookiejar(admin.cookies),'new_cookies':requests.utils.dict_from_cookiejar(fresh.cookies)}))
print('RESULT',sum(ok for _,ok in checks),'passed',sum(not ok for _,ok in checks),'failed')
open('/tmp/moa-review/results.json','w').write(json.dumps(checks,ensure_ascii=False,indent=2))
raise SystemExit(any(not ok for _,ok in checks))
