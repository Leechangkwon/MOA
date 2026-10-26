#!/usr/bin/env python3
"""사이트 Q:F-15 S3 과업 길잡이 수락 [http] (실제 PHP · PostgreSQL · 격리 틀 memories_harness.py 만)

  python3 tests/community/memories_harness.py --port 8768 --suite guide_journeys_http.py --output <결과 폴더>/http

합성 DB · 임시 복사본 · 로컬 php -S 두 대(포트 · 포트+1)만 친다(운영 · tbox_re · 시드 계정 0). 계정은 fixture 의 claude_ 다섯과
이 시험이 합성 DB 에 만드는 claude_s3_offer01 하나 · UA tbox-review/<날짜>. 결과 파일에는 단언 이름 · 참/거짓 · 측정값만(세션 · CSRF · 본문 0).
지시서 docs/qf15-s3-guide-directive.md 「수락 시험」 [http] ①~⑫ · 사이트 PRD 9.1 Q:T-G2 · G3 · G11 · G18 · community ARCH 26장.

  [db]    ① 새 표 소유자(틀이 갈음한 tbox_testdb) · tbox_app SELECT · INSERT · UPDATE 참 · DELETE 거짓 · tbox_backup SELECT 참 · 부분 유니크 색인 ·
            마이그레이션 두 번(틀) + 세 번째 적용 오류 0 · 모양(BEGIN · 첫 문장 SET LOCAL ROLE tbox_owner · COMMIT) · grants.sql 블록 하나
  [api]   ② GET 의 journeys · can(직원 · 관리자 · 손님 · 관리자의 직원 보기) · csrf_token  ③ CSRF 없음 403 · 세션 없음 401 · 개인 토큰 헤더만 401 ·
            MCP 토큰 헤더만 401 · GET · DELETE 405 · 손님 task-add 403 · 직원 notice-post 403 · 1025바이트 400 · 응답 꼴
          ④ 남의 행 0 · B 의 PATCH 는 B 행만  ⑤ 표 없는 인스턴스(이름 잠시 바꿈) — GET journeys/can null · 옛 PATCH 200 · 새 PATCH 503
          ⑥ 여정 행이 하나라도 생기면 guide.offer 거짓(활성 뒤 만든 새 합성 계정)
  [state] ⑦ 진행 중 하나 ⑧ 시작 시각 규칙 ⑨ 행 없음 · 그만둔 행 400 ⑩ 사실 여섯 ⑪ 동시 20쌍(두 서버) ⑫ 감사 0
"""
from pathlib import Path
exec(compile(Path(__file__).with_name('memories_http.py').read_text().split('try:\n for u in users:')[0], __file__, 'exec'))
import re, secrets, threading
from concurrent.futures import ThreadPoolExecutor
from urllib.parse import urlsplit, urlunsplit
assert str(root).startswith('/tmp/qaredi-stage13-') and manifest['database'].startswith('tbox_memories_test_')
UA = 'tbox-review/' + time.strftime('%Y-%m-%d')
SECOND = int(os.environ.get('STAGE89_SECOND_PORT') or 0)
JOURNEYS = ['task-add', 'file-share', 'minutes-write', 'notice-post', 'message-send', 'invite-colleague']
PAGES = {'task-add': 1, 'file-share': 1, 'minutes-write': 2, 'notice-post': 3, 'message-send': 1, 'invite-colleague': 1}
NO_RESULT = '아직 결과가 없어요 — 위 단계를 하고 다시 보기를 눌러 주세요'
metrics = {}


def check(ok, name):   # noqa: F811 — 이 시험은 실패해도 다음 단언으로 간다(결과 파일에 전부 남긴다)
    checks.append({'pass': bool(ok), 'name': name})
    print(('PASS ' if ok else 'FAIL ') + name, flush=True)
    return bool(ok)


def guard(name, fn):
    try:
        fn()
    except Exception as error:   # noqa: BLE001 — 시험 흐름의 어떤 예외든 실패로 센다
        check(False, name + ' raised ' + type(error).__name__ + ': ' + str(error).splitlines()[0][:200] if str(error) else name + ' raised ' + type(error).__name__)


def uid(key): return int(users[key]['id'])
def lit(v): return "'" + str(v).replace("'", "''") + "'"


def at_port(url, port):
    if not port:
        return url
    p = urlsplit(url)
    return urlunsplit((p.scheme, p.hostname + ':' + str(port), p.path, p.query, p.fragment))


def req(method, url, who=None, csrf=True, body=None, headers=None, port=None, data=None, files=None, raw_body=None):
    """who — fixture 키(users) 또는 {'session': …, 'csrf': …}"""
    h = {'User-Agent': UA, 'Accept': 'application/json'}
    if who is not None:
        acc = users[who] if isinstance(who, str) else who
        h['Cookie'] = '__Host-tbox_session=' + acc['session']
        if csrf and acc.get('csrf'):
            h['X-CSRF-Token'] = acc['csrf']
    h.update(headers or {})
    kw = {}
    if raw_body is not None:
        h['Content-Type'] = 'application/json'
        kw['data'] = raw_body
    elif files is not None:
        kw['data'] = data or {}
        kw['files'] = files
    elif body is not None:
        kw['json'] = body
    return requests.request(method, at_port(url, port), headers=h, timeout=40, **kw)


def guide_get(who):
    r = req('GET', site + '/community/api/me/guide', who)
    try:
        return r.status_code, r.json()
    except ValueError:
        return r.status_code, None


def jpatch(who, body, port=None, csrf=True):
    return req('PATCH', site + '/community/api/me/journeys', who, csrf=csrf, body=body, port=port)


