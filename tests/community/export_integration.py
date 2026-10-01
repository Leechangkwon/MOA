#!/usr/bin/env python3
"""C:T-310..321 against a fresh database and actual PHP routes; synthetic data only."""
import io
import json
import os
from pathlib import Path
import subprocess
import time
import uuid
import zipfile
import requests

manifest = json.loads(Path(os.environ['COMMUNITY_EXPORT_MANIFEST']).read_text())
assert manifest['database'].startswith('tbox_export_test_')
root = Path(manifest['instance'])
output = Path(os.environ['COMMUNITY_EXPORT_OUTPUT'])
data = json.loads(Path(manifest['credentials_file']).read_text())
users, files = data['users'], data['files']
site = manifest['base_url'].removesuffix('/tbox')
checks = []


def check(ok, name):
    checks.append({'pass': bool(ok), 'name': name})
    print(('PASS ' if ok else 'FAIL ') + name, flush=True)
    if not ok: raise AssertionError(name)


def call(path, user='employee', expected=200, headers=None, method='GET', body=None):
    hs = {'User-Agent': 'tbox-review/' + time.strftime('%Y-%m-%d')}
    if user:
        hs.update(Cookie='__Host-tbox_session=' + users[user]['session'], **{'X-CSRF-Token':users[user]['csrf']})
    hs.update(headers or {})
    response = requests.request(method, site + '/community' + path, json=body, headers=hs, timeout=90, allow_redirects=False)
    check(response.status_code == expected, method + ' ' + path + ' HTTP ' + str(expected))
    return response


def sql(statement):
    env = {k:v for k,v in os.environ.items() if not k.startswith('PG')}
    env['PGPASSFILE'] = str(Path.home()/'.config/qaredi/pgpass-testdb')
    result = subprocess.run(['psql','-h','127.0.0.1','-p','5432','-U','tbox_testdb','-w','-X','-At',
          '-d',manifest['database'],'-v','ON_ERROR_STOP=1'],env=env,input=statement.encode(),
          stdout=subprocess.PIPE,stderr=subprocess.PIPE,check=True)
    return result.stdout.decode().strip()


def literal(value): return "'" + str(value).replace("'", "''") + "'"


def doc(text): return {'schema':1,'blocks':[{'id':str(uuid.uuid4()),'type':'paragraph','text':[{'t':text}]}]}


def page(title, text='검증용 본문', parent=None, visibility='private', owner='employee', document=None):
    uid = users[owner]['id']
    space = sql("SELECT id FROM community.spaces WHERE kind='personal' AND owner_user_id="+str(uid))
    if not space:
        space = sql("INSERT INTO community.spaces(kind,owner_user_id,name,access_level) VALUES('personal',"+str(uid)+",'검증 공간','internal') RETURNING id").splitlines()[0]
    pid = str(uuid.uuid4())
    sql('INSERT INTO community.pages(public_id,space_id,parent_page_id,title,doc,visibility,author_id,last_edited_by) VALUES('
        + ','.join([literal(pid),str(int(space)), '(SELECT id FROM community.pages WHERE public_id='+literal(parent)+')' if parent else 'NULL',
                    literal(title),literal(json.dumps(document or doc(text),ensure_ascii=False))+'::jsonb',literal(visibility),str(uid),str(uid)]) + ')')
    return pid


def export(pid, fmt='md', tree=False, **kwargs):
    return call('/api/pages/'+pid+'/export?format='+fmt+'&tree='+str(int(tree)), **kwargs)


def archive(response):
    with zipfile.ZipFile(io.BytesIO(response.content)) as bundle:
        return {n:bundle.read(n).decode() for n in bundle.namelist()}


def audit(pid):
    return json.loads(sql("SELECT row_to_json(a) FROM audit_logs a WHERE action='page.export' AND target_id=(SELECT id FROM community.pages WHERE public_id="
       +literal(pid)+") ORDER BY id DESC LIMIT 1"))


