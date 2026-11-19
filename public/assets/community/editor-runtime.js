/** Editing-only toolkit. Loaded on an explicit edit action; no shell boot side effects. */
import { createTableEditor } from './table-editor.js?v=a9fddd18fefd';
import { EditorState, NodeSelection, Plugin, PluginKey, TextSelection } from './vendor/pm/prosemirror-state.js?v=5823578fa61a';
import { Decoration, DecorationSet, EditorView } from './vendor/pm/prosemirror-view.js?v=5823578fa61a';
import { keymap } from './vendor/pm/prosemirror-keymap.js?v=5823578fa61a';
import { history, redo, undo } from './vendor/pm/prosemirror-history.js?v=5823578fa61a';
import { baseKeymap, chainCommands } from './vendor/pm/prosemirror-commands.js?v=5823578fa61a';
import { InputRule, inputRules, textblockTypeInputRule } from './vendor/pm/prosemirror-inputrules.js?v=5823578fa61a';
import { dropCursor } from './vendor/pm/prosemirror-dropcursor.js?v=5823578fa61a';
import { gapCursor } from './vendor/pm/prosemirror-gapcursor.js?v=5823578fa61a';
import { suggestionTypeAllowed } from './suggestions.js?v=a9fddd18fefd';
import { embedHost, imageWidthPct, renderDoc, renderFileBlock } from './doc-render.js?v=a9fddd18fefd';
import { BLOCK_TYPES, COLORS, clipboardDomParser, colorAllowed, editorDomParser, fromDoc, mentionAllowed, schema, schemeOkForEditor, toDoc, uuid4 } from './editor-schema.js?v=a9fddd18fefd';
import { loadAutomations } from './automations.js?v=a9fddd18fefd';