def row(key, journey):
    v = sql("SELECT status || '|' || page || '|' || step || '|' || started_at::text || '|' || coalesce(completed_at::text,'') || '|' || confirmed_by_user::text"
            " FROM community.guide_journeys WHERE user_id=" + str(uid(key)) + " AND journey=" + lit(journey))
    if not v:
        return None
    s, p, st, started, completed, confirmed = v.split('|')
    return {'status': s, 'page': int(p), 'step': int(st), 'started': started, 'completed': completed, 'confirmed': confirmed}


def started_epoch(key, journey):
    v = sql("SELECT extract(epoch FROM started_at)::text FROM community.guide_journeys WHERE user_id=" + str(uid(key)) + " AND journey=" + lit(journey))
    return float(v) if v else None


def active_count(key):
    return int(sql("SELECT count(*) FROM community.guide_journeys WHERE user_id=" + str(uid(key)) + " AND status='active'"))


def psql_file(path):
    env = {k: v for k, v in os.environ.items() if not k.startswith('PG')}
    env['PGPASSFILE'] = str(Path.home() / '.config/qaredi/pgpass-testdb')
    return subprocess.run(['psql', '-h', '127.0.0.1', '-U', 'tbox_testdb', '-w', '-X', '-d', manifest['database'], '-v', 'ON_ERROR_STOP=1', '-f', str(path)],
                          env=env, capture_output=True, text=True)


def new_session(user_id, act_role=None, reauth=False):
    token = secrets.token_hex(32)
    digest = hashlib.sha256(token.encode()).hexdigest()
    cols, vals = 'id,user_id,expires_at,ip,user_agent', "'" + digest + "'," + str(user_id) + ",clock_timestamp()+interval '8 hours','127.0.0.1'," + lit(UA)
    if act_role:
        cols += ',act_role,act_started_at,act_last_seen_at'
        vals += ',' + lit(act_role) + ',now(),now()'
    if reauth:
        cols += ',reauth_at'
        vals += ',now()'
    sql('INSERT INTO sessions(' + cols + ') VALUES(' + vals + ')')
    acc = {'session': token, 'csrf': ''}
    code, data = guide_get(acc)
    if code == 200 and isinstance(data, dict):
        acc['csrf'] = data.get('csrf_token', '')
    return acc


def reauth_now(key):
    digest = hashlib.sha256(users[key]['session'].encode()).hexdigest()
    sql("UPDATE sessions SET reauth_at=now() WHERE id='" + digest + "'")


