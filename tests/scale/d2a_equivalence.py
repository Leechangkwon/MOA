#!/usr/bin/env python3
"""규모 D2a 보안 바닥 ①~④ — 옛 SQL(D2a 앞 판의 코드) vs 새 SQL(이 판) 결과 같음 · 미리 센 글 수 · 상한 개수 · 익명 가림.

격리: d1_measure 의 틀(합성 DB `tbox_scale_d_<pid>` · tbox_testdb · /tmp 작업 폴더 · 로컬 php -S)을 그대로 쓴다. 운영 DB · 운영 파일 ·
운영 계정 0. 같은 합성 DB 에 PHP 서버 둘을 띄운다 — 새 판(이 저장소 그대로)과 옛 판(`--base` 판의 src · public — git archive).
두 서버에 같은 사람(합성 계정 ≥ 20명 · 세션은 합성 DB 에 직접 넣는다)으로 같은 창구를 불러 응답 JSON 전체를 견준다:
  ① 옛 응답 → 「기대 새 응답」: 상한 칸만 옛 값에서 계산(total → min(total, 1000) + total_capped · file_count → min(n, 100) +
     file_count_capped) — 그 밖은 **글자 그대로**(목록의 id · 순서 · 모든 칸). 브리핑은 만든 시각(generated_at)만 뺀다.
  ② 글 만들기 · 옮기기 · 게시 · 게시 취소 · 숨기기 · 지우기 · 되살리기 · 영구삭제 · 동시 쓰기를 섞은 뒤 모든 게시판에서
     미리 센 글 수(spaces.post_count) = 그때그때 센 값(옛 하위 질의) · 본문만 고친 글은 게시판 행을 건드리지 않음.
  ③ 상한 개수가 같은 권한 조건으로만 센다 — ①의 total 대조(사람마다 옛 total 은 권한 조건으로 센 값) + 못 보는 1,100개 폴더 · 안 보이는
     900개가 섞인 폴더에서 직원 값이 보이는 것만.
  ④ 카페 홈 · 브리핑 · 알림 응답에 익명 게시판 글쓴이 id · 이름이 없다.
결과: --output 폴더의 equivalence.json · equivalence.md. 끝나면(실패해도) 서버 정지 · DB DROP · 작업 폴더 삭제.
"""
import argparse
from concurrent.futures import ThreadPoolExecutor
import hashlib
import importlib.util
import io
import json
import os
import secrets
import signal
import socket
import subprocess
import sys
import tarfile
import time
from pathlib import Path

import requests

ROOT = Path(__file__).resolve().parents[2]
_spec = importlib.util.spec_from_file_location('d1_measure', ROOT / 'tests/scale/d1_measure.py')
d1 = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(d1)

BASE_REV = '63aba86'   # D2a 앞 판(main + feat/scale-d1 합침) — 옛 SQL 의 원본
UA = 'tbox-review/' + time.strftime('%Y-%m-%d')
TIER = dict(d1.TIERS['S'])
CAP_TOTAL, CAP_FOLDER = 1000, 100


def log(*a):
    print(*a, flush=True)


def run_counted_writers(writer, count_rows, expected=400):
    """두 작업의 예외를 호출자에게 전달하고 실제 삽입 행 수도 검증한다."""
    before = count_rows()
    with ThreadPoolExecutor(max_workers=2) as pool:
        futures = [pool.submit(writer, tag) for tag in ('a', 'b')]
        for future in futures:
            future.result()
    inserted = count_rows() - before
    if inserted != expected:
        raise AssertionError('concurrent writers inserted %d rows, expected %d' % (inserted, expected))
    return inserted


