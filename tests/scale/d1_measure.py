#!/usr/bin/env python3
"""규모 D1 — 측정만(앱 코드 변경 0). 합성 DB(S/M/L) · 실제 HTTP · 질의 수 · EXPLAIN (ANALYZE, BUFFERS).

격리: 이 저장소 사본을 /tmp 작업 폴더에 복사해 PHP 내장 서버로 띄우고, 같은 PostgreSQL 클러스터에
새 합성 DB `tbox_scale_d_<pid>` 를 만든다(시험 틀 방식: tbox_testdb · ~/.config/qaredi/pgpass-testdb).
운영 DB · 운영 파일 · 운영 계정 0. 끝나면(실패해도) 서버 정지 · DB DROP · 작업 폴더 삭제.

질의 수 · SQL 원문: pg_stat_statements 를 쓸 수 없는 계정이라 앱과 PostgreSQL 사이에 이 스크립트가 띄운
작은 프로토콜 중계(127.0.0.1, 평문 — PGSSLMODE=disable)를 두고 Parse/Bind/Execute 를 센다. 시간 측정은
중계 없는 서버(직결)로, 질의 수 · SQL 은 중계 서버로 따로 잰다(중계의 지연이 시간에 섞이지 않게).
"""
import argparse
import hashlib
import json
import os
import re
import secrets
import shutil
import signal
import socket
import statistics
import struct
import subprocess
import sys
import tempfile
import threading
import time
from pathlib import Path
from urllib.parse import urlencode

import requests

ROOT = Path(__file__).resolve().parents[2]
PGPASS = Path.home() / '.config/qaredi/pgpass-testdb'
DB_OPTS = ['-h', '127.0.0.1', '-p', '5432', '-U', 'tbox_testdb', '-w']
MIGRATIONS = [
    '2026-09-29_community_trust.sql', '2026-09-30_community_automation.sql',
    '2026-10-01_community_suggestions.sql', '2026-10-18_community_ai.sql',
    '2026-10-03_community_memories.sql', '2026-10-03_community_messenger.sql',
    '2026-10-03_memory_story.sql', '2026-10-14_memory_social.sql',
    '2026-10-14_optional_member_auth.sql', '2026-10-14_usage_guide.sql',
    '2026-10-14_travel_plans.sql', '2026-10-14_travel_scopes.sql',
    '2026-10-15_remote_mcp.sql', '2026-10-19_qf12_work.sql',
    '2026-10-21_privacy_retention.sql', '2026-10-22_owner_fix.sql', '2026-10-23_feedback_indexes.sql',
    '2026-10-11_product_spaces.sql', '2026-10-13_community_page_attachments.sql',
    '2026-10-14_community_cafe.sql', '2026-10-14_task_start_date.sql', '2026-10-14_invite_requests.sql',
]
# 운영에 반영된 규모 A · B 색인(시험 틀 기본 목록에는 없다 — 운영과 같은 색인으로 잰다). 한 번만(트리거 생성은 재실행 불가).
SCALE_MIGRATIONS = ['2026-10-24_scale_comments.sql', '2026-10-25_scale_board_counts.sql', '2026-10-25_scale_board_indexes.sql']
OWNER_ADAPT = ('2026-10-01_community_suggestions.sql', '2026-10-18_community_ai.sql',
               '2026-10-21_privacy_retention.sql', '2026-10-11_product_spaces.sql')
# 기획 3장 — 데이터 단계(누적 목표). L 은 디스크에 맞춰 l_factor 로 줄일 수 있다(줄이면 보고서에 기록).
TIERS = {
    'S': dict(users=200, teams=10, pages=10_000, comments=50_000, prop_events=50_000, notifications=50_000,
              audit=100_000, stories=10_000, media=10_000, story_comments=10_000, files=10_000, reports=100, invites=100),
    'M': dict(users=1_000, teams=30, pages=100_000, comments=500_000, prop_events=500_000, notifications=500_000,
              audit=1_000_000, stories=100_000, media=100_000, story_comments=100_000, files=100_000, reports=1_000, invites=1_000),
    'L': dict(users=2_000, teams=50, pages=1_000_000, comments=3_000_000, prop_events=3_000_000, notifications=3_000_000,
              audit=5_000_000, stories=500_000, media=500_000, story_comments=500_000, files=500_000, reports=10_000, invites=10_000),
}


def free_gb():
    st = os.statvfs('/')
    return st.f_bavail * st.f_frsize / 1024 ** 3


def env_values(path):
    out = {}
    for line in path.read_text().splitlines():
        if line.strip() and not line.lstrip().startswith('#') and '=' in line:
            k, v = line.strip().split('=', 1)
            out[k] = v.strip().strip('"\'')
    return out


def private_write(path, text):
    path.write_text(text)
    path.chmod(0o600)


class Pg:
    def __init__(self, db):
        self.db = db
        self.env = {k: v for k, v in os.environ.items() if not k.startswith('PG')}
        self.env['PGPASSFILE'] = str(PGPASS)

    def cmd(self, program, tail, **kw):
        return subprocess.run([program] + DB_OPTS + tail, env=self.env, check=True,
                              stdout=subprocess.PIPE, stderr=subprocess.PIPE, **kw)

    def sql(self, text, db=None, timeout=None):
        r = subprocess.run(['psql'] + DB_OPTS + ['-X', '-At', '-d', db or self.db, '-v', 'ON_ERROR_STOP=1'],
                           input=text.encode(), env=self.env, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                           timeout=timeout)
        if r.returncode:
            raise RuntimeError('psql failed: ' + r.stderr.decode(errors='replace')[-1500:])
        return r.stdout.decode().strip()

    def file(self, path, variables=()):
        tail = ['-X', '-d', self.db, '-v', 'ON_ERROR_STOP=1']
        for k, v in variables:
            tail += ['-v', '%s=%s' % (k, v)]
        return self.cmd('psql', tail + ['-f', str(path)])


# ─────────────────────────── PostgreSQL 프로토콜 중계(질의 수 · SQL 원문) ───────────────────────────
class PgProxy:
    """평문 v3 프로토콜만. 프런트엔드 Parse/Bind/Execute/Query 와 백엔드 ReadyForQuery 를 읽어 기록한다."""

    def __init__(self, port):
        self.port = port
        self.lock = threading.Lock()
        self.events = []
        self.sock = socket.socket()
        self.sock.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        self.sock.bind(('127.0.0.1', port))
        self.sock.listen(16)
        self.alive = True
        self.open_socks = []
        threading.Thread(target=self.accept, daemon=True).start()

    def close(self):
        self.alive = False
        for s in [self.sock] + self.open_socks:
            try:
                s.shutdown(socket.SHUT_RDWR)
            except OSError:
                pass
            try:
                s.close()
            except OSError:
                pass

    def mark(self):
        with self.lock:
            return len(self.events)

    def since(self, n):
        with self.lock:
            return list(self.events[n:])

    def accept(self):
        while self.alive:
            try:
                client, _ = self.sock.accept()
            except OSError:
                return
            threading.Thread(target=self.session, args=(client,), daemon=True).start()

    @staticmethod
    def recv_exact(s, n):
        buf = b''
        while len(buf) < n:
            chunk = s.recv(n - len(buf))
            if not chunk:
                raise EOFError
            buf += chunk
        return buf

    def session(self, client):
        server = socket.create_connection(('127.0.0.1', 5432))
        self.open_socks += [client, server]
        for s in (client, server):  # 작은 메시지를 하나씩 넘기므로 Nagle 지연(40ms)을 끈다
            s.setsockopt(socket.IPPROTO_TCP, socket.TCP_NODELAY, 1)
        state = dict(stmts={}, pending=[], current=None)
        cond = threading.Lock()

        def backend():
            try:
                while True:
                    head = self.recv_exact(server, 5)
                    kind, ln = head[:1], struct.unpack('!I', head[1:])[0]
                    body = self.recv_exact(server, ln - 4)
                    client.sendall(head + body)
                    if kind in (b'Z', b'E'):
                        now = time.perf_counter()
                        with cond:
                            done = state['pending']
                            state['pending'] = []
                        for ev in done:
                            ev['ms'] = round((now - ev['t0']) * 1000, 3)
                            ev['error'] = kind == b'E'
                            del ev['t0']
                            with self.lock:
                                self.events.append(ev)
            except (EOFError, OSError):
                pass
            finally:
                for s in (client, server):
                    try:
                        s.close()
                    except OSError:
                        pass

        try:
            # 시작 메시지(종류 바이트 없음). SSL/GSS 요청이면 'N' 으로 거절하고 다음 시작 메시지를 받는다.
            while True:
                ln = struct.unpack('!I', self.recv_exact(client, 4))[0]
                body = self.recv_exact(client, ln - 4)
                code = struct.unpack('!I', body[:4])[0]
                if code in (80877103, 80877104):
                    client.sendall(b'N')
                    continue
                server.sendall(struct.pack('!I', ln) + body)
                break
            threading.Thread(target=backend, daemon=True).start()
            while True:
                head = self.recv_exact(client, 5)
                kind, ln = head[:1], struct.unpack('!I', head[1:])[0]
                body = self.recv_exact(client, ln - 4)
                if kind == b'P':
                    name, rest = body.split(b'\0', 1)
                    query, _ = rest.split(b'\0', 1)
                    state['stmts'][name] = query.decode(errors='replace')
                elif kind == b'B':
                    portal, rest = body.split(b'\0', 1)
                    stmt, rest = rest.split(b'\0', 1)
                    nf = struct.unpack('!H', rest[:2])[0]
                    fmts = struct.unpack('!%dH' % nf, rest[2:2 + 2 * nf]) if nf else ()
                    pos = 2 + 2 * nf
                    npar = struct.unpack('!H', rest[pos:pos + 2])[0]
                    pos += 2
                    params = []
                    for i in range(npar):
                        plen = struct.unpack('!i', rest[pos:pos + 4])[0]
                        pos += 4
                        if plen < 0:
                            params.append(None)
                        else:
                            raw = rest[pos:pos + plen]
                            pos += plen
                            fmt = fmts[i] if len(fmts) > 1 else (fmts[0] if fmts else 0)
                            params.append(raw.decode(errors='replace') if fmt == 0 else '\\x' + raw.hex())
                    state['current'] = dict(sql=state['stmts'].get(stmt, ''), params=params)
                elif kind == b'E':
                    ev = dict(state['current'] or dict(sql='', params=[]))
                    ev['t0'] = time.perf_counter()
                    with cond:
                        state['pending'].append(ev)
                elif kind == b'Q':
                    ev = dict(sql=body.rstrip(b'\0').decode(errors='replace'), params=[], simple=True,
                              t0=time.perf_counter())
                    with cond:
                        state['pending'].append(ev)
                server.sendall(head + body)
                if kind == b'X':
                    break
        except (EOFError, OSError, ValueError):
            pass
        finally:
            for s in (client, server):
                try:
                    s.close()
                except OSError:
                    pass


