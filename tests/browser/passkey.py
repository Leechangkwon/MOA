#!/usr/bin/env python3
# tests/browser/passkey.py — T:F-72 화면 시험(PK-T10 로그인 · PK-T11 내 계정 · PK-T12 원래 자리 · PK-T13 누름 수) + 라운드 C 화면
#
# 부르는 쪽: tests/passkey.sh (임시 인스턴스 · 임시 DB `tbox_d3p_*`. 운영 80·8082·8081 에는 요청하지 않는다).
# 쓰는 법: py_secret S_E1 S_E2 S_A1 S_G1 PW_E1 PW_E2 PW_A1 SC_E1 SC_E2 SC_A1 -- python3 passkey.py <SITE> <UA> <SHOTS> <DB> <WORK> <SITE_ALT>
#   SITE = http://localhost:<포트>(RP ID localhost) · SITE_ALT = 같은 DB · APP_URL 은 SITE 인 다른 포트(다른 origin 거부 확인)
#   세션·비밀번호·2단계 비밀은 표준입력 열 줄(R-62). 준비 SQL 은 임시 DB 에만(표준입력).
# 가상 인증기: 크로미움 CDP `WebAuthn.enable` + `addVirtualAuthenticator({ctap2, internal, resident, UV, isUserVerified, automaticPresenceSimulation})`.
#   탭마다 인증기가 따로라, 새 탭에는 앞 탭의 열쇠를 `getCredentials` → `addCredential` 로 옮긴다(같은 휴대폰 흉내).
# 휴대폰 흉내: 412·360 폭 · has_touch · is_mobile · UA 는 **검토 표식으로 시작**하고 Android 를 담는다(`tbox-review/<날짜> (Linux; Android 16; SM-F966N) Mobile`).
# 폭·색: 로그인·내 계정 화면을 360 · 412 · 820(터치) · 1280(마우스) × 라이트 · 다크로 찍고(SHOTS) 문서 가로 넘침 0 · 콘솔 오류·pageerror 0 · 기본 창 0.
# 나가는 값: 마지막 두 줄 `SMOKE_PASS=<n>` · `SMOKE_FAIL=<n>` · 종료 코드 0/1 · WORK/va-samples.json(가상 인증기 표본 — PK-T1 교차)
import base64
import hashlib
import hmac
import json
import os
import re
import struct
import subprocess
import sys
import time
import urllib.parse

from playwright.sync_api import sync_playwright

SITE, UA, SHOTS, DB, WORK, SITE_ALT = [a.rstrip("/") for a in sys.argv[1:7]]
S_E1, S_E2, S_A1, S_G1, PW_E1, PW_E2, PW_A1, SC_E1, SC_E2, SC_A1 = [sys.stdin.readline().rstrip("\n") for _ in range(10)]
MOBILE_UA = UA + " (Linux; Android 16; SM-F966N) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Mobile Safari/537.36"
os.makedirs(SHOTS, exist_ok=True)
P = F = 0
dialogs = []
errors = []
samples = {"rp_id": "localhost", "origin": SITE, "register": [], "login": []}


def step(name, cond, detail=""):
    global P, F
    if cond:
        P += 1
    else:
        F += 1
    print("  %s %s%s" % ("✔" if cond else "✖", name, ("  — " + str(detail)[:400]) if (detail != "" and not cond) else ""), flush=True)


def note(t):
    print("  · " + t, flush=True)


def sub(t):
    print("\n── " + t, flush=True)


def sql(q):
    r = subprocess.run(["sudo", "-n", "-u", "postgres", "psql", "-X", "-q", "-At", "-d", DB], input=q + "\n",
                       capture_output=True, text=True, cwd="/")
    return r.stdout.strip()


