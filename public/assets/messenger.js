/**
 * public/assets/messenger.js — 모아 메신저 한 벌 (사이트 Q:F-11 「어느 화면에서나 이어지는 메신저」 지시서 A·B)
 *
 * 요구사항: docs/qf11-floating-messenger-directive.md 「지시서 A」 A-1~A-4 · 「지시서 B」 B-1~B-4 ·
 *           기획 사이트 docs/persistent-messenger-plan.md 4.1~4.8·5장 · DESIGN 2.17 · SECURITY R-116 · R-117 · R-118 · C:R-10 ·
 *           API.md 「모아 초대 가입·메신저」 · 커뮤니티 ARCHITECTURE 「임시저장」 절.
 *
 * 무엇을 하나
 *   `/messages` 페이지(붙박이 'page' — member.js 가 부른다)와 모든 로그인 화면의 떠 있는 창('window' — 이 파일이 스스로 붙는다)이
 *   같이 쓰는 메신저 화면 코드.
 *   ① 창 상태(sessionStorage `qaredi-messenger:v1:<아이디>`) — 열림·상대·AI 스레드 **번호**·스크롤만(본문·이름 0). 같은 탭에서 화면을 옮기면 이어 연다.
 *   ② 쓰던 글(localStorage, 커뮤니티 임시저장 `drafts.js` 와 같은 열쇠·AES-GCM·AAD·7일·20개) —
 *      `qaredi-draft:v1:<아이디>:message:<상대 번호>` `{text, attachment_id, request_id}` · `…:ai:<스레드 번호 또는 0>` `{text}`.
 *      열쇠를 못 받으면 **쓰지 않는다**(평문 대체 0) — 입력 칸 아래 배지 「임시저장 못 함」.
 *   ③ 문자 메시지 `request_id`(UUIDv4) — 상대별 쓰던 글에 붙여 두고, 글·첨부가 바뀌면 새로. 결과가 불확실한 실패 뒤 같은 글은 같은 UUID.
 *   ④ (B) 떠 있는 창 — `dialog.show()`(비모달) · 모든 `[data-messenger-open]` 이 연다 · 복원은 초점을 옮기지 않는다 · Esc 는 초점이 창 안일 때만.
 *   ⑤ (B) 폴링 — 열림+보임 8초(현재 대화 + 사람 목록) · 닫힘+보임 30초(안 읽은 수만, 대표 탭만) · 숨김 정지 · 자동 요청은 전부 `X-Qaredi-Background`.
 *   ⑥ (B) 여러 탭 — `navigator.locks` 로 **보이는 탭** 하나가 대표(닫힌 창의 배지 폴링), 결과는 `BroadcastChannel('qaredi-messenger:<아이디>')` 로 나눈다.
 *      받은 값은 모양·범위를 검사한 **데이터**로만 그린다(`node()`+textContent). 대표 선출을 모르는 브라우저는 탭마다 폴링.
 *   ⑦ (B) 꾸미기 → 모아 AI — `qaredi:messenger {mode:'ai', prompt}` 를 받으면 창을 AI 칸으로 열고 입력 칸에 **미리 채운다**(자동 전송 0).
 *      그 요청에서 보낸 질문의 답변에만 「꾸미기 화면으로」 → `qaredi:ai-result {text}` + 창 닫기.
 *
 * 규칙
 *   · 화면 출력은 `node()`(createElement + textContent)만. 저장소·BroadcastChannel 에서 받은 값은 **데이터**로만 — 번호는 정규식·범위 검사 뒤에 쓴다.
 *   · 다른 사람 접두의 값은 읽지 않는다 — 이름으로만 골라 지운다(계정이 바뀐 탭).
 *   · 쓰던 글은 서버로 보내지 않는다. AES 열쇠는 drafts.js 모듈 안에만(여기·전역·쿠키·저장소 0). 탭 사이에는 본문 대신 SHA-256 요약만.
 *   · 외부 주소 0 · 콘솔 0 · 새 창구 0(열쇠는 기존 `GET /community/api/me/draft-key`, 안 읽은 수는 기존 `GET /api/messages?q=`).
 *   · PC 도우미(내 PC 의 loopback 주소)는 `/messages` 에서만 — 이 파일에는 그 주소가 없다(member-assist.js 가 창에서는 안내만 보인다).
 *   · 역할 비교 0 — `/api/me` 의 `can_community_write`·`acting` 만 본다(근거는 서버 `Member::requireMessenger`).
 */
import { createDrafts } from './community/drafts.js?v=cd5329f796b6';

/** 창 상태 열쇠 접두 — 뒤에 진짜 사용자 아이디(`/api/me` 의 username — 역할로 보기 중에도 실제 계정) */
const STATE_PREFIX = 'qaredi-messenger:v1:';
/** 같은 탭의 창 위치만. 대화 v1 및 암호화 초안과 독립된 숫자 데이터. */
const POSITION_PREFIX = 'qaredi-messenger-position:v1:';
const SIZE_PREFIX = 'qaredi-messenger-size:v1:';
/** 임시저장 접두(drafts.js · app.js clearMyDrafts 와 같은 값) */
const DRAFT_PREFIX = 'qaredi-draft:v1:';
/** 대표 탭 잠금 · 탭 사이 통로 이름 접두(B-3) — 뒤에 진짜 아이디. 다른 사람 탭과 섞이지 않는다 */
const SHARE_PREFIX = 'qaredi-messenger:';
/** 아이디 — `Auth::USERNAME_RE` 와 같은 글자 집합(`:` 없음) */
const OWNER_RE = /^[a-z0-9_.-]{3,64}$/;
/** 다른 사람의 메신저 쓰던 글 이름(값은 읽지 않는다) */
const OTHER_MESSENGER_DRAFT_RE = /^qaredi-draft:v1:([a-z0-9_.-]{3,64}):(?:message|ai):/;
const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;
const HEX64_RE = /^[0-9a-f]{64}$/;
const STATE_KEYS = ['at', 'kind', 'open', 'peer', 'scroll', 'thread', 'v'];
const SHARE_KINDS = ['people', 'messages', 'unread', 'sent', 'read'];
const MAX_NUMBER = 9999999999;
const MAX_SCROLL = 100000000;
const MAX_COUNT = 100000;
/** 탭 사이로 받는 목록의 상한 — 서버 응답의 상한과 같다(사람 100 · 대화 60) */
const MAX_PEOPLE = 100;
const MAX_SHARED_MESSAGES = 60;
/** 열린 대화 + 사람 목록 다시 읽기(member.js 그대로) */
const POLL_MS = 8000;
/** 창이 닫혀 있을 때 안 읽은 수만 — 커뮤니티 알림 종과 같은 주기(editor.js) */
const CLOSED_POLL_MS = 30000;
/** 쓰던 글 암호화 저장 묶음 */
const DRAFT_DELAY_MS = 1000;
/** 목록 스크롤이 멈춘 뒤 상태 쓰기 */
const SCROLL_SETTLE_MS = 300;
/** 열쇠 창구가 답하지 않을 때 기다리는 한도 — 넘으면 「못 받음」(다시 조르지 않는다) */
const KEY_TIMEOUT_MS = 10000;
/** 꾸미기 요청문을 넣을 수 있는 길이(서버 `member_ai.php` 의 질문 상한과 같다 — 코드 포인트) */
const AI_PROMPT_MAX = 4000;
/** 커뮤니티 창구 기준 — 셸(커뮤니티 모드)·런처 `<body data-community-base>`, 없으면(모아 머리·t-box 셸) 같은 값의 기본 */
const COMMUNITY_BASE = (document.body && document.body.getAttribute('data-community-base')) || '/community';

function uuid() {
  if (window.crypto && typeof window.crypto.randomUUID === 'function') { return window.crypto.randomUUID(); }
  return '10000000-1000-4000-8000-100000000000'.replace(/[018]/g, (c) => (Number(c) ^ window.crypto.getRandomValues(new Uint8Array(1))[0] & 15 >> Number(c) / 4).toString(16));
}

function sessionArea() { try { return window.sessionStorage || null; } catch (e) { return null; } }
function localArea() { try { return window.localStorage || null; } catch (e) { return null; } }

/** 저장소의 열쇠 이름들(값은 읽지 않는다) */
function names(area) {
  const out = [];
  if (area === null) { return out; }
  try {
    for (let i = 0; i < area.length; i += 1) {
      const k = area.key(i);
      if (typeof k === 'string') { out.push(k); }
    }
  } catch (e) {
    return [];
  }
  return out;
}

function drop(area, name) {
  try { if (area !== null) { area.removeItem(name); } } catch (e) { /* 막힌 저장소 — 지울 것도 없다 */ }
}

const isNumber = (v) => Number.isInteger(v) && v >= 1 && v <= MAX_NUMBER;
const stateKey = (owner) => STATE_PREFIX + owner;

/**
 * 창 상태 읽기 — 모양·범위가 틀리면 **지우고 무시**한다.
 * @return {{open:boolean, kind:string, peer:number|null, thread:number|null, fromBottom:number}|null}
 */
function readState(owner) {
  const area = sessionArea();
  if (area === null) { return null; }
  let raw = null;
  try { raw = area.getItem(stateKey(owner)); } catch (e) { return null; }
  if (raw === null) { return null; }
  let v = null;
  try { v = JSON.parse(raw); } catch (e) { v = null; }
  const ok = v !== null && typeof v === 'object' && !Array.isArray(v)
    && Object.keys(v).sort().join(',') === STATE_KEYS.join(',')
    && v.v === 1 && typeof v.open === 'boolean' && (v.kind === 'person' || v.kind === 'ai')
    && (v.peer === null || isNumber(v.peer)) && (v.thread === null || isNumber(v.thread))
    && (v.kind === 'person' ? v.peer !== null && v.thread === null : v.peer === null)
    && v.scroll !== null && typeof v.scroll === 'object' && !Array.isArray(v.scroll)
    && Object.keys(v.scroll).join(',') === 'fromBottom'
    && Number.isInteger(v.scroll.fromBottom) && v.scroll.fromBottom >= 0 && v.scroll.fromBottom <= MAX_SCROLL
    && typeof v.at === 'number' && Number.isFinite(v.at);
  if (!ok) { drop(area, stateKey(owner)); return null; }
  return { open: v.open, kind: v.kind, peer: v.peer, thread: v.thread, fromBottom: v.scroll.fromBottom };
}

function writeState(owner, st) {
  const area = sessionArea();
  if (area === null) { return; }
  const value = JSON.stringify({ v: 1, open: st.open === true, kind: st.kind, peer: st.peer, thread: st.thread,
    scroll: { fromBottom: st.fromBottom }, at: Date.now() });
  try { area.setItem(stateKey(owner), value); } catch (e) { /* 막힌 저장소 — 이어 열기만 못 한다 */ }
}

