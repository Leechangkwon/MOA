/* public/assets/purchase_docs.js — 구매 전표 화면 (20단계, PRD F-30~F-32, 시안 ①②③④⑤⑧⑨)
 *
 * 19단계의 `purchase.js` 와 **별도 파일**이다(지시서: 19단계 보정과 충돌 방지).
 * 두 파일은 서로를 부르지 않고, 각 화면은 둘 중 하나만 읽어 들인다.
 *
 * 지키는 것 (PRD S-01 · S-03, ARCH 13장)
 *   · **inner/outer HTML 계열과 document.write 를 쓰지 않는다.** 문서에 글자를 넣는 길은
 *     `textContent` 하나뿐이다. 품목명·비고·거래처 이름은 사람이 적은 문자열이고,
 *     그중 하나라도 태그로 해석되면 그것이 XSS 다.
 *   · **권한을 브라우저에서 판단하지 않는다.** 단추를 보일지는 API 가 준 `can_write`·
 *     `can_send` 로 정하고, 그 값이 무엇이든 서버가 요청마다 다시 판단한다.
 *   · **금액을 브라우저가 만들지 않는다.** 화면의 합계는 눈으로 확인하는 값이고,
 *     저장되는 금액은 서버가 `p_items.price_in` 에서 다시 계산한다 (SECURITY R-66).
 *     그래서 저장 요청 본문에는 `qty` 만 실린다 — 단가·금액 칸이 아예 없다.
 *   · **지점을 화면이 고르지 않는다.** 목록에 무엇이 보일지는 서버의 SQL WHERE 가 정한다.
 *   · 상태 변경 요청에는 `X-CSRF-Token` 을 싣는다. 토큰은 `/api/me` 가 준다.
 *
 * app.js·purchase.js 와 겹치지 않게 이 파일의 모든 것은 즉시 실행 함수 안에 있다(전역 0개).
 */
