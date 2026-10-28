#!/usr/bin/env python3
# tests/browser/dbg_u3_ui.py — 디버깅 묶음 U3 「커뮤니티를 쓰기 쉽게」(사용자 2026-09-26 22:5x 「커뮤니티 사용 방법이 어려워 보인다」 · 에이전트 시연)를
#                              진짜 브라우저로 눌러 본다
#
# 부르는 쪽: tests/dbg_u3_ui.sh (임시 인스턴스 · 임시 DB `tbox_d3u_*`. 운영 80·8082·8081 에는 요청하지 않는다).
# 쓰는 법: py_secret S_E1 S_E2 S_A1 S_G1 -- python3 dbg_u3_ui.py <SITE> <UA> <SHOTS> [before]
#   세션 넷은 **표준입력 네 줄**(R-62 — 명령 인자에 비밀 0). 관리자 세션은 부르는 셸이 재인증해 둔 것이다(감사 로그 화면).
#   넷째 인자 `before` 는 고치기 전 판(fd96aca)을 찍을 때 — 판정은 참고용이고 스크린샷만 쓴다(종료 코드 0).
# 폭·색: 360 · 412(둘 다 폰 — 터치 · 모바일) · 820(터치 — 폴드 펼침, `pointer: coarse`) · 1280 × 라이트 · 다크 = 8 조합(DBGU3_COMBOS 로 줄임).
# 보는 것(항목 번호는 지시서 「고칠 것」)
#   공통  문서 가로 넘침 0 · 콘솔 오류·pageerror 0(없는 주소 화면의 문서 자체 404 한 줄만 예외) · 브라우저 기본 창 0 ·
#         (폰·터치) 누르는 것의 누름 자리 44px · 보이는 상자 안 어디를 눌러도 그 자신(U2 시험 방식)
#   1     폰 폭 글 화면 — 속성 요약 한 줄 + 「속성 더보기」(펼치면 속성 줄 · 다시 접힘) · 본문 첫 줄이 첫 화면(창 높이의 70% 안) · 넓은 화면은 속성 줄 그대로
#   2     경로 줄 첫 칸 — 내 공간 / 「아이디 의 공간」(남의 공간 · 링크 아님) / 팀 이름(팀원은 링크) / 게시판 이름(게시판 링크)
#   3     댓글 주소 링크 — http·https 만 `<a>`(사이트 안 같은 창 · 밖 새 창 · rel noopener noreferrer) · javascript: · 아이디 든 주소는 글자 · 끝 문장 부호·붙은 한글 떼기 ·
#         댓글 글자 대비 AA(4.5:1 이상)
#   4     데이터베이스 보드 — 1280 에서 열 여섯이 다 보임 · 넘치는 폭에서는 가장자리 표시(data-more) + 안내 줄 · 끝까지 밀면 반대쪽 표시
#   5     휴지통 자리 — 커뮤니티 사이드바 「내 휴지통」(첫 묶음 끝 = t-box 「내 휴지통」과 같은 자리·이름) · 첫 화면에서 누르면 창 · 다른 화면에서 누르면 첫 화면의 창 · 손님 숨김
#   6     없는 주소 — 브라우저 화면은 「찾을 수 없는 페이지」 + 첫 화면 링크(로그인 전·후 같은 글자) · 상태 404
#   7     감사 로그 — 1280 에서 표가 칸 안(요약 칸 잘림 0) · 역할·결과·등급 칸 글자 한글(원래 값은 title) · 거르기 값 그대로
#   8     공개 게시 거부 문장 — 사내 그림이 든 페이지를 「공개」 게시판에 게시 → 창에 쉬운 문장(게시판 등급 이름 · 할 일)
#   9     820 터치 — t-box 파일 표의 행 동작 아이콘이 두 줄로 접혀도 넓힌 자리가 위아래 이웃을 가져가지 않는다
#   11    도움말 문구 ↔ 실제 단추 글자(좁은 폭 「＋ 페이지」 · 넓은 폭 「하위 페이지 만들기」 — 데이터베이스도)
#   12    손님이 막힌(403) 페이지 — 머리에 「사용 방법」 밖의 단추(이력 · 더보기 · 편집) 0 · 속성·요약·댓글·경로 0
#   13    손님 「내 공간」 설명 — 할 수 없는 「공개 범위」 문장 대신 읽기 안내(직원은 그대로)
# 나가는 값: 마지막 줄 `SMOKE_FAIL=<n>` · 종료 코드 0/1
import base64
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
BEFORE = len(sys.argv) > 4 and sys.argv[4] == "before"
S_E1 = sys.stdin.readline().rstrip("\n")
S_E2 = sys.stdin.readline().rstrip("\n")
S_A1 = sys.stdin.readline().rstrip("\n")
S_G1 = sys.stdin.readline().rstrip("\n")
if not (S_E1 and S_E2 and S_A1 and S_G1):
    print("세션 넷을 표준입력으로 주어야 한다 — 시작하지 않는다")
    sys.exit(2)
HOST = re.match(r"https?://([^/:]+)", SITE).group(1)
COMBOS = [(int(w), t) for w, t in (c.split(":") for c in (os.environ.get("DBGU3_COMBOS") or "360:light,360:dark,412:light,412:dark,820:light,820:dark,1280:light,1280:dark").split(","))]
os.makedirs(SHOTS, exist_ok=True)
fails = 0
passes = 0
dialogs = []
errors = []
TAG = uuid.uuid4().hex[:6]
PNG = base64.b64decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==")


def step(name, ok, detail=""):
    global fails, passes
    if ok:
        passes += 1
    else:
        fails += 1
    print("  %s %s%s" % ("✔" if ok else "✖", name, ("  — " + str(detail)[:400]) if (detail != "" and not ok) else ""), flush=True)


def note(msg):
    print("  · " + msg, flush=True)


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
        bnd = "----dbgu3" + uuid.uuid4().hex
        parts = []
        fields = [("access_level", level), ("description", "")] + ([("team_id", str(team_id))] if team_id else [])
        for k, v in fields:
            parts.append(("--%s\r\nContent-Disposition: form-data; name=\"%s\"\r\n\r\n%s\r\n" % (bnd, k, v)).encode())
        parts.append(("--%s\r\nContent-Disposition: form-data; name=\"file\"; filename=\"%s\"\r\nContent-Type: application/octet-stream\r\n\r\n" % (bnd, name)).encode() + data + b"\r\n")
        parts.append(("--%s--\r\n" % bnd).encode())
        return self.req("POST", "/tbox/api/files", raw=b"".join(parts), ctype="multipart/form-data; boundary=" + bnd)


