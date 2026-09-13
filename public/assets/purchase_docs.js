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

  /** 숫자 보기 좋게. 값 자체는 서버가 만든 문자열이며 여기서 바꾸지 않는다. */
  function money(value) {
    if (value === null || value === undefined || value === '') { return '—'; }
    var n = Number(value);
    if (isNaN(n)) { return String(value); }
    return n.toLocaleString('ko-KR');
  }

  /** 수량 — 소수점 뒤 0 은 지운다(5.000 → 5) */
  function qtyText(value) {
    if (value === null || value === undefined || value === '') { return '—'; }
    var n = Number(value);
    if (isNaN(n)) { return String(value); }
    return String(Math.round(n * 1000) / 1000);
  }

  function today() {
    var d = new Date();
    function two(v) { return (v < 10 ? '0' : '') + v; }
    return d.getFullYear() + '-' + two(d.getMonth() + 1) + '-' + two(d.getDate());
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
   *
   * 20단계의 라우트는 재인증 대상이 아니다(`purchase.read`·`request.write`·`buy.write`·`send`).
   * 그래도 관문이 늘어날 날을 위해 `next` 를 그대로 따른다 — 경로는 서버가 정한 **고정 키**
   * 목록에서만 나오고, 이 파일이 주소를 조립하지 않는다 (PRD F-23 오픈 리다이렉트 방지).
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

  function cell(row, text, className) {
    var td = el('td', className || null, text);
    row.appendChild(td);
    return td;
  }

  /** 상태 어휘 — 서버 값 → 사람이 읽는 말 (여기서 판단을 하지 않는다, 이름만 바꾼다) */
  var STATUS_LABEL = {
    unconfirmed: '미확인',
    in_progress: '진행중',
    partial: '일부 발주',
    done: '완료',
    void: '취소'
  };
  var STATUS_CLASS = {
    unconfirmed: 'p-badge',
    in_progress: 'p-badge p-badge--accent',
    partial: 'p-badge p-badge--warn',
    done: 'p-badge p-badge--ok',
    void: 'p-badge p-badge--danger'
  };

  function statusBadge(status) {
    return el('span', STATUS_CLASS[status] || 'p-badge', STATUS_LABEL[status] || status);
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

  function initTabs(navId, map) {
    var nav = $(navId);
    if (!nav) { return; }
    var buttons = nav.querySelectorAll('.p-tab');
    Array.prototype.forEach.call(buttons, function (button) {
      button.addEventListener('click', function () {
        Array.prototype.forEach.call(buttons, function (b) { b.classList.remove('is-on'); });
        button.classList.add('is-on');
        var wanted = button.getAttribute('data-tab');
        Object.keys(map).forEach(function (key) {
          var panel = $(map[key].panel);
          if (panel) { panel.hidden = key !== wanted; }
        });
        if (map[wanted] && typeof map[wanted].onShow === 'function') { map[wanted].onShow(); }
      });
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
      var sum = 0;
      lines.forEach(function (line, index) {
        var tr = el('tr');
        cell(tr, String(index + 1), 'p-col-no');
        cell(tr, line.code);
        cell(tr, line.name + (line.spec ? ' · ' + line.spec : ''));
        cell(tr, dash(line.vendor_name));

        var qtyTd = el('td', 'p-num');
        var qtyInput = document.createElement('input');
        qtyInput.type = 'number';
        qtyInput.min = '0.001';
        qtyInput.step = '0.001';
        qtyInput.value = String(line.qty);
        qtyInput.addEventListener('input', function () {
          line.qty = qtyInput.value;
          renderSum();
        });
        qtyTd.appendChild(qtyInput);
        tr.appendChild(qtyTd);

        cell(tr, money(line.price_in), 'p-num');
        var amount = line.price_in === null ? null : Number(line.price_in) * Number(line.qty || 0);
        cell(tr, amount === null || isNaN(amount) ? '—' : money(amount), 'p-num');

        var remarkTd = el('td');
        var remarkInput = document.createElement('input');
        remarkInput.type = 'text';
        remarkInput.maxLength = 200;
        remarkInput.value = line.remark || '';
        remarkInput.addEventListener('input', function () { line.remark = remarkInput.value; });
        remarkTd.appendChild(remarkInput);
        tr.appendChild(remarkTd);

        var actTd = el('td');
        var drop = el('button', 'btn btn--quiet btn--small', '빼기');
        drop.type = 'button';
        drop.addEventListener('click', function () {
          lines.splice(index, 1);
          renderLines();
        });
        actTd.appendChild(drop);
        tr.appendChild(actTd);

        body.appendChild(tr);
        if (amount !== null && !isNaN(amount)) { sum += amount; }
      });
      $('pr-line-empty').hidden = lines.length > 0;
      renderSum();
    }

    function renderSum() {
      var sum = 0;
      var count = 0;
      lines.forEach(function (line) {
        count += 1;
        if (line.price_in !== null && line.price_in !== undefined) {
          var v = Number(line.price_in) * Number(line.qty || 0);
          if (!isNaN(v)) { sum += v; }
        }
      });
      setText($('pr-sum'), '품목 ' + count + '건 · 합계(부가세 별도) ' + money(sum) + ' 원');
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
          cell(tr, money(row.price_in), 'p-num');

          var qtyTd = el('td', 'p-num');
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
      $('pick-modal').showModal();
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
      $('pick-modal').close();
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
          cell(tr, String(row.times), 'p-num');
          cell(tr, qtyText(row.avg_qty), 'p-num');
          cell(tr, row.last_date);
          var qtyTd = el('td', 'p-num');
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
        cell(tr, qtyText(row.default_qty), 'p-num');
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
      $('reco-modal').showModal();
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
      $('reco-modal').close();
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
    $('pr-form').addEventListener('submit', function (event) {
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
          cell(tr, row.doc_date + ' · ' + row.doc_no);
          cell(tr, dash(row.branch_name));
          cell(tr, dash(row.location_name));
          cell(tr, dash(row.vendor_name));
          cell(tr, dash(row.manager_name));
          cell(tr, String(row.line_count), 'p-num');
          cell(tr, money(row.amount), 'p-num');
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
          cell(tr, qtyText(line.qty), 'p-num');
          cell(tr, qtyText(line.taken_qty), 'p-num');
          cell(tr, qtyText(line.remaining), 'p-num');
          cell(tr, money(line.amount), 'p-num');
          var st = el('td');
          st.appendChild(el('span', 'p-badge', line.status === 'void' ? '취소'
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

    /* ── 현황(사이클) ──────────────────────────────────── */
    function loadCycle() {
      request('GET', '/api/purchase/cycle').then(function (res) {
        if (!res || !res.ok) { return; }
        var body = $('pr-cycle-body');
        clear(body);
        (res.data.rows || []).forEach(function (row) {
          var tr = el('tr');
          cell(tr, row.pr_doc_no + ' · ' + row.pr_date);
          cell(tr, dash(row.requester) + ' · ' + dash(row.location_name));
          cell(tr, row.name + ' · ' + row.code);
          cell(tr, qtyText(row.qty), 'p-num');
          var orders = (row.orders || []).map(function (o) { return o.doc_no; }).join(', ');
          cell(tr, orders === '' ? '—' : orders);
          cell(tr, qtyText(row.ordered_qty), 'p-num');
          cell(tr, qtyText(row.not_ordered), 'p-num');
          cell(tr, '—');
          var st = el('td');
          st.appendChild(statusBadge(row.pr_status));
          tr.appendChild(st);
          body.appendChild(tr);
        });
      });
    }

    initTabs('pr-tabs', {
      input: { panel: 'pr-input' },
      docs: { panel: 'pr-docs', onShow: loadDocs },
      status: { panel: 'pr-status-panel', onShow: loadCycle }
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
      var sum = 0;
      lines.forEach(function (line, index) {
        var tr = el('tr');
        cell(tr, String(index + 1), 'p-col-no');
        cell(tr, line.code);
        cell(tr, line.name + (line.spec ? ' · ' + line.spec : ''));
        cell(tr, line.doc_no + ' · ' + dash(line.manager_name));
        cell(tr, qtyText(line.remaining), 'p-num');

        var qtyTd = el('td', 'p-num');
        var qtyInput = document.createElement('input');
        qtyInput.type = 'number';
        qtyInput.min = '0.001';
        qtyInput.step = '0.001';
        qtyInput.value = String(line.qty);
        qtyInput.addEventListener('input', function () {
          line.qty = qtyInput.value;
          renderSum();
        });
        qtyTd.appendChild(qtyInput);
        tr.appendChild(qtyTd);

        cell(tr, money(line.price_in), 'p-num');
        var amount = line.price_in === null ? null : Number(line.price_in) * Number(line.qty || 0);
        cell(tr, amount === null || isNaN(amount) ? '—' : money(amount), 'p-num');

        var actTd = el('td');
        var drop = el('button', 'btn btn--quiet btn--small', '빼기');
        drop.type = 'button';
        drop.addEventListener('click', function () {
          lines.splice(index, 1);
          renderLines();
        });
        actTd.appendChild(drop);
        tr.appendChild(actTd);

        body.appendChild(tr);
        if (amount !== null && !isNaN(amount)) { sum += amount; }
      });
      $('po-line-empty').hidden = lines.length > 0;
      renderSum();
    }

    function renderSum() {
      var sum = 0;
      lines.forEach(function (line) {
        if (line.price_in === null || line.price_in === undefined) { return; }
        var v = Number(line.price_in) * Number(line.qty || 0);
        if (!isNaN(v)) { sum += v; }
      });
      setText($('po-sum'), '줄 ' + lines.length + '개 · 공급가액 ' + money(sum) + ' 원 (부가세 별도)');
    }

    /* ── 불러오기 모달 ─────────────────────────────────── */
    var pickedLines = {};

    function drawTree(nodes, container) {
      nodes.forEach(function (node) {
        var box = el('div', 'p-tree-group');
        var head = el('div', 'p-tree-head');
        head.appendChild(el('span', null, node.label));
        head.appendChild(el('span', 'p-tree-sum',
          '줄 ' + node.line_count + ' · 잔량 ' + qtyText(node.qty)));
        box.appendChild(head);

        if (node.children) {
          var kids = el('div', 'p-tree-children');
          drawTree(node.children, kids);
          box.appendChild(kids);
        } else if (node.lines) {
          var wrap = el('div', 'p-tree-children');
          node.lines.forEach(function (line) {
            var row = el('div', 'p-tree-line');
            var check = document.createElement('input');
            check.type = 'checkbox';
            row.appendChild(check);
            row.appendChild(el('span', 'p-tree-name',
              line.code + ' · ' + line.name + (line.spec ? ' · ' + line.spec : '')));
            row.appendChild(el('span', 'p-tree-sum',
              line.doc_no + ' · ' + dash(line.manager_name)
              + ' · 잔량 ' + qtyText(line.remaining)));
            var qtyInput = document.createElement('input');
            qtyInput.type = 'number';
            qtyInput.min = '0.001';
            qtyInput.step = '0.001';
            qtyInput.value = qtyText(line.remaining);
            row.appendChild(qtyInput);

            function sync() {
              if (check.checked) {
                pickedLines[String(line.line_id)] = {
                  src_line_id: line.line_id,
                  code: line.code,
                  name: line.name,
                  spec: line.spec,
                  doc_no: line.doc_no,
                  manager_name: line.manager_name,
                  price_in: line.price_in,
                  remaining: line.remaining,
                  vendor_id: line.doc_vendor_id === null ? line.item_vendor_id : line.doc_vendor_id,
                  qty: qtyInput.value
                };
              } else {
                delete pickedLines[String(line.line_id)];
              }
              setText($('load-info'), '고른 줄 ' + Object.keys(pickedLines).length + '개');
            }
            check.addEventListener('change', sync);
            qtyInput.addEventListener('input', function () { if (check.checked) { sync(); } });

            wrap.appendChild(row);
          });
          box.appendChild(wrap);
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
        drawTree(res.data.groups || [], tree);
        setText($('load-info'), '미발주 줄 ' + res.data.line_count + '개 · 잔량 합계 '
          + qtyText(res.data.qty) + (res.data.truncated ? ' (상한에서 끊김 — 조건을 좁혀 주세요)' : ''));
      });
    }

    var groupNav = $('load-groups');
    Array.prototype.forEach.call(groupNav.querySelectorAll('.chip'), function (chip) {
      chip.addEventListener('click', function () {
        var key = chip.getAttribute('data-group');
        var at = groups.indexOf(key);
        if (at >= 0) {
          groups.splice(at, 1);
          chip.classList.remove('is-on');
        } else if (groups.length < 3) {
          groups.push(key);
          chip.classList.add('is-on');
        } else {
          say('묶는 기준은 세 개까지입니다.');
          return;
        }
        setText($('load-order'), groups.length === 0 ? '' : '층 순서: ' + groups.join(' › '));
      });
    });

    $('po-load').addEventListener('click', function () {
      pickedLines = {};
      if (groups.length === 0) {
        groups = ['vendor'];
        var first = groupNav.querySelector('.chip[data-group="vendor"]');
        if (first) { first.classList.add('is-on'); }
        setText($('load-order'), '층 순서: vendor');
      }
      $('load-modal').showModal();
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
      $('load-modal').close();
    });

    /* ── 저장 ──────────────────────────────────────────── */
    $('po-form').addEventListener('submit', function (event) {
      event.preventDefault();
      if (lines.length === 0) { say('발주요청에서 줄을 담아 주세요.'); return; }
      if ($('po-location').value === '') { say('입고 장소를 골라 주세요.'); return; }
      var payload = {
        doc_date: $('po-date').value,
        to_location_id: Number($('po-location').value),
        lines: []
      };
      if ($('po-vendor').value !== '') { payload.vendor_id = Number($('po-vendor').value); }
      if ($('po-memo').value !== '') { payload.memo = $('po-memo').value; }
      lines.forEach(function (line) {
        // 여기도 **수량만** 보낸다 (R-66).
        payload.lines.push({ src_line_id: line.src_line_id, qty: String(line.qty) });
      });
      request('POST', '/api/purchase/po', payload).then(function (res) {
        if (!res) { return; }
        if (!res.ok) { say(errorMessage(res.data)); return; }
        say('발주서 ' + res.data.doc_no + ' 을(를) 만들었습니다.');
        lines = [];
        renderLines();
        loadDocs();
        openDetail(res.data.id);
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
          cell(tr, row.doc_date + ' · ' + row.doc_no);
          cell(tr, dash(row.vendor_name));
          cell(tr, dash(row.location_name));
          cell(tr, dash(row.manager_name));
          cell(tr, String(row.line_count), 'p-num');
          cell(tr, money(row.amount), 'p-num');
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
        $('po-print').href = '/purchase/po/' + id + '/print';

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
          cell(tr, line.code);
          cell(tr, line.name);
          cell(tr, dash(line.src_doc_no));
          cell(tr, qtyText(line.qty), 'p-num');
          cell(tr, money(line.unit_price), 'p-num');
          cell(tr, money(line.amount), 'p-num');
          body.appendChild(tr);
        });
        setText($('po-detail-totals'), '공급가액 ' + money(res.data.totals.supply)
          + ' · 부가세 ' + money(res.data.totals.vat)
          + ' · 합계 ' + money(res.data.totals.total));

        $('po-send').hidden = !res.data.can_send;
        $('po-void').hidden = !res.data.can_write;
        $('po-head-form').hidden = !res.data.can_write;
        $('po-detail').hidden = false;
      });
    }

    $('po-detail-close').addEventListener('click', function () { $('po-detail').hidden = true; });

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
          var tr = el('tr');
          cell(tr, row.doc_date + ' · ' + row.doc_no);
          cell(tr, row.doc_type === 'pr' ? '발주요청' : '발주서');
          cell(tr, dash(row.vendor_name));
          cell(tr, dash(row.branch_name) + ' ' + dash(row.location_name));
          cell(tr, dash(row.manager_name));
          cell(tr, String(row.line_count), 'p-num');
          cell(tr, money(row.amount), 'p-num');
          var st = el('td');
          st.appendChild(statusBadge(row.status));
          tr.appendChild(st);
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
      request('GET', '/api/purchase/cycle' + params).then(function (res) {
        if (!res || !res.ok) { return; }
        var body = $('cycle-body');
        clear(body);
        (res.data.rows || []).forEach(function (row) {
          var tr = el('tr');
          cell(tr, row.pr_doc_no + ' · ' + row.pr_date);
          cell(tr, dash(row.requester) + ' · ' + dash(row.location_name));
          cell(tr, row.name + ' · ' + row.code);
          cell(tr, qtyText(row.qty), 'p-num');
          var orders = (row.orders || []).map(function (o) {
            return o.doc_no + (o.sent_at ? ' (보냄)' : '');
          }).join(', ');
          cell(tr, orders === '' ? '—' : orders);
          cell(tr, qtyText(row.ordered_qty), 'p-num');
          cell(tr, qtyText(row.not_ordered), 'p-num');
          cell(tr, '—');
          cell(tr, '—', 'p-num');
          var st = el('td');
          st.appendChild(statusBadge(row.pr_status));
          tr.appendChild(st);
          body.appendChild(tr);
        });
        setText($('cycle-info'), '요청 줄 ' + res.data.total + '개'
          + (res.data.truncated ? ' (상한에서 끊김 — 기간을 좁혀 주세요)' : ''));
      });
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
        return readJson(response);
      })
      .then(function (me) {
        if (me === null) { return; }
        csrfToken = typeof me.csrf_token === 'string' ? me.csrf_token : '';
        if ($('pr-tabs')) { initPr(); }
        if ($('po-tabs')) { initPo(); }
        if ($('docs-tabs')) { initDocs(); }
      })
      .catch(function () { say(GENERIC_ERROR); });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
}());
