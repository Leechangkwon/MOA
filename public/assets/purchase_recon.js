/* public/assets/purchase_recon.js — 주간 사용량 · 월 대사 · 월 마감 · 기공 명세서
 * (22단계, PRD F-34 · F-35, 시안 Weekly · Recon)
 *
 * 19단계 `purchase.js`·20단계 `purchase_docs.js`·21단계 `purchase_stock.js` 와 **별도 파일**이다
 * (지시서: 다른 JS 를 고치지 않는다). 두 화면이 이 한 파일을 읽고, 어느 화면인지는 DOM 에 있는
 * id 로 고른다.
 *
 * 지키는 것 (PRD S-01 · S-03, ARCH 13장)
 *   · **inner/outer HTML 계열과 document.write 를 쓰지 않는다.** 글자를 넣는 길은
 *     `textContent` 하나뿐이다 — 품목명·비고·거래처 이름·명세서 파일명은 사람이 적은
 *     문자열이고, 그중 하나라도 태그로 해석되면 그것이 XSS 다.
 *   · **권한을 브라우저에서 판단하지 않는다.** 「이 달 마감」을 눌러도 서버가 라우트 정책
 *     `purchase.close` 와 재인증 관문으로 다시 막는다. 화면은 편의일 뿐 근거가 아니다.
 *   · **금액을 보내지 않는다.** 기공 명세서 저장 본문에 단가·금액 칸이 아예 없다 —
 *     단가는 서버가 `p_items.price_in` 에서 읽는다 (SECURITY R-66).
 *   · **지점을 화면이 고르지 않는다.** 목록에 무엇이 보일지는 서버의 SQL WHERE 가 정한다.
 *   · 상태 변경 요청에는 `X-CSRF-Token` 을 싣는다. 토큰은 `/api/me` 가 준다.
 *   · 재인증이 필요하면 서버가 준 **고정 키**로만 이동한다(`/reauth?to=purchaserecon`) —
 *     주소를 응답 값에서 조립하지 않는다 (PRD F-23 오픈 리다이렉트 방지).
 *
 * 다른 파일과 겹치지 않게 모든 것이 즉시 실행 함수 안에 있다(전역 0개).
 */
