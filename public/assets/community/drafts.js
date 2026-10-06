/**
 * public/assets/community/drafts.js — 쓰던 글 임시저장 (Q:F-4 B1-2, 사이트 PRD Q:F-7 · 결정 W-1~W-4)
 *
 * 요구사항: 지시서 `../docs/qf4-quick-fixes-directive.md` 라운드 B 「B1-2」 설계 1~8 · 금지 패턴,
 *           커뮤니티 ARCHITECTURE 「임시저장」 절, SECURITY C:R-10.
 *
 * 무엇을 하나
 *   로그인이 끊겨도(무활동 30분) 저장되지 않은 페이지 제목·본문·댓글·답글을 잃지 않게, **그 브라우저의 localStorage 에
 *   암호화해서** 7일까지 남긴다. 같은 사람이 다시 로그인해 그 페이지를 열면 화면(editor.js)이 「불러오기 / 버리기」를 묻는다.
 *   서버는 이 내용을 **받지 않는다** — 끊긴 순간에는 서버가 받을 수 없기 때문이다(W-1).
 *
 * 보관 열쇠·모양 (설계 1)
 *   `qaredi-draft:v1:<사용자>:page:<페이지 uuid>` · `qaredi-draft:v1:<사용자>:comment:<페이지 uuid>:<답글 대상 uuid 또는 new>`
 *   사이트 Q:F-11(2026-10-06, 떠 있는 메신저 — 사이트 `docs/persistent-messenger-plan.md` 4.2 · SECURITY R-116) 두 종류:
 *   `qaredi-draft:v1:<사용자>:message:<상대 번호>`(사람 1:1 쓰던 글) · `qaredi-draft:v1:<사용자>:ai:<스레드 번호 또는 0>`(모아 AI).
 *   번호는 `^[1-9][0-9]{0,9}$`(ai 는 `0` 도). 암호화·AAD·7일·20개·sweep·shutdown 은 페이지·댓글과 **같다**.
 *   `entries()` 는 부르는 쪽이 종류를 고르지 않으면 페이지·댓글만 돌려준다(커뮤니티 화면이 메신저 항목을 그리지 않게) —
 *   정리(`sweep`·20개 `trim`)는 네 종류 전부를 센다.
 *   값 `{"v":1,"iv":"…","ct":"…","saved_at":<ms>}` — `ct` 는 `{base_version,title,doc}`(댓글은 `{body}`) JSON 을 암호화한 것.
 *   **평문은 `saved_at` 하나**(7일 정리용). `<사용자>` 는 **진짜 사용자**의 아이디다(역할로 보기 중이어도 — `/api/me` 의
 *   `username` 은 언제나 실제 계정). 아이디 글자 집합(`[a-z0-9_.-]`)에 `:` 가 없어 접두가 다른 사람과 겹치지 않는다.
 *
 * 암호화 (설계 2 — W-3 ⓑ)
 *   WebCrypto AES-GCM 256 · 쓸 때마다 **새 무작위 IV 12바이트** · **AAD = 보관 열쇠 문자열**(다른 사람·다른 페이지 칸으로
 *   옮겨 붙이면 풀리지 않는다). 열쇠는 `GET /community/api/me/draft-key`(본인 것만)에서 받아
 *   `importKey(…, extractable=false)` 로 **이 모듈 안(메모리)에만** 둔다 — 저장소·전역 변수·쿠키에 넣지 않는다.
 *   받은 글자는 가져오자마자 바이트를 0 으로 덮는다. 풀기 실패(열쇠가 바뀜·손상·옮겨 붙임) → 그 항목을 조용히 지운다.
 *   **열쇠를 아직 못 받았으면 쓰지 않는다 — 평문으로 대신 쓰지 않는다.**
 *
 * 크기·정리 (설계 4·6)
 *   한 사람 20개까지(넘으면 오래된 것부터 버림) · `saved_at` 이 7일을 넘은 것은 화면을 열 때마다(`sweep`) 지운다.
 *   쓰기 실패(용량·사생활 모드·막힌 저장소)는 여기서 삼키고 `false` 를 돌려준다 — 화면이 「임시저장 못 함」 배지를 보인다.
 *   저장(서버) 자체는 막지 않는다.
 *
 * 규칙
 *   · 이 모듈은 **네트워크에 닿지 않는다** — 열쇠를 받는 일도 부르는 쪽이 준 함수(`loadKey`)가 한다. 내용은 어디로도 보내지 않는다.
 *   · 다른 사용자 접두의 **값은 읽지 않는다**(열쇠 이름을 훑는 것은 내 것을 고르기 위해서뿐). 콘솔·로그 출력 0.
 *   · DOM 을 만지지 않는다(그리기는 editor.js 가 `createElement`·`textContent` 로).
 */

