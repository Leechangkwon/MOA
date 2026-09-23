/*
 * public/assets/community/database.js — 데이터베이스(6-A) 화면: DB 페이지의 **표**와 행 페이지의 **속성 줄**.
 *
 * 요구사항: 커뮤니티 PRD F-27, 지시서 `stage6a-database-directive.md` 「화면」.
 * 창구: GET /api/databases/{id}/rows · PATCH /api/databases/{id}/schema · PATCH /api/pages/{id}/values ·
 *       POST /api/pages {parent_id: DB 페이지}(새 행) · PATCH /api/pages/{id}/properties {status}(상태 열).
 *
 * 규칙 (DESIGN 원칙)
 *   · 모든 글자는 textContent / createElement 로만 넣는다(HTML 문자열을 끼워 넣지 않는다).
 *   · 인라인 style 0 · 새 색값 0. 칸 편집은 **그 자리**(창 없이), 속성 더하기·고치기만 창(`#db-prop-dialog`).
 *   · 「고칠 수 있나」는 창구가 준 값(`can_*`)만 따른다 — 판단은 서버가 다시 한다.
 *   · 값의 모양은 서버(`DbProps`)가 가른다. 화면은 입력 칸의 종류만 고른다.
 *
 * editor.js 가 `createDatabaseView(deps)` 로 한 번 만들고, 페이지를 읽을 때마다 `draw(data)` 를 부른다.
 */

const TYPE_LABELS = {
  text: '글', number: '숫자', checkbox: '체크', url: '주소', email: '메일', phone: '전화',
  date: '날짜', select: '선택', multi_select: '다중 선택', person: '사람'
};
const OP_LABELS = {
  contains: '포함', not_contains: '포함 안 함', eq: '=', ne: '≠', lt: '<', le: '≤', gt: '>', ge: '≥',
  empty: '비어 있음', not_empty: '비어 있지 않음', is: '이다', on: '그날', before: '전', after: '뒤',
  has: '가짐', not_has: '안 가짐'
};
const OPS = {
  text: ['contains', 'not_contains', 'eq', 'empty', 'not_empty'],
  url: ['contains', 'eq', 'empty', 'not_empty'],
  email: ['contains', 'eq', 'empty', 'not_empty'],
  phone: ['contains', 'eq', 'empty', 'not_empty'],
  number: ['eq', 'ne', 'lt', 'le', 'gt', 'ge', 'empty', 'not_empty'],
  checkbox: ['is'],
  date: ['on', 'before', 'after', 'empty', 'not_empty'],
  select: ['eq', 'ne', 'empty', 'not_empty'],
  multi_select: ['has', 'not_has', 'empty', 'not_empty'],
  person: ['eq', 'empty', 'not_empty'],
  title: ['contains', 'not_contains', 'eq'],
  status: ['eq', 'ne', 'empty', 'not_empty']
};
const STATUS_ORDER = ['todo', 'doing', 'review', 'done', 'hold'];

