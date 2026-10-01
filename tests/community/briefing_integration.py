#!/usr/bin/env python3
"""C:T-460..465. Fresh synthetic database, real HTTP, exact response privacy checks."""
import json
import os
from pathlib import Path
import subprocess
import time
import uuid
import requests

manifest = json.loads(Path(os.environ['COMMUNITY_EXPORT_MANIFEST']).read_text())
assert manifest['database'].startswith('tbox_export_test_')
root = Path(manifest['instance'])
output = Path(os.environ['COMMUNITY_EXPORT_OUTPUT'])
data = json.loads(Path(manifest['credentials_file']).read_text())
users = data['users']
site = manifest['base_url'].removesuffix('/tbox')
checks = []


def check(ok, name):
    checks.append({'pass': bool(ok), 'name': name})
    print(('PASS ' if ok else 'FAIL ') + name, flush=True)
    if not ok:
        raise AssertionError(name)


def call(path, user='employee', expected=200, method='GET', body=None):
    hs = {'User-Agent': 'tbox-review/' + time.strftime('%Y-%m-%d')}
    if user:
        hs.update(Cookie='__Host-tbox_session=' + users[user]['session'], **{'X-CSRF-Token': users[user]['csrf']})
    response = requests.request(method, site + path, json=body, headers=hs, timeout=30, allow_redirects=False)
    check(response.status_code == expected, method + ' ' + path + ' HTTP ' + str(expected))
    return response


def sql(statement):
    env = {k: v for k, v in os.environ.items() if not k.startswith('PG')}
    env['PGPASSFILE'] = str(Path.home() / '.config/qaredi/pgpass-testdb')
    result = subprocess.run(['psql', '-h', '127.0.0.1', '-p', '5432', '-U', 'tbox_testdb', '-w', '-X', '-At',
                             '-d', manifest['database'], '-v', 'ON_ERROR_STOP=1'], env=env, input=statement.encode(),
                            stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=True)
    return result.stdout.decode().strip()


def literal(value):
    return "'" + str(value).replace("'", "''") + "'"


def doc(text):
    return {'schema': 1, 'blocks': [{'id': str(uuid.uuid4()), 'type': 'paragraph', 'text': [{'t': text, 'm': {}}]}]}


def page(title, status=None, due=None, owner='employee', visibility='private', document=None, space=None):
    uid = users[owner]['id']
    if space is None:
        space = sql("SELECT id FROM community.spaces WHERE kind='personal' AND owner_user_id=" + str(uid))
        if not space:
            space = sql("INSERT INTO community.spaces(kind,owner_user_id,name,access_level) VALUES('personal',"
                        + str(uid) + ",'검증 공간','internal') RETURNING id").splitlines()[0]
    pid = str(uuid.uuid4())
    values = [literal(pid), str(int(space)), literal(title), literal(json.dumps(document or doc('검증용 본문'), ensure_ascii=False)) + '::jsonb',
              literal(visibility), str(uid), str(uid), literal(status) if status else 'NULL', literal(due) if due else 'NULL']
    sql('INSERT INTO community.pages(public_id,space_id,title,doc,visibility,author_id,last_edited_by,status,due_date) VALUES('
        + ','.join(values) + ')')
    return pid


def all_items(response):
    return response['top'] + response['missed'] + response['others']


def snapshot():
    # Hash complete rows, not only counts: reading cannot silently modify a read_at/document either.
    names = sql("SELECT tablename FROM pg_tables WHERE schemaname='community' ORDER BY tablename").splitlines()
    return {n: sql('SELECT count(*)::text || \':\' || coalesce(md5(string_agg(row_to_json(t)::text,\'\' ORDER BY row_to_json(t)::text)),\'\') FROM community."'
                   + n.replace('"', '""') + '" t') for n in names}