def control_sql(text):
    t = text.strip().upper()
    return t in ('BEGIN', 'COMMIT', 'ROLLBACK', 'DISCARD ALL') or t.startswith('SET ') or t.startswith('SAVEPOINT') \
        or t.startswith('RELEASE SAVEPOINT') or t.startswith('ROLLBACK TO')


# ─────────────────────────────────────── 격리 환경 ───────────────────────────────────────
class Harness:
    def __init__(self, port, proxy_port):
        if str(ROOT).startswith('/var/www'):
            raise SystemExit('Operating source refused — run from the isolated worktree')
        for p in (port, port + 1, proxy_port):
            with socket.socket() as s:
                if s.connect_ex(('127.0.0.1', p)) == 0:
                    raise SystemExit('Port %d occupied; nothing stopped' % p)
        self.port, self.proxy_port = port, proxy_port
        self.db = 'tbox_scale_d_%d' % os.getpid()
        self.pg = Pg(self.db)
        self.work = Path(tempfile.mkdtemp(prefix='qaredi-scale-d-', dir='/tmp'))
        self.work.chmod(0o700)
        self.created = False
        self.servers = []
        self.proxy = None
        self.secrets = []

    def setup_db(self):
        password = None
        for line in PGPASS.read_text().splitlines():
            f = line.split(':', 4)
            if len(f) == 5 and f[3] == 'tbox_testdb':
                password = f[4]
        if password is None:
            raise SystemExit('Dedicated test DB credentials unavailable')
        self.secrets.append(password)
        if not self.db.startswith('tbox_scale_d_'):
            raise RuntimeError('DB name guard')
        self.pg.cmd('createdb', ['-O', 'tbox_testdb', self.db])
        self.created = True
        base = self.work / 'direct'
        shutil.copytree(ROOT, base, ignore=shutil.ignore_patterns('.git', '.env', '__pycache__', 'node_modules'))

        def sql_file(path):
            if path.name in OWNER_ADAPT:
                adapted = self.work / ('owner-' + path.name)
                adapted.write_text(path.read_text().replace('OWNER TO tbox_owner;', 'OWNER TO tbox_testdb;')
                                   .replace('SET LOCAL ROLE tbox_owner;', 'SET LOCAL ROLE tbox_testdb;'))
                path = adapted
            return self.pg.file(path)
        logs = [sql_file(base / 'db/schema.sql').stdout]
        self.pg.sql('CREATE SCHEMA community;')
        logs.append(sql_file(base / 'db/community/schema.sql').stdout)
        for _ in range(2):  # 시험 틀과 같이 두 번(재적용 안전) — 규모 색인은 IF NOT EXISTS
            for m in MIGRATIONS:
                logs.append(sql_file(base / 'db/migrations' / m).stdout)
        for m in SCALE_MIGRATIONS:
            logs.append(sql_file(base / 'db/migrations' / m).stdout)
        grants = (base / 'db/grants.sql').read_text().replace('tbox_re', self.db)
        if 'DATABASE tbox_re ' in grants:
            raise RuntimeError('grant guard')
        (self.work / 'grants.sql').write_text(grants)
        logs.append(sql_file(self.work / 'grants.sql').stdout)
        logs.append(sql_file(base / 'db/community/grants.sql').stdout)
        return b''.join(logs)

    def write_instances(self):
        config = env_values(ROOT / '.env.example')
        # 연결 열쇠만(시험 틀과 같음) — 앱 역할(tbox_app, 15s 문장 제한)로 이 합성 DB 에 붙는다.
        conn = env_values(Path('/var/www/qaredi/tbox-v2/.env'))
        for k in ('DB_HOST', 'DB_PORT', 'DB_USER', 'DB_PASS'):
            config[k] = conn[k]
        self.secrets.append(conn['DB_PASS'])
        (self.work / 'storage').mkdir(mode=0o700)
        (self.work / 'logs').mkdir(mode=0o700)
        app_secret = secrets.token_hex(32)
        self.secrets.append(app_secret)
        config.update(DB_NAME=self.db, APP_SECRET=app_secret, STORAGE_DIR=str(self.work / 'storage'),
                      AUDIT_LOG_FILE=str(self.work / 'logs/audit.log'), API_TOKEN=secrets.token_hex(24),
                      API_TOKEN_USER='d1_admin', SCAN_ENABLED='0', ALERT_COMMAND='', COMMUNITY_GPT_ENABLED='0',
                      ASSISTANT_ENABLED='0')
        self.config = config
        direct = self.work / 'direct'
        proxied = self.work / 'proxied'
        shutil.copytree(direct, proxied)
        for inst, port, dbport in ((direct, self.port, config['DB_PORT']), (proxied, self.port + 1, str(self.proxy_port))):
            c = dict(config, APP_URL='http://127.0.0.1:%d' % port, DB_HOST='127.0.0.1', DB_PORT=dbport)
            private_write(inst / '.env', ''.join('%s=%s\n' % kv for kv in c.items()))
        return direct

    def start(self):
        self.proxy = PgProxy(self.proxy_port)
        log = (self.work / 'logs/php.log').open('wb')
        env = {k: v for k, v in os.environ.items() if k != 'PHP_CLI_SERVER_WORKERS' and not k.startswith('PG')}
        env['PGSSLMODE'] = 'disable'  # 두 서버 같은 조건(중계는 평문만 읽는다)
        for inst, port in ((self.work / 'direct', self.port), (self.work / 'proxied', self.port + 1)):
            p = subprocess.Popen(['php', '-d', 'opcache.enable_cli=1', '-d', 'opcache.validate_timestamps=0',
                                  '-S', '127.0.0.1:%d' % port, '-t', 'public', 'tests/community/export_router.php'],
                                 cwd=inst, env=env, stdout=log, stderr=log, start_new_session=True)
            self.servers.append(p)
            for _ in range(80):
                if p.poll() is not None:
                    raise RuntimeError('PHP server stopped')
                with socket.socket() as s:
                    if s.connect_ex(('127.0.0.1', port)) == 0:
                        break
                time.sleep(0.1)
            else:
                raise RuntimeError('PHP startup timed out')

    def stop(self):
        for p in self.servers:
            try:
                os.killpg(p.pid, signal.SIGTERM)
                p.wait(timeout=5)
            except (ProcessLookupError, subprocess.TimeoutExpired):
                try:
                    os.killpg(p.pid, signal.SIGKILL)
                except ProcessLookupError:
                    pass
        self.servers = []
        if self.proxy:
            self.proxy.close()
            self.proxy = None

    def teardown(self):
        self.stop()
        if self.created:
            try:  # 이 스크립트 자신(tbox_testdb)의 남은 연결 — 중단된 생성 · EXPLAIN
                Pg('postgres').sql("SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname='%s' AND usename=current_user "
                                   "AND pid<>pg_backend_pid()" % self.db, timeout=30)
            except Exception:
                pass
            # 앱 역할(tbox_app)의 영속 연결은 PHP 가 멈추면 닫힌다 — 남은 연결이 사라질 때까지 다시 시도
            last = ''
            for attempt in range(150):  # 앱 연결이 긴 문장(최대 15초)을 끝내고 닫힐 때까지 — 최대 5분
                try:
                    self.pg.cmd('dropdb', ['--if-exists', self.db])
                    self.created = False
                    break
                except subprocess.CalledProcessError as e:
                    last = (e.stderr or b'').decode(errors='replace')[-300:]
                    try:
                        Pg('postgres').sql("SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname='%s' "
                                           "AND usename=current_user AND pid<>pg_backend_pid()" % self.db, timeout=30)
                    except Exception:
                        pass
                    time.sleep(2)
            if self.created:
                print('WARNING dropdb failed — synthetic DB %s left; drop it manually: %s' % (self.db, last), flush=True)
        shutil.rmtree(self.work, ignore_errors=True)

    def scrub(self, text):
        for s in self.secrets:
            if s:
                text = text.replace(s, '[PRIVATE]')
        return text


