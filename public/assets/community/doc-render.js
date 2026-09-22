/**
 * public/assets/community/doc-render.js — 블록 본문을 그리는 **클라이언트 렌더러**
 *
 * 요구사항: C:F-15 · C:S-11, ../../../../community/ARCHITECTURE.md 12.4, DESIGN.md 2.14, C:T-15 · C:T-19.
 *
 * 규칙 (서버 렌더러 src/community/DocRender.php 와 **같은 규칙·같은 순서**)
 *   · 요소는 `document.createElement`, 글자는 `textContent` 로만 만든다.
 *     문자열을 HTML 로 바꾸는 길(inner/outer HTML 속성·insertAdjacent 계열)이 한 줄도 없다.
 *   · 꾸밈은 허용 목록 → CSS 클래스로만. 인라인 `style` 을 쓰지 않는다.
 *   · 링크는 그리기 직전 **스킴을 다시 본다**. 통과하지 못하면 링크를 만들지 않고 글자만 남긴다.
 *   · 모르는 타입은 「지원하지 않는 블록」 한 줄 — 조용히 버리지 않는다.
 *
 * 두 렌더러의 출력(텍스트 노드 열·클래스 집합)이 같은지 tests/community/stage3.sh 가 본다.
 */

/** 마크를 감싸는 고정 순서(안쪽 → 바깥쪽). DocRender::MARK_ORDER 와 같아야 한다. */
const MARK_ORDER = ['code', 'color', 'strike', 'underline', 'italic', 'bold', 'link'];

const MARK_TAG = {
  code: 'code',
  strike: 's',
  underline: 'u',
  italic: 'em',
  bold: 'strong'
};

/** 기본 색 9 — 배경은 `_bg` 를 붙인 값이다 (doc-schema.json `colors`·`color_suffix_bg`). */
const COLORS = ['gray', 'brown', 'orange', 'yellow', 'green', 'blue', 'purple', 'pink', 'red'];

/** 색 이름 → 클래스. 허용 목록 밖이면 null (클래스를 붙이지 않는다). */
export function colorClass(value) {
  if (typeof value !== 'string') { return null; }
  if (value.slice(-3) === '_bg') {
    return COLORS.indexOf(value.slice(0, -3)) === -1 ? null : 'nt-' + value.slice(0, -3) + '-bg';
  }
  return COLORS.indexOf(value) === -1 ? null : 'nt-' + value;
}

/**
 * 링크 주소의 스킴 검사 — Doc::schemeOk 와 같은 규칙이다.
 * 정규식은 `test()` 로만 쓴다(`exec` 를 쓰지 않는다 — 보안 린트가 코드 실행 함수 이름으로 본다).
 */
