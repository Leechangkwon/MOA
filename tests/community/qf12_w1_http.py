#!/usr/bin/env python3
"""Q:F-12 W1 — 서버 수락 시험: 작업의 시작 예정 · 실제 시작 · 끝난 날 (실제 PHP · PostgreSQL · 격리 틀 memories_harness.py 만)

  python3 tests/community/memories_harness.py --suite qf12_w1_http.py --output <결과 폴더>

지시서 docs/qf12-w1-directive.md 「완료 기준 — HTTP(격리)」 · 기획 work-ux-plan.md 19.2 · 결정 WK-29.
합성 DB(tbox_memories_test_*) · 임시 복사본 · 로컬 php -S 만(운영 DB · 운영 8082 · 시드 계정 0). 요청은 fixture 의 claude_ 계정 · UA tbox-review/<날짜>.
결과 파일에는 단언 이름 · 참/거짓 + 확정한 값(상태 · 날짜 · 질의 수)만 — 세션 · CSRF 0.

fixture: A=claude_employee01(팀 X) · B=claude_manager01(팀 X — A 프로젝트 보기 공유) · C=claude_employee02(팀 Y — 못 봄)

  [save]    PATCH …/properties {start}: 저장 200 · 다시 읽어도 · 날짜 아님(글자 · 없는 날 · 숫자) 400 · start > due 400(문구) · due 를 start 앞으로 400 ·
            같은 값 = 사건 0 · null 로 지움 · 사건 'start' 1행 · 감사 page.property allow(prop start) · 보기 공유 B 403 + 감사 deny(지금 due 와 같음) · 못 보는 C 403/404
  [calc]    상태 todo → doing → review → doing → done (사건 시각을 합성 날짜로): started = 첫 doing 날(Asia/Seoul — UTC 16:30 = 다음 날) ·
            finished = 마지막 done 날 · done → doing 되돌리면 finished null · doing 없이 바로 done = started null · finished = 그 done 날
  [lists]   my-tasks(끝난 것 done=1 포함) · 프로젝트 행(/api/databases/{id}/rows) · pages_get 의 세 값이 같음 · 보기 공유 B 의 행 목록도 같은 값
  [n+1]     목록 질의 수(my_tasks.php · database_rows.php 실행 — PDOStatement::execute 를 센다) 작업 3 = 30
  [authz]   C(못 보는 사람)의 my-tasks · 행 목록에 0 — 담당을 C 로 DB 에 직접 박아도 0(지금 판정 그대로)
  [mig]     마이그레이션 두 번(틀) + 세 번째 직접 적용 0 오류 · CHECK 에 'start' 한 번 · 옛 prop('due' · 'status' · db:*) 그대로 · 열 DATE
"""
from pathlib import Path
exec(compile(Path(__file__).with_name('memories_http.py').read_text().split('try:\n for u in users:')[0], __file__, 'exec'))
assert str(root).startswith('/tmp/qaredi-stage13-') and manifest['database'].startswith('tbox_memories_test_')

NAMES = {'A': 'employee', 'B': 'manager', 'C': 'other', 'G': 'guest', 'M': 'admin'}
codes = {}
MIGRATION = root / 'db/migrations/2026-10-14_task_start_date.sql'
LATE_MSG = '시작 예정일이 기한보다 늦어요'


def check(ok, name):
    checks.append({'pass': bool(ok), 'name': name})
    print(('PASS ' if ok else 'FAIL ') + name, flush=True)
    return bool(ok)


def req(method, path, body=None, user='A', **kw): return raw(method, path, body, NAMES[user] if user else None, **kw)


def expect(method, path, body, user, status, name, **kw):
    r = req(method, path, body, user, **kw)
    check(r.status_code == status, name + ' → ' + str(status) + ' (got ' + str(r.status_code) + ')')
    if r.status_code != status:
        print('  body=' + r.text[:300], flush=True)
    return r


def n(text): return int((sql(text).splitlines() or ['0'])[0] or 0)
def lit(v): return "'" + str(v).replace("'", "''") + "'"
def pk(public_id): return n('SELECT id FROM community.pages WHERE public_id=' + lit(public_id))


def props(pid, body, user='A'): return req('PATCH', '/api/pages/' + pid + '/properties', body, user)


def got(pid, user='A'):
    j = req('GET', '/api/pages/' + pid, None, user).json()
    return {k: j.get(k) for k in ('start', 'started', 'finished', 'due')}


def three(d): return {k: d.get(k) for k in ('start', 'started', 'finished')}


