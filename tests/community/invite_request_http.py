#!/usr/bin/env python3
"""사이트 H2 — 초대 요청 수락 시험 · HTTP (실제 PHP · PostgreSQL · 격리 틀 memories_harness.py 만)

  python3 tests/community/memories_harness.py --suite invite_request_http.py --output <결과 폴더>

합성 DB · 임시 복사본 · 로컬 php -S 127.0.0.1:8768 만 친다(운영 · tbox_re · 시드 계정 0). 계정은 fixture 의 claude_ 와 이 시험이 가입시키는
h2_ 합성 계정뿐 · UA tbox-review/<날짜> · 외부 요청 0. 보관 삭제는 합성 DB 소유자(tbox_testdb)로 scripts/privacy_purge.php 를 부른다
(작업 폴더의 0600 pgpass — 끝나면 작업 폴더째 지워진다). 결과 파일에는 단언 이름 · 참/거짓 · 건수만 — 코드 · 세션 · 이름 원문 0.

지시서 docs/invite-request-directive.md 완료 기준 「HTTP(격리)」:
  요청 201 {status_path} · 코드 64 16진 · DB 엔 해시만 · 키 · 길이 · 제어 문자 · agree 거짓 400 · CSRF 없음 403 · 미끼 → 201 같은 모양 + 행 0 + deny 감사 ·
  IP 1시간 4번째 429 · 24시간 6번째 429 · 열린 200 → 503 · 로그인한 사람 화면 → / · 확인: 틀린 코드 404 · 대기 · 반려(메모 0) · 승인 → 초대 발급
  (1회 · 7일 · 팀 · 이름표에 이름 0) → 그 초대로 가입 · 다시 확인 → 앞 초대 거둠 + 새 발급 · 4번째 409 · 가입 뒤 상태 · 1분 21번째 429 ·
  목록 · 결정 직원 403 · 재인증 없으면 403 · 이미 결정 409 · 없는 팀 400 · 감사 세 동작 문장(500 0) · 감사에 이름 · 소속 · 이유 · 코드 0 ·
  access log 에 코드 0 · 보관 삭제 · AL-25(dry-run 21건 → 경보 1) · 처리방침 새 판 · ?v= 옛 판 · 회원 동의 관문 → 동의 뒤 통과 · 마이그레이션 두 번(틀)
"""
from pathlib import Path
exec(compile(Path(__file__).with_name('memories_http.py').read_text().split('try:\n for u in users:')[0], __file__, 'exec'))
import re, secrets, hmac
assert str(root).startswith('/tmp/qaredi-stage13-') and manifest['database'].startswith('tbox_memories_test_')
UA = 'tbox-review/' + time.strftime('%Y-%m-%d')
TB = site + '/tbox'
WORK = root.parent
results = {}
bad5xx = []


def check(ok, name):
    checks.append({'pass': bool(ok), 'name': name})
    print(('PASS ' if ok else 'FAIL ') + name, flush=True)
    return bool(ok)


def guard(name, fn):
    try:
        fn()
    except Exception as error:  # noqa: BLE001
        check(False, name + ' raised ' + type(error).__name__ + ': ' + str(error).splitlines()[0][:200])


def lit(v): return "'" + str(v).replace("'", "''") + "'"
def uid(key): return int(users[key]['id'])
def count(q): return int(sql(q).splitlines()[0])


def env_values(path):
    out_v = {}
    for line in path.read_text().splitlines():
        if line.strip() and not line.lstrip().startswith('#') and '=' in line:
            k, v = line.strip().split('=', 1)
            out_v[k] = v.strip().strip('"\'')
    return out_v


FIX = env_values(Path(os.environ['FILE_RENAME_FIXTURE_CONFIG']))
JOIN = secrets.token_hex(32)


def req(method, path, body=None, join=True, user=None, csrf=True, headers=None, raw_body=None):
    h = {'User-Agent': UA}
    cookies = []
    if join:
        cookies.append('__Host-moa_join=' + JOIN)
        h['X-Join-CSRF'] = JOIN
    if user:
        cookies.append('__Host-tbox_session=' + users[user]['session'])
        if csrf:
            h['X-CSRF-Token'] = users[user]['csrf']
    if cookies:
        h['Cookie'] = '; '.join(cookies)
    h.update(headers or {})
    if raw_body is not None:
        h['Content-Type'] = 'application/json'
        r = requests.request(method, TB + path, data=raw_body, headers=h, timeout=40, allow_redirects=False)
    else:
        r = requests.request(method, TB + path, json=body, headers=h, timeout=40, allow_redirects=False)
    if r.status_code >= 500 and not (r.status_code == 503 and path == '/api/invite-requests'):   # 503 = 열린 요청 200 상한(의도한 답 — cap 단언이 따로 본다)
        bad5xx.append(method + ' ' + path + ' ' + str(r.status_code))
    return r


def js(r):
    try:
        return r.json()
    except ValueError:
        return {}


