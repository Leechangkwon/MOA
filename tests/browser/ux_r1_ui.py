#!/usr/bin/env python3
# tests/browser/ux_r1_ui.py — 사용감 라운드 1 「빠른 승리」(사용자 2026-09-28 10:45 「사용감을 최적화하라」 · 실측 보고서 ux-audit-2026-09-28)를
#                            진짜 브라우저로 눌러 본다 — 수락 기준 Q:T-11 ~ Q:T-21
#
# 부르는 쪽: tests/ux_r1_ui.sh (임시 인스턴스 · 임시 DB `tbox_uxr1_*`. 운영 80·8082·8081 에는 요청하지 않는다).
# 쓰는 법: py_secret S_E1 S_E2 S_M1 S_A1 S_G1 PW_E1 SEC_E1 PW_E2 SEC_E2 -- python3 ux_r1_ui.py <SITE> <UA> <SHOTS> [before]
#   세션 다섯 · 직원 둘의 비밀번호와 2단계 비밀은 **표준입력 아홉 줄**(R-62 — 명령 인자에 비밀 0 · 출력 0).
#   환경 UXR1_DB = 임시 DB 이름(감사 행 세기 — tbox_testdb 역할 · PGPASSFILE) · UXR1_COMBOS = 폭:색 목록(기본 여덟)
#   넷째 인자 `before` 는 고치기 전 판을 찍을 때 — 판정은 참고용이고 스크린샷만 쓴다(종료 코드 0).
# 폭·색: 360(780) · 412(915) — 폰 터치 · 820 — 폴드 펼침 터치(pointer: coarse) · 1280 × 라이트 · 다크
# 보는 것(Q:T 번호 = 지시서 「고칠 것」 번호 + 10)
#   공통     가로 넘침 0 · 콘솔 오류·pageerror 0 · 브라우저 기본 창 0 · (터치) 새 단추 누름 자리 44 · 보이는 상자 안 어디를 눌러도 그 자신(U2 방식)
#   Q:T-11   새 페이지 제목 — 빈 칸 + 자리표시 「제목」 · 커서 제목 칸 · Enter → 본문 첫 줄 · 제목 저장 · 비워 두면 서버 기본값 그대로 ·
#            기존 기본 제목 페이지 「편집」 = 빈 칸 + 커서 본문 · 트리 「하위 추가」(1280) = 편집 모드 + 제목 칸
#   Q:T-12   좁은 폭 편집 머리 — 412×915 새 페이지 본문 첫 줄 top ≤ 300 · 큰 제목·경로·「제목」 이름표 숨김 · 사용 방법·이력은 더보기 안 ·
#            머리 한 줄 · 속성 요약 한 줄 · 읽기 모드와 720 이상은 그대로
#   Q:T-13   알림 — 폰 윗줄 종 + 안 읽은 수(사이드바와 같은 값) · ☰ aria-label · 종 → 같은 알림 창 · 첫 화면 알림 수 요청 1(새 요청 0) · 1280 은 윗줄 없음
#   Q:T-14   「내 공간」 「최근」 다섯 줄 — /api/me/recent 앞 다섯과 같은 차례 · 누르면 그 페이지 · 첫 화면 recent 요청 1 · 비면 숨김
#   Q:T-15   블록 창 — 보이는 순서 · 그림·파일이 목록을 밀지 않고 보임 · 값 없는 블록 한 번 누르기 · 값 있는 블록 두 단계 · 키보드 ↑↓ Enter · 블록 바꾸기
#   Q:T-16   앱 전환 — 윗줄 앱 이름 → 서랍의 앱 메뉴 · 다른 앱에 초점 → 두 번에 t-box
#   Q:T-17   2단계 코드 자동 제출 — 여섯 자리에서 한 번 · 틀리면 칸 비우고 초점 · 자동 제출과 Enter 가 겹쳐도 요청 한 번
#   Q:T-18   검색 — 윗줄 검색 → 같은 창 · 입력 칸 초점 · 터치 기기 「Ctrl K」 숨김 · 1280 은 보임
#   Q:T-19   t-box 올리기 — 모두 성공하면 창 닫힘 · 새 줄 강조(화면 안 · 2.5초 뒤 풀림 · 움직임 0) · 다시 열면 고른 파일 0 · 하나라도 거부면 창 그대로
#   Q:T-20   게시판 글 상태 글자 — 「게시됨 · 사내 모두 봄」(title 원래 값) · 숨긴 글 「숨김 · 쓴 사람·게시판 관리자만 봄」 · 편집 모드 요약에도
#   Q:T-21   다른 탭에서 다시 로그인 — 같은 계정: 옛 탭 저장 성공 · 그 사이 csrf deny ≤ 1 · heartbeat 2분 더 추가 deny 0 ·
#            다른 계정: 옛 탭 저장은 한 번만 나가고(다시 보내지 않음) 안내 한 줄 · 그 뒤 쓰기 요청 0
#            + 경계 경우(route 모의 — 이 브라우저 문맥 안에서만 답을 바꾼다): S1 서버가 계속 403 → 다시 1 뒤 멈춤(고리 0) · S2 재인증 403 비켜 감 ·
#            S3 /api/me 401 → 로그인 흐름·임시저장 · S4 저장+heartbeat 동시 403 → /api/me 1 · S5′ 경합(보낸 토큰으로 견줌 → /api/me 추가 0 · 저장됨) ·
#            S6 다른 계정 → 다시 0 + 안내 · S7 heartbeat 가 계속 403(130초 · 사람 입력 0) → /api/me 자동 표식 없는 요청 0 · 세션 활동 시각 그대로
#            (임시 DB sessions 행 · 대조로 사람 요청 한 번이면 밀림) · 같은 토큰으로 same 뒤 사람 403 도 다시 묻지 않음 · 탭이 다시 보이면 한 번 다시(표식) ·
#            S8 t-box 파일 화면 writeFetch(옛 토큰 → 한 번 다시 · 같은 토큰의 진짜 403 은 두 번째부터 묻지 않음)
# 나가는 값: 마지막 줄 `SMOKE_FAIL=<n>` · 종료 코드 0/1
import json
import os
import re
import subprocess
import sys
import time
import hmac
import hashlib
import base64
import struct
import urllib.error
import urllib.request
import uuid

from playwright.sync_api import sync_playwright

SITE = sys.argv[1].rstrip("/")
UA = sys.argv[2]
SHOTS = sys.argv[3]
BEFORE = len(sys.argv) > 4 and sys.argv[4] == "before"
_in = [sys.stdin.readline().rstrip("\n") for _ in range(9)]
S_E1, S_E2, S_M1, S_A1, S_G1, PW_E1, SEC_E1, PW_E2, SEC_E2 = _in
if not all(_in):
    print("세션 다섯 · 비밀번호·2단계 비밀 둘씩을 표준입력 아홉 줄로 주어야 한다 — 시작하지 않는다")
    sys.exit(2)
assert re.match(r"^http://127\.0\.0\.1:839[1-5]$", SITE), "임시 인스턴스(8391~8395)만"
HOST = "127.0.0.1"
DB = os.environ.get("UXR1_DB", "")
COMBOS = [(int(w), t) for w, t in (c.split(":") for c in (os.environ.get("UXR1_COMBOS") or "360:light,360:dark,412:light,412:dark,820:light,820:dark,1280:light,1280:dark").split(","))]
os.makedirs(SHOTS, exist_ok=True)
fails = 0
passes = 0
dialogs = []
errors = []
TAG = uuid.uuid4().hex[:6]


def step(name, ok, detail=""):
    global fails, passes
    if BEFORE:
        print("  %s %s%s" % ("·" if ok else "○", name, ("  — " + str(detail)[:300]) if (detail != "" and not ok) else ""), flush=True)
        return
    if ok:
        passes += 1
    else:
        fails += 1
    print("  %s %s%s" % ("✔" if ok else "✖", name, ("  — " + str(detail)[:400]) if (detail != "" and not ok) else ""), flush=True)


def note(msg):
    print("  · " + msg, flush=True)


def audit_q(sql):
    """임시 DB(UXR1_DB) 읽기 — tbox_testdb 역할 · PGPASSFILE(경로) · SQL 은 표준입력"""
    if not re.match(r"^tbox_uxr1_[a-z0-9_]+$", DB):
        return None
    env = {k: v for k, v in os.environ.items() if k not in ("PGHOST", "PGPORT", "PGUSER", "PGDATABASE", "PGPASSWORD", "PGSERVICE", "PGOPTIONS")}
    env["PGPASSFILE"] = os.path.expanduser("~/.config/qaredi/pgpass-testdb")
    r = subprocess.run(["psql", "-h", "127.0.0.1", "-U", "tbox_testdb", "-w", "-X", "-q", "-At", "-d", DB], input=sql, capture_output=True, text=True, env=env, timeout=30)
    return r.stdout.strip()


def totp(secret):
    s = secret.strip().replace(" ", "").upper(); s += "=" * (-len(s) % 8)
    key = base64.b32decode(s); t = int(time.time()) // 30
    h = hmac.new(key, struct.pack(">Q", t), hashlib.sha1).digest(); o = h[-1] & 15
    return "%06d" % ((struct.unpack(">I", h[o:o + 4])[0] & 0x7fffffff) % 1000000)


LAST_STEP = {}


def fresh_code(who, secret):
    """이 계정에 앞서 쓴 창(30초)과 다른 창의 코드 — 같은 창이면 다음 창까지 기다린다(재사용 거부 R-45). 여유 4초 미만이면 다음 창."""
    while True:
        now = time.time()
        stp = int(now) // 30
        left = 30 - int(now) % 30
        if stp != LAST_STEP.get(who) and left >= 4:
            LAST_STEP[who] = stp
            return totp(secret)
        time.sleep(left + 0.5)


class Api:
    def __init__(self, sess):
        self.sess = sess
        self.csrf = ""
        c, me = self.req("GET", "/tbox/api/me")
        self.me = me if c == 200 else {}
        self.csrf = self.me.get("csrf_token", "")

    def req(self, method, path, body=None):
        h = {"User-Agent": UA, "Cookie": "__Host-tbox_session=" + self.sess}
        if self.csrf and method != "GET":
            h["X-CSRF-Token"] = self.csrf
        data = None
        if body is not None:
            data = json.dumps(body).encode()
            h["Content-Type"] = "application/json"
        try:
            with urllib.request.urlopen(urllib.request.Request(SITE + path, data=data, method=method, headers=h), timeout=60) as r:
                code, txt = r.status, r.read().decode("utf-8", "replace")
        except urllib.error.HTTPError as e:
            code, txt = e.code, e.read().decode("utf-8", "replace")
        try:
            return code, json.loads(txt or "{}")
        except ValueError:
            return code, {"_raw": txt[:200]}


class MeMock:
    """Q:T-21 모의 — 브라우저 문맥의 `/tbox/api/me` 답을 바꾼다(Playwright route · 임시 인스턴스). mode:
       None 그대로 · 'stale' 토큰을 옛 값처럼(부팅 때 — 옛 탭 상태) · 'fresh-each' 부를 때마다 새 토큰 · 'other' 아이디를 다른 사람으로 · '401'
       바꾼 토큰은 서버가 모르는 글자다(쓰기는 서버에서 403) — 값을 출력하지 않는다."""
    def __init__(self):
        self.mode = None
        self.stale = "stale-" + uuid.uuid4().hex

    def handle(self, route):
        if self.mode is None:
            return route.continue_()
        if self.mode == "401":
            return route.fulfill(status=401, headers={"Content-Type": "application/json", "Cache-Control": "no-store"}, body=json.dumps({"error": "로그인이 필요합니다"}))
        resp = route.fetch()
        try:
            d = resp.json()
        except Exception:
            return route.fulfill(response=resp)
        if self.mode == "stale":
            d["csrf_token"] = self.stale
        elif self.mode == "fresh-each":
            d["csrf_token"] = "fake-" + uuid.uuid4().hex
        elif self.mode == "other":
            d["username"] = "claude_someone_else"
        return route.fulfill(response=resp, body=json.dumps(d))


