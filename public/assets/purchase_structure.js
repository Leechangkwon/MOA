/* public/assets/purchase_structure.js — 「브랜드·지점·창고」 한 페이지 (24단계, PRD F-37)
 *
 * 19단계의 `purchase.js`·21단계의 `purchase_stock.js` 와 **별도 파일**이다(지시서: 다른 JS 를
 * 고치지 않는다). 전역을 만들지 않도록 전부 즉시 실행 함수 안에 있다.
 *
 * 지키는 것 (PRD S-01 · S-03, ARCH 13장)
 *   · **inner/outer HTML 계열과 document.write 를 쓰지 않는다.** 문서에 글자를 넣는 길은
 *     `textContent` 하나뿐이다. 창고명·지점명은 사람이 적은(또는 이카운트가 준) 문자열이고,
 *     그중 하나라도 태그로 해석되면 그것이 XSS 다.
 *   · **화면에서 판정하지 않는다.** 「추천」은 서버가 준 값을 보여 줄 뿐이고, 연결은 언제나
 *     사람이 고른 `branch_id` 로 간다. 막는 것은 라우트 정책 `purchase.master.write` 와
 *     재인증 관문이다 — 단추를 개발자도구로 되살려도 서버가 다시 막는다.
 *   · 상태를 바꾸는 요청에는 `X-CSRF-Token` 을 싣는다. 토큰은 `/api/me` 가 준다.
 *   · 재인증이 필요하면 **고정 키**로만 돌아온다(`/reauth?to=purchasestruct`) — 주소를
 *     요청 값에서 조립하지 않는다 (PRD F-23 오픈 리다이렉트 방지).
 */