def reauth(key, fresh=True):
    sid = hashlib.sha256(users[key]['session'].encode()).hexdigest()
    sql('UPDATE sessions SET reauth_at=' + ('now()' if fresh else 'NULL') + ' WHERE id=' + lit(sid))


def reset_ip():
    # 이 시험의 요청은 전부 127.0.0.1 — 1시간 · 24시간 셈을 비우려고 요청 행의 접수 때를 25시간 당긴다(보관 30일 안 · 결정한 행은 결정 때 기준)
    sql("UPDATE invite_requests SET created_at = created_at - interval '25 hours' WHERE ip = '127.0.0.1' AND created_at > now() - interval '25 hours'")


def new_request(tag, expect=201):
    body = {'name': 'H2합성이름' + tag, 'org': 'H2합성소속' + tag, 'reason': 'H2합성이유' + tag + ' 줄바꿈\n둘째 줄', 'agree': True}
    r = req('POST', '/api/invite-requests', body)
    d = js(r)
    code = None
    if r.status_code == 201 and isinstance(d.get('status_path'), str):
        m = re.fullmatch(r'/tbox/request-invite/status#code=([0-9a-f]{64})', d['status_path'])
        code = m.group(1) if m else None
    return r, d, code


def status(code):
    return req('POST', '/api/invite-requests/status', {'code': code})


def row_of(code):
    out = sql("SELECT id, status FROM invite_requests WHERE status_hash = " + lit(hashlib.sha256(code.encode()).hexdigest()))
    if not out:
        return None
    a, b = out.split('|')
    return int(a), b


