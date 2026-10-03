#!/usr/bin/env python3
"""Private-plan ownership, scope, conflict and preservation acceptance in synthetic DB only."""
import hashlib
import json
import os
from pathlib import Path
import secrets
import subprocess
import time
import uuid
from urllib.parse import urlsplit
import requests

manifest = json.loads(Path(os.environ['FILE_RENAME_MANIFEST']).read_text())
assert manifest['database'].startswith('tbox_memories_test_')
assert urlsplit(manifest['base_url']).hostname in ('127.0.0.1', 'localhost')
base = manifest['base_url'].removesuffix('/tbox') + '/community'
users = json.loads(Path(manifest['credentials_file']).read_text())['users']
checks = []


def check(ok, label):
    checks.append({'pass': bool(ok), 'name': label})
    print(('PASS ' if ok else 'FAIL ') + label, flush=True)
    if not ok: raise AssertionError(label)


def sql(statement):
    env = {k:v for k,v in os.environ.items() if not k.startswith('PG')}
    env['PGPASSFILE'] = str(Path.home() / '.config/qaredi/pgpass-testdb')
    result = subprocess.run(['psql','-h','127.0.0.1','-U','tbox_testdb','-w','-X','-At','-d',manifest['database'],'-v','ON_ERROR_STOP=1'],
                            env=env, input=statement.encode(), capture_output=True, check=True)
    return result.stdout.decode().strip()


def call(method, path, body=None, user='employee', expected=200, csrf=True, token=None):
    headers = {'User-Agent':'tbox-review/' + time.strftime('%Y-%m-%d')}
    if token:
        headers['X-API-Token'] = token
    elif user:
        headers['Cookie'] = '__Host-tbox_session=' + users[user]['session']
        if csrf: headers['X-CSRF-Token'] = users[user]['csrf']
    response = requests.request(method, base + path, json=body, headers=headers, timeout=35, allow_redirects=False)
    check(response.status_code == expected, f'{method} travel fixture {response.status_code}/{expected}')
    return response.json()


def mint(scope, user='employee'):
    assert scope in ('travel.read','travel.read+travel.write')
    token = secrets.token_hex(24)
    uid = int(users[user]['id'])
    sql("INSERT INTO api_tokens(user_id,name,token_hash,hint,created_by,scope,expires_at) VALUES(" + str(uid) +
        ",'travel fixture','" + hashlib.sha256(token.encode()).hexdigest() + "','" + token[:6] + "'," + str(uid) + ",'" + scope + "',now()+interval '1 hour')")
    return token


def body():
    return {'title':'합성 제주 여행','destination':'제주','starts_on':'2026-10-04','ends_on':'2026-10-05','notes':'나만의 계획',
            'steps':[{'id':str(uuid.uuid4()),'title':'해변 산책','date':'2026-10-09','status':'planned','notes':''}], 'request_id':str(uuid.uuid4())}


try:
    path='/api/travel-plans'
    data=body()
    call('GET',path,user=None,expected=401)
    call('GET',path,user='guest',expected=403)
    call('POST',path,data,csrf=False,expected=403)
    call('POST',path,dict(data,owner_user_id=users['other']['id']),expected=400)
    plan=call('POST',path,data,expected=201)['plan']; pid=plan['id']; url=path+'/'+pid
    check(plan['visibility']=='private','all plans are private')
    check(call('POST',path,data,expected=201)['plan']['id']==pid,'create replay retains same plan')
    for who in ('other','admin','manager'):
        call('GET',url,user=who,expected=404)
        call('PATCH',url,dict(data,revision=1),user=who,expected=404)
        call('DELETE',url,user=who,expected=404)
        check(not call('GET',path,user=who)['plans'],'foreign plans excluded from list even for admins')
    call('PATCH',url,dict(data,revision=0),expected=400)
    changed=call('PATCH',url,dict(data,revision=1,notes='새 기기에서 변경'))['plan']
    call('PATCH',url,dict(data,revision=1,notes='오래된 창'),expected=409)
    check(call('GET',url)['plan']['notes']=='새 기기에서 변경','stale update preserves newer content')
    post={'caption':'보존할 합성 원문','location':'제주','trip':'합성','taken_on':'2026-10-03','visibility':'private','media_ids':[],'request_id':str(uuid.uuid4())}
    post_id=call('POST','/api/memories',post,expected=201)['id']
    foreign=call('POST','/api/memories',dict(post,request_id=str(uuid.uuid4())),user='other',expected=201)['id']
    call('POST',url+'/records',{'post_id':foreign},expected=404)
    call('POST',url+'/records',{'post_id':post_id},user='other',expected=404)
    call('POST',url+'/records',{'post_id':post_id,'step_id':str(uuid.uuid4())},expected=400)
    linked=call('POST',url+'/records',{'post_id':post_id,'step_id':data['steps'][0]['id']})['plan']
    check(len(linked['records'])==1,'owned plan and post link')
    call('POST',url+'/records',{'post_id':post_id,'step_id':data['steps'][0]['id']})
    check(len(call('GET',url)['plan']['records'])==1,'link retries idempotent')
    newer=call('PATCH',url,dict(data,revision=changed['revision'],steps=[]))['plan']
    check(newer['records'][0]['step_id'] is None,'removing activity retains memory on whole trip')
    preserved=call('GET','/api/memories/'+post_id)['post']
    check(preserved['caption']==post['caption'] and preserved['original_caption']==post['caption'] and preserved['visibility']=='private','plan edits preserve memory content and visibility')
    old_token=users['employee']['community.write']['token']
    call('GET',path,token=old_token,expected=403)
    call('POST',path,body(),token=old_token,expected=403)
    read=mint('travel.read'); write=mint('travel.read+travel.write'); other=mint('travel.read+travel.write','other')
    check('csrf_token' not in call('GET',path,token=read),'token listing does not require or disclose browser CSRF')
    call('GET',url,token=read)
    call('POST',path,body(),token=read,expected=403)
    token_plan=call('POST',path,body(),token=write,expected=201)['plan']
    call('GET',url,token=other,expected=404)
    call('POST',url+'/records',{'post_id':foreign},token=write,expected=404)
    call('POST',url+'/records',{'post_id':post_id,'step_id':None},token=write)
    call('GET','/api/pages',token=write,expected=403)
    call('DELETE',url,token=write,expected=401)
    sql("UPDATE api_tokens SET revoked_at=now() WHERE token_hash='" + hashlib.sha256(write.encode()).hexdigest() + "'")
    call('GET',path,token=write,expected=401)
    call('DELETE',url)
    call('GET',url,expected=404)
    check(call('GET','/api/memories/'+post_id)['post']['caption']==post['caption'],'deleting plan retains original post')
finally:
    Path(os.environ['FILE_RENAME_OUTPUT']).joinpath('travel-results.json').write_text(json.dumps(checks,ensure_ascii=False,indent=2))
    print('TRAVEL_HTTP pass='+str(sum(c['pass'] for c in checks))+' fail='+str(sum(not c['pass'] for c in checks)),flush=True)
