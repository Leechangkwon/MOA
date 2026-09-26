#!/usr/bin/env python3
# tests/browser/qf3c_zip.py — 개발 대기열 6 · Q:F-3a 라운드 C(zip 으로 받기) 화면을 진짜 브라우저로 눌러 본다
#                             (지시서 1.4 · 결정 M-5 · QF3A-T9 의 zip 몫)
#
# 부르는 쪽: tests/qf3c_zip.sh (QF3C_BROWSER=1 — 임시 인스턴스 · 임시 DB. 운영 8082 에는 요청하지 않는다).
# 쓰는 법: printf '%s\n' "$직원1" | python3 qf3c_zip.py <SITE> <UA> <SHOTS> <임시 DB> <INST>
#   세션은 **표준입력 한 줄**(R-62). 임시 DB 는 검사 상태를 정하고(sudo psql) 감사·sha256 을 보는 데만 쓴다.
# 보는 것
#   ① 막대 — 맨 앞이 「zip 으로 받기」(아이콘 단추 · 행 동작 순서 보기 → 받기 → 고치기 …) · 색 = 줄의 「다운로드」와 같은 파랑(뜻 색) · 막대 한 줄
#   ② 고른 넷(clean 셋 + 검사 중 하나) → zip 받기 → 브라우저 내려받기 한 건 · 이름 `t-box-YYYYMMDD-HHMMSS.zip` · 안 이름 = 고른 순서의 clean 셋 ·
#      sha256 = DB · 결과 한 줄 「3개를 zip 으로 받았습니다. 1개는 받을 수 없어 뺐습니다 …」 · 고른 것은 그대로
#   ③ 만드는 동안 — 단추 비활성 · 막대 아래 「zip 을 만드는 중입니다」(요청을 잠시 붙잡아 본다) · 끝나면 풀림
#   ④ 넣을 것이 없음(검사 중 하나만) → 403 서버 문장 + 「(뺀 파일 1개)」 `#msg` · 내려받기 0
#   ⑤ 폭·색 — 360 · 412(터치) · 1280 × 라이트 · 다크: 고르고 zip 받은 뒤 가로 넘침 0 · 막대 한 줄 · 결과 한 줄 보임 · 스크린샷(SHOTS — 사람이 직접 연다)
#   전 과정 pageerror · console.error · 요청 실패(blob: 제외) 0 · HTTP≥400 은 ④ 가 일부러 낸 403 만
# 나가는 값: 마지막 줄 `SMOKE_FAIL=<n>` · 종료 코드 0/1
import hashlib
import io
import json
import os
import re
import subprocess
import sys
import time
import urllib.error
import urllib.request
import uuid
import zipfile

from playwright.sync_api import sync_playwright

SITE = sys.argv[1].rstrip("/")
UA = sys.argv[2]
SHOTS = sys.argv[3]
DB = sys.argv[4]
INST = sys.argv[5]
E1 = sys.stdin.readline().rstrip("\n")
if not E1:
    print("세션을 표준입력으로 주어야 한다 — 시작하지 않는다")
    sys.exit(2)
TBOX = SITE + "/tbox"
fails = 0
passes = 0
pageerrors, console_errors, http_bad, req_failed = [], [], [], []
expected_bad = []     # (상태, 주소 조각) — 그 단계가 일부러 낸 4xx
TAG = uuid.uuid4().hex[:6]
os.makedirs(SHOTS, exist_ok=True)


def step(name, ok, detail=""):
    global fails, passes
    if ok:
        passes += 1
    else:
        fails += 1
    print("  %s %s%s" % ("✔" if ok else "✖", name, ("  — " + str(detail)[:300]) if (detail != "" and not ok) else ""))


def sql(q):
    r = subprocess.run(["sudo", "-n", "-u", "postgres", "psql", "-X", "-d", DB, "-t", "-A", "-c", q],
                       capture_output=True, text=True, cwd="/")
    return r.stdout.strip()


