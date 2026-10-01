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
 *              ★ 2026-09-23 사용자 결정으로 **에디터는 bold·italic·underline·strike·code 를
 *                만들지 않는다**(툴바에 단추도 단축키도 없다). 마크 정의는 여기 **그대로 둔다** —
 *                서버 `doc-schema.json`·`Doc::validate`·렌더러 두 벌이 무변경이고, 이미 그
 *                마크가 든 글을 **읽을 때 전과 똑같이 보여야** 하기 때문이다.
 *   **하위 블록(children)은 만들지 않는다.** 트리는 4단계다 — 들어 있는 문서를 만나면
 *   `fromDoc` 이 예외를 던지고 편집기가 열리지 않는다(읽기는 된다). 조용히 펴서 저장하면
 *   사용자가 쓴 구조가 말없이 사라진다.
 */

import { Schema, DOMParser } from './vendor/pm/prosemirror-model.js?v=5823578fa61a';

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

/**
 * 7-D2 관리자 팔레트 값의 모양 — `pal:<이름>`(글자) · `pal:<이름>_bg`(배경). 이름 `[a-z0-9-]{1,20}` · 끝 `-bg` 아님
 * (Doc::isPaletteColor · doc-render.js `paletteClass` 와 같은 규칙). **목록은 보지 않는다** — 뺀 이름이 든 글도 그대로 열리고 저장된다(P-8·P-10).
 * 정규식은 `match()` 로만 쓴다(보안 린트).
 */
const PALETTE_RE = /^pal:([a-z0-9-]{1,20})(_bg)?$/;
function paletteParts(value) {
  if (typeof value !== 'string') { return null; }
  const m = value.match(PALETTE_RE);
  if (!m || m[1].slice(-3) === '-bg') { return null; }
  return { name: m[1], bg: !!m[2] };
}

/** 색 값이 허용 목록 안인가 (글자 9 + 배경 9 · 7-D2 팔레트 모양) */
export function colorAllowed(value) {
  if (typeof value !== 'string') { return false; }
  if (paletteParts(value)) { return true; }
  const base = value.slice(-3) === '_bg' ? value.slice(0, -3) : value;
  return COLORS.indexOf(base) !== -1;
}

/** 색 이름 → 클래스 (doc-render.js 의 `colorClass` 와 같은 규칙 — 팔레트는 `pal-<이름>`·`pal-<이름>-bg`) */
function colorClass(value) {
  if (!colorAllowed(value)) { return null; }
  const pal = paletteParts(value);
  if (pal) { return 'pal-' + pal.name + (pal.bg ? '-bg' : ''); }
  return value.slice(-3) === '_bg' ? 'nt-' + value.slice(0, -3) + '-bg' : 'nt-' + value;
}

/**
 * 붙여넣은 DOM 의 클래스 하나 → 색 값 (없으면 null). `nt-<색>`·`nt-<색>-bg` 와 7-D2 `pal-<이름>`·`pal-<이름>-bg`.
 * `pal-x-bg` 는 먼저 「x 의 배경」으로 읽고, 그 이름이 모양 밖이면(`pal-bg` = 이름 `bg` 의 글자색) 글자색으로 읽는다 —
 * 이름이 `-bg` 로 끝날 수 없어서 두 읽기가 겹치지 않는다.
 */
