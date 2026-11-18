import { drawCalendar, drawLegend, rangeFor } from './calendar.js?v=4a50ab9739e5';
import { renderDoc } from './doc-render.js?v=4a50ab9739e5';
import { createMentionInput } from './mention-input.js?v=4a50ab9739e5';
import { personText } from './cafe.js?v=4a50ab9739e5';
import { optionLabel } from './db-option-label.js?v=4a50ab9739e5';
import { repeatDialog } from './automations.js?v=4a50ab9739e5';
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
 *     업무함(홈 · 내 작업)도 같은 칸 하나(U3 · WK-26). 칸 폭은 CSS 변수 `--task-pane-w` 하나(CSSOM — 진행 폭 `--pct` 와 같은 예외).
 *
 * editor.js 가 `createWork(deps)` 로 한 번 만들고 화면 키에 따라 initHome · initMyTasks · initPortfolio 를, 페이지 화면에서는
 * database.js 에 넘기는 프로젝트 고리(`projectHooks()`)와 페이지 고리(`pageLoaded`)를 쓴다. 본문 편집은 editor.js 의 `mountEditor` 를 빌린다.
 */

const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/;
const DATE_RE = /^\d{4}-\d{2}-\d{2}$/;
/** Q:F-12 W1 — 「10월 12일」(작업 칸의 실제 시작 · 끝난 날 줄) */
function monthDayText(day) { return Number(day.slice(5, 7)) + '월 ' + Number(day.slice(8, 10)) + '일'; }
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

/* ════════════════ 업무함 합치기 (Q:F-12 U2 · DESIGN 2.20-A · WK-22 ①) ════════════════ */

/** 업무함의 「검토 요청 · 멘션」 묶음 — 「오늘」과 「이번 주」 사이(my-tasks 묶음 키와 겹치지 않는 화면 키) */
export const INBOX_REVIEW_KEY = 'review_mention';
const INBOX_REVIEW_NAME = '검토 요청 · 멘션';
/** briefing 줄 가운데 업무함에 잇는 사유 — 그 밖(기한 · 공지 · 체크 · 파일 …)은 잇지 않는다(기한은 my-tasks 가 이미 준다 · 공지는 홈 한 줄) */
const INBOX_MARKS = ['review', 'mention'];

/**
 * 두 창구의 답을 **잇기만** 하는 순수 함수(DOM 0 · 요청 0 — 단위 시험 대상). 화면은 거르지도 더하지도 않는다(0장 1).
 *   myTasks   `GET /api/my-tasks` 답 그대로 — buckets 순서(overdue → today → this_week → later → no_due)를 지킨다.
 *   briefing  `GET /api/briefing` 답(없거나 403 · 404 면 null) — top · missed · others 중 page_id 가 있고 사유(kind · reasons[].kind)에
 *             review · mention 이 있는 줄만 모은다. 같은 page_id 가 my-tasks 에 있으면 **그 줄에 표식만**(marks) 더하고, 없으면
 *             「검토 요청 · 멘션」 묶음(today 와 this_week 사이)에 한 줄 — briefing 안에 같은 page_id 가 둘이면 하나(표식은 합친다).
 *   favorites ★ 한 페이지 id(Set 또는 배열 — `/api/me/favorites` 의 답) — 각 묶음 안에서 ★ 먼저, 나머지는 창구 순서 그대로.
 *   today     날짜(Y-m-d) — 지금은 쓰지 않는다(마감 글자는 그리는 쪽이 만든다). 서명은 지시서 그대로 둔다.
 * 돌려주는 값: [{ key, name, late, more, items: [줄] }] — 빈 묶음도 남긴다(그 자리 추가 「오늘」 · 「기한 없음」). 줄 =
 *   { id, title, icon, status, due, project, doc_space, assigned_by, comments_n, sub_done, sub_total, fav, marks: [...], summary, from: 'tasks'|'briefing' }
 */
export function buildInbox(myTasks, briefing, favorites, today) {
  void today;
  const fav = favorites instanceof Set ? favorites : new Set(Array.isArray(favorites) ? favorites : []);
  const favFirst = (list) => list.filter((e) => e.fav).concat(list.filter((e) => !e.fav));
  const index = {};
  const groups = [];
  for (const b of (myTasks && Array.isArray(myTasks.buckets) ? myTasks.buckets : [])) {
    const items = (Array.isArray(b.items) ? b.items : []).map((it) => ({
      id: it.public_id, title: it.title, icon: it.icon || null, status: it.status, due: it.due, project: it.project || null,
      doc_space: it.doc_space || null, assigned_by: it.assigned_by || null, comments_n: it.comments_n, sub_done: it.sub_done,
      sub_total: it.sub_total, fav: fav.has(it.public_id), marks: [], summary: null, from: 'tasks'
    }));
    for (const e of items) { if (!index[e.id]) { index[e.id] = e; } }
    groups.push({ key: b.key, name: b.name, late: b.key === 'overdue', more: b.next_cursor || null, items: favFirst(items) });
  }
  const extra = [];
  const seen = {};
  const lists = briefing ? [briefing.top, briefing.missed, briefing.others] : [];
  for (const list of lists) {
    for (const it of (Array.isArray(list) ? list : [])) {
      if (!it || typeof it.page_id !== 'string' || it.page_id === '') { continue; }
      const kinds = [it.kind].concat(Array.isArray(it.reasons) ? it.reasons.map((r) => (r ? r.kind : null)) : []);
      const marks = INBOX_MARKS.filter((k) => kinds.indexOf(k) !== -1);
      if (!marks.length) { continue; }
      const target = index[it.page_id] || seen[it.page_id];
      if (target) {
        for (const m of marks) { if (target.marks.indexOf(m) === -1) { target.marks.push(m); } }
        continue;
      }
      const e = { id: it.page_id, title: it.title, icon: null, status: null, due: typeof it.due === 'string' ? it.due : null, project: null,
        doc_space: null, assigned_by: null, comments_n: 0, sub_done: 0, sub_total: 0, fav: fav.has(it.page_id), marks: marks.slice(),
        summary: typeof it.summary === 'string' && it.summary !== '' ? it.summary : null, from: 'briefing' };
      seen[it.page_id] = e;
      extra.push(e);
    }
  }
  let at = groups.findIndex((g) => g.key !== 'overdue' && g.key !== 'today');
  if (at === -1) { at = groups.length; }
  groups.splice(at, 0, { key: INBOX_REVIEW_KEY, name: INBOX_REVIEW_NAME, late: false, more: null, items: favFirst(extra) });
  return groups;
}

