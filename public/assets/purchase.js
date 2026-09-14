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

  /* ── 공통 도우미 한 벌 (25단계) — purchase_common.js 하나에만 있다 ──
   * 다섯 번들이 각자 들고 있던 사본(el·setText·dash·errorMessage·request·say …)을 지웠다.
   * 호출 경로·본문·응답 처리는 그대로다.
   */
  var UI = window.PurchaseUI;
  var $ = UI.$;
  var el = UI.el;
  var setText = UI.setText;
  var clear = UI.clear;
  var say = UI.say;
  var dash = UI.dash;
  var errorMessage = UI.errorMessage;
  var badge = UI.badge;

  /**
   * 재인증을 마치고 **보던 화면으로** 돌아오게 하는 꼬리표.
   * 지금 어느 화면인지는 **그 화면에만 있는 요소**로 가른다(app.js 가 `/reauth` 화면을
   * 폼으로 가르는 것과 같은 방식). 값은 코드에 적힌 고정 문자열 둘뿐이다.
   */
  function reauthKey() {
    if ($('p-import-form')) { return 'purchaseimport'; }
    if ($('p-tabs')) { return 'purchasemasters'; }
    return '';   // 품목 상세 등 — 재인증 대상이 아니라 여기 올 일이 없다
  }

  var apiClient = UI.api(reauthKey);
  var request = apiClient.request;

  /** 종류별 표 머리와 각 행에서 꺼낼 칸. 라벨은 시안 ⑭ 의 것을 따른다. */
  var KIND_COLUMNS = {
    'brands':     [['name', '브랜드'], ['ceo_name', '대표'], ['biz_no', '사업자번호'], ['tel', '전화']],
    'branches':   [['name', '지점'], ['brand_name', '브랜드']],
    'locations':  [['code', '코드'], ['name', '장소'], ['kind', '용도'], ['branch_name', '지점'], ['legacy_name', '이전 이름']],
    'vendors':    [['code', '코드'], ['name', '거래처'], ['biz_no', '사업자번호']],
    'categories': [['name', '분류'], ['level', '분류 층'], ['parent_name', '상위']],
    'par-levels': [['item_code', '품목코드'], ['item_name', '품목'], ['location_name', '장소'], ['qty', '인가량'], ['effective_from', '적용 시작']]
  };

  /** 분류 층의 한국어 이름 — 표·셀렉트·모달이 같은 말을 쓴다 */
  var CATEGORY_LEVELS = { '1': '대분류', '2': '중분류', '3': '소분류' };

  /** 가져오기 행 판정의 배지 색 = 뜻 (시안 ⑯) */
  var VERDICT_CLASS = {
    insert: 'badge--accent', update: 'badge--ok',
    review: 'badge--warn', error: 'badge--danger'
  };

  var KIND_TITLE = {
    'brands': '브랜드', 'branches': '지점', 'locations': '장소',
    'vendors': '거래처', 'categories': '분류', 'par-levels': '인가량'
  };

  /** 만들기 폼의 칸 정의 — [키, 라벨, 형식, 필수] */
  /**
   * 종류별 등록 폼의 칸 (3차 보정 2026-09-13 — 「id 를 숫자로 적기」 를 없앴다).
   *
   * 전에는 `brand_id` 같은 참조를 **숫자 입력칸**으로 두었다. 사람이 브랜드 번호를 외우고
   * 있을 리 없으니 화면을 오가며 번호를 찾아 적어야 했다. 이제 참조 칸은 `ref` 이고,
   * 화면이 그 종류의 목록을 서버에서 받아 셀렉트로 만들고 **옆에 「+ 새로 등록」** 을 세운다
   * (사용자 결정 2026-09-13 「접근한 페이지에서 필요한 정보에 대한 팝업창을 띄워 등록」).
   *
   * 칸 하나: { key, label, type, required }
   *   type `text` · `number` · `date`
   *        `choice`  — options: [[값, 보이는 말], …]
   *        `ref`     — ref: 참조할 종류. 셀렉트 + 「+ 새로 등록」
   *        `item`    — 품목 하나 고르기. 품목은 1.35만 건이라 셀렉트가 아니라 **검색**이다
   *                    (발주요청의 「품목 찾기」 와 **같은 API** `/api/purchase/items`).
   */
  var KIND_FIELDS = {
    'brands': [
      { key: 'name', label: '브랜드 이름', type: 'text', required: true },
      { key: 'ceo_name', label: '대표자', type: 'text' },
      { key: 'biz_no', label: '사업자번호', type: 'text' },
      { key: 'tel', label: '전화', type: 'text' },
      { key: 'address', label: '주소', type: 'text' }
    ],
    'branches': [
      { key: 'brand_id', label: '브랜드', type: 'ref', ref: 'brands', required: true },
      { key: 'name', label: '지점 이름', type: 'text', required: true }
    ],
    'locations': [
      { key: 'branch_id', label: '지점', type: 'ref', ref: 'branches', required: true },
      { key: 'kind', label: '용도', type: 'choice', required: true, options: [
        ['warehouse', '창고'], ['central', '중앙장'], ['or', '수술방'], ['other', '기타']
      ] },
      { key: 'name', label: '장소 이름', type: 'text', required: true },
      { key: 'code', label: '코드', type: 'text' },
      { key: 'legacy_name', label: '이전 이름', type: 'text' }
    ],
    'vendors': [
      { key: 'name', label: '거래처 이름', type: 'text', required: true },
      { key: 'code', label: '거래처 코드', type: 'text' },
      { key: 'biz_no', label: '사업자번호', type: 'text' },
      { key: 'ceo_name', label: '대표자명', type: 'text' },
      { key: 'tel', label: '전화', type: 'text' }
    ],
    'categories': [
      { key: 'name', label: '분류 이름', type: 'text', required: true },
      { key: 'level', label: '분류 층', type: 'choice', required: true, options: [
        ['1', '대분류'], ['2', '중분류'], ['3', '소분류']
      ] },
      { key: 'parent_id', label: '상위 분류 (대분류는 비움)', type: 'ref', ref: 'categories' }
    ],
    'par-levels': [
      { key: 'location_id', label: '장소', type: 'ref', ref: 'locations', required: true },
      { key: 'item_id', label: '품목', type: 'item', required: true },
      { key: 'qty', label: '인가량', type: 'number', required: true },
      { key: 'effective_from', label: '적용 시작 (YYYY-MM-DD)', type: 'date' }
    ]
    // 품목 등록 폼은 25단계에서 지웠다 — 어느 화면에서도 열 수 없는 **도달 불가 코드**였다.
    // 품목 등록 화면(시안 ⑮ 편집형)은 26단계 후보다 (지시서 4장).
  };

  /** 종류 → 등록 API 경로. 품목만 `/items` 이고 나머지는 기초정보 6종 경로다. */
  function postFor(kind) {
    return '/api/purchase/' + kind;
  }

  /** 종류 → 모달 제목에 쓸 이름 */
  var KIND_NOUN = {
    brands: '브랜드', branches: '지점', locations: '장소(창고)',
    vendors: '거래처', categories: '분류', 'par-levels': '인가량', items: '품목'
  };

  /* ══════════════════════════════════════════════════════
   * 「+ 새로 등록」 팝업 — 세 화면이 함께 쓴다 (3차 보정 2026-09-13)
   *
   * 사용자 결정: "각각 등록이 필요한 건들이 있는데, **접근한 페이지에서** 필요한 정보에 대한
   * 팝업창을 띄워 등록할 수 있도록 개선한다."
   *
   * 지키는 것
   *   · **새 창구를 만들지 않는다.** 기존 `POST /api/purchase/{종류}` 를 그대로 부른다 —
   *     같은 권한(`purchase.master.write`)·같은 재인증 관문·같은 감사 기록을 지난다.
   *     팝업은 **화면 편의**일 뿐 권한 경로가 아니다 (PRD S-01).
   *   · 파일 올리기의 새 폴더 대화상자와 **같은 `<dialog class="modal">`**. ESC·바깥 누르기·
   *     닫기 단추로 닫히고, 열려 있는 동안 뒤 화면은 조작되지 않는다(브라우저가 막는다).
   *   · 문서에 글자를 넣는 길은 `textContent` 하나뿐이다.
   *   · 모달 안에서 또 참조가 필요하면(품목 모달 안의 거래처) **한 겹 더** 열 수 있다.
   *     겹은 2까지다 — 그보다 깊어지면 사람이 자기가 무엇을 만들던 중인지 잃는다.
   * ══════════════════════════════════════════════════════ */

  /** 지금 열려 있는 모달의 겹 수 (0 = 없음). 2 를 넘기지 않는다. */
  var modalDepth = 0;
  var MODAL_MAX_DEPTH = 2;

  /** 참조 셀렉트를 채울 목록을 받아 둔다 — 같은 종류를 여러 번 그릴 때 다시 부르지 않는다 */
  var refCache = {};

  /** 참조 목록을 비운다 (등록이 성공해 목록이 바뀌었을 때) */
  function clearRefCache(kind) {
    if (kind) { delete refCache[kind]; } else { refCache = {}; }
  }

  /**
   * 참조 목록을 받는다 (`/api/purchase/{종류}`). 실패해도 화면은 살아 있어야 하므로
   * 빈 목록으로 이어 간다 — 사람이 「+ 새로 등록」 으로 만들면 된다.
   */
  function loadRef(kind) {
    if (refCache[kind]) { return Promise.resolve(refCache[kind]); }
    return request('GET', '/api/purchase/' + kind + '?page=1').then(function (result) {
      var items = (result && result.ok && Array.isArray(result.data.items)) ? result.data.items : [];
      refCache[kind] = items;
      return items;
    }).catch(function () { return []; });
  }

  /** 참조 항목 하나를 사람이 읽을 한 줄로 (분류는 단계가, 장소는 지점이 함께 보여야 고를 수 있다) */
  function refLabel(kind, row) {
    if (kind === 'categories') {
      return (CATEGORY_LEVELS[String(row.level)] || String(row.level)) + ' · ' + row.name
        + (row.parent_name ? ' (상위: ' + row.parent_name + ')' : '');
    }
    if (kind === 'locations') {
      return row.name + (row.branch_name ? ' (' + row.branch_name + ')' : '');
    }
    if (kind === 'branches') {
      return row.name + (row.brand_name ? ' (' + row.brand_name + ')' : '');
    }
    if (kind === 'vendors') {
      return row.name + (row.code ? ' [' + row.code + ']' : '');
    }
    return row.name;
  }

  /** 셀렉트를 목록으로 채운다. `selected` 가 있으면 그것을 고른다. */
  function fillRefSelect(select, kind, items, selected) {
    clear(select);
    var blank = document.createElement('option');
    blank.value = '';
    setText(blank, '— 고르세요 —');
    select.appendChild(blank);
    items.forEach(function (row) {
      var option = document.createElement('option');
      option.value = String(row.id);
      setText(option, refLabel(kind, row));
      if (selected !== undefined && selected !== null && String(row.id) === String(selected)) {
        option.selected = true;
      }
      select.appendChild(option);
    });
  }

  /**
   * 칸 하나를 그린다. 참조 칸이면 셀렉트 + 「+ 새로 등록」 단추까지.
   *
   * @param spec   위 KIND_FIELDS 의 한 칸
   * @param value  미리 채울 값 (가져오기 오류 행에서 넘어온 이름 등)
   * @returns {label, read} — read() 가 지금 값을 돌려준다
   */
  function buildField(spec, value) {
    /* 참조·품목 칸은 안에 컨트롤이 둘·셋이라 한 칸에 넣으면 줄이 접히고, 그러면
       그 칸만 키가 커져 라벨 줄이 들쭉날쭉해진다 — 그래서 그리드 두 칸을 준다 (25단계). */
    var wide = spec.type === 'ref' || spec.type === 'item';
    var label = el('label', wide ? 'field field--span2' : 'field');
    label.appendChild(el('span', 'field__label', spec.label + (spec.required ? ' *' : '')));

    if (spec.type === 'ref') {
      var row = el('div', 'field__row');
      var select = document.createElement('select');
      select.setAttribute('data-key', spec.key);
      row.appendChild(select);

      loadRef(spec.ref).then(function (items) { fillRefSelect(select, spec.ref, items, value); });

      var add = el('button', 'btn btn--quiet btn--small', '+ 새로 등록');
      add.type = 'button';
      add.addEventListener('click', function () {
        openRegisterModal(spec.ref, {}, function (created) {
          // 등록에 성공하면 목록을 다시 받아 **방금 만든 것이 골라진 채로** 돌아온다.
          clearRefCache(spec.ref);
          loadRef(spec.ref).then(function (items) {
            fillRefSelect(select, spec.ref, items, created && created.id);
          });
        });
      });
      row.appendChild(add);
      label.appendChild(row);
      return { label: label, read: function () { return select.value; } };
    }

    if (spec.type === 'item') {
      // 품목은 1.35만 건이라 셀렉트에 담지 않는다 — 발주요청의 「품목 찾기」 와 같은 API 로 찾는다.
      var box = el('div', 'field__row');
      var search = document.createElement('input');
      search.type = 'search';
      search.placeholder = '품목코드·품목명으로 찾기';
      box.appendChild(search);
      var picked = document.createElement('select');
      picked.setAttribute('data-key', spec.key);
      box.appendChild(picked);
      var find = el('button', 'btn btn--quiet btn--small', '찾기');
      find.type = 'button';
      function runSearch() {
        request('GET', '/api/purchase/items?page=1&q=' + encodeURIComponent(search.value))
          .then(function (result) {
            if (!result || !result.ok) { return; }
            var items = Array.isArray(result.data.items) ? result.data.items : [];
            clear(picked);
            var blank = document.createElement('option');
            blank.value = '';
            setText(blank, items.length === 0 ? '— 찾은 품목 없음 —' : '— 고르세요 (' + String(result.data.total) + '건 중 20건) —');
            picked.appendChild(blank);
            items.forEach(function (it) {
              var option = document.createElement('option');
              option.value = String(it.id);
              setText(option, it.code + ' · ' + it.name);
              picked.appendChild(option);
            });
          }).catch(function () { /* 못 찾아도 화면은 살아 있다 */ });
      }
      find.addEventListener('click', runSearch);
      search.addEventListener('change', runSearch);
      box.appendChild(find);
      label.appendChild(box);
      return { label: label, read: function () { return picked.value; } };
    }

    if (spec.type === 'choice') {
      var sel = document.createElement('select');
      sel.setAttribute('data-key', spec.key);
      (spec.options || []).forEach(function (pair) {
        var option = document.createElement('option');
        option.value = pair[0];
        setText(option, pair[1]);
        if (value !== undefined && String(value) === pair[0]) { option.selected = true; }
        sel.appendChild(option);
      });
      label.appendChild(sel);
      return { label: label, read: function () { return sel.value; } };
    }

    var input = document.createElement('input');
    input.type = spec.type === 'number' ? 'number' : (spec.type === 'date' ? 'date' : 'text');
    input.setAttribute('data-key', spec.key);
    input.maxLength = 200;
    if (value !== undefined && value !== null) { input.value = String(value); }
    if (spec.required) { input.required = true; }
    label.appendChild(input);
    return { label: label, read: function () { return input.value.trim(); } };
  }

  /**
   * 등록 팝업을 연다.
   *
   * @param kind    등록할 종류 (brands·branches·locations·vendors·categories·par-levels·items)
   * @param prefill {키: 값} 미리 채울 값 (가져오기 오류 행의 이름 등)
   * @param onDone  201 뒤에 부를 함수 — 서버가 준 본문을 받는다
   */
  function openRegisterModal(kind, prefill, onDone) {
    if (!KIND_FIELDS[kind]) { return; }
    if (typeof window.HTMLDialogElement !== 'function'
        || typeof document.createElement('dialog').showModal !== 'function') {
      // <dialog> 를 못 쓰는 브라우저 — 기초정보 화면으로 보낸다(같은 서버 창구를 지난다).
      say('이 브라우저는 팝업 등록을 지원하지 않습니다. 기초정보 화면에서 등록해 주세요.');
      return;
    }
    if (modalDepth >= MODAL_MAX_DEPTH) {
      say('먼저 열린 등록 창을 끝낸 뒤에 다시 눌러 주세요.');
      return;
    }
    modalDepth++;

    var dialog = document.createElement('dialog');
    dialog.className = 'modal modal--form';

    var head = el('div', 'modal__head');
    head.appendChild(el('h2', 'modal__title', KIND_NOUN[kind] + ' 새로 등록'));
    var closeX = el('button', 'btn btn--icon', '✕');
    closeX.type = 'button';
    closeX.title = '닫기';
    closeX.setAttribute('aria-label', '닫기');
    head.appendChild(closeX);
    dialog.appendChild(head);

    var body = el('div', 'modal__body');
    var readers = [];
    KIND_FIELDS[kind].forEach(function (spec) {
      var built = buildField(spec, (prefill || {})[spec.key]);
      readers.push({ key: spec.key, read: built.read, required: spec.required === true, spec: spec });
      body.appendChild(built.label);
    });
    dialog.appendChild(body);

    var foot = el('div', 'modal__foot');
    var msg = el('p', 'msg', '');
    msg.setAttribute('role', 'alert');
    foot.appendChild(msg);
    var cancel = el('button', 'btn btn--quiet', '취소');
    cancel.type = 'button';
    foot.appendChild(cancel);
    var submit = el('button', 'btn', '등록');
    submit.type = 'button';
    foot.appendChild(submit);
    dialog.appendChild(foot);

    document.body.appendChild(dialog);

    function done() {
      modalDepth = Math.max(0, modalDepth - 1);
      if (dialog.open) { dialog.close(); }
      if (dialog.parentNode) { dialog.parentNode.removeChild(dialog); }
    }
    closeX.addEventListener('click', done);
    cancel.addEventListener('click', done);
    dialog.addEventListener('cancel', function () { done(); });
    // 배경(backdrop) 누르기 — 새 폴더 모달과 같은 방식이다.
    dialog.addEventListener('click', function (event) {
      if (event.target === dialog) { done(); }
    });

    function send(allowDuplicateName) {
      var payload = {};
      var missing = [];
      readers.forEach(function (r) {
        var v = r.read();
        if (v === '' || v === null || v === undefined) {
          if (r.required) { missing.push(r.spec.label); }
          return;
        }
        payload[r.key] = v;
      });
      if (missing.length > 0) {
        setText(msg, '필수 칸 ' + missing.map(function (m) { return '「' + m + '」'; }).join(' · ') + ' 을(를) 채워 주세요.');
        return;
      }
      if (allowDuplicateName) { payload.allow_duplicate_name = true; }

      submit.disabled = true;
      setText(msg, '등록하는 중…');
      request('POST', postFor(kind), payload)
        .then(function (result) {
          submit.disabled = false;
          if (!result) { done(); return; }   // 401·재인증 403 은 request 가 화면을 옮겼다
          if (result.status === 201) {
            clearRefCache(kind);
            done();
            if (typeof onDone === 'function') { onDone(result.data); }
            return;
          }
          if (result.status === 409 && kind === 'vendors' && !allowDuplicateName) {
            /* 2차 보정에서 거래처 이름의 유일 제약을 없앴다 — 같은 이름은 **되묻고** 통과시킨다.
               (같은 **코드** 는 여전히 DB 가 막으므로 이 단추로도 통과하지 않는다.) */
            setText(msg, '같은 이름의 거래처가 이미 있습니다.');
            var force = el('button', 'btn btn--quiet btn--small', '그래도 등록');
            force.type = 'button';
            force.addEventListener('click', function () {
              if (force.parentNode) { force.parentNode.removeChild(force); }
              send(true);
            });
            foot.insertBefore(force, cancel);
            return;
          }
          setText(msg, errorMessage(result.data));
        })
        .catch(function () {
          submit.disabled = false;
          setText(msg, UI.GENERIC_ERROR);
        });
    }

    submit.addEventListener('click', function () { send(false); });
    dialog.showModal();
  }

  /** 장소 용도의 사람 말 (시안 ⑭) */
  var LOCATION_KIND_LABEL = {
    warehouse: '창고', central: '중앙장', or: '수술방', other: '기타'
  };

  /**
   * 마지막으로 보던 기초정보 탭을 기억한다 (4차 보정).
   *
   * 재인증을 마치고 `/purchase/masters` 로 돌아오면 **보던 탭**이 다시 열려야 한다.
   * 탭 이름을 `?tab=` 으로 주소에 실어 고정 표에 넣으면 표가 조합만큼 늘어나므로,
   * 주소는 건드리지 않고 이 브라우저에만 남긴다.
   *
   * 읽을 때 **허용 목록(KIND_COLUMNS)을 다시 본다** — sessionStorage 는 사용자가 고칠 수
   * 있는 저장소이고, 거기서 온 문자열이 그대로 API 경로가 되면 안 된다.
   * 저장소를 못 쓰는 브라우저(사생활 보호 창)에서도 화면은 그대로 동작해야 하므로
   * 읽기·쓰기를 모두 try 로 감싼다.
   */
  var TAB_MEMORY_KEY = 'tbox.purchase.tab';

  function rememberTab(kind) {
    try { window.sessionStorage.setItem(TAB_MEMORY_KEY, kind); } catch (e) { /* 무시 */ }
  }

  function rememberedTab() {
    var saved = null;
    try { saved = window.sessionStorage.getItem(TAB_MEMORY_KEY); } catch (e) { saved = null; }
    return (typeof saved === 'string' && KIND_COLUMNS[saved]) ? saved : null;
  }

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
        .catch(function () { say(UI.GENERIC_ERROR); });
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
        stateCell.appendChild(UI.badge(item.is_active ? '사용' : '중단',
                                       item.is_active ? 'badge--ok' : 'badge--off'));
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

    /* 만들기 폼 — 3차 보정에서 **모달과 같은 칸 만들기 코드**(buildField)를 쓴다.
       참조 칸은 셀렉트 + 「+ 새로 등록」 이라, 여기서도 브랜드를 만들다 말고 화면을
       떠날 필요가 없다. 폼이 두 벌이면 언젠가 한쪽만 고쳐진다. */
    var createReaders = [];

    function renderCreateForm() {
      var panel = $('p-create-panel');
      var fields = $('p-create-fields');
      if (!panel || !fields) { return; }
      panel.hidden = !state.canEdit;
      setText($('p-create-title'), KIND_TITLE[state.kind] + ' 새로 만들기');
      clear(fields);
      createReaders = [];
      (KIND_FIELDS[state.kind] || []).forEach(function (spec) {
        var built = buildField(spec, undefined);
        createReaders.push({ key: spec.key, read: built.read, required: spec.required === true, spec: spec });
        fields.appendChild(built.label);
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
        .catch(function () { say(UI.GENERIC_ERROR); });
    }

    if (tabs) {
      tabs.addEventListener('click', function (event) {
        var target = event.target;
        if (!target || !target.getAttribute) { return; }
        var kind = target.getAttribute('data-kind');
        if (!kind || !KIND_COLUMNS[kind]) { return; }
        state.kind = kind;
        state.page = 1;
        rememberTab(kind);
        var buttons = tabs.querySelectorAll('.tab');
        for (var i = 0; i < buttons.length; i++) {
          var on = buttons[i] === target;
          buttons[i].className = on ? 'tab is-on' : 'tab';
          buttons[i].setAttribute('aria-selected', on ? 'true' : 'false');
        }
        setText($('p-list-title'), KIND_TITLE[kind]);
        say('');
        load();
      });

      /* 재인증을 마치고 돌아왔을 때 **보던 탭**을 다시 연다 (4차 보정).
         값은 위 rememberedTab() 이 허용 목록으로 한 번 거른 것이다. */
      var back = rememberedTab();
      if (back && back !== state.kind) {
        state.kind = back;
        var all = tabs.querySelectorAll('.tab');
        for (var j = 0; j < all.length; j++) {
          var onBack = all[j].getAttribute('data-kind') === back;
          all[j].className = onBack ? 'tab is-on' : 'tab';
          all[j].setAttribute('aria-selected', onBack ? 'true' : 'false');
        }
        setText($('p-list-title'), KIND_TITLE[back]);
      }
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

    /** 만들기 요청 — 거래처의 409 는 모달과 **같은 방식**으로 되묻는다 (2차 보정 규칙). */
    function submitCreate(payload, allowDuplicateName) {
      var body = {};
      Object.keys(payload).forEach(function (k) { body[k] = payload[k]; });
      if (allowDuplicateName) { body.allow_duplicate_name = true; }
      request('POST', '/api/purchase/' + state.kind, body)
        .then(function (result) {
          if (!result) { return; }
          if (result.status === 201) {
            var form2 = $('p-create-form');
            if (form2) { form2.reset(); }
            clearRefCache(state.kind);
            say('만들었습니다.');
            renderCreateForm();
            load();
            return;
          }
          if (result.status === 409 && state.kind === 'vendors' && !allowDuplicateName
              && window.confirm('같은 이름의 거래처가 이미 있습니다. 그래도 등록할까요?')) {
            submitCreate(payload, true);
            return;
          }
          say(errorMessage(result.data));
        })
        .catch(function () { say(UI.GENERIC_ERROR); });
    }

    var form = $('p-create-form');
    if (form) {
      form.addEventListener('submit', function (event) {
        event.preventDefault();
        var payload = {};
        var missing = [];
        createReaders.forEach(function (r) {
          var v = r.read();
          if (v === '' || v === null || v === undefined) {
            if (r.required) { missing.push(r.spec.label); }
            return;
          }
          payload[r.key] = v;
        });
        if (missing.length > 0) {
          say('필수 칸 ' + missing.map(function (m) { return '「' + m + '」'; }).join(' · ') + ' 을(를) 채워 주세요.');
          return;
        }
        say('');
        submitCreate(payload, false);
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

    /*
     * 23단계 — 사용 지점 **빼기**. 서버는 `p_item_branches.is_active` 를 끄고(지우지 않는다),
     * 권한(`purchase.master.write` + 재인증)은 **서버가** 다시 본다. 화면은 단추를 보여 줄 뿐
     * 판단하지 않는다 — 권한이 없으면 403 이 오고 그 시도도 감사 로그에 남는다 (PRD S-01).
     */
    function offBranch(branchId, branchName) {
      if (!window.confirm('「' + branchName + '」 을(를) 이 품목의 사용 지점에서 뺍니다. 계속할까요?')) {
        return;
      }
      request('PATCH', '/api/purchase/items/' + itemId, { branches_off: [branchId] })
        .then(function (result) {
          if (!result) { return; }
          if (!result.ok) { say(errorMessage(result.data)); return; }
          say('사용 지점에서 뺐습니다 (지운 것이 아니라 끈 것이며 지난 전표에는 남습니다).');
          initItem(root);
        })
        .catch(function () { say(UI.GENERIC_ERROR); });
    }

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
          var dd = el('dd', row[0] === '품목코드' ? 'mono' : null, dash(row[1]));
          if (row[0] === '현재고') { dd.id = 'p-item-stock'; }
          facts.appendChild(dd);
        });

        /* 현재고 — 품목 상세 API 는 아직 null 을 준다. **있는 창구**(재고 현황)에서
           같은 품목의 장소별 현재고를 받아 더한다. 새 API 를 만들지 않는다.
           행이 하나도 없으면 「—」 그대로 둔다 — 0 으로 보여 주면 「재고가 없다」는
           틀린 사실을 말하게 된다. */
        if (item.code) {
          request('GET', '/api/purchase/stock?q=' + encodeURIComponent(String(item.code)))
            .then(function (stockRes) {
              if (!stockRes || !stockRes.ok) { return; }
              var mine = (stockRes.data.items || []).filter(function (r) {
                return String(r.item_id) === String(item.id);
              });
              if (mine.length === 0) { return; }
              var sum = 0;
              mine.forEach(function (r) {
                var n = Number(r.on_hand);
                if (!isNaN(n)) { sum += n; }
              });
              setText($('p-item-stock'), String(Math.round(sum * 1000) / 1000)
                + ' (장소 ' + String(mine.length) + '곳 합계)');
            });
        }

        var chips = $('p-item-branches');
        clear(chips);
        var branches = Array.isArray(result.data.branches) ? result.data.branches : [];
        if (branches.length === 0) {
          chips.appendChild(el('li', 'chip', '지정 없음 (브랜드 전체)'));
        } else {
          branches.forEach(function (branch) {
            var chip = el('li', 'chip', branch.branch_name);
            var off  = el('button', 'btn btn--quiet btn--small', '빼기');
            off.type = 'button';
            off.addEventListener('click', function () {
              offBranch(branch.branch_id, String(branch.branch_name));
            });
            chip.appendChild(off);
            chips.appendChild(chip);
          });
        }

        var parBody = $('p-item-par');
        clear(parBody);
        var pars = Array.isArray(result.data.par_levels) ? result.data.par_levels : [];
        pars.forEach(function (par) {
          var tr = document.createElement('tr');
          tr.appendChild(el('td', null, dash(par.branch_name)));
          tr.appendChild(el('td', null, dash(par.location_name)));
          tr.appendChild(el('td', 'num mono', dash(par.qty)));
          tr.appendChild(el('td', null, dash(par.effective_from)));
          parBody.appendChild(tr);
        });
        var parEmpty = $('p-item-par-empty');
        if (parEmpty) { parEmpty.hidden = pars.length !== 0; }
      })
      .catch(function () { say(UI.GENERIC_ERROR); });
  }

  /* ══════════════════════════════════════════════════════
   * 화면 ⑯ — 엑셀 가져오기 (파일 → 열 맞추기 → 미리보기 → 확정)
   * ══════════════════════════════════════════════════════ */

  var STATUS_LABEL = { insert: '등록', update: '갱신', review: '확인', error: '오류' };

  /** 오류 사유 코드 → 사람 말 (서버는 코드만 준다 — 문구는 화면의 몫이다) */
  var REASON_LABEL = {
    required_missing: '필수 칸이 비었습니다',
    vendor_not_found: '거래처를 찾을 수 없습니다 — 「없는 거래처를 함께 등록」 을 켜거나'
                    + ' 거래처를 먼저 가져오세요',
    // 9차 보정 2026-09-14 — 거래처 칸이 **비어 있는** 행. 품목은 거래처 없이 저장할 수 없고,
    // 「미지정 거래처」 같은 가짜를 만들지도 않는다(그 순간 발주서가 갈 곳을 잃는다).
    vendor_missing: '거래처 칸이 비어 있습니다 — 파일에서 구매처명을 채워 주세요'
                  + ' (품목은 거래처 없이 등록할 수 없습니다)',
    category_not_found: '분류 이름이 등록된 분류와 다릅니다 — 「파일의 분류를 함께 등록」 을 켜면'
                      + ' 확정과 같은 순간에 만듭니다',
    duplicate_in_file: '같은 거래처·코드가 이 파일 안에 또 있습니다',
    branch_not_found: '지점 이름을 찾을 수 없습니다',
    name_differs: '품목명이 등록된 이름과 다릅니다',
    // 1차 보정 — 이카운트의 「당수량(분자)/(분모)」. 분모가 1 이 아니면 사람이 봐야 한다.
    pack_den_not_one: '포장 수량의 분모가 1 이 아닙니다 — 낱개 수를 확인해 주세요',
    // 2차 보정 2026-09-13 — 실제 이카운트 거래처·창고 파일에서 나온 확인거리들
    duplicate_name_in_file: '같은 이름이 이 파일에 이미 있습니다 (코드가 다르면 「승격」해 따로 등록하세요)',
    name_exists: '같은 이름의 거래처가 이미 등록돼 있습니다 (다른 사업자면 「승격」해 따로 등록하세요)',
    branch_ambiguous: '창고명에 여러 지점 이름이 들어 있어 어느 지점인지 정할 수 없습니다',
    brand_not_found: '브랜드를 찾을 수 없습니다 — 「기본 브랜드」를 고르거나 브랜드를 먼저 등록하세요',
    // 3차 보정 (SECURITY R-72) — 같은 이름의 거래처가 여럿이라 어느 쪽인지 못 정한다.
    vendor_ambiguous: '같은 이름의 거래처가 여럿입니다 — 거래처 코드 열을 함께 매핑하거나,'
                    + ' 승격하면 첫 거래처로 붙습니다',
    // 5차 보정 — 창고명으로 지점을 알아낼 수 없다(「중앙창고」처럼 조각이 하나거나 어미가 없다).
    branch_unresolved: '창고명으로 지점을 알아낼 수 없습니다 — 「기본 지점」 을 고르거나'
                     + ' 지점 열을 매핑해 주세요'
  };

  function initImport() {
    var state = {
      importId: '', kind: 'items', columns: [], sample: [], targets: {}, required: {},
      judged: null,
      // 1차 보정 2026-09-13 — 머리행은 서버가 고르고 사람이 바꾼다.
      headerRow: 0,        // 지금 고른 줄 (0기준)
      detected: {},        // 종류별 서버 감지 결과
      step: 1, fileName: '', fileRows: 0,
      headRows: [],        // 앞 20행 원문 — 재업로드 없이 다시 그리는 재료
      guess: {},           // 종류별 매핑 후보 (서버가 준 것)
      // 2차 보정 — 지점 열이 없는 창고 파일·브랜드 열이 없는 지점 파일을 위한 기본값
      branchCount: 0, brandCount: 0, derive: '',
      // 3차 보정 — 체크를 끈 행 번호 모음 (확정에서 exclude_rows 로 나간다)
      excluded: {},
      // 6차 보정 — 행 목록의 쪽·필터 (판정은 전체 행, 보이는 것만 자른다)
      rowPage: 1, rowStatus: '', rowQuery: '', lastPreview: null
    };

    /** 왼쪽 「열 맞추기 요약」 — 무엇을 어떤 칸으로 넣는 중인지 잊지 않게 (시안 ⑯) */
    function renderSummary() {
      var box = $('p-summary');
      if (!box) { return; }
      clear(box);
      var mapping = currentMapping();
      box.appendChild(el('dt', null, '파일'));
      box.appendChild(el('dd', null, state.fileName || '—'));
      box.appendChild(el('dt', null, '행 수'));
      box.appendChild(el('dd', 'num mono', String(state.fileRows || 0)));
      box.appendChild(el('dt', null, '종류'));
      box.appendChild(el('dd', null, KIND_NOUN[state.kind] || state.kind));
      box.appendChild(el('dt', null, '머리행'));
      box.appendChild(el('dd', 'num mono', String(state.headerRow + 1)));
      /* mapping 은 {엑셀 열 이름: 우리 칸 키} 다 — 사람이 읽을 이름으로 바꿔 적는다 */
      Object.keys(mapping).forEach(function (column) {
        box.appendChild(el('dt', null, labelOf(mapping[column])));
        box.appendChild(el('dd', null, String(column)));
      });
    }

    /**
     * 지금 어느 걸음인가 — **한 단계 패널만** 보인다 (6차 보정 2026-09-13, 사용자 결정).
     *
     * 전에는 네 패널이 모두 쌓여서, 8,100행짜리 표 아래에 확정 단추가 묻혔다.
     * 끝난 단계는 한 줄 요약(`p-done-N`) + 「수정」 으로 접고, 지금 단계만 펼친다.
     */
    function step(current) {
      state.step = current;
      [1, 2, 3, 4].forEach(function (n) {
        var node = $('p-step-' + String(n));
        if (node) { node.className = n === current ? 'chip is-on' : (n < current ? 'chip is-done' : 'chip'); }
      });

      /* 3 걸음은 **2단**이다 — 왼쪽 「열 맞추기 요약」이 미리보기·결과를 보는 동안 남는다
         (시안 ⑯). 그래서 감추고 켜는 것은 바깥 2단 틀(`p-preview-split`)이다. */
      var panels = { 1: 'p-file-panel', 2: 'p-map-panel', 3: 'p-preview-split', 4: 'p-result-panel' };
      Object.keys(panels).forEach(function (n) {
        var node = $(panels[n]);
        if (node) { node.hidden = Number(n) !== current; }
      });
      renderSummary();

      // 끝난 단계의 한 줄 요약
      [1, 2].forEach(function (n) {
        var done = $('p-done-' + String(n));
        if (done) { done.hidden = current <= n; }
      });
      var d1 = $('p-done-1-text');
      if (d1) {
        setText(d1, state.fileName
          ? '파일: ' + state.fileName + ' · ' + String(state.fileRows) + '행 · 종류: ' + (KIND_NOUN[state.kind] || state.kind)
          : '');
      }
      var d2 = $('p-done-2-text');
      if (d2) {
        var mapped = Object.keys(currentMapping()).length;
        setText(d2, '머리행 ' + String(state.headerRow + 1) + '행 · 이어진 칸 ' + String(mapped) + '개');
      }
    }

    // 「수정」 — 앞 단계로 돌아간다. 2단계로 돌아갔다 다시 오면 **재판정**된다.
    [['p-back-1', 1], ['p-back-2', 2]].forEach(function (pair) {
      var button = $(pair[0]);
      if (button) {
        button.addEventListener('click', function () { say(''); step(pair[1]); });
      }
    });

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

    // 5차 보정 — 「함께 만들기」 를 켜고 끄면 필요한 기본값 칸·안내가 달라진다.
    ['p-create-branches', 'p-create-brands',
     // 9차 보정 — 품목 쪽 두 스위치. 켜고 끄면 다음 미리보기부터 판정이 달라진다.
     'p-create-categories', 'p-create-vendors'].forEach(function (id) {
      var box = $(id);
      if (box) { box.addEventListener('change', function () { showDefaults(); say(''); }); }
    });

    /* 3차 보정 — 「기본 지점」·「기본 브랜드」 옆의 「+ 새로 등록」.
       지점이 0건이라 창고를 한 건도 못 넣던 막다른 길을 **이 화면에서** 푼다. */
    [['p-add-branch', 'branches', 'p-default-branch'],
     ['p-add-brand', 'brands', 'p-default-brand']].forEach(function (trio) {
      var button = $(trio[0]);
      if (!button) { return; }
      button.addEventListener('click', function () {
        openRegisterModal(trio[1], {}, function (created) {
          var select = $(trio[2]);
          if (!select) { return; }
          // 목록을 다시 받아 **방금 만든 것이 골라진 채로** 돌아온다.
          request('GET', '/api/purchase/' + trio[1] + '?page=1').then(function (result) {
            if (!result || !result.ok) { return; }
            var items = Array.isArray(result.data.items) ? result.data.items : [];
            clear(select);
            var blank = document.createElement('option');
            blank.value = '';
            setText(blank, '— 고르세요 —');
            select.appendChild(blank);
            items.forEach(function (row) {
              var option = document.createElement('option');
              option.value = String(row.id);
              setText(option, refLabel(trio[1], row));
              if (created && String(row.id) === String(created.id)) { option.selected = true; }
              select.appendChild(option);
            });
            if (trio[1] === 'branches') { state.branchCount = items.length; }
            if (trio[1] === 'brands') { state.brandCount = items.length; }
            showDefaults();
            say('등록했습니다 — 이제 미리보기를 눌러 주세요.');
          }).catch(function () { /* 목록을 못 받아도 화면은 살아 있다 */ });
        });
      });
    });

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
      // 5차 보정 — 창고에서 지점을 만들 때도 브랜드가 필요하다.
      var makeBranches = $('p-create-branches');
      var needBrand  = state.kind === 'branches'
                    || (state.kind === 'locations' && makeBranches && makeBranches.checked);
      var derive     = state.kind === 'branches' || state.kind === 'locations';

      // 창고 종류일 때만 「함께 만들기」 체크 둘을 보여 준다.
      var createBox = $('p-create-missing');
      if (createBox) { createBox.hidden = state.kind !== 'locations'; }
      /* 9차 보정 — 품목 종류의 짝(「파일의 분류를 함께 등록」·「없는 거래처를 함께 등록」).
         종류가 items 가 아니면 서버도 이 옵션을 무시하므로 화면에서도 감춘다. */
      var itemsBox = $('p-create-items');
      if (itemsBox) { itemsBox.hidden = state.kind !== 'items'; }
      var itemsHelp = $('p-create-items-help');
      if (itemsHelp) { itemsHelp.hidden = state.kind !== 'items'; }

      box.hidden = !(needBranch || needBrand || derive);
      var bf = $('p-default-branch-field');
      if (bf) { bf.hidden = !needBranch; }
      var rf = $('p-default-brand-field');
      if (rf) { rf.hidden = !needBrand; }
      var db = $('p-derive-branches');
      // 지점 뽑기 단추는 **창고 파일을 올렸을 때만** 뜻이 있다(창고명에서 뽑는다).
      if (db) { db.hidden = !(state.kind === 'locations'); }

      /* 목록이 0건이면 **셀렉트를 감추고 안내를 바로 보여 준다** (4차 보정).
         고를 것이 없는 셀렉트를 띄워 두면 "왜 안 되지" 로 시간을 쓴다. */
      var branchEmpty = needBranch && state.branchCount === 0;
      var brandEmpty  = needBrand && state.brandCount === 0;
      var branchSelect = $('p-default-branch');
      if (branchSelect) { branchSelect.hidden = branchEmpty; }
      var brandSelect = $('p-default-brand');
      if (brandSelect) { brandSelect.hidden = brandEmpty; }

      var note = $('p-defaults-note');
      if (!note) { return; }
      if (branchEmpty) {
        setText(note, '등록된 지점이 없습니다 — 옆의 「+ 새로 등록」 으로 만들거나,'
                    + ' 「창고명에서 지점 이름 뽑기」 로 창고명에서 한 번에 만들 수 있습니다.');
      } else if (brandEmpty) {
        setText(note, '등록된 브랜드가 없습니다 — 옆의 「+ 새로 등록」 으로 먼저 만들어 주세요.');
      } else if (needBranch) {
        setText(note, '「기본 지점」 은 **창고명으로 지점을 알아낼 수 없는 행**에만 쓰입니다'
                    + ' (예 「중앙창고」). 「플란치과_강남점」 처럼 생긴 이름은 각자의 지점으로 갑니다.');
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
      // 5차 보정 — 창고 가져오기 한 흐름에서 브랜드·지점을 함께 만들까
      var cb = $('p-create-branches');
      var cr = $('p-create-brands');
      if (cb && cb.checked) { body.create_branches = true; }
      if (cr && cr.checked) { body.create_brands = true; }
      /* 9차 보정 — 품목 파일의 분류·없는 거래처를 확정과 같은 트랜잭션에서 만들까.
         종류가 items 일 때만 보낸다(그 밖에는 서버가 무시하지만, 안 보내는 것이 원칙이다). */
      if (body.kind === 'items') {
        var cc = $('p-create-categories');
        var cv = $('p-create-vendors');
        if (cc && cc.checked) { body.create_categories = true; }
        if (cv && cv.checked) { body.create_vendors = true; }
      }
      // 6차 보정 — 보이는 목록의 쪽·필터 (판정 범위가 아니라 **표시 범위**다)
      body.page = state.rowPage;
      if (state.rowStatus !== '') { body.status = state.rowStatus; }
      if (state.rowQuery !== '') { body.q = state.rowQuery; }
      Object.keys(extra || {}).forEach(function (k) { body[k] = extra[k]; });
      return body;
    }

    /**
     * 저장된 매핑 하나를 화면에 되살린다 — 매핑 · **머리행** · **옵션**까지 (9차 보정).
     *
     * 옵션을 빼면 「파일의 분류를 함께 등록」 이 꺼진 채로 판정이 돌아, 같은 파일인데
     * 8,000행이 「분류를 찾을 수 없습니다」 가 된다. 사람은 매핑을 골랐으니 됐다고 믿는다 —
     * 그 어긋남이 F-29 의 「두 걸음」 을 깨뜨린 자리다.
     */
    function applySaved(chosen) {
      var row = typeof chosen.header_row === 'number' ? chosen.header_row : 0;
      if (row < 0 || row >= state.headRows.length) { row = 0; }
      var sel = $('p-header-row');
      if (sel) { sel.value = String(row); }

      var opts = chosen.options || {};
      [['create_branches', 'p-create-branches'], ['create_brands', 'p-create-brands'],
       ['create_categories', 'p-create-categories'], ['create_vendors', 'p-create-vendors']
      ].forEach(function (pair) {
        var box = $(pair[1]);
        if (box && typeof opts[pair[0]] === 'boolean') { box.checked = opts[pair[0]]; }
      });
      [['default_branch', 'p-default-branch'], ['default_brand', 'p-default-brand']
      ].forEach(function (pair) {
        var pick = $(pair[1]);
        if (pick && opts[pair[0]]) { pick.value = String(opts[pair[0]]); }
      });

      applyHeaderRow(row, chosen.mapping);
      showDefaults();
    }

    /* 「열 맞추기 보기」 — 자동 경로로 3 걸음에 바로 온 사람이 2 걸음을 열어 보는 길 (9차 보정).
       적용된 매핑을 숨기지 않는다: 무엇이 씌워졌는지 언제든 확인하고 고칠 수 있어야 한다. */
    var showMapping = $('p-show-mapping');
    if (showMapping) {
      showMapping.addEventListener('click', function () { say(''); step(2); });
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
              applySaved(chosen);
            });

            state.headerRow = picked;
            state.derive = '';
            fillHeaderSelect();
            applyHeaderRow(picked, state.guess[state.kind] || {});
            showDefaults();          // 2차 보정 — 종류에 맞는 기본값 칸을 연다
            state.fileName = result.data.file_name || '';
            state.fileRows = result.data.rows || 0;
            step(2);
            say('');

            /* ─── 9차 보정 — **자동 경로** (사용자 결정 「자동 마이그레이션 가능하도록」) ───
             *
             * 서버가 이 파일에 그대로 맞는 저장 매핑을 찾아 `match` 로 알려 준다. 있으면
             * 매핑·머리행·옵션을 되살려 **미리보기까지 한 번에** 간다 — 매달 같은 이카운트
             * 파일을 올리면서 열 15개와 체크 둘을 다시 고를 이유가 없다.
             *
             * 종류가 다르면 건너뛴다(거래처 파일을 올렸는데 품목 매핑을 씌우지 않는다).
             * 건너뛴 것을 숨기지도 않는다 — 3 걸음 위에 무엇을 적용했는지 적고,
             * 「열 맞추기 보기」 로 언제든 2 걸음을 열 수 있다. */
            var autoNote = $('p-auto-note');
            var matched = null;
            var matchedIndex = -1;
            if (result.data.match) {
              (result.data.saved || []).forEach(function (one, index) {
                if (one.id === result.data.match && one.kind === state.kind) {
                  matched = one;
                  matchedIndex = index;
                }
              });
            }
            if (!matched) {
              if (autoNote) { autoNote.hidden = true; }
              return;
            }
            savedSelect.value = String(matchedIndex);
            applySaved(matched);
            if (autoNote) {
              setText($('p-auto-text'),
                '저장된 매핑 「' + String(matched.name) + '」 을 적용했습니다 — ');
              autoNote.hidden = false;
            }
            say('저장된 매핑을 적용해 판정하는 중…');
            request('POST', '/api/purchase/import/' + state.importId + '/preview', importBody())
              .then(function (preview) {
                if (!preview) { return; }
                if (!preview.ok) { say(errorMessage(preview.data)); step(2); return; }
                renderPreview(preview.data, '');
              })
              .catch(function () { say(UI.GENERIC_ERROR); step(2); });
          })
          .catch(function () { say(UI.GENERIC_ERROR); });
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

    /**
     * 다시 판정한다 — **재업로드 없이** 같은 import_id·같은 매핑·같은 옵션으로 다시 묻는다
     * (3차 보정). 팝업으로 거래처·지점·분류를 만든 직후에 부른다: 방금 만든 것이 반영돼
     * 「거래처를 찾을 수 없습니다」 3,000행이 한 번에 「등록」으로 바뀐다.
     */
    function rejudge(note) {
      say('다시 판정하는 중…');
      request('POST', '/api/purchase/import/' + state.importId + '/preview', importBody())
        .then(function (result) {
          if (!result) { return; }
          if (!result.ok) { say(errorMessage(result.data)); return; }
          renderPreview(result.data, note || '');
        })
        .catch(function () { say(UI.GENERIC_ERROR); });
    }

    /**
     * 「이것만 등록하면 N행이 풀린다」 줄 (3차 보정).
     * 서버가 값 기준으로 묶어 준 `missing` 목록을 그대로 쓴다 — 같은 거래처 이름으로 막힌
     * 행이 3,000개여도 단추는 **하나**다.
     */
    function renderMissing(data) {
      var box = $('p-missing');
      var shell = $('p-missing-box');
      if (!box) { return; }
      clear(box);
      var list = Array.isArray(data.missing) ? data.missing : [];
      // 6차 보정 — 기본은 **접혀 있고 건수만** 보인다(도구줄 아래가 길어지지 않게).
      if (shell) {
        shell.hidden = list.length === 0;
        setText($('p-missing-summary'),
          '등록되지 않은 값 ' + String(list.length) + '가지 — 여기서 바로 등록 (펼치기)');
      }
      if (list.length === 0) { return; }

      list.forEach(function (miss) {
        var line = el('div', 'p-missing__row');
        line.appendChild(badge(KIND_NOUN[miss.kind] || miss.kind, 'badge--danger'));
        line.appendChild(el('span', 'p-missing__value', miss.value));
        line.appendChild(el('span', 'muted', '이 값 ' + String(miss.rows) + '행'));

        var add = el('button', 'btn btn--quiet btn--small', (KIND_NOUN[miss.kind] || miss.kind) + ' 등록');
        add.type = 'button';
        add.addEventListener('click', function () {
          // 파일에 적힌 이름을 **그대로 채워** 연다 — 사람이 다시 타이핑하지 않는다.
          var prefill = { name: miss.value };
          if (miss.kind === 'categories') {
            if (miss.level) { prefill.level = String(miss.level); }
            if (miss.parent_id) { prefill.parent_id = String(miss.parent_id); }
          }
          openRegisterModal(miss.kind, prefill, function () {
            rejudge('「' + miss.value + '」 을(를) 등록했습니다 — 다시 판정했습니다.');
          });
        });
        line.appendChild(add);
        box.appendChild(line);
      });
    }

    /** 무엇을 보고 지점·브랜드를 정했나 — 표의 배지 문구 */
    var BY_LABEL = {
      column:   '파일의 열',
      name:     '이름에서 찾음',
      name_new: '새로 만듦',
      default:  '기본값'
    };

    /**
     * 「이번 확정으로 **새로 생길** 브랜드·지점」 요약 (5차 보정 2026-09-13).
     *
     * 사용자 결정대로 창고 파일 하나가 브랜드·지점까지 만든다 — 그러면 **무엇이 생기는지**를
     * 누르기 전에 보여 줘야 한다. 이름 목록은 접어 두고(`<details>`) 개수만 먼저 보인다.
     */
    function renderToCreate(data) {
      var box = $('p-tocreate');
      var shell = $('p-tocreate-box');
      if (!box) { return; }
      clear(box);
      var plan = data.to_create || {};
      var brands = Array.isArray(plan.brands) ? plan.brands : [];
      var branches = Array.isArray(plan.branches) ? plan.branches : [];
      // 9차 보정 — 품목 가져오기의 분류·거래처. **수는 전체**(new_*), 이름은 앞 20개만 온다.
      var cats = Array.isArray(plan.categories) ? plan.categories : [];
      var vends = Array.isArray(plan.vendors) ? plan.vendors : [];
      var catCount = data.new_categories || 0;
      var vendCount = data.new_vendors || 0;
      var none = brands.length === 0 && branches.length === 0 && catCount === 0 && vendCount === 0;
      if (shell) {
        shell.hidden = none;
        var parts = [];
        if (brands.length > 0) { parts.push('브랜드 ' + String(brands.length) + '개'); }
        if (branches.length > 0) { parts.push('지점 ' + String(branches.length) + '개'); }
        if (catCount > 0) { parts.push('분류 ' + String(catCount) + '개'); }
        if (vendCount > 0) { parts.push('거래처 ' + String(vendCount) + '개'); }
        setText($('p-tocreate-summary'),
          '확정하면 ' + parts.join(' · ') + '가 함께 만들어집니다 (펼치기)');
      }
      if (none) { return; }

      box.appendChild(el('p', 'muted',
        '품목·창고보다 먼저, 같은 트랜잭션 안에서 만들어집니다 — 한 줄이라도 실패하면 함께 되돌아갑니다.'));

      if (brands.length > 0) {
        var bd = document.createElement('details');
        bd.appendChild(el('summary', null, '새로 만들 브랜드 ' + String(brands.length) + '개 보기'));
        var bul = el('ul', 'p-chips');
        brands.forEach(function (n) { bul.appendChild(el('li', 'chip', n)); });
        bd.appendChild(bul);
        box.appendChild(bd);
      }
      if (branches.length > 0) {
        var rd = document.createElement('details');
        rd.appendChild(el('summary', null, '새로 만들 지점 ' + String(branches.length) + '개 보기'));
        var rul = el('ul', 'p-chips');
        branches.forEach(function (b) {
          rul.appendChild(el('li', 'chip', b.name + (b.brand ? ' (' + b.brand + ')' : '')));
        });
        rd.appendChild(rul);
        box.appendChild(rd);
      }

      /* 9차 보정 — 새 분류는 **몇 단계의 어느 부모 밑**인지까지 보여 준다.
         「기타」 는 대분류마다 하나씩 있어서, 이름만 보면 어느 것인지 알 수 없다. */
      if (catCount > 0) {
        var cd = document.createElement('details');
        cd.appendChild(el('summary', null, '새로 만들 분류 ' + String(catCount) + '개'
          + (cats.length < catCount ? ' 중 앞 ' + String(cats.length) + '개' : '') + ' 보기'));
        var cul = el('ul', 'p-chips');
        cats.forEach(function (c) {
          cul.appendChild(el('li', 'chip',
            String(c.level) + '단 ' + String(c.name)
            + (c.parent_name ? ' (상위 ' + String(c.parent_name) + ')' : '')));
        });
        cd.appendChild(cul);
        box.appendChild(cd);
      }
      if (vendCount > 0) {
        var vd = document.createElement('details');
        vd.appendChild(el('summary', null, '새로 만들 거래처 ' + String(vendCount) + '개'
          + (vends.length < vendCount ? ' 중 앞 ' + String(vends.length) + '개' : '') + ' 보기'));
        var vul = el('ul', 'p-chips');
        vends.forEach(function (n) { vul.appendChild(el('li', 'chip', n)); });
        vd.appendChild(vul);
        box.appendChild(vd);
        box.appendChild(el('p', 'muted',
          '새 거래처는 「이름」만 등록됩니다 (거래처 코드는 비어 있습니다) — 파일에 코드 열이 없습니다.'));
      }
    }

    /**
     * 미리보기 결과를 그린다 (2차 보정에서 함수로 뺐다 — 「지점 뽑기」도 같은 표를 쓴다).
     * 모든 글자는 `textContent` 로만 들어간다.
     */
    function renderPreview(data, note, keepExcluded) {
      state.judged = data;
      state.lastPreview = data;
      /* 3차 보정 — 판정이 **새로** 되면 뺀 행 기억도 비운다.
         6차 보정 — 쪽을 넘기거나 필터를 바꾼 것뿐이면 **유지한다**(사용자 결정:
         "체크 상태는 페이지를 넘겨도 유지"). 그래서 그 경우만 keepExcluded 로 부른다. */
      if (keepExcluded !== true) { state.excluded = {}; }

      var counts = data.counts || {};
      var box = $('p-preview-counts');
      clear(box);
      [['insert', '등록'], ['update', '갱신'],
       ['review', '확인 필요'], ['error', '오류']].forEach(function (pair) {
        box.appendChild(badge(pair[1] + ' ' + String(counts[pair[0]] || 0),
                              VERDICT_CLASS[pair[0]] || ''));
      });
      var excludedCount = Object.keys(state.excluded || {}).length;
      if (excludedCount > 0) {
        box.appendChild(badge('제외 ' + String(excludedCount), 'badge--off'));
      }
      /* 9차 보정 — 확정으로 **새로 생길** 분류·거래처 수. 사람이 「등록 8,000」 만 보고 누르면,
         기초정보가 157개 늘어난 것을 나중에야 안다. 누르기 전에 말한다(이름은 아래 details). */
      if (data.new_categories) {
        box.appendChild(badge('새 분류 ' + String(data.new_categories), 'badge--accent'));
      }
      if (data.new_vendors) {
        box.appendChild(badge('새 거래처 ' + String(data.new_vendors), 'badge--accent'));
      }

      /* 6차 보정 — 도구줄의 단추들. 「확정」 에 **저장될 행 수**를 적어 두면, 오류가 남아
         있어도 무엇이 들어가는지 알고 누를 수 있다(사용자 결정 「전부 스크롤하지 않아도 확정」). */
      var willSave = Math.max(0, (counts.insert || 0) + (counts.update || 0) - excludedCount);
      var commitButton = $('p-commit');
      if (commitButton) { setText(commitButton, '확정 (' + String(willSave) + '행)'); }
      var promoteAll = $('p-promote-all');
      if (promoteAll) { promoteAll.hidden = (counts.review || 0) === 0; }
      var dl = $('p-errors-download');
      if (dl) { dl.hidden = ((counts.error || 0) + (counts.review || 0)) === 0; }

      renderMissing(data);
      renderToCreate(data);
      renderFilters(data);
      renderPager(data);

      var tbody = $('p-preview-body');
      clear(tbody);
      (data.rows || []).forEach(function (row) {
        var tr = document.createElement('tr');

        /* 행 체크박스 — **기본은 전체 선택**이다 (3차 보정).
           끄면 그 행만 저장에서 빠진다(`exclude_rows`). 창고명에서 뽑은 지점 후보 중
           「임시」·「상담실」 처럼 지점이 아닌 것을 빼는 것이 첫 용도다.
           판정을 바꾸는 것이 아니라 **쓰기만 건너뛴다** — 미리보기와 확정의 판정이 같아야 한다. */
        var pickCell = document.createElement('td');
        var pick = document.createElement('input');
        pick.type = 'checkbox';
        // 6차 보정 — 쪽을 넘겼다 돌아와도 **끈 상태가 남아 있어야** 한다.
        pick.checked = !state.excluded[row.row_no];
        pick.setAttribute('aria-label', String(row.row_no) + '행 저장');
        pick.addEventListener('change', function () {
          if (pick.checked) { delete state.excluded[row.row_no]; }
          else { state.excluded[row.row_no] = true; }
          updateExcludedNote();
        });
        pickCell.appendChild(pick);
        tr.appendChild(pickCell);

        tr.appendChild(el('td', null, row.row_no));

        var statusCell = document.createElement('td');
        statusCell.appendChild(badge(STATUS_LABEL[row.status] || row.status,
                                     VERDICT_CLASS[row.status] || ''));
        /* 9차 보정 — 이 행이 **새로 만들 것**에 기대고 있으면 판정 옆에 작게 알린다
           (「등록 · 분류 새로」). 판정 자체는 그대로 「등록」이다. */
        var rowFlags = row.flags || {};
        if (rowFlags.category_new) { statusCell.appendChild(badge('분류 새로', 'badge--accent')); }
        if (rowFlags.vendor_new) { statusCell.appendChild(badge('거래처 새로', 'badge--accent')); }
        tr.appendChild(statusCell);

        /* 5차 보정 — 브랜드 칸. 새로 만들 브랜드면 이름과 「새로 만듦」 을 함께 보여 준다.
           (브랜드는 기존 것을 쓰면 지점 아래에 숨으므로 이름을 따로 내지 않는다.) */
        var brandCell = document.createElement('td');
        if (row.brand_name_new) {
          brandCell.appendChild(badge('새로 만듦', 'badge--accent'));
          brandCell.appendChild(el('span', 'p-missing__value', ' ' + row.brand_name_new));
        } else {
          setText(brandCell, row.brand_by ? (BY_LABEL[row.brand_by] || row.brand_by) : '—');
          brandCell.className = 'muted';
        }
        tr.appendChild(brandCell);

        // 2차 보정 — 어느 지점으로 가는지와 **무엇을 보고 정했는지**를 함께 보여 준다.
        // 5차 보정 — 아직 없는 지점이면 그 이름과 「새로 만듦」 을 보여 준다.
        var branchCell = document.createElement('td');
        if (row.branch_name_new) {
          branchCell.appendChild(badge('새로 만듦', 'badge--accent'));
          branchCell.appendChild(el('span', 'p-missing__value', ' ' + row.branch_name_new));
        } else {
          var branchText = '—';
          if (row.branch_name) {
            branchText = row.branch_name
              + (row.branch_by ? ' (' + (BY_LABEL[row.branch_by] || row.branch_by) + ')' : '');
          }
          setText(branchCell, branchText);
          branchCell.className = 'muted';
        }
        tr.appendChild(branchCell);

        var reasonCell = el('td', 'muted', row.reason ? (REASON_LABEL[row.reason] || row.reason) : '—');
        // 같은 이름의 거래처가 여럿이면 **몇 개인지**까지 말해 준다 (R-72).
        if (row.vendor_count) {
          setText(reasonCell, (REASON_LABEL[row.reason] || row.reason)
            + ' (같은 이름 ' + String(row.vendor_count) + '개)');
        }
        tr.appendChild(reasonCell);

        var values = [];
        /* 7차 보정 — 창고명에서 갈라 낸 **장소 상세**를 맨 앞에 보여 준다.
           「플란치과_부평점_B1F_의약품창고」 가 어느 지점의 **어느 자리**인지 한눈에 보이게. */
        if (row.detail) { values.push('자리=' + row.detail); }
        Object.keys(row.data || {}).forEach(function (key) {
          if (row.data[key] !== '' && key !== 'derived_from') {
            values.push(labelOf(key) + '=' + row.data[key]);
          }
        });
        tr.appendChild(el('td', 'p-cell-wide', values.join(' · ')));
        tbody.appendChild(tr);
      });

      updateExcludedNote();
      step(3);
      say(note || '');
    }

    /**
     * 상태 필터 칩 (6차 보정). 누르면 **서버에 다시 물어** 그 상태의 행만 받는다 —
     * 화면이 50행만 들고 거르면 「오류만 보기」가 그 50행 안에서만 동작해 사람을 속인다.
     */
    function renderFilters(data) {
      var bar = $('p-filter-chips');
      if (!bar) { return; }
      clear(bar);
      var counts = data.counts || {};
      var chips = [
        ['', '전체', (counts.insert || 0) + (counts.update || 0) + (counts.review || 0) + (counts.error || 0)],
        ['insert', '등록', counts.insert || 0],
        ['update', '갱신', counts.update || 0],
        ['review', '확인 필요', counts.review || 0],
        ['error', '오류', counts.error || 0]
      ];
      chips.forEach(function (chip) {
        var button = el('button', state.rowStatus === chip[0] ? 'chip is-on' : 'chip',
                        chip[1] + ' ' + String(chip[2]));
        button.type = 'button';
        button.addEventListener('click', function () {
          state.rowStatus = chip[0];
          state.rowPage = 1;
          reloadRows();
        });
        bar.appendChild(button);
      });
    }

    /** ‹ 1 / N › — 쪽 이동 (6차 보정) */
    function renderPager(data) {
      var info = $('p-row-info');
      var page = data.row_page || 1;
      var pages = data.row_pages || 1;
      if (info) {
        setText(info, String(page) + ' / ' + String(pages) + ' 쪽 · 이 조건의 행 '
                    + String(data.row_total || 0) + '개 (전체 ' + String(data.total || 0) + '행)');
      }
      var prev = $('p-row-prev');
      if (prev) { prev.disabled = page <= 1; }
      var next = $('p-row-next');
      if (next) { next.disabled = page >= pages; }
    }

    /** 같은 판정을 **다시 물어** 지금 쪽·필터에 맞는 50행을 받는다 (판정은 서버가 다시 한다) */
    function reloadRows() {
      say('불러오는 중…');
      request('POST', '/api/purchase/import/' + state.importId + '/preview', importBody())
        .then(function (result) {
          if (!result) { return; }
          if (!result.ok) { say(errorMessage(result.data)); return; }
          renderPreview(result.data, '', true);
        })
        .catch(function () { say(UI.GENERIC_ERROR); });
    }

    /** 「N행을 빼고 저장합니다」 안내 — 체크를 끌 때마다 다시 쓴다 */
    function updateExcludedNote() {
      var note = $('p-excluded-note');
      if (!note) { return; }
      var n = Object.keys(state.excluded || {}).length;
      setText(note, n === 0 ? '' : '체크를 끈 ' + String(n) + '행은 저장하지 않습니다.');
    }

    /**
     * 지점·브랜드가 정해졌는지 **보내기 전에** 본다 (4차 보정 2026-09-13).
     *
     * 전에는 그대로 보내서 서버가 `default_branch_required` 400 을 돌려주었고, 화면에는
     * 「잘못된 요청입니다」만 떴다 — 창고 파일에 지점 열이 없다는 것도, 어디서 고르는지도
     * 알 수 없었다(사용자 관찰). 서버의 400 문구는 그대로 둔다(사유를 응답에 싣지 않는
     * 규칙은 그대로다 — PRD S-09-5). 여기서 막는 것은 **편의**일 뿐이고, 보내면 서버가
     * 여전히 막는다.
     *
     * @param forDerive 파생 모드(지점 뽑기)인가 — 그때 필요한 것은 브랜드다
     * @return 안내 문구 (빈 문자열이면 보내도 된다)
     */
    function defaultsProblem(forDerive) {
      if (forDerive) {
        var brand = $('p-default-brand');
        if (!brand || brand.value === '') {
          return state.brandCount === 0
            ? '등록된 브랜드가 없습니다 — 옆의 「+ 새로 등록」 으로 브랜드를 먼저 만들어 주세요.'
            : '「기본 브랜드」 를 골라 주세요 — 뽑은 지점들이 그 브랜드 아래에 만들어집니다.';
        }
        return '';
      }
      if (state.kind !== 'locations') { return ''; }

      // 파일에 지점 열이 매핑돼 있으면 화면의 기본값은 필요 없다(서버의 판정 규칙 ①).
      var mapping = currentMapping();
      var hasBranchColumn = false;
      Object.keys(mapping).forEach(function (c) { if (mapping[c] === 'branch') { hasBranchColumn = true; } });
      if (hasBranchColumn) { return ''; }

      // 5차 보정 — 「지점도 함께 만들기」 가 켜져 있으면 기본 지점 없이도 간다
      // (창고명에서 지점을 뽑아 **행마다 다른 지점**으로 만든다).
      var makeBranches = $('p-create-branches');
      if (makeBranches && makeBranches.checked) {
        var brandSel = $('p-default-brand');
        var makeBrands = $('p-create-brands');
        var haveBrand = (brandSel && brandSel.value !== '') || (makeBrands && makeBrands.checked);
        if (!haveBrand) {
          return state.brandCount === 0
            ? '새로 만들 지점을 어느 브랜드에 붙일지 알 수 없습니다 —'
              + ' 「브랜드도 함께 만들기」 를 켜거나, 옆의 「+ 새로 등록」 으로 브랜드를 먼저 만들어 주세요.'
            : '새로 만들 지점을 어느 브랜드에 붙일지 골라 주세요 —'
              + ' 「기본 브랜드」 를 고르거나 「브랜드도 함께 만들기」 를 켜면 됩니다.';
        }
        return '';
      }

      var branch = $('p-default-branch');
      if (!branch || branch.value === '') {
        return state.branchCount === 0
          ? '등록된 지점이 없습니다 — 위의 「창고명에서 지점도 함께 만들기」 를 켜면'
            + ' 창고명에서 지점을 만들면서 한 번에 넣을 수 있습니다.'
          : '「기본 지점」 을 고르거나, 「창고명에서 지점도 함께 만들기」 를 켜 주세요'
            + ' (창고명이 「브랜드_지점」 꼴이면 각자의 지점으로 들어갑니다).';
      }
      return '';
    }

    var previewButton = $('p-map-preview');
    if (previewButton) {
      previewButton.addEventListener('click', function () {
        state.derive = '';   // 일반 미리보기는 파생 모드가 아니다
        var problem = defaultsProblem(false);
        if (problem !== '') { say(problem); return; }
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
          .catch(function () { say(UI.GENERIC_ERROR); });
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
        var brandProblem = defaultsProblem(true);
        if (brandProblem !== '') { say(brandProblem); return; }
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
          .catch(function () { state.derive = ''; say(UI.GENERIC_ERROR); });
      });
    }

    /* 6차 보정 — 쪽 이동·검색·「확인 필요 모두 승격」. 전부 **서버에 다시 물어** 그린다. */
    var rowPrev = $('p-row-prev');
    if (rowPrev) {
      rowPrev.addEventListener('click', function () {
        if (state.rowPage > 1) { state.rowPage -= 1; reloadRows(); }
      });
    }
    var rowNext = $('p-row-next');
    if (rowNext) {
      rowNext.addEventListener('click', function () { state.rowPage += 1; reloadRows(); });
    }
    var rowQ = $('p-row-q');
    if (rowQ) {
      rowQ.addEventListener('change', function () {
        state.rowQuery = rowQ.value;
        state.rowPage = 1;
        reloadRows();
      });
    }
    var promoteAllButton = $('p-promote-all');
    if (promoteAllButton) {
      promoteAllButton.addEventListener('click', function () {
        /* 「확인 필요」 를 전부 승격한다. 승격 목록은 **확정 요청에만** 실린다 —
           판정을 바꾸는 것이 아니라 "이 행들은 사람이 봤다" 는 표시다. */
        var all = (state.lastPreview && Array.isArray(state.lastPreview.attention))
          ? state.lastPreview.attention : [];
        var picked = [];
        all.forEach(function (r) { if (r.status === 'review') { picked.push(r.row_no); } });
        if (picked.length === 0) { say('승격할 「확인 필요」 행이 없습니다.'); return; }
        state.promote = picked;
        say('「확인 필요」 ' + String(picked.length) + '행을 승격 대상으로 두었습니다 — 확정을 누르면 함께 저장됩니다.');
      });
    }

    var saveButton = $('p-map-save');
    if (saveButton) {
      saveButton.addEventListener('click', function () {
        var name = ($('p-map-name').value || '').trim();
        if (name === '') { say('매핑 이름을 적어 주세요.'); return; }
        say('');
        /* 9차 보정 — **옵션도 함께** 저장한다. 매핑만 기억하면 다음 달에 「분류·거래처 함께
           등록」 이 꺼진 채로 돌아 같은 파일이 통째로 오류가 된다. 보내는 것은 화면의 스위치와
           기본 지점·브랜드 id 뿐이고, 서버가 허용 키·타입을 다시 본다. */
        var saveOptions = {};
        [['create_branches', 'p-create-branches'], ['create_brands', 'p-create-brands'],
         ['create_categories', 'p-create-categories'], ['create_vendors', 'p-create-vendors']
        ].forEach(function (pair) {
          var box = $(pair[1]);
          if (box) { saveOptions[pair[0]] = !!box.checked; }
        });
        [['default_branch', 'p-default-branch'], ['default_brand', 'p-default-brand']
        ].forEach(function (pair) {
          var pick = $(pair[1]);
          if (pick && pick.value !== '') { saveOptions[pair[0]] = pick.value; }
        });
        request('POST', '/api/purchase/import/mappings',
                { kind: state.kind, name: name, mapping: currentMapping(),
                  header_row: state.headerRow, options: saveOptions })
          .then(function (result) {
            if (!result) { return; }
            say(result.ok ? '매핑과 옵션을 저장했습니다 — 같은 양식을 다시 올리면 자동으로 적용됩니다.'
                          : errorMessage(result.data));
          })
          .catch(function () { say(UI.GENERIC_ERROR); });
      });
    }

    var commitButton = $('p-commit');
    if (commitButton) {
      commitButton.addEventListener('click', function () {
        var commitProblem = defaultsProblem(state.derive === 'location_name');
        if (commitProblem !== '') { say(commitProblem); return; }
        if (!window.confirm('오류 행을 뺀 나머지를 저장합니다. 계속할까요?')) { return; }
        say('확정하는 중…');
        var commitExtra = { exclude_rows: Object.keys(state.excluded || {}).map(Number) };
        if (Array.isArray(state.promote) && state.promote.length > 0) {
          commitExtra.promote = state.promote;
        }
        /* 확정 요청에는 **쪽·필터를 싣지 않는다** — 저장은 언제나 전체 행이 대상이다.
           importBody() 가 미리보기용으로 붙이는 page·status·q 셋을 **모두** 지운다. 6차 보정
           직후에는 page 만 남겨 보냈고, 확정 핸들러의 허용 키 목록에 page 가 없어 「잘못된
           요청입니다」 400 으로 확정이 전부 막혔다(2026-09-13 20:05 실측 — 미리보기는 되는데
           확정만 안 되는 형태). 서버도 이 셋은 받아서 무시하지만, 안 보내는 것이 원칙이다. */
        var commitBody = importBody(commitExtra);
        delete commitBody.page;
        delete commitBody.status;
        delete commitBody.q;
        var commitBtn = $('p-commit');
        if (commitBtn) { commitBtn.disabled = true; setText(commitBtn, '확정하는 중…'); }
        request('POST', '/api/purchase/import/' + state.importId + '/commit', commitBody)
          .then(function (result) {
            if (commitBtn) { commitBtn.disabled = false; setText(commitBtn, '확정'); }
            if (!result) { return; }
            if (!result.ok) { say(errorMessage(result.data)); return; }
            setText($('p-result'),
              '전체 ' + String(result.data.rows) + '행 중 새로 등록 ' + String(result.data.inserted)
              + ' · 갱신 ' + String(result.data.updated) + ' · 건너뜀 ' + String(result.data.skipped)
              + (result.data.excluded ? ' (그중 직접 뺀 행 ' + String(result.data.excluded) + ')' : '')
              + (result.data.brands_created || result.data.branches_created
                  ? ' · 함께 만든 브랜드 ' + String(result.data.brands_created || 0)
                    + ' · 지점 ' + String(result.data.branches_created || 0)
                  : '')
              // 9차 보정 — 품목 가져오기가 함께 만든 분류·거래처
              + (result.data.categories_created || result.data.vendors_created
                  ? ' · 함께 만든 분류 ' + String(result.data.categories_created || 0)
                    + ' · 거래처 ' + String(result.data.vendors_created || 0)
                  : ''));
            step(4);
            say('');
          })
          .catch(function () {
            if (commitBtn) { commitBtn.disabled = false; setText(commitBtn, '확정'); }
            say(UI.GENERIC_ERROR);
          });
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
        apiClient.setCsrf(me.csrf_token);
        if (isMasters) { initMasters(); }
        if (itemRoot) { initItem(itemRoot); }
        if (isImport) { initImport(); }
      })
      .catch(function () { say(UI.GENERIC_ERROR); });
  });
}());
