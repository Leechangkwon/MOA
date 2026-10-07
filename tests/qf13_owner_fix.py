#!/usr/bin/env python3
"""사이트 Q:F-13 재지시 P2-R3 — 소유자 바로잡기 마이그레이션(db/migrations/2026-10-22_owner_fix.sql) 스크래치 DB 시험.

근거: SECURITY R-120 · ARCHITECTURE 10장 끝 「마이그레이션 적용 계정」 · 지시서 docs/qf13-privacy-directive.md 「P2-R3」.

틀: 같은 PostgreSQL 클러스터에 스크래치 DB 하나(`sudo -n -u postgres createdb qf13_owner_fix_<pid>_<무작위>`)를 만들고 끝나면 지운다
(실패해도). 운영 DB(tbox_re)에는 붙지도 읽지도 않는다 — 모든 psql 호출은 이 스크래치 이름만 `-d` 로 받고(이름 모양 검사),
흘려 넣는 SQL 에 `\\c`·`\\connect`·`DATABASE tbox_re` 가 있으면 시작하지 않는다. 앱 서버·포트·브라우저 없음.

운영 모양 흉내(지금 운영 실측 2026-10-07)
  · 새 설치 스키마(db/schema.sql · db/community/schema.sql · memories_harness.py 와 같은 마이그레이션 목록)를 `SET ROLE tbox_owner` 로
    만든다 — 목록 밖 표의 소유자는 운영처럼 tbox_owner.
  · 목록의 표 15 · 함수 1 을 postgres 소유로 넘긴다(표에 딸린 BIGSERIAL 시퀀스 4 는 함께 넘어간다 — 운영과 같은 연결).
  · 권한은 운영과 같은 모양으로 postgres 가 다시 건다: tbox_app = arwd(여행 둘 · 팔로우 · 선호 · folders · mcp_* 다섯) ·
    arw(usage_guides · share_links · fetch_hosts · api_tokens) · r(usage_guide_versions) · 시퀀스 rU / tbox_backup = r(표 · 시퀀스) /
    함수는 PUBLIC 실행 회수(소유자만).
  · 목록 밖 postgres 소유 객체(카나리아): public.qf13_canary(+ 딸린 시퀀스) · 따로 선 시퀀스 public.qf13_canary_standalone_seq ·
    다른 스키마의 같은 이름 표 community.fetch_hosts · 인자가 다른 같은 이름 함수 public.mcp_bump_security_epoch(integer).
  · 몇 줄의 행(사용자 둘 · 팔로우 · 선호 · 안내 · 허용 사이트 · 폴더 · MCP 손님 · 카나리아) — 행 지문 비교용.

단언
  0  빈 DB 에 적용 → 오류 0 · 「바꾼 것 0」(목록 객체 · community 스키마가 없어도)
  사전  흉내가 운영 모양인가: 목록 20 소유자 postgres · 앱/백업 권한 모양 · tbox_owner DELETE 거짓(R-120 재현) ·
        tbox_owner 로 DELETE … WHERE false → 42501 · privacy_purge_expired(dry) → 42501
  가드  `SET ROLE tbox_owner` 로 적용 → 거부(슈퍼유저 안내) · 카탈로그·행 변화 0
  ①  1회 적용 뒤 목록 객체(표 15 · 시퀀스 4 · 함수 1) 소유자 = tbox_owner · 딸린 인덱스도 tbox_owner
  ②  tbox_app · tbox_backup 권한(권한 목록에서 소유자 항목을 뺀 나머지 — 받는 사람 · 권한 · 넘김 가능)이 전과 같음 + 기대 모양 그대로 ·
     함수 PUBLIC/tbox_app 실행 0 그대로
  ③  has_table_privilege('tbox_owner', 표, 'DELETE') = 참(15) · UPDATE 참 · 시퀀스 USAGE 참 · 실제 DELETE … WHERE false 통과 ·
     privacy_purge_expired(dry · 실제 둘 다) 통과
  ④  목록 밖 postgres 소유 객체(카나리아 다섯)는 소유자 · 권한 그대로 · 카탈로그 전체 비교에서 바뀐 것은 목록 객체와
     그 딸림(인덱스 · TOAST · 행 타입)뿐 · 스키마 · DB · 기본 권한 · 열 권한 · 트리거 그대로 · 행 지문 그대로
  ⑤  2회 적용 → 「바꾼 것 0」 · 카탈로그 · 행 지문이 1회 뒤와 같음
  덤  사용자 갱신 트리거(mcp_bump_security_epoch)가 앱 계정의 UPDATE 에서 소유자 변경 전후 같게 돈다(되돌리는 트랜잭션 안 ·
      그 트랜잭션에서만 tbox_app 에 users 갱신 권한을 잠깐 줌 — 스크래치 DB 한정, ROLLBACK)
  B  갈래: 표에 딸리지 않은 postgres 시퀀스는 시퀀스 칸이 바꾼다 · postgres 가 아닌 소유자(tbox_perm)의 목록 표는 그대로 →
     되돌린 뒤 카탈로그가 ⑤ 와 같음

쓰는 법: python3 tests/qf13_owner_fix.py [--output 결과폴더]
나가는 값: 0 통과 · 1 실패 · 3 준비물 없음(sudo -n 으로 postgres 를 쓸 수 없음)
결과 파일: owner-fix-results.json(단언 이름 · 참/거짓) · migration-run*.log(psql 출력 원문) · acl-before-after.txt(목록 20 의 권한 목록 원문)
— 스크래치 DB 의 합성 값뿐이다(비밀 0).
"""
import argparse
import json
import os
import re
import secrets
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MIGRATION = ROOT / 'db/migrations/2026-10-22_owner_fix.sql'