export function createEditorRuntime(deps) {
  const { APP_BASE, ATT_UUID_RE, COMMUNITY_BASE, GENERIC_ERROR, UPLOAD_IMAGE_MIMES, api, attachmentPreviews, body, clear, currentPageId, errorMessage, liveImageViews, makeIcon, personLabel, readJson, readPreview, sendAttachment, setText, show, toLogin, watchAttachments, shared } = deps;
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

/* ─── 편집 중 그림 · 크기 끌기 (Q:F-12 U4-3 · WK-28 · DESIGN 2.26) ─────────────────────────────
 *
 * 편집기 안 그림 블록을 요약 카드가 아니라 **그림 자체**로 그린다 — 읽기 렌더러의 `renderFileBlock` 한 벌(같은 figure · 같은 주소 ·
 * 검사 중이면 「검사 중이에요」 상자 · 받을 수 없으면 카드)을 빌린다(렌더러 두 벌 0). 아래 설명 한 줄(없으면 `.muted` 「설명을 더하려면 두 번 누르세요」).
 * 두 번 누르기 = 지금 「블록 고치기」 창(설명 · 폭). 페이지 편집기와 작업 칸 편집기(mountPaneEditor)가 **같은 함수**를 쓴다.
 *
 * 크기 — 그림을 고르면(ProseMirror 노드 선택) 오른쪽 가장자리 가운데 손잡이 `.doc-image__resize`(role=slider) · 끌면 본문 폭의 10~100%, 5% 간격으로
 * 맞춰지고(클래스 `doc-image--p{N}` 만 바꾼다 — 인라인 style 0) 놓으면 `width_pct` 를 노드 값으로 넣는다 → 편집기의 보통 저장 창구가 저장한다.
 * 키보드: 그림을 고른 채 `Alt+←/→` 5%(`imageWidthStep`). 쓸 수 없는 사람에게는 편집기 자체가 없다(손잡이 0) · 제안 중에는 손잡이 · 단축키 0.
 */
const IMAGE_PCT_MIN = 10;
const IMAGE_PCT_MAX = 100;
const IMAGE_HINT = '설명을 더하려면 두 번 누르세요';

function snapImagePct(v) {
  const n = Math.round(Number(v) / 5) * 5;
  return Math.min(IMAGE_PCT_MAX, Math.max(IMAGE_PCT_MIN, Number.isFinite(n) ? n : 60));
}

/** 지금 그려진 그림 폭(본문 폭 대비 %, 5 단위) — 옛 넷(small · normal · wide · full)에서 시작할 때 */
function measuredImagePct(dom) {
  const fig = dom ? dom.querySelector('.doc-image') : null;
  const host = dom ? dom.clientWidth : 0;
  if (!fig || host <= 0) { return 60; }
  return snapImagePct(fig.getBoundingClientRect().width / host * 100);
}

function setImageWidthClass(fig, pct) {
  for (const c of Array.from(fig.classList)) { if (c.indexOf('doc-image--') === 0) { fig.classList.remove(c); } }
  fig.classList.add('doc-image--p' + String(pct));
}

/** Alt+←/→ — 고른 그림의 폭을 5% 바꾼다(고른 것이 그림이 아니면 다른 키 동작에 넘긴다) */
function imageWidthStep(delta) {
  return (state, dispatch, view) => {
    const sel = state.selection;
    if (!(sel instanceof NodeSelection) || sel.node.type !== schema.nodes.image) { return false; }
    if (!dispatch) { return true; }
    let cur = imageWidthPct(sel.node.attrs.width_pct);
    if (cur === null) { cur = view ? measuredImagePct(view.nodeDOM(sel.from)) : 60; }
    const next = snapImagePct(cur + delta);
    if (next === sel.node.attrs.width_pct) { return true; }
    const tr = state.tr.setNodeMarkup(sel.from, null, Object.assign({}, sel.node.attrs, { width_pct: next }));
    tr.setSelection(NodeSelection.create(tr.doc, sel.from));
    dispatch(tr.scrollIntoView());
    return true;
  };
}
const imageWidthKeys = () => keymap({ 'Alt-ArrowLeft': imageWidthStep(-5), 'Alt-ArrowRight': imageWidthStep(5) });

/**
 * @param {{ctx: Function, resizable: Function}} cfg  ctx() = 읽기 지도(file_titles · attachment_titles · attachment_page · base · tbox_base) ·
 *        resizable() = 손잡이를 둘까(고칠 수 있고 제안 중이 아님)
 */
function imageViews(cfg) {
  return new Plugin({
    props: {
      nodeViews: {
        image(initial, view, getPos) {
          let node = initial;
          let fig = null;
          let handle = null;
          let sizeEl = null;
          const dom = document.createElement('div');
          dom.className = 'doc-atom';
          dom.setAttribute('data-atom', 'image');
          dom.setAttribute('contenteditable', 'false');
          // 받기 카드(검사가 끝나지 않았거나 그림이 아닌 첨부)의 링크는 편집 중에는 따라가지 않는다(고르기 · 두 번 누르기만)
          dom.addEventListener('click', (e) => { if (e.target instanceof Element && e.target.closest('a')) { e.preventDefault(); } });
          const pctNow = () => { const v = imageWidthPct(node.attrs.width_pct); return v === null ? measuredImagePct(dom) : v; };
          const paintSize = (pct) => { if (sizeEl) { sizeEl.textContent = String(pct) + '%'; } if (handle) { handle.setAttribute('aria-valuenow', String(pct)); } };
          function commit(pct) {
            const pos = typeof getPos === 'function' ? getPos() : null;
            if (typeof pos !== 'number' || view.isDestroyed) { return; }
            if (pct === node.attrs.width_pct) { draw(); return; }
            const tr = view.state.tr.setNodeMarkup(pos, null, Object.assign({}, node.attrs, { width_pct: pct }));
            tr.setSelection(NodeSelection.create(tr.doc, pos));
            view.dispatch(tr);
          }
          function startDrag(e) {
            if (e.button !== 0 || !fig) { return; }
            e.preventDefault();
            e.stopPropagation();
            const host = dom.clientWidth || 1;
            const left = fig.getBoundingClientRect().left;
            let pct = pctNow();
            document.body.setAttribute('data-image-resizing', '');
            try { handle.setPointerCapture(e.pointerId); } catch (err) { /* 붙잡기는 편의 */ }
            const move = (ev) => {
              pct = snapImagePct((ev.clientX - left) / host * 100);
              setImageWidthClass(fig, pct);
              paintSize(pct);
            };
            const up = () => {
              handle.removeEventListener('pointermove', move);
              handle.removeEventListener('pointerup', up);
              handle.removeEventListener('pointercancel', up);
              document.body.removeAttribute('data-image-resizing');
              commit(pct);
            };
            handle.addEventListener('pointermove', move);
            handle.addEventListener('pointerup', up);
            handle.addEventListener('pointercancel', up);
          }
          function draw() {
            while (dom.firstChild) { dom.removeChild(dom.firstChild); }
            const cap = typeof node.attrs.caption === 'string' ? node.attrs.caption : '';
            const block = { type: 'image', file_ref: node.attrs.file_ref, caption: cap === '' ? [] : [{ t: cap, m: {} }], width: node.attrs.width };
            if (imageWidthPct(node.attrs.width_pct) !== null) { block.width_pct = node.attrs.width_pct; }
            let shown;
            // 카페 C4 — 편집기에서만 올린 사람의 브라우저 안 사본(검사 중 그림)을 렌더러에 넘긴다(읽기 화면 · 다른 사람에게는 없다)
            const ctx = Object.assign({}, cfg.ctx() || {}, { attachment_previews: attachmentPreviews });
            try { shown = renderFileBlock(block, null, ctx); } catch (err) { shown = document.createElement('div'); shown.className = 'doc-file'; }
            dom.appendChild(shown);
            fig = shown.matches('figure.doc-image') ? shown : null;
            for (const img of shown.querySelectorAll('img')) { img.draggable = false; }
            if (cap === '') {
              const hint = document.createElement(fig ? 'figcaption' : 'p');
              hint.className = (fig ? 'doc-image__caption' : 'doc-file__caption') + ' muted';
              hint.textContent = IMAGE_HINT;
              shown.appendChild(hint);
            }
            handle = null;
            sizeEl = null;
            if (fig && cfg.resizable()) {
              handle = document.createElement('span');
              handle.className = 'doc-image__resize';
              handle.setAttribute('role', 'slider');
              handle.setAttribute('aria-label', '그림 폭 — 끌거나 Alt+←/→');
              handle.setAttribute('aria-valuemin', String(IMAGE_PCT_MIN));
              handle.setAttribute('aria-valuemax', String(IMAGE_PCT_MAX));
              handle.addEventListener('pointerdown', startDrag);
              sizeEl = document.createElement('span');
              sizeEl.className = 'doc-image__size';
              sizeEl.setAttribute('aria-hidden', 'true');
              fig.append(handle, sizeEl);
              const v = imageWidthPct(node.attrs.width_pct);
              if (v !== null) { paintSize(v); }
            }
          }
          draw();
          // 카페 C4 — 검사가 끝나면(watchAttachments) 이 부품만 다시 그린다 · 부품이 걷히면 빠진다
          const live = { ref: () => node.attrs.file_ref, draw: () => { draw(); if (dom.classList.contains('ProseMirror-selectednode') && handle) { paintSize(pctNow()); } } };
          liveImageViews.add(live);
          return {
            dom: dom,
            update(next) {
              if (next.type !== node.type) { return false; }
              const same = JSON.stringify(next.attrs) === JSON.stringify(node.attrs);
              node = next;
              if (!same) { draw(); }
              return true;
            },
            destroy() { liveImageViews.delete(live); },
            selectNode() { dom.classList.add('ProseMirror-selectednode'); if (handle) { paintSize(pctNow()); } },
            deselectNode() { dom.classList.remove('ProseMirror-selectednode'); },
            stopEvent(e) { return !!(handle && e.target instanceof Node && handle.contains(e.target)); },
            ignoreMutation: () => true
          };
        }
      }
    }
  });
}

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

  /* 사용감 라운드 1 UX-7 — 창의 **보이는 순서**는 자주 쓰는 것 먼저: 본문 · 제목 1 · 할 일 · 글머리 목록 · 그림 · 파일 · 번호 목록 · 나머지는 전 순서.
     `BLOCK_TYPES` 배열(단축키 · 빈 페이지 안내 · `data-block-index` 가 쓰는 번호)은 그대로 두고 그리는 차례만 바꾼다. ↑↓ 도 이 차례로 움직인다. */
  const FIRST = [['paragraph', null], ['heading', 1], ['to_do', null], ['bulleted', null], ['image', null], ['file', null], ['numbered', null]];
  const order = [];
  for (const [type, level] of FIRST) {
    const i = BLOCK_TYPES.findIndex((e) => e.type === type && (level === null || (e.attrs && e.attrs.level === level)));
    if (i >= 0 && order.indexOf(i) === -1) { order.push(i); }
  }
  for (let i = 0; i < BLOCK_TYPES.length; i++) { if (order.indexOf(i) === -1) { order.push(i); } }
  /** 값을 먼저 정하는 블록(다음 창 · 새 페이지)은 고르면 미리보기 → 「넣기」 두 단계 그대로. 나머지는 한 번 누르면 곧바로 넣고 닫는다 */
  const oneTap = (entry) => !entry.makesPage && !entry.editsAtom;

  /** 목록을 한 번만 그린다 — 종류는 늘 같다 */
  for (const i of order) {
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
    // UX-7 — 값이 필요 없는 블록은 누르는 것이 곧 「넣기」(블록 바꾸기 창이면 「바꾸기」). 키보드는 ↑↓ 로 고르고 Enter 로 넣는다(아래 keydown)
    button.addEventListener('click', () => {
      select(i);
      if (oneTap(entry)) { commit(); }
    });

    li.appendChild(button);
    listEl.appendChild(li);
    items[i] = li;
  }

  /** 고른 줄의 단추에 초점(목록 안에서 보이게) — 창을 열 때와 ↑↓ 때. Enter 는 초점 줄 = 고른 줄을 넣는다 */
  function focusPicked() {
    const button = items[index] ? items[index].querySelector('button') : null;
    if (!button) { return; }
    button.focus({ preventScroll: true });
    if (typeof button.scrollIntoView === 'function') { button.scrollIntoView({ block: 'nearest' }); }
  }

  /** 보이는 차례로 한 칸 옮긴다(↑↓) */
  function step(delta) {
    const at = order.indexOf(index);
    const next = ((at + delta) % order.length + order.length) % order.length;
    select(order[next]);
    focusPicked();
  }

  function select(i) {
    index = ((i % BLOCK_TYPES.length) + BLOCK_TYPES.length) % BLOCK_TYPES.length;
    for (let k = 0; k < items.length; k++) {
      if (!items[k]) { continue; }
      const on = k === index;
      items[k].className = 'list__item' + (on ? ' is-active' : '');
      items[k].setAttribute('aria-selected', on ? 'true' : 'false');
    }
    const entry = BLOCK_TYPES[index];
    insertButton.disabled = !!(hooks && hooks.suggestionMode && hooks.suggestionMode() && !suggestionTypeAllowed(entry.type));
    setText(nameEl, entry.label);
    setText(descEl, entry.desc);
    setText(keysEl, shortcutSentence(entry));
    clear(sampleEl);
    // 미리보기도 읽기 렌더러로 그린다. 4단계의 「하위 페이지」는 제목 지도(`sampleCtx`)가 있다.
    sampleEl.appendChild(renderDoc(entry.sample, entry.sampleCtx || {}));
  }

  function open(kind, pos) {
    for (let i=0;i<items.length;i++) {
      const disabled=!!(hooks && hooks.suggestionMode && hooks.suggestionMode() && !suggestionTypeAllowed(BLOCK_TYPES[i].type));
      const button=items[i].querySelector('button');button.disabled=disabled;button.title=disabled?'제안에서는 글 블록만 넣거나 바꿀 수 있습니다':'';
    }
    mode = kind === 'change' ? 'change' : 'insert';
    targetPos = mode === 'change' ? pos : null;
    setText(titleEl, mode === 'change' ? '블록 바꾸기' : '블록 추가');
    setText(insertButton, mode === 'change' ? '바꾸기' : '넣기');
    select(order[0]);
    if (typeof dialog.showModal === 'function' && !dialog.open) { dialog.showModal(); }
    // 사용감 라운드 1 UX-7 — 처음 초점은 목록의 첫 줄(전에는 머리 ✕ 라 Enter 가 창을 닫았다). ↑↓ · Enter 로 곧바로 고르고 넣는다
    focusPicked();
  }

  function commit() {
    const view = getView();
    const entry = BLOCK_TYPES[index];
    if (!view || !entry) { dialog.close(); return; }
    if (hooks && hooks.suggestionMode && hooks.suggestionMode() && !suggestionTypeAllowed(entry.type)) return;

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
    if (event.key === 'ArrowDown') { event.preventDefault(); step(1); return; }
    if (event.key === 'ArrowUp') { event.preventDefault(); step(-1); return; }
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

  select(order[0]);
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
const ATOM_TYPES = ['db_view','button','toggle', 'table', 'link_to_page', 'image', 'file', 'columns', 'embed'];
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
  const dbPane=document.createElement('div');dbPane.className='atom-pane';dbPane.hidden=true;
  const dbChoice=document.createElement('select');dbChoice.className='input';dbChoice.setAttribute('aria-label','데이터베이스');
  const dbViewChoice=document.createElement('select');dbViewChoice.className='input';dbViewChoice.setAttribute('aria-label','저장된 뷰');
  dbPane.append(dbChoice,dbViewChoice);$('atom-embed').parentElement.appendChild(dbPane);panes.db_view=dbPane;
  async function loadWidgetViews(){dbViewChoice.replaceChildren();if(!dbChoice.value)return;const r=await api('GET','/api/databases/'+dbChoice.value+'/rows');if(r.status!==200){setText(msgEl,errorMessage(r.data));return;}for(const v of r.data.views||[]){if(v.type==='form')continue;const o=document.createElement('option');o.value=v.id;o.textContent=v.name;o.selected=v.id===cur.view_id;dbViewChoice.append(o);}}
  dbChoice.addEventListener('change',()=>loadWidgetViews().catch(()=>setText(msgEl,GENERIC_ERROR)));
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

  /* Q:F-12 U3 C(DESIGN 2.24 ③) — 그림 · 파일 창의 탭 둘: 「이 문서에 올리기」(첫 탭 · 기본 — 파일 고르기) · 「자료실에서 고르기」(지금 창 그대로).
     올리기는 연 편집기가 준 `hooks.upload()`(그 문서의 createUploads — 고칠 수 있고 제안 중이 아닐 때만)가 있을 때만 보인다.
     넣기: 고르면 창을 닫고 커서 자리에 자리표시 → 블록(붙여넣기 · 끌어놓기와 같은 길 — 설명 · 그림 폭은 이 창의 값) /
     고치기: 창 안에서 올리고(진행률 한 줄) 그 첨부를 고른 파일로 둔다 — 「고치기」를 눌러야 바뀐다. */
  const filePane = panes.file;
  const captionField = fileCaption.closest('label');
  const libPanel = elt('div', { role: 'tabpanel', 'aria-label': '자료실에서 고르기' });
  while (filePane.firstChild && filePane.firstChild !== captionField) { libPanel.appendChild(filePane.firstChild); }
  const tabBar = elt('div', { class: 'toolbar db-viewbar', role: 'tablist', 'aria-label': '파일을 가져올 곳' });
  const tabUp = elt('button', { class: 'btn btn--quiet btn--small db-type', type: 'button', role: 'tab', 'data-atom-tab': 'upload', text: '이 문서에 올리기' });
  const tabLib = elt('button', { class: 'btn btn--quiet btn--small db-type', type: 'button', role: 'tab', 'data-atom-tab': 'library', text: '자료실에서 고르기' });
  tabBar.append(tabUp, tabLib);
  const picker = elt('input', { type: 'file', hidden: true, 'data-atom-upload-input': true });
  const uploadPick = elt('button', { class: 'btn btn--quiet', type: 'button', 'data-atom-upload': true });
  uploadPick.append(makeIcon('upload'), elt('span', { text: '파일 고르기' }));
  const upState = elt('p', { class: 'help', role: 'status', 'aria-live': 'polite' });
  const upPanel = elt('div', { role: 'tabpanel', 'aria-label': '이 문서에 올리기' }, [
    elt('div', { class: 'toolbar' }, [uploadPick, picker]),
    elt('p', { class: 'help', text: '올린 파일은 이 문서에만 딸려요 — 이 문서를 볼 수 있는 사람만 받을 수 있고, 자료실 목록에는 나오지 않아요. 붙여넣기(Ctrl+V)나 끌어놓기로도 넣을 수 있어요.' }),
    upState
  ]);
  filePane.insertBefore(libPanel, captionField);
  filePane.insertBefore(upPanel, libPanel);
  filePane.insertBefore(tabBar, upPanel);
  let fileTab = 'library';
  function setFileTab(which) {
    fileTab = which;
    for (const [b, on] of [[tabUp, which === 'upload'], [tabLib, which === 'library']]) {
      b.classList.toggle('is-active', on);
      b.setAttribute('aria-selected', on ? 'true' : 'false');
      b.tabIndex = on ? 0 : -1;
    }
    show(upPanel, which === 'upload');
    show(libPanel, which === 'library');
  }
  tabUp.addEventListener('click', () => setFileTab('upload'));
  tabLib.addEventListener('click', () => setFileTab('library'));
  tabBar.addEventListener('keydown', (e) => {
    if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') { return; }
    e.preventDefault();
    const next = fileTab === 'upload' ? tabLib : tabUp;
    next.click();
    next.focus();
  });
  uploadPick.addEventListener('click', () => picker.click());
  picker.addEventListener('change', () => {
    const file = picker.files && picker.files[0] ? picker.files[0] : null;
    picker.value = '';
    const up = hooks.upload ? hooks.upload() : null;
    const view = getView();
    if (!file || !up || !view) { return; }
    if (pos === null) {
      const extra = { caption: fileCaption.value, width: widthSelect.value };
      dialog.close();
      const $from = view.state.selection.$from;
      up.insert(view, [file], $from.depth >= 1 ? $from.after(1) : view.state.doc.content.size, extra);
      view.focus();
      return;
    }
    uploadPick.disabled = true;
    setText(msgEl, '');
    setText(upState, '올리는 중 0%');
    up.send(file, (n) => setText(upState, '올리는 중 ' + n + '%')).promise.then((data) => {
      cur.file_ref = 'att:' + data.id;
      cur.name = data.name || '';
      setText(upState, '올렸어요 — 「고치기」를 누르면 이 파일로 바뀌어요.');
    }).catch((err) => {
      setText(upState, '');
      if (err && err.status === 401) { toLogin(); return; }
      if (!(err && err.aborted)) { setText(msgEl, err && err.message ? err.message : GENERIC_ERROR); }
    }).finally(() => { uploadPick.disabled = false; });
  });

  let type = null;
  let pos = null;
  let cur = {};
  let searchTimer = null;
  let searchSerial = 0;
  const tableEditor = createTableEditor({pane:panes.table,grid,note:text=>setText(msgEl,text),context:()=>hooks.context ? hooks.context() : {}});
  $('atom-merge').addEventListener('click',()=>tableEditor.merge(Math.max(1,Math.min(20,parseInt($('atom-merge-rows').value,10)||1)),Math.max(1,Math.min(20,parseInt($('atom-merge-cols').value,10)||1))));
  $('atom-unmerge').addEventListener('click',()=>tableEditor.unmerge());
  dialog.addEventListener('close',()=>tableEditor.close());

  /* 6-C 단 나누기 — 단 수에 따라 셋째 칸을 보이고 감춘다 */
  const colsN = $('atom-columns-n');
  const syncCols = () => {
    show($('atom-col-2-field'), colsN.value === '3');
    // 3단 → 2단: 셋째 단 글은 넣을 때 버려진다 — 말없이 버리지 않게 알린다(독립 검토 6-C L3)
    setText(msgEl, colsN.value === '2' && $('atom-col-2').value.trim() !== '' ? '2단으로 줄이면 셋째 단의 글은 저장되지 않습니다.' : '');
  };
  colsN.addEventListener('change', syncCols);
  for (const [id,kind] of [['atom-row-add','row_add'],['atom-row-del','row_del'],['atom-col-add','col_add'],['atom-col-del','col_del']]) {
    $(id).addEventListener('click',()=>tableEditor.changeShape(kind));
  }

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

  const LABELS = { db_view:'데이터베이스 뷰', button:'버튼', toggle: '토글', table: '표', link_to_page: '페이지 링크', image: '그림', file: '파일', columns: '단 나누기', embed: '임베드' };

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
      hRow.checked = attrs ? cur.header_row === true : true;
      hCol.checked = cur.header_col === true;
      tableEditor.open({...cur,id:cur.id || uuid4(),header_row:hRow.checked,header_col:hCol.checked});
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
    } else if (type === 'db_view') {
      dbChoice.replaceChildren();api('GET','/api/databases').then(async r=>{if(r.status!==200){setText(msgEl,errorMessage(r.data));return;}for(const d of r.data.databases||[]){const o=document.createElement('option');o.value=d.id;o.textContent=d.title;o.selected=d.id===cur.database_id;dbChoice.append(o);}await loadWidgetViews();}).catch(()=>setText(msgEl,GENERIC_ERROR));
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
      // U4-3 — 끌어서 맞춘 폭(`width_pct`)이 있으면 그 값을 첫 고르개 항목으로(그대로 두면 그 폭 · 넷 중 하나를 고르면 끌어 맞춘 폭을 지운다)
      for (const o of Array.from(widthSelect.querySelectorAll('option[value="pct"]'))) { o.remove(); }
      const dragged = type === 'image' ? imageWidthPct(cur.width_pct) : null;
      if (dragged !== null) { widthSelect.insertBefore(new Option('끌어서 맞춘 폭 ' + String(dragged) + '%', 'pct'), widthSelect.firstChild); }
      widthSelect.value = dragged !== null ? 'pct' : (cur.width || 'normal');
      // U3 C — 올리기 탭(올릴 수 있는 편집기일 때만 · 첫 탭 · 기본). 자료실 파일(tbox:)을 고치는 중이면 자료실 탭부터
      const canUpload = !!(hooks.upload && hooks.upload());
      // 카페 C2 (ARCH 25.3 · DESIGN 2.29) — 익명게시판 글에는 자료실 파일을 넣을 수 없다(창구가 400 anon_board) — 「자료실에서 고르기」 탭 0 · 「이 문서에 올리기」만
      show(tabLib, !shared.pageIsAnon);
      show(tabBar, canUpload);
      setText(upState, '');
      picker.setAttribute('accept', type === 'image' ? UPLOAD_IMAGE_MIMES.join(',') : '');
      if (!picker.getAttribute('accept')) { picker.removeAttribute('accept'); }
      uploadPick.setAttribute('aria-label', type === 'image' ? '이 문서에 그림 올리기' : '이 문서에 파일 올리기');
      setFileTab(shared.pageIsAnon || (canUpload && !(typeof cur.file_ref === 'string' && cur.file_ref.slice(0, 5) === 'tbox:')) ? 'upload' : 'library');
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
      return tableEditor.collect(hRow.checked,hCol.checked);
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
    if(type==='db_view'){if(!dbChoice.value||!dbViewChoice.value){setText(msgEl,'데이터베이스와 저장된 뷰를 선택하세요');return null;}return {id,database_id:dbChoice.value,view_id:dbViewChoice.value};}
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
    if (type === 'image') {
      const keep = widthSelect.value === 'pct' && imageWidthPct(cur.width_pct) !== null;
      out.width = keep ? (['full', 'wide', 'normal', 'small'].indexOf(cur.width) !== -1 ? cur.width : 'normal') : widthSelect.value;
      out.width_pct = keep ? cur.width_pct : null;
    }
    return out;
  }

  function commit() {
    const view = getView();
    if (!view || !type) { dialog.close(); return; }
    if (tableEditor.composing()) { return; }
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
    if (event.isComposing || event.keyCode === 229 || event.key !== 'Enter' || !t || t.tagName !== 'INPUT' || t.type === 'search' || t.type === 'checkbox' || t.type === 'number') { return; }
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

/* ─── Q:F-12 U3 — 창 한 벌 나눠 쓰기 · 문서에 바로 올리기 (DESIGN 2.21 「칸 편집기」 · 2.24) ─────────────
 *
 * 「블록 추가」 · 「블록 고치기」 창은 화면에 **한 벌**이다. 페이지 화면 편집기와 작업 상세 칸 편집기(mountPaneEditor)가 같은 함수
 * (`createBlockDialog` · `createAtomDialog`)로 만든 같은 창을 나눠 쓰고, 연 쪽의 편집기(getView)로 넣는다 — 편집기 두 벌 0.
 * 창 마크업은 페이지 화면(page_view.php)에 있고, 그 마크업이 없는 화면(업무 홈 · 내 작업 — 칸 편집기만 있는 화면)에서는
 * `ensureEditorDialogs` 가 **같은 id · 같은 클래스**로 createElement 해 둔다(글자는 textContent — HTML 문자열 0).
 */


/** 요소 하나 — attrs: class · id · text(글자) · 그 밖은 setAttribute. kids 는 요소 배열 */
function elt(tag, attrs, kids) {
  const node = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs || {})) {
    if (v === null || v === undefined || v === false) { continue; }
    if (k === 'class') { node.className = v; } else if (k === 'text') { node.textContent = v; } else { node.setAttribute(k, v === true ? '' : String(v)); }
  }
  for (const kid of kids || []) { if (kid) { node.appendChild(kid); } }
  return node;
}

