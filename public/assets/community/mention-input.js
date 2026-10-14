/*
 * public/assets/community/mention-input.js — 댓글 칸의 `@` 멘션 입력 부품 **한 벌** (사이트 Q:F-12 U4-4)
 *
 * 요구사항: 기획 `../../../../community/work-ux-plan.md` 16.4 「U4-4 댓글 멘션」 · 16.5, DESIGN.md 2.27 「멘션 입력 — 댓글」 · 2.15 · 6장.
 * 쓰는 곳: 전체 화면 댓글(editor.js — `#comment-input`) · 작업 칸 댓글(work.js — `.task-pane__foot textarea`) **둘 다** 이 함수 하나(두 벌 금지).
 * 창구: GET /api/mention-candidates?page=&q= (빈 q = 「최근 이 문서에 쓴 사람 → 아이디 순」 · 그 문서를 볼 수 있는 쓰기 자격 사람만 · 최대 10).
 *
 * 하는 일
 *   ① `@` 를 치는 순간(또는 캐럿이 `@토큰` 안에 들어오면 — 누르기 · 화살표) 입력 칸 바로 아래 떠 있는 후보 목록(`.menu__list` · role=listbox ·
 *      최대 10줄 — 아바타 + 이름 + 아이디). `@` 뒤 글자로 좁힌다 · ↑↓ · Enter/Tab 고르기 · Esc 닫기 · 고르면 그 토큰을 `@아이디 ` 로 바꿔 넣는다.
 *   ② 확인 색 — 입력 칸 뒤 그림자 층(`.mention-backdrop` — 같은 글꼴 · 여백 · 줄바꿈 · 글자 투명)에 `@아이디` 마다 바탕색:
 *      후보 창구가 **정확히 그 아이디**를 돌려준 사람 = `.mention-mark--ok`, 못 찾음 = `.mention-mark--miss`. 칸 아래 `.help` 한 줄이 글자로도 말한다
 *      (「부를 사람: 이름(아이디) · 찾을 수 없음: @abc」 — 색만으로 가르지 않는다).
 *   ③ 보내기 옆 안내(`notice` — 부르는 쪽이 보내기 단추 옆에 둔다): 못 찾은 토큰이 있으면 「찾을 수 없는 사람 n명 — 그 이름은 알림이 가지 않아요」.
 *      보내기는 막지 않는다 — 알림은 지금처럼 서버가 정확한 아이디만 보낸다(comments_create.php 무변경).
 *
 * 규칙
 *   · **화면은 판단하지 않는다** — 후보 · 확인은 창구가 준 답뿐(「그 문서를 볼 수 있는 사람」은 서버 `decideMentionTarget`). 화면이 사람을 거르거나 더하지 않는다.
 *   · 요소는 createElement, 글자는 textContent · createTextNode 로만(HTML 문자열 0). 인라인 style 0 — 두 층의 맞춤은 CSS(`.mention-field`)가 한다.
 *   · 토큰 모양은 서버 `community_mentions_in` 과 같다: 앞 글자가 `[a-z0-9_.@-]` 가 아닌 `@` + `[a-z0-9_.-]{3,64}`(끝 마침표는 뗀다).
 *   · 카페 C2(DESIGN 2.29 「익명 표시」) — 끄기 옵션 하나 `disabled`(참/거짓 또는 그 값을 돌려주는 함수 — 페이지를 받은 뒤 정해지므로 함수로 넘긴다).
 *     켜져 있으면 그냥 입력 칸이다: 후보 목록 · 확인 색 · 「부를 사람」 줄 · 후보 창구 요청 0, 칸 아래 한 줄 「익명게시판에서는 @ 부르기를 쓸 수 없어요」.
 *     서버도 익명게시판 글에서는 멘션을 해석하지 않는다(comments_create — 알림 0 · mention_candidates 403) — 화면은 그 사실을 미리 말할 뿐이다.
 */

const TOKEN_RE = /(^|[^a-z0-9_.@-])@([a-z0-9_.-]{1,64})/g;
const NAME_RE = /^[a-z0-9_.-]{3,64}$/;
const EMPTY_TEXT = '부를 수 있는 사람이 없어요 — 이 문서를 볼 수 있는 사람만 부를 수 있어요';
const OFF_TEXT = '익명게시판에서는 @ 부르기를 쓸 수 없어요';
let seqId = 0;

function el(tag, cls, text) {
  const n = document.createElement(tag);
  if (cls) { n.className = cls; }
  if (text !== undefined && text !== null) { n.textContent = String(text); }
  return n;
}

function initialOf(name) {
  const t = String(name || '').trim();
  const c = t === '' ? '?' : Array.from(t)[0];
  return /^[a-z]$/.test(c) ? c.toUpperCase() : c;
}