const PREFIX = 'qaredi-draft:v1:';
/** 사람 아이디 — `Auth::USERNAME_RE` 와 같은 글자 집합(`:` 없음) */
const OWNER_RE = /^[a-z0-9_.-]{3,64}$/;
const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/;
/** 메신저 상대 번호(Q:F-11) — 사람은 1 이상 */
const PEER_RE = /^[1-9][0-9]{0,9}$/;
/** 모아 AI 스레드 번호(Q:F-11) — 새 대화는 0 */
const THREAD_RE = /^(?:0|[1-9][0-9]{0,9})$/;
/** 종류를 고르지 않은 `entries()` 가 돌려주는 것 — 커뮤니티 화면(editor.js)이 쓰던 그대로 */
const DEFAULT_KINDS = ['page', 'comment'];
/** 7일(W-2) */
export const DRAFT_TTL_MS = 7 * 24 * 60 * 60 * 1000;
/** 한 사람 몫 상한 */
export const DRAFT_MAX = 20;
const IV_BYTES = 12;
const KEY_BYTES = 32;

const encoder = new TextEncoder();
const decoder = new TextDecoder();

function toB64(bytes) {
  let s = '';
  for (let i = 0; i < bytes.length; i += 1) { s += String.fromCharCode(bytes[i]); }
  return window.btoa(s);
}

function fromB64(text) {
  if (typeof text !== 'string' || text === '' || !/^[A-Za-z0-9+/]+={0,2}$/.test(text)) { return null; }
  try {
    const s = window.atob(text);
    const out = new Uint8Array(s.length);
    for (let i = 0; i < s.length; i += 1) { out[i] = s.charCodeAt(i); }
    return out;
  } catch (e) {
    return null;
  }
}

function fromB64url(text) {
  if (typeof text !== 'string' || !/^[A-Za-z0-9_-]+$/.test(text)) { return null; }
  const std = text.replace(/-/g, '+').replace(/_/g, '/');
  return fromB64(std + '='.repeat((4 - (std.length % 4)) % 4));
}

function storage() {
  try { return window.localStorage || null; } catch (e) { return null; }
}

function cryptoReady() {
  return typeof window.crypto === 'object' && window.crypto !== null
    && typeof window.crypto.getRandomValues === 'function'
    && typeof window.crypto.subtle === 'object' && window.crypto.subtle !== null;
}

/**
 * 한 사람 몫의 임시저장 창구를 만든다.
 *
 * @param {string}   owner    진짜 사용자의 아이디(`/api/me` 의 username). 모양이 아니면 임시저장은 꺼진 채다.
 * @param {Function} loadKey  열쇠 글자(base64url 32바이트)를 돌려주는 Promise 함수. 못 받으면 null. **한 화면에 한 번만** 부른다.
 */
