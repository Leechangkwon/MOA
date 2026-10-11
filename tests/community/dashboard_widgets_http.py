"""Included by product_spaces_http.py only; real router on harness-owned synthetic DB."""
assert manifest['database'].startswith('tbox_memories_test_')
def widget_layout(kind='my_tasks',source=None):
 return {'version':3,'source':'all','view':'recent','guide':'pending','widgets':[{'id':'widget-http-a','type':kind,'source':source if source is not None else {},'visible':True,'locked':False,'desktop':{'x':0,'y':0,'w':480,'h':420,'size':'m'},'mobile':{'x':0,'y':0,'w':360,'h':420,'size':'m'}}]}

v3personal=call('GET','/api/me/home-dashboard');v3personal['layout']=widget_layout()
call('PATCH','/api/me/home-dashboard',v3personal,csrf=False,expected=403)
call('PATCH','/api/me/home-dashboard',v3personal,user=None,expected=401)
v3personal=call('PATCH','/api/me/home-dashboard',v3personal)
check(v3personal['layout']['widgets'][0]['source']=={},'v3 real JSON preserves empty source object')
untouched_v3=dashboards()
check(call('GET','/api/me/home-dashboard')==v3personal and dashboards()==untouched_v3,'v3 GET leaves persisted rows/revision/audit unchanged')
for keys,value in [(['guide'],True),(['widgets',0,'id'],'bad\n'),(['widgets',0,'type'],'space_file'),(['widgets',0,'locked'],'false'),(['widgets',0,'desktop','size'],'wide'),(['widgets',0,'mobile','w'],True),(['widgets',0,'source'],{'url':'https://invalid.example'}),(['widgets',0,'body'],'x')]:
 bad=deepcopy(v3personal);at=bad['layout']
 for key in keys[:-1]:at=at[key]
 at[keys[-1]]=value
 call('PATCH','/api/me/home-dashboard',bad,expected=400)
bad=deepcopy(v3personal);bad['layout']['widgets'][0]['source']=[]
v3personal=call('PATCH','/api/me/home-dashboard',bad)
check(v3personal['layout']['widgets'][0]['source']=={},'v3 decoded empty array source normalizes to object on real HTTP')
bad=deepcopy(v3personal);bad['layout']['widgets'][0]['locked']=True;second=deepcopy(bad['layout']['widgets'][0]);second['id']='widget-http-b';bad['layout']['widgets'].append(second)
call('PATCH','/api/me/home-dashboard',bad,expected=400)
bad['layout']['widgets'][1]['desktop']['y']=500;bad['layout']['widgets'][1]['mobile']['y']=500
v3personal=call('PATCH','/api/me/home-dashboard',bad)
check(all(w['locked'] for w in v3personal['layout']['widgets']),'v3 disjoint locked anchors persist through real router')
v3shared=call('GET',path+'/dashboard');v3shared['layout']=widget_layout('space_task',{'space_id':sid,'scope':'all'})
call('PATCH',path+'/dashboard',v3shared,user='other',expected=403)
call('PATCH',path+'/dashboard',v3shared,user='admin',expected=404)
v3shared=call('PATCH',path+'/dashboard',v3shared,user='manager')
bad=deepcopy(v3shared);bad['layout']['widgets'][0]['source']['space_id']=g
call('PATCH',path+'/dashboard',bad,expected=400)
bad=deepcopy(v3shared);bad['layout']['widgets'][0]['source']['space_id']=sid+'\n'
call('PATCH',path+'/dashboard',bad,expected=400)
bad=deepcopy(v3shared);bad['layout']['widgets'][0]['source']['scope']='mine'
call('PATCH',path+'/dashboard',bad,expected=400)
call('PATCH','/api/me/home-dashboard',v3personal,expected=200)
call('PATCH','/api/me/home-dashboard',v3personal,expected=409)
v3personal=call('GET','/api/me/home-dashboard');v3personal['layout']['guide']='later'
with ThreadPoolExecutor(max_workers=2) as pool:
 first_port=int(site.rsplit(':',1)[1]);hs={'User-Agent':'tbox-review/'+time.strftime('%Y-%m-%d'),'Cookie':'__Host-tbox_session='+users['employee']['session'],'X-CSRF-Token':users['employee']['csrf']}
 outcomes=list(pool.map(lambda port:requests.patch(base.replace(':'+str(first_port),':'+str(port))+'/api/me/home-dashboard',json=v3personal,headers=hs,timeout=35).status_code,[first_port,first_port+1]))
