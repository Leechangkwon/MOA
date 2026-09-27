#!/usr/bin/env python3
# -*- coding: utf-8 -*-
# tests/d74_username_api.py — t-box d74 관리자 「로그인 아이디 바꾸기」 창구 수락 시험 (구현 기록 docs/d74-username-change-implementation.md)
#
# 부르는 쪽: tests/d74_username.sh (임시 인스턴스 · 임시 DB `tbox_d74_*`. 운영 80·8082·8081 에는 요청하지 않는다).
# 쓰는 법: python3 d74_username_api.py <SITE> <UA> <DB> <임시 .env> <임시 앱 폴더> <작업 폴더>
#   검토 계정 다섯 줄(`아이디 비밀번호 2단계비밀`)은 **표준입력**으로만 받는다(R-62 — 명령 인자에 비밀 0).
#   claude_admin01 · claude_manager01 · claude_employee01 · claude_employee02 · claude_guest01 순서.
#
# 이름을 바꿔 볼 대상은 이 시험이 임시 DB 안에서 claude_admin01 로 **새로 만드는** 사람 계정 둘이다(시험용 이름 `d74…` —
# 운영 계정·시드 계정은 쓰지 않는다): T1(직원 · 개발팀) · T2(관리자). 둘 다 창구로 첫 로그인 절차(비밀번호 · 2단계)를 마친다.
#
# 보는 것 (항목 머리는 출력의 「──」 줄)
#   P  양성    관리자 + 재인증 → 200 · 응답/목록 새 이름 · 감사 user.update allow(target_name=옛 이름 · changed_fields=[username] ·
#              새 이름은 행 어디에도 없음 · sessions_revoked 0) · 옛 이름 로그인 = 없는 아이디와 같은 모양(상태·본문·쿠키 0) ·
#              새 이름 로그인(비밀번호·2단계 그대로) · 바꾼 사람·바뀐 사람 세션 유지 · 개인 토큰 유지 · 공용 토큰(다른 계정 귀속) 유지
#   N  음성    팀장·직원·손님 403 · 재인증 없음 403 next:reauth · CSRF 없음·남의 CSRF 403 · 형식(짧음·김·대문자·빈칸·숫자 아님·줄바꿈 끝/가운데 — RV-D74-1)·
#              검토 접두 400 · 이미 있음 400(대소문자는 형식 400 — 계정 만들기와 같은 표) · 권한 칸과 섞음 400 · 같은 이름 400 ·
#              사유 없음 400 · 검토 계정 대상 400 · 자기 자신 400 · 공용 토큰 켜짐(지금 이름·새 이름) 409 / 꺼짐이면 통과 · 거부마다 감사 행
#   C  확인    잠금 집계(행에 붙음 · 옛 이름 시도는 세지 않음) · AL-2(옛 이름 시도도 IP 로 셈) · AL-1(이름 묶음 — 바꾸면 갈라짐) ·
#              멘션(알림은 번호 · 본문은 글자) · 휴대폰 경보 받기(번호) · 감사 거르기(이름 그대로 일치) · 노출(댓글 작성자 · 공유 후보 ·
#              알림 보낸 사람) · 로그인 응답 시간(없는 아이디 · 틀린 비밀번호)
# 나가는 값: 마지막 줄 `SMOKE_FAIL=<n>` · 종료 코드 0/1
import base64
import hashlib
import hmac
import http.client
import json
import os
import re
import secrets
import struct
import subprocess
import sys
import time
import urllib.parse

SITE, UA, DB, ENVF, APP, WORK = sys.argv[1:7]
_u = urllib.parse.urlparse(SITE)
HOST, PORT = _u.hostname, _u.port
if PORT in (80, 8082, 8081) or not DB.startswith('tbox_d74_') or not DB.endswith('_test'):
    print('운영 포트·임시 DB 이름 규칙 밖 — 시작하지 않는다')
    sys.exit(3)
ACC = {}
for _ in range(5):
    parts = sys.stdin.readline().split()
    if len(parts) == 3:
        ACC[parts[0]] = {'pw': parts[1], 'sec': parts[2]}
for need in ('claude_admin01', 'claude_manager01', 'claude_employee01', 'claude_employee02', 'claude_guest01'):
    if need not in ACC:
        print('검토 계정 비밀을 표준입력으로 받지 못했다(%s) — 시작하지 않는다' % need)
        sys.exit(3)

PASS = 0
FAIL = 0
NOTES = []


def ok(name, cond, detail=''):
    global PASS, FAIL
    if cond:
        PASS += 1
        print('  ✔ %s' % name, flush=True)
    else:
        FAIL += 1
        print('  ✖ %s  — %s' % (name, str(detail)[:500]), flush=True)


def note(s):
    NOTES.append(s)
    print('  · %s' % s, flush=True)


def head(s):
    print('\n── %s' % s, flush=True)


def sql(q):
    """임시 DB 한 모양(표준입력) — 운영 DB 에는 닿지 않는다(DB 이름 규칙은 위에서 확인)"""
    r = subprocess.run(['sudo', '-n', '-u', 'postgres', 'psql', '-X', '-q', '-At', '-d', DB],
                       input=q, capture_output=True, text=True)
    return r.stdout.strip()


def amax():
    return int(sql('SELECT coalesce(max(id),0) FROM audit_logs;') or 0)


def arows(since, extra=''):
    """since 뒤 감사 행 — (action, result, reason, target_name, detail, summary, actor_name)"""
    out = sql("SELECT json_agg(json_build_object('a',action,'r',result,'why',reason,'t',target_name,'d',detail,'s',summary,'actor',actor_name,'tid',target_id) ORDER BY id) "
              "FROM audit_logs WHERE id > %d %s;" % (since, extra))
    return json.loads(out) if out else []


