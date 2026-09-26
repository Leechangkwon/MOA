#!/usr/bin/env python3
# tests/browser/dbg_u_ui.py — 디버깅 묶음 U(디자인 점검 2026-09-26 12:13 의 높음 다섯 + 중간 일부) 화면을 진짜 브라우저로 눌러 본다
#
# 부르는 쪽: tests/dbg_u_ui.sh (임시 인스턴스 · 임시 DB `tbox_d1u_*`. 운영 80·8082·8081 에는 요청하지 않는다).
# 쓰는 법: py_secret S_E1 S_A1 S_G1 PW_E1 SEC_E1 PW_A1 -- python3 dbg_u_ui.py <SITE> <UA> <SHOTS> [before]
#   세션 셋 · 비밀번호 둘 · TOTP 비밀 하나는 **표준입력 여섯 줄**(R-62 — 명령 인자에 비밀 0). TOTP 비밀이 없으면 「-」.
#   넷째 인자 `before` 는 고치기 전 판(a57cf6b)을 찍을 때 — 판정은 참고용이고 스크린샷만 쓴다(종료 코드 0).
# 폭·색: 360 · 412(터치 흉내) · 820 · 1280 × 라이트 · 다크 = 8 조합. 조합마다 화면을 찍고(SHOTS — 사람이 직접 연다) 잰다.
# 보는 것 (지시서 「시험」 절 그대로)
#   공통   문서 가로 넘침 0(scrollWidth ≤ clientWidth) · 콘솔 오류·pageerror 0 · 브라우저 기본 창(alert·prompt·confirm) 0건
#   H1     좁은 폭의 표 — 파일 행의 받기·공유 · 내 휴지통 복구 · 계정 관리 단추 다섯(820 포함) · 커뮤니티 휴지통 되살리기 ·
#          템플릿 만들기 · 토큰 상태 칸과 회수 단추가 **화면 안**(boundingBox 오른쪽 ≤ 폭, 왼쪽 ≥ 0)
#   H2     파일 「수정」 = 창 하나(설명 · 등급 고르개 · 팀 이름 고르개, 선택지는 서버가 준 것만) · Esc·닫기는 요청 0 · 초점 복귀 ·
#          저장은 바뀐 키만 · 내 계정 토큰 회수 = 확인 창(#confirm-dialog) · 계정 관리 토큰 창의 회수 = 창 안 확인 줄
#   H3     손님(Guest) — 새 페이지·사이드바 새 페이지·선택하기·템플릿·시작하기 페이지의 편집·넘기기·바꾸기·태그 0 · 직원은 그대로
#   H4     문서 안 표 — 칸 글자가 한 글자씩 세로로 쪼개지지 않는다(「김영업」 한 줄) · 표만 가로로 밀린다
#   H5     편집 모드 머리 동작 한 줄(360·412) · 「편집 끝」 화면 안 · 저장 배지는 머리 동작 줄 밖
#   M1 크기 한 줄 · M2 시각 두 줄까지 · M4 좁은 폭 창 ✕ 가 머리 첫 줄 · M7 역할 한글(title 에 원래 값) · M11 트리에 기본값 「비공개」 0 ·
#   M13 2단계 코드가 틀리면 코드 문장 · 비밀번호 칸 그대로 · M17 미리보기·관리자 배지의 개발자 말 0 · L4 터치 뒤 툴팁 0(마우스 올리면 보임)
# 나가는 값: 마지막 줄 `SMOKE_FAIL=<n>` · 종료 코드 0/1
import base64
import hashlib
import hmac
import json
import os
import re
import struct
import sys
import time
import urllib.error
import urllib.request
import uuid

from playwright.sync_api import sync_playwright

SITE = sys.argv[1].rstrip("/")
UA = sys.argv[2]
SHOTS = sys.argv[3]
BEFORE = len(sys.argv) > 4 and sys.argv[4] == "before"
S_E1 = sys.stdin.readline().rstrip("\n")
S_A1 = sys.stdin.readline().rstrip("\n")
S_G1 = sys.stdin.readline().rstrip("\n")
PW_E1 = sys.stdin.readline().rstrip("\n")
SEC_E1 = sys.stdin.readline().rstrip("\n")
PW_A1 = sys.stdin.readline().rstrip("\n")
if not (S_E1 and S_A1 and S_G1 and PW_A1):
    print("세션·비밀번호를 표준입력으로 주어야 한다 — 시작하지 않는다")
    sys.exit(2)
if SEC_E1 == "-":
    SEC_E1 = ""
HOST = re.match(r"https?://([^/:]+)", SITE).group(1)
COMBOS = [(int(w), t) for w, t in (c.split(":") for c in (os.environ.get("DBGU_COMBOS") or "360:light,360:dark,412:light,412:dark,820:light,820:dark,1280:light,1280:dark").split(","))]
os.makedirs(SHOTS, exist_ok=True)
fails = 0
passes = 0
dialogs = []          # 브라우저 기본 창(alert·prompt·confirm) — 0 이어야 한다
errors = []           # pageerror · console.error
TAG = uuid.uuid4().hex[:6]


def step(name, ok, detail=""):
    global fails, passes
    if ok:
        passes += 1
    else:
        fails += 1
    print("  %s %s%s" % ("✔" if ok else "✖", name, ("  — " + str(detail)[:300]) if (detail != "" and not ok) else ""), flush=True)


# ─── 창구 부르기(세션 쿠키 · CSRF) — 준비와 서버 판정 확인에만 ───────────────────────
class Api:
    def __init__(self, sess):
        self.sess = sess
        self.csrf = ""
        c, me = self.req("GET", "/tbox/api/me")
        self.me = me if c == 200 else {}
        self.csrf = self.me.get("csrf_token", "")

    def req(self, method, path, body=None, raw=None, ctype=None):
        h = {"User-Agent": UA, "Cookie": "__Host-tbox_session=" + self.sess}
        if self.csrf and method != "GET":
            h["X-CSRF-Token"] = self.csrf
        data = raw
        if body is not None:
            data = json.dumps(body).encode()
            h["Content-Type"] = "application/json"
        if ctype:
            h["Content-Type"] = ctype
        try:
            with urllib.request.urlopen(urllib.request.Request(SITE + path, data=data, method=method, headers=h), timeout=60) as r:
                code, txt = r.status, r.read().decode("utf-8", "replace")
        except urllib.error.HTTPError as e:
            code, txt = e.code, e.read().decode("utf-8", "replace")
        try:
            return code, json.loads(txt or "{}")
        except ValueError:
            return code, {"_raw": txt[:200]}

    def upload(self, name, data, level="internal", team_id=None):
        bnd = "----dbgu" + uuid.uuid4().hex
        parts = []
        fields = [("access_level", level), ("description", "")] + ([("team_id", str(team_id))] if team_id is not None else [])
        for k, v in fields:
            parts.append(("--%s\r\nContent-Disposition: form-data; name=\"%s\"\r\n\r\n%s\r\n" % (bnd, k, v)).encode())
        parts.append(("--%s\r\nContent-Disposition: form-data; name=\"file\"; filename=\"%s\"\r\nContent-Type: application/octet-stream\r\n\r\n" % (bnd, name)).encode() + data + b"\r\n")
        parts.append(("--%s--\r\n" % bnd).encode())
        return self.req("POST", "/tbox/api/files", raw=b"".join(parts), ctype="multipart/form-data; boundary=" + bnd)