/** 이 탭에 남은 **다른 사람** 몫(창 상태 · 메신저 쓰던 글)을 이름으로만 지운다 — 계정이 바뀐 탭(M-5) */
function forgetOthers(owner) {
  const s = sessionArea(), mine = stateKey(owner);
  for (const n of names(s)) { if (n.indexOf(STATE_PREFIX) === 0 && n !== mine) { drop(s, n); } }
  for (const n of names(s)) { if ((n.indexOf(POSITION_PREFIX) === 0 && n !== POSITION_PREFIX + owner) || (n.indexOf(SIZE_PREFIX) === 0 && n !== SIZE_PREFIX + owner)) { drop(s, n); } }
  const l = localArea();
  for (const n of names(l)) {
    const m = n.match(OTHER_MESSENGER_DRAFT_RE);
    if (m !== null && m[1] !== owner) { drop(l, n); }
  }
}

/** 로그아웃 — 이 사람의 창 상태와 쓰던 글 접두 전부(app.js clearMyDrafts 와 같은 범위 — 값을 읽지 않는다) */
function forgetMine(owner) {
  drop(sessionArea(), stateKey(owner));
  drop(sessionArea(), POSITION_PREFIX + owner); drop(sessionArea(), SIZE_PREFIX + owner);
  const l = localArea(), mine = DRAFT_PREFIX + owner + ':';
  for (const n of names(l)) { if (n.indexOf(mine) === 0) { drop(l, n); } }
}

/** 떠 있는 창의 숫자 위치·크기만 관리한다. 대화/초안/서버 상태와 분리한다. */
function movableWindow(dialog, owner, available) {
  const head = dialog.querySelector('.modal__head');
  const handle = document.getElementById('messenger-move-handle');
  const reset = document.getElementById('messenger-position-reset');
  const edges = [...dialog.querySelectorAll('[data-messenger-resize]')];
  const desktop = window.matchMedia('(min-width:720px)');
  const key = POSITION_PREFIX + owner, sizeKey = SIZE_PREFIX + owner;
  const listeners = [], defaults = { w: 400, h: 640, mobileH: 0 };
  let desired = null, size = { ...defaults }, drag = null, dead = false;
  const number = (v, min = 0) => Number.isInteger(v) && v >= min && v <= 100000;
  function read(name, valid) {
    try {
      const area = sessionArea(), raw = area && area.getItem(name);
      if (raw === null || raw === undefined) { return null; }
      const value = JSON.parse(raw);
      if (valid(value)) { return value; }
    } catch (e) { /* 잘못된 값은 데이터로만 다룬다 */ }
    drop(sessionArea(), name); return null;
  }
  const saved = read(key, (v) => plainObject(v) && exactKeys(v, 'v,x,y') && v.v === 1 && number(v.x) && number(v.y));
  if (saved) { desired = { x: saved.x, y: saved.y }; }
  const savedSize = read(sizeKey, (v) => plainObject(v) && exactKeys(v, 'h,mobileH,v,w') && v.v === 1 && number(v.w, 1) && number(v.h, 1) && number(v.mobileH));
  if (savedSize) { size = { w: savedSize.w, h: savedSize.h, mobileH: savedSize.mobileH }; }
  function on(target, type, fn, options) {
    target.addEventListener(type, fn, options);
    listeners.push(() => target.removeEventListener(type, fn, options));
  }
  const modalOpen = () => document.querySelector('dialog:modal') !== null;
  function viewport() {
    const v = window.visualViewport;
    return { x: v ? v.offsetLeft : 0, y: v ? v.offsetTop : 0, w: v ? v.width : innerWidth, h: v ? v.height : innerHeight };
  }
  const clamp = (n, min, max) => Math.max(min, Math.min(max, n));
  function bounds() {
    const v = viewport(), w = Math.max(1, v.w - 32), h = Math.max(1, v.h - 32);
    return { left: v.x + 16, top: v.y + 16, right: v.x + v.w - 16, bottom: v.y + v.h - 16, w, h, minW: Math.min(360, w), minH: Math.min(420, h) };
  }
  function paint(x, y, w, h) {
    if (![x, y, w, h].every(Number.isFinite)) { return; }
    dialog.style.left = Math.round(x) + 'px'; dialog.style.top = Math.round(y) + 'px';
    dialog.style.width = Math.floor(w) + 'px'; dialog.style.height = Math.floor(h) + 'px';
    dialog.style.right = 'auto'; dialog.style.bottom = 'auto';
    dialog.style.maxWidth = 'none'; dialog.style.maxHeight = 'none';
    dialog.classList.toggle('is-short', h < 420);
    dialog.classList.toggle('is-tiny', h < 260);
    if (h >= 260) { dialog.scrollTop = 0; }
    syncEdges();
  }
  function draw(point = desired, dimensions = size) {
    const b = bounds(), w = clamp(dimensions.w, b.minW, b.w);
    const defaultSize = dimensions.w === defaults.w && dimensions.h === defaults.h;
    const h = defaultSize ? Math.min(defaults.h, Math.max(1, viewport().h - 96)) : clamp(dimensions.h, b.minH, b.h);
    const x = clamp(point ? point.x : b.right - w, b.left, b.right - w);
    const y = clamp(point ? point.y : b.bottom - 56 - h, b.top, b.bottom - h);
    paint(x, y, w, h);
  }
  function drawMobile(height = size.mobileH) {
    const v = viewport(), max = Math.max(1, v.h - 8), min = Math.min(420, max);
    const h = Math.floor(clamp(height || innerHeight * .88, min, max));
    paint(v.x, v.y + v.h - h, v.w, h);
  }
  function syncEdges() {
    const top = dialog.scrollTop;
    for (const edge of edges) {
      if (top > 0) { edge.style.translate = '0 ' + top + 'px'; }
      else { edge.style.removeProperty('translate'); }
    }
  }
  function clearPosition() {
    for (const p of ['left', 'top', 'right', 'bottom', 'width', 'height', 'max-width', 'max-height']) { dialog.style.removeProperty(p); }
  }
  function apply() {
    if (dead) { return; }
    const enabled = desktop.matches && available();
    handle.tabIndex = enabled ? 0 : -1;
    if (enabled) { handle.setAttribute('role', 'button'); handle.setAttribute('aria-label', '메신저 창 이동'); handle.setAttribute('aria-describedby', 'messenger-move-help'); }
    else { handle.removeAttribute('role'); handle.removeAttribute('aria-label'); handle.removeAttribute('aria-describedby'); }
    reset.disabled = !available();
    reset.setAttribute('aria-label', desktop.matches ? '메신저 창을 기본 위치와 크기로' : '메신저 창을 기본 높이로');
    for (const edge of edges) {
      const enabledEdge = available() && (desktop.matches || edge.dataset.messengerResize === 'n');
      edge.disabled = !enabledEdge; edge.tabIndex = enabledEdge ? 0 : -1;
    }
    if (dialog.open) { if (desktop.matches) { draw(); } else { drawMobile(); } }
  }
  function storePosition() {
    if (dead || !available() || desired === null) { return; }
    try { const s = sessionArea(); if (s) { s.setItem(key, JSON.stringify({ v: 1, x: desired.x, y: desired.y })); } } catch (e) { /* 메모리 위치 유지 */ }
  }
  function storeSize() {
    if (dead || !available()) { return; }
    if (size.w === defaults.w && size.h === defaults.h && size.mobileH === 0) { drop(sessionArea(), sizeKey); return; }
    try { const s = sessionArea(); if (s) { s.setItem(sizeKey, JSON.stringify({ v: 1, w: size.w, h: size.h, mobileH: size.mobileH })); } } catch (e) { /* 메모리 크기 유지 */ }
  }
  function commit(kind, mobile) {
    const r = dialog.getBoundingClientRect();
    if (mobile) { size.mobileH = Math.round(r.height); storeSize(); }
    else {
      desired = { x: Math.round(r.left), y: Math.round(r.top) }; storePosition();
      if (kind !== 'move') { size.w = Math.round(r.width); size.h = Math.round(r.height); storeSize(); }
    }
  }
  function finish(save = false) {
    if (drag === null) { return; }
    const ended = drag; drag = null;
    dialog.classList.remove('is-moving', 'is-resizing');
    if (save && available() && !modalOpen() && ended.mobile === !desktop.matches) { commit(ended.kind, ended.mobile); }
    // 확정값을 먼저 기록하여 동기 lostpointercapture도 원복시키지 못한다.
    try { if (ended.target.hasPointerCapture(ended.id)) { ended.target.releasePointerCapture(ended.id); } } catch (e) { /* 이미 해제된 포인터 */ }
    apply();
  }
  const cancel = () => { finish(false); apply(); };
  function resize(r, edge, dx, dy, mobile) {
    if (mobile) { drawMobile(r.height - dy); return; }
    const b = bounds(); let left = r.left, right = r.right, top = r.top, bottom = r.bottom;
    if (edge.includes('w')) { left = clamp(left + dx, b.left, right - b.minW); }
    if (edge.includes('e')) { right = clamp(right + dx, left + b.minW, b.right); }
    if (edge.includes('n')) { top = clamp(top + dy, b.top, bottom - b.minH); }
    if (edge.includes('s')) { bottom = clamp(bottom + dy, top + b.minH, b.bottom); }
    draw({ x: left, y: top }, { w: right - left, h: bottom - top });
  }
  function begin(e, kind, target) {
    const mobile = !desktop.matches;
    if (dead || !available() || !dialog.open || modalOpen() || drag !== null || e.button !== 0 || e.isPrimary === false || (mobile && kind !== 'n')) { return; }
    if (!Number.isFinite(e.clientX) || !Number.isFinite(e.clientY)) { return; }
    drag = { id: e.pointerId, target, kind, mobile, clientX: e.clientX, clientY: e.clientY, rect: dialog.getBoundingClientRect() };
    try { target.setPointerCapture(e.pointerId); } catch (error) { drag = null; return; }
    e.preventDefault(); (kind === 'move' ? handle : target).focus({ preventScroll: true });
    dialog.classList.add(kind === 'move' ? 'is-moving' : 'is-resizing');
  }
  on(head, 'pointerdown', (e) => {
    if (!(e.target instanceof Element) || e.target.closest('button,a,input,textarea,select,label,summary,[contenteditable="true"]')) { return; }
    begin(e, 'move', head);
  });
  for (const edge of edges) { on(edge, 'pointerdown', (e) => begin(e, edge.dataset.messengerResize, edge)); }
  on(dialog, 'pointermove', (e) => {
    if (drag === null || drag.id !== e.pointerId) { return; }
    if (!available() || drag.mobile !== !desktop.matches || modalOpen()) { cancel(); return; }
    if (!Number.isFinite(e.clientX) || !Number.isFinite(e.clientY)) { return; }
    const dx = e.clientX - drag.clientX, dy = e.clientY - drag.clientY;
    if (drag.kind === 'move') { draw({ x: drag.rect.left + dx, y: drag.rect.top + dy }); }
    else { resize(drag.rect, drag.kind, dx, dy, drag.mobile); }
  });
  on(dialog, 'pointerup', (e) => { if (drag !== null && drag.id === e.pointerId) { finish(true); } });
  on(dialog, 'pointercancel', cancel); on(dialog, 'lostpointercapture', cancel);
  function keyboard(e, kind) {
    const mobile = !desktop.matches;
    if (dead || !available() || !dialog.open || modalOpen() || (mobile && kind !== 'n') || e.isComposing || e.ctrlKey || e.metaKey || e.altKey) { return; }
    const d = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] }[e.key];
    if (!d || (mobile && d[0]) || (kind !== 'move' && ((d[0] && !/[we]/.test(kind)) || (d[1] && !/[ns]/.test(kind))))) { return; }
    e.preventDefault(); cancel();
    const r = dialog.getBoundingClientRect(), step = e.shiftKey ? 40 : 10;
    if (kind === 'move') { draw({ x: r.left + d[0] * step, y: r.top + d[1] * step }); }
    else { resize(r, kind, d[0] * step, d[1] * step, mobile); }
    commit(kind, mobile);
  }
  on(handle, 'keydown', (e) => keyboard(e, 'move'));
  for (const edge of edges) { on(edge, 'keydown', (e) => keyboard(e, edge.dataset.messengerResize)); }
  on(reset, 'click', () => {
    if (dead || !available() || modalOpen()) { return; }
    finish(false);
    if (desktop.matches) { desired = null; size.w = defaults.w; size.h = defaults.h; drop(sessionArea(), key); }
    else { size.mobileH = 0; }
    storeSize(); apply();
  });
  on(dialog, 'scroll', syncEdges, { passive: true });
  on(window, 'resize', cancel); on(window, 'blur', cancel); on(window, 'pagehide', cancel); on(dialog, 'close', cancel); on(desktop, 'change', cancel);
  if (window.visualViewport) { on(window.visualViewport, 'resize', cancel); on(window.visualViewport, 'scroll', cancel); }
  on(document, 'visibilitychange', () => { if (document.hidden) { cancel(); } });
  on(document, 'focusin', () => { if (drag !== null && modalOpen()) { cancel(); } });
  const observer = new MutationObserver(() => { if (drag !== null && (!dialog.open || modalOpen())) { cancel(); } });
  observer.observe(document.body, { subtree: true, childList: true, attributes: true, attributeFilter: ['open'] });
  apply();
  return { apply, cancel, destroy() { finish(false); dead = true; observer.disconnect(); for (const remove of listeners) { remove(); } clearPosition(); dialog.classList.remove('is-short', 'is-tiny'); handle.tabIndex = -1; handle.removeAttribute('role'); handle.removeAttribute('aria-label'); handle.removeAttribute('aria-describedby'); reset.disabled = true; for (const edge of edges) { edge.disabled = true; edge.tabIndex = -1; edge.style.removeProperty('translate'); } } };
}