# ─── HTTP ──────────────────────────────────────────────────────────
class Resp:
    def __init__(self, status, headers, body, secs):
        self.status, self.headers, self.raw, self.secs = status, headers, body, secs
        try:
            self.json = json.loads(body.decode('utf-8')) if body else None
        except Exception:
            self.json = None

    def cookies(self):
        return [v for k, v in self.headers if k.lower() == 'set-cookie']


def req(method, path, sess=None, body=None, csrf=None, token=None, src=None, raw_csrf=False):
    h = {'User-Agent': UA}
    if sess:
        h['Cookie'] = '__Host-tbox_session=' + sess
    if csrf is not None:
        h['X-CSRF-Token'] = csrf
    if token:
        h['X-API-Token'] = token
    data = None
    if body is not None:
        data = json.dumps(body).encode()
        h['Content-Type'] = 'application/json'
    c = http.client.HTTPConnection(HOST, PORT, timeout=60, source_address=(src, 0) if src else None)
    t0 = time.monotonic()
    c.request(method, path, body=data, headers=h)
    r = c.getresponse()
    b = r.read()
    t1 = time.monotonic()
    c.close()
    return Resp(r.status, r.getheaders(), b, t1 - t0)


def session_from(resp):
    for v in resp.cookies():
        m = re.match(r'__Host-tbox_session=([0-9a-f]{64});', v)
        if m:
            return m.group(1)
    return None


# ─── TOTP (RFC 6238 · 30초 · 6자리) — 같은 창 코드를 두 번 쓰지 않게 계정별로 마지막 창을 기억한다 ──
LAST_STEP = {}


def totp(secret, step):
    key = base64.b32decode(secret.upper() + '=' * (-len(secret) % 8))
    mac = hmac.new(key, struct.pack('>Q', step), hashlib.sha1).digest()
    o = mac[-1] & 0x0F
    return '%06d' % ((struct.unpack('>I', mac[o:o + 4])[0] & 0x7FFFFFFF) % 1000000)


def fresh_code(who, secret):
    while True:
        step = int(time.time()) // 30
        if LAST_STEP.get(who, -1) < step and (time.time() % 30) < 27:
            LAST_STEP[who] = step
            return totp(secret, step)
        time.sleep(31 - (time.time() % 30) if (time.time() % 30) >= 27 else 0.5)


class Who:
    def __init__(self, name, pw, sec):
        self.name, self.pw, self.sec = name, pw, sec
        self.sess = None
        self.csrf = None

    def login(self, src=None, key=None):
        for _ in range(3):
            code = fresh_code(key or self.name, self.sec) if self.sec else None
            b = {'username': self.name, 'password': self.pw}
            if code:
                b['otp'] = code
            r = req('POST', '/tbox/api/login', body=b, src=src)
            s = session_from(r)
            if r.status == 200 and s:
                self.sess = s
                self.me()
                return r
            if r.json and 'code_reused' in json.dumps(r.json, ensure_ascii=False):
                continue
            return r
        return r

    def me(self):
        r = req('GET', '/tbox/api/me', self.sess)
        if r.status == 200 and r.json:
            self.csrf = r.json.get('csrf_token')
        return r

    def call(self, method, path, body=None, csrf='mine', src=None):
        c = self.csrf if csrf == 'mine' else csrf
        return req(method, path, self.sess, body, c, src=src)

    def reauth(self):
        return self.call('POST', '/tbox/api/me/reauth', {'password': self.pw})


def rename(actor, uid, new, reason='d74 시험 — 아이디 바꾸기', **kw):
    body = {'username': new}
    if reason is not None:
        body['reason_text'] = reason
    return actor.call('PATCH', '/tbox/api/users/%d' % uid, body, **kw)


def uid_of(name):
    v = sql("SELECT id FROM users WHERE username = '%s';" % name)
    return int(v) if v.isdigit() else None


def set_shared_token(value, user):
    """임시 .env 의 공용 토큰 두 줄만 바꾼다(값은 이 시험이 만든 시험용 값 — 운영 토큰이 아니다). 앱은 요청마다 .env 를 읽는다"""
    lines = open(ENVF, encoding='utf-8').read().split('\n')
    keep = [ln for ln in lines if not ln.startswith('API_TOKEN=') and not ln.startswith('API_TOKEN_USER=')]
    if keep and keep[-1] == '':
        keep = keep[:-1]
    keep += ['API_TOKEN=' + value, 'API_TOKEN_USER=' + user, '']
    with open(ENVF, 'w', encoding='utf-8') as f:
        f.write('\n'.join(keep))
    os.chmod(ENVF, 0o600)


def alerts(rule):
    st = os.path.join(WORK, 'al-%s-%d.json' % (rule, int(time.time() * 1000)))
    r = subprocess.run(['php', os.path.join(APP, 'scripts/alerts.php'), '--rule', rule, '--dry-run', '--state', st],
                       cwd='/', capture_output=True, text=True, timeout=120)
    return r.stdout + r.stderr


def shown(label, r):
    """문서(API.md 16장)에 옮길 실제 응답 — 이름 자리는 가린다(시험용 이름도 적지 않는다)"""
    body = r.raw.decode('utf-8', 'replace')
    for n in (T1_OLD, T1_NEW, T2_NAME):
        body = body.replace(n, '<아이디>')
    note('응답 %s → %d %s' % (label, r.status, body[:300]))


def denied(since, reason, action='user.update'):
    rows = [x for x in arows(since) if x['a'] == action and x['r'] == 'deny' and x['why'] == reason]
    return len(rows)


