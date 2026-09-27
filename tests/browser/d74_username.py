#!/usr/bin/env python3
# tests/browser/d74_username.py — t-box d74 「로그인 아이디 바꾸기」 화면을 진짜 브라우저로 눌러 본다
#
# 부르는 쪽: tests/d74_username.sh (임시 인스턴스 · 임시 DB `tbox_d74_*`. 운영 80·8082·8081 에는 요청하지 않는다).
#   공용 잠금(flock …/browser.lock) 아래에서 한 번에 하나만 돈다 — 부르는 셸이 잠금을 쥔다.
# 쓰는 법: printf '<관리자 세션>\n<관리자 비밀번호>\n' | python3 d74_username.py <SITE> <UA> <SHOTS> <저장소 뿌리>
#   세션·비밀번호는 **표준입력 두 줄**(R-62 — 명령 인자에 비밀 0). 관리자는 재인증을 마친 세션이어야 한다(15분 창).
# 폭·색: 360 · 412(터치 흉내) · 1280 × 라이트 · 다크 = 6 조합(D74_COMBOS 로 줄임). 조합마다 찍고(SHOTS — 사람이 직접 연다) 잰다.
# 보는 것
#   공통   가로 넘침 0 · 콘솔 오류·pageerror 0 · 브라우저 기본 창(alert·prompt·confirm) 0건
#   창     「관리」 상세 창의 동작 여섯(바꾸기·아이디·임시 비밀번호·2단계·프로그램 토큰·활성) · 본인 줄은 아이디 카드 숨김 ·
#          「로그인 아이디 바꾸기」 → 상세 창을 닫고 아이디 창 하나만(겹쳐 열지 않음) · 창 단추 규칙(바닥 오른쪽 끝 주요 하나 · 그 왼쪽 「닫기」 ·
#          머리 ✕ — dbg_u2_ui.py 의 MODAL_RULE 한 벌) · 낱말 표(같은 WORDS) · 폰 폭 터치 44px(같은 TOUCH) · 입력칸 속성(자동 완성·대문자 끔)
#   흐름   빈 칸 → 창 안 안내(요청 0) · 이미 있는 아이디 → 서버 문구가 **창 안**에 · 창은 열린 채 · Esc → 「취소했습니다」 + 입력 값 비움 ·
#          (1280 라이트 한 번) 바꾸기 → 창 닫힘 · 알림 줄 · 목록·상세가 새 이름 · 알림 줄에 새 이름 없음
# 나가는 값: 마지막 줄 `SMOKE_FAIL=<n>` · 종료 코드 0/1
import ast
import json
import os
import re
import sys
import urllib.error
import urllib.request
import uuid

from playwright.sync_api import sync_playwright

SITE = sys.argv[1].rstrip("/")
UA = sys.argv[2]
SHOTS = sys.argv[3]
ROOT = sys.argv[4]
S_A = sys.stdin.readline().rstrip("\n")
PW_A = sys.stdin.readline().rstrip("\n")
if not (S_A and PW_A):
    print("세션·비밀번호를 표준입력으로 주어야 한다 — 시작하지 않는다")
    sys.exit(2)
HOST = re.match(r"https?://([^/:]+)", SITE).group(1)
if re.search(r":(80|8082|8081)$", SITE):
    print("운영 포트 — 시작하지 않는다")
    sys.exit(3)
COMBOS = [(int(w), t) for w, t in (c.split(":") for c in (os.environ.get("D74_COMBOS") or "360:light,360:dark,412:light,412:dark,1280:light,1280:dark").split(","))]
os.makedirs(SHOTS, exist_ok=True)
TAG = uuid.uuid4().hex[:6]

# 측정 도우미 — dbg_u2_ui.py 의 창 단추 규칙·낱말 표·터치·넘침 **한 벌**을 글자 그대로 읽어 쓴다(두 벌로 두지 않는다 · 실행하지 않고 값만)
_src = open(os.path.join(ROOT, "tests/browser/dbg_u2_ui.py"), encoding="utf-8").read()
_consts = {}
for node in ast.parse(_src).body:
    if isinstance(node, ast.Assign) and len(node.targets) == 1 and isinstance(node.targets[0], ast.Name) \
            and node.targets[0].id in ("OVER", "MODAL_RULE", "OLD", "WORDS", "TOUCH"):
        _consts[node.targets[0].id] = ast.literal_eval(node.value)
if len(_consts) != 5:
    print("dbg_u2_ui.py 의 측정 도우미 다섯을 읽지 못했다 — 시작하지 않는다 %s" % sorted(_consts))
    sys.exit(2)