function classColor(c) {
  if (c.indexOf('nt-') === 0) {
    const name = c.slice(-3) === '-bg' ? c.slice(3, -3) + '_bg' : c.slice(3);
    return colorAllowed(name) && !paletteParts(name) ? name : null;
  }
  if (c.indexOf('pal-') === 0) {
    if (c.slice(-3) === '-bg') {
      const bg = 'pal:' + c.slice(4, -3) + '_bg';
      if (paletteParts(bg)) { return bg; }
    }
    const fg = 'pal:' + c.slice(4);
    return paletteParts(fg) ? fg : null;
  }
  return null;
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
    if (c.indexOf('nt-') === 0 || c.indexOf('pal-') === 0) {
      const name = classColor(c);
      if (name) { color = name; }
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

    button: {
      group:'block', atom:true, selectable:true, attrs:{id:{default:null},label:{default:''},automation_id:{default:null}},
      toDOM: node => ['button',{type:'button',class:'btn',disabled:'disabled'},node.attrs.label]
    },
    toc: {
      group: 'block',
      atom: true,
      selectable: true,
      attrs: { id: { default: null } },
      toDOM: () => ['div', { class: 'doc-toc', contenteditable: 'false' }, '목차']
    },

    /*
     * 하위 페이지 (4단계 `child_page`).
     *
     * 우리 JSON 에 저장되는 것은 `page_id` **하나**다. `title` 은 편집기 안에서만 쓰는
     * **보여 주기용** 속성이라 `toDoc` 이 버린다 — 제목의 진실은 그 페이지 행이고, 여기 베껴
     * 두면 제목을 바꾼 뒤 두 값이 어긋난다.
     * 글자를 담지 않는 덩어리(atom)라 `divider`·`toc` 와 같은 갈래로 다룬다.
     */
    child_page: {
      group: 'block',
      atom: true,
      selectable: true,
      attrs: { id: { default: null }, page_id: { default: null }, title: { default: '' } },
      toDOM: (node) => [
        'div',
        { class: 'card card--soon doc-child-page', contenteditable: 'false' },
        ['span', { class: 'card__title' }, '📄 ' + (node.attrs.title || '하위 페이지')]
      ]
    },

    /*
     * 5-E 새 블록 다섯 — 전부 **덩어리(atom)** 이고 편집기 안에서는 요약 카드로 보인다.
     * 값은 창(editor.js 「블록 고치기」)에서 고치고, 카드를 **두 번 누르면** 창이 다시 열린다(결정 E-2).
     * 편집기가 평평한 블록 구조라 하위 블록을 가진 토글은 「평문 문단들」로만 다룬다(`kids`).
     * 보여 주기용 속성(`title`·`name`)은 `toDoc` 이 버린다 — 진실은 그 페이지·그 파일 행이다.
     */
    toggle: {
      group: 'block', atom: true, selectable: true,
      attrs: { id: { default: null }, summary: { default: '' }, kids: { default: [] } },
      toDOM: (node) => ['div', { class: 'card card--link doc-atom', contenteditable: 'false', 'data-atom': 'toggle' },
        ['span', { class: 'card__title' }, '▸ ' + (node.attrs.summary || '토글')],
        ['span', { class: 'card__meta' }, '문단 ' + String((node.attrs.kids || []).length) + '개 · 두 번 눌러 고치기']]
    },
    table: {
      group: 'block', atom: true, selectable: true,
      // 6-C — spans: 합친 칸 {'행,열': [세로, 가로]} (시작 칸만). 덮이는 칸은 비어 있다.
      attrs: { id: { default: null }, header_row: { default: true }, header_col: { default: false }, rows: { default: [['', ''], ['', '']] }, spans: { default: {} } },
      toDOM: (node) => ['div', { class: 'card card--link doc-atom', contenteditable: 'false', 'data-atom': 'table' },
        ['span', { class: 'card__title' }, '표 ' + String((node.attrs.rows || []).length) + '×' + String(((node.attrs.rows || [])[0] || []).length)],
        ['span', { class: 'card__meta' }, (((node.attrs.rows || [])[0] || []).join(' · ') || '빈 표') + ' · 두 번 눌러 고치기']]
    },
    /* 6-C 단 나누기 — 덩어리 + 창(결정 I-2). cols = [{id, kids:[{id, text}]}] 2~3개, 단 안은 평문 문단만. */
    columns: {
      group: 'block', atom: true, selectable: true,
      attrs: { id: { default: null }, cols: { default: [] } },
      toDOM: (node) => ['div', { class: 'card card--link doc-atom', contenteditable: 'false', 'data-atom': 'columns' },
        ['span', { class: 'card__title' }, '단 나누기 ' + String((node.attrs.cols || []).length) + '단'],
        ['span', { class: 'card__meta' }, (node.attrs.cols || []).map((c) => ((c.kids || [])[0] || {}).text || '빈 단').join(' | ') + ' · 두 번 눌러 고치기']]
    },
    link_to_page: {
      group: 'block', atom: true, selectable: true,
      attrs: { id: { default: null }, page_id: { default: null }, title: { default: '' } },
      toDOM: (node) => ['div', { class: 'card card--link doc-atom', contenteditable: 'false', 'data-atom': 'link_to_page' },
        ['span', { class: 'card__title' }, '🔗 ' + (node.attrs.title || '페이지 링크')],
        ['span', { class: 'card__meta' }, '두 번 눌러 바꾸기']]
    },
    image: {
      group: 'block', atom: true, selectable: true,
      attrs: { id: { default: null }, file_ref: { default: null }, caption: { default: '' }, width: { default: 'normal' }, name: { default: '' } },
      toDOM: (node) => ['div', { class: 'card card--link doc-atom', contenteditable: 'false', 'data-atom': 'image' },
        ['span', { class: 'card__title' }, '🖼 ' + (node.attrs.name || '그림')],
        ['span', { class: 'card__meta' }, (node.attrs.caption || '설명 없음') + ' · 두 번 눌러 고치기']]
    },
    file: {
      group: 'block', atom: true, selectable: true,
      attrs: { id: { default: null }, file_ref: { default: null }, caption: { default: '' }, name: { default: '' } },
      toDOM: (node) => ['div', { class: 'card card--link doc-atom', contenteditable: 'false', 'data-atom': 'file' },
        ['span', { class: 'card__title' }, '📎 ' + (node.attrs.name || '파일')],
        ['span', { class: 'card__meta' }, (node.attrs.caption || '설명 없음') + ' · 두 번 눌러 고치기']]
    },
    // 7-C 임베드 — 편집기 안에서는 요약 카드(주소는 글자로만 보인다 — 편집 중에는 틀을 열지 않는다)
    embed: {
      group: 'block', atom: true, selectable: true,
      attrs: { id: { default: null }, url: { default: '' }, caption: { default: '' } },
      toDOM: (node) => ['div', { class: 'card card--link doc-atom', contenteditable: 'false', 'data-atom': 'embed' },
        ['span', { class: 'card__title' }, '🧩 임베드 — ' + (node.attrs.url || '주소 없음')],
        ['span', { class: 'card__meta' }, (node.attrs.caption || '설명 없음') + ' · 두 번 눌러 고치기']]
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
            const name = classColor(c);
            if (name) { return { color: name }; }
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
    },
    /* 7-D4 글 안 멘션 — 글자는 「@」 하나, 이름은 저장하지 않는다(`label` 은 편집기 안에서만 · `key` 는 이웃한 같은 대상 멘션이 한 조각으로
       합쳐지지 않게 하는 편집기 안 값). `inclusive: false` — 뒤에 친 글자가 멘션에 붙지 않는다. **`parseDOM` 없음** — 붙여넣기로는
       멘션이 생기지 않는다(14.3). 링크와 함께 있을 수 없다(`excludes` — 서버 검증기도 400). 라벨은 CSS `.editor .doc-mention::after` 가
       `data-label` 로 보인다(인라인 style 0). `data-mention-*` 셋은 편집기가 **자기 DOM 을 다시 읽을 때**(`editorDomParser` — 멘션 바로 뒤에 친
       글자·한글 조합이 그 span 안에 들어가 ProseMirror 가 그 자리를 다시 읽는다) 마크를 되살리는 재료다 — 붙여넣기 파서에는 이 규칙이 없다. */
    mention: {
      attrs: { kind: {}, id: {}, label: { default: '' }, key: { default: null } },
      inclusive: false,
      excludes: 'mention link',
      toDOM: (mark) => ['span', {
        class: 'doc-mention', 'data-label': String(mark.attrs.label || ''),
        'data-mention-kind': String(mark.attrs.kind), 'data-mention-id': String(mark.attrs.id), 'data-mention-key': String(mark.attrs.key || '')
      }, 0]
    }
  }
});