class Equiv:
    def __init__(self, args):
        self.args = args
        self.h = d1.Harness(args.port, args.proxy_port)
        self.checks = []
        self.pairs = dict(total=0, same=0, by_route={})
        self.servers = []

    # ───────────────────────── 준비 ─────────────────────────
    def check(self, ok, name, **ev):
        self.checks.append(dict(name=name, passed=bool(ok), **ev))
        log(('PASS ' if ok else 'FAIL ') + name + ('' if ok else ' ' + json.dumps(ev, ensure_ascii=False)[:600]))
        return ok

    def sql(self, text, timeout=600):
        return self.h.pg.sql(text, timeout=timeout)

    def prepare(self):
        h = self.h
        h.setup_db()
        log('UNLOGGED', d1.make_unlogged(h.pg).splitlines()[-1])
        h.write_instances()
        consents = {}
        for d in ('terms', 'privacy', 'age14', 'ai_overseas'):
            consents[d] = subprocess.check_output(['php', '-r', "require 'src/Legal.php'; echo Legal::current($argv[1]);", d],
                                                  cwd=h.work / 'direct', text=True).strip()
        t0 = time.time()
        vars_ = dict(TIER, boards=24, consent_terms=consents['terms'], consent_privacy=consents['privacy'],
                     consent_age14=consents['age14'], consent_ai=consents['ai_overseas'])
        tail = []
        for k, v in sorted(vars_.items()):
            tail += ['-v', '%s=%s' % (k, v)]
        def psqlf(path, extra):
            try:
                h.pg.cmd('psql', ['-X', '-q', '-d', h.db, '-v', 'ON_ERROR_STOP=1'] + extra + ['-f', str(path)])
            except subprocess.CalledProcessError as e:
                raise RuntimeError('psql %s failed: %s' % (path.name, h.scrub((e.stderr or b'').decode(errors='replace'))[-1200:]))
        psqlf(ROOT / 'tests/scale/d1_seed.sql', tail)
        psqlf(ROOT / 'tests/scale/d2a_mix.sql', ['-v', 'users=%d' % TIER['users'], '-v', 'teams=%d' % TIER['teams'], '-v', 'boards=24'])
        log('SEEDED S + mix in %.0fs' % (time.time() - t0))
        # 옛 판 — 같은 사본에 src · public 만 base 판으로 바꾼다(db · tests · .env 는 같다 — 스키마는 새 판 그대로)
        old = h.work / 'proxied'
        archive = subprocess.run(['git', '-C', str(ROOT), 'archive', self.args.base, 'src', 'public'],
                                 check=True, capture_output=True).stdout
        for d in ('src', 'public'):
            subprocess.run(['rm', '-rf', str(old / d)], check=True)
        with tarfile.open(fileobj=io.BytesIO(archive)) as tar:
            for m in tar.getmembers():
                target = (old / m.name).resolve()
                if old.resolve() not in target.parents:
                    raise RuntimeError('archive path guard')
                if m.isdir():
                    target.mkdir(parents=True, exist_ok=True)
                elif m.isfile():
                    target.parent.mkdir(parents=True, exist_ok=True)
                    target.write_bytes(tar.extractfile(m).read())
        cfg = dict(h.config, APP_URL='http://127.0.0.1:%d' % (self.args.port + 1), DB_HOST='127.0.0.1', DB_PORT=h.config['DB_PORT'])
        d1.private_write(old / '.env', ''.join('%s=%s\n' % kv for kv in cfg.items()))
        self.old_rev = subprocess.run(['git', '-C', str(ROOT), 'rev-parse', self.args.base], check=True, capture_output=True,
                                      text=True).stdout.strip()

    def start(self):
        log_f = (self.h.work / 'logs/php.log').open('wb')
        env = {k: v for k, v in os.environ.items() if k != 'PHP_CLI_SERVER_WORKERS' and not k.startswith('PG')}
        for inst, port in ((self.h.work / 'direct', self.args.port), (self.h.work / 'proxied', self.args.port + 1)):
            p = subprocess.Popen(['php', '-d', 'opcache.enable_cli=1', '-S', '127.0.0.1:%d' % port, '-t', 'public',
                                  'tests/community/export_router.php'], cwd=inst, env=env, stdout=log_f, stderr=log_f,
                                 start_new_session=True)
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
                    p.wait(timeout=5)
                except ProcessLookupError:
                    pass
            if p.poll() is None:
                raise RuntimeError('owned PHP process did not stop')
        self.servers = []

    def sessions(self, uids):
        out = {}
        for uid in uids:
            token = secrets.token_hex(32)
            self.sql("INSERT INTO public.sessions(id,user_id,expires_at,ip,user_agent,reauth_at) VALUES('%s',%d,clock_timestamp()+interval '12 hours',"
                     "'127.0.0.1','%s',now())" % (hashlib.sha256(token.encode()).hexdigest(), uid, UA))
            out[uid] = token
        self.h.secrets += list(out.values())
        return out

    # ───────────────────────── ① 결과 같음 ─────────────────────────
    def get(self, port, path, token):
        r = self.http.get('http://127.0.0.1:%d%s' % (port, path), timeout=60, allow_redirects=False,
                          headers={'User-Agent': UA, 'Cookie': '__Host-tbox_session=' + token, 'Accept': 'application/json'})
        try:
            body = r.json()
        except ValueError:
            body = r.text[:2000]
        return r.status_code, body

    @staticmethod
    def expected(old, kind):
        """옛 응답 → 이 판에서 기대하는 응답(상한 칸만 옛 값에서 계산)."""
        if not isinstance(old, dict):
            return old
        e = json.loads(json.dumps(old))
        if kind == 'total' and isinstance(e.get('total'), int):
            e['total_capped'] = e['total'] > CAP_TOTAL
            e['total'] = min(e['total'], CAP_TOTAL)
        if kind == 'folders' and isinstance(e.get('folders'), list):
            for f in e['folders']:
                n = f.get('file_count')
                if isinstance(n, int):
                    f['file_count'] = min(n, CAP_FOLDER)
                    f['file_count_capped'] = n > CAP_FOLDER
        if kind == 'briefing':
            e.pop('generated_at', None)
        return e

    def compare(self, uid, route, path, kind):
        token = self.tokens[uid]
        so, old = self.get(self.args.port + 1, path, token)
        sn, new = self.get(self.args.port, path, token)
        if kind == 'briefing' and isinstance(new, dict):
            new = dict(new)
            new.pop('generated_at', None)
        exp = self.expected(old, kind)
        same = so == sn and exp == new
        st = self.pairs['by_route'].setdefault(route, dict(total=0, same=0, statuses={}))
        st['total'] += 1
        st['same'] += 1 if same else 0
        st['statuses'][str(sn)] = st['statuses'].get(str(sn), 0) + 1
        self.pairs['total'] += 1
        self.pairs['same'] += 1 if same else 0
        if not same:
            self.diffs.append(dict(uid=uid, path=path, old_status=so, new_status=sn,
                                   old=json.dumps(exp, ensure_ascii=False)[:1500], new=json.dumps(new, ensure_ascii=False)[:1500]))
        if so >= 500 or sn >= 500:
            self.server_errors.append(dict(uid=uid, path=path, old=so, new=sn))
        return sn, new, so, old

    def scopes(self):
        q = self.sql
        self.folders = [int(x) for x in q("SELECT id FROM public.folders WHERE access_level='private' OR id >= 1900 OR id IN (1,2,3,4,5,6) ORDER BY id").split()]
        bb = TIER['users'] + TIER['teams']
        self.boards = list(range(bb + 1, bb + 25))
        self.team_space = lambda team: TIER['users'] + team

    def run_pairs(self):
        self.http = requests.Session()
        self.http.trust_env = False
        self.diffs, self.server_errors = [], []
        info = {int(r.split('|')[0]): dict(role=r.split('|')[1], team=(int(r.split('|')[2]) if r.split('|')[2] else None),
                                          active=r.split('|')[3] == 't')
                for r in self.sql("SELECT id, role, coalesce(team_id::text,''), is_active FROM public.users WHERE id IN (%s)"
                                  % ','.join(str(u) for u in self.uids)).splitlines()}
        self.people = info
        for uid in self.uids:
            t0 = time.time()
            u = info[uid]
            sp = [uid, 10, 15]                                   # 내 개인 공간 · 남의 개인 공간 둘(공유 받은 글이 있다)
            if u['team']:
                sp.append(self.team_space(u['team']))
            sp += [self.team_space(1 if (u['team'] or 0) != 1 else 2), self.boards[0], self.boards[3]]
            # 자료실 — 루트 정렬 여섯 · 쪽 · 깊은 쪽(상한 너머) · 폴더마다 · 전역 검색
            for sort in ('created_at', 'name', 'size'):
                for d in ('desc', 'asc'):
                    self.compare(uid, 'files', '/tbox/api/files?folder=0&sort=%s&dir=%s' % (sort, d), 'total')
            for page in (2, 3, 50, 51, 170):
                self.compare(uid, 'files', '/tbox/api/files?folder=0&page=%d' % page, 'total')
            for f in self.folders:
                self.compare(uid, 'files', '/tbox/api/files?folder=%d' % f, 'total')
                self.compare(uid, 'folders', '/tbox/api/folders?parent=%d' % f, 'folders')
            for f in (1910, 1912, 1021, 1051):
                for sort in ('name', 'size'):
                    self.compare(uid, 'files', '/tbox/api/files?folder=%d&sort=%s&dir=desc&page=2' % (f, sort), 'total')
            self.compare(uid, 'folders', '/tbox/api/folders?parent=0', 'folders')
            for qq in ('%EB%B3%B4%EA%B3%A0%EC%84%9C', 'd2a'):
                self.compare(uid, 'files', '/tbox/api/files?q=%s' % qq, 'total')
                self.compare(uid, 'files', '/tbox/api/files?q=%s&page=2' % qq, 'total')
            # 커뮤니티
            self.compare(uid, 'cafe', '/community/api/cafe', 'plain')
            for area in ('', '?area=cafe', '?area=work'):
                self.compare(uid, 'boards', '/community/api/boards' + area, 'plain')
            for page in (1, 2, 3, 51, 53):
                self.compare(uid, 'shared', '/community/api/pages?shared=me&page=%d' % page, 'total')
            self.compare(uid, 'shared', '/community/api/pages?shared=me&status=todo', 'total')
            self.compare(uid, 'shared', '/community/api/pages?shared=me&status=none', 'total')
            for s in sp:
                for page in (1, 2, 4):
                    self.compare(uid, 'space', '/community/api/pages?space=%d&page=%d' % (s, page), 'total')
                self.compare(uid, 'space', '/community/api/pages?space=%d&status=todo' % s, 'total')
                self.compare(uid, 'space', '/community/api/pages?space=%d&q=%%ED%%9A%%8C%%EC%%9D%%98' % s, 'total')
                self.compare(uid, 'tree', '/community/api/pages?space=%d&tree=1' % s, 'plain')
            self.compare(uid, 'space', '/community/api/pages', 'total')
            self.compare(uid, 'trash', '/community/api/me/trash', 'plain')
            self.compare(uid, 'briefing', '/community/api/briefing', 'briefing')
            self.compare(uid, 'notifications', '/community/api/notifications', 'plain')
            self.compare(uid, 'notifications', '/community/api/notifications?unread=1', 'plain')
            log('  user %-4d %-8s team=%-4s pairs=%d same=%d (%.0fs)' % (uid, u['role'], u['team'], self.pairs['total'],
                                                                      self.pairs['same'], time.time() - t0))

    # ───────────────────────── ③ 상한 개수 — 명시 경우 ─────────────────────────
    def cap_cases(self):
        emp, adm = self.tokens[2], self.tokens[1]
        s, b = self.get(self.args.port, '/tbox/api/files?folder=1910', emp)
        self.check(s == 200 and b.get('total') == 300 and b.get('total_capped') is False,
                   'cap: employee sees only the 300 visible of 1,200 (900 admin-only never counted)', status=s, total=b.get('total') if isinstance(b, dict) else None)
        s, b = self.get(self.args.port, '/tbox/api/files?folder=1910', adm)
        self.check(s == 200 and b.get('total') == 1000 and b.get('total_capped') is True, 'cap: admin 1,200 → 1,000+', total=b.get('total'))
        s, b = self.get(self.args.port, '/tbox/api/files?folder=1912', emp)
        self.check(s == 403, 'cap: unreadable admin-only folder stays 403 (no count leaks)', status=s)
        s, b = self.get(self.args.port, '/tbox/api/folders?parent=0', emp)
        names = {f['id']: f for f in b.get('folders', [])} if isinstance(b, dict) else {}
        self.check(1912 not in names and names.get(1910, {}).get('file_count') == 100 and names[1910].get('file_count_capped') is True
                   and names.get(1911, {}).get('file_count') == 50 and names[1911].get('file_count_capped') is False,
                   'cap: folder counts 100+ / 50 · invisible folder absent', f1910=names.get(1910), f1911=names.get(1911))
        s, b = self.get(self.args.port, '/community/api/pages?shared=me', self.tokens[5])
        self.check(s == 200 and b.get('total') == 1000 and b.get('total_capped') is True, 'cap: 1,050 shares → 1,000+', total=b.get('total'))

    # ───────────────────────── ② 미리 센 글 수 ─────────────────────────
    LIVE = ("SELECT b.id, b.post_count, (SELECT count(*) FROM community.pages p WHERE p.deleted_at IS NULL AND p.hidden_at IS NULL "
            "AND (p.space_id = b.id OR (p.visibility = 'published' AND p.published_board_id = b.id))) FROM community.spaces b "
            "WHERE b.kind = 'board' ORDER BY b.id")

    def counts_equal(self, name):
        rows = [r.split('|') for r in self.sql(self.LIVE).splitlines()]
        bad = [r for r in rows if r[1] != r[2]]
        nonboard = self.sql("SELECT count(*) FROM community.spaces WHERE kind <> 'board' AND post_count <> 0")
        return self.check(not bad and nonboard == '0', 'post_count == live count on all %d boards — %s' % (len(rows), name),
                          bad=bad[:5], nonboard=nonboard)

    def post_counts(self):
        bb = TIER['users'] + TIER['teams']
        self.counts_equal('after seed + mix')
        steps = [
            ('create', f"INSERT INTO community.pages (space_id, position, title, visibility, doc, author_id, last_edited_by) "
                       f"SELECT {bb} + 1 + (k % 24), 0, 'd2a 셈 ' || k, 'private', '{{}}'::jsonb, 2, 2 FROM generate_series(1, 120) k"),
            ('create hidden/deleted', f"INSERT INTO community.pages (space_id, position, title, visibility, doc, author_id, last_edited_by, hidden_at, hidden_by, deleted_at, deleted_by) "
                                      f"SELECT {bb} + 1 + (k % 5), 0, 'd2a 셈숨 ' || k, 'private', '{{}}'::jsonb, 3, 3, "
                                      f"CASE WHEN k % 2 = 0 THEN now() END, CASE WHEN k % 2 = 0 THEN 1 END, CASE WHEN k % 3 = 0 THEN now() END, "
                                      f"CASE WHEN k % 3 = 0 THEN 3 END FROM generate_series(1, 30) k"),
            ('move board→board', f"UPDATE community.pages SET space_id = {bb} + 1 + ((space_id - {bb}) % 24) WHERE title LIKE 'd2a 셈 %' AND id % 3 = 0"),
            ('move board→team', f"UPDATE community.pages SET space_id = {TIER['users']} + 1 WHERE title LIKE 'd2a 셈 %' AND id % 7 = 0"),
            ('publish', f"UPDATE community.pages SET visibility = 'published', published_from = visibility, published_board_id = {bb} + 1 + (id % 24), "
                        f"published_at = now(), published_by = author_id WHERE id IN (SELECT p.id FROM community.pages p JOIN community.spaces s "
                        f"ON s.id = p.space_id AND s.kind = 'personal' WHERE p.visibility <> 'published' AND p.deleted_at IS NULL ORDER BY p.id LIMIT 40)"),
            ('publish to own-space board (same id once)', f"UPDATE community.pages SET visibility = 'published', published_from = 'private', "
                        f"published_board_id = space_id, published_at = now(), published_by = author_id "
                        f"WHERE title LIKE 'd2a 셈 %' AND id % 11 = 0 AND visibility <> 'published'"),
            ('re-publish to another board', f"UPDATE community.pages SET published_board_id = {bb} + 1 + ((published_board_id - {bb}) % 24) "
                                            f"WHERE visibility = 'published' AND id % 2 = 0"),
            ('unpublish', "UPDATE community.pages SET visibility = published_from, published_from = NULL, published_board_id = NULL, "
                          "published_at = NULL, published_by = NULL WHERE visibility = 'published' AND id % 5 = 0"),
            ('hide', "UPDATE community.pages SET hidden_at = now(), hidden_by = 1 WHERE title LIKE 'd2a 셈 %' AND id % 4 = 0 AND hidden_at IS NULL"),
            ('unhide', "UPDATE community.pages SET hidden_at = NULL, hidden_by = NULL WHERE hidden_at IS NOT NULL AND id % 8 = 0"),
            ('delete', "UPDATE community.pages SET deleted_at = now(), deleted_by = author_id WHERE title LIKE 'd2a 셈 %' AND id % 5 = 1 AND deleted_at IS NULL"),
            ('restore', "UPDATE community.pages SET deleted_at = NULL, deleted_by = NULL WHERE title LIKE 'd2a 셈%' AND deleted_at IS NOT NULL AND id % 2 = 1"),
            ('delete published', "UPDATE community.pages SET deleted_at = now(), deleted_by = author_id WHERE visibility = 'published' AND id % 3 = 0 AND deleted_at IS NULL"),
            ('purge (purged_at)', "UPDATE community.pages SET purged_at = now() WHERE deleted_at IS NOT NULL AND title LIKE 'd2a 셈%'"),
            ('purge (row delete)', "DELETE FROM community.pages WHERE title LIKE 'd2a 셈%' AND id % 6 = 0 AND NOT EXISTS "
                                   "(SELECT 1 FROM community.pages c WHERE c.parent_page_id = community.pages.id)"),
            ('hidden+deleted same statement', "UPDATE community.pages SET hidden_at = now(), hidden_by = 1, deleted_at = now(), deleted_by = 1 "
                                              "WHERE title LIKE 'd2a 셈 %' AND id % 9 = 2"),
            ('restore both', "UPDATE community.pages SET hidden_at = NULL, hidden_by = NULL, deleted_at = NULL, deleted_by = NULL, purged_at = NULL "
                             "WHERE title LIKE 'd2a 셈 %' AND id % 9 = 2"),
        ]
        for name, stmt in steps:
            self.sql(stmt)
            self.counts_equal(name)
        # 본문 · 제목만 고치면(자동 저장) 게시판 행을 고치지 않는다 — 게시판 행의 xmin 이 그대로
        before = self.sql("SELECT string_agg(id || ':' || xmin::text, ',' ORDER BY id) FROM community.spaces WHERE kind = 'board'")
        self.sql("UPDATE community.pages SET doc = jsonb_build_object('schema', 1, 'blocks', '[]'::jsonb), title = title || ' 고침', "
                 "updated_at = now(), doc_version = doc_version + 1 WHERE title LIKE 'd2a 셈 %'")
        after = self.sql("SELECT string_agg(id || ':' || xmin::text, ',' ORDER BY id) FROM community.spaces WHERE kind = 'board'")
        self.check(before == after, 'doc/title-only autosave does not touch board rows (trigger WHEN)')
        # 동시 쓰기 — 두 세션이 같은 게시판에 따로따로 200번(트랜잭션마다 한 글)
        def writer(tag):
            text = ''.join("INSERT INTO community.pages (space_id, position, title, visibility, doc, author_id, last_edited_by) "
                           "VALUES (%d, 0, 'd2a 동시 %s %d', 'private', '{}'::jsonb, 2, 2);\n" % (bb + 1, tag, i) for i in range(200))
            self.h.pg.sql(text, timeout=300)
        try:
            inserted = run_counted_writers(writer, lambda: int(self.sql("SELECT count(*) FROM community.pages WHERE title LIKE 'd2a 동시 %'")))
        except Exception as e:
            self.check(False, 'two concurrent writers insert exactly 400 rows', error=self.h.scrub(repr(e)))
            raise
        self.check(inserted == 400, 'two concurrent writers insert exactly 400 rows', inserted=inserted)
        self.counts_equal('two concurrent writers × 200')
        # 이주를 다시 돌려도(두 번째) 같은 값 · 바꾸는 행 0
        out = self.h.pg.cmd('psql', ['-X', '-d', self.h.db, '-v', 'ON_ERROR_STOP=1', '-f',
                                     str(self.h.work / 'direct/db/migrations/2026-10-26_scale_d2a_board_counts.sql')]).stdout.decode()
        self.check('UPDATE 0' in out and out.count('UPDATE 0') >= 2, 'board_counts migration re-run: fill changes 0 rows', out=out[-300:])
        self.counts_equal('after migration re-run')

    # ───────────────────────── ④ 익명 가림 ─────────────────────────
    def anonymity(self):
        bb = TIER['users'] + TIER['teams']
        anon_authors = {}
        for r in self.sql(f"SELECT p.public_id, u.id, u.username, coalesce(u.display_name,'') FROM community.pages p JOIN public.users u ON u.id = "
                          f"p.author_id WHERE p.space_id = {bb} + 4").splitlines():
            pid, uid, uname, dname = r.split('|')
            anon_authors[pid] = (int(uid), uname, dname)
        for r in self.sql(f"SELECT p.public_id, u.id, u.username, coalesce(u.display_name,'') FROM community.pages p JOIN community.spaces s ON "
                          f"s.id = p.space_id JOIN public.users u ON u.id = s.owner_user_id WHERE p.published_board_id = {bb} + 4").splitlines():
            pid, uid, uname, dname = r.split('|')
            anon_authors[pid] = (int(uid), uname, dname)
        seen = dict(cafe=0, notif=0)
        problems = []
        for uid in self.uids:
            if not self.people[uid]['active']:
                continue
            s, cafe = self.get(self.args.port, '/community/api/cafe', self.tokens[uid])
            if s == 200:
                for item in cafe.get('recent', []) + cafe.get('popular', []):
                    if item['id'] in anon_authors:
                        seen['cafe'] += 1
                        w = item.get('writer') or {}
                        a = anon_authors[item['id']]
                        if w.get('username') is not None or not item['board'].get('anonymous') or w.get('display_name') in (a[1], a[2]) \
                                or set(w) - {'username', 'display_name', 'anon', 'is_me'}:
                            problems.append(('cafe', uid, item))
            s, notes = self.get(self.args.port, '/community/api/notifications', self.tokens[uid])
            if s == 200:
                for n in notes.get('notifications', []):
                    pg = n.get('page') or {}
                    if pg.get('id') in anon_authors:
                        seen['notif'] += 1
                        a = n.get('actor')
                        if a is not None and (a.get('username') is not None or set(a) - {'username', 'display_name', 'anon', 'is_me'}):
                            problems.append(('notification', uid, n))
            s, brief = self.get(self.args.port, '/community/api/briefing', self.tokens[uid])
            text = json.dumps(brief, ensure_ascii=False)
            if '"username"' in text or '"author' in text or '"writer' in text or '"actor' in text:
                problems.append(('briefing', uid, text[:300]))
        self.check(not problems and seen['cafe'] > 0 and seen['notif'] > 0,
                   'anonymous board writer never in cafe · notifications · briefing (%d cafe rows · %d notification rows checked)'
                   % (seen['cafe'], seen['notif']), problems=problems[:3])

    # ───────────────────────── 실행 ─────────────────────────
    def main(self):
        out = self.args.output
        out.mkdir(parents=True, exist_ok=True)
        started = time.time()
        meta = dict(started=time.strftime('%Y-%m-%dT%H:%M:%S%z'), free_gb_start=round(d1.free_gb(), 2), base=self.args.base)
        try:
            self.prepare()
            meta['old_rev'] = self.old_rev
            self.uids = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 20, 21, 27, 30, 40, 57, 100, 101, 155]
            self.tokens = self.sessions(self.uids)
            self.scopes()
            self.start()
            self.run_pairs()
            people = [u for u in self.uids if self.people[u]['active']]
            roles = sorted({self.people[u]['role'] for u in people})
            teams = sorted({self.people[u]['team'] for u in people if self.people[u]['team']})
            self.check(self.pairs['total'] > 0 and self.pairs['same'] == self.pairs['total'] and not self.server_errors,
                       'old SQL vs new SQL — same ids · order · fields: %d/%d pairs · %d people (%s · teams %s)'
                       % (self.pairs['same'], self.pairs['total'], len(people), ','.join(roles), teams),
                       diffs=self.diffs[:3], server_errors=self.server_errors[:3])
            self.check(len(people) >= 20 and {'Admin', 'Manager', 'Employee', 'Guest'} <= set(roles) and len(teams) >= 5,
                       'coverage: ≥ 20 people · Admin/Manager/Employee/Guest · ≥ 5 teams', people=len(people), roles=roles)
            self.cap_cases()
            self.anonymity()
            self.post_counts()
        finally:
            cleanup_errors = []
            try:
                self.stop()
            except Exception as e:
                cleanup_errors.append(self.h.scrub(repr(e))[:300])
            try:
                self.h.teardown()
            except Exception as e:
                cleanup_errors.append(self.h.scrub(repr(e))[:300])
            self.check(not cleanup_errors and not self.h.created and not self.h.work.exists(),
                       'owned processes stopped, synthetic DB dropped, work directory removed',
                       errors=cleanup_errors, db_left=self.h.created, work_left=self.h.work.exists())
            meta['finished'] = time.strftime('%Y-%m-%dT%H:%M:%S%z')
            meta['elapsed_min'] = round((time.time() - started) / 60, 1)
            meta['free_gb_end'] = round(d1.free_gb(), 2)
            meta['synthetic_db'] = 'LEFT — drop manually' if self.h.created else 'dropped'
            res = dict(meta=meta, pairs=self.pairs, checks=self.checks, diffs=getattr(self, 'diffs', [])[:50],
                       server_errors=getattr(self, 'server_errors', [])[:50], people=getattr(self, 'people', {}))
            (out / 'equivalence.json').write_text(self.h.scrub(json.dumps(res, ensure_ascii=False, indent=1, default=str)))
            md = ['| 창구 | 쌍 | 같음 | 상태 |', '|---|---|---|---|']
            for k, v in self.pairs['by_route'].items():
                md.append('| %s | %d | %d | %s |' % (k, v['total'], v['same'], ' '.join('%s×%d' % kv for kv in sorted(v['statuses'].items()))))
            md.append('')
            md += ['- %s %s' % ('PASS' if c['passed'] else 'FAIL', c['name']) for c in self.checks]
            (out / 'equivalence.md').write_text('\n'.join(md) + '\n')
            passed = sum(c['passed'] for c in self.checks)
            log('D2A_EQUIV pass=%d fail=%d pairs=%d/%d synthetic_db=%s' % (passed, len(self.checks) - passed, self.pairs['same'],
                                                                         self.pairs['total'], meta['synthetic_db']))
        if any(not c['passed'] for c in self.checks) or len(self.checks) < 10:
            raise SystemExit(1)


if __name__ == '__main__':
    ap = argparse.ArgumentParser()
    ap.add_argument('--output', type=Path, required=True)
    ap.add_argument('--base', default=BASE_REV, help='옛 SQL 의 판(git rev) — 기본 D2a 앞 판')
    ap.add_argument('--port', type=int, default=8868)
    ap.add_argument('--proxy-port', type=int, default=8878)
    a = ap.parse_args()
    a.output = a.output.resolve()
    if str(a.output).startswith(str(ROOT)):
        raise SystemExit('output inside repository refused')
    Equiv(a).main()