OVER, MODAL_RULE, OLD, WORDS, TOUCH = (_consts[k] for k in ("OVER", "MODAL_RULE", "OLD", "WORDS", "TOUCH"))

fails = 0
passes = 0
dialogs = []
errors = []


def step(name, ok, detail=""):
    global fails, passes
    if ok:
        passes += 1
    else:
        fails += 1
    print("  %s %s%s" % ("✔" if ok else "✖", name, ("  — " + str(detail)[:400]) if (detail != "" and not ok) else ""), flush=True)


def api(method, path, body=None, csrf=None):
    h = {"User-Agent": UA, "Cookie": "__Host-tbox_session=" + S_A}
    data = None
    if csrf:
        h["X-CSRF-Token"] = csrf
    if body is not None:
        data = json.dumps(body).encode()
        h["Content-Type"] = "application/json"
    r = urllib.request.Request(SITE + path, data=data, headers=h, method=method)
    try:
        with urllib.request.urlopen(r, timeout=30) as resp:
            return resp.status, json.loads(resp.read().decode() or "null")
    except urllib.error.HTTPError as e:
        try:
            return e.code, json.loads(e.read().decode() or "null")
        except Exception:
            return e.code, None


print("── 준비 (임시 DB 에만 쓴다)")
c, me = api("GET", "/tbox/api/me")
CSRF = (me or {}).get("csrf_token", "")
if c == 200 and (me or {}).get("reauth_fresh") is not True:
    # 공용 잠금을 기다리는 동안 15분 창이 닫혔을 수 있다 — 잠금을 쥔 뒤 여기서 다시 확인한다(같은 세션)
    api("POST", "/tbox/api/me/reauth", {"password": PW_A}, CSRF)
    c, me = api("GET", "/tbox/api/me")
step("관리자 /api/me 200 · 재인증 창 열림", c == 200 and (me or {}).get("reauth_fresh") is True, (c, (me or {}).get("reauth_fresh")))
TARGET = "d74w" + TAG            # 화면에서 이름을 바꿔 볼 사람 계정(임시 DB · 시험용 이름)
NEWNAME = "d74v" + TAG
c, d = api("POST", "/tbox/api/users", {"username": TARGET, "role": "Employee", "reason_text": "d74 화면 시험 대상"}, CSRF)
step("화면 시험 대상 계정 만들기 201(임시 DB)", c == 201, (c, str(d)[:160]))


def modal_rule(pg, what):
    r = pg.evaluate(MODAL_RULE, True)
    step("창 단추 규칙 — %s (창 %d개)" % (what, r["n"]), r["n"] >= 1 and not r["bad"], r["bad"][:6])


def words(pg, what):
    h = pg.evaluate(WORDS, OLD)
    step("낱말 표 — %s 화면 글자에 옛 낱말·영어 값 0" % what, not h, h[:6])


def touch(pg, what, root):
    r = pg.evaluate(TOUCH, root)
    step("터치 대상 44px — %s (누르는 것 %d개)" % (what, r["n"]), r["n"] > 0 and not r["bad"], "%d개 모자람 %s" % (len(r["bad"]), r["bad"][:8]))
    step("넓힌 누름 자리가 잘리거나 덮이지 않음 — %s" % what, not r["clipped"], r["clipped"][:6])
    step("보이는 상자 안 어디를 눌러도 자기 자신 — %s" % what, not r["stolen"], r["stolen"][:6])


def no_overflow(pg, what):
    o = pg.evaluate(OVER)
    step(what + " — 가로 넘침 0", isinstance(o, int) and o <= 0, o)


def shot(pg, name):
    try:
        pg.screenshot(path=os.path.join(SHOTS, name + ".png"), full_page=False)
    except Exception as ex:
        print("  · 스크린샷 실패 %s %s" % (name, str(ex)[:80]))


def guard(name, fn):
    try:
        fn()
    except Exception as ex:
        step(name + " — 진행 중 예외", False, str(ex).split("\n")[0][:200])


cur = {"name": TARGET}
renamed = {"done": False}