function ensureEditorDialogs() {
  const host = document.querySelector('main.main') || document.querySelector('.main') || body;
  if (!host) { return; }
  // 아이콘 자리는 서버 마크업과 같은 모양 — `span.icon[data-icon]` 안에 셸(app.js)이 채우는 것과 같은 `makeIcon` 한 벌
  const closeIcon = (attr) => elt('button', { class: 'btn btn--icon btn--plain', type: 'button', [attr]: true, 'aria-label': '닫기' },
    [elt('span', { class: 'icon', 'data-icon': 'x' }, [makeIcon('x')])]);
  if (!document.getElementById('block-dialog')) {
    host.appendChild(elt('dialog', { id: 'block-dialog', class: 'modal', 'aria-labelledby': 'block-dialog-title' }, [
      elt('div', { class: 'modal__head' }, [elt('h2', { id: 'block-dialog-title', class: 'modal__title', text: '블록 추가' }), closeIcon('data-block-close')]),
      elt('div', { class: 'modal__body block-picker' }, [
        elt('ul', { id: 'block-list', class: 'list', role: 'listbox', 'aria-label': '블록 종류' }),
        elt('div', { class: 'block-picker__detail' }, [
          elt('p', { id: 'block-preview-name', class: 'panel__title' }), elt('p', { id: 'block-desc', class: 'muted' }),
          elt('p', { id: 'block-keys', class: 'block-picker__keys' }), elt('div', { id: 'block-sample', class: 'block-picker__sample' })
        ])
      ]),
      elt('div', { class: 'form-actions' }, [
        elt('button', { class: 'btn btn--quiet', type: 'button', 'data-block-close': true, text: '닫기' }),
        elt('button', { id: 'block-insert', class: 'btn', type: 'button', text: '넣기' })
      ])
    ]));
  }
  if (!document.getElementById('atom-dialog')) {
    const field = (label, control, extra) => elt('label', Object.assign({ class: 'field field--full' }, extra || {}), [elt('span', { class: 'field__label', text: label }), control]);
    const check = (id, label) => elt('label', { class: 'field field--full' }, [elt('span', { class: 'field__label' }, [elt('input', { id: id, type: 'checkbox' }), document.createTextNode(' ' + label)])]);
    const small = (id, text) => elt('button', { id: id, class: 'btn btn--quiet btn--small', type: 'button', text: text });
    const opt = (value, text) => elt('option', { value: value, text: text });
    host.appendChild(elt('dialog', { id: 'atom-dialog', class: 'modal modal--form', 'aria-labelledby': 'atom-dialog-title' }, [
      elt('div', { class: 'modal__head' }, [elt('h2', { id: 'atom-dialog-title', class: 'modal__title', text: '블록 고치기' }), closeIcon('data-atom-close')]),
      elt('div', { class: 'modal__body form' }, [
        elt('p', { id: 'atom-msg', class: 'msg', role: 'alert', 'aria-live': 'polite' }),
        elt('div', { id: 'atom-toggle', class: 'atom-pane', hidden: true }, [
          field('제목 줄 (누르면 펼쳐진다)', elt('input', { id: 'atom-toggle-summary', type: 'text', maxlength: '500', autocomplete: 'off', spellcheck: 'false' })),
          field('펼치면 보이는 내용 — 한 줄이 한 문단', elt('textarea', { id: 'atom-toggle-body', rows: '6', spellcheck: 'false' }))
        ]),
        elt('div', { id: 'atom-table', class: 'atom-pane', hidden: true }, [
          check('atom-table-hrow', '첫 행을 머리로'), check('atom-table-hcol', '첫 열을 머리로'),
          elt('div', { class: 'doc-table-wrap' }, [elt('table', { class: 'doc-table atom-grid' }, [elt('tbody', { id: 'atom-table-grid' })])]),
          elt('div', { class: 'toolbar' }, [small('atom-row-add', '행 더하기'), small('atom-row-del', '마지막 행 빼기'), small('atom-col-add', '열 더하기'), small('atom-col-del', '마지막 열 빼기')]),
          elt('div', { class: 'toolbar atom-merge' }, [
            elt('label', { class: 'atom-merge__field' }, [document.createTextNode('가로 '), elt('input', { id: 'atom-merge-cols', class: 'input', type: 'number', min: '1', max: '20', value: '2' })]),
            elt('label', { class: 'atom-merge__field' }, [document.createTextNode('세로 '), elt('input', { id: 'atom-merge-rows', class: 'input', type: 'number', min: '1', max: '20', value: '1' })]),
            small('atom-merge', '고른 칸부터 합치기'), small('atom-unmerge', '고른 칸 풀기')
          ]),
          elt('p', { class: 'help', text: '합칠 칸이 비어 있어야 합칩니다. 칸을 선택하면 아래에서 글자와 서식을 고칠 수 있습니다.' })
        ]),
        elt('div', { id: 'atom-columns', class: 'atom-pane', hidden: true }, [
          elt('label', { class: 'field' }, [elt('span', { class: 'field__label', text: '단 수' }), elt('select', { id: 'atom-columns-n' }, [opt('2', '2단'), opt('3', '3단')])]),
          elt('div', { class: 'atom-columns' }, [0, 1, 2].map((i) => elt('label', Object.assign({ class: 'field' }, i === 2 ? { id: 'atom-col-2-field' } : {}), [
            elt('span', { class: 'field__label', text: (i + 1) + '단' }), elt('textarea', { id: 'atom-col-' + i, rows: '6', spellcheck: 'false' })])))
        ]),
        elt('div', { id: 'atom-link', class: 'atom-pane', hidden: true }, [
          field('걸 페이지 찾기', elt('input', { id: 'atom-link-q', type: 'search', maxlength: '200', autocomplete: 'off', spellcheck: 'false', placeholder: '제목이나 본문의 낱말' })),
          elt('p', { id: 'atom-link-chosen', class: 'help' }), elt('ul', { id: 'atom-link-list', class: 'list', 'aria-label': '찾은 페이지' })
        ]),
        elt('div', { id: 'atom-embed', class: 'atom-pane', hidden: true }, [
          field('주소 (https)', elt('input', { id: 'atom-embed-url', class: 'input', type: 'url', maxlength: '2048', autocomplete: 'off', spellcheck: 'false', placeholder: 'https://' })),
          elt('p', { id: 'atom-embed-hosts', class: 'help' }),
          field('설명 (선택)', elt('input', { id: 'atom-embed-caption', class: 'input', type: 'text', maxlength: '2000', autocomplete: 'off' }))
        ]),
        elt('div', { id: 'atom-file', class: 'atom-pane', hidden: true }, [
          field('내 자료실 파일에서 고르기', elt('input', { id: 'atom-file-q', type: 'search', maxlength: '200', autocomplete: 'off', spellcheck: 'false', placeholder: '파일 이름 (비우면 맨 위 폴더)' })),
          elt('p', { id: 'atom-file-chosen', class: 'help' }), elt('ul', { id: 'atom-file-list', class: 'list', 'aria-label': '고를 수 있는 파일' }),
          elt('div', { class: 'toolbar' }, [
            elt('a', { id: 'atom-file-upload', class: 'btn btn--quiet btn--small', href: '#', target: '_blank', rel: 'noopener', text: '자료실에 올리기' }),
            small('atom-file-refresh', '목록 새로 고침')
          ]),
          elt('p', { class: 'help', text: '페이지를 읽는 사람이 모두 볼 수 있는 등급의 파일만 붙습니다. 좁으면 저장할 때 알려 드립니다.' }),
          field('설명 (선택)', elt('input', { id: 'atom-file-caption', type: 'text', maxlength: '500', autocomplete: 'off', spellcheck: 'false' })),
          elt('label', { id: 'atom-image-width-field', class: 'field' }, [elt('span', { class: 'field__label', text: '그림 폭' }),
            elt('select', { id: 'atom-image-width' }, [opt('small', '작게'), opt('normal', '보통'), opt('wide', '넓게'), opt('full', '꽉 차게')])])
        ])
      ]),
      elt('div', { class: 'form-actions' }, [
        elt('button', { class: 'btn btn--quiet', type: 'button', 'data-atom-close': true, text: '닫기' }),
        elt('button', { id: 'atom-commit', class: 'btn', type: 'button', text: '넣기' })
      ])
    ]));
  }
}

