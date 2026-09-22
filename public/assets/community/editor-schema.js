/**
 * public/assets/community/editor-schema.js — ProseMirror ↔ 우리 블록 JSON 의 **유일한 통로**
 *
 * 요구사항: C:F-15 · C:F-16 · C:S-11 · C:S-12,
 *           ../../../../community/ARCHITECTURE.md 12.2 · 14.1 · 14.2, DESIGN.md 2.14.
 *
 * 무엇을 하나
 *   · 우리 스키마의 **노드·마크만** ProseMirror 에 등록한다. 등록하지 않은 것은 편집기가
 *     만들 수도, 붙여넣기로 들여올 수도 없다 — 붙여넣기 안전(14.3)의 뿌리가 여기다.
 *   · `toDoc(pmDoc)` · `fromDoc(doc)` 두 함수가 형식을 옮기는 **유일한 자리**다. 다른 파일이
 *     ProseMirror 의 JSON 을 직접 만지지 않는다.
 *   · 모르는 것을 만나면 **조용히 바꾸지 않고 예외를 던진다**. 저장되는 형식은 서버
 *     `src/community/Doc.php` 가 다시 검사하고, 여기서 막는 것은 「보내기 전에 알아채기」다.
 *
 * 3단계의 범위 (ARCH 21.3)
 *   블록 10종: paragraph · heading(1~3) · bulleted · numbered · to_do · quote · callout ·
 *              divider · code · toc
 *   마크 7종 : bold · italic · underline · strike · code · color(글자 9·배경 9) · link
 *   **하위 블록(children)은 만들지 않는다.** 트리는 4단계다 — 들어 있는 문서를 만나면
 *   `fromDoc` 이 예외를 던지고 편집기가 열리지 않는다(읽기는 된다). 조용히 펴서 저장하면
 *   사용자가 쓴 구조가 말없이 사라진다.
 */

import { Schema } from './vendor/pm/prosemirror-model.js';

/** 기본 색 9 — 배경은 `_bg` 를 붙인 값이다 (doc-schema.json 과 같은 목록) */
export const COLORS = ['gray', 'brown', 'orange', 'yellow', 'green', 'blue', 'purple', 'pink', 'red'];

/** 코드 블록의 언어 허용 목록 (doc-schema.json `languages` 와 같은 목록) */
export const LANGUAGES = [
  'plain', 'bash', 'c', 'cpp', 'csharp', 'css', 'diff', 'dockerfile', 'go', 'graphql',
  'groovy', 'html', 'ini', 'java', 'javascript', 'json', 'kotlin', 'latex', 'lua', 'makefile',
  'markdown', 'mermaid', 'nginx', 'objectivec', 'perl', 'php', 'powershell', 'python', 'r', 'ruby',
  'rust', 'scala', 'shell', 'sql', 'swift', 'toml', 'typescript', 'vbnet', 'xml', 'yaml'
];

const ALIGNS = ['left', 'center', 'right'];
const SIZES = ['small', 'normal', 'large', 'xlarge'];

/** 블록 id 용 UUIDv4. `crypto.getRandomValues` 로만 만든다(예측 가능한 id 를 만들지 않는다). */
export function uuid4() {
  const b = new Uint8Array(16);
  crypto.getRandomValues(b);
  b[6] = (b[6] & 0x0f) | 0x40;
  b[8] = (b[8] & 0x3f) | 0x80;
  const h = [];
  for (let i = 0; i < 16; i++) { h.push(b[i].toString(16).padStart(2, '0')); }
  const s = h.join('');
  return s.slice(0, 8) + '-' + s.slice(8, 12) + '-' + s.slice(12, 16) + '-' + s.slice(16, 20) + '-' + s.slice(20);
}

/** 색 값이 허용 목록 안인가 (글자 9 + 배경 9) */
export function colorAllowed(value) {
  if (typeof value !== 'string') { return false; }
  const base = value.slice(-3) === '_bg' ? value.slice(0, -3) : value;
  return COLORS.indexOf(base) !== -1;
}

