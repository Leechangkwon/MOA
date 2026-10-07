#!/usr/bin/env python3
"""사이트 Q:F-13 지시서 P1 — 처리방침·약관 게시 · 가입 동의 · 기존 회원 관문 · AI 국외 전송 동의 · 동의 기록 · 화면
(실제 PHP·PostgreSQL + Chromium).

자기 시험 틀이다 — memories_harness.py 와 같은 방식으로:
  · 저장소의 임시 사본(/tmp/qaredi-qf13p-*) · 빈 합성 DB `tbox_memories_test_qf13_<pid>`(+ 마이그레이션 시험용 `…qf13m_<pid>`) ·
    로컬 php -S 127.0.0.1:8786 하나 · 끝나면 서버·DB·사본을 지운다. 포트가 쓰이고 있으면 아무것도 멈추지 않고 끝낸다.
  · 운영 DB·운영 8082·시드 계정 0. 앱 접속 키 넷(DB_HOST·PORT·USER·PASS)만 memories_harness.py 와 같은 자리에서 읽어 **새 빈 DB** 에 쓴다
    (운영 행·비밀·파일을 복사하지 않는다). 계정은 fixture 의 claude_ 와 이 시험이 만드는 qf13_ 합성 계정뿐 · UA tbox-review/<날짜>.
  · 외부 요청 0 — 사본의 Fetch.php(모아 AI·도구·사진 이야기)는 memory_ai_transport.php.fixture 로, Ai.php(커뮤니티 AI)는
    stage13_ai_stub.php.fixture 로 바꿔 「합성 provider」가 받은 요청을 파일에 센다(사본에서만 — 저장소 코드는 그대로).
  · 결과 파일(results.json)에는 단언 이름과 참/거짓, 상태 코드·건수만 — 세션·CSRF·비밀번호·응답 본문 원문은 싣지 않는다.

시험(지시서 P1 표)
  공개   로그인 없이 /privacy · /terms 200 · Set-Cookie 0 · 운영자·책임자 이메일·시행일 · ?v=없는값 404 · ?v=../x 404 · 로그인해도 개인 데이터 0
  가입   체크 둘 중 하나라도 빠짐 → 400 · 계정 0 / 둘 다 → 201 · user_consents 세 줄(terms·privacy·age14) · 감사
  기존 회원  동의 줄 없는 회원 로그인 → 화면 /tbox/consent · 세션 API 403 consent_required(동의·로그아웃·/api/me 제외) ·
         커뮤니티도 같음 · 개인 토큰 창구는 그대로 · 계정 절차가 먼저 · 동의 뒤 정상 · 판이 바뀌면 다시
  AI     ai_overseas 없는 회원의 모아 AI · 메신저 AI 도구 · 사진 이야기 · 커뮤니티 AI → 409 · 합성 provider 호출 0 / 동의 뒤 200 / 철회 뒤 다시 409
  기록   앱 계정으로 user_consents UPDATE/DELETE/TRUNCATE → permission denied · 감사 consent.agree · consent.withdraw · auth.gate
  마이그레이션  P1 전 스키마(main) 위에 2회 적용 = 새 설치 스키마와 같은 모양 · 기존 계정 줄 0
  화면   바닥 링크 다섯 자리 · 동의 화면 360·412·820·1440 가로 넘침 0 · 콘솔 0 · 고대비(밝게·어둡게) · 동의 뒤 보던 곳으로 ·
         메신저 AI 409 → 동의 창 → 동의하면 같은 요청이 한 번 다시(호출 1) · 닫으면 호출 0

실행:  . <결과 폴더>/env.sh && python3 tests/qf13_consent_http.py --output /절대/결과/폴더
"""
import argparse
import base64
import hashlib
import hmac
import json
import os
import re
import secrets
import shutil
import signal
import socket
import struct
import subprocess
import sys
import tempfile
import time
import uuid
import zlib
from pathlib import Path

import requests

ROOT = Path(__file__).resolve().parents[1]
UA = 'tbox-review/' + time.strftime('%Y-%m-%d')
PASSWORD = 'Qf13-synthetic-only-2026!'
checks = []


def check(ok, name):
    checks.append({'name': name, 'pass': bool(ok)})
    print(('PASS ' if ok else 'FAIL ') + name, flush=True)
    if not ok:
        raise AssertionError(name)


def values(path):
    out = {}
    for line in path.read_text().splitlines():
        if line.strip() and not line.lstrip().startswith('#') and '=' in line:
            k, v = line.strip().split('=', 1)
            out[k] = v.strip().strip('"\'')
    return out


def private_write(path, text):
    path.write_text(text)
    path.chmod(0o600)


