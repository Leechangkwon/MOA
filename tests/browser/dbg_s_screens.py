#!/usr/bin/env python3
# tests/browser/dbg_s_screens.py — 디버깅 묶음 S 화면 스모크(내 활동 거부 행 가림 · 도움말 · 내 계정 비밀번호 칸)
#
# 부르는 쪽: tests/dbg_s_security.sh (DBGS_BROWSER=1 — 임시 인스턴스 · 임시 DB · 포트 8211~8219). 운영 8082 에는 요청하지 않는다.
# 쓰는 법:   py_secret S_E1 -- python3 dbg_s_screens.py <SITE> <UA> <SHOTS>
#   세션은 **표준입력** 첫 줄로만 받는다(명령 인자·환경 변수에 싣지 않는다 — R-62).
#
# 보는 것
#   ⓐ /tbox/activity — 거부 행이 「(볼 수 없는 대상)」으로 보인다 · 거부 행 글자에 admin-only 0 · 도움말에 가림 안내 한 줄
#   ⓑ /tbox/settings — 비밀번호 변경 칸의 안내에 「개인 프로그램 토큰도 모두 취소됩니다」 · 도움말에 같은 뜻 한 줄
#   ⓒ 360·1280 × 라이트·다크 스크린샷(activity · settings · 도움말 창) · 360 에서 문서 가로 넘침 0
# 공통: pageerror 0 · console.error 0 · HTTP ≥ 400 0 · 브라우저 기본 대화상자 0
# 나가는 값: 0 통과 · 1 실패 (마지막 줄 `SMOKE_FAIL=<개수>`)
import os
import re
import sys
from urllib.parse import urlsplit

from playwright.sync_api import sync_playwright

SITE = sys.argv[1].rstrip("/")
UA = sys.argv[2]
SHOTS = sys.argv[3]
E1 = sys.stdin.readline().rstrip("\n")
HOST = re.match(r"https?://([^/:]+)", SITE).group(1)
TBOX = SITE + "/tbox"
os.makedirs(SHOTS, exist_ok=True)

fails = 0


def step(name, ok, detail=""):
    global fails
    if not ok:
        fails += 1
    print("  %s %s%s" % ("✔" if ok else "✖", name, ("  — " + str(detail)[:300]) if detail else ""))


def new_context(browser, theme, width):
    ctx = browser.new_context(user_agent=UA, viewport={"width": width, "height": 900})
    ctx.add_cookies([
        {"name": "__Host-tbox_session", "value": E1, "domain": HOST, "path": "/", "httpOnly": True, "secure": True, "sameSite": "Lax"},
        {"name": "tbox_theme", "value": theme, "domain": HOST, "path": "/", "secure": False, "sameSite": "Lax"},
    ])
    return ctx


def watch(page, seen):
    page.on("pageerror", lambda e: seen["pageerrors"].append(str(e)))
    page.on("console", lambda m: seen["console"].append(m.text) if m.type == "error" else None)
    page.on("response", lambda r: seen["http_bad"].append("%d %s" % (r.status, urlsplit(r.url).path))
            if r.status >= 400 and HOST in r.url else None)

    def on_dialog(d):
        seen["native_dialogs"].append(d.type + ":" + d.message[:80])
        d.dismiss()
    page.on("dialog", on_dialog)


def no_overflow(page):
    return page.evaluate("() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1")


with sync_playwright() as pw:
    browser = pw.chromium.launch()
    for theme in ("light", "dark"):
        for width in (1280, 360):
            label = "%s·%d" % (theme, width)
            seen = {"pageerrors": [], "console": [], "http_bad": [], "native_dialogs": []}
            ctx = new_context(browser, theme, width)
            page = ctx.new_page()
            watch(page, seen)

            # ⓐ 내 활동
            page.goto(TBOX + "/activity", wait_until="networkidle")
            page.wait_for_function("() => document.querySelectorAll('#activity-body tr').length > 0", timeout=10000)
            rows = page.eval_on_selector_all("#activity-body tr", "els => els.map(e => Array.from(e.children).map(c => c.textContent))")
            deny = [r for r in rows if len(r) >= 3 and r[2].strip() == "deny"]
            step("%s 내 활동 — 거부 행이 있다(몸통이 만든 남의 파일 찔러 보기)" % label, len(deny) >= 1, len(deny))
            step("%s   거부 행 요약에 「(볼 수 없는 대상)」" % label, any("(볼 수 없는 대상)" in r[1] for r in deny), [r[1] for r in deny][:2])
            step("%s   내가 올리다 막힌 행은 이름 그대로(가림 없음)" % label,
                 any("올리려 해서 거부" in r[1] and "(볼 수 없는 대상)" not in r[1] for r in deny), [r[1] for r in deny][:3])
            step("%s   거부 행 글자에 admin-only 0" % label, all("admin-only" not in r[1] for r in deny), [r[1] for r in deny][:2])
            theme_attr = page.evaluate("() => document.documentElement.getAttribute('data-theme') || ''")
            step("%s   테마 적용(data-theme=%s)" % (label, theme), theme_attr == theme, theme_attr)
            if width == 360:
                step("%s   가로 넘침 0" % label, no_overflow(page))
            page.screenshot(path=os.path.join(SHOTS, "activity-%s-%d.png" % (theme, width)), full_page=True)
            page.click("[data-help-open]")
            page.wait_for_selector("#help-dialog[open]", timeout=5000)
            htext = page.text_content("#help-dialog") or ""
            step("%s   도움말에 가림 안내 한 줄" % label, "(볼 수 없는 대상)" in htext and "가려" in htext, htext[-200:])
            page.screenshot(path=os.path.join(SHOTS, "activity-help-%s-%d.png" % (theme, width)))
            page.keyboard.press("Escape")

            # ⓑ 내 계정
            page.goto(TBOX + "/settings", wait_until="networkidle")
            sub = page.text_content("#password-form >> xpath=ancestor::section[1]") or ""
            step("%s 내 계정 — 비밀번호 칸 안내에 「개인 프로그램 토큰도 모두 취소됩니다」" % label, "개인 프로그램 토큰도 모두 취소됩니다" in sub, sub[:160])
            if width == 360:
                step("%s   가로 넘침 0" % label, no_overflow(page))
            page.screenshot(path=os.path.join(SHOTS, "settings-%s-%d.png" % (theme, width)), full_page=True)
            page.click("[data-help-open]")
            page.wait_for_selector("#help-dialog[open]", timeout=5000)
            htext = page.text_content("#help-dialog") or ""
            step("%s   도움말에 「비밀번호를 바꾸면 개인 프로그램 토큰도 모두 취소됩니다」" % label, "비밀번호를 바꾸면 개인 프로그램 토큰도 모두 취소됩니다" in htext, htext[:200])
            page.screenshot(path=os.path.join(SHOTS, "settings-help-%s-%d.png" % (theme, width)))
            page.keyboard.press("Escape")

            step("%s — pageerror·console.error·HTTP≥400·기본 대화상자 0" % label,
                 not seen["pageerrors"] and not seen["console"] and not seen["http_bad"] and not seen["native_dialogs"],
                 "%s %s %s %s" % (seen["pageerrors"][:2], seen["console"][:2], seen["http_bad"][:2], seen["native_dialogs"][:2]))
            ctx.close()
    browser.close()

print("SMOKE_FAIL=%d" % fails)
sys.exit(1 if fails else 0)
