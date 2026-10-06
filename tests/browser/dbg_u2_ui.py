#!/usr/bin/env python3
# tests/browser/dbg_u2_ui.py — 디버깅 묶음 U2(디자인 점검 2026-09-26 12:13 의 남은 중간 + 그날 검토·운영 캡처) 화면을 진짜 브라우저로 눌러 본다
#
# 부르는 쪽: tests/dbg_u2_ui.sh (임시 인스턴스 · 임시 DB `tbox_d2u_*`. 운영 80·8082·8081 에는 요청하지 않는다).
# 쓰는 법: py_secret S_E1 S_A1 S_G1 PW_A1 -- python3 dbg_u2_ui.py <SITE> <UA> <SHOTS> [before]
#   세션 셋 · 관리자 비밀번호 하나는 **표준입력 네 줄**(R-62 — 명령 인자에 비밀 0).
#   넷째 인자 `before` 는 고치기 전 판(4d256f6)을 찍을 때 — 판정은 참고용이고 스크린샷만 쓴다(종료 코드 0).
# 폭·색: 360 · 412(터치 흉내) · 820 · 1280 × 라이트 · 다크 = 8 조합(DBGU2_COMBOS 로 줄임). 조합마다 화면을 찍고(SHOTS — 사람이 직접 연다) 잰다.
# 보는 것
#   공통    문서 가로 넘침 0 · 콘솔 오류·pageerror 0 · 브라우저 기본 창(alert·prompt·confirm) 0건
#   M3      창 단추 규칙(DESIGN 2.7) — 화면에 있는 **모든 창**(닫힌 것 포함)의 바닥: 「닫기」 하나(테두리) · 그 오른쪽은 주요 하나(검정 또는 빨강)뿐 ·
#           닫기 왼쪽엔 검정 0 · 본문 안 제출·검정 단추 0 · 「그만두기」「취소」 0 · 머리에 ✕. 연 창은 보이는 순서(x)가 DOM 순서와 같고 단추가 화면 안
#   M6      낱말 표(DESIGN 5장) — 화면 글자(단추·이름표·머리·안내·고르개·aria-label·도움말)에 옛 낱말 0:
#           복구 · 삭제 · 다운로드 · 내려받 · 레벨 · 접근 등급 · 내 계정 설정 · 그만두기(창 바닥)
#   등급    파일 고치기·올리기·가져오기·등급 바꾸기·새 폴더 창의 등급 고르개, 계정 역할 고르개, 감사 등급 거르기 — 글자에 영어 값 0(값은 그대로)
#   재인증  공용 재인증 창을 Esc 로 닫으면 창이 DOM 에서 빠지고, 다시 저장하면 **다시 뜬다** · 수정 창에 이유 한 줄
#   M8      계정 행 동작 = 「관리」 하나 · 상세 창에서 기존 동작 다섯(이름·역할·팀·만료 / 임시 비밀번호 / 2단계 초기화 / 프로그램 토큰 / 비활성화) ·
#           창 안에서 다른 창을 겹쳐 열지 않는다(상세 창을 닫고 연다) · 좁은 폭(720 미만)만 행 쌓기 — 820 은 표
#   M12     폰 폭(360·412)에서 누르는 것(단추·링크 단추·체크·접기 머리)의 누름 자리 44×44px 이상 · 표 줄 체크는 보이는 크기 그대로
#   L-2     데이터베이스 보기 — 손님(me.can_community_write=false)이면 창구 값이 참이어도 「행 추가」·「속성 더하기」 숨김(직원은 그대로)
# 나가는 값: 마지막 줄 `SMOKE_FAIL=<n>` · 종료 코드 0/1
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
S_A1 = sys.stdin.readline().rstrip("\n")
S_G1 = sys.stdin.readline().rstrip("\n")
PW_A1 = sys.stdin.readline().rstrip("\n")
if not (S_E1 and S_A1 and S_G1 and PW_A1):
    print("세션·비밀번호를 표준입력으로 주어야 한다 — 시작하지 않는다")
    sys.exit(2)
HOST = re.match(r"https?://([^/:]+)", SITE).group(1)
COMBOS = [(int(w), t) for w, t in (c.split(":") for c in (os.environ.get("DBGU2_COMBOS") or "360:light,360:dark,412:light,412:dark,820:light,820:dark,1280:light,1280:dark").split(","))]
os.makedirs(SHOTS, exist_ok=True)
fails = 0
passes = 0
dialogs = []
errors = []
TAG = uuid.uuid4().hex[:6]


def step(name, ok, detail=""):
    global fails, passes
    if ok:
        passes += 1
    else:
        fails += 1
    print("  %s %s%s" % ("✔" if ok else "✖", name, ("  — " + str(detail)[:400]) if (detail != "" and not ok) else ""), flush=True)


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

    def upload(self, name, data, level="internal"):
        bnd = "----dbgu2" + uuid.uuid4().hex
        parts = []
        for k, v in (("access_level", level), ("description", "")):
            parts.append(("--%s\r\nContent-Disposition: form-data; name=\"%s\"\r\n\r\n%s\r\n" % (bnd, k, v)).encode())
        parts.append(("--%s\r\nContent-Disposition: form-data; name=\"file\"; filename=\"%s\"\r\nContent-Type: application/octet-stream\r\n\r\n" % (bnd, name)).encode() + data + b"\r\n")
        parts.append(("--%s--\r\n" % bnd).encode())
        return self.req("POST", "/tbox/api/files", raw=b"".join(parts), ctype="multipart/form-data; boundary=" + bnd)


E = Api(S_E1)
A = Api(S_A1)
G = Api(S_G1)
print("── 준비 (임시 DB 에만 쓴다)")
step("세 계정 /api/me 200", bool(E.csrf and A.csrf and G.csrf), (bool(E.csrf), bool(A.csrf), bool(G.csrf)))
names = ["DBGU2-%s-분기_보고서_최종.csv" % TAG, "DBGU2-%s-회의록.txt" % TAG, "DBGU2-%s-휴지통.txt" % TAG]
ids = {}
for n in names:
    c, d = E.upload(n, ("dbgu2 %s %s\n" % (TAG, n)).encode())
    ids[n] = (d.get("file") or d).get("id") if isinstance(d, dict) else None
step("파일 셋 올리기 201", all(ids.values()), ids)
if ids.get(names[2]):
    c, _ = E.req("DELETE", "/tbox/api/files/" + ids[names[2]])
    step("셋째 파일 휴지통으로 200", c == 200, c)
c, d = E.req("POST", "/community/api/pages", {"title": "DBGU2 창 시험 " + TAG})
PID = ((d.get("page") or {}).get("id")) if isinstance(d, dict) else None
step("커뮤니티 페이지 201", c == 201 and bool(PID), (c, str(d)[:120]))
c, d = E.req("POST", "/community/api/pages", {"title": "DBGU2 지울 페이지 " + TAG})
TP = ((d.get("page") or {}).get("id")) if isinstance(d, dict) else None
if TP:
    c2, _ = E.req("DELETE", "/community/api/pages/" + TP)
    step("커뮤니티 페이지 휴지통으로 200", c2 == 200, c2)
