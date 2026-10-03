#!/usr/bin/env python3
from pathlib import Path
exec(compile(Path(__file__).with_name('stage12_http.py').read_text(),str(Path(__file__).with_name('stage12_http.py')),'exec'))

def fixture(mode,arg):
 result=subprocess.run(['php',str(root/'tests/community/stage12_fixture.php'),mode],input=json.dumps(arg).encode(),env=os.environ,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
 if result.returncode: print(result.stderr.decode()[:1000]);raise AssertionError('fixture command')
 return json.loads(result.stdout)
try:
 # Malformed URLs bypass the personalized 404 shell even with a valid session.
 bad=call('GET','/pub/not-a-token',expected=404);check('Set-Cookie' not in bad.headers and 'csrf' not in bad.text,'malformed public URL never opens session')
 docpage(other,{'schema':1,'blocks':[block('synced_source',source_id=source,children=[block('paragraph',text=[])])]},expected=400)
 dbp=page('Public form DB',database=True);dbid=call('GET','/api/pages/'+dbp).json()['database']['id']
 form={'name':'Public survey','type':'form','public':True,'open':True,'anonymous':True,'notify':True,'questions':[{'prop':'title','required':True}]}
 call('PATCH',f'/api/databases/{dbid}/views',{'add':form},expected=403)
 publication=call('POST',f'/api/pages/{dbp}/web-publish',{'confirm_public':True},expected=201).json();pp=publication['url'].removeprefix('/community')
 views=call('PATCH',f'/api/databases/{dbid}/views',{'add':form}).json()['views'];view=next(v for v in views if v['type']=='form');fp=pp+'/form/'+view['id']
 r=call('GET',fp,user=None);check('<script' not in r.text and 'csrf' not in r.text and 'Set-Cookie' not in r.headers and 'name="values[title]"' in r.text,'C:T-744 static anonymous public form')
 sig=re.search(r'name="signature" value="([^"]+)"',r.text)[1]
 before=sql('SELECT count(*) FROM sessions');answer=call('POST',fp+'/submit',{'signature':sig,'values':{'title':'PUBLIC FORM RESPONSE MARKER'}},None,201)
 check(answer.json()=={'ok':True},'C:T-750 submit returns no response UUID or identity');check(sql('SELECT count(*) FROM sessions')==before,'C:T-746 public submit creates no session')
 response=json.loads(sql("SELECT row_to_json(p) FROM (SELECT public_id,author_id,last_edited_by,public_form_response,form_anonymous FROM community.pages WHERE title='PUBLIC FORM RESPONSE MARKER') p"));rid=response['public_id']
 check(response['author_id'] is None and response['last_edited_by'] is None and response['public_form_response'] and response['form_anonymous'],'C:T-746 explicit null author public response')
 check('PUBLIC FORM RESPONSE MARKER' not in call('GET',pp,user=None).text,'published DB excludes response rows')
 call('POST',fp+'/submit',{'signature':sig,'values':{'title':'REPLAY'}},None,403)
 check(sql("SELECT count(*) FROM community.pages WHERE title='REPLAY'")=='0','C:T-745 nonce replay is rejected atomically')
 sig2=re.search(r'name="signature" value="([^"]+)"',call('GET',fp,user=None).text)[1]
 call('POST',fp+'/submit',{'signature':sig2,'values':{'url':'https://example.test'}},None,400)
 call('POST',fp+'/submit',{'signature':sig2,'values':{'title':''}},None,400)
 call('POST',fp+'/submit',{'signature':sig2,'values':{'title':'SECOND ANSWER'}},None,201)
 call('POST',fp+'/submit',{'signature':sig2,'values':{'title':'LIMIT'}},None,429)
 check(sql("SELECT count(*) FROM community.notifications WHERE kind='form_response'")=='2','public response can notify owner without author')
 check(sql("SELECT count(*) FROM audit_logs WHERE action='page.create' AND detail::text LIKE '%PUBLIC FORM RESPONSE MARKER%'")=='0','public answers absent from audit details')
 gp=call('POST','/api/groups',{'name':'form view group','members':['claude_employee01','claude_employee02']},'admin').json()['id']
 call('PUT',f'/api/pages/{dbp}/group-shares/{gp}',{'level':'view'});call('GET','/api/pages/'+rid,user='other',expected=403)
 check(rid not in call('GET','/api/pages',user='other').text,'parent group view cannot list form responses')
 call('PUT',f'/api/pages/{rid}/group-shares/{gp}',{'level':'view'});call('GET','/api/pages/'+rid,user='other')
 check(rid in call('GET','/api/pages?shared=me',user='other').text,'direct group view lists response')
 call('DELETE',f'/api/pages/{rid}/group-shares/{gp}');call('GET','/api/pages/'+rid,user='other',expected=403)
 call('PUT',f'/api/pages/{dbp}/group-shares/{gp}',{'level':'edit'});call('GET','/api/pages/'+rid,user='other')
 # Revocation and form closure are rechecked before nonce consumption.
 call('PATCH',f'/api/databases/{dbid}/views',{'update':{'id':view['id'],'open':False}});call('GET',fp,user=None,expected=404)
 # External importer: immutable primitive values, upsert, missing marker; no external network.
 extpage=page('Connector DB',user='admin',database=True);extid=call('GET','/api/pages/'+extpage,user='admin').json()['database']['id']
 schema=call('PATCH',f'/api/databases/{extid}/schema',{'add':{'name':'External literal','type':'text'}},'admin').json()['database']['schema'];key=schema[-1]['key']
 connector=call('POST','/api/admin/connectors',{'name':'Pending API','database_id':extid,'url_host':'example.com','url_path':'/api/usage','schedule':{'type':'schedule','freq':'daily','time':'09:00'},'field_map':{'value':key}},'admin').json()
 check(not connector['secret_configured'],'external API destination is unconfigured');cid=connector['id']
 check(fixture('import',{'id':cid,'rows':[{'external_key':'EXTERNAL A','value':'<script>literal</script>'},{'external_key':'EXTERNAL B','value':'two'}]})['ok'],'C:T-757 importer accepts literal values')
 ext=json.loads(sql("SELECT row_to_json(p) FROM (SELECT public_id,properties FROM community.pages WHERE external_key='EXTERNAL A') p"));eid=ext['public_id']
 check(ext['properties'][key]=='<script>literal</script>','C:T-760 external HTML remains plain property text')
 call('PATCH','/api/pages/'+eid+'/values',{'values':{key:'changed'}},'admin',400)
 check(fixture('import',{'id':cid,'rows':[{'external_key':'EXTERNAL A','value':'updated'}]})['ok'],'import upsert rerun')
 check(sql("SELECT count(*) FROM community.pages WHERE external_key='EXTERNAL A'")=='1' and sql("SELECT external_missing FROM community.pages WHERE external_key='EXTERNAL B'")=='t','C:T-758 same key upserts and missing is marked not deleted')
 check(not fixture('import',{'id':cid,'rows':[{'external_key':'EXTERNAL A','value':'should rollback'},{'external_key':'EXTERNAL A','value':'dupe'}]})['ok'],'duplicate external key aborts whole transaction')
 check(sql("SELECT properties->>'"+key+"' FROM community.pages WHERE external_key='EXTERNAL A'")=='updated','failed import preserves previous row values')
 check(not fixture('import',{'id':cid,'rows':[{'external_key':'TOO LARGE','value':'x'}]*5001})['ok'],'connector 5000 row limit')
 # Outbox creation is atomic with notification; retracted notifications never send.
 sql("UPDATE community.messenger_hooks SET is_active=true WHERE id="+str(h['id']))
 check(fixture('notify',{'page':p,'rollback':True})['outbox']==0,'outbox rollback follows notification transaction')
 check(fixture('notify',{'page':p,'rollback':False})['outbox']==1,'outbox enqueued with notification')
 sql("UPDATE community.notifications SET retracted_at=now() WHERE kind='comment_on_my_page'")
 check(fixture('worker',{})['sent']==0,'C:T-755 worker drops retracted notification before network')
 check(sql("SELECT status FROM community.notification_outbox ORDER BY id DESC LIMIT 1")=='skipped','outbox status skipped')

 # Atomic ownership when two different pages introduce the same previously absent source UUID.
 from concurrent.futures import ThreadPoolExecutor
 import threading
 left=page('source race left');right=page('source race right');race_source=str(uuid.uuid4());barrier=threading.Barrier(2)
 sql("CREATE FUNCTION community.stage12_source_delay() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN PERFORM pg_sleep(0.5); RETURN NEW; END $$; CREATE TRIGGER stage12_source_delay BEFORE INSERT ON community.synced_sources FOR EACH ROW EXECUTE FUNCTION community.stage12_source_delay()")
 def racing(item):
  pid,port,title=item;barrier.wait()
  return pid,title,requests.patch('http://127.0.0.1:'+str(port)+'/community/api/pages/'+pid,json={'doc_version':1,'doc':{'schema':1,'blocks':[block('synced_source',source_id=race_source,children=[block('paragraph',text=[{'t':title,'m':{}}])])]}},headers={'User-Agent':'tbox-review/'+time.strftime('%Y-%m-%d'),'Cookie':'__Host-tbox_session='+users['employee']['session'],'X-CSRF-Token':users['employee']['csrf']},timeout=20).status_code
 with ThreadPoolExecutor(2) as pool: results=list(pool.map(racing,[(left,8678,'RACE LEFT'),(right,8679,'RACE RIGHT')]))
 sql('DROP TRIGGER stage12_source_delay ON community.synced_sources; DROP FUNCTION community.stage12_source_delay()')
 check(sorted(r[2] for r in results)==[200,400],'concurrent new source ID one wins and one is rejected')
 winner=next(r for r in results if r[2]==200);stored=json.loads(sql("SELECT json_build_object('page',p.public_id,'blocks',s.blocks) FROM community.synced_sources s JOIN community.pages p ON p.id=s.page_id WHERE s.public_id='"+race_source+"'"))
 check(stored['page']==winner[0] and stored['blocks'][0]['text'][0]['t']==winner[1],'concurrent source owner and original content remain consistent')
 print('EXTENDED_ACCEPTANCE_COUNT='+str(len(checks)))
finally:
 (output/'acceptance.json').write_text(json.dumps(checks,ensure_ascii=False,indent=2))