check(sorted(outcomes)==[200,409],'v3 concurrent personal full save has one winner and one conflict')
check(call('GET','/api/me/home-dashboard')['layout']['guide']=='later','v3 guide saved with full-layout revision')

probe=call('POST','/api/product-spaces',{'name':'위젯 출처 회수','kind':'company'},expected=201)['space']['id'];probe_path='/api/product-spaces/'+probe
probe_invite=call('POST',probe_path+'/invites',{'role':'viewer','max_uses':1},expected=201)
call('POST','/api/product-spaces/join',{'token':probe_invite['token']},user='manager')
manager_layout=call('GET','/api/me/home-dashboard',user='manager');manager_layout['layout']=widget_layout('space_task',{'space_id':probe,'scope':'all'});manager_layout['layout']['widgets'][0]['visible']=False
manager_layout=call('PATCH','/api/me/home-dashboard',manager_layout,user='manager')
call('DELETE',probe_path+'/members/'+str(users['manager']['id']))
call('PATCH','/api/me/home-dashboard',manager_layout,user='manager',expected=404)
manager_layout['layout']['widgets']=[];manager_layout['layout']['guide']='done'
manager_layout=call('PATCH','/api/me/home-dashboard',manager_layout,user='manager')
check(manager_layout['layout']['widgets']==[],'v3 revoked hidden source can be removed before save')
call('PATCH',probe_path,{'status':'archived'})
bad=call('GET','/api/me/home-dashboard');bad['layout']=widget_layout('space_event',{'space_id':probe,'scope':'all'})
call('PATCH','/api/me/home-dashboard',bad,expected=403)

query_space=call('POST','/api/product-spaces',{'name':'위젯 정렬 검증','kind':'company'},expected=201)['space']['id'];query_path='/api/product-spaces/'+query_space
sql("INSERT INTO product_space_items(space_id,kind,title,status,created_by) SELECT '"+query_space+"','task','새 완료 작업','done',"+str(users['employee']['id'])+" FROM generate_series(1,110)")
urgent=call('POST',query_path+'/items',{'kind':'task','title':'오래된 가장 급한 작업','due_at':'2000-01-01','assignee_id':users['employee']['id']},expected=201)['item']
sql("UPDATE product_space_items SET updated_at='2000-01-01' WHERE id='"+urgent['id']+"'")
unassigned=call('POST',query_path+'/items',{'kind':'task','title':'담당 없음','due_at':'2099-01-01'},expected=201)['item']
ordered=call('GET',query_path+'/items?view=dashboard&kind=task&limit=1')
check([item['id'] for item in ordered['items']]==[urgent['id']] and ordered['truncated'],'HTTP dashboard task filters/sorts before limit despite 110 newer rows')
mine=call('GET',query_path+'/items?view=dashboard&kind=task&assignee=me&limit=8')
check([item['id'] for item in mine['items']]==[urgent['id']] and not mine['truncated'],'HTTP mine excludes own-created unassigned task')
check(len(call('GET',query_path+'/items?kind=task')['items'])==100,'HTTP ordinary item list stays latest 100')
def test_event(title,start,end,updated='2000-01-01'):
 return sql("INSERT INTO product_space_items(space_id,kind,title,starts_at,ends_at,created_by,updated_at) VALUES('"+query_space+"','event','"+title+"',"+start+","+end+","+str(users['employee']['id'])+",'"+updated+"') RETURNING id").splitlines()[0]
day="date_trunc('day',now() AT TIME ZONE 'Asia/Seoul') AT TIME ZONE 'Asia/Seoul'"
unknown=test_event('오늘 종료 미정',day,'NULL')
ongoing=test_event('진행 중',"now()-interval '1 minute'","now()+interval '1 hour'")
following=test_event('다가오는 일정',"now()+interval '1 day'","now()+interval '1 day 1 hour'")
test_event('어제 종료 미정',"("+day+")-interval '1 day'",'NULL','2026-01-01')
sql("INSERT INTO product_space_items(space_id,kind,title,starts_at,ends_at,created_by) SELECT '"+query_space+"','event','종료됨',now()-interval '2 days',now()-interval '1 day',"+str(users['employee']['id'])+" FROM generate_series(1,110)")
events=call('GET',query_path+'/items?view=dashboard&kind=event&limit=8')
check([item['id'] for item in events['items']]==[ongoing,unknown,following] and events['as_of'].endswith('+09:00'),'HTTP event ongoing/null-end-day boundary/order precede LIMIT')
check(not call('GET',path+'/items?view=dashboard&kind=file&limit=1',user='manager')['items'],'HTTP dashboard file list applies original rights before limit')
layout_home=call('GET',query_path+'/home?view=layout')
check(layout_home['space']['id']==query_space and layout_home['widgets']=={} and layout_home['truncated']=={} and layout_home['revision']==0,'HTTP layout-only home preserves space/settings and returns empty content objects')
check(layout_home['layout']==call('GET',query_path+'/dashboard')['layout'],'HTTP layout-only home and dashboard have identical recommendations')
call('GET',query_path+'/home?view=layout',user='other',expected=404)
call('GET',query_path+'/home?view=layout',user=None,expected=401)
for suffix in ['view=other','view[]=layout','view=layout&kind=task','unexpected=1']:
 call('GET',query_path+'/home?'+suffix,expected=400)
