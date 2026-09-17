/* public/assets/purchase_common.js — 구매·재고 화면 공통 도우미 한 벌 (25단계, PRD T-50)
 *
 * 왜 이 파일이 생겼나
 *   25단계 전에는 `el`·`setText`·`dash`·`errorMessage`·`request`·`cell`·`badge`·`qty`·
 *   `money`·`kpi`·`initTabs`·`say` 가 **다섯 벌**(purchase.js·purchase_docs.js·
 *   purchase_stock.js·purchase_recon.js·purchase_structure.js)에 각각 있었다.
 *   그래서 한쪽을 고쳐도 다른 화면은 그대로였고, 같은 배지가 화면마다 다른 색이 되었다.
 *   이제 **한 벌**뿐이고, 다섯 번들은 이 파일을 읽어 같은 것을 쓴다.
 *
 * 지키는 것 (PRD S-01 · S-03, ARCH 13장)
 *   · 문서에 글자를 넣는 길은 `textContent` 하나뿐이다 — inner/outer HTML·insertAdjacentHTML·
 *     document.write 를 한 글자도 쓰지 않는다. 품목명·비고·거래처 이름은 사람이 적은
 *     문자열이고, 그중 하나라도 태그로 해석되면 그것이 XSS 다.
 *   · **권한을 브라우저에서 판단하지 않는다.** 이 파일에는 역할 비교가 없다.
 *   · 상태 변경 요청에는 `X-CSRF-Token` 을 싣는다. 토큰은 `/api/me` 가 준다.
 *   · 재인증 이동 주소는 **코드에 적힌 고정 키**에서만 나온다 — 응답 값이 주소가 되지
 *     않는다 (PRD F-23 오픈 리다이렉트 방지).
 *   · 인라인 style 을 만들지 않는다 (CSP `style-src 'self'`) — 보임/숨김은 `hidden` 과
 *     클래스로만 바꾼다.
 *
 * 전역은 `window.PurchaseUI` **하나**뿐이다.
 */