TAG = secrets.token_hex(3)
T1_OLD, T1_NEW, T2_NAME = 'd74u' + TAG, 'd74n' + TAG, 'd74a' + TAG
NEWPW1, NEWPW2 = 'D74-' + secrets.token_urlsafe(18), 'D74-' + secrets.token_urlsafe(18)
SHARED = secrets.token_urlsafe(40)[:48]   # 시험용 공용 토큰(48자) — 운영 값이 아니다
set_shared_token('', '')                   # 시작은 꺼짐

head('준비 — 검토 계정 로그인 · 관리자 재인증 · 시험 대상 둘 만들기')
A1 = Who('claude_admin01', ACC['claude_admin01']['pw'], ACC['claude_admin01']['sec'])
M1 = Who('claude_manager01', ACC['claude_manager01']['pw'], ACC['claude_manager01']['sec'])
E1 = Who('claude_employee01', ACC['claude_employee01']['pw'], ACC['claude_employee01']['sec'])
E2 = Who('claude_employee02', ACC['claude_employee02']['pw'], ACC['claude_employee02']['sec'])
G1 = Who('claude_guest01', ACC['claude_guest01']['pw'], ACC['claude_guest01']['sec'])
for w in (A1, M1, E1, E2, G1):
    r = w.login()
    ok('%s 로그인 200' % w.name, r.status == 200 and w.sess and w.csrf, (r.status, r.json))
ok('관리자 재인증 200', A1.reauth().status == 200)
A2 = Who('claude_admin01', A1.pw, A1.sec)          # 같은 관리자, **재인증 안 한** 두 번째 세션
r = A2.login(key='claude_admin01')
ok('관리자 두 번째 세션(재인증 없음) 로그인 200', r.status == 200 and A2.sess, r.status)
team = sql("SELECT id FROM teams WHERE name = '개발팀' AND is_active;")
created = {}
for name, role, tid in ((T1_OLD, 'Employee', int(team) if team.isdigit() else None), (T2_NAME, 'Admin', None)):
    b = {'username': name, 'role': role, 'reason_text': 'd74 시험 대상'}
    if tid:
        b['team_id'] = tid
    r = A1.call('POST', '/tbox/api/users', b)
    ok('시험 대상 %s(%s) 만들기 201' % (name, role), r.status == 201 and r.json and r.json.get('temp_password'), (r.status, r.json))
    created[name] = r.json.get('temp_password') if r.json else None
T1_ID, T2_ID = uid_of(T1_OLD), uid_of(T2_NAME)


def first_login(name, temp, newpw):
    """창구로 첫 로그인 절차를 마친다 — 비밀번호 바꾸기 → 2단계 등록·확인. 2단계 비밀은 이 시험 안에만 있다"""
    w = Who(name, temp, None)
    r = w.login()
    if r.status != 200:
        return None, 'login %s %s' % (r.status, r.json)
    r = w.call('POST', '/tbox/api/me/password', {'current': temp, 'new': newpw})
    if r.status != 200:
        return None, 'password %s %s' % (r.status, r.json)
    w.pw = newpw
    if w.me().status != 200:
        r = w.login()
        if r.status != 200:
            return None, 'relogin %s' % r.status
    r = w.call('POST', '/tbox/api/me/mfa/enroll', {})
    if r.status != 200 or not r.json:
        return None, 'enroll %s %s' % (r.status, r.json)
    w.sec = r.json['secret']
    w.otpauth = r.json.get('otpauth', '')
    r = w.call('POST', '/tbox/api/me/mfa/confirm', {'otp': fresh_code(name, w.sec)})
    if r.status != 200:
        return None, 'confirm %s %s' % (r.status, r.json)
    m = w.me()
    return w, (m.json or {}).get('next')


T1, why1 = first_login(T1_OLD, created.get(T1_OLD), NEWPW1)
ok('T1 첫 로그인 절차(비밀번호 → 2단계) 끝 · 다음 걸음 없음', T1 is not None and why1 is None, why1)
T2, why2 = first_login(T2_NAME, created.get(T2_NAME), NEWPW2)
ok('T2(관리자) 첫 로그인 절차 끝', T2 is not None and why2 is None, why2)
if T1 is None or T2 is None or T1_ID is None:
    print('SMOKE_FAIL=%d' % (FAIL + 1))
    sys.exit(1)
ok('T1 인증 앱 등록 주소(otpauth)의 계정 이름 = 옛 아이디(등록 때 이름)', ('%3A' + T1_OLD) in T1.otpauth or (':' + T1_OLD) in T1.otpauth, T1.otpauth[:60].replace(T1.sec, '…'))

# 개인 토큰(T1) · 공용 토큰(다른 계정 귀속)
r = T1.call('POST', '/tbox/api/me/api-tokens', {'name': 'd74 시험', 'purpose': 'd74 시험 — 이름을 바꿔도 되나', 'scope': 'read',
                                                  'password': T1.pw, 'otp': fresh_code(T1_OLD, T1.sec)})
PTOK = r.json.get('token') if (r.status == 201 and r.json) else None
ok('T1 개인 토큰 발급 201', PTOK is not None, (r.status, r.json))
r = req('GET', '/tbox/api/whoami', token=PTOK)
ok('개인 토큰 whoami 200 · 옛 이름', r.status == 200 and (r.json or {}).get('username') == T1_OLD, (r.status, r.json))

head('C 준비 — 잠금 집계 · AL-1 · 멘션 · 휴대폰 받기 (이름을 바꾸기 전)')
for _ in range(2):
    req('POST', '/tbox/api/login', body={'username': T1_OLD, 'password': 'wrong-' + secrets.token_hex(6)}, src='127.0.0.7')
ok('T1 틀린 비밀번호 2번 → failed_count 2 (계정 행)', sql('SELECT failed_count FROM users WHERE id = %d;' % T1_ID) == '2')
for _ in range(3):
    T1.call('GET', '/tbox/api/users')
