/* public/assets/purchase.js — 구매·재고 모듈 화면 (19단계, PRD F-28 · F-29, 시안 ⑭⑮⑯)
 *
 * 지키는 것 (PRD S-01 · S-03, ARCH 13장)
 *   · **inner/outer HTML 계열과 document 직접 쓰기를 쓰지 않는다.** 문서에 글자를 넣는 길은
 *     `textContent` 하나뿐이다. 표에 뜨는 이름·규격·사유는 전부 사람이 적은 문자열이고,
 *     그중 하나라도 태그로 해석되면 그것이 XSS 다.
 *   · **권한을 브라우저에서 판단하지 않는다.** 화면이 「만들기」 단추를 보일지는 API 가 준
 *     `can_edit` 하나로 정하고, 그 값이 무엇이든 서버가 요청마다 다시 판단한다.
 *     단추를 개발자도구로 되살려도 `purchase.master.write` 정책과 재인증 관문이 막는다.
 *   · **지점을 화면이 고르지 않는다.** 목록에 무엇이 보일지는 서버의 SQL WHERE 가 정한다
 *     (`Authz::purchaseScopeWhere`). 이 파일에 지점 필터 로직이 없는 것이 정상이다.
 *   · 상태 변경 요청에는 `X-CSRF-Token` 을 싣는다. 토큰은 `/api/me` 가 준다.
 *   · 403 `{next:"reauth"}` 를 받으면 재인증 화면으로 보낸다(기존 화면과 같은 처리).
 *
 * app.js 와 겹치지 않게 이 파일의 모든 것은 즉시 실행 함수 안에 있다(전역 0개).
 */
