#!/usr/bin/env python3
"""Real HTTP regression tests against synthetic files only; samples contain no tokens."""
from concurrent.futures import ThreadPoolExecutor
import hashlib
import json
import os
from pathlib import Path
import subprocess
import time
from urllib.parse import quote
import uuid

import requests

manifest = json.loads(Path(os.environ['FILE_RENAME_MANIFEST']).read_text())
assert manifest['database'].startswith('tbox_rename_test_')
root = Path(manifest['instance'])
output = Path(os.environ['FILE_RENAME_OUTPUT'])
data = json.loads(Path(manifest['credentials_file']).read_text())
users, files = data['users'], data['files']
base = manifest['base_url']
checks = []
private_values = [manifest['shared_token']]
for user in users.values():
    private_values.extend([user['session'], user['csrf']])
    private_values.extend(user[s]['token'] for s in ('read','read+upload','read+upload+delete','community.write','session.open'))


def check(condition, name):
    checks.append({'pass': bool(condition), 'name': name})
    print(('PASS ' if condition else 'FAIL ') + name, flush=True)
    if not condition:
        raise AssertionError(name)


def call(method, path, body=None, user='employee', scope='read+upload', expected=None, cookie=False, token=None):
    headers = {'User-Agent': 'tbox-review/' + time.strftime('%Y-%m-%d')}
    if cookie:
        headers['Cookie'] = '__Host-tbox_session=' + users[user]['session']
        headers['X-CSRF-Token'] = users[user]['csrf']
    else:
        headers['X-API-Token'] = token or users[user][scope]['token']
    response = requests.request(method, base + path, headers=headers, json=body, timeout=25)
    if expected is not None:
        check(response.status_code == expected, '{} {} expected {} actual {}'.format(method,path,expected,response.status_code))
    return response


def rename(key, name, old=None, **kwargs):
    return call('PATCH', '/api/files/' + files[key]['id'],
                {'name': name, 'expected_name': old or files[key]['name']}, **kwargs)


def sql(text):
    env = {k: v for k, v in os.environ.items() if not k.startswith('PG')}
    env['PGPASSFILE'] = str(Path.home() / '.config/qaredi/pgpass-testdb')
    result = subprocess.run(['psql','-h','127.0.0.1','-p','5432','-U','tbox_testdb','-w','-X','-At',
                             '-d',manifest['database'],'-v','ON_ERROR_STOP=1'],
                            env=env, input=text.encode(), stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=True)
    return result.stdout.decode().strip()


