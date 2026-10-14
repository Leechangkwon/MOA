#!/usr/bin/env python3
"""사이트 Q:F-12 카페 C1 — 보안 바닥 수락 시험 (실제 PHP · PostgreSQL, 격리 틀 memories_harness.py 만).

지시서 docs/cafe-directive.md 「C1 완료 기준」 ①~⑩(⑪ 정적 검사는 틀 밖에서 — 보고서). 커뮤니티 ARCHITECTURE 25장.
합성 DB(tbox_memories_test_*) · 임시 복사본 · 로컬 php -S 127.0.0.1:8768 · fixture claude_ 계정 + 시험이 만드는 카나리아 계정 하나.
운영 DB · 운영 8082 · 시드 계정 0. 요청 UA 는 tbox-review/<날짜>.

계정: W = 카나리아(아이디 anoncanary7f3a · 이름 익명카나리아, 팀 A) · A = claude_employee01(팀 A) · B = claude_manager01(팀 A) ·
      E = claude_employee02(팀 B — 다른 직원) · M = claude_admin01(관리자) · G = claude_guest01(손님).
결과 파일에는 단언 이름 · 참/거짓 + 확정한 값(상태 · 이름표 · 질의 수)만 — 세션 · CSRF 0.
"""
from pathlib import Path
exec(compile(Path(__file__).with_name('memories_http.py').read_text().split('try:\n for u in users:')[0], __file__, 'exec'))
import struct
import urllib.parse
assert str(root).startswith('/tmp/qaredi-stage13-') and manifest['database'].startswith('tbox_memories_test_')

CANARY_USER = 'anoncanary7f3a'
CANARY_NAME = '익명카나리아'
FIX = {'A': 'employee', 'B': 'manager', 'E': 'other', 'M': 'admin', 'G': 'guest'}
UNAME = {'A': 'claude_employee01', 'B': 'claude_manager01', 'E': 'claude_employee02', 'M': 'claude_admin01', 'G': 'claude_guest01',
         'W': CANARY_USER}
CRED = {k: {'session': users[v]['session'], 'csrf': users[v]['csrf']} for k, v in FIX.items()}
codes = {}
checks = []
work = root.parent
storage = work / 'storage'
MIGRATION = root / 'db/migrations/2026-10-14_community_cafe.sql'


def check(ok, name):
    checks.append({'pass': bool(ok), 'name': name})
    print(('PASS ' if ok else 'FAIL ') + name, flush=True)
    return bool(ok)


def http(method, url, body=None, who='A', csrf=True, files=None):
    h = {'User-Agent': 'tbox-review/' + time.strftime('%Y-%m-%d')}
    if who:
        h['Cookie'] = '__Host-tbox_session=' + CRED[who]['session']
        if csrf:
            h['X-CSRF-Token'] = CRED[who]['csrf']
    return requests.request(method, url, json=body, headers=h, files=files, timeout=60)


def req(method, path, body=None, who='A', **kw):
    return http(method, base + path, body, who, **kw)


def expect(method, path, body, who, status, name, **kw):
    r = req(method, path, body, who, **kw)
    check(r.status_code == status, name + ' → ' + str(status) + ' (got ' + str(r.status_code) + ')')
    if r.status_code != status:
        print('  body=' + r.text[:300], flush=True)
    return r


def n(text):
    return int((sql(text).splitlines() or ['0'])[0] or 0)


def lit(v):
    return "'" + str(v).replace("'", "''") + "'"


def pk(public_id):
    return n('SELECT id FROM community.pages WHERE public_id=' + lit(public_id))


def psql_file(path):
    env = {k: v for k, v in os.environ.items() if not k.startswith('PG')}
    env['PGPASSFILE'] = str(Path.home() / '.config/qaredi/pgpass-testdb')
    return subprocess.run(['psql', '-h', '127.0.0.1', '-U', 'tbox_testdb', '-w', '-X', '-At', '-d', manifest['database'],
                           '-v', 'ON_ERROR_STOP=1', '-f', str(path)], env=env, capture_output=True, text=True)


def psql_try(text):
    env = {k: v for k, v in os.environ.items() if not k.startswith('PG')}
    env['PGPASSFILE'] = str(Path.home() / '.config/qaredi/pgpass-testdb')
    return subprocess.run(['psql', '-h', '127.0.0.1', '-U', 'tbox_testdb', '-w', '-X', '-At', '-d', manifest['database'],
                           '-v', 'ON_ERROR_STOP=1'], env=env, input=text, capture_output=True, text=True)


def php(code, owner=False):
    cfg = os.environ['FILE_RENAME_FIXTURE_CONFIG'] if owner else str(root / '.env')
    prelude = ("require 'src/Env.php'; Env::load(" + repr(cfg) + "); require 'src/bootstrap.php';"
               "require_once 'src/community/routes.php';")
    done = subprocess.run(['php', '-r', prelude + code], cwd=root, capture_output=True, text=True, timeout=120)
    if done.returncode != 0:
        print('PHP_ERR=' + done.stderr[-600:], flush=True)
    return done.stdout.strip()


def reauth(who, fresh=True):
    sid = hashlib.sha256(CRED[who]['session'].encode()).hexdigest()
    sql('UPDATE sessions SET reauth_at=' + ('now()' if fresh else 'NULL') + ' WHERE id=' + lit(sid))


IDKEYS = {'author_id', 'user_id', 'actor_id', 'owner_user_id', 'space_owner_user_id', 'by_id', '_by_id', 'writer_id',
          'reporter_id', 'last_edited_by', 'requester_id', 'edited_by', 'verified_by', 'hidden_by', 'published_by'}


def id_leaks(obj, ids, path=''):
    """사람 id 가 응답에 실렸나 — 사람 칸 이름의 키 값이 W/A/B id 이거나, 사람 객체(display_name 을 든 객체)에 id 가 있으면 새는 것."""
    out = []
    if isinstance(obj, dict):
        if 'display_name' in obj and ('id' in obj or 'user_id' in obj):
            out.append(path + '{person with id}')
        for k, v in obj.items():
            if k in IDKEYS and isinstance(v, int) and v in ids:
                out.append(path + '.' + k)
            out += id_leaks(v, ids, path + '.' + str(k))
    elif isinstance(obj, list):
        for i, v in enumerate(obj):
            out += id_leaks(v, ids, path + '[' + str(i) + ']')
    return out


def no_canary(text):
    return CANARY_USER not in text and CANARY_NAME not in text


NAMES = {}   # C1b — W · A · B 의 아이디 · 표시 이름(준비에서 DB 로 채움)


def no_names(text, skip=''):
    """C1b ②: 카나리아에 더해 댓글 쓴 사람 A · B 의 아이디 · 표시 이름도 0 — skip 은 본인 시점(그 사람 이름은 빼고 본다)."""
    return all(v not in text for k, vals in NAMES.items() if k not in skip for v in vals)


def doc_with_text(text, extra=None):
    blocks = [{'id': str(uuid.uuid4()), 'type': 'paragraph', 'text': [{'t': text, 'm': {}}]}]
    return {'schema': 1, 'blocks': blocks + (extra or [])}


def save_doc(pid, doc, who):
    cur = req('GET', '/api/pages/' + pid, None, who).json()['page']['doc_version']
    return req('PATCH', '/api/pages/' + pid, {'doc': doc, 'doc_version': cur}, who)


def board_post(board_id, title, who):
    r = expect('POST', '/api/boards/' + str(board_id) + '/pages', {'title': title}, who, 201, 'post 「' + title + '」 by ' + who)
    return r.json()['page']['id'] if r.status_code == 201 else str(uuid.uuid4())