/** 칸 글자의 `@토큰` 들 — [{ at(‘@’ 자리), end(토큰 끝 — 끝 마침표 뺌), name }]. 서버 규칙과 같은 모양(3자 미만은 name 이 짧은 채로 — 확인 대상 아님) */
export function mentionTokens(text) {
  const out = [];
  const s = String(text || '');
  for (const m of s.matchAll(TOKEN_RE)) {
    const at = m.index + m[1].length;
    let name = m[2];
    while (name.endsWith('.')) { name = name.slice(0, -1); }
    out.push({ at: at, end: at + 1 + name.length, raw: at + 1 + m[2].length, name: name });
  }
  return out;
}

/**
 * @param {HTMLTextAreaElement} textarea
 * @param {{pageId: string|Function, api: Function, personLabel?: Function, listId?: string, disabled?: boolean|Function}} opts
 * @return {{notice: HTMLElement, refresh: Function, reset: Function, close: Function, missing: Function}}
 */
export function createMentionInput(textarea, opts) {
  const api = opts.api;
  const pageId = () => (typeof opts.pageId === 'function' ? opts.pageId() : opts.pageId);
  const label = typeof opts.personLabel === 'function'
    ? opts.personLabel
    : (display, username) => (display ? display + '(' + username + ')' : username);
  const uid = 'mention-' + String(++seqId);
  /** 카페 C2 — 끔(익명게시판). 함수면 부를 때마다 묻는다 */
  const off = () => (typeof opts.disabled === 'function' ? opts.disabled() === true : opts.disabled === true);

  /* 틀 — 입력 칸 자리에 `.mention-field`(그림자 층 · 입력 칸 · 떠 있는 목록)를 세운다. 입력 칸은 그대로 옮긴다(id · 이름표 · 듣는 것 그대로) */
  const field = el('div', 'mention-field');
  const backdrop = el('div', 'mention-backdrop');
  backdrop.setAttribute('aria-hidden', 'true');
  const list = el('div', 'menu__list');
  list.id = opts.listId || uid + '-list';
  list.setAttribute('role', 'listbox');
  list.setAttribute('aria-label', '부를 사람 후보');
  list.hidden = true;
  textarea.parentNode.insertBefore(field, textarea);
  field.append(backdrop, textarea, list);
  const help = el('p', 'help');
  help.setAttribute('aria-live', 'polite');
  help.hidden = true;
  field.after(help);
  const notice = el('p', 'help');
  notice.setAttribute('aria-live', 'polite');
  notice.hidden = true;
  // 맞춤법 물결 밑줄(빨강)이 「찾을 수 없음」 표시와 겹쳐 보이지 않게 — 전체 화면 댓글 칸과 같은 값
  textarea.spellcheck = false;
  textarea.setAttribute('aria-autocomplete', 'list');
  textarea.setAttribute('aria-controls', list.id);

  /* 확인 — 아이디 → { state: 'ok'|'miss'|'wait', who } (창구 답 그대로 · 화면 수명 동안) */
  const known = new Map();
  let checkTimer = null;
  let listSeq = 0;
  let listTimer = null;
  let open = null;          // { at, end, q, people, active }
  let dismissedAt = -1;     // Esc 로 닫은 토큰의 `@` 자리 — 그 토큰을 떠나거나 글자가 바뀌면 다시 연다

  function candidates(q) {
    return api('GET', '/api/mention-candidates?page=' + encodeURIComponent(pageId()) + '&q=' + encodeURIComponent(q))
      .then((r) => (r.status === 200 && r.data && Array.isArray(r.data.candidates) ? r.data.candidates : null));
  }

  function remember(p) {
    if (p && typeof p.username === 'string') { known.set(p.username, { state: 'ok', who: p }); }
  }

  /* ── 그림자 층 · 아래 줄 ── */
  function paint() {
    const text = textarea.value;
    while (backdrop.firstChild) { backdrop.removeChild(backdrop.firstChild); }
    if (off()) {
      // 끔 — 확인 색 0 · 안내 한 줄만(색 · 후보 없이 그냥 입력 칸)
      backdrop.appendChild(document.createTextNode(text + '​'));
      help.textContent = OFF_TEXT;
      help.hidden = false;
      notice.textContent = '';
      notice.hidden = true;
      return;
    }
    let pos = 0;
    const ok = [];
    const miss = [];
    for (const t of mentionTokens(text)) {
      if (!NAME_RE.test(t.name)) { continue; }
      const k = known.get(t.name);
      if (!k || k.state === 'wait') { continue; }
      if (t.at > pos) { backdrop.appendChild(document.createTextNode(text.slice(pos, t.at))); }
      const mark = el('span', k.state === 'ok' ? 'mention-mark--ok' : 'mention-mark--miss', text.slice(t.at, t.end));
      backdrop.appendChild(mark);
      pos = t.end;
      const list_ = k.state === 'ok' ? ok : miss;
      const word = k.state === 'ok' ? label(k.who.display_name || null, k.who.username) : '@' + t.name;
      if (list_.indexOf(word) === -1) { list_.push(word); }
    }
    // 끝 줄바꿈도 같은 높이로 재게 보이지 않는 글자 하나
    backdrop.appendChild(document.createTextNode(text.slice(pos) + '​'));
    backdrop.scrollTop = textarea.scrollTop;
    const parts = [];
    if (ok.length) { parts.push('부를 사람: ' + ok.join(', ')); }
    if (miss.length) { parts.push('찾을 수 없음: ' + miss.join(', ')); }
    help.textContent = parts.join(' · ');
    help.hidden = parts.length === 0;
    notice.textContent = miss.length ? '찾을 수 없는 사람 ' + String(miss.length) + '명 — 그 이름은 알림이 가지 않아요' : '';
    notice.hidden = miss.length === 0;
  }

  /** 아직 모르는 아이디를 창구에 묻는다(정확히 같은 아이디가 돌아오면 확인 · 아니면 못 찾음). 300ms 묶음 —
      지금 치고 있는 토큰(캐럿이 그 끝)은 손을 멈춘 뒤(1초)에 묻는다 — 한 글자마다 빨강이 깜빡이지 않게 */
  function runCheck(skipCurrent) {
    if (off()) { return; }
    const names = [];
    for (const t of mentionTokens(textarea.value)) {
      if (NAME_RE.test(t.name) && !known.has(t.name) && names.indexOf(t.name) === -1) { names.push(t.name); }
    }
    const cur = tokenAtCaret();
    let skipped = false;
    for (const name of names.slice(0, 10)) {
      if (skipCurrent && cur && cur.name === name && textarea.selectionStart === cur.raw && document.activeElement === textarea) { skipped = true; continue; }
      known.set(name, { state: 'wait', who: null });
      candidates(name).then((people) => {
        if (people === null) { known.delete(name); return; }
        const hit = people.find((p) => p && p.username === name);
        known.set(name, hit ? { state: 'ok', who: hit } : { state: 'miss', who: null });
        paint();
      }).catch(() => { known.delete(name); });
    }
    if (skipped) { checkTimer = window.setTimeout(() => runCheck(false), 1000); }
  }
  function scheduleCheck() {
    window.clearTimeout(checkTimer);
    checkTimer = window.setTimeout(() => runCheck(true), 300);
  }

  /* ── 후보 목록 ── */
  /** 캐럿이 든 `@토큰`(빈 `@` 도) — { at, end, raw, name } 또는 null */
  function tokenAtCaret() {
    if (textarea.selectionStart !== textarea.selectionEnd) { return null; }
    const pos = textarea.selectionStart || 0;
    const text = textarea.value;
    const before = text.slice(0, pos);
    const m = before.match(/(^|[^a-z0-9_.@-])@([a-z0-9_.-]{0,64})$/);
    if (!m) { return null; }
    const at = pos - m[2].length - 1;
    const tail = text.slice(pos).match(/^[a-z0-9_.-]*/)[0];
    const name = m[2] + tail;
    return { at: at, raw: at + 1 + name.length, end: at + 1 + name.length, name: name };
  }

  function close() {
    open = null;
    list.hidden = true;
    while (list.firstChild) { list.removeChild(list.firstChild); }
    textarea.removeAttribute('aria-activedescendant');
  }

  function draw() {
    while (list.firstChild) { list.removeChild(list.firstChild); }
    if (!open) { return; }
    if (!open.people.length) {
      list.appendChild(el('p', 'help', EMPTY_TEXT));
    }
    open.people.forEach((p, i) => {
      const b = el('button', 'menu__item');
      b.type = 'button';
      b.id = uid + '-opt-' + String(i);
      b.tabIndex = -1;
      b.setAttribute('role', 'option');
      b.setAttribute('data-mention', p.username);
      b.setAttribute('aria-selected', i === open.active ? 'true' : 'false');
      const av = el('span', 'task-avatar', initialOf(p.display_name || p.username));
      av.setAttribute('aria-hidden', 'true');
      b.appendChild(av);
      // 이름 + 아이디(--muted) — 이름이 없으면 아이디 한 번만
      b.appendChild(el('span', null, p.display_name || p.username));
      if (p.display_name) { b.appendChild(el('span', 'muted', p.username)); }
      // 입력 칸이 초점을 잃지 않게(누르면 바로 고른다)
      b.addEventListener('mousedown', (e) => e.preventDefault());
      b.addEventListener('click', () => pick(p));
      list.appendChild(b);
    });
    list.hidden = false;
    const act = open.people[open.active] ? uid + '-opt-' + String(open.active) : '';
    if (act) { textarea.setAttribute('aria-activedescendant', act); } else { textarea.removeAttribute('aria-activedescendant'); }
  }

  function query(tok) {
    const mine = ++listSeq;
    window.clearTimeout(listTimer);
    listTimer = window.setTimeout(() => {
      candidates(tok.name).then((people) => {
        if (mine !== listSeq) { return; }
        const cur = tokenAtCaret();
        if (!cur || cur.at !== tok.at || document.activeElement !== textarea) { return; }
        const ps = (people || []).filter((p) => p && typeof p.username === 'string');
        for (const p of ps) { remember(p); }
        open = { at: cur.at, end: cur.end, q: cur.name, people: ps.slice(0, 10), active: 0 };
        draw();
        paint();
      }).catch(() => {});
    }, tok.name === '' ? 0 : 150);
  }

  /** 캐럿 자리를 보고 목록을 연다 · 닫는다(입력 · 누르기 · 화살표) */
  function sync(fromInput) {
    if (off()) { close(); return; }
    const tok = tokenAtCaret();
    if (!tok) { dismissedAt = -1; close(); return; }
    if (fromInput) { dismissedAt = -1; }
    if (dismissedAt === tok.at) { return; }
    if (open && open.at === tok.at && open.q === tok.name) { return; }
    query(tok);
  }

  function pick(p) {
    const tok = tokenAtCaret() || (open ? { at: open.at, end: open.end } : null);
    if (!tok) { close(); return; }
    const text = textarea.value;
    const after = text.slice(tok.end);
    const glue = /^\s/.test(after) ? '' : ' ';
    const insert = '@' + p.username + glue;
    textarea.value = text.slice(0, tok.at) + insert + after;
    const caret = tok.at + insert.length + (glue === '' ? 1 : 0);
    textarea.focus();
    textarea.setSelectionRange(caret, caret);
    remember(p);
    close();
    paint();
    // 부르는 쪽(임시저장 등)이 입력으로 알게 — 목록은 이 이벤트로 다시 열리지 않는다(캐럿이 토큰 밖)
    textarea.dispatchEvent(new Event('input', { bubbles: true }));
  }

  textarea.addEventListener('input', () => { paint(); scheduleCheck(); sync(true); });
  textarea.addEventListener('click', () => sync(false));
  textarea.addEventListener('keyup', (e) => {
    if (['ArrowLeft', 'ArrowRight', 'Home', 'End'].indexOf(e.key) !== -1) { sync(false); }
  });
  textarea.addEventListener('scroll', () => { backdrop.scrollTop = textarea.scrollTop; });
  textarea.addEventListener('blur', () => { window.setTimeout(() => { if (document.activeElement !== textarea) { close(); } scheduleCheck(); }, 120); });
  textarea.addEventListener('keydown', (e) => {
    if (!open || list.hidden || e.isComposing || e.keyCode === 229) { return; }
    const n = open.people.length;
    if (e.key === 'ArrowDown' && n) { e.preventDefault(); open.active = (open.active + 1) % n; draw(); return; }
    if (e.key === 'ArrowUp' && n) { e.preventDefault(); open.active = (open.active - 1 + n) % n; draw(); return; }
    if ((e.key === 'Enter' || e.key === 'Tab') && n && !e.shiftKey && !e.ctrlKey && !e.metaKey && !e.altKey) {
      e.preventDefault();
      pick(open.people[open.active]);
      return;
    }
    if (e.key === 'Escape') {
      e.preventDefault();
      e.stopPropagation();
      dismissedAt = open.at;
      close();
    }
  });
  if (typeof ResizeObserver === 'function') {
    try { new ResizeObserver(() => { backdrop.scrollTop = textarea.scrollTop; }).observe(textarea); } catch (e) { /* 크기 맞춤은 CSS 몫 */ }
  }
  paint();

  return {
    notice: notice,
    /** 칸 값을 밖에서 바꾼 뒤(보낸 뒤 비우기 · 사본 되살리기) 다시 그린다 */
    refresh: () => { paint(); scheduleCheck(); },
    reset: () => { close(); paint(); },
    close: close,
    /** 지금 못 찾은 아이디 수 */
    missing: () => mentionTokens(textarea.value).filter((t) => (known.get(t.name) || {}).state === 'miss').length
  };
}
