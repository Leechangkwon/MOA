#!/usr/bin/env python3
"""Real router/auth/CSRF/SQL and legacy download in an empty harness-owned DB."""
from pathlib import Path
from copy import deepcopy
exec(compile(Path(__file__).with_name('memories_http.py').read_text().split('try:\n for u in users:')[0],__file__,'exec'))
base=site+'/tbox'
def v2(settings):
 upgraded=deepcopy(settings);upgraded['layout']['version']=2
 for i,widget in enumerate(upgraded['layout']['widgets']):
  widget.pop('size',None)
  widget['desktop']={'x':127,'y':i*601,'w':743,'h':577}
  widget['mobile']={'x':13,'y':i*927,'w':317,'h':913}
 return upgraded
def dashboards():
 return (sql('SELECT row_to_json(d)::text FROM product_space_dashboards d ORDER BY space_id'),sql('SELECT row_to_json(d)::text FROM product_personal_dashboards d ORDER BY user_id'),sql("SELECT count(*) FROM audit_logs WHERE action='product.dashboard.save' AND result='allow'"))
try:
 page=raw('GET','/spaces')
 check(page.status_code==200 and 'id="space-app"' in page.text and 'space-page.js' in page.text,'authenticated real space page renders application')
 anonymous=requests.get(base+'/spaces',headers={'User-Agent':'tbox-review/'+time.strftime('%Y-%m-%d')},allow_redirects=False,timeout=35)
 check(anonymous.status_code==302 and anonymous.headers.get('Location','').startswith('/tbox/login'),'anonymous space page redirects to login')
 call('GET','/api/product-spaces',user=None,expected=401)
 call('POST','/api/product-spaces',{'name':'x','kind':'group'},csrf=False,expected=403)
 call('POST','/api/product-spaces',{'name':'x','kind':'group'},user='guest',expected=403)
 check(not call('GET','/api/product-spaces')['spaces'],'legacy files/team do not create product memberships')
 before=sql('SELECT count(*) FROM product_space_dashboards')
 s=call('POST','/api/product-spaces',{'name':'회사','kind':'company'},expected=201)['space'];sid=s['id'];path='/api/product-spaces/'+sid
 call('GET',path,user='admin',expected=404)
 call('GET',path+'/home',user='other',expected=404)
 check(sql('SELECT count(*) FROM product_space_dashboards')==before,'space GET no default dashboard write')
 check(call('GET',path+'/dashboard')['layout']['widgets'][0]['id']=='notice','company initial home starts notice')
 untouched=dashboards();call('GET','/api/me/home-dashboard');call('GET',path+'/dashboard');check(dashboards()==untouched,'fresh personal/shared dashboard GET creates no rows or audit')
 g=call('POST','/api/product-spaces',{'name':'모임','kind':'group'},user='other',expected=201)['space']['id']
 check(call('GET','/api/product-spaces/'+g+'/dashboard',user='other')['layout']['widgets'][0]['id']=='event','gathering home starts schedule')
 invite=call('POST',path+'/invites',{'role':'editor','max_uses':1},expected=201)
 join=call('POST','/api/product-spaces/join',{'token':invite['token']},user='manager')['space'];check(join['role']=='editor','hashed invitation adds second space role')
 call('POST','/api/product-spaces/join',{'token':invite['token']},user='other',expected=404)
 task=call('POST',path+'/items',{'kind':'task','title':'실제 작업','due_at':'2026-11-01','assignee_id':users['manager']['id']},expected=201)['item']
 edit=call('PATCH',path+'/items/'+task['id'],{'revision':task['revision'],'status':'doing'},user='manager')['item'];check(edit['status']=='doing','scheduled task partial patch retains valid ISO date')
 event=call('POST',path+'/items',{'kind':'event','title':'일정','starts_at':'2026-11-01T19:00','ends_at':'2026-11-01T20:00'},expected=201)['item']
 call('PATCH',path+'/items/'+event['id'],{'revision':event['revision'],'title':'수정된 일정'})
 call('POST',path+'/items',{'kind':'notice','title':'안내','body':'<img src=x> plain'},expected=201)
 story=call('POST',path+'/items',{'kind':'story','title':'활동 후기','body':'공간 안에서 기록'},user='manager',expected=201)['item']
 home=call('GET',path+'/home');check(len(home['widgets']['task'])==1 and len(home['widgets']['event'])==1 and len(home['widgets']['story'])==1,'widgets come from persisted scoped records')
 # Explicit v1 saves still work; GET must not upgrade the row automatically.
 settings=call('GET',path+'/dashboard');settings['layout']['widgets'][0]['size']='small'
 settings=call('PATCH',path+'/dashboard',settings,user='manager');untouched=dashboards()
 check(call('GET',path+'/dashboard')==settings and settings['layout']['version']==1,'legacy shared v1 roundtrip retained')
 check(dashboards()==untouched,'legacy shared GET leaves layout revision timestamp and audit unchanged')
 # Two listeners race on a shared v2 revision: one wins, one conflicts.
 settings=v2(settings);settings['layout']['widgets'][0]['visible']=False
 settings['layout']['widgets'][0]['desktop']={'x':960,'y':19840,'w':240,'h':160}
 settings['layout']['widgets'][0]['mobile']={'x':0,'y':18000,'w':360,'h':2000}
 with ThreadPoolExecutor(max_workers=2) as pool:
  first_port=int(site.rsplit(':',1)[1]);h={'User-Agent':'tbox-review/'+time.strftime('%Y-%m-%d'),'Cookie':'__Host-tbox_session='+users['employee']['session'],'X-CSRF-Token':users['employee']['csrf']}
  results=list(pool.map(lambda port:requests.patch(base.replace(':'+str(first_port),':'+str(port))+path+'/dashboard',json=settings,headers=h,timeout=35).status_code,[first_port,first_port+1]))
 check(sorted(results)==[200,409],'concurrent shared v2 home save exactly one winner')
 shared=call('GET',path+'/dashboard');check(shared['layout']==settings['layout'] and shared['revision']==settings['revision']+1,'shared v2 exact edge coordinates and hidden widget survive reload')
 personal=call('GET','/api/me/home-dashboard');personal['layout']['source']='files';call('PATCH','/api/me/home-dashboard',personal);check(call('GET','/api/me/home-dashboard')['layout']['source']=='files','server personal configuration sync')
 check(call('GET','/api/me/home-dashboard',user='other')['layout']['source']=='all','personal sync not cross-account')
 call('PATCH','/api/me/home-dashboard',personal,expected=409)
 personal=v2(call('GET','/api/me/home-dashboard'));personal['layout']['source']='pages';personal['layout']['view']='favorites'
 savedpersonal=call('PATCH','/api/me/home-dashboard',personal)
 check(savedpersonal['layout']==personal['layout'] and savedpersonal['revision']==personal['revision']+1,'personal v2 arbitrary coordinates and source/view persisted')
 untouched=dashboards();check(call('GET','/api/me/home-dashboard')==savedpersonal,'personal v2 exact reload');call('GET',path+'/dashboard');call('GET',path+'/home');call('GET','/api/me/product-home')
 check(dashboards()==untouched,'v2 dashboard and home GET preserve all rows revision timestamps and audit')
 # Malformed coordinate objects are rejected by the actual JSON/router path too.
 for endpoint,current in [(path+'/dashboard',shared),('/api/me/home-dashboard',savedpersonal)]:
  call('PATCH',endpoint,current,csrf=False,expected=403)
  call('PATCH',endpoint,current,user=None,expected=401)
  for screen in ['desktop','mobile']:
   for coordinate in ['x','y','w','h']:
    for badvalue in [0.0,'160',True,None,9223372036854775808]:
     bad=deepcopy(current);bad['layout']['widgets'][0][screen][coordinate]=badvalue
     call('PATCH',endpoint,bad,expected=400)
   bad=deepcopy(current);del bad['layout']['widgets'][0][screen]['h'];call('PATCH',endpoint,bad,expected=400)
   bad=deepcopy(current);bad['layout']['widgets'][0][screen]['z']=1;call('PATCH',endpoint,bad,expected=400)
   bad=deepcopy(current);bad['layout']['widgets'][0][screen]={'x':0,'y':19841,'w':240 if screen=='desktop' else 160,'h':160};call('PATCH',endpoint,bad,expected=400)
  bad=deepcopy(current);bad['layout']['widgets'][0]['id']=bad['layout']['widgets'][1]['id'];call('PATCH',endpoint,bad,expected=400)
  bad=deepcopy(current);bad['layout']['widgets'].pop();call('PATCH',endpoint,bad,expected=400)
  bad=deepcopy(current);bad['layout']['widgets'][0]['style']='position:fixed';call('PATCH',endpoint,bad,expected=400)
  bad=deepcopy(current);bad['layout']['widgets'][0]['id']='unknown';call('PATCH',endpoint,bad,expected=400)
  bad=deepcopy(current);bad['layout']['owner_user_id']=users['other']['id'];call('PATCH',endpoint,bad,expected=400)
  bad=deepcopy(current);bad['layout']['source']='x'*8193;call('PATCH',endpoint,bad,expected=400)
  bad=deepcopy(current);bad['revision']-=1;call('PATCH',endpoint,bad,expected=409)
 check(dashboards()==untouched,'invalid and denied HTTP saves preserve all dashboard rows and success audit')
 call('PATCH',path+'/dashboard',shared,user='admin',expected=404)
 viewerinvite=call('POST',path+'/invites',{'role':'viewer','max_uses':1},expected=201);call('POST','/api/product-spaces/join',{'token':viewerinvite['token']},user='other')
 call('PATCH',path+'/dashboard',shared,user='other',expected=403)
 guest=v2(call('GET','/api/me/home-dashboard',user='guest'));guestsaved=call('PATCH','/api/me/home-dashboard',guest,user='guest')
 check(guestsaved['layout']==guest['layout'],'guest can save own v2 preferences')
 check(call('GET','/api/me/home-dashboard',user='other')['revision']==0,'guest and employee v2 writes remain account scoped')
 f=json.loads(Path(manifest['credentials_file']).read_text())['files']['main']['id']
 link=call('POST',path+'/items',{'kind':'file','title':'함께 보는 자료','file_id':f},expected=201)['item']
 download=raw('GET',path+'/items/'+link['id']+'/download');check(download.status_code==200 and download.content==b'SYNTHETIC FIXTURE ONLY\n','gated download reuses actual storage hash/integrity handler')
 # Rights revoked at the original file, no metadata/byte leak through an old link.
 sql("UPDATE files SET access_level='private' WHERE public_id='"+f+"'")
 check(not call('GET',path+'/items?kind=file',user='manager')['items'],'revoked file original rights hide linked title')
 call('GET',path+'/items/'+link['id'],user='manager',expected=404);call('GET',path+'/items/'+link['id']+'/download',user='manager',expected=404)
 call('GET',path+'/items/'+link['id']+'/download',user='other',expected=404)

 # Independent race space, preserving the transfer target test in the original company.
 race=call('POST','/api/product-spaces',{'name':'동시성 모임','kind':'group'},expected=201)['space']['id'];racepath='/api/product-spaces/'+race
 inv=call('POST',racepath+'/invites',{'role':'member','max_uses':1},expected=201)
 first_port=int(site.rsplit(':',1)[1])
 def parallel(method,path,body,user,port):
  h={'User-Agent':'tbox-review/'+time.strftime('%Y-%m-%d'),'Cookie':'__Host-tbox_session='+users[user]['session'],'X-CSRF-Token':users[user]['csrf']}
  return requests.request(method,base.replace(':'+str(first_port),':'+str(port))+path,json=body,headers=h,timeout=35).status_code
 with ThreadPoolExecutor(max_workers=2) as pool:
  futures=[pool.submit(parallel,'POST','/api/product-spaces/join',{'token':inv['token']},user,port) for user,port in [('other',first_port),('admin',first_port+1)]]
  check(sorted(f.result() for f in futures)==[200,404],'concurrent one-use invite exactly one new membership')
 check(sql("SELECT uses FROM product_space_invites WHERE id='"+inv['invite']['id']+"'")=='1','concurrent invite count never exceeds max')
 sql("INSERT INTO product_space_members(space_id,user_id,role) VALUES('"+race+"',"+str(users['manager']['id'])+",'owner')")
 with ThreadPoolExecutor(max_workers=2) as pool:
  futures=[pool.submit(parallel,'DELETE',racepath+'/members/'+str(users[user]['id']),None,user,port) for user,port in [('employee',first_port),('manager',first_port+1)]]
  check(sorted(f.result() for f in futures)==[200,409],'concurrent two-owner leave preserves exactly one owner')
 check(sql("SELECT count(*) FROM product_space_members WHERE space_id='"+race+"' AND active AND role='owner'")=='1','last owner survives transaction race')
 # Mandatory first-login and consent gates must execute on new APIs, not only UI.
 sql('UPDATE users SET must_change_password=true WHERE id='+str(users['manager']['id']))
 call('GET',path+'/home',user='manager',expected=403);sql('UPDATE users SET must_change_password=false WHERE id='+str(users['manager']['id']))
 sql('UPDATE users SET is_active=false WHERE id='+str(users['manager']['id']))
 call('GET',path+'/home',user='manager',expected=401);sql('UPDATE users SET is_active=true WHERE id='+str(users['manager']['id']))
 sql("INSERT INTO user_consents(user_id,doc,version,action,ip) SELECT user_id,doc,'2000-01-01','agree','127.0.0.1' FROM user_consents WHERE user_id="+str(users['other']['id'])+" AND doc='terms' ORDER BY id DESC LIMIT 1")
 call('GET','/api/product-spaces/'+g+'/home',user='other',expected=403)
 consentpage=requests.get(base+'/spaces',headers={'User-Agent':'tbox-review/'+time.strftime('%Y-%m-%d'),'Cookie':'__Host-tbox_session='+users['other']['session']},allow_redirects=False,timeout=35)
 check(consentpage.status_code==302 and '/tbox/consent' in consentpage.headers.get('Location',''),'space page enforces consent before rendering')
 # Fresh session after disabling intentionally invalidates old sessions; owner unaffected.
 call('DELETE',path+'/members/'+str(users['employee']['id']),expected=409)
 transfer=call('POST',path+'/transfer',{'user_id':users['other']['id'],'password':'not-the-password'},expected=403)
 check(sql("SELECT count(*) FROM audit_logs WHERE action LIKE 'product.%' AND result='allow'")!='0','actual mutation audit written')
 check(sql("SELECT count(*) FROM audit_logs WHERE detail::text LIKE '%"+invite['token']+"%'")=='0','token absent from audit details')
 call('PATCH',path,{'status':'archived'});check(call('GET',path)['space']['status']=='archived','archive retains accessible records')
 call('PATCH',path+'/dashboard',shared,expected=403)
 call('POST',path+'/items',{'kind':'story','title':'no'},expected=403);call('PATCH',path,{'status':'active'})
 call('DELETE',path+'/items/'+story['id']);check(not call('GET',path+'/items?kind=story')['items'],'logical content delete')
 # Actual password and one-time OTP helpers, not test doubles.
 import hmac,struct
 ph=subprocess.check_output(['php','-r','echo password_hash("synthetic-password", PASSWORD_ARGON2ID);'],text=True)
 sql("UPDATE users SET password_hash='"+ph+"',mfa_last_step=NULL WHERE id="+str(users['employee']['id']))
 secret=sql('SELECT mfa_secret FROM users WHERE id='+str(users['employee']['id']))
 def otp(at):
  digest=hmac.new(base64.b32decode(secret+'='*((8-len(secret)%8)%8)),struct.pack('>Q',int(at)//30),hashlib.sha1).digest();offset=digest[-1]&15
  return str((struct.unpack('>I',digest[offset:offset+4])[0]&0x7fffffff)%1000000).zfill(6)
 code=otp(time.time())
 call('POST',path+'/transfer',{'user_id':users['admin']['id'],'password':'synthetic-password','otp':code},expected=400)
 call('POST',path+'/transfer',{'user_id':users['manager']['id'],'password':'synthetic-password','otp':code},expected=403)
 check(int(sql('SELECT failed_count FROM users WHERE id='+str(users['employee']['id'])))>=1,'OTP reuse counts authentication failure')
 time.sleep(31-(time.time()%30))
 moved=call('POST',path+'/transfer',{'user_id':users['manager']['id'],'password':'synthetic-password','otp':otp(time.time())})
 check(moved['space']['role']=='admin','successful real password+freshOTP transfer demotes original owner')
 check(sql("SELECT role FROM product_space_members WHERE space_id='"+sid+"' AND user_id="+str(users['manager']['id']))=='owner','target owner persisted after credential-confirmed transfer')
 check(sql("SELECT count(*) FROM audit_logs WHERE action='product.request' AND result='deny'")!='0','actual denied request audit written')
 check(sql("SELECT count(*) FROM audit_logs WHERE action='product.owner.transfer' AND detail->>'to_user'='"+str(users['manager']['id'])+"' AND detail->>'role_after'='admin'")=='1','transfer audit identifies target and role change')

finally:
 (out/'product-spaces-checks.json').write_text(json.dumps(checks,ensure_ascii=False,indent=2));print('PASS',sum(c['pass'] for c in checks),'FAIL',sum(not c['pass'] for c in checks))