def comment(pid, body, who, parent=None):
    payload = {'body': body}
    if parent:
        payload['parent_id'] = parent
    r = expect('POST', '/api/pages/' + pid + '/comments', payload, who, 201, 'comment by ' + who)
    return r.json()['comment']['id'] if r.status_code == 201 else str(uuid.uuid4())


def notifications(who):
    return req('GET', '/api/notifications', None, who)


JPG = bytes.fromhex('ffd8ffe000104a46494600010100000100010000ffdb004300080606070605080707070909080a0c140d0c0b0b0c1912130f141d1a1f1e1d1a1c1c20242e2720222c231c1c2837292c30313434341f27393d38323c2e333432ffc0000b080001000101011100ffc4001f0000010501010101010100000000000000000102030405060708090a0bffc400b5100002010303020403050504040000017d01020300041105122131410613516107227114328191a1082342b1c11552d1f02433627282090a161718191a25262728292a3435363738393a434445464748494a535455565758595a636465666768696a737475767778797a838485868788898a92939495969798999aa2a3a4a5a6a7a8a9aab2b3b4b5b6b7b8b9bac2c3c4c5c6c7c8c9cad2d3d4d5d6d7d8d9dae1e2e3e4e5e6e7e8e9eaf1f2f3f4f5f6f7f8f9faffda0008010100003f00fbd3ffd9')


def jpeg_with_gps():
    """합성 JPEG + APP1 Exif(IFD0: ImageDescription 「GPSCANARY…」 · GPSInfo → GPS IFD: 위도 N 37). 사진 · 위치는 지어낸 값."""
    desc = b'GPSCANARY-37.5665N\x00'
    # TIFF (big endian) — IFD0 at 8: 2 entries
    ifd0_off = 8
    ifd0_size = 2 + 2 * 12 + 4
    desc_off = ifd0_off + ifd0_size
    gps_off = desc_off + len(desc)
    tiff = b'MM\x00\x2a' + struct.pack('>I', ifd0_off)
    tiff += struct.pack('>H', 2)
    tiff += struct.pack('>HHII', 0x010E, 2, len(desc), desc_off)      # ImageDescription ASCII
    tiff += struct.pack('>HHII', 0x8825, 4, 1, gps_off)                # GPSInfo IFD pointer
    tiff += struct.pack('>I', 0)
    tiff += desc
    # GPS IFD: 1 entry GPSLatitudeRef 'N\0'
    tiff += struct.pack('>H', 1) + struct.pack('>HHI', 0x0001, 2, 2) + b'N\x00\x00\x00' + struct.pack('>I', 0)
    app1 = b'Exif\x00\x00' + tiff
    seg = b'\xff\xe1' + struct.pack('>H', len(app1) + 2) + app1
    return JPG[:2] + seg + JPG[2:]


def stored_file(att_public_id):
    name = sql('SELECT stored_name FROM community.page_attachments WHERE public_id=' + lit(att_public_id))
    return storage / 'attachments' / name[:2] / name if name else None


def audit_rows(params, who='M'):
    rows, page = [], 1
    while True:
        q = dict(params, page=str(page))
        r = http('GET', site + '/tbox/api/audit?' + urllib.parse.urlencode(q), None, who)
        if r.status_code != 200:
            return r, rows
        j = r.json()
        rows += j['rows']
        if page * j['per_page'] >= j['total'] or page >= 30:
            return r, rows
        page += 1


def count_cafe_queries(user_id):
    code = (
        "final class CafeCountStmt extends PDOStatement { public static int $n = 0; protected function __construct() {}"
        " public function execute(?array $p = null): bool { self::$n++; return parent::execute($p); } }"
        "$db = Db::instance(); $open = new ReflectionMethod(Db::class, 'open'); $open->setAccessible(true);"
        "$pdo = $open->invoke($db, false); $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [CafeCountStmt::class, []]);"
        "$prop = new ReflectionProperty(Db::class, 'pdo'); $prop->setAccessible(true); $prop->setValue($db, $pdo);"
        "$user = community_user_by_id(" + str(int(user_id)) + ") + ['reauth_fresh' => false];"
        "CafeCountStmt::$n = 0; $params = []; ob_start(); include 'src/community/handlers/cafe_home.php'; $out = ob_get_clean();"
        "$j = json_decode($out, true); echo json_encode(['queries' => CafeCountStmt::$n, 'recent' => count($j['recent'] ?? []),"
        " 'boards' => count($j['boards'] ?? []), 'popular' => count($j['popular'] ?? [])]);")
    out = php(code)
    try:
        return json.loads(out.splitlines()[-1])
    except Exception:
        print('COUNT_OUT=' + out[-400:], flush=True)
        return {'queries': -1}


