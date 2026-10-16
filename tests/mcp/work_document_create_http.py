#!/usr/bin/env python3
"""Project-task creation acceptance, on the disposable PHP/Postgres harness only."""
import hashlib,json,os,secrets,sys,uuid
from pathlib import Path
from concurrent.futures import ThreadPoolExecutor
from threading import Barrier
import requests
from remote_http import Acceptance,guard
manifest=json.loads(Path(os.environ['FILE_RENAME_MANIFEST']).read_text())
instance=guard(manifest,os.environ['FILE_RENAME_FIXTURE_CONFIG'],os.environ.get('REMOTE_MCP_HARNESS'))
a=Acceptance(manifest,instance,Path(os.environ['FILE_RENAME_OUTPUT']))
sys.path.insert(0,str(instance/'clients/mcp'))
from qaredi_mcp.client import QarediClient,QarediError

def page(title,**extra):
    return a.api('POST','/pages',dict(title=title,**extra),expected=201)['page']['id']
def read(pid,user='employee'):
    return a.api('GET','/work/documents/'+pid,user=user)
def update(pid,version,**extra):
    return a.api('PATCH','/work/documents/'+pid,dict(doc_version=version,**extra))
def mint(scope,user='employee'):
    token=secrets.token_hex(24)
    a.sql("INSERT INTO api_tokens(user_id,name,token_hash,hint,created_by,scope,expires_at) VALUES(:'uid','work fixture',:'digest','fixtur',:'uid',:'scope',now()+interval '1 hour')",uid=a.users[user]['id'],digest=hashlib.sha256(token.encode()).hexdigest(),scope=scope)
    return token