# 재발급 시험용 계정 하나(임시 DB) — 검토 계정의 비밀번호를 바꾸지 않으려고 따로 만든다
TEMP_USER = "dbgu2_" + TAG
c, d = A.req("POST", "/tbox/api/users", {"username": TEMP_USER, "role": "Employee", "reason_text": "디버깅 묶음 U2 재발급 화면 시험"})
step("재발급 시험용 계정 만들기 201(임시 DB)", c == 201, (c, str(d)[:160]))
c, gp = G.req("GET", "/community/api/pages?tree=1")
GUEST_PAGE = next((p.get("id") for p in (gp.get("pages") or [])), None) if isinstance(gp, dict) else None
step("손님의 시작하기 페이지가 있다", bool(GUEST_PAGE), str(gp)[:160])

# ─── 측정 도우미 ──────────────────────────────────────────────────
OVER = "() => document.documentElement.scrollWidth - document.documentElement.clientWidth"

# 창 단추 규칙(DESIGN 2.7 「창 바닥 단추」) — 화면에 있는 모든 <dialog>(닫힌 것 포함, 검색 창 제외)
MODAL_RULE = r"""(onlyOpen) => {
  const bad = []; let n = 0;
  const black = b => b.classList.contains('btn') && !b.matches('.btn--quiet, .btn--danger, .btn--plain, .btn--icon');
  for (const d of document.querySelectorAll('dialog')) {
    if (d.id === 'search-dialog') continue;
    // 숨김은 **창 안에서만** 본다 — 창을 품은 사이드바(<aside hidden>, 넓은 화면에선 CSS 가 보임)는 따지지 않는다
    const shown = b => { if (b.hidden) return false; const h = b.closest('[hidden]'); return !(h && d.contains(h)); };
    if (onlyOpen && !d.open) continue;
    n++;
    const id = d.id || d.className;
    const head = d.querySelector(':scope > .modal__head, :scope > form > .modal__head');
    const x = head && [...head.querySelectorAll('button')].find(b => b.matches('.btn--icon') && b.getAttribute('aria-label') === '닫기');
    if (!x) bad.push([id, '머리에 ✕ 없음']);
    // 떠 있는 창(DESIGN 2.17 · 사이트 Q:F-11)의 바닥은 입력 줄 form 하나다 — 입력 칸 + 「닫기」(좁은 폭 시트) + 검정 「보내기」. 규칙은 같게 센다.
    const foots = d.querySelectorAll(d.matches('dialog.floating') ? ':scope > form' : ':scope > .modal__foot, :scope > .form-actions, :scope > form > .modal__foot, :scope > form > .form-actions');
    if (foots.length !== 1) { bad.push([id, '바닥 ' + foots.length]); continue; }
    const foot = foots[0];
    const btns = [...foot.querySelectorAll(':scope > button, :scope > a.btn')].filter(shown);
    const closes = btns.filter(b => b.textContent.trim() === '닫기');
    if (closes.length !== 1) { bad.push([id, '「닫기」 ' + closes.length + '개']); continue; }
    const close = closes[0];
    if (!close.matches('.btn--quiet')) bad.push([id, '「닫기」가 테두리 단추가 아님']);
    for (const b of btns) if (/그만두기|취소/.test(b.textContent)) bad.push([id, '낱말 ' + b.textContent.trim()]);
    const i = btns.indexOf(close);
    const after = btns.slice(i + 1), before = btns.slice(0, i);
    if (after.length > 1) bad.push([id, '「닫기」 오른쪽 단추 ' + after.length + '개']);
    if (after.length === 1 && !(black(after[0]) || after[0].matches('.btn--danger:not(.btn--quiet)'))) bad.push([id, '오른쪽 끝이 주요 단추가 아님: ' + after[0].textContent.trim()]);
    if (before.some(black)) bad.push([id, '「닫기」 왼쪽에 검정 단추']);
    // 탭(role=tab)의 검정은 「고른 탭」 표시라 단추 위계가 아니다(이력 창의 버전·처리 이력)
    const bodyBlack = [...d.querySelectorAll('button, a.btn')].filter(b => !foot.contains(b) && shown(b) && black(b) && !b.closest('.modal__head') && b.getAttribute('role') !== 'tab');
    if (bodyBlack.length) bad.push([id, '본문 안 검정 단추 ' + bodyBlack.map(b => b.textContent.trim().slice(0, 12)).join('/')]);
    const bodySubmit = [...d.querySelectorAll('button[type=submit]')].filter(b => !foot.contains(b) && shown(b));
    if (bodySubmit.length) bad.push([id, '본문 안 제출 단추 ' + bodySubmit.map(b => b.textContent.trim().slice(0, 12)).join('/')]);
    const fs = getComputedStyle(foot);
    if (/reverse/.test(fs.flexDirection)) bad.push([id, '바닥 flex 순서 뒤집힘']);
    for (const b of btns) if (getComputedStyle(b).order !== '0') bad.push([id, 'order ' + b.textContent.trim()]);
    if (d.open) {
      const W = document.documentElement.clientWidth;
      const rs = btns.map(b => b.getBoundingClientRect()).filter(r => r.width > 0);
      for (let k = 1; k < rs.length; k++) {
        const same = Math.abs(rs[k].top - rs[k - 1].top) < 4;
        if (same && rs[k].left < rs[k - 1].left) bad.push([id, '보이는 순서가 DOM 순서와 다름']);
      }
      for (const r of rs) if (r.right > W + 1 || r.left < -1) bad.push([id, '바닥 단추가 화면 밖']);
    }
  }
  return {n: n, bad: bad};
}"""

# 낱말 표(DESIGN 5장) — 화면 글자 중 「UI 가 쓴 말」만(데이터 칸·문서 본문·감사 문장·릴리스 기록은 뺀다)
OLD = ["복구", "삭제", "다운로드", "내려받", "내려 받", "레벨", "접근 등급", "내 계정 설정", "루트", "업로드"]
WORDS = r"""(old) => {
  const skip = '#page-doc, .doc, #audit-body, #activity-body, .changelog__list, #changelog-dialog, .list__body, #files-bulk-body, #upload-status-body, [data-user-text]';
  const sel = 'button, a, summary, th, label, .field__label, h1, h2, h3, .page-head__sub, .panel__sub, .help, .notice, .muted, .modal__title, .modal__meta, option, .card__title, .card__desc, .nav__item, .menu__item, .app-menu__name, .help-dialog__list li, .preview__label, .preview__value, [aria-label], [title]';
  const hits = [];
  const vis = e => !!(e.getClientRects().length) || e.tagName === 'OPTION';
  for (const e of document.querySelectorAll(sel)) {
    if (e.closest(skip)) continue;
    const dlg = e.closest('dialog');
    if (dlg && !dlg.open) continue;
    if (!vis(e)) continue;
    let t = '';
    if (['BUTTON','A','SUMMARY','TH','LABEL','H1','H2','H3','OPTION'].includes(e.tagName) || e.matches('.field__label, .page-head__sub, .panel__sub, .help, .notice, .muted, .modal__title, .card__title, .card__desc, .nav__item, .menu__item, .app-menu__name, .help-dialog__list li, .preview__label')) {
      // 데이터 칸(td) 안의 글자는 빼고, 이 요소가 직접 가진 글자
      t = [...e.childNodes].filter(n => n.nodeType === 3).map(n => n.textContent).join(' ');
      if (['BUTTON','A','OPTION','TH','H1','H2','H3','SUMMARY'].includes(e.tagName) || e.matches('.card__title, .nav__item, .menu__item, .help-dialog__list li, .field__label')) t = e.textContent;
    }
    t += ' ' + (e.getAttribute('aria-label') || '') + ' ' + (e.matches('button, a, [data-icon]') ? (e.getAttribute('title') || '') : '');
    for (const w of old) if (t.includes(w)) hits.push([w, t.trim().replace(/\s+/g, ' ').slice(0, 40)]);
  }
  // 고르개 글자에 영어 값(등급·역할)
  for (const o of document.querySelectorAll('select option')) {
    if (o.closest(skip)) continue;
    const t = o.textContent.trim();
    if (/^(public|internal|team|admin-only)\b/.test(t) || /\((Admin|Manager|Employee|Guest)\)/.test(t) || /^(Admin|Manager|Employee|Guest)$/.test(t)) hits.push(['영어 값', t]);
  }
  return hits;
}"""