TABLES = ['community.memory_follows', 'community.memory_preferences', 'community.travel_plan_records',
          'community.travel_plans', 'community.usage_guide_versions', 'community.usage_guides',
          'public.api_tokens', 'public.fetch_hosts', 'public.folders', 'public.mcp_clients', 'public.mcp_codes',
          'public.mcp_grants', 'public.mcp_pending', 'public.mcp_tokens', 'public.share_links']
SEQUENCES = ['public.api_tokens_id_seq', 'public.fetch_hosts_id_seq', 'public.folders_id_seq', 'public.share_links_id_seq']
FUNCTION = 'public.mcp_bump_security_epoch()'
LISTED_REL = TABLES + SEQUENCES
PURGE_TARGETS = ['community.travel_plans', 'community.travel_plan_records', 'community.memory_follows',
                 'community.memory_preferences', 'public.api_tokens']

ARWD = ['community.memory_follows', 'community.memory_preferences', 'community.travel_plans', 'community.travel_plan_records',
        'public.folders', 'public.mcp_clients', 'public.mcp_pending', 'public.mcp_grants', 'public.mcp_codes', 'public.mcp_tokens']
ARW = ['community.usage_guides', 'public.share_links', 'public.fetch_hosts', 'public.api_tokens']
R_ONLY = ['community.usage_guide_versions']
assert sorted(ARWD + ARW + R_ONLY) == sorted(TABLES)
APP_PRIVS = {}
for t in ARWD:
    APP_PRIVS[t] = ['DELETE', 'INSERT', 'SELECT', 'UPDATE']
for t in ARW:
    APP_PRIVS[t] = ['INSERT', 'SELECT', 'UPDATE']
for t in R_ONLY:
    APP_PRIVS[t] = ['SELECT']
for s in SEQUENCES:
    APP_PRIVS[s] = ['SELECT', 'USAGE']

CANARY_REL = ['public.qf13_canary', 'public.qf13_canary_id_seq', 'public.qf13_canary_standalone_seq', 'community.fetch_hosts']
CANARY_FN = ['public.mcp_bump_security_epoch(integer)']

# memories_harness.py 의 마이그레이션 목록(새 설치 모양)과 같다 — 이번 파일은 시험 대상이라 따로 적용한다.
BASE_MIGRATIONS = ['2026-09-29_community_trust.sql', '2026-09-30_community_automation.sql',
                   '2026-10-01_community_suggestions.sql', '2026-10-18_community_ai.sql',
                   '2026-10-03_community_memories.sql', '2026-10-03_community_messenger.sql',
                   '2026-10-03_memory_story.sql', '2026-10-14_memory_social.sql',
                   '2026-10-14_optional_member_auth.sql', '2026-10-14_usage_guide.sql',
                   '2026-10-14_travel_plans.sql', '2026-10-14_travel_scopes.sql',
                   '2026-10-15_remote_mcp.sql', '2026-10-19_qf12_work.sql',
                   '2026-10-21_privacy_retention.sql']

DB = 'qf13_owner_fix_{}_{}'.format(os.getpid(), secrets.token_hex(4))
DB_RE = re.compile(r'^qf13_owner_fix_[0-9]+_[0-9a-f]{8}$')
SEP = '\x1f'
RESULTS = []


def check(cond, name):
    RESULTS.append({'name': name, 'ok': bool(cond)})
    print(('  ✔ ' if cond else '  ✖ ') + name, flush=True)
    return bool(cond)


def section(title):
    print('\n── ' + title, flush=True)


def guard_sql(text):
    if re.search(r'^\s*\\(c|connect)\b', text, re.M) or re.search(r'DATABASE\s+"?tbox_re"?\b', text, re.I):
        raise SystemExit('SQL guard: connection switch or production database reference refused')


def run_psql(text, *, args=(), check_rc=True):
    if not DB_RE.match(DB) or DB == 'tbox_re':
        raise SystemExit('Scratch DB name guard failed')
    guard_sql(text)
    cmd = ['sudo', '-n', '-u', 'postgres', 'psql', '-X', '-v', 'ON_ERROR_STOP=1', '-d', DB] + list(args) + ['-f', '-']
    r = subprocess.run(cmd, input=text.encode(), capture_output=True, cwd='/')
    if check_rc and r.returncode != 0:
        raise RuntimeError('psql failed rc={}: {}'.format(r.returncode, r.stderr.decode(errors='replace')[-2000:]))
    return r


def rows(sql):
    r = run_psql(sql, args=('-q', '-A', '-t', '-F', SEP))
    out = r.stdout.decode()
    return [line.split(SEP) for line in out.splitlines() if line != '']


def scalar(sql):
    res = rows(sql)
    return res[0][0] if res else None


def arr(items):
    return 'ARRAY[' + ','.join("'" + i.replace("'", "''") + "'" for i in items) + ']::text[]'


def notices(stderr):
    return [m.group(1) for m in re.finditer(r'NOTICE:\s+(.*)', stderr)]


