#!/usr/bin/env python3
# tests/browser/qf3a_bulk.py — 개발 대기열 6 · Q:F-3a 라운드 B(일괄 작업) 화면을 진짜 브라우저로 눌러 본다
#                              (지시서 QF3A-T9 의 라운드 B 몫 + QF3A-T6 · T7 의 화면 몫 · 결정 M-4 · M-6 · M-7)
#
# 부르는 쪽: tests/community/qf3b.sh (임시 인스턴스 · 임시 DB — 운영 8082 에는 요청하지 않는다).
# 쓰는 법: printf '%s\n' "$직원1" "$직원2" "$관리자1_옛" "$관리자1_새" "$관리자1_새2" "$직원1_둘째" "$직원2_둘째" | python3 qf3a_bulk.py <SITE> <UA> <SHOTS> <IDS_JSON> <임시 DB>
#   세션 일곱은 **표준입력 한 줄씩**(R-62). claude_manager01 은 세 번 로그인한 세션(옛 → 새 → 새2 — 만든 순서) — 끊김 시험을 두 앱에서
#   한 번씩 한다(① 커뮤니티: 옛 세션을 만료시키고 새 세션으로 잇는다 ② t-box: 새 세션을 만료시키고 새2 로 잇는다).
#   직원 둘의 둘째 세션 — 도는 중에 쿠키를 그 세션으로 바꿔 「다른 탭에서 다시 로그인함」을 만든다(⑱ · ⑳).
#   IDS_JSON = qf3b.sh 가 만든 {e1_files, e2_files, m1_files, limit_folder, dest_folder, tag}.
#   임시 DB 는 세션 만료 흉내(last_seen_at 을 과거로 — **그 계정의 가장 새 세션 k 개 밖**만)와 감사·상태 확인에만 쓴다(sudo psql).
# 보는 것
#   t-box   ① 고르기 — 줄 체크 · Shift+클릭 범위 · 머리 체크(이 쪽 전부) · Space · Esc 로 풀기 · 폴더를 바꾸면 풀림(M-4) · 막대 한 줄 ·
#             막대 아이콘에 올려도 칸이 안 밀림(DESIGN 2.8) ② 섞인 선택 휴지통(T6) — 확인 창 → 진행 표 3 완료 · 2 거부(한 건 삭제와
#             같은 문장) · 감사 5행 → 10초 「되돌리기」(M-7) ③ 폴더로 옮기기 ④ 등급·팀 바꾸기 ⑤ 즐겨찾기 넣기 · 즐겨찾기 보기에서 빼기
#             ⑥ 100개 한도(쪽을 넘겨 가며) ⑦ 도중 세션 만료(T7) → 멈춤·남은 줄 유지·「로그인 화면 열기」 → 새 세션 「이어서 하기」 ·
#             다른 계정으로 로그인했으면 잇지 않음 ⑧ 빈 목록 안내 글 안쪽 여백 12px(라운드 A 디자인 지적)
#   커뮤니티 ⑨ 「선택하기」 모드 · Space · Esc 로 끝남 · 찾기 시작하면 끝남 ⑩ 부모+자식+깊이 초과 옮기기(T6 — 「부모와 함께」 · 그 항목만 거부)
#             ⑪ 지우기 → 「되돌리기」 ⑫ 즐겨찾기 → 사이드바 ⑬ 휴지통 창 여러 줄 되살리기 ⑭ 사이드바 트리 줄 ☆(올리면 보임 · 누르면 넣기·빼기 ·
#             초점이면 보임) ⑮ 100개 한도 ⑯ 도중 세션 만료 → 이어서 하기
#   두 앱   ⑱ ⑳ 도는 중 세션 바뀜(같은 계정 · 다른 탭 로그인) → 첫 403 에서 `/api/me` 로 보고 **거부로 세지 않고 멈춤** → 이어서 하기 →
#             셸 토큰도 새 값(재인증 창 · 로그아웃 — 로그아웃이 실제로 됨) · 재인증 멈춤 뒤 다른 계정이면 「이어서 하기」가 보내기 전에 막음(요청 0)
#             ㉑ 커뮤니티도 작업을 시작하면 고른 것이 풀리고, 도는 동안 새로 고른 것은 끝난 뒤에도 남음(내 공간 · 휴지통 창) ·
#             두 앱 체크 달린 열 머리의 화면 읽기 이름 = 「이름」·「제목」 글자만
#   화면    두 앱 × 360 · 412(터치) · 820 · 1280 × 라이트 · 다크 — 가로 넘침 0 · 막대 한 줄 · 스크린샷(SHOTS — 사람이 직접 연다)
#   전 과정 pageerror · console.error · 요청 실패 0 · HTTP≥400 은 **그 단계에서 일부러 낸 것**(남의 파일 403 · 만료 401 · 깊이 400)만
# 나가는 값: 마지막 줄 `SMOKE_FAIL=<n>` · 종료 코드 0/1
import json
import os
import re
import subprocess
import sys
import time
import uuid

from playwright.sync_api import sync_playwright

SITE = sys.argv[1].rstrip("/")
UA = sys.argv[2]
SHOTS = sys.argv[3]
IDS = json.load(open(sys.argv[4], encoding="utf-8"))
DB = sys.argv[5]
E1 = sys.stdin.readline().rstrip("\n")
E2 = sys.stdin.readline().rstrip("\n")
M1_OLD = sys.stdin.readline().rstrip("\n")
M1_NEW = sys.stdin.readline().rstrip("\n")
M1_NEW2 = sys.stdin.readline().rstrip("\n")
E1B = sys.stdin.readline().rstrip("\n")   # claude_employee01 두 번째 세션(다른 탭에서 다시 로그인한 것과 같다)
E2B = sys.stdin.readline().rstrip("\n")   # claude_employee02 두 번째 세션
if not all((E1, E2, M1_OLD, M1_NEW, M1_NEW2, E1B, E2B)):
    print("세션 일곱을 표준입력으로 한 줄씩 주어야 한다 — 시작하지 않는다")
    sys.exit(2)
TBOX = SITE + "/tbox"
COMMUNITY = SITE + "/community"
fails = 0
passes = 0
pageerrors, console_errors, http_bad, req_failed = [], [], [], []
expected_bad = []     # (상태, 주소 조각) — 그 단계가 일부러 낸 4xx
TAG = uuid.uuid4().hex[:6]


def step(name, ok, detail=""):
    global fails, passes
    if ok:
        passes += 1
    else:
        fails += 1
    print("  %s %s%s" % ("✔" if ok else "✖", name, ("  — " + str(detail)[:260]) if (detail != "" and not ok) else ""))


def sql(q):
    r = subprocess.run(["sudo", "-n", "-u", "postgres", "psql", "-X", "-d", DB, "-t", "-A", "-c", q],
                       capture_output=True, text=True, cwd="/")
    return r.stdout.strip()


def expire_manager_except_newest(keep):
    """claude_manager01 의 세션 중 **가장 새 keep 개 밖**을 무활동 만료로(임시 DB). 세션 값은 인자에 싣지 않는다 — 계정 이름과 만든 순서만."""
    sql("UPDATE sessions SET last_seen_at = now() - interval '3 hours' "
        "WHERE user_id = (SELECT id FROM users WHERE username = 'claude_manager01') "
        "AND id NOT IN (SELECT id FROM sessions WHERE user_id = (SELECT id FROM users WHERE username = 'claude_manager01') "
        "ORDER BY created_at DESC LIMIT %d)" % keep)


def host():
    return re.match(r"https?://([^/:]+)", SITE).group(1)


def set_session(ctx, session):
    ctx.add_cookies([{"name": "tbox_session", "value": session, "domain": host(), "path": "/",
                      "httpOnly": True, "secure": False, "sameSite": "Lax"}])


def context(browser, session, scheme="light", width=1280, height=900, touch=False):
    ctx = browser.new_context(user_agent=UA, viewport={"width": width, "height": height}, color_scheme=scheme,
                              has_touch=touch, is_mobile=touch)
    set_session(ctx, session)
    page = ctx.new_page()
    page.on("pageerror", lambda e: pageerrors.append(str(e)))
    page.on("console", lambda m: console_errors.append(m.text)
            if m.type == "error" and "Failed to load resource" not in m.text else None)
    page.on("requestfailed", lambda r: req_failed.append(r.url))
    page.on("response", lambda r: http_bad.append((r.status, r.url))
            if r.status >= 400 and host() in r.url else None)
    return ctx, page