# With source SELECT unavailable, the layout-only route must still work: no five content queries.
sql('REVOKE SELECT ON product_space_items FROM tbox_app')
try:
 check(call('GET',query_path+'/home?view=layout')==layout_home,'HTTP layout-only home never reads product content table')
finally:
 sql('GRANT SELECT ON product_space_items TO tbox_app')
check(len(call('GET',query_path+'/home')['widgets']['task'])==6,'HTTP ordinary home retains legacy six-item content summaries')
for suffix in ['view=other&kind=task','view=dashboard','view=dashboard&kind=event&assignee=me','view=dashboard&kind=task&limit=0','view=dashboard&kind=task&limit=9','view=dashboard&kind=task&limit=1.0','view=dashboard&kind=task&limit[]=1','view=dashboard&kind=task&unknown=1']:
 call('GET',query_path+'/items?'+suffix,expected=400)
business_before=sql("SELECT md5(coalesce(jsonb_agg(to_jsonb(i) ORDER BY id)::text,'[]')) FROM product_space_items i")
dashboard_before=dashboards()
call('GET',query_path+'/items?view=dashboard&kind=task&limit=8');call('GET',query_path+'/items?view=dashboard&kind=event&limit=8');call('GET',query_path+'/dashboard');call('GET','/api/me/home-dashboard');call('GET',query_path+'/home?view=layout')
check(sql("SELECT md5(coalesce(jsonb_agg(to_jsonb(i) ORDER BY id)::text,'[]')) FROM product_space_items i")==business_before and dashboards()==dashboard_before,'HTTP dashboard GET has no content/settings/revision/audit writes')

# Existing Briefing route returns additive reasons without creating reads/notifications.
space_id=sql("SELECT id FROM community.spaces WHERE kind='personal' AND owner_user_id="+str(users['employee']['id'])+" LIMIT 1")
if not space_id:space_id=sql("INSERT INTO community.spaces(kind,owner_user_id,name,access_level) VALUES('personal',"+str(users['employee']['id'])+",'위젯 합성 업무','internal') RETURNING id").splitlines()[0]
brief_page=str(uuid.uuid4())
sql("INSERT INTO community.pages(public_id,space_id,title,doc,visibility,author_id,last_edited_by,status,due_date) VALUES('"+brief_page+"',"+space_id+",'기한과 검토가 함께 있는 문서','{\"schema\":1,\"blocks\":[]}'::jsonb,'private',"+str(users['employee']['id'])+","+str(users['employee']['id'])+",'review',(now() AT TIME ZONE 'Asia/Seoul')::date-1)")
def briefing_state():
 return sql("SELECT jsonb_build_object('pages',(SELECT md5(coalesce(jsonb_agg(to_jsonb(p) ORDER BY id)::text,'[]')) FROM community.pages p),'reads',(SELECT count(*) FROM community.page_reads),'notifications',(SELECT count(*) FROM community.notifications))::text")
before_briefing=briefing_state()
response=requests.get(site+'/community/api/briefing',headers=hs,timeout=35)
check(response.status_code==200,'HTTP existing Briefing route succeeds with reasons extension')
brief=response.json();items=brief['top']+brief['missed']+brief['others'];merged=next(item for item in items if item['key']=='page:'+brief_page)
check(merged['page_id']==brief_page and {'overdue','review'} <= {reason['kind'] for reason in merged['reasons']},'HTTP duplicate page keeps deadline and review reasons with source ID')
check(briefing_state()==before_briefing,'HTTP Briefing reasons do not mark reads or mutate source pages/notifications')
