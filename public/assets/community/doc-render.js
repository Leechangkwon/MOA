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
const MARK_ORDER = ['code', 'color', 'strike', 'underline', 'italic', 'bold', 'link', 'mention'];

const MARK_TAG = {
  code: 'code',
  strike: 's',
  underline: 'u',
  italic: 'em',
  bold: 'strong'
};

/**
 * `child_page` 가 가리키는 페이지를 볼 수 없을 때의 문구 (4단계).
 * **서버 렌더러 `DocRender::CHILD_PAGE_UNREADABLE` 과 한 글자도 다르면 안 된다** — C:T-19.
 */
const CHILD_PAGE_UNREADABLE = '볼 수 없는 페이지';
/** 5-E — 볼 수 없는(또는 없는) 파일 자리. 서버 렌더러 `DocRender::FILE_UNREADABLE` 과 같은 문구(C:T-19) */
const FILE_UNREADABLE = '볼 수 없는 파일';
/** 7-C — 임베드 카드 문구. 서버 렌더러 `DocRender::EMBED_*` 와 같은 문구(C:T-19) */
const EMBED_WAITING = '허용 대기 · 새 탭에서 열기';
const EMBED_BLOCKED = '허용 목록 밖의 임베드';
/**
 * 7-D4 — 글 안 멘션의 대상을 볼 수 없을 때(없는 대상·못 보는 대상·모양 밖 id 모두 같은 글자) 「@」 뒤에 붙는 문구.
 * **서버 렌더러 `DocRender::MENTION_UNREADABLE` 과 한 글자도 다르면 안 된다** — C:T-19 · C:T-225.
 */
const MENTION_UNREADABLE = '볼 수 없음';
/** 틀 sandbox 토큰 — 서버 `COMMUNITY_EMBED_SANDBOX`(src/bootstrap.php)와 **같은 글자**여야 한다(사용자 결정 2026-09-24 · stage7c 가 견준다) */
const EMBED_SANDBOX = 'allow-scripts allow-same-origin allow-presentation allow-popups';
/** 7-C — `Doc::embedHost` 와 같은 규칙(https · 소문자 ASCII 라벨 · 점 하나 이상 · IP·포트·사용자 정보 없음) */
// 경로는 ASCII 인쇄 가능 글자만 — 서버(`Doc::embedHost`)와 같은 규칙(유니코드 공백에서 갈리지 않게, 독립 검토 7-C L-2)
const EMBED_URL_RE = /^https:\/\/([^/?#\u0000-\u0020\u007f-\uffff]+)(?:[/?#][^\u0000-\u0020\u007f-\uffff"'<>\\^`{|}]*)?$/;
const EMBED_HOST_RE = /^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]([a-z0-9-]{0,61}[a-z0-9])?$/;
export function embedHost(url) {
  const m = typeof url === 'string' ? url.match(EMBED_URL_RE) : null;
  if (!m || m[1].length < 4 || m[1].length > 253 || !EMBED_HOST_RE.test(m[1])) { return null; }
  return m[1];
}

/** 파일 크기 글자 — 서버 렌더러 `sizeText` 와 **같은 정수 계산**(C:T-19) */
function sizeText(bytes) {
  const b = Math.max(0, Math.floor(Number(bytes) || 0));
  if (b < 1024) { return String(b) + ' B'; }
  if (b < 1048576) { const t = Math.floor((b * 10 + 512) / 1024); return String(Math.floor(t / 10)) + '.' + String(t % 10) + ' KB'; }
  const t = Math.floor((b * 10 + 524288) / 1048576);
  return String(Math.floor(t / 10)) + '.' + String(t % 10) + ' MB';
}

/** 5-E — 주소에 넣는 페이지·파일 id 의 모양 (DocRender.php 의 UUID_RE 와 같다) */
const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/;

/**
 * 7-D4 글 안 멘션 `{kind, id}` 의 모양 — `Doc::mentionShapeOk` 와 같은 규칙(키 정확히 둘 · 사람 = 정수 1~2⁵³ · 페이지 = 소문자 UUID ·
 * 날짜 = YYYY-MM-DD 1900~2100 있는 날). 정규식은 `test()`·`match()` 로만 쓴다(보안 린트).
 */
const MENTION_USER_ID_MAX = 9007199254740992;
const MENTION_DATE_RE = /^(\d{4})-(\d{2})-(\d{2})$/;
function mentionDateOk(value) {
  if (typeof value !== 'string') { return false; }
  const m = value.match(MENTION_DATE_RE);
  if (!m) { return false; }
  const y = Number(m[1]);
  const mo = Number(m[2]);
  const d = Number(m[3]);
  if (y < 1900 || y > 2100 || mo < 1 || mo > 12 || d < 1) { return false; }
  const leap = (y % 4 === 0 && y % 100 !== 0) || y % 400 === 0;
  const days = [31, leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
  return d <= days[mo - 1];
}
export function mentionShapeOk(value) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) { return false; }
  const keys = Object.keys(value);
  if (keys.length !== 2 || keys.indexOf('kind') === -1 || keys.indexOf('id') === -1) { return false; }
  if (value.kind === 'user') { return Number.isInteger(value.id) && value.id >= 1 && value.id <= MENTION_USER_ID_MAX; }
  if (value.kind === 'page') { return typeof value.id === 'string' && UUID_RE.test(value.id); }
  if (value.kind === 'date') { return mentionDateOk(value.id); }
  return false;
}

