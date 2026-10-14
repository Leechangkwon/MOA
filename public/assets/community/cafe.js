/*
 * public/assets/community/cafe.js — 「우리 공간」 카페 화면 부품 한 벌 (사이트 Q:F-12 카페 C2)
 *
 * 요구사항: DESIGN.md 2.29(카페 홈 · 카페 셸 · 익명 표시 · 신고) · 2.3 · 2.4 · 2.6 · 2.7 · 2.21 · 6장,
 *           커뮤니티 ARCHITECTURE 25장(창구 모양 — 25.5 신고 · 처리 · 작성자 확인 · 25.7 게시판 요청), 기획 `community/cafe-plan.md`.
 * 쓰는 곳: editor.js(카페 글 · 게시판 화면의 신고 모달 · 익명 이름표 · 관리자 화면 둘 — 신고함 `/community/reports` · 게시판 요청 `/community/board-requests`) ·
 *          space-page.js(「우리 공간」 카페 홈의 「게시판 만들어 주세요」 모달 — 그 화면은 셸이 없어 동적 import 로 부른다).
 *
 * 규칙
 *   · **화면은 판단하지 않는다** — 누가 신고 · 처리 · 확인할 수 있나는 창구가 요청마다 판정한다(CommunityAuthz). 단추를 숨기는 값은 창구가 준 것
 *     (`can_request_board` · `/api/me` 의 `can_admin` · 이름표의 `is_me` · `can_reveal`)뿐이다.
 *   · 요소는 createElement / createElementNS, 글자는 textContent 로만(HTML 문자열 0) · 인라인 style 0 · 새 색값 0.
 *     새 클래스는 DESIGN 2.29 의 다섯 중 `.anon-avatar` 하나만 이 파일이 쓴다(나머지 넷은 space-page.js).
 *   · 모달은 2.7 공용 창 한 벌(`dialog.modal.modal--form` — 머리 ✕ · 바닥 오른쪽 끝 주요 하나 · 그 왼쪽 「닫기」). 닫히는 모든 길(✕ · 닫기 · Esc · 끝)에서
 *     **창을 DOM 에서 지운다** — 작성자 확인 결과(실제 이름)는 이 창 안에만 있고, 닫으면 문서에서 사라진다. 저장소 · 주소 · 콘솔에 남기지 않는다.
 *   · 재인증(403 `next:"reauth"`)은 셸의 공용 창(`window.qarediHandleReauth`) — 통과하면 같은 요청을 다시 보낸다(DESIGN 2.7 예외 — 겹치는 창은 그것 하나).
 */

const GENERIC = '요청을 처리하지 못했어요. 잠시 뒤 다시 해 주세요.';
const SVG_NS = 'http://www.w3.org/2000/svg';

/** 신고 사유 다섯 — 값은 창구의 `reason_kind` 허용 목록(COMMUNITY_REPORT_REASONS)과 같은 글자 */
export const REPORT_REASONS = [
  ['abuse', '욕설 · 비하'],
  ['defamation', '명예훼손'],
  ['privacy', '개인정보 노출'],
  ['spam', '광고 · 도배'],
  ['other', '그 밖'],
];
const REASON_TEXT = Object.fromEntries(REPORT_REASONS);
const REPORT_STATUS = { open: ['열림', 'badge badge--internal'], hidden: ['숨김', 'badge badge--off'], rejected: ['반려', 'badge'] };
const REQUEST_STATUS = { open: ['열림', 'badge badge--internal'], created: ['만듦', 'badge badge--ok'], rejected: ['반려', 'badge'] };

function el(tag, cls, text) {
  const n = document.createElement(tag);
  if (cls) { n.className = cls; }
  if (text !== undefined && text !== null) { n.textContent = String(text); }
  return n;
}

/** 선 아이콘 하나(24×24 · 선 1.8 — 셸 아이콘과 같은 규격). path 는 이 파일의 고정 글자뿐 */
function svgIcon(d) {
  const svg = document.createElementNS(SVG_NS, 'svg');
  for (const [k, v] of [['width', '16'], ['height', '16'], ['viewBox', '0 0 24 24'], ['fill', 'none'], ['stroke', 'currentColor'],
    ['stroke-width', '1.8'], ['stroke-linecap', 'round'], ['stroke-linejoin', 'round'], ['aria-hidden', 'true']]) { svg.setAttribute(k, v); }
  const p = document.createElementNS(SVG_NS, 'path');
  p.setAttribute('d', d);
  svg.appendChild(p);
  return svg;
}
const ICON_X = 'M6 6l12 12M18 6 6 18';
const ICON_PERSON = 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM4.5 20.5a7.5 7.5 0 0 1 15 0';

function msgOf(data) {
  if (data && typeof data.error === 'string' && data.error !== '') { return data.error; }
  if (data && data.error && typeof data.error.message === 'string') { return data.error.message; }
  return GENERIC;
}