def T(t):
    return [{"t": t, "m": []}]


def B(tp, **kw):
    d = {"id": str(uuid.uuid4()), "type": tp}
    d.update(kw)
    return d


def page_id(d):
    return ((d.get("page") or {}).get("id")) if isinstance(d, dict) else None


E = Api(S_E1)
E2 = Api(S_E2)
A = Api(S_A1)
G = Api(S_G1)
print("── 준비 (임시 DB 에만 쓴다)")
step("네 계정 /api/me 200", bool(E.csrf and E2.csrf and A.csrf and G.csrf), (bool(E.csrf), bool(E2.csrf), bool(A.csrf), bool(G.csrf)))
E1_NAME = E.me.get("username", "claude_employee01")

# t-box 파일 셋(파일 표의 행 동작 아이콘 — 9번)
for n in ("DBGU3-%s-분기_보고서.csv" % TAG, "DBGU3-%s-회의록.txt" % TAG, "DBGU3-%s-사진.png" % TAG):
    E.upload(n, PNG if n.endswith(".png") else ("dbgu3 %s\n" % n).encode())

# 1·3 — 내 페이지(사내 공개 · 상태 · 태그 둘 · 본문 세 문단 · 댓글에 주소)
LONG = "본문 첫 줄 — 폰에서 글 화면을 열면 이 줄이 첫 화면에 보여야 한다(속성 여섯 줄이 밀어내지 않게). "
doc = {"schema": 1, "blocks": [
    B("paragraph", text=T(LONG + TAG)),
    B("paragraph", text=T("둘째 문단 — 속성은 제목 아래 한 줄 요약으로 접히고 「속성 더보기」로 펼친다.")),
    B("paragraph", text=T("셋째 문단 — 댓글의 주소는 링크가 된다.")),
]}
c, d = E.req("POST", "/community/api/pages", {"title": "DBGU3 글 화면 " + TAG, "doc": doc})
P_OWN = page_id(d)
step("내 페이지 201", c == 201 and bool(P_OWN), (c, str(d)[:120]))
if P_OWN:
    step("  사내로 공개 200", E.req("PATCH", "/community/api/pages/%s/visibility" % P_OWN, {"visibility": "internal"})[0] == 200)
    step("  상태 진행 200", E.req("PATCH", "/community/api/pages/%s/properties" % P_OWN, {"status": "doing"})[0] == 200)
    step("  태그 둘 200", E.req("PUT", "/community/api/pages/%s/tags" % P_OWN, {"tags": ["휴대폰", "U3"]})[0] == 200)
INTERNAL_URL = SITE + "/community/p/" + (P_OWN or "x")
COMMENT = ("사이트 안: %s\n"
           "밖: https://example.com/docs?q=1&x=2.\n"
           "괄호 (https://example.org/a/b) 끝.\n"
           "한글이 붙음 https://example.net/path에서 보세요\n"
           "스크립트 javascript:alert(1) 는 글자\n"
           "아이디 든 주소 https://good.example@evil.example/x 는 글자\n"
           "http://example.com/plain") % INTERNAL_URL
if P_OWN:
    c, d = E.req("POST", "/community/api/pages/%s/comments" % P_OWN, {"body": COMMENT})
    step("  주소가 든 댓글 201", c == 201, (c, str(d)[:120]))

# 2 — 팀 공간 페이지 · 게시판 글
c, sp = E.req("GET", "/community/api/spaces")
TEAM = next((s for s in (sp.get("spaces") or []) if s.get("kind") == "team"), None) if isinstance(sp, dict) else None
step("직원의 팀 공간이 있다", bool(TEAM), str(sp)[:160])
P_TEAM = None
if TEAM:
    c, d = E.req("POST", "/community/api/pages", {"title": "DBGU3 팀 페이지 " + TAG, "space_id": TEAM["id"]})
    P_TEAM = page_id(d)
    step("  팀 페이지 201", c == 201 and bool(P_TEAM), (c, str(d)[:120]))
c, bl = E.req("GET", "/community/api/boards")
BOARDS = (bl.get("boards") or []) if isinstance(bl, dict) else []
BOARD_W = next((b for b in BOARDS if b.get("can_write") and b.get("access_level") == "internal"), None)
step("직원이 쓸 수 있는 사내 게시판이 있다", bool(BOARD_W), str(BOARDS)[:160])
P_POST = None
if BOARD_W:
    c, d = E.req("POST", "/community/api/boards/%s/pages" % BOARD_W["id"], {"title": "DBGU3 글 " + TAG, "tags": ["질문", "폰"]})
    P_POST = page_id(d)
    step("  게시판 글 201", c == 201 and bool(P_POST), (c, str(d)[:120]))
    if P_POST:
        ver = ((d.get("page") or {}).get("doc_version")) or 1
        c, d = E.req("PATCH", "/community/api/pages/" + P_POST, {"doc": {"schema": 1, "blocks": [B("paragraph", text=T("게시판 글 본문 첫 줄 " + TAG))]}, "doc_version": ver})
        step("  게시판 글 본문 200", c == 200, (c, str(d)[:120]))

# 4 — 데이터베이스 + 상태 여섯 칸에 행
c, d = E.req("POST", "/community/api/pages", {"title": "DBGU3 보드 " + TAG, "database": True})
P_DB = page_id(d)
step("데이터베이스 201", c == 201 and bool(P_DB), (c, str(d)[:120]))
if P_DB:
    okn = 0
    for st in (None, "todo", "doing", "review", "done", "hold", "done"):
        c, d = E.req("POST", "/community/api/pages", {"title": "행 %s %s" % (st or "없음", TAG), "parent_id": P_DB})
        rid = page_id(d)
        if rid and st:
            c = E.req("PATCH", "/community/api/pages/%s/properties" % rid, {"status": st})[0]
        okn += 1 if rid and c in (200, 201) else 0
    step("  행 일곱(상태 여섯 칸) 만들기", okn == 7, okn)

