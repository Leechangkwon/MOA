/**
 * public/assets/community/editor.js — 커뮤니티 화면의 동작 한 벌 (모듈 스크립트)
 *
 * 요구사항: C:F-15 · C:F-16 · C:F-17(최소) · C:S-11 · C:S-12,
 *           ../../../../community/ARCHITECTURE.md 12.4 · 13.3 · 14장 · 21.3,
 *           DESIGN.md 2.14 · 6장.
 *
 * 다루는 화면 둘 (서버가 `<body data-page>` 로 알려 준다 — app.js 는 이 두 키를 무시한다)
 *   community-home  내 공간 — 목록·찾기·새 페이지
 *   community-page  페이지 — 읽기(렌더러) ↔ 편집(ProseMirror), 자동 저장, 409 안내, 삭제
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
import { EditorView } from './vendor/pm/prosemirror-view.js';
import { keymap } from './vendor/pm/prosemirror-keymap.js';
import { history, undo, redo } from './vendor/pm/prosemirror-history.js';
import { baseKeymap, toggleMark, setBlockType, chainCommands } from './vendor/pm/prosemirror-commands.js';
import { inputRules, textblockTypeInputRule, InputRule } from './vendor/pm/prosemirror-inputrules.js';
import { dropCursor } from './vendor/pm/prosemirror-dropcursor.js';
import { gapCursor } from './vendor/pm/prosemirror-gapcursor.js';

import { renderDoc } from './doc-render.js';
import { schema, toDoc, fromDoc, uuid4, colorAllowed, schemeOkForEditor, COLORS, BLOCK_MENU } from './editor-schema.js';

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
      while (list.firstChild) { list.removeChild(list.firstChild); }
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

/* ─── 페이지 화면 (community-page) ───────────────────────────── */

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
    const para = schema.nodes.paragraph.create({ id: uuid4(), color: null, align: 'left', size: 'normal' });
    const tr = state.tr.replaceWith(from, to, [hr, para]);
    return tr.setSelection(TextSelection.create(tr.doc, from + hr.nodeSize + 1));
  }));

  return inputRules({ rules: rules });
}

/** 지금 커서가 있는 블록을 다른 종류로 바꾼다 (슬래시 메뉴가 쓴다) */
function turnInto(view, type, attrs) {
  const nodeType = schema.nodes[type];
  if (!nodeType) { return; }

  if (type === 'divider' || type === 'toc') {
    const { $from } = view.state.selection;
    const from = $from.before($from.depth);
    const to = $from.after($from.depth);
    const atom = nodeType.create({ id: uuid4() });
    const para = schema.nodes.paragraph.create({ id: uuid4(), color: null, align: 'left', size: 'normal' });
    const tr = view.state.tr.replaceWith(from, to, [atom, para]);
    view.dispatch(tr.setSelection(TextSelection.create(tr.doc, from + atom.nodeSize + 1)));
    view.focus();
    return;
  }

  const keep = view.state.selection.$from.parent.attrs || {};
  const merged = Object.assign(
    { id: typeof keep.id === 'string' ? keep.id : uuid4(), color: keep.color || null, align: keep.align || 'left', size: keep.size || 'normal' },
    attrs || {}
  );
  setBlockType(nodeType, merged)(view.state, view.dispatch);
  view.focus();
}

/** 커서 앞의 `/낱말` 을 지운다 (슬래시 메뉴가 항목을 고른 직후) */
function deleteSlashQuery(view, length) {
  const { from } = view.state.selection;
  view.dispatch(view.state.tr.delete(from - length, from));
}

/* ── 슬래시 메뉴 ───────────────────────────────────────────── */

