#!/usr/bin/env python3
"""화면 스크린샷 + DOM 시각 결함 점검 (Playwright). ui_snapshot.sh 가 부른다.
결함 종류: dup_id(id 중복) · stage_text(화면 문구의 「N단계」) · overflow_x(페이지 가로 넘침) · clipped(텍스트가 상자에서 잘림) · overlap(형제 카드 겹침)
          · row_height(같은 줄의 버튼·입력 높이 불일치) · offscreen(뷰포트 오른쪽 밖으로 나간 요소)
          · undefined_class(어느 스타일시트에도 규칙이 없는 class)
"""
import json, sys, time
from playwright.sync_api import sync_playwright

BASE, UA, COOKIES, OUT, WIDTH = sys.argv[1], sys.argv[2], sys.argv[3], sys.argv[4], int(sys.argv[5])
import os
PAGES = os.environ["UI_PAGES"].split(",") if os.environ.get("UI_PAGES") else ["/", "/settings", "/activity", "/admin", "/users",
         "/org", "/admin/permissions", "/audit", "/trash"]

AUDIT_JS = r"""
() => {
  const out = {overflow_x: document.documentElement.scrollWidth > window.innerWidth,
               clipped: [], overlap: [], row_height: [], offscreen: [], undefined_class: [], dup_id: [], stage_text: []};
  // 0) id 중복 · 화면 문구에 남은 개발 단계 번호(「21단계」 등)
  { const ids = {}; for (const el of document.querySelectorAll('[id]')) ids[el.id] = (ids[el.id] || 0) + 1;
    for (const k in ids) if (ids[k] > 1) out.dup_id.push(k + '×' + ids[k]);
    const m = (document.querySelector('main') || document.body).innerText.match(/\d{1,2}단계/g);
    if (m) out.stage_text = [...new Set(m)]; }
  const vis = el => { const r = el.getBoundingClientRect(); const cs = getComputedStyle(el);
    return r.width > 0 && r.height > 0 && cs.visibility !== 'hidden' && cs.display !== 'none'; };
  const desc = el => (el.tagName.toLowerCase() + (el.id ? '#' + el.id : '') +
    (el.className && typeof el.className === 'string' ? '.' + el.className.trim().split(/\s+/).slice(0,3).join('.') : '') +
    ' «' + (el.textContent || '').trim().replace(/\s+/g,' ').slice(0, 40) + '»');
  // 1) 텍스트 잘림: overflow hidden/clip 이고 내용이 상자보다 큰 요소, 또는 nowrap 인데 넘치는 요소
  for (const el of document.querySelectorAll('body *')) {
    if (!vis(el)) continue;
    const cs = getComputedStyle(el);
    const hidden = /hidden|clip/.test(cs.overflowX) || /hidden|clip/.test(cs.overflowY) || cs.textOverflow === 'ellipsis';
    const over = (el.scrollWidth - el.clientWidth > 2) || (el.scrollHeight - el.clientHeight > 2);
    if (hidden && over && !el.matches('.table-wrap, .table-wrap *, select, textarea, [data-allow-scroll]')) {
      if (el.tagName === 'SELECT' || el.tagName === 'INPUT') continue;
      out.clipped.push(desc(el));
    }
    const r = el.getBoundingClientRect();
    if (r.right > window.innerWidth + 1 && !el.closest('.table-wrap')) out.offscreen.push(desc(el));
  }
  // 2) 형제 카드/패널 겹침
  const cards = [...document.querySelectorAll('.panel, .card, .p-kpi, .kpi, dialog[open]')].filter(vis);
  for (let i = 0; i < cards.length; i++) for (let j = i + 1; j < cards.length; j++) {
    if (cards[i].contains(cards[j]) || cards[j].contains(cards[i])) continue;
    const a = cards[i].getBoundingClientRect(), b = cards[j].getBoundingClientRect();
    const ix = Math.min(a.right, b.right) - Math.max(a.left, b.left), iy = Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top);
    if (ix > 4 && iy > 4) out.overlap.push(desc(cards[i]) + ' ∩ ' + desc(cards[j]));
  }
  // 3) 같은 줄(flex/grid 행)의 컨트롤 높이 불일치
  for (const row of document.querySelectorAll('body *')) {
    if (!vis(row)) continue;
    const cs = getComputedStyle(row); if (!/flex|grid/.test(cs.display)) continue;
    const kids = [...row.children].filter(k => vis(k) && k.matches('button, .btn, input, select, .input, a.btn, .chip, label.check'));
    if (kids.length < 2) continue;
    const tops = kids.map(k => Math.round(k.getBoundingClientRect().top));
    const line = tops[0]; const same = kids.filter((k, i) => Math.abs(tops[i] - line) < 6);
    if (same.length < 2) continue;
    const hs = same.map(k => Math.round(k.getBoundingClientRect().height));
    if (Math.max(...hs) - Math.min(...hs) > 4) out.row_height.push(desc(row) + ' → ' + hs.join('/'));
  }
  // 4) 규칙 없는 class
  const rules = new Set();
  for (const ss of document.styleSheets) { let rs; try { rs = ss.cssRules; } catch (e) { continue; }
    for (const r of rs) { if (r.selectorText) for (const m of r.selectorText.matchAll(/\.([a-zA-Z0-9_-]+)/g)) rules.add(m[1]);
      if (r.cssRules) for (const rr of r.cssRules) if (rr.selectorText) for (const m of rr.selectorText.matchAll(/\.([a-zA-Z0-9_-]+)/g)) rules.add(m[1]); } }
  const seen = new Set();
  for (const el of document.querySelectorAll('[class]')) for (const c of el.classList)
    if (!rules.has(c) && !seen.has(c) && !/^(is-|has-|js-|sr-only|icon|active)/.test(c)) { seen.add(c); out.undefined_class.push(c); }
  return out;
}
"""