def png_rgb(w, h, rgb=(90, 140, 200)):
    raw = b''.join(b'\x00' + bytes(rgb) * w for _ in range(h))
    def chunk(t, d):
        return struct.pack('>I', len(d)) + t + d + struct.pack('>I', zlib.crc32(t + d) & 0xffffffff)
    return b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('>IIBBBBB', w, h, 8, 2, 0, 0, 0)) + chunk(b'IDAT', zlib.compress(raw)) + chunk(b'IEND', b'')


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--port', type=int, default=8786, choices=(8786,))
    args = parser.parse_args()
    out = args.output.resolve()
    if str(ROOT).startswith('/var/www'):
        raise SystemExit('Operating source refused')
    with socket.socket() as sock:
        if sock.connect_ex(('127.0.0.1', args.port)) == 0:
            raise SystemExit('Port occupied; no existing process stopped')
    out.mkdir(parents=True, exist_ok=True)
    pgpass = Path.home() / '.config/qaredi/pgpass-testdb'
    password = None
    for line in pgpass.read_text().splitlines():
        f = line.split(':', 4)
        if len(f) == 5 and f[3] == 'tbox_testdb':
            password = f[4]
            break
    if password is None:
        raise SystemExit('Dedicated test DB credentials unavailable')
    work = Path(tempfile.mkdtemp(prefix='qaredi-qf13p-', dir='/tmp'))
    work.chmod(0o700)
    instance = work / 'tbox-v2'
    db_name = 'tbox_memories_test_qf13_{}'.format(os.getpid())
    mig_db = 'tbox_memories_test_qf13m_{}'.format(os.getpid())
    db_env = {k: v for k, v in os.environ.items() if not k.startswith('PG')}
    db_env['PGPASSFILE'] = str(pgpass)
    db_opts = ['-h', '127.0.0.1', '-p', '5432', '-U', 'tbox_testdb', '-w']
    created = []
    server = None
    config = {}

    def db_cmd(program, tail, **kw):
        return subprocess.run([program] + db_opts + tail, env=db_env, check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, **kw)

    def sql_file(db, path):
        text = path.read_text()
        if 'OWNER TO tbox_owner;' in text:
            # 합성 DB 소유자만 — 운영 마이그레이션은 tbox_owner 그대로(memories_harness.py 와 같은 갈음)
            adapted = work / ('adapted-' + path.name)
            adapted.write_text(text.replace('OWNER TO tbox_owner;', 'OWNER TO tbox_testdb;'))
            path = adapted
        return db_cmd('psql', ['-X', '-d', db, '-v', 'ON_ERROR_STOP=1', '-f', str(path)])

    def sql(text, db=None):
        r = subprocess.run(['psql'] + db_opts + ['-X', '-q', '-At', '-d', db or db_name, '-v', 'ON_ERROR_STOP=1'], env=db_env,
                           input=text.encode(), capture_output=True, check=True)
        return r.stdout.decode().strip()

    try:
        shutil.copytree(ROOT, instance, ignore=shutil.ignore_patterns('.git', '.env', '__pycache__'))
        # ── 합성 provider (사본에서만) ──
        fetch = instance / 'src/Fetch.php'
        source = fetch.read_text()
        needle = "        $host = 'generativelanguage.googleapis.com';"
        assert source.count(needle) == 1
        fetch.write_text(source.replace(needle, "        return require __DIR__ . '/memory-ai-test-transport.php';\n" + needle))
        (instance / 'src/memory-ai-test-transport.php').write_bytes((ROOT / 'tests/community/memory_ai_transport.php.fixture').read_bytes())
        stub = (ROOT / 'tests/community/stage13_ai_stub.php.fixture').read_text()
        assert stub.count("'tbox_stage13_test_'") == 1
        (instance / 'src/Ai.php').write_text(stub.replace("'tbox_stage13_test_'", "'tbox_memories_test_'"))
        for name in ('storage', 'logs'):
            (work / name).mkdir(mode=0o700)
        config = values(ROOT / '.env.example')
        connection = values(Path('/var/www/qaredi/tbox-v2/.env'))   # 접속 키 넷만 (memories_harness.py 와 같다)
        for key in ('DB_HOST', 'DB_PORT', 'DB_USER', 'DB_PASS'):
            config[key] = connection[key]
        config.update(DB_NAME=db_name, APP_SECRET=secrets.token_hex(32), STORAGE_DIR=str(work / 'storage'),
                      AUDIT_LOG_FILE=str(work / 'logs/audit.log'), APP_URL='http://127.0.0.1:{}'.format(args.port),
                      API_TOKEN=secrets.token_hex(24), API_TOKEN_USER='claude_employee01', SCAN_ENABLED='0', ALERT_COMMAND='')
        def write_config(path, data):
            private_write(path, ''.join('{}={}\n'.format(k, v) for k, v in data.items()))
        write_config(instance / '.env', config)
        write_config(instance / '.stage13-fixture.env', dict(config, DB_USER='tbox_testdb', DB_PASS=password))

        # ── 마이그레이션 시험: P1 전 스키마(HEAD 의 첫 부모가 아니라 merge-base main) 위에 2회 적용 ──
        base_dir = work / 'base-db'
        base_dir.mkdir(mode=0o700)
        for name in ('schema.sql', 'assistant.sql'):   # schema.sql 이 \ir 로 assistant.sql 을 부른다 — 같은 판(main)으로 나란히 둔다
            (base_dir / name).write_bytes(subprocess.run(['git', 'show', 'main:db/' + name], cwd=ROOT, check=True, capture_output=True).stdout)
        db_cmd('createdb', ['-O', 'tbox_testdb', mig_db]); created.append(mig_db)
        logs = [sql_file(mig_db, base_dir / 'schema.sql').stdout]
        check(sql("SELECT to_regclass('public.user_consents') IS NULL", mig_db) == 't', 'MIG base (main) schema has no user_consents table')
        sql("INSERT INTO users(username,password_hash,role) VALUES('qf13_existing','x','Employee')", mig_db)
        for _ in range(2):
            logs.append(sql_file(mig_db, instance / 'db/migrations/2026-10-20_user_consents.sql').stdout)

        # ── 본 DB: 새 설치 스키마 + memories_harness.py 와 같은 마이그레이션 목록(2회) + 이번 마이그레이션(2회) + 권한 ──
        db_cmd('createdb', ['-O', 'tbox_testdb', db_name]); created.append(db_name)
        logs.append(sql_file(db_name, instance / 'db/schema.sql').stdout)
        db_cmd('psql', ['-X', '-d', db_name, '-v', 'ON_ERROR_STOP=1'], input=b'CREATE SCHEMA community;')
        logs.append(sql_file(db_name, instance / 'db/community/schema.sql').stdout)
        migrations = ['2026-09-29_community_trust.sql', '2026-09-30_community_automation.sql', '2026-10-01_community_suggestions.sql',
                      '2026-10-18_community_ai.sql', '2026-10-03_community_memories.sql', '2026-10-03_community_messenger.sql',
                      '2026-10-03_memory_story.sql', '2026-10-14_memory_social.sql', '2026-10-14_optional_member_auth.sql',
                      '2026-10-14_usage_guide.sql', '2026-10-14_travel_plans.sql', '2026-10-14_travel_scopes.sql',
                      '2026-10-15_remote_mcp.sql', '2026-10-19_qf12_work.sql', '2026-10-20_user_consents.sql']
        for _ in range(2):
            for m in migrations:
                logs.append(sql_file(db_name, instance / 'db/migrations' / m).stdout)
        for db in (db_name, mig_db):
            grants = (instance / 'db/grants.sql').read_text().replace('tbox_re', db)
            if 'DATABASE tbox_re ' in grants or not db.startswith('tbox_memories_test_'):
                raise RuntimeError('Temporary DB guard failed')
            gp = work / ('grants-' + db + '.sql')
            gp.write_text(grants)
            logs.append(sql_file(db, gp).stdout)
        logs.append(sql_file(db_name, instance / 'db/community/grants.sql').stdout)
        (out / 'schema.log').write_bytes(b''.join(logs))

        shape = ("SELECT string_agg(column_name||':'||data_type||':'||is_nullable||':'||coalesce(column_default,''),',' ORDER BY ordinal_position) "
                 "FROM information_schema.columns WHERE table_schema='public' AND table_name='user_consents'")
        cons = ("SELECT string_agg(pg_get_constraintdef(c.oid),' | ' ORDER BY pg_get_constraintdef(c.oid)) FROM pg_constraint c "
                "WHERE c.conrelid='public.user_consents'::regclass")
        idx = "SELECT string_agg(indexdef,' | ' ORDER BY indexdef) FROM pg_indexes WHERE schemaname='public' AND tablename='user_consents'"
        privs = ("SELECT string_agg(r||':'||p||'='||has_table_privilege(r,'public.user_consents',p)::text,',' ORDER BY r,p) FROM "
                 "unnest(ARRAY['tbox_app','tbox_backup']) r, unnest(ARRAY['SELECT','INSERT','UPDATE','DELETE','TRUNCATE']) p")
        for q, label in ((shape, 'columns'), (cons, 'constraints'), (idx, 'indexes'), (privs, 'privileges')):
            check(sql(q, mig_db) == sql(q, db_name) and sql(q, db_name) != '', 'MIG migration x2 on main schema == fresh schema.sql shape: ' + label)
        check(sql("SELECT count(*) FROM user_consents", mig_db) == '0', 'MIG migration creates no consent rows for existing members (PT-4: they consent on next login)')
        check(sql(privs, db_name) == 'tbox_app:DELETE=false,tbox_app:INSERT=true,tbox_app:SELECT=true,tbox_app:TRUNCATE=false,tbox_app:UPDATE=false,'
              'tbox_backup:DELETE=false,tbox_backup:INSERT=false,tbox_backup:SELECT=true,tbox_backup:TRUNCATE=false,tbox_backup:UPDATE=false',
              'REC privileges: tbox_app SELECT+INSERT only (no UPDATE/DELETE/TRUNCATE) · tbox_backup SELECT only')

        # ── fixture (claude_ 다섯 — 「이미 동의한 기존 회원」) ──
        auth_path = work / 'credentials.json'
        fixture_env = dict(os.environ, FILE_RENAME_FIXTURE_CONFIG=str(instance / '.stage13-fixture.env'))
        fx = subprocess.run(['php', 'tests/community/memories_fixture.php', 'init'], cwd=instance, env=fixture_env,
                            input=str(auth_path).encode(), check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        (out / 'fixture.log').write_bytes(fx.stdout)
        users = json.loads(auth_path.read_text())['users']

        with (work / 'logs/php.log').open('wb') as server_log:
            server = subprocess.Popen(['php', '-d', 'upload_max_filesize=100M', '-d', 'post_max_size=105M', '-S', '127.0.0.1:{}'.format(args.port),
                                       '-t', 'public', 'tests/community/export_router.php'], cwd=instance,
                                      env={k: v for k, v in os.environ.items() if k != 'PHP_CLI_SERVER_WORKERS'},
                                      stdout=server_log, stderr=server_log, start_new_session=True)
            for _ in range(50):
                if server.poll() is not None:
                    raise RuntimeError('Owned test server stopped')
                with socket.socket() as sock:
                    if sock.connect_ex(('127.0.0.1', args.port)) == 0:
                        break
                time.sleep(0.1)
            else:
                raise RuntimeError('Test HTTP startup timed out')
            print('SYNTHETIC_DB_CREATED=1; PRODUCTION_RECORDS_COPIED=0', flush=True)
            suite(instance, work, out, config, users, sql, args.port)
            server_log.flush()
        (out / 'http.log').write_bytes((work / 'logs/php.log').read_bytes())
    except subprocess.CalledProcessError as error:
        detail = (error.stderr or b'').decode(errors='replace')
        for s in [password] + [config.get(k, '') for k in ('DB_PASS', 'APP_SECRET', 'API_TOKEN')]:
            if s:
                detail = detail.replace(s, '[PRIVATE]')
        print('HARNESS_FAILURE=' + type(error).__name__ + ': ' + detail[-1800:], flush=True)
        raise SystemExit(1)
    finally:
        passed = sum(1 for c in checks if c['pass'])
        (out / 'results.json').write_text(json.dumps({'passed': passed, 'failed': len(checks) - passed, 'checks': checks},
                                                     ensure_ascii=False, indent=1) + '\n')
        if server is not None:
            try:
                os.killpg(server.pid, signal.SIGTERM)
                server.wait(timeout=5)
            except ProcessLookupError:
                pass
        for db in created:
            try:
                db_cmd('dropdb', ['--if-exists', db])
            except subprocess.CalledProcessError:
                print('DROP_FAILED=' + db, flush=True)
        shutil.rmtree(work)
        print('OWNED_TEST_PROCESSES_STOPPED=1; SYNTHETIC_DB_DROPPED=1; PASSED={} FAILED={}'.format(
            sum(1 for c in checks if c['pass']), sum(1 for c in checks if not c['pass'])), flush=True)


def suite(instance, work, out, config, users, sql, port):
    site = 'http://127.0.0.1:{}'.format(port)
    TB = site + '/tbox'
    CB = site + '/community'
    storage = work / 'storage'
    secret = config['APP_SECRET']
    legal = json.loads((instance / 'config/legal.json').read_text())
    VER = {'terms': legal['terms'][-1]['version'], 'privacy': legal['privacy'][-1]['version']}

    def sess_headers(sess, csrf=True, extra=None):
        h = {'User-Agent': UA}
        if sess:
            h['Cookie'] = '__Host-tbox_session=' + sess['session']
            if csrf:
                h['X-CSRF-Token'] = sess['csrf']
        h.update(extra or {})
        return h

    def req(method, url, sess=None, body=None, csrf=True, headers=None, files=None, html=False):
        h = sess_headers(sess, csrf, headers)
        if html:
            h['Accept'] = 'text/html,application/xhtml+xml'
        return requests.request(method, url, json=body, headers=h, files=files, timeout=40, allow_redirects=False)

    def make_session(uid):
        token = secrets.token_hex(32)
        digest = hashlib.sha256(token.encode()).hexdigest()
        sql("INSERT INTO sessions(id,user_id,expires_at,ip,user_agent) VALUES('" + digest + "'," + str(uid) + ",clock_timestamp()+interval '8 hours','127.0.0.1','" + UA + "')")
        return {'id': uid, 'session': token, 'csrf': hmac.new(secret.encode(), ('csrf:' + digest).encode(), hashlib.sha256).hexdigest()}

    team_a = sql("SELECT team_id FROM users WHERE username='claude_employee01'")
    pw_hash = subprocess.run(['php', '-r', 'echo password_hash($argv[1],PASSWORD_ARGON2ID);', PASSWORD], capture_output=True, text=True, check=True).stdout

    def make_user(name, consents=(), must_change=False, high_contrast=False):
        uid = int(sql("INSERT INTO users(username,display_name,password_hash,role,team_id,must_change_password,mfa_setup_required,password_changed_at,high_contrast) "
                      "VALUES('" + name + "','합성 " + name + "','" + pw_hash + "','Employee'," + team_a + "," + ('true' if must_change else 'false') +
                      ",false,now()," + ('true' if high_contrast else 'false') + ") RETURNING id"))
        for doc in consents:
            sql("INSERT INTO user_consents(user_id,doc,version,action) VALUES(" + str(uid) + ",'" + doc + "','" +
                VER['terms' if doc in ('terms', 'age14') else 'privacy'] + "','agree')")
        # 새 계정에 뜨는 「사용 안내」 창은 이 시험의 대상이 아니다 — 닫아 둔 상태로(qf13h_privacy_browser.py 와 같은 준비)
        sql("INSERT INTO community.usage_guides(user_id,version,status,context) VALUES(" + str(uid) + ",1,'dismissed','overview') ON CONFLICT DO NOTHING")
        return uid

    def consent_rows(uid):
        return sql("SELECT string_agg(doc||':'||version||':'||action,',' ORDER BY id) FROM user_consents WHERE user_id=" + str(uid))

    def audit_count(action, uid=None, reason=None):
        q = "SELECT count(*) FROM audit_logs WHERE action='" + action + "'"
        if uid is not None:
            q += " AND target_id=" + str(uid)
        if reason is not None:
            q += " AND reason='" + reason + "'"
        return int(sql(q))

    def no_cookie(r):
        return 'set-cookie' not in {k.lower() for k in r.headers.keys()}

    # ═════════ 공개 ═════════
    for doc in ('privacy', 'terms'):
        r = req('GET', TB + '/' + doc, html=True)
        body = r.text
        check(r.status_code == 200 and no_cookie(r) and 'text/html' in r.headers.get('Content-Type', ''), 'PUB GET /tbox/' + doc + ' without login: 200 · Set-Cookie 0')
        check("default-src 'self'" in r.headers.get('Content-Security-Policy', '') and r.headers.get('Cache-Control') == 'no-store',
              'PUB /tbox/' + doc + ' keeps the bootstrap CSP (default-src self) · no-store')
        check('이창권' in body and ('시행일 <span data-legal-version>' + VER[doc] + '</span>') in body and '<script' not in body.lower(),
              'PUB /tbox/' + doc + ' body: operator 이창권 · effective date ' + VER[doc] + ' at the top · no script tag')
        if doc == 'privacy':
            check('changkwon.lee@metaht.kr' in body and '1833-6972' in body and 'ecrm.police.go.kr' in body and VER['privacy'] + '부터 적용됩니다' in body,
                  'PUB privacy: officer e-mail · 제12조 agencies · 제14조 effective date')
        else:
            check(VER['terms'] + '부터 적용합니다' in body, 'PUB terms: 부칙 effective date')
        r2 = req('GET', site + '/' + doc)
        check(r2.status_code == 308 and r2.headers.get('Location') == '/tbox/' + doc and no_cookie(r2), 'PUB GET /' + doc + ' → 308 /tbox/' + doc + ' (site-layer rule) · Set-Cookie 0')
        r3 = req('GET', TB + '/' + doc + '?v=' + VER[doc], html=True)
        check(r3.status_code == 200 and no_cookie(r3), 'PUB ?v=<listed version> 200')
        for bad in ('1999-01-01', '../x', '..%2Fconfig%2Flegal.json', '%2e%2e%2fcontent', '', 'privacy-' + VER['privacy']):
            rb = req('GET', TB + '/' + doc + '?v=' + bad, html=True)
            check(rb.status_code == 404 and no_cookie(rb), 'PUB ?v=' + (bad or '(empty)') + ' → 404 (only listed versions)')
        rb = req('GET', TB + '/' + doc + '?v[]=x')
        check(rb.status_code == 404, 'PUB ?v[]=x (array) → 404')
        signed = req('GET', TB + '/' + doc, sess=users['employee'], html=True)
        check(signed.status_code == 200 and no_cookie(signed) and 'claude_employee01' not in signed.text and signed.text == body,
              'PUB /tbox/' + doc + ' with a session cookie: same bytes as anonymous · no account data · Set-Cookie 0')
    check(req('GET', TB + '/privacy/../config/legal.json').status_code in (404,), 'PUB path traversal on route → 404 (router)')
    check(req('POST', TB + '/privacy').status_code == 405, 'PUB POST /tbox/privacy → 405')

    # ═════════ 가입 ═════════
    admin_id = users['admin']['id']
    code = secrets.token_hex(32)
    sql("INSERT INTO member_invites(code_hash,label,team_id,created_by,expires_at,max_uses) VALUES('" + hashlib.sha256(code.encode()).hexdigest() +
        "','qf13 synthetic'," + team_a + "," + str(admin_id) + ",now()+interval '1 day',5)")
    join = requests.get(TB + '/join', headers={'User-Agent': UA, 'Accept': 'text/html'}, timeout=20)
    jcsrf = re.search(r'data-join-csrf="([a-f0-9]{64})"', join.text).group(1)
    check(join.status_code == 200 and 'id="join-terms"' in join.text and 'id="join-age14"' in join.text and 'join-privacy-summary' in join.text,
          'JOIN page has terms + age14 checkboxes and the privacy summary notice')
    jh = {'User-Agent': UA, 'X-Join-CSRF': jcsrf, 'Cookie': '__Host-moa_join=' + jcsrf}
    def register(extra, name):
        sql("UPDATE member_rate_limits SET window_start=now()-interval '2 minutes'")
        body = {'invite': code, 'username': name, 'display_name': '합성 가입', 'password': PASSWORD}
        body.update(extra)
        for k in [k for k, v in body.items() if v is None]:
            del body[k]
        return requests.post(TB + '/api/register', json=body, headers=jh, timeout=40, allow_redirects=False)
    def accounts(name):
        return int(sql("SELECT count(*) FROM users WHERE username='" + name + "'"))
    before = int(sql('SELECT count(*) FROM user_consents'))
    for extra, label in (({}, 'both missing'), ({'agree_terms': True}, 'age14 missing'), ({'age14': True}, 'agree_terms missing'),
                         ({'agree_terms': False, 'age14': True}, 'agree_terms false'), ({'agree_terms': True, 'age14': False}, 'age14 false'),
                         ({'agree_terms': 'true', 'age14': 'true'}, 'string "true"'), ({'agree_terms': 1, 'age14': 1}, 'number 1')):
        r = register(extra, 'qf13_join_bad')
        check(r.status_code == 400 and accounts('qf13_join_bad') == 0, 'JOIN ' + label + ' → 400 · account 0')
    check(int(sql('SELECT count(*) FROM user_consents')) == before and sql("SELECT used_count FROM member_invites WHERE label='qf13 synthetic'") == '0',
          'JOIN rejected requests wrote no consent rows and did not use the invite')
    join_400 = register({'agree_terms': True}, 'qf13_join_bad').text
    r = register({'agree_terms': True, 'age14': True}, 'qf13_join_ok')
    join_201 = r.text
    check(r.status_code == 201 and accounts('qf13_join_ok') == 1, 'JOIN both true → 201 · account 1')
    jid = int(sql("SELECT id FROM users WHERE username='qf13_join_ok'"))
    check(consent_rows(jid) == 'terms:{t}:agree,privacy:{p}:agree,age14:{t}:agree'.format(t=VER['terms'], p=VER['privacy']),
          'JOIN user_consents: exactly three rows terms·privacy·age14 (agree · current versions)')
    check(sql("SELECT count(*) FROM user_consents WHERE user_id=" + str(jid) + " AND ip='127.0.0.1'") == '3', 'JOIN consent rows record the client IP')
    check(audit_count('consent.agree', jid) == 1 and sql("SELECT detail->>'via' FROM audit_logs WHERE action='consent.agree' AND target_id=" + str(jid)) == 'register',
          'JOIN audit consent.agree (via register) · 1 row')
    jcookie = None
    for c in r.headers.get('Set-Cookie', '').split(','):
        m = re.search(r'__Host-tbox_session=([0-9a-f]{64})', c)
        if m:
            jcookie = m.group(1)
    jsess = {'session': jcookie, 'csrf': requests.get(TB + '/api/me', headers={'User-Agent': UA, 'Cookie': '__Host-tbox_session=' + jcookie}, timeout=20).json()['csrf_token']}
    check(req('GET', TB + '/api/files', jsess).status_code == 200 and req('GET', CB + '/', jsess, html=True).status_code == 200,
          'JOIN new member is not gated (files API 200 · community 200)')

    # ═════════ 기존 회원 ═════════
    mid = make_user('qf13_member')
    lr = requests.post(TB + '/api/login', json={'username': 'qf13_member', 'password': PASSWORD}, headers={'User-Agent': UA}, timeout=40)
    check(lr.status_code == 200 and lr.json().get('next') is None, 'GATE existing member without consent rows logs in (200 · next null)')
    msess_token = re.search(r'__Host-tbox_session=([0-9a-f]{64})', lr.headers.get('Set-Cookie', '')).group(1)
    me = requests.get(TB + '/api/me', headers={'User-Agent': UA, 'Cookie': '__Host-tbox_session=' + msess_token}, timeout=20)
    check(me.status_code == 200, 'GATE GET /api/me allowed while consent is missing')
    msess = {'session': msess_token, 'csrf': me.json()['csrf_token']}
    for path, loc in (('/', '/tbox/consent'), ('/settings', '/tbox/consent?to=%2Ftbox%2Fsettings'), ('/messages', '/tbox/consent?to=%2Ftbox%2Fmessages')):
        r = req('GET', TB + path, msess, html=True)
        check(r.status_code == 302 and r.headers.get('Location') == loc, 'GATE screen /tbox' + path + ' → 302 ' + loc)
    for path, loc in (('/', '/tbox/consent?to=%2Fcommunity%2F'), ('/memories', '/tbox/consent?to=%2Fcommunity%2Fmemories'), ('/my-tasks', '/tbox/consent')):
        r = req('GET', CB + path, msess, html=True)
        check(r.status_code == 302 and r.headers.get('Location') == loc, 'GATE community screen /community' + path + ' → 302 ' + loc)
    def is_gate(r):
        try:
            d = r.json()
        except ValueError:
            return False
        return r.status_code == 403 and d.get('code') == 'consent_required' and d.get('next') == 'consent' and isinstance(d.get('error'), str)
    # 응답 본문 견본(개인 데이터·비밀 0 — 고정 문장 · 판 · 시각뿐) — API.md 와 보고에 원문으로 싣는다
    samples = {'403 consent_required (GET /tbox/api/files)': req('GET', TB + '/api/files', msess).text,
               '302 screen (GET /tbox/settings) Location': req('GET', TB + '/settings', msess, html=True).headers.get('Location')}
    for method, url, body in (('GET', TB + '/api/files', None), ('GET', TB + '/api/me/activity', None), ('POST', TB + '/api/folders', {'name': 'x'}),
                              ('GET', TB + '/api/messages', None), ('GET', CB + '/api/me/space', None), ('GET', CB + '/api/search?q=x', None),
                              ('POST', TB + '/api/messages/ai', {'thread_id': None, 'text': 'x', 'request_id': str(uuid.uuid4())})):
        r = req(method, url, msess, body)
        check(is_gate(r), 'GATE session API ' + method + ' ' + url.replace(site, '') + ' → 403 consent_required')
    check(req('GET', TB + '/api/me/consents', msess).json().get('required') is True, 'GATE GET /api/me/consents allowed · required true')
    cp = req('GET', TB + '/consent', msess, html=True)
    check(cp.status_code == 200 and 'id="consent-form"' in cp.text and 'data-member-page="consent"' in cp.text, 'GATE GET /tbox/consent allowed · shows the consent form')
    cpt = req('GET', TB + '/consent?to=%2Ftbox%2Fsettings', msess, html=True)
    check('data-consent-to="/tbox/settings"' in cpt.text, 'GATE consent page keeps an allow-listed ?to= (Http::returnPath)')
    cpx = req('GET', TB + '/consent?to=https%3A%2F%2Fevil.example%2F', msess, html=True)
    check(cpx.status_code == 200 and 'data-consent-to' not in cpx.text, 'GATE consent page drops a ?to= outside the allow-list (no open redirect)')
    check(audit_count('auth.gate', mid, 'consent_required') >= 10, 'GATE every blocked request leaves auth.gate|deny|consent_required')
    # P2-R1(RV-P2-1) — 동의하지 않은 사람의 탈퇴 길: 탈퇴 요청 두 창구만 관문을 지난다(재인증은 핸들러가 묻는다)
    check('id="consent-delete-request"' in cp.text and 'id="consent-logout"' in cp.text and '/tbox/assets/app.js?v=' in cp.text
          and 'id="tpl-icon-x"' in cp.text, 'R1 consent page (consent missing): 「탈퇴 요청」 next to 「로그아웃」 · loads the shared dialogs (app.js · ✕ icon template)')
    gate_n = audit_count('auth.gate', mid, 'consent_required')
    r = req('GET', TB + '/api/me/delete-request', msess)
    check(r.status_code == 200 and r.json() == {'request': None}, 'R1 GET /api/me/delete-request passes the consent gate (200 · request null)')
    r = req('POST', TB + '/api/me/delete-request', msess, {'confirm': True})
    samples['403 POST /tbox/api/me/delete-request (consent missing · no fresh re-auth)'] = r.text
    check(r.status_code == 403 and r.json().get('next') == 'reauth' and 'code' not in r.json()
          and audit_count('auth.gate', mid, 'consent_required') == gate_n and audit_count('auth.gate', mid, 'reauth_required') == 1
          and sql('SELECT count(*) FROM account_deletion_requests WHERE user_id=' + str(mid)) == '0',
          'R1 POST /api/me/delete-request passes the consent gate and reaches the handler: 403 next:reauth (no consent_required) · no row')
    r = req('POST', TB + '/api/me/reauth', msess, {'password': PASSWORD})
    samples['403 POST /tbox/api/me/reauth (consent missing)'] = r.text
    check(is_gate(r), 'R1 GAP (decision pending): POST /api/me/reauth while consent is missing → 403 consent_required (only the two delete-request routes were opened)')
    for method, url, body in (('GET', TB + '/api/files', None), ('GET', TB + '/api/admin/deletion-requests', None), ('GET', CB + '/api/me/space', None)):
        check(is_gate(req(method, url, msess, body)), 'R1 other session API still gated after opening the two routes: ' + method + ' ' + url.replace(site, '') + ' → 403 consent_required')
    # 개인 토큰 창구는 막지 않는다
    tok = secrets.token_hex(24)
    sql("INSERT INTO api_tokens(user_id,name,token_hash,hint,created_by,scope,expires_at) VALUES(" + str(mid) + ",'qf13','" +
        hashlib.sha256(tok.encode()).hexdigest() + "','" + tok[:6] + "'," + str(mid) + ",'read',now()+interval '1 day')")
    th = {'User-Agent': UA, 'X-API-Token': tok}
    check(requests.get(TB + '/api/files', headers=th, timeout=20).status_code == 200, 'GATE program token route GET /api/files → 200 (not gated)')
    check(requests.get(TB + '/api/whoami', headers=th, timeout=20).status_code == 200, 'GATE program token route GET /api/whoami → 200 (not gated)')
    ctok = secrets.token_hex(24)
    sql("INSERT INTO api_tokens(user_id,name,token_hash,hint,created_by,scope,expires_at) VALUES(" + str(mid) + ",'qf13c','" +
        hashlib.sha256(ctok.encode()).hexdigest() + "','" + ctok[:6] + "'," + str(mid) + ",'community.write',now()+interval '1 day')")
    check(requests.get(CB + '/api/me/space', headers={'User-Agent': UA, 'X-API-Token': ctok}, timeout=20).status_code == 200,
          'GATE community program token route GET /community/api/me/space → 200 (not gated)')
    check(is_gate(req('GET', CB + '/api/me/space', msess)), 'GATE the same community route with the session cookie → 403 consent_required')
    # 동의 창구 — CSRF · 본문 모양
    check(req('POST', TB + '/api/me/consents', msess, {'doc': 'terms'}, csrf=False).status_code == 403 and consent_rows(mid) == '',
          'CONSENT POST without CSRF → 403 · no rows')
    for body, label in (({}, 'empty'), ({'doc': 'privacy'}, 'privacy alone'), ({'doc': 'age14'}, 'age14'), ({'doc': 'terms', 'action': 'withdraw'}, 'terms withdraw'),
                        ({'doc': 'ai_overseas', 'action': 'nope'}, 'unknown action'), ({'doc': 'terms', 'user_id': admin_id}, 'extra key user_id'),
                        ({'doc': ['terms']}, 'array doc'), ({'doc': 'TERMS'}, 'upper case')):
        check(req('POST', TB + '/api/me/consents', msess, body).status_code == 400, 'CONSENT bad body (' + label + ') → 400')
    check(consent_rows(mid) == '', 'CONSENT rejected bodies wrote no rows')
    samples['200 GET /tbox/api/me/consents (before)'] = req('GET', TB + '/api/me/consents', msess).text
    r = req('POST', TB + '/api/me/consents', msess, {'doc': 'terms'})
    samples['200 POST /tbox/api/me/consents {"doc":"terms"}'] = r.text
    samples['400 POST /tbox/api/me/consents {"doc":"privacy"}'] = req('POST', TB + '/api/me/consents', msess, {'doc': 'privacy'}).text
    d = r.json()
    check(r.status_code == 200 and d['changed'] is True and d['required'] is False and d['ai_overseas'] is False, 'CONSENT {doc:terms} → 200 changed · required false · AI still not agreed')
    check(consent_rows(mid) == 'terms:{t}:agree,privacy:{p}:agree'.format(t=VER['terms'], p=VER['privacy']), 'CONSENT rows terms + privacy (current versions) — no age14 for existing members')
    check(audit_count('consent.agree', mid) == 1, 'CONSENT audit consent.agree · 1 row')
    r = req('POST', TB + '/api/me/consents', msess, {'doc': 'terms'})
    check(r.status_code == 200 and r.json()['changed'] is False and consent_rows(mid).count('agree') == 2 and audit_count('consent.agree', mid) == 1,
          'CONSENT repeat {doc:terms} → 200 changed false · no new rows · no new audit')
    check(req('GET', TB + '/api/files', msess).status_code == 200 and req('GET', TB + '/', msess, html=True).status_code == 200
          and req('GET', CB + '/', msess, html=True).status_code == 200 and req('GET', CB + '/api/me/space', msess).status_code == 200,
          'GATE after consent: t-box and community screens and APIs are normal')
    cpd = req('GET', TB + '/consent', msess, html=True)
    check(cpd.status_code == 200 and 'id="consent-delete-request"' not in cpd.text and '/assets/app.js' not in cpd.text and 'id="consent-done"' in cpd.text,
          'R1 consent page after consent: no 「탈퇴 요청」 there (the 「내 계정」 panel has it) · app.js not loaded')
    # 계정 절차가 먼저
    sid = make_user('qf13_steps', must_change=True)
    ssess = make_session(sid)
    r = req('GET', TB + '/api/files', ssess)
    check(r.status_code == 403 and r.json().get('next') == 'password_change', 'GATE first-login step comes first: API → 403 next password_change (not consent)')
    r = req('GET', TB + '/settings', ssess, html=True)
    check(r.status_code == 302 and r.headers.get('Location') == '/tbox/', 'GATE first-login step comes first: screen → 302 /tbox/ (no /consent loop)')
    check(audit_count('auth.gate', sid, 'consent_required') == 0, 'GATE no consent audit while the account step is pending')
    # 로그아웃은 언제나 열려 있다
    lsid = make_user('qf13_logout')
    lsess = make_session(lsid)
    check(req('POST', TB + '/api/logout', lsess, {}).status_code == 200 and req('GET', TB + '/api/me', lsess).status_code == 401,
          'GATE POST /api/logout allowed while consent is missing (session ends)')
    # 판이 바뀌면 다시 묻는다(사본의 목록에 다음 판을 더했다가 되돌린다)
    vsid = make_user('qf13_version', consents=('terms', 'privacy'))
    vsess = make_session(vsid)
    check(req('GET', TB + '/api/files', vsess).status_code == 200, 'VERSION member with current consent is not gated')
    lj = instance / 'config/legal.json'
    original = lj.read_text()
    newver = '9999-12-31'   # 어떤 실제 시행일보다 뒤인 판(목록은 오름차순이어야 한다 — 발행 날 자리 값 0000-00-00 이 실제 날짜로 바뀌어도 그대로 돈다)
    (instance / ('content/legal/terms-' + newver + '.html')).write_bytes((instance / 'content/legal/terms-{}.html'.format(VER['terms'])).read_bytes())
    data = json.loads(original)
    data['terms'].append({'version': newver, 'file': 'terms-' + newver + '.html'})
    lj.write_text(json.dumps(data))
    try:
        check(is_gate(req('GET', TB + '/api/files', vsess)), 'VERSION new terms version in the list → the member is gated again (403 consent_required)')
        old = req('GET', TB + '/terms?v=' + VER['terms'], html=True)
        check(old.status_code == 200 and '이전 판입니다' in old.text and ('?v=' + newver) in old.text, 'VERSION previous version stays readable (?v=) with the version list')
        check('지금 판입니다' in req('GET', TB + '/terms', html=True).text, 'VERSION /tbox/terms shows the new version as current')
        r = req('POST', TB + '/api/me/consents', vsess, {'doc': 'terms'})
        check(r.status_code == 200 and r.json()['required'] is False and consent_rows(vsid).endswith('terms:{}:agree,privacy:{}:agree'.format(newver, VER['privacy'])),
              'VERSION re-consent records the new terms version (and privacy again — one bundle)')
        check(req('GET', TB + '/api/files', vsess).status_code == 200, 'VERSION after re-consent: normal')
        bad = dict(data)
        bad['terms'] = data['terms'] + [{'version': '../../x', 'file': 'terms-x.html'}]
        lj.write_text(json.dumps(bad))
        check(req('GET', TB + '/terms', html=True).status_code == 500, 'VERSION malformed list entry (path-like version) → 500 closed (no file read)')
    finally:
        lj.write_text(original)
        (instance / ('content/legal/terms-' + newver + '.html')).unlink()

    # ═════════ AI 국외 전송 ═════════
    keydir = work / 'private-messenger-ai'
    keydir.mkdir(mode=0o700)
    (keydir / 'gemini.key').write_text('synthetic-key-for-disposable-test-only')
    (keydir / 'gemini.key').chmod(0o600)
    sql('UPDATE member_ai_settings SET enabled=true, image_enabled=true')
    (storage / 'transport-mode.json').write_text('{}')
    (storage / 'fake-key').write_text('registered')
    sql("UPDATE community.ai_settings SET external_data_mode='free_public', search_enabled=true")
    capture = storage / 'transport-capture.jsonl'
    ccapture = storage / 'fake-capture.jsonl'
    def provider_calls():
        a = len(capture.read_text().splitlines()) if capture.exists() else 0
        b = len(ccapture.read_text().splitlines()) if ccapture.exists() else 0
        return a + b
    def reset_rate():
        sql("UPDATE member_rate_limits SET window_start=now()-interval '2 minutes'")
    aid = make_user('qf13_ai', consents=('terms', 'privacy'))
    asess = make_session(aid)
    other = users['other']['id']
    # 사진 기록 하나(사진 1장) — 사진 이야기 시험 재료
    up = req('POST', CB + '/api/memories/media', asess, files={'file': ('합성사진.png', png_rgb(8, 8), 'application/octet-stream')})
    check(up.status_code == 201, 'AI setup: photo upload 201')
    mediaid = up.json()['id']
    pr = req('POST', CB + '/api/memories', asess, {'caption': '합성 기록', 'location': '합성 장소', 'trip': '검증', 'taken_on': '2026-10-07',
                                                   'visibility': 'private', 'media_ids': [mediaid], 'request_id': str(uuid.uuid4())})
    check(pr.status_code == 201, 'AI setup: memory post 201')
    pid = pr.json()['id']
    def ai_calls():
        reset_rate()
        rev = req('GET', CB + '/api/memories/' + pid, asess).json()['post']['revision']
        reset_rate()
        out = {
            'moa_ai': req('POST', TB + '/api/messages/ai', asess, {'thread_id': None, 'text': '합성 질문', 'request_id': str(uuid.uuid4())}),
        }
        reset_rate()
        out['assist'] = req('POST', TB + '/api/messages/assist', asess, {'action': 'polish', 'aspect': '1:1', 'language': 'ko', 'peer_id': other,
                                                                         'request_id': str(uuid.uuid4()), 'source_id': None, 'text': '다듬을 합성 문장'})
        reset_rate()
        out['story'] = req('POST', CB + '/api/memories/' + pid + '/caption', asess, {'consent': True, 'request_id': str(uuid.uuid4()), 'revision': rev,
                                                                                    'media_ids': [mediaid], 'tone': '담백하고 따뜻한', 'wish': '합성 요청', 'format': 'story'})
        reset_rate()
        out['community'] = req('POST', CB + '/api/ai/search', asess, {'q': '합성 찾기', 'confirmed': True})
        return out
    def is_ai_409(r):
        try:
            d = r.json()
        except ValueError:
            return False
        n = d.get('notice') or {}
        return (r.status_code == 409 and d.get('code') == 'ai_consent_required' and isinstance(d.get('error'), str)
                and isinstance(n.get('rows'), list) and any(row[0] == '받는 곳' and 'Google' in row[1] for row in n['rows']) and n.get('policy_url') == '/tbox/privacy#privacy-7')
    ledgers = ("SELECT (SELECT count(*) FROM member_ai_turns WHERE user_id={u})||','||(SELECT count(*) FROM member_ai_assists WHERE user_id={u})||','||"
               "(SELECT count(*) FROM community.ai_requests WHERE user_id={u})||','||(SELECT count(*) FROM community.ai_log WHERE user_id={u})").format(u=aid)
    calls0 = provider_calls()
    res = ai_calls()
    for k, r in res.items():
        check(is_ai_409(r), 'AI no ai_overseas: ' + k + ' → 409 ai_consent_required with the notice')
        samples['409 ' + k] = r.text
    check(provider_calls() == calls0 == 0, 'AI no ai_overseas: synthetic provider calls 0 (all four paths)')
    check(sql(ledgers) == '0,0,0,0', 'AI no ai_overseas: no AI ledger rows (turns · assists · community requests · community log)')
    check(req('GET', TB + '/api/me/consents', asess).json()['ai_overseas'] is False, 'AI state ai_overseas false')
    r = req('POST', TB + '/api/me/consents', asess, {'doc': 'ai_overseas'})
    check(r.status_code == 200 and r.json()['ai_overseas'] is True and audit_count('consent.agree', aid) == 1, 'AI agree → 200 · ai_overseas true · audit consent.agree')
    res = ai_calls()
    check(res['moa_ai'].status_code == 200 and res['moa_ai'].json()['turn']['state'] == 'complete', 'AI after agree: moa AI → 200 complete')
    check(res['assist'].status_code == 200 and res['assist'].json()['job']['state'] == 'complete', 'AI after agree: messenger AI tool → 200 complete')
    check(res['story'].status_code == 200 and res['story'].json()['state'] == 'complete', 'AI after agree: photo story → 200 complete')
    check(res['community'].status_code == 200 and isinstance(res['community'].json().get('results'), list), 'AI after agree: community AI search → 200')
    calls1 = provider_calls()
    check(calls1 == 4, 'AI after agree: synthetic provider received exactly 4 requests')
    r = req('POST', TB + '/api/me/consents', asess, {'doc': 'ai_overseas', 'action': 'withdraw'})
    check(r.status_code == 200 and r.json()['ai_overseas'] is False and audit_count('consent.withdraw', aid) == 1, 'AI withdraw → 200 · ai_overseas false · audit consent.withdraw')
    r = req('POST', TB + '/api/me/consents', asess, {'doc': 'ai_overseas', 'action': 'withdraw'})
    check(r.status_code == 200 and r.json()['changed'] is False and audit_count('consent.withdraw', aid) == 1, 'AI repeat withdraw → changed false · no new row')
    res = ai_calls()
    for k, r in res.items():
        check(is_ai_409(r), 'AI after withdraw: ' + k + ' → 409 again')
    check(provider_calls() == calls1, 'AI after withdraw: synthetic provider calls unchanged (0 new)')
    check(consent_rows(aid) == 'terms:{t}:agree,privacy:{p}:agree,ai_overseas:{p}:agree,ai_overseas:{p}:withdraw'.format(t=VER['terms'], p=VER['privacy']),
          'AI consent rows appended in order (agree → withdraw · nothing rewritten)')

    # ═════════ 기록 — 앱 계정으로 고치기·지우기 ═════════
    probe = work / 'append_only_probe.php'
    probe.write_text("""<?php
declare(strict_types=1);
require __DIR__ . '/tbox-v2/src/bootstrap.php';
if (!str_starts_with(Env::get('DB_NAME'), 'tbox_memories_test_')) { exit(3); }
$out = [];
$out['user'] = (string) Db::instance()->run('SELECT current_user')->fetchColumn();
foreach (['update' => "UPDATE user_consents SET action='withdraw' WHERE doc='ai_overseas'", 'delete' => 'DELETE FROM user_consents', 'truncate' => 'TRUNCATE user_consents'] as $k => $q) {
    try { Db::instance()->run($q); $out[$k] = 'allowed'; } catch (PDOException $e) { $out[$k] = (string) $e->getCode(); }
}
try { $out['select'] = Db::instance()->run('SELECT count(*) FROM user_consents')->fetchColumn() > 0 ? 'select-ok' : 'select-empty'; } catch (PDOException $e) { $out['select'] = (string) $e->getCode(); }
echo json_encode($out);
""")
    pr = subprocess.run(['php', str(probe)], cwd=work, capture_output=True, text=True, timeout=60)
    got = json.loads(pr.stdout)
    check(got['user'] == config['DB_USER'] and got['update'] == '42501' and got['delete'] == '42501' and got['truncate'] == '42501' and got['select'] == 'select-ok',
          'REC app account (' + got['user'] + '): UPDATE / DELETE / TRUNCATE user_consents → 42501 permission denied · SELECT works')
    for action in ('consent.agree', 'consent.withdraw'):
        s = sql("SELECT summary FROM audit_logs WHERE action='" + action + "' ORDER BY id DESC LIMIT 1")
        check(s != '' and '{' not in s, 'REC audit ' + action + ' has a filled summary sentence')
    check(sql("SELECT count(*) FROM audit_logs WHERE action='auth.gate' AND reason='consent_required' AND summary LIKE '%처리방침%'") != '0', 'REC audit auth.gate|deny|consent_required summary')
    samples['400 POST /tbox/api/register (age14 missing)'] = join_400
    samples['201 POST /tbox/api/register (both true)'] = join_201
    samples['audit summaries'] = sql("SELECT string_agg(DISTINCT action||'|'||result||'|'||coalesce(reason,'')||' => '||summary, E'\\n') FROM audit_logs WHERE action IN ('consent.agree','consent.withdraw') OR reason='consent_required'")
    (out / 'samples.json').write_text(json.dumps(samples, ensure_ascii=False, indent=1) + '\n')

    # ═════════ 화면 ═════════
    browser_suite(site, TB, CB, users, sql, make_user, make_session, storage, provider_calls, reset_rate, out)


def browser_suite(site, TB, CB, users, sql, make_user, make_session, storage, provider_calls, reset_rate, out):
    from playwright.sync_api import sync_playwright, expect
    host = '127.0.0.1'
    def cookie(sess, extra=()):
        c = [{'name': '__Host-tbox_session', 'value': sess['session'], 'domain': host, 'path': '/', 'secure': True, 'httpOnly': True, 'sameSite': 'Lax'}]
        for name, value in extra:
            c.append({'name': name, 'value': value, 'domain': host, 'path': '/', 'secure': False, 'httpOnly': False, 'sameSite': 'Lax'})
        return c
    def code_console(lines):
        return [t for t, url in lines if not (t.startswith('Failed to load resource') and url.endswith('/favicon.ico'))]
    def legal_ok(page, scope, label):
        links = page.locator(scope + ' .legal-links').first
        expect(links).to_be_visible()
        priv = links.locator('a[href="/tbox/privacy"]')
        terms = links.locator('a[href="/tbox/terms"]')
        bold = priv.locator('strong')
        ok = priv.count() == 1 and terms.count() == 1 and bold.count() == 1 and '개인정보 처리방침' in bold.inner_text() and '이용약관' in terms.inner_text()
        weight = page.evaluate("(el) => getComputedStyle(el).fontWeight", bold.element_handle())
        check(ok and int(weight) >= 600, 'UI footer links (' + label + '): 「개인정보 처리방침」(bold ' + str(weight) + ') · 「이용약관」 → /tbox/privacy · /tbox/terms')

    with sync_playwright() as p:
        browser = p.chromium.launch(executable_path=os.environ.get('CHROMIUM_EXECUTABLE') or None)
        # ── 바닥 링크 다섯 자리 ──
        anon = browser.new_context(viewport={'width': 1280, 'height': 900}, user_agent=UA)
        page = anon.new_page()
        lines = []
        page.on('console', lambda m: lines.append((m.text, m.location.get('url', ''))) if m.type == 'error' else None)
        page.goto(site + '/'); legal_ok(page, 'footer.moa-footer', 'launcher footer')
        page.goto(TB + '/login'); legal_ok(page, '.login__pane', 'login screen')
        page.goto(TB + '/join'); legal_ok(page, 'header.moa-nav', 'join screen (moa header)')
        check(page.locator('#join-privacy-summary a[href="/tbox/privacy"] strong').count() == 1 and page.locator('#join-terms-help a[href="/tbox/terms"]').count() == 1,
              'UI join screen: privacy summary links the full policy (bold) · terms full text link')
        # 가입 화면 — 체크 없이 제출하면 브라우저가 막고(편의) 서버 요청 0
        posted = []
        page.on('request', lambda r: posted.append(r.url) if r.method == 'POST' else None)
        for sel, val in (('#join-invite', 'a' * 64), ('#join-name', '화면 시험'), ('#join-username', 'qf13_ui_join'), ('#join-password', PASSWORD), ('#join-confirm', PASSWORD)):
            page.fill(sel, val)
        page.click('#join-form button[type=submit]')
        page.wait_for_timeout(300)
        check(posted == [] and page.evaluate("document.getElementById('join-terms').validity.valueMissing"), 'UI join without the two checks: browser blocks the submit (server enforcement tested above)')
        page.goto(TB + '/privacy'); legal_ok(page, 'footer.moa-footer', 'public privacy page footer')
        check(page.locator('h1.wiki-title').inner_text() == '모아(Qaredi) 개인정보 처리방침' and page.locator('#privacy-7').count() == 1, 'UI public privacy page renders the document (title · 제7조 anchor)')
        anon.close()
        mctx = browser.new_context(viewport={'width': 1280, 'height': 900}, user_agent=UA)
        mctx.add_cookies(cookie(users['employee']))
        page = mctx.new_page()
        page.goto(TB + '/welcome'); legal_ok(page, 'header.moa-nav', 'moa header (welcome)')
        page.goto(TB + '/'); legal_ok(page, 'aside.sidebar', 'shell sidebar bottom')
        foot = page.locator('aside.sidebar .sidebar__foot').bounding_box(); leg = page.locator('aside.sidebar > .legal-links').bounding_box()
        check(foot is not None and leg is not None and leg['y'] >= foot['y'] + foot['height'] - 1, 'UI shell: legal links sit right under the version row (sidebar bottom)')
        mctx.close()
        for url in (TB + '/privacy', TB + '/terms'):
            check(requests.get(url, headers={'User-Agent': UA}, timeout=20).status_code == 200, 'UI footer target ' + url.replace(site, '') + ' → 200')

        # ── 동의 화면: 360 · 412 · 820 · 1440 × 밝게 · 어둡게 × 고대비 ──
        hid = make_user('qf13_screen', high_contrast=True)
        for theme in ('light', 'dark'):
            for width in (360, 412, 820, 1440):
                hsess = make_session(hid)
                ctx = browser.new_context(viewport={'width': width, 'height': 900}, user_agent=UA, has_touch=width <= 412)
                ctx.add_cookies(cookie(hsess, (('tbox_theme', theme),)))
                pg = ctx.new_page()
                lines = []; errors = []
                pg.on('console', lambda m, lines=lines: lines.append((m.text, m.location.get('url', ''))) if m.type == 'error' else None)
                pg.on('pageerror', lambda e, errors=errors: errors.append(str(e)))
                resp = pg.goto(TB + '/settings')
                check(pg.url == TB + '/consent?to=%2Ftbox%2Fsettings' and resp.status == 200, 'UI ' + theme + ' ' + str(width) + ': /tbox/settings → consent screen (?to kept)')
                pg.wait_for_load_state('networkidle')
                over = pg.evaluate("() => ({doc: document.documentElement.scrollWidth - document.documentElement.clientWidth, "
                                   "items: [...document.querySelectorAll('body *')].filter(e => { const r = e.getBoundingClientRect(); return r.width > 0 && r.right > document.documentElement.clientWidth + 1; }).length})")
                contrast = pg.evaluate("document.documentElement.getAttribute('data-contrast')")
                theme_attr = pg.evaluate("document.documentElement.getAttribute('data-theme')")
                check(over['doc'] <= 0 and over['items'] == 0, 'UI ' + theme + ' ' + str(width) + ': consent screen horizontal overflow 0 (doc ' + str(over['doc']) + ' · elements ' + str(over['items']) + ')')
                check(contrast == 'high' and theme_attr == theme, 'UI ' + theme + ' ' + str(width) + ': high contrast on (data-contrast=high · data-theme=' + theme + ')')
                expect(pg.locator('#consent-terms')).to_be_visible(); expect(pg.locator('#consent-form button[type=submit]')).to_be_visible()
                box = pg.locator('#consent-form button[type=submit]').bounding_box()
                check(box['x'] >= 0 and box['x'] + box['width'] <= width and pg.locator('#consent-ai').is_hidden(), 'UI ' + theme + ' ' + str(width) + ': submit button inside the screen · AI section waits until terms are done')
                pg.screenshot(path=str(out / 'consent-{}-{}.png'.format(theme, width)), full_page=True)
                check(code_console(lines) == [] and errors == [], 'UI ' + theme + ' ' + str(width) + ': console errors 0 · page errors 0')
                ctx.close()
        # 동의 → 보던 곳으로
        ctx = browser.new_context(viewport={'width': 412, 'height': 900}, user_agent=UA, has_touch=True)
        csess = make_session(hid)
        ctx.add_cookies(cookie(csess))
        pg = ctx.new_page(); lines = []; errors = []
        pg.on('console', lambda m: lines.append((m.text, m.location.get('url', ''))) if m.type == 'error' else None)
        pg.on('pageerror', lambda e: errors.append(str(e)))
        pg.goto(TB + '/settings')
        pg.click('#consent-form button[type=submit]')
        pg.wait_for_timeout(300)
        check(pg.url.startswith(TB + '/consent') and sql("SELECT count(*) FROM user_consents WHERE user_id=" + str(hid)) == '0', 'UI submit without checks: stays · no rows')
        pg.check('#consent-terms'); pg.check('#consent-privacy')
        with pg.expect_navigation():
            pg.click('#consent-form button[type=submit]')
        pg.wait_for_load_state('networkidle')
        check(pg.url == TB + '/settings' and sql("SELECT string_agg(doc,',' ORDER BY id) FROM user_consents WHERE user_id=" + str(hid)) == 'terms,privacy',
              'UI agree on the consent screen → back to /tbox/settings · rows terms,privacy')
        pg.goto(TB + '/consent'); pg.wait_for_load_state('networkidle')
        expect(pg.locator('#consent-ai')).to_be_visible()
        check(pg.locator('#consent-ai .info-item').count() == 6 and pg.locator('#consent-ai-agree').is_visible(), 'UI consent screen after agreeing: AI overseas section (6 notice rows · agree button)')
        pg.click('#consent-ai-agree'); expect(pg.locator('#consent-ai-withdraw')).to_be_visible()
        check(pg.locator('#consent-ai-state').inner_text() == '동의함', 'UI AI agree button → 동의함')
        pg.click('#consent-ai-withdraw'); expect(pg.locator('#consent-ai-agree')).to_be_visible()
        check(pg.locator('#consent-ai-state').inner_text() == '동의하지 않음' and sql("SELECT string_agg(doc||':'||action,',' ORDER BY id) FROM user_consents WHERE user_id=" + str(hid)) ==
              'terms:agree,privacy:agree,ai_overseas:agree,ai_overseas:withdraw', 'UI AI withdraw button → 동의하지 않음 · rows appended')
        check(code_console(lines) == [] and errors == [], 'UI consent flow: console errors 0 · page errors 0')
        ctx.close()

        # ── P2-R1(RV-P2-1 · RV-P2-3) 동의하지 않은 회원의 「탈퇴 요청」 길 — 공용 확인 창 → 공용 재인증 창(탈퇴 문구) → 요청 ──
        did = make_user('qf13_delete')
        dsess = make_session(did)
        def del_rows():
            return sql('SELECT count(*) FROM account_deletion_requests WHERE user_id=' + str(did))
        ctx = browser.new_context(viewport={'width': 412, 'height': 900}, user_agent=UA, has_touch=True)
        ctx.add_cookies(cookie(dsess))
        pg = ctx.new_page(); lines = []; errors = []; apis = []
        pg.on('console', lambda m: lines.append((m.text, m.location.get('url', ''))) if m.type == 'error' else None)
        pg.on('pageerror', lambda e: errors.append(str(e)))
        pg.on('request', lambda r: apis.append(r.method + ' ' + r.url.replace(site, '').split('?')[0]) if '/api/' in r.url else None)
        pg.goto(TB + '/consent'); pg.wait_for_load_state('networkidle')
        expect(pg.locator('#consent-delete-request')).to_be_visible()
        check(pg.locator('#consent-logout').is_visible() and pg.locator('#consent-delete-state').is_hidden()
              and sorted(apis) == ['GET /tbox/api/me', 'GET /tbox/api/me/delete-request'],
              'UI R1 consent screen: 「탈퇴 요청」 beside 「로그아웃」 · on load only GET /api/me + GET /api/me/delete-request (app.js adds no calls)')
        pg.click('#consent-delete-request')
        confirm = pg.locator('#confirm-dialog')
        expect(confirm).to_be_visible()
        check(pg.locator('#confirm-dialog-title').inner_text() == '탈퇴를 요청할까요?' and confirm.locator('.modal__head button[aria-label="닫기"] svg').count() == 1
              and confirm.locator('.btn--danger').inner_text() == '탈퇴 요청' and del_rows() == '0',
              'UI R1 「탈퇴 요청」 → the shared confirm dialog (title · ✕ icon · red 「탈퇴 요청」) · nothing sent yet')
        pg.keyboard.press('Escape')
        expect(confirm).not_to_be_visible()
        check(del_rows() == '0' and 'POST /tbox/api/me/delete-request' not in apis, 'UI R1 closing the confirm dialog (Esc) sends nothing')
        pg.click('#consent-delete-request')
        confirm.locator('.btn--danger').click()
        pw_input = pg.locator('#reauth-dialog-form input[type=password]')
        expect(pw_input).to_be_visible()
        meta = pg.locator('dialog.modal[open]:has(#reauth-dialog-form) .modal__meta').inner_text()
        check(meta.startswith('탈퇴를 요청하려면 비밀번호를 다시 확인해 주세요') and '관리 기능' not in meta and del_rows() == '0'
              and apis.count('POST /tbox/api/me/delete-request') == 1,
              'UI R1 confirm → POST 403 next:reauth → the shared re-auth dialog says 「탈퇴를 요청하려면 비밀번호를 다시 확인해 주세요」 · no row yet')
        pg.screenshot(path=str(out / 'r1-consent-delete-reauth-412.png'))
        pw_input.fill(PASSWORD)
        pw_input.press('Enter')
        reauth_msg = pg.locator('dialog.modal[open]:has(#reauth-dialog-form) .modal__foot .msg')
        expect(reauth_msg).not_to_be_empty()
        check(reauth_msg.inner_text() == '이용약관 동의와 개인정보 처리방침 확인을 먼저 마쳐 주세요' and del_rows() == '0'
              and sql('SELECT count(*) FROM sessions WHERE user_id=' + str(did) + ' AND reauth_at IS NOT NULL') == '0',
              'UI R1 GAP (decision pending): the password cannot be confirmed from the consent screen — POST /api/me/reauth is gated (dialog shows the consent message) · no window · no row')
        pg.screenshot(path=str(out / 'r1-consent-delete-reauth-gap-412.png'))
        pg.keyboard.press('Escape')
        expect(pg.locator('#reauth-dialog-form')).to_have_count(0)
        # 재인증 창을 시험 DB 에서 연다(위 GAP — 창구로는 열 수 없다) → 같은 화면 길이 201 까지 가는지
        sql('UPDATE sessions SET reauth_at=now() WHERE user_id=' + str(did))
        pg.click('#consent-delete-request')
        confirm.locator('.btn--danger').click()
        expect(pg.locator('#consent-delete-state')).to_be_visible(timeout=15000)
        check('탈퇴를 요청했습니다' in pg.locator('#consent-delete-state').inner_text() and pg.locator('#consent-delete-request').is_hidden()
              and sql("SELECT count(*) FROM account_deletion_requests WHERE status='pending' AND user_id=" + str(did)) == '1'
              and sql('SELECT count(*) FROM user_consents WHERE user_id=' + str(did)) == '0',
              'UI R1 with a fresh re-auth window (opened in the test DB): confirm → request 201 → 「탈퇴를 요청했습니다」 · button hidden · still no consent rows')
        pg.screenshot(path=str(out / 'r1-consent-delete-requested-412.png'), full_page=True)
        pg.reload(); pg.wait_for_load_state('networkidle')
        expect(pg.locator('#consent-delete-state')).to_be_visible()
        over = pg.evaluate("() => document.documentElement.scrollWidth - document.documentElement.clientWidth")
        check(pg.locator('#consent-delete-request').is_hidden() and over <= 0, 'UI R1 reload: the consent screen shows the open request (GET) · horizontal overflow 0 at 412')
        check(code_console([l for l in lines if 'status of 403' not in l[0]]) == [] and errors == [],
              'UI R1 consent-screen withdrawal: console errors 0 besides the expected 403 resource lines · page errors 0')
        ctx.close()

        # ── 메신저 모아 AI: 409 → 동의 창 → 동의하면 같은 요청 한 번 다시 / 닫으면 호출 0 ──
        wid = make_user('qf13_window', consents=('terms', 'privacy'))
        wsess = make_session(wid)
        ctx = browser.new_context(viewport={'width': 1280, 'height': 900}, user_agent=UA)
        ctx.add_cookies(cookie(wsess))
        pg = ctx.new_page(); lines = []; errors = []
        pg.on('console', lambda m: lines.append((m.text, m.location.get('url', ''))) if m.type == 'error' else None)
        pg.on('pageerror', lambda e: errors.append(str(e)))
        pg.goto(TB + '/messages'); pg.wait_for_load_state('networkidle')
        pg.click('#message-ai'); pg.wait_for_timeout(500)
        before = provider_calls(); reset_rate()
        pg.fill('#message-text', '동의 창 시험 질문')
        pg.click('#message-send')
        dialog = pg.locator('dialog.modal[open]')
        expect(dialog).to_be_visible()
        check(dialog.locator('.info-item').count() == 6 and 'Google' in dialog.inner_text() and dialog.locator('a[href="/tbox/privacy#privacy-7"]').count() == 1
              and provider_calls() == before, 'UI messenger AI send without consent → consent dialog (6 rows · policy link) · provider calls 0')
        check(pg.evaluate("document.activeElement && document.activeElement.textContent") == '닫기', 'UI consent dialog first focus is 「닫기」')
        foot_order = dialog.locator('.modal__foot > *').evaluate_all("els => els.map(e => e.tagName + ':' + e.className + ':' + e.textContent)")
        check(len(foot_order) == 3 and foot_order[0].startswith('P:msg') and foot_order[1] == 'BUTTON:btn btn--quiet:닫기' and foot_order[2] == 'BUTTON:btn:동의하고 계속하기'
              and dialog.locator('.modal__head button[aria-label="닫기"]').count() == 1, 'UI consent dialog bottom: msg · 「닫기」(outline) · primary at the right end · ✕ in the head (DESIGN 2.7)')
        pg.screenshot(path=str(out / 'messenger-consent-dialog.png'))
        dialog.get_by_role('button', name='동의하고 계속하기').click()
        expect(pg.locator('dialog.modal[open]')).to_have_count(0)
        pg.wait_for_timeout(1200)
        check(provider_calls() == before + 1 and sql("SELECT count(*) FROM member_ai_turns WHERE user_id=" + str(wid) + " AND state='complete'") == '1'
              and sql("SELECT string_agg(doc||':'||action,',' ORDER BY id) FROM user_consents WHERE user_id=" + str(wid)) == 'terms:agree,privacy:agree,ai_overseas:agree',
              'UI agree in the dialog → consent row · the same question is sent once (provider 1 · turn complete)')
        sql("INSERT INTO user_consents(user_id,doc,version,action) SELECT " + str(wid) + ",'ai_overseas',version,'withdraw' FROM user_consents WHERE user_id=" + str(wid) + " AND doc='privacy' LIMIT 1")
        before = provider_calls(); reset_rate()
        pg.fill('#message-text', '두 번째 질문 — 닫기')
        pg.click('#message-send')
        expect(pg.locator('dialog.modal[open]')).to_be_visible()
        pg.keyboard.press('Escape')
        expect(pg.locator('dialog.modal[open]')).to_have_count(0)
        pg.wait_for_timeout(500)
        check(provider_calls() == before and '동의하지 않아' in pg.locator('#member-status').inner_text(), 'UI close the dialog (Esc) → no provider call · message explains AI is not used')
        check(code_console([l for l in lines if 'status of 409' not in l[0]]) == [] and errors == [], 'UI messenger: console errors 0 besides the expected 409 resource line · page errors 0')
        pg.screenshot(path=str(out / 'messenger-consent.png'))
        ctx.close()
        # 좁은 폭(412 터치)에서도 창이 화면 안 · 가로 넘침 0
        sql("INSERT INTO user_consents(user_id,doc,version,action) SELECT " + str(wid) + ",'ai_overseas',version,'withdraw' FROM user_consents WHERE user_id=" + str(wid) + " AND doc='privacy' LIMIT 1")
        ctx = browser.new_context(viewport={'width': 412, 'height': 900}, user_agent=UA, has_touch=True)
        ctx.add_cookies(cookie(make_session(wid)))
        pg = ctx.new_page(); errors = []
        pg.on('pageerror', lambda e: errors.append(str(e)))
        pg.goto(TB + '/messages'); pg.wait_for_load_state('networkidle')
        pg.click('#message-ai'); pg.wait_for_timeout(500); reset_rate()
        pg.fill('#message-text', '좁은 화면 질문'); pg.click('#message-send')
        dialog = pg.locator('dialog.modal[open]'); expect(dialog).to_be_visible()
        box = dialog.bounding_box()
        over = pg.evaluate("() => document.documentElement.scrollWidth - document.documentElement.clientWidth")
        check(box['x'] >= 0 and box['x'] + box['width'] <= 412 and over <= 0 and errors == [], 'UI 412: consent dialog inside the screen · horizontal overflow 0 · page errors 0')
        pg.screenshot(path=str(out / 'messenger-consent-dialog-412.png'))
        dialog.get_by_role('button', name='닫기').first.click()
        expect(pg.locator('dialog.modal[open]')).to_have_count(0)
        ctx.close()

        # ── 사진 이야기(memories.js) · 커뮤니티 AI(editor.js) — 같은 409 → 동의 창 → 같은 요청 한 번 다시 ──
        def wait_calls(target):
            for _ in range(60):
                if provider_calls() >= target:
                    return True
                time.sleep(0.1)
            return provider_calls() >= target
        uid2 = make_user('qf13_ui_ai', consents=('terms', 'privacy'))
        s2 = make_session(uid2)
        h2 = {'User-Agent': UA, 'Cookie': '__Host-tbox_session=' + s2['session'], 'X-CSRF-Token': s2['csrf']}
        media2 = requests.post(CB + '/api/memories/media', headers=h2, files={'file': ('합성사진.png', png_rgb(8, 8), 'application/octet-stream')}, timeout=40).json()['id']
        made = requests.post(CB + '/api/memories', headers=h2, timeout=40, json={'caption': '화면 시험 기록', 'location': '합성 장소', 'trip': '검증', 'taken_on': '2026-10-07',
                                                                               'visibility': 'private', 'media_ids': [media2], 'request_id': str(uuid.uuid4())})
        check(made.status_code == 201, 'UI story setup: private memory post with one photo')
        ctx = browser.new_context(viewport={'width': 1280, 'height': 900}, user_agent=UA)
        ctx.add_cookies(cookie(s2))
        pg = ctx.new_page(); lines = []; errors = []
        pg.on('console', lambda m: lines.append((m.text, m.location.get('url', ''))) if m.type == 'error' else None)
        pg.on('pageerror', lambda e: errors.append(str(e)))
        pg.goto(CB + '/memories?view=mine'); pg.wait_for_load_state('networkidle')
        pg.get_by_role('button', name='AI로 꾸미기').first.click()
        expect(pg.locator('#gemini-generate')).to_be_enabled()
        before = provider_calls(); reset_rate()
        pg.click('#gemini-generate')
        consent = pg.locator('dialog[open]').filter(has_text='국외 전송 동의')
        expect(consent).to_be_visible()
        check(provider_calls() == before and consent.locator('.info-item').count() == 6, 'UI photo story (memories.js): 409 → consent dialog over the studio · provider calls 0')
        consent.get_by_role('button', name='동의하고 계속하기').click()
        expect(consent).to_have_count(0)
        check(wait_calls(before + 1) and provider_calls() == before + 1 and pg.locator('#studio-dialog').is_visible(),
              'UI photo story: agree → the same request is sent once (provider 1) · the studio stays open with its inputs')
        pg.screenshot(path=str(out / 'story-after-consent.png'))
        ctx.close()
        sql("INSERT INTO user_consents(user_id,doc,version,action) SELECT " + str(uid2) + ",'ai_overseas',version,'withdraw' FROM user_consents WHERE user_id=" + str(uid2) + " AND doc='privacy' LIMIT 1")
        ctx = browser.new_context(viewport={'width': 1280, 'height': 900}, user_agent=UA)
        ctx.add_cookies(cookie(make_session(uid2)))
        pg = ctx.new_page()
        pg.on('console', lambda m: lines.append((m.text, m.location.get('url', ''))) if m.type == 'error' else None)
        pg.on('pageerror', lambda e: errors.append(str(e)))
        pg.goto(CB + '/ai'); pg.wait_for_load_state('networkidle')
        panel = pg.locator('#community-ai-center section.panel').first
        panel.locator('textarea').fill('합성 찾기')
        panel.locator('input[type=checkbox]').check()
        before = provider_calls()
        panel.get_by_role('button', name='뜻으로 찾기').click()
        consent = pg.locator('dialog[open]').filter(has_text='국외 전송 동의')
        expect(consent).to_be_visible()
        check(provider_calls() == before, 'UI community AI (editor.js): 409 → consent dialog · provider calls 0')
        consent.get_by_role('button', name='닫기').last.click()
        expect(consent).to_have_count(0)
        pg.wait_for_timeout(300)
        check(provider_calls() == before and '동의하지 않아' in panel.inner_text(), 'UI community AI: close → no provider call · the panel says AI is not used')
        panel.get_by_role('button', name='뜻으로 찾기').click()
        consent = pg.locator('dialog[open]').filter(has_text='국외 전송 동의')
        expect(consent).to_be_visible()
        consent.get_by_role('button', name='동의하고 계속하기').click()
        expect(consent).to_have_count(0)
        check(wait_calls(before + 1) and provider_calls() == before + 1, 'UI community AI: agree → the same search is sent once (provider 1)')
        expect(panel.locator('.msg').first).to_contain_text('완료했습니다')
        check(code_console([l for l in lines if 'status of 409' not in l[0]]) == [] and errors == [], 'UI story · community AI: console errors 0 besides the expected 409 lines · page errors 0')
        ctx.close()
        browser.close()


if __name__ == '__main__':
    main()