/* 창 한 벌 + 지금 연 편집기(bind) — 페이지 화면 · 칸 편집기가 같은 창을 쓴다. 여는 순간 그 편집기로 묶는다 */
let sharedBlockDialog;
let sharedAtomDialog;
let blockBind = null;
let atomBind = null;

/** 「블록 추가」 창 — `createBlockDialog` 한 벌을 그 편집기(getView · hooks)로 묶어 연다 */
function blockDialogFor(getView, hooks) {
  if (sharedBlockDialog === undefined) {
    ensureEditorDialogs();
    sharedBlockDialog = createBlockDialog(() => (blockBind ? blockBind.getView() : null), {
      createChildPage: () => (blockBind && typeof blockBind.hooks.createChildPage === 'function' ? blockBind.hooks.createChildPage() : Promise.resolve(null)),
      editAtom: (type, attrs, pos) => { if (blockBind && typeof blockBind.hooks.editAtom === 'function') { blockBind.hooks.editAtom(type, attrs, pos); } },
      suggestionMode: () => !!(blockBind && typeof blockBind.hooks.suggestionMode === 'function' && blockBind.hooks.suggestionMode())
    });
  }
  if (!sharedBlockDialog) { return null; }
  const mine = { getView: getView, hooks: hooks || {} };
  return {
    open: (kind, pos) => { blockBind = mine; sharedBlockDialog.open(kind, pos); },
    close: () => { if (blockBind === mine) { sharedBlockDialog.close(); } },
    changeTo: (entry, pos) => { blockBind = mine; sharedBlockDialog.changeTo(entry, pos); }
  };
}