# 터치 대상(DESIGN 4장) — 보이는 누르는 것의 누름 자리(자기 상자 ∪ ::before 상자) 44×44 이상
TOUCH = r"""(root) => {
  const W = document.documentElement.clientWidth, H = window.innerHeight;
  const out = []; const clipped = []; const stolen = []; let n = 0;
  const IA = 'button, a[href], summary, input, select, textarea, label';
  // 보이는 상자 안 어디를 눌러도 그 자신인가(독립 검토 중간 — 넓힌 자리가 이웃을 가져가지 않았나): 위·가운데·아래 끝, 왼·오른 끝을 눌러 본다.
  // 가져간 것이 다른 누르는 것(또는 그 안)일 때만 센다 — 붙박이 머리 줄 같은 누르지 않는 덮개는 이 규칙 밖
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
    if (e.closest('#page-doc, .doc, .changelog__list')) continue;
    const dlg = e.closest('dialog'); if (dlg && !dlg.open) continue;
    if (e.disabled) continue;
    const cs = getComputedStyle(e);
    if (cs.visibility === 'hidden' || cs.display === 'none') continue;
    if (e.tagName === 'A' && cs.display === 'inline') continue;          // 글 속 링크 — 규칙 밖
    if (e.matches('input') && e.closest('label')) continue;              // 글자와 함께 누르는 체크·라디오 — 라벨이 누름 자리
    const r = e.getBoundingClientRect();
    if (r.width < 2 || r.height < 2 || r.right < 0 || r.bottom < 0 || r.left > W || r.top > H) continue;
    // 닫힌 접기(details — 새 Chromium 은 content-visibility 로 숨겨 상자가 남는다) · 스크롤 칸 밖으로 밀려 가린 것은 셈하지 않는다
    if (e.checkVisibility && !e.checkVisibility({visibilityProperty: true, contentVisibilityAuto: true})) continue;
    const c0 = document.elementFromPoint(Math.min(W - 1, Math.max(0, (r.left + r.right) / 2)), Math.min(H - 1, Math.max(0, (r.top + r.bottom) / 2)));
    if (!c0 || !(c0 === e || e.contains(c0) || c0.contains(e))) continue;
    n++;
    const [w, h] = box(e);
    const name = (e.getAttribute('aria-label') || e.textContent || e.tagName).trim().replace(/\s+/g, ' ').slice(0, 18);
    selfHit(e, r, name);
    if (w < 43.5 || h < 43.5) { out.push([name, Math.round(w), Math.round(h)]); continue; }
    // 넓힌 자리가 조상의 넘침 숨김에 잘리거나 다른 것에 덮이지 않았나 — 보이는 상자 밖, 넓힌 자리 안쪽 끝(모자란 폭의 절반 − 1px)을 눌러 본다(화면 안인 점만).
    // 그 점이 이 요소(또는 그 안)이거나 **이웃한 누르는 것**이면 된다(이웃과 겹친 자리는 뒤의 것이 받는다 — 4장)
    const cx = (r.left + r.right) / 2, cy = (r.top + r.bottom) / 2;
    const oy = (44 - r.height) / 2 - 1, ox = (44 - r.width) / 2 - 1;
    const pts = [];
    if (oy >= 1) pts.push([cx, r.top - oy], [cx, r.bottom + oy]);
    if (ox >= 1) pts.push([r.left - ox, cy], [r.right + ox, cy]);
    // 넘침을 자르는 가장 가까운 조상(스크롤 칸 · 표 감싸개) 밖의 점은 보지 않는다 — 칸 가장자리의 단추는 그 칸까지만 넓어진다(4장 한계)
    let clip = null;
    for (let a = e.parentElement; a && a !== document.body; a = a.parentElement) {
      const o = getComputedStyle(a);
      if (o.overflowX !== 'visible' || o.overflowY !== 'visible') { clip = a.getBoundingClientRect(); break; }
    }
    for (const [x, y] of pts) {
      if (x < 1 || y < 1 || x > W - 1 || y > H - 1) continue;
      if (clip && (x < clip.left || x > clip.right || y < clip.top || y > clip.bottom)) continue;
      const hit = document.elementFromPoint(x, y);
      if (!hit) continue;
      if (hit === e || e.contains(hit)) continue;
      if (hit.closest('button, a[href], summary, input, select, textarea, label')) continue;
      clipped.push([name, Math.round(x), Math.round(y), hit.tagName + (hit.id ? '#' + hit.id : '') + (hit.className && typeof hit.className === 'string' ? '.' + hit.className.split(' ')[0] : '')]);
      break;
    }
  }
  for (const l of scope.querySelectorAll('label:has(> input[type=checkbox]), label:has(> input[type=radio]), .field__label:has(> input[type=checkbox]), .field__label:has(> input[type=radio])')) {
    const dlg = l.closest('dialog'); if (dlg && !dlg.open) continue;
    const r = l.getBoundingClientRect();
    if (r.width < 2 || r.bottom < 0 || r.top > H) continue;
    n++;
    selfHit(l, r, '라벨 ' + l.textContent.trim().slice(0, 14));
    if (r.height < 43.5 && box(l.querySelector('input'))[1] < 43.5) out.push(['라벨 ' + l.textContent.trim().slice(0, 14), Math.round(r.width), Math.round(r.height)]);
  }
  return {n: n, bad: out, clipped: clipped, stolen: stolen};
}"""


def modal_rule(pg, what, only_open=False, need=1):
    try:
        r = pg.evaluate(MODAL_RULE, only_open)
    except Exception as ex:
        step(what, False, str(ex)[:160])
        return
    step("M3 창 단추 규칙 — %s (창 %d개)" % (what, r["n"]), r["n"] >= need and not r["bad"], r["bad"][:6] if r["bad"] else "창 %d개" % r["n"])


def words(pg, what):
    try:
        h = pg.evaluate(WORDS, OLD)
    except Exception as ex:
        step(what, False, str(ex)[:160])
        return
    step("M6 낱말 표 — %s 화면 글자에 옛 낱말·영어 값 0" % what, not h, h[:6])


def touch(pg, what, root="body"):
    try:
        r = pg.evaluate(TOUCH, root)
    except Exception as ex:
        step(what, False, str(ex)[:160])
        return
    step("M12 터치 대상 44px — %s (누르는 것 %d개)" % (what, r["n"]), r["n"] > 0 and not r["bad"], "%d개 모자람 %s" % (len(r["bad"]), r["bad"][:8]))
    if not BEFORE:
        step("M12 넓힌 누름 자리가 잘리거나 덮이지 않음 — %s" % what, not r["clipped"], "%d개 %s" % (len(r["clipped"]), r["clipped"][:6]))
    step("M12 보이는 상자 안 어디를 눌러도 자기 자신(위·가운데·아래·왼·오른 끝) — %s" % what, not r["stolen"], "%d개 %s" % (len(r["stolen"]), r["stolen"][:6]))