export function createWork(deps) {
  const { api, base, tboxBase, setText, show, clear, errorMessage, genericError, toLogin, statusLabels, personLabel, shortWhen, makeIcon } = deps;
  const canWrite = typeof deps.canWrite === 'function' ? deps.canWrite : () => false;
  const meName = typeof deps.meName === 'function' ? deps.meName : () => '';
  /* Q:F-12 U2 — 사이드바 즐겨찾기의 지금 함수(editor.js `navStar` · `favoriteIds` · `favoriteListeners`)를 빌린다 — ★ 토글 창구 · 목록 · 새로 받았을 때 알림.
     없으면(다른 화면 · 옛 판) ★ 는 표시만 한다(토글 0) */
  const favs = deps.favorites && typeof deps.favorites === 'object' ? deps.favorites : null;
  const favIds = () => (favs && typeof favs.ids === 'function' ? favs.ids() : new Set());
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
    // 카페 C2 (DESIGN 2.29) — 칸이 익명게시판 글을 열면 창구가 사람 객체를 이름표(「글쓴이」 · 「익명N」 · is_me)로 바꿔 준다 — 「(나)」를 붙인다
    if (person && person.anon === true) { return personText(person, personLabel); }
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
  /** 알림 줄(.msg) 한 줄 — 오류는 기본(--danger), 성공 안내만 `ok` 로 성공 모양(.msg--ok · DESIGN 2.21 「알림 색」 U3-4) */
  function say(node, text, ok) {
    setText(node, text);
    if (node && node.classList) { node.classList.toggle('msg--ok', !!ok && String(text || '') !== ''); }
  }
  /** Q:F-16 1차 — editor.js 가 만든 「템플릿으로 저장」 창 한 벌(창이 없는 화면 — 업무 홈 · 내 작업 — 이면 null) */
  function templateSaver() {
    const t = typeof deps.templateSave === 'function' ? deps.templateSave() : deps.templateSave;
    return t && typeof t.open === 'function' ? t : null;
  }
  /** Q:F-16 1차 — 반복 창 「만들 자리」 기본값(일반 템플릿일 때) = 지금 프로젝트. 프로젝트 화면이 아니면 null(반복 창 그대로) */
  function repeatParentOf() {
    if (!project || !project.db || typeof project.db.page_id !== 'string') { return null; }
    const t = pageData && pageData.page && typeof pageData.page.title === 'string' ? pageData.page.title : '';
    return { id: project.db.page_id, title: '이 프로젝트 · ' + t };
  }

  /* ════════════════ 작업 줄 (DESIGN 2.20) ════════════════ */

  /**
   * 작업 줄 하나 — `li.task-row`: 첫 칸(▸ · 완료 원 · 제목 · 보조 · 맡긴 표시) + 칸(프로젝트 또는 담당) + 마감 (+ 빈 ＋ 칸).
   * opts: kind('my'|'project'|'compact'|'pane'|'inbox' — inbox 는 inboxRow 갈래, U2) · today · canCheck · current · onOpen(item) · onCheck(item, done) · expand(true|false|null) · onExpand()
   */
  function taskRow(item, opts) {
    if (opts.kind === 'inbox') { return inboxRow(item, opts); }
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

  /* ════════════════ 업무함 줄 (DESIGN 2.20-A — taskRow 의 kind 'inbox' 갈래) ════════════════ */

  /** 업무함 마감 글자 — 「n일 지남」(--danger) · 「오늘」(--primary) · 「10/15」. 색만으로 가르지 않는다(글자 · 「지난 기한」 꼬리표) */
  function inboxDue(due, today) {
    const span = el('span', null);
    if (typeof due !== 'string' || !DATE_RE.test(due)) { return span; }
    if (due === today) { span.className = 'task-due--today'; span.textContent = '오늘'; return span; }
    if (due < today) { span.className = 'task-due--late'; span.textContent = daysBetween(due, today) + '일 지남'; return span; }
    span.textContent = Number(due.slice(5, 7)) + '/' + Number(due.slice(8, 10));
    return span;
  }

  /** ★ — 쓸 수 있으면(`can_community_write`) 사이드바 즐겨찾기의 같은 단추 함수(editor.js `navStar` — PUT/DELETE /api/pages/{id}/favorite ·
      끝나면 즐겨찾기 목록을 다시 받는다)를 빌려 모양 클래스만 바꾼다. 아니면(손님 · 빌릴 함수 없음) 표시만(★ 한 줄에 찬 별 · 누름 0) */
  function inboxStar(item) {
    if (canWrite() && favs && typeof favs.star === 'function') {
      const b = favs.star({ id: item.id, title: item.title, icon: item.icon || null });
      b.className = 'task-inbox__star';
      return b;
    }
    const s = el('span', 'task-inbox__star');
    s.setAttribute('aria-hidden', 'true');
    if (item.fav) { s.appendChild(icon('star-fill')); }
    return s;
  }

  /**
   * 업무함 한 줄 — `li.task-row.task-inbox__row`: 완료 원(누름은 줄 펼침과 따로 — 형제 단추) · ★ · `button.task-inbox__line`(줄 전체가 누름 자리 —
   * 출처 · 제목 600 + 보조 글 · 꼬리(댓글 · 하위 · 「@」 · 「검토」) · 마감). Tab 순서: 완료 원 → ★ → 줄.
   * opts: today · canCheck · expanded · onLine(item) · onDone(item, li, done)
   * 완료 원은 my-tasks 줄(작업)에만 — briefing 에서 온 줄(검토 · 멘션 — 작업이 아닐 수 있는 문서)은 빈 칸.
   */
  function inboxRow(item, opts) {
    const li = el('li', 'task-row task-inbox__row' + (item.status === 'done' ? ' task-row--done' : ''));
    li.setAttribute('data-task', item.id);
    if (opts.expanded) { li.setAttribute('aria-current', 'true'); }
    if (opts.canCheck && item.from === 'tasks') {
      const check = el('button', 'task-check');
      check.type = 'button';
      const paint = () => {
        const done = item.status === 'done';
        check.setAttribute('aria-pressed', done ? 'true' : 'false');
        check.setAttribute('aria-label', (done ? '완료 취소' : '완료 표시') + ' — ' + item.title);
      };
      paint();
      check.appendChild(icon('check'));
      check.addEventListener('click', (e) => {
        e.stopPropagation();
        const next = item.status !== 'done';
        check.disabled = true;
        setDone(item.id, next).then(() => {
          item.status = next ? 'done' : 'todo';
          li.classList.toggle('task-row--done', next);
          paint();
          if (opts.onDone) { opts.onDone(item, li, next); }
        }).catch((err) => setText(msgEl(), err && err.message ? err.message : genericError)).finally(() => { check.disabled = false; });
      });
      li.appendChild(check);
    } else {
      li.appendChild(el('span', 'task-row__spacer'));
    }
    li.appendChild(inboxStar(item));
    const line = el('button', 'task-inbox__line');
    line.type = 'button';
    line.setAttribute('aria-expanded', opts.expanded ? 'true' : 'false');
    // 출처 — 프로젝트(색 네모 + 이름) · 문서(공간) · briefing 줄은 「문서」(창구가 공간 이름을 주지 않는다 — 서버 0)
    const src = el('span', 'task-row__cell task-row__cell--meta');
    if (item.project) {
      src.appendChild(colorBox(item.project.color, 'sm'));
      src.appendChild(el('span', null, item.project.title));
    } else {
      src.appendChild(el('span', null, item.doc_space || '문서'));
    }
    line.appendChild(src);
    // 제목 + 보조 글(「○○ 님이 맡김」 · briefing summary) — 한 줄 줄임. 설명 미리보기는 없다(창구가 주지 않는다)
    const main = el('span', 'task-row__main');
    main.appendChild(el('span', 'task-row__title', (item.icon ? item.icon + ' ' : '') + item.title));
    const sub = item.assigned_by ? item.assigned_by + ' 님이 맡김' : item.summary;
    if (sub) { main.appendChild(el('span', 'task-row__aux', sub)); }
    line.appendChild(main);
    // 꼬리 — 댓글 n · 하위 n/m · 「@」(멘션) · 「검토」(검토 요청)
    const tail = el('span', 'task-row__aux');
    const parts = [];
    if (typeof item.comments_n === 'number' && item.comments_n > 0) { parts.push('댓글 ' + item.comments_n); }
    if (typeof item.sub_total === 'number' && item.sub_total > 0) { parts.push('하위 ' + item.sub_done + '/' + item.sub_total); }
    if (parts.length) { tail.appendChild(el('span', null, parts.join(' · '))); }
    if (item.marks.indexOf('mention') !== -1) { tail.appendChild(el('span', 'badge', '@')); }
    if (item.marks.indexOf('review') !== -1) { tail.appendChild(el('span', 'badge', '검토')); }
    line.appendChild(tail);
    const due = el('span', 'task-row__cell task-row__cell--due');
    due.appendChild(inboxDue(item.due, opts.today));
    line.appendChild(due);
    line.addEventListener('click', () => { if (opts.onLine) { opts.onLine(item); } });
    li.appendChild(line);
    return li;
  }

  /**
   * 업무함 한 벌(홈 · 내 작업 공용) — 꼬리표 · 줄 · 오른쪽 작업 상세 칸(`aside#task-pane` — 2.21 그대로) · ↓↑ · Esc.
   * Q:F-12 U3(WK-26 ② — DESIGN 2.20-A 「펼침」): 줄을 누르면 폭과 상관없이 **오른쪽 칸 하나**(프로젝트 · 보드 · 달력과 같은 자리 · 같은 createPane).
   * 줄 아래 펼침(U2 의 `div.task-inbox__pane`)은 없앴다 — 칸 폭은 2.21 「폭 조절」.
   * cfg: { root, ul, empty, emptyText, aside, limit, addKeys() → [묶음 키], onAdd(group, li), onMore(group, btn), tags() → bool, onChanged() }
   * draw(groups, today) 로 그린다. 묶음 가르기 · 합치기는 buildInbox(위)가 한다 — 이 함수는 그리기만.
   */
  function inboxView(cfg) {
    let groups = [];
    let today = seoulToday();
    const lineOf = (li) => (li ? li.querySelector(':scope > .task-inbox__line') : null);
    const rowOf = (id) => cfg.ul.querySelector('li.task-inbox__row[data-task="' + id + '"]');
    function mark(id) {
      for (const li of cfg.ul.querySelectorAll('li.task-inbox__row')) {
        const on = id !== null && li.getAttribute('data-task') === id;
        if (on) { li.setAttribute('aria-current', 'true'); } else { li.removeAttribute('aria-current'); }
        const ln = lineOf(li);
        if (ln) { ln.setAttribute('aria-expanded', on ? 'true' : 'false'); }
      }
    }
    const pane = createPane(cfg.aside, {
      onChanged: () => { if (cfg.onChanged) { cfg.onChanged(); } },
      onMarked: (id) => mark(id)
    });
    function draw(nextGroups, nextToday) {
      groups = nextGroups;
      today = nextToday || today;
      const focusId = document.activeElement && cfg.ul.contains(document.activeElement)
        ? (document.activeElement.closest('li[data-task]') || { getAttribute: () => null }).getAttribute('data-task') : null;
      clear(cfg.ul);
      const tags = cfg.tags ? cfg.tags() : true;
      const addKeys = cfg.addKeys ? cfg.addKeys() : [];
      const limit = cfg.limit || Infinity;
      let shown = 0;
      let total = 0;
      for (const g of groups) {
        total += g.items.length;
        const room = Math.max(0, limit - shown);
        const items = g.items.slice(0, room);
        const canAdd = addKeys.indexOf(g.key) !== -1;
        if (!items.length && !canAdd) { continue; }
        if (tags) {
          const tag = el('li', 'task-inbox__tag' + (g.late ? ' task-group--late' : ''), g.name + ' ' + g.items.length + (g.more ? '+' : ''));
          tag.setAttribute('aria-hidden', 'true');
          tag.setAttribute('data-bucket', g.key);
          cfg.ul.appendChild(tag);
        }
        for (const it of items) {
          cfg.ul.appendChild(taskRow(it, {
            kind: 'inbox', today: today, canCheck: canWrite(), expanded: pane.current() === it.id,
            onLine: (item) => {
              if (pane.current() === item.id) { pane.close({ push: true }); return; }
              pane.open(item.id, { push: true });
            },
            onDone: (item, li) => {
              const lines = [...cfg.ul.querySelectorAll('.task-inbox__line')];
              const at = lines.indexOf(lineOf(li));
              const next = lines[at + 1] || lines[at - 1];
              if (next) { next.focus(); }
            }
          }));
        }
        shown += items.length;
        if (g.more && cfg.onMore && limit === Infinity) {
          const li = el('li', 'task-list__actions');
          const moreBtn = textButton('더 보기', 'btn btn--quiet btn--small task-group__more');
          moreBtn.setAttribute('data-more-bucket', g.key);
          moreBtn.addEventListener('click', () => cfg.onMore(g, moreBtn));
          li.appendChild(moreBtn);
          cfg.ul.appendChild(li);
        }
        if (canAdd && cfg.onAdd) {
          const li = el('li', 'task-list__actions');
          const addBtn = textButton('작업 추가', 'btn btn--quiet btn--small', 'plus');
          addBtn.setAttribute('data-add-bucket', g.key);
          addBtn.addEventListener('click', () => cfg.onAdd(g, li, addBtn));
          li.appendChild(addBtn);
          cfg.ul.appendChild(li);
        }
      }
      // 열린 작업의 줄 표시(aria-current · aria-expanded)를 다시 그린 줄에 옮긴다 — 줄이 빠져도 칸은 그대로(프로젝트 화면과 같다)
      if (pane.current()) { mark(pane.current()); }
      if (focusId) {
        const ln = lineOf(rowOf(focusId));
        if (ln) { ln.focus({ preventScroll: true }); }
      }
      if (cfg.empty) {
        setText(cfg.empty, cfg.emptyText ? cfg.emptyText() : '');
        show(cfg.empty, total === 0 && addKeys.length === 0);
      }
      return { shown: shown, total: total };
    }
    // ↓↑ = 줄 이동 · Esc = 칸 닫기(목록 · 칸 안에서 — 열린 메뉴 · 입력 · 편집기 · 창 안에서는 그쪽이 먼저) · 초점은 그 줄로
    const onKey = (e) => {
      const t = e.target instanceof Element ? e.target : null;
      if (!t) { return; }
      if ((e.key === 'ArrowDown' || e.key === 'ArrowUp') && t.matches('.task-inbox__line')) {
        const lines = [...cfg.ul.querySelectorAll('.task-inbox__line')];
        const at = lines.indexOf(t);
        const next = lines[at + (e.key === 'ArrowDown' ? 1 : -1)];
        e.preventDefault();
        if (next) { next.focus(); }
        return;
      }
      if (e.key !== 'Escape' || e.isComposing || e.defaultPrevented || !pane.current()) { return; }
      if (document.querySelector('details[open].menu') || document.querySelector('dialog[open]')
        || t.closest('input, textarea, select, [contenteditable="true"], .editor-toolbar, [role="separator"]')) { return; }
      const id = pane.current();
      e.preventDefault();
      pane.close({ push: true });
      const ln = lineOf(rowOf(id));
      if (ln) { ln.focus(); }
    };
    cfg.root.addEventListener('keydown', onKey);
    if (cfg.aside) { cfg.aside.addEventListener('keydown', onKey); }
    return { pane: pane, draw: draw };
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

  /** 칸 폭 범위(DESIGN 2.21 「폭 조절」 — 400px ~ 화면 폭 70%, 목록이 480px 아래로 줄지 않게) · 기본 폭은 지금 CSS 의 clamp(400px, 34vw, 480px) 와 같은 값 */
  const PANE_MIN = 400;
  const PANE_STEP = 16;
  const LIST_MIN = 480;
  const viewW = () => document.documentElement.clientWidth || window.innerWidth;
  /** 위 끝 = min(화면 폭 70%, 목록 480px 이 남는 폭) — 목록 자리 = 보이는 폭 − 본문 왼쪽(사이드바) − 본문 여백 32 · 32 − 칸 */
  function paneMax() {
    const main = document.querySelector('.main');
    const left = main ? Math.max(0, Math.round(main.getBoundingClientRect().left)) : 0;
    return Math.max(PANE_MIN, Math.min(Math.round(window.innerWidth * 0.7), viewW() - left - 64 - LIST_MIN));
  }
  const paneDefault = () => Math.max(PANE_MIN, Math.min(480, Math.round(window.innerWidth * 0.34)));
  const paneKey = () => 'qaredi-task-pane-w:v1:' + meName();

  /**
   * U3 A — 칸 폭 손잡이 · 넓게 보기. 폭은 `<body>` 의 CSS 변수 `--task-pane-w` 하나(CSSOM — 인라인 style 은 이 변수뿐 ·
   * 기본 폭이면 지운다)이고 칸 폭 · 목록 여백 · 손잡이 자리를 CSS 가 같은 변수로 정한다(app.css). 저장은 이 브라우저 localStorage
   * (숫자 하나 = 사람이 고른 폭 — 아이디가 없으면 저장하지 않는다). 화면 폭 · 사이드바 접기가 바뀌면 고른 폭을 새 범위로 다시 묶는다(고른 값은 그대로).
   * 1200 미만에서는 CSS 가 손잡이 · 「넓게 보기」를 숨기고 칸이 목록을 대신한다(폭 조절 0).
   */
  function paneSizer(aside) {
    if (!aside || aside.tagName !== 'ASIDE') { return null; }
    let wanted = null;     // 사람이 고른 폭(null = 기본 폭)
    let before = null;     // 넓게 보기 전 고른 폭
    let wideBtn = null;    // 지금 칸의 「넓게 보기」(칸을 다시 그릴 때마다 새 단추)
    const handle = el('div', 'task-pane__resize');
    handle.setAttribute('role', 'separator');
    handle.setAttribute('aria-orientation', 'vertical');
    handle.setAttribute('aria-label', '작업 상세 폭');
    handle.tabIndex = 0;
    const clampW = (w) => Math.max(PANE_MIN, Math.min(paneMax(), Math.round(w)));
    const now = () => (wanted === null ? paneDefault() : clampW(wanted));
    function paint() {
      const w = now();
      if (wanted === null || w === paneDefault()) { body.style.removeProperty('--task-pane-w'); } else { body.style.setProperty('--task-pane-w', w + 'px'); }
      if (body.getAttribute('style') === '') { body.removeAttribute('style'); }
      handle.setAttribute('aria-valuemin', String(PANE_MIN));
      handle.setAttribute('aria-valuemax', String(paneMax()));
      handle.setAttribute('aria-valuenow', String(w));
      if (wideBtn) {
        const wide = w >= paneMax();
        wideBtn.setAttribute('aria-pressed', wide ? 'true' : 'false');
        wideBtn.setAttribute('aria-label', wide ? '넓게 보기 끄기' : '넓게 보기');
      }
    }
    function apply(next, save) {
      wanted = next === null ? null : clampW(next);
      if (wanted !== null && wanted === paneDefault()) { wanted = null; }
      if (save && meName() !== '') {
        try { if (wanted === null) { window.localStorage.removeItem(paneKey()); } else { window.localStorage.setItem(paneKey(), String(wanted)); } } catch (e) { /* 기억은 편의 */ }
      }
      paint();
    }
    // 처음 — 이 사람의 기억(숫자 하나 · 지금 범위 밖이면 범위 안으로 그리되 고른 값은 그대로 둔다)
    try {
      const saved = meName() !== '' ? Number.parseInt(window.localStorage.getItem(paneKey()) || '', 10) : NaN;
      if (Number.isFinite(saved) && saved >= PANE_MIN) { wanted = saved; }
    } catch (e) { /* 기억 없음 */ }
    paint();
    // 끌기 — 칸은 화면 오른쪽 끝(스크롤 막대 안쪽)에 붙어 있으니 폭 = 보이는 폭 − 누른 x
    let dragging = false;
    handle.addEventListener('pointerdown', (e) => {
      if (e.button !== 0) { return; }
      e.preventDefault();
      dragging = true;
      try { handle.setPointerCapture(e.pointerId); } catch (err) { /* 끌기는 그대로 */ }
      body.setAttribute('data-task-resizing', '');
    });
    handle.addEventListener('pointermove', (e) => {
      if (!dragging) { return; }
      apply(viewW() - e.clientX, false);
    });
    const endDrag = (e) => {
      if (!dragging) { return; }
      dragging = false;
      try { handle.releasePointerCapture(e.pointerId); } catch (err) { /* 이미 풀림 */ }
      body.removeAttribute('data-task-resizing');
      before = null;
      apply(wanted, true);
    };
    handle.addEventListener('pointerup', endDrag);
    handle.addEventListener('pointercancel', endDrag);
    // 키보드 — ←(왼쪽으로 = 넓게) · →(좁게) 16px · Home = 기본 폭
    handle.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
        e.preventDefault();
        before = null;
        apply(now() + (e.key === 'ArrowLeft' ? PANE_STEP : -PANE_STEP), true);
      } else if (e.key === 'Home') {
        e.preventDefault();
        before = null;
        apply(null, true);
      }
    });
    handle.addEventListener('dblclick', () => { before = null; apply(null, true); });
    // 화면 폭 · 사이드바 접기(☰ — `<body data-sidebar>`)가 바뀌면 같은 고른 폭을 새 범위로 다시 그린다
    window.addEventListener('resize', paint);
    try { new MutationObserver(paint).observe(body, { attributes: true, attributeFilter: ['data-sidebar'] }); } catch (e) { /* 없으면 다음 resize 때 */ }
    return {
      handle: handle,
      /** 머리 줄 「넓게 보기」 단추(칸을 다시 그릴 때마다 새로 — 상태는 paint 가 칠한다). 켜면 위 끝까지 · 끄면 앞 폭 */
      wideButton: () => {
        const b = iconButton('chevron', '넓게 보기');
        b.setAttribute('data-pane-wide', '');
        b.addEventListener('click', () => {
          if (now() >= paneMax()) { const back = before; before = null; apply(back, true); return; }
          before = wanted;
          apply(paneMax(), true);
        });
        wideBtn = b;
        paint();
        return b;
      }
    };
  }

  /**
   * `aside.task-pane` 하나를 다룬다. 줄을 누르면 칸만 바뀌고 주소에 `?task=` — 뒤로 가기로 앞 작업 · 닫힘(popstate).
   * ctx: { onChanged(id) — 목록 다시 그리기, onMarked(id|null) — 고른 줄 표시 }
   *
   * Q:F-12 U3(WK-26 ②) — 자리는 `aside#task-pane` 하나(프로젝트 · 업무함 · 보드 · 달력 모두). U2 의 「줄 아래 펼침」 갈래(함수 자리)는 없앴다.
   * 안쪽(머리 줄 · 제목 · 속성 · 설명 · 하위 작업 · 활동 · 댓글)은 **같은 draw 한 벌**이다(두 벌 0).
   * U3 A(DESIGN 2.21 「폭 조절 · 넓게 보기」) — 왼쪽 경계 손잡이 `.task-pane__resize`(끌기 · ←/→ 16px · Home · 두 번 누르기 = 기본 폭) ·
   * 머리 줄 「넓게 보기」(aria-pressed — 켜면 화면 폭 70%, 끄면 앞 폭). 폭은 CSS 변수 `--task-pane-w` 하나(CSSOM — `<body>` 에만 ·
   * 기본 폭이면 지운다) · 기억은 localStorage `qaredi-task-pane-w:v1:<아이디>`(숫자 하나 · 서버 0). 1200 미만은 CSS 가 손잡이 · 단추를 숨긴다.
   *
   * Q:F-12 U4-2(DESIGN 2.25 「작업 전체 화면」) — `ctx.mode === 'full'` 이면 같은 draw 한 벌을 페이지 화면의 `section#task-full` 에 본문 폭으로 그린다:
   * 주소 `?task=` 0 · `data-task-open` 0 · 폭 손잡이 · 넓게 보기 · 「전체 화면으로 열기」 · 닫기 ✕ 0 · 머리 줄 맨 앞 「‹ 프로젝트」(그 프로젝트 주소) ·
   * 더보기에 「문서로 보기」(`ctx.onAsDoc` — 옛 일반 문서 모양) · 하위 작업 › 는 그 작업의 전체 화면 주소 · 지우면 그 프로젝트로.
   * 댓글 칸은 전체 화면 댓글과 같은 멘션 입력 부품(`createMentionInput` — U4-4, 두 벌 0).
   */
  function createPane(paneEl, ctx) {
    let currentId = null;
    let loadSeq = 0;
    let editor = null;
    let editorHasError = false;
    let attWatch = null;   // 카페 C4 — 칸에 그린 문서의 검사 중 첨부 다시 묻기(editor.js watchAttachments 한 벌)
    let lastData = null;   // Q:F-16 1차 — 마지막으로 그린 창구 답 {id, data}(꺾쇠 메뉴 「지금 작업을 템플릿으로 저장」)
    const full = ctx.mode === 'full';
    const sizer = full ? null : paneSizer(paneEl);
    let projectHref = base + '/';

    function urlFor(id) {
      if (full) { return pageUrl(id); }
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
    /** 칸을 비운다(폭 손잡이는 남긴다 — 안쪽을 다시 그릴 때마다) */
    function fresh() {
      clear(paneEl);
      if (sizer) { paneEl.appendChild(sizer.handle); }
    }
    /** 칸을 걷는다(편집 중이면 저장을 마치고 걷는다) — 닫을 때 */
    function release() {
      if (attWatch) { attWatch.stop(); attWatch = null; }
      if (editor) { editor.flush().catch(() => {}); editor.destroy(); editor = null; }
      if (paneEl) { show(paneEl, false); clear(paneEl); }
      if (!full) { body.removeAttribute('data-task-open'); }
      lastData = null;
    }
    /** U4-2 — 전체 화면: 이미 받은 페이지 답(editor.js 가 넘긴 것)으로 곧바로 그린다(창구를 한 번 더 부르지 않는다) */
    function showFull(id, data) {
      if (!UUID_RE.test(id) || !paneEl) { return; }
      currentId = id;
      show(paneEl, true);
      fresh();
      draw(id, data, false);
    }
    function open(id, opts) {
      if (!UUID_RE.test(id) || !paneEl) { return; }
      if (full) { window.location.assign(pageUrl(id)); return; }
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
      if (full) { window.location.assign(projectHref); return; }
      if (opts && opts.push && currentId !== null) {
        try { window.history.pushState({ task: null }, '', urlFor(null)); } catch (e) { /* 편의 */ }
      }
      release();
      currentId = null;
      ctx.onMarked(null);
    }
    if (!full) {
      window.addEventListener('popstate', () => {
        const want = taskFromUrl();
        if (want) { open(want, { push: false }); } else if (currentId !== null) { close({ push: false }); }
      });
    }

    function load(id, focusHead) {
      const seq = ++loadSeq;
      return api('GET', '/api/pages/' + id).then((r) => {
        if (seq !== loadSeq || id !== currentId) { return; }
        fresh();
        if (r.status === 401) { toLogin(); return; }
        if (r.status !== 200) {
          const head = el('div', 'task-pane__head');
          if (!full) {
            const back = textButton('‹ 목록', 'btn btn--quiet btn--small task-pane__back');
            back.addEventListener('click', () => close({ push: true }));
            head.appendChild(back);
            const tools = el('span', 'task-pane__tools');
            const x = iconButton('x', '닫기');
            x.addEventListener('click', () => close({ push: true }));
            tools.appendChild(x);
            head.appendChild(tools);
          }
          paneEl.appendChild(head);
          paneEl.appendChild(el('p', 'msg', errorMessage(r.data)));
          return;
        }
        draw(id, r.data, focusHead);
      }).catch(() => { if (seq === loadSeq) { fresh(); paneEl.appendChild(el('p', 'msg', genericError)); } });
    }

    function changed(id) {
      if (ctx.onChanged) { ctx.onChanged(id); }
      if (id === currentId) { load(id, false); }
    }

    function patchProps(id, payload, msg) {
      say(msg, '');
      return api('PATCH', '/api/pages/' + id + '/properties', payload).then((r) => {
        if (r.status !== 200) { say(msg, failMessage(r)); return false; }
        changed(id);
        return true;
      }).catch(() => { say(msg, genericError); return false; });
    }

    function draw(id, data, focusHead) {
      lastData = { id: id, data: data };
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

      /* 머리 줄 — 「‹ 목록」(좁은 폭) · 완료 표시 · 링크 복사 · 전체 화면으로 열기 · 더보기 · 닫기
         U4-2 전체 화면: 「‹ 프로젝트」(그 프로젝트 주소 — 경로 줄의 바로 위 칸) · 완료 표시 · 링크 복사 · 더보기(+ 「문서로 보기」) */
      const head = el('div', 'task-pane__head');
      const crumbsHead = Array.isArray(data.breadcrumb) ? data.breadcrumb : [];
      // page.parent는 서버가 읽기 권한을 확인한 바로 위 항목이다(숨겨진 조상은 null).
      const parent = page.parent && UUID_RE.test(String(page.parent.id)) && page.parent.id !== id ? page.parent : null;
      const parentTask = !full && !data.row && parent;
      if (full) {
        const proj = data.row && crumbsHead.length >= 2 ? crumbsHead[crumbsHead.length - 2] : null;
        projectHref = proj && UUID_RE.test(String(proj.id)) ? pageUrl(proj.id) : base + '/';
        const up = el('a', 'btn btn--quiet btn--small', '‹ 프로젝트');
        up.href = projectHref;
        up.setAttribute('data-task-up', '');
        if (proj && proj.title) { up.setAttribute('aria-label', '프로젝트 「' + proj.title + '」로'); }
        head.appendChild(up);
      } else {
        const back = textButton('‹ 목록', 'btn btn--quiet btn--small task-pane__back');
        back.addEventListener('click', () => close({ push: true }));
        head.appendChild(back);
      }
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
        // U3-4(DESIGN 2.21 「알림 색」) — 성공 안내는 성공 모양(.msg--ok), 오류만 --danger
        const done = () => say(msg, '링크를 복사했어요.', true);
        if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(link).then(done).catch(() => say(msg, link, true)); }
        else { say(msg, link, true); }
      });
      tools.appendChild(copy);
      // U3 A — 넓게 보기(≥1200 에서만 보인다 — CSS). 켜면 화면 폭 70%, 끄면 앞 폭
      if (sizer) { tools.appendChild(sizer.wideButton()); }
      if (!full) {
        const fullLink = el('a', 'btn btn--icon btn--plain');
        fullLink.href = pageUrl(id);
        fullLink.setAttribute('aria-label', '전체 화면으로 열기');
        fullLink.appendChild(icon('files'));
        tools.appendChild(fullLink);
      }
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
      // Q:F-16 1차(DESIGN 2.38 「작업 상세 더보기」) — 페이지 더보기와 같은 창 한 벌. 보일지는 창구 답 template_scopes 뿐(화면이 범위를 따지지 않는다)
      const tplItem = el('button', 'menu__item', '템플릿으로 저장');
      tplItem.type = 'button';
      tplItem.setAttribute('aria-haspopup', 'dialog');
      tplItem.setAttribute('data-task-save-template', '');
      tplItem.hidden = !(w && Array.isArray(data.template_scopes) && data.template_scopes.length > 0 && templateSaver() !== null);
      tplItem.addEventListener('click', () => { const saver = templateSaver(); if (saver) { saver.open(id, data, { repeatParent: repeatParentOf(), onSaved: pickerForget }); } });
      const sep = el('hr', 'menu__sep');
      const delItem = el('button', 'menu__item menu__item--danger', '지우기');
      delItem.type = 'button';
      delItem.hidden = !canManage;
      list.append(titleItem, editItem, subItem, tplItem);
      // U4-2 — 전체 화면의 「문서로 보기」(프로젝트 메뉴와 같은 방식 — 옛 일반 문서 모양 · 볼 수 있는 사람 누구나)
      if (full && typeof ctx.onAsDoc === 'function') {
        const asDocItem = el('button', 'menu__item');
        asDocItem.type = 'button';
        asDocItem.setAttribute('data-task-as-doc', '');
        asDocItem.append(icon('files'), el('span', null, '문서로 보기'));
        asDocItem.addEventListener('click', () => { more.open = false; ctx.onAsDoc(); });
        list.appendChild(asDocItem);
      }
      list.append(sep, delItem);
      more.appendChild(list);
      tools.appendChild(more);
      if (!full) {
        const x = iconButton('x', '닫기');
        x.addEventListener('click', () => close({ push: true }));
        tools.appendChild(x);
      }
      head.appendChild(tools);
      paneEl.appendChild(head);

      if (parentTask) {
        const nav = el('nav', 'task-pane__parent');
        nav.setAttribute('aria-label', '상위 항목');
        const up = el('a', 'btn btn--quiet btn--small');
        const parentTitle = parent.title || '제목 없는 페이지';
        up.href = urlFor(parent.id);
        up.setAttribute('data-task-parent', '');
        up.setAttribute('aria-label', '상위 항목 「' + parentTitle + '」로 돌아가기');
        up.title = parentTitle;
        up.append(el('span', null, '‹ 상위 항목'), el('span', 'task-pane__parent-title', parentTitle));
        let navigating = false;
        up.addEventListener('click', async (e) => {
          if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) { return; }
          e.preventDefault();
          if (navigating) { return; }
          navigating = true;
          up.setAttribute('aria-disabled', 'true');
          try {
            if (editor) {
              await editor.flush();
              if (editorHasError) { return; }
            }
            if (currentId !== id || !up.isConnected) { return; }
            open(parent.id, { push: true });
            if (!full) { paneEl.scrollTop = 0; }
          } catch (error) {
            say(msg, genericError);
          } finally {
            navigating = false;
            up.removeAttribute('aria-disabled');
          }
        });
        nav.appendChild(up);
        paneEl.appendChild(nav);
      }

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
              if (r.status !== 200) { input.replaceWith(title); say(msg, failMessage(r)); return; }
              changed(id);
            }).catch(() => { input.replaceWith(title); say(msg, genericError); });
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
      /* Q:F-12 W1 (DESIGN 2.34 · 결정 WK-29) — 「마감」 앞 「시작 예정」(같은 모양 · 비우기 가능) + 읽기 줄 둘:
         실제 시작(처음 「진행 중」으로 바꾼 날 — 서버 계산) · 끝난 날(끝났을 때만). 시작 예정 > 마감이면 칸 아래 알림(서버 문구 그대로) */
      const ddStart = addProp('시작 예정');
      const startMsg = el('p', 'msg');
      startMsg.setAttribute('role', 'alert');
      startMsg.setAttribute('aria-live', 'polite');
      if (canEdit) {
        const input = el('input');
        input.type = 'date';
        input.value = typeof data.start === 'string' ? data.start : '';
        input.setAttribute('aria-label', '시작 예정 날짜');
        input.addEventListener('change', () => {
          patchProps(id, { start: input.value === '' ? null : input.value }, startMsg).then((ok) => {
            if (!ok) { input.value = typeof data.start === 'string' ? data.start : ''; }
          });
        });
        ddStart.appendChild(input);
      } else {
        ddStart.appendChild(el('span', typeof data.start === 'string' ? null : 'muted', typeof data.start === 'string' ? data.start : '없음'));
      }
      ddStart.appendChild(el('p', 'help task-pane__started',
        typeof data.started === 'string' && DATE_RE.test(data.started) ? '실제 시작 ' + monthDayText(data.started) + ' — 진행 중으로 바꾼 날' : '아직 시작 전'));
      if (typeof data.finished === 'string' && DATE_RE.test(data.finished)) {
        ddStart.appendChild(el('p', 'help task-pane__finished', '끝난 날 ' + monthDayText(data.finished)));
      }
      ddStart.appendChild(startMsg);
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
        if (typeof section === 'string' && section !== '') {
          const prop = (data.row.schema || []).find((p) => p.key === 'section');
          ddProject.appendChild(el('span', 'muted', '· ' + optionLabel(prop, section)));
        }
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

      /* 설명 — 블록 본문(읽기 기본 · 누르면 편집 — U1-b 2026-10-08, DESIGN 2.21 「누르면 편집」). 고칠 수 있으면(can_edit) 설명 칸을
         누르거나(링크 · 단추 · 입력 · 임베드 · 접기 머리를 누른 것은 제 동작) 칸에 초점을 두고 Enter → 「본문 고치기」와 같은 함수 하나 */
      if (editor) {
        // 편집 중에 칸을 다시 그리면(하위 작업 · 상태 저장 뒤) 옛 편집기는 저장을 마치고 걷는다 — 다시 누를 수 있게
        const stale = editor;
        editor = null;
        stale.flush().catch(() => {});
        stale.destroy();
      }
      const docSec = el('section', 'task-pane__section');
      docSec.appendChild(el('h3', 'task-pane__section-head', '설명'));
      const docBox = el('div', 'task-pane__doc');
      const docEditable = canEdit && typeof deps.mountEditor === 'function';
      const setDocEditable = (on) => {
        docBox.classList.toggle('task-pane__doc--editable', on);
        if (on) {
          docBox.tabIndex = 0;
          docBox.setAttribute('role', 'group');
          docBox.setAttribute('aria-label', '설명 — Enter 로 고치기');
        } else {
          docBox.removeAttribute('tabindex');
          docBox.removeAttribute('role');
          docBox.removeAttribute('aria-label');
        }
      };
      // U3 C — 문서 전용 첨부(`att:`)의 이름 지도(창구가 받을 수 있는 사람에게만 준 것)와 그 문서 id — 렌더러가 첨부 창구 주소를 만든다
      const readCtx = {
        child_titles: data.child_titles || {}, link_titles: data.link_titles || {}, file_titles: data.file_titles || {},
        attachment_titles: data.attachment_titles && typeof data.attachment_titles === 'object' ? data.attachment_titles : {}, attachment_page: id,
        synced: data.synced || {}, embeds: data.embeds || { hosts: [], frames: false }, base: base, tbox_base: tboxBase,
        mention_labels: data.mention_labels || {}, db_views: data.db_views || {}, buttons: {}
      };
      const drawDoc = () => {
        clear(docBox);
        const blocks = page.doc && Array.isArray(page.doc.blocks) ? page.doc.blocks : [];
        const empty = () => docBox.appendChild(el('p', 'muted', docEditable ? '누르면 설명을 적을 수 있어요.' : '설명이 없어요.'));
        if (blocks.length === 0) { empty(); return; }
        let node = null;
        try { node = renderDoc(page.doc, readCtx); } catch (e) { docBox.appendChild(el('p', 'muted', '본문을 그릴 수 없어요 — 전체 화면으로 열어 보세요.')); return; }
        // 빈 문단 하나뿐인 본문(새로 만든 작업)은 빈 상자 대신 안내 한 줄
        if (node.textContent.trim() === '' && !node.querySelector('img, table, iframe, hr, a, input')) { empty(); return; }
        docBox.appendChild(node);
      };
      drawDoc();
      setDocEditable(docEditable);
      docSec.appendChild(docBox);
      /* 카페 C4 (DESIGN 2.26 「검사 중 미리보기」 ②③) — 이 문서의 검사 중 첨부를 다시 묻는다(전체 화면과 같은 함수). 끝나면 편집 중이면 그 그림 부품만,
         읽기면 설명을 다시 그리고 「파일 첨부」 줄의 배지도 고친다. 칸을 다시 그리거나 닫으면 멈춘다 */
      if (attWatch) { attWatch.stop(); attWatch = null; }
      const attRows = new Map();
      if (typeof deps.watchAttachments === 'function') {
        const mine = deps.watchAttachments({
          pageId: () => id,
          titles: () => readCtx.attachment_titles,
          onChange: (ids) => {
            if (!docBox.isConnected) { mine.stop(); return; }
            if (typeof deps.redrawAttachments === 'function') { deps.redrawAttachments(ids); }
            if (!editor) { drawDoc(); }
            for (const f of ids) { const li = attRows.get(f); if (li) { drawAttRow(li, f); } }
          }
        });
        attWatch = mine;
        mine.kick();
      }
      const editState = el('span', 'badge');
      editState.hidden = true;
      docSec.firstChild.appendChild(editState);
      // 「본문 고치기」(더보기) · 설명 누르기 · 설명에서 Enter — 세 길이 같은 일을 한다
      const startDocEdit = () => {
        if (editor || !docEditable || !docBox.isConnected) { return; }
        more.open = false;
        setDocEditable(false);
        clear(docBox);
        const mount = el('div', 'panel');
        docBox.appendChild(mount);
        const endBtn = textButton('편집 마침', 'btn btn--quiet btn--small');
        docSec.firstChild.appendChild(endBtn);
        say(msg, '');
        editorHasError = false;
        editor = deps.mountEditor(mount, {
          pageId: id, doc: page.doc, version: page.doc_version, titles: readCtx,
          onState: (t) => { setText(editState, t); show(editState, t !== ''); },
          onSaved: (doc, version) => { editorHasError = false; page.doc = doc; page.doc_version = version; if (attWatch) { attWatch.kick(true); } },
          onUploaded: () => { if (attWatch) { attWatch.kick(); } },
          onError: (t) => { editorHasError = true; say(msg, t); }
        });
        if (!editor) { drawDoc(); endBtn.remove(); setDocEditable(true); return; }
        editor.focus();
        const mine = editor;
        endBtn.addEventListener('click', () => {
          if (editor !== mine) { return; }
          editor = null;
          mine.flush().finally(() => {
            mine.destroy();
            endBtn.remove();
            show(editState, false);
            drawDoc();
            setDocEditable(true);
            if (docBox.isConnected) { docBox.focus({ preventScroll: true }); }
            if (ctx.onChanged) { ctx.onChanged(id); }
          });
        });
      };
      editItem.addEventListener('click', startDocEdit);
      if (docEditable) {
        docBox.addEventListener('click', (e) => {
          if (!docBox.classList.contains('task-pane__doc--editable')) { return; }
          const hit = e.target instanceof Element ? e.target.closest('a, button, input, textarea, select, iframe, label, summary, [contenteditable]') : null;
          if (hit && docBox.contains(hit)) { return; }
          // 끌어서 글자를 고른 뒤의 누름은 편집으로 들어가지 않는다(복사하려던 것)
          const sel = typeof window.getSelection === 'function' ? window.getSelection() : null;
          if (sel && String(sel) !== '') { return; }
          startDocEdit();
        });
        docBox.addEventListener('keydown', (e) => {
          if (e.key !== 'Enter' || e.isComposing || e.target !== docBox || !docBox.classList.contains('task-pane__doc--editable')) { return; }
          e.preventDefault();
          startDocEdit();
        });
      }
      paneEl.appendChild(docSec);

      // Q:F-17: the server supplies availability and the action URL for project tasks only.
      if (data.whiteboard) {
        const wb = data.whiteboard;
        const section = el('section', 'task-pane__section');
        section.appendChild(el('h3', 'task-pane__label', '회의 그림판'));
        if (wb.exists || wb.can_create) {
          const link = el('a', 'btn btn--quiet btn--small', wb.exists ? '회의 그림판 열기 · 판 ' + wb.version_no : '회의 그림판 만들기');
          link.href = wb.url;
          section.appendChild(link);
        } else { section.appendChild(el('p', 'muted', '아직 회의 그림판이 없어요.')); }
        paneEl.appendChild(section);
      }

      /* 하위 작업(WK-6 ① — 하위 페이지 = 하위 작업). 상태 · 기한은 그 공간 트리(보는 사람이 볼 수 있는 것만)에서.
         U1-c(WK-18 A, 2026-10-08 — DESIGN 2.21 「누르면 편집」): 고칠 수 있는 줄(부모 can_edit · 트리에 보이는 하위 작업)은 제목이 단추 —
         누르거나 Enter → 그 자리 입력(Enter 저장 · Esc 되돌림 · 떠나면 저장 · 빈 값 · 같은 값은 저장 없이 되돌림). 저장은 상세 칸 큰 제목과
         같은 창구(`PATCH /api/pages/{하위 작업} {doc_version, title}` — 판 번호는 그 하위 작업을 GET 으로 읽어서)이고 서버가 하위 작업마다
         다시 판정한다. 고칠 수 없으면 제목은 글자. 줄 끝 › = 그 하위 작업 열기(주소 ?task=) · 목록 끝 「＋ 하위 작업 추가」(고칠 수 있을 때) */
      const subSec = el('section', 'task-pane__section');
      const subHead = el('h3', 'task-pane__section-head', '하위 작업');
      const subCount = el('span', 'task-group__count');
      subHead.appendChild(subCount);
      subSec.appendChild(subHead);
      const subList = el('ul', 'task-list__rows');
      subSec.appendChild(subList);
      const children = Array.isArray(data.children) ? data.children : [];
      // 하위 작업 제목 그 자리 고치기 — 상세 칸 큰 제목(startTitle)과 같은 순서 · 같은 저장 창구
      const startSubTitle = (c, btn) => {
        if (!btn.isConnected) { return; }
        const input = el('input', 'task-add__input');
        input.type = 'text';
        input.maxLength = 200;   // 페이지 제목 상한 = 서버 SaveDoc.php 의 iconv_strlen(title) > 200 거부
        input.autocomplete = 'off';
        input.value = c.title || '';
        input.setAttribute('aria-label', '하위 작업 제목');
        btn.replaceWith(input);
        input.focus();
        let ended = false;
        const back = (focus) => {
          if (input.isConnected) { input.replaceWith(btn); }
          if (focus && btn.isConnected) { btn.focus(); }
        };
        const end = (save, byKey) => {
          if (ended) { return; }
          ended = true;
          const next = input.value.trim();
          if (!save || next === '' || next === c.title) { back(byKey); return; }
          input.disabled = true;
          say(msg, '');
          api('GET', '/api/pages/' + c.id).then((g) => (g.status !== 200 ? g
            : api('PATCH', '/api/pages/' + c.id, { doc_version: g.data.page.doc_version, title: next }))).then((r) => {
            if (r.status !== 200) { back(byKey); say(msg, failMessage(r)); return; }
            forgetTree(data.space ? data.space.id : null);
            // 설명을 고치던 중이면 그 저장을 먼저 마친 뒤 다시 그린다(다시 읽은 본문이 옛 판이 되지 않게)
            Promise.resolve(editor ? editor.flush() : null).catch(() => {}).then(() => changed(id));
          }).catch(() => { back(byKey); say(msg, genericError); });
        };
        input.addEventListener('keydown', (e) => {
          if (e.key === 'Enter' && !e.isComposing) { e.preventDefault(); end(true, true); }
          if (e.key === 'Escape') { e.preventDefault(); end(false, true); }
        });
        input.addEventListener('blur', () => end(true, false));
      };
      const drawSubs = (tree) => {
        clear(subList);
        const byId = {};
        for (const t of tree || []) { byId[t.id] = t; }
        let done = 0;
        for (const c of children) {
          const t = byId[c.id] || {};
          const item = { id: c.id, title: c.title, icon: c.icon, status: t.status || null, due: t.due || null, assignee: t.assignee || null };
          if (item.status === 'done') { done += 1; }
          const editable = canEdit && !!byId[c.id];
          const row = taskRow(item, {
            kind: 'pane', today: today, canCheck: editable,
            onCheck: (it, d) => setDone(it.id, d).then(() => changed(id)).catch((e) => say(msg, e.message))
          });
          // 제목 — 고칠 수 있으면 단추(글자 모양), 아니면 글자. 여는 길은 줄 끝 › 하나
          const label = (item.icon ? item.icon + ' ' : '') + item.title;
          let head;
          if (editable) {
            head = el('button', 'task-row__title', label);
            head.type = 'button';
            head.setAttribute('aria-label', '제목 고치기 — ' + item.title);
            head.addEventListener('click', () => startSubTitle(c, head));
          } else {
            head = el('span', 'task-row__title', label);
          }
          const link = row.querySelector('a.task-row__title');
          if (link) { link.replaceWith(head); }
          const go = el('a', 'btn btn--icon btn--plain');
          go.href = urlFor(c.id);
          go.setAttribute('aria-label', '하위 작업 열기');
          go.appendChild(icon('chevron'));
          go.addEventListener('click', (e) => {
            if (full || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) { return; }
            e.preventDefault();
            open(c.id, { push: true });
          });
          row.appendChild(go);
          subList.appendChild(row);
        }
        setText(subCount, children.length ? done + '/' + children.length : '');
        if (!children.length) { subList.appendChild(el('li', 'muted', '하위 작업이 없어요.')); }
      };
      drawSubs([]);
      if (children.length && data.space) {
        treeOf(data.space.id).then(drawSubs).catch(() => {});
      }
      // 목록 끝 「＋ 하위 작업 추가」(U1-c) — 머리 ＋ · 더보기 「하위 작업 더하기」와 같은 그 자리 입력 줄을 연다(그 자리에 입력 줄이 선다)
      let addBtn = null;
      const addSub = () => {
        subItem.closest('details').open = false;
        const already = subSec.querySelector('.task-add__input');
        if (already) { already.focus(); return; }
        if (addBtn) { addBtn.hidden = true; }
        addLine(subSec, '하위 작업 이름', (t) => api('POST', '/api/pages', { parent_id: id, title: t }).then((r) => {
          if (r.status !== 201) { throw new Error(failMessage(r)); }
          return api('PATCH', '/api/pages/' + r.data.page.id + '/properties', { status: 'todo' });
        }), (savedOne) => { if (savedOne) { forgetTree(data.space ? data.space.id : null); } load(id, false); });
      };
      if (canEdit) {
        const plus = iconButton('plus', '하위 작업 더하기');
        plus.addEventListener('click', addSub);
        subHead.appendChild(plus);
        addBtn = textButton('＋ 하위 작업 추가', 'btn btn--quiet btn--small');
        addBtn.addEventListener('click', addSub);
        subSec.appendChild(addBtn);
      }
      subItem.addEventListener('click', addSub);
      paneEl.appendChild(subSec);

      /** 「파일 첨부」 한 줄(문서 전용 첨부) — 검사가 끝난 것은 받기 링크, 아니면 이름 + 배지. 검사가 끝나면(C4 다시 묻기) 같은 함수로 다시 그린다 */
      function drawAttRow(li, f) {
        clear(li);
        const ent = readCtx.attachment_titles[f] || {};
        if (ent.scan_status === 'clean') {
          const a = el('a', null, ent.name || '파일');
          a.href = base + '/api/pages/' + id + '/attachments/' + f + '/download';
          li.appendChild(a);
        } else {
          li.appendChild(el('span', null, ent.name || '파일'));
          li.appendChild(el('span', 'badge', ent.scan_status === 'pending' ? '검사 중' : '받을 수 없음'));
        }
      }

      /* 파일 첨부 — 본문에 붙인 자료실 파일(보는 사람이 자료실에서 볼 수 있는 것만 창구가 준다) + 이 문서 전용 첨부
         (U3 C — `attachment_titles`: 창구가 이 문서의 첨부를 받을 수 있는 사람에게만 준 것). 검사가 끝나지 않은 첨부는 이름 + 배지(받기 0) */
      const files = data.file_titles && typeof data.file_titles === 'object' ? Object.keys(data.file_titles).filter((k) => UUID_RE.test(k)) : [];
      const atts = Object.keys(readCtx.attachment_titles).filter((k) => UUID_RE.test(k));
      if (files.length || atts.length) {
        const fileSec = el('section', 'task-pane__section');
        fileSec.appendChild(el('h3', 'task-pane__section-head', '파일 첨부 ' + (files.length + atts.length)));
        const ul = el('ul', 'task-list__rows');
        for (const f of files) {
          const li = el('li', 'task-event');
          const a = el('a', null, data.file_titles[f].name || '파일');
          a.href = tboxBase + '/api/files/' + f + '/download';
          li.appendChild(a);
          ul.appendChild(li);
        }
        for (const f of atts) {
          const li = el('li', 'task-event');
          li.setAttribute('data-attachment', f);
          drawAttRow(li, f);
          attRows.set(f, li);
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
      let mentionStatus = {};
      function eventText(e) {
        const by = personLabel(e.by_display_name || null, e.by || '');
        if (e.kind === 'property') {
          const label = (v) => (e.prop === 'status' ? (statusLabels[v] ? statusLabels[v].text : '없음') : (v === null || v === '' ? '없음' : v));
          if (e.prop === 'assignee') { return e.to ? by + ' 님이 ' + e.to + ' 님에게 맡겼어요' : by + ' 님이 담당을 비웠어요'; }
          if (e.prop === 'status') { return by + ' 님이 상태를 「' + label(e.from) + '」에서 「' + label(e.to) + '」로 바꿨어요'; }
          if (e.prop === 'due') { return by + ' 님이 마감을 ' + label(e.from) + '에서 ' + label(e.to) + '로 바꿨어요'; }
          if (e.prop === 'start') { return by + ' 님이 시작 예정을 ' + label(e.from) + '에서 ' + label(e.to) + '로 바꿨어요'; }
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
            if (typeof deps.mentionInto === 'function') { deps.mentionInto(li, it.comment.body, mentionStatus); }
            else { li.appendChild(document.createTextNode(it.comment.body)); }
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
          // 카페 C4 — 남긴 댓글의 `@아이디` 확인 지도(서버 판정 — 전체 화면 댓글과 같은 창구 · 같은 표시 함수)
          mentionStatus = r.data.mention_status && typeof r.data.mention_status === 'object' && !Array.isArray(r.data.mention_status) ? r.data.mention_status : {};
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

      /* 바닥 — 댓글 칸(@ 부르기 = 멘션 입력 부품 한 벌 — U4-4 · DESIGN 2.27: 후보 목록 · 확인 색 · 「부를 사람」 줄) + 「보내기」(테두리 — 칸 안에 검정을 두지 않는다) */
      if (canComment) {
        const foot = el('form', 'task-pane__foot');
        const ta = el('textarea');
        ta.rows = 2;
        ta.maxLength = 2000;
        ta.placeholder = '댓글을 쓰거나 @ 로 사람을 부르세요';
        ta.setAttribute('aria-label', '댓글');
        const send = el('button', 'btn btn--quiet', '보내기');
        send.type = 'submit';
        foot.append(ta, send);
        // 카페 C2 — 칸이 익명게시판 글을 열면 멘션 부품을 끈다(같은 부품의 끄기 옵션 — 창구가 준 board.board_kind)
        const anonBoard = !!(data.board && data.board.board_kind === 'anonymous');
        if (anonBoard) { ta.placeholder = '댓글을 적어 주세요 — 다른 사람에게는 「익명N」으로 보여요'; }
        const mention = createMentionInput(ta, { pageId: id, api: api, personLabel: personLabel, disabled: anonBoard });
        foot.insertBefore(mention.notice, send);
        foot.addEventListener('submit', (e) => {
          e.preventDefault();
          const text = ta.value.trim();
          if (text === '') { ta.focus(); return; }
          send.disabled = true;
          api('POST', '/api/pages/' + id + '/comments', { body: text }).then((r) => {
            send.disabled = false;
            if (r.status !== 201 && r.status !== 200) { say(msg, failMessage(r)); return; }
            ta.value = '';
            mention.reset();
            loadComments();
          }).catch(() => { send.disabled = false; say(msg, genericError); });
        });
        paneEl.appendChild(foot);
      }

      /* 지우기 — 확인 창(공용 openConfirm) 뒤 `DELETE /api/pages/{id}`(휴지통 30일) */
      delItem.addEventListener('click', () => {
        const yes = () => api('DELETE', '/api/pages/' + id).then((r) => {
          if (r.status !== 200) { say(msg, failMessage(r)); return; }
          close({ push: true });
          if (ctx.onChanged) { ctx.onChanged(id); }
        }).catch(() => say(msg, genericError));
        if (typeof window.qarediOpenConfirm === 'function') {
          window.qarediOpenConfirm({ title: '작업을 지울까요?', lead: '「' + (page.title || '') + '」과 하위 작업이 목록에서 사라집니다.', note: '30일 안에는 「내 휴지통」에서 되살릴 수 있습니다.', okLabel: '휴지통으로 옮기기' }, yes);
        }
      });
      if (focusHead) { title.focus({ preventScroll: true }); }
    }

    /**
     * 담당 고르기(DESIGN 2.21) — 「바꾸기」 → 2.15 `.menu` 안 찾기 칸 + **창구가 준 후보만**(`assignee-candidates` — 그 작업을 고칠 수 있는 사람) ·
     * 맨 아래 「담당 없음」. 화면은 사람을 거르지도 더하지도 않는다(0장 1). 고른 값은 `PATCH /properties {assignee}` 가 다시 판정한다.
     * U1-a(2026-10-08 — 기획 13장): 메뉴를 열면 곧바로 빈 검색어로 부른다(창구가 나 먼저 · 최대 10) · 입력하면 200ms 뒤 좁힌다 ·
     * 늦게 온 답은 버린다(seq). 빈 검색어 답에서 후보가 0이거나 나 한 명뿐이면 후보 아래 안내 한 줄(그때는 「고칠 수 있는 사람만 나와요」를 숨긴다) —
     * 「나」는 `/api/me` 의 아이디로만 견준다.
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
      // U1-a — 빈 검색어 답이 0명이거나 나 한 명뿐일 때만 보이는 안내(후보 아래)
      const solo = el('p', 'help', '이 작업을 고칠 수 있는 사람이 나뿐이에요. 「공유」에서 편집 권한을 주거나 팀 공간 프로젝트에 두면 맡길 수 있어요.');
      solo.hidden = true;
      list.appendChild(solo);
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
        api('GET', '/api/pages/' + id + '/assignee-candidates?q=' + encodeURIComponent(text)).then((r) => {
          if (mine !== seq) { return; }
          clear(found);
          solo.hidden = true;
          hint.hidden = false;
          if (r.status !== 200) { found.appendChild(el('p', 'msg', failMessage(r))); return; }
          const people = Array.isArray(r.data.candidates) ? r.data.candidates : [];
          const me = meName();
          solo.hidden = !(text === '' && (people.length === 0 || (people.length === 1 && me !== '' && people[0].username === me)));
          // 같은 말을 두 번 하지 않는다(검토 RV-U1-1) — 「나뿐이에요」가 보이면 「고칠 수 있는 사람만 나와요」는 숨긴다
          hint.hidden = !solo.hidden;
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
      // 열자마자 후보(U1-a) — 빈 검색어로 곧바로 부른다
      box.addEventListener('toggle', () => {
        if (!box.open) { return; }
        window.clearTimeout(timer);
        q.value = '';
        q.focus();
        search();
      });
      return box;
    }

    return { open, close, showFull, release, current: () => currentId, host: () => paneEl, fromUrl: taskFromUrl, reload: () => (currentId ? load(currentId, false) : null),
      currentData: () => (currentId !== null && lastData && lastData.id === currentId ? lastData.data : null) };
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

  /** briefing — 실패(403 · 404 · 네트워크)면 null. 업무함의 작업 줄은 그대로 그려진다(검토 · 멘션 묶음만 빠짐 — DESIGN 2.20-A 「데이터」) */
  function loadBriefing() {
    return api('GET', '/api/briefing').then((r) => (r.status === 200 && r.data && typeof r.data === 'object' ? r.data : null)).catch(() => null);
  }

  function initHome() {
    const root = $('work-home');
    if (!root) { return; }
    const msg = msgEl();
    const ul = $('work-home-rows');
    if (!ul) { return; }
    let tasks = null;
    let brief = null;
    // 업무함 앞 10줄(WK-20 C — 묶음 경계를 지나며 센다) · 나머지는 「모두 보기」(→ 내 작업). 줄을 누르면 오른쪽 작업 상세 칸(2.21 aside — WK-26)
    const view = inboxView({
      root: root, ul: ul, empty: $('work-home-empty'), emptyText: () => '오늘 할 일이 없어요.', aside: $('task-pane'), limit: 10,
      onChanged: () => { load(); }
    });
    function draw() {
      if (!tasks) { return; }
      view.draw(buildInbox(tasks, brief, favIds(), tasks.today), typeof tasks.today === 'string' ? tasks.today : seoulToday());
    }
    function notice() {
      // 안 읽은 공지 한 줄 — briefing `others` 중 공지(게시판 읽기 판정 그대로 · 서버가 만든 주소만). 업무함에는 섞지 않는다(WK-8 ②)
      const box = $('work-home-notice');
      const link = $('work-home-notice-link');
      if (!brief || !box || !link) { return; }
      const item = (Array.isArray(brief.others) ? brief.others : []).find((i) => i && i.kind === 'notice'
        && typeof i.url === 'string' && i.url.indexOf(base + '/p/') === 0 && UUID_RE.test(i.url.slice((base + '/p/').length)));
      if (!item) { return; }
      setText(link, item.title);
      link.href = item.url;
      show(box, true);
    }
    function load() {
      const t = api('GET', '/api/my-tasks').then((r) => {
        if (r.status === 401) { toLogin(); return false; }
        if (r.status !== 200) { say(msg, errorMessage(r.data)); return false; }
        tasks = r.data;
        return true;
      }).catch(() => { say(msg, genericError); return false; });
      return Promise.all([t, loadBriefing()]).then(([ok, b]) => {
        brief = b;
        notice();
        if (ok) { draw(); }
      });
    }
    if (favs && typeof favs.onChange === 'function') { favs.onChange(draw); }
    window.addEventListener('work:changed', () => load());
    load().then(() => {
      const want = view.pane.fromUrl();
      if (want) { view.pane.open(want, { push: false }); }
    });
  }

  /* ════════════════ 내 작업 ════════════════ */

  function initMyTasks() {
    const root = $('work-my-tasks');
    if (!root) { return; }
    const msg = msgEl();
    const rowsEl = $('my-tasks-rows');
    const emptyEl = $('my-tasks-empty');
    const listEl = $('my-tasks-list');
    const boardEl = $('my-tasks-board');
    const calEl = $('my-tasks-calendar');
    const doneBtn = $('my-tasks-done');
    let view = 'list';
    let done = false;
    let data = null;
    let brief = null;
    let anchor = seoulToday();
    // 업무함 전체(WK-20 C) — 꼬리표 · 「더 보기」(next_cursor) · 「오늘」 「기한 없음」 꼬리표 뒤 「＋ 작업 추가」 그대로. 끝난 작업은 꼬리표 없이 한 흐름
    const inbox = inboxView({
      root: root, ul: rowsEl, empty: emptyEl, aside: $('task-pane'), limit: Infinity,
      emptyText: () => (done ? '끝난 작업이 없어요.' : '맡은 작업이 없어요. 「작업 추가」로 내 할 일을 적어 보세요.'),
      tags: () => !done,
      addKeys: () => (!done && canWrite() ? ADD_BUCKETS : []),
      onChanged: () => { load(); },
      onMore: (g, moreBtn) => {
        moreBtn.disabled = true;
        const b = (data.buckets || []).find((x) => x.key === g.key);
        if (!b) { return; }
        api('GET', '/api/my-tasks?done=' + (done ? '1' : '0') + '&bucket=' + encodeURIComponent(b.key) + '&cursor=' + encodeURIComponent(b.next_cursor)).then((r) => {
          if (r.status !== 200) { moreBtn.disabled = false; say(msg, failMessage(r)); return; }
          const got = (r.data.buckets || [])[0];
          if (got) { b.items = (b.items || []).concat(got.items || []); b.next_cursor = got.next_cursor; }
          draw();
        }).catch(() => { moreBtn.disabled = false; say(msg, genericError); });
      },
      onAdd: (g, li, addBtn) => {
        const today = data && typeof data.today === 'string' ? data.today : seoulToday();
        addBtn.hidden = true;
        addLine(li, g.name + '에 작업 추가', (title) => quickAdd(title, g.key === 'today' ? today : null), (saved) => {
          if (!saved) { addBtn.hidden = false; return; }
          load().then(() => {
            const again = rowsEl.querySelector('[data-add-bucket="' + g.key + '"]');
            if (again) { again.click(); }
          });
        });
      }
    });
    const pane = inbox.pane;

    function load() {
      const t = api('GET', '/api/my-tasks?done=' + (done ? '1' : '0')).then((r) => {
        if (r.status === 401) { toLogin(); return null; }
        if (r.status !== 200) { say(msg, errorMessage(r.data)); return null; }
        return r.data;
      }).catch(() => { say(msg, genericError); return null; });
      return Promise.all([t, done ? Promise.resolve(null) : loadBriefing()]).then(([d, b]) => {
        if (!d) { return; }
        data = d;
        brief = b;
        draw();
      });
    }
    function draw() {
      if (!data) { return; }
      const today = typeof data.today === 'string' ? data.today : seoulToday();
      show(listEl, view === 'list');
      show(boardEl, view === 'board');
      show(calEl, view === 'calendar');
      if (view === 'board') { drawBoard(today); return; }
      if (view === 'calendar') { drawCal(); return; }
      inbox.draw(buildInbox(data, done ? null : brief, favIds(), today), today);
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
      tools.appendChild(drawLegend());
      calEl.appendChild(tools);
      const host = el('div');
      calEl.appendChild(host);
      // Q:F-12 W1 — 기한 · 시작 예정 · 실제 시작 중 하나라도 있는 작업(막대 자료는 창구가 준 세 값 그대로) · 누르면 오른쪽 칸
      const rows = [];
      for (const b of data.buckets || []) {
        for (const it of b.items || []) {
          if (it.due || it.start || it.started) {
            rows.push({ id: it.public_id, title: it.title, due: it.due, start: it.start, started: it.started, finished: it.finished });
          }
        }
      }
      drawCalendar(host, { type: 'calendar', date_prop: 'due', mode: 'month' }, rows, range, {
        base: base, edit: false, today: typeof data.today === 'string' ? data.today : seoulToday(),
        open: (taskId) => { pane.open(taskId, { push: true }); }
      });
    }
    for (const b of root.querySelectorAll('[data-my-view]')) {
      b.addEventListener('click', () => {
        view = b.getAttribute('data-my-view');
        for (const x of root.querySelectorAll('[data-my-view]')) {
          const on = x === b;
          x.classList.toggle('is-active', on);
          x.setAttribute('aria-pressed', on ? 'true' : 'false');
        }
        // 열린 작업 칸은 보기를 바꿔도 그대로(WK-26 — 목록 · 보드 · 달력 모두 같은 오른쪽 칸)
        draw();
      });
    }
    if (favs && typeof favs.onChange === 'function') { favs.onChange(() => { if (view === 'list') { draw(); } }); }
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
  let taskFull = null;          // U4-2 — 작업 전체 화면 칸(한 번만 만든다)
  /** Q:F-12 U5-1(DESIGN 2.28 「첫 화면 뼈대」) — 서버가 project · task 첫 화면에만 그린 회색 막대(`.skeleton`)를 화면이 선 뒤 걷는다.
   *  CSS 도 `data-project-view` 뒤에는 뼈대를 보이지 않지만, 요소째 지워 화면 읽기 · 점검 도구에 남지 않게 한다 */
  function dropSkeleton() {
    for (const s of document.querySelectorAll('.main .skeleton')) { s.remove(); }
  }
  function pageLoaded(pageId, data) {
    pageData = data;
    const projectDocument = $('project-document');
    if (projectDocument) {
      projectDocument.hidden = !(data.page && data.page.doc && Array.isArray(data.page.doc.blocks)
        && data.page.doc.blocks.some((b) => b.type !== 'paragraph' || (b.text || []).some((t) => (t.t || '').trim() !== '')));
    }
    headDrawn = false;
    if (project && body.hasAttribute('data-project-view')) { drawProjectHead(); }
    // Q:F-12 U4-2(DESIGN 2.25) — 서버가 단 첫 화면 종류가 「작업」이면 오른쪽 칸과 같은 부품 한 벌을 `#task-full` 에 본문 폭으로(같은 draw).
    // 표식은 서버가 읽기 판정을 지난 사람에게만 단다 — 화면은 그 표식과 창구 답(can_*)만 따른다. 처음 한 번만 그리고, 그 뒤는 칸이 스스로 다시 읽는다
    const host = $('task-full');
    if (!taskFull && host && body.getAttribute('data-page-kind') === 'task' && data && data.page) {
      taskFull = createPane(host, {
        mode: 'full',
        onChanged: () => { forgetTree(data.space ? data.space.id : null); },
        onMarked: () => {},
        // 「문서로 보기」 — 표식을 떼고 칸을 걷으면 CSS 가 옛 일반 문서 부품을 그대로 보인다(프로젝트 「문서로 보기」와 같은 방식)
        onAsDoc: () => {
          body.removeAttribute('data-page-kind');
          if (taskFull) { taskFull.release(); }
          const top = $('page-title');
          if (top) { top.scrollIntoView({ block: 'start' }); }
        }
      });
      taskFull.showFull(pageId, data);
      dropSkeleton();   // U5-1 — showFull 이 칸을 비우며 이미 걷었다(남은 것이 있으면 여기서)
    }
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
    // U5-1 — 프로젝트 화면이 섰다(같은 흐름에서 database.js 가 곧바로 목록을 그린다 — 그 사이 그리기 0): 뼈대를 걷는다
    dropSkeleton();
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
    if (isProject) { enterProject(ctx); } else {
      if (project) { project = null; body.removeAttribute('data-project-view'); }
      // U4-1 — 첫 화면 종류가 「프로젝트」였는데 창구 답의 쓰임이 아니면(그사이 「표로 쓰기」) 표식을 떼고 일반 화면으로
      if (body.getAttribute('data-page-kind') === 'project') { body.removeAttribute('data-page-kind'); }
      dropSkeleton();
    }
  }

  function useAs(dbId, to) {
    const msg = msgEl();
    return api('PATCH', '/api/databases/' + dbId + '/schema', { use_as: to }).then((r) => {
      if (r.status !== 200) { say(msg, failMessage(r)); return; }
      window.location.reload();
    }).catch(() => say(msg, genericError));
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
    const projectDocument = $('project-document');
    if (projectDocument && asDocBtn) { projectDocument.addEventListener('click', () => asDocBtn.click()); }
    if (asDocBtn) {
      asDocBtn.addEventListener('click', () => {
        asDoc = true;
        body.removeAttribute('data-project-view');
        body.removeAttribute('data-page-kind');   // U4-1 — 서버가 단 첫 화면 종류도 뗀다(옛 모양)
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

  /* ════════════════ Q:F-16 1차 — 「작업 추가 ▾」 템플릿 골라 작업 만들기 (DESIGN 2.38) ════════════════
     꺾쇠(details.menu.menu--picker)는 머리 하나 · 섹션 끝마다 하나. 열 때 한 번만 목록 창구 둘(?database= · 일반)을 부르고 프로젝트마다 기억한다.
     보일지 · 무엇을 고를 수 있는지는 창구 값(can_add_rows · 목록 · template_scopes)뿐 — 역할 · 공간 · 범위 비교 0.
     목록 위의 「빈 작업」은 지금 「작업 추가」 단추를 그대로 누른다(그 자리 입력 줄 — 새 입력 코드 0). */
  const PICK_SCOPE = { database: '이 프로젝트', personal: '내 템플릿', team: '팀', site: '사이트 기본' };
  const PICK_ORDER = { personal: 0, team: 1, site: 2 };
  const finePointer = typeof window.matchMedia === 'function' ? window.matchMedia('(hover: hover) and (pointer: fine)') : null;
  const pickerParts = new WeakMap();
  let picker = null;     // { dbId, rows, general, bodies, errors, loading } — 프로젝트(DB) 하나에 하나
  let lastList = null;   // { gp, ctx } — 마지막으로 그린 목록(머리 꺾쇠가 섹션 칸을 안다)

  function pickerState() {
    const dbId = project && project.db ? String(project.db.id) : '';
    if (!picker || picker.dbId !== dbId) { picker = { dbId: dbId, rows: null, general: null, bodies: {}, errors: [], loading: null }; }
    return picker;
  }
  /** 「템플릿으로 저장」(201) 뒤 — 다음에 꺾쇠를 열 때 목록을 다시 받는다 */
  function pickerForget() {
    if (picker) { picker.rows = null; picker.general = null; picker.loading = null; }
  }
  function pickScopeText(t) {
    if (t.scope === 'team' && typeof t.team === 'string' && t.team !== '') { return PICK_SCOPE.team + ' · ' + t.team; }
    return PICK_SCOPE[t.scope] || '';
  }
  function loadPicker() {
    const st = pickerState();
    if (st.rows !== null) { return Promise.resolve(st); }
    if (st.loading) { return st.loading; }
    const take = (r) => {
      if (r.status === 401) { toLogin(); return { list: [], err: '' }; }
      if (r.status !== 200) { return { list: [], err: errorMessage(r.data) }; }
      return { list: r.data && Array.isArray(r.data.templates) ? r.data.templates : [], err: '' };
    };
    const fail = () => ({ list: [], err: genericError });
    st.loading = Promise.all([
      api('GET', '/api/templates?database=' + encodeURIComponent(st.dbId)).then(take, fail),
      api('GET', '/api/templates').then(take, fail)
    ]).then(([a, b]) => {
      st.rows = a.list;
      st.general = b.list.map((t, i) => ({ t: t, i: i }))
        .sort((x, y) => ((PICK_ORDER[x.t.scope] ?? 9) - (PICK_ORDER[y.t.scope] ?? 9)) || (x.i - y.i)).map((x) => x.t);
      st.errors = [a.err, b.err].filter((e) => e !== '');
      st.loading = null;
      return st;
    });
    return st.loading;
  }
  /** 섹션 칸 · 누른 자리 — 머리는 지금 보이는 목록의 첫 섹션(지금 「작업 추가」가 여는 그 자리), 섹션 끝은 그 섹션 */
  function pickPlace(parts) {
    if (parts.section !== null) { return { gp: parts.gp, value: parts.section, name: parts.sectionName, ctx: parts.ctx }; }
    const first = document.querySelector('#project-list:not([hidden]) [data-add-section]');
    if (!first || !lastList) { return { gp: null, value: '', name: '', ctx: project }; }
    const value = first.getAttribute('data-add-section') || '';
    const gp = lastList.gp;
    return { gp: gp, value: value, name: gp && value !== '' ? optionLabel(gp, value) : '', ctx: lastList.ctx };
  }
  /** 꺾쇠 안의 틀(한 번) — 목록이 비면 CSS(2.15)가 꺾쇠째 숨기므로 「빈 작업」은 처음부터 있다 */
  function setupPicker(menu, opts) {
    if (!menu || pickerParts.has(menu)) { return; }
    const summary = menu.querySelector('summary');
    const list = menu.querySelector('.menu__list');
    if (!summary || !list) { return; }
    clear(list);
    const sheetHead = el('div', 'menu__sheet-head');
    const closeSheet = iconButton('x', '닫기');
    closeSheet.addEventListener('click', () => { menu.open = false; summary.focus(); });
    sheetHead.append(el('strong', null, '템플릿 골라 작업 추가'), closeSheet);
    const col = el('div');
    const msg = el('p', 'msg');
    msg.setAttribute('role', 'alert');
    msg.setAttribute('aria-live', 'polite');
    const empty = el('button', 'menu__item');
    empty.type = 'button';
    empty.setAttribute('data-template-empty', '');
    empty.append(icon('plus'), el('span', null, '빈 작업'));
    empty.addEventListener('click', () => opts.onEmpty());
    const rowsBox = el('div');
    const hint = el('p', 'menu__hint', '자주 쓰는 작업을 템플릿으로 저장하면 여기서 골라 쓸 수 있어요.');
    hint.hidden = true;
    const sep = el('hr', 'menu__sep');
    const saveItem = el('button', 'menu__item', '지금 작업을 템플릿으로 저장');
    saveItem.type = 'button';
    saveItem.setAttribute('aria-haspopup', 'dialog');
    saveItem.setAttribute('data-template-save-current', '');
    saveItem.hidden = true;
    saveItem.addEventListener('click', () => {
      const saver = templateSaver();
      const id = projectPane ? projectPane.current() : null;
      const data = projectPane ? projectPane.currentData() : null;
      if (saver && id && data) { saver.open(id, data, { repeatParent: repeatParentOf(), onSaved: pickerForget }); }
    });
    col.append(msg, empty, rowsBox, hint, sep, saveItem);
    const preview = el('section', 'menu__preview');
    preview.setAttribute('role', 'region');
    preview.setAttribute('aria-label', '템플릿 미리보기');
    preview.hidden = true;
    list.append(sheetHead, col, preview);
    const parts = { menu: menu, summary: summary, msg: msg, rowsBox: rowsBox, hint: hint, saveItem: saveItem, preview: preview,
      section: opts.section, sectionName: opts.sectionName || '', gp: opts.gp || null, ctx: opts.ctx || null, previewing: null, hoverTimer: 0 };
    pickerParts.set(menu, parts);
    menu.addEventListener('toggle', () => { if (menu.open) { openPicker(parts); } });
  }
  function openPicker(parts) {
    say(parts.msg, '');
    const saver = templateSaver();
    const data = projectPane ? projectPane.currentData() : null;
    parts.saveItem.hidden = !(canWrite() && saver && data && Array.isArray(data.template_scopes) && data.template_scopes.length > 0);
    drawPickRows(parts);
    const st = pickerState();
    if (st.rows === null) {
      loadPicker().then(() => { if (parts.menu.open && parts.menu.isConnected) { drawPickRows(parts); } });
    }
  }
  function drawPickRows(parts) {
    const st = pickerState();
    clear(parts.rowsBox);
    if (st.rows === null) { parts.hint.hidden = true; return; }
    const all = st.rows.concat(st.general || []);
    for (const t of all) {
      if (!t || typeof t.id !== 'string') { continue; }
      const row = el('div', 'menu__row');
      const item = el('button', 'menu__item');
      item.type = 'button';
      item.setAttribute('data-template-id', t.id);
      item.setAttribute('data-template-scope', String(t.scope || ''));
      item.append(el('span', null, (t.icon ? t.icon + ' ' : '') + (t.name || t.title || '')), el('span', 'menu__scope', pickScopeText(t)));
      item.addEventListener('click', () => makeFromTemplate(parts, t));
      item.addEventListener('focusin', () => previewTemplate(parts, t));
      item.addEventListener('pointerenter', () => {
        if (!finePointer || !finePointer.matches) { return; }
        window.clearTimeout(parts.hoverTimer);
        parts.hoverTimer = window.setTimeout(() => previewTemplate(parts, t), 150);
      });
      item.addEventListener('pointerleave', () => window.clearTimeout(parts.hoverTimer));
      const look = iconButton('search', (t.name || t.title || '') + ' 미리보기', 'menu__look');
      look.setAttribute('aria-pressed', parts.previewing === t.id ? 'true' : 'false');
      look.setAttribute('data-template-look', t.id);
      look.addEventListener('click', () => previewTemplate(parts, t));
      row.append(item, look);
      parts.rowsBox.appendChild(row);
    }
    parts.hint.hidden = !(all.length === 0 && st.errors.length === 0);
    say(parts.msg, st.errors.length ? st.errors[0] : '');
  }
  function previewCtx() {
    const data = pageData || {};
    return { child_titles: {}, link_titles: {}, file_titles: {}, attachment_titles: {}, attachment_page: null, synced: {},
      embeds: data.embeds || { hosts: [], frames: false }, base: base, tbox_base: tboxBase, mention_labels: {}, db_views: {}, buttons: {} };
  }
  function previewTemplate(parts, t) {
    window.clearTimeout(parts.hoverTimer);
    if (parts.previewing === t.id && !parts.preview.hidden) { return; }
    parts.previewing = t.id;
    for (const b of parts.rowsBox.querySelectorAll('[data-template-look]')) {
      b.setAttribute('aria-pressed', b.getAttribute('data-template-look') === t.id ? 'true' : 'false');
    }
    const st = pickerState();
    const known = st.bodies[t.id];
    if (known) { drawPreview(parts, known); return; }
    // 본문을 받는 동안 앞 템플릿의 미리보기(와 그 「만들기 · 반복 만들기」)를 남기지 않는다 — 남기면 그 단추가 앞 템플릿으로 움직인다(검토 보정)
    clear(parts.preview);
    parts.preview.hidden = true;
    api('GET', '/api/templates/' + encodeURIComponent(t.id)).then((r) => {
      if (r.status === 401) { toLogin(); return; }
      if (parts.previewing !== t.id || !parts.menu.isConnected) { return; }
      if (r.status !== 200 || !r.data || !r.data.template) {
        clear(parts.preview);
        parts.preview.hidden = false;
        const m = el('p', 'msg', errorMessage(r.data));
        m.setAttribute('role', 'alert');
        parts.preview.appendChild(m);
        return;
      }
      st.bodies[t.id] = r.data.template;
      drawPreview(parts, r.data.template);
    }).catch(() => {
      if (parts.previewing !== t.id) { return; }
      clear(parts.preview);
      parts.preview.hidden = false;
      parts.preview.appendChild(el('p', 'msg', genericError));
    });
  }
  /** 칸 값 한 줄 — 행 템플릿의 값 가운데 지금 프로젝트 스키마에 있는 칸만(서버도 지금 스키마로 다시 거른다) */
  function fillLine(tpl, place) {
    if (tpl.scope !== 'database' || !project || !project.db) { return ''; }
    const schema = Array.isArray(project.db.schema) ? project.db.schema : [];
    const vals = tpl.properties && typeof tpl.properties === 'object' ? tpl.properties : {};
    const bits = [];
    for (const k of Object.keys(vals)) {
      const prop = schema.find((p) => p.key === k);
      if (!prop) { continue; }
      const v = vals[k];
      let text = '';
      if (prop.type === 'select') { text = optionLabel(prop, v); }
      else if (prop.type === 'multi_select') { text = (Array.isArray(v) ? v : []).map((x) => optionLabel(prop, x)).join('·'); }
      else if (prop.type === 'checkbox') { text = v === true ? '예' : '아니요'; }
      else if (v !== null && v !== undefined) { text = String(v); }
      if (text !== '') { bits.push((prop.name || k) + ' ' + text); }
    }
    if (bits.length === 0) { return ''; }
    let line = '미리 채움: ' + bits.join(' · ');
    if (place.gp && place.value !== '') { line += ' (' + (place.gp.key === 'section' ? '섹션' : place.gp.name) + '은 누른 자리 ' + place.name + ')'; }
    return line;
  }
  function drawPreview(parts, tpl) {
    const pv = parts.preview;
    clear(pv);
    pv.hidden = false;
    const head = el('div', 'toolbar');
    head.append(el('strong', null, (tpl.icon ? tpl.icon + ' ' : '') + (tpl.name || '')), el('span', 'muted', pickScopeText(tpl)));
    pv.appendChild(head);
    pv.appendChild(el('p', 'muted', '제목: ' + (tpl.title || '')));
    const line = fillLine(tpl, pickPlace(parts));
    if (line !== '') { pv.appendChild(el('p', 'muted', line)); }
    pv.appendChild(el('p', 'muted', '상태 · 담당 · 기한은 만든 뒤 작업 상세에서 정해요.'));
    const docBox = el('div');
    const blocks = tpl.doc && Array.isArray(tpl.doc.blocks) ? tpl.doc.blocks : [];
    let node = null;
    if (blocks.length) {
      try { node = renderDoc(tpl.doc, previewCtx()); } catch (e) { node = null; docBox.appendChild(el('p', 'muted', '본문을 그릴 수 없어요 — 전체 화면으로 열어 보세요.')); }
    }
    if (node && (node.textContent.trim() !== '' || node.querySelector('img, table, iframe, hr, a, input'))) { docBox.appendChild(node); }
    else if (!docBox.firstChild) { docBox.appendChild(el('p', 'muted', '본문이 비어 있어요.')); }
    pv.appendChild(docBox);
    const foot = el('div', 'toolbar');
    const use = textButton('이 템플릿으로 만들기', 'btn btn--quiet btn--small');
    use.setAttribute('data-template-make', '');
    use.addEventListener('click', () => { parts.menu.open = false; parts.summary.focus(); makeFromTemplate(parts, tpl); });
    foot.appendChild(use);
    if (canWrite()) {
      const rep = textButton('이 템플릿으로 반복 만들기', 'btn btn--quiet btn--small');
      rep.setAttribute('data-template-repeat', '');
      rep.addEventListener('click', () => {
        parts.menu.open = false;
        parts.summary.focus();
        repeatDialog(api, null, { template: tpl, parent: repeatParentOf() });
      });
      foot.appendChild(rep);
    }
    pv.appendChild(foot);
  }
  /** 고르기 → 만들기: 지금 「작업 추가」 줄과 같은 두 걸음(POST 행 → PATCH 상태 「할 일」) · 제목은 서버가 템플릿 제목으로 */
  function makeFromTemplate(parts, t) {
    if (!project || !project.db) { return; }
    const place = pickPlace(parts);
    const payload = { parent_id: project.db.page_id, template_id: t.id };
    if (place.gp && place.value !== '') { payload.properties = {}; payload.properties[place.gp.key] = place.value; }
    const msg = $('db-msg') || msgEl();
    const buttons = Array.from(parts.menu.querySelectorAll('button'));
    for (const b of buttons) { b.disabled = true; }
    say(msg, '');
    api('POST', '/api/pages', payload).then((r) => {
      if (r.status !== 201) { throw new Error(failMessage(r)); }
      return api('PATCH', '/api/pages/' + r.data.page.id + '/properties', { status: 'todo' }).then(() => r.data.page.id);
    }).then((id) => {
      const ctx = place.ctx || project;
      return Promise.resolve(ctx && typeof ctx.reload === 'function' ? ctx.reload() : null).then(() => { if (projectPane) { projectPane.open(id, { push: true }); } });
    }).catch((err) => say(msg, err && err.message ? err.message : genericError))
      .finally(() => { for (const b of buttons) { b.disabled = false; } });
  }

  function drawProjectHead() {
    if (!project || !pageData) { return; }
    headDrawn = true;
    const page = pageData.page || {};
    const w = canWrite();
    setText($('project-title'), page.title || '');
    document.title = (page.title || '') + ' · 모아 업무';
    setText(document.querySelector('.appbar__page'), '프로젝트');   // 폰 윗줄의 화면 이름(서버는 「페이지」로 그렸다)
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
    // Q:F-16 1차 — 머리 꺾쇠(창구 값 can_add_rows · 쓰기 자격만)
    const pickHead = $('project-add-task-pick');
    if (pickHead) {
      setupPicker(pickHead, { section: null, onEmpty: () => { const b = $('project-add-task'); if (b) { b.click(); } } });
      show(pickHead, project.db.can_add_rows === true && canWrite());
    }
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
      say(msg, '');
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
      if (!picked) { say(msg, '상태를 고르세요.'); return; }
      const save = $('project-status-save');
      save.disabled = true;
      api('POST', '/api/projects/' + project.db.page_id + '/status', { status: picked.value, note: note.value.trim() }).then((r) => {
        save.disabled = false;
        if (r.status !== 200) { say(msg, failMessage(r)); return; }
        dialog.close();
        loadProjectStatus();
      }).catch(() => { save.disabled = false; say(msg, genericError); });
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
  function editProjectSection(ctx, prop, group, trigger) {
    const m = modal('project-section', '섹션 관리', '이름 저장');
    const name = el('input');
    name.type = 'text'; name.maxLength = 50; name.required = true; name.value = group.name;
    field(m.form, '섹션 이름', name);
    const hint = el('p', 'muted', '이름을 바꿔도 이 섹션의 작업은 그대로 유지됩니다.');
    const remove = el('button', 'btn btn--danger btn--small', '섹션 삭제');
    remove.type = 'button';
    m.form.append(hint, remove);
    let action = 'rename'; let busy = false;
    const lock = (on) => {
      busy = on;
      for (const b of m.dialog.querySelectorAll('button, input')) { b.disabled = on; }
    };
    remove.addEventListener('click', () => {
      action = 'remove'; name.hidden = true; name.required = false; remove.hidden = true;
      hint.textContent = '이 섹션만 삭제합니다. 안에 있는 작업은 삭제되지 않고 「섹션 없음」에 표시됩니다.';
      m.ok.textContent = '섹션만 삭제'; m.ok.className = 'btn btn--danger';
      m.ok.focus();
    });
    m.dialog.addEventListener('cancel', (e) => { if (busy) { e.preventDefault(); } });
    m.dialog.addEventListener('close', () => {
      m.dialog.remove();
      if (trigger.isConnected) { trigger.focus(); }
    });
    m.form.addEventListener('submit', (e) => {
      e.preventDefault(); if (busy) { return; }
      const change = { action: action, value: group.value };
      if (action === 'rename') { change.name = name.value.trim(); }
      lock(true); setText(m.msg, '');
      api('PATCH', '/api/databases/' + ctx.db.id + '/schema', { section: change, version: ctx.db.version }).then((r) => {
        if (r.status !== 200) { lock(false); say(m.msg, failMessage(r)); return; }
        m.dialog.close();
        Promise.resolve(ctx.reload()).then(() => {
          const first = document.querySelector('#project-list .task-group__toggle');
          if (first) { first.focus(); }
        });
      }).catch(() => { lock(false); say(m.msg, genericError); });
    });
    m.dialog.showModal(); name.focus(); name.select();
  }

  function drawProjectList(host, ctx) {
    project = ctx;
    const db = ctx.db;
    const msg = $('db-msg') || msgEl();
    const today = seoulToday();
    const view = (ctx.views || []).find((v) => v.id === ctx.viewId) || null;
    const groupKey = view && view.group_by && view.group_by !== 'status' ? view.group_by : 'section';
    const gp = (db.schema || []).find((p) => p.key === groupKey && p.type === 'select' && Array.isArray(p.options)) || null;
    const spaceId = pageData && pageData.space ? pageData.space.id : null;
    lastList = { gp: gp, ctx: ctx };   // Q:F-16 1차 — 머리 꺾쇠가 섹션 칸을 안다
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
    const groups = gp ? gp.options.map((o) => ({ value: o, name: optionLabel(gp, o) })) : [];
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
        const groupHead = el('div', 'task-group__head');
        groupHead.appendChild(tog);
        if (gp && gp.key === 'section' && g.value !== '' && db.can_manage_schema === true) {
          const manage = iconButton('settings', g.name + ' 섹션 관리');
          manage.setAttribute('data-section-manage', g.value);
          manage.addEventListener('click', () => editProjectSection(ctx, gp, g, manage));
          groupHead.appendChild(manage);
        }
        sec.appendChild(groupHead);
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
              onCheck: (it, d) => setDone(it.id, d).then(() => ctx.reload()).catch((e) => say(msg, e.message))
            }));
            if (expanded) {
              for (const k of kids) {
                ul.appendChild(taskRow({ id: k.id, title: k.title, icon: k.icon, status: k.status, due: k.due,
                  assignee: k.assignee ? { username: k.assignee.username, display_name: null } : null }, {
                  kind: 'project', sub: true, today: today, canCheck: db.can_edit_values === true,
                  current: projectPane && projectPane.current() === k.id,
                  onOpen: (it) => projectPane.open(it.id, { push: true }),
                  onCheck: (it, d) => setDone(it.id, d).then(() => { forgetTree(spaceId); ctx.reload(); }).catch((e) => say(msg, e.message))
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
            // Q:F-16 1차(DESIGN 2.38 「자리 둘」) — 「작업 추가」 바로 오른쪽 꺾쇠. 「작업 추가」의 글자 · 속성 · 처리는 그대로
            if (canWrite()) {
              const pick = el('details', 'menu menu--picker');
              pick.setAttribute('data-add-section-pick', g.value);
              const pickSum = el('summary', 'btn btn--icon btn--quiet btn--small');
              pickSum.setAttribute('aria-label', '템플릿 골라 ' + g.name + '에 작업 추가');
              pickSum.appendChild(icon('chevron'));
              pick.append(pickSum, el('div', 'menu__list'));
              actions.appendChild(pick);
              setupPicker(pick, { section: g.value, sectionName: g.name, gp: gp, ctx: ctx, onEmpty: () => add.click() });
            }
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
            api('PATCH', '/api/databases/' + db.id + '/schema', { version: db.version, options: { key: gp.key, options: gp.options.concat([name]) } }).then((r) => {
              if (r.status !== 200) { input.disabled = false; say(msg, failMessage(r)); return; }
              ctx.reload();
            }).catch(() => { input.disabled = false; say(msg, genericError); });
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
    // Q:F-12 W1 — 프로젝트 달력 막대 누름 = 그 작업을 오른쪽 칸으로(목록 줄과 같은 칸)
    return { onRows: onRows, drawList: drawProjectList, openTask: (taskId) => { if (projectPane) { projectPane.open(taskId, { push: true }); } } };
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
          say(msg, errorMessage(r.data));
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
                if (x.status !== 200) { out.disabled = false; say(msg, failMessage(x)); return; }
                load();
              }).catch(() => { out.disabled = false; say(msg, genericError); });
            });
            tdAct.appendChild(out);
          }
          tr.appendChild(tdAct);
          rowsEl.appendChild(tr);
        }
        show(emptyEl, items.length === 0);
        say(msg, '');
        markNav();
      }).catch(() => say(msg, genericError));
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
          if (r.status !== 200) { say(msg, failMessage(r)); return; }
          window.location.assign(base + '/');
        }).catch(() => say(msg, genericError));
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
      // WK-17 A(U1-d) — 팀 공간 먼저. 앞 문장이 「개인 공간이면 나만 봐요」를 대신한다
      m.form.appendChild(el('p', 'help', '팀 공간에 만들면 팀원이 보고 맡을 수 있어요. 내 공간에 만들면 나만 보고 나만 맡을 수 있어요. '
        + '섹션(00. 준비 · 01. 진행 · 02. 마무리)과 목록 · 보드 · 타임라인 · 달력 보기가 함께 만들어져요.'));
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
    // 「어디에 만들까요」(WK-17 A · U1-d) — 쓸 수 있는 팀 공간 먼저(이름 가나다 순) · 내 공간은 끝. 첫 값 = 첫 팀 공간(없으면 내 공간).
    // 고르개의 순서만 정한다 — 그 공간에 만들 수 있나는 만들기 창구가 다시 판정한다
    spacesOnce().then((spaces) => {
      clear(newProject.space);
      const writable = spaces.filter((s) => s.can_write === true);
      const teams = writable.filter((s) => s.kind === 'team').sort((a, b) => String(a.name).localeCompare(String(b.name), 'ko'));
      for (const s of teams) { newProject.space.appendChild(new Option(s.name + ' (팀 공간)', String(s.id))); }
      for (const s of writable) {
        if (s.kind !== 'team') { newProject.space.appendChild(new Option('내 공간', String(s.id))); }
      }
      newProject.space.selectedIndex = newProject.space.options.length ? 0 : -1;
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