ok('AL-1 재료 — T1(옛 이름)의 관리 창구 거부 3행', sql("SELECT count(*) FROM audit_logs WHERE actor_name = '%s' AND action = 'authz.route' AND result = 'deny';" % T1_OLD) == '3')
# 멘션 — 직원(개발팀)이 개발팀 공간에 페이지를 만들고 @옛이름 댓글
space = sql("SELECT s.id FROM community.spaces s JOIN teams t ON t.id = s.team_id WHERE s.kind = 'team' AND t.name = '개발팀' AND s.is_active ORDER BY s.id LIMIT 1;")
PAGE = None
if space.isdigit():
    r = E1.call('POST', '/community/api/pages', {'title': 'd74 멘션 ' + TAG, 'space_id': int(space)})
    PAGE = (r.json or {}).get('page', {}).get('id') if r.status in (200, 201) else None
ok('멘션 재료 — 개발팀 공간에 페이지 만들기', PAGE is not None, (space, r.status if space.isdigit() else '-'))
if PAGE:
    r = E1.call('POST', '/community/api/pages/%s/comments' % PAGE, {'body': '@%s 확인 부탁 (d74 시험)' % T1_OLD})
    ok('@옛이름 댓글 201', r.status == 201, (r.status, r.json))
    ok('  T1 에게 멘션 알림 1 (알림 표는 user_id)', sql("SELECT count(*) FROM community.notifications WHERE user_id = %d AND kind = 'mention';" % T1_ID) == '1')
# 휴대폰 경보 받기(F-70) — T2(관리자)가 받기를 등록(재인증 필요). 기기 키는 P-256 공개 키(시험용으로 방금 만든 것)
T2.reauth()
PUSH_OK = False
try:
    kp = subprocess.run(['openssl', 'ecparam', '-name', 'prime256v1', '-genkey', '-noout'], capture_output=True, check=True).stdout
    pub = subprocess.run(['openssl', 'ec', '-pubout', '-outform', 'DER', '-conv_form', 'uncompressed'], input=kp, capture_output=True, check=True).stdout
    point = pub[-65:]
    p256 = base64.urlsafe_b64encode(point).rstrip(b'=').decode()
    auth = base64.urlsafe_b64encode(os.urandom(16)).rstrip(b'=').decode()
    r = T2.call('POST', '/tbox/api/admin/push/subscriptions',
                {'endpoint': 'https://fcm.googleapis.com/fcm/send/d74-' + TAG, 'keys': {'p256dh': p256, 'auth': auth}})
    PUSH_OK = r.status in (200, 201)
    note('휴대폰 경보 받기 등록 응답 %s' % r.status)
except Exception as ex:
    note('휴대폰 경보 받기 등록 준비 실패 — %s' % str(ex)[:80])
ok('F-70 받기 등록(T2) — 받기 표는 user_id', PUSH_OK and sql('SELECT count(*) FROM alert_push_subscriptions WHERE user_id = %d AND revoked_at IS NULL;' % T2_ID) == '1')

head('N 음성 — 권한 · 재인증 · CSRF')
a0 = amax()
for w, label in ((M1, '팀장'), (E1, '직원'), (G1, '손님')):
    r = rename(w, T1_ID, 'd74x' + TAG)
    ok('%s → 403' % label, r.status == 403, (r.status, r.json))
ok('  세 번 다 authz.route deny role_not_permitted 감사', denied(a0, 'role_not_permitted', 'authz.route') == 3, arows(a0)[-3:])
a0 = amax()
r = rename(A2, T1_ID, 'd74x' + TAG)
ok('재인증 없는 관리자 세션 → 403 next:reauth', r.status == 403 and (r.json or {}).get('next') == 'reauth', (r.status, r.json))
shown('재인증 없음', r)
ok('  auth.gate deny reauth_required 감사', denied(a0, 'reauth_required', 'auth.gate') == 1, arows(a0))
a0 = amax()
r = rename(A1, T1_ID, 'd74x' + TAG, csrf=None)
ok('CSRF 머리 없음 → 403', r.status == 403, (r.status, r.json))
r = rename(A1, T1_ID, 'd74x' + TAG, csrf=E1.csrf)
ok('남의 세션 CSRF → 403', r.status == 403, (r.status, r.json))
ok('  csrf deny token_mismatch 감사 2', denied(a0, 'token_mismatch', 'csrf') == 2, arows(a0))
ok('  여기까지 T1 이름 그대로', sql('SELECT username FROM users WHERE id = %d;' % T1_ID) == T1_OLD)

head('N 음성 — 이름 규칙 (계정 만들기와 같은 표)')
cases = [('ab', 400, 'bad_request', '짧음(2자)'), ('a' * 65, 400, 'bad_request', '김(65자)'),
         (T1_OLD.upper(), 400, 'bad_request', '대문자'), ('d74 x', 400, 'bad_request', '빈칸'),
         ('', 400, 'bad_request', '빈 글자'), (12345, 400, 'bad_request', '숫자 값'),
         ('d74/x', 400, 'bad_request', '빗금'), ('claude_d74' + TAG, 400, 'reserved_prefix', 'claude_ 접두'),
         ('qa_d74' + TAG, 400, 'reserved_prefix', 'qa_ 접두'), (T2_NAME, 400, 'already_exists', '이미 있음(사람)'),
         ('claude_employee02', 400, 'already_exists', '이미 있음(검토 계정 — 이미 있음이 먼저)'),
         (T2_NAME.upper(), 400, 'bad_request', '대소문자만 다른 중복(대문자는 형식 오류)'),
         # 독립 검토 RV-D74-1 — PCRE `$` 가 끝 줄바꿈 앞에서도 맞아 형식을 통과하고 DB CHECK 에서 500·감사 0 이던 것(고친 뒤 400 · 감사 1)
         ('abc\n', 400, 'bad_request', '끝 줄바꿈(RV-D74-1)'), ('ab\nc', 400, 'bad_request', '가운데 줄바꿈(RV-D74-1)')]