/** 「블록 고치기」 창(토글 · 표 · 페이지 링크 · 그림 · 파일 …) — `createAtomDialog` 한 벌을 그 편집기로 묶어 연다 */
function atomDialogFor(getView, hooks) {
  if (sharedAtomDialog === undefined) {
    ensureEditorDialogs();
    const call = (name, fallback) => (...args) => (atomBind && typeof atomBind.hooks[name] === 'function' ? atomBind.hooks[name](...args) : fallback);
    sharedAtomDialog = createAtomDialog(() => (atomBind ? atomBind.getView() : null), {
      selfId: call('selfId', ''), context: call('context', {}), rememberLink: call('rememberLink'), rememberFile: call('rememberFile'), upload: call('upload', null)
    });
  }
  if (!sharedAtomDialog) { return null; }
  const mine = { getView: getView, hooks: hooks || {} };
  return {
    open: (type, attrs, pos) => { atomBind = mine; sharedAtomDialog.open(type, attrs, pos); },
    close: () => { if (atomBind === mine) { sharedAtomDialog.close(); } }
  };
}

/** Ctrl+Alt+숫자 — 커서가 있는 블록을 그 종류로 바꾼다(`BLOCK_TYPES[].key`, 창의 「바꾸기」와 같다). 페이지 · 칸 편집기 공용 */
function blockKeymapFor(getDialog) {
  const bindings = {};
  for (const entry of BLOCK_TYPES) {
    if (!entry.key || entry.makesPage) { continue; }
    bindings[entry.key] = (pmState) => {
      const $from = pmState.selection.$from;
      const dialog = getDialog();
      if ($from.depth < 1 || !dialog) { return false; }
      dialog.changeTo(entry, $from.before(1));
      return true;
    };
  }
  return keymap(bindings);
}

