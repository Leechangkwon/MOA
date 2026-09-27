#!/usr/bin/env python3
# tests/passkey_api.py — T:F-72 수락 시험의 **창구 몸통** (PK-T2~T9 · Q:F-7 B1-1 서버 쪽) — 부르는 쪽: tests/passkey.sh
#
# 쓰는 법: py_secret <세션 다섯> <비밀번호 다섯> <2단계 비밀 다섯> -- python3 tests/passkey_api.py <SITE> <UA> <DB> <INST> <WORK> [<SITE_IP>]
#   SITE     임시 인스턴스 `http://localhost:<포트>`(APP_URL 과 같은 값 — RP ID localhost) · 운영 80·8082·8081 에는 요청하지 않는다
#   DB       임시 DB(tbox_d3p_…) — 준비·확인 SQL 은 표준입력으로만(sudo 명령 줄이 문장마다 달라지지 않게)
#   INST     임시 앱 폴더(경보 dry-run 을 임시 .env 로) · WORK 0700 작업 폴더 · SITE_IP APP_URL 이 IP 인 두 번째 인스턴스(있으면)
#   표준입력 15줄: 세션 e1 e2 m1 a1 g1 · 비밀번호 e1 e2 m1 a1 g1 · TOTP 비밀 e1 e2 m1 a1 g1 (R-62 — 명령 인자에 비밀 0)
# 소프트웨어 인증기(tests/lib/passkey_forge.py — 구현과 다른 코드)로 등록·로그인 응답을 만든다. 서버는 증명 none 을 받으므로
# 진짜 휴대폰과 가릴 수 없다(설계 그대로 — PK-D1). 가상 인증기(브라우저)는 tests/browser/passkey.py 가 따로 본다.
# 나가는 값: 마지막 줄 `API_PASS=<n> API_FAIL=<m>` · 종료 코드 0/1
import base64
import hashlib
import hmac
import json
import os
import re
import struct
import subprocess
import sys
import threading
import time
import urllib.error
import urllib.request

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), "lib"))
import passkey_forge as F  # noqa: E402

SITE, UA, DB, INST, WORK = sys.argv[1:6]
SITE_IP = sys.argv[6] if len(sys.argv) > 6 else ""
RP = "localhost"
ORIGIN = SITE.rstrip("/")
NAMES = ["claude_employee01", "claude_employee02", "claude_manager01", "claude_admin01", "claude_guest01"]
KEYS = ["e1", "e2", "m1", "a1", "g1"]
SESS = {k: sys.stdin.readline().rstrip("\n") for k in KEYS}
PW = {k: sys.stdin.readline().rstrip("\n") for k in KEYS}
SEC = {k: sys.stdin.readline().rstrip("\n") for k in KEYS}
USER = dict(zip(KEYS, NAMES))
P = Fl = 0
LOGIN_FAILED = "아이디 또는 비밀번호가 올바르지 않습니다"


def ok(name, cond, detail=""):
    global P, Fl
    if cond:
        P += 1
        print("  ✔ " + name, flush=True)
    else:
        Fl += 1
        print("  ✖ " + name + ("  — " + str(detail)[:500] if detail != "" else ""), flush=True)


def sub(t):
    print("\n── " + t, flush=True)


def sql(q):
    r = subprocess.run(["sudo", "-n", "-u", "postgres", "psql", "-X", "-q", "-At", "-d", DB], input=q + "\n",
                       capture_output=True, text=True, cwd="/")
    return r.stdout.strip()


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


OPENER = urllib.request.build_opener(NoRedirect)


def req(method, path, cookies=None, body=None, csrf=None, site=None, raw=None, ctype="application/json", ua=None):
    h = {"User-Agent": ua or UA}
    if cookies:
        h["Cookie"] = "; ".join("%s=%s" % kv for kv in cookies.items())
    data = None
    if raw is not None:
        data = raw
        h["Content-Type"] = ctype
    elif body is not None:
        data = json.dumps(body, ensure_ascii=False).encode("utf-8")
        h["Content-Type"] = ctype
    if csrf:
        h["X-CSRF-Token"] = csrf
    r = urllib.request.Request((site or SITE) + path, data=data, headers=h, method=method)
    try:
        with OPENER.open(r, timeout=60) as x:
            b = x.read()
            c = x.status
            hd = x.headers
    except urllib.error.HTTPError as e:
        b = e.read()
        c = e.code
        hd = e.headers
    try:
        d = json.loads(b or b"{}")
    except Exception:
        d = None
    return c, d, b, hd


def setcookies(hd):
    return hd.get_all("Set-Cookie") or []


def cookie_val(hd, name):
    for v in setcookies(hd):
        m = re.match(r"^%s=([^;]*)" % re.escape(name), v)
        if m:
            return m.group(1)
    return None


