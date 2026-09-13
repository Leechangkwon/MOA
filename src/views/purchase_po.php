<?php
declare(strict_types=1);

/**
 * src/views/purchase_po.php — 발주서 화면의 마크업 (시안 ④ ImportModal · ⑤ PO)
 *
 * 틀만 놓는다. 불러오기 트리·발주서 줄·머리 수정은 `purchase_docs.js` 가
 * `textContent` 로만 그린다 (PRD S-03). 인라인 스크립트·on* 속성은 쓰지 않는다.
 *
 * @var array<string,mixed> $user
 */

require __DIR__ . '/shell_top.php';
?>
    <link rel="stylesheet" href="/assets/purchase_docs.css?v=<?= (int) filemtime(__DIR__ . '/../../public/assets/purchase_docs.css') ?>">

    <header class="page-head">
      <div class="page-head__text">
        <h1 class="page-head__title">구매관리 › 발주서</h1>
        <p class="page-head__sub">거래처 하나에 보내는 문서입니다. 줄마다 <strong>어느 발주요청에서 왔는지</strong>가 붙고, 저장하면 그 요청의 잔량이 줄어듭니다.</p>
      </div>
      <div class="page-head__actions">
        <a class="btn btn--quiet" href="/purchase/pr">발주요청</a>
        <a class="btn btn--quiet" href="/purchase/docs">전표·사이클</a>
        <a class="btn btn--quiet" href="/purchase/masters">기초정보</a>
      </div>
    </header>

  <p class="notice">
    발주서는 <strong>발주요청의 미발주 잔량</strong>에서 만듭니다. 같은 줄을 두 번 담으려 하거나
    잔량보다 많이 담으려 하면 서버가 거부합니다 — 두 사람이 동시에 담아도 한쪽만 통과합니다.
    「보냄」은 <strong>검토(viewer)·구매(buyer)</strong> 만 누를 수 있고, 한 번 보낸 뒤 고치려면 사유가 필요합니다.
  </p>

  <nav class="p-tabs" id="po-tabs" aria-label="발주서 화면">
    <button class="p-tab is-on" type="button" data-tab="input">입력</button>
    <button class="p-tab" type="button" data-tab="docs">전표</button>
  </nav>

  <!-- ── 입력 탭 ───────────────────────────────────────────── -->
  <section class="panel" id="po-input">
    <div class="panel__head">
      <h2 class="panel__title">새 발주서</h2>
      <div class="panel__tools">
        <button id="po-load" class="btn btn--small" type="button">발주요청 불러오기</button>
      </div>
    </div>

    <form id="po-form" class="form form--grid" autocomplete="off">
      <div class="form__fields">
        <label class="field">
          <span class="field__label">일자</span>
          <input id="po-date" type="date" required>
        </label>
        <label class="field">
          <span class="field__label">입고 장소</span>
          <select id="po-location"></select>
        </label>
        <label class="field">
          <span class="field__label">거래처</span>
          <select id="po-vendor"></select>
        </label>
        <label class="field field--wide">
          <span class="field__label">참조 · 비고</span>
          <input id="po-memo" type="text" maxlength="500">
        </label>
      </div>

      <div class="table-wrap">
        <table class="files p-lines">
          <thead>
            <tr>
              <th class="p-col-no">#</th>
              <th>코드</th>
              <th>품목명 · 규격</th>
              <th>출처 요청</th>
              <th class="p-num">잔량</th>
              <th class="p-num">담을 수량</th>
              <th class="p-num">단가</th>
              <th class="p-num">공급가액</th>
              <th></th>
            </tr>
          </thead>
          <tbody id="po-line-body"></tbody>
        </table>
      </div>
      <p id="po-line-empty" class="muted">「발주요청 불러오기」로 줄을 담습니다.</p>

      <div class="p-foot">
        <span id="po-sum" class="muted"></span>
        <button id="po-save" class="btn" type="submit">발주서 만들기</button>
      </div>
    </form>
  </section>

  <!-- ── 전표 탭 ───────────────────────────────────────────── -->
  <section class="panel" id="po-docs" hidden>
    <div class="panel__head">
      <h2 class="panel__title">발주서 전표</h2>
      <div class="panel__tools">
        <label class="field field--inline">
          <span class="field__label">검색</span>
          <input id="po-q" type="search" maxlength="100" autocomplete="off" spellcheck="false">
        </label>
        <label class="field field--inline">
          <span class="field__label">보냄</span>
          <select id="po-sent">
            <option value="">전체</option>
            <option value="0">아직 안 보냄</option>
            <option value="1">보냄</option>
          </select>
        </label>
      </div>
    </div>

    <div class="table-wrap">
      <table class="files">
        <thead>
          <tr>
            <th>일자 · 번호</th><th>거래처</th><th>장소</th><th>담당자</th>
            <th class="p-num">품목</th><th class="p-num">금액</th><th>상태</th><th>보냄</th>
          </tr>
        </thead>
        <tbody id="po-doc-body"></tbody>
      </table>
    </div>
    <p id="po-doc-empty" class="muted" hidden>표시할 발주서가 없습니다.</p>

    <div class="table-foot">
      <span id="po-page-info" class="muted"></span>
      <div class="pager">
        <button id="po-prev" class="btn btn--quiet btn--small" type="button">이전</button>
        <button id="po-next" class="btn btn--quiet btn--small" type="button">다음</button>
      </div>
    </div>
  </section>

  <!-- 발주서 상세 · 머리 수정 · 보냄 -->
  <section class="panel" id="po-detail" hidden>
    <div class="panel__head">
      <h2 class="panel__title" id="po-detail-title">발주서</h2>
      <div class="panel__tools">
        <a id="po-print" class="btn btn--quiet btn--small" href="/purchase/po" target="_blank" rel="noopener">인쇄 · PDF</a>
        <button id="po-send" class="btn btn--small" type="button">보냄</button>
        <button id="po-void" class="btn btn--danger btn--small" type="button">발주 취소</button>
        <button id="po-detail-close" class="btn btn--quiet btn--small" type="button">닫기</button>
      </div>
    </div>
    <p id="po-detail-sub" class="muted"></p>

    <form id="po-head-form" class="form form--grid" autocomplete="off">
      <div class="form__fields">
        <label class="field"><span class="field__label">수신</span><input id="poh-receiver" type="text" maxlength="200"></label>
        <label class="field"><span class="field__label">참조</span><input id="poh-cc" type="text" maxlength="200"></label>
        <label class="field"><span class="field__label">TEL</span><input id="poh-tel" type="text" maxlength="200"></label>
        <label class="field"><span class="field__label">납품창고</span><input id="poh-delivery" type="text" maxlength="200"></label>
        <label class="field"><span class="field__label">납기일자</span><input id="poh-due" type="date"></label>
        <label class="field field--wide"><span class="field__label">비고</span><input id="poh-memo" type="text" maxlength="200"></label>
        <label class="field field--wide" id="poh-reason-field" hidden>
          <span class="field__label">수정 사유 (보낸 뒤에는 필수)</span>
          <input id="poh-reason" type="text" maxlength="300">
        </label>
      </div>
      <div class="p-foot">
        <span class="muted">금액 세 칸은 저장하지 않습니다 — 볼 때마다 서버가 줄에서 다시 계산합니다.</span>
        <button id="poh-save" class="btn btn--quiet" type="submit">머리 수정 저장</button>
      </div>
    </form>

    <div class="table-wrap">
      <table class="files">
        <thead>
          <tr>
            <th class="p-col-no">#</th><th>코드</th><th>품목명</th><th>출처 요청</th>
            <th class="p-num">수량</th><th class="p-num">단가</th><th class="p-num">공급가액</th>
          </tr>
        </thead>
        <tbody id="po-detail-body"></tbody>
      </table>
    </div>
    <p id="po-detail-totals" class="p-totals"></p>
  </section>

  <!-- ── 불러오기 모달 (시안 ④ ImportModal) ──────────────────── -->
  <dialog class="modal modal--wide" id="load-modal">
    <form method="dialog" class="modal__form">
      <h2 class="modal__title">발주요청 불러오기</h2>
      <div class="modal__body">
        <p class="muted">묶는 기준을 <strong>누른 순서</strong>가 트리의 층이 됩니다. 미발주 잔량이 남은 줄만 나옵니다.</p>
        <div class="p-pick-tools" id="load-groups">
          <button class="chip" type="button" data-group="vendor">거래처</button>
          <button class="chip" type="button" data-group="location">장소</button>
          <button class="chip" type="button" data-group="manager">담당자</button>
          <button class="chip" type="button" data-group="branch">지점</button>
          <button class="chip" type="button" data-group="category">분류</button>
          <span id="load-order" class="muted"></span>
        </div>
        <div class="p-pick-tools">
          <label class="field field--inline">
            <span class="field__label">검색</span>
            <input id="load-q" type="search" maxlength="100" autocomplete="off" spellcheck="false">
          </label>
          <label class="field field--inline">
            <span class="field__label">기간</span>
            <input id="load-from" type="date">
          </label>
          <label class="field field--inline">
            <span class="field__label">~</span>
            <input id="load-to" type="date">
          </label>
          <button id="load-run" class="btn btn--small" type="button">불러오기</button>
        </div>
        <div class="p-tree-wrap" id="load-tree"></div>
        <p id="load-info" class="muted"></p>
      </div>
      <div class="modal__actions">
        <button class="btn btn--quiet" type="submit" value="cancel">닫기</button>
        <button id="load-add" class="btn" type="button">발주서에 담기</button>
      </div>
    </form>
  </dialog>

<script src="/assets/purchase_docs.js?v=<?= (int) filemtime(__DIR__ . '/../../public/assets/purchase_docs.js') ?>"></script>
<?php require __DIR__ . '/shell_bottom.php'; ?>