def my_tasks(user='A', done=False):
    j = req('GET', '/api/my-tasks?done=' + ('1' if done else '0'), None, user).json()
    return {it['public_id']: it for b in j.get('buckets', []) for it in b.get('items', [])}


def rows(dbid, user='A'):
    r = req('GET', '/api/databases/' + dbid + '/rows', None, user)
    return r, ({x['id']: x for x in r.json().get('rows', [])} if r.status_code == 200 else {})


def psql_file(path):
    env = {k: v for k, v in os.environ.items() if not k.startswith('PG')}
    env['PGPASSFILE'] = str(Path.home() / '.config/qaredi/pgpass-testdb')
    return subprocess.run(['psql', '-h', '127.0.0.1', '-U', 'tbox_testdb', '-w', '-X', '-At', '-d', manifest['database'],
                           '-v', 'ON_ERROR_STOP=1', '-f', str(path)], env=env, capture_output=True, text=True)


php_prelude = ("require 'src/Env.php'; Env::load(getcwd().'/.env'); require 'src/bootstrap.php';"
               "require_once 'src/community/routes.php';")


def count_queries(handler, user_key, get=None, params=None):
    """핸들러 하나를 실제 코드 그대로 돌려 PDOStatement::execute 횟수를 센다(cafe_c1 과 같은 방식)."""
    code = (
        "final class W1CountStmt extends PDOStatement { public static int $n = 0; protected function __construct() {}"
        " public function execute(?array $p = null): bool { self::$n++; return parent::execute($p); } }"
        "$db = Db::instance(); $open = new ReflectionMethod(Db::class, 'open'); $open->setAccessible(true);"
        "$pdo = $open->invoke($db, false); $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [W1CountStmt::class, []]);"
        "$prop = new ReflectionProperty(Db::class, 'pdo'); $prop->setAccessible(true); $prop->setValue($db, $pdo);"
        "$user = community_user_by_id(" + str(int(users[NAMES[user_key]]['id'])) + ") + ['reauth_fresh' => false];"
        "$_GET = json_decode(" + repr(json.dumps(get or {})) + ", true); $params = json_decode(" + repr(json.dumps(params or [])) + ", true);"
        "W1CountStmt::$n = 0; ob_start(); include 'src/community/handlers/" + handler + "'; $out = ob_get_clean();"
        "$j = json_decode($out, true); $c = 0; foreach (($j['buckets'] ?? []) as $b) { $c += count($b['items']); } $c += count($j['rows'] ?? []);"
        "echo json_encode(['queries' => W1CountStmt::$n, 'items' => $c]);")
    done = subprocess.run(['php', '-r', php_prelude + code], cwd=root, capture_output=True, text=True, timeout=120)
    try:
        return json.loads(done.stdout.strip().splitlines()[-1])
    except Exception:
        print('COUNT_OUT=' + (done.stdout + done.stderr)[-500:], flush=True)
        return {'queries': -1, 'items': -1}


