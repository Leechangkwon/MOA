/**
 * public/assets/community/editor.js — 커뮤니티 화면의 동작 한 벌 (모듈 스크립트)
 *
 * 요구사항: C:F-15 · C:F-16 · C:F-17(최소) · C:S-11 · C:S-12,
 *           ../../../../community/ARCHITECTURE.md 12.4 · 13.3 · 14장 · 21.3,
 *           DESIGN.md 2.7 · 2.14 · 6장,
 *           ../../../../community/stage3b-editor-rework-directive.md (사용자 결정 2026-09-23).
 *
 * 다루는 화면 둘 (서버가 `<body data-page>` 로 알려 준다 — app.js 는 이 두 키를 무시한다)
 *   community-home  내 공간 — 목록·찾기·새 페이지
 *   community-page  페이지 — 읽기(렌더러) ↔ 편집(ProseMirror), 자동 저장, 409 안내, 삭제
 *
 * 2026-09-23 다시 짠 것 (관문 ③ 에서 사용자가 만난 것들)
 *   · **문자 꾸미기(굵게·기울임·밑줄·취소선·인라인 코드)를 만들지 않는다.** 툴바는 「색」
 *     「배경」 「링크」 셋뿐이고 Ctrl+B 같은 단축키도 등록하지 않는다. PM 스키마의 마크는
 *     그대로 두어(읽기 호환) **에디터가 만들지 않을** 뿐이다.
 *   · **`/` 슬래시 메뉴를 없앴다.** 블록은 「블록 추가」 창(`<dialog id="block-dialog">`)으로 넣는다.
 *     `/` 는 그냥 글자로 들어간다.
 *   · **Enter·Backspace 를 우리가 짠다.** `baseKeymap` 의 기본 분리는 우리 평평한 블록 구조에서
 *     목록을 문단으로 떨어뜨렸다(사용자 보고 「목록에서 줄바꿈이 안 된다」).
 *   · **저장 경합을 번호로 막는다.** 보낸 시점의 변경 번호를 기억해 두고, 응답이 200 이어도
 *     그 뒤에 변경이 있으면 「저장됨」을 보이지 않고 한 번 더 보낸다.
 *
 * 규칙
 *   · 절대 경로는 `<body data-community-base>`(커뮤니티)와 `data-base`(t-box) 로만 만든다.
 *     이 파일에 `/community/…`·`/tbox/…` 같은 문자열 리터럴이 없다.
 *   · 요소는 `createElement`, 글자는 `textContent` 로만 만든다. 문자열을 HTML 로 바꾸지 않는다.
 *   · **화면은 판단하지 않는다.** 단추가 보이고 안 보이고는 서버가 준 `can_edit` 을 따를 뿐이고,
 *     허용 여부는 서버가 요청마다 다시 판단한다 (DESIGN 원칙 1, PRD S-01).
 *   · 저장 형식은 `editor-schema.js` 의 `toDoc`/`fromDoc` 한 통로만 지난다. 그 결과는
 *     서버 `Doc::validate` 가 다시 검사한다(서버 검증이 진실이다).
 */

import { EditorState, TextSelection, NodeSelection, Plugin } from './vendor/pm/prosemirror-state.js?v=5823578fa61a';
import { EditorView, Decoration, DecorationSet } from './vendor/pm/prosemirror-view.js?v=5823578fa61a';
import { keymap } from './vendor/pm/prosemirror-keymap.js?v=5823578fa61a';
import { history, undo, redo } from './vendor/pm/prosemirror-history.js?v=5823578fa61a';
import { baseKeymap, chainCommands } from './vendor/pm/prosemirror-commands.js?v=5823578fa61a';
import { inputRules, textblockTypeInputRule, InputRule } from './vendor/pm/prosemirror-inputrules.js?v=5823578fa61a';
import { dropCursor } from './vendor/pm/prosemirror-dropcursor.js?v=5823578fa61a';
import { gapCursor } from './vendor/pm/prosemirror-gapcursor.js?v=5823578fa61a';

import { renderDoc, embedHost } from './doc-render.js?v=4faf2f126cbc';
import { createDatabaseView } from './database.js?v=4faf2f126cbc';
import { bindTemplateGallery, createTemplateSave } from './templates.js?v=4faf2f126cbc';
import { bindEmbedAdmin } from './embeds.js?v=4faf2f126cbc';
import { bindPopular } from './popular.js?v=4faf2f126cbc';
import { bindPalettes } from './palettes.js?v=4faf2f126cbc';
import { schema, toDoc, fromDoc, uuid4, colorAllowed, schemeOkForEditor, mentionAllowed, editorDomParser, clipboardDomParser, COLORS, BLOCK_TYPES } from './editor-schema.js?v=4faf2f126cbc';
import { drawAutomationPage, drawAutomationSpace, bindAutomations, loadAutomations } from './automations.js?v=4faf2f126cbc';
import { drawTrust, bindProcedures } from './procedures.js?v=4faf2f126cbc';
import { createPageExport } from './export.js?v=4faf2f126cbc';
import { createDrafts } from './drafts.js?v=4faf2f126cbc';

/* ─── 주소와 공통 도우미 ─────────────────────────────────────── */

const body = document.body;
const COMMUNITY_BASE = (body && body.getAttribute('data-community-base')) || '';
const APP_BASE = (body && body.getAttribute('data-base')) || '';
const GENERIC_ERROR = '요청을 처리할 수 없습니다. 잠시 후 다시 시도하세요';

/** 상태 변경 요청에 붙일 CSRF 토큰. t-box 창구 `GET /api/me` 가 준다(같은 세션·같은 토큰). */
let csrfToken = null;

function setText(el, text) {
  if (el) { el.textContent = text === null || text === undefined ? '' : String(text); }
}

function show(el, on) {
  if (el) { el.hidden = !on; }
}

function clear(el) {
  if (!el) { return; }
  while (el.firstChild) { el.removeChild(el.firstChild); }
}

/** 응답 본문을 JSON 으로 읽는다. 아니면 빈 객체 — 화면이 사유를 지어내지 않는다. */
function readJson(response) {
  return response.text().then((raw) => {
    try { return JSON.parse(raw); } catch (e) { return {}; }
  });
}

/**
 * 5-A″ — 사람을 보이는 모양 「이름 (아이디)」(이름이 없으면 「아이디」). **모양은 한 벌**이다 —
 * t-box 셸 스크립트(app.js)의 `personLabel` 을 빌린다(셸이 이 파일보다 먼저 실행된다: app.js 는
 * `<body>` 끝의 일반 스크립트, 이 파일은 지연 실행되는 모듈). 없으면 아이디만 보인다 — 모양을
 * 여기서 다시 짓지 않는다. 넣는 쪽은 언제나 `textContent` 다.
 */
function personLabel(displayName, username) {
  const shared = typeof window.qarediPersonLabel === 'function' ? window.qarediPersonLabel : null;
  return shared ? shared(displayName, username) : String(username === null || username === undefined ? '' : username);
}

/**
 * 역할의 화면 글자 — 한글(디버깅 묶음 U M7 · DESIGN 5장). 셸(app.js)의 `window.qarediRoleLabel` 한 벌을 쓰고, 셸이 없는 날에는
 * 받은 글자 그대로다. 값(`role`)을 비교하는 코드가 아니다 — 보이는 글자만 바꾼다.
 */
function roleText(role) {
  const shared = typeof window.qarediRoleLabel === 'function' ? window.qarediRoleLabel : null;
  return shared ? shared(role) : String(role === null || role === undefined ? '' : role);
}

function errorMessage(data) {
  return data && typeof data.error === 'string' && data.error !== '' ? data.error : GENERIC_ERROR;
}

/**
 * Q:F-4 U-3 — **사람이 누르지 않은 주기 요청**(알림 폴링·편집 heartbeat)에 다는 표식. 서버 `Auth::BACKGROUND_HEADER`·
 * `BACKGROUND_VALUE` 와 같은 이름·값이고, 이 파일에서는 여기 한 곳이다. 이 표식이 달린 요청은 세션을 **확인만** 하고
 * 늘리지 않는다 — 켜 둔 탭이 「활동 없는 30분 로그아웃」을 끝없이 미루지 않게. 저장·댓글 같은 쓰기에는 달지 않는다(늘 연장).
 */
const BACKGROUND_HEADER = 'X-Qaredi-Background';
const BACKGROUND_VALUE = '1';

/** 창을 닫을 때 보내는 저장(`keepalive`)의 본문 상한 — 브라우저가 keepalive 요청에 64KiB 까지만 허락한다. 넘으면 평소대로 보낸다. */
const KEEPALIVE_MAX_BYTES = 60000;

/**
 * 커뮤니티 창구 부르기. 경로는 코드에 적힌 모듈 경로이고 접두는 여기서 한 번만 붙는다.
 *
 * @param {Object} [opts]  `{background: true}` — 자동 요청 표식(U-3) · `{keepalive: true}` — 창을 닫는 중에도 끝까지 보낸다(B1-2 설계 7)
 */
function api(method, path, payload, opts) {
  const options = {
    method: method,
    credentials: 'same-origin',
    cache: 'no-store',
    headers: {}
  };
  if (opts && opts.background === true) { options.headers[BACKGROUND_HEADER] = BACKGROUND_VALUE; }
  if (method !== 'GET' && method !== 'HEAD') {
    if (csrfToken === null) { return Promise.reject(new Error('no-csrf')); }
    options.headers['X-CSRF-Token'] = csrfToken;
    options.headers['Content-Type'] = 'application/json';
    options.body = JSON.stringify(payload === undefined ? {} : payload);
    if (opts && opts.keepalive === true && new TextEncoder().encode(options.body).length <= KEEPALIVE_MAX_BYTES) {
      options.keepalive = true;
    }
  }
  return fetch(COMMUNITY_BASE + path, options).then((response) =>
    readJson(response).then((data) => ({ status: response.status, data: data }))
  );
}

/**
 * Q:F-4 B1-2 — 로그인 화면으로 **가기 전에** 부를 일(쓰던 글 임시저장). 페이지 화면이 등록한다.
 * 401 을 받은 그 순간 열쇠는 이미 메모리에 있다 — 암호화해 쓴 뒤에 떠난다(늦어도 1.5초 뒤에는 떠난다).
 */
const leaveHooks = [];
/** 일부러 떠나는 중인가 — 이때는 「저장 안 된 변경」 닫기 경고를 띄우지 않는다(임시저장이 이미 남았다) */
let leavingOnPurpose = false;

function toLogin() {
  if (leavingOnPurpose) { return; }
  leavingOnPurpose = true;
  const jobs = leaveHooks.map((fn) => {
    try { return Promise.resolve(fn()).catch(() => null); } catch (e) { return Promise.resolve(null); }
  });
  const late = new Promise((resolve) => { window.setTimeout(resolve, 1500); });
  Promise.race([Promise.all(jobs), late]).then(() => {
    window.location.assign(APP_BASE + '/login?to=' + loginReturnTag());
  });
}

/**
 * Q:F-7 B1-1 — 로그인 뒤 돌아올 곳의 꼬리표. 셸(app.js)의 `qarediReturnPath` 로 걸러 허용 목록 안이면 그 정규형(rawurlencode),
 * 아니면 옛 키 `community`. 편집 중이었으면(쓸 수 있는 본문이 열려 있으면) `?edit=1` 을 붙여 — 로그인 뒤 그 페이지가 편집 모드로 열리고
 * 임시저장 알림 줄(B1-2)이 뜬다. 거르기는 서버(login_page.php · Http::returnPath)가 한 번 더 한다.
 */
function loginReturnTag() {
  const rp = typeof window.qarediReturnPath === 'function' ? window.qarediReturnPath : null;
  const enc = typeof window.qarediRawEncode === 'function' ? window.qarediRawEncode : null;
  if (rp === null || enc === null) { return 'community'; }
  const editing = document.querySelector('#page-doc [contenteditable="true"], .ProseMirror[contenteditable="true"]') !== null;
  const here = rp(window.location.pathname + (editing ? '?edit=1' : window.location.search));
  return here !== null && here !== COMMUNITY_BASE + '/' ? enc(here) : 'community';
}

/**
 * Q:F-4 B1-2 — 이 화면의 임시저장 창구(`drafts.js`). `boot` 가 `/api/me` 의 **진짜 사용자** 아이디로 만든다.
 * 열쇠는 그 모듈 안에만 있다(이 파일에 열쇠를 담는 변수가 없다).
 */
let drafts = null;

/** 임시저장 열쇠 받기 — `drafts.js` 가 한 화면에 한 번만 부른다. 값은 곧바로 그 모듈에 넘어가고 여기 남지 않는다. */
function loadDraftKey() {
  return api('GET', '/api/me/draft-key').then((result) => (
    result.status === 200 && result.data && result.data.v === 1 && typeof result.data.key === 'string'
      ? result.data.key : null
  ));
}

/** 이 브라우저에서 잰 시각(ms) → 「2026-09-24 15:12」 (DESIGN 5장 날짜 모양 — 임시저장은 서버 시각이 없다) */
function localWhen(ms) {
  const d = new Date(ms);
  if (isNaN(d.getTime())) { return ''; }
  const two = (n) => (n < 10 ? '0' : '') + String(n);
  return d.getFullYear() + '-' + two(d.getMonth() + 1) + '-' + two(d.getDate()) + ' ' + two(d.getHours()) + ':' + two(d.getMinutes());
}

/**
 * Q:F-4 P-4a — **탭이 보일 때만** 도는 주기 작업(알림 폴링 · 편집 heartbeat).
 *
 * 숨은 탭(`document.hidden`)에서는 타이머를 걷어 요청을 0 으로 만들고, 다시 보이면 **곧바로 한 번** 부른 뒤
 * 원래 주기로 돌아간다. `start()` 가 불리지 않았거나 `stop()` 뒤에는 보임·숨김이 바뀌어도 아무것도 하지 않는다.
 * 주기 값은 부르는 쪽이 코드에 적은 상수다 — 시험용 분기·전역 손잡이 없음.
 * (숨김 동안 편집 저장 flush 는 이것과 따로, 페이지의 `visibilitychange` 가 전처럼 한다.)
 *
 * @param {Function} fn  부를 일(요청 하나)
 * @param {number}   ms  주기(밀리초)
 * @return {{start: Function, stop: Function}}
 */
function whileVisible(fn, ms) {
  let timer = null;
  let running = false;
  const arm = () => { if (timer === null) { timer = window.setInterval(fn, ms); } };
  const disarm = () => { if (timer !== null) { window.clearInterval(timer); timer = null; } };
  document.addEventListener('visibilitychange', () => {
    if (!running) { return; }
    if (document.hidden) { disarm(); return; }
    if (timer === null) { fn(); arm(); }
  });
  return {
    start() {
      running = true;
      disarm();
      // 숨은 채로 열린 탭(배경 탭으로 연 링크)은 보일 때까지 부르지 않는다 — 보이는 순간 한 번 부른다.
      if (!document.hidden) { fn(); arm(); }
    },
    stop() {
      running = false;
      disarm();
    }
  };
}

/* ─── 공개 범위 (4-B) ────────────────────────────────────────────
 *
 * ★ 이 표는 **보이는 말과 색**일 뿐 판정이 아니다. 무엇을 고를 수 있고 무엇이 거부되는지는
 *   서버가 요청마다 정하고(`CommunityAuthz`), 화면은 그 답을 그린다 (DESIGN 원칙 1).
 * ★ 색은 t-box 등급 배지의 뜻 그대로다 — 좁을수록 빨강, 넓을수록 파랑 (DESIGN 2.2).
 *   새 색값을 만들지 않았다.
 */
const VISIBILITY_LABELS = {
  private: { text: '비공개', badge: 'badge--admin-only' },
  team: { text: '팀', badge: 'badge--team' },
  internal: { text: '사내', badge: 'badge--internal' }
};

/** 거부 사유(서버가 준 `reason`) → 사람 문장. 표에 없으면 서버가 준 문구를 그대로 쓴다. */
const VISIBILITY_REASONS = {
  children_wider: '하위 페이지가 더 넓게 열려 있습니다. 하위까지 함께 바꾸려면 아래를 켜세요',
  wider_than_parent: '상위 페이지보다 넓게 열 수 없습니다. 상위 페이지를 먼저 넓히세요',
  space_governed: '팀 공간의 페이지는 공간 등급을 따릅니다',
  // 5-A — 공유·인계에서 고를 수 없는 사람(없는·쓰지 않는 계정, 주인 자신, 인계는 손님도)
  target_not_permitted: '그 사람에게는 줄 수 없습니다. 쓰고 있는 계정인지 확인하세요'
};

/* ─── 공유 (5-A) ─────────────────────────────────────────────────
 * 보이는 말뿐이다. 무엇을 줄 수 있고 누가 받을 수 있는지는 서버가 정한다(`CommunityAuthz`). */
const SHARE_LABELS = { view: '보기', edit: '편집' };

/* ─── 상태 (4-D, C:F-37) ─────────────────────────────────────────
 *
 * ★ 여기 있는 것은 **보이는 말과 색**뿐이다. 값이 다섯인지·무엇이 거부되는지는 서버가
 *   정하고(`COMMUNITY_STATUSES`, DB CHECK), 화면은 그 답을 그린다 (DESIGN 원칙 1).
 * ★ 색은 DESIGN 2.2 의 배지 뜻 그대로다 — 회색(꺼짐)·파랑(정보)·보라·초록(허용)·노랑(주의).
 *   새 색값을 만들지 않았다: `community.css` 의 다섯 줄은 app.css 의 같은 토큰을 가리킨다.
 */
const STATUS_LABELS = {
  todo: { text: '할 일', badge: 'badge--status-todo' },
  doing: { text: '진행', badge: 'badge--status-doing' },
  review: { text: '검토', badge: 'badge--status-review' },
  done: { text: '완료', badge: 'badge--status-done' },
  hold: { text: '보류', badge: 'badge--status-hold' }
};

/** 「상태 없음」 — 값이 아니라 값이 없는 상태다. 배지도 기본(회색) 하나뿐이다. */
const STATUS_NONE_TEXT = '없음';

/** 배지 한 칸을 상태로 채운다. 모르는 값이면 「없음」으로 둔다(지어내지 않는다). */
function paintStatus(el, status) {
  if (!el) { return; }
  const entry = STATUS_LABELS[status];
  el.className = 'badge' + (entry ? ' ' + entry.badge : '');
  if (entry) { el.setAttribute('title', status); } else { el.removeAttribute('title'); }
  setText(el, entry ? entry.text : STATUS_NONE_TEXT);
}

/** 목록 줄에 붙일 작은 상태 배지 하나. 상태가 없으면 아무것도 만들지 않는다. */
function statusBadge(status) {
  const entry = STATUS_LABELS[status];
  if (!entry) { return null; }
  const span = document.createElement('span');
  span.className = 'badge ' + entry.badge;
  span.setAttribute('title', status);
  span.textContent = entry.text;
  return span;
}

/** 목록 줄에 붙일 「보기 공유」·「편집 공유」 배지 (5-A). 모르는 값이면 아무것도 만들지 않는다. */
function shareBadge(level) {
  const text = SHARE_LABELS[level];
  if (!text) { return null; }
  const span = document.createElement('span');
  span.className = 'badge';
  span.setAttribute('title', level);
  span.textContent = text + ' 공유';
  return span;
}

/** 이력 줄의 속성 값 — 상태는 한글 라벨, 담당자는 계정명, 없음은 「없음」. */
function propValueText(prop, value) {
  if (value === null || value === undefined || value === '') { return STATUS_NONE_TEXT; }
  if (prop === 'status') {
    const entry = STATUS_LABELS[value];
    return entry ? entry.text : String(value);
  }
  return String(value);
}

/**
 * 아이콘 한 칸. `app.js` 의 `makeIcon` 과 **같은 방식**이다 — SVG 마크업은 서버가 만든
 * `<template>` 안에 있고 여기서는 `cloneNode` 로 복제만 한다. 문자열을 HTML 로 바꾸는
 * 자리가 없다 (CSP `script-src 'self'`, C:S-03). 이 파일은 모듈이라 `app.js` 의 함수를
 * 부를 수 없어 같은 여섯 줄을 여기에도 둔다.
 */
function makeIcon(name) {
  const host = document.createElement('span');
  host.className = 'icon';
  const tpl = document.getElementById('tpl-icon-' + String(name));
  if (tpl && tpl.content) { host.appendChild(tpl.content.cloneNode(true)); }
  return host;
}

/** 배지 한 칸을 공개 범위로 채운다. 모르는 값이면 숨긴다(지어내지 않는다). */
function paintVisibility(el, visibility) {
  if (!el) { return; }
  const entry = VISIBILITY_LABELS[visibility];
  if (!entry) { show(el, false); return; }
  el.className = 'badge ' + entry.badge;
  el.setAttribute('title', visibility);
  setText(el, entry.text);
  show(el, true);
}

/**
 * 트리·카드 줄에 붙일 작은 배지 하나.
 * **기본값(비공개)은 그리지 않는다** — 내 공간의 페이지는 처음에 비공개라, 트리 거의 모든 줄에 빨간 「비공개」가 붙어
 * 정작 넓게 연 페이지(팀·사내)가 눈에 띄지 않았다(디버깅 묶음 U M11 · 2026-09-26). 줄에는 **기본과 다른 것**만 보인다.
 * 페이지 화면 속성 줄의 「공개 범위」 배지(`paintVisibility`)는 그대로 비공개도 보인다 — 거기는 그 페이지의 값을 묻는 자리다.
 */
function visibilityBadge(visibility) {
  if (visibility === 'private') { return null; }
  const entry = VISIBILITY_LABELS[visibility];
  if (!entry) { return null; }
  const span = document.createElement('span');
  span.className = 'badge ' + entry.badge;
  span.setAttribute('title', visibility);
  span.textContent = entry.text;
  return span;
}

/**
 * 주소의 `?space=` — 숫자만 받는다 (4-B).
 * 형태가 아니면 없는 것으로 다룬다: 창구가 어차피 404 로 답하지만, 이상한 값을 그대로
 * 창구에 실어 보내면 「무엇이 잘못됐나」가 주소와 응답 두 곳으로 흩어진다.
 */
function currentSpaceId() {
  let raw = '';
  try {
    raw = new URLSearchParams(window.location.search).get('space') || '';
  } catch (e) {
    raw = '';
  }
  return /^[1-9][0-9]{0,17}$/.test(raw) ? raw : '';
}

/** 공간 하나의 주소. 개인 공간(기본)은 꼬리표 없이 둔다. */
function spaceHref(id, kind) {
  return COMMUNITY_BASE + '/workspace' + (kind === 'personal' ? '' : '?space=' + String(id));
}

/**
 * 셸 왼쪽 내비의 「팀 공간」 묶음을 채운다 (4-B, 두 화면 공통).
 *
 * 항목은 창구가 준 것만 들어간다 — 이 파일에는 「내 팀이 무엇인가」를 아는 코드가 없다.
 * 팀 공간이 없으면 제목 줄까지 숨은 채로 끝난다.
 */
function fillSpaceNav(spaces) {
  const box = document.getElementById('nav-community-teams');
  const title = document.getElementById('nav-community-team-title');
  if (!box) { return; }
  clear(box);
  const teams = (Array.isArray(spaces) ? spaces : []).filter((s) => s && s.kind === 'team');
  // 팀마다의 줄과 트리는 `fillSidebarTrees` 가 그린다(5-A′). 여기서는 묶음을 열고 닫기만 한다.
  show(box, teams.length > 0);
  show(title, teams.length > 0);
}

/**
 * 내가 볼 수 있는 공간들. **팀 공간이 아직 없으면 창구가 그 자리에서 만든다**(C:T-36) —
 * 그래서 이 호출은 화면을 여는 것만으로 팀 공간을 여는 일을 겸한다. 두 번 불러도 같다.
 */
function loadSpaces() {
  return api('GET', '/api/spaces').then((result) => {
    if (result.status !== 200) { return []; }
    const spaces = Array.isArray(result.data.spaces) ? result.data.spaces : [];
    fillSpaceNav(spaces);
    fillSidebarTrees(spaces);
    return spaces;
  }).catch(() => []);
}

/* ─── 사이드바 페이지 트리 (5-A′, 사용자 지적 2026-09-23) ─────────────
 *
 * 「사이드바에 내 공간·+ 새 페이지가 있는데 그 아래 페이지가 보여야 할 것 같다」 — 4-A 지시서의
 * 목표 문장(「왼쪽 내 공간이 트리로」)이 구현에서 빠졌던 것을 채운다.
 *   · 내 공간 · 팀 공간마다 · 공유받은 페이지 — 항목은 **창구가 준 것만**(`?tree=1` 은 행 관문을
 *     지난 목록이다). 이 파일에는 누가 무엇을 볼 수 있나를 아는 코드가 없다 (PRD S-01).
 *   · 접기·펼치기는 ▸ 단추. 지금 페이지와 그 윗줄들만 펼쳐 두고 지금 페이지를 강조한다.
 *   · 들여쓰기는 목록이 겹칠 때마다 CSS 가 준다 — 인라인 style 0 (DESIGN 0장 2).
 */
function currentPageId() {
  const meta = document.getElementById('page-meta');
  return meta ? (meta.getAttribute('data-page-id') || '') : '';
}

/** 펼침 단추 하나 — 하위가 없으면 자리만 차지하는 빈 칸이다(제목 왼쪽 끝을 가지런히) */
function navToggle(label, kids, expanded) {
  const toggle = document.createElement('button');
  toggle.type = 'button';
  toggle.className = 'nav-tree__toggle';
  if (!kids) {
    toggle.disabled = true;
    toggle.tabIndex = -1;
    toggle.setAttribute('aria-hidden', 'true');
    return toggle;
  }
  toggle.textContent = '▸';
  toggle.setAttribute('aria-label', label + ' 하위 ' + (expanded ? '접기' : '펼치기'));
  toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
  if (expanded) { toggle.classList.add('is-open'); }
  toggle.addEventListener('click', () => {
    const box = toggle.parentNode && toggle.parentNode.nextElementSibling;
    if (!box) { return; }
    const open = box.hidden;
    box.hidden = !open;
    toggle.classList.toggle('is-open', open);
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    toggle.setAttribute('aria-label', label + ' 하위 ' + (open ? '접기' : '펼치기'));
  });
  return toggle;
}

/** 평평한 목록(`?tree=1`) → 사이드바 트리. `found` 는 지금 페이지가 이 트리에 있었나 */
function navTree(pages, activeId) {
  const byParent = new Map();
  const byId = new Map();
  for (const page of pages) {
    byId.set(page.id, page);
    const key = typeof page.parent_id === 'string' ? page.parent_id : '';
    if (!byParent.has(key)) { byParent.set(key, []); }
    byParent.get(key).push(page);
  }
  // 지금 페이지의 윗줄들은 펼친다(깊이 8 — 16 에서 멈추는 것은 자료가 어긋났을 때의 안전장치)
  const openIds = new Set();
  let cursor = byId.get(activeId);
  for (let guard = 0; cursor && guard < 16; guard++) {
    const up = typeof cursor.parent_id === 'string' ? cursor.parent_id : '';
    if (up === '') { break; }
    openIds.add(up);
    cursor = byId.get(up);
  }

  function level(key, depth) {
    const ul = document.createElement('ul');
    ul.className = 'nav-tree';
    for (const node of byParent.get(key) || []) {
      const kids = depth < 12 ? (byParent.get(node.id) || []) : [];
      const expanded = openIds.has(node.id) || node.id === activeId;
      const title = (node.icon ? node.icon + ' ' : '') + node.title;
      const li = document.createElement('li');
      const row = document.createElement('div');
      row.className = 'nav-tree__row' + (node.id === activeId ? ' is-active' : '');
      const link = document.createElement('a');
      link.className = 'nav-tree__link';
      link.setAttribute('href', COMMUNITY_BASE + '/p/' + node.id);
      link.textContent = title;
      if (node.id === activeId) { link.setAttribute('aria-current', 'page'); }
      row.appendChild(navToggle(title, kids.length > 0, expanded));
      row.appendChild(link);
      row.appendChild(navStar(node));   // 라운드 B — 트리 항목의 ☆(넣기·빼기)
      li.appendChild(row);
      if (kids.length > 0) {
        const sub = level(node.id, depth + 1);
        sub.hidden = !expanded;
        li.appendChild(sub);
      }
      ul.appendChild(li);
    }
    return ul;
  }
  return { el: level('', 0), found: byId.has(activeId) };
}

function spaceTree(space) {
  const tail = space.kind === 'personal' ? '' : '&space=' + encodeURIComponent(String(space.id));
  return api('GET', '/api/pages?tree=1' + tail).then((result) => (
    result.status === 200 && Array.isArray(result.data.pages) ? result.data.pages : []
  )).catch(() => []);
}

function fillSidebarTrees(spaces) {
  const mineBox = document.getElementById('nav-community-tree');
  const teamBox = document.getElementById('nav-community-teams');
  const sharedBox = document.getElementById('nav-community-shared');
  const sharedTitle = document.getElementById('nav-community-shared-title');
  const activeId = currentPageId();
  const hereSpace = currentSpaceId();
  const list = Array.isArray(spaces) ? spaces : [];

  const personal = list.find((s) => s && s.kind === 'personal');
  if (mineBox && personal) {
    spaceTree(personal).then((pages) => {
      clear(mineBox);
      mineBox.appendChild(navTree(pages, activeId).el);
      markActiveVisible(mineBox);
    });
  }

  // 팀 공간 — 팀 이름 줄(공간으로 가는 링크) 밑에 그 팀의 트리. 지금 페이지가 그 안에 있거나
  // 그 공간을 보는 중이면 펼친다.
  if (teamBox) {
    clear(teamBox);
    for (const space of list.filter((s) => s && s.kind === 'team')) {
      const group = document.createElement('div');
      const row = document.createElement('div');
      row.className = 'nav-tree__row nav-tree__row--space' + (hereSpace === String(space.id) && activeId === '' ? ' is-active' : '');
      const link = document.createElement('a');
      link.className = 'nav-tree__link';
      link.setAttribute('href', spaceHref(space.id, space.kind));
      link.appendChild(makeIcon('users'));
      const name = document.createElement('span');
      name.textContent = space.name;
      link.appendChild(name);
      group.appendChild(row);
      teamBox.appendChild(group);
      spaceTree(space).then((pages) => {
        const tree = navTree(pages, activeId);
        const expanded = tree.found || hereSpace === String(space.id);
        row.appendChild(navToggle(space.name, pages.length > 0, expanded));
        row.appendChild(link);
        if (pages.length > 0) {
          tree.el.hidden = !expanded;
          group.appendChild(tree.el);
        }
        markActiveVisible(group);
      });
    }
  }

  // 공유받은 페이지 (5-A) — 공간을 가리지 않는 한 번의 창구 호출. 없으면 제목째 숨는다.
  if (sharedBox) {
    api('GET', '/api/pages?shared=me').then((result) => {
      const pages = result.status === 200 && Array.isArray(result.data.pages) ? result.data.pages : [];
      clear(sharedBox);
      const ul = document.createElement('ul');
      ul.className = 'nav-tree';
      for (const page of pages) {
        const li = document.createElement('li');
        const row = document.createElement('div');
        row.className = 'nav-tree__row' + (page.id === activeId ? ' is-active' : '');
        row.appendChild(navToggle('', false, false));
        const link = document.createElement('a');
        link.className = 'nav-tree__link';
        link.setAttribute('href', COMMUNITY_BASE + '/p/' + page.id);
        link.textContent = (page.icon ? page.icon + ' ' : '') + page.title;
        if (page.id === activeId) { link.setAttribute('aria-current', 'page'); }
        row.appendChild(link);
        row.appendChild(navStar(page));   // 라운드 B — 트리 항목의 ☆
        li.appendChild(row);
        ul.appendChild(li);
      }
      sharedBox.appendChild(ul);
      show(sharedBox, pages.length > 0);
      show(sharedTitle, pages.length > 0);
    }).catch(() => {});
  }
}

/** 지금 페이지 줄이 사이드바 칸 밖이면 그 칸 안에서만 보이게 굴린다(본문은 움직이지 않는다) */
function markActiveVisible(box) {
  const row = box.querySelector('.nav-tree__row.is-active');
  const side = document.getElementById('sidebar');
  if (!row || !side) { return; }
  const r = row.getBoundingClientRect();
  const b = side.getBoundingClientRect();
  if (r.top < b.top || r.bottom > b.bottom) {
    side.scrollTop += r.top - b.top - b.height / 3;
  }
}

/* ─── 즐겨찾기 · 최근 (개발 대기열 6 · Q:F-3a 라운드 A — 지시서 1.1 · 1.2 · 결정 M-1) ─────────────
 *
 * 사이드바 맨 위 「즐겨찾기」(접을 수 있음) → 「최근」 5개 + 「더 보기」. 세 화면(내 공간·페이지·게시판) 공통.
 *   · 항목은 **창구가 준 것만** — 두 창구 모두 검색과 같은 조건절 안에서만 돌려준다(못 보게 된 페이지는 제목째 없다).
 *     이 파일에는 누가 무엇을 볼 수 있나를 아는 코드가 없다 (PRD S-01, DESIGN 원칙 1).
 *   · 줄은 사이드바 트리와 같은 `.nav-tree` 줄이고 글자는 textContent 뿐이다. 새 클래스·인라인 style 0.
 *   · 즐겨찾기 순서: 줄을 끌어 놓거나(HTML 끌어 놓기) 링크에 초점을 두고 Alt+↑/↓ → `PATCH /api/me/favorites {ids}`.
 *     실패하면 창구의 순서로 다시 그린다.
 *   · 「최근 기록 지우기」는 창 안의 빨간 단추를 **두 번** 눌러야 나간다(댓글 지우기와 같은 방식 — 모달 안에 모달 0).
 */
let favoriteIds = new Set();
/** 즐겨찾기를 새로 받았을 때 알릴 곳 — 페이지 화면의 ☆ 메뉴가 등록한다 */
const favoriteListeners = [];
/** 최근 목록(창구의 답 그대로) — 「더 보기」 창이 쓴다 */
let recentPages = [];
const FAV_OPEN_KEY = 'qaredi-nav-fav-open';

/** 사이드바 한 줄 — 트리 줄과 같은 모양(왼쪽 빈 칸은 트리의 펼침 단추 자리) */
function navPageRow(page, activeId) {
  const li = document.createElement('li');
  const row = document.createElement('div');
  row.className = 'nav-tree__row' + (page.id === activeId ? ' is-active' : '');
  row.appendChild(navToggle('', false, false));
  const link = document.createElement('a');
  link.className = 'nav-tree__link';
  link.setAttribute('href', COMMUNITY_BASE + '/p/' + page.id);
  link.textContent = (page.icon ? page.icon + ' ' : '') + page.title;
  if (page.id === activeId) { link.setAttribute('aria-current', 'page'); }
  row.appendChild(link);
  row.appendChild(navStar(page));   // 라운드 B — 즐겨찾기·최근 줄에도 같은 ☆(즐겨찾기 줄에서는 누르면 빠진다)
  li.appendChild(row);
  return { li: li, link: link };
}

/** 즐겨찾기 줄들의 지금 순서(페이지 id) */
function favoriteOrder(ul) {
  return Array.from(ul.querySelectorAll('li[data-fav-id]')).map((li) => li.getAttribute('data-fav-id'));
}

function saveFavoriteOrder(ul, before) {
  const ids = favoriteOrder(ul);
  if (ids.join(',') === before.join(',')) { return; }
  api('PATCH', '/api/me/favorites', { ids: ids }).then((result) => {
    if (result.status === 401) { toLogin(); return; }
    if (result.status !== 200) {
      setText(document.getElementById('msg'), errorMessage(result.data));
      loadFavoritesNav();   // 창구의 순서로 되돌린다
    }
  }).catch(() => { loadFavoritesNav(); });
}

function drawFavoritesNav(pages) {
  const box = document.getElementById('nav-community-fav-box');
  const nav = document.getElementById('nav-community-fav');
  if (!box || !nav) { return; }
  clear(nav);
  const activeId = currentPageId();
  const ul = document.createElement('ul');
  ul.className = 'nav-tree';
  let dragging = null;
  let before = [];
  for (const page of pages) {
    const made = navPageRow(page, activeId);
    const li = made.li;
    li.setAttribute('data-fav-id', page.id);
    li.draggable = true;
    // 링크 자체가 끌리면(주소 끌기) 줄이 움직이지 않는다 — 줄(li)이 끌리게 링크의 끌기를 끈다
    made.link.draggable = false;
    made.link.setAttribute('aria-keyshortcuts', 'Alt+ArrowUp Alt+ArrowDown');
    made.link.addEventListener('keydown', (event) => {
      if (!event.altKey || (event.key !== 'ArrowUp' && event.key !== 'ArrowDown')) { return; }
      event.preventDefault();
      const was = favoriteOrder(ul);
      if (event.key === 'ArrowUp' && li.previousElementSibling) {
        ul.insertBefore(li, li.previousElementSibling);
      } else if (event.key === 'ArrowDown' && li.nextElementSibling) {
        ul.insertBefore(li.nextElementSibling, li);
      }
      made.link.focus();
      saveFavoriteOrder(ul, was);
    });
    li.addEventListener('dragstart', (event) => {
      dragging = li;
      before = favoriteOrder(ul);
      if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', page.id);
      }
    });
    li.addEventListener('dragover', (event) => {
      if (dragging === null || dragging === li) { return; }
      event.preventDefault();
      const r = li.getBoundingClientRect();
      ul.insertBefore(dragging, event.clientY > r.top + r.height / 2 ? li.nextSibling : li);
    });
    li.addEventListener('drop', (event) => { event.preventDefault(); });
    li.addEventListener('dragend', () => {
      if (dragging === null) { return; }
      dragging = null;
      saveFavoriteOrder(ul, before);
    });
    ul.appendChild(li);
  }
  nav.appendChild(ul);
  show(box, pages.length > 0);
}

/* T:F-72 라운드 C · 결정 PK-D5 — 좁은 폭(960px 미만) 「내 공간」 첫 화면 맨 위의 즐겨찾기(`#community-home-fav`, 홈 화면에만 있다).
   사이드바와 **같은 답 · 같은 줄**(navPageRow — ☆ 로 빼기도 같다)을 한 번 더 그린다. 넓은 폭은 사이드바가 늘 보이므로 숨긴다. */
const HOME_FAV_NARROW = '(max-width: 959.98px)';
let homeFavPages = [];
let homeFavWatch = false;
function drawHomeFavorites(pages) {
  const box = document.getElementById('community-home-fav');
  const list = document.getElementById('community-home-fav-list');
  if (!box || !list) { return; }
  homeFavPages = pages;
  clear(list);
  const ul = document.createElement('ul');
  ul.className = 'nav-tree';
  for (const page of pages) { ul.appendChild(navPageRow(page, '').li); }
  list.appendChild(ul);
  let mq = null;
  try { mq = window.matchMedia(HOME_FAV_NARROW); } catch (e) { mq = null; }
  if (mq && !homeFavWatch && typeof mq.addEventListener === 'function') {
    homeFavWatch = true;
    mq.addEventListener('change', () => { drawHomeFavorites(homeFavPages); });
  }
  show(box, pages.length > 0 && mq !== null && mq.matches);
}

/** 즐겨찾기를 받아 사이드바를 그리고, 등록된 곳(☆ 메뉴)에 알린다 */
function loadFavoritesNav() {
  return api('GET', '/api/me/favorites').then((result) => {
    if (result.status === 401) { toLogin(); return []; }
    const pages = result.status === 200 && Array.isArray(result.data.pages) ? result.data.pages : [];
    favoriteIds = new Set(pages.map((p) => p.id));
    drawFavoritesNav(pages);
    drawHomeFavorites(pages);
    for (const fn of favoriteListeners) { fn(); }
    return pages;
  }).catch(() => []);
}

/** 접은 상태를 이 브라우저에만 기억한다(화면 취향 — 저장이 막혀도 기본은 펼침) */
function bindFavoritesFold() {
  const box = document.getElementById('nav-community-fav-box');
  if (!box) { return; }
  try {
    if (window.localStorage.getItem(FAV_OPEN_KEY) === '0') { box.open = false; }
  } catch (e) { /* 저장소를 못 쓰면 늘 펼친 채로 시작한다 */ }
  box.addEventListener('toggle', () => {
    try { window.localStorage.setItem(FAV_OPEN_KEY, box.open ? '1' : '0'); } catch (e) { /* 무시 */ }
  });
}

function drawRecentNav(pages) {
  const title = document.getElementById('nav-community-recent-title');
  const nav = document.getElementById('nav-community-recent');
  if (!nav) { return; }
  clear(nav);
  const activeId = currentPageId();
  const ul = document.createElement('ul');
  ul.className = 'nav-tree';
  for (const page of pages.slice(0, 5)) {
    ul.appendChild(navPageRow(page, activeId).li);
  }
  nav.appendChild(ul);
  if (pages.length > 0) {
    const more = document.createElement('button');
    more.type = 'button';
    more.className = 'nav__item';
    more.id = 'nav-community-recent-more';
    more.setAttribute('aria-haspopup', 'dialog');
    more.appendChild(makeIcon('log'));
    const label = document.createElement('span');
    label.textContent = '더 보기';
    more.appendChild(label);
    more.addEventListener('click', openRecentDialog);
    nav.appendChild(more);
  }
  show(nav, pages.length > 0);
  show(title, pages.length > 0);
}

function loadRecentNav() {
  return api('GET', '/api/me/recent').then((result) => {
    if (result.status === 401) { toLogin(); return []; }
    recentPages = result.status === 200 && Array.isArray(result.data.pages) ? result.data.pages : [];
    drawRecentNav(recentPages);
    return recentPages;
  }).catch(() => []);
}

function fillRecentDialog() {
  const list = document.getElementById('recent-list');
  const empty = document.getElementById('recent-empty');
  const clearButton = document.getElementById('recent-clear');
  clear(list);
  for (const page of recentPages) {
    const li = document.createElement('li');
    li.className = 'list__item';
    const head = document.createElement('div');
    head.className = 'list__head';
    const link = document.createElement('a');
    link.setAttribute('href', COMMUNITY_BASE + '/p/' + page.id);
    link.textContent = (page.icon ? page.icon + ' ' : '') + page.title;
    head.appendChild(link);
    li.appendChild(head);
    const body = document.createElement('div');
    body.className = 'list__body';
    const where = document.createElement('span');
    where.textContent = page.space && typeof page.space.name === 'string' ? page.space.name : '';
    const when = document.createElement('span');
    when.className = 'muted';
    when.textContent = shortWhen(page.last_viewed_at);
    if (typeof page.last_viewed_at === 'string') { when.setAttribute('title', page.last_viewed_at); }
    body.appendChild(where);
    body.appendChild(when);
    li.appendChild(body);
    list.appendChild(li);
  }
  show(empty, recentPages.length === 0);
  if (clearButton) { clearButton.disabled = recentPages.length === 0; }
}

let recentDialogBound = false;

function openRecentDialog() {
  const dialog = document.getElementById('recent-dialog');
  if (!dialog) { return; }
  const msg = document.getElementById('recent-msg');
  const clearButton = document.getElementById('recent-clear');
  if (!recentDialogBound) {
    recentDialogBound = true;
    // 좁은 화면에서는 사이드바가 숨는다 — 창을 body 끝으로 옮겨 둔다(알림 창과 같은 까닭)
    document.body.appendChild(dialog);
    for (const close of dialog.querySelectorAll('[data-recent-close]')) {
      close.addEventListener('click', () => dialog.close());
    }
    dialog.addEventListener('close', () => {
      if (clearButton) { delete clearButton.dataset.armed; clearButton.textContent = '최근 기록 지우기'; }
    });
    if (clearButton) {
      // 지우기 — 되돌릴 수 없는 동작이라 한 번 더 누르게 한다(DESIGN 4장 「위험 동작 → 확인」 · 댓글 지우기와 같다)
      clearButton.addEventListener('click', () => {
        if (clearButton.dataset.armed !== '1') {
          clearButton.dataset.armed = '1';
          clearButton.textContent = '정말 지우기';
          return;
        }
        clearButton.disabled = true;
        setText(msg, '');
        api('DELETE', '/api/me/recent').then((result) => {
          if (result.status === 401) { toLogin(); return; }
          delete clearButton.dataset.armed;
          clearButton.textContent = '최근 기록 지우기';
          if (result.status !== 200) {
            clearButton.disabled = false;
            setText(msg, errorMessage(result.data));
            return;
          }
          recentPages = [];
          drawRecentNav(recentPages);
          fillRecentDialog();
        }).catch(() => { clearButton.disabled = false; setText(msg, GENERIC_ERROR); });
      });
    }
  }
  setText(msg, '');
  fillRecentDialog();
  dialog.showModal();
  // 창을 연 뒤 창구의 지금 답으로 한 번 더 맞춘다(다른 탭에서 연 페이지까지)
  loadRecentNav().then(() => { if (dialog.open) { fillRecentDialog(); } });
}

/* ─── 사이드바 트리 줄의 ☆ (개발 대기열 6 · Q:F-3a 라운드 B — 지시서 1.1 「넣기 | … 트리 항목 메뉴」) ───────────
 *
 * 사이드바의 페이지 줄(내 공간 · 팀 공간 · 공유받은 페이지 · 즐겨찾기 · 최근)마다 끝에 ☆ 단추 하나. 항목 메뉴(「⋯」)로 만들지 않은
 * 까닭: 항목이 하나뿐인 메뉴는 DESIGN 2.15 「하지 말 것」이다(정의된 항목이 둘 이하인 메뉴 → 그냥 단추). 모양은 줄의 펼침 단추와 같은
 * `.nav-tree__toggle`(20px · 테두리 없음) — 새 클래스 0. 넣은 줄(★)은 늘 보이고, 안 넣은 줄은 줄에 올리거나 초점이 있을 때만
 * 보인다(community.css — 「하위 추가」와 같은 규칙).
 * 넣을 수 있는지는 창구가 판단한다(`PUT /api/pages/{id}/favorite` — 그 순간 읽을 수 있어야 · 못 읽으면 404 문구). 화면은 창구가 준
 * 즐겨찾기 목록(`favoriteIds`)에 있나 없나로만 별을 칠한다.
 */
function navStar(page) {
  const title = (page.icon ? page.icon + ' ' : '') + page.title;
  const button = document.createElement('button');
  button.type = 'button';
  button.className = 'nav-tree__toggle';
  button.setAttribute('data-fav-toggle', page.id);
  button.setAttribute('data-fav-title', title);
  paintNavStar(button);
  button.addEventListener('click', (event) => {
    event.preventDefault();
    const on = favoriteIds.has(page.id);
    button.disabled = true;
    api(on ? 'DELETE' : 'PUT', '/api/pages/' + page.id + '/favorite', {})
      .then((result) => {
        button.disabled = false;
        if (result.status === 401) { toLogin(); return null; }
        if (result.status !== 200) { setText(document.getElementById('msg'), errorMessage(result.data)); return null; }
        return loadFavoritesNav();   // 목록·모든 ☆ 를 창구의 답으로 다시 맞춘다
      })
      .catch(() => { button.disabled = false; setText(document.getElementById('msg'), GENERIC_ERROR); });
  });
  return button;
}

function paintNavStar(button) {
  const id = button.getAttribute('data-fav-toggle') || '';
  const on = favoriteIds.has(id);
  button.replaceChildren(makeIcon(on ? 'star-fill' : 'star'));
  button.setAttribute('aria-pressed', on ? 'true' : 'false');
  button.setAttribute('aria-label', '「' + (button.getAttribute('data-fav-title') || '') + '」 ' + (on ? '즐겨찾기에서 빼기' : '즐겨찾기에 넣기'));
}

/** 즐겨찾기를 새로 받을 때마다 사이드바의 모든 ☆ 를 맞춘다 */
function paintNavStars() {
  for (const button of document.querySelectorAll('#sidebar [data-fav-toggle]')) { paintNavStar(button); }
}
favoriteListeners.push(paintNavStars);

/* ─── 일괄 작업 진행기 (개발 대기열 6 · Q:F-3a 라운드 B — 지시서 1.3 · 결정 M-4 · M-7) ───────────────────
 *
 * 원칙: **새 서버 창구 0** — 한 건 작업과 같은 창구를 항목마다 한 번씩, 차례로 부른다(t-box F-27 일괄 올리기·파일 화면 일괄과 같은 방식).
 * 할 수 있는지는 창구가 항목마다 판단하고(판정·감사·거부 문장이 한 건과 같다), 화면은 그 답을 줄마다 적을 뿐이다(DESIGN 원칙 1).
 *   · 줄 상태: 대기 → 하는 중… → 완료 글자 / 창구가 준 거부 문장(F-68). 423 은 그 줄만 「나중에」.
 *   · 부모와 그 하위를 함께 골랐으면 하위는 보내지 않는다 — 부모가 되면 「부모와 함께 …」, 부모가 거부되면 「… 부모를 … 못 했습니다」
 *     (순서 섞임·깊이 오류를 막는다 — 지시서 1.3). 부모가 늘 하위보다 먼저 오게 트리 순서로 보낸다.
 *   · 401 이면 멈추고 남은 줄을 그대로 둔다 → 새 탭에서 로그인한 뒤 「이어서 하기」: `/api/me` 를 새로 물어 **같은 계정**이면 새 CSRF
 *     토큰으로 그 줄부터(다른 계정이면 잇지 않는다 — QF3A-T7). 403 reauth 는 셸의 재인증 창을 띄우고 통과하면 그 줄부터.
 *     「이어서 하기」는 멈춘 까닭과 상관없이 보내기 전에 `/api/me` 로 같은 계정인지 보고, 새 토큰을 셸에도 넘긴다(`window.qarediSetCsrf`).
 *     구간(시작 · 이어 가기)의 첫 403 에서도 `/api/me` 로 세션이 그대로인지 본다 — 바뀌었으면 거부로 세지 않고 멈춘다.
 *   · 「되돌리기」(휴지통으로 보낸 것 — M-7 커뮤니티는 바로): 10초 동안, 지운 페이지마다 `POST /api/pages/{id}/restore`.
 *   · 글자는 전부 textContent · 인라인 style 0 · 새 클래스 0(`.upload-status` 진행 표 · `.notice` · `.table-foot` 그대로).
 *
 * @param {Object} el     진행 표 요소들 {panel, title, body, stop, stopText, login, resume, summary, undo?, close?}
 * @param {Object} hooks  {onChange?() — 도는 중인지가 바뀔 때, beforeReauth?() · afterReauth?() — 재인증 창을 띄우기 전·뒤(모달 안 모달 0)}
 */
const BULK_PICK_MAX = 100;   // 결정 M-4
const BULK_UNDO_MS = 10000;  // 결정 M-7
/** 「이어서 하기」가 같은 계정인지 견줄 아이디(boot 가 `/api/me` 의 username 으로 채운다 — 역할로 보기 중에도 실제 계정) */
let meUsername = null;

function createBulkRunner(el, hooks) {
  let job = null;
  let undoTimer = null;
  let undoJob = null;

  function setState(item, text, cls) {
    setText(item.stateCell, text);
    item.stateCell.className = cls ? 'upload-status__state ' + cls : 'upload-status__state';
  }
  function busy() { return job !== null && !job.finished; }
  function changed() { if (hooks && typeof hooks.onChange === 'function') { hooks.onChange(); } }

  /** 「n개 완료 · m개 못 함」 — 부모와 함께 된 하위는 완료, 나중에는 못 함 */
  function tally(j) {
    let ok = 0;
    let no = 0;
    for (const item of j.items) {
      if (item.result === 'ok') { ok += 1; } else if (item.result === 'deny' || item.result === 'later') { no += 1; }
    }
    return String(ok) + '개 완료 · ' + String(no) + '개 못 함';
  }

  function hideUndo() {
    if (undoTimer !== null) { window.clearTimeout(undoTimer); undoTimer = null; }
    undoJob = null;
    show(el.undo, false);
  }

  function start(next) {
    if (busy() || !el.panel || !el.body) { return false; }
    hideUndo();
    job = next;
    job.index = 0;
    job.finished = false;
    job.paused = null;
    job.sessionChecked = false;
    clear(el.body);
    for (const item of job.items) {
      const tr = document.createElement('tr');
      const name = document.createElement('td');
      name.textContent = item.name;
      tr.appendChild(name);
      item.stateCell = document.createElement('td');
      tr.appendChild(item.stateCell);
      item.result = 'wait';
      setState(item, item.parent ? '대기 — 부모와 함께' : '대기', '');
      el.body.appendChild(tr);
    }
    setText(el.title, job.title + ' · ' + String(job.items.length) + '개');
    show(el.stop, false);
    setText(el.summary, '');
    if (el.close) { el.close.disabled = true; }
    show(el.panel, true);
    el.title.focus();
    changed();
    step(job);
    return true;
  }

  function step(j) {
    if (job !== j || j.paused !== null) { return; }
    // 부모와 함께 고른 하위 — 보내지 않고 부모의 결과로 적는다(부모가 트리 순서상 먼저 끝났다)
    while (j.index < j.items.length && j.items[j.index].parent) {
      const kid = j.items[j.index];
      if (kid.parent.result === 'ok') {
        kid.result = 'ok';
        setState(kid, j.withParentText || '부모와 함께 처리됨', 'is-ok');
      } else {
        kid.result = 'deny';
        setState(kid, j.parentFailText || '부모를 처리하지 못해 그대로 두었습니다', 'is-deny');
      }
      j.index += 1;
    }
    if (j.index >= j.items.length) { finish(j); return; }
    const item = j.items[j.index];
    setState(item, '하는 중…', 'is-busy');
    j.send(item)
      .then((result) => {
        if (result.status === 401) { pause(j, 'login'); return 'stop'; }
        if (result.status === 403 && result.data && result.data.next === 'reauth' && typeof window.qarediHandleReauth === 'function') {
          pause(j, 'reauth');
          if (hooks && typeof hooks.beforeReauth === 'function') { hooks.beforeReauth(); }
          window.qarediHandleReauth({ status: 403 }, result.data, () => {
            if (hooks && typeof hooks.afterReauth === 'function') { hooks.afterReauth(); }
            cont(j);
          });
          return 'stop';
        }
        if (result.status === 403 && !j.sessionChecked) {
          // 이 구간의 첫 403 — 거부로 세기 전에 세션이 그대로인지 `/api/me` 로 본다(다른 탭에서 다시 로그인했으면 이 탭의
          // CSRF 토큰이 옛 세션 것이라 줄마다 403 이 된다 — 그건 거부가 아니라 멈출 일이다)
          j.sessionChecked = true;
          return sessionCheck().then((why) => {
            if (why !== null) { pause(j, why); return 'stop'; }
            item.result = 'deny';
            setState(item, errorMessage(result.data), 'is-deny');   // 세션은 그대로 — 창구의 거부 문장 그대로
            return 'next';
          });
        }
        if (result.status >= 200 && result.status < 300) {
          item.result = 'ok';
          setState(item, typeof j.okText === 'function' ? j.okText(result.data) : j.okText, 'is-ok');
        } else if (result.status === 423) {
          item.result = 'later';
          setState(item, '나중에 — ' + errorMessage(result.data), 'is-deny');
        } else {
          item.result = 'deny';
          setState(item, errorMessage(result.data), 'is-deny');   // 한 건 작업과 같은 서버 문장 그대로
        }
        return 'next';
      })
      .catch(() => {
        item.result = 'deny';
        setState(item, GENERIC_ERROR, 'is-deny');
        return 'next';
      })
      .then((what) => {
        if (what === 'next') { j.index += 1; step(j); }
      });
  }

  /**
   * 세션이 이 작업을 시작한 그대로인가 — `/api/me` 를 새로 묻는다(셸과 같은 창구 · no-store). 그대로면 null, 아니면 멈출 까닭:
   * 'login' 세션 없음(401) · 'other' 다른 계정 · 'session' 같은 계정이지만 세션이 바뀜(토큰이 세션 id 로 계산되므로 토큰이 다르다) ·
   * 'check' 물어보지 못함 — 모르는 채 거부로 세지 않는다.
   */
  function sessionCheck() {
    if (typeof window.qarediMe !== 'function') { return Promise.resolve('check'); }
    return window.qarediMe(true).then((result) => {
      if (result.status === 401 || !result.me) { return 'login'; }
      if (meUsername === null || result.me.username !== meUsername) { return 'other'; }
      if (result.me.csrf_token !== csrfToken) { return 'session'; }
      return null;
    }, () => 'check');
  }

  function pause(j, why) {
    j.paused = why;
    const left = j.items.length - j.index;
    const item = j.items[j.index];
    if (why === 'login') {
      setState(item, '멈춤 — 로그인이 필요합니다', 'is-deny');
      setText(el.stopText, '로그인이 끝나 멈췄습니다 — 남은 ' + String(left) + '개는 그대로 있습니다. 새 탭에서 다시 로그인한 뒤 「이어서 하기」를 누르세요.');
    } else if (why === 'other') {
      setState(item, '멈춤 — 다른 계정으로 로그인됨', 'is-deny');
      setText(el.stopText, '다른 계정으로 로그인했습니다 — 이어서 할 수 없습니다. 이 화면을 새로 고친 뒤 다시 고르세요.');
    } else if (why === 'session') {
      setState(item, '멈춤 — 로그인이 바뀌었습니다', 'is-deny');
      setText(el.stopText, '다른 탭에서 다시 로그인해 멈췄습니다 — 남은 ' + String(left) + '개는 그대로 있습니다. 「이어서 하기」를 누르면 같은 계정인지 확인하고 이어 갑니다.');
    } else if (why === 'check') {
      setState(item, '멈춤 — 로그인 상태를 확인하지 못했습니다', 'is-deny');
      setText(el.stopText, '로그인 상태를 확인하지 못해 멈췄습니다 — 남은 ' + String(left) + '개는 그대로 있습니다. 「이어서 하기」를 누르면 다시 확인합니다.');
    } else {
      setState(item, '멈춤 — 비밀번호 확인이 필요합니다', 'is-deny');
      setText(el.stopText, '비밀번호 확인이 필요해 멈췄습니다 — 남은 ' + String(left) + '개는 그대로 있습니다. 「이어서 하기」를 누르면 다시 묻습니다.');
    }
    show(el.login, why === 'login');
    show(el.resume, why !== 'other');   // 다른 계정이면 잇지 않는다
    if (el.resume) { el.resume.disabled = false; }
    show(el.stop, true);
    if (el.close) { el.close.disabled = false; }
    setText(el.summary, tally(j) + ' · 남은 ' + String(left) + '개');
    changed();
  }

  function cont(j) {
    if (job !== j || j.paused === null) { return; }
    j.paused = null;
    j.sessionChecked = false;   // 이어 간 구간의 첫 403 도 다시 세션을 본다
    show(el.stop, false);
    if (el.close) { el.close.disabled = true; }
    setText(el.summary, '');
    changed();
    step(j);
  }

  /* 「이어서 하기」 — 멈춘 까닭과 상관없이(로그인 · 재인증 · 세션 바뀜 · 확인 못 함) **보내기 전에** `/api/me` 를 새로 물어 같은 계정인지
     본다. 다른 계정이면 요청 0 으로 끝. 같은 계정이면 새 CSRF 토큰(이 모듈 · 셸의 재인증 창 · 로그아웃 둘 다)으로 멈춘 줄부터.
     재인증 멈춤이면 그 줄을 다시 보내 창구가 또 403 reauth 를 주고, 그때 셸의 재인증 창이 뜬다. */
  function resumeClick() {
    const j = job;
    if (j === null || j.paused === null) { return; }
    if (el.resume) { el.resume.disabled = true; }
    const ask = typeof window.qarediMe === 'function'
      ? window.qarediMe(true)
      : Promise.resolve({ status: 0, me: null });
    ask.then((result) => {
      if (el.resume) { el.resume.disabled = false; }
      if (job !== j || j.paused === null) { return; }
      if (result.status === 401 || !result.me) {
        if (j.paused !== 'login') { pause(j, 'login'); }   // 세션이 끝났다 — 로그인 멈춤으로(「로그인 화면 열기」가 보인다)
        setText(el.stopText, '아직 로그인되지 않았습니다 — 새 탭에서 로그인한 뒤 다시 「이어서 하기」를 누르세요. 남은 ' + String(j.items.length - j.index) + '개는 그대로 있습니다.');
        return;
      }
      if (meUsername === null || result.me.username !== meUsername) {
        // 다른 계정으로 로그인했다 — 그 계정으로 이 작업을 잇지 않는다
        pause(j, 'other');
        return;
      }
      if (typeof result.me.csrf_token === 'string') {
        csrfToken = result.me.csrf_token;   // 새 세션의 토큰
        if (typeof window.qarediSetCsrf === 'function') { window.qarediSetCsrf(result.me.csrf_token); }   // 셸도 같은 값으로
      }
      cont(j);
    }).catch(() => {
      if (el.resume) { el.resume.disabled = false; }
      setText(el.stopText, GENERIC_ERROR);
    });
  }

  function finish(j) {
    j.finished = true;
    show(el.stop, false);
    if (el.close) { el.close.disabled = false; }
    let line = tally(j);
    const undoItems = j.items.filter((item) => item.result === 'ok' && !item.parent);
    if (typeof j.undo === 'function' && el.undo && undoItems.length > 0) {
      line += ' — ' + String(BULK_UNDO_MS / 1000) + '초 안에 되돌릴 수 있습니다';
      undoJob = j.undo(undoItems.map((item) => ({ id: item.id, name: item.name })));
      show(el.undo, true);
      undoTimer = window.setTimeout(hideUndo, BULK_UNDO_MS);
    }
    setText(el.summary, line);
    changed();
    if (typeof j.done === 'function') { j.done(j); }
  }

  if (el.resume) { el.resume.addEventListener('click', resumeClick); }
  if (el.undo) {
    el.undo.addEventListener('click', () => {
      const next = undoJob;
      hideUndo();
      if (next) { start(next); }
    });
  }
  if (el.close) {
    el.close.addEventListener('click', () => {
      // 끝났거나 멈춘 작업만 닫힌다(도는 동안은 단추가 비활성). 멈춘 작업을 닫으면 남은 줄은 보내지 않는다.
      if (job !== null && !job.finished && job.paused === null) { return; }
      job = null;
      hideUndo();
      show(el.panel, false);
      changed();
    });
  }
  return {
    start: start,
    busy: busy,
    /** 창을 다시 열 때 — **끝난** 작업의 진행 표를 치운다(도는 중이거나 멈춘 작업은 남은 줄과 함께 그대로 둔다) */
    reset() {
      if (busy()) { return; }
      job = null;
      hideUndo();
      show(el.panel, false);
      changed();
    }
  };
}

/** 트리 순서(부모가 하위보다 먼저)로 고른 페이지를 늘어놓고, 고른 조상이 있는 것에는 그 조상 항목을 `parent` 로 단다 */
function bulkTreeItems(pickedIds, pages) {
  const byParent = new Map();
  const byId = new Map();
  for (const page of pages) {
    byId.set(page.id, page);
    const key = typeof page.parent_id === 'string' ? page.parent_id : '';
    if (!byParent.has(key)) { byParent.set(key, []); }
    byParent.get(key).push(page);
  }
  const order = [];
  const walk = (key, depth) => {
    if (depth > 16) { return; }
    for (const node of byParent.get(key) || []) { order.push(node); walk(node.id, depth + 1); }
  };
  walk('', 0);
  const items = [];
  const itemOf = new Map();
  for (const node of order) {
    if (!pickedIds.has(node.id)) { continue; }
    const item = { id: node.id, name: (node.icon ? node.icon + ' ' : '') + node.title, parent: null };
    // 가장 가까운 고른 조상(있으면) — 그 조상과 함께 처리된다
    let up = typeof node.parent_id === 'string' ? byId.get(node.parent_id) : undefined;
    for (let guard = 0; up && guard < 16; guard++) {
      if (itemOf.has(up.id)) { item.parent = itemOf.get(up.id); break; }
      up = typeof up.parent_id === 'string' ? byId.get(up.parent_id) : undefined;
    }
    itemOf.set(node.id, item);
    items.push(item);
  }
  return items;
}

/* ─── 내 공간 (community-home) ───────────────────────────────── */

/** 손님 「내 공간」 설명 — home_page.php 가 서버에서 고르는 글자와 같은 문장(디버깅 묶음 U3) */
const GUEST_SPACE_SUB = '손님 계정은 읽기만 합니다. 공유받은 페이지와 볼 수 있는 게시판 글을 읽을 수 있습니다.';

function trustListBadges(page,box) {
  for (const text of [page.page_kind==='procedure' ? '절차서' : '',
    ({verified:'검증됨',expired:'검증 만료'})[page.verify_state] || '',page.due ? '기한 '+page.due : '']) {
    if (!text) continue;const badge=document.createElement('span');badge.className='badge badge--internal';badge.textContent=text;box.appendChild(badge);
  }
}
function initHome() {
  const msg = document.getElementById('msg');
  const list = document.getElementById('community-list');
  const treeBox = document.getElementById('community-tree');
  const empty = document.getElementById('community-empty');
  const count = document.getElementById('community-count');
  const search = document.getElementById('community-search');
  const spaceSelect = document.getElementById('community-space');
  const spaceLabel = document.getElementById('community-space-label');
  const headTitle = document.getElementById('community-space-title');
  const headSub = document.getElementById('community-space-sub');
  const statusSelect = document.getElementById('community-status');
  const verifySelect = document.getElementById('community-verify');
  const dueSelect = document.getElementById('community-due');
  const sharedWrap = document.getElementById('community-shared-wrap');
  const sharedBox = document.getElementById('community-shared');
  const sharedCount = document.getElementById('community-shared-count');
  const spaceId = currentSpaceId();
  let timer = null;

  /** 지금 고른 상태 거르개. 값은 `<option>` 에 적힌 것뿐이고 화면이 지어내지 않는다 (4-D). */
  function pickedStatus() {
    return statusSelect ? statusSelect.value : '';
  }

  /** 목록 창구 주소 — 고른 공간과 상태를 꼬리표로 붙인다 (4-B · 4-D). */
  function listPath(extra) {
    const parts = [];
    if (spaceId !== '') { parts.push('space=' + encodeURIComponent(spaceId)); }
    const status = pickedStatus();
    if (status !== '') { parts.push('status=' + encodeURIComponent(status)); }
    if (verifySelect && verifySelect.value) parts.push('verify=' + encodeURIComponent(verifySelect.value));
    if (dueSelect && dueSelect.value) parts.push('due=' + encodeURIComponent(dueSelect.value));
    if (extra) { parts.push(extra); }
    return '/api/pages' + (parts.length > 0 ? '?' + parts.join('&') : '');
  }

  /** 화면 머리를 창구가 준 공간 요약으로 바꾼다. 화면이 이름을 지어내지 않는다. */
  function drawSpaceHead(space) {
    if (!space) { return; }
    drawAutomationSpace(space, api);
    setText(headTitle, space.kind === 'team' ? space.name : '내 공간');
    // 디버깅 묶음 U3 — 손님(쓰기 자격 없음 — `/api/me` 의 can_community_write, 서버 판정 값)에게는 할 수 없는 「공개 범위」 문장 대신 읽기 안내
    setText(headSub, space.kind === 'team'
      ? '팀이 함께 쓰는 공간입니다. 팀원 누구나 페이지를 만들고 고칠 수 있습니다.'
      : (meCanWrite
        ? '나만 보는 개인 업무 공간입니다. 페이지마다 공개 범위를 고를 수 있습니다.'
        : GUEST_SPACE_SUB));
    document.title = (space.kind === 'team' ? space.name : '내 공간') + ' · 커뮤니티';
  }

  // 지금 보고 있는 공간의 종류. 창구가 준 값이고 화면이 정하지 않는다.
  let spaceKind = 'personal';

  function card(page) {
    const a = document.createElement('a');
    a.className = 'card card--link';
    a.setAttribute('href', COMMUNITY_BASE + '/p/' + page.id);

    const title = document.createElement('span');
    title.className = 'card__title';
    title.textContent = (page.icon ? page.icon + ' ' : '') + page.title;

    const desc = document.createElement('span');
    desc.className = 'card__desc';
    desc.textContent = page.excerpt && page.excerpt !== '' ? page.excerpt : '(내용 없음)';

    const when = document.createElement('span');
    when.className = 'muted';
    when.textContent = shortWhen(page.updated_at);

    a.appendChild(title);
    a.appendChild(desc);
    trustListBadges(page,a);
    // 공개 범위 배지 (4-B). 팀 공간의 페이지에는 자기 범위가 없어 값이 와도 그리지 않는다.
    const mark = spaceKind === 'team' ? null : visibilityBadge(page.visibility);
    if (mark) { a.appendChild(mark); }
    // 상태 배지 (4-D). 창구가 준 값이고, 없으면 아무것도 붙지 않는다.
    // 4-D 의 담당자 표시는 5-A 에서 뺐다 — 담당자는 이제 소유자이고, 지정을 멈췄다.
    const st = statusBadge(page.status);
    if (st) { a.appendChild(st); }
    a.appendChild(when);
    return a;
  }

  /** 「공유받은 페이지」 줄 하나 — 누구의 공간인지와 받은 권한을 함께 보인다 (5-A).
      구조는 DESIGN 2.3 의 누를 수 있는 카드 그대로다: `.card__body` > `.card__head`(제목 + 배지) ·
      `.card__desc`(어느 공간) · `.card__meta`(고친 때). 새 클래스 0. */
  function sharedCard(page) {
    const a = document.createElement('a');
    a.className = 'card card--link';
    a.setAttribute('href', COMMUNITY_BASE + '/p/' + page.id);

    const bodyBox = document.createElement('span');
    bodyBox.className = 'card__body';
    const head = document.createElement('span');
    head.className = 'card__head';
    const title = document.createElement('span');
    title.className = 'card__title';
    title.textContent = (page.icon ? page.icon + ' ' : '') + page.title;
    head.appendChild(title);
    const level = shareBadge(page.share_level);
    if (level) { head.appendChild(level); }
    const st = statusBadge(page.status);
    if (st) { head.appendChild(st); }
    bodyBox.appendChild(head);
    trustListBadges(page,bodyBox);

    const where = document.createElement('span');
    where.className = 'card__desc';
    where.textContent = page.space && typeof page.space.name === 'string' ? page.space.name : '';
    bodyBox.appendChild(where);

    const when = document.createElement('span');
    when.className = 'card__meta';
    when.textContent = shortWhen(page.updated_at);
    bodyBox.appendChild(when);

    a.appendChild(bodyBox);
    return a;
  }

  /** 공유받은 페이지 — 공간을 가리지 않는 하나의 창구 호출이다 (SQL WHERE, C:T-57). */
  function loadShared() {
    const status = pickedStatus();
    const path = '/api/pages?shared=me' + (status === '' ? '' : '&status=' + encodeURIComponent(status));
    return api('GET', path).then((result) => {
      if (result.status !== 200) { show(sharedWrap, false); return; }
      const pages = Array.isArray(result.data.pages) ? result.data.pages : [];
      clear(sharedBox);
      for (const page of pages) { sharedBox.appendChild(sharedCard(page)); }
      setText(sharedCount, pages.length === 0 ? '' : String(result.data.total) + '개');
      // 공유받은 페이지가 하나도 없으면 묶음 자체를 보이지 않는다(빈 칸을 늘리지 않는다).
      show(sharedWrap, pages.length > 0);
    }).catch(() => { show(sharedWrap, false); });
  }

  /** 트리 한 줄 — 제목 링크 + 「하위 추가」. 하위가 있으면 `<details>` 안에 들어간다. */
  function treeRow(node, kidCount) {
    const row = document.createElement('span');
    row.className = 'page-tree__row';
    // 라운드 B — 「선택」 모드면 줄 맨 앞에 체크(`<summary>` 안이어도 체크를 누르면 접힘·펼침은 일어나지 않는다 — 체크가 누름의 대상)
    if (sel.on) { row.appendChild(selCheck(node)); }

    // 삼각형 자리. 하위가 없어도 자리를 비워 둬 제목의 왼쪽 끝이 층마다 가지런하다.
    const caret = document.createElement('span');
    caret.className = 'page-tree__caret';
    caret.setAttribute('aria-hidden', 'true');
    caret.textContent = kidCount > 0 ? '▸' : '';

    const link = document.createElement('a');
    link.className = 'page-tree__link';
    link.setAttribute('href', COMMUNITY_BASE + '/p/' + node.id);
    link.textContent = (node.icon ? node.icon + ' ' : '') + node.title;

    const mark = spaceKind === 'team' ? null : visibilityBadge(node.visibility);

    /* 독립 검토 L-1 — 「하위 추가」는 **쓸 수 있는 사람에게만** 만든다(`/api/me` 의 can_community_write — 위 `meCanWrite`).
       손님에게는 줄에 올리면 나타났다가 누르면 권한 없음만 나오던 단추다. 숨김은 편의이고 `POST /api/pages` 는 정책이 다시 막는다. */
    const add = meCanWrite ? document.createElement('button') : null;
    if (add) {
    add.type = 'button';
    add.className = 'btn btn--small btn--quiet page-tree__add';
    add.textContent = '하위 추가';
    add.addEventListener('click', (event) => {
      // `<summary>` 안에서 눌렸을 때 접힘·펼침이 함께 일어나지 않게 기본 동작을 멈춘다.
      event.preventDefault();
      event.stopPropagation();
      add.disabled = true;
      // 부모가 정해지면 공간도 따라 정해진다 — 창구가 부모의 공간을 그대로 쓴다.
      api('POST', '/api/pages', { parent_id: node.id })
        .then((result) => {
          if (result.status === 401) { toLogin(); return; }
          if (result.status === 201 && result.data.page && typeof result.data.page.id === 'string') {
            window.location.assign(COMMUNITY_BASE + '/p/' + result.data.page.id);
            return;
          }
          setText(msg, errorMessage(result.data));
          add.disabled = false;
        })
        .catch(() => { setText(msg, GENERIC_ERROR); add.disabled = false; });
    });
    }

    row.appendChild(caret);
    row.appendChild(link);
    trustListBadges(node,row);
    if (mark) { row.appendChild(mark); }
    // 상태 배지 (4-D) — 창구가 준 값이고, 없으면 아무것도 붙지 않는다.
    const st = statusBadge(node.status);
    if (st) { row.appendChild(st); }
    if (kidCount > 0) {
      const badge = document.createElement('span');
      badge.className = 'muted';
      badge.textContent = '하위 ' + String(kidCount);
      row.appendChild(badge);
    }
    if (add) { row.appendChild(add); }
    return row;
  }

  /**
   * 평평한 목록을 트리로 그린다. **접기·펼치기는 `<details>` 가 한다** — 여닫는 스크립트가
   * 없으므로 JS 가 멈춰 있어도(그리고 키보드로도) 동작한다.
   * 깊이는 서버가 8 까지만 허용하지만, 그래도 12 에서 멈춘다 — 자료가 어긋나도 화면이
   * 끝없이 파고들지 않게 하는 안전장치다.
   */
  function treeLevel(byParent, key, depth) {
    const box = document.createDocumentFragment();
    const nodes = byParent.get(key) || [];
    if (depth > 12) { return box; }

    for (const node of nodes) {
      const kids = byParent.get(node.id) || [];
      if (kids.length === 0) {
        const leaf = document.createElement('div');
        leaf.className = 'page-tree__node';
        leaf.appendChild(treeRow(node, 0));
        box.appendChild(leaf);
        continue;
      }
      const details = document.createElement('details');
      details.className = 'page-tree__node';
      details.open = true;
      const summary = document.createElement('summary');
      summary.appendChild(treeRow(node, kids.length));
      const kidBox = document.createElement('div');
      kidBox.className = 'page-tree__kids';
      kidBox.appendChild(treeLevel(byParent, node.id, depth + 1));
      details.appendChild(summary);
      details.appendChild(kidBox);
      box.appendChild(details);
    }
    return box;
  }

  function loadTree() {
    return api('GET', listPath('tree=1')).then((result) => {
      if (result.status === 401) { toLogin(); return; }
      if (result.status !== 200) { setText(msg, errorMessage(result.data)); return; }
      setText(msg, '');
      if (result.data.space) { spaceKind = result.data.space.kind; drawSpaceHead(result.data.space); }
      const pages = Array.isArray(result.data.pages) ? result.data.pages : [];
      treePages = pages;
      treeTotal = result.data.total;
      // 라운드 B — 새 트리에 없는 페이지(지웠거나 옮겨 나간 것)는 고른 것에서 뺀다
      const here = new Set(pages.map((p) => p.id));
      for (const id of Array.from(sel.picked.keys())) { if (!here.has(id)) { sel.picked.delete(id); } }
      drawTree();
    });
  }

  /** 받아 둔 트리(treePages)를 그린다 — 「선택」 모드를 켜고 끌 때도 창구를 다시 부르지 않고 이것만 부른다 */
  function drawTree() {
    clear(treeBox);
    const pages = treePages;
    const byParent = new Map();
    for (const page of pages) {
      const key = typeof page.parent_id === 'string' ? page.parent_id : '';
      if (!byParent.has(key)) { byParent.set(key, []); }
      byParent.get(key).push(page);
    }
    treeBox.appendChild(treeLevel(byParent, '', 0));
    show(treeBox, pages.length > 0);
    show(list, false);
    show(empty, pages.length === 0);
    setText(count, pages.length === 0 ? '' : '페이지 ' + String(treeTotal) + '개');
    syncSel();
  }

  /* ─── 「선택」 모드 · 일괄 작업 (개발 대기열 6 · Q:F-3a 라운드 B — 지시서 1.3 · 결정 M-4 · M-6 · M-7) ───────────
   * 트리 줄마다 체크 → 막대의 옮기기 · 즐겨찾기 · 휴지통. 100개까지. 공간을 바꾸면 화면이 다시 열려 풀리고(M-4), 상태 거르개를 바꾸면
   * 풀린다(목록이 바뀐다). 찾기(카드 목록)를 시작하면 모드가 끝난다. Esc · ✕ · 「선택 끝내기」로 끝낸다. 공개 범위 일괄은 없다(M-6).
   * 동작은 **한 건과 같은 창구**를 페이지마다 한 번씩(진행기 `createBulkRunner`) — 새 서버 창구 0. 판단은 창구가 한다.
   */
  let treePages = [];
  let treeTotal = 0;
  const sel = { on: false, picked: new Map(), full: false };
  const selButton = document.getElementById('community-select');
  const selLabel = document.getElementById('community-select-label');
  const bulkBar = document.getElementById('community-bulk-bar');
  const bulkCount = document.getElementById('community-bulk-count');
  const bulkNote = document.getElementById('community-bulk-note');
  const bulkMoveBtn = document.getElementById('community-bulk-move');
  const bulkFavBtn = document.getElementById('community-bulk-fav');
  const bulkTrashBtn = document.getElementById('community-bulk-trash');
  const bulkDoneBtn = document.getElementById('community-bulk-done');
  const runner = createBulkRunner({
    panel: document.getElementById('community-bulk-panel'),
    title: document.getElementById('community-bulk-title'),
    body: document.getElementById('community-bulk-body'),
    stop: document.getElementById('community-bulk-stop'),
    stopText: document.getElementById('community-bulk-stop-text'),
    login: document.getElementById('community-bulk-login'),
    resume: document.getElementById('community-bulk-resume'),
    summary: document.getElementById('community-bulk-summary'),
    undo: document.getElementById('community-bulk-undo'),
    close: document.getElementById('community-bulk-close')
  }, { onChange: () => syncSel() });

  function selCheck(node) {
    const check = document.createElement('input');
    check.type = 'checkbox';
    check.setAttribute('data-bulk', '');
    check.setAttribute('data-bulk-page', node.id);
    check.setAttribute('aria-label', '「' + (node.icon ? node.icon + ' ' : '') + node.title + '」 고르기');
    check.checked = sel.picked.has(node.id);
    check.addEventListener('click', (event) => {
      event.stopPropagation();   // 줄(요약) 누름으로 번지지 않게 — 체크 자체의 켜고 끄기는 그대로
      sel.full = false;
      if (check.checked) {
        if (sel.picked.size >= BULK_PICK_MAX) {
          check.checked = false;
          sel.full = true;
        } else {
          sel.picked.set(node.id, node);
        }
      } else {
        sel.picked.delete(node.id);
      }
      syncSel();
    });
    return check;
  }

  /** 체크·막대·「선택」 단추를 지금 상태에 맞춘다 */
  function syncSel() {
    for (const check of treeBox.querySelectorAll('input[data-bulk-page]')) {
      check.checked = sel.picked.has(check.getAttribute('data-bulk-page'));
    }
    if (selButton) {
      selButton.setAttribute('aria-pressed', sel.on ? 'true' : 'false');
      setText(selLabel, sel.on ? '선택 끝내기' : '선택하기');
    }
    const n = sel.picked.size;
    const running = runner.busy();
    setText(bulkCount, String(n) + '개 선택됨');
    // 안내는 막대 아래 한 줄(막대는 폰 폭에서도 한 줄 — 개수 + 아이콘 단추)
    let note = '줄 앞의 체크로 고르세요 — 한 번에 ' + String(BULK_PICK_MAX) + '개까지 · Esc 로 끝냅니다.';
    if (sel.full) {
      note = '한 번에 ' + String(BULK_PICK_MAX) + '개까지 고를 수 있습니다 — 더 고르려면 먼저 몇 개를 푸세요.';
    } else if (running && n > 0) {
      note = '앞 작업이 끝나면 고른 페이지로 할 수 있습니다.';
    }
    setText(bulkNote, note);
    for (const b of [bulkMoveBtn, bulkFavBtn, bulkTrashBtn]) { if (b) { b.disabled = n === 0 || running; } }
    show(bulkBar, sel.on);
    show(bulkNote, sel.on);
  }

  function selMode(on) {
    sel.on = on;
    sel.picked.clear();
    sel.full = false;
    if (treeBox.hidden && on) {
      // 찾기 결과(카드)를 보고 있었다면 트리로 돌아간다 — 모드는 트리에서만 쓴다
      if (search) { search.value = ''; }
      load('').catch(() => setText(msg, GENERIC_ERROR));
    } else {
      drawTree();
    }
    syncSel();
  }

  function selItems() {
    return bulkTreeItems(new Set(sel.picked.keys()), treePages);
  }

  function afterBulk() {
    // 고른 것은 시작할 때 풀렸다(startBulk) — 도는 동안 새로 고른 것은 남기고, 트리에서 사라진 줄은 loadTree 가 뺀다
    loadSpaces();   // 사이드바 트리도 창구의 새 답으로(옮기거나 지운 페이지가 그 자리에 남지 않게)
    return load(search ? search.value : '').catch(() => setText(msg, GENERIC_ERROR));
  }

  /** 작업을 시작한다 — 고른 것은 작업으로 넘어가며 풀린다(t-box 파일 화면과 같다 · 도는 동안 새로 고르면 다음 작업 몫) */
  function startBulk(next) {
    if (!runner.start(next)) { return; }
    sel.picked.clear();
    sel.full = false;
    syncSel();
  }

  /* 옮기기 — 고른 페이지와 그 하위는 새 부모 후보에서 뺀다(서버가 cycle 로 다시 막는다). 부모가 늘 하위보다 먼저 간다. */
  const bulkMoveDialog = document.getElementById('bulk-move-dialog');
  const bulkMoveTree = document.getElementById('bulk-move-tree');
  const bulkMoveMsg = document.getElementById('bulk-move-msg');
  const bulkMoveMeta = document.getElementById('bulk-move-meta');
  const bulkMoveConfirm = document.getElementById('bulk-move-confirm');
  let bulkMovePick = null;
  let bulkMovePicked = false;

  function bulkMoveButton(label, id) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'move-tree__pick';
    button.setAttribute('role', 'option');
    button.setAttribute('aria-selected', 'false');
    button.textContent = label;
    button.addEventListener('click', () => {
      bulkMovePick = id;
      bulkMovePicked = true;
      for (const other of bulkMoveTree.querySelectorAll('.move-tree__pick')) {
        const on = other === button;
        other.className = 'move-tree__pick' + (on ? ' is-selected' : '');
        other.setAttribute('aria-selected', on ? 'true' : 'false');
      }
    });
    return button;
  }

  function bulkMoveLevel(byParent, key, depth, skip) {
    const box = document.createDocumentFragment();
    if (depth > 12) { return box; }
    for (const node of byParent.get(key) || []) {
      if (skip.has(node.id)) { continue; }   // 고른 페이지와 그 아래는 새 부모가 될 수 없다
      const wrap = document.createElement('div');
      wrap.className = 'move-tree__node';
      wrap.appendChild(bulkMoveButton((node.icon ? node.icon + ' ' : '') + node.title, node.id));
      const kids = bulkMoveLevel(byParent, node.id, depth + 1, skip);
      if (kids.childNodes.length > 0) {
        const kidBox = document.createElement('div');
        kidBox.className = 'move-tree__kids';
        kidBox.appendChild(kids);
        wrap.appendChild(kidBox);
      }
      box.appendChild(wrap);
    }
    return box;
  }

  function openBulkMove() {
    if (!bulkMoveDialog || sel.picked.size === 0) { return; }
    bulkMovePick = null;
    bulkMovePicked = false;
    setText(bulkMoveMsg, '');
    setText(bulkMoveMeta, '고른 페이지 ' + String(sel.picked.size) + '개');
    clear(bulkMoveTree);
    const byParent = new Map();
    for (const page of treePages) {
      const k = typeof page.parent_id === 'string' ? page.parent_id : '';
      if (!byParent.has(k)) { byParent.set(k, []); }
      byParent.get(k).push(page);
    }
    bulkMoveTree.appendChild(bulkMoveButton('맨 위 (상위 없음)', null));
    bulkMoveTree.appendChild(bulkMoveLevel(byParent, '', 0, new Set(sel.picked.keys())));
    if (!bulkMoveDialog.open) { bulkMoveDialog.showModal(); }
  }

  function runBulkMove() {
    if (!bulkMovePicked) { setText(bulkMoveMsg, '새 상위 페이지를 고르세요'); return; }
    const target = bulkMovePick;
    const targetNode = target === null ? null : treePages.find((p) => p.id === target);
    const where = targetNode ? (targetNode.icon ? targetNode.icon + ' ' : '') + targetNode.title : '맨 위';
    bulkMoveDialog.close();
    const items = selItems();
    startBulk({
      title: '「' + where + '」 아래로 옮기기',
      items: items,
      okText: '옮김',
      withParentText: '부모와 함께 옮겨짐',
      parentFailText: '옮기지 않음 — 부모를 옮기지 못했습니다',
      send: (item) => api('PATCH', '/api/pages/' + item.id + '/move', { parent_id: target }),
      done: () => { afterBulk(); }
    });
  }

  function runBulkFavorite() {
    const items = selItems().map((item) => ({ id: item.id, name: item.name, parent: null }));   // 즐겨찾기는 페이지마다(하위도 제 줄)
    if (items.length === 0) { return; }
    startBulk({
      title: '즐겨찾기에 넣기',
      items: items,
      okText: (data) => (data && data.listed === false ? '넣음 — 목록에는 나오지 않습니다' : '넣음'),
      send: (item) => api('PUT', '/api/pages/' + item.id + '/favorite', {}),
      done: () => { loadFavoritesNav(); afterBulk(); }
    });
  }

  const bulkDeleteDialog = document.getElementById('bulk-delete-dialog');
  function openBulkDelete() {
    if (!bulkDeleteDialog || sel.picked.size === 0) { return; }
    setText(document.getElementById('bulk-delete-lead'),
      '고른 페이지 ' + String(sel.picked.size) + '개와 그 하위 페이지가 목록에서 사라집니다. 하나씩 차례로 지웁니다.');
    if (!bulkDeleteDialog.open) { bulkDeleteDialog.showModal(); }
  }

  function runBulkDelete() {
    bulkDeleteDialog.close();
    const items = selItems();
    startBulk({
      title: '휴지통으로 옮기기',
      items: items,
      okText: '휴지통으로 옮김',
      withParentText: '부모와 함께 지워짐',
      parentFailText: '지우지 않음 — 부모를 지우지 못했습니다',
      send: (item) => api('DELETE', '/api/pages/' + item.id, {}),
      // M-7 — 커뮤니티는 바로: 10초 동안 「되돌리기」 = 지운 페이지마다 되살리기(하위는 함께 돌아온다)
      undo: (done) => ({
        title: '되돌리기 — 휴지통에서 되살리기',
        items: done.map((d) => ({ id: d.id, name: d.name, parent: null })),
        okText: (data) => (data && data.moved_to_root === true ? '되살림 — 맨 위로 올렸습니다' : '되살림'),
        send: (item) => api('POST', '/api/pages/' + item.id + '/restore', {}),
        done: () => { afterBulk(); }
      }),
      done: () => { afterBulk(); loadFavoritesNav(); }
    });
  }

  if (selButton) {
    selButton.addEventListener('click', () => { selMode(!sel.on); });
  }
  if (bulkDoneBtn) {
    bulkDoneBtn.addEventListener('click', () => {
      selMode(false);
      if (selButton) { selButton.focus(); }
    });
  }
  if (bulkMoveBtn) { bulkMoveBtn.addEventListener('click', openBulkMove); }
  if (bulkFavBtn) { bulkFavBtn.addEventListener('click', runBulkFavorite); }
  if (bulkTrashBtn) { bulkTrashBtn.addEventListener('click', openBulkDelete); }
  if (bulkMoveDialog) {
    for (const close of bulkMoveDialog.querySelectorAll('[data-bulk-move-close]')) {
      close.addEventListener('click', () => bulkMoveDialog.close());
    }
    if (bulkMoveConfirm) { bulkMoveConfirm.addEventListener('click', runBulkMove); }
  }
  if (bulkDeleteDialog) {
    for (const close of bulkDeleteDialog.querySelectorAll('[data-bulk-delete-close]')) {
      close.addEventListener('click', () => bulkDeleteDialog.close());
    }
    const confirmButton = document.getElementById('bulk-delete-confirm');
    if (confirmButton) { confirmButton.addEventListener('click', runBulkDelete); }
  }
  document.addEventListener('keydown', (event) => {
    // Esc — 「선택」 모드를 끝낸다. 창·메뉴가 열려 있으면 그쪽이 먼저 닫힌다(DESIGN 2.7 · 2.15)
    if (event.key !== 'Escape' || !sel.on) { return; }
    if (document.querySelector('dialog[open]') || document.querySelector('details.menu[open]')) { return; }
    selMode(false);
    if (selButton) { selButton.focus(); }
  });

  function load(q) {
    // 「공유받은 페이지」는 공간·찾기와 상관없이 늘 같은 자리에 있다 (5-A).
    loadShared();
    // 찾기를 껐으면 트리로 돌아간다 — 트리 안에서 찾는 것은 뜻이 없다.
    if (!q || q === '') { return loadTree(); }
    return api('GET', listPath('q=' + encodeURIComponent(q))).then((result) => {
      if (result.status === 401) { toLogin(); return; }
      if (result.status !== 200) { setText(msg, errorMessage(result.data)); return; }
      setText(msg, '');
      if (result.data.space) { spaceKind = result.data.space.kind; drawSpaceHead(result.data.space); }
      clear(list);
      const pages = Array.isArray(result.data.pages) ? result.data.pages : [];
      for (const page of pages) { list.appendChild(card(page)); }
      show(treeBox, false);
      show(list, true);
      show(empty, pages.length === 0);
      setText(count, pages.length === 0 ? '' : '페이지 ' + String(result.data.total) + '개');
    });
  }

  /* 공간 고르개 (4-B) — 항목은 창구가 준 것만이다. 고르면 주소를 바꿔 **화면을 다시
     연다**: 고른 공간이 주소에 남아야 새로 고침·뒤로 가기·즐겨찾기가 같은 곳을 연다. */
  function fillSpacePicker(spaces) {
    if (!spaceSelect) { return; }
    clear(spaceSelect);
    for (const space of spaces) {
      const option = document.createElement('option');
      option.value = String(space.id);
      option.setAttribute('data-kind', space.kind);
      option.textContent = space.name;
      if (spaceId === '' ? space.kind === 'personal' : spaceId === String(space.id)) {
        option.selected = true;
      }
      spaceSelect.appendChild(option);
    }
    // 고를 것이 하나뿐이면 고르개를 보이지 않는다(팀이 없는 계정).
    show(spaceSelect, spaces.length > 1);
    show(spaceLabel, spaces.length > 1);
  }

  if (spaceSelect) {
    spaceSelect.addEventListener('change', () => {
      const picked = spaceSelect.value;
      const option = spaceSelect.options[spaceSelect.selectedIndex];
      const kind = option && option.getAttribute('data-kind') === 'personal' ? 'personal' : 'team';
      window.location.assign(spaceHref(picked, kind));
    });
  }

  // 개인 공간은 첫 방문에 서버가 만든다(「시작하기」 페이지와 함께).
  // 팀 공간은 `GET /api/spaces` 가 만든다 — 두 창구 모두 두 번 불러도 결과가 같다.
  api('GET', '/api/me/space')
    .then((result) => {
      if (result.status === 401) { toLogin(); return null; }
      if (result.status !== 200) { setText(msg, errorMessage(result.data)); return null; }
      return loadSpaces();
    })
    .then((spaces) => {
      if (spaces === null) { return null; }
      const list2 = Array.isArray(spaces) ? spaces : [];
      fillSpacePicker(list2);
      for (const space of list2) {
        if (spaceId === '' ? space.kind === 'personal' : spaceId === String(space.id)) {
          drawSpaceHead(space);
        }
      }
      return load('');
    })
    .catch(() => setText(msg, GENERIC_ERROR));

  if (search) {
    search.addEventListener('input', () => {
      // 라운드 B — 찾기(카드 목록)를 시작하면 「선택」 모드가 끝난다(모드는 트리에서만 — 고른 것이 화면에서 사라진 채 남지 않게)
      if (sel.on && search.value !== '') {
        sel.on = false;
        sel.picked.clear();
        sel.full = false;
        syncSel();
      }
      window.clearTimeout(timer);
      timer = window.setTimeout(() => { load(search.value).catch(() => setText(msg, GENERIC_ERROR)); }, 300);
    });
  }

  // 상태 거르개 (4-D) — 고르면 창구를 다시 부른다. 화면이 목록에서 줄을 빼지 않는다.
  for (const filter of [statusSelect,verifySelect,dueSelect].filter(Boolean)) {
    filter.addEventListener('change', () => {
      sel.picked.clear();   // 라운드 B — 목록이 바뀌면 고른 것이 풀린다(M-4)
      sel.full = false;
      load(search ? search.value : '').catch(() => setText(msg, GENERIC_ERROR));
    });
  }

  // 휴지통 (4-C) — 되살린 뒤에는 트리를 다시 받는다(화면이 카드를 지어내지 않는다).
  bindTrash(() => { load(search ? search.value : '').catch(() => setText(msg, GENERIC_ERROR)); });
  // 7-B — 「템플릿에서」 창. 만든 페이지는 지금 보고 있는 공간에 생긴다(「새 페이지」와 같은 규칙 — 창구가 다시 판단한다)
  bindTemplateGallery({
    api: api, setText: setText, show: show, clear: clear, errorMessage: errorMessage,
    genericError: GENERIC_ERROR, toLogin: toLogin, base: COMMUNITY_BASE, currentSpaceId: currentSpaceId, canWrite: meCanWrite
  });
  // 7-C — 「임베드 허용 목록」 창(단추는 창구가 준 `can_manage` 일 때만)
  bindEmbedAdmin({
    api: api, setText: setText, show: show, clear: clear, errorMessage: errorMessage,
    genericError: GENERIC_ERROR, toLogin: toLogin
  });
  // 7-D1 — 「많이 읽힌 문서」 창(단추는 관리자에게만)
  bindPopular({
    api: api, setText: setText, show: show, clear: clear, errorMessage: errorMessage,
    genericError: GENERIC_ERROR, toLogin: toLogin, base: COMMUNITY_BASE
  });
  // 7-D2 — 「팔레트 관리」 창(메뉴 항목은 관리자에게만 — 판단은 창구가 준 can_manage, 막는 것은 서버)
  bindPalettes({
    api: api, setText: setText, show: show, clear: clear, errorMessage: errorMessage,
    genericError: GENERIC_ERROR, toLogin: toLogin
  });
  // B1-2 — 「저장 안 된 글 n개」(B1-1 이 없어도 그 페이지로 찾아가게)
  drawHomeDrafts(msg);
}

/**
 * Q:F-4 B1-2 설계 5 — 첫 화면의 「저장 안 된 글 n개」 줄. **이 사람 몫만**(`drafts.entries` 가 내 접두만 훑는다).
 *
 * 항목이 있을 때만 열쇠를 받고(한 번), 풀리는 것만 센다 — 풀리지 않는 것(열쇠가 바뀜·손상)은 `read` 가 조용히 지운다.
 * 줄마다 그 페이지 링크이고 글자는 푼 제목(댓글은 앞 40자)이다 — 전부 `textContent`. 구성 요소는 DESIGN 2.6 `.notice`
 * (주황 — 「알고 하라」) 그대로다(새 클래스 0). 열쇠를 못 받으면(503 등) 줄을 그리지 않는다 — 셀 수 없는 것을 세지 않는다.
 *
 * @param {Element|null} anchor  이 요소 **뒤**에 줄을 넣는다(`#msg`)
 */
function drawHomeDrafts(anchor) {
  if (!drafts || !drafts.available() || !anchor || !anchor.parentNode) { return; }
  const list = drafts.entries();
  if (list.length === 0) { return; }
  drafts.ready().then((ok) => {
    if (!ok) { return null; }
    return Promise.all(list.map((e) => drafts.read(e.key).then((got) => (got ? { entry: e, data: got.data } : null))));
  }).then((items) => {
    const found = (items || []).filter((x) => x !== null)
      .sort((a, b) => b.entry.savedAt - a.entry.savedAt);
    if (found.length === 0) { return; }
    const box = document.createElement('section');
    box.className = 'notice';
    box.setAttribute('role', 'status');
    box.setAttribute('data-drafts-home', '');
    const title = document.createElement('p');
    title.className = 'notice__title';
    title.textContent = '저장 안 된 글 ' + String(found.length) + '개 — 이 브라우저에 암호화해 남아 있습니다. 페이지를 열어 불러오거나 버리세요.';
    box.appendChild(title);
    const ul = document.createElement('ul');
    ul.className = 'notice__list';
    for (const x of found) {
      const li = document.createElement('li');
      const a = document.createElement('a');
      a.setAttribute('href', COMMUNITY_BASE + '/p/' + x.entry.pageId);
      if (x.entry.kind === 'page') {
        const t = typeof x.data.title === 'string' && x.data.title.trim() !== '' ? x.data.title : '제목 없는 페이지';
        a.textContent = t;
      } else {
        const b = typeof x.data.body === 'string' ? x.data.body.replace(/\s+/g, ' ').trim() : '';
        const cut = Array.from(b).slice(0, 40).join('');
        a.textContent = (x.entry.target === 'new' ? '댓글: 「' : '답글: 「') + cut + (Array.from(b).length > 40 ? '…」' : '」');
      }
      li.appendChild(a);
      const when = document.createElement('span');
      when.className = 'muted';
      when.textContent = ' · ' + localWhen(x.entry.savedAt);
      li.appendChild(when);
      ul.appendChild(li);
    }
    box.appendChild(ul);
    anchor.parentNode.insertBefore(box, anchor.nextSibling);
  }).catch(() => { /* 임시저장은 편의 — 첫 화면을 막지 않는다 */ });
}

/* ─── 휴지통 (4-C, C:F-29) ─────────────────────────────────────
 *
 * 「내 공간」 화면의 단추 하나가 창을 열고, 목록은 창구 `GET /api/me/trash` 가 주는 것만
 * 그린다 — 화면에는 「내가 지운 것인가」·「되살릴 수 있나」를 따지는 코드가 한 줄도 없다
 * (DESIGN 원칙 1, PRD S-01). 되살리기는 서버가 다시 판단한다.
 *
 * 한 줄은 **한 번의 삭제**다. 함께 내려간 하위는 「하위 n」으로만 세고, 되살리면 함께
 * 돌아온다. 부모가 그 사이 다시 지워졌다면 창구가 뿌리로 올리고 그 사실을 응답에 적는데,
 * 그때는 **그렇게 됐다고 말한다** — 조용히 옮기지 않는다.
 *
 * @param onRestored 되살린 뒤 목록을 다시 그리는 일 (initHome 이 넘긴다)
 */
const TRASH_MOVE_REASONS = {
  parent_deleted: '되살렸습니다. 상위 페이지가 아직 휴지통에 있어 맨 위로 올렸습니다',
  parent_narrower: '되살렸습니다. 상위 페이지가 그 사이 더 좁게 닫혀 맨 위로 올렸습니다'
};

function bindTrash(onRestored) {
  const button = document.getElementById('community-trash');
  const dialog = document.getElementById('trash-dialog');
  const tbody = document.getElementById('trash-body');
  const empty = document.getElementById('trash-empty');
  const meta = document.getElementById('trash-meta');
  const msg = document.getElementById('trash-msg');
  if (!button || !dialog || !tbody) { return; }

  /* 라운드 B — 여러 줄 되살리기(지시서 1.3 「휴지통 화면에서 되살리기」). 줄 앞 체크 · 머리 체크(모두) · 100개까지 → 「되살리기」가
     고른 줄마다 `POST /api/pages/{id}/restore` 를 한 번씩(한 줄 되살리기와 같은 창구 · 같은 판정). 진행은 창 안의 표. */
  const picked = new Map();
  let shown = [];   // 지금 그린 줄 [{page, check}]
  const checkAll = document.getElementById('trash-check-all');
  const bulkBarEl = document.getElementById('trash-bulk-bar');
  const bulkCountEl = document.getElementById('trash-bulk-count');
  const bulkRestore = document.getElementById('trash-bulk-restore');
  const trashRunner = createBulkRunner({
    panel: document.getElementById('trash-bulk-panel'),
    title: document.getElementById('trash-bulk-title'),
    body: document.getElementById('trash-bulk-body'),
    stop: document.getElementById('trash-bulk-stop'),
    stopText: document.getElementById('trash-bulk-stop-text'),
    login: document.getElementById('trash-bulk-login'),
    resume: document.getElementById('trash-bulk-resume'),
    summary: document.getElementById('trash-bulk-summary')
  }, {
    onChange: () => syncPicked(),
    // 재인증 창은 모달이다 — 모달 안에 모달을 열지 않게 휴지통 창을 잠시 닫았다가 통과하면 다시 연다(popular.js 와 같은 방식)
    beforeReauth: () => { if (dialog.open) { dialog.close(); } },
    afterReauth: () => { if (!dialog.open) { dialog.showModal(); } }
  });
  let full = false;

  function syncPicked() {
    let onShown = 0;
    for (const s of shown) {
      const on = picked.has(s.page.id);
      s.check.checked = on;
      if (on) { onShown += 1; }
    }
    if (checkAll) {
      checkAll.checked = shown.length > 0 && onShown === shown.length;
      checkAll.indeterminate = onShown > 0 && onShown < shown.length;
      checkAll.disabled = shown.length === 0 || trashRunner.busy();
    }
    const n = picked.size;
    setText(bulkCountEl, String(n) + '개 선택됨');
    // 100개에 닿으면 창의 알림 줄에(막대는 좁은 창에서도 한 줄로 둔다)
    if (full) { setText(msg, '한 번에 ' + String(BULK_PICK_MAX) + '개까지 고를 수 있습니다 — 더 고르려면 먼저 몇 개를 푸세요.'); }
    if (bulkRestore) { bulkRestore.disabled = n === 0 || trashRunner.busy(); }
    show(bulkBarEl, n > 0);
  }

  function pickCheck(page) {
    const check = document.createElement('input');
    check.type = 'checkbox';
    check.setAttribute('data-bulk', '');
    check.setAttribute('data-bulk-trash', page.id);
    check.setAttribute('aria-label', '「' + (page.icon ? page.icon + ' ' : '') + page.title + '」 고르기');
    check.addEventListener('click', () => {
      full = false;
      if (check.checked) {
        if (picked.size >= BULK_PICK_MAX) { check.checked = false; full = true; } else { picked.set(page.id, page); }
      } else {
        picked.delete(page.id);
      }
      syncPicked();
    });
    shown.push({ page: page, check: check });
    return check;
  }

  if (checkAll) {
    checkAll.addEventListener('click', () => {
      const all = shown.length > 0 && shown.every((s) => picked.has(s.page.id));
      full = false;
      for (const s of shown) {
        if (all) { picked.delete(s.page.id); } else if (!picked.has(s.page.id)) {
          if (picked.size >= BULK_PICK_MAX) { full = true; break; }
          picked.set(s.page.id, s.page);
        }
      }
      syncPicked();
    });
  }
  if (bulkRestore) {
    bulkRestore.addEventListener('click', () => {
      const items = Array.from(picked.values()).map((p) => ({ id: p.id, name: (p.icon ? p.icon + ' ' : '') + p.title, parent: null }));
      if (items.length === 0) { return; }
      setText(msg, '');
      const started = trashRunner.start({
        title: '되살리기',
        items: items,
        okText: (data) => (data && data.moved_to_root === true
          ? (TRASH_MOVE_REASONS[data.moved_reason] || '되살렸습니다. 원래 자리로 돌아갈 수 없어 맨 위로 올렸습니다')
          : '되살림'),
        send: (item) => api('POST', '/api/pages/' + item.id + '/restore', {}),
        done: () => {
          // 고른 것은 시작할 때 풀렸다 — 도는 동안 새로 고른 것은 남기고, 목록에서 사라진 줄은 load 가 뺀다
          loadSpaces();   // 사이드바 트리도 새 답으로
          load().then(() => { if (typeof onRestored === 'function') { onRestored(); } }).catch(() => setText(msg, GENERIC_ERROR));
        }
      });
      // 작업을 시작하면 고른 것은 작업으로 넘어가며 풀린다(t-box 파일 화면 · 내 공간 트리와 같다)
      if (started) { picked.clear(); full = false; syncPicked(); }
    });
  }

  function row(page) {
    const tr = document.createElement('tr');

    const title = document.createElement('td');
    title.appendChild(pickCheck(page));   // 라운드 B — 줄 앞 체크(열을 늘리지 않는다)
    title.appendChild(document.createTextNode((page.icon ? page.icon + ' ' : '') + page.title));
    tr.appendChild(title);

    const space = document.createElement('td');
    space.textContent = page.space && page.space.name ? page.space.name : '—';
    tr.appendChild(space);

    const kids = document.createElement('td');
    kids.textContent = page.descendants > 0 ? String(page.descendants) + '개' : '—';
    tr.appendChild(kids);

    const when = document.createElement('td');
    setWhenParts(when, page.deleted_at);   // 디버깅 묶음 U M2
    when.setAttribute('title', String(page.deleted_at));
    tr.appendChild(when);

    const actions = document.createElement('td');
    actions.className = 'actions';
    const back = document.createElement('button');
    back.type = 'button';
    back.className = 'btn btn--small btn--quiet';
    back.textContent = '되살리기';
    back.addEventListener('click', () => {
      back.disabled = true;
      api('POST', '/api/pages/' + page.id + '/restore', {})
        .then((result) => {
          if (result.status === 401) { toLogin(); return; }
          if (result.status !== 200) {
            setText(msg, errorMessage(result.data));
            back.disabled = false;
            return;
          }
          /* 왜 맨 위로 올렸는지는 **창구가 준 까닭**으로 가른다. 화면이 「상위가 지워졌겠지」
             하고 지어내지 않는다 (DESIGN 원칙 1). 모르는 까닭이면 까닭 없이 알린다. */
          setText(msg, result.data.moved_to_root !== true
            ? '되살렸습니다'
            : (TRASH_MOVE_REASONS[result.data.moved_reason] || '되살렸습니다. 원래 자리로 돌아갈 수 없어 맨 위로 올렸습니다'));
          return load().then(() => { if (typeof onRestored === 'function') { onRestored(); } });
        })
        .catch(() => { setText(msg, GENERIC_ERROR); back.disabled = false; });
    });
    actions.appendChild(back);
    tr.appendChild(actions);
    return tr;
  }

  function load() {
    return api('GET', '/api/me/trash').then((result) => {
      if (result.status === 401) { toLogin(); return; }
      if (result.status !== 200) { setText(msg, errorMessage(result.data)); return; }
      const pages = Array.isArray(result.data.pages) ? result.data.pages : [];
      clear(tbody);
      shown = [];
      for (const page of pages) { tbody.appendChild(row(page)); }
      // 목록에 없는 줄(되살렸거나 30일 정리된 것)은 고른 것에서 뺀다
      const here = new Set(pages.map((p) => p.id));
      for (const id of Array.from(picked.keys())) { if (!here.has(id)) { picked.delete(id); } }
      syncPicked();
      show(empty, pages.length === 0);
      setText(meta, pages.length === 0
        ? ''
        : String(pages.length) + '건 · ' + String(result.data.keep_days) + '일 뒤 지워집니다');
    });
  }

  function openTrash() {
    if (typeof dialog.showModal !== 'function') { return; }
    setText(msg, '');
    // 라운드 B — 창을 새로 열 때마다 고른 것·끝난 진행 표를 치운다(도는 중인 작업은 그대로 둔다)
    trashRunner.reset();
    if (!trashRunner.busy()) { picked.clear(); full = false; }
    if (!dialog.open) { dialog.showModal(); }
    load().catch(() => setText(msg, GENERIC_ERROR));
  }
  button.addEventListener('click', openTrash);
  /* 디버깅 묶음 U3 — 사이드바 「내 휴지통」(모든 커뮤니티 화면 · 주소 `/community/?trash=1`). 이 화면(첫 화면)에서는 옮겨 가지 않고
     같은 창을 연다(좁은 폭이면 열린 서랍을 먼저 닫는다). 다른 화면에서 온 꼬리표는 창을 연 뒤 주소에서 뗀다 — 새로 고침이 창을 또 열지 않게. */
  const navTrash = document.getElementById('nav-community-trash');
  if (navTrash) {
    navTrash.addEventListener('click', (event) => {
      if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) { return; }
      event.preventDefault();
      const drawerToggle = document.getElementById('nav-toggle');
      if (drawerToggle && drawerToggle.getAttribute('aria-expanded') === 'true') { drawerToggle.click(); }
      openTrash();
    });
  }
  let wantTrash = false;
  try { wantTrash = new URLSearchParams(window.location.search).get('trash') === '1'; } catch (e) { wantTrash = false; }
  if (wantTrash) {
    try {
      const u = new URL(window.location.href);
      u.searchParams.delete('trash');
      window.history.replaceState(window.history.state, '', u.pathname + u.search + u.hash);
    } catch (e) { /* 주소를 못 고쳐도 창은 연다 */ }
    openTrash();
  }
  for (const close of dialog.querySelectorAll('[data-trash-close]')) {
    close.addEventListener('click', () => dialog.close());
  }
}

/** 시각을 짧게 (t-box 의 `shortWhen` 과 같은 뜻 — 이 파일은 모듈이라 따로 둔다) */
function shortWhen(value) {
  if (typeof value !== 'string' || value === '') { return ''; }
  return value.slice(0, 16).replace('T', ' ');
}

/**
 * 표 칸의 시각 — 날짜와 시각을 **두 조각**(`.nowrap` 둘 + 빈칸)으로 (디버깅 묶음 U M2 · 2026-09-26, t-box `addWhenCell` 과 같은 방식).
 * 좁은 칸에서 날짜의 하이픈이 줄바꿈 자리가 되어 「2026-」/「09-26」/「11:54」 세 줄로 갈라졌다 — 이제 많아야 두 줄이다.
 * 글자(textContent)는 전과 같다. 요소로 만들고 글자는 textContent 로만 넣는다.
 */
function setWhenParts(el, value) {
  const text = shortWhen(value);
  const m = text.match(/^(\S+) (\S+)$/);   // String#match — 보안 린트의 코드 실행 함수 이름 규칙(S-09)에 걸리지 않게
  el.textContent = '';
  if (!m) { el.textContent = text; return; }
  const day = document.createElement('span');
  day.className = 'nowrap';
  day.textContent = m[1];
  const clock = document.createElement('span');
  clock.className = 'nowrap';
  clock.textContent = m[2];
  el.appendChild(day);
  el.appendChild(document.createTextNode(' '));
  el.appendChild(clock);
}

/* ─── 새 페이지 만들기 (두 화면 모두의 단추) ─────────────────── */

function bindNewPage() {
  const buttons = [document.getElementById('community-new'), document.getElementById('nav-community-new')];
  const msg = document.getElementById('msg');
  for (const button of buttons) {
    if (!button) { continue; }
    button.addEventListener('click', () => {
      button.disabled = true;
      /* 4-B — 지금 보고 있는 공간에 만든다. 값은 주소의 `?space=` 이고 창구가 다시
         판단한다(팀 공간이면 그 팀원만). 개인 공간이면 꼬리표가 없으므로 칸을 보내지
         않고, 창구의 기본값(내 개인 공간)이 그대로 쓰인다. */
      const here = currentSpaceId();
      api('POST', '/api/pages', here === '' ? {} : { space_id: here })
        .then((result) => {
          if (result.status === 401) { toLogin(); return; }
          if (result.status === 201 && result.data.page && typeof result.data.page.id === 'string') {
            /* T:F-72 2장 ① (취합 결정) — 「새 페이지」는 열자마자 편집 모드: 게시판 「새 글」과 같은 `?edit=1`
               (페이지 화면이 창구의 can_edit 을 보고 편집을 켠 뒤 주소에서 꼬리표를 지운다). */
            window.location.assign(COMMUNITY_BASE + '/p/' + result.data.page.id + '?edit=1');
            return;
          }
          setText(msg, errorMessage(result.data));
          button.disabled = false;
        })
        .catch(() => { setText(msg, GENERIC_ERROR); button.disabled = false; });
    });
  }
}

/* ─── 게시판 (5-C) ─────────────────────────────────────────────
 *
 * 사이드바 「게시판」 묶음 · 게시판 화면(community-board) · 페이지 화면의 게시 창이 쓴다.
 * ★ 무엇이 보이고 무엇을 할 수 있나는 창구가 준 값(`boards`·`can_write`·`can_moderate`·`can_manage`)뿐이다.
 *   이 파일에는 등급·역할을 따지는 코드가 없다 (DESIGN 원칙 1, PRD S-01).
 * ★ 등급 배지의 색은 t-box 파일 등급 배지 그대로다(DESIGN 2.2 — 새 색값 0).
 */
const BOARD_LEVEL_LABELS = {
  public: { text: '공개', badge: 'badge--public' },
  internal: { text: '사내', badge: 'badge--internal' },
  team: { text: '팀', badge: 'badge--team' },
  'admin-only': { text: '관리자', badge: 'badge--admin-only' }
};
const BOARD_KIND_LABELS = {
  notice: { text: '공지', icon: 'log' },
  discussion: { text: '토론', icon: 'users' },
  qa: { text: '질문답변', icon: 'help' }
};
/** 게시판 창구가 돌려주는 거부 사유 → 사람 문장. 없으면 서버가 준 문구를 그대로 쓴다. */
const BOARD_REASONS = {
  board_inactive: '보관 중인 게시판입니다. 읽기만 됩니다',
  already_published: '이미 게시 중입니다. 다른 게시판에 걸려면 먼저 게시를 해제하세요',
  not_published: '게시 중인 페이지가 아닙니다',
  published: '게시 중에는 공개 범위를 바꿀 수 없습니다. 먼저 게시를 해제하세요',
  not_qa: '질문과 답변 게시판의 글만 「해결됨」을 켤 수 있습니다'
};

/** 게시판 한 줄의 배지(등급). 모르는 값이면 만들지 않는다(지어내지 않는다). */
function boardLevelBadge(level) {
  const entry = BOARD_LEVEL_LABELS[level];
  if (!entry) { return null; }
  const span = document.createElement('span');
  span.className = 'badge ' + entry.badge;
  span.setAttribute('title', level);
  span.textContent = entry.text;
  return span;
}

/** 이 화면이 보고 있는 게시판 번호 (게시판 화면에서만). 숫자가 아니면 빈 글자. */
function currentBoardId() {
  const meta = document.getElementById('board-meta');
  const raw = meta ? (meta.getAttribute('data-board-id') || '') : '';
  return /^[1-9][0-9]{0,8}$/.test(raw) ? raw : '';
}

/**
 * 사이드바 「게시판」 묶음 — 창구 `GET /api/boards` 가 준 **볼 수 있는 게시판만**.
 * 돌려주는 값은 창구의 답 전체(게시 창·관리 창이 다시 쓴다). 실패하면 묶음은 숨은 채다.
 */
let boardsCache = null;
function loadBoards() {
  return api('GET', '/api/boards').then((result) => {
    if (result.status !== 200 || !Array.isArray(result.data.boards)) { return null; }
    boardsCache = result.data;
    const box = document.getElementById('nav-community-boards');
    const title = document.getElementById('nav-community-board-title');
    if (box) {
      clear(box);
      const here = currentBoardId();
      for (const board of result.data.boards) {
        const a = document.createElement('a');
        a.className = 'nav__item' + (String(board.id) === here ? ' is-active' : '');
        a.setAttribute('href', COMMUNITY_BASE + '/b/' + String(board.id));
        if (String(board.id) === here) { a.setAttribute('aria-current', 'page'); }
        const kind = BOARD_KIND_LABELS[board.board_kind];
        a.appendChild(makeIcon(kind ? kind.icon : 'files'));
        const name = document.createElement('span');
        name.textContent = board.name + (board.is_active ? '' : ' (보관)');
        a.appendChild(name);
        box.appendChild(a);
      }
      show(box, result.data.boards.length > 0);
      show(title, result.data.boards.length > 0);
    }
    return result.data;
  }).catch(() => null);
}

/** 쉼표로 나눈 태그 글자 → 목록(앞뒤 공백을 떼고 빈 것은 뺀다). 검사는 창구가 한다. */
function splitTags(raw) {
  return String(raw || '').split(/[,，]/).map((t) => t.trim()).filter((t) => t !== '');
}

/** 게시판 한 곳에서 쓰인 태그(자동완성) → `<datalist>` 를 채운다. 창구가 볼 수 있는 글의 태그만 준다. */
function fillTagOptions(listEl, boardId, q) {
  if (!listEl || !boardId) { return Promise.resolve([]); }
  const path = '/api/boards/' + String(boardId) + '/tags' + (q ? '?q=' + encodeURIComponent(q) : '');
  return api('GET', path).then((result) => {
    const tags = result.status === 200 && Array.isArray(result.data.tags) ? result.data.tags : [];
    clear(listEl);
    for (const t of tags) {
      const option = document.createElement('option');
      option.value = t.tag;
      listEl.appendChild(option);
    }
    return tags;
  }).catch(() => []);
}

/* ─── 게시판 화면 (community-board) ──────────────────────────── */

function initBoard() {
  const boardId = currentBoardId();
  if (boardId === '') { return; }
  const msg = document.getElementById('msg');
  const titleEl = document.getElementById('board-title');
  const subEl = document.getElementById('board-sub');
  const levelEl = document.getElementById('board-level');
  const kindEl = document.getElementById('board-kind');
  const inactiveEl = document.getElementById('board-inactive');
  const newButton = document.getElementById('board-new');
  const manageButton = document.getElementById('board-manage');
  const searchEl = document.getElementById('board-search');
  const tagEl = document.getElementById('board-tag');
  const tagOptions = document.getElementById('board-tag-options');
  const tagsBox = document.getElementById('board-tags');
  const sortEl = document.getElementById('board-sort');
  const countEl = document.getElementById('board-count');
  const bodyEl = document.getElementById('board-body');
  const emptyEl = document.getElementById('board-empty');
  const pageInfo = document.getElementById('board-page-info');
  const prevButton = document.getElementById('board-prev');
  const nextButton = document.getElementById('board-next');

  let pageNo = 1;
  let timer = null;
  let board = null;

  function listPath() {
    const params = new URLSearchParams();
    const q = searchEl ? searchEl.value.trim() : '';
    const tag = tagEl ? tagEl.value.trim() : '';
    if (q !== '') { params.set('q', q); }
    if (tag !== '') { params.set('tag', tag); }
    if (sortEl && sortEl.value !== 'new') { params.set('sort', sortEl.value); }
    if (pageNo > 1) { params.set('page', String(pageNo)); }
    const tail = params.toString();
    return '/api/boards/' + boardId + '/pages' + (tail === '' ? '' : '?' + tail);
  }

  function drawHead(b) {
    board = b;
    setText(titleEl, b.name);
    document.title = b.name + ' · 커뮤니티';
    setText(subEl, b.description);
    const level = BOARD_LEVEL_LABELS[b.access_level];
    if (levelEl && level) {
      levelEl.className = 'badge ' + level.badge;
      levelEl.setAttribute('title', b.access_level);
      setText(levelEl, level.text + (b.team_name ? ' · ' + b.team_name : ''));
      show(levelEl, true);
    }
    const kind = BOARD_KIND_LABELS[b.board_kind];
    if (kindEl && kind) {
      kindEl.setAttribute('title', b.board_kind);
      setText(kindEl, kind.text);
      show(kindEl, true);
    }
    show(inactiveEl, b.is_active !== true);
    show(newButton, b.can_write === true);
  }

  function row(item) {
    const tr = document.createElement('tr');
    const titleCell = document.createElement('td');
    const a = document.createElement('a');
    a.setAttribute('href', COMMUNITY_BASE + '/p/' + item.id);
    a.textContent = (item.icon ? item.icon + ' ' : '') + item.title;
    titleCell.appendChild(a);
    if (item.resolved === true && board && board.board_kind === 'qa') {
      const done = document.createElement('span');
      done.className = 'badge badge--ok';
      done.textContent = '해결됨';
      titleCell.appendChild(document.createTextNode(' '));
      titleCell.appendChild(done);
    }
    if (item.kind === 'published') {
      const pub = document.createElement('span');
      pub.className = 'badge';
      pub.setAttribute('title', '개인 공간의 페이지를 게시한 것입니다');
      pub.textContent = '게시됨';
      titleCell.appendChild(document.createTextNode(' '));
      titleCell.appendChild(pub);
    }
    tr.appendChild(titleCell);

    const who = document.createElement('td');
    who.textContent = item.writer ? personLabel(item.writer.display_name, item.writer.username) : '—';
    tr.appendChild(who);

    const when = document.createElement('td');
    setWhenParts(when, item.posted_at);   // 디버깅 묶음 U M2
    when.setAttribute('title', String(item.posted_at));
    tr.appendChild(when);

    const tags = document.createElement('td');
    const list = Array.isArray(item.tags) ? item.tags : [];
    if (list.length === 0) { tags.textContent = '—'; }
    for (const t of list) {
      const chip = document.createElement('button');
      chip.type = 'button';
      chip.className = 'btn btn--small btn--quiet';
      chip.textContent = '#' + t;
      chip.setAttribute('aria-label', '태그 ' + t + ' 로 거르기');
      chip.addEventListener('click', () => { if (tagEl) { tagEl.value = t; } pageNo = 1; load(); });
      if (tags.firstChild) { tags.appendChild(document.createTextNode(' ')); }
      tags.appendChild(chip);
    }
    tr.appendChild(tags);

    const views = document.createElement('td');
    views.textContent = String(item.views);
    tr.appendChild(views);
    return tr;
  }

  function drawPopularTags() {
    if (!tagsBox) { return; }
    fillTagOptions(tagOptions, boardId, '').then((tags) => {
      clear(tagsBox);
      for (const t of tags) {
        const chip = document.createElement('button');
        chip.type = 'button';
        chip.className = 'btn btn--small btn--quiet';
        chip.textContent = '#' + t.tag + ' ' + String(t.count);
        chip.setAttribute('aria-label', '태그 ' + t.tag + ' 로 거르기');
        chip.addEventListener('click', () => { if (tagEl) { tagEl.value = t.tag; } pageNo = 1; load(); });
        tagsBox.appendChild(chip);
      }
      show(tagsBox, tags.length > 0);
    });
  }

  function load() {
    return api('GET', listPath()).then((result) => {
      if (result.status === 401) { toLogin(); return; }
      if (result.status !== 200) {
        setText(msg, errorMessage(result.data));
        show(emptyEl, false);
        return false;
      }
      drawHead(result.data.board);
      clear(bodyEl);
      const pages = Array.isArray(result.data.pages) ? result.data.pages : [];
      for (const item of pages) { bodyEl.appendChild(row(item)); }
      const total = Number(result.data.total) || 0;
      const per = Number(result.data.per_page) || 20;
      const last = Math.max(1, Math.ceil(total / per));
      setText(countEl, '전체 ' + String(total) + '건');
      setText(pageInfo, String(pageNo) + ' / ' + String(last) + ' 페이지 · 전체 ' + String(total) + '건');
      if (prevButton) { prevButton.disabled = pageNo <= 1; }
      if (nextButton) { nextButton.disabled = pageNo >= last; }
      const filtered = (searchEl && searchEl.value.trim() !== '') || (tagEl && tagEl.value.trim() !== '');
      setText(emptyEl, filtered ? '조건에 맞는 글이 없습니다.' : '아직 글이 없습니다.');
      show(emptyEl, pages.length === 0);
      setText(msg, '');
      return true;
    }).catch(() => { setText(msg, GENERIC_ERROR); return false; });
  }

  function later() {
    if (timer !== null) { clearTimeout(timer); }
    timer = setTimeout(() => { timer = null; pageNo = 1; load(); }, 250);
  }
  if (searchEl) { searchEl.addEventListener('input', later); }
  if (tagEl) {
    tagEl.addEventListener('input', () => {
      fillTagOptions(tagOptions, boardId, tagEl.value.trim());
      later();
    });
  }
  if (sortEl) { sortEl.addEventListener('change', () => { pageNo = 1; load(); }); }
  if (prevButton) { prevButton.addEventListener('click', () => { if (pageNo > 1) { pageNo -= 1; load(); } }); }
  if (nextButton) { nextButton.addEventListener('click', () => { pageNo += 1; load(); }); }

  /* ── 「새 글」 창 — 제목·태그만 받고, 만든 뒤 그 글을 편집 모드로 연다 ── */
  const newDialog = document.getElementById('board-new-dialog');
  const newInput = document.getElementById('board-new-input');
  const newTags = document.getElementById('board-new-tags');
  const newMsg = document.getElementById('board-new-msg');
  const newSave = document.getElementById('board-new-save');
  if (newButton && newDialog && typeof newDialog.showModal === 'function') {
    newButton.addEventListener('click', () => {
      setText(newMsg, '');
      if (newInput) { newInput.value = ''; }
      if (newTags) { newTags.value = ''; }
      newDialog.showModal();
      if (newInput) { newInput.focus(); }
    });
    for (const close of newDialog.querySelectorAll('[data-board-new-close]')) {
      close.addEventListener('click', () => newDialog.close());
    }
    const create = () => {
      const title = newInput ? newInput.value.trim() : '';
      if (title === '') { setText(newMsg, '제목을 적어 주세요.'); return; }
      newSave.disabled = true;
      api('POST', '/api/boards/' + boardId + '/pages', { title: title, tags: splitTags(newTags ? newTags.value : '') })
        .then((result) => {
          newSave.disabled = false;
          if (result.status === 401) { toLogin(); return; }
          if (result.status === 201 && result.data.page && typeof result.data.page.id === 'string') {
            window.location.assign(COMMUNITY_BASE + '/p/' + result.data.page.id + '?edit=1');
            return;
          }
          const reason = result.data && typeof result.data.reason === 'string' ? result.data.reason : '';
          setText(newMsg, BOARD_REASONS[reason] || errorMessage(result.data));
        })
        .catch(() => { newSave.disabled = false; setText(newMsg, GENERIC_ERROR); });
    };
    if (newSave) { newSave.addEventListener('click', create); }
    if (newInput) {
      newInput.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !event.isComposing) { event.preventDefault(); create(); }
      });
    }
  }

  bindBoardManage(manageButton, () => { loadBoards(); load(); });
  // 태그 단추 줄은 목록을 **볼 수 있을 때만** 부른다(못 보는 게시판에 창구를 한 번 더 두드리지 않는다).
  load().then((ok) => { if (ok) { drawPopularTags(); } });
}

/**
 * 「게시판 관리」 창 (관리자) — 만들기 · 이름 · 설명 · 보관. 등급·팀·종류는 만들 때만 고른다.
 * 단추는 창구가 준 `can_manage` 가 참일 때만 보인다. 창구는 `role:Admin` + 재인증이 다시 막고,
 * 재인증이 필요하면 셸의 **같은 재인증 창**(`window.qarediHandleReauth`)을 띄운 뒤 다시 보낸다.
 */
function bindBoardManage(button, onChanged) {
  const dialog = document.getElementById('board-manage-dialog');
  if (!button || !dialog || typeof dialog.showModal !== 'function') { return; }
  const msg = document.getElementById('board-manage-msg');
  const list = document.getElementById('board-manage-list');
  const mode = document.getElementById('board-manage-mode');
  const nameEl = document.getElementById('board-manage-name');
  const descEl = document.getElementById('board-manage-desc');
  const levelEl = document.getElementById('board-manage-level');
  const teamEl = document.getElementById('board-manage-team');
  const kindEl = document.getElementById('board-manage-kind');
  const activeEl = document.getElementById('board-manage-active');
  const levelField = document.getElementById('board-manage-level-field');
  const teamField = document.getElementById('board-manage-team-field');
  const kindField = document.getElementById('board-manage-kind-field');
  const activeField = document.getElementById('board-manage-active-field');
  const save = document.getElementById('board-manage-save');
  const reset = document.getElementById('board-manage-reset');
  let editing = null;   // 고치는 중인 게시판(창구가 준 줄) — null 이면 만들기
  let teamsLoaded = false;

  function showButton(data) {
    show(button, !!data && data.can_manage === true);
  }
  if (boardsCache) { showButton(boardsCache); }
  loadBoards().then(showButton);

  function drawMode() {
    const isNew = editing === null;
    setText(mode, isNew ? '새 게시판 만들기' : '「' + editing.name + '」 고치기');
    setText(save, isNew ? '만들기' : '바꾸기');
    show(levelField, isNew);
    show(kindField, isNew);
    show(teamField, isNew && levelEl && levelEl.value === 'team');
    show(activeField, !isNew);
    show(reset, !isNew);
    if (nameEl) { nameEl.value = isNew ? '' : editing.name; }
    if (descEl) { descEl.value = isNew ? '' : editing.description; }
    if (activeEl) { activeEl.checked = isNew ? true : editing.is_active === true; }
  }

  function drawList() {
    clear(list);
    const boards = boardsCache && Array.isArray(boardsCache.boards) ? boardsCache.boards : [];
    for (const b of boards) {
      const li = document.createElement('li');
      li.className = 'list__item';
      const head = document.createElement('div');
      head.className = 'list__head';
      const pick = document.createElement('button');
      pick.type = 'button';
      pick.className = 'btn btn--small btn--quiet';
      pick.textContent = b.name;
      pick.setAttribute('aria-label', '게시판 ' + b.name + ' 고치기');
      pick.addEventListener('click', () => { editing = b; setText(msg, ''); drawMode(); });
      head.appendChild(pick);
      const badge = boardLevelBadge(b.access_level);
      if (badge) { head.appendChild(badge); }
      const kind = BOARD_KIND_LABELS[b.board_kind];
      if (kind) {
        const k = document.createElement('span');
        k.className = 'badge';
        k.textContent = kind.text;
        head.appendChild(k);
      }
      if (b.is_active !== true) {
        const off = document.createElement('span');
        off.className = 'badge badge--off';
        off.textContent = '보관';
        head.appendChild(off);
      }
      li.appendChild(head);
      list.appendChild(li);
    }
  }

  function loadTeams() {
    if (teamsLoaded || !teamEl) { return; }
    teamsLoaded = true;
    fetch(APP_BASE + '/api/teams', { credentials: 'same-origin', cache: 'no-store' })
      .then((response) => (response.ok ? readJson(response) : { teams: [] }))
      .then((data) => {
        clear(teamEl);
        for (const t of Array.isArray(data.teams) ? data.teams : []) {
          const option = document.createElement('option');
          option.value = String(t.id);
          option.textContent = t.name;
          teamEl.appendChild(option);
        }
      })
      .catch(() => {});
  }

  if (levelEl) {
    levelEl.addEventListener('change', () => {
      show(teamField, editing === null && levelEl.value === 'team');
      if (levelEl.value === 'team') { loadTeams(); }
    });
  }

  button.addEventListener('click', () => {
    editing = null;
    setText(msg, '');
    drawList();
    drawMode();
    dialog.showModal();
  });
  for (const close of dialog.querySelectorAll('[data-board-manage-close]')) {
    close.addEventListener('click', () => dialog.close());
  }
  if (reset) { reset.addEventListener('click', () => { editing = null; setText(msg, ''); drawMode(); }); }

  function submit() {
    let method = 'POST';
    let path = '/api/boards';
    let payload;
    if (editing === null) {
      payload = {
        name: nameEl ? nameEl.value : '',
        description: descEl ? descEl.value : '',
        access_level: levelEl ? levelEl.value : '',
        board_kind: kindEl ? kindEl.value : ''
      };
      if (payload.access_level === 'team') { payload.team_id = teamEl ? Number(teamEl.value) : 0; }
    } else {
      method = 'PATCH';
      path = '/api/boards/' + String(editing.id);
      payload = {
        name: nameEl ? nameEl.value : '',
        description: descEl ? descEl.value : '',
        is_active: activeEl ? activeEl.checked : true
      };
    }
    save.disabled = true;
    api(method, path, payload).then((result) => {
      save.disabled = false;
      if (result.status === 401) { toLogin(); return; }
      if (result.status === 200 || result.status === 201) {
        setText(msg, '');
        editing = null;
        loadBoards().then(() => { drawList(); drawMode(); });
        if (typeof onChanged === 'function') { onChanged(); }
        return;
      }
      /* 재인증 — 셸의 같은 창을 띄우고, 통과하면 이 창을 다시 열어 요청을 그대로 다시 보낸다(막는 것은
         서버다). 모달 안에 모달을 열지 않도록(DESIGN 2.7) 이 창을 먼저 닫는다 — 적은 값은 칸에 남는다. */
      if (result.status === 403 && result.data && result.data.next === 'reauth'
          && typeof window.qarediHandleReauth === 'function') {
        dialog.close();
        window.qarediHandleReauth({ status: 403 }, result.data, () => { dialog.showModal(); submit(); });
        return;
      }
      setText(msg, result.status === 409 ? '이미 있는 이름입니다. 다른 이름을 적어 주세요.' : errorMessage(result.data));
    }).catch(() => { save.disabled = false; setText(msg, GENERIC_ERROR); });
  }
  if (save) { save.addEventListener('click', submit); }
}

/* ─── 알림 (5-D, PRD F-22) — 사이드바 종 · 30초 폴링 · 알림 창 ─────────
 * ★ 알림 목록의 제목은 창구가 **지금도 볼 수 있는 페이지만** 준다(아니면 `page: null` → 「볼 수 없는 페이지」).
 *   이 파일은 누가 무엇을 볼 수 있나를 모른다.
 */
const NOTIFY_TEXT = {
  mention: '님이 댓글에서 나를 불렀습니다',
  reply: '님이 내 댓글에 답글을 달았습니다',
  comment_on_my_page: '님이 내 페이지에 댓글을 달았습니다',
  handover: '님이 페이지를 나에게 넘겼습니다',
  recurring_created: '반복 페이지가 만들어졌습니다',
  review_requested: '검토할 문서가 있습니다',
  due_tomorrow: '내일 기한인 문서가 있습니다',
  automation_denied: '자동 작업의 권한을 확인해 주세요',
  automation_disabled: '자동 작업이 꺼졌습니다'
};

function initNotifications() {
  const bell = document.getElementById('nav-community-bell');
  const count = document.getElementById('nav-community-bell-count');
  const dialog = document.getElementById('notify-dialog');
  if (!bell || !dialog) { return; }
  // 좁은 화면에서는 사이드바가 숨는다 — 창을 `body` 끝으로 옮겨 둔다(검색 창과 같은 까닭, 5-B).
  document.body.appendChild(dialog);
  const list = document.getElementById('notify-list');
  const empty = document.getElementById('notify-empty');
  const msg = document.getElementById('notify-msg');
  const allRead = document.getElementById('notify-all-read');

  function paintCount(n) {
    setText(count, n > 99 ? '99+' : String(n));
    show(count, n > 0);
    bell.setAttribute('aria-label', n > 0 ? '알림 — 안 읽은 것 ' + String(n) + '개' : '알림');
  }
  // U-3 — 30초 폴링은 사람이 누른 것이 아니다: 표식을 달아 세션을 늘리지 않는다(창을 열어 목록을 보는 open() 은 사람 요청).
  function poll() {
    api('GET', '/api/notifications?unread=1', undefined, { background: true }).then((result) => {
      if (result.status === 200) { paintCount(Number(result.data.unread) || 0); }
    }).catch(() => {});
  }
  function item(n) {
    const li = document.createElement('li');
    li.className = 'list__item';
    const head = document.createElement('div');
    head.className = 'list__head';
    const automationStatus = n.automation_status === true;
    const what = document.createElement(n.page || automationStatus ? 'a' : 'span');
    const who = n.actor ? personLabel(n.actor.display_name, n.actor.username) : '누군가';
    what.textContent = automationStatus ? NOTIFY_TEXT[n.kind] : n.kind === 'verify_expired' ? '검증이 만료됐습니다' : who + ' ' + (NOTIFY_TEXT[n.kind] || '님의 알림');
    if (n.page || automationStatus) {
      what.setAttribute('href', automationStatus ? COMMUNITY_BASE + '/automations' : COMMUNITY_BASE + '/p/' + n.page.id + (n.comment_id ? '#comment-' + n.comment_id : ''));
      what.addEventListener('click', () => {
        api('POST', '/api/notifications/read', { ids: [n.id] }).catch(() => {});
      });
    }
    head.appendChild(what);
    if (!n.read) {
      const b = document.createElement('span');
      b.className = 'badge badge--internal';
      b.textContent = '새 알림';
      head.appendChild(b);
    }
    li.appendChild(head);
    const body = document.createElement('div');
    body.className = 'list__body';
    const page = document.createElement('span');
    page.textContent = automationStatus ? '자동 작업에서 실행 기록을 확인하세요.' : n.page ? '「' + n.page.title + '」' : '볼 수 없는 페이지';
    const when = document.createElement('span');
    when.className = 'muted';
    when.textContent = shortWhen(n.at);
    body.appendChild(page);
    body.appendChild(when);
    li.appendChild(body);
    return li;
  }
  function open() {
    setText(msg, '');
    api('GET', '/api/notifications').then((result) => {
      if (result.status === 401) { toLogin(); return; }
      if (result.status !== 200) { setText(msg, errorMessage(result.data)); return; }
      clear(list);
      const items = Array.isArray(result.data.notifications) ? result.data.notifications : [];
      for (const n of items) { list.appendChild(item(n)); }
      show(empty, items.length === 0);
      paintCount(Number(result.data.unread) || 0);
    }).catch(() => setText(msg, GENERIC_ERROR));
    if (typeof dialog.showModal === 'function' && !dialog.open) { dialog.showModal(); }
  }
  bell.addEventListener('click', open);
  for (const close of dialog.querySelectorAll('[data-notify-close]')) {
    close.addEventListener('click', () => dialog.close());
  }
  if (allRead) {
    allRead.addEventListener('click', () => {
      allRead.disabled = true;
      api('POST', '/api/notifications/read', { all: true }).then(() => {
        allRead.disabled = false;
        open();
      }).catch(() => { allRead.disabled = false; setText(msg, GENERIC_ERROR); });
    });
  }
  // P-4a — 30초 폴링은 탭이 보일 때만(숨으면 멈추고, 다시 보이면 곧바로 한 번).
  whileVisible(poll, 30000).start();
}

/* ─── 블록 공통 ──────────────────────────────────────────────── */

/** Enter 가 「같은 종류로 이어 가는」 블록들. 빈 줄에서 Enter 면 문단으로 빠져나온다. */
const CONTINUE_KINDS = ['bulleted', 'numbered', 'to_do', 'quote', 'callout'];

/** 블록 공통 속성 한 벌 — 없는 값은 기본값으로 채운다 */
function blockBaseAttrs(from, id) {
  const a = from || {};
  return {
    id: typeof id === 'string' && id !== '' ? id : uuid4(),
    color: colorAllowed(a.color) ? a.color : null,
    align: a.align === 'center' || a.align === 'right' ? a.align : 'left',
    size: a.size === 'small' || a.size === 'large' || a.size === 'xlarge' ? a.size : 'normal'
  };
}

/** 창이 고른 종류로 노드 하나를 만든다 (글자는 비어 있다) */
function makeBlockNode(entry) {
  const nodeType = schema.nodes[entry.type];
  if (!nodeType) { return null; }
  if (entry.type === 'divider' || entry.type === 'toc') {
    return nodeType.create({ id: uuid4() });
  }
  const attrs = Object.assign(blockBaseAttrs(null, uuid4()), entry.attrs || {});
  if (entry.type === 'code_block') { attrs.language = 'plain'; }
  return nodeType.create(attrs);
}

/* ─── 우리가 짠 Enter · Backspace (지시서 3장) ───────────────── */

/**
 * Enter.
 *
 *  · 목록(`bulleted`·`numbered`·`to_do`)·`quote`·`callout` 에서 **글자가 있으면** 같은 종류로
 *    이어 간다(`to_do` 의 `checked` 는 새 줄에서 `false`, `callout` 의 아이콘은 그대로).
 *  · 같은 블록이 **비어 있으면** 그 블록을 `paragraph` 로 바꾼다(목록 빠져나오기).
 *  · `heading` 에서 Enter → 다음 블록은 `paragraph`(제목이 이어지면 안 된다).
 *  · `code_block` 은 여기서 false 를 돌려주고 아래 `commandCodeEnter`·`newlineInCode` 가 맡는다.
 */
function commandEnter(state, dispatch) {
  const sel = state.selection;
  const $from = sel.$from;
  if ($from.depth !== 1 || !$from.parent.isTextblock) { return false; }

  const parent = $from.parent;
  const name = parent.type.name;
  if (name === 'code_block') { return false; }

  // ① 빈 목록·인용·강조 상자 → 문단으로 빠져나온다
  if (sel.empty && parent.content.size === 0 && CONTINUE_KINDS.indexOf(name) !== -1) {
    if (dispatch) {
      dispatch(state.tr
        .setBlockType($from.pos, $from.pos, schema.nodes.paragraph, blockBaseAttrs(parent.attrs, parent.attrs.id))
        .scrollIntoView());
    }
    return true;
  }

  // ② 나머지 — 다음 블록의 종류를 우리가 정해서 쪼갠다
  let nextType = schema.nodes.paragraph;
  const nextAttrs = blockBaseAttrs(parent.attrs, uuid4());
  if (CONTINUE_KINDS.indexOf(name) !== -1) {
    nextType = parent.type;
    if (name === 'to_do') { nextAttrs.checked = false; }
    if (name === 'callout') { nextAttrs.icon = parent.attrs.icon; }
  }

  if (dispatch) {
    const tr = state.tr;
    if (!sel.empty) { tr.deleteSelection(); }
    tr.split(tr.mapping.map($from.pos), 1, [{ type: nextType, attrs: nextAttrs }]);
    dispatch(tr.scrollIntoView());
  }
  return true;
}

/** 코드 블록에서 빠져나온다 — 커서 앞 `trim` 글자를 지우고 뒤에 빈 문단을 둔다 */
function exitCodeBlock(state, dispatch, trim) {
  const $from = state.selection.$from;
  if ($from.depth !== 1 || $from.parent.type.name !== 'code_block') { return false; }
  if (dispatch) {
    const tr = state.tr;
    const after = $from.after(1);
    if (trim > 0) { tr.delete($from.pos - trim, $from.pos); }
    const at = tr.mapping.map(after);
    tr.insert(at, schema.nodes.paragraph.create(blockBaseAttrs(null, uuid4())));
    tr.setSelection(TextSelection.create(tr.doc, at + 1));
    dispatch(tr.scrollIntoView());
  }
  return true;
}

/** 코드 블록 안에서 **빈 줄 두 번**이면 빠져나온다. 그 밖에는 `newlineInCode` 가 줄을 바꾼다. */
function commandCodeEnter(state, dispatch) {
  const sel = state.selection;
  if (!sel.empty) { return false; }
  const $from = sel.$from;
  if ($from.depth !== 1 || $from.parent.type.name !== 'code_block') { return false; }
  if ($from.parentOffset !== $from.parent.content.size) { return false; }
  const before = $from.parent.textBetween(0, $from.parentOffset);
  if (!/\n\n$/.test(before)) { return false; }
  return exitCodeBlock(state, dispatch, 2);
}

/** Shift+Enter — 코드 블록에서 곧바로 빠져나온다 */
function commandShiftEnter(state, dispatch) {
  return exitCodeBlock(state, dispatch, 0);
}

/**
 * Backspace — 블록 **맨 앞**에서 누르면 목록·인용·강조 상자는 `paragraph` 로 되돌린다
 * (글자를 지우지 않는다). 그 밖의 자리는 `baseKeymap` 이 맡는다.
 */
function commandBackspace(state, dispatch) {
  const sel = state.selection;
  if (!sel.empty) { return false; }
  const $from = sel.$from;
  if ($from.depth !== 1 || $from.parentOffset !== 0) { return false; }
  const parent = $from.parent;
  if (CONTINUE_KINDS.indexOf(parent.type.name) === -1) { return false; }
  if (dispatch) {
    dispatch(state.tr
      .setBlockType($from.pos, $from.pos, schema.nodes.paragraph, blockBaseAttrs(parent.attrs, parent.attrs.id))
      .scrollIntoView());
  }
  return true;
}

/* ─── 플러그인 ───────────────────────────────────────────────── */

/** 블록 id 를 **유일하게** 지키는 플러그인 — 나뉘거나 복제된 블록에 새 id 를 준다 */
const uniqueBlockIds = new Plugin({
  appendTransaction(trs, oldState, newState) {
    let changed = false;
    for (const tr of trs) { if (tr.docChanged) { changed = true; } }
    if (!changed) { return null; }

    const seen = Object.create(null);
    let out = null;
    newState.doc.forEach((node, offset) => {
      const id = node.attrs && node.attrs.id;
      if (typeof id === 'string' && id !== '' && !seen[id]) {
        seen[id] = true;
        return;
      }
      const fresh = uuid4();
      seen[fresh] = true;
      out = out || newState.tr;
      out.setNodeMarkup(offset, undefined, Object.assign({}, node.attrs, { id: fresh }));
    });
    return out;
  }
});

/**
 * 블록 손잡이(⋮⋮) — 블록마다 왼쪽에 단추 하나를 둔다. 누르면 「블록 바꾸기」 창이 열린다.
 *
 * 자리는 **CSS 가 정한다**(`.editor > *` 가 기준, 손잡이는 그 왼쪽 바깥). 좌표를 JS 로 쓰지
 * 않는다(DESIGN 0장 2 — 인라인 style 0). 누른 자리는 클릭 때 `posAtDOM` 으로 다시 찾는다.
 */
const blockHandles = new Plugin({
  props: {
    decorations(state) {
      const decorations = [];
      state.doc.forEach((node, offset) => {
        if (!node.isTextblock) { return; }
        decorations.push(Decoration.widget(offset + 1, () => {
          const button = document.createElement('button');
          button.type = 'button';
          button.className = 'block-handle';
          button.setAttribute('contenteditable', 'false');
          button.setAttribute('tabindex', '-1');
          button.setAttribute('aria-label', '이 블록을 다른 종류로 바꾸기');
          button.textContent = '⋮⋮';
          return button;
        }, { side: -1, ignoreSelection: true, key: 'handle-' + String(node.attrs.id) }));
      });
      return DecorationSet.create(state.doc, decorations);
    }
  }
});

/**
 * 할 일 상자 누르기 — 체크를 켜고 끈다 (2026-09-23 사용자 지적 「체크가 안 됨」).
 * 상자는 `contenteditable=false` 인 장식이라 눌러도 PM 이 아무 일도 하지 않았다. 바뀐 체크는
 * 보통 저장으로 나가고, 서버가 이전 문서와 견주어 「처리 이력」에 남긴다(`community_check_changes`).
 */
const todoToggle = new Plugin({
  props: {
    handleDOMEvents: {
      mousedown(view, event) {
        const box = event.target instanceof Element ? event.target.closest('.doc-todo__box') : null;
        if (!box || !view.dom.contains(box)) { return false; }
        const text = box.parentNode ? box.parentNode.querySelector('.doc-todo__text') : null;
        if (!text) { return false; }
        event.preventDefault();
        const $pos = view.state.doc.resolve(view.posAtDOM(text, 0));
        const node = $pos.parent;
        if (node.type !== schema.nodes.to_do) { return false; }
        view.dispatch(view.state.tr.setNodeMarkup($pos.before(), undefined,
          Object.assign({}, node.attrs, { checked: !node.attrs.checked })));
        return true;
      }
    }
  }
});

/**
 * 목차를 **편집하는 동안에도** 채운다 (2026-09-23 사용자 결정 — 전에는 「읽기 모드에서 채워집니다」
 * 자리표시뿐이라 넣은 블록이 무엇인지 알 수 없었다). 모양은 읽기 렌더러의 목차와 같다
 * (`nav.doc-toc > ul > li.doc-toc__item--N > a`). 누르면 그 제목으로 옮겨 간다.
 * 문서가 바뀔 때마다 편집기 안의 목차를 전부 다시 그린다 — 목차는 제목 몇 줄이라 가볍다.
 */
function drawLiveToc(dom, view) {
  clear(dom);
  const items = [];
  view.state.doc.forEach((node, offset) => {
    if (node.type === schema.nodes.heading && node.textContent.trim() !== '') {
      items.push({ level: node.attrs.level, text: node.textContent, pos: offset, size: node.nodeSize });
    }
  });
  if (items.length === 0) {
    const p = document.createElement('p');
    p.className = 'muted';
    p.textContent = '제목이 아직 없습니다 — 「제목 1·2·3」 블록을 넣으면 여기에 모입니다';
    dom.appendChild(p);
    return;
  }
  const ul = document.createElement('ul');
  for (const h of items) {
    const li = document.createElement('li');
    li.className = 'doc-toc__item doc-toc__item--' + String(h.level);
    const a = document.createElement('a');
    a.setAttribute('href', '#');
    a.textContent = h.text;
    a.addEventListener('mousedown', (event) => {
      event.preventDefault();
      const end = Math.min(h.pos + h.size - 1, view.state.doc.content.size);
      view.dispatch(view.state.tr.setSelection(TextSelection.create(view.state.doc, end)).scrollIntoView());
      view.focus();
    });
    a.addEventListener('click', (event) => { event.preventDefault(); });
    li.appendChild(a);
    ul.appendChild(li);
  }
  dom.appendChild(ul);
}

const liveToc = new Plugin({
  props: {
    nodeViews: {
      toc(node, view) {
        const dom = document.createElement('nav');
        dom.className = 'doc-toc';
        dom.setAttribute('contenteditable', 'false');
        dom.setAttribute('data-live-toc', '');
        drawLiveToc(dom, view);
        return {
          dom: dom,
          update: (next) => next.type === schema.nodes.toc,
          stopEvent: () => true,
          ignoreMutation: () => true
        };
      }
    }
  },
  view() {
    return {
      update(view, prevState) {
        if (view.state.doc.eq(prevState.doc)) { return; }
        for (const dom of view.dom.querySelectorAll('[data-live-toc]')) { drawLiveToc(dom, view); }
      }
    };
  }
});

function buildInputRules() {
  const rules = [];

  for (const level of [1, 2, 3]) {
    rules.push(textblockTypeInputRule(
      new RegExp('^#{' + level + '}\\s$'),
      schema.nodes.heading,
      { level: level }
    ));
  }
  rules.push(textblockTypeInputRule(/^\s*[-*]\s$/, schema.nodes.bulleted));
  rules.push(textblockTypeInputRule(/^\s*\d+\.\s$/, schema.nodes.numbered));
  rules.push(textblockTypeInputRule(/^\s*\[\]\s$/, schema.nodes.to_do, { checked: false }));
  rules.push(textblockTypeInputRule(/^\s*\[x\]\s$/i, schema.nodes.to_do, { checked: true }));
  rules.push(textblockTypeInputRule(/^\s*>\s$/, schema.nodes.quote));
  rules.push(textblockTypeInputRule(/^```$/, schema.nodes.code_block));

  // `---` → 구분선. 그 자리의 블록을 통째로 바꾸고 **뒤에 빈 문단**을 둔다 —
  // 구분선은 글자를 담지 않는 블록이라, 뒤에 쓸 자리가 없으면 커서가 갈 곳을 잃는다.
  rules.push(new InputRule(/^---$/, (state, match, start) => {
    const $start = state.doc.resolve(start);
    const from = $start.before($start.depth);
    const to = $start.after($start.depth);
    const hr = schema.nodes.divider.create({ id: uuid4() });
    const para = schema.nodes.paragraph.create(blockBaseAttrs(null, uuid4()));
    const tr = state.tr.replaceWith(from, to, [hr, para]);
    return tr.setSelection(TextSelection.create(tr.doc, from + hr.nodeSize + 1));
  }));

  return inputRules({ rules: rules });
}

/* ─── 단축키 글자 (「블록 추가」 창과 빈 페이지 안내가 같이 쓴다) ─── */

const IS_MAC = /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent || '');

/** `Mod-Alt-1` → 「Ctrl+Alt+1」 / 맥은 「⌘⌥1」 */
function keyLabel(key) {
  if (typeof key !== 'string' || key === '') { return ''; }
  const parts = key.split('-');
  const last = parts.pop();
  const names = parts.map((m) => {
    if (m === 'Mod') { return IS_MAC ? '⌘' : 'Ctrl'; }
    if (m === 'Alt') { return IS_MAC ? '⌥' : 'Alt'; }
    if (m === 'Shift') { return IS_MAC ? '⇧' : 'Shift'; }
    return m;
  });
  return IS_MAC ? names.join('') + last : names.concat([last]).join('+');
}

/** 줄 맨 앞에서 치는 글자 — 끝의 빈칸은 「빈칸」이라고 적는다(보이지 않으니까) */
function mdLabel(md) {
  if (typeof md !== 'string' || md === '') { return ''; }
  if (md === 'Enter') { return 'Enter'; }
  return /\s$/.test(md) ? md.trim() + ' 빈칸' : md;
}

/** 창 오른쪽 설명 줄 — 이 종류를 만드는 길을 전부 적는다 */
function shortcutSentence(entry) {
  const out = [];
  if (entry.md === 'Enter') {
    out.push('줄 끝에서 Enter — 새 줄은 본문입니다');
  } else if (entry.md) {
    out.push('줄 맨 앞에서 「' + mdLabel(entry.md) + '」');
  }
  if (entry.key) { out.push(keyLabel(entry.key) + ' — 지금 블록을 이 종류로'); }
  return out.length === 0 ? '단축키 없음 — 이 창에서 넣습니다' : '단축키: ' + out.join(' · ');
}

/* ─── 「블록 추가」 창 (지시서 2장 — `/` 슬래시 메뉴를 대신한다) ─── */

function createBlockDialog(getView, hooks) {
  const dialog = document.getElementById('block-dialog');
  const listEl = document.getElementById('block-list');
  const titleEl = document.getElementById('block-dialog-title');
  const nameEl = document.getElementById('block-preview-name');
  const descEl = document.getElementById('block-desc');
  const keysEl = document.getElementById('block-keys');
  const sampleEl = document.getElementById('block-sample');
  const insertButton = document.getElementById('block-insert');
  if (!dialog || !listEl || !sampleEl || !insertButton) { return null; }

  let index = 0;
  let mode = 'insert';
  let targetPos = null;
  const items = [];

  /** 목록을 한 번만 그린다 — 종류는 늘 같다 */
  for (let i = 0; i < BLOCK_TYPES.length; i++) {
    const entry = BLOCK_TYPES[i];
    const li = document.createElement('li');
    li.className = 'list__item';
    li.setAttribute('role', 'option');
    li.setAttribute('aria-selected', 'false');

    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'block-pick';
    button.setAttribute('data-block-type', entry.type);
    button.setAttribute('data-block-index', String(i));
    const strong = document.createElement('strong');
    strong.textContent = entry.label;
    button.appendChild(strong);
    const hint = mdLabel(entry.md) || keyLabel(entry.key);
    if (hint) {
      const keys = document.createElement('span');
      keys.className = 'block-pick__keys';
      keys.textContent = hint;
      button.appendChild(keys);
    }
    button.addEventListener('click', () => { select(i); });

    li.appendChild(button);
    listEl.appendChild(li);
    items.push(li);
  }

  function select(i) {
    index = ((i % BLOCK_TYPES.length) + BLOCK_TYPES.length) % BLOCK_TYPES.length;
    for (let k = 0; k < items.length; k++) {
      const on = k === index;
      items[k].className = 'list__item' + (on ? ' is-active' : '');
      items[k].setAttribute('aria-selected', on ? 'true' : 'false');
    }
    const entry = BLOCK_TYPES[index];
    setText(nameEl, entry.label);
    setText(descEl, entry.desc);
    setText(keysEl, shortcutSentence(entry));
    clear(sampleEl);
    // 미리보기도 읽기 렌더러로 그린다. 4단계의 「하위 페이지」는 제목 지도(`sampleCtx`)가 있다.
    sampleEl.appendChild(renderDoc(entry.sample, entry.sampleCtx || {}));
  }

  function open(kind, pos) {
    mode = kind === 'change' ? 'change' : 'insert';
    targetPos = mode === 'change' ? pos : null;
    setText(titleEl, mode === 'change' ? '블록 바꾸기' : '블록 추가');
    setText(insertButton, mode === 'change' ? '바꾸기' : '넣기');
    select(0);
    if (typeof dialog.showModal === 'function' && !dialog.open) { dialog.showModal(); }
  }

  function commit() {
    const view = getView();
    const entry = BLOCK_TYPES[index];
    if (!view || !entry) { dialog.close(); return; }

    /* 4단계 「하위 페이지」만 **넣기 전에 서버를 부른다** — 빈 페이지를 먼저 만들고 그 id 로
       블록을 넣는다. 만들지 못하면 블록도 넣지 않는다(가리킬 곳이 없는 카드를 만들지 않는다).
       블록을 바꾸는 자리에서 골랐더라도 같은 동작이다 — 「바꾼다」로 새 페이지가 생기는 것이
       더 놀라운 일이라 커서 뒤에 **넣는다**. */
    if (entry.makesPage) {
      if (!hooks || typeof hooks.createChildPage !== 'function') { dialog.close(); return; }
      insertButton.disabled = true;
      hooks.createChildPage()
        .then((made) => {
          insertButton.disabled = false;
          dialog.close();
          if (!made || typeof made.id !== 'string') { return; }
          const node = schema.nodes.child_page.create({ id: uuid4(), page_id: made.id, title: made.title || '' });
          insertNode(view, node);
          view.focus();
        })
        .catch(() => { insertButton.disabled = false; dialog.close(); });
      return;
    }

    /* 5-E 덩어리 다섯 — 값을 먼저 정해야 하므로 「블록 고치기」 창으로 넘긴다(결정 E-2).
       「바꾸기」 자리에서 골랐어도 **넣는다** — 글자 블록을 표로 「바꾸면」 글자가 사라지기 때문이다. */
    if (entry.editsAtom) {
      dialog.close();
      if (hooks && typeof hooks.editAtom === 'function') { hooks.editAtom(entry.type, null, null); }
      return;
    }

    if (mode === 'change') { changeBlock(view, entry, targetPos); } else { insertBlock(view, entry); }
    dialog.close();
    view.focus();
  }

  /** 커서가 있던 블록 **다음**에 새 블록을 넣고 커서를 그리로 옮긴다 */
  function insertBlock(view, entry) {
    const node = makeBlockNode(entry);
    if (!node) { return; }
    insertNode(view, node);
  }

  /** 이미 만들어진 노드 하나를 커서 다음에 넣는다 (위 둘이 같이 쓴다) */
  function insertNode(view, node) {
    const $from = view.state.selection.$from;
    const at = $from.depth >= 1 ? $from.after(1) : view.state.doc.content.size;
    const tr = view.state.tr.insert(at, node);
    if (node.isTextblock) {
      tr.setSelection(TextSelection.create(tr.doc, at + 1));
    } else {
      // 구분선·목차는 글자를 담지 않는다 — 뒤에 빈 문단을 하나 두고 거기로 간다.
      const para = schema.nodes.paragraph.create(blockBaseAttrs(null, uuid4()));
      tr.insert(at + node.nodeSize, para);
      tr.setSelection(TextSelection.create(tr.doc, at + node.nodeSize + 1));
    }
    view.dispatch(tr.scrollIntoView());
  }

  /** 그 블록의 **종류만** 바꾼다 — 글자는 그대로 둔다 */
  function changeBlock(view, entry, pos) {
    if (typeof pos !== 'number') { return; }
    const node = view.state.doc.nodeAt(pos);
    const nodeType = schema.nodes[entry.type];
    if (!node || !nodeType) { return; }

    if (entry.type === 'divider' || entry.type === 'toc') {
      const atom = nodeType.create({ id: node.attrs.id || uuid4() });
      view.dispatch(view.state.tr.replaceWith(pos, pos + node.nodeSize, atom).scrollIntoView());
      return;
    }
    if (!node.isTextblock) {
      const fresh = makeBlockNode(entry);
      const tr = view.state.tr.replaceWith(pos, pos + node.nodeSize, fresh);
      tr.setSelection(TextSelection.create(tr.doc, pos + 1));
      view.dispatch(tr.scrollIntoView());
      return;
    }
    const attrs = Object.assign(blockBaseAttrs(node.attrs, node.attrs.id), entry.attrs || {});
    if (entry.type === 'code_block') { attrs.language = 'plain'; }
    const tr = view.state.tr.setBlockType(pos + 1, pos + node.nodeSize - 1, nodeType, attrs);
    tr.setSelection(TextSelection.create(tr.doc, Math.min(pos + 1, tr.doc.content.size)));
    view.dispatch(tr.scrollIntoView());
  }

  // 키보드: ↑↓ 옮기기 · Enter 넣기 · Esc 닫기(<dialog> 가 스스로 한다)
  dialog.addEventListener('keydown', (event) => {
    if (event.key === 'ArrowDown') { event.preventDefault(); select(index + 1); return; }
    if (event.key === 'ArrowUp') { event.preventDefault(); select(index - 1); return; }
    if (event.key === 'Enter') {
      const target = event.target;
      if (target && target.hasAttribute && target.hasAttribute('data-block-close')) { return; }
      event.preventDefault();
      commit();
    }
  }, true);

  insertButton.addEventListener('click', commit);
  for (const close of dialog.querySelectorAll('[data-block-close]')) {
    close.addEventListener('click', () => dialog.close());
  }

  select(0);
  return {
    open: open,
    close: () => { if (dialog.open) { dialog.close(); } },
    // 단축키(Ctrl+Alt+숫자)가 창을 열지 않고 같은 「바꾸기」를 부른다 — 규칙을 두 벌로 두지 않는다.
    changeTo: (entry, pos) => { const view = getView(); if (view) { changeBlock(view, entry, pos); } }
  };
}

/* ─── 「블록 고치기」 창 — 5-E 덩어리 다섯 (결정 E-2) ─────────────
 *
 * 토글·표·페이지 링크·그림·파일은 편집기 안에서 요약 카드(덩어리)이고 값은 여기서 고친다.
 * `open(type, attrs, pos)` — attrs 가 null 이면 새로 넣기, pos 가 수이면 그 자리의 블록을 고친다.
 * 페이지 찾기는 커뮤니티 검색 창구(볼 수 있는 것만), 파일 찾기는 **t-box 창구 `GET /api/files`** (내 권한 목록)를
 * 그대로 부른다. 고른 이름은 `remember*` 로 제목 지도에 넣어 둔다 — 읽기 화면이 저장 뒤 곧바로 이름을 그리게.
 * 등급 규칙(파일이 페이지를 읽는 범위보다 좁으면 안 된다)은 **서버가 저장할 때** 판정한다 — 여기서 걸러 보이지 않는다.
 */
const ATOM_TYPES = ['button','toggle', 'table', 'link_to_page', 'image', 'file', 'columns', 'embed'];
const TABLE_MAX_ROWS = 200;
const TABLE_MAX_COLS = 20;

function createAtomDialog(getView, hooks) {
  const dialog = document.getElementById('atom-dialog');
  if (!dialog) { return null; }
  const $ = (id) => document.getElementById(id);
  const titleEl = $('atom-dialog-title');
  const msgEl = $('atom-msg');
  const commitButton = $('atom-commit');
  const panes = { toggle: $('atom-toggle'), table: $('atom-table'), link_to_page: $('atom-link'), image: $('atom-file'), file: $('atom-file'), columns: $('atom-columns'), embed: $('atom-embed') };
  // 7-C 임베드 — 주소 · 설명 · 허용된 사이트 안내(창구 `GET /api/embed-domains` 의 `hosts` — 서버가 그릴 때 쓰는 판정 그대로)
  const buttonPane = document.createElement('div'); buttonPane.className='atom-pane'; buttonPane.hidden=true;
  const buttonLabel=document.createElement('input');buttonLabel.type='text';buttonLabel.maxLength=30;
  const buttonChoice=document.createElement('select');
  for (const [text,control] of [['단추 문구',buttonLabel],['이 페이지에 묶인 내 버튼 작업',buttonChoice]]) { control.setAttribute('aria-label',text); const label=document.createElement('label');label.className='field';const span=document.createElement('span');span.className='field__label';span.textContent=text;label.append(span,control);buttonPane.appendChild(label); }
  $('atom-embed').parentElement.appendChild(buttonPane);panes.button=buttonPane;
  const embedUrl = $('atom-embed-url');
  const embedCaption = $('atom-embed-caption');
  const embedHosts = $('atom-embed-hosts');
  const toggleSummary = $('atom-toggle-summary');
  const toggleBody = $('atom-toggle-body');
  const hRow = $('atom-table-hrow');
  const hCol = $('atom-table-hcol');
  const grid = $('atom-table-grid');
  const linkQ = $('atom-link-q');
  const linkList = $('atom-link-list');
  const linkChosen = $('atom-link-chosen');
  const fileQ = $('atom-file-q');
  const fileList = $('atom-file-list');
  const fileChosen = $('atom-file-chosen');
  const fileCaption = $('atom-file-caption');
  const widthField = $('atom-image-width-field');
  const widthSelect = $('atom-image-width');
  const uploadLink = $('atom-file-upload');
  if (uploadLink) { uploadLink.setAttribute('href', APP_BASE + '/'); }

  let type = null;
  let pos = null;
  let cur = {};
  let rows = [['']];
  let spans = {};          // 6-C 합친 칸 {'행,열': [세로, 가로]}
  let picked = null;       // 6-C 고른 칸 [행, 열]
  let searchTimer = null;
  let searchSerial = 0;

  /* ── 표 칸 격자 — 진짜 <table> 안의 입력 칸(열 수가 바뀌어도 인라인 style 없음) ── */
  function readGrid() {
    const out = [];
    for (const tr of grid.querySelectorAll('tr')) {
      out.push([...tr.querySelectorAll('input')].map((inp) => inp.value));
    }
    if (out.length > 0) { rows = out; }
  }
  /** 6-C — 합친 칸이 덮는 자리('행,열') */
  function coveredSet() {
    const out = new Set();
    for (const key of Object.keys(spans)) {
      const [r0, c0] = key.split(',').map(Number);
      const [rs, cs] = spans[key];
      for (let y = r0; y < r0 + rs; y++) { for (let x = c0; x < c0 + cs; x++) { if (y !== r0 || x !== c0) { out.add(y + ',' + x); } } }
    }
    return out;
  }
  function drawGrid() {
    clear(grid);
    // 표 모양이 바뀌어 표 밖으로 나가는 합침은 버린다
    for (const key of Object.keys(spans)) {
      const [r0, c0] = key.split(',').map(Number);
      if (r0 + spans[key][0] > rows.length || c0 + spans[key][1] > (rows[0] || []).length) { delete spans[key]; }
    }
    const covered = coveredSet();
    for (let r = 0; r < rows.length; r++) {
      const tr = document.createElement('tr');
      for (let c = 0; c < rows[r].length; c++) {
        const td = document.createElement('td');
        td.className = 'doc-table__cell' + (picked && picked[0] === r && picked[1] === c ? ' is-picked' : '');
        const inp = document.createElement('input');
        inp.type = 'text';
        inp.className = 'input';
        inp.maxLength = 2000;
        inp.autocomplete = 'off';
        inp.spellcheck = false;
        inp.setAttribute('data-r', String(r));
        inp.setAttribute('data-c', String(c));
        const sp = spans[r + ',' + c];
        if (covered.has(r + ',' + c)) {
          // 덮인 칸 — 비우고 막는다(합친 칸의 일부)
          inp.value = '';
          rows[r][c] = '';
          inp.disabled = true;
          inp.placeholder = '(합침)';
        } else {
          inp.value = rows[r][c];
        }
        inp.setAttribute('aria-label', String(r + 1) + '행 ' + String(c + 1) + '열' + (sp ? ' — 합친 칸 ' + sp[0] + '×' + sp[1] : ''));
        inp.addEventListener('focus', () => { picked = [r, c]; for (const x of grid.querySelectorAll('.is-picked')) { x.classList.remove('is-picked'); } td.classList.add('is-picked'); });
        td.appendChild(inp);
        tr.appendChild(td);
      }
      grid.appendChild(tr);
    }
  }
  function reshape(fn) { readGrid(); fn(); drawGrid(); }
  /* 6-C 칸 합치기 — 고른 칸부터 가로·세로 몇 칸. 덮일 칸에 글자가 있거나 다른 합침과 겹치면 합치지 않는다 */
  $('atom-merge').addEventListener('click', () => {
    readGrid();
    if (!picked) { setText(msgEl, '합칠 첫 칸을 먼저 누르세요.'); return; }
    const [r0, c0] = picked;
    const cs = Math.max(1, Math.min(20, parseInt($('atom-merge-cols').value, 10) || 1));
    const rs = Math.max(1, Math.min(20, parseInt($('atom-merge-rows').value, 10) || 1));
    if (r0 + rs > rows.length || c0 + cs > rows[0].length) { setText(msgEl, '표 밖으로 나갑니다.'); return; }
    // 이미 합친 칸의 크기를 바꿀 때는 자기 합침을 빼고 센다(독립 검토 6-C L5)
    const own = spans[r0 + ',' + c0];
    delete spans[r0 + ',' + c0];
    const covered = coveredSet();
    if (own) { spans[r0 + ',' + c0] = own; }
    for (let y = r0; y < r0 + rs; y++) {
      for (let x = c0; x < c0 + cs; x++) {
        if (y === r0 && x === c0) { continue; }
        if (covered.has(y + ',' + x) || spans[y + ',' + x] || String(rows[y][x]).trim() !== '') {
          setText(msgEl, '합칠 칸이 비어 있어야 합니다(다른 합침과도 겹치면 안 됩니다).');
          return;
        }
      }
    }
    if (covered.has(r0 + ',' + c0)) { setText(msgEl, '이미 합쳐진 칸 안입니다.'); return; }
    setText(msgEl, '');
    if (rs === 1 && cs === 1) { delete spans[r0 + ',' + c0]; } else { spans[r0 + ',' + c0] = [rs, cs]; }
    drawGrid();
  });
  $('atom-unmerge').addEventListener('click', () => {
    if (!picked) { setText(msgEl, '풀 칸을 먼저 누르세요.'); return; }
    readGrid();
    delete spans[picked[0] + ',' + picked[1]];
    setText(msgEl, '');
    drawGrid();
  });

  /* 6-C 단 나누기 — 단 수에 따라 셋째 칸을 보이고 감춘다 */
  const colsN = $('atom-columns-n');
  const syncCols = () => {
    show($('atom-col-2-field'), colsN.value === '3');
    // 3단 → 2단: 셋째 단 글은 넣을 때 버려진다 — 말없이 버리지 않게 알린다(독립 검토 6-C L3)
    setText(msgEl, colsN.value === '2' && $('atom-col-2').value.trim() !== '' ? '2단으로 줄이면 셋째 단의 글은 저장되지 않습니다.' : '');
  };
  colsN.addEventListener('change', syncCols);
  $('atom-row-add').addEventListener('click', () => reshape(() => {
    if (rows.length < TABLE_MAX_ROWS) { rows.push(rows[0].map(() => '')); }
  }));
  $('atom-row-del').addEventListener('click', () => reshape(() => { if (rows.length > 1) { rows.pop(); } }));
  $('atom-col-add').addEventListener('click', () => reshape(() => {
    if (rows[0].length < TABLE_MAX_COLS) { for (const r of rows) { r.push(''); } }
  }));
  $('atom-col-del').addEventListener('click', () => reshape(() => {
    if (rows[0].length > 1) { for (const r of rows) { r.pop(); } }
  }));

  /* ── 페이지 찾기 (커뮤니티 검색 — 볼 수 있는 페이지만) ── */
  function drawChosen() {
    if (type === 'link_to_page') {
      setText(linkChosen, cur.page_id ? '고른 페이지: ' + (cur.title || '제목 없는 페이지') : '아직 고르지 않았습니다.');
    } else if (type === 'image' || type === 'file') {
      setText(fileChosen, cur.file_ref ? '고른 파일: ' + (cur.name || '이름 없음') : '아직 고르지 않았습니다.');
    }
  }
  function pickButton(label, meta, onPick) {
    const li = document.createElement('li');
    li.className = 'list__item';
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'block-pick';
    const strong = document.createElement('strong');
    strong.textContent = label;
    b.appendChild(strong);
    if (meta) {
      const span = document.createElement('span');
      span.className = 'block-pick__keys';
      span.textContent = meta;
      b.appendChild(span);
    }
    b.addEventListener('click', onPick);
    li.appendChild(b);
    return li;
  }
  function searchPages() {
    const q = linkQ.value.trim();
    const serial = ++searchSerial;
    clear(linkList);
    if (q === '') { return; }
    api('GET', '/api/search?q=' + encodeURIComponent(q)).then((result) => {
      if (serial !== searchSerial) { return; }
      if (result.status !== 200) { setText(msgEl, errorMessage(result.data)); return; }
      for (const r of Array.isArray(result.data.results) ? result.data.results : []) {
        if (!r || typeof r.id !== 'string' || r.id === hooks.selfId()) { continue; }
        linkList.appendChild(pickButton(r.title || '제목 없는 페이지', r.space && r.space.name ? r.space.name : '', () => {
          cur.page_id = r.id;
          cur.title = r.title || '';
          hooks.rememberLink(r.id, { title: r.title || '', icon: r.icon || null });
          drawChosen();
        }));
      }
      if (!linkList.firstChild) { linkList.appendChild(pickButton('찾은 페이지가 없습니다', '', () => {})); }
    }).catch(() => setText(msgEl, GENERIC_ERROR));
  }

  /* ── 파일 찾기 (t-box 창구 — 내 권한으로 볼 수 있는 파일만) ── */
  function searchFiles() {
    const q = fileQ.value.trim();
    const serial = ++searchSerial;
    clear(fileList);
    fetch(APP_BASE + '/api/files' + (q === '' ? '' : '?q=' + encodeURIComponent(q)), { credentials: 'same-origin', cache: 'no-store' })
      .then((response) => readJson(response).then((data) => ({ status: response.status, data: data })))
      .then((result) => {
        if (serial !== searchSerial) { return; }
        if (result.status !== 200) { setText(msgEl, errorMessage(result.data)); return; }
        for (const f of Array.isArray(result.data.files) ? result.data.files : []) {
          if (!f || typeof f.id !== 'string') { continue; }
          const isImage = f.preview_kind === 'image' && f.scan_status === 'clean';
          if (type === 'image' && !isImage) { continue; }
          fileList.appendChild(pickButton(f.name || '이름 없음', sizeLabel(f.size_bytes), () => {
            cur.file_ref = 'tbox:' + f.id;
            cur.name = f.name || '';
            hooks.rememberFile(f.id, { name: f.name || '', size: typeof f.size_bytes === 'number' ? f.size_bytes : 0, image: isImage });
            drawChosen();
          }));
        }
        if (!fileList.firstChild) {
          fileList.appendChild(pickButton(type === 'image' ? '고를 수 있는 그림이 없습니다' : '고를 수 있는 파일이 없습니다', '', () => {}));
        }
      })
      .catch(() => setText(msgEl, GENERIC_ERROR));
  }
  function sizeLabel(n) {
    if (typeof n !== 'number' || n < 0) { return ''; }
    if (n < 1024) { return String(n) + ' B'; }
    if (n < 1048576) { return String(Math.round(n / 1024)) + ' KB'; }
    return String(Math.round(n / 104857.6) / 10) + ' MB';
  }
  function debounce(fn) {
    return () => { if (searchTimer) { clearTimeout(searchTimer); } searchTimer = setTimeout(fn, 250); };
  }
  linkQ.addEventListener('input', debounce(searchPages));
  fileQ.addEventListener('input', debounce(searchFiles));
  $('atom-file-refresh').addEventListener('click', searchFiles);

  const LABELS = { button:'버튼', toggle: '토글', table: '표', link_to_page: '페이지 링크', image: '그림', file: '파일', columns: '단 나누기', embed: '임베드' };

  /** 7-C — 허용된 사이트 안내. 판단은 서버가 저장할 때 한다 — 여기서는 무엇을 넣을 수 있는지 알려 주고, 받은 목록 밖이면
      넣기 전에 알린다(목록 밖을 넣으면 그 뒤 자동 저장이 전부 400 이 된다 — 독립 검토 7-C L-5). 목록을 못 받으면 서버에 맡긴다.
      목록은 `hosts`(서버가 거른 것 — 이 사이트 자신·상위·하위 도메인은 표에 있어도 빠진다)다. 관리용 표 `domains` 를 쓰면
      저장·그리기에서 막히는 자기 주소가 「허용됨」으로 보인다(임베드 켜기 독립 검토 정보 6). */
  let embedAllowed = null;
  function loadEmbedHosts() {
    setText(embedHosts, '허용된 사이트를 읽는 중…');
    embedAllowed = null;
    api('GET', '/api/embed-domains').then((result) => {
      if (result.status !== 200) { setText(embedHosts, ''); return; }
      const hosts = (Array.isArray(result.data.hosts) ? result.data.hosts : []).filter((h) => typeof h === 'string');
      embedAllowed = hosts;
      setText(embedHosts, hosts.length === 0
        ? '관리자가 아직 허용한 사이트가 없습니다 — 지금은 임베드를 넣을 수 없습니다.'
        : '허용된 사이트: ' + hosts.join(', ') + (result.data.frames === true ? '' : ' · 사이트가 틀을 열기 전까지는 「허용 대기」 카드로 보입니다'));
    }).catch(() => setText(embedHosts, ''));
  }

  function open(nextType, attrs, nextPos) {
    if (ATOM_TYPES.indexOf(nextType) === -1) { return; }
    type = nextType;
    pos = typeof nextPos === 'number' ? nextPos : null;
    cur = Object.assign({}, attrs || {});
    setText(msgEl, '');
    setText(titleEl, LABELS[type] + (pos === null ? ' 넣기' : ' 고치기'));
    setText(commitButton, pos === null ? '넣기' : '고치기');
    for (const k of Object.keys(panes)) { show(panes[k], false); }
    show(panes[type], true);

    if (type === 'toggle') {
      toggleSummary.value = cur.summary || '';
      toggleBody.value = (Array.isArray(cur.kids) ? cur.kids : []).map((k) => (k && k.text) || '').join('\n');
    } else if (type === 'table') {
      rows = Array.isArray(cur.rows) && cur.rows.length > 0 ? cur.rows.map((r) => r.slice()) : [['', ''], ['', '']];
      hRow.checked = attrs ? cur.header_row === true : true;
      hCol.checked = cur.header_col === true;
      spans = cur.spans && typeof cur.spans === 'object' ? JSON.parse(JSON.stringify(cur.spans)) : {};
      picked = null;
      drawGrid();
    } else if (type === 'columns') {
      const cols = Array.isArray(cur.cols) && cur.cols.length >= 2 ? cur.cols : [{ kids: [] }, { kids: [] }];
      colsN.value = cols.length === 3 ? '3' : '2';
      for (let i = 0; i < 3; i++) {
        $('atom-col-' + i).value = cols[i] ? (cols[i].kids || []).map((k) => (k && k.text) || '').join('\n') : '';
      }
      syncCols();
    } else if (type === 'link_to_page') {
      linkQ.value = '';
      clear(linkList);
    } else if (type === 'button') {
      buttonLabel.value=cur.label||'';buttonChoice.replaceChildren();
      loadAutomations(api).then(result=>{
        for(const r of result.automations||[]) { if(r.kind!=='button'||r.scope_page_id!==currentPageId())continue;const option=document.createElement('option');option.value=r.id;option.textContent=r.name;option.selected=r.id===cur.automation_id;buttonChoice.appendChild(option); }
        if (cur.automation_id && !Array.from(buttonChoice.options).some(o => o.value === cur.automation_id)) {
          const existing = document.createElement('option'); existing.value = cur.automation_id; existing.textContent = '현재 연결된 작업'; existing.selected = true; buttonChoice.appendChild(existing);
        }
        if(buttonChoice.children.length===0)setText(msgEl,'이 페이지의 더보기에서 버튼 작업을 먼저 만드세요.');
      }).catch(()=>setText(msgEl,GENERIC_ERROR));
    } else if (type === 'embed') {
      embedUrl.value = cur.url || '';
      embedCaption.value = cur.caption || '';
      loadEmbedHosts();
    } else {
      fileQ.value = '';
      fileCaption.value = cur.caption || '';
      show(widthField, type === 'image');
      widthSelect.value = cur.width || 'normal';
      searchFiles();
    }
    drawChosen();
    if (typeof dialog.showModal === 'function' && !dialog.open) { dialog.showModal(); }
  }

  /** 창의 값 → 노드 속성. 고르지 않은 링크·파일은 넣지 않는다(가리킬 곳 없는 카드를 만들지 않는다). */
  function collect() {
    const id = typeof cur.id === 'string' && cur.id !== '' ? cur.id : uuid4();
    if (type === 'toggle') {
      const old = Array.isArray(cur.kids) ? cur.kids : [];
      const lines = toggleBody.value.split('\n');
      // 줄 순서대로 예전 문단 id 를 물려준다 — 댓글 앵커가 가능한 한 살아 있게.
      return { id: id, summary: toggleSummary.value, kids: lines.map((t, i) => ({ id: old[i] && old[i].id ? old[i].id : null, text: t })) };
    }
    if (type === 'table') {
      readGrid();
      return { id: id, header_row: hRow.checked, header_col: hCol.checked, rows: rows.map((r) => r.slice()), spans: JSON.parse(JSON.stringify(spans)) };
    }
    if (type === 'columns') {
      const n = colsN.value === '3' ? 3 : 2;
      const old = Array.isArray(cur.cols) ? cur.cols : [];
      const cols = [];
      for (let i = 0; i < n; i++) {
        const oldKids = old[i] && Array.isArray(old[i].kids) ? old[i].kids : [];
        const lines = $('atom-col-' + i).value.split('\n');
        // 줄 순서대로 예전 문단 id 를 물려준다(댓글 앵커가 가능한 한 살아 있게)
        cols.push({ id: old[i] && old[i].id ? old[i].id : null, kids: lines.map((t, k) => ({ id: oldKids[k] && oldKids[k].id ? oldKids[k].id : null, text: t })) });
      }
      return { id: id, cols: cols };
    }
    if (type === 'button') {
      if(!buttonChoice.value || !buttonLabel.value.trim()) { setText(msgEl,'단추 문구와 작업을 선택하세요.');return null; }
      return {id:id,label:buttonLabel.value.trim(),automation_id:buttonChoice.value};
    }
    if (type === 'embed') {
      /* 주소는 브라우저의 URL 규칙으로 맞춰 보낸다 — 호스트 소문자·국제 도메인 punycode·경로의 비 ASCII 퍼센트 인코딩.
         모양 검사는 서버와 같은 `embedHost`, 목록 검사는 받은 목록으로 한 번 · 최종 판단은 서버가 저장할 때 한다. */
      let raw = embedUrl.value.trim();
      try { const u = new URL(raw); if (u.protocol === 'https:') { raw = u.href; } } catch (e) { /* 모양이 아니면 아래에서 알린다 */ }
      const host = embedHost(raw);
      if (host === null) { setText(msgEl, 'https:// 로 시작하는 주소를 넣으세요(포트·IP 주소는 안 됩니다).'); return null; }
      if (Array.isArray(embedAllowed) && embedAllowed.indexOf(host) === -1) {
        setText(msgEl, host + ' 은(는) 허용 목록에 없습니다. 관리자에게 등록을 요청하세요.');
        return null;
      }
      return { id: id, url: raw, caption: embedCaption.value };
    }
    if (type === 'link_to_page') {
      if (!cur.page_id) { setText(msgEl, '걸 페이지를 먼저 고르세요.'); return null; }
      return { id: id, page_id: cur.page_id, title: cur.title || '' };
    }
    if (!cur.file_ref) { setText(msgEl, (type === 'image' ? '그림' : '파일') + '을 먼저 고르세요.'); return null; }
    const out = { id: id, file_ref: cur.file_ref, caption: fileCaption.value, name: cur.name || '' };
    if (type === 'image') { out.width = widthSelect.value; }
    return out;
  }

  function commit() {
    const view = getView();
    if (!view || !type) { dialog.close(); return; }
    const attrs = collect();
    if (!attrs) { return; }
    const node = schema.nodes[type].create(attrs);
    const current = pos === null ? null : view.state.doc.nodeAt(pos);
    if (current && current.type.name === type) {
      view.dispatch(view.state.tr.setNodeMarkup(pos, null, attrs).scrollIntoView());
    } else {
      const $from = view.state.selection.$from;
      const at = $from.depth >= 1 ? $from.after(1) : view.state.doc.content.size;
      const tr = view.state.tr.insert(at, node);
      const para = schema.nodes.paragraph.create(blockBaseAttrs(null, uuid4()));
      tr.insert(at + node.nodeSize, para);
      tr.setSelection(TextSelection.create(tr.doc, at + node.nodeSize + 1));
      view.dispatch(tr.scrollIntoView());
    }
    dialog.close();
    view.focus();
  }

  commitButton.addEventListener('click', commit);
  for (const close of dialog.querySelectorAll('[data-atom-close]')) {
    close.addEventListener('click', () => dialog.close());
  }
  // 한 줄 칸에서 Enter = 넣기 (여러 줄 칸·단추·검색 칸은 제외)
  dialog.addEventListener('keydown', (event) => {
    const t = event.target;
    // 칸 수 입력(number)에서 Enter 는 창을 확정하지 않는다(독립 검토 6-C L4)
    if (event.key !== 'Enter' || !t || t.tagName !== 'INPUT' || t.type === 'search' || t.type === 'checkbox' || t.type === 'number') { return; }
    event.preventDefault();
    commit();
  });

  return {
    open: open,
    close: () => { if (dialog.open) { dialog.close(); } }
  };
}

/** 편집기에서 덩어리 카드를 두 번 누르거나, 골라 둔 채 Enter → 「블록 고치기」 창 */
function atomAt(state) {
  const sel = state.selection;
  if (sel instanceof NodeSelection && ATOM_TYPES.indexOf(sel.node.type.name) !== -1) {
    return { node: sel.node, pos: sel.from };
  }
  return null;
}

/* ─── 인라인 툴바 — 「색」 「배경」 「링크」 셋 (지시서 1장) + 7-D4 「멘션」 ───── */

/**
 * 7-D2 — 편집기 색 줄에 더할 **살아 있는 팔레트 이름**(P-11). 편집기를 만들 때 한 번 받는다(화면 수명 동안 한 번 — 실패도 기억).
 * 못 받으면(오류·표를 못 읽음) 기본 9색만 — 모양 밖 이름은 버린다. 색은 서버가 만든 CSS(`#palette-css`)가 클래스로 준다(인라인 style 0).
 */
let paletteNames = null;
let palettesLoading = null;
function loadPaletteNames() {
  if (palettesLoading) { return palettesLoading; }
  palettesLoading = api('GET', '/api/palettes').then((result) => {
    const list = result.status === 200 && result.data && Array.isArray(result.data.palettes) ? result.data.palettes : [];
    paletteNames = list.map((p) => (p && typeof p.name === 'string' ? p.name : ''))
      .filter((n) => n !== '' && colorAllowed('pal:' + n));
    return paletteNames;
  }).catch(() => {
    paletteNames = [];
    return paletteNames;
  });
  return palettesLoading;
}

function createToolbar(view, opts) {
  const wrap = document.getElementById('inline-toolbar');
  if (!wrap) { return null; }
  const pageId = opts && typeof opts.pageId === 'string' ? opts.pageId : '';
  const rememberMention = opts && typeof opts.rememberMention === 'function' ? opts.rememberMention : () => {};
  loadPaletteNames();   // 7-D2 — 편집기를 만들 때 한 번(색 줄은 열 때 짓는다 — 그때까지 오면 이름 견본이 함께)
  // 「블록 추가」는 마크업에서 이 줄 안에 있다. 비우기 전에 붙잡아 두었다가 줄 맨 앞에 다시 놓는다
  // (단추의 click 은 initPage 가 걸어 두었으므로 요소를 새로 만들지 않는다).
  const addBlock = document.getElementById('page-add-block');

  clear(wrap);
  let fgPanel = null;
  let bgPanel = null;
  let linkPanel = null;
  let linkInput = null;
  let linkNote = null;
  // 7-D4 멘션 줄 — 종류 · 찾기(사람·페이지) 또는 날짜 · 후보 목록(줄 **안** — 떠 있는 목록이 아니다, 5-D 댓글 @ 후보와 같은 규칙)
  let mentionPanel = null;
  let mentionKind = null;
  let mentionQ = null;
  let mentionDate = null;
  let mentionDateApply = null;
  let mentionList = null;
  let mentionTimer = null;
  let mentionSerial = 0;

  function labelButton(id, text, onClick) {
    const button = document.createElement('button');
    button.type = 'button';
    button.id = id;
    button.className = 'btn btn--small btn--quiet';
    button.textContent = text;   // 글자 단추에는 툴팁을 달지 않는다 (DESIGN 2.8)
    button.addEventListener('mousedown', (event) => { event.preventDefault(); onClick(); });
    return button;
  }

  /** 고른 글자의 색을 바꾼다. `value` 가 null 이면 색을 없앤다. */
  function applyColor(value) {
    const { from, to } = view.state.selection;
    if (from === to) { return; }
    const tr = view.state.tr.removeMark(from, to, schema.marks.color);
    if (value !== null) { tr.addMark(from, to, schema.marks.color.create({ color: value })); }
    view.dispatch(tr);
    closePanels();
    view.focus();
  }

  /** 팔레트 한 줄 — 9색 + 관리자 팔레트(7-D2) + 「없앰」. 열었을 때만 만든다(닫힌 툴바의 단추는 셋뿐이다). */
  function buildPalette(suffix, label) {
    const panel = document.createElement('div');
    panel.className = 'editor-palette__row';
    panel.hidden = true;
    // 어느 판의 팔레트 목록으로 지었나 — 목록이 늦게 오면 다음에 열 때 다시 짓는다(togglePanel)
    panel.setAttribute('data-palettes', paletteNames === null ? 'none' : String(paletteNames.length));

    const title = document.createElement('span');
    title.className = 'muted';
    title.textContent = label;
    panel.appendChild(title);

    for (const color of COLORS) {
      const value = color + suffix;
      const swatch = document.createElement('button');
      swatch.type = 'button';
      swatch.className = 'btn btn--icon btn--plain ' + (suffix === '' ? 'nt-' + color : 'nt-' + color + '-bg');
      swatch.setAttribute('aria-label', label + ' ' + color);
      swatch.setAttribute('data-color', value);
      swatch.textContent = '●';
      swatch.addEventListener('mousedown', (event) => {
        event.preventDefault();
        if (!colorAllowed(value)) { return; }
        applyColor(value);
      });
      panel.appendChild(swatch);
    }

    /* 7-D2 — 기본 9색 뒤에 관리자 팔레트. 견본 클래스 `pal-<이름>`·`pal-<이름>-bg`(서버 CSS 가 색을 준다) · 값 `pal:<이름>[_bg]`.
       뺀 이름은 목록에 없으니 새로 고를 수 없다 — 이미 글에 든 것은 그대로 둔다(P-11). */
    for (const name of paletteNames || []) {
      const value = 'pal:' + name + suffix;
      const swatch = document.createElement('button');
      swatch.type = 'button';
      swatch.className = 'btn btn--icon btn--plain ' + (suffix === '' ? 'pal-' + name : 'pal-' + name + '-bg');
      swatch.setAttribute('aria-label', label + ' ' + name);
      swatch.setAttribute('data-color', value);
      swatch.textContent = '●';
      swatch.addEventListener('mousedown', (event) => {
        event.preventDefault();
        if (!colorAllowed(value)) { return; }
        applyColor(value);
      });
      panel.appendChild(swatch);
    }

    const none = document.createElement('button');
    none.type = 'button';
    none.className = 'btn btn--small btn--quiet';
    none.setAttribute('data-color', 'none');
    none.textContent = '없앰';
    none.addEventListener('mousedown', (event) => { event.preventDefault(); applyColor(null); });
    panel.appendChild(none);

    return panel;
  }

  function applyLink() {
    const href = linkInput.value.trim();
    const { from, to } = view.state.selection;
    if (from === to) { setText(linkNote, '링크를 걸 글자를 먼저 고르세요'); return; }
    if (!schemeOkForEditor(href)) {
      // 화면 위 `#msg` 로 보내지 않는다 — 고친 자리 옆에서 알린다(지시서 1장).
      setText(linkNote, 'http, https, 메일 주소만 넣을 수 있습니다');
      return;
    }
    setText(linkNote, '');
    const tr = view.state.tr.removeMark(from, to, schema.marks.link);
    tr.addMark(from, to, schema.marks.link.create({ href: href }));
    view.dispatch(tr);
    closePanels();
    view.focus();
  }

  function buildLinkRow() {
    const row = document.createElement('div');
    row.className = 'editor-palette__row';
    row.hidden = true;

    linkInput = document.createElement('input');
    linkInput.type = 'text';
    linkInput.id = 'link-input';
    linkInput.setAttribute('placeholder', 'https://…');
    linkInput.setAttribute('maxlength', '2048');
    linkInput.setAttribute('spellcheck', 'false');
    linkInput.setAttribute('aria-label', '링크 주소');

    const apply = document.createElement('button');
    apply.type = 'button';
    apply.id = 'link-apply';
    apply.className = 'btn btn--small';
    apply.textContent = '걸기';

    const drop = document.createElement('button');
    drop.type = 'button';
    drop.id = 'link-drop';
    drop.className = 'btn btn--small btn--quiet';
    drop.textContent = '빼기';

    linkNote = document.createElement('span');
    linkNote.id = 'link-note';
    linkNote.className = 'muted';
    linkNote.setAttribute('role', 'status');

    // ★ 입력칸에서 **Enter = 걸기**, Esc = 닫기 (관문 ③ 「Enter 가 아무 동작도 하지 않는다」)
    linkInput.addEventListener('keydown', (event) => {
      if (event.key === 'Enter') { event.preventDefault(); applyLink(); return; }
      if (event.key === 'Escape') { event.preventDefault(); closePanels(); view.focus(); }
    });
    apply.addEventListener('mousedown', (event) => { event.preventDefault(); applyLink(); });
    drop.addEventListener('mousedown', (event) => {
      event.preventDefault();
      const { from, to } = view.state.selection;
      view.dispatch(view.state.tr.removeMark(from, to, schema.marks.link));
      closePanels();
      view.focus();
    });

    row.appendChild(linkInput);
    row.appendChild(apply);
    row.appendChild(drop);
    row.appendChild(linkNote);
    return row;
  }

  /* ── 7-D4 글 안 멘션 (지시서 M-7) ──
     커서 자리에 글자 「@」 하나 + `mention {kind, id}` 마크를 넣는다(이름은 저장하지 않는다 — 라벨은 편집기 안에서만 보인다).
     후보는 창구가 준 것만: 사람 = `GET /api/mention-candidates?page=&q=`(그 페이지를 볼 수 있는 활성 사용자 — 댓글 @ 와 같은 창구),
     페이지 = `GET /api/search?q=` 의 결과 앞 10(볼 수 있는 것만), 날짜 = 날짜 칸. `@` 키로 여는 길은 없다(3-B 교훈 — 단추만). */
  function mentionItem(text, meta, onPick) {
    const li = document.createElement('li');
    li.className = 'list__item';
    if (!onPick) {
      li.textContent = text;
      return li;
    }
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'block-pick';
    b.setAttribute('data-mention-pick', '');
    const strong = document.createElement('strong');
    strong.textContent = text;
    b.appendChild(strong);
    if (meta) {
      const span = document.createElement('span');
      span.className = 'block-pick__keys';
      span.textContent = meta;
      b.appendChild(span);
    }
    b.addEventListener('mousedown', (event) => { event.preventDefault(); });
    b.addEventListener('click', onPick);
    li.appendChild(b);
    return li;
  }

  function insertMention(kind, id, label) {
    if (!mentionAllowed(kind, id)) { return; }
    const sel = view.state.selection;
    const $to = sel.$to;
    if (!$to.parent.inlineContent || !$to.parent.type.allowsMarkType(schema.marks.mention)) {
      clear(mentionList);
      mentionList.appendChild(mentionItem('글 안(문단·제목·목록)에 커서를 두고 넣으세요', '', null));
      show(mentionList, true);
      return;
    }
    const pos = sel.to;
    const keep = $to.marks().filter((mk) => mk.type !== schema.marks.mention && mk.type !== schema.marks.link);
    const mark = schema.marks.mention.create({ kind: kind, id: id, label: label, key: uuid4() });
    const tr = view.state.tr.insert(pos, schema.text('@', keep.concat([mark])));
    tr.setSelection(TextSelection.create(tr.doc, pos + 1));
    tr.setStoredMarks(keep);
    view.dispatch(tr.scrollIntoView());
    if (kind !== 'date') { rememberMention((kind === 'user' ? 'user:' : 'page:') + String(id), { label: label }); }
    closePanels();
    view.focus();
  }

  function mentionSearch() {
    const serial = ++mentionSerial;
    const kind = mentionKind.value;
    const q = mentionQ.value.trim();
    clear(mentionList);
    show(mentionList, false);
    if (q === '' || kind === 'date') { return; }
    const path = kind === 'user'
      ? '/api/mention-candidates?page=' + encodeURIComponent(pageId) + '&q=' + encodeURIComponent(q)
      : '/api/search?q=' + encodeURIComponent(q);
    api('GET', path).then((result) => {
      if (serial !== mentionSerial || mentionKind.value !== kind) { return; }
      clear(mentionList);
      if (result.status !== 200) {
        mentionList.appendChild(mentionItem(errorMessage(result.data), '', null));
        show(mentionList, true);
        return;
      }
      if (kind === 'user') {
        for (const p of Array.isArray(result.data.candidates) ? result.data.candidates : []) {
          if (!p || !Number.isInteger(p.id) || typeof p.username !== 'string') { continue; }
          const label = personLabel(p.display_name, p.username);
          mentionList.appendChild(mentionItem(label, '', () => insertMention('user', p.id, label)));
        }
      } else {
        const found = Array.isArray(result.data.results) ? result.data.results.slice(0, 10) : [];
        for (const r of found) {
          if (!r || typeof r.id !== 'string') { continue; }
          const title = typeof r.title === 'string' ? r.title : '';
          mentionList.appendChild(mentionItem(title !== '' ? title : '제목 없는 페이지', r.space && r.space.name ? r.space.name : '',
            () => insertMention('page', r.id, title)));
        }
      }
      if (!mentionList.firstChild) {
        mentionList.appendChild(mentionItem(kind === 'user' ? '찾은 사람이 없습니다' : '찾은 페이지가 없습니다', '', null));
      }
      show(mentionList, true);
    }).catch(() => {
      if (serial !== mentionSerial) { return; }
      clear(mentionList);
      mentionList.appendChild(mentionItem(GENERIC_ERROR, '', null));
      show(mentionList, true);
    });
  }

  function drawMentionKind() {
    const isDate = mentionKind.value === 'date';
    show(mentionQ, !isDate);
    show(mentionDate, isDate);
    show(mentionDateApply, isDate);
    mentionQ.setAttribute('placeholder', mentionKind.value === 'user' ? '이름이나 아이디' : '페이지 제목의 낱말');
    mentionQ.value = '';
    mentionSerial += 1;
    clear(mentionList);
    show(mentionList, false);
  }

  function buildMentionRow() {
    const row = document.createElement('div');
    row.className = 'editor-palette__row';
    row.id = 'mention-row';
    row.hidden = true;

    mentionKind = document.createElement('select');
    mentionKind.id = 'mention-kind';
    mentionKind.className = 'input';
    mentionKind.setAttribute('aria-label', '멘션 종류');
    for (const [value, text] of [['user', '사람'], ['page', '페이지'], ['date', '날짜']]) {
      const o = document.createElement('option');
      o.value = value;
      o.textContent = text;
      mentionKind.appendChild(o);
    }

    mentionQ = document.createElement('input');
    mentionQ.type = 'search';
    mentionQ.id = 'mention-q';
    mentionQ.className = 'input';
    mentionQ.setAttribute('maxlength', '64');
    mentionQ.setAttribute('autocomplete', 'off');
    mentionQ.setAttribute('spellcheck', 'false');
    mentionQ.setAttribute('aria-label', '찾을 말');

    mentionDate = document.createElement('input');
    mentionDate.type = 'date';
    mentionDate.id = 'mention-date';
    mentionDate.className = 'input';
    mentionDate.setAttribute('min', '1900-01-01');
    mentionDate.setAttribute('max', '2100-12-31');
    mentionDate.setAttribute('aria-label', '날짜');

    mentionDateApply = document.createElement('button');
    mentionDateApply.type = 'button';
    mentionDateApply.id = 'mention-date-apply';
    mentionDateApply.className = 'btn btn--small';
    mentionDateApply.textContent = '넣기';

    mentionList = document.createElement('ul');
    mentionList.id = 'mention-list';
    mentionList.className = 'list list--scroll';
    mentionList.setAttribute('aria-label', '멘션 후보');
    mentionList.hidden = true;

    const applyDate = () => {
      const v = mentionDate.value;
      if (!mentionAllowed('date', v)) { mentionDate.focus(); return; }
      insertMention('date', v, v);
    };
    mentionKind.addEventListener('change', () => { drawMentionKind(); (mentionKind.value === 'date' ? mentionDate : mentionQ).focus(); });
    mentionQ.addEventListener('input', () => {
      if (mentionTimer !== null) { window.clearTimeout(mentionTimer); }
      mentionTimer = window.setTimeout(mentionSearch, 200);
    });
    // Enter = 첫 후보 · Esc = 닫기 (링크 칸과 같은 손버릇)
    mentionQ.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') { event.preventDefault(); closePanels(); view.focus(); return; }
      if (event.key === 'Enter') {
        event.preventDefault();
        const first = mentionList.querySelector('[data-mention-pick]');
        if (first) { first.click(); }
      }
    });
    mentionDate.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') { event.preventDefault(); closePanels(); view.focus(); return; }
      if (event.key === 'Enter') { event.preventDefault(); applyDate(); }
    });
    mentionDateApply.addEventListener('mousedown', (event) => { event.preventDefault(); applyDate(); });

    row.appendChild(mentionKind);
    row.appendChild(mentionQ);
    row.appendChild(mentionDate);
    row.appendChild(mentionDateApply);
    row.appendChild(mentionList);
    return row;
  }

  function mentionOpen() { return !!(mentionPanel && !mentionPanel.hidden); }

  function closePanels() {
    if (fgPanel) { fgPanel.hidden = true; }
    if (bgPanel) { bgPanel.hidden = true; }
    if (linkPanel) { linkPanel.hidden = true; }
    if (linkNote) { setText(linkNote, ''); }
    if (mentionPanel) {
      mentionPanel.hidden = true;
      mentionSerial += 1;
      if (mentionTimer !== null) { window.clearTimeout(mentionTimer); mentionTimer = null; }
    }
  }

  function anyPanelOpen() {
    return (fgPanel && !fgPanel.hidden) || (bgPanel && !bgPanel.hidden) || (linkPanel && !linkPanel.hidden) || mentionOpen();
  }

  function togglePanel(which) {
    const wasOpen = which === 'fg' ? (fgPanel && !fgPanel.hidden)
      : which === 'bg' ? (bgPanel && !bgPanel.hidden)
        : which === 'mention' ? mentionOpen()
          : (linkPanel && !linkPanel.hidden);
    closePanels();
    if (wasOpen) { return; }
    // 7-D2 — 팔레트 목록이 그 줄을 지은 뒤에 왔으면 새 목록으로 다시 짓는다(늦게 온 이름이 빠지지 않게)
    const stale = (panel) => panel && panel.getAttribute('data-palettes') !== (paletteNames === null ? 'none' : String(paletteNames.length));
    if (which === 'fg') {
      if (stale(fgPanel)) { fgPanel.remove(); fgPanel = null; }
      if (!fgPanel) { fgPanel = buildPalette('', '글자색'); wrap.appendChild(fgPanel); }
      fgPanel.hidden = false;
    } else if (which === 'bg') {
      if (stale(bgPanel)) { bgPanel.remove(); bgPanel = null; }
      if (!bgPanel) { bgPanel = buildPalette('_bg', '배경색'); wrap.appendChild(bgPanel); }
      bgPanel.hidden = false;
    } else if (which === 'mention') {
      if (!mentionPanel) { mentionPanel = buildMentionRow(); wrap.appendChild(mentionPanel); }
      drawMentionKind();
      mentionPanel.hidden = false;
      (mentionKind.value === 'date' ? mentionDate : mentionQ).focus();
    } else {
      if (!linkPanel) { linkPanel = buildLinkRow(); wrap.appendChild(linkPanel); }
      linkPanel.hidden = false;
      linkInput.focus();
      linkInput.select();
    }
  }

  const row = document.createElement('div');
  row.className = 'editor-toolbar__row';
  const tools = [
    labelButton('tool-color', '색', () => togglePanel('fg')),
    labelButton('tool-bg', '배경', () => togglePanel('bg')),
    labelButton('tool-link', '링크', () => togglePanel('link'))
  ];
  // 7-D4 넷째 단추 — 글자를 고르지 않아도 쓸 수 있다(커서 자리에 넣는다)
  const mentionTool = labelButton('tool-mention', '멘션', () => togglePanel('mention'));
  if (addBlock) {
    row.appendChild(addBlock);
    const sep = document.createElement('span');
    sep.className = 'editor-toolbar__sep';
    sep.setAttribute('aria-hidden', 'true');
    row.appendChild(sep);
  }
  for (const tool of tools) { row.appendChild(tool); }
  row.appendChild(mentionTool);
  wrap.appendChild(row);

  /* 자리는 **CSS 가 정한다**(편집 영역 위에 붙는 줄, 팔레트는 그 아래로 뜬다). 좌표를 JS 로
     써 넣지 않는다(DESIGN 0장 2 — 인라인 style 0). 툴바는 편집하는 동안 늘 보이고, 여기서는
     단추를 쓸 수 있나만 정한다 — 보였다 숨었다 하면 본문이 밀렸다(2026-09-23 사용자 지적).
     ★ `view.hasFocus()` 는 보지 않는다 — 링크 입력칸을 누르는 순간 편집기가 초점을 잃어
       툴바가 사라지던 것이 관문 ③ 의 「링크가 안 걸린다」였다. 입력칸에 초점이 가도
       편집기의 선택은 그대로라 여기 판단이 바뀌지 않는다. */
  function refresh() {
    const empty = view.state.selection.empty;
    // 멘션 줄은 커서만 있어도 연다 — 색·배경·링크 줄만 닫는다
    if (empty && anyPanelOpen() && !mentionOpen()) { closePanels(); }
    for (const tool of tools) { tool.disabled = empty; }
  }

  show(wrap, true);
  refresh();

  return {
    refresh: refresh,
    hide: () => { closePanels(); show(wrap, false); }
  };
}

/* ── 페이지 화면 전체 ──────────────────────────────────────── */

function initPage() {
  const meta = document.getElementById('page-meta');
  if (!meta) { return; }
  const pageId = meta.getAttribute('data-page-id') || '';

  const msg = document.getElementById('msg');
  const titleEl = document.getElementById('page-title');
  const crumbEl = document.getElementById('page-crumb');
  const subEl = document.getElementById('page-sub');
  const docEl = document.getElementById('page-doc');
  const editorEl = document.getElementById('page-editor');
  const titleField = document.getElementById('page-title-field');
  const titleInput = document.getElementById('page-title-input');
  const editButton = document.getElementById('page-edit');
  const doneButton = document.getElementById('page-done');
  const addBlockButton = document.getElementById('page-add-block');
  const deleteButton = document.getElementById('page-delete');
  const saveState = document.getElementById('page-save-state');
  const editorsBadge = document.getElementById('page-editors');
  const pageStateLine = document.getElementById('page-state');   // 디버깅 묶음 U H5 — 위 두 배지를 담는 제목 아래 줄
  const childrenWrap = document.getElementById('page-children-wrap');
  const childrenBox = document.getElementById('page-children');
  const childrenCount = document.getElementById('page-children-count');
  const childrenEmpty = document.getElementById('page-children-empty');
  const newChildButton = document.getElementById('page-new-child');
  const newDbButton = document.getElementById('page-new-db');
  // 6-A — DB 표·행 속성 줄. 도우미는 이 파일의 것을 그대로 넘긴다(두 벌로 두지 않는다).
  const dbView = createDatabaseView({
    api: api, setText: setText, show: show, clear: clear, errorMessage: errorMessage,
    base: COMMUNITY_BASE, statusLabels: STATUS_LABELS, genericError: GENERIC_ERROR, toLogin: toLogin,
    tboxBase: APP_BASE, spaceId: () => (state.space ? state.space.id : 0),
    // 디버깅 묶음 U2 · 독립 검토 L-2 — 데이터베이스의 쓰기 단추도 `/api/me` 의 쓰기 자격과 **둘 다** 참일 때만(페이지 머리와 같은 규칙)
    canWrite: () => meCanWrite
  });
  const moveButton = document.getElementById('page-move');
  const pageExport = createPageExport({ base: COMMUNITY_BASE, pageId, beforeOpen: async () => {
    await flushSave();
    if (serial !== savedSerial || pendingConflict) throw new Error('저장을 마친 뒤 다시 내보내 주세요.');
  } });
  // 7-B — 「템플릿으로 저장」. 고를 수 있는 범위는 창구가 준 `template_scopes` 뿐이다.
  const templateSave = createTemplateSave({
    api: api, setText: setText, show: show, clear: clear, errorMessage: errorMessage,
    genericError: GENERIC_ERROR, toLogin: toLogin
  });
  const conflictDialog = document.getElementById('conflict-dialog');
  const visibilityBadgeEl = document.getElementById('page-visibility');
  const visibilityButton = document.getElementById('page-visibility-edit');
  // 4-D — 속성 줄
  const propsRow = document.getElementById('page-props');
  const statusButton = document.getElementById('page-status');
  const statusBadgeEl = document.getElementById('page-status-badge');
  // 5-A — 「소유자」 칸과 「넘기기」, 공유받은 사람의 「공유 보기」
  const ownerNameEl = document.getElementById('page-owner-name');
  // 디버깅 묶음 U3 — 좁은 폭(720 미만) 속성 요약 한 줄과 「속성 더보기」(넓은 화면에서는 CSS 가 요약을 숨기고 속성 줄을 그대로 보인다)
  const propsSummary = document.getElementById('page-props-summary');
  const propsLine = document.getElementById('page-props-line');
  const propsToggle = document.getElementById('page-props-toggle');
  const propsToggleLabel = document.getElementById('page-props-toggle-label');
  let propsOpen = false;
  const handoverButton = document.getElementById('page-handover');
  const shareMineEl = document.getElementById('page-share-mine');
  const shareOpenButton = document.getElementById('page-share-open');
  // 개발 대기열 6 — 「더보기」 맨 위의 즐겨찾기 ☆. 넣었나는 창구의 즐겨찾기 목록(지금 볼 수 있는 것)에 이 페이지가 있나만 본다.
  const favButton = document.getElementById('page-fav');
  const favLabel = document.getElementById('page-fav-label');

  let state = {
    doc: null, version: 0, title: '', canEdit: false, editing: false,
    // 4-A — 창구가 준 「볼 수 있는 하위 페이지」 제목 지도. 렌더러 두 벌이 이것만 본다.
    childTitles: {},
    // 5-E — 페이지 링크 · 붙인 파일의 제목 지도(창구가 **보는 사람 권한으로** 거른 것).
    linkTitles: {}, fileTitles: {}, embeds: { hosts: [], frames: false },
    // 7-D4 — 글 안 멘션의 이름 지도(창구가 **보는 사람 기준으로** 거른 것 — 볼 수 있는 것만)
    mentionLabels: {},
    // 4-B — 지금의 공개 범위와 이 페이지가 든 공간. 둘 다 창구가 준 값이다.
    visibility: 'private', space: null, canSetVisibility: false,
    // 4-D — 상태. 문자열 또는 null.
    status: null, canSetProperties: false,
    // 5-A — 소유자(`{kind, username|name, is_active}`) · 관리 자격 · 공유 · 인계. 전부 창구가 준 값이다.
    owner: null, canManage: false, canShare: false, canHandover: false,
    shareLevel: null, subtreeCount: null,
    // 5-C — 걸린 게시판 · 쓴 사람 · 태그 · 조회수 · 해결됨과 단추 다섯의 값. 전부 창구가 준 값이다.
    board: null, writer: null, tags: [], viewCount: 0, resolved: false,
    canTag: false, canPublish: false, canUnpublish: false, canHide: false, canResolve: false,
    // 5-D — 댓글을 쓸 수 있나 · 공지 「읽은 사람」을 볼 수 있나(창구가 준 값)
    canComment: false, canViewReads: false
  };
  let view = null;
  let toolbar = null;
  let saveTimer = null;
  let heartbeat = null;
  let pendingConflict = null;

  /* ── 저장 경합을 막는 번호 (지시서 4장) ──
     `serial`   변경이 있을 때마다 1 오른다.
     `sentSerial` 지금 날아가 있는 요청이 담고 있는 번호.
     `savedSerial` 서버가 200 으로 받아 준 마지막 번호.
     변경이 남아 있는 동안(`serial !== savedSerial`) 「저장됨」을 보이지 않는다. */
  let serial = 0;
  let sentSerial = -1;
  let savedSerial = 0;
  let saving = false;
  let inFlight = null;

  function badge(text, kind) {
    if (!saveState) { return; }
    saveState.className = 'badge' + (kind ? ' ' + kind : '');
    setText(saveState, text);
    show(saveState, text !== '');
    syncPageState();
  }

  /* 디버깅 묶음 U H5 — 상태 배지 줄(`#page-state`, 제목 아래)은 보이는 배지가 하나라도 있을 때만 보인다 */
  function syncPageState() {
    if (!pageStateLine) { return; }
    show(pageStateLine, Array.prototype.some.call(pageStateLine.children, (el) => !el.hidden));
  }

  /* ── 쓰던 글 임시저장 (Q:F-4 B1-2 설계 3·4·6) ──
     저장이 안 된 변경이 있는 동안만, 그 사본을 이 브라우저에 **암호화해서** 남긴다(`drafts.js`).
       쓰는 때   변경마다(1초 묶음) · 401 을 받은 그 순간(`leaveHooks` — 로그인 화면으로 가기 전) · 창 닫기·탭 숨김
       지우는 때 그 판이 200 으로 저장된 뒤(`serial === savedSerial`) · 「버리기」 · 409 창의 「새로 고침」
     열쇠를 못 받았으면 쓰지 않는다(평문 대체 0) — 그때와 쓰기가 실패했을 때는 배지 「임시저장 못 함」. 저장(서버)은 그대로다. */
  const draftPageKey = drafts ? drafts.pageKey(pageId) : null;
  let draftTimer = null;
  let draftSeq = 0;          // 더 새 쓰기가 시작되면 앞선 쓰기는 암호화가 끝나도 쓰지 않는다
  let draftBadge = null;
  let commentsLoaded = Promise.resolve();

  /** 배지 「임시저장 못 함」(회색 — 꺼짐, DESIGN 2.2). 저장 상태 배지 옆에 한 번만 만든다. */
  function draftOff(on) {
    const want = on !== false;
    if (!want && draftBadge === null) { return; }
    if (draftBadge === null) {
      if (!saveState || !saveState.parentNode) { return; }
      draftBadge = document.createElement('span');
      draftBadge.className = 'badge badge--off';
      draftBadge.setAttribute('data-draft-off', '');
      draftBadge.textContent = '임시저장 못 함';
      draftBadge.setAttribute('title', '쓰던 글을 이 브라우저에 남기지 못했습니다. 서버 저장은 그대로 됩니다');
      saveState.parentNode.insertBefore(draftBadge, saveState.nextSibling);
    }
    show(draftBadge, want);
    syncPageState();
  }

  function titleNow() {
    return titleInput && titleInput.value.trim() !== '' ? titleInput.value.trim() : state.title;
  }

  /** 지금 편집기의 저장 안 된 사본을 쓴다. 쓸 것이 없으면(저장됨·편집 중 아님·열쇠 없음) 아무것도 하지 않는다. */
  function writePageDraft() {
    if (!drafts || draftPageKey === null || !state.editing || view === null || serial === savedSerial || !drafts.hasKey()) {
      return Promise.resolve();
    }
    const mySerial = serial;
    draftSeq += 1;
    const mySeq = draftSeq;
    let payload;
    try {
      payload = { base_version: state.version, title: titleNow(), doc: currentDoc() };
    } catch (e) {
      return Promise.resolve();
    }
    return drafts.write(draftPageKey, payload, () => mySeq === draftSeq && savedSerial < mySerial)
      .then((ok) => { draftOff(!ok); });
  }

  function scheduleDraft() {
    if (!drafts || draftPageKey === null || draftTimer !== null) { return; }
    draftTimer = window.setTimeout(() => { draftTimer = null; writePageDraft(); }, 1000);
  }

  /** 서버에 저장됐다 — 사본을 지우고, 날아가 있는 쓰기도 무르게 한다(번호를 올린다). */
  function clearPageDraft() {
    window.clearTimeout(draftTimer);
    draftTimer = null;
    draftSeq += 1;
    if (drafts && draftPageKey !== null) { drafts.remove(draftPageKey); }
  }

  /** 읽기 렌더러에 넘길 지도 — 하위 페이지(4-A) · 페이지 링크 · 파일(5-E, 셋 다 보는 사람이 볼 수 있는 것만) */
  function readCtx() {
    return {
      child_titles: state.childTitles, link_titles: state.linkTitles, file_titles: state.fileTitles,
      embeds: state.embeds, base: COMMUNITY_BASE, tbox_base: APP_BASE, mention_labels: state.mentionLabels, buttons: state.buttons, pressButton: async (id, button) => {
        button.disabled=true;
        try { const result=await api('POST','/api/automations/'+id+'/run',{page_id:pageId});
          if (result.status === 200) {
            if (result.data.status !== 'ok') { setText(msg, result.data.status === 'partial' ? '일부 작업만 완료했습니다. 자동 작업의 실행 기록을 확인해 주세요.' : '작업을 실행하지 못했습니다. 원본과 현재 권한을 확인해 주세요.'); return; }
            const p = (result.data.created_pages || [])[0]; if (p) location.assign(COMMUNITY_BASE + '/p/' + p.id); else setText(msg, '실행했습니다.');
          }
          else setText(msg,errorMessage(result.data));
        } catch(e) { setText(msg,GENERIC_ERROR); } finally {button.disabled=false;}
      }
    };
  }

  /** 「이 페이지를 참조한 곳」 (5-E, F-23) — 창구가 준 목록(볼 수 있는 페이지만)을 그대로. 비면 절을 숨긴다. */
  const backlinksWrap = document.getElementById('page-backlinks-wrap');
  const backlinksEl = document.getElementById('page-backlinks');
  function drawBacklinks(items) {
    if (!backlinksWrap || !backlinksEl) { return; }
    clear(backlinksEl);
    for (const b of items) {
      if (!b || typeof b.id !== 'string') { continue; }
      const li = document.createElement('li');
      li.className = 'list__item';
      const a = document.createElement('a');
      a.href = COMMUNITY_BASE + '/p/' + encodeURIComponent(b.id);
      a.textContent = (b.title && b.title !== '' ? b.title : '제목 없는 페이지');
      li.appendChild(a);
      backlinksEl.appendChild(li);
    }
    show(backlinksWrap, backlinksEl.firstChild !== null);
  }

  function drawRead() {
    clear(docEl);
    if (state.doc) {
      docEl.appendChild(renderDoc(state.doc, readCtx()));
    }
    armTodos();
  }

  /* ── 읽기 화면의 할 일 상자 (2026-09-23 사용자 지적 「편집 끝 상태에서 체크가 안 됨」) ──
   *
   * 렌더러(`doc-render.js`)는 서버 렌더러와 **같은 모양**이어야 하므로(C:T-19) 건드리지 않고,
   * 그린 뒤에 여기서 상자에 누를 수 있는 성질만 붙인다. 고칠 수 있는 사람(`can_edit`)에게만이다 —
   * 눌러 봐야 서버가 다시 막지만, 못 하는 일을 할 수 있는 것처럼 보이게 하지 않는다.
   * 누르면 문서의 그 할 일만 뒤집어 **보통 저장 창구로 곧바로** 보낸다. 체크 기록은 서버가
   * 이전 문서와 견주어 남긴다(「처리 이력」). 상자와 할 일 블록은 그린 순서로 짝짓는다.
   */
  function todoBlocks(doc) {
    const out = [];
    (function walk(blocks) {
      for (const b of Array.isArray(blocks) ? blocks : []) {
        if (b && b.type === 'to_do') { out.push(b); }
        if (b && Array.isArray(b.children)) { walk(b.children); }
      }
    })(doc && doc.blocks);
    return out;
  }

  function armTodos() {
    docEl.classList.toggle('doc--checkable', state.canEdit);
    if (!state.canEdit) { return; }
    docEl.querySelectorAll('.doc-todo__box').forEach((box, i) => {
      box.removeAttribute('aria-hidden');
      box.setAttribute('role', 'checkbox');
      box.setAttribute('tabindex', '0');
      box.setAttribute('aria-checked', box.parentNode.classList.contains('is-done') ? 'true' : 'false');
      box.setAttribute('aria-label', '할 일 체크');
      box.setAttribute('data-todo-index', String(i));
    });
  }

  let todoBusy = false;
  function toggleTodo(index) {
    if (todoBusy || !state.canEdit || state.editing || !state.doc) { return; }
    const doc = JSON.parse(JSON.stringify(state.doc));
    const target = todoBlocks(doc)[index];
    if (!target) { return; }
    target.checked = target.checked !== true;
    todoBusy = true;
    badge('저장 중…', '');
    api('PATCH', '/api/pages/' + pageId, { doc_version: state.version, doc: doc, title: state.title })
      .then((result) => {
        todoBusy = false;
        if (result.status === 401) { toLogin(); return; }
        if (result.status === 200) {
          state.version = result.data.doc_version;
          state.doc = doc;
          drawRead();
          const again = docEl.querySelector('[data-todo-index="' + String(index) + '"]');
          if (again) { again.focus(); }
          badge('저장됨', 'badge--ok');
          return;
        }
        if (result.status === 409) {
          badge('', '');
          setText(msg, '다른 곳에서 먼저 고쳐져 새로 불러왔습니다. 다시 눌러 주세요.');
          load();
          return;
        }
        badge('저장 못 함', 'badge--admin-only');
        setText(msg, errorMessage(result.data));
      })
      .catch(() => {
        todoBusy = false;
        badge('저장 못 함', 'badge--admin-only');
        setText(msg, GENERIC_ERROR);
      });
  }

  function todoIndexOf(target) {
    const box = target instanceof Element ? target.closest('.doc-todo__box[data-todo-index]') : null;
    return box && docEl.contains(box) ? Number(box.getAttribute('data-todo-index')) : -1;
  }

  docEl.addEventListener('click', (event) => {
    const i = todoIndexOf(event.target);
    if (i >= 0) { event.preventDefault(); toggleTodo(i); }
  });
  docEl.addEventListener('keydown', (event) => {
    if (event.key !== ' ' && event.key !== 'Enter') { return; }
    const i = todoIndexOf(event.target);
    if (i >= 0) { event.preventDefault(); toggleTodo(i); }
  });

  /**
   * 경로 줄 첫 칸 — 이 페이지가 든 **실제 공간**(디버깅 묶음 U3 · 사용자 지적 2026-09-26 「늘 『내 공간 ›』」).
   * 이름은 전부 창구가 준 값이다 — 게시판 글은 `board.name`(게시판을 볼 수 있을 때만 창구가 싣는다) · 팀 공간은 `space.name` ·
   * 남의 개인 공간은 `space.name`(「아이디 의 공간」 — 공유받은 페이지 목록이 이미 보이는 글자) · 내 공간은 「내 공간」.
   * 링크는 **그 사람이 열 수 있는 곳만**: 내 공간 → 커뮤니티 첫 화면 · 팀원 → 그 팀 공간 · 게시판 글 → 그 게시판.
   * 남의 개인 공간·팀원이 아닌 팀 공간(공유·관리자 열람)은 들어갈 화면이 없어 글자만이다. 고르는 값 `granted_by` 는 창구가 한
   * 판정의 결과이고 화면은 모양만 고른다 — 막는 것은 창구다(원칙 1).
   */
  function crumbRoot() {
    const b = state.board;
    const sp = state.space;
    if (b && b.kind === 'post' && typeof b.name === 'string') {
      return { text: b.name, href: COMMUNITY_BASE + '/b/' + String(b.id) };
    }
    if (sp && sp.kind === 'team' && typeof sp.name === 'string') {
      return { text: sp.name, href: state.grantedBy === 'team_member' ? spaceHref(sp.id, 'team') : null };
    }
    if (sp && sp.kind === 'personal' && state.grantedBy !== 'owner' && typeof sp.name === 'string' && sp.name !== '') {
      return { text: sp.name, href: null };
    }
    if (sp && sp.kind !== 'personal') { return { text: '게시판', href: null }; }   // 게시판 이름을 받지 못한 글(창구가 싣지 않음) — 이름을 짓지 않는다
    return { text: '내 공간', href: COMMUNITY_BASE + '/workspace' };
  }

  /** 경로(breadcrumb) — 「공간 › 부모 › 이 페이지」. 마지막 칸만 링크가 아니다. */
  function drawCrumb(breadcrumb) {
    if (!crumbEl) { return; }
    clear(crumbEl);
    const trail = Array.isArray(breadcrumb) ? breadcrumb : [];

    const root = crumbRoot();
    const home = document.createElement(root.href ? 'a' : 'span');
    home.className = 'crumb__item';
    if (root.href) { home.setAttribute('href', root.href); }
    home.textContent = root.text;
    crumbEl.appendChild(home);

    for (let i = 0; i < trail.length; i++) {
      const sep = document.createElement('span');
      sep.className = 'crumb__sep';
      sep.setAttribute('aria-hidden', 'true');
      sep.textContent = '›';
      crumbEl.appendChild(sep);

      const label = (trail[i].icon ? trail[i].icon + ' ' : '') + trail[i].title;
      if (i === trail.length - 1) {
        const here = document.createElement('span');
        here.className = 'crumb__item is-current';
        here.setAttribute('aria-current', 'page');
        here.textContent = label;
        crumbEl.appendChild(here);
        continue;
      }
      const a = document.createElement('a');
      a.className = 'crumb__item';
      a.setAttribute('href', COMMUNITY_BASE + '/p/' + trail[i].id);
      a.textContent = label;
      crumbEl.appendChild(a);
    }
    show(crumbEl, true);
  }

  function drawChildren(children) {
    clear(childrenBox);
    const list = Array.isArray(children) ? children : [];
    for (const child of list) {
      const a = document.createElement('a');
      a.className = 'card card--link';
      a.setAttribute('href', COMMUNITY_BASE + '/p/' + child.id);
      const title = document.createElement('span');
      title.className = 'card__title';
      title.textContent = (child.icon ? child.icon + ' ' : '') + child.title;
      a.appendChild(title);
      if (child.child_count > 0) {
        const meta = document.createElement('span');
        meta.className = 'card__meta';
        meta.textContent = '하위 ' + String(child.child_count);
        a.appendChild(meta);
      }
      childrenBox.appendChild(a);
    }
    setText(childrenCount, list.length === 0 ? '' : String(list.length) + '개');
    show(childrenEmpty, list.length === 0);
    // 하위가 없어도 「하위 페이지 만들기」가 있어야 하므로, 고칠 수 있으면 칸을 연다.
    // 5-C — 게시판 글에는 하위 페이지가 없다(한 줄 목록 — 창구도 400 `space_governed`).
    const flat = !!state.board && state.board.kind === 'post';
    // 6-A — DB 페이지 아래 페이지는 **행**이다(표의 「새 행」으로 만든다) — 하위 만들기 단추 둘을 감춘다.
    const isDb = !!state.database;
    show(childrenWrap, list.length > 0 || (state.canEdit && !flat && !isDb));
    show(newChildButton, state.canEdit && !flat && !isDb);
    show(newDbButton, state.canEdit && !flat && !isDb);
  }

  /* ── 즐겨찾기 ☆ (개발 대기열 6 · 지시서 1.1) — 글자·별 모양·aria-pressed 를 창구가 준 목록에 맞춘다 ── */
  function paintFavorite() {
    if (!favButton) { return; }
    const on = favoriteIds.has(pageId);
    favButton.setAttribute('aria-pressed', on ? 'true' : 'false');
    setText(favLabel, on ? '즐겨찾기에서 빼기' : '즐겨찾기에 넣기');
    const icon = favButton.querySelector('.icon');
    const fresh = makeIcon(on ? 'star-fill' : 'star');
    if (icon) { favButton.replaceChild(fresh, icon); } else { favButton.insertBefore(fresh, favButton.firstChild); }
  }
  favoriteListeners.push(paintFavorite);
  if (favButton) {
    favButton.addEventListener('click', () => {
      const on = favoriteIds.has(pageId);
      favButton.disabled = true;
      setText(msg, '');
      api(on ? 'DELETE' : 'PUT', '/api/pages/' + pageId + '/favorite').then((result) => {
        favButton.disabled = false;
        if (result.status === 401) { toLogin(); return; }
        if (result.status !== 200) { setText(msg, errorMessage(result.data)); return; }
        /* 넣었지만 목록 조건(검색과 같은 조건절) 밖인 페이지 — 관리자 권한으로 연 남의 비공개 페이지처럼(QF3A-T3).
           화면은 창구가 준 참·거짓(`listed`)만 보고 알린다. */
        if (!on && result.data && result.data.listed === false) {
          setText(msg, '즐겨찾기에 넣었지만 이 페이지는 즐겨찾기 목록에 나오지 않습니다 — 검색에도 나오지 않는 페이지(관리자 권한으로 연 다른 사람의 페이지)입니다.');
        }
        loadFavoritesNav();
      }).catch(() => { favButton.disabled = false; setText(msg, GENERIC_ERROR); });
    });
  }

  function load() {
    return api('GET', '/api/pages/' + pageId).then((result) => {
      if (result.status === 401) { toLogin(); return; }
      if (result.status !== 200) {
        setText(msg, errorMessage(result.data));
        show(docEl, false);
        return;
      }
      const page = result.data.page;
      drawTrust(result.data, pageId, api, load);
      drawAutomationPage(result.data, pageId, api, load);
      state.procedureCount = result.data.procedures_in_branch || 0;
      state.doc = page.doc;
      state.version = page.doc_version;
      state.title = page.title;
      // 디버깅 묶음 U H3 — 쓰는 단추는 페이지별 값과 `/api/me` 의 쓰기 자격이 **둘 다** 참일 때만(위 `meCanWrite`)
      const w = meCanWrite;
      state.canEdit = w && result.data.can_edit === true;
      state.childTitles = result.data.child_titles && typeof result.data.child_titles === 'object'
        ? result.data.child_titles
        : {};
      state.linkTitles = result.data.link_titles && typeof result.data.link_titles === 'object' ? result.data.link_titles : {};
      state.fileTitles = result.data.file_titles && typeof result.data.file_titles === 'object' ? result.data.file_titles : {};
      state.buttons = result.data.buttons || {};
      state.mentionLabels = result.data.mention_labels && typeof result.data.mention_labels === 'object' ? result.data.mention_labels : {};
      // 7-C — 임베드 재료(허용 목록 · 틀을 여는가). 판단은 서버가 준 값뿐이다
      state.embeds = result.data.embeds && typeof result.data.embeds === 'object' ? result.data.embeds : { hosts: [], frames: false };
      drawBacklinks(Array.isArray(result.data.backlinks) ? result.data.backlinks : []);
      state.visibility = typeof page.visibility === 'string' ? page.visibility : 'private';
      state.space = result.data.space || null;
      // 디버깅 묶음 U3 — 이 페이지를 **무엇으로** 볼 수 있나(창구의 판정 결과 `granted_by`). 경로 줄 첫 칸의 이름·링크만 고른다(권한 판단 아님)
      state.grantedBy = typeof result.data.granted_by === 'string' ? result.data.granted_by : '';
      state.canSetVisibility = w && result.data.can_set_visibility === true;
      state.status = typeof page.status === 'string' ? page.status : null;
      state.canSetProperties = w && result.data.can_set_properties === true;
      state.owner = page.owner && typeof page.owner === 'object' ? page.owner : null;
      state.canManage = w && result.data.can_manage === true;
      state.canShare = w && result.data.can_share === true;
      state.canHandover = w && result.data.can_handover === true;
      state.shareLevel = typeof result.data.share_level === 'string' ? result.data.share_level : null;
      state.subtreeCount = typeof result.data.subtree_count === 'number' ? result.data.subtree_count : null;
      state.board = result.data.board && typeof result.data.board === 'object' ? result.data.board : null;
      state.writer = result.data.writer && typeof result.data.writer === 'object' ? result.data.writer : null;
      state.tags = Array.isArray(result.data.tags) ? result.data.tags : [];
      state.viewCount = typeof result.data.view_count === 'number' ? result.data.view_count : 0;
      state.resolved = result.data.resolved === true;
      state.canTag = w && result.data.can_tag === true;
      state.canPublish = w && result.data.can_publish === true;
      state.canUnpublish = w && result.data.can_unpublish === true;
      state.canHide = w && result.data.can_hide === true;
      state.canResolve = w && result.data.can_resolve === true;
      state.canComment = w && result.data.can_comment === true;
      state.canViewReads = result.data.can_view_reads === true;
      drawVisibility();
      drawProps();
      drawBoard();
      drawTags();
      commentsLoaded = loadComments();   // B1-2 — 답글 사본을 되살릴 때 대상 댓글이 그려진 뒤에 찾는다
      loadReads();
      setText(titleEl, page.title);
      document.title = page.title + ' · 커뮤니티';
      setText(subEl, page.parent ? '상위: ' + page.parent.title : '');
      drawCrumb(result.data.breadcrumb);
      drawRead();
      state.database = result.data.database || null;
      drawChildren(result.data.children);
      dbView.draw(pageId, result.data);
      templateSave.draw(pageId, result.data);
      pageExport.draw(result.data);
      showEditors(result.data.editors);
      show(editButton, state.canEdit && !state.editing);
      // 디버깅 묶음 U3 — 「이력」은 창구가 이 페이지를 내준(읽기 판정 통과) 뒤에만. 막힌·없는 페이지에서는 숨은 채다(page_view.php 기본 hidden)
      show(document.getElementById('page-history'), true);
      // 5-A — 지우기·옮기기는 **관리**다. 편집 공유자에게는 `can_edit` 이 참이어도 이 둘이 없다.
      show(deleteButton, state.canManage);
      // 5-C — 게시판 글은 트리가 없어 옮길 자리가 없다(창구도 `space_governed`). 지우기는 쓴 사람이 한다.
      show(moveButton, state.canManage && !(state.board && state.board.kind === 'post'));
      // 개발 대기열 6 — 즐겨찾기 ☆ 는 이 페이지를 볼 수 있는 사람이면 누구나(넣기는 창구가 다시 판단한다)
      show(favButton, true);
      paintFavorite();
      show(visibilityButton, state.canSetVisibility);
      // 공유받은 사람 — 받은 권한 배지와 「공유 보기」(자기 공유 빼기). 관리할 수 있는 사람은 「바꾸기」로 연다.
      if (shareMineEl) {
        const label = SHARE_LABELS[state.shareLevel];
        setText(shareMineEl, label ? '받은 권한: ' + label : '');
        show(shareMineEl, !!label && !state.canShare);
      }
      /* 5-A″ — 팀 공간의 페이지에는 공개 범위가 없다(공간 등급을 따른다). 관리할 수 있는 팀원에게는
         같은 창의 **공유 부분만** 여는 「사람 추가」가 보인다. 판단은 창구가 준 `can_share` 뿐이다. */
      const teamShare = state.canShare && !!state.space && state.space.kind === 'team';
      setText(shareOpenLabel, teamShare ? '사람 추가' : '공유 보기');
      show(shareOpenButton, (!!SHARE_LABELS[state.shareLevel] && !state.canShare) || teamShare);
      setText(msg, '');
    });
  }

  /**
   * 머리의 공개 범위 배지 (4-B).
   * 팀 공간의 페이지에는 자기 범위가 없다(공간 등급을 따른다) — 그 자리에는 「팀 공간」을
   * 보인다. 어느 쪽이든 **창구가 준 값**이고 화면이 판단해서 고르지 않는다.
   */
  function drawVisibility() {
    /* 5-C — 게시판 글은 게시판 등급을 따른다(자기 범위가 없다) · 게시된 페이지는 「게시됨」.
       어느 쪽이든 창구가 준 값(`board.access_level`·`visibility`)이고 화면이 판단하지 않는다. */
    if (state.board && state.board.kind === 'post') {
      const level = BOARD_LEVEL_LABELS[state.board.access_level];
      if (visibilityBadgeEl && level) {
        visibilityBadgeEl.className = 'badge ' + level.badge;
        visibilityBadgeEl.setAttribute('title', state.board.access_level);
        setText(visibilityBadgeEl, '게시판 등급 · ' + level.text);
        show(visibilityBadgeEl, true);
      }
      return;
    }
    if (state.visibility === 'published') {
      if (visibilityBadgeEl) {
        visibilityBadgeEl.className = 'badge badge--ok';
        visibilityBadgeEl.setAttribute('title', 'published');
        setText(visibilityBadgeEl, '게시됨');
        show(visibilityBadgeEl, true);
      }
      return;
    }
    if (state.space && state.space.kind === 'team') {
      if (visibilityBadgeEl) {
        visibilityBadgeEl.className = 'badge badge--team';
        visibilityBadgeEl.setAttribute('title', 'team');
        setText(visibilityBadgeEl, '팀 공간');
        show(visibilityBadgeEl, true);
      }
      return;
    }
    paintVisibility(visibilityBadgeEl, state.visibility);
  }

  /* ── 속성 줄 (4-D, C:F-37) ──
     상태 배지와 담당자 이름을 창구가 준 값으로 그린다. 고칠 수 없는 사람에게는 두 단추가
     `disabled` 이고 값은 그대로 보인다 — 「볼 수는 있으나 바꿀 수 없다」가 화면에도 그대로
     드러난다. 되살려 눌러도 `PATCH …/properties` 가 같은 함수로 다시 막는다 (C:T-51). */
  function drawProps() {
    if (!propsRow) { return; }
    paintStatus(statusBadgeEl, state.status);
    // 5-A 「소유자」 — 공간의 주인. 팀 공간은 「팀 공간」이다. 글자는 창구가 준 값 그대로다.
    // 5-C — 게시판 글은 「쓴 사람」이다(공간의 주인이 사람이 아니다).
    setText(document.getElementById('page-owner-label'), state.writer ? '쓴 사람' : '소유자');
    if (ownerNameEl && state.writer) {
      setText(ownerNameEl, personLabel(state.writer.display_name, state.writer.username));
    } else if (ownerNameEl) {
      let text = '—';
      if (state.owner && state.owner.kind === 'personal' && typeof state.owner.username === 'string') {
        text = personLabel(state.owner.display_name, state.owner.username)
          + (state.owner.is_active === false ? ' (쓰지 않는 계정)' : '');
      } else if (state.owner && state.owner.kind === 'team') {
        text = '팀 공간';
      }
      setText(ownerNameEl, text);
    }
    if (statusButton) { statusButton.disabled = !state.canSetProperties; }
    show(handoverButton, state.canHandover);
    show(propsRow, true);
  }

  /* ── 게시판 · 게시 · 숨김 · 해결됨 (5-C) ──
     단추 다섯은 창구가 준 `can_*` 로만 보인다. 누르면 창구를 부르고, 끝나면 **다시 받아** 그린다 —
     화면이 결과를 지어내지 않는다. 되살려 눌러도 창구가 같은 함수로 다시 막는다(DESIGN 원칙 1). */
  const boardGroup = document.getElementById('page-board-group');
  const boardLink = document.getElementById('page-board-link');
  const boardHidden = document.getElementById('page-board-hidden');
  const resolvedBadge = document.getElementById('page-resolved-badge');
  const publishButton = document.getElementById('page-publish');
  const unpublishButton = document.getElementById('page-unpublish');
  const hideButton = document.getElementById('page-hide');
  const resolveButton = document.getElementById('page-resolve');
  const viewsEl = document.getElementById('page-views');

  function drawBoard() {
    const b = state.board;
    show(boardGroup, !!b);
    if (b) {
      if (boardLink) {
        boardLink.setAttribute('href', COMMUNITY_BASE + '/b/' + String(b.id));
        setText(boardLink, b.name);
      }
      show(boardHidden, b.hidden === true);
      const qa = b.board_kind === 'qa';
      if (resolvedBadge) {
        resolvedBadge.className = 'badge' + (state.resolved ? ' badge--ok' : '');
        setText(resolvedBadge, state.resolved ? '해결됨' : '미해결');
      }
      show(resolvedBadge, qa);
      setText(resolveButton, state.resolved ? '해결됨 빼기' : '해결됨으로 표시');
    }
    show(publishButton, state.canPublish);
    show(unpublishButton, state.canUnpublish);
    show(hideButton, state.canHide);
    setText(hideButton, b && b.hidden === true ? '숨김 풀기' : '숨기기');
    show(resolveButton, state.canResolve);
    setText(viewsEl, '조회 ' + String(state.viewCount));
    show(viewsEl, !!b);
  }

  /** 단추 하나 = 창구 한 번. 성공하면 다시 받아 그리고(배지·단추가 바뀌는 것이 곧 알림 — DESIGN 4장),
      실패하면 서버 문구(또는 사유 표)를 `#msg`(오류 줄)에 보인다. */
  function boardAction(button, method, path, payload, okText) {
    if (!button) { return; }
    button.disabled = true;
    api(method, path, payload).then((result) => {
      button.disabled = false;
      if (result.status === 401) { toLogin(); return null; }
      if (result.status === 200) {
        return api('GET', '/api/pages/' + pageId).then((again) => {
          if (again.status === 200) { return load().then(() => setText(msg, okText)); }
          // 해제·숨김 뒤 더는 볼 수 없으면(게시판 등급으로만 보던 관리자) 게시판으로 돌아간다.
          window.location.assign(state.board ? COMMUNITY_BASE + '/b/' + String(state.board.id) : COMMUNITY_BASE + '/workspace');
          return null;
        });
      }
      const reason = result.data && typeof result.data.reason === 'string' ? result.data.reason : '';
      setText(msg, BOARD_REASONS[reason] || errorMessage(result.data));
      return null;
    }).catch(() => { button.disabled = false; setText(msg, GENERIC_ERROR); });
  }

  if (unpublishButton) {
    unpublishButton.addEventListener('click', () => boardAction(unpublishButton, 'DELETE',
      '/api/pages/' + pageId + '/publish', undefined, ''));
  }
  if (hideButton) {
    hideButton.addEventListener('click', () => {
      const hidden = !!state.board && state.board.hidden === true;
      boardAction(hideButton, hidden ? 'DELETE' : 'POST', '/api/pages/' + pageId + '/hide', undefined,
        '');
    });
  }
  if (resolveButton) {
    resolveButton.addEventListener('click', () => boardAction(resolveButton, 'PATCH',
      '/api/pages/' + pageId + '/properties', { resolved: !state.resolved }, ''));
  }

  /* 「게시판에 게시」 창 — 고를 수 있는 게시판은 창구가 `can_write` 로 답한 것만. */
  const publishDialog = document.getElementById('publish-dialog');
  const publishChoices = document.getElementById('publish-choices');
  const publishEmpty = document.getElementById('publish-empty');
  const publishMsg = document.getElementById('publish-msg');
  const publishSave = document.getElementById('publish-save');
  if (publishButton && publishDialog && typeof publishDialog.showModal === 'function') {
    publishButton.addEventListener('click', () => {
      setText(publishMsg, '');
      clear(publishChoices);
      if (publishSave) { publishSave.disabled = true; }
      loadBoards().then((data) => {
        const writable = data && Array.isArray(data.boards) ? data.boards.filter((b) => b.can_write === true) : [];
        for (const b of writable) {
          const label = document.createElement('label');
          label.className = 'field field--full';
          const line = document.createElement('span');
          line.className = 'field__label';
          const radio = document.createElement('input');
          radio.type = 'radio';
          radio.name = 'publish-board';
          radio.value = String(b.id);
          radio.addEventListener('change', () => { if (publishSave) { publishSave.disabled = false; } });
          line.appendChild(radio);
          line.appendChild(document.createTextNode(' ' + b.name + ' '));
          const badge = boardLevelBadge(b.access_level);
          if (badge) { line.appendChild(badge); }
          label.appendChild(line);
          if (b.description) {
            const help = document.createElement('span');
            help.className = 'help';
            help.textContent = b.description;
            label.appendChild(help);
          }
          publishChoices.appendChild(label);
        }
        show(publishEmpty, writable.length === 0);
      });
      publishDialog.showModal();
    });
    for (const close of publishDialog.querySelectorAll('[data-publish-close]')) {
      close.addEventListener('click', () => publishDialog.close());
    }
    if (publishSave) {
      publishSave.addEventListener('click', () => {
        const picked = publishDialog.querySelector('input[name="publish-board"]:checked');
        if (!picked) { return; }
        publishSave.disabled = true;
        api('POST', '/api/pages/' + pageId + '/publish', { board_id: Number(picked.value) }).then((result) => {
          publishSave.disabled = false;
          if (result.status === 401) { toLogin(); return null; }
          if (result.status === 200) {
            publishDialog.close();
            // 성공은 화면이 곧바로 바뀌는 것으로 알린다(배지 「게시됨」 — DESIGN 4장 「성공」). `#msg` 는 오류 줄이다.
            return load();
          }
          const reason = result.data && typeof result.data.reason === 'string' ? result.data.reason : '';
          setText(publishMsg, BOARD_REASONS[reason] || errorMessage(result.data));
          return null;
        }).catch(() => { publishSave.disabled = false; setText(publishMsg, GENERIC_ERROR); });
      });
    }
  }

  /* ── 태그 (5-C) — 칩 + 입력 칸. 보내는 것은 언제나 **새 태그 전부**(`PUT …/tags`) ── */
  const tagsEl = document.getElementById('page-tags');
  const tagInput = document.getElementById('page-tag-input');
  const tagOptions = document.getElementById('page-tag-options');

  function drawTags() {
    clear(tagsEl);
    if (state.tags.length === 0 && !state.canTag) { setText(tagsEl, '—'); }
    for (const t of state.tags) {
      if (state.canTag) {
        const chip = document.createElement('button');
        chip.type = 'button';
        chip.className = 'btn btn--small btn--quiet';
        chip.setAttribute('aria-label', '태그 ' + t + ' 빼기');
        chip.appendChild(document.createTextNode('#' + t + ' '));
        chip.appendChild(makeIcon('x'));
        chip.addEventListener('click', () => putTags(state.tags.filter((x) => x !== t)));
        tagsEl.appendChild(chip);
      } else {
        const badge = document.createElement('span');
        badge.className = 'badge';
        badge.textContent = '#' + t;
        tagsEl.appendChild(badge);
      }
    }
    show(tagInput, state.canTag);
    if (state.canTag && state.board) { fillTagOptions(tagOptions, state.board.id, ''); }
    drawPropsSummary();
  }

  /* ── 좁은 폭 속성 요약 (디버깅 묶음 U3 · DESIGN 2.9 「좁은 폭 글 화면」) ──
     720px 미만에서는 속성 줄(상태·쓴 사람·공개 범위·게시판·태그·조회 — 한 줄에 하나라 여섯 줄)이 본문을 첫 화면 아래로 밀었다.
     그래서 속성 줄을 접고(`data-folded` — CSS 가 좁은 폭에서만 숨긴다) 요약 한 줄 + 「속성 더보기」를 둔다. 요약은 속성 줄이
     **이미 그린 것**을 옮긴다 — 배지는 cloneNode, 이름·수는 textContent(새 값·새 판단 0). 단추·편집 권한은 속성 줄 그대로다
     (펼치면 같은 단추가 같은 자리에 있다). 넓은 화면에서는 요약이 숨고 `data-folded` 는 아무 일도 하지 않는다. */
  function paintPropsFold() {
    if (!propsRow || !propsToggle) { return; }
    propsRow.toggleAttribute('data-folded', !propsOpen);
    propsToggle.setAttribute('aria-expanded', propsOpen ? 'true' : 'false');
    setText(propsToggleLabel, propsOpen ? '속성 접기' : '속성 더보기');
  }

  function drawPropsSummary() {
    if (!propsSummary || !propsLine) { return; }
    clear(propsLine);
    // 배지(상태 — 있을 때만 · 공개 범위)는 속성 줄의 것을 복제하고, 나머지는 글자 한 덩어리(「쓴 사람 … · 게시판 … · 태그 n · 조회 n」)
    for (const el of [state.status ? statusBadgeEl : null, visibilityBadgeEl]) {
      if (!el || el.hidden || el.textContent.trim() === '') { continue; }
      const c = el.cloneNode(true);
      c.removeAttribute('id');
      propsLine.appendChild(c);
    }
    const words = [];
    const who = ownerNameEl ? ownerNameEl.textContent.trim() : '';
    if (who !== '' && who !== '—' && who !== '…') {
      const label = document.getElementById('page-owner-label');
      words.push((label ? label.textContent.trim() + ' ' : '') + who);
    }
    if (state.board && typeof state.board.name === 'string' && state.board.kind !== 'post') { words.push('게시판 ' + state.board.name); }
    if (state.tags.length > 0) { words.push('태그 ' + String(state.tags.length)); }
    if (state.board) { words.push('조회 ' + String(state.viewCount)); }
    if (words.length > 0) {
      const t = document.createElement('span');
      t.textContent = words.join(' · ');
      propsLine.appendChild(t);
    }
    paintPropsFold();
    show(propsSummary, true);
  }
  if (propsToggle) {
    // 초점은 「속성 접기」에 그대로 둔다 — 펼친 줄은 바로 아래라 Tab 한 번이면 닿고, 다시 접기도 쉽다
    propsToggle.addEventListener('click', () => { propsOpen = !propsOpen; paintPropsFold(); });
  }

  function putTags(list) {
    return api('PUT', '/api/pages/' + pageId + '/tags', { tags: list }).then((result) => {
      if (result.status === 401) { toLogin(); return; }
      if (result.status === 200) {
        state.tags = Array.isArray(result.data.page.tags) ? result.data.page.tags : [];
        drawTags();
        setText(msg, '');
        return;
      }
      setText(msg, result.status === 400
        ? '태그는 10개까지, 하나에 30자까지 붙일 수 있습니다(줄바꿈·제어 문자는 안 됩니다).'
        : errorMessage(result.data));
    }).catch(() => setText(msg, GENERIC_ERROR));
  }

  if (tagInput) {
    tagInput.addEventListener('keydown', (event) => {
      if (event.key !== 'Enter' || event.isComposing) { return; }
      event.preventDefault();
      const add = splitTags(tagInput.value);
      if (add.length === 0) { return; }
      tagInput.value = '';
      putTags(state.tags.concat(add));
    });
    tagInput.addEventListener('input', () => {
      if (state.board) { fillTagOptions(tagOptions, state.board.id, tagInput.value.trim()); }
    });
  }

  /* ── 댓글 (5-D, PRD F-21 · F-22) ──
     목록·단추는 창구가 준 값(`can_edit`·`can_hide`·`can_resolve`·`can_comment`)만 따른다. 본문은 평문 —
     `textContent` 로만 넣고 CSS 가 줄바꿈을 보인다(결정 D-1). 누를 때마다 창구를 부르고 **다시 받아** 그린다. */
  const commentsWrap = document.getElementById('page-comments-wrap');
  const commentsList = document.getElementById('page-comments');
  const commentsEmpty = document.getElementById('page-comments-empty');
  const commentsCount = document.getElementById('page-comments-count');
  const commentsMsg = document.getElementById('comments-msg');
  const commentForm = document.getElementById('comment-form');
  const commentInput = document.getElementById('comment-input');
  const commentQuoteEl = document.getElementById('comment-quote');
  const commentReplyEl = document.getElementById('comment-reply-to');
  const commentQuotePick = document.getElementById('comment-quote-pick');
  const commentCancel = document.getElementById('comment-cancel');
  const commentSubmit = document.getElementById('comment-submit');
  const mentionList = document.getElementById('comment-mentions');
  const readsEl = document.getElementById('page-reads');
  let replyTo = null;      // {id, who}
  let anchor = null;       // {block_id, quote}
  let mentionTimer = null;

  /* B1-2 — 댓글·답글 쓰기 칸의 임시저장(W-4). 쓰기 칸에는 자동 저장이 없어, 로그인이 끊긴 채 「댓글 달기」를 누르면
     쓴 글 전체가 사라지던 자리다. 열쇠는 `…:comment:<페이지>:<답글 대상 uuid 또는 new>` — 지금 쓰기 칸의 대상이 곧 열쇠이고,
     대상이 바뀌면(「답글」·「그만두기」) 사본도 새 열쇠로 옮긴다. 열쇠는 처음 입력할 때 받는다(한 화면에 한 번). */
  let commentKeyNow = null;
  let commentDraftTimer = null;
  let commentSeq = 0;

  function composerKey() {
    return drafts ? drafts.commentKey(pageId, replyTo ? replyTo.id : 'new') : null;
  }

  function writeCommentDraft() {
    if (!drafts || !commentInput || !drafts.hasKey()) { return Promise.resolve(); }
    window.clearTimeout(commentDraftTimer);
    commentDraftTimer = null;
    const k = composerKey();
    if (k === null) { return Promise.resolve(); }
    if (commentKeyNow !== null && commentKeyNow !== k) { drafts.remove(commentKeyNow); }
    commentKeyNow = k;
    commentSeq += 1;
    const mySeq = commentSeq;
    const text = commentInput.value;
    if (text.trim() === '') { drafts.remove(k); return Promise.resolve(); }
    return drafts.write(k, { body: text }, () => mySeq === commentSeq).then((ok) => { draftOff(!ok); });
  }

  function scheduleCommentDraft() {
    if (commentDraftTimer !== null) { return; }
    commentDraftTimer = window.setTimeout(() => { commentDraftTimer = null; writeCommentDraft(); }, 1000);
  }

  /** 보내졌다 — 그 사본을 지우고 날아가 있는 쓰기를 무른다 */
  function clearCommentDraft(k) {
    window.clearTimeout(commentDraftTimer);
    commentDraftTimer = null;
    commentSeq += 1;
    if (drafts && k) { drafts.remove(k); }
    if (drafts && commentKeyNow && commentKeyNow !== k) { drafts.remove(commentKeyNow); }
    commentKeyNow = null;
  }

  function commentButton(text, onClick) {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'btn btn--small btn--quiet';
    b.textContent = text;
    b.addEventListener('click', onClick);
    return b;
  }

  function commentCall(method, path, payload) {
    setText(commentsMsg, '');
    return api(method, path, payload).then((result) => {
      if (result.status === 401) { toLogin(); return false; }
      if (result.status === 200 || result.status === 201) { loadComments(); return true; }
      const reason = result.data && typeof result.data.reason === 'string' ? result.data.reason : '';
      setText(commentsMsg, reason === 'reply_depth' ? '답글에는 답글을 달 수 없습니다.'
        : (BOARD_REASONS[reason] || errorMessage(result.data)));
      return false;
    }).catch(() => { setText(commentsMsg, GENERIC_ERROR); return false; });
  }

  /**
   * 댓글 본문의 주소를 링크로 (디버깅 묶음 U3 · 시연 2026-09-26 「댓글 속 주소가 링크가 아니다」).
   * 본문은 평문 그대로다(결정 D-1) — **글자 노드와 `<a>` 를 하나씩 만들어** 붙인다(문자열을 HTML 로 바꾸는 자리 0). 링크가 되는 것은
   * `http://`·`https://` 로 시작하는 조각뿐이고(`javascript:`·`data:` 등 다른 스킴은 정규식에 걸리지 않아 글자로 남는다),
   * 걸린 조각도 `new URL()` 로 다시 읽어 스킴이 http·https 인 것만 쓴다(doc-render 의 링크 규칙과 같은 두 스킴).
   * 끝의 문장 부호(. , ) 」 등)는 주소에서 떼고, 한글이 붙으면 거기서 끊는다(「…/p/uuid에서」 — 복사한 주소는 한글이 %xx 로 바뀌어 있다).
   * 사이트 안 주소(`location.origin` 과 같은 곳)는 같은 창, 밖 주소는 새 창 — 둘 다 `rel="noopener noreferrer"`. 아이디·비밀번호가 든 주소는 글자로.
   */
  const COMMENT_URL_SPLIT = /(https?:\/\/[^\s<>"'「」『』\u3131-\u318e\uac00-\ud7a3]+)/;
  const COMMENT_URL_TAIL = /[.,;:!?)\]}'"。、…]+$/;
  function linkifyInto(el, text) {
    const parts = String(text).split(COMMENT_URL_SPLIT);
    for (let i = 0; i < parts.length; i++) {
      const piece = parts[i];
      if (piece === '') { continue; }
      if (i % 2 === 0) { el.appendChild(document.createTextNode(piece)); continue; }
      let raw = piece;
      let tail = '';
      const cut = raw.replace(COMMENT_URL_TAIL, '');
      if (cut !== raw) { tail = raw.slice(cut.length); raw = cut; }
      let url = null;
      try { url = new URL(raw); } catch (e) { url = null; }
      // 아이디·비밀번호가 든 주소(`https://좋은곳@다른곳`)는 보이는 글자와 가는 곳이 달라 보일 수 있어 글자로 둔다
      if (!url || (url.protocol !== 'https:' && url.protocol !== 'http:') || url.hostname === '' || url.username !== '' || url.password !== '') {
        el.appendChild(document.createTextNode(piece));
        continue;
      }
      // 클래스 없는 글 속 링크 — 터치 넓힘(app.css 끝 `a[class]`)이 윗줄·아랫줄 글자를 덮지 않게(DESIGN 4장 「글 속 링크는 규칙 밖」). 모양은 community.css
      const a = document.createElement('a');
      a.setAttribute('href', url.href);
      a.setAttribute('rel', 'noopener noreferrer');
      if (url.origin !== window.location.origin) { a.setAttribute('target', '_blank'); }
      a.textContent = raw;
      el.appendChild(a);
      if (tail !== '') { el.appendChild(document.createTextNode(tail)); }
    }
  }

  function commentItem(c) {
    const li = document.createElement('li');
    li.className = 'list__item' + (c.parent_id ? ' is-reply' : '');
    li.id = 'comment-' + c.id;
    const head = document.createElement('div');
    head.className = 'list__head';
    const who = document.createElement('strong');
    who.textContent = c.author ? personLabel(c.author.display_name, c.author.username) : '—';
    head.appendChild(who);
    const when = document.createElement('span');
    when.className = 'muted';
    when.textContent = shortWhen(c.created_at) + (c.edited ? ' · 고침' : '');
    head.appendChild(when);
    if (c.resolved) {
      const r = document.createElement('span');
      r.className = 'badge badge--ok';
      r.textContent = '해결됨';
      head.appendChild(r);
    }
    if (c.hidden) {
      const h = document.createElement('span');
      h.className = 'badge badge--off';
      h.textContent = '숨김';
      head.appendChild(h);
    }
    li.appendChild(head);
    if (c.quote) {
      const q = document.createElement('p');
      q.className = 'help';
      q.textContent = '「' + c.quote + '」';
      if (c.block_id) {
        const go = document.createElement('a');
        go.setAttribute('href', '#block-' + c.block_id);
        go.textContent = ' 본문에서 보기';
        q.appendChild(go);
      }
      li.appendChild(q);
    }
    const body = document.createElement('div');
    body.className = 'list__body';
    if (c.deleted) { body.textContent = '지운 댓글입니다.'; body.classList.add('muted'); }
    else if (c.body === null) { body.textContent = '숨긴 댓글입니다.'; body.classList.add('muted'); }
    else { linkifyInto(body, c.body); }
    li.appendChild(body);

    if (!c.deleted) {
      const actions = document.createElement('div');
      actions.className = 'toolbar';
      if (state.canComment && !c.parent_id) {
        actions.appendChild(commentButton('답글', () => {
          replyTo = { id: c.id, who: who.textContent };
          drawComposer();
          if (commentInput) { commentInput.focus(); }
        }));
      }
      if (!c.hidden && c.body !== null) {
        const help = commentButton((c.helped ? '도움됐어요 취소' : '도움됐어요') + ' ' + String(c.helpful), () => {
          commentCall(c.helped ? 'DELETE' : 'POST', '/api/comments/' + c.id + '/helpful');
        });
        help.disabled = !state.canComment;
        actions.appendChild(help);
      }
      if (c.can_resolve) {
        actions.appendChild(commentButton(c.resolved ? '해결됨 빼기' : '해결됨', () => {
          commentCall(c.resolved ? 'DELETE' : 'POST', '/api/comments/' + c.id + '/resolve');
        }));
      }
      if (c.can_edit) {
        // 고치기 — 그 자리에서 입력 칸으로 바뀐다(모달을 띄우지 않는다). 「저장」 을 누르면 창구로.
        actions.appendChild(commentButton('고치기', () => {
          const box = document.createElement('div');
          box.className = 'field field--full';
          const area = document.createElement('textarea');
          area.rows = 3;
          area.maxLength = 2000;
          area.value = c.body || '';
          area.setAttribute('aria-label', '댓글 고치기');
          box.appendChild(area);
          const row = document.createElement('div');
          row.className = 'toolbar';
          row.appendChild(commentButton('저장', () => {
            if (area.value.trim() !== '') { commentCall('PATCH', '/api/comments/' + c.id, { body: area.value }); }
          }));
          row.appendChild(commentButton('그만두기', () => loadComments()));
          box.appendChild(row);
          body.replaceWith(box);
          actions.hidden = true;
          area.focus();
        }));
        // 지우기 — 되돌릴 수 없는 동작이라 한 번 더 누르게 한다(DESIGN 4장 「위험 동작 → 확인」).
        const del = commentButton('지우기', () => {
          if (del.dataset.armed === '1') { commentCall('DELETE', '/api/comments/' + c.id); return; }
          del.dataset.armed = '1';
          del.textContent = '정말 지우기';
        });
        del.classList.add('btn--danger');
        actions.appendChild(del);
      }
      if (c.can_hide) {
        actions.appendChild(commentButton(c.hidden ? '숨김 풀기' : '숨기기', () => {
          commentCall(c.hidden ? 'DELETE' : 'POST', '/api/comments/' + c.id + '/hide');
        }));
      }
      if (actions.firstChild) { li.appendChild(actions); }
    }
    return li;
  }

  function loadComments() {
    return api('GET', '/api/pages/' + pageId + '/comments').then((result) => {
      if (result.status !== 200) { show(commentsWrap, false); return; }
      const all = Array.isArray(result.data.comments) ? result.data.comments : [];
      clear(commentsList);
      // 첫 댓글 → 그 아래 답글 순으로(창구는 시각 순으로 준다)
      const replies = new Map();
      for (const c of all) {
        if (c.parent_id) {
          if (!replies.has(c.parent_id)) { replies.set(c.parent_id, []); }
          replies.get(c.parent_id).push(c);
        }
      }
      for (const c of all.filter((x) => !x.parent_id)) {
        commentsList.appendChild(commentItem(c));
        for (const r of replies.get(c.id) || []) { commentsList.appendChild(commentItem(r)); }
      }
      setText(commentsCount, String(result.data.count || 0) + '개');
      show(commentsEmpty, all.length === 0);
      state.canComment = meCanWrite && result.data.can_comment === true;
      show(commentForm, state.canComment);
      show(commentsWrap, true);
      if (window.location.hash.indexOf('#comment-') === 0) {
        const target = document.getElementById(window.location.hash.slice(1));
        if (target) { target.scrollIntoView({ block: 'center' }); }
      }
    }).catch(() => {});
  }

  function drawComposer() {
    setText(commentReplyEl, replyTo ? replyTo.who + ' 님 댓글에 답글' : '');
    show(commentReplyEl, !!replyTo);
    setText(commentQuoteEl, anchor ? '인용: 「' + anchor.quote + '」' : '');
    show(commentQuoteEl, !!anchor);
    show(commentCancel, !!replyTo || !!anchor);
    // B1-2 — 답글 대상이 바뀌었으면 임시저장 사본도 그 열쇠로 옮긴다
    if (drafts && drafts.hasKey() && commentKeyNow !== null && commentKeyNow !== composerKey()) { writeCommentDraft(); }
  }

  /* 블록 앵커 — 읽기 화면에서 고른 글자와 그 글자가 든 블록(렌더러가 붙인 `id="block-<uuid>"`) */
  if (commentQuotePick) {
    commentQuotePick.addEventListener('click', () => {
      const sel = window.getSelection ? window.getSelection() : null;
      const text = sel ? String(sel).replace(/\s+/g, ' ').trim() : '';
      const node = sel && sel.anchorNode ? (sel.anchorNode.nodeType === 1 ? sel.anchorNode : sel.anchorNode.parentElement) : null;
      const block = node && docEl.contains(node) ? node.closest('[id^="block-"]') : null;
      if (text === '' || !block) {
        setText(commentsMsg, '먼저 본문(읽기 화면)에서 인용할 글자를 끌어서 고르세요.');
        return;
      }
      // 글자(코드 포인트) 단위로 300자 · 제어 문자는 빈칸으로 — 서버 규칙(`community_clean_plain`)과 같게
      const clean = Array.from(text.replace(/[\u0000-\u001f\u007f]/g, ' ')).slice(0, 300).join('').trim();
      anchor = { block_id: block.id.slice('block-'.length), quote: clean };
      setText(commentsMsg, '');
      drawComposer();
    });
  }
  if (commentCancel) {
    commentCancel.addEventListener('click', () => { replyTo = null; anchor = null; drawComposer(); });
  }

  /* `@` 멘션 후보 — 입력 칸 **아래 칸 안에서** 펼친다(떠 있는 목록이 아니다). 후보는 창구가
     「그 페이지를 볼 수 있는 사람」만 준다(11-1 ⑥). 눌러서 넣는다. */
  function mentionQuery() {
    if (!commentInput) { return ''; }
    const before = commentInput.value.slice(0, commentInput.selectionStart || 0);
    const m = before.match(/(^|[^a-z0-9_.@-])@([a-z0-9_.-]{1,64})$/);
    return m ? m[2] : '';
  }
  function drawMentions() {
    const q = mentionQuery();
    if (q === '') { clear(mentionList); show(mentionList, false); return; }
    api('GET', '/api/mention-candidates?page=' + encodeURIComponent(pageId) + '&q=' + encodeURIComponent(q))
      .then((result) => {
        clear(mentionList);
        const people = result.status === 200 && Array.isArray(result.data.candidates) ? result.data.candidates : [];
        for (const p of people) {
          const li = document.createElement('li');
          li.className = 'list__item';
          const b = commentButton(personLabel(p.display_name, p.username), () => {
            const pos = commentInput.selectionStart || 0;
            const before = commentInput.value.slice(0, pos).replace(/@[a-z0-9_.-]{1,64}$/, '@' + p.username + ' ');
            commentInput.value = before + commentInput.value.slice(pos);
            commentInput.focus();
            commentInput.setSelectionRange(before.length, before.length);
            clear(mentionList);
            show(mentionList, false);
          });
          b.setAttribute('data-mention', p.username);
          li.appendChild(b);
          mentionList.appendChild(li);
        }
        show(mentionList, people.length > 0);
      }).catch(() => {});
  }
  if (commentInput) {
    commentInput.addEventListener('input', () => {
      if (mentionTimer !== null) { clearTimeout(mentionTimer); }
      mentionTimer = setTimeout(drawMentions, 200);
      // B1-2 — 처음 입력할 때 열쇠를 받고(한 번), 그 뒤 1초 묶음으로 사본을 쓴다. 못 받으면 쓰지 않는다(평문 대체 0).
      if (drafts) {
        if (!drafts.available()) { draftOff(true); return; }
        drafts.ready().then((ok) => { if (ok) { scheduleCommentDraft(); } else { draftOff(true); } });
      }
    });
  }
  if (commentForm) {
    commentForm.addEventListener('submit', (event) => {
      event.preventDefault();
      const text = commentInput ? commentInput.value : '';
      if (text.trim() === '') { setText(commentsMsg, '댓글을 적어 주세요.'); return; }
      const payload = { body: text };
      if (replyTo) { payload.parent_id = replyTo.id; }
      if (anchor) { payload.block_id = anchor.block_id; payload.quote = anchor.quote; }
      commentSubmit.disabled = true;
      const sentKey = composerKey();
      // 401 이면 commentCall → toLogin 이 떠나기 전에 이 칸의 사본을 암호화해 남긴다(leaveHooks). 되살릴 때 자동 전송 0.
      commentCall('POST', '/api/pages/' + pageId + '/comments', payload).then((ok) => {
        commentSubmit.disabled = false;
        if (ok) {
          clearCommentDraft(sentKey);   // B1-2 설계 4 — 보내졌다
          commentInput.value = '';
          replyTo = null;
          anchor = null;
          drawComposer();
          show(mentionList, false);
        }
      });
    });
  }

  /* 공지 「읽은 사람 n/N」 — 쓴 사람·Admin 에게만(창구 `can_view_reads`). 수만 보인다(결정 D-8). */
  function loadReads() {
    if (!readsEl) { return; }
    if (!state.canViewReads) { show(readsEl, false); return; }
    api('GET', '/api/pages/' + pageId + '/reads').then((result) => {
      if (result.status !== 200) { show(readsEl, false); return; }
      setText(readsEl, '읽은 사람 ' + String(result.data.reads.read) + ' / ' + String(result.data.reads.total));
      show(readsEl, true);
    }).catch(() => {});
  }

  /* ── 「상태」 창 (4-D) ──
     라디오 여섯(없음 + 다섯)이다. 「없음」의 값은 빈 문자열이고, 창구에는 `null` 로 보낸다 —
     「값이 없다」와 「빈 글자」는 다른 것이고, DB 도 NULL 과 '' 를 다르게 본다. */
  const statusDialog = document.getElementById('status-dialog');
  const statusMsg = document.getElementById('status-msg');
  const statusSave = document.getElementById('status-save');

  function statusRadios() {
    return statusDialog ? statusDialog.querySelectorAll('input[name="page-status"]') : [];
  }

  function openStatus() {
    if (!statusDialog || typeof statusDialog.showModal !== 'function') { return; }
    setText(statusMsg, '');
    for (const radio of statusRadios()) {
      radio.checked = radio.value === (state.status === null ? '' : state.status);
    }
    if (!statusDialog.open) { statusDialog.showModal(); }
  }

  function pickedStatusValue() {
    for (const radio of statusRadios()) {
      if (radio.checked) { return radio.value === '' ? null : radio.value; }
    }
    return null;
  }

  /** 속성 창구 한 번. 성공하면 화면을 **창구가 준 값으로** 다시 그린다. */
  function patchProperties(payload, msgEl, onDone) {
    return api('PATCH', '/api/pages/' + pageId + '/properties', payload)
      .then((result) => {
        if (result.status === 401) { toLogin(); return null; }
        if (result.status === 200) { return load().then(() => onDone(result.data)); }
        const reason = result.data && typeof result.data.reason === 'string' ? result.data.reason : '';
        setText(msgEl, VISIBILITY_REASONS[reason] || errorMessage(result.data));
        return null;
      });
  }

  if (statusButton && statusDialog) {
    statusButton.addEventListener('click', openStatus);
    for (const close of statusDialog.querySelectorAll('[data-status-close]')) {
      close.addEventListener('click', () => statusDialog.close());
    }
    if (statusSave) {
      statusSave.addEventListener('click', () => {
        statusSave.disabled = true;
        patchProperties({ status: pickedStatusValue() }, statusMsg, () => { statusDialog.close(); })
          .then(() => { statusSave.disabled = false; })
          .catch(() => { statusSave.disabled = false; setText(statusMsg, GENERIC_ERROR); });
      });
    }
  }

  /* ── 「공개 범위」 창 (4-B) ──
     라디오 셋(비공개·팀·사내) + 「하위 페이지까지 함께」 체크다. 「게시」는 없다 —
     게시판 공간이 아직 없기 때문이고, 보내도 창구가 400 으로 막는다.
     거부 사유는 **사람 문장**으로 바꿔 보인다(표 `VISIBILITY_REASONS`). */
  const visibilityDialog = document.getElementById('visibility-dialog');
  const visibilityMsg = document.getElementById('visibility-msg');
  const visibilitySave = document.getElementById('visibility-save');
  const cascadeBox = document.getElementById('visibility-cascade');

  function visibilityRadios() {
    return visibilityDialog
      ? visibilityDialog.querySelectorAll('input[name="page-visibility"]')
      : [];
  }

  /* ── 「사람 추가」 (5-A) — 공개 범위 창 아래쪽 ──
     후보는 `GET /api/share-candidates?page=&q=`, 목록은 `GET /api/pages/{id}/shares` 가 준 것만
     그린다. 추가·등급 바꾸기·빼기는 **누르는 즉시** 창구로 가고, 끝나면 목록을 **다시 받는다** —
     화면이 줄을 지어내지 않는다. 보기 공유자·팀·사내로 보는 사람에게 창구는 **수만** 준다. */
  const choicesBox = document.getElementById('visibility-choices');
  const shareMsg = document.getElementById('share-msg');
  const shareAdd = document.getElementById('share-add');
  const shareSearch = document.getElementById('share-search');
  const shareLevelPick = document.getElementById('share-level');
  const shareCandidates = document.getElementById('share-candidates');
  const shareCount = document.getElementById('share-count');
  const shareList = document.getElementById('share-list');
  const shareLeave = document.getElementById('share-leave');
  const shareTitle = document.getElementById('share-title');
  const shareHint = document.getElementById('share-hint');
  const visibilityTitle = document.getElementById('visibility-title');
  const shareOpenLabel = document.getElementById('page-share-open-label');
  let shareTimer = null;
  let shareMyLevel = null;
  // 「내 공유 빼기」가 보낼 계정명 — **창구가 알려 준다**(`me`). 화면이 내가 누구인지 따로 알지 않는다.
  let shareMe = '';

  function sharePath(username) {
    return '/api/pages/' + pageId + '/shares' + (username ? '/' + encodeURIComponent(username) : '');
  }

  function shareFail(result) {
    const reason = result.data && typeof result.data.reason === 'string' ? result.data.reason : '';
    setText(shareMsg, VISIBILITY_REASONS[reason] || errorMessage(result.data));
  }

  /** 공유한 사람 한 줄 — 이름·역할 · 등급 고르개(관리자만) · 「빼기」(관리자만). 물려받은 줄은 읽기만. */
  function shareRow(share, canManage) {
    const li = document.createElement('li');
    li.className = 'list__item';
    li.setAttribute('data-share-user', share.username);
    const head = document.createElement('div');
    head.className = 'list__head';
    const who = document.createElement('strong');
    who.textContent = personLabel(share.display_name, share.username) + ' · ' + roleText(share.role)
      + (share.is_active === false ? ' (쓰지 않는 계정)' : '');
    head.appendChild(who);
    const level = shareBadge(share.level);
    if (level) { head.appendChild(level); }
    li.appendChild(head);

    const body = document.createElement('div');
    body.className = 'list__body';
    if (share.inherited === true) {
      const from = document.createElement('span');
      from.className = 'muted';
      from.textContent = '상위 「' + String(share.from_title || '') + '」 에서 물려받음';
      body.appendChild(from);
    } else if (canManage) {
      const pick = document.createElement('select');
      pick.setAttribute('aria-label', personLabel(share.display_name, share.username) + ' 의 권한');
      pick.setAttribute('data-share-level', share.username);
      for (const value of ['view', 'edit']) {
        const option = document.createElement('option');
        option.value = value;
        option.textContent = SHARE_LABELS[value];
        option.selected = value === share.level;
        pick.appendChild(option);
      }
      pick.addEventListener('change', () => {
        putShare(share.username, pick.value).catch(() => setText(shareMsg, GENERIC_ERROR));
      });
      const remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'btn btn--quiet btn--small btn--danger';
      remove.setAttribute('data-share-remove', share.username);
      remove.textContent = '빼기';
      remove.addEventListener('click', () => {
        remove.disabled = true;
        removeShare(share.username).catch(() => { remove.disabled = false; setText(shareMsg, GENERIC_ERROR); });
      });
      body.appendChild(pick);
      body.appendChild(remove);
    }
    li.appendChild(body);
    return li;
  }

  function loadShares() {
    return api('GET', sharePath(null)).then((result) => {
      if (result.status === 401) { toLogin(); return; }
      if (result.status !== 200) { shareFail(result); return; }
      const canManage = result.data.can_manage === true;
      shareMyLevel = typeof result.data.my_level === 'string' ? result.data.my_level : null;
      shareMe = typeof result.data.me === 'string' ? result.data.me : '';
      clear(shareList);
      const shares = Array.isArray(result.data.shares) ? result.data.shares : null;
      if (shares !== null) {
        for (const share of shares) { shareList.appendChild(shareRow(share, canManage)); }
      }
      const n = typeof result.data.count === 'number' ? result.data.count : 0;
      setText(shareCount, n === 0 ? '아직 공유한 사람이 없습니다.' : '공유받은 사람 ' + String(n) + '명');
      show(shareAdd, canManage);
      // 5-A″ — 「아이디나 이름을 입력하세요」는 찾을 수 있는 사람(관리 자격)에게만, 아직 안 쳤을 때만.
      show(shareHint, canManage && (!shareSearch || shareSearch.value.trim() === ''));
      setText(shareTitle, canManage ? '사람 추가' : '공유');
      show(shareLeave, shareMyLevel !== null && shareMe !== '');
    });
  }

  function putShare(username, level) {
    setText(shareMsg, '');
    return api('PUT', sharePath(username), { level: level }).then((result) => {
      if (result.status === 401) { toLogin(); return null; }
      if (result.status !== 200) { shareFail(result); return null; }
      return loadShares();
    });
  }

  function removeShare(username) {
    setText(shareMsg, '');
    return api('DELETE', sharePath(username), {}).then((result) => {
      if (result.status === 401) { toLogin(); return null; }
      if (result.status !== 200) { shareFail(result); return null; }
      return loadShares();
    });
  }

  function candidateItem(person, onPick, label) {
    const li = document.createElement('li');
    li.className = 'list__item';
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'btn btn--quiet btn--small';
    button.setAttribute('data-candidate', person.username);
    button.textContent = personLabel(person.display_name, person.username) + ' · ' + roleText(person.role) + (label ? ' — ' + label : '');
    button.addEventListener('click', () => onPick(person, button));
    li.appendChild(button);
    return li;
  }

  function loadShareCandidates() {
    const q = shareSearch ? shareSearch.value.trim() : '';
    // 5-A″ — 한 글자도 없으면 찾지 않는다(창구도 빈 목록을 준다). 대신 무엇을 칠지 알려 준다.
    show(shareHint, q === '');
    if (q === '') { clear(shareCandidates); show(shareCandidates, false); setText(shareMsg, ''); return Promise.resolve(); }
    return api('GET', '/api/share-candidates?page=' + encodeURIComponent(pageId) + '&q=' + encodeURIComponent(q))
      .then((result) => {
        if (result.status === 401) { toLogin(); return; }
        if (result.status !== 200) { shareFail(result); return; }
        const people = Array.isArray(result.data.candidates) ? result.data.candidates : [];
        clear(shareCandidates);
        for (const person of people) {
          shareCandidates.appendChild(candidateItem(person, (who, button) => {
            button.disabled = true;
            putShare(who.username, shareLevelPick ? shareLevelPick.value : 'view')
              .then(() => {
                if (shareSearch) { shareSearch.value = ''; }
                clear(shareCandidates);
                show(shareCandidates, false);
              })
              .catch(() => { button.disabled = false; setText(shareMsg, GENERIC_ERROR); });
          }, '추가'));
        }
        show(shareCandidates, people.length > 0);
        if (people.length === 0) { setText(shareMsg, '그런 아이디나 이름이 없습니다'); } else { setText(shareMsg, ''); }
      });
  }

  if (shareSearch) {
    shareSearch.addEventListener('input', () => {
      window.clearTimeout(shareTimer);
      shareTimer = window.setTimeout(() => {
        loadShareCandidates().catch(() => setText(shareMsg, GENERIC_ERROR));
      }, 300);
    });
  }
  if (shareLeave) {
    shareLeave.addEventListener('click', () => {
      if (shareMe === '') { return; }
      shareLeave.disabled = true;
      api('DELETE', sharePath(shareMe), {})
        .then((result) => {
          shareLeave.disabled = false;
          if (result.status === 401) { toLogin(); return null; }
          if (result.status !== 200) { shareFail(result); return null; }
          // 뺀 그 순간부터 서버가 다시 판단한다 — 이 페이지를 계속 볼 수 있는지는 창구에 묻는다.
          visibilityDialog.close();
          return api('GET', '/api/pages/' + pageId).then((again) => {
            if (again.status === 200) { return load(); }
            window.location.assign(COMMUNITY_BASE + '/workspace');
            return null;
          });
        })
        .catch(() => { shareLeave.disabled = false; setText(shareMsg, GENERIC_ERROR); });
    });
  }

  /**
   * 공개 범위 창을 연다. `mode` 가 `share-only` 면(공유받은 사람) 범위 고르기를 숨기고 공유 부분만 보인다.
   */
  function openVisibility(mode) {
    if (!visibilityDialog || typeof visibilityDialog.showModal !== 'function') { return; }
    const shareOnly = mode === 'share-only';
    setText(visibilityMsg, '');
    setText(shareMsg, '');
    if (cascadeBox) { cascadeBox.checked = false; }
    for (const radio of visibilityRadios()) {
      radio.checked = radio.value === state.visibility;
    }
    show(choicesBox, !shareOnly);
    show(visibilitySave, !shareOnly);
    // 5-A″ — 공유 부분만 여는 창(공유받은 사람 · 팀 공간)은 제목도 「공유」다. 팀 공간에는 공개 범위가 없다.
    setText(visibilityTitle, shareOnly ? '공유' : '공개 범위');
    show(shareHint, true);
    if (shareSearch) { shareSearch.value = ''; }
    clear(shareCandidates);
    show(shareCandidates, false);
    if (!visibilityDialog.open) { visibilityDialog.showModal(); }
    loadShares().catch(() => setText(shareMsg, GENERIC_ERROR));
  }

  function pickedVisibility() {
    for (const radio of visibilityRadios()) {
      if (radio.checked) { return radio.value; }
    }
    return null;
  }

  if (visibilityButton && visibilityDialog) {
    visibilityButton.addEventListener('click', () => openVisibility('full'));
    if (shareOpenButton) { shareOpenButton.addEventListener('click', () => openVisibility('share-only')); }
    for (const close of visibilityDialog.querySelectorAll('[data-visibility-close]')) {
      close.addEventListener('click', () => visibilityDialog.close());
    }
    if (visibilitySave) {
      visibilitySave.addEventListener('click', () => {
        const want = pickedVisibility();
        if (want === null) { setText(visibilityMsg, '공개 범위를 고르세요'); return; }
        visibilitySave.disabled = true;
        api('PATCH', '/api/pages/' + pageId + '/visibility', {
          visibility: want,
          cascade: cascadeBox ? cascadeBox.checked === true : false
        })
          .then((result) => {
            visibilitySave.disabled = false;
            if (result.status === 401) { toLogin(); return; }
            if (result.status === 200) {
              visibilityDialog.close();
              // 배지·하위 목록이 함께 바뀐다 — 서버가 준 값으로 다시 그린다.
              return load();
            }
            const reason = result.data && typeof result.data.reason === 'string' ? result.data.reason : '';
            setText(visibilityMsg, VISIBILITY_REASONS[reason] || errorMessage(result.data));
          })
          .catch(() => { visibilitySave.disabled = false; setText(visibilityMsg, GENERIC_ERROR); });
      });
    }
  }

  /* ── 「소유자 넘기기」 창 (5-A 인계) ──
     받는 사람은 `GET /api/share-candidates?for=handover` 가 준 사람 중에서 고르고(손님·쓰지 않는
     계정은 창구가 빼고 준다), 「기존 소유자에게 남길 권한」 라디오 셋에는 **기본 선택이 없다**
     (사용자 결정 2). 둘 다 고르기 전에는 「넘기기」가 `disabled` 다 — 그래도 서버가 `keep` 없는
     요청을 400 으로 다시 막는다. 관리자가 남의 페이지를 넘길 때 재인증이 필요하면 창구가
     `next:"reauth"` 로 답하고, 화면은 그렇게 말할 뿐 스스로 판단하지 않는다. */
  const handoverDialog = document.getElementById('handover-dialog');
  const handoverLead = document.getElementById('handover-lead');
  const handoverMsg = document.getElementById('handover-msg');
  const handoverSearch = document.getElementById('handover-search');
  const handoverList = document.getElementById('handover-candidates');
  const handoverPicked = document.getElementById('handover-picked');
  const handoverConfirm = document.getElementById('handover-confirm');
  let handoverTo = '';
  let handoverToLabel = '';
  let handoverTimer = null;
  const handoverHint = document.getElementById('handover-hint');

  function handoverRadios() {
    return handoverDialog ? handoverDialog.querySelectorAll('input[name="handover-keep"]') : [];
  }

  function handoverKeep() {
    for (const radio of handoverRadios()) {
      if (radio.checked) { return radio.value; }
    }
    return null;
  }

  function handoverArm() {
    if (handoverConfirm) { handoverConfirm.disabled = handoverTo === '' || handoverKeep() === null; }
  }

  function loadHandoverCandidates() {
    const q = handoverSearch ? handoverSearch.value.trim() : '';
    // 5-A″ — 한 글자부터 찾는다. 비었으면 목록을 비우고 무엇을 칠지 알려 준다(창구도 빈 목록을 준다).
    show(handoverHint, q === '');
    if (q === '') { clear(handoverList); setText(handoverMsg, ''); return Promise.resolve(); }
    return api('GET', '/api/share-candidates?for=handover&page=' + encodeURIComponent(pageId)
      + '&q=' + encodeURIComponent(q))
      .then((result) => {
        if (result.status === 401) { toLogin(); return; }
        if (result.status !== 200) { setText(handoverMsg, errorMessage(result.data)); return; }
        const people = Array.isArray(result.data.candidates) ? result.data.candidates : [];
        clear(handoverList);
        for (const person of people) {
          handoverList.appendChild(candidateItem(person, (who) => {
            handoverTo = who.username;
            handoverToLabel = personLabel(who.display_name, who.username);
            setText(handoverPicked, '받는 사람: ' + handoverToLabel + ' · ' + roleText(who.role));
            for (const b of handoverList.querySelectorAll('[data-candidate]')) {
              b.setAttribute('aria-pressed', b.getAttribute('data-candidate') === who.username ? 'true' : 'false');
            }
            handoverArm();
          }, ''));
        }
        if (people.length === 0) { setText(handoverMsg, '그런 아이디나 이름으로 넘겨받을 수 있는 사람이 없습니다'); } else { setText(handoverMsg, ''); }
      });
  }

  function openHandover() {
    if (!handoverDialog || typeof handoverDialog.showModal !== 'function') { return; }
    handoverTo = '';
    handoverToLabel = '';
    clear(handoverList);
    show(handoverHint, true);
    setText(handoverMsg, '');
    setText(handoverPicked, '받는 사람을 아직 고르지 않았습니다.');
    // ★ 기본값을 두지 않는다 — 열 때마다 라디오를 모두 비운다(사용자 결정 2).
    for (const radio of handoverRadios()) { radio.checked = false; }
    if (handoverSearch) { handoverSearch.value = ''; }
    const n = typeof state.subtreeCount === 'number' ? state.subtreeCount : 0;
    setText(handoverLead, n > 0
      ? '이 페이지와 하위 페이지 ' + String(n) + '개(그중 절차서 ' + String(state.procedureCount || 0) + '건)가 받는 사람의 공간 맨 위로 옮겨 갑니다.'
      : '이 페이지가 받는 사람의 공간 맨 위로 옮겨 갑니다.');
    handoverArm();
    if (!handoverDialog.open) { handoverDialog.showModal(); }
  }

  if (handoverButton && handoverDialog) {
    handoverButton.addEventListener('click', openHandover);
    for (const close of handoverDialog.querySelectorAll('[data-handover-close]')) {
      close.addEventListener('click', () => handoverDialog.close());
    }
    for (const radio of handoverRadios()) { radio.addEventListener('change', handoverArm); }
    if (handoverSearch) {
      handoverSearch.addEventListener('input', () => {
        window.clearTimeout(handoverTimer);
        handoverTimer = window.setTimeout(() => {
          loadHandoverCandidates().catch(() => setText(handoverMsg, GENERIC_ERROR));
        }, 300);
      });
    }
    if (handoverConfirm) {
      handoverConfirm.addEventListener('click', () => {
        const keep = handoverKeep();
        if (handoverTo === '' || keep === null) { handoverArm(); return; }
        handoverConfirm.disabled = true;
        api('POST', '/api/pages/' + pageId + '/handover', { to: handoverTo, keep: keep })
          .then((result) => {
            if (result.status === 401) { toLogin(); return null; }
            if (result.status === 200) {
              handoverDialog.close();
              // 넘긴 뒤에도 볼 수 있는지는 서버가 다시 판단한다 — 못 보면 내 공간으로 돌아간다.
              return api('GET', '/api/pages/' + pageId).then((again) => {
                if (again.status === 200) {
                  return load().then(() => setText(msg, '넘겼습니다. 새 소유자: '
                    + personLabel(result.data.to_display_name, result.data.to)));
                }
                window.location.assign(COMMUNITY_BASE + '/workspace');
                return null;
              });
            }
            handoverArm();
            if (result.data && result.data.next === 'reauth') {
              setText(handoverMsg, '관리자 권한으로 넘기려면 비밀번호를 다시 확인해야 합니다. t-box 관리 화면에서 확인한 뒤 다시 누르세요.');
              return null;
            }
            const reason = result.data && typeof result.data.reason === 'string' ? result.data.reason : '';
            setText(handoverMsg, VISIBILITY_REASONS[reason] || errorMessage(result.data));
            return null;
          })
          .catch(() => { handoverArm(); setText(handoverMsg, GENERIC_ERROR); });
      });
    }
  }

  /* ── 「이력」 창 (4-C, C:F-19 · C:F-20) ──
     탭 둘은 단추 둘이다 — 고른 쪽이 검정(`.btn`), 나머지가 흐림(`.btn--quiet`).
     · 「버전」  판마다 한 줄. 「보기」는 이 창 안에서 그 판을 **읽기 렌더러로** 그리고,
                「되돌리기」는 같은 창 안에서 한 번 더 묻는다(모달 안에 모달을 열지 않는다).
     · 「처리 이력」 판·체크를 시간순 한 줄씩. **본문 글자는 여기에 없다** — 창구가 주지
                않기 때문이고(C:T-44), 화면도 지어내지 않는다.
     되돌리기 단추는 창구가 준 `can_edit` 을 따른다. 되살려 눌러도 서버가 다시 막는다. */
  const historyDialog = document.getElementById('history-dialog');
  const historyMeta = document.getElementById('history-meta');
  const historyMsg = document.getElementById('history-msg');
  const historyTabVersions = document.getElementById('history-tab-versions');
  const historyTabEvents = document.getElementById('history-tab-events');
  const historyVersions = document.getElementById('history-versions');
  const historyVersionsBody = document.getElementById('history-versions-body');
  const historyVersionsEmpty = document.getElementById('history-versions-empty');
  const historyEvents = document.getElementById('history-events');
  const historyEventsList = document.getElementById('history-events-list');
  const historyEventsEmpty = document.getElementById('history-events-empty');
  const historyPreview = document.getElementById('history-preview');
  const historyPreviewTitle = document.getElementById('history-preview-title');
  const historyPreviewDoc = document.getElementById('history-preview-doc');
  const historyPreviewBack = document.getElementById('history-preview-back');
  const historyConfirm = document.getElementById('history-confirm');
  const historyConfirmText = document.getElementById('history-confirm-text');
  const historyConfirmOk = document.getElementById('history-confirm-ok');
  const historyConfirmCancel = document.getElementById('history-confirm-cancel');
  const historyButton = document.getElementById('page-history');

  let historyCanEdit = false;      // 창구가 준 값. 화면이 정하지 않는다
  let historyPending = null;       // 되돌리기 확인을 기다리는 판 번호

  /** 탭 하나를 고른다. 단추 두 개의 모양과 `aria-selected`, 그리고 보이는 칸이 함께 바뀐다. */
  function historyTab(which) {
    const onVersions = which === 'versions';
    if (historyTabVersions) {
      historyTabVersions.className = onVersions ? 'btn' : 'btn btn--quiet';
      historyTabVersions.setAttribute('aria-selected', onVersions ? 'true' : 'false');
    }
    if (historyTabEvents) {
      historyTabEvents.className = onVersions ? 'btn btn--quiet' : 'btn';
      historyTabEvents.setAttribute('aria-selected', onVersions ? 'false' : 'true');
    }
    historyClosePreview();
    show(historyVersions, onVersions);
    show(historyEvents, !onVersions);
  }

  /** 미리보기를 닫고 목록으로 돌아온다. */
  function historyClosePreview() {
    show(historyPreview, false);
    clear(historyPreviewDoc);
    setText(historyPreviewTitle, '');
  }

  function historyHideConfirm() {
    historyPending = null;
    show(historyConfirm, false);
    setText(historyConfirmText, '');
  }

  /** 판 한 줄. 마지막 칸이 「보기」·「되돌리기」다 (DESIGN 2.4 `td.actions`). */
  function historyRow(entry) {
    const tr = document.createElement('tr');

    const num = document.createElement('td');
    num.textContent = entry.is_current
      ? String(entry.version) + ' (지금)'
      : String(entry.version);
    tr.appendChild(num);

    const title = document.createElement('td');
    title.textContent = entry.title;
    tr.appendChild(title);

    const who = document.createElement('td');
    who.textContent = entry.by === '' ? '—' : personLabel(entry.by_display_name, entry.by);
    tr.appendChild(who);

    const when = document.createElement('td');
    setWhenParts(when, entry.at);   // 디버깅 묶음 U M2
    when.setAttribute('title', String(entry.at));
    tr.appendChild(when);

    const blocks = document.createElement('td');
    blocks.textContent = String(entry.block_count);
    tr.appendChild(blocks);

    const actions = document.createElement('td');
    actions.className = 'actions';

    const view = document.createElement('button');
    view.type = 'button';
    view.className = 'btn btn--small btn--quiet';
    view.textContent = '보기';
    view.addEventListener('click', () => historyShow(entry.version, entry.is_current === true));
    actions.appendChild(view);

    if (historyCanEdit && entry.is_current !== true) {
      const back = document.createElement('button');
      back.type = 'button';
      back.className = 'btn btn--small btn--quiet btn--danger';
      back.textContent = '되돌리기';
      back.addEventListener('click', () => historyAskRestore(entry.version));
      actions.appendChild(back);
    }
    tr.appendChild(actions);

    // 되돌리기로 만들어진 판이면 그 사실을 줄 아래에 한 줄로 적는다(본문 글자가 아니다).
    if (typeof entry.restored_from === 'number') {
      const note = document.createElement('span');
      note.className = 'muted';
      note.textContent = ' · 판 ' + String(entry.restored_from) + ' 에서 되돌림';
      num.appendChild(note);
    }
    return tr;
  }

  function historyLoadVersions() {
    return api('GET', '/api/pages/' + pageId + '/versions').then((result) => {
      if (result.status === 401) { toLogin(); return; }
      if (result.status !== 200) { setText(historyMsg, errorMessage(result.data)); return; }
      setText(historyMsg, '');
      historyCanEdit = meCanWrite && result.data.can_edit === true;   // 디버깅 묶음 U H3
      const list = Array.isArray(result.data.versions) ? result.data.versions : [];
      clear(historyVersionsBody);
      for (const entry of list) { historyVersionsBody.appendChild(historyRow(entry)); }
      // 지금 판 한 줄만 있으면 「지난 판이 없다」는 뜻이다.
      show(historyVersionsEmpty, list.length <= 1);
      setText(historyMeta, '판 ' + String(result.data.page.doc_version) + ' · 기록 ' + String(list.length) + '개');
    });
  }

  /** 줄 하나 — 「무엇을 · 누가 · 언제」. 본문 글자는 창구가 주지 않는다 (C:T-44). */
  function historyEventItem(event) {
    const li = document.createElement('li');
    li.className = 'list__item';

    const head = document.createElement('div');
    head.className = 'list__head';
    const what = document.createElement('strong');
    if (event.kind === 'property' && event.prop === 'publish') {
      // 5-C — 「게시: 자유게시판」·「게시 해제: 자유게시판」(값은 게시판 이름 — 창구가 준 글자 그대로)
      what.textContent = event.to ? '게시: ' + String(event.to) : '게시 해제: ' + String(event.from || '');
    } else if (event.kind === 'property' && event.prop === 'hide') {
      what.textContent = event.to === 'hidden' ? '게시판에서 숨김' : '숨김 풀림';
    } else if (event.kind === 'property' && event.prop === 'resolved') {
      what.textContent = event.to === 'resolved' ? '해결됨으로 표시' : '해결됨 표시를 뺌';
    } else if (event.kind === 'property') {
      // 4-D — 「상태: 할 일 → 진행」·「담당자: 없음 → qa_employee01」(담당자는 5-A 전의 옛 기록)
      what.textContent = ({assignee:'담당자',status:'상태',verified:'검증',kind:'문서 종류',due:'기한'}[event.prop] || event.prop) + ': '
        + propValueText(event.prop, event.from) + ' → ' + propValueText(event.prop, event.to);
    } else if (event.kind === 'handover') {
      // 5-A — 「소유자: qa_employee01 → qa_employee02」
      what.textContent = '소유자: ' + personLabel(event.from_display_name, event.from)
        + ' → ' + personLabel(event.to_display_name, event.to);
    } else if (event.kind === 'check') {
      what.textContent = event.checked === true ? '할 일을 체크했습니다' : '체크를 풀었습니다';
    } else if (typeof event.restored_from === 'number') {
      what.textContent = '판 ' + String(event.version) + ' — 판 ' + String(event.restored_from) + ' 에서 되돌림';
    } else {
      what.textContent = '판 ' + String(event.version) + ' 저장';
    }
    const when = document.createElement('span');
    when.className = 'muted';
    when.textContent = shortWhen(event.at);
    when.setAttribute('title', String(event.at));
    head.appendChild(what);
    head.appendChild(when);
    li.appendChild(head);

    const body = document.createElement('div');
    body.className = 'list__body';
    const who = document.createElement('span');
    who.textContent = (event.by === '' ? '—' : personLabel(event.by_display_name, event.by)) + ' 님';
    body.appendChild(who);
    if (event.kind === 'version') {
      const blocks = document.createElement('span');
      blocks.textContent = '블록 ' + String(event.block_count) + '개';
      body.appendChild(blocks);
      if (event.is_current === true) {
        const now = document.createElement('span');
        now.textContent = '지금 판';
        body.appendChild(now);
      }
    } else if (event.kind === 'handover') {
      const count = document.createElement('span');
      count.textContent = String(event.page_count) + '쪽';
      body.appendChild(count);
      const kept = document.createElement('span');
      kept.textContent = '옛 소유자에게 남긴 권한: ' + (SHARE_LABELS[event.kept] || '없음');
      body.appendChild(kept);
    } else if (event.kind === 'check') {
      // 어느 칸인가는 블록 id 앞 8자로 가리킨다 — 할 일에 적힌 글자는 싣지 않는다.
      const which = document.createElement('span');
      which.textContent = '칸 ' + String(event.block_id).slice(0, 8);
      body.appendChild(which);
    }
    li.appendChild(body);
    return li;
  }

  function historyLoadEvents() {
    return api('GET', '/api/pages/' + pageId + '/history').then((result) => {
      if (result.status === 401) { toLogin(); return; }
      if (result.status !== 200) { setText(historyMsg, errorMessage(result.data)); return; }
      setText(historyMsg, '');
      const list = Array.isArray(result.data.events) ? result.data.events : [];
      clear(historyEventsList);
      for (const event of list) { historyEventsList.appendChild(historyEventItem(event)); }
      show(historyEventsEmpty, list.length === 0);
    });
  }

  /** 그 판을 **이 창 안에서** 읽기 렌더러로 그린다. 본문은 이 창구로만 들어온다. */
  function historyShow(version, isCurrent) {
    historyHideConfirm();
    return api('GET', '/api/pages/' + pageId + '/versions/' + String(version))
      .then((result) => {
        if (result.status === 401) { toLogin(); return; }
        if (result.status !== 200) { setText(historyMsg, errorMessage(result.data)); return; }
        setText(historyMsg, '');
        const got = result.data.version;
        setText(historyPreviewTitle, '판 ' + String(got.version) + (isCurrent ? ' (지금)' : '') + ' · ' + got.title);
        clear(historyPreviewDoc);
        /* 하위 페이지 카드의 제목 지도는 **지금 문서의 것**이다 — 지난 판이 가리키던 페이지가
           그 뒤에 지워졌다면 지도에 없고, 렌더러가 「볼 수 없는 페이지」로 그린다.
           7-D4 — 글 안 멘션의 이름은 **그 판** 응답의 지도(`mention_labels` — 볼 수 있는 것만)로 그린다. */
        const verCtx = readCtx();
        verCtx.mention_labels = result.data.mention_labels && typeof result.data.mention_labels === 'object' ? result.data.mention_labels : {};
        historyPreviewDoc.appendChild(renderDoc(got.doc, verCtx));
        show(historyVersions, false);
        show(historyEvents, false);
        show(historyPreview, true);
      })
      .catch(() => setText(historyMsg, GENERIC_ERROR));
  }

  function historyAskRestore(version) {
    historyPending = version;
    setText(historyConfirmText, '판 ' + String(version) + ' 의 내용으로 되돌릴까요?');
    show(historyConfirm, true);
  }

  function historyDoRestore() {
    if (historyPending === null) { return; }
    const version = historyPending;
    historyConfirmOk.disabled = true;
    api('POST', '/api/pages/' + pageId + '/versions/' + String(version) + '/restore', {})
      .then((result) => {
        historyConfirmOk.disabled = false;
        if (result.status === 401) { toLogin(); return; }
        if (result.status !== 200) { setText(historyMsg, errorMessage(result.data)); return; }
        historyHideConfirm();
        historyClosePreview();
        /* 본문·판 번호가 바뀌었다 — 창구가 준 값으로 화면과 목록을 다시 그린다.
           알림은 **다시 그린 뒤에** 놓는다: 목록을 새로 받는 일이 알림 칸을 비우기 때문이다
           (그 전에 적어 두면 사람이 읽기도 전에 사라진다 — 브라우저 스모크 30번 실측). */
        return load()
          .then(() => historyLoadVersions())
          .then(() => historyLoadEvents())
          .then(() => setText(historyMsg, '판 ' + String(version) + ' 의 내용으로 되돌렸습니다'));
      })
      .catch(() => { historyConfirmOk.disabled = false; setText(historyMsg, GENERIC_ERROR); });
  }

  function openHistory() {
    if (!historyDialog || typeof historyDialog.showModal !== 'function') { return; }
    setText(historyMsg, '');
    historyHideConfirm();
    historyTab('versions');
    if (!historyDialog.open) { historyDialog.showModal(); }
    historyLoadVersions()
      .then(() => historyLoadEvents())
      .catch(() => setText(historyMsg, GENERIC_ERROR));
  }

  if (historyButton && historyDialog) {
    historyButton.addEventListener('click', openHistory);
    for (const close of historyDialog.querySelectorAll('[data-history-close]')) {
      close.addEventListener('click', () => historyDialog.close());
    }
    if (historyTabVersions) { historyTabVersions.addEventListener('click', () => historyTab('versions')); }
    if (historyTabEvents) { historyTabEvents.addEventListener('click', () => historyTab('events')); }
    if (historyPreviewBack) {
      historyPreviewBack.addEventListener('click', () => {
        historyClosePreview();
        show(historyVersions, historyTabVersions.getAttribute('aria-selected') === 'true');
        show(historyEvents, historyTabEvents.getAttribute('aria-selected') === 'true');
      });
    }
    if (historyConfirmCancel) { historyConfirmCancel.addEventListener('click', historyHideConfirm); }
    if (historyConfirmOk) { historyConfirmOk.addEventListener('click', historyDoRestore); }
  }

  function showEditors(editors) {
    const list = Array.isArray(editors) ? editors : [];
    if (list.length === 0) { show(editorsBadge, false); syncPageState(); return; }
    const names = list.map((e) => personLabel(e.display_name, e.username)).join(', ');
    setText(editorsBadge, names + ' 님도 보는 중');
    show(editorsBadge, true);
    syncPageState();
  }

  /**
   * 이 페이지 **아래**에 빈 페이지를 하나 만든다 (4-A).
   * 「블록 추가」 창의 「하위 페이지」와 「하위 페이지 만들기」 단추가 같이 쓴다.
   * 만들어진 제목은 곧 카드에 보여야 하므로 제목 지도에도 함께 넣는다.
   */
  function createChildPage() {
    return api('POST', '/api/pages', { parent_id: pageId }).then((result) => {
      if (result.status === 401) { toLogin(); return null; }
      if (result.status !== 201 || !result.data.page || typeof result.data.page.id !== 'string') {
        setText(msg, errorMessage(result.data));
        return null;
      }
      const made = { id: result.data.page.id, title: result.data.page.title || '제목 없는 페이지' };
      state.childTitles[made.id] = { title: made.title, icon: null };
      return made;
    });
  }

  /* 5-E — 고른 페이지·파일의 이름을 제목 지도에 넣어 두면 저장 뒤 읽기 화면이 곧바로 이름을 그린다.
     지도는 다음 `load()` 에서 서버 것으로 바뀐다(서버가 **보는 사람 권한으로** 다시 거른다). */
  const atomDialog = createAtomDialog(() => view, {
    selfId: () => pageId,
    rememberLink: (id, entry) => { state.linkTitles[id] = entry; },
    rememberFile: (id, entry) => { state.fileTitles[id] = entry; }
  });
  function editAtom(type, attrs, pos) { if (atomDialog) { atomDialog.open(type, attrs, pos); } }
  function commandAtomEdit(pmState) {
    const hit = atomAt(pmState);
    if (!hit) { return false; }
    editAtom(hit.node.type.name, hit.node.attrs, hit.pos);
    return true;
  }

  const blockDialog = createBlockDialog(() => view, { createChildPage: createChildPage, editAtom: editAtom });

  /* ── 편집 모드 ── */

  /* ── 빈 페이지 안내 · 기본 구성 (2026-09-23) ── */

  const starterEl = document.getElementById('editor-starter');
  const starterKeys = document.getElementById('editor-starter-keys');
  const starterApply = document.getElementById('editor-starter-apply');

  // 안내에 보일 블록 — 처음 쓰는 사람이 알아야 할 것만. 단축키 글자는 창과 같은 곳(BLOCK_TYPES)에서 온다.
  const STARTER_PICKS = [['paragraph'], ['heading', 1], ['heading', 2], ['bulleted'], ['numbered'], ['to_do'], ['toc']];
  if (starterKeys) {
    for (const [type, level] of STARTER_PICKS) {
      const entry = BLOCK_TYPES.find((e) => e.type === type && (level === undefined || (e.attrs && e.attrs.level === level)));
      if (!entry) { continue; }
      const li = document.createElement('li');
      const name = document.createElement('strong');
      name.textContent = entry.label;
      const keys = document.createElement('span');
      keys.className = 'block-pick__keys';
      keys.textContent = mdLabel(entry.md) || '「블록 추가」에서';
      li.appendChild(name);
      li.appendChild(keys);
      starterKeys.appendChild(li);
    }
  }

  function docIsEmpty() {
    if (!view) { return false; }
    const d = view.state.doc;
    return d.childCount === 1 && d.firstChild.type === schema.nodes.paragraph && d.firstChild.content.size === 0;
  }

  function drawStarter() { show(starterEl, docIsEmpty()); }

  /** 기본 구성 — 빈 문서일 때만 통째로 넣는다(쓴 글을 덮지 않는다). 되돌리기(Ctrl+Z)로 뺄 수 있다. */
  function applyStarter() {
    if (!view || !docIsEmpty()) { return; }
    const n = schema.nodes;
    const text = (t) => (t === '' ? null : schema.text(t));
    const blocks = [
      n.heading.create({ id: uuid4(), level: 1 }, text('제목')),
      n.toc.create({ id: uuid4() }),
      n.heading.create({ id: uuid4(), level: 2 }, text('개요')),
      n.paragraph.create({ id: uuid4() }, text('무엇에 관한 글인지 한두 줄로 적습니다.')),
      n.heading.create({ id: uuid4(), level: 2 }, text('할 일')),
      n.to_do.create({ id: uuid4(), checked: false }, text('첫 번째 할 일')),
      n.heading.create({ id: uuid4(), level: 2 }, text('메모')),
      n.paragraph.create({ id: uuid4() })
    ];
    const tr = view.state.tr.replaceWith(0, view.state.doc.content.size, blocks);
    // 커서는 맨 위 「제목」 끝 — 바로 고쳐 쓰게.
    tr.setSelection(TextSelection.create(tr.doc, 1 + '제목'.length));
    view.dispatch(tr.scrollIntoView());
    view.focus();
  }

  if (starterApply) { starterApply.addEventListener('click', applyStarter); }

  /** Ctrl+Alt+숫자 — 커서가 있는 블록을 그 종류로 바꾼다(`BLOCK_TYPES[].key`, 창의 「바꾸기」와 같다) */
  function blockKeymap() {
    const bindings = {};
    for (const entry of BLOCK_TYPES) {
      if (!entry.key || entry.makesPage) { continue; }
      bindings[entry.key] = (pmState) => {
        const $from = pmState.selection.$from;
        if ($from.depth < 1 || !blockDialog) { return false; }
        blockDialog.changeTo(entry, $from.before(1));
        return true;
      };
    }
    return keymap(bindings);
  }

  function startEditing() {
    // ★ 편집기는 **한 번만** 만든다 (지시서 4장) — 두 벌이 겹치면 친 글자가 사라진다.
    if (state.editing || view !== null) { return; }

    let pmDoc;
    try {
      // 하위 페이지 카드에 제목을 보여 주려고 지도를 함께 넘긴다 (4-A).
      pmDoc = fromDoc(state.doc, { child_titles: state.childTitles, link_titles: state.linkTitles, file_titles: state.fileTitles, mention_labels: state.mentionLabels });
    } catch (e) {
      setText(msg, e && e.message ? e.message : GENERIC_ERROR);
      return;
    }

    const editorState = EditorState.create({
      schema: schema,
      doc: schema.nodeFromJSON(pmDoc),
      plugins: [
        buildInputRules(),
        blockKeymap(),
        // ★ 우리 명령을 `baseKeymap` **앞에** 둔다 (지시서 3장).
        //   Ctrl+B·I·U 는 등록하지 않는다 — 문자 꾸미기를 만들지 않기로 했다.
        keymap({
          'Mod-z': undo,
          'Mod-y': redo,
          'Shift-Mod-z': redo,
          Enter: chainCommands(commandAtomEdit, commandCodeEnter, commandEnter),
          'Shift-Enter': commandShiftEnter,
          Backspace: commandBackspace,
          // 3단계에는 들여쓰기가 없다. 탭으로 초점이 새어 나가지 않게 여기서 멈춘다.
          Tab: () => true,
          'Shift-Tab': () => true
        }),
        keymap(baseKeymap),
        history(),
        dropCursor(),
        gapCursor(),
        uniqueBlockIds,
        blockHandles,
        todoToggle,
        liveToc
      ]
    });

    view = new EditorView(editorEl, {
      state: editorState,
      attributes: { class: 'doc editor' },
      // 7-D4 — 자기 DOM 을 다시 읽을 때만 멘션을 되살린다 · 붙여넣기·끌어 놓기는 스키마 그대로(멘션 0) — editor-schema.js 머리글
      domParser: editorDomParser,
      clipboardParser: clipboardDomParser,
      handleDoubleClickOn(v, p, node, nodePos, event, direct) {
        if (!direct || ATOM_TYPES.indexOf(node.type.name) === -1) { return false; }
        editAtom(node.type.name, node.attrs, nodePos);
        return true;
      },
      dispatchTransaction(tr) {
        const next = view.state.apply(tr);
        view.updateState(next);
        if (toolbar) { toolbar.refresh(); }
        if (tr.docChanged) { markDirty(); drawStarter(); }
      }
    });

    toolbar = createToolbar(view, {
      pageId: pageId,
      // 7-D4 — 새로 넣은 멘션의 라벨을 이름 지도에 넣어 두면 저장 뒤 읽기 화면이 곧바로 그린다(다음 `load()` 에서 서버 것으로 바뀐다)
      rememberMention: (key, entry) => { state.mentionLabels[key] = entry; }
    });
    drawStarter();

    show(docEl, false);
    show(editorEl, true);
    show(titleField, true);
    if (titleInput) { titleInput.value = state.title; }
    show(editButton, false);
    show(doneButton, true);
    show(addBlockButton, true);
    state.editing = true;
    serial = 0;
    savedSerial = 0;
    sentSerial = -1;
    badge('저장됨', 'badge--ok');
    view.focus();
    startHeartbeat();
    // B1-2 — 편집 모드에 들어갈 때 임시저장 열쇠를 받는다(한 화면에 한 번). 못 받으면(503·막힌 브라우저) 쓰지 않고 배지로 알린다.
    if (drafts) {
      if (!drafts.available()) {
        draftOff(true);
      } else {
        drafts.ready().then((ok) => { if (!ok && state.editing) { draftOff(true); } });
      }
    }
  }

  /* ── 임시저장 되살리기 (B1-2 설계 5) ──
     페이지를 열 때 **이 사람·이 페이지** 항목이 있으면 열쇠를 받아(한 번) 풀어 보고, 풀리는 것만 알림 줄로 묻는다.
     불러오기 → 본문은 편집 모드에 넣고 **평소 저장 경로**로 보낸다(보낼 판 번호는 임시저장 때의 `base_version` —
     그사이 서버 판이 바뀌었으면 기존 409 충돌 창이 뜬다, 새 병합 코드 0). 되살린 본문은 `fromDoc`(스키마 통로)을 지나고
     서버 `Doc::validate` 가 다시 본다. 댓글은 쓰기 칸에 **넣기만** 한다(보내지 않는다). 자동 실행 0 — 사람이 누를 때만. */
  let draftNotice = null;

  function applyPageDraft(data) {
    let node;
    try {
      const pmDoc = fromDoc(data.doc, { child_titles: state.childTitles, link_titles: state.linkTitles, file_titles: state.fileTitles, mention_labels: state.mentionLabels });
      node = schema.nodeFromJSON(pmDoc);
    } catch (e) {
      return false;
    }
    if (!state.editing) { startEditing(); }
    if (view === null) { return false; }
    if (titleInput) { titleInput.value = typeof data.title === 'string' && data.title.trim() !== '' ? data.title : state.title; }
    state.version = data.base_version;
    // 문서 전체를 사본으로 바꾸는 편집 한 번 → dispatchTransaction 이 markDirty → 평소 저장. Ctrl+Z 로 서버 판으로 되돌릴 수 있다.
    view.dispatch(view.state.tr.replaceWith(0, view.state.doc.content.size, node.content).scrollIntoView());
    return true;
  }

  function applyCommentDraft(c) {
    return commentsLoaded.then(() => {
      if (!commentInput || !state.canComment) { return; }
      replyTo = null;
      if (c.target !== 'new') {
        const li = document.getElementById('comment-' + c.target);
        const who = li ? li.querySelector('.list__head strong') : null;
        if (li && !li.classList.contains('is-reply')) { replyTo = { id: c.target, who: who ? who.textContent : '' }; }
      }
      commentInput.value = c.body;
      commentKeyNow = c.key;
      drawComposer();   // 대상 댓글이 사라졌으면 새 댓글 열쇠로 옮겨 쓴다(drawComposer 안)
    });
  }

  function drawDraftNotice(pageDraft, commentDraft) {
    if (!msg || !msg.parentNode) { return; }
    if (draftNotice !== null) { draftNotice.remove(); }
    const box = document.createElement('section');
    box.className = 'notice';
    box.setAttribute('role', 'status');
    box.setAttribute('data-draft-notice', '');
    const newest = Math.max(pageDraft ? pageDraft.savedAt : 0, commentDraft ? commentDraft.savedAt : 0);
    const what = [];
    if (pageDraft) { what.push('본문'); }
    if (commentDraft) { what.push(commentDraft.target === 'new' ? '댓글' : '답글'); }
    const title = document.createElement('p');
    title.className = 'notice__title';
    title.textContent = '저장 안 된 내용이 있습니다 (' + localWhen(newest) + ' · ' + what.join(' · ') + ')';
    box.appendChild(title);
    const note = document.createElement('p');
    note.textContent = '로그인이 끊기기 전에 쓰던 것을 이 브라우저에 암호화해 남겨 두었습니다. 불러오면 본문은 편집 모드로 넣어 저장하고, 댓글은 쓰기 칸에 넣기만 합니다.';
    box.appendChild(note);
    const actions = document.createElement('div');
    actions.className = 'toolbar notice__actions';
    const loadButton = document.createElement('button');
    loadButton.type = 'button';
    loadButton.className = 'btn btn--quiet btn--small';
    loadButton.setAttribute('data-draft-load', '');
    loadButton.textContent = '불러오기';
    const dropButton = document.createElement('button');
    dropButton.type = 'button';
    dropButton.className = 'btn btn--quiet btn--small';
    dropButton.setAttribute('data-draft-drop', '');
    dropButton.textContent = '버리기';
    actions.appendChild(loadButton);
    actions.appendChild(dropButton);
    box.appendChild(actions);

    const close = () => { box.remove(); if (draftNotice === box) { draftNotice = null; } };
    loadButton.addEventListener('click', () => {
      loadButton.disabled = true;
      dropButton.disabled = true;
      if (pageDraft && !applyPageDraft(pageDraft.data)) {
        drafts.remove(pageDraft.key);
        setText(msg, '임시저장된 본문을 불러오지 못해 버렸습니다(모양이 맞지 않습니다).');
      }
      (commentDraft ? applyCommentDraft(commentDraft) : Promise.resolve()).then(close, close);
    });
    dropButton.addEventListener('click', () => {
      // 서버 요청 0 — 이 브라우저의 사본만 지운다. 그사이 새로 쓰기 시작한 것(지금 지키는 중인 사본)은 건드리지 않는다.
      if (pageDraft && !(state.editing && serial !== savedSerial)) { drafts.remove(pageDraft.key); }
      if (commentDraft && !(commentInput && commentInput.value.trim() !== '' && commentKeyNow === commentDraft.key)) {
        drafts.remove(commentDraft.key);
      }
      close();
    });
    msg.parentNode.insertBefore(box, msg.nextSibling);
    draftNotice = box;
  }

  function offerDrafts() {
    if (!drafts || !drafts.available()) { return; }
    const here = drafts.entries().filter((e) => e.pageId === pageId);
    if (here.length === 0) { return; }
    drafts.ready().then((ok) => {
      if (!ok) { return null; }
      const pageEntry = here.find((e) => e.kind === 'page') || null;
      const commentEntry = here.filter((e) => e.kind === 'comment').sort((a, b) => b.savedAt - a.savedAt)[0] || null;
      return Promise.all([
        pageEntry ? drafts.read(pageEntry.key) : null,
        commentEntry ? drafts.read(commentEntry.key) : null
      ]).then((got) => {
        const p = got[0];
        const c = got[1];
        let pageDraft = null;
        let commentDraft = null;
        if (p) {
          if (typeof p.data.base_version === 'number' && p.data.doc !== null && typeof p.data.doc === 'object') {
            pageDraft = { key: pageEntry.key, data: p.data, savedAt: p.savedAt };
          } else {
            drafts.remove(pageEntry.key);   // 풀렸지만 모양이 아니다 — 되살릴 수 없는 사본은 남기지 않는다
          }
        }
        if (c) {
          if (typeof c.data.body === 'string' && c.data.body.trim() !== '') {
            commentDraft = { key: commentEntry.key, target: commentEntry.target, body: c.data.body, savedAt: c.savedAt };
          } else {
            drafts.remove(commentEntry.key);
          }
        }
        // 고칠 수 없게 된 페이지의 본문·댓글을 쓸 수 없게 된 페이지의 댓글은 묻지 않는다(사본은 7일 뒤 정리된다).
        if (pageDraft && !state.canEdit) { pageDraft = null; }
        if (commentDraft && !state.canComment) { commentDraft = null; }
        if (pageDraft || commentDraft) { drawDraftNotice(pageDraft, commentDraft); }
      });
    }).catch(() => { /* 임시저장은 편의 — 페이지를 막지 않는다 */ });
  }

  function stopEditing() {
    flushSave().then(() => {
      if (blockDialog) { blockDialog.close(); }
      if (atomDialog) { atomDialog.close(); }
      if (toolbar) { toolbar.hide(); toolbar = null; }
      show(starterEl, false);
      if (view) { view.destroy(); view = null; }
      clear(editorEl);
      stopHeartbeat();
      state.editing = false;
      show(editorEl, false);
      show(titleField, false);
      show(docEl, true);
      show(doneButton, false);
      show(addBlockButton, false);
      show(editButton, state.canEdit);
      drawRead();
      setText(titleEl, state.title);
    });
  }

  /* ── 자동 저장 (1.5초 멈춤 · 늦어도 10초) ── */

  let firstDirtyAt = 0;

  function markDirty() {
    serial += 1;
    badge('저장 중…', '');
    const now = Date.now();
    if (firstDirtyAt === 0) { firstDirtyAt = now; }
    window.clearTimeout(saveTimer);
    const wait = now - firstDirtyAt >= 10000 ? 0 : 1500;
    saveTimer = window.setTimeout(() => { save(); }, wait);
    scheduleDraft();   // B1-2 — 1초 묶음으로 이 브라우저에 암호화 사본
  }

  function currentDoc() {
    return view ? toDoc(view.state.doc.toJSON()) : state.doc;
  }

  /**
   * 남은 것을 지금 보낸다. 보내는 중이었다면 **그 뒤에 이어지는 저장까지** 기다린다.
   * @param {boolean} [closing] 창을 닫거나 탭이 숨는 중 — 요청에 `keepalive` 를 단다(B1-2 설계 7, 닫히면서 취소되지 않게)
   */
  function flushSave(closing) {
    window.clearTimeout(saveTimer);
    if (saving) { return inFlight || Promise.resolve(); }
    if (serial === savedSerial) { return Promise.resolve(); }
    return Promise.resolve(save(closing === true));
  }

  /**
   * 저장 한 번.
   *
   * ★ 보내는 중에 또 고쳐지면 **그 변경을 잃지 않는다**(관문 ③ 실측 2026-09-22·09-23).
   *   전에는 요청이 날아가 있는 1초 남짓 사이에 친 글자가 조용히 사라졌고, 배지는 앞선 요청이
   *   끝나며 「저장됨」으로 바뀌어 **사용자는 저장된 줄 알았다**. 이제
   *     ① 보낼 때의 변경 번호(`sentSerial`)를 기억하고
   *     ② 200 이 와도 `serial` 이 그보다 크면 「저장됨」을 **보이지 않고** 곧바로 한 번 더 보낸다.
   *   409(충돌)일 때는 이어서 보내지 않는다 — 무엇을 할지는 사람이 모달에서 고른다.
   */
  function save(closing) {
    if (saving) { return inFlight || Promise.resolve(); }
    saving = true;

    const mySerial = serial;
    sentSerial = mySerial;
    const doc = currentDoc();
    const title = titleInput && titleInput.value.trim() !== '' ? titleInput.value.trim() : state.title;

    inFlight = api('PATCH', '/api/pages/' + pageId, {
      doc_version: state.version,
      doc: doc,
      title: title
    }, { keepalive: closing === true })
      .then((result) => {
        saving = false;
        // B1-2 — 로그인이 끊겼다: `toLogin` 이 떠나기 **전에** 이 판의 사본을 암호화해 남긴다(leaveHooks).
        if (result.status === 401) { toLogin(); return; }
        if (result.status === 200) {
          state.version = result.data.doc_version;
          state.title = result.data.title;
          state.doc = doc;
          state.buttons = result.data.buttons || {};
          savedSerial = mySerial;
          setText(titleEl, state.title);
          // 사이드바 트리의 지금 페이지 줄도 새 제목으로 (5-A′) — 다시 불러오지 않는다
          const navHere = document.querySelector('.nav-tree__row.is-active .nav-tree__link');
          if (navHere) { navHere.textContent = state.title; }
          if (serial !== savedSerial) {
            // 보내는 사이에 또 고쳐졌다 — 아직 「저장됨」이 아니다.
            badge('저장 중…', '');
            return save();
          }
          firstDirtyAt = 0;
          badge('저장됨', 'badge--ok');
          clearPageDraft();   // B1-2 설계 4 — 이 판이 서버에 있다. 사본은 필요 없다
          return;
        }
        if (result.status === 409) {
          badge('저장 못 함', 'badge--admin-only');
          pendingConflict = { doc: doc, title: title, server: result.data };
          openConflict();
          return;
        }
        badge('저장 못 함', 'badge--admin-only');
        setText(msg, errorMessage(result.data));
        if (Array.isArray(result.data.errors) && result.data.errors.length > 0) {
          setText(msg, errorMessage(result.data) + ' — ' + result.data.errors[0].path + ' (' + result.data.errors[0].reason + ')');
        }
      })
      .catch(() => {
        saving = false;
        badge('저장 못 함', 'badge--admin-only');
        setText(msg, GENERIC_ERROR);
      });
    return inFlight;
  }

  /* ── 409 안내 (ARCH 14.3) ── */

  function openConflict() {
    if (!conflictDialog || typeof conflictDialog.showModal !== 'function') {
      setText(msg, '다른 곳에서 먼저 저장되었습니다. 새로 고침하세요.');
      return;
    }
    if (!conflictDialog.open) { conflictDialog.showModal(); }
  }

  if (conflictDialog) {
    // 디버깅 묶음 U2 — 머리 ✕ 와 바닥 「닫기」 둘 다(전엔 querySelector 라 첫 것만 이어졌다)
    for (const closeButton of conflictDialog.querySelectorAll('[data-conflict-close]')) {
      closeButton.addEventListener('click', () => conflictDialog.close());
    }
    const reload = document.getElementById('conflict-reload');
    if (reload) {
      reload.addEventListener('click', () => {
        conflictDialog.close();
        // B1-2 — 「새로 고침」은 이 창에서 쓴 내용을 버리는 선택이다(창의 안내문). 임시저장 사본도 함께 버린다.
        clearPageDraft();
        leavingOnPurpose = true;
        window.location.reload();
      });
    }
    const overwrite = document.getElementById('conflict-overwrite');
    if (overwrite) {
      overwrite.addEventListener('click', () => {
        conflictDialog.close();
        if (!pendingConflict) { return; }
        // 서버가 준 **지금의 판 번호**로 다시 보낸다 — 내 것이 새 판이 된다.
        state.version = typeof pendingConflict.server.current_version === 'number'
          ? pendingConflict.server.current_version
          : state.version;
        pendingConflict = null;
        save();
      });
    }
  }

  /* ── 편집 중 표시 (30초) ── */

  /* P-4a — 편집 중 heartbeat 도 탭이 보일 때만 돈다(숨으면 멈추고, 다시 보이면 곧바로 한 번).
     숨어 있는 동안 서버의 「편집 중」 표시는 기존 만료 규칙(마지막 신호 뒤 2분, pages_heartbeat.php)대로
     다른 사람 화면에서 사라질 수 있다 — 받아들인 동작이다(지시서 P-4a ④). */
  function startHeartbeat() {
    if (heartbeat === null) {
      // U-3 — heartbeat 도 자동 요청이다(표식). 편집 모드로 켜 두기만 한 탭은 30분 뒤 로그아웃되고, 쓰던 글은 임시저장이 지킨다.
      heartbeat = whileVisible(() => {
        api('POST', '/api/pages/' + pageId + '/heartbeat', {}, { background: true })
          .then((result) => { if (result.status === 200) { showEditors(result.data.editors); } })
          .catch(() => {});
      }, 30000);
    }
    heartbeat.start();
  }

  function stopHeartbeat() {
    if (heartbeat !== null) { heartbeat.stop(); }
  }

  /* ── 단추 ── */

  if (editButton) { editButton.addEventListener('click', startEditing); }
  if (doneButton) { doneButton.addEventListener('click', stopEditing); }
  if (addBlockButton && blockDialog) {
    addBlockButton.addEventListener('click', () => { blockDialog.open('insert', null); });
  }
  if (titleInput) {
    titleInput.addEventListener('input', () => { markDirty(); });
  }

  /* ── 「하위 페이지 만들기」 (4-A) ──
     본문은 건드리지 않고 트리에만 한 장 더한다. 만든 뒤 목록을 다시 받아 **결과를 보인다** —
     화면이 스스로 카드를 지어내지 않는다(서버가 준 목록만 그린다). */
  if (newChildButton) {
    newChildButton.addEventListener('click', () => {
      newChildButton.disabled = true;
      createChildPage()
        .then((made) => {
          newChildButton.disabled = false;
          if (!made) { return; }
          return load();
        })
        .catch(() => { newChildButton.disabled = false; setText(msg, GENERIC_ERROR); });
    });
  }

  /* ── 「하위 데이터베이스 만들기」 (6-A) — 만든 뒤 그 DB 페이지로 간다(표·속성은 거기서) ── */
  if (newDbButton) {
    newDbButton.addEventListener('click', () => {
      newDbButton.disabled = true;
      api('POST', '/api/pages', { parent_id: pageId, database: true })
        .then((result) => {
          newDbButton.disabled = false;
          if (result.status === 401) { toLogin(); return; }
          if (result.status !== 201 || !result.data.page) { setText(msg, errorMessage(result.data)); return; }
          window.location.assign(COMMUNITY_BASE + '/p/' + result.data.page.id);
        })
        .catch(() => { newDbButton.disabled = false; setText(msg, GENERIC_ERROR); });
    });
  }

  /* ── 「옮기기」 창 (4-A) ──
     트리를 한 번(`?tree=1`)에 받아 고를 수 있는 부모만 그린다. 자기 자신과 자기 하위는
     목록에서 빼지만, **막는 것은 서버**다(`decidePageMove` — cycle·depth_exceeded).
     화면의 목록은 편의일 뿐 판정이 아니다 (DESIGN 원칙 1). */
  const moveDialog = document.getElementById('move-dialog');
  const moveTreeBox = document.getElementById('move-tree');
  const moveMsg = document.getElementById('move-msg');
  const moveConfirm = document.getElementById('move-confirm');
  let movePick = null;          // 고른 새 부모의 id (null = 맨 위)
  let movePicked = false;       // 하나라도 골랐나

  function movePickButton(label, id, depth) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'move-tree__pick';
    button.setAttribute('role', 'option');
    button.setAttribute('aria-selected', 'false');
    button.setAttribute('data-parent-id', id === null ? '' : id);
    button.setAttribute('data-depth', String(depth));
    button.textContent = label;
    button.addEventListener('click', () => {
      movePick = id;
      movePicked = true;
      for (const other of moveTreeBox.querySelectorAll('.move-tree__pick')) {
        const on = other === button;
        other.className = 'move-tree__pick' + (on ? ' is-selected' : '');
        other.setAttribute('aria-selected', on ? 'true' : 'false');
      }
    });
    return button;
  }

  function moveLevel(byParent, key, depth, skip) {
    const box = document.createDocumentFragment();
    if (depth > 12) { return box; }
    for (const node of byParent.get(key) || []) {
      if (node.id === skip) { continue; }   // 자기 자신과 그 아래는 부모가 될 수 없다
      const wrap = document.createElement('div');
      wrap.className = 'move-tree__node';
      wrap.appendChild(movePickButton((node.icon ? node.icon + ' ' : '') + node.title, node.id, depth));
      const kids = moveLevel(byParent, node.id, depth + 1, skip);
      if (kids.childNodes.length > 0) {
        const kidBox = document.createElement('div');
        kidBox.className = 'move-tree__kids';
        kidBox.appendChild(kids);
        wrap.appendChild(kidBox);
      }
      box.appendChild(wrap);
    }
    return box;
  }

  function openMove() {
    if (!moveDialog || typeof moveDialog.showModal !== 'function') { return; }
    movePick = null;
    movePicked = false;
    setText(moveMsg, '');
    clear(moveTreeBox);
    // 4-B — 이 페이지가 **든 공간**의 트리를 받는다(팀 공간에서도 같은 창을 쓴다).
    api('GET', '/api/pages?tree=1' + (state.space ? '&space=' + encodeURIComponent(String(state.space.id)) : ''))
      .then((result) => {
        if (result.status === 401) { toLogin(); return; }
        if (result.status !== 200) { setText(moveMsg, errorMessage(result.data)); return; }
        const pages = Array.isArray(result.data.pages) ? result.data.pages : [];
        const byParent = new Map();
        for (const page of pages) {
          const k = typeof page.parent_id === 'string' ? page.parent_id : '';
          if (!byParent.has(k)) { byParent.set(k, []); }
          byParent.get(k).push(page);
        }
        moveTreeBox.appendChild(movePickButton('맨 위 (상위 없음)', null, 0));
        moveTreeBox.appendChild(moveLevel(byParent, '', 0, pageId));
      })
      .catch(() => setText(moveMsg, GENERIC_ERROR));
    if (!moveDialog.open) { moveDialog.showModal(); }
  }

  if (moveButton && moveDialog) {
    moveButton.addEventListener('click', openMove);
    for (const close of moveDialog.querySelectorAll('[data-move-close]')) {
      close.addEventListener('click', () => moveDialog.close());
    }
    if (moveConfirm) {
      moveConfirm.addEventListener('click', () => {
        if (!movePicked) { setText(moveMsg, '새 상위 페이지를 고르세요'); return; }
        moveConfirm.disabled = true;
        api('PATCH', '/api/pages/' + pageId + '/move', { parent_id: movePick })
          .then((result) => {
            moveConfirm.disabled = false;
            if (result.status === 401) { toLogin(); return; }
            if (result.status === 200) {
              moveDialog.close();
              // 경로·하위 목록이 함께 바뀐다 — 서버가 준 값으로 다시 그린다.
              return load();
            }
            setText(moveMsg, errorMessage(result.data));
          })
          .catch(() => { moveConfirm.disabled = false; setText(moveMsg, GENERIC_ERROR); });
      });
    }
  }

  // 블록 손잡이(⋮⋮) — 자리는 클릭할 때 다시 찾는다(장식은 문서가 바뀔 때마다 새로 그려진다).
  if (editorEl && blockDialog) {
    editorEl.addEventListener('mousedown', (event) => {
      const target = event.target;
      const handle = target && target.closest ? target.closest('.block-handle') : null;
      if (!handle || view === null) { return; }
      event.preventDefault();
      let pos = null;
      try { pos = view.posAtDOM(handle.parentNode, 0); } catch (e) { pos = null; }
      if (pos === null) { return; }
      const $pos = view.state.doc.resolve(pos);
      if ($pos.depth < 1) { return; }
      blockDialog.open('change', $pos.before(1));
    });
  }

  const deleteDialog = document.getElementById('delete-dialog');
  if (deleteButton && deleteDialog) {
    deleteButton.addEventListener('click', () => {
      if (typeof deleteDialog.showModal === 'function') { deleteDialog.showModal(); }
    });
    // 디버깅 묶음 U2 — 머리 ✕ 와 바닥 「닫기」 둘 다. 전엔 querySelector 라 첫 것(✕)만 이어져 바닥의 「그만두기」를 눌러도 닫히지 않았다
    for (const cancel of deleteDialog.querySelectorAll('[data-delete-close]')) {
      cancel.addEventListener('click', () => deleteDialog.close());
    }
    const confirmButton = document.getElementById('delete-confirm');
    if (confirmButton) {
      confirmButton.addEventListener('click', () => {
        deleteDialog.close();
        confirmButton.disabled = true;
        api('DELETE', '/api/pages/' + pageId, {})
          .then((result) => {
            if (result.status === 401) { toLogin(); return; }
            if (result.status === 200) { window.location.assign(COMMUNITY_BASE + '/workspace'); return; }
            setText(msg, errorMessage(result.data));
            confirmButton.disabled = false;
          })
          .catch(() => { setText(msg, GENERIC_ERROR); confirmButton.disabled = false; });
      });
    }
  }

  // B1-2 — 로그인 화면으로 떠나기 전(401 그 순간)에 이 화면의 저장 안 된 본문·쓰기 칸을 암호화해 남긴다.
  leaveHooks.push(() => Promise.all([writePageDraft(), writeCommentDraft()]));

  // 독립 검토 M-1(2026-09-24) — 로그아웃 단추(app.js)가 사본을 지우기 **직전**에 알린다. 그 뒤로는
  // 떠나는 길(beforeunload·visibilitychange·401 → leaveHooks)이 사본을 다시 쓰지 않는다.
  document.addEventListener('qaredi:logout', () => {
    leavingOnPurpose = true;
    // 끄면 hasKey() 가 false 가 되어 writePageDraft·writeCommentDraft(1초 묶음 타이머 포함)가 곧바로 빠진다
    if (drafts) { drafts.shutdown(); }
  });

  // 창을 닫거나 다른 탭으로 옮겨 갈 때 남은 변경을 보낸다 (지시서 4장).
  // B1-2 설계 7 — 그 요청에 `keepalive`(닫히면서 취소되지 않게) · 임시저장 사본도 한 번 더 ·
  // 저장 안 된 변경이 남았으면 브라우저 기본 닫기 경고(문구는 브라우저가 정한다 — 일부러 떠나는 중이면 묻지 않는다).
  window.addEventListener('beforeunload', (event) => {
    if (state.editing) {
      flushSave(true);
      writePageDraft();
      if (!leavingOnPurpose && serial !== savedSerial) {
        event.preventDefault();
        event.returnValue = '';
      }
    }
    writeCommentDraft();
  });
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState !== 'hidden') { return; }
    if (state.editing) { flushSave(true); writePageDraft(); }
    writeCommentDraft();
  });

  /* 5-C — 게시판 「새 글」은 `?edit=1` 로 열린다: 고칠 수 있으면(창구의 `can_edit`) 곧바로 편집 모드.
     꼬리표는 주소에서 지운다(새로 고침·뒤로 가기에 다시 편집 모드로 들어가지 않게). */
  load().then(() => {
    // 개발 대기열 6 — 「최근」은 이 페이지를 연 **뒤에** 받는다(창구가 방금 이 줄을 남겼다 — 읽기 판정을 지난 경우만)
    loadRecentNav();
    // B1-2 설계 5 — 이 사람·이 페이지의 임시저장이 있으면 알림 줄로 묻는다(불러오기는 사람이 누를 때만)
    offerDrafts();
    let wantEdit = false;
    try { wantEdit = new URLSearchParams(window.location.search).get('edit') === '1'; } catch (e) { wantEdit = false; }
    if (!wantEdit) { return; }
    try { window.history.replaceState(null, '', window.location.pathname); } catch (e) { /* 주소 정리는 편의일 뿐 */ }
    if (state.canEdit && !state.editing) { startEditing(); }
  }).catch(() => { loadRecentNav(); setText(msg, GENERIC_ERROR); });
}

/* ─── 시작 ───────────────────────────────────────────────────── */

/**
 * P-5a — `GET /api/me` 는 한 화면에 한 번. 셸 스크립트(app.js — 이 모듈보다 먼저 실행된다)가 내놓은
 * `window.qarediMe` 가 첫 호출의 Promise 를 나눠 준다. 셸이 없는 날(파일이 빠진 배포 등)에만 전처럼 직접 부른다.
 *
 * @return Promise<{status:number, me:(Object|null)}>
 */
function loadMe() {
  if (typeof window.qarediMe === 'function') { return window.qarediMe(); }
  return fetch(APP_BASE + '/api/me', { credentials: 'same-origin', cache: 'no-store' })
    .then((response) => {
      if (!response.ok) { return { status: response.status, me: null }; }
      return readJson(response).then((me) => ({ status: response.status, me: me }));
    });
}

let booted = false;

/**
 * 디버깅 묶음 U H3 (2026-09-26) — 이 사람이 커뮤니티에 **쓸 수 있는 역할인가**(`/api/me` 의 `can_community_write` — 서버가
 * 라우트 정책 `community.write` 와 같은 함수로 정한 값). 페이지 화면은 창구가 준 페이지별 can_*(편집·넘기기·공개 범위·태그…)와
 * **둘 다** 참일 때만 단추를 보인다 — 손님이 자기 「시작하기」 페이지에서 누르면 권한 없음만 나오던 단추들이다.
 * 값이 없으면(옛 창구) 페이지별 값만 따른다. 숨김은 편의이고 막는 것은 서버다 (PRD S-01).
 */
let meCanWrite = true;

function boot() {
  if (booted) { return; }
  booted = true;

  const page = body && body.dataset ? body.dataset.page : '';
  if (page !== 'community-home' && page !== 'community-page' && page !== 'community-board' && page !== 'community-threads') { return; }
  if (COMMUNITY_BASE === '' || APP_BASE === '') { return; }

  // CSRF 토큰은 t-box 창구가 준다 — 한 앱·한 세션이라 같은 토큰이 커뮤니티 창구에도 통한다.
  // P-5a — 셸(app.js)과 **같은 한 번**을 나눠 쓴다(`window.qarediMe`, 화면 수명 동안만 · no-store).
  loadMe()
    .then((result) => {
      if (result.status === 401) { toLogin(); return null; }
      if (result.me === null) { return null; }
      return Promise.resolve(result.me).then((me) => {
        if (typeof me.csrf_token !== 'string') { return null; }
        csrfToken = me.csrf_token;
        // B1-2 — 임시저장 창구(진짜 사용자 아이디 — 역할로 보기 중이어도 `username` 은 실제 계정). 열쇠는 아직 받지 않는다.
        // 7일이 지난 내 항목은 커뮤니티 화면을 열 때마다 정리한다(`saved_at` 은 평문이라 열쇠가 필요 없다).
        drafts = createDrafts(typeof me.username === 'string' ? me.username : '', loadDraftKey);
        // 라운드 B — 일괄 작업의 「이어서 하기」가 같은 계정인지 견줄 아이디
        meUsername = typeof me.username === 'string' ? me.username : null;
        // 디버깅 묶음 U H3 — 쓰기 자격(서버 판정 값). false 일 때만 끈다
        meCanWrite = me.can_community_write !== false;
        drafts.sweep();
        bindNewPage();
        // 개발 대기열 6 — 사이드바 「즐겨찾기」「최근」(세 화면 공통). 페이지 화면의 「최근」은 그 페이지를 연 뒤 initPage 가 받는다.
        bindFavoritesFold();
        loadFavoritesNav();
        if (page !== 'community-page') { loadRecentNav(); }
        if (page === 'community-home') {
          if (location.pathname === COMMUNITY_BASE + '/automations') { bindAutomations(api, COMMUNITY_BASE); loadSpaces(); } else if (location.pathname === COMMUNITY_BASE + '/procedures') { bindProcedures(api, COMMUNITY_BASE); loadSpaces(); } else { initHome(); }
        } else if (page === 'community-threads') {
          loadSpaces();
        } else if (page === 'community-board') {
          // 5-C — 게시판 화면. 사이드바의 공간·게시판 묶음은 여기서 함께 채운다.
          loadSpaces();
          initBoard();
        } else {
          // 4-B — 페이지 화면에서도 셸 왼쪽의 「팀 공간」 묶음을 채운다(홈은 initHome 이 한다).
          loadSpaces();
          initPage();
        }
        // 5-C — 사이드바 「게시판」 묶음(세 화면 공통). 게시판 화면은 관리 창이 같은 답을 다시 쓴다.
        if (page !== 'community-board') { loadBoards(); }
        // 5-D — 사이드바 알림 종(세 화면 공통, 30초 폴링)
        initNotifications();
        return null;
      });
    })
    .catch(() => setText(document.getElementById('msg'), GENERIC_ERROR));
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', boot);
} else {
  boot();
}