UNLOGGED_SQL = r"""
DO $$
DECLARE r record; progress boolean; left_n int; saved text[] := '{}'; i int;
BEGIN
  IF current_database() NOT LIKE 'tbox\_scale\_d\_%' THEN RAISE EXCEPTION 'synthetic DB guard'; END IF;
  FOR pass IN 1..2 LOOP
    progress := true;
    WHILE progress LOOP
      progress := false;
      FOR r IN SELECT c.oid::regclass AS t FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
                WHERE c.relkind = 'r' AND c.relpersistence = 'p' AND n.nspname IN ('public', 'community') LOOP
        BEGIN
          EXECUTE format('ALTER TABLE %s SET UNLOGGED', r.t);
          progress := true;
        EXCEPTION WHEN others THEN NULL;  -- 아직 영구 표가 참조 중 — 다음 바퀴
        END;
      END LOOP;
    END LOOP;
    EXIT WHEN pass = 2;
    -- 서로 참조하는 고리(예: pages ↔ databases): 빈 표에서 외래키를 잠깐 떼고 바꾼 뒤 같은 이름 · 정의로 다시 붙인다
    FOR r IN SELECT con.conname, con.conrelid::regclass AS t, pg_get_constraintdef(con.oid) AS def
               FROM pg_constraint con JOIN pg_class c ON c.oid = con.conrelid JOIN pg_class f ON f.oid = con.confrelid
              WHERE con.contype = 'f' AND c.relpersistence = 'p' AND f.relpersistence = 'p'
                AND c.relnamespace IN ('public'::regnamespace, 'community'::regnamespace) LOOP
      saved := saved || format('ALTER TABLE %s ADD CONSTRAINT %I %s', r.t, r.conname, r.def);
      EXECUTE format('ALTER TABLE %s DROP CONSTRAINT %I', r.t, r.conname);
    END LOOP;
  END LOOP;
  FOR i IN 1..coalesce(array_length(saved, 1), 0) LOOP EXECUTE saved[i]; END LOOP;
  SELECT count(*) INTO left_n FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
   WHERE c.relkind = 'r' AND c.relpersistence = 'p' AND n.nspname IN ('public', 'community');
  RAISE NOTICE 'still logged: %, foreign keys re-added: %', left_n, coalesce(array_length(saved, 1), 0);
END $$;
SELECT count(*) FILTER (WHERE relpersistence = 'u') || '/' || count(*) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
 WHERE c.relkind = 'r' AND n.nspname IN ('public', 'community');
"""


def make_unlogged(pg):
    """합성 DB 의 표를 UNLOGGED 로 — 대량 생성의 WAL(공유 클러스터 · 디스크)을 없앤다. 읽기 계획 · 속도는 같다."""
    return pg.sql(UNLOGGED_SQL, timeout=600)


def seed(pg, targets, consents, cap_gb, floor_gb, boards=24):
    """d1_seed.sql 을 돌리며 디스크 · DB 크기를 지켜본다 — 넘으면 자기 문장을 취소하고 멈춘다."""
    vars_ = dict(targets, boards=boards, consent_terms=consents['terms'], consent_privacy=consents['privacy'],
                 consent_age14=consents['age14'], consent_ai=consents['ai_overseas'])
    tail = ['-X', '-d', pg.db, '-v', 'ON_ERROR_STOP=1']
    for k, v in sorted(vars_.items()):
        tail += ['-v', '%s=%s' % (k, v)]
    t0 = time.time()
    proc = subprocess.Popen(['psql'] + DB_OPTS + tail + ['-f', str(ROOT / 'tests/scale/d1_seed.sql')], env=pg.env,
                            stdout=subprocess.PIPE, stderr=subprocess.PIPE, start_new_session=True)
    low, peak, abort = 99.0, 0.0, None
    last_size = 0
    try:
        return _seed_wait(pg, proc, cap_gb, floor_gb, t0)
    except BaseException:
        # 중단(Ctrl-C · 오류)이면 생성 문장을 취소하고 psql 을 닫는다 — 끝 정리의 DROP 이 기다리지 않게
        try:
            pg.sql("SELECT pg_cancel_backend(pid) FROM pg_stat_activity WHERE datname=current_database() AND pid<>pg_backend_pid() "
                   "AND usename=current_user", timeout=30)
        except Exception:
            pass
        proc.kill()
        raise


def _seed_wait(pg, proc, cap_gb, floor_gb, t0):
    low, peak, abort = 99.0, 0.0, None
    last_size = 0
    while proc.poll() is None:
        time.sleep(3)
        low = min(low, free_gb())
        if time.time() - last_size > 15:
            last_size = time.time()
            try:
                peak = max(peak, int(pg.sql('SELECT pg_database_size(current_database())', timeout=30)) / 1024 ** 3)
            except Exception:
                pass
        if low < floor_gb or peak > cap_gb:
            abort = 'free %.2fGB / db %.2fGB' % (low, peak)
            pg.sql("SELECT pg_cancel_backend(pid) FROM pg_stat_activity WHERE datname=current_database() AND pid<>pg_backend_pid() "
                   "AND usename=current_user")
            proc.wait(timeout=60)
            break
    out, err = proc.communicate()
    if abort:
        raise RuntimeError('disk guard stopped seeding: ' + abort)
    if proc.returncode:
        raise RuntimeError('seed failed: ' + err.decode(errors='replace')[-1500:])
    t1 = time.time()
    pg.sql('SET statement_timeout = 0;\nVACUUM (ANALYZE);', timeout=7200)
    size = int(pg.sql('SELECT pg_database_size(current_database())')) / 1024 ** 3
    return dict(seed_s=round(t1 - t0, 1), vacuum_s=round(time.time() - t1, 1), min_free_gb=round(min(low, free_gb()), 2),
                db_gb=round(size, 3), peak_db_gb=round(max(peak, size), 3))


# ─────────────────────────────────────── 창구 목록 ───────────────────────────────────────
ROUTE_RE = re.compile(r"\[\s*'GET'\s*,\s*'([^']+)'\s*,\s*'([^']+)'\s*,\s*([^\]]+?)\s*\]")


def route_inventory():
    """라우트 표 두 곳의 GET 전부(+ 런처 `/`)."""
    out = [dict(key='GET /', handler='src/site/launcher_page.php', policy='public(세션 읽기)')]
    for src, prefix in (('public/index.php', '/tbox'), ('src/community/routes.php', '/community')):
        for m in ROUTE_RE.finditer((ROOT / src).read_text()):
            path, handler, policy = m.groups()
            if not path.startswith('/'):
                continue  # 메서드 목록 배열 같은 표 밖 글자
            out.append(dict(key='GET ' + (prefix + path if path != '/' else prefix + '/'), handler=handler,
                            policy=policy.replace('Authz::POLICY_', '').strip("'").lower() if 'POLICY' in policy else policy.strip("'")))
    return out