/**
 * 7-D4 — 멘션 한 조각의 글자·감쌀 요소. `DocRender::mentionParts` 와 같은 규칙(지시서 M-6):
 * 사람·페이지는 `ctx.mention_labels`(창구가 준 **볼 수 있는 것만**의 지도)에 있을 때만 「@라벨」 — 없으면 「@볼 수 없음」(글자만 · 링크 0) ·
 * 페이지는 `a.doc-mention[href=<base>/p/<uuid>]`(사이트 안 — `target` 없음) · 날짜는 판정 없이 id 그대로 · 모양 밖 id 는 「@볼 수 없음」.
 */
function mentionParts(value, ctx) {
  const unknown = { text: '@' + MENTION_UNREADABLE, tag: 'span', cls: 'doc-mention doc-mention--unknown', href: null };
  if (!mentionShapeOk(value)) { return unknown; }
  if (value.kind === 'date') { return { text: '@' + value.id, tag: 'span', cls: 'doc-mention', href: null }; }
  const map = ctx && ctx.mention_labels && typeof ctx.mention_labels === 'object' ? ctx.mention_labels : {};
  const key = (value.kind === 'user' ? 'user:' : 'page:') + String(value.id);
  const entry = Object.prototype.hasOwnProperty.call(map, key) ? map[key] : null;
  if (!entry || typeof entry !== 'object' || typeof entry.label !== 'string') { return unknown; }
  if (value.kind === 'page') {
    return { text: '@' + entry.label, tag: 'a', cls: 'doc-mention', href: (ctx && typeof ctx.base === 'string' ? ctx.base : '') + '/p/' + value.id };
  }
  return { text: '@' + entry.label, tag: 'span', cls: 'doc-mention', href: null };
}

/** 5-E 페이지 링크 카드 — 하위 페이지 카드와 같은 모양, 지도는 `ctx.link_titles`(볼 수 있는 것만) */
function renderLinkPage(block, id, ctx) {
  const map = ctx && ctx.link_titles && typeof ctx.link_titles === 'object' ? ctx.link_titles : {};
  const pageId = typeof block.page_id === 'string' && UUID_RE.test(block.page_id) ? block.page_id : '';
  const entry = Object.prototype.hasOwnProperty.call(map, pageId) ? map[pageId] : null;
  const span = document.createElement('span');
  span.className = 'card__title';
  if (!entry || typeof entry !== 'object') {
    const div = document.createElement('div');
    div.className = 'card card--soon doc-link-page';
    if (id) { div.id = 'block-' + id; }
    span.textContent = CHILD_PAGE_UNREADABLE;
    div.appendChild(span);
    return div;
  }
  const a = document.createElement('a');
  a.className = 'card card--link doc-link-page';
  a.setAttribute('href', (ctx && typeof ctx.base === 'string' ? ctx.base : '') + '/p/' + pageId);
  if (id) { a.id = 'block-' + id; }
  span.textContent = (typeof entry.icon === 'string' && entry.icon !== '' ? entry.icon + ' ' : '') + (typeof entry.title === 'string' ? entry.title : '');
  a.appendChild(span);
  return a;
}

/**
 * 7-C 임베드 (K-4) — `ctx.embeds` = {hosts, frames}. 서버 렌더러와 같은 세 갈래:
 * 목록 안 + 틀 열림 → `<iframe sandbox="EMBED_SANDBOX">`(서버 `COMMUNITY_EMBED_SANDBOX` 와 같은 글자 — 사용자 결정 2026-09-24)
 *   · 목록 안 → 「허용 대기」 카드 + 새 탭 링크 · 목록 밖 → 링크 없는 카드.
 */
