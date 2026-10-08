#!/usr/bin/env python3
"""Q:F-12 지시서 A-7 — 보안 바닥: 담당 · 프로젝트 상태 · 포트폴리오 · 표 권한 · 마이그레이션 (실제 PHP·PostgreSQL).

memories_harness.py 만 부른다(합성 DB `tbox_memories_test_*` · 임시 복사본 · 로컬 php -S, 끝나면 지운다).
운영 DB·운영 8082·시드 계정 0. 요청은 fixture 의 claude_ 계정과 UA tbox-review/<날짜> 뿐이다.
결과 파일에는 단언 이름과 참/거짓(+ 거부 코드·권한 오류 같은 **고정 문자열** 원문)만 — 응답 본문·세션·CSRF 0.

fixture (지시서 A-7): 팀 X 구성원 A=claude_employee01 · B=claude_manager01(쓰기) · 팀 Y 구성원 C=claude_employee02 ·
손님 G=claude_guest01 · Admin M=claude_admin01(이 시험에서 팀을 비운다 — 「관리자 열람」 갈래를 보려고, Q:T-36 의 한 갈래에서만
잠시 팀 X 로) · 비활성 I(합성 한 줄). A 의 개인 공간 비공개 P1 · B 에게 「보기」 공유 P2 · 「편집」 공유 P3 · 팀 X 공간 T1 ·
양식 데이터베이스 F(소유 A, 응답 1건).

  Q:T-24 담당 정하기        Q:T-25 담당 후보(빈 q · 판정 상한 50 — U1-a)  Q:T-26 맡겨도 권한 0  Q:T-27 양식 신원·응답 알림
  Q:T-28 기록 불변(권한)    Q:T-33 프로젝트 상태        Q:T-34 포트폴리오 읽기     Q:T-35 숨은 프로젝트
  Q:T-36 넣기 판정·사건     Q:T-37 진행률               마이그레이션 2회 멱등 · 옛 assignee_id 보존 · 새 설치 = 마이그레이션 SAME
"""
from pathlib import Path
exec(compile(Path(__file__).with_name('memories_http.py').read_text().split('try:\n for u in users:')[0], __file__, 'exec'))
import re
assert str(root).startswith('/tmp/qaredi-stage13-') and manifest['database'].startswith('tbox_memories_test_')

REFUSED = '이 사람에게는 맡길 수 없어요'
NAMES = {'A': 'employee', 'B': 'manager', 'C': 'other', 'G': 'guest', 'M': 'admin'}
codes = {}          # 확정한 값(거부 코드·권한 오류 원문 등 고정 문자열) — 결과 파일에 함께 적는다


def who(k): return NAMES[k]
def uname(k): return {'A': 'claude_employee01', 'B': 'claude_manager01', 'C': 'claude_employee02', 'G': 'claude_guest01', 'M': 'claude_admin01'}[k]
def uid(k): return int(users[who(k)]['id'])


def req(method, path, body=None, user='A'):
    """커뮤니티 창구 한 번 — 응답을 돌려주기만 하고 찍지 않는다."""
    return raw(method, path, body, who(user))


def expect(method, path, body, user, status, name):
    r = req(method, path, body, user)
    check(r.status_code == status, name + ' → ' + str(status) + ' (got ' + str(r.status_code) + ')')
    return r


def q1(text): return sql(text)
def n(text): return int((sql(text).splitlines() or ['0'])[0] or 0)


def pk(public_id): return n("SELECT id FROM community.pages WHERE public_id='" + public_id + "'")


def audits(action, result, reason=None, actor=None, target=None):
    w = "action='" + action + "' AND result='" + result + "'"
    if reason is not None: w += " AND reason='" + reason + "'"
    if actor is not None: w += " AND actor_name='" + actor + "'"
    if target is not None: w += ' AND target_id=' + str(target)
    return n('SELECT count(*) FROM audit_logs WHERE ' + w)


def new_page(title, user='A', **extra):
    body = dict(title=title, **extra)
    r = expect('POST', '/api/pages', body, user, 201, 'create page ' + title)
    return r.json()['page']


def make_project(title, user='A', color='mint', **extra):
    page = new_page(title, user, database=True, **extra)
    sql("UPDATE community.databases SET purpose='project', color='" + color + "' WHERE public_id='" + page['database']['id'] + "'")
    return page


def php(code):
    """격리 복사본의 앱 코드(앱 계정 연결 · 복사본 .env)로 PHP 한 조각 — 출력은 고정 문자열만 돌려받는다."""
    prelude = ("require 'src/Env.php'; Env::load(getcwd().'/.env'); require 'src/bootstrap.php';"
               "require_once 'src/community/CommunityAuthz.php';")
    done = subprocess.run(['php', '-r', prelude + code], cwd=root, capture_output=True, text=True, timeout=60)
    return done.stdout.strip()


