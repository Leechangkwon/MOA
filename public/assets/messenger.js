/**
 * public/assets/messenger.js — 모아 메신저 한 벌 (사이트 Q:F-11 「어느 화면에서나 이어지는 메신저」 지시서 A — M-A 바닥)
 *
 * 요구사항: docs/qf11-floating-messenger-directive.md 「지시서 A」 A-1~A-4 · 기획 사이트 docs/persistent-messenger-plan.md 4.1·4.2·4.4·5장 ·
 *           SECURITY R-116 · R-118 · C:R-10 · API.md 「모아 초대 가입·메신저」 · 커뮤니티 ARCHITECTURE 「임시저장」 절.
 *
 * 무엇을 하나
 *   `/messages` 페이지(붙박이 'page')와 B 의 떠 있는 창('window')이 같이 쓰는 메신저 화면 코드. 지금은 'page' 만 있다.
 *   member.js 의 `messenger()` 를 그대로 옮기고 셋을 더했다.
 *   ① 창 상태(sessionStorage `qaredi-messenger:v1:<아이디>`) — 상대·AI 스레드 **번호**·스크롤만(본문·이름 0). 같은 탭에서 다시 열면 이어 연다.
 *   ② 쓰던 글(localStorage, 커뮤니티 임시저장 `drafts.js` 와 같은 열쇠·AES-GCM·AAD·7일·20개) —
 *      `qaredi-draft:v1:<아이디>:message:<상대 번호>` `{text, attachment_id, request_id}` · `…:ai:<스레드 번호 또는 0>` `{text}`.
 *      열쇠를 못 받으면 **쓰지 않는다**(평문 대체 0) — 입력 칸 아래 배지 「임시저장 못 함」.
 *   ③ 문자 메시지 `request_id`(UUIDv4) — 상대별 쓰던 글에 붙여 두고, 글·첨부가 바뀌면 새로. 결과가 불확실한 실패 뒤 같은 글은 같은 UUID.
 *
 * 규칙
 *   · 화면 출력은 `node()`(createElement + textContent)만. 저장소에서 읽은 값은 **데이터**로만 — 번호는 정규식·범위 검사 뒤에 쓴다.
 *   · 다른 사람 접두의 값은 읽지 않는다 — 이름으로만 골라 지운다(계정이 바뀐 탭).
 *   · 쓰던 글은 서버로 보내지 않는다. AES 열쇠는 drafts.js 모듈 안에만(여기·전역·쿠키·저장소 0).
 *   · 외부 주소 0 · 콘솔 0 · 새 창구 0(열쇠는 기존 `GET /community/api/me/draft-key`).
 */
import { createDrafts } from './community/drafts.js?v=20bf0dd55320';

/** 창 상태 열쇠 접두 — 뒤에 진짜 사용자 아이디(`/api/me` 의 username — 역할로 보기 중에도 실제 계정) */
const STATE_PREFIX = 'qaredi-messenger:v1:';
/** 임시저장 접두(drafts.js · app.js clearMyDrafts 와 같은 값) */
const DRAFT_PREFIX = 'qaredi-draft:v1:';
/** 아이디 — `Auth::USERNAME_RE` 와 같은 글자 집합(`:` 없음) */
const OWNER_RE = /^[a-z0-9_.-]{3,64}$/;
/** 다른 사람의 메신저 쓰던 글 이름(값은 읽지 않는다) */
const OTHER_MESSENGER_DRAFT_RE = /^qaredi-draft:v1:([a-z0-9_.-]{3,64}):(?:message|ai):/;
const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;
const STATE_KEYS = ['at', 'kind', 'open', 'peer', 'scroll', 'thread', 'v'];
const MAX_NUMBER = 9999999999;
const MAX_SCROLL = 100000000;
/** 열린 대화 + 사람 목록 다시 읽기(member.js 그대로) */
const POLL_MS = 8000;
/** 쓰던 글 암호화 저장 묶음 */
const DRAFT_DELAY_MS = 1000;
/** 목록 스크롤이 멈춘 뒤 상태 쓰기 */
const SCROLL_SETTLE_MS = 300;
/** 열쇠 창구가 답하지 않을 때 기다리는 한도 — 넘으면 「못 받음」(다시 조르지 않는다) */
const KEY_TIMEOUT_MS = 10000;

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
  const l = localArea();
  for (const n of names(l)) {
    const m = n.match(OTHER_MESSENGER_DRAFT_RE);
    if (m !== null && m[1] !== owner) { drop(l, n); }
  }
}

