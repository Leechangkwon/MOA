/* public/assets/purchase_stock.js — 재고 화면 (21단계, PRD F-33 · F-34, 시안 ⑥⑦⑩⑪)
 *
 * 20단계의 `purchase_docs.js`·19단계의 `purchase.js` 와 **별도 파일**이다(지시서: 다른 JS 를
 * 고치지 않는다). 세 화면이 이 한 파일을 읽고, 어느 화면인지는 DOM 에 있는 탭 id 로 고른다.
 *
 * 지키는 것 (PRD S-01 · S-03, ARCH 13장)
 *   · **inner/outer HTML 계열과 document.write 를 쓰지 않는다.** 문서에 글자를 넣는 길은
 *     `textContent` 하나뿐이다. 품목명·비고·거래처 이름은 사람이 적은 문자열이고,
 *     그중 하나라도 태그로 해석되면 그것이 XSS 다.
 *   · **권한을 브라우저에서 판단하지 않는다.** 저장 단추를 눌러도 서버가 라우트 정책
 *     `purchase.stock.write` 로 다시 막는다. 화면은 편의일 뿐 근거가 아니다.
 *   · **수량 말고는 보내지 않는다.** 저장 본문에 단가·금액 칸이 아예 없다 —
 *     단가는 서버가 발주서 줄·`p_items.price_in` 에서 읽는다 (SECURITY R-66).
 *   · **음수 재고를 화면이 판정하지 않는다.** 빨간 줄은 안내이고, 막는 것은 서버의
 *     장소 잠금 + 합 재계산이다(400 `negative_stock`).
 *   · **지점을 화면이 고르지 않는다.** 목록에 무엇이 보일지는 서버의 SQL WHERE 가 정한다.
 *   · 상태 변경 요청에는 `X-CSRF-Token` 을 싣는다. 토큰은 `/api/me` 가 준다.
 *
 * app.js·purchase.js·purchase_docs.js 와 겹치지 않게 모든 것이 즉시 실행 함수 안에 있다(전역 0개).
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

  /** 수량 — 소수점 뒤 0 은 지운다(5.000 → 5). 값 자체는 서버가 만든 문자열이다. */
  function qty(value) {
    if (value === null || value === undefined || value === '') { return '—'; }
    var n = Number(value);
    if (isNaN(n)) { return String(value); }
    return String(Math.round(n * 1000) / 1000);
  }

  function money(value) {
    if (value === null || value === undefined || value === '') { return '—'; }
    var n = Number(value);
    if (isNaN(n)) { return String(value); }
    return n.toLocaleString('ko-KR');
  }

  function today() {
    var d = new Date();
    function two(v) { return (v < 10 ? '0' : '') + v; }
    return d.getFullYear() + '-' + two(d.getMonth() + 1) + '-' + two(d.getDate());
  }

  function num(value) {
    var n = Number(value);
    return isNaN(n) ? 0 : n;
  }

  function errorMessage(data) {
    if (data && typeof data.error === 'string' && data.error !== '') { return data.error; }
    return GENERIC_ERROR;
  }

  function readJson(response) {
    return response.json().then(function (data) {
      return data && typeof data === 'object' ? data : {};
    }).catch(function () { return {}; });
  }

  /**
   * 401 → 로그인, 403 + next → 강제 절차·재인증 화면. 처리했으면 true.
   * 경로는 서버가 정한 **고정 키**에서만 나오고 이 파일이 주소를 조립하지 않는다
   * (PRD F-23 오픈 리다이렉트 방지).
   */
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

  function request(method, path, body) {
    var init = {
      method: method,
      credentials: 'same-origin',
      cache: 'no-store',
      headers: {}
    };
    if (method !== 'GET') { init.headers['X-CSRF-Token'] = csrfToken; }
    if (body !== undefined && body !== null) {
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

  function cell(row, text, className) {
    var td = el('td', className || null, text);
    row.appendChild(td);
    return td;
  }

  /** 표 안의 수량 입력칸. 값은 문자열 그대로 서버에 간다(서버가 형식을 다시 본다). */
  function qtyInput(value, onInput, className) {
    var input = document.createElement('input');
    input.type = 'number';
    input.min = '0';
    input.step = '0.001';
    input.className = className || 'p-in';
    input.value = value === null || value === undefined ? '' : String(value);
    input.addEventListener('input', function () { onInput(input.value); });
    return input;
  }

  function textInput(value, maxLength, onInput, className) {
    var input = document.createElement('input');
    input.type = 'text';
    input.maxLength = maxLength;
    input.className = className || 'p-in p-in--text';
    input.value = value === null || value === undefined ? '' : String(value);
    input.addEventListener('input', function () { onInput(input.value); });
    return input;
  }

  function dateInput(value, onInput) {
    var input = document.createElement('input');
    input.type = 'date';
    input.className = 'p-in p-in--date';
    input.value = value === null || value === undefined ? '' : String(value);
    input.addEventListener('input', function () { onInput(input.value); });
    return input;
  }

  function button(label, className, onClick) {
    var b = el('button', className || 'btn btn--quiet btn--small', label);
    b.type = 'button';
    b.addEventListener('click', onClick);
    return b;
  }

  /** 셀렉트를 목록으로 채운다. `textContent` 로만 글자를 넣는다. */
  function fillSelect(select, rows, valueKey, labelKey, emptyLabel) {
    if (!select) { return; }
    var keep = select.value;
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
    if (keep !== '') { select.value = keep; }
  }

  /** 기초정보 목록 — 서버가 이미 지점으로 좁혀 준다 */
  function loadMaster(kind) {
    return request('GET', '/api/purchase/' + kind).then(function (res) {
      if (!res || !res.ok) { return []; }
      return Array.isArray(res.data.items) ? res.data.items : [];
    });
  }

  var STATUS_LABEL = {
    unconfirmed: '미확인', in_progress: '진행중', partial: '일부', done: '완료', void: '취소'
  };
  var STATUS_CLASS = {
    unconfirmed: 'p-badge', in_progress: 'p-badge p-badge--accent',
    partial: 'p-badge p-badge--warn', done: 'p-badge p-badge--ok',
    void: 'p-badge p-badge--danger'
  };
  function statusBadge(status) {
    return el('span', STATUS_CLASS[status] || 'p-badge', STATUS_LABEL[status] || status);
  }

  function kpi(container, label, value, note) {
    var card = el('div', 'p-kpi');
    card.appendChild(el('div', 'p-kpi__label', label));
    card.appendChild(el('div', 'p-kpi__value', value));
    card.appendChild(el('div', 'p-kpi__note', note || ''));
    container.appendChild(card);
  }

  function initTabs(navId, map) {
    var nav = $(navId);
    if (!nav) { return; }
    var buttons = nav.querySelectorAll('.p-tab');
    Array.prototype.forEach.call(buttons, function (b) {
      b.addEventListener('click', function () {
        Array.prototype.forEach.call(buttons, function (x) { x.classList.remove('is-on'); });
        b.classList.add('is-on');
        var wanted = b.getAttribute('data-tab');
        Object.keys(map).forEach(function (key) {
          var panel = $(map[key].panel);
          if (panel) { panel.hidden = key !== wanted; }
        });
        if (map[wanted] && typeof map[wanted].onShow === 'function') { map[wanted].onShow(); }
      });
    });
  }

  /* ══════════════════════════════════════════════════════
   * 화면 ① — 현황 (시안 ⑩ Status · ⑪ Backorder)
   * ══════════════════════════════════════════════════════ */

  function initStatus() {
    var stPage = 1;
    var lgPage = 1;
    var locations = [];

    function locationsOf(branchValue) {
      if (branchValue === '') { return locations; }
      return locations.filter(function (l) { return String(l.branch_id) === branchValue; });
    }

    function loadStatus() {
      var params = '?page=' + stPage;
      if ($('st-branch').value !== '') {
        params += '&branch=' + encodeURIComponent($('st-branch').value);
      }
      if ($('st-location').value !== '') {
        params += '&location=' + encodeURIComponent($('st-location').value);
      }
      if ($('st-q').value.trim() !== '') {
        params += '&q=' + encodeURIComponent($('st-q').value.trim());
      }
      if ($('st-short').checked) { params += '&short=1'; }

      request('GET', '/api/purchase/stock' + params).then(function (res) {
        if (!res) { return; }
        if (!res.ok) { say(errorMessage(res.data)); return; }
        var items = Array.isArray(res.data.items) ? res.data.items : [];
        var body = $('st-body');
        clear(body);

        var shortCount = 0;
        var incoming = 0;
        items.forEach(function (row) {
          if (num(row.gap) < 0) { shortCount += 1; }
          incoming += num(row.incoming);

          var tr = el('tr');
          if (num(row.gap) < 0) { tr.className = 'p-row-bad'; }
          var nameTd = cell(tr, row.name);
          nameTd.appendChild(el('span', 'p-sub', row.code + (row.spec ? ' · ' + row.spec : '')));
          cell(tr, row.location_name);
          cell(tr, qty(row.on_hand), 'p-num');
          cell(tr, row.par_qty === null ? '—' : String(row.par_qty), 'p-num');
          var gapTd = cell(tr, qty(row.gap), 'p-num');
          gapTd.classList.add(num(row.gap) < 0 ? 'p-short' : 'p-plus');
          cell(tr, qty(row.incoming), 'p-num');
          cell(tr, qty(row.weekly_use), 'p-num');
          // 발주 제안은 23단계 값이다 — 서버가 null 을 주므로 화면도 지어내지 않는다.
          cell(tr, row.suggest_qty === null ? '— (23단계)' : qty(row.suggest_qty));

          var actTd = el('td');
          actTd.appendChild(button('수불부', 'btn btn--quiet btn--small', function () {
            $('lg-location').value = String(row.location_id);
            $('lg-item').value = String(row.item_id);
            lgPage = 1;
            showTab('ledger');
            loadLedger();
          }));
          tr.appendChild(actTd);
          body.appendChild(tr);
        });

        $('st-empty').hidden = items.length > 0;
        var kpis = $('st-kpis');
        clear(kpis);
        kpi(kpis, '인가량 미달 품목', String(shortCount), '이 페이지 기준');
        kpi(kpis, '미입고 수량', qty(String(incoming)), '이 페이지 기준');
        kpi(kpis, '전체 행', String(res.data.total), '장소 × 품목');
        setText($('st-page-info'), res.data.page + ' 쪽 · 전체 ' + res.data.total + '행');
      });
    }

    function loadLedger() {
      var params = '?page=' + lgPage;
      if ($('lg-location').value !== '') {
        params += '&location=' + encodeURIComponent($('lg-location').value);
      }
      if ($('lg-item').value !== '') {
        params += '&item=' + encodeURIComponent($('lg-item').value);
      }
      if ($('lg-from').value !== '') { params += '&from=' + encodeURIComponent($('lg-from').value); }
      if ($('lg-to').value !== '') { params += '&to=' + encodeURIComponent($('lg-to').value); }

      request('GET', '/api/purchase/ledger' + params).then(function (res) {
        if (!res) { return; }
        if (!res.ok) { say(errorMessage(res.data)); return; }
        var body = $('lg-body');
        clear(body);
        (res.data.rows || []).forEach(function (row) {
          var tr = el('tr');
          cell(tr, row.moved_at);
          var docTd = cell(tr, row.doc_no);
          docTd.appendChild(el('span', 'p-sub', row.doc_type.toUpperCase()
            + (row.reverses ? ' · 되돌림' : '')));
          var itemTd = cell(tr, row.name);
          itemTd.appendChild(el('span', 'p-sub', row.code));
          cell(tr, row.location_name);
          var deltaTd = cell(tr, qty(row.qty_delta), 'p-num');
          deltaTd.classList.add(num(row.qty_delta) < 0 ? 'p-short' : 'p-plus');
          cell(tr, row.balance === null ? '—' : qty(row.balance), 'p-num');
          cell(tr, dash(row.lot_no) + (row.expiry_date ? ' · ' + row.expiry_date : ''));
          cell(tr, dash(row.actor_name));
          body.appendChild(tr);
        });
        setText($('lg-info'), res.data.running
          ? '장소와 품목을 함께 고르면 잔액이 이어집니다.'
          : '장소와 품목을 함께 골라야 잔액 칸이 채워집니다 (섞인 목록의 누적은 뜻이 없습니다).');
        setText($('lg-page-info'), res.data.page + ' 쪽 · 전체 ' + res.data.total + '행');
      });
    }

    function loadBackorder() {
      var params = '';
      var joiner = '?';
      if ($('bo-vendor').value !== '') {
        params += joiner + 'vendor=' + encodeURIComponent($('bo-vendor').value);
        joiner = '&';
      }
      if ($('bo-overdue').checked) { params += joiner + 'overdue=1'; joiner = '&'; }
      if ($('bo-q').value.trim() !== '') {
        params += joiner + 'q=' + encodeURIComponent($('bo-q').value.trim());
      }

      request('GET', '/api/purchase/backorder' + params).then(function (res) {
        if (!res) { return; }
        if (!res.ok) { say(errorMessage(res.data)); return; }
        var host = $('bo-groups');
        clear(host);
        var groups = Array.isArray(res.data.groups) ? res.data.groups : [];

        var kpis = $('bo-kpis');
        clear(kpis);
        kpi(kpis, '미입고 발주서', String(res.data.doc_count) + ' 건',
          '거래처 ' + groups.length + '곳');
        kpi(kpis, '납기 지남', String(res.data.overdue) + ' 줄', '보낸 날 + 조달기간 기준');
        kpi(kpis, '미입고 금액', money(res.data.amount), '공급가액 기준');
        kpi(kpis, '미입고 줄', String(res.data.line_count) + ' 줄',
          res.data.truncated ? '상한에서 끊김 — 좁혀 보세요' : '');

        groups.forEach(function (group) {
          var box = el('div', 'p-group');
          var head = el('div', 'p-group__head');
          head.appendChild(el('span', null, group.vendor_name));
          head.appendChild(el('span', 'p-group__meta',
            '발주서 ' + group.doc_count + '건 · 미입고 ' + group.line_count + '줄 · '
              + money(group.amount) + ' 원'));
          box.appendChild(head);

          var wrap = el('div', 'table-wrap');
          var table = el('table', 'files');
          var thead = el('thead');
          var hrow = el('tr');
          ['발주서', '보낸 날 · 납기', '지점 · 입고 장소', '품목', '발주', '입고', '미입고', '경과', '요청자']
            .forEach(function (label, index) {
              var th = el('th', index >= 4 && index <= 6 ? 'p-num' : null, label);
              hrow.appendChild(th);
            });
          thead.appendChild(hrow);
          table.appendChild(thead);

          var tbody = el('tbody');
          group.lines.forEach(function (line) {
            var tr = el('tr');
            if (line.is_overdue) { tr.className = 'p-row-bad'; }
            cell(tr, line.po_doc_no);
            cell(tr, dash(line.sent_at ? line.sent_at.slice(0, 10) : null)
              + ' · ' + dash(line.due_date));
            cell(tr, dash(line.branch_name) + ' · ' + dash(line.location_name));
            var itemTd = cell(tr, line.name);
            itemTd.appendChild(el('span', 'p-sub',
              line.code + (line.partial ? ' · 부분 입고' : '')));
            cell(tr, qty(line.ordered_qty), 'p-num');
            cell(tr, qty(line.received_qty), 'p-num');
            cell(tr, qty(line.remaining), 'p-num');
            var days = cell(tr, line.days_elapsed + '일');
            if (line.is_overdue) { days.className = 'p-short'; }
            cell(tr, dash(line.manager_name));
            tbody.appendChild(tr);
          });
          table.appendChild(tbody);
          wrap.appendChild(table);
          box.appendChild(wrap);
          host.appendChild(box);
        });

        $('bo-empty').hidden = groups.length > 0;
      });
    }

    function showTab(key) {
      var nav = $('stock-tabs');
      var buttons = nav.querySelectorAll('.p-tab');
      Array.prototype.forEach.call(buttons, function (b) {
        var on = b.getAttribute('data-tab') === key;
        b.classList.toggle('is-on', on);
      });
      $('stock-status').hidden = key !== 'status';
      $('stock-ledger').hidden = key !== 'ledger';
      $('stock-backorder').hidden = key !== 'backorder';
    }

    initTabs('stock-tabs', {
      status: { panel: 'stock-status', onShow: loadStatus },
      ledger: { panel: 'stock-ledger', onShow: loadLedger },
      backorder: { panel: 'stock-backorder', onShow: loadBackorder }
    });

    $('st-branch').addEventListener('change', function () {
      fillSelect($('st-location'), locationsOf($('st-branch').value), 'id', 'name', '전체 장소');
      stPage = 1;
      loadStatus();
    });
    $('st-location').addEventListener('change', function () { stPage = 1; loadStatus(); });
    $('st-q').addEventListener('change', function () { stPage = 1; loadStatus(); });
    $('st-short').addEventListener('change', function () { stPage = 1; loadStatus(); });
    $('st-prev').addEventListener('click', function () {
      if (stPage > 1) { stPage -= 1; loadStatus(); }
    });
    $('st-next').addEventListener('click', function () { stPage += 1; loadStatus(); });

    $('lg-run').addEventListener('click', function () { lgPage = 1; loadLedger(); });
    $('lg-prev').addEventListener('click', function () {
      if (lgPage > 1) { lgPage -= 1; loadLedger(); }
    });
    $('lg-next').addEventListener('click', function () { lgPage += 1; loadLedger(); });
    $('bo-run').addEventListener('click', loadBackorder);

    Promise.all([loadMaster('branches'), loadMaster('locations'), loadMaster('vendors')])
      .then(function (all) {
        fillSelect($('st-branch'), all[0], 'id', 'name', '전체 지점');
        locations = all[1];
        fillSelect($('st-location'), locations, 'id', 'name', '전체 장소');
        fillSelect($('lg-location'), locations, 'id', 'name', '전체 장소');
        fillSelect($('bo-vendor'), all[2], 'id', 'name', '전체 거래처');
        loadStatus();
      });
  }

  /* ══════════════════════════════════════════════════════
   * 화면 ② — 구매(입고) (시안 ⑥ GR)
   * ══════════════════════════════════════════════════════ */

  function initReceipt() {
    var lines = [];      // [{po_line_id, code, name, ordered, received, remaining, qty, lot, exp, remark}]
    var lastDocId = null;

    function renderLines() {
      var body = $('gr-line-body');
      clear(body);
      var total = 0;
      lines.forEach(function (line, index) {
        var tr = el('tr');
        cell(tr, String(index + 1), 'p-col-no');
        var nameTd = cell(tr, line.name);
        nameTd.appendChild(el('span', 'p-sub', line.code));
        cell(tr, line.po_doc_no);
        cell(tr, qty(line.ordered), 'p-num');
        cell(tr, qty(line.received), 'p-num');

        var qtyTd = el('td', 'p-num');
        qtyTd.appendChild(qtyInput(line.qty, function (value) {
          line.qty = value;
          renderLines();
        }));
        tr.appendChild(qtyTd);

        var left = num(line.remaining) - num(line.qty);
        var leftTd = cell(tr, qty(String(left)), 'p-num');
        if (left < 0) {
          leftTd.classList.add('p-short');
          tr.className = 'p-row-bad';
        }

        var lotTd = el('td');
        lotTd.appendChild(textInput(line.lot, 60, function (v) { line.lot = v; }));
        tr.appendChild(lotTd);

        var expTd = el('td');
        expTd.appendChild(dateInput(line.exp, function (v) { line.exp = v; }));
        tr.appendChild(expTd);

        var remarkTd = el('td');
        remarkTd.appendChild(textInput(line.remark, 200, function (v) { line.remark = v; }));
        tr.appendChild(remarkTd);

        var actTd = el('td');
        actTd.appendChild(button('빼기', 'btn btn--quiet btn--small', function () {
          lines.splice(index, 1);
          renderLines();
        }));
        tr.appendChild(actTd);

        body.appendChild(tr);
        total += num(line.qty);
      });
      $('gr-line-empty').hidden = lines.length > 0;
      setText($('gr-sum'), lines.length === 0
        ? ''
        : '입고 줄 ' + lines.length + '건 · 이번 입고 ' + qty(String(total)) + ' 낱개'
          + ' · 수량 0 인 줄은 보내지 않습니다');
    }

    function addLine(order, line) {
      var exists = false;
      lines.forEach(function (l) { if (l.po_line_id === line.po_line_id) { exists = true; } });
      if (exists) { return; }
      lines.push({
        po_line_id: line.po_line_id,
        po_doc_no: order.po_doc_no,
        code: line.code,
        name: line.name,
        ordered: line.ordered_qty,
        received: line.received_qty,
        remaining: line.remaining,
        qty: line.remaining,
        lot: '',
        exp: '',
        remark: ''
      });
      renderLines();
    }

    function loadOrders() {
      var params = '';
      var joiner = '?';
      if ($('gr-vendor').value !== '') {
        params += joiner + 'vendor=' + encodeURIComponent($('gr-vendor').value);
        joiner = '&';
      }
      if ($('gr-overdue').checked) { params += joiner + 'overdue=1'; joiner = '&'; }
      if ($('gr-q').value.trim() !== '') {
        params += joiner + 'q=' + encodeURIComponent($('gr-q').value.trim());
      }

      request('GET', '/api/purchase/gr/load' + params).then(function (res) {
        if (!res) { return; }
        if (!res.ok) { say(errorMessage(res.data)); return; }
        var host = $('gr-orders');
        clear(host);
        var orders = Array.isArray(res.data.orders) ? res.data.orders : [];
        orders.forEach(function (order) {
          var box = el('div', 'p-group');
          var head = el('div', 'p-group__head');
          head.appendChild(el('span', null, order.po_doc_no));
          head.appendChild(el('span', 'p-group__meta',
            dash(order.vendor_name) + ' · ' + dash(order.location_name)
              + ' · 보낸 지 ' + order.days_elapsed + '일'));
          head.appendChild(button('줄 전부 담기', 'btn btn--quiet btn--small', function () {
            order.lines.forEach(function (line) { addLine(order, line); });
          }));
          box.appendChild(head);

          var wrap = el('div', 'table-wrap');
          var table = el('table', 'files');
          var thead = el('thead');
          var hrow = el('tr');
          ['품목', '발주', '기입고', '미입고', ''].forEach(function (label, index) {
            hrow.appendChild(el('th', index >= 1 && index <= 3 ? 'p-num' : null, label));
          });
          thead.appendChild(hrow);
          table.appendChild(thead);

          var tbody = el('tbody');
          order.lines.forEach(function (line) {
            var tr = el('tr');
            var nameTd = cell(tr, line.name);
            nameTd.appendChild(el('span', 'p-sub',
              line.code + (line.stock_managed ? '' : ' · 재고관리 안 함')));
            cell(tr, qty(line.ordered_qty), 'p-num');
            cell(tr, qty(line.received_qty), 'p-num');
            cell(tr, qty(line.remaining), 'p-num');
            var actTd = el('td');
            actTd.appendChild(button('담기', 'btn btn--quiet btn--small', function () {
              addLine(order, line);
            }));
            tr.appendChild(actTd);
            tbody.appendChild(tr);
          });
          table.appendChild(tbody);
          wrap.appendChild(table);
          box.appendChild(wrap);
          host.appendChild(box);
        });
        $('gr-load-empty').hidden = orders.length > 0;
      });
    }

    function renderResult(doc) {
      lastDocId = doc.id;
      $('gr-result').hidden = false;
      setText($('gr-result-head'), doc.doc_no + ' · ' + doc.doc_date
        + ' · 원장 ' + doc.moves + '행');
      var body = $('gr-result-body');
      clear(body);
      (doc.lines || []).forEach(function (line) {
        var tr = el('tr');
        cell(tr, String(line.line_no), 'p-col-no');
        var nameTd = cell(tr, line.name);
        nameTd.appendChild(el('span', 'p-sub', line.code));
        cell(tr, qty(line.qty), 'p-num');
        cell(tr, dash(line.lot_no) + (line.expiry_date ? ' · ' + line.expiry_date : ''));
        cell(tr, dash(line.src_doc_no));
        var st = el('td');
        st.appendChild(statusBadge(line.status));
        tr.appendChild(st);
        body.appendChild(tr);
      });
    }

    function save() {
      var payload = { doc_date: $('gr-date').value, lines: [] };
      if ($('gr-memo').value.trim() !== '') { payload.memo = $('gr-memo').value.trim(); }
      lines.forEach(function (line) {
        if (num(line.qty) <= 0) { return; }   // 0 인 줄은 전표에 넣지 않는다 (시안 ⑥)
        var out = { src_line_id: line.po_line_id, qty: String(line.qty) };
        if (line.lot !== '') { out.lot_no = line.lot; }
        if (line.exp !== '') { out.expiry_date = line.exp; }
        if (line.remark !== '') { out.remark = line.remark; }
        payload.lines.push(out);
      });
      if (payload.lines.length === 0) {
        say('입고할 수량이 있는 줄이 없습니다.');
        return;
      }
      request('POST', '/api/purchase/gr', payload).then(function (res) {
        if (!res) { return; }
        if (!res.ok) { say(errorMessage(res.data)); return; }
        say('입고를 확정했습니다: ' + res.data.doc_no);
        lines = [];
        renderLines();
        renderResult(res.data);
        loadOrders();
      });
    }

    function voidDoc() {
      if (lastDocId === null) { return; }
      request('POST', '/api/purchase/gr/' + lastDocId + '/void', {}).then(function (res) {
        if (!res) { return; }
        if (!res.ok) { say(errorMessage(res.data)); return; }
        say('입고 전표를 취소했습니다 (원장에 반대 부호 행 ' + res.data.moves + '행).');
        $('gr-result').hidden = true;
        lastDocId = null;
        loadOrders();
      });
    }

    $('gr-date').value = today();
    $('gr-load-run').addEventListener('click', loadOrders);
    $('gr-vendor').addEventListener('change', loadOrders);
    $('gr-overdue').addEventListener('change', loadOrders);
    $('gr-q').addEventListener('change', loadOrders);
    $('gr-save').addEventListener('click', save);
    $('gr-void').addEventListener('click', voidDoc);

    loadMaster('vendors').then(function (rows) {
      fillSelect($('gr-vendor'), rows, 'id', 'name', '전체 거래처');
      loadOrders();
    });
    renderLines();
  }

  /* ══════════════════════════════════════════════════════
   * 화면 ③ — 이동 · 사용 · 실사 (시안 ⑦ MoveUse)
   * ══════════════════════════════════════════════════════ */

  function initMove() {
    var mvLines = [];
    var isLines = [];
    var adjLines = [];
    var lastDoc = null;   // {id, type}

    function renderResult(doc, type) {
      lastDoc = { id: doc.id, type: type };
      $('mv-result').hidden = false;
      setText($('mv-result-head'), doc.doc_no + ' · ' + doc.doc_date
        + ' · 원장 ' + doc.moves + '행'
        + (doc.count_cycle ? ' · 주기 ' + doc.count_cycle : ''));
      var body = $('mv-result-body');
      clear(body);
      (doc.lines || []).forEach(function (line) {
        var tr = el('tr');
        cell(tr, String(line.line_no), 'p-col-no');
        var nameTd = cell(tr, line.name);
        nameTd.appendChild(el('span', 'p-sub', line.code));
        cell(tr, qty(line.qty), 'p-num');
        cell(tr, line.counted_qty === null ? '—' : qty(line.counted_qty), 'p-num');
        var st = el('td');
        st.appendChild(statusBadge(line.status));
        tr.appendChild(st);
        body.appendChild(tr);
      });
    }

    function voidDoc() {
      if (lastDoc === null) { return; }
      request('POST', '/api/purchase/' + lastDoc.type + '/' + lastDoc.id + '/void', {})
        .then(function (res) {
          if (!res) { return; }
          if (!res.ok) { say(errorMessage(res.data)); return; }
          say('전표를 취소했습니다 (원장에 반대 부호 행 ' + res.data.moves + '행).');
          $('mv-result').hidden = true;
          lastDoc = null;
        });
    }

    /* ── 이동 ─────────────────────────────────────────── */
    function renderMv() {
      var body = $('mv-body');
      clear(body);
      var total = 0;
      mvLines.forEach(function (line, index) {
        var tr = el('tr');
        cell(tr, String(index + 1), 'p-col-no');
        var nameTd = cell(tr, line.name);
        nameTd.appendChild(el('span', 'p-sub', line.code));
        cell(tr, qty(line.from_qty), 'p-num');
        cell(tr, qty(line.to_qty) + ' / ' + (line.par_qty === null ? '—' : line.par_qty), 'p-num');

        var qtyTd = el('td', 'p-num');
        qtyTd.appendChild(qtyInput(line.qty, function (v) { line.qty = v; renderMv(); }));
        tr.appendChild(qtyTd);

        var afterFrom = num(line.from_qty) - num(line.qty);
        var afterTo = num(line.to_qty) + num(line.qty);
        var afterTd = cell(tr, qty(String(afterFrom)) + ' / ' + qty(String(afterTo)), 'p-num');
        if (afterFrom < 0) {
          afterTd.classList.add('p-short');
          tr.className = 'p-row-bad';
        }

        var remarkTd = el('td');
        remarkTd.appendChild(textInput(line.remark, 200, function (v) { line.remark = v; }));
        tr.appendChild(remarkTd);

        var actTd = el('td');
        actTd.appendChild(button('빼기', 'btn btn--quiet btn--small', function () {
          mvLines.splice(index, 1);
          renderMv();
        }));
        tr.appendChild(actTd);
        body.appendChild(tr);
        total += num(line.qty);
      });
      $('mv-empty').hidden = mvLines.length > 0;
      setText($('mv-sum'), mvLines.length === 0
        ? ''
        : '이동 줄 ' + mvLines.length + '건 · 합계 ' + qty(String(total)) + ' 낱개');
    }

    function suggest() {
      if ($('mv-to').value === '') { say('받는 장소를 고르세요.'); return; }
      var params = '?to=' + encodeURIComponent($('mv-to').value);
      if ($('mv-from').value !== '') {
        params += '&from=' + encodeURIComponent($('mv-from').value);
      }
      request('GET', '/api/purchase/mv/suggest' + params).then(function (res) {
        if (!res) { return; }
        if (!res.ok) { say(errorMessage(res.data)); return; }
        if ($('mv-from').value === '') {
          $('mv-from').value = String(res.data.from_location_id);
        }
        mvLines = (res.data.items || []).map(function (row) {
          return {
            item_id: row.item_id,
            code: row.code,
            name: row.name,
            from_qty: row.from_qty,
            to_qty: row.to_qty,
            par_qty: row.par_qty,
            qty: row.suggest_qty,
            remark: ''
          };
        });
        renderMv();
        say(mvLines.length === 0
          ? '채울 부족분이 없습니다.'
          : '제안 ' + mvLines.length + '줄 — 수량은 고칠 수 있습니다.');
      });
    }

    function saveMv() {
      var payload = {
        from_location_id: $('mv-from').value,
        to_location_id: $('mv-to').value,
        doc_date: $('mv-date').value,
        lines: []
      };
      mvLines.forEach(function (line) {
        if (num(line.qty) <= 0) { return; }
        var out = { item_id: line.item_id, qty: String(line.qty) };
        if (line.remark !== '') { out.remark = line.remark; }
        payload.lines.push(out);
      });
      if (payload.lines.length === 0) { say('이동할 수량이 있는 줄이 없습니다.'); return; }
      request('POST', '/api/purchase/mv', payload).then(function (res) {
        if (!res) { return; }
        if (!res.ok) { say(errorMessage(res.data)); return; }
        say('이동을 확정했습니다: ' + res.data.doc_no);
        mvLines = [];
        renderMv();
        renderResult(res.data, 'mv');
      });
    }

    /* ── 사용 ─────────────────────────────────────────── */
    function renderIs() {
      var body = $('is-body');
      clear(body);
      var total = 0;
      isLines.forEach(function (line, index) {
        var tr = el('tr');
        cell(tr, String(index + 1), 'p-col-no');
        var nameTd = cell(tr, line.name);
        nameTd.appendChild(el('span', 'p-sub', line.code));
        cell(tr, qty(line.on_hand), 'p-num');

        var qtyTd = el('td', 'p-num');
        qtyTd.appendChild(qtyInput(line.qty, function (v) { line.qty = v; renderIs(); }));
        tr.appendChild(qtyTd);

        var after = num(line.on_hand) - num(line.qty);
        var afterTd = cell(tr, qty(String(after)), 'p-num');
        if (after < 0) {
          afterTd.classList.add('p-short');
          tr.className = 'p-row-bad';
        }

        var remarkTd = el('td');
        remarkTd.appendChild(textInput(line.remark, 200, function (v) { line.remark = v; }));
        tr.appendChild(remarkTd);

        var actTd = el('td');
        actTd.appendChild(button('빼기', 'btn btn--quiet btn--small', function () {
          isLines.splice(index, 1);
          renderIs();
        }));
        tr.appendChild(actTd);
        body.appendChild(tr);
        total += num(line.qty);
      });
      $('is-empty').hidden = isLines.length > 0;
      setText($('is-sum'), isLines.length === 0
        ? ''
        : '사용 줄 ' + isLines.length + '건 · 합계 ' + qty(String(total)) + ' 낱개');
    }

    function loadIs() {
      if ($('is-from').value === '') { say('쓴 장소를 고르세요.'); return; }
      // 실사 시작 API 가 「그 장소의 장부 수량」을 주므로 사용 탭도 같은 목록을 쓴다.
      request('GET', '/api/purchase/adj/start?location=' + encodeURIComponent($('is-from').value))
        .then(function (res) {
          if (!res) { return; }
          if (!res.ok) { say(errorMessage(res.data)); return; }
          isLines = (res.data.items || [])
            .filter(function (row) { return num(row.book_qty) > 0; })
            .map(function (row) {
              return {
                item_id: row.item_id, code: row.code, name: row.name,
                on_hand: row.book_qty, qty: '0', remark: ''
              };
            });
          renderIs();
        });
    }

    function saveIs() {
      var payload = {
        from_location_id: $('is-from').value,
        doc_date: $('is-date').value,
        lines: []
      };
      isLines.forEach(function (line) {
        if (num(line.qty) <= 0) { return; }
        var out = { item_id: line.item_id, qty: String(line.qty) };
        if (line.remark !== '') { out.remark = line.remark; }
        payload.lines.push(out);
      });
      if (payload.lines.length === 0) { say('사용 수량이 있는 줄이 없습니다.'); return; }
      request('POST', '/api/purchase/is', payload).then(function (res) {
        if (!res) { return; }
        if (!res.ok) { say(errorMessage(res.data)); return; }
        say('사용을 확정했습니다: ' + res.data.doc_no);
        isLines = [];
        renderIs();
        renderResult(res.data, 'is');
      });
    }

    /* ── 실사 ─────────────────────────────────────────── */
    function renderAdj() {
      var body = $('adj-body');
      clear(body);
      var diffCount = 0;
      adjLines.forEach(function (line, index) {
        var tr = el('tr');
        cell(tr, String(index + 1), 'p-col-no');
        var nameTd = cell(tr, line.name);
        nameTd.appendChild(el('span', 'p-sub', line.code));
        cell(tr, qty(line.book), 'p-num');

        var countedTd = el('td', 'p-num');
        countedTd.appendChild(qtyInput(line.counted, function (v) {
          line.counted = v;
          renderAdj();
        }));
        tr.appendChild(countedTd);

        var diff = num(line.counted) - num(line.book);
        var diffTd = cell(tr, diff === 0 ? '0' : qty(String(diff)), 'p-num');
        if (diff !== 0) {
          diffCount += 1;
          diffTd.classList.add(diff < 0 ? 'p-short' : 'p-plus');
        }
        cell(tr, line.par_qty === null ? '—' : String(line.par_qty), 'p-num');

        var remarkTd = el('td');
        remarkTd.appendChild(textInput(line.remark, 200, function (v) { line.remark = v; }));
        tr.appendChild(remarkTd);
        body.appendChild(tr);
      });
      $('adj-empty').hidden = adjLines.length > 0;
      setText($('adj-sum'), adjLines.length === 0
        ? ''
        : '센 품목 ' + adjLines.length + '건 · 차이 있는 줄 ' + diffCount + '건'
          + ' (차이가 있는 줄만 원장에 남습니다)');
    }

    function startAdj() {
      if ($('adj-location').value === '') { say('실사할 장소를 고르세요.'); return; }
      var params = '?location=' + encodeURIComponent($('adj-location').value)
        + '&cycle=' + encodeURIComponent($('adj-cycle').value);
      request('GET', '/api/purchase/adj/start' + params).then(function (res) {
        if (!res) { return; }
        if (!res.ok) { say(errorMessage(res.data)); return; }
        adjLines = (res.data.items || []).map(function (row) {
          return {
            item_id: row.item_id, code: row.code, name: row.name,
            book: row.book_qty, counted: row.book_qty, par_qty: row.par_qty, remark: ''
          };
        });
        renderAdj();
        say('장부를 불러왔습니다 — 「실제」 칸을 고쳐 주세요.');
      });
    }

    function saveAdj() {
      var payload = {
        location_id: $('adj-location').value,
        count_cycle: $('adj-cycle').value,
        doc_date: $('adj-date').value,
        lines: []
      };
      adjLines.forEach(function (line) {
        var out = {
          item_id: line.item_id,
          book_qty: String(line.book),
          counted_qty: String(line.counted === '' ? line.book : line.counted)
        };
        if (line.remark !== '') { out.remark = line.remark; }
        payload.lines.push(out);
      });
      if (payload.lines.length === 0) { say('센 줄이 없습니다.'); return; }
      request('POST', '/api/purchase/adj', payload).then(function (res) {
        if (!res) { return; }
        if (!res.ok) { say(errorMessage(res.data)); return; }
        say('실사를 확정했습니다: ' + res.data.doc_no);
        adjLines = [];
        renderAdj();
        renderResult(res.data, 'adj');
      });
    }

    $('mv-date').value = today();
    $('is-date').value = today();
    $('adj-date').value = today();

    $('mv-suggest').addEventListener('click', suggest);
    $('mv-save').addEventListener('click', saveMv);
    $('is-load').addEventListener('click', loadIs);
    $('is-save').addEventListener('click', saveIs);
    $('adj-start').addEventListener('click', startAdj);
    $('adj-save').addEventListener('click', saveAdj);
    $('mv-void').addEventListener('click', voidDoc);

    initTabs('move-tabs', {
      mv: { panel: 'move-mv' },
      is: { panel: 'move-is' },
      adj: { panel: 'move-adj' }
    });

    loadMaster('locations').then(function (rows) {
      fillSelect($('mv-from'), rows, 'id', 'name', '고르세요');
      fillSelect($('mv-to'), rows, 'id', 'name', '고르세요');
      fillSelect($('is-from'), rows, 'id', 'name', '고르세요');
      fillSelect($('adj-location'), rows, 'id', 'name', '고르세요');
    });

    renderMv();
    renderIs();
    renderAdj();
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
        return readJson(response);
      })
      .then(function (me) {
        if (me === null) { return; }
        csrfToken = typeof me.csrf_token === 'string' ? me.csrf_token : '';
        if ($('stock-tabs')) { initStatus(); }
        if ($('gr-load')) { initReceipt(); }
        if ($('move-tabs')) { initMove(); }
      })
      .catch(function () { say(GENERIC_ERROR); });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
}());
