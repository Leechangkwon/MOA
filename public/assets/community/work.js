import { drawCalendar, rangeFor } from './calendar.js?v=ad03113a4884';
import { renderDoc } from './doc-render.js?v=ad03113a4884';
/*
 * public/assets/community/work.js — 업무 화면(사이트 Q:F-12 · 조각 D): 업무 홈 · 내 작업 · 프로젝트(작업 목록 + 오른쪽 작업 상세 칸 ·
 * 프로젝트 머리 · 상태) · 포트폴리오 · 「새로 만들기」(작업 · 프로젝트) · 사이드바 포트폴리오 묶음.
 *
 * 요구사항: 기획 `../../../../community/work-ux-plan.md` 5.3 · 5.5, DESIGN.md 2.19 ~ 2.22 · 6장,
 *           커뮤니티 `../../../../community/ARCHITECTURE.md` 23.3 ~ 23.8, API.md 22장.
 * 창구: GET /api/my-tasks · GET /api/briefing · GET|PATCH /api/pages/{id} · PATCH /api/pages/{id}/properties(status · due · assignee) ·
 *       GET /api/pages/{id}/assignee-candidates · GET /api/pages/{id}/history · GET|POST /api/pages/{id}/comments ·
 *       POST /api/pages(my_tasks · preset · 행) · GET /api/pages?space=&tree=1(하위 작업) · PATCH /api/databases/{id}/schema(섹션 · use_as) ·
 *       GET|POST /api/projects/{id}/status · GET /api/spaces · GET /api/spaces/{num}/portfolios · POST /api/portfolios ·
 *       GET|PATCH|DELETE /api/portfolios/{id} · POST|DELETE /api/portfolios/{id}/items(/{project}).
 *
 * 규칙 (DESIGN 0장)
 *   · **화면은 판단하지 않는다.** 쓰는 단추(완료 · 바꾸기 · 작업 추가 · 섹션 추가 · 제목 고치기 · 상태 남기기 · 넣기)는 창구가 준
 *     `can_*`(+ `/api/me` 의 `can_community_write`)가 참일 때만 그린다 — 역할 비교 0. 막는 것은 서버다(요청마다 다시 판정).
 *   · 담당 후보는 **창구가 준 목록만**(`assignee-candidates`) — 화면이 사람을 거르거나 더하지 않는다.
 *   · 요소는 createElement, 글자는 textContent 로만 넣는다(HTML 문자열 0). 인라인 style 0 — 진행 폭은 CSSOM `--pct` 하나.
 *   · 절대 경로는 `<body data-community-base>`·`data-base` 에서만 만든다(이 파일에 `/community/…` 글자 0).
 *   · 작업 상세 칸은 dialog 가 아니라 `aside.task-pane`(화면의 한 칸) — 주소 `?task={public_id}` 가 연다, 뒤로 가기로 앞 작업 · 닫힘.
 *
 * editor.js 가 `createWork(deps)` 로 한 번 만들고 화면 키에 따라 initHome · initMyTasks · initPortfolio 를, 페이지 화면에서는
 * database.js 에 넘기는 프로젝트 고리(`projectHooks()`)와 페이지 고리(`pageLoaded`)를 쓴다. 본문 편집은 editor.js 의 `mountEditor` 를 빌린다.
 */

const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/;
const DATE_RE = /^\d{4}-\d{2}-\d{2}$/;
const PROJECT_COLORS = ['peach', 'lilac', 'mint', 'sand'];
/** 프로젝트 상태 다섯(ARCH 23.6 · DESIGN 2.22) — 값은 서버 CHECK 그대로, 글자만 한글 */
const PROJECT_STATUS = { on_track: '순조로움', at_risk: '위험', off_track: '늦어짐', on_hold: '보류', complete: '완료' };
const PROJECT_STATUS_ORDER = ['on_track', 'at_risk', 'off_track', 'on_hold', 'complete'];
/** 「내 작업」 묶음 — 서버 묶음 키 그대로(이름은 창구가 준 것을 쓴다). 그 자리 추가가 되는 묶음과 그때의 기한 */
const ADD_BUCKETS = ['today', 'no_due'];
/** 맡김 안내(WK-14 결정 문구 — DESIGN 2.21) */
const ASSIGN_NOTE = '작업을 맡겨도 소유자와 볼 수 있는 곳은 바뀌지 않아요. 담당자는 이 작업을 고칠 수 있는 사람 중에서 고를 수 있어요.';
const VISIBILITY_TEXT = { private: '비공개', team: '팀', internal: '사내', published: '게시됨' };

function el(tag, cls, text) {
  const n = document.createElement(tag);
  if (cls) { n.className = cls; }
  if (text !== undefined && text !== null) { n.textContent = String(text); }
  return n;
}