def session_row_id(c):
    """브라우저 문맥의 세션 쿠키 → sessions.id(서버와 같이 sha256 · 값은 출력하지 않는다)"""
    for ck in c.cookies():
        if ck.get("name") == "__Host-tbox_session" and ck.get("value"):
            return hashlib.sha256(ck["value"].encode()).hexdigest()
    return ""


def session_seen(sid):
    """임시 DB 의 그 세션 행 두 활동 시각(last_seen_at · act_last_seen_at) — 글자 하나로"""
    if not re.match(r"^[0-9a-f]{64}$", sid or ""):
        return ""
    v = audit_q("SELECT coalesce(to_char(last_seen_at, 'YYYY-MM-DD HH24:MI:SS.US'), '-') || ' / ' || coalesce(to_char(act_last_seen_at, 'YYYY-MM-DD HH24:MI:SS.US'), '-') FROM sessions WHERE id = '%s';" % sid)
    return v or ""


def T(t):
    return [{"t": t, "m": []}]


def pid_of(d):
    return ((d.get("page") or {}).get("id")) if isinstance(d, dict) else None


E = Api(S_E1)
E2 = Api(S_E2)
M = Api(S_M1)
A = Api(S_A1)
G = Api(S_G1)
print("── 준비 (임시 DB 에만 쓴다)")
step("다섯 계정 /api/me 200", bool(E.csrf and E2.csrf and M.csrf and A.csrf and G.csrf), (bool(E.csrf), bool(E2.csrf), bool(M.csrf), bool(A.csrf), bool(G.csrf)))
E1_NAME = E.me.get("username", "claude_employee01")

# 기본 제목 페이지(제목 없이 만듦) · 제목 있는 페이지 · 편집 대상 · 하위 추가용 부모
c, d = E.req("POST", "/community/api/pages", {})
P_DEFAULT = pid_of(d)
step("준비 — 제목 없이 만든 페이지의 서버 제목 = 「제목 없는 페이지」", c == 201 and (d.get("page") or {}).get("title") == "제목 없는 페이지", (c, str(d)[:120]))
c, d = E.req("POST", "/community/api/pages", {"title": "UXR1 글 " + TAG})
P_EDIT = pid_of(d)
c, d = E.req("GET", "/community/api/pages/" + str(P_EDIT))
ver = (d.get("page") or {}).get("doc_version", 1)
E.req("PATCH", "/community/api/pages/" + str(P_EDIT), {"doc_version": ver, "title": "UXR1 글 " + TAG,
      "doc": {"schema": 1, "blocks": [{"id": str(uuid.uuid4()), "type": "paragraph", "text": T("첫 문단 — 사용감 라운드 1 시험 글")}]}})
# 팀장 글(사내) + 직원 댓글 둘 → 팀장 안 읽은 알림
c, d = M.req("POST", "/community/api/pages", {"title": "UXR1 팀장 글 " + TAG})
P_MGR = pid_of(d)
M.req("PATCH", "/community/api/pages/%s/visibility" % P_MGR, {"visibility": "internal"})
cm = [E.req("POST", "/community/api/pages/%s/comments" % P_MGR, {"body": "UXR1 댓글 %d" % i})[0] for i in range(2)]
step("준비 — 팀장 글에 직원 댓글 둘(201)", cm == [201, 201], cm)
# 게시판 글 둘(사내 게시판) — 하나는 관리자가 숨긴다
c, d = E.req("GET", "/community/api/boards")
BOARD = next((b for b in (d.get("boards") or []) if b.get("access_level") == "internal" and b.get("can_write") and b.get("board_kind") != "notice"), None)
BP = BP2 = None
if BOARD:
    c, d = E.req("POST", "/community/api/boards/%d/pages" % BOARD["id"], {"title": "UXR1 게시판 글 " + TAG})
    BP = pid_of(d)
    c, d = E.req("POST", "/community/api/boards/%d/pages" % BOARD["id"], {"title": "UXR1 숨길 글 " + TAG})
    BP2 = pid_of(d)
    ch, dh = A.req("POST", "/community/api/pages/%s/hide" % BP2, {})
    step("준비 — 관리자가 게시판 글 하나를 숨김(200)", ch == 200, (ch, str(dh)[:160]))
step("준비 — 사내 게시판에 글 둘", bool(BP and BP2), (BOARD, BP, BP2))
# Q:T-21 전용 글(다른 시험이 블록 모양을 바꾸지 않는 글)
c, d = E.req("POST", "/community/api/pages", {"title": "UXR1 세션 글 " + TAG})
P_SESS = pid_of(d)
c, d = E.req("GET", "/community/api/pages/" + str(P_SESS))
E.req("PATCH", "/community/api/pages/" + str(P_SESS), {"doc_version": (d.get("page") or {}).get("doc_version", 1), "title": "UXR1 세션 글 " + TAG,
      "doc": {"schema": 1, "blocks": [{"id": str(uuid.uuid4()), "type": "paragraph", "text": T("세션 시험 문단")}]}})
# Q:T-21 모의(경계 경우) 전용 글
c, d = E.req("POST", "/community/api/pages", {"title": "UXR1 세션 모의 " + TAG})
P_MOCK = pid_of(d)
c, d = E.req("GET", "/community/api/pages/" + str(P_MOCK))
E.req("PATCH", "/community/api/pages/" + str(P_MOCK), {"doc_version": (d.get("page") or {}).get("doc_version", 1), "title": "UXR1 세션 모의 " + TAG,
      "doc": {"schema": 1, "blocks": [{"id": str(uuid.uuid4()), "type": "paragraph", "text": T("모의 시험 문단")}]}})
step("준비 — Q:T-21 모의 전용 글", bool(P_SESS and P_MOCK), (P_SESS, P_MOCK))
# 최근: 직원이 P_EDIT 를 연다(창구가 최근 줄을 남긴다) · 손님은 최근을 지운다(빈 판 숨김 시험)
E.req("GET", "/community/api/pages/" + str(P_EDIT))
cg, _ = G.req("DELETE", "/community/api/me/recent", {})
note("손님 최근 지우기 %s" % cg)

# ─── 측정 도우미 ──────────────────────────────────────────────────
OVER = "() => document.documentElement.scrollWidth - document.documentElement.clientWidth"
TOUCH = r"""(sels) => {
  const W = document.documentElement.clientWidth, H = window.innerHeight;
  const out = []; const stolen = []; let n = 0;
  const IA = 'button, a[href], summary, input, select, textarea, label';
  const selfHit = (e, r, name) => {
    const cx = (r.left + r.right) / 2, cy = (r.top + r.bottom) / 2;
    for (const [x, y, where] of [[cx, r.top + 1, '위'], [cx, cy, '가운데'], [cx, r.bottom - 1, '아래'], [r.left + 1, cy, '왼'], [r.right - 1, cy, '오른']]) {
      if (x < 1 || y < 1 || x > W - 1 || y > H - 1) continue;
      const hit = document.elementFromPoint(x, y);
      if (!hit || hit === e || e.contains(hit)) continue;
      const other = hit.closest(IA);
      if (!other || other === e || other.contains(e)) continue;
      stolen.push([name, where, (other.getAttribute('aria-label') || other.textContent || other.tagName).trim().replace(/\s+/g, ' ').slice(0, 16)]);
      return;
    }
  };
  const box = (e) => {
    const r = e.getBoundingClientRect();
    let L = r.left, T = r.top, R = r.right, B = r.bottom;
    const s = getComputedStyle(e, '::before');
    if (s.content && s.content !== 'none' && s.position === 'absolute') {
      const t = parseFloat(s.top), l = parseFloat(s.left), rr = parseFloat(s.right), b = parseFloat(s.bottom);
      if (isFinite(t)) T = Math.min(T, r.top + t);
      if (isFinite(b)) B = Math.max(B, r.bottom - b);
      if (isFinite(l)) L = Math.min(L, r.left + l);
      if (isFinite(rr)) R = Math.max(R, r.right - rr);
    }
    return [R - L, B - T];
  };
  for (const sel of sels) {
    for (const e of document.querySelectorAll(sel)) {
      const cs = getComputedStyle(e);
      if (cs.visibility === 'hidden' || cs.display === 'none' || e.closest('[hidden]')) continue;
      const r = e.getBoundingClientRect();
      if (r.width < 2 || r.height < 2 || r.bottom < 0 || r.top > H) continue;
      // 스크롤 칸에 가려 가운데가 보이지 않는 것(목록 아래로 잘린 줄)은 누를 수 없는 자리 — dbg_u2_ui TOUCH 와 같이 건너뛴다
      const c0 = document.elementFromPoint(Math.min(W - 1, Math.max(0, (r.left + r.right) / 2)), Math.min(H - 1, Math.max(0, (r.top + r.bottom) / 2)));
      if (!c0 || !(c0 === e || e.contains(c0) || c0.contains(e))) continue;
      n++;
      const [w, h] = box(e);
      const name = (e.getAttribute('aria-label') || e.textContent || e.tagName).trim().replace(/\s+/g, ' ').slice(0, 18);
      selfHit(e, r, name);
      if (w < 43.5 || h < 43.5) out.push([name, Math.round(w), Math.round(h)]);
    }
  }
  return {n: n, bad: out, stolen: stolen};
}"""
VIS = "(sel) => { const e = document.querySelector(sel); if (!e) return false; const cs = getComputedStyle(e); if (cs.display === 'none' || cs.visibility === 'hidden') return false; const r = e.getBoundingClientRect(); return r.width > 1 && r.height > 1; }"   # 넓은 화면의 사이드바는 hidden 속성이 있어도 CSS 가 펼친다 — 조상 [hidden] 대신 실제 상자로 본다
RECT = "(sel) => { const e = document.querySelector(sel); if (!e) return null; const r = e.getBoundingClientRect(); return [Math.round(r.left), Math.round(r.top), Math.round(r.width), Math.round(r.height)]; }"
BODY_TOP = "() => { const pm = document.querySelector('.ProseMirror'); const f = pm && (pm.firstElementChild || pm); return f ? Math.round(f.getBoundingClientRect().top) : null; }"


def touch(pg, what, sels):
    try:
        r = pg.evaluate(TOUCH, sels)
    except Exception as ex:
        step(what, False, str(ex)[:160])
        return
    step("터치 44px — %s (누르는 것 %d개)" % (what, r["n"]), r["n"] > 0 and not r["bad"], "%d개 모자람 %s" % (len(r["bad"]), r["bad"][:8]))
    step("보이는 상자 안 어디를 눌러도 자기 자신 — %s" % what, not r["stolen"], "%d개 %s" % (len(r["stolen"]), r["stolen"][:6]))


def no_overflow(pg, what):
    try:
        o = pg.evaluate(OVER)
    except Exception as ex:
        o = str(ex)[:80]
    step(what + " — 가로 넘침 0", isinstance(o, int) and o <= 0, o)


