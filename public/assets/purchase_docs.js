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

  /* F-62 — 로그인 사용자. **이름**은 `/api/me` 가 주고(그 창구는 내부 id 를 주지 않는다),
     **번호**는 발주서 화면이 담당자 목록에서 같은 이름을 찾아 맞춘다. 둘 다 표시·미리보기용
     이며 저장 본문에 실리지 않는다 — 직접 줄의 담당자는 서버가 세션에서 정한다. */
  var meUser = { id: null, name: '' };

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

    /* ── 품목 찾기 모달 ───────────────────────────────────
     * F-61 — 창을 그리고 다루는 코드는 이제 `PurchaseUI.itemPicker` **한 벌**이다
     * (발주서 화면이 같은 창을 연다). 이 화면이 넘기는 것은 「담았을 때 무엇을 할까」뿐이다.
     */
    var picker = UI.itemPicker({
      request: request,
      onAdd: function (chosen) {
        chosen.forEach(function (one) { addItem(one.row, one.qty); });
        renderLines();
      }
    });

    $('pr-pick').addEventListener('click', picker.open);

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
    /*
     * 담은 줄 — F-61 부터 **세 갈래**다 (`source`):
     *   'request'  발주요청에서 불러온 줄 (`src_line_id`, 잔량이 있다)
     *   'direct'   품목 찾기로 바로 담은 줄 (`item_id`)
     *   'suggest'  부족 산출에서 담은 줄 (`item_id` — 서버에는 'direct' 와 같은 줄이다)
     * 뒤 둘은 요청 잔량이 없으므로 잔량 칸이 「—」 다.
     */
    var lines = [];        // [{source, src_line_id|item_id, code, name, …, location_id, qty}]
    var docPage = 1;
    var openDocId = null;
    var openSentAt = null;

    /**
     * 이 줄이 실제로 들어갈 **입고 장소** — 머리값이 있으면 그것, 없으면 줄이 들고 온 장소다.
     * 서버(`purchase_po_create.php`)의 규칙과 같은 문장이고, 화면은 그것을 **보여만** 준다.
     */
    function lineLocation(line) {
      var head = $('po-location');
      if (head && head.value !== '') {
        return {
          id: Number(head.value),
          name: head.options[head.selectedIndex] ? head.options[head.selectedIndex].text : ''
        };
      }
      if (line.location_id === null || line.location_id === undefined) { return null; }
      return { id: line.location_id, name: line.location_name };
    }

    /**
     * 이 줄의 **담당자** — 머리값이 있으면 그것, 없으면 줄이 들고 온 담당자다 (F-62 ②).
     * 위 `lineLocation` 과 같은 문장이고, 서버(`purchase_po_create.php`)의 규칙과도 같다 —
     * 화면은 그것을 **보여만** 준다.
     */
    function lineManager(line) {
      var head = $('po-manager');
      if (head && head.value !== '') {
        return {
          id: Number(head.value),
          name: head.options[head.selectedIndex] ? head.options[head.selectedIndex].text : ''
        };
      }
      var noId = (line.manager_id === null || line.manager_id === undefined);
      var noName = (line.manager_name === null || line.manager_name === undefined);
      if (noId && noName) { return null; }
      return { id: noId ? null : line.manager_id, name: line.manager_name };
    }

    /**
     * 미리보기 묶음에 쓸 담당자 조각 — **이름을 먼저** 쓴다 (독립 검토 지적 ②2, 2026-09-17).
     *
     * 왜 번호가 아니라 이름인가: 담당자 이름이 오는 세 곳(`pr_load` 의 `manager_name` ·
     * `/api/me` 의 `username` · 담당자 목록의 `name`)이 **모두 `users.username` 한 열**이고
     * 그 열은 NOT NULL UNIQUE 다. 그래서 이름으로 묶는 것은 서버가 번호로 묶는 것과 **같은
     * 결과**다. 반면 번호는 화면이 늘 알 수 있는 값이 아니다 — 직접 줄의 담당자 번호는
     * 담당자 목록에서 이름을 찾아 맞추므로, 구매 역할이 없는 계정(목록에 없다)으로 로그인하면
     * `null` 로 남는다. 그때 번호로 묶으면 같은 사람이 두 묶음으로 갈려 미리보기가 「2장」이라고
     * 말하고 서버는 1장을 저장한다(라벨도 똑같이 보인다). 이름으로 묶으면 그 어긋남이 없다.
     *
     * ★ 주의 — **이 함수는 `manager_name` 이 유일하다는 것에 기대고 있다.** 지금 그 값은
     *   `users.username`(NOT NULL UNIQUE, 소문자·숫자만)이라 참이다. 앞으로 사람 이름 칸
     *   (`display_name` 같은 것)을 만들어 `manager_name` 이 **사람 이름**을 담게 되면 동명이인이
     *   한 묶음으로 합쳐진다. 그때는 이 함수를 **번호 기준으로 되돌리고**, 대신 직접 줄의 담당자
     *   번호를 화면이 확실히 알 수 있게 창구(`/api/me` 또는 담당자 목록)가 번호를 주도록 고쳐야
     *   한다. 사람 이름 칸은 별건 후보다(STAGES 「후보(사람 이름)」).
     */
    function managerKey(who) {
      if (!who) { return ''; }
      if (who.name !== null && who.name !== undefined && String(who.name) !== '') {
        return 'n:' + String(who.name);
      }
      return 'i:' + String(who.id);
    }

    /** 출처 칸 — 요청 줄은 PR 번호(+요청자), 직접 줄은 「직접」, 부족 산출은 「부족 산출」 */
    var SOURCE_LABEL = { direct: '직접', suggest: '부족 산출' };

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

        /* 입고 장소 — 머리값이 있으면 그것, 없으면 줄의 장소 (F-61 ②) */
        var where = lineLocation(line);
        var locTd = cell(tr, where ? dash(where.name) : '—');
        if (!where) { locTd.className = 'p-short'; }

        /* 출처 — 요청 줄은 PR 번호(+요청자), 직접·부족 산출은 그 말 그대로 (F-61 ⑤) */
        var srcTd = el('td');
        if (line.source === 'request') {
          srcTd.appendChild(el('span', 'mono', line.doc_no));
          srcTd.appendChild(el('span', 'p-sub', dash(line.manager_name)));
        } else {
          srcTd.appendChild(badge(SOURCE_LABEL[line.source] || '직접', 'badge--accent'));
          if (line.note) { srcTd.appendChild(el('span', 'p-sub', line.note)); }
        }
        tr.appendChild(srcTd);

        var remainTd = el('td', 'num mono');
        if (line.source !== 'request') {
          /* 직접 줄에는 되짚을 요청 잔량이 **없다** — 0 이 아니라 「없음」 이다 */
          setText(remainTd, '—');
        } else if (Number(line.remaining || 0) > 0) {
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
     * 저장 단추 옆 **분할 미리보기** (F-60 ④ · F-61 ⑤ · F-62 ⑤).
     * 담은 줄의 `vendor_id` 는 서버가 준 값이고, 장소·담당자는 머리값 또는 줄의 값이다.
     * 화면은 그 둘로 **세어 보여만** 준다 — 실제로 나누는 판정은 서버가 저장할 때 다시 한다.
     * 나누는 열쇠가 (거래처, 입고 장소, 담당자) 세 축이므로 미리보기도 세 축을 센다.
     * 머리 거래처 칸도 여기서 함께 맞춘다: 섞여 있으면 비우고 잠근다.
     */
    function renderSplitPreview() {
      var order = [];
      var byKey = {};
      var vendorOrder = [];
      lines.forEach(function (line) {
        var vendor = String(line.vendor_id === undefined || line.vendor_id === null ? '' : line.vendor_id);
        var where = lineLocation(line);
        var who = lineManager(line);
        var key = vendor + ':' + String(where ? where.id : '') + ':' + managerKey(who);
        if (!Object.prototype.hasOwnProperty.call(byKey, key)) {
          byKey[key] = {
            vendor: vendor,
            vendor_name: line.vendor_name,
            location_name: where ? where.name : null,
            manager_name: who ? who.name : null,
            /* 축 판정이 다시 계산하지 않게 묶음 열쇠 조각을 그대로 적어 둔다 */
            manager_key: managerKey(who),
            count: 0
          };
          order.push(key);
        }
        byKey[key].count += 1;
        if (vendorOrder.indexOf(vendor) < 0) { vendorOrder.push(vendor); }
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

      /*
       * 문구는 **갈리는 축만** 말한다 (F-62 ⑤ · F-61 ⑤ · F-60 ④).
       * 장소가 한 곳뿐이면 장소를, 담당자가 한 사람뿐이면 담당자를 말하지 않는다 —
       * 그래서 장소·담당자가 하나씩인 저장의 문장은 F-60 문장 그대로이고, 담당자만 하나인
       * 저장의 문장은 F-61 문장 그대로다. 같은 값을 두 번 읽게 하지 않는 것이 이 갈래의 이유다.
       */
      var places = {};
      var mgrs = {};
      /* 「축이 갈렸나」 도 묶음과 **같은 기준**으로 센다 — 두 기준이 다르면 미리보기의 장 수와
         라벨이 어긋난다(독립 검토 지적 ②2). 담당자는 `managerKey` 한 곳에서만 정한다. */
      order.forEach(function (key) {
        places[String(byKey[key].location_name)] = true;
        mgrs[byKey[key].manager_key] = true;
      });
      var manyPlaces = Object.keys(places).length > 1;
      var manyMgrs = Object.keys(mgrs).length > 1;

      /** 갈리는 축의 이름을 `×` 로 잇는다 — 라벨도 같은 축만 `·` 로 잇는다 */
      var axisLabel = '거래처';
      if (manyPlaces) { axisLabel += '×입고장소'; }
      if (manyMgrs) { axisLabel += '×담당자'; }

      var label = function (group) {
        var text = dash(group.vendor_name);
        if (manyPlaces) { text += '·' + dash(group.location_name); }
        if (manyMgrs) { text += '·' + dash(group.manager_name); }
        return text;
      };
      var parts = order.map(function (key) {
        return label(byKey[key]) + '(' + byKey[key].count + '줄)';
      });
      if (order.length === 1) {
        /* 묶음이 하나면 갈리는 축이 없다 — 축 이름을 붙이지 않는다 (F-61 과 같은 문장) */
        setText(preview, label(byKey[order[0]]) + ' · 1장');
      } else {
        setText(preview, '저장하면 ' + axisLabel + '별 ' + order.length + '장: ' + parts.join(' · '));
      }

      if (vendorOrder.length === 1) {
        vendorSelect.disabled = false;
        vendorHelp.hidden = true;
        setText(vendorHelp, '');
        /* 거래처가 하나뿐이면 머리 칸을 그 값으로 미리 채운다 (기존 동작 유지) */
        if (vendorSelect.value === '' && vendorOrder[0] !== '') { vendorSelect.value = vendorOrder[0]; }
        return;
      }
      vendorSelect.value = '';
      vendorSelect.disabled = true;
      setText(vendorHelp, '거래처별로 나눠 저장됩니다');
      vendorHelp.hidden = false;
    }

    /* ── 불러오기 모달 (시안 ④ ImportModal) ───────────────
     * 좌 트리(묶음) / 우 줄 표 2단. 25단계 전에는 트리 안에 줄이 섞여 있어
     * 층이 깊어질수록 줄이 오른쪽으로 밀려 이름이 잘렸다.
     *
     * F-63 — 칩바·트리·묶음 체크(중간 상태)·담을 수량을 그리는 코드는 `UI.loadPicker`
     * **한 벌**로 옮겼다. 입고 화면의 「발주서 불러오기」 창이 같은 함수를 부른다
     * (F-61 이 `itemPicker()` 를 뽑은 것과 같은 방식). 이 파일에 남은 것은 이 화면만의
     * 두 가지 — **줄 표의 열**과 **담은 뒤 무엇을 만드는지**다.
     * 부르는 API·질의 문자열·겉모양·문구는 그대로다.
     */

    /**
     * 담은 줄 하나의 모양. 거래처는 **서버가 준 값**을 그대로 옮긴다 —
     * 화면은 나중에 그것을 미리보기로 **보여만** 주고, 나누는 판정은 서버가 한다 (F-60).
     */
    function pickedFrom(line, qty) {
      return {
        source: 'request',
        src_line_id: line.line_id,
        code: line.code,
        name: line.name,
        spec: line.spec,
        doc_no: line.doc_no,
        /* F-62 ② — 요청서의 담당자를 그대로 들고 온다(`GET /api/purchase/pr/load` 가
           두 칸 다 준다). 서버도 같은 값을 **자기가 다시** 읽는다 — 화면이 보낸 담당자를
           믿지 않는다(요청 줄의 담당자는 그 요청 전표가 정한다). */
        manager_id: line.manager_id,
        manager_name: line.manager_name,
        price_in: line.price_in,
        remaining: line.remaining,
        vendor_id: line.doc_vendor_id === null ? line.item_vendor_id : line.doc_vendor_id,
        vendor_name: line.doc_vendor_id === null ? line.item_vendor_name : line.doc_vendor_name,
        /* F-61 ② — 요청서의 창고를 그대로 들고 온다. 서버도 같은 값을 **자기가 다시** 읽는다
           (화면이 보낸 장소를 믿지 않는다 — 요청 줄의 장소는 그 요청 전표가 정한다). */
        location_id: line.location_id,
        location_name: line.location_name,
        qty: qty
      };
    }

    /** 창 안 줄 표의 열 — 체크 칸과 「담을 수량」 칸 **사이** (코드·품목·출처 요청·잔량) */
    function poLoadCells(tr, line) {
      cell(tr, line.code, 'mono');
      var nameTd = cell(tr, line.name);
      nameTd.className = 'p-name p-cell-wide';
      if (line.spec) { nameTd.appendChild(el('span', 'p-sub', line.spec)); }

      var srcTd = el('td');
      srcTd.appendChild(el('span', 'mono', line.doc_no));
      srcTd.appendChild(el('span', 'p-sub', dash(line.manager_name)));
      tr.appendChild(srcTd);

      cell(tr, qtyText(line.remaining), 'num mono');
    }

    var loadPick = UI.loadPicker({
      request: request,
      path: '/api/purchase/pr/load',
      lineKey: function (line) { return line.line_id; },
      params: function () {
        var out = '';
        if ($('load-q').value.trim() !== '') {
          out += '&q=' + encodeURIComponent($('load-q').value.trim());
        }
        if ($('load-from').value !== '') {
          out += '&from=' + encodeURIComponent($('load-from').value);
        }
        if ($('load-to').value !== '') {
          out += '&to=' + encodeURIComponent($('load-to').value);
        }
        return out;
      },
      cells: poLoadCells,
      pickedFrom: pickedFrom,
      /* 같은 요청 줄은 한 번만 들어간다 — 20단계의 판정을 그대로 옮긴 것이다 */
      onAdd: function (list) {
        list.forEach(function (picked) {
          var found = false;
          lines.forEach(function (line) {
            if (String(line.src_line_id) === String(picked.src_line_id)) { found = true; }
          });
          if (!found) { lines.push(picked); }
        });
        renderLines();
      }
    });

    $('po-load').addEventListener('click', function () { loadPick.open(); });

    /* ══ 직접 입력 — 품목 찾기 (F-61 ③) ══════════════════
     * 창을 그리는 코드는 발주요청 화면과 **한 벌**이다 (`PurchaseUI.itemPicker`).
     * 발주서에만 있는 것은 「입고 장소」 칸 하나다: 머리 장소를 비워 두었으면 여기서 고른다.
     */
    function pickLocationNeeded() {
      return $('po-location').value === '';
    }

    function syncPickLocation() {
      var field = $('pick-location-field');
      if (field) { field.hidden = !pickLocationNeeded(); }
    }

    function chosenLocation(select) {
      if (!select || select.value === '') { return null; }
      return {
        id: Number(select.value),
        name: select.options[select.selectedIndex] ? select.options[select.selectedIndex].text : ''
      };
    }

    /** 직접 줄 하나를 담는다 (품목 찾기·부족 산출이 같은 함수를 쓴다) */
    function addDirect(row, qty, where, source, note) {
      var exists = null;
      lines.forEach(function (line) {
        if (line.source !== 'request' && String(line.item_id) === String(row.item_id)
            && String(line.location_id) === String(where ? where.id : '')) {
          exists = line;
        }
      });
      if (exists) {
        exists.qty = String(Number(exists.qty || 0) + Number(qty || 1));
        return;
      }
      lines.push({
        source: source,
        item_id: row.item_id,
        code: row.code,
        name: row.name,
        spec: row.spec || null,
        unit: row.unit || null,
        vendor_id: row.vendor_id === undefined ? null : row.vendor_id,
        vendor_name: row.vendor_name || null,
        price_in: row.price_in === undefined ? null : row.price_in,
        qty: String(qty || 1),
        location_id: where ? where.id : null,
        location_name: where ? where.name : null,
        /* F-62 ② — 직접 줄·부족 산출 줄의 담당자는 **로그인 사용자**다(물려받을 요청서가
           없다). 저장 본문에는 싣지 않는다 — 서버가 세션에서 같은 값을 정한다. */
        manager_id: meUser.id,
        manager_name: meUser.name,
        note: note || null
      });
    }

    var picker = UI.itemPicker({
      request: request,
      guard: function () {
        if (pickLocationNeeded() && $('pick-location').value === '') {
          return '머리 「입고 장소」가 비어 있습니다 — 창에서 장소를 골라 주세요.';
        }
        return '';
      },
      onAdd: function (chosen) {
        var where = pickLocationNeeded()
          ? chosenLocation($('pick-location'))
          : chosenLocation($('po-location'));
        chosen.forEach(function (one) { addDirect(one.row, one.qty, where, 'direct', null); });
        renderLines();
      }
    });

    $('po-pick').addEventListener('click', function () {
      syncPickLocation();
      picker.open();
    });

    /* ══ 부족 산출 (F-61 ④ · F-36) ════════════════════════
     * 자료는 **기존 창구** `GET /api/purchase/suggest` 하나뿐이다. 제안 수량은 서버가
     * 계산한 값(`suggest_qty`)이고, 화면은 그것을 기본값으로 보여 준 뒤 사람이 고친 수량을
     * 직접 줄로 담는다. 화면이 식을 다시 계산하지 않는다 — 응답의 `formula` 를 그대로 적는다.
     */
    var shortPicked = {};

    function syncShortInfo() {
      setText($('short-info'), '고른 품목 ' + Object.keys(shortPicked).length + '건');
    }

    function runShort() {
      var loc = $('short-location').value;
      if (loc === '') { say('장소를 골라 주세요.'); return; }
      var params = '?location=' + encodeURIComponent(loc)
        + '&only_short=' + ($('short-only').checked ? '1' : '0');
      if ($('short-q').value.trim() !== '') {
        params += '&q=' + encodeURIComponent($('short-q').value.trim());
      }
      request('GET', '/api/purchase/suggest' + params).then(function (res) {
        if (!res) { return; }
        if (!res.ok) { say(errorMessage(res.data)); return; }
        var body = $('short-body');
        clear(body);
        shortPicked = {};
        var items = Array.isArray(res.data.items) ? res.data.items : [];
        items.forEach(function (row) {
          var tr = el('tr');
          var checkTd = el('td', 'p-col-check');
          var check = document.createElement('input');
          check.type = 'checkbox';
          checkTd.appendChild(check);
          tr.appendChild(checkTd);

          var nameTd = cell(tr, row.name, 'p-name p-cell-wide');
          nameTd.appendChild(el('span', 'p-sub mono', row.code
            + (row.spec ? ' · ' + row.spec : '')));
          cell(tr, row.par_qty === null ? '—' : qtyText(row.par_qty), 'num mono');
          cell(tr, qtyText(row.on_hand), 'num mono');
          cell(tr, qtyText(row.incoming), 'num mono');
          var sugTd = el('td', 'num mono');
          sugTd.appendChild(badge(qtyText(row.suggest_qty), 'badge--accent'));
          tr.appendChild(sugTd);

          var qtyTd = el('td', 'num');
          var qtyInput = document.createElement('input');
          qtyInput.type = 'number';
          qtyInput.min = '0.001';
          qtyInput.step = '0.001';
          qtyInput.className = 'p-in p-in--qty';
          qtyInput.value = qtyText(row.suggest_qty);
          qtyTd.appendChild(qtyInput);
          tr.appendChild(qtyTd);

          function sync() {
            if (check.checked) {
              shortPicked[String(row.item_id)] = { row: row, qty: qtyInput.value };
            } else {
              delete shortPicked[String(row.item_id)];
            }
            syncShortInfo();
          }
          check.addEventListener('change', sync);
          qtyInput.addEventListener('input', function () { if (check.checked) { sync(); } });

          body.appendChild(tr);
        });
        $('short-empty').hidden = items.length > 0;
        /* 식은 서버가 준 문장 그대로다 — 화면이 고쳐 적으면 두 곳이 갈린다 */
        setText($('short-formula'), res.data.formula || '');
        syncShortInfo();
      });
    }

    $('po-short').addEventListener('click', function () {
      shortPicked = {};
      clear($('short-body'));
      setText($('short-formula'), '');
      $('short-empty').hidden = true;
      /* 머리 장소를 이미 골랐으면 그 장소를 기본으로 (사람이 바꿀 수 있다) */
      if ($('po-location').value !== '') { $('short-location').value = $('po-location').value; }
      syncShortInfo();
      openModal('short-modal');
      if ($('short-location').value !== '') { runShort(); }
    });
    $('short-search').addEventListener('click', runShort);
    $('short-location').addEventListener('change', runShort);
    $('short-only').addEventListener('change', runShort);
    $('short-add').addEventListener('click', function () {
      var where = chosenLocation($('short-location'));
      if (!where) { say('장소를 골라 주세요.'); return; }
      Object.keys(shortPicked).forEach(function (key) {
        var one = shortPicked[key];
        addDirect({
          item_id: one.row.item_id,
          code: one.row.code,
          name: one.row.name,
          spec: one.row.spec,
          unit: one.row.unit,
          vendor_id: one.row.vendor_id,
          vendor_name: one.row.vendor_name,
          price_in: null
        }, one.qty, where, 'suggest', '제안 ' + qtyText(one.row.suggest_qty));
      });
      shortPicked = {};
      renderLines();
      closeModal('short-modal');
    });

    /* ══ 현황 탭 — 미입고. 입고·재고 화면과 **같은 함수**를 부른다 (F-45 · F-61 ①) ══ */
    function loadStatus() {
      UI.renderBackorder($('po-backorder'), {
        request: request,
        kpis: $('po-bo-kpis'),
        empty: $('po-bo-empty'),
        vendor: $('po-bo-vendor'),
        overdue: $('po-bo-overdue')
      });
    }
    $('po-bo-run').addEventListener('click', loadStatus);
    $('po-bo-vendor').addEventListener('change', loadStatus);
    $('po-bo-overdue').addEventListener('change', loadStatus);

    /* 머리 장소를 바꾸면 줄의 입고 장소·미리보기·찾기 창이 함께 따라온다 */
    $('po-location').addEventListener('change', function () {
      syncPickLocation();
      renderLines();
    });

    /* 머리 담당자를 바꾸면 미리보기가 함께 따라온다 (F-62 — 장소 칸과 같은 방식) */
    $('po-manager').addEventListener('change', renderLines);

    /* ── 저장 ──────────────────────────────────────────── */
    $('po-input').addEventListener('submit', function (event) {
      event.preventDefault();
      if (lines.length === 0) { say('발주요청 불러오기·품목 찾기·부족 산출로 줄을 담아 주세요.'); return; }
      var missing = 0;
      lines.forEach(function (line) { if (!lineLocation(line)) { missing += 1; } });
      if (missing > 0) {
        say('입고 장소를 정할 수 없는 줄이 ' + missing + '개 있습니다 — 머리 「입고 장소」를 고르세요.');
        return;
      }
      /* F-62 — 담당자도 같은 방식으로 본다: 머리가 비었고 줄에도 없으면 나눌 수 없다 */
      var noMgr = 0;
      lines.forEach(function (line) { if (!lineManager(line)) { noMgr += 1; } });
      if (noMgr > 0) {
        say('담당자를 정할 수 없는 줄이 ' + noMgr + '개 있습니다 — 머리 「담당자」를 고르세요.');
        return;
      }
      var payload = {
        doc_date: $('po-date').value,
        // F-60 — 화면은 **언제나** 나눠 달라고 한다. 거래처·장소가 하나씩이면 1장이 나온다.
        split: true,
        lines: []
      };
      /* F-61 ② — 머리 장소가 비어 있으면 **키 자체를 싣지 않는다**. 그래야 서버가
         「줄에서 자동」 으로 읽는다(빈 값을 보내면 형식 오류다). */
      if ($('po-location').value !== '') {
        payload.to_location_id = Number($('po-location').value);
      }
      if ($('po-vendor').value !== '') { payload.vendor_id = Number($('po-vendor').value); }
      /* F-62 — 머리 담당자도 장소·거래처와 같은 규칙이다: 비었으면 **키 자체를 싣지 않는다**.
         담은 줄에는 담당자를 싣지 않는다 — 서버가 줄에서 다시 판정한다(거래처·단가와 같다). */
      if ($('po-manager').value !== '') { payload.manager_id = Number($('po-manager').value); }
      if ($('po-memo').value !== '') { payload.memo = $('po-memo').value; }
      lines.forEach(function (line) {
        // 여기도 **수량만** 보낸다 (R-66). 거래처·단가는 서버가 줄에서 다시 판정한다.
        if (line.source === 'request') {
          payload.lines.push({ src_line_id: line.src_line_id, qty: String(line.qty) });
          return;
        }
        var one = { item_id: line.item_id, qty: String(line.qty) };
        /* 머리 장소가 있으면 그것이 우선이므로 줄에는 적지 않는다 (서버가 400 으로 되돌린다) */
        if ($('po-location').value === '' && line.location_id !== null) {
          one.location_id = line.location_id;
        }
        payload.lines.push(one);
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
      docs: { panel: 'po-docs', onShow: loadDocs },
      /* 현황 = 미입고. 입고 화면 현황 탭과 **같은 창구·같은 렌더러**다 (F-61 ①) */
      status: { panel: 'po-status', onShow: loadStatus }
    });

    $('po-date').value = today();
    renderLines();
    Promise.all([loadMaster('locations'), loadMaster('vendors'), loadMaster('managers')])
      .then(function (all) {
        /* 머리 장소의 첫 항목이 기본값이다 — 비워 두면 줄이 장소를 정한다 (F-61 ②) */
        fillSelect($('po-location'), all[0], 'id', 'name', '— 줄에서 자동 —');
        fillSelect($('po-vendor'), all[1], 'id', 'name', '— 줄에서 자동 —');
        /* F-62 — 머리 담당자. 비워 두면 줄이 담당자를 정한다(요청서의 담당자로 나뉜다) */
        fillSelect($('po-manager'), all[2], 'id', 'name', '— 줄에서 자동 —');
        fillSelect($('pick-location'), all[0], 'id', 'name', '— 입고 장소 —');
        fillSelect($('short-location'), all[0], 'id', 'name', '— 장소 —');
        fillSelect($('pick-vendor'), all[1], 'id', 'name', '— 전체 —');
        fillSelect($('po-bo-vendor'), all[1], 'id', 'name', '— 전체 —');
        /* 로그인 사용자의 **번호**를 여기서 맞춘다 — `/api/me` 는 이름만 주므로, 같은 이름을
           담당자 목록에서 찾는다(모듈 역할이 없는 계정은 목록에 없어 번호가 null 로 남고,
           그때 미리보기는 이름으로 센다). 직접 줄의 담당자 표시에만 쓰인다. */
        all[2].forEach(function (row) {
          if (String(row.name) === meUser.name) { meUser.id = row.id; }
        });
        syncPickLocation();
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
        meUser.name = typeof me.username === 'string' ? me.username : '';
        var managerBox = $('pr-manager');
        if (managerBox) { managerBox.value = meUser.name; }
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