try:
    # ════════ 준비 — 카나리아 계정 W · 사람 번호 ═════════════════════════════════════
    made = php(
        "require_once 'src/Legal.php'; $db = Db::instance();"
        "$team = $db->run(\"SELECT team_id FROM users WHERE username='claude_employee01'\")->fetchColumn();"
        "$id = (int) $db->run(\"INSERT INTO users(username,display_name,password_hash,role,team_id,mfa_secret,mfa_enabled,expires_at)"
        " VALUES(:u,:d,:h,'Employee',:t,:s,true,clock_timestamp()+interval '14 days') RETURNING id\","
        " [':u'=>" + repr(CANARY_USER) + ", ':d'=>" + repr(CANARY_NAME) + ", ':h'=>password_hash(bin2hex(random_bytes(16)), PASSWORD_ARGON2ID),"
        " ':t'=>$team, ':s'=>Totp::generateSecret()])->fetchColumn();"
        "$session = bin2hex(random_bytes(32)); $hash = hash('sha256', $session);"
        "$db->run(\"INSERT INTO sessions(id,user_id,expires_at,ip,user_agent) VALUES(:h,:u,clock_timestamp()+interval '8 hours','127.0.0.1',:ua)\","
        " [':h'=>$hash, ':u'=>$id, ':ua'=>'tbox-review/'.date('Y-m-d')]);"
        "foreach (['terms','privacy','age14','ai_overseas'] as $d) { $db->run(\"INSERT INTO user_consents(user_id,doc,version,action,ip) VALUES(:u,:d,:v,'agree','127.0.0.1')\","
        " [':u'=>$id, ':d'=>$d, ':v'=>Legal::current($d)]); }"
        "echo json_encode(['id'=>$id, 'session'=>$session, 'csrf'=>hash_hmac('sha256','csrf:'.$hash, Env::get('APP_SECRET'))]);", owner=True)
    wcred = json.loads(made.splitlines()[-1])
    CRED['W'] = {'session': wcred['session'], 'csrf': wcred['csrf']}
    UID = {k: n('SELECT id FROM users WHERE username=' + lit(v)) for k, v in UNAME.items()}
    check(UID['W'] == wcred['id'], 'setup: canary account W created (synthetic)')
    team_a = n("SELECT team_id FROM users WHERE username='claude_employee01'")
    team_b = n("SELECT team_id FROM users WHERE username='claude_employee02'")
    for k in ('W', 'A', 'B', 'E', 'M', 'G'):
        expect('GET', '/api/me/space', None, k, 200, 'setup: personal space ' + k)
    WAB = {UID['W'], UID['A'], UID['B']}
    for k in ('W', 'A', 'B'):
        dn = sql('SELECT coalesce(display_name, \'\') FROM users WHERE id=' + str(UID[k]))
        NAMES[k] = [x for x in (UNAME[k], dn) if x and len(x) >= 2]
    codes['wab_names_checked'] = {k: len(v) for k, v in NAMES.items()}

    # ════════ ① 스키마 ═══════════════════════════════════════════════════════════════
    # 첫 게시판 셋은 운영 이주(2026-09-24)가 만든 것 — 합성 새 설치에는 없으므로 같은 모양으로 넣은 뒤 카페 이주를 두 번 더 돌린다.
    sql("INSERT INTO community.spaces (kind,name,description,access_level,notice,board_kind) VALUES "
        "('board','공지사항','합성','public',true,'notice'),('board','자유게시판','합성','internal',false,'discussion'),"
        "('board','질문과 답변','합성','internal',false,'qa')")
    shape_sql = ("SELECT string_agg(conrelid::regclass || ':' || conname || ':' || pg_get_constraintdef(oid), E'\\n' ORDER BY 1)"
                 " FROM pg_constraint WHERE conrelid IN ('community.spaces'::regclass,'community.reports'::regclass,"
                 "'community.anon_aliases'::regclass,'community.board_requests'::regclass,'community.notifications'::regclass);")
    acl_sql = ("SELECT string_agg(relname || ':' || coalesce(relacl::text,''), E'\\n' ORDER BY relname) FROM pg_class c JOIN pg_namespace s ON s.oid=c.relnamespace"
               " WHERE s.nspname='community' AND relname IN ('reports','anon_aliases','board_requests');")
    r1 = psql_file(MIGRATION)
    shape1, acl1 = sql(shape_sql), sql(acl_sql)
    rows1 = sql("SELECT string_agg(name || ':' || area || ':' || board_kind || ':' || access_level, ',' ORDER BY id) FROM community.spaces WHERE kind='board'")
    r2 = psql_file(MIGRATION)
    shape2, acl2 = sql(shape_sql), sql(acl_sql)
    rows2 = sql("SELECT string_agg(name || ':' || area || ':' || board_kind || ':' || access_level, ',' ORDER BY id) FROM community.spaces WHERE kind='board'")
    check(r1.returncode == 0 and r2.returncode == 0, '① migration re-run twice more on the populated DB → rc 0 · 0')
    check(shape1 == shape2 and acl1 == acl2 and rows1 == rows2, '① migration twice: constraints · grants · board rows identical (SAME)')
    (out / 'cafe-c1-schema-shape.txt').write_text(shape2 + '\n\n' + acl2 + '\n\n' + rows2 + '\n')
    free = n("SELECT id FROM community.spaces WHERE kind='board' AND name='자유게시판'")
    anon = n("SELECT id FROM community.spaces WHERE kind='board' AND board_kind='anonymous'")
    qa = n("SELECT id FROM community.spaces WHERE kind='board' AND name='질문과 답변'")
    check(sql("SELECT area FROM community.spaces WHERE id=" + str(free)) == 'cafe', '① 자유게시판 area = cafe (moved by migration)')
    check(sql("SELECT area FROM community.spaces WHERE id=" + str(qa)) == 'work', '① 질문과 답변 stays area = work')
    check(n("SELECT count(*) FROM community.spaces WHERE kind='board' AND board_kind='anonymous'") == 1
          and sql("SELECT name||':'||access_level||':'||area FROM community.spaces WHERE id=" + str(anon)) == '익명게시판:internal:cafe',
          '① exactly one 익명게시판 (internal · anonymous · cafe)')
    bad = psql_try("INSERT INTO community.spaces (kind,name,access_level,board_kind,area) VALUES ('board','익명업무','internal','anonymous','work');")
    check(bad.returncode != 0 and 'spaces_board_anon_cafe' in bad.stderr, '① anonymous + work → CHECK spaces_board_anon_cafe rejects')
    bad2 = psql_try("INSERT INTO community.spaces (kind,owner_user_id,name,access_level,area) VALUES ('personal'," + str(UID['A']) + ",'x','admin-only','cafe');")
    check(bad2.returncode != 0, '① non-board area = cafe → CHECK rejects')
    privs = sql("SELECT has_table_privilege('tbox_app','community.reports','DELETE')::text || ',' ||"
                " has_table_privilege('tbox_app','community.anon_aliases','UPDATE')::text || ',' ||"
                " has_table_privilege('tbox_app','community.anon_aliases','DELETE')::text || ',' ||"
                " has_table_privilege('tbox_app','community.board_requests','DELETE')::text || ',' ||"
                " has_column_privilege('tbox_app','community.reports','reporter_id','UPDATE')::text || ',' ||"
                " has_column_privilege('tbox_app','community.reports','status','UPDATE')::text")
    codes['tbox_app_privs'] = privs
    check(privs == 'false,false,false,false,false,true',
          '① tbox_app: reports DELETE 0 · anon_aliases UPDATE 0 · DELETE 0 · board_requests DELETE 0 · reports.reporter_id UPDATE 0 · status UPDATE 1')
    lst = req('GET', '/api/boards?area=work', None, 'A').json()['boards']
    check(all(b['area'] == 'work' for b in lst) and free not in [b['id'] for b in lst] and qa in [b['id'] for b in lst],
          '① GET /api/boards?area=work excludes 자유게시판 (sidebar list)')
    lst = req('GET', '/api/boards?area=cafe', None, 'A').json()['boards']
    check({b['id'] for b in lst} >= {free, anon} and all(b['area'] == 'cafe' for b in lst)
          and [b['anonymous'] for b in lst if b['id'] == anon] == [True], '① GET /api/boards?area=cafe → 자유 · 익명(anonymous true)')
    check(len(req('GET', '/api/boards', None, 'A').json()['boards']) >= 4, '① GET /api/boards (no area) → all as before')

    # ════════ ② 카나리아 — 익명게시판 글 · 댓글 · 답글 · 도움됐어요 · 해결 ═════════════════════
    P = board_post(anon, '익명 시험 글 zebracafe', 'W')
    pid_P = pk(P)
    r = save_doc(P, doc_with_text('익명 본문 zebracafe 입니다'), 'W')
    check(r.status_code == 200, '② W saves body of anon post (PATCH) → 200')
    cA = comment(P, '첫 댓글 zebracafe A', 'A')
    cB = comment(P, '둘째 댓글 zebracafe B', 'B')
    cW = comment(P, '글쓴이 답글 zebracafe', 'W', parent=cA)
    cA2 = comment(P, 'A 의 답글 zebracafe', 'A', parent=cB)
    expect('POST', '/api/comments/' + cA + '/helpful', None, 'E', 200, '② E marks A comment helpful')
    expect('POST', '/api/comments/' + cA + '/resolve', None, 'A', 200, '② A resolves own thread')
    aliases = sql("SELECT string_agg(u.username || '=' || a.alias_no, ',' ORDER BY a.alias_no) FROM community.anon_aliases a JOIN users u ON u.id=a.user_id WHERE a.page_id=" + str(pid_P))
    codes['aliases'] = aliases.replace(CANARY_USER, 'W')
    check(aliases == CANARY_USER + '=0,claude_employee01=1,claude_manager01=2', '② alias rows: W=0 · A=1 (first commenter) · B=2')

    cur_ver = req('GET', '/api/pages/' + P, None, 'W').json()['page']['doc_version']
    surfaces = [
        ('pages_get', '/api/pages/' + P), ('board_list', '/api/boards/' + str(anon) + '/pages'),
        ('comments_list', '/api/pages/' + P + '/comments'), ('search', '/api/search?q=zebracafe'),
        ('history', '/api/pages/' + P + '/history'), ('versions_list', '/api/pages/' + P + '/versions'),
        ('version_get', '/api/pages/' + P + '/versions/' + str(cur_ver)),
        ('export_md', '/api/pages/' + P + '/export?format=md'), ('export_html', '/api/pages/' + P + '/export?format=html'),
        ('page_print', '/p/' + P + '/print'), ('cafe', '/api/cafe'),
    ]
    for who in ('E', 'M'):
        for label, path in surfaces:
            r = req('GET', path, None, who)
            ok = r.status_code == 200 and no_canary(r.text) and no_names(r.text)
            leaks = []
            if r.headers.get('Content-Type', '').startswith('application/json'):
                leaks = id_leaks(r.json(), WAB)
            check(ok and not leaks, '② ' + who + ' ' + label + ': 200 · canary id/name 0 · A/B id/name 0 · W/A/B user-id keys 0'
                  + ('' if not leaks else ' LEAK ' + ','.join(leaks[:3])) + ('' if r.status_code == 200 else ' (got ' + str(r.status_code) + ')'))
    for who in ('W', 'A', 'B'):
        r = notifications(who)
        check(r.status_code == 200 and no_canary(r.text) and no_names(r.text, skip=who) and not id_leaks(r.json(), WAB),
              '② notifications_list of ' + who + ': canary 0 · other W/A/B names 0 · ids 0')

    pg = req('GET', '/api/pages/' + P, None, 'E').json()
    check(pg['writer'] == {'username': None, 'display_name': '글쓴이', 'anon': True, 'is_me': False}
          and pg['page']['owner'] is None and pg['verify'] is None and pg['editors'] == [],
          '② pages_get (E): writer 「글쓴이」 · owner · verify null · editors []')
    pgw = req('GET', '/api/pages/' + P, None, 'W').json()
    check(pgw['writer']['display_name'] == '글쓴이' and pgw['writer']['is_me'] is True, '② pages_get (W): writer is_me true')
    by_id = {c['id']: c for c in req('GET', '/api/pages/' + P + '/comments', None, 'E').json()['comments']}
    labels = {k: (by_id[k]['author'] or {}).get('display_name') for k in (cA, cB, cW, cA2)}
    codes['comment_labels'] = labels
    check(labels == {cA: '익명1', cB: '익명2', cW: '글쓴이', cA2: '익명1'},
          '② comments_list (E): 익명1 · 익명2 · 글쓴이 · same person same number (A reply 익명1)')
    check(all(by_id[k]['author']['username'] is None and by_id[k]['author']['is_me'] is False for k in by_id),
          '② comments_list (E): username null · is_me false everywhere')
    w_view = {c['id']: c for c in req('GET', '/api/pages/' + P + '/comments', None, 'W').json()['comments']}
    a_view = {c['id']: c for c in req('GET', '/api/pages/' + P + '/comments', None, 'A').json()['comments']}
    check(w_view[cW]['author']['is_me'] is True and w_view[cA]['author']['is_me'] is False and a_view[cA]['author']['is_me'] is True,
          '② comments_list: own comment is_me true (W · A), others false')
    items = req('GET', '/api/boards/' + str(anon) + '/pages', None, 'E').json()['pages']
    row = [i for i in items if i['id'] == P]
    check(row and row[0]['writer'] == {'username': None, 'display_name': '글쓴이', 'anon': True, 'is_me': False},
          '② board list (= launcher feed source) writer 「글쓴이」')
    itw = [i for i in req('GET', '/api/boards/' + str(anon) + '/pages', None, 'W').json()['pages'] if i['id'] == P]
    check(itw and itw[0]['writer']['is_me'] is True, '② board list (W): is_me true')
    sr = req('GET', '/api/search?q=zebracafe', None, 'E').json()
    s_labels = sorted({c['author']['display_name'] for c in sr['comments'] if c['page']['id'] == P})
    codes['search_labels'] = s_labels
    check(s_labels and set(s_labels) <= {'글쓴이', '익명1', '익명2'} and all(c['author']['username'] is None for c in sr['comments'] if c['page']['id'] == P),
          '② search comment authors are labels only')
    na = notifications('A').json()['notifications']
    reply_to_a = [x for x in na if x['kind'] == 'reply' and x['page'] and x['page']['id'] == P]
    check(reply_to_a and reply_to_a[0]['actor'] == {'username': None, 'display_name': '글쓴이', 'anon': True, 'is_me': False},
          '② A reply notification from W → actor 「글쓴이」')
    nw = notifications('W').json()['notifications']
    mine = sorted({x['actor']['display_name'] for x in nw if x['kind'] == 'comment_on_my_page' and x['page'] and x['page']['id'] == P})
    check(mine == ['익명1', '익명2'], '② W comment_on_my_page notifications → actors 익명1 · 익명2 (got ' + str(mine) + ')')
    nb = notifications('B').json()['notifications']
    rb = [x for x in nb if x['kind'] == 'reply' and x['page'] and x['page']['id'] == P]
    check(rb and rb[0]['actor']['display_name'] == '익명1', '② B reply notification from A → 익명1')
    hist = req('GET', '/api/pages/' + P + '/history', None, 'E').json()
    check(hist['suggestions'] == [] and all(e.get('by') in ('글쓴이', '익명1', '익명2', '익명', '관리자') for e in hist['events']),
          '② history: by = labels · suggestions []')
    vl = req('GET', '/api/pages/' + P + '/versions', None, 'E').json()
    check(all(v['by'] == '글쓴이' for v in vl['versions']), '② versions_list: by 「글쓴이」')
    vg = req('GET', '/api/pages/' + P + '/versions/' + str(cur_ver), None, 'E').json()
    check(vg['version']['by'] == '글쓴이', '② version_get: by 「글쓴이」')
    md = req('GET', '/api/pages/' + P + '/export?format=md', None, 'E').text
    check('익명게시판' in md and no_canary(md), '② export md: owner 「익명게시판」')
    cafe = req('GET', '/api/cafe', None, 'E').json()
    rc = [i for i in cafe['recent'] if i['id'] == P]
    check(rc and rc[0]['writer']['display_name'] == '글쓴이' and rc[0]['board']['anonymous'] is True, '② cafe recent: writer 「글쓴이」')

    # ════════ ③ 끈 기능 ═════════════════════════════════════════════════════════════
    mypage = expect('POST', '/api/pages', {'title': '개인 글 게시 시험'}, 'W', 201, '③ W personal page').json()['page']['id']
    r = expect('POST', '/api/pages/' + mypage + '/publish', {'board_id': anon}, 'W', 403, '③ publish personal page to 익명게시판')
    codes['publish_anon_reason'] = r.json().get('reason')
    check(r.json().get('reason') == 'anon_board', '③ publish refusal reason anon_board')
    base_ver = req('GET', '/api/pages/' + P, None, 'E').json()['page']['doc_version']
    first_block = req('GET', '/api/pages/' + P, None, 'W').json()['page']['doc']['blocks'][0]
    expect('POST', '/api/pages/' + P + '/suggestions',
           {'base_version': base_ver, 'ops': [{'op': 'replace', 'block_id': first_block['id'],
                                               'block': dict(first_block, text=[{'t': '제안', 'm': {}}])}]},
           'E', 403, '③ suggestion on anon post (E)')
    check(req('GET', '/api/pages/' + P, None, 'E').json()['can_suggest'] is False, '③ pages_get can_suggest false')
    r = expect('PATCH', '/api/pages/' + P + '/properties', {'verify': 30}, 'W', 400, '③ verify by author on anon post')
    check(r.json().get('reason') == 'anon_board', '③ verify refusal reason anon_board')
    expect('PATCH', '/api/pages/' + P + '/properties', {'verify': 30}, 'E', 403, '③ verify by other employee')
    expect('GET', '/api/mention-candidates?page=' + P + '&q=', None, 'E', 403, '③ mention_candidates (empty q) on anon post')
    expect('GET', '/api/mention-candidates?page=' + P + '&q=claude', None, 'A', 403, '③ mention_candidates (q) on anon post')
    before = n("SELECT count(*) FROM community.notifications WHERE user_id=" + str(UID['E']) + " AND kind='mention'")
    comment(P, '@claude_employee02 보세요 zebracafe', 'A')
    after = n("SELECT count(*) FROM community.notifications WHERE user_id=" + str(UID['E']) + " AND kind='mention'")
    check(after == before, '③ `@E` in anon comment → mention notification 0')
    expect('PATCH', '/api/pages/' + P + '/properties', {'kind': 'procedure'}, 'W', 200, '③ W marks anon post as procedure (kind)')
    procs = req('GET', '/api/procedures', None, 'E').json()['pages']
    check(P not in [p['id'] for p in procs], '③ procedures list excludes anon post')
    procs_w = req('GET', '/api/procedures', None, 'W').json()['pages']
    check(P not in [p['id'] for p in procs_w], '③ procedures list excludes anon post (author view too)')
    creds = json.loads(Path(manifest['credentials_file']).read_text())
    main_file = creds['files']['main']['id']
    ref_block = {'id': str(uuid.uuid4()), 'type': 'file', 'file_ref': 'tbox:' + main_file}
    PA = board_post(anon, '익명 자료실 시험', 'A')
    r = save_doc(PA, doc_with_text('자료실 파일', [ref_block]), 'A')
    check(r.status_code == 400 and r.json().get('reason') == 'anon_board', '③ anon post body with 자료실 tbox: ref → 400 anon_board (got ' + str(r.status_code) + ')')
    PF = board_post(free, '자유 자료실 시험', 'A')
    r = save_doc(PF, doc_with_text('자료실 파일', [dict(ref_block, id=str(uuid.uuid4()))]), 'A')
    check(r.status_code == 200, '③ same body on 자유게시판 → 200 (got ' + str(r.status_code) + ')')

    # ════════ ④ 일반 카페 · 업무 · 개인 — 이름 그대로 · 키 그대로 ═══════════════════════════════
    F = board_post(free, '자유 실명 글', 'W')
    cF = comment(F, '자유 댓글', 'A')
    fp = req('GET', '/api/pages/' + F, None, 'E').json()
    check(fp['writer'] == {'username': CANARY_USER, 'display_name': CANARY_NAME}, '④ 자유게시판 pages_get writer = real name (no anon keys)')
    fc = [c for c in req('GET', '/api/pages/' + F + '/comments', None, 'E').json()['comments'] if c['id'] == cF]
    check(fc and fc[0]['author'] == {'username': 'claude_employee01', 'display_name': None}, '④ 자유게시판 comment author = real (keys username · display_name)')
    fl = [i for i in req('GET', '/api/boards/' + str(free) + '/pages', None, 'E').json()['pages'] if i['id'] == F]
    check(fl and set(fl[0]['writer']) == {'username', 'display_name'} and fl[0]['writer']['username'] == CANARY_USER, '④ 자유게시판 list writer keys unchanged')
    Q = board_post(qa, '업무 질문 글', 'A')
    qp = req('GET', '/api/pages/' + Q, None, 'E').json()
    check(set(qp) == set(pg) and set(qp['writer']) == {'username', 'display_name'}, '④ work board pages_get: same top-level keys as anon post · writer keys unchanged')
    personal = req('GET', '/api/pages/' + mypage, None, 'W').json()
    check(personal['page']['owner'] is not None and personal['page']['owner'].get('username') == CANARY_USER and personal['writer'] is None,
          '④ personal page: owner unchanged')
    codes['pages_get_keys'] = sorted(qp)

    # ════════ ⑤ 신고 ═════════════════════════════════════════════════════════════════
    r = expect('POST', '/api/reports', {'page_id': P, 'reason_kind': 'abuse', 'detail': '합성 신고'}, 'E', 201, '⑤ E reports W anon post')
    rep_E = r.json()['report']['id']
    expect('POST', '/api/reports', {'page_id': P, 'reason_kind': 'spam'}, 'E', 409, '⑤ same target again (open)')
    expect('POST', '/api/reports', {'page_id': P, 'reason_kind': 'abuse'}, 'W', 400, '⑤ W reports own post')
    expect('POST', '/api/reports', {'page_id': P, 'comment_id': cW, 'reason_kind': 'abuse'}, 'W', 400, '⑤ W reports own comment')
    expect('POST', '/api/reports', {'page_id': P, 'reason_kind': 'abuse'}, 'G', 403, '⑤ guest reports')
    priv = expect('POST', '/api/pages', {'title': 'A 비공개'}, 'A', 201, '⑤ A private page').json()['page']['id']
    expect('POST', '/api/reports', {'page_id': priv, 'reason_kind': 'abuse'}, 'E', 404, '⑤ unreadable target → 404')
    expect('POST', '/api/reports', {'page_id': str(uuid.uuid4()), 'reason_kind': 'abuse'}, 'E', 404, '⑤ unknown target → 404')
    r = expect('POST', '/api/reports', {'page_id': Q, 'reason_kind': 'abuse'}, 'E', 400, '⑤ work board post → 400')
    check(r.json().get('reason') == 'not_cafe', '⑤ work board refusal reason not_cafe')
    expect('POST', '/api/reports', {'page_id': P, 'reason_kind': 'nope'}, 'E', 400, '⑤ bad reason_kind → 400')
    rep_A = expect('POST', '/api/reports', {'page_id': P, 'reason_kind': 'privacy'}, 'A', 201, '⑤ A also reports P').json()['report']['id']
    rep_cB = expect('POST', '/api/reports', {'page_id': P, 'comment_id': cB, 'reason_kind': 'spam'}, 'E', 201, '⑤ E reports B comment').json()['report']['id']
    rep_cA = expect('POST', '/api/reports', {'page_id': P, 'comment_id': cA, 'reason_kind': 'other'}, 'B', 201, '⑤ B reports A comment').json()['report']['id']
    # 하루 상한 — E 의 24시간 신고를 20 으로 채운다(지난 처리된 합성 행 — 열린 신고 UNIQUE 밖)
    have = n("SELECT count(*) FROM community.reports WHERE reporter_id=" + str(UID['E']) + " AND created_at > now() - interval '24 hours'")
    sql("INSERT INTO community.reports (page_id, reporter_id, reason_kind, status, resolved_at) SELECT " + str(pid_P) + ", " + str(UID['E'])
        + ", 'other', 'rejected', now() FROM generate_series(1, " + str(20 - have) + ")")
    r = expect('POST', '/api/reports', {'page_id': F, 'reason_kind': 'abuse'}, 'E', 429, '⑤ 21st report in a day')
    check(n("SELECT count(*) FROM audit_logs WHERE action='report.create' AND result='deny' AND reason='rate_limited'") >= 1, '⑤ rate limit audited (deny)')
    sql("DELETE FROM community.reports WHERE reporter_id=" + str(UID['E']) + " AND status='rejected' AND resolved_by IS NULL")
    expect('GET', '/api/reports', None, 'E', 403, '⑤ reports_list by employee')
    reauth('M', False)
    r = expect('GET', '/api/reports', None, 'M', 403, '⑤ reports_list by admin without reauth')
    check(r.json().get('next') == 'reauth', '⑤ admin without reauth → next: reauth')
    reauth('M')
    rl = expect('GET', '/api/reports', None, 'M', 200, '⑤ reports_list by admin (reauth)')
    check(no_canary(rl.text) and not id_leaks(rl.json(), WAB), '② reports_list (M): canary 0 · ids 0')
    anon_targets = json.dumps([x['target'] for x in rl.json()['reports'] if x['target']['anonymous']], ensure_ascii=False)
    check(anon_targets != '[]' and no_names(anon_targets), '② reports_list (M): anon targets carry no A/B id/name (reporters are shown by design)')
    mine = [x for x in rl.json()['reports'] if x['id'] == rep_E]
    check(mine and mine[0]['target']['author']['display_name'] == '글쓴이' and mine[0]['reporter']['username'] == 'claude_employee02'
          and mine[0]['same_target_count'] == 2, '⑤ reports_list: target author label · reporter name · same-target count 2')
    cbrow = [x for x in rl.json()['reports'] if x['id'] == rep_cB]
    check(cbrow and cbrow[0]['target']['author']['display_name'] == '익명2' and cbrow[0]['target']['excerpt'].startswith('둘째 댓글'),
          '⑤ reports_list: comment target → 익명2 · excerpt')
    expect('POST', '/api/reports/' + rep_E + '/resolve', {'action': 'hide'}, 'M', 400, '⑤ resolve hide without note')
    expect('POST', '/api/reports/' + rep_E + '/resolve', {'action': 'hide', 'note': '   '}, 'M', 400, '⑤ resolve hide blank note')
    expect('POST', '/api/reports/' + rep_E + '/resolve', {'action': 'hide', 'note': 'x'}, 'E', 403, '⑤ resolve by employee')
    r = expect('POST', '/api/reports/' + rep_E + '/resolve', {'action': 'hide', 'note': '욕설로 숨겼어요'}, 'M', 200, '⑤ resolve hide')
    check(r.json().get('closed') == 2, '⑤ hide closes every open report on the same target (2)')
    check(sql('SELECT hidden_at IS NOT NULL FROM community.pages WHERE id=' + str(pid_P)) == 't', '⑤ post hidden')
    check(sql("SELECT string_agg(status, ',' ORDER BY id) FROM community.reports WHERE page_id=" + str(pid_P) + " AND comment_id IS NULL") == 'hidden,hidden',
          '⑤ both open reports on P → hidden')
    expect('POST', '/api/reports/' + rep_E + '/resolve', {'action': 'reject', 'note': 'x'}, 'M', 409, '⑤ resolve again → 409')
    mod = [x for x in notifications('W').json()['notifications'] if x['kind'] == 'moderated']
    check(mod and mod[0]['actor'] == {'username': None, 'display_name': '관리자', 'anon': True, 'is_me': False}
          and mod[0].get('moderation_note') == '욕설로 숨겼어요', '⑤ W gets moderated notification · actor 「관리자」 · note from report')
    check(n("SELECT count(*) FROM community.notifications WHERE kind='moderated' AND actor_id IS NOT NULL") == 0, '⑤ moderated notification actor_id NULL in DB')
    r = expect('POST', '/api/reports/' + rep_cB + '/resolve', {'action': 'reject', 'note': '문제 없어요'}, 'M', 200, '⑤ resolve reject (B comment)')
    check(sql("SELECT status FROM community.reports WHERE public_id=" + lit(rep_cB)) == 'rejected'
          and sql("SELECT status FROM community.reports WHERE public_id=" + lit(rep_cA)) == 'open', '⑤ reject changes only that report')
    check(sql("SELECT hidden_at IS NULL FROM community.comments WHERE public_id=" + lit(cB)) == 't', '⑤ reject leaves comment visible')
    check(n("SELECT count(*) FROM audit_logs WHERE action='report.create' AND result='allow'") >= 4
          and n("SELECT count(*) FROM audit_logs WHERE action='report.resolve' AND result='allow'") == 2
          and n("SELECT count(*) FROM audit_logs WHERE action='page.hide' AND result='allow' AND detail->>'page_title' LIKE '익명 시험 글%'") == 1,
          '⑤ audit rows: report.create · report.resolve ×2 · page.hide (same hide audit as the hide button)')
    # 지금 숨김 단추 창구는 입력 · 응답 그대로
    hb = expect('DELETE', '/api/pages/' + P + '/hide', None, 'M', 200, '⑤ existing unhide button (pages_hide DELETE) still works')
    check(set(hb.json()) == {'page', 'changed', 'granted_by'} and hb.json()['changed'] is True, '⑤ pages_hide response shape unchanged')
    r = expect('POST', '/api/reports/' + rep_cA + '/resolve', {'action': 'hide', 'note': '댓글 숨김'}, 'M', 200, '⑤ resolve hide on comment')
    check(sql("SELECT hidden_at IS NOT NULL FROM community.comments WHERE public_id=" + lit(cA)) == 't', '⑤ comment hidden via report')
    modA = [x for x in notifications('A').json()['notifications'] if x['kind'] == 'moderated']
    check(modA and modA[0].get('moderation_note') == '댓글 숨김' and modA[0]['actor']['display_name'] == '관리자', '⑤ A (comment writer) gets moderated notification')
    hb = expect('POST', '/api/pages/' + P + '/hide', None, 'M', 200, '⑤ existing hide button (pages_hide POST)')
    check(set(hb.json()) == {'page', 'changed', 'granted_by'}, '⑤ pages_hide POST response shape unchanged')
    ch = expect('DELETE', '/api/comments/' + cA + '/hide', None, 'M', 200, '⑤ existing comment unhide button')
    check(set(ch.json()) == {'comment', 'changed', 'granted_by'} and ch.json()['changed'] is True, '⑤ comment_hide response shape unchanged')

    # ════════ ⑥ 작성자 확인 ═══════════════════════════════════════════════════════════
    notif_before = n("SELECT count(*) FROM community.notifications WHERE user_id IN (" + str(UID['W']) + "," + str(UID['E']) + ")")
    reauth('M')
    r = expect('POST', '/api/reports/' + rep_E + '/reveal', {'reason': '명예훼손 신고 처리를 위해 확인합니다 이십자'}, 'M', 200, '⑥ reveal (admin · reauth · reason ≥20)')
    check(r.json() == {'author': {'username': CANARY_USER, 'display_name': CANARY_NAME}}, '⑥ reveal response = real author only')
    check('no-store' in r.headers.get('Cache-Control', ''), '⑥ reveal response Cache-Control no-store')
    r2 = expect('POST', '/api/reports/' + rep_cB + '/reveal', {'reason': '댓글 신고 처리를 위한 확인 사유입니다 스무자'}, 'M', 200, '⑥ reveal comment report')
    check(r2.json()['author']['username'] == 'claude_manager01', '⑥ comment reveal → comment writer (B)')
    reason19 = '가' * 19
    expect('POST', '/api/reports/' + rep_E + '/reveal', {'reason': reason19}, 'M', 400, '⑥ reason 19 chars')
    expect('POST', '/api/reports/' + rep_E + '/reveal', {}, 'M', 400, '⑥ no reason')
    reauth('M', False)
    r = expect('POST', '/api/reports/' + rep_E + '/reveal', {'reason': '재인증 없이 확인을 시도해 봅니다 이십자이상'}, 'M', 403, '⑥ reveal without reauth')
    check(no_canary(r.text), '⑥ reveal denial body has no name')
    reauth('M')
    rep_F = expect('POST', '/api/reports', {'page_id': F, 'reason_kind': 'abuse'}, 'A', 201, '⑥ A reports W 자유게시판 post').json()['report']['id']
    r = expect('POST', '/api/reports/' + rep_F + '/reveal', {'reason': '자유게시판 글 확인 시도입니다 이십자이상'}, 'M', 400, '⑥ reveal on 자유게시판 target')
    check(r.json().get('reason') == 'not_anon_board' and no_canary(r.text), '⑥ 자유게시판 reveal refusal reason not_anon_board')
    expect('POST', '/api/reports/' + rep_E + '/reveal', {'reason': '직원이 확인을 시도합니다 이십자이상입니다'}, 'E', 403, '⑥ reveal by employee')
    expect('POST', '/api/pages/' + P + '/reveal', {'reason': '신고 없는 글 확인 이십자 이상 입니다'}, 'M', 404, '⑥ no reveal endpoint by page id (report id only)')
    check(n("SELECT count(*) FROM audit_logs WHERE action='anon.reveal' AND result='allow'") == 2
          and n("SELECT count(*) FROM audit_logs WHERE action='anon.reveal' AND result='deny' AND reason IN ('bad_reason','not_anon_board')") >= 3,
          '⑥ audit anon.reveal allow ×2 · deny (bad_reason · not_anon_board)')
    check(sql("SELECT detail->>'reason' FROM audit_logs WHERE action='anon.reveal' AND result='allow' ORDER BY id LIMIT 1").startswith('명예훼손'),
          '⑥ reveal reason recorded in audit detail')
    check(n("SELECT count(*) FROM community.notifications WHERE user_id IN (" + str(UID['W']) + "," + str(UID['E']) + ")") == notif_before,
          '⑥ reveal sends no notification to writer · reporter')
    check(n("SELECT count(*) FROM information_schema.columns WHERE table_schema='community' AND table_name='reports' AND column_name LIKE '%reveal%'") == 0,
          '⑥ reveal result not stored (no reveal column)')
    r = req('GET', '/api/pages/' + P + '/comments', None, 'M')
    check(r.status_code == 200 and no_canary(r.text) and no_names(r.text), '⑥ after reveal: comments_list (M) still canary 0 · A/B names 0')

    # ════════ ⑦ 감사 화면 ═════════════════════════════════════════════════════════════
    reauth('M')
    r, rows = audit_rows({'target': '익명 시험 글'})
    check(r.status_code == 200 and rows, '⑦ audit list (M · reauth) target filter → rows')
    anon_rows = [x for x in rows if (x.get('detail') or {}).get('anon_board') is True and x['action'] not in ('anon.reveal',) and not x['action'].startswith('report.')]
    codes['audit_anon_actions'] = sorted({x['action'] for x in anon_rows})
    check(anon_rows and all(x['actor_name'] == '익명(익명게시판)' and x['ip'] is None and 'login_ref' not in (x['detail'] or {})
                            and x['user_agent'] is None and CANARY_USER not in x['summary'] for x in anon_rows),
          '⑦ anon rows: actor 「익명(익명게시판)」 · ip null · login_ref 0 · summary actor masked')
    check(no_canary(json.dumps(rows, ensure_ascii=False)), '⑦ target-filtered audit list: canary 0')
    check(no_names(json.dumps(anon_rows, ensure_ascii=False)), '⑦ anon-marked audit rows: A/B id/name 0')
    for action in ('page.create', 'page.update', 'comment.create', 'comment.resolve'):
        check(any(x['action'] == action for x in anon_rows), '⑦ marker present on ' + action)
    rep_rows = [x for x in rows if x['action'] in ('report.resolve', 'anon.reveal')]
    check(rep_rows and all(x['actor_name'] == 'claude_admin01' for x in rep_rows), '⑦ report.resolve · anon.reveal rows keep admin name')
    r, wrows = audit_rows({'actor': CANARY_USER})
    check(r.status_code == 200 and not [x for x in wrows if '익명 시험 글' in (x.get('target_name') or '') or (x.get('detail') or {}).get('anon_board')],
          '⑦ actor=<W> filter → anon rows 0 (got ' + str(len(wrows)) + ' non-anon rows)')
    r, irows = audit_rows({'ip': '127.0.0.1'})
    check(r.status_code == 200 and not [x for x in irows if (x.get('detail') or {}).get('anon_board') and not x['action'].startswith('report.') and x['action'] != 'anon.reveal'],
          '⑦ ip= filter → anon rows 0')
    r, arows = audit_rows({'actor': 'claude_employee01'})
    check(not [x for x in arows if (x.get('detail') or {}).get('anon_board') and not x['action'].startswith('report.')], '⑦ actor=<A> filter → anon comment rows 0')

    # ════════ ⑧ 게시판 요청 ═══════════════════════════════════════════════════════════
    req_ids = []
    for i in range(3):
        r = expect('POST', '/api/board-requests', {'name': '독서 모임 ' + str(i), 'purpose': '책 이야기\n함께 해요', 'anonymous': i == 0}, 'A', 201, '⑧ request #' + str(i + 1))
        req_ids.append(r.json()['request']['id'])
    expect('POST', '/api/board-requests', {'name': '네 번째', 'purpose': '넘침'}, 'A', 429, '⑧ 4th open request')
    expect('POST', '/api/board-requests', {'name': '손님', 'purpose': 'x'}, 'G', 403, '⑧ guest request')
    check(n("SELECT count(*) FROM community.notifications WHERE user_id=" + str(UID['M']) + " AND kind='board_request'") == 3, '⑧ admin notified ×3')
    nm = [x for x in notifications('M').json()['notifications'] if x['kind'] == 'board_request']
    check(nm and nm[0]['actor']['username'] == 'claude_employee01', '⑧ admin notification actor = requester')
    expect('GET', '/api/board-requests', None, 'A', 403, '⑧ request list by employee')
    reauth('M')
    lst = expect('GET', '/api/board-requests', None, 'M', 200, '⑧ request list by admin').json()['requests']
    check(len(lst) == 3, '⑧ admin sees 3 open requests')
    r = expect('POST', '/api/board-requests/' + req_ids[0] + '/decide', {'action': 'create', 'note': '만들었어요'}, 'M', 200, '⑧ decide create')
    nb_id = r.json()['request']['board_id']
    check(sql('SELECT area || \':\' || board_kind || \':\' || access_level FROM community.spaces WHERE id=' + str(nb_id)) == 'cafe:anonymous:internal',
          '⑧ created board area cafe · anonymous (requested) · internal')
    check(n("SELECT count(*) FROM community.notifications WHERE user_id=" + str(UID['A']) + " AND kind='board_request_done'") == 1, '⑧ requester notified')
    expect('POST', '/api/board-requests/' + req_ids[1] + '/decide', {'action': 'reject'}, 'M', 400, '⑧ reject without note')
    expect('POST', '/api/board-requests/' + req_ids[1] + '/decide', {'action': 'reject', 'note': '비슷한 게시판이 있어요'}, 'M', 200, '⑧ reject with note')
    check(sql("SELECT status || ':' || decision_note FROM community.board_requests WHERE public_id=" + lit(req_ids[1])) == 'rejected:비슷한 게시판이 있어요', '⑧ reject note stored')
    expect('POST', '/api/board-requests/' + req_ids[0] + '/decide', {'action': 'reject', 'note': 'x'}, 'M', 409, '⑧ decide again → 409')
    check(n("SELECT count(*) FROM audit_logs WHERE action IN ('board_request.create','board_request.decide') AND result='allow'") == 5
          and n("SELECT count(*) FROM audit_logs WHERE action='board.create' AND result='allow' AND detail->>'area'='cafe'") >= 1,
          '⑧ audit board_request.create ×3 · decide ×2 · board.create (shared path)')
    expect('POST', '/api/boards', {'name': '업무익명', 'access_level': 'internal', 'board_kind': 'anonymous'}, 'M', 400, '⑧ boards_create anonymous without cafe → 400')
    expect('POST', '/api/boards', {'name': '이름중복', 'access_level': 'internal', 'board_kind': 'discussion', 'area': 'cafe'}, 'M', 201, '⑧ boards_create area cafe')
    expect('POST', '/api/boards', {'name': '이름중복', 'access_level': 'internal', 'board_kind': 'discussion'}, 'M', 409, '⑧ boards_create duplicate name → 409 (shared path)')

    # ════════ ⑨ 카페 홈 ═════════════════════════════════════════════════════════════
    tb = expect('POST', '/api/boards', {'name': 'B팀 카페', 'access_level': 'team', 'team_id': team_b, 'board_kind': 'discussion', 'area': 'cafe'},
                'M', 201, '⑨ team-level cafe board for team B').json()['board']['id']
    names_w = [b['name'] for b in req('GET', '/api/cafe', None, 'W').json()['boards']]
    names_e = [b['name'] for b in req('GET', '/api/cafe', None, 'E').json()['boards']]
    check('B팀 카페' not in names_w and 'B팀 카페' in names_e, '⑨ team board: other team 0 · own team shown')
    check('질문과 답변' not in names_w and '자유게시판' in names_w and '익명게시판' in names_w, '⑨ cafe boards only (work boards 0)')
    TB = board_post(tb, 'B팀 비밀 글', 'E')
    rec_w = req('GET', '/api/cafe', None, 'W').json()
    check(TB not in [i['id'] for i in rec_w['recent']] and TB not in [i['id'] for i in rec_w['popular']], '⑨ unreadable team board post not in recent · popular')
    check(P not in [i['id'] for i in rec_w['recent']], '⑨ hidden post not in recent')
    D = board_post(free, '지울 글', 'W')
    expect('DELETE', '/api/pages/' + D, None, 'W', 200, '⑨ delete a free post')
    check(D not in [i['id'] for i in req('GET', '/api/cafe', None, 'W').json()['recent']], '⑨ deleted post not in recent')
    small = count_cafe_queries(UID['A'])
    for i in range(100):
        req('POST', '/api/boards/' + str(free) + '/pages', {'title': '부하 글 ' + str(i)}, 'B')
    big = count_cafe_queries(UID['A'])
    codes['cafe_queries'] = {'before': small, 'after_100_posts': big}
    # 질의 셋(게시판 · 최근 · 인기) + t-box 권한 표 읽기 하나(Authz::decideRead 의 permissions — 요청마다 한 번) = 4, 행 수와 무관
    check(small.get('queries', -1) == big.get('queries', -2) and 0 < big.get('queries', 0) <= 4 and big.get('recent') == 10,
          '⑨ /api/cafe query count fixed (' + str(small.get('queries')) + ' = ' + str(big.get('queries')) + ' · recent rows '
          + str(small.get('recent')) + ' → ' + str(big.get('recent')) + ')')

    # ════════ ⑩ 그림 메타데이터 ═════════════════════════════════════════════════════════
    gps = jpeg_with_gps()
    PI = board_post(anon, '익명 그림 글', 'W')
    r = req('POST', '/api/pages/' + PI + '/attachments', None, 'W', files={'file': ('사진.jpg', gps, 'application/octet-stream')})
    check(r.status_code == 201, '⑩ anon post JPEG with GPS EXIF upload → 201 (got ' + str(r.status_code) + ')')
    f = stored_file(r.json()['id']) if r.status_code == 201 else None
    data = f.read_bytes() if f and f.is_file() else b''
    check(data[:2] == b'\xff\xd8' and b'Exif' not in data and b'GPSCANARY' not in data, '⑩ stored anon file: EXIF 0 · GPS text 0')
    check(r.status_code == 201 and r.json()['size'] == len(data), '⑩ stored size = stripped bytes')
    FI = board_post(free, '자유 그림 글', 'W')
    r = req('POST', '/api/pages/' + FI + '/attachments', None, 'W', files={'file': ('사진.jpg', gps, 'application/octet-stream')})
    f2 = stored_file(r.json()['id']) if r.status_code == 201 else None
    data2 = f2.read_bytes() if f2 and f2.is_file() else b''
    check(r.status_code == 201 and data2 == gps, '⑩ 자유게시판 upload stored as before (bytes unchanged)')
    check(n("SELECT count(*) FROM audit_logs WHERE action='attachment.upload' AND result='allow' AND detail->>'anon_board'='true'") == 1,
          '⑦ marker present on attachment.upload (anon only)')

    # ════════ C1b ① AI 넣기 감사 표식 — AI 열쇠 없이 합성 ai_log 행 ═══════════════════════════
    log_id = sql("INSERT INTO community.ai_log (user_id, feature, output_text, model, started_at, status, chars_in, chars_out)"
                 " VALUES (" + str(UID['W']) + ", 'summary', '합성 요약 zebracafe', 'synthetic', now(), 'ok', 0, 9) RETURNING public_id").splitlines()[0]
    ver = req('GET', '/api/pages/' + PI, None, 'W').json()['page']['doc_version']
    r = expect('POST', '/api/pages/' + PI + '/ai-insert', {'log_id': log_id, 'doc_version': ver}, 'W', 200, 'C1b① ai-insert on anon post (synthetic ai_log)')
    ai_marker = sql("SELECT coalesce(detail->>'anon_board','') FROM audit_logs WHERE action='ai.insert' AND result='allow' AND detail->>'ai_log_id'=" + lit(log_id))
    codes['ai_insert_anon_board'] = ai_marker
    check(ai_marker == 'true', 'C1b① ai.insert audit detail.anon_board = true')
    reauth('M')
    r, wrows2 = audit_rows({'actor': CANARY_USER})
    check(r.status_code == 200 and not [x for x in wrows2 if x['action'] == 'ai.insert'], 'C1b① actor=<W> audit filter → ai.insert row 0')
    r = http('GET', site + '/tbox/api/audit?page=1', None, 'M')   # 'ai.insert' 는 「어떻게」 필터 목록 밖 — 최신 첫 쪽에서 찾는다
    ai_rows = [x for x in (r.json().get('rows', []) if r.status_code == 200 else []) if x['action'] == 'ai.insert']
    check(r.status_code == 200 and ai_rows and all(x['actor_name'] == '익명(익명게시판)' and x['ip'] is None for x in ai_rows),
          'C1b① ai.insert row on audit screen masked (익명(익명게시판) · ip null)')

    # ════════ 감사 표식을 단 커뮤니티 동작(합성 DB 실측) ════════════════════════════════════
    codes['anon_marked_actions'] = sql("SELECT string_agg(DISTINCT action, ',' ORDER BY action) FROM audit_logs WHERE detail->>'anon_board'='true'").split(',')
finally:
    (out / 'cafe-c1-results.json').write_text(json.dumps({'checks': checks, 'confirmed_values': codes}, ensure_ascii=False, indent=2))
    print('RESULT pass=' + str(sum(x['pass'] for x in checks)) + ' fail=' + str(sum(not x['pass'] for x in checks)), flush=True)
if any(not x['pass'] for x in checks):
    raise SystemExit(1)