export function schemeOk(href) {
  if (typeof href !== 'string' || href.length > 2048) { return false; }
  if (/[\u0000-\u001f\u007f]/.test(href)) { return false; }
  if (/^https:\/\/[^\s/?#]+\S*$/.test(href)) { return true; }
  if (/^http:\/\/[^\s/?#]+\S*$/.test(href)) { return true; }
  if (/^mailto:[^\s@]{1,128}@[^\s@]{1,128}$/.test(href)) { return true; }
  if (/^page:[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/.test(href)) { return true; }
  return false;
}

export function isExternal(href) {
  return href.indexOf('https://') === 0 || href.indexOf('http://') === 0 || href.indexOf('mailto:') === 0;
}

/** 리치 텍스트를 평문 한 줄로 (목차·발췌에 쓴다) */
export function plainText(text) {
  let out = '';
  if (!Array.isArray(text)) { return out; }
  for (const seg of text) {
    if (seg && typeof seg.t === 'string') { out += seg.t; }
  }
  return out;
}

/** 블록의 클래스 문자열 — 고정 이름 + 꾸밈 셋 */
function blockClass(base, block) {
  const out = [base];
  const c = colorClass(block.color);
  if (c) { out.push(c); }
  if (block.align === 'center' || block.align === 'right') { out.push('al-' + block.align); }
  if (['small', 'normal', 'large', 'xlarge'].indexOf(block.size) !== -1) { out.push('sz-' + block.size); }
  return out.join(' ');
}

/** 세그먼트 하나 → 노드 (마크를 고정 순서로 감싼다) */
function renderSegment(seg) {
  let node = document.createTextNode(typeof seg.t === 'string' ? seg.t : '');
  const marks = seg && typeof seg.m === 'object' && seg.m !== null ? seg.m : {};

  for (const name of MARK_ORDER) {
    if (!Object.prototype.hasOwnProperty.call(marks, name)) { continue; }
    const value = marks[name];

    if (name === 'color') {
      const klass = colorClass(value);
      if (!klass) { continue; }
      const span = document.createElement('span');
      span.className = klass;
      span.appendChild(node);
      node = span;
      continue;
    }

    if (name === 'link') {
      if (!value || typeof value.href !== 'string' || !schemeOk(value.href)) { continue; }
      const a = document.createElement('a');
      a.className = 'doc-link';
      a.setAttribute('href', value.href);
      if (isExternal(value.href)) {
        a.setAttribute('target', '_blank');
        a.setAttribute('rel', 'noopener noreferrer');
      }
      a.appendChild(node);
      node = a;
      continue;
    }

    if (value !== true) { continue; }
    const el = document.createElement(MARK_TAG[name]);
    if (name === 'code') { el.className = 'doc-inline-code'; }
    el.appendChild(node);
    node = el;
  }

  return node;
}

/** 리치 텍스트를 부모에 붙인다 */
function appendRich(parent, text) {
  if (!Array.isArray(text)) { return; }
  for (const seg of text) {
    if (!seg || typeof seg.t !== 'string') { continue; }
    parent.appendChild(renderSegment(seg));
  }
}

function unsupported() {
  const div = document.createElement('div');
  div.className = 'block-unsupported';
  div.textContent = '지원하지 않는 블록';
  return div;
}

/** 문서 안의 제목 블록을 모은다 (목차용) */
function headings(list, out) {
  if (!Array.isArray(list)) { return out; }
  for (const block of list) {
    if (!block || typeof block !== 'object') { continue; }
    if (block.type === 'heading' && typeof block.id === 'string') {
      out.push({
        id: block.id,
        level: typeof block.level === 'number' ? block.level : 1,
        text: plainText(block.text)
      });
    }
    if (Array.isArray(block.children)) { headings(block.children, out); }
  }
  return out;
}

function renderToc(rootBlocks, id) {
  const nav = document.createElement('nav');
  nav.className = 'doc-toc';
  if (id) { nav.id = 'block-' + id; }
  const items = headings(rootBlocks, []);
  if (items.length === 0) {
    const p = document.createElement('p');
    p.className = 'muted';
    p.textContent = '제목이 아직 없습니다';
    nav.appendChild(p);
    return nav;
  }
  const ul = document.createElement('ul');
  for (const h of items) {
    const li = document.createElement('li');
    li.className = 'doc-toc__item doc-toc__item--' + String(h.level);
    const a = document.createElement('a');
    a.setAttribute('href', '#block-' + h.id);
    a.textContent = h.text;
    li.appendChild(a);
    ul.appendChild(li);
  }
  nav.appendChild(ul);
  return nav;
}

/** 블록 하나 → 노드 배열 (하위 블록이 있으면 형제로 뒤에 붙는다 — 서버 렌더러와 같은 모양) */
function renderBlock(block, rootBlocks) {
  const nodes = [];
  const type = block.type;
  const id = typeof block.id === 'string' ? block.id : '';
  const kids = Array.isArray(block.children) ? renderBlocks(block.children, rootBlocks) : null;
  const setId = (el) => { if (id) { el.id = 'block-' + id; } };

  if (type === 'paragraph') {
    const p = document.createElement('p');
    p.className = blockClass('doc-p', block);
    setId(p);
    appendRich(p, block.text);
    nodes.push(p);
  } else if (type === 'heading') {
    const level = block.level === 2 || block.level === 3 ? block.level : 1;
    const h = document.createElement('h' + String(level));
    h.className = blockClass('doc-h' + String(level), block);
    setId(h);
    appendRich(h, block.text);
    nodes.push(h);
  } else if (type === 'bulleted' || type === 'numbered') {
    const li = document.createElement('li');
    li.className = blockClass('doc-li', block);
    setId(li);
    appendRich(li, block.text);
    if (kids) { li.appendChild(kids); }
    nodes.push(li);
    return nodes;   // 하위는 <li> 안에 들어간다 (서버 렌더러와 같다)
  } else if (type === 'to_do') {
    const done = block.checked === true;
    const div = document.createElement('div');
    div.className = blockClass('doc-todo' + (done ? ' is-done' : ''), block);
    setId(div);
    const box = document.createElement('span');
    box.className = 'doc-todo__box';
    box.setAttribute('aria-hidden', 'true');
    box.textContent = done ? '✔' : '';
    const span = document.createElement('span');
    span.className = 'doc-todo__text';
    appendRich(span, block.text);
    div.appendChild(box);
    div.appendChild(span);
    nodes.push(div);
  } else if (type === 'quote') {
    const q = document.createElement('blockquote');
    q.className = blockClass('doc-quote', block);
    setId(q);
    appendRich(q, block.text);
    nodes.push(q);
  } else if (type === 'callout') {
    const div = document.createElement('div');
    div.className = blockClass('doc-callout', block);
    setId(div);
    const icon = document.createElement('span');
    icon.className = 'doc-callout__icon';
    icon.setAttribute('aria-hidden', 'true');
    icon.textContent = typeof block.icon === 'string' ? block.icon : '';
    const span = document.createElement('span');
    span.className = 'doc-callout__text';
    appendRich(span, block.text);
    div.appendChild(icon);
    div.appendChild(span);
    nodes.push(div);
  } else if (type === 'divider') {
    const hr = document.createElement('hr');
    hr.className = 'doc-divider';
    setId(hr);
    nodes.push(hr);
    return nodes;
  } else if (type === 'code') {
    const div = document.createElement('div');
    div.className = 'doc-code';
    setId(div);
    const lang = document.createElement('span');
    lang.className = 'doc-code__lang';
    lang.textContent = typeof block.language === 'string' ? block.language : 'plain';
    const pre = document.createElement('pre');
    const code = document.createElement('code');
    code.textContent = typeof block.code === 'string' ? block.code : '';
    pre.appendChild(code);
    div.appendChild(lang);
    div.appendChild(pre);
    if (Array.isArray(block.caption) && block.caption.length > 0) {
      const cap = document.createElement('p');
      cap.className = 'doc-code__caption';
      appendRich(cap, block.caption);
      div.appendChild(cap);
    }
    nodes.push(div);
    return nodes;
  } else if (type === 'toc') {
    nodes.push(renderToc(rootBlocks, id));
    return nodes;
  } else {
    nodes.push(unsupported());
    return nodes;
  }

  if (kids) { nodes.push(kids); }
  return nodes;
}

/** 블록 배열 → DocumentFragment. 이어진 목록은 한 ul·ol 로 묶는다. */
function renderBlocks(list, rootBlocks) {
  const frag = document.createDocumentFragment();
  let openTag = '';
  let listEl = null;

  const closeList = () => { openTag = ''; listEl = null; };

  for (const block of Array.isArray(list) ? list : []) {
    if (!block || typeof block !== 'object' || typeof block.type !== 'string') {
      closeList();
      frag.appendChild(unsupported());
      continue;
    }
    const want = block.type === 'bulleted' ? 'ul' : (block.type === 'numbered' ? 'ol' : '');
    if (want !== openTag) {
      closeList();
      openTag = want;
      if (want !== '') {
        listEl = document.createElement(want);
        listEl.className = want === 'ul' ? 'doc-ul' : 'doc-ol';
        frag.appendChild(listEl);
      }
    }
    const target = listEl || frag;
    for (const node of renderBlock(block, rootBlocks)) {
      target.appendChild(node);
    }
  }

  return frag;
}

/**
 * 문서 하나를 그린다.
 *
 * @param {object} doc  `{ schema: 1, blocks: [...] }`
 * @returns {DocumentFragment} `<div class="doc">…</div>` 하나를 담은 조각
 */
export function renderDoc(doc) {
  const frag = document.createDocumentFragment();
  const root = document.createElement('div');
  root.className = 'doc';
  const blocks = doc && Array.isArray(doc.blocks) ? doc.blocks : [];
  root.appendChild(renderBlocks(blocks, blocks));
  frag.appendChild(root);
  return frag;
}
