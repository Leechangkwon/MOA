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

import { EditorState, TextSelection, Plugin } from './vendor/pm/prosemirror-state.js';
import { EditorView, Decoration, DecorationSet } from './vendor/pm/prosemirror-view.js';
import { keymap } from './vendor/pm/prosemirror-keymap.js';
import { history, undo, redo } from './vendor/pm/prosemirror-history.js';
import { baseKeymap, chainCommands } from './vendor/pm/prosemirror-commands.js';
import { inputRules, textblockTypeInputRule, InputRule } from './vendor/pm/prosemirror-inputrules.js';
import { dropCursor } from './vendor/pm/prosemirror-dropcursor.js';
import { gapCursor } from './vendor/pm/prosemirror-gapcursor.js';

import { renderDoc } from './doc-render.js';
import { schema, toDoc, fromDoc, uuid4, colorAllowed, schemeOkForEditor, COLORS, BLOCK_TYPES } from './editor-schema.js';

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

function errorMessage(data) {
  return data && typeof data.error === 'string' && data.error !== '' ? data.error : GENERIC_ERROR;
}

/** 커뮤니티 창구 부르기. 경로는 코드에 적힌 모듈 경로이고 접두는 여기서 한 번만 붙는다. */
function api(method, path, payload) {
  const options = {
    method: method,
    credentials: 'same-origin',
    cache: 'no-store',
    headers: {}
  };
  if (method !== 'GET' && method !== 'HEAD') {
    if (csrfToken === null) { return Promise.reject(new Error('no-csrf')); }
    options.headers['X-CSRF-Token'] = csrfToken;
    options.headers['Content-Type'] = 'application/json';
    options.body = JSON.stringify(payload === undefined ? {} : payload);
  }
  return fetch(COMMUNITY_BASE + path, options).then((response) =>
    readJson(response).then((data) => ({ status: response.status, data: data }))
  );
}

function toLogin() {
  window.location.assign(APP_BASE + '/login?to=community');
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

/** 트리·카드 줄에 붙일 작은 배지 하나. */
function visibilityBadge(visibility) {
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
  return COMMUNITY_BASE + '/' + (kind === 'personal' ? '' : '?space=' + String(id));
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
  const here = currentSpaceId();
  for (const space of teams) {
    const a = document.createElement('a');
    a.className = 'nav__item' + (here === String(space.id) ? ' is-active' : '');
    a.setAttribute('href', spaceHref(space.id, space.kind));
    const icon = makeIcon('users');
    const label = document.createElement('span');
    label.textContent = space.name;
    a.appendChild(icon);
    a.appendChild(label);
    box.appendChild(a);
  }
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
    return spaces;
  }).catch(() => []);
}