def no_overflow(pg, what):
    try:
        o = pg.evaluate(OVER)
    except Exception as ex:
        o = str(ex)[:80]
    step(what + " — 가로 넘침 0", isinstance(o, int) and o <= 0, o)


def shot(pg, name):
    try:
        pg.screenshot(path=os.path.join(SHOTS, name + ".png"), full_page=False)
    except Exception as ex:
        print("  · 스크린샷 실패 %s %s" % (name, str(ex)[:80]))


def ctx(br, sess, w, theme):
    touch_dev = w < 720
    c = br.new_context(user_agent=UA, viewport={"width": w, "height": 900}, device_scale_factor=1,
                       has_touch=touch_dev, is_mobile=touch_dev, color_scheme="light")
    ck = [{"name": "tbox_theme", "value": theme, "domain": HOST, "path": "/"}]
    if sess:
        ck.append({"name": "__Host-tbox_session", "value": sess, "domain": HOST, "path": "/", "httpOnly": True, "secure": True})   # S2 — __Host- 쿠키(secure · path /)
    c.add_cookies(ck)
    return c


def page_of(c):
    pg = c.new_page()
    pg.on("dialog", lambda d: (dialogs.append(d.type + ": " + d.message[:60]), d.dismiss()))
    pg.on("pageerror", lambda e: errors.append("pageerror " + str(e)[:160]))
    pg.on("console", lambda m: errors.append("console " + pg.url.replace(SITE, "")[:40] + " " + m.text[:160]) if m.type == "error" else None)
    return pg


def go(pg, path):
    pg.goto(SITE + path, wait_until="networkidle", timeout=30000)
    pg.wait_for_timeout(400)


def guard(name, fn):
    try:
        fn()
    except Exception as ex:
        step(name + " — 진행 중 예외", False, str(ex).split("\n")[0][:200])


def open_dialog_checks(pg, did, what, tag, narrow):
    """열린 창 하나 — 단추 규칙(보이는 순서) · 낱말 · (폰) 터치 · 넘침 · 스크린샷"""
    opened = pg.evaluate("(i) => !!document.querySelector('dialog#' + i + '[open]')", did)
    step("%s 창이 열렸다 %s" % (what, tag), opened, did)
    if not opened:
        return False
    modal_rule(pg, "%s(연 창) %s" % (what, tag), only_open=True)
    words(pg, "%s 창 %s" % (what, tag))
    if narrow:
        touch(pg, "%s 창 %s" % (what, tag), "dialog#" + did)
    no_overflow(pg, "%s 창 %s" % (what, tag))
    inner = pg.evaluate("""(i) => { const b = document.querySelector('dialog#' + i + ' .modal__body'); return b ? b.scrollWidth - b.clientWidth : 0; }""", did)
    step("%s 창 본문 가로 넘침 0 %s" % (what, tag), inner <= 1, inner)
    # 같은 id 의 창이 두 화면에 있다(파일 이동 · 페이지 옮기기 — move-dialog) — 화면 주소를 이름 앞에
    where = "c" if "/community/" in pg.url else "t"
    shot(pg, "%s-%s-%s" % (where, did, tag))
    return True


def esc(pg):
    pg.keyboard.press("Escape")
    pg.wait_for_timeout(250)