def while_file_locked(change_sql, expected, current):
    env = {k: v for k, v in os.environ.items() if not k.startswith('PG')}
    env['PGPASSFILE'] = str(Path.home() / '.config/qaredi/pgpass-testdb')
    proc = subprocess.Popen(['psql','-h','127.0.0.1','-p','5432','-U','tbox_testdb','-w','-X','-At',
                             '-d',manifest['database'],'-v','ON_ERROR_STOP=1'],
                            env=env, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    row_before = snapshot('main')
    try:
        proc.stdin.write('BEGIN; SELECT id FROM files WHERE id={} FOR UPDATE;\n\\echo LOCK_READY\n'.format(files['main']['internal_id']))
        proc.stdin.flush()
        while proc.stdout.readline().strip() != 'LOCK_READY':
            if proc.poll() is not None:
                raise AssertionError('Owned fixture lock failed')
        with ThreadPoolExecutor(max_workers=1) as pool:
            job = pool.submit(rename,'main','잠금대기변경.txt',old=current)
            for _ in range(100):
                waiting = sql("SELECT count(*) FROM pg_locks l JOIN pg_stat_activity a ON a.pid=l.pid WHERE a.datname=current_database() AND a.usename='tbox_app' AND NOT l.granted;")
                if int(waiting) > 0:
                    break
                time.sleep(0.05)
            else:
                raise AssertionError('HTTP did not reach file-lock barrier')
            check(not job.done(),'rename actually waiting for file lock')
            sql(change_sql)
            proc.stdin.write('ROLLBACK;\n\\q\n'); proc.stdin.flush()
            response = job.result()
            check(response.status_code == expected,'token recheck after file lock: HTTP '+str(expected))
            check(snapshot('main') == row_before,'post-lock authorization rejection keeps file unchanged')
    finally:
        if proc.poll() is None:
            proc.terminate()
        proc.wait(timeout=5)


def snapshot(key):
    return json.loads(sql('SELECT row_to_json(f) FROM files f WHERE id={};'.format(files[key]['internal_id'])))


def audit_count(key, result='allow'):
    return int(sql("SELECT count(*) FROM audit_logs WHERE action='file.rename' AND target_id={} AND result='{}';".format(files[key]['internal_id'], result)))


def sample(name, response):
    payload = json.dumps({'http_status':response.status_code,'body':response.json()}, ensure_ascii=False, indent=2) + '\n'
    check(not any(value in payload for value in private_values), 'sample excludes secrets: ' + name)
    (output / name).write_text(payload)


def vectors():
    result = subprocess.run(['php','tests/file_rename/fixture.php','vectors'], cwd=root, check=True,
                            stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    return json.loads(result.stdout)['vector_mismatches']


def run():
    before = snapshot('main')
    original = call('GET','/api/files/' + files['main']['id'] + '/download',expected=200)
    check(original.content == b'SYNTHETIC FIXTURE ONLY\n', 'download fixture bytes before rename')
    for scope in ('read','community.write','session.open'):
        rename('main','금지.txt',scope=scope,expected=403)
    rename('main','금지.txt',token=manifest['shared_token'],expected=403)
    rename('main','금지.txt',cookie=True,expected=403)
    for user in ('other','admin','manager'):
        response = rename('main','금지.txt',user=user,expected=403)
        check(response.json().get('code') == 'forbidden' and files['main']['name'] not in response.text, 'ownership denial is generic: '+user)
    rename('hidden','금지.txt',expected=403)
    rename('main','금지.txt',user='guest',expected=403)
    rename('deleted','금지.txt',expected=404)
    call('PATCH','/api/files/' + str(uuid.uuid4()), {'name':'새자료.txt','expected_name':'없음.txt'},expected=404)
    path = '/api/files/' + files['main']['id']
    for body in ({'name':'새자료.txt'}, {'expected_name':'기존자료.txt'},
                 {'name':None,'expected_name':'기존자료.txt'}, {'name':42,'expected_name':'기존자료.txt'},
                 {'name':'새자료.txt','expected_name':[]}, {'name':'새자료.txt','expected_name':''},
                 {'name':'새자료.txt','expected_name':'기존자료.txt','folder_id':None},
                 {'name':'새자료.txt','expected_name':'기존자료.txt','access_level':'public'},
                 {'name':'새자료.txt','expected_name':'기존자료.txt','uploaded_by':users['admin']['id']}):
        call('PATCH',path,body,expected=400)
    bad_names = ['', ' ', ' 새자료.txt', '새자료.txt ', '../새자료.txt', 'dir/새자료.txt',
                 'dir\\새자료.txt', 'C:새자료.txt', '새자료\x00.txt', '새자료\n.txt',
                 '새자료\u202e.txt', '새자료\u200b.txt', '.', '..', 'a'*252+'.txt', '새자료',
                 'shell.php.txt', '새자료?.txt', '새자료".txt', '새자료<.txt', '새자료|.txt']
    for name in bad_names:
        rename('main',name,expected=400)
    response = rename('main','새자료.xlsx',expected=400)
    check(response.json()['code'] == 'extension_changed','extension change explicit error')
    sample('extension-changed.json',response)
    check(snapshot('main') == before,'rejected requests never change file row')
    check(audit_count('main') == 0,'rejected requests have no success audit')
    response = rename('main','새자료.txt',old='지나간이름.txt',expected=409)
    check(response.json()['code'] == 'name_conflict','CAS conflict explicit error')
    sample('name-conflict.json',response)
    new_name = '팔월_검증완료자료.txt'
    response = rename('main',new_name,expected=200)
    check(response.json() == {'id':files['main']['id'],'name':new_name},'success exact UUID/name contract')
    sample('rename-success.json',response)
    after = snapshot('main')
    changed = [key for key in before if before[key] != after[key]]
    check(set(changed) == {'original_name','search_tsv'},'only display name and search vector changed')
    for field in ('public_id','stored_name','ext','mime','sha256','size_bytes','folder_id','access_level','team_id','uploaded_by','description'):
        check(after[field] == before[field],'immutable field: '+field)
    check(snapshot('duplicate')['original_name'] == '기존자료.txt','same-name other UUID untouched')
    check(audit_count('main') == 1,'one mutation one success audit')
    audit = json.loads(sql("SELECT row_to_json(a) FROM audit_logs a WHERE action='file.rename' AND result='allow' AND target_id={} ORDER BY id DESC LIMIT 1;".format(files['main']['internal_id'])))
    check(audit['detail']['before']['name'] == '기존자료.txt' and audit['detail']['after']['name'] == new_name,
          'audit records old and new name')
    check(audit['detail']['changed'] == ['original_name'] and audit['actor_id'] == users['employee']['id'],
          'audit records changed field and actor')
    check(not any(value in json.dumps(audit) for value in private_values),'audit contains no credentials')
    downloaded = call('GET',path+'/download',expected=200)
    check(downloaded.content == original.content and hashlib.sha256(downloaded.content).hexdigest() == before['sha256'],
          'download bytes/hash identical after rename')
    check(quote(new_name) in downloaded.headers['Content-Disposition'],'download filename is new UTF8 name')
    (output/'download-headers.json').write_text(json.dumps({'status':downloaded.status_code,
        'Content-Disposition':downloaded.headers['Content-Disposition'],'body_sha256':hashlib.sha256(downloaded.content).hexdigest()},ensure_ascii=False,indent=2))
    listed = call('GET','/api/files?q='+quote('팔월_검증완료자료'),expected=200).json()
    check(any(row['id'] == files['main']['id'] and row['name'] == new_name for row in listed), 'search returns new name')
    old = call('GET','/api/files?q='+quote('기존자료'),expected=200).json()
    check(not any(row['id'] == files['main']['id'] for row in old), 'old name search excludes renamed UUID')
    desc = call('GET','/api/files?q='+quote('설명근거'),expected=200).json()
    check(any(row['id'] == files['main']['id'] for row in desc), 'description still searchable')
    rename('main',new_name,old=new_name,expected=200)
    check(audit_count('main') == 1,'same-name retry no duplicate mutation audit')
    rename('main','대문자.TXT',old=new_name,scope='read+upload+delete',expected=200)
    check(snapshot('main')['ext'] == 'txt','case-only extension accepted; stored extension unchanged')
    rename('admin','관리자본인.txt',user='admin',expected=200)
    rename('manager','관리자급본인.txt',user='manager',expected=200)
    # Two simultaneous requests with the same previous name: one wins, one conflicts.
    with ThreadPoolExecutor(max_workers=2) as pool:
        results = list(pool.map(lambda name: rename('concurrent',name), ('경쟁A.txt','경쟁B.txt')))
    check(sorted(r.status_code for r in results) == [200,409],'concurrent CAS exactly one 200 and one 409')
    check(audit_count('concurrent') == 1,'concurrent CAS exactly one mutation audit')
    # Old token folder path and cookie description path remain available with correct guards.
    call('PATCH',path,{'description':'금지 설명'},expected=400)
    rename('main','동명허용.txt',old='대문자.TXT',expected=200)
    rename('duplicate','동명허용.txt',expected=200)
    check(snapshot('main')['original_name'] == snapshot('duplicate')['original_name'],'duplicate display names allowed by UUID')
    call('PATCH',path,{'folder_id':data['folders']['b']['id']},expected=200)
    check(snapshot('main')['folder_id'] == data['folders']['b']['internal_id'],'existing token folder move still works')
    call('PATCH',path,{'description':'수정된설명근거'},cookie=True,expected=200)
    check(vectors() == 0,'all search vectors match name and current description')
    current = '동명허용.txt'
    for index in range(4):
        future_name = '동시설명검증{}.txt'.format(index)
        with ThreadPoolExecutor(max_workers=2) as pool:
            a = pool.submit(rename,'main',future_name,old=current)
            b = pool.submit(call,'PATCH',path,{'description':'동시 설명 '+str(index)},cookie=True)
            responses = [a.result(),b.result()]
        check(all(r.status_code == 200 for r in responses),'concurrent description and rename both succeed '+str(index))
        check(vectors() == 0,'no stale name search vector after concurrent description '+str(index))
        current = future_name
    token_id = users['employee']['read+upload']['id']
    for change in ("revoked_at=clock_timestamp()", "revoked_at=NULL,expires_at=clock_timestamp()-interval '1 second'"):
        sql('UPDATE api_tokens SET {} WHERE id={};'.format(change,token_id))
        rename('main','인증금지.txt',old=current,expected=401)
    sql("UPDATE api_tokens SET revoked_at=NULL,expires_at=clock_timestamp()+interval '1 day' WHERE id={};".format(token_id))
    sql('UPDATE users SET is_active=false WHERE id={};'.format(users['employee']['id']))
    rename('main','계정금지.txt',old=current,expected=403)
    sql('UPDATE users SET is_active=true WHERE id={};'.format(users['employee']['id']))
    for change, expected in (("revoked_at=clock_timestamp()",401),
                             ("expires_at=clock_timestamp()-interval '1 second'",401),
                             ("scope='read'",403)):
        while_file_locked('UPDATE api_tokens SET {} WHERE id={};'.format(change,token_id),expected,current)
        sql("UPDATE api_tokens SET scope='read+upload',revoked_at=NULL,expires_at=clock_timestamp()+interval '1 day' WHERE id={};".format(token_id))
    # Audit insertion failure must roll back name and vector together (temporary DB only).
    sql('REVOKE INSERT ON audit_logs FROM tbox_app;')
    row_before = snapshot('main')
    try:
        rename('main','감사실패.txt',old=current,expected=500)
    finally:
        sql('GRANT INSERT ON audit_logs TO tbox_app;')
    check(snapshot('main') == row_before,'audit failure rolls back whole rename')
    check(vectors() == 0,'final search vectors consistent')


completed = False
try:
    run()
    completed = True
except Exception as error:
    checks.append({'pass':False,'name':'uncaught test failure: '+type(error).__name__})
    raise
finally:
    summary = {'completed':completed,'passed':sum(c['pass'] for c in checks), 'failed':sum(not c['pass'] for c in checks), 'checks':checks}
    (output/'regression-results.json').write_text(json.dumps(summary,ensure_ascii=False,indent=2)+'\n')
    print('FILE_RENAME_CHECKS_PASS={}; FAIL={}'.format(summary['passed'],summary['failed']),flush=True)
