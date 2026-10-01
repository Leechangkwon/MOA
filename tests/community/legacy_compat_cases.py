#!/usr/bin/env python3
"""Focused compatibility evidence for stages 4A–7D; not the complete legacy matrix.

Run only with stage89_harness.py and an empty synthetic database. No old test script,
production dump, account, service or configuration is used. SQL is fixture setup or
assertion against that dedicated database; product actions use the actual HTTP API.
"""
import copy
import json
import os
from pathlib import Path
import subprocess
import time
import uuid
from urllib.parse import quote
import requests

manifest=json.loads(Path(os.environ['FILE_RENAME_MANIFEST']).read_text())
assert manifest['database'].startswith('tbox_stage89_test_')
assert manifest['base_url'].startswith('http://127.0.0.1:')
root=Path(manifest['instance']);output=Path(os.environ['FILE_RENAME_OUTPUT'])
users=json.loads(Path(manifest['credentials_file']).read_text())['users']
base=manifest['base_url'].removesuffix('/tbox')+'/community';checks=[]
def check(ok,name):
    checks.append({'pass':bool(ok),'name':name});print(('PASS ' if ok else 'FAIL ')+name,flush=True)
    if not ok:raise AssertionError(name)
def call(method,path,body=None,user='employee',expected=200):
    r=requests.request(method,base+path,json=body,headers={'User-Agent':'tbox-review/'+time.strftime('%Y-%m-%d'),'Cookie':'__Host-tbox_session='+users[user]['session'],'X-CSRF-Token':users[user]['csrf']},timeout=30)
    if r.status_code!=expected:print('SAFE_ERROR_REASON='+str(r.json().get('reason','unspecified')),flush=True)
    check(r.status_code==expected,f'{method} {path} {r.status_code}/{expected}')
    return r.json()
def sql(text):
    env={k:v for k,v in os.environ.items() if not k.startswith('PG')};env['PGPASSFILE']=str(Path.home()/'.config/qaredi/pgpass-testdb')
    p=subprocess.run(['psql','-h','127.0.0.1','-U','tbox_testdb','-w','-X','-At','-d',manifest['database'],'-v','ON_ERROR_STOP=1'],env=env,input=text.encode(),capture_output=True,check=True)
    return p.stdout.decode().strip()
def doc(text):return {'schema':1,'blocks':[{'id':str(uuid.uuid4()),'type':'paragraph','text':[{'t':text,'m':{}}]}]}
def page(title, user='employee', **extra):return call('POST','/api/pages',dict(title=title,**extra),user,201)['page']['id']
def save(pid,body,user='employee'):
    v=call('GET','/api/pages/'+pid,user=user)['page']['doc_version']
    return call('PATCH','/api/pages/'+pid,dict(body,doc_version=v),user)
def share(pid,level='view'):return call('PUT','/api/pages/'+pid+'/shares/claude_employee02',{'level':level})
def template(pid,name,scope='personal'):
    return call('POST','/api/templates',{'page_id':pid,'name':name,'scope':scope},expected=201)['template']['id']

# These are subsets of each original requirement, documented separately from full-suite completion.
coverage={
 'hierarchy_visibility':['C:T-22','C:T-24','C:T-25','C:T-27','C:T-31','C:T-32','C:T-33','C:T-34','C:T-36'],
 'versions_checks':['C:T-39','C:T-41','C:T-42','C:T-43','C:T-44','C:T-45','C:T-46','C:T-47','C:T-51'],
 'sharing_handover':['C:T-53','C:T-54','C:T-55','C:T-58','C:T-60'],
 'boards_tags_search':['C:T-80','C:T-81','C:T-84','C:T-85','C:T-86'],
 'comments_notifications':['C:T-100','C:T-101','C:T-102'],
 'database_templates':['C:T-130','C:T-131','C:T-132','C:T-136','C:T-171','C:T-172','C:T-173','C:T-174'],
 'embeds_mentions':['C:T-181','C:T-182','C:T-220','C:T-223','C:T-224','C:T-226'],
}
unverified=[
 'Full original scripts and exhaustive all-user/all-page authorization matrices; this suite samples affected paths.',
 'Legacy browser/editor keyboard, drag, paste and visual checks, PHP/JS renderer parity, nginx/frame sandbox behavior.',
 'Production services, real operational data, migration/backfill volume and upgrade/backup workflows.',
 '7A purge/retention, 6B relation/file/rollup/calculation matrix, 6C view layouts, 7D popularity/palette/fold matrix.',
 'Every legacy size/depth/row quota, immutable-table privilege attack and concurrent-writing permutation.',
]