/* ─── 내 공간 (community-home) ───────────────────────────────── */

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
    if (extra) { parts.push(extra); }
    return '/api/pages' + (parts.length > 0 ? '?' + parts.join('&') : '');
  }

  /** 화면 머리를 창구가 준 공간 요약으로 바꾼다. 화면이 이름을 지어내지 않는다. */
  function drawSpaceHead(space) {
    if (!space) { return; }
    setText(headTitle, space.kind === 'team' ? space.name : '내 공간');
    setText(headSub, space.kind === 'team'
      ? '팀이 함께 쓰는 공간입니다. 팀원 누구나 페이지를 만들고 고칠 수 있습니다.'
      : '나만 보는 개인 업무 공간입니다. 페이지마다 공개 범위를 고를 수 있습니다.');
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

    const add = document.createElement('button');
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

    row.appendChild(caret);
    row.appendChild(link);
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
    row.appendChild(add);
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
      clear(treeBox);
      const pages = Array.isArray(result.data.pages) ? result.data.pages : [];
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
      setText(count, pages.length === 0 ? '' : '페이지 ' + String(result.data.total) + '개');
    });
  }

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
      window.clearTimeout(timer);
      timer = window.setTimeout(() => { load(search.value).catch(() => setText(msg, GENERIC_ERROR)); }, 300);
    });
  }

  // 상태 거르개 (4-D) — 고르면 창구를 다시 부른다. 화면이 목록에서 줄을 빼지 않는다.
  if (statusSelect) {
    statusSelect.addEventListener('change', () => {
      load(search ? search.value : '').catch(() => setText(msg, GENERIC_ERROR));
    });
  }

  // 휴지통 (4-C) — 되살린 뒤에는 트리를 다시 받는다(화면이 카드를 지어내지 않는다).
  bindTrash(() => { load(search ? search.value : '').catch(() => setText(msg, GENERIC_ERROR)); });
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

  function row(page) {
    const tr = document.createElement('tr');

    const title = document.createElement('td');
    title.textContent = (page.icon ? page.icon + ' ' : '') + page.title;
    tr.appendChild(title);

    const space = document.createElement('td');
    space.textContent = page.space && page.space.name ? page.space.name : '—';
    tr.appendChild(space);

    const kids = document.createElement('td');
    kids.textContent = page.descendants > 0 ? String(page.descendants) + '개' : '—';
    tr.appendChild(kids);

    const when = document.createElement('td');
    when.textContent = shortWhen(page.deleted_at);
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
      for (const page of pages) { tbody.appendChild(row(page)); }
      show(empty, pages.length === 0);
      setText(meta, pages.length === 0
        ? ''
        : String(pages.length) + '건 · ' + String(result.data.keep_days) + '일 뒤 지워집니다');
    });
  }

  button.addEventListener('click', () => {
    if (typeof dialog.showModal !== 'function') { return; }
    setText(msg, '');
    if (!dialog.open) { dialog.showModal(); }
    load().catch(() => setText(msg, GENERIC_ERROR));
  });
  for (const close of dialog.querySelectorAll('[data-trash-close]')) {
    close.addEventListener('click', () => dialog.close());
  }
}

/** 시각을 짧게 (t-box 의 `shortWhen` 과 같은 뜻 — 이 파일은 모듈이라 따로 둔다) */
function shortWhen(value) {
  if (typeof value !== 'string' || value === '') { return ''; }
  return value.slice(0, 16).replace('T', ' ');
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
            window.location.assign(COMMUNITY_BASE + '/p/' + result.data.page.id);
            return;
          }
          setText(msg, errorMessage(result.data));
          button.disabled = false;
        })
        .catch(() => { setText(msg, GENERIC_ERROR); button.disabled = false; });
    });
  }
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

/* ─── 인라인 툴바 — 「색」 「배경」 「링크」 셋 (지시서 1장) ───── */