def wait_until(fn, timeout=10.0):
    end = time.time() + timeout
    while time.time() < end:
        try:
            if fn():
                return True
        except Exception:
            pass
        time.sleep(0.1)
    return False


def no_overflow(page):
    return page.evaluate("() => document.documentElement.scrollWidth - window.innerWidth") <= 0


def one_line(page, sel):
    """막대의 보이는 자식이 모두 한 줄(위치 차 4px 안)인가"""
    tops = page.evaluate("(s) => [...document.querySelectorAll(s + ' > *')].filter(e => e.offsetParent !== null)"
                         ".map(e => { const r = e.getBoundingClientRect(); return Math.round(r.top + r.height / 2); })", sel)
    return len(tops) > 0 and max(tops) - min(tops) <= 4, tops


def rows_of(page, tbody):
    return page.evaluate("(s) => [...document.querySelectorAll(s + ' tr')].map(tr => [...tr.children].map(td => td.textContent.trim()))", tbody)


def csrf(page):
    return (page.request.get(TBOX + "/api/me").json() or {}).get("csrf_token", "")


def fcheck(page, fid):
    return page.locator("input[data-bulk-file='%s']" % fid)


def shot(page, name, full=False):
    page.evaluate("() => window.scrollTo(0, 0)")
    page.screenshot(path=os.path.join(SHOTS, name), full_page=full)


def wait_tbox_rows(page):
    page.wait_for_function("() => document.querySelectorAll('#files-body tr').length > 0", timeout=15000)


def wait_summary(page, prefix, title_has=""):
    return wait_until(lambda: (page.locator("#%s-bulk-summary" % prefix).text_content() or "").find("완료") >= 0
                      and (page.locator("#%s-bulk-title" % prefix).text_content() or "").find(title_has) >= 0, 25)


E1F = IDS["e1_files"]
E2F = IDS["e2_files"]
M1F = IDS["m1_files"]