def totp(secret):
    while 30 - (time.time() % 30) < 6:   # 창 0 — 경계 가까이면 다음 창까지(입력·전송 사이에 넘어가지 않게)
        time.sleep(0.5)
    key = base64.b32decode(secret + "=" * (-len(secret) % 8))
    mac = hmac.new(key, struct.pack(">Q", int(time.time() // 30)), hashlib.sha1).digest()
    o = mac[-1] & 0x0F
    return "%06d" % ((struct.unpack(">I", mac[o:o + 4])[0] & 0x7FFFFFFF) % 1000000)


def uid(name):
    return sql("SELECT id FROM users WHERE username = '%s'" % name)


# ─── 준비(임시 DB 만) ──────────────────────────────────────────────
sql("UPDATE passkeys SET revoked_at = now(), revoke_reason = 'self' WHERE revoked_at IS NULL")
sql("UPDATE users SET mfa_enabled = false, mfa_last_step = NULL, failed_count = 0, locked_until = NULL, must_change_password = false, is_active = true "
    "WHERE username = 'claude_employee02'")
sql("UPDATE users SET failed_count = 0, locked_until = NULL, mfa_last_step = NULL WHERE username LIKE 'claude\\_%'")
sql("DELETE FROM passkey_challenges")


# 크로미움 가상 인증기는 자동 완성(conditional mediation) 요청에도 사람 없이 곧바로 답한다 — 실제 휴대폰은 사람이 키보드 위
# 제안을 골라야 답한다. 그래서 시험 페이지에서는 conditional 호출을 **기록만** 하고 답하지 않게(중단되면 AbortError) 감싼다.
# 모달(ⓐ 단추 · ⓒ 곧바로) 호출은 그대로 가상 인증기로 간다. window.__pkCalls 에 호출마다 mediation 값이 쌓인다.
COND_HOLD = """(() => {
  try {
    const orig = navigator.credentials.get.bind(navigator.credentials);
    window.__pkCalls = [];
    navigator.credentials.get = function (opts) {
      const m = opts && opts.mediation ? opts.mediation : 'optional';
      window.__pkCalls.push(m);
      if (m === 'conditional') {
        return new Promise((resolve, reject) => {
          if (opts.signal) { opts.signal.addEventListener('abort', () => reject(new DOMException('aborted', 'AbortError'))); }
        });
      }
      return orig(opts);
    };
  } catch (e) { /* credentials 없는 문맥 */ }
})();"""


# 사람이 곧바로 뜬 지문 창을 **보고** 취소하는 흉내 — 가상 인증기(UV 거부)는 즉시 거절하는데, 앱은 0.3초 안에 끝난 거절을 「브라우저가 누름 없는
# 호출을 막은 것」으로 보고 세지 않는다(설계 — passkey.js modal). 그래서 모달 호출의 거절만 0.7초 뒤로 미룬다(흔들리지 않게 — 시각 경계 0).
CANCEL_SLOW = """(() => {
  try {
    const g = navigator.credentials.get.bind(navigator.credentials);
    navigator.credentials.get = function (opts) {
      if (opts && opts.mediation === 'conditional') { return g(opts); }
      const t0 = Date.now();
      return g(opts).then((v) => v, (e) => new Promise((_, rej) => setTimeout(() => rej(e), Math.max(0, 700 - (Date.now() - t0)))));
    };
  } catch (e) { /* credentials 없는 문맥 */ }
})();"""


def ctx_for(br, w, mobile=True, theme="light", h=900):
    c = br.new_context(user_agent=MOBILE_UA if mobile else UA, viewport={"width": w, "height": h}, device_scale_factor=1,
                       is_mobile=mobile, has_touch=mobile, color_scheme=theme)
    c.add_init_script(COND_HOLD)
    return c


def calls(page):
    return page.evaluate("() => (window.__pkCalls || []).slice()")


def add_session(ctx, token):
    ctx.add_cookies([{"name": "__Host-tbox_session", "value": token, "domain": "localhost", "path": "/",
                      "secure": True, "httpOnly": True, "sameSite": "Lax"}])


def watch(page, tag):
    page.on("dialog", lambda d: (dialogs.append((tag, d.type, d.message)), d.dismiss()))
    page.on("pageerror", lambda e: errors.append((tag, "pageerror", str(e))))
    page.on("console", lambda m: errors.append((tag, "console", m.text)) if m.type == "error" else None)


def authenticator(page, uv=True):
    cdp = page.context.new_cdp_session(page)
    cdp.send("WebAuthn.enable", {"enableUI": False})
    aid = cdp.send("WebAuthn.addVirtualAuthenticator", {"options": {
        "protocol": "ctap2", "transport": "internal", "hasResidentKey": True, "hasUserVerification": True,
        "isUserVerified": uv, "automaticPresenceSimulation": True}})["authenticatorId"]
    return cdp, aid


# 같은 휴대폰 흉내 — 탭마다 인증기가 따로라 열쇠를 옮긴다. ★ 가상 인증기는 서명할 때마다 **서명 횟수**를 올리므로, 처음 인증기(va)에서
# 매번 복사하면 뒤 탭은 옛 횟수로 서명해 서버가 `passkey_counter`(열쇠 복제 의심 · AL-23)로 거부한다(앱은 맞게 동작 — 2026-09-27 격리 재현).
# 그래서 서명에 성공한 탭은 닫기 전에 snap() 으로 열쇠(횟수 포함)를 저장하고, 새 탭은 그 **마지막 판**을 받는다.
LATEST = {"creds": []}


def snap(a):
    LATEST["creds"] = a[0].send("WebAuthn.getCredentials", {"authenticatorId": a[1]})["credentials"]


def copy_creds(_src, dst):
    dcdp, daid = dst
    for c in LATEST["creds"]:
        dcdp.send("WebAuthn.addCredential", {"authenticatorId": daid, "credential": c})


def capture(page):
    def on_req(r):
        try:
            if r.method == "POST" and r.url.endswith("/tbox/api/me/passkeys"):
                b = json.loads(r.post_data or "{}")
                samples["register"].append({"attestationObject": b["attestationObject"], "clientDataJSON": b["clientDataJSON"],
                                            "credential_id": b["rawId"]})
            if r.method == "POST" and r.url.endswith("/tbox/api/login/passkey"):
                b = json.loads(r.post_data or "{}")
                samples["login"].append({"rawId": b["rawId"], "authenticatorData": b["authenticatorData"],
                                         "clientDataJSON": b["clientDataJSON"], "signature": b["signature"]})
        except Exception as e:  # noqa: BLE001
            errors.append(("capture", "capture", str(e)))
    page.on("request", on_req)


def overflow(page):
    return page.evaluate("() => document.documentElement.scrollWidth - document.documentElement.clientWidth")


def shot(page, name):
    page.screenshot(path=os.path.join(SHOTS, name + ".png"), full_page=True)


def local(page, key):
    return page.evaluate("(k) => { try { return window.localStorage.getItem(k); } catch (e) { return 'ERR'; } }", key)


def passkey_reqs(page_log, path):
    return len([u for u in page_log if u.endswith(path)])


with sync_playwright() as p:
    br = p.chromium.launch(args=["--host-resolver-rules=MAP localhost 127.0.0.1"])

    # ═══ PK-T11 내 계정 — 등록 (412 터치 · claude_employee02 · 2단계 끔) ═══════════════
    sub("PK-T11 내 계정 — 휴대폰 흉내(412 터치)에서 「이 휴대폰 등록하기」")
    c1 = ctx_for(br, 412)
    add_session(c1, S_E2)
    pg = c1.new_page()
    watch(pg, "reg")
    capture(pg)
    va = authenticator(pg)
    reqlog = []
    pg.on("request", lambda r: reqlog.append(r.url))
    pg.goto(SITE + "/tbox/settings")
    pg.wait_for_selector("#passkey-panel:not([hidden])", timeout=10000)
    pg.wait_for_selector("#passkey-register-open:not([hidden])", timeout=10000)
    step("패널 보임 · 「이 휴대폰 등록하기」 보임 · PC 안내 숨김 · 빈 목록 문장", pg.is_visible("#passkey-register-open")
         and not pg.is_visible("#passkey-pc-note") and pg.is_visible("#passkey-empty"))
    shot(pg, "settings-412-light-empty")
    pg.click("#passkey-register-open")
    pg.wait_for_selector("#passkey-register-dialog[open]")
    step("등록 창 — 기기 이름 기본값(Android …) · 2단계 칸 숨김(끈 계정) · 바닥 = 알림 · 닫기(테두리) · 등록하기(검정 오른쪽 끝)",
         pg.input_value("#passkey-name").startswith("Android") and not pg.is_visible("#passkey-otp-field")
         and pg.evaluate("""() => { const f = document.querySelector('#passkey-register-dialog .modal__foot');
             const b = Array.from(f.querySelectorAll('button')); return b.length === 2 && b[0].textContent.trim() === '닫기'
             && b[0].className.includes('btn--quiet') && b[1].textContent.trim() === '등록하기' && b[1].className === 'btn'; }"""),
         pg.input_value("#passkey-name"))
    shot(pg, "settings-412-light-dialog")
    pg.fill("#passkey-name", "내 폴드")
    pg.fill("#passkey-password", PW_E2)
    pg.click("#passkey-register")
    pg.wait_for_selector("#passkey-ok:not([hidden])", timeout=15000)
    rows = pg.eval_on_selector_all("#passkey-body tr", "rs => rs.map(r => Array.from(r.cells).map(c => c.textContent.trim()))")
    step("등록 → 창 닫힘 · 결과 한 줄 · 목록 한 줄(내 폴드 · 쓰는 중 · 아직 안 씀)", not pg.is_visible("#passkey-register-dialog")
         and len(rows) == 1 and rows[0][0] == "내 폴드" and rows[0][4] == "쓰는 중" and rows[0][3] == "아직 안 씀", rows)
    step("「이 기기 표시」 localStorage = 1(계정 정보 없음 — 값은 1 하나)", local(pg, "qaredi.passkey-here") == "1")
    step("비밀번호 칸이 비었다(보낸 뒤)", pg.input_value("#passkey-password") == "")
    creds = va[0].send("WebAuthn.getCredentials", {"authenticatorId": va[1]})["credentials"]
    step("가상 인증기에 이 사이트 열쇠 하나(resident · rpId localhost)", len(creds) == 1 and creds[0]["isResidentCredential"] and creds[0]["rpId"] == "localhost", creds and creds[0].get("rpId"))
    e2_cid = base64.b64decode(creds[0]["credentialId"]) if creds else b""
    db_row = sql("SELECT octet_length(public_key) || '|' || backup_eligible || '|' || name || '|' || device_hint FROM passkeys WHERE credential_id = decode('%s','hex')" % e2_cid.hex())
    step("DB 한 줄 — SPKI 91 · 이름 · 기기 요약(UA 에서 고른 고정 낱말)", db_row.startswith("91|") and "|내 폴드|Android" in db_row, db_row)
    shot(pg, "settings-412-light-list")
    snap(va)
    # 해제 확인 창(열기만 — 「닫기」)
    pg.click("#passkey-body button")
    pg.wait_for_selector("#confirm-dialog[open]")
    step("해제 → 공용 확인 창(「해제하기」 빨강 · 처음 초점 닫기) · 기본 창 0",
         pg.evaluate("() => { const d = document.getElementById('confirm-dialog'); const ok = d.querySelector('.btn--danger');"
                     " return ok && ok.textContent === '해제하기' && document.activeElement && document.activeElement.textContent === '닫기'; }"))
    shot(pg, "settings-412-light-confirm")
    pg.keyboard.press("Escape")
    step("Esc — 요청 0 · 열쇠 그대로", sql("SELECT revoked_at IS NULL FROM passkeys WHERE credential_id = decode('%s','hex')" % e2_cid.hex()) == "t")

    # ═══ PK-T10 로그인 — 로그아웃 직후 · 단추 · 원래 자리 ═══════════════════════════
    sub("PK-T10 로그인 — 로그아웃 직후에는 곧바로 뜨는 창 없음 → 단추 → 보던 곳(/tbox/settings)")
    reqlog.clear()
    pg.evaluate("() => document.getElementById('logout').click()")
    pg.wait_for_url(re.compile(r".*/tbox/login.*"), timeout=10000)
    pg.wait_for_selector("#passkey-login-btn:not([hidden])", timeout=10000)
    time.sleep(1.0)
    step("로그아웃 뒤 로그인 화면 주소 = /tbox/login?to=%2Ftbox%2Fsettings (B1-1)", pg.url.endswith("/tbox/login?to=%2Ftbox%2Fsettings"), pg.url)
    step("로그아웃 직후 — 곧바로 뜨는 창 없음(모달 호출 0 · 로그인 창구 요청 0) · 자동 완성만 걸림(conditional 1)",
         passkey_reqs(reqlog, "/tbox/api/login/passkey") == 0 and calls(pg) == ["conditional"], calls(pg))
    step("표시 있음 → 검정 「지문·얼굴로 로그인」 + 「비밀번호로 로그인」 · 아이디·비밀번호 칸 접힘",
         pg.get_attribute("#passkey-login-btn", "class") == "btn btn--block" and pg.is_visible("#passkey-password-open")
         and not pg.is_visible("#username"), pg.get_attribute("#passkey-login-btn", "class"))
    step("아이디 칸 autocomplete=「username webauthn」 · 비밀번호 칸 「current-password」",
         pg.get_attribute("#username", "autocomplete") == "username webauthn" and pg.get_attribute("#password", "autocomplete") == "current-password")
    shot(pg, "login-412-light-marker")
    pg.click("#passkey-login-btn")
    pg.wait_for_url(re.compile(r".*/tbox/settings$"), timeout=15000)
    step("지문 → 보던 곳(/tbox/settings)으로", pg.url.endswith("/tbox/settings"), pg.url)
    step("로그인 창구 요청 1 · 성공 뒤 표시 그대로", passkey_reqs(reqlog, "/tbox/api/login/passkey") == 1 and local(pg, "qaredi.passkey-here") == "1")
    snap(va)
    step("감사 auth.login allow passkey(e2)", sql("SELECT count(*) FROM audit_logs WHERE action='auth.login' AND reason='passkey' AND target_name='claude_employee02' AND happened_at > now() - interval '2 minutes'") != "0")

    # ═══ PK-T13 누름 수 — 새 탭(표시 있음) · 로그아웃 상태 · 시작 주소 /community/ ═══════
    sub("PK-T13 목표 지표 — 홈 화면 → 지문 → 업무 홈 → 내 문서 → 새 페이지")
    c1.clear_cookies()
    pg2 = c1.new_page()
    watch(pg2, "taps")
    capture(pg2)
    va2 = authenticator(pg2)
    copy_creds(va, va2)
    taps = {"n": 0, "chars": 0}
    log2 = []
    pg2.on("request", lambda r: log2.append(r.url))
    taps["n"] += 1   # 홈 화면 아이콘(= 시작 주소를 연다)
    pg2.goto(SITE + "/community/")
    pg2.wait_for_selector("#passkey-login-btn:not([hidden])")
    step("로그인 방법 선택 전 모달 호출 없음", pg2.evaluate("window.__pkCalls.every(x => x === 'conditional')"))
    taps["n"] += 1
    pg2.click("#passkey-login-btn")
    pg2.wait_for_url(re.compile(r".*/community/$"), timeout=20000)
    # Q:F-12 D — `/community/` 는 업무 홈(옛 둘러보기 #community-overview · #overview-workspace 는 없어졌다). 「내 문서」는 머리의 링크 하나(누름 수 같음)
    pg2.wait_for_selector("#work-home", timeout=10000)
    step("패스키 선택 → 업무 홈", pg2.url.endswith("/community/") and taps["n"] == 2, pg2.url)
    taps["n"] += 1
    pg2.click("#work-home-docs")
    pg2.wait_for_url(re.compile(r".*/community/workspace$"), timeout=10000)
    pg2.wait_for_selector("#community-new:not([hidden])", timeout=10000)
    step("업무 홈에서 「내 문서」 열기", pg2.url.endswith("/community/workspace") and taps["n"] == 3, pg2.url)
    step("  로그인 창구 요청 1 · 사용자가 로그인 방법을 선택", passkey_reqs(log2, "/tbox/api/login/passkey") == 1)
    taps["n"] += 1
    pg2.click("#community-new")
    pg2.wait_for_url(re.compile(r".*/community/p/[0-9a-f-]{36}(\?edit=1)?$"), timeout=15000)
    pg2.wait_for_selector("[contenteditable='true']", timeout=15000)
    step("「새 페이지」 → 곧바로 편집 모드(쓸 수 있는 본문 · 주소의 ?edit=1 은 지움)", pg2.is_visible("[contenteditable='true']") and "?edit" not in pg2.url, pg2.url)
    print("  · PK-T13 누름 %d · 지문 1 · 글자 %d (업무 홈에서 내 문서 선택 포함)" % (taps["n"], taps["chars"]), flush=True)
    step("PK-T13 업무 홈 포함 누름 ≤ 4 · 글자 0", taps["n"] <= 4 and taps["chars"] == 0, taps)
    new_page_id = pg2.url.rsplit("/", 1)[1].split("?")[0]
    snap(va2)
    pg2.close()

    # ═══ PK-T12 원래 자리 — 로그아웃 상태로 편집 주소 → 지문 → 그 페이지 편집 모드 ══════
    sub("PK-T12 원래 자리 — 로그아웃 상태로 /community/p/<uuid>?edit=1 → 지문 → 그 페이지 편집 모드")
    c1.clear_cookies()
    pg3 = c1.new_page()
    watch(pg3, "edit-return")
    capture(pg3)
    va3 = authenticator(pg3)
    copy_creds(va, va3)
    pg3.goto(SITE + "/community/p/%s?edit=1" % new_page_id)
    pg3.click("#passkey-login-btn")
    pg3.wait_for_url(re.compile(r".*/community/p/%s(\?edit=1)?$" % new_page_id), timeout=20000)
    pg3.wait_for_selector("[contenteditable='true']", timeout=15000)
    snap(va3)
    step("로그인 뒤 그 페이지로 · 편집 모드", ("/community/p/" + new_page_id) in pg3.url and pg3.is_visible("[contenteditable='true']"), pg3.url)
    # 편집 중 세션이 끊기면(401) — editor.js toLogin 이 ?edit=1 을 싣는다
    c1.clear_cookies()
    tag = pg3.evaluate("() => { const f = window.qarediReturnPath; const e = window.qarediRawEncode; return f && e ? e(f(location.pathname + '?edit=1')) : null; }")
    step("편집 중 로그인 꼬리표 = /community/p/<uuid>?edit=1 의 정규형(rawurlencode)", tag == urllib.parse.quote("/community/p/%s?edit=1" % new_page_id, safe=""), tag)
    pg3.close()

    # ═══ 벡터 — 화면 함수가 서버와 같은 답 ══════════════════════════════════════
    sub("B1-1 벡터 — 화면 returnPath(app.js) 결과가 tests/lib/return_path_vectors.txt 와 같다(서버는 창구 몸통이 봤다)")
    pv = c1.new_page()
    watch(pv, "vectors")
    pv.add_init_script("try { sessionStorage.setItem('qaredi.just-logged-out','1'); } catch (e) {}")
    pv.goto(SITE + "/tbox/login")
    vec = []
    for line in open(os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "lib", "return_path_vectors.txt"), encoding="utf-8").read().split("\n"):
        if line and not line.startswith("#"):
            a, b = line.split("\t")
            vec.append((a, b))
    got = pv.evaluate("(v) => v.map(([a]) => { const r = window.qarediReturnPath(a); return r === null ? '-' : r; })", vec)
    bad = [(a[:60], b[:60], g[:60]) for (a, b), g in zip(vec, got) if b != g]
    step("화면 함수 %d줄 — 서버와 같은 답(열린 되돌리기 벡터 전부 null)" % len(vec), bad == [], bad[:5])
    # data-to 를 개발자도구로 고친 뒤 지문 로그인 → 첫 화면
    va_v = authenticator(pv)
    copy_creds(va, va_v)
    pv.goto(SITE + "/tbox/login?to=%2Ftbox%2Fsettings")
    pv.wait_for_selector("#passkey-login-btn:not([hidden])")
    pv.evaluate("() => document.body.setAttribute('data-to', '//evil.example')")
    pv.click("#passkey-login-btn")
    pv.wait_for_url(re.compile(r"^%s/tbox/$" % re.escape(SITE)), timeout=15000)
    step("data-to 를 //evil.example 로 고친 뒤 지문 로그인 → 첫 화면(/tbox/)", pv.url == SITE + "/tbox/", pv.url)
    snap(va_v)
    pv.close()

    # ═══ 취소 두 번 → 표시 꺼짐 · 꺼진 열쇠 → 표시 꺼짐 ═══════════════════════════════
    sub("PK-T10 — 곧바로 뜬 창을 연달아 두 번 취소하면 표시가 꺼진다 · 「꺼진 열쇠」 답이면 표시가 꺼진다")
    c1.clear_cookies()
    for i in (1, 2):
        pc = c1.new_page()
        watch(pc, "cancel%d" % i)
        pc.add_init_script(CANCEL_SLOW)
        vac = authenticator(pc, uv=False)
        copy_creds(va, vac)
        pc.goto(SITE + "/tbox/login")
        pc.wait_for_selector("#passkey-login-btn:not([hidden])")
        pc.click("#passkey-login-btn")
        pc.wait_for_selector("#passkey-recovery:not([hidden])")
        pc.wait_for_function("() => !document.getElementById('passkey-login-btn').disabled", timeout=5000)
        step("취소 %d — 로그인 화면에 남음 · 단추 보임 · 비밀번호 길 그대로" % i, "/tbox/login" in pc.url and pc.is_visible("#passkey-login-btn"), pc.url)
        step("취소만으로 기존 기기 표시를 지우지 않음", local(pc, "qaredi.passkey-here") == "1")
        pc.close()
    # 표시 없음 → 테두리 단추 + 칸 그대로
    pn = c1.new_page()
    watch(pn, "nomarker")
    authenticator(pn)   # 휴대폰에는 기기 인증기가 늘 있다(이 사이트 열쇠만 없다) — 없으면 단추 자체를 숨긴다(PC 판정)
    pn.goto(SITE + "/tbox/login")
    pn.evaluate("localStorage.removeItem('qaredi.passkey-here')")
    pn.reload()
    pn.wait_for_selector("#passkey-login-btn:not([hidden])")
    step("표시 없음 → 「지문·얼굴로 로그인」 테두리(.btn--quiet) · 아이디·비밀번호 칸 · 검정 「로그인」",
         "btn--quiet" in (pn.get_attribute("#passkey-login-btn", "class") or "") and pn.is_visible("#username") and pn.is_visible("#login-submit"))
    shot(pn, "login-412-light-nomarker")
    pn.close()
    # 꺼진 열쇠
    pr = c1.new_page()
    watch(pr, "revoked")
    var = authenticator(pr)
    copy_creds(va, var)
    pr.add_init_script("try { localStorage.setItem('qaredi.passkey-here','1'); } catch (e) {}")
    sql("UPDATE passkeys SET revoked_at = now(), revoke_reason = 'self' WHERE credential_id = decode('%s','hex')" % e2_cid.hex())
    pr.goto(SITE + "/tbox/login")
    pr.click("#passkey-login-btn")
    pr.wait_for_function("() => (document.getElementById('msg') || {}).textContent && document.getElementById('msg').textContent.includes('꺼져 있습니다')", timeout=15000)
    step("「꺼진 열쇠」 → 문장 · 비밀번호 칸 펼침 · 표시 꺼짐", pr.is_visible("#username")
         and pr.evaluate("() => localStorage.getItem('qaredi.passkey-here')") is None)
    pr.close()
    c1.close()

    # ═══ 다른 origin(같은 DB · APP_URL 은 SITE 인 다른 포트) ══════════════════════════
    sub("다른 origin — %s 에서 만든 등록 응답은 거부(passkey_invalid)" % SITE_ALT)
    ca = ctx_for(br, 412)
    ca.add_cookies([{"name": "__Host-tbox_session", "value": S_E1, "domain": "localhost", "path": "/", "secure": True, "httpOnly": True, "sameSite": "Lax"}])
    pa = ca.new_page()
    watch(pa, "alt")
    authenticator(pa)
    a0 = sql("SELECT coalesce(max(id),0) FROM audit_logs")
    sql("UPDATE users SET mfa_last_step = NULL WHERE username = 'claude_employee01'")
    pa.goto(SITE_ALT + "/tbox/settings")
    pa.wait_for_selector("#passkey-register-open:not([hidden])", timeout=10000)
    pa.click("#passkey-register-open")
    pa.fill("#passkey-password", PW_E1)
    if pa.is_visible("#passkey-otp-field"):
        pa.fill("#passkey-otp", totp(SC_E1))
    pa.click("#passkey-register")
    pa.wait_for_function("() => document.getElementById('passkey-register-msg').textContent !== ''", timeout=15000)
    step("다른 origin 등록 → 창 안 거부 문장 · 감사 passkey_register deny passkey_invalid",
         sql("SELECT count(*) FROM audit_logs WHERE id > %s AND action='auth.passkey_register' AND reason='passkey_invalid'" % a0) == "1",
         pa.text_content("#passkey-register-msg"))
    ca.close()

    # ═══ PC(1280 마우스) — 단추·자동 완성 0 ════════════════════════════════════════
    sub("PK-D1 — PC(1280 · 마우스 · 데스크톱 UA): 지문 단추·자동 완성 0 · 내 계정은 안내 한 줄")
    cd = ctx_for(br, 1280, mobile=False)
    pd = cd.new_page()
    watch(pd, "pc")
    authenticator(pd)
    logd = []
    pd.on("request", lambda r: logd.append(r.url))
    pd.goto(SITE + "/tbox/login")
    time.sleep(1.5)
    step("PC 플랫폼 인증기 지원 시 패스키 선택 표시", pd.is_visible("#passkey-login-btn")
         and pd.evaluate("window.__pkCalls.every(x => x === 'conditional')"))
    add_session(cd, S_E1)
    pd.goto(SITE + "/tbox/settings")
    pd.wait_for_selector("#passkey-panel:not([hidden])")
    time.sleep(0.8)
    step("PC 내 계정 — 지원 기기는 패스키 등록 가능", pd.is_visible("#passkey-register-open") and not pd.is_visible("#passkey-pc-note"))
    cd.close()

    # ═══ 라운드 C — 재인증을 지문으로 · 권장 카드 · 좁은 폭 즐겨찾기 · 매니페스트 ══════════
    sub("라운드 C — 관리자(claude_admin01) 휴대폰: 등록 → /tbox/reauth?to=admin 「지문·얼굴로 확인」 → /tbox/admin")
    cr = ctx_for(br, 412)
    add_session(cr, S_A1)
    prc = cr.new_page()
    watch(prc, "reauth")
    capture(prc)
    authenticator(prc)
    prc.goto(SITE + "/tbox/settings")
    prc.wait_for_selector("#passkey-register-open:not([hidden])", timeout=10000)
    prc.click("#passkey-register-open")
    prc.fill("#passkey-password", PW_A1)
    sql("UPDATE users SET mfa_last_step = NULL WHERE username = 'claude_admin01'")
    prc.fill("#passkey-otp", totp(SC_A1))
    prc.click("#passkey-register")
    prc.wait_for_selector("#passkey-ok:not([hidden])", timeout=15000)
    step("관리자 등록(비밀번호 + 2단계 코드) → 목록 한 줄", len(prc.query_selector_all("#passkey-body tr")) == 1)
    prc.goto(SITE + "/tbox/reauth?to=admin")
    prc.wait_for_selector("#passkey-reauth-btn", timeout=10000)
    step("재인증 화면 — 「지문·얼굴로 확인」이 비밀번호 칸 위", prc.evaluate(
        "() => { const b = document.getElementById('passkey-reauth-btn'); const pw = document.getElementById('reauth-password');"
        " return !!b && !!pw && (b.compareDocumentPosition(pw) & Node.DOCUMENT_POSITION_FOLLOWING) !== 0; }"))
    shot(prc, "reauth-412-light")
    prc.click("#passkey-reauth-btn")
    prc.wait_for_url(re.compile(r".*/tbox/admin$"), timeout=15000)
    step("지문으로 확인 → /tbox/admin(15분 창)", prc.url.endswith("/tbox/admin"), prc.url)
    # 재인증 창(모달) — 창이 닫혀 있으면 관리 링크를 눌렀을 때 뜨는 창에도 단추
    sql("UPDATE sessions SET reauth_at = NULL WHERE user_id = %s" % uid("claude_admin01"))
    prc.goto(SITE + "/tbox/")
    prc.wait_for_load_state("networkidle")
    has_modal_btn = prc.evaluate("""() => new Promise((res) => {
        const a = Array.from(document.querySelectorAll('a.nav__item[href], a.card--link[href]')).find(x => /\\/admin$/.test(x.getAttribute('href') || ''));
        if (!a) { res('no-link'); return; }
        a.click();
        setTimeout(() => { const d = document.querySelector('dialog.modal--form[open]'); res(d && d.querySelector('#passkey-reauth-btn') ? 'yes' : 'no'); }, 1500);
    })""")
    step("관리 링크 → 재인증 창(모달)에도 「지문·얼굴로 확인」", has_modal_btn == "yes", has_modal_btn)
    cr.close()

    sub("권장 카드 — 휴대폰 · 열쇠 0 · 표시 없음 → 첫 화면에 「다음부터 지문·얼굴로 로그인하기」 · 「나중에」 → 이 세션 동안 숨김")
    ck = ctx_for(br, 412)
    add_session(ck, S_G1)
    sql("UPDATE passkeys SET revoked_at = now(), revoke_reason = 'self' WHERE user_id = %s AND revoked_at IS NULL" % uid("claude_guest01"))
    pk_ = ck.new_page()
    watch(pk_, "card")
    authenticator(pk_)
    pk_.goto(SITE + "/community/workspace")
    pk_.wait_for_selector("#passkey-recommend", timeout=10000)
    step("커뮤니티 첫 화면에 권장 카드", pk_.is_visible("#passkey-recommend"))
    shot(pk_, "card-412-light")
    pk_.click("#passkey-recommend button")
    step("「나중에」 → 카드 사라짐", pk_.query_selector("#passkey-recommend") is None)
    pk_.reload()
    time.sleep(1.2)
    step("새로 고쳐도 이 세션 동안 숨김", pk_.query_selector("#passkey-recommend") is None)
    ck.close()

    # ═══ B1-1 ① — 비밀번호 길도 같은 함수로 보던 곳에(2단계 켠 계정 포함) · 뒤 절이 쓸 e2 새 세션(S_E2 는 PK-T10 로그아웃으로 끊겼다) ═══
    sub("B1-1 ① 비밀번호 길 — 로그아웃 상태로 편집 주소 → 비밀번호 로그인 → 그 페이지 · 2단계 켠 관리자 → 비밀번호+코드 → 보던 곳")
    cpw = ctx_for(br, 412)
    ppw = cpw.new_page()
    watch(ppw, "pw-return")
    ppw.goto(SITE + "/community/p/%s?edit=1" % new_page_id)
    ppw.wait_for_selector("#username", state="visible", timeout=10000)
    sql("UPDATE users SET failed_count = 0, locked_until = NULL WHERE username = 'claude_employee02'")
    ppw.fill("#username", "claude_employee02")
    ppw.fill("#password", PW_E2)
    ppw.click("#login-submit")
    ppw.wait_for_url(re.compile(r".*/community/p/%s(\?edit=1)?$" % new_page_id), timeout=15000)
    ppw.wait_for_selector("[contenteditable='true']", timeout=15000)
    step("비밀번호 로그인(표시 없음 · 2단계 끔) → 그 페이지 편집 모드", ("/community/p/" + new_page_id) in ppw.url and ppw.is_visible("[contenteditable='true']"), ppw.url)
    S_E2 = next((c["value"] for c in cpw.cookies() if c["name"] == "__Host-tbox_session"), "")
    step("  새 세션 쿠키(뒤 절에 쓴다)", re.match("^[0-9a-f]{64}$", S_E2 or "") is not None)
    cpw.close()
    cpa = ctx_for(br, 412)
    ppa = cpa.new_page()
    watch(ppa, "pw-return-mfa")
    ppa.goto(SITE + "/tbox/settings")
    ppa.wait_for_selector("#username", state="visible", timeout=10000)
    step("미로그인 /tbox/settings → 로그인 화면 꼬리표 ?to=%2Ftbox%2Fsettings", ppa.url.endswith("/tbox/login?to=%2Ftbox%2Fsettings"), ppa.url)
    sql("UPDATE users SET failed_count = 0, locked_until = NULL, mfa_last_step = NULL WHERE username = 'claude_admin01'")
    ppa.fill("#username", "claude_admin01")
    ppa.fill("#password", PW_A1)
    ppa.click("#login-submit")
    ppa.wait_for_selector("#otp", state="visible", timeout=10000)
    ppa.fill("#otp", totp(SC_A1))
    # 사용감 라운드 1 UX-9 — 여섯 자리를 넣으면 로그인 화면이 스스로 제출한다(옛 판은 단추를 누른다)
    try:
        ppa.wait_for_url(re.compile(r".*/tbox/settings$"), timeout=4000)
    except Exception:
        ppa.click("#login-submit")
    ppa.wait_for_url(re.compile(r".*/tbox/settings$"), timeout=15000)
    step("2단계 켠 관리자 — 비밀번호 → 코드 → 보던 곳(/tbox/settings)", ppa.url.endswith("/tbox/settings"), ppa.url)
    cpa.close()

    sub("좁은 폭 「내 공간」 첫 화면 맨 위 즐겨찾기(PK-D5 · d71)")
    cf = ctx_for(br, 412)
    add_session(cf, S_E2)
    pf = cf.new_page()
    watch(pf, "fav")
    csrf = pf.request.get(SITE + "/tbox/api/me").json().get("csrf_token", "")
    r = pf.request.put(SITE + "/community/api/pages/%s/favorite" % new_page_id, headers={"X-CSRF-Token": csrf})
    step("(준비) 새 페이지를 즐겨찾기에(PUT 200·201·204)", r.status in (200, 201, 204), r.status)
    pf.goto(SITE + "/community/workspace")
    pf.wait_for_selector("#community-home-fav:not([hidden])", timeout=10000)
    step("412 — 첫 화면 맨 위 「즐겨찾기」 패널에 그 페이지 링크", pf.evaluate(
        "(id) => !!document.querySelector('#community-home-fav a[href$=\"/community/p/' + id + '\"]')", new_page_id))
    step("  패널이 화면 머리 바로 아래(첫 목록보다 위)", pf.evaluate(
        "() => { const f = document.getElementById('community-home-fav'); const t = document.getElementById('community-tree') || document.getElementById('community-list');"
        " return !t || (f.compareDocumentPosition(t) & Node.DOCUMENT_POSITION_FOLLOWING) !== 0; }"))
    shot(pf, "community-412-light-fav")
    pf.set_viewport_size({"width": 1280, "height": 900})
    time.sleep(0.6)
    step("1280 으로 넓히면 숨김(사이드바가 늘 보인다)", not pf.is_visible("#community-home-fav"))
    cf.close()

    sub("홈 화면 앱 매니페스트")
    cm = ctx_for(br, 412)
    pm = cm.new_page()
    r = pm.request.get(SITE + "/tbox/app.webmanifest")
    m = r.json() if r.status == 200 else {}
    step("GET /tbox/app.webmanifest 200 · 이름 모아 · 시작 /community/ · 범위 / · standalone · 아이콘 둘 · 바로가기 없음",
         r.status == 200 and m.get("name") == "모아" and m.get("short_name") == "모아" and m.get("start_url") == "/community/" and m.get("scope") == "/"
         and m.get("display") == "standalone" and len(m.get("icons", [])) == 2 and "shortcuts" not in m, m)
    ic = pm.request.get(SITE + m["icons"][0]["src"]) if m.get("icons") else None
    step("아이콘 PNG 200", ic is not None and ic.status == 200 and ic.headers.get("content-type", "").startswith("image/png"))
    pm.goto(SITE + "/tbox/login")
    step("로그인 화면에 <link rel=manifest href=/tbox/app.webmanifest>", pm.evaluate("() => { const l = document.querySelector('link[rel=manifest]'); return l ? l.getAttribute('href') : null; }") == "/tbox/app.webmanifest")
    pm.goto(SITE + "/")
    step("런처(로그인 전)에도 manifest", pm.evaluate("() => !!document.querySelector('link[rel=manifest][href=\"/tbox/app.webmanifest\"]')"))
    cm.close()

    # ═══ 표본 셋째 — claude_employee01 새 휴대폰(새 인증기)으로 등록(가상 인증기 표본 · 2단계 켠 계정의 창) ═══
    sub("가상 인증기 표본 셋째 — claude_employee01(2단계 켬) 등록")
    ce = ctx_for(br, 412)
    add_session(ce, S_E1)
    pe = ce.new_page()
    watch(pe, "e1reg")
    capture(pe)
    authenticator(pe)
    sql("UPDATE passkeys SET revoked_at = now(), revoke_reason = 'self' WHERE user_id = %s AND revoked_at IS NULL" % uid("claude_employee01"))
    pe.goto(SITE + "/tbox/settings")
    pe.wait_for_selector("#passkey-register-open:not([hidden])", timeout=10000)
    pe.click("#passkey-register-open")
    step("2단계 켠 계정 — 등록 창에 코드 칸", pe.is_visible("#passkey-otp-field"))
    pe.fill("#passkey-password", PW_E1)
    sql("UPDATE users SET mfa_last_step = NULL WHERE username = 'claude_employee01'")
    pe.fill("#passkey-otp", totp(SC_E1))
    pe.click("#passkey-register")
    pe.wait_for_selector("#passkey-ok:not([hidden])", timeout=15000)
    step("e1 등록(비밀번호 + 코드) → 목록 한 줄", len(pe.query_selector_all("#passkey-body tr")) == 1)
    ce.close()

    # ═══ 폭·색 — 로그인 · 내 계정 × 360 · 412 · 1280 × 라이트 · 다크 ═══════════════════
    sub("화면 — 로그인 · 내 계정 × 360 · 412 · 820(터치) · 1280(마우스) × 라이트 · 다크 — 넘침 0 · 스크린샷")
    sql("UPDATE passkeys SET revoked_at = NULL, revoke_reason = NULL, revoked_by = NULL WHERE credential_id = decode('%s','hex')" % e2_cid.hex())
    for w in (360, 412, 820, 1280):
        for theme in ("light", "dark"):
            mob = w != 1280
            cx = ctx_for(br, w, mobile=mob, theme=theme)
            px = cx.new_page()
            watch(px, "grid-%d-%s" % (w, theme))
            authenticator(px)
            px.add_init_script("try { sessionStorage.setItem('qaredi.just-logged-out','1'); } catch (e) {}")
            px.goto(SITE + "/tbox/login")
            time.sleep(0.8)
            ov = overflow(px)
            shot(px, "login-%d-%s" % (w, theme))
            add_session(cx, S_E2)
            px.goto(SITE + "/tbox/settings")
            px.wait_for_selector("#passkey-panel:not([hidden])", timeout=10000)
            time.sleep(0.8)
            ov2 = overflow(px)
            shot(px, "settings-%d-%s" % (w, theme))
            btn_ok = True
            if mob and px.query_selector("#passkey-body tr td.actions button"):
                btn_ok = px.evaluate("(w) => { const b = document.querySelector('#passkey-body tr td.actions button').getBoundingClientRect(); return b.right <= w && b.left >= 0; }", w)
            step("%d %s — 로그인·내 계정 가로 넘침 0 · 해제 단추 화면 안" % (w, theme), ov <= 0 and ov2 <= 0 and btn_ok, (ov, ov2, btn_ok))
            if w == 360 and theme == "dark" and px.is_visible("#passkey-register-open"):
                px.click("#passkey-register-open")
                px.wait_for_selector("#passkey-register-dialog[open]")
                inside = px.evaluate("(w) => Array.from(document.querySelectorAll('#passkey-register-dialog .modal__foot button')).every(b => { const r = b.getBoundingClientRect(); return r.right <= w && r.left >= 0; })", w)
                step("360 다크 — 등록 창 바닥 단추 화면 안", inside)
                shot(px, "settings-360-dark-dialog")
            cx.close()

    br.close()

open(os.path.join(WORK, "va-samples.json"), "w").write(json.dumps({
    "rp_id": "localhost", "origin": SITE,
    "register": samples["register"],
    "login": [dict(l, spki_der_hex=sql("SELECT encode(public_key,'hex') FROM passkeys WHERE credential_id = decode('%s','hex')" % base64.urlsafe_b64decode(l["rawId"] + "=" * (-len(l["rawId"]) % 4)).hex()))
              for l in samples["login"]]}))
note("가상 인증기 표본 — 등록 %d · 로그인 %d → WORK/va-samples.json" % (len(samples["register"]), len(samples["login"])))
step("브라우저 기본 창(alert·prompt·confirm) 0", dialogs == [], dialogs[:5])
NEG_TAGS = {"revoked", "alt", "cancel1", "cancel2", "pw-return-mfa"}   # 일부러 거부를 받는 흐름 — 브라우저가 4xx 를 콘솔에 적는다(우리 코드의 오류가 아니다) · 2단계 로그인의 첫 401 mfa_required 는 설계(ARCH 4.0)
errs = [e for e in errors if "favicon" not in e[2]
        and not (e[0] in NEG_TAGS and e[1] == "console" and re.search(r"status of 4\d\d", e[2]))]
step("콘솔 오류·pageerror 0", errs == [], errs[:6])
print("SMOKE_PASS=%d" % P)
print("SMOKE_FAIL=%d" % F)
sys.exit(0 if F == 0 else 1)