def host():
    return re.match(r"https?://([^/:]+)", SITE).group(1)


def api(method, path, body=None, raw=None, ctype=None, csrf=""):
    h = {"User-Agent": UA, "Cookie": "__Host-tbox_session=" + E1}
    if csrf:
        h["X-CSRF-Token"] = csrf
    data = raw
    if body is not None:
        data = json.dumps(body).encode(); h["Content-Type"] = "application/json"
    if ctype:
        h["Content-Type"] = ctype
    r = urllib.request.Request(TBOX + path, data=data, headers=h, method=method)
    try:
        with urllib.request.urlopen(r, timeout=60) as x:
            return x.status, x.read()
    except urllib.error.HTTPError as e:
        return e.code, e.read()


def upload(name, csrf):
    b = uuid.uuid4().hex
    content = ("화면 시험 " + name + " " + uuid.uuid4().hex + "\n").encode()
    parts = []
    for k, v in (("access_level", "internal"), ("description", "qf3c 화면")):
        parts.append(('--%s\r\nContent-Disposition: form-data; name="%s"\r\n\r\n%s\r\n' % (b, k, v)).encode())
    parts.append(('--%s\r\nContent-Disposition: form-data; name="file"; filename="%s"\r\nContent-Type: text/plain\r\n\r\n' % (b, name)).encode() + content + b"\r\n")
    parts.append(("--%s--\r\n" % b).encode())
    c, raw = api("POST", "/api/files", raw=b"".join(parts), ctype="multipart/form-data; boundary=" + b, csrf=csrf)
    d = json.loads(raw or b"{}")
    return ((d.get("file") or d).get("id", "")), hashlib.sha256(content).hexdigest()


def context(browser, scheme="light", width=1280, height=900, touch=False):
    ctx = browser.new_context(user_agent=UA, viewport={"width": width, "height": height}, color_scheme=scheme,
                              has_touch=touch, is_mobile=touch, accept_downloads=True)
    ctx.add_cookies([{"name": "__Host-tbox_session", "value": E1, "domain": host(), "path": "/",
                      "httpOnly": True, "secure": True, "sameSite": "Lax"}])
    page = ctx.new_page()
    page.on("pageerror", lambda e: pageerrors.append(str(e)))
    page.on("console", lambda m: console_errors.append(m.text)
            if m.type == "error" and "Failed to load resource" not in m.text else None)
    page.on("requestfailed", lambda r: req_failed.append(r.url) if not r.url.startswith("blob:") else None)
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
    tops = page.evaluate("(s) => [...document.querySelectorAll(s + ' > *')].filter(e => e.offsetParent !== null)"
                         ".map(e => { const r = e.getBoundingClientRect(); return Math.round(r.top + r.height / 2); })", sel)
    return len(tops) > 0 and max(tops) - min(tops) <= 4, tops


def shot(page, name, full=False):
    page.evaluate("() => window.scrollTo(0, 0)")
    page.screenshot(path=os.path.join(SHOTS, name), full_page=full)


def wait_rows(page):
    page.wait_for_function("() => document.querySelectorAll('#files-body tr').length > 0", timeout=15000)


def fcheck(page, fid):
    return page.locator("input[data-bulk-file='%s']" % fid)


def zip_names(path):
    z = zipfile.ZipFile(path)
    return [(i.filename, hashlib.sha256(z.read(i)).hexdigest()) for i in z.infolist()]


# ── 준비 — 이 시험 파일 넷(가장 새 파일이라 첫 쪽 맨 위) ──
st, raw = api("GET", "/api/me")
CSRF = (json.loads(raw or b"{}") or {}).get("csrf_token", "")
files = [upload("qf3c-ui-%s-%d.txt" % (TAG, i), CSRF) for i in range(4)]
IDS = [f[0] for f in files]
step("준비 — 파일 넷 올림", all(len(x) == 36 for x in IDS), IDS)
sql("UPDATE files SET scan_status = 'clean' WHERE public_id IN (%s)" % ",".join("'%s'" % x for x in IDS))
sql("UPDATE files SET scan_status = 'pending' WHERE public_id = '%s'" % IDS[3])
CLEAN = IDS[:3]
PEND = IDS[3]