def run():
    today = sql("SELECT (now() AT TIME ZONE 'Asia/Seoul')::date")
    yesterday = sql("SELECT (now() AT TIME ZONE 'Asia/Seoul')::date - 1")
    older = sql("SELECT (now() AT TIME ZONE 'Asia/Seoul')::date - 3")
    a = page('마감이 지난 구매 검토', 'todo', older)
    b = page('어제 마감한 월 보고서', 'doing', yesterday)
    c = page('오늘 보낼 자료', 'todo', today)
    d = page('오늘 확인할 명세', 'todo', today)
    review = page('월마감 대사 검토', 'review')
    done = page('이미 끝난 문서', 'done', older)
    hold = page('보류한 문서', 'hold', older)
    secret_title = '남의 비공개 문서 표식'
    secret = page(secret_title, 'review', older, 'other')
    team_secret = page('다른 팀의 검토 표식', 'review', older, 'other', 'team')
    deleted = page('지운 문서 표식', 'todo', older)
    sql('UPDATE community.pages SET deleted_at=now() WHERE public_id=' + literal(deleted))
    check_doc = doc('')
    check_doc['blocks'][0] = {'id': str(uuid.uuid4()), 'type': 'to_do', 'checked': False, 'text': [{'t': '첨부 근거 확인', 'm': {}}]}
    checkbox = page('보고서 체크리스트', document=check_doc)
    exp = page('검증 기한 지난 절차서')
    sql('UPDATE community.pages SET verified_by=' + str(users['employee']['id'])
        + ", verified_at=now()-interval '3 days', verify_expires_at=now()-interval '1 day' WHERE public_id=" + literal(exp))
    # Mention three visible unresolved threads, yesterday; one more already answered.
    comment_page = page('답변할 업무 댓글', visibility='internal')
    comment_ids = []
    for index in range(4):
        cid = str(uuid.uuid4())
        sql('INSERT INTO community.comments(public_id,page_id,body,author_id,created_at) VALUES('
            + literal(cid) + ',(SELECT id FROM community.pages WHERE public_id=' + literal(comment_page)
            + "),'확인 부탁드립니다'," + str(users['other']['id']) + ",now()-interval '1 day')")
        sql("INSERT INTO community.notifications(user_id,kind,page_id,comment_id,actor_id,created_at) SELECT "
            + str(users['employee']['id']) + ",'mention',page_id,id," + str(users['other']['id'])
            + ",now()-interval '1 day' FROM community.comments WHERE public_id=" + literal(cid))
        comment_ids.append(cid)
    sql('INSERT INTO community.comments(page_id,parent_comment_id,body,author_id) SELECT page_id,id,\'답변 완료\','
        + str(users['employee']['id']) + ' FROM community.comments WHERE public_id=' + literal(comment_ids[-1]))
    # A notification cannot confer access to the underlying private page.
    hidden_c = str(uuid.uuid4())
    sql('INSERT INTO community.comments(public_id,page_id,body,author_id) SELECT ' + literal(hidden_c)
        + ",id,'숨긴 본문'," + str(users['other']['id']) + ' FROM community.pages WHERE public_id=' + literal(secret))
    sql("INSERT INTO community.notifications(user_id,kind,page_id,comment_id,actor_id) SELECT "
        + str(users['employee']['id']) + ",'mention',page_id,id," + str(users['other']['id'])
        + ' FROM community.comments WHERE public_id=' + literal(hidden_c))
    board = sql("INSERT INTO community.spaces(kind,name,access_level,notice,board_kind) VALUES('board','검증 공지','internal',true,'notice') RETURNING id").splitlines()[0]
    notice = page('어제 공지 확인', owner='other', visibility='private', space=board)
    sql("UPDATE community.pages SET created_at=now()-interval '1 day' WHERE public_id=" + literal(notice))
    qa_board = sql("INSERT INTO community.spaces(kind,name,access_level,board_kind) VALUES('board','검증 질문','internal','qa') RETURNING id").splitlines()[0]
    question = page('해결하지 못한 질문', visibility='private', space=qa_board)
    recurrence = page('오늘의 반복 업무')
    rule = sql("INSERT INTO community.automations(public_id,owner_user_id,kind,name,trigger,actions) VALUES(gen_random_uuid(),"
               + str(users['employee']['id']) + ",'recurring','검증 반복','{}','[{}]') RETURNING id").splitlines()[0]
    sql("INSERT INTO community.automation_runs(public_id,automation_id,run_by,trigger_kind,dry_run,status,steps,created_page_ids) SELECT gen_random_uuid(),"
        + rule + ',' + str(users['employee']['id']) + ",'recurring',false,'ok','[]',ARRAY[id] FROM community.pages WHERE public_id=" + literal(recurrence))
    sql("UPDATE files SET scan_status='pending' WHERE id=" + str(data['files']['main']['internal_id']))
    sql("INSERT INTO share_links(token_hash,file_id,created_by,expires_at,max_downloads) VALUES('" + 'c' * 64 + "',"
        + str(data['files']['main']['internal_id']) + ',' + str(users['employee']['id']) + ",now()+interval '3 hours',10)")
    baseline = snapshot()
    response = call('/community/api/briefing')
    result = response.json()
    items = all_items(result)
    ids = [i['key'] for i in result['top']]
    check(len(ids) == 3 and set(ids[:2]) == {'page:' + a, 'page:' + b} and ids[2] in {'page:' + c, 'page:' + d}, 'C:T-461 priority overdue then today; top capped three')
    check(len(result['missed']) == 2 and all(i['missed'] for i in result['missed']), 'C:T-462 yesterday is capped two')
    check(len({i['key'] for i in items}) == len(items), 'one action appears only once across sections')
    check(all(v not in response.text for v in (secret, secret_title, team_secret, hidden_c, deleted, done, hold, comment_ids[-1])), 'C:T-460 denied/resolved/deleted/done/hold sources absent from raw response')
    kinds = {i['kind'] for i in items}
    check({'review', 'mention', 'verify_expired', 'unchecked', 'notice', 'question', 'recurring', 'pending_file', 'expiring_share'} <= kinds, 'all source categories collected from real rows')
    check('token_hash' not in response.text and 'c' * 64 not in response.text, 'share secrets never leave SQL boundary')
    for user in ('other', 'admin'):
        raw = call('/community/api/briefing', user=user).text
        check(a not in raw and '마감이 지난 구매 검토' not in raw and checkbox not in raw, 'C:T-460 ' + user + ' cannot discover another private page')
    call('/community/api/briefing', user=None, expected=401)
    call('/community/today', user=None, expected=302)
    empty = call('/community/api/briefing', user='guest').json()
    check(empty['total'] == 0, 'empty read-only guest briefing is valid')
    html = call('/community/today').text
    check('briefing.js' in html and 'community/editor.js' not in html, 'Today uses independent read-only startup')
    launch = call('/')
    check('id="launcher-briefing"' in launch.text and '오늘 ' + str(result['count']) + '건' in launch.text and 'no-store' in launch.headers.get('Cache-Control', ''), 'C:T-464 launcher count is SSR and no-store')
    check(all(i['title'] not in launch.text for i in items), 'launcher contains count only, no source titles')
    check('id="launcher-briefing"' not in call('/', user=None).text, 'prelogin launcher has no briefing or data query')
    check(snapshot() == baseline, 'C:T-465 API, Today, launcher do not insert or change any community rows')
    sql('REVOKE SELECT ON community.automation_runs FROM tbox_app')
    failed = call('/')
    check('id="launcher-briefing"' not in failed.text, 'C:T-464 failed community query omits only count; launcher remains 200')
    sql('GRANT SELECT ON community.automation_runs TO tbox_app')
    output.joinpath('fixture-ids.json').write_text(json.dumps({'check': checkbox, 'block': check_doc['blocks'][0]['id']}))
    subprocess.run(['python3', str(Path(__file__).with_name('browser_9d.py'))], check=True, env=os.environ.copy())
    events = sql('SELECT count(*) FROM community.checklist_events WHERE page_id=(SELECT id FROM community.pages WHERE public_id=' + literal(checkbox) + ')')
    checked = sql("SELECT doc->'blocks'->0->>'checked' FROM community.pages WHERE public_id=" + literal(checkbox))
    check(events == '1' and checked == 'true', 'C:T-463 browser check updates actual document and exactly one event')


try:
    run()
finally:
    output.joinpath('http-results.json').write_text(json.dumps(checks, ensure_ascii=False, indent=2))