try:
    for k in NAMES:
        expect('GET', '/api/me/space', None, k, 200, 'personal space ' + k)

    # ════════ 준비 — A 개인 프로젝트 · 작업 · B 보기 공유 ═══════════════════════════════
    proj = expect('POST', '/api/pages', {'database': True, 'preset': 'project', 'title': 'W1 합성 프로젝트'}, 'A', 201, 'fixture project').json()['page']
    dbid = proj['database']['id']
    T = expect('POST', '/api/pages', {'parent_id': proj['id'], 'title': 'W1 디버깅', 'properties': {'section': '01. 진행'}}, 'A', 201, 'fixture task').json()['page']['id']
    expect('PUT', '/api/pages/' + proj['id'] + '/shares/claude_manager01', {'level': 'view'}, 'A', 200, 'fixture: view-share project to B')

    # ════════ [mig] 마이그레이션 ═══════════════════════════════════════════════════════
    defn = sql("SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conrelid='community.page_property_events'::regclass AND contype='c' AND pg_get_constraintdef(oid) LIKE '%prop%'")
    codes['prop_check_after_two'] = defn
    check(defn.count("'start'") == 1 and "'due'" in defn and "'status'" in defn and "'assignee'" in defn and '^db:' in defn,
          "mig: after two applications CHECK has 'start' exactly once · earlier props (status · assignee · due · db:*) kept")
    check(n("SELECT count(*) FROM pg_constraint WHERE conrelid='community.page_property_events'::regclass AND contype='c' AND pg_get_constraintdef(oid) LIKE '%prop%'") == 1,
          'mig: one prop CHECK constraint')
    third = psql_file(MIGRATION)
    defn3 = sql("SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conrelid='community.page_property_events'::regclass AND contype='c' AND pg_get_constraintdef(oid) LIKE '%prop%'")
    check(third.returncode == 0 and defn3 == defn, 'mig: third application exit 0 · CHECK unchanged (idempotent)')
    check(sql("SELECT data_type FROM information_schema.columns WHERE table_schema='community' AND table_name='pages' AND column_name='start_date'") == 'date',
          'mig: community.pages.start_date is DATE')

    # ════════ [save] 시작 예정 저장 ═══════════════════════════════════════════════════
    ev0 = n("SELECT count(*) FROM community.page_property_events WHERE prop='start' AND page_id=" + str(pk(T)))
    au0 = n("SELECT count(*) FROM audit_logs WHERE action='page.property' AND result='allow' AND detail->>'prop'='start'")
    r = expect('PATCH', '/api/pages/' + T + '/properties', {'start': '2026-10-10'}, 'A', 200, 'save start 2026-10-10')
    check(r.status_code == 200 and r.json().get('changed') == ['start'], "save: changed = ['start']")
    check(got(T)['start'] == '2026-10-10', 'save: pages_get start = 2026-10-10 (read again)')
    check(n("SELECT count(*) FROM community.page_property_events WHERE prop='start' AND page_id=" + str(pk(T))) == ev0 + 1
          and sql("SELECT coalesce(from_value,'∅')||'→'||to_value FROM community.page_property_events WHERE prop='start' AND page_id=" + str(pk(T)) + ' ORDER BY id DESC LIMIT 1') == '∅→2026-10-10',
          "save: exactly one property event 'start' ∅ → 2026-10-10")
    check(n("SELECT count(*) FROM audit_logs WHERE action='page.property' AND result='allow' AND detail->>'prop'='start'") == au0 + 1, 'save: audit page.property allow (prop start) +1')
    r = expect('PATCH', '/api/pages/' + T + '/properties', {'start': '2026-10-10'}, 'A', 200, 'save same start again')
    check(r.json().get('changed') == [] and n("SELECT count(*) FROM community.page_property_events WHERE prop='start' AND page_id=" + str(pk(T))) == ev0 + 1,
          'save: same value → changed [] · event count unchanged')
    for bad_value, label in (('abc', 'text'), ('2026-02-30', 'impossible day'), (20261010, 'number'), ('2026-10-10T09:00', 'datetime'), ('', 'empty string')):
        expect('PATCH', '/api/pages/' + T + '/properties', {'start': bad_value}, 'A', 400, 'start not a date (' + label + ')')
    expect('PATCH', '/api/pages/' + T + '/properties', {'due': '2026-10-16'}, 'A', 200, 'save due 2026-10-16')
    r = expect('PATCH', '/api/pages/' + T + '/properties', {'start': '2026-10-17'}, 'A', 400, 'start 2026-10-17 > due 2026-10-16')
    codes['late_400'] = r.json()
    check(r.json().get('error') == LATE_MSG and r.json().get('reason') == 'start_after_due', 'start > due: error 「' + LATE_MSG + '」 · reason start_after_due')
    r = expect('PATCH', '/api/pages/' + T + '/properties', {'due': '2026-10-09'}, 'A', 400, 'due 2026-10-09 before start 2026-10-10')
    check(r.json().get('error') == LATE_MSG, 'due before start: same sentence')
    r = expect('PATCH', '/api/pages/' + T + '/properties', {'start': '2026-10-20', 'due': '2026-10-25'}, 'A', 200, 'start + due together (20 ≤ 25)')
    check(got(T)['start'] == '2026-10-20' and got(T)['due'] == '2026-10-25', 'start + due together stored')
    expect('PATCH', '/api/pages/' + T + '/properties', {'start': '2026-10-16', 'due': '2026-10-16'}, 'A', 200, 'start = due allowed')
    check(sql("SELECT count(*) FROM community.page_property_events WHERE page_id=" + str(pk(T)) + " AND prop='start'") == str(ev0 + 3), "events: start ×3 so far (10 → 20 → 16)")
    expect('PATCH', '/api/pages/' + T + '/properties', {'start': None}, 'A', 200, 'clear start with null')
    check(got(T)['start'] is None and sql("SELECT coalesce(to_value,'∅') FROM community.page_property_events WHERE prop='start' AND page_id=" + str(pk(T)) + ' ORDER BY id DESC LIMIT 1') == '∅',
          'clear: start null · event to ∅')
    expect('PATCH', '/api/pages/' + T + '/properties', {'start': '2026-10-10', 'due': '2026-10-16'}, 'A', 200, 'restore start 10 · due 16')
    # 권한 — 지금 due 와 같은 갈래(decidePageChange)
    d0 = n("SELECT count(*) FROM audit_logs WHERE action='page.property' AND result='deny' AND actor_name='claude_manager01'")
    check(got(T, 'B')['start'] == '2026-10-10', 'B (view share) reads start')
    expect('PATCH', '/api/pages/' + T + '/properties', {'start': '2026-10-11'}, 'B', 403, 'B (view share) cannot change start')
    expect('PATCH', '/api/pages/' + T + '/properties', {'due': '2026-10-17'}, 'B', 403, 'B (view share) cannot change due (same branch)')
    check(n("SELECT count(*) FROM audit_logs WHERE action='page.property' AND result='deny' AND actor_name='claude_manager01'") == d0 + 2, 'B denials audited page.property deny ×2')
    rc = props(T, {'start': '2026-10-11'}, 'C')
    check(rc.status_code in (403, 404), 'C (cannot read) start → 403/404 (got ' + str(rc.status_code) + ')')
    expect('PATCH', '/api/pages/' + T + '/properties', {'start': '2026-10-11'}, 'G', 403, 'guest start → 403')
    check(got(T)['start'] == '2026-10-10', 'start unchanged after denied requests')

    # ════════ [calc] 실제 시작 · 끝난 날 ═════════════════════════════════════════════════
    tid = pk(T)
    def set_status(s, at):
        expect('PATCH', '/api/pages/' + T + '/properties', {'status': s}, 'A', 200, 'status → ' + s)
        sql("UPDATE community.page_property_events SET at=" + lit(at) + "::timestamptz WHERE id=(SELECT max(id) FROM community.page_property_events WHERE page_id=" + str(tid) + " AND prop='status')")
    set_status('todo', '2026-10-03 09:00:00+09')
    check(three(got(T)) == {'start': '2026-10-10', 'started': None, 'finished': None}, 'todo: started null · finished null')
    set_status('doing', '2026-10-11 16:30:00+00')     # = 서울 10월 12일 01:30
    check(got(T)['started'] == '2026-10-12', 'doing at 2026-10-11 16:30 UTC → started 2026-10-12 (Asia/Seoul)')
    set_status('review', '2026-10-13 10:00:00+09')
    set_status('doing', '2026-10-14 10:00:00+09')
    check(got(T)['started'] == '2026-10-12', 'review → doing again: started stays the FIRST doing day (10-12)')
    set_status('done', '2026-10-15 18:00:00+09')
    g = got(T)
    codes['after_done'] = g
    check(g['started'] == '2026-10-12' and g['finished'] == '2026-10-15', 'done: started 10-12 · finished 10-15 (last done day)')
    set_status('doing', '2026-10-16 09:00:00+09')
    check(got(T)['finished'] is None and got(T)['started'] == '2026-10-12', 'done → doing: finished null · started unchanged')
    set_status('done', '2026-10-17 09:00:00+09')
    check(got(T)['finished'] == '2026-10-17', 'done again: finished = latest done (10-17)')
    # 바로 done
    T2 = expect('POST', '/api/pages', {'parent_id': proj['id'], 'title': 'W1 바로 끝난 작업'}, 'A', 201, 'fixture task 2').json()['page']['id']
    expect('PATCH', '/api/pages/' + T2 + '/properties', {'status': 'done'}, 'A', 200, 'task 2 status → done (no doing)')
    sql("UPDATE community.page_property_events SET at='2026-10-08 12:00:00+09' WHERE page_id=" + str(pk(T2)) + " AND prop='status'")
    check(three(got(T2)) == {'start': None, 'started': None, 'finished': '2026-10-08'}, 'straight to done: started null · finished 10-08')
    # 진행 중(끝나지 않음)
    T3 = expect('POST', '/api/pages', {'parent_id': proj['id'], 'title': 'W1 진행 중 작업'}, 'A', 201, 'fixture task 3').json()['page']['id']
    expect('PATCH', '/api/pages/' + T3 + '/properties', {'status': 'doing', 'start': '2026-10-05', 'due': '2026-10-09'}, 'A', 200, 'task 3 doing + start + due in one request')
    sql("UPDATE community.page_property_events SET at='2026-10-06 12:00:00+09' WHERE page_id=" + str(pk(T3)) + " AND prop='status'")
    check(three(got(T3)) == {'start': '2026-10-05', 'started': '2026-10-06', 'finished': None}, 'doing (not done): started 10-06 · finished null')

    # ════════ [lists] 세 창구가 같은 값 ═══════════════════════════════════════════════════
    mt_open, mt_done = my_tasks('A'), my_tasks('A', True)
    r_rows, by_row = rows(dbid, 'A')
    for pid, label in ((T, 'T (done)'), (T2, 'T2 (done)'), (T3, 'T3 (doing)')):
        g = three(got(pid))
        m = mt_done.get(pid) or mt_open.get(pid) or {}
        codes['three_' + label] = {'get': g, 'my_tasks': three(m), 'rows': three(by_row.get(pid, {}))}
        check(m and three(m) == g, label + ': my-tasks start · started · finished = pages_get ' + json.dumps(g))
        check(three(by_row.get(pid, {})) == g, label + ': project rows = pages_get')
    check(set(('start', 'started', 'finished')) <= set(next(iter(mt_open.values())).keys()), 'my-tasks items carry start · started · finished keys')
    _, by_row_b = rows(dbid, 'B')
    check(three(by_row_b.get(T3, {})) == three(got(T3)), 'B (view share) rows: same three values')

    # ════════ [authz] 못 보는 사람의 목록에 0 ═════════════════════════════════════════════
    sql('UPDATE community.pages SET assignee_id=' + str(int(users['other']['id'])) + ' WHERE id=' + str(pk(T3)))
    mt_c = {**my_tasks('C'), **my_tasks('C', True)}
    check(not ({T, T2, T3} & set(mt_c)), 'C my-tasks (open + done) contains none of the tasks — even with assignee forced to C in the DB')
    rc, by_c = rows(dbid, 'C')
    check(rc.status_code in (403, 404) and not by_c, 'C project rows → 403/404 (got ' + str(rc.status_code) + ')')
    rg = req('GET', '/api/pages/' + T3, None, 'C')
    check(rg.status_code in (403, 404) and 'started' not in rg.text, 'C pages_get → 403/404 · no started value')
    sql('UPDATE community.pages SET assignee_id=NULL WHERE id=' + str(pk(T3)))

    # ════════ [n+1] 목록 질의 수 — 작업 3 = 30 ════════════════════════════════════════════
    q = expect('POST', '/api/pages', {'database': True, 'preset': 'project', 'title': 'W1 질의 수 프로젝트'}, 'A', 201, 'fixture count project').json()['page']
    qdb = q['database']['id']
    def add_task(i):
        t = req('POST', '/api/pages', {'parent_id': q['id'], 'title': 'W1 질의 ' + str(i)}, 'A').json()['page']['id']
        req('PATCH', '/api/pages/' + t + '/properties', {'status': 'doing', 'start': '2026-10-0' + str(1 + i % 9)}, 'A')
        return t
    for i in range(3):
        add_task(i)
    mt3 = count_queries('my_tasks.php', 'A', {'done': '0'})
    rows3 = count_queries('database_rows.php', 'A', {}, [qdb])
    for i in range(3, 30):
        add_task(i)
    mt30 = count_queries('my_tasks.php', 'A', {'done': '0'})
    rows30 = count_queries('database_rows.php', 'A', {}, [qdb])
    codes['queries'] = {'my_tasks': [mt3, mt30], 'rows': [rows3, rows30]}
    check(mt3['queries'] > 0 and mt3['queries'] == mt30['queries'] and mt30['items'] - mt3['items'] == 27,
          'my-tasks query count fixed: 3 tasks %s = 30 tasks %s (items %s → %s)' % (mt3['queries'], mt30['queries'], mt3['items'], mt30['items']))
    check(rows3['queries'] > 0 and rows3['queries'] == rows30['queries'] and rows3['items'] == 3 and rows30['items'] == 30,
          'project rows query count fixed: 3 rows %s = 30 rows %s' % (rows3['queries'], rows30['queries']))
    plan = sql("EXPLAIN SELECT 1 FROM community.page_property_events e WHERE e.page_id = 1 AND e.prop = 'status'")
    codes['events_plan'] = plan
finally:
    (out / 'qf12-w1-http-results.json').write_text(json.dumps({'checks': checks, 'confirmed_values': codes}, ensure_ascii=False, indent=2))
    print('RESULT pass=' + str(sum(x['pass'] for x in checks)) + ' fail=' + str(sum(not x['pass'] for x in checks)), flush=True)
    if any(not x['pass'] for x in checks):
        raise SystemExit(1)
