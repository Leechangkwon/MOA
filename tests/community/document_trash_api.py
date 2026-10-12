#!/usr/bin/env python3
"""Document trash capabilities and unchanged delete/restore contracts, empty DB only.

Run through memories_harness.py --port 8818 --suite document_trash_api.py.
No production records, operational HTTP or external services are used.
"""
from pathlib import Path
import json
import os
import subprocess
import time
import uuid

import requests

manifest = json.loads(Path(os.environ['FILE_RENAME_MANIFEST']).read_text())
root = Path(manifest['instance'])
out = Path(os.environ['FILE_RENAME_OUTPUT'])
assert str(root).startswith('/tmp/qaredi-stage13-')
assert manifest['database'].startswith('tbox_memories_test_8818_')
site = manifest['base_url'].removesuffix('/tbox')
assert site == 'http://127.0.0.1:8818'
users = json.loads(Path(manifest['credentials_file']).read_text())['users']
checks = []


def check(ok, name):
    checks.append({'name': name, 'pass': bool(ok)})
    print(('PASS ' if ok else 'FAIL ') + name, flush=True)
    if not ok:
        raise AssertionError(name)


def sql(query):
    env = {k: v for k, v in os.environ.items() if not k.startswith('PG')}
    env['PGPASSFILE'] = str(Path.home() / '.config/qaredi/pgpass-testdb')
    result = subprocess.run(['psql', '-h', '127.0.0.1', '-U', 'tbox_testdb', '-w', '-X', '-qAt',
                             '-d', manifest['database'], '-v', 'ON_ERROR_STOP=1'],
                            env=env, input=query, text=True, capture_output=True, check=True)
    return result.stdout.strip()


def lit(value):
    return "'" + str(value).replace("'", "''") + "'"


def request(method, path, body=None, user='employee', status=200, csrf=True):
    headers = {'User-Agent': 'tbox-review/' + time.strftime('%Y-%m-%d')}
    if user:
        headers['Cookie'] = '__Host-tbox_session=' + users[user]['session']
        if csrf:
            headers['X-CSRF-Token'] = users[user]['csrf']
    response = requests.request(method, site + '/community' + path, json=body,
                                headers=headers, timeout=35, allow_redirects=False)
    check(response.status_code == status, method + ' ' + path + ' status ' + str(response.status_code) + '/' + str(status))
    return response.json()


def new_page(title, user='employee', **extra):
    return request('POST', '/api/pages', dict(title=title, **extra), user, 201)['page']['id']


def rows(path, user='employee'):
    result = request('GET', path, user=user)
    check(all(type(p.get('can_delete')) is bool for p in result['pages']),
          path + ' returns a strict can_delete boolean on every row for ' + user)
    return {p['id']: p for p in result['pages']}


def capability(path, pid, expected, user='employee'):
    found = rows(path, user)
    check(pid in found and found[pid]['can_delete'] is expected,
          path + ' delete capability ' + str(expected) + ' for ' + user)
    return found[pid]


def remember(pid, user):
    request('GET', '/api/pages/' + pid, user=user)
    request('PUT', '/api/pages/' + pid + '/favorite', {}, user)


def projects(user, limit=100, progress=True):
    # Real production helper, same app DB account and current user row as the SSR shell.
    code = ("require 'src/bootstrap.php';require 'src/community/routes.php';"
            "$u=Db::instance()->run('SELECT * FROM users WHERE id=:id',[':id'=>" + str(users[user]['id']) + "])->fetch();"
            'echo json_encode(community_work_projects($u,' + str(limit) + ',false,' + ('true' if progress else 'false') + '));')
    result = subprocess.run(['php', '-r', code], cwd=root, text=True,
                            capture_output=True, check=True, timeout=40)
    data = json.loads(result.stdout)
    check(all(type(p.get('can_delete')) is bool for p in data['items']),
          'project helper strict delete capabilities for ' + user)
    return data