/** 덩어리 카드를 골라 둔 채 Enter → 「블록 고치기」 창(페이지 · 칸 편집기 공용) */
function atomEditCommand(editAtom) {
  return (pmState) => {
    const hit = atomAt(pmState);
    if (!hit) { return false; }
    editAtom(hit.node.type.name, hit.node.attrs, hit.pos);
    return true;
  };
}

/* ── 문서에 바로 올리기 (U3 C · DESIGN 2.24 · 커뮤니티 ARCH 24장) ──
 * 입구 셋(붙여넣기 · 끌어놓기 · 「이 문서에 올리기」 탭)이 **한 함수**(`createUploads`)를 지난다. 올린 것은 그 문서 전용 첨부
 * (`POST /api/pages/{id}/attachments` — 판정 · 검사 · 총량 · 민감정보는 서버가 한다)이고 본문에는 `att:<uuid>` 그림 · 파일 블록이 들어간다.
 * 붙여넣기 · 끌어놓기는 **파일만** 받는다 — 붙여넣은 HTML 의 외부 `<img src>` 는 지금처럼 버린다(스키마에 parseDOM 0 · 이 함수는 파일만 본다).
 * 올리는 동안 그 자리에 자리표시(ProseMirror 장식 — 본문에 저장되지 않는다)가 서고, 끝나면 그 자리에 블록이 들어간다. 여러 개는 차례로(한 번에 하나).
 * 실패하면 자리표시가 사라지고 창구가 준 이유 문장(F-68)을 알림 줄에 — 「다시 시도」 0. 입구는 편집기가 있을 때만(편집기 = 고칠 수 있는 사람) —
 * 막는 것은 서버다.
 */
const uploadKey = new PluginKey('qaredi-doc-upload');

/**
 * 한 문서의 올리기 한 벌. cfg: { pageId() → 문서 id, onError(글자), remember(첨부 id, {name,size,image,scan_status}) — 이름 지도에 넣기 }
 * → { plugin() — 붙여넣기 · 끌어놓기 · 자리표시, insert(view, files, at, extra) — 그 자리에 자리표시 → 블록, send(file, onProgress), remember }
 */