(function () {
  'use strict';

  /* ── 공통 도우미 한 벌 (25단계) ─────────────────────────
   * el·setText·dash·errorMessage·request·cell·badge·qty·money·kpi·initTabs·say 는
   * 이제 `purchase_common.js` 한 곳에만 있다. 다섯 번들이 각자 들고 있던 사본을 지웠다 —
   * 한쪽만 고쳐지는 일이 없도록. 호출 경로·본문·응답 처리는 그대로다.
   */
  var UI = window.PurchaseUI;
  var $ = UI.$;
  var el = UI.el;
  var setText = UI.setText;
  var clear = UI.clear;
  var say = UI.say;
  var dash = UI.dash;
  var money = UI.money;
  var qtyText = UI.qty;
  var today = UI.today;
  var errorMessage = UI.errorMessage;
  var cell = UI.cell;
  var badge = UI.badge;
  var openModal = UI.openModal;
  var closeModal = UI.closeModal;

  /* 이 화면들(발주요청·발주서·전표)의 라우트는 재인증 대상이 아니다 — 그래도 관문이
     늘어날 날을 위해 서버가 준 `next` 를 따른다. 꼬리표는 없다(확인 뒤 홈으로). */
  var apiClient = UI.api('');
  var request = apiClient.request;

  /** 셀렉트를 목록으로 채운다. `textContent` 로만 글자를 넣는다. */
  function fillSelect(select, rows, valueKey, labelKey, emptyLabel) {
    if (!select) { return; }
    clear(select);
    if (emptyLabel !== null && emptyLabel !== undefined) {
      var blank = el('option', null, emptyLabel);
      blank.value = '';
      select.appendChild(blank);
    }
    rows.forEach(function (row) {
      var opt = el('option', null, String(row[labelKey]));
      opt.value = String(row[valueKey]);
      select.appendChild(opt);
    });
  }

  /** 상태 어휘 — 서버 값 → 사람이 읽는 말 (여기서 판단을 하지 않는다, 이름만 바꾼다) */
  var STATUS_LABEL = {
    unconfirmed: '미확인',
    in_progress: '진행중',
    partial: '일부 발주',
    done: '완료',
    void: '취소'
  };
  /* 색이 곧 뜻이다 (시안 README): 진행중·주의 warn · 일부 발주·정보 accent ·
     완료 ok · 취소 off · 미확인은 기본 회색. 다섯 화면이 같은 표를 본다. */
  var STATUS_CLASS = {
    unconfirmed: '',
    in_progress: 'badge--warn',
    partial: 'badge--accent',
    done: 'badge--ok',
    void: 'badge--off'
  };

  function statusBadge(status) {
    return badge(STATUS_LABEL[status] || status, STATUS_CLASS[status] || '');
  }

  /** 수량 문자열을 숫자로. 값이 없거나(21단계 전) 숫자가 아니면 0 — 배지 판정에만 쓴다. */
  function qtyNumber(value) {
    if (value === null || value === undefined || value === '') { return 0; }
    var n = Number(value);
    return isNaN(n) ? 0 : n;
  }

  /**
   * 사이클 한 줄에 **「아직 안 온 것」 배지**를 세운다 (21단계 `received_qty`·`not_received`).
   *
   * 조건 둘을 **함께** 본다: 미입고가 남아 있고, 그 요청에서 나온 발주서 중 **보낸 것**이 있다.
   * 아직 보내지 않은 발주서의 미입고는 거래처 탓이 아니라 우리가 아직 안 보낸 것이므로,
   * 그것까지 빨갛게 세우면 배지가 「늘 켜져 있는 불」이 되어 아무도 보지 않게 된다.
   *
   * 판정에 쓰는 값은 전부 **서버가 준 것**이고, 글자는 `textContent` 로만 들어간다.
   */
  function markLate(td, row) {
    if (!td || qtyNumber(row.not_received) <= 0) { return td; }
    var sent = (row.orders || []).some(function (order) {
      return order.doc_type === 'po' && order.sent_at;
    });
    if (!sent) { return td; }
    td.appendChild(badge('미입고', 'badge--danger'));
    return td;
  }

  /** 기초정보 셀렉트에 쓸 목록 — 서버가 이미 지점으로 좁혀 준다 */
  function loadMaster(kind) {
    return request('GET', '/api/purchase/' + kind).then(function (res) {
      if (!res || !res.ok) { return []; }
      return Array.isArray(res.data.items) ? res.data.items : [];
    });
  }

  /* ══════════════════════════════════════════════════════
   * 탭
   * ══════════════════════════════════════════════════════ */

  var initTabs = UI.initTabs;


  /* ══════════════════════════════════════════════════════
   * 사이클 표 — **한 벌** (시안 ⑨ Cycle)
   *
   * 25단계 전에는 같은 API(`/api/purchase/cycle`)를 두 함수가 **다른 열**로 그렸다
   * (발주요청 「현황」 탭 9열 · 전표 「사이클」 탭 10열). 한쪽만 고쳐지는 자리였다.
   * 이제 머리와 몸을 이 함수 하나가 그리고, 두 화면은 그릴 자리만 넘긴다.
   * ══════════════════════════════════════════════════════ */

  var CYCLE_COLUMNS = [
    ['발주요청', ''], ['요청자 · 장소', ''], ['품목', ''], ['요청', 'num'],
    ['발주서', ''], ['발주', 'num'], ['미발주', 'num'],
    ['입고', 'num'], ['미입고', 'num'], ['상태', '']
  ];

  function renderCycleHead(headRow) {
    if (!headRow || headRow.childNodes.length > 0) { return; }
    CYCLE_COLUMNS.forEach(function (column) {
      var th = el('th', column[1] || null, column[0]);
      th.setAttribute('scope', 'col');
      headRow.appendChild(th);
    });
  }

  /** 사이클 한 줄. 전표번호는 mono, 수량은 num mono, 상태는 배지다. */
  function renderCycleRows(bodyEl, rows) {
    clear(bodyEl);
    (rows || []).forEach(function (row) {
      var tr = el('tr');
      cell(tr, row.pr_doc_no, 'mono').appendChild(el('span', 'p-sub', row.pr_date));
      cell(tr, dash(row.requester)).appendChild(el('span', 'p-sub', dash(row.location_name)));
      cell(tr, row.name).appendChild(el('span', 'p-sub mono', row.code));

      cell(tr, qtyText(row.qty), 'num mono');

      var orderTd = el('td');
      var orders = row.orders || [];
      if (orders.length === 0) {
        setText(orderTd, '—');
      } else {
        orders.forEach(function (order) {
          var line = el('div');
          line.appendChild(el('span', 'mono', order.doc_no));
          if (order.sent_at) { line.appendChild(badge('보냄', 'badge--accent')); }
          orderTd.appendChild(line);
        });
      }
      tr.appendChild(orderTd);

      cell(tr, qtyText(row.ordered_qty), 'num mono');
      cell(tr, qtyText(row.not_ordered), 'num mono');
      // 서버가 원장 합으로 채우는 두 칸 (PRD F-34 R4) — 화면이 0 을 지어내지 않는다.
      cell(tr, qtyText(row.received_qty), 'num mono');
      markLate(cell(tr, qtyText(row.not_received), 'num mono'), row);

      var st = el('td');
      st.appendChild(statusBadge(row.pr_status));
      tr.appendChild(st);
      bodyEl.appendChild(tr);
    });
  }

  /** 사이클 한 벌을 불러 그린다. `ids` = {head, body, info}, `params` = 질의 문자열 */
  function loadCycleInto(ids, params) {
    renderCycleHead($(ids.head));
    return request('GET', '/api/purchase/cycle' + (params || '')).then(function (res) {
      if (!res || !res.ok) { return; }
      renderCycleRows($(ids.body), res.data.rows);
      setText($(ids.info), '요청 줄 ' + res.data.total + '개'
        + (res.data.truncated ? ' · 상한에서 끊김 — 기간을 좁혀 주세요' : ''));
    });
  }

  /* ══════════════════════════════════════════════════════
   * 화면 ① — 발주요청 (시안 Main · ItemPicker · MyList)
   * ══════════════════════════════════════════════════════ */

  function initPr() {
    var lines = [];          // [{item_id, code, name, spec, unit, vendor_name, price_in, qty, remark}]
    var picked = {};         // 품목 찾기에서 고른 것 (item_id → {row, qty})
    var pickPage = 1;
    var docPage = 1;
    var recoChosen = {};

    function renderLines() {
      var body = $('pr-line-body');
      clear(body);
      lines.forEach(function (line, index) {
        var tr = el('tr');
        cell(tr, String(index + 1), 'p-col-no');
        cell(tr, line.code, 'mono');
        var nameTd = cell(tr, line.name);
        nameTd.className = 'p-name p-cell-wide';
        if (line.spec) { nameTd.appendChild(el('span', 'p-sub', line.spec)); }
        cell(tr, dash(line.vendor_name));

        var qtyTd = el('td', 'num');
        var qtyInput = document.createElement('input');
        qtyInput.type = 'number';
        qtyInput.min = '0.001';
        qtyInput.step = '0.001';
        qtyInput.className = 'p-in p-in--qty';
        qtyInput.value = String(line.qty);
        qtyInput.addEventListener('input', function () {
          line.qty = qtyInput.value;
          renderSum();
        });
        qtyTd.appendChild(qtyInput);
        tr.appendChild(qtyTd);

        cell(tr, money(line.price_in), 'num mono');
        var amount = line.price_in === null ? null : Number(line.price_in) * Number(line.qty || 0);
        cell(tr, amount === null || isNaN(amount) ? '—' : money(amount), 'num mono');

        var remarkTd = el('td');
        var remarkInput = document.createElement('input');
        remarkInput.type = 'text';
        remarkInput.maxLength = 200;
        remarkInput.className = 'p-in p-in--text';
        remarkInput.value = line.remark || '';
        remarkInput.addEventListener('input', function () { line.remark = remarkInput.value; });
        remarkTd.appendChild(remarkInput);
        tr.appendChild(remarkTd);

        var actTd = el('td');
        actTd.appendChild(UI.button('빼기', 'btn btn--quiet btn--small', function () {
          lines.splice(index, 1);
          renderLines();
        }));
        tr.appendChild(actTd);

        body.appendChild(tr);
      });
      $('pr-line-empty').hidden = lines.length > 0;
      renderSum();
    }

    /** 푸터 — 좌 「품목 N건 · 수량 M · 거래처 K곳」, 우 합계(시안 ① Main 푸터) */
    function renderSum() {
      var sum = 0;
      var qtySum = 0;
      var vendors = {};
      lines.forEach(function (line) {
        var q = Number(line.qty || 0);
        if (!isNaN(q)) { qtySum += q; }
        if (line.vendor_name) { vendors[String(line.vendor_name)] = true; }
        if (line.price_in !== null && line.price_in !== undefined) {
          var v = Number(line.price_in) * Number(q || 0);
          if (!isNaN(v)) { sum += v; }
        }
      });
      var left = $('pr-sum');
      clear(left);
      left.appendChild(el('span', null, '품목 ' + lines.length + '건 · 수량 ' + qtyText(qtySum) + ' '));
      var vendorCount = Object.keys(vendors).length;
      if (vendorCount > 0) {
        left.appendChild(badge('거래처 ' + vendorCount + '곳', 'badge--accent'));
      }
      setText($('pr-total'), money(sum));
    }

    function addItem(row, qty) {
      var exists = null;
      lines.forEach(function (line) { if (line.item_id === row.item_id) { exists = line; } });
      if (exists) {
        exists.qty = String(Number(exists.qty || 0) + Number(qty || 1));
        return;
      }
      lines.push({
        item_id: row.item_id,
        code: row.code,
        name: row.name,
        spec: row.spec || null,
        unit: row.unit || null,
        vendor_name: row.vendor_name || null,
        price_in: row.price_in === undefined ? null : row.price_in,
        qty: String(qty || 1),
        remark: ''
      });
    }

    /* ── 품목 찾기 모달 ─────────────────────────────────── */
    function runPick() {
      var params = '?page=' + pickPage;
      var q = $('pick-q').value.trim();
      if (q !== '') { params += '&q=' + encodeURIComponent(q); }
      var vendor = $('pick-vendor').value;
      if (vendor !== '') { params += '&vendor=' + encodeURIComponent(vendor); }

      request('GET', '/api/purchase/items' + params).then(function (res) {
        if (!res) { return; }
        if (!res.ok) { say(errorMessage(res.data)); return; }
        var body = $('pick-body');
        clear(body);
        var items = Array.isArray(res.data.items) ? res.data.items : [];
        items.forEach(function (row) {
          var tr = el('tr');
          var checkTd = el('td');
          var check = document.createElement('input');
          check.type = 'checkbox';
          check.checked = Object.prototype.hasOwnProperty.call(picked, String(row.id));
          checkTd.appendChild(check);
          tr.appendChild(checkTd);

          cell(tr, row.code);
          cell(tr, row.name + (row.spec ? ' · ' + row.spec : ''));
          cell(tr, dash(row.vendor_name));
          cell(tr, money(row.price_in), 'num');

          var qtyTd = el('td', 'num');
          var qtyInput = document.createElement('input');
          qtyInput.type = 'number';
          qtyInput.min = '0.001';
          qtyInput.step = '0.001';
          qtyInput.value = picked[String(row.id)] ? picked[String(row.id)].qty : '1';
          qtyTd.appendChild(qtyInput);
          tr.appendChild(qtyTd);

          function sync() {
            if (check.checked) {
              picked[String(row.id)] = {
                row: {
                  item_id: row.id, code: row.code, name: row.name, spec: row.spec,
                  unit: row.unit, vendor_name: row.vendor_name, price_in: row.price_in
                },
                qty: qtyInput.value
              };
            } else {
              delete picked[String(row.id)];
            }
            setText($('pick-info'), '전체 ' + res.data.total + '건 · '
              + pickPage + '쪽 · 고른 품목 ' + Object.keys(picked).length + '건');
          }
          check.addEventListener('change', sync);
          qtyInput.addEventListener('input', function () { if (check.checked) { sync(); } });

          body.appendChild(tr);
        });
        setText($('pick-info'), '전체 ' + res.data.total + '건 · ' + pickPage + '쪽 · 고른 품목 '
          + Object.keys(picked).length + '건');
      });
    }

    $('pr-pick').addEventListener('click', function () {
      pickPage = 1;
      picked = {};
      openModal('pick-modal');
      runPick();
    });
    $('pick-search').addEventListener('click', function () { pickPage = 1; runPick(); });
    $('pick-prev').addEventListener('click', function () {
      if (pickPage > 1) { pickPage -= 1; runPick(); }
    });
    $('pick-next').addEventListener('click', function () { pickPage += 1; runPick(); });
    $('pick-add').addEventListener('click', function () {
      Object.keys(picked).forEach(function (key) {
        addItem(picked[key].row, picked[key].qty);
      });
      picked = {};
      renderLines();
      closeModal('pick-modal');
    });

    /* ── 추천 · 내 리스트 모달 ──────────────────────────── */
    function runReco() {
      var scope = $('reco-branch').checked ? 'branch' : 'me';
      request('GET', '/api/purchase/recommend?scope=' + scope).then(function (res) {
        if (!res || !res.ok) { return; }
        setText($('reco-note'), res.data.branch_ready
          ? '지점 기준은 「지점과 공유」 리스트가 있을 때만 열립니다 (지금: ' + res.data.scope + ')'
          : '지점 기준은 「지점과 공유」 리스트를 만든 뒤에 열립니다.');
        var body = $('reco-body');
        clear(body);
        (res.data.items || []).forEach(function (row) {
          var tr = el('tr');
          var checkTd = el('td');
          var check = document.createElement('input');
          check.type = 'checkbox';
          checkTd.appendChild(check);
          tr.appendChild(checkTd);
          cell(tr, row.name + ' · ' + row.code);
          cell(tr, String(row.times), 'num');
          cell(tr, qtyText(row.avg_qty), 'num');
          cell(tr, row.last_date);
          var qtyTd = el('td', 'num');
          var qtyInput = document.createElement('input');
          qtyInput.type = 'number';
          qtyInput.min = '0.001';
          qtyInput.step = '0.001';
          qtyInput.value = qtyText(row.avg_qty);
          qtyTd.appendChild(qtyInput);
          tr.appendChild(qtyTd);
          function sync() {
            if (check.checked) {
              recoChosen['r' + row.item_id] = { row: row, qty: qtyInput.value };
            } else {
              delete recoChosen['r' + row.item_id];
            }
          }
          check.addEventListener('change', sync);
          qtyInput.addEventListener('input', function () { if (check.checked) { sync(); } });
          body.appendChild(tr);
        });
      });
    }

    var myLists = [];

    function runLists() {
      request('GET', '/api/purchase/lists').then(function (res) {
        if (!res || !res.ok) { return; }
        myLists = Array.isArray(res.data.items) ? res.data.items : [];
        fillSelect($('reco-list'), myLists, 'id', 'name', '— 리스트 —');
        renderListItems();
      });
    }

    function renderListItems() {
      var body = $('reco-list-body');
      clear(body);
      var id = $('reco-list').value;
      var chosen = null;
      myLists.forEach(function (list) { if (String(list.id) === String(id)) { chosen = list; } });
      if (!chosen) { return; }
      (chosen.items || []).forEach(function (row) {
        var tr = el('tr');
        var checkTd = el('td');
        var check = document.createElement('input');
        check.type = 'checkbox';
        checkTd.appendChild(check);
        tr.appendChild(checkTd);
        cell(tr, row.name + ' · ' + row.code);
        cell(tr, qtyText(row.default_qty), 'num');
        check.addEventListener('change', function () {
          if (check.checked) {
            recoChosen['l' + row.item_id] = { row: row, qty: row.default_qty };
          } else {
            delete recoChosen['l' + row.item_id];
          }
        });
        body.appendChild(tr);
      });
    }

    $('pr-reco').addEventListener('click', function () {
      recoChosen = {};
      openModal('reco-modal');
      runReco();
      runLists();
    });
    $('reco-branch').addEventListener('change', runReco);
    $('reco-list').addEventListener('change', renderListItems);
    $('reco-add').addEventListener('click', function () {
      Object.keys(recoChosen).forEach(function (key) {
        addItem(recoChosen[key].row, recoChosen[key].qty);
      });
      recoChosen = {};
      renderLines();
      closeModal('reco-modal');
    });
    $('reco-list-add').addEventListener('click', function () {
      var id = $('reco-list').value;
      myLists.forEach(function (list) {
        if (String(list.id) !== String(id)) { return; }
        (list.items || []).forEach(function (row) { addItem(row, row.default_qty); });
      });
      renderLines();
      say('리스트의 품목을 담았습니다.');
    });
    $('reco-list-new').addEventListener('click', function () {
      if (lines.length === 0) { say('먼저 줄을 담아 주세요.'); return; }
      var name = window.prompt('리스트 이름 (60자 이내)', '');
      if (name === null || name.trim() === '') { return; }
      var payload = { name: name.trim(), items: [] };
      lines.forEach(function (line, index) {
        payload.items.push({ item_id: line.item_id, default_qty: String(line.qty), sort_no: index + 1 });
      });
      request('POST', '/api/purchase/lists', payload).then(function (res) {
        if (!res) { return; }
        if (!res.ok) { say(errorMessage(res.data)); return; }
        say('리스트 「' + res.data.name + '」 을(를) 만들었습니다.');
        runLists();
      });
    });

    /* ── 저장 ──────────────────────────────────────────── */
    $('pr-input').addEventListener('submit', function (event) {
      event.preventDefault();
      if (lines.length === 0) { say('품목을 한 줄 이상 담아 주세요.'); return; }
      var payload = { doc_date: $('pr-date').value, lines: [] };
      if ($('pr-branch').value !== '') { payload.branch_id = Number($('pr-branch').value); }
      if ($('pr-location').value !== '') { payload.to_location_id = Number($('pr-location').value); }
      if ($('pr-vendor').value !== '') { payload.vendor_id = Number($('pr-vendor').value); }
      if ($('pr-memo').value !== '') { payload.memo = $('pr-memo').value; }
      lines.forEach(function (line) {
        // **수량만** 보낸다. 단가·금액은 서버가 p_items 에서 계산한다 (R-66).
        var item = { item_id: line.item_id, qty: String(line.qty) };
        if (line.remark) { item.remark = line.remark; }
        payload.lines.push(item);
      });
      request('POST', '/api/purchase/pr', payload).then(function (res) {
        if (!res) { return; }
        if (!res.ok) { say(errorMessage(res.data)); return; }
        say('발주요청 ' + res.data.doc_no + ' 을(를) 저장했습니다.');
        lines = [];
        renderLines();
        loadDocs();
      });
    });

    /* ── 전표 목록 ─────────────────────────────────────── */
    function loadDocs() {
      var params = '?page=' + docPage;
      if ($('pr-q').value.trim() !== '') { params += '&q=' + encodeURIComponent($('pr-q').value.trim()); }
      if ($('pr-status').value !== '') { params += '&status=' + encodeURIComponent($('pr-status').value); }
      if ($('pr-mine').checked) { params += '&mine=1'; }

      request('GET', '/api/purchase/pr' + params).then(function (res) {
        if (!res || !res.ok) { return; }
        var body = $('pr-doc-body');
        clear(body);
        var items = Array.isArray(res.data.items) ? res.data.items : [];
        items.forEach(function (row) {
          var tr = el('tr', 'p-row-click');
          var noTd = cell(tr, row.doc_no, 'mono');
          noTd.appendChild(el('span', 'p-sub', row.doc_date));
          cell(tr, dash(row.branch_name));
          cell(tr, dash(row.location_name));
          cell(tr, dash(row.vendor_name));
          cell(tr, dash(row.manager_name));
          cell(tr, String(row.line_count), 'num mono');
          cell(tr, money(row.amount), 'num mono');
          var st = el('td');
          st.appendChild(statusBadge(row.status));
          tr.appendChild(st);
          tr.addEventListener('click', function () { openDetail(row.id); });
          body.appendChild(tr);
        });
        $('pr-doc-empty').hidden = items.length > 0;
        setText($('pr-page-info'), '전체 ' + res.data.total + '건 · ' + res.data.page + '쪽');
      });
    }

    var openDocId = null;

    function openDetail(id) {
      request('GET', '/api/purchase/pr/' + id).then(function (res) {
        if (!res) { return; }
        if (!res.ok) { say(errorMessage(res.data)); return; }
        openDocId = id;
        var doc = res.data.doc;
        setText($('pr-detail-title'), doc.doc_no);
        setText($('pr-detail-sub'), doc.doc_date + ' · ' + dash(doc.branch_name) + ' · '
          + dash(doc.location_name) + ' · 담당 ' + dash(doc.manager_name)
          + ' · 상태 ' + (STATUS_LABEL[doc.status] || doc.status)
          + (doc.split_from_no ? ' · ' + doc.split_from_no + ' 에서 갈라짐' : ''));

        var body = $('pr-detail-body');
        clear(body);
        (res.data.lines || []).forEach(function (line) {
          var tr = el('tr');
          cell(tr, String(line.line_no), 'p-col-no');
          cell(tr, line.code);
          cell(tr, line.name);
          cell(tr, qtyText(line.qty), 'num');
          cell(tr, qtyText(line.taken_qty), 'num');
          cell(tr, qtyText(line.remaining), 'num');
          cell(tr, money(line.amount), 'num');
          var st = el('td');
          st.appendChild(el('span', 'badge', line.status === 'void' ? '취소'
            : (line.locked_at ? '잠김' : '미확인')));
          tr.appendChild(st);
          var actTd = el('td');
          if (!line.locked_at && line.status === 'unconfirmed') {
            var drop = el('button', 'btn btn--quiet btn--small', '줄 취소');
            drop.type = 'button';
            drop.addEventListener('click', function () {
              request('PATCH', '/api/purchase/pr/' + id,
                { lines: [{ id: line.id, remove: true }] }).then(function (r) {
                if (!r) { return; }
                if (!r.ok) { say(errorMessage(r.data)); return; }
                say('줄을 취소했습니다.');
                openDetail(id);
                loadDocs();
              });
            });
            actTd.appendChild(drop);
          }
          tr.appendChild(actTd);
          body.appendChild(tr);
        });

        setText($('pr-detail-note'), '합계(부가세 별도) ' + money(res.data.totals.supply)
          + ' 원 · 부가세 ' + money(res.data.totals.vat)
          + ' 원 · 합계 ' + money(res.data.totals.total) + ' 원');
        $('pr-detail').hidden = false;
      });
    }

    $('pr-detail-close').addEventListener('click', function () { $('pr-detail').hidden = true; });
    $('pr-detail-void').addEventListener('click', function () {
      if (openDocId === null) { return; }
      var reason = window.prompt('요청을 취소합니다. 사유를 적어 주세요 (감사 로그에 남습니다)', '');
      if (reason === null) { return; }
      request('POST', '/api/purchase/pr/' + openDocId + '/void', { reason: reason })
        .then(function (res) {
          if (!res) { return; }
          if (!res.ok) { say(errorMessage(res.data)); return; }
          say('요청 ' + res.data.doc_no + ' 을(를) 취소했습니다.');
          $('pr-detail').hidden = true;
          loadDocs();
        });
    });

    $('pr-q').addEventListener('change', function () { docPage = 1; loadDocs(); });
    $('pr-status').addEventListener('change', function () { docPage = 1; loadDocs(); });
    $('pr-mine').addEventListener('change', function () { docPage = 1; loadDocs(); });
    $('pr-prev').addEventListener('click', function () {
      if (docPage > 1) { docPage -= 1; loadDocs(); }
    });
    $('pr-next').addEventListener('click', function () { docPage += 1; loadDocs(); });

    /* ── 현황(사이클) — 전표 화면과 **같은 함수**가 그린다 ── */
    function loadCycle() {
      loadCycleInto({ head: 'pr-cycle-head', body: 'pr-cycle-body', info: 'pr-cycle-info' }, '');
    }

    initTabs('pr-tabs', {
      input: { panel: 'pr-input' },
      docs: { panel: 'pr-docs', onShow: loadDocs },
      status: { panel: 'pr-status-panel', onShow: loadCycle }
    });

    /* 머리말 액션은 **이 화면 안의 일**만 한다 — 다른 화면으로 가는 링크는 사이드바가 맡는다.
       「전표 보기」는 탭을 바꾸고, 「다시 작성」은 담은 줄을 비운다. */
    $('pr-goto-docs').addEventListener('click', function () { UI.showTab('pr-tabs', 'docs'); });
    $('pr-reset').addEventListener('click', function () {
      lines = [];
      renderLines();
      $('pr-memo').value = '';
      say('담은 줄을 비웠습니다.');
    });

    // 탭 전환은 패널 셋만 여닫는다 — 상세는 전표 탭에서 열린다.
    $('pr-date').value = today();
    renderLines();

    Promise.all([loadMaster('branches'), loadMaster('locations'), loadMaster('vendors')])
      .then(function (all) {
        fillSelect($('pr-branch'), all[0], 'id', 'name', '— 내 지점 —');
        fillSelect($('pr-location'), all[1], 'id', 'name', '— 지정 안 함 —');
        fillSelect($('pr-vendor'), all[2], 'id', 'name', '— 지정 안 함 —');
        fillSelect($('pick-vendor'), all[2], 'id', 'name', '— 전체 —');
      });
  }

  /* ══════════════════════════════════════════════════════
   * 화면 ② — 발주서 (시안 ImportModal · PO)
   * ══════════════════════════════════════════════════════ */

  function initPo() {
    var lines = [];        // [{src_line_id, code, name, ..., remaining, qty}]
    var groups = [];       // 묶는 기준(누른 순서)
    var docPage = 1;
    var openDocId = null;
    var openSentAt = null;

    function renderLines() {
      var body = $('po-line-body');
      clear(body);
      lines.forEach(function (line, index) {
        var tr = el('tr');
        cell(tr, String(index + 1), 'p-col-no');
        cell(tr, line.code, 'mono');
        var nameTd = cell(tr, line.name);
        nameTd.className = 'p-name p-cell-wide';
        if (line.spec) { nameTd.appendChild(el('span', 'p-sub', line.spec)); }

        /* 거래처 — 서버가 준 값을 그대로 보여 준다. 저장하면 이 값으로 전표가 나뉜다 (F-60) */
        cell(tr, dash(line.vendor_name));

        /* 출처 요청 — 전표번호는 mono, 잔량이 남아 있으면 주의(warn) 배지 */
        var srcTd = el('td');
        srcTd.appendChild(el('span', 'mono', line.doc_no));
        srcTd.appendChild(el('span', 'p-sub', dash(line.manager_name)));
        tr.appendChild(srcTd);

        var remainTd = el('td', 'num mono');
        if (Number(line.remaining || 0) > 0) {
          remainTd.appendChild(badge(qtyText(line.remaining), 'badge--warn'));
        } else {
          setText(remainTd, qtyText(line.remaining));
        }
        tr.appendChild(remainTd);

        var qtyTd = el('td', 'num');
        var qtyInput = document.createElement('input');
        qtyInput.type = 'number';
        qtyInput.min = '0.001';
        qtyInput.step = '0.001';
        qtyInput.className = 'p-in p-in--qty';
        qtyInput.value = String(line.qty);
        qtyInput.addEventListener('input', function () {
          line.qty = qtyInput.value;
          renderSum();
        });
        qtyTd.appendChild(qtyInput);
        tr.appendChild(qtyTd);

        cell(tr, money(line.price_in), 'num mono');
        var amount = line.price_in === null ? null : Number(line.price_in) * Number(line.qty || 0);
        cell(tr, amount === null || isNaN(amount) ? '—' : money(amount), 'num mono');

        var actTd = el('td');
        actTd.appendChild(UI.button('빼기', 'btn btn--quiet btn--small', function () {
          lines.splice(index, 1);
          renderLines();
        }));
        tr.appendChild(actTd);

        body.appendChild(tr);
      });
      $('po-line-empty').hidden = lines.length > 0;
      renderSum();
    }

    function renderSum() {
      var sum = 0;
      var qtySum = 0;
      lines.forEach(function (line) {
        var q = Number(line.qty || 0);
        if (!isNaN(q)) { qtySum += q; }
        if (line.price_in === null || line.price_in === undefined) { return; }
        var v = Number(line.price_in) * q;
        if (!isNaN(v)) { sum += v; }
      });
      setText($('po-sum'), '줄 ' + lines.length + '개 · 수량 ' + qtyText(qtySum));
      setText($('po-total'), money(sum));
      renderSplitPreview();
    }

    /**
     * 저장 단추 옆 **분할 미리보기** (F-60 ④).
     * 담은 줄의 `vendor_id` 는 불러오기 응답이 준 값이고, 화면은 그것으로 **세어 보여만** 준다 —
     * 실제로 나누는 판정은 서버가 저장할 때 다시 한다.
     * 머리 거래처 칸도 여기서 함께 맞춘다: 섞여 있으면 비우고 잠근다.
     */
    function renderSplitPreview() {
      var order = [];
      var byVendor = {};
      lines.forEach(function (line) {
        var key = String(line.vendor_id === undefined || line.vendor_id === null ? '' : line.vendor_id);
        if (!Object.prototype.hasOwnProperty.call(byVendor, key)) {
          byVendor[key] = { name: line.vendor_name, count: 0 };
          order.push(key);
        }
        byVendor[key].count += 1;
      });

      var preview = $('po-split-preview');
      var vendorSelect = $('po-vendor');
      var vendorHelp = $('po-vendor-help');
      if (order.length === 0) {
        setText(preview, '');
        vendorSelect.disabled = false;
        vendorHelp.hidden = true;
        setText(vendorHelp, '');
        return;
      }

      var parts = order.map(function (key) {
        return dash(byVendor[key].name) + '(' + byVendor[key].count + '줄)';
      });
      if (order.length === 1) {
        setText(preview, dash(byVendor[order[0]].name) + ' · 1장');
        vendorSelect.disabled = false;
        vendorHelp.hidden = true;
        setText(vendorHelp, '');
        /* 거래처가 하나뿐이면 머리 칸을 그 값으로 미리 채운다 (기존 동작 유지) */
        if (vendorSelect.value === '' && order[0] !== '') { vendorSelect.value = order[0]; }
        return;
      }
      setText(preview, '저장하면 거래처별 ' + order.length + '장: ' + parts.join(' · '));
      vendorSelect.value = '';
      vendorSelect.disabled = true;
      setText(vendorHelp, '거래처별로 나눠 저장됩니다');
      vendorHelp.hidden = false;
    }

    /* ── 불러오기 모달 (시안 ④ ImportModal) ───────────────
     * 좌 트리(묶음) / 우 줄 표 2단. 25단계 전에는 트리 안에 줄이 섞여 있어
     * 층이 깊어질수록 줄이 오른쪽으로 밀려 이름이 잘렸다.
     * 부르는 API·질의 문자열은 그대로다 — 배치만 바꾼다.
     */
    var pickedLines = {};
    var openLeaf = null;
    var openNode = null;
    /* 트리 묶음 노드의 체크 상태를 다시 맞추려고 들고 있는 목록 — [{input, lines}] (F-60) */
    var nodeChecks = [];

    function syncLoadInfo() {
      setText($('load-info'), '고른 줄 ' + Object.keys(pickedLines).length + '개');
    }

    /**
     * 담은 줄 하나의 모양. 거래처는 **서버가 준 값**을 그대로 옮긴다 —
     * 화면은 나중에 그것을 미리보기로 **보여만** 주고, 나누는 판정은 서버가 한다 (F-60).
     */
    function pickedFrom(line, qty) {
      return {
        src_line_id: line.line_id,
        code: line.code,
        name: line.name,
        spec: line.spec,
        doc_no: line.doc_no,
        manager_name: line.manager_name,
        price_in: line.price_in,
        remaining: line.remaining,
        vendor_id: line.doc_vendor_id === null ? line.item_vendor_id : line.doc_vendor_id,
        vendor_name: line.doc_vendor_id === null ? line.item_vendor_name : line.doc_vendor_name,
        qty: qty
      };
    }

    /** 묶음 노드 아래의 **모든 잎 줄** (층이 몇 겹이든 내려간다) */
    function leafLines(node) {
      if (node.lines) { return node.lines.slice(); }
      var out = [];
      (node.children || []).forEach(function (kid) {
        out = out.concat(leafLines(kid));
      });
      return out;
    }

    /** 담긴 줄 수에 따라 묶음 체크박스를 다시 맞춘다 — 하위 일부만 담겼으면 중간 상태 */
    function syncNodeChecks() {
      nodeChecks.forEach(function (node) {
        var on = 0;
        node.lines.forEach(function (line) {
          if (Object.prototype.hasOwnProperty.call(pickedLines, String(line.line_id))) { on += 1; }
        });
        node.input.checked = on > 0 && on === node.lines.length;
        node.input.indeterminate = on > 0 && on < node.lines.length;
      });
    }

    /** 오른쪽 표 — 고른 묶음의 줄만 그린다 */
    function drawLines(node) {
      var body = $('load-lines-body');
      clear(body);
      var rows = (node && node.lines) ? node.lines : [];
      openNode = node;
      setText($('load-lines-title'), node ? node.label : '줄');
      $('load-lines-empty').hidden = rows.length > 0;
      setText($('load-lines-empty'), node ? '이 묶음에는 줄이 없습니다.' : '왼쪽에서 묶음을 고르세요.');

      rows.forEach(function (line) {
        var key = String(line.line_id);
        var tr = el('tr');

        var checkTd = el('td', 'p-col-check p-pick');
        var check = document.createElement('input');
        check.type = 'checkbox';
        check.checked = Object.prototype.hasOwnProperty.call(pickedLines, key);
        checkTd.appendChild(check);
        tr.appendChild(checkTd);

        cell(tr, line.code, 'mono');
        var nameTd = cell(tr, line.name);
        nameTd.className = 'p-name p-cell-wide';
        if (line.spec) { nameTd.appendChild(el('span', 'p-sub', line.spec)); }

        var srcTd = el('td');
        srcTd.appendChild(el('span', 'mono', line.doc_no));
        srcTd.appendChild(el('span', 'p-sub', dash(line.manager_name)));
        tr.appendChild(srcTd);

        cell(tr, qtyText(line.remaining), 'num mono');

        var qtyTd = el('td', 'num');
        var qtyInput = document.createElement('input');
        qtyInput.type = 'number';
        qtyInput.min = '0.001';
        qtyInput.step = '0.001';
        qtyInput.className = 'p-in p-in--qty';
        qtyInput.value = Object.prototype.hasOwnProperty.call(pickedLines, key)
          ? pickedLines[key].qty : qtyText(line.remaining);
        qtyTd.appendChild(qtyInput);
        tr.appendChild(qtyTd);

        function sync() {
          if (check.checked) {
            pickedLines[key] = pickedFrom(line, qtyInput.value);
          } else {
            delete pickedLines[key];
          }
          syncLoadInfo();
          syncNodeChecks();
        }
        check.addEventListener('change', sync);
        qtyInput.addEventListener('input', function () { if (check.checked) { sync(); } });

        body.appendChild(tr);
      });
    }

    /**
     * 왼쪽 트리 — 층마다 들여쓰고, 줄이 달린 마디를 누르면 오른쪽이 바뀐다.
     *
     * F-60: **모든 묶음 노드**에 체크박스가 붙는다. 체크하면 그 아래 잎 줄을 전부
     * **잔량 그대로** 담고, 해제하면 전부 뺀다. 체크박스는 펼치기 단추의 **형제**라
     * 두 클릭이 서로 전파되지 않는다(단추 안에 넣으면 누를 때마다 접혔다 펴진다).
     */
    function drawTree(nodes, container) {
      nodes.forEach(function (node) {
        var box = el('div', 'p-tree-group');
        var row = el('div', 'p-tree-row');

        var check = document.createElement('input');
        check.type = 'checkbox';
        check.className = 'p-tree-check';
        check.setAttribute('aria-label', node.label + ' 묶음 전체 담기');
        var mine = leafLines(node);
        nodeChecks.push({ input: check, lines: mine });
        check.addEventListener('click', function (event) { event.stopPropagation(); });
        check.addEventListener('change', function () {
          mine.forEach(function (line) {
            var key = String(line.line_id);
            if (check.checked) {
              pickedLines[key] = pickedFrom(line, qtyText(line.remaining));
            } else {
              delete pickedLines[key];
            }
          });
          if (openNode) { drawLines(openNode); }
          syncLoadInfo();
          syncNodeChecks();
        });
        row.appendChild(check);

        var head = el('button', 'p-tree-head');
        head.type = 'button';
        head.appendChild(el('span', 'p-tree-name', node.label));
        head.appendChild(badge('줄 ' + node.line_count, 'badge--off'));
        head.appendChild(el('span', 'p-tree-sum', '잔량 ' + qtyText(node.qty)));
        row.appendChild(head);
        box.appendChild(row);

        if (node.children) {
          var kids = el('div', 'p-tree-children');
          drawTree(node.children, kids);
          box.appendChild(kids);
          head.addEventListener('click', function () {
            kids.hidden = !kids.hidden;
            head.setAttribute('aria-expanded', kids.hidden ? 'false' : 'true');
          });
          head.setAttribute('aria-expanded', 'true');
        } else {
          head.addEventListener('click', function () {
            if (openLeaf) { openLeaf.classList.remove('is-on'); }
            head.classList.add('is-on');
            openLeaf = head;
            drawLines(node);
          });
        }
        container.appendChild(box);
      });
    }

    function runLoad() {
      var params = '?group=' + encodeURIComponent(groups.join(','));
      if ($('load-q').value.trim() !== '') { params += '&q=' + encodeURIComponent($('load-q').value.trim()); }
      if ($('load-from').value !== '') { params += '&from=' + encodeURIComponent($('load-from').value); }
      if ($('load-to').value !== '') { params += '&to=' + encodeURIComponent($('load-to').value); }

      request('GET', '/api/purchase/pr/load' + params).then(function (res) {
        if (!res) { return; }
        if (!res.ok) { say(errorMessage(res.data)); return; }
        var tree = $('load-tree');
        clear(tree);
        openLeaf = null;
        openNode = null;
        nodeChecks = [];
        var nodes = res.data.groups || [];
        drawTree(nodes, tree);
        $('load-tree-empty').hidden = nodes.length > 0;
        drawLines(null);
        syncLoadInfo();
        syncNodeChecks();
      });
    }

    /* 묶는 기준 칩 — **누른 순서**가 층이다. 순서는 칩 안의 번호로 보인다. */
    var GROUP_MARKS = ['\u2460', '\u2461', '\u2462'];
    var groupNav = $('load-groups');

    function renderGroupMarks() {
      Array.prototype.forEach.call(groupNav.querySelectorAll('.chip'), function (chip) {
        var at = groups.indexOf(chip.getAttribute('data-group'));
        var mark = chip.querySelector('b');
        if (at < 0) {
          chip.classList.remove('is-on');
          if (mark) { chip.removeChild(mark); }
          return;
        }
        chip.classList.add('is-on');
        if (!mark) {
          mark = el('b');
          chip.insertBefore(mark, chip.firstChild);
        }
        setText(mark, GROUP_MARKS[at] || String(at + 1));
      });
    }

    Array.prototype.forEach.call(groupNav.querySelectorAll('.chip'), function (chip) {
      chip.addEventListener('click', function () {
        var key = chip.getAttribute('data-group');
        var at = groups.indexOf(key);
        if (at >= 0) {
          groups.splice(at, 1);
        } else if (groups.length < 3) {
          groups.push(key);
        } else {
          say('묶는 기준은 세 개까지입니다.');
          return;
        }
        renderGroupMarks();
      });
    });

    $('po-load').addEventListener('click', function () {
      pickedLines = {};
      if (groups.length === 0) { groups = ['vendor']; }
      renderGroupMarks();
      openModal('load-modal');
      runLoad();
    });
    $('load-run').addEventListener('click', runLoad);
    $('load-add').addEventListener('click', function () {
      Object.keys(pickedLines).forEach(function (key) {
        var found = false;
        lines.forEach(function (line) {
          if (String(line.src_line_id) === key) { found = true; }
        });
        if (!found) { lines.push(pickedLines[key]); }
      });
      pickedLines = {};
      renderLines();
      closeModal('load-modal');
    });

    /* ── 저장 ──────────────────────────────────────────── */
    $('po-input').addEventListener('submit', function (event) {
      event.preventDefault();
      if (lines.length === 0) { say('발주요청에서 줄을 담아 주세요.'); return; }
      if ($('po-location').value === '') { say('입고 장소를 골라 주세요.'); return; }
      var payload = {
        doc_date: $('po-date').value,
        to_location_id: Number($('po-location').value),
        // F-60 — 화면은 **언제나** 나눠 달라고 한다. 거래처가 하나면 1장이 나온다.
        split: true,
        lines: []
      };
      if ($('po-vendor').value !== '') { payload.vendor_id = Number($('po-vendor').value); }
      if ($('po-memo').value !== '') { payload.memo = $('po-memo').value; }
      lines.forEach(function (line) {
        // 여기도 **수량만** 보낸다 (R-66). 거래처는 서버가 줄에서 다시 판정한다.
        payload.lines.push({ src_line_id: line.src_line_id, qty: String(line.qty) });
      });
      request('POST', '/api/purchase/po', payload).then(function (res) {
        if (!res) { return; }
        if (!res.ok) { say(errorMessage(res.data)); return; }
        var docs = Array.isArray(res.data.docs) ? res.data.docs : [];
        var nos = docs.map(function (doc) { return doc.doc_no; });
        say('발주서 ' + docs.length + '장을 만들었습니다: ' + nos.join(', '));
        lines = [];
        renderLines();
        loadDocs();
        if (docs.length > 0) { openDetail(docs[0].id); }
      });
    });

    /* ── 전표 목록·상세 ────────────────────────────────── */
    function loadDocs() {
      var params = '?page=' + docPage;
      if ($('po-q').value.trim() !== '') { params += '&q=' + encodeURIComponent($('po-q').value.trim()); }
      if ($('po-sent').value !== '') { params += '&sent=' + encodeURIComponent($('po-sent').value); }

      request('GET', '/api/purchase/po' + params).then(function (res) {
        if (!res || !res.ok) { return; }
        var body = $('po-doc-body');
        clear(body);
        var items = Array.isArray(res.data.items) ? res.data.items : [];
        items.forEach(function (row) {
          var tr = el('tr', 'p-row-click');
          var noTd = cell(tr, row.doc_no, 'mono');
          noTd.appendChild(el('span', 'p-sub', row.doc_date));
          cell(tr, dash(row.vendor_name));
          cell(tr, dash(row.location_name));
          cell(tr, dash(row.manager_name));
          cell(tr, String(row.line_count), 'num mono');
          cell(tr, money(row.amount), 'num mono');
          var st = el('td');
          st.appendChild(statusBadge(row.status));
          tr.appendChild(st);
          cell(tr, row.sent_at ? row.sent_at.slice(0, 16).replace('T', ' ') : '—');
          tr.addEventListener('click', function () { openDetail(row.id); });
          body.appendChild(tr);
        });
        $('po-doc-empty').hidden = items.length > 0;
        setText($('po-page-info'), '전체 ' + res.data.total + '건 · ' + res.data.page + '쪽');
      });
    }

    function openDetail(id) {
      request('GET', '/api/purchase/po/' + id).then(function (res) {
        if (!res) { return; }
        if (!res.ok) { say(errorMessage(res.data)); return; }
        openDocId = id;
        var doc = res.data.doc;
        openSentAt = doc.sent_at;
        setText($('po-detail-title'), doc.doc_no);
        setText($('po-detail-sub'), doc.doc_date + ' · ' + dash(doc.vendor_name) + ' · '
          + dash(doc.location_name) + ' · 담당 ' + dash(doc.manager_name)
          + (doc.sent_at ? ' · 보냄 ' + doc.sent_at + ' (' + dash(doc.sent_by_name) + ')'
                         : ' · 아직 보내지 않음'));
        /* 인쇄 링크는 **전표를 고른 뒤에만** 나온다 — 더미 href 를 두지 않는다 */
        $('po-print').href = '/purchase/po/' + id + '/print';
        $('po-print').hidden = false;

        var head = res.data.header || {};
        $('poh-receiver').value = head.receiver || '';
        $('poh-cc').value = head.cc || '';
        $('poh-tel').value = head.tel || '';
        $('poh-delivery').value = head.delivery_location || '';
        $('poh-due').value = head.due_date || '';
        $('poh-memo').value = head.memo || '';
        $('poh-reason-field').hidden = !doc.sent_at;

        var body = $('po-detail-body');
        clear(body);
        (res.data.lines || []).forEach(function (line) {
          var tr = el('tr');
          cell(tr, String(line.line_no), 'p-col-no');
          cell(tr, line.code, 'mono');
          cell(tr, line.name, 'p-name p-cell-wide');
          cell(tr, dash(line.src_doc_no), 'mono');
          cell(tr, qtyText(line.qty), 'num mono');
          cell(tr, money(line.unit_price), 'num mono');
          cell(tr, money(line.amount), 'num mono');
          body.appendChild(tr);
        });
        setText($('po-detail-totals'), '공급가액 ' + money(res.data.totals.supply)
          + ' · 부가세 ' + money(res.data.totals.vat)
          + ' · 합계 ' + money(res.data.totals.total));

        $('po-send').hidden = !res.data.can_send;
        $('po-void').hidden = !res.data.can_write;
        $('po-head-box').hidden = !res.data.can_write;
        $('po-detail').hidden = false;
      });
    }

    $('po-detail-close').addEventListener('click', function () {
      $('po-detail').hidden = true;
      $('po-print').hidden = true;
    });
    $('po-goto-docs').addEventListener('click', function () { UI.showTab('po-tabs', 'docs'); });

    $('po-head-form').addEventListener('submit', function (event) {
      event.preventDefault();
      if (openDocId === null) { return; }
      var payload = {
        header: {
          receiver: $('poh-receiver').value,
          cc: $('poh-cc').value,
          tel: $('poh-tel').value,
          delivery_location: $('poh-delivery').value,
          due_date: $('poh-due').value,
          memo: $('poh-memo').value
        }
      };
      if (openSentAt) { payload.reason = $('poh-reason').value; }
      request('PATCH', '/api/purchase/po/' + openDocId, payload).then(function (res) {
        if (!res) { return; }
        if (!res.ok) { say(errorMessage(res.data)); return; }
        say('발주서 머리를 고쳤습니다.');
        openDetail(openDocId);
      });
    });

    $('po-send').addEventListener('click', function () {
      if (openDocId === null) { return; }
      var payload = {};
      if (openSentAt) {
        var reason = window.prompt('이미 보낸 발주서입니다. 다시 보내는 사유를 적어 주세요 (필수)', '');
        if (reason === null || reason.trim() === '') { return; }
        payload.reason = reason.trim();
      }
      request('POST', '/api/purchase/po/' + openDocId + '/send', payload).then(function (res) {
        if (!res) { return; }
        if (!res.ok) { say(errorMessage(res.data)); return; }
        say('발주서를 「보냄」으로 표시했습니다 · ' + res.data.sent_at);
        openDetail(openDocId);
        loadDocs();
      });
    });

    $('po-void').addEventListener('click', function () {
      if (openDocId === null) { return; }
      var reason = window.prompt('발주서를 취소합니다. 사유를 적어 주세요 (보낸 뒤에는 필수)', '');
      if (reason === null) { return; }
      request('POST', '/api/purchase/po/' + openDocId + '/void', { reason: reason })
        .then(function (res) {
          if (!res) { return; }
          if (!res.ok) { say(errorMessage(res.data)); return; }
          say('발주서 ' + res.data.doc_no + ' 을(를) 취소했습니다. 요청 잔량이 되돌아갑니다.');
          $('po-detail').hidden = true;
          loadDocs();
        });
    });

    $('po-q').addEventListener('change', function () { docPage = 1; loadDocs(); });
    $('po-sent').addEventListener('change', function () { docPage = 1; loadDocs(); });
    $('po-prev').addEventListener('click', function () {
      if (docPage > 1) { docPage -= 1; loadDocs(); }
    });
    $('po-next').addEventListener('click', function () { docPage += 1; loadDocs(); });

    initTabs('po-tabs', {
      input: { panel: 'po-input' },
      docs: { panel: 'po-docs', onShow: loadDocs }
    });

    $('po-date').value = today();
    renderLines();
    Promise.all([loadMaster('locations'), loadMaster('vendors')]).then(function (all) {
      fillSelect($('po-location'), all[0], 'id', 'name', '— 입고 장소 —');
      fillSelect($('po-vendor'), all[1], 'id', 'name', '— 줄에서 자동 —');
    });
  }

  /* ══════════════════════════════════════════════════════
   * 화면 ③ — 전표 · 사이클 추적 (시안 Docs · Cycle)
   * ══════════════════════════════════════════════════════ */

  function initDocs() {
    var page = 1;
    var openRow = null;

    /* ── 연결 전표 aside (시안 ⑧ Docs 우측) ───────────────
     * 자료는 기존 `GET /api/purchase/cycle?pr=…|po=…` **하나**뿐이다 (새 API 없음).
     * 요청 줄들을 받아 ① 요청 ② 발주서 ③ 입고 로 묶어 시간 순으로 세운다.
     */
    var FLOW_STEP = {
      pr: { label: '발주요청', kind: '' },
      po: { label: '발주서', kind: 'badge--accent' },
      gr: { label: '입고', kind: 'badge--ok' }
    };

    function flowItem(type, docNo, meta, href) {
      var li = el('li', 'p-flow__item');
      var head = el('div', 'p-flow__head');
      var step = FLOW_STEP[type] || FLOW_STEP.pr;
      head.appendChild(badge(step.label, step.kind));
      if (href) {
        var link = el('a', 'mono', docNo);
        link.href = href;
        head.appendChild(link);
      } else {
        head.appendChild(el('span', 'mono', docNo));
      }
      li.appendChild(head);
      li.appendChild(el('div', 'p-flow__meta', meta));
      return li;
    }

    function loadFlow(type, row) {
      var flow = $('docs-flow');
      var items = $('docs-flow-items');
      clear(flow);
      clear(items);
      $('docs-flow-empty').hidden = true;
      $('docs-flow-items-wrap').hidden = false;

      request('GET', '/api/purchase/cycle?' + (type === 'po' ? 'po=' : 'pr=') + row.id)
        .then(function (res) {
          if (!res || !res.ok) { return; }
          var rows = res.data.rows || [];
          if (rows.length === 0) {
            $('docs-flow-empty').hidden = false;
            $('docs-flow-items-wrap').hidden = true;
            setText($('docs-flow-empty'), '연결된 전표가 없습니다.');
            return;
          }

          var first = rows[0];
          flow.appendChild(flowItem('pr', first.pr_doc_no,
            first.pr_date + ' · ' + dash(first.requester) + ' · ' + dash(first.location_name),
            '/purchase/pr?doc=' + first.pr_doc_id));

          var seen = {};
          ['orders', 'receipts'].forEach(function (bucket) {
            rows.forEach(function (r) {
              (r[bucket] || []).forEach(function (d) {
                var key = d.doc_type + ':' + d.doc_no;
                if (seen[key]) { return; }
                seen[key] = true;
                var meta = d.doc_date + ' · ' + dash(d.manager_name)
                  + (d.sent_at ? ' · 보냄' : '');
                flow.appendChild(flowItem(d.doc_type, d.doc_no, meta,
                  d.doc_type === 'po' ? '/purchase/po?doc=' + d.doc_id : null));
              });
            });
          });

          rows.forEach(function (r) {
            var tr = el('tr');
            var nameTd = cell(tr, r.name);
            nameTd.className = 'p-name';
            nameTd.appendChild(el('span', 'p-sub mono', r.code));
            cell(tr, qtyText(r.qty), 'num mono');
            cell(tr, qtyText(r.ordered_qty), 'num mono');
            cell(tr, qtyText(r.received_qty), 'num mono');
            items.appendChild(tr);
          });
        });
    }

    function loadList() {
      var type = $('docs-type').value;
      var params = '?page=' + page;
      if ($('docs-q').value.trim() !== '') { params += '&q=' + encodeURIComponent($('docs-q').value.trim()); }
      if ($('docs-from').value !== '') { params += '&from=' + encodeURIComponent($('docs-from').value); }
      if ($('docs-to').value !== '') { params += '&to=' + encodeURIComponent($('docs-to').value); }

      request('GET', '/api/purchase/' + type + params).then(function (res) {
        if (!res || !res.ok) { return; }
        var body = $('docs-body');
        clear(body);
        var items = Array.isArray(res.data.items) ? res.data.items : [];
        items.forEach(function (row) {
          var tr = el('tr', 'p-row-click');
          var noTd = cell(tr, row.doc_no, 'mono');
          noTd.appendChild(el('span', 'p-sub', row.doc_date));
          var kindTd = el('td');
          kindTd.appendChild(row.doc_type === 'pr'
            ? badge('발주요청', '')
            : badge('발주서', 'badge--accent'));
          tr.appendChild(kindTd);
          cell(tr, dash(row.vendor_name));
          cell(tr, dash(row.branch_name)).appendChild(el('span', 'p-sub', dash(row.location_name)));
          cell(tr, dash(row.manager_name));
          cell(tr, String(row.line_count), 'num mono');
          cell(tr, money(row.amount), 'num mono');
          var st = el('td');
          st.appendChild(statusBadge(row.status));
          tr.appendChild(st);
          tr.addEventListener('click', function () {
            if (openRow) { openRow.classList.remove('is-on'); }
            tr.classList.add('is-on');
            openRow = tr;
            loadFlow(type, row);
          });
          body.appendChild(tr);
        });
        $('docs-empty').hidden = items.length > 0;
        setText($('docs-page-info'), '전체 ' + res.data.total + '건 · ' + res.data.page + '쪽');
      });
    }

    function loadCycle() {
      var params = '';
      var joiner = '?';
      if ($('cycle-from').value !== '') {
        params += joiner + 'from=' + encodeURIComponent($('cycle-from').value);
        joiner = '&';
      }
      if ($('cycle-to').value !== '') {
        params += joiner + 'to=' + encodeURIComponent($('cycle-to').value);
      }
      loadCycleInto({ head: 'cycle-head', body: 'cycle-body', info: 'cycle-info' }, params);
    }

    $('docs-type').addEventListener('change', function () { page = 1; loadList(); });
    $('docs-q').addEventListener('change', function () { page = 1; loadList(); });
    $('docs-from').addEventListener('change', function () { page = 1; loadList(); });
    $('docs-to').addEventListener('change', function () { page = 1; loadList(); });
    $('docs-prev').addEventListener('click', function () {
      if (page > 1) { page -= 1; loadList(); }
    });
    $('docs-next').addEventListener('click', function () { page += 1; loadList(); });
    $('cycle-run').addEventListener('click', loadCycle);

    initTabs('docs-tabs', {
      all: { panel: 'docs-all', onShow: loadList },
      cycle: { panel: 'docs-cycle', onShow: loadCycle }
    });

    loadList();
  }

  /* ══════════════════════════════════════════════════════
   * 부트 — `/api/me` 로 CSRF 토큰을 받은 뒤 화면을 고른다
   * ══════════════════════════════════════════════════════ */

  function boot() {
    fetch('/api/me', { credentials: 'same-origin', cache: 'no-store' })
      .then(function (response) {
        if (response.status === 401) {
          window.location.href = '/login';
          return null;
        }
        return UI.readJson(response);
      })
      .then(function (me) {
        if (me === null) { return; }
        apiClient.setCsrf(me.csrf_token);
        /* 담당자 칸은 **서버가 준 이름**(`/api/me`)을 그대로 넣는다 — 읽기 전용이고,
           저장 본문에 실리지 않는다(요청의 담당자는 서버가 세션에서 정한다). */
        var managerBox = $('pr-manager');
        if (managerBox) { managerBox.value = typeof me.username === 'string' ? me.username : ''; }
        if ($('pr-tabs')) { initPr(); }
        if ($('po-tabs')) { initPo(); }
        if ($('docs-tabs')) { initDocs(); }
      })
      .catch(function () { say(UI.GENERIC_ERROR); });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
}());