# 8 — 관리자의 페이지에 사내 그림 → 「공개」 게시판에 게시하면 거부
c, d = A.upload("DBGU3-%s-사내그림.png" % TAG, PNG, "internal")
IMG = (d.get("file") or d).get("id") if isinstance(d, dict) else None
step("관리자 사내 그림 올리기 201", c == 201 and bool(IMG), (c, str(d)[:120]))
P_IMG = None
BOARD_PUB = next((b for b in ((A.req("GET", "/community/api/boards")[1] or {}).get("boards") or []) if b.get("can_write") and b.get("access_level") == "public"), None)
step("관리자가 쓸 수 있는 「공개」 게시판이 있다", bool(BOARD_PUB))
def attach(api, title, blocks):
    """파일은 페이지를 만든 뒤 붙인다(창구 규칙 — file_ref) — 만들고 본문을 고친다"""
    c, d = api.req("POST", "/community/api/pages", {"title": title})
    pid = page_id(d)
    if not pid:
        return None, (c, str(d)[:120])
    ver = ((d.get("page") or {}).get("doc_version")) or 1
    c, d = api.req("PATCH", "/community/api/pages/" + pid, {"doc": {"schema": 1, "blocks": blocks}, "doc_version": ver})
    return (pid if c == 200 else None), (c, str(d)[:160])


if IMG:
    P_IMG, info = attach(A, "DBGU3 사내 그림 " + TAG, [B("paragraph", text=T("사내 등급 그림이 든 페이지")), B("image", file_ref="tbox:" + IMG, width="normal")])
    step("  그림 든 페이지(만든 뒤 붙이기) 200", bool(P_IMG), info)

# 8 — 창구가 주는 거부 문장(서버 문장 — 상태 코드·사유 코드는 그대로)
EXPECT_PUB = "이 페이지에 「공개」 게시판에 올릴 수 없는 그림이나 파일이 들어 있습니다. 자료실에서 그 파일의 등급을 「공개」로 올리거나, 페이지에서 빼고 다시 게시하세요"
EXPECT_VIS = "이 페이지(하위 포함)에 새 공개 범위의 사람이 볼 수 없는 그림이나 파일이 들어 있습니다(파일 등급이 더 좁음). 자료실에서 그 파일의 등급을 올리거나, 페이지에서 빼고 다시 바꾸세요"
if P_IMG and BOARD_PUB:
    c, d = A.req("POST", "/community/api/pages/%s/publish" % P_IMG, {"board_id": BOARD_PUB["id"]})
    if BEFORE:
        note("8 (고치기 전 판) 게시 거부 %s — %s" % (c, str(d)[:200]))
    else:
        step("8 창구 — 사내 그림 → 「공개」 게시판 400 file_narrower · 쉬운 문장", c == 400 and d.get("reason") == "file_narrower" and d.get("error") == EXPECT_PUB, (c, d))
c, d = E.upload("DBGU3-%s-팀그림.png" % TAG, PNG, "team", (TEAM or {}).get("team_id"))
TIMG = (d.get("file") or d).get("id") if isinstance(d, dict) else None
if TIMG:
    P_TIMG, info = attach(E, "DBGU3 팀 그림 " + TAG, [B("image", file_ref="tbox:" + TIMG, width="small")])
    c, d = E.req("PATCH", "/community/api/pages/%s/visibility" % P_TIMG, {"visibility": "internal"}) if P_TIMG else (0, {"_attach": info})
    if BEFORE:
        note("8 (고치기 전 판) 범위 넓히기 거부 %s — %s" % (c, str(d)[:200]))
    else:
        step("8 창구 — 팀 그림이 든 페이지를 사내로 넓히기 400 file_narrower · 쉬운 문장", c == 400 and d.get("reason") == "file_narrower" and d.get("error") == EXPECT_VIS, (c, d))
else:
    step("8 팀 등급 그림 올리기", False, (c, str(d)[:120]))

# ─── 측정 도우미 ──────────────────────────────────────────────────
OVER = "() => document.documentElement.scrollWidth - document.documentElement.clientWidth"

# 터치 대상 — tests/browser/dbg_u2_ui.py 의 TOUCH 와 같은 규칙(자기 상자 ∪ ::before ≥ 44 · 보이는 상자 다섯 점이 자기 자신)
TOUCH = r"""(root) => {
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
  const scope = document.querySelector(root) || document;
  for (const e of scope.querySelectorAll('button, a[href], summary, input[type=checkbox], input[type=radio]')) {
    if (e.closest('#page-doc, .doc, .changelog__list, #page-comments .list__body')) continue;
    const dlg = e.closest('dialog'); if (dlg && !dlg.open) continue;
    if (e.disabled) continue;
    const cs = getComputedStyle(e);
    if (cs.visibility === 'hidden' || cs.display === 'none') continue;
    if (e.tagName === 'A' && cs.display === 'inline') continue;
    if (e.matches('input') && e.closest('label')) continue;
    const r = e.getBoundingClientRect();
    if (r.width < 2 || r.height < 2 || r.right < 0 || r.bottom < 0 || r.left > W || r.top > H) continue;
    if (e.checkVisibility && !e.checkVisibility({visibilityProperty: true, contentVisibilityAuto: true})) continue;
    const c0 = document.elementFromPoint(Math.min(W - 1, Math.max(0, (r.left + r.right) / 2)), Math.min(H - 1, Math.max(0, (r.top + r.bottom) / 2)));
    if (!c0 || !(c0 === e || e.contains(c0) || c0.contains(e))) continue;
    n++;
    const [w, h] = box(e);
    const name = (e.getAttribute('aria-label') || e.textContent || e.tagName).trim().replace(/\s+/g, ' ').slice(0, 18);
    selfHit(e, r, name);
    if (w < 43.5 || h < 43.5) out.push([name, Math.round(w), Math.round(h)]);
  }
  return {n: n, bad: out, stolen: stolen};
}"""

# 첫 문단 첫 줄의 아래 끝(창 위에서) — Range 로 글자 줄 상자
FIRST_LINE = r"""() => {
  const doc = document.getElementById('page-doc');
  if (!doc) return null;
  const w = document.createTreeWalker(doc, NodeFilter.SHOW_TEXT, { acceptNode: n => n.textContent.trim() ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_REJECT });
  const t = w.nextNode(); if (!t) return null;
  const rg = document.createRange(); rg.setStart(t, 0); rg.setEnd(t, Math.min(1, t.length));
  const r = rg.getClientRects()[0]; if (!r) return null;
  return { bottom: Math.round(r.bottom), top: Math.round(r.top), h: window.innerHeight, text: t.textContent.slice(0, 12) };
}"""