with sync_playwright() as p:
    browser = p.chromium.launch()

    print("── ① 막대 — 맨 앞 「zip 으로 받기」 · 뜻 색(받기 = 파랑) · 한 줄")
    ctx, page = context(browser)
    page.goto(TBOX + "/", wait_until="networkidle")
    wait_rows(page)
    step("① 막대에 zip 단추(처음엔 막대째 숨음)", page.locator("#files-bulk-zip").count() == 1 and page.locator("#files-bulk-bar").is_hidden())
    for x in (CLEAN[2], CLEAN[0], PEND, CLEAN[1]):   # 고른 순서 = zip 안 순서(보낸 순서)
        fcheck(page, x).click()
    step("① 넷 고름 → 막대 「4개 선택됨」", wait_until(lambda: page.locator("#files-bulk-count").text_content() == "4개 선택됨"),
         page.locator("#files-bulk-count").text_content())
    first_btn = page.evaluate("() => { const b = document.querySelector('#files-bulk-bar > button'); return b ? b.id : ''; }")
    step("① 막대의 첫 단추 = zip 으로 받기(받기 → 고치기 → 이동 → ☆ → 삭제)", first_btn == "files-bulk-zip", first_btn)
    lab = page.locator("#files-bulk-zip").get_attribute("aria-label")
    step("① aria-label(툴팁) 「zip 으로 받기」 · 아이콘 단추(.btn--icon) · 아이콘 = 받기(download)",
         lab == "zip 으로 받기" and "btn--icon" in (page.locator("#files-bulk-zip").get_attribute("class") or "")
         and page.locator("#files-bulk-zip svg path").count() >= 1, lab)
    colors = page.evaluate("() => { const z = document.getElementById('files-bulk-zip'); const d = document.querySelector('#files-body .btn--icon[aria-label=\"다운로드\"]');"
                           " return [getComputedStyle(z).color, d ? getComputedStyle(d).color : '', getComputedStyle(document.getElementById('files-bulk-level')).color]; }")
    step("① 색 = 줄의 「다운로드」와 같은 파랑 · 「등급·팀 바꾸기」(회색)와 다름", colors[0] == colors[1] and colors[0] != colors[2], colors)
    ok_line, tops = one_line(page, "#files-bulk-bar")
    step("① 막대 한 줄(개수 + 아이콘 다섯 + ✕)", ok_line, tops)

    print("── ③ 만드는 동안 — 단추 비활성 · 막대 아래 한 줄 (요청을 잠시 붙잡는다)")
    held = {"route": None}
    page.route("**/tbox/api/files/zip", lambda route: held.__setitem__("route", route))
    page.locator("#files-bulk-zip").click()
    wait_until(lambda: (page.wait_for_timeout(50) or True) and held["route"] is not None, 5)   # 동기 API 는 Playwright 호출 중에만 사건을 돌린다
    busy_ok = wait_until(lambda: page.locator("#files-bulk-zip").is_disabled()
                         and "zip 을 만드는 중" in (page.locator("#files-bulk-note").text_content() or "")
                         and page.locator("#files-bulk-note").is_visible(), 5)
    step("③ 만드는 동안 단추 비활성 · 「zip 을 만드는 중입니다 …」", busy_ok, page.locator("#files-bulk-note").text_content())
    shot(page, "zip-busy-light-1280.png")
    print("── ② 받기 — 브라우저 내려받기 한 건 · 안 이름·바이트 · 결과 한 줄 · 고른 것 그대로")
    a0 = sql("SELECT coalesce(max(id),0) FROM audit_logs")
    with page.expect_download(timeout=30000) as dl_info:
        held["route"].continue_()
    dl = dl_info.value
    target = os.path.join(SHOTS, "downloaded-" + TAG + ".zip")
    dl.save_as(target)
    page.unroute("**/tbox/api/files/zip")
    step("② 내려받은 이름 t-box-YYYYMMDD-HHMMSS.zip", re.match(r"^t-box-\d{8}-\d{6}\.zip$", dl.suggested_filename or "") is not None, dl.suggested_filename)
    got = zip_names(target) if os.path.exists(target) else []
    want = [("qf3c-ui-%s-%d.txt" % (TAG, i), files[i][1]) for i in (2, 0, 1)]
    step("② zip 안 = 고른 순서의 clean 셋(검사 중 하나는 빠짐) · sha256 = 올린 바이트", got == want, (got, want))
    step("② sha256 = DB", all(sql("SELECT sha256 FROM files WHERE public_id = '%s'" % CLEAN[i]) == files[i][1] for i in range(3)))
    done = page.locator("#files-ok")
    step("② 결과 한 줄 「3개를 zip 으로 받았습니다. 1개는 받을 수 없어 뺐습니다 — …」",
         wait_until(lambda: done.is_visible() and (done.text_content() or "").startswith("3개를 zip 으로 받았습니다. 1개는 받을 수 없어 뺐습니다")),
         done.text_content())
    step("② 끝나면 단추 다시 켜짐 · 막대 아래 한 줄 사라짐 · 고른 것 그대로(4개)",
         wait_until(lambda: not page.locator("#files-bulk-zip").is_disabled() and page.locator("#files-bulk-note").is_hidden())
         and page.locator("#files-bulk-count").text_content() == "4개 선택됨")
    aud = sql("SELECT string_agg(action || ':' || result || ':' || coalesce(reason,''), ',' ORDER BY id) FROM audit_logs WHERE id > %s AND actor_name = 'claude_employee01'" % a0)
    step("② 감사 — 검사 중 거부 1 · 받기 3 · 묶음 1", aud == "file.download:deny:scan_pending,file.download:allow:,file.download:allow:,file.download:allow:,file.zip:allow:", aud)
    shot(page, "zip-done-light-1280.png")

    print("── ④ 넣을 것이 없음 — 검사 중 하나만 → 403 서버 문장 + 뺀 개수 · 내려받기 0")
    page.keyboard.press("Escape")
    wait_until(lambda: page.locator("#files-bulk-bar").is_hidden())
    fcheck(page, PEND).click()
    expected_bad.append((403, "/api/files/zip"))
    downloads = []
    page.on("download", lambda d: downloads.append(d))
    page.locator("#files-bulk-zip").click()
    m = page.locator("#files-bulk-msg")
    step("④ 막대 바로 아래 한 줄(#files-bulk-msg) = 서버 문장 + 「(뺀 파일 1개)」", wait_until(lambda: "zip 에 넣을 수 있는 파일이 없습니다" in (m.text_content() or "") and "(뺀 파일 1개)" in (m.text_content() or "")),
         m.text_content())
    pos = page.evaluate("() => { const b = document.getElementById('files-bulk-bar').getBoundingClientRect(); const e = document.getElementById('files-bulk-msg').getBoundingClientRect();"
                        " return [Math.round(b.bottom), Math.round(e.top), Math.round(e.bottom), window.innerHeight, getComputedStyle(document.getElementById('files-bulk-msg')).color,"
                        " getComputedStyle(document.getElementById('msg')).color]; }")
    step("④ 그 줄이 막대 바로 밑(24px 안) · 화면 안 · 색 = 화면 끝 #msg 와 같은 빨강(.msg)", 0 <= pos[1] - pos[0] <= 24 and pos[2] <= pos[3] and pos[4] == pos[5], pos)
    page.wait_for_timeout(500)
    step("④ 내려받기 0 · 결과 한 줄 숨음 · 화면 끝 #msg 는 비었다", len(downloads) == 0 and page.locator("#files-ok").is_hidden() and (page.locator("#msg").text_content() or "") == "")
    shot(page, "zip-nothing-light-1280.png")
    page.keyboard.press("Escape")
    step("④ 고른 것을 풀면(Esc) 거부 한 줄도 지워진다", wait_until(lambda: page.locator("#files-bulk-bar").is_hidden() and (m.text_content() or "") == ""))
    ctx.close()

    print("── ⑤ 폭·색 — 360 · 412(터치) · 1280 × 라이트·다크 (고르고 zip 받은 뒤 · 가로 넘침 0 · 막대 한 줄 · 결과 한 줄)")
    for width, touch in ((360, False), (412, True), (1280, False)):
        for scheme in ("light", "dark"):
            tagw = "%s-%d" % (scheme, width)
            ctx, page = context(browser, scheme=scheme, width=width, height=820, touch=touch)
            page.goto(TBOX + "/", wait_until="networkidle")
            wait_rows(page)
            for x in (CLEAN[0], CLEAN[1], PEND):
                fcheck(page, x).click()
            ok_line, tops = one_line(page, "#files-bulk-bar")
            step("⑤ %s — 막대 한 줄(개수 + 아이콘 다섯 + ✕)" % tagw, ok_line, tops)
            with page.expect_download(timeout=30000) as dl_info:
                page.locator("#files-bulk-zip").click()
            dl = dl_info.value
            step("⑤ %s — 내려받기 한 건(%s)" % (tagw, dl.suggested_filename), re.match(r"^t-box-\d{8}-\d{6}\.zip$", dl.suggested_filename or "") is not None)
            step("⑤ %s — 결과 한 줄 「2개를 … 1개는 …」 보임" % tagw,
                 wait_until(lambda: page.locator("#files-ok").is_visible() and (page.locator("#files-ok").text_content() or "").startswith("2개를 zip 으로 받았습니다. 1개는")))
            page.wait_for_timeout(300)
            step("⑤ %s — 가로 넘침 0" % tagw, no_overflow(page), page.evaluate("() => [document.documentElement.scrollWidth, window.innerWidth]"))
            box = page.evaluate("() => { const r = document.getElementById('files-ok').getBoundingClientRect(); return [r.left, r.right, window.innerWidth]; }")
            step("⑤ %s — 결과 한 줄이 화면 안(좌우)" % tagw, box[0] >= 0 and box[1] <= box[2] + 0.5, box)
            shot(page, "zip-%s.png" % tagw)
            page.locator("#files-bulk-zip").hover()
            page.wait_for_timeout(300)
            shot(page, "zip-hover-%s.png" % tagw)
            step("⑤ %s — zip 단추에 올려도 가로 넘침 0(툴팁)" % tagw, no_overflow(page))
            ctx.close()

    browser.close()

# 일부러 낸 4xx 만 빼고 남은 것이 있으면 실패
unexpected = [(s, u) for (s, u) in http_bad if not any(s == es and frag in u for (es, frag) in expected_bad)]
step("오류 0 — pageerror", len(pageerrors) == 0, pageerrors[:3])
step("오류 0 — console.error", len(console_errors) == 0, console_errors[:3])
step("오류 0 — 요청 실패(blob: 빼고)", len(req_failed) == 0, req_failed[:3])
step("오류 0 — HTTP≥400(④ 가 일부러 낸 403 빼고)", len(unexpected) == 0, unexpected[:5])
leftover = [n for n in os.listdir(os.path.join(INST, "tmp")) if n.startswith("zip-")]
step("끝 — 앱 임시 폴더에 zip-* 0", leftover == [], leftover)
print("SMOKE_PASS=%d" % passes)
print("SMOKE_FAIL=%d" % fails)
sys.exit(1 if fails else 0)