def snapshot():
    # Compare all community tables plus audit. Authentication session access timestamps are unrelated.
    tables = sql("SELECT tablename FROM pg_tables WHERE schemaname='community' ORDER BY tablename").splitlines()
    return {table: sql('SELECT md5(coalesce(string_agg(j::text,\'\' ORDER BY j::text),\'\')) '
                       'FROM (SELECT to_jsonb(t) AS j FROM community.' + table + ' t) q') for table in tables} | {
        'audit': sql("SELECT count(*) FROM audit_logs")}


def document_snapshot():
    # Deletion is allowed to change only these four lifecycle columns on page rows.
    return sql("SELECT md5(string_agg((to_jsonb(p)-'deleted_at'-'deleted_by'-'purge_after'-'updated_at')::text,'' ORDER BY id)) FROM community.pages p")


def audit_count(action, result, pid):
    return int(sql('SELECT count(*) FROM audit_logs WHERE action=' + lit(action) +
                   ' AND result=' + lit(result) + ' AND target_id=(SELECT id FROM community.pages WHERE public_id=' + lit(pid) + ')'))


try:
    for user in users:
        request('GET', '/api/me/space', user=user)
    sql("UPDATE users SET team_id=NULL WHERE username='claude_admin01'")
    team = next(s['id'] for s in request('GET', '/api/spaces')['spaces'] if s['kind'] == 'team')
    other_team = next(s['id'] for s in request('GET', '/api/spaces', user='other')['spaces'] if s['kind'] == 'team')
    original = {'schema': 1, 'blocks': [{'id': str(uuid.uuid4()), 'type': 'paragraph',
                 'text': [{'t': '합성 원문 · 삭제와 복원 뒤에도 보존', 'm': {'bold': True}}]}]}
    own = new_page('삭제검증 본인', doc=original)
    shared_edit = new_page('삭제검증 편집 공유')
    shared_view = new_page('삭제검증 보기 공유')
    team_page = new_page('삭제검증 팀', space_id=team)
    internal = new_page('삭제검증 사내')
    private = new_page('삭제검증 숨은 개인')
    request('PATCH', '/api/pages/' + internal + '/visibility', {'visibility': 'internal'})
    for pid, level in [(shared_edit, 'edit'), (shared_view, 'view')]:
        request('PUT', '/api/pages/' + pid + '/shares/claude_manager01', {'level': level})
    request('PUT', '/api/pages/' + team_page + '/shares/claude_employee02', {'level': 'edit'})

    project = new_page('삭제검증 내 프로젝트', database=True, preset='project')
    shared_project = new_page('삭제검증 공유 프로젝트', database=True, preset='project')
    internal_project = new_page('삭제검증 사내 프로젝트', database=True, preset='project')
    team_project = new_page('삭제검증 팀 프로젝트', space_id=team, database=True, preset='project')
    request('PUT', '/api/pages/' + shared_project + '/shares/claude_manager01', {'level': 'edit'})
    request('PATCH', '/api/pages/' + internal_project + '/visibility', {'visibility': 'internal'})
    project_child = new_page('삭제검증 프로젝트 작업', parent_id=project)

    # Board rows can occur in favorites/recent even though the workspace tree contains personal/team pages.
    board = int(sql("INSERT INTO community.spaces(kind,name,access_level,board_kind,created_by) VALUES('board','삭제검증 게시판','public','discussion'," + str(users['admin']['id']) + ') RETURNING id'))
    board_post = request('POST', '/api/boards/' + str(board) + '/pages', {'title': '삭제검증 내 게시글'}, status=201)['page']['id']
    board_other = request('POST', '/api/boards/' + str(board) + '/pages', {'title': '삭제검증 다른 게시글'}, 'other', 201)['page']['id']
    for user, ids in [('employee', [own, team_page, board_post, board_other]),
                      ('manager', [shared_edit, shared_view, team_page, board_post]),
                      ('other', [team_page, board_post]), ('admin', [internal, private]),
                      ('guest', [board_post])]:
        for pid in ids:
            remember(pid, user)

    # Capabilities must not use edit rights, administrator visibility, or roles in place of manage rights.
    before = snapshot()
    for path in ['/api/pages', '/api/pages?tree=1', '/api/pages?q=삭제검증']:
        capability(path, own, True)
    capability('/api/pages?tree=1&space=' + str(team), team_page, True, 'manager')
    capability('/api/pages?space=' + str(team), team_page, True)
    for pid in [shared_edit, shared_view]:
        row = capability('/api/pages?shared=me', pid, False, 'manager')
        check(row['can_edit'] is (pid == shared_edit), 'shared edit permission stays distinct from delete')
    capability('/api/pages?shared=me', team_page, False, 'other')
    for query in ['', '&tree=1', '&q=삭제검증']:
        path = '/api/pages?space=' + str(board) + query
        capability(path, board_post, True)
        capability(path, board_other, False)
        capability(path, board_other, True, 'other')
        capability(path, board_post, False, 'guest')
    for user, cases in [('employee', [(own, True), (team_page, True), (board_post, True), (board_other, False)]),
                        ('manager', [(shared_edit, False), (shared_view, False), (team_page, True), (board_post, False)]),
                        ('other', [(team_page, False)]), ('admin', [(internal, False)]), ('guest', [(board_post, False)])]:
        for path in ['/api/me/favorites', '/api/me/recent']:
            data = rows(path, user)
            for pid, expected in cases:
                check(pid in data and data[pid]['can_delete'] is expected,
                      path + ' actual ' + user + ' row permission ' + str(expected))
            if user == 'admin':
                check(private not in data, 'administrator privileged private read stays absent from ' + path)
            check(all(not ({'author_id', 'space_owner_user_id', 'space_team_id', 'space_is_active'} & p.keys()) for p in data.values()),
                  path + ' internal permission inputs are not exposed')
    guest_rows = rows('/api/pages?tree=1', 'guest')
    check(guest_rows and all(not p['can_delete'] for p in guest_rows.values()), 'Guest own welcome documents cannot be deleted')
    for user, cases in [('employee', [(project, True), (team_project, True)]),
                        ('manager', [(shared_project, False), (team_project, True)]),
                        ('admin', [(internal_project, False)])]:
        data = projects(user)
        by_id = {p['id']: p for p in data['items']}
        for pid, expected in cases:
            check(pid in by_id and by_id[pid]['can_delete'] is expected, 'SSR project capability for ' + user)
        if user == 'admin':
            check(project not in by_id, 'SSR does not expose administrator-only private projects')
    limited = projects('employee', 1)
    check(len(limited['items']) == 1 and limited['more'] is True, 'project limit and more semantics preserved')
    for user in users:
        expected = projects(user, 10, False)
        actual = request('GET', '/api/pages?view=projects', user=user)
        check(actual == {'pages': expected['items'], 'more': expected['more']},
              'HTTP projects exactly matches fixed-ten read-only SSR helper for ' + user)
        check(all(p['progress'] is None for p in actual['pages']), 'HTTP project refresh does not request progress aggregation')
    narrow = request('GET', '/api/pages?view=projects&space=' + str(other_team) + '&shared=me&tree=1&page=999&q=missing&limit=1000')
    check(narrow == request('GET', '/api/pages?view=projects'),
          'project view ignores unrelated filters without widening current-user scope or fixed limit')
    check(request('GET', '/api/pages?view=unknown') == request('GET', '/api/pages'),
          'unknown view retains existing safe normal-list fallback')
    check(snapshot() == before, 'all capability GETs and project helper change no community or audit rows')
    request('GET', '/api/pages?view=projects', user=None, status=401)

    # Current permissions are recomputed after archival, share revocation, and leaving a team.
    sql('UPDATE community.spaces SET is_active=false WHERE id=' + str(board))
    capability('/api/me/favorites', board_post, False)
    capability('/api/me/recent', board_post, False)
    for query in ['', '&tree=1']:
        capability('/api/pages?space=' + str(board) + query, board_post, False)
    request('DELETE', '/api/pages/' + board_post, {}, status=403)
    sql('UPDATE community.spaces SET is_active=true WHERE id=' + str(board))
    request('DELETE', '/api/pages/' + shared_edit + '/shares/claude_manager01', {})
    check(shared_edit not in rows('/api/me/favorites', 'manager') and shared_edit not in rows('/api/me/recent', 'manager'),
          'revoked shared document disappears instead of exposing a stale capability')
    team_id = sql("SELECT team_id FROM users WHERE username='claude_manager01'")
    sql("UPDATE users SET team_id=NULL WHERE username='claude_manager01'")
    check(team_page not in rows('/api/me/favorites', 'manager'), 'departed team member loses listing')
    request('DELETE', '/api/pages/' + team_page, {}, 'manager', 403)
    sql("UPDATE users SET team_id=" + team_id + " WHERE username='claude_manager01'")

    # The existing mutation endpoint remains authoritative even with forged/stale client capability.
    preserved = document_snapshot()
    for pid, user in [(shared_view, 'manager'), (shared_project, 'manager'), (team_page, 'other'), (internal, 'admin'),
                      (board_post, 'other'), (own, 'guest')]:
        request('DELETE', '/api/pages/' + pid, {}, user, 403)
    request('DELETE', '/api/pages/' + own, {}, status=403, csrf=False)
    request('DELETE', '/api/pages/' + own, {}, user=None, status=401)
    request('DELETE', '/api/pages/' + str(uuid.uuid4()), {}, status=404)
    request('GET', '/api/pages?tree=1&space=' + str(other_team), status=403)
    check(document_snapshot() == preserved, 'denied DELETE and cross-space requests preserve every document')
    check(audit_count('page.delete', 'deny', shared_view) == 1 and audit_count('page.delete', 'deny', shared_project) == 1
          and audit_count('page.delete', 'deny', internal) == 1,
          'shared editor/viewer and administrator manage denials retain audit records')

    # Three generations and a separately deleted sibling retain exact original JSON, versions and relationships.
    child = new_page('삭제검증 하위', parent_id=own, doc=original)
    grandchild = new_page('삭제검증 손자', parent_id=child, doc=original)
    separately_deleted = new_page('삭제검증 이전 삭제', parent_id=own, doc=original)
    request('PATCH', '/api/pages/' + own, {'doc_version': 1, 'title': '삭제검증 본인 새 제목'})
    request('PATCH', '/api/pages/' + own + '/properties', {'status': 'doing'})
    request('DELETE', '/api/pages/' + separately_deleted, {})
    preserved = document_snapshot()
    history_before = snapshot()
    result = request('DELETE', '/api/pages/' + own, {})
    check(result == {'deleted': 3}, 'one DELETE cascades exactly three live generations')
    ids = ','.join(lit(pid) for pid in [own, child, grandchild])
    check(sql('SELECT count(DISTINCT deleted_at)||\'|\'||bool_and(deleted_by=' + str(users['employee']['id']) +
              ')||\'|\'||bool_and(purge_after=deleted_at+interval \'30 days\') FROM community.pages WHERE public_id IN (' + ids + ')') == '1|true|true',
          'cascade shares deletion timestamp, actor and exact 30-day retention')
    check(document_snapshot() == preserved, 'soft delete preserves original documents, versions, properties and parent IDs')
    history_after = snapshot()
    check(all(history_before[t] == history_after[t] for t in history_before if t not in ['pages', 'audit']),
          'delete leaves every other community table byte-equivalent')
    check(audit_count('page.delete', 'allow', own) == 1 and audit_count('page.delete', 'allow', child) == 0,
          'one root delete audit, no separate child mutation/audit')
    request('DELETE', '/api/pages/' + own, {}, status=404)
    request('GET', '/api/pages/' + child, status=404)
    for path in ['/api/pages?tree=1', '/api/me/favorites', '/api/me/recent']:
        check(own not in rows(path), 'deleted root absent from ' + path)
    trash = request('GET', '/api/me/trash')
    trash_row = next(p for p in trash['pages'] if p['id'] == own)
    check(trash_row['descendants'] == 2 and 'doc' not in trash_row, 'trash represents one cascade with two descendants and no body')
    request('POST', '/api/pages/' + own + '/restore', {}, 'admin', 403)
    request('POST', '/api/pages/' + own + '/restore', {}, status=403, csrf=False)
    request('POST', '/api/pages/' + own + '/restore', {}, user=None, status=401)
    restored = request('POST', '/api/pages/' + own + '/restore', {})
    check(restored['restored'] == 3 and restored['moved_to_root'] is False, 'restore returns exactly the cascade to its original positions')
    check(sql('SELECT deleted_at IS NOT NULL FROM community.pages WHERE public_id=' + lit(separately_deleted)) == 't',
          'earlier separately deleted child remains in trash')
    check(document_snapshot() == preserved, 'restore retains every original document and stored version number')
    check(audit_count('page.restore', 'allow', own) == 1, 'restore audit recorded once')
    request('POST', '/api/pages/' + own + '/restore', {}, status=404)
    capability('/api/me/favorites', own, True)

    # Team member can delete; only the actual deleting member can restore. Board author policy is unchanged.
    request('DELETE', '/api/pages/' + team_page, {}, 'manager')
    request('POST', '/api/pages/' + team_page + '/restore', {}, 'employee', 403)
    request('POST', '/api/pages/' + team_page + '/restore', {}, 'manager')
    request('DELETE', '/api/pages/' + board_post, {})
    request('POST', '/api/pages/' + board_post + '/restore', {})

    # A project is a page: its task rows cascade even though workspace trees exclude database rows.
    before_project = document_snapshot()
    check(request('DELETE', '/api/pages/' + project, {})['deleted'] == 2, 'project deletion includes its task row')
    check(project not in {p['id'] for p in projects('employee')['items']}, 'deleted project disappears from SSR helper')
    check(project not in rows('/api/pages?view=projects'), 'HTTP refresh removes the deleted project')
    check(request('POST', '/api/pages/' + project + '/restore', {})['restored'] == 2, 'project and its task row restore together')
    capability('/api/pages?view=projects', project, True)
    check(document_snapshot() == before_project, 'project, task data and schema references preserved')

    # An individually restored descendant is promoted when its original parent remains deleted.
    request('DELETE', '/api/pages/' + own, {})
    moved = request('POST', '/api/pages/' + child + '/restore', {})
    check(moved['restored'] == 2 and moved['moved_to_root'] is True and moved['moved_reason'] == 'parent_deleted',
          'existing restore-to-root fallback remains explicit')
    request('POST', '/api/pages/' + own + '/restore', {})
    request('POST', '/api/pages/' + separately_deleted + '/restore', {})
    check(sql('SELECT parent_page_id IS NULL FROM community.pages WHERE public_id=' + lit(child)) == 't',
          'restore fallback changes only the intended parent link')
    check(sql("SELECT count(*) FROM audit_logs WHERE detail::text LIKE '%합성 원문%'") == '0', 'audit never contains document body')
except Exception as error:
    if not checks or checks[-1]['pass']:
        checks.append({'name': 'suite interrupted: ' + type(error).__name__, 'pass': False})
    raise
finally:
    (out / 'document-trash-api-results.json').write_text(json.dumps(checks, ensure_ascii=False, indent=2))
    print('RESULT pass=' + str(sum(c['pass'] for c in checks)) + ' fail=' + str(sum(not c['pass'] for c in checks)), flush=True)