export function createDrafts(owner, loadKey) {
  const mine = typeof owner === 'string' && OWNER_RE.test(owner) ? PREFIX + owner + ':' : null;
  const usable = mine !== null && cryptoReady() && storage() !== null;
  let key = null;
  let keyPromise = null;
  /* 독립 검토 M-1(2026-09-24) — 로그아웃한 뒤에는 이 화면이 끝날 때까지 **다시 쓰지 않는다**.
     로그아웃 단추가 사본을 지운 직후 창을 떠나는 경로(beforeunload·401 → leaveHooks)가
     같은 열쇠로 다시 암호화해 쓰던 길을 막는다. 이미 암호화 중이던 쓰기도 저장 직전에 다시 확인한다. */
  let shutDown = false;

  function isMine(storageKey) {
    return mine !== null && typeof storageKey === 'string' && storageKey.indexOf(mine) === 0;
  }

  /** 열쇠 받기 — 한 화면 수명 동안 한 번. 실패(401·503·모양 틀림)도 그대로 기억한다(다시 조르지 않는다). */
  function ready() {
    if (!usable) { return Promise.resolve(false); }
    if (keyPromise === null) {
      keyPromise = Promise.resolve()
        .then(() => loadKey())
        .then((raw) => {
          const bytes = fromB64url(raw);
          if (bytes === null) { return null; }
          if (bytes.length !== KEY_BYTES) { bytes.fill(0); return null; }
          return window.crypto.subtle.importKey('raw', bytes, { name: 'AES-GCM' }, false, ['encrypt', 'decrypt'])
            .then((k) => { bytes.fill(0); return k; }, () => { bytes.fill(0); return null; });
        })
        .catch(() => null)
        .then((k) => { key = k; return k !== null; });
    }
    return keyPromise;
  }

  /** 내 항목 전부(네 종류) — 값은 `saved_at` 만 본다(암호문은 풀지 않는다). 모양이 틀린 내 항목은 지운다. */
  function allEntries() {
    const s = storage();
    if (!usable || s === null) { return []; }
    const names = [];
    try {
      for (let i = 0; i < s.length; i += 1) {
        const k = s.key(i);
        if (isMine(k)) { names.push(k); }
      }
    } catch (e) {
      return [];
    }
    const out = [];
    for (const k of names) {
      const parts = k.slice(mine.length).split(':');
      let rec = null;
      try { rec = JSON.parse(s.getItem(k)); } catch (e) { rec = null; }
      const savedAt = rec && rec.v === 1 && typeof rec.saved_at === 'number' ? rec.saved_at : NaN;
      let entry = null;
      if (parts.length === 2 && parts[0] === 'page' && UUID_RE.test(parts[1])) {
        entry = { key: k, kind: 'page', pageId: parts[1], target: null, savedAt: savedAt };
      } else if (parts.length === 3 && parts[0] === 'comment' && UUID_RE.test(parts[1])
                 && (parts[2] === 'new' || UUID_RE.test(parts[2]))) {
        entry = { key: k, kind: 'comment', pageId: parts[1], target: parts[2], savedAt: savedAt };
      } else if (parts.length === 2 && parts[0] === 'message' && PEER_RE.test(parts[1])) {
        entry = { key: k, kind: 'message', pageId: null, target: null, peer: parts[1], savedAt: savedAt };
      } else if (parts.length === 2 && parts[0] === 'ai' && THREAD_RE.test(parts[1])) {
        entry = { key: k, kind: 'ai', pageId: null, target: null, thread: parts[1], savedAt: savedAt };
      }
      if (entry === null || !isFinite(savedAt)) { remove(k); continue; }
      out.push(entry);
    }
    return out;
  }

  /**
   * 내 항목들 — 종류를 고른다(`['message','ai']` 등). 고르지 않으면 페이지·댓글만(커뮤니티 화면이 쓰던 그대로).
   *
   * @param {string[]=} kinds  'page' · 'comment' · 'message' · 'ai' 중에서
   */
  function entries(kinds) {
    const want = Array.isArray(kinds) ? kinds : DEFAULT_KINDS;
    return allEntries().filter((e) => want.indexOf(e.kind) !== -1);
  }

  function remove(storageKey) {
    if (!isMine(storageKey)) { return; }
    const s = storage();
    try { if (s !== null) { s.removeItem(storageKey); } } catch (e) { /* 막힌 저장소 — 지울 것도 없다 */ }
  }

  /** 7일이 지난 내 항목을 지운다(설계 4 · W-2). 열쇠가 없어도 된다 — `saved_at` 은 평문이다. */
  function sweep(now) {
    const t = typeof now === 'number' ? now : Date.now();
    let n = 0;
    for (const e of allEntries()) {
      if (t - e.savedAt > DRAFT_TTL_MS) { remove(e.key); n += 1; }
    }
    return n;
  }

  /** 새 항목 자리를 만든다 — 이 열쇠를 빼고 `DRAFT_MAX - 1` 개만 남긴다(오래된 것부터 버림) */
  function trim(exceptKey) {
    const others = allEntries().filter((e) => e.key !== exceptKey).sort((a, b) => a.savedAt - b.savedAt);
    while (others.length > DRAFT_MAX - 1) { remove(others.shift().key); }
  }

  function pageKey(pageId) {
    return mine !== null && UUID_RE.test(String(pageId)) ? mine + 'page:' + pageId : null;
  }

  function commentKey(pageId, target) {
    const t = target === null || target === undefined ? 'new' : String(target);
    if (mine === null || !UUID_RE.test(String(pageId)) || (t !== 'new' && !UUID_RE.test(t))) { return null; }
    return mine + 'comment:' + pageId + ':' + t;
  }

  /** 메신저 사람 1:1 쓰던 글(Q:F-11) — 상대 번호가 모양이 아니면 null */
  function messageKey(peer) {
    return mine !== null && PEER_RE.test(String(peer)) ? mine + 'message:' + String(peer) : null;
  }

  /** 모아 AI 쓰던 글(Q:F-11) — 스레드 번호, 새 대화는 0 */
  function aiKey(thread) {
    return mine !== null && THREAD_RE.test(String(thread)) ? mine + 'ai:' + String(thread) : null;
  }

  /**
   * 암호화해서 쓴다. 열쇠가 없으면 **쓰지 않는다**(평문 대체 0).
   *
   * @param {string}   storageKey  pageKey/commentKey/messageKey/aiKey 가 만든 내 열쇠
   * @param {Object}   payload     `{base_version,title,doc}` · `{body}` · 메신저 `{text, attachment_id, request_id}` · AI `{text}`
   * @param {Function} stillWanted 암호화가 끝난 뒤 쓰기 직전에 다시 묻는다 — 그사이 서버에 저장됐거나 더 새 쓰기가
   *                               시작됐으면 false(그러면 쓰지 않고 넘어간다 — 지우지도 않는다)
   * @return Promise<boolean> 썼거나 쓸 필요가 없어졌으면 true, 못 썼으면(열쇠 없음·용량·막힌 저장소) false
   */
  function write(storageKey, payload, stillWanted) {
    if (shutDown || !usable || key === null || !isMine(storageKey)) { return Promise.resolve(false); }
    let plain;
    try { plain = encoder.encode(JSON.stringify(payload)); } catch (e) { return Promise.resolve(false); }
    const iv = window.crypto.getRandomValues(new Uint8Array(IV_BYTES));
    return window.crypto.subtle.encrypt({ name: 'AES-GCM', iv: iv, additionalData: encoder.encode(storageKey) }, key, plain)
      .then((ct) => {
        plain.fill(0);
        if (shutDown) { return false; }
        if (typeof stillWanted === 'function' && !stillWanted()) { return true; }
        const record = JSON.stringify({ v: 1, iv: toB64(iv), ct: toB64(new Uint8Array(ct)), saved_at: Date.now() });
        trim(storageKey);
        const s = storage();
        if (s === null) { return false; }
        try {
          s.setItem(storageKey, record);
          return true;
        } catch (e) {
          return false;
        }
      }, () => { plain.fill(0); return false; });
  }

  /**
   * 풀어서 돌려준다. 풀리지 않으면(열쇠가 바뀜·손상·다른 칸에서 옮겨 붙임) 그 항목을 **조용히 지우고** null.
   *
   * @return Promise<{data: Object, savedAt: number}|null>
   */
  function read(storageKey) {
    if (!usable || key === null || !isMine(storageKey)) { return Promise.resolve(null); }
    const s = storage();
    let rec = null;
    try { rec = JSON.parse(s.getItem(storageKey)); } catch (e) { rec = null; }
    if (rec === null) { return Promise.resolve(null); }
    const iv = rec && rec.v === 1 ? fromB64(rec.iv) : null;
    const ct = rec && rec.v === 1 ? fromB64(rec.ct) : null;
    if (iv === null || iv.length !== IV_BYTES || ct === null || ct.length <= 16 || typeof rec.saved_at !== 'number') {
      remove(storageKey);
      return Promise.resolve(null);
    }
    return window.crypto.subtle.decrypt({ name: 'AES-GCM', iv: iv, additionalData: encoder.encode(storageKey) }, key, ct)
      .then((plainBuf) => {
        const data = JSON.parse(decoder.decode(plainBuf));
        return data !== null && typeof data === 'object' ? { data: data, savedAt: rec.saved_at } : null;
      })
      .catch(() => null)
      .then((out) => {
        if (out === null) { remove(storageKey); }
        return out;
      });
  }

  return {
    /** 이 브라우저에서 임시저장을 쓸 수 있나(WebCrypto·저장소·아이디) — 열쇠와 무관 */
    available: () => usable,
    /** 열쇠를 받아 두었나 */
    hasKey: () => key !== null && !shutDown,
    /** 로그아웃 — 열쇠를 버리고 이 화면 수명 동안 쓰기를 멈춘다(지우기는 app.js clearMyDrafts 가 한다) */
    shutdown: () => { shutDown = true; key = null; },
    ready: ready,
    entries: entries,
    sweep: sweep,
    pageKey: pageKey,
    commentKey: commentKey,
    messageKey: messageKey,
    aiKey: aiKey,
    write: write,
    read: read,
    remove: remove
  };
}