# 분류: list(목록) · cursor(커서 목록) · offset(OFFSET 목록) · search(검색) · home(홈) · count(개수) · detail(상세) · other(측정 제외)
# 값: (분류, 계정 'emp'|'admin', 경로(자리표시 채움 · 질의 포함) 또는 None, 메모)
#   자리표시: {board} 큰 게시판 · {board2} 작은 게시판 · {hot} 댓글 많은 게시판 글 · {doc} 직원 문서 · {task} 직원 작업
#             {story} 볼 수 있는 이야기 · {owner} 이야기 주인 계정명 · {file} 볼 수 있는 파일 · {folder} 폴더 · {team} 팀 번호
#             {space} 직원 개인 공간 번호 · {uid} 직원 사람 번호
D = 'deep'
SPEC = {
    'GET /': ('home', 'emp', '/', '런처(로그인 상태)'),
    # ── t-box ──
    'GET /tbox/': ('home', 'emp', '/tbox/', 't-box 홈 화면'),
    'GET /tbox/api/me': ('count', 'emp', '/tbox/api/me', '내 정보 · 알림 수'),
    'GET /tbox/api/me/home-dashboard': ('home', 'emp', '/tbox/api/me/home-dashboard', ''),
    'GET /tbox/api/me/product-home': ('home', 'emp', '/tbox/api/me/product-home', ''),
    'GET /tbox/api/product-spaces': ('list', 'emp', '/tbox/api/product-spaces', '제품 공간 표 비어 있음(단계 표 밖)'),
    'GET /tbox/api/files': ('offset', 'emp', '/tbox/api/files', '자료실 목록', {D: '/tbox/api/files?page={deep}'}),
    'GET /tbox/api/files#q': ('search', 'emp', '/tbox/api/files?q=보고서', '자료실 검색(흔한 낱말)'),
    'GET /tbox/api/files#rare': ('search', 'emp', '/tbox/api/files?q=워크숍', '자료실 검색(드문 낱말)'),
    'GET /tbox/api/files/trash': ('offset', 'emp', '/tbox/api/files/trash', '파일 휴지통', {D: '/tbox/api/files/trash?page={deep}'}),
    'GET /tbox/api/files/trash#admin': ('offset', 'admin', '/tbox/api/files/trash', '파일 휴지통(관리자 전체)', {D: '/tbox/api/files/trash?page={deep}'}),
    'GET /tbox/api/files/favorites': ('list', 'emp', '/tbox/api/files/favorites', ''),
    'GET /tbox/api/files/recent': ('list', 'emp', '/tbox/api/files/recent', ''),
    'GET /tbox/api/folders/favorites': ('list', 'emp', '/tbox/api/folders/favorites', ''),
    'GET /tbox/api/folders': ('list', 'emp', '/tbox/api/folders', ''),
    'GET /tbox/api/teams': ('list', 'emp', '/tbox/api/teams', ''),
    'GET /tbox/api/audit': ('offset', 'admin', '/tbox/api/audit', '감사 로그', {D: '/tbox/api/audit?page={deep}'}),
    'GET /tbox/api/audit#filter': ('offset', 'admin', '/tbox/api/audit?actor=d1_u00010', '감사 로그(사람 거르기)'),
    'GET /tbox/api/users': ('offset', 'admin', '/tbox/api/users', '사용자 목록', {D: '/tbox/api/users?page={deep}'}),
    'GET /tbox/api/me/activity': ('offset', 'emp', '/tbox/api/me/activity', '내 활동(감사 로그)', {D: '/tbox/api/me/activity?page={deep}'}),
    'GET /tbox/api/me/shares': ('list', 'emp', '/tbox/api/me/shares', ''),
    'GET /tbox/api/invite-requests': ('list', 'admin', '/tbox/api/invite-requests', '초대 요청'),
    'GET /tbox/api/invites': ('list', 'admin', '/tbox/api/invites', ''),
    'GET /tbox/api/admin/deletion-requests': ('list', 'admin', '/tbox/api/admin/deletion-requests', ''),
    'GET /tbox/api/admin/shares': ('list', 'admin', '/tbox/api/admin/shares', ''),
    'GET /tbox/api/org/teams': ('count', 'admin', '/tbox/api/org/teams', '팀별 인원 수'),
    'GET /tbox/api/org/teams/{num}/members': ('list', 'admin', '/tbox/api/org/teams/1/members', ''),
    'GET /tbox/api/grants/mine': ('list', 'emp', '/tbox/api/grants/mine', ''),
    'GET /tbox/api/access-requests': ('list', 'emp', '/tbox/api/access-requests', ''),
    'GET /tbox/api/access/people': ('search', 'emp', '/tbox/api/access/people?q=d1_u0001', '사람 찾기'),
    'GET /tbox/api/messages': ('cursor', 'emp', '/tbox/api/messages', '모아 쪽지(표 비어 있음)'),
    'GET /tbox/api/assistant/runs': ('cursor', 'admin', None, '도우미 꺼짐(ASSISTANT_ENABLED=0 · 토큰 전용) — 제외'),
    'GET /tbox/api/files/{id}/preview': ('detail', 'emp', None, '파일 본문 미리보기 — 디스크 파일 0 이라 제외'),
    'GET /tbox/audit': ('home', 'admin', '/tbox/audit', '감사 화면'),
    'GET /tbox/users': ('home', 'admin', '/tbox/users', '사용자 화면'),
    'GET /tbox/trash': ('home', 'emp', '/tbox/trash', ''),
    'GET /tbox/activity': ('home', 'emp', '/tbox/activity', ''),
    'GET /tbox/admin': ('home', 'admin', '/tbox/admin', ''),
    'GET /tbox/org': ('home', 'admin', '/tbox/org', ''),
    'GET /tbox/settings': ('home', 'emp', '/tbox/settings', ''),
    'GET /tbox/messages': ('home', 'emp', '/tbox/messages', ''),
    'GET /tbox/spaces': ('home', 'emp', '/tbox/spaces', ''),
    'GET /tbox/programs': ('home', 'emp', '/tbox/programs', ''),
    'GET /tbox/api/programs': ('list', 'emp', '/tbox/api/programs', ''),
    # ── 커뮤니티 ──
    'GET /community/': ('home', 'emp', '/community/', '업무 홈 화면'),
    'GET /community/workspace': ('home', 'emp', '/community/workspace', ''),
    'GET /community/cafe': ('home', 'emp', '/community/cafe', '카페 화면'),
    'GET /community/api/cafe': ('home', 'emp', '/community/api/cafe', '카페 홈(게시판 · 최근 · 인기)'),
    'GET /community/today': ('home', 'emp', '/community/today', ''),
    'GET /community/api/briefing': ('home', 'emp', '/community/api/briefing', '오늘 브리핑'),
    'GET /community/my-tasks': ('home', 'emp', '/community/my-tasks', ''),
    'GET /community/api/my-tasks': ('offset', 'emp', '/community/api/my-tasks', '내 작업(cursor=줄 수 → OFFSET)'),
    'GET /community/api/my-tasks#done': ('offset', 'emp', '/community/api/my-tasks?done=1', '내 작업(끝난 것 포함)'),
    'GET /community/api/me/space': ('count', 'emp', '/community/api/me/space', '내 공간 · 개수'),
    'GET /community/api/spaces': ('list', 'emp', '/community/api/spaces', '공간 목록'),
    'GET /community/api/pages': ('offset', 'emp', '/community/api/pages?space={space}', '공간 페이지 목록', {D: '/community/api/pages?space={space}&page={deep}'}),
    'GET /community/api/pages#team': ('offset', 'emp', '/community/api/pages?space={teamspace}', '팀 공간 페이지 목록', {D: '/community/api/pages?space={teamspace}&page={deep}'}),
    'GET /community/api/pages#tree': ('list', 'emp', '/community/api/pages?space={space}&tree=1', '공간 나무'),
    'GET /community/api/pages#shared': ('offset', 'emp', '/community/api/pages?shared=me', '나에게 공유됨'),
    'GET /community/api/pages#q': ('search', 'emp', '/community/api/pages?space={teamspace}&q=회의', '공간 안 찾기'),
    'GET /community/api/pages#status': ('list', 'emp', '/community/api/pages?space={teamspace}&status=todo', '상태 거르기'),
    'GET /community/api/procedures': ('offset', 'emp', '/community/api/procedures', '절차서 목록', {D: '/community/api/procedures?page={deep}'}),
    'GET /community/api/search': ('search', 'emp', '/community/api/search?q=회의', '통합 검색(흔한 낱말)'),
    'GET /community/api/search#rare': ('search', 'emp', '/community/api/search?q=워크숍', '통합 검색(드문 낱말)'),
    'GET /community/api/search#two': ('search', 'emp', '/community/api/search?q=회의 결과', '통합 검색(두 낱말)'),
    'GET /community/api/search#admin': ('search', 'admin', '/community/api/search?q=회의', '통합 검색(관리자)'),
    'GET /community/api/search#latin': ('search', 'emp', '/community/api/search?q=d1x', '통합 검색(영문 — ILIKE)'),
    'GET /community/api/pages/{id}': ('detail', 'emp', '/community/api/pages/{hot}', '게시판 글 읽기(조회 기록)'),
    'GET /community/api/pages/{id}#doc': ('detail', 'emp', '/community/api/pages/{doc}', '내 문서 읽기'),
    'GET /community/p/{id}': ('detail', 'emp', '/community/p/{hot}', '글 화면'),
    'GET /community/api/pages/{id}/comments': ('list', 'emp', '/community/api/pages/{hot}/comments', '댓글(인기 글 — 전부)'),
    'GET /community/api/pages/{id}/versions': ('list', 'emp', '/community/api/pages/{doc}/versions', ''),
    'GET /community/api/pages/{id}/history': ('list', 'emp', '/community/api/pages/{task}/history', '작업 기록(속성 사건)'),
    'GET /community/api/pages/{id}/reads': ('count', 'admin', '/community/api/pages/{notice}/reads', '공지 읽음 수'),
    'GET /community/api/pages/{id}/attachments': ('list', 'emp', '/community/api/pages/{hot}/attachments', ''),
    'GET /community/api/pages/{id}/shares': ('list', 'emp', '/community/api/pages/{doc}/shares', ''),
    'GET /community/api/pages/{id}/suggestions': ('list', 'emp', '/community/api/pages/{doc}/suggestions', ''),
    'GET /community/api/pages/{id}/assignee-candidates': ('search', 'emp', '/community/api/pages/{task}/assignee-candidates?q=d1', ''),
    'GET /community/api/me/trash': ('list', 'emp', '/community/api/me/trash', '내 휴지통'),
    'GET /community/api/me/favorites': ('list', 'emp', '/community/api/me/favorites', ''),
    'GET /community/api/me/recent': ('list', 'emp', '/community/api/me/recent', ''),
    'GET /community/api/me/suggestions': ('list', 'emp', '/community/api/me/suggestions', ''),
    'GET /community/api/notifications': ('list', 'emp', '/community/api/notifications', '알림'),
    'GET /community/api/notifications#unread': ('count', 'emp', '/community/api/notifications?unread=1', '안 읽은 알림'),
    'GET /community/api/mention-candidates': ('search', 'emp', '/community/api/mention-candidates?page={hot}&q=d1_u', ''),
    'GET /community/api/share-candidates': ('search', 'emp', '/community/api/share-candidates?page={doc}&q=d1_u', ''),
    'GET /community/b/{num}': ('home', 'emp', '/community/b/{board}', '게시판 화면'),
    'GET /community/api/boards': ('list', 'emp', '/community/api/boards', '게시판 목록(글 수)'),
    'GET /community/api/boards#cafe': ('list', 'emp', '/community/api/boards?area=cafe', ''),
    'GET /community/api/boards/{num}/pages': ('cursor', 'emp', '/community/api/boards/{board}/pages', '큰 게시판 목록(새 글)'),
    'GET /community/api/boards/{num}/pages#updated': ('cursor', 'emp', '/community/api/boards/{board}/pages?sort=updated', ''),
    'GET /community/api/boards/{num}/pages#views': ('cursor', 'emp', '/community/api/boards/{board}/pages?sort=views', ''),
    'GET /community/api/boards/{num}/pages#small': ('cursor', 'emp', '/community/api/boards/{board2}/pages', '작은 게시판'),
    'GET /community/api/boards/{num}/pages#tag': ('cursor', 'emp', '/community/api/boards/{board}/pages?tag=공지', '태그 거르기'),
    'GET /community/api/boards/{num}/pages#q': ('search', 'emp', '/community/api/boards/{board}/pages?q=회의', '게시판 안 찾기'),
    'GET /community/api/boards/{num}/pages#legacy': ('offset', 'emp', '/community/api/boards/{board}/pages?page=20', '옛 page=20(상한)'),
    'GET /community/api/boards/{num}/tags': ('count', 'emp', '/community/api/boards/{board}/tags', '태그별 수'),
    'GET /community/api/reports': ('list', 'admin', '/community/api/reports', '신고함'),
    'GET /community/api/reports#all': ('list', 'admin', '/community/api/reports?status=all', '신고함(전부)'),
    'GET /community/reports': ('home', 'admin', '/community/reports', ''),
    'GET /community/api/board-requests': ('list', 'admin', '/community/api/board-requests', ''),
    'GET /community/api/admin/popular': ('count', 'admin', '/community/api/admin/popular', '인기 글 집계'),
    'GET /community/memories': ('home', 'emp', '/community/memories', '이야기 화면'),
    'GET /community/api/memories': ('cursor', 'emp', '/community/api/memories', '이야기 피드(+통계 count)'),
    'GET /community/api/memories#discover': ('cursor', 'emp', '/community/api/memories?view=discover', '이야기 둘러보기'),
    'GET /community/api/memories#mine': ('cursor', 'emp', '/community/api/memories?view=mine', ''),
    'GET /community/api/memories#profile': ('cursor', 'emp', '/community/api/memories?profile={owner}', '다른 사람 이야기'),
    'GET /community/api/memories#q': ('search', 'emp', '/community/api/memories?view=discover&q=회의', '이야기 찾기'),
    'GET /community/api/memories#offset': ('offset', 'emp', '/community/api/memories?offset=10000', '이야기 offset=10000(상한)'),
    'GET /community/api/memories/{id}': ('detail', 'emp', '/community/api/memories/{story}', ''),
    'GET /community/api/memories/{id}/comments': ('cursor', 'emp', '/community/api/memories/{story}/comments', ''),
    'GET /community/api/memories/social': ('list', 'emp', '/community/api/memories/social', '팔로우 목록'),
    'GET /community/memories/u/{username}': ('home', 'emp', '/community/memories/u/{owner}', ''),
    'GET /community/api/databases': ('list', 'emp', '/community/api/databases', '데이터베이스 표 비어 있음'),
    'GET /community/api/templates': ('list', 'emp', '/community/api/templates', ''),
    'GET /community/api/automations': ('cursor', 'emp', '/community/api/automations', ''),
    'GET /community/api/collections': ('list', 'emp', '/community/api/collections', ''),
    'GET /community/api/groups': ('list', 'emp', '/community/api/groups', ''),
    'GET /community/api/me/web-publications': ('list', 'emp', '/community/api/me/web-publications', ''),
    'GET /community/api/palettes': ('list', 'emp', '/community/api/palettes', ''),
    'GET /community/api/embed-domains': ('list', 'emp', '/community/api/embed-domains', ''),
    'GET /community/api/me/guide': ('detail', 'emp', '/community/api/me/guide', ''),
    'GET /community/api/travel-plans': ('list', 'emp', '/community/api/travel-plans', '여행 계획 표 비어 있음'),
    'GET /community/procedures': ('home', 'emp', '/community/procedures', ''),
    'GET /community/api/me/ai-log': ('list', 'emp', '/community/api/me/ai-log', ''),
    'GET /community/api/admin/ai-log': ('list', 'admin', '/community/api/admin/ai-log', ''),
    'GET /community/api/admin/automations': ('cursor', 'admin', '/community/api/admin/automations', ''),
    'GET /community/api/admin/web-publications': ('list', 'admin', '/community/api/admin/web-publications', ''),
}
BUDGET_MS = dict(list=150, cursor=150, offset=150, search=1000, home=300, count=150, detail=150)