/** 임시저장 열쇠 받기 — drafts.js 가 한 화면에 한 번만 부른다(실패도 기억). 커뮤니티 editor.js 와 같은 요청 모양. */
function loadDraftKey() {
  const controller = typeof AbortController === 'function' ? new AbortController() : null;
  const timer = controller ? window.setTimeout(() => controller.abort(), KEY_TIMEOUT_MS) : null;
  const options = { method: 'GET', credentials: 'same-origin', cache: 'no-store', headers: {} };
  if (controller) { options.signal = controller.signal; }
  return fetch(COMMUNITY_BASE + '/api/me/draft-key', options)
    .then((r) => (r.status === 200 ? r.json() : null))
    .then((d) => (d !== null && typeof d === 'object' && d.v === 1 && typeof d.key === 'string' ? d.key : null))
    .catch(() => null)
    .finally(() => { if (timer !== null) { window.clearTimeout(timer); } });
}

/** 풀어 낸 값 — 모양이 아니면 null(그 항목은 버린다) */
function cleanDraft(kind, data) {
  if (data === null || typeof data !== 'object' || typeof data.text !== 'string') { return null; }
  if (kind === 'ai') { return { text: data.text }; }
  const attachment = data.attachment_id === null || data.attachment_id === undefined ? null : data.attachment_id;
  const request = data.request_id === null || data.request_id === undefined ? null : data.request_id;
  if ((attachment !== null && (typeof attachment !== 'string' || !UUID_RE.test(attachment)))
      || (request !== null && (typeof request !== 'string' || !UUID_RE.test(request)))) { return null; }
  return { text: data.text, attachment_id: attachment, request_id: request };
}

const emptyDraft = (v) => !v || (v.text === '' && !v.attachment_id);

/**
 * 한 사람의 메신저 쓰던 글 — 메모리 사본 + 암호화 localStorage(drafts.js).
 * @param {string}   owner  진짜 사용자 아이디
 * @param {Function} off    「임시저장 못 함」 배지 켜기/끄기(boolean)
 */
function draftStore(owner, off) {
  const d = createDrafts(owner, loadDraftKey);
  const mem = new Map(), timers = new Map(), seqs = new Map();
  let restored = null, stopped = false;
  const slot = (kind, id) => kind + ':' + id;
  const storageKey = (kind, id) => (kind === 'message' ? d.messageKey(id) : d.aiKey(id));
  const bump = (k) => { const n = (seqs.get(k) || 0) + 1; seqs.set(k, n); return n; };

  /** 한 화면에 한 번 — 이 사람의 메신저 항목이 있을 때만 열쇠를 받아 풀어 둔다(풀리지 않는 것은 drafts.js 가 조용히 지운다) */
  function restore() {
    if (restored !== null) { return restored; }
    if (!d.available()) { restored = Promise.resolve(); return restored; }
    d.sweep();
    const list = d.entries(['message', 'ai']);
    if (list.length === 0) { restored = Promise.resolve(); return restored; }
    restored = d.ready().then((ok) => {
      if (!ok) { off(true); return null; }
      return Promise.all(list.map((e) => d.read(e.key).then((got) => {
        if (got === null || stopped) { return; }
        const id = e.kind === 'message' ? e.peer : e.thread;
        const v = cleanDraft(e.kind, got.data);
        if (v === null || emptyDraft(v)) { d.remove(e.key); return; }
        if (!mem.has(slot(e.kind, id))) { mem.set(slot(e.kind, id), v); }
      })));
    }).catch(() => null);
    return restored;
  }

  function write(kind, id) {
    const k = slot(kind, id), key = storageKey(kind, id), value = mem.get(k);
    if (stopped || key === null || value === undefined) { return Promise.resolve(); }
    const seq = bump(k);
    return d.ready().then((ok) => {
      if (!ok) { off(true); return null; }
      if (stopped || seqs.get(k) !== seq || mem.get(k) !== value) { return null; }
      return d.write(key, value, () => !stopped && seqs.get(k) === seq && mem.get(k) === value).then((done) => { off(!done); });
    }).catch(() => { off(true); });
  }

  /** 쓰던 글이 바뀌었다 — 메모리에 두고 1초 묶음으로 암호화해 쓴다. 비었으면 지운다. */
  function put(kind, id, value) {
    if (stopped || storageKey(kind, id) === null) { return; }
    if (emptyDraft(value)) { forget(kind, id); return; }
    const k = slot(kind, id);
    mem.set(k, value);
    if (!d.available()) { off(true); return; }
    d.ready().then((ok) => { if (!ok) { off(true); } });
    if (!timers.has(k)) {
      timers.set(k, window.setTimeout(() => { timers.delete(k); write(kind, id); }, DRAFT_DELAY_MS));
    }
  }

  /** 기다리지 않고 지금 쓴다(상대·스레드를 바꾸기 직전 · 탭 숨김 · 떠날 때 · 401) */
  function flush(kind, id) {
    const k = slot(kind, id);
    if (!timers.has(k)) { return Promise.resolve(); }
    window.clearTimeout(timers.get(k)); timers.delete(k);
    return write(kind, id);
  }

  function flushAll() {
    const jobs = [];
    for (const k of Array.from(timers.keys())) {
      const at = k.indexOf(':');
      jobs.push(flush(k.slice(0, at), k.slice(at + 1)));
    }
    return Promise.all(jobs);
  }

  /** 보냈다 · 비웠다 — 메모리와 저장소에서 지우고, 날아가 있는 쓰기도 무른다 */
  function forget(kind, id) {
    const k = slot(kind, id), key = storageKey(kind, id);
    if (timers.has(k)) { window.clearTimeout(timers.get(k)); timers.delete(k); }
    bump(k); mem.delete(k);
    if (key !== null) { d.remove(key); }
  }

  return {
    restore, put, flush, flushAll, forget,
    get: (kind, id) => mem.get(slot(kind, String(id))),
    /** 로그아웃 — 열쇠를 버리고 이 화면 수명 동안 다시 쓰지 않는다 */
    shutdown() {
      stopped = true;
      for (const t of timers.values()) { window.clearTimeout(t); }
      timers.clear(); mem.clear(); d.shutdown();
    },
  };
}

/* ─── 여러 탭 — 탭 사이로 오가는 값의 모양 검사(B-3 · R-117) ─────────────────────────────
 * BroadcastChannel 은 같은 출처·같은 브라우저 안에서만 오가지만, 받은 값은 **데이터**로만 쓴다:
 * 열쇠 집합이 정확히 맞고, 번호·개수는 범위 안의 정수, 글자는 길이 상한 안의 문자열일 때만 받는다. DOM 문자열로 해석하는 길은 없다. */

const exactKeys = (o, list) => Object.keys(o).sort().join(',') === list;
const plainObject = (o) => o !== null && typeof o === 'object' && !Array.isArray(o);
const cleanCount = (v) => (Number.isInteger(v) && v >= 0 && v <= MAX_COUNT ? v : null);
const shortText = (v, max) => typeof v === 'string' && v.length <= max;