# 글자색 대비(WCAG) — 요소 글자색과 가장 가까운 불투명 바탕
CONTRAST = r"""(sel) => {
  const e = document.querySelector(sel); if (!e) return null;
  const rgb = s => { const m = s.match(/rgba?\(([^)]+)\)/); if (!m) return null; const p = m[1].split(/[ ,\/]+/).filter(Boolean).map(Number); return {r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1}; };
  const lum = c => { const f = v => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }; return 0.2126 * f(c.r) + 0.7152 * f(c.g) + 0.0722 * f(c.b); };
  const fg = rgb(getComputedStyle(e).color);
  let bg = null;
  for (let a = e; a; a = a.parentElement) { const c = rgb(getComputedStyle(a).backgroundColor); if (c && c.a > 0.95) { bg = c; break; } }
  if (!bg) bg = {r: 255, g: 255, b: 255, a: 1};
  const L1 = lum(fg), L2 = lum(bg);
  return Math.round(((Math.max(L1, L2) + 0.05) / (Math.min(L1, L2) + 0.05)) * 100) / 100;
}"""


def touch(pg, what, root="body"):
    try:
        r = pg.evaluate(TOUCH, root)
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


def ctx(br, sess, w, theme):
    phone = w < 720
    touchdev = phone or w == 820          # 820 = 폴드 펼침(터치 — pointer: coarse)
    c = br.new_context(user_agent=UA, viewport={"width": w, "height": 780 if phone else 900}, device_scale_factor=1,
                       has_touch=touchdev, is_mobile=phone, color_scheme="light")
    ck = [{"name": "tbox_theme", "value": theme, "domain": HOST, "path": "/"}]
    ck.append({"name": "qaredi_sidebar", "value": "open", "domain": HOST, "path": "/"})  # 카페 C5 — 펼친 셸을 잰다(쿠키 qaredi_sidebar 없으면 접힘이 기본)
    if sess:
        ck.append({"name": "__Host-tbox_session", "value": sess, "domain": HOST, "path": "/", "httpOnly": True, "secure": True})
    c.add_cookies(ck)
    return c


def page_of(c):
    pg = c.new_page()
    pg.on("dialog", lambda d: (dialogs.append(d.type + ": " + d.message[:60]), d.dismiss()))
    pg.on("pageerror", lambda e: errors.append("pageerror " + str(e)[:160]))
    pg.on("console", lambda m: errors.append("console " + pg.url.replace(SITE, "")[:48] + " " + m.text[:160]) if m.type == "error" else None)
    return pg


def go(pg, path):
    r = pg.goto(SITE + path, wait_until="networkidle", timeout=30000)
    pg.wait_for_timeout(400)
    return r


def guard(name, fn):
    try:
        fn()
    except Exception as ex:
        step(name + " — 진행 중 예외", False, str(ex).split("\n")[0][:200])


def crumb_root(pg):
    return pg.evaluate("""() => { const c = document.querySelector('#page-crumb'); if (!c || c.hidden) return null;
      const f = c.firstElementChild; return f ? {tag: f.tagName, text: f.textContent.trim(), href: f.getAttribute('href')} : null; }""")


def open_drawer(pg):
    """좁은 폭(960 미만)은 사이드바가 서랍 — 단추로 연다"""
    if pg.evaluate("() => { const t = document.getElementById('nav-toggle'); return !!t && t.getClientRects().length > 0 && t.getAttribute('aria-expanded') !== 'true'; }"):
        pg.click("#nav-toggle")
        pg.wait_for_timeout(250)