/**
 * 7-D4 글 안 멘션 `{kind, id}` 의 모양 — `Doc::mentionShapeOk`·`doc-render.js` `mentionShapeOk` 와 같은 규칙
 * (사람 = 정수 1~2⁵³ · 페이지 = 소문자 UUID · 날짜 = YYYY-MM-DD 1900~2100 있는 날). 정규식은 `test()`·`match()` 로만.
 */
const MENTION_UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/;
const MENTION_DATE_RE = /^(\d{4})-(\d{2})-(\d{2})$/;
export function mentionAllowed(kind, id) {
  if (kind === 'user') { return Number.isInteger(id) && id >= 1 && id <= 9007199254740992; }
  if (kind === 'page') { return typeof id === 'string' && MENTION_UUID_RE.test(id); }
  if (kind !== 'date' || typeof id !== 'string') { return false; }
  const m = id.match(MENTION_DATE_RE);
  if (!m) { return false; }
  const y = Number(m[1]);
  const mo = Number(m[2]);
  const d = Number(m[3]);
  if (y < 1900 || y > 2100 || mo < 1 || mo > 12 || d < 1) { return false; }
  const leap = (y % 4 === 0 && y % 100 !== 0) || y % 400 === 0;
  return d <= [31, leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31][mo - 1];
}
function mentionValueOk(v) {
  if (!v || typeof v !== 'object' || Array.isArray(v)) { return false; }
  const keys = Object.keys(v);
  return keys.length === 2 && keys.indexOf('kind') !== -1 && keys.indexOf('id') !== -1 && mentionAllowed(v.kind, v.id);
}

/** 7-D4 — 편집기 안에서 멘션 뒤에 보일 라벨(저장하지 않는다). 사람·페이지는 창구가 준 지도, 날짜는 id, 없으면 읽기 렌더러와 같은 「볼 수 없음」 */
export const MENTION_UNREADABLE = '볼 수 없음';
export function mentionLabel(kind, id, labels) {
  if (kind === 'date') { return String(id); }
  const map = labels && typeof labels === 'object' ? labels : {};
  const key = (kind === 'user' ? 'user:' : 'page:') + String(id);
  const entry = Object.prototype.hasOwnProperty.call(map, key) ? map[key] : null;
  return entry && typeof entry === 'object' && typeof entry.label === 'string' ? entry.label : MENTION_UNREADABLE;
}

/**
 * 7-D4 — 파서 두 벌(EditorView 에 넘긴다).
 *   · `editorDomParser` — 편집기가 **자기 DOM** 을 다시 읽을 때(친 글자·조합)만 쓴다. 스키마 규칙 + 멘션 규칙 하나: 우리 `toDOM` 이 그린
 *     `span.doc-mention[data-mention-kind]` 에서 kind·id 를 되읽고 모양이 맞을 때만 마크(아니면 글자). 이것이 없으면 멘션 바로 뒤에 친
 *     글자가 span 안에 들어간 순간 그 자리를 다시 읽으며 「@」 의 마크가 사라진다(2026-09-27 첫 수락 시험에서 겪음).
 *   · `clipboardDomParser` — 붙여넣기·끌어 놓기. **스키마 그대로**(멘션 규칙 없음 — `mention` 마크에 `parseDOM` 이 없다) — 붙여넣은 HTML
 *     에서는 멘션이 생기지 않는다(지시서 M-7 · 14.3).
 */
const MENTION_DOM_RULE = {
  tag: 'span.doc-mention[data-mention-kind]',
  mark: 'mention',
  getAttrs: (dom) => {
    const kind = dom.getAttribute('data-mention-kind');
    const raw = dom.getAttribute('data-mention-id') || '';
    const id = kind === 'user' && /^[0-9]{1,16}$/.test(raw) ? Number(raw) : raw;
    if (!mentionAllowed(kind, id)) { return false; }
    return { kind: kind, id: id, label: dom.getAttribute('data-label') || '', key: dom.getAttribute('data-mention-key') || null };
  }
};
export const clipboardDomParser = DOMParser.fromSchema(schema);
export const editorDomParser = new DOMParser(schema, [MENTION_DOM_RULE].concat(clipboardDomParser.rules));

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

/**
 * 리치 텍스트 → ProseMirror 의 text 노드 배열.
 * 7-D4 — 멘션 마크는 글자가 「@」 이고 모양이 맞을 때만 옮기고, 라벨은 창구가 준 `mention_labels`(없으면 「볼 수 없음」)에서 붙인다.
 */