function renderEmbed(block, id, ctx) {
  const url = typeof block.url === 'string' ? block.url : '';
  const host = embedHost(url);
  const emb = ctx && ctx.embeds && typeof ctx.embeds === 'object' ? ctx.embeds : {};
  const hosts = Array.isArray(emb.hosts) ? emb.hosts : [];
  const listed = host !== null && hosts.indexOf(host) !== -1;
  const hasCap = Array.isArray(block.caption) && block.caption.length > 0;
  if (listed && emb.frames === true) {
    const fig = document.createElement('figure');
    fig.className = 'doc-embed';
    if (id) { fig.id = 'block-' + id; }
    const frame = document.createElement('iframe');
    frame.className = 'doc-embed__frame';
    frame.setAttribute('sandbox', EMBED_SANDBOX);
    frame.setAttribute('allow', 'fullscreen; picture-in-picture; encrypted-media');
    frame.setAttribute('referrerpolicy', 'strict-origin');
    frame.setAttribute('loading', 'lazy');
    frame.setAttribute('title', '임베드 — ' + host);
    frame.setAttribute('src', url);
    fig.appendChild(frame);
    if (hasCap) {
      const cap = document.createElement('figcaption');
      cap.className = 'doc-embed__caption';
      appendRich(cap, block.caption, ctx);
      fig.appendChild(cap);
    }
    return fig;
  }
  const wrap = document.createElement('div');
  wrap.className = 'doc-embed';
  if (id) { wrap.id = 'block-' + id; }
  if (listed) {
    const a = document.createElement('a');
    a.className = 'card card--link';
    a.setAttribute('href', url);
    a.setAttribute('target', '_blank');
    a.setAttribute('rel', 'noopener noreferrer');
    const t = document.createElement('span');
    t.className = 'card__title';
    t.textContent = '임베드 — ' + host;
    const meta = document.createElement('span');
    meta.className = 'card__meta';
    meta.textContent = EMBED_WAITING;
    a.appendChild(t);
    a.appendChild(meta);
    wrap.appendChild(a);
    if (hasCap) {
      const p = document.createElement('p');
      p.className = 'doc-embed__caption';
      appendRich(p, block.caption, ctx);
      wrap.appendChild(p);
    }
    return wrap;
  }
  const card = document.createElement('div');
  card.className = 'card card--soon';
  const t = document.createElement('span');
  t.className = 'card__title';
  t.textContent = EMBED_BLOCKED;
  card.appendChild(t);
  if (host !== null) {
    const meta = document.createElement('span');
    meta.className = 'card__meta';
    meta.textContent = host;
    card.appendChild(meta);
  }
  wrap.appendChild(card);
  return wrap;
}

/**
 * Q:F-12 U3 C(커뮤니티 ARCH 24.5 · DESIGN 2.24) — `att:<uuid>` 이 문서 전용 첨부. 지도(`ctx.attachment_titles` — 창구가 이 문서의 첨부를
 * 받을 수 있는 사람에게만 준 것)와 문서 id(`ctx.attachment_page`)가 있을 때만 그린다(없으면 「볼 수 없는 파일」 — 서버 렌더러와 같은 문구).
 * 주소는 첨부 창구(`ctx.base` + `/api/pages/{문서}/attachments/{첨부}/raw|download`) — 가져가는 순간에도 창구가 문서 판정으로 다시 판단한다.
 * 그림은 검사가 끝난(clean) 그림만 `<img>`. 검사 대기(pending)면 그림은 「검사 중이에요」 회색 상자(`.doc-upload` 자리표시 모양),
 * 파일은 이름 + 「검사 중」 배지(받기 링크 0 — 창구 423 을 오류로 띄우지 않는다). 그 밖은 서버 렌더러와 같은 받기 카드.
 * 카페 C4(DESIGN 2.26 「검사 중 미리보기」) — 사본 지도 인자 하나 `ctx.attachment_previews`(첨부 id → 올린 사람 브라우저 안 사본 `data:image/…` —
 * **편집기 그림 부품만** 넘긴다 · 읽기 화면 · 다른 사람에게는 없다). 검사 중 그림에 사본이 있으면 상자 대신 **그림 자체**(같은 figure · 같은 폭 클래스)
 * + 왼쪽 위 `.badge` 「검사 중 · 나만 보여요」. 서버 사본은 검사 전에 주소로 쓰지 않는다. 감염(`infected`)은 받기 카드 대신 「올릴 수 없는 파일이에요」 카드.
 */
