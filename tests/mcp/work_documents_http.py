#!/usr/bin/env python3
"""Real PHP/SQL/OAuth and local client acceptance. Owned disposable harness only."""
import copy
import hashlib
import json
import os
from pathlib import Path
import secrets
import sys
import uuid
from concurrent.futures import ThreadPoolExecutor
from threading import Barrier
import requests
from remote_http import Acceptance, guard

manifest = json.loads(Path(os.environ['FILE_RENAME_MANIFEST']).read_text())
instance = guard(manifest, os.environ['FILE_RENAME_FIXTURE_CONFIG'], os.environ.get('REMOTE_MCP_HARNESS'))
a = Acceptance(manifest, instance, Path(os.environ['FILE_RENAME_OUTPUT']))
sys.path.insert(0, str(instance / 'clients/mcp'))
from qaredi_mcp.client import QarediClient, QarediError


def block(text, **extra):
    return dict(id=str(uuid.uuid4()), type='paragraph', text=[{'t': text, 'm':[]}], **extra)


def page(title, **extra):
    return a.api('POST', '/pages', dict(title=title, **extra), expected=201)['page']['id']


def read(pid, user='employee'):
    return a.api('GET', '/work/documents/' + pid, user=user)


def update(pid, version, expected=200, user='employee', **extra):
    return a.api('PATCH', '/work/documents/' + pid, dict(doc_version=version, **extra), user=user, expected=expected)


def mint(scope, user='employee'):
    token = secrets.token_hex(24)
    a.sql("INSERT INTO api_tokens(user_id,name,token_hash,hint,created_by,scope,expires_at) VALUES("
          ":'uid','work fixture',:'digest','fixtur',:'uid',:'scope',now()+interval '1 hour')",
          uid=a.users[user]['id'], digest=hashlib.sha256(token.encode()).hexdigest(), scope=scope)
    return token