try:
    for u in users:
        users[u]['username'] = sql('SELECT username FROM users WHERE id=' + str(users[u]['id']))
        req('GET', site + '/community/api/me/space', u)   # 개인 공간(첫 방문 때 생긴다 — 다른 시험과 같은 준비)

    # ─── [db] ① ─────────────────────────────────────────────────────────────────────────────
    def db_shape():
        summary = {}
        owner = sql("SELECT tableowner FROM pg_tables WHERE schemaname='community' AND tablename='guide_journeys'")
        summary['owner'] = owner
        check(owner == 'tbox_testdb', '① [db] new table owner = the harness-substituted owner tbox_testdb (production file says SET LOCAL ROLE tbox_owner) (%s)' % owner)
        roles = sql("SELECT string_agg(rolname, ',' ORDER BY rolname) FROM pg_roles WHERE rolname IN ('tbox_app','tbox_backup')")
        summary['roles'] = roles
        if check(roles == 'tbox_app,tbox_backup', '① [db] cluster has tbox_app and tbox_backup (%s)' % roles):
            priv = sql("SELECT concat_ws(',', has_table_privilege('tbox_app','community.guide_journeys','SELECT'), has_table_privilege('tbox_app','community.guide_journeys','INSERT'),"
                       " has_table_privilege('tbox_app','community.guide_journeys','UPDATE'), has_table_privilege('tbox_app','community.guide_journeys','DELETE'),"
                       " has_table_privilege('tbox_backup','community.guide_journeys','SELECT'), has_table_privilege('tbox_backup','community.guide_journeys','INSERT'),"
                       " has_table_privilege('tbox_backup','community.guide_journeys','DELETE'))")
            summary['privileges'] = priv
            check(priv == 't,t,t,f,t,f,f', '① [db] tbox_app SELECT·INSERT·UPDATE true · DELETE false · tbox_backup SELECT true · INSERT·DELETE false (%s)' % priv)
            seq = sql("SELECT count(*) FROM pg_depend d JOIN pg_class c ON c.oid=d.objid WHERE c.relkind='S' AND d.refobjid='community.guide_journeys'::regclass")
            check(seq == '0', '① [db] sequences 0 (%s)' % seq)
        idx = sql("SELECT indexdef FROM pg_indexes WHERE schemaname='community' AND indexname='guide_journeys_one_active'")
        summary['index'] = idx
        check('CREATE UNIQUE INDEX' in idx and '(user_id)' in idx and "WHERE (status = 'active'::text)" in idx, '① [db] partial unique index guide_journeys_one_active (user_id) WHERE status = active')
        pk = sql("SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conrelid='community.guide_journeys'::regclass AND contype='p'")
        check(pk == 'PRIMARY KEY (user_id, journey)', '① [db] primary key (user_id, journey) (%s)' % pk)
        cks = sql("SELECT string_agg(pg_get_constraintdef(oid), ' ; ' ORDER BY conname) FROM pg_constraint WHERE conrelid='community.guide_journeys'::regclass AND contype IN ('c','f')")
        summary['constraints'] = cks
        check("journey ~ '^[a-z][a-z0-9-]{1,31}$'" in cks and "'active'" in cks and "'dismissed'" in cks and 'page >= 0' in cks and 'page <= 9' in cks
              and 'step >= 0' in cks and 'step <= 30' in cks and 'REFERENCES users(id) ON DELETE CASCADE' in cks, '① [db] CHECK journey pattern · status four · page 0~9 · step 0~30 · FK users ON DELETE CASCADE')
        cols = sql("SELECT string_agg(column_name || ':' || data_type || ':' || is_nullable || ':' || coalesce(column_default,''), ' ; ' ORDER BY ordinal_position)"
                   " FROM information_schema.columns WHERE table_schema='community' AND table_name='guide_journeys'")
        summary['columns'] = cols
        check(cols.startswith('user_id:bigint:NO:') and 'confirmed_by_user:boolean:NO:false' in cols and 'completed_at:timestamp with time zone:YES:' in cols
              and 'started_at:timestamp with time zone:NO:now()' in cols, '① [db] columns as community ARCH 26.2')
        log = (out / 'schema.log').read_text(errors='replace') if (out / 'schema.log').exists() else ''
        check('ERROR' not in log, '① [db] harness applied the migrations twice — schema log has no ERROR')
        # 세 번째 적용(틀과 같은 갈음) — 오류 0 · 행 · 권한 · 색인 그대로
        mig = (root / 'db/migrations/2026-10-27_qf15_s3_guide_journeys.sql').read_text()
        adapted = out / 's3-migration-third.sql'
        adapted.write_text(mig.replace('SET LOCAL ROLE tbox_owner;', 'SET LOCAL ROLE tbox_testdb;'))
        try:
            before = sql("SELECT count(*) FROM community.guide_journeys")
            r = psql_file(adapted)
            check(r.returncode == 0 and 'ERROR' not in r.stderr, '① [db] third application: exit 0 · no ERROR')
            check(sql("SELECT count(*) FROM community.guide_journeys") == before, '① [db] third application leaves rows unchanged')
        finally:
            adapted.unlink()
        code = '\n'.join(line.split('--', 1)[0] for line in mig.splitlines() if not line.startswith('\\set'))
        stmts = [re.sub(r'\s+', ' ', s).strip().rstrip(';').upper() for s in re.split(r';\s*\n', code) if s.strip()]
        summary['statements'] = [s[:60] for s in stmts]
        check(stmts[:2] == ['BEGIN', 'SET LOCAL ROLE TBOX_OWNER'] and stmts[-1] == 'COMMIT' and "SET LOCAL LOCK_TIMEOUT = '5S'" in stmts,
              '① [db] migration: BEGIN · first statement SET LOCAL ROLE tbox_owner · lock_timeout 5s · COMMIT')
        risky = re.findall(r'\b(DROP\s+\w+|DELETE\s+FROM|TRUNCATE|INSERT\s+INTO|UPDATE\s+[\w.]+\s+SET|CREATE\s+(?:OR\s+REPLACE\s+)?(?:SEQUENCE|FUNCTION|TRIGGER))\b', code, re.I)
        kinds = sorted({s.split(' (')[0].split(' IF NOT EXISTS')[0] for s in stmts})
        summary['kinds'] = kinds
        check('\\set ON_ERROR_STOP on' in mig and risky == [] and all(s in ('BEGIN', 'COMMIT', 'END IF', 'END $$') or s.startswith(('SET LOCAL ', 'CREATE TABLE IF NOT EXISTS COMMUNITY.GUIDE_JOURNEYS ',
              'CREATE UNIQUE INDEX IF NOT EXISTS GUIDE_JOURNEYS_ONE_ACTIVE ', 'DO $$ BEGIN IF EXISTS', 'IF EXISTS')) for s in stmts),
              '① [db] migration: ON_ERROR_STOP · only BEGIN · SET LOCAL · CREATE TABLE/INDEX IF NOT EXISTS · GRANT DO block · COMMIT (rows · DROP · DELETE · sequences · functions 0) %s' % risky)
        grants = (root / 'db/community/grants.sql').read_text()
        block = re.search(r"DO \$\$ BEGIN\n(?:(?!END \$\$;).)*IF to_regclass\('community\.usage_guides'\).*?END \$\$;", grants, re.S)
        check(grants.count("to_regclass('community.guide_journeys')") == 1 and block is not None and "to_regclass('community.guide_journeys')" in block.group(0)
              and 'GRANT SELECT,INSERT,UPDATE ON community.guide_journeys TO tbox_app;' in block.group(0) and 'GRANT SELECT ON community.guide_journeys TO tbox_backup;' in block.group(0),
              '① [db] db/community/grants.sql: one guide_journeys IF block inside the usage_guides DO block (app SIU · backup S)')
        metrics['db'] = summary
    guard('db', db_shape)

    # ─── [api] ② GET ────────────────────────────────────────────────────────────────────────
    def api_get():
        summary = {}
        want = {'employee': {'task-add': True, 'file-share': True, 'minutes-write': True, 'notice-post': False, 'message-send': True, 'invite-colleague': False},
                'admin': {j: True for j in JOURNEYS}, 'guest': {j: False for j in JOURNEYS}}
        for key, expected in want.items():
            code, data = guide_get(key)
            summary[key] = {'code': code, 'can': data and data.get('can'), 'journeys': data and data.get('journeys')}
            check(code == 200 and isinstance(data, dict) and data.get('journeys') == {'active': None, 'paused': [], 'completed': []},
                  '② GET /community/api/me/guide %s: 200 · journeys {active:null, paused:[], completed:[]}' % key)
            check(isinstance(data, dict) and isinstance(data.get('can'), dict) and list(data['can'].keys()) == JOURNEYS and data['can'] == expected,
                  '② %s can = %s' % (key, json.dumps(data and data.get('can'))))
            check(isinstance(data, dict) and isinstance(data.get('csrf_token'), str) and len(data['csrf_token']) >= 32 and isinstance(data.get('guide'), dict),
                  '② %s response keeps guide · has csrf_token' % key)
        acting = new_session(uid('admin'), act_role='Employee')
        code, data = guide_get(acting)
        exp = dict(want['employee'], **{'message-send': False})
        summary['acting'] = {'code': code, 'can': data and data.get('can')}
        check(code == 200 and isinstance(data, dict) and data.get('can') == exp, "② admin acting as Employee: employee values with message-send false %s" % json.dumps(data and data.get('can')))
        metrics['api_get'] = summary
    guard('api_get', api_get)

    # ─── [api] ③ PATCH 창구 ──────────────────────────────────────────────────────────────────
    def api_patch():
        summary = {}
        body = {'journey': 'task-add', 'status': 'active', 'page': 0, 'step': 0}
        url = site + '/community/api/me/journeys'
        r = jpatch('employee', body, csrf=False)
        summary['no_csrf'] = r.status_code
        check(r.status_code == 403, '③ PATCH valid body without CSRF → 403 (%d)' % r.status_code)
        r = req('PATCH', url, None, body=body)
        summary['no_session'] = r.status_code
        check(r.status_code == 401, '③ PATCH without a session → 401 (%d)' % r.status_code)
        for scope in ('community.write', 'read+upload+delete'):
            r = req('PATCH', url, None, body=body, headers={'X-API-Token': users['employee'][scope]['token']})
            summary['token_' + scope] = r.status_code
            check(r.status_code == 401, '③ PATCH with only a personal token header (%s scope, synthetic) → 401 (%d)' % (scope, r.status_code))
        # MCP 토큰 — 합성 DB 에 진짜 모양의 연결 · 접근 토큰 한 벌(이 창구는 Authorization 을 읽지 않는다)
        client = 'mcp_client_' + secrets.token_urlsafe(32)[:43].ljust(43, 'a')
        grant = sql("SELECT gen_random_uuid()::text")
        token = secrets.token_urlsafe(32)
        sql("INSERT INTO public.mcp_clients(id,name,redirect_uris,authorized_at) VALUES(" + lit(client) + ",'S3 합성 연결','[\"https://mcp-fixture.invalid/cb\"]',now())")
        sql("INSERT INTO public.mcp_grants(id,user_id,client_id,security_epoch,scope,resource) SELECT " + lit(grant) + "::uuid," + str(uid('employee')) + "," + lit(client)
            + ",mcp_security_epoch,'qaredi.read qaredi.write','https://mcp-fixture.invalid/mcp' FROM users WHERE id=" + str(uid('employee')))
        sql("INSERT INTO public.mcp_tokens(token_hash,grant_id,kind,expires_at) VALUES('" + hashlib.sha256(token.encode()).hexdigest() + "'," + lit(grant) + "::uuid,'access',now()+interval '10 minutes')")
        r = req('PATCH', url, None, body=body, headers={'Authorization': 'Bearer ' + token})
        summary['mcp'] = r.status_code
        check(r.status_code == 401, '③ PATCH with only an MCP access token header (synthetic grant) → 401 (%d)' % r.status_code)
        for method in ('GET', 'DELETE', 'POST', 'PUT'):
            r = req(method, url, 'employee', body=body if method != 'GET' else None)
            summary[method] = r.status_code
            check(r.status_code == 405, '③ %s /community/api/me/journeys → 405 (%d)' % (method, r.status_code))
        r = jpatch('guest', body)
        summary['guest_task_add'] = r.status_code
        check(r.status_code == 403, '③ guest PATCH task-add → 403 (%d)' % r.status_code)
        r = jpatch('employee', {'journey': 'notice-post', 'status': 'active', 'page': 0, 'step': 0})
        summary['employee_notice'] = r.status_code
        check(r.status_code == 403, '③ employee PATCH notice-post → 403 (S3-1 A) (%d)' % r.status_code)
        r = jpatch('employee', {'journey': 'invite-colleague', 'status': 'active', 'page': 0, 'step': 0})
        check(r.status_code == 403, '③ employee PATCH invite-colleague → 403 (%d)' % r.status_code)
        check(sql("SELECT count(*) FROM community.guide_journeys WHERE user_id IN (" + str(uid('guest')) + "," + str(uid('employee')) + ")") == '0',
              '③ refused requests wrote no rows')
        for bad in ({}, dict(body, user_id=uid('other')), dict(body, confirm=True), {'journey': '../a', 'status': 'active', 'page': 0, 'step': 0}, dict(body, page='0')):
            r = jpatch('employee', bad)
            check(r.status_code == 400 and r.json().get('error') == '잘못된 요청입니다', '③ PATCH %s → 400 MSG_BAD_REQUEST (%d)' % (json.dumps(bad, ensure_ascii=False), r.status_code))
        compact = json.dumps(body, separators=(',', ':'))
        ok_body = compact[:-1] + ' ' * (1024 - len(compact)) + '}'
        big_body = compact[:-1] + ' ' * (1025 - len(compact)) + '}'
        r = req('PATCH', url, 'employee', raw_body=big_body.encode())
        summary['1025'] = r.status_code
        check(len(big_body.encode()) == 1025 and r.status_code == 400, '③ 1025-byte body → 400 (%d)' % r.status_code)
        r = req('PATCH', url, 'employee', raw_body=ok_body.encode())
        summary['1024'] = r.status_code
        data = r.json() if r.status_code == 200 else {}
        check(len(ok_body.encode()) == 1024 and r.status_code == 200, '③ the same body padded to 1024 bytes → 200 (%d)' % r.status_code)
        check(set(data.keys()) == {'journeys', 'can', 'csrf_token'} and isinstance(data.get('journeys'), dict) and data['journeys'].get('active', {}).get('journey') == 'task-add'
              and data['journeys']['active'].get('page') == 0 and isinstance(data['journeys']['active'].get('fact'), dict) and list(data['can'].keys()) == JOURNEYS,
              '③ 200 response = {journeys, can, csrf_token} · active task-add with fact')
        check(r.headers.get('Cache-Control', '').find('no-store') >= 0, '③ Cache-Control no-store')
        metrics['api_patch'] = summary
    guard('api_patch', api_patch)

    # ─── [api] ④ 남의 행 ─────────────────────────────────────────────────────────────────────
    def others():
        a_before = row('employee', 'task-add')
        code, data = guide_get('other')
        check(code == 200 and data['journeys'] == {'active': None, 'paused': [], 'completed': []}, "④ employee A's row (task-add active) is not in employee B's GET")
        r = jpatch('other', {'journey': 'task-add', 'status': 'active', 'page': 0, 'step': 2})
        check(r.status_code == 200 and r.json()['journeys']['active']['step'] == 2, "④ B's PATCH 200 (B's own row)")
        check(row('employee', 'task-add') == a_before and row('other', 'task-add')['step'] == 2, "④ B's PATCH changed only B's row — A's row identical (SQL)")
        r = jpatch('other', {'journey': 'task-add', 'status': 'paused', 'page': 0, 'step': 3})
        check(r.status_code == 200 and row('employee', 'task-add') == a_before and row('other', 'task-add')['status'] == 'paused', "④ B pauses — A's row still identical")
        code, data = guide_get('employee')
        check(data['journeys']['active']['journey'] == 'task-add' and data['journeys']['paused'] == [], "④ A's GET shows only A's rows")
    guard('others', others)

    # ─── [api] ⑤ 표 없는 경우 ────────────────────────────────────────────────────────────────
    def missing_table():
        summary = {}
        sql('ALTER TABLE community.guide_journeys RENAME TO guide_journeys_s3hold')
        try:
            code, data = guide_get('manager')
            summary['get'] = {'code': code, 'keys': data and sorted(data.keys())}
            check(code == 200 and data.get('journeys') is None and data.get('can') is None and isinstance(data.get('guide'), dict) and 'journeys' in data and 'can' in data,
                  '⑤ table renamed: GET 200 · journeys null · can null · guide kept')
            r = req('PATCH', site + '/community/api/me/guide', 'manager', body={'status': 'dismissed', 'context': 'overview', 'step': 0})
            summary['old_patch'] = r.status_code
            check(r.status_code == 200 and r.json().get('guide', {}).get('status') == 'dismissed', '⑤ old PATCH /community/api/me/guide 200 (%d)' % r.status_code)
            r = jpatch('manager', {'journey': 'file-share', 'status': 'active', 'page': 0, 'step': 0})
            summary['new_patch'] = r.status_code
            check(r.status_code == 503 and r.json().get('error') == '안내를 준비 중이에요. 잠시 뒤 다시 열어 주세요.', '⑤ new PATCH → 503 「안내를 준비 중이에요…」 (%d)' % r.status_code)
        finally:
            sql('ALTER TABLE community.guide_journeys_s3hold RENAME TO guide_journeys')
            sql("DELETE FROM community.usage_guides WHERE user_id=" + str(uid('manager')))
        check(sql("SELECT to_regclass('community.guide_journeys') IS NOT NULL") == 't', '⑤ table name restored')
        metrics['missing_table'] = summary
    guard('missing_table', missing_table)

    # ─── [api] ⑥ 첫 로그인 제안 ──────────────────────────────────────────────────────────────
    def offer():
        new_id = sql("INSERT INTO users(username,password_hash,role,team_id,mfa_secret,mfa_enabled,expires_at) SELECT 'claude_s3_offer01',password_hash,'Employee',team_id,mfa_secret,true,"
                     "clock_timestamp()+interval '14 days' FROM users WHERE id=" + str(uid('employee')) + " RETURNING id").splitlines()[0]
        sql("INSERT INTO user_consents(user_id,doc,version,action,ip) SELECT " + new_id + ",doc,version,action,ip FROM user_consents WHERE user_id=" + str(uid('employee')))
        acc = new_session(int(new_id))
        users['offer'] = {'id': int(new_id), **acc}
        code, data = guide_get(acc)
        check(code == 200 and data['guide'].get('offer') is True and data['journeys'] == {'active': None, 'paused': [], 'completed': []},
              '⑥ new synthetic account after the guide activation: guide.offer true while it has no journey rows')
        r = jpatch(acc, {'journey': 'message-send', 'status': 'active', 'page': 0, 'step': 0})
        check(r.status_code == 200, '⑥ the new account starts a journey (%d)' % r.status_code)
        code, data = guide_get(acc)
        check(code == 200 and data['guide'].get('offer') is False and sql("SELECT count(*) FROM community.usage_guides WHERE user_id=" + new_id) == '0',
              '⑥ one journey row → guide.offer false (usage_guides untouched — UsageGuide.php unchanged)')
        r = jpatch(acc, {'journey': 'message-send', 'status': 'paused', 'page': 0, 'step': 0})
        code, data = guide_get(acc)
        check(data['guide'].get('offer') is False, '⑥ a paused row still keeps the offer off')
    guard('offer', offer)

    # ─── [state] ⑦ 진행 중 하나 ──────────────────────────────────────────────────────────────
    def one_active():
        r1 = jpatch('manager', {'journey': 'file-share', 'status': 'active', 'page': 0, 'step': 3})
        r2 = jpatch('manager', {'journey': 'message-send', 'status': 'active', 'page': 0, 'step': 1})
        fs, ms = row('manager', 'file-share'), row('manager', 'message-send')
        check(r1.status_code == 200 and r2.status_code == 200 and fs['status'] == 'paused' and fs['step'] == 3 and ms['status'] == 'active' and active_count('manager') == 1,
              '⑦ file-share active → message-send active: file-share paused (step kept) · active rows 1 (SQL)')
        data = r2.json()['journeys']
        check(data['active']['journey'] == 'message-send' and data['paused'] == [{'journey': 'file-share', 'page': 0, 'step': 3}], '⑦ response: active message-send · paused [file-share]')
    guard('one_active', one_active)

    # ─── [state] ⑧ 시작 시각 ─────────────────────────────────────────────────────────────────
    def start_time():
        summary = {}
        k = 'manager'
        body = {'journey': 'minutes-write', 'status': 'active', 'page': 0, 'step': 0}
        check(jpatch(k, body).status_code == 200, '⑧ minutes-write active (T0)')
        t0 = started_epoch(k, 'minutes-write')
        time.sleep(1.1)
        r = jpatch(k, dict(body, page=1, step=0))
        check(r.status_code == 200 and started_epoch(k, 'minutes-write') == t0 and row(k, 'minutes-write')['page'] == 1, '⑧ 1 s later {active, page 1, step 0}: started_at = T0')
        jpatch(k, dict(body, status='paused', page=1, step=2))
        time.sleep(0.3)
        r = jpatch(k, dict(body, page=1, step=2))
        check(r.status_code == 200 and started_epoch(k, 'minutes-write') == t0 and row(k, 'minutes-write')['status'] == 'active', '⑧ paused → active: started_at = T0')
        r = jpatch(k, dict(body, page=1, step=4, restart=True))
        t1 = started_epoch(k, 'minutes-write')
        rw = row(k, 'minutes-write')
        check(r.status_code == 200 and t1 > t0 and rw['page'] == 0 and rw['step'] == 0, '⑧ restart:true → started_at later than T0 · page 0 · step 0')
        jpatch(k, dict(body, status='dismissed'))
        time.sleep(0.3)
        r = jpatch(k, body)
        t2 = started_epoch(k, 'minutes-write')
        check(r.status_code == 200 and t2 > t1 and row(k, 'minutes-write')['status'] == 'active', '⑧ dismissed → active: new start time')
        # completed → active — 결과 하나(보통 문서)를 만든 뒤 완성 → 다시 하기
        p = req('POST', site + '/community/api/pages', k, body={'title': 'S3 ⑧ 합성 문서'})
        check(p.status_code == 201, '⑧ fixture: manager writes a plain document (%d)' % p.status_code)
        r = jpatch(k, dict(body, status='completed', page=1, step=6))
        check(r.status_code == 200 and row(k, 'minutes-write')['status'] == 'completed' and row(k, 'minutes-write')['completed'] != '', '⑧ completed (fact true)')
        time.sleep(0.3)
        r = jpatch(k, body)
        t3 = started_epoch(k, 'minutes-write')
        rw = row(k, 'minutes-write')
        check(r.status_code == 200 and t3 > t2 and rw['status'] == 'active' and rw['completed'] == '', '⑧ completed → active: new start time · completed_at cleared')
        summary.update(t0=t0, t1=t1, t2=t2, t3=t3)
        metrics['start_time'] = summary
    guard('start_time', start_time)

    # ─── [state] ⑨ 행 없음 · 그만둔 행 ──────────────────────────────────────────────────────
    def bad_states():
        k = 'admin'
        for st in ('paused', 'dismissed', 'completed'):
            r = jpatch(k, {'journey': 'invite-colleague', 'status': st, 'page': 0, 'step': 0})
            check(r.status_code == 400, '⑨ no row → %s 400 (%d)' % (st, r.status_code))
        check(row(k, 'invite-colleague') is None, '⑨ still no row')
        jpatch(k, {'journey': 'invite-colleague', 'status': 'active', 'page': 0, 'step': 0})
        jpatch(k, {'journey': 'invite-colleague', 'status': 'dismissed', 'page': 0, 'step': 1})
        for st in ('completed', 'paused', 'dismissed'):
            r = jpatch(k, {'journey': 'invite-colleague', 'status': st, 'page': 0, 'step': 1})
            check(r.status_code == 400, '⑨ dismissed row → %s 400 (%d)' % (st, r.status_code))
        check(row(k, 'invite-colleague')['status'] == 'dismissed', '⑨ the dismissed row is unchanged')
    guard('bad_states', bad_states)

    # ─── [state] ⑩ 사실 여섯 ─────────────────────────────────────────────────────────────────
    def facts():
        summary = {}
        # file-share — 시작 전 파일만 있으면 400 → 그 계정이 하나 올림 → 200
        before = int(sql("SELECT count(*) FROM files WHERE uploaded_by=" + str(uid('employee')) + " AND deleted_at IS NULL"))
        check(before > 0, '⑩ fixture: the employee already has files from before (%d)' % before)
        jpatch('employee', {'journey': 'file-share', 'status': 'active', 'page': 0, 'step': 0})
        r = jpatch('employee', {'journey': 'file-share', 'status': 'completed', 'page': 0, 'step': 5})
        summary['file_before'] = r.status_code
        check(r.status_code == 400 and r.json().get('error') == NO_RESULT, '⑩ file-share: only files from before the start → completed 400 「%s」' % NO_RESULT)
        code, data = guide_get('employee')
        check(data['journeys']['active']['fact'] == {'ok': False, 'ref': None}, '⑩ file-share GET fact {ok:false, ref:null}')
        up = req('POST', site + '/tbox/api/files', 'employee', data={'access_level': 'internal'}, files={'file': ('S3-합성.txt', b'S3 synthetic upload', 'application/octet-stream')})
        check(up.status_code == 201, '⑩ fixture: the employee uploads one file through the app (%d)' % up.status_code)
        code, data = guide_get('employee')
        check(data['journeys']['active']['fact'] == {'ok': True, 'ref': None}, '⑩ file-share GET fact {ok:true}')
        r = jpatch('employee', {'journey': 'file-share', 'status': 'completed', 'page': 0, 'step': 5})
        rw = row('employee', 'file-share')
        summary['file_after'] = r.status_code
        check(r.status_code == 200 and rw['status'] == 'completed' and rw['completed'] != '' and rw['confirmed'] == 'false', '⑩ file-share completed 200 · completed_at · confirmed_by_user false')
        check([c['journey'] for c in r.json()['journeys']['completed']] == ['file-share'] and r.json()['journeys']['active'] is None, '⑩ GET/PATCH completed list [file-share] · active null')
        # task-add — 「내 할 일」 빠른 추가 하나
        jpatch('employee', {'journey': 'task-add', 'status': 'active', 'page': 0, 'step': 0, 'restart': True})
        check(jpatch('employee', {'journey': 'task-add', 'status': 'completed', 'page': 0, 'step': 3}).status_code == 400, '⑩ task-add: nothing yet → 400')
        t = req('POST', site + '/community/api/pages', 'employee', body={'my_tasks': True, 'title': 'S3 합성 할 일'})
        check(t.status_code == 201, '⑩ fixture: 「내 할 일」 quick add (%d)' % t.status_code)
        r = jpatch('employee', {'journey': 'task-add', 'status': 'completed', 'page': 0, 'step': 3})
        check(r.status_code == 200, '⑩ task-add: one quick-add task → completed 200 (%d)' % r.status_code)
        # minutes-write — 「내 할 일」 자동 프로젝트 · 새 프로젝트(데이터베이스 페이지) · 그 행만이면 거짓 · 보통 문서 → 참 · ref
        o = 'other'
        jpatch(o, {'journey': 'minutes-write', 'status': 'active', 'page': 0, 'step': 0})
        t = req('POST', site + '/community/api/pages', o, body={'my_tasks': True, 'title': 'S3 ⑩ 할 일'})
        auto = sql("SELECT count(*) FROM community.my_tasks_projects WHERE user_id=" + str(uid(o)))
        proj = req('POST', site + '/community/api/pages', o, body={'title': 'S3 ⑩ 프로젝트', 'database': True, 'preset': 'project'})
        pid = proj.json().get('page', {}).get('id') if proj.status_code == 201 else None
        rowp = req('POST', site + '/community/api/pages', o, body={'parent_id': pid, 'title': 'S3 ⑩ 프로젝트 줄'}) if pid else None
        check(t.status_code == 201 and auto == '1' and proj.status_code == 201 and rowp is not None and rowp.status_code == 201,
              '⑩ fixture: other makes a 「내 할 일」 task (auto project) · a new project (database page) · a row in it (%s %s %s %s)'
              % (t.status_code, auto, proj.status_code, rowp.status_code if rowp is not None else '-'))
        code, data = guide_get(o)
        check(data['journeys']['active']['fact'] == {'ok': False, 'ref': None}, '⑩ minutes-write: auto project · project page · its rows only → fact false')
        r = jpatch(o, {'journey': 'minutes-write', 'status': 'completed', 'page': 1, 'step': 6})
        check(r.status_code == 400, '⑩ minutes-write completed → 400 (%d)' % r.status_code)
        d = req('POST', site + '/community/api/pages', o, body={'title': 'S3 ⑩ 회의록'})
        doc = d.json().get('page', {}).get('id') if d.status_code == 201 else None
        code, data = guide_get(o)
        summary['minutes_ref'] = data['journeys']['active']['fact']
        check(doc and data['journeys']['active']['fact'] == {'ok': True, 'ref': doc}, '⑩ minutes-write: one plain document → fact true · ref = its public_id')
        check(jpatch(o, {'journey': 'minutes-write', 'status': 'completed', 'page': 1, 'step': 6}).status_code == 200, '⑩ minutes-write completed 200')
        # notice-post — 관리자의 업무 공지 게시판 글
        reauth_now('admin')
        b = req('POST', site + '/community/api/boards', 'admin', body={'name': 'S3 합성 공지', 'description': '', 'access_level': 'internal', 'board_kind': 'notice', 'area': 'work'})
        board = b.json().get('board', {}).get('id') if b.status_code in (200, 201) and isinstance(b.json().get('board'), dict) else None
        if board is None:
            board = int(sql("INSERT INTO community.spaces(kind,name,description,access_level,board_kind,area,is_active) VALUES('board','S3 합성 공지','','internal','notice','work',true) RETURNING id").splitlines()[0])
            summary['board_by_sql'] = True
        summary['board'] = {'api': b.status_code, 'id': board}
        jpatch('admin', {'journey': 'notice-post', 'status': 'active', 'page': 0, 'step': 0})
        check(jpatch('admin', {'journey': 'notice-post', 'status': 'completed', 'page': 2, 'step': 1}).status_code == 400, '⑩ notice-post: nothing yet → 400')
        post = req('POST', site + '/community/api/boards/' + str(board) + '/pages', 'admin', body={'title': 'S3 합성 공지 글', 'tags': []})
        ref = post.json().get('page', {}).get('id') if post.status_code == 201 else None
        code, data = guide_get('admin')
        summary['notice_ref'] = data['journeys']['active']['fact']
        check(ref and data['journeys']['active']['fact'] == {'ok': True, 'ref': ref}, "⑩ notice-post: the admin's post on the work notice board → fact true · ref (%s)" % post.status_code)
        check(jpatch('admin', {'journey': 'notice-post', 'status': 'completed', 'page': 2, 'step': 1}).status_code == 200, '⑩ notice-post completed 200')
        # message-send — 모아 AI 에게만 보내면 거짓 · 동료에게 하나 → 참
        jpatch('manager', {'journey': 'message-send', 'status': 'active', 'page': 0, 'step': 0, 'restart': True})
        ai = req('POST', site + '/tbox/api/messages/ai', 'manager', body={'text': 'S3 합성 AI 질문', 'thread_id': None, 'request_id': str(uuid.uuid4())})
        summary['ai_status'] = ai.status_code
        code, data = guide_get('manager')
        check(data['journeys']['active']['fact']['ok'] is False and jpatch('manager', {'journey': 'message-send', 'status': 'completed', 'page': 0, 'step': 3}).status_code == 400,
              '⑩ message-send: a 모아 AI request only (status %d — AI off in the harness · other table) → fact false · completed 400' % ai.status_code)
        m = req('POST', site + '/tbox/api/messages/' + str(uid('employee')), 'manager', body={'text': 'S3 합성 한마디', 'request_id': str(uuid.uuid4())})
        check(m.status_code in (200, 201), '⑩ fixture: manager sends one message to a colleague (%d)' % m.status_code)
        check(jpatch('manager', {'journey': 'message-send', 'status': 'completed', 'page': 0, 'step': 3}).status_code == 200, '⑩ message-send completed 200')
        # invite-colleague — 초대 하나
        jpatch('admin', {'journey': 'invite-colleague', 'status': 'active', 'page': 0, 'step': 0})
        check(jpatch('admin', {'journey': 'invite-colleague', 'status': 'completed', 'page': 0, 'step': 2}).status_code == 400, '⑩ invite-colleague: nothing yet → 400')
        reauth_now('admin')
        inv = req('POST', site + '/tbox/api/invites', 'admin', body={'label': 'S3 합성 초대'})
        check(inv.status_code == 201, '⑩ fixture: admin makes one invite (%d)' % inv.status_code)
        check(jpatch('admin', {'journey': 'invite-colleague', 'status': 'completed', 'page': 0, 'step': 2}).status_code == 200, '⑩ invite-colleague completed 200')
        metrics['facts'] = summary
    guard('facts', facts)

    # ─── [state] ⑪ 동시 ─────────────────────────────────────────────────────────────────────
    def concurrent():
        summary = {'codes': [], 'active_after': []}
        k = 'admin'
        sql("UPDATE community.guide_journeys SET status='paused' WHERE user_id=" + str(uid(k)) + " AND status='active'")
        check(SECOND > 0, '⑪ second owned server port present (%d)' % SECOND)
        pairs = [(JOURNEYS[i % 6], JOURNEYS[(i + 1 + i // 6) % 6]) for i in range(20)]
        pairs = [(a, b) if a != b else (a, JOURNEYS[(JOURNEYS.index(b) + 1) % 6]) for a, b in pairs]
        bad = []
        with ThreadPoolExecutor(max_workers=2) as pool:
            for a, b in pairs:
                start = threading.Barrier(2)

                def go(j, port):
                    start.wait()
                    return jpatch(k, {'journey': j, 'status': 'active', 'page': 0, 'step': 0}, port=port).status_code
                f1, f2 = pool.submit(go, a, None), pool.submit(go, b, SECOND)
                codes = (f1.result(), f2.result())
                n = active_count(k)
                summary['codes'].append(codes)
                summary['active_after'].append(n)
                if any(c not in (200, 409) for c in codes) or n != 1:
                    bad.append((a, b, codes, n))
        flat = [c for pair in summary['codes'] for c in pair]
        summary['tally'] = {str(c): flat.count(c) for c in sorted(set(flat))}
        check(not bad, '⑪ 20 concurrent pairs (two servers): only 200 or 409 · 500 none · active rows 1 after every pair %s %s' % (summary['tally'], bad[:3]))
        log = (Path(root.parent) / 'logs/php.log').read_text(errors='replace') if (Path(root.parent) / 'logs/php.log').exists() else ''
        check(not re.search(r'(Fatal|Uncaught)[^\n]*(GuideJourney|guide_journeys_api)', log), '⑪ PHP log has no fatal · uncaught error from the journeys code')
        metrics['concurrent'] = summary
    guard('concurrent', concurrent)

    # ─── [state] ⑫ 감사 ─────────────────────────────────────────────────────────────────────
    def audit():
        allow = sql("SELECT count(*) FROM audit_logs WHERE result='allow' AND (coalesce(target_name,'') LIKE '%/api/me/journeys%' OR coalesce(detail::text,'') LIKE '%/api/me/journeys%')")
        before = sql("SELECT count(*) FROM audit_logs")
        for st, step in (('active', 1), ('active', 2), ('paused', 2), ('active', 0)):
            jpatch('other', {'journey': 'task-add', 'status': st, 'page': 0, 'step': step})
        after = sql("SELECT count(*) FROM audit_logs")
        check(allow == '0', '⑫ audit_logs: allow rows for /api/me/journeys 0 (%s)' % allow)
        check(before == after, '⑫ four successful PATCHes add no audit row (%s → %s)' % (before, after))
    guard('audit', audit)
finally:
    (out / 'guide-journeys-http-metrics.json').write_text(json.dumps(metrics, ensure_ascii=False, indent=1, default=str))
    (out / 'guide-journeys-http-results.json').write_text(json.dumps(checks, ensure_ascii=False, indent=2))
    print('RESULT pass=' + str(sum(x['pass'] for x in checks)) + ' fail=' + str(sum(not x['pass'] for x in checks)), flush=True)
if any(not x['pass'] for x in checks):
    raise SystemExit(1)