const ATT_PREVIEW_PREFIX = /^data:image\/(png|jpeg|gif);base64,/;
const ATT_PENDING_MINE = '검사 중 · 나만 보여요';
const ATT_INFECTED = '올릴 수 없는 파일이에요';
function renderAttachmentBlock(block, id, ctx) {
  const map = ctx && ctx.attachment_titles && typeof ctx.attachment_titles === 'object' ? ctx.attachment_titles : {};
  const page = ctx && typeof ctx.attachment_page === 'string' && UUID_RE.test(ctx.attachment_page) ? ctx.attachment_page : '';
  const base = ctx && typeof ctx.base === 'string' ? ctx.base : '';
  const ref = typeof block.file_ref === 'string' && UUID_RE.test(block.file_ref.slice(4)) ? block.file_ref.slice(4) : '';
  const entry = ref !== '' && Object.prototype.hasOwnProperty.call(map, ref) && map[ref] && typeof map[ref] === 'object' ? map[ref] : null;
  const hasCap = Array.isArray(block.caption) && block.caption.length > 0;
  const wrap = document.createElement('div');
  wrap.className = 'doc-file';
  if (id) { wrap.id = 'block-' + id; }
  if (!entry || page === '') {
    const card = document.createElement('div');
    card.className = 'card card--soon';
    const t = document.createElement('span');
    t.className = 'card__title';
    t.textContent = FILE_UNREADABLE;
    card.appendChild(t);
    wrap.appendChild(card);
    return wrap;
  }
  const name = typeof entry.name === 'string' ? entry.name : '';
  const href = base + '/api/pages/' + page + '/attachments/' + ref;
  const addCaption = (host, tag, cls) => {
    if (!hasCap) { return; }
    const cap = document.createElement(tag);
    cap.className = cls;
    appendRich(cap, block.caption, ctx);
    host.appendChild(cap);
  };
  if (entry.scan_status === 'pending') {
    const previews = ctx && ctx.attachment_previews && typeof ctx.attachment_previews === 'object' ? ctx.attachment_previews : null;
    const preview = previews && Object.prototype.hasOwnProperty.call(previews, ref) && typeof previews[ref] === 'string' ? previews[ref] : '';
    if (block.type === 'image' && entry.image === true && ATT_PREVIEW_PREFIX.test(preview)) {
      const fig = document.createElement('figure');
      fig.className = 'doc-image ' + imageWidthClass(block);
      fig.setAttribute('data-preview', '');
      if (id) { fig.id = 'block-' + id; }
      const img = document.createElement('img');
      img.className = 'doc-image__img';
      img.setAttribute('src', preview);
      img.setAttribute('alt', name);
      const mark = document.createElement('span');
      mark.className = 'badge';
      mark.textContent = ATT_PENDING_MINE;
      fig.appendChild(img);
      fig.appendChild(mark);
      addCaption(fig, 'figcaption', 'doc-image__caption');
      return fig;
    }
    if (block.type === 'image') {
      const box = document.createElement('div');
      box.className = 'doc-upload';
      box.setAttribute('data-kind', 'image');
      const n = document.createElement('span');
      n.className = 'doc-upload__name';
      n.textContent = name;
      const p = document.createElement('span');
      p.className = 'doc-upload__progress';
      p.textContent = '검사 중이에요';
      box.appendChild(n);
      box.appendChild(p);
      wrap.appendChild(box);
    } else {
      const card = document.createElement('div');
      card.className = 'card card--soon';
      const t = document.createElement('span');
      t.className = 'card__title';
      t.textContent = name;
      const b = document.createElement('span');
      b.className = 'badge';
      b.textContent = '검사 중';
      card.appendChild(t);
      card.appendChild(b);
      wrap.appendChild(card);
    }
    addCaption(wrap, 'p', 'doc-file__caption');
    return wrap;
  }
  if (entry.scan_status === 'infected') {
    const card = document.createElement('div');
    card.className = 'card card--soon';
    const t = document.createElement('span');
    t.className = 'card__title';
    t.textContent = ATT_INFECTED;
    const m = document.createElement('span');
    m.className = 'card__meta';
    m.textContent = name;
    card.appendChild(t);
    card.appendChild(m);
    wrap.appendChild(card);
    return wrap;
  }
  if (block.type === 'image' && entry.image === true && entry.scan_status === 'clean') {
    const fig = document.createElement('figure');
    fig.className = 'doc-image ' + imageWidthClass(block);
    if (id) { fig.id = 'block-' + id; }
    const img = document.createElement('img');
    img.className = 'doc-image__img';
    img.setAttribute('src', href + '/raw');
    img.setAttribute('alt', name);
    img.setAttribute('loading', 'lazy');
    fig.appendChild(img);
    addCaption(fig, 'figcaption', 'doc-image__caption');
    return fig;
  }
  const a = document.createElement('a');
  a.className = 'card card--link';
  a.setAttribute('href', href + '/download');
  const t = document.createElement('span');
  t.className = 'card__title';
  t.textContent = name;
  const m = document.createElement('span');
  m.className = 'card__meta';
  m.textContent = sizeText(entry.size);
  a.appendChild(t);
  a.appendChild(m);
  wrap.appendChild(a);
  addCaption(wrap, 'p', 'doc-file__caption');
  return wrap;
}