/** 로그아웃 — 이 사람의 창 상태와 쓰던 글 접두 전부(app.js clearMyDrafts 와 같은 범위 — 값을 읽지 않는다) */
function forgetMine(owner) {
  drop(sessionArea(), stateKey(owner));
  const l = localArea(), mine = DRAFT_PREFIX + owner + ':';
  for (const n of names(l)) { if (n.indexOf(mine) === 0) { drop(l, n); } }
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

let mounted = null;

/**
 * 메신저를 붙인다. member.js 가 쓰던 헬퍼를 그대로 받는다(복제 0).
 *
 * @param {{api:Function, me:Object, node:Function, say:Function, date:Function, label:Function, mode:'page'|'window'}} options
 * @return {Promise<{mode:string}|null>} 붙이지 않았으면(쓰기 자격 없음 · 역할로 보기) null
 */
async function mount(options) {
  if (mounted !== null) { return mounted; }
  const { api, me, node, say, date, label } = options;
  const mode = options.mode;
  if (mode !== 'page') { throw new Error('메신저 화면을 열 수 없습니다. 새로고침해 주세요.'); }
  const owner = me && typeof me.username === 'string' && OWNER_RE.test(me.username) ? me.username : null;
  if (owner === null) { throw new Error('계정 정보를 확인하지 못했습니다. 새로고침해 주세요.'); }
  forgetOthers(owner);
  if (me.can_community_write === false || me.acting === true) { drop(sessionArea(), stateKey(owner)); return null; }

  const $ = (id) => document.getElementById(id);
  const base = document.body.dataset.base || '/tbox';
  let loggedOut = false, badge = null;

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
  /** 401(세션 만료)을 받은 그 순간 — 열쇠가 이미 메모리에 있으면 쓰던 글을 남긴다(커뮤니티 「401 그 순간」 규칙) */
  const call = (path, method = 'GET', body, join = false, passive = false) => api(path, method, body, join, passive).catch((error) => {
    if (error && error.status === 401 && !loggedOut) { store.flushAll(); saveState(); }
    throw error;
  });

  let peer = null, generation = 0, oldest = null, latest = 0, loading = false, peopleSeq = 0;
  const currentMessages = new Map();
  let peerProfile = null;
  const sendingPeers = new Set(), verifying = new Set(), verified = new Set();
  const peerDraft = (id) => store.get('message', id);

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
    activate: () => { remember(); if (peer) { store.flush('message', String(peer)); } peer = null; peerProfile = null; generation++; currentMessages.clear(); assist.sync(); people().catch((e) => say(e.message)); } });

  /** 지금 창 상태 — 번호·스크롤만 */
  function snapshot() {
    const list = $('message-list');
    const fromBottom = Math.max(0, Math.min(MAX_SCROLL, Math.round(list.scrollHeight - list.scrollTop - list.clientHeight)));
    if (ai.active) { const t = ai.getState().thread; return { kind: 'ai', peer: null, thread: isNumber(t) ? t : null, fromBottom }; }
    if (isNumber(peer)) { return { kind: 'person', peer, thread: null, fromBottom }; }
    return null;
  }
  function saveState() {
    if (loggedOut) { return; }
    const now = snapshot();
    if (now === null) { return; }
    const prev = readState(owner);
    // A(붙박이 페이지)는 kind·peer·thread·scroll 만 쓴다 — `open` 은 B 의 떠 있는 창 몫이라 있던 값을 그대로 둔다.
    writeState(owner, Object.assign({ open: prev ? prev.open : false }, now));
  }
  function applyScroll(fromBottom) {
    const list = $('message-list');
    list.scrollTop = Math.max(0, list.scrollHeight - list.clientHeight - fromBottom);
  }

  async function people() {
    const ticket = ++peopleSeq;
    const data = await call('/api/messages?q=' + encodeURIComponent($('message-search').value), 'GET', undefined, false, true);
    if (ticket !== peopleSeq) { return; }
    $('message-people').replaceChildren();
    for (const p of data.people) {
      const b = node('button', undefined, 'moa-person'); b.type = 'button'; b.setAttribute('aria-pressed', String(peer === Number(p.id)));
      b.append(node('span', label(p).slice(0, 1), 'moa-avatar'), node('span', label(p)), node('small', p.username));
      if (Number(p.unread) > 0) { b.append(node('span', p.unread, 'moa-unread')); }
      b.addEventListener('click', () => open(Number(p.id)));
      $('message-people').append(b);
    }
    if (!data.people.length) { $('message-people').append(node('p', '찾는 구성원이 없습니다.', 'moa-muted')); }
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
    if (!peer || document.hidden) { return; }
    const ticket = generation, id = peer; const list = $('message-list'), nearBottom = list.scrollHeight - list.scrollTop - list.clientHeight < 100;
    const data = await call('/api/messages/' + id + (older && oldest ? '?before=' + oldest : ''), 'GET', undefined, false, true);
    if (ticket !== generation) { return; }
    peerProfile = data.peer; $('message-send').disabled = sendingPeers.has(id); assist.sync();
    $('message-peer-name').textContent = label(data.peer) + ' 님과의 대화'; $('message-peer-meta').textContent = '대화 상대 @' + data.peer.username + ' · 1:1 대화';
    let changed = false;
    for (const m of data.messages) { const prior = currentMessages.get(Number(m.id)); if (!prior || prior.read_at !== m.read_at) { changed = true; } currentMessages.set(Number(m.id), m); latest = Math.max(latest, Number(m.id)); }
    if (data.messages.length) { oldest = Math.min(...currentMessages.keys()); if (older || currentMessages.size <= 60) { $('message-older').hidden = !data.has_more; } }
    if (changed || !currentMessages.size) { render(!older && nearBottom); }
    if (latest && !document.hidden && ticket === generation) { await call('/api/messages/' + id, 'PATCH', { through: latest }, false, true); }
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
  $('message-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    if (Array.from($('message-text').value).length > 4000) { say('메시지는 4,000자까지 보낼 수 있습니다. 내용을 줄여 주세요.'); return; }
    if (ai.active) { await ai.send(); return; }
    if (!peer || !peerProfile || $('message-send').disabled || sendingPeers.has(peer)) { return; }
    const id = peer, ticket = generation, text = $('message-text').value, attachment = assist.attachment(id);
    if (!text.trim() && !attachment) { return; }
    remember();
    const draft = peerDraft(id);
    // 같은 글·같은 첨부를 다시 보내면 같은 request_id(서버가 한 행으로 묶는다) — 글이 바뀌었으면 새로.
    const request = draft && draft.text === text && draft.attachment_id === attachment && draft.request_id ? draft.request_id : uuid();
    store.put('message', String(id), { text, attachment_id: attachment, request_id: request });
    sendingPeers.add(id); $('message-send').disabled = true; assist.sync();
    try {
      await call('/api/messages/' + id, 'POST', { text, attachment_id: attachment, request_id: request });
      const sent = peerDraft(id);
      if (sent && sent.text === text && sent.attachment_id === attachment) { store.forget('message', String(id)); }
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
    } finally { sendingPeers.delete(id); if (peer === id) { $('message-send').disabled = !peerProfile; } assist.sync(); }
  });
  $('message-text').addEventListener('input', () => {
    if (ai.active) { store.put('ai', String(ai.getState().thread || 0), { text: $('message-text').value }); } else { remember(); }
  });
  $('message-text').addEventListener('keydown', (e) => { if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); if (!$('message-send').disabled) { $('message-form').requestSubmit(); } } });
  $('message-older').addEventListener('click', async () => { if (loading) { return; } loading = true; $('message-older').disabled = true; try { await load(true); } catch (e) { say(e.message); } finally { loading = false; $('message-older').disabled = false; } });
  let searchTimer; $('message-search').addEventListener('input', () => { clearTimeout(searchTimer); searchTimer = setTimeout(() => people().catch((e) => say(e.message)), 250); });
  let scrollTimer = null;
  $('message-list').addEventListener('scroll', () => { window.clearTimeout(scrollTimer); scrollTimer = window.setTimeout(saveState, SCROLL_SETTLE_MS); }, { passive: true });
  document.addEventListener('visibilitychange', () => { if (document.hidden && !loggedOut) { store.flushAll(); saveState(); } });
  window.addEventListener('pagehide', () => { if (!loggedOut) { store.flushAll(); saveState(); } });
  // 로그아웃(app.js 가 지우기 직전에 알린다 — 이 화면에 app.js 가 없을 때도 같은 이벤트면 같게): 다시 쓰지 않고 이 사람 몫을 지운다.
  document.addEventListener('qaredi:logout', () => {
    loggedOut = true; window.clearTimeout(scrollTimer); store.shutdown(); forgetMine(owner);
  });

  mounted = Object.freeze({ mode });
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