def main():
    report = {}
    with sync_playwright() as p:
        browser = p.chromium.launch(channel='chromium')  # headless_shell 대신 정식 빌드(디스크 절약: shell 미설치)
        ctx = browser.new_context(viewport={"width": WIDTH, "height": 900}, user_agent=UA, locale="ko-KR")
        cookies = []
        for kv in COOKIES.split(";"):
            if "=" in kv:
                n, v = kv.split("=", 1); cookies.append({"name": n, "value": v, "url": BASE})
        ctx.add_cookies(cookies)
        page = ctx.new_page()
        console = []
        page.on("console", lambda m: console.append(m.text[:160]) if m.type in ("error",) else None)
        page.on("pageerror", lambda e: console.append("pageerror: " + str(e)[:160]))
        for path in PAGES:
            console.clear()
            slug = path.strip("/").replace("/", "_") or "home"
            page.goto(BASE + path, wait_until="networkidle")
            time.sleep(0.4)
            shots = {}
            page.screenshot(path=f"{OUT}/{slug}.png", full_page=True)
            shots["main"] = page.evaluate(AUDIT_JS)
            shots["main"]["final_url"] = page.url
            shots["main"]["console_errors"] = list(console)
            # 탭이 있으면 탭마다
            tabs = page.query_selector_all(".p-tab, .tabs .tab, [role=tab]")
            for i, t in enumerate(tabs):
                if i == 0: continue
                try:
                    t.click(); time.sleep(0.4)
                    label = (t.inner_text() or f"tab{i}").strip().replace("/", "_")[:12]
                    page.screenshot(path=f"{OUT}/{slug}__{i}_{label}.png", full_page=True)
                    shots[f"tab:{label}"] = page.evaluate(AUDIT_JS)
                except Exception as e:
                    shots[f"tab{i}"] = {"error": str(e)[:120]}
            report[path] = shots
        browser.close()
    with open(f"{OUT}/report.json", "w") as f:
        json.dump(report, f, ensure_ascii=False, indent=1)
    # 요약
    tot = 0
    for path, shots in report.items():
        for name, a in shots.items():
            if "error" in a: print(f"{path} [{name}] ERROR {a['error']}"); continue
            n = sum(len(a[k]) for k in ("clipped","overlap","row_height","offscreen","undefined_class","dup_id","stage_text")) + len(a.get("console_errors", [])) + (1 if a["overflow_x"] else 0)
            tot += n
            print(f"{path} [{name}] {n}건  overflow_x={a['overflow_x']} clipped={len(a['clipped'])} overlap={len(a['overlap'])} "
                  f"row_height={len(a['row_height'])} offscreen={len(a['offscreen'])} undefined_class={len(a['undefined_class'])} dup_id={len(a['dup_id'])} stage_text={len(a['stage_text'])} console={len(a.get('console_errors', []))}"
                  + ("" if a.get('final_url', path).endswith(path) else f"  → {a['final_url']}"))
    print(f"합계 {tot}건")

main()
