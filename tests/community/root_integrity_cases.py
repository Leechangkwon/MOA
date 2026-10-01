#!/usr/bin/env python3
"""Regression cases on the guarded stage89 synthetic server, never live records."""
import uuid
from pathlib import Path

helper = (Path(__file__).parent / 'stage89_http.py').read_text().split('\ntry:\n', 1)[0]
exec(compile(helper, 'stage89_http_helpers', 'exec'))


def lit(value):
    return "'" + str(value).replace("'", "''") + "'"


def patch_doc(pid, doc):
    before = call('GET', '/api/pages/' + pid, expected=200).json()['page']
    return call('PATCH', '/api/pages/' + pid,
                {'doc_version': before['doc_version'], 'doc': doc}, expected=200)


try:
    for u in ('employee', 'other'):
        call('GET', '/api/me/space', user=u, expected=200)
    pid = call('POST', '/api/pages', {'title': '판 복원 안전 검증'}, expected=201).json()['page']['id']
    call('PATCH', '/api/pages/' + pid + '/properties', {'kind': 'procedure', 'due': '2026-10-02'}, expected=200)
    rule = {'kind': 'button', 'name': '복원 버튼', 'scope_page_id': pid, 'trigger': {'type': 'button'},
            'press_scope': 'owner', 'actions': [{'type': 'create_page', 'source': {'page': pid},
                                               'parent': pid, 'title_pattern': '실행 사본'},
                                              {'type': 'notify', 'to': 'owner', 'text_key': 'recurring_created'}]}
    aid = call('POST', '/api/automations', rule, expected=201).json()['automation']['id']
    call('POST', '/api/automations/' + aid + '/dry-run', {}, expected=200)
    call('PATCH', '/api/automations/' + aid, {'is_enabled': True}, expected=200)
    button_doc = {'schema': 1, 'blocks': [{'id': str(uuid.uuid4()), 'type': 'button',
                                          'label': '정산 시작', 'automation_id': aid}]}
    saved = patch_doc(pid, button_doc).json()
    check(saved['buttons'][aid]['can_press'], 'saving a new button returns fresh ordinary permission map')
    call('POST', '/api/automations/' + aid + '/run', {'page_id': pid}, expected=200)
    # Ensure subsequent snapshot is outside the existing editor's five-minute coalescing window.
    sql("UPDATE community.page_versions SET edited_at=now()-interval '10 minutes' WHERE page_id=(SELECT id FROM community.pages WHERE public_id=" + lit(pid) + ')')
    empty = {'schema': 1, 'blocks': [{'id': str(uuid.uuid4()), 'type': 'paragraph', 'text': []}]}
    patch_doc(pid, empty)
    old = sql("SELECT version FROM community.page_versions WHERE page_id=(SELECT id FROM community.pages WHERE public_id=" + lit(pid) + ") AND doc::text LIKE '%automation_id%' ORDER BY version DESC LIMIT 1")
    check(bool(old), 'actual saved history retains prior button document')
    call('PUT', '/api/pages/' + pid + '/shares/claude_employee02', {'level': 'edit'}, expected=200)
    before = sql('SELECT doc::text FROM community.pages WHERE public_id=' + lit(pid))
    r = call('POST', '/api/pages/' + pid + '/versions/' + old + '/restore', {}, user='other', expected=400)
    check(r.json().get('reason') == 'button_not_allowed', 'shared editor cannot reinsert owner button by restoring history')
    check(sql('SELECT doc::text FROM community.pages WHERE public_id=' + lit(pid)) == before, 'rejected restore leaves current document unchanged')
    call('POST', '/api/pages/' + pid + '/versions/' + old + '/restore', {}, expected=200)
    check(call('GET', '/api/pages/' + pid, expected=200).json()['page']['doc'] == button_doc, 'owner may restore its currently bound button')

    # Reapply the complete bundle after real stage9 property and notification rows exist.
    def counts():
        return sql("SELECT json_build_array((SELECT count(*) FROM community.pages),(SELECT count(*) FROM community.page_property_events),(SELECT count(*) FROM community.notifications),(SELECT count(*) FROM community.automation_runs))")
    before = counts()
    for _ in range(2):
        for migration in ('2026-09-29_community_trust.sql', '2026-09-30_community_automation.sql'):
            sql((root / 'db/migrations' / migration).read_text())
        sql((root / 'db/community/grants.sql').read_text())
    check(sql("SELECT has_table_privilege('tbox_app','community.automation_runs','SELECT') AND NOT has_table_privilege('tbox_app','community.automation_runs','UPDATE') AND has_column_privilege('tbox_app','community.automation_runs','undone_at','UPDATE') AND NOT has_column_privilege('tbox_app','community.automation_runs','steps','UPDATE') AND NOT has_table_privilege('tbox_app','community.automation_runs','DELETE')") == 't', 'reapplied grants retain immutable run history and narrow undo columns')
    check(sql("SELECT has_table_privilege('tbox_backup','community.automation_runs','SELECT') AND NOT has_table_privilege('tbox_backup','community.automation_runs','INSERT') AND NOT has_table_privilege('tbox_app','community.page_property_events','UPDATE')") == 't', 'reapplied grants preserve backup reads and immutable property events')
    check(counts() == before, 'reapplying stages8 and9 twice preserves stage9 records and succeeds')
    check(sql("SELECT count(*) FROM community.page_property_events WHERE prop='due'") != '0', 'due events survive stage8 constraint refresh')
    check(sql("SELECT count(*) FROM community.notifications WHERE kind='recurring_created'") != '0', 'stage9 notification rows survive stage8 constraint refresh')

    # 101 disabled rules are permitted. Pagination must not hide old enabled rules.
    uid = int(sql("SELECT id FROM users WHERE username='claude_employee01'"))
    for i in range(101):
        sql("INSERT INTO community.automations(public_id,owner_user_id,kind,name,trigger,actions) VALUES(" +
            lit(uuid.uuid4()) + ',' + str(uid) + ",'recurring','pagination fixture'," +
            lit(json.dumps({'type': 'schedule', 'freq': 'daily', 'time': '09:00'})) + '::jsonb,' +
            lit(json.dumps(rule['actions'])) + '::jsonb)')
    first = call('GET', '/api/automations', expected=200).json()
    second = call('GET', '/api/automations?after=' + first['next_cursor'], expected=200).json()
    ids = [r['id'] for r in first['automations'] + second['automations']]
    check(len(ids) == 102 and len(set(ids)) == 102 and aid in ids, 'cursor listing returns all 102 rules without duplicate or hidden active rule')
    call('GET', '/api/automations?after=%27%3BDROP', expected=400)
    call('GET', '/api/automations/' + aid, user='other', expected=404)
    denied = call('GET', '/api/admin/automations', user='admin', expected=403).json()
    check(denied.get('next') == 'reauth', 'admin metadata requires recent reauthentication')
    # This guarded synthetic fixture models a freshly reauthenticated admin session.
    # Password/TOTP and actual reauthentication are covered by the cross-regression suite.
    sql("UPDATE sessions SET reauth_at=now() WHERE user_id=(SELECT id FROM users WHERE username='claude_admin01')")
    admin_first = call('GET', '/api/admin/automations', user='admin', expected=200).json()
    admin_next = call('GET', '/api/admin/automations?after=' + admin_first['next_cursor'], user='admin', expected=200).json()
    admin_rows = admin_first['automations'] + admin_next['automations']
    check(len(admin_rows) == 102 and all(set(r) == {'id', 'name', 'is_enabled', 'owner', 'last_result'} for r in admin_rows),
          'admin pagination exposes all rule metadata without source, body or private page fields')
    call('GET', '/api/admin/automations', user='other', expected=403)

    sql("INSERT INTO community.notifications(user_id,kind,page_id,actor_id) VALUES(" + str(uid) + ",'automation_denied',NULL,NULL)")
    notices = call('GET', '/api/notifications', expected=200).json()['notifications']
    notice = next(n for n in notices if n['kind'] == 'automation_denied')
    check(notice['automation_status'] and notice['page'] is None and notice['actor'] is None,
          'rule status notification contains no inaccessible page or actor information')
finally:
    (output / 'root-integrity-results.json').write_text(json.dumps(checks, ensure_ascii=False, indent=2))