function createSlashMenu(view) {
  const wrap = document.getElementById('slash-menu');
  const list = document.getElementById('slash-list');
  if (!wrap || !list) { return null; }

  let open = false;
  let items = [];
  let index = 0;
  let queryLength = 0;

  function draw() {
    while (list.firstChild) { list.removeChild(list.firstChild); }
    items.forEach((item, i) => {
      const li = document.createElement('li');
      li.className = 'list__item' + (i === index ? ' is-active' : '');
      li.setAttribute('role', 'option');
      li.setAttribute('aria-selected', i === index ? 'true' : 'false');

      const head = document.createElement('span');
      head.className = 'list__head';
      const strong = document.createElement('strong');
      strong.textContent = item.label;
      const hint = document.createElement('span');
      hint.className = 'muted';
      hint.textContent = item.hint;
      head.appendChild(strong);
      head.appendChild(hint);
      li.appendChild(head);

      li.addEventListener('mousedown', (event) => {
        event.preventDefault();
        choose(i);
      });
      list.appendChild(li);
    });
  }

  function openWith(query) {
    const needle = query.toLowerCase();
    items = BLOCK_MENU.filter((item) =>
      needle === '' || item.label.toLowerCase().indexOf(needle) !== -1 || item.keywords.indexOf(needle) !== -1
    );
    if (items.length === 0) { close(); return; }
    queryLength = query.length + 1;   // `/` 한 글자를 더한다
    index = 0;
    open = true;
    show(wrap, true);
    draw();
  }

  function close() {
    open = false;
    items = [];
    show(wrap, false);
  }

  function choose(i) {
    const item = items[i];
    if (!item) { close(); return; }
    const length = queryLength;
    close();
    deleteSlashQuery(view, length);
    turnInto(view, item.type, item.attrs);
  }

  function onKeyDown(event) {
    if (!open) { return false; }
    if (event.key === 'ArrowDown') { index = (index + 1) % items.length; draw(); return true; }
    if (event.key === 'ArrowUp') { index = (index - 1 + items.length) % items.length; draw(); return true; }
    if (event.key === 'Enter' || event.key === 'Tab') { choose(index); return true; }
    if (event.key === 'Escape') { close(); return true; }
    return false;
  }

  /** 문서가 바뀔 때마다 커서 앞을 보고 열고 닫는다 */
  function refresh() {
    const { $from, empty } = view.state.selection;
    if (!empty || !$from.parent.isTextblock || $from.parent.type.name === 'code_block') { close(); return; }
    const before = $from.parent.textBetween(0, $from.parentOffset, undefined, '￼');
    const match = before.match(/\/([^/\s]*)$/);
    if (match === null) { close(); return; }
    openWith(match[1]);
  }

  return { onKeyDown: onKeyDown, refresh: refresh, close: close, isOpen: () => open };
}

/* ── 인라인 툴바 ───────────────────────────────────────────── */