/**
 * Q:F-12 U4-3(WK-28 · DESIGN 2.26 「폭 클래스」) — 그림 폭 클래스 하나. 서버 렌더러 `DocRender::imageWidthClass` 와 같은 규칙:
 * `width_pct` 가 정수 10~100 · 5의 배수면 `doc-image--p{N}`(19값 — 검증기 `pct5` 와 같은 조건), 아니면 옛 `width` 넷(기본 normal).
 * 값을 그대로 클래스에 붙이지 않는다 — 돌려주는 글자는 이 고정 규칙에서만 나온다(인라인 style 0).
 */
export function imageWidthPct(value) {
  return Number.isInteger(value) && value >= 10 && value <= 100 && value % 5 === 0 ? value : null;
}
export function imageWidthClass(block) {
  const pct = imageWidthPct(block ? block.width_pct : null);
  if (pct !== null) { return 'doc-image--p' + String(pct); }
  return 'doc-image--' + (block && ['full', 'wide', 'normal', 'small'].indexOf(block.width) !== -1 ? block.width : 'normal');
}

/**
 * 5-E 그림·파일 — 지도(`ctx.file_titles`, 보는 사람이 t-box 에서 볼 수 있는 것만)에 있을 때만. 주소는 t-box 창구(`ctx.tbox_base`).
 * Q:F-12 U4-3 — 편집기의 그림 표시 부품(editor.js `imageViews`)도 **이 함수 한 벌**로 그린다(읽기 화면과 같은 figure · 같은 주소 · 같은 검사 중 상자).
 */
export function renderFileBlock(block, id, ctx) {
  if (typeof block.file_ref === 'string' && block.file_ref.slice(0, 4) === 'att:') { return renderAttachmentBlock(block, id, ctx); }
  const map = ctx && ctx.file_titles && typeof ctx.file_titles === 'object' ? ctx.file_titles : {};
  const tbox = ctx && typeof ctx.tbox_base === 'string' ? ctx.tbox_base : '';
  const ref = typeof block.file_ref === 'string' && block.file_ref.slice(0, 5) === 'tbox:' && UUID_RE.test(block.file_ref.slice(5))
    ? block.file_ref.slice(5) : '';
  const entry = Object.prototype.hasOwnProperty.call(map, ref) ? map[ref] : null;
  const hasCap = Array.isArray(block.caption) && block.caption.length > 0;
  if (!entry || typeof entry !== 'object') {
    const wrap = document.createElement('div');
    wrap.className = 'doc-file';
    if (id) { wrap.id = 'block-' + id; }
    const card = document.createElement('div');
    card.className = 'card card--soon';
    const t = document.createElement('span');
    t.className = 'card__title';
    t.textContent = FILE_UNREADABLE;
    card.appendChild(t);
    wrap.appendChild(card);
    return wrap;
  }
  const name = typeof entry.name === 'string' ? entry.name : '';
  if (block.type === 'image' && entry.image === true) {
    const fig = document.createElement('figure');
    fig.className = 'doc-image ' + imageWidthClass(block);
    if (id) { fig.id = 'block-' + id; }
    const img = document.createElement('img');
    img.className = 'doc-image__img';
    img.setAttribute('src', tbox + '/api/files/' + ref + '/preview/raw');
    img.setAttribute('alt', name);
    img.setAttribute('loading', 'lazy');
    fig.appendChild(img);
    if (hasCap) {
      const cap = document.createElement('figcaption');
      cap.className = 'doc-image__caption';
      appendRich(cap, block.caption, ctx);
      fig.appendChild(cap);
    }
    return fig;
  }
  const wrap = document.createElement('div');
  wrap.className = 'doc-file';
  if (id) { wrap.id = 'block-' + id; }
  const a = document.createElement('a');
  a.className = 'card card--link';
  a.setAttribute('href', tbox + '/api/files/' + ref + '/download');
  const t = document.createElement('span');
  t.className = 'card__title';
  t.textContent = name;
  const m = document.createElement('span');
  m.className = 'card__meta';
  m.textContent = sizeText(entry.size);
  a.appendChild(t);
  a.appendChild(m);
  wrap.appendChild(a);
  if (hasCap) {
    const p = document.createElement('p');
    p.className = 'doc-file__caption';
    appendRich(p, block.caption, ctx);
    wrap.appendChild(p);
  }
  return wrap;
}

