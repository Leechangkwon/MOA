#!/usr/bin/env python3
"""Q:F-12 지시서 B-5 — 서버: 내 작업 · 「오늘」 · 프로젝트 만들기 · 「프로젝트로 쓰기」 · 「내 할 일」 · 섹션 묶기 · 담당 칸 (실제 PHP·PostgreSQL + Chromium).

memories_harness.py 만 부른다(합성 DB `tbox_memories_test_*` · 임시 복사본 · 로컬 php -S 둘, 끝나면 지운다).
운영 DB·운영 8082·시드 계정 0. 요청은 fixture 의 claude_ 계정과 UA tbox-review/<날짜> 뿐이다.
결과 파일에는 단언 이름과 참/거짓 + 확정한 값(묶음 이름 · preset 칸·보기 원문 · group_by 저장 모양 · 308 대상 같은 **고정 문자열**)만 — 세션·CSRF 0.

fixture: A=claude_employee01(팀 X) · B=claude_manager01(팀 X) · C=claude_employee02(팀 Y) · G=claude_guest01 · M=claude_admin01(이 시험에서 팀을 비운다).

  Q:T-29 내 작업 노출   남의 비공개 작업에 assignee_id=나(SQL 준비) → 0 · Admin 의 내 작업에 남의 비공개 0 · 날짜 경계 넷 · 옛 문서 → 「문서 · 공간」
  Q:T-30 공지 빼기      안 읽은 공지 3 · 작업 0 → top·missed 에 공지 0 · /community/today 308 → /community/my-tasks
  Q:T-31 프로젝트·섹션  preset 원문 · 편집 공유자 섹션 추가 403 · board group_by 섹션 200 / 글 칸 400 / 없는 키 400 · 카드 옮기기 = 섹션 값 + 사건 db:section
  Q:T-32 내 할 일       두 요청 동시(포트 둘) → my_tasks_projects 1행 · 프로젝트 1 · 비공개 · 남이 읽으면 403 / PK 충돌 갈래를 잠금으로 확정 재현
  더해서                「프로젝트로 쓰기」 두 번 = 멱등 · 「표로 쓰기」 = purpose 만 · 담당 칸(거르기·정렬 · 값 쓰기 0) · 보드 화면 360·412·820·1440
"""
from pathlib import Path
exec(compile(Path(__file__).with_name('memories_http.py').read_text().split('try:\n for u in users:')[0], __file__, 'exec'))
import re
import datetime
import threading
from urllib.parse import urlsplit
assert str(root).startswith('/tmp/qaredi-stage13-') and manifest['database'].startswith('tbox_memories_test_')

NAMES = {'A': 'employee', 'B': 'manager', 'C': 'other', 'G': 'guest', 'M': 'admin'}
UNAME = {'A': 'claude_employee01', 'B': 'claude_manager01', 'C': 'claude_employee02', 'G': 'claude_guest01', 'M': 'claude_admin01'}
codes = {}          # 확정한 값(고정 문자열·모양) — 결과 파일에 함께 적는다
SECOND_PORT = int(os.environ.get('STAGE89_SECOND_PORT', '8769'))


def check(ok, name):
    """단언 — 실패해도 멈추지 않고 끝까지 돈다(마지막에 실패 수로 끝낸다). 이름·참거짓만 남긴다."""
    checks.append({'pass': bool(ok), 'name': name})
    print(('PASS ' if ok else 'FAIL ') + name, flush=True)
    return bool(ok)


def uid(k): return int(users[NAMES[k]]['id'])
def req(method, path, body=None, user='A', port=None, **kw): return raw(method, path, body, NAMES[user] if user else None, port=port, **kw)


def expect(method, path, body, user, status, name, port=None):
    r = req(method, path, body, user, port=port)
    check(r.status_code == status, name + ' → ' + str(status) + ' (got ' + str(r.status_code) + ')')
    return r


def n(text): return int((sql(text).splitlines() or ['0'])[0] or 0)
def lit(v): return "'" + str(v).replace("'", "''") + "'"
def pk(public_id): return n('SELECT id FROM community.pages WHERE public_id=' + lit(public_id))


def page(title, user='A', **extra):
    r = expect('POST', '/api/pages', dict(title=title, **extra), user, 201, 'create ' + title)
    return r.json()['page']


def props(public_id, body, user='A', status=200, name=None):
    return expect('PATCH', '/api/pages/' + public_id + '/properties', body, user, status, name or ('properties ' + json.dumps(body, ensure_ascii=False)))


def my_tasks(user='A', query=''):
    r = req('GET', '/api/my-tasks' + query, None, user)
    return r


def flat(resp):
    """응답의 줄 전부 → {public_id: (bucket, item)}"""
    out = {}
    for b in resp.json()['buckets']:
        for it in b['items']:
            out[it['public_id']] = (b['key'], it)
    return out


def strip_ids(views): return [{k: v for k, v in x.items() if k != 'id'} for x in views]


def rows_of(dbid, user='A', query=''):
    return req('GET', '/api/databases/' + dbid + '/rows' + query, None, user)


php_prelude = ("require 'src/Env.php'; Env::load(getcwd().'/.env'); require 'src/bootstrap.php';"
               "require_once 'src/community/routes.php';")


def php(code):
    done = subprocess.run(['php', '-r', php_prelude + code], cwd=root, capture_output=True, text=True, timeout=60)
    return done.stdout.strip()