/** 서버가 준 사람 한 줄 → 탭 사이로 보낼 모양(번호·글자만). 서버는 숫자를 글자로 줄 수 있어 여기서 숫자로 맞춘다 */
function normPerson(p) {
  if (!plainObject(p)) { return null; }
  const id = Number(p.id), unread = Number(p.unread || 0);
  if (!isNumber(id) || !shortText(p.username, 64) || p.username === '' || cleanCount(unread) === null
      || !(p.display_name === null || p.display_name === undefined || shortText(p.display_name, 200))) { return null; }
  return { id, username: p.username, display_name: typeof p.display_name === 'string' ? p.display_name : null, unread };
}

/** 받은 사람 한 줄 — 보낸 모양 그대로여야 한다 */
function cleanPerson(p) {
  if (!plainObject(p) || !exactKeys(p, 'display_name,id,unread,username')) { return null; }
  if (!isNumber(p.id) || !shortText(p.username, 64) || p.username === '' || cleanCount(p.unread) === null
      || !(p.display_name === null || shortText(p.display_name, 200))) { return null; }
  return { id: p.id, username: p.username, display_name: p.display_name, unread: p.unread };
}

/** 서버가 준 메시지 한 건 → 탭 사이로 보낼 모양 */
function normMessage(m) {
  if (!plainObject(m)) { return null; }
  const out = { id: Number(m.id), sender_id: Number(m.sender_id), body: m.body, sent_at: m.sent_at,
    read_at: m.read_at === undefined ? null : m.read_at, ai_attachment_id: m.ai_attachment_id === undefined ? null : m.ai_attachment_id };
  return cleanMessage(out);
}

/** 받은 메시지 한 건 — 본문은 글자로만(4,000 코드 포인트 = UTF-16 최대 8,000) · 첨부는 UUID 일 때만 */
function cleanMessage(m) {
  if (!plainObject(m) || !exactKeys(m, 'ai_attachment_id,body,id,read_at,sender_id,sent_at')) { return null; }
  if (!isNumber(m.id) || !isNumber(m.sender_id) || !shortText(m.body, 8000) || !shortText(m.sent_at, 64)
      || !(m.read_at === null || shortText(m.read_at, 64))
      || !(m.ai_attachment_id === null || (typeof m.ai_attachment_id === 'string' && UUID_RE.test(m.ai_attachment_id)))) { return null; }
  return { id: m.id, sender_id: m.sender_id, body: m.body, sent_at: m.sent_at, read_at: m.read_at, ai_attachment_id: m.ai_attachment_id };
}

/**
 * 탭 사이 메시지 `{v:1, kind, peer, data}` — 모양이 하나라도 틀리면 null(버린다).
 * @return {{kind:string, peer:number|null, data:Object}|null}
 */
function cleanShare(raw) {
  if (!plainObject(raw) || !exactKeys(raw, 'data,kind,peer,v') || raw.v !== 1 || SHARE_KINDS.indexOf(raw.kind) === -1) { return null; }
  if (!(raw.peer === null || isNumber(raw.peer)) || !plainObject(raw.data)) { return null; }
  const kind = raw.kind, peer = raw.peer, d = raw.data;
  if (kind === 'unread') {
    const count = exactKeys(d, 'count') && peer === null ? cleanCount(d.count) : null;
    return count === null ? null : { kind, peer, data: { count } };
  }
  if (kind === 'people') {
    if (peer !== null || !exactKeys(d, 'people') || !Array.isArray(d.people) || d.people.length > MAX_PEOPLE) { return null; }
    const people = d.people.map(cleanPerson);
    return people.indexOf(null) === -1 ? { kind, peer, data: { people } } : null;
  }
  if (kind === 'messages') {
    if (peer === null || !exactKeys(d, 'messages') || !Array.isArray(d.messages) || d.messages.length > MAX_SHARED_MESSAGES) { return null; }
    const messages = d.messages.map(cleanMessage);
    return messages.indexOf(null) === -1 ? { kind, peer, data: { messages } } : null;
  }
  if (kind === 'read') {
    return peer !== null && exactKeys(d, '') ? { kind, peer, data: {} } : null;
  }
  // sent — 본문 대신 SHA-256 요약(64자 16진)만 오간다
  return peer !== null && exactKeys(d, 'digest') && typeof d.digest === 'string' && HEX64_RE.test(d.digest) ? { kind, peer, data: { digest: d.digest } } : null;
}

/** 글의 SHA-256 요약(16진 64자). WebCrypto 가 없으면 null — 그때는 다른 탭의 쓰던 글을 건드리지 않는다 */
function digestHex(text) {
  if (!(window.crypto && window.crypto.subtle && typeof TextEncoder === 'function')) { return Promise.resolve(null); }
  return window.crypto.subtle.digest('SHA-256', new TextEncoder().encode(String(text)))
    .then((buf) => Array.from(new Uint8Array(buf), (b) => b.toString(16).padStart(2, '0')).join(''), () => null);
}

/** 탭 사이 통로. BroadcastChannel 이 없으면 아무것도 하지 않는다(탭마다 따로 — 기획 4.4-A) */
function shareChannel(owner, onMessage) {
  let bc = null;
  try { bc = typeof BroadcastChannel === 'function' ? new BroadcastChannel(SHARE_PREFIX + owner) : null; } catch (e) { bc = null; }
  if (bc !== null) {
    bc.onmessage = (e) => { const m = cleanShare(e.data); if (m !== null) { onMessage(m); } };
  }
  return {
    post(kind, peer, data) { if (bc !== null) { try { bc.postMessage({ v: 1, kind, peer, data }); } catch (e) { /* 닫힌 통로 */ } } },
    close() { if (bc !== null) { try { bc.close(); } catch (e) { /* 이미 닫힘 */ } bc = null; } },
  };
}

/**
 * 대표 탭 — `navigator.locks` 로 **보이는 탭** 하나만 닫힌 창의 배지 폴링을 맡는다(B-3).
 * 숨은 탭은 잠금을 놓고 줄에서도 빠진다(숨김 = 요청 0). 잠금은 탭이 닫히면 브라우저가 풀어 다음 탭이 잇는다.
 * `navigator.locks` 가 없으면 모든 탭이 스스로 대표다(탭마다 폴링 — 기획 4.4-A).
 */
function leadership(owner, onChange) {
  const locks = navigator.locks && typeof navigator.locks.request === 'function' ? navigator.locks : null;
  let leader = locks === null, release = null, waiting = null, stopped = false;
  function want() {
    if (locks === null || stopped || leader || waiting !== null || document.hidden) { return; }
    const ctrl = new AbortController();
    waiting = ctrl;
    locks.request(SHARE_PREFIX + owner, { mode: 'exclusive', signal: ctrl.signal }, () => {
      waiting = null;
      if (stopped || document.hidden) { return undefined; }   // 받는 사이 숨었다 — 곧바로 놓는다
      leader = true;
      onChange(true);
      return new Promise((resolve) => { release = resolve; });   // 놓을 때까지 쥔다
    }).catch(() => null).then(() => {
      if (waiting === ctrl) { waiting = null; }
      if (leader) { leader = false; release = null; onChange(false); }
    });
  }
  function drop() {
    if (waiting !== null) { waiting.abort(); waiting = null; }
    if (release !== null) { const r = release; release = null; leader = false; r(); onChange(false); }
  }
  return {
    get leader() { return leader && !stopped; },
    path: locks === null ? 'each-tab' : 'navigator.locks',
    want, drop,
    stop() { drop(); stopped = true; },
  };
}

/** 사이트 Q:F-13 P1 — 동의 창을 닫았을 때(동의하지 않음) 막혔던 요청이 내는 문장 */
const AI_CONSENT_DECLINED = 'AI 국외 전송에 동의하지 않아 AI 기능을 쓸 수 없어요. 다른 기능은 그대로 쓸 수 있어요.';

/**
 * 사이트 Q:F-13 P1 — AI 국외 전송 동의 창(결정 PT-8). 서버가 409 `{code:"ai_consent_required", notice}` 로 막은 요청에서만 연다.
 * 글자는 서버가 준 notice(처리방침 제7조를 줄로 나눈 것 — src/Legal.php 한 곳)를 textContent 로 그린다. 「동의하고 계속하기」면
 * `agree()`(= `POST /api/me/consents {doc:"ai_overseas"}`)를 부르고 참을 돌려준다 — 부르는 쪽이 막혔던 요청을 **한 번** 다시 보낸다.
 * 닫기 · ✕ · Esc 는 거짓. 막는 것은 서버다(이 창은 안내와 기록 요청뿐). 구성 요소: dialog.modal.modal--form · .info-grid · .help · .msg · .btn
 * (DESIGN 2.7 — 바닥 오른쪽 끝 주요 하나 · 그 왼쪽 「닫기」 · 머리 ✕ · 처음 초점은 「닫기」).
 */