/** 5-E 표 — 머리 행·머리 열은 `<th>`. 6-C 칸 병합은 rowspan·colspan, 덮인 칸은 그리지 않는다. 서버 렌더러와 같은 모양 */
/** 6-C — 합친 칸이 덮는 자리 집합('행,열'). DocRender::coveredCells 와 같은 규칙 */
function coveredCells(rows) {
  const covered = new Set();
  rows.forEach((row, ri) => {
    const cells = row && Array.isArray(row.cells) ? row.cells : [];
    cells.forEach((cell, ci) => {
      if (covered.has(ri + ',' + ci) || !cell || typeof cell !== 'object') { return; }
      const rs = Number.isInteger(cell.rowspan) ? Math.max(1, Math.min(20, cell.rowspan)) : 1;
      const cs = Number.isInteger(cell.colspan) ? Math.max(1, Math.min(20, cell.colspan)) : 1;
      for (let y = ri; y < ri + rs; y++) {
        for (let x = ci; x < ci + cs; x++) {
          if (y !== ri || x !== ci) { covered.add(y + ',' + x); }
        }
      }
    });
  });
  return covered;
}

function renderTable(block, id, ctx) {
  const wrap = document.createElement('div');
  wrap.className = 'doc-table-wrap';
  if (id) { wrap.id = 'block-' + id; }
  const table = document.createElement('table');
  table.className = 'doc-table';
  const tbody = document.createElement('tbody');
  const rows = Array.isArray(block.rows) ? block.rows : [];
  const covered = coveredCells(rows);
  rows.forEach((row, ri) => {
    const tr = document.createElement('tr');
    const rc = row && typeof row === 'object' ? colorClass(row.color) : null;
    if (rc) { tr.className = rc; }
    const cells = row && Array.isArray(row.cells) ? row.cells : [];
    cells.forEach((cell, ci) => {
      if (covered.has(ri + ',' + ci)) { return; }
      const head = (block.header_row === true && ri === 0) || (block.header_col === true && ci === 0);
      const el = document.createElement(head ? 'th' : 'td');
      if (cell && Number.isInteger(cell.rowspan) && cell.rowspan > 1) { el.setAttribute('rowspan', String(cell.rowspan)); }
      if (cell && Number.isInteger(cell.colspan) && cell.colspan > 1) { el.setAttribute('colspan', String(cell.colspan)); }
      const cc = cell && typeof cell === 'object' ? colorClass(cell.color) : null;
      el.className = 'doc-table__cell' + (cc ? ' ' + cc : '');
      appendRich(el, cell && Array.isArray(cell.text) ? cell.text : [], ctx);
      tr.appendChild(el);
    });
    tbody.appendChild(tr);
  });
  table.appendChild(tbody);
  wrap.appendChild(table);
  return wrap;
}

/** 기본 색 9 — 배경은 `_bg` 를 붙인 값이다 (doc-schema.json `colors`·`color_suffix_bg`). */
const COLORS = ['gray', 'brown', 'orange', 'yellow', 'green', 'blue', 'purple', 'pink', 'red'];

/**
 * 7-D2 관리자 팔레트 — `pal:<이름>`·`pal:<이름>_bg` 의 **모양만**(Doc::isPaletteColor 와 같은 규칙: 이름 `[a-z0-9-]{1,20}` · 끝 `-bg` 아님).
 * 목록은 보지 않는다(P-10) — 뺀 이름은 서버 CSS(`/community/palette.css`)에 규칙이 없어 색 없이 그려진다.
 * 정규식은 `match()` 로만 쓴다(`exec` 이름을 쓰지 않는다 — 보안 린트).
 */
const PALETTE_RE = /^pal:([a-z0-9-]{1,20})(_bg)?$/;
export function paletteClass(value) {
  if (typeof value !== 'string') { return null; }
  const m = value.match(PALETTE_RE);
  if (!m || m[1].slice(-3) === '-bg') { return null; }
  return 'pal-' + m[1] + (m[2] ? '-bg' : '');
}