# ─────────────────────────────────────── 측정 ───────────────────────────────────────
UA = 'tbox-d1/' + time.strftime('%Y-%m-%d')


def make_sessions(pg):
    """측정 계정 1 관리자 · 2 직원 — 세션 원문은 이 프로세스 메모리에만."""
    out = {}
    for key, uid in (('admin', 1), ('emp', 2)):
        token = secrets.token_hex(32)
        pg.sql("INSERT INTO public.sessions(id,user_id,expires_at,ip,user_agent,reauth_at) VALUES('%s',%d,clock_timestamp()+interval '12 hours','127.0.0.1','%s',now())"
               % (hashlib.sha256(token.encode()).hexdigest(), uid, UA))
        out[key] = token
    return out


def discover_ids(pg, t):
    """자리표시 값 — 측정 데이터 안에서 고른다."""
    q = lambda s: pg.sql(s)
    board = t['users'] + t['teams'] + 1
    ids = dict(board=board, board2=t['users'] + t['teams'] + 9, space=2, teamspace=t['users'] + 1, uid=2, team=1)
    ids['hot'] = q("SELECT p.public_id FROM community.pages p JOIN community.comments c ON c.page_id=p.id "
                   "WHERE p.space_id=%d AND p.deleted_at IS NULL AND p.hidden_at IS NULL GROUP BY p.id ORDER BY count(*) DESC, p.id LIMIT 1" % board)
    ids['hot_comments'] = int(q("SELECT count(*) FROM community.comments c JOIN community.pages p ON p.id=c.page_id WHERE p.public_id='%s'" % ids['hot']))
    ids['notice'] = q("SELECT public_id FROM community.pages WHERE space_id=%d AND deleted_at IS NULL AND hidden_at IS NULL ORDER BY id DESC LIMIT 1" % (board + 1))
    ids['doc'] = q("SELECT public_id FROM community.pages WHERE space_id=2 AND deleted_at IS NULL AND status IS NULL ORDER BY id DESC LIMIT 1")
    ids['task'] = q("SELECT p.public_id FROM community.pages p WHERE p.status IS NOT NULL AND p.deleted_at IS NULL AND p.space_id IN (2,%d) "
                    "ORDER BY (SELECT count(*) FROM community.page_property_events e WHERE e.page_id=p.id) DESC, p.id LIMIT 1" % (t['users'] + 1))
    ids['story'] = q("SELECT id FROM community.memory_posts WHERE deleted_at IS NULL AND visibility='community' "
                     "ORDER BY (SELECT count(*) FROM community.memory_comments c WHERE c.post_id=memory_posts.id) DESC LIMIT 1")
    ids['owner'] = q("SELECT u.username FROM community.memory_posts p JOIN public.users u ON u.id=p.owner_user_id "
                     "WHERE p.id='%s'" % ids['story'])
    return ids


def fill(path, ids, deep=None):
    out = path
    for k, v in ids.items():
        out = out.replace('{%s}' % k, str(v))
    if deep is not None:
        out = out.replace('{deep}', str(deep))
    return out


def pct(values, p):
    s = sorted(values)
    if not s:
        return None
    k = (len(s) - 1) * p / 100
    f = int(k)
    c = min(f + 1, len(s) - 1)
    return round(s[f] + (s[c] - s[f]) * (k - f), 1)


