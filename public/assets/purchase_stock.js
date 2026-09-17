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

  /* ── 공통 도우미 한 벌 (25단계) — purchase_common.js 하나에만 있다 ──
   * 다섯 번들이 각자 들고 있던 사본을 지웠다. 호출 경로·본문·응답 처리는 그대로다.
   */
  var UI = window.PurchaseUI;
  var $ = UI.$;
  var el = UI.el;
  var setText = UI.setText;
  var clear = UI.clear;
  var say = UI.say;
  var dash = UI.dash;
  var qty = UI.qty;
  var today = UI.today;
  var num = UI.toNumber;
  var errorMessage = UI.errorMessage;
  var cell = UI.cell;
  var badge = UI.badge;
  var button = UI.button;
  var kpi = UI.kpi;
  var fillSelect = UI.fillSelect;
  var initTabs = UI.initTabs;

  /* 재고 화면의 라우트는 재인증 대상이 아니다 — 꼬리표 없이 `/reauth` 로 보낸다
     (경로는 코드에 적힌 고정 문자열이고, 응답 값이 주소가 되지 않는다). */
  var apiClient = UI.api('');
  var request = apiClient.request;

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

  /** 기초정보 목록 — 서버가 이미 지점으로 좁혀 준다 */
  function loadMaster(kind) {
    return request('GET', '/api/purchase/' + kind).then(function (res) {
      if (!res || !res.ok) { return []; }
      return Array.isArray(res.data.items) ? res.data.items : [];
    });
  }

  /* 상태 어휘는 README 의 고정 어휘 그대로다 — 축약하지 않는다(「일부」 ✕ / 「일부 발주」 ○).
     색이 곧 뜻이다: 진행중 warn · 일부 발주 accent · 완료 ok · 취소 off · 미확인 기본. */
  var STATUS_LABEL = {
    unconfirmed: '미확인', in_progress: '진행중', partial: '일부 발주', done: '완료', void: '취소'
  };
  var STATUS_CLASS = {
    unconfirmed: '', in_progress: 'badge--warn',
    partial: 'badge--accent', done: 'badge--ok', void: 'badge--off'
  };
  function statusBadge(status) {
    return badge(STATUS_LABEL[status] || status, STATUS_CLASS[status] || '');
  }

  /* ══════════════════════════════════════════════════════
   * 화면 ① — 현황 (시안 ⑩ Status · ⑪ Backorder)
   * ══════════════════════════════════════════════════════ */

  function initStatus() {
    var stPage = 1;
    var lgPage = 1;
    var locations = [];
    /* 23단계 — 「담기」로 고른 줄. 키는 `장소id:품목id`, 값은 서버가 준 제안 한 줄 그대로다.
       화면은 제안을 **다시 계산하지 않는다** — 계산은 서버(`src/purchase/Suggest.php`)뿐이고,
       보낼 때도 수량이 아니라 「이 장소의 이 품목」만 보낸다(수량을 고친 때만 그 값을 싣는다). */
    var picked = {};

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

      /* 「제안 있는 것만」은 **다른 창구**다 — 같은 계산을 서버가 하고 제안 0 인 줄을 빼 준다.
         화면에서 거르지 않는 이유: 그러면 쪽 수·전체 건수가 표와 어긋난다. */
      var onlySuggest = $('st-suggest-only').checked;
      var path = onlySuggest ? '/api/purchase/suggest' : '/api/purchase/stock';
      if (onlySuggest) { params += '&only_short=1'; }

      request('GET', path + params).then(function (res) {
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
          var nameTd = cell(tr, row.name, 'p-name');
          nameTd.appendChild(el('span', 'p-sub mono', row.code + (row.spec ? ' · ' + row.spec : '')));
          cell(tr, row.location_name);

          /* 현재고 숫자를 누르면 **그 품목·그 장소**의 수불부가 열린다
             (DB 내부 id 를 사람이 타이핑하던 칸을 없앤 자리 — 25단계). */
          var onHandTd = el('td', 'num mono');
          onHandTd.appendChild(button(qty(row.on_hand), 'btn btn--plain btn--small', function () {
            openLedgerFor(row);
          }));
          tr.appendChild(onHandTd);

          cell(tr, row.par_qty === null ? '—' : String(row.par_qty), 'num mono');

          /* 과부족 — 모자라면 danger 배지, 딱 맞으면 warn 배지, 남으면 배지 없이 숫자 */
          var gapTd = el('td', 'num mono');
          if (num(row.gap) < 0) {
            gapTd.appendChild(badge(qty(row.gap), 'badge--danger'));
          } else if (num(row.gap) === 0) {
            gapTd.appendChild(badge('0', 'badge--warn'));
          } else {
            setText(gapTd, qty(row.gap));
            gapTd.classList.add('p-plus');
          }
          tr.appendChild(gapTd);

          /* 미입고 — 0 이면 배지 없이 흐리게 */
          var incTd = el('td', 'num mono');
          if (num(row.incoming) > 0) {
            incTd.appendChild(badge(qty(row.incoming), 'badge--warn'));
          } else {
            setText(incTd, '—');
            incTd.classList.add('muted');
          }
          tr.appendChild(incTd);

          cell(tr, qty(row.weekly_use), 'num mono');

          /* 발주 제안 — 값도 근거도 **서버가 준 것**이다 (PRD F-36). 여기서 더하지 않는다. */
          var sugN   = num(row.suggest_qty);
          var sugTd  = cell(tr, '', 'num');
          sugTd.appendChild(el('b', 'p-suggest', sugN > 0 ? qty(row.suggest_qty) : '—'));
          sugTd.appendChild(el('span', 'p-basis', basisText(row)));

          /* 담기 — 제안이 있는 줄만. 체크만 하고, 전표를 만드는 것은 서버다. */
          var pickTd = el('td', 'p-pick');
          if (sugN > 0) {
            var key = String(row.location_id) + ':' + String(row.item_id);
            var box = document.createElement('input');
            box.type = 'checkbox';
            box.checked = Object.prototype.hasOwnProperty.call(picked, key);
            box.addEventListener('change', function () {
              if (box.checked) { picked[key] = row; } else { delete picked[key]; }
              setText($('st-pick-info'), pickInfo());
            });
            pickTd.appendChild(box);
            var qtyIn = qtyInput(qty(row.suggest_qty), function (value) {
              if (picked[key]) { picked[key] = rowWithQty(row, value); }
            }, 'p-in p-in--qty');
            qtyIn.step = String(row.min_order_qty || 1);
            pickTd.appendChild(qtyIn);
          } else {
            pickTd.appendChild(el('span', 'p-sub', '—'));
          }
          tr.appendChild(pickTd);

          var actTd = el('td');
          actTd.appendChild(button('수불부', 'btn btn--quiet btn--small', function () {
            openLedgerFor(row);
          }));
          tr.appendChild(actTd);
          body.appendChild(tr);
        });

        $('st-empty').hidden = items.length > 0;
        var kpis = $('st-kpis');
        clear(kpis);
        kpi(kpis, '인가량 미달', String(shortCount), '이 페이지 기준', shortCount > 0);
        kpi(kpis, '미입고 수량', qty(String(incoming)), '이 페이지 기준');
        kpi(kpis, '전체 행', String(res.data.total), '장소 × 품목');
        setText($('st-page-info'), res.data.page + ' 쪽 · 전체 ' + res.data.total + '행');
      });
    }

    /** 재고 표의 한 줄에서 수불부 탭으로. 품목 id 는 **화면이 들고 있던 값**이다. */
    function openLedgerFor(row) {
      $('lg-location').value = String(row.location_id);
      $('lg-item').value = String(row.item_id);
      setText($('lg-item-name'), row.name + ' · ' + row.location_name);
      $('lg-item-box').hidden = false;
      lgPage = 1;
      UI.showTab('stock-tabs', 'ledger');
      loadLedger();
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
          var docTd = cell(tr, row.doc_no, 'mono');
          docTd.appendChild(el('span', 'p-sub', row.doc_type.toUpperCase()
            + (row.reverses ? ' · 되돌림' : '')));
          var itemTd = cell(tr, row.name, 'p-name');
          itemTd.appendChild(el('span', 'p-sub mono', row.code));
          cell(tr, row.location_name);
          var deltaTd = cell(tr, qty(row.qty_delta), 'num mono');
          deltaTd.classList.add(num(row.qty_delta) < 0 ? 'p-short' : 'p-plus');
          cell(tr, row.balance === null ? '—' : qty(row.balance), 'num mono');
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

    /**
     * 미입고 표 — **그리는 함수는 `PurchaseUI.renderBackorder` 한 벌**이다 (F-45).
     * 입고 화면의 「현황」 탭도 같은 함수를 부른다. 여기서 하는 일은 자기 화면의 칸을
     * 넘겨 주는 것뿐이라, 두 화면이 어긋날 자리가 없다.
     */
    function loadBackorder() {
      UI.renderBackorder($('bo-groups'), {
        request: request,
        kpis: $('bo-kpis'),
        empty: $('bo-empty'),
        vendor: $('bo-vendor'),
        overdue: $('bo-overdue'),
        q: $('bo-q')
      });
    }

    /** 제안의 **근거**를 그대로 읽어 준다 — 서버가 준 값만 이어 붙인다(계산 아님). */
    function basisText(row) {
      if (num(row.suggest_qty) <= 0) { return ''; }
      var lead = row.usage_basis === 'none'
        ? '조달 ' + String(row.lead_days) + '일 예상 0 (사용 기록 없음)'
        : '조달 ' + String(row.lead_days) + '일 예상 ' + qty(row.lead_use);
      return '(인가 ' + (row.par_qty === null ? '0' : String(row.par_qty))
        + ' · 안전 ' + String(row.safety_qty)
        + ' · 현재고 ' + qty(row.on_hand)
        + ' · 미입고 ' + qty(row.incoming)
        + ' · ' + lead
        + ' · 최소 ' + String(row.min_order_qty) + '단위)';
    }

    /** 고른 줄의 수량만 바꾼 사본 — 원본(서버가 준 제안)은 건드리지 않는다. */
    function rowWithQty(row, value) {
      var copy = {};
      Object.keys(row).forEach(function (k) { copy[k] = row[k]; });
      copy.pick_qty = value;
      return copy;
    }

    function pickedList() {
      return Object.keys(picked).map(function (k) { return picked[k]; });
    }

    function pickInfo() {
      var list = pickedList();
      if (list.length === 0) { return ''; }
      return '고른 제안 ' + String(list.length) + '줄';
    }

    /*
     * 고른 제안 → 발주요청 한 건. 장소가 섞이면 **보내지 않는다** — 요청 전표의 지점은
     * 장소에서 나오므로(서버 `Doc::locationBranch`) 한 전표에 두 장소를 담을 수 없다.
     * 수량을 고치지 않은 줄은 `qty` 를 아예 보내지 않는다 — 그러면 서버가 자기 제안값을 쓴다.
     */
    function applyPicked() {
      var list = pickedList();
      if (list.length === 0) { say('담을 제안을 먼저 고르세요.'); return; }
      var locId = list[0].location_id;
      var mixed = list.some(function (r) { return r.location_id !== locId; });
      if (mixed) {
        say('한 번에 한 장소씩 담을 수 있습니다 — 장소 필터로 좁힌 뒤 다시 고르세요.');
        return;
      }
      var lines = list.map(function (r) {
        if (r.pick_qty === undefined || r.pick_qty === null || String(r.pick_qty) === '') {
          return { item_id: r.item_id };
        }
        return { item_id: r.item_id, qty: String(r.pick_qty) };
      });
      request('POST', '/api/purchase/suggest/apply', { location_id: locId, lines: lines })
        .then(function (res) {
          if (!res) { return; }
          if (!res.ok) { say(errorMessage(res.data)); return; }
          picked = {};
          setText($('st-pick-info'), '');
          say('발주요청 ' + String(res.data.doc_no) + ' 을(를) 만들었습니다 ('
            + String((res.data.lines || []).length) + '줄 · 제안 합계 '
            + qty(res.data.suggested_qty) + ' · 담은 합계 ' + qty(res.data.applied_qty) + ').');
          loadStatus();
        });
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
    $('st-suggest-only').addEventListener('change', function () { stPage = 1; loadStatus(); });
    $('st-apply').addEventListener('click', applyPicked);
    $('st-prev').addEventListener('click', function () {
      if (stPage > 1) { stPage -= 1; loadStatus(); }
    });
    $('st-next').addEventListener('click', function () { stPage += 1; loadStatus(); });

    $('st-reload').addEventListener('click', function () { stPage = 1; loadStatus(); });
    $('lg-run').addEventListener('click', function () { lgPage = 1; loadLedger(); });
    $('lg-item-clear').addEventListener('click', function () {
      $('lg-item').value = '';
      $('lg-item-box').hidden = true;
      lgPage = 1;
      loadLedger();
    });
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
    var lastDocNo = '';

    function renderLines() {
      var body = $('gr-line-body');
      clear(body);
      var total = 0;
      lines.forEach(function (line, index) {
        var tr = el('tr');
        cell(tr, String(index + 1), 'p-col-no');
        var nameTd = cell(tr, line.name, 'p-name');
        nameTd.appendChild(el('span', 'p-sub mono', line.code));
        cell(tr, line.po_doc_no, 'mono');
        cell(tr, qty(line.ordered), 'num mono');
        cell(tr, qty(line.received), 'num mono');

        var qtyTd = el('td', 'num');
        qtyTd.appendChild(qtyInput(line.qty, function (value) {
          line.qty = value;
          renderLines();
        }));
        tr.appendChild(qtyTd);

        /* 남는 미입고 — 0 이면 ok, 일부 남으면 warn, 하나도 안 왔으면 danger.
           음수(발주보다 많이 적음)는 빨간 줄로 보이고, 막는 것은 서버다. */
        var left = num(line.remaining) - num(line.qty);
        var leftTd = el('td', 'num mono');
        if (left < 0) {
          leftTd.appendChild(badge(qty(String(left)), 'badge--danger'));
          tr.className = 'p-row-bad';
        } else if (left === 0) {
          leftTd.appendChild(badge('0', 'badge--ok'));
        } else if (left >= num(line.remaining)) {
          leftTd.appendChild(badge(qty(String(left)), 'badge--danger'));
        } else {
          leftTd.appendChild(badge(qty(String(left)), 'badge--warn'));
        }
        tr.appendChild(leftTd);

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
      $('gr-foot').hidden = lines.length === 0;
      setText($('gr-sum'), lines.length === 0
        ? ''
        : '입고 줄 ' + lines.length + '건 · 이번 입고 ' + qty(String(total)) + ' 낱개'
          + ' · 수량 0 인 줄은 보내지 않습니다');
    }

    /**
     * 입고 줄 하나를 담는다. **같은 발주서 줄은 한 번만** 들어간다(중복 방지 그대로).
     * 줄 객체의 칸 이름은 21단계 그대로다 — 입고 확정 본문이 그것을 쓴다.
     *
     * F-63: 셋째 인자는 불러오기 창의 「담을 수량」이다. 없으면 21단계와 같이 미입고
     * 잔량을 그대로 넣는다.
     */
    function addLine(order, line, qtyValue) {
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
        qty: (qtyValue === undefined || qtyValue === null || qtyValue === '')
          ? line.remaining : qtyValue,
        lot: '',
        exp: '',
        remark: ''
      });
      renderLines();
    }

    /* ══ 발주서 불러오기 창 (F-63) ══════════════════════════
     *
     * 21단계에는 이 자리에 `loadOrders()` 가 있었다 — 화면에 들어갈 때마다 자동으로
     * `GET /api/purchase/gr/load` 를 부르고, 발주서별 고정 묶음을 「입력」 탭 맨 위에
     * 펼쳐 그렸다. 이제 **단추 뒤 창**이고, 트리·묶음 체크·담을 수량을 그리는 코드는
     * 발주서 화면과 **한 벌**(`UI.loadPicker`)이다. 이 파일에 남은 것은 이 화면만의
     * 두 가지 — **줄 표의 열**과 **담은 뒤 무엇을 만드는지**다.
     */

    /** 창 안 줄 표의 열 — 체크 칸과 「담을 수량」 칸 사이 */
    function grLoadCells(tr, line) {
      cell(tr, line.code, 'mono');
      var nameTd = cell(tr, line.name);
      nameTd.className = 'p-name p-cell-wide';
      var sub = line.spec ? String(line.spec) : '';
      if (!line.stock_managed) { sub += (sub === '' ? '' : ' · ') + '재고관리 안 함'; }
      if (sub !== '') { nameTd.appendChild(el('span', 'p-sub', sub)); }

      var srcTd = el('td');
      srcTd.appendChild(el('span', 'mono', line.po_doc_no));
      srcTd.appendChild(el('span', 'p-sub',
        dash(line.manager_name) + ' · 보낸 지 ' + line.days_elapsed + '일'));
      tr.appendChild(srcTd);

      cell(tr, qty(line.ordered_qty), 'num mono');
      cell(tr, qty(line.received_qty), 'num mono');
      var remTd = el('td', 'num mono');
      remTd.appendChild(badge(qty(line.remaining), 'badge--warn'));
      tr.appendChild(remTd);
    }

    var grLoad = UI.loadPicker({
      request: request,
      path: '/api/purchase/gr/load',
      nodesKey: 'nodes',
      ids: {
        modal: 'gr-load-modal',
        groups: 'grload-groups',
        tree: 'grload-tree',
        treeEmpty: 'grload-tree-empty',
        linesTitle: 'grload-lines-title',
        body: 'grload-lines-body',
        linesEmpty: 'grload-lines-empty',
        info: 'grload-info',
        run: 'grload-run',
        add: 'grload-add'
      },
      /* 조회는 창 안 「불러오기」를 눌러야 돈다 — 창을 여는 것만으로는 한 번도 부르지
         않는다 (사용자 결정 2026-09-17, PRD 12.2 T-75 (f)). */
      runOnOpen: false,
      /* 기본은 **전표(발주서)** 한 층이다 — 사용자 요청이 「발주서에서 발주요청 불러오듯이
         **전표** 불러오도록」 이었다(PRD F-63). 다른 기준으로 묶고 싶으면 칩을 누른다. */
      groups: ['doc'],
      /* 왼쪽 마디를 「묶음」 이 아니라 「발주서」 라고 부른다 — 기본 층이 전표 하나다.
         조사가 달라 문장을 조립하지 않고 통째로 넘긴다. */
      nodeWord: '발주서',
      pickText: '왼쪽에서 발주서를 고르세요.',
      emptyText: '이 발주서에는 줄이 없습니다.',
      lineKey: function (line) { return line.po_line_id; },
      params: function () {
        var out = '';
        if ($('gr-vendor').value !== '') {
          out += '&vendor=' + encodeURIComponent($('gr-vendor').value);
        }
        if ($('gr-overdue').checked) { out += '&overdue=1'; }
        if ($('gr-q').value.trim() !== '') {
          out += '&q=' + encodeURIComponent($('gr-q').value.trim());
        }
        return out;
      },
      cells: grLoadCells,
      /* 담은 줄 — 서버가 준 줄을 그대로 들고 있는다. 발주서 번호도 **줄에** 실려 오므로
         묶음 머리를 들고 다닐 필요가 없다(여러 전표를 한 번에 담을 수 있는 이유다). */
      pickedFrom: function (line, qtyValue) {
        return { line: line, qty: qtyValue };
      },
      onAdd: function (list) {
        list.forEach(function (item) {
          addLine({ po_doc_no: item.line.po_doc_no }, item.line, item.qty);
        });
      }
    });

    function renderResult(doc) {
      lastDocId = doc.id;
      lastDocNo = doc.doc_no;
      $('gr-result').hidden = false;
      setText($('gr-result-head'), doc.doc_no + ' · ' + doc.doc_date
        + ' · 원장 ' + doc.moves + '행');
      var body = $('gr-result-body');
      clear(body);
      (doc.lines || []).forEach(function (line) {
        var tr = el('tr');
        cell(tr, String(line.line_no), 'p-col-no');
        var nameTd = cell(tr, line.name, 'p-name');
        nameTd.appendChild(el('span', 'p-sub mono', line.code));
        cell(tr, qty(line.qty), 'num mono');
        cell(tr, dash(line.lot_no) + (line.expiry_date ? ' · ' + line.expiry_date : ''));
        cell(tr, dash(line.src_doc_no), 'mono');
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
        /* F-63 — 21단계에는 여기서 불러오기 목록을 다시 읽었다(패널이 늘 펼쳐져 있었으므로).
           이제 목록은 창 안에만 있고 창은 닫힌 상태라, 다시 열 때 조회한다. */
      });
    }

    /* ── 사유 모달 (F-45) ────────────────────────────────
     * 26단계 계정 화면의 사유 모달과 **같은 형식**이다. 구매 화면에서 `window.prompt`
     * 를 늘리지 않으려는 것이 이유다 — 모달은 화면 안에 있고, 글자는 textContent 로만
     * 들어가며, 닫히는 길은 「취소 확정」·「그만두기」·Esc 셋뿐이다.
     *
     * `done(사유문자열)` 또는 `done(null)`(그만둠) 로 끝난다 — 어느 쪽이든 반드시 부른다.
     * 사유는 **비어 있어도 된다**: 입고 취소의 사유는 서버에서 선택값이고(마감된 달만
     * 필수), 여기서 더 조이면 화면이 서버보다 엄해져 판단이 두 곳으로 갈린다.
     */
    var reasonDone = null;

    function closeReason(value) {
      var done = reasonDone;
      reasonDone = null;
      var dialog = $('gr-reason');
      if (dialog && dialog.open) { dialog.close(); }
      if (done) { done(value); }
    }

    function askReason(lead, done) {
      var dialog = $('gr-reason');
      if (!dialog || typeof dialog.showModal !== 'function') {
        say(UI.GENERIC_ERROR);
        done(null);
        return;
      }
      reasonDone = done;
      setText($('grr-lead'), lead);
      setText($('grr-msg'), '');
      $('grr-text').value = '';
      dialog.showModal();
      $('grr-text').focus();
    }

    $('gr-reason-form').addEventListener('submit', function (event) {
      event.preventDefault();
      closeReason($('grr-text').value);
    });
    $('grr-cancel').addEventListener('click', function () { closeReason(null); });
    $('gr-reason').addEventListener('cancel', function (event) {
      // Esc 로 닫아도 「그만두었다」 가 남게 한다 (조용한 종료 금지).
      event.preventDefault();
      closeReason(null);
    });

    /**
     * 입고 취소 — **기존 API 그대로**다 (`POST /api/purchase/gr/{id}/void`, 21단계).
     * 입력 탭의 「이 전표 취소」와 전표 탭의 「입고 취소」가 같은 함수를 부른다.
     */
    function voidGr(docId, docNo, after) {
      askReason('입고 전표 ' + docNo + ' 을(를) 취소합니다. 원장에 반대 부호 행이 새로 들어가고'
        + ' 발주서의 미입고가 되돌아옵니다.', function (reason) {
        if (reason === null) { say('취소하지 않았습니다.'); return; }
        var payload = {};
        if (reason.trim() !== '') { payload.reason = reason.trim(); }
        request('POST', '/api/purchase/gr/' + docId + '/void', payload).then(function (res) {
          if (!res) { return; }
          if (!res.ok) { say(errorMessage(res.data)); return; }
          say('입고 전표를 취소했습니다 (원장에 반대 부호 행 ' + res.data.moves + '행).');
          /* F-63 — 되돌아온 미입고는 불러오기 창을 **다시 열 때** 보인다(같은 이유). */
          if (typeof after === 'function') { after(res.data); }
        });
      });
    }

    /* ══ 전표 탭 — 목록 · 상세 · 취소 ══════════════════════ */

    var docPage = 1;
    var openDocId = null;

    function loadDocs() {
      var params = '?page=' + docPage;
      if ($('gr-doc-q').value.trim() !== '') {
        params += '&q=' + encodeURIComponent($('gr-doc-q').value.trim());
      }
      if ($('gr-doc-vendor').value !== '') {
        params += '&vendor=' + encodeURIComponent($('gr-doc-vendor').value);
      }
      if ($('gr-doc-from').value !== '') {
        params += '&from=' + encodeURIComponent($('gr-doc-from').value);
      }
      if ($('gr-doc-to').value !== '') {
        params += '&to=' + encodeURIComponent($('gr-doc-to').value);
      }
      if ($('gr-doc-status').value !== '') {
        params += '&status=' + encodeURIComponent($('gr-doc-status').value);
      }

      request('GET', '/api/purchase/gr' + params).then(function (res) {
        if (!res) { return; }
        if (!res.ok) { say(errorMessage(res.data)); return; }
        var body = $('gr-doc-body');
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
          cell(tr, qty(row.qty_total), 'num mono');
          var st = el('td');
          st.appendChild(statusBadge(row.status));
          tr.appendChild(st);
          tr.addEventListener('click', function () { openDetail(row.id); });
          body.appendChild(tr);
        });
        $('gr-doc-empty').hidden = items.length > 0;
        setText($('gr-doc-page-info'), '전체 ' + res.data.total + '건 · ' + res.data.page + '쪽');
      });
    }

    function openDetail(id) {
      request('GET', '/api/purchase/gr/' + id).then(function (res) {
        if (!res) { return; }
        if (!res.ok) { say(errorMessage(res.data)); return; }
        openDocId = id;
        var doc = res.data.doc;
        setText($('gr-detail-title'), doc.doc_no);
        setText($('gr-detail-sub'), doc.doc_date + ' · ' + dash(doc.vendor_name)
          + ' · ' + dash(doc.location_name) + ' · 담당 ' + dash(doc.manager_name)
          + (doc.voided_at ? ' · 취소됨 ' + doc.voided_at.slice(0, 16).replace('T', ' ') : '')
          + (doc.closed_month ? ' · 마감 ' + doc.closed_month : '')
          + (doc.memo ? ' · ' + doc.memo : ''));

        var body = $('gr-detail-body');
        clear(body);
        var lines = res.data.lines || [];
        lines.forEach(function (line) {
          var tr = el('tr');
          if (line.status === 'void') { tr.className = 'p-row-bad'; }
          cell(tr, String(line.line_no), 'p-col-no');
          var nameTd = cell(tr, line.name, 'p-name p-cell-wide');
          nameTd.appendChild(el('span', 'p-sub mono', line.code));
          cell(tr, dash(line.spec));
          cell(tr, qty(line.qty), 'num mono');
          cell(tr, dash(line.lot_no));
          cell(tr, dash(line.expiry_date));
          var srcTd = cell(tr, dash(line.src_doc_no), 'mono');
          if (line.src_line_no !== null && line.src_line_no !== undefined) {
            srcTd.appendChild(el('span', 'p-sub', line.src_line_no + '번 줄'));
          }
          cell(tr, dash(line.remark));
          body.appendChild(tr);
        });
        setText($('gr-detail-totals'), '줄 ' + lines.length + '건 · 입고 수량 합계 '
          + qty(res.data.totals.qty) + ' 낱개');
        /* 단추를 보일지는 서버가 준 `can_void` 로만 정한다 — 화면은 역할을 판단하지 않는다. */
        $('gr-detail-void').hidden = !res.data.can_void;
        $('gr-detail').hidden = false;
      });
    }

    /* ══ 현황 탭 — 미입고. 재고 화면과 **같은 함수**를 부른다 (F-45) ══ */

    function loadStatus() {
      UI.renderBackorder($('gr-backorder'), {
        request: request,
        kpis: $('gr-bo-kpis'),
        empty: $('gr-bo-empty'),
        vendor: $('gr-bo-vendor'),
        overdue: $('gr-bo-overdue')
      });
    }

    $('gr-date').value = today();
    /* 단추는 **창만** 연다 — 조회 0회. 창 안 「불러오기」(`#grload-run`) 배선은
       `UI.loadPicker` 가 들고 있다 (F-63). */
    $('gr-load-open').addEventListener('click', function () { grLoad.open(); });
    $('gr-save').addEventListener('click', save);
    $('gr-void').addEventListener('click', function () {
      if (lastDocId === null) { return; }
      voidGr(lastDocId, lastDocNo, function () {
        $('gr-result').hidden = true;
        lastDocId = null;
        lastDocNo = '';
      });
    });

    $('gr-doc-q').addEventListener('change', function () { docPage = 1; loadDocs(); });
    $('gr-doc-vendor').addEventListener('change', function () { docPage = 1; loadDocs(); });
    $('gr-doc-from').addEventListener('change', function () { docPage = 1; loadDocs(); });
    $('gr-doc-to').addEventListener('change', function () { docPage = 1; loadDocs(); });
    $('gr-doc-status').addEventListener('change', function () { docPage = 1; loadDocs(); });
    $('gr-doc-prev').addEventListener('click', function () {
      if (docPage > 1) { docPage -= 1; loadDocs(); }
    });
    $('gr-doc-next').addEventListener('click', function () { docPage += 1; loadDocs(); });
    $('gr-detail-close').addEventListener('click', function () { $('gr-detail').hidden = true; });
    $('gr-detail-void').addEventListener('click', function () {
      if (openDocId === null) { return; }
      var docNo = $('gr-detail-title').textContent;
      voidGr(openDocId, docNo, function () {
        $('gr-detail').hidden = true;
        openDocId = null;
        loadDocs();
      });
    });

    $('gr-bo-run').addEventListener('click', loadStatus);
    $('gr-bo-vendor').addEventListener('change', loadStatus);
    $('gr-bo-overdue').addEventListener('change', loadStatus);

    initTabs('gr-tabs', {
      input: { panel: 'gr-input' },
      docs: { panel: 'gr-docs', onShow: loadDocs },
      status: { panel: 'gr-status', onShow: loadStatus }
    });

    /* 거래처 목록만 채운다 — **미입고 조회는 하지 않는다**(F-63). 21단계에는 여기서
       `loadOrders()` 를 불러 화면에 들어갈 때마다 300줄까지 읽었다. */
    loadMaster('vendors').then(function (rows) {
      fillSelect($('gr-vendor'), rows, 'id', 'name', '전체 거래처');
      fillSelect($('gr-doc-vendor'), rows, 'id', 'name', '전체 거래처');
      fillSelect($('gr-bo-vendor'), rows, 'id', 'name', '전체 거래처');
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
        cell(tr, qty(line.qty), 'num');
        cell(tr, line.counted_qty === null ? '—' : qty(line.counted_qty), 'num');
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
        cell(tr, qty(line.from_qty), 'num');
        cell(tr, qty(line.to_qty) + ' / ' + (line.par_qty === null ? '—' : line.par_qty), 'num');

        var qtyTd = el('td', 'num');
        qtyTd.appendChild(qtyInput(line.qty, function (v) { line.qty = v; renderMv(); }));
        tr.appendChild(qtyTd);

        var afterFrom = num(line.from_qty) - num(line.qty);
        var afterTo = num(line.to_qty) + num(line.qty);
        var afterTd = cell(tr, qty(String(afterFrom)) + ' / ' + qty(String(afterTo)), 'num');
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
      $('mv-foot').hidden = mvLines.length === 0;
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
        cell(tr, qty(line.on_hand), 'num');

        var qtyTd = el('td', 'num');
        qtyTd.appendChild(qtyInput(line.qty, function (v) { line.qty = v; renderIs(); }));
        tr.appendChild(qtyTd);

        var after = num(line.on_hand) - num(line.qty);
        var afterTd = cell(tr, qty(String(after)), 'num');
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
      $('is-foot').hidden = isLines.length === 0;
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
        cell(tr, qty(line.book), 'num');

        var countedTd = el('td', 'num');
        countedTd.appendChild(qtyInput(line.counted, function (v) {
          line.counted = v;
          renderAdj();
        }));
        tr.appendChild(countedTd);

        var diff = num(line.counted) - num(line.book);
        var diffTd = cell(tr, diff === 0 ? '0' : qty(String(diff)), 'num');
        if (diff !== 0) {
          diffCount += 1;
          diffTd.classList.add(diff < 0 ? 'p-short' : 'p-plus');
        }
        cell(tr, line.par_qty === null ? '—' : String(line.par_qty), 'num');

        var remarkTd = el('td');
        remarkTd.appendChild(textInput(line.remark, 200, function (v) { line.remark = v; }));
        tr.appendChild(remarkTd);
        body.appendChild(tr);
      });
      $('adj-empty').hidden = adjLines.length > 0;
      $('adj-foot').hidden = adjLines.length === 0;
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
        return UI.readJson(response);
      })
      .then(function (me) {
        if (me === null) { return; }
        apiClient.setCsrf(me.csrf_token);
        if ($('stock-tabs')) { initStatus(); }
        if ($('gr-form')) { initReceipt(); }
        if ($('move-tabs')) { initMove(); }
      })
      .catch(function () { say(UI.GENERIC_ERROR); });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
}());