function richToPM(text, labels) {
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
    const isMention = seg.t === '@' && mentionValueOk(m.mention) && !m.link;
    if (!isMention && m.link && typeof m.link.href === 'string' && schemeOkForEditor(m.link.href)) {
      marks.push({ type: 'link', attrs: { href: m.link.href } });
    }
    if (isMention) {
      marks.push({ type: 'mention', attrs: { kind: m.mention.kind, id: m.mention.id, label: mentionLabel(m.mention.kind, m.mention.id, labels), key: uuid4() } });
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
/** 리치 텍스트 → 평문(5-E 덩어리 편집용). 줄바꿈이 아닌 제어 문자는 지운다. */
function plainOf(text) {
  let out = '';
  for (const seg of Array.isArray(text) ? text : []) {
    if (seg && typeof seg.t === 'string') { out += seg.t; }
  }
  return out;
}

/**
 * 5-E 덩어리가 **평문 창으로 손실 없이** 다룰 수 있는 블록인가 (독립 검토 M2).
 * 꾸밈(글자 마크·블록 색·정렬·크기·행/칸 색·열 폭)이 하나라도 있으면 거짓 — 그때 `fromDoc` 이 던져 편집기를 열지 않는다.
 * 창을 거치며 꾸밈이 **말없이 지워지는** 것보다, 편집을 막고 읽기 전용으로 두는 쪽이 낫다(API 로 만든 문서 보호).
 */
function richIsPlain(text) {
  for (const seg of Array.isArray(text) ? text : []) {
    if (seg && seg.m && typeof seg.m === 'object' && Object.keys(seg.m).length > 0) { return false; }
  }
  return true;
}
function blockIsPlain(b) {
  return (b.color === undefined || b.color === null)
    && (b.align === undefined || b.align === 'left')
    && (b.size === undefined || b.size === 'normal');
}
function atomIsPlain(b) {
  if (!blockIsPlain(b) || !richIsPlain(b.text) || !richIsPlain(b.caption)) { return false; }
  if (b.type === 'toggle') {
    return (Array.isArray(b.children) ? b.children : []).every((k) => k && blockIsPlain(k) && richIsPlain(k.text));
  }
  if (b.type === 'table') {
    if (b.col_widths !== undefined) { return false; }
    for (const r of Array.isArray(b.rows) ? b.rows : []) {
      if (!r || (r.color !== undefined && r.color !== null)) { return false; }
      for (const c of Array.isArray(r.cells) ? r.cells : []) {
        if (!c || (c.color !== undefined && c.color !== null) || !richIsPlain(c.text)) { return false; }
      }
    }
  }
  return true;
}

/**
 * 평문 창을 거쳐도 **그대로** 돌아오는 글인가(독립 검토 6-C L2) — 줄바꿈이 없고 2,000자 이하이며 앞뒤 공백이 없다.
 * 아니면 창을 거치며 모양이 바뀌므로(줄바꿈 → 빈칸, 긴 글 합치기 → too_long) 편집기를 열지 않는다.
 */
function plainRoundTrips(text) {
  const t = plainOf(text);
  return t.indexOf('\n') === -1 && t.length <= 2000 && t === t.trim();
}

/** 평문 → 리치 텍스트 한 조각(비면 빈 배열). 제어 문자는 빈칸으로 — 서버가 400 을 주지 않게 편집기 출력을 다듬는다. */
function richOf(plain) {
  const t = String(plain || '').replace(/[\u0000-\u001f\u007f]/g, ' ').trim();
  return t === '' ? [] : [{ t: t, m: {} }];
}

export function fromDoc(doc, ctx) {
  const blocks = doc && Array.isArray(doc.blocks) ? doc.blocks : [];
  const content = [];
  // 4단계 — 하위 페이지 카드에 보여 줄 제목 지도(창구가 준 `child_titles`). 없어도 된다.
  const childTitles = ctx && ctx.child_titles && typeof ctx.child_titles === 'object' ? ctx.child_titles : {};
  // 7-D4 — 글 안 멘션의 라벨 지도(창구가 준 `mention_labels` — 볼 수 있는 것만). 편집기 안에서만 보이고 저장되지 않는다.
  const mentionLabels = ctx && ctx.mention_labels && typeof ctx.mention_labels === 'object' ? ctx.mention_labels : {};

  for (const b of blocks) {
    if (!b || typeof b.type !== 'string') {
      throw new Error('모르는 블록이 들어 있습니다');
    }
    if (Array.isArray(b.children) && b.children.length > 0 && b.type !== 'toggle' && b.type !== 'column_list') {
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
    if (b.type === 'child_page') {
      const pid = typeof b.page_id === 'string' ? b.page_id : null;
      const entry = pid && Object.prototype.hasOwnProperty.call(childTitles, pid) ? childTitles[pid] : null;
      content.push({
        type: 'child_page',
        attrs: {
          id: attrs.id,
          page_id: pid,
          // 지도에 없으면 읽기 렌더러와 같은 문구로 보인다 — 볼 수 없는 페이지라는 뜻이다.
          title: entry && typeof entry.title === 'string' ? entry.title : '볼 수 없는 페이지'
        }
      });
      continue;
    }
    /* ── 5-E 덩어리 다섯 ── 창은 평문만 다룬다. 꾸밈이 든 것은 편집기를 열지 않는다(말없이 지우지 않게 — M2). */
    if (b.type === 'column_list') {
      // 6-C — 단마다 평문 문단만 든 단 나누기만 편집기가 다룬다(다른 블록·꾸밈이 있으면 편집기를 열지 않는다 — 말없이 지우지 않게)
      const cols = [];
      for (const c of Array.isArray(b.children) ? b.children : []) {
        // 단 자체의 비율·색·정렬·크기도 창이 다루지 않는다 — 있으면 편집기를 열지 않는다(독립 검토 6-C M1)
        if (!c || c.type !== 'column' || !blockIsPlain(c) || c.ratio !== undefined) {
          throw new Error('비율·꾸밈이 든 단 나누기는 아직 편집기에서 고칠 수 없습니다');
        }
        const kids = [];
        for (const k of Array.isArray(c.children) ? c.children : []) {
          if (!k || k.type !== 'paragraph' || (Array.isArray(k.children) && k.children.length > 0) || !blockIsPlain(k) || !richIsPlain(k.text)
              || !plainRoundTrips(k.text)) {
            throw new Error('문단이 아닌 블록이나 꾸밈이 든 단 나누기는 아직 편집기에서 고칠 수 없습니다');
          }
          kids.push({ id: typeof k.id === 'string' ? k.id : null, text: plainOf(k.text) });
        }
        cols.push({ id: c && typeof c.id === 'string' ? c.id : null, kids: kids });
      }
      if (!blockIsPlain(b)) { throw new Error('꾸밈이 든 단 나누기는 아직 편집기에서 고칠 수 없습니다'); }
      content.push({ type: 'columns', attrs: { id: attrs.id, cols: cols } });
      continue;
    }
    if (['toggle', 'table', 'image', 'file', 'link_to_page', 'embed'].indexOf(b.type) !== -1 && !atomIsPlain(b)) {
      throw new Error('꾸밈(색·링크·열 폭 등)이 든 토글·표·설명은 아직 편집기에서 고칠 수 없습니다');
    }
    if (b.type === 'toggle') {
      const kids = [];
      for (const k of Array.isArray(b.children) ? b.children : []) {
        if (!k || k.type !== 'paragraph' || (Array.isArray(k.children) && k.children.length > 0) || !plainRoundTrips(k.text)) {
          throw new Error('문단이 아닌 블록(또는 여러 줄 문단)이 든 토글은 아직 편집할 수 없습니다');
        }
        kids.push({ id: typeof k.id === 'string' ? k.id : null, text: plainOf(k.text) });
      }
      content.push({ type: 'toggle', attrs: { id: attrs.id, summary: plainOf(b.text), kids: kids } });
      continue;
    }
    if (b.type === 'table') {
      const rows = [];
      const spans = {};
      (Array.isArray(b.rows) ? b.rows : []).forEach((r, ri) => {
        const cells = r && Array.isArray(r.cells) ? r.cells : [];
        cells.forEach((c, ci) => {
          const rs = c && Number.isInteger(c.rowspan) ? c.rowspan : 1;
          const cs = c && Number.isInteger(c.colspan) ? c.colspan : 1;
          if (rs > 1 || cs > 1) { spans[ri + ',' + ci] = [rs, cs]; }
        });
        rows.push(cells.map((c) => plainOf(c && c.text)));
      });
      content.push({ type: 'table', attrs: { id: attrs.id, header_row: b.header_row === true, header_col: b.header_col === true, rows: rows, spans: spans } });
      continue;
    }
    if (b.type === 'link_to_page') {
      const lmap = ctx && ctx.link_titles && typeof ctx.link_titles === 'object' ? ctx.link_titles : {};
      const pid = typeof b.page_id === 'string' ? b.page_id : null;
      const ent = pid && Object.prototype.hasOwnProperty.call(lmap, pid) ? lmap[pid] : null;
      content.push({ type: 'link_to_page', attrs: { id: attrs.id, page_id: pid, title: ent && typeof ent.title === 'string' ? ent.title : '볼 수 없는 페이지' } });
      continue;
    }
    if (b.type === 'button') { content.push({type:'button',attrs:{id:attrs.id,label:b.label,automation_id:b.automation_id}}); continue; }
    if (b.type === 'embed') {
      content.push({ type: 'embed', attrs: { id: attrs.id, url: typeof b.url === 'string' ? b.url : '', caption: plainOf(b.caption) } });
      continue;
    }
    if (b.type === 'image' || b.type === 'file') {
      const fmap = ctx && ctx.file_titles && typeof ctx.file_titles === 'object' ? ctx.file_titles : {};
      const ref = typeof b.file_ref === 'string' ? b.file_ref : null;
      const fid = ref && ref.slice(0, 5) === 'tbox:' ? ref.slice(5) : '';
      const ent = Object.prototype.hasOwnProperty.call(fmap, fid) ? fmap[fid] : null;
      const a2 = { id: attrs.id, file_ref: ref, caption: plainOf(b.caption), name: ent && typeof ent.name === 'string' ? ent.name : '볼 수 없는 파일' };
      if (b.type === 'image') { a2.width = ['full', 'wide', 'normal', 'small'].indexOf(b.width) !== -1 ? b.width : 'normal'; }
      content.push({ type: b.type, attrs: a2 });
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
      content.push({ type: b.type, attrs: attrs, content: richToPM(b.text, mentionLabels) });
      continue;
    }
    if (b.type === 'heading') {
      const level = b.level === 2 || b.level === 3 ? b.level : 1;
      content.push({ type: 'heading', attrs: Object.assign({ level: level }, attrs), content: richToPM(b.text, mentionLabels) });
      continue;
    }
    if (b.type === 'to_do') {
      content.push({
        type: 'to_do',
        attrs: Object.assign({ checked: b.checked === true }, attrs),
        content: richToPM(b.text, mentionLabels)
      });
      continue;
    }
    if (b.type === 'callout') {
      content.push({
        type: 'callout',
        attrs: Object.assign({ icon: typeof b.icon === 'string' && b.icon !== '' ? b.icon : '💡' }, attrs),
        content: richToPM(b.text, mentionLabels)
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
      } else if (mark.type === 'mention' && mark.attrs && mentionAllowed(mark.attrs.kind, mark.attrs.id)) {
        // 7-D4 — 저장하는 것은 `kind`·`id` 뿐(라벨·편집기 안 key 는 버린다)
        m.mention = { kind: mark.attrs.kind, id: mark.attrs.id };
      }
    }
    // 줄바꿈이 아닌 제어문자는 우리 형식이 받지 않는다(서버가 400 이다). 저장 전에 지운다 —
    // **편집기가 만든 자기 출력**을 정리하는 것이고, 사용자가 쓴 글자를 바꾸는 것이 아니다.
    const clean = node.text.replace(/[\u0000-\u0009\u000b-\u001f\u007f]/g, '');
    if (clean === '') { continue; }
    // 7-D4 — 멘션 마크가 「@」 한 글자가 아닌 조각(또는 링크와 함께)에 남았으면 마크를 떼고 글자만(서버는 400 — 편집기 자기 출력 정리)
    if (m.mention && (clean !== '@' || m.link)) { delete m.mention; }
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

    // 글자를 담지 않는 덩어리에는 꾸밈 세 가지를 얹지 않는다.
    const isAtom = ['button','divider', 'toc', 'child_page', 'toggle', 'table', 'link_to_page', 'image', 'file', 'columns', 'embed'].indexOf(ourType) !== -1;
    // 6-C — 편집기의 `columns` 덩어리 → 문서의 column_list > column > 문단들
    if (ourType === 'columns') {
      block.type = 'column_list';
      const cols = Array.isArray(a.cols) && a.cols.length >= 2 ? a.cols.slice(0, 3) : [{ kids: [] }, { kids: [] }];
      block.children = cols.map((c) => ({
        id: c && typeof c.id === 'string' && c.id !== '' ? c.id : uuid4(),
        type: 'column',
        children: (c && Array.isArray(c.kids) && c.kids.length > 0 ? c.kids : [{ id: null, text: '' }]).map((k) => ({
          id: k && typeof k.id === 'string' && k.id !== '' ? k.id : uuid4(), type: 'paragraph', text: richOf(k && k.text)
        }))
      }));
      blocks.push(block);
      continue;
    }

    if (!isAtom) {
      if (colorAllowed(a.color)) { block.color = a.color; }
      if (a.align === 'center' || a.align === 'right') { block.align = a.align; }
      if (a.size && a.size !== 'normal' && SIZES.indexOf(a.size) !== -1) { block.size = a.size; }
    }

    if (ourType === 'child_page') {
      // `title` 은 편집기 안에서만 쓰는 값이라 **저장하지 않는다**(제목의 진실은 그 페이지 행).
      if (typeof a.page_id !== 'string' || a.page_id === '') { continue; }
      block.page_id = a.page_id;
      blocks.push(block);
      continue;
    }
    /* 5-E 덩어리 — 보여 주기용 속성(`title`·`name`)은 버린다. 가리킬 곳이 없는 카드는 저장하지 않는다. */
    if (ourType === 'toggle') {
      block.text = richOf(a.summary);
      const kids = Array.isArray(a.kids) && a.kids.length > 0 ? a.kids : [{ id: null, text: '' }];
      block.children = kids.map((k) => ({
        id: k && typeof k.id === 'string' && k.id !== '' ? k.id : uuid4(), type: 'paragraph', text: richOf(k && k.text)
      }));
      blocks.push(block);
      continue;
    }
    if (ourType === 'table') {
      const rows = Array.isArray(a.rows) && a.rows.length > 0 ? a.rows : [['']];
      const width = Math.max(1, ...rows.map((r) => (Array.isArray(r) ? r.length : 0)));
      block.header_row = a.header_row === true;
      block.header_col = a.header_col === true;
      // 6-C 합친 칸 — 시작 칸에 rowspan·colspan, 덮이는 칸은 비운다(검증기가 같은 규칙으로 본다)
      const spans = a.spans && typeof a.spans === 'object' ? a.spans : {};
      const covered = new Set();
      for (const key of Object.keys(spans)) {
        const [r0, c0] = key.split(',').map(Number);
        const [rs, cs] = spans[key];
        for (let y = r0; y < r0 + rs; y++) { for (let x = c0; x < c0 + cs; x++) { if (y !== r0 || x !== c0) { covered.add(y + ',' + x); } } }
      }
      block.rows = rows.map((r, ri) => ({ cells: Array.from({ length: width }, (_, i) => {
        const cell = { text: covered.has(ri + ',' + i) ? [] : richOf(Array.isArray(r) ? r[i] : '') };
        const sp = spans[ri + ',' + i];
        if (sp && sp[0] > 1) { cell.rowspan = sp[0]; }
        if (sp && sp[1] > 1) { cell.colspan = sp[1]; }
        return cell;
      }) }));
      blocks.push(block);
      continue;
    }
    if (ourType === 'link_to_page') {
      if (typeof a.page_id !== 'string' || a.page_id === '') { continue; }
      block.page_id = a.page_id;
      blocks.push(block);
      continue;
    }
    if (ourType === 'button') { block.label=a.label;block.automation_id=a.automation_id;blocks.push(block);continue; }
    if (ourType === 'embed') {
      if (typeof a.url !== 'string' || a.url === '') { continue; }
      block.url = a.url;
      const ecap = richOf(a.caption);
      if (ecap.length > 0) { block.caption = ecap; }
      blocks.push(block);
      continue;
    }
    if (ourType === 'image' || ourType === 'file') {
      if (typeof a.file_ref !== 'string' || a.file_ref.slice(0, 5) !== 'tbox:') { continue; }
      block.file_ref = a.file_ref;
      const cap = richOf(a.caption);
      if (cap.length > 0) { block.caption = cap; }
      if (ourType === 'image') { block.width = ['full', 'wide', 'normal', 'small'].indexOf(a.width) !== -1 ? a.width : 'normal'; }
      blocks.push(block);
      continue;
    }
    if (isAtom) {
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

  /* 문서 전체에서 id 가 겹치면 뒤의 것을 새로 만든다(독립 검토 6-C L1 — 덩어리 카드를 복사하면 안쪽 문단·단 id 가
     그대로 따라와 서버가 duplicate_id 로 저장을 막는다. 최상위 id 는 uniqueBlockIds 가 이미 맞춘다). */
  const seen = new Set();
  const dedupe = (list) => {
    for (const b of list) {
      if (seen.has(b.id)) { b.id = uuid4(); }
      seen.add(b.id);
      if (Array.isArray(b.children)) { dedupe(b.children); }
    }
  };
  dedupe(blocks);

  return { schema: 1, blocks: blocks };
}

/**
 * 「블록 추가」 창이 쓰는 표 (사용자 결정 2026-09-23 — `/` 슬래시 메뉴는 없앴다).
 *
 * 한 줄이 한 종류다.
 *   `type`   ProseMirror 노드 이름 (우리 JSON 의 `code` 는 PM 에서 `code_block` 이다)
 *   `label`  창의 왼쪽 목록에 보이는 이름
 *   `desc`   오른쪽에 한 줄로 붙는 설명
 *   `sample` 오른쪽 미리보기를 **읽기 렌더러(`renderDoc`)로 그리는** 우리 문서 JSON.
 *            견본과 실제가 어긋나지 않게 같은 렌더러를 쓴다.
 *   `attrs`  넣을 때 블록에 얹을 속성(제목의 `level` 처럼)
 *
 * 3단계의 블록 10종(ARCH 21.3) + 제목 3단계를 따로 세어 12줄이다.
 *
 * 단축키 (2026-09-23 사용자 요청 — 「블록 추가」 창에서 보이고, 편집기에서 그대로 된다)
 *   `md`  줄 맨 앞에서 치는 글자(마크다운 단축, `buildInputRules` 가 실제로 바꾼다).
 *         본문만 `'Enter'` — 줄을 바꾸면 새 줄은 본문이다(목록·할 일은 같은 종류로 이어진다).
 *   `key` 지금 블록을 그 종류로 바꾸는 키(`prosemirror-keymap` 이름, Mod = Ctrl / ⌘).
 *         번호는 노션과 같다 — 0 본문 · 1~3 제목 · 4 할 일 · 5 글머리 · 6 번호 · 8 코드. 7 인용 · 9 강조 상자.
 */
export const BLOCK_TYPES = [
  {type:'button',label:'버튼',editsAtom:true,desc:'현재 페이지에 묶인 내 버튼 작업을 실행합니다.',sample:{schema:1,blocks:[{type:'button',label:'이번 달 시작',automation_id:'00000000-0000-4000-8000-000000000001'}]}},
  {
    type: 'paragraph', label: '본문',
    md: 'Enter', key: 'Mod-Alt-0',
    desc: '보통 글입니다. 가장 많이 쓰는 블록입니다.',
    sample: { schema: 1, blocks: [{ type: 'paragraph', text: [{ t: '보통 글을 쓰는 자리입니다.', m: {} }] }] }
  },
  {
    type: 'heading', attrs: { level: 1 }, label: '제목 1',
    md: '# ', key: 'Mod-Alt-1',
    desc: '가장 큰 제목입니다. 문서를 큰 단락으로 나눕니다.',
    sample: { schema: 1, blocks: [{ type: 'heading', level: 1, text: [{ t: '제목 1', m: {} }] }] }
  },
  {
    type: 'heading', attrs: { level: 2 }, label: '제목 2',
    md: '## ', key: 'Mod-Alt-2',
    desc: '중간 제목입니다. 제목 1 아래를 나눕니다.',
    sample: { schema: 1, blocks: [{ type: 'heading', level: 2, text: [{ t: '제목 2', m: {} }] }] }
  },
  {
    type: 'heading', attrs: { level: 3 }, label: '제목 3',
    md: '### ', key: 'Mod-Alt-3',
    desc: '작은 제목입니다. 제목 2 아래를 나눕니다.',
    sample: { schema: 1, blocks: [{ type: 'heading', level: 3, text: [{ t: '제목 3', m: {} }] }] }
  },
  {
    type: 'bulleted', label: '글머리 목록',
    md: '- ', key: 'Mod-Alt-5',
    desc: '점으로 늘어놓습니다. 줄 끝에서 Enter 를 누르면 다음 항목이 이어집니다.',
    sample: { schema: 1, blocks: [
      { type: 'bulleted', text: [{ t: '첫째 항목', m: {} }] },
      { type: 'bulleted', text: [{ t: '둘째 항목', m: {} }] }
    ] }
  },
  {
    type: 'numbered', label: '번호 목록',
    md: '1. ', key: 'Mod-Alt-6',
    desc: '번호로 늘어놓습니다. 줄 끝에서 Enter 를 누르면 다음 번호가 이어집니다.',
    sample: { schema: 1, blocks: [
      { type: 'numbered', text: [{ t: '첫째 항목', m: {} }] },
      { type: 'numbered', text: [{ t: '둘째 항목', m: {} }] }
    ] }
  },
  {
    type: 'to_do', attrs: { checked: false }, label: '할 일',
    md: '[] ', key: 'Mod-Alt-4',
    desc: '체크 상자가 앞에 붙습니다. 끝낸 일은 취소선으로 보입니다.',
    sample: { schema: 1, blocks: [
      { type: 'to_do', checked: false, text: [{ t: '아직 못 한 일', m: {} }] },
      { type: 'to_do', checked: true, text: [{ t: '끝낸 일', m: {} }] }
    ] }
  },
  {
    type: 'quote', label: '인용',
    md: '> ', key: 'Mod-Alt-7',
    desc: '왼쪽에 선이 붙습니다. 다른 글을 옮겨 적을 때 씁니다.',
    sample: { schema: 1, blocks: [{ type: 'quote', text: [{ t: '옮겨 적은 문장입니다.', m: {} }] }] }
  },
  {
    type: 'callout', attrs: { icon: '💡' }, label: '강조 상자',
    key: 'Mod-Alt-9',
    desc: '아이콘과 배경이 붙는 상자입니다. 눈에 띄게 알릴 때 씁니다.',
    sample: { schema: 1, blocks: [{ type: 'callout', icon: '💡', text: [{ t: '눈에 띄게 알릴 내용입니다.', m: {} }] }] }
  },
  {
    type: 'divider', label: '구분선',
    md: '---',
    desc: '가로줄 하나로 위아래를 나눕니다. 글자는 담지 않습니다.',
    sample: { schema: 1, blocks: [
      { type: 'paragraph', text: [{ t: '위쪽 글', m: {} }] },
      { type: 'divider' },
      { type: 'paragraph', text: [{ t: '아래쪽 글', m: {} }] }
    ] }
  },
  {
    type: 'code_block', label: '코드',
    md: '```', key: 'Mod-Alt-8',
    desc: '고정폭 글꼴로 그대로 보여 줍니다. 안에서 Enter 는 줄바꿈입니다.',
    sample: { schema: 1, blocks: [{ type: 'code', language: 'plain', code: 'echo "안녕"' }] }
  },
  {
    type: 'toc', label: '목차',
    desc: '이 페이지의 제목(제목 1·2·3)을 모아 차례를 만듭니다. 제목을 쓰거나 고치면 바로 따라 바뀌고, 누르면 그 제목으로 갑니다.',
    sample: { schema: 1, blocks: [
      { id: '00000000-0000-4000-8000-000000000001', type: 'heading', level: 1, text: [{ t: '제목 1', m: {} }] },
      { id: '00000000-0000-4000-8000-000000000002', type: 'heading', level: 2, text: [{ t: '제목 2', m: {} }] },
      { type: 'toc' }
    ] }
  },
  {
    /*
     * 하위 페이지 (4단계). 이 한 줄만 **넣기 전에 서버를 부른다** — 빈 페이지를 먼저 만들고
     * 그 id 로 블록을 넣는다(`makesPage`). 그래서 「넣기」를 누르면 새 페이지가 하나 생긴다.
     */
    type: 'child_page', label: '하위 페이지', makesPage: true,
    desc: '이 페이지 아래에 새 페이지를 만들고 카드로 걸어 둡니다. 카드를 누르면 그 페이지로 갑니다.',
    sample: { schema: 1, blocks: [
      { id: '00000000-0000-4000-8000-0000000000aa', type: 'child_page', page_id: '00000000-0000-4000-8000-0000000000bb' }
    ] },
    // 미리보기도 읽기 렌더러로 그린다 — 제목 지도를 여기서 흉내 낸다(견본과 실제가 같은 코드).
    sampleCtx: { base: '', child_titles: { '00000000-0000-4000-8000-0000000000bb': { title: '새 하위 페이지', icon: null } } }
  },
  /* ── 5-E — 넣으면 **창이 먼저 열린다**(`editsAtom`). 값을 정한 뒤에 블록이 들어가고, 두 번 누르면 다시 고친다. ── */
  {
    type: 'toggle', label: '토글', editsAtom: true,
    desc: '한 줄 제목을 누르면 아래 내용이 펼쳐집니다. 긴 설명·자주 묻는 것을 접어 둘 때 씁니다.',
    sample: { schema: 1, blocks: [{ id: '00000000-0000-4000-8000-0000000000c1', type: 'toggle', text: [{ t: '눌러서 펼치기', m: {} }],
      children: [{ id: '00000000-0000-4000-8000-0000000000c2', type: 'paragraph', text: [{ t: '접혀 있던 내용입니다.', m: {} }] }] }] }
  },
  {
    type: 'table', label: '표', editsAtom: true,
    desc: '행과 열로 늘어놓습니다. 첫 행(또는 첫 열)을 머리로 굵게 둘 수 있습니다. 칸 합치기는 아직 없습니다.',
    sample: { schema: 1, blocks: [{ id: '00000000-0000-4000-8000-0000000000c3', type: 'table', header_row: true, header_col: false, rows: [
      { cells: [{ text: [{ t: '항목', m: {} }] }, { text: [{ t: '담당', m: {} }] }] },
      { cells: [{ text: [{ t: '계획서', m: {} }] }, { text: [{ t: '김하나', m: {} }] }] }] }] }
  },
  {
    type: 'columns', label: '단 나누기', editsAtom: true,
    desc: '본문을 2단·3단으로 나란히 씁니다. 단마다 문단을 적습니다(꾸밈 없이). 좁은 화면에서는 위아래로 쌓입니다.',
    sample: { schema: 1, blocks: [{ id: '00000000-0000-4000-8000-0000000000d1', type: 'column_list', children: [
      { id: '00000000-0000-4000-8000-0000000000d2', type: 'column', children: [{ id: '00000000-0000-4000-8000-0000000000d3', type: 'paragraph', text: [{ t: '왼쪽 단', m: {} }] }] },
      { id: '00000000-0000-4000-8000-0000000000d4', type: 'column', children: [{ id: '00000000-0000-4000-8000-0000000000d5', type: 'paragraph', text: [{ t: '오른쪽 단', m: {} }] }] }] }] }
  },
  {
    type: 'link_to_page', label: '페이지 링크', editsAtom: true,
    desc: '다른 페이지를 카드로 걸어 둡니다. 걸린 페이지 아래 「이 페이지를 참조한 곳」에도 나옵니다(볼 수 있는 사람에게만).',
    sample: { schema: 1, blocks: [{ id: '00000000-0000-4000-8000-0000000000c4', type: 'link_to_page', page_id: '00000000-0000-4000-8000-0000000000c5' }] },
    sampleCtx: { base: '', link_titles: { '00000000-0000-4000-8000-0000000000c5': { title: '회의록', icon: null } } }
  },
  {
    type: 'image', label: '그림', editsAtom: true,
    desc: 't-box 에 올린 그림을 보여 줍니다. 페이지를 읽는 사람이 모두 볼 수 있는 등급의 파일만 붙습니다.',
    sample: { schema: 1, blocks: [{ id: '00000000-0000-4000-8000-0000000000c6', type: 'file', file_ref: 'tbox:00000000-0000-4000-8000-0000000000c7' }] },
    sampleCtx: { base: '', tbox_base: '', file_titles: { '00000000-0000-4000-8000-0000000000c7': { name: '그림.png', size: 184320, image: false } } }
  },
  {
    type: 'file', label: '파일', editsAtom: true,
    desc: 't-box 에 올린 파일을 카드로 겁니다. 누르면 t-box 가 자기 권한으로 내려줍니다.',
    sample: { schema: 1, blocks: [{ id: '00000000-0000-4000-8000-0000000000c8', type: 'file', file_ref: 'tbox:00000000-0000-4000-8000-0000000000c9' }] },
    sampleCtx: { base: '', tbox_base: '', file_titles: { '00000000-0000-4000-8000-0000000000c9': { name: '보고서.pdf', size: 1887436, image: false } } }
  },
  {
    type: 'embed', label: '임베드', editsAtom: true,
    desc: '관리자가 허용한 사이트(https)의 주소를 페이지 안에 끼워 보여 줍니다. 허용 목록 밖의 주소는 저장되지 않습니다.',
    sample: { schema: 1, blocks: [{ id: '00000000-0000-4000-8000-0000000000e1', type: 'embed', url: 'https://www.example.com/', caption: [{ t: '예시 임베드', m: {} }] }] },
    sampleCtx: { embeds: { hosts: ['www.example.com'], frames: false } }
  }
];
