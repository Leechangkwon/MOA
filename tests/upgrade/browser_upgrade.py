#!/usr/bin/env python3
# tests/upgrade/browser_upgrade.py — 업그레이드 스모크의 브라우저 몫 (docs/checkbot/upgrade-smoke-design.md · A 방식)
#
# 부르는 쪽: tests/upgrade_smoke.sh (임시 인스턴스 · 임시 DB). 단독 실행 불가.
# 쓰는 법:   printf '%s\n' <세션 쿠키> | python3 browser_upgrade.py <SITE> <UA> <단계> <프로필 폴더> <flows.json> <자산 기록> <표식>
#   단계  fill    — 프로필(디스크 캐시가 남는 브라우저)로 N-1 화면을 돌며 캐시를 채운다. 단추는 누르지 않는다
#         upgrade — 같은 프로필로(= N-1 을 캐시한 채) N 화면을 열고 단추를 누른다
#         fresh   — 캐시 없는 새 문맥으로 같은 동선(대조 — 캐시 탓인지 코드 탓인지 가른다)
#   표식  — 부르는 셸이 이번 실행에 만든 난수(비밀 아님). 임시 서버 라우터가 모든 응답에 X-Upgrade-Run 으로 낸다.
#           흐름마다 처음 여는 화면 응답(page.goto)에 이 값이 없으면 남의 서버일 수 있다 → 그 흐름은 OTHER 로 고정(모듈 연결
#           오류가 겹쳐도 LINK 로 세지 않는다)하고, **그 단계의 남은 흐름은 열지 않는다**(쿠키를 더 보내지 않는다 — 독립 검토 낮음 3·참고 3)
#   쿠키는 표준입력 첫 줄로만 받는다(명령 인자·환경 변수 0 — tests/lib/secrets.sh 규칙).
#
# 캐시는 흉내 내지 않는다(route 0). 임시 서버가 운영 nginx 와 같은 캐시 머리를 내므로
# 브라우저가 진짜 디스크 캐시로 판단한다. 어느 부품이 캐시로 답했는지는 「브라우저가 받은 자산 주소」에서
# 「이번 단계에 서버가 실제로 받은 자산 주소(자산 기록)」를 빼서 찾는다.
#
# 판정(설계 7장 — 사고와 같은 모양의 오류만 호환성 실패로 센다 · HO-HF0-006 교훈): 흐름마다 사유코드 하나
#   LINK  모듈 연결 오류(pageerror 가 export·import·모듈 불러오기 실패)
#   REQ0  누른 뒤 5초 안에 기대한 요청 0건(기다리다 시간이 다 된 것만) · on_load 는 화면을 연 뒤(networkidle + 600ms) 기대한 요청 0건
#   OTHER 그 밖의 끊김 — 로그인 화면(세션 무효) · 화면 ≥400 · 표식 머리 없음 · 누를 것 없음 · 누르지 못함 · 기대 요청의 응답 ≥400 ·
#         기다리는 중의 다른 예외(창 닫힘 등)
#   여러 이유가 겹치면 LINK > REQ0 > OTHER 의 앞선 것. 이유 글은 모두 붙인다.
#   steps[] 의 expect 가 없는 click 은 준비 동작(좁은 화면에서 메뉴 열기 등)이다.
#   그 밖의 pageerror·콘솔 오류는 참고(WARN)로만 찍는다.
# 나가는 줄: FLOW <id> <pass|fail> <LINK|REQ0|OTHER|-> <이유>   ·   CACHED <id> <자산 주소>(upgrade 에서 끊긴 흐름만)
#            WARN <id> <내용>   ·   CREATED=<id>   ·   SW <id> <n>(서비스 워커 등록 수 · 못 읽으면 -1 — 부르는 셸이 0 을 기대)
#            CACHEHITS <id> <n>(upgrade — 캐시가 답한 자산 수, 양성 대조) · FILL <id> … · FILLFAIL <id> <이유>(fill — 캐시를 못 채운 흐름)
# 로그인 화면으로 가면(세션 무효) 그 흐름은 fail(OTHER)·FILLFAIL 이다 — 헛통과·헛채우기를 막는다(독립 검토 M1).
# 나가는 값: 0 (판정은 부르는 셸이 FLOW 줄로 한다) · 2 준비 실패
import json
import re
import sys
from urllib.parse import urlsplit

from playwright.sync_api import TimeoutError as PWTimeout
from playwright.sync_api import sync_playwright

if len(sys.argv) != 8:
    print("쓰는 법: browser_upgrade.py <SITE> <UA> <fill|upgrade|fresh> <프로필> <flows.json> <자산 기록> <표식>", file=sys.stderr)
    sys.exit(2)