# ─── 카탈로그 · 행 지문 ───────────────────────────────────────────
REL_SQL = r"""
SELECT n.nspname||'.'||c.relname, c.relkind, pg_get_userbyid(c.relowner), coalesce(c.relacl::text,'-'),
       coalesce((SELECT pn.nspname||'.'||pc.relname FROM pg_index i JOIN pg_class pc ON pc.oid=i.indrelid
                   JOIN pg_namespace pn ON pn.oid=pc.relnamespace WHERE i.indexrelid=c.oid),
                (SELECT pn.nspname||'.'||pc.relname FROM pg_depend d JOIN pg_class pc ON pc.oid=d.refobjid
                   JOIN pg_namespace pn ON pn.oid=pc.relnamespace
                  WHERE c.relkind='S' AND d.classid='pg_class'::regclass AND d.objid=c.oid
                    AND d.refclassid='pg_class'::regclass AND d.deptype IN ('a','i') LIMIT 1),
                (SELECT pn.nspname||'.'||pc.relname FROM pg_class pc JOIN pg_namespace pn ON pn.oid=pc.relnamespace
                  WHERE c.relkind='t' AND pc.reltoastrelid=c.oid),
                '')
  FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
 WHERE n.nspname NOT IN ('pg_catalog','information_schema') AND n.nspname !~ '^pg_temp_' AND n.nspname !~ '^pg_toast_temp_'
 ORDER BY 1
"""
FN_SQL = r"""
SELECT n.nspname||'.'||p.proname||'('||pg_get_function_identity_arguments(p.oid)||')', pg_get_userbyid(p.proowner),
       coalesce(p.proacl::text,'-'), p.prosecdef::text
  FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
 WHERE n.nspname NOT IN ('pg_catalog','information_schema') ORDER BY 1
"""
TYPE_SQL = r"""
SELECT n.nspname||'.'||t.typname, pg_get_userbyid(t.typowner), coalesce(t.typacl::text,'-'),
       coalesce((SELECT rn.nspname||'.'||rc.relname FROM pg_class rc JOIN pg_namespace rn ON rn.oid=rc.relnamespace
                  WHERE rc.oid=t.typrelid),
                (SELECT rn.nspname||'.'||rc.relname FROM pg_type et JOIN pg_class rc ON rc.oid=et.typrelid
                   JOIN pg_namespace rn ON rn.oid=rc.relnamespace WHERE t.typelem<>0 AND et.oid=t.typelem),
                '')
  FROM pg_type t JOIN pg_namespace n ON n.oid=t.typnamespace
 WHERE n.nspname NOT IN ('pg_catalog','information_schema') AND n.nspname !~ '^pg_temp_' AND n.nspname !~ '^pg_toast_temp_'
 ORDER BY 1
"""
MISC_SQL = r"""
SELECT 'col', n.nspname||'.'||c.relname||'.'||a.attname, a.attacl::text
  FROM pg_attribute a JOIN pg_class c ON c.oid=a.attrelid JOIN pg_namespace n ON n.oid=c.relnamespace
 WHERE a.attacl IS NOT NULL AND n.nspname NOT IN ('pg_catalog','information_schema')
UNION ALL
SELECT 'nsp', nspname, pg_get_userbyid(nspowner)||' '||coalesce(nspacl::text,'-')
  FROM pg_namespace WHERE nspname !~ '^pg_' AND nspname <> 'information_schema'
UNION ALL
SELECT 'db', datname, pg_get_userbyid(datdba)||' '||coalesce(datacl::text,'-') FROM pg_database WHERE datname=current_database()
UNION ALL
SELECT 'defacl', pg_get_userbyid(defaclrole)||'/'||defaclnamespace::text||'/'||defaclobjtype::text, defaclacl::text FROM pg_default_acl
UNION ALL
SELECT 'trg', tg.tgrelid::regclass::text||'.'||tg.tgname, tg.tgfoid::regprocedure::text||' '||tg.tgenabled::text
  FROM pg_trigger tg WHERE NOT tg.tgisinternal
ORDER BY 1, 2
"""


def snapshot():
    snap = {}
    for name, kind, owner, acl, parent in rows(REL_SQL):
        snap[('rel', name)] = {'kind': kind, 'owner': owner, 'acl': acl, 'parent': parent}
    for name, owner, acl, secdef in rows(FN_SQL):
        snap[('fn', name)] = {'owner': owner, 'acl': acl, 'secdef': secdef}
    for name, owner, acl, parent in rows(TYPE_SQL):
        snap[('type', name)] = {'owner': owner, 'acl': acl, 'parent': parent}
    for cat, name, value in rows(MISC_SQL):
        snap[(cat, name)] = {'value': value}
    return snap


def data_fingerprint():
    tables = rows("SELECT n.nspname, c.relname FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace "
                  "WHERE c.relkind='r' AND n.nspname IN ('public','community') ORDER BY 1,2")
    parts = []
    for nsp, rel in tables:
        ident = '"{}"."{}"'.format(nsp.replace('"', '""'), rel.replace('"', '""'))
        lit = (nsp + '.' + rel).replace("'", "''")
        parts.append("SELECT '{}', count(*)::text, md5(coalesce(string_agg(x::text, E'\\n' ORDER BY x::text), '')) FROM {} x"
                     .format(lit, ident))
    res = {}
    for name, n, digest in rows('\nUNION ALL\n'.join(parts) + '\nORDER BY 1'):
        res[name] = (int(n), digest)
    return res


def root_parent(snap, key):
    seen = set()
    while key in snap and snap[key].get('parent') and key not in seen:
        seen.add(key)
        key = ('rel', snap[key]['parent'])
    return key[1]


