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

/* ─── 내 공간 (community-home) ───────────────────────────────── */

function initHome() {
  const msg = document.getElementById('msg');
  const list = document.getElementById('community-list');
  const empty = document.getElementById('community-empty');
  const count = document.getElementById('community-count');
  const search = document.getElementById('community-search');
  let timer = null;

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
    a.appendChild(when);
    return a;
  }

  function load(q) {
    const query = q && q !== '' ? '?q=' + encodeURIComponent(q) : '';
    return api('GET', '/api/pages' + query).then((result) => {
      if (result.status === 401) { toLogin(); return; }
      if (result.status !== 200) { setText(msg, errorMessage(result.data)); return; }
      setText(msg, '');
      clear(list);
      const pages = Array.isArray(result.data.pages) ? result.data.pages : [];
      for (const page of pages) { list.appendChild(card(page)); }
      show(empty, pages.length === 0);
      setText(count, pages.length === 0 ? '' : '페이지 ' + String(result.data.total) + '개');
    });
  }

  // 개인 공간은 첫 방문에 서버가 만든다(「시작하기」 페이지와 함께).
  api('GET', '/api/me/space')
    .then((result) => {
      if (result.status === 401) { toLogin(); return; }
      if (result.status !== 200) { setText(msg, errorMessage(result.data)); return; }
      return load('');
    })
    .catch(() => setText(msg, GENERIC_ERROR));

  if (search) {
    search.addEventListener('input', () => {
      window.clearTimeout(timer);
      timer = window.setTimeout(() => { load(search.value).catch(() => setText(msg, GENERIC_ERROR)); }, 300);
    });
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
      api('POST', '/api/pages', {})
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

/* ─── 「블록 추가」 창 (지시서 2장 — `/` 슬래시 메뉴를 대신한다) ─── */

function createBlockDialog(getView) {
  const dialog = document.getElementById('block-dialog');
  const listEl = document.getElementById('block-list');
  const titleEl = document.getElementById('block-dialog-title');
  const nameEl = document.getElementById('block-preview-name');
  const descEl = document.getElementById('block-desc');
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
    clear(sampleEl);
    sampleEl.appendChild(renderDoc(entry.sample));
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
    if (mode === 'change') { changeBlock(view, entry, targetPos); } else { insertBlock(view, entry); }
    dialog.close();
    view.focus();
  }

  /** 커서가 있던 블록 **다음**에 새 블록을 넣고 커서를 그리로 옮긴다 */
  function insertBlock(view, entry) {
    const node = makeBlockNode(entry);
    if (!node) { return; }
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
  return { open: open, close: () => { if (dialog.open) { dialog.close(); } } };
}

/* ─── 인라인 툴바 — 「색」 「배경」 「링크」 셋 (지시서 1장) ───── */

function createToolbar(view) {
  const wrap = document.getElementById('inline-toolbar');
  if (!wrap) { return null; }

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
  row.appendChild(labelButton('tool-color', '색', () => togglePanel('fg')));
  row.appendChild(labelButton('tool-bg', '배경', () => togglePanel('bg')));
  row.appendChild(labelButton('tool-link', '링크', () => togglePanel('link')));
  wrap.appendChild(row);

  /* 자리는 **CSS 가 정한다**(편집 영역 위에 붙는 줄). 좌표를 JS 로 써 넣지 않는다
     (DESIGN 0장 2 — 인라인 style 0). 보이고 안 보이고만 여기서 정한다.
     ★ `view.hasFocus()` 는 보지 않는다 — 링크 입력칸을 누르는 순간 편집기가 초점을 잃어
       툴바가 사라지던 것이 관문 ③ 의 「링크가 안 걸린다」였다. */
  function refresh() {
    if (anyPanelOpen()) { show(wrap, true); return; }
    if (view.state.selection.empty) { show(wrap, false); return; }
    show(wrap, true);
  }

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
  const conflictDialog = document.getElementById('conflict-dialog');

  let state = { doc: null, version: 0, title: '', canEdit: false, editing: false };
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
    if (state.doc) { docEl.appendChild(renderDoc(state.doc)); }
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
      childrenBox.appendChild(a);
    }
    show(childrenWrap, list.length > 0);
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
      setText(titleEl, page.title);
      document.title = page.title + ' · 커뮤니티';
      setText(crumbEl, page.parent ? '상위: ' + page.parent.title : '');
      drawRead();
      drawChildren(result.data.children);
      showEditors(result.data.editors);
      show(editButton, state.canEdit && !state.editing);
      show(deleteButton, state.canEdit);
      setText(msg, '');
    });
  }

  function showEditors(editors) {
    const list = Array.isArray(editors) ? editors : [];
    if (list.length === 0) { show(editorsBadge, false); return; }
    const names = list.map((e) => e.username).join(', ');
    setText(editorsBadge, names + ' 님도 보는 중');
    show(editorsBadge, true);
  }

  const blockDialog = createBlockDialog(() => view);

  /* ── 편집 모드 ── */

  function startEditing() {
    // ★ 편집기는 **한 번만** 만든다 (지시서 4장) — 두 벌이 겹치면 친 글자가 사라진다.
    if (state.editing || view !== null) { return; }

    let pmDoc;
    try {
      pmDoc = fromDoc(state.doc);
    } catch (e) {
      setText(msg, e && e.message ? e.message : GENERIC_ERROR);
      return;
    }

    const editorState = EditorState.create({
      schema: schema,
      doc: schema.nodeFromJSON(pmDoc),
      plugins: [
        buildInputRules(),
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
        blockHandles
      ]
    });

    view = new EditorView(editorEl, {
      state: editorState,
      attributes: { class: 'doc editor' },
      dispatchTransaction(tr) {
        const next = view.state.apply(tr);
        view.updateState(next);
        if (toolbar) { toolbar.refresh(); }
        if (tr.docChanged) { markDirty(); }
      }
    });

    toolbar = createToolbar(view);

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
        if (page === 'community-home') { initHome(); } else { initPage(); }
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