def shot(pg, name, full=False):
    try:
        pg.screenshot(path=os.path.join(SHOTS, name + ".png"), full_page=full)
    except Exception as ex:
        print("  · 스크린샷 실패 %s %s" % (name, str(ex)[:80]))


def height_of(w):
    return 780 if w == 360 else (915 if w == 412 else (1180 if w == 820 else 900))


def ctx(br, sess, w, theme, reduced=False):
    phone = w < 720
    touchdev = phone or w == 820
    c = br.new_context(user_agent=UA, viewport={"width": w, "height": height_of(w)}, device_scale_factor=1,
                       has_touch=touchdev, is_mobile=phone, color_scheme="light", locale="ko-KR",
                       reduced_motion="reduce" if reduced else "no-preference")
    ck = [{"name": "tbox_theme", "value": theme, "domain": HOST, "path": "/"}]
    ck.append({"name": "qaredi_sidebar", "value": "open", "domain": HOST, "path": "/"})  # 카페 C5 — 펼친 셸을 잰다(쿠키 qaredi_sidebar 없으면 접힘이 기본)
    if sess:
        ck.append({"name": "__Host-tbox_session", "value": sess, "domain": HOST, "path": "/", "httpOnly": True, "secure": True})
    c.add_cookies(ck)
    return c


def page_of(c, reqlog=None, allow400=False):
    # 401·403 은 로그인·세션 시험이 일부러 만드는 답이다 · 400 은 거부 올리기(Q:T-19)만 일부러 만든다(allow400)
    pg = c.new_page()
    pg.on("dialog", lambda d: (dialogs.append(d.type + ": " + d.message[:60]), d.dismiss()))
    pg.on("pageerror", lambda e: errors.append("pageerror " + str(e)[:160]))
    pg.on("console", lambda m: errors.append("console " + pg.url.replace(SITE, "")[:48] + " " + m.text[:160]) if m.type == "error" and "401" not in m.text and "403" not in m.text and not (allow400 and "status of 400" in m.text) else None)
    if reqlog is not None:
        pg.on("request", lambda r: reqlog.append((r.method, r.url.replace(SITE, ""))))
    return pg


def go(pg, path):
    r = pg.goto(SITE + path, wait_until="networkidle", timeout=30000)
    pg.wait_for_timeout(400)
    return r


def guard(name, fn):
    try:
        fn()
    except Exception as ex:
        step(name + " — 진행 중 예외", False, str(ex).split("\n")[0][:220])


def open_drawer(pg):
    if pg.evaluate("() => { const t = document.getElementById('nav-toggle'); return !!t && t.getClientRects().length > 0 && t.getAttribute('aria-expanded') !== 'true'; }"):
        pg.click("#nav-toggle")
        pg.wait_for_timeout(250)


def wait_saved(pg, ms=12000):
    t0 = time.time()
    seen_busy = False
    while time.time() - t0 < ms / 1000:
        s = pg.evaluate("() => { const b = document.getElementById('page-save-state'); return b && !b.hidden ? b.textContent.trim() : ''; }")
        if s == "저장 중…":
            seen_busy = True
        if s == "저장됨" and seen_busy:
            return s
        if s == "저장 못 함":
            return s
        pg.wait_for_timeout(150)
    return pg.evaluate("() => { const b = document.getElementById('page-save-state'); return b ? b.textContent.trim() : ''; }")


BLOCK_ORDER = ["본문", "제목 1", "할 일", "글머리 목록", "그림", "파일", "번호 목록"]