try:
    for user in users:call('GET','/api/me/space',user=user)
    original=doc('version sentinel original');p=page('compat private sentinel',doc=original)
    original_read=call('GET','/api/pages/'+p)['page']['doc']
    call('GET','/api/pages/'+p,user='other',expected=403)
    call('GET','/api/pages/'+p,user='admin')
    call('PATCH','/api/pages/'+p,{'title':'unauthorized','doc_version':1},user='admin',expected=403)
    for viewer in ['other','admin']:
        found=call('GET','/api/search?q=compat%20private%20sentinel',user=viewer)
        check(p not in json.dumps(found),'private source excluded from ordinary search '+viewer)
    child=page('compat child',parent_id=p);grand=page('compat grandchild',parent_id=child)
    check([x['id'] for x in call('GET','/api/pages/'+grand)['breadcrumb']]==[p,child,grand],'C:T-22 three-level breadcrumb')
    call('PATCH','/api/pages/'+p+'/move',{'parent_id':grand},expected=400)
    call('POST','/api/pages',{'title':'foreign parent','parent_id':p},user='other',expected=403)
    call('PATCH','/api/pages/'+child+'/visibility',{'visibility':'internal'},expected=400)
    share(p);call('GET','/api/pages/'+grand,user='other')
    call('PATCH','/api/pages/'+p,{'doc':doc('view-only edit'),'doc_version':1},user='other',expected=403)
    call('POST','/api/pages',{'title':'view child','parent_id':p},user='other',expected=403)
    call('DELETE','/api/pages/'+p+'/shares/claude_employee02');call('GET','/api/pages/'+grand,user='other',expected=403)
    share(p,'edit')
    updated=save(p,{'doc':doc('version sentinel changed')},user='other')
    call('PATCH','/api/pages/'+p,{'doc':original,'doc_version':1},expected=409)
    call('DELETE','/api/pages/'+p,user='other',expected=403)
    call('PATCH','/api/pages/'+p+'/move',{'parent_id':None},user='other',expected=403)
    call('PATCH','/api/pages/'+p+'/visibility',{'visibility':'internal'},user='other',expected=403)
    versions=call('GET','/api/pages/'+p+'/versions')
    check(any(x['version']==1 and not x['is_current'] for x in versions['versions']),'C:T-39 original version retained')
    check('version sentinel original' not in json.dumps(versions),'C:T-44 history list does not expose body')
    call('POST','/api/pages/'+p+'/versions/1/restore',{})
    restored=call('GET','/api/pages/'+p)['page']
    check(restored['doc']==original_read and restored['doc_version']>2,'C:T-39 restore copies original into a new version')
    check(sql("SELECT excerpt FROM community.page_blocks b JOIN community.pages p ON p.id=b.page_id WHERE p.public_id='"+p+"'")=='version sentinel original','C:T-45 restored derived text agrees with document')
    todoid=str(uuid.uuid4());todo={'schema':1,'blocks':[{'id':todoid,'type':'to_do','checked':False,'text':[{'t':'compat checklist','m':{}}]}]}
    checklist=page('compat checklist',doc=todo)
    for state in [True,False,True]:
        todo['blocks'][0]['checked']=state;save(checklist,{'doc':todo})
    history=call('GET','/api/pages/'+checklist+'/history')
    check([e['checked'] for e in reversed(history['events']) if e['kind']=='check']==[True,False,True],'C:T-41 three ordered checklist events')
    for status in ['todo','doing','review','done','hold',None]:
        call('PATCH','/api/pages/'+checklist+'/properties',{'status':status})
        check(call('GET','/api/pages/'+checklist)['page']['status']==status,'C:T-46 status roundtrip '+str(status))
    call('PATCH','/api/pages/'+checklist+'/properties',{'status':'urgent'},expected=400)
    call('PATCH','/api/pages/'+checklist+'/properties',{'assignee':'claude_employee02'},expected=400)
    call('GET','/api/pages/'+checklist+'/versions',user='other',expected=403)
    call('PATCH','/api/pages/'+checklist+'/properties',{'status':'done'},user='admin',expected=403)
    call('PATCH','/api/pages/'+p+'/visibility',{'visibility':'internal','cascade':True})
    call('GET','/api/pages/'+grand,user='other');call('GET','/api/pages/'+grand,user='guest',expected=403)
    call('PATCH','/api/pages/'+p+'/visibility',{'visibility':'private'},expected=400)
    call('PATCH','/api/pages/'+p+'/visibility',{'visibility':'private','cascade':True})
    call('DELETE','/api/pages/'+p)
    call('GET','/api/pages/'+grand,expected=404)
    check(call('POST','/api/pages/'+p+'/restore',{})['restored']==3,'C:T-43 branch restored together')
    transfer=page('compat transfer');transfer_child=page('compat transferred child',parent_id=transfer)
    call('POST','/api/pages/'+transfer+'/handover',{'to':'claude_employee02','keep':'none'})
    call('GET','/api/pages/'+transfer_child,expected=403)
    owned=call('GET','/api/pages/'+transfer_child,user='other')
    check(owned['can_edit'] is True,'C:T-58 receiving owner can edit handed-over descendants')

    call('GET','/api/spaces');call('GET','/api/spaces',user='other')
    team=int(sql("SELECT s.id FROM community.spaces s JOIN users u ON u.team_id=s.team_id WHERE s.kind='team' AND u.username='claude_employee01'"))
    team_page=page('compat team template',space_id=team)
    call('GET','/api/pages/'+team_page,user='manager')
    call('GET','/api/pages/'+team_page,user='other',expected=403)
    team_tpl=template(team_page,'compat team template','team')
    call('GET','/api/templates/'+team_tpl,user='other',expected=403)
    page('team template copy',user='manager',template_id=team_tpl)
    # Only the dedicated synthetic session receives fresh reauthentication state.
    sql("UPDATE public.sessions SET reauth_at=now() WHERE user_id=(SELECT id FROM users WHERE username='claude_admin01')")
    board=call('POST','/api/boards',{'name':'compat internal discussion','access_level':'internal','board_kind':'discussion'},user='admin',expected=201)['board']['id']
    post=call('POST',f'/api/boards/{board}/pages',{'title':'compat board searchable'},expected=201)['page']['id']
    call('GET','/api/pages/'+post,user='other');call('GET','/api/pages/'+post,user='guest',expected=403)
    for viewer in ['other','admin']:call('PATCH','/api/pages/'+post,{'title':'not author','doc_version':1},user=viewer,expected=403)
    call('POST','/api/pages',{'title':'board child','parent_id':post},expected=400)
    tags=call('PUT','/api/pages/'+post+'/tags',{'tags':['CompatTag','compattag']})
    check(len(tags['page']['tags'])==1,'C:T-84 case-insensitive tags deduplicate')
    call('PUT','/api/pages/'+post+'/tags',{'tags':[f'tag{i}' for i in range(11)]},expected=400)
    found=call('GET','/api/search?q=CompatTag',user='other')
    check(post in [x['id'] for x in found['results']],'C:T-86 tag search includes readable board page')
    call('GET','/api/pages/'+post,user='other');call('GET','/api/pages/'+post,user='other')
    check(sql("SELECT count(*) FROM community.page_views v JOIN community.pages p ON p.id=v.page_id WHERE p.public_id='"+post+"' AND v.user_id=(SELECT id FROM users WHERE username='claude_employee02')")=='1','C:T-85 same reader daily view deduplication')

    comment=call('POST','/api/pages/'+p+'/comments',{'body':'hello @claude_employee02 @claude_employee02 @claude_admin01'},expected=201)['comment']['id']
    check(sql("SELECT count(*) FROM community.notifications n JOIN community.comments c ON c.id=n.comment_id WHERE c.public_id='"+comment+"' AND n.user_id=(SELECT id FROM users WHERE username='claude_employee02')")=='1','C:T-101 duplicate mention yields one readable notification')
    check(sql("SELECT count(*) FROM community.notifications n JOIN community.comments c ON c.id=n.comment_id WHERE c.public_id='"+comment+"' AND n.user_id=(SELECT id FROM users WHERE username='claude_admin01')")=='0','C:T-101 privileged-only admin receives no private mention')
    notice=next(n for n in call('GET','/api/notifications',user='other')['notifications'] if n.get('comment_id')==comment)
    check(call('POST','/api/notifications/read',{'ids':[notice['id']]})['changed']==0,'notification read cannot change another user row')
    check(call('POST','/api/notifications/read',{'ids':[notice['id']]},user='other')['changed']==1,'owner marks notification read')
    check(call('POST','/api/notifications/read',{'ids':[notice['id']]},user='other')['changed']==0,'notification read is idempotent')
    reply=call('POST','/api/pages/'+p+'/comments',{'body':'reply','parent_id':comment},user='other',expected=201)['comment']['id']
    call('POST','/api/pages/'+p+'/comments',{'body':'nested reply','parent_id':reply},expected=400)
    call('PATCH','/api/comments/'+comment,{'body':'not author'},user='other',expected=403)
    call('DELETE','/api/pages/'+p+'/shares/claude_employee02')
    notices=call('GET','/api/notifications',user='other')['notifications']
    check(next(n for n in notices if n['id']==notice['id'])['page'] is None,'C:T-101 lost sharing removes old notification title/link')
    call('GET','/api/pages/'+p+'/comments',user='other',expected=403)
    board_notice=call('POST','/api/boards',{'name':'compat notice','access_level':'internal','board_kind':'notice'},user='admin',expected=201)['board']['id']
    call('POST',f'/api/boards/{board_notice}/pages',{'title':'not manager'},expected=403)
    announcement=call('POST',f'/api/boards/{board_notice}/pages',{'title':'compat announcement'},user='admin',expected=201)['page']['id']
    call('GET','/api/pages/'+announcement);call('GET','/api/pages/'+announcement)
    check(sql("SELECT count(*) FROM community.page_reads r JOIN community.pages p ON p.id=r.page_id WHERE p.public_id='"+announcement+"' AND r.user_id=(SELECT id FROM users WHERE username='claude_employee01')")=='1','C:T-102 notice read is recorded once')

    dbresult=call('POST','/api/pages',{'title':'compat database','database':True},expected=201)['page'];dbpage=dbresult['id'];dbid=dbresult['database']['id']
    added=call('PATCH','/api/databases/'+dbid+'/schema',{'add':{'name':'Quantity','type':'number'}})['database'];key=added['schema'][0]['key'];oldversion=added['version']
    call('PATCH','/api/databases/'+dbid+'/schema',{'rename':{'key':key,'name':'Amount'}})
    call('PATCH','/api/databases/'+dbid+'/schema',{'rename':{'key':key,'name':'Old rename'},'version':oldversion},expected=409)
    row=page('compat row',parent_id=dbpage,properties={key:12})
    call('PATCH','/api/pages/'+row+'/values',{'values':{key:'bad number'}},expected=400)
    call('PATCH','/api/pages/'+row+'/values',{'values':{key:24}})
    check(call('GET','/api/pages/'+row)['row']['values'][key]==24,'C:T-131 DB value update')
    check(row not in [x['id'] for x in call('GET','/api/pages/'+dbpage)['children']],'C:T-132 DB row omitted from ordinary tree children')
    check(call('GET','/api/databases/'+dbid+'/rows')['rows'][0]['id']==row,'C:T-132 row visible in database list')
    call('GET','/api/databases/'+dbid+'/rows',user='other',expected=403)
    share(dbpage,'edit')
    call('PATCH','/api/databases/'+dbid+'/schema',{'add':{'name':'foreign schema','type':'text'}},user='other',expected=403)
    call('PATCH','/api/pages/'+row+'/values',{'values':{key:30}},user='other')
    rowtpl=template(row,'compat row template','database')
    copyrow=page('compat row copy',template_id=rowtpl)
    check(call('GET','/api/pages/'+copyrow)['row']['values'][key]==30,'C:T-173 ordinary DB template copy preserves valid properties')
    call('POST','/api/pages',{'template_id':rowtpl,'parent_id':p},expected=400)
    personal_tpl=template(checklist,'compat personal checklist')
    copied=page('compat copied checklist',template_id=personal_tpl)
    copied_doc=call('GET','/api/pages/'+copied)['page']['doc']
    check(copied_doc['blocks'][0]['id']!=todoid and copied_doc['blocks'][0]['type']=='to_do','C:T-172 personal template gets fresh block IDs')
    call('POST','/api/pages',{'template_id':personal_tpl},user='other',expected=403)
    call('DELETE','/api/templates/'+personal_tpl);call('GET','/api/templates/'+personal_tpl,expected=404);call('GET','/api/pages/'+copied)

    emb=page('compat embed');blocked={'schema':1,'blocks':[{'id':str(uuid.uuid4()),'type':'embed','url':'https://blocked.example.com/embed/1'}]}
    call('PATCH','/api/pages/'+emb,{'doc':blocked,'doc_version':1},expected=400)
    domain=call('POST','/api/embed-domains',{'host':'allowed.example.com'},user='admin',expected=201)['domain']['id']
    allowed=copy.deepcopy(blocked);allowed['blocks'][0]['url']='https://allowed.example.com/embed/1';save(emb,{'doc':allowed})
    call('DELETE','/api/embed-domains/'+str(domain),user='admin')
    save(emb,{'doc':allowed,'title':'compat retained embed'})
    fresh=copy.deepcopy(allowed);fresh['blocks'].append({'id':str(uuid.uuid4()),'type':'embed','url':'https://allowed.example.com/embed/2'})
    v=call('GET','/api/pages/'+emb)['page']['doc_version'];call('PATCH','/api/pages/'+emb,{'doc':fresh,'doc_version':v},expected=400)
    check(True,'C:T-181/182 removed host keeps old embed but rejects new embed')
    hidden=page('compat secret mention',user='other');mentions={'schema':1,'blocks':[{'id':str(uuid.uuid4()),'type':'paragraph','text':[
      {'t':'@','m':{'mention':{'kind':'page','id':hidden}}},
      {'t':'@','m':{'mention':{'kind':'user','id':users['other']['id']}}},
      {'t':'@','m':{'mention':{'kind':'date','id':'2026-10-01'}}}]}]}
    mentionpage=page('compat mention container',doc=mentions);got=call('GET','/api/pages/'+mentionpage)
    check('page:'+hidden not in got['mention_labels'] and 'compat secret mention' not in json.dumps(got),'C:T-223 unreadable mention reveals no title')
    check(sql("SELECT plain_text FROM community.pages WHERE public_id='"+mentionpage+"'").replace(' ','')=='@@@','C:T-224 mention index contains marker only')
    invalid=copy.deepcopy(mentions);invalid['blocks'][0]['text'][0]['m']['mention']['label']='saved private title'
    call('PATCH','/api/pages/'+mentionpage,{'doc':invalid,'doc_version':1},expected=400)
    mt=template(mentionpage,'compat mention template');mtcopy=page('compat stripped mention copy',template_id=mt)
    check('"mention"' not in json.dumps(call('GET','/api/pages/'+mtcopy)['page']['doc']),'C:T-226 template removes mention marks')
finally:
    report={'checks':checks,'coverage_subsets':coverage,'unverified':unverified}
    (output/'legacy-compat-results.json').write_text(json.dumps(report,ensure_ascii=False,indent=2))
    print(f'LEGACY_CHECKS={len(checks)} PASS={sum(c["pass"] for c in checks)} FAIL={sum(not c["pass"] for c in checks)}',flush=True)