try:
    # ════════ 준비 ════════════════════════════════════════════════
    for k in NAMES:
        expect('GET', '/api/me/space', None, k, 200, 'personal space ' + k)
    team_x = n("SELECT team_id FROM users WHERE username='claude_employee01'")
    team_y = n("SELECT team_id FROM users WHERE username='claude_employee02'")
    check(team_x > 0 and team_y > 0 and team_x != team_y and n("SELECT team_id FROM users WHERE username='claude_manager01'") == team_x,
          'fixture: A·B in team X, C in team Y')
    # M 은 이 시험에서 팀 없는 Admin(「관리자 열람」 갈래). 세션은 매 요청 users 를 다시 읽는다.
    sql("UPDATE users SET team_id=NULL WHERE username='claude_admin01'")
    inactive_id = n("INSERT INTO users(username,password_hash,role,team_id,is_active) VALUES('claude_qf12_inactive','x','Employee',"
                    + str(team_x) + ",false) RETURNING id")
    check(inactive_id > 0, 'fixture: inactive member I in team X')
    space_x = next(s['id'] for s in req('GET', '/api/spaces', None, 'A').json()['spaces'] if s['kind'] == 'team')
    space_y = next(s['id'] for s in req('GET', '/api/spaces', None, 'C').json()['spaces'] if s['kind'] == 'team')
    check(space_x != space_y, 'fixture: team spaces X and Y')

    P1 = new_page('QF12 P1 비공개')['id']
    P2 = new_page('QF12 P2 보기 공유')['id']
    P3 = new_page('QF12 P3 편집 공유')['id']
    T1 = new_page('QF12 T1 팀 작업', space_id=space_x)['id']
    expect('PUT', '/api/pages/' + P2 + '/shares/claude_manager01', {'level': 'view'}, 'A', 200, 'share P2 view to B')
    expect('PUT', '/api/pages/' + P3 + '/shares/claude_manager01', {'level': 'edit'}, 'A', 200, 'share P3 edit to B')

    # ════════ 정적 — 판정 함수가 담당을 허용 이유로 쓰지 않는다 ═══════════════
    authz = (root / 'src/community/CommunityAuthz.php').read_text()
    body_start = authz.index('public static function decidePageAssign')
    body_end = authz.index('/* ══', body_start)
    check('assignee_id' not in authz[body_start:body_end], 'static: no assignee_id inside the five new decide functions')
    check(not re.search(r"assignee_id'\]\s*\?\?", authz), "static: canSeeFormIdentity no longer reads assignee_id ??")
    check(not re.search(r"assignee_id'\]\s*\?\?", (root / 'src/community/forms_helpers.php').read_text()), 'static: forms_helpers no longer reads assignee_id ??')

    # 판정 함수 단위 — 이 경로는 HTTP 로 만들기 어려운 갈래(게시판 글 · 없는 대상 · 팀 없는 공간)
    unit = json.loads(php(
        "$A=['id'=>1,'role'=>'Employee','team_id'=>7];$M=['id'=>9,'role'=>'Admin','team_id'=>null];"
        "echo json_encode(["
        "'board'=>CommunityAuthz::decidePageAssign($A,['space_kind'=>'board','author_id'=>1]),"
        "'null_target'=>CommunityAuthz::decideAssignTarget(null,['space_kind'=>'team','space_team_id'=>7]),"
        "'guest_target'=>CommunityAuthz::decideAssignTarget(['id'=>3,'role'=>'Guest','team_id'=>7,'is_active'=>true],['space_kind'=>'team','space_team_id'=>7]),"
        "'team_target'=>CommunityAuthz::decideAssignTarget(['id'=>4,'role'=>'Employee','team_id'=>7,'is_active'=>true],['space_kind'=>'team','space_team_id'=>7]),"
        "'pf_personal'=>CommunityAuthz::decidePortfolioRead($A,['space_kind'=>'personal','space_team_id'=>null]),"
        "'pf_deleted'=>CommunityAuthz::decidePortfolioRead($A,['deleted_at'=>'2026-01-01','space_kind'=>'team','space_team_id'=>7]),"
        "'pf_admin_manage'=>CommunityAuthz::decidePortfolioManage($M,['space_kind'=>'team','space_team_id'=>7]),"
        "'pf_member_manage'=>CommunityAuthz::decidePortfolioManage($A,['space_kind'=>'team','space_team_id'=>7]),"
        "]);"))
    check(unit['board'] == [False, 'space_governed'], 'unit: decidePageAssign board post → space_governed')
    check(unit['null_target'] == [False, 'inactive'], 'unit: decideAssignTarget(null) → inactive')
    check(unit['guest_target'] == [False, 'not_writer'], 'unit: decideAssignTarget(Guest) → not_writer')
    check(unit['team_target'] == [True, 'can_edit'], 'unit: decideAssignTarget(team member) → can_edit')
    check(unit['pf_personal'] == [False, 'not_member'], 'unit: decidePortfolioRead non-team space → not_member')
    check(unit['pf_deleted'] == [False, 'not_found'], 'unit: decidePortfolioRead deleted → not_found')
    check(unit['pf_admin_manage'] == [False, 'not_member'], 'unit: decidePortfolioManage Admin without team → not_member (read only)')
    check(unit['pf_member_manage'] == [True, 'team_member'], 'unit: decidePortfolioManage team member writer → team_member')

    # ════════ Q:T-24 담당 정하기 ═════════════════════════════════════
    t1 = pk(T1)
    r = expect('PATCH', '/api/pages/' + T1 + '/properties', {'assignee': 'claude_manager01'}, 'A', 200, 'Q:T-24 A assigns T1 to B')
    check(r.json()['changed'] == ['assignee'] and r.json()['page']['assignee']['username'] == 'claude_manager01', 'Q:T-24 response changed=[assignee] and assignee B')
    check(q1("SELECT count(*)||'|'||coalesce(max(from_value),'∅')||'|'||max(to_value)||'|'||max(by_user_id) FROM community.page_property_events WHERE page_id=" + str(t1) + " AND prop='assignee'")
          == '1|∅|claude_manager01|' + str(uid('A')), 'Q:T-24 one page_property_events row prop=assignee, value = username, by A')
    check(n("SELECT count(*) FROM community.notifications WHERE kind='assigned' AND page_id=" + str(t1) + ' AND user_id=' + str(uid('B')) + ' AND actor_id=' + str(uid('A'))) == 1,
          'Q:T-24 one assigned notification to B')
    check(audits('page.assign', 'allow', None, 'claude_employee01', t1) == 1, 'Q:T-24 audit page.assign|allow 1')
    check(q1("SELECT detail->>'granted_by'||'|'||coalesce(detail->>'from','∅')||'|'||(detail->>'to') FROM audit_logs WHERE action='page.assign' AND result='allow' AND target_id=" + str(t1))
          == 'team_member|∅|claude_manager01', 'Q:T-24 audit detail granted_by=team_member · to=B')
    again = expect('PATCH', '/api/pages/' + T1 + '/properties', {'assignee': 'claude_manager01'}, 'A', 200, 'Q:T-24 same assignee again')
    check(again.json()['changed'] == [] and n("SELECT count(*) FROM community.page_property_events WHERE page_id=" + str(t1) + " AND prop='assignee'") == 1
          and audits('page.assign', 'allow', None, None, t1) == 1, 'Q:T-24 same value → no event, no audit')
    expect('PATCH', '/api/pages/' + P2 + '/properties', {'assignee': 'claude_manager01'}, 'B', 403, 'Q:T-24 B (view share) assigns P2')
    codes['B_on_P2'] = q1("SELECT reason FROM audit_logs WHERE action='page.assign' AND result='deny' AND actor_name='claude_manager01' ORDER BY id DESC LIMIT 1")
    check(codes['B_on_P2'] == 'not_owner', 'Q:T-24 audit page.assign|deny reason for view-share = not_owner')
    expect('PATCH', '/api/pages/' + T1 + '/properties', {'assignee': 'claude_employee01'}, 'G', 403, 'Q:T-24 Guest assigns')
    codes['G_route'] = q1("SELECT action||'|'||result||'|'||reason FROM audit_logs WHERE actor_name='claude_guest01' ORDER BY id DESC LIMIT 1")
    expect('PATCH', '/api/pages/' + P1 + '/properties', {'assignee': 'claude_employee01'}, 'M', 403, 'Q:T-24 Admin assigns A private P1')
    codes['M_on_P1'] = q1("SELECT reason FROM audit_logs WHERE action='page.assign' AND result='deny' AND actor_name='claude_admin01' ORDER BY id DESC LIMIT 1")
    check(codes['M_on_P1'] == 'not_owner', 'Q:T-24 audit page.assign|deny reason for Admin = not_owner')
    check(n('SELECT count(*) FROM community.pages WHERE assignee_id IS NOT NULL AND id IN (' + ','.join(str(pk(x)) for x in (P1, P2)) + ')') == 0,
          'Q:T-24 denied requests change nothing')

    # ════════ Q:T-25 담당 후보 ═══════════════════════════════════════
    refusals = {}
    for label, page, value, reason in [('B view-only on P2', P2, 'claude_manager01', 'assignee_cannot_edit'),
                                       ('C cannot see P1', P1, 'claude_employee02', 'assignee_cannot_edit'),
                                       ('Guest on P1', P1, 'claude_guest01', 'not_writer'),
                                       ('inactive I on P1', P1, 'claude_qf12_inactive', 'inactive'),
                                       ('unknown id on P1', P1, 'no_such_user_qf12', 'inactive')]:
        r = expect('PATCH', '/api/pages/' + page + '/properties', {'assignee': value}, 'A', 400, 'Q:T-25 ' + label)
        refusals[label] = r.text
        got = q1("SELECT reason FROM audit_logs WHERE action='page.assign' AND result='deny' AND actor_name='claude_employee01' AND detail->>'to'='" + value + "' ORDER BY id DESC LIMIT 1")
        codes['target:' + label] = got
        check(got == reason, 'Q:T-25 audit deny reason ' + label + ' = ' + reason)
    for label, value in [('number', 123), ('bad shape', 'Bad Name!'), ('empty', '')]:
        r = expect('PATCH', '/api/pages/' + P1 + '/properties', {'assignee': value}, 'A', 400, 'Q:T-25 malformed assignee ' + label)
        refusals['malformed ' + label] = r.text
    check(len(set(refusals.values())) == 1 and json.loads(next(iter(refusals.values()))) == {'error': REFUSED},
          'Q:T-25 every target refusal (unknown · malformed · inactive · guest · cannot edit) has the one identical body')
    codes['refused_body'] = next(iter(refusals.values()))
    check(n("SELECT count(*) FROM community.page_property_events WHERE prop='assignee' AND page_id IN (" + str(pk(P1)) + ',' + str(pk(P2)) + ')') == 0,
          'Q:T-25 refusals write no event')
    expect('PATCH', '/api/pages/' + P3 + '/properties', {'assignee': 'claude_manager01'}, 'A', 200, 'Q:T-25 A assigns P3 to B (edit share)')

    def cands(page, user='A', qtext='claude'):
        r = req('GET', '/api/pages/' + page + '/assignee-candidates?q=' + qtext, None, user)
        return r.status_code, sorted(c['username'] for c in r.json().get('candidates', [])) if r.status_code == 200 else None
    st, p2c = cands(P2)
    check(st == 200 and 'claude_manager01' not in p2c, 'Q:T-25 candidates P2: B (view only) absent')
    st, p1c = cands(P1)
    check(st == 200 and not ({'claude_employee02', 'claude_guest01', 'claude_qf12_inactive', 'claude_manager01', 'claude_admin01'} & set(p1c)),
          'Q:T-25 candidates P1: C · G · I · B · M absent')
    st, p3c = cands(P3)
    check(st == 200 and p3c.count('claude_manager01') == 1, 'Q:T-25 candidates P3: B (edit share) present once')
    st, t1c = cands(T1)
    check(st == 200 and set(t1c) == {'claude_employee01', 'claude_manager01'}, 'Q:T-25 candidates T1: exactly the active writers of team X (A, B)')
    check(all(set(c) == {'username', 'display_name'} for c in req('GET', '/api/pages/' + T1 + '/assignee-candidates?q=claude', None, 'A').json()['candidates']),
          'Q:T-25 candidate rows carry username/display_name only (no id/role/team)')
    check(cands(P2, 'B')[0] == 403, 'Q:T-25 caller who cannot assign (B on P2) → 403')
    check(cands(P1, 'M')[0] == 403, 'Q:T-25 Admin on A private page → 403')
    check(cands(T1, 'G')[0] == 403, 'Q:T-25 Guest → 403 (route policy community.write)')
    check(cands(P1, 'C')[0] == 403, 'Q:T-25 C cannot read P1 → 403')

    # ── Q:T-25 고침 — 빈 q(사용감 라운드 U1-a, 2026-10-08 · 기획 13장 · 커뮤니티 ARCH 23.3 「창구」 빈 q 줄) ──────────────
    #    빈 목록 대신 「그 작업을 고칠 수 있는 사람」 — 활성 사용자를 부르는 사람 먼저 · 아이디 순으로 50명까지 판정해 통과한 10명
    def cands_raw(page, user='A', qtext=''):
        r = req('GET', '/api/pages/' + page + '/assignee-candidates' + ('' if qtext is None else '?q=' + qtext), None, user)
        return r.status_code, (r.json() if r.status_code == 200 else None)

    def names_of(body):
        return [c['username'] for c in body['candidates']] if body else None
    st, e1 = cands_raw(P1)
    check(st == 200 and names_of(e1) == ['claude_employee01'], 'Q:T-25 empty q P1 (private, owner A) → [A] ' + json.dumps(names_of(e1)))
    started = time.time()
    st, et = cands_raw(T1)
    codes['empty_q_T1_ms'] = round((time.time() - started) * 1000)
    et_names = names_of(et) or []
    check(st == 200 and len(et_names) == 2 and set(et_names) == {'claude_employee01', 'claude_manager01'}, 'Q:T-25 empty q T1 → exactly {A, B} ' + json.dumps(et_names))
    check(et_names[:1] == ['claude_employee01'], 'Q:T-25 empty q T1 called by A → A is the first row')
    st, eb = cands_raw(T1, 'B')
    check(st == 200 and names_of(eb) == ['claude_manager01', 'claude_employee01'], 'Q:T-25 empty q T1 called by B → B first, then A (caller first · then username) ' + json.dumps(names_of(eb)))
    st, e2 = cands_raw(P2)
    check(st == 200 and 'claude_manager01' not in names_of(e2) and names_of(e2) == ['claude_employee01'], 'Q:T-25 empty q P2: B (view share only) absent → [A]')
    check(set(et) == {'candidates'} and all(set(c) == {'username', 'display_name'} for c in et['candidates']),
          'Q:T-25 empty q response: one top key (candidates) · rows carry the two keys username/display_name only')
    st, en = cands_raw(T1, 'A', None)
    check(st == 200 and names_of(en) == et_names, 'Q:T-25 no q parameter = empty q (same list)')
    check(cands_raw(P2, 'B')[0] == 403, 'Q:T-25 empty q: caller who cannot assign (B on P2) → 403')
    check(cands_raw(P1, 'M')[0] == 403, 'Q:T-25 empty q: Admin on A private page → 403')
    check(cands_raw(T1, 'G')[0] == 403, 'Q:T-25 empty q: Guest → 403')
    check(cands_raw(P1, 'C')[0] == 403, 'Q:T-25 empty q: C cannot read P1 → 403')

    # ── 빈 q 판정 상한 50 — 활성 합성 사용자 60명 넘게 ────────────────────────────────────────────────────────────
    #    아이디가 누구보다 앞에 오는 「T1 을 못 고치는」 합성 사용자(팀 Y) 60명 + 「T1 을 고칠 수 있는」 합성 사용자(팀 X) aaau1ok 하나.
    #    부르는 사람(A) 다음 순서가 막는 사람들이라, 판정이 몇 번째에서 멈추는지가 결과(aaau1ok · B 가 나오나)로 드러난다.
    blockers = ['aaau1blk%02d' % i for i in range(60)]
    sql('INSERT INTO users(username,password_hash,role,team_id,is_active) VALUES '
        + ','.join("('" + u + "','x','Employee'," + str(team_y) + ',true)' for u in blockers))
    sql("INSERT INTO users(username,password_hash,role,team_id,is_active) VALUES('aaau1ok','x','Employee'," + str(team_x) + ',true)')
    codes['cap_active_users'] = n('SELECT count(*) FROM users WHERE is_active')
    check(codes['cap_active_users'] > 60, 'cap fixture: active users > 60 (%d)' % codes['cap_active_users'])
    st, okq = cands_raw(T1, 'A', 'aaau1ok')
    check(st == 200 and names_of(okq) == ['aaau1ok'], 'cap fixture: aaau1ok can edit T1 (found by q) — absence below is the cap, not the rule')
    st, okb = cands_raw(T1, 'A', 'aaau1blk')
    check(st == 200 and names_of(okb) == [], 'cap fixture: blockers cannot edit T1 (q=aaau1blk → none)')
    st, c60 = cands_raw(T1)
    check(st == 200 and names_of(c60) == ['claude_employee01'],
          'cap: 60 blockers first → empty q judges A + 49 blockers (50) and stops → [A] (B · aaau1ok not judged) ' + json.dumps(names_of(c60)))
    sql("UPDATE users SET is_active=false WHERE username IN (" + ','.join("'" + u + "'" for u in blockers[49:]) + ')')
    st, c49 = cands_raw(T1)
    check(st == 200 and names_of(c49) == ['claude_employee01'], 'cap boundary: 49 blockers → aaau1ok is the 51st → not judged → [A] ' + json.dumps(names_of(c49)))
    sql("UPDATE users SET is_active=false WHERE username='aaau1blk48'")
    st, c48 = cands_raw(T1)
    check(st == 200 and names_of(c48) == ['claude_employee01', 'aaau1ok'],
          'cap boundary: 48 blockers → aaau1ok is the 50th → judged → [A, aaau1ok] (cap = exactly 50) ' + json.dumps(names_of(c48)))
    codes['empty_q_judge_cap'] = 50
    sql("UPDATE users SET is_active=false WHERE username LIKE 'aaau1%'")
    check(n("SELECT count(*) FROM users WHERE is_active AND username LIKE 'aaau1%'") == 0, 'cap fixture: synthetic cap users deactivated (later checks unaffected)')

    # ════════ Q:T-26 맡겨도 권한 0 ════════════════════════════════════
    g = req('GET', '/api/pages/' + T1, None, 'A').json()
    check(g['can_assign'] is True and g['assignee_can_edit'] is True and g['page']['assignee']['username'] == 'claude_manager01',
          'Q:T-26 GET T1 (A): can_assign true · assignee_can_edit true · assignee object unchanged shape')
    check(set(g['page']['assignee']) == {'username', 'role', 'is_active'}, 'Q:T-26 assignee object keys unchanged (username, role, is_active)')
    check(req('GET', '/api/pages/' + P1, None, 'M').json()['can_assign'] is False, 'Q:T-26 Admin reading P1: can_assign false')
    check(req('GET', '/api/pages/' + P1, None, 'A').json()['assignee_can_edit'] is None, 'Q:T-26 no assignee → assignee_can_edit null')
    check(req('GET', '/api/pages/' + P3, None, 'B').status_code == 200, 'Q:T-26 B reads P3 while shared')
    expect('DELETE', '/api/pages/' + P3 + '/shares/claude_manager01', None, 'A', 200, 'Q:T-26 A revokes B share on P3')
    rb = req('GET', '/api/pages/' + P3, None, 'B')
    check(rb.status_code in (403, 404), 'Q:T-26 B GET P3 after revoke → 403/404 (got ' + str(rb.status_code) + ')')
    ga = req('GET', '/api/pages/' + P3, None, 'A').json()
    check(ga['page']['assignee']['username'] == 'claude_manager01' and ga['assignee_can_edit'] is False,
          'Q:T-26 A GET P3: assignee kept · assignee_can_edit=false')
    expect('PATCH', '/api/pages/' + P3 + '/properties', {'status': 'done'}, 'B', 403, 'Q:T-26 assignee B cannot change P3 status')
    nb = req('GET', '/api/notifications', None, 'B')
    p3_title = 'QF12 P3 편집 공유'
    p3_rows = [x for x in nb.json()['notifications'] if x['kind'] == 'assigned' and x['page'] is not None and x['page']['id'] == P3]
    check(len(p3_rows) == 0 and p3_title not in nb.text and P3 not in nb.text,
          'Q:T-26 B notification list re-judged: assigned row for P3 has no page/title (0)')
    check(n("SELECT count(*) FROM community.notifications WHERE kind='assigned' AND user_id=" + str(uid('B')) + ' AND page_id=' + str(pk(P3))) == 1,
          'Q:T-26 the stored notification row itself is kept (filtering is at read time)')
    check(any(x['kind'] == 'assigned' and x['page'] and x['page']['id'] == T1 for x in nb.json()['notifications']),
          'Q:T-26 T1 assigned notification (still readable) keeps its page')
    sr = req('GET', '/api/search?q=' + 'QF12 P3', None, 'B')
    check(sr.status_code == 200 and P3 not in sr.text, 'Q:T-26 B search does not return P3')

    # ════════ Q:T-27 양식 응답자 신원 · 응답 알림 ═══════════════════════
    F = new_page('QF12 F 양식', database=True)
    fdid = F['database']['id']
    view = expect('PATCH', '/api/databases/' + fdid + '/views', {'add': {'name': '익명 접수', 'type': 'form', 'questions': [{'prop': 'title', 'required': True}],
                  'audience': 'internal', 'anonymous': True, 'open': True}}, 'A', 200, 'Q:T-27 form view').json()['views'][-1]
    expect('PUT', '/api/pages/' + F['id'] + '/shares/claude_manager01', {'level': 'edit'}, 'A', 200, 'Q:T-27 share F edit to B')
    endpoint = '/api/forms/' + fdid + '/' + view['id'] + '/submit'
    expect('POST', endpoint, {'values': {'title': 'QF12 응답 하나'}}, 'C', 201, 'Q:T-27 C submits response 1')
    sql("UPDATE community.pages SET assignee_id=" + str(uid('B')) + " WHERE public_id='" + F['id'] + "'")
    check(n("SELECT assignee_id FROM community.pages WHERE public_id='" + F['id'] + "'") == uid('B'), 'Q:T-27 F assignee = B prepared by SQL')
    rid = q1("SELECT p.public_id FROM community.pages p JOIN community.databases d ON d.id=p.database_id WHERE d.public_id='" + fdid + "' AND p.title='QF12 응답 하나'")
    rv = req('GET', '/api/pages/' + rid, None, 'B')
    check(rv.status_code == 200 and rv.json()['form_response']['hidden'] is True and 'claude_employee02' not in rv.text,
          'Q:T-27 assignee B (edit share) still cannot see respondent identity')
    ra = req('GET', '/api/pages/' + rid, None, 'A')
    check(ra.status_code == 200 and ra.json()['form_response']['hidden'] is False and 'claude_employee02' in ra.text,
          'Q:T-27 owner A still sees respondent identity')
    na0 = n("SELECT count(*) FROM community.notifications WHERE kind='form_response' AND user_id=" + str(uid('A')))
    nb0 = n("SELECT count(*) FROM community.notifications WHERE kind='form_response' AND user_id=" + str(uid('B')))
    expect('POST', endpoint, {'values': {'title': 'QF12 응답 둘'}}, 'C', 201, 'Q:T-27 C submits response 2')
    check(n("SELECT count(*) FROM community.notifications WHERE kind='form_response' AND user_id=" + str(uid('A'))) == na0 + 1
          and n("SELECT count(*) FROM community.notifications WHERE kind='form_response' AND user_id=" + str(uid('B'))) == nb0 == 0,
          'Q:T-27 form_response notification goes to owner A only (assignee B 0)')

    # ════════ Q:T-33 프로젝트 상태 ═══════════════════════════════════
    PX = make_project('QF12 팀 프로젝트 X', space_id=space_x)
    pxi = pk(PX['id'])
    expect('POST', '/api/projects/' + PX['id'] + '/status', {'status': 'on_track', 'note': '시작했어요'}, 'A', 200, 'Q:T-33 A sets status')
    expect('POST', '/api/projects/' + PX['id'] + '/status', {'status': 'on_track'}, 'G', 403, 'Q:T-33 Guest sets status')
    expect('POST', '/api/projects/' + PX['id'] + '/status', {'status': 'great'}, 'A', 400, 'Q:T-33 value outside five')
    expect('POST', '/api/projects/' + PX['id'] + '/status', {'status': 'at_risk', 'note': '가' * 281}, 'A', 400, 'Q:T-33 note 281 chars')
    expect('POST', '/api/projects/' + PX['id'] + '/status', {'status': 'at_risk', 'note': 'x', 'by': 'claude_admin01'}, 'A', 400, 'Q:T-33 extra key')
    expect('POST', '/api/projects/' + PX['id'] + '/status', {'status': 'at_risk', 'note': 'a\u0007b'}, 'A', 400, 'Q:T-33 control char in note')
    expect('POST', '/api/projects/' + F['id'] + '/status', {'status': 'on_track'}, 'A', 400, 'Q:T-33 database that is not a project')
    expect('POST', '/api/projects/' + P1 + '/status', {'status': 'on_track'}, 'A', 400, 'Q:T-33 plain page')
    expect('POST', '/api/projects/' + PX['id'] + '/status', {'status': 'on_track'}, 'C', 403, 'Q:T-33 other team C')
    expect('POST', '/api/projects/' + PX['id'] + '/status', {'status': 'on_track'}, 'M', 403, 'Q:T-33 Admin (read only) sets status')
    codes['M_status'] = q1("SELECT reason FROM audit_logs WHERE action='project.status' AND result='deny' AND actor_name='claude_admin01' ORDER BY id DESC LIMIT 1")
    check(codes['M_status'] == 'not_member', 'Q:T-33 audit project.status|deny reason for Admin = not_member')
    expect('POST', '/api/projects/' + PX['id'] + '/status', {'status': 'at_risk', 'note': '나' * 280}, 'B', 200, 'Q:T-33 B (team member) 280 chars')
    cur = expect('GET', '/api/projects/' + PX['id'] + '/status', None, 'A', 200, 'Q:T-33 GET status').json()
    check(cur['current']['status'] == 'at_risk' and cur['current']['by']['username'] == 'claude_manager01' and len(cur['updates']) == 2
          and cur['updates'][1]['status'] == 'on_track', 'Q:T-33 current = last row (at_risk by B), history 2')
    check(len(req('GET', '/api/projects/' + PX['id'] + '/status?limit=1', None, 'A').json()['updates']) == 1, 'Q:T-33 limit=1')
    for bad in ('0', '51', 'x'):
        expect('GET', '/api/projects/' + PX['id'] + '/status?limit=' + bad, None, 'A', 400, 'Q:T-33 limit ' + bad)
    expect('GET', '/api/projects/' + PX['id'] + '/status', None, 'C', 403, 'Q:T-33 C GET status')
    expect('GET', '/api/projects/' + F['id'] + '/status', None, 'A', 400, 'Q:T-33 GET on non-project DB')
    check(req('PATCH', '/api/projects/' + PX['id'] + '/status', {'status': 'complete'}, 'A').status_code == 405
          and req('DELETE', '/api/projects/' + PX['id'] + '/status', None, 'A').status_code == 405, 'Q:T-33 no update/delete endpoint (405)')
    check(audits('project.status', 'allow', None, None, pxi) == 2, 'Q:T-33 audit project.status|allow 2')
    check(n('SELECT count(*) FROM community.project_status_updates WHERE page_id=' + str(pxi)) == 2, 'Q:T-33 two status rows')

    # ════════ Q:T-34 포트폴리오 읽기 ══════════════════════════════════
    pf = expect('POST', '/api/portfolios', {'space': space_x, 'name': 'QF12 포트폴리오'}, 'A', 201, 'Q:T-34 A creates PF in team X').json()['portfolio']
    PF = pf['id']
    pfi = n("SELECT id FROM community.portfolios WHERE public_id='" + PF + "'")
    expect('GET', '/api/portfolios/' + PF, None, 'A', 200, 'Q:T-34 A reads PF')
    expect('GET', '/api/portfolios/' + PF, None, 'B', 200, 'Q:T-34 B reads PF')
    c404 = expect('GET', '/api/portfolios/' + PF, None, 'C', 404, 'Q:T-34 C (other team) reads PF')
    g404 = expect('GET', '/api/portfolios/' + PF, None, 'G', 404, 'Q:T-34 Guest reads PF')
    a0 = audits('portfolio.read', 'allow', 'admin_all', 'claude_admin01')
    expect('GET', '/api/portfolios/' + PF, None, 'M', 200, 'Q:T-34 Admin reads PF')
    check(audits('portfolio.read', 'allow', 'admin_all', 'claude_admin01') == a0 + 1, 'Q:T-34 audit portfolio.read|allow|admin_all 1')
    x404 = expect('GET', '/api/portfolios/' + str(uuid.uuid4()), None, 'A', 404, 'Q:T-34 unknown id')
    check(c404.text == g404.text == x404.text, 'Q:T-34 404 body identical for other team · guest · unknown id')
    codes['pf_404_body'] = x404.text
    check(audits('portfolio.read', 'deny', 'not_member', 'claude_employee02') >= 1, 'Q:T-34 audit portfolio.read|deny|not_member for C')
    lst = expect('GET', '/api/spaces/' + str(space_x) + '/portfolios', None, 'B', 200, 'Q:T-34 B lists team X portfolios').json()
    check([p['id'] for p in lst['portfolios']] == [PF] and lst['can_create'] is True, 'Q:T-34 list has PF · can_create for member')
    expect('GET', '/api/spaces/' + str(space_x) + '/portfolios', None, 'C', 404, 'Q:T-34 C lists team X portfolios')
    ml = expect('GET', '/api/spaces/' + str(space_x) + '/portfolios', None, 'M', 200, 'Q:T-34 Admin lists').json()
    check(ml['can_create'] is False, 'Q:T-34 Admin list can_create false (read only)')
    expect('POST', '/api/portfolios', {'space': space_x, 'name': 'QF12 관리자'}, 'M', 403, 'Q:T-34 Admin cannot create')
    expect('POST', '/api/portfolios', {'space': space_x, 'name': 'QF12 다른 팀'}, 'C', 404, 'Q:T-34 C cannot create in team X (404)')
    expect('POST', '/api/portfolios', {'space': n("SELECT id FROM community.spaces WHERE kind='personal' AND owner_user_id=" + str(uid('A'))), 'name': 'QF12 개인'},
           'A', 404, 'Q:T-34 portfolio in a personal space refused (404)')
    expect('POST', '/api/portfolios', {'space': space_x, 'name': 'qf12 포트폴리오'}, 'B', 409, 'Q:T-34 duplicate live name (case-insensitive) 409')
    for label, nm in [('empty', '   '), ('81 chars', 'a' * 81), ('control', 'a\u0007')]:
        expect('POST', '/api/portfolios', {'space': space_x, 'name': nm}, 'A', 400, 'Q:T-34 bad name ' + label)
    expect('POST', '/api/portfolios', {'space': space_x, 'name': 'x', 'role': 'Admin'}, 'A', 400, 'Q:T-34 extra key')

    # ════════ Q:T-35 숨은 프로젝트 ═══════════════════════════════════
    PA = make_project('QF12 비밀 프로젝트 PA', color='lilac')          # A 개인 공간 · 비공개
    expect('POST', '/api/projects/' + PA['id'] + '/status', {'status': 'off_track', 'note': '비밀 진행'}, 'A', 200, 'Q:T-35 status on hidden PA')
    expect('POST', '/api/portfolios/' + PF + '/items', {'project': PA['id']}, 'A', 201, 'Q:T-35 A adds own private PA')
    expect('POST', '/api/portfolios/' + PF + '/items', {'project': PX['id']}, 'A', 201, 'Q:T-35 A adds team PX')
    check(req('POST', '/api/portfolios/' + PF + '/items', {'project': PX['id']}, 'A').json() == {'added': False}, 'Q:T-35 adding twice is a no-op')
    va = req('GET', '/api/portfolios/' + PF, None, 'A').json()
    check(sorted(i['project']['id'] for i in va['items']) == sorted([PA['id'], PX['id']]) and va['total'] == 2
          and va['by_status']['off_track'] == 1 and va['by_status']['at_risk'] == 1, 'Q:T-35 A sees both (total 2 · off_track 1 · at_risk 1)')
    vbr = req('GET', '/api/portfolios/' + PF, None, 'B')
    vb = vbr.json()
    check([i['project']['id'] for i in vb['items']] == [PX['id']] and vb['total'] == 1, 'Q:T-35 B sees only PX (total 1)')
    check(sum(vb['by_status'].values()) == 1 and vb['by_status']['off_track'] == 0, 'Q:T-35 B status counts exclude hidden PA')
    check('QF12 비밀 프로젝트 PA' not in vbr.text and PA['id'] not in vbr.text and '비밀 진행' not in vbr.text,
          'Q:T-35 B raw response has no PA title · public_id · status note')
    check(not any(k in vbr.text for k in ('hidden', 'skipped', 'omitted')), 'Q:T-35 no hidden-count field in response')
    check(set(vb) == {'portfolio', 'can_manage', 'total', 'by_status', 'items'}, 'Q:T-35 response keys fixed')
    expect('DELETE', '/api/portfolios/' + PF + '/items/' + PA['id'], None, 'B', 404, 'Q:T-35 B cannot remove the project it cannot see (404)')
    check(n('SELECT count(*) FROM community.portfolio_items WHERE portfolio_id=' + str(pfi) + ' AND page_id=' + str(pk(PA['id']))) == 1, 'Q:T-35 PA item kept')

    # ════════ Q:T-36 넣기 판정 · 사건 ════════════════════════════════
    PY = make_project('QF12 팀 Y 프로젝트', user='C', space_id=space_y)
    expect('POST', '/api/portfolios/' + PF + '/items', {'project': PY['id']}, 'M', 403, 'Q:T-36 Admin (no team) adds team Y project')
    codes['M_add_noteam'] = q1("SELECT reason FROM audit_logs WHERE action='portfolio.add' AND result='deny' AND actor_name='claude_admin01' ORDER BY id DESC LIMIT 1")
    check(codes['M_add_noteam'] == 'not_member', 'Q:T-36 audit portfolio.add|deny Admin = not_member')
    # Admin 이 팀 X 구성원이기도 한 경우 — 관리할 수 있지만, 관리자 특권으로만 보이는 프로젝트는 넣지 못한다
    sql('UPDATE users SET team_id=' + str(team_x) + " WHERE username='claude_admin01'")
    expect('POST', '/api/portfolios/' + PF + '/items', {'project': PY['id']}, 'M', 403, 'Q:T-36 Admin in team X adds project readable only as admin_all')
    codes['M_add_team'] = q1("SELECT reason FROM audit_logs WHERE action='portfolio.add' AND result='deny' AND actor_name='claude_admin01' ORDER BY id DESC LIMIT 1")
    check(codes['M_add_team'] == 'project_not_readable', 'Q:T-36 audit portfolio.add|deny = project_not_readable')
    sql("UPDATE users SET team_id=NULL WHERE username='claude_admin01'")
    check(n('SELECT count(*) FROM community.portfolio_items WHERE page_id=' + str(pk(PY['id']))) == 0, 'Q:T-36 PY never added')
    expect('POST', '/api/portfolios/' + PF + '/items', {'project': F['id']}, 'A', 400, 'Q:T-36 non-project database')
    expect('POST', '/api/portfolios/' + PF + '/items', {'project': P1}, 'A', 400, 'Q:T-36 plain page')
    expect('POST', '/api/portfolios/' + PF + '/items', {'project': PY['id']}, 'C', 404, 'Q:T-36 other team C adds (404)')
    check(audits('portfolio.add', 'deny', 'not_member', 'claude_employee02') == 1, 'Q:T-36 audit portfolio.add|deny|not_member for C')
    expect('POST', '/api/portfolios/' + PF + '/items', {'project': PY['id']}, 'B', 403, 'Q:T-36 B adds project B cannot see')
    expect('POST', '/api/portfolios/' + PF + '/items', {'project': str(uuid.uuid4())}, 'A', 404, 'Q:T-36 unknown project')
    expect('POST', '/api/portfolios/' + PF + '/items', {'project': 'not-a-uuid'}, 'A', 400, 'Q:T-36 malformed project id')
    pf2 = expect('POST', '/api/portfolios', {'space': space_x, 'name': 'QF12 사건 포트폴리오'}, 'B', 201, 'Q:T-36 B creates PF2').json()['portfolio']
    pf2i = n("SELECT id FROM community.portfolios WHERE public_id='" + pf2['id'] + "'")
    expect('POST', '/api/portfolios/' + pf2['id'] + '/items', {'project': PX['id']}, 'B', 201, 'Q:T-36 add')
    expect('DELETE', '/api/portfolios/' + pf2['id'] + '/items/' + PX['id'], None, 'B', 200, 'Q:T-36 remove')
    expect('DELETE', '/api/portfolios/' + pf2['id'] + '/items/' + PX['id'], None, 'B', 404, 'Q:T-36 remove again 404')
    expect('PATCH', '/api/portfolios/' + pf2['id'], {'name': 'QF12 바뀐 이름'}, 'A', 200, 'Q:T-36 rename')
    expect('PATCH', '/api/portfolios/' + pf2['id'], {'name': 'QF12 포트폴리오'}, 'A', 409, 'Q:T-36 rename to existing name 409')
    expect('PATCH', '/api/portfolios/' + pf2['id'], {'name': 'QF12 새 이름'}, 'M', 403, 'Q:T-36 Admin cannot rename')
    expect('PATCH', '/api/portfolios/' + pf2['id'], {'name': 'QF12 새 이름'}, 'C', 404, 'Q:T-36 C cannot rename (404)')
    expect('DELETE', '/api/portfolios/' + pf2['id'], None, 'M', 403, 'Q:T-36 Admin cannot delete')
    expect('DELETE', '/api/portfolios/' + pf2['id'], None, 'A', 200, 'Q:T-36 delete')
    gone = expect('GET', '/api/portfolios/' + pf2['id'], None, 'A', 404, 'Q:T-36 deleted portfolio reads 404')
    check(gone.text == codes['pf_404_body'], 'Q:T-36 deleted portfolio 404 body same as unknown id')
    check(q1("SELECT string_agg(kind,',' ORDER BY id) FROM community.portfolio_events WHERE portfolio_id=" + str(pf2i)) == 'create,add,remove,rename,delete',
          'Q:T-36 portfolio_events: create,add,remove,rename,delete')
    check(q1("SELECT coalesce(from_value,'∅')||'>'||coalesce(to_value,'∅') FROM community.portfolio_events WHERE kind='rename' AND portfolio_id=" + str(pf2i))
          == 'QF12 사건 포트폴리오>QF12 바뀐 이름', 'Q:T-36 rename event keeps old→new name')
    check(n('SELECT count(*) FROM community.portfolios WHERE id=' + str(pf2i) + ' AND deleted_at IS NOT NULL') == 1, 'Q:T-36 delete keeps the row (deleted_at)')
    check(all(audits('portfolio.' + a, 'allow', None, None, pf2i) == 1 for a in ('create', 'add', 'remove', 'rename', 'delete')),
          'Q:T-36 audit portfolio.create|add|remove|rename|delete allow 1 each')
    check(audits('portfolio.rename', 'deny', 'not_member', 'claude_admin01', pf2i) == 1 and audits('portfolio.delete', 'deny', 'not_member', 'claude_admin01', pf2i) == 1,
          'Q:T-36 Admin rename/delete denials audited (not_member)')

    # ════════ Q:T-37 진행률 — 보는 사람이 볼 수 있는 행만 ═══════════════
    PQ = make_project('QF12 팀 범위 프로젝트', color='sand')
    expect('PATCH', '/api/pages/' + PQ['id'] + '/visibility', {'visibility': 'team'}, 'A', 200, 'Q:T-37 PQ visibility team (B same team sees it)')
    rows = [expect('POST', '/api/pages', {'title': 'QF12 행 ' + str(i), 'parent_id': PQ['id'], 'properties': {}}, 'A', 201, 'Q:T-37 row ' + str(i)).json()['page']['id']
            for i in range(1, 6)]
    for r_ in rows[:3]:
        expect('PATCH', '/api/pages/' + r_ + '/properties', {'status': 'done'}, 'A', 200, 'Q:T-37 row done')
    for r_ in (rows[0], rows[3]):
        expect('PATCH', '/api/pages/' + r_ + '/visibility', {'visibility': 'private'}, 'A', 200, 'Q:T-37 hide row from B (private child)')
    check(req('GET', '/api/pages/' + rows[0], None, 'B').status_code == 403 and req('GET', '/api/pages/' + rows[1], None, 'B').status_code == 200,
          'Q:T-37 fixture: B cannot read hidden rows, can read others')
    expect('POST', '/api/portfolios/' + PF + '/items', {'project': PQ['id']}, 'A', 201, 'Q:T-37 add PQ')
    pa_ = next(i for i in req('GET', '/api/portfolios/' + PF, None, 'A').json()['items'] if i['project']['id'] == PQ['id'])
    pb_ = next(i for i in req('GET', '/api/portfolios/' + PF, None, 'B').json()['items'] if i['project']['id'] == PQ['id'])
    check(pa_['progress'] == {'done': 3, 'total': 5}, 'Q:T-37 A progress 3/5')
    check(pb_['progress'] == {'done': 2, 'total': 3}, 'Q:T-37 B progress 2/3 (numerator and denominator count visible rows only)')
    check(pb_['owner'] == {'username': 'claude_employee01', 'display_name': None} and pb_['project']['color'] == 'sand',
          'Q:T-37 project owner = space owner · color')

    # ════════ A-8 검토 반영(재지시 1) — RV-A1 · RV-A2 ═════════════════════
    # 고치기 전 판(b9e3c2c)에서 둘 다 실패함을 한 번에 보이려고, 이 절의 단언은 **모아 두었다가** 절 끝에서 한꺼번에 판정한다
    # (앞 단언이 실패해도 RV-A2 까지 돌아 FAIL 줄이 모두 찍힌다). 결과 파일에는 이름·참/거짓만 남는 것은 같다.
    a8_failed = []

    def soft(ok, name):
        checks.append({'pass': bool(ok), 'name': name})
        print(('PASS ' if ok else 'FAIL ') + name, flush=True)
        if not ok:
            a8_failed.append(name)

    # 팀 X 구성원이기도 한 Admin MX — 시험 준비로만 한 줄(fixture 와 같은 모양의 세션 · CSRF, 결과 파일에 값 0)
    mx_id = n("INSERT INTO users(username,password_hash,role,team_id,mfa_secret,mfa_enabled,expires_at) VALUES('claude_qf12_admin_x','x','Admin',"
              + str(team_x) + ",'JBSWY3DPEHPK3PXP',true,clock_timestamp()+interval '1 day') RETURNING id")
    mx_session = uuid.uuid4().hex + uuid.uuid4().hex
    mx_hash = hashlib.sha256(mx_session.encode()).hexdigest()
    sql("INSERT INTO sessions(id,user_id,expires_at,ip,user_agent) VALUES('" + mx_hash + "'," + str(mx_id)
        + ",clock_timestamp()+interval '8 hours','127.0.0.1','tbox-review/" + time.strftime('%Y-%m-%d') + "')")
    # 사이트 Q:F-13 P1 — MX 도 fixture claude_ 처럼 「이미 동의한 기존 회원」이다(동의 관문은 이 시험의 대상이 아니다 — 같은 동의 줄을 옮겨 둔다)
    sql("INSERT INTO user_consents(user_id,doc,version,action) SELECT " + str(mx_id) + ",doc,version,action FROM user_consents"
        + " WHERE user_id=(SELECT id FROM users WHERE username='claude_admin01') ORDER BY id")
    users['admin_x'] = {'id': mx_id, 'session': mx_session,
                        'csrf': php("echo hash_hmac('sha256','csrf:" + mx_hash + "',Env::get('APP_SECRET'));")}
    NAMES['MX'] = 'admin_x'
    check(req('GET', '/api/me/space', None, 'MX').status_code == 200 and n("SELECT team_id FROM users WHERE id=" + str(mx_id)) == team_x
          and n("SELECT team_id FROM users WHERE username='claude_admin01'") == 0, 'A-8 fixture: Admin MX in team X (session works) · Admin M without team')

    # RV-A1 준비 — 사내 공개 프로젝트 PI(A 개인 공간): 행 4개 중 2개(완료 1 · 미완료 1)를 비공개로. M 은 관리자 특권 없이도
    # 사내 공개로 PI 를 보므로, 「관리자 열람으로만 보이는 행이 진행률 분모에 들어가는가」를 M 에게서도 잰다.
    PI = make_project('QF12 사내 프로젝트 PI', color='peach')
    expect('PATCH', '/api/pages/' + PI['id'] + '/visibility', {'visibility': 'internal'}, 'A', 200, 'A-8 PI visibility internal')
    pi_rows = [expect('POST', '/api/pages', {'title': 'QF12 PI 행 ' + str(i), 'parent_id': PI['id'], 'properties': {}}, 'A', 201,
                      'A-8 PI row ' + str(i)).json()['page']['id'] for i in range(1, 5)]
    for r_ in pi_rows[:2]:
        expect('PATCH', '/api/pages/' + r_ + '/properties', {'status': 'done'}, 'A', 200, 'A-8 PI row done')
    for r_ in (pi_rows[0], pi_rows[2]):
        expect('PATCH', '/api/pages/' + r_ + '/visibility', {'visibility': 'private'}, 'A', 200, 'A-8 PI hide row (private child)')
    check(req('GET', '/api/pages/' + pi_rows[0], None, 'C').status_code == 403 and req('GET', '/api/pages/' + pi_rows[1], None, 'C').status_code == 200
          and req('GET', '/api/pages/' + pi_rows[0], None, 'M').status_code == 200,
          'A-8 fixture: hidden PI rows unreadable for a writer (C) · readable for Admin M only by admin_all')
    expect('POST', '/api/portfolios/' + PF + '/items', {'project': PI['id']}, 'A', 201, 'A-8 add PI to PF')
    pa_title, pa_note = 'QF12 비밀 프로젝트 PA', '비밀 진행'

    for label, viewer in [('Admin M (admin_all, no team)', 'M'), ('Admin MX (also team X member)', 'MX')]:
        rr = req('GET', '/api/portfolios/' + PF, None, viewer)
        soft(rr.status_code == 200, 'RV-A1 ' + label + ' reads PF → 200 (got ' + str(rr.status_code) + ')')
        body = rr.json() if rr.status_code == 200 else {'items': [], 'total': -1, 'by_status': {}}
        ids = [i['project']['id'] for i in body['items']]
        soft(PA['id'] not in ids, 'RV-A1 ' + label + ': A private project PA row 0')
        soft(body['total'] == len(ids) and body['by_status'].get('off_track', -1) == 0 and sum(body['by_status'].values()) == len(ids),
             'RV-A1 ' + label + ': total · by_status do not count PA (off_track 0)')
        soft(pa_title not in rr.text and PA['id'] not in rr.text and pa_note not in rr.text,
             'RV-A1 ' + label + ': raw response has no PA title · public_id · status note')
        pi_item = next((i for i in body['items'] if i['project']['id'] == PI['id']), None)
        soft(pi_item is not None and pi_item['progress'] == {'done': 1, 'total': 2},
             'RV-A1 ' + label + ': PI progress 1/2 — rows visible only by admin_all are not in numerator/denominator')
        codes['rv_a1_' + viewer] = {'ids': sorted('PA' if x == PA['id'] else 'PX' if x == PX['id'] else 'PQ' if x == PQ['id'] else 'PI' if x == PI['id'] else '?' for x in ids),
                                    'total': body['total'], 'off_track': body['by_status'].get('off_track'),
                                    'pi_progress': None if pi_item is None else pi_item['progress'],
                                    'pq_progress': next((i['progress'] for i in body['items'] if i['project']['id'] == PQ['id']), None)}
    soft(codes['rv_a1_M']['ids'] == ['PI'], 'RV-A1 Admin M (no team) sees exactly the project it can see as itself (PI) — not PA · PX · PQ')
    soft(codes['rv_a1_MX']['ids'] == ['PI', 'PQ', 'PX'] and codes['rv_a1_MX']['pq_progress'] == {'done': 2, 'total': 3},
         'RV-A1 Admin MX (team X) sees PI · PQ · PX like a member · PQ progress 2/3 (same as member B)')
    vb2 = req('GET', '/api/portfolios/' + PF, None, 'B').json()
    soft(sorted(i['project']['id'] for i in vb2['items']) == sorted([PX['id'], PQ['id'], PI['id']]), 'RV-A1 member B unchanged: PX · PQ · PI')

    # RV-A2 ① 볼 수 없는 사람(C → A 의 비공개 P1)이 속성 바꾸기 → 403 · 감사 page.read|deny|not_owner DB 행 +1
    p1i = pk(P1)
    before_read = audits('page.read', 'deny', 'not_owner', 'claude_employee02', p1i)
    r_ = req('PATCH', '/api/pages/' + P1 + '/properties', {'status': 'done'}, 'C')
    soft(r_.status_code == 403, 'RV-A2 C (cannot read P1) PATCH properties → 403 (got ' + str(r_.status_code) + ')')
    after_read = audits('page.read', 'deny', 'not_owner', 'claude_employee02', p1i)
    codes['rv_a2_page_read_rows'] = str(before_read) + '→' + str(after_read)
    soft(after_read == before_read + 1, 'RV-A2 audit_logs page.read|deny|not_owner DB row +1 (' + codes['rv_a2_page_read_rows'] + ')')
    soft(q1("SELECT coalesce(status,'∅') FROM community.pages WHERE id=" + str(p1i)) == '∅', 'RV-A2 P1 status unchanged')

    # RV-A2 ② 볼 수는 있으나 고칠 수 없는 사람(C → A 의 사내 공개 페이지) → 403 · 감사 page.property|deny|not_owner DB 행 +1 · 값 불변
    PN = new_page('QF12 사내 공개 문서')['id']
    expect('PATCH', '/api/pages/' + PN + '/visibility', {'visibility': 'internal'}, 'A', 200, 'RV-A2 PN visibility internal')
    pni = pk(PN)
    check(req('GET', '/api/pages/' + PN, None, 'C').status_code == 200, 'RV-A2 fixture: C can read internal PN')
    before_prop = audits('page.property', 'deny', 'not_owner', 'claude_employee02', pni)
    r_ = req('PATCH', '/api/pages/' + PN + '/properties', {'status': 'doing'}, 'C')
    soft(r_.status_code == 403, 'RV-A2 C (reads, cannot change PN) PATCH properties → 403 (got ' + str(r_.status_code) + ')')
    after_prop = audits('page.property', 'deny', 'not_owner', 'claude_employee02', pni)
    codes['rv_a2_page_property_rows'] = str(before_prop) + '→' + str(after_prop)
    soft(after_prop == before_prop + 1, 'RV-A2 audit_logs page.property|deny|not_owner DB row +1 (' + codes['rv_a2_page_property_rows'] + ')')
    soft(q1("SELECT coalesce(status,'∅') FROM community.pages WHERE id=" + str(pni)) == '∅'
         and n("SELECT count(*) FROM community.page_property_events WHERE page_id=" + str(pni)) == 0, 'RV-A2 PN status unchanged · no property event')
    if a8_failed:
        raise AssertionError('A-8: ' + str(len(a8_failed)) + ' failed')

    # ════════ 한도 ════════════════════════════════════════════════════
    filler = [n("INSERT INTO community.pages(space_id,title,doc,author_id,last_edited_by) VALUES("
                + str(space_x) + ",'QF12 채움 " + str(i) + "','{\"schema\":1,\"blocks\":[]}'," + str(uid('A')) + ',' + str(uid('A')) + ') RETURNING id')
              for i in range(100)]
    pf3 = expect('POST', '/api/portfolios', {'space': space_x, 'name': 'QF12 한도'}, 'A', 201, 'limit: PF3').json()['portfolio']
    pf3i = n("SELECT id FROM community.portfolios WHERE public_id='" + pf3['id'] + "'")
    sql('INSERT INTO community.portfolio_items(portfolio_id,page_id,position,added_by) SELECT ' + str(pf3i) + ', unnest(ARRAY[' + ','.join(map(str, filler)) + ']), 1, ' + str(uid('A')))
    expect('POST', '/api/portfolios/' + pf3['id'] + '/items', {'project': PX['id']}, 'A', 400, 'limit: 101st project 400')
    live = n('SELECT count(*) FROM community.portfolios WHERE deleted_at IS NULL AND space_id=' + str(space_x))
    sql('INSERT INTO community.portfolios(space_id,name,created_by) SELECT ' + str(space_x) + ", 'QF12 채움 포트폴리오 '||g, " + str(uid('A'))
        + ' FROM generate_series(1,' + str(50 - live) + ') g')
    expect('POST', '/api/portfolios', {'space': space_x, 'name': 'QF12 51번째'}, 'A', 400, 'limit: 51st portfolio in a team space 400')

    # ════════ Q:T-28 기록 불변 — 앱 계정(tbox_app) 권한 ════════════════════
    perm = json.loads(php(
        "$db=Db::instance();$out=[];"
        "foreach(['UPDATE community.page_property_events SET to_value=NULL WHERE id=-1','DELETE FROM community.page_property_events WHERE id=-1',"
        "'UPDATE community.project_status_updates SET note=\\'\\' WHERE id=-1','DELETE FROM community.project_status_updates WHERE id=-1',"
        "'UPDATE community.portfolio_events SET to_value=NULL WHERE id=-1','DELETE FROM community.portfolio_events WHERE id=-1',"
        "'DELETE FROM community.portfolios WHERE id=-1','UPDATE community.my_tasks_projects SET page_id=page_id WHERE user_id=-1'] as $q){"
        "try{$db->run($q);$out[$q]='ALLOWED';}catch(Throwable $e){$m=$e->getMessage();$out[$q]=preg_match('/permission denied for table [a-z_]+/',$m,$x)?$x[0]:'OTHER';}}"
        "$out['current_user']=$db->run('SELECT current_user')->fetchColumn();echo json_encode($out);"))
    codes['permission_denied'] = perm
    check(perm.pop('current_user') == 'tbox_app', 'Q:T-28 checks run as the application role tbox_app')
    for q, msg in perm.items():
        table = re.search(r'community\.([a-z_]+)', q).group(1)
        check(msg == 'permission denied for table ' + table, 'Q:T-28 app ' + q.split()[0] + ' on ' + table + ' → ' + msg)
    grants = q1("SELECT string_agg(table_name||':'||privilege_type, ',' ORDER BY table_name, privilege_type) FROM information_schema.role_table_grants "
                "WHERE grantee='tbox_app' AND table_schema='community' AND table_name IN ('my_tasks_projects','project_status_updates','portfolios','portfolio_items','portfolio_events')")
    codes['app_grants'] = grants
    check(grants == ('my_tasks_projects:DELETE,my_tasks_projects:INSERT,my_tasks_projects:SELECT,'
                     'portfolio_events:INSERT,portfolio_events:SELECT,'
                     'portfolio_items:DELETE,portfolio_items:INSERT,portfolio_items:SELECT,portfolio_items:UPDATE,'
                     'portfolios:INSERT,portfolios:SELECT,portfolios:UPDATE,'
                     'project_status_updates:INSERT,project_status_updates:SELECT'), 'Q:T-28 app grants exactly as ARCH 23.2')
    check(q1("SELECT string_agg(DISTINCT privilege_type, ',') FROM information_schema.role_table_grants WHERE grantee='tbox_backup' AND table_schema='community' "
             "AND table_name IN ('my_tasks_projects','project_status_updates','portfolios','portfolio_items','portfolio_events')") == 'SELECT',
          'Q:T-28 backup role SELECT only')

    # ════════ 마이그레이션 — 2회 멱등 · 옛 assignee_id 보존 · 새 설치 = 마이그레이션 SAME ═══════
    check('2026-10-19_qf12_work.sql' in (root / 'tests/community/memories_harness.py').read_text(), 'migration: harness applies it (twice) on the fresh-install shape')
    check(q1("SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conrelid='community.notifications'::regclass AND contype='c' AND pg_get_constraintdef(oid) LIKE '%kind%'").count("'assigned'") == 1,
          "migration: notifications kind CHECK contains 'assigned' exactly once after two applications")
    env = {k: v for k, v in os.environ.items() if not k.startswith('PG')}
    env['PGPASSFILE'] = str(Path.home() / '.config/qaredi/pgpass-testdb')
    pgo = ['-h', '127.0.0.1', '-p', '5432', '-U', 'tbox_testdb', '-w']
    tag = str(os.getpid())
    dbs = {'fresh': 'tbox_memories_test_qf12a_fresh_' + tag, 'mig': 'tbox_memories_test_qf12a_mig_' + tag}

    def pg(prog, args, text=None):
        return subprocess.run([prog] + pgo + args, env=env, input=text.encode() if text is not None else None, capture_output=True, check=True).stdout.decode()

    def psql_file(db, path):
        pg('psql', ['-X', '-q', '-d', db, '-v', 'ON_ERROR_STOP=1', '-f', str(path)])

    def psql_text(db, text):
        return pg('psql', ['-X', '-At', '-d', db, '-v', 'ON_ERROR_STOP=1'], text).strip()

    new_schema = (root / 'db/community/schema.sql').read_text()
    marker = '\n-- ─────────────────────────────────────────────────────────────\n-- Q:F-12 업무 화면'
    old_schema = new_schema[:new_schema.index(marker)]   # = 기준 판(Q:F-12 전)의 schema.sql 글자 그대로 — 이 절은 끝에 덧붙였다
    oldp = out / 'qf12-old-community-schema.sql'
    oldp.write_text(old_schema)
    check(not any(w in old_schema for w in ('portfolio', 'my_tasks', 'project_status_updates', "'assigned'")), 'migration: base (pre-Q:F-12) schema has none of the new objects')
    grants_db = {}
    try:
        for key, db in dbs.items():
            pg('createdb', ['-O', 'tbox_testdb', db])
            psql_file(db, root / 'db/schema.sql')
            psql_text(db, 'CREATE SCHEMA community;')
            psql_file(db, root / 'db/community/schema.sql' if key == 'fresh' else oldp)
        # 옛 assignee_id 한 줄(5-A 이전 값) — 마이그레이션 전 기존 DB 에 있던 것처럼
        mig = dbs['mig']
        old_uid = psql_text(mig, "INSERT INTO public.users(username,password_hash,role) VALUES('claude_qf12_legacy','x','Employee') RETURNING id").splitlines()[0]
        old_sp = psql_text(mig, "INSERT INTO community.spaces(kind,owner_user_id,name,access_level) VALUES('personal'," + old_uid + ",'옛 공간','admin-only') RETURNING id").splitlines()[0]
        psql_text(mig, "INSERT INTO community.pages(space_id,title,doc,author_id,last_edited_by,assignee_id) VALUES(" + old_sp + ",'옛 담당','{}'," + old_uid + ',' + old_uid + ',' + old_uid + ')')
        for _ in range(2):
            for db in dbs.values():
                psql_file(db, root / 'db/migrations/2026-10-19_qf12_work.sql')
        for key, db in dbs.items():
            gpath = out / ('qf12-grants-' + key + '.sql')
            text = (root / 'db/grants.sql').read_text().replace('tbox_re', db)
            assert 'DATABASE tbox_re ' not in text and db.startswith('tbox_memories_test_qf12a_')
            gpath.write_text(text)
            psql_file(db, gpath)
            psql_file(db, root / 'db/community/grants.sql')
            gpath.unlink()
        check(psql_text(mig, "SELECT count(*)||'|'||min(assignee_id) FROM community.pages WHERE title='옛 담당'") == '1|' + old_uid,
              'migration: old assignee_id value preserved after two applications')
        shape_sql = """
            SELECT 'col|'||c.relname||'|'||a.attnum||'|'||a.attname||'|'||format_type(a.atttypid,a.atttypmod)||'|'||a.attnotnull||'|'||coalesce(pg_get_expr(d.adbin,d.adrelid),'')
              FROM pg_attribute a JOIN pg_class c ON c.oid=a.attrelid JOIN pg_namespace s ON s.oid=c.relnamespace
              LEFT JOIN pg_attrdef d ON d.adrelid=a.attrelid AND d.adnum=a.attnum
             WHERE s.nspname='community' AND c.relkind IN ('r','p') AND a.attnum>0 AND NOT a.attisdropped
            UNION ALL
            SELECT 'con|'||c.relname||'|'||k.conname||'|'||pg_get_constraintdef(k.oid)
              FROM pg_constraint k JOIN pg_class c ON c.oid=k.conrelid JOIN pg_namespace s ON s.oid=c.relnamespace WHERE s.nspname='community'
            UNION ALL
            SELECT 'idx|'||indexname||'|'||indexdef FROM pg_indexes WHERE schemaname='community'
            UNION ALL
            SELECT 'acl|'||table_name||'|'||grantee||'|'||privilege_type FROM information_schema.role_table_grants WHERE table_schema='community'
            UNION ALL
            SELECT 'seq|'||sequence_name FROM information_schema.sequences WHERE sequence_schema='community'
            ORDER BY 1;
        """
        shapes = {key: psql_text(db, shape_sql) for key, db in dbs.items()}
        for key in shapes:
            (out / ('qf12-shape-' + key + '.txt')).write_text(shapes[key] + '\n')
        diff = sorted(set(shapes['fresh'].splitlines()) ^ set(shapes['mig'].splitlines()))
        (out / 'qf12-shape-diff.txt').write_text('\n'.join(diff) + '\n')
        codes['shape_lines'] = len(shapes['fresh'].splitlines())
        print('SHAPE ' + ('SAME' if shapes['fresh'] == shapes['mig'] else 'DIFF') + ' lines=' + str(codes['shape_lines']) + ' diff=' + str(len(diff)), flush=True)
        check(shapes['fresh'] == shapes['mig'], 'migration: fresh install (schema.sql+grants) and pre-Q:F-12 schema + migration×2 (+grants) SAME '
              '(columns · constraints · indexes · grants · sequences, ' + str(codes['shape_lines']) + ' lines)')
        check(sum(1 for l in shapes['fresh'].splitlines() if 'portfolio' in l or 'project_status' in l or 'my_tasks' in l or 'purpose' in l) >= 30,
              'migration: compared shape includes the new objects')
    finally:
        for db in dbs.values():
            subprocess.run(['dropdb'] + pgo + ['--if-exists', db], env=env, capture_output=True)
        check(all(n("SELECT count(*) FROM pg_database WHERE datname='" + db + "'") == 0 for db in dbs.values()), 'migration: temporary comparison DBs dropped')
finally:
    (out / 'qf12-floor-results.json').write_text(json.dumps({'checks': checks, 'confirmed_values': codes}, ensure_ascii=False, indent=2))
    print('RESULT pass=' + str(sum(x['pass'] for x in checks)) + ' fail=' + str(sum(not x['pass'] for x in checks)), flush=True)