def totp(secret, t):
    k = base64.b32decode(secret.upper() + "=" * (-len(secret) % 8))
    m = hmac.new(k, struct.pack(">Q", int(t) // 30), hashlib.sha1).digest()
    o = m[-1] & 15
    return "%06d" % ((struct.unpack(">I", m[o:o + 4])[0] & 0x7FFFFFFF) % 1000000)


E = Api(S_E1)
A = Api(S_A1)
G = Api(S_G1)
print("── 준비 (임시 DB 에만 쓴다)")
step("세 계정 /api/me 200", bool(E.csrf and A.csrf and G.csrf), (bool(E.csrf), bool(A.csrf), bool(G.csrf)))
if not BEFORE:
    step("H3 /api/me can_community_write — 직원 참", E.me.get("can_community_write") is True, E.me.get("can_community_write"))
    step("H3 /api/me can_community_write — 손님 거짓", G.me.get("can_community_write") is False, G.me.get("can_community_write"))
    step("H3 /api/me can_community_write — 관리자 참", A.me.get("can_community_write") is True, A.me.get("can_community_write"))
    c, d = G.req("POST", "/community/api/pages", {"title": "DBGU 손님 시험 " + TAG})
    step("H3 서버 거부는 그대로 — 손님 POST /community/api/pages → 403", c == 403, (c, d))

# 파일 둘(이름이 긴 것) + 하나는 휴지통(내 휴지통 「복구」)
names = ["DBGU-%s-거래처별_샘플_발송_현황_최종본.csv" % TAG, "DBGU-%s-회의록.txt" % TAG, "DBGU-%s-지울것.txt" % TAG]
ids = {}
for n in names:
    c, d = E.upload(n, ("dbgu %s %s\n" % (TAG, n)).encode())
    fid = (d.get("file") or d).get("id") if isinstance(d, dict) else None
    ids[n] = fid
step("파일 셋 올리기 201", all(ids.values()), ids)
# 독립 검토 M-1 — 팀 등급 파일(직원 팀) 하나: 관리자가 설명만 고칠 때 team_id 가 실리지 않는지 본다
TEAM_FILE = "DBGU-%s-팀파일.txt" % TAG
MY_TEAM = E.me.get("team_id")
c, d = E.upload(TEAM_FILE, ("dbgu team %s\n" % TAG).encode(), level="team", team_id=MY_TEAM)
step("팀 등급 파일 올리기 201(직원 팀)", c == 201 and MY_TEAM is not None, (c, str(d)[:120], MY_TEAM))
if ids.get(names[2]):
    c, _ = E.req("DELETE", "/tbox/api/files/" + ids[names[2]])
    step("셋째 파일 휴지통으로 200", c == 200, c)

# 커뮤니티 — 문서 안 표가 든 페이지 · 휴지통 한 줄 · 내 템플릿 하나
def T(t):
    return [{"t": t, "m": []}]


def B(tp, **kw):
    d = {"id": str(uuid.uuid4()), "type": tp}
    d.update(kw)
    return d


def cell(t):
    return {"text": T(t), "color": None}


doc = {"schema": 1, "blocks": [
    B("heading", level=1, text=T("DBGU 회의 요약")),
    B("paragraph", text=T("문서 안 표가 폰에서 한 글자씩 세로로 쪼개지지 않는지 본다.")),
    B("table", header_row=True, header_col=False, rows=[
        {"color": None, "cells": [cell("거래처"), cell("담당"), cell("상태"), cell("메모")]},
        {"color": None, "cells": [cell("한빛전자"), cell("김영업"), cell("발송 완료"), cell("10월 첫 주 회신 예정")]},
        {"color": None, "cells": [cell("대한정밀기계공업주식회사"), cell("이영업"), cell("대기"), cell("담당자 휴가 — 9월 30일 복귀")]}]),
    B("paragraph", text=T("")),
]}
c, d = E.req("POST", "/community/api/pages", {"title": "DBGU 표 시험 " + TAG, "doc": doc})
PID = ((d.get("page") or {}).get("id")) if isinstance(d, dict) else None
step("커뮤니티 표 페이지 201", c == 201 and bool(PID), (c, str(d)[:120]))
c, d = E.req("POST", "/community/api/pages", {"title": "DBGU 지울 페이지 " + TAG})
TP = ((d.get("page") or {}).get("id")) if isinstance(d, dict) else None
if TP:
    c2, _ = E.req("DELETE", "/community/api/pages/" + TP)
    step("커뮤니티 페이지 휴지통으로 200", c2 == 200, c2)
if PID:
    c, d = E.req("POST", "/community/api/templates", {"page_id": PID, "name": "DBGU 틀 " + TAG})
    step("내 템플릿 하나 201", c == 201, (c, str(d)[:120]))

# 내 계정 토큰 하나(회수 흐름용) — 2단계 코드는 로그인에 쓴 창 다음 창의 것(R-45 한 번만)
TOKEN_NAME = "DBGU-%s" % TAG
if SEC_E1 and PW_E1:
    # 코드는 한 번만 쓰인다(R-45) — 로그인에 쓴 창 다음 창의 것을 쓰고, 400(통일 문구 — 코드 재사용·경계 등)이면 그다음 창에서 한 번 더
    c, d = 0, {}
    for attempt in (1, 2):
        wait = 32 - int(time.time()) % 30
        print("  · 토큰 발급 %d회 — 다음 2단계 코드 창까지 %d초 기다린다" % (attempt, wait), flush=True)
        time.sleep(wait)
        at = time.time()
        c, d = E.req("POST", "/tbox/api/me/api-tokens", {"name": TOKEN_NAME, "purpose": "디버깅 묶음 U 회수 시험", "scope": "read",
                                                       "password": PW_E1, "otp": totp(SEC_E1, at)})
        print("    %d회 → %s (창 안 %.1f초)" % (attempt, c, at % 30), flush=True)
        if c == 201:
            break
    step("내 계정 토큰 발급 201", c == 201, (c, d.get("error") if isinstance(d, dict) else d))
else:
    step("내 계정 토큰 발급 — 비밀번호·TOTP 비밀이 없다", False, "PW/SEC 없음")

c, gspace = G.req("GET", "/community/api/me/space")
c, gp = G.req("GET", "/community/api/pages?tree=1")
GUEST_PAGE = None
for p in (gp.get("pages") or []) if isinstance(gp, dict) else []:
    GUEST_PAGE = p.get("id")
    break
step("손님의 시작하기 페이지가 있다", bool(GUEST_PAGE), str(gp)[:160])

# ─── 측정 도우미 ──────────────────────────────────────────────────
OVER = "() => document.documentElement.scrollWidth - document.documentElement.clientWidth"
INVIEW = """(sel) => { const out = []; const W = document.documentElement.clientWidth;
  for (const e of document.querySelectorAll(sel)) { if (!e.getClientRects().length) continue; const r = e.getBoundingClientRect();
    out.push({t: (e.getAttribute('aria-label') || e.textContent || '').trim().slice(0, 16), l: Math.round(r.left), r: Math.round(r.right), w: W}); }
  return out; }"""
LINES = """(el) => { const rg = document.createRange(); rg.selectNodeContents(el); return new Set([...rg.getClientRects()].filter(q => q.width > 0).map(q => Math.round(q.top))).size; }"""


def in_view(pg, sel, what, need=1):
    try:
        items = pg.evaluate(INVIEW, sel)
    except Exception as ex:
        step(what, False, str(ex)[:120])
        return
    bad = [i for i in items if i["r"] > i["w"] + 1 or i["l"] < -1]
    step(what + " (%d개)" % len(items), len(items) >= need and not bad, bad[:3] if bad else "보이는 것 %d개" % len(items))


def no_overflow(pg, what):
    try:
        o = pg.evaluate(OVER)
    except Exception as ex:
        o = str(ex)[:80]
    step(what + " — 가로 넘침 0", o == 0 or (isinstance(o, int) and o <= 0), o)


def shot(pg, name):
    try:
        pg.screenshot(path=os.path.join(SHOTS, name + ".png"), full_page=False)
    except Exception as ex:
        print("  · 스크린샷 실패 %s %s" % (name, str(ex)[:80]))


def ctx(br, sess, w, theme):
    touch = w < 720
    c = br.new_context(user_agent=UA, viewport={"width": w, "height": 900}, device_scale_factor=1,
                       has_touch=touch, is_mobile=touch, color_scheme="light")
    ck = [{"name": "tbox_theme", "value": theme, "domain": HOST, "path": "/"}]
    if sess:
        ck.append({"name": "__Host-tbox_session", "value": sess, "domain": HOST, "path": "/", "httpOnly": True, "secure": True})
    c.add_cookies(ck)
    return c


LOGIN_401 = "Failed to load resource: the server responded with a status of 401"


def page_of(c, login_screen=False):
    # login_screen — 로그인 화면의 1단계 답(401 + mfa_required)과 틀린 코드의 401 은 **설계된 응답**이다(브라우저가 콘솔에 「리소스 401」을 찍는다).
    #                그 한 줄만 빼고 센다. 그 밖의 콘솔 오류·pageerror 는 어느 화면이든 센다.
    pg = c.new_page()
    pg.on("dialog", lambda d: (dialogs.append(d.type + ": " + d.message[:60]), d.dismiss()))
    pg.on("pageerror", lambda e: errors.append("pageerror " + str(e)[:160]))
    pg.on("console", lambda m: errors.append("console " + pg.url.replace(SITE, "")[:40] + " " + m.text[:160])
          if m.type == "error" and not (login_screen and m.text.startswith(LOGIN_401)) else None)
    return pg


def go(pg, path):
    pg.goto(SITE + path, wait_until="networkidle", timeout=30000)
    pg.wait_for_timeout(500)


def guard(name, fn):
    try:
        fn()
    except Exception as ex:
        step(name + " — 진행 중 예외", False, str(ex).split("\n")[0][:200])


def one_line_head(pg, what):
    r = pg.evaluate("""() => { const a = document.querySelector('.page-head__actions'); if (!a) return null;
      const kids = [...a.children].filter(k => k.getClientRects().length && getComputedStyle(k).display !== 'none');
      const cs = kids.map(k => { const r = k.getBoundingClientRect(); return (r.top + r.bottom) / 2; }).sort((a, b) => a - b);
      let lines = cs.length ? 1 : 0; for (let i = 1; i < cs.length; i++) { if (cs[i] - cs[i - 1] > 12) lines++; }
      return {n: kids.length, lines: lines}; }""")
    step(what + " — 머리 동작 한 줄", r is not None and r["lines"] == 1, r)


print("── 화면 (%s)" % ("고치기 전 판 — 스크린샷만" if BEFORE else "고친 판"))
revoked_once = False
edited_once = False
with sync_playwright() as p:
    br = p.chromium.launch()
    for w, theme in COMBOS:
        tag = "%d-%s" % (w, theme)
        narrow = w < 720
        print("\n── %s" % tag, flush=True)

        # ── 직원: t-box 파일 · 수정 창 · 내 휴지통 · 내 계정 ────────────────
        c = ctx(br, S_E1, w, theme)
        pg = page_of(c)

        def files():
            go(pg, "/tbox/")
            no_overflow(pg, "t-box 파일 " + tag)
            # 받기는 `<a aria-label="다운로드">`(링크), 나머지는 `<button>` — 동작 칸의 aria-label 달린 것 전부를 본다
            in_view(pg, "#files-body tr td.actions [aria-label]", "H1 파일 행 동작(보기·받기·고치기·공유·이동·삭제)이 화면 안 " + tag, 6)
            in_view(pg, "#files-body tr:first-child td.actions [aria-label='다운로드'], #files-body tr:first-child td.actions [aria-label^='공유']",
                    "H1 첫 행 받기·공유 화면 안 " + tag, 2)
            sizes = pg.evaluate("""(L) => [...document.querySelectorAll('#files-body tr')].slice(0, 6).map(tr => { const td = tr.children[1]; const f = new Function('return ' + L)(); return td ? [td.textContent, f(td)] : null; })""", LINES)
            step("M1 크기 한 줄(숫자·단위 갈라짐 0) " + tag, bool(sizes) and all(s and s[1] == 1 for s in sizes), sizes[:3])
            whens = pg.evaluate("""(L) => { const f = new Function('return ' + L)(); return [...document.querySelectorAll('#files-body tr')].slice(0, 6).map(tr => { const td = [...tr.children].find(x => /^\\d{4}-\\d{2}-\\d{2} /.test(x.textContent)); return td ? f(td) : 0; }); }""", LINES)
            step("M2 시각 두 줄까지 " + tag, bool(whens) and max(whens) <= 2, whens)
            shot(pg, "t-files-" + tag)
        guard("t-box 파일 " + tag, files)

        def edit_dialog():
            global edited_once
            go(pg, "/tbox/")
            row = pg.locator("#files-body tr", has_text=names[0]).first
            btn = row.locator("button[aria-label='수정']")
            sent = []
            pg.on("request", lambda r: sent.append(r.method + " " + r.url) if r.method == "PATCH" else None)
            btn.click()
            pg.wait_for_timeout(500)
            opened = pg.evaluate("() => !!document.querySelector('#file-edit-dialog[open]')")
            step("H2 「수정」 → 창 하나(#file-edit-dialog) " + tag, opened)
            if not opened:
                return
            shot(pg, "t-edit-dialog-" + tag)
            info = pg.evaluate("""() => ({levels: [...document.querySelectorAll('#file-edit-level option')].map(o => [o.value, o.textContent]),
               teams: [...document.querySelectorAll('#file-edit-team option')].map(o => o.textContent),
               focus: document.activeElement && document.activeElement.id,
               overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth})""")
            allowed = set((E.me.get("upload_levels") or [])) | {"internal"}
            step("H2 등급은 고르기 — 선택지 ⊆ 서버가 준 upload_levels ∪ 지금 등급 " + tag,
                 bool(info["levels"]) and all(v in allowed for v, _ in info["levels"]), info["levels"])
            step("H2 팀은 이름으로 — 숫자만인 선택지 0 " + tag, bool(info["teams"]) and not any(t.strip().isdigit() for t in info["teams"]), info["teams"][:4])
            step("H2 처음 초점 = 설명 칸 " + tag, info["focus"] == "file-edit-description", info["focus"])
            step("H2 창 열린 동안 가로 넘침 0 " + tag, info["overflow"] <= 0, info["overflow"])
            if narrow:
                x = pg.evaluate("""() => { const h = document.querySelector('#file-edit-dialog .modal__head'); const b = document.getElementById('file-edit-x');
                   const hr = h.getBoundingClientRect(), br = b.getBoundingClientRect(); return {top: Math.round(br.top - hr.top), right: Math.round(hr.right - br.right)}; }""")
                step("M4 좁은 폭 창 ✕ 는 머리 첫 줄 오른끝 " + tag, x["top"] <= 16 and x["right"] <= 16, x)
            pg.keyboard.press("Escape")
            pg.wait_for_timeout(300)
            st = pg.evaluate("() => ({open: !!document.querySelector('#file-edit-dialog[open]'), focus: document.activeElement && document.activeElement.getAttribute('aria-label')})")
            step("H2 Esc → 닫힘 · 초점이 「수정」 단추로 돌아옴 · PATCH 0 " + tag, not st["open"] and st["focus"] == "수정" and not sent, (st, sent))
            btn.click()
            pg.wait_for_timeout(300)
            pg.click("#file-edit-close")
            pg.wait_for_timeout(300)
            st = pg.evaluate("() => !!document.querySelector('#file-edit-dialog[open]')")
            step("H2 「닫기」 → 닫힘 · PATCH 0 " + tag, not st and not sent, sent)
            if not edited_once:
                btn.click()
                pg.wait_for_timeout(300)
                bodies = []
                pg.on("request", lambda r: bodies.append(r.post_data) if r.method == "PATCH" else None)
                pg.fill("#file-edit-description", "DBGU 설명 " + TAG)
                pg.click("#file-edit-submit")
                pg.wait_for_timeout(1200)
                okn = pg.evaluate("() => { const o = document.getElementById('files-ok'); return o && !o.hidden ? o.textContent : ''; }")
                closed = not pg.evaluate("() => !!document.querySelector('#file-edit-dialog[open]')")
                body = json.loads(bodies[0]) if bodies else {}
                step("H2 저장 → PATCH 한 번 · 바뀐 키만(description) · 창 닫힘 · 결과 한 줄 " + tag,
                     len(bodies) == 1 and list(body.keys()) == ["description"] and closed and "고쳤습니다" in okn, (bodies, closed, okn))
                edited_once = True
        guard("H2 파일 수정 창 " + tag, edit_dialog)

        def touch_tip():
            if not narrow:
                go(pg, "/tbox/")
                star = pg.locator("#files-body tr:first-child button[data-fav-file]").first
                star.hover()
                pg.wait_for_timeout(200)
                d = star.evaluate("(b) => getComputedStyle(b, '::after').display")
                step("L4 마우스(1280·820)는 올리면 툴팁이 보인다 " + tag, d == "block", d)
                return
            go(pg, "/tbox/")
            star = pg.locator("#files-body tr:first-child button[data-fav-file]").first
            star.tap()
            pg.wait_for_timeout(500)
            d = star.evaluate("(b) => getComputedStyle(b, '::after').display")
            step("L4 터치로 누른 뒤 툴팁이 남지 않는다 " + tag, d == "none", d)
            star.tap()   # 즐겨찾기 되돌리기(임시 DB)
            pg.wait_for_timeout(400)
        guard("L4 툴팁 " + tag, touch_tip)

        def trash():
            go(pg, "/tbox/trash")
            no_overflow(pg, "t-box 내 휴지통 " + tag)
            in_view(pg, "#trash-body td.actions button", "H1 내 휴지통 복구 단추 화면 안 " + tag, 1)
            whens = pg.evaluate("""(L) => { const f = new Function('return ' + L)(); return [...document.querySelectorAll('#trash-body tr')].slice(0, 6).map(tr => { const td = [...tr.children].find(x => /^\\d{4}-\\d{2}-\\d{2} /.test(x.textContent)); return td ? f(td) : 0; }); }""", LINES)
            step("M2 휴지통 시각 두 줄까지 " + tag, bool(whens) and max(whens) <= 2, whens)
            shot(pg, "t-trash-" + tag)
        guard("t-box 내 휴지통 " + tag, trash)

        def settings():
            global revoked_once
            go(pg, "/tbox/settings")
            no_overflow(pg, "내 계정 " + tag)
            row = pg.locator("#my-tokens-body tr", has_text=TOKEN_NAME).first
            if row.count() == 0:
                step("내 계정 — 시험 토큰 줄이 있다 " + tag, revoked_once, "토큰 줄 없음")
                shot(pg, "t-settings-" + tag)
                return
            in_view(pg, "#my-tokens-body tr td.actions button", "H1 토큰 회수 단추 화면 안 " + tag, 1)
            state_ok = row.evaluate("""(tr) => { const W = document.documentElement.clientWidth; const td = tr.children[6]; const r = td.getBoundingClientRect(); return r.width > 0 && r.right <= W + 1 && r.left >= -1 && td.textContent.trim() !== ''; }""")
            step("H1 토큰 상태 칸이 화면 안 " + tag, state_ok)
            role = pg.evaluate("() => { const e = [...document.querySelectorAll('.info-item')].find(x => x.textContent.includes('역할')); const v = e && e.querySelector('.info-item__value'); return v ? [v.textContent.trim(), v.getAttribute('title')] : null; }")
            step("M7 내 계정 역할 한글 · title 에 원래 값 " + tag, role == ["직원", "Employee"], role)
            shot(pg, "t-settings-" + tag)
            sent = []
            pg.on("request", lambda r: sent.append(r.url) if r.method == "DELETE" else None)
            # 확인 창·Esc 는 살아 있는 토큰의 「회수」 아무것으로(시험 토큰은 첫 조합에서 실제로 회수된다 — 그 뒤 조합에는 단추가 없다)
            rb = row.locator("td.actions button") if row.locator("td.actions button").count() else pg.locator("#my-tokens-body td.actions button").first
            if rb.count() == 0:
                step("내 계정 — 살아 있는 토큰의 「회수」 단추가 있다 " + tag, False, "없음")
                return
            rb.click()
            pg.wait_for_timeout(400)
            opened = pg.evaluate("() => !!document.querySelector('#confirm-dialog[open]')")
            step("H2 토큰 회수 → 확인 창(#confirm-dialog) · 브라우저 기본 창 아님 " + tag, opened)
            shot(pg, "t-settings-revoke-confirm-" + tag)
            pg.keyboard.press("Escape")
            pg.wait_for_timeout(300)
            f = pg.evaluate("() => document.activeElement && document.activeElement.textContent.trim()")
            step("H2 Esc → 요청 0 · 초점이 「회수」로 돌아옴 " + tag, not sent and f == "회수", (sent, f))
            if not revoked_once and not BEFORE and row.locator("td.actions button").count():
                rb.click()
                pg.wait_for_timeout(300)
                pg.locator("#confirm-dialog button.btn--danger").click()
                pg.wait_for_timeout(1500)
                st = pg.locator("#my-tokens-body tr", has_text=TOKEN_NAME).first.inner_text() if pg.locator("#my-tokens-body tr", has_text=TOKEN_NAME).count() else ""
                step("H2 「회수하기」 → DELETE 한 번 · 줄이 「회수됨」 " + tag, len(sent) == 1 and "회수됨" in st, (sent, st[:60]))
                revoked_once = True
        guard("내 계정 토큰 " + tag, settings)

        def chip_role():
            go(pg, "/tbox/")
            r = pg.evaluate("() => { const e = document.querySelector('.userchip__role'); return e ? [e.textContent.trim(), e.getAttribute('title')] : null; }")
            step("M7 사이드바 역할 「직원」 · title Employee " + tag, r == ["직원", "Employee"], r)
        if w == 1280:
            guard("사이드바 역할 " + tag, chip_role)

        def preview_words():
            go(pg, "/tbox/")
            pg.locator("#files-body tr", has_text=names[1]).first.locator("button[aria-label^='미리보기']").click()
            pg.wait_for_timeout(900)
            t = pg.evaluate("() => (document.querySelector('#preview-dialog .preview__info') || {}).textContent || ''")
            step("M17 미리보기 정보 판에 「Authz」「권한 근거」 0 · 「파일 지문」 " + tag, "Authz" not in t and "권한 근거" not in t and "파일 지문" in t, t[:120])
            shot(pg, "t-preview-" + tag)
            pg.keyboard.press("Escape")
        if w in (360, 1280):
            guard("미리보기 말 " + tag, preview_words)
        c.close()

        # ── 직원: 커뮤니티 홈(휴지통 창 · 템플릿 창 · 트리 배지) · 표 페이지 · 편집 모드 ─────
        c = ctx(br, S_E1, w, theme)
        pg = page_of(c)

        def chome():
            go(pg, "/community/")
            no_overflow(pg, "커뮤니티 홈 " + tag)
            vis = pg.evaluate("() => ['community-new', 'community-select', 'community-template-new'].map(i => { const e = document.getElementById(i); return e && !e.hidden; })")
            step("H3 직원에게 쓰기 단추는 그대로(새 페이지·선택하기·템플릿) " + tag, vis == [True, True, True], vis)
            n_add = pg.locator("#community-tree .page-tree__add").count()
            step("L-1 직원 트리에는 「하위 추가」 단추가 있다 " + tag, n_add > 0, n_add)
            red = pg.evaluate("() => [...document.querySelectorAll('#community-tree .badge')].filter(b => b.textContent.trim() === '비공개').length")
            step("M11 트리에 기본값 「비공개」 배지 0 " + tag, red == 0, red)
            shot(pg, "c-home-" + tag)
            pg.click("#community-more > summary")
            pg.wait_for_timeout(250)
            pg.click("#community-trash")
            pg.wait_for_timeout(900)
            in_view(pg, "#trash-body td.actions button", "H1 커뮤니티 휴지통 「되살리기」 화면 안 " + tag, 1)
            if narrow:
                x = pg.evaluate("""() => { const h = document.querySelector('#trash-dialog .modal__head') || document.querySelector('dialog[open] .modal__head'); const b = h && h.querySelector('.btn--icon');
                   if (!b) return null; const hr = h.getBoundingClientRect(), br = b.getBoundingClientRect(); return {top: Math.round(br.top - hr.top), right: Math.round(hr.right - br.right)}; }""")
                step("M4 휴지통 창 ✕ 는 머리 첫 줄 오른끝 " + tag, bool(x) and x["top"] <= 16 and x["right"] <= 16, x)
            shot(pg, "c-trash-" + tag)
            pg.keyboard.press("Escape")
            pg.wait_for_timeout(300)
            pg.click("#community-more > summary")
            pg.wait_for_timeout(250)
            pg.click("#community-template-new")
            pg.wait_for_timeout(900)
            in_view(pg, "#template-body td.actions button", "H1 템플릿 「보기·만들기」 화면 안 " + tag, 2)
            shot(pg, "c-template-" + tag)
            pg.keyboard.press("Escape")
        guard("커뮤니티 홈 " + tag, chome)

        def cpage():
            if not PID:
                return
            go(pg, "/community/p/" + PID)
            no_overflow(pg, "커뮤니티 표 페이지 " + tag)
            cells = pg.evaluate("""(L) => { const f = new Function('return ' + L)(); return [...document.querySelectorAll('#page-doc .doc-table td, #page-doc .doc-table th')].filter(td => ['담당', '김영업', '이영업', '상태', '대기'].includes(td.textContent.trim())).map(td => [td.textContent.trim(), f(td)]); }""", LINES)
            step("H4 문서 표 칸 한 줄(「김영업」·「담당」 세로 쪼개짐 0) " + tag, len(cells) >= 4 and all(n == 1 for _, n in cells), cells)
            pg.locator("#page-doc .doc-table").first.scroll_into_view_if_needed()
            pg.wait_for_timeout(200)
            shot(pg, "c-doctable-" + tag)
            go(pg, "/community/p/" + PID)
            pg.click("#page-edit")
            pg.wait_for_timeout(1500)
            one_line_head(pg, "H5 편집 모드 " + tag)
            in_view(pg, "#page-done", "H5 「편집 끝」 화면 안 " + tag, 1)
            inhead = pg.evaluate("() => !!document.querySelector('.page-head__actions #page-save-state, .page-head__actions #page-editors')")
            step("H5 저장·보는 사람 배지는 머리 동작 줄 밖 " + tag, not inhead)
            no_overflow(pg, "편집 모드 " + tag)
            pg.evaluate("() => window.scrollTo(0, 0)")
            shot(pg, "c-edit-" + tag)
            pg.click("#page-done")
            pg.wait_for_timeout(1200)
        guard("커뮤니티 페이지 " + tag, cpage)
        c.close()

        # ── 손님: 커뮤니티 홈 · 시작하기 페이지 — 쓰기 단추 0 ─────────────────
        c = ctx(br, S_G1, w, theme)
        pg = page_of(c)

        def guest():
            go(pg, "/community/")
            vis = pg.evaluate("""() => ['community-new', 'nav-community-new', 'community-select', 'community-template-new'].map(i => {
                const e = document.getElementById(i); return e ? [i, !e.hidden && !!e.getClientRects().length || (!e.hidden && i === 'nav-community-new')] : [i, false]; })""")
            step("H3 손님 홈 — 새 페이지·사이드바 새 페이지·선택하기·템플릿 0 " + tag, not any(v for _, v in vis), vis)
            n_add = pg.locator("#community-tree .page-tree__add").count()
            step("L-1 손님 트리에 「하위 추가」 단추 0(만들지 않음) " + tag, n_add == 0, n_add)
            shot(pg, "g-home-" + tag)
            if GUEST_PAGE:
                go(pg, "/community/p/" + GUEST_PAGE)
                shown = pg.evaluate("""() => ['page-edit', 'page-handover', 'page-visibility-edit', 'page-tag-input', 'page-new-child', 'page-new-db', 'page-delete', 'page-move']
                   .map(i => { const e = document.getElementById(i); return [i, !!(e && !e.hidden && e.getClientRects().length)]; }).filter(x => x[1]).map(x => x[0])""")
                step("H3 손님 시작하기 — 편집·넘기기·바꾸기·태그·하위 만들기·삭제·옮기기 0 " + tag, shown == [], shown)
                one_line_head(pg, "손님 페이지 " + tag)
                shot(pg, "g-page-" + tag)
        guard("손님 " + tag, guest)
        c.close()

        # ── 관리자: 계정 관리(표 · 토큰 창) · 임베드 창 ✕ ─────────────────
        c = ctx(br, S_A1, w, theme)
        pg = page_of(c)

        def users():
            go(pg, "/tbox/users")
            no_overflow(pg, "계정 관리 " + tag)
            in_view(pg, "#users-body tr:nth-child(-n+4) td.actions button", "H1 계정 관리 단추 다섯이 화면 안 " + tag, 12)
            roles = pg.evaluate("() => [...document.querySelectorAll('#users-body tr')].slice(0, 6).map(tr => [tr.children[2].textContent.trim(), tr.children[2].getAttribute('title')])")
            step("M7 계정 표 역할 한글 · title 에 원래 값 " + tag,
                 bool(roles) and all(t in ("관리자", "팀장", "직원", "손님") and ti in ("Admin", "Manager", "Employee", "Guest") for t, ti in roles), roles[:3])
            opts = pg.evaluate("() => [...document.querySelectorAll('#u-role option')].map(o => [o.value, o.textContent.trim()])")
            step("M7 역할 고르개 글자 한글 · 값은 그대로 " + tag, bool(opts) and all(v in t and not t == v for v, t in opts if v), opts)
            pg.locator("#users-body tr:first-child").scroll_into_view_if_needed()
            shot(pg, "a-users-" + tag)
            # 토큰 창 — claude_employee01 줄
            row = pg.locator("#users-body tr", has_text="claude_employee01").first
            row.scroll_into_view_if_needed()
            row.locator("button", has_text="프로그램 토큰").click()
            pg.wait_for_timeout(1000)
            in_view(pg, "#token-dialog td.actions button", "H1 토큰 창 「회수」 화면 안 " + tag, 1)
            st = pg.evaluate("""() => { const W = document.documentElement.clientWidth; return [...document.querySelectorAll('#token-dialog tbody tr')].map(tr => { const td = tr.children[6]; if (!td) return false; const r = td.getBoundingClientRect(); return r.width > 0 && r.right <= W + 1 && r.left >= -1; }); }""")
            step("H1 토큰 창 상태 칸이 화면 안 " + tag, bool(st) and all(st), st)
            shot(pg, "a-tokens-" + tag)
            sent = []
            pg.on("request", lambda r: sent.append(r.url) if r.method == "DELETE" else None)
            live = pg.locator("#token-dialog td.actions button").first
            if live.count():
                live.click()
                pg.wait_for_timeout(300)
                cf = pg.evaluate("() => { const n = document.querySelector('#token-dialog .notice'); return n ? [!n.hidden, document.activeElement && document.activeElement.textContent.trim()] : null; }")
                step("H2 토큰 창 회수 → 창 안 확인 줄 · 처음 초점 「그만두기」 · 브라우저 기본 창 아님 " + tag, cf == [True, "그만두기"], cf)
                shot(pg, "a-tokens-confirm-" + tag)
                pg.locator("#token-dialog .notice button", has_text="그만두기").click()
                pg.wait_for_timeout(300)
                back = pg.evaluate("() => [document.querySelector('#token-dialog .notice').hidden, document.activeElement && document.activeElement.textContent.trim()]")
                step("H2 「그만두기」 → 요청 0 · 확인 줄 닫힘 · 초점 「회수」 " + tag, back == [True, "회수"] and not sent, (back, sent))
            pg.keyboard.press("Escape")
            pg.wait_for_timeout(300)
        guard("계정 관리 " + tag, users)

        def embed_head():
            if not narrow:
                return
            go(pg, "/community/")
            pg.click("#community-more > summary")
            pg.wait_for_timeout(250)
            pg.click("#community-embed-admin")
            pg.wait_for_timeout(900)
            x = pg.evaluate("""() => { const h = document.querySelector('#embed-dialog .modal__head'); const b = h && h.querySelector('.btn--icon');
               if (!b) return null; const hr = h.getBoundingClientRect(), br = b.getBoundingClientRect(); return {top: Math.round(br.top - hr.top), right: Math.round(hr.right - br.right)}; }""")
            step("M4 임베드 창 ✕ 는 머리 첫 줄 오른끝 " + tag, bool(x) and x["top"] <= 16 and x["right"] <= 16, x)
            shot(pg, "a-embed-" + tag)
            pg.keyboard.press("Escape")
        guard("임베드 창 " + tag, embed_head)

        def review_m1():
            if (w, theme) != (1280, "light") or BEFORE:
                return
            # ⓐ 팀 목록이 늦게 온다 — /api/teams 를 붙잡아 둔 채 창을 열고, 설명만 고친 뒤 목록을 풀고 저장
            held = []
            pg.route("**/tbox/api/teams", lambda r: held.append(r))
            bodies = []
            pg.on("request", lambda r: bodies.append(r.post_data) if r.method == "PATCH" and "/api/files/" in r.url else None)
            pg.goto(SITE + "/tbox/", wait_until="domcontentloaded")
            pg.wait_for_selector("#files-body tr", timeout=20000)
            pg.wait_for_timeout(500)
            row = pg.locator("#files-body tr", has_text=TEAM_FILE).first
            row.locator("button[aria-label='수정']").click()
            pg.wait_for_timeout(300)
            v0 = pg.evaluate("() => { const s = document.getElementById('file-edit-team'); return [s.value, [...s.options].map(o => [o.value, o.hasAttribute('data-edit-current')])]; }")
            step("M-1ⓐ 목록 전에 연 창 — 팀 고르개 = 파일의 팀(「지금 팀」 자리)", v0[0] == str(MY_TEAM), v0)
            pg.fill("#file-edit-description", "DBGU M-1 늦은 목록 " + TAG)
            for r in held:
                r.continue_()
            pg.wait_for_timeout(1200)
            v1 = pg.evaluate("() => { const s = document.getElementById('file-edit-team'); return [s.value, s.querySelectorAll('option[data-edit-current]').length, s.options.length]; }")
            step("M-1ⓐ 목록이 온 뒤에도 고르개 = 파일의 팀 · 「지금 팀」 자리는 진짜 선택지로 바뀜", v1[0] == str(MY_TEAM) and v1[1] == 0 and v1[2] > 1, v1)
            pg.click("#file-edit-submit")
            pg.wait_for_timeout(1200)
            body = json.loads(bodies[-1]) if bodies else {}
            step("M-1ⓐ 설명만 고치면 PATCH 본문 = description 하나(team_id 없음 — 관리자 · 늦은 목록)", list(body.keys()) == ["description"], bodies[-1:] )
            pg.unroute("**/tbox/api/teams")
            # ⓑ 파일의 팀이 목록에 없다(쓰지 않는 팀) — 목록에서 그 팀을 빼 준다
            def drop_team(route):
                resp = route.fetch()
                data = resp.json()
                data["teams"] = [t for t in data.get("teams", []) if str(t.get("id")) != str(MY_TEAM)]
                route.fulfill(response=resp, json=data)
            pg.route("**/tbox/api/teams", drop_team)
            go(pg, "/tbox/")
            row = pg.locator("#files-body tr", has_text=TEAM_FILE).first
            row.locator("button[aria-label='수정']").click()
            pg.wait_for_timeout(300)
            v2 = pg.evaluate("() => { const s = document.getElementById('file-edit-team'); const o = s.selectedOptions[0]; return [s.value, o && o.hasAttribute('data-edit-current'), o && o.textContent]; }")
            step("M-1ⓑ 목록에 없는 팀 — 「지금 팀(쓰지 않는 팀)」 자리를 고른 채 열림", v2[0] == str(MY_TEAM) and v2[1] is True and "지금 팀" in (v2[2] or ""), v2)
            n0 = len(bodies)
            pg.fill("#file-edit-description", "DBGU M-1 쓰지 않는 팀 " + TAG)
            pg.click("#file-edit-submit")
            pg.wait_for_timeout(1200)
            body = json.loads(bodies[-1]) if len(bodies) > n0 else {}
            step("M-1ⓑ 설명만 고치면 PATCH 본문 = description 하나(team_id 없음 — 쓰지 않는 팀)", list(body.keys()) == ["description"], bodies[n0:])
            pg.unroute("**/tbox/api/teams")
            # ⓒ 등급을 team 으로 바꿀 때는 team_id 가 실린다(직원 파일 · 관리자 문맥)
            go(pg, "/tbox/")
            row = pg.locator("#files-body tr", has_text=names[1]).first
            row.locator("button[aria-label='수정']").click()
            pg.wait_for_timeout(300)
            pg.select_option("#file-edit-level", "team")
            pg.select_option("#file-edit-team", str(MY_TEAM))
            n0 = len(bodies)
            pg.click("#file-edit-submit")
            pg.wait_for_timeout(1200)
            body = json.loads(bodies[-1]) if len(bodies) > n0 else {}
            step("M-1ⓒ 등급을 team 으로 바꾸면 access_level·team_id 가 실린다", sorted(body.keys()) == ["access_level", "team_id"] and body.get("team_id") == str(MY_TEAM), bodies[n0:])
            # ⓓ 디버깅 묶음 S-8 과 합친 길 — 첫 PATCH 가 403 next:reauth 면 재인증 창 → 통과 뒤 **같은 본문**을 다시 보내고 수정 창이 닫힌다
            state = {"n": 0}
            def once_reauth(route):
                state["n"] += 1
                if state["n"] == 1:
                    route.fulfill(status=403, json={"error": "관리 기능을 쓰려면 비밀번호를 다시 확인하세요", "next": "reauth"})
                else:
                    route.continue_()
            pg.route("**/tbox/api/files/*", lambda r: once_reauth(r) if r.request.method == "PATCH" else r.continue_())
            go(pg, "/tbox/")
            row = pg.locator("#files-body tr", has_text=TEAM_FILE).first
            row.locator("button[aria-label='수정']").click()
            pg.wait_for_timeout(300)
            n0 = len(bodies)
            n_err = len(errors)   # 이 절이 일부러 낸 403 한 줄(브라우저가 콘솔에 「리소스 403」)은 오류로 세지 않는다
            pg.fill("#file-edit-description", "DBGU S-8 합침 " + TAG)
            pg.click("#file-edit-submit")
            pg.wait_for_timeout(800)
            re_open = pg.evaluate("() => !!document.querySelector('#reauth-dialog-form') && !!document.querySelector('#reauth-dialog-form').closest('dialog[open]')")
            step("S-8 합침 — 403 next:reauth 면 재인증 창이 뜨고 수정 창은 열린 채", re_open and pg.evaluate("() => !!document.querySelector('#file-edit-dialog[open]')"), re_open)
            pg.fill("#reauth-dialog-form input[type=password]", PW_A1)
            pg.locator("#reauth-dialog-form").evaluate("f => f.requestSubmit()")
            pg.wait_for_timeout(1800)
            sent = [json.loads(b) for b in bodies[n0:] if b]
            closed = not pg.evaluate("() => !!document.querySelector('#file-edit-dialog[open]')")
            step("S-8 합침 — 재인증 뒤 같은 본문으로 다시 보냄(두 번 모두 description 하나) · 수정 창 닫힘",
                 len(sent) == 2 and sent[0] == sent[1] and list(sent[0].keys()) == ["description"] and closed, (sent, closed))
            pg.unroute("**/tbox/api/files/*")
            errors[n_err:] = [e for e in errors[n_err:] if "status of 403" not in e]

        def review_l3():
            if (w, theme) != (1280, "light") or BEFORE:
                return
            # 폴드처럼 hover 를 알리는 터치 기기 — 주 포인터 fine · hover: hover · 보조 포인터 coarse
            cdp = pg.context.new_cdp_session(pg)
            cdp.send("Emulation.setEmulatedMedia", {"features": [{"name": "hover", "value": "hover"}, {"name": "pointer", "value": "fine"},
                                                                 {"name": "any-pointer", "value": "coarse"}]})
            go(pg, "/tbox/")
            mm = pg.evaluate("() => [matchMedia('(hover: hover)').matches, matchMedia('(pointer: fine)').matches, matchMedia('(any-pointer: coarse)').matches]")
            star = pg.locator("#files-body tr:first-child button[data-fav-file]").first
            star.hover()
            pg.wait_for_timeout(200)
            d = star.evaluate("(b) => getComputedStyle(b, '::after').display")
            rule = pg.evaluate("""() => { for (const sh of document.styleSheets) { let rs; try { rs = sh.cssRules; } catch (e) { continue; }
                for (const r of rs) { if (r.media && /any-pointer: coarse/.test(r.conditionText || r.media.mediaText) && /btn--icon\\[aria-label\\]:hover:not\\(:focus-visible\\)::after/.test(r.cssText)) return true; } } return false; }""")
            if mm == [True, True, True]:
                step("L-3 hover 를 알리는 터치 기기(any-pointer: coarse)에서 올려도 툴팁 안 뜸", d == "none", (mm, d))
            else:
                step("L-3 (흉내 안 됨 %s) — any-pointer: coarse 에서 툴팁 끄는 규칙이 있다" % mm, rule, rule)
            cdp.send("Emulation.setEmulatedMedia", {"features": []})
            go(pg, "/tbox/")
            star = pg.locator("#files-body tr:first-child button[data-fav-file]").first
            star.hover()
            pg.wait_for_timeout(200)
            d2 = star.evaluate("(b) => getComputedStyle(b, '::after').display")
            step("L-3 마우스만(1280)은 올리면 툴팁이 그대로 보인다", d2 == "block", d2)

        guard("검토 M-1 " + tag, review_m1)
        guard("검토 L-3 " + tag, review_l3)

        def admin_badge():
            go(pg, "/tbox/admin")
            t = pg.evaluate("() => (document.getElementById('admin-reauth') || {}).textContent || ''")
            step("M17 관리자 페이지 배지에 「15분 창」 0 " + tag, "창" not in t and t != "", t)
        if w == 1280:
            guard("관리자 배지 " + tag, admin_badge)
        c.close()

        # ── 로그인 2단계 코드 틀림(관리자) — 새 문맥, 세션 없음 ────────────────
        if (w, theme) in ((412, "dark"), (1280, "light")):
            c = ctx(br, None, w, theme)
            pg = page_of(c, login_screen=True)

            def login_otp():
                go(pg, "/tbox/login")
                pg.fill("#username", "claude_admin01")
                pg.fill("#password", PW_A1)
                pg.click("#login-submit")
                pg.wait_for_timeout(1500)
                if not pg.is_visible("#otp"):
                    step("M13 2단계 칸이 열린다 " + tag, False, pg.inner_text("#msg"))
                    return
                pg.fill("#otp", "000000")
                pg.click("#login-submit")
                pg.wait_for_timeout(1500)
                m = pg.inner_text("#msg").strip()
                pw_kept = pg.evaluate("() => document.getElementById('password').value.length > 0")
                # 독립 검토 L-4 — 비밀번호 칸을 비우지 않으니 문장이 비밀번호·인증 코드 둘 다를 가리킨다(계정 있음은 말하지 않는다)
                step("M13·L-4 2단계에서 틀리면 「비밀번호나 인증 코드」 문장(「아이디 또는 비밀번호」 아님) " + tag,
                     "인증 코드" in m and "비밀번호" in m and "아이디 또는 비밀번호" not in m, m)
                step("M13 비밀번호 칸은 비우지 않는다 · 2단계에 머문다 " + tag, pw_kept and pg.is_visible("#otp"), pw_kept)
                shot(pg, "login-otp-wrong-" + tag)
            guard("로그인 " + tag, login_otp)
            c.close()
    br.close()

print("\n── 공통")
step("브라우저 기본 창(alert·prompt·confirm) 0건", not dialogs, dialogs[:5])
step("콘솔 오류·pageerror 0건", not errors, errors[:6])
print("\n통과 %d · 실패 %d" % (passes, fails))
print("SMOKE_FAIL=%d" % fails)
sys.exit(0 if BEFORE else (1 if fails else 0))