SITE, UA, PHASE, PROFILE, FLOWS, ASSET_LOG, MARK = (sys.argv[1].rstrip("/"), sys.argv[2], sys.argv[3], sys.argv[4],
                                                    sys.argv[5], sys.argv[6], sys.argv[7])
if PHASE not in ("fill", "upgrade", "fresh"):
    print("단계는 fill·upgrade·fresh 중 하나", file=sys.stderr)
    sys.exit(2)
if not re.fullmatch(r"[0-9a-f]{16,64}", MARK):
    print("표식 모양이 틀렸다(16진 16~64자)", file=sys.stderr)
    sys.exit(2)
COOKIE = sys.stdin.readline().strip()
if not COOKIE:
    print("표준입력에 세션 쿠키가 없다", file=sys.stderr)
    sys.exit(2)
with open(FLOWS, encoding="utf-8") as fh:
    FLOW_LIST = json.load(fh)["flows"]

HOST = urlsplit(SITE).hostname
ASSET_PREFIX = "/tbox/assets/"
PAGE_RE = re.compile(r"/community/p/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})")
# 사고(UR-0001)와 같은 부류 — 모듈 그래프가 이어지지 않아 스크립트가 통째로 안 도는 오류
LINK_RE = re.compile(r"does not provide an export|Failed to fetch dynamically imported module|"
                     r"Importing a module script failed|Cannot use import statement|is not defined in module|"
                     r"error loading dynamically imported module", re.I)
CODE_ORDER = ("LINK", "REQ0", "OTHER")
SW_JS = "() => navigator.serviceWorker ? navigator.serviceWorker.getRegistrations().then(r => r.length) : 0"


def server_seen():
    """자산 기록에서 이번 단계에 서버가 받은 자산 주소(경로?쿼리) 집합"""
    try:
        with open(ASSET_LOG, encoding="utf-8") as fh:
            return {line.strip() for line in fh if line.strip()}
    except FileNotFoundError:
        return set()


def matches(req, want):
    if not want:
        return False
    p = urlsplit(req.url)
    return req.method == want["method"] and p.hostname == HOST and p.path.startswith(want["path"])


def resp_status(req):
    """기대 요청의 응답 코드 — 응답 없이 끊겼으면 0"""
    try:
        r = req.response()
        return r.status if r else 0
    except Exception:
        return 0


def run_flow(ctx, flow):
    seen = {"pageerrors": [], "console": [], "assets": [], "reqs": [], "sw": 0}
    page = ctx.new_page()
    page.on("pageerror", lambda e: seen["pageerrors"].append(str(e)))
    page.on("console", lambda m: seen["console"].append(m.text)
            if m.type == "error" and "Failed to load resource" not in m.text else None)
    page.on("request", lambda r: seen["reqs"].append(r))

    def on_done(r):
        p = urlsplit(r.url)
        if p.hostname == HOST and p.path.startswith(ASSET_PREFIX):
            seen["assets"].append(p.path + ("?" + p.query if p.query else ""))
    page.on("requestfinished", on_done)

    resp = page.goto(SITE + flow["url"], wait_until="networkidle")
    page.wait_for_timeout(600)
    problems = []          # (사유코드, 글)
    created = ""
    status = resp.status if resp else 0
    seen["status"] = status
    seen["foreign"] = not resp or resp.headers.get("x-upgrade-run") != MARK
    if seen["foreign"]:
        problems.append(("OTHER", "화면 응답에 이번 실행의 표식 머리가 없다(남의 서버?)"))
    elif "/login" in urlsplit(page.url).path:
        problems.append(("OTHER", "로그인 화면으로 감(세션 무효)"))
    elif status >= 400:
        problems.append(("OTHER", "화면 %d" % status))
    try:
        seen["sw"] = int(page.evaluate(SW_JS) or 0)
    except Exception:
        seen["sw"] = -1         # 못 읽음 — 0 으로 삼키지 않는다(부르는 셸이 실패로 센다 · 독립 검토 낮음 4)
    if PHASE != "fill" and not problems:
        if flow.get("on_load"):
            hit = [r for r in seen["reqs"] if matches(r, flow["on_load"])]
            code = resp_status(hit[0]) if hit else None
            if not hit:
                problems.append(("REQ0", "열자마자 %s %s 0건" % (flow["on_load"]["method"], flow["on_load"]["path"])))
            elif code >= 400 or code == 0:
                problems.append(("OTHER", "열자마자 %s %s 응답 %d" % (flow["on_load"]["method"], flow["on_load"]["path"], code)))
        for st in flow.get("steps", []):
            loc = page.locator(st["click"])
            if loc.count() == 0:
                problems.append(("OTHER", "누를 것 없음 %s" % st["click"]))
                continue
            want = st.get("expect")
            try:
                if want:
                    # 고정 대기 대신 기대 요청을 기다린다(검토 L4) — 5초 안에 없으면 0건
                    with page.expect_request(lambda r, w=want: matches(r, w), timeout=5000) as got:
                        loc.first.click(timeout=3000)
                    code = resp_status(got.value)
                    if code >= 400 or code == 0:   # 요청은 나갔다 — 사고 모양(0건)은 아니지만 동선이 끊겼다
                        problems.append(("OTHER", "%s → %s %s 응답 %d" % (st["click"], want["method"], want["path"], code)))
                else:
                    loc.first.click(timeout=3000)
                    page.wait_for_timeout(400)
            except Exception as e:
                msg = str(e).splitlines()[0]
                if want and isinstance(e, PWTimeout) and "Locator.click" not in msg:   # 누르기는 됐고 기대 요청을 기다리다 시간이 다 됐다
                    problems.append(("REQ0", "%s → %s %s 0건" % (st["click"], want["method"], want["path"])))
                else:  # 가려짐·안 보임·그 밖의 예외 — 사고 모양은 아니지만 동선이 끊겼다
                    problems.append(("OTHER", "누르지 못함 %s (%s)" % (st["click"], msg[:80])))
                continue
        if flow.get("creates") == "community_page":
            try:
                page.wait_for_url(PAGE_RE, timeout=4000)
            except Exception:
                pass
            m = PAGE_RE.search(page.url)
            created = m.group(1) if m else ""
            if created:
                page.wait_for_load_state("networkidle")
    page.close()
    return seen, problems, created