print("── 화면 (%s)" % ("고치기 전 판 — 스크린샷만" if BEFORE else "고친 판"))
reauth_done = False
with sync_playwright() as p:
    br = p.chromium.launch()
    for w, theme in COMBOS:
        tag = "%d-%s" % (w, theme)
        narrow = w < 720
        print("\n── %s" % tag, flush=True)

        # ── 직원: t-box 파일 화면과 창들 ─────────────────────────────
        c = ctx(br, S_E1, w, theme)
        pg = page_of(c)

        def files():
            go(pg, "/tbox/")
            no_overflow(pg, "t-box 파일 " + tag)
            modal_rule(pg, "t-box 파일 화면의 창 전부 " + tag, need=8)
            words(pg, "t-box 파일 " + tag)
            if narrow:
                touch(pg, "t-box 파일 " + tag)
                # 줄 체크: 보이는 크기 그대로(13~16px) · 누름 자리 44 — 체크 왼쪽 10px 을 눌러도 그 체크
                r = pg.evaluate("""() => { const c = document.querySelector('#files-body input[type=checkbox][data-bulk]'); if (!c) return null;
                    const b = c.getBoundingClientRect(); const e = document.elementFromPoint(b.left - 10, b.top + b.height / 2);
                    const e2 = document.elementFromPoint(b.left + b.width / 2, b.top - 10);
                    return {w: Math.round(b.width), hit: e === c, hit2: e2 === c}; }""")
                step("M12 표 줄 체크 — 보이는 크기 그대로 · 바깥 10px 을 눌러도 그 체크 " + tag,
                     bool(r) and r["w"] <= 20 and r["hit"] and r["hit2"], r)
                # 서랍(사이드바)을 열고 — 세로로 쌓인 내비 줄(독립 검토 실측: 넓힌 자리가 다음 줄을 가져감)
                pg.click("#nav-toggle")
                pg.wait_for_timeout(350)
                touch(pg, "t-box 사이드바(서랍) " + tag, "#sidebar")
                shot(pg, "t-sidebar-" + tag)
                pg.click("#nav-toggle")
                pg.wait_for_timeout(250)
            shot(pg, "t-files-" + tag)
        guard("t-box 파일 " + tag, files)

        def file_dialogs():
            go(pg, "/tbox/")
            pg.click("#upload-toggle")
            pg.wait_for_timeout(300)
            if open_dialog_checks(pg, "upload-dialog", "파일 올리기", tag, narrow):
                opts = pg.evaluate("() => [...document.querySelectorAll('#upload-level option')].map(o => o.textContent.trim())")
                step("등급 고르개(올리기) 글자 한글 — 영어 값 0 " + tag, bool(opts) and not any(re.match(r"(public|internal|team|admin-only)\b", o) for o in opts), opts)
                last = pg.evaluate("() => { const b = [...document.querySelectorAll('#upload-dialog .modal__foot button')].filter(x => !x.hidden); return b.length ? [b[b.length - 1].id, b[b.length - 1].textContent.trim()] : null; }")
                step("올리기 창 — 「올리기」가 바닥 오른쪽 끝 " + tag, last is not None and last[0] == "upload-submit", last)
            esc(pg)
            if (w, theme) == (1280, "light") and not BEFORE:
                # 제출 단추를 폼 밖(바닥)으로 옮겼다 — form 속성으로 잇는다. ① 올리기: 바닥 「올리기」를 눌러 올라가나
                got = []
                pg.on("response", lambda r: got.append((r.request.method, r.url.split("?")[0].rsplit("/tbox", 1)[-1], r.status)) if r.request.method == "POST" and "/tbox/api/" in r.url else None)
                pg.click("#upload-toggle")
                pg.wait_for_timeout(300)
                pg.set_input_files("#upload-file", files=[{"name": "DBGU2-%s-바닥단추.txt" % TAG, "mimeType": "text/plain", "buffer": b"dbgu2 foot submit\n"}])
                pg.select_option("#upload-level", "internal")
                pg.click("#upload-submit")
                pg.wait_for_timeout(2500)
                up = [g for g in got if g[1] == "/api/files"]
                step("바닥 단추 — 올리기 창 바닥 「올리기」 → POST /api/files 201", up[:1] == [("POST", "/api/files", 201)], got)
                esc(pg)
                pg.wait_for_timeout(300)
                # ② 새 폴더: 이름 칸에서 Enter — 제출 단추가 폼 밖이어도 Enter 제출이 된다(form 속성)
                if pg.locator("#folder-new").is_visible():
                    got.clear()
                    pg.click("#folder-new")
                    pg.wait_for_timeout(300)
                    pg.fill("#folder-name", "DBGU2 폴더 " + TAG)
                    pg.select_option("#folder-level-select", "internal")   # 맨 위에는 기본 등급이 없다(필수 칸 — 고르지 않으면 브라우저가 제출을 막는다)
                    pg.press("#folder-name", "Enter")
                    pg.wait_for_timeout(1500)
                    fd = [g for g in got if g[1] == "/api/folders"]
                    closed = not pg.evaluate("() => !!document.querySelector('#folder-dialog[open]')")
                    step("바닥 단추 — 새 폴더 이름 칸 Enter → POST /api/folders 201 · 창 닫힘", fd[:1] == [("POST", "/api/folders", 201)] and closed, (got, closed))
                else:
                    step("바닥 단추 — 새 폴더 단추가 보인다(직원)", False, "새 폴더 단추 없음")
                go(pg, "/tbox/")
            row = pg.locator("#files-body tr", has_text=names[0]).first
            row.locator("button[aria-label='수정']").click()
            pg.wait_for_timeout(300)
            if open_dialog_checks(pg, "file-edit-dialog", "파일 고치기", tag, narrow):
                info = pg.evaluate("""() => ({opts: [...document.querySelectorAll('#file-edit-level option')].map(o => [o.value, o.textContent.trim()]),
                   label: (document.querySelector('#file-edit-level-field .field__label') || {}).textContent || ''})""")
                step("파일 고치기 — 등급 고르개 글자 한글만(값은 영어 그대로) " + tag,
                     bool(info["opts"]) and all(v in ("public", "internal", "team", "admin-only") and v not in t for v, t in info["opts"]), info["opts"])
                step("파일 고치기 — 이름표 「등급」(파일 목록 머리와 같은 말) " + tag, info["label"].strip() == "등급", info["label"])
            esc(pg)
            row.locator("button[aria-label^='공유']").click()
            pg.wait_for_timeout(300)
            if open_dialog_checks(pg, "share-dialog", "공유 링크", tag, narrow):
                blacks = pg.evaluate("() => [...document.querySelectorAll('#share-dialog button, #share-dialog a.btn')].filter(b => !b.hidden && !b.closest('[hidden]') && b.classList.contains('btn') && !b.matches('.btn--quiet,.btn--danger,.btn--plain,.btn--icon')).map(b => b.textContent.trim())")
                step("공유 창 — 주요(검정) 단추 하나(「링크 만들기」) " + tag, blacks == ["링크 만들기"], blacks)
            esc(pg)
            row.locator("button[aria-label='폴더로 이동']").click()
            pg.wait_for_timeout(500)
            if open_dialog_checks(pg, "move-dialog", "폴더로 이동", tag, narrow):
                order = pg.evaluate("() => [...document.querySelectorAll('#move-dialog .modal__foot button')].map(b => b.textContent.trim())")
                step("이동 창 — 순서 「닫기」 → 「여기로 이동」 " + tag, order == ["닫기", "여기로 이동"], order)
            esc(pg)
            row.locator("button[aria-label^='미리보기']").click()
            pg.wait_for_timeout(900)
            if open_dialog_checks(pg, "preview-dialog", "미리보기", tag, narrow):
                cl = pg.evaluate("() => { const b = document.getElementById('preview-close'); return b ? b.className : null; }")
                step("미리보기 — 「닫기」는 테두리 단추(검정 아님) " + tag, cl is not None and "btn--quiet" in cl, cl)
            esc(pg)
            row.locator("button[aria-label='지우기'], button[aria-label='삭제']").first.click()
            pg.wait_for_timeout(300)
            open_dialog_checks(pg, "confirm-dialog", "지우기 확인", tag, narrow)
            esc(pg)
            # 고르면 나오는 막대 → 등급·팀 바꾸기
            pg.locator("#files-body tr", has_text=names[1]).first.locator("input[type=checkbox]").check()
            pg.wait_for_timeout(250)
            words(pg, "선택 막대 " + tag)
            pg.click("#files-bulk-level")
            pg.wait_for_timeout(300)
            if open_dialog_checks(pg, "bulk-level-dialog", "등급·팀 바꾸기", tag, narrow):
                opts = pg.evaluate("() => [...document.querySelectorAll('#bulk-level-select option')].map(o => o.textContent.trim())")
                step("등급 고르개(일괄) 글자 한글 " + tag, bool(opts) and not any(re.match(r"(public|internal|team|admin-only)\b", o) for o in opts), opts)
            esc(pg)
            pg.click("#files-bulk-clear")
        guard("t-box 파일 창 " + tag, file_dialogs)

        def trash():
            go(pg, "/tbox/trash")
            no_overflow(pg, "t-box 내 휴지통 " + tag)
            words(pg, "t-box 내 휴지통 " + tag)
            n = pg.locator("#trash-body td.actions button[aria-label='되살리기']").count()
            step("내 휴지통 행 동작 「되살리기」 " + tag, n >= 1, n)
            if narrow:
                touch(pg, "t-box 내 휴지통 " + tag)
            shot(pg, "t-trash-" + tag)
        guard("t-box 내 휴지통 " + tag, trash)

        def settings():
            go(pg, "/tbox/settings")
            no_overflow(pg, "내 계정 " + tag)
            modal_rule(pg, "내 계정 화면의 창 전부 " + tag, need=2)
            words(pg, "내 계정 " + tag)
            if narrow:
                touch(pg, "내 계정 " + tag)
            shot(pg, "t-settings-" + tag)
            opener = pg.locator("#token-issue-open")
            if opener.count() and opener.is_visible():
                opener.click()
                pg.wait_for_timeout(300)
                open_dialog_checks(pg, "token-issue-dialog", "토큰 발급", tag, narrow)
                esc(pg)
        guard("내 계정 " + tag, settings)

        def app_menu_help():
            go(pg, "/tbox/")
            if narrow:
                pg.locator(".appbar button[aria-controls], .appbar button").first.click()
                pg.wait_for_timeout(300)
            pg.locator(".brand__switch").first.click()
            pg.wait_for_timeout(300)
            t = pg.evaluate("() => (document.querySelector('.app-menu') || {}).innerText || ''")
            step("앱 메뉴 계정 항목 「내 계정」(「내 계정 설정」 아님) " + tag, "내 계정" in t and "내 계정 설정" not in t, t[:120])
            if narrow:
                touch(pg, "앱 메뉴 " + tag, ".app-menu")
            shot(pg, "t-appmenu-" + tag)
            go(pg, "/tbox/")
            pg.locator("[data-help-open]").first.click()
            pg.wait_for_timeout(300)
            open_dialog_checks(pg, "help-dialog", "사용 방법(파일)", tag, narrow)
            esc(pg)
        if w in (360, 1280):
            guard("앱 메뉴·도움말 " + tag, app_menu_help)
        c.close()

        # ── 직원: 커뮤니티 홈 · 페이지 ─────────────────────────────
        c = ctx(br, S_E1, w, theme)
        pg = page_of(c)

        def chome():
            # 원래 어긋남(Q:F-12 C 에서 함께 고침): 「더보기」(#community-more)·휴지통·템플릿·선택하기가 있는 커뮤니티 홈은
            # 둘러보기가 `/community/` 를 차지한 뒤로 `/community/workspace`(업무 앱 「내 문서」)다.
            go(pg, "/community/workspace")
            no_overflow(pg, "커뮤니티 홈 " + tag)
            modal_rule(pg, "커뮤니티 홈의 창 전부 " + tag, need=5)
            words(pg, "커뮤니티 홈 " + tag)
            if narrow:
                touch(pg, "커뮤니티 홈 " + tag)
                pg.click("#nav-toggle")
                pg.wait_for_timeout(600)
                touch(pg, "커뮤니티 사이드바 트리(서랍) " + tag, "#sidebar")
                shot(pg, "c-sidebar-" + tag)
                pg.click("#nav-toggle")
                pg.wait_for_timeout(250)
                pg.click("#community-more > summary")
                pg.wait_for_timeout(250)
                touch(pg, "커뮤니티 더보기 메뉴 " + tag, "#community-more")
                shot(pg, "c-more-menu-" + tag)
                pg.click("#community-more > summary")
                pg.wait_for_timeout(200)
            shot(pg, "c-home-" + tag)
            pg.click("#community-more > summary")
            pg.wait_for_timeout(250)
            pg.click("#community-trash")
            pg.wait_for_timeout(900)
            open_dialog_checks(pg, "trash-dialog", "커뮤니티 휴지통", tag, narrow)
            esc(pg)
            pg.click("#community-more > summary")
            pg.wait_for_timeout(250)
            pg.click("#community-template-new")
            pg.wait_for_timeout(900)
            open_dialog_checks(pg, "template-dialog", "템플릿에서 만들기", tag, narrow)
            esc(pg)
            # 선택하기 → 한 줄 고르기 → 옮기기·지우기 창
            pg.click("#community-select")
            pg.wait_for_timeout(300)
            chk = pg.locator("#community-tree input[type=checkbox][data-bulk]").first
            if chk.count():
                chk.check()
                pg.wait_for_timeout(250)
                pg.click("#community-bulk-move")
                pg.wait_for_timeout(600)
                open_dialog_checks(pg, "bulk-move-dialog", "여러 페이지 옮기기", tag, narrow)
                esc(pg)
                pg.locator("#community-bulk-trash").click()
                pg.wait_for_timeout(300)
                open_dialog_checks(pg, "bulk-delete-dialog", "여러 페이지 지우기", tag, narrow)
                esc(pg)
            pg.keyboard.press("Escape")
        guard("커뮤니티 홈 " + tag, chome)

        def cpage():
            if not PID:
                return
            go(pg, "/community/p/" + PID)
            no_overflow(pg, "커뮤니티 페이지 " + tag)
            modal_rule(pg, "커뮤니티 페이지의 창 전부 " + tag, need=10)
            words(pg, "커뮤니티 페이지 " + tag)
            if narrow:
                touch(pg, "커뮤니티 페이지 " + tag)
            shot(pg, "c-page-" + tag)
            for opener, did, what in (("#page-delete", "delete-dialog", "페이지 지우기"), ("#page-move", "move-dialog", "페이지 옮기기")):
                pg.click("#page-more > summary")
                pg.wait_for_timeout(250)
                pg.click(opener)
                pg.wait_for_timeout(600)
                open_dialog_checks(pg, did, what, tag, narrow)
                esc(pg)
            # 디버깅 묶음 U3 — 폰 폭(720 미만)은 속성 줄이 접혀 있다(요약 한 줄 + 「속성 더보기」) — 펼친 뒤 누른다(넓은 폭은 단추가 없어 그대로)
            if pg.evaluate("() => { const t = document.getElementById('page-props-toggle'); return !!t && t.getClientRects().length > 0 && t.getAttribute('aria-expanded') !== 'true'; }"):
                pg.click("#page-props-toggle")
                pg.wait_for_timeout(200)
            pg.click("#page-status")
            pg.wait_for_timeout(300)
            open_dialog_checks(pg, "status-dialog", "상태 바꾸기", tag, narrow)
            esc(pg)
            pg.click("#page-visibility-edit")
            pg.wait_for_timeout(500)
            open_dialog_checks(pg, "visibility-dialog", "공개 범위", tag, narrow)
            esc(pg)
        guard("커뮤니티 페이지 " + tag, cpage)

        def db_l2(ctxname, pgx, expect_visible):
            if not (GUEST_PAGE or PID):
                return
            target = GUEST_PAGE if ctxname == "손님" else PID
            fake_db = {"id": 987654321, "schema": [], "can_add_rows": True, "can_manage_schema": True}

            def page_route(route):
                resp = route.fetch()
                try:
                    data = resp.json()
                except Exception:
                    return route.fulfill(response=resp)
                if isinstance(data, dict) and isinstance(data.get("page"), dict):
                    data["database"] = fake_db
                return route.fulfill(response=resp, json=data)

            def rows_route(route):
                route.fulfill(status=200, json={"database": {"id": 987654321, "schema": []}, "rows": [], "total": 0,
                                                "can_add_rows": True, "can_edit_values": True, "can_manage_schema": True, "views": []})
            pgx.route("**/community/api/pages/" + target, page_route)
            pgx.route("**/community/api/pages/" + target + "?*", page_route)
            pgx.route("**/community/api/databases/987654321/**", rows_route)
            # 가짜 데이터베이스의 행 템플릿 목록(templates.js) — 서버에 없는 id 라 400 이 된다. 빈 목록으로
            pgx.route("**/community/api/templates?database=987654321*", lambda r: r.fulfill(status=200, json={"templates": []}))
            go(pgx, "/community/p/" + target)
            pgx.wait_for_timeout(600)
            st = pgx.evaluate("""() => ['db-wrap', 'db-add-row', 'db-add-prop'].map(i => { const e = document.getElementById(i); return [i, !!(e && !e.hidden && e.getClientRects().length)]; })""")
            pgx.unroute("**/community/api/pages/" + target)
            pgx.unroute("**/community/api/pages/" + target + "?*")
            pgx.unroute("**/community/api/databases/987654321/**")
            pgx.unroute("**/community/api/templates?database=987654321*")
            vis = dict(st)
            if expect_visible:
                step("L-2 직원 — 데이터베이스 「행 추가」·「속성 더하기」 그대로 보임 " + tag, vis.get("db-wrap") and vis.get("db-add-row") and vis.get("db-add-prop"), st)
            else:
                step("L-2 손님 — 창구 값이 참이어도 「행 추가」·「속성 더하기」 숨김 " + tag, vis.get("db-wrap") and not vis.get("db-add-row") and not vis.get("db-add-prop"), st)
            shot(pgx, "c-db-l2-%s-%s" % ("guest" if not expect_visible else "employee", tag))
        if (w, theme) in ((360, "light"), (1280, "dark")):
            guard("L-2 직원 " + tag, lambda: db_l2("직원", pg, True))
        c.close()

        if (w, theme) in ((360, "light"), (1280, "dark")):
            c = ctx(br, S_G1, w, theme)
            pg = page_of(c)
            guard("L-2 손님 " + tag, lambda: db_l2("손님", pg, False))
            c.close()

        # ── 관리자: 계정 관리(「관리」 하나 + 상세 창) · 조직 · 감사 · 관리자 페이지 ─────
        c = ctx(br, S_A1, w, theme)
        pg = page_of(c)

        def users():
            go(pg, "/tbox/users")
            no_overflow(pg, "계정 관리 " + tag)
            modal_rule(pg, "계정 관리 화면의 창 전부 " + tag, need=3)
            words(pg, "계정 관리 " + tag)
            rows = pg.evaluate("""() => [...document.querySelectorAll('#users-body tr')].slice(0, 8).map(tr => { const td = tr.querySelector('td.actions');
                return td ? [...td.querySelectorAll('button')].map(b => b.textContent.trim()) : null; })""")
            step("M8 계정 행 동작 = 「관리」 단추 하나 " + tag, bool(rows) and all(r == ["관리"] for r in rows), rows[:3])
            stacked = pg.evaluate("() => { const t = document.querySelector('table.files:has(#users-body)'); return t ? getComputedStyle(t).display : null; }")
            step("M8 계정 표 쌓기는 720 미만만 (%s) " % ("쌓임" if narrow else "표") + tag, stacked == ("block" if narrow else "table"), stacked)
            in_view = pg.evaluate("""() => { const W = document.documentElement.clientWidth; return [...document.querySelectorAll('#users-body td.actions button')].slice(0, 8).every(b => { const r = b.getBoundingClientRect(); return r.right <= W + 1 && r.left >= -1; }); }""")
            step("M8 「관리」 단추가 화면 안 " + tag, in_view)
            if narrow:
                touch(pg, "계정 관리 " + tag)
            opts = pg.evaluate("() => [...document.querySelectorAll('#u-role option, #ue-role option')].map(o => [o.value, o.textContent.trim()])")
            ROLE_KO = {"Admin": "관리자", "Manager": "팀장", "Employee": "직원", "Guest": "손님"}
            step("역할 고르개 글자 한글만 · 값↔글자 짝 그대로 " + tag, bool(opts) and all(ROLE_KO.get(v) == t for v, t in opts), opts)
            shot(pg, "a-users-" + tag)
            row = pg.locator("#users-body tr", has_text="claude_employee01").first
            row.scroll_into_view_if_needed()
            row.locator("td.actions button").first.click()
            pg.wait_for_timeout(400)
            if not open_dialog_checks(pg, "user-manage-dialog", "계정 관리 상세", tag, narrow):
                return
            acts = pg.evaluate("() => [...document.querySelectorAll('#user-manage-dialog [data-user-action]')].filter(b => !b.hidden).map(b => b.getAttribute('data-user-action'))")
            # d74 (2026-09-27) — 「로그인 아이디 바꾸기」(username)가 더해져 여섯이다(화면 시험은 tests/browser/d74_username.py)
            step("M8 상세 창의 동작 여섯(바꾸기·아이디·임시 비밀번호·2단계 초기화·프로그램 토큰·활성) " + tag,
                 sorted(acts) == sorted(["edit", "username", "password", "mfa", "tokens", "active"]), acts)
            facts = pg.evaluate("() => (document.getElementById('user-manage-facts') || {}).innerText || ''")
            step("M8 상세 창에 계정 사실(역할 한글·팀·2단계) " + tag, "직원" in facts and "2단계" in facts, facts[:120])
            # 프로그램 토큰 → 상세 창을 닫고 토큰 창(겹쳐 열지 않음)
            pg.click("#user-manage-dialog [data-user-action='tokens']")
            pg.wait_for_timeout(900)
            st = pg.evaluate("() => [!!document.querySelector('#user-manage-dialog[open]'), !!document.querySelector('#token-dialog[open]'), document.querySelectorAll('dialog[open]').length]")
            step("M8 「프로그램 토큰」 → 상세 창 닫고 토큰 창 하나만 열림 " + tag, st == [False, True, 1], st)
            open_dialog_checks(pg, "token-dialog", "프로그램 토큰", tag, narrow)
            esc(pg)
            f = pg.evaluate("() => document.activeElement && document.activeElement.textContent.trim()")
            step("M8 토큰 창을 닫으면 초점이 그 줄 「관리」로 " + tag, f == "관리", f)
            # 이름·역할·팀·만료 → 계정 변경 창
            row.locator("td.actions button").first.click()
            pg.wait_for_timeout(300)
            pg.click("#user-manage-dialog [data-user-action='edit']")
            pg.wait_for_timeout(400)
            st = pg.evaluate("() => document.querySelectorAll('dialog[open]').length")
            step("M8 「바꾸기」 → 창 하나(계정 변경) " + tag, st == 1, st)
            open_dialog_checks(pg, "user-edit", "계정 변경", tag, narrow)
            pg.click("#ue-cancel")
            pg.wait_for_timeout(250)
            # 임시 비밀번호 재발급 → 사유 창 → 닫기(요청 0)
            sent = []
            pg.on("request", lambda r: sent.append(r.url) if r.method == "POST" and "/password/reset" in r.url else None)
            row.locator("td.actions button").first.click()
            pg.wait_for_timeout(300)
            pg.click("#user-manage-dialog [data-user-action='password']")
            pg.wait_for_timeout(300)
            open_dialog_checks(pg, "reason-dialog", "사유", tag, narrow)
            lbl = pg.evaluate("() => (document.getElementById('rd-submit') || {}).textContent || ''").strip()
            step("M8 사유 창 바닥 단추는 동작 이름(「재발급하기」 — 「확인」 아님) " + tag, lbl == "재발급하기", lbl)
            pg.click("#rd-cancel")
            pg.wait_for_timeout(250)
            step("M8 사유 창 「닫기」 → 요청 0 " + tag, not sent, sent)
            if (w, theme) in ((360, "light"), (1280, "light")) and not BEFORE:
                # 임시 비밀번호 재발급 — 결과 상자가 **보이는 창**(그 계정의 「관리」 창)에 나온다(전엔 닫힌 「계정 만들기」 창 안에 들어가 보이지 않았다)
                race = (w, theme) == (1280, "light")
                held = []
                if race:
                    # 독립 검토 낮음 — 답을 붙잡아 두고 그사이 **다른 계정**(claude_employee01)의 「관리」 창을 연다
                    pg.route("**/tbox/api/users/*/password/reset", lambda r: held.append(r))
                trow = pg.locator("#users-body tr", has_text=TEMP_USER).first
                trow.scroll_into_view_if_needed()
                trow.locator("td.actions button").first.click()
                pg.wait_for_timeout(300)
                pg.click("#user-manage-dialog [data-user-action='password']")
                pg.wait_for_timeout(300)
                pg.fill("#rd-reason", "디버깅 묶음 U2 재발급 화면 시험")
                pg.click("#rd-submit")
                if race:
                    pg.wait_for_timeout(500)
                    erow = pg.locator("#users-body tr", has_text="claude_employee01").first
                    erow.locator("td.actions button").first.click()
                    pg.wait_for_timeout(300)
                    other = pg.evaluate("() => (document.getElementById('um-sub') || {}).textContent || ''")
                    for r0 in held:
                        r0.continue_()
                    pg.wait_for_timeout(1800)
                    pg.unroute("**/tbox/api/users/*/password/reset")
                    sub = pg.evaluate("() => (document.getElementById('um-sub') || {}).textContent || ''")
                    step("M8 재발급 답을 기다리는 동안 다른 계정 창을 열어도 상자는 **그 계정**(재발급한 계정) 창에 " + tag,
                         "claude_employee01" in other and TEMP_USER in sub and "claude_employee01" not in sub, (other, sub))
                else:
                    pg.wait_for_timeout(1500)
                box = pg.evaluate("""() => { const b = document.getElementById('temp-password-box'); if (!b) return null; const f = b.querySelector('input');
                    return {inManage: !!b.closest('#user-manage-dialog[open]'), visible: b.getClientRects().length > 0, filled: !!(f && f.value.length >= 8)}; }""")
                step("M8 재발급 → 임시 비밀번호 상자가 그 계정의 「관리」 창 안에 보인다 " + tag, box == {"inManage": True, "visible": True, "filled": True}, box)
                shot(pg, "a-temp-password-" + tag)
                pg.locator("#temp-password-box button", has_text="닫기").click()
                pg.wait_for_timeout(300)
                st = pg.evaluate("() => [!!document.querySelector('#user-manage-dialog[open]'), !!document.getElementById('temp-password-box')]")
                step("M8 상자의 「닫기」 → 상자와 창이 함께 닫힘(값 지움) " + tag, st == [False, False], st)
            if (w, theme) == (1280, "light") and not BEFORE:
                # 독립 검토 낮음(기존 결함 · R-39 「한 번만」) — 계정 만들기 뒤 상자를 Esc 로 닫으면 다음에 열 때 지난 임시 비밀번호가 보였다
                newu = "dbgu2c_" + TAG
                pg.click("#user-create-open")
                pg.wait_for_timeout(300)
                pg.fill("#u-username", newu)
                pg.select_option("#u-role", "Employee")
                pg.fill("#u-reason", "디버깅 묶음 U2 만들기 창 시험")
                pg.click("#u-submit")
                pg.wait_for_timeout(1500)
                shown = pg.evaluate("() => { const b = document.getElementById('temp-password-box'); const f = b && b.querySelector('input'); return !!(b && b.closest('#user-create-dialog[open]') && f && f.value.length >= 8); }")
                step("계정 만들기 → 임시 비밀번호 상자(한 번) " + tag, shown, shown)
                esc(pg)
                pg.click("#user-create-open")
                pg.wait_for_timeout(300)
                left = pg.evaluate("() => { const b = document.getElementById('temp-password-box'); return [!!b, [...document.querySelectorAll('#user-create-dialog input.temp-password')].map(f => f.value).join('')]; }")
                step("계정 만들기 창을 Esc 로 닫고 다시 열면 지난 임시 비밀번호 0(상자·값 지움 — R-39) " + tag, left == [False, ""], left)
                esc(pg)
        guard("계정 관리 " + tag, users)

        def admin_pages():
            for path, what, need in (("/tbox/org", "조직", 4), ("/tbox/admin", "관리자 페이지", 0), ("/tbox/audit", "감사 로그", 0),
                                     ("/tbox/admin/permissions", "권한 매트릭스", 1), ("/tbox/trash?scope=all", "전체 휴지통", 0)):
                go(pg, path)
                no_overflow(pg, what + " " + tag)
                if need:
                    modal_rule(pg, "%s 화면의 창 전부 %s" % (what, tag), need=need)
                words(pg, what + " " + tag)
                if narrow:
                    touch(pg, what + " " + tag)
                shot(pg, "a-%s-%s" % (path.strip("/").replace("/", "_").replace("?", "_").replace("=", "_"), tag))
            go(pg, "/tbox/audit")
            opts = pg.evaluate("() => [...document.querySelectorAll('select option')].filter(o => ['public','internal','team','admin-only'].includes(o.value)).map(o => o.textContent.trim())")
            step("감사 로그 등급 거르기 글자 한글 " + tag, opts == ["공개", "사내", "팀", "관리자"], opts)
        if w in (360, 1280) or theme == "dark":
            guard("관리 화면 " + tag, admin_pages)

        def reauth_esc():
            global reauth_done
            # 재인증 관문을 흉내 — 파일 고치기의 첫 PATCH 를 403 next:reauth 로
            state = {"n": 0}

            def gate(route):
                if route.request.method != "PATCH":
                    return route.continue_()
                state["n"] += 1
                if state["n"] <= 2:
                    return route.fulfill(status=403, json={"error": "관리 기능을 쓰려면 비밀번호를 다시 확인하세요", "next": "reauth"})
                return route.continue_()
            pg.route("**/tbox/api/files/*", gate)
            go(pg, "/tbox/")
            row = pg.locator("#files-body tr", has_text=names[1]).first
            row.locator("button[aria-label='수정']").click()
            pg.wait_for_timeout(300)
            n_err = len(errors)
            pg.fill("#file-edit-description", "DBGU2 재인증 %s %s" % (TAG, tag))   # 조합마다 다른 값 — 같은 값이면 「바뀐 내용이 없습니다」로 요청이 안 나간다
            pg.click("#file-edit-submit")
            pg.wait_for_timeout(700)
            r1 = pg.evaluate("() => { const f = document.getElementById('reauth-dialog-form'); return !!(f && f.closest('dialog[open]')); }")
            step("재인증 — 403 next:reauth → 공용 재인증 창이 뜬다 " + tag, r1)
            shot(pg, "t-reauth-open-" + tag)
            esc(pg)
            st = pg.evaluate("""() => ({reauthInDom: !!document.getElementById('reauth-dialog-form'), edit: !!document.querySelector('#file-edit-dialog[open]'),
                msg: (document.getElementById('file-edit-msg') || {}).textContent || '', submit: !(document.getElementById('file-edit-submit') || {}).disabled})""")
            step("재인증 Esc → 재인증 창이 DOM 에서 빠짐 · 수정 창은 열린 채 · 이유 한 줄 · 저장 단추 살아 있음 " + tag,
                 not st["reauthInDom"] and st["edit"] and st["msg"].strip() != "" and st["submit"], st)
            shot(pg, "t-reauth-after-esc-" + tag)
            pg.click("#file-edit-submit")
            pg.wait_for_timeout(700)
            r2 = pg.evaluate("() => { const f = document.getElementById('reauth-dialog-form'); return !!(f && f.closest('dialog[open]')); }")
            step("재인증 Esc 뒤 다시 저장 → 재인증 창이 **다시** 뜬다(새로고침 없이) " + tag, r2)
            if r2:
                pg.fill("#reauth-dialog-form input[type=password]", PW_A1)
                pg.locator("#reauth-dialog-form").evaluate("f => f.requestSubmit()")
                pg.wait_for_timeout(1800)
                closed = not pg.evaluate("() => !!document.querySelector('#file-edit-dialog[open]')")
                step("재인증 통과 → 같은 값을 다시 보내 수정 창 닫힘 " + tag, closed and state["n"] == 3, (closed, state["n"]))
            pg.unroute("**/tbox/api/files/*")
            errors[n_err:] = [e for e in errors[n_err:] if "status of 403" not in e]
            reauth_done = True
        if (w, theme) in ((360, "light"), (1280, "light"), (412, "dark")) and not BEFORE:
            guard("재인증 Esc " + tag, reauth_esc)
        c.close()
    br.close()

print("\n── 공통")
step("브라우저 기본 창(alert·prompt·confirm) 0건", not dialogs, dialogs[:5])
step("콘솔 오류·pageerror 0건", not errors, errors[:6])
print("\n통과 %d · 실패 %d" % (passes, fails))
print("SMOKE_FAIL=%d" % fails)
sys.exit(0 if BEFORE else (1 if fails else 0))