function aiConsentDialog(notice, agree) {
  return new Promise((resolve) => {
    const n = plainObject(notice) ? notice : {};
    const el = (tag, text, cls) => { const e = document.createElement(tag); if (text !== undefined) { e.textContent = text; } if (cls) { e.className = cls; } return e; };
    const d = el('dialog', undefined, 'modal modal--form');
    const head = el('div', undefined, 'modal__head');
    const title = el('h2', typeof n.title === 'string' ? n.title : 'AI 기능 — 국외 전송 동의', 'modal__title');
    title.id = 'ai-consent-title';
    d.setAttribute('aria-labelledby', title.id);
    const x = el('button', '×', 'btn btn--icon btn--plain'); x.type = 'button'; x.setAttribute('aria-label', '닫기');
    head.append(title, x);
    const body = el('div', undefined, 'modal__body');
    body.append(el('p', 'AI 기능을 처음 쓸 때 한 번 묻습니다. 요청한 내용이 아래처럼 국외로 전송됩니다.', 'help'));
    const grid = el('div', undefined, 'info-grid');
    for (const row of Array.isArray(n.rows) ? n.rows : []) {
      if (!Array.isArray(row) || typeof row[0] !== 'string' || typeof row[1] !== 'string') { continue; }
      const item = el('div', undefined, 'info-item');
      item.append(el('span', row[0], 'field__label'), el('span', row[1], 'info-item__value'));
      grid.append(item);
    }
    body.append(grid);
    body.append(el('p', typeof n.note === 'string' ? n.note : '', 'help'));
    if (typeof n.policy_url === 'string' && /^\/tbox\/privacy(#[a-z0-9-]+)?$/.test(n.policy_url)) {
      const links = el('p', undefined, 'legal-links');
      const a = el('a', '개인정보 처리방침 제7조 보기'); a.href = n.policy_url; a.target = '_blank'; a.rel = 'noopener'; links.append(a); body.append(links);
    }
    const foot = el('div', undefined, 'modal__foot');
    const msg = el('p', '', 'msg'); msg.setAttribute('role', 'alert');
    const close = el('button', '닫기', 'btn btn--quiet'); close.type = 'button';
    const ok = el('button', '동의하고 계속하기', 'btn'); ok.type = 'button';
    foot.append(msg, close, ok);
    d.append(head, body, foot);
    let agreed = false;
    const shut = () => { if (d.open) { d.close(); } };
    x.addEventListener('click', shut);
    close.addEventListener('click', shut);
    ok.addEventListener('click', async () => {
      ok.disabled = true; close.disabled = true; msg.textContent = '';
      try { await agree(); agreed = true; shut(); }
      catch (e) { msg.textContent = e && e.message ? e.message : '동의를 저장하지 못했습니다. 다시 시도해 주세요.'; }
      finally { ok.disabled = false; close.disabled = false; }
    });
    d.addEventListener('close', () => { d.remove(); resolve(agreed); });
    document.body.append(d);
    d.showModal();
    close.focus();
  });
}

/**
 * 떠 있는 창용 헬퍼 — member.js 가 없는 화면(셸·런처·시작 안내)에서 같은 모양의 `api()`·`node()`·`say()`·`date()`·`label()`.
 * `/messages`(붙박이)는 member.js 의 것을 그대로 받는다(A-1 — 복제 0). 이 화면들에는 member.js 가 없거나 메신저를 부르지 않는다.
 */
function windowKit(base, status) {
  let csrf = '';
  const say = (text) => { if (status !== null) { status.textContent = text; } };
  async function api(path, method = 'GET', body, join = false, passive = false) {
    const headers = {};
    if (method !== 'GET') { headers['Content-Type'] = 'application/json'; headers['X-CSRF-Token'] = csrf; }
    if (passive) { headers['X-Qaredi-Background'] = '1'; }
    const r = await fetch(base + path, { method, headers, credentials: 'same-origin', cache: 'no-store',
      body: body === undefined ? undefined : JSON.stringify(body) });
    let data;
    try { data = await r.json(); } catch (e) { throw new Error('응답을 받지 못했습니다. 잠시 뒤 다시 시도해 주세요.'); }
    if (!r.ok) {
      if (r.status === 401) { say('로그인이 만료되었습니다. 다시 로그인해 주세요.'); }
      const error = new Error(data && data.next === 'reauth' ? '관리자 인증 시간이 지났습니다. 관리자 인증 갱신 링크에서 다시 인증해 주세요.'
        : (data && typeof data.error === 'string' ? data.error : '요청을 처리하지 못했습니다.'));
      error.status = r.status;
      // 사이트 Q:F-13 P1 — 409 ai_consent_required 의 분류어와 동의 창 글자(notice)를 call() 이 읽는다(member.js 의 api 와 같은 모양)
      error.code = data && typeof data.code === 'string' ? data.code : '';
      error.data = data;
      throw error;
    }
    return data;
  }
  const node = (tag, text, cls) => { const e = document.createElement(tag); if (text !== undefined) { e.textContent = text; } if (cls) { e.className = cls; } return e; };
  const label = (p) => p.display_name || p.username;
  const date = (s) => new Date(s).toLocaleString('ko-KR', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
  return { api, node, say, label, date, setCsrf(v) { csrf = typeof v === 'string' ? v : ''; } };
}

/** 안 읽은 수 — 모든 `[data-message-unread]` 에 같은 값(0 이면 숨김) */
function paintBadge(count) {
  const text = count > 99 ? '99+' : String(count);
  for (const el of document.querySelectorAll('[data-message-unread]')) { el.textContent = text; el.hidden = count === 0; }
}

/** 그 영역 안에서 키보드로 처음 닿는 보이는 것 — 복원으로 열린 창을 닫을 때 초점을 둘 자리(DESIGN 2.17 「본문 첫 초점」) */
function firstFocusable(root) {
  const list = root.querySelectorAll('a[href], button:not([disabled]), summary, input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])');
  for (const el of list) {
    if (el.closest('[hidden]') === null && el.closest('dialog:not([open])') === null && el.getClientRects().length > 0) { return el; }
  }
  return null;
}

let mounted = null;

/**
 * 메신저를 붙인다. 'page' 는 member.js 가 쓰던 헬퍼를 그대로 받고(복제 0), 'window' 는 이 파일의 boot() 가 windowKit 으로 부른다.
 *
 * @param {{api:Function, me:Object, node:Function, say:Function, date:Function, label:Function, mode:'page'|'window'}} options
 * @return {Promise<{mode:string}|null>} 붙이지 않았으면(쓰기 자격 없음 · 역할로 보기 · 창 마크업 없음) null
 */
async function mount(options) {
  if (mounted !== null) { return mounted; }
  const { api, me, node, say, date, label } = options;
  const mode = options.mode;
  if (mode !== 'page' && mode !== 'window') { throw new Error('메신저 화면을 열 수 없습니다. 새로고침해 주세요.'); }
  const isWindow = mode === 'window';
  const owner = me && typeof me.username === 'string' && OWNER_RE.test(me.username) ? me.username : null;
  if (owner === null) { throw new Error('계정 정보를 확인하지 못했습니다. 새로고침해 주세요.'); }
  forgetOthers(owner);
  // 쓰기 자격이 없거나 역할로 보기면 붙이지 않고 이 사람의 창 상태를 지운다. 창은 자격이 **참**일 때만(값이 없으면 붙이지 않는다).
  if ((isWindow ? me.can_community_write !== true : me.can_community_write === false) || me.acting === true) {
    drop(sessionArea(), stateKey(owner)); drop(sessionArea(), POSITION_PREFIX + owner); drop(sessionArea(), SIZE_PREFIX + owner); return null;
  }

  const $ = (id) => document.getElementById(id);
  const dialog = isWindow ? $('messenger-window') : null;
  if (isWindow && (dialog === null || typeof dialog.show !== 'function')) { return null; }
  const base = document.body.dataset.base || '/tbox';
  let loggedOut = false, halted = false, badge = null;
  const position = isWindow ? movableWindow(dialog, owner, () => !loggedOut && !halted) : null;

  /** 「임시저장 못 함」(회색 — 꺼짐, 커뮤니티와 같은 글자·클래스). 입력 칸 아래에 한 번만 만든다. */
  function off(on) {
    if (!on && badge === null) { return; }
    if (badge === null) {
      const field = $('message-text') ? $('message-text').closest('.field') : null;
      if (field === null) { return; }
      badge = node('span');
      badge.setAttribute('data-draft-off', '');
      const b = node('span', '임시저장 못 함', 'badge badge--off');
      b.setAttribute('title', '쓰던 글을 이 브라우저에 남기지 못했습니다. 보내기는 그대로 됩니다');
      badge.append(b); field.append(badge);
    }
    badge.hidden = !on;
  }

  const store = draftStore(owner, off);
  /** 401(세션 만료)을 받은 그 순간 — 열쇠가 이미 메모리에 있으면 쓰던 글을 남긴다(커뮤니티 「401 그 순간」 규칙). 창은 폴링을 멈춘다 */
  const call = (path, method = 'GET', body, join = false, passive = false) => api(path, method, body, join, passive).catch((error) => {
    if (error && error.status === 401 && !loggedOut) { store.flushAll(); saveState(); if (isWindow) { halted = true; schedule(null); } }
    if (isWindow && error && (error.status === 401 || error.status === 403)) { position.cancel(); }
    // 사이트 Q:F-13 P1 — AI 국외 전송 동의가 없어 서버가 409 로 막은 요청(모아 AI · 메시지 AI 도구): 동의 창 → 동의하면 같은 요청을 한 번 다시.
    // 다시 보낸 요청은 call 이 아니라 api 로 보낸다(고리 0). 자동 요청(passive)은 창을 띄우지 않는다.
    if (error && error.status === 409 && error.code === 'ai_consent_required' && !passive) {
      return aiConsentDialog(error.data && error.data.notice, () => api('/api/me/consents', 'POST', { doc: 'ai_overseas' }))
        .then((agreed) => { if (agreed) { return api(path, method, body, join, passive); } throw new Error(AI_CONSENT_DECLINED); });
    }
    throw error;
  });

  let peer = null, generation = 0, oldest = null, latest = 0, loading = false, peopleSeq = 0, lastPeople = null;
  const currentMessages = new Map();
  /** 상대를 고르기 전 대화 머리(서버가 그린 글자) — 이어 열 상대가 없어졌을 때 되돌린다(forgetPeer) */
  const idleHead = { name: $('message-peer-name').textContent, meta: $('message-peer-meta').textContent };
  const metaHome = $('message-peer-meta').parentElement;
  let peerProfile = null;
  const sendingPeers = new Set(), verifying = new Set(), verified = new Set();
  const peerDraft = (id) => store.get('message', id);
  /** 창의 보기 — 'list'(사람·AI 목록) · 'conversation'(대화). 400px 에 두 열을 넣지 않는다(DESIGN 2.17) */
  let view = 'list';
  /** 꾸미기 요청문을 채운 뒤 처음 보낸 AI 질문 하나만 「꾸미기 화면으로」 단추를 단다 */
  let studioArmed = false;

  /** 지금 입력 칸 → 그 상대의 쓰던 글. 글·첨부가 바뀌면 request_id 를 비운다(보낼 때 새로 만든다). */
  function remember() {
    if (!peer) { return; }
    const old = peerDraft(peer), text = $('message-text').value;
    let attachment = assist.attachment(peer);
    if (attachment === null && verifying.has(peer) && old) { attachment = old.attachment_id; }
    const same = old && old.text === text && old.attachment_id === attachment;
    store.put('message', String(peer), { text, attachment_id: attachment, request_id: same ? old.request_id : null });
  }

  const assist = window.MoaMessageAssist({ api: call, node, say,
    context: () => ({ peer, name: peerProfile ? label(peerProfile) : '대화 상대', ready: Boolean(peerProfile), sending: sendingPeers.has(peer) }),
    changed: (p) => { if (p === peer) { remember(); } } });
  /** AI 스레드별 쓰던 글 — member-ai.js 가 쓰던 Map 자리(get/set/delete). set 은 스레드를 바꾸기 직전이라 바로 쓴다. */
  const aiDrafts = {
    get: (k) => { const v = store.get('ai', String(k)); return v ? v.text : undefined; },
    set: (k, text) => { store.put('ai', String(k), { text: String(text) }); store.flush('ai', String(k)); return aiDrafts; },
    delete: (k) => { store.forget('ai', String(k)); return true; },
  };
  const ai = window.MoaAiConversation({ api: call, me, say, node, date, drafts: aiDrafts, onState: () => saveState(),
    activate: () => { remember(); if (peer) { store.flush('message', String(peer)); } peer = null; peerProfile = null; generation++; currentMessages.clear(); assist.sync(); setView('conversation'); people().catch((e) => say(e.message)); },
    // 창에서만: 꾸미기에서 온 질문의 답 → 꾸미기 화면으로(memories.js 가 `qaredi:ai-result` 를 받아 결과 칸에 넣고 꾸미기 창을 다시 연다)
    onResult: isWindow ? (text) => { hide(); window.dispatchEvent(new CustomEvent('qaredi:ai-result', { detail: { text: String(text) } })); } : undefined,
    claim: isWindow ? () => { const armed = studioArmed; studioArmed = false; return armed; } : undefined });

  /** 탭 사이 통로(B-3) — 받은 값은 cleanShare 를 지난 데이터 */
  const share = shareChannel(owner, receive);

  /** 지금 창 상태 — 번호·스크롤만 */
  function snapshot() {
    const list = $('message-list');
    const fromBottom = Math.max(0, Math.min(MAX_SCROLL, Math.round(list.scrollHeight - list.scrollTop - list.clientHeight)));
    if (ai.active) { const t = ai.getState().thread; return { kind: 'ai', peer: null, thread: isNumber(t) ? t : null, fromBottom }; }
    if (isNumber(peer)) { return { kind: 'person', peer, thread: null, fromBottom }; }
    return null;
  }
  /**
   * 상태를 쓴다. 창은 지금 열림 그대로, 붙박이 페이지는 있던 값을 두고(머무는 동안) 떠날 때 `open:true`(B-3 — 다음 화면에서 창이 이어 열린다).
   * @param {boolean=} open 이 값으로 쓴다(창을 닫기 직전 · 페이지를 떠날 때)
   */
  function saveState(open) {
    if (loggedOut) { return; }
    const prev = readState(owner);
    const now = snapshot();
    const isOpen = typeof open === 'boolean' ? open : (isWindow ? dialog.open : (prev ? prev.open : false));
    if (now === null) {
      // 아직 아무 대화도 열지 않았다 — 이어 열 자리(번호)는 그대로 두고 열림만 바꾼다
      if (prev !== null && prev.open !== isOpen) { writeState(owner, Object.assign({}, prev, { open: isOpen })); }
      return;
    }
    // 닫힌 창은 목록 높이가 0 이라 스크롤 값이 0 으로 읽힌다 — 같은 자리면 있던 값을 둔다
    if (isWindow && !dialog.open && prev !== null && prev.kind === now.kind && prev.peer === now.peer && prev.thread === now.thread) { now.fromBottom = prev.fromBottom; }
    writeState(owner, Object.assign({ open: isOpen }, now));
  }
  function applyScroll(fromBottom) {
    const list = $('message-list');
    list.scrollTop = Math.max(0, list.scrollHeight - list.clientHeight - fromBottom);
  }

  /** 사람 목록 그리기 — 서버 응답이든 다른 탭이 나눈 것이든 normPerson/cleanPerson 을 지난 값만 */
  function paintPeople(list) {
    $('message-people').replaceChildren();
    for (const p of list) {
      const b = node('button', undefined, 'moa-person'); b.type = 'button'; b.setAttribute('aria-pressed', String(peer === p.id));
      b.append(node('span', label(p).slice(0, 1), 'moa-avatar'), node('span', label(p)), node('small', p.username));
      if (p.unread > 0) { b.append(node('span', String(p.unread), 'moa-unread')); }
      b.addEventListener('click', () => { open(p.id).catch((e) => say(e.message)); });
      $('message-people').append(b);
    }
    if (!list.length) { $('message-people').append(node('p', '찾는 구성원이 없습니다.', 'moa-muted')); }
  }
  /** 검색어 없는 사람 목록 = 안 읽은 수의 근거. 배지를 그리고 다른 탭에 나눈다 */
  function shareUnread(list) {
    lastPeople = list; freshAt = Date.now();
    const count = Math.min(MAX_COUNT, list.reduce((sum, p) => sum + p.unread, 0));
    paintBadge(count);
    share.post('people', null, { people: list });
    share.post('unread', null, { count });
  }
  async function people() {
    const ticket = ++peopleSeq, q = $('message-search').value;
    const data = await call('/api/messages?q=' + encodeURIComponent(q), 'GET', undefined, false, true);
    if (ticket !== peopleSeq) { return; }
    const list = (Array.isArray(data.people) ? data.people : []).map(normPerson).filter((p) => p !== null).slice(0, MAX_PEOPLE);
    paintPeople(list);
    if (q === '') { shareUnread(list); }
  }
  /** 창이 닫혀 있을 때 — 안 읽은 수만(기존 목록 창구, 새 창구 0) */
  async function unread() {
    const data = await call('/api/messages?q=', 'GET', undefined, false, true);
    shareUnread((Array.isArray(data.people) ? data.people : []).map(normPerson).filter((p) => p !== null).slice(0, MAX_PEOPLE));
  }
  function render(scroll) {
    const list = $('message-list'), previous = list.scrollHeight, previousTop = list.scrollTop; list.replaceChildren();
    for (const m of [...currentMessages.values()].sort((a, b) => Number(a.id) - Number(b.id))) {
      const mine = Number(m.sender_id) === Number(me.member_id);
      const bubble = node('article', undefined, 'moa-bubble' + (mine ? ' moa-bubble--mine' : ''));
      bubble.append(node('strong', mine ? '나 · ' + me.username : '상대 · ' + (peerProfile?.username || '대화 상대'), 'moa-message-author'), node('p', m.body));
      if (typeof m.ai_attachment_id === 'string' && UUID_RE.test(m.ai_attachment_id)) {
        const link = node('a', undefined, 'moa-message-image'), image = node('img');
        link.href = base + '/api/messages/attachments/' + m.ai_attachment_id; link.target = '_blank'; link.rel = 'noopener noreferrer'; link.setAttribute('aria-label', 'AI로 만든 이미지 크게 보기');
        image.src = link.href; image.alt = '대화에 첨부한 AI 생성 이미지'; image.loading = 'lazy'; link.append(image); bubble.append(link, node('small', 'AI로 만든 이미지'));
      }
      const footer = node('div', undefined, 'moa-bubble-footer');
      footer.append(node('small', date(m.sent_at) + (mine ? (m.read_at ? ' · 읽음' : ' · 전송됨') : '')));
      if (!mine) { const translate = node('button', '번역', 'moa-message-translate'); translate.type = 'button'; translate.setAttribute('aria-label', '이 메시지 번역하기'); translate.addEventListener('click', () => assist.open(m)); footer.append(translate); }
      bubble.append(footer); list.append(bubble);
    }
    if (!currentMessages.size) { list.append(node('p', '첫 인사를 건네 보세요.', 'moa-empty')); }
    if (scroll) { list.scrollTop = list.scrollHeight; } else if (loading) { list.scrollTop = previousTop + list.scrollHeight - previous; } else { list.scrollTop = previousTop; }
  }
  async function load(older = false) {
    if (ai.active) { await ai.load(older); return; }
    // 창이 닫혀 있으면 대화를 읽지 않는다 — 읽음 처리(PATCH)는 사람이 보고 있을 때만
    if (!peer || document.hidden || (isWindow && !dialog.open)) { return; }
    const ticket = generation, id = peer; const list = $('message-list'), nearBottom = list.scrollHeight - list.scrollTop - list.clientHeight < 100;
    const data = await call('/api/messages/' + id + (older && oldest ? '?before=' + oldest : ''), 'GET', undefined, false, true);
    if (ticket !== generation) { return; }
    peerProfile = data.peer; $('message-send').disabled = sendingPeers.has(id); assist.sync();
    $('message-peer-name').textContent = label(data.peer) + ' 님과의 대화'; $('message-peer-meta').textContent = '대화 상대 @' + data.peer.username + ' · 1:1 대화';
    let changed = false;
    for (const m of data.messages) { const prior = currentMessages.get(Number(m.id)); if (!prior || prior.read_at !== m.read_at) { changed = true; } currentMessages.set(Number(m.id), m); latest = Math.max(latest, Number(m.id)); }
    if (data.messages.length) { oldest = Math.min(...currentMessages.keys()); if (older || currentMessages.size <= 60) { $('message-older').hidden = !data.has_more; } }
    if (changed || !currentMessages.size) { render(!older && nearBottom); }
    if (!older) {
      const shared = data.messages.map(normMessage).filter((m) => m !== null).slice(-MAX_SHARED_MESSAGES);
      share.post('messages', id, { messages: shared });
    }
    if (latest && !document.hidden && ticket === generation) { await call('/api/messages/' + id, 'PATCH', { through: latest }, false, true); share.post('read', id, {}); markRead(id); }
  }
  /** 복원한 쓰던 글의 첨부 이미지 — 서버 목록에 아직 있는(완료·이 상대·내 것) 것만 다시 붙이고, 없으면 버린다 */
  function reattach(id) {
    const draft = peerDraft(id);
    if (!draft || !draft.attachment_id || assist.attachment(id) !== null || verified.has(id) || verifying.has(id)) { return; }
    const wanted = draft.attachment_id;
    verifying.add(id);
    call('/api/messages/assist?peer=' + id, 'GET', undefined, false, true).then((data) => {
      const job = data && Array.isArray(data.jobs) ? data.jobs.find((j) => j && j.id === wanted && j.action === 'image' && j.state === 'complete') : null;
      return job ? assist.restore(id, job.id, job.image_url) : false;
    }).catch(() => false).then((ok) => {
      verifying.delete(id); verified.add(id);
      const now = peerDraft(id);
      if (!ok && now && now.attachment_id === wanted) { store.put('message', String(id), { text: now.text, attachment_id: null, request_id: null }); }
    });
  }
  /** 상대를 연다. restoring=true(이어 열기)면 초점을 옮기지 않는다. 열었으면 true · 이어 열 상대가 없으면(404) 404 · 그 밖 false. */
  async function open(id, restoring = false) {
    remember(); if (peer) { store.flush('message', String(peer)); }
    ai.close(); peer = id; peerProfile = null; generation++; const ticket = generation;
    studioArmed = false; setView('conversation');
    $('message-peer-name').textContent = '대화를 불러오는 중…'; $('message-peer-meta').textContent = '';
    currentMessages.clear(); oldest = null; latest = 0; $('message-list').replaceChildren();
    const draft = peerDraft(id);
    $('message-text').value = draft ? draft.text : ''; $('message-text').disabled = false; $('message-send').disabled = true;
    $('message-older').hidden = true; assist.sync(); say('');
    try {
      await load(); if (ticket !== generation) { return false; }
      await people(); if (ticket !== generation) { return false; }
      $('message-send').disabled = sendingPeers.has(id); assist.sync();
      if (!restoring) { $('message-text').focus(); }
      saveState(); reattach(id);
      return true;
    } catch (e) {
      if (ticket !== generation) { return false; }
      if (restoring && e.status === 404) { return 404; }
      say(e.message); return false;
    }
  }
  /**
   * 이어 열 상대가 이제 없다(404 — 보정 D-1). 그 번호를 잊고 처음 모습(상대 없음 · 입력 칸 잠금 · 목록)으로 되돌린다.
   * 남겨 두면 떠날 때 saveState() 가 그 번호를 다시 써서 화면마다 같은 404 를 또 친다.
   */
  function forgetPeer() {
    peer = null; peerProfile = null; generation++;
    currentMessages.clear(); oldest = null; latest = 0; $('message-list').replaceChildren();
    $('message-peer-name').textContent = idleHead.name; $('message-peer-meta').textContent = idleHead.meta;
    $('message-text').value = ''; $('message-text').disabled = true; $('message-send').disabled = true; $('message-older').hidden = true;
    assist.sync(); setView('list');
  }

  /* ─── 탭 사이로 받은 것(B-3) — 전부 cleanShare 를 지난 데이터 ─── */
  /** 다른 탭이 이 상대의 대화를 읽었다 — 같은 상대를 보고 있으면 합쳐 그린다(읽음 처리는 그 탭이 했다) */
  function mergeShared(id, list) {
    if (ai.active || peer !== id || peerProfile === null) { return; }
    let changed = false;
    for (const m of list) {
      if (oldest !== null && m.id < oldest) { continue; }
      const prior = currentMessages.get(m.id);
      if (!prior || prior.read_at !== m.read_at) { changed = true; currentMessages.set(m.id, m); latest = Math.max(latest, m.id); }
    }
    if (changed) { const l = $('message-list'); render(l.scrollHeight - l.scrollTop - l.clientHeight < 100); }
  }
  /** 어느 탭이든 이 상대의 글을 읽었다 — 배지와 목록의 그 사람 숫자를 0 으로 */
  function markRead(id) {
    if (lastPeople === null) { return; }
    let touched = false;
    lastPeople = lastPeople.map((p) => { if (p.id === id && p.unread !== 0) { touched = true; return Object.assign({}, p, { unread: 0 }); } return p; });
    if (!touched) { return; }
    paintBadge(Math.min(MAX_COUNT, lastPeople.reduce((sum, p) => sum + p.unread, 0)));
    if ((!isWindow || dialog.open) && $('message-search').value === '') { paintPeople(lastPeople); }
  }
  /** 다른 탭이 이 상대에게 보냈다 — 같은 글(요약이 같다)이 여기 쓰던 글로 남아 있으면 지운다(M-4: 보낸 글이 다른 탭 초안으로 남지 않게) */
  async function onSent(id, digest) {
    const draft = peerDraft(id);
    if (!draft || draft.text === '') { return; }
    const mine = await digestHex(draft.text);
    if (mine === null || mine !== digest || peerDraft(id) !== draft) { return; }
    store.forget('message', String(id));
    if (peer === id && !ai.active && $('message-text').value === draft.text) { $('message-text').value = ''; assist.sync(); }
  }
  function receive(msg) {
    if (loggedOut) { return; }
    if (msg.kind === 'unread') { paintBadge(msg.data.count); return; }
    if (msg.kind === 'people') {
      lastPeople = msg.data.people;
      paintBadge(Math.min(MAX_COUNT, lastPeople.reduce((sum, p) => sum + p.unread, 0)));
      if ((!isWindow || dialog.open) && $('message-search').value === '') { paintPeople(lastPeople); }
      return;
    }
    if (msg.kind === 'messages') { mergeShared(msg.peer, msg.data.messages); return; }
    if (msg.kind === 'read') { markRead(msg.peer); return; }
    onSent(msg.peer, msg.data.digest).catch(() => null);
  }

  function composerFocus() {
    const input = $('message-text'), send = $('message-send');
    let restore = document.activeElement === input || document.activeElement === send;
    const moved = (event) => { if (event.target !== input && event.target !== send) restore = false; };
    document.addEventListener('focusin', moved);
    document.addEventListener('pointerdown', moved);
    return (current) => {
      document.removeEventListener('focusin', moved);
      document.removeEventListener('pointerdown', moved);
      if (current && restore && !input.disabled && input.getClientRects().length && !document.hidden) input.focus({ preventScroll: true });
    };
  }
  $('message-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    if (Array.from($('message-text').value).length > 4000) { say('메시지는 4,000자까지 보낼 수 있습니다. 내용을 줄여 주세요.'); return; }
    if (ai.active) { const restore = composerFocus(); try { await ai.send(); } finally { restore(ai.active); } return; }
    if (!peer || !peerProfile || $('message-send').disabled || sendingPeers.has(peer)) { return; }
    const id = peer, ticket = generation, text = $('message-text').value, attachment = assist.attachment(id);
    if (!text.trim() && !attachment) { return; }
    remember();
    const draft = peerDraft(id);
    // 같은 글·같은 첨부를 다시 보내면 같은 request_id(서버가 한 행으로 묶는다) — 글이 바뀌었으면 새로.
    const request = draft && draft.text === text && draft.attachment_id === attachment && draft.request_id ? draft.request_id : uuid();
    store.put('message', String(id), { text, attachment_id: attachment, request_id: request });
    const restore = composerFocus();
    sendingPeers.add(id); $('message-send').disabled = true; assist.sync();
    try {
      await call('/api/messages/' + id, 'POST', { text, attachment_id: attachment, request_id: request });
      const sent = peerDraft(id);
      if (sent && sent.text === text && sent.attachment_id === attachment) { store.forget('message', String(id)); }
      digestHex(text).then((d) => { if (d !== null) { share.post('sent', id, { digest: d }); } });
      assist.sent(id, attachment);
      if (peer !== id) { return; }
      if ($('message-text').value === text) { $('message-text').value = ''; }
      const refreshGeneration = generation; await load(); if (peer !== id || refreshGeneration !== generation) { return; }
      $('message-list').scrollTop = $('message-list').scrollHeight; await people(); if (peer === id && refreshGeneration === generation) { say(''); }
    } catch (error) {
      // 서버가 답한 4xx 는 행을 만들지 않았다 — 다음 시도는 새 request_id. 5xx·네트워크(불확실)는 같은 값을 남긴다.
      if (error.status && error.status < 500) {
        const now = peerDraft(id);
        if (now && now.request_id === request) { store.put('message', String(id), { text: now.text, attachment_id: now.attachment_id, request_id: null }); }
      }
      if (peer === id && ticket === generation) { say(error.message); }
    } finally { sendingPeers.delete(id); if (peer === id) { $('message-send').disabled = !peerProfile; } assist.sync(); restore(peer === id && ticket === generation && !ai.active); }
  });
  $('message-text').addEventListener('input', () => {
    if (ai.active) { store.put('ai', String(ai.getState().thread || 0), { text: $('message-text').value }); } else { remember(); }
  });
  $('message-text').addEventListener('keydown', (e) => { if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); if (!$('message-send').disabled) { $('message-form').requestSubmit(); } } });
  $('message-older').addEventListener('click', async () => { if (loading) { return; } loading = true; $('message-older').disabled = true; try { await load(true); } catch (e) { say(e.message); } finally { loading = false; $('message-older').disabled = false; } });
  let searchTimer; $('message-search').addEventListener('input', () => { clearTimeout(searchTimer); searchTimer = setTimeout(() => people().catch((e) => say(e.message)), 250); });
  let scrollTimer = null;
  $('message-list').addEventListener('scroll', () => { window.clearTimeout(scrollTimer); scrollTimer = window.setTimeout(saveState, SCROLL_SETTLE_MS); }, { passive: true });

  /* ─── 떠 있는 창(B-1~B-3) — 'page' 에서는 아래 함수들이 아무것도 하지 않는다 ─── */
  let timer = null, ticking = false, again = false, started = false, opener = null, freshAt = 0;
  // 대표는 **닫힌 창**의 배지 폴링만 맡는다(열린 창은 대표와 상관없이 8초). 대표가 되면 곧바로 한 번(방금 읽었으면 30초 뒤), 놓으면 멈춘다
  const lead = isWindow ? leadership(owner, (on) => {
    if (dialog.open) { return; }
    schedule(on ? (Date.now() - freshAt > 5000 ? 0 : CLOSED_POLL_MS) : null);
  }) : null;

  function setView(next) {
    if (!isWindow) { return; }
    view = next;
    dialog.setAttribute('data-view', next);
    const metaParent = ai.active ? $('message-ai-notice-details') : metaHome;
    if ($('message-peer-meta').parentElement !== metaParent) { metaParent.appendChild($('message-peer-meta')); }
    $('messenger-back').hidden = next !== 'conversation';
  }
  function markOpeners(open) {
    for (const b of document.querySelectorAll('[data-messenger-open]')) { b.setAttribute('aria-expanded', String(open)); }
  }
  /** 다음 폴링 — null 이면 멈춘다 */
  function schedule(ms) {
    if (!isWindow) { return; }
    window.clearTimeout(timer); timer = null;
    if (ms !== null && !loggedOut && !halted) { timer = window.setTimeout(tick, ms); }
  }
  /**
   * 폴링 한 번(R-117) — 열림+보임: 현재 대화 + 사람 목록(8초) · 닫힘+보임: 대표 탭만 안 읽은 수(30초) · 숨김: 아무것도 하지 않는다.
   * 모든 요청은 `call(…, passive=true)` — 세션을 늘리지 않는다(U-3).
   */
  async function tick() {
    timer = null;
    if (ticking) { again = true; return; }
    if (loggedOut || halted || document.hidden) { return; }
    const isOpen = dialog.open;
    if (!isOpen && !lead.leader) { return; }
    ticking = true;
    try {
      if (isOpen) {
        if (view === 'conversation') { await load(); }
        await people();
        if (lead.leader && $('message-search').value !== '') { await unread(); }
      } else {
        await unread();
      }
    } catch (e) {
      if (dialog.open && !halted) { say(e.message); }
    } finally {
      ticking = false;
    }
    if (again) { again = false; schedule(0); return; }
    if (!document.hidden) { schedule(dialog.open ? POLL_MS : (lead.leader ? CLOSED_POLL_MS : null)); }
  }
  /** 복원으로 열 때 show() 가 창 안 첫 단추로 초점을 옮겼으면 되돌린다(복원은 조용히 — DESIGN 2.17) */
  function keepFocus(before) {
    if (!dialog.contains(document.activeElement)) { return; }
    if (before && before !== document.body && before.isConnected && !dialog.contains(before) && typeof before.focus === 'function') { before.focus({ preventScroll: true }); }
    if (dialog.contains(document.activeElement)) { document.activeElement.blur(); }
  }
  function focusInside() {
    if (view === 'conversation' && !$('message-text').disabled) { $('message-text').focus(); return; }
    if (view === 'list') { $('message-search').focus(); }
  }
  /** 처음 열 때 한 번 — 쓰던 글을 풀고, 이어 열 자리(상대·AI 스레드·스크롤)를 연다. 없으면 목록 */
  async function start(user) {
    await store.restore();
    const saved = readState(owner);
    let opened = false;
    if (saved !== null && saved.kind === 'person' && saved.peer !== Number(me.member_id)) {
      const result = await open(saved.peer, true);
      if (result === true) { applyScroll(saved.fromBottom); saveState(); opened = true; }
      else if (result === 404) { forgetPeer(); drop(sessionArea(), stateKey(owner)); }
    } else if (saved !== null && saved.kind === 'ai') {
      if (await ai.setState({ thread: saved.thread })) { applyScroll(saved.fromBottom); saveState(); }
      else if (ai.active && peer === null) { drop(sessionArea(), stateKey(owner)); await ai.open(); }
      opened = ai.active;
    }
    if (!opened) { setView('list'); await people(); }
    if (user) { focusInside(); }
  }
  /**
   * 창을 띄운다(`dialog.show()` — 비모달). user=true(사람이 눌렀다)면 초점을 창 안으로, false(복원)면 초점을 건드리지 않는다.
   * @param {Element|null} by 연 단추 — 닫으면 초점을 돌려준다
   */
  async function openWindow(user, by) {
    if (!isWindow || loggedOut) { return false; }
    const before = document.activeElement, wasOpen = dialog.open;
    if (!wasOpen) { dialog.show(); }
    position.apply();
    if (!user) { keepFocus(before); }
    if (user && by) { opener = by; }
    markOpeners(true);
    if (!wasOpen) { saveState(true); }
    if (halted) { return true; }   // 세션이 끝났다 — 창에는 「로그인이 만료되었습니다」 한 줄만, 요청은 더 하지 않는다
    if (!started) {
      started = true;
      try { await start(user); } catch (e) { say(e.message); }
      schedule(POLL_MS);   // start() 가 방금 대화와 목록을 읽었다
    } else {
      if (user) { focusInside(); }
      schedule(0);         // 다시 열었다 — 곧바로 한 번
    }
    return true;
  }
  /** 닫는다 — ✕ · 「닫기」 · Esc 모두 여기. 상태(상대·초안·스크롤)는 남고 `open:false`. 초점은 연 단추로, 복원으로 열렸으면 본문 첫 초점으로 */
  function hide() {
    if (!isWindow || !dialog.open) { return; }
    const inside = dialog.contains(document.activeElement);
    position.cancel();
    store.flushAll(); saveState(false);
    dialog.close();
    markOpeners(false);
    if (inside) {
      const back = opener && opener.isConnected && opener.getClientRects().length > 0 ? opener : firstFocusable(document.querySelector('main') || document.body);
      if (back !== null) { back.focus({ preventScroll: true }); }
    }
    opener = null;
    schedule(lead.leader ? CLOSED_POLL_MS : null);
  }
  /** 꾸미기 → 모아 AI(B-4) — 창을 AI 칸으로 열고 요청문을 입력 칸에 미리 채운다. 보내기는 사람이 누른다(자동 전송 0) */
  async function askAi(prompt) {
    if (!(await openWindow(true, null))) { return; }
    if (!ai.active) { await ai.open(); }
    setView('conversation');
    const box = $('message-text');
    let value = box.value ? box.value + '\n\n' + prompt : prompt;
    const chars = Array.from(value);
    if (chars.length > AI_PROMPT_MAX) { value = chars.slice(0, AI_PROMPT_MAX).join(''); say('요청문이 4,000자를 넘어 4,000자까지만 넣었어요. 확인한 뒤 보내 주세요.'); }
    box.value = value;
    box.dispatchEvent(new Event('input', { bubbles: true }));
    studioArmed = true;
    if (!box.disabled) { box.focus(); }
  }

  if (isWindow) {
    document.addEventListener('click', (e) => {
      const b = e.target instanceof Element ? e.target.closest('[data-messenger-open]') : null;
      if (b === null || loggedOut) { return; }
      e.preventDefault();
      // 카페 C6 (DESIGN 2.30 「메신저 단추」) — 여는 단추는 열고 닫는다(aria-expanded 와 같은 뜻). 닫으면 초점은 그 단추에 그대로
      if (dialog.open) { hide(); return; }
      openWindow(true, b).catch((err) => say(err.message));
    });
    for (const b of dialog.querySelectorAll('[data-messenger-close]')) { b.addEventListener('click', () => hide()); }
    $('messenger-back').addEventListener('click', () => {
      remember(); if (peer) { store.flush('message', String(peer)); }
      setView('list');
      people().catch((e) => say(e.message));
      const pressed = document.querySelector('#message-people [aria-pressed="true"]');
      (pressed || $('message-ai')).focus();
    });
    // 비모달 dialog 는 Esc 로 `cancel` 이 오지 않는다 — 초점이 창 안일 때만 닫는다(IME 조합 중 제외)
    dialog.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && !e.isComposing && e.target instanceof Node && dialog.contains(e.target)) { e.preventDefault(); hide(); }
    });
    window.addEventListener('qaredi:messenger', (e) => {
      const d = e.detail;
      if (plainObject(d) && exactKeys(d, 'mode,prompt') && d.mode === 'ai' && typeof d.prompt === 'string') { askAi(d.prompt).catch((err) => say(err.message)); }
    });
    window.addEventListener('pageshow', (e) => { if (e.persisted && !document.hidden) { lead.want(); schedule(0); } });
  }

  document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
      if (!loggedOut) { store.flushAll(); saveState(); }
      if (isWindow) { schedule(null); lead.drop(); }
      return;
    }
    if (isWindow && !loggedOut && !halted) { lead.want(); if (dialog.open || lead.leader) { schedule(0); } }
  });
  // 붙박이 페이지를 떠날 때는 `open:true` — 다음 화면에서 창이 같은 대화로 이어 열린다(B-3)
  window.addEventListener('pagehide', () => {
    if (!loggedOut) { store.flushAll(); saveState(isWindow ? undefined : true); }
    if (isWindow) { schedule(null); lead.drop(); }
  });
  // 로그아웃(app.js 가 지우기 직전에 알린다 — 이 화면에 app.js 가 없을 때도 같은 이벤트면 같게): 다시 쓰지 않고 이 사람 몫을 지운다.
  document.addEventListener('qaredi:logout', () => {
    if (position !== null) { position.destroy(); }
    loggedOut = true; window.clearTimeout(scrollTimer); store.shutdown(); forgetMine(owner);
    share.close();
    if (isWindow) { schedule(null); lead.stop(); if (dialog.open) { dialog.close(); } markOpeners(false); paintBadge(0); }
  });

  mounted = Object.freeze({ mode, poll: isWindow ? lead.path : 'page' });

  if (isWindow) {
    setView('list');
    const saved = readState(owner);
    if (saved !== null && saved.open) { await openWindow(false, null); }
    // 첫 배지 — 이 화면에서 한 번은 직접 읽는다(대표가 아니어도). 그 뒤 닫힌 창은 대표 탭만 30초마다
    if (!document.hidden && !dialog.open) { freshAt = Date.now(); unread().catch(() => null); }
    lead.want();
    if (lead.leader && !dialog.open) { schedule(CLOSED_POLL_MS); }
    return mounted;
  }

  const restoring = store.restore();
  await people();
  await restoring;
  const saved = readState(owner);
  if (saved !== null && saved.kind === 'person' && saved.peer !== Number(me.member_id)) {
    const result = await open(saved.peer, true);
    if (result === true) { applyScroll(saved.fromBottom); saveState(); }
    // 이어 열 상대가 이제 대화할 수 없는 사람이면(404) 상태를 비우고 페이지의 처음 모습(모아 AI)으로.
    else if (result === 404) { drop(sessionArea(), stateKey(owner)); await ai.open(); }
  } else if (saved !== null && saved.kind === 'ai') {
    if (await ai.setState({ thread: saved.thread })) { applyScroll(saved.fromBottom); saveState(); }
    else if (ai.active && peer === null) { drop(sessionArea(), stateKey(owner)); await ai.open(); }
  } else {
    await ai.open();
  }
  async function poll() { if (!document.hidden) { try { await load(); await people(); } catch (e) { say(e.message); } } setTimeout(poll, POLL_MS); }
  setTimeout(poll, POLL_MS);
  return mounted;
}