for val, st, why, label in cases:
    a0 = amax()
    r = rename(A1, T1_ID, val)
    ok('%s → %d · user.update deny %s' % (label, st, why), r.status == st and denied(a0, why) == 1, (r.status, r.json, [(x['a'], x['why']) for x in arows(a0)]))
    rows = arows(a0)
    if isinstance(val, str) and len(val) >= 3 and val not in (T2_NAME, 'claude_employee02'):
        # 줄바꿈이 든 값은 JSON 에서 `\n` 두 글자로 적히므로 그 모양으로도 찾는다(그냥 찾으면 늘 「없다」가 된다)
        forms = {val, json.dumps(val, ensure_ascii=False)[1:-1]}
        ok('  시도한 이름이 감사 행(요약·상세·대상)에 없다 — %s' % label,
           all(f not in json.dumps(x, ensure_ascii=False) for x in rows for f in forms), rows)
    # 계정 만들기 창구의 같은 값 — 같은 상태·같은 사유(형식·접두·이미 있음)
    if isinstance(val, str):
        a1 = amax()
        r2 = A1.call('POST', '/tbox/api/users', {'username': val, 'role': 'Employee', 'reason_text': 'd74 대조'})
        ok('  계정 만들기도 같은 값에 %d · user.create deny %s' % (st, why), r2.status == st and denied(a1, why, 'user.create') == 1, (r2.status, r2.json))
_r = rename(A1, T1_ID, 'AB')
shown('형식(대문자)', _r)
msg_fmt = _r.json or {}
shown('이미 있음', rename(A1, T1_ID, T2_NAME))
shown('검토 접두', rename(A1, T1_ID, 'claude_x' + TAG))
ok('형식 오류 문구는 아이디 규칙을 말한다(요청 값 없음)', '3~64자' in msg_fmt.get('error', '') and 'AB' not in msg_fmt.get('error', ''), msg_fmt)
a0 = amax()
r = A1.call('PATCH', '/tbox/api/users/%d' % T1_ID, {'username': 'd74x' + TAG, 'role': 'Manager', 'reason_text': 'd74'})
ok('권한 칸과 섞음(username + role) → 400 · 이름·역할 그대로', r.status == 400 and sql('SELECT username || role FROM users WHERE id = %d;' % T1_ID) == T1_OLD + 'Employee', (r.status, r.json))
r = A1.call('PATCH', '/tbox/api/users/%d' % T1_ID, {'username': 'd74x' + TAG, 'is_active': False, 'reason_text': 'd74'})
ok('권한 칸과 섞음(username + is_active) → 400 · 그대로 활성', r.status == 400 and sql('SELECT is_active FROM users WHERE id = %d;' % T1_ID) == 't', (r.status, r.json))
a0 = amax()
r = rename(A1, T1_ID, T1_OLD)
ok('같은 이름 → 400 바뀐 것 없음 · user.update deny bad_request', r.status == 400 and denied(a0, 'bad_request') == 1, (r.status, r.json))
a0 = amax()
r = rename(A1, T1_ID, 'd74x' + TAG, reason=None)
ok('사유 없음 → 400 · 감사', r.status == 400 and denied(a0, 'bad_request') == 1, (r.status, r.json))
r = rename(A1, T1_ID, 'd74x' + TAG, reason='   ')
ok('사유 빈칸만 → 400', r.status == 400, r.status)
e1id = uid_of('claude_employee01')
a0 = amax()
r = rename(A1, e1id, 'd74x' + TAG)
shown('검토 계정 대상', r)
ok('검토 계정(claude_employee01) 대상 → 400 review_account · 이름 그대로',
   r.status == 400 and denied(a0, 'review_account') == 1 and uid_of('claude_employee01') == e1id, (r.status, r.json))
a0 = amax()
r = rename(A1, uid_of('claude_admin01'), 'd74x' + TAG)
ok('자기 자신(claude_admin01) → 400 self_change', r.status == 400 and denied(a0, 'self_change') == 1, (r.status, r.json))
a0 = amax()
r = rename(T2, T2_ID, 'd74x' + TAG)
ok('자기 자신(사람 관리자 T2, 재인증함) → 400 self_change', r.status == 400 and denied(a0, 'self_change') == 1, (r.status, r.json))
ok('  (T2 는 재인증 상태 — self_change 가 관문이 아니라 판정에서 막혔다)', (T2.me().json or {}).get('reauth_fresh') is True)

head('N — 공용 토큰 가드 (켜짐 · 지금 이름/새 이름 · 꺼짐)')
set_shared_token(SHARED, T1_OLD)
r = req('GET', '/tbox/api/whoami', token=SHARED)
ok('공용 토큰 켜짐 · 귀속 T1 → whoami 200 · T1', r.status == 200 and (r.json or {}).get('username') == T1_OLD, (r.status, r.json))
a0 = amax()
r = rename(A1, T1_ID, T1_NEW)
ok('켜짐 + 지금 이름이 귀속 아이디 → 409 shared_token_user · 이름 그대로',
   r.status == 409 and denied(a0, 'shared_token_user') == 1 and sql('SELECT username FROM users WHERE id = %d;' % T1_ID) == T1_OLD, (r.status, r.json))