try:
    for user in a.users:a.api('GET','/me/space',user=user)
    project=page('작업 기록 프로젝트',database=True,preset='project')
    pid=page('하위 작업',parent_id=project)
    reader=QarediClient(a.site,mint('community.read'))
    client=a.register()
    writer=a.grant(client,scope='memories.read work.read work.write')['access_token']
    ro=a.grant(client,scope='memories.read work.read')['access_token']
    old=a.grant(client,scope='memories.read')['access_token']
    # User-designated project task creation: ordinary route and OAuth share the same domain operation.
    local = QarediClient(a.site, mint('community.read+community.write'))
    sections = local.read_work_document(project)['project']['sections']
    a.check([s['value'] for s in sections] == ['00. 준비','01. 진행','02. 마무리'], 'read project current section values and display names')
    parent_before = read(project)
    task_args = dict(project_id=project, request_id=str(uuid.uuid4()), title='완료 기능 설명',
                     blocks=[{'type':'heading','text':'기능과 사용자 가치','level':2},
                             {'text':'실제 구현·시험·운영 반영을 구분한 설명'}], status='done', section='02. 마무리')
    created = local.create_work_document(**task_args)
    a.check(created['created'] and created['id']==task_args['request_id'] and '?task=' in created['url'], 'local creates a direct project task with stable identity')
    a.check(read(project)==parent_before, 'task creation preserves the full project document')
    a.check(read(created['id'])['doc']['blocks'][1]['text'][0]['t']==task_args['blocks'][1]['text'], 'new task contains the requested text')
    saved = a.api('GET','/pages/'+created['id'])['page']
    a.check(saved['status']=='done' and a.sql("SELECT properties->>'section' FROM community.pages WHERE public_id=CAST(:'pid' AS uuid)",pid=created['id'])=='02. 마무리', 'initial task status and section are saved')
    a.check(saved['visibility']==a.api('GET','/pages/'+project)['page']['visibility'], 'new task inherits the project visibility')
    a.check(a.sql("SELECT actor_id FROM audit_logs WHERE action='page.create' ORDER BY id DESC LIMIT 1")==str(a.users['employee']['id']), 'task audit belongs to authenticated token user')
    replay = local.create_work_document(**task_args)
    a.check(not replay['created'] and replay['id']==created['id'], 'identical creation retry returns existing task')
    before_count = a.sql('SELECT count(*) FROM community.pages')
    for changes in [dict(title='다른 요청'),dict(status='todo'),dict(blocks=[{'text':'다른 본문'}]),dict(section='01. 진행')]:
        try:
            local.create_work_document(**dict(task_args,**changes))
            a.check(False,'changed request must conflict')
        except QarediError as exc:
            a.check(exc.status==409,'changed retry conflicts without overwriting')
    a.check(a.sql('SELECT count(*) FROM community.pages')==before_count,'retries create no duplicates')
    for extra in [dict(visibility='internal'),dict(author_id=a.users['other']['id']),dict(space_id=1),dict(database=True),dict(parent_id=project)]:
        a.api('POST','/work/documents',dict(task_args,request_id=str(uuid.uuid4()),**extra),expected=400)
    for changes in [dict(title='x\ny'),dict(title='x'*201),dict(blocks=[]),dict(blocks=[{'text':'x'}]*101),
                    dict(blocks=[{'text':'x'*10001}]),dict(blocks=[{'type':'image','text':'x'}]),
                    dict(blocks=[{'type':'heading','text':'x','level':True}]),dict(blocks=[{'text':'x','file_ref':'att:'+str(uuid.uuid4())}]),
                    dict(section='없는 섹션'),dict(status='invalid'),dict(request_id='bad')]:
        a.api('POST','/work/documents',{**task_args,'request_id':str(uuid.uuid4()),**changes},expected=400)
    a.request('POST','/community/api/work/documents',user='employee',csrf=False,json=dict(task_args,request_id=str(uuid.uuid4())),expected=403)
    for user,expected in [('guest',403),('other',404),('admin',404)]:
        a.api('POST','/work/documents',dict(task_args,request_id=str(uuid.uuid4())),user=user,expected=expected)
    for bad_parent,expected in [(pid,400),(page('일반 문서'),400),(str(uuid.uuid4()),404)]:
        a.api('POST','/work/documents',dict(task_args,project_id=bad_parent,request_id=str(uuid.uuid4())),expected=expected)
    try:
        reader.create_work_document(**dict(task_args,request_id=str(uuid.uuid4())))
        a.check(False,'read token cannot create')
    except QarediError as exc:
        a.check(exc.status==403,'read token cannot create project tasks')
    a.sql("INSERT INTO community.page_shares(page_id,user_id,level,granted_by) SELECT id,:'uid','view',:'by' FROM community.pages WHERE public_id=CAST(:'pid' AS uuid)", uid=a.users['manager']['id'],by=a.users['employee']['id'],pid=project)
    a.api('POST','/work/documents',dict(task_args,request_id=str(uuid.uuid4())),user='manager',expected=403)
    a.sql("UPDATE community.page_shares SET level='edit' WHERE user_id=:'uid'",uid=a.users['manager']['id'])
    shared = a.api('POST','/work/documents',dict(task_args,request_id=str(uuid.uuid4())),user='manager',expected=201)
    a.check(read(shared['id'],'manager')['can_edit'], 'inherited edit share can create project tasks')
    a.sql("DELETE FROM community.page_shares WHERE user_id=:'uid'",uid=a.users['manager']['id'])
    a.api('POST','/work/documents',dict(task_args,request_id=str(uuid.uuid4())),user='manager',expected=404)
    oauth_args=dict(task_args,request_id=str(uuid.uuid4()),title='OAuth 프로젝트 작업')
    oauth = a.tool(writer,'qaredi_create_work_document',oauth_args,user='other')
    a.check(oauth['created'] and read(oauth['id'])['can_edit'], 'OAuth creates under grant account, ignoring other-account cookie')
    a.tool(ro,'qaredi_create_work_document',dict(task_args,request_id=str(uuid.uuid4())),error=403)
    a.tool(old,'qaredi_create_work_document',dict(task_args,request_id=str(uuid.uuid4())),error=403)
    a.tool(writer,'qaredi_create_work_document',dict(task_args,request_id=str(uuid.uuid4()),section='없음'),error=400)
    # Both workers serialize on the same parent: exactly one create and one safe replay.
    race_args=dict(task_args,request_id=str(uuid.uuid4()),title='동시 생성')
    race_token=mint('community.read+community.write');barrier=Barrier(2)
    def race_create(port):
        with requests.Session() as http:
            http.trust_env=False;barrier.wait()
            return http.post('http://127.0.0.1:'+str(port)+'/community/api/work/documents',
                headers={'X-API-Token':race_token,'User-Agent':'tbox-review/2026-10-17'},json=race_args,timeout=35).status_code
    with ThreadPoolExecutor(max_workers=2) as pool:
        statuses=list(pool.map(race_create,[8768,8769]))
    a.check(sorted(statuses)==[200,201], 'concurrent creation commits once and returns the same task')
    a.check(a.sql("SELECT count(*) FROM community.pages WHERE public_id=CAST(:'pid' AS uuid)",pid=race_args['request_id'])=='1','one row for concurrent request UUID')
    latest=read(created['id'])
    update(created['id'],latest['doc_version'],append=[{'text':'사용자가 추가한 기록 보존'}])
    a.api('POST','/work/documents',task_args,expected=409)
    a.check(read(created['id'])['doc_version']==latest['doc_version']+1,'retry never overwrites subsequently edited task')
    a.api('DELETE','/pages/'+created['id'],{})
    a.api('POST','/work/documents',task_args,expected=409)
    a.check(True,'creation never resurrects a deleted task')
    from playwright.sync_api import sync_playwright
    with sync_playwright() as playwright:
        browser=playwright.chromium.launch()
        for width in [390,1280]:
            context=browser.new_context(viewport={'width':width,'height':900},user_agent='tbox-review/2026-10-17')
            context.add_cookies([{'name':'__Host-tbox_session','value':a.users['employee']['session'],
                                 'domain':'127.0.0.1','path':'/','secure':True,'httpOnly':True}])
            browser_page=context.new_page();errors=[];browser_page.on('pageerror',lambda e:errors.append(str(e)))
            browser_page.goto(a.site+'/community/p/'+project,wait_until='networkidle')
            later=browser_page.get_by_role('button',name='나중에 볼게요',exact=True)
            if later.is_visible():later.click()
            row=browser_page.locator('[data-task="'+oauth['id']+'"] .task-row__title');row.wait_for()
            a.check(row.is_visible(),'created task visible in project list '+str(width))
            row.click()
            browser_page.locator('#task-pane').get_by_text('실제 구현·시험·운영 반영을 구분한 설명',exact=True).wait_for()
            a.check(True,'project list opens the actual task body '+str(width))
            a.check(browser_page.evaluate('document.documentElement.scrollWidth<=innerWidth+1'),'task project no horizontal overflow '+str(width))
            a.check(not errors,'task project no JS errors '+str(width))
            browser_page.screenshot(path=str(a.output/('task-record-'+str(width)+'.png')),full_page=True)
            context.close()
        browser.close()
finally:
    a.output.mkdir(parents=True,exist_ok=True)
    (a.output/'work-document-create-results.json').write_text(json.dumps(a.checks,ensure_ascii=False,indent=2))
    print('RESULT',sum(c['pass'] for c in a.checks),'PASS',sum(not c['pass'] for c in a.checks),'FAIL',flush=True)