def list_of(body):
    """응답에서 가장 큰 목록과 그 길이 · 다음 커서."""
    if not isinstance(body, dict):
        return None, 0, None
    best, n = None, -1
    for k, v in body.items():
        if isinstance(v, list) and len(v) > n:
            best, n = k, len(v)
    cur = body.get('next_cursor')
    if cur is None and isinstance(body.get('buckets'), list):
        for b in body['buckets']:
            if b.get('next_cursor'):
                cur = ('bucket', b.get('key'), b['next_cursor'])
                break
    return best, max(n, 0), cur


class Client:
    def __init__(self, port, sessions):
        self.base = 'http://127.0.0.1:%d' % port
        self.sessions = sessions
        self.http = requests.Session()
        self.http.trust_env = False

    def get(self, path, user):
        headers = {'User-Agent': UA, 'Cookie': '__Host-tbox_session=' + self.sessions[user], 'Accept': 'application/json, text/html'}
        t0 = time.perf_counter()
        r = self.http.get(self.base + path, headers=headers, timeout=60, allow_redirects=False)
        _ = r.content
        return r, (time.perf_counter() - t0) * 1000


def summarize_events(events):
    stmts, sets, out = [], [], []
    for ev in events:
        sql = ev['sql']
        if control_sql(sql):
            u = sql.strip().upper()
            if u in ('BEGIN', 'COMMIT', 'ROLLBACK'):
                sets = []
            elif u.startswith('SET LOCAL'):
                sets.append(sql.strip())
            continue
        out.append(dict(sql=sql, params=ev['params'], ms=ev.get('ms'), error=ev.get('error', False), sets=list(sets)))
    return out


def sql_flags(stmts):
    text = '\n'.join(s['sql'] for s in stmts).upper()
    return dict(offset=bool(re.search(r'\bOFFSET\s+(CAST|\$|\:|[1-9])', text)), count=('COUNT(' in text),
                ilike=('ILIKE' in text or 'STRPOS(' in text))