with sync_playwright() as p:
    if PHASE == "fresh":
        browser = p.chromium.launch()
        ctx = browser.new_context(user_agent=UA, viewport={"width": 390, "height": 844})
    else:
        browser = None
        ctx = p.chromium.launch_persistent_context(PROFILE, headless=True, user_agent=UA,
                                                   viewport={"width": 390, "height": 844})
    ctx.add_cookies([{"name": "tbox_session", "value": COOKIE, "domain": HOST, "path": "/",
                      "httpOnly": True, "secure": False, "sameSite": "Lax"}])
    stopped = ""
    for flow in FLOW_LIST:
        if stopped:             # 앞 흐름에서 표식이 틀렸다 — 이 단계의 남은 흐름은 열지 않는다(쿠키를 더 보내지 않는다)
            print("SW %s 0" % flow["id"])
            if PHASE == "fill":
                print("FILLFAIL %s %s" % (flow["id"], stopped))
            else:
                print("FLOW %s fail OTHER %s" % (flow["id"], stopped))
            continue
        seen, problems, created = run_flow(ctx, flow)
        if seen.get("foreign"):
            stopped = "앞 흐름(%s)에서 표식 머리가 틀려 이 단계를 멈췄다" % flow["id"]
        if created:
            print("CREATED=%s" % created)
        print("SW %s %d" % (flow["id"], seen["sw"]))
        if PHASE == "fill":
            print("FILL %s 자산 %d건 · 화면 %d" % (flow["id"], len(seen["assets"]), seen.get("status", 0)))
            if problems or not seen["assets"]:
                print("FILLFAIL %s %s" % (flow["id"], " | ".join(t for _, t in problems) or "자산 0건"))
            continue
        link = [e for e in seen["pageerrors"] if LINK_RE.search(e)]
        other = [e for e in seen["pageerrors"] if not LINK_RE.search(e)] + seen["console"]
        if not seen.get("foreign"):   # 남의 서버일 수 있는 화면의 모듈 오류는 호환성 판정에 쓰지 않는다
            problems = ([("LINK", "모듈 연결 오류: " + link[0][:160])] if link else []) + problems
        code = min((c for c, _ in problems), key=CODE_ORDER.index) if problems else "-"
        print("FLOW %s %s %s %s" % (flow["id"], "fail" if problems else "pass", code,
                                    " | ".join(t for _, t in problems) if problems else "-"))
        for w in other[:3]:
            print("WARN %s %s" % (flow["id"], w[:160]))
        if PHASE == "upgrade":
            srv = server_seen()
            cached = sorted(a for a in set(seen["assets"]) if a not in srv)
            print("CACHEHITS %s %d" % (flow["id"], len(cached)))
            if problems:   # 원인 부품은 끊긴 흐름에만 붙인다
                for a in cached:
                    print("CACHED %s %s" % (flow["id"], a))
    ctx.close()
    if browser:
        browser.close()
sys.exit(0)