/** 색 이름 → 클래스. 허용 목록 밖이면 null (클래스를 붙이지 않는다). */
export function colorClass(value) {
  if (typeof value !== 'string') { return null; }
  const pal = paletteClass(value);
  if (pal) { return pal; }
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

/**
 * 세그먼트 하나 → 노드 (마크를 고정 순서로 감싼다).
 * 7-D4 — `mention` 마크가 있으면 원문 글자(「@」)를 라벨로 바꿔 그리고(`mentionParts`) 가장 바깥에 둔다. 멘션과 링크는 함께 오지 않는다(검증기) —
 * 그래도 둘 다 있으면 링크는 그리지 않는다(서버 렌더러와 같다).
 */
function renderSegment(seg, ctx) {
  const marks = seg && typeof seg.m === 'object' && seg.m !== null ? seg.m : {};
  const mention = Object.prototype.hasOwnProperty.call(marks, 'mention') ? mentionParts(marks.mention, ctx) : null;
  let node = document.createTextNode(mention ? mention.text : (typeof seg.t === 'string' ? seg.t : ''));

  for (const name of MARK_ORDER) {
    if (!Object.prototype.hasOwnProperty.call(marks, name)) { continue; }
    const value = marks[name];

    if (name === 'mention') {
      const el = document.createElement(mention.tag);
      el.className = mention.cls;
      if (mention.href !== null) { el.setAttribute('href', mention.href); }
      el.appendChild(node);
      node = el;
      continue;
    }

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
      if (mention || !value || typeof value.href !== 'string' || !schemeOk(value.href)) { continue; }
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

/** 리치 텍스트를 부모에 붙인다 (`ctx` — 7-D4 멘션 이름 지도 `mention_labels`·주소 접두 `base`) */
function appendRich(parent, text, ctx) {
  if (!Array.isArray(text)) { return; }
  for (const seg of text) {
    if (!seg || typeof seg.t !== 'string') { continue; }
    parent.appendChild(renderSegment(seg, ctx));
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

/**
 * 하위 페이지 카드 (4단계 `child_page`).
 *
 * 제목은 `ctx.child_titles` 에서 찾는다 — 그 지도는 창구가 **볼 수 있는 것만** 담아 준다
 * (`GET /community/api/pages/{id}` 의 `child_titles`). 지도에 없으면 「볼 수 없는 페이지」
 * 한 장이고, 그때는 누를 수 없으니 `<a>` 를 만들지 않는다 (DESIGN 2.3).
 * **문구·클래스는 서버 렌더러 `DocRender::CHILD_PAGE_UNREADABLE` 과 같아야 한다** (C:T-19).
 */
function renderChildPage(block, id, ctx) {
  const map = ctx && ctx.child_titles && typeof ctx.child_titles === 'object' ? ctx.child_titles : {};
  const pageId = typeof block.page_id === 'string' ? block.page_id : '';
  const entry = Object.prototype.hasOwnProperty.call(map, pageId) ? map[pageId] : null;

  if (!entry || typeof entry !== 'object') {
    const div = document.createElement('div');
    div.className = 'card card--soon doc-child-page';
    if (id) { div.id = 'block-' + id; }
    const span = document.createElement('span');
    span.className = 'card__title';
    span.textContent = CHILD_PAGE_UNREADABLE;
    div.appendChild(span);
    return div;
  }

  const base = ctx && typeof ctx.base === 'string' ? ctx.base : '';
  const a = document.createElement('a');
  a.className = 'card card--link doc-child-page';
  a.setAttribute('href', base + '/p/' + pageId);
  if (id) { a.id = 'block-' + id; }
  const span = document.createElement('span');
  span.className = 'card__title';
  const icon = typeof entry.icon === 'string' && entry.icon !== '' ? entry.icon + ' ' : '';
  span.textContent = icon + (typeof entry.title === 'string' ? entry.title : '');
  a.appendChild(span);
  return a;
}

/** 블록 하나 → 노드 배열 (하위 블록이 있으면 형제로 뒤에 붙는다 — 서버 렌더러와 같은 모양) */
function renderBlock(block, rootBlocks, ctx) {
  if (block && (block.type === 'synced_source' || block.type === 'synced_ref')) {
    const el = document.createElement('div'); el.className = 'doc-synced';
    const source = block.type === 'synced_source' ? {blocks:block.children || []} : ((ctx && ctx.synced || {})[block.source_id] || {message:'볼 수 없는 원본'});
    if (source.blocks) { el.appendChild(renderBlocks(source.blocks,source.blocks,ctx)); }
    else { const p=document.createElement('p'); p.textContent=source.message;el.appendChild(p); }
    if (source.page_id) { const a=document.createElement('a');a.href=(ctx.base||'/community')+'/p/'+source.page_id;a.textContent='원본에서 고치기';el.appendChild(a); }
    return [el];
  }
  const nodes = [];
  const type = block.type;
  const id = typeof block.id === 'string' ? block.id : '';
  const kids = Array.isArray(block.children) ? renderBlocks(block.children, rootBlocks, ctx) : null;
  const setId = (el) => { if (id) { el.id = 'block-' + id; } };

  if (type === 'paragraph') {
    const p = document.createElement('p');
    p.className = blockClass('doc-p', block);
    setId(p);
    appendRich(p, block.text, ctx);
    nodes.push(p);
  } else if (type === 'heading') {
    const level = block.level === 2 || block.level === 3 ? block.level : 1;
    const h = document.createElement('h' + String(level));
    h.className = blockClass('doc-h' + String(level), block);
    setId(h);
    appendRich(h, block.text, ctx);
    nodes.push(h);
  } else if (type === 'bulleted' || type === 'numbered') {
    const li = document.createElement('li');
    li.className = blockClass('doc-li', block);
    setId(li);
    appendRich(li, block.text, ctx);
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
    appendRich(span, block.text, ctx);
    div.appendChild(box);
    div.appendChild(span);
    nodes.push(div);
  } else if (type === 'quote') {
    const q = document.createElement('blockquote');
    q.className = blockClass('doc-quote', block);
    setId(q);
    appendRich(q, block.text, ctx);
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
    appendRich(span, block.text, ctx);
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
      appendRich(cap, block.caption, ctx);
      div.appendChild(cap);
    }
    nodes.push(div);
    return nodes;
  } else if (type === 'toc') {
    nodes.push(renderToc(rootBlocks, id));
    return nodes;
  } else if (type === 'child_page') {
    nodes.push(renderChildPage(block, id, ctx));
    return nodes;
  } else if (type === 'toggle') {
    // 5-E 토글 — 펼치고 접는 것은 `<details>`(스크립트 0). 하위 블록은 **안에** 들어간다(서버 렌더러와 같다).
    const det = document.createElement('details');
    det.className = blockClass('doc-toggle', block);
    setId(det);
    const sum = document.createElement('summary');
    sum.className = 'doc-toggle__summary';
    appendRich(sum, block.text, ctx);
    const inner = document.createElement('div');
    inner.className = 'doc-toggle__body';
    if (kids) { inner.appendChild(kids); }
    det.appendChild(sum);
    det.appendChild(inner);
    nodes.push(det);
    return nodes;
  } else if (type === 'column_list') {
    // 6-C 단 나누기 — 서버 렌더러와 같은 모양(단 수 클래스 · 단 안은 보통 블록)
    const box = document.createElement('div');
    box.className = 'doc-columns doc-columns--' + ((Array.isArray(block.children) ? block.children.length : 0) === 3 ? '3' : '2');
    setId(box);
    if (kids) { box.appendChild(kids); }
    nodes.push(box);
    return nodes;
  } else if (type === 'column') {
    const colEl = document.createElement('div');
    colEl.className = 'doc-column';
    setId(colEl);
    if (kids) { colEl.appendChild(kids); }
    nodes.push(colEl);
    return nodes;
  } else if (type === 'table') {
    nodes.push(renderTable(block, id, ctx));
    return nodes;
  } else if (type === 'link_to_page') {
    nodes.push(renderLinkPage(block, id, ctx));
    return nodes;
  } else if (type === 'image' || type === 'file') {
    nodes.push(renderFileBlock(block, id, ctx));
    return nodes;
  } else if (type === 'db_view') {
    const card=document.createElement('section');card.className='panel db-widget';setId(card);
    const title=document.createElement('h3');title.className='panel__title';const entry=ctx.db_views&&ctx.db_views[block.id];
    title.textContent=entry?entry.title+' · '+entry.name:'볼 수 없는 데이터베이스';card.appendChild(title);nodes.push(card);return nodes;
  } else if (type === 'button') {
    const b=document.createElement('button'); b.type='button'; b.className='btn'; setId(b); b.textContent=String(block.label || '');
    b.dataset.automationId=String(block.automation_id || '');
    b.disabled=!(ctx.buttons && ctx.buttons[block.automation_id] && ctx.buttons[block.automation_id].can_press===true);
    if (!b.disabled && typeof ctx.pressButton==='function') b.addEventListener('click',()=>ctx.pressButton(block.automation_id,b));
    nodes.push(b); return nodes;
  } else if (type === 'embed') {
    nodes.push(renderEmbed(block, id, ctx));
    return nodes;
  } else {
    nodes.push(unsupported());
    return nodes;
  }

  if (kids) { nodes.push(kids); }
  return nodes;
}

/** 블록 배열 → DocumentFragment. 이어진 목록은 한 ul·ol 로 묶는다. */
function renderBlocks(list, rootBlocks, ctx) {
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
    for (const node of renderBlock(block, rootBlocks, ctx)) {
      target.appendChild(node);
    }
  }

  return frag;
}

/**
 * 문서 하나를 그린다.
 *
 * @param {object} doc  `{ schema: 1, blocks: [...] }`
 * @param {object} [ctx] 그릴 때 필요한 곁가지 (4단계)
 *                       · `child_titles` 창구가 준 「볼 수 있는 하위 페이지」 제목 지도
 *                       · `base`         커뮤니티 주소 접두 (`/community`)
 *                       · `mention_labels` 7-D4 창구가 준 글 안 멘션 이름 지도(`user:<id>`·`page:<uuid>` → `{label}` · 볼 수 있는 것만)
 * @returns {DocumentFragment} `<div class="doc">…</div>` 하나를 담은 조각
 */
export function renderDoc(doc, ctx) {
  const frag = document.createDocumentFragment();
  const root = document.createElement('div');
  root.className = 'doc';
  const blocks = doc && Array.isArray(doc.blocks) ? doc.blocks : [];
  root.appendChild(renderBlocks(blocks, blocks, ctx || {}));
  frag.appendChild(root);
  return frag;
}