codes_seen = []
names_seen = []
try:
    for u in users:
        users[u]['username'] = sql('SELECT username FROM users WHERE id=' + str(users[u]['id']))
    team_a = int(sql('SELECT team_id FROM users WHERE id=' + str(uid('employee'))))
    ADM = uid('admin')

    # ── 마이그레이션 두 번 · 모양 · 권한 ─────────────────────────────────────────
    def shape():
        log = (out / 'schema.log').read_bytes().decode(errors='replace') if (out / 'schema.log').exists() else ''
        check(count("SELECT count(*) FROM pg_class WHERE relname='invite_requests' AND relkind='r'") == 1, 'migration: invite_requests exists after schema.sql + migration ×2 + grants')
        cons = sql("SELECT string_agg(conname, ',' ORDER BY conname) FROM pg_constraint WHERE conrelid='public.invite_requests'::regclass")
        check('invite_requests_shape' in cons and 'invite_requests_status_hash_key' in cons, 'migration: CHECK shape + UNIQUE(status_hash) %s' % cons)
        idx = count("SELECT count(*) FROM pg_indexes WHERE tablename='invite_requests'")
        check(idx == 6, 'migration: indexes (pk + unique + four) = %d' % idx)
        priv = sql("SELECT has_table_privilege('tbox_app','public.invite_requests','DELETE')::text || has_table_privilege('tbox_app','public.invite_requests','SELECT')::text"
                   " || has_column_privilege('tbox_app','public.invite_requests','name','UPDATE')::text || has_column_privilege('tbox_app','public.invite_requests','status','UPDATE')::text"
                   " || has_column_privilege('tbox_app','public.invite_requests','created_at','UPDATE')::text || has_column_privilege('tbox_app','public.invite_requests','status','INSERT')::text"
                   " || has_table_privilege('tbox_backup','public.invite_requests','INSERT')::text")
        check(priv == 'falsetruefalsetruefalsefalsefalse', 'grants: tbox_app DELETE 0 · SELECT · UPDATE(name) 0 · UPDATE(status) · UPDATE(created_at) 0 · INSERT(status) 0 · backup INSERT 0 (%s)' % priv)
        results['schema_log_errors'] = log.count('ERROR')
    guard('shape', shape)

    # ── 화면 둘 — 공개 · 로그인한 사람은 / ─────────────────────────────────────
    def pages():
        for path in ('/request-invite', '/request-invite/status'):
            r = requests.get(TB + path, headers={'User-Agent': UA}, timeout=30, allow_redirects=False)
            setc = r.headers.get('Set-Cookie', '')
            check(r.status_code == 200 and 'invite-request.js' in r.text and '__Host-moa_join=' in setc and 'HttpOnly' in setc and 'SameSite=Strict' in setc,
                  'page %s: public 200 · join CSRF cookie (HttpOnly · Strict) · script' % path)
            check('H2합성' not in r.text and 'invite_requests' not in r.text, 'page %s: skeleton only (no data)' % path)
            r2 = req('GET', path, join=False, user='employee', csrf=False)
            check(r2.status_code == 302 and r2.headers.get('Location') == '/', 'page %s: signed-in → 302 / (%s %s)' % (path, r2.status_code, r2.headers.get('Location')))
        r = requests.get(TB + '/request-invite', headers={'User-Agent': UA}, timeout=30)
        check(all(x in r.text for x in ('id="invite-request-name"', 'id="invite-request-org"', 'id="invite-request-reason"', 'id="invite-request-agree"',
                                         'name="website"', 'tabindex="-1"', 'data-trap', 'aria-hidden="true"', 'maxlength="40"', 'maxlength="80"', 'maxlength="500"')),
              'page /request-invite: three fields + agree + hidden honeypot (tabindex -1 · aria-hidden · autocomplete off)')
    guard('pages', pages)

    # ── 요청 · 검사 · 속도 · 미끼 · 상한 ───────────────────────────────────────
    state = {}

    def create():
        before = count('SELECT count(*) FROM invite_requests')
        r, d, code = new_request('A')
        check(r.status_code == 201 and list(d.keys()) == ['status_path'] and code is not None, 'create: 201 {status_path} only · code 64 hex (%s keys %s)' % (r.status_code, list(d.keys())))
        state['A'] = code
        codes_seen.append(code)
        names_seen.extend(['H2합성이름A', 'H2합성소속A', 'H2합성이유A'])
        h = hashlib.sha256(code.encode()).hexdigest()
        check(count('SELECT count(*) FROM invite_requests WHERE status_hash=' + lit(h)) == 1 and count('SELECT count(*) FROM invite_requests') == before + 1,
              'create: one row · stored = sha256(code)')
        dump = sql("SELECT coalesce(string_agg(t::text, ' '), '') FROM invite_requests t")
        check(code not in dump, 'create: plaintext code nowhere in the table')
        rowv = sql("SELECT name || '|' || org || '|' || status || '|' || host(ip) || '|' || (reason LIKE '%' || chr(10) || '%')::text FROM invite_requests WHERE status_hash=" + lit(h))
        check(rowv == 'H2합성이름A|H2합성소속A|open|127.0.0.1|true', 'create: name · org · status open · ip · reason keeps line break')
        # 400 — 키 · 길이 · 제어 문자 · agree
        base = {'name': '이름', 'org': '소속', 'reason': '이유', 'agree': True}
        cases = [('unknown key', dict(base, email='x@example.invalid')), ('name 41', dict(base, name='가' * 41)), ('org 81', dict(base, org='가' * 81)),
                 ('reason 501', dict(base, reason='가' * 501)), ('name blank', dict(base, name='   ')), ('name control', dict(base, name='이름\u0007')),
                 ('org newline', dict(base, org='소속\n둘')), ('reason control end', dict(base, reason='이유\u0000')), ('reason control mid', dict(base, reason='이\u0008유')), ('format char', dict(base, name='이‮름')),
                 ('agree false', dict(base, agree=False)), ('agree "true"', dict(base, agree='true')), ('agree missing', {k: v for k, v in base.items() if k != 'agree'}),
                 ('name number', dict(base, name=12)), ('website number', dict(base, website=1))]
        n0 = count('SELECT count(*) FROM invite_requests')
        for label, body in cases:
            r = req('POST', '/api/invite-requests', body)
            check(r.status_code == 400, 'create 400: %s (%d)' % (label, r.status_code))
        r = req('POST', '/api/invite-requests', raw_body='{"name":')
        check(r.status_code == 400, 'create 400: broken JSON')
        r = req('POST', '/api/invite-requests', base, join=False)
        check(r.status_code == 403, 'create: no join CSRF → 403 (%d)' % r.status_code)
        r = requests.post(TB + '/api/invite-requests', json=base, headers={'User-Agent': UA, 'Cookie': '__Host-moa_join=' + JOIN, 'X-Join-CSRF': secrets.token_hex(32)}, timeout=30)
        check(r.status_code == 403, 'create: mismatched join CSRF → 403 (%d)' % r.status_code)
        r = req('POST', '/api/invite-requests', base, user='employee')
        check(r.status_code == 409, 'create: signed-in → 409 (%d)' % r.status_code)
        check(count('SELECT count(*) FROM invite_requests') == n0, 'create: rejected requests left no rows')
        # 1시간 4번째 429
        r2, _, c2 = new_request('B')
        r3, _, c3 = new_request('C')
        state['B'], state['C'] = c2, c3
        codes_seen.extend([c2, c3])
        deny0 = count("SELECT count(*) FROM audit_logs WHERE action='invite_request.create' AND result='deny' AND reason='rate_limited'")
        r4, d4, _ = new_request('D')
        check(r2.status_code == 201 and r3.status_code == 201 and r4.status_code == 429 and r4.headers.get('Retry-After') == '3600',
              'rate: per IP 1 hour — 2nd · 3rd 201 · 4th 429 Retry-After 3600 (%d %d %d)' % (r2.status_code, r3.status_code, r4.status_code))
        check(count("SELECT count(*) FROM audit_logs WHERE action='invite_request.create' AND result='deny' AND reason='rate_limited'") == deny0 + 1, 'rate: 429 → deny rate_limited audit')
        # 24시간 6번째 429 — 앞 셋을 2시간 전으로
        sql("UPDATE invite_requests SET created_at = now() - interval '2 hours' WHERE ip='127.0.0.1' AND created_at > now() - interval '1 hour'")
        r5, _, c5 = new_request('E')
        r6, _, c6 = new_request('F')
        r7, _, _ = new_request('G')
        state['E'], state['F'] = c5, c6
        codes_seen.extend([c5, c6])
        check(r5.status_code == 201 and r6.status_code == 201 and r7.status_code == 429, 'rate: per IP 24 hours — 4th · 5th 201 · 6th 429 (%d %d %d)' % (r5.status_code, r6.status_code, r7.status_code))
        reset_ip()
        # 미끼 칸 — 같은 201 모양 · 행 0 · deny
        n1 = count('SELECT count(*) FROM invite_requests')
        hp0 = count("SELECT count(*) FROM audit_logs WHERE action='invite_request.create' AND result='deny' AND reason='honeypot'")
        r = req('POST', '/api/invite-requests', dict(base, name='H2미끼이름', website='https://spam.example.invalid'))
        d = js(r)
        m = re.fullmatch(r'/tbox/request-invite/status#code=([0-9a-f]{64})', str(d.get('status_path', '')))
        check(r.status_code == 201 and list(d.keys()) == ['status_path'] and m is not None and r.headers.get('Content-Type') == r2.headers.get('Content-Type'),
              'honeypot: same 201 {status_path} shape (64 hex) as a real request')
        check(count('SELECT count(*) FROM invite_requests') == n1 and count("SELECT count(*) FROM invite_requests WHERE name='H2미끼이름'") == 0, 'honeypot: no DB row')
        check(count("SELECT count(*) FROM audit_logs WHERE action='invite_request.create' AND result='deny' AND reason='honeypot'") == hp0 + 1, 'honeypot: deny honeypot audit')
        if m:
            r = status(m.group(1))
            check(r.status_code == 404, 'honeypot: its fake code is a wrong code (404)')
            codes_seen.append(m.group(1))
        # 열린 요청 200 → 503
        open_now = count("SELECT count(*) FROM invite_requests WHERE status='open'")
        sql("INSERT INTO invite_requests(name,org,reason,status_hash,ip) SELECT 'H2채움', 'H2채움', 'H2채움', encode(sha256(convert_to(gen_random_uuid()::text,'UTF8')),'hex'), '10.9.0.1' FROM generate_series(1," + str(200 - open_now) + ")")
        check(count("SELECT count(*) FROM invite_requests WHERE status='open'") == 200, 'cap: 200 open requests prepared')
        r = req('POST', '/api/invite-requests', base)
        check(r.status_code == 503 and count("SELECT count(*) FROM audit_logs WHERE action='invite_request.create' AND reason='too_many_open'") == 1,
              'cap: 200 open → 503 · deny too_many_open (%d)' % r.status_code)
        sql("DELETE FROM invite_requests WHERE name='H2채움'")
        reset_ip()
    guard('create', create)

    # ── 관리자 목록 · 결정 ───────────────────────────────────────────────────
    def admin():
        r = req('GET', '/api/invite-requests', join=False, user='employee')
        check(r.status_code == 403, 'list: employee → 403 (%d)' % r.status_code)
        r = req('GET', '/api/invite-requests', join=False, user='manager')
        check(r.status_code == 403, 'list: manager → 403 (%d)' % r.status_code)
        reauth('admin', False)
        r = req('GET', '/api/invite-requests', join=False, user='admin')
        check(r.status_code == 403 and js(r).get('next') == 'reauth', 'list: admin without reauth → 403 next:reauth')
        a_id = row_of(state['A'])[0]
        r = req('POST', '/api/invite-requests/%d/decide' % a_id, {'action': 'approve'}, join=False, user='admin')
        check(r.status_code == 403 and js(r).get('next') == 'reauth', 'decide: admin without reauth → 403 next:reauth')
        r = req('POST', '/api/invite-requests/%d/decide' % a_id, {'action': 'approve'}, join=False, user='employee')
        check(r.status_code == 403, 'decide: employee → 403')
        reauth('admin')
        r = req('GET', '/api/invite-requests', join=False, user='admin')
        d = js(r)
        rows = d.get('requests', [])
        check(r.status_code == 200 and d.get('open_count') == len([x for x in rows if x['status'] == 'open']) and rows and rows[0]['status'] == 'open',
              'list: admin (reauth) 200 · open first · open_count %s' % d.get('open_count'))
        check(all(set(x.keys()) == {'id', 'name', 'org', 'reason', 'status', 'created_at', 'decided_at', 'team_id', 'team_name', 'decision_note', 'issued_count', 'decided_by', 'joined'} for x in rows)
              and 'status_hash' not in r.text and '127.0.0.1' not in r.text, 'list: fields fixed · no hash · no IP')
        check(any(x['name'] == 'H2합성이름A' and x['org'] == 'H2합성소속A' and x['reason'].startswith('H2합성이유A') for x in rows), 'list: name · org · reason visible to the admin')
        ids = {k: row_of(state[k])[0] for k in ('A', 'B', 'C', 'E', 'F')}
        state['ids'] = ids
        for label, body, want in [('unknown action', {'action': 'maybe'}, 400), ('unknown key', {'action': 'approve', 'who': 1}, 400),
                                  ('team string', {'action': 'approve', 'team_id': '1'}, 400), ('reject with team', {'action': 'reject', 'team_id': team_a}, 400),
                                  ('missing team', {'action': 'approve', 'team_id': 999999}, 400), ('note 301', {'action': 'reject', 'note': '가' * 301}, 400), ('note control', {'action': 'reject', 'note': '메모\u0000'}, 400)]:
            r = req('POST', '/api/invite-requests/%d/decide' % ids['A'], body, join=False, user='admin')
            check(r.status_code == want, 'decide %d: %s (%d)' % (want, label, r.status_code))
        r = req('POST', '/api/invite-requests/999999/decide', {'action': 'reject'}, join=False, user='admin')
        check(r.status_code == 404, 'decide: unknown request 404 (%d)' % r.status_code)
        r = req('POST', '/api/invite-requests/%d/decide' % ids['A'], {'action': 'approve', 'team_id': team_a}, join=False, user='admin')
        check(r.status_code == 200 and js(r).get('status') == 'approved', 'decide: approve A with team A → 200')
        r = req('POST', '/api/invite-requests/%d/decide' % ids['A'], {'action': 'reject'}, join=False, user='admin')
        check(r.status_code == 409, 'decide: already decided → 409 (%d)' % r.status_code)
        r = req('POST', '/api/invite-requests/%d/decide' % ids['B'], {'action': 'reject', 'note': 'H2합성메모 관리자만'}, join=False, user='admin')
        check(r.status_code == 200, 'decide: reject B with note → 200')
        r = req('POST', '/api/invite-requests/%d/decide' % ids['C'], {'action': 'approve'}, join=False, user='admin')
        check(r.status_code == 200, 'decide: approve C without team → 200')
        row = sql('SELECT status || \'|\' || coalesce(team_id::text,\'-\') || \'|\' || decided_by || \'|\' || (decided_at IS NOT NULL)::text FROM invite_requests WHERE id=' + str(ids['A']))
        check(row == 'approved|%d|%d|true' % (team_a, ADM), 'decide: row approved · team · decided_by · decided_at (%s)' % row)
        names_seen.append('H2합성메모')
    guard('admin', admin)

    # ── 확인 주소 · 발급 · 가입 ─────────────────────────────────────────────
    def statuses():
        sql('DELETE FROM member_rate_limits')
        r = status(secrets.token_hex(32))
        check(r.status_code == 404 and js(r).get('error'), 'status: wrong code → 404')
        r = status('XYZ')
        check(r.status_code == 404, 'status: malformed code → 404 (same)')
        r = req('POST', '/api/invite-requests/status', {'code': state['E'], 'x': 1})
        check(r.status_code == 400, 'status: extra key → 400')
        r = req('POST', '/api/invite-requests/status', {'code': state['E']}, join=False)
        check(r.status_code == 403, 'status: no join CSRF → 403')
        r = status(state['E'])
        check(r.status_code == 200 and js(r) == {'status': 'open'}, 'status: open → {status: open}')
        r = status(state['B'])
        check(r.status_code == 200 and js(r) == {'status': 'rejected'} and 'H2합성메모' not in r.text, 'status: rejected → {status: rejected} · note 0')
        inv0 = count('SELECT count(*) FROM member_invites')
        iss0 = count("SELECT count(*) FROM audit_logs WHERE action='invite_request.issue' AND result='allow'")
        r = status(state['A'])
        d = js(r)
        m = re.fullmatch(r'/tbox/join#invite=([0-9a-f]{64})', str(d.get('invite_path', '')))
        check(r.status_code == 200 and d.get('status') == 'approved' and m is not None, 'status: approved → invite_path /tbox/join#invite=<64 hex>')
        ids = state['ids']
        inv = sql("SELECT i.id || '|' || i.max_uses || '|' || i.used_count || '|' || coalesce(i.team_id::text,'-') || '|' || i.label || '|' || i.created_by || '|' "
                  "|| (i.expires_at BETWEEN now() + interval '7 days' - interval '2 minutes' AND now() + interval '7 days' + interval '1 minute')::text "
                  "FROM member_invites i JOIN invite_requests r ON r.invite_id = i.id WHERE r.id=" + str(ids['A']))
        parts = inv.split('|') if inv else []
        check(len(parts) == 7 and parts[1] == '1' and parts[2] == '0' and parts[3] == str(team_a) and parts[4] == '초대 요청 #%d' % ids['A'] and parts[5] == str(ADM) and parts[6] == 'true',
              'issue: member_invites 1-use · 7 days · team A · label 「초대 요청 #번호」 · created_by approving admin (%s)' % inv.replace('H2', ''))
        check('H2' not in inv, 'issue: invite label has no requester name')
        check(count('SELECT count(*) FROM member_invites') == inv0 + 1 and count("SELECT count(*) FROM audit_logs WHERE action='invite_request.issue' AND result='allow'") == iss0 + 1,
              'issue: one invite · one invite_request.issue audit')
        if m:
            code = m.group(1)
            codes_seen.append(code)
            check(count('SELECT count(*) FROM member_invites WHERE code_hash=' + lit(hashlib.sha256(code.encode()).hexdigest())) == 1, 'issue: invite stored as sha256 only (same as member_invites)')
            r = req('POST', '/api/register', {'invite': code, 'username': 'h2_joined01', 'display_name': 'H2 합성 가입자', 'password': 'H2-invite-synthetic-only-2026!',
                                              'agree_terms': True, 'age14': True})
            check(r.status_code == 201, 'join: register with the issued invite → 201 (%d)' % r.status_code)
            check(sql("SELECT coalesce(team_id::text,'-') || '|' || role FROM users WHERE username='h2_joined01'") == '%d|Employee' % team_a, 'join: new account in team A · Employee')
            r = status(state['A'])
            check(r.status_code == 200 and js(r) == {'status': 'joined'}, 'status: after joining → {status: joined} (no new invite)')
            check(count('SELECT count(*) FROM member_invites') == inv0 + 1, 'status: joined does not issue another invite')
        # C: 다시 열면 앞 초대 거둠 + 새 발급 · 세 번 · 넷째 409
        paths = []
        for i in range(3):
            r = status(state['C'])
            d = js(r)
            paths.append(d.get('invite_path'))
            check(r.status_code == 200 and d.get('status') == 'approved' and isinstance(d.get('invite_path'), str), 'reissue: open #%d → new invite' % (i + 1))
        codes_seen.extend([p.split('=')[-1] for p in paths if isinstance(p, str)])
        rows = sql("SELECT string_agg((revoked_at IS NOT NULL)::text, ',' ORDER BY id) FROM member_invites WHERE label=" + lit('초대 요청 #%d' % ids['C']))
        check(rows == 'true,true,false' and len(set(paths)) == 3, 'reissue: earlier two invites revoked · latest open (%s)' % rows)
        check(sql('SELECT issued_count FROM invite_requests WHERE id=' + str(ids['C'])) == '3', 'reissue: issued_count 3')
        lim0 = count("SELECT count(*) FROM audit_logs WHERE action='invite_request.issue' AND reason='issue_limit'")
        r = status(state['C'])
        check(r.status_code == 409 and '세 번' in js(r).get('error', ''), 'reissue: 4th open → 409 「세 번」')
        check(count("SELECT count(*) FROM audit_logs WHERE action='invite_request.issue' AND reason='issue_limit'") == lim0 + 1, 'reissue: 409 → deny issue_limit audit')
        check(sql("SELECT string_agg((revoked_at IS NOT NULL)::text, ',' ORDER BY id) FROM member_invites WHERE label=" + lit('초대 요청 #%d' % ids['C'])) == 'true,true,false',
              'reissue: 409 keeps the third invite usable')
        if isinstance(paths[0], str):
            r = req('POST', '/api/register', {'invite': paths[0].split('=')[-1], 'username': 'h2_revoked01', 'display_name': 'H2', 'password': 'H2-invite-synthetic-only-2026!',
                                              'agree_terms': True, 'age14': True})
            check(r.status_code == 410, 'reissue: a revoked earlier invite cannot register (410 · %d)' % r.status_code)
        # 1분 21번째 429
        sql('DELETE FROM member_rate_limits')
        codes = [status(state['E']).status_code for _ in range(20)]
        r21 = status(state['E'])
        check(all(c == 200 for c in codes) and r21.status_code == 429, 'status rate: 20 per minute ok · 21st → 429 (%d)' % r21.status_code)
        sql('DELETE FROM member_rate_limits')
    guard('status', statuses)

    # ── 감사 · 로그에 이름 · 소속 · 이유 · 코드 0 ─────────────────────────────
    def audit():
        rows = sql("SELECT action || '|' || result || '|' || coalesce(reason,'') || '|' || count(*) FROM audit_logs WHERE action LIKE 'invite_request.%' GROUP BY action, result, reason ORDER BY 1")
        results['audit'] = rows.splitlines()
        for want in ('invite_request.create|allow|', 'invite_request.create|deny|rate_limited', 'invite_request.create|deny|honeypot', 'invite_request.create|deny|too_many_open',
                     'invite_request.decide|allow|approve', 'invite_request.decide|allow|reject', 'invite_request.issue|allow|', 'invite_request.issue|deny|issue_limit'):
            check(any(x.startswith(want) for x in rows.splitlines()), 'audit: %s present' % want)
        summ = sql("SELECT string_agg(DISTINCT action || ' :: ' || summary, chr(10)) FROM audit_logs WHERE action LIKE 'invite_request.%'")
        results['summaries'] = summ.splitlines()
        check(count("SELECT count(*) FROM audit_logs WHERE action LIKE 'invite_request.%' AND (summary IS NULL OR summary = '')") == 0, 'audit: every row has a summary sentence')
        check(count("SELECT count(*) FROM audit_logs WHERE action='invite_request.create' AND result='allow' AND target_name ~ '^초대 요청 #[0-9]+$'") ==
              count("SELECT count(*) FROM audit_logs WHERE action='invite_request.create' AND result='allow'"), 'audit: target name 「초대 요청 #번호」')
        blob = sql("SELECT coalesce(string_agg(coalesce(summary,'') || coalesce(target_name,'') || coalesce(detail::text,''), ' '), '') FROM audit_logs")
        check(not any(n in blob for n in names_seen), 'audit: requester name · org · reason · note appear in 0 audit rows')
        check(not any(c in blob for c in codes_seen if c), 'audit: status / invite codes appear in 0 audit rows (%d codes)' % len(codes_seen))
        afile = (WORK / 'logs/audit.log')
        text = afile.read_text(errors='replace') if afile.exists() else ''
        check(text != '' and not any(c in text for c in codes_seen if c) and not any(n in text for n in names_seen), 'audit file: codes · names 0')
        plog = (WORK / 'logs/php.log').read_text(errors='replace')
        check('/tbox/api/invite-requests/status' in plog and not any(c in plog for c in codes_seen if c), 'access log (php -S): request paths logged · codes 0')
        check(bad5xx == [], 'no 5xx during the run (except the intended 503 cap) %s' % bad5xx)
    guard('audit', audit)

    # ── 처리방침 새 판 · 옛 판 · 동의 관문 ──────────────────────────────────
    def privacy():
        r = requests.get(TB + '/privacy', headers={'User-Agent': UA}, timeout=30)
        check(r.status_code == 200 and '이 방침은 2026-10-14부터 적용됩니다.' in r.text and '초대 요청 심사와 결과 안내' in r.text
              and '초대를 요청할 때: 이름, 소속(회사 · 팀), 쓰려는 이유, 접속 IP 주소' in r.text and '처리한 날부터 30일(처리하지 않은 요청은 접수한 날부터 30일) 뒤 삭제' in r.text
              and '이메일·전화번호·생년월일·주민등록번호를 받지 않습니다' in r.text, '/tbox/privacy: new 2026-10-14 edition (four edits · no-email sentence kept)')
        r = requests.get(TB + '/privacy?v=2026-10-08', headers={'User-Agent': UA}, timeout=30)
        check(r.status_code == 200 and '이 방침은 2026-10-08부터 적용됩니다.' in r.text and '초대 요청 심사' not in r.text, '/tbox/privacy?v=2026-10-08: old edition')
        mid = uid('manager')
        sql("INSERT INTO user_consents(user_id,doc,version,action,ip) VALUES(" + str(mid) + ",'privacy','2026-10-08','agree','127.0.0.1')")
        r = req('GET', '/api/teams', join=False, user='manager')
        check(r.status_code == 403 and js(r).get('next') == 'consent', 'consent: member with the old privacy edition → 403 next:consent (%d)' % r.status_code)
        r = req('POST', '/api/me/consents', {'doc': 'terms'}, join=False, user='manager')
        check(r.status_code in (200, 201), 'consent: agree → %d' % r.status_code)
        r = req('GET', '/api/teams', join=False, user='manager')
        check(r.status_code == 200, 'consent: after agreeing → 200')
        check(sql("SELECT version FROM user_consents WHERE user_id=%d AND doc='privacy' ORDER BY id DESC LIMIT 1" % mid) == '2026-10-14', 'consent: newest privacy row = 2026-10-14')
    guard('privacy', privacy)

    # ── AL-25 — 1시간 21건 → 경보 한 줄 ─────────────────────────────────────
    def al25():
        n = count("SELECT count(*) FROM audit_logs WHERE action='invite_request.create' AND result='allow' AND happened_at > now() - interval '1 hour'")
        made = 0
        while n < 21:
            reset_ip()
            r, _, c = new_request('Z%d' % made)
            codes_seen.append(c)
            made += 1
            if r.status_code != 201:
                break
            n += 1
        reset_ip()
        check(n == 21, 'AL-25: 21 invite_request.create allow rows in the last hour (%d made here)' % made)
        state_file = WORK / 'al25-state.json'
        empty = WORK / 'al25-empty-auth.log'
        empty.write_text('')
        r = subprocess.run(['php', str(root / 'scripts/alerts.php'), '--rule', 'AL-25', '--dry-run', '--state', str(state_file), '--auth-log', str(empty)],
                           cwd='/', capture_output=True, text=True, timeout=120)
        (out / 'al25-dryrun.txt').write_text('$ php scripts/alerts.php --rule AL-25 --dry-run\nexit=' + str(r.returncode) + '\n' + r.stdout + r.stderr)
        lines = [x for x in r.stdout.splitlines() if '[dry-run] AL-25' in x]
        check(r.returncode == 0 and len(lines) == 1 and '[dry-run] AL-25 requests' in lines[0] and '"count":21' in lines[0], 'AL-25: dry-run → one alert (requests · count 21)')
        check('AL-25 초대 요청 1시간 21건' in r.stdout and not any(nm in r.stdout for nm in names_seen), 'AL-25: summary line · names 0')
    guard('al25', al25)

    # ── 보관 기한 삭제 — privacy_purge 한 번 ────────────────────────────────
    def retention():
        def pg_escape(v): return v.replace('\\', '\\\\').replace(':', '\\:')
        pgpass = WORK / 'h2-owner.pgpass'
        pgpass.write_text(':'.join([pg_escape(FIX['DB_HOST']), pg_escape(FIX.get('DB_PORT', '5432') or '5432'), pg_escape(FIX['DB_NAME']), 'tbox_testdb', pg_escape(FIX['DB_PASS'])]) + '\n')
        pgpass.chmod(0o600)
        env = dict(os.environ, TBOX_OWNER_PGPASS=str(pgpass), TBOX_OWNER_ROLE='tbox_testdb')
        mk = ("INSERT INTO invite_requests(name,org,reason,status_hash,ip,status,decided_by,decided_at,created_at) VALUES"
              "('H2보관','H2보관','old-decided',encode(sha256('h2-r1'::bytea),'hex'),'10.8.0.1','rejected'," + str(ADM) + ",now()-interval '31 days',now()-interval '40 days'),"
              "('H2보관','H2보관','old-open',encode(sha256('h2-r2'::bytea),'hex'),'10.8.0.1','open',NULL,NULL,now()-interval '31 days'),"
              "('H2보관','H2보관','keep-decided',encode(sha256('h2-r3'::bytea),'hex'),'10.8.0.1','rejected'," + str(ADM) + ",now()-interval '29 days',now()-interval '40 days'),"
              "('H2보관','H2보관','keep-open',encode(sha256('h2-r4'::bytea),'hex'),'10.8.0.1','open',NULL,NULL,now()-interval '29 days')")
        sql(mk)
        total0 = count('SELECT count(*) FROM invite_requests')
        dry = subprocess.run(['php', 'scripts/privacy_purge.php'], cwd=root, env=env, capture_output=True, text=True, timeout=120)
        (out / 'purge-dry.txt').write_text('exit=' + str(dry.returncode) + '\n' + dry.stdout + dry.stderr)
        check(dry.returncode == 0 and 'invite_requests=2' in dry.stdout and count('SELECT count(*) FROM invite_requests') == total0, 'retention: dry → invite_requests=2 · nothing deleted')
        run = subprocess.run(['php', 'scripts/privacy_purge.php', '--run'], cwd=root, env=env, capture_output=True, text=True, timeout=120)
        (out / 'purge-run.txt').write_text('exit=' + str(run.returncode) + '\n' + run.stdout + run.stderr)
        left = sql("SELECT string_agg(reason, ',' ORDER BY reason) FROM invite_requests WHERE name='H2보관'")
        check(run.returncode == 0 and left == 'keep-decided,keep-open' and count('SELECT count(*) FROM invite_requests') == total0 - 2,
              'retention: --run deletes decided 31d + open 31d · keeps 29d rows (%s)' % left)
        check(count("SELECT count(*) FROM audit_logs WHERE action='privacy.purge' AND result='allow' AND (detail->'counts'->>'invite_requests')::int = 2") == 1,
              'retention: privacy.purge audit counts.invite_requests = 2')
        check(not any(nm in run.stdout + dry.stdout for nm in names_seen + ['H2보관']), 'retention: output has counts only (names 0)')
        again = subprocess.run(['php', 'scripts/privacy_purge.php'], cwd=root, env=env, capture_output=True, text=True, timeout=120)
        check('invite_requests=0' in again.stdout, 'retention: second dry → 0')
        probe = subprocess.run(['php', '-r', "require 'src/bootstrap.php'; if (!str_starts_with(Env::get('DB_NAME'),'tbox_memories_test_')) exit(3); try { Db::instance()->run('DELETE FROM invite_requests WHERE id=0'); echo 'ok'; } catch (PDOException $e) { echo $e->getCode(); }"],
                               cwd=root, capture_output=True, text=True, timeout=60)
        check(probe.stdout.strip() == '42501', 'retention: app account DELETE on invite_requests → 42501 (%s)' % probe.stdout.strip()[:20])
        pgpass.unlink()
    guard('retention', retention)
finally:
    (out / 'invite-request-http-metrics.json').write_text(json.dumps(results, ensure_ascii=False, indent=1))
    (out / 'invite-request-http-results.json').write_text(json.dumps(checks, ensure_ascii=False, indent=2))
    print('RESULT pass=' + str(sum(x['pass'] for x in checks)) + ' fail=' + str(sum(not x['pass'] for x in checks)), flush=True)
if any(not x['pass'] for x in checks):
    raise SystemExit(1)