(function () {
  'use strict';

  var GENERIC_ERROR = '처리하지 못했습니다. 잠시 후 다시 시도해 주세요.';

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

  /** 화면 위쪽 알림 줄 한 곳(`#msg`)에만 적는다 — 화면마다 다른 자리에 띄우지 않는다 */
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

  /** 금액 보기 좋게. 저장되는 금액은 서버가 다시 계산한다 (SECURITY R-66). */
  function money(value) {
    if (value === null || value === undefined || value === '') { return '—'; }
    var n = Number(value);
    if (isNaN(n)) { return String(value); }
    return n.toLocaleString('ko-KR');
  }

  /** 판정에만 쓰는 숫자 (값이 없으면 0) */
  function toNumber(value) {
    if (value === null || value === undefined || value === '') { return 0; }
    var n = Number(value);
    return isNaN(n) ? 0 : n;
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

  /* ── 표·배지·KPI ──────────────────────────────────────── */

  function cell(row, text, className) {
    var td = el('td', className || null, text);
    row.appendChild(td);
    return td;
  }

  /**
   * 배지 한 개. 색이 곧 뜻이다 (시안 docs/purchase/design/README.md)
   *   badge--ok 완료·일치 / badge--warn 진행중·부분·주의 / badge--danger 부족·지남·오류
   *   badge--accent 일부 발주·발주서·정보 / badge--team 이동·중앙장 / badge--off 취소·중단
   */
  function badge(text, kind) {
    return el('span', kind ? 'badge ' + kind : 'badge', text);
  }

  function button(label, className, onClick) {
    var b = el('button', className || 'btn btn--quiet btn--small', label);
    b.type = 'button';
    if (typeof onClick === 'function') { b.addEventListener('click', onClick); }
    return b;
  }

  /** KPI 카드 한 장. `danger` 가 참이면 빨간 카드(값 > 0 인 「미달·지남·차이」). */
  function kpi(container, label, value, note, danger) {
    if (!container) { return null; }
    var card = el('div', danger ? 'kpi kpi--danger' : 'kpi');
    card.appendChild(el('div', 'kpi__label', label));
    card.appendChild(el('div', 'kpi__value', value));
    if (note !== undefined && note !== null && note !== '') {
      card.appendChild(el('div', 'kpi__note', note));
    }
    container.appendChild(card);
    return card;
  }

  /* ── 미입고 현황 — **한 벌** (F-45) ─────────────────────
   *
   * 왜 여기 있나
   *   같은 표를 두 화면이 그린다: 재고 화면의 「미입고」 탭과 입고 화면의 「현황」 탭.
   *   21단계에는 재고 화면 것 하나뿐이었고, F-45 에서 입고 화면에 같은 표가 필요해졌다.
   *   복사해 두면 한쪽만 고쳐지는 날이 온다(25단계가 다섯 벌의 도우미를 한 벌로 모은 이유와
   *   같다) — 그래서 **함수는 하나**이고, 두 화면은 자기 칸들을 넘겨 줄 뿐이다.
   *
   * 화면마다 다른 것은 **DOM 요소와 request 함수**뿐이라 그것만 인자로 받는다. 경로·
   * 파라미터·응답 처리는 두 화면이 한 글자도 같다 — 그래야 「같은 것을 보고 있다」 가 참이 된다.
   *
   *   host  그룹(거래처별 접이식 표)이 들어갈 요소
   *   opts  { request, kpis, empty, vendor, overdue, q } — request 만 필수이고 나머지는
   *         없으면 그 칸이 없는 화면이다(예: 입고 화면 현황 탭에는 검색 칸이 없다).
   *
   * 글자는 전부 `textContent` 로만 들어간다 (PRD S-03). 판정에 쓰는 값은 서버가 준 것뿐이다.
   */
  function renderBackorder(host, opts) {
    var o = opts || {};
    if (!host || typeof o.request !== 'function') { return; }

    var params = '';
    var joiner = '?';
    if (o.vendor && o.vendor.value !== '') {
      params += joiner + 'vendor=' + encodeURIComponent(o.vendor.value);
      joiner = '&';
    }
    if (o.overdue && o.overdue.checked) { params += joiner + 'overdue=1'; joiner = '&'; }
    if (o.q && o.q.value.trim() !== '') {
      params += joiner + 'q=' + encodeURIComponent(o.q.value.trim());
    }

    o.request('GET', '/api/purchase/backorder' + params).then(function (res) {
      if (!res) { return; }
      if (!res.ok) { say(errorMessage(res.data)); return; }
      clear(host);
      var groups = Array.isArray(res.data.groups) ? res.data.groups : [];

      if (o.kpis) {
        clear(o.kpis);
        kpi(o.kpis, '미입고 발주서', String(res.data.doc_count) + ' 건',
          '거래처 ' + groups.length + '곳');
        kpi(o.kpis, '납기 지남', String(res.data.overdue) + ' 줄', '보낸 날 + 조달기간 기준',
          toNumber(res.data.overdue) > 0);
        kpi(o.kpis, '미입고 금액', money(res.data.amount), '공급가액 기준');
        kpi(o.kpis, '미입고 줄', String(res.data.line_count) + ' 줄',
          res.data.truncated ? '상한에서 끊김 — 좁혀 보세요' : '');
      }

      groups.forEach(function (group) {
        var box = el('div', 'p-group');
        var head = el('button', 'p-group__head');
        head.type = 'button';
        head.setAttribute('aria-expanded', 'true');
        var caret = el('span', 'p-caret', '▾');
        head.appendChild(caret);
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
            var th = el('th', index >= 4 && index <= 6 ? 'num' : null, label);
            th.setAttribute('scope', 'col');
            hrow.appendChild(th);
          });
        thead.appendChild(hrow);
        table.appendChild(thead);

        var tbody = el('tbody');
        group.lines.forEach(function (line) {
          var tr = el('tr');
          if (line.is_overdue) { tr.className = 'p-row-late'; }
          cell(tr, line.po_doc_no, 'mono');
          cell(tr, dash(line.sent_at ? line.sent_at.slice(0, 10) : null)
            + ' · ' + dash(line.due_date));
          cell(tr, dash(line.branch_name) + ' · ' + dash(line.location_name));
          var itemTd = cell(tr, line.name, 'p-name');
          itemTd.appendChild(el('span', 'p-sub mono',
            line.code + (line.partial ? ' · 부분 입고' : '')));
          cell(tr, qty(line.ordered_qty), 'num mono');
          cell(tr, qty(line.received_qty), 'num mono');
          var remTd = el('td', 'num mono');
          remTd.appendChild(badge(qty(line.remaining), 'badge--danger'));
          tr.appendChild(remTd);
          var days = cell(tr, line.days_elapsed + '일');
          if (line.is_overdue) { days.className = 'p-short'; }
          cell(tr, dash(line.manager_name));
          tbody.appendChild(tr);
        });
        table.appendChild(tbody);
        wrap.appendChild(table);
        wrap.className = 'table-wrap p-group__body';
        box.appendChild(wrap);
        head.addEventListener('click', function () {
          wrap.hidden = !wrap.hidden;
          head.setAttribute('aria-expanded', wrap.hidden ? 'false' : 'true');
          setText(caret, wrap.hidden ? '▸' : '▾');
        });
        host.appendChild(box);
      });

      if (o.empty) { o.empty.hidden = groups.length > 0; }
    });
  }

  /* ── 품목 찾기 (ItemPicker) — **한 벌** (F-61) ───────────
   *
   * 왜 여기 있나
   *   같은 창을 두 화면이 연다: 발주요청 화면(시안 ① ItemPicker)과 발주서 화면(F-61 ③
   *   「직접 입력」). 20단계에는 발주요청 것 하나뿐이었고, F-61 에서 발주서에도 같은 창이
   *   필요해졌다. 복사해 두면 한쪽만 고쳐지는 날이 온다(25단계가 다섯 벌의 도우미를 한 벌로
   *   모은 이유, F-45 가 미입고 표를 한 벌로 만든 이유와 같다) — 그래서 **함수는 하나**다.
   *
   *   opts.request   API 호출 함수 (필수)
   *   opts.ids       칸 id 를 바꿀 때만. 기본은 `pick-*`(두 화면이 같은 이름을 쓴다)
   *   opts.guard     담기 전에 부른다. 문자열을 돌려주면 그 말을 알림 줄에 적고 멈춘다
   *                  (발주서에서 「입고 장소를 골라 주세요」 에 쓰인다)
   *   opts.onAdd     담기 — [{row, qty}] 를 받는다. row 는 서버가 준 값만 옮긴 것이다
   *
   * 단가는 **보여 주기만** 한다 — 저장 본문에 단가가 실리는 길은 어느 화면에도 없다 (R-66).
   * 글자는 전부 `textContent` 로만 들어간다 (PRD S-03).
   */
  function itemPicker(opts) {
    var o = opts || {};
    var ids = o.ids || {};
    var page = 1;
    var picked = {};

    function key(name, fallback) { return ids[name] || fallback; }
    function node(name, fallback) { return $(key(name, fallback)); }

    function syncInfo(total) {
      setText(node('info', 'pick-info'), '전체 ' + total + '건 · ' + page + '쪽 · 고른 품목 '
        + Object.keys(picked).length + '건');
    }

    function run() {
      var params = '?page=' + page;
      var qBox = node('q', 'pick-q');
      if (qBox && qBox.value.trim() !== '') {
        params += '&q=' + encodeURIComponent(qBox.value.trim());
      }
      var vendorBox = node('vendor', 'pick-vendor');
      if (vendorBox && vendorBox.value !== '') {
        params += '&vendor=' + encodeURIComponent(vendorBox.value);
      }

      o.request('GET', '/api/purchase/items' + params).then(function (res) {
        if (!res) { return; }
        if (!res.ok) { say(errorMessage(res.data)); return; }
        var body = node('body', 'pick-body');
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
                  unit: row.unit, vendor_id: row.vendor_id, vendor_name: row.vendor_name,
                  price_in: row.price_in
                },
                qty: qtyInput.value
              };
            } else {
              delete picked[String(row.id)];
            }
            syncInfo(res.data.total);
          }
          check.addEventListener('change', sync);
          qtyInput.addEventListener('input', function () { if (check.checked) { sync(); } });

          body.appendChild(tr);
        });
        syncInfo(res.data.total);
      });
    }

    function on(name, fallback, handler) {
      var b = node(name, fallback);
      if (b) { b.addEventListener('click', handler); }
    }

    on('search', 'pick-search', function () { page = 1; run(); });
    on('prev', 'pick-prev', function () { if (page > 1) { page -= 1; run(); } });
    on('next', 'pick-next', function () { page += 1; run(); });
    on('add', 'pick-add', function () {
      if (typeof o.guard === 'function') {
        var problem = o.guard();
        if (typeof problem === 'string' && problem !== '') { say(problem); return; }
      }
      var out = [];
      Object.keys(picked).forEach(function (k) { out.push(picked[k]); });
      picked = {};
      if (typeof o.onAdd === 'function') { o.onAdd(out); }
      closeModal(key('modal', 'pick-modal'));
    });

    return {
      open: function () {
        page = 1;
        picked = {};
        openModal(key('modal', 'pick-modal'));
        run();
      },
      run: run
    };
  }

  /** 셀렉트를 목록으로 채운다. 고르고 있던 값은 목록에 남아 있으면 지킨다. */
  function fillSelect(select, rows, valueKey, labelKey, emptyLabel) {
    if (!select) { return; }
    var keep = select.value;
    clear(select);
    if (emptyLabel !== null && emptyLabel !== undefined) {
      var blank = el('option', null, emptyLabel);
      blank.value = '';
      select.appendChild(blank);
    }
    (rows || []).forEach(function (row) {
      var opt = el('option', null, String(row[labelKey]));
      opt.value = String(row[valueKey]);
      select.appendChild(opt);
    });
    if (keep !== '') { select.value = keep; }
  }

  /* ── 탭 (공통 클래스 .tabs / .tab / .is-on) ──────────── */

  function initTabs(navId, map) {
    var nav = $(navId);
    if (!nav) { return; }
    var buttons = nav.querySelectorAll('.tab');
    Array.prototype.forEach.call(buttons, function (b) {
      b.addEventListener('click', function () {
        show(b.getAttribute('data-tab'));
      });
    });
    function show(wanted) {
      Array.prototype.forEach.call(buttons, function (x) {
        var on = x.getAttribute('data-tab') === wanted;
        x.classList.toggle('is-on', on);
        x.setAttribute('aria-selected', on ? 'true' : 'false');
      });
      Object.keys(map).forEach(function (key) {
        var panel = $(map[key].panel);
        if (panel) { panel.hidden = key !== wanted; }
      });
      if (map[wanted] && typeof map[wanted].onShow === 'function') { map[wanted].onShow(); }
    }
    nav.tboxShowTab = show;
  }

  /** 탭을 코드에서 바꾼다 (예: 재고 표의 숫자를 눌러 수불부 탭으로) */
  function showTab(navId, wanted) {
    var nav = $(navId);
    if (nav && typeof nav.tboxShowTab === 'function') { nav.tboxShowTab(wanted); }
  }

  /* ── 모달 (<dialog> 표준: .modal__head / __body / __foot) ── */

  function openModal(id) {
    var dialog = $(id);
    if (dialog && typeof dialog.showModal === 'function' && !dialog.open) { dialog.showModal(); }
  }

  function closeModal(id) {
    var dialog = $(id);
    if (dialog && dialog.open) { dialog.close(); }
  }

  /**
   * `data-modal-close` 가 붙은 단추가 자기를 감싼 <dialog> 를 닫는다.
   * 인라인 on* 속성을 쓰지 않으려고 여기서 한 번만 걸어 둔다 (CSP).
   */
  function wireModalClose(root) {
    var scope = root || document;
    var buttons = scope.querySelectorAll('[data-modal-close]');
    Array.prototype.forEach.call(buttons, function (b) {
      b.addEventListener('click', function () {
        var dialog = b.closest('dialog');
        if (dialog && dialog.open) { dialog.close(); }
      });
    });
  }

  /* ── 파일 고르기 라벨 — 고른 파일 이름을 옆에 적는다 ── */
  function wireFileLabels(root) {
    var scope = root || document;
    var labels = scope.querySelectorAll('.filelabel');
    Array.prototype.forEach.call(labels, function (label) {
      var input = label.querySelector('input[type="file"]');
      var name = label.querySelector('.filelabel__name');
      if (!input || !name) { return; }
      input.addEventListener('change', function () {
        setText(name, input.files && input.files.length > 0 ? input.files[0].name : '고른 파일 없음');
      });
    });
  }

  /* ── API 호출 한 벌 ──────────────────────────────────── */

  /**
   * `request`·`upload` 를 만든다. 화면마다 다른 것은 **재인증 꼬리표 하나**뿐이라
   * 그것만 인자로 받는다 (문자열 또는 문자열을 돌려주는 함수).
   *
   * 호출 경로·본문·응답 처리는 25단계 전과 **한 글자도 같다** — 이 단계는 보이는 것만 고친다.
   */
  function api(reauthKey) {
    var csrfToken = '';

    function keyOf() {
      var k = typeof reauthKey === 'function' ? reauthKey() : reauthKey;
      return typeof k === 'string' ? k : '';
    }

    /** 401 → 로그인, 403 + next → 강제 절차·재인증 화면. 처리했으면 true */
    function handleGate(response, data) {
      if (response.status === 401) {
        window.location.href = '/login';
        return true;
      }
      if (response.status === 403 && data && data.next === 'reauth') {
        var key = keyOf();
        window.location.href = key === '' ? '/reauth' : '/reauth?to=' + key;
        return true;
      }
      if (response.status === 403 && data
          && (data.next === 'password_change' || data.next === 'mfa_setup')) {
        window.location.href = '/';
        return true;
      }
      return false;
    }

    function finish(response) {
      return readJson(response).then(function (data) {
        if (handleGate(response, data)) { return null; }
        return { ok: response.ok, status: response.status, data: data };
      });
    }

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
      return fetch(path, init).then(finish);
    }

    /** 파일 올리기 — multipart 는 Content-Type 을 브라우저가 정하게 둔다(경계 문자열) */
    function upload(path, formData) {
      return fetch(path, {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { 'X-CSRF-Token': csrfToken },
        body: formData
      }).then(finish);
    }

    function setCsrf(token) { csrfToken = typeof token === 'string' ? token : ''; }

    return { request: request, upload: upload, setCsrf: setCsrf };
  }

  window.PurchaseUI = {
    GENERIC_ERROR: GENERIC_ERROR,
    $: $,
    el: el,
    setText: setText,
    clear: clear,
    say: say,
    dash: dash,
    qty: qty,
    money: money,
    toNumber: toNumber,
    today: today,
    errorMessage: errorMessage,
    readJson: readJson,
    cell: cell,
    badge: badge,
    button: button,
    kpi: kpi,
    renderBackorder: renderBackorder,
    itemPicker: itemPicker,
    fillSelect: fillSelect,
    initTabs: initTabs,
    showTab: showTab,
    openModal: openModal,
    closeModal: closeModal,
    wireModalClose: wireModalClose,
    wireFileLabels: wireFileLabels,
    api: api
  };

  document.addEventListener('DOMContentLoaded', function () {
    wireModalClose(document);
    wireFileLabels(document);
  });
})();