window.MoaMessenger = Object.freeze({ mount });

/**
 * 떠 있는 창 — 창 마크업(`src/views/messenger_window.php`, 쓰기 자격이 있고 역할로 보기가 아닐 때만 서버가 그린다)이 있는
 * 화면에서 스스로 붙는다. `/messages`(붙박이)는 member.js 가 'page' 로 부르므로 여기서 붙지 않는다.
 * `/api/me` 는 셸이면 app.js 가 이미 부른 한 번(`window.qarediMe`)을 쓰고, 없는 화면(런처·시작 안내)에서만 한 번 부른다.
 */
async function boot() {
  const dialog = document.getElementById('messenger-window');
  if (dialog === null || document.body.dataset.memberPage === 'messages') { return; }
  if (typeof window.MoaAiConversation !== 'function' || typeof window.MoaMessageAssist !== 'function') { return; }
  const kit = windowKit(document.body.dataset.base || '/tbox', document.getElementById('messenger-window-status'));
  let me = null;
  try {
    if (typeof window.qarediMe === 'function') {
      const r = await window.qarediMe();
      me = r && r.status === 200 ? r.me : null;
    } else {
      me = await kit.api('/api/me', 'GET', undefined, false, true);
    }
  } catch (e) {
    me = null;
  }
  // 첫 로그인 절차(비밀번호 바꾸기·2단계 등록)가 남았으면 붙이지 않는다 — 메신저 창구가 그 관문에서 막힌다
  if (!plainObject(me) || (typeof me.next === 'string' && me.next !== '')) { return; }
  kit.setCsrf(me.csrf_token);
  try {
    await mount({ api: kit.api, me, node: kit.node, say: kit.say, date: kit.date, label: kit.label, mode: 'window' });
  } catch (e) {
    kit.say(e.message);
  }
}

boot();