def run():
    pid = page('월마감 검토 보고서')
    if os.environ.get('EXPORT_BROWSER_ONLY') == '1':
        output.joinpath('fixture-ids.json').write_text(json.dumps({'own':pid}))
        subprocess.run(['python3',str(Path(__file__).with_name('browser_8b.py'))],check=True,env=os.environ.copy())
        return
    before = sql('SELECT row_to_json(p) FROM community.pages p WHERE public_id='+literal(pid))
    for fmt in ('md','html'):
        result = export(pid,fmt)
        check('attachment;' in result.headers.get('Content-Disposition','') and "filename*=UTF-8''" in result.headers['Content-Disposition'],fmt+' attachment filenames')
        check('검증용 본문' in result.content.decode(),fmt+' retains actual body')
        check(audit(pid)['detail']['format']==fmt and audit(pid)['detail']['page_ids']==[pid],fmt+' audit contains exactly exported ID')
    printed = call('/p/'+pid+'/print')
    check('editor.js' not in printed.text and '<iframe' not in printed.text and 'print.js' in printed.text, 'print uses SSR without editor or iframe')
    check(audit(pid)['detail']['format']=='print','print emits export audit')
    check(sql('SELECT row_to_json(p) FROM community.pages p WHERE public_id='+literal(pid))==before,'export and print do not alter document record')
    call('/p/'+pid+'/print',user=None,expected=302)
    for fmt in ('md','html'): export(pid,fmt,user='other',expected=403)
    for p in ['/p/'+pid+'/print','/api/pages/'+pid+'/export?format=md']:
        call(p,user='admin',expected=403)
        check(audit(pid)['reason']=='privileged_only','admin private export is explicitly denied')
    export(pid,user='guest',expected=403)
    export(str(uuid.uuid4()),expected=404)
    deleted = page('휴지통 문서')
    sql('UPDATE community.pages SET deleted_at=now() WHERE public_id='+literal(deleted))
    export(deleted,expected=404)
    call('/api/pages/'+pid+'/export?format[]=md',expected=400)
    call('/api/pages/'+pid+'/export?format=html&tree=yes',expected=400)
    call('/api/pages/'+pid+'/export',user=None,expected=401,headers={'X-API-Token':users['employee']['community.write']['token']})
    own = call('/api/pages/'+pid).json()
    check(own['can_export'] is True,'own page advertises can_export')
    check(call('/api/pages/'+pid,user='admin').json()['can_export'] is False,'privileged-only UI flag is false')

    tree = page('공유 트리',visibility='team')
    hidden1 = page('SECRET_CHILD_ONE',parent=tree)
    hidden2 = page('SECRET_CHILD_TWO',parent=tree)
    grand = page('SECRET_GRANDCHILD',text='SECRET_GRAND_BODY',parent=hidden1)
    visible = page('확인 가능한 문서',parent=tree,visibility='team')
    outside = page('외부 연결 문서',visibility='team')
    body = doc('대사 결과')
    for target in (visible,outside,hidden1):
        body['blocks'].append({'id':str(uuid.uuid4()),'type':'link_to_page','page_id':target})
    body['blocks'] += [{'id':str(uuid.uuid4()),'type':'file','file_ref':'tbox:'+files['hidden']['id'], 'caption':[{'t':'SECRET_CAPTION'}]},
                       {'id':str(uuid.uuid4()),'type':'embed','url':'https://secret.invalid/EMBED_PRIVATE'}]
    sql('UPDATE community.pages SET doc='+literal(json.dumps(body,ensure_ascii=False))+'::jsonb WHERE public_id='+literal(tree))
    temp = root.parent/'tmp'
    old_tmp = sorted(p.name for p in temp.iterdir())
    for fmt in ('md','html'):
        result = export(tree,fmt,True,user='manager',headers={'Host':'evil.example'})
        contents = archive(result); text='\n'.join(contents.values())
        check(len(contents)==3,fmt+' ZIP contains only root/visible/index')
        check(all(x not in text for x in (hidden1,hidden2,grand,'SECRET_CHILD','SECRET_GRAND','SECRET_CAPTION',files['hidden']['id'],files['hidden']['name'],'secret.invalid','evil.example')),fmt+' ZIP conceals forbidden branches, files, hosts')
        check('빠진 페이지 2개' in text,fmt+' counts denied branch roots, not grandchildren')
        check(site+'/community/p/'+outside in text and ('02-' in text or '02%2D' in text),fmt+' uses trusted outside URL and ZIP link')
        detail = audit(tree)['detail']
        check(set(detail['page_ids'])=={tree,visible} and detail['skipped']==2,fmt+' audit excludes hidden IDs')
        check(sorted(p.name for p in temp.iterdir())==old_tmp,fmt+' ZIP temporary file removed after success')
    sql("UPDATE community.pages SET visibility='internal' WHERE public_id IN ("+literal(tree)+','+literal(visible)+')')
    check(len(archive(export(tree,tree=True,user='admin')))==3,'admin internal tree still omits privileged-only children')
    names = page('../../etc/passwd')
    for title in ['a/b','같은 제목','같은 제목','한'*200,'CON']:
        page(title,parent=names)
    entries = archive(export(names,tree=True))
    check(all(not n.startswith('/') and '..' not in n and all(len(c)<=80 for c in n.split('/')) for n in entries),'ZIP paths sanitize title traversal and length')
    check(any(n.endswith('같은 제목-2.md') for n in entries),'ZIP duplicate titles retain numeric suffix')

    dbpage = page('행이 있는 문서')
    dbid = sql("INSERT INTO community.databases(page_id,created_by) VALUES((SELECT id FROM community.pages WHERE public_id="+literal(dbpage)+"),"+str(users['employee']['id'])+") RETURNING id").splitlines()[0]
    rowpage = page('SECRET_DB_ROW',parent=dbpage)
    sql("UPDATE community.pages SET database_id="+str(int(dbid))+",properties='{}'::jsonb WHERE public_id="+literal(rowpage))
    result = archive(export(dbpage,tree=True))
    check(len(result)==2 and 'SECRET_DB_ROW' not in '\n'.join(result.values()) and '행 1개는' in '\n'.join(result.values()),'database rows excluded with visible row count notice')

    large = page('201개 한도')
    sql("INSERT INTO community.pages(space_id,parent_page_id,title,doc,author_id,last_edited_by) SELECT p.space_id,p.id,'child-'||n,p.doc,p.author_id,p.last_edited_by FROM community.pages p CROSS JOIN generate_series(1,200) n WHERE p.public_id="+literal(large))
    result=export(large,tree=True,expected=400)
    check(result.json().get('code')=='too_large' and audit(large)['reason']=='too_large','201 pages fail before ZIP creation')
    bytesroot = page('20MB 한도')
    bigdoc={'schema':1,'blocks':[{'id':str(uuid.uuid4()),'type':'code','code':'x'*49000,'language':'plain'} for _ in range(16)]}
    sql('UPDATE community.pages SET doc='+literal(json.dumps(bigdoc))+'::jsonb WHERE public_id='+literal(bytesroot))
    sql("INSERT INTO community.pages(space_id,parent_page_id,title,doc,author_id,last_edited_by) SELECT p.space_id,p.id,'large-'||n,p.doc,p.author_id,p.last_edited_by FROM community.pages p CROSS JOIN generate_series(1,28) n WHERE p.public_id="+literal(bytesroot))
    result=export(bytesroot,tree=True,expected=400)
    check(result.json().get('code')=='too_large','rendered result over 20MB fails')
    check(sorted(p.name for p in temp.iterdir())==old_tmp,'temporary folder unchanged after both limits fail')
    output.joinpath('fixture-ids.json').write_text(json.dumps({'own':pid,'tree':tree,'visible':visible}))
    # Optional browser checks run while this isolated server and its sessions are alive.
    browser = Path(__file__).with_name('browser_8b.py')
    if browser.exists():
        subprocess.run(['python3',str(browser)],check=True,env=os.environ.copy())


try:
    run()
finally:
    output.joinpath('integration-results.json').write_text(json.dumps({'checks':checks,'passed':sum(x['pass'] for x in checks),'failed':sum(not x['pass'] for x in checks)},ensure_ascii=False,indent=2))
    print('HTTP_CHECKS='+str(len(checks)),flush=True)