/** 색 이름 → 클래스 (doc-render.js 의 `colorClass` 와 같은 규칙) */
function colorClass(value) {
  if (!colorAllowed(value)) { return null; }
  return value.slice(-3) === '_bg' ? 'nt-' + value.slice(0, -3) + '-bg' : 'nt-' + value;
}

/** 블록 공통 속성 — id·color·align·size */
const commonAttrs = {
  id: { default: null },
  color: { default: null },
  align: { default: 'left' },
  size: { default: 'normal' }
};

/** 공통 속성을 클래스 문자열로 (읽기 렌더러와 **같은 순서**로 붙인다) */
function blockClass(base, attrs) {
  const out = [base];
  const c = colorClass(attrs.color);
  if (c) { out.push(c); }
  if (attrs.align === 'center' || attrs.align === 'right') { out.push('al-' + attrs.align); }
  if (SIZES.indexOf(attrs.size) !== -1 && attrs.size !== 'normal') { out.push('sz-' + attrs.size); }
  return out.join(' ');
}

/** 붙여넣은 DOM 에서 공통 속성을 읽는다 — 허용 목록 밖 값은 기본값이 된다(우리 것만 남는다) */
function readCommon(dom) {
  const cls = (dom.getAttribute('class') || '').split(/\s+/);
  let color = null;
  let align = 'left';
  let size = 'normal';
  for (const c of cls) {
    if (c.indexOf('nt-') === 0) {
      const name = c.slice(-3) === '-bg' ? c.slice(3, -3) + '_bg' : c.slice(3);
      if (colorAllowed(name)) { color = name; }
    } else if (c.indexOf('al-') === 0 && ALIGNS.indexOf(c.slice(3)) !== -1) {
      align = c.slice(3);
    } else if (c.indexOf('sz-') === 0 && SIZES.indexOf(c.slice(3)) !== -1) {
      size = c.slice(3);
    }
  }
  return { id: null, color: color, align: align, size: size };
}

/**
 * 우리 스키마 — 여기 없는 노드·마크는 편집기 안에 **존재할 수 없다**.
 *
 * 목록(bulleted·numbered)은 중첩 목록이 아니라 **평평한 블록**이다. 우리 문서 모델이 그렇게
 * 생겼기 때문이다(ARCH 12.2 — `bulleted` 는 최상위 블록이고, 이어진 것들을 읽기 렌더러가
 * `<ul>` 로 묶는다). 편집기 안에서도 같은 모양이라야 변환이 한 겹으로 끝난다.
 */