(function () {
  'use strict';

  /* 오류 문구는 purchase_common.js 에 한 벌 있다 (UI.GENERIC_ERROR). */

  /** 재인증 뒤 돌아올 **고정 키** — 서버 TBOX_REAUTH_PATHS·app.js REAUTH_PATHS 와 같은 값 */
  var REAUTH_KEY = 'purchaserecon';

  /** 판정 배지의 색 = 뜻 (시안 README) — 일치 ok · 차이 warn · 한쪽에만 있음 danger */
  var DIFF_CLASS = {
    'none': 'badge--ok',
    'qty': 'badge--warn',
    'price': 'badge--warn',
    'missing_ours': 'badge--danger',
    'missing_theirs': 'badge--danger'
  };

  /** 차이 판정의 한국어 이름. 서버가 정한 다섯 값 말고는 만들지 않는다. */
  var DIFF_LABELS = {
    'none': '일치',
    'qty': '수량 차이',
    'price': '단가 차이',
    'missing_ours': '명세서만 있음',
    'missing_theirs': '우리만 있음'
  };

  /** 열 맞추기 칸의 한국어 이름 (서버 Recon::TARGETS 와 같은 키) */
  var TARGET_LABELS = {
    'item_code': '품목코드',
    'item_name': '품목명 (필수)',
    'qty': '수량 (필수)',
    'unit_price': '단가',
    'amount': '금액'
  };

  /* ── 작은 도우미 ─────────────────────────────────────── */

  /* ── 공통 도우미 한 벌 (25단계) — purchase_common.js 하나에만 있다 ── */
  var UI = window.PurchaseUI;
  var $ = UI.$;
  var el = UI.el;
  var setText = UI.setText;
  var clear = UI.clear;
  var say = UI.say;
  var dash = UI.dash;
  var qty = UI.qty;
  var money = UI.money;
  var today = UI.today;
  var errorMessage = UI.errorMessage;
  var cell = UI.cell;
  var badge = UI.badge;
  var button = UI.button;
  var kpi = UI.kpi;
  var fillSelect = UI.fillSelect;
  var initTabs = UI.initTabs;

  function thisMonth() { return today().slice(0, 7); }

  /* 월 마감은 재인증 대상이다 — 확인을 마치면 **이 화면으로** 돌아오도록
     코드에 적힌 키를 붙인다(응답 값이 주소가 되지 않는다). */
  var apiClient = UI.api(REAUTH_KEY);
  var request = apiClient.request;
  var upload = apiClient.upload;

  /* ══════════════════════════════════════════════════════
   * 주간 사용량 (시안 Weekly)
   * ══════════════════════════════════════════════════════ */

  function initWeekly() {
    var head = $('wk-head');
    var body = $('wk-body');

    function draw(data) {
      clear(head);
      clear(body);
      var weeks = data.weeks || [];
      var cells = data.cells || [];
      var branchFilter = $('wk-branch').value;

      // 행 이름 → 주 → 합
      var rows = {};
      var order = [];
      var weekTotals = {};
      var grand = 0;
      cells.forEach(function (c) {
        if (branchFilter !== '' && String(c.branch_id) !== branchFilter) { return; }
        if (!rows[c.name]) { rows[c.name] = {}; order.push(c.name); }
        var n = Number(c.qty);
        if (isNaN(n)) { n = 0; }
        rows[c.name][c.week] = (rows[c.name][c.week] || 0) + n;
        weekTotals[c.week] = (weekTotals[c.week] || 0) + n;
        grand += n;
      });
      order.sort();

      head.appendChild(el('th', null, data.group_label || '분류'));
      weeks.forEach(function (w) { head.appendChild(el('th', 'num p-week-head', w)); });
      head.appendChild(el('th', 'num p-week-total', '합계'));

      order.forEach(function (name) {
        var tr = el('tr');
        cell(tr, name);
        var sum = 0;
        weeks.forEach(function (w) {
          var v = rows[name][w] || 0;
          sum += v;
          cell(tr, v === 0 ? '—' : qty(v), 'num mono');
        });
        cell(tr, qty(sum), 'num mono p-week-total');
        body.appendChild(tr);
      });

      if (order.length > 0) {
        var tot = el('tr', 'p-row-total');
        cell(tot, '합계 (낱개)');
        weeks.forEach(function (w) {
          cell(tot, qty(weekTotals[w] || 0), 'num mono');
        });
        cell(tot, qty(grand), 'num mono p-week-total');
        body.appendChild(tot);
      }

      $('wk-empty').hidden = order.length > 0;
      $('wk-foot').hidden = !data.truncated;
      setText($('wk-range'), String(data.from) + ' ~ ' + String(data.to));
      /* 기간 칸을 **서버가 고른 범위**로 채운다 — 「‹ 전주 / 다음 주 ›」가 그 값을 7일 민다. */
      if ($('wk-from').value === '') { $('wk-from').value = String(data.from); }
      if ($('wk-to').value === '') { $('wk-to').value = String(data.to); }

      var kpis = $('wk-kpis');
      clear(kpis);
      kpi(kpis, '기간', String(weeks.length) + '주', String(data.from) + ' ~ ' + String(data.to));
      kpi(kpis, '합계 (낱개)', qty(grand), data.group_label + ' ' + String(order.length) + '줄');
      kpi(kpis, '지점', branchFilter === '' ? '전체' : $('wk-branch').selectedOptions[0].textContent,
          '보이는 범위는 서버가 정합니다');
    }

    function load() {
      var params = '?from=' + encodeURIComponent($('wk-from').value || '')
                 + '&to=' + encodeURIComponent($('wk-to').value || '')
                 + '&group=' + encodeURIComponent($('wk-group').value || 'cat1');
      say('');
      request('GET', '/api/purchase/usage/weekly' + params).then(function (res) {
        if (!res) { return; }
        if (!res.ok) { say(errorMessage(res.data)); return; }
        fillSelect($('wk-branch'), res.data.branches || [], 'id', 'name', '전체');
        draw(res.data);
      }).catch(function () { say(UI.GENERIC_ERROR); });
    }

    /** 기간을 7일씩 민다 — 조회 로직은 그대로 `load()` 를 다시 부른다 */
    function shiftWeek(days) {
      var from = $('wk-from').value;
      var to = $('wk-to').value;
      if (from === '' || to === '') { say('먼저 기간을 골라 주세요.'); return; }
      function moved(value) {
        var d = new Date(value + 'T00:00:00');
        d.setDate(d.getDate() + days);
        function two(v) { return (v < 10 ? '0' : '') + v; }
        return d.getFullYear() + '-' + two(d.getMonth() + 1) + '-' + two(d.getDate());
      }
      $('wk-from').value = moved(from);
      $('wk-to').value = moved(to);
      load();
    }

    $('wk-run').addEventListener('click', load);
    $('wk-group').addEventListener('change', load);
    $('wk-branch').addEventListener('change', load);
    $('wk-prev').addEventListener('click', function () { shiftWeek(-7); });
    $('wk-next').addEventListener('click', function () { shiftWeek(7); });
    load();
  }

  /* ══════════════════════════════════════════════════════
   * 월 대사 · 월 마감 · 기공 명세서 (시안 Recon)
   * ══════════════════════════════════════════════════════ */

  function initRecon() {
    var current = null;     // 지금 열어 둔 명세서 (서버 응답 그대로)
    var labLines = [];      // 기공 명세서에 담은 줄

    /* ── 탭 — 공통 한 벌(initTabs)을 쓴다 ── */
    initTabs('recon-tabs', {
      recon: { panel: 'recon-main', onShow: function () { say(''); } },
      close: { panel: 'recon-close', onShow: function () { say(''); } },
      lab:   { panel: 'recon-lab',   onShow: function () { say(''); } }
    });

    /* ── 명세서 목록 ── */
    function loadList() {
      request('GET', '/api/purchase/recon').then(function (res) {
        if (!res) { return; }
        if (!res.ok) { say(errorMessage(res.data)); return; }
        var body = $('rc-list');
        clear(body);
        var rows = res.data.statements || [];
        rows.forEach(function (st) {
          var tr = el('tr');
          cell(tr, st.vendor_name);
          cell(tr, st.month);
          cell(tr, st.file_name);
          cell(tr, st.committed ? String(st.lines) : '—', 'num');
          cell(tr, st.committed ? String(st.diffs) : '—', 'num');
          cell(tr, money(st.total_amount), 'num');
          cell(tr, st.created_by);
          var act = el('td');
          act.appendChild(button(st.committed ? '대사표 열기' : '열 맞추기', 'btn btn--quiet btn--small',
            function () { openStatement(st.id); }));
          tr.appendChild(act);
          body.appendChild(tr);
        });
        $('rc-empty').hidden = rows.length > 0;
      }).catch(function () { say(UI.GENERIC_ERROR); });
    }

    /* ── 열 맞추기 ── */
    function drawMapping(data) {
      var wrap = $('rc-map-fields');
      clear(wrap);
      var columns = data.columns || [];
      (data.targets || []).forEach(function (target) {
        var field = el('div', 'p-map__field');
        field.appendChild(el('div', 'p-map__label', TARGET_LABELS[target] || target));
        var select = el('select');
        select.setAttribute('data-target', target);
        var blank = el('option', null, '— 없음 —');
        blank.value = '';
        select.appendChild(blank);
        columns.forEach(function (name, idx) {
          var label = String(idx + 1) + '. ' + (String(name) === '' ? '(빈 칸)' : String(name));
          var opt = el('option', null, label);
          opt.value = String(idx);
          select.appendChild(opt);
        });
        if (data.mapping && data.mapping[target] !== undefined && data.mapping[target] !== null) {
          select.value = String(data.mapping[target]);
        }
        field.appendChild(select);
        wrap.appendChild(field);
      });

      var hr = $('rc-header-row');
      clear(hr);
      (data.head_rows || []).forEach(function (row, idx) {
        var joined = Array.prototype.slice.call(row, 0, 6).join(' | ');
        var opt = el('option', null, String(idx + 1) + '행: ' + joined);
        opt.value = String(idx);
        hr.appendChild(opt);
      });
      hr.value = String(data.header_row || 0);
      $('rc-map').hidden = false;
    }

    function readMapping() {
      var mapping = {};
      Array.prototype.forEach.call($('rc-map-fields').querySelectorAll('select'), function (select) {
        if (select.value !== '') {
          mapping[select.getAttribute('data-target')] = Number(select.value);
        }
      });
      return mapping;
    }

    /* ── 대조 결과 ── */
    function drawResult(data) {
      var kpis = $('rc-kpis');
      clear(kpis);
      var counts = data.diff_counts || {};
      var totals = data.totals || {};
      kpi(kpis, '우리 구매 합계', money(totals.ours), '입고 전표에서 서버가 계산');
      kpi(kpis, '거래처 명세서 합계', money(totals.theirs), '명세서 파일의 값');
      kpi(kpis, '차이', money(totals.diff),
          '수량 ' + String(counts.qty || 0) + ' · 단가 ' + String(counts.price || 0)
          + ' · 명세서만 ' + String(counts.missing_ours || 0)
          + ' · 우리만 ' + String(counts.missing_theirs || 0),
          Number(totals.diff || 0) !== 0);
      kpi(kpis, '일치', String(counts.none || 0) + '줄', '전체 ' + String((data.lines || []).length) + '줄');

      var body = $('rc-lines');
      clear(body);
      var onlyDiff = $('rc-only-diff').checked;
      (data.lines || []).forEach(function (line) {
        if (onlyDiff && line.diff_type === 'none') { return; }
        var tr = el('tr', line.diff_type === 'none' ? null : 'p-row-bad');
        cell(tr, String(line.row_no), 'p-col-no');
        cell(tr, dash(line.branch_name));
        var item = el('td');
        item.appendChild(el('span', null, String(line.item_name)));
        if (line.item_code) { item.appendChild(el('span', 'p-sub mono', String(line.item_code))); }
        if (line.our_doc_no) { item.appendChild(el('span', 'p-sub mono', String(line.our_doc_no))); }
        tr.appendChild(item);
        cell(tr, qty(line.our_qty), 'num mono');
        cell(tr, money(line.our_unit_price), 'num mono');
        cell(tr, money(line.our_amount), 'num mono');
        cell(tr, line.diff_type === 'missing_theirs' ? '—' : qty(line.qty), 'num mono');
        cell(tr, line.diff_type === 'missing_theirs' ? '—' : money(line.unit_price), 'num mono');
        cell(tr, line.diff_type === 'missing_theirs' ? '—' : money(line.amount), 'num mono');
        cell(tr, money(line.amount_diff !== undefined ? line.amount_diff : null), 'num mono');
        var judge = el('td');
        judge.appendChild(badge(DIFF_LABELS[line.diff_type] || String(line.diff_type),
                                DIFF_CLASS[line.diff_type] || ''));
        tr.appendChild(judge);
        cell(tr, dash(line.note));
        body.appendChild(tr);
      });
      $('rc-result').hidden = false;
    }

    function openStatement(id) {
      request('GET', '/api/purchase/recon/' + String(id)).then(function (res) {
        if (!res) { return; }
        if (!res.ok) { say(errorMessage(res.data)); return; }
        current = res.data;
        if (res.data.committed) {
          $('rc-map').hidden = true;
          drawResult(res.data);
          say('확정된 대사표입니다 — 그때의 판정이 그대로 남아 있습니다.');
        } else {
          drawMapping(res.data);
          $('rc-result').hidden = true;
          say('열을 맞춘 뒤 「맞춰 보기」로 결과를 확인하고 확정하세요.');
        }
      }).catch(function () { say(UI.GENERIC_ERROR); });
    }

    $('rc-upload').addEventListener('click', function () {
      var file = $('rc-file').files && $('rc-file').files[0];
      if (!file) { say('올릴 명세서 파일을 고르세요.'); return; }
      if (!$('rc-vendor').value) { say('거래처를 고르세요.'); return; }
      if (!$('rc-month').value) { say('명세서의 달을 고르세요.'); return; }
      var form = new FormData();
      form.append('vendor_id', $('rc-vendor').value);
      form.append('month', $('rc-month').value);
      form.append('file', file);
      say('올리는 중…');
      upload('/api/purchase/recon', form).then(function (res) {
        if (!res) { return; }
        if (!res.ok) { say(errorMessage(res.data)); return; }
        current = res.data;
        current.committed = false;
        loadList();
        if (res.data.text_empty) {
          say('파일에서 표를 읽지 못했습니다 (글자가 없는 PDF 일 수 있습니다). 엑셀로 다시 올려 주세요.');
          return;
        }
        drawMapping(res.data);
        $('rc-result').hidden = true;
        say('열을 맞춘 뒤 「맞춰 보기」를 누르세요.');
      }).catch(function () { say(UI.GENERIC_ERROR); });
    });

    $('rc-reload').addEventListener('click', loadList);
    $('rc-only-diff').addEventListener('change', function () {
      if (current) { drawResult(current.lastResult || current); }
    });

    $('rc-preview').addEventListener('click', function () {
      if (!current) { say('먼저 명세서를 고르세요.'); return; }
      var id = current.statement_id;
      request('POST', '/api/purchase/recon/' + String(id) + '/preview', {
        mapping: readMapping(),
        header_row: Number($('rc-header-row').value || 0)
      }).then(function (res) {
        if (!res) { return; }
        if (!res.ok) { say(errorMessage(res.data)); return; }
        current.lastResult = res.data;
        drawResult(res.data);
        say('맞춰 봤습니다 — 아직 저장하지 않았습니다.');
      }).catch(function () { say(UI.GENERIC_ERROR); });
    });

    $('rc-commit').addEventListener('click', function () {
      if (!current) { say('먼저 명세서를 고르세요.'); return; }
      var id = current.statement_id;
      request('POST', '/api/purchase/recon/' + String(id) + '/commit', {
        mapping: readMapping(),
        header_row: Number($('rc-header-row').value || 0)
      }).then(function (res) {
        if (!res) { return; }
        if (!res.ok) { say(errorMessage(res.data)); return; }
        say('대사를 확정했습니다 (' + String(res.data.lines) + '줄).');
        loadList();
        openStatement(id);
      }).catch(function () { say(UI.GENERIC_ERROR); });
    });

    /* ── 월 마감 ── */
    function loadCloses() {
      request('GET', '/api/purchase/close').then(function (res) {
        if (!res) { return; }
        if (!res.ok) { say(errorMessage(res.data)); return; }
        var body = $('cl-list');
        clear(body);
        var rows = res.data.closes || [];
        rows.forEach(function (c) {
          var tr = el('tr');
          cell(tr, c.month);
          cell(tr, c.branch_id === null ? '전사' : String(c.branch_name));
          cell(tr, c.closed_by);
          cell(tr, c.closed_at);
          cell(tr, dash(c.reason_text));
          body.appendChild(tr);
        });
        $('cl-empty').hidden = rows.length > 0;
      }).catch(function () { say(UI.GENERIC_ERROR); });
    }

    $('cl-run').addEventListener('click', function () {
      if (!$('cl-month').value) { say('마감할 달을 고르세요.'); return; }
      var payload = { month: $('cl-month').value };
      if ($('cl-branch').value !== '') { payload.branch_id = Number($('cl-branch').value); }
      if ($('cl-reason').value !== '') { payload.reason_text = $('cl-reason').value; }
      say('마감하는 중…');
      request('POST', '/api/purchase/close', payload).then(function (res) {
        if (!res) { return; }
        if (!res.ok) { say(errorMessage(res.data)); return; }
        say('마감했습니다 — 전표 ' + String(res.data.docs_closed) + '건이 잠겼습니다.');
        loadCloses();
      }).catch(function () { say(UI.GENERIC_ERROR); });
    });

    /* ── 기공 명세서 ── */
    function drawLab() {
      var body = $('lb-body');
      clear(body);
      labLines.forEach(function (line, idx) {
        var tr = el('tr');
        var item = el('td');
        item.appendChild(el('span', null, String(line.name)));
        item.appendChild(el('span', 'p-sub', String(line.code)));
        tr.appendChild(item);
        var flag = el('td');
        flag.appendChild(line.stock_managed
          ? badge('재고관리 — 담을 수 없음', 'badge--danger')
          : badge('재고관리 안 함', 'badge--ok'));
        tr.appendChild(flag);
        var qtyCell = el('td', 'num');
        var input = document.createElement('input');
        input.type = 'number';
        input.min = '0';
        input.step = '0.001';
        input.className = 'p-in';
        input.value = String(line.qty);
        input.addEventListener('input', function () { labLines[idx].qty = input.value; });
        qtyCell.appendChild(input);
        tr.appendChild(qtyCell);
        var remarkCell = el('td');
        var remark = document.createElement('input');
        remark.type = 'text';
        remark.maxLength = 200;
        remark.className = 'p-in p-in--text';
        remark.value = line.remark || '';
        remark.addEventListener('input', function () { labLines[idx].remark = remark.value; });
        remarkCell.appendChild(remark);
        tr.appendChild(remarkCell);
        var act = el('td');
        act.appendChild(button('빼기', 'btn btn--quiet btn--small', function () {
          labLines.splice(idx, 1);
          drawLab();
        }));
        tr.appendChild(act);
        body.appendChild(tr);
      });
      $('lb-empty').hidden = labLines.length > 0;
    }

    $('lb-search').addEventListener('click', function () {
      var q = $('lb-q').value;
      var vendor = $('lb-vendor').value;
      if (q === '') { say('찾을 품목 이름이나 코드를 적으세요.'); return; }
      // `stock_managed=0` — 기공 명세서에 담을 수 있는 것만 찾는다. 그래도 **막는 것은 서버**다
      // (재고관리 품목이 섞이면 400 `stock_managed_item`).
      var path = '/api/purchase/items?stock_managed=0&q=' + encodeURIComponent(q)
               + (vendor ? '&vendor=' + encodeURIComponent(vendor) : '');
      request('GET', path).then(function (res) {
        if (!res) { return; }
        if (!res.ok) { say(errorMessage(res.data)); return; }
        var items = res.data.items || [];
        if (items.length === 0) { say('찾은 품목이 없습니다.'); return; }
        items.slice(0, 20).forEach(function (item) {
          var already = labLines.some(function (l) { return l.item_id === item.id; });
          if (already) { return; }
          labLines.push({
            item_id: item.id,
            code: item.code,
            name: item.name,
            stock_managed: item.stock_managed !== false,
            qty: '1',
            remark: ''
          });
        });
        drawLab();
        say('담았습니다 — 재고관리를 하는 품목은 서버가 거부합니다(400).');
      }).catch(function () { say(UI.GENERIC_ERROR); });
    });

    $('lb-save').addEventListener('click', function () {
      if (labLines.length === 0) { say('담은 품목이 없습니다.'); return; }
      if (!$('lb-vendor').value) { say('거래처를 고르세요.'); return; }
      if (!$('lb-location').value) { say('장소를 고르세요.'); return; }
      var payload = {
        vendor_id: Number($('lb-vendor').value),
        to_location_id: Number($('lb-location').value),
        doc_date: $('lb-date').value || today(),
        lines: labLines.map(function (l) {
          var out = { item_id: l.item_id, qty: String(l.qty) };
          if (l.remark) { out.remark = l.remark; }
          return out;
        })
      };
      // 대사 탭에서 **확정된** 명세서를 열어 두었다면 그 표에 연결한다 (지시서 보충 4).
      // 확정 전 명세서에는 아직 줄이 없어 연결할 곳이 없다.
      if (current && current.statement_id && current.committed === true) {
        payload.statement_id = current.statement_id;
      }
      say('만드는 중…');
      request('POST', '/api/purchase/lab', payload).then(function (res) {
        if (!res) { return; }
        if (!res.ok) { say(errorMessage(res.data)); return; }
        say('입고 전표 ' + String(res.data.doc_no) + ' 을(를) 만들었습니다 (원장 ' + String(res.data.moves) + '행).');
        labLines = [];
        drawLab();
      }).catch(function () { say(UI.GENERIC_ERROR); });
    });

    /* ── 기초 목록 ── */
    request('GET', '/api/purchase/vendors?per_page=200').then(function (res) {
      if (!res || !res.ok) { return; }
      var rows = res.data.items || res.data.rows || [];
      fillSelect($('rc-vendor'), rows, 'id', 'name', '거래처를 고르세요');
      fillSelect($('lb-vendor'), rows, 'id', 'name', '거래처를 고르세요');
    }).catch(function () { /* 목록이 없으면 셀렉트가 비어 있을 뿐이다 */ });

    request('GET', '/api/purchase/branches?per_page=200').then(function (res) {
      if (!res || !res.ok) { return; }
      var rows = res.data.items || res.data.rows || [];
      fillSelect($('cl-branch'), rows, 'id', 'name', '전사 (모든 지점)');
    }).catch(function () { /* 같은 이유 */ });

    request('GET', '/api/purchase/locations?per_page=200').then(function (res) {
      if (!res || !res.ok) { return; }
      var rows = res.data.items || res.data.rows || [];
      fillSelect($('lb-location'), rows, 'id', 'name', '장소를 고르세요');
    }).catch(function () { /* 같은 이유 */ });

    $('rc-month').value = thisMonth();
    $('cl-month').value = thisMonth();
    $('lb-date').value = today();
    loadList();
    loadCloses();
  }

  /* ══════════════════════════════════════════════════════
   * 시작 — 어느 화면인지는 DOM 이 말해 준다
   * ══════════════════════════════════════════════════════ */

  function start() {
    fetch('/api/me', { credentials: 'same-origin', cache: 'no-store' })
      .then(function (response) { return UI.readJson(response); })
      .then(function (me) {
        apiClient.setCsrf(me.csrf_token);
        if ($('weekly-panel')) { initWeekly(); }
        if ($('recon-tabs')) { initRecon(); }
      })
      .catch(function () { say(UI.GENERIC_ERROR); });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