with sync_playwright() as p:
    br = p.chromium.launch()
    for w, theme in COMBOS:
        tag = "%d-%s" % (w, theme)
        narrow = w < 720
        print("\n── %s" % tag, flush=True)
        c = br.new_context(user_agent=UA, viewport={"width": w, "height": 900}, device_scale_factor=1,
                           has_touch=narrow, is_mobile=narrow, color_scheme="light")
        c.add_cookies([{"name": "tbox_theme", "value": theme, "domain": HOST, "path": "/"},
                       {"name": "__Host-tbox_session", "value": S_A, "domain": HOST, "path": "/", "httpOnly": True, "secure": True}])
        pg = c.new_page()
        pg.on("dialog", lambda dd: (dialogs.append(dd.type + ": " + dd.message[:60]), dd.dismiss()))
        pg.on("pageerror", lambda e: errors.append("pageerror " + str(e)[:160]))
        pg.on("console", lambda m: errors.append("console " + m.text[:160]) if m.type == "error" else None)
        patches = []
        pg.on("request", lambda r: patches.append(r.post_data or "") if r.method == "PATCH" and "/api/users/" in r.url else None)

        def run():
            pg.goto(SITE + "/tbox/users", wait_until="networkidle", timeout=30000)
            pg.wait_for_timeout(400)
            no_overflow(pg, "계정 관리 " + tag)
            words(pg, "계정 관리 " + tag)
            # 본인 줄 — 아이디 카드가 숨는다
            me_row = pg.locator("#users-body tr", has_text="claude_admin01").first
            me_row.scroll_into_view_if_needed()
            me_row.locator("td.actions button").first.click()
            pg.wait_for_timeout(300)
            hid = pg.evaluate("() => { const b = document.querySelector('#user-manage-dialog [data-user-action=\"username\"]'); return b ? b.hidden : null; }")
            step("본인 줄 「관리」 — 아이디 카드 숨김 " + tag, hid is True, hid)
            pg.keyboard.press("Escape")
            pg.wait_for_timeout(250)
            row = pg.locator("#users-body tr", has_text=cur["name"]).first
            row.scroll_into_view_if_needed()
            row.locator("td.actions button").first.click()
            pg.wait_for_timeout(400)
            acts = pg.evaluate("() => [...document.querySelectorAll('#user-manage-dialog [data-user-action]')].filter(b => !b.hidden).map(b => b.getAttribute('data-user-action'))")
            step("상세 창 동작 여섯(바꾸기·아이디·임시 비밀번호·2단계·프로그램 토큰·활성) " + tag,
                 sorted(acts) == sorted(["edit", "username", "password", "mfa", "tokens", "active"]), acts)
            facts = pg.evaluate("() => (document.getElementById('user-manage-facts') || {}).innerText || ''")
            step("상세 창 아이디 칸 = 지금 이름 " + tag, cur["name"] in facts, facts[:100])
            if narrow:
                touch(pg, "계정 관리 상세 창 " + tag, "dialog#user-manage-dialog")
            shot(pg, "manage-%s" % tag)
            pg.click("#user-manage-dialog [data-user-action='username']")
            pg.wait_for_timeout(400)
            st = pg.evaluate("() => [!!document.querySelector('#user-manage-dialog[open]'), !!document.querySelector('#user-rename[open]'), document.querySelectorAll('dialog[open]').length]")
            step("「로그인 아이디 바꾸기」 → 상세 창 닫고 아이디 창 하나만 " + tag, st == [False, True, 1], st)
            modal_rule(pg, "아이디 창 " + tag)
            words(pg, "아이디 창 " + tag)
            if narrow:
                touch(pg, "아이디 창 " + tag, "dialog#user-rename")
            no_overflow(pg, "아이디 창 " + tag)
            inner = pg.evaluate("() => { const b = document.querySelector('dialog#user-rename .modal__body'); return b ? b.scrollWidth - b.clientWidth : 0; }")
            step("아이디 창 본문 가로 넘침 0 " + tag, inner <= 1, inner)
            attrs = pg.evaluate("() => { const i = document.getElementById('ur-username'); return i ? [i.getAttribute('autocomplete'), i.getAttribute('autocapitalize'), i.getAttribute('spellcheck'), i.maxLength, document.activeElement === i] : null; }")
            step("새 아이디 칸 — 자동 완성 끔 · 대문자 끔 · 맞춤법 끔 · 64자 · 처음 초점 " + tag, attrs == ["off", "none", "false", 64, True], attrs)
            title = pg.evaluate("() => (document.getElementById('ur-title') || {}).textContent || ''")
            step("창 제목에 대상 계정 " + tag, cur["name"] in title, title)
            foot = pg.evaluate("() => [...document.querySelectorAll('#user-rename .modal__foot button')].map(b => b.textContent.trim())")
            step("바닥 단추 = 「닫기」 · 「아이디 바꾸기」(오른쪽 끝) " + tag, foot == ["닫기", "아이디 바꾸기"], foot)
            shot(pg, "rename-open-%s" % tag)
            n0 = len(patches)
            pg.click("#ur-submit")
            pg.wait_for_timeout(300)
            m = pg.evaluate("() => (document.getElementById('ur-msg') || {}).textContent || ''")
            step("빈 칸 저장 → 창 안 안내 · 요청 0 " + tag, "새 아이디를 적어야" in m and len(patches) == n0, (m, len(patches) - n0))
            if (w, theme) == (360, "light"):
                pg.fill("#ur-username", "claude_employee02")
                pg.fill("#ur-reason", "d74 화면 시험 — 이미 있는 아이디")
                pg.click("#ur-submit")
                pg.wait_for_timeout(900)
                st = pg.evaluate("() => [!!document.querySelector('#user-rename[open]'), (document.getElementById('ur-msg') || {}).textContent || '']")
                step("이미 있는 아이디 → 서버 문구가 창 안에 · 창은 열린 채 " + tag, st[0] is True and "이미 있는 아이디" in st[1], st)
                # 일부러 받은 400 한 번 — 브라우저가 콘솔에 「status of 400」 을 적는다(시험이 부른 거부라 오류로 세지 않는다 · dbg_u2_ui 의 403 과 같은 처리)
                errors[:] = [e for e in errors if "status of 400" not in e]
                shot(pg, "rename-error-%s" % tag)
            pg.fill("#ur-username", "d74esc" + TAG)
            pg.keyboard.press("Escape")
            pg.wait_for_timeout(300)
            st = pg.evaluate("() => [!!document.querySelector('#user-rename[open]'), (document.getElementById('ur-username') || {}).value, (document.getElementById('msg') || {}).textContent || '']")
            step("Esc → 창 닫힘 · 입력 값 비움 · 「취소했습니다」 " + tag, st[0] is False and st[1] == "" and "취소했습니다" in st[2], st)
            if (w, theme) == (1280, "light") and not renamed["done"]:
                row = pg.locator("#users-body tr", has_text=cur["name"]).first
                row.locator("td.actions button").first.click()
                pg.wait_for_timeout(300)
                pg.click("#user-manage-dialog [data-user-action='username']")
                pg.wait_for_timeout(300)
                pg.fill("#ur-username", NEWNAME)
                pg.fill("#ur-reason", "d74 화면 시험 — 아이디 바꾸기")
                shot(pg, "rename-filled-%s" % tag)
                pg.click("#ur-submit")
                pg.wait_for_timeout(1500)
                st = pg.evaluate("() => [!!document.querySelector('#user-rename[open]'), (document.getElementById('msg') || {}).textContent || '', (document.getElementById('ur-username') || {}).value]")
                step("바꾸기 → 창 닫힘 · 알림 줄 「로그인 아이디를 바꿨습니다」 · 입력 값 비움 " + tag, st[0] is False and "로그인 아이디를 바꿨습니다" in st[1] and st[2] == "", st)
                step("  알림 줄에 새 아이디를 적지 않는다 " + tag, NEWNAME not in st[1], st[1])
                pg.wait_for_timeout(600)
                rows_txt = pg.evaluate("() => (document.getElementById('users-body') || {}).innerText || ''")
                step("  목록이 새 이름으로(옛 이름 0) " + tag, NEWNAME in rows_txt and TARGET not in rows_txt, rows_txt.count(NEWNAME))
                cur["name"] = NEWNAME
                renamed["done"] = True
                row = pg.locator("#users-body tr", has_text=NEWNAME).first
                row.locator("td.actions button").first.click()
                pg.wait_for_timeout(300)
                facts = pg.evaluate("() => (document.getElementById('user-manage-facts') || {}).innerText || ''")
                step("  상세 창 아이디 칸도 새 이름 " + tag, NEWNAME in facts, facts[:100])
                shot(pg, "renamed-manage-%s" % tag)
                pg.keyboard.press("Escape")
                pg.wait_for_timeout(250)
                body = [x for x in patches if x]
                step("  보낸 본문은 username · reason_text 둘뿐 " + tag, bool(body) and sorted(json.loads(body[-1]).keys()) == ["reason_text", "username"], body[-1:] if body else body)
            shot(pg, "users-%s" % tag)

        guard("계정 관리 " + tag, run)
        c.close()
    br.close()

print("\n── 공통")
c2, lst = api("GET", "/tbox/api/users?page=1")
names = [u.get("username") for u in (lst or {}).get("users", [])]
step("창구로 본 목록에도 새 이름 · 옛 이름 0", NEWNAME in names and TARGET not in names, (NEWNAME in names, TARGET in names))
step("브라우저 기본 창(alert·prompt·confirm) 0건", not dialogs, dialogs[:5])
step("콘솔 오류·pageerror 0건", not errors, errors[:6])
print("\n통과 %d · 실패 %d" % (passes, fails))
print("SMOKE_FAIL=%d" % fails)
sys.exit(1 if fails else 0)