export const schema = new Schema({
  nodes: {
    doc: { content: 'block+' },

    text: { group: 'inline' },

    paragraph: {
      group: 'block',
      content: 'inline*',
      attrs: commonAttrs,
      parseDOM: [{ tag: 'p', getAttrs: readCommon }],
      toDOM: (node) => ['p', { class: blockClass('doc-p', node.attrs) }, 0]
    },

    heading: {
      group: 'block',
      content: 'inline*',
      defining: true,
      attrs: Object.assign({ level: { default: 1 } }, commonAttrs),
      parseDOM: [1, 2, 3].map((level) => ({
        tag: 'h' + level,
        getAttrs: (dom) => Object.assign(readCommon(dom), { level: level })
      })),
      toDOM: (node) => ['h' + node.attrs.level, { class: blockClass('doc-h' + node.attrs.level, node.attrs) }, 0]
    },

    bulleted: {
      group: 'block',
      content: 'inline*',
      attrs: commonAttrs,
      // 붙여넣기: `<ul><li>` 과 그 밖의 `<li>` 는 글머리 목록이 된다.
      // 번호 목록(`<ol><li>`)은 아래에서 더 높은 우선순위로 가져간다.
      parseDOM: [{ tag: 'ul li', getAttrs: readCommon }, { tag: 'li', getAttrs: readCommon, priority: 30 }],
      toDOM: (node) => ['div', { class: blockClass('doc-li', node.attrs), 'data-kind': 'bulleted' }, 0]
    },

    numbered: {
      group: 'block',
      content: 'inline*',
      attrs: commonAttrs,
      parseDOM: [{ tag: 'ol li', getAttrs: readCommon, priority: 60 }],
      toDOM: (node) => ['div', { class: blockClass('doc-li', node.attrs), 'data-kind': 'numbered' }, 0]
    },

    to_do: {
      group: 'block',
      content: 'inline*',
      attrs: Object.assign({ checked: { default: false } }, commonAttrs),
      toDOM: (node) => [
        'div',
        { class: blockClass('doc-todo' + (node.attrs.checked ? ' is-done' : ''), node.attrs) },
        ['span', { class: 'doc-todo__box', contenteditable: 'false', 'aria-hidden': 'true' }, node.attrs.checked ? '✔' : ''],
        ['span', { class: 'doc-todo__text' }, 0]
      ]
    },

    quote: {
      group: 'block',
      content: 'inline*',
      attrs: commonAttrs,
      parseDOM: [{ tag: 'blockquote', getAttrs: readCommon }],
      toDOM: (node) => ['blockquote', { class: blockClass('doc-quote', node.attrs) }, 0]
    },

    callout: {
      group: 'block',
      content: 'inline*',
      attrs: Object.assign({ icon: { default: '💡' } }, commonAttrs),
      toDOM: (node) => [
        'div',
        { class: blockClass('doc-callout', node.attrs) },
        ['span', { class: 'doc-callout__icon', contenteditable: 'false', 'aria-hidden': 'true' }, node.attrs.icon],
        ['span', { class: 'doc-callout__text' }, 0]
      ]
    },

    divider: {
      group: 'block',
      atom: true,
      selectable: true,
      attrs: { id: { default: null } },
      parseDOM: [{ tag: 'hr' }],
      toDOM: () => ['hr', { class: 'doc-divider' }]
    },

    /*
     * ★ 노드 이름이 `code` 가 **아니다**. ProseMirror 의 `Schema` 는 같은 이름이 노드와 마크에
     *   둘 다 있으면 「code can not be both a node and a mark」 로 **예외를 던지고**, 그러면
     *   이 모듈의 평가가 거기서 멈춰 커뮤니티 화면의 JS 가 통째로 죽는다
     *   (2026-09-22 관문 ③ 실측 — 「새 페이지」 단추가 눌리지 않았다).
     *   우리 **JSON 의 블록 타입은 `code` 그대로**이고, 이름을 옮기는 일은 아래
     *   `toDoc`/`fromDoc` 이 한 곳에서만 한다.
     */
    code_block: {
      group: 'block',
      content: 'text*',
      marks: '',
      code: true,
      defining: true,
      attrs: Object.assign({ language: { default: 'plain' } }, commonAttrs),
      parseDOM: [{ tag: 'pre', preserveWhitespace: 'full' }],
      toDOM: (node) => ['pre', { class: 'doc-code', 'data-lang': node.attrs.language }, ['code', 0]]
    },

    toc: {
      group: 'block',
      atom: true,
      selectable: true,
      attrs: { id: { default: null } },
      toDOM: () => ['div', { class: 'doc-toc', contenteditable: 'false' }, '목차 (읽기 모드에서 제목이 채워집니다)']
    }
  },

  marks: {
    bold: {
      parseDOM: [{ tag: 'strong' }, { tag: 'b' }, { style: 'font-weight=bold' }],
      toDOM: () => ['strong', 0]
    },
    italic: {
      parseDOM: [{ tag: 'em' }, { tag: 'i' }],
      toDOM: () => ['em', 0]
    },
    underline: {
      parseDOM: [{ tag: 'u' }],
      toDOM: () => ['u', 0]
    },
    strike: {
      parseDOM: [{ tag: 's' }, { tag: 'del' }],
      toDOM: () => ['s', 0]
    },
    code: {
      parseDOM: [{ tag: 'code' }],
      toDOM: () => ['code', { class: 'doc-inline-code' }, 0]
    },
    color: {
      attrs: { color: { default: 'gray' } },
      parseDOM: [{
        tag: 'span[class]',
        getAttrs: (dom) => {
          const cls = (dom.getAttribute('class') || '').split(/\s+/);
          for (const c of cls) {
            if (c.indexOf('nt-') !== 0) { continue; }
            const name = c.slice(-3) === '-bg' ? c.slice(3, -3) + '_bg' : c.slice(3);
            if (colorAllowed(name)) { return { color: name }; }
          }
          return false;
        }
      }],
      toDOM: (mark) => ['span', { class: colorClass(mark.attrs.color) || '' }, 0]
    },
    link: {
      attrs: { href: {} },
      inclusive: false,
      parseDOM: [{
        tag: 'a[href]',
        getAttrs: (dom) => {
          const href = dom.getAttribute('href') || '';
          // 허용 스킴이 아니면 **마크 자체를 만들지 않는다** — 붙여넣기로 들어온
          // `javascript:` 는 링크가 아니라 글자가 된다.
          return schemeOkForEditor(href) ? { href: href } : false;
        }
      }],
      toDOM: (mark) => ['a', { class: 'doc-link', href: mark.attrs.href }, 0]
    }
  }
});

