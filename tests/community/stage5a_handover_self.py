#!/usr/bin/env python3
# tests/community/stage5a_handover_self.py — Manager 는 팀원 페이지를 **자기 자신에게** 넘길 수 없다 (SECURITY C:R-05 ②)
#
# 사용: py_secret S_E1 S_E2 S_M -- python3 tests/community/stage5a_handover_self.py <SITE> <UA>
#   세션 셋(직원1 · 직원2 · 같은 팀 Manager — claude_employee01·claude_employee02·claude_manager01)은 **표준입력에서 한 줄씩**
#   차례로 읽는다 — 명령 인자에 싣지 않는다(R-62 · 봇 규칙 R15). 셸에서는 tests/lib/secrets.sh 의 py_secret 로 준다.
#   Manager 는 팀원 페이지를 내용을 보지 않고 넘길 뿐이다(PRD 11-1 ⑦). 자기에게 넘기면 소유자가 되어 「비열람」이
#   풀리므로 400 이어야 한다. 다른 사람에게는 넘겨진다. 만든 페이지는 새 주인이 창구로 지운다. 마지막 줄 `FAIL=<n>`.
import json, sys, urllib.request, urllib.error
B = sys.argv[1].rstrip('/'); UA = sys.argv[2]
S = {w: sys.stdin.readline().rstrip("\n") for w in ("e1", "e2", "m")}; CS = {}
if not all(S.values()):
    print("세션 셋(직원1·직원2·Manager)을 표준입력으로 한 줄씩 주어야 한다 — 시작하지 않는다"); sys.exit(2)
def req(w, m, p, b=None):
    h = {"User-Agent": UA, "Cookie": "tbox_session=" + S[w]}
    if m != "GET":
        h["X-CSRF-Token"] = CS[w]; h["Content-Type"] = "application/json"
    r = urllib.request.Request(B + p, data=None if b is None else json.dumps(b).encode(), headers=h, method=m)
    try:
        with urllib.request.urlopen(r) as x: return x.status, json.loads(x.read() or b"{}")
    except urllib.error.HTTPError as e:
        try: return e.code, json.loads(e.read() or b"{}")
        except Exception: return e.code, {}
for w in S: CS[w] = req(w, "GET", "/tbox/api/me")[1].get("csrf_token", "")
C = "/community/api/pages"; f = 0
def ok(n, c, d=""):
    global f; print(("  ✔ " if c else "  ✖ ") + n + ("" if c else "  — " + str(d)[:200])); f += 0 if c else 1
c, d = req("e1", "POST", C, {"title": "매니저인계시험"}); P = d.get("page", {}).get("id", ""); ok("직원1 비공개 페이지 201", c == 201, (c, d))
ok("Manager 는 그 페이지를 못 본다(403)", req("m", "GET", f"{C}/{P}")[0] == 403)
c, d = req("m", "POST", f"{C}/{P}/handover", {"to": "claude_manager01", "keep": "view"}); ok("Manager 가 자기에게 넘기기 → 400", c == 400, (c, d))
ok("여전히 Manager 는 못 본다", req("m", "GET", f"{C}/{P}")[0] == 403)
ok("여전히 직원1이 주인(GET 200)", req("e1", "GET", f"{C}/{P}")[0] == 200)
c, d = req("m", "POST", f"{C}/{P}/handover", {"to": "claude_employee02", "keep": "none"}); ok("Manager 가 다른 사람에게 넘기기 → 200", c == 200, (c, d))
ok("새 주인 직원2 GET 200", req("e2", "GET", f"{C}/{P}")[0] == 200)
ok("Manager 는 넘긴 뒤에도 못 본다", req("m", "GET", f"{C}/{P}")[0] == 403)
ok("정리 — 직원2 삭제", req("e2", "DELETE", f"{C}/{P}", {})[0] == 200)
print("FAIL=%d" % f); sys.exit(1 if f else 0)