/** 시각 — 「YYYY-MM-DD HH:MM」(DESIGN 2.4 — 초 · 시간대는 title 로). 서버 값은 바꾸지 않는다 */
export function shortTime(value) {
  const d = new Date(String(value || ''));
  if (Number.isNaN(d.getTime())) { return String(value || ''); }
  const two = (n) => String(n).padStart(2, '0');
  return d.getFullYear() + '-' + two(d.getMonth() + 1) + '-' + two(d.getDate()) + ' ' + two(d.getHours()) + ':' + two(d.getMinutes());
}

/** 익명 이름표인가(창구가 바꿔 끼운 사람 객체 — `anon: true`) */
export function isAnon(person) {
  return !!person && typeof person === 'object' && person.anon === true;
}

/** 익명 아바타 — 머리글자 대신 사람 아이콘(회색 --surface-2 · DESIGN 2.29) */
export function anonAvatar() {
  const a = el('span', 'anon-avatar');
  a.setAttribute('aria-hidden', 'true');
  a.appendChild(svgIcon(ICON_PERSON));
  return a;
}

/**
 * 사람 칸을 채운다 — 익명이면 「글쓴이」 · 「익명N」(창구가 준 display_name) + 내 것이면 뒤에 「(나)」(--muted) · avatar 가 참이면 사람 아이콘을 앞에.
 * 실명이면 `label(display_name, username)` 그대로(지금 화면의 한 벌 — personLabel). 아이디 · 사용자 id 는 익명 객체에 없다(창구가 싣지 않는다).
 */
export function personInto(node, person, label, opts) {
  while (node.firstChild) { node.removeChild(node.firstChild); }
  if (!person || typeof person !== 'object') { node.appendChild(document.createTextNode('—')); return node; }
  if (isAnon(person)) {
    if (opts && opts.avatar) { node.appendChild(anonAvatar()); }
    node.appendChild(document.createTextNode(String(person.display_name || '익명')));
    if (person.is_me === true) { node.appendChild(el('span', 'muted', ' (나)')); }
    return node;
  }
  const text = typeof label === 'function' ? label(person.display_name || null, person.username || '') : String(person.display_name || person.username || '');
  node.appendChild(document.createTextNode(text));
  return node;
}

/** 사람 한 줄 글자(목록 · 칸 안 문장용) — 익명이면 「익명2 (나)」 */
export function personText(person, label) {
  if (!person || typeof person !== 'object') { return '—'; }
  if (isAnon(person)) { return String(person.display_name || '익명') + (person.is_me === true ? ' (나)' : ''); }
  return typeof label === 'function' ? label(person.display_name || null, person.username || '') : String(person.display_name || person.username || '');
}

/**
 * 2.7 공용 창 한 벌. 바닥 = 오류 한 줄(.msg) · 「닫기」(테두리) · 주요 하나(검정 · danger 면 빨강). 닫히는 모든 길에서 창을 DOM 에서 지운다.
 * @return {{dialog, body, msg, ok, close: Function}}
 */
function openModal(opts) {
  const d = el('dialog', 'modal modal--form' + (opts.stack ? ' modal--stack' : ''));
  const head = el('div', 'modal__head');
  const title = el('h2', 'modal__title', opts.title);
  title.id = 'cafe-modal-title-' + String(Date.now());
  d.setAttribute('aria-labelledby', title.id);
  const x = el('button', 'btn btn--icon btn--plain');
  x.type = 'button';
  x.setAttribute('aria-label', '닫기');
  const xi = el('span', 'icon');
  xi.appendChild(svgIcon(ICON_X));
  x.appendChild(xi);
  head.append(title, x);
  const body = el('div', 'modal__body');
  const foot = el('div', 'modal__foot');
  const msg = el('p', 'msg');
  msg.setAttribute('role', 'alert');
  msg.setAttribute('aria-live', 'polite');
  const close = el('button', 'btn btn--quiet', '닫기');
  close.type = 'button';
  foot.append(msg, close);
  let ok = null;
  if (opts.okText) {
    ok = el('button', opts.danger ? 'btn btn--danger' : 'btn', opts.okText);
    ok.type = 'button';
    foot.appendChild(ok);
  }
  d.append(head, body, foot);
  const opener = document.activeElement;
  const shut = () => { if (d.open) { d.close(); } };
  x.addEventListener('click', shut);
  close.addEventListener('click', shut);
  d.addEventListener('close', () => {
    // 창의 모든 글자(작성자 확인 결과 · 적은 사유 포함)를 문서에서 걷는다 — 닫힌 창을 남겨 두지 않는다
    d.remove();
    if (typeof opts.onClose === 'function') { opts.onClose(); }
    if (opener && opener.isConnected && typeof opener.focus === 'function') { opener.focus(); }
  });
  document.body.appendChild(d);
  return { dialog: d, body: body, msg: msg, ok: ok, closeButton: close, close: shut, show: () => { d.showModal(); } };
}