function createToolbar(view) {
  const wrap = document.getElementById('inline-toolbar');
  if (!wrap) { return null; }

  let palette = null;
  let linkRow = null;

  function iconButton(label, onClick, text) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'btn btn--icon btn--plain';
    button.setAttribute('aria-label', label);
    button.textContent = text;
    button.addEventListener('mousedown', (event) => { event.preventDefault(); onClick(); });
    return button;
  }

  function mark(name, attrs) {
    toggleMark(schema.marks[name], attrs)(view.state, view.dispatch);
    view.focus();
  }

  function buildPalette() {
    const panel = document.createElement('div');
    panel.className = 'panel editor-palette';
    panel.hidden = true;
    for (const group of [{ suffix: '', label: '글자색' }, { suffix: '_bg', label: '배경색' }]) {
      const row = document.createElement('div');
      row.className = 'editor-palette__row';
      const title = document.createElement('span');
      title.className = 'muted';
      title.textContent = group.label;
      row.appendChild(title);
      for (const color of COLORS) {
        const value = color + group.suffix;
        const swatch = document.createElement('button');
        swatch.type = 'button';
        swatch.className = 'btn btn--icon btn--plain ' + (group.suffix === '' ? 'nt-' + color : 'nt-' + color + '-bg');
        swatch.setAttribute('aria-label', group.label + ' ' + color);
        swatch.textContent = '●';
        swatch.addEventListener('mousedown', (event) => {
          event.preventDefault();
          if (!colorAllowed(value)) { return; }
          mark('color', { color: value });
          panel.hidden = true;
        });
        row.appendChild(swatch);
      }
      panel.appendChild(row);
    }
    return panel;
  }

  function buildLinkRow() {
    const row = document.createElement('div');
    row.className = 'editor-palette__row';
    row.hidden = true;

    const input = document.createElement('input');
    input.type = 'text';
    input.setAttribute('placeholder', 'https://…');
    input.setAttribute('maxlength', '2048');
    input.setAttribute('spellcheck', 'false');

    const apply = document.createElement('button');
    apply.type = 'button';
    apply.className = 'btn btn--small';
    apply.textContent = '링크 걸기';

    const drop = document.createElement('button');
    drop.type = 'button';
    drop.className = 'btn btn--small btn--quiet';
    drop.textContent = '링크 빼기';

    apply.addEventListener('mousedown', (event) => {
      event.preventDefault();
      const href = input.value.trim();
      if (!schemeOkForEditor(href)) {
        setText(document.getElementById('msg'), 'http, https, 메일 주소만 넣을 수 있습니다');
        return;
      }
      toggleMark(schema.marks.link, { href: href })(view.state, view.dispatch);
      row.hidden = true;
      view.focus();
    });
    drop.addEventListener('mousedown', (event) => {
      event.preventDefault();
      const { from, to } = view.state.selection;
      view.dispatch(view.state.tr.removeMark(from, to, schema.marks.link));
      row.hidden = true;
      view.focus();
    });

    row.appendChild(input);
    row.appendChild(apply);
    row.appendChild(drop);
    return row;
  }

  const bar = document.createElement('div');
  bar.className = 'editor-toolbar__row';
  bar.appendChild(iconButton('굵게', () => mark('bold'), 'B'));
  bar.appendChild(iconButton('기울임', () => mark('italic'), 'I'));
  bar.appendChild(iconButton('밑줄', () => mark('underline'), 'U'));
  bar.appendChild(iconButton('취소선', () => mark('strike'), 'S'));
  bar.appendChild(iconButton('코드', () => mark('code'), '</>'));
  bar.appendChild(iconButton('색', () => { if (palette) { palette.hidden = !palette.hidden; } }, '색'));
  bar.appendChild(iconButton('링크', () => { if (linkRow) { linkRow.hidden = !linkRow.hidden; } }, '링크'));

  palette = buildPalette();
  linkRow = buildLinkRow();
  wrap.appendChild(bar);
  wrap.appendChild(palette);
  wrap.appendChild(linkRow);

  /* 자리는 **CSS 가 정한다**(편집 영역 위에 붙는 줄). 좌표를 JS 로 써 넣지 않는 이유:
     화면 규칙이 인라인 style 을 쓰지 않기로 했고(DESIGN 0장 2), 글자를 고르면 늘 같은 자리에
     뜨는 편이 손이 덜 간다. 보이고 안 보이고만 여기서 정한다. */
  function refresh() {
    const { empty } = view.state.selection;
    if (empty || !view.hasFocus()) {
      show(wrap, false);
      palette.hidden = true;
      linkRow.hidden = true;
      return;
    }
    show(wrap, true);
  }

  return { refresh: refresh, hide: () => show(wrap, false) };
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
  const deleteButton = document.getElementById('page-delete');
  const saveState = document.getElementById('page-save-state');
  const editorsBadge = document.getElementById('page-editors');
  const childrenWrap = document.getElementById('page-children-wrap');
  const childrenBox = document.getElementById('page-children');
  const conflictDialog = document.getElementById('conflict-dialog');

  let state = { doc: null, version: 0, title: '', canEdit: false, editing: false };
  let view = null;
  let slash = null;
  let toolbar = null;
  let saveTimer = null;
  let firstDirtyAt = 0;
  let saving = false;
  let savePending = false;   // 보내는 중에 또 고쳐졌나 (끝나면 한 번 더 보낸다)
  let pendingConflict = null;
  let heartbeat = null;

  function badge(text, kind) {
    if (!saveState) { return; }
    saveState.className = 'badge' + (kind ? ' ' + kind : '');
    setText(saveState, text);
    show(saveState, text !== '');
  }

  function drawRead() {
    while (docEl.firstChild) { docEl.removeChild(docEl.firstChild); }
    if (state.doc) { docEl.appendChild(renderDoc(state.doc)); }
  }

  function drawChildren(children) {
    while (childrenBox.firstChild) { childrenBox.removeChild(childrenBox.firstChild); }
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
      show(editButton, state.canEdit);
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

  /* ── 편집 모드 ── */

  function startEditing() {
    let pmDoc;
    try {
      pmDoc = fromDoc(state.doc);
    } catch (e) {
      setText(msg, e && e.message ? e.message : GENERIC_ERROR);
      return;
    }

    slash = null;
    toolbar = null;

    const editorState = EditorState.create({
      schema: schema,
      doc: schema.nodeFromJSON(pmDoc),
      plugins: [
        buildInputRules(),
        keymap({
          'Mod-z': undo,
          'Mod-y': redo,
          'Shift-Mod-z': redo,
          'Mod-b': toggleMark(schema.marks.bold),
          'Mod-i': toggleMark(schema.marks.italic),
          'Mod-u': toggleMark(schema.marks.underline),
          // 목록·인용 한가운데에서 Backspace 로 보통 글로 되돌린다.
          Backspace: chainCommands(
            (s, dispatch) => {
              const { $from, empty } = s.selection;
              if (!empty || $from.parentOffset !== 0) { return false; }
              const name = $from.parent.type.name;
              if (name === 'paragraph' || name === 'doc') { return false; }
              return setBlockType(schema.nodes.paragraph, $from.parent.attrs)(s, dispatch);
            },
            baseKeymap.Backspace
          ),
          // 3단계에는 들여쓰기가 없다. 탭으로 초점이 새어 나가지 않게 여기서 멈춘다.
          Tab: () => true,
          'Shift-Tab': () => true
        }),
        keymap(baseKeymap),
        history(),
        dropCursor(),
        gapCursor(),
        uniqueBlockIds
      ]
    });

    view = new EditorView(editorEl, {
      state: editorState,
      attributes: { class: 'doc editor' },
      handleKeyDown(v, event) {
        return slash ? slash.onKeyDown(event) : false;
      },
      dispatchTransaction(tr) {
        const next = view.state.apply(tr);
        view.updateState(next);
        if (slash) { slash.refresh(); }
        if (toolbar) { toolbar.refresh(); }
        if (tr.docChanged) { markDirty(); }
      }
    });

    slash = createSlashMenu(view);
    toolbar = createToolbar(view);

    show(docEl, false);
    show(editorEl, true);
    show(titleField, true);
    if (titleInput) { titleInput.value = state.title; }
    show(editButton, false);
    show(doneButton, true);
    state.editing = true;
    badge('저장됨', 'badge--ok');
    view.focus();
    startHeartbeat();
  }

  function stopEditing() {
    flushSave().then(() => {
      if (view) { view.destroy(); view = null; }
      if (toolbar) { toolbar.hide(); }
      if (slash) { slash.close(); }
      stopHeartbeat();
      state.editing = false;
      show(editorEl, false);
      show(titleField, false);
      show(docEl, true);
      show(doneButton, false);
      show(editButton, state.canEdit);
      drawRead();
      setText(titleEl, state.title);
    });
  }

  /* ── 자동 저장 (1.5초 멈춤 · 늦어도 10초) ── */

  function markDirty() {
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

  /** 남은 것을 지금 보낸다. 보내는 중이었다면 그 뒤에 이어지는 저장까지 기다린다. */
  function flushSave() {
    window.clearTimeout(saveTimer);
    if (!saving && !savePending && firstDirtyAt === 0
        && (!titleInput || titleInput.value === state.title)) {
      return Promise.resolve();
    }
    return Promise.resolve(save());
  }

  /**
   * 저장 한 번.
   *
   * ★ 보내는 중에 또 고쳐지면 **그 변경을 잃지 않는다** (관문 ③ 실측 2026-09-22).
   *   전에는 `if (saving) return;` 한 줄이라, 요청이 날아가 있는 1초 남짓 사이에 친 글자가
   *   조용히 사라졌다 — 배지는 앞선 요청이 끝나며 「저장됨」으로 바뀌어서 **사용자는 저장된
   *   줄 알았다**. 이제 그 사이의 변경은 `savePending` 에 표시해 두고, 끝나는 즉시 한 번 더 보낸다.
   *   409(충돌)일 때는 이어서 보내지 않는다 — 무엇을 할지는 사람이 모달에서 고른다.
   */
  function save() {
    if (saving) { savePending = true; return Promise.resolve(); }
    saving = true;
    savePending = false;

    const doc = currentDoc();
    const title = titleInput && titleInput.value.trim() !== '' ? titleInput.value.trim() : state.title;
    const startedAt = firstDirtyAt;

    /** 보내는 동안 또 고쳐졌으면 한 번 더 — 아니면 여기서 끝난다 */
    const done = () => {
      saving = false;
      if (savePending) {
        savePending = false;
        return save();
      }
      return undefined;
    };

    return api('PATCH', '/api/pages/' + pageId, {
      doc_version: state.version,
      doc: doc,
      title: title
    })
      .then((result) => {
        if (result.status === 401) { saving = false; toLogin(); return; }
        if (result.status === 200) {
          state.version = result.data.doc_version;
          state.title = result.data.title;
          state.doc = doc;
          // 보내는 사이에 또 고쳐졌다면 아직 「깨끗」하지 않다 — 그때는 시작 시각을 살려 둔다.
          if (!savePending && firstDirtyAt === startedAt) { firstDirtyAt = 0; }
          setText(titleEl, state.title);
          badge(savePending ? '저장 중…' : '저장됨', savePending ? '' : 'badge--ok');
          return done();
        }
        if (result.status === 409) {
          savePending = false;
          saving = false;
          badge('충돌', 'badge--admin-only');
          pendingConflict = { doc: doc, title: title, server: result.data };
          openConflict();
          return;
        }
        savePending = false;
        saving = false;
        badge('저장 안 됨', 'badge--admin-only');
        setText(msg, errorMessage(result.data));
        if (Array.isArray(result.data.errors) && result.data.errors.length > 0) {
          setText(msg, errorMessage(result.data) + ' — ' + result.data.errors[0].path + ' (' + result.data.errors[0].reason + ')');
        }
      })
      .catch(() => {
        savePending = false;
        saving = false;
        badge('저장 안 됨', 'badge--admin-only');
        setText(msg, GENERIC_ERROR);
      });
  }

  /* ── 409 안내 (ARCH 14.3) ── */

  function openConflict() {
    if (!conflictDialog || typeof conflictDialog.showModal !== 'function') {
      setText(msg, '다른 곳에서 먼저 저장되었습니다. 새로 고침하세요.');
      return;
    }
    conflictDialog.showModal();
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
  if (titleInput) {
    titleInput.addEventListener('input', () => { markDirty(); });
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

  window.addEventListener('beforeunload', () => {
    if (state.editing && firstDirtyAt !== 0) { save(); }
  });

  load().catch(() => setText(msg, GENERIC_ERROR));
}

/* ─── 시작 ───────────────────────────────────────────────────── */

function boot() {
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