/** 링크 스킴 검사 — `Doc::schemeOk`·`doc-render.js` 와 같은 규칙 */
export function schemeOkForEditor(href) {
  if (typeof href !== 'string' || href.length > 2048) { return false; }
  if (/[\u0000-\u001f\u007f]/.test(href)) { return false; }
  if (/^https:\/\/[^\s/?#]+\S*$/.test(href)) { return true; }
  if (/^http:\/\/[^\s/?#]+\S*$/.test(href)) { return true; }
  if (/^mailto:[^\s@]{1,128}@[^\s@]{1,128}$/.test(href)) { return true; }
  if (/^page:[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/.test(href)) { return true; }
  return false;
}

/* ─── 우리 JSON → ProseMirror ──────────────────────────────────── */

/** 리치 텍스트 → ProseMirror 의 text 노드 배열 */
function richToPM(text) {
  const out = [];
  if (!Array.isArray(text)) { return out; }
  for (const seg of text) {
    if (!seg || typeof seg.t !== 'string' || seg.t === '') { continue; }
    const marks = [];
    const m = seg.m && typeof seg.m === 'object' ? seg.m : {};
    for (const name of ['bold', 'italic', 'underline', 'strike', 'code']) {
      if (m[name] === true) { marks.push({ type: name }); }
    }
    if (typeof m.color === 'string' && colorAllowed(m.color)) {
      marks.push({ type: 'color', attrs: { color: m.color } });
    }
    if (m.link && typeof m.link.href === 'string' && schemeOkForEditor(m.link.href)) {
      marks.push({ type: 'link', attrs: { href: m.link.href } });
    }
    out.push({ type: 'text', text: seg.t, marks: marks });
  }
  return out;
}

/**
 * 우리 문서 → ProseMirror 문서 JSON.
 *
 * 편집기가 다룰 수 없는 것을 만나면 **예외**다. 부르는 쪽(editor.js)이 그것을 잡아
 * 「이 문서는 아직 편집할 수 없습니다」로 안내하고 읽기 모드에 머문다.
 */
export function fromDoc(doc) {
  const blocks = doc && Array.isArray(doc.blocks) ? doc.blocks : [];
  const content = [];

  for (const b of blocks) {
    if (!b || typeof b.type !== 'string') {
      throw new Error('모르는 블록이 들어 있습니다');
    }
    if (Array.isArray(b.children) && b.children.length > 0) {
      throw new Error('하위 블록이 들어 있는 문서는 아직 편집할 수 없습니다');
    }
    const attrs = {
      id: typeof b.id === 'string' ? b.id : null,
      color: colorAllowed(b.color) ? b.color : null,
      align: ALIGNS.indexOf(b.align) !== -1 ? b.align : 'left',
      size: SIZES.indexOf(b.size) !== -1 ? b.size : 'normal'
    };

    if (b.type === 'divider' || b.type === 'toc') {
      content.push({ type: b.type, attrs: { id: attrs.id } });
      continue;
    }
    if (b.type === 'code') {
      // 우리 `code` → ProseMirror `code_block` (이름이 갈리는 자리는 여기와 toDoc 둘뿐이다)
      const text = typeof b.code === 'string' ? b.code : '';
      content.push({
        type: 'code_block',
        attrs: Object.assign({ language: LANGUAGES.indexOf(b.language) !== -1 ? b.language : 'plain' }, attrs),
        content: text === '' ? [] : [{ type: 'text', text: text }]
      });
      continue;
    }
    if (['paragraph', 'bulleted', 'numbered', 'quote'].indexOf(b.type) !== -1) {
      content.push({ type: b.type, attrs: attrs, content: richToPM(b.text) });
      continue;
    }
    if (b.type === 'heading') {
      const level = b.level === 2 || b.level === 3 ? b.level : 1;
      content.push({ type: 'heading', attrs: Object.assign({ level: level }, attrs), content: richToPM(b.text) });
      continue;
    }
    if (b.type === 'to_do') {
      content.push({
        type: 'to_do',
        attrs: Object.assign({ checked: b.checked === true }, attrs),
        content: richToPM(b.text)
      });
      continue;
    }
    if (b.type === 'callout') {
      content.push({
        type: 'callout',
        attrs: Object.assign({ icon: typeof b.icon === 'string' && b.icon !== '' ? b.icon : '💡' }, attrs),
        content: richToPM(b.text)
      });
      continue;
    }
    throw new Error('이 편집기가 아직 모르는 블록입니다: ' + b.type);
  }

  // 문서가 비어 있으면 빈 문단 하나를 둔다 — ProseMirror 의 `block+` 를 만족시키기 위해서다.
  if (content.length === 0) {
    content.push({ type: 'paragraph', attrs: { id: uuid4(), color: null, align: 'left', size: 'normal' }, content: [] });
  }
  return { type: 'doc', content: content };
}

/* ─── ProseMirror → 우리 JSON ──────────────────────────────────── */

/** ProseMirror 의 text 노드 배열 → 리치 텍스트 */
function pmToRich(content) {
  const out = [];
  if (!Array.isArray(content)) { return out; }
  for (const node of content) {
    if (!node || node.type !== 'text' || typeof node.text !== 'string' || node.text === '') { continue; }
    const m = {};
    for (const mark of Array.isArray(node.marks) ? node.marks : []) {
      if (!mark || typeof mark.type !== 'string') { continue; }
      if (['bold', 'italic', 'underline', 'strike', 'code'].indexOf(mark.type) !== -1) {
        m[mark.type] = true;
      } else if (mark.type === 'color' && mark.attrs && colorAllowed(mark.attrs.color)) {
        m.color = mark.attrs.color;
      } else if (mark.type === 'link' && mark.attrs && schemeOkForEditor(mark.attrs.href)) {
        m.link = { href: mark.attrs.href };
      }
    }
    // 줄바꿈이 아닌 제어문자는 우리 형식이 받지 않는다(서버가 400 이다). 저장 전에 지운다 —
    // **편집기가 만든 자기 출력**을 정리하는 것이고, 사용자가 쓴 글자를 바꾸는 것이 아니다.
    const clean = node.text.replace(/[\u0000-\u0009\u000b-\u001f\u007f]/g, '');
    if (clean === '') { continue; }
    out.push({ t: clean, m: m });
  }
  return out;
}

/**
 * ProseMirror 문서 JSON → 우리 문서.
 *
 * 블록 id 는 **이미 붙어 있어야 한다**(editor.js 의 id 플러그인이 붙인다). 없으면 여기서
 * 새로 만든다 — 없는 채로 서버에 보내면 400 이기 때문이고, 그 자리는 새 블록뿐이다.
 */
export function toDoc(pmDoc) {
  const blocks = [];
  const content = pmDoc && Array.isArray(pmDoc.content) ? pmDoc.content : [];

  for (const node of content) {
    if (!node || typeof node.type !== 'string') { continue; }
    const a = node.attrs || {};
    // ProseMirror `code_block` → 우리 `code`. 그 밖의 이름은 양쪽이 같다.
    const ourType = node.type === 'code_block' ? 'code' : node.type;
    const block = { id: typeof a.id === 'string' && a.id !== '' ? a.id : uuid4(), type: ourType };

    if (ourType !== 'divider' && ourType !== 'toc') {
      if (colorAllowed(a.color)) { block.color = a.color; }
      if (a.align === 'center' || a.align === 'right') { block.align = a.align; }
      if (a.size && a.size !== 'normal' && SIZES.indexOf(a.size) !== -1) { block.size = a.size; }
    }

    if (ourType === 'divider' || ourType === 'toc') {
      blocks.push(block);
      continue;
    }
    if (ourType === 'code') {
      let text = '';
      for (const t of Array.isArray(node.content) ? node.content : []) {
        if (t && typeof t.text === 'string') { text += t.text; }
      }
      block.code = text.replace(/[\u0000-\u0008\u000b-\u001f\u007f]/g, '');
      block.language = LANGUAGES.indexOf(a.language) !== -1 ? a.language : 'plain';
      blocks.push(block);
      continue;
    }
    if (ourType === 'heading') {
      block.level = a.level === 2 || a.level === 3 ? a.level : 1;
    }
    if (ourType === 'to_do') {
      block.checked = a.checked === true;
    }
    if (ourType === 'callout') {
      block.icon = typeof a.icon === 'string' && a.icon !== '' ? a.icon : '💡';
    }
    block.text = pmToRich(node.content);
    blocks.push(block);
  }

  return { schema: 1, blocks: blocks };
}

/**
 * `/` 슬래시 메뉴에 나오는 것들 (ARCH 14.3). 3단계의 블록 10종 그대로이고, 순서는
 * 「자주 쓰는 것부터」다. `keywords` 는 한글·영문 둘 다 받아 좁힌다.
 */
export const BLOCK_MENU = [
  { type: 'paragraph', label: '본문', hint: '보통 글', keywords: 'text 본문 문단 글' },
  { type: 'heading', attrs: { level: 1 }, label: '제목 1', hint: '큰 제목', keywords: 'h1 heading 제목' },
  { type: 'heading', attrs: { level: 2 }, label: '제목 2', hint: '중간 제목', keywords: 'h2 heading 제목' },
  { type: 'heading', attrs: { level: 3 }, label: '제목 3', hint: '작은 제목', keywords: 'h3 heading 제목' },
  { type: 'bulleted', label: '글머리 목록', hint: '• 로 시작', keywords: 'bullet list 목록 점' },
  { type: 'numbered', label: '번호 목록', hint: '1. 로 시작', keywords: 'number list 번호 목록' },
  { type: 'to_do', label: '할 일', hint: '체크 상자', keywords: 'todo check 할일 체크' },
  { type: 'quote', label: '인용', hint: '왼쪽 선', keywords: 'quote 인용' },
  { type: 'callout', label: '강조 상자', hint: '아이콘 + 배경', keywords: 'callout 강조 상자 안내' },
  { type: 'divider', label: '구분선', hint: '가로줄', keywords: 'divider hr 구분선 줄' },
  { type: 'code_block', label: '코드', hint: '고정폭 + 언어', keywords: 'code 코드' },
  { type: 'toc', label: '목차', hint: '제목 모으기', keywords: 'toc 목차 차례' }
];