(function () {
  'use strict';

  var GENERIC_ERROR = '처리하지 못했습니다. 잠시 후 다시 시도해 주세요.';

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
      window.location.href = '/reauth';
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

  /* ══════════════════════════════════════════════════════
   * 화면 ⑭ — 기초정보 목록·만들기
   * ══════════════════════════════════════════════════════ */

  /** 종류별 표 머리와 각 행에서 꺼낼 칸. 라벨은 시안 ⑭ 의 것을 따른다. */
  var KIND_COLUMNS = {
    'brands':     [['name', '브랜드'], ['ceo_name', '대표'], ['biz_no', '사업자번호'], ['tel', '전화']],
    'branches':   [['name', '지점'], ['brand_name', '브랜드']],
    'locations':  [['code', '코드'], ['name', '장소'], ['kind', '용도'], ['branch_name', '지점'], ['legacy_name', '이전 이름']],
    'vendors':    [['code', '코드'], ['name', '거래처'], ['biz_no', '사업자번호']],
    'categories': [['name', '분류'], ['level', '단계'], ['parent_name', '상위']],
    'par-levels': [['item_code', '품목코드'], ['item_name', '품목'], ['location_name', '장소'], ['qty', '인가량'], ['effective_from', '적용 시작']]
  };

  var KIND_TITLE = {
    'brands': '브랜드', 'branches': '지점', 'locations': '장소',
    'vendors': '거래처', 'categories': '분류', 'par-levels': '인가량'
  };

  /** 만들기 폼의 칸 정의 — [키, 라벨, 형식, 필수] */
  var KIND_FIELDS = {
    'brands': [
      ['name', '브랜드 이름', 'text', true],
      ['ceo_name', '대표자', 'text', false],
      ['biz_no', '사업자번호', 'text', false],
      ['tel', '전화', 'text', false],
      ['address', '주소', 'text', false]
    ],
    'branches': [
      ['brand_id', '브랜드 id', 'number', true],
      ['name', '지점 이름', 'text', true]
    ],
    'locations': [
      ['branch_id', '지점 id', 'number', true],
      ['kind', '용도 (warehouse·central·or·other)', 'text', true],
      ['name', '장소 이름', 'text', true],
      ['code', '코드', 'text', false],
      ['legacy_name', '이전 이름', 'text', false]
    ],
    'vendors': [
      ['name', '거래처 이름', 'text', true],
      ['code', '거래처 코드', 'text', false],
      ['biz_no', '사업자번호', 'text', false]
    ],
    'categories': [
      ['name', '분류 이름', 'text', true],
      ['level', '단계 (1·2·3)', 'number', true],
      ['parent_id', '상위 분류 id (1단계는 비움)', 'number', false]
    ],
    'par-levels': [
      ['location_id', '장소 id', 'number', true],
      ['item_id', '품목 id', 'number', true],
      ['qty', '인가량', 'number', true],
      ['effective_from', '적용 시작 (YYYY-MM-DD)', 'text', false]
    ]
  };

  /** 장소 용도의 사람 말 (시안 ⑭) */
  var LOCATION_KIND_LABEL = {
    warehouse: '창고', central: '중앙장', or: '수술방', other: '기타'
  };

  function initMasters() {
    var tabs = $('p-tabs');
    var head = $('p-head');
    var body = $('p-body');
    var empty = $('p-empty');
    var pageInfo = $('p-page-info');
    var state = { kind: 'brands', page: 1, total: 0, perPage: 50, canEdit: false };

    function renderHead() {
      clear(head);
      var columns = KIND_COLUMNS[state.kind] || [];
      columns.forEach(function (column) {
        var th = el('th', null, column[1]);
        th.setAttribute('scope', 'col');
        head.appendChild(th);
      });
      var last = el('th', null, '상태');
      last.setAttribute('scope', 'col');
      head.appendChild(last);
      var actions = el('th', null, '작업');
      actions.setAttribute('scope', 'col');
      head.appendChild(actions);
    }

    function cellValue(item, key) {
      if (key === 'kind') { return LOCATION_KIND_LABEL[item.kind] || item.kind; }
      return item[key];
    }

    function stopResume(item) {
      var next = item.is_active === false;
      var what = next ? '재개' : '중단';
      if (!window.confirm('「' + dash(item.name || item.item_name) + '」 을(를) ' + what + '할까요?')) {
        return;
      }
      say('');
      request('PATCH', '/api/purchase/' + state.kind + '/' + String(item.id), { is_active: next })
        .then(function (result) {
          if (!result) { return; }
          if (!result.ok) { say(errorMessage(result.data)); return; }
          load();
        })
        .catch(function () { say(GENERIC_ERROR); });
    }

    function renderRows(items) {
      clear(body);
      var columns = KIND_COLUMNS[state.kind] || [];
      items.forEach(function (item) {
        var tr = document.createElement('tr');
        columns.forEach(function (column) {
          tr.appendChild(el('td', null, dash(cellValue(item, column[0]))));
        });

        var stateCell = document.createElement('td');
        var badge = el('span', item.is_active ? 'p-badge p-badge--update' : 'p-badge p-badge--off',
                       item.is_active ? '사용' : '중단');
        stateCell.appendChild(badge);
        tr.appendChild(stateCell);

        var actions = document.createElement('td');
        actions.className = 'actions';
        if (state.canEdit) {
          var button = el('button', 'btn btn--quiet btn--small', item.is_active ? '중단' : '재개');
          button.type = 'button';
          button.addEventListener('click', function () { stopResume(item); });
          actions.appendChild(button);
        } else {
          setText(actions, '—');
        }
        tr.appendChild(actions);

        body.appendChild(tr);
      });
      if (empty) { empty.hidden = items.length !== 0; }
    }

    function renderCreateForm() {
      var panel = $('p-create-panel');
      var fields = $('p-create-fields');
      if (!panel || !fields) { return; }
      panel.hidden = !state.canEdit;
      setText($('p-create-title'), KIND_TITLE[state.kind] + ' 새로 만들기');
      clear(fields);
      (KIND_FIELDS[state.kind] || []).forEach(function (spec) {
        var label = el('label', 'field');
        label.appendChild(el('span', 'field__label', spec[1] + (spec[3] ? ' *' : '')));
        var input = document.createElement('input');
        input.type = spec[2] === 'number' ? 'number' : 'text';
        input.id = 'p-new-' + spec[0];
        input.setAttribute('data-key', spec[0]);
        input.maxLength = 200;
        if (spec[3]) { input.required = true; }
        label.appendChild(input);
        fields.appendChild(label);
      });
    }

    function load() {
      var params = '?page=' + String(state.page);
      var q = $('p-q');
      if (q && q.value !== '') { params += '&q=' + encodeURIComponent(q.value); }
      var inactive = $('p-inactive');
      if (inactive && inactive.checked) { params += '&include_inactive=1'; }

      request('GET', '/api/purchase/' + state.kind + params)
        .then(function (result) {
          if (!result) { return; }
          if (!result.ok) { say(errorMessage(result.data)); return; }
          state.total = typeof result.data.total === 'number' ? result.data.total : 0;
          state.canEdit = result.data.can_edit === true;
          var importLink = $('p-import-link');
          if (importLink) { importLink.hidden = !state.canEdit; }
          renderHead();
          renderRows(Array.isArray(result.data.items) ? result.data.items : []);
          renderCreateForm();
          setText(pageInfo, '전체 ' + String(state.total) + '건 · ' + String(state.page) + '쪽');
        })
        .catch(function () { say(GENERIC_ERROR); });
    }

    if (tabs) {
      tabs.addEventListener('click', function (event) {
        var target = event.target;
        if (!target || !target.getAttribute) { return; }
        var kind = target.getAttribute('data-kind');
        if (!kind || !KIND_COLUMNS[kind]) { return; }
        state.kind = kind;
        state.page = 1;
        var buttons = tabs.querySelectorAll('.p-tab');
        for (var i = 0; i < buttons.length; i++) {
          buttons[i].className = buttons[i] === target ? 'p-tab is-on' : 'p-tab';
        }
        setText($('p-list-title'), KIND_TITLE[kind]);
        say('');
        load();
      });
    }

    var searchBox = $('p-q');
    if (searchBox) {
      searchBox.addEventListener('change', function () { state.page = 1; load(); });
    }
    var inactiveBox = $('p-inactive');
    if (inactiveBox) {
      inactiveBox.addEventListener('change', function () { state.page = 1; load(); });
    }
    var prev = $('p-prev');
    if (prev) {
      prev.addEventListener('click', function () {
        if (state.page > 1) { state.page -= 1; load(); }
      });
    }
    var next = $('p-next');
    if (next) {
      next.addEventListener('click', function () {
        if (state.page * state.perPage < state.total) { state.page += 1; load(); }
      });
    }

    var form = $('p-create-form');
    if (form) {
      form.addEventListener('submit', function (event) {
        event.preventDefault();
        var payload = {};
        var inputs = $('p-create-fields').querySelectorAll('input[data-key]');
        for (var i = 0; i < inputs.length; i++) {
          var key = inputs[i].getAttribute('data-key');
          var value = inputs[i].value.trim();
          if (value !== '') { payload[key] = value; }
        }
        say('');
        request('POST', '/api/purchase/' + state.kind, payload)
          .then(function (result) {
            if (!result) { return; }
            if (!result.ok) { say(errorMessage(result.data)); return; }
            form.reset();
            say('만들었습니다.');
            load();
          })
          .catch(function () { say(GENERIC_ERROR); });
      });
    }

    load();
  }

  /* ══════════════════════════════════════════════════════
   * 화면 ⑮ — 품목 상세
   * ══════════════════════════════════════════════════════ */

  function initItem(root) {
    var itemId = root.getAttribute('data-item-id') || '';
    if (!/^[0-9]{1,9}$/.test(itemId)) { say('품목을 찾을 수 없습니다.'); return; }

    request('GET', '/api/purchase/items/' + itemId)
      .then(function (result) {
        if (!result) { return; }
        if (!result.ok) { say(errorMessage(result.data)); return; }
        var item = result.data.item || {};
        setText($('p-item-name'), dash(item.code) + ' · ' + dash(item.name));

        var facts = $('p-item-facts');
        clear(facts);
        var rows = [
          ['품목코드', item.code],
          ['품목명', item.name],
          ['규격', item.spec],
          ['단위', item.unit],
          ['포장 낱개 수', item.pack_qty],
          ['바코드', item.barcode],
          ['브랜드', item.brand_name],
          ['기본 거래처', item.vendor_name],
          ['분류', [item.cat1_name, item.cat2_name, item.cat3_name]
            .filter(function (n) { return n; }).join(' › ')],
          ['재고관리', item.stock_managed ? '한다' : '안 한다 (구매 = 사용)'],
          ['안전재고 (낱개)', item.safety_qty],
          ['조달기간 (일)', item.lead_days],
          ['최소구매단위 (낱개)', item.min_order_qty],
          ['입고단가 (VAT 별도)', item.price_in],
          ['현재고', item.stock_qty],
          ['상태', item.is_active ? '사용' : '중단']
        ];
        rows.forEach(function (row) {
          facts.appendChild(el('dt', null, row[0]));
          facts.appendChild(el('dd', null, dash(row[1])));
        });

        var chips = $('p-item-branches');
        clear(chips);
        var branches = Array.isArray(result.data.branches) ? result.data.branches : [];
        if (branches.length === 0) {
          chips.appendChild(el('li', null, '지정 없음 (브랜드 전체)'));
        } else {
          branches.forEach(function (branch) {
            chips.appendChild(el('li', null, branch.branch_name));
          });
        }

        var parBody = $('p-item-par');
        clear(parBody);
        var pars = Array.isArray(result.data.par_levels) ? result.data.par_levels : [];
        pars.forEach(function (par) {
          var tr = document.createElement('tr');
          tr.appendChild(el('td', null, dash(par.branch_name)));
          tr.appendChild(el('td', null, dash(par.location_name)));
          tr.appendChild(el('td', null, dash(par.qty)));
          tr.appendChild(el('td', null, dash(par.effective_from)));
          parBody.appendChild(tr);
        });
        var parEmpty = $('p-item-par-empty');
        if (parEmpty) { parEmpty.hidden = pars.length !== 0; }
      })
      .catch(function () { say(GENERIC_ERROR); });
  }

  /* ══════════════════════════════════════════════════════
   * 화면 ⑯ — 엑셀 가져오기 (파일 → 열 맞추기 → 미리보기 → 확정)
   * ══════════════════════════════════════════════════════ */

  var STATUS_LABEL = { insert: '등록', update: '갱신', review: '확인', error: '오류' };

  /** 오류 사유 코드 → 사람 말 (서버는 코드만 준다 — 문구는 화면의 몫이다) */
  var REASON_LABEL = {
    required_missing: '필수 칸이 비었습니다',
    vendor_not_found: '거래처를 찾을 수 없습니다 — 거래처를 먼저 가져오세요',
    unit_missing: '단위가 없습니다',
    category_not_found: '분류 이름이 등록된 분류와 다릅니다',
    duplicate_in_file: '같은 거래처·코드가 이 파일 안에 또 있습니다',
    branch_not_found: '지점 이름을 찾을 수 없습니다',
    name_differs: '품목명이 등록된 이름과 다릅니다',
    // 1차 보정 — 이카운트의 「당수량(분자)/(분모)」. 분모가 1 이 아니면 사람이 봐야 한다.
    pack_den_not_one: '포장 수량의 분모가 1 이 아닙니다 — 낱개 수를 확인해 주세요',
    // 2차 보정 2026-09-13 — 실제 이카운트 거래처·창고 파일에서 나온 확인거리들
    duplicate_name_in_file: '같은 이름이 이 파일에 이미 있습니다 (코드가 다르면 「승격」해 따로 등록하세요)',
    name_exists: '같은 이름의 거래처가 이미 등록돼 있습니다 (다른 사업자면 「승격」해 따로 등록하세요)',
    branch_ambiguous: '창고명에 여러 지점 이름이 들어 있어 어느 지점인지 정할 수 없습니다',
    brand_not_found: '브랜드를 찾을 수 없습니다 — 「기본 브랜드」를 고르거나 브랜드를 먼저 등록하세요'
  };

  function initImport() {
    var state = {
      importId: '', kind: 'items', columns: [], sample: [], targets: {}, required: {},
      judged: null,
      // 1차 보정 2026-09-13 — 머리행은 서버가 고르고 사람이 바꾼다.
      headerRow: 0,        // 지금 고른 줄 (0기준)
      detected: {},        // 종류별 서버 감지 결과
      headRows: [],        // 앞 20행 원문 — 재업로드 없이 다시 그리는 재료
      guess: {},           // 종류별 매핑 후보 (서버가 준 것)
      // 2차 보정 — 지점 열이 없는 창고 파일·브랜드 열이 없는 지점 파일을 위한 기본값
      branchCount: 0, brandCount: 0, derive: ''
    };

    function step(current) {
      [1, 2, 3, 4].forEach(function (n) {
        var node = $('p-step-' + String(n));
        if (node) { node.className = n <= current ? 'p-step is-on' : 'p-step'; }
      });
    }

    function renderMapping(guess) {
      var tbody = $('p-map-body');
      clear(tbody);
      var targets = state.targets[state.kind] || [];
      state.columns.forEach(function (column, index) {
        var tr = document.createElement('tr');
        tr.appendChild(el('td', null, dash(column)));

        var samples = state.sample.map(function (row) { return row[index]; })
          .filter(function (v) { return v !== undefined && v !== ''; })
          .slice(0, 2).join(' · ');
        var sampleCell = el('td', 'muted p-cell-wide', dash(samples));
        tr.appendChild(sampleCell);

        var cell = document.createElement('td');
        var select = document.createElement('select');
        select.setAttribute('data-column', column);
        var skip = document.createElement('option');
        skip.value = '';
        setText(skip, '건너뜀');
        select.appendChild(skip);
        targets.forEach(function (target) {
          var option = document.createElement('option');
          option.value = target;
          // 2차 보정 — 종류에 맞는 사람 말로 보여 준다(`code` 가 아니라 「창고코드」).
          setText(option, labelOf(target));
          if (guess && guess[column] === target) { option.selected = true; }
          select.appendChild(option);
        });
        cell.appendChild(select);
        tr.appendChild(cell);

        tbody.appendChild(tr);
      });
    }

    /**
     * 지금 고른 머리행으로 **열 목록과 표본을 다시 만든다** (파일을 다시 올리지 않는다).
     * 재료는 업로드 응답의 `head_rows`(앞 20행 원문)뿐이고, 서버에 다시 묻지 않는다.
     */
    function applyHeaderRow(row, guess) {
      state.headerRow = row;
      state.columns = state.headRows[row] ? state.headRows[row].slice() : [];
      state.sample = state.headRows.slice(row + 1, row + 6);

      var note = $('p-header-note');
      if (note) {
        var auto = state.detected[state.kind];
        setText(note,
          String(row + 1) + '행을 머리행(열 이름이 적힌 줄)으로 봅니다'
          + (auto === row ? ' — 서버가 자동으로 고른 줄입니다.' : ' — 직접 고른 줄입니다.')
          + ' 데이터는 ' + String(row + 2) + '행부터입니다.');
      }
      renderMapping(guess || state.guess[state.kind] || {});
    }

    /** 머리행 셀렉트를 채운다 — 각 줄의 첫 세 칸을 보여 줘 고르기 쉽게 한다. */
    function fillHeaderSelect() {
      var select = $('p-header-row');
      if (!select) { return; }
      clear(select);
      state.headRows.forEach(function (row, index) {
        var peek = row.filter(function (v) { return v !== ''; }).slice(0, 3).join(' | ');
        var option = document.createElement('option');
        option.value = String(index);
        setText(option, String(index + 1) + '행: ' + (peek === '' ? '(빈 줄)' : peek));
        if (index === state.headerRow) { option.selected = true; }
        select.appendChild(option);
      });
    }

    fillDefaults();   // 2차 보정 — 지점·브랜드 목록을 미리 받아 둔다

    var headerSelect = $('p-header-row');
    if (headerSelect) {
      headerSelect.addEventListener('change', function () {
        var row = Number(headerSelect.value);
        if (!isFinite(row) || row < 0 || row >= state.headRows.length) { return; }
        // 줄을 바꾸면 매핑 후보도 그 줄 기준으로 다시 추측할 수 없다(서버가 준 것은 감지된
        // 줄 기준이다) — 그래서 열 이름이 같을 때만 이어진다. 나머지는 사람이 고른다.
        applyHeaderRow(row, null);
        say('');
      });
    }

    /**
     * 「기본 지점」·「기본 브랜드」 셀렉트를 채운다 (2차 보정 2026-09-13).
     *
     * 목록은 서버에서 받는다 — 화면이 고정 목록을 들고 있으면 지점이 늘 때마다 화면을
     * 고쳐야 한다. 값은 **id 뿐**이고, 그 id 로 무엇을 할 수 있는지는 서버가 다시 판단한다.
     */
    function fillDefaults() {
      [['/api/purchase/branches', 'p-default-branch'],
       ['/api/purchase/brands', 'p-default-brand']].forEach(function (pair) {
        var select = $(pair[1]);
        if (!select) { return; }
        request('GET', pair[0] + '?page=1').then(function (result) {
          if (!result || !result.ok) { return; }
          var items = Array.isArray(result.data.items) ? result.data.items : [];
          items.forEach(function (row) {
            var option = document.createElement('option');
            option.value = String(row.id);
            setText(option, row.name);
            select.appendChild(option);
          });
          if (pair[1] === 'p-default-branch') { state.branchCount = items.length; }
          if (pair[1] === 'p-default-brand') { state.brandCount = items.length; }
          showDefaults();
        }).catch(function () { /* 목록을 못 받아도 나머지 화면은 동작한다 */ });
      });
    }

    /** 종류에 따라 어떤 기본값 칸을 보일지 정하고, 없으면 무엇을 먼저 하라고 알린다. */
    function showDefaults() {
      var box = $('p-defaults');
      if (!box) { return; }
      var needBranch = state.kind === 'locations';
      var needBrand  = state.kind === 'branches';
      var derive     = state.kind === 'branches' || state.kind === 'locations';

      box.hidden = !(needBranch || needBrand || derive);
      var bf = $('p-default-branch-field');
      if (bf) { bf.hidden = !needBranch; }
      var rf = $('p-default-brand-field');
      if (rf) { rf.hidden = !needBrand; }
      var db = $('p-derive-branches');
      // 지점 뽑기 단추는 **창고 파일을 올렸을 때만** 뜻이 있다(창고명에서 뽑는다).
      if (db) { db.hidden = !(state.kind === 'locations'); }

      var note = $('p-defaults-note');
      if (!note) { return; }
      if (needBranch && state.branchCount === 0) {
        setText(note, '등록된 지점이 없습니다 — 「창고명에서 지점 이름 뽑기」로 먼저 만들거나,'
                    + ' 기초정보 화면의 「지점」 탭에서 직접 등록하세요.');
      } else if (needBrand && state.brandCount === 0) {
        setText(note, '등록된 브랜드가 없습니다 — 기초정보 화면의 「브랜드」 탭에서 먼저 등록하세요.');
      } else {
        setText(note, '');
      }
    }

    /** 미리보기·확정에 함께 보내는 값 한 벌 (기본 지점·브랜드·파생 모드) */
    function importBody(extra) {
      var body = {
        kind: state.derive === 'location_name' ? 'branches' : state.kind,
        mapping: currentMapping(),
        header_row: state.headerRow
      };
      var branch = $('p-default-branch');
      if (branch && branch.value !== '') { body.default_branch = branch.value; }
      var brand = $('p-default-brand');
      if (brand && brand.value !== '') { body.default_brand = brand.value; }
      if (state.derive !== '') { body.derive = state.derive; }
      Object.keys(extra || {}).forEach(function (k) { body[k] = extra[k]; });
      return body;
    }

    function currentMapping() {
      var mapping = {};
      var selects = $('p-map-body').querySelectorAll('select[data-column]');
      for (var i = 0; i < selects.length; i++) {
        var column = selects[i].getAttribute('data-column');
        if (selects[i].value !== '') { mapping[column] = selects[i].value; }
      }
      return mapping;
    }

    var form = $('p-import-form');
    if (form) {
      form.addEventListener('submit', function (event) {
        event.preventDefault();
        var input = $('p-import-file');
        if (!input || !input.files || input.files.length !== 1) {
          say('파일을 하나 고르세요.');
          return;
        }
        state.kind = $('p-import-kind').value;
        var data = new FormData();
        data.append('file', input.files[0]);
        say('올리는 중…');
        request('POST', '/api/purchase/import', data, true)
          .then(function (result) {
            if (!result) { return; }
            if (!result.ok) { say(errorMessage(result.data)); return; }
            state.importId = result.data.import_id;
            state.targets = result.data.targets || {};
            state.required = result.data.required || {};
            state.guess = result.data.mapping || {};
            // 1차 보정 — 앞 20행 원문과 종류별 감지 결과를 함께 받는다.
            state.headRows = Array.isArray(result.data.head_rows) ? result.data.head_rows : [];
            state.detected = result.data.header_rows || {};
            var picked = typeof result.data.header_row === 'number' ? result.data.header_row : 0;
            if (typeof state.detected[state.kind] === 'number') { picked = state.detected[state.kind]; }
            setText($('p-import-info'),
              dash(result.data.file_name) + ' · ' + String(result.data.rows) + '행'
              + (result.data.truncated ? ' · 상한에서 끊겼습니다(확정 불가)' : ''));

            var savedSelect = $('p-map-saved');
            clear(savedSelect);
            var none = document.createElement('option');
            none.value = '';
            setText(none, '— 저장된 매핑 없이 —');
            savedSelect.appendChild(none);
            (result.data.saved || []).forEach(function (saved, index) {
              if (saved.kind !== state.kind) { return; }
              var option = document.createElement('option');
              option.value = String(index);
              setText(option, saved.name);
              savedSelect.appendChild(option);
            });
            savedSelect.addEventListener('change', function () {
              var chosen = (result.data.saved || [])[Number(savedSelect.value)];
              if (!chosen) {
                applyHeaderRow(state.detected[state.kind] || 0, state.guess[state.kind] || {});
                return;
              }
              // 저장된 매핑은 **머리행 위치도 함께** 기억한다 — 같은 양식이 두 걸음으로 끝난다.
              var row = typeof chosen.header_row === 'number' ? chosen.header_row : 0;
              if (row < 0 || row >= state.headRows.length) { row = 0; }
              var sel = $('p-header-row');
              if (sel) { sel.value = String(row); }
              applyHeaderRow(row, chosen.mapping);
            });

            state.headerRow = picked;
            state.derive = '';
            fillHeaderSelect();
            applyHeaderRow(picked, state.guess[state.kind] || {});
            showDefaults();          // 2차 보정 — 종류에 맞는 기본값 칸을 연다
            $('p-map-panel').hidden = false;
            step(2);
            say('');
          })
          .catch(function () { say(GENERIC_ERROR); });
      });
    }

    /**
     * 미리보기를 누르기 전에 **필수 칸이 채워졌는지** 화면이 먼저 본다 (1차 보정 2026-09-13).
     *
     * 전에는 그대로 보내서 서버가 `mapping_incomplete` 400 을 돌려주었고, 화면에는
     * 「잘못된 요청입니다」만 떠서 **무엇이 빠졌는지 알 수 없었다**(2026-09-13 05:37 사고).
     * 서버의 400 문구는 그대로 둔다 — 사유를 응답에 싣지 않는 규칙(PRD S-09-5)은 그대로이고,
     * 여기서 막는 것은 편의일 뿐이다(보내면 서버가 여전히 막는다).
     *
     * @return list<string> 비어 있는 필수 칸 이름들
     */
    function missingRequired() {
      var need = state.required[state.kind] || [];
      var mapping = currentMapping();
      var have = {};
      Object.keys(mapping).forEach(function (column) { have[mapping[column]] = true; });
      return need.filter(function (target) { return have[target] !== true; });
    }

    /**
     * 우리 칸 이름의 사람 말 — **종류마다 다르다** (2차 보정 2026-09-13).
     *
     * 전에는 표가 하나뿐이라, 창고를 가져오는데 「품목명이 비어 있습니다」 라는 안내가 떴다
     * (사용자 혼란 실측). `code`·`name` 은 종류마다 뜻이 완전히 다르므로 표를 갈라 둔다.
     */
    var TARGET_LABEL = {
      items:     { code: '품목코드', name: '품목명', vendor: '구매처(거래처)', unit: '단위',
                   pack_qty: '포장 낱개 수', pack_den: '당수량(분모)', price_in: '입고단가',
                   cat1: '대분류', cat2: '중분류', cat3: '소분류', spec: '규격', barcode: '바코드' },
      vendors:   { code: '거래처코드', name: '거래처명', biz_no: '사업자번호',
                   ceo_name: '대표자명', tel: '전화', active: '사용구분' },
      locations: { code: '창고코드', name: '창고명', branch: '지점', kind: '구분(용도)',
                   legacy_name: '이전 이름', active: '사용' },
      branches:  { name: '지점명', brand: '브랜드', code: '지점코드', active: '사용' }
    };

    /** 지금 종류의 칸 이름 → 사람 말 (표에 없으면 원래 이름 그대로) */
    function labelOf(target) {
      var table = TARGET_LABEL[state.kind] || {};
      return table[target] || target;
    }

    /** 어느 규칙으로 지점이 정해졌나 — 미리보기 표의 「지점」 칸에 덧붙이는 꼬리표 */
    var BRANCH_BY_LABEL = {
      column: '파일의 지점 열',
      name:   '창고명에서 찾음',
      default: '기본 지점'
    };

    /**
     * 미리보기 결과를 그린다 (2차 보정에서 함수로 뺐다 — 「지점 뽑기」도 같은 표를 쓴다).
     * 모든 글자는 `textContent` 로만 들어간다.
     */
    function renderPreview(data, note) {
      state.judged = data;

      var counts = data.counts || {};
      var box = $('p-preview-counts');
      clear(box);
      [['insert', '새로 등록'], ['update', '기존 갱신'],
       ['review', '확인 필요'], ['error', '오류']].forEach(function (pair) {
        box.appendChild(el('span', 'p-badge p-badge--' + pair[0],
                           pair[1] + ' ' + String(counts[pair[0]] || 0)));
      });

      var tbody = $('p-preview-body');
      clear(tbody);
      (data.rows || []).forEach(function (row) {
        var tr = document.createElement('tr');
        tr.appendChild(el('td', null, row.row_no));

        var statusCell = document.createElement('td');
        statusCell.appendChild(el('span', 'p-badge p-badge--' + row.status,
                                  STATUS_LABEL[row.status] || row.status));
        tr.appendChild(statusCell);

        // 2차 보정 — 어느 지점으로 가는지와 **무엇을 보고 정했는지**를 함께 보여 준다.
        var branchText = '—';
        if (row.branch_name) {
          branchText = row.branch_name
            + (row.branch_by ? ' (' + (BRANCH_BY_LABEL[row.branch_by] || row.branch_by) + ')' : '');
        }
        tr.appendChild(el('td', 'muted', branchText));

        tr.appendChild(el('td', 'muted', row.reason ? (REASON_LABEL[row.reason] || row.reason) : '—'));

        var values = [];
        Object.keys(row.data || {}).forEach(function (key) {
          if (row.data[key] !== '' && key !== 'derived_from') {
            values.push(labelOf(key) + '=' + row.data[key]);
          }
        });
        tr.appendChild(el('td', 'p-cell-wide', values.join(' · ')));
        tbody.appendChild(tr);
      });

      $('p-preview-panel').hidden = false;
      step(3);
      say(note || '');
    }

    var previewButton = $('p-map-preview');
    if (previewButton) {
      previewButton.addEventListener('click', function () {
        state.derive = '';   // 일반 미리보기는 파생 모드가 아니다
        var missing = missingRequired();
        if (missing.length > 0) {
          say('필수 칸 ' + missing.map(function (t) { return '「' + labelOf(t) + '」'; }).join(' · ')
              + ' 이(가) 비어 있습니다. 오른쪽 「우리 칸」 에서 골라 주세요'
              + ' (열 이름이 안 보이면 위의 「머리행」 을 바꿔 보세요).');
          return;
        }
        say('판정하는 중…');
        request('POST', '/api/purchase/import/' + state.importId + '/preview', importBody())
          .then(function (result) {
            if (!result) { return; }
            if (!result.ok) { say(errorMessage(result.data)); return; }
            renderPreview(result.data, '');
          })
          .catch(function () { say(GENERIC_ERROR); });
      });
    }

    /* 「창고명에서 지점 이름 뽑기」 (2차 보정, 지시 4).
     *
     * 새 라우트를 만들지 않는다 — 같은 미리보기 창구에 `kind=branches`·`derive=location_name`
     * 을 보내면 서버가 창고명에서 지점 이름을 뽑아 **중복을 뺀 목록**으로 판정해 준다.
     * 사람이 그 목록을 보고 확정하면 지점이 생기고, 그 다음에 창고를 가져오면 된다.
     * 판정·확정 경로는 기존 그대로라 트랜잭션·감사도 똑같다.
     */
    var deriveButton = $('p-derive-branches');
    if (deriveButton) {
      deriveButton.addEventListener('click', function () {
        var mapping = currentMapping();
        var hasName = false;
        Object.keys(mapping).forEach(function (c) { if (mapping[c] === 'name') { hasName = true; } });
        if (!hasName) {
          say('먼저 「창고명」 열을 오른쪽에서 「창고명」 으로 골라 주세요 — 그 이름에서 지점을 뽑습니다.');
          return;
        }
        state.derive = 'location_name';
        say('창고명에서 지점 이름을 뽑는 중…');
        request('POST', '/api/purchase/import/' + state.importId + '/preview', importBody())
          .then(function (result) {
            if (!result) { state.derive = ''; return; }
            if (!result.ok) { state.derive = ''; say(errorMessage(result.data)); return; }
            renderPreview(result.data,
              '창고명에서 뽑은 지점 ' + String(result.data.total) + '개입니다. '
              + '확정을 누르면 지점으로 등록되고, 그 뒤에 창고를 다시 가져오면 됩니다.');
          })
          .catch(function () { state.derive = ''; say(GENERIC_ERROR); });
      });
    }

    var saveButton = $('p-map-save');
    if (saveButton) {
      saveButton.addEventListener('click', function () {
        var name = ($('p-map-name').value || '').trim();
        if (name === '') { say('매핑 이름을 적어 주세요.'); return; }
        say('');
        request('POST', '/api/purchase/import/mappings',
                { kind: state.kind, name: name, mapping: currentMapping(),
                  header_row: state.headerRow })
          .then(function (result) {
            if (!result) { return; }
            say(result.ok ? '매핑을 저장했습니다.' : errorMessage(result.data));
          })
          .catch(function () { say(GENERIC_ERROR); });
      });
    }

    var commitButton = $('p-commit');
    if (commitButton) {
      commitButton.addEventListener('click', function () {
        if (!window.confirm('오류 행을 뺀 나머지를 저장합니다. 계속할까요?')) { return; }
        say('확정하는 중…');
        request('POST', '/api/purchase/import/' + state.importId + '/commit', importBody())
          .then(function (result) {
            if (!result) { return; }
            if (!result.ok) { say(errorMessage(result.data)); return; }
            setText($('p-result'),
              '전체 ' + String(result.data.rows) + '행 중 새로 등록 ' + String(result.data.inserted)
              + ' · 갱신 ' + String(result.data.updated) + ' · 건너뜀 ' + String(result.data.skipped));
            $('p-result-panel').hidden = false;
            step(4);
            say('');
          })
          .catch(function () { say(GENERIC_ERROR); });
      });
    }

    /* 오류 목록 내려받기 — 서버가 이미 준 판정으로 브라우저에서 CSV 를 만든다(새 API 없음).
       값 앞의 `=`·`+`·`-`·`@` 에는 작은따옴표를 붙여 남의 엑셀에서 수식이 되지 않게 한다
       (서버의 Sheet::csvCell 과 같은 규칙). */
    var downloadButton = $('p-errors-download');
    if (downloadButton) {
      downloadButton.addEventListener('click', function () {
        if (!state.judged) { say('먼저 미리보기를 하세요.'); return; }
        var rows = (state.judged.attention || []);
        if (rows.length === 0) { say('오류·확인 행이 없습니다.'); return; }
        var keys = Object.keys(rows[0].data || {});
        var lines = [['행', '판정', '사유'].concat(keys).map(csvCell).join(',')];
        rows.forEach(function (row) {
          var line = [String(row.row_no), STATUS_LABEL[row.status] || row.status,
                      row.reason ? (REASON_LABEL[row.reason] || row.reason) : ''];
          keys.forEach(function (key) { line.push(String((row.data || {})[key] || '')); });
          lines.push(line.map(csvCell).join(','));
        });
        var blob = new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
        var url = URL.createObjectURL(blob);
        var link = document.createElement('a');
        link.href = url;
        link.download = 'import-errors.csv';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
      });
    }

    function csvCell(value) {
      var text = String(value === null || value === undefined ? '' : value);
      if (/^[\t\r]*[=+\-@]/.test(text)) { text = "'" + text; }
      return '"' + text.replace(/"/g, '""') + '"';
    }
  }

  /* ══════════════════════════════════════════════════════
   * 시작 — `/api/me` 로 CSRF 토큰을 받은 뒤 화면을 고른다
   * ══════════════════════════════════════════════════════ */

  document.addEventListener('DOMContentLoaded', function () {
    var isMasters = !!$('p-tabs');
    var itemRoot = $('p-item');
    var isImport = !!$('p-import-form');
    if (!isMasters && !itemRoot && !isImport) { return; }

    fetch('/api/me', { credentials: 'same-origin', cache: 'no-store' })
      .then(function (response) {
        if (response.status === 401) { window.location.href = '/login'; return null; }
        return response.json();
      })
      .then(function (me) {
        if (!me) { return; }
        csrfToken = typeof me.csrf_token === 'string' ? me.csrf_token : '';
        if (isMasters) { initMasters(); }
        if (itemRoot) { initItem(itemRoot); }
        if (isImport) { initImport(); }
      })
      .catch(function () { say(GENERIC_ERROR); });
  });
}());