try:
    for user in a.users:
        a.api('GET', '/me/space', user=user)
    project = page('개발 프로젝트', database=True, preset='project')
    page('다른 프로젝트', database=True, preset='project')
    first = a.api('GET', '/work/projects?limit=1')
    second = a.api('GET', '/work/projects?limit=1&after_id=' + first['next_after_id'])
    a.check(len(first['items']) == len(second['items']) == 1 and first['items'][0]['id'] != second['items'][0]['id'], 'project keyset pagination')
    a.check(second['next_after_id'] is None, 'project pagination ends')
    a.check(a.api('GET', '/work/projects', user='other')['items'] == [], 'other account cannot discover private projects')
    a.sql("UPDATE users SET team_id=NULL WHERE id=:'uid'", uid=a.users['admin']['id'])
    a.check(a.api('GET', '/work/projects', user='admin')['items'] == [], 'admin override does not expose private projects')
    text_block = block('개발 대기')
    text_block['text'] = [{'t':'개발 ', 'm':{'bold':True}}, {'t':'대기', 'm':{'color':'red'}}]
    text_block['children'] = [block('자식 문단 보존')]
    table = dict(id=str(uuid.uuid4()), type='table', header_row=True, header_col=False,
                 col_widths=[200, 300], rows=[{'cells':[{'text':[{'t':'항목','m':[]}]}, {'text':[{'t':'설명', 'm':{'bold':True}}]}]}])
    doc = {'schema':1, 'blocks':[text_block, table, block('중복 중복')]}
    pid = page('기능 설명', parent_id=project, doc=doc)
    child = page('개발 기록', parent_id=project)
    listing = a.api('GET', '/work/documents?parent_id=' + project + '&limit=1')
    more = a.api('GET', '/work/documents?parent_id=' + project + '&limit=1&after_id=' + listing['next_after_id'])
    a.check({listing['items'][0]['id'], more['items'][0]['id']} == {pid,child}, 'children paginated under chosen parent only')
    image = dict(id=str(uuid.uuid4()), type='image', width='normal', file_ref='att:' + str(uuid.uuid4()))
    # An existing opaque attachment reference: no actual user file or production storage is used.
    doc['blocks'].append(image)
    a.sql("UPDATE community.pages SET doc=CAST(:'doc' AS jsonb) WHERE public_id=CAST(:'pid' AS uuid)", doc=json.dumps(doc), pid=pid)
    original = read(pid)
    a.check(original['doc'] == doc and original['can_edit'], 'original structured document and version available')
    change = dict(block_id=text_block['id'], old_text='개발 대기', new_text='개발 완료')
    result = update(pid, original['doc_version'], replacements=[change], append=[{'type':'heading','text':'개발 기록','level':2}, {'text':'검사 통과 / 운영 미반영'}])
    current = read(pid)
    a.check(result['doc_version'] == original['doc_version'] + 1, 'one atomic version advance')
    a.check(current['doc']['blocks'][1:4] == doc['blocks'][1:4], 'table widths/marks, duplicate text and image preserved exactly')
    a.check(current['doc']['blocks'][0]['children'] == text_block['children'], 'nested sibling content preserved')
    a.check(current['doc']['blocks'][0]['text'][0]['m'] == {'bold':True}, 'replacement inherits insertion-point formatting')
    a.check(a.sql("SELECT count(*) FROM community.page_versions v JOIN community.pages p ON p.id=v.page_id WHERE p.public_id=CAST(:'pid' AS uuid)", pid=pid) == '1', 'previous document snapshot retained')
    update(pid, original['doc_version'], expected=409, append=[{'text':'중복 전송 금지'}])
    a.check(read(pid) == current, 'stale append leaves document unchanged')
    for body, status in [
        ({'replacements':[dict(block_id=doc['blocks'][2]['id'], old_text='중복', new_text='변경')]},409),
        ({'replacements':[dict(block_id=str(uuid.uuid4()), old_text='x', new_text='y')]},409),
        ({'replacements':[dict(block_id=table['id'], old_text='항목', new_text='x')]},400),
        ({'append':[{'text':'x', 'file_ref':'att:'+str(uuid.uuid4())}]},400),
        ({'append':[{'text':'x'*10001}]},400),
        ({'append':[{'text':'x', 'type':'heading', 'level':False}]},400),
        ({'append':{'text':'x'}},400),
        ({'visibility':'internal', 'append':[{'text':'x'}]},400),
        ({'append':[]},400),
    ]:
        update(pid, current['doc_version'], expected=status, **body)
        a.check(read(pid) == current, 'rejected mutation preserves entire document')
    a.api('PATCH','/work/documents/'+pid, {'append':[{'text':'x'}]}, expected=400)
    a.api('PATCH','/work/documents/'+pid, {'doc_version':True,'append':[{'text':'x'}]}, expected=400)
    a.request('PATCH', '/community/api/work/documents/' + pid, user='employee', csrf=False,
              json={'doc_version':current['doc_version'], 'append':[{'text':'x'}]}, expected=403)
    for user in ('other','admin','guest'):
        a.api('GET','/work/documents/'+pid, user=user, expected=404)
    a.api('PATCH','/work/documents/'+pid, {'doc_version':current['doc_version'], 'append':[{'text':'x'}]}, user='guest', expected=403)
    # Existing sharing gates: read share can inspect but cannot write, edit share can write.
    a.sql("INSERT INTO community.page_shares(page_id,user_id,level,granted_by) SELECT id,:'uid','view',:'by' FROM community.pages WHERE public_id=CAST(:'pid' AS uuid)", uid=a.users['manager']['id'], by=a.users['employee']['id'], pid=project)
    a.check(not read(pid,'manager')['can_edit'], 'inherited read share is read-only')
    update(pid, current['doc_version'], expected=403, user='manager', append=[{'text':'x'}])
    a.sql("UPDATE community.page_shares SET level='edit' WHERE user_id=:'uid'", uid=a.users['manager']['id'])
    a.check(read(pid,'manager')['can_edit'], 'inherited edit share allows editing')
    update(pid,current['doc_version'],user='manager',append=[{'text':'공동 개발 기록'}])
    # Local MCP client talks to the real PHP token endpoints, no mocked HTTP.
    token = mint('community.read+community.write')
    local = QarediClient(a.site, token)
    a.check(len(local.list_work_projects()['items']) == 2, 'local client project lookup')
    a.check(len(local.list_work_documents(project)['items']) == 2, 'local client child lookup')
    lr = local.read_work_document(a.site+'/community/p/'+pid)
    local.update_work_document(pid, lr['doc_version'], append=[{'text':'로컬 MCP 기록'}])
    reader = QarediClient(a.site, mint('community.read'))
    a.check(reader.read_work_document(pid)['doc_version'] == lr['doc_version']+1, 'read token sees updated version')
    try:
        reader.update_work_document(pid, lr['doc_version']+1, append=[{'text':'x'}])
        a.check(False,'read token must reject write')
    except QarediError as exc:
        a.check(exc.status==403,'local read token cannot update')
    a.request('PATCH','/community/api/pages/'+pid, headers={'X-API-Token':token}, json={'doc_version':1,'title':'x'}, expected=401)
    a.request('POST','/community/api/pages', headers={'X-API-Token':token}, json={'title':'x','parent_id':project}, expected=400)
    # Real OAuth approval, catalog filtering and structured failures.
    client = a.register()
    writer = a.grant(client, scope='memories.read work.read work.write')['access_token']
    ro = a.grant(client, scope='memories.read work.read')['access_token']
    old = a.grant(client, scope='memories.read')['access_token']
    tools = a.rpc(writer,'tools/list')['result']['tools']
    a.check(len([t for t in tools if '_work_' in t['name']]) == 5,'approved work tools advertised')
    a.check(not any('_work_' in t['name'] for t in a.rpc(old,'tools/list')['result']['tools']), 'existing scopes do not gain work access')
    a.tool(old,'qaredi_read_work_document',{'page_id':pid},error=403)
    a.tool(ro,'qaredi_update_work_document',{'page_id':pid,'doc_version':1,'append':[{'text':'x'}]},error=403)
    a.check(len(a.tool(writer,'qaredi_list_work_projects')['items']) == 2, 'OAuth project lookup')
    a.check(len(a.tool(writer,'qaredi_list_work_documents',{'parent_id':project})['items']) == 2, 'OAuth child lookup')
    wr = a.tool(writer,'qaredi_read_work_document',{'page_id':pid})
    a.tool(writer,'qaredi_update_work_document',{'page_id':pid,'doc_version':wr['doc_version'],'append':[{'text':'웹 MCP 개발 기록'}]},user='other')
    a.tool(writer,'qaredi_update_work_document',{'page_id':pid,'doc_version':wr['doc_version'],'append':[{'text':'재전송'}]},error=409)
    # Canonical SaveDoc validation failure must remain a JSON-RPC tool error, with no partial commit.
    a.tool(writer,'qaredi_update_work_document',{'page_id':pid,'doc_version':read(pid)['doc_version'],
           'replacements':[{'block_id':text_block['id'],'old_text':'개발 완료','new_text':''}]})
    a.check(a.sql("SELECT actor_id FROM audit_logs WHERE action='page.update' ORDER BY id DESC LIMIT 1") == str(a.users['employee']['id']), 'OAuth actor wins over incidental other-account cookie')
    # Concurrent appends with one version: exactly one commits, including on separate PHP workers.
    version = read(pid)['doc_version']; barrier=Barrier(2)
    def race(port):
        with requests.Session() as http:
            http.trust_env=False
            barrier.wait()
            return http.patch('http://127.0.0.1:'+str(port)+'/community/api/work/documents/'+pid,
                headers={'X-API-Token':token,'User-Agent':'tbox-review/2026-10-16'},
                json={'doc_version':version,'append':[{'text':'동시 기록'}]},timeout=35).status_code
    with ThreadPoolExecutor(max_workers=2) as pool:
        statuses=list(pool.map(race,[8768,8769]))
    a.check(sorted(statuses)==[200,409] and read(pid)['doc_version']==version+1,'concurrent requests commit once and reject stale writer')
    a.api('DELETE','/pages/'+child, {})
    a.tool(writer,'qaredi_read_work_document',{'page_id':child},error=404)
    a.check(len(local.list_work_documents(project)['items'])==1,'deleted documents excluded')
    # SaveDoc's own validator must fail through MCP, not emit an unwrapped HTTP error.
    crowded = block('unused')
    crowded['text'] = [{'t':'replace-me' if i == 0 else str(i), 'm':[]} for i in range(100)]
    crowded_id = page('서식 한도 검증', doc={'schema':1,'blocks':[crowded]})
    crowded_before = read(crowded_id)
    a.tool(writer,'qaredi_update_work_document',{'page_id':crowded_id,'doc_version':1,
           'replacements':[{'block_id':crowded['id'],'old_text':'replace-me','new_text':'x'*10000}]},error=400)
    a.check(read(crowded_id)==crowded_before,'canonical validation failure rolls back whole MCP transaction')
    spaces = a.api('GET','/spaces')['spaces']
    team_id = next(s['id'] for s in spaces if s['kind']=='team')
    team_doc = page('팀 개발 기록', space_id=team_id)
    a.check(read(team_doc,'manager')['can_edit'],'team member edit rights preserved')
    a.api('GET','/work/documents/'+team_doc,user='other',expected=404)
    a.sql("UPDATE community.spaces SET is_active=false WHERE id=:'sid'",sid=team_id)
    a.api('GET','/work/documents/'+team_doc,expected=404)
    a.check(not any(x['id']==team_doc for x in local.list_work_projects()['items']), 'inactive spaces excluded')
    a.sql("DELETE FROM community.page_shares WHERE user_id=:'uid'",uid=a.users['manager']['id'])
    a.api('GET','/work/documents/'+pid,user='manager',expected=404)
    a.check(True,'revoked inherited share rejected on next request')
    a.sql("UPDATE api_tokens SET revoked_at=now() WHERE token_hash=:'digest'",digest=hashlib.sha256(token.encode()).hexdigest())
    try:
        local.read_work_document(pid)
        a.check(False,'revoked token must fail')
    except QarediError as exc:
        a.check(exc.status==401,'revoked local token rejected immediately')


finally:
    a.output.mkdir(parents=True,exist_ok=True)
    (a.output/'work-documents-results.json').write_text(json.dumps(a.checks,ensure_ascii=False,indent=2))
    print('RESULT',sum(c['pass'] for c in a.checks),'PASS',sum(not c['pass'] for c in a.checks),'FAIL',flush=True)