try:
    # ════════ 준비 ════════════════════════════════════════════════
    for k in NAMES:
        expect('GET', '/api/me/space', None, k, 200, 'personal space ' + k)
    sql("UPDATE users SET team_id=NULL WHERE username='claude_admin01'")     # M = 팀 없는 Admin(관리자 열람 갈래만)
    space_x = next(s['id'] for s in req('GET', '/api/spaces', None, 'A').json()['spaces'] if s['kind'] == 'team')
    today = sql("SELECT (now() AT TIME ZONE 'Asia/Seoul')::date")
    td = datetime.date.fromisoformat(today)
    sunday = td + datetime.timedelta(days=6 - td.weekday())
    dates = {'yesterday': td - datetime.timedelta(days=1), 'today': td, 'sunday': sunday, 'next_monday': sunday + datetime.timedelta(days=1)}
    want_bucket = {'yesterday': 'overdue', 'today': 'today', 'sunday': 'today' if sunday == td else 'this_week', 'next_monday': 'later'}
    codes['dates'] = {k: v.isoformat() for k, v in dates.items()} | {'today_is_sunday': sunday == td}

    # ════════ Q:T-29 내 작업 ═════════════════════════════════════════
    r0 = my_tasks('A')
    check(r0.status_code == 200, 'Q:T-29 GET /api/my-tasks 200')
    codes['my_tasks_shape'] = {'top_keys': sorted(r0.json()), 'buckets': [[b['key'], b['name']] for b in r0.json()['buckets']],
                               'bucket_keys': sorted(r0.json()['buckets'][0])}
    check([b['key'] for b in r0.json()['buckets']] == ['overdue', 'today', 'this_week', 'later', 'no_due']
          and [b['name'] for b in r0.json()['buckets']] == ['지난 기한', '오늘', '이번 주', '나중에', '기한 없음'],
          'Q:T-29 five buckets in order with names (지난 기한 · 오늘 · 이번 주 · 나중에 · 기한 없음)')
    check(r0.json()['today'] == today and r0.json()['done'] is False, 'Q:T-29 today = Asia/Seoul date · done false')

    boundary = {}
    for label, d in dates.items():
        p_ = page('QF12B 경계 ' + label)
        props(p_['id'], {'status': 'todo', 'due': d.isoformat()})
        boundary[label] = p_['id']
    nodue = page('QF12B 기한 없는 할 일')
    props(nodue['id'], {'status': 'doing'})
    old_doc = page('QF12B 옛 문서 상태만')
    props(old_doc['id'], {'status': 'review'})
    plain = page('QF12B 상태 없는 문서')                                       # 상태·기한 없음 → 내 작업 아님
    done_p = page('QF12B 끝난 일')
    props(done_p['id'], {'status': 'done', 'due': today})

    # 남의 비공개 작업에 담당 = A (시험 준비로 직접) · A 의 비공개 작업에 담당 = M
    pc = page('QF12B C 비공개 작업', 'C')
    props(pc['id'], {'status': 'todo', 'due': today}, 'C')
    sql('UPDATE community.pages SET assignee_id=' + str(uid('A')) + ' WHERE public_id=' + lit(pc['id']))
    pam = page('QF12B A 비공개 관리자 담당')
    props(pam['id'], {'status': 'todo'})
    sql('UPDATE community.pages SET assignee_id=' + str(uid('M')) + ' WHERE public_id=' + lit(pam['id']))

    # B 가 팀 X 공간 작업을 A 에게 맡김 → A 의 줄에 「맡긴 사람」
    tb = page('QF12B 팀 작업 맡김', 'B', space_id=space_x)
    props(tb['id'], {'status': 'todo', 'due': (td + datetime.timedelta(days=30)).isoformat()}, 'B')
    props(tb['id'], {'assignee': 'claude_employee01'}, 'B', 200, 'Q:T-29 B assigns team task to A')
    # 하위 작업 — A 의 「팀 공개」 문서 둘(완료 1 · 비공개 1) · 담당 B(시험 준비로 직접)
    pt = page('QF12B 하위 셈 부모')
    expect('PATCH', '/api/pages/' + pt['id'] + '/visibility', {'visibility': 'team'}, 'A', 200, 'Q:T-29 parent visibility team')
    props(pt['id'], {'status': 'todo'})
    ch1 = page('QF12B 하위 하나', parent_id=pt['id'])
    ch2 = page('QF12B 하위 둘 비공개', parent_id=pt['id'])
    props(ch1['id'], {'status': 'done'})
    expect('PATCH', '/api/pages/' + ch2['id'] + '/visibility', {'visibility': 'private'}, 'A', 200, 'Q:T-29 child 2 private')
    sql('INSERT INTO community.comments(page_id,body,author_id) SELECT id,\'합성 댓글\',' + str(uid('B')) + ' FROM community.pages WHERE public_id=' + lit(pt['id']))
    # 프로젝트 행
    proj_a = page('QF12B A 프로젝트', database=True, preset='project')
    row_a = page('QF12B 프로젝트 행', parent_id=proj_a['id'], properties={'section': '01. 진행'})
    props(row_a['id'], {'status': 'todo', 'due': today})

    ra = my_tasks('A')
    fa = flat(ra)
    for label, pid in boundary.items():
        check(fa.get(pid, ('∅',))[0] == want_bucket[label], 'Q:T-29 date boundary ' + label + ' (' + dates[label].isoformat() + ') → ' + want_bucket[label])
    check(fa.get(nodue['id'], ('∅',))[0] == 'no_due', 'Q:T-29 status-only task → no_due')
    check(old_doc['id'] in fa and fa[old_doc['id']][1]['doc_space'] == '문서 · claude_employee01 의 공간' and fa[old_doc['id']][1]['project'] is None,
          'Q:T-29 old document (status only) → doc_space 「문서 · 공간 이름」 · project null')
    codes['doc_space_example'] = fa.get(old_doc['id'], (None, {}))[1].get('doc_space')
    check(plain['id'] not in fa and done_p['id'] not in fa, 'Q:T-29 no status/due document absent · done absent (done=0)')
    check(pc['id'] not in fa and 'QF12B C 비공개 작업' not in ra.text, "Q:T-29 another's private task with assignee_id=A (SQL) → 0 (row and title absent)")
    check(fa.get(tb['id'], ('∅', {}))[1].get('assigned_by') == 'claude_manager01', 'Q:T-29 assigned_by = assigner display (username when no display name)')
    check(fa.get(row_a['id'], ('∅', {}))[1].get('project') == {'public_id': proj_a['id'], 'title': 'QF12B A 프로젝트', 'color': 'peach'}
          and fa[row_a['id']][1]['doc_space'] is None, 'Q:T-29 project row → project {public_id,title,color} · doc_space null')
    check(fa.get(pt['id'], ('∅', {}))[1].get('sub_total') == 2 and fa[pt['id']][1]['sub_done'] == 1 and fa[pt['id']][1]['comments_n'] == 1,
          'Q:T-29 owner sees sub 1/2 · comments_n 1')
    item_keys = sorted(fa[old_doc['id']][1]) if old_doc['id'] in fa else []
    codes['my_tasks_item_keys'] = item_keys
    check(item_keys == sorted(['public_id', 'title', 'status', 'due', 'project', 'doc_space', 'assigned_by', 'comments_n', 'sub_done', 'sub_total']),
          'Q:T-29 item keys exactly (public_id title status due project doc_space assigned_by comments_n sub_done sub_total)')
    check(fa.get(tb['id'], ('∅', {}))[1].get('assigned_by') is not None and fa.get(old_doc['id'], ('∅', {}))[1].get('assigned_by') is None,
          'Q:T-29 assigned_by only on tasks someone else assigned')
    done_resp = my_tasks('A', '?done=1')
    check(done_p['id'] in flat(done_resp) and boundary['today'] not in flat(done_resp), 'Q:T-29 done=1 lists done only')

    # B — 담당이 B 인 A 의 「팀 공개」 문서(시험 준비로 직접): 하위는 B 가 볼 수 있는 것만 센다
    sql('UPDATE community.pages SET assignee_id=' + str(uid('B')) + ' WHERE public_id=' + lit(pt['id']))
    rb = my_tasks('B')
    fb = flat(rb)
    check(fb.get(pt['id'], ('∅', {}))[1].get('sub_total') == 1 and fb[pt['id']][1]['sub_done'] == 1,
          'Q:T-29 B sees sub 1/1 — private child not counted')
    check('QF12B 하위 둘 비공개' not in rb.text and ch2['id'] not in rb.text, 'Q:T-29 B raw response has no private child title/id')
    check(tb['id'] not in fb, 'Q:T-29 B (author) does not list the task assigned to A')
    # Admin — 남의 비공개 0
    rm = my_tasks('M')
    fm = flat(rm)
    check(rm.status_code == 200 and pam['id'] not in fm and 'QF12B A 비공개 관리자 담당' not in rm.text,
          "Q:T-29 Admin M (assignee_id=M on A's private task) → 0")
    check(not any(t_ in rm.text for t_ in ('QF12B 경계', 'QF12B 옛 문서', 'QF12B C 비공개')), "Q:T-29 Admin M's my-tasks has no other people's private task titles")
    # 담당이 권한을 잃으면 빠진다 — 공유 편집으로 맡긴 뒤 회수
    sh = page('QF12B 공유 뒤 회수')
    props(sh['id'], {'status': 'todo'})
    expect('PUT', '/api/pages/' + sh['id'] + '/shares/claude_manager01', {'level': 'edit'}, 'A', 200, 'Q:T-29 share edit to B')
    props(sh['id'], {'assignee': 'claude_manager01'}, 'A', 200, 'Q:T-29 assign shared task to B')
    check(sh['id'] in flat(my_tasks('B')), 'Q:T-29 B lists the shared task while shared')
    expect('DELETE', '/api/pages/' + sh['id'] + '/shares/claude_manager01', None, 'A', 200, 'Q:T-29 revoke share')
    after = my_tasks('B')
    check(sh['id'] not in flat(after) and 'QF12B 공유 뒤 회수' not in after.text, 'Q:T-29 after revoke the task leaves B list (assignee kept)')
    check(n('SELECT assignee_id FROM community.pages WHERE public_id=' + lit(sh['id'])) == uid('B'), 'Q:T-29 assignee_id kept after revoke')
    # 묶음 더 보기 — 「나중에」 101 줄(시험 준비로 직접)
    a_space = n('SELECT id FROM community.spaces WHERE kind=\'personal\' AND owner_user_id=' + str(uid('A')))
    sql('INSERT INTO community.pages(space_id,title,doc,author_id,last_edited_by,status,due_date) SELECT ' + str(a_space)
        + ",'QF12B 채움 '||g,'{\"schema\":1,\"blocks\":[]}'," + str(uid('A')) + ',' + str(uid('A')) + ",'todo',CAST(" + lit(today)
        + ' AS date)+200+g FROM generate_series(1,101) g')
    later = next(b for b in my_tasks('A').json()['buckets'] if b['key'] == 'later')
    check(len(later['items']) == 100 and later['next_cursor'] == '100', 'Q:T-29 bucket page 100 · next_cursor "100"')
    more = expect('GET', '/api/my-tasks?bucket=later&cursor=100', None, 'A', 200, 'Q:T-29 more in later')
    mb = more.json()['buckets']
    check(len(mb) == 1 and mb[0]['key'] == 'later' and len(mb[0]['items']) >= 2 and mb[0]['next_cursor'] is None
          and not ({i['public_id'] for i in mb[0]['items']} & {i['public_id'] for i in later['items']}), 'Q:T-29 cursor page: only later, rest, no overlap, next_cursor null')
    for q, label in [('?done=2', 'done=2'), ('?bucket=soon', 'unknown bucket'), ('?cursor=100', 'cursor without bucket'),
                     ('?bucket=later&cursor=abc', 'cursor not a number'), ('?bucket=later&cursor=10001', 'cursor over max')]:
        expect('GET', '/api/my-tasks' + q, None, 'A', 400, 'Q:T-29 ' + label)
    check(req('GET', '/api/my-tasks', None, None).status_code == 401, 'Q:T-29 signed-out → 401')
    gr = expect('GET', '/api/my-tasks', None, 'G', 200, 'Q:T-29 guest (community.read) → 200')
    check(sum(len(b['items']) for b in gr.json()['buckets']) == 0, 'Q:T-29 guest list empty')
    # 질의 계획 원문(ARCH 23.10 미확인 항목) — 합성 DB 위 EXPLAIN
    plan = php("[$s,$b]=community_my_tasks_sql(['id'=>" + str(uid('A')) + ",'role'=>'Employee','team_id'=>" + str(n("SELECT team_id FROM users WHERE username='claude_employee01'"))
               + "],false,'" + today + "','" + sunday.isoformat() + "',null,0);"
               "foreach(Db::instance()->run('EXPLAIN '.$s,$b)->fetchAll() as $r){echo $r['QUERY PLAN'],\"\\n\";}")
    (out / 'qf12-my-tasks-explain.txt').write_text(plan + '\n')
    check('Seq Scan' in plan or 'Index' in plan, 'Q:T-29 EXPLAIN of my-tasks query captured (qf12-my-tasks-explain.txt)')

    # ════════ Q:T-30 「오늘」 공지 빼기 · 옛 주소 ════════════════════════════
    board = sql("INSERT INTO community.spaces(kind,name,access_level,notice,board_kind) VALUES('board','QF12B 공지','internal',true,'notice') RETURNING id").splitlines()[0]
    notice_ids = []
    for i in range(3):
        nid = str(uuid.uuid4())
        sql('INSERT INTO community.pages(public_id,space_id,title,doc,author_id,last_edited_by) VALUES(' + lit(nid) + ',' + board
            + ",'QF12B 안 읽은 공지 " + str(i) + "','{\"schema\":1,\"blocks\":[]}'," + str(uid('A')) + ',' + str(uid('A')) + ')')
        notice_ids.append(nid)
    # C 는 작업 0 — 위 C 비공개 작업의 상태·기한을 비우고, 「시작하기」(빈 체크 셋 = 요약의 할 일)를 휴지통으로(시험 준비로 직접)
    sql('UPDATE community.pages SET status=NULL, due_date=NULL WHERE public_id=' + lit(pc['id']))
    sql("UPDATE community.pages SET deleted_at=now() WHERE title='시작하기' AND space_id=(SELECT id FROM community.spaces WHERE kind='personal' AND owner_user_id="
        + str(uid('C')) + ')')
    pre = expect('GET', '/api/briefing', None, 'C', 200, 'Q:T-30 fixture: C briefing').json()
    check(not [i for i in pre['top'] + pre['missed'] + pre['others'] if i['kind'] != 'notice'], 'Q:T-30 fixture: C has 0 tasks (only notices)')
    br = expect('GET', '/api/briefing', None, 'C', 200, 'Q:T-30 briefing for C')
    bj = br.json()
    check(not any(i['kind'] == 'notice' for i in bj['top'] + bj['missed']), 'Q:T-30 top · missed have 0 notices')
    check(sum(1 for i in bj['others'] if i['kind'] == 'notice') == 3, 'Q:T-30 the 3 unread notices remain in others (home notice line source)')
    check(bj['count'] == 0, 'Q:T-30 count (launcher 「오늘 n건」) = 0 — notices not counted')
    codes['briefing_C'] = {'top': len(bj['top']), 'missed': len(bj['missed']), 'others_notice': sum(1 for i in bj['others'] if i['kind'] == 'notice'), 'count': bj['count']}
    for path in ('/today', '/today?x=1'):
        rt = requests.get(base + path, headers={'User-Agent': 'tbox-review/' + time.strftime('%Y-%m-%d'),
                                                'Cookie': '__Host-tbox_session=' + users['employee']['session']}, allow_redirects=False, timeout=30)
        check(rt.status_code == 308 and rt.headers.get('Location') == '/community/my-tasks', 'Q:T-30 GET /community' + path + ' → 308 /community/my-tasks')
        codes['today_redirect'] = [rt.status_code, rt.headers.get('Location')]
    ru = requests.get(base + '/today', headers={'User-Agent': 'tbox-review/' + time.strftime('%Y-%m-%d')}, allow_redirects=False, timeout=30)
    check(ru.status_code == 302 and '/login' in ru.headers.get('Location', ''), 'Q:T-30 signed-out /community/today → 302 login (unchanged)')

    # ════════ Q:T-31 프로젝트 만들기 · 섹션 · 묶기 ════════════════════════════
    pr = expect('POST', '/api/pages', {'title': 'QF12B 팀 프로젝트', 'database': True, 'preset': 'project', 'space_id': space_x}, 'A', 201, 'Q:T-31 create project (preset)').json()['page']
    dbid = pr['database']['id']
    check(pr['database'].get('purpose') == 'project' and pr['database'].get('color') == 'peach', 'Q:T-31 response database.purpose=project · color=peach (first in team space)')
    pr2 = expect('POST', '/api/pages', {'title': 'QF12B 둘째 프로젝트', 'database': True, 'preset': 'project', 'space_id': space_x}, 'B', 201, 'Q:T-31 second project').json()['page']
    check(pr2['database'].get('color') == 'lilac', 'Q:T-31 colour rotates by visible project count (second → lilac)')
    check(sql("SELECT purpose||'|'||color FROM community.databases WHERE public_id=" + lit(dbid)) == 'project|peach', 'Q:T-31 databases.purpose=project · color stored')
    rr = expect('GET', '/api/databases/' + dbid + '/rows', None, 'A', 200, 'Q:T-31 rows of project').json()
    codes['preset_schema'] = rr['database']['schema']
    codes['preset_views'] = strip_ids(rr['views'])
    check(rr['database']['schema'] == [{'key': 'section', 'name': '섹션', 'type': 'select', 'options': ['00. 준비', '01. 진행', '02. 마무리']}],
          'Q:T-31 preset schema = section select with 3 options')
    check(strip_ids(rr['views']) == [{'name': '목록', 'type': 'list', 'group_by': 'section'}, {'name': '보드', 'type': 'board', 'group_by': 'section'},
                                     {'name': '타임라인', 'type': 'timeline', 'date_prop': 'due', 'scale': 'month'},
                                     {'name': '달력', 'type': 'calendar', 'date_prop': 'due', 'mode': 'month'}]
          and all(re.fullmatch(r'v[0-9a-f]{8}', v['id']) for v in rr['views']), 'Q:T-31 preset views = 4 (list·board group_by section · timeline·calendar due)')
    check(sql('SELECT p.visibility FROM community.pages p WHERE p.public_id=' + lit(pr['id'])) == 'private', 'Q:T-31 project page created like any page (team space row)')
    expect('POST', '/api/pages', {'title': 'x', 'database': True, 'preset': 'kanban'}, 'A', 400, 'Q:T-31 unknown preset')
    expect('POST', '/api/pages', {'title': 'x', 'preset': 'project'}, 'A', 400, 'Q:T-31 preset without database')
    expect('POST', '/api/pages', {'title': 'x', 'database': True, 'preset': 'project', 'schema': []}, 'A', 400, 'Q:T-31 shape from request refused (extra key)')
    expect('POST', '/api/pages', {'title': 'x', 'database': True, 'preset': 'project', 'space_id': space_x}, 'C', 403, 'Q:T-31 other team cannot create in team X')
    # 편집 공유자는 섹션을 못 바꾼다(decidePageManage) — A 개인 공간 프로젝트를 B 에게 편집 공유
    pp = page('QF12B 개인 프로젝트', database=True, preset='project')
    ppdb = pp['database']['id']
    expect('PUT', '/api/pages/' + pp['id'] + '/shares/claude_manager01', {'level': 'edit'}, 'A', 200, 'Q:T-31 share project edit to B')
    four = ['00. 준비', '01. 진행', '02. 마무리', '03. 회고']
    a0 = n("SELECT count(*) FROM audit_logs WHERE action='database.schema' AND result='deny' AND reason='not_owner' AND actor_name='claude_manager01'")
    expect('PATCH', '/api/databases/' + ppdb + '/schema', {'options': {'key': 'section', 'options': four}}, 'B', 403, 'Q:T-31 edit-share B adds a section')
    check(n("SELECT count(*) FROM audit_logs WHERE action='database.schema' AND result='deny' AND reason='not_owner' AND actor_name='claude_manager01'") == a0 + 1,
          'Q:T-31 audit database.schema|deny|not_owner +1')
    expect('PATCH', '/api/databases/' + ppdb + '/schema', {'options': {'key': 'section', 'options': four}}, 'A', 200, 'Q:T-31 owner adds a section')
    check(rows_of(ppdb).json()['database']['schema'][0]['options'] == four, 'Q:T-31 section options now 4 in order')
    # 보드 묶기
    txt = expect('PATCH', '/api/databases/' + ppdb + '/schema', {'add': {'name': '메모', 'type': 'text'}}, 'A', 200, 'Q:T-31 add text prop').json()['database']['schema'][-1]['key']
    multi = expect('PATCH', '/api/databases/' + ppdb + '/schema', {'add': {'name': '태그', 'type': 'multi_select', 'options': ['가', '나']}}, 'A', 200, 'Q:T-31 add multi_select').json()['database']['schema'][-1]['key']
    sel2 = expect('PATCH', '/api/databases/' + ppdb + '/schema', {'add': {'name': '우선', 'type': 'select', 'options': ['높음', '낮음']}}, 'A', 200, 'Q:T-31 add select').json()['database']['schema'][-1]['key']
    vb = expect('PATCH', '/api/databases/' + ppdb + '/views', {'add': {'name': '섹션 보드 둘', 'type': 'board', 'group_by': 'section'}}, 'A', 200, 'Q:T-31 board group_by section 200').json()
    codes['group_by_stored'] = strip_ids([v for v in vb['views'] if v['name'] == '섹션 보드 둘'])
    check(sql("SELECT v FROM community.databases d, jsonb_array_elements(d.views) v WHERE d.public_id=" + lit(ppdb) + " AND v->>'name'='섹션 보드 둘'")
          .replace(' ', '').count('"group_by":"section"') == 1, 'Q:T-31 stored view has {"group_by":"section"}')
    expect('PATCH', '/api/databases/' + ppdb + '/views', {'add': {'name': '글 보드', 'type': 'board', 'group_by': txt}}, 'A', 400, 'Q:T-31 board group_by text prop 400')
    expect('PATCH', '/api/databases/' + ppdb + '/views', {'add': {'name': '없는 보드', 'type': 'board', 'group_by': 'p00000000'}}, 'A', 400, 'Q:T-31 board group_by missing key 400')
    expect('PATCH', '/api/databases/' + ppdb + '/views', {'add': {'name': '다중 보드', 'type': 'board', 'group_by': multi}}, 'A', 400, 'Q:T-31 board group_by multi_select 400')
    expect('PATCH', '/api/databases/' + ppdb + '/views', {'add': {'name': '표 묶기', 'type': 'table', 'group_by': 'section'}}, 'A', 400, 'Q:T-31 table view group_by 400')
    expect('PATCH', '/api/databases/' + ppdb + '/views', {'add': {'name': '상태 보드', 'type': 'board', 'group_by': 'status'}}, 'A', 200, 'Q:T-31 board group_by status 200')
    expect('PATCH', '/api/databases/' + ppdb + '/views', {'add': {'name': '우선 목록', 'type': 'list', 'group_by': sel2}}, 'A', 200, 'Q:T-31 list group_by other select 200')
    expect('PATCH', '/api/databases/' + ppdb + '/views', {'add': {'name': '편집 공유 보드', 'type': 'board', 'group_by': 'section'}}, 'B', 200, 'Q:T-31 edit-share may add a view (H-1 unchanged)')
    # 카드 옮기기 = 섹션 값 + 사건 db:section
    card = page('QF12B 카드', parent_id=pp['id'])
    mv = expect('PATCH', '/api/pages/' + card['id'] + '/values', {'values': {'section': '01. 진행'}}, 'A', 200, 'Q:T-31 move card to 01. 진행')
    check(mv.json()['values'].get('section') == '01. 진행', 'Q:T-31 values response section = 01. 진행')
    check(sql("SELECT prop||'|'||coalesce(from_value,'∅')||'|'||to_value FROM community.page_property_events WHERE page_id=" + str(pk(card['id']))
              + " AND prop LIKE 'db:%' ORDER BY id DESC LIMIT 1") == 'db:section|∅|01. 진행', 'Q:T-31 event db:section ∅ → 01. 진행')
    expect('PATCH', '/api/pages/' + card['id'] + '/values', {'values': {'section': '99. 없는 섹션'}}, 'A', 400, 'Q:T-31 value outside options 400')
    expect('PATCH', '/api/pages/' + card['id'] + '/values', {'values': {'section': '02. 마무리'}}, 'B', 200, 'Q:T-31 edit-share B moves the card (decidePageChange)')
    expect('PATCH', '/api/pages/' + card['id'] + '/values', {'values': {'section': '00. 준비'}}, 'C', 403, 'Q:T-31 C cannot move the card')
    # 섹션으로 거르기·정렬
    flt = json.dumps({'and': [{'prop': 'section', 'op': 'eq', 'value': '02. 마무리'}]}, ensure_ascii=False)
    check(rows_of(ppdb, 'A', '?filter=' + requests.utils.quote(flt)).json()['total'] == 1, 'Q:T-31 filter by section works')

    # ════════ 「프로젝트로 쓰기」·「표로 쓰기」 (WK-16 A) ═══════════════════════
    plain_db = page('QF12B 그냥 표', database=True)
    pdb = plain_db['database']['id']
    expect('PATCH', '/api/databases/' + pdb + '/views', {'add': {'name': '보드', 'type': 'board'}}, 'A', 200, 'use_as: existing view named 보드')
    u1 = expect('PATCH', '/api/databases/' + pdb + '/schema', {'use_as': 'project'}, 'A', 200, 'use_as project').json()
    check(u1['changed'] is True and u1['added'] == {'prop': True, 'views': ['목록', '타임라인', '달력']} and u1['database']['purpose'] == 'project'
          and u1['database']['color'] in ('peach', 'lilac', 'mint', 'sand'), 'use_as project: section added · missing views only (보드 kept) · purpose project')
    s1 = rows_of(pdb).json()
    u2 = expect('PATCH', '/api/databases/' + pdb + '/schema', {'use_as': 'project'}, 'A', 200, 'use_as project again').json()
    s2 = rows_of(pdb).json()
    check(u2['changed'] is False and u2['added'] == {'prop': False, 'views': []} and s2['database']['schema'] == s1['database']['schema']
          and s2['views'] == s1['views'], 'use_as project twice = idempotent (no duplicate prop/view)')
    check(sum(1 for p_ in s2['database']['schema'] if p_['key'] == 'section') == 1 and len({v['name'] for v in s2['views']}) == len(s2['views']) == 4,
          'use_as: one section prop · 4 uniquely named views')
    expect('PATCH', '/api/databases/' + pdb + '/schema', {'use_as': 'table'}, 'B', 403, 'use_as: B (no manage) 403')
    u3 = expect('PATCH', '/api/databases/' + pdb + '/schema', {'use_as': 'table'}, 'A', 200, 'use_as table').json()
    s3 = rows_of(pdb).json()
    check(u3['database']['purpose'] is None and sql('SELECT coalesce(purpose,\'∅\') FROM community.databases WHERE public_id=' + lit(pdb)) == '∅'
          and s3['database']['schema'] == s2['database']['schema'] and s3['views'] == s2['views'], 'use_as table: purpose NULL only (props · views kept)')
    expect('PATCH', '/api/databases/' + pdb + '/schema', {'use_as': 'kanban'}, 'A', 400, 'use_as unknown value 400')
    expect('PATCH', '/api/databases/' + pdb + '/schema', {'use_as': 'project', 'add': {'name': 'x', 'type': 'text'}}, 'A', 400, 'use_as with another op 400')
    clash = page('QF12B 섹션 이름 겹침', database=True)
    expect('PATCH', '/api/databases/' + clash['database']['id'] + '/schema', {'add': {'name': '섹션', 'type': 'text'}}, 'A', 200, 'use_as: user prop named 섹션')
    rc = expect('PATCH', '/api/databases/' + clash['database']['id'] + '/schema', {'use_as': 'project'}, 'A', 400, 'use_as: name 섹션 taken → 400')
    check(rc.json().get('reason') == 'name_taken', 'use_as: reason name_taken')
    check(n("SELECT count(*) FROM audit_logs WHERE action='database.schema' AND result='allow' AND detail->>'op'='use_as' AND target_id=" + str(pk(plain_db['id']))) == 2,
          'use_as: audit database.schema|allow op=use_as only for real changes (2 of 3 calls)')

    # ════════ 담당 칸(내장 assignee — 거르기·정렬만) ════════════════════════
    props(card['id'], {'assignee': 'claude_employee01'}, 'A', 200, 'assignee builtin: assign card to A (A window)')
    fa_ = json.dumps({'and': [{'prop': 'assignee', 'op': 'eq', 'value': 'claude_employee01'}]})
    check(rows_of(ppdb, 'A', '?filter=' + requests.utils.quote(fa_)).json()['total'] == 1, 'assignee builtin: filter eq me → 1')
    fe = json.dumps({'and': [{'prop': 'assignee', 'op': 'empty'}]})
    check(rows_of(ppdb, 'A', '?filter=' + requests.utils.quote(fe)).json()['total'] == 0, 'assignee builtin: filter empty → 0 (only row is assigned)')
    check(rows_of(ppdb, 'A', '?sort=' + requests.utils.quote(json.dumps([{'prop': 'assignee', 'dir': 'desc'}]))).status_code == 200, 'assignee builtin: sort 200')
    bad = rows_of(ppdb, 'A', '?filter=' + requests.utils.quote(json.dumps({'and': [{'prop': 'assignee', 'op': 'contains', 'value': 'x'}]})))
    check(bad.status_code == 400, 'assignee builtin: op contains 400')
    expect('PATCH', '/api/pages/' + card['id'] + '/values', {'values': {'assignee': 'claude_manager01'}}, 'A', 400, 'assignee builtin: no write through values (400)')
    expect('PATCH', '/api/databases/' + ppdb + '/views', {'add': {'name': '내 담당', 'type': 'table', 'filter': {'and': [{'prop': 'assignee', 'op': 'eq', 'value': 'claude_employee01'}]},
                                                             'sort': [{'prop': 'assignee', 'dir': 'asc'}]}}, 'A', 200, 'assignee builtin: saved view with assignee filter/sort')

    # ════════ Q:T-32 「내 할 일」 ════════════════════════════════════
    expect('POST', '/api/pages', {'my_tasks': True, 'title': 'x', 'parent_id': pp['id']}, 'C', 400, 'Q:T-32 my_tasks with parent_id 400')
    expect('POST', '/api/pages', {'my_tasks': False, 'title': 'x'}, 'C', 400, 'Q:T-32 my_tasks false 400')
    expect('POST', '/api/pages', {'title': 'x', 'due': today}, 'C', 400, 'Q:T-32 due without my_tasks 400')
    expect('POST', '/api/pages', {'my_tasks': True, 'title': 'x', 'due': '2026-02-30'}, 'C', 400, 'Q:T-32 bad due 400')
    check(n('SELECT count(*) FROM community.my_tasks_projects WHERE user_id=' + str(uid('C'))) == 0, 'Q:T-32 rejected requests create no project')
    # 두 탭 동시 — 서로 다른 PHP 프로세스 둘(포트 8768 · 8769)
    barrier = threading.Barrier(2)
    results = {}

    def quick(tag, port):
        barrier.wait()
        results[tag] = req('POST', '/api/pages', {'my_tasks': True, 'title': 'QF12B 동시 ' + tag, 'due': today}, 'C', port=port)
    th = [threading.Thread(target=quick, args=('1', None)), threading.Thread(target=quick, args=('2', SECOND_PORT))]
    for t_ in th: t_.start()
    for t_ in th: t_.join()
    check(results['1'].status_code == 201 and results['2'].status_code == 201, 'Q:T-32 two concurrent quick adds → 201 · 201')
    c_proj = {results[k].json()['my_tasks']['project_id'] for k in results}
    check(len(c_proj) == 1, 'Q:T-32 both rows went to the same project')
    check(sum(1 for k in results if results[k].json()['my_tasks']['created']) == 1, 'Q:T-32 exactly one request reports created=true')
    cid = uid('C')
    check(n('SELECT count(*) FROM community.my_tasks_projects WHERE user_id=' + str(cid)) == 1, 'Q:T-32 my_tasks_projects 1 row')
    check(n("SELECT count(*) FROM community.databases d JOIN community.pages p ON p.id=d.page_id JOIN community.spaces s ON s.id=p.space_id "
            "WHERE d.purpose='my_tasks' AND s.owner_user_id=" + str(cid)) == 1, 'Q:T-32 one my_tasks project')
    cp = next(iter(c_proj))
    check(sql('SELECT p.visibility||\'|\'||coalesce(p.parent_page_id::text,\'∅\')||\'|\'||p.title||\'|\'||s.kind FROM community.pages p JOIN community.spaces s ON s.id=p.space_id WHERE p.public_id=' + lit(cp))
          == 'private|∅|내 할 일|personal', 'Q:T-32 project is private · top level · titled 내 할 일 · personal space')
    check(n('SELECT count(*) FROM community.pages WHERE parent_page_id=' + str(pk(cp)) + " AND status='todo' AND due_date=CAST(" + lit(today) + ' AS date)') == 2,
          'Q:T-32 both rows under it (status todo · due set)')
    check(n("SELECT count(*) FROM audit_logs WHERE action='database.create' AND actor_name='claude_employee02' AND target_name='내 할 일'") == 1,
          'Q:T-32 one database.create audit for 내 할 일 (loser rolled back)')
    rb_ = req('GET', '/api/pages/' + cp, None, 'B')
    check(rb_.status_code == 403, 'Q:T-32 B reads C 내 할 일 → 403 (got ' + str(rb_.status_code) + ')')
    fc = flat(my_tasks('C'))
    check(all(results[k].json()['page']['id'] in fc and fc[results[k].json()['page']['id']][0] == 'today'
              and fc[results[k].json()['page']['id']][1]['project']['title'] == '내 할 일' for k in results), 'Q:T-32 quick-added rows appear in my-tasks (today · project 내 할 일)')
    # PK 충돌 갈래를 확정 재현 — 다른 연결이 M 의 줄을 먼저 적고(커밋 전) 잡고 있는 동안 빠른 추가 → 충돌 → 되돌림 → 다시 읽기
    winner = page('QF12B M 먼저 만든 할 일', 'M', database=True)
    sql("UPDATE community.databases SET purpose='my_tasks' WHERE public_id=" + lit(winner['database']['id']))
    mdb_before = n("SELECT count(*) FROM community.databases WHERE purpose='my_tasks'")
    env_ = {k: v for k, v in os.environ.items() if not k.startswith('PG')}
    env_['PGPASSFILE'] = str(Path.home() / '.config/qaredi/pgpass-testdb')
    holder = subprocess.Popen(['psql', '-h', '127.0.0.1', '-U', 'tbox_testdb', '-w', '-X', '-q', '-d', manifest['database'], '-v', 'ON_ERROR_STOP=1'],
                              stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, env=env_, text=True)
    holder.stdin.write('BEGIN; INSERT INTO community.my_tasks_projects(user_id,page_id) VALUES(' + str(uid('M')) + ',' + str(pk(winner['id']))
                       + '); SELECT pg_sleep(4); COMMIT;\n')
    holder.stdin.close()
    time.sleep(1.0)
    t0 = time.time()
    race = req('POST', '/api/pages', {'my_tasks': True, 'title': 'QF12B 경합 행'}, 'M')
    waited = time.time() - t0
    holder.wait(timeout=30)
    check(race.status_code == 201 and race.json()['my_tasks'] == {'project_id': winner['id'], 'created': False},
          'Q:T-32 forced PK conflict: request waits, rolls back its own project and uses the committed one (created=false)')
    check(waited > 1.5, 'Q:T-32 forced PK conflict: the request actually waited on the held row (' + str(round(waited, 1)) + 's)')
    check(n("SELECT count(*) FROM community.databases WHERE purpose='my_tasks'") == mdb_before and n('SELECT count(*) FROM community.my_tasks_projects WHERE user_id=' + str(uid('M'))) == 1,
          'Q:T-32 forced conflict leaves no extra project · one mapping row')
    check(n("SELECT count(*) FROM community.pages WHERE title='내 할 일' AND space_id=(SELECT id FROM community.spaces WHERE kind='personal' AND owner_user_id=" + str(uid('M')) + ')') == 0,
          "Q:T-32 forced conflict: the loser's 내 할 일 page did not survive")
    check(n('SELECT count(*) FROM community.pages WHERE parent_page_id=' + str(pk(winner['id'])) + " AND title='QF12B 경합 행'") == 1, 'Q:T-32 forced conflict: row added to the winner project')
    # 휴지통으로 가면 다음에 새로 만든다
    expect('DELETE', '/api/pages/' + cp, None, 'C', 200, 'Q:T-32 C trashes 내 할 일')
    again = expect('POST', '/api/pages', {'my_tasks': True, 'title': 'QF12B 휴지통 뒤'}, 'C', 201, 'Q:T-32 quick add after trash').json()
    check(again['my_tasks']['created'] is True and again['my_tasks']['project_id'] != cp
          and n('SELECT count(*) FROM community.my_tasks_projects WHERE user_id=' + str(cid)) == 1
          and sql('SELECT p.public_id FROM community.my_tasks_projects m JOIN community.pages p ON p.id=m.page_id WHERE m.user_id=' + str(cid)) == again['my_tasks']['project_id'],
          'Q:T-32 after trash: new project, mapping row replaced (still 1)')
    mt_db = sql('SELECT d.public_id FROM community.databases d JOIN community.pages p ON p.id=d.page_id WHERE p.public_id=' + lit(again['my_tasks']['project_id']))
    use_mt = req('PATCH', '/api/databases/' + mt_db + '/schema', {'use_as': 'table'}, 'C')
    check(use_mt.status_code == 400 and use_mt.json().get('reason') == 'my_tasks', 'Q:T-32 내 할 일 cannot be turned into a table (400 my_tasks)')
    # 프로그램 토큰으로는 my_tasks·preset·due 를 받지 않는다(Q:F-8 — 제목·본문만)
    tok = users['other']['community.write']['token']
    for body_ in ({'my_tasks': True, 'title': 'x'}, {'title': 'x', 'database': True, 'preset': 'project'}):
        rt_ = requests.post(base + '/api/pages', json=body_, headers={'User-Agent': 'tbox-review/' + time.strftime('%Y-%m-%d'), 'X-API-Token': tok}, timeout=30)
        check(rt_.status_code == 400 and rt_.json().get('reason') == 'token_scope_fields', 'Q:T-32 API token ' + ','.join(body_) + ' → 400 token_scope_fields')

    # ════════ 보드 화면 — 묶기 따르기 · 360·412·820·1440 · 콘솔 0 · 카드 옮기기 1회 ═══════════════
    from playwright.sync_api import sync_playwright
    host = urlsplit(site).hostname
    shots = out / 'shots'
    shots.mkdir(exist_ok=True)
    bp = page('QF12B 보드 프로젝트', database=True, preset='project')
    bcards = [page('QF12B 보드 카드 ' + str(i), parent_id=bp['id']) for i in range(3)]
    expect('PATCH', '/api/pages/' + bcards[1]['id'] + '/values', {'values': {'section': '01. 진행'}}, 'A', 200, 'board: card 1 in 01. 진행')
    board_view = next(v['id'] for v in rows_of(bp['database']['id']).json()['views'] if v['name'] == '보드')
    # 사용 안내 첫 창은 이 시험의 대상이 아니다 — 다른 화면 시험과 같은 방식으로 「닫음」 한 줄(시험 준비)
    sql("INSERT INTO community.usage_guides(user_id,version,status,context) SELECT id,1,'dismissed','overview' FROM users ON CONFLICT DO NOTHING")
    metrics = {}
    with sync_playwright() as pw:
        browser = pw.chromium.launch(executable_path=os.environ.get('CHROMIUM_EXECUTABLE'))
        try:
            for width, touch in ((360, False), (412, True), (412, False), (820, False), (1440, False)):
                tag = str(width) + ('t' if touch else '')
                ctx = browser.new_context(viewport={'width': width, 'height': 844 if width < 720 else 900}, locale='ko-KR',
                                          user_agent='tbox-review/' + time.strftime('%Y-%m-%d'), has_touch=touch, is_mobile=touch)
                ctx.add_cookies([{'name': '__Host-tbox_session', 'value': users['employee']['session'], 'domain': host, 'path': '/',
                                  'secure': True, 'httpOnly': True, 'sameSite': 'Lax'}])
                pg = ctx.new_page()
                cons, errs, bad5 = [], [], []
                pg.on('console', lambda m: cons.append(m.text))
                pg.on('pageerror', lambda e: errs.append(str(e)))
                pg.on('response', lambda r: bad5.append(r.url) if r.status >= 500 else None)
                pg.goto(base + '/p/' + bp['id'], wait_until='networkidle')
                pg.locator('[data-db-view="' + board_view + '"]').click()
                pg.locator('#db-board .db-board__col').first.wait_for()
                pg.wait_for_timeout(300)
                heads = pg.eval_on_selector_all('#db-board .db-board__head', 'els => els.map(e => e.textContent)')
                metrics['heads-' + tag] = heads
                check(heads == ['섹션 없음 2', '00. 준비 0', '01. 진행 1', '02. 마무리 0'], 'board ' + tag + ': columns follow group_by=section (섹션 없음 + 3 options in order)')
                over = pg.evaluate('document.documentElement.scrollWidth - document.documentElement.clientWidth')
                metrics['overflow-' + tag] = over
                check(over <= 0, 'board ' + tag + ': horizontal page overflow 0 (got ' + str(over) + ')')
                pg.screenshot(path=str(shots / ('board-' + tag + '.png')), full_page=False)
                if tag == '1440':
                    cardsel = '#db-board [data-row="' + bcards[0]['id'] + '"]'
                    pg.locator(cardsel).focus()
                    with pg.expect_response(lambda r: '/values' in r.url and r.request.method == 'PATCH') as resp:
                        pg.keyboard.press('ArrowRight')
                    check(resp.value.status == 200, 'board 1440: keyboard move (→) sends one values PATCH 200')
                    pg.wait_for_timeout(300)
                    heads2 = pg.eval_on_selector_all('#db-board .db-board__head', 'els => els.map(e => e.textContent)')
                    check(heads2 == ['섹션 없음 1', '00. 준비 1', '01. 진행 1', '02. 마무리 0'], 'board 1440: card moved to 00. 준비 on screen')
                    check(sql("SELECT properties->>'section' FROM community.pages WHERE public_id=" + lit(bcards[0]['id'])) == '00. 준비'
                          and sql("SELECT prop||'|'||coalesce(from_value,'∅')||'|'||to_value FROM community.page_property_events WHERE page_id=" + str(pk(bcards[0]['id']))
                                  + ' ORDER BY id DESC LIMIT 1') == 'db:section|∅|00. 준비', 'board 1440: stored section value + event db:section')
                    check(pg.evaluate('document.activeElement && document.activeElement.getAttribute("data-row")') == bcards[0]['id'], 'board 1440: focus stays on the moved card')
                    label = pg.locator(cardsel).get_attribute('aria-label')
                    check(label.endswith('← → 로 섹션 옮기기'), 'board 1440: card aria-label names the grouping (섹션)')
                    # 묶지 않은 보드(빠른 「보드」 단추)는 전처럼 상태 열
                    pg.locator('[data-db-view="' + board_view + '"]').click()
                    pg.wait_for_timeout(300)
                    pg.locator('[data-db-type="board"]').click()
                    pg.wait_for_timeout(300)
                    heads3 = pg.eval_on_selector_all('#db-board .db-board__head', 'els => els.map(e => e.textContent.replace(/ [0-9]+$/, ""))')
                    check(len(heads3) == 6 and heads3[0] == '없음', 'board 1440: ungrouped board keeps status columns (없음 + 5)')
                code_console = [c for c in cons if not c.startswith('Failed to load resource') and not c.startswith('[DOM] ')]
                metrics['console-' + tag] = code_console[:5]
                check(not errs and not bad5 and not code_console, 'board ' + tag + ': console 0 · page errors 0 · 5xx 0')
                ctx.close()
        finally:
            browser.close()
    codes['board_metrics'] = metrics
finally:
    (out / 'qf12-server-results.json').write_text(json.dumps({'checks': checks, 'confirmed_values': codes}, ensure_ascii=False, indent=2))
    fails = sum(not x['pass'] for x in checks)
    print('RESULT pass=' + str(sum(x['pass'] for x in checks)) + ' fail=' + str(fails), flush=True)
if any(not x['pass'] for x in checks):
    raise SystemExit(1)