function createUploads(cfg) {
  let queue = Promise.resolve();
  let seq = 0;
  const remember = (data) => { cfg.remember(data.id, { name: data.name || '', size: Number(data.size) || 0, image: data.image === true, scan_status: data.scan_status || 'clean' }); };
  /* 카페 C4 — 검사 중 그림은 올린 그 파일의 브라우저 안 사본을 기억해 두고(편집기에서만 그린다) 다시 묻기를 켠다(cfg.onUploaded — 그 화면의 watchAttachments) */
  const settle = (data, preview) => preview.then((url) => {
    if (url && data.image === true && data.scan_status === 'pending' && ATT_UUID_RE.test(String(data.id))) { attachmentPreviews[data.id] = url; }
    return data;
  });
  const uploaded = () => { if (typeof cfg.onUploaded === 'function') { cfg.onUploaded(); } };
  function placeholder(kind, name, onCancel) {
    const box = elt('div', { class: 'doc-upload', 'data-kind': kind, role: 'status', 'aria-live': 'polite', contenteditable: 'false' });
    const progress = elt('span', { class: 'doc-upload__progress', text: '올리는 중 0%' });
    const cancel = elt('button', { class: 'btn btn--quiet btn--small', type: 'button', text: '취소' });
    cancel.addEventListener('mousedown', (e) => e.preventDefault());
    cancel.addEventListener('click', onCancel);
    box.append(elt('span', { class: 'doc-upload__name', text: name }), progress, cancel);
    return { box: box, progress: progress };
  }
  function where(view, id) {
    const set = uploadKey.getState(view.state);
    const found = set ? set.find(undefined, undefined, (spec) => spec.uploadId === id) : [];
    return found.length ? found[0].from : null;
  }
  function drop(view, id) {
    if (!view.isDestroyed && where(view, id) !== null) { view.dispatch(view.state.tr.setMeta(uploadKey, { remove: id })); }
  }
  function place(view, id, data, extra) {
    remember(data);
    if (view.isDestroyed) { return; }
    const at = where(view, id);
    if (at === null) { return; }
    const type = data.image === true ? 'image' : 'file';
    const attrs = { id: uuid4(), file_ref: 'att:' + data.id, caption: extra && typeof extra.caption === 'string' ? extra.caption : '', name: data.name || '' };
    if (type === 'image') { attrs.width = extra && ['full', 'wide', 'normal', 'small'].indexOf(extra.width) !== -1 ? extra.width : 'normal'; }
    view.dispatch(view.state.tr.insert(at, schema.nodes[type].create(attrs)).setMeta(uploadKey, { remove: id }).scrollIntoView());
  }
  function insert(view, files, at, extra) {
    for (const file of files) {
      const id = 'up-' + String(++seq);
      const kind = UPLOAD_IMAGE_MIMES.indexOf(file.type) !== -1 ? 'image' : 'file';
      let job = null;
      let cancelled = false;
      const ph = placeholder(kind, typeof file.name === 'string' && file.name !== '' ? file.name : (kind === 'image' ? '그림' : '파일'), () => {
        cancelled = true;
        if (job) { job.abort(); }
        drop(view, id);
      });
      view.dispatch(view.state.tr.setMeta(uploadKey, { add: { id: id, pos: at, dom: ph.box, side: seq } }));
      queue = queue.then(() => {
        if (cancelled || view.isDestroyed) { return null; }
        job = sendAttachment(cfg.pageId(), file, (n) => setText(ph.progress, '올리는 중 ' + n + '%'));
        const preview = kind === 'image' ? readPreview(file) : Promise.resolve(null);
        return job.promise.then((data) => settle(data, preview)).then((data) => { place(view, id, data, extra); uploaded(); }, (err) => {
          drop(view, id);
          if (err && err.aborted) { return; }
          if (err && err.status === 401) { toLogin(); return; }
          cfg.onError(err && err.message ? err.message : GENERIC_ERROR);
        });
      });
    }
  }
  function plugin() {
    return new Plugin({
      key: uploadKey,
      state: {
        init: () => DecorationSet.empty,
        apply(tr, set) {
          let next = set.map(tr.mapping, tr.doc);
          const meta = tr.getMeta(uploadKey);
          if (meta && meta.add) {
            next = next.add(tr.doc, [Decoration.widget(meta.add.pos, meta.add.dom, { uploadId: meta.add.id, side: meta.add.side, ignoreSelection: true, key: meta.add.id })]);
          }
          if (meta && meta.remove) { next = next.remove(next.find(undefined, undefined, (spec) => spec.uploadId === meta.remove)); }
          return next;
        }
      },
      props: {
        decorations: (state) => uploadKey.getState(state),
        // 붙여넣기 — 파일(캡처 그림)만. 글이 함께 든 붙여넣기(표 칸 · 문서 복사)는 지금처럼 글로(그 안의 외부 그림 주소는 버린다)
        handlePaste(view, event) {
          const data = event && event.clipboardData;
          const files = data ? Array.from(data.files || []) : [];
          if (!files.length || String(data.getData('text/plain') || '').trim() !== '') { return false; }
          const $from = view.state.selection.$from;
          insert(view, files, $from.depth >= 1 ? $from.after(1) : view.state.doc.content.size, null);
          return true;
        },
        // 끌어놓기 — 파일만(편집기 안에서 블록을 옮기는 끌기는 지금 그대로). 놓은 자리에서 가까운 블록 경계에
        handleDrop(view, event, slice, moved) {
          if (moved) { return false; }
          const files = event && event.dataTransfer ? Array.from(event.dataTransfer.files || []) : [];
          if (!files.length) { return false; }
          const hit = view.posAtCoords({ left: event.clientX, top: event.clientY });
          let at = view.state.doc.content.size;
          if (hit) {
            const $p = view.state.doc.resolve(hit.pos);
            at = $p.depth >= 1 ? (hit.pos - $p.start(1) <= $p.end(1) - hit.pos ? $p.before(1) : $p.after(1)) : hit.pos;
          }
          insert(view, files, at, null);
          return true;
        }
      }
    });
  }
  return {
    plugin: plugin,
    insert: insert,
    /** 차례를 기다리는 올리기가 다 끝나면(성공 · 실패 · 취소 모두) */
    idle: () => queue.then(() => null, () => null),
    send: (file, onProgress) => {
      const job = sendAttachment(cfg.pageId(), file, onProgress);
      const preview = UPLOAD_IMAGE_MIMES.indexOf(file && file.type) !== -1 ? readPreview(file) : Promise.resolve(null);
      return { promise: job.promise.then((data) => settle(data, preview)).then((data) => { remember(data); uploaded(); return data; }), abort: job.abort };
    }
  };
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
  /* Q:F-12 U3 B(DESIGN 2.21 「칸 편집기」) — 작업 상세 칸 편집기도 **이 함수**로 같은 도구줄을 단다: `opts.wrap`(칸 안 줄) ·
     `opts.addBlock`(그 칸의 「블록 추가」 단추) · `opts.paper: false`(용지 고르개는 전체 화면 편집기 몫). 칸 줄은 id 를 달지 않는다
     (페이지 편집기와 한 화면에 함께 있어도 id 가 겹치지 않게 — 모양 · 클래스 · 동작은 같다). */
  const own = !!(opts && opts.wrap);
  const wrap = own ? opts.wrap : document.getElementById('inline-toolbar');
  if (!wrap) { return null; }
  const setId = (node, id) => { if (!own) { node.id = id; } };
  const pageId = opts && typeof opts.pageId === 'string' ? opts.pageId : '';
  const rememberMention = opts && typeof opts.rememberMention === 'function' ? opts.rememberMention : () => {};
  loadPaletteNames();   // 7-D2 — 편집기를 만들 때 한 번(색 줄은 열 때 짓는다 — 그때까지 오면 이름 견본이 함께)
  // 「블록 추가」는 마크업에서 이 줄 안에 있다. 비우기 전에 붙잡아 두었다가 줄 맨 앞에 다시 놓는다
  // (단추의 click 은 initPage 가 걸어 두었으므로 요소를 새로 만들지 않는다).
  const addBlock = own ? (opts.addBlock || null) : document.getElementById('page-add-block');

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
    setId(button, id);
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
    setId(linkInput, 'link-input');
    linkInput.setAttribute('placeholder', 'https://…');
    linkInput.setAttribute('maxlength', '2048');
    linkInput.setAttribute('spellcheck', 'false');
    linkInput.setAttribute('aria-label', '링크 주소');

    const apply = document.createElement('button');
    apply.type = 'button';
    setId(apply, 'link-apply');
    apply.className = 'btn btn--small';
    apply.textContent = '걸기';

    const drop = document.createElement('button');
    drop.type = 'button';
    setId(drop, 'link-drop');
    drop.className = 'btn btn--small btn--quiet';
    drop.textContent = '빼기';

    linkNote = document.createElement('span');
    setId(linkNote, 'link-note');
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
    setId(row, 'mention-row');
    row.hidden = true;

    mentionKind = document.createElement('select');
    setId(mentionKind, 'mention-kind');
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
    setId(mentionQ, 'mention-q');
    mentionQ.className = 'input';
    mentionQ.setAttribute('maxlength', '64');
    mentionQ.setAttribute('autocomplete', 'off');
    mentionQ.setAttribute('spellcheck', 'false');
    mentionQ.setAttribute('aria-label', '찾을 말');

    mentionDate = document.createElement('input');
    mentionDate.type = 'date';
    setId(mentionDate, 'mention-date');
    mentionDate.className = 'input';
    mentionDate.setAttribute('min', '1900-01-01');
    mentionDate.setAttribute('max', '2100-12-31');
    mentionDate.setAttribute('aria-label', '날짜');

    mentionDateApply = document.createElement('button');
    mentionDateApply.type = 'button';
    setId(mentionDateApply, 'mention-date-apply');
    mentionDateApply.className = 'btn btn--small';
    mentionDateApply.textContent = '넣기';

    mentionList = document.createElement('ul');
    setId(mentionList, 'mention-list');
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
  const paperLabel = document.createElement('label');
  paperLabel.className = 'editor-paper-mode';
  const paperText = document.createElement('span');
  paperText.textContent = '용지';
  const paperSelect = document.createElement('select');
  paperSelect.className = 'input editor-paper-mode__select';
  paperSelect.setAttribute('aria-label', '편집 용지 크기');
  /* Q:F-12 W1c (DESIGN 2.35) — 기본은 용지 없음(작업 전체 화면과 같은 열 폭 편집 칸). A4 · A3 · Letter 를 고른 사람만 종이 모드(전과 같은 폭).
     옛 값 'wide'(넓게)와 고른 적 없음(전 기본 'a4' 는 저장되지 않았다)은 기본으로 */
  for (const [value, label] of [['', '기본'], ['a4', 'A4'], ['a3', 'A3'], ['letter', 'Letter']]) {
    const option = document.createElement('option');
    option.value = value;
    option.textContent = label;
    paperSelect.appendChild(option);
  }
  const savedPaper = (() => {
    try { return localStorage.getItem('community-editor-paper') || ''; } catch (_) { return ''; }
  })();
  paperSelect.value = ['a4', 'a3', 'letter'].includes(savedPaper) ? savedPaper : '';
  const editorPanel = opts && opts.paper === false ? null : document.getElementById('page-editor');
  const paintPaper = () => {
    if (!editorPanel) { return; }
    if (paperSelect.value === '') { editorPanel.removeAttribute('data-paper-mode'); } else { editorPanel.setAttribute('data-paper-mode', paperSelect.value); }
  };
  paintPaper();
  paperSelect.addEventListener('change', () => {
    paintPaper();
    try { localStorage.setItem('community-editor-paper', paperSelect.value); } catch (_) {}
  });
  paperLabel.append(paperText, paperSelect);
  if (!(opts && opts.paper === false)) { row.appendChild(paperLabel); }
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

function mountPaneEditor(mount, opts) {
  const titles = opts.titles;
  for (const k of ['child_titles', 'link_titles', 'file_titles', 'attachment_titles', 'mention_labels']) {
    if (!titles[k] || typeof titles[k] !== 'object' || Array.isArray(titles[k])) { titles[k] = {}; }
  }
  let pmDoc;
  try { pmDoc = fromDoc(opts.doc, { child_titles: titles.child_titles, link_titles: titles.link_titles, file_titles: titles.file_titles, attachment_titles: titles.attachment_titles, mention_labels: titles.mention_labels }); }
  catch (e) { opts.onError(e && e.message ? e.message : GENERIC_ERROR); return null; }
  let version = opts.version;
  let dirty = false;
  let stopped = false;
  let timer = null;
  let inFlight = Promise.resolve();
  let view = null;
  let gone = false;     // 걷은 뒤에도 마지막 저장(flush)은 view.state 로 보낸다 — 창 · 올리기만 끊는다
  let toolbar = null;
  const getView = () => (gone ? null : view);
  const uploads = createUploads({
    pageId: () => opts.pageId,
    onError: (t) => opts.onError(t),
    remember: (id, entry) => { titles.attachment_titles[id] = entry; },
    onUploaded: () => { if (typeof opts.onUploaded === 'function') { opts.onUploaded(); } }   // 카페 C4 — 칸의 watchAttachments 를 켠다
  });
  // 「하위 페이지」 블록 — 페이지 화면과 같은 창구(이 작업 아래 새 페이지 = 하위 작업)
  function createChildPage() {
    return api('POST', '/api/pages', { parent_id: opts.pageId }).then((result) => {
      if (result.status === 401) { toLogin(); return null; }
      if (result.status !== 201 || !result.data.page || typeof result.data.page.id !== 'string') { opts.onError(errorMessage(result.data)); return null; }
      const made = { id: result.data.page.id, title: result.data.page.title || '제목 없는 페이지' };
      titles.child_titles[made.id] = { title: made.title, icon: null };
      return made;
    });
  }
  const atomDialog = atomDialogFor(getView, {
    selfId: () => opts.pageId,
    context: () => ({ mention_labels: titles.mention_labels }),
    rememberLink: (id, entry) => { titles.link_titles[id] = entry; },
    rememberFile: (id, entry) => { titles.file_titles[id] = entry; },
    upload: () => (view !== null && !gone && !stopped ? uploads : null)
  });
  const editAtom = (type, attrs, pos) => { if (atomDialog) { atomDialog.open(type, attrs, pos); } };
  const blockDialog = blockDialogFor(getView, { createChildPage: createChildPage, editAtom: editAtom, suggestionMode: () => false });
  // 도구줄 자리 — 페이지 화면의 `#inline-toolbar` 와 같은 모양(.panel.editor-toolbar · role=toolbar)과 같은 「블록 추가」 단추. 줄 안은 createToolbar 가 채운다
  const bar = elt('div', { class: 'panel editor-toolbar', role: 'toolbar', 'aria-label': '블록 추가와 글자 꾸미기' });
  const addBlock = elt('button', { class: 'btn btn--small btn--quiet', type: 'button' });
  addBlock.append(makeIcon('plus'), document.createTextNode('블록 추가'));
  addBlock.addEventListener('click', () => { if (blockDialog && view !== null && !gone) { blockDialog.open('insert', null); } });
  bar.appendChild(addBlock);
  if (mount.parentNode) { mount.parentNode.insertBefore(bar, mount); }
  const pmState = EditorState.create({
    schema: schema,
    doc: schema.nodeFromJSON(pmDoc),
    plugins: [
      buildInputRules(),
      blockKeymapFor(() => blockDialog),
      keymap({ 'Mod-z': undo, 'Mod-y': redo, 'Shift-Mod-z': redo, Enter: chainCommands(atomEditCommand(editAtom), commandCodeEnter, commandEnter),
        'Shift-Enter': commandShiftEnter, Backspace: commandBackspace, Tab: () => true, 'Shift-Tab': () => true }),
      keymap(baseKeymap), history(), dropCursor(), gapCursor(), uniqueBlockIds, todoToggle, liveToc,
      // U4-3 — 전체 편집기와 같은 그림 표시 부품 · 손잡이 · Alt+←/→(같은 함수)
      imageViews({ ctx: () => ({ file_titles: titles.file_titles, attachment_titles: titles.attachment_titles, attachment_page: opts.pageId, base: COMMUNITY_BASE, tbox_base: APP_BASE }), resizable: () => !stopped }),
      imageWidthKeys(),
      uploads.plugin()
    ]
  });
  function save() {
    if (!dirty || stopped) { return inFlight; }
    dirty = false;
    const doc = toDoc(view.state.doc.toJSON());
    inFlight = api('PATCH', '/api/pages/' + opts.pageId, { doc_version: version, doc: doc }).then((r) => {
      if (r.status === 401) { toLogin(); return; }
      if (r.status === 200) { version = r.data.doc_version; opts.onState(dirty ? '저장 중…' : '저장됨'); opts.onSaved(doc, version); if (dirty) { return save(); } return; }
      stopped = true;
      opts.onState('저장 못 함');
      opts.onError(r.status === 409 ? '다른 곳에서 먼저 저장되었습니다. 전체 화면으로 열어 확인하세요.' : errorMessage(r.data));
    }).catch(() => { opts.onState('저장 못 함'); opts.onError(GENERIC_ERROR); });
    return inFlight;
  }
  view = new EditorView(mount, {
    state: pmState,
    attributes: { class: 'doc editor', 'aria-label': '작업 설명' },
    domParser: editorDomParser,
    clipboardParser: clipboardDomParser,
    handleDoubleClickOn(v, p, node, nodePos, event, direct) {
      if (!direct || ATOM_TYPES.indexOf(node.type.name) === -1) { return false; }
      editAtom(node.type.name, node.attrs, nodePos);
      return true;
    },
    dispatchTransaction(tr) {
      view.updateState(view.state.apply(tr));
      if (toolbar) { toolbar.refresh(); }
      if (tr.docChanged && !stopped) {
        dirty = true;
        opts.onState('저장 중…');
        window.clearTimeout(timer);
        timer = window.setTimeout(save, 1500);
      }
    }
  });
  toolbar = createToolbar(view, {
    pageId: opts.pageId, wrap: bar, addBlock: addBlock, paper: false,
    rememberMention: (key, entry) => { titles.mention_labels[key] = entry; }
  });
  return {
    insertText: text => { if (!gone && !stopped) view.pasteText(text); },
    insertFiles: files => { if (!gone && !stopped) uploads.insert(view, files, view.state.selection.from); },
    // 「편집 마침」 — 올리던 것이 블록으로 들어간 뒤에 저장한다(걷기 전에 기다리는 쪽만 — 칸을 닫으며 걷으면 올리던 것은 문서 첨부로만 남는다)
    flush: () => { window.clearTimeout(timer); return uploads.idle().then(() => inFlight).then(save); },
    destroy: () => {
      window.clearTimeout(timer);
      if (blockDialog) { blockDialog.close(); }
      if (atomDialog) { atomDialog.close(); }
      if (!gone) { gone = true; view.destroy(); }
      toolbar = null;
      bar.remove();
    },
    focus: () => { if (!gone) { view.focus(); } }
  };
}


  return { ATOM_TYPES, BLOCK_TYPES, COLORS, CONTINUE_KINDS, Decoration, DecorationSet, EditorState, EditorView, IMAGE_HINT, IMAGE_PCT_MAX, IMAGE_PCT_MIN, IS_MAC, InputRule, NodeSelection, Plugin, PluginKey, TABLE_MAX_COLS, TABLE_MAX_ROWS, TextSelection, atomAt, atomBind, atomDialogFor, atomEditCommand, baseKeymap, blockBaseAttrs, blockBind, blockDialogFor, blockHandles, blockKeymapFor, buildInputRules, chainCommands, clipboardDomParser, colorAllowed, commandBackspace, commandCodeEnter, commandEnter, commandShiftEnter, createAtomDialog, createBlockDialog, createTableEditor, createToolbar, createUploads, drawLiveToc, dropCursor, editorDomParser, elt, embedHost, ensureEditorDialogs, exitCodeBlock, fromDoc, gapCursor, history, imageViews, imageWidthKeys, imageWidthPct, imageWidthStep, inputRules, keyLabel, keymap, liveToc, loadAutomations, loadPaletteNames, makeBlockNode, mdLabel, measuredImagePct, mentionAllowed, mountPaneEditor, paletteNames, palettesLoading, redo, renderDoc, renderFileBlock, schema, schemeOkForEditor, setImageWidthClass, sharedAtomDialog, sharedBlockDialog, shortcutSentence, snapImagePct, suggestionTypeAllowed, textblockTypeInputRule, toDoc, todoToggle, undo, uniqueBlockIds, uploadKey, uuid4 };
}