export function createDatabaseView(deps) {
  const { api, setText, show, clear, errorMessage, base, statusLabels, genericError, toLogin } = deps;
  const $ = (id) => document.getElementById(id);

  const wrap = $('db-wrap');
  const tableEl = $('db-table');
  const countEl = $('db-count');
  const msgEl = $('db-msg');
  const addRowForm = $('db-add-row');
  const addRowTitle = $('db-add-row-title');
  const addPropButton = $('db-add-prop');
  const fProp = $('db-f-prop');
  const fOp = $('db-f-op');
  const fApply = $('db-f-apply');
  const fClear = $('db-f-clear');
  const sortProp = $('db-sort');
  const sortDir = $('db-sort-dir');
  const rowWrap = $('row-props-wrap');
  const rowList = $('row-props');
  const rowMsg = $('row-props-msg');
  const dialog = $('db-prop-dialog');

  let db = null;          // {id, page_id, schema, can_add_rows, can_manage_schema, can_edit_values}
  let rows = [];
  let filter = null;      // {and:[…]} 또는 null
  let sort = null;        // [{prop, dir}] 또는 null
  let version = null;     // 스키마 판(updated_at) — 창구가 준 값

  /* ── 값 → 보이는 글자·요소 ── */
  function valueNode(prop, v) {
    const span = document.createElement('span');
    if (v === undefined || v === null) { span.className = 'muted'; span.textContent = '—'; return span; }
    switch (prop.type) {
      case 'checkbox': span.textContent = v === true ? '✓' : '—'; return span;
      case 'date': span.textContent = v.start + (v.end ? ' ~ ' + v.end : ''); return span;
      case 'multi_select': {
        span.className = 'db-chips';
        for (const t of Array.isArray(v) ? v : []) {
          const chip = document.createElement('span');
          chip.className = 'badge';
          chip.textContent = t;
          span.appendChild(chip);
        }
        return span;
      }
      case 'select': { span.className = 'badge'; span.textContent = String(v); return span; }
      case 'url': {
        // 서버가 http(s) 만 받는다. 그래도 그리기 전에 한 번 더 본다.
        if (/^https?:\/\//i.test(String(v))) {
          const a = document.createElement('a');
          a.href = String(v);
          a.rel = 'noopener noreferrer';
          a.target = '_blank';
          a.textContent = String(v);
          return a;
        }
        span.textContent = String(v);
        return span;
      }
      default: span.textContent = String(v); return span;
    }
  }

  /* ── 값 고치는 입력 칸 — 종류에 맞는 칸 하나를 만든다. commit(값) 으로 저장을 부른다 ── */
  function editorFor(prop, v, commit, cancel) {
    let el;
    const done = () => commit(readValue());
    function readValue() {
      switch (prop.type) {
        case 'number': return el.value.trim() === '' ? null : Number(el.value);
        case 'checkbox': return el.checked;
        case 'date': {
          if (el.value === '') { return null; }
          // 화면은 시작일만 고른다 — 창구로 넣은 끝날은 시작일보다 뒤면 그대로 둔다
          const out = { start: el.value };
          if (v && typeof v.end === 'string' && v.end.slice(0, 10) >= el.value) { out.end = v.end; }
          return out;
        }
        case 'select': return el.value === '' ? null : el.value;
        case 'multi_select': return [...el.querySelectorAll('input:checked')].map((c) => c.value);
        default: return el.value.trim() === '' ? null : el.value;
      }
    }
    switch (prop.type) {
      case 'checkbox':
        el = document.createElement('input');
        el.type = 'checkbox';
        el.checked = v === true;
        el.addEventListener('change', done);
        return el;
      case 'select':
        el = document.createElement('select');
        el.className = 'input';
        el.appendChild(new Option('—', ''));
        for (const o of prop.options || []) { el.appendChild(new Option(o, o, false, o === v)); }
        el.addEventListener('change', done);
        break;
      case 'multi_select': {
        el = document.createElement('div');
        el.className = 'db-multi';
        for (const o of prop.options || []) {
          const label = document.createElement('label');
          const c = document.createElement('input');
          c.type = 'checkbox';
          c.value = o;
          c.checked = Array.isArray(v) && v.indexOf(o) !== -1;
          label.appendChild(c);
          label.appendChild(document.createTextNode(' ' + o));
          el.appendChild(label);
        }
        const ok = document.createElement('button');
        ok.type = 'button';
        ok.className = 'btn btn--small';
        ok.textContent = '저장';
        ok.addEventListener('click', done);
        el.appendChild(ok);
        return el;
      }
      case 'date':
        el = document.createElement('input');
        el.type = 'date';
        el.className = 'input';
        el.value = v && v.start ? String(v.start).slice(0, 10) : '';
        el.addEventListener('change', done);
        break;
      case 'number':
        el = document.createElement('input');
        el.type = 'number';
        el.step = 'any';
        el.className = 'input';
        el.value = typeof v === 'number' ? String(v) : '';
        break;
      default:
        el = document.createElement('input');
        el.type = prop.type === 'email' ? 'email' : (prop.type === 'url' ? 'url' : 'text');
        el.className = 'input';
        el.maxLength = 2000;
        el.value = v === undefined || v === null ? '' : String(v);
        if (prop.type === 'person') { el.placeholder = '아이디'; }
    }
    el.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' && el.tagName === 'INPUT') { e.preventDefault(); done(); }
      if (e.key === 'Escape') { e.preventDefault(); cancel(); }
    });
    return el;
  }

  function saveValue(rowId, prop, value) {
    const values = {};
    values[prop.key] = value;
    return api('PATCH', '/api/pages/' + rowId + '/values', { values: values }).then((r) => {
      if (r.status === 401) { toLogin(); return null; }
      if (r.status !== 200) { throw new Error(errorMessage(r.data)); }
      return r.data.values || {};
    });
  }

  function saveStatus(rowId, status) {
    return api('PATCH', '/api/pages/' + rowId + '/properties', { status: status }).then((r) => {
      if (r.status !== 200) { throw new Error(errorMessage(r.data)); }
    });
  }

  /* ── 표 ── */
  function headerCell(text, prop) {
    const th = document.createElement('th');
    th.className = 'doc-table__cell';
    if (prop && db.can_manage_schema) {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'db-col';
      b.textContent = text;
      b.title = TYPE_LABELS[prop.type] + ' · 눌러서 고치기';
      b.addEventListener('click', () => openPropDialog(prop));
      th.appendChild(b);
    } else {
      th.textContent = text;
    }
    return th;
  }

  function drawTable() {
    clear(tableEl);
    const thead = document.createElement('thead');
    const hr = document.createElement('tr');
    hr.appendChild(headerCell('제목', null));
    hr.appendChild(headerCell('상태', null));
    for (const p of db.schema) { hr.appendChild(headerCell(p.name, p)); }
    hr.appendChild(headerCell('소유자', null));
    thead.appendChild(hr);
    tableEl.appendChild(thead);

    const tbody = document.createElement('tbody');
    for (const r of rows) {
      const tr = document.createElement('tr');
      tr.setAttribute('data-row', r.id);
      const tdTitle = document.createElement('td');
      tdTitle.className = 'doc-table__cell';
      const a = document.createElement('a');
      a.href = base + '/p/' + r.id;
      a.textContent = (r.icon ? r.icon + ' ' : '') + r.title;
      tdTitle.appendChild(a);
      tr.appendChild(tdTitle);

      const tdStatus = document.createElement('td');
      tdStatus.className = 'doc-table__cell';
      drawStatusCell(tdStatus, r);
      tr.appendChild(tdStatus);

      for (const p of db.schema) {
        const td = document.createElement('td');
        td.className = 'doc-table__cell' + (db.can_edit_values ? ' db-cell' : '');
        td.setAttribute('data-prop', p.key);
        fillCell(td, r, p);
        tr.appendChild(td);
      }
      const tdOwner = document.createElement('td');
      tdOwner.className = 'doc-table__cell muted';
      tdOwner.textContent = r.owner ? (r.owner.display_name ? r.owner.display_name + ' (' + r.owner.username + ')' : r.owner.username) : '팀 공간';
      tr.appendChild(tdOwner);
      tbody.appendChild(tr);
    }
    tableEl.appendChild(tbody);
  }

  function drawStatusCell(td, r) {
    clear(td);
    if (!db.can_edit_values) {
      const s = statusLabels[r.status];
      const span = document.createElement('span');
      span.className = s ? 'badge ' + s.badge : 'muted';
      span.textContent = s ? s.text : '—';
      td.appendChild(span);
      return;
    }
    const sel = document.createElement('select');
    sel.className = 'input db-status';
    sel.setAttribute('aria-label', r.title + ' 상태');
    sel.appendChild(new Option('—', ''));
    for (const k of STATUS_ORDER) { sel.appendChild(new Option(statusLabels[k].text, k, false, r.status === k)); }
    sel.addEventListener('change', () => {
      const want = sel.value === '' ? null : sel.value;
      saveStatus(r.id, want).then(() => { r.status = want; setText(msgEl, ''); })
        .catch((e) => { sel.value = r.status || ''; setText(msgEl, e.message || genericError); });
    });
    td.appendChild(sel);
  }

  function fillCell(td, r, p) {
    clear(td);
    const v = r.values ? r.values[p.key] : undefined;
    if (!db.can_edit_values) { td.appendChild(valueNode(p, v)); return; }
    if (p.type === 'checkbox') {
      // 체크는 누르는 즉시 저장한다(칸 편집 모드 없이)
      const box = editorFor(p, v, (val) => {
        saveValue(r.id, p, val).then((vals) => { if (vals) { r.values = vals; } setText(msgEl, ''); })
          .catch((e) => { box.checked = v === true; setText(msgEl, e.message || genericError); });
      }, () => {});
      box.setAttribute('aria-label', r.title + ' ' + p.name);
      td.appendChild(box);
      return;
    }
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'db-cell__show';
    b.setAttribute('aria-label', r.title + ' ' + p.name + ' 고치기');
    b.appendChild(valueNode(p, v));
    b.addEventListener('click', () => editCell(td, r, p));
    td.appendChild(b);
  }

  function editCell(td, r, p) {
    clear(td);
    const v = r.values ? r.values[p.key] : undefined;
    let busy = false;
    const ed = editorFor(p, v, (val) => {
      if (busy) { return; }
      busy = true;
      saveValue(r.id, p, val).then((vals) => {
        if (vals) { r.values = vals; }
        setText(msgEl, '');
        fillCell(td, r, p);
      }).catch((e) => { busy = false; setText(msgEl, e.message || genericError); });
    }, () => fillCell(td, r, p));
    td.appendChild(ed);
    if (ed.focus) { ed.focus(); }
  }

  /* ── 행 목록 받기 ── */
  function loadRows() {
    const q = [];
    if (filter) { q.push('filter=' + encodeURIComponent(JSON.stringify(filter))); }
    if (sort) { q.push('sort=' + encodeURIComponent(JSON.stringify(sort))); }
    return api('GET', '/api/databases/' + db.id + '/rows' + (q.length ? '?' + q.join('&') : '')).then((r) => {
      if (r.status === 401) { toLogin(); return; }
      if (r.status !== 200) { setText(msgEl, errorMessage(r.data)); return; }
      db.schema = r.data.database.schema;
      db.can_add_rows = r.data.can_add_rows === true;
      db.can_edit_values = r.data.can_edit_values === true;
      db.can_manage_schema = r.data.can_manage_schema === true;
      rows = Array.isArray(r.data.rows) ? r.data.rows : [];
      setText(countEl, '행 ' + String(r.data.total) + '개' + (filter ? ' (거른 결과)' : ''));
      show(addRowForm, db.can_add_rows);
      show(addPropButton, db.can_manage_schema);
      drawFilterProps();
      drawTable();
    }).catch(() => setText(msgEl, genericError));
  }

  /* ── 거르기·정렬 ── */
  function filterable() {
    const list = [{ key: 'title', name: '제목', type: 'title' }, { key: 'status', name: '상태', type: 'status' }];
    return list.concat(db.schema);
  }
  function drawFilterProps() {
    const keep = fProp.value;
    const keepSort = sortProp.value;
    clear(fProp);
    clear(sortProp);
    sortProp.appendChild(new Option('정렬 없음', ''));
    for (const p of filterable()) {
      fProp.appendChild(new Option(p.name, p.key, false, p.key === keep));
      if (p.type !== 'multi_select') { sortProp.appendChild(new Option(p.name, p.key, false, p.key === keepSort)); }
    }
    for (const [k, t] of [['created', '만든 때'], ['updated', '고친 때']]) { sortProp.appendChild(new Option(t, k, false, k === keepSort)); }
    drawOps();
  }
  function currentFilterProp() {
    return filterable().find((p) => p.key === fProp.value) || null;
  }
  function drawOps() {
    const p = currentFilterProp();
    const keep = fOp.value;
    clear(fOp);
    for (const op of p ? OPS[p.type] || [] : []) { fOp.appendChild(new Option(OP_LABELS[op], op, false, op === keep)); }
    drawValueInput();
  }
  function drawValueInput() {
    const p = currentFilterProp();
    const op = fOp.value;
    // 값 칸은 종류가 바뀔 때마다 새로 끼운다 — 늘 **지금 문서에 있는** 칸의 부모를 찾는다
    const current = document.getElementById('db-f-val');
    const holder = current.parentNode;
    let el;
    if (!p || op === 'empty' || op === 'not_empty') {
      el = document.createElement('input');
      el.type = 'text';
      el.disabled = true;
    } else if (p.type === 'select' || p.type === 'multi_select' || p.type === 'status' || p.type === 'checkbox') {
      el = document.createElement('select');
      const opts = p.type === 'status' ? STATUS_ORDER.map((k) => [k, statusLabels[k].text])
        : p.type === 'checkbox' ? [['true', '예'], ['false', '아니오']] : (p.options || []).map((o) => [o, o]);
      for (const [v, t] of opts) { el.appendChild(new Option(t, v)); }
    } else {
      el = document.createElement('input');
      el.type = p.type === 'number' ? 'number' : (p.type === 'date' ? 'date' : 'text');
      if (p.type === 'number') { el.step = 'any'; }
      el.maxLength = 200;
    }
    el.id = 'db-f-val';
    el.className = 'input';
    el.setAttribute('aria-label', '거를 값');
    holder.replaceChild(el, current);
  }
  fProp.addEventListener('change', drawOps);
  fOp.addEventListener('change', drawValueInput);
  fApply.addEventListener('click', () => {
    const p = currentFilterProp();
    if (!p) { return; }
    const op = fOp.value;
    const cond = { prop: p.key, op: op };
    const el = document.getElementById('db-f-val');
    if (op !== 'empty' && op !== 'not_empty') {
      if (p.type === 'number') { cond.value = Number(el.value); } else if (p.type === 'checkbox') { cond.value = el.value === 'true'; } else { cond.value = el.value; }
    }
    filter = { and: [cond] };
    show(fClear, true);
    loadRows();
  });
  fClear.addEventListener('click', () => { filter = null; show(fClear, false); loadRows(); });
  const onSort = () => { sort = sortProp.value === '' ? null : [{ prop: sortProp.value, dir: sortDir.value }]; loadRows(); };
  sortProp.addEventListener('change', onSort);
  sortDir.addEventListener('change', onSort);

  /* ── 새 행 ── */
  addRowForm.addEventListener('submit', (e) => {
    e.preventDefault();
    const title = addRowTitle.value.trim();
    if (title === '') { addRowTitle.focus(); return; }
    api('POST', '/api/pages', { parent_id: db.page_id, title: title }).then((r) => {
      if (r.status === 401) { toLogin(); return; }
      if (r.status !== 201) { setText(msgEl, errorMessage(r.data)); return; }
      addRowTitle.value = '';
      setText(msgEl, '');
      loadRows();
    }).catch(() => setText(msgEl, genericError));
  });

  /* ── 속성 창(더하기 · 이름·선택지 고치기 · 지우기) ── */
  const dTitle = $('db-prop-title');
  const dName = $('db-prop-name');
  const dType = $('db-prop-type');
  const dTypeField = $('db-prop-type-field');
  const dOptsField = $('db-prop-options-field');
  const dOpts = $('db-prop-options');
  const dMsg = $('db-prop-msg');
  const dSave = $('db-prop-save');
  const dRemove = $('db-prop-remove');
  let editing = null;
  let removeArmed = false;

  for (const t of Object.keys(TYPE_LABELS)) { dType.appendChild(new Option(TYPE_LABELS[t], t)); }
  const syncOpts = () => show(dOptsField, ['select', 'multi_select'].indexOf(editing ? editing.type : dType.value) !== -1);
  dType.addEventListener('change', syncOpts);

  function openPropDialog(prop) {
    editing = prop;
    removeArmed = false;
    setText(dMsg, '');
    setText(dTitle, prop ? '속성 고치기 — ' + prop.name : '속성 더하기');
    setText(dSave, prop ? '고치기' : '더하기');
    setText(dRemove, '지우기');
    show(dRemove, !!prop);
    show(dTypeField, !prop);
    dName.value = prop ? prop.name : '';
    dType.value = prop ? prop.type : 'text';
    dOpts.value = prop && prop.options ? prop.options.join('\n') : '';
    syncOpts();
    if (typeof dialog.showModal === 'function' && !dialog.open) { dialog.showModal(); }
    dName.focus();
  }
  addPropButton.addEventListener('click', () => openPropDialog(null));
  for (const c of dialog.querySelectorAll('[data-db-prop-close]')) { c.addEventListener('click', () => dialog.close()); }

  function sendSchema(body) {
    if (version) { body.version = version; }
    return api('PATCH', '/api/databases/' + db.id + '/schema', body).then((r) => {
      if (r.status === 401) { toLogin(); return false; }
      if (r.status !== 200) { setText(dMsg, errorMessage(r.data)); return false; }
      version = r.data.database.version;
      db.schema = r.data.database.schema;
      return true;
    });
  }
  function optionsFromText() {
    return dOpts.value.split('\n').map((s) => s.trim()).filter((s) => s !== '');
  }
  dSave.addEventListener('click', () => {
    const name = dName.value.trim();
    let chain;
    if (!editing) {
      const add = { name: name, type: dType.value };
      if (['select', 'multi_select'].indexOf(dType.value) !== -1) { add.options = optionsFromText(); }
      chain = sendSchema({ add: add });
    } else {
      chain = Promise.resolve(true);
      if (name !== editing.name) { chain = chain.then((ok) => ok && sendSchema({ rename: { key: editing.key, name: name } })); }
      if (['select', 'multi_select'].indexOf(editing.type) !== -1 && optionsFromText().join('\n') !== (editing.options || []).join('\n')) {
        chain = chain.then((ok) => ok && sendSchema({ options: { key: editing.key, options: optionsFromText() } }));
      }
    }
    chain.then((ok) => { if (ok) { dialog.close(); loadRows(); } }).catch(() => setText(dMsg, genericError));
  });
  // 지우기는 두 번 눌러야 한다(확인 창 없이 — DESIGN 「확인은 화면 안에서」)
  dRemove.addEventListener('click', () => {
    if (!editing) { return; }
    if (!removeArmed) { removeArmed = true; setText(dRemove, '정말 지우기'); return; }
    sendSchema({ remove: { key: editing.key } }).then((ok) => { if (ok) { dialog.close(); loadRows(); } })
      .catch(() => setText(dMsg, genericError));
  });

  /* ── 행 페이지의 속성 줄 ── */
  function drawRowProps(pageId, row) {
    clear(rowList);
    for (const p of row.schema) {
      const dt = document.createElement('dt');
      dt.className = 'db-props__name';
      dt.textContent = p.name;
      const dd = document.createElement('dd');
      dd.className = 'db-props__value';
      dd.setAttribute('data-prop', p.key);
      const fake = { id: pageId, title: '', values: row.values };
      const fill = () => {
        clear(dd);
        const v = fake.values ? fake.values[p.key] : undefined;
        if (!row.can_edit) { dd.appendChild(valueNode(p, v)); return; }
        if (p.type === 'checkbox') {
          const box = editorFor(p, v, (val) => {
            saveValue(pageId, p, val).then((vals) => { if (vals) { fake.values = vals; } setText(rowMsg, ''); })
              .catch((e) => { box.checked = v === true; setText(rowMsg, e.message || genericError); });
          }, () => {});
          box.setAttribute('aria-label', p.name);
          dd.appendChild(box);
          return;
        }
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'db-cell__show';
        b.setAttribute('aria-label', p.name + ' 고치기');
        b.appendChild(valueNode(p, v));
        b.addEventListener('click', () => {
          clear(dd);
          const ed = editorFor(p, v, (val) => {
            saveValue(pageId, p, val).then((vals) => { if (vals) { fake.values = vals; } setText(rowMsg, ''); fill(); })
              .catch((e) => setText(rowMsg, e.message || genericError));
          }, fill);
          dd.appendChild(ed);
          if (ed.focus) { ed.focus(); }
        });
        dd.appendChild(b);
      };
      fill();
      rowList.appendChild(dt);
      rowList.appendChild(dd);
    }
    show(rowWrap, row.schema.length > 0);
  }

  return {
    /** 페이지를 읽을 때마다 부른다 — DB 페이지면 표, 행이면 속성 줄, 아니면 둘 다 숨긴다 */
    draw(pageId, data) {
      setText(msgEl, '');
      setText(rowMsg, '');
      if (data.database) {
        db = {
          id: data.database.id, page_id: pageId, schema: data.database.schema || [],
          can_add_rows: data.database.can_add_rows === true,
          can_manage_schema: data.database.can_manage_schema === true,
          can_edit_values: data.database.can_add_rows === true
        };
        version = null;
        show(wrap, true);
        loadRows();
      } else {
        db = null;
        show(wrap, false);
      }
      if (data.row) { drawRowProps(pageId, data.row); } else { show(rowWrap, false); }
    }
  };
}