print("── 화면 (%s)" % ("고치기 전 판 — 스크린샷만" if BEFORE else "고친 판"))
publish_done = False
with sync_playwright() as p:
    br = p.chromium.launch()
    for w, theme in COMBOS:
        tag = "%d-%s" % (w, theme)
        phone = w < 720
        touchdev = phone or w == 820
        print("\n── %s" % tag, flush=True)
        c = ctx(br, S_E1, w, theme)
        pg = page_of(c)

        # ── 1 · 2 · 3 · 11 내 글 화면 ─────────────────────────────
        def own_page():
            go(pg, "/community/p/" + P_OWN)
            pg.wait_for_selector("#page-comments .list__item", timeout=8000)
            no_overflow(pg, "내 글 화면 " + tag)
            shot(pg, "c-page-own-%s" % tag)
            cr = crumb_root(pg)
            step("2 경로 줄 첫 칸 = 「내 공간」(커뮤니티 첫 화면 링크) " + tag, bool(cr) and cr["text"] == "내 공간" and cr["tag"] == "A" and cr["href"] == "/community/", cr)
            fl = pg.evaluate(FIRST_LINE)
            if phone:
                vis = pg.evaluate("() => { const s = document.getElementById('page-props-summary'); const p = document.getElementById('page-props');"
                                  " return {summary: !!s && getComputedStyle(s).display !== 'none' && !s.hidden, props: !!p && getComputedStyle(p).display !== 'none' && !p.hidden,"
                                  " line: s ? s.textContent.replace(/\\s+/g, ' ').trim() : ''}; }")
                step("1 폰 폭 — 속성 요약 한 줄이 보이고 속성 줄은 접혀 있다 " + tag, vis["summary"] and not vis["props"], vis)
                step("1 요약에 공개 범위·쓴 사람(소유자)·태그 수 " + tag, "사내" in vis["line"] and E1_NAME in vis["line"] and "태그 2" in vis["line"], vis["line"])
                step("1 폰 폭 — 본문 첫 줄이 첫 화면 위쪽 절반 안 %s" % tag, bool(fl) and fl["bottom"] <= 0.5 * fl["h"], fl)
                touch(pg, "내 글 화면 " + tag)
                if not BEFORE:
                    pg.click("#page-props-toggle")
                    pg.wait_for_timeout(250)
                    op = pg.evaluate("() => { const p = document.getElementById('page-props'); const t = document.getElementById('page-props-toggle');"
                                     " return {props: getComputedStyle(p).display !== 'none', exp: t.getAttribute('aria-expanded'), label: t.textContent.trim(),"
                                     " status: !!document.querySelector('#page-props #page-status:not([disabled])'), tags: !!document.querySelector('#page-tag-input:not([hidden])')}; }")
                    step("1 「속성 더보기」 → 속성 줄 펼침 · 「속성 접기」 · 상태 단추·태그 칸 그대로 " + tag,
                         op["props"] and op["exp"] == "true" and op["label"] == "속성 접기" and op["status"] and op["tags"], op)
                    shot(pg, "c-page-own-open-%s" % tag)
                    touch(pg, "펼친 속성 줄 " + tag, "#page-props")
                    pg.click("#page-props-toggle")
                    pg.wait_for_timeout(200)
                    step("1 다시 누르면 접힌다 " + tag, pg.evaluate("() => getComputedStyle(document.getElementById('page-props')).display === 'none'"))
            else:
                vis = pg.evaluate("() => { const s = document.getElementById('page-props-summary'); const p = document.getElementById('page-props');"
                                  " return {summary: !!s && getComputedStyle(s).display !== 'none' && !s.hidden, props: !!p && getComputedStyle(p).display !== 'none'}; }")
                step("1 넓은 폭 — 속성 줄 그대로 · 요약 줄 없음 " + tag, vis["props"] and not vis["summary"], vis)
                if touchdev:
                    touch(pg, "내 글 화면 " + tag)
            # 3 댓글 링크
            links = pg.evaluate("""() => [...document.querySelectorAll('#page-comments .list__body a')].map(a => ({text: a.textContent, href: a.getAttribute('href'), target: a.getAttribute('target'), rel: a.getAttribute('rel')}))""")
            body = pg.evaluate("() => { const b = document.querySelector('#page-comments .list__body'); return b ? b.textContent : ''; }")
            byhref = {l["href"]: l for l in links}
            inl = byhref.get(INTERNAL_URL)
            step("3 사이트 안 주소 → 링크 · 같은 창 · rel noopener noreferrer " + tag,
                 bool(inl) and not inl["target"] and "noopener" in (inl["rel"] or "") and "noreferrer" in (inl["rel"] or ""), links[:3])
            ext = byhref.get("https://example.com/docs?q=1&x=2")
            step("3 밖 주소 → 새 창(target _blank) · rel noopener noreferrer · 끝 마침표는 글자 " + tag,
                 bool(ext) and ext["target"] == "_blank" and "noopener" in (ext["rel"] or "") and ext["text"] == "https://example.com/docs?q=1&x=2", links)
            step("3 괄호 속 주소 — 닫는 괄호는 주소에서 뗀다 " + tag, "https://example.org/a/b" in byhref, list(byhref)[:8])
            step("3 한글이 붙은 주소 — 한글 앞에서 끊는다 " + tag, "https://example.net/path" in byhref, list(byhref)[:8])
            step("3 http 주소도 링크 " + tag, "http://example.com/plain" in byhref, list(byhref)[:8])
            step("3 javascript: · 아이디 든 주소는 링크가 아니고 글자로 남는다 " + tag,
                 not any((l["href"] or "").lower().startswith("javascript") or "@" in (l["href"] or "") for l in links)
                 and "javascript:alert(1)" in body and "https://good.example@evil.example/x" in body, links)
            step("3 댓글 안 링크는 http·https 만 " + tag, all(re.match(r"^https?://", l["href"] or "") for l in links) and len(links) >= 5, len(links))
            ratio = pg.evaluate(CONTRAST, "#page-comments .list__body")
            step("3 댓글 글자 대비 — AA(4.5:1) 넘어 본문 글자색 수준(7:1 이상 · 회색 4.8:1 이 아님) %s = %s" % (tag, ratio), isinstance(ratio, (int, float)) and ratio >= 7, ratio)
            pg.evaluate("() => document.getElementById('page-comments-wrap').scrollIntoView()")
            pg.wait_for_timeout(150)
            shot(pg, "c-comments-%s" % tag)
            # 11 도움말 문구 ↔ 단추 글자
            lab = pg.evaluate("""() => { const v = e => { if (!e) return null; const c = e.cloneNode(true);
                  if (window.matchMedia('(max-width: 719.98px)').matches) c.querySelectorAll('.narrow-hide').forEach(x => x.remove());
                  return c.textContent.replace(/\\s+/g, ' ').trim(); };
                  return {child: v(document.getElementById('page-new-child')), db: v(document.getElementById('page-new-db')),
                          shown: !!document.getElementById('page-new-child') && !document.getElementById('page-new-child').hidden}; }""")
            pg.click("[data-help-open]")
            pg.wait_for_timeout(300)
            help_txt = pg.evaluate("() => { const d = document.querySelector('dialog[open]'); return d ? d.textContent.replace(/\\s+/g, ' ') : ''; }")
            if lab["shown"]:
                want = ["「＋ %s」" % lab["child"], "「＋ %s」" % lab["db"]] if phone else ["「%s」" % lab["child"], "「%s」" % lab["db"]]
                step("11 도움말이 지금 보이는 단추 글자를 그대로 말한다 %s %s" % (tag, want), all(x in help_txt for x in want), [x for x in want if x not in help_txt])
            else:
                step("11 하위 페이지 단추가 보인다(내 페이지) " + tag, False, lab)
            pg.keyboard.press("Escape")
            pg.wait_for_timeout(200)
        guard("내 글 화면 " + tag, own_page)

        # ── 2 팀 · 게시판 글 ────────────────────────────────────
        def team_board():
            if P_TEAM:
                go(pg, "/community/p/" + P_TEAM)
                cr = crumb_root(pg)
                step("2 팀 공간 페이지 — 첫 칸 = 팀 이름(팀원은 그 팀 공간 링크) " + tag,
                     bool(cr) and cr["text"] == TEAM["name"] and cr["tag"] == "A" and cr["href"] == "/community/?space=%s" % TEAM["id"], cr)
                no_overflow(pg, "팀 페이지 " + tag)
                if w in (412, 1280):
                    shot(pg, "c-page-team-%s" % tag)
            if P_POST:
                go(pg, "/community/p/" + P_POST)
                cr = crumb_root(pg)
                step("2 게시판 글 — 첫 칸 = 게시판 이름(게시판 링크) " + tag,
                     bool(cr) and cr["text"] == BOARD_W["name"] and cr["tag"] == "A" and cr["href"] == "/community/b/%s" % BOARD_W["id"], cr)
                if phone:
                    # 게시판 글은 속성 줄이 가장 길다(상태 · 쓴 사람 · 공개 범위 · 게시판 · 태그 · 조회 — 여섯 줄)
                    fl = pg.evaluate(FIRST_LINE)
                    step("1 게시판 글(속성 여섯)도 본문 첫 줄이 첫 화면 위쪽 절반 안 %s" % tag, bool(fl) and fl["bottom"] <= 0.5 * fl["h"], fl)
                    shot(pg, "c-page-post-%s" % tag)
        guard("팀·게시판 글 " + tag, team_board)

        # ── 4 데이터베이스 보드 ────────────────────────────────
        def board():
            go(pg, "/community/p/" + P_DB)
            pg.click("button[data-db-type=board]")
            pg.wait_for_timeout(500)
            m = pg.evaluate("""() => { const b = document.getElementById('db-board'); const r = b.getBoundingClientRect();
                 const cols = [...b.querySelectorAll('.db-board__col')].map(c => { const x = c.getBoundingClientRect(); return {h: c.querySelector('.db-board__head').textContent.trim(), l: Math.round(x.left), r: Math.round(x.right)}; });
                 const hint = document.getElementById('db-board-more');
                 return {sw: b.scrollWidth, cw: b.clientWidth, left: Math.round(r.left), right: Math.round(r.right), cols: cols, more: b.getAttribute('data-more'),
                         hint: !!hint && !hint.hidden && getComputedStyle(hint).display !== 'none', mask: getComputedStyle(b).maskImage || getComputedStyle(b).webkitMaskImage}; }""")
            step("4 보드 열 여섯(없음 · 할 일 · 진행 · 검토 · 완료 · 보류) " + tag, len(m["cols"]) == 6, [c["h"] for c in m["cols"]])
            pg.evaluate("() => document.getElementById('db-board').scrollIntoView()")
            pg.wait_for_timeout(150)
            shot(pg, "c-db-board-%s" % tag)
            if w >= 1280:
                step("4 1280 — 열이 모두 보인다(보드 칸 안 · 가로 밀기 0) " + tag,
                     m["sw"] <= m["cw"] + 1 and all(c["r"] <= m["right"] + 1 for c in m["cols"]), m)
                step("4 1280 — 넘치지 않으니 가장자리 표시·안내 줄 없음 " + tag, not m["more"] and not m["hint"], m)
            else:
                over = m["sw"] > m["cw"] + 1
                step("4 %d — 넘치면 오른쪽 가장자리 표시(data-more=right · 흐림 mask) + 안내 줄 %s" % (w, tag),
                     over and m["more"] == "right" and m["hint"] and "gradient" in (m["mask"] or ""), m)
                pg.evaluate("() => { const b = document.getElementById('db-board'); b.scrollLeft = b.scrollWidth; }")
                pg.wait_for_timeout(250)
                m2 = pg.evaluate("() => { const b = document.getElementById('db-board'); const r = b.getBoundingClientRect(); const last = b.lastElementChild.getBoundingClientRect(); return {more: b.getAttribute('data-more'), lastIn: last.right <= r.right + 1}; }")
                step("4 끝까지 밀면 왼쪽 표시로 바뀌고 마지막 열(보류)이 칸 안 " + tag, m2["more"] == "left" and m2["lastIn"], m2)
                shot(pg, "c-db-board-end-%s" % tag)
            no_overflow(pg, "데이터베이스 보드 " + tag)
            if touchdev:
                touch(pg, "데이터베이스 보드 화면 " + tag)
        guard("데이터베이스 보드 " + tag, board)

        # ── 5 휴지통 자리 ──────────────────────────────────────
        def trash():
            # Q:F-12 C (DESIGN 2.19 ⑧ — 시안 v1 승인): 업무 앱의 「내 휴지통」은 접힌 「더보기」 묶음의 끝이다(자료실은 그대로 첫 묶음의 끝).
            # 「내 문서」 화면(/community/workspace — `/community/` 는 둘러보기·업무 홈)에서 이동 없이 창이 열린다(원래 어긋남: 전에는 `/community/` 를 첫 화면으로 봤다).
            go(pg, "/community/workspace")
            open_drawer(pg)
            pg.evaluate("() => { const d = document.getElementById('nav-community-more-box'); if (d) d.open = true; }")
            pos = pg.evaluate("""() => { const a = document.getElementById('nav-community-trash'); if (!a) return null; const nav = a.closest('nav');
                  const items = nav ? [...nav.querySelectorAll(':scope > .nav__item')] : []; return {text: a.textContent.trim(), nav: nav && nav.getAttribute('aria-label'),
                  last: items[items.length - 1] === a, hidden: a.hidden, href: a.getAttribute('href'), more: !!a.closest('details#nav-community-more-box')}; }""")
            step("5 업무 사이드바 「내 휴지통」 — 「더보기」 묶음의 끝(DESIGN 2.19 ⑧) " + tag, bool(pos) and pos["text"] == "내 휴지통" and pos["last"] and pos["more"] and not pos["hidden"], pos)
            sub = pg.evaluate("() => (document.getElementById('community-space-sub') || {}).textContent || ''").strip()
            step("13 직원 「내 공간」 설명은 그대로(공개 범위 문장) " + tag, "공개 범위" in sub, sub)
            if w == 1280 or phone:
                shot(pg, "c-sidebar-%s" % tag)
            if not BEFORE and pos:
                pg.click("#nav-community-trash")
                pg.wait_for_timeout(500)
                st = pg.evaluate("() => ({open: !!document.querySelector('dialog#trash-dialog[open]'), url: location.pathname + location.search, title: (document.getElementById('trash-title') || {}).textContent})")
                step("5 「내 문서」 화면에서 누르면 옮겨 가지 않고 휴지통 창 「내 휴지통」 " + tag, st["open"] and st["url"] == "/community/workspace" and (st["title"] or "").strip() == "내 휴지통", st)
                shot(pg, "c-trash-dialog-%s" % tag)
                pg.keyboard.press("Escape")
                pg.wait_for_timeout(200)
                if P_OWN and w in (412, 1280):
                    go(pg, "/community/p/" + P_OWN)
                    open_drawer(pg)
                    pg.evaluate("() => { const d = document.getElementById('nav-community-more-box'); if (d) d.open = true; }")
                    pg.click("#nav-community-trash")
                    pg.wait_for_load_state("networkidle")
                    pg.wait_for_timeout(600)
                    st = pg.evaluate("() => ({open: !!document.querySelector('dialog#trash-dialog[open]'), url: location.pathname + location.search})")
                    step("5 글 화면에서 누르면 「내 문서」 화면으로 가 휴지통 창 · 주소에서 꼬리표를 뗀다 " + tag, st["open"] and st["url"] == "/community/workspace", st)
                    pg.keyboard.press("Escape")
            go(pg, "/tbox/")
            open_drawer(pg)
            tpos = pg.evaluate("""() => { const a = document.getElementById('nav-my-trash'); if (!a) return null; const nav = a.closest('nav');
                  const items = nav ? [...nav.querySelectorAll(':scope > .nav__item')] : []; return {text: a.textContent.trim(), last: items[items.length - 1] === a, hidden: a.hidden}; }""")
            step("5 t-box 사이드바 「내 휴지통」도 첫 묶음의 끝(같은 이름·같은 자리) " + tag, bool(tpos) and tpos["text"] == "내 휴지통" and tpos["last"] and not tpos["hidden"], tpos)
        guard("휴지통 자리 " + tag, trash)

        # ── 9 820 터치 — t-box 파일 표 행 동작 ─────────────────────
        def tbox_files():
            go(pg, "/tbox/")
            no_overflow(pg, "t-box 파일 " + tag)
            # 표가 첫 화면 아래일 수 있다(폰) — 화면 밖의 것은 터치 측정이 세지 않으므로 표를 창 안으로
            pg.evaluate("() => { const b = document.getElementById('files-body'); if (b) b.scrollIntoView({block: 'start'}); }")
            pg.wait_for_timeout(200)
            rows = pg.evaluate("""() => [...document.querySelectorAll('#files-body td.actions')].slice(0, 6).map(td => {
                  const tops = new Set([...td.querySelectorAll('.btn')].map(b => Math.round(b.getBoundingClientRect().top))); return tops.size; })""")
            if w == 820:
                note("820 터치 — 행 동작 아이콘 줄 수 %s" % rows)
                touch(pg, "t-box 파일 표(행 동작 두 줄) " + tag, "#files-body")
                shot(pg, "t-files-%s" % tag)
            elif phone:
                touch(pg, "t-box 파일 " + tag, "#files-body")
        guard("t-box 파일 " + tag, tbox_files)
        c.close()

        # ── 2 남의 공간(직원 둘째 계정) ──────────────────────────
        c2 = ctx(br, S_E2, w, theme)
        pg2 = page_of(c2)

        def other_space():
            go(pg2, "/community/p/" + P_OWN)
            cr = crumb_root(pg2)
            step("2 남의 개인 공간 페이지 — 첫 칸 = 「%s 의 공간」(링크 아님) %s" % (E1_NAME, tag),
                 bool(cr) and cr["text"] == E1_NAME + " 의 공간" and cr["tag"] == "SPAN" and not cr["href"], cr)
            no_overflow(pg2, "남의 글 화면 " + tag)
            shot(pg2, "c-page-other-%s" % tag)
            if phone:
                fl = pg2.evaluate(FIRST_LINE)
                step("1 남의 글도 본문 첫 줄이 첫 화면 위쪽 절반 안 %s" % tag, bool(fl) and fl["bottom"] <= 0.5 * fl["h"], fl)
        guard("남의 글 화면 " + tag, other_space)
        c2.close()

        # ── 5 손님 — 사이드바 「내 휴지통」 숨김 ────────────────────
        if w in (412, 1280):
            c3 = ctx(br, S_G1, w, theme)
            pg3 = page_of(c3)

            def guest():
                go(pg3, "/community/")
                h = pg3.evaluate("() => { const a = document.getElementById('nav-community-trash'); return a ? a.hidden : 'none'; }")
                step("5 손님 — 「내 휴지통」 숨김(지울 수 없어 늘 비어 있음) " + tag, h is True, h)
                sub = pg3.evaluate("() => (document.getElementById('community-space-sub') || {}).textContent || ''").strip()
                step("13 손님 「내 공간」 설명 — 할 수 없는 「공개 범위」 문장 0 · 읽기 안내 " + tag, "공개 범위" not in sub and "읽기만" in sub, sub)
                if w == 1280:
                    shot(pg3, "c-home-guest-%s" % tag)
                # 12 막힌(403) 페이지 — 머리에 「사용 방법」 밖의 단추·메뉴가 보이지 않는다(이력 · 더보기 · 편집) · 속성·요약·댓글 없음
                before_err = len(errors)
                go(pg3, "/community/p/" + P_OWN)
                pg3.wait_for_timeout(300)
                # 창구가 403 으로 막는 것이 이 경우의 뜻이다 — Chromium 이 남기는 「Failed to load resource … 403」 줄만 받아들인다
                new = errors[before_err:]
                extra = [e for e in new if "status of 403" not in e]
                step("12 손님 막힌 페이지 콘솔 — 창구 403 줄 밖 0 " + tag, not extra, new)
                del errors[before_err:]
                vis = pg3.evaluate("""() => { const shown = e => !!e && e.getClientRects().length > 0 && getComputedStyle(e).visibility !== 'hidden';
                      const head = [...document.querySelectorAll('.page-head__actions > *')].filter(shown).map(e => e.matches('[data-help-open]') ? 'help' : (e.id || e.tagName));
                      return {head: head, props: shown(document.getElementById('page-props')), summary: shown(document.getElementById('page-props-summary')),
                              comments: shown(document.getElementById('page-comments-wrap')), crumb: shown(document.getElementById('page-crumb')),
                              msg: (document.getElementById('msg') || {}).textContent || ''}; }""")
                step("12 손님이 막힌 페이지 — 머리엔 「사용 방법」 하나(이력·더보기·편집 0) · 속성·요약·댓글·경로 0 · 거부 문장 " + tag,
                     vis["head"] == ["help"] and not vis["props"] and not vis["summary"] and not vis["comments"] and not vis["crumb"] and vis["msg"].strip() != "", vis)
                if w == 412:
                    shot(pg3, "c-page-guest-403-%s" % tag)
            guard("손님 " + tag, guest)
            c3.close()

        # ── 6 없는 주소 ───────────────────────────────────────
        def not_found():
            texts = []
            for who, sess in (("로그인", S_E1), ("로그인 전", None)):
                cx = ctx(br, sess, w, theme)
                px = page_of(cx)
                before_err = len(errors)
                r = px.goto(SITE + "/tbox/my-trash", wait_until="networkidle", timeout=30000)
                px.wait_for_timeout(200)
                info = px.evaluate("() => ({h1: (document.querySelector('h1') || {}).textContent || '', a: [...document.querySelectorAll('a')].map(a => [a.textContent.trim(), a.getAttribute('href')]), body: document.body.innerText, pre: !!document.querySelector('pre') || /^\\s*\\{\"error\"/.test(document.body.innerText)})")
                step("6 없는 주소(%s) — 상태 404 · 「찾을 수 없는 페이지」 · 첫 화면 링크 · JSON 글자 0 %s" % (who, tag),
                     r is not None and r.status == 404 and info["h1"].strip() == "찾을 수 없는 페이지" and ["첫 화면으로", "/"] in info["a"] and not info["pre"], (r.status if r else None, info))
                new = errors[before_err:]
                # 문서 자체가 404 라 Chromium 이 「Failed to load resource … 404」 한 줄을 남긴다 — 그 한 줄만 받아들인다
                extra = [e for e in new if "status of 404" not in e]
                step("6 없는 주소(%s) 콘솔 — 문서 404 한 줄 밖 0 %s" % (who, tag), len(extra) == 0 and len(new) <= 1, new)
                del errors[before_err:]
                no_overflow(px, "없는 주소(%s) %s" % (who, tag))
                shot(px, "t-404-%s-%s" % ("in" if sess else "out", tag))
                texts.append(info["body"])
                cx.close()
            step("6 로그인 전·후 없는 주소 화면 글자가 같다(세션·계정 정보 0) " + tag, len(texts) == 2 and texts[0] == texts[1] and E1_NAME not in texts[0], texts)
        guard("없는 주소 " + tag, not_found)

        # ── 7 감사 로그 · 8 게시 거부 문장 (관리자) ───────────────────
        c4 = ctx(br, S_A1, w, theme)
        pg4 = page_of(c4)

        def audit():
            go(pg4, "/tbox/audit")
            pg4.wait_for_selector("#audit-body tr", timeout=10000)
            m = pg4.evaluate("""() => { const w = document.querySelector('.table-wrap:has(#audit-body)') || document.querySelector('.table-wrap'); const t = w.querySelector('table');
                  const ths = [...t.querySelectorAll('thead th')]; const sum = ths.find(th => th.textContent.trim() === '요약'); const wr = w.getBoundingClientRect();
                  const rows = [...document.querySelectorAll('#audit-body tr')].slice(0, 20).map(tr => [...tr.children].map(td => [td.textContent.trim(), td.getAttribute('title')]));
                  const opts = [...document.querySelectorAll('#f-result option')].map(o => o.value);
                  return {sw: w.scrollWidth, cw: w.clientWidth, sumRight: sum ? Math.round(sum.getBoundingClientRect().right) : null, wrapRight: Math.round(wr.right), rows: rows, opts: opts}; }""")
            if w >= 1280:
                step("7 1280 — 감사 표가 칸 안(가로 밀기 0 · 「요약」 칸 잘림 0) " + tag, m["sw"] <= m["cw"] + 1 and m["sumRight"] is not None and m["sumRight"] <= m["wrapRight"] + 1,
                     {k: m[k] for k in ("sw", "cw", "sumRight", "wrapRight")})
            roles = {r[2][0] for r in m["rows"] if len(r) > 6}
            results = {r[6][0] for r in m["rows"] if len(r) > 6}
            levels = {r[5][0] for r in m["rows"] if len(r) > 6}
            rtitles = {r[2][1] for r in m["rows"] if len(r) > 6 and r[2][0] != "—"}
            step("7 역할 칸 글자 한글(관리자·팀장·직원·손님) · 원래 값은 title " + tag,
                 bool(roles) and roles <= {"관리자", "팀장", "직원", "손님", "—"} and rtitles <= {"Admin", "Manager", "Employee", "Guest"}, (roles, rtitles))
            step("7 결과 칸 글자 한글(허용·거부·오류) " + tag, bool(results) and results <= {"허용", "거부", "오류", "—"}, results)
            step("7 등급 칸 글자 한글(공개·사내·팀·관리자) " + tag, levels <= {"공개", "사내", "팀", "관리자", "—"}, levels)
            step("7 결과 거르기 값은 그대로(allow · deny · error) " + tag, {"allow", "deny", "error"} <= set(m["opts"]), m["opts"])
            no_overflow(pg4, "감사 로그 " + tag)
            shot(pg4, "t-audit-%s" % tag)
        guard("감사 로그 " + tag, audit)

        if P_IMG and BOARD_PUB and not publish_done and (w == 1280 or len(COMBOS) == 1):
            def publish():
                global publish_done
                publish_done = True
                go(pg4, "/community/p/" + P_IMG)
                pg4.click("#page-more > summary")
                pg4.click("#page-publish")
                pg4.wait_for_selector("input[name=publish-board][value='%s']" % BOARD_PUB["id"], timeout=8000)
                pg4.check("input[name=publish-board][value='%s']" % BOARD_PUB["id"])
                before_err = len(errors)
                pg4.click("#publish-save")
                pg4.wait_for_function("() => (document.getElementById('publish-msg') || {}).textContent", timeout=8000)
                msg = pg4.evaluate("() => document.getElementById('publish-msg').textContent.trim()")
                # 창구가 400 으로 거부하는 것이 이 경우의 뜻이다 — Chromium 의 「Failed to load resource … 400」 한 줄만 받아들인다
                new = errors[before_err:]
                step("8 게시 거부 콘솔 — 창구 400 줄 밖 0 " + tag, not [e for e in new if "status of 400" not in e], new)
                del errors[before_err:]
                step("8 사내 그림 → 「공개」 게시판 — 창에 쉬운 문장(게시판 등급 · 할 일) " + tag,
                     "「공개」" in msg and "그림이나 파일" in msg and "등급" in msg and "file_narrower" not in msg and "다시 게시" in msg, msg)
                shot(pg4, "c-publish-refused-%s" % tag)
                pg4.keyboard.press("Escape")
            guard("게시 거부 " + tag, publish)
        c4.close()

    br.close()

print("\n── 공통")
step("브라우저 기본 창(alert·prompt·confirm) 0", not dialogs, dialogs[:5])
step("콘솔 오류·pageerror 0", not errors, errors[:8])
print("\n통과 %d · 실패 %d" % (passes, fails))
print("SMOKE_FAIL=%d" % fails)
sys.exit(0 if BEFORE or fails == 0 else 1)
