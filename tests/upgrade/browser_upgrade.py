#!/usr/bin/env python3
# tests/upgrade/browser_upgrade.py — 업그레이드 스모크의 브라우저 몫 (docs/checkbot/upgrade-smoke-design.md · A 방식)
#
# 부르는 쪽: tests/upgrade_smoke.sh (임시 인스턴스 · 임시 DB). 단독 실행 불가.
# 쓰는 법:   printf '%s\n' <세션 쿠키> | python3 browser_upgrade.py <SITE> <UA> <단계> <프로필 폴더> <flows.json> <자산 기록>
#   단계  fill    — 프로필(디스크 캐시가 남는 브라우저)로 N-1 화면을 돌며 캐시를 채운다. 단추는 누르지 않는다
#         upgrade — 같은 프로필로(= N-1 을 캐시한 채) N 화면을 열고 단추를 누른다
#         fresh   — 캐시 없는 새 문맥으로 같은 동선(대조 — 캐시 탓인지 코드 탓인지 가른다)
#   쿠키는 표준입력 첫 줄로만 받는다(명령 인자·환경 변수 0 — tests/lib/secrets.sh 규칙).
#
# 캐시는 흉내 내지 않는다(route 0). 임시 서버가 운영 nginx 와 같은 캐시 머리(자산 7일)를 내므로
# 브라우저가 진짜 디스크 캐시로 판단한다. 어느 부품이 캐시로 답했는지는 「브라우저가 받은 자산 주소」에서
# 「이번 단계에 서버가 실제로 받은 자산 주소(자산 기록)」를 빼서 찾는다.
#
# 판정(설계 7장 — 사고와 같은 모양의 오류만 센다): 흐름마다
#   steps[] 의 expect 가 없는 click 은 준비 동작(좁은 화면에서 메뉴 열기 등)이다.
#   fail = 모듈 연결 오류(pageerror 가 export·import·모듈 불러오기 실패) 또는 기대한 요청 0
#   그 밖의 pageerror·콘솔 오류는 참고(warn)로 찍는다.
# 나가는 줄: FLOW <id> <pass|fail> <이유>   ·   CACHED <id> <자산 주소>(upgrade 에서 끊긴 흐름만)   ·   WARN <id> <내용>   ·   CREATED=<id>
#            CACHEHITS <id> <n>(upgrade — 캐시가 답한 자산 수, 양성 대조) · FILL <id> … · FILLFAIL <id> <이유>(fill — 캐시를 못 채운 흐름)
# 로그인 화면으로 가면(세션 무효) 그 흐름은 fail·FILLFAIL 이다 — 헛통과·헛채우기를 막는다(독립 검토 M1).
# 나가는 값: 0 (판정은 부르는 셸이 FLOW 줄로 한다) · 2 준비 실패
import json
import os
import re
import sys
from urllib.parse import urlsplit

from playwright.sync_api import sync_playwright

if len(sys.argv) != 7:
    print("쓰는 법: browser_upgrade.py <SITE> <UA> <fill|upgrade|fresh> <프로필> <flows.json> <자산 기록>", file=sys.stderr)
    sys.exit(2)
SITE, UA, PHASE, PROFILE, FLOWS, ASSET_LOG = sys.argv[1].rstrip("/"), sys.argv[2], sys.argv[3], sys.argv[4], sys.argv[5], sys.argv[6]
if PHASE not in ("fill", "upgrade", "fresh"):
    print("단계는 fill·upgrade·fresh 중 하나", file=sys.stderr)
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


def run_flow(ctx, flow):
    seen = {"pageerrors": [], "console": [], "assets": [], "reqs": []}
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
    missing = []
    created = ""
    status = resp.status if resp else 0
    if "/login" in urlsplit(page.url).path:
        missing.append("로그인 화면으로 감(세션 무효)")
    elif status >= 400:
        missing.append("화면 %d" % status)
    seen["status"] = status
    if PHASE != "fill" and not missing:
        if flow.get("on_load") and not any(matches(r, flow["on_load"]) for r in seen["reqs"]):
            missing.append("열자마자 %s %s 0건" % (flow["on_load"]["method"], flow["on_load"]["path"]))
        for st in flow.get("steps", []):
            loc = page.locator(st["click"])
            if loc.count() == 0:
                missing.append("누를 것 없음 %s" % st["click"])
                continue
            want = st.get("expect")
            try:
                if want:
                    # 고정 대기 대신 기대 요청을 기다린다(검토 L4) — 5초 안에 없으면 0건
                    with page.expect_request(lambda r, w=want: matches(r, w), timeout=5000):
                        loc.first.click(timeout=3000)
                else:
                    loc.first.click(timeout=3000)
                    page.wait_for_timeout(400)
            except Exception as e:
                msg = str(e).splitlines()[0]
                if want and "Locator.click" not in msg:   # 누르기는 됐고 기대 요청을 기다리다 끝났다
                    missing.append("%s → %s %s 0건" % (st["click"], want["method"], want["path"]))
                else:  # 가려짐·안 보임 — 사고 모양은 아니지만 동선이 끊겼다
                    missing.append("누르지 못함 %s (%s)" % (st["click"], msg[:80]))
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
    return seen, missing, created


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
    for flow in FLOW_LIST:
        seen, missing, created = run_flow(ctx, flow)
        if created:
            print("CREATED=%s" % created)
        if PHASE == "fill":
            print("FILL %s 자산 %d건 · 화면 %d" % (flow["id"], len(seen["assets"]), seen.get("status", 0)))
            if missing or not seen["assets"]:
                print("FILLFAIL %s %s" % (flow["id"], " | ".join(missing) or "자산 0건"))
            continue
        link = [e for e in seen["pageerrors"] if LINK_RE.search(e)]
        other = [e for e in seen["pageerrors"] if not LINK_RE.search(e)] + seen["console"]
        reasons = ["모듈 연결 오류: " + link[0][:160]] if link else []
        reasons += missing
        print("FLOW %s %s %s" % (flow["id"], "fail" if reasons else "pass", " | ".join(reasons) if reasons else "-"))
        for w in other[:3]:
            print("WARN %s %s" % (flow["id"], w[:160]))
        if PHASE == "upgrade":
            srv = server_seen()
            cached = sorted(a for a in set(seen["assets"]) if a not in srv)
            print("CACHEHITS %s %d" % (flow["id"], len(cached)))
            if reasons:   # 원인 부품은 끊긴 흐름에만 붙인다
                for a in cached:
                    print("CACHED %s %s" % (flow["id"], a))
    ctx.close()
    if browser:
        browser.close()
sys.exit(0)