(function () {
  'use strict';

  var GENERIC_ERROR = '처리하지 못했습니다. 잠시 후 다시 시도해 주세요.';
  /* 재인증 뒤 돌아올 **고정 키** — 서버 TBOX_REAUTH_PATHS·app.js REAUTH_PATHS 와 같은 값 */
  var REAUTH_KEY = 'purchasestruct';

  /** 장소 용도의 한국어 이름. 값 목록 자체는 서버(`kinds`)가 준다. */
  var KIND_LABEL = { warehouse: '창고', central: '중앙', or: '진료실', other: '기타' };

  /* ── 작은 도우미 ─────────────────────────────────────── */

  function $(id) { return document.getElementById(id); }

  /** 문서에 글자를 넣는 **유일한** 통로 */
  function setText(node, value) {
    if (!node) { return; }
    node.textContent = value === null || value === undefined ? '' : String(value);
  }

  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) { node.className = className; }
    if (text !== undefined) { setText(node, text); }
    return node;
  }

  function clear(node) {
    if (!node) { return; }
    while (node.firstChild) { node.removeChild(node.firstChild); }
  }

  function say(message) { setText($('msg'), message); }

  function dash(value) {
    return value === null || value === undefined || value === '' ? '—' : String(value);
  }

  /** 서버가 준 오류 문구만 보여 준다(없으면 공통 문구). 상태 코드를 지어내지 않는다. */
  function errorMessage(data) {
    if (data && typeof data.error === 'string' && data.error !== '') { return data.error; }
    return GENERIC_ERROR;
  }

  function readJson(response) {
    return response.json().then(function (data) {
      return data && typeof data === 'object' ? data : {};
    }).catch(function () { return {}; });
  }

  /** 401 → 로그인, 403 + next → 강제 절차·재인증 화면. 처리했으면 true */
  function handleGate(response, data) {
    if (response.status === 401) {
      window.location.href = '/login';
      return true;
    }
    if (response.status === 403 && data && data.next === 'reauth') {
      window.location.href = '/reauth?to=' + REAUTH_KEY;
      return true;
    }
    if (response.status === 403 && data
        && (data.next === 'password_change' || data.next === 'mfa_setup')) {
      window.location.href = '/';
      return true;
    }
    return false;
  }

  var csrfToken = '';

  function request(method, path, body, isForm) {
    var init = {
      method: method,
      credentials: 'same-origin',
      cache: 'no-store',
      headers: {}
    };
    if (method !== 'GET') { init.headers['X-CSRF-Token'] = csrfToken; }
    if (isForm) {
      init.body = body;
    } else if (body !== undefined && body !== null) {
      init.headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(body);
    }
    return fetch(path, init).then(function (response) {
      return readJson(response).then(function (data) {
        if (handleGate(response, data)) { return null; }
        return { ok: response.ok, status: response.status, data: data };
      });
    });
  }

  function button(label, className, onClick) {
    var b = el('button', className || 'btn btn--quiet btn--small', label);
    b.type = 'button';
    b.addEventListener('click', onClick);
    return b;
  }

  function cell(row, text, className) {
    var td = el('td', className || null, text);
    row.appendChild(td);
    return td;
  }

  /* ── 화면 상태 — 서버가 준 값을 그대로 들고 있을 뿐이다 ─── */
  var state = {
    brands: [],
    unlinked: [],
    excluded: [],
    counts: {},
    kinds: [],
    /** 행마다 사람이 고른 지점 (열쇠: inbox_id, 값: branch_id 문자열) */
    picked: {},
    /** 체크된 행 (열쇠: inbox_id) */
    checked: {}
  };

  /* ══════════════════════════════════════════════════════
   * 그리기
   * ══════════════════════════════════════════════════════ */

  function kindLabel(kind) { return KIND_LABEL[kind] || kind; }

  /** 지점 셀렉트 — 브랜드별 optgroup. 중단된 지점은 넣지 않는다(연결 대상이 아니다). */
  function branchSelect(selected) {
    var select = document.createElement('select');
    select.className = 'st-branch-select';
    var blank = el('option', null, '— 지점 고르기 —');
    blank.value = '';
    select.appendChild(blank);
    state.brands.forEach(function (brand) {
      var group = document.createElement('optgroup');
      group.label = String(brand.name);
      var added = 0;
      (brand.branches || []).forEach(function (branch) {
        if (branch.is_active === false) { return; }
        var option = el('option', null, String(branch.name));
        option.value = String(branch.id);
        group.appendChild(option);
        added += 1;
      });
      if (added > 0) { select.appendChild(group); }
    });
    select.value = selected === undefined || selected === null ? '' : String(selected);
    return select;
  }

  function renderCounts() {
    var box = $('st-counts');
    clear(box);
    var c = state.counts || {};
    [['미연결', c.unlinked], ['연결', c.linked], ['제외', c.excluded],
     ['브랜드', c.brands], ['지점', c.branches]].forEach(function (pair) {
      var chip = el('span', 'st-chip');
      chip.appendChild(el('span', 'st-chip__label', pair[0]));
      chip.appendChild(el('span', 'st-chip__value', String(pair[1] === undefined ? 0 : pair[1])));
      box.appendChild(chip);
    });
    if (Number(c.unlinked) === 0) {
      var done = el('span', 'st-chip st-chip--ok',
        '모두 정리됨 — 연결 ' + String(c.linked || 0) + ' · 제외 ' + String(c.excluded || 0));
      box.appendChild(done);
    }
  }

  function renderTree() {
    var wrap = $('st-tree');
    clear(wrap);
    $('st-tree-empty').hidden = state.brands.length > 0;

    state.brands.forEach(function (brand) {
      var block = el('div', 'st-brand');
      var head = el('div', 'st-brand__head');
      head.appendChild(el('span', 'st-brand__name', String(brand.name)));
      if (brand.is_active === false) { head.appendChild(el('span', 'p-badge p-badge--off', '중단')); }
      block.appendChild(head);

      (brand.branches || []).forEach(function (branch) {
        var row = el('div', 'st-branch');
        var line = el('div', 'st-branch__head');
        line.appendChild(el('span', 'st-branch__name', String(branch.name)));
        if (branch.code) { line.appendChild(el('span', 'st-branch__code', String(branch.code))); }
        line.appendChild(el('span', 'st-branch__count',
          '창고 ' + String((branch.locations || []).length)));
        if (branch.is_active === false) { line.appendChild(el('span', 'p-badge p-badge--off', '중단')); }
        row.appendChild(line);

        var list = el('ul', 'st-locs');
        (branch.locations || []).forEach(function (loc) {
          var li = el('li', 'st-loc');
          li.appendChild(el('span', 'st-loc__name', String(loc.name)));
          li.appendChild(el('span', 'st-loc__meta',
            dash(loc.code) + ' · ' + kindLabel(loc.kind)));
          if (loc.is_active === false) { li.appendChild(el('span', 'p-badge p-badge--off', '중단')); }
          list.appendChild(li);
        });
        if ((branch.locations || []).length === 0) {
          list.appendChild(el('li', 'st-loc st-loc--empty', '연결된 창고가 없습니다'));
        }
        row.appendChild(list);
        block.appendChild(row);
      });

      block.appendChild(branchForm(brand));
      wrap.appendChild(block);
    });
  }

  /** 브랜드 블록 끝의 「+ 지점」 — 이름 한 칸만 받는다 */
  function branchForm(brand) {
    var form = el('form', 'st-inline-form');
    form.autocomplete = 'off';
    var label = el('label', 'field field--inline');
    label.appendChild(el('span', 'field__label', '새 지점'));
    var input = document.createElement('input');
    input.type = 'text';
    input.maxLength = 60;
    input.required = true;
    label.appendChild(input);
    form.appendChild(label);
    var submit = el('button', 'btn btn--quiet btn--small', '+ 지점');
    submit.type = 'submit';
    form.appendChild(submit);
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      var name = input.value.trim();
      if (name === '') { say('지점 이름을 적으세요.'); return; }
      say('');
      request('POST', '/api/purchase/branches', { brand_id: brand.id, name: name })
        .then(function (result) {
          if (!result) { return; }
          if (!result.ok) { say(errorMessage(result.data)); return; }
          input.value = '';
          say('지점 「' + name + '」 을(를) 만들었습니다.');
          load();
        })
        .catch(function () { say(GENERIC_ERROR); });
    });
    return form;
  }

  function renderUnlinked() {
    var body = $('st-unlinked-body');
    clear(body);
    setText($('st-unlinked-title'), '미연결 창고 ' + String(state.unlinked.length));
    $('st-unlinked-empty').hidden = state.unlinked.length > 0;

    state.unlinked.forEach(function (item) {
      var row = document.createElement('tr');

      var checkCell = el('td', 'st-col-check');
      var check = document.createElement('input');
      check.type = 'checkbox';
      check.checked = state.checked[item.id] === true;
      check.addEventListener('change', function () {
        if (check.checked) { state.checked[item.id] = true; } else { delete state.checked[item.id]; }
      });
      checkCell.appendChild(check);
      row.appendChild(checkCell);

      cell(row, String(item.ecount_code), 'mono');
      cell(row, String(item.name));
      cell(row, dash(item.kind_text));
      cell(row, item.ecount_active === false ? '미사용' : '사용');

      var branchCell = el('td', 'st-col-branch');
      var suggested = item.suggest && item.suggest.branch_id ? item.suggest.branch_id : null;
      var picked = Object.prototype.hasOwnProperty.call(state.picked, item.id)
        ? state.picked[item.id]
        : (suggested === null ? '' : String(suggested));
      var select = branchSelect(picked);
      select.addEventListener('change', function () { state.picked[item.id] = select.value; });
      branchCell.appendChild(select);
      if (suggested !== null) { branchCell.appendChild(el('span', 'p-badge', '추천')); }
      row.appendChild(branchCell);

      var excludeCell = el('td', 'st-col-exclude');
      var exclude = document.createElement('input');
      exclude.type = 'checkbox';
      exclude.addEventListener('change', function () {
        exclude.disabled = true;
        setExcluded([item.id], true);
      });
      excludeCell.appendChild(exclude);
      row.appendChild(excludeCell);

      body.appendChild(row);
    });
  }

  function renderExcluded() {
    var body = $('st-excluded-body');
    clear(body);
    setText($('st-excluded-title'), '제외된 창고 ' + String(state.excluded.length));
    $('st-excluded-empty').hidden = state.excluded.length > 0;

    state.excluded.forEach(function (item) {
      var row = document.createElement('tr');
      cell(row, String(item.ecount_code), 'mono');
      cell(row, String(item.name));
      cell(row, dash(item.kind_text));
      var actions = el('td', null);
      actions.appendChild(button('되살리기', 'btn btn--quiet btn--small', function () {
        setExcluded([item.id], false);
      }));
      row.appendChild(actions);
      body.appendChild(row);
    });
  }

  function renderKinds() {
    var select = $('st-kind');
    if (!select || select.options.length > 0) { return; }
    var auto = el('option', null, '구분에서 유추');
    auto.value = '';
    select.appendChild(auto);
    state.kinds.forEach(function (kind) {
      var option = el('option', null, kindLabel(kind));
      option.value = String(kind);
      select.appendChild(option);
    });
  }

  function renderBulkBranch() {
    var holder = $('st-bulk-branch');
    var keep = holder.value;
    var fresh = branchSelect(keep);
    fresh.id = 'st-bulk-branch';
    holder.parentNode.replaceChild(fresh, holder);
  }

  /* ══════════════════════════════════════════════════════
   * 서버 부르기
   * ══════════════════════════════════════════════════════ */

  function load() {
    return request('GET', '/api/purchase/structure').then(function (result) {
      if (!result) { return; }
      if (!result.ok) { say(errorMessage(result.data)); return; }
      var data = result.data;
      state.brands   = Array.isArray(data.brands) ? data.brands : [];
      state.unlinked = Array.isArray(data.unlinked) ? data.unlinked : [];
      state.excluded = Array.isArray(data.excluded) ? data.excluded : [];
      state.counts   = data.counts && typeof data.counts === 'object' ? data.counts : {};
      state.kinds    = Array.isArray(data.kinds) ? data.kinds : [];
      state.picked   = {};
      state.checked  = {};
      renderKinds();
      renderCounts();
      renderTree();
      renderBulkBranch();
      renderUnlinked();
      renderExcluded();
    }).catch(function () { say(GENERIC_ERROR); });
  }

  /** 행마다의 (창고, 지점) 짝을 보낸다. 용도는 셀렉트가 비어 있으면 보내지 않는다(서버가 유추). */
  function sendLinks(links) {
    if (links.length === 0) { say('연결할 창고를 고르세요.'); return; }
    var body = { links: links };
    var kind = $('st-kind').value;
    if (kind !== '') { body.kind = kind; }
    say('연결하는 중…');
    request('POST', '/api/purchase/structure/link', body)
      .then(function (result) {
        if (!result) { return; }
        if (!result.ok) { say(errorMessage(result.data)); load(); return; }
        say('창고 ' + String(result.data.links) + '개를 연결했습니다.');
        load();
      })
      .catch(function () { say(GENERIC_ERROR); });
  }

  function setExcluded(ids, excluded) {
    say(excluded ? '제외하는 중…' : '되살리는 중…');
    request('POST', '/api/purchase/structure/exclude', { inbox_ids: ids, excluded: excluded })
      .then(function (result) {
        if (!result) { return; }
        if (!result.ok) { say(errorMessage(result.data)); load(); return; }
        say(excluded
          ? '창고 ' + String(result.data.changed) + '개를 제외했습니다.'
          : '창고 ' + String(result.data.changed) + '개를 되살렸습니다.');
        load();
      })
      .catch(function () { say(GENERIC_ERROR); });
  }

  /** 행에서 지금 고른 지점 — 사람이 바꿨으면 그 값, 아니면 서버가 준 추천 */
  function chosenBranch(item) {
    if (Object.prototype.hasOwnProperty.call(state.picked, item.id)) {
      return state.picked[item.id];
    }
    return item.suggest && item.suggest.branch_id ? String(item.suggest.branch_id) : '';
  }

  function initUpload() {
    var form = $('st-upload-form');
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      var input = $('st-file');
      if (!input || !input.files || input.files.length !== 1) {
        say('파일을 하나 고르세요.');
        return;
      }
      var data = new FormData();
      data.append('file', input.files[0]);
      say('올리는 중…');
      request('POST', '/api/purchase/structure/upload', data, true)
        .then(function (result) {
          if (!result) { return; }
          if (!result.ok) { say(errorMessage(result.data)); return; }
          var d = result.data;
          var line = '창고 파일 「' + dash(d.file_name) + '」 — 등록 ' + String(d.inserted)
            + ' · 갱신 ' + String(d.updated) + ' · 변동 없음 ' + String(d.unchanged)
            + ' · 건너뜀 ' + String(d.skipped);
          if (Number(d.renamed_linked) > 0) {
            line += ' · 이미 연결된 창고 ' + String(d.renamed_linked) + '개의 이름이 바뀌었습니다(확인 필요)';
          }
          say(line);
          input.value = '';
          load();
        })
        .catch(function () { say(GENERIC_ERROR); });
    });
  }

  function initButtons() {
    $('st-brand-form').addEventListener('submit', function (event) {
      event.preventDefault();
      var name = $('st-brand-name').value.trim();
      if (name === '') { say('브랜드 이름을 적으세요.'); return; }
      say('');
      request('POST', '/api/purchase/brands', { name: name })
        .then(function (result) {
          if (!result) { return; }
          if (!result.ok) { say(errorMessage(result.data)); return; }
          $('st-brand-name').value = '';
          say('브랜드 「' + name + '」 을(를) 만들었습니다.');
          load();
        })
        .catch(function () { say(GENERIC_ERROR); });
    });

    $('st-select-all').addEventListener('click', function () {
      var boxes = $('st-unlinked-body').querySelectorAll('.st-col-check input');
      var turnOn = Object.keys(state.checked).length !== state.unlinked.length;
      state.checked = {};
      Array.prototype.forEach.call(boxes, function (box, index) {
        box.checked = turnOn;
        if (turnOn && state.unlinked[index]) { state.checked[state.unlinked[index].id] = true; }
      });
    });

    // 「추천대로 연결」 — 체크된 행 중 **지점이 정해진 것만**, 행마다 자기 값으로.
    $('st-link-suggest').addEventListener('click', function () {
      var links = [];
      state.unlinked.forEach(function (item) {
        if (state.checked[item.id] !== true) { return; }
        var branch = chosenBranch(item);
        if (branch === '') { return; }
        links.push({ inbox_id: item.id, branch_id: Number(branch) });
      });
      sendLinks(links);
    });

    // 「이 지점에 연결」 — 체크된 행 전부를 **한 지점**으로.
    $('st-link-bulk').addEventListener('click', function () {
      var branch = $('st-bulk-branch').value;
      if (branch === '') { say('연결할 지점을 고르세요.'); return; }
      var links = [];
      state.unlinked.forEach(function (item) {
        if (state.checked[item.id] === true) {
          links.push({ inbox_id: item.id, branch_id: Number(branch) });
        }
      });
      sendLinks(links);
    });

    $('st-excluded-toggle').addEventListener('click', function () {
      var wrap = $('st-excluded-wrap');
      var open = wrap.hidden === true;
      wrap.hidden = !open;
      $('st-excluded-toggle').setAttribute('aria-expanded', open ? 'true' : 'false');
      setText($('st-excluded-toggle'), open ? '접기' : '펼치기');
    });
  }

  /* ══════════════════════════════════════════════════════
   * 부트 — `/api/me` 로 CSRF 토큰을 받은 뒤 화면을 그린다
   * ══════════════════════════════════════════════════════ */

  function boot() {
    if (!$('st-unlinked-body')) { return; }
    fetch('/api/me', { credentials: 'same-origin', cache: 'no-store' })
      .then(function (response) {
        if (response.status === 401) {
          window.location.href = '/login';
          return null;
        }
        return readJson(response);
      })
      .then(function (me) {
        if (me === null) { return; }
        csrfToken = typeof me.csrf_token === 'string' ? me.csrf_token : '';
        initUpload();
        initButtons();
        load();
      })
      .catch(function () { say(GENERIC_ERROR); });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
}());