/** 칸 하나(라벨 위 · 입력 아래 — 2.5) */
function field(labelText, control, help) {
  const wrap = el('label', 'field field--full');
  wrap.appendChild(el('span', 'field__label', labelText));
  wrap.appendChild(control);
  if (help) { wrap.appendChild(help); }
  return wrap;
}

function textarea(max, rows, aria) {
  const t = el('textarea');
  t.maxLength = max;
  t.rows = rows;
  if (aria) { t.setAttribute('aria-label', aria); }
  return t;
}

/**
 * @param {{api: Function, personLabel?: Function, makeIcon?: Function, base?: string}} deps
 *   api(method, path, body) → Promise<{status, data}> — 커뮤니티 창구(`/community` 아래 경로)
 */
export function createCafe(deps) {
  const api = deps.api;
  const label = typeof deps.personLabel === 'function' ? deps.personLabel : null;
  const base = typeof deps.base === 'string' ? deps.base : '/community';

  /** 재인증이 필요하면 셸의 공용 창을 띄우고 통과 뒤 retry — 띄웠으면 참 */
  function reauth(result, retry) {
    if (result && result.status === 403 && result.data && result.data.next === 'reauth' && typeof window.qarediHandleReauth === 'function') {
      window.qarediHandleReauth({ status: 403 }, result.data, retry);
      return true;
    }
    return false;
  }

  /* ── 신고 모달 (DESIGN 2.29 「신고」) — 라디오 다섯 + 설명(선택 · 500) ─────────────────────────── */
  function openReport(opts) {
    const m = openModal({ title: '신고하기', okText: '신고하기' });
    m.dialog.setAttribute('data-cafe-modal', 'report');
    m.body.appendChild(el('p', 'help', opts.commentId ? '이 댓글을 신고하는 까닭을 골라 주세요. 신고한 사람은 관리자만 봐요.'
      : '이 글을 신고하는 까닭을 골라 주세요. 신고한 사람은 관리자만 봐요.'));
    const set = el('fieldset', 'field field--full');
    set.appendChild(el('legend', 'field__label', '사유 (필수)'));
    const name = 'cafe-report-reason-' + String(Date.now());
    for (const [value, text] of REPORT_REASONS) {
      const row = el('label', 'field__label');
      const r = el('input');
      r.type = 'radio';
      r.name = name;
      r.value = value;
      row.append(r, document.createTextNode(' ' + text));
      set.appendChild(row);
    }
    m.body.appendChild(set);
    const detail = textarea(500, 3, '설명');
    m.body.appendChild(field('설명 (선택 · 500자까지)', detail));
    m.ok.addEventListener('click', () => {
      const picked = set.querySelector('input[type=radio]:checked');
      if (!picked) { m.msg.textContent = '신고하는 까닭을 골라 주세요.'; return; }
      const payload = { page_id: opts.pageId, reason_kind: picked.value };
      if (opts.commentId) { payload.comment_id = opts.commentId; }
      if (detail.value.trim() !== '') { payload.detail = detail.value; }
      m.ok.disabled = true;
      m.msg.textContent = '';
      api('POST', '/api/reports', payload).then((r) => {
        m.ok.disabled = false;
        if (r.status === 201) {
          m.close();
          if (typeof opts.onDone === 'function') { opts.onDone('신고했어요 — 관리자가 확인해요'); }
          return;
        }
        m.msg.textContent = r.status === 409 ? '이미 신고한 글이에요' : msgOf(r.data);
      }).catch(() => { m.ok.disabled = false; m.msg.textContent = GENERIC; });
    });
    m.show();
    const first = set.querySelector('input[type=radio]');
    if (first) { first.focus(); }
    return m;
  }

  /* ── 게시판 요청 모달 (DESIGN 2.29 「게시판 요청」) — 이름(40) · 쓰임(500) · 익명게시판으로 ─────────── */
  function openBoardRequest(opts) {
    const m = openModal({ title: '게시판 만들어 주세요', okText: '요청하기' });
    m.dialog.setAttribute('data-cafe-modal', 'board-request');
    m.body.appendChild(el('p', 'help', '관리자가 확인하고 만들어요. 열린 요청은 한 사람에 셋까지예요.'));
    const nameEl = el('input');
    nameEl.type = 'text';
    nameEl.maxLength = 40;
    nameEl.autocomplete = 'off';
    m.body.appendChild(field('게시판 이름 (필수 · 40자까지)', nameEl));
    const purpose = textarea(500, 3);
    m.body.appendChild(field('어디에 쓰나요 (필수 · 500자까지)', purpose));
    const anonWrap = el('label', 'field field--full');
    const anon = el('input');
    anon.type = 'checkbox';
    const anonLine = el('span', 'field__label');
    anonLine.append(anon, document.createTextNode(' 익명게시판으로'));
    anonWrap.append(anonLine, el('span', 'help', '익명게시판은 화면에서만 이름을 가려요 — 신고가 들어오면 관리자가 확인할 수 있어요.'));
    m.body.appendChild(anonWrap);
    m.ok.addEventListener('click', () => {
      if (nameEl.value.trim() === '' || purpose.value.trim() === '') { m.msg.textContent = '이름과 쓰임을 적어 주세요.'; return; }
      m.ok.disabled = true;
      m.msg.textContent = '';
      api('POST', '/api/board-requests', { name: nameEl.value, purpose: purpose.value, anonymous: anon.checked }).then((r) => {
        m.ok.disabled = false;
        if (r.status === 201) {
          m.close();
          if (typeof opts.onDone === 'function') { opts.onDone('요청했어요 — 관리자가 확인하면 알림이 와요'); }
          return;
        }
        m.msg.textContent = msgOf(r.data);
      }).catch(() => { m.ok.disabled = false; m.msg.textContent = GENERIC; });
    });
    m.show();
    nameEl.focus();
    return m;
  }

  /* ── 작성자 확인 모달 (DESIGN 2.29 「작성자 확인」 · ARCH 25.5) ─────────────────────────────────
     결과(실제 이름 · 아이디)는 이 창 안 한 줄에만 그린다 — 변수에 남기지 않고, 창이 닫히면 창째 DOM 에서 지운다(openModal). */
  function openReveal(reportId) {
    const m = openModal({ title: '작성자 확인', okText: '확인' });
    m.dialog.setAttribute('data-cafe-modal', 'reveal');
    const note = el('div', 'notice');
    note.appendChild(el('p', 'notice__title', '확인한 사실이 감사 기록에 남아요. 신고 처리에 필요할 때만 쓰세요.'));
    m.body.appendChild(note);
    const reason = textarea(500, 3);
    const count = el('span', 'help', '0자 — 20자 이상 적어 주세요');
    count.setAttribute('aria-live', 'polite');
    reason.addEventListener('input', () => {
      const n = Array.from(reason.value.trim()).length;
      count.textContent = String(n) + '자' + (n < 20 ? ' — 20자 이상 적어 주세요' : '');
    });
    m.body.appendChild(field('사유 (필수 · 20자 이상)', reason, count));
    const result = el('p', 'ok-note');
    result.hidden = true;
    result.setAttribute('role', 'status');
    m.body.appendChild(result);
    const send = () => {
      if (Array.from(reason.value.trim()).length < 20) { m.msg.textContent = '사유를 20자 이상 적어 주세요.'; return; }
      m.ok.disabled = true;
      m.msg.textContent = '';
      api('POST', '/api/reports/' + encodeURIComponent(reportId) + '/reveal', { reason: reason.value }).then((r) => {
        m.ok.disabled = false;
        if (r.status === 200 && r.data && r.data.author) {
          const a = r.data.author;
          result.textContent = '쓴 사람: ' + (a.display_name ? a.display_name + ' (' + a.username + ')' : String(a.username));
          result.hidden = false;
          reason.readOnly = true;
          m.ok.hidden = true;
          m.closeButton.focus();
          return;
        }
        if (reauth(r, send)) { return; }
        m.msg.textContent = msgOf(r.data);
      }).catch(() => { m.ok.disabled = false; m.msg.textContent = GENERIC; });
    };
    m.ok.addEventListener('click', send);
    m.show();
    reason.focus();
    return m;
  }

  /** 「작성자 확인」 단추 — 재인증 창이 먼저(15분 창이 닫혀 있으면), 그 뒤 사유 창. 재인증 여부는 관리자 창구의 답(403 reauth)으로만 안다 */
  function startReveal(reportId) {
    api('GET', '/api/reports?status=open').then((r) => {
      if (r.status === 200) { openReveal(reportId); return; }
      if (reauth(r, () => openReveal(reportId))) { return; }
    }).catch(() => {});
  }

  /* ── 셸 (「우리 공간」 모드 사이드바) — 관리 묶음은 `/api/me` 의 can_admin(표시용)이 참일 때만 연다 ─────────── */
  function bindShell(me) {
    const admin = document.getElementById('nav-cafe-admin');
    if (admin) { admin.hidden = !(me && me.can_admin === true); }
  }

  /* ── 탭(열림 · 숨김 · 반려 …) — 2.21 활동 탭과 같은 `.db-viewbar` · `.db-type` ── */
  function tabs(host, list, onPick) {
    const bar = el('div', 'toolbar db-viewbar');
    bar.setAttribute('role', 'tablist');
    bar.setAttribute('aria-label', '상태');
    const buttons = list.map(([value, text]) => {
      const b = el('button', 'btn btn--quiet btn--small db-type', text);
      b.type = 'button';
      b.setAttribute('role', 'tab');
      b.setAttribute('data-status', value);
      b.addEventListener('click', () => pick(value));
      bar.appendChild(b);
      return b;
    });
    function pick(value) {
      for (const b of buttons) {
        const on = b.getAttribute('data-status') === value;
        b.classList.toggle('is-active', on);
        b.setAttribute('aria-selected', on ? 'true' : 'false');
      }
      onPick(value);
    }
    host.appendChild(bar);
    return pick;
  }

  /* ── 신고함 (관리자 · DESIGN 2.29 「신고함」) — 2.4 표 + 탭 + 행 누름 → 오른쪽 칸(2.21 `aside.task-pane` 한 벌) ─────────── */
  function initReports() {
    const tabHost = document.getElementById('reports-tabs');
    const bodyEl = document.getElementById('reports-body');
    const empty = document.getElementById('reports-empty');
    const msg = document.getElementById('msg');
    const pane = document.getElementById('report-pane');
    if (!tabHost || !bodyEl || !pane) { return; }
    let status = 'open';
    let rows = [];
    let openId = null;

    function closePane() {
      openId = null;
      pane.hidden = true;
      while (pane.firstChild) { pane.removeChild(pane.firstChild); }
      document.body.removeAttribute('data-task-open');
      for (const tr of bodyEl.querySelectorAll('tr.is-selected')) { tr.classList.remove('is-selected'); }
    }

    function sameTarget(a, b) {
      return a.target.page_id === b.target.page_id && (a.target.comment_id || null) === (b.target.comment_id || null);
    }

    function prop(dl, name, value) {
      dl.appendChild(el('dt', null, name));
      const dd = el('dd');
      if (value instanceof Node) { dd.appendChild(value); } else { dd.textContent = String(value); }
      dl.appendChild(dd);
      return dd;
    }

    function drawPane(rep) {
      openId = rep.id;
      while (pane.firstChild) { pane.removeChild(pane.firstChild); }
      const pmsg = el('p', 'msg');
      pmsg.setAttribute('role', 'alert');
      const head = el('div', 'task-pane__head');
      const back = el('button', 'btn btn--quiet btn--small task-pane__back', '‹ 목록');
      back.type = 'button';
      back.addEventListener('click', closePane);
      const tools = el('div', 'task-pane__tools');
      const x = el('button', 'btn btn--icon btn--plain');
      x.type = 'button';
      x.setAttribute('aria-label', '닫기');
      const xi = el('span', 'icon');
      xi.appendChild(svgIcon(ICON_X));
      x.appendChild(xi);
      x.addEventListener('click', closePane);
      tools.appendChild(x);
      head.append(back, tools);
      pane.appendChild(head);
      const t = rep.target;
      const title = el('h2', 'task-pane__title', t.title);
      title.tabIndex = -1;
      pane.appendChild(title);
      const dl = el('dl', 'task-pane__props');
      prop(dl, '대상', t.kind === 'comment' ? '댓글' : '글');
      prop(dl, '게시판', t.board || '—');
      prop(dl, '쓴 사람', personInto(el('span'), t.author, label, { avatar: true }));
      const st = REPORT_STATUS[rep.status] || [rep.status, 'badge'];
      prop(dl, '상태', el('span', st[1], st[0]));
      prop(dl, '신고 수', String(rep.same_target_count));
      pane.appendChild(dl);
      if (t.excerpt) { pane.appendChild(el('p', 'task-comment', t.excerpt)); }
      const go = el('a', null, t.kind === 'comment' ? '댓글이 달린 글 열기' : '글 열기');
      go.setAttribute('href', base + '/p/' + encodeURIComponent(t.page_id) + (t.comment_id ? '#comment-' + encodeURIComponent(t.comment_id) : ''));
      pane.appendChild(go);
      // 같은 대상의 신고(이 탭에서 받은 목록 안 — 창구가 준 줄을 묶기만 한다)
      const sec = el('section', 'task-pane__section');
      sec.appendChild(el('h3', 'task-pane__section-head', '신고 목록'));
      const ul = el('ul', 'task-pane__activity');
      for (const r of rows.filter((x2) => sameTarget(x2, rep))) {
        const li = el('li', 'task-comment');
        li.appendChild(el('span', 'task-comment__who', personText(r.reporter, label) + ' · ' + shortTime(r.created_at) + ' · ' + (REASON_TEXT[r.reason_kind] || r.reason_kind)));
        li.appendChild(document.createTextNode(r.detail || '설명 없음'));
        ul.appendChild(li);
      }
      sec.appendChild(ul);
      pane.appendChild(sec);
      if (rep.status === 'open') {
        const memo = textarea(500, 3);
        pane.appendChild(field('처리 메모 (필수 · 500자까지)', memo, el('span', 'help', '숨기면 쓴 사람에게 「관리자가 숨겼어요 — 메모」 알림이 가요.')));
        const bar = el('div', 'toolbar');
        const reject = el('button', 'btn btn--quiet', '반려');
        reject.type = 'button';
        const hide = el('button', 'btn btn--danger', '숨기기');
        hide.type = 'button';
        bar.append(reject, hide);
        pane.appendChild(bar);
        const resolve = (action, button) => {
          const send = () => {
            if (memo.value.trim() === '') { pmsg.textContent = '처리 메모를 적어 주세요.'; memo.focus(); return; }
            button.disabled = true;
            pmsg.textContent = '';
            api('POST', '/api/reports/' + encodeURIComponent(rep.id) + '/resolve', { action: action, note: memo.value }).then((r) => {
              button.disabled = false;
              if (r.status === 200) {
                closePane();
                if (msg) { msg.textContent = action === 'hide' ? '숨겼어요' : '반려했어요'; msg.classList.add('msg--ok'); }
                load(status);
                return;
              }
              if (reauth(r, send)) { return; }
              pmsg.textContent = msgOf(r.data);
            }).catch(() => { button.disabled = false; pmsg.textContent = GENERIC; });
          };
          send();
        };
        hide.addEventListener('click', () => resolve('hide', hide));
        reject.addEventListener('click', () => resolve('reject', reject));
      } else if (rep.note) {
        pane.appendChild(el('p', 'task-pane__note', '처리 메모: ' + rep.note + (rep.resolver ? ' — ' + personText(rep.resolver, label) : '')));
      }
      if (t.can_reveal === true) {
        const reveal = el('button', 'btn btn--quiet', '작성자 확인');
        reveal.type = 'button';
        reveal.setAttribute('aria-haspopup', 'dialog');
        reveal.setAttribute('data-cafe-reveal', '');
        reveal.addEventListener('click', () => startReveal(rep.id));
        const revealBar = el('div', 'toolbar');
        revealBar.appendChild(reveal);
        pane.appendChild(revealBar);
      }
      pane.appendChild(pmsg);
      pane.hidden = false;
      document.body.setAttribute('data-task-open', '');
      title.focus({ preventScroll: true });
    }

    function row(rep) {
      const tr = el('tr');
      tr.setAttribute('data-report', rep.id);
      const first = el('td');
      const pick = el('button', 'btn btn--quiet btn--small', rep.target.title + (rep.target.kind === 'comment' ? ' · 댓글' : ''));
      pick.type = 'button';
      pick.addEventListener('click', (e) => { e.stopPropagation(); open(rep, tr); });
      first.appendChild(pick);
      if (rep.target.anonymous) { first.append(document.createTextNode(' '), el('span', 'badge', '익명')); }
      tr.appendChild(first);
      tr.appendChild(el('td', null, REASON_TEXT[rep.reason_kind] || rep.reason_kind));
      tr.appendChild(el('td', null, personText(rep.reporter, label)));
      tr.appendChild(el('td', null, String(rep.same_target_count)));
      const when = el('td', null, shortTime(rep.created_at));
      when.setAttribute('title', String(rep.created_at));
      tr.appendChild(when);
      const st = REPORT_STATUS[rep.status] || [rep.status, 'badge'];
      const sc = el('td');
      sc.appendChild(el('span', st[1], st[0]));
      tr.appendChild(sc);
      tr.addEventListener('click', () => open(rep, tr));
      return tr;
    }
    function open(rep, tr) {
      for (const x2 of bodyEl.querySelectorAll('tr.is-selected')) { x2.classList.remove('is-selected'); }
      tr.classList.add('is-selected');
      drawPane(rep);
    }

    function load(which) {
      status = which;
      closePane();
      return api('GET', '/api/reports?status=' + encodeURIComponent(which)).then((r) => {
        if (r.status !== 200) {
          if (reauth(r, () => load(which))) { return; }
          if (msg) { msg.classList.remove('msg--ok'); msg.textContent = msgOf(r.data); }
          return;
        }
        rows = Array.isArray(r.data.reports) ? r.data.reports : [];
        while (bodyEl.firstChild) { bodyEl.removeChild(bodyEl.firstChild); }
        for (const rep of rows) { bodyEl.appendChild(row(rep)); }
        if (empty) {
          empty.textContent = which === 'open' ? '열린 신고가 없습니다.' : (which === 'hidden' ? '숨긴 신고가 없습니다.' : '반려한 신고가 없습니다.');
          empty.hidden = rows.length > 0;
        }
      }).catch(() => { if (msg) { msg.textContent = GENERIC; } });
    }
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && openId !== null && !document.querySelector('dialog[open]')) { closePane(); } });
    const pickTab = tabs(tabHost, [['open', '열림'], ['hidden', '숨김'], ['rejected', '반려']], (v) => { if (msg) { msg.textContent = ''; } load(v); });
    pickTab('open');
  }

  /* ── 게시판 요청 (관리자 · DESIGN 2.29 「게시판 요청」) — 2.4 표 + 행 동작 「만들기」 · 「반려」(메모 모달) ─────────── */
  function initBoardRequests() {
    const tabHost = document.getElementById('requests-tabs');
    const bodyEl = document.getElementById('requests-body');
    const empty = document.getElementById('requests-empty');
    const msg = document.getElementById('msg');
    if (!tabHost || !bodyEl) { return; }
    let status = 'open';

    function decide(req, action) {
      const m = openModal({ title: action === 'create' ? '게시판 만들기' : '요청 반려', okText: action === 'create' ? '만들기' : '반려하기', danger: false });
      m.dialog.setAttribute('data-cafe-modal', 'decide');
      m.body.appendChild(el('p', 'help', '「' + req.name + '」' + (req.anonymous ? ' · 익명게시판' : '') + ' — ' + personText(req.requester, label) + ' 님의 요청'));
      let level = null;
      if (action === 'create') {
        level = el('select');
        for (const [v, t] of [['internal', '사내 — 사내 구성원 모두'], ['public', '공개 — 손님까지 모두'], ['admin-only', '관리자 — 관리자만']]) {
          const o = el('option', null, t);
          o.value = v;
          level.appendChild(o);
        }
        m.body.appendChild(field('등급 (만든 뒤에는 바꿀 수 없어요)', level));
      }
      const note = textarea(500, 3);
      m.body.appendChild(field(action === 'create' ? '메모 (선택 · 500자까지)' : '반려하는 까닭 (필수 · 500자까지)', note,
        el('span', 'help', '요청한 사람에게 알림으로 가요.')));
      const send = () => {
        if (action === 'reject' && note.value.trim() === '') { m.msg.textContent = '반려하는 까닭을 적어 주세요.'; return; }
        const payload = { action: action };
        if (note.value.trim() !== '') { payload.note = note.value; }
        if (level) { payload.access_level = level.value; }
        m.ok.disabled = true;
        m.msg.textContent = '';
        api('POST', '/api/board-requests/' + encodeURIComponent(req.id) + '/decide', payload).then((r) => {
          m.ok.disabled = false;
          if (r.status === 200) {
            m.close();
            if (msg) { msg.textContent = action === 'create' ? '게시판을 만들었어요' : '반려했어요'; msg.classList.add('msg--ok'); }
            load(status);
            return;
          }
          if (reauth(r, send)) { return; }
          m.msg.textContent = msgOf(r.data);
        }).catch(() => { m.ok.disabled = false; m.msg.textContent = GENERIC; });
      };
      m.ok.addEventListener('click', send);
      m.show();
      (level || note).focus();
    }

    function row(req) {
      const tr = el('tr');
      tr.setAttribute('data-request', req.id);
      const first = el('td', null, req.name);
      if (req.anonymous) { first.append(document.createTextNode(' '), el('span', 'badge', '익명')); }
      tr.appendChild(first);
      tr.appendChild(el('td', null, req.purpose));
      tr.appendChild(el('td', null, personText(req.requester, label)));
      const when = el('td', null, shortTime(req.created_at));
      when.setAttribute('title', String(req.created_at));
      tr.appendChild(when);
      const st = REQUEST_STATUS[req.status] || [req.status, 'badge'];
      const sc = el('td');
      sc.appendChild(el('span', st[1], st[0]));
      if (req.note) { sc.append(document.createTextNode(' '), el('span', 'muted', req.note)); }
      tr.appendChild(sc);
      const act = el('td', 'actions');
      if (req.status === 'open') {
        const mk = el('button', 'btn btn--quiet btn--small', '만들기');
        mk.type = 'button';
        mk.setAttribute('aria-haspopup', 'dialog');
        mk.addEventListener('click', () => decide(req, 'create'));
        const rj = el('button', 'btn btn--quiet btn--small', '반려');
        rj.type = 'button';
        rj.setAttribute('aria-haspopup', 'dialog');
        rj.addEventListener('click', () => decide(req, 'reject'));
        act.append(mk, rj);
      } else if (req.board_id) {
        const a = el('a', 'btn btn--quiet btn--small', '게시판 열기');
        a.setAttribute('href', base + '/b/' + String(Number(req.board_id)));
        act.appendChild(a);
      }
      tr.appendChild(act);
      return tr;
    }

    function load(which) {
      status = which;
      return api('GET', '/api/board-requests?status=' + encodeURIComponent(which)).then((r) => {
        if (r.status !== 200) {
          if (reauth(r, () => load(which))) { return; }
          if (msg) { msg.classList.remove('msg--ok'); msg.textContent = msgOf(r.data); }
          return;
        }
        const list = Array.isArray(r.data.requests) ? r.data.requests : [];
        while (bodyEl.firstChild) { bodyEl.removeChild(bodyEl.firstChild); }
        for (const req of list) { bodyEl.appendChild(row(req)); }
        if (empty) {
          empty.textContent = which === 'open' ? '열린 요청이 없습니다.' : (which === 'created' ? '만든 요청이 없습니다.' : '반려한 요청이 없습니다.');
          empty.hidden = list.length > 0;
        }
      }).catch(() => { if (msg) { msg.textContent = GENERIC; } });
    }
    const pickTab = tabs(tabHost, [['open', '열림'], ['created', '만듦'], ['rejected', '반려']], (v) => { if (msg) { msg.textContent = ''; } load(v); });
    pickTab('open');
  }

  /* ── 카페 홈 (사이트 H3 · DESIGN 2.29 「「우리 공간」 = 카페」 · 카페 홈 ①②) — `/community/cafe` 화면(카페 셸 안). space-page.js 에서 옮겨 온 한 벌.
     창구 `GET /api/cafe` 가 준 것만 그린다(읽을 수 있는 카페 게시판 · 그 글 — 거르는 것은 창구의 SQL WHERE). 익명게시판 글의 쓴 사람은 창구가
     이름표(「글쓴이」 · is_me)로 준다 — 화면은 「(나)」만 붙인다. 「게시판 만들어 주세요」는 창구가 준 can_request_board 가 참일 때만(창구가 다시 막는다).
     요소는 createElement · textContent 뿐 */
  function cafeWhen(value) {
    const d = new Date(String(value || ''));
    if (Number.isNaN(d.getTime())) { return String(value || ''); }
    const two = (n) => String(n).padStart(2, '0');
    return d.getFullYear() + '-' + two(d.getMonth() + 1) + '-' + two(d.getDate()) + ' ' + two(d.getHours()) + ':' + two(d.getMinutes());
  }
  function cafeWriter(w) {
    const span = el('span', 'muted');
    if (!w) { span.textContent = '—'; return span; }
    if (w.anon === true) { span.textContent = String(w.display_name || '익명') + (w.is_me === true ? ' (나)' : ''); return span; }
    span.textContent = w.display_name ? w.display_name + '(' + w.username + ')' : String(w.username || '');
    return span;
  }
  function cafeRow(item) {
    const li = el('li');
    const a = el('a');
    a.setAttribute('href', base + '/p/' + encodeURIComponent(item.id));
    const board = el('span', item.board && item.board.anonymous ? 'badge' : 'badge badge--internal', item.board && item.board.name ? item.board.name : '');
    const title = el('span', null, (item.icon ? item.icon + ' ' : '') + item.title);
    const when = el('span', 'muted', cafeWhen(item.posted_at));
    when.title = String(item.posted_at || '');
    a.append(board, title, cafeWriter(item.writer), when);
    li.appendChild(a);
    return li;
  }
  function cafeBoardCard(b) {
    const a = el('a', 'card card--link cafe-board');
    a.setAttribute('href', base + '/b/' + String(Number(b.id)));
    const body = el('span', 'card__body');
    const head = el('span', 'card__head');
    head.appendChild(el('span', 'card__title', b.name + (b.is_active ? '' : ' (보관)')));
    if (b.anonymous) { head.appendChild(el('span', 'badge', '익명')); }
    body.append(head, el('span', 'card__desc', b.description || '카페 게시판'));
    const meta = el('span', 'card__meta');
    const posts = el('span', null, '글 ');
    const fresh = el('span', null, '오늘 새 글 ');
    posts.appendChild(el('strong', null, String(b.post_count)));
    fresh.appendChild(el('strong', null, String(b.new_count)));
    meta.append(posts, fresh);
    body.appendChild(meta);
    a.appendChild(body);
    return a;
  }
  function initHome() {
    const host = document.getElementById('cafe-home');
    if (!host) { return; }
    const status = document.getElementById('cafe-status');
    const boards = document.getElementById('cafe-boards');
    const feed = document.getElementById('cafe-feed');
    const request = document.getElementById('cafe-request');
    if (request) {
      request.addEventListener('click', () => openBoardRequest({ onDone: (text) => { status.hidden = false; status.textContent = text; } }));
    }
    api('GET', '/api/cafe').then((r) => {
      if (r.status !== 200) {
        status.textContent = r.data && typeof r.data.error === 'string' ? r.data.error : '카페를 불러오지 못했습니다.';
        return;
      }
      const d = r.data;
      boards.replaceChildren();
      for (const b of d.boards || []) { boards.appendChild(cafeBoardCard(b)); }
      for (const [id, list] of [['cafe-recent', d.recent || []], ['cafe-popular', d.popular || []]]) {
        const ul = document.getElementById(id);
        ul.replaceChildren();
        for (const item of list) { ul.appendChild(cafeRow(item)); }
        document.getElementById(id + '-empty').hidden = list.length > 0;
      }
      feed.hidden = false;
      const any = (d.boards || []).length > 0;
      status.textContent = any ? '' : '볼 수 있는 카페 게시판이 없어요.';
      status.hidden = any;
      if (request) { request.hidden = d.can_request_board !== true; }
    }).catch(() => { status.textContent = '카페를 불러오지 못했습니다.'; });
  }

  return { openReport, openBoardRequest, startReveal, bindShell, initReports, initBoardRequests, initHome };
}