ok('  409 문구 「공용 프로그램 토큰에 묶여 …」', '공용 프로그램 토큰' in ((r.json or {}).get('error') or ''), r.json)
shown('공용 토큰 귀속', r)
set_shared_token('short-token', T1_OLD)      # 32자 미만 = 꺼짐(비교조차 하지 않는다 — Auth 와 같은 문턱)
r = req('GET', '/tbox/api/whoami', token='short-token')
ok('32자 미만 공용 토큰 = 꺼짐(whoami 401)', r.status == 401, r.status)
set_shared_token(SHARED, 'claude_employee02')   # 켜짐 · 다른 계정 귀속 — 이름 바꾸기와 상관없음

head('P 양성 — 관리자 + 재인증 → 바뀜')
a0 = amax()
s_before = sql('SELECT count(*) FROM sessions WHERE user_id = %d;' % T1_ID)
r = rename(A1, T1_ID, T1_NEW, reason='d74 시험 — 잠금 표적이 되지 않게 아이디를 바꿈')
ok('PATCH username → 200', r.status == 200, (r.status, r.json))
shown('바꾸기 성공', r)
ok('  응답 username = 새 이름 · sessions_revoked 0', (r.json or {}).get('username') == T1_NEW and (r.json or {}).get('sessions_revoked') == 0, r.json)
ok('  DB 계정 행 이름 = 새 이름(번호 그대로)', sql('SELECT username FROM users WHERE id = %d;' % T1_ID) == T1_NEW)
rows = [x for x in arows(a0) if x['a'] == 'user.update']
ok('  감사 user.update allow 1 · target_name = 옛 이름 · target_id = 번호',
   len(rows) == 1 and rows[0]['r'] == 'allow' and rows[0]['t'] == T1_OLD and rows[0]['tid'] == T1_ID, rows)
d = rows[0]['d'] if rows else {}
ok('  detail.changed_fields = ["username"] · sessions_revoked 0 · reason_text 있음',
   d.get('changed_fields') == ['username'] and d.get('sessions_revoked') == 0 and bool(d.get('reason_text')), d)
ok('  새 이름이 그 행(요약·상세·대상) 어디에도 없다', rows and T1_NEW not in json.dumps(rows[0], ensure_ascii=False), rows)
ok('  요약 문장 「이름·아이디를 바꿨습니다」', rows and '이름·아이디를 바꿨습니다' in (rows[0]['s'] or ''), rows[0]['s'] if rows else '')
ok('  새 이름은 이 뒤 감사 행 어디에도(이 요청의 행 전부) 없다', all(T1_NEW not in json.dumps(x, ensure_ascii=False) for x in arows(a0)), arows(a0))
lst = A1.call('GET', '/tbox/api/users?page=1').json or {}
names = {u.get('id'): u.get('username') for u in lst.get('users', [])}
ok('  계정 목록(/api/users) 그 번호 = 새 이름', names.get(T1_ID) == T1_NEW, names.get(T1_ID))
ok('  세션 행 수 그대로(%s)' % s_before, sql('SELECT count(*) FROM sessions WHERE user_id = %d;' % T1_ID) == s_before)
ok('  잠금 집계 — 옛 이름 때 틀린 2회(failed_count 2)가 계정 행에 그대로 붙어 있다(번호 기준)', sql('SELECT failed_count FROM users WHERE id = %d;' % T1_ID) == '2')