/** Asia/Seoul 오늘(서버 묶음과 같은 날짜 기준) — 화면 표시용. 묶음은 서버가 가른다 */
function seoulToday() {
  try { return new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Seoul' }).format(new Date()); } catch (e) { return new Date().toISOString().slice(0, 10); }
}

function daysBetween(a, b) {
  return Math.round((Date.parse(b + 'T12:00:00Z') - Date.parse(a + 'T12:00:00Z')) / 86400000);
}

function initialOf(name) {
  const t = String(name || '').trim();
  const c = t === '' ? '?' : Array.from(t)[0];
  return /^[a-z]$/.test(c) ? c.toUpperCase() : c;
}

export function createWork(deps) {
  const { api, base, tboxBase, setText, show, clear, errorMessage, genericError, toLogin, statusLabels, personLabel, shortWhen, makeIcon } = deps;
  const canWrite = typeof deps.canWrite === 'function' ? deps.canWrite : () => false;
  const meName = typeof deps.meName === 'function' ? deps.meName : () => '';
  const body = document.body;
  const $ = (id) => document.getElementById(id);
  const msgEl = () => $('msg');
  const pageUrl = (id) => base + '/p/' + id;

  function icon(name) { return makeIcon(name); }
  function iconButton(name, label, cls) {
    const b = el('button', 'btn btn--icon btn--plain' + (cls ? ' ' + cls : ''));
    b.type = 'button';
    b.setAttribute('aria-label', label);
    b.appendChild(icon(name));
    return b;
  }
  function textButton(text, cls, iconName) {
    const b = el('button', cls || 'btn btn--quiet btn--small');
    b.type = 'button';
    if (iconName) { b.appendChild(icon(iconName)); }
    b.appendChild(el('span', null, text));
    return b;
  }
  function colorBox(color, size) {
    const span = el('span', 'task-color' + (size ? ' task-color--' + size : '') + (PROJECT_COLORS.indexOf(color) !== -1 ? ' moa-' + color : ''));
    span.setAttribute('aria-hidden', 'true');
    return span;
  }
  function avatar(person, size) {
    const name = person ? (person.display_name || person.username || '') : '';
    const a = el('span', 'task-avatar' + (size ? ' task-avatar--' + size : ''), initialOf(name));
    a.setAttribute('aria-hidden', 'true');
    return a;
  }
  function who(person) {
    return person ? personLabel(person.display_name || null, person.username || '') : '';
  }
  /** 진행률 — DESIGN 2.22 `.progress`(role=progressbar) + 「7/9」. 폭은 CSSOM(`--pct`)로만 */
  function progressLine(done, total) {
    const line = el('span', 'progress-line');
    const bar = el('span', 'progress');
    bar.setAttribute('role', 'progressbar');
    bar.setAttribute('aria-valuemin', '0');
    bar.setAttribute('aria-valuemax', String(total));
    bar.setAttribute('aria-valuenow', String(done));
    bar.setAttribute('aria-label', '완료 ' + done + '/' + total);
    bar.style.setProperty('--pct', (total > 0 ? Math.round((done / total) * 100) : 0) + '%');
    line.appendChild(bar);
    line.appendChild(el('span', 'progress__text', done + '/' + total));
    return line;
  }
  function statusDot(status) {
    const d = el('span', 'status-dot' + (PROJECT_STATUS[status] ? ' status-dot--' + status : ''));
    d.setAttribute('aria-hidden', 'true');
    return d;
  }
  /** 마감 글자 — 지남(빨강 · 「n일 지남」은 상세 칸에서) · 오늘(--primary) · 그 밖(--fg-2). 색만으로 가르지 않는다(묶음 이름 · 글자) */
  function dueNode(due, today, long) {
    const span = el('span', null);
    if (typeof due !== 'string' || !DATE_RE.test(due)) { span.textContent = long ? '없음' : ''; return span; }
    const late = due < today;
    if (due === today) { span.className = 'task-due--today'; span.textContent = '오늘'; }
    else { span.className = late ? 'task-due--late' : ''; span.textContent = due; }
    if (long && late) { span.textContent = due + ' · ' + daysBetween(due, today) + '일 지남'; }
    return span;
  }
  function failMessage(r) {
    if (r.status === 401) { toLogin(); return ''; }
    return errorMessage(r.data);
  }

  /* ════════════════ 작업 줄 (DESIGN 2.20) ════════════════ */

  /**
   * 작업 줄 하나 — `li.task-row`: 첫 칸(▸ · 완료 원 · 제목 · 보조 · 맡긴 표시) + 칸(프로젝트 또는 담당) + 마감 (+ 빈 ＋ 칸).
   * opts: kind('my'|'project'|'compact'|'pane') · today · canCheck · current · onOpen(item) · onCheck(item, done) · expand(true|false|null) · onExpand()
   */
  function taskRow(item, opts) {
    const li = el('li', 'task-row' + (opts.sub ? ' task-row--sub' : '') + (item.status === 'done' ? ' task-row--done' : ''));
    li.setAttribute('data-task', item.id);
    if (opts.current) { li.setAttribute('aria-current', 'true'); }
    const main = el('div', 'task-row__main');
    if (opts.kind === 'project') {
      if (opts.expand === null || opts.expand === undefined) {
        main.appendChild(el('span', 'task-row__spacer'));
      } else {
        const ex = el('button', 'task-row__expand');
        ex.type = 'button';
        ex.setAttribute('aria-expanded', opts.expand ? 'true' : 'false');
        ex.setAttribute('aria-label', opts.expand ? '하위 작업 접기' : '하위 작업 펼치기');
        ex.appendChild(icon('chevron'));
        ex.addEventListener('click', () => { if (opts.onExpand) { opts.onExpand(); } });
        main.appendChild(ex);
      }
    }
    if (opts.canCheck) {
      const done = item.status === 'done';
      const check = el('button', 'task-check');
      check.type = 'button';
      check.setAttribute('aria-pressed', done ? 'true' : 'false');
      check.setAttribute('aria-label', (done ? '완료 취소' : '완료 표시') + ' — ' + item.title);
      check.appendChild(icon('check'));
      check.addEventListener('click', () => {
        check.disabled = true;
        Promise.resolve(opts.onCheck(item, !done)).finally(() => { check.disabled = false; });
      });
      main.appendChild(check);
    }
    const a = el('a', 'task-row__title', (item.icon ? item.icon + ' ' : '') + item.title);
    a.href = opts.href ? opts.href(item) : pageUrl(item.id);
    if (opts.onOpen) {
      a.addEventListener('click', (e) => {
        if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) { return; }
        e.preventDefault();
        opts.onOpen(item);
      });
    }
    main.appendChild(a);
    // 보조(댓글 수 · 하위 n/m) · 맡긴 표시 — 홈 요약 칸(좁다)에는 두지 않는다(제목이 먼저 — 「내 작업」 화면에 다 있다)
    const auxParts = [];
    if (opts.kind !== 'compact' && typeof item.comments_n === 'number' && item.comments_n > 0) { auxParts.push('댓글 ' + item.comments_n); }
    if (opts.kind !== 'compact' && typeof item.sub_total === 'number' && item.sub_total > 0) { auxParts.push('하위 ' + item.sub_done + '/' + item.sub_total); }
    if (auxParts.length) { main.appendChild(el('span', 'task-row__aux', auxParts.join(' · '))); }
    if (item.assigned_by && opts.kind !== 'compact') { main.appendChild(el('span', 'badge', item.assigned_by + ' 님이 맡김')); }
    li.appendChild(main);
    if (opts.kind === 'compact') {
      const due = el('span', 'task-row__cell task-row__cell--due');
      due.appendChild(dueNode(item.due, opts.today, false));
      li.appendChild(due);
      return li;
    }
    const meta = el('span', 'task-row__cell task-row__cell--meta');
    if (opts.kind === 'my') {
      if (item.project) {
        meta.appendChild(colorBox(item.project.color, 'sm'));
        meta.appendChild(el('span', null, item.project.title));
      } else if (item.doc_space) {
        meta.appendChild(el('span', null, item.doc_space));
      }
    } else if (item.assignee) {
      meta.appendChild(avatar(item.assignee));
      meta.appendChild(el('span', null, who(item.assignee)));
    }
    li.appendChild(meta);
    const due = el('span', 'task-row__cell task-row__cell--due');
    due.appendChild(dueNode(item.due, opts.today, false));
    li.appendChild(due);
    if (opts.kind === 'project') { li.appendChild(el('span', 'task-row__cell task-row__cell--more')); }
    return li;
  }

  /** 상태 바꾸기(완료 원) — `PATCH /properties {status}`. 끝나면 done → 「완료」, 아니면 「할 일」 */
  function setDone(id, done) {
    return api('PATCH', '/api/pages/' + id + '/properties', { status: done ? 'done' : 'todo' }).then((r) => {
      if (r.status !== 200) { throw new Error(failMessage(r)); }
      return r.data;
    });
  }

  /**
   * 그 자리 추가 줄(DESIGN 2.20) — Enter = 저장하고 다음 빈 줄 · Esc = 그만 · 빈 칸 Enter = 끝.
   * save(title) → Promise. 끝나면 onEnd().
   */
  function addLine(host, label, save, onEnd) {
    const line = el('div', 'task-add');
    const input = el('input', 'task-add__input');
    input.type = 'text';
    input.maxLength = 200;
    input.autocomplete = 'off';
    input.placeholder = '작업 이름 — Enter 로 저장';
    input.setAttribute('aria-label', label);
    line.appendChild(input);
    host.appendChild(line);
    let busy = false;
    input.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') { e.preventDefault(); line.remove(); onEnd(false); return; }
      if (e.key !== 'Enter' || e.isComposing || busy) { return; }
      e.preventDefault();
      const title = input.value.trim();
      if (title === '') { line.remove(); onEnd(false); return; }
      busy = true;
      input.disabled = true;
      Promise.resolve(save(title)).then(() => {
        busy = false;
        input.disabled = false;
        input.value = '';
        onEnd(true, input);
      }).catch((err) => {
        busy = false;
        input.disabled = false;
        setText(msgEl(), err && err.message ? err.message : genericError);
        input.focus();
      });
    });
    input.focus();
    return input;
  }

  /* ════════════════ 작업 상세 칸 (DESIGN 2.21) ════════════════ */

  /**
   * `aside.task-pane` 하나를 다룬다. 줄을 누르면 칸만 바뀌고 주소에 `?task=` — 뒤로 가기로 앞 작업 · 닫힘(popstate).
   * ctx: { onChanged(id) — 목록 다시 그리기, onMarked(id|null) — 고른 줄 표시 }
   */
  function createPane(paneEl, ctx) {
    let currentId = null;
    let loadSeq = 0;
    let editor = null;

    function urlFor(id) {
      const u = new URL(window.location.href);
      if (id) { u.searchParams.set('task', id); } else { u.searchParams.delete('task'); }
      return u.pathname + u.search + u.hash;
    }
    function taskFromUrl() {
      try {
        const t = new URLSearchParams(window.location.search).get('task');
        return t && UUID_RE.test(t) ? t : null;
      } catch (e) { return null; }
    }
    function open(id, opts) {
      if (!UUID_RE.test(id)) { return; }
      if (opts && opts.push && id !== currentId) {
        try { window.history.pushState({ task: id }, '', urlFor(id)); } catch (e) { /* 주소는 편의 */ }
      }
      currentId = id;
      body.setAttribute('data-task-open', '');
      show(paneEl, true);
      ctx.onMarked(id);
      load(id, true);
    }
    function close(opts) {
      if (editor) { editor.flush().catch(() => {}); editor.destroy(); editor = null; }
      if (opts && opts.push && currentId !== null) {
        try { window.history.pushState({ task: null }, '', urlFor(null)); } catch (e) { /* 편의 */ }
      }
      currentId = null;
      body.removeAttribute('data-task-open');
      show(paneEl, false);
      clear(paneEl);
      ctx.onMarked(null);
    }
    window.addEventListener('popstate', () => {
      const want = taskFromUrl();
      if (want) { open(want, { push: false }); } else if (currentId !== null) { close({ push: false }); }
    });

    function load(id, focusHead) {
      const seq = ++loadSeq;
      return api('GET', '/api/pages/' + id).then((r) => {
        if (seq !== loadSeq || id !== currentId) { return; }
        clear(paneEl);
        if (r.status === 401) { toLogin(); return; }
        if (r.status !== 200) {
          const head = el('div', 'task-pane__head');
          const back = textButton('‹ 목록', 'btn btn--quiet btn--small task-pane__back');
          back.addEventListener('click', () => close({ push: true }));
          head.appendChild(back);
          const tools = el('span', 'task-pane__tools');
          const x = iconButton('x', '닫기');
          x.addEventListener('click', () => close({ push: true }));
          tools.appendChild(x);
          head.appendChild(tools);
          paneEl.appendChild(head);
          paneEl.appendChild(el('p', 'msg', errorMessage(r.data)));
          return;
        }
        draw(id, r.data, focusHead);
      }).catch(() => { if (seq === loadSeq) { clear(paneEl); paneEl.appendChild(el('p', 'msg', genericError)); } });
    }

    function changed(id) {
      if (ctx.onChanged) { ctx.onChanged(id); }
      if (id === currentId) { load(id, false); }
    }

    function patchProps(id, payload, msg) {
      setText(msg, '');
      return api('PATCH', '/api/pages/' + id + '/properties', payload).then((r) => {
        if (r.status !== 200) { setText(msg, failMessage(r)); return false; }
        changed(id);
        return true;
      }).catch(() => { setText(msg, genericError); return false; });
    }

    function draw(id, data, focusHead) {
      const page = data.page || {};
      const w = canWrite();
      const canEdit = w && data.can_edit === true;
      const canAssign = w && data.can_assign === true;
      const canManage = w && data.can_manage === true;
      const canComment = w && data.can_comment === true;
      const today = seoulToday();
      const msg = el('p', 'msg');
      msg.setAttribute('role', 'alert');
      msg.setAttribute('aria-live', 'polite');

      /* 머리 줄 — 「‹ 목록」(좁은 폭) · 완료 표시 · 링크 복사 · 전체 화면으로 열기 · 더보기 · 닫기 */
      const head = el('div', 'task-pane__head');
      const back = textButton('‹ 목록', 'btn btn--quiet btn--small task-pane__back');
      back.addEventListener('click', () => close({ push: true }));
      head.appendChild(back);
      if (canEdit) {
        const done = page.status === 'done';
        const doneBtn = textButton(done ? '완료 취소' : '완료 표시', 'btn btn--quiet btn--small', 'check');
        doneBtn.setAttribute('aria-pressed', done ? 'true' : 'false');
        doneBtn.addEventListener('click', () => {
          doneBtn.disabled = true;
          patchProps(id, { status: done ? 'todo' : 'done' }, msg).finally(() => { doneBtn.disabled = false; });
        });
        head.appendChild(doneBtn);
      }
      const tools = el('span', 'task-pane__tools');
      const copy = iconButton('link', '링크 복사');
      copy.addEventListener('click', () => {
        const link = new URL(urlFor(id), window.location.origin).href;
        const done = () => setText(msg, '링크를 복사했어요.');
        if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(link).then(done).catch(() => setText(msg, link)); }
        else { setText(msg, link); }
      });
      tools.appendChild(copy);
      const full = el('a', 'btn btn--icon btn--plain');
      full.href = pageUrl(id);
      full.setAttribute('aria-label', '전체 화면으로 열기');
      full.appendChild(icon('files'));
      tools.appendChild(full);
      // 더보기(2.15) — 본문 고치기 · 하위 작업 더하기(고칠 수 있을 때) · 지우기(관리 — 맨 아래 빨강). 모두 숨으면 메뉴째 숨는다(CSS)
      const more = el('details', 'menu');
      const sum = el('summary', 'btn btn--icon btn--plain');
      sum.setAttribute('aria-label', '더보기');
      sum.appendChild(icon('menu'));
      more.appendChild(sum);
      const list = el('div', 'menu__list');
      const titleItem = el('button', 'menu__item', '제목 고치기');
      titleItem.type = 'button';
      titleItem.hidden = !canEdit;
      const editItem = el('button', 'menu__item', '본문 고치기');
      editItem.type = 'button';
      editItem.hidden = !canEdit;
      const subItem = el('button', 'menu__item', '하위 작업 더하기');
      subItem.type = 'button';
      subItem.hidden = !canEdit;
      const sep = el('hr', 'menu__sep');
      const delItem = el('button', 'menu__item menu__item--danger', '지우기');
      delItem.type = 'button';
      delItem.hidden = !canManage;
      list.append(titleItem, editItem, subItem, sep, delItem);
      more.appendChild(list);
      tools.appendChild(more);
      const x = iconButton('x', '닫기');
      x.addEventListener('click', () => close({ push: true }));
      tools.appendChild(x);
      head.appendChild(tools);
      paneEl.appendChild(head);

      /* 제목 — 누르면 그 자리 고치기(고칠 수 있을 때 · 지금 페이지 제목 고치기와 같은 창구) */
      const title = el('h2', 'task-pane__title', page.title || '');
      title.id = 'task-pane-title';
      title.tabIndex = -1;
      paneEl.removeAttribute('aria-label');
      paneEl.setAttribute('aria-labelledby', 'task-pane-title');
      if (canEdit) {
        const startTitle = () => {
          if (!title.isConnected) { return; }
          const input = el('input', 'task-pane__title-edit');
          input.type = 'text';
          input.maxLength = 200;
          input.value = page.title || '';
          input.setAttribute('aria-label', '작업 제목');
          title.replaceWith(input);
          input.focus();
          let ended = false;
          const end = (save) => {
            if (ended) { return; }
            ended = true;
            const next = input.value.trim();
            if (!save || next === '' || next === page.title) { input.replaceWith(title); return; }
            api('PATCH', '/api/pages/' + id, { doc_version: page.doc_version, title: next }).then((r) => {
              if (r.status !== 200) { input.replaceWith(title); setText(msg, failMessage(r)); return; }
              changed(id);
            }).catch(() => { input.replaceWith(title); setText(msg, genericError); });
          };
          input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.isComposing) { e.preventDefault(); end(true); }
            if (e.key === 'Escape') { e.preventDefault(); end(false); }
          });
          input.addEventListener('blur', () => end(true));
        };
        // 마우스는 제목을 눌러서, 키보드는 「더보기 › 제목 고치기」로(제목은 머리글 그대로 둔다)
        title.addEventListener('click', startTitle);
        titleItem.addEventListener('click', startTitle);
      }
      paneEl.appendChild(title);
      paneEl.appendChild(msg);

      /* 속성 — 담당자 · 마감 · 프로젝트 · 상태 · 소유자 · 볼 수 있는 곳 */
      const props = el('dl', 'task-pane__props');
      const addProp = (name) => {
        props.appendChild(el('dt', null, name));
        const dd = el('dd');
        props.appendChild(dd);
        return dd;
      };
      // 담당자
      const ddAssignee = addProp('담당자');
      if (page.assignee) {
        ddAssignee.appendChild(avatar(page.assignee, 'md'));
        ddAssignee.appendChild(el('span', null, personLabel(null, page.assignee.username)));
      } else {
        ddAssignee.appendChild(el('span', 'muted', '없음'));
      }
      const byLine = el('span', 'muted');
      ddAssignee.appendChild(byLine);
      if (page.assignee && data.assignee_can_edit === false && canEdit) {
        ddAssignee.appendChild(el('span', 'badge badge--exec', '담당자가 이 작업을 고칠 수 없어요 — 다시 맡기세요'));
      }
      if (canAssign) { ddAssignee.appendChild(assignPicker(id, page.assignee, msg)); }
      // 마감
      const ddDue = addProp('마감');
      if (canEdit) {
        const input = el('input');
        input.type = 'date';
        input.value = typeof data.due === 'string' ? data.due : '';
        input.setAttribute('aria-label', '마감 날짜');
        input.addEventListener('change', () => { patchProps(id, { due: input.value === '' ? null : input.value }, msg); });
        ddDue.appendChild(input);
        if (typeof data.due === 'string' && data.due < today) { ddDue.appendChild(dueNode(data.due, today, true)); }
      } else {
        ddDue.appendChild(dueNode(data.due, today, true));
      }
      // 프로젝트(행이면 그 데이터베이스 페이지 — 경로 줄의 바로 위 칸) · 섹션
      const ddProject = addProp('프로젝트');
      const crumbs = Array.isArray(data.breadcrumb) ? data.breadcrumb : [];
      if (data.row && crumbs.length >= 2) {
        const proj = crumbs[crumbs.length - 2];
        const pa = el('a', null, proj.title);
        pa.href = pageUrl(proj.id);
        ddProject.appendChild(pa);
        const section = data.row.values ? data.row.values.section : null;
        if (typeof section === 'string' && section !== '') { ddProject.appendChild(el('span', 'muted', '· ' + section)); }
      } else {
        ddProject.appendChild(el('span', 'muted', data.space ? '문서 · ' + data.space.name : '없음'));
      }
      // 상태(지금 상태 배지 — 고칠 수 있으면 고르개)
      const ddStatus = addProp('상태');
      if (canEdit) {
        const sel = el('select');
        sel.setAttribute('aria-label', '상태');
        sel.appendChild(new Option('없음', ''));
        for (const k of ['todo', 'doing', 'review', 'done', 'hold']) { sel.appendChild(new Option(statusLabels[k].text, k, false, page.status === k)); }
        sel.addEventListener('change', () => { patchProps(id, { status: sel.value === '' ? null : sel.value }, msg); });
        ddStatus.appendChild(sel);
      } else {
        const s = statusLabels[page.status];
        ddStatus.appendChild(el('span', s ? 'badge ' + s.badge : 'muted', s ? s.text : '없음'));
      }
      // 소유자
      const ddOwner = addProp('소유자');
      if (page.owner && page.owner.kind === 'personal') { ddOwner.appendChild(el('span', null, personLabel(page.owner.display_name || null, page.owner.username))); }
      else if (data.writer) { ddOwner.appendChild(el('span', null, who(data.writer))); }
      else { ddOwner.appendChild(el('span', null, page.owner && page.owner.name ? page.owner.name : '팀 공간')); }
      // 볼 수 있는 곳(자물쇠 + 공간 이름 · 공개 범위)
      const ddWhere = addProp('볼 수 있는 곳');
      ddWhere.appendChild(icon('lock'));
      const spaceName = data.space ? data.space.name : '';
      const vis = data.space && data.space.kind === 'team' ? '팀 공간' : (VISIBILITY_TEXT[page.visibility] || '');
      ddWhere.appendChild(el('span', null, spaceName + (vis ? ' · ' + vis : '')));
      paneEl.appendChild(props);
      paneEl.appendChild(el('p', 'task-pane__note', ASSIGN_NOTE));
      // 「○○ 님이 맡김」 — 처리 이력의 마지막 담당 사건(보는 사람이 그 페이지를 볼 수 있어 받은 기록)
      api('GET', '/api/pages/' + id + '/history').then((h) => {
        if (h.status !== 200 || id !== currentId) { return; }
        const events = Array.isArray(h.data.events) ? h.data.events : [];
        const last = events.find((e) => e.kind === 'property' && e.prop === 'assignee');
        if (last && page.assignee && last.to === page.assignee.username && last.by !== last.to) {
          setText(byLine, personLabel(last.by_display_name || null, last.by) + ' 님이 맡김');
        }
        drawActivity(events);
      }).catch(() => {});

      /* 설명 — 블록 본문(읽기 기본 · 고칠 수 있으면 「본문 고치기」로 그 자리 편집) */
      const docSec = el('section', 'task-pane__section');
      docSec.appendChild(el('h3', 'task-pane__section-head', '설명'));
      const docBox = el('div', 'task-pane__doc');
      const readCtx = {
        child_titles: data.child_titles || {}, link_titles: data.link_titles || {}, file_titles: data.file_titles || {},
        synced: data.synced || {}, embeds: data.embeds || { hosts: [], frames: false }, base: base, tbox_base: tboxBase,
        mention_labels: data.mention_labels || {}, db_views: data.db_views || {}, buttons: {}
      };
      const drawDoc = () => {
        clear(docBox);
        const blocks = page.doc && Array.isArray(page.doc.blocks) ? page.doc.blocks : [];
        const empty = () => docBox.appendChild(el('p', 'muted', canEdit ? '설명이 없어요. 더보기 › 본문 고치기로 적어 보세요.' : '설명이 없어요.'));
        if (blocks.length === 0) { empty(); return; }
        let node = null;
        try { node = renderDoc(page.doc, readCtx); } catch (e) { docBox.appendChild(el('p', 'muted', '본문을 그릴 수 없어요 — 전체 화면으로 열어 보세요.')); return; }
        // 빈 문단 하나뿐인 본문(새로 만든 작업)은 빈 상자 대신 안내 한 줄
        if (node.textContent.trim() === '' && !node.querySelector('img, table, iframe, hr, a, input')) { empty(); return; }
        docBox.appendChild(node);
      };
      drawDoc();
      docSec.appendChild(docBox);
      const editState = el('span', 'badge');
      editState.hidden = true;
      docSec.firstChild.appendChild(editState);
      editItem.addEventListener('click', () => {
        if (editor || typeof deps.mountEditor !== 'function') { return; }
        clear(docBox);
        const mount = el('div', 'panel');
        docBox.appendChild(mount);
        const endBtn = textButton('편집 마침', 'btn btn--quiet btn--small');
        docSec.firstChild.appendChild(endBtn);
        editor = deps.mountEditor(mount, {
          pageId: id, doc: page.doc, version: page.doc_version, titles: readCtx,
          onState: (t) => { setText(editState, t); show(editState, t !== ''); },
          onSaved: (doc, version) => { page.doc = doc; page.doc_version = version; },
          onError: (t) => setText(msg, t)
        });
        if (!editor) { drawDoc(); endBtn.remove(); return; }
        editor.focus();
        endBtn.addEventListener('click', () => {
          const ed = editor;
          editor = null;
          ed.flush().finally(() => { ed.destroy(); endBtn.remove(); show(editState, false); drawDoc(); if (ctx.onChanged) { ctx.onChanged(id); } });
        });
      });
      paneEl.appendChild(docSec);

      /* 하위 작업(WK-6 ① — 하위 페이지 = 하위 작업). 상태 · 기한은 그 공간 트리(보는 사람이 볼 수 있는 것만)에서 */
      const subSec = el('section', 'task-pane__section');
      const subHead = el('h3', 'task-pane__section-head', '하위 작업');
      const subCount = el('span', 'task-group__count');
      subHead.appendChild(subCount);
      subSec.appendChild(subHead);
      const subList = el('ul', 'task-list__rows');
      subSec.appendChild(subList);
      const children = Array.isArray(data.children) ? data.children : [];
      const drawSubs = (tree) => {
        clear(subList);
        const byId = {};
        for (const t of tree || []) { byId[t.id] = t; }
        let done = 0;
        for (const c of children) {
          const t = byId[c.id] || {};
          const item = { id: c.id, title: c.title, icon: c.icon, status: t.status || null, due: t.due || null, assignee: t.assignee || null };
          if (item.status === 'done') { done += 1; }
          subList.appendChild(taskRow(item, {
            kind: 'pane', today: today, canCheck: canEdit && !!byId[c.id],
            onCheck: (it, d) => setDone(it.id, d).then(() => changed(id)).catch((e) => setText(msg, e.message)),
            onOpen: (it) => open(it.id, { push: true })
          }));
        }
        setText(subCount, children.length ? done + '/' + children.length : '');
        if (!children.length) { subList.appendChild(el('li', 'muted', '하위 작업이 없어요.')); }
      };
      drawSubs([]);
      if (children.length && data.space) {
        treeOf(data.space.id).then(drawSubs).catch(() => {});
      }
      const addSub = () => {
        subItem.closest('details').open = false;
        addLine(subSec, '하위 작업 이름', (t) => api('POST', '/api/pages', { parent_id: id, title: t }).then((r) => {
          if (r.status !== 201) { throw new Error(failMessage(r)); }
          return api('PATCH', '/api/pages/' + r.data.page.id + '/properties', { status: 'todo' });
        }), (savedOne) => { if (savedOne) { forgetTree(data.space ? data.space.id : null); } load(id, false); });
      };
      if (canEdit) {
        const plus = iconButton('plus', '하위 작업 더하기');
        plus.addEventListener('click', addSub);
        subHead.appendChild(plus);
      }
      subItem.addEventListener('click', addSub);
      paneEl.appendChild(subSec);

      /* 파일 첨부(본문에 붙인 파일 — 보는 사람이 자료실에서 볼 수 있는 것만 창구가 준다) */
      const files = data.file_titles && typeof data.file_titles === 'object' ? Object.keys(data.file_titles).filter((k) => UUID_RE.test(k)) : [];
      if (files.length) {
        const fileSec = el('section', 'task-pane__section');
        fileSec.appendChild(el('h3', 'task-pane__section-head', '파일 첨부 ' + files.length));
        const ul = el('ul', 'task-list__rows');
        for (const f of files) {
          const li = el('li', 'task-event');
          const a = el('a', null, data.file_titles[f].name || '파일');
          a.href = tboxBase + '/api/files/' + f + '/download';
          li.appendChild(a);
          ul.appendChild(li);
        }
        fileSec.appendChild(ul);
        paneEl.appendChild(fileSec);
      }

      /* 활동 — 「댓글 · 모든 활동」(지울 수 없는 변경 기록 + 댓글을 한 줄기로) · 오래된 순 */
      const actSec = el('section', 'task-pane__section');
      const tabs = el('div', 'toolbar db-viewbar');
      tabs.setAttribute('role', 'tablist');
      tabs.setAttribute('aria-label', '활동');
      const tabComments = el('button', 'btn btn--quiet btn--small db-type is-active', '댓글');
      const tabAll = el('button', 'btn btn--quiet btn--small db-type', '모든 활동');
      for (const t of [tabComments, tabAll]) { t.type = 'button'; t.setAttribute('role', 'tab'); }
      const order = el('button', 'btn btn--plain btn--small', '오래된 순');
      order.type = 'button';
      order.setAttribute('aria-pressed', 'true');
      tabs.append(tabComments, tabAll, order);
      actSec.appendChild(tabs);
      const actList = el('ul', 'task-pane__activity');
      actList.setAttribute('role', 'tabpanel');
      actSec.appendChild(actList);
      paneEl.appendChild(actSec);
      let mode = 'comments';
      let oldestFirst = true;
      let events = [];
      let comments = [];
      function eventText(e) {
        const by = personLabel(e.by_display_name || null, e.by || '');
        if (e.kind === 'property') {
          const label = (v) => (e.prop === 'status' ? (statusLabels[v] ? statusLabels[v].text : '없음') : (v === null || v === '' ? '없음' : v));
          if (e.prop === 'assignee') { return e.to ? by + ' 님이 ' + e.to + ' 님에게 맡겼어요' : by + ' 님이 담당을 비웠어요'; }
          if (e.prop === 'status') { return by + ' 님이 상태를 「' + label(e.from) + '」에서 「' + label(e.to) + '」로 바꿨어요'; }
          if (e.prop === 'due') { return by + ' 님이 마감을 ' + label(e.from) + '에서 ' + label(e.to) + '로 바꿨어요'; }
          return by + ' 님이 ' + e.prop + ' 값을 바꿨어요';
        }
        if (e.kind === 'check') { return by + ' 님이 할 일을 ' + (e.checked ? '체크했어요' : '체크 해제했어요'); }
        if (e.kind === 'handover') { return by + ' 님이 ' + (e.to_display_name || e.to) + ' 님에게 넘겼어요'; }
        if (e.kind === 'version') { return by + ' 님이 본문을 고쳤어요(판 ' + e.version + ')'; }
        return '';
      }
      function drawActivityList() {
        clear(actList);
        let items = comments.filter((c) => !c.deleted && c.body !== null).map((c) => ({ at: c.created_at, comment: c }));
        if (mode === 'all') {
          for (const e of events) {
            if (e.kind === 'version' && e.is_current) { continue; }
            const t = eventText(e);
            if (t) { items.push({ at: e.at, text: t }); }
          }
        }
        items.sort((a, b) => (Date.parse(a.at) - Date.parse(b.at)) * (oldestFirst ? 1 : -1));
        for (const it of items) {
          if (it.comment) {
            const li = el('li', 'task-comment');
            li.appendChild(el('span', 'task-comment__who', who(it.comment.author) + ' · ' + shortWhen(it.comment.created_at)));
            li.appendChild(document.createTextNode(it.comment.body));
            actList.appendChild(li);
          } else {
            actList.appendChild(el('li', 'task-event', it.text + ' · ' + shortWhen(it.at)));
          }
        }
        if (!items.length) { actList.appendChild(el('li', 'muted', mode === 'all' ? '기록이 없어요.' : '댓글이 없어요.')); }
      }
      function drawActivity(list) { events = list; drawActivityList(); }
      function loadComments() {
        return api('GET', '/api/pages/' + id + '/comments').then((r) => {
          if (r.status !== 200 || id !== currentId) { return; }
          comments = Array.isArray(r.data.comments) ? r.data.comments : [];
          drawActivityList();
        }).catch(() => {});
      }
      const pickTab = (m) => {
        mode = m;
        tabComments.classList.toggle('is-active', m === 'comments');
        tabAll.classList.toggle('is-active', m === 'all');
        tabComments.setAttribute('aria-selected', m === 'comments' ? 'true' : 'false');
        tabAll.setAttribute('aria-selected', m === 'all' ? 'true' : 'false');
        drawActivityList();
      };
      tabComments.addEventListener('click', () => pickTab('comments'));
      tabAll.addEventListener('click', () => pickTab('all'));
      order.addEventListener('click', () => {
        oldestFirst = !oldestFirst;
        setText(order, oldestFirst ? '오래된 순' : '최근 순');
        order.setAttribute('aria-pressed', oldestFirst ? 'true' : 'false');
        drawActivityList();
      });
      pickTab('comments');
      loadComments();

      /* 바닥 — 댓글 칸(@아이디로 부르기 = 지금 멘션) + 「보내기」(테두리 — 칸 안에 검정을 두지 않는다) */
      if (canComment) {
        const foot = el('form', 'task-pane__foot');
        const ta = el('textarea');
        ta.rows = 2;
        ta.maxLength = 2000;
        ta.placeholder = '댓글을 쓰거나 @아이디로 부르세요';
        ta.setAttribute('aria-label', '댓글');
        const send = el('button', 'btn btn--quiet', '보내기');
        send.type = 'submit';
        foot.append(ta, send);
        foot.addEventListener('submit', (e) => {
          e.preventDefault();
          const text = ta.value.trim();
          if (text === '') { ta.focus(); return; }
          send.disabled = true;
          api('POST', '/api/pages/' + id + '/comments', { body: text }).then((r) => {
            send.disabled = false;
            if (r.status !== 201 && r.status !== 200) { setText(msg, failMessage(r)); return; }
            ta.value = '';
            loadComments();
          }).catch(() => { send.disabled = false; setText(msg, genericError); });
        });
        paneEl.appendChild(foot);
      }

      /* 지우기 — 확인 창(공용 openConfirm) 뒤 `DELETE /api/pages/{id}`(휴지통 30일) */
      delItem.addEventListener('click', () => {
        const yes = () => api('DELETE', '/api/pages/' + id).then((r) => {
          if (r.status !== 200) { setText(msg, failMessage(r)); return; }
          close({ push: true });
          if (ctx.onChanged) { ctx.onChanged(id); }
        }).catch(() => setText(msg, genericError));
        if (typeof window.qarediOpenConfirm === 'function') {
          window.qarediOpenConfirm({ title: '작업을 지울까요?', lead: '「' + (page.title || '') + '」과 하위 작업이 목록에서 사라집니다.', note: '30일 안에는 「내 휴지통」에서 되살릴 수 있습니다.', okLabel: '휴지통으로 옮기기' }, yes);
        }
      });
      if (focusHead) { title.focus({ preventScroll: true }); }
    }

    /**
     * 담당 고르기(DESIGN 2.21) — 「바꾸기」 → 2.15 `.menu` 안 찾기 칸 + **창구가 준 후보만**(`assignee-candidates` — 그 작업을 고칠 수 있는 사람) ·
     * 맨 아래 「담당 없음」. 화면은 사람을 거르지도 더하지도 않는다(0장 1). 고른 값은 `PATCH /properties {assignee}` 가 다시 판정한다.
     */
    function assignPicker(id, current, msg) {
      const box = el('details', 'menu');
      box.id = 'task-assign';
      const sum = el('summary', 'btn btn--quiet btn--small', '바꾸기');
      sum.setAttribute('aria-label', '담당자 바꾸기');
      box.appendChild(sum);
      const list = el('div', 'menu__list');
      const q = el('input');
      q.type = 'search';
      q.maxLength = 64;
      q.placeholder = '이름이나 아이디';
      q.setAttribute('aria-label', '담당자 찾기');
      list.appendChild(q);
      const found = el('div');
      found.setAttribute('role', 'group');
      found.setAttribute('aria-label', '담당 후보');
      list.appendChild(found);
      const hint = el('p', 'help', '이 작업을 고칠 수 있는 사람만 나와요.');
      list.appendChild(hint);
      // 「담당 없음」은 늘 보인다(메뉴는 보이는 항목이 없으면 통째로 숨는다 — 2.15) · 지금 담당이 없으면 누를 일이 없어 비활성
      const none = el('button', 'menu__item', '담당 없음');
      none.type = 'button';
      none.disabled = !current;
      list.appendChild(none);
      box.appendChild(list);
      const pick = (username) => {
        box.open = false;
        patchProps(id, { assignee: username }, msg);
      };
      none.addEventListener('click', () => pick(null));
      let seq = 0;
      let timer = null;
      const search = () => {
        const text = q.value.trim();
        const mine = ++seq;
        if (text === '') { clear(found); return; }
        api('GET', '/api/pages/' + id + '/assignee-candidates?q=' + encodeURIComponent(text)).then((r) => {
          if (mine !== seq) { return; }
          clear(found);
          if (r.status !== 200) { found.appendChild(el('p', 'msg', failMessage(r))); return; }
          const people = Array.isArray(r.data.candidates) ? r.data.candidates : [];
          for (const p of people) {
            const b = el('button', 'menu__item');
            b.type = 'button';
            b.setAttribute('data-candidate', p.username);
            b.appendChild(avatar(p));
            b.appendChild(el('span', null, personLabel(p.display_name || null, p.username)));
            b.addEventListener('click', () => pick(p.username));
            found.appendChild(b);
          }
          if (!people.length) { found.appendChild(el('p', 'muted', '맡길 수 있는 사람이 없어요.')); }
        }).catch(() => {});
      };
      q.addEventListener('input', () => { window.clearTimeout(timer); timer = window.setTimeout(search, 200); });
      q.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); window.clearTimeout(timer); search(); } });
      box.addEventListener('toggle', () => { if (box.open) { q.focus(); } });
      return box;
    }

    return { open, close, current: () => currentId, fromUrl: taskFromUrl, reload: () => (currentId ? load(currentId, false) : null) };
  }

  /* 공간 트리(하위 작업의 상태 · 기한) — 공간마다 한 번. 공간 관문에 막히면(남의 개인 공간의 공유 페이지) 빈 목록 */
  const trees = {};
  function treeOf(spaceId) {
    const key = String(spaceId);
    if (!trees[key]) {
      trees[key] = api('GET', '/api/pages?space=' + encodeURIComponent(key) + '&tree=1').then((r) => (r.status === 200 && Array.isArray(r.data.pages) ? r.data.pages : []));
    }
    return trees[key];
  }
  function forgetTree(spaceId) { if (spaceId !== null && spaceId !== undefined) { delete trees[String(spaceId)]; } }

  /* ════════════════ 업무 홈 ════════════════ */

  function initHome() {
    const root = $('work-home');
    if (!root) { return; }
    const msg = msgEl();
    function drawSummary() {
      return api('GET', '/api/my-tasks').then((r) => {
        if (r.status === 401) { toLogin(); return; }
        if (r.status !== 200) { setText(msg, errorMessage(r.data)); return; }
        const today = typeof r.data.today === 'string' ? r.data.today : seoulToday();
        for (const col of root.querySelectorAll('[data-home-bucket]')) {
          const key = col.getAttribute('data-home-bucket');
          const bucket = (r.data.buckets || []).find((b) => b.key === key) || { items: [] };
          const rows = col.querySelector('[data-home-rows]');
          clear(rows);
          const items = Array.isArray(bucket.items) ? bucket.items : [];
          for (const it of items.slice(0, 5)) {
            rows.appendChild(taskRow({ id: it.public_id, title: it.title, status: it.status, due: it.due, assigned_by: it.assigned_by }, {
              kind: 'compact', today: today, canCheck: canWrite(),
              href: (item) => base + '/my-tasks?task=' + item.id,
              onCheck: (item, d) => setDone(item.id, d).then(drawSummary).catch((e) => setText(msg, e.message))
            }));
          }
          const more = items.length - 5 + (bucket.next_cursor ? 1 : 0);
          if (more > 0) {
            const li = el('li', 'task-row');
            const a = el('a', 'btn btn--plain btn--small', items.length > 5 ? (bucket.next_cursor ? '더 있어요 — 내 작업에서 보기' : (items.length - 5) + '개 더 — 내 작업에서 보기') : '더 있어요 — 내 작업에서 보기');
            a.href = base + '/my-tasks';
            li.appendChild(a);
            rows.appendChild(li);
          }
          setText(col.querySelector('[data-home-count]'), items.length + (bucket.next_cursor ? '+' : ''));
          show(col.querySelector('[data-home-empty]'), items.length === 0);
        }
      }).catch(() => setText(msg, genericError));
    }
    drawSummary();
    // 안 읽은 공지 한 줄 — 「오늘」 요약의 `others` 중 공지(게시판 읽기 판정 그대로 · 서버가 만든 주소만)
    const notice = $('work-home-notice');
    const link = $('work-home-notice-link');
    api('GET', '/api/briefing').then((r) => {
      if (r.status !== 200 || !notice || !link) { return; }
      const item = (Array.isArray(r.data.others) ? r.data.others : []).find((i) => i && i.kind === 'notice'
        && typeof i.url === 'string' && i.url.indexOf(base + '/p/') === 0 && UUID_RE.test(i.url.slice((base + '/p/').length)));
      if (!item) { return; }
      setText(link, item.title);
      link.href = item.url;
      show(notice, true);
    }).catch(() => {});
    window.addEventListener('work:changed', drawSummary);
  }

  /* ════════════════ 내 작업 ════════════════ */

  function initMyTasks() {
    const root = $('work-my-tasks');
    if (!root) { return; }
    const msg = msgEl();
    const groupsEl = $('my-tasks-groups');
    const emptyEl = $('my-tasks-empty');
    const listEl = $('my-tasks-list');
    const boardEl = $('my-tasks-board');
    const calEl = $('my-tasks-calendar');
    const doneBtn = $('my-tasks-done');
    let view = 'list';
    let done = false;
    let data = null;
    const collapsed = { later: true };
    let adding = null;   // 그 자리 추가가 열린 묶음 키
    let anchor = seoulToday();
    const pane = createPane($('task-pane'), {
      onChanged: () => load(),
      onMarked: (id) => {
        for (const r of root.querySelectorAll('.task-row[data-task]')) {
          if (r.getAttribute('data-task') === id) { r.setAttribute('aria-current', 'true'); } else { r.removeAttribute('aria-current'); }
        }
      }
    });
    const rowOpts = (today) => ({
      kind: 'my', today: today, canCheck: canWrite(),
      current: false,
      onOpen: (item) => pane.open(item.id, { push: true }),
      onCheck: (item, d) => setDone(item.id, d).then(() => load()).catch((e) => setText(msg, e.message))
    });
    const toItem = (it) => ({ id: it.public_id, title: it.title, status: it.status, due: it.due, project: it.project, doc_space: it.doc_space,
      assigned_by: it.assigned_by, comments_n: it.comments_n, sub_done: it.sub_done, sub_total: it.sub_total });

    function load() {
      return api('GET', '/api/my-tasks?done=' + (done ? '1' : '0')).then((r) => {
        if (r.status === 401) { toLogin(); return; }
        if (r.status !== 200) { setText(msg, errorMessage(r.data)); return; }
        data = r.data;
        draw();
      }).catch(() => setText(msg, genericError));
    }
    function draw() {
      if (!data) { return; }
      const today = typeof data.today === 'string' ? data.today : seoulToday();
      show(listEl, view === 'list');
      show(boardEl, view === 'board');
      show(calEl, view === 'calendar');
      if (view === 'board') { drawBoard(today); return; }
      if (view === 'calendar') { drawCal(); return; }
      clear(groupsEl);
      let total = 0;
      for (const b of data.buckets || []) {
        const items = Array.isArray(b.items) ? b.items : [];
        total += items.length;
        const canAdd = !done && canWrite() && ADD_BUCKETS.indexOf(b.key) !== -1;
        if (!items.length && !canAdd) { continue; }
        const group = el('section', 'task-group');
        group.setAttribute('data-bucket', b.key);
        const open = !collapsed[b.key] || adding === b.key;
        const tog = el('button', 'task-group__toggle' + (b.key === 'overdue' ? ' task-group--late' : ''));
        tog.type = 'button';
        tog.setAttribute('aria-expanded', open ? 'true' : 'false');
        tog.appendChild(icon('chevron'));
        tog.appendChild(el('span', null, b.name));
        tog.appendChild(el('span', 'task-group__count', String(items.length) + (b.next_cursor ? '+' : '')));
        tog.addEventListener('click', () => { collapsed[b.key] = open; draw(); });
        group.appendChild(tog);
        if (open) {
          const ul = el('ul', 'task-list__rows');
          for (const it of items) {
            const item = toItem(it);
            ul.appendChild(taskRow(item, Object.assign(rowOpts(today), { current: item.id === pane.current() })));
          }
          group.appendChild(ul);
          if (b.next_cursor) {
            const moreBtn = textButton('더 보기', 'btn btn--quiet btn--small task-group__more');
            moreBtn.addEventListener('click', () => {
              moreBtn.disabled = true;
              api('GET', '/api/my-tasks?done=' + (done ? '1' : '0') + '&bucket=' + encodeURIComponent(b.key) + '&cursor=' + encodeURIComponent(b.next_cursor)).then((r) => {
                if (r.status !== 200) { moreBtn.disabled = false; setText(msg, failMessage(r)); return; }
                const got = (r.data.buckets || [])[0];
                if (got) { b.items = items.concat(got.items || []); b.next_cursor = got.next_cursor; }
                draw();
              }).catch(() => { moreBtn.disabled = false; setText(msg, genericError); });
            });
            group.appendChild(moreBtn);
          }
          if (canAdd) {
            const actions = el('div', 'task-list__actions');
            const addBtn = textButton('작업 추가', 'btn btn--quiet btn--small', 'plus');
            addBtn.setAttribute('data-add-bucket', b.key);
            actions.appendChild(addBtn);
            group.appendChild(actions);
            const startAdd = () => {
              adding = b.key;
              actions.hidden = true;
              addLine(group, b.name + '에 작업 추가', (title) => quickAdd(title, b.key === 'today' ? today : null), (saved) => {
                if (!saved) { adding = null; actions.hidden = false; return; }
                load().then(() => {
                  const again = groupsEl.querySelector('[data-add-bucket="' + b.key + '"]');
                  if (again) { again.click(); }
                });
              });
            };
            addBtn.addEventListener('click', startAdd);
          }
        }
        groupsEl.appendChild(group);
      }
      setText(emptyEl, done ? '끝난 작업이 없어요.' : '맡은 작업이 없어요. 「작업 추가」로 내 할 일을 적어 보세요.');
      show(emptyEl, total === 0 && !(canWrite() && !done));
    }
    function quickAdd(title, due) {
      const payload = { my_tasks: true, title: title };
      if (due) { payload.due = due; }
      return api('POST', '/api/pages', payload).then((r) => {
        if (r.status !== 201) { throw new Error(failMessage(r)); }
        return r.data;
      });
    }
    function drawBoard(today) {
      clear(boardEl);
      for (const b of data.buckets || []) {
        const col = el('section', 'db-board__col');
        const items = Array.isArray(b.items) ? b.items : [];
        col.appendChild(el('p', 'db-board__head', b.name + ' ' + items.length));
        for (const it of items) {
          const card = el('div', 'card db-card');
          const a = el('a', 'db-card__title', it.title);
          a.href = pageUrl(it.public_id);
          a.addEventListener('click', (e) => {
            if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) { return; }
            e.preventDefault();
            pane.open(it.public_id, { push: true });
          });
          card.appendChild(a);
          const line = el('div', 'db-card__prop');
          if (it.project) { line.appendChild(colorBox(it.project.color, 'sm')); line.appendChild(el('span', null, ' ' + it.project.title)); }
          else if (it.doc_space) { line.appendChild(el('span', 'muted', it.doc_space)); }
          card.appendChild(line);
          const dl = el('div', 'db-card__prop');
          dl.appendChild(dueNode(it.due, today, false));
          card.appendChild(dl);
          col.appendChild(card);
        }
        boardEl.appendChild(col);
      }
    }
    function drawCal() {
      clear(calEl);
      const tools = el('div', 'toolbar');
      const range = rangeFor({ type: 'calendar', mode: 'month' }, anchor);
      for (const [label, delta] of [['이전 달', -1], ['다음 달', 1]]) {
        const b = textButton(label, 'btn btn--quiet btn--small');
        b.addEventListener('click', () => {
          const next = new Date(anchor + 'T12:00:00Z');
          next.setUTCDate(1);
          next.setUTCMonth(next.getUTCMonth() + delta);
          anchor = next.toISOString().slice(0, 10);
          drawCal();
        });
        tools.appendChild(b);
      }
      tools.appendChild(el('span', 'muted', range.join(' ~ ')));
      calEl.appendChild(tools);
      const host = el('div');
      calEl.appendChild(host);
      const rows = [];
      for (const b of data.buckets || []) { for (const it of b.items || []) { if (it.due) { rows.push({ id: it.public_id, title: it.title, due: it.due }); } } }
      drawCalendar(host, { type: 'calendar', date_prop: 'due', mode: 'month' }, rows, range, { base: base, edit: false });
    }
    for (const b of root.querySelectorAll('[data-my-view]')) {
      b.addEventListener('click', () => {
        view = b.getAttribute('data-my-view');
        for (const x of root.querySelectorAll('[data-my-view]')) {
          const on = x === b;
          x.classList.toggle('is-active', on);
          x.setAttribute('aria-pressed', on ? 'true' : 'false');
        }
        draw();
      });
    }
    if (doneBtn) {
      doneBtn.addEventListener('click', () => {
        done = !done;
        doneBtn.classList.toggle('is-active', done);
        doneBtn.setAttribute('aria-pressed', done ? 'true' : 'false');
        setText(doneBtn, done ? '끝나지 않은 작업 보기' : '끝난 작업 보기');
        load();
      });
    }
    window.addEventListener('work:changed', () => load());
    load().then(() => {
      const want = pane.fromUrl();
      if (want) { pane.open(want, { push: false }); }
    });
  }

  /* ════════════════ 프로젝트 화면 (페이지 화면 위 — database.js 고리) ════════════════ */

  let pageData = null;          // 지금 페이지 창구 답(editor.js 가 넘긴다)
  let project = null;           // { db, rows, reload } — 프로젝트일 때만
  let projectPane = null;
  let projectExpanded = {};     // 하위 작업을 펼친 줄
  let projectCollapsed = {};    // 접은 섹션
  let projectAdding = null;     // 그 자리 추가가 열린 섹션 값('' = 섹션 없음)
  let projectBound = false;
  let asDoc = false;            // 「문서로 보기」를 골랐나

  let headDrawn = false;
  function pageLoaded(pageId, data) {
    pageData = data;
    headDrawn = false;
    if (project && body.hasAttribute('data-project-view')) { drawProjectHead(); }
  }

  function enterProject(ctx) {
    project = ctx;
    if (!projectBound) {
      projectBound = true;
      bindProjectHead();
      projectPane = createPane($('task-pane'), {
        onChanged: () => { forgetTree(pageData && pageData.space ? pageData.space.id : null); if (project) { project.reload(); } },
        onMarked: (id) => {
          const host = $('project-list');
          if (!host) { return; }
          for (const r of host.querySelectorAll('.task-row[data-task]')) {
            if (r.getAttribute('data-task') === id) { r.setAttribute('aria-current', 'true'); } else { r.removeAttribute('aria-current'); }
          }
        }
      });
      const want = projectPane.fromUrl();
      if (want) { projectPane.open(want, { push: false }); }
    }
    if (!asDoc) { body.setAttribute('data-project-view', ''); }
    if (!headDrawn) { drawProjectHead(); }
    moveViewExtras();
  }

  /** 행 목록 창구 답마다(database.js) — 쓰임에 따라 프로젝트 화면 · 「프로젝트로 쓰기」 항목 */
  function onRows(ctx) {
    const purpose = ctx.db.purpose;
    const isProject = purpose === 'project' || purpose === 'my_tasks';
    const useProject = $('page-use-project');
    const asProject = $('page-as-project');
    show(useProject, !isProject && ctx.db.can_manage_schema === true);
    show(asProject, isProject && asDoc);
    if (useProject && !useProject.dataset.bound) {
      useProject.dataset.bound = '1';
      useProject.addEventListener('click', () => useAs(ctx.db.id, 'project'));
    }
    if (asProject && !asProject.dataset.bound) {
      asProject.dataset.bound = '1';
      asProject.addEventListener('click', () => { asDoc = false; headDrawn = false; show(asProject, false); if (project) { enterProject(project); project.reload(); } });
    }
    if (isProject) { enterProject(ctx); } else if (project) { project = null; body.removeAttribute('data-project-view'); }
  }

  function useAs(dbId, to) {
    const msg = msgEl();
    return api('PATCH', '/api/databases/' + dbId + '/schema', { use_as: to }).then((r) => {
      if (r.status !== 200) { setText(msg, failMessage(r)); return; }
      window.location.reload();
    }).catch(() => setText(msg, genericError));
  }

  /** 보기 탭에 「규칙(= 자동 작업)」 링크와 「＋」(보기 더하기 — 지금 「폼 · 일정 · 차트 추가」 단추)를 옮겨 둔다 — 한 번만 */
  function moveViewExtras() {
    const bar = document.querySelector('#db-wrap .db-viewbar');
    if (!bar || bar.querySelector('[data-project-rules]')) { return; }
    const rules = el('a', 'btn btn--quiet btn--small db-type', '규칙');
    rules.href = base + '/automations';
    rules.setAttribute('data-project-rules', '');
    bar.appendChild(rules);
    const addView = $('db-add-view');
    if (addView) {
      clear(addView);
      addView.appendChild(icon('plus'));
      addView.setAttribute('aria-label', '보기 더하기 — 폼 · 일정 · 차트');
      addView.className = 'btn btn--icon btn--plain';
      bar.appendChild(addView);
    }
  }

  function bindProjectHead() {
    const asDocBtn = $('project-as-doc');
    if (asDocBtn) {
      asDocBtn.addEventListener('click', () => {
        asDoc = true;
        body.removeAttribute('data-project-view');
        show($('project-head'), false);
        if (projectPane && projectPane.current()) { projectPane.close({ push: true }); }
        show($('page-as-project'), true);
        if (project) { project.reload(); }
      });
    }
    const hist = $('project-history');
    if (hist) { hist.addEventListener('click', () => { const h = $('page-history'); if (h) { h.click(); } }); }
    const del = $('project-delete');
    if (del) { del.addEventListener('click', () => { const d = $('page-delete'); if (d) { d.click(); } }); }
    const asTable = $('project-as-table');
    if (asTable) { asTable.addEventListener('click', () => { if (project) { useAs(project.db.id, 'table'); } }); }
    const share = $('project-share');
    if (share) {
      share.addEventListener('click', () => {
        const a = $('page-visibility-edit');
        const b = $('page-share-open');
        if (a && !a.hidden) { a.click(); } else if (b && !b.hidden) { b.click(); }
      });
    }
    const fav = $('project-fav');
    const pageFav = $('page-fav');
    if (fav && pageFav) {
      const sync = () => {
        const on = pageFav.getAttribute('aria-pressed') === 'true';
        fav.setAttribute('aria-pressed', on ? 'true' : 'false');
        fav.setAttribute('aria-label', on ? '즐겨찾기에서 빼기' : '즐겨찾기에 넣기');
        clear(fav);
        fav.appendChild(icon(on ? 'star-fill' : 'star'));
        show(fav, !pageFav.hidden);
      };
      new MutationObserver(sync).observe(pageFav, { attributes: true, attributeFilter: ['aria-pressed', 'hidden'] });
      fav.addEventListener('click', () => pageFav.click());
      sync();
    }
    const addTask = $('project-add-task');
    if (addTask) {
      addTask.addEventListener('click', () => {
        // 첫 섹션 끝의 그 자리 추가를 연다(휴대폰도 목록 위 이 단추 — 떠 있는 단추 0)
        if (projectPane && projectPane.current()) { projectPane.close({ push: true }); }
        const first = document.querySelector('#project-list:not([hidden]) [data-add-section]');
        if (first) { first.click(); } else { openNewTask(); }
      });
    }
    bindProjectStatus();
  }

  function drawProjectHead() {
    if (!project || !pageData) { return; }
    headDrawn = true;
    const page = pageData.page || {};
    const w = canWrite();
    setText($('project-title'), page.title || '');
    document.title = (page.title || '') + ' · 모아 업무';
    const color = $('project-color');
    if (color) { color.className = 'task-color task-color--lg' + (PROJECT_COLORS.indexOf(project.db.color) !== -1 ? ' moa-' + project.db.color : ''); }
    show($('project-head'), !asDoc);
    show($('project-history'), true);
    show($('project-delete'), w && pageData.can_manage === true);
    show($('project-as-table'), w && project.db.can_manage_schema === true && project.db.purpose === 'project');
    const shareBtn = $('project-share');
    const a = $('page-visibility-edit');
    const b = $('page-share-open');
    show(shareBtn, (a && !a.hidden) || (b && !b.hidden));
    show($('project-add-task'), project.db.can_add_rows === true);
    const status = $('project-status');
    if (status) { status.disabled = false; }
    loadProjectStatus();
    drawMembers();
  }

  function subLine(current) {
    const page = pageData.page || {};
    const parts = [];
    if (pageData.space) { parts.push(pageData.space.name); }
    if (page.owner && page.owner.kind === 'personal') { parts.push('소유자 ' + personLabel(page.owner.display_name || null, page.owner.username)); }
    if (current) { parts.push(shortWhen(current.at).slice(0, 10) + ' ' + (current.note || PROJECT_STATUS[current.status] || '')); }
    setText($('project-sub'), parts.join(' · '));
  }

  let projectStatusData = null;
  function loadProjectStatus() {
    const pid = project ? project.db.page_id : null;
    if (!pid) { return; }
    api('GET', '/api/projects/' + pid + '/status?limit=10').then((r) => {
      const current = r.status === 200 ? r.data.current : null;
      projectStatusData = r.status === 200 ? r.data : null;
      const name = current && PROJECT_STATUS[current.status] ? PROJECT_STATUS[current.status] : '상태 없음';
      const dot = $('project-status-dot');
      if (dot) { dot.className = 'status-dot' + (current && PROJECT_STATUS[current.status] ? ' status-dot--' + current.status : ''); }
      setText($('project-status-text'), name);
      const btn = $('project-status');
      if (btn) {
        btn.setAttribute('aria-label', '프로젝트 상태 바꾸기 — 지금 ' + name);
        const canSet = canWrite() && pageData && pageData.can_edit === true && project.db.purpose === 'project';
        btn.disabled = !canSet;
        if (!canSet) { btn.setAttribute('aria-label', '프로젝트 상태 — 지금 ' + name); }
      }
      subLine(current);
    }).catch(() => subLine(null));
  }

  function bindProjectStatus() {
    const btn = $('project-status');
    const dialog = $('project-status-dialog');
    const form = $('project-status-form');
    const note = $('project-status-note');
    const msg = $('project-status-msg');
    const hist = $('project-status-history');
    if (!btn || !dialog || !form) { return; }
    btn.addEventListener('click', () => {
      if (btn.disabled) { return; }
      setText(msg, '');
      note.value = '';
      const current = projectStatusData ? projectStatusData.current : null;
      for (const r of form.querySelectorAll('input[name="project-status"]')) { r.checked = !!current && r.value === current.status; }
      clear(hist);
      const ups = projectStatusData && Array.isArray(projectStatusData.updates) ? projectStatusData.updates : [];
      for (const u of ups) {
        const li = el('li', 'list__item');
        const h = el('div', 'list__head');
        h.appendChild(statusDot(u.status));
        h.appendChild(el('strong', null, PROJECT_STATUS[u.status] || u.status));
        h.appendChild(el('span', 'muted', shortWhen(u.at) + (u.by ? ' · ' + who(u.by) : '')));
        li.appendChild(h);
        if (u.note) { li.appendChild(el('div', 'list__body', u.note)); }
        hist.appendChild(li);
      }
      show(hist, ups.length > 0);
      if (typeof dialog.showModal === 'function') { dialog.showModal(); }
    });
    for (const c of dialog.querySelectorAll('[data-project-status-close]')) { c.addEventListener('click', () => dialog.close()); }
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      const picked = form.querySelector('input[name="project-status"]:checked');
      if (!picked) { setText(msg, '상태를 고르세요.'); return; }
      const save = $('project-status-save');
      save.disabled = true;
      api('POST', '/api/projects/' + project.db.page_id + '/status', { status: picked.value, note: note.value.trim() }).then((r) => {
        save.disabled = false;
        if (r.status !== 200) { setText(msg, failMessage(r)); return; }
        dialog.close();
        loadProjectStatus();
      }).catch(() => { save.disabled = false; setText(msg, genericError); });
    });
  }

  /** 구성원 아바타(최대 4 + 「+n」) — 소유자 + 공유받은 사람(창구가 이름을 줄 때만 · 수는 창구가 준 count) */
  function drawMembers() {
    const host = $('project-members');
    if (!host || !project) { return; }
    const page = pageData.page || {};
    api('GET', '/api/pages/' + project.db.page_id + '/shares').then((r) => {
      clear(host);
      const people = [];
      if (page.owner && page.owner.kind === 'personal') { people.push({ username: page.owner.username, display_name: page.owner.display_name || null }); }
      if (r.status === 200 && Array.isArray(r.data.shares)) {
        for (const s of r.data.shares) { if (s && s.username) { people.push({ username: s.username, display_name: s.display_name || null }); } }
      }
      const shown = people.slice(0, 4);
      for (const p of shown) { host.appendChild(avatar(p, 'lg')); }
      const total = people.length + (r.status === 200 && !Array.isArray(r.data.shares) ? Number(r.data.count) || 0 : 0);
      if (total > shown.length) { host.appendChild(el('span', 'task-avatar task-avatar--lg', '+' + (total - shown.length))); }
      host.setAttribute('aria-label', '함께하는 사람 ' + total + '명');
      host.setAttribute('role', 'img');
      show(host, shown.length > 0);
    }).catch(() => {});
  }

  /**
   * 프로젝트 목록 보기(DESIGN 2.20) — database.js 가 목록 보기일 때 부른다. 섹션(묶는 select 칸)의 선택지 순서로 묶음, 빈 값은 「섹션 없음」.
   * 칸: 이름 · 담당자 · 마감 · ＋(칸 더하기 — 관리 자격). 줄을 누르면 오른쪽 상세 칸(`?task=`).
   */
  function drawProjectList(host, ctx) {
    project = ctx;
    const db = ctx.db;
    const msg = $('db-msg') || msgEl();
    const today = seoulToday();
    const view = (ctx.views || []).find((v) => v.id === ctx.viewId) || null;
    const groupKey = view && view.group_by && view.group_by !== 'status' ? view.group_by : 'section';
    const gp = (db.schema || []).find((p) => p.key === groupKey && p.type === 'select' && Array.isArray(p.options)) || null;
    const spaceId = pageData && pageData.space ? pageData.space.id : null;
    clear(host);
    const headRow = el('div', 'task-list__head');
    headRow.append(el('span', null, '이름'), el('span', null, '담당자'), el('span', null, '마감'));
    if (db.can_manage_schema) {
      const plus = iconButton('plus', '칸 더하기');
      plus.addEventListener('click', () => { const b = $('db-add-prop'); if (b) { b.click(); } });
      headRow.appendChild(plus);
    } else {
      headRow.appendChild(el('span'));
    }
    host.appendChild(headRow);
    const groups = gp ? gp.options.map((o) => ({ value: o, name: o })) : [];
    groups.push({ value: '', name: gp ? (gp.key === 'section' ? '섹션 없음' : gp.name + ' 없음') : '작업' });
    const valueOf = (r) => {
      const v = gp && r.values ? r.values[gp.key] : null;
      return gp && typeof v === 'string' && gp.options.indexOf(v) !== -1 ? v : '';
    };
    const childrenOf = {};
    const render = (tree) => {
      for (const t of tree || []) { if (t.parent_id) { (childrenOf[t.parent_id] = childrenOf[t.parent_id] || []).push(t); } }
      for (const g of groups) {
        const rows = ctx.rows.filter((r) => valueOf(r) === g.value);
        if (g.value === '' && rows.length === 0 && gp) { continue; }
        const sec = el('section', 'task-group');
        sec.setAttribute('data-section', g.value);
        const open = !projectCollapsed[g.value] || projectAdding === g.value;
        const tog = el('button', 'task-group__toggle');
        tog.type = 'button';
        tog.setAttribute('aria-expanded', open ? 'true' : 'false');
        tog.appendChild(icon('chevron'));
        tog.appendChild(el('span', null, g.name));
        tog.appendChild(el('span', 'task-group__count', String(rows.length)));
        tog.addEventListener('click', () => { projectCollapsed[g.value] = open; drawProjectList(host, ctx); });
        sec.appendChild(tog);
        if (open) {
          const ul = el('ul', 'task-list__rows');
          for (const r of rows) {
            const kids = (childrenOf[r.id] || []);
            const item = { id: r.id, title: r.title, icon: r.icon, status: r.status, due: r.due, assignee: r.assignee,
              sub_total: kids.length, sub_done: kids.filter((k) => k.status === 'done').length };
            const expanded = !!projectExpanded[r.id];
            ul.appendChild(taskRow(item, {
              kind: 'project', today: today, canCheck: db.can_edit_values === true,
              current: projectPane && projectPane.current() === r.id,
              expand: kids.length ? expanded : null,
              onExpand: () => { projectExpanded[r.id] = !expanded; drawProjectList(host, ctx); },
              onOpen: (it) => projectPane.open(it.id, { push: true }),
              onCheck: (it, d) => setDone(it.id, d).then(() => ctx.reload()).catch((e) => setText(msg, e.message))
            }));
            if (expanded) {
              for (const k of kids) {
                ul.appendChild(taskRow({ id: k.id, title: k.title, icon: k.icon, status: k.status, due: k.due,
                  assignee: k.assignee ? { username: k.assignee.username, display_name: null } : null }, {
                  kind: 'project', sub: true, today: today, canCheck: db.can_edit_values === true,
                  current: projectPane && projectPane.current() === k.id,
                  onOpen: (it) => projectPane.open(it.id, { push: true }),
                  onCheck: (it, d) => setDone(it.id, d).then(() => { forgetTree(spaceId); ctx.reload(); }).catch((e) => setText(msg, e.message))
                }));
              }
            }
          }
          sec.appendChild(ul);
          if (db.can_add_rows === true) {
            const actions = el('div', 'task-list__actions');
            const add = textButton('작업 추가', 'btn btn--quiet btn--small', 'plus');
            add.setAttribute('data-add-section', g.value);
            actions.appendChild(add);
            sec.appendChild(actions);
            add.addEventListener('click', () => {
              projectAdding = g.value;
              actions.hidden = true;
              addLine(sec, g.name + '에 작업 추가', (title) => {
                const payload = { parent_id: db.page_id, title: title };
                if (gp && g.value !== '') { payload.properties = {}; payload.properties[gp.key] = g.value; }
                return api('POST', '/api/pages', payload).then((r) => {
                  if (r.status !== 201) { throw new Error(failMessage(r)); }
                  return api('PATCH', '/api/pages/' + r.data.page.id + '/properties', { status: 'todo' });
                });
              }, (saved) => {
                if (!saved) { projectAdding = null; actions.hidden = false; return; }
                Promise.resolve(ctx.reload()).then(() => {
                  const again = host.querySelector('[data-add-section="' + g.value.replace(/["\\]/g, '') + '"]');
                  if (again && again.getAttribute('data-add-section') === g.value) { again.click(); }
                });
              });
            });
          }
        }
        host.appendChild(sec);
      }
      // 섹션 추가(관리 자격 — 스키마 변경 `options`, decidePageManage)
      if (gp && db.can_manage_schema === true) {
        const tail = el('div', 'task-list__actions');
        const addSec = textButton('섹션 추가', 'btn btn--quiet btn--small', 'plus');
        tail.appendChild(addSec);
        host.appendChild(tail);
        addSec.addEventListener('click', () => {
          tail.hidden = true;
          const line = el('div', 'task-add');
          const input = el('input', 'task-add__input');
          input.type = 'text';
          input.maxLength = 50;
          input.placeholder = '섹션 이름 — Enter 로 저장';
          input.setAttribute('aria-label', '새 섹션 이름');
          line.appendChild(input);
          host.appendChild(line);
          input.focus();
          input.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') { line.remove(); tail.hidden = false; return; }
            if (e.key !== 'Enter' || e.isComposing) { return; }
            e.preventDefault();
            const name = input.value.trim();
            if (name === '') { line.remove(); tail.hidden = false; return; }
            input.disabled = true;
            api('PATCH', '/api/databases/' + db.id + '/schema', { options: { key: gp.key, options: gp.options.concat([name]) } }).then((r) => {
              if (r.status !== 200) { input.disabled = false; setText(msg, failMessage(r)); return; }
              ctx.reload();
            }).catch(() => { input.disabled = false; setText(msg, genericError); });
          });
        });
      }
      if (!ctx.rows.length) { host.appendChild(el('p', 'muted', db.can_add_rows ? '아직 작업이 없어요. 「작업 추가」로 첫 작업을 적어 보세요.' : '아직 작업이 없어요.')); }
    };
    if (spaceId !== null && ctx.rows.length) {
      treeOf(spaceId).then(render).catch(() => render([]));
    } else {
      render([]);
    }
  }

  /** database.js 에 넘기는 고리 — 행 목록 답마다 · 목록 보기 그리기 */
  function projectHooks() {
    return { onRows: onRows, drawList: drawProjectList };
  }

  /* ════════════════ 포트폴리오 ════════════════ */

  function initPortfolio() {
    const root = $('work-portfolio');
    if (!root) { return; }
    const id = root.getAttribute('data-portfolio-id') || '';
    if (!UUID_RE.test(id)) { return; }
    const msg = msgEl();
    const title = $('portfolio-title');
    const sub = $('portfolio-sub');
    const counts = $('portfolio-counts');
    const rowsEl = $('portfolio-rows');
    const emptyEl = $('portfolio-empty');
    const addBtn = $('portfolio-add');
    const addRow = $('portfolio-add-row');
    const renameBtn = $('portfolio-rename');
    const deleteBtn = $('portfolio-delete');
    const addDialog = $('portfolio-add-dialog');
    const renameDialog = $('portfolio-rename-dialog');
    let current = null;
    const heads = Array.from(document.querySelectorAll('#portfolio-table thead th')).map((th) => th.textContent.trim());
    function cell(tr, i) {
      const td = el('td');
      if (heads[i]) { td.setAttribute('data-label', heads[i]); }
      tr.appendChild(td);
      return td;
    }
    function load() {
      return api('GET', '/api/portfolios/' + id).then((r) => {
        if (r.status === 401) { toLogin(); return; }
        if (r.status !== 200) {
          setText(title, '포트폴리오');
          setText(msg, errorMessage(r.data));
          show(root.querySelector('.table-wrap'), false);
          show($('portfolio-info'), false);
          return;
        }
        current = r.data;
        const pf = r.data.portfolio || {};
        setText(title, pf.name || '');
        document.title = (pf.name || '포트폴리오') + ' · 모아 업무';
        setText(sub, (pf.space ? pf.space.name + ' · ' : '') + '팀이 함께 보는 프로젝트 묶음이에요.');
        const can = canWrite() && r.data.can_manage === true;
        show(addBtn, can);
        show(addRow, can);
        show(renameBtn, can);
        show(deleteBtn, can);
        clear(counts);
        const by = r.data.by_status || {};
        for (const k of PROJECT_STATUS_ORDER.concat(['none'])) {
          const n = Number(by[k]) || 0;
          if (!n) { continue; }
          const b = el('span', 'badge');
          if (k !== 'none') { b.appendChild(statusDot(k)); }
          b.appendChild(document.createTextNode((k === 'none' ? '상태 없음' : PROJECT_STATUS[k]) + ' ' + n));
          counts.appendChild(b);
        }
        counts.appendChild(el('span', 'muted', '전체 ' + (Number(r.data.total) || 0)));
        clear(rowsEl);
        const items = Array.isArray(r.data.items) ? r.data.items : [];
        const today = seoulToday();
        for (const it of items) {
          const p = it.project || {};
          const tr = el('tr');
          tr.setAttribute('data-project', p.id || '');
          const tdName = cell(tr, 0);
          const a = el('a');
          a.href = pageUrl(p.id);
          a.appendChild(colorBox(p.color, 'sm'));
          a.appendChild(el('span', null, (p.icon ? p.icon + ' ' : '') + (p.title || '')));
          tdName.appendChild(a);
          const tdStatus = cell(tr, 1);
          if (it.status && PROJECT_STATUS[it.status.status]) {
            const b = el('span', 'badge');
            b.appendChild(statusDot(it.status.status));
            b.appendChild(document.createTextNode(PROJECT_STATUS[it.status.status]));
            if (it.status.status === 'complete') { b.appendChild(icon('check')); }
            tdStatus.appendChild(b);
          } else {
            tdStatus.appendChild(el('span', 'muted', '—'));
          }
          const prog = it.progress || { done: 0, total: 0 };
          cell(tr, 2).appendChild(progressLine(Number(prog.done) || 0, Number(prog.total) || 0));
          cell(tr, 3).appendChild(el('span', null, it.owner ? who(it.owner) : '—'));
          cell(tr, 4).appendChild(dueNode(it.due, today, false));
          const tdNote = cell(tr, 5);
          if (it.status) {
            tdNote.appendChild(el('span', 'muted', shortWhen(it.status.at).slice(0, 10) + ' '));
            tdNote.appendChild(document.createTextNode(it.status.note || ''));
          } else { tdNote.textContent = '—'; }
          const tdAct = el('td', 'actions');
          if (can) {
            const out = el('button', 'btn btn--quiet btn--small', '빼기');
            out.type = 'button';
            out.setAttribute('aria-label', '포트폴리오에서 빼기 — ' + (p.title || ''));
            out.addEventListener('click', () => {
              out.disabled = true;
              api('DELETE', '/api/portfolios/' + id + '/items/' + p.id).then((x) => {
                if (x.status !== 200) { out.disabled = false; setText(msg, failMessage(x)); return; }
                load();
              }).catch(() => { out.disabled = false; setText(msg, genericError); });
            });
            tdAct.appendChild(out);
          }
          tr.appendChild(tdAct);
          rowsEl.appendChild(tr);
        }
        show(emptyEl, items.length === 0);
        setText(msg, '');
        markNav();
      }).catch(() => setText(msg, genericError));
    }
    function markNav() {
      for (const a of document.querySelectorAll('#nav-work-portfolios a[data-portfolio-id]')) {
        const on = a.getAttribute('data-portfolio-id') === id;
        a.classList.toggle('is-active', on);
        if (on) { a.setAttribute('aria-current', 'page'); }
      }
    }
    const openAdd = () => { setText($('portfolio-add-msg'), ''); if (addDialog && typeof addDialog.showModal === 'function') { addDialog.showModal(); } };
    if (addBtn) { addBtn.addEventListener('click', openAdd); }
    if (addRow) { addRow.addEventListener('click', openAdd); }
    for (const d of [addDialog, renameDialog]) {
      if (!d) { continue; }
      for (const c of d.querySelectorAll('[data-portfolio-close]')) { c.addEventListener('click', () => d.close()); }
    }
    const addForm = $('portfolio-add-form');
    if (addForm) {
      addForm.addEventListener('submit', (e) => {
        e.preventDefault();
        const pick = $('portfolio-add-pick');
        if (!pick || !UUID_RE.test(pick.value)) { setText($('portfolio-add-msg'), '넣을 프로젝트를 고르세요.'); return; }
        api('POST', '/api/portfolios/' + id + '/items', { project: pick.value }).then((r) => {
          if (r.status !== 201 && r.status !== 200) { setText($('portfolio-add-msg'), failMessage(r)); return; }
          addDialog.close();
          load();
        }).catch(() => setText($('portfolio-add-msg'), genericError));
      });
    }
    if (renameBtn) {
      renameBtn.addEventListener('click', () => {
        const input = $('portfolio-rename-input');
        if (input && current) { input.value = current.portfolio.name || ''; }
        setText($('portfolio-rename-msg'), '');
        if (renameDialog && typeof renameDialog.showModal === 'function') { renameDialog.showModal(); }
      });
    }
    const renameForm = $('portfolio-rename-form');
    if (renameForm) {
      renameForm.addEventListener('submit', (e) => {
        e.preventDefault();
        const name = $('portfolio-rename-input').value.trim();
        if (name === '') { return; }
        api('PATCH', '/api/portfolios/' + id, { name: name }).then((r) => {
          if (r.status !== 200) { setText($('portfolio-rename-msg'), failMessage(r)); return; }
          renameDialog.close();
          load();
          fillPortfolioNav();
        }).catch(() => setText($('portfolio-rename-msg'), genericError));
      });
    }
    if (deleteBtn) {
      deleteBtn.addEventListener('click', () => {
        const yes = () => api('DELETE', '/api/portfolios/' + id).then((r) => {
          if (r.status !== 200) { setText(msg, failMessage(r)); return; }
          window.location.assign(base + '/');
        }).catch(() => setText(msg, genericError));
        if (typeof window.qarediOpenConfirm === 'function') {
          window.qarediOpenConfirm({ title: '포트폴리오를 지울까요?', lead: '「' + (current && current.portfolio ? current.portfolio.name : '') + '」 묶음이 사라집니다.', note: '넣어 둔 프로젝트와 작업은 그대로 남아요.', okLabel: '포트폴리오 지우기' }, yes);
        }
      });
    }
    load();
  }

  /* ════════════════ 셸 — 「새로 만들기」 · 사이드바 포트폴리오 · 지금 프로젝트 표시 ════════════════ */

  function modal(idBase, titleText, submitText) {
    const d = el('dialog', 'modal modal--form');
    d.id = idBase + '-dialog';
    d.setAttribute('aria-labelledby', idBase + '-title');
    const head = el('div', 'modal__head');
    const h = el('h2', 'modal__title', titleText);
    h.id = idBase + '-title';
    head.appendChild(h);
    const x = iconButton('x', '닫기');
    x.addEventListener('click', () => d.close());
    head.appendChild(x);
    d.appendChild(head);
    const form = el('form', 'modal__body form');
    form.id = idBase + '-form';
    d.appendChild(form);
    const foot = el('div', 'form-actions');
    const msg = el('p', 'msg');
    msg.setAttribute('role', 'alert');
    msg.setAttribute('aria-live', 'polite');
    const close = el('button', 'btn btn--quiet', '닫기');
    close.type = 'button';
    close.addEventListener('click', () => d.close());
    const ok = el('button', 'btn', submitText);
    ok.type = 'submit';
    ok.setAttribute('form', form.id);
    foot.append(msg, close, ok);
    d.appendChild(foot);
    document.body.appendChild(d);
    return { dialog: d, form: form, msg: msg, ok: ok };
  }
  function field(form, label, control) {
    const f = el('label', 'field field--full');
    f.appendChild(el('span', 'field__label', label));
    f.appendChild(control);
    form.appendChild(f);
    return control;
  }
  function closeDrawer() {
    const t = $('nav-toggle');
    if (t && t.getAttribute('aria-expanded') === 'true') { t.click(); }
  }

  let newTask = null;
  function openNewTask() {
    if (!canWrite()) { return; }
    closeDrawer();
    if (!newTask) {
      const m = modal('work-new-task', '새 작업', '만들기');
      const title = el('input');
      title.type = 'text';
      title.maxLength = 200;
      title.required = true;
      title.autocomplete = 'off';
      field(m.form, '작업 이름', title);
      const due = el('input');
      due.type = 'date';
      field(m.form, '마감 (선택)', due);
      const where = el('select');
      field(m.form, '프로젝트', where);
      m.form.appendChild(el('p', 'help', '담당자는 만든 뒤 작업 상세 칸에서 정해요 — 그 작업을 고칠 수 있는 사람 중에서 고를 수 있어요.'));
      m.form.addEventListener('submit', (e) => {
        e.preventDefault();
        const t = title.value.trim();
        if (t === '') { title.focus(); return; }
        const dueValue = DATE_RE.test(due.value) ? due.value : null;
        m.ok.disabled = true;
        setText(m.msg, '');
        const target = where.value;
        const made = target === 'my_tasks'
          ? api('POST', '/api/pages', Object.assign({ my_tasks: true, title: t }, dueValue ? { due: dueValue } : {})).then((r) => {
            if (r.status !== 201) { throw new Error(failMessage(r)); }
            return { id: r.data.page.id, project: null };
          })
          : api('POST', '/api/pages', { parent_id: target, title: t }).then((r) => {
            if (r.status !== 201) { throw new Error(failMessage(r)); }
            const props = { status: 'todo' };
            if (dueValue) { props.due = dueValue; }
            return api('PATCH', '/api/pages/' + r.data.page.id + '/properties', props).then(() => ({ id: r.data.page.id, project: target }));
          });
        made.then((got) => {
          m.ok.disabled = false;
          m.dialog.close();
          title.value = '';
          due.value = '';
          if ($('work-my-tasks') || $('work-home')) {
            window.dispatchEvent(new Event('work:changed'));
            if ($('work-my-tasks') && !got.project) { window.history.pushState({ task: got.id }, '', base + '/my-tasks?task=' + got.id); window.dispatchEvent(new PopStateEvent('popstate')); }
            return;
          }
          window.location.assign(got.project ? pageUrl(got.project) + '?task=' + got.id : base + '/my-tasks?task=' + got.id);
        }).catch((err) => { m.ok.disabled = false; setText(m.msg, err && err.message ? err.message : genericError); });
      });
      newTask = { m: m, title: title, where: where };
    }
    // 프로젝트 고르개 — 「내 할 일」 + 사이드바에 서버가 그린 프로젝트(보는 사람의 검색 조건절 안). 넣을 수 있나는 만들기 창구가 다시 판정
    clear(newTask.where);
    newTask.where.appendChild(new Option('내 할 일', 'my_tasks'));
    const here = project && project.db && project.db.purpose === 'project' && project.db.can_add_rows ? project.db.page_id : null;
    for (const a of document.querySelectorAll('#nav-work-projects a[data-project-id][data-project-purpose="project"]')) {
      const pid = a.getAttribute('data-project-id');
      if (UUID_RE.test(pid)) { newTask.where.appendChild(new Option(a.textContent.trim(), pid, false, pid === here)); }
    }
    if (here && !Array.from(newTask.where.options).some((o) => o.value === here) && pageData && pageData.page) {
      newTask.where.appendChild(new Option(pageData.page.title, here, true, true));
    }
    setText(newTask.m.msg, '');
    newTask.m.dialog.showModal();
    newTask.title.focus();
  }

  let newProject = null;
  function openNewProject() {
    if (!canWrite()) { return; }
    closeDrawer();
    if (!newProject) {
      const m = modal('work-new-project', '새 프로젝트', '만들기');
      const name = el('input');
      name.type = 'text';
      name.maxLength = 200;
      name.required = true;
      name.autocomplete = 'off';
      field(m.form, '프로젝트 이름', name);
      const space = el('select');
      field(m.form, '어디에 만들까요', space);
      m.form.appendChild(el('p', 'help', '섹션(00. 준비 · 01. 진행 · 02. 마무리)과 목록 · 보드 · 타임라인 · 달력 보기가 함께 만들어져요. 개인 공간에 만들면 처음에는 나만 봐요.'));
      m.form.addEventListener('submit', (e) => {
        e.preventDefault();
        const t = name.value.trim();
        if (t === '') { name.focus(); return; }
        m.ok.disabled = true;
        setText(m.msg, '');
        const payload = { database: true, preset: 'project', title: t };
        if (/^\d+$/.test(space.value)) { payload.space_id = Number(space.value); }
        api('POST', '/api/pages', payload).then((r) => {
          m.ok.disabled = false;
          if (r.status !== 201) { setText(m.msg, failMessage(r)); return; }
          window.location.assign(pageUrl(r.data.page.id));
        }).catch(() => { m.ok.disabled = false; setText(m.msg, genericError); });
      });
      newProject = { m: m, name: name, space: space };
    }
    clear(newProject.space);
    spacesOnce().then((spaces) => {
      clear(newProject.space);
      for (const s of spaces) {
        if (s.can_write !== true) { continue; }
        newProject.space.appendChild(new Option(s.kind === 'team' ? s.name + ' (팀 공간)' : '내 공간', String(s.id)));
      }
    });
    setText(newProject.m.msg, '');
    newProject.m.dialog.showModal();
    newProject.name.focus();
  }

  let spacesPromise = null;
  function spacesOnce() {
    if (!spacesPromise) {
      spacesPromise = (typeof deps.loadSpaces === 'function' ? deps.loadSpaces() : api('GET', '/api/spaces').then((r) => (r.status === 200 ? r.data.spaces : [])))
        .then((s) => (Array.isArray(s) ? s : [])).catch(() => []);
    }
    return spacesPromise;
  }

  let newPortfolio = null;
  function openNewPortfolio(spaces) {
    closeDrawer();
    if (!newPortfolio) {
      const m = modal('work-new-portfolio', '새 포트폴리오', '만들기');
      const name = el('input');
      name.type = 'text';
      name.maxLength = 80;
      name.required = true;
      name.autocomplete = 'off';
      field(m.form, '포트폴리오 이름', name);
      const space = el('select');
      field(m.form, '팀 공간', space);
      m.form.appendChild(el('p', 'help', '포트폴리오는 팀이 함께 봐요. 넣은 프로젝트도 보는 사람마다 볼 수 있는 것만 보여요.'));
      m.form.addEventListener('submit', (e) => {
        e.preventDefault();
        const t = name.value.trim();
        if (t === '' || !/^\d+$/.test(space.value)) { return; }
        m.ok.disabled = true;
        api('POST', '/api/portfolios', { space: Number(space.value), name: t }).then((r) => {
          m.ok.disabled = false;
          if (r.status !== 201) { setText(m.msg, failMessage(r)); return; }
          window.location.assign(base + '/portfolios/' + r.data.portfolio.id);
        }).catch(() => { m.ok.disabled = false; setText(m.msg, genericError); });
      });
      newPortfolio = { m: m, name: name, space: space };
    }
    clear(newPortfolio.space);
    for (const s of spaces) { newPortfolio.space.appendChild(new Option(s.name, String(s.id))); }
    setText(newPortfolio.m.msg, '');
    newPortfolio.m.dialog.showModal();
    newPortfolio.name.focus();
  }

  /** 사이드바 「포트폴리오」 — 내 팀 공간마다 창구(`decidePortfolioRead`)가 준 목록 · 만들 수 있으면(`can_create`) 「＋ 포트폴리오」 */
  function fillPortfolioNav() {
    const nav = $('nav-work-portfolios');
    const title = $('nav-work-portfolios-title');
    if (!nav) { return Promise.resolve(); }
    return spacesOnce().then((spaces) => {
      const teams = spaces.filter((s) => s.kind === 'team');
      return Promise.all(teams.map((s) => api('GET', '/api/spaces/' + encodeURIComponent(String(s.id)) + '/portfolios').then((r) => (r.status === 200 ? r.data : null)).catch(() => null)));
    }).then((answers) => {
      clear(nav);
      const creatable = [];
      for (const a of answers) {
        if (!a) { continue; }
        if (a.can_create === true && canWrite()) { creatable.push(a.space); }
        for (const p of Array.isArray(a.portfolios) ? a.portfolios : []) {
          if (!UUID_RE.test(p.id)) { continue; }
          const link = el('a', 'nav__item');
          link.href = base + '/portfolios/' + p.id;
          link.setAttribute('data-portfolio-id', p.id);
          link.appendChild(icon('grid'));
          link.appendChild(el('span', null, p.name));
          nav.appendChild(link);
        }
      }
      if (creatable.length) {
        const b = el('button', 'nav__item');
        b.type = 'button';
        b.id = 'nav-work-new-portfolio';
        b.setAttribute('aria-haspopup', 'dialog');
        b.appendChild(icon('plus'));
        b.appendChild(el('span', null, '포트폴리오'));
        b.addEventListener('click', () => openNewPortfolio(creatable));
        nav.appendChild(b);
      }
      const any = nav.children.length > 0;
      show(nav, any);
      show(title, any);
      const here = $('work-portfolio');
      if (here) {
        const id = here.getAttribute('data-portfolio-id');
        for (const a of nav.querySelectorAll('a[data-portfolio-id]')) { if (a.getAttribute('data-portfolio-id') === id) { a.classList.add('is-active'); a.setAttribute('aria-current', 'page'); } }
      }
    });
  }

  function bindShell() {
    // 「＋ 새로 만들기」 메뉴의 작업 · 프로젝트(셸이 쓰기 자격일 때만 그렸다 — C 가 숨겨 둔 두 항목을 연다)
    const nt = $('nav-new-task');
    const np = $('nav-new-project');
    show(nt, !!nt);
    show(np, !!np);
    if (nt) { nt.addEventListener('click', openNewTask); }
    if (np) { np.addEventListener('click', openNewProject); }
    document.addEventListener('click', (e) => {
      const t = e.target instanceof Element ? e.target.closest('[data-work-new-task],[data-work-new-project]') : null;
      if (!t) { return; }
      e.preventDefault();
      if (t.hasAttribute('data-work-new-task')) { openNewTask(); } else { openNewProject(); }
    });
    // 사이드바 「프로젝트」 — 지금 보는 프로젝트 줄을 켠다(서버가 그린 줄 · id 만 견준다)
    const m = window.location.pathname.match(/\/p\/([0-9a-f-]{36})$/);
    if (m) {
      for (const a of document.querySelectorAll('#nav-work-projects a[data-project-id]')) {
        if (a.getAttribute('data-project-id') === m[1]) { a.classList.add('is-active'); a.setAttribute('aria-current', 'page'); }
      }
    }
    fillPortfolioNav();
  }

  return { initHome, initMyTasks, initPortfolio, bindShell, projectHooks, pageLoaded };
}