print("── 화면 (%s)" % ("고치기 전 판 — 스크린샷만" if BEFORE else "고친 판"))
with sync_playwright() as p:
    br = p.chromium.launch()
    for w, theme in COMBOS:
        tag = "%d-%s" % (w, theme)
        phone = w < 720
        touchdev = phone or w == 820
        narrowbar = w < 960
        print("\n── %s" % tag, flush=True)

        # ── 공통 · Q:T-14 · Q:T-16 · Q:T-18 — 「내 공간」 첫 화면 ──────────────
        def home():
            c = ctx(br, S_E1, w, theme)
            reqs = []
            pg = page_of(c, reqs)
            go(pg, "/community/workspace")
            pg.wait_for_timeout(500)
            no_overflow(pg, "「내 공간」 " + tag)
            shot(pg, "home-%s" % tag)
            n_recent = len([r for r in reqs if r[0] == "GET" and r[1].startswith("/community/api/me/recent")])
            step("Q:T-14 첫 화면 「최근」 창구 요청 1(새 요청 0) " + tag, n_recent == 1, n_recent)
            st, rec = E.req("GET", "/community/api/me/recent")
            want = [x["title"] for x in (rec.get("pages") or [])[:5]]
            got = pg.evaluate("() => [...document.querySelectorAll('#community-home-recent-list a.nav-tree__link')].map(a => [a.textContent.trim(), a.getAttribute('href')])")
            step("Q:T-14 「최근」 판이 보이고 다섯 줄 이하 · 창구 앞 다섯과 같은 차례 " + tag,
                 pg.evaluate(VIS, "#community-home-recent") and 0 < len(got) <= 5 and [g[0] for g in got] == [(x.get("icon") + " " if x.get("icon") else "") + x["title"] for x in (rec.get("pages") or [])[:5]],
                 {"got": got[:5], "want": want})
            step("Q:T-14 「최근」 줄은 그 페이지 주소 " + tag, all(re.match(r"^/community/p/[0-9a-f-]{36}$", g[1] or "") for g in got), got[:2])
            lay = pg.evaluate("""() => { const box = document.getElementById('community-home-recent'); if (!box) return null;
              const t = document.getElementById('community-home-recent-title'); const rows = [...box.querySelectorAll('.nav-tree__row')];
              const vis = (e) => { const cs = getComputedStyle(e); return cs.display !== 'none' && cs.visibility !== 'hidden' && e.getBoundingClientRect().width > 1; };
              return {title: t ? Math.round(t.getBoundingClientRect().left) : null, n: rows.length,
                      links: rows.map(r => Math.round(r.querySelector('.nav-tree__link').getBoundingClientRect().left)),
                      expand: rows.filter(r => [...r.querySelectorAll('.nav-tree__toggle:not([data-fav-toggle])')].some(vis)).length,
                      stars: rows.filter(r => { const s = r.querySelector('[data-fav-toggle]'); return !!s && vis(s); }).length}; }""")
            step("Q:T-14 「최근」 줄 — 빈 펼침 자리 0 · 제목 글자가 판 제목과 같은 왼쪽 선(들여쓰기 0) · ☆ 그대로(터치 기기는 늘 보임) " + tag,
                 bool(lay) and lay["n"] > 0 and lay["title"] is not None and lay["expand"] == 0 and all(abs(x - lay["title"]) <= 1 for x in lay["links"])
                 and (lay["stars"] == lay["n"] if touchdev else True), lay)
            if narrowbar:
                step("Q:T-16 · Q:T-18 폰 윗줄 — 앱 이름 단추 · 검색 · 알림 종이 보인다 " + tag,
                     all(pg.evaluate(VIS, s) for s in ("#appbar-apps", "#appbar-search", "#appbar-bell")),
                     [pg.evaluate(VIS, s) for s in ("#appbar-apps", "#appbar-search", "#appbar-bell")])
                if touchdev:
                    touch(pg, "폰 윗줄(☰ · 앱 이름 · 검색 · 종) " + tag, ["#nav-toggle", "#appbar-apps", "#appbar-search", "#appbar-bell"])
                    pg.locator("#community-home-recent").scroll_into_view_if_needed()
                    touch(pg, "「최근」 줄 " + tag, ["#community-home-recent a.nav-tree__link", "#community-home-recent [data-fav-toggle]"])
            else:
                step("1280 — 윗줄이 없다(앱 이름·검색·종 숨김) " + tag, not any(pg.evaluate(VIS, s) for s in ("#appbar-apps", "#appbar-search", "#appbar-bell")))
            # Q:T-18 「Ctrl K」 — 터치 기기 숨김 · 1280 보임
            open_drawer(pg)
            kv = pg.evaluate(VIS, ".nav-search__key")
            step("Q:T-18 「Ctrl K」 글자 — 터치 기기 숨김 · 마우스 기기 보임 " + tag, kv == (not touchdev), kv)
            c.close()
        guard("「내 공간」 " + tag, home)

        # ── Q:T-11 · Q:T-12 — 새 페이지 편집(기본 제목 · new=1) ─────────────
        def new_page():
            c = ctx(br, S_E1, w, theme)
            pg = page_of(c)
            go(pg, "/community/p/%s?edit=1&new=1" % P_DEFAULT)
            pg.wait_for_selector(".ProseMirror[contenteditable=true]", timeout=10000)
            pg.wait_for_timeout(500)
            no_overflow(pg, "새 페이지 편집 " + tag)
            shot(pg, "newpage-edit-%s" % tag)
            info = pg.evaluate("""() => { const t = document.getElementById('page-title-input'); const a = document.activeElement;
              return {active: a ? a.id : null, value: t.value, ph: t.getAttribute('placeholder'), query: location.search, fs: getComputedStyle(t).fontSize}; }""")
            step("Q:T-11 새 페이지 — 제목 칸 빈 칸 · 자리표시 「제목」 · 커서 제목 칸 · 주소 꼬리표 지움 " + tag,
                 info["active"] == "page-title-input" and info["value"] == "" and info["ph"] == "제목" and info["query"] == "", info)
            top = pg.evaluate(BODY_TOP)
            if phone:
                if w == 412:
                    step("Q:T-12 412×915 새 페이지 본문 첫 줄 top ≤ 300 " + tag, isinstance(top, int) and top <= 300, top)
                else:
                    step("Q:T-12 360×780 새 페이지 본문 첫 줄 top ≤ 300 " + tag, isinstance(top, int) and top <= 300, top)
                hid = pg.evaluate("""() => { const d = s => { const e = document.querySelector(s); return !e || e.hidden || getComputedStyle(e).display === 'none'; };
                  const lab = document.querySelector('#page-title-field > .field__label'); const lr = lab.getBoundingClientRect();
                  return {h1: d('#page-title'), crumb: d('#page-crumb'), help: d('.page-head__actions > [data-help-open]'), history: d('#page-history'),
                          label_sr: lr.width <= 1 && lr.height <= 1 && getComputedStyle(lab).display !== 'none'}; }""")
                step("Q:T-12 좁은 폭 편집 — 큰 제목·경로·머리의 사용 방법·이력 숨김 · 「제목」 이름표는 화면 읽기용으로만 " + tag, all(hid.values()), hid)
                step("Q:T-12 제목 칸이 곧 제목(24px) " + tag, info["fs"] == "24px", info["fs"])
                row = pg.evaluate("""() => { const r = s => document.querySelector(s).getBoundingClientRect();
                  const st = document.getElementById('page-state'); const more = r('#page-more > summary'), done = r('#page-done');
                  const inBodyHead = !!document.querySelector('#page-body-head:not([hidden]) > #page-done:not([hidden])');
                  const sum = r('#page-props-summary');
                  return {state: st && !st.hidden ? Math.round(st.getBoundingClientRect().top + st.getBoundingClientRect().height / 2) : null,
                          more: Math.round(more.top + more.height / 2), done: Math.round(done.top + done.height / 2), sumh: Math.round(sum.height), inBodyHead}; }""")
                # Q:F-12 W1c (DESIGN 2.35) — 「편집 마침」은 머리 동작 줄이 아니라 본문 머리 줄 오른쪽(#page-body-head). 전: 머리 한 줄에 편집 끝
                step("Q:T-12 머리 한 줄 — 상태 배지 · 더보기 같은 줄 · 「편집 마침」은 본문 머리 줄 " + tag,
                     row["state"] is not None and abs(row["state"] - row["more"]) <= 8 and row["inBodyHead"], row)
                step("Q:T-12 속성 요약 한 줄(높이 44 이하) " + tag, row["sumh"] <= 44, row)
                pg.click("#page-more > summary")
                pg.wait_for_timeout(250)
                items = pg.evaluate("() => [...document.querySelectorAll('#page-more .menu__item')].filter(e => e.getClientRects().length && getComputedStyle(e).display !== 'none').map(e => e.id || e.textContent.trim())")
                step("Q:T-12 더보기 맨 위 「사용 방법」 · 「이력」 " + tag, items[:2] == ["page-more-help", "page-more-history"], items)
                shot(pg, "newpage-more-%s" % tag)
                if touchdev:
                    touch(pg, "더보기 안 사용 방법·이력 " + tag, ["#page-more-help", "#page-more-history"])
                pg.click("#page-more-help")
                pg.wait_for_timeout(300)
                step("Q:T-12 더보기 → 사용 방법 = 도움말 창 " + tag, pg.evaluate("() => { const d = document.getElementById('help-dialog'); return !!d && d.open; }"))
                pg.keyboard.press("Escape")
                pg.wait_for_timeout(200)
            else:
                vis = pg.evaluate("""() => { const v = s => { const e = document.querySelector(s); return !!e && !e.hidden && getComputedStyle(e).display !== 'none'; };
                  const lab = document.querySelector('#page-title-field > .field__label').getBoundingClientRect();
                  return {h1: v('#page-title'), help: v('.page-head__actions > [data-help-open]'), history: v('#page-history'), label: lab.height > 8, more_help: v('#page-more-help')}; }""")
                step("Q:T-12 넓은 폭 편집 — 머리 그대로(큰 제목 · 사용 방법 · 이력 · 「제목」 이름표) · 더보기 안 사용 방법 없음 " + tag,
                     vis["h1"] and vis["help"] and vis["history"] and vis["label"] and not vis["more_help"], vis)
            # 읽기 모드(편집 끝) — 전과 같다
            pg.click("#page-done")
            pg.wait_for_selector("#page-edit:not([hidden])", timeout=10000)
            pg.wait_for_timeout(300)
            rd = pg.evaluate("""() => { const v = s => { const e = document.querySelector(s); return !!e && !e.hidden && getComputedStyle(e).display !== 'none'; };
              return {h1: v('#page-title'), help: v('.page-head__actions > [data-help-open]'), editing: document.body.hasAttribute('data-page-editing'),
                      more_help: !document.getElementById('page-more-help') || document.getElementById('page-more-help').hidden}; }""")
            step("Q:T-12 읽기 모드 — 큰 제목·사용 방법 그대로 · 편집 표식 없음 · 더보기의 사용 방법 hidden " + tag,
                 rd["h1"] and rd["help"] and not rd["editing"] and rd["more_help"], rd)
            shot(pg, "page-read-%s" % tag)
            no_overflow(pg, "읽기 모드 " + tag)
            c.close()
        guard("새 페이지 편집 " + tag, new_page)

        # ── Q:T-13 — 팀장 첫 화면 알림 ─────────────────────────────────────
        def manager():
            c = ctx(br, S_M1, w, theme)
            reqs = []
            pg = page_of(c, reqs)
            go(pg, "/community/workspace")
            pg.wait_for_timeout(700)
            no_overflow(pg, "팀장 첫 화면 " + tag)
            shot(pg, "manager-home-%s" % tag)
            n_unread = len([r for r in reqs if r[0] == "GET" and r[1].startswith("/community/api/notifications?unread=1")])
            step("Q:T-13 첫 화면 알림 수 요청 1(윗줄 종이 새 요청을 만들지 않는다) " + tag, n_unread == 1, n_unread)
            if narrowbar:
                v = pg.evaluate("""() => { const b = document.getElementById('appbar-bell-count'), s = document.getElementById('nav-community-bell-count');
                  const bell = document.getElementById('appbar-bell').getBoundingClientRect();
                  return {bar: b && !b.hidden ? b.textContent.trim() : null, side: s && !s.hidden ? s.textContent.trim() : null, in_view: bell.top >= 0 && bell.bottom <= innerHeight,
                          toggle: document.getElementById('nav-toggle').getAttribute('aria-label'), bell_label: document.getElementById('appbar-bell').getAttribute('aria-label')}; }""")
                step("Q:T-13 윗줄 종에 안 읽은 수 — 사이드바와 같은 값 · 첫 화면 안 " + tag, v["bar"] is not None and v["bar"] == v["side"] and v["in_view"], v)
                step("Q:T-13 ☰ · 종 aria-label 에 안 읽은 수 " + tag, bool(v["bar"]) and v["toggle"] == "메뉴 열기 · 안 읽은 알림 " + v["bar"] and v["bell_label"] == "알림 — 안 읽은 것 " + v["bar"] + "개", v)
                pg.click("#appbar-bell")
                pg.wait_for_selector("#notify-dialog[open]", timeout=5000)
                pg.wait_for_selector("#notify-list li", timeout=8000)
                step("Q:T-13 윗줄 종 → 알림 창(같은 창) " + tag, pg.locator("#notify-list li").count() >= 1)
                shot(pg, "manager-notify-%s" % tag)
            c.close()
        guard("팀장 첫 화면 " + tag, manager)

        # ── Q:T-20 — 게시판 글 상태 글자 ───────────────────────────────────
        def board():
            if not (BP and BP2):
                step("Q:T-20 준비 — 게시판 글", False)
                return
            c = ctx(br, S_E1, w, theme)
            pg = page_of(c)
            go(pg, "/community/p/" + BP)
            v = pg.evaluate("() => { const e = document.getElementById('page-visibility'); return e ? [e.textContent.trim(), e.getAttribute('title'), e.className] : null; }")
            step("Q:T-20 게시판 글 — 「게시됨 · 사내 모두 봄」 · title=internal · 사내 배지 " + tag,
                 v is not None and v[0] == "게시됨 · 사내 모두 봄" and v[1] == "internal" and "badge--internal" in v[2], v)
            if phone:
                line = pg.evaluate("() => document.getElementById('page-props-line').textContent")
                step("Q:T-20 좁은 폭 요약 줄에도 같은 글자 " + tag, "게시됨 · 사내 모두 봄" in line, line)
            no_overflow(pg, "게시판 글 " + tag)
            shot(pg, "board-post-%s" % tag)
            go(pg, "/community/p/" + BP2)
            v2 = pg.evaluate("() => { const e = document.getElementById('page-visibility'); return e ? [e.textContent.trim(), e.className] : null; }")
            step("Q:T-20 숨긴 글(쓴 사람이 봄) — 「숨김 · 쓴 사람·게시판 관리자만 봄」 · 꺼짐 배지 " + tag,
                 v2 is not None and v2[0] == "숨김 · 쓴 사람·게시판 관리자만 봄" and "badge--off" in v2[1], v2)
            c.close()
        guard("게시판 글 " + tag, board)

        # ── t-box 첫 화면(윗줄 · 알림 없음) · 로그인 화면 ────────────────────
        def tbox():
            c = ctx(br, S_E1, w, theme)
            pg = page_of(c)
            go(pg, "/tbox/")
            no_overflow(pg, "t-box 첫 화면 " + tag)
            shot(pg, "tbox-home-%s" % tag)
            if narrowbar:
                step("t-box 윗줄 — 앱 이름·검색은 있고 알림 종은 없다(t-box 셸에는 알림이 없다) " + tag,
                     pg.evaluate(VIS, "#appbar-apps") and pg.evaluate(VIS, "#appbar-search") and pg.locator("#appbar-bell").count() == 0)
            c.close()
            c = ctx(br, None, w, theme)
            pg = page_of(c)
            go(pg, "/tbox/login?to=community")
            no_overflow(pg, "로그인 화면 " + tag)
            shot(pg, "login-%s" % tag)
            c.close()
        guard("t-box · 로그인 " + tag, tbox)

    # ════ 흐름 시험(한 조합씩) ════════════════════════════════════════════
    print("\n── Q:T-11 새 페이지 제목 흐름 (412 터치)", flush=True)

    def flow_title():
        c = ctx(br, S_E1, 412, "light")
        pg = page_of(c)
        go(pg, "/community/workspace")
        pg.click("#community-new")
        pg.wait_for_url(re.compile(r"/community/p/[0-9a-f-]{36}$"), timeout=15000)
        pg.wait_for_selector(".ProseMirror[contenteditable=true]", timeout=10000)
        pg.wait_for_timeout(400)
        pid = re.search(r"/p/([0-9a-f-]{36})", pg.url).group(1)
        step("Q:T-11 「새 페이지」 → 커서가 제목 칸", pg.evaluate("() => document.activeElement && document.activeElement.id") == "page-title-input")
        pg.keyboard.type("UXR1 제목부터 " + TAG)
        pg.keyboard.press("Enter")
        pg.wait_for_timeout(150)
        step("Q:T-11 제목 칸에서 Enter → 본문 첫 줄(편집기)", pg.evaluate("() => !!document.activeElement && !!document.activeElement.closest('.ProseMirror')"))
        pg.keyboard.type("본문 첫 줄 " + TAG)
        s = wait_saved(pg)
        cc, d = E.req("GET", "/community/api/pages/" + pid)
        pgd = d.get("page") or {}
        first = "".join(x.get("t", "") for x in ((pgd.get("doc") or {}).get("blocks") or [{}])[0].get("text", []))
        step("Q:T-11 저장 — 제목 · 본문 첫 줄이 친 그대로", s == "저장됨" and pgd.get("title") == "UXR1 제목부터 " + TAG and first == "본문 첫 줄 " + TAG, (s, pgd.get("title"), first))
        # 제목을 비운 채 본문만
        go(pg, "/community/workspace")
        pg.click("#community-new")
        pg.wait_for_url(re.compile(r"/community/p/[0-9a-f-]{36}$"), timeout=15000)
        pg.wait_for_selector(".ProseMirror[contenteditable=true]", timeout=10000)
        pg.wait_for_timeout(300)
        pid2 = re.search(r"/p/([0-9a-f-]{36})", pg.url).group(1)
        pg.click(".ProseMirror p >> nth=0")
        pg.keyboard.type("제목 없이 본문만")
        s = wait_saved(pg)
        cc, d = E.req("GET", "/community/api/pages/" + pid2)
        step("Q:T-11 제목 칸을 비워 두면 서버 제목은 기본값 그대로(「제목 없는 페이지」)", s == "저장됨" and (d.get("page") or {}).get("title") == "제목 없는 페이지", (s, (d.get("page") or {}).get("title")))
        # 기존 기본 제목 페이지의 「편집」 — 빈 칸 + 자리표시 · 커서는 본문
        go(pg, "/community/p/" + pid2)
        pg.wait_for_selector("#page-edit:not([hidden])", timeout=10000)
        step("Q:T-11 읽기 모드 제목은 서버 값 그대로", pg.inner_text("#page-title").strip() == "제목 없는 페이지", pg.inner_text("#page-title"))
        pg.click("#page-edit")
        pg.wait_for_selector(".ProseMirror[contenteditable=true]", timeout=10000)
        pg.wait_for_timeout(200)
        v = pg.evaluate("() => ({value: document.getElementById('page-title-input').value, inbody: !!document.activeElement && !!document.activeElement.closest('.ProseMirror')})")
        step("Q:T-11 기본 제목 페이지 「편집」 — 제목 칸 빈 칸 · 커서는 본문(새 페이지가 아니므로)", v["value"] == "" and v["inbody"], v)
        c.close()
    guard("Q:T-11 흐름", flow_title)

    print("\n── Q:T-11 트리 「하위 추가」 (1280 마우스)", flush=True)

    def flow_child():
        c = ctx(br, S_E1, 1280, "light")
        pg = page_of(c)
        go(pg, "/community/workspace")
        row = pg.locator("#community-tree .page-tree__row", has=pg.locator("a.page-tree__link", has_text="UXR1 글 " + TAG)).first
        row.hover()
        pg.wait_for_timeout(150)
        row.locator(".page-tree__add").click()
        pg.wait_for_url(re.compile(r"/community/p/[0-9a-f-]{36}$"), timeout=15000)
        pg.wait_for_selector(".ProseMirror[contenteditable=true]", timeout=10000)
        pg.wait_for_timeout(300)
        v = pg.evaluate("() => ({active: document.activeElement && document.activeElement.id, value: document.getElementById('page-title-input').value, editing: document.body.hasAttribute('data-page-editing')})")
        step("Q:T-11 트리 「하위 추가」 → 곧바로 편집 모드 · 커서 제목 칸 · 빈 칸", v["active"] == "page-title-input" and v["value"] == "" and v["editing"], v)
        c.close()
    guard("Q:T-11 하위 추가", flow_child)

    print("\n── Q:T-15 블록 창 (412 터치 · 1280 키보드)", flush=True)

    def flow_blocks():
        c = ctx(br, S_E1, 412, "light")
        pg = page_of(c)
        go(pg, "/community/p/%s?edit=1" % P_EDIT)
        pg.wait_for_selector(".ProseMirror[contenteditable=true]", timeout=10000)
        pg.click(".ProseMirror p >> nth=-1")
        pg.keyboard.press("End")
        pg.click("#page-add-block")
        pg.wait_for_selector("#block-dialog[open]", timeout=5000)
        pg.wait_for_timeout(250)
        labels = pg.evaluate("() => [...document.querySelectorAll('#block-list .block-pick strong')].map(s => s.textContent)")
        step("Q:T-15 보이는 순서 — 본문 · 제목 1 · 할 일 · 글머리 목록 · 그림 · 파일 · 번호 목록 · …", labels[:7] == BLOCK_ORDER, labels[:9])
        vis = pg.evaluate("""() => { const l = document.getElementById('block-list').getBoundingClientRect();
          return [...document.querySelectorAll('#block-list .block-pick')].slice(0, 6).map(b => { const r = b.getBoundingClientRect(); return r.top >= l.top - 1 && r.bottom <= l.bottom + 1 && r.bottom <= innerHeight; }); }""")
        step("Q:T-15 412 — 자주 쓰는 여섯(그림·파일 포함)이 목록을 밀지 않고 보인다", vis == [True] * 6, vis)
        shot(pg, "block-dialog-412")
        touch(pg, "블록 창 목록 줄(412)", ["#block-list .block-pick"])
        n0 = pg.evaluate("() => document.querySelectorAll('.ProseMirror .doc-todo').length")
        pg.locator("#block-list .block-pick", has_text="할 일").first.click()
        pg.wait_for_timeout(250)
        n1 = pg.evaluate("() => document.querySelectorAll('.ProseMirror .doc-todo').length")
        step("Q:T-15 값 없는 블록(할 일) — 한 번 누르면 곧바로 넣고 창이 닫힌다", not pg.evaluate("() => document.getElementById('block-dialog').open") and n1 == n0 + 1, (n0, n1))
        pg.click("#page-add-block")
        pg.wait_for_selector("#block-dialog[open]", timeout=5000)
        pg.locator("#block-list .block-pick", has_text="파일").last.click()
        pg.wait_for_timeout(250)
        step("Q:T-15 값 있는 블록(파일) — 누르면 미리보기(창 그대로) · 「넣기」 두 단계",
             pg.evaluate("() => document.getElementById('block-dialog').open") and pg.inner_text("#block-preview-name").strip() == "파일")
        pg.click("#block-insert")
        pg.wait_for_selector("#atom-dialog[open]", timeout=5000)
        step("Q:T-15 「넣기」 → 파일 고르기 창(다음 창)", pg.evaluate("() => document.getElementById('atom-dialog').open"))
        pg.keyboard.press("Escape")
        pg.wait_for_timeout(200)
        s = wait_saved(pg)
        step("Q:T-15 넣은 할 일 저장", s == "저장됨", s)
        c.close()
        # 키보드(1280) — ↑↓ 는 보이는 차례 · Enter 로 넣기 · 블록 바꾸기 한 번 누르기
        c = ctx(br, S_E1, 1280, "light")
        pg = page_of(c)
        go(pg, "/community/p/%s?edit=1" % P_EDIT)
        pg.wait_for_selector(".ProseMirror[contenteditable=true]", timeout=10000)
        pg.click(".ProseMirror p >> nth=0")
        pg.keyboard.press("End")
        pg.click("#page-add-block")
        pg.wait_for_selector("#block-dialog[open]", timeout=5000)
        pg.keyboard.press("ArrowDown")
        pg.keyboard.press("ArrowDown")
        pg.wait_for_timeout(120)
        nm = pg.inner_text("#block-preview-name").strip()
        step("Q:T-15 키보드 ↓↓ = 보이는 차례 셋째(할 일)", nm == "할 일", nm)
        n0 = pg.evaluate("() => document.querySelectorAll('.ProseMirror .doc-todo').length")
        pg.keyboard.press("Enter")
        pg.wait_for_timeout(250)
        n1 = pg.evaluate("() => document.querySelectorAll('.ProseMirror .doc-todo').length")
        step("Q:T-15 Enter → 넣고 창 닫힘", not pg.evaluate("() => document.getElementById('block-dialog').open") and n1 == n0 + 1, (n0, n1))
        first = pg.locator(".ProseMirror > *").first
        first.hover()
        pg.wait_for_timeout(150)
        first.locator(".block-handle").click()
        pg.wait_for_selector("#block-dialog[open]", timeout=5000)
        ttl = pg.inner_text("#block-dialog-title").strip()
        pg.locator("#block-list .block-pick", has_text="제목 1").first.click()
        pg.wait_for_timeout(250)
        k = pg.evaluate("() => { const e = document.querySelector('.ProseMirror > *'); return e ? (e.dataset.kind || e.tagName.toLowerCase()) : null; }")
        step("Q:T-15 블록 바꾸기 — 「제목 1」 한 번 누르면 곧바로 바뀌고 창 닫힘", ttl == "블록 바꾸기" and k == "h1" and not pg.evaluate("() => document.getElementById('block-dialog').open"), (ttl, k))
        wait_saved(pg)
        c.close()
    guard("Q:T-15 블록 창", flow_blocks)

    print("\n── Q:T-16 앱 전환 · Q:T-18 검색 (412 터치)", flush=True)

    def flow_switch():
        c = ctx(br, S_E1, 412, "light")
        pg = page_of(c)
        go(pg, "/community/workspace")
        pg.click("#appbar-apps")
        pg.wait_for_timeout(300)
        v = pg.evaluate("""() => { const s = document.getElementById('sidebar'), d = document.querySelector('details.appbar__apps'), a = document.activeElement;
          return {sidebar: !s.hidden, open: d.open, active: a ? a.getAttribute('href') : null, expanded: document.getElementById('appbar-apps').getAttribute('aria-expanded')}; }""")
        step("Q:T-16 윗줄 앱 이름 → 서랍 · 앱 메뉴 펼침 · 초점은 다른 앱(t-box)", v["sidebar"] and v["open"] and (v["active"] or "").startswith("/tbox") and v["expanded"] == "true", v)
        shot(pg, "app-switch-412")
        pg.click("details.appbar__apps a.app-menu__app[href^='/tbox']")
        pg.wait_for_url(re.compile(r"/tbox/?$"), timeout=10000)
        step("Q:T-16 두 번 누름으로 t-box", True)
        go(pg, "/tbox/")
        pg.click("#appbar-apps")
        pg.wait_for_timeout(300)
        act = pg.evaluate("() => document.activeElement ? document.activeElement.getAttribute('href') : null")
        step("Q:T-16 t-box 에서는 업무(앱 목록 순서의 다음 앱 — Q:F-12 자료실 · 업무 · 이야기)에 초점", (act or "") == "/community/", act)
        pg.click("#appbar-apps")
        pg.wait_for_timeout(200)
        step("Q:T-16 다시 누르면 접고 서랍을 닫는다", pg.evaluate("() => document.getElementById('sidebar').hidden && !document.querySelector('details.appbar__apps').open"))
        # Q:T-18 검색
        go(pg, "/community/workspace")
        pg.click("#appbar-search")
        pg.wait_for_timeout(300)
        v = pg.evaluate("() => ({open: document.getElementById('search-dialog').open, active: document.activeElement && document.activeElement.id})")
        step("Q:T-18 윗줄 검색 → 통합 검색 창 · 입력 칸 초점", v["open"] and v["active"] == "search-input", v)
        pg.keyboard.type("UXR1 글")
        pg.wait_for_selector("#search-results li", timeout=10000)
        step("Q:T-18 검색 결과가 나온다", pg.locator("#search-results li").count() >= 1)
        shot(pg, "search-412")
        c.close()
    guard("Q:T-16 · Q:T-18", flow_switch)

    print("\n── Q:T-19 t-box 올리기 뒤 (412 터치 · 움직임 줄이기)", flush=True)

    def flow_upload():
        c = ctx(br, S_E1, 412, "light", reduced=True)
        posts = []
        pg = page_of(c, allow400=True)
        pg.on("response", lambda r: posts.append(r.status) if r.request.method == "POST" and r.url.split("?")[0].endswith("/tbox/api/files") else None)
        go(pg, "/tbox/")
        pg.click("#upload-toggle")
        pg.wait_for_timeout(300)
        names = ["UXR1-%s-가.txt" % TAG, "UXR1-%s-나.txt" % TAG]
        pg.set_input_files("#upload-file", files=[{"name": n, "mimeType": "text/plain", "buffer": ("uxr1 " + n + "\n").encode()} for n in names])
        pg.select_option("#upload-level", "internal")
        pg.click("#upload-submit")
        t0 = time.time()
        while len(posts) < 2 and time.time() - t0 < 20:
            pg.wait_for_timeout(200)
        pg.wait_for_timeout(1200)
        v = pg.evaluate("""(ns) => { const d = document.getElementById('upload-dialog'); const rows = [...document.querySelectorAll('#files-body > tr')];
          const mine = rows.filter(r => ns.some(n => r.textContent.includes(n)));
          const r0 = mine[0] ? mine[0].getBoundingClientRect() : null;
          return {open: d.open, fresh: mine.map(r => r.classList.contains('is-fresh')), in_view: !!r0 && r0.top >= 0 && r0.bottom <= innerHeight,
                  td_transition: mine[0] ? getComputedStyle(mine[0].querySelector('td')).transitionDuration : null,
                  reduce: matchMedia('(prefers-reduced-motion: reduce)').matches}; }""", names)
        step("Q:T-19 모두 성공(201 ×2) → 올리기 창 닫힘", posts == [201, 201] and not v["open"], (posts, v))
        step("Q:T-19 새 줄 둘 강조(.is-fresh) · 첫 줄이 화면 안", v["fresh"] == [True, True] and v["in_view"], v)
        step("Q:T-19 강조는 움직임 0(전환 없음) · 움직임 줄이기 설정 읽힘", v["reduce"] and (v["td_transition"] or "").split(",")[0].strip() in ("0s", "0ms"), v)
        shot(pg, "upload-fresh-412")
        pg.wait_for_timeout(2600)
        step("Q:T-19 2.5초 뒤 강조가 풀린다", pg.evaluate("() => document.querySelectorAll('#files-body > tr.is-fresh').length") == 0)
        pg.click("#upload-toggle")
        pg.wait_for_timeout(300)
        v = pg.evaluate("() => ({picked: document.getElementById('upload-picked') ? document.getElementById('upload-picked').textContent.trim() : '', files: document.getElementById('upload-file').files.length, progress_hidden: document.getElementById('upload-progress').hidden})")
        step("Q:T-19 다시 열면 고른 파일 0 · 진행 표 숨김(같은 파일을 두 번 올리지 않게)", v["picked"] == "" and v["files"] == 0 and v["progress_hidden"], v)
        # 거부 하나 — 창을 열어 둔다
        posts.clear()
        pg.set_input_files("#upload-file", files=[{"name": "UXR1-%s-거부.php" % TAG, "mimeType": "text/plain", "buffer": b"<?php echo 1;\n"}])
        pg.select_option("#upload-level", "internal")
        pg.click("#upload-submit")
        t0 = time.time()
        while len(posts) < 1 and time.time() - t0 < 20:
            pg.wait_for_timeout(200)
        pg.wait_for_timeout(800)
        v = pg.evaluate("() => ({open: document.getElementById('upload-dialog').open, state: [...document.querySelectorAll('.upload-status__state')].map(e => e.textContent.trim())})")
        step("Q:T-19 거부가 있으면 창을 열어 둔다(진행 표에 까닭)", bool(posts) and posts[0] != 201 and v["open"] and bool(v["state"]), (posts, v))
        shot(pg, "upload-deny-412")
        pg.keyboard.press("Escape")
        c.close()
    guard("Q:T-19 올리기", flow_upload)

    print("\n── Q:T-17 2단계 코드 자동 제출 (412 · claude_employee02)", flush=True)

    def flow_totp():
        c = ctx(br, None, 412, "light")
        posts = []
        pg = page_of(c)
        pg.on("request", lambda r: posts.append(r.url) if r.method == "POST" and r.url.split("?")[0].endswith("/tbox/api/login") else None)
        go(pg, "/tbox/login?to=community")
        code = fresh_code("claude_employee02", SEC_E2)
        pg.fill("#username", E2.me.get("username", "claude_employee02"))
        pg.fill("#password", PW_E2)
        pg.click("#login-submit")
        pg.wait_for_selector("#otp-field:not([hidden])", timeout=10000)
        pg.wait_for_timeout(200)
        step("Q:T-17 1단계 뒤 코드 칸 펼침 · 초점", pg.evaluate("() => document.activeElement && document.activeElement.id") == "otp")
        pg.evaluate("() => { document.getElementById('password').value = ''; }")   # 스크린샷·화면에 비밀번호 점도 남기지 않게(2단계는 들고 있는 값으로 보낸다)
        wrong = "000000" if code != "000000" else "111111"
        n0 = len(posts)
        pg.keyboard.type(wrong)
        pg.wait_for_timeout(1500)
        v = pg.evaluate("() => ({value: document.getElementById('otp').value, active: document.activeElement && document.activeElement.id, msg: document.getElementById('msg').textContent.trim(), url: location.pathname})")
        step("Q:T-17 여섯 자리를 치면 한 번 자동 제출 · 틀리면 칸 비움 · 초점 · 문장", len(posts) - n0 == 1 and v["value"] == "" and v["active"] == "otp" and v["msg"] != "" and v["url"].endswith("/login"), (len(posts) - n0, v))
        code = fresh_code("claude_employee02", SEC_E2)
        n1 = len(posts)
        pg.keyboard.type(code[:5])
        pg.wait_for_timeout(300)
        step("Q:T-17 다섯 자리까지는 제출 0", len(posts) == n1, len(posts) - n1)
        pg.keyboard.type(code[5:])
        pg.keyboard.press("Enter")   # 자동 제출과 겹친 Enter — 두 번째 요청을 보내지 않는다
        try:
            pg.wait_for_url(lambda u: "/login" not in u, timeout=10000)
            moved = True
        except Exception:
            moved = False
        pg.wait_for_timeout(500)
        step("Q:T-17 맞는 코드 → 자동 제출 한 번(Enter 가 겹쳐도 요청 1) · 로그인", moved and len(posts) - n1 == 1, (moved, len(posts) - n1, pg.url.replace(SITE, "")))
        # 이 화면 로그인 세션은 닫는다
        pg.evaluate("""async () => { const me = await (await fetch('/tbox/api/me', {credentials: 'same-origin', cache: 'no-store'})).json();
          await fetch('/tbox/api/logout', {method: 'POST', credentials: 'same-origin', headers: {'X-CSRF-Token': me.csrf_token}}); }""")
        c.close()
    guard("Q:T-17 자동 제출", flow_totp)

    print("\n── Q:T-21 다른 탭에서 다시 로그인한 뒤 옛 탭 (412 · 한 브라우저 문맥)", flush=True)

    LOGIN_JS = """async ([u, pw, otp]) => {
      const me = await fetch('/tbox/api/me', {credentials: 'same-origin', cache: 'no-store'});
      if (me.status === 200) { const j = await me.json(); await fetch('/tbox/api/logout', {method: 'POST', credentials: 'same-origin', headers: {'X-CSRF-Token': j.csrf_token}}); }
      const r = await fetch('/tbox/api/login', {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({username: u, password: pw, otp: otp})});
      return r.status; }"""

    def deny_since(mark):
        v = audit_q("SELECT count(*) FROM audit_logs WHERE id > %d AND action = 'csrf' AND result = 'deny' AND reason = 'token_mismatch';" % int(mark))
        return int(v) if v and v.isdigit() else None

    def audit_max():
        v = audit_q("SELECT coalesce(max(id),0) FROM audit_logs;")
        return int(v) if v and v.isdigit() else None

    def flow_session():
        # 새 세션으로 시작한다(아래에서 이 세션을 로그아웃하므로 다른 시험이 쓰는 S_E1 을 쓰지 않는다)
        c = ctx(br, None, 412, "light")
        tb = c.new_page()
        tb.goto(SITE + "/tbox/login", wait_until="networkidle")
        st0 = tb.evaluate(LOGIN_JS, [E1_NAME, PW_E1, fresh_code("claude_employee01", SEC_E1)])
        step("Q:T-21 준비 — 새 문맥에서 직원 로그인 200", st0 == 200, st0)
        reqs = []
        ta = page_of(c, reqs)
        go(ta, "/community/p/%s?edit=1" % P_SESS)
        ta.wait_for_selector(".ProseMirror[contenteditable=true]", timeout=10000)
        ta.click(".ProseMirror p >> nth=-1")
        ta.keyboard.press("End")
        ta.keyboard.type(" 가")
        s = wait_saved(ta)
        step("Q:T-21 준비 — 탭 A 편집 저장", s == "저장됨", s)
        # 탭 B — 로그아웃 · 같은 계정 다시 로그인
        m0 = audit_max()
        tb.bring_to_front()
        st1 = tb.evaluate(LOGIN_JS, [E1_NAME, PW_E1, fresh_code("claude_employee01", SEC_E1)])
        step("Q:T-21 탭 B — 로그아웃 · 같은 계정 다시 로그인 200", st1 == 200, st1)
        ta.bring_to_front()
        ta.wait_for_timeout(500)
        ta.click(".ProseMirror p >> nth=-1")
        ta.keyboard.press("End")
        ta.keyboard.type(" 나")
        s = wait_saved(ta, 20000)
        cc, d = E.req("GET", "/community/api/pages/" + P_SESS)
        txt = json.dumps((d.get("page") or {}).get("doc") or {}, ensure_ascii=False)
        step("Q:T-21 같은 계정 — 탭 A 저장 성공(새 토큰으로 한 번 다시 보냄) · 서버 본문에 친 글자", s == "저장됨" and " 나" in txt, (s, txt[-120:]))
        dn = deny_since(m0) if m0 is not None else None
        step("Q:T-21 그 사이 임시 감사의 csrf deny ≤ 1", dn is not None and dn <= 1, dn)
        step("Q:T-21 알림 줄에 멈춤 안내 없음(같은 계정)", "다른 계정" not in ta.inner_text("#msg"), ta.inner_text("#msg"))
        m1 = audit_max()
        hb0 = len([r for r in reqs if r[0] == "POST" and r[1].endswith("/heartbeat")])
        note("heartbeat 2분 이어 돌리기(30초마다) — %s" % time.strftime("%T"))
        ta.wait_for_timeout(125000)
        hb1 = len([r for r in reqs if r[0] == "POST" and r[1].endswith("/heartbeat")])
        dn2 = deny_since(m1) if m1 is not None else None
        step("Q:T-21 heartbeat 2분 더 — 요청은 나가고(%d) 추가 csrf deny 0" % (hb1 - hb0), hb1 - hb0 >= 3 and dn2 == 0, (hb1 - hb0, dn2))
        # 다른 계정
        tb.bring_to_front()
        st2 = tb.evaluate(LOGIN_JS, [E2.me.get("username", "claude_employee02"), PW_E2, fresh_code("claude_employee02", SEC_E2)])
        step("Q:T-21 탭 B — 로그아웃 · 다른 계정 로그인 200", st2 == 200, st2)
        ta.bring_to_front()
        ta.wait_for_timeout(300)
        writes = lambda: [r for r in reqs if r[0] in ("PATCH", "POST", "PUT", "DELETE") and r[1].startswith("/community/api/")]
        k0 = len(writes())
        ta.click(".ProseMirror p >> nth=-1")
        ta.keyboard.press("End")
        ta.keyboard.type(" 다")
        s = wait_saved(ta, 15000)
        ta.wait_for_timeout(1500)
        after = writes()[k0:]
        msg = ta.inner_text("#msg").strip()
        # 옛 토큰으로 나간 쓰기는 **하나**(저장 또는 그 사이 30초 heartbeat — 먼저 403 을 받은 쪽)이고 다시 보낸 것 0 · 저장은 보내지 않거나 한 번
        step("Q:T-21 다른 계정 — 옛 탭 쓰기 요청 하나 이하(다시 보내지 않음) · 「저장 못 함」 · 안내 한 줄",
             len(after) <= 1 and len([r for r in after if r[0] == "PATCH"]) <= 1 and s == "저장 못 함" and msg == "다른 계정으로 로그인되어 이 탭은 멈췄습니다 — 새로 고치세요", (after, s, msg))
        shot(ta, "session-other-412")
        w0 = len([r for r in reqs if r[0] in ("PATCH", "POST", "PUT", "DELETE") and r[1].startswith("/community/api/")])
        n0 = len([r for r in reqs if r[0] == "GET" and r[1].startswith("/community/api/notifications?unread=1")])
        ta.keyboard.type(" 라")
        ta.wait_for_timeout(35000)   # 자동 저장(1.5초) · heartbeat(30초) · 알림 폴링(30초) 한 번씩은 지나간다
        w1 = len([r for r in reqs if r[0] in ("PATCH", "POST", "PUT", "DELETE") and r[1].startswith("/community/api/")])
        n1 = len([r for r in reqs if r[0] == "GET" and r[1].startswith("/community/api/notifications?unread=1")])
        step("Q:T-21 멈춘 탭 — 자동 알림 폴링도 0(다른 계정의 알림 수를 그리지 않는다)", n1 == n0, n1 - n0)
        cc, d = E.req("GET", "/community/api/pages/" + P_SESS)
        txt = json.dumps((d.get("page") or {}).get("doc") or {}, ensure_ascii=False)
        step("Q:T-21 멈춘 탭 — 그 뒤 쓰기·heartbeat 요청 0 · 서버 본문에 「다」「라」 없음", w1 == w0 and " 다" not in txt and " 라" not in txt, (w1 - w0, txt[-120:]))
        # 정리 — 이 문맥의 다른 계정 세션을 닫는다
        tb.evaluate("""async () => { const me = await fetch('/tbox/api/me', {credentials: 'same-origin', cache: 'no-store'}); if (me.status !== 200) return;
          const j = await me.json(); await fetch('/tbox/api/logout', {method: 'POST', credentials: 'same-origin', headers: {'X-CSRF-Token': j.csrf_token}}); }""")
        c.close()
    guard("Q:T-21 세션", flow_session)

    print("\n── Q:T-21 모의 — 경계 경우(route 모의 · 1280 · 새 세션 하나)", flush=True)

    def flow_session_mock():
        # 모의는 이 브라우저 문맥 안에서만 — `/tbox/api/me` 답(MeMock) · 페이지 창구 답(page.route). 서버에 가는 요청은 임시 인스턴스뿐.
        c = ctx(br, None, 1280, "light")
        mm = MeMock()
        c.route("**/tbox/api/me", mm.handle)
        tb = c.new_page()
        tb.goto(SITE + "/tbox/login", wait_until="networkidle")
        st = tb.evaluate(LOGIN_JS, [E1_NAME, PW_E1, fresh_code("claude_employee01", SEC_E1)])
        tb.close()
        step("Q:T-21 모의 준비 — 새 문맥에서 직원 로그인 200", st == 200, st)
        SID = session_row_id(c)
        mlog = []
        PAGE = "/community/api/pages/" + str(P_MOCK)
        HB = PAGE + "/heartbeat"

        def logreq(r):
            mlog.append({"m": r.method, "u": r.url.replace(SITE, ""), "bg": r.headers.get("x-qaredi-background") or "", "t": time.time()})

        def me_n(since, until=None, bg=None):
            return len([r for r in mlog if r["m"] == "GET" and r["u"].split("?")[0] == "/tbox/api/me" and r["t"] >= since
                        and (until is None or r["t"] < until) and (bg is None or r["bg"] == bg)])

        def patch_n(since):
            return len([r for r in mlog if r["m"] == "PATCH" and r["u"] == PAGE and r["t"] >= since])

        def hb_n(since):
            return len([r for r in mlog if r["m"] == "POST" and r["u"] == HB and r["t"] >= since])

        def save_state(pg):
            return pg.evaluate("() => { const b = document.getElementById('page-save-state'); return b && !b.hidden ? b.textContent.trim() : ''; }")

        def wait_state(pg, want, ms=15000):
            t0 = time.time()
            while time.time() - t0 < ms / 1000:
                s = save_state(pg)
                if s in want:
                    return s
                pg.wait_for_timeout(200)
            return save_state(pg)

        def type_body(pg, text):
            pg.click(".ProseMirror p >> nth=-1")
            pg.keyboard.press("End")
            pg.keyboard.type(text)

        def server_text():
            cc, d = E.req("GET", "/community/api/pages/" + str(P_MOCK))
            return json.dumps((d.get("page") or {}).get("doc") or {}, ensure_ascii=False)

        def jr(status, body):
            return lambda route: route.fulfill(status=status, headers={"Content-Type": "application/json"}, body=json.dumps(body, ensure_ascii=False))

        HB_OK = jr(200, {"editors": []})
        DENY = jr(403, {"error": "권한이 없습니다"})

        def open_editor(boot_mode, page_routes):
            """부팅 때 `/api/me` 모양(boot_mode) · 페이지 창구 모의(page_routes: [(주소 끝, 처리기)])는 **열기 전에** 건다(첫 heartbeat 도 모의)"""
            mm.mode = boot_mode
            pg = page_of(c)
            pg.on("request", logreq)
            for suffix, fn in page_routes:
                pg.route("**" + suffix, fn)
            pg.goto(SITE + "/community/p/%s?edit=1" % P_MOCK, wait_until="load")
            pg.wait_for_selector(".ProseMirror[contenteditable=true]", timeout=15000)
            pg.wait_for_timeout(800)
            mm.mode = None
            return pg

        def only(method, fn):
            return lambda route: fn(route) if route.request.method == method else route.continue_()

        # S1 고리 0 — 서버(모의)가 저장을 늘 403 · `/api/me` 는 부를 때마다 새 토큰(같은 계정 · 토큰 다름이 끝없이)
        ta = open_editor(None, [(PAGE, only("PATCH", DENY)), (HB, HB_OK)])
        mm.mode = "fresh-each"
        t0 = time.time()
        type_body(ta, " 가")
        s = wait_state(ta, ("저장 못 함",))
        ta.wait_for_timeout(8000)
        mm.mode = None
        step("Q:T-21 모의 S1 고리 0 — 서버가 계속 403 이어도 저장은 원래 1 + 다시 1 에서 멈춤 · /api/me 1 · 「저장 못 함」(8초 더 기다림)",
             patch_n(t0) == 2 and me_n(t0) == 1 and s == "저장 못 함", (patch_n(t0), me_n(t0), s))
        ta.close()

        # S2 재인증 요구 403(next: reauth) — 세션 확인 0 · 다시 보내기 0
        ta = open_editor(None, [(PAGE, only("PATCH", jr(403, {"error": "비밀번호를 한 번 더 확인하세요", "next": "reauth"}))), (HB, HB_OK)])
        t0 = time.time()
        type_body(ta, " 나")
        ta.wait_for_timeout(4000)
        step("Q:T-21 모의 S2 재인증 요구 403(next: reauth)은 비켜 감 — 저장 1 · /api/me 0", patch_n(t0) == 1 and me_n(t0) == 0, (patch_n(t0), me_n(t0)))
        ta.close()

        # S3 저장 403 → /api/me 401 → 전과 같은 로그인 흐름(돌아올 주소 ?edit=1) · 임시저장 · 다시 보내기 0
        #    로그인 화면 요청은 **요청 기록**으로 잡는다 — 실제 세션은 살아 있어 로그인 화면이 302 로 편집 화면에 되돌려 보낸다(주소 이동 사건에는 안 남는다).
        #    그 요청이 나가면 곧바로 모의를 끈다(켜 둔 채면 되돌아온 화면이 다시 401 을 받아 로그인으로 — 모의가 만든 고리).
        ta = open_editor(None, [(PAGE, only("PATCH", DENY)), (HB, HB_OK)])
        DRAFTS = "() => JSON.stringify(Object.keys(localStorage).filter(k => k.startsWith('qaredi-draft:')).sort().map(k => [k, localStorage.getItem(k)]))"
        dv0 = ta.evaluate(DRAFTS)
        t0 = time.time()
        mm.mode = "401"
        type_body(ta, " 다")
        logins = []
        tw = time.time()
        while time.time() - tw < 10 and not logins:
            ta.wait_for_timeout(100)
            logins = [r for r in mlog if r["m"] == "GET" and r["u"].startswith("/tbox/login?to=") and r["t"] >= t0]
        mm.mode = None
        ta.wait_for_timeout(3000)
        t_nav = logins[0]["t"] if logins else time.time()
        dv1 = ta.evaluate(DRAFTS)
        # 떠날 때의 PATCH 한 번(beforeunload flushSave — 전부터 있던 동작)은 받아 준다 · 401 갈래는 다시 보내지 않는다
        n_p = len([r for r in mlog if r["m"] == "PATCH" and r["u"] == PAGE and t0 <= r["t"] <= t_nav + 0.5])
        step("Q:T-21 모의 S3 /api/me 401 → 로그인 화면 요청(?to=…edit%3D1) · 그 전 /api/me 1 · 저장 2 이하(다시 보내기 0) · 임시저장 사본이 새로 쓰임",
             bool(logins) and "edit%3D1" in logins[0]["u"] and me_n(t0, t_nav) == 1 and n_p <= 2 and dv1 != dv0 and dv1 != "[]",
             (logins[0]["u"].replace(str(P_MOCK), "<P>")[:60] if logins else None, me_n(t0, t_nav), n_p, dv1 != dv0))
        ta.close()

        # S4 동시 403 둘(heartbeat · 저장 — 옛 토큰) → /api/me 한 번으로 묶임 · 둘 다 한 번씩만 다시 · 저장됨 (/api/me 0.8초 늦춤 · 늦춤 0)
        for delay in (0.8, 0.0):
            held = []
            done = [False]

            def hold(route):
                if not done[0] and route.request.method in ("PATCH", "POST"):
                    held.append(route)
                else:
                    route.continue_()
            ta = open_editor("stale", [(PAGE, hold), (HB, hold)])
            if delay:
                # 늦춤은 부팅 **뒤에** 건다(페이지 처리기가 먼저라, 부팅 때 걸면 부팅 /api/me 가 옛 토큰 모의를 건너뛴다)
                def slow_after(d):
                    def slow_me(route):          # 처리기는 인자 하나(둘이면 Playwright 가 request 를 넘긴다)
                        time.sleep(d)
                        route.continue_()
                    return slow_me
                ta.route("**/tbox/api/me", slow_after(delay))
            text = " 라%d" % int(delay * 10)
            type_body(ta, text)
            tw = time.time()
            while time.time() - tw < 20 and not (any(r.request.url.endswith("/heartbeat") for r in held) and any(r.request.method == "PATCH" for r in held)):
                ta.wait_for_timeout(250)
            done[0] = True
            nhb = len([r for r in held if r.request.url.endswith("/heartbeat")])
            t0 = time.time()
            resps = [r.fetch() for r in held]           # 서버에 옛 토큰 그대로 — 진짜 403
            for r, x in zip(held, resps):
                r.fulfill(response=x)
            s = wait_state(ta, ("저장됨", "저장 못 함"))
            ta.wait_for_timeout(1500)
            txt = server_text()
            step("Q:T-21 모의 S4 동시 403(늦춤 %.1f초) — 붙잡은 둘 다 서버 403 · /api/me 1 · 저장 다시 1 · heartbeat 다시 %d · 저장됨 · 서버 본문에 친 글자" % (delay, nhb),
                 nhb >= 1 and all(x.status == 403 for x in resps) and me_n(t0) == 1 and patch_n(t0) == 1 and hb_n(t0) == nhb and s == "저장됨" and text in txt,
                 ([x.status for x in resps], me_n(t0), patch_n(t0), hb_n(t0), nhb, s, text in txt))
            ta.close()

        # S5′ 경합 — heartbeat 403 이 먼저 토큰을 바꾸고 2.5초 뒤 옛 토큰으로 이미 나간 저장의 403 이 온다 → /api/me 없이 지금 토큰으로 한 번 다시
        held = []
        done = [False]

        def hold2(route):
            if not done[0] and route.request.method in ("PATCH", "POST"):
                held.append(route)
            else:
                route.continue_()
        ta = open_editor("stale", [(PAGE, hold2), (HB, hold2)])
        type_body(ta, " 마")
        tw = time.time()
        while time.time() - tw < 20 and not (any(r.request.url.endswith("/heartbeat") for r in held) and any(r.request.method == "PATCH" for r in held)):
            ta.wait_for_timeout(250)
        done[0] = True
        hbr = [r for r in held if r.request.url.endswith("/heartbeat")]
        svr = [r for r in held if r.request.method == "PATCH"]
        t0 = time.time()
        for r in hbr:
            x = r.fetch()
            r.fulfill(response=x)
        ta.wait_for_timeout(2500)
        t1 = time.time()
        ys = []
        for r in svr:
            y = r.fetch()
            ys.append(y.status)
            r.fulfill(response=y)
        s = wait_state(ta, ("저장됨", "저장 못 함"))
        ta.wait_for_timeout(1500)
        txt = server_text()
        note("S5′ 수치 — heartbeat 확인 /api/me %d · 늦은 저장 403 뒤 /api/me %d · 저장 다시 보내기 %d · 상태 「%s」" % (me_n(t0, t1), me_n(t1), patch_n(t1), s))
        step("Q:T-21 모의 S5′ 경합 — heartbeat 의 확인 /api/me 1 뒤, 옛 토큰 저장의 늦은 403 → /api/me 추가 0 · 저장 다시 1 · 저장됨 · 서버 본문에 「마」",
             len(hbr) >= 1 and len(svr) == 1 and ys == [403] and me_n(t0, t1) == 1 and me_n(t1) == 0 and patch_n(t1) == 1 and s == "저장됨" and " 마" in txt,
             (len(hbr), len(svr), ys, me_n(t0, t1), me_n(t1), patch_n(t1), s, " 마" in txt, ta.inner_text("#msg").strip()[:40]))
        ta.close()

        # S6 다른 계정(모의 — /api/me 아이디가 다름) — 저장 1 · 다시 0 · 안내 한 줄 · 그 뒤 쓰기 0
        ta = open_editor("stale", [(HB, HB_OK)])
        mm.mode = "other"
        t0 = time.time()
        type_body(ta, " 바")
        s = wait_state(ta, ("저장 못 함",))
        ta.wait_for_timeout(1500)
        msg = ta.inner_text("#msg").strip()
        n_w = patch_n(t0)
        type_body(ta, " 사")
        ta.wait_for_timeout(4000)
        mm.mode = None
        txt = server_text()
        step("Q:T-21 모의 S6 다른 계정 — 저장 1 · 다시 0 · 「저장 못 함」 · 안내 한 줄 · 그 뒤 저장 요청 0 · 서버 본문에 「바」「사」 없음",
             n_w == 1 and patch_n(t0) == 1 and s == "저장 못 함" and msg == "다른 계정으로 로그인되어 이 탭은 멈췄습니다 — 새로 고치세요" and " 바" not in txt and " 사" not in txt,
             (n_w, patch_n(t0), s, msg[:40]))
        ta.close()

        # S7 자동 요청 조용함 — heartbeat 가 계속 403(같은 계정 · 같은 토큰 — 편집 권한을 잃은 탭) · 사람 입력 0 · 130초
        tp = time.time()
        ta = open_editor(None, [(HB, DENY)])
        ta.wait_for_timeout(2000)
        # 부팅의 /api/me(화면을 연 사람 요청)는 빼고 첫 heartbeat 뒤부터 센다
        hb_t = [r["t"] for r in mlog if r["m"] == "POST" and r["u"] == HB and r["t"] >= tp]
        th = hb_t[0] if hb_t else time.time()
        boot_all, boot_bg = me_n(th), me_n(th, bg="1")
        ls0 = session_seen(SID)
        t0 = time.time()
        note("S7 heartbeat 403 · 사람 입력 없이 130초 — %s" % time.strftime("%T"))
        ta.wait_for_timeout(130000)
        ls1 = session_seen(SID)
        n_hb, n_all, n_plain = hb_n(t0), me_n(t0), me_n(t0, bg="")
        note("S7 수치 — heartbeat %d · /api/me %d(자동 표식 없는 것 %d) · 세션 활동 시각 %s → %s" % (n_hb, n_all, n_plain, ls0 or "?", ls1 or "?"))
        step("Q:T-21 모의 S7 첫 heartbeat 403 의 세션 확인 한 번은 자동 요청 — /api/me 1 · 자동 요청 표식", bool(hb_t) and boot_all == 1 and boot_bg == 1, (len(hb_t), boot_all, boot_bg))
        step("Q:T-21 모의 S7 130초 — heartbeat %d(403) · /api/me 자동 표식 없는 요청 0 · 같은 토큰이라 다시 묻지 않음(/api/me 0) · 세션 last_seen_at·act_last_seen_at 그대로" % n_hb,
             n_hb >= 4 and n_plain == 0 and n_all == 0 and ls0 != "" and ls1 == ls0, (n_hb, n_all, n_plain, ls0 != "", ls1 == ls0))
        # 사람 요청도 같은 토큰으로 이미 same 이면 다시 묻지 않는다(원래 403 그대로)
        ta.route("**" + PAGE, only("PATCH", DENY))
        t2 = time.time()
        type_body(ta, " 차")
        s = wait_state(ta, ("저장 못 함",))
        ta.wait_for_timeout(1000)
        step("Q:T-21 모의 S7 같은 토큰으로 이미 same — 사람 저장의 403 도 /api/me 없이 그대로 · 「저장 못 함」", patch_n(t2) >= 1 and me_n(t2) == 0 and s == "저장 못 함", (patch_n(t2), me_n(t2), s))
        # 탭이 다시 보이면(숨어 있는 동안 다른 탭에서 다시 로그인했을 수 있다) 한 번 다시 묻는다 — 자동 요청이 부른 확인이라 표식 달고
        t3 = time.time()
        ta.evaluate("""() => { Object.defineProperty(document, 'hidden', {configurable: true, get: () => true});
          Object.defineProperty(document, 'visibilityState', {configurable: true, get: () => 'hidden'});
          document.dispatchEvent(new Event('visibilitychange')); }""")
        ta.wait_for_timeout(1200)
        ta.evaluate("""() => { delete document.hidden; delete document.visibilityState; document.dispatchEvent(new Event('visibilitychange')); }""")
        ta.wait_for_timeout(2500)
        step("Q:T-21 모의 S7 탭이 다시 보이면 heartbeat 403 을 한 번 다시 묻는다 — /api/me 1(자동 요청 표식) · 표식 없는 것 0",
             hb_n(t3) >= 1 and me_n(t3) == 1 and me_n(t3, bg="1") == 1, (hb_n(t3), me_n(t3), me_n(t3, bg="1")))
        ls2 = session_seen(SID)
        # 대조 — 사람 요청(표식 없는 /api/me) 한 번이면 활동 시각이 밀린다(위 셈이 살아 있다)
        ctl = ta.evaluate("() => fetch('/tbox/api/me', {credentials: 'same-origin', cache: 'no-store'}).then(r => r.status)")
        ta.wait_for_timeout(500)
        ls3 = session_seen(SID)
        step("Q:T-21 모의 S7 사람 입력·다시 보임 뒤에도 활동 시각 그대로 · 대조: 사람 요청 한 번(%s)이면 last_seen_at 이 밀린다" % ctl,
             ls2 == ls0 and ctl == 200 and ls3 != "" and ls3 != ls0, (ls2 == ls0, ctl, ls3 != ls0))
        ta.close()

        # S8 t-box 파일 화면(writeFetch) — 옛 토큰으로 새 폴더 → 같은 계정이면 한 번 다시 → 성공 · 같은 토큰의 진짜 403 은 두 번째부터 다시 묻지 않음
        mm.mode = "stale"
        tf = page_of(c)
        tf.on("request", logreq)
        tf.goto(SITE + "/tbox/", wait_until="networkidle")
        tf.wait_for_timeout(600)
        mm.mode = None

        def folder_posts(since):
            return len([r for r in mlog if r["m"] == "POST" and r["u"].split("?")[0] == "/tbox/api/folders" and r["t"] >= since])

        def new_folder():
            tf.click("#folder-new")
            tf.wait_for_selector("#folder-dialog[open]", timeout=5000)
            tf.fill("#folder-name", "uxr1-mock-" + uuid.uuid4().hex[:5])
            opts = tf.evaluate("() => [...document.querySelectorAll('#folder-level-select option')].map(o => o.value).filter(v => v)")
            if opts:
                tf.select_option("#folder-level-select", "internal" if "internal" in opts else opts[0])
            tf.click("#folder-submit")
            tf.wait_for_timeout(2500)
        t0 = time.time()
        new_folder()
        dlg = tf.evaluate("() => document.getElementById('folder-dialog').open")
        step("Q:T-21 모의 S8 t-box 새 폴더(옛 토큰) — POST 2(원래 403 + 다시 1) · /api/me 1 · 성공(창 닫힘)", folder_posts(t0) == 2 and me_n(t0) == 1 and not dlg, (folder_posts(t0), me_n(t0), dlg))
        if dlg:
            tf.keyboard.press("Escape")
        tf.route("**/tbox/api/folders", only("POST", DENY))
        t1 = time.time()
        new_folder()
        tf.click("#folder-submit")
        tf.wait_for_timeout(2000)
        m = tf.inner_text("#folder-dialog-msg").strip() if tf.evaluate("() => document.getElementById('folder-dialog').open") else ""
        step("Q:T-21 모의 S8 t-box 같은 토큰의 진짜 403 두 번 — POST 2(다시 보내기 0) · /api/me 1(두 번째는 묻지 않음) · 창에 까닭",
             folder_posts(t1) == 2 and me_n(t1) == 1 and m != "", (folder_posts(t1), me_n(t1), m[:40]))
        tf.close()
        # 정리 — 이 문맥의 세션을 닫는다
        lp = c.new_page()
        lp.goto(SITE + "/tbox/login", wait_until="load")
        lp.evaluate("""async () => { const me = await fetch('/tbox/api/me', {credentials: 'same-origin', cache: 'no-store'}); if (me.status !== 200) return;
          const j = await me.json(); await fetch('/tbox/api/logout', {method: 'POST', credentials: 'same-origin', headers: {'X-CSRF-Token': j.csrf_token}}); }""")
        c.close()
    guard("Q:T-21 모의", flow_session_mock)

    # 손님 — 최근이 비면 판째 숨는다
    def guest():
        c = ctx(br, S_G1, 412, "light")
        pg = page_of(c)
        go(pg, "/community/workspace")
        cc, d = G.req("GET", "/community/api/me/recent")
        empty = len(d.get("pages") or []) == 0
        step("Q:T-14 최근이 비면 「최근」 판 숨김(손님 — 지운 뒤)", empty and not pg.evaluate(VIS, "#community-home-recent"), (empty, pg.evaluate(VIS, "#community-home-recent")))
        c.close()
    guard("Q:T-14 손님", guest)

    br.close()

print("\n── 전체")
step("콘솔 오류·pageerror 0", not errors, errors[:6])
step("브라우저 기본 창(alert·confirm·prompt) 0", not dialogs, dialogs[:4])
print("SMOKE_PASS=%d" % passes)
print("SMOKE_FAIL=%d" % fails)
sys.exit(0 if BEFORE or fails == 0 else 1)