with sync_playwright() as p:
    browser = p.chromium.launch()

    # ═══ t-box ═══════════════════════════════════════════════════
    print("── 1. t-box 고르기 — 줄 체크 · Shift 범위 · 머리 체크 · Space · Esc · 폴더 바꾸면 풀림")
    ctx, page = context(browser, E1)
    page.goto(TBOX + "/", wait_until="networkidle")
    wait_tbox_rows(page)
    step("1 머리 체크(이 쪽 모두 고르기)와 줄 체크(data-bulk)가 있다",
         page.locator("#files-check-all").count() == 1 and fcheck(page, E1F[0]).count() == 1)
    step("1 처음에는 막대가 숨어 있고 검색 줄이 보인다", page.locator("#files-bulk-bar").is_hidden() and page.locator("#search-form").is_visible())
    fcheck(page, E1F[5]).click()
    step("1 줄 체크 → 막대 「1개 선택됨」 · 검색 줄 자리에 막대", wait_until(lambda: page.locator("#files-bulk-bar").is_visible())
         and page.locator("#files-bulk-count").text_content() == "1개 선택됨" and page.locator("#search-form").is_hidden())
    fcheck(page, E1F[2]).click(modifiers=["Shift"])
    picked = [x for x in E1F[2:6] if fcheck(page, x).is_checked()]
    step("1 Shift+클릭 → 그 사이 넷(5·4·3·2) 모두 고름", len(picked) == 4 and page.locator("#files-bulk-count").text_content() == "4개 선택됨",
         page.locator("#files-bulk-count").text_content())
    step("1 머리 체크는 일부만 고른 상태(indeterminate)", page.evaluate("() => document.getElementById('files-check-all').indeterminate"))
    ok_line, tops = one_line(page, "#files-bulk-bar")
    step("1 막대가 한 줄(개수 + 아이콘 넷 + ✕)", ok_line, tops)
    shot(page, "tbox-select-light-1280.png")
    dims = "() => [document.documentElement.scrollHeight, document.documentElement.scrollWidth]"
    d0 = page.evaluate(dims)
    page.locator("#files-bulk-clear").hover()
    page.wait_for_timeout(250)
    d1 = page.evaluate(dims)
    step("1 막대 끝 아이콘(✕)에 올려도 문서 크기 그대로(DESIGN 2.8 ★ 툴팁)", d0 == d1, (d0, d1))
    page.mouse.move(5, 5)
    page.locator("#files-check-all").click()
    n_rows = page.locator("#files-body tr").count()
    step("1 머리 체크 → 이 쪽 줄 전부(%d)" % n_rows, page.locator("#files-bulk-count").text_content() == "%d개 선택됨" % n_rows,
         page.locator("#files-bulk-count").text_content())
    page.locator("#files-check-all").click()
    step("1 머리 체크 다시 → 이 쪽 전부 풀림 · 막대 숨음", wait_until(lambda: page.locator("#files-bulk-bar").is_hidden()))
    fcheck(page, E1F[0]).focus()
    page.keyboard.press("Space")
    step("1 줄 체크에 초점을 두고 Space → 고름", wait_until(lambda: fcheck(page, E1F[0]).is_checked())
         and page.locator("#files-bulk-count").text_content() == "1개 선택됨")
    page.keyboard.press("Tab")
    step("1 Tab → 다음 칸(☆)으로 초점이 간다", page.evaluate("() => document.activeElement && document.activeElement.hasAttribute('data-fav-file')"))
    page.keyboard.press("Escape")
    step("1 Esc → 고른 것이 풀리고 막대가 숨는다", wait_until(lambda: page.locator("#files-bulk-bar").is_hidden()) and not fcheck(page, E1F[0]).is_checked())
    fcheck(page, E1F[0]).click()
    page.locator("#folder-list .foldertree__item", has_text="qf3b-받는폴더-" + IDS["tag"]).first.click()
    step("1 폴더를 바꾸면 고른 것이 풀린다(M-4)", wait_until(lambda: page.locator("#files-bulk-bar").is_hidden()))
    page.locator("#folder-crumbs .crumbs__item").first.click()
    wait_tbox_rows(page)
    fcheck(page, E1F[0]).click()
    page.locator("#files-view-recent").click()
    step("1 보기를 바꾸면 고른 것이 풀린다(M-4)", wait_until(lambda: page.locator("#files-bulk-bar").is_hidden()))
    page.locator("#files-view-files").click()
    wait_tbox_rows(page)

    print("── 2. 섞인 선택 휴지통(QF3A-T6) — 내 파일 3 + 남의 파일 2 · 확인 창 · 진행 표 · 감사 5행 · 되돌리기(M-7)")
    single = page.request.fetch(TBOX + "/api/files/" + E2F[1], method="DELETE", headers={"X-CSRF-Token": csrf(page)})
    single_msg = (single.json() or {}).get("error", "")
    expected_bad.append((403, "/api/files/" + E2F[0]))
    expected_bad.append((403, "/api/files/" + E2F[1]))
    step("2 기준 — 남의 파일 한 건 삭제 403 · 문장", single.status == 403 and single_msg != "", (single.status, single_msg))
    a0 = sql("SELECT coalesce(max(id), 0) FROM audit_logs")
    sel5 = E1F[0:3] + E2F[0:2]
    for x in sel5:
        fcheck(page, x).click()
    step("2 다섯 고름", page.locator("#files-bulk-count").text_content() == "5개 선택됨", page.locator("#files-bulk-count").text_content())
    page.locator("#files-bulk-trash").click()
    step("2 확인 창(U-2a 와 같은 창) — 제목 「파일 5개를 휴지통으로 옮길까요?」 · 처음 초점 「닫기」",
         wait_until(lambda: page.locator("#confirm-dialog[open]").count() == 1)
         and page.locator("#confirm-dialog-title").text_content() == "파일 5개를 휴지통으로 옮길까요?"
         and page.evaluate("() => document.activeElement && document.activeElement.textContent === '닫기'"))
    shot(page, "tbox-trash-confirm-light-1280.png")
    me_calls = {"n": 0}
    page.on("request", lambda r: me_calls.__setitem__("n", me_calls["n"] + 1) if r.url.split("?")[0].endswith("/tbox/api/me") else None)
    page.locator("#confirm-dialog .btn--danger").click()
    step("2 진행 표 — 끝남", wait_summary(page, "files", "휴지통"))
    step("2 첫 403 에서만 세션 확인(`/api/me` 1번 — 둘째 403 은 묻지 않고 거부로 셈)", me_calls["n"] == 1, me_calls["n"])
    rows = rows_of(page, "#files-bulk-body")
    step("2 줄 = 내 셋 「휴지통으로 옮김」 · 남의 둘 = 한 건 삭제와 같은 문장",
         [r[1] for r in rows] == ["휴지통으로 옮김"] * 3 + [single_msg] * 2, rows)
    summ = page.locator("#files-bulk-summary").text_content()
    step("2 끝 줄 「3개 완료 · 2개 못 함」 + 10초 되돌리기", summ.startswith("3개 완료 · 2개 못 함") and "10초" in summ, summ)
    ids_sql = ",".join(sql("SELECT id FROM files WHERE public_id = '%s'" % x) for x in sel5)
    aud = sql("SELECT string_agg(result, ',' ORDER BY id) FROM audit_logs WHERE id > %s AND action = 'file.delete' AND actor_name = 'claude_employee01' AND target_id IN (%s)" % (a0, ids_sql))
    step("2 감사 file.delete 5행(allow 3 · deny 2 — 파일마다 한 줄)", aud == "allow,allow,allow,deny,deny", aud)
    step("2 「되돌리기」 단추가 보인다", page.locator("#files-bulk-undo").is_visible())
    shot(page, "tbox-trash-done-light-1280.png", full=True)
    page.locator("#files-bulk-undo").click()
    step("2 되돌리기 — 셋 「되살림」", wait_summary(page, "files", "되돌리기") and [r[1] for r in rows_of(page, "#files-bulk-body")] == ["되살림"] * 3,
         rows_of(page, "#files-bulk-body"))
    step("2 셋 다 다시 살아 있다(DB)", sql("SELECT count(*) FROM files WHERE public_id IN (%s) AND deleted_at IS NULL" % ",".join("'%s'" % x for x in E1F[0:3])) == "3")
    page.locator("#files-bulk-close").click()
    step("2 「닫기」 → 진행 표 숨음", wait_until(lambda: page.locator("#files-bulk-panel").is_hidden()))

    print("── 3. 폴더로 옮기기 · 4. 등급 바꾸기 · 5. 즐겨찾기")
    wait_tbox_rows(page)
    fcheck(page, E1F[3]).click()
    fcheck(page, E1F[4]).click()
    page.locator("#files-bulk-move").click()
    step("3 이동 창 — 「고른 파일 2개」", wait_until(lambda: page.locator("#move-dialog[open]").count() == 1) and page.locator("#move-file").text_content() == "고른 파일 2개")
    page.locator("#move-list .foldertree__item", has_text="qf3b-받는폴더-" + IDS["tag"]).first.click()
    page.wait_for_timeout(500)
    page.locator("#move-here").click()
    step("3 진행 표 「2개 완료 · 0개 못 함」", wait_summary(page, "files", "옮기기") and page.locator("#files-bulk-summary").text_content().startswith("2개 완료 · 0개 못 함"),
         page.locator("#files-bulk-summary").text_content())
    step("3 두 파일이 받는 폴더에(DB)", sql("SELECT count(*) FROM files WHERE public_id IN ('%s','%s') AND folder_id = %s" % (E1F[3], E1F[4], IDS["dest_folder"])) == "2")
    wait_tbox_rows(page)
    fcheck(page, E1F[5]).click()
    fcheck(page, E2F[0]).click()
    expected_bad.append((403, "/api/files/" + E2F[0]))
    page.locator("#files-bulk-level").click()
    step("4 등급·팀 창 — 선택지(올리기 창과 같은 목록)", wait_until(lambda: page.locator("#bulk-level-dialog[open]").count() == 1)
         and page.locator("#bulk-level-select option[value='public']").count() == 1)
    page.select_option("#bulk-level-select", "public")
    step("4 공개를 고르면 팀 칸은 숨는다", page.locator("#bulk-team-field").is_hidden())
    # 좁히는 쪽(사내 → 팀)만 직원에게 열려 있다(넓히기는 Admin 만 — PRD 5.3). 팀 칸의 기본값은 내 팀(올리기 창과 같다)
    page.select_option("#bulk-level-select", "team")
    step("4 팀을 고르면 팀 칸이 보이고 기본값은 내 팀", page.locator("#bulk-team-field").is_visible()
         and page.eval_on_selector("#bulk-team-select", "s => s.value") == sql("SELECT team_id FROM users WHERE username = 'claude_employee01'"))
    shot(page, "tbox-level-dialog-light-1280.png")
    page.locator("#bulk-level-submit").click()
    step("4 진행 표 — 내 파일 「바꿈」 · 남의 파일 거부 문장", wait_summary(page, "files", "등급")
         and [r[1] for r in rows_of(page, "#files-bulk-body")][0] == "바꿈" and rows_of(page, "#files-bulk-body")[1][1] not in ("바꿈", "대기", ""),
         rows_of(page, "#files-bulk-body"))
    step("4 DB — 내 파일만 팀으로", sql("SELECT access_level FROM files WHERE public_id = '%s'" % E1F[5]) == "team"
         and sql("SELECT access_level FROM files WHERE public_id = '%s'" % E2F[0]) == "internal")
    wait_tbox_rows(page)
    for x in (E1F[0], E1F[1]):
        fcheck(page, x).click()
    page.locator("#files-bulk-fav").click()
    step("5 즐겨찾기에 넣기 — 「2개 완료」", wait_summary(page, "files", "즐겨찾기") and page.locator("#files-bulk-summary").text_content().startswith("2개 완료"))
    step("5 두 줄의 ☆ 가 찬 별(aria-pressed)", wait_until(lambda: all(page.locator("button[data-fav-file='%s']" % x).get_attribute("aria-pressed") == "true" for x in (E1F[0], E1F[1]))))
    page.locator("#files-view-favorites").click()
    page.wait_for_function("() => (document.getElementById('page-info').textContent || '').indexOf('즐겨찾기 ') === 0")
    fcheck(page, E1F[0]).click()
    step("5 즐겨찾기 보기에서는 막대의 별 단추가 「즐겨찾기에서 빼기」", page.locator("#files-bulk-fav").get_attribute("aria-label") == "즐겨찾기에서 빼기")
    page.locator("#files-bulk-fav").click()
    step("5 빼기 → 그 줄이 즐겨찾기 목록에서 사라진다", wait_summary(page, "files", "빼기") and wait_until(lambda: page.locator("button[data-fav-file='%s']" % E1F[0]).count() == 0))
    page.locator("#files-bulk-close").click()
    page.locator("#files-view-files").click()
    wait_tbox_rows(page)

    print("── 6. 100개 한도 — 쪽을 넘겨 가며(M-4)")
    page.locator("#folder-list .foldertree__item", has_text="qf3b-한도폴더-" + IDS["tag"]).first.click()
    page.wait_for_function("() => (document.getElementById('page-info').textContent || '').indexOf('전체 105건') >= 0", timeout=15000)
    for i in range(6):
        page.locator("#files-check-all").click()
        if i < 5:
            before = page.locator("#files-body tr").first.text_content()
            page.locator("#page-next").click()
            wait_until(lambda: page.locator("#files-body tr").first.text_content() != before)
    step("6 여섯 쪽 머리 체크 → 100개에서 멈춤", page.locator("#files-bulk-count").text_content() == "100개 선택됨", page.locator("#files-bulk-count").text_content())
    step("6 막대 아래 한 줄 「한 번에 100개까지…」", page.locator("#files-bulk-note").is_visible() and "100개까지" in (page.locator("#files-bulk-note").text_content() or ""))
    page.locator("#page-prev").click()
    page.wait_for_timeout(600)
    step("6 쪽을 넘겨도 고른 것이 남아 있다(앞 쪽 줄이 체크됨)", page.evaluate("() => [...document.querySelectorAll('#files-body input[data-bulk-file]')].every(c => c.checked)"))
    shot(page, "tbox-limit-light-1280.png")
    page.keyboard.press("Escape")
    step("6 Esc → 100개 모두 풀림", wait_until(lambda: page.locator("#files-bulk-bar").is_hidden()))
    print("── 8. 빈 목록 안내 글 안쪽 여백(라운드 A 디자인 지적)")
    step("8 #files-empty 안쪽 여백 12px(표 머리·바닥 글자와 같은 줄)", page.evaluate("() => getComputedStyle(document.getElementById('files-empty')).paddingLeft") == "12px",
         page.evaluate("() => getComputedStyle(document.getElementById('files-empty')).paddingLeft"))
    ctx.close()

    print("── 7-b. 다른 계정으로 로그인했으면 잇지 않는다(가짜 401 로 멈춘 뒤 다른 계정 세션)")
    ctx, page = context(browser, M1_NEW2)
    page.goto(TBOX + "/", wait_until="networkidle")
    wait_tbox_rows(page)
    sent = {"n": 0}
    def fake401(route):
        sent["n"] += 1
        if sent["n"] == 1:
            route.fulfill(status=401, body='{"error":"로그인이 필요합니다"}', headers={"Content-Type": "application/json"})
        else:
            route.continue_()
    page.route("**/tbox/api/files/*/favorite", fake401)
    fcheck(page, E1F[0]).click()
    fcheck(page, E1F[1]).click()
    expected_bad.append((401, "/favorite"))
    page.locator("#files-bulk-fav").click()
    step("7b 멈춤 표시", wait_until(lambda: page.locator("#files-bulk-stop").is_visible()))
    set_session(ctx, E1)
    page.locator("#files-bulk-resume").click()
    step("7b 다른 계정 — 「이어서 할 수 없습니다」 · 「이어서 하기」 숨음",
         wait_until(lambda: "다른 계정" in (page.locator("#files-bulk-stop-text").text_content() or "")) and page.locator("#files-bulk-resume").is_hidden())
    page.wait_for_timeout(500)
    step("7b 그 뒤 요청 0(남의 계정으로 보내지 않았다)", sent["n"] == 1, sent["n"])
    ctx.close()

    # ═══ 커뮤니티 ════════════════════════════════════════════════
    print("── 9. 커뮤니티 「선택하기」 모드 — Space · Esc · 찾기 시작하면 끝남")
    ctx, page = context(browser, E2)
    page.goto(COMMUNITY + "/", wait_until="networkidle")
    tok = csrf(page)
    H = {"X-CSRF-Token": tok, "Content-Type": "application/json"}

    def mk(title, parent=None):
        body = {"title": title}
        if parent:
            body["parent_id"] = parent
        r = page.request.post(COMMUNITY + "/api/pages", data=json.dumps(body), headers=H)
        return (r.json().get("page") or {}).get("id", "")
    chain, parent = [], None
    for i in range(6):
        parent = mk("B깊이%d %s" % (i + 1, TAG), parent)
        chain.append(parent)
    A = mk("B부모 %s" % TAG)
    A1 = mk("B자식 %s" % TAG, A)
    X0 = mk("B깊은 %s" % TAG)
    X1 = mk("B깊은1 %s" % TAG, X0)
    X2 = mk("B깊은2 %s" % TAG, X1)
    B = mk("B잎 %s" % TAG)
    step("9 재료 페이지(깊이 6 사슬 · A+A1 · X+X1+X2 · B)", all(len(x) == 36 for x in chain + [A, A1, X0, X1, X2, B]))
    page.reload(wait_until="networkidle")
    page.wait_for_function("() => document.querySelectorAll('#community-tree .page-tree__row').length > 0", timeout=15000)
    step("9 처음에는 체크가 없다 · 막대 숨음", page.locator("#community-tree input[data-bulk-page]").count() == 0 and page.locator("#community-bulk-bar").is_hidden())
    page.locator("#community-select").click()
    step("9 「선택하기」 → 눌림(aria-pressed) · 글자 「선택 끝내기」 · 줄마다 체크 · 막대와 안내",
         page.get_attribute("#community-select", "aria-pressed") == "true" and page.locator("#community-select-label").text_content() == "선택 끝내기"
         and page.locator("#community-tree input[data-bulk-page]").count() > 5 and page.locator("#community-bulk-bar").is_visible()
         and page.locator("#community-bulk-note").is_visible())
    chk = lambda pid: page.locator("#community-tree input[data-bulk-page='%s']" % pid)
    open_before = page.evaluate("(id) => { const c = document.querySelector(\"#community-tree input[data-bulk-page='\" + id + \"']\"); const d = c.closest('details'); return d ? d.open : null; }", X0)
    chk(X0).focus()
    page.keyboard.press("Space")
    step("9 요약 줄(<summary>) 안 체크에 Space → 고름 · 접힘·펼침은 그대로",
         wait_until(lambda: chk(X0).is_checked()) and page.evaluate("(id) => { const c = document.querySelector(\"#community-tree input[data-bulk-page='\" + id + \"']\"); const d = c.closest('details'); return d ? d.open : null; }", X0) == open_before)
    chk(X0).click()
    step("9 클릭으로 풀기 · 접힘·펼침 그대로", not chk(X0).is_checked()
         and page.evaluate("(id) => { const c = document.querySelector(\"#community-tree input[data-bulk-page='\" + id + \"']\"); const d = c.closest('details'); return d ? d.open : null; }", X0) == open_before)
    page.keyboard.press("Escape")
    step("9 Esc → 모드 끝(체크 사라짐 · 막대 숨음 · 초점은 「선택하기」)", wait_until(lambda: page.locator("#community-bulk-bar").is_hidden())
         and page.locator("#community-tree input[data-bulk-page]").count() == 0
         and page.evaluate("() => document.activeElement && document.activeElement.id === 'community-select'"))
    page.locator("#community-select").click()
    page.fill("#community-search", "B잎")
    step("9 찾기를 시작하면 모드가 끝난다", wait_until(lambda: page.get_attribute("#community-select", "aria-pressed") == "false" and page.locator("#community-bulk-bar").is_hidden()))
    page.fill("#community-search", "")
    page.wait_for_function("() => !document.getElementById('community-tree').hidden", timeout=10000)

    print("── 10. 부모+자식 · 깊이 초과 옮기기(QF3A-T6 커뮤니티)")
    page.locator("#community-select").click()
    for x in (A, A1, X0, B):
        chk(x).click()
    step("10 넷 고름", page.locator("#community-bulk-count").text_content() == "4개 선택됨", page.locator("#community-bulk-count").text_content())
    ok_line, tops = one_line(page, "#community-bulk-bar")
    step("10 막대 한 줄", ok_line, tops)
    shot(page, "community-select-light-1280.png")
    page.locator("#community-bulk-move").click()
    step("10 옮기기 창 — 고른 페이지와 그 하위는 새 부모 후보에 없다", wait_until(lambda: page.locator("#bulk-move-dialog[open]").count() == 1)
         and page.locator("#bulk-move-tree .move-tree__pick", has_text="B부모 " + TAG).count() == 0
         and page.locator("#bulk-move-tree .move-tree__pick", has_text="B깊은1 " + TAG).count() == 0)
    page.locator("#bulk-move-tree .move-tree__pick", has_text="B깊이6 " + TAG).click()
    shot(page, "community-move-dialog-light-1280.png")
    single = page.request.fetch(COMMUNITY + "/api/pages/%s/move" % X0, method="PATCH", data=json.dumps({"parent_id": chain[-1]}), headers=H)
    depth_msg = (single.json() or {}).get("error", "")
    expected_bad.append((400, "/api/pages/%s/move" % X0))
    page.locator("#bulk-move-confirm").click()
    step("10 진행 표 끝남", wait_summary(page, "community", "옮기기"))
    rows = rows_of(page, "#community-bulk-body")
    step("10 A 「옮김」 · A1 「부모와 함께 옮겨짐」 · X 한 건 옮기기와 같은 깊이 문장 · B 「옮김」",
         [r[1] for r in rows] == ["옮김", "부모와 함께 옮겨짐", depth_msg, "옮김"] and depth_msg != "", (rows, depth_msg))
    step("10 끝 줄 「3개 완료 · 1개 못 함」", page.locator("#community-bulk-summary").text_content().startswith("3개 완료 · 1개 못 함"),
         page.locator("#community-bulk-summary").text_content())
    shot(page, "community-move-done-light-1280.png", full=True)

    print("── 11. 지우기 → 되돌리기(M-7) · 12. 즐겨찾기 · 13. 휴지통 여러 줄 되살리기")
    page.wait_for_function("(id) => !!document.querySelector(\"#community-tree input[data-bulk-page='\" + id + \"']\")", arg=A, timeout=10000)
    chk(A).click()
    chk(A1).click()
    page.locator("#community-bulk-trash").click()
    step("11 확인 창 — 「고른 페이지 2개와 그 하위…」", wait_until(lambda: page.locator("#bulk-delete-dialog[open]").count() == 1)
         and "2개" in (page.locator("#bulk-delete-lead").text_content() or ""))
    shot(page, "community-delete-confirm-light-1280.png")
    page.locator("#bulk-delete-confirm").click()
    step("11 A 「휴지통으로 옮김」 · A1 「부모와 함께 지워짐」", wait_summary(page, "community", "휴지통")
         and [r[1] for r in rows_of(page, "#community-bulk-body")] == ["휴지통으로 옮김", "부모와 함께 지워짐"], rows_of(page, "#community-bulk-body"))
    step("11 「되돌리기」 보임", page.locator("#community-bulk-undo").is_visible())
    page.locator("#community-bulk-undo").click()
    step("11 되돌리기 — A 「되살림」(하위는 함께 돌아온다)", wait_summary(page, "community", "되돌리기")
         and rows_of(page, "#community-bulk-body")[0][1].startswith("되살림"), rows_of(page, "#community-bulk-body"))
    step("11 A · A1 다시 살아 있다(DB)", sql("SELECT count(*) FROM community.pages WHERE public_id IN ('%s','%s') AND deleted_at IS NULL" % (A, A1)) == "2")
    page.wait_for_function("(id) => !!document.querySelector(\"#community-tree input[data-bulk-page='\" + id + \"']\")", arg=B, timeout=10000)
    chk(B).click()
    chk(X0).click()
    page.locator("#community-bulk-fav").click()
    step("12 즐겨찾기에 넣기 「2개 완료 · 0개 못 함」", wait_summary(page, "community", "즐겨찾기") and page.locator("#community-bulk-summary").text_content().startswith("2개 완료 · 0개 못 함"),
         page.locator("#community-bulk-summary").text_content())
    step("12 사이드바 「즐겨찾기」에 B · X", wait_until(lambda: page.locator("#nav-community-fav a[href$='/p/%s']" % B).count() == 1
                                               and page.locator("#nav-community-fav a[href$='/p/%s']" % X0).count() == 1))
    page.wait_for_function("(id) => !!document.querySelector(\"#community-tree input[data-bulk-page='\" + id + \"']\")", arg=B, timeout=10000)
    chk(B).click()
    chk(X0).click()
    page.locator("#community-bulk-trash").click()
    page.locator("#bulk-delete-confirm").click()
    step("13 B · X 휴지통으로(X 의 하위는 X 와 함께)", wait_summary(page, "community", "휴지통") and page.locator("#community-bulk-summary").text_content().startswith("2개 완료 · 0개 못 함"),
         page.locator("#community-bulk-summary").text_content())
    page.locator("#community-bulk-close").click()
    page.keyboard.press("Escape")
    page.locator("#community-more > summary").click()
    page.locator("#community-trash").click()
    tchk = lambda pid: page.locator("#trash-body input[data-bulk-trash='%s']" % pid)
    step("13 휴지통 창 — 줄 앞 체크 · 머리 체크", wait_until(lambda: tchk(B).count() == 1 and tchk(X0).count() == 1) and page.locator("#trash-check-all").count() == 1)
    tchk(B).click()
    tchk(X0).click()
    step("13 두 줄 고름 → 창 안 막대 「2개 선택됨」", page.locator("#trash-bulk-bar").is_visible() and page.locator("#trash-bulk-count").text_content() == "2개 선택됨")
    shot(page, "community-trash-select-light-1280.png")
    page.locator("#trash-bulk-restore").click()
    step("13 되살리기 「2개 완료 · 0개 못 함」", wait_until(lambda: (page.locator("#trash-bulk-summary").text_content() or "").startswith("2개 완료 · 0개 못 함"), 20),
         page.locator("#trash-bulk-summary").text_content())
    step("13 휴지통 목록에서 사라짐 · DB 살아 있음", wait_until(lambda: tchk(B).count() == 0 and tchk(X0).count() == 0)
         and sql("SELECT count(*) FROM community.pages WHERE public_id IN ('%s','%s') AND deleted_at IS NULL" % (B, X0)) == "2")
    shot(page, "community-trash-done-light-1280.png")
    page.locator("#trash-dialog [data-trash-close]").first.click()

    print("── 14. 사이드바 트리 줄 ☆ — 올리면 보임 · 누르면 넣기·빼기 · 초점이면 보임")
    page.goto(COMMUNITY + "/", wait_until="networkidle")
    side_row = page.locator("#nav-community-tree .nav-tree__row", has_text="B깊이1 " + TAG).first
    star = side_row.locator("[data-fav-toggle]")
    wait_until(lambda: star.count() == 1)
    vis = lambda: page.evaluate("(el) => getComputedStyle(el).visibility", star.element_handle())
    page.mouse.move(700, 700)
    step("14 안 넣은 줄의 ☆ 는 평소 숨음(자리는 잡음)", vis() == "hidden" and star.get_attribute("aria-pressed") == "false", vis())
    side_row.hover()
    step("14 줄에 올리면 ☆ 보임", wait_until(lambda: vis() == "visible"))
    shot(page, "community-sidebar-star-hover-light-1280.png")
    star.click()
    step("14 누르면 ★(aria-pressed) · 사이드바 「즐겨찾기」에 들어감", wait_until(lambda: star.get_attribute("aria-pressed") == "true"
         and page.locator("#nav-community-fav a[href$='/p/%s']" % chain[0]).count() == 1))
    page.mouse.move(700, 700)
    step("14 넣은 줄의 ★ 는 올리지 않아도 보임", wait_until(lambda: vis() == "visible"))
    fav_row = page.locator("#nav-community-fav li[data-fav-id='%s'] .nav-tree__row" % chain[0])
    fav_star = fav_row.locator("[data-fav-toggle]")
    fvis = lambda: page.evaluate("(el) => getComputedStyle(el).visibility", fav_star.element_handle())
    step("14 「즐겨찾기」 묶음 안의 ★ 는 평소 숨음(묶음 자체가 즐겨찾기 — 줄마다 별이 늘 보이지 않게)", fvis() == "hidden", fvis())
    fav_row.hover()
    step("14 그 줄에 올리면 ★ 보임", wait_until(lambda: fvis() == "visible"))
    fav_star.click()
    step("14 즐겨찾기 줄의 ★ 를 누르면 빠진다", wait_until(lambda: page.locator("#nav-community-fav a[href$='/p/%s']" % chain[0]).count() == 0))
    side_row.locator("a").focus()
    step("14 키보드 — 줄 링크에 초점이면 ☆ 보임(Tab 으로 닿는다)", wait_until(lambda: vis() == "visible"))
    page.keyboard.press("Tab")
    step("14 Tab → ☆ 에 초점 · 화면 읽기 이름 「…」 즐겨찾기에 넣기", page.evaluate("() => document.activeElement && document.activeElement.hasAttribute('data-fav-toggle')")
         and (page.evaluate("() => document.activeElement.getAttribute('aria-label')") or "").endswith("즐겨찾기에 넣기"))

    print("── 15. 100개 한도(커뮤니티)")
    lim_parent = mk("B한도묶음 %s" % TAG)
    kids = [mk("B한도 %s %03d" % (TAG, i), lim_parent) for i in range(101)]
    page.goto(COMMUNITY + "/", wait_until="networkidle")
    page.wait_for_function("() => document.querySelectorAll('#community-tree .page-tree__row').length > 100", timeout=20000)
    page.locator("#community-select").click()
    for k in kids:
        chk(k).click()
    step("15 101개를 눌러도 100개에서 멈춤 · 101번째 체크는 켜지지 않음", page.locator("#community-bulk-count").text_content() == "100개 선택됨" and not chk(kids[100]).is_checked(),
         page.locator("#community-bulk-count").text_content())
    step("15 안내 「한 번에 100개까지…」", "100개까지 고를 수 있습니다" in (page.locator("#community-bulk-note").text_content() or ""))
    page.keyboard.press("Escape")
    ctx.close()

    print("── 16. 커뮤니티 도중 세션 만료(QF3A-T7) — claude_manager01 옛 세션 → 새 세션")
    ctx, page = context(browser, M1_OLD)
    page.goto(COMMUNITY + "/", wait_until="networkidle")
    tokm = csrf(page)
    Hm = {"X-CSRF-Token": tokm, "Content-Type": "application/json"}
    mp = []
    for i in range(3):
        r = page.request.post(COMMUNITY + "/api/pages", data=json.dumps({"title": "B끊김 %s %d" % (TAG, i)}), headers=Hm)
        mp.append((r.json().get("page") or {}).get("id", ""))
    page.reload(wait_until="networkidle")
    page.wait_for_function("() => document.querySelectorAll('#community-tree .page-tree__row').length > 0", timeout=15000)
    page.locator("#community-select").click()
    for x in mp:
        chk(x).click()
    seen = {"n": 0}
    def expire_on_second(route):
        seen["n"] += 1
        if seen["n"] == 2:
            expire_manager_except_newest(2)   # 옛 세션만 만료(새 · 새2 는 산다)
        route.continue_()
    page.route("**/community/api/pages/*", lambda route: expire_on_second(route) if route.request.method == "DELETE" else route.continue_())
    expected_bad.append((401, "/community/api/"))
    expected_bad.append((401, "/tbox/api/me"))
    page.locator("#community-bulk-trash").click()
    page.locator("#bulk-delete-confirm").click()
    step("16 멈춤 — 「로그인이 끝나 멈췄습니다 — 남은 2개…」", wait_until(lambda: page.locator("#community-bulk-stop").is_visible(), 20)
         and "남은 2개" in (page.locator("#community-bulk-stop-text").text_content() or ""), page.locator("#community-bulk-stop-text").text_content())
    rows = rows_of(page, "#community-bulk-body")
    step("16 줄 = 「휴지통으로 옮김」 · 「멈춤 — 로그인이 필요합니다」 · 「대기」(남은 목록 유지)",
         [r[1] for r in rows] == ["휴지통으로 옮김", "멈춤 — 로그인이 필요합니다", "대기"], rows)
    step("16 「로그인 화면 열기」 — 새 탭(target=_blank · noopener) · 로그인 뒤 커뮤니티로",
         page.get_attribute("#community-bulk-login", "target") == "_blank" and "noopener" in (page.get_attribute("#community-bulk-login", "rel") or "")
         and (page.get_attribute("#community-bulk-login", "href") or "").endswith("/login?to=community"))
    step("16 둘째 페이지는 지워지지 않았다(DB)", sql("SELECT deleted_at IS NULL FROM community.pages WHERE public_id = '%s'" % mp[1]) == "t")
    shot(page, "community-expired-light-1280.png", full=True)
    page.locator("#community-bulk-resume").click()
    step("16 로그인 전 「이어서 하기」 → 「아직 로그인되지 않았습니다」(남은 줄 그대로)", wait_until(lambda: "아직 로그인되지" in (page.locator("#community-bulk-stop-text").text_content() or "")))
    set_session(ctx, M1_NEW)      # 새 탭에서 다시 로그인한 것과 같다(같은 쿠키 병)
    page.locator("#community-bulk-resume").click()
    step("16 새 세션으로 「이어서 하기」 → 남은 둘 「휴지통으로 옮김」 · 「3개 완료 · 0개 못 함」",
         wait_until(lambda: (page.locator("#community-bulk-summary").text_content() or "").startswith("3개 완료 · 0개 못 함"), 25)
         and [r[1] for r in rows_of(page, "#community-bulk-body")] == ["휴지통으로 옮김"] * 3, rows_of(page, "#community-bulk-body"))
    step("16 셋 다 휴지통에(DB)", sql("SELECT count(*) FROM community.pages WHERE public_id IN (%s) AND deleted_at IS NOT NULL" % ",".join("'%s'" % x for x in mp)) == "3")
    ctx.close()

    print("── 7. t-box 도중 세션 만료(QF3A-T7) — claude_manager01 새 세션 → 새2 세션")
    ctx, page = context(browser, M1_NEW)
    page.goto(TBOX + "/", wait_until="networkidle")
    wait_tbox_rows(page)
    for x in M1F:
        fcheck(page, x).click()
    seen = {"n": 0}
    def expire_on_second_t(route):
        if route.request.method == "DELETE":
            seen["n"] += 1
            if seen["n"] == 2:
                expire_manager_except_newest(1)   # 새 세션 만료(새2 만 산다)
        route.continue_()
    page.route("**/tbox/api/files/*", expire_on_second_t)
    expected_bad.append((401, "/tbox/api/files/"))
    page.locator("#files-bulk-trash").click()
    page.locator("#confirm-dialog .btn--danger").click()
    step("7 멈춤 표시 · 남은 2개", wait_until(lambda: page.locator("#files-bulk-stop").is_visible(), 20)
         and "남은 2개" in (page.locator("#files-bulk-stop-text").text_content() or ""))
    step("7 줄 = 완료 · 멈춤 · 대기", [r[1] for r in rows_of(page, "#files-bulk-body")] == ["휴지통으로 옮김", "멈춤 — 로그인이 필요합니다", "대기"], rows_of(page, "#files-bulk-body"))
    step("7 「로그인 화면 열기」 새 탭 · /tbox/login", page.get_attribute("#files-bulk-login", "target") == "_blank"
         and (page.get_attribute("#files-bulk-login", "href") or "").endswith("/tbox/login"))
    step("7 「닫기」는 멈춘 동안 누를 수 있고, 도는 동안에는 막혀 있었다(지금 활성)", page.locator("#files-bulk-close").is_enabled())
    shot(page, "tbox-expired-light-1280.png", full=True)
    set_session(ctx, M1_NEW2)
    page.locator("#files-bulk-resume").click()
    # 멈춘 동안의 끝 줄(「1개 완료 · … · 남은 2개」)에도 「완료」가 있다 — 끝난 뒤의 문장이 될 때까지 기다린다
    step("7 새 세션으로 이어서 → 「3개 완료 · 0개 못 함」",
         wait_until(lambda: (page.locator("#files-bulk-summary").text_content() or "").startswith("3개 완료 · 0개 못 함"), 25),
         page.locator("#files-bulk-summary").text_content())
    step("7 셋 다 휴지통에(DB)", sql("SELECT count(*) FROM files WHERE public_id IN (%s) AND deleted_at IS NOT NULL" % ",".join("'%s'" % x for x in M1F)) == "3")
    ctx.close()

    print("── 18. t-box 도는 중 세션 바뀜(같은 계정 · 다른 탭에서 다시 로그인) — 첫 403 에서 멈춤 · 이어서 하기 · 셸 토큰도 새 값")
    ctx, page = context(browser, E1)
    page.goto(TBOX + "/", wait_until="networkidle")
    wait_tbox_rows(page)
    step("18 파일 표 열 머리의 화면 읽기 이름 = 「이름」(체크의 이름이 섞이지 않음)",
         page.get_by_role("columnheader", name="이름", exact=True).count() == 1)
    old_tok = csrf(page)
    swapped = {"n": 0}
    def swap_on_second(route):
        if route.request.method == "PUT":
            swapped["n"] += 1
            if swapped["n"] == 2:
                # 다른 탭에서 같은 계정으로 다시 로그인한 것과 같다 — 둘째 요청부터 둘째 세션 쿠키 + 이 탭의 옛 토큰(→ 403 토큰 불일치)
                set_session(ctx, E1B)
                route.continue_(headers={**route.request.headers, "cookie": "tbox_session=" + E1B})
                return
        route.continue_()
    page.route("**/tbox/api/files/*/favorite", swap_on_second)
    expected_bad.append((403, "/tbox/api/files/%s/favorite" % E1F[1]))
    for x in E1F[0:3]:
        fcheck(page, x).click()
    page.locator("#files-bulk-fav").click()
    step("18 멈춤 — 「다른 탭에서 다시 로그인해 멈췄습니다 — 남은 2개…」",
         wait_until(lambda: page.locator("#files-bulk-stop").is_visible(), 20)
         and "다른 탭에서 다시 로그인" in (page.locator("#files-bulk-stop-text").text_content() or "")
         and "남은 2개" in (page.locator("#files-bulk-stop-text").text_content() or ""), page.locator("#files-bulk-stop-text").text_content())
    step("18 줄 = 넣음 · 「멈춤 — 로그인이 바뀌었습니다」 · 대기(거부로 세지 않음)",
         [r[1] for r in rows_of(page, "#files-bulk-body")] == ["넣음", "멈춤 — 로그인이 바뀌었습니다", "대기"], rows_of(page, "#files-bulk-body"))
    step("18 끝 줄 「1개 완료 · 0개 못 함 · 남은 2개」", (page.locator("#files-bulk-summary").text_content() or "").startswith("1개 완료 · 0개 못 함"),
         page.locator("#files-bulk-summary").text_content())
    page.locator("#files-bulk-resume").click()
    step("18 이어서 하기 → 「3개 완료 · 0개 못 함」",
         wait_until(lambda: (page.locator("#files-bulk-summary").text_content() or "").startswith("3개 완료 · 0개 못 함"), 20),
         page.locator("#files-bulk-summary").text_content())
    new_tok = csrf(page)
    step("18 셸의 토큰(재인증 창 · 로그아웃)도 새 세션 값", new_tok != old_tok and new_tok != ""
         and page.evaluate("() => [window.shellCsrf, window.reauthCsrf]") == [new_tok, new_tok])
    page.locator("summary.brand__switch").first.click()   # 로그아웃 단추는 앱 메뉴 안에 있다
    page.wait_for_timeout(300)
    with page.expect_response(lambda r: r.url.endswith("/tbox/api/logout")) as lo:
        page.locator("#logout").click()
    step("18 셸 로그아웃이 새 토큰으로 실제로 된다(200)", lo.value.status == 200, lo.value.status)
    page.wait_for_url(re.compile(r".*/tbox/login.*"), timeout=15000)   # 로그인 화면이 다 뜬 뒤에 닫는다(중간에 닫으면 요청 실패로 잡힌다)
    page.wait_for_load_state("networkidle")
    ctx.close()

    print("── 18-b. t-box 재인증 멈춤 뒤 다른 계정 — 「이어서 하기」가 보내기 전에 계정을 본다(요청 0)")
    ctx, page = context(browser, E1)
    page.goto(TBOX + "/", wait_until="networkidle")
    wait_tbox_rows(page)
    sent = {"n": 0}
    def fake_reauth(route):
        sent["n"] += 1
        if sent["n"] == 1:
            route.fulfill(status=403, body='{"error":"비밀번호를 다시 확인하세요","next":"reauth"}', headers={"Content-Type": "application/json"})
        else:
            route.continue_()
    page.route("**/tbox/api/files/*/favorite", fake_reauth)
    expected_bad.append((403, "/tbox/api/files/%s/favorite" % E1F[0]))   # 가짜 403 reauth(첫 줄)
    fcheck(page, E1F[0]).click()
    fcheck(page, E1F[1]).click()
    page.locator("#files-bulk-fav").click()
    reauth_dlg = page.locator("dialog:has(#reauth-dialog-form)")
    step("18b 재인증 창이 뜬다", wait_until(lambda: reauth_dlg.count() == 1 and reauth_dlg.is_visible()))
    reauth_dlg.locator(".modal__foot button", has_text="닫기").click()
    step("18b 창을 닫으면 멈춤 — 「비밀번호 확인이 필요해 멈췄습니다」", wait_until(lambda: page.locator("#files-bulk-stop").is_visible())
         and "비밀번호 확인" in (page.locator("#files-bulk-stop-text").text_content() or ""))
    set_session(ctx, E2)
    page.locator("#files-bulk-resume").click()
    step("18b 다른 계정 — 「이어서 할 수 없습니다」 · 「이어서 하기」 숨음",
         wait_until(lambda: "다른 계정" in (page.locator("#files-bulk-stop-text").text_content() or "")) and page.locator("#files-bulk-resume").is_hidden())
    page.wait_for_timeout(500)
    step("18b 그 뒤 요청 0(다른 계정 쿠키로 보내지 않았다)", sent["n"] == 1, sent["n"])
    ctx.close()

    print("── 20. 커뮤니티 도는 중 세션 바뀜 — 첫 403 에서 멈춤 · 이어서 하기 · 셸 토큰도 새 값 · 재인증 멈춤 뒤 다른 계정")
    ctx, page = context(browser, E2)
    page.goto(COMMUNITY + "/", wait_until="networkidle")
    tok2 = csrf(page)
    H2 = {"X-CSRF-Token": tok2, "Content-Type": "application/json"}
    sp = [(page.request.post(COMMUNITY + "/api/pages", data=json.dumps({"title": "B세션 %s %d" % (TAG, i)}), headers=H2).json().get("page") or {}).get("id", "")
          for i in range(3)]
    page.reload(wait_until="networkidle")
    page.wait_for_function("() => document.querySelectorAll('#community-tree .page-tree__row').length > 0", timeout=15000)
    page.locator("#community-select").click()
    for x in sp:
        chk(x).click()
    cswap = {"n": 0}
    def cswap_on_second(route):
        if route.request.method == "DELETE":
            cswap["n"] += 1
            if cswap["n"] == 2:
                set_session(ctx, E2B)
                route.continue_(headers={**route.request.headers, "cookie": "tbox_session=" + E2B})
                return
        route.continue_()
    page.route("**/community/api/pages/*", cswap_on_second)
    expected_bad.append((403, "/community/api/pages/%s" % sp[1]))
    page.locator("#community-bulk-trash").click()
    page.locator("#bulk-delete-confirm").click()
    step("20 멈춤 — 「다른 탭에서 다시 로그인해 멈췄습니다 — 남은 2개…」",
         wait_until(lambda: page.locator("#community-bulk-stop").is_visible(), 20)
         and "다른 탭에서 다시 로그인" in (page.locator("#community-bulk-stop-text").text_content() or "")
         and "남은 2개" in (page.locator("#community-bulk-stop-text").text_content() or ""), page.locator("#community-bulk-stop-text").text_content())
    step("20 줄 = 옮김 · 「멈춤 — 로그인이 바뀌었습니다」 · 대기(거부로 세지 않음)",
         [r[1] for r in rows_of(page, "#community-bulk-body")] == ["휴지통으로 옮김", "멈춤 — 로그인이 바뀌었습니다", "대기"], rows_of(page, "#community-bulk-body"))
    step("20 둘째·셋째 페이지는 그대로(DB)", sql("SELECT count(*) FROM community.pages WHERE public_id IN ('%s','%s') AND deleted_at IS NULL" % (sp[1], sp[2])) == "2")
    page.locator("#community-bulk-resume").click()
    step("20 이어서 하기 → 「3개 완료 · 0개 못 함」",
         wait_until(lambda: (page.locator("#community-bulk-summary").text_content() or "").startswith("3개 완료 · 0개 못 함"), 20),
         page.locator("#community-bulk-summary").text_content())
    new_tok2 = csrf(page)
    step("20 셸의 토큰(재인증 창 · 로그아웃)도 새 세션 값(window.qarediSetCsrf)", new_tok2 != tok2 and new_tok2 != ""
         and page.evaluate("() => [window.shellCsrf, window.reauthCsrf]") == [new_tok2, new_tok2])
    page.locator("#community-bulk-close").click()
    # 재인증 멈춤 뒤 다른 계정 — 같은 화면(둘째 세션)에서 가짜 403 reauth 한 번
    rp = [(page.request.post(COMMUNITY + "/api/pages", data=json.dumps({"title": "B재인증 %s %d" % (TAG, i)}), headers={"X-CSRF-Token": new_tok2, "Content-Type": "application/json"}).json().get("page") or {}).get("id", "")
          for i in range(2)]
    page.unroute("**/community/api/pages/*")
    page.reload(wait_until="networkidle")
    page.wait_for_function("(id) => !!document.querySelector(\"#community-tree a[href$='/p/\" + id + \"']\")", arg=rp[1], timeout=15000)
    csent = {"n": 0}
    def cfake_reauth(route):
        if route.request.method == "DELETE":
            csent["n"] += 1
            if csent["n"] == 1:
                route.fulfill(status=403, body='{"error":"비밀번호를 다시 확인하세요","next":"reauth"}', headers={"Content-Type": "application/json"})
                return
        route.continue_()
    page.route("**/community/api/pages/*", cfake_reauth)
    expected_bad.append((403, "/community/api/pages/%s" % rp[0]))
    page.locator("#community-select").click()
    for x in rp:
        chk(x).click()
    page.locator("#community-bulk-trash").click()
    page.locator("#bulk-delete-confirm").click()
    step("20b 셸의 재인증 창이 뜬다", wait_until(lambda: page.locator("dialog:has(#reauth-dialog-form)").count() == 1))
    page.locator("dialog:has(#reauth-dialog-form) .modal__foot button", has_text="닫기").click()
    step("20b 창을 닫으면 멈춤 — 「비밀번호 확인이 필요해 멈췄습니다」", wait_until(lambda: page.locator("#community-bulk-stop").is_visible())
         and "비밀번호 확인" in (page.locator("#community-bulk-stop-text").text_content() or ""))
    set_session(ctx, E1)
    page.locator("#community-bulk-resume").click()
    step("20b 다른 계정 — 「이어서 할 수 없습니다」 · 「이어서 하기」 숨음",
         wait_until(lambda: "다른 계정" in (page.locator("#community-bulk-stop-text").text_content() or "")) and page.locator("#community-bulk-resume").is_hidden())
    page.wait_for_timeout(500)
    step("20b 그 뒤 요청 0 · 두 페이지 그대로(DB)", csent["n"] == 1
         and sql("SELECT count(*) FROM community.pages WHERE public_id IN ('%s','%s') AND deleted_at IS NULL" % (rp[0], rp[1])) == "2", csent["n"])
    ctx.close()

    print("── 21. 커뮤니티 — 작업을 시작하면 고른 것이 풀린다 · 도는 동안 새로 고른 것은 끝난 뒤에도 남는다(내 공간 · 휴지통 창)")
    ctx, page = context(browser, E2)
    page.goto(COMMUNITY + "/", wait_until="networkidle")
    tok3 = csrf(page)
    H3 = {"X-CSRF-Token": tok3, "Content-Type": "application/json"}
    qp = [(page.request.post(COMMUNITY + "/api/pages", data=json.dumps({"title": "B풀기 %s %d" % (TAG, i)}), headers=H3).json().get("page") or {}).get("id", "")
          for i in range(3)]
    page.reload(wait_until="networkidle")
    page.wait_for_function("(id) => !!document.querySelector(\"#community-tree a[href$='/p/\" + id + \"']\")", arg=qp[2], timeout=15000)
    held = []
    def hold_first(route):
        if not held:
            held.append(route)   # 첫 요청을 잡아 둔다 — 작업이 도는 중인 상태
            return
        route.continue_()
    page.route("**/community/api/pages/*/favorite", hold_first)
    page.locator("#community-select").click()
    chk(qp[0]).click()
    chk(qp[1]).click()
    page.locator("#community-bulk-fav").click()
    step("21 시작하자마자 고른 것이 풀린다(「0개 선택됨」 · 체크 꺼짐)", wait_until(lambda: len(held) == 1)
         and page.locator("#community-bulk-count").text_content() == "0개 선택됨" and not chk(qp[0]).is_checked() and not chk(qp[1]).is_checked(),
         page.locator("#community-bulk-count").text_content())
    chk(qp[2]).click()
    step("21 도는 동안 새로 고름 → 「1개 선택됨」 · 안내 「앞 작업이 끝나면…」", page.locator("#community-bulk-count").text_content() == "1개 선택됨"
         and "앞 작업이 끝나면" in (page.locator("#community-bulk-note").text_content() or ""))
    held[0].continue_()
    step("21 작업 끝남 「2개 완료」", wait_summary(page, "community", "즐겨찾기") and (page.locator("#community-bulk-summary").text_content() or "").startswith("2개 완료"),
         page.locator("#community-bulk-summary").text_content())
    step("21 끝난 뒤에도 도는 동안 고른 것은 남는다(「1개 선택됨」 · 그 줄 체크)",
         wait_until(lambda: page.locator("#community-bulk-count").text_content() == "1개 선택됨" and chk(qp[2]).is_checked()),
         page.locator("#community-bulk-count").text_content())
    page.unroute("**/community/api/pages/*/favorite")
    page.locator("#community-bulk-close").click()
    page.keyboard.press("Escape")
    for x in qp[0:2]:
        page.request.fetch(COMMUNITY + "/api/pages/" + x, method="DELETE", headers=H3, data="{}")
    theld = []
    def thold_first(route):
        if not theld:
            theld.append(route)
            return
        route.continue_()
    page.route("**/community/api/pages/*/restore", thold_first)
    page.locator("#community-more > summary").click()
    page.locator("#community-trash").click()
    tchk = lambda pid: page.locator("#trash-body input[data-bulk-trash='%s']" % pid)
    wait_until(lambda: tchk(qp[0]).count() == 1 and tchk(qp[1]).count() == 1)
    step("21 휴지통 표 열 머리의 화면 읽기 이름 = 「제목」", page.get_by_role("columnheader", name="제목", exact=True).count() == 1)
    tchk(qp[0]).click()
    tchk(qp[1]).click()
    page.locator("#trash-bulk-restore").click()
    step("21 휴지통 창 — 되살리기를 시작하자마자 고른 것이 풀린다(막대 숨음)", wait_until(lambda: len(theld) == 1)
         and page.locator("#trash-bulk-bar").is_hidden() and not tchk(qp[0]).is_checked())
    theld[0].continue_()
    step("21 휴지통 창 — 되살리기 「2개 완료 · 0개 못 함」", wait_until(lambda: (page.locator("#trash-bulk-summary").text_content() or "").startswith("2개 완료 · 0개 못 함"), 20),
         page.locator("#trash-bulk-summary").text_content())
    page.unroute("**/community/api/pages/*/restore")
    page.locator("#trash-dialog [data-trash-close]").first.click()
    ctx.close()

    print("── 17. 폭·색 — 두 앱 × 360 · 412(터치) · 820 · 1280 × 라이트·다크 (가로 넘침 0 · 막대 한 줄)")
    for width, touch in ((360, False), (412, True), (820, False), (1280, False)):
        for scheme in ("light", "dark"):
            tagw = "%s-%d%s" % (scheme, width, "-touch" if touch else "")
            ctx, page = context(browser, E1, scheme=scheme, width=width, height=844 if width < 900 else 900, touch=touch)
            page.goto(TBOX + "/", wait_until="networkidle")
            wait_tbox_rows(page)
            fcheck(page, E1F[1]).click()
            fcheck(page, E1F[2]).click()
            ok_line, tops = one_line(page, "#files-bulk-bar")
            step("17 t-box %s — 막대 한 줄 · 가로 넘침 0" % tagw, ok_line and no_overflow(page), tops)
            page.locator("#files-bulk-fav").click()
            wait_summary(page, "files", "즐겨찾기")
            step("17 t-box %s — 진행 표가 나와도 가로 넘침 0" % tagw, no_overflow(page))
            shot(page, "tbox-bulk-%s.png" % tagw, full=True)
            page.goto(COMMUNITY + "/", wait_until="networkidle")
            page.wait_for_function("() => document.querySelectorAll('#community-tree .page-tree__row').length > 0", timeout=15000)
            page.locator("#community-select").click()
            boxes = page.locator("#community-tree input[data-bulk-page]")
            boxes.nth(0).click()
            boxes.nth(1).click()
            ok_line, tops = one_line(page, "#community-bulk-bar")
            step("17 커뮤니티 %s — 막대 한 줄 · 가로 넘침 0" % tagw, ok_line and no_overflow(page), tops)
            shot(page, "community-select-%s.png" % tagw)
            if width < 960:
                page.keyboard.press("Escape")
                page.locator("#nav-toggle").click()
                page.wait_for_timeout(400)
                st = page.locator("#nav-community-tree [data-fav-toggle]").first
                v = page.evaluate("(el) => getComputedStyle(el).visibility", st.element_handle()) if st.count() else ""
                if touch:
                    step("17 커뮤니티 %s — 터치 기기 서랍에서는 ☆ 가 늘 보인다" % tagw, v == "visible", v)
                step("17 커뮤니티 %s — 서랍 열어도 가로 넘침 0" % tagw, no_overflow(page))
                shot(page, "community-drawer-%s.png" % tagw)
            ctx.close()

    browser.close()

# 일부러 낸 4xx 만 빼고 남은 것이 있으면 실패
unexpected = [(s, u) for (s, u) in http_bad if not any(s == es and frag in u for (es, frag) in expected_bad)]
step("오류 0 — pageerror", len(pageerrors) == 0, pageerrors[:3])
step("오류 0 — console.error", len(console_errors) == 0, console_errors[:3])
step("오류 0 — 요청 실패", len(req_failed) == 0, req_failed[:3])
step("오류 0 — HTTP≥400(단계가 일부러 낸 403·401·400 빼고)", len(unexpected) == 0, unexpected[:5])
print("SMOKE_PASS=%d" % passes)
print("SMOKE_FAIL=%d" % fails)
sys.exit(1 if fails else 0)