def measure_endpoint(name, spec, ids, direct, proxied, proxy, reps, deep_rows=10000):
    cat, user, path, note = spec[:4]
    extra = spec[4] if len(spec) > 4 else {}
    res = dict(name=name, category=cat, user=user, note=note)
    if path is None:
        res['skipped'] = note
        return res
    url = fill(path, ids)
    res['url'] = url
    r, _ = direct.get(url, user)  # 데우기
    times = []
    for _ in range(reps):
        r, ms = direct.get(url, user)
        times.append(ms)
    res.update(status=r.status_code, bytes=len(r.content), ms=[round(x, 1) for x in times],
               p50=pct(times, 50), p95=pct(times, 95))
    body = None
    if 'json' in r.headers.get('Content-Type', ''):
        try:
            body = r.json()
        except ValueError:
            body = None
    key, n, cur = list_of(body)
    res.update(list_key=key, rows=n, has_cursor=cur is not None, per_page=(body or {}).get('per_page') if isinstance(body, dict) else None)
    if isinstance(body, dict):
        for k in ('total', 'count', 'unread', 'total_pages'):
            if isinstance(body.get(k), int):
                res['resp_' + k] = body[k]
    # 질의 수 · SQL — 중계 서버로 한 번(데운 뒤)
    proxied.get(url, user)
    mark = proxy.mark()
    rp, pms = proxied.get(url, user)
    time.sleep(0.05)
    stmts = summarize_events(proxy.since(mark))
    res.update(queries=len(stmts), db_ms=round(sum(s['ms'] or 0 for s in stmts), 1), proxied_ms=round(pms, 1),
               proxied_status=rp.status_code, **sql_flags(stmts))
    res['statements'] = sorted(stmts, key=lambda s: -(s['ms'] or 0))[:4]
    same = {}
    for s in stmts:
        same[s['sql']] = same.get(s['sql'], 0) + 1
    if same:
        top = max(same.items(), key=lambda kv: kv[1])
        res['dup_max'] = top[1]
        res['dup_sql'] = top[0][:300] if top[1] > 2 else None
    # 커서 따라 다음 두 쪽
    if cur is not None and cat in ('cursor', 'offset', 'list', 'search'):
        pages = []
        c = cur
        for _ in range(2):
            if c is None:
                break
            if isinstance(c, tuple):
                nxt = url + ('&' if '?' in url else '?') + urlencode({'bucket': c[1], 'cursor': c[2]})
            else:
                nxt = url + ('&' if '?' in url else '?') + urlencode({'cursor': c})
            tt = []
            for _ in range(max(3, reps // 2)):
                rr, ms = direct.get(nxt, user)
                tt.append(ms)
            pages.append(dict(status=rr.status_code, p50=pct(tt, 50), p95=pct(tt, 95)))
            try:
                _, _, c = list_of(rr.json())
            except ValueError:
                c = None
        res['next_pages'] = pages
    # 깊은 쪽(OFFSET 목록 — 약 1만 번째 줄)
    if D in extra:
        per = res.get('per_page') or n or 20
        deep = max(2, deep_rows // max(per, 1) + 1)
        durl = fill(extra[D], ids, deep)
        direct.get(durl, user)
        tt = []
        for _ in range(max(3, reps // 2)):
            rr, ms = direct.get(durl, user)
            tt.append(ms)
        try:
            _, dn, _ = list_of(rr.json())
        except ValueError:
            dn = None
        mark = proxy.mark()
        proxied.get(durl, user)
        time.sleep(0.05)
        dst = summarize_events(proxy.since(mark))
        res['deep'] = dict(url=durl, page=deep, per_page=per, status=rr.status_code, rows=dn, p50=pct(tt, 50), p95=pct(tt, 95),
                           statements=sorted(dst, key=lambda s: -(s['ms'] or 0))[:2])
    return res


def literal(v):
    if v is None:
        return 'NULL'
    return "'" + str(v).replace("'", "''") + "'"


def explain(pg, stmt, timeout_s=60):
    sql = stmt['sql'].strip().rstrip(';')
    head = sql.lstrip('( \n').upper()
    if not (head.startswith('SELECT') or head.startswith('WITH')):
        return None
    params = stmt['params']
    script = ['BEGIN;', "SET LOCAL statement_timeout = '%ds';" % timeout_s] + [s + ';' for s in stmt.get('sets', [])]
    if params:
        script.append('PREPARE d1q AS %s;' % sql)
        script.append('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) EXECUTE d1q(%s);' % ', '.join(literal(p) for p in params))
    else:
        script.append('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) %s;' % sql)
    script.append('ROLLBACK;')
    try:
        raw = pg.sql('\n'.join(script), timeout=timeout_s + 30)
    except (RuntimeError, subprocess.TimeoutExpired) as e:
        return dict(error=str(e)[-300:])
    lines = raw.splitlines()
    start = next((i for i, l in enumerate(lines) if l.startswith('[')), None)
    if start is None:
        return dict(error='no plan')
    text = '\n'.join(lines[start:])
    end = text.rfind(']')
    try:
        return json.loads(text[:end + 1])[0]
    except ValueError:
        return dict(error='plan parse')


def plan_gist(plan):
    if not plan or 'Plan' not in plan:
        return plan
    g = dict(exec_ms=plan.get('Execution Time'), plan_ms=plan.get('Planning Time'), seq=[], sort_disk=[], nested=[], hits=0, reads=0,
             temp=0, top=[])

    def walk(n, depth=0):
        loops = n.get('Actual Loops', 1) or 1
        rows = (n.get('Actual Rows') or 0) * loops
        nt = n.get('Node Type')
        rel = n.get('Relation Name')
        if nt == 'Seq Scan':
            g['seq'].append(dict(rel=rel, rows=rows, removed=(n.get('Rows Removed by Filter') or 0) * loops,
                                 ms=round((n.get('Actual Total Time') or 0) * loops, 1)))
        if nt in ('Sort', 'Incremental Sort') and (n.get('Sort Space Type') == 'Disk' or 'external' in str(n.get('Sort Method', ''))):
            g['sort_disk'].append(dict(method=n.get('Sort Method'), kb=n.get('Sort Space Used')))
        if nt == 'Nested Loop' and loops * (n.get('Actual Rows') or 0) > 10000:
            g['nested'].append(dict(rows=rows))
        if depth < 6:
            g['top'].append('%s%s%s rows=%s loops=%s t=%.1f' % ('  ' * depth, nt, (' on ' + rel) if rel else
                            (' using ' + n['Index Name']) if n.get('Index Name') else '', n.get('Actual Rows'), loops,
                            n.get('Actual Total Time') or 0))
            if n.get('Index Name') and rel:
                g['top'][-1] += ' idx=' + n['Index Name']
        for c in n.get('Plans', []) or []:
            walk(c, depth + 1)
    walk(plan['Plan'])
    p = plan['Plan']
    g['hits'] = p.get('Shared Hit Blocks', 0)
    g['reads'] = p.get('Shared Read Blocks', 0)
    g['temp'] = p.get('Temp Written Blocks', 0)
    g['top'] = g['top'][:25]
    return g


SIZE_TABLES = dict(pages='community.pages', comments='community.comments', prop_events='community.page_property_events',
                   notifications='community.notifications', audit='public.audit_logs', stories='community.memory_posts',
                   media='community.memory_media', story_comments='community.memory_comments', files='public.files',
                   reports='community.reports', invites='public.invite_requests', users='public.users')
PG_SETTINGS = ('server_version', 'shared_buffers', 'work_mem', 'effective_cache_size', 'random_page_cost', 'max_parallel_workers_per_gather',
               'jit', 'default_statistics_target', 'max_wal_size', 'temp_file_limit', 'lc_collate', 'statement_timeout')


def table_stats(pg):
    rows = pg.sql("SELECT n.nspname||'.'||c.relname, c.reltuples::bigint, pg_total_relation_size(c.oid), pg_relation_size(c.oid) "
                  "FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE c.relkind='r' AND n.nspname IN ('public','community') "
                  "AND pg_total_relation_size(c.oid) > 200000 ORDER BY 3 DESC")
    out = {}
    for line in rows.splitlines():
        name, n, total, heap = line.split('|')
        out[name] = dict(rows=int(n), total_mb=round(int(total) / 1024 ** 2, 1), heap_mb=round(int(heap) / 1024 ** 2, 1))
    return out


def scale_targets(targets, f_pages, f_other, keep=('users', 'teams')):
    return {k: (v if k in keep else max(1, int(v * (f_pages if k == 'pages' else f_other)))) for k, v in targets.items()}


def plan_l(m_stats, m_targets, l_targets, budget_gb, pages_share=0.6):
    """M 의 행당 크기로 L 크기를 미리 셈한다. 예산을 넘으면 글(pages)에 예산의 60% 까지 먼저 주고
    나머지 큰 표는 남은 예산에 같은 비율로 줄인다(「100만 글 기준」을 되도록 지키려고)."""
    per = {}
    fixed = sum(v['total_mb'] for k, v in m_stats.items() if k not in SIZE_TABLES.values()) / 1024
    for key, table in SIZE_TABLES.items():
        st = m_stats.get(table)
        if st and m_targets.get(key):
            per[key] = st['total_mb'] / m_targets[key] * l_targets[key] / 1024
    pages_gb = per.get('pages', 0) + sum(v['total_mb'] for k, v in m_stats.items() if k == 'community.page_tags') / 1024 * 10
    other_gb = sum(v for k, v in per.items() if k not in ('pages', 'users'))
    full = round(pages_gb + other_gb + fixed, 2)
    room = budget_gb - fixed
    if pages_gb + other_gb <= room:
        return full, 1.0, 1.0
    f_pages = min(1.0, room * pages_share / pages_gb)
    f_other = max(0.03, min(1.0, (room - pages_gb * f_pages) / other_gb))
    return full, round(f_pages, 3), round(f_other, 3)


# 창구 → 시간을 끄는 표(외삽의 n). 나머지는 글(pages).
DRIVER = (('/tbox/api/files', 'files'), ('/tbox/api/folders', 'files'),
          ('/tbox/api/audit', 'audit'), ('/tbox/api/me/activity', 'audit'), ('/community/api/memories', 'stories'),
          ('/community/api/notifications', 'notifications'), ('/comments', 'comments'), ('/history', 'prop_events'),
          ('/community/api/reports', 'reports'), ('/tbox/api/invite-requests', 'invites'), ('/tbox/api/users', 'users'))
BASE_MS = 10.0          # 빈 창구도 내는 PHP · 세션 고정 비용(측정의 최소 p50 근처)
TARGET_PAGES = 285_000_000
TIMEOUT_MS = 15000      # 앱 역할 statement_timeout


def extrapolate(rows, targets):
    """M→L 기울기(log-log)로 2억 8500만 글 세계(L 기획 비율 × 285)의 시간과 기준을 넘는 데이터 크기를 셈한다."""
    import math
    for row in rows:
        if not all(t in row for t in ('M', 'L')) or 'L' not in targets or 'M' not in targets:
            continue
        drv = next((d for pfx, d in DRIVER if pfx in row['name']), 'pages')
        worst = lambda x: max([x['p50'] or 0] + [n[0] or 0 for n in x['next']] + [x['deep']['p50'] if x['deep'] else 0])
        pts = [(targets[t][drv], max(worst(row[t]) - BASE_MS, 0.5)) for t in ('S', 'M', 'L') if t in row and t in targets]
        nL, tL = pts[-1][0], worst(row['L'])
        dL = pts[-1][1]
        # 기울기 k — S·M·L 세 점의 log-log 최소제곱(데이터 n 이 c 배면 시간은 c^k 배). 3ms 밑은 고정 비용으로 본다.
        xs = [math.log(n) for n, _ in pts]
        ys = [math.log(d) for _, d in pts]
        mx, my = sum(xs) / len(xs), sum(ys) / len(ys)
        den = sum((x - mx) ** 2 for x in xs)
        k = 0.0 if dL < 3 or den == 0 else max(0.0, min(2.0, sum((x - mx) * (y - my) for x, y in zip(xs, ys)) / den))
        nM = pts[-2][0] if len(pts) > 1 else nL
        plan_l = TIERS['L'][drv]
        n285 = plan_l * TARGET_PAGES / TIERS['L']['pages']
        t285 = BASE_MS + dL * (n285 / nL) ** k
        b = row['budget']

        def at(limit):
            if tL >= limit:
                return nL * ((limit - BASE_MS) / dL) ** (1 / k) if k > 0.05 else 0
            return nL * ((limit - BASE_MS) / dL) ** (1 / k) if k > 0.05 else None
        nb, nt = at(b), at(TIMEOUT_MS)
        scale = TIERS['L']['pages'] / plan_l   # 그 표 n → 같은 비율의 「글 수」
        row['extrap'] = dict(driver=drv, n_M=nM, n_L=nL, k=round(k, 2), t285_ms=round(t285), t285_s=round(t285 / 1000, 1),
                             pages_at_budget=(round(nb * scale) if nb is not None else None),
                             pages_at_timeout=(round(nt * scale) if nt is not None else None))


def summarize(folder):
    """tier-*.json → summary.json + summary.md(보고서 표의 원본)."""
    tiers = {}
    for tier in ('S', 'M', 'L'):
        f = folder / ('tier-%s.json' % tier)
        if f.exists():
            tiers[tier] = json.loads(f.read_text())
    names = []
    for info in tiers.values():
        for r in info['results']:
            if r['name'] not in names:
                names.append(r['name'])
    rows = []
    for name in names:
        row = dict(name=name)
        for tier, info in tiers.items():
            r = next((x for x in info['results'] if x['name'] == name), None)
            if not r:
                continue
            row['category'] = r.get('category')
            row['user'] = r.get('user')
            row['note'] = r.get('note')
            if r.get('skipped') or r.get('error'):
                row['skipped'] = r.get('skipped') or r.get('error')
                continue
            row[tier] = dict(status=r.get('status'), p50=r.get('p50'), p95=r.get('p95'), q=r.get('queries'), db_ms=r.get('db_ms'),
                             rows=r.get('rows'), dup=r.get('dup_max'), offset=r.get('offset'), count=r.get('count'),
                             next=[(x['p50'], x['p95']) for x in r.get('next_pages', [])],
                             deep=(dict(p50=r['deep']['p50'], p95=r['deep']['p95'], page=r['deep']['page'], rows=r['deep']['rows'])
                                   if r.get('deep') else None))
        b = BUDGET_MS.get(row.get('category'), 150)
        row['budget'] = b
        if 'S' in row and 'L' in row and row['S']['p50']:
            row['ratio'] = round(row['L']['p50'] / row['S']['p50'], 2)
            worst = max([row['L']['p95'] or 0] + [x[1] or 0 for x in row['L']['next']] +
                        [row['L']['deep']['p95'] if row['L']['deep'] else 0])
            row['worst_L'] = worst
            row['over_budget'] = worst > b
            row['grows'] = row['ratio'] > 3
        rows.append(row)
    extrapolate(rows, {k: v['targets'] for k, v in tiers.items()})
    (folder / 'summary.json').write_text(json.dumps(rows, ensure_ascii=False, indent=1))
    md = ['| 창구 | 분류 | 기준 | S p50/p95 | M p50/p95 | L p50/p95 | L 다음 쪽 p95 | L 깊은 쪽 p95 | 질의 수 S/M/L | L/S | 판정 |',
          '|---|---|---|---|---|---|---|---|---|---|---|']
    for row in rows:
        if row.get('skipped') and 'L' not in row:
            md.append('| `%s` | %s | — | — | — | — | — | — | — | — | 제외: %s |' % (row['name'], row.get('category'), row['skipped']))
            continue
        f = lambda t: '%s/%s' % (row[t]['p50'], row[t]['p95']) if t in row else '—'
        q = '/'.join(str(row[t]['q']) if t in row else '—' for t in ('S', 'M', 'L'))
        nx = ', '.join(str(x[1]) for x in row['L']['next']) if 'L' in row and row['L']['next'] else '—'
        dp = '%s(쪽 %s · %s줄)' % (row['L']['deep']['p95'], row['L']['deep']['page'], row['L']['deep']['rows']) if 'L' in row and row['L']['deep'] else '—'
        verdict = []
        if row.get('over_budget'):
            verdict.append('**기준 넘음**')
        if row.get('grows'):
            verdict.append('**%s배**' % row['ratio'])
        st = row.get('L', {}).get('status')
        if st and st != 200:
            verdict.append('HTTP %s' % st)
        md.append('| `%s` | %s | %s | %s | %s | %s | %s | %s | %s | %s | %s |' % (
            row['name'].replace('GET ', ''), row.get('category'), row['budget'], f('S'), f('M'), f('L'), nx, dp, q,
            row.get('ratio', '—'), ' '.join(verdict) or '통과'))
    (folder / 'summary.md').write_text('\n'.join(md) + '\n')
    return rows


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--output', type=Path, required=True)
    ap.add_argument('--summarize', action='store_true', help='측정하지 않고 --output 폴더의 tier-*.json 만 요약')
    ap.add_argument('--tiers', default='S,M,L')
    ap.add_argument('--reps', type=int, default=7)
    ap.add_argument('--port', type=int, default=8838)
    ap.add_argument('--proxy-port', type=int, default=8848)
    ap.add_argument('--cap-gb', type=float, default=3.0)
    ap.add_argument('--floor-gb', type=float, default=2.0)
    ap.add_argument('--margin-gb', type=float, default=0.4, help='L 예산에서 VACUUM · 임시 파일 몫으로 남길 여유')
    ap.add_argument('--l-factor', type=float, default=None, help='L 을 이 비율로 강제(없으면 디스크로 정함)')
    ap.add_argument('--l-pages-factor', type=float, default=None, help='L 의 글(pages)만 이 비율로(--l-other-factor 와 함께)')
    ap.add_argument('--l-other-factor', type=float, default=None, help='L 의 글 밖 큰 표 비율')
    ap.add_argument('--only', default='', help='쉼표로 창구 이름 일부(개발용)')
    args = ap.parse_args()
    out = args.output.resolve()
    if args.summarize:
        summarize(out)
        return
    out.mkdir(parents=True, exist_ok=True)
    started = time.time()
    meta = dict(started=time.strftime('%Y-%m-%dT%H:%M:%S%z'), free_gb_start=round(free_gb(), 2), cap_gb=args.cap_gb,
                floor_gb=args.floor_gb, reps=args.reps, tiers={}, notes=[])
    if meta['free_gb_start'] < 6:
        meta['notes'].append('시작 여유 %.2fGB < 6GB — 지시서대로 L 을 줄인다(M 행당 크기로 예산에 맞춤)' % meta['free_gb_start'])
    h = Harness(args.port, args.proxy_port)
    inventory = route_inventory()
    (out / 'inventory.json').write_text(json.dumps(inventory, ensure_ascii=False, indent=1))
    try:
        h.setup_db()
        meta['unlogged'] = make_unlogged(h.pg).splitlines()[-1]
        h.write_instances()
        consents = {}
        for d in ('terms', 'privacy', 'age14', 'ai_overseas'):
            consents[d] = subprocess.check_output(['php', '-r', "require 'src/Legal.php'; echo Legal::current($argv[1]);", d],
                                                  cwd=h.work / 'direct', text=True).strip()
        meta['pg_settings'] = dict(l.split('|', 1) for l in h.pg.sql(
            "SELECT name, setting||coalesce(unit,'') FROM pg_settings WHERE name IN (%s)" % ','.join("'%s'" % s for s in PG_SETTINGS)).splitlines())
        meta['app_role_settings'] = h.pg.sql("SELECT coalesce(string_agg(array_to_string(setconfig, ','), ';'),'') FROM pg_db_role_setting s "
                                             "JOIN pg_roles r ON r.oid=s.setrole WHERE r.rolname='%s'" % h.config['DB_USER'])
        h.start()
        only = [x for x in args.only.split(',') if x]
        prev = None
        for tier in [x.strip() for x in args.tiers.split(',') if x.strip()]:
            targets = dict(TIERS[tier])
            if tier == 'L':
                if args.l_pages_factor is not None and args.l_other_factor is not None:
                    targets = scale_targets(targets, args.l_pages_factor, args.l_other_factor)
                    meta['l_plan'] = dict(f_pages=args.l_pages_factor, f_other=args.l_other_factor, source='M 행당 크기로 손으로 정한 비율(명령 인자)')
                elif args.l_factor is not None:
                    targets = scale_targets(targets, args.l_factor, args.l_factor)
                    meta['l_plan'] = dict(f_pages=args.l_factor, f_other=args.l_factor, source='명령 인자')
                elif prev is not None:
                    budget = min(args.cap_gb, free_gb() + prev['seed']['db_gb'] - args.floor_gb - args.margin_gb)
                    est, fp, fo = plan_l(prev['tables'], prev['targets'], targets, budget)
                    meta['l_plan'] = dict(estimated_full_gb=est, budget_gb=round(budget, 2), f_pages=fp, f_other=fo)
                    targets = scale_targets(targets, fp, fo)
            print('TIER %s targets=%s' % (tier, targets), flush=True)
            info = dict(targets=targets)
            info['seed'] = seed(h.pg, targets, consents, args.cap_gb, args.floor_gb)
            info['tables'] = table_stats(h.pg)
            info['free_gb_after_seed'] = round(free_gb(), 2)
            print('SEEDED %s %s' % (tier, info['seed']), flush=True)
            sessions = make_sessions(h.pg)
            ids = discover_ids(h.pg, targets)
            info['ids'] = {k: v for k, v in ids.items()}
            direct, proxied = Client(h.port, sessions), Client(h.port + 1, sessions)
            load0 = os.getloadavg()
            results = []
            t_measure = time.time()
            last_reauth = time.time()
            for name, spec in SPEC.items():
                if only and not any(o in name for o in only):
                    continue
                if time.time() - last_reauth > 300:  # 관리 영역은 최근 15분 재인증 세션만 — 합성 세션의 시각을 새로
                    h.pg.sql("UPDATE public.sessions SET reauth_at=now() WHERE user_id IN (1,2)")
                    last_reauth = time.time()
                try:
                    r = measure_endpoint(name, spec, ids, direct, proxied, h.proxy, args.reps)
                except Exception as e:  # 한 창구 실패가 전체를 멈추지 않게 — 기록한다
                    r = dict(name=name, category=spec[0], error=h.scrub(repr(e))[-300:])
                results.append(r)
                print('  %-58s %s p50=%s p95=%s q=%s' % (name, r.get('status'), r.get('p50'), r.get('p95'), r.get('queries')), flush=True)
            # EXPLAIN — 창구마다 가장 느린 문장(2ms 넘는 것 · L 에서는 1등은 늘) + 깊은 쪽
            for r in results:
                cand = [s for s in r.get('statements', [])[:2] if (s['ms'] or 0) > 2 or (tier == 'L' and s is r['statements'][0])]
                if r.get('deep', {}).get('statements'):
                    cand.append(dict(r['deep']['statements'][0], deep=True))
                plans = []
                for s in cand:
                    p = explain(h.pg, s)
                    plans.append(dict(sql=s['sql'], params=s['params'], ms=s['ms'], deep=s.get('deep', False), gist=plan_gist(p)))
                    if p and 'Plan' in p:
                        (out / 'plans').mkdir(exist_ok=True)
                        fn = out / 'plans' / ('%s-%s-%d.json' % (tier, re.sub(r'[^A-Za-z0-9]+', '_', r['name'])[:80], len(plans)))
                        fn.write_text(json.dumps(dict(sql=s['sql'], params=s['params'], plan=p), ensure_ascii=False, indent=1))
                r['plans'] = plans
            info['measure_s'] = round(time.time() - t_measure, 1)
            info['loadavg'] = [load0, os.getloadavg()]
            info['results'] = results
            (out / ('tier-%s.json' % tier)).write_text(json.dumps(info, ensure_ascii=False, indent=1))
            meta['tiers'][tier] = dict(targets=targets, seed=info['seed'], measure_s=info['measure_s'], ids=info['ids'],
                                       free_gb_after_seed=info['free_gb_after_seed'], loadavg=info['loadavg'])
            prev = info
            (out / 'meta.json').write_text(json.dumps(meta, ensure_ascii=False, indent=1))
    finally:
        try:
            h.teardown()
        except Exception as e:
            print('TEARDOWN_ERROR ' + h.scrub(repr(e))[:300], flush=True)
        meta['finished'] = time.strftime('%Y-%m-%dT%H:%M:%S%z')
        meta['elapsed_min'] = round((time.time() - started) / 60, 1)
        meta['free_gb_end'] = round(free_gb(), 2)
        meta['cleanup'] = 'servers stopped · DB %s · work dir removed' % ('LEFT — drop manually' if h.created else 'dropped')
        (out / 'meta.json').write_text(json.dumps(meta, ensure_ascii=False, indent=1))
        try:
            summarize(out)
        except Exception as e:
            print('SUMMARY_FAILED ' + repr(e)[:200], flush=True)
        print('OWNED_PROCESSES_STOPPED=1; SYNTHETIC_DB_DROPPED=%d; WORK_REMOVED=1' % (0 if h.created else 1), flush=True)


if __name__ == '__main__':
    main()