def totp(secret, t=None):
    key = base64.b32decode(secret + "=" * (-len(secret) % 8))
    ctr = int((t or time.time()) // 30)
    mac = hmac.new(key, struct.pack(">Q", ctr), hashlib.sha1).digest()
    o = mac[-1] & 0x0F
    return "%06d" % ((struct.unpack(">I", mac[o:o + 4])[0] & 0x7FFFFFFF) % 1000000)


def fresh_code(k):
    """이 계정의 2단계 코드 — 임시 DB 의 기준선(mfa_last_step)을 비우고 지금 코드(같은 30초에 여러 번 쓰려고 — 임시 DB 만).
    창이 0 이라 30초 경계를 넘으면 틀린 코드가 된다 — 끝 5초 안이면 다음 창까지 기다린다."""
    while 30 - (time.time() % 30) < 5:
        time.sleep(0.5)
    sql("UPDATE users SET mfa_last_step = NULL WHERE username = '%s'" % USER[k])
    return totp(SEC[k])


def unlock(k):
    sql("UPDATE users SET failed_count = 0, locked_until = NULL WHERE username = '%s'" % USER[k])


def failed(k):
    return int(sql("SELECT failed_count FROM users WHERE username = '%s'" % USER[k]) or "0")


def uid(k):
    return int(sql("SELECT id FROM users WHERE username = '%s'" % USER[k]))


def amax():
    return int(sql("SELECT coalesce(max(id),0) FROM audit_logs") or "0")


def audit_rows(a0, cond="true"):
    return sql("SELECT action || '|' || result || '|' || coalesce(reason,'') FROM audit_logs WHERE id > %d AND %s ORDER BY id" % (a0, cond)).splitlines()


def login_pw(k):
    """비밀번호(+코드)로 새 세션 — 돌려주는 것은 세션 쿠키 값"""
    unlock(k)
    body = {"username": USER[k], "password": PW[k]}
    mfa = sql("SELECT mfa_enabled FROM users WHERE username = '%s'" % USER[k]) == "t"
    if mfa:
        body["otp"] = fresh_code(k)
    c, d, _, hd = req("POST", "/tbox/api/login", body=body)
    return cookie_val(hd, "__Host-tbox_session") if c == 200 else None


class S:
    """세션 하나(쿠키 · CSRF)"""
    def __init__(self, k, token=None):
        self.k = k
        self.token = token or SESS[k]
        c, d, _, _ = req("GET", "/tbox/api/me", cookies=self.ck())
        self.me = d if c == 200 and isinstance(d, dict) else {}
        self.csrf = self.me.get("csrf_token", "")

    def ck(self, extra=None):
        c = {"__Host-tbox_session": self.token}
        if extra:
            c.update(extra)
        return c

    def get(self, path):
        return req("GET", path, cookies=self.ck())

    def post(self, path, body=None, extra_cookies=None):
        return req("POST", path, cookies=self.ck(extra_cookies), body=body if body is not None else {}, csrf=self.csrf)

    def delete(self, path):
        return req("DELETE", path, cookies=self.ck(), csrf=self.csrf)


def reg_options(s, name="시험 폰", pw=None, otp="auto"):
    body = {"name": name, "password": PW[s.k] if pw is None else pw}
    mfa = sql("SELECT mfa_enabled FROM users WHERE username = '%s'" % USER[s.k]) == "t"
    if otp == "auto":
        if mfa:
            body["otp"] = fresh_code(s.k)
    elif otp is not None:
        body["otp"] = otp
    return s.post("/tbox/api/me/passkeys/options", body)


def register(s, key, name="시험 폰", opts=None, **kw):
    if opts is None:
        c, d, _, _ = reg_options(s, name)
        if c != 200:
            return c, d, None
        opts = d
    pk = opts["publicKey"]
    body = F.register_body(key, name, pk["challenge"], kw.pop("origin", ORIGIN), RP, **kw)
    c, d, _, _ = s.post("/tbox/api/me/passkeys", body)
    return c, d, F.unb64u(pk["user"]["id"])


def login_options(pre=None, site=None):
    c, d, _, hd = req("POST", "/tbox/api/login/passkey/options", body={}, cookies={"__Host-tbox_pk": pre} if pre else None, site=site)
    newpre = cookie_val(hd, "__Host-tbox_pk")
    return c, d, newpre or pre, hd


def pk_login(key, handle, pre=None, opts=None, extra_cookies=None, **kw):
    if opts is None:
        c, d, pre, _ = login_options(pre)
        opts = d
    pk = opts["publicKey"]
    body = F.login_body(key, handle, pk["challenge"], kw.pop("origin", ORIGIN), RP, **kw)
    cookies = {}
    if pre:
        cookies["__Host-tbox_pk"] = pre
    if extra_cookies:
        cookies.update(extra_cookies)
    c, d, b, hd = req("POST", "/tbox/api/login/passkey", body=body, cookies=cookies or None)
    return c, d, b, hd, body


RACE_APP = "pk_rv1_hold"   # 「먼저 지나간 요청」 흉내 연결의 이름(임시 DB 의 pg_stat_activity 로만 찾는다)


def race_send(cred, bump, send):
    """RV-PK-1 — 서버가 열쇠 행을 **읽은 뒤·갱신하기 전에** 다른 요청이 먼저 서명 횟수를 올린 상황.
    다른 연결(임시 DB · 표준입력 psql — 명령 줄은 늘 같은 모양)이 그 행의 sign_count 를 bump 로 올린 채 커밋하지 않고(행 잠금) 있는 동안
    send() 를 보낸다 → 서버의 찾기 SELECT 는 커밋된 옛 값을 읽어 7) 을 통과하고 갱신 UPDATE 에서 그 잠금을 기다린다 → 기다림을
    pg_stat_activity 로 본 뒤 커밋한다. 돌려주는 것: (send() 결과 또는 None, 잠금을 쥐었나, 서버 UPDATE 가 기다렸나)"""
    hold = subprocess.Popen(["sudo", "-n", "-u", "postgres", "psql", "-X", "-q", "-At", "-d", DB], stdin=subprocess.PIPE,
                            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, text=True, cwd="/")
    hold.stdin.write("SET application_name = '%s';\nBEGIN;\nUPDATE passkeys SET sign_count = %d WHERE credential_id = decode('%s','hex');\n"
                     % (RACE_APP, bump, cred.cred_id.hex()))
    hold.stdin.flush()
    held = waited = False
    for _ in range(100):
        if sql("SELECT count(*) FROM pg_stat_activity WHERE application_name = '%s' AND state = 'idle in transaction'" % RACE_APP) == "1":
            held = True
            break
        time.sleep(0.1)
    out = []
    th = None
    if held:
        th = threading.Thread(target=lambda: out.append(send()), daemon=True)
        th.start()
        for _ in range(150):
            if sql("SELECT count(*) FROM pg_stat_activity WHERE datname = current_database() AND application_name <> '%s' "
                   "AND wait_event_type = 'Lock' AND query ILIKE '%%update passkeys%%sign_count%%'" % RACE_APP) == "1":
                waited = True
                break
            if not th.is_alive():
                break
            time.sleep(0.1)
    hold.stdin.write("COMMIT;\n" if held else "ROLLBACK;\n")
    hold.stdin.close()
    hold.wait(timeout=30)
    if th is not None:
        th.join(timeout=60)
    return (out[0] if out else None), held, waited


# ═══════════════════════════════════════════════════════════════════════
s = {k: S(k) for k in KEYS}
ok("세션 다섯의 CSRF 토큰(/tbox/api/me 200)", all(len(s[k].csrf) > 10 for k in KEYS))
ok("/api/me — passkey_available 참 · passkeys_active 0 (임시 인스턴스 APP_URL=%s)" % ORIGIN,
   s["e1"].me.get("passkey_available") is True and s["e1"].me.get("passkeys_active") == 0,
   {k: s["e1"].me.get(k) for k in ("passkey_available", "passkeys_active")})
# claude_employee02 는 이 임시 DB 에서만 2단계를 끈다(「2단계 끈 계정」 갈래 — 코드 없이 등록)
E2_MFA = sql("SELECT mfa_enabled::text || '|' || coalesce(mfa_secret,'') FROM users WHERE username = 'claude_employee02'")
sql("UPDATE users SET mfa_enabled = false WHERE username = 'claude_employee02'")

# ═══ PK-T2 등록 양성 ══════════════════════════════════════════════════
sub("PK-T2 등록 양성 — claude_employee02(2단계 끔) · claude_admin01(켬)")
a0 = amax()
k_e2a = F.SoftKey()
c, d, handle_e2 = register(s["e2"], k_e2a, "내 폴드")
ok("e2 등록 → 201 · 응답에 열쇠 id·공개 키 없음", c == 201 and isinstance(d, dict) and "passkey" in d
   and F.b64u(k_e2a.cred_id) not in json.dumps(d), (c, d))
row = sql("SELECT octet_length(public_key) || '|' || octet_length(user_handle) || '|' || backup_eligible || '|' || backed_up || '|' || name "
          "|| '|' || device_hint || '|' || alg || '|' || array_to_string(transports, ',') FROM passkeys WHERE credential_id = decode('%s','hex')"
          % k_e2a.cred_id.hex())
ok("표 한 줄 — SPKI 91 · user_handle 32 · BE/BS 참 · 이름 · 기기 요약(검토 UA 라 「기타 브라우저」) · alg -7 · transports",
   row == "91|32|true|true|내 폴드|기타 브라우저|-7|internal,hybrid", row)
ok("공개 키 = 소프트웨어 인증기의 공개 키(다른 코드가 만든 SPKI 와 같다)",
   sql("SELECT encode(public_key,'hex') FROM passkeys WHERE credential_id = decode('%s','hex')" % k_e2a.cred_id.hex()) == k_e2a.spki_der().hex())
rows = audit_rows(a0, "action LIKE 'auth.passkey%'")
ok("감사 auth.passkey_register allow 한 행", rows == ["auth.passkey_register|allow|"], rows)
keys = sql("SELECT string_agg(k, ',' ORDER BY k) FROM audit_logs, jsonb_object_keys(detail) k WHERE id > %d AND action = 'auth.passkey_register'" % a0)
reg_keys = set(keys.split(",")) - {"route", "request_id", "login_ref"}
ok("감사 detail 키는 등록부만(credential_no · credential_label · device_hint · backed_up · 자동 키)",
   reg_keys <= {"credential_no", "credential_label", "device_hint", "backed_up", "actor_team", "acting_as", "auth", "token_kind"}, keys)
blob = sql("SELECT coalesce(string_agg(summary || ' ' || coalesce(detail::text,''), ' '),'') FROM audit_logs WHERE id > %d" % a0)
cid_hex, cid_b64 = k_e2a.cred_id.hex(), F.b64u(k_e2a.cred_id)
pk_hex = k_e2a.spki_der().hex()
ok("열쇠 id·공개 키 원문이 감사(DB)에 0", cid_hex not in blob and cid_b64 not in blob and pk_hex[:40] not in blob)
logs = ""
for f in ("server.log", "log/audit.log"):
    try:
        logs += open(os.path.join(INST, f), encoding="utf-8", errors="replace").read()
    except OSError:
        pass
ok("열쇠 id·공개 키 원문이 app.log(서버 로그)·감사 파일에 0", cid_hex not in logs and cid_b64 not in logs and pk_hex[:40] not in logs)
k_e2b = F.SoftKey()
c, d, handle_e2b = register(s["e2"], k_e2b, "두 번째 폰")
ok("두 번째 등록 → 201 · 같은 user_handle", c == 201 and handle_e2b == handle_e2, (c, d))
ok("user_handle 32바이트 · 계정 번호·아이디가 아니다", len(handle_e2) == 32 and USER["e2"].encode() not in handle_e2
   and str(uid("e2")).encode() != handle_e2)
k_a1 = F.SoftKey()
c, d, handle_a1 = register(s["a1"], k_a1, "관리자 폰")
ok("a1(2단계 켬) 비밀번호+코드 → 201", c == 201, (c, d))
c, d, _, _ = s["e2"].get("/tbox/api/me")
ok("/api/me passkeys_active 2(e2)", isinstance(d, dict) and d.get("passkeys_active") == 2, d)
c, d, _, _ = s["e2"].get("/tbox/api/me/passkeys")
ok("GET /api/me/passkeys — 내 것 둘 · 상태 active · synced · 열쇠 id·공개 키 칸 0",
   c == 200 and len(d["passkeys"]) == 2 and all(p["state"] == "active" and p["synced"] is True for p in d["passkeys"])
   and not any(k in json.dumps(d) for k in ("credential_id", "public_key", "user_handle", cid_b64)), d)

# ═══ PK-T3 등록 음성 ══════════════════════════════════════════════════
sub("PK-T3 등록 음성")
unlock("e1")
f0 = int(failed("e1"))
a0 = amax()
c, d, _, _ = reg_options(s["e1"], pw="wrong-password-xyz")
ok("비밀번호 틀림 → 400 통일 문구 · 실패 +1 · 감사 bad_credentials", c == 400 and d.get("error") == "잘못된 요청입니다"
   and int(failed("e1")) == f0 + 1 and audit_rows(a0) == ["auth.passkey_register|deny|bad_credentials"], (c, d, failed("e1"), audit_rows(a0)))
a0 = amax()
c, d, _, _ = reg_options(s["e1"], otp="000000" if totp(SEC["e1"]) != "000000" else "111111")
ok("코드 틀림 → 400 · 감사 bad_code", c == 400 and audit_rows(a0) == ["auth.passkey_register|deny|bad_code"], (c, audit_rows(a0)))
while 30 - (time.time() % 30) < 12:   # 두 요청이 같은 30초 창 안에 들게
    time.sleep(0.5)
code = fresh_code("e1")
c1, _, _, _ = reg_options(s["e1"], otp=code)
a0 = amax()
c2, d2, _, _ = reg_options(s["e1"], otp=code)
ok("같은 코드 두 번 → 둘째 400 code_reused", c1 == 200 and c2 == 400 and audit_rows(a0) == ["auth.passkey_register|deny|code_reused"], (c1, c2, audit_rows(a0)))
unlock("e1")
c, d, _, _ = reg_options(s["e1"], name="")
ok("이름 빈 값 → 400 bad_request", c == 400)
c, d, _, _ = reg_options(s["e1"], name="가" * 41)
ok("이름 41자 → 400", c == 400)
c, d, _, _ = reg_options(s["e1"], name="이름‮뒤집기")
ok("이름에 서식 문자(U+202E) → 400", c == 400)
c, d, _, _ = s["e1"].post("/tbox/api/me/passkeys/options", {"name": "x", "password": PW["e1"], "otp": fresh_code("e1"), "role": "Admin"})
ok("모르는 키(role) → 400", c == 400)
# 다른 세션의 챌린지
e1b = S("e1", login_pw("e1"))
c, opts_other, _, _ = reg_options(e1b)
k = F.SoftKey()
a0 = amax()
c, d, _ = register(s["e1"], k, opts=opts_other)
ok("같은 사람 · **다른 세션**의 챌린지로 등록 → 400 passkey_challenge", c == 400 and audit_rows(a0) == ["auth.passkey_register|deny|passkey_challenge"], (c, d, audit_rows(a0)))
c, opts, _, _ = reg_options(s["e1"])
k = F.SoftKey()
c1, d1, _ = register(s["e1"], k, opts=opts)
k2 = F.SoftKey()
a0 = amax()
c2, d2, _ = register(s["e1"], k2, opts=opts)
ok("챌린지 두 번 — 첫째 201 · 둘째 400 passkey_challenge(한 번만)", c1 == 201 and c2 == 400 and audit_rows(a0) == ["auth.passkey_register|deny|passkey_challenge"], (c1, c2, audit_rows(a0)))
c, opts, _, _ = reg_options(s["e1"])
sql("UPDATE passkey_challenges SET expires_at = now() - interval '1 second' WHERE purpose = 'register' AND used_at IS NULL AND user_id = %d" % uid("e1"))
a0 = amax()
c, d, _ = register(s["e1"], F.SoftKey(), opts=opts)
ok("2분 지남(임시 DB 에서 expires_at 당김) → 400 passkey_challenge", c == 400 and audit_rows(a0) == ["auth.passkey_register|deny|passkey_challenge"], (c, audit_rows(a0)))
for name, kw, want in [
    ("UV 없음(flags UP·AT)", {"flags": F.UP | F.AT}, "passkey_invalid"),
    ("UP 없음", {"flags": F.UV | F.AT}, "passkey_invalid"),
    ("다른 origin(http://localhost:8399)", {"origin": "http://localhost:8399"}, "passkey_invalid"),
    ("https 로 흉내 낸 origin", {"origin": "https://localhost"}, "passkey_invalid"),
    ("fmt packed(직접 만든 본문)", {"fmt": "packed", "att_stmt": {"alg": -7, "sig": b"\x30" * 70}}, "passkey_invalid"),
    ("attStmt 비지 않음", {"att_stmt": {"x": 1}}, "passkey_invalid"),
    ("type webauthn.get", {"cdj": None}, "passkey_invalid"),
]:
    c, opts, _, _ = reg_options(s["e1"])
    kk = F.SoftKey()
    if name.startswith("type"):
        kw = {"cdj": F.client_data("webauthn.get", opts["publicKey"]["challenge"], ORIGIN)}
    a0 = amax()
    c, d, _ = register(s["e1"], kk, opts=opts, **kw)
    ok("%s → 400 %s" % (name, want), c == 400 and audit_rows(a0) == ["auth.passkey_register|deny|" + want], (c, d, audit_rows(a0)))
c, opts, _, _ = reg_options(s["e1"])
kk = F.SoftKey()
a0 = amax()
c, d, _ = register(s["e1"], kk, opts=opts, ad=F.auth_data("evil.example", F.UP | F.UV | F.AT, 0, kk))
ok("rpIdHash 다름 → 400 passkey_invalid", c == 400 and audit_rows(a0) == ["auth.passkey_register|deny|passkey_invalid"], (c, audit_rows(a0)))
c, opts, _, _ = reg_options(s["e1"])
kk = F.SoftKey()
a0 = amax()
c, d, _ = register(s["e1"], kk, opts=opts, raw_id=os.urandom(32))
ok("rawId ≠ authData 안의 열쇠 id → 400 passkey_invalid", c == 400 and audit_rows(a0) == ["auth.passkey_register|deny|passkey_invalid"], (c, audit_rows(a0)))
c, opts, _, _ = reg_options(s["e1"])
a0 = amax()
c, d, _ = register(s["e1"], k, opts=opts)   # k 는 위에서 이미 등록됐다(직접 POST — 화면의 excludeCredentials 를 건너뜀)
ok("같은 열쇠 두 번(직접 POST) → 400 passkey_duplicate(「이미 등록」 문장)", c == 400 and audit_rows(a0) == ["auth.passkey_register|deny|passkey_duplicate"]
   and "이미 등록" in (d or {}).get("error", ""), (c, d, audit_rows(a0)))
c, opts, _, _ = reg_options(s["e1"])
ok("등록 옵션의 excludeCredentials = 내 살아 있는 열쇠(1개) · allowCredentials 없음 · UV required · platform · none",
   len(opts["publicKey"]["excludeCredentials"]) == 1 and opts["publicKey"]["authenticatorSelection"] == {
       "authenticatorAttachment": "platform", "residentKey": "required", "requireResidentKey": True, "userVerification": "required"}
   and opts["publicKey"]["attestation"] == "none" and opts["publicKey"]["pubKeyCredParams"] == [{"type": "public-key", "alg": -7}],
   opts["publicKey"])
for n in range(4):   # e1 은 1개 → 5개
    register(s["e1"], F.SoftKey(), "폰 %d" % n)
ok("e1 살아 있는 열쇠 5", sql("SELECT count(*) FROM passkeys WHERE user_id = %d AND revoked_at IS NULL" % uid("e1")) == "5")
a0 = amax()
c, d, _, _ = reg_options(s["e1"])
ok("6번째 — 옵션에서 400 limit_exceeded(「5개까지」 문장)", c == 400 and "5개" in (d or {}).get("error", "")
   and audit_rows(a0) == ["auth.passkey_register|deny|limit_exceeded"], (c, d, audit_rows(a0)))
# 등록 창구의 상한(옵션 둘을 4개일 때 받고 차례로 등록)
sql("UPDATE passkeys SET revoked_at = now(), revoke_reason = 'self' WHERE id = (SELECT max(id) FROM passkeys WHERE user_id = %d)" % uid("e1"))
c1, o1, _, _ = reg_options(s["e1"])
c2, o2, _, _ = reg_options(s["e1"])
ra, _, _ = register(s["e1"], F.SoftKey(), opts=o1)
a0 = amax()
rb, db_, _ = register(s["e1"], F.SoftKey(), opts=o2)
ok("4개일 때 받은 옵션 둘 — 첫 등록 201 · 둘째는 등록 창구(사용자 행 잠금 뒤)에서 400 limit_exceeded",
   ra == 201 and rb == 400 and audit_rows(a0) == ["auth.passkey_register|deny|limit_exceeded"], (ra, rb, audit_rows(a0)))
# 강제 절차 중 관문
sql("UPDATE users SET must_change_password = true WHERE username = 'claude_manager01'")
c, d, _, _ = reg_options(s["m1"])
ok("강제 절차(비밀번호 변경 먼저) 중 등록 옵션 → 403 관문(next password_change)", c == 403 and (d or {}).get("next") == "password_change", (c, d))
c, d, _, _ = s["m1"].post("/tbox/api/me/passkeys", {"name": "x"})
ok("강제 절차 중 등록 창구 → 403", c == 403, c)
sql("UPDATE users SET must_change_password = false WHERE username = 'claude_manager01'")
# CSRF
c, d, _, _ = req("POST", "/tbox/api/me/passkeys/options", cookies=s["e1"].ck(), body={"name": "x", "password": "y"})
ok("CSRF 없이 → 403", c == 403, c)
c, d, _, _ = req("GET", "/tbox/api/me/passkeys")
ok("로그인 없이 목록 → 401", c == 401, c)
if SITE_IP:
    c, d, _, _ = req("POST", "/tbox/api/login/passkey/options", body={}, site=SITE_IP)
    ok("APP_URL 이 IP 인 인스턴스 — 로그인 옵션 503", c == 503, (c, d))
    c, d, _, _ = req("POST", "/tbox/api/login/passkey", body={"type": "public-key"}, site=SITE_IP)
    ok("APP_URL 이 IP 인 인스턴스 — 로그인 503", c == 503, c)
    ip_tok = None
    c0, _, _, hd0 = req("POST", "/tbox/api/login", site=SITE_IP, body={"username": USER["e2"], "password": PW["e2"]})
    ip_tok = cookie_val(hd0, "__Host-tbox_session")
    c, d, _, _ = req("GET", "/tbox/api/me", cookies={"__Host-tbox_session": ip_tok or ""}, site=SITE_IP)
    ok("APP_URL 이 IP 인 인스턴스 — /api/me passkey_available 거짓 · passkeys_active 0", c == 200 and d.get("passkey_available") is False
       and d.get("passkeys_active") == 0, (c0, c, d and {k: d.get(k) for k in ("passkey_available", "passkeys_active")}))

# ═══ PK-T4 로그인 양성 ═════════════════════════════════════════════════
sub("PK-T4 로그인 양성")
c, d, pre, hd = login_options()
pc = [v for v in setcookies(hd) if v.startswith("__Host-tbox_pk=")]
ok("로그인 옵션 200 · allowCredentials 빈 목록 · UV required · rpId localhost · timeout 300000",
   c == 200 and d["publicKey"]["allowCredentials"] == [] and d["publicKey"]["userVerification"] == "required"
   and d["publicKey"]["rpId"] == "localhost" and d["publicKey"]["timeout"] == 300000, d)
ok("로그인 전 쿠키 — __Host-tbox_pk 64자 hex · Max-Age=600 · path=/ · secure · HttpOnly · SameSite=Strict · Domain 없음",
   len(pc) == 1 and re.match(r"^__Host-tbox_pk=[0-9a-f]{64};", pc[0]) and "Max-Age=600" in pc[0] and "path=/" in pc[0]
   and "secure" in pc[0] and "HttpOnly" in pc[0] and "SameSite=Strict" in pc[0] and "domain=" not in pc[0].lower(), pc)
c2, d2, pre2, _ = login_options(pre)
ok("쿠키가 있으면 같은 값을 다시 쓴다(탭 여럿)", pre2 == pre)
ok("로그인 전 사람에게 계정 정보 0 — 옵션 응답에 사람·열쇠 글자 없음", all(n not in json.dumps(d2, ensure_ascii=False) for n in NAMES))
old = login_pw("e2")
ok("(준비) e2 의 비밀번호 세션 하나 — 이 쿠키를 든 채 지문 로그인", bool(old))
old_id = hashlib.sha256((old or "").encode()).hexdigest()
a0 = amax()
sc0 = sql("SELECT sign_count || '|' || coalesce(last_used_at::text,'-') FROM passkeys WHERE credential_id = decode('%s','hex')" % k_e2a.cred_id.hex())
c, d, b, hd, _ = pk_login(k_e2a, handle_e2, pre, opts=d2, count=5, extra_cookies={"__Host-tbox_session": old})
ok("지문 로그인 200 · /api/login 과 같은 모양(ok·next·mfa_required·mfa_recommended)", c == 200 and set(d.keys()) == {"ok", "next", "mfa_required", "mfa_recommended"}, (c, d))
new_tok = cookie_val(hd, "__Host-tbox_session")
ok("새 세션 쿠키(64자 hex) · 로그인 전 쿠키 지움(Max-Age=0)", bool(new_tok) and re.match("^[0-9a-f]{64}$", new_tok or "")
   and any(v.startswith("__Host-tbox_pk=") and ("Max-Age=0" in v or "1970" in v) for v in setcookies(hd)), setcookies(hd))
ok("옛 쿠키 세션은 지워졌다(세션 고정 막기)", sql("SELECT count(*) FROM sessions WHERE id = '%s'" % old_id) == "0")
ok("감사 auth.login allow passkey 한 행", audit_rows(a0, "action = 'auth.login'") == ["auth.login|allow|passkey"], audit_rows(a0))
sc1 = sql("SELECT sign_count || '|' || coalesce(last_used_at::text,'-') FROM passkeys WHERE credential_id = decode('%s','hex')" % k_e2a.cred_id.hex())
ok("서명 횟수 5 · 마지막 사용 갱신", sc1.startswith("5|") and sc1 != sc0 and not sc1.endswith("|-"), (sc0, sc1))
c, d, _, _ = req("GET", "/tbox/api/me", cookies={"__Host-tbox_session": new_tok})
ok("새 세션으로 /api/me 200 · 사람 = e2 · reauth_fresh 거짓", c == 200 and d.get("username") == USER["e2"] and d.get("reauth_fresh") is False, (c, d and d.get("username")))
unlock("a1")
c, d, b, hd, _ = pk_login(k_a1, handle_a1)
a1_tok = cookie_val(hd, "__Host-tbox_session")
ok("2단계 켠 Admin 도 코드 없이 200", c == 200, (c, d))
c, d, _, hd = req("GET", "/tbox/users", cookies={"__Host-tbox_session": a1_tok or ""})
ok("곧바로 /tbox/users → 302 /tbox/reauth?to=users (로그인은 재인증을 열지 않는다)", c == 302 and hd.get("Location") == "/tbox/reauth?to=users", (c, hd.get("Location")))

# ═══ PK-T5 로그인 음성 ═════════════════════════════════════════════════
sub("PK-T5 로그인 음성 — 전부 401 한 문구(「꺼진 열쇠」만 문장) + 감사 사유")


def neg(name, reason, key=k_e2a, handle=handle_e2, count=None, before=None, pre="new", body_mut=None, want_code=401, counts=None, **kw):
    unlock("e2")   # 앞 시험의 실패가 쌓여 잠기지 않게(잠긴 동안은 세지 않는다 — 셈을 보려면 풀어 둔다)
    if before:
        before()
    if pre == "new":
        c, o, p, _ = login_options()
    else:
        c, o, p, _ = login_options()
        p = pre
    a0 = amax()
    f0 = failed("e2")
    body = F.login_body(key, handle, o["publicKey"]["challenge"], kw.pop("origin", ORIGIN), RP, count=count, **kw)
    if body_mut:
        body = body_mut(body, o)
    cookies = {"__Host-tbox_pk": p} if p else None
    c, d, b, hd = req("POST", "/tbox/api/login/passkey", body=body, cookies=cookies)
    rows = audit_rows(a0, "action IN ('auth.login','user.expired','auth.lock')")
    want_rows = ["auth.login|deny|" + reason] if reason != "guest_expired" else ["user.expired|deny|guest_expired"]
    good = c == want_code and rows[:1] == want_rows
    if want_code == 401 and reason != "passkey_revoked":
        good = good and (d or {}).get("error") == LOGIN_FAILED and set((d or {}).keys()) == {"error"}
    if counts is not None:
        good = good and int(failed("e2")) - f0 == counts
    ok("%s → %d %s%s" % (name, want_code, reason, "" if counts is None else " · 실패 +%d" % counts), good, (c, d, rows, failed("e2"), f0))
    return b


unlock("e2")
c, o, p, _ = login_options()
body = F.login_body(k_e2a, handle_e2, o["publicKey"]["challenge"], ORIGIN, RP, count=6)
c1, _, _, _ = req("POST", "/tbox/api/login/passkey", body=body, cookies={"__Host-tbox_pk": p})
a0 = amax()
c2, d2, _, _ = req("POST", "/tbox/api/login/passkey", body=body, cookies={"__Host-tbox_pk": p})
ok("같은 응답 두 번(챌린지 재사용) → 첫째 200 · 둘째 401 passkey_challenge", c1 == 200 and c2 == 401 and audit_rows(a0) == ["auth.login|deny|passkey_challenge"], (c1, c2, audit_rows(a0)))
neg("만료(임시 DB 에서 expires_at 당김)", "passkey_challenge", count=7,
    before=lambda: None, body_mut=lambda b, o: (sql("UPDATE passkey_challenges SET expires_at = now() - interval '1 second' WHERE purpose='login' AND used_at IS NULL"), b)[1])
neg("로그인 전 쿠키 없음", "passkey_challenge", count=7, pre=None)
neg("로그인 전 쿠키가 다른 값", "passkey_challenge", count=7, pre="0" * 64)
neg("다른 origin(http://localhost:8399)", "passkey_invalid", count=7, origin="http://localhost:8399")
neg("rpIdHash 바꿔 다시 서명(evil.example)", "passkey_invalid", count=7, rp_hash=hashlib.sha256(b"evil.example").digest(), counts=1)
neg("UV 0", "passkey_invalid", count=7, flags=F.UP | F.BE | F.BS, counts=1)
neg("UP 0", "passkey_invalid", count=7, flags=F.UV | F.BE | F.BS, counts=1)
neg("BE 가 등록 때와 다름(0)", "passkey_invalid", count=7, flags=F.UP | F.UV, counts=1)
neg("AT 붙은 authData", "passkey_invalid", count=7, ad=F.auth_data(RP, F.UP | F.UV | F.BE | F.BS | F.AT, 7, k_e2a), counts=1)
neg("type webauthn.create", "passkey_invalid", count=7, typ="webauthn.create")
bad_sig_body = neg("서명 한 바이트 뒤집기", "passkey_invalid", count=7, flip_sig=True, counts=1)
neg("남의 열쇠로 서명(열쇠 id 는 e2 것)", "passkey_invalid", count=7, sign_with=F.SoftKey(), counts=1)
unlock("e2")
neg("서명 횟수 역행(저장값 100 · 새 값 50) → passkey_counter", "passkey_counter", count=50,
    before=lambda: sql("UPDATE passkeys SET sign_count = 100 WHERE credential_id = decode('%s','hex')" % k_e2a.cred_id.hex()), counts=1)
neg("서명 횟수 같음(100 · 100)", "passkey_counter", count=100, counts=1)
# RV-PK-1(독립 검토 2026-09-27) — 저장값 100 을 읽은 **뒤** 다른 요청이 먼저 150 으로 올리고 커밋(이 요청의 새 값 101 은 읽은 값보다 크다).
# 갱신이 조건부(AND sign_count = 읽은 값)라 0 행 → 세션 발급 트랜잭션을 통째로 되돌린다. 조건이 없던 판은 200 + 세션 + 서명 횟수 101 로 뒤로 감.
unlock("e2")
c, o, p, _ = login_options()
body = F.login_body(k_e2a, handle_e2, o["publicKey"]["challenge"], ORIGIN, RP, count=101)
e2_sess0 = sql("SELECT count(*) FROM sessions WHERE user_id = %d" % uid("e2"))
a0 = amax()
f0 = failed("e2")
res, held, waited = race_send(k_e2a, 150, lambda: req("POST", "/tbox/api/login/passkey", body=body, cookies={"__Host-tbox_pk": p}))
c, d, b, hd = res if res else (None, None, None, None)
rows = audit_rows(a0, "action IN ('auth.login','auth.lock')")
sc_after = sql("SELECT sign_count FROM passkeys WHERE credential_id = decode('%s','hex')" % k_e2a.cred_id.hex())
sess_after = sql("SELECT count(*) FROM sessions WHERE user_id = %d" % uid("e2"))
new_sess = cookie_val(hd, "__Host-tbox_session") if hd is not None else None
ok("RV-PK-1 로그인 — 읽은 뒤 다른 요청이 먼저 서명 횟수를 올림(100 → 150 · 이 요청 101 · 서버 UPDATE 가 잠금을 기다린 뒤 커밋) → "
   "401 통일 문구 · 감사 passkey_counter · 실패 +1 · 세션 발급 0 · 서명 횟수 150 그대로",
   held and waited and c == 401 and (d or {}).get("error") == LOGIN_FAILED and set((d or {}).keys()) == {"error"}
   and rows == ["auth.login|deny|passkey_counter"] and failed("e2") - f0 == 1 and new_sess is None
   and e2_sess0.isdigit() and sess_after == e2_sess0 and sc_after == "150",
   (held, waited, c, d, rows, failed("e2") - f0, bool(new_sess), e2_sess0, sess_after, sc_after))
sql("UPDATE passkeys SET sign_count = 0 WHERE credential_id = decode('%s','hex')" % k_e2a.cred_id.hex())
unlock("e2")
neg("userHandle 남의 것(a1)", "passkey_invalid", count=0, handle=handle_a1)
neg("userHandle 빠짐", "passkey_invalid", count=0, handle=None)
unknown_body = neg("없는 열쇠", "passkey_unknown", key=F.SoftKey(), count=0)
c_u, d_u, b_u, _, _ = pk_login(F.SoftKey(), handle_e2, count=0)
c_s, d_s, b_s, _, _ = pk_login(k_e2a, handle_e2, count=0, flip_sig=True)
ok("「없는 열쇠」와 「서명 틀림」의 응답 본문이 같다(바이트)", c_u == c_s == 401 and b_u == b_s, (b_u, b_s))
unlock("e2")
# 해제된 열쇠 · 90일
k_rev = F.SoftKey()
register(s["e2"], k_rev, "해제될 폰")
sql("UPDATE passkeys SET revoked_at = now(), revoke_reason = 'self', revoked_by = %d WHERE credential_id = decode('%s','hex')" % (uid("e2"), k_rev.cred_id.hex()))
c, o, p, _ = login_options()
a0 = amax()
body = F.login_body(k_rev, handle_e2, o["publicKey"]["challenge"], ORIGIN, RP)
c, d, b, hd = req("POST", "/tbox/api/login/passkey", body=body, cookies={"__Host-tbox_pk": p})
ok("해제된 열쇠 → 401 「꺼져 있습니다」 문장 + passkey_off · 감사 passkey_revoked · 로그인 전 쿠키 지움",
   c == 401 and "꺼져 있습니다" in (d or {}).get("error", "") and (d or {}).get("passkey_off") is True
   and audit_rows(a0, "action='auth.login'") == ["auth.login|deny|passkey_revoked"]
   and any(v.startswith("__Host-tbox_pk=") and ("Max-Age=0" in v or "1970" in v) for v in setcookies(hd)), (c, d, audit_rows(a0)))
k_old = F.SoftKey()
register(s["e2"], k_old, "오래된 폰")
sql("UPDATE passkeys SET last_used_at = now() - interval '91 days', created_at = now() - interval '120 days' WHERE credential_id = decode('%s','hex')" % k_old.cred_id.hex())
neg("90일 지난 열쇠(last_used_at 91일 전)", "passkey_revoked", key=k_old)
c, d, _, _ = s["e2"].get("/tbox/api/me/passkeys")
ok("목록에 90일 열쇠는 「stale」 · 해제된 열쇠는 없음", c == 200 and any(p["name"] == "오래된 폰" and p["state"] == "stale" for p in d["passkeys"])
   and not any(p["name"] == "해제될 폰" for p in d["passkeys"]), d)
ok("/api/me passkeys_active 는 90일 열쇠를 세지 않는다", S("e2").me.get("passkeys_active") == int(sql(
    "SELECT count(*) FROM passkeys WHERE user_id = %d AND revoked_at IS NULL AND coalesce(last_used_at, created_at) >= now() - interval '90 days'" % uid("e2"))))
neg("비활성 계정", "inactive", count=0,
    before=lambda: sql("UPDATE users SET is_active = false WHERE username = 'claude_employee02'"))
sql("UPDATE users SET is_active = true WHERE username = 'claude_employee02'")
neg("must_change_password(임시 비밀번호 72시간 규칙 우회 막기)", "forced_step", count=0,
    before=lambda: sql("UPDATE users SET must_change_password = true WHERE username = 'claude_employee02'"))
sql("UPDATE users SET must_change_password = false WHERE username = 'claude_employee02'")
neg("잠긴 계정", "locked", count=0, want_code=423,
    before=lambda: sql("UPDATE users SET failed_count = 5, locked_until = now() + interval '10 minutes' WHERE username = 'claude_employee02'"))
unlock("e2")
# Guest 만료
k_g = F.SoftKey()
c, d, handle_g = register(s["g1"], k_g, "손님 폰")
ok("(준비) 손님도 등록 201(취합 판단 — Guest 받기)", c == 201, (c, d))
G_EXP = sql("SELECT expires_at FROM users WHERE username = 'claude_guest01'")
sql("UPDATE users SET expires_at = now() - interval '1 day' WHERE username = 'claude_guest01'")
c, o, p, _ = login_options()
a0 = amax()
c, d, _, _ = req("POST", "/tbox/api/login/passkey", body=F.login_body(k_g, handle_g, o["publicKey"]["challenge"], ORIGIN, RP), cookies={"__Host-tbox_pk": p})
ok("Guest 만료 → 401 통일 문구 · 감사 user.expired guest_expired", c == 401 and (d or {}).get("error") == LOGIN_FAILED
   and "user.expired|deny|guest_expired" in audit_rows(a0), (c, d, audit_rows(a0)))
sql("UPDATE users SET expires_at = '%s' WHERE username = 'claude_guest01'" % G_EXP)
# 모양 거부(400 · 세지 않음)
f0 = failed("e2")
for name, body in [("빈 본문", {}), ("모르는 키", {"type": "public-key", "id": "a", "rawId": "a", "x": 1}),
                   ("id ≠ rawId", {"type": "public-key", "id": "AAAA", "rawId": "BBBB"}),
                   ("authenticatorAttachment cross-platform", dict(F.login_body(k_e2a, handle_e2, F.b64u(os.urandom(32)), ORIGIN, RP), authenticatorAttachment="cross-platform")),
                   ("서명 73바이트", dict(F.login_body(k_e2a, handle_e2, F.b64u(os.urandom(32)), ORIGIN, RP), signature=F.b64u(os.urandom(73)))),
                   ("base64url 아님", dict(F.login_body(k_e2a, handle_e2, F.b64u(os.urandom(32)), ORIGIN, RP), clientDataJSON="**"))]:
    c, d, _, _ = req("POST", "/tbox/api/login/passkey", body=body)
    ok("모양 거부 — %s → 400" % name, c == 400, (c, d))
ok("모양 거부는 실패로 세지 않는다", failed("e2") == f0)
c, d, _, _ = req("POST", "/tbox/api/login/passkey/options", raw=b"{}", ctype="application/x-www-form-urlencoded")
ok("로그인 옵션 — JSON 이 아니면 400(크로스사이트 폼 막기)", c == 400, c)
c, d, _, _ = req("POST", "/tbox/api/login/passkey/options", body={"username": "claude_employee02"})
ok("로그인 옵션 — 아이디를 받지 않는다(본문 {} 만) → 400", c == 400, c)

# ═══ PK-T6 재인증 (라운드 C) ═══════════════════════════════════════════
sub("PK-T6 재인증을 지문·얼굴로 (라운드 C)")
unlock("a1")
a1 = S("a1", a1_tok)
c, d, _, _ = a1.get("/tbox/api/users")
ok("(준비) 지문 로그인한 a1 — /api/users 403 next reauth", c == 403 and (d or {}).get("next") == "reauth", (c, d))
c, o, _, _ = a1.post("/tbox/api/me/reauth/passkey/options", {})
ok("재인증 옵션 200 · allowCredentials = a1 의 열쇠 하나", c == 200 and [x["id"] for x in o["publicKey"]["allowCredentials"]] == [F.b64u(k_a1.cred_id)], (c, o))
a0 = amax()
body = F.login_body(k_a1, handle_a1, o["publicKey"]["challenge"], ORIGIN, RP, count=0)
c, d, _, _ = a1.post("/tbox/api/me/reauth/passkey", body)
ok("지문으로 재인증 200 · reauth_until · 감사 auth.reauth allow passkey", c == 200 and d.get("ok") is True and d.get("reauth_until")
   and audit_rows(a0, "action='auth.reauth'") == ["auth.reauth|allow|passkey"], (c, d, audit_rows(a0)))
c, d, _, _ = a1.get("/tbox/api/users")
ok("그 뒤 /api/users 200(15분 창)", c == 200, c)
c, d, _, _ = a1.post("/tbox/api/me/reauth/passkey", body)
ok("같은 응답 다시 → 400(챌린지 한 번)", c == 400, c)
c, o2, _, _ = a1.post("/tbox/api/me/reauth/passkey/options", {})
a0 = amax()
c, d, _, _ = a1.post("/tbox/api/me/reauth/passkey", F.login_body(k_e2a, handle_e2, o2["publicKey"]["challenge"], ORIGIN, RP, count=0))
ok("다른 계정(e2)의 열쇠로 a1 재인증 → 400 · 감사 auth.reauth deny passkey_invalid", c == 400
   and audit_rows(a0, "action='auth.reauth'") == ["auth.reauth|deny|passkey_invalid"], (c, audit_rows(a0)))
# RV-PK-1 재인증 — 로그인과 같은 흉내(저장값 10 을 읽은 뒤 다른 요청이 20 · 이 요청 11) → 조건부 갱신 0 행 → 되돌림 · 기존 passkey_invalid 갈래
unlock("a1")
sql("UPDATE passkeys SET sign_count = 10 WHERE credential_id = decode('%s','hex')" % k_a1.cred_id.hex())
a1r = S("a1", login_pw("a1"))
c, o, _, _ = a1r.post("/tbox/api/me/reauth/passkey/options", {})
body = F.login_body(k_a1, handle_a1, o["publicKey"]["challenge"], ORIGIN, RP, count=11)
a0 = amax()
f0 = failed("a1")
res, held, waited = race_send(k_a1, 20, lambda: a1r.post("/tbox/api/me/reauth/passkey", body))
c, d = (res[0], res[1]) if res else (None, None)
c2, d2, _, _ = a1r.get("/tbox/api/users")
rows = audit_rows(a0, "action = 'auth.reauth'")
ra = sql("SELECT (reauth_at IS NULL)::text FROM sessions WHERE id = '%s'" % hashlib.sha256(a1r.token.encode()).hexdigest())
sc_after = sql("SELECT sign_count FROM passkeys WHERE credential_id = decode('%s','hex')" % k_a1.cred_id.hex())
ok("RV-PK-1 재인증 — 읽은 뒤 다른 요청이 먼저 서명 횟수를 올림(10 → 20 · 이 요청 11 · 서버 UPDATE 가 잠금을 기다린 뒤 커밋) → "
   "400 · 감사 auth.reauth deny passkey_invalid · 재인증 창 안 열림(reauth_at 없음 · /api/users 403 next reauth) · 실패 셈 그대로 · 서명 횟수 20 그대로",
   held and waited and c == 400 and rows == ["auth.reauth|deny|passkey_invalid"] and ra == "true"
   and c2 == 403 and (d2 or {}).get("next") == "reauth" and failed("a1") == f0 and sc_after == "20",
   (held, waited, c, d, rows, ra, c2, (d2 or {}).get("next"), failed("a1"), f0, sc_after))
sql("UPDATE passkeys SET sign_count = 0 WHERE credential_id = decode('%s','hex')" % k_a1.cred_id.hex())
unlock("a1")
a1b = S("a1", login_pw("a1"))
codes = []
for i in range(5):
    c, o, _, _ = a1b.post("/tbox/api/me/reauth/passkey/options", {})
    if c != 200:
        codes.append("opt%d" % c)
        break
    c, d, _, _ = a1b.post("/tbox/api/me/reauth/passkey", F.login_body(k_a1, handle_a1, o["publicKey"]["challenge"], ORIGIN, RP, count=0, flip_sig=True))
    codes.append(c)
c, d, _, _ = a1b.get("/tbox/api/me")
ok("재인증 지문 실패 5회 → 잠금 · 그 계정 세션 끊김(/api/me 401) · auth.lock 한 행", codes == [400] * 5 and c == 401
   and sql("SELECT (locked_until > now())::text FROM users WHERE username='claude_admin01'") == "true", (codes, c))
unlock("a1")
c, d, _, _ = s["g1"].post("/tbox/api/me/reauth/passkey/options", {})
ok("재인증 옵션은 **내** 열쇠만(g1 — 자기 것 1개)", c == 200 and len(d["publicKey"]["allowCredentials"]) == 1, (c, d))
c, d, _, _ = s["m1"].post("/tbox/api/me/reauth/passkey/options", {})
ok("열쇠가 없으면 재인증 옵션 400", c == 400, c)

# ═══ PK-T7 자동 해제 ══════════════════════════════════════════════════
sub("PK-T7 자동 해제 · 본인 해제 · 남의 번호")
live = lambda k: int(sql("SELECT count(*) FROM passkeys WHERE user_id = %d AND revoked_at IS NULL" % uid(k)))
mine = int(sql("SELECT min(id) FROM passkeys WHERE user_id = %d AND revoked_at IS NULL" % uid("e2")))
theirs = int(sql("SELECT min(id) FROM passkeys WHERE user_id = %d AND revoked_at IS NULL" % uid("a1")))
c1, d1, b1, _ = s["e2"].delete("/tbox/api/me/passkeys/%d" % theirs)
c2, d2, b2, _ = s["e2"].delete("/tbox/api/me/passkeys/999999")
ok("남의 번호 DELETE → 404 · 없는 번호와 같은 본문", c1 == 404 and c2 == 404 and b1 == b2, (c1, c2, b1, b2))
ok("남의 열쇠는 그대로", sql("SELECT revoked_at IS NULL FROM passkeys WHERE id = %d" % theirs) == "t")
a0 = amax()
n0 = live("e2")
c, d, _, _ = s["e2"].delete("/tbox/api/me/passkeys/%d" % mine)
ok("본인 해제 200 · revoked_at + self · 감사 auth.passkey_revoke allow self · remaining", c == 200 and live("e2") == n0 - 1
   and sql("SELECT revoke_reason FROM passkeys WHERE id = %d" % mine) == "self"
   and audit_rows(a0, "action LIKE 'auth.passkey%'") == ["auth.passkey_revoke|allow|self"] and isinstance(d.get("remaining"), int), (c, d, audit_rows(a0)))
c, d, _, _ = s["e2"].delete("/tbox/api/me/passkeys/%d" % mine)
ok("이미 해제한 번호 → 404", c == 404, c)


def prep_e2(n=2):
    for i in range(n):
        register(s["e2"], F.SoftKey(), "해제 시험 %d" % i)


def check_revoked(label, cause, a0, before_live):
    rows = audit_rows(a0, "action = 'auth.passkey_revoke'")
    ok("%s → e2 열쇠 전부 해제(%d개) · 사유 %s · 감사 열쇠마다 한 행" % (label, before_live, cause),
       live("e2") == 0 and before_live > 0 and rows == ["auth.passkey_revoke|allow|" + cause] * before_live
       and sql("SELECT count(*) FROM passkeys WHERE user_id = %d AND revoke_reason = '%s' AND revoked_at > now() - interval '1 minute'" % (uid("e2"), cause)) == str(before_live),
       (live("e2"), rows))


E2_HASH = sql("SELECT password_hash FROM users WHERE username='claude_employee02'")
E2_TEAM = sql("SELECT coalesce(team_id::text,'') FROM users WHERE username='claude_employee02'")
# 본인 비밀번호 변경
prep_e2()
n = live("e2")
sql("INSERT INTO api_tokens (user_id, name, token_hash, hint, created_by, scope, expires_at) VALUES (%d, 'pk-test', '%s', 'abcdef', %d, 'read', now() + interval '1 day')"
    % (uid("e2"), hashlib.sha256(os.urandom(16)).hexdigest(), uid("e2")))
a0 = amax()
c, d, _, _ = s["e2"].post("/tbox/api/me/password", {"current": PW["e2"], "new": PW["e2"] + "-x1"})
check_revoked("본인 비밀번호 변경", "password_change", a0, n)
ok("  응답 passkeys_revoked · 토큰 회수와 한 트랜잭션(api_token.revoke 도)", c == 200 and d.get("passkeys_revoked") == n and d.get("tokens_revoked", 0) >= 1, (c, d))
sql("UPDATE users SET password_hash = '%s' WHERE username = 'claude_employee02'" % E2_HASH)
s["e2"] = S("e2", login_pw("e2"))
# 관리자 임시 비밀번호 재발급 · 2단계 초기화 · 역할 · 비활성 · 팀
admin = S("a1", login_pw("a1"))
c, d, _, _ = admin.post("/tbox/api/me/reauth", {"password": PW["a1"]})
ok("(준비) a1 비밀번호 재인증 200", c == 200, (c, d))
e2id = uid("e2")
for label, method, path, body, cause in [
    ("임시 비밀번호 재발급", "POST", "/tbox/api/users/%d/password/reset" % e2id, {"reason_text": "패스키 시험"}, "password_reset"),
    ("2단계 초기화", "POST", "/tbox/api/users/%d/mfa/reset" % e2id, {"reason_text": "패스키 시험"}, "mfa_reset"),
    ("역할 변경(직원 → 팀장)", "PATCH", "/tbox/api/users/%d" % e2id, {"role": "Manager", "reason_text": "패스키 시험"}, "role_change"),
    ("비활성화", "PATCH", "/tbox/api/users/%d" % e2id, {"is_active": False, "reason_text": "패스키 시험"}, "deactivate"),
]:
    sql("UPDATE users SET password_hash = '%s', must_change_password = false, temp_password_issued_at = NULL, role = 'Employee', "
        "is_active = true, mfa_setup_required = false, failed_count = 0, locked_until = NULL WHERE username = 'claude_employee02'" % E2_HASH)
    s["e2"] = S("e2", login_pw("e2"))
    prep_e2()
    n = live("e2")
    a0 = amax()
    c, d, _, _ = req(method, path, cookies=admin.ck(), body=body, csrf=admin.csrf)
    ok("  (%s 창구 %d)" % (label, c), c == 200, (c, d))
    check_revoked(label, cause, a0, n)
sql("UPDATE users SET password_hash = '%s', must_change_password = false, temp_password_issued_at = NULL, role = 'Employee', "
    "is_active = true, mfa_setup_required = false WHERE username = 'claude_employee02'" % E2_HASH)
s["e2"] = S("e2", login_pw("e2"))
prep_e2()
n = live("e2")
other_team = sql("SELECT id FROM teams WHERE is_active AND id::text <> '%s' ORDER BY id LIMIT 1" % E2_TEAM)
if other_team:
    c, d, _, _ = req("PATCH", "/tbox/api/users/%d" % e2id, cookies=admin.ck(), body={"team_id": int(other_team), "reason_text": "패스키 시험"}, csrf=admin.csrf)
    ok("팀 변경은 해제하지 않는다(%d개 그대로)" % n, c == 200 and live("e2") == n, (c, live("e2")))
    sql("UPDATE users SET team_id = %s WHERE username = 'claude_employee02'" % (E2_TEAM or "NULL"))
    s["e2"] = S("e2", login_pw("e2"))
# 2단계 끄기(켜 둔 뒤 끄기) — 해제하지 않는다
sql("UPDATE users SET mfa_enabled = true, mfa_secret = '%s', mfa_last_step = NULL, mfa_setup_required = false WHERE username = 'claude_employee02'" % SEC["e2"])
s["e2"] = S("e2", login_pw("e2"))
n = live("e2")
c, d, _, _ = s["e2"].post("/tbox/api/me/mfa/disable", {"current_password": PW["e2"], "otp": fresh_code("e2")})
ok("2단계 끄기는 해제하지 않는다(%d개 그대로)" % n, c == 200 and live("e2") == n, (c, d, live("e2")))
# 한 트랜잭션 — 열쇠 해제 중 실패를 흉내 내면 토큰 회수·비밀번호 재발급도 되돌아간다
sql("CREATE OR REPLACE FUNCTION pk_test_fail() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN "
    "IF NEW.revoke_reason = 'password_reset' THEN RAISE EXCEPTION 'pk test fail'; END IF; RETURN NEW; END $$;\n"
    "DROP TRIGGER IF EXISTS pk_test_fail ON passkeys;\n"
    "CREATE TRIGGER pk_test_fail BEFORE UPDATE ON passkeys FOR EACH ROW EXECUTE FUNCTION pk_test_fail();")
sql("INSERT INTO api_tokens (user_id, name, token_hash, hint, created_by, scope, expires_at) VALUES (%d, 'pk-tx', '%s', 'abcdef', %d, 'read', now() + interval '1 day')"
    % (e2id, hashlib.sha256(os.urandom(16)).hexdigest(), e2id))
prep_e2(1)
n = live("e2")
tok_live = sql("SELECT count(*) FROM api_tokens WHERE user_id = %d AND revoked_at IS NULL" % e2id)
h0 = sql("SELECT password_hash FROM users WHERE username='claude_employee02'")
c, d, _, _ = admin.post("/tbox/api/users/%d/password/reset" % e2id, {"reason_text": "패스키 트랜잭션 시험"})
ok("해제 중 실패(트리거 흉내) → 500 · 열쇠·토큰·비밀번호 모두 그대로(한 트랜잭션)", c == 500 and live("e2") == n
   and sql("SELECT count(*) FROM api_tokens WHERE user_id = %d AND revoked_at IS NULL" % e2id) == tok_live
   and sql("SELECT password_hash FROM users WHERE username='claude_employee02'") == h0, (c, live("e2"), n))
sql("DROP TRIGGER IF EXISTS pk_test_fail ON passkeys; DROP FUNCTION IF EXISTS pk_test_fail();")
# 원래대로(e2 의 2단계)
mfa_on, _, mfa_sec = E2_MFA.partition("|")
sql("UPDATE users SET mfa_enabled = false, mfa_last_step = NULL WHERE username = 'claude_employee02'")

# ═══ PK-T8 챌린지 표 · 권한 ═══════════════════════════════════════════
sub("PK-T8 챌린지 표 · 권한")
sql("INSERT INTO passkey_challenges (challenge_hash, purpose, binding_hash, expires_at, created_at) "
    "SELECT encode(sha256(('old'||g)::bytea),'hex'), 'login', repeat('a',64), now() - interval '11 minutes', now() - interval '20 minutes' FROM generate_series(1,20) g")
login_options()
ok("만료 정리 — 10분 넘게 지난 챌린지 20개가 옵션 한 번에 지워졌다", sql("SELECT count(*) FROM passkey_challenges WHERE expires_at < now() - interval '10 minutes'") == "0")
sql("INSERT INTO passkey_challenges (challenge_hash, purpose, binding_hash, expires_at) "
    "SELECT encode(sha256(('live'||g)::bytea),'hex'), 'login', repeat('b',64), now() + interval '5 minutes' FROM generate_series(1,5000) g")
c, d, _, _ = login_options()
ok("살아 있는 로그인 챌린지 5,000 넘음 → 503 「잠시 뒤 다시 시도하세요」", c == 503 and (d or {}).get("error") == "잠시 뒤 다시 시도하세요", (c, d))
sql("DELETE FROM passkey_challenges WHERE binding_hash = repeat('b',64)")
c, d, _, _ = login_options()
ok("  지운 뒤 다시 200", c == 200, c)
priv = sql("SELECT has_table_privilege('tbox_app','passkeys','DELETE')::text || has_table_privilege('tbox_app','passkeys','INSERT')::text || "
           "has_table_privilege('tbox_app','passkey_challenges','DELETE')::text || has_table_privilege('tbox_backup','passkeys','SELECT')::text || "
           "has_table_privilege('tbox_backup','passkeys','INSERT')::text || has_table_privilege('tbox_backup','passkey_challenges','UPDATE')::text")
ok("권한 — tbox_app passkeys DELETE 없음 · INSERT 있음 · 챌린지 DELETE 있음 · tbox_backup SELECT 만",
   priv == "falsetruetruetruefalsefalse", priv)
for role in ("tbox_perm", "tbox_push"):
    r = sql("SELECT CASE WHEN EXISTS (SELECT 1 FROM pg_roles WHERE rolname='%s') THEN has_table_privilege('%s','passkeys','SELECT')::text ELSE 'none' END" % (role, role))
    ok("권한 — %s 는 passkeys 에 닿지 않는다" % role, r in ("false", "none"), r)
ok("챌린지 표에 원문 0 — challenge_hash 64자 hex 만 · binding_hash 64자 hex",
   sql("SELECT count(*) FROM passkey_challenges WHERE challenge_hash !~ '^[0-9a-f]{64}$' OR (binding_hash IS NOT NULL AND binding_hash !~ '^[0-9a-f]{64}$')") == "0")

# ═══ PK-T9 경보 ═══════════════════════════════════════════════════════
sub("PK-T9 경보 — 임시 사본 alerts.php --dry-run(임시 .env · 임시 상태 · 운영 auth.log 오프셋에 닿지 않게 --auth-log 빈 파일)")
open(os.path.join(WORK, "empty-auth.log"), "w").close()


def run_alerts(rule):
    r = subprocess.run(["php", os.path.join(INST, "app/scripts/alerts.php"), "--rule", rule, "--dry-run",
                        "--state", os.path.join(WORK, "al-%s.json" % rule), "--auth-log", os.path.join(WORK, "empty-auth.log")],
                       capture_output=True, text=True, cwd="/")
    return r.returncode, r.stdout + r.stderr


# 경보 창은 5분 — 이 절에서 **새로** 사건 셋을 만든다(앞 절의 사건은 창 밖일 수 있다)
# ① 사람 모양(검토 UA 아님) 등록 — origin human
sql("UPDATE passkeys SET revoked_at = now(), revoke_reason = 'self' WHERE user_id = %d AND revoked_at IS NULL" % uid("e1"))
c, o, _, _ = reg_options(s["e1"])
kh = F.SoftKey()
body = F.register_body(kh, "사람 폰", o["publicKey"]["challenge"], ORIGIN, RP)
c, d, _, _ = req("POST", "/tbox/api/me/passkeys", cookies=s["e1"].ck(), body=body, csrf=s["e1"].csrf,
                 ua="Mozilla/5.0 (Linux; Android 16; SM-F966N) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/29.0 Chrome/140.0 Mobile Safari/537.36")
ok("(준비) 검토 UA 없이 등록 201", c == 201, (c, d))
# ② 검토 계정 + 검토 UA 등록 — origin claude-review
unlock("a1")
a1c = S("a1", login_pw("a1"))
c, d, _ = register(a1c, F.SoftKey(), "관리자 둘째 폰")
ok("(준비) a1 검토 UA 등록 201", c == 201, (c, d))
# ③ 서명 횟수 역행 — e2 의 새 열쇠
unlock("e2")
s["e2"] = S("e2", login_pw("e2"))
kc = F.SoftKey()
c, d, hc = register(s["e2"], kc, "복제 의심 폰")
sql("UPDATE passkeys SET sign_count = 100 WHERE credential_id = decode('%s','hex')" % kc.cred_id.hex())
c, d, _, _, _ = pk_login(kc, hc, count=50)
ok("(준비) 역행 로그인 401", c == 401, (c, d))
unlock("e2")
rc, out = run_alerts("AL-23")
lines = [l for l in out.splitlines() if "[dry-run] AL-23" in l]
ok("AL-23 dry-run 종료 0", rc == 0, out[-400:])
ok("AL-23 — claude_employee01(사람 UA) 등록 → origin human", any("AL-23 claude_employee01 " in l and "origin=human" in l and '"kind":"register"' in l for l in lines), lines)
ok("AL-23 — claude_admin01(검토 UA) 등록 → origin claude-review", any("AL-23 claude_admin01 " in l and "origin=claude-review" in l for l in lines), lines)
ok("AL-23 — 서명 횟수 역행 → counter 한 줄(claude_employee02)", any("AL-23 claude_employee02 " in l and '"kind":"counter"' in l for l in lines), lines)
ok("AL-23 근거 — 기기 요약·열쇠 번호·이름 칸 · 열쇠 id·공개 키 원문 0", all(F.b64u(kh.cred_id) not in l and kh.cred_id.hex() not in l
   and kh.spki_der().hex()[:40] not in l for l in lines)
   and any('"device_hint":"Android Samsung Internet"' in l and '"credential_no":"' in l and '"credential_label":"' in l for l in lines), lines)
rc, out = run_alerts("AL-2")
ok("AL-2 dry-run — 재인증 지문 실패(auth.reauth deny passkey_*)가 로그인 뒤 실패로 들어간다", rc == 0 and any(
    "[dry-run] AL-2 " in l and "auth.reauth" in l for l in out.splitlines()), out[-600:])

# ═══ Q:F-7 B1-1 서버 쪽 — 보던 곳으로 · 열린 되돌리기 ═══════════════════════
sub("B1-1 서버 — 미로그인 302 꼬리표 · 로그인 화면 data-to · 이미 로그인 → 302 · 벡터(서버 Location)")
page_uuid = sql("SELECT public_id FROM community.pages WHERE deleted_at IS NULL ORDER BY id LIMIT 1")
c, d, b, hd = req("GET", "/community/p/%s?edit=1" % page_uuid)
ok("미로그인 /community/p/<uuid>?edit=1 → 302 /tbox/login?to=%2Fcommunity%2Fp%2F<uuid>%3Fedit%3D1",
   c == 302 and hd.get("Location") == "/tbox/login?to=%2Fcommunity%2Fp%2F" + page_uuid + "%3Fedit%3D1", (c, hd.get("Location")))
c, d, b, hd = req("GET", "/community/")
ok("미로그인 /community/ → 옛 키 ?to=community", c == 302 and hd.get("Location") == "/tbox/login?to=community", hd.get("Location"))
c, d, b, hd = req("GET", "/tbox/settings")
ok("미로그인 /tbox/settings → /tbox/login?to=%2Ftbox%2Fsettings", c == 302 and hd.get("Location") == "/tbox/login?to=%2Ftbox%2Fsettings", hd.get("Location"))
c, d, b, hd = req("GET", "/tbox/")
ok("미로그인 /tbox/ → /tbox/login(꼬리표 없음 — 첫 화면)", c == 302 and hd.get("Location") == "/tbox/login", hd.get("Location"))
c, d, b, hd = req("GET", "/tbox/users")
ok("미로그인 /tbox/users(관리 화면) → /tbox/login(꼬리표 없음)", c == 302 and hd.get("Location") == "/tbox/login", hd.get("Location"))
c, d, b, hd = req("GET", "/tbox/?q=%EB%B3%B4%EA%B3%A0%EC%84%9C&folder=3")
ok("미로그인 /tbox/?q=보고서&folder=3 → 정규형(folder 먼저) 꼬리표", c == 302 and hd.get("Location") == "/tbox/login?to=" + urllib.request.quote("/tbox/?folder=3&q=%EB%B3%B4%EA%B3%A0%EC%84%9C", safe=""), hd.get("Location"))
c, d, b, hd = req("GET", "/tbox/login?to=%2Fcommunity%2Fp%2F" + page_uuid)
ok("로그인 화면 data-to = 정규형 · 왼쪽 판 커뮤니티", c == 200 and ('data-to="/community/p/%s"' % page_uuid).encode() in b and "커뮤니티로".encode() in b, c)
c, d, b, hd = req("GET", "/tbox/login?to=%2Fcommunity%2Fp%2F" + page_uuid, cookies=s["e1"].ck())
ok("이미 로그인된 채 /tbox/login?to=%2Fcommunity%2Fp%2F<uuid> → 302 그 페이지", c == 302 and hd.get("Location") == "/community/p/" + page_uuid, (c, hd.get("Location")))
vec_path = os.path.join(os.path.dirname(os.path.abspath(__file__)), "lib", "return_path_vectors.txt")
vbad = []
nv = 0
for line in open(vec_path, encoding="utf-8").read().split("\n"):
    if line == "" or line.startswith("#"):
        continue
    inp, want = line.split("\t")
    nv += 1
    c, d, b, hd = req("GET", "/tbox/login?to=" + urllib.request.quote(inp, safe=""), cookies=s["e1"].ck())
    loc = hd.get("Location")
    exp = want if want != "-" else "/tbox/"
    if c != 302 or loc != exp:
        vbad.append((inp[:80], want[:80], c, loc))
ok("벡터 %d줄 — 이미 로그인된 채 ?to=<벡터> → 302 Location = 기대(목록 밖은 첫 화면 /tbox/)" % nv, vbad == [], vbad[:5])
lbad = []
for line in open(vec_path, encoding="utf-8").read().split("\n"):
    if line == "" or line.startswith("#"):
        continue
    inp, want = line.split("\t")
    c, d, b, hd = req("GET", "/tbox/login?to=" + urllib.request.quote(inp, safe=""))
    m = re.search(rb'<body[^>]*?data-to="([^"]*)"', b or b"")
    got = m.group(1).decode().replace("&amp;", "&") if m else None
    expw = None if want == "-" else want
    if inp in ("tbox", "community"):
        continue
    if c != 200 or got != expw:
        lbad.append((inp[:60], want[:60], got))
ok("벡터 — 로그인 화면의 data-to 가 기대와 같다(목록 밖은 속성 없음)", lbad == [], lbad[:5])
c, d, b, hd = req("GET", "/tbox/login?to=community")
ok("옛 키 ?to=community 그대로(data-to=community)", c == 200 and b'data-to="community"' in b, c)
c, d, b, hd = req("GET", "/tbox/login?to=%0d%0aSet-Cookie:x")
ok("CR·LF 벡터 — 응답 머리에 Set-Cookie: x 0", c == 200 and not any("x" == v.split("=")[0] for v in setcookies(hd)), setcookies(hd))

print("\nAPI_PASS=%d API_FAIL=%d" % (P, Fl))
sys.exit(0 if Fl == 0 else 1)