function createToolbar(view) {
  const wrap = document.getElementById('inline-toolbar');
  if (!wrap) { return null; }
  // 「블록 추가」는 마크업에서 이 줄 안에 있다. 비우기 전에 붙잡아 두었다가 줄 맨 앞에 다시 놓는다
  // (단추의 click 은 initPage 가 걸어 두었으므로 요소를 새로 만들지 않는다).
  const addBlock = document.getElementById('page-add-block');

  clear(wrap);
  let fgPanel = null;
  let bgPanel = null;
  let linkPanel = null;
  let linkInput = null;
  let linkNote = null;

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

  /** 팔레트 한 줄 — 9색 + 「없앰」. 열었을 때만 만든다(닫힌 툴바의 단추는 셋뿐이다). */
  function buildPalette(suffix, label) {
    const panel = document.createElement('div');
    panel.className = 'editor-palette__row';
    panel.hidden = true;

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

  function closePanels() {
    if (fgPanel) { fgPanel.hidden = true; }
    if (bgPanel) { bgPanel.hidden = true; }
    if (linkPanel) { linkPanel.hidden = true; }
    if (linkNote) { setText(linkNote, ''); }
  }

  function anyPanelOpen() {
    return (fgPanel && !fgPanel.hidden) || (bgPanel && !bgPanel.hidden) || (linkPanel && !linkPanel.hidden);
  }

  function togglePanel(which) {
    const wasOpen = which === 'fg' ? (fgPanel && !fgPanel.hidden)
      : which === 'bg' ? (bgPanel && !bgPanel.hidden)
        : (linkPanel && !linkPanel.hidden);
    closePanels();
    if (wasOpen) { return; }
    if (which === 'fg') {
      if (!fgPanel) { fgPanel = buildPalette('', '글자색'); wrap.appendChild(fgPanel); }
      fgPanel.hidden = false;
    } else if (which === 'bg') {
      if (!bgPanel) { bgPanel = buildPalette('_bg', '배경색'); wrap.appendChild(bgPanel); }
      bgPanel.hidden = false;
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
  if (addBlock) {
    row.appendChild(addBlock);
    const sep = document.createElement('span');
    sep.className = 'editor-toolbar__sep';
    sep.setAttribute('aria-hidden', 'true');
    row.appendChild(sep);
  }
  for (const tool of tools) { row.appendChild(tool); }
  wrap.appendChild(row);

  /* 자리는 **CSS 가 정한다**(편집 영역 위에 붙는 줄, 팔레트는 그 아래로 뜬다). 좌표를 JS 로
     써 넣지 않는다(DESIGN 0장 2 — 인라인 style 0). 툴바는 편집하는 동안 늘 보이고, 여기서는
     단추를 쓸 수 있나만 정한다 — 보였다 숨었다 하면 본문이 밀렸다(2026-09-23 사용자 지적).
     ★ `view.hasFocus()` 는 보지 않는다 — 링크 입력칸을 누르는 순간 편집기가 초점을 잃어
       툴바가 사라지던 것이 관문 ③ 의 「링크가 안 걸린다」였다. 입력칸에 초점이 가도
       편집기의 선택은 그대로라 여기 판단이 바뀌지 않는다. */
  function refresh() {
    const empty = view.state.selection.empty;
    if (empty && anyPanelOpen()) { closePanels(); }
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
  const childrenWrap = document.getElementById('page-children-wrap');
  const childrenBox = document.getElementById('page-children');
  const childrenCount = document.getElementById('page-children-count');
  const childrenEmpty = document.getElementById('page-children-empty');
  const newChildButton = document.getElementById('page-new-child');
  const moveButton = document.getElementById('page-move');
  const conflictDialog = document.getElementById('conflict-dialog');
  const visibilityBadgeEl = document.getElementById('page-visibility');
  const visibilityButton = document.getElementById('page-visibility-edit');
  // 4-D — 속성 줄
  const propsRow = document.getElementById('page-props');
  const statusButton = document.getElementById('page-status');
  const statusBadgeEl = document.getElementById('page-status-badge');
  // 5-A — 「소유자」 칸과 「넘기기」, 공유받은 사람의 「공유 보기」
  const ownerNameEl = document.getElementById('page-owner-name');
  const handoverButton = document.getElementById('page-handover');
  const shareMineEl = document.getElementById('page-share-mine');
  const shareOpenButton = document.getElementById('page-share-open');

  let state = {
    doc: null, version: 0, title: '', canEdit: false, editing: false,
    // 4-A — 창구가 준 「볼 수 있는 하위 페이지」 제목 지도. 렌더러 두 벌이 이것만 본다.
    childTitles: {},
    // 4-B — 지금의 공개 범위와 이 페이지가 든 공간. 둘 다 창구가 준 값이다.
    visibility: 'private', space: null, canSetVisibility: false,
    // 4-D — 상태. 문자열 또는 null.
    status: null, canSetProperties: false,
    // 5-A — 소유자(`{kind, username|name, is_active}`) · 관리 자격 · 공유 · 인계. 전부 창구가 준 값이다.
    owner: null, canManage: false, canShare: false, canHandover: false,
    shareLevel: null, subtreeCount: null
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
  }

  function drawRead() {
    clear(docEl);
    if (state.doc) {
      docEl.appendChild(renderDoc(state.doc, { child_titles: state.childTitles, base: COMMUNITY_BASE }));
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

  /** 경로(breadcrumb) — 「내 공간 › 부모 › 이 페이지」. 마지막 칸만 링크가 아니다. */
  function drawCrumb(breadcrumb) {
    if (!crumbEl) { return; }
    clear(crumbEl);
    const trail = Array.isArray(breadcrumb) ? breadcrumb : [];

    const home = document.createElement('a');
    home.className = 'crumb__item';
    home.setAttribute('href', COMMUNITY_BASE + '/');
    home.textContent = '내 공간';
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
    show(childrenWrap, list.length > 0 || state.canEdit);
    show(newChildButton, state.canEdit);
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
      state.doc = page.doc;
      state.version = page.doc_version;
      state.title = page.title;
      state.canEdit = result.data.can_edit === true;
      state.childTitles = result.data.child_titles && typeof result.data.child_titles === 'object'
        ? result.data.child_titles
        : {};
      state.visibility = typeof page.visibility === 'string' ? page.visibility : 'private';
      state.space = result.data.space || null;
      state.canSetVisibility = result.data.can_set_visibility === true;
      state.status = typeof page.status === 'string' ? page.status : null;
      state.canSetProperties = result.data.can_set_properties === true;
      state.owner = page.owner && typeof page.owner === 'object' ? page.owner : null;
      state.canManage = result.data.can_manage === true;
      state.canShare = result.data.can_share === true;
      state.canHandover = result.data.can_handover === true;
      state.shareLevel = typeof result.data.share_level === 'string' ? result.data.share_level : null;
      state.subtreeCount = typeof result.data.subtree_count === 'number' ? result.data.subtree_count : null;
      drawVisibility();
      drawProps();
      setText(titleEl, page.title);
      document.title = page.title + ' · 커뮤니티';
      setText(subEl, page.parent ? '상위: ' + page.parent.title : '');
      drawCrumb(result.data.breadcrumb);
      drawRead();
      drawChildren(result.data.children);
      showEditors(result.data.editors);
      show(editButton, state.canEdit && !state.editing);
      // 5-A — 지우기·옮기기는 **관리**다. 편집 공유자에게는 `can_edit` 이 참이어도 이 둘이 없다.
      show(deleteButton, state.canManage);
      show(moveButton, state.canManage);
      show(visibilityButton, state.canSetVisibility);
      // 공유받은 사람 — 받은 권한 배지와 「공유 보기」(자기 공유 빼기). 관리할 수 있는 사람은 「바꾸기」로 연다.
      if (shareMineEl) {
        const label = SHARE_LABELS[state.shareLevel];
        setText(shareMineEl, label ? '받은 권한: ' + label : '');
        show(shareMineEl, !!label && !state.canShare);
      }
      show(shareOpenButton, !!SHARE_LABELS[state.shareLevel] && !state.canShare);
      setText(msg, '');
    });
  }

  /**
   * 머리의 공개 범위 배지 (4-B).
   * 팀 공간의 페이지에는 자기 범위가 없다(공간 등급을 따른다) — 그 자리에는 「팀 공간」을
   * 보인다. 어느 쪽이든 **창구가 준 값**이고 화면이 판단해서 고르지 않는다.
   */
  function drawVisibility() {
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
    if (ownerNameEl) {
      let text = '—';
      if (state.owner && state.owner.kind === 'personal' && typeof state.owner.username === 'string') {
        text = state.owner.username + (state.owner.is_active === false ? ' (쓰지 않는 계정)' : '');
      } else if (state.owner && state.owner.kind === 'team') {
        text = '팀 공간';
      }
      setText(ownerNameEl, text);
    }
    if (statusButton) { statusButton.disabled = !state.canSetProperties; }
    show(handoverButton, state.canHandover);
    show(propsRow, true);
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
    who.textContent = share.username + ' · ' + share.role + (share.is_active === false ? ' (쓰지 않는 계정)' : '');
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
      pick.setAttribute('aria-label', share.username + ' 의 권한');
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
    button.textContent = person.username + ' · ' + person.role + (label ? ' — ' + label : '');
    button.addEventListener('click', () => onPick(person, button));
    li.appendChild(button);
    return li;
  }

  function loadShareCandidates() {
    const q = shareSearch ? shareSearch.value.trim() : '';
    if (q === '') { clear(shareCandidates); show(shareCandidates, false); return Promise.resolve(); }
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
        if (people.length === 0) { setText(shareMsg, '찾는 아이디가 없습니다'); } else { setText(shareMsg, ''); }
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
            window.location.assign(COMMUNITY_BASE + '/');
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
  let handoverTimer = null;

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
    return api('GET', '/api/share-candidates?for=handover&page=' + encodeURIComponent(pageId)
      + (q === '' ? '' : '&q=' + encodeURIComponent(q)))
      .then((result) => {
        if (result.status === 401) { toLogin(); return; }
        if (result.status !== 200) { setText(handoverMsg, errorMessage(result.data)); return; }
        const people = Array.isArray(result.data.candidates) ? result.data.candidates : [];
        clear(handoverList);
        for (const person of people) {
          handoverList.appendChild(candidateItem(person, (who) => {
            handoverTo = who.username;
            setText(handoverPicked, '받는 사람: ' + who.username + ' · ' + who.role);
            for (const b of handoverList.querySelectorAll('[data-candidate]')) {
              b.setAttribute('aria-pressed', b.getAttribute('data-candidate') === who.username ? 'true' : 'false');
            }
            handoverArm();
          }, ''));
        }
        if (people.length === 0) { setText(handoverMsg, '넘겨받을 수 있는 사람이 없습니다'); } else { setText(handoverMsg, ''); }
      });
  }

  function openHandover() {
    if (!handoverDialog || typeof handoverDialog.showModal !== 'function') { return; }
    handoverTo = '';
    setText(handoverMsg, '');
    setText(handoverPicked, '받는 사람을 아직 고르지 않았습니다.');
    // ★ 기본값을 두지 않는다 — 열 때마다 라디오를 모두 비운다(사용자 결정 2).
    for (const radio of handoverRadios()) { radio.checked = false; }
    if (handoverSearch) { handoverSearch.value = ''; }
    const n = typeof state.subtreeCount === 'number' ? state.subtreeCount : 0;
    setText(handoverLead, n > 0
      ? '이 페이지와 하위 페이지 ' + String(n) + '개가 받는 사람의 공간 맨 위로 옮겨 갑니다.'
      : '이 페이지가 받는 사람의 공간 맨 위로 옮겨 갑니다.');
    handoverArm();
    if (!handoverDialog.open) { handoverDialog.showModal(); }
    loadHandoverCandidates().catch(() => setText(handoverMsg, GENERIC_ERROR));
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
                  return load().then(() => setText(msg, '넘겼습니다. 새 소유자: ' + String(result.data.to)));
                }
                window.location.assign(COMMUNITY_BASE + '/');
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
    who.textContent = entry.by === '' ? '—' : entry.by;
    tr.appendChild(who);

    const when = document.createElement('td');
    when.textContent = shortWhen(entry.at);
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
      historyCanEdit = result.data.can_edit === true;
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
    if (event.kind === 'property') {
      // 4-D — 「상태: 할 일 → 진행」·「담당자: 없음 → qa_employee01」(담당자는 5-A 전의 옛 기록)
      what.textContent = (event.prop === 'assignee' ? '담당자' : '상태') + ': '
        + propValueText(event.prop, event.from) + ' → ' + propValueText(event.prop, event.to);
    } else if (event.kind === 'handover') {
      // 5-A — 「소유자: qa_employee01 → qa_employee02」
      what.textContent = '소유자: ' + String(event.from) + ' → ' + String(event.to);
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
    who.textContent = (event.by === '' ? '—' : event.by) + ' 님';
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
           그 뒤에 지워졌다면 지도에 없고, 렌더러가 「볼 수 없는 페이지」로 그린다. */
        historyPreviewDoc.appendChild(renderDoc(got.doc, {
          child_titles: state.childTitles, base: COMMUNITY_BASE
        }));
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
    if (list.length === 0) { show(editorsBadge, false); return; }
    const names = list.map((e) => e.username).join(', ');
    setText(editorsBadge, names + ' 님도 보는 중');
    show(editorsBadge, true);
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

  const blockDialog = createBlockDialog(() => view, { createChildPage: createChildPage });

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
      pmDoc = fromDoc(state.doc, { child_titles: state.childTitles });
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
          Enter: chainCommands(commandCodeEnter, commandEnter),
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
      dispatchTransaction(tr) {
        const next = view.state.apply(tr);
        view.updateState(next);
        if (toolbar) { toolbar.refresh(); }
        if (tr.docChanged) { markDirty(); drawStarter(); }
      }
    });

    toolbar = createToolbar(view);
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
  }

  function stopEditing() {
    flushSave().then(() => {
      if (blockDialog) { blockDialog.close(); }
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
  }

  function currentDoc() {
    return view ? toDoc(view.state.doc.toJSON()) : state.doc;
  }

  /** 남은 것을 지금 보낸다. 보내는 중이었다면 **그 뒤에 이어지는 저장까지** 기다린다. */
  function flushSave() {
    window.clearTimeout(saveTimer);
    if (saving) { return inFlight || Promise.resolve(); }
    if (serial === savedSerial) { return Promise.resolve(); }
    return Promise.resolve(save());
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
  function save() {
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
    })
      .then((result) => {
        saving = false;
        if (result.status === 401) { toLogin(); return; }
        if (result.status === 200) {
          state.version = result.data.doc_version;
          state.title = result.data.title;
          state.doc = doc;
          savedSerial = mySerial;
          setText(titleEl, state.title);
          if (serial !== savedSerial) {
            // 보내는 사이에 또 고쳐졌다 — 아직 「저장됨」이 아니다.
            badge('저장 중…', '');
            return save();
          }
          firstDirtyAt = 0;
          badge('저장됨', 'badge--ok');
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
    const closeButton = conflictDialog.querySelector('[data-conflict-close]');
    if (closeButton) {
      closeButton.addEventListener('click', () => conflictDialog.close());
    }
    const reload = document.getElementById('conflict-reload');
    if (reload) {
      reload.addEventListener('click', () => {
        conflictDialog.close();
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

  function startHeartbeat() {
    stopHeartbeat();
    const beat = () => {
      api('POST', '/api/pages/' + pageId + '/heartbeat', {})
        .then((result) => { if (result.status === 200) { showEditors(result.data.editors); } })
        .catch(() => {});
    };
    beat();
    heartbeat = window.setInterval(beat, 30000);
  }

  function stopHeartbeat() {
    if (heartbeat !== null) { window.clearInterval(heartbeat); heartbeat = null; }
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
    const cancel = deleteDialog.querySelector('[data-delete-close]');
    if (cancel) { cancel.addEventListener('click', () => deleteDialog.close()); }
    const confirmButton = document.getElementById('delete-confirm');
    if (confirmButton) {
      confirmButton.addEventListener('click', () => {
        deleteDialog.close();
        confirmButton.disabled = true;
        api('DELETE', '/api/pages/' + pageId, {})
          .then((result) => {
            if (result.status === 401) { toLogin(); return; }
            if (result.status === 200) { window.location.assign(COMMUNITY_BASE + '/'); return; }
            setText(msg, errorMessage(result.data));
            confirmButton.disabled = false;
          })
          .catch(() => { setText(msg, GENERIC_ERROR); confirmButton.disabled = false; });
      });
    }
  }

  // 창을 닫거나 다른 탭으로 옮겨 갈 때 남은 변경을 보낸다 (지시서 4장).
  window.addEventListener('beforeunload', () => {
    if (state.editing) { flushSave(); }
  });
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden' && state.editing) { flushSave(); }
  });

  load().catch(() => setText(msg, GENERIC_ERROR));
}

/* ─── 시작 ───────────────────────────────────────────────────── */

let booted = false;

function boot() {
  if (booted) { return; }
  booted = true;

  const page = body && body.dataset ? body.dataset.page : '';
  if (page !== 'community-home' && page !== 'community-page') { return; }
  if (COMMUNITY_BASE === '' || APP_BASE === '') { return; }

  // CSRF 토큰은 t-box 창구가 준다 — 한 앱·한 세션이라 같은 토큰이 커뮤니티 창구에도 통한다.
  fetch(APP_BASE + '/api/me', { credentials: 'same-origin', cache: 'no-store' })
    .then((response) => {
      if (response.status === 401) { toLogin(); return null; }
      if (!response.ok) { return null; }
      return readJson(response).then((me) => {
        if (typeof me.csrf_token !== 'string') { return null; }
        csrfToken = me.csrf_token;
        bindNewPage();
        if (page === 'community-home') {
          initHome();
        } else {
          // 4-B — 페이지 화면에서도 셸 왼쪽의 「팀 공간」 묶음을 채운다(홈은 initHome 이 한다).
          loadSpaces();
          initPage();
        }
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