head('P — 로그인 · 세션 · 토큰 · 2단계')
bad = 'nobody' + secrets.token_hex(4)
r_old = req('POST', '/tbox/api/login', body={'username': T1_OLD, 'password': T1.pw, 'otp': totp(T1.sec, int(time.time()) // 30)}, src='127.0.0.8')
r_none = req('POST', '/tbox/api/login', body={'username': bad, 'password': T1.pw, 'otp': '123456'}, src='127.0.0.8')
ok('옛 이름 + 맞는 비밀번호·코드 → 401', r_old.status == 401, (r_old.status, r_old.json))
ok('  없는 아이디와 같은 모양(상태·본문 글자 그대로·세션 쿠키 0)', r_old.status == r_none.status and r_old.raw == r_none.raw
   and not session_from(r_old) and not session_from(r_none), (r_old.raw[:80], r_none.raw[:80]))
T1n = Who(T1_NEW, T1.pw, T1.sec)
r = T1n.login(key=T1_OLD)
ok('새 이름 + 같은 비밀번호 + 같은 2단계 비밀 → 200(인증 앱 이름표는 옛 이름이어도 코드는 맞다)', r.status == 200 and T1n.sess, (r.status, r.json))
m = T1.me()
ok('바뀐 사람의 이전 세션 그대로 — /api/me 200 · 이름 새것', m.status == 200 and (m.json or {}).get('username') == T1_NEW, (m.status, (m.json or {}).get('username')))
ok('바꾼 사람(관리자) 세션 그대로 — /api/me 200', A1.me().status == 200)
r = req('GET', '/tbox/api/whoami', token=PTOK)
ok('개인 토큰(user_id 기준) 그대로 — whoami 200 · 새 이름', r.status == 200 and (r.json or {}).get('username') == T1_NEW, (r.status, r.json))
r = req('GET', '/tbox/api/whoami', token=SHARED)
ok('공용 토큰(다른 계정 귀속) 그대로 — whoami 200 · claude_employee02', r.status == 200 and (r.json or {}).get('username') == 'claude_employee02', (r.status, r.json))
set_shared_token(SHARED, T1_OLD)
r = req('GET', '/tbox/api/whoami', token=SHARED)
ok('참고 — 공용 토큰 귀속이 옛 이름이면 503(바꾸는 순간 끊긴다 · 그래서 가드)', r.status == 503, (r.status, r.json))
set_shared_token('', '')

head('C — 잠금 집계 · AL-2 (이름을 바꾼 뒤)')
fc_after_login = sql('SELECT failed_count FROM users WHERE id = %d;' % T1_ID)
note('새 이름 로그인 성공 뒤 failed_count = %s (성공이 0 으로 되돌린다)' % fc_after_login)
st = []
for _ in range(6):
    st.append(req('POST', '/tbox/api/login', body={'username': T1_OLD, 'password': 'wrong-' + secrets.token_hex(6)}, src='127.0.0.9').status)
ok('옛 이름으로 6번 틀림 → 전부 401 · 423 0(잠기지 않는다)', st == [401] * 6, st)
ok('  계정 failed_count 그대로(옛 이름 시도는 계정에 세지 않는다)', sql('SELECT failed_count FROM users WHERE id = %d;' % T1_ID) == fc_after_login)
st = []
for _ in range(6):
    st.append(req('POST', '/tbox/api/login', body={'username': bad, 'password': 'wrong-' + secrets.token_hex(6)}, src='127.0.0.9').status)
ok('없는 아이디 6번 → 전부 401(옛 이름과 같다)', st == [401] * 6, st)
out = alerts('AL-2')
ok('AL-2(dry-run) — 옛 이름·없는 아이디 12회 IP 127.0.0.9 는 20회 밑이라 발동 0', '127.0.0.9' not in out, out[-300:])
for _ in range(8):
    req('POST', '/tbox/api/login', body={'username': T1_OLD, 'password': 'wrong'}, src='127.0.0.9')
out = alerts('AL-2')
ok('AL-2(dry-run) — 옛 이름 시도까지 IP 로 세어 20회 넘으면 발동(127.0.0.9)', '127.0.0.9' in out, out[-400:])
st = []
for _ in range(6):
    st.append(req('POST', '/tbox/api/login', body={'username': T1_NEW, 'password': 'wrong-' + secrets.token_hex(6)}, src='127.0.0.10').status)
ok('새 이름으로 틀림 → 5번째 뒤 잠김(423) — 잠금은 새 이름(계정 행)에 걸린다', st[:5] == [401] * 5 and st[5] == 423, st)
out = alerts('AL-2')
ok('  AL-2(dry-run) — 잠금 발생 IP 127.0.0.10 발동', '127.0.0.10' in out, out[-400:])
ok('  잠금 행 auth.lock 대상 = 새 이름', sql("SELECT count(*) FROM audit_logs WHERE action = 'auth.lock' AND target_name = '%s';" % T1_NEW) == '1')
sql('UPDATE users SET failed_count = 0, locked_until = NULL WHERE id = %d;' % T1_ID)   # 임시 DB 시험 계정만 — 뒤 항목을 위해 풂

head('C — AL-1 (actor_name 묶음)')
for _ in range(3):
    T1.call('GET', '/tbox/api/users')
out = alerts('AL-1')
ok('옛 이름 3 + 새 이름 3 = 한 사람 6회지만 이름이 갈려 AL-1 발동 0 (이름 묶음의 한계)', T1_OLD not in out and T1_NEW not in out, out[-300:])
for _ in range(2):
    T1.call('GET', '/tbox/api/users')
out = alerts('AL-1')
ok('  새 이름 5회가 되면 발동(새 이름)', T1_NEW in out, out[-300:])

head('C — 멘션 · 노출')
if PAGE:
    ok('바꾸기 전 멘션 알림 그대로(user_id)', sql("SELECT count(*) FROM community.notifications WHERE user_id = %d AND kind = 'mention';" % T1_ID) == '1')
    r = E1.call('POST', '/community/api/pages/%s/comments' % PAGE, {'body': '@%s 다시 (d74 시험)' % T1_OLD})
    ok('@옛이름 새 댓글 → 알림 늘지 않음(없는 이름)', r.status == 201 and sql("SELECT count(*) FROM community.notifications WHERE user_id = %d AND kind = 'mention';" % T1_ID) == '1', r.status)
    r = E1.call('POST', '/community/api/pages/%s/comments' % PAGE, {'body': '@%s 새 이름으로 (d74 시험)' % T1_NEW})
    ok('@새이름 댓글 → 알림 +1', r.status == 201 and sql("SELECT count(*) FROM community.notifications WHERE user_id = %d AND kind = 'mention';" % T1_ID) == '2', r.status)
    lst = E1.call('GET', '/community/api/pages/%s/comments' % PAGE).json or {}
    bodies = json.dumps(lst, ensure_ascii=False)
    ok('옛 댓글 본문의 「@옛이름」은 글자 그대로 남는다(링크 아님 — 저장이 글자)', ('@' + T1_OLD) in bodies, bodies[:200])
    r = T1.call('POST', '/community/api/pages/%s/comments' % PAGE, {'body': 'T1 이 남긴 댓글 (d74 시험)'})
    lst = E1.call('GET', '/community/api/pages/%s/comments' % PAGE).json or {}
    ok('노출 — 댓글 작성자 칸에 지금 아이디(새 이름)가 같은 팀 직원에게 보인다', T1_NEW in json.dumps(lst, ensure_ascii=False))
    r = E1.call('GET', '/community/api/share-candidates?page=%s&q=%s' % (PAGE, 'd74n'))
    ok('노출 — 공유 후보 찾기(직원)에 새 이름이 앞 글자 검색으로 나온다', r.status == 200 and T1_NEW in r.raw.decode(), (r.status, r.raw[:120]))
    r = G1.call('GET', '/community/api/share-candidates?page=%s&q=%s' % (PAGE, 'd74n'))
    ok('  손님은 공유 후보 403(S2 M-1 그대로)', r.status in (403, 404), r.status)
    r = T1.call('GET', '/community/api/notifications')
    ok('알림 목록(T1) 200 · 보낸 사람 칸은 아이디 글자(actor_name)', r.status == 200 and 'claude_employee01' in r.raw.decode(), r.status)

head('C — 결정 d76 ② 순서 (관리자 T2 = 공용 토큰 귀속 계정) · 휴대폰 경보 받기')
T2_NEW = 'd74b' + TAG
set_shared_token(SHARED, T2_NAME)          # 운영과 같은 꼴 — 공용 토큰이 켜져 있고 귀속이 관리자
r = req('GET', '/tbox/api/whoami', token=SHARED)
ok('준비 — 공용 토큰 귀속 T2 → whoami 200 · T2', r.status == 200 and (r.json or {}).get('username') == T2_NAME, (r.status, r.json))
a0 = amax()
r = rename(A1, T2_ID, T2_NEW)
ok('귀속 줄을 그대로 둔 채 바꾸기 → 409(닫힌 실패)', r.status == 409 and denied(a0, 'shared_token_user') == 1, (r.status, r.json))
set_shared_token(SHARED, T2_NEW)           # ① 도우미 --env 가 하는 일(임시 .env 사본에서 — 앱은 요청마다 .env 를 다시 읽는다)
r = req('GET', '/tbox/api/whoami', token=SHARED)
ok('① .env 귀속 줄을 새 아이디로 → 그 순간부터 공용 토큰 503(잠시 끊김 · 재시작 없이 바로)', r.status == 503, (r.status, r.json))
r = rename(A1, T2_ID, T2_NEW)
ok('② 창구로 아이디 바꾸기 → 200(지금 이름 ≠ 귀속 — 가드 통과)', r.status == 200 and (r.json or {}).get('username') == T2_NEW, (r.status, r.json))
r = req('GET', '/tbox/api/whoami', token=SHARED)
ok('③ 공용 토큰 다시 200 · 새 아이디의 계정(Admin)', r.status == 200 and (r.json or {}).get('username') == T2_NEW and (r.json or {}).get('role') == 'Admin', (r.status, r.json))
set_shared_token('', '')
if PUSH_OK:
    ok('  받기 행 그대로 · 지금도 관리자(still_admin — 번호로 잇는다)',
       sql("SELECT count(*) FROM alert_push_subscriptions s JOIN users u ON u.id = s.user_id WHERE s.user_id = %d AND s.revoked_at IS NULL AND u.role = 'Admin' AND u.is_active;" % T2_ID) == '1')
    r = T2.call('GET', '/tbox/api/admin/push/subscriptions')
    ok('  T2 세션으로 받기 목록 200 · 1개', r.status == 200 and len((r.json or {}).get('subscriptions', (r.json or {}).get('items', []))) >= 1, (r.status, r.raw[:120]))

head('C — 감사 거르기 (actor = 이름 그대로 일치)')
r_o = A1.call('GET', '/tbox/api/audit?actor=%s' % T1_OLD)
r_n = A1.call('GET', '/tbox/api/audit?actor=%s' % T1_NEW)
rows_o = (r_o.json or {}).get('rows', (r_o.json or {}).get('logs', []))
rows_n = (r_n.json or {}).get('rows', (r_n.json or {}).get('logs', []))
ok('감사 조회 actor=옛 이름 200 · 옛 행이 나온다', r_o.status == 200 and len(rows_o) > 0, (r_o.status, len(rows_o), r_o.raw[:120]))
ok('감사 조회 actor=새 이름 200 · 옛 이름 시절 행은 안 나온다(이름 그대로 일치)', r_n.status == 200 and all(x.get('actor_name') == T1_NEW for x in rows_n) and len(rows_n) > 0,
   (r_n.status, len(rows_n)))
note('감사 거르기 — actor=옛 이름 %d행 · actor=새 이름 %d행 (같은 사람 · 번호로 잇는 거르기는 없다 — target 은 글자 부분 일치)' % (len(rows_o), len(rows_n)))

head('C — 로그인 응답 시간 (없는 아이디 · 틀린 비밀번호 · 참고)')
t_none, t_wrong = [], []
for i in range(6):
    t_none.append(req('POST', '/tbox/api/login', body={'username': 'nobody' + secrets.token_hex(4), 'password': 'x' * 12}, src='127.0.0.11').secs)
    if i < 4:
        t_wrong.append(req('POST', '/tbox/api/login', body={'username': 'claude_manager01', 'password': 'x' * 12}, src='127.0.0.11').secs)
med = lambda v: sorted(v)[len(v) // 2]
note('로그인 401 시간 중앙값 — 없는 아이디 %.0fms · 있는 아이디 틀린 비밀번호 %.0fms (차 %.0fms)' % (med(t_none) * 1000, med(t_wrong) * 1000, (med(t_wrong) - med(t_none)) * 1000))
r_w = req('POST', '/tbox/api/login', body={'username': 'claude_manager01', 'password': 'x' * 12}, src='127.0.0.11')
r_n2 = req('POST', '/tbox/api/login', body={'username': 'nobody' + secrets.token_hex(4), 'password': 'x' * 12}, src='127.0.0.11')
ok('없는 아이디 · 틀린 비밀번호 — 상태·본문 같다(401 같은 글자)', r_w.status == r_n2.status == 401 and r_w.raw == r_n2.raw, (r_w.raw[:60], r_n2.raw[:60]))
sql("UPDATE users SET failed_count = 0, locked_until = NULL WHERE username = 'claude_manager01';")   # 임시 DB 만 — 틀린 시도 5회로 잠겼을 수 있다

print('\n통과 %d · 실패 %d' % (PASS, FAIL))
print('NOTES=' + json.dumps(NOTES, ensure_ascii=False))
print('SMOKE_FAIL=%d' % FAIL)
sys.exit(1 if FAIL else 0)