def non_owner_acl(names, fn=False):
    """권한 목록에서 소유자 항목을 뺀 나머지 — (받는 사람, 권한, 넘김 가능)의 정렬된 목록."""
    if fn:
        q = ("SELECT n.nspname||'.'||p.proname||'('||pg_get_function_identity_arguments(p.oid)||')', "
             "coalesce(string_agg(CASE a.grantee WHEN 0 THEN 'PUBLIC' ELSE pg_get_userbyid(a.grantee) END||':'||a.privilege_type||':'||a.is_grantable::text, ',' "
             "ORDER BY CASE a.grantee WHEN 0 THEN 'PUBLIC' ELSE pg_get_userbyid(a.grantee) END, a.privilege_type), '') "
             "FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace "
             "LEFT JOIN LATERAL aclexplode(p.proacl) a ON a.grantee <> p.proowner "
             "WHERE p.oid = ANY (SELECT to_regprocedure(x) FROM unnest({}) x) GROUP BY 1 ORDER BY 1").format(arr(names))
    else:
        q = ("SELECT n.nspname||'.'||c.relname, "
             "coalesce(string_agg(CASE a.grantee WHEN 0 THEN 'PUBLIC' ELSE pg_get_userbyid(a.grantee) END||':'||a.privilege_type||':'||a.is_grantable::text, ',' "
             "ORDER BY CASE a.grantee WHEN 0 THEN 'PUBLIC' ELSE pg_get_userbyid(a.grantee) END, a.privilege_type), '') "
             "FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace "
             "LEFT JOIN LATERAL aclexplode(c.relacl) a ON a.grantee <> c.relowner "
             "WHERE n.nspname||'.'||c.relname = ANY ({}) GROUP BY 1 ORDER BY 1").format(arr(names))
    return {name: value for name, value in rows(q)}


def expected_acl(name):
    parts = ['tbox_app:{}:false'.format(p) for p in APP_PRIVS[name]] + ['tbox_backup:SELECT:false']
    return ','.join(sorted(parts))


def owner_probe():
    """tbox_owner 로 DELETE … WHERE false — 되돌리는 트랜잭션 안. {표: 'ok'|'42501'}"""
    text = ("BEGIN;\nSET LOCAL ROLE tbox_owner;\nDO $probe$ DECLARE t text; BEGIN\n FOREACH t IN ARRAY " + arr(TABLES) + " LOOP\n"
            "  BEGIN EXECUTE 'DELETE FROM ' || t || ' WHERE false'; RAISE NOTICE 'probe %=ok', t;\n"
            "  EXCEPTION WHEN insufficient_privilege THEN RAISE NOTICE 'probe %=42501', t; END;\n END LOOP; END $probe$;\nROLLBACK;\n")
    r = run_psql(text)
    found = {}
    for line in notices(r.stderr.decode()):
        m = re.match(r'probe (\S+)=(\S+)', line)
        if m:
            found[m.group(1)] = m.group(2)
    return found


def purge_probe(dry):
    """tbox_owner 로 privacy_purge_expired — 되돌리는 트랜잭션 안. (rc, SQLSTATE 또는 '')"""
    text = ("\\set VERBOSITY verbose\nBEGIN;\nSET LOCAL ROLE tbox_owner;\n"
            "SELECT count(*) FROM public.privacy_purge_expired({}, 100);\nROLLBACK;\n").format('true' if dry else 'false')
    r = run_psql(text, args=('-q', '-A', '-t'), check_rc=False)
    err = r.stderr.decode()
    m = re.search(r'ERROR:\s+([0-9A-Z]{5}):', err)
    return r.returncode, (m.group(1) if m else ''), err.strip().splitlines()[:2]


def trigger_probe():
    """앱 계정의 users UPDATE 에서 보안 시대(mcp_security_epoch)가 오르는가 — 되돌리는 트랜잭션 안(그 안에서만 갱신 권한)."""
    text = ("BEGIN;\nGRANT SELECT, UPDATE ON public.users TO tbox_app;\nSET LOCAL ROLE tbox_app;\n"
            "UPDATE public.users SET is_active = NOT is_active WHERE username = 'qf13of_a' RETURNING mcp_security_epoch;\n"
            "ROLLBACK;\n")
    r = run_psql(text, args=('-q', '-A', '-t'), check_rc=False)
    return r.returncode, r.stdout.decode().strip(), r.stderr.decode().strip()[:300]


def apply_migration(as_owner_role=False):
    text = MIGRATION.read_text()
    if as_owner_role:
        text = 'SET ROLE tbox_owner;\n' + text
    return run_psql(text, check_rc=False)


def base_files():
    """새 설치 모양 — 파일마다 따로 붙는다(memories_harness.py 처럼 세션 설정이 다음 파일로 새지 않게) · 첫 줄 SET ROLE tbox_owner."""
    schema = (ROOT / 'db/schema.sql').read_text()
    include = '\\ir assistant.sql'
    if schema.count(include) != 1:
        raise RuntimeError('schema.sql include shape changed')
    schema = schema.replace(include, (ROOT / 'db/assistant.sql').read_text())   # 표준입력으로 넣으므로 \\ir 을 펼친다
    chunks = [schema, 'CREATE SCHEMA community;\n', (ROOT / 'db/community/schema.sql').read_text()]
    chunks += [(ROOT / 'db/migrations' / m).read_text() for m in BASE_MIGRATIONS]
    return ['SET ROLE tbox_owner;\n' + c for c in chunks]


def production_shape_sql():
    t = ', '.join(TABLES)
    s = ', '.join(SEQUENCES)
    lines = ['BEGIN;']
    for name in TABLES:
        lines.append('ALTER TABLE {} OWNER TO postgres;'.format(name))   # 딸린 BIGSERIAL 시퀀스 · 인덱스가 함께 넘어간다
    lines.append('ALTER FUNCTION {} OWNER TO postgres;'.format(FUNCTION))
    lines.append('REVOKE ALL ON {} FROM tbox_app, tbox_backup;'.format(t))
    lines.append('REVOKE ALL ON SEQUENCE {} FROM tbox_app, tbox_backup;'.format(s))
    lines.append('GRANT SELECT, INSERT, UPDATE, DELETE ON {} TO tbox_app;'.format(', '.join(ARWD)))
    lines.append('GRANT SELECT, INSERT, UPDATE ON {} TO tbox_app;'.format(', '.join(ARW)))
    lines.append('GRANT SELECT ON {} TO tbox_app;'.format(', '.join(R_ONLY)))
    lines.append('GRANT SELECT ON {} TO tbox_backup;'.format(t))
    lines.append('GRANT USAGE, SELECT ON SEQUENCE {} TO tbox_app;'.format(s))
    lines.append('GRANT SELECT ON SEQUENCE {} TO tbox_backup;'.format(s))
    lines.append('REVOKE ALL ON FUNCTION {} FROM PUBLIC;'.format(FUNCTION))
    # 목록 밖 postgres 소유 객체(카나리아)
    lines += [
        'CREATE TABLE public.qf13_canary (id BIGSERIAL PRIMARY KEY, note TEXT NOT NULL);',
        'GRANT SELECT, INSERT ON public.qf13_canary TO tbox_app;',
        'GRANT SELECT ON public.qf13_canary TO tbox_backup;',
        'GRANT USAGE, SELECT ON SEQUENCE public.qf13_canary_id_seq TO tbox_app;',
        'CREATE SEQUENCE public.qf13_canary_standalone_seq;',
        'GRANT SELECT ON SEQUENCE public.qf13_canary_standalone_seq TO tbox_backup;',
        'CREATE TABLE community.fetch_hosts (id INT PRIMARY KEY, note TEXT NOT NULL);',
        'GRANT SELECT ON community.fetch_hosts TO tbox_backup;',
        "CREATE FUNCTION public.mcp_bump_security_epoch(integer) RETURNS integer LANGUAGE sql IMMUTABLE AS 'SELECT $1';",
        'REVOKE ALL ON FUNCTION public.mcp_bump_security_epoch(integer) FROM PUBLIC;',
        # 행(합성 값만)
        "INSERT INTO public.users(username, password_hash, role) VALUES ('qf13of_a','x','Employee'), ('qf13of_b','x','Employee');",
        "INSERT INTO community.memory_follows(follower_user_id, followed_user_id) "
        "SELECT a.id, b.id FROM public.users a, public.users b WHERE a.username='qf13of_a' AND b.username='qf13of_b';",
        "INSERT INTO community.memory_preferences(user_id) SELECT id FROM public.users WHERE username='qf13of_a';",
        "INSERT INTO community.usage_guides(user_id, version, status, context) "
        "SELECT id, 1, 'active', 'overview' FROM public.users WHERE username='qf13of_a';",
        "INSERT INTO public.fetch_hosts(host, note) VALUES ('owner-fix.example.invalid', 'qf13 owner-fix fixture');",
        "INSERT INTO public.folders(name, depth, access_level, created_by) "
        "SELECT 'qf13 owner-fix', 1, 'private', id FROM public.users WHERE username='qf13of_a';",
        "INSERT INTO public.mcp_clients(id, name, redirect_uris) VALUES ('mcp_client_' || repeat('A', 43), 'qf13 fixture', '[]');",
        "INSERT INTO public.qf13_canary(note) VALUES ('canary one'), ('canary two');",
        "INSERT INTO community.fetch_hosts VALUES (1, 'same name, other schema');",
        "SELECT nextval('public.qf13_canary_standalone_seq');",
        'COMMIT;',
    ]
    return '\n'.join(lines) + '\n'


def diff_keys(a, b):
    return sorted(k for k in set(a) | set(b) if a.get(k) != b.get(k))


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--output', type=Path, default=Path('/tmp/qaredi-qf13-owner-fix-results'))
    args = parser.parse_args()
    out = args.output.resolve()
    if str(out).startswith('/var/www'):
        raise SystemExit('Output under operating tree refused')
    out.mkdir(parents=True, exist_ok=True)
    if str(ROOT).startswith('/var/www'):
        print('운영 작업 트리에서 돌리지 않는다 — 저장소 사본(worktree)에서 돌린다')
    probe = subprocess.run(['sudo', '-n', '-u', 'postgres', 'true'], capture_output=True, cwd='/')
    if probe.returncode != 0:
        print('sudo -n -u postgres 를 쓸 수 없다 — 시작하지 않는다')
        return 3
    print('스크래치 DB:', DB, '(운영 tbox_re 접속 0)')
    created = False
    logs = []
    try:
        r = subprocess.run(['sudo', '-n', '-u', 'postgres', 'createdb', '-O', 'tbox_owner', DB], capture_output=True, cwd='/')
        if r.returncode != 0:
            raise RuntimeError('createdb failed: ' + r.stderr.decode(errors='replace'))
        created = True

        section('0  빈 DB 에 적용 (목록 객체 · community 스키마 없음)')
        r0 = apply_migration()
        logs.append('== run0 (empty DB) rc={}\n{}{}'.format(r0.returncode, r0.stdout.decode(), r0.stderr.decode()))
        n0 = notices(r0.stderr.decode())
        check(r0.returncode == 0, '0 빈 DB 적용 rc=0')
        check(any('바꾼 것 0' in x for x in n0), '0 빈 DB 「바꾼 것 0」')

        section('흉내 만들기 — 새 설치 스키마(SET ROLE tbox_owner) → 목록 20 을 postgres 로 · 운영 권한 모양 · 카나리아 · 행')
        for chunk in base_files():
            run_psql(chunk)
        run_psql(production_shape_sql())
        listed_seq_parent = dict(rows("SELECT n.nspname||'.'||c.relname, pn.nspname||'.'||pc.relname FROM pg_class c "
                                      "JOIN pg_namespace n ON n.oid=c.relnamespace JOIN pg_depend d ON d.classid='pg_class'::regclass "
                                      "AND d.objid=c.oid AND d.refclassid='pg_class'::regclass AND d.deptype IN ('a','i') "
                                      "JOIN pg_class pc ON pc.oid=d.refobjid JOIN pg_namespace pn ON pn.oid=pc.relnamespace "
                                      "WHERE c.relkind='S' AND n.nspname||'.'||c.relname = ANY ({})".format(arr(SEQUENCES))))
        print('  · 목록 시퀀스의 딸린 표:', json.dumps(listed_seq_parent, ensure_ascii=False))

        s0 = snapshot()
        d0 = data_fingerprint()
        acl0 = non_owner_acl(LISTED_REL)
        facl0 = non_owner_acl([FUNCTION] + CANARY_FN, fn=True)
        cacl0 = non_owner_acl(CANARY_REL)

        section('사전 — 흉내가 운영 모양인가 (R-120 재현)')
        pg_owned = sorted(k[1] for k, v in s0.items() if k[0] == 'rel' and v['owner'] == 'postgres' and v['kind'] in ('r', 'S'))
        check(pg_owned == sorted(LISTED_REL + CANARY_REL),
              '사전 postgres 소유 표·시퀀스 = 목록 19 + 카나리아 4 (실제 {})'.format(len(pg_owned)))
        check(all(s0[('rel', n)]['owner'] == 'postgres' for n in LISTED_REL), '사전 목록 표 15 · 시퀀스 4 소유자 postgres')
        check(s0[('fn', FUNCTION)]['owner'] == 'postgres', '사전 함수 소유자 postgres')
        check(all(listed_seq_parent.get(sq) == sq[:-len('_id_seq')] for sq in SEQUENCES), '사전 목록 시퀀스 4 는 각 표의 BIGSERIAL(딸림)')
        check(all(acl0.get(n) == expected_acl(n) for n in LISTED_REL), '사전 tbox_app · tbox_backup 권한 = 운영 모양(arwd · arw · r · rU / r)')
        check(facl0.get(FUNCTION) == '', '사전 함수 소유자 밖 권한 0 (PUBLIC 회수)')
        pre_del = rows("SELECT t, has_table_privilege('tbox_owner', t, 'DELETE')::text, has_table_privilege('tbox_owner', t, 'UPDATE')::text "
                       "FROM unnest({}) t".format(arr(TABLES)))
        check(all(d == 'false' and u == 'false' for _, d, u in pre_del), '사전 has_table_privilege(tbox_owner, 표 15, DELETE·UPDATE) = 거짓')
        p0 = owner_probe()
        check(len(p0) == 15 and all(v == '42501' for v in p0.values()), '사전 tbox_owner DELETE … WHERE false → 42501 (15/15)')
        rc, state, first = purge_probe(True)
        print('  · 사전 privacy_purge_expired(dry) 오류 첫 줄:', first[0] if first else '-')
        check(rc != 0 and state == '42501', '사전 tbox_owner privacy_purge_expired(dry) → 42501 (실제 rc={} {})'.format(rc, state))
        trc, tout, terr = trigger_probe()
        check(trc == 0 and tout == '1', '사전 앱 계정 users UPDATE → mcp_security_epoch 1 (트리거 함수 postgres 소유, 되돌림)')
        check(snapshot() == s0 and data_fingerprint() == d0, '사전 확인 뒤 카탈로그·행 변화 0(되돌린 트랜잭션)')

        section('가드 — SET ROLE tbox_owner 로 적용하면 거부')
        rg = apply_migration(as_owner_role=True)
        logs.append('== run-guard (SET ROLE tbox_owner) rc={}\n{}{}'.format(rg.returncode, rg.stdout.decode(), rg.stderr.decode()))
        err_g = rg.stderr.decode()
        check(rg.returncode != 0 and 'owner_fix: postgres 소유 객체 20개' in err_g and '슈퍼유저' in err_g,
              '가드 rc≠0 · 「postgres 소유 객체 20개 … 슈퍼유저」 안내')
        check(snapshot() == s0 and data_fingerprint() == d0, '가드 뒤 카탈로그·행 변화 0')

        section('1회 적용 (postgres · SET ROLE 없이)')
        r1 = apply_migration()
        logs.append('== run1 rc={}\n{}{}'.format(r1.returncode, r1.stdout.decode(), r1.stderr.decode()))
        n1 = notices(r1.stderr.decode())
        print('  · NOTICE:', json.dumps(n1, ensure_ascii=False))
        check(r1.returncode == 0, '1회 rc=0')
        check(sum('owner_fix: 표 ' in x for x in n1) == 15 and sum('owner_fix: 함수 ' in x for x in n1) == 1
              and any('바꾼 것 16' in x for x in n1), '1회 NOTICE 표 15 · 함수 1 · 「바꾼 것 16」(시퀀스 4 는 표와 함께)')
        s1 = snapshot()
        d1 = data_fingerprint()
        acl1 = non_owner_acl(LISTED_REL)
        facl1 = non_owner_acl([FUNCTION] + CANARY_FN, fn=True)
        cacl1 = non_owner_acl(CANARY_REL)

        section('① 소유자')
        check(all(s1[('rel', n)]['owner'] == 'tbox_owner' for n in TABLES), '① 표 15 소유자 tbox_owner')
        check(all(s1[('rel', n)]['owner'] == 'tbox_owner' for n in SEQUENCES), '① 시퀀스 4 소유자 tbox_owner')
        check(s1[('fn', FUNCTION)]['owner'] == 'tbox_owner', '① 함수 mcp_bump_security_epoch() 소유자 tbox_owner')
        idx = [k[1] for k, v in s1.items() if k[0] == 'rel' and v['kind'] == 'i' and v['parent'] in TABLES]
        check(idx and all(s1[('rel', i)]['owner'] == 'tbox_owner' for i in idx), '① 목록 표의 인덱스 {} 개도 tbox_owner'.format(len(idx)))

        section('② 앱 · 백업 권한(소유자 항목을 뺀 나머지)')
        check(acl1 == acl0, '② 목록 19 의 tbox_app · tbox_backup 권한이 전과 같음')
        check(all(acl1.get(n) == expected_acl(n) for n in LISTED_REL), '② 기대 모양 그대로(arwd · arw · r · rU / r)')
        check(facl1.get(FUNCTION) == '' and facl1 == facl0, '② 함수 소유자 밖 권한 0 그대로 (PUBLIC 회수 유지)')
        fpriv = rows("SELECT has_function_privilege('tbox_app', '{0}', 'EXECUTE')::text, has_function_privilege('public', '{0}', 'EXECUTE')::text, "
                     "has_function_privilege('tbox_owner', '{0}', 'EXECUTE')::text".format(FUNCTION))[0]
        check(fpriv == ['false', 'false', 'true'], '② 함수 실행: tbox_app 거짓 · PUBLIC 거짓 · tbox_owner 참')
        no_pg = all('postgres' not in s1[('rel', n)]['acl'] for n in LISTED_REL) and 'postgres' not in s1[('fn', FUNCTION)]['acl']
        check(no_pg, '② 목록 20 의 권한 목록에 postgres 항목 0 (준 사람도 tbox_owner 로 옮겨 적힘)')

        section('③ tbox_owner 가 정리 대상을 다룬다')
        post = rows("SELECT t, has_table_privilege('tbox_owner', t, 'DELETE')::text, has_table_privilege('tbox_owner', t, 'UPDATE')::text "
                    "FROM unnest({}) t".format(arr(TABLES)))
        check(all(d == 'true' for _, d, _u in post), '③ has_table_privilege(tbox_owner, 표 15, DELETE) = 참')
        check(all(u == 'true' for _, _d, u in post), '③ has_table_privilege(tbox_owner, 표 15, UPDATE) = 참')
        seqp = rows("SELECT has_sequence_privilege('tbox_owner', s, 'USAGE')::text FROM unnest({}) s".format(arr(SEQUENCES)))
        check(len(seqp) == 4 and all(x[0] == 'true' for x in seqp), '③ has_sequence_privilege(tbox_owner, 시퀀스 4, USAGE) = 참')
        p1 = owner_probe()
        check(len(p1) == 15 and all(v == 'ok' for v in p1.values()), '③ tbox_owner DELETE … WHERE false 통과 (15/15)')
        check(all(p1.get(t) == 'ok' for t in PURGE_TARGETS), '③ 정리 대상 다섯(여행 둘 · 팔로우 · 선호 · api_tokens) 통과')
        rc, state, _ = purge_probe(True)
        check(rc == 0 and state == '', '③ tbox_owner privacy_purge_expired(dry) 통과 (실제 rc={} {})'.format(rc, state or '-'))
        rc, state, _ = purge_probe(False)
        check(rc == 0 and state == '', '③ tbox_owner privacy_purge_expired(실제 · 되돌림) 통과 (실제 rc={} {})'.format(rc, state or '-'))
        trc, tout, terr = trigger_probe()
        check(trc == 0 and tout == '1', '덤 앱 계정 users UPDATE → mcp_security_epoch 1 (트리거 함수 tbox_owner 소유, 되돌림)')

        section('④ 목록 밖은 그대로')
        check(all(s1[('rel', n)] == s0[('rel', n)] for n in CANARY_REL), '④ 카나리아 표·시퀀스 넷 소유자 postgres · 권한 원문 그대로')
        check(all(s1[('fn', n)] == s0[('fn', n)] for n in CANARY_FN) and facl1.get(CANARY_FN[0]) == facl0.get(CANARY_FN[0]),
              '④ 같은 이름 다른 인자 함수 mcp_bump_security_epoch(integer) 그대로(postgres)')
        check(cacl1 == cacl0, '④ 카나리아 소유자 밖 권한 그대로')
        changed = diff_keys(s0, s1)
        unexpected = []
        for k in changed:
            cat, name = k
            if cat == 'rel' and (name in LISTED_REL or root_parent(s1, k) in TABLES):
                continue
            if cat == 'fn' and name == FUNCTION:
                continue
            if cat == 'type':
                parent = s1.get(k, s0.get(k, {})).get('parent', '')
                if parent in TABLES or root_parent(s1, ('rel', parent)) in TABLES:
                    continue
            unexpected.append('{}:{}'.format(cat, name))
        print('  · 바뀐 카탈로그 항목 {} 개 (목록 객체와 딸림)'.format(len(changed)))
        check(not unexpected, '④ 카탈로그 전체 비교 — 목록 밖 바뀐 것 0 {}'.format(unexpected[:5] if unexpected else ''))
        changed_main = sorted(k[1] for k in changed if k[0] == 'rel' and s1[k]['kind'] in ('r', 'S'))
        check(changed_main == sorted(LISTED_REL), '④ 바뀐 표·시퀀스 = 목록 19 정확히')
        check(sorted(k[1] for k in changed if k[0] == 'fn') == [FUNCTION], '④ 바뀐 함수 = 목록 1 정확히')
        check(not [k for k in changed if k[0] in ('col', 'nsp', 'db', 'defacl', 'trg')], '④ 열 권한 · 스키마 · DB · 기본 권한 · 트리거 그대로')
        check(d1 == d0, '④ 행 지문(공개·커뮤니티 표 {} 개의 수·md5) 그대로'.format(len(d0)))

        section('⑤ 2회 적용')
        r2 = apply_migration()
        logs.append('== run2 rc={}\n{}{}'.format(r2.returncode, r2.stdout.decode(), r2.stderr.decode()))
        n2 = notices(r2.stderr.decode())
        print('  · NOTICE:', json.dumps(n2, ensure_ascii=False))
        check(r2.returncode == 0, '⑤ 2회 rc=0')
        check(len(n2) == 1 and '바꾼 것 0' in n2[0], '⑤ 2회 NOTICE 「바꾼 것 0」 하나')
        s2 = snapshot()
        check(s2 == s1, '⑤ 카탈로그(소유자 · 권한 원문 · 딸림 · 함수 · 타입 · 스키마 · DB) 1회 뒤와 같음')
        check(data_fingerprint() == d1, '⑤ 행 지문 1회 뒤와 같음')

        section('B 갈래 — 표에 딸리지 않은 postgres 시퀀스 · postgres 가 아닌 소유자')
        run_psql('BEGIN;\nALTER SEQUENCE public.folders_id_seq OWNED BY NONE;\nALTER SEQUENCE public.folders_id_seq OWNER TO postgres;\n'
                 'ALTER TABLE community.usage_guide_versions OWNER TO tbox_perm;\nCOMMIT;\n')
        rb = apply_migration()
        logs.append('== run-B rc={}\n{}{}'.format(rb.returncode, rb.stdout.decode(), rb.stderr.decode()))
        nb = notices(rb.stderr.decode())
        print('  · NOTICE:', json.dumps(nb, ensure_ascii=False))
        sb = snapshot()
        check(rb.returncode == 0 and any('시퀀스 public.folders_id_seq' in x for x in nb) and any('바꾼 것 1' in x for x in nb),
              'B 딸리지 않은 postgres 시퀀스는 시퀀스 칸이 바꿈(「바꾼 것 1」)')
        check(sb[('rel', 'public.folders_id_seq')]['owner'] == 'tbox_owner', 'B folders_id_seq 소유자 tbox_owner')
        check(sb[('rel', 'community.usage_guide_versions')]['owner'] == 'tbox_perm', 'B postgres 가 아닌 소유자(tbox_perm)의 목록 표는 그대로')
        run_psql('BEGIN;\nALTER TABLE community.usage_guide_versions OWNER TO tbox_owner;\n'
                 'ALTER SEQUENCE public.folders_id_seq OWNED BY public.folders.id;\nCOMMIT;\n')
        check(snapshot() == s2 and data_fingerprint() == d1, 'B 되돌린 뒤 카탈로그·행 = ⑤ 와 같음')

        with (out / 'acl-before-after.txt').open('w') as fh:
            for n in LISTED_REL:
                fh.write('{}\n  before {} {}\n  after  {} {}\n'.format(n, s0[('rel', n)]['owner'], s0[('rel', n)]['acl'],
                                                                       s1[('rel', n)]['owner'], s1[('rel', n)]['acl']))
            for n in [FUNCTION] + CANARY_FN:
                fh.write('{}\n  before {} {}\n  after  {} {}\n'.format(n, s0[('fn', n)]['owner'], s0[('fn', n)]['acl'],
                                                                       s1[('fn', n)]['owner'], s1[('fn', n)]['acl']))
            for n in CANARY_REL:
                fh.write('{}\n  before {} {}\n  after  {} {}\n'.format(n, s0[('rel', n)]['owner'], s0[('rel', n)]['acl'],
                                                                       s1[('rel', n)]['owner'], s1[('rel', n)]['acl']))
    except Exception as error:   # 실패도 결과로 남기고 DB 는 지운다
        check(False, '시험 실행 오류: {}: {}'.format(type(error).__name__, str(error)[:600]))
    finally:
        (out / 'migration-runs.log').write_text('\n'.join(logs))
        if created:
            d = subprocess.run(['sudo', '-n', '-u', 'postgres', 'dropdb', '--if-exists', DB], capture_output=True, cwd='/')
            print('\n스크래치 DB 지움:', DB, 'rc={}'.format(d.returncode))
            left = subprocess.run(['sudo', '-n', '-u', 'postgres', 'psql', '-X', '-At', '-d', 'postgres', '-c',
                                   "SELECT count(*) FROM pg_database WHERE datname = '{}'".format(DB)],
                                  capture_output=True, cwd='/').stdout.decode().strip()
            check(left == '0', '끝 스크래치 DB 남은 것 0')
    passed = sum(1 for x in RESULTS if x['ok'])
    failed = len(RESULTS) - passed
    (out / 'owner-fix-results.json').write_text(json.dumps({'database': DB, 'passed': passed, 'failed': failed,
                                                           'results': RESULTS}, ensure_ascii=False, indent=1))
    print('\n결과: {} 통과 / {} 실패'.format(passed, failed))
    return 0 if failed == 0 else 1


if __name__ == '__main__':
    sys.exit(main())
