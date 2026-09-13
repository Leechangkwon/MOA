<?php
declare(strict_types=1);

/**
 * src/views/purchase_docs.php — 전표·사이클 추적 화면 (시안 ⑧ Docs · ⑨ Cycle)
 *
 * 요청·발주서를 한 표에서 보고, 요청 줄 하나가 **어느 발주서로 갔는지**를 사이클 표로 본다.
 * 값은 `purchase_docs.js` 가 `textContent` 로만 채운다 (PRD S-03).
 *
 * @var array<string,mixed> $user
 */

require __DIR__ . '/shell_top.php';
?>
    <link rel="stylesheet" href="/assets/purchase_docs.css?v=<?= (int) filemtime(__DIR__ . '/../../public/assets/purchase_docs.css') ?>">

    <header class="page-head">
      <div class="page-head__text">
        <h1 class="page-head__title">구매관리 › 전표</h1>
        <p class="page-head__sub">요청 → 발주 → 입고. 한 줄을 누르면 앞뒤로 연결된 전표가 함께 보입니다. (이동·사용·실사는 전표 목록에서)</p>
      </div>
      <div class="page-head__actions">
        <a class="btn btn--quiet" href="/purchase/pr">발주요청</a>
        <a class="btn btn--quiet" href="/purchase/po">발주서</a>
      </div>
    </header>

  <nav class="p-tabs" id="docs-tabs" aria-label="전표 화면">
    <button class="p-tab is-on" type="button" data-tab="all">전표</button>
    <button class="p-tab" type="button" data-tab="cycle">사이클 추적</button>
  </nav>

  <section class="panel" id="docs-all">
    <div class="panel__head">
      <h2 class="panel__title">전표 목록</h2>
      <div class="panel__tools">
        <label class="field field--inline">
          <span class="field__label">구분</span>
          <select id="docs-type">
            <option value="pr">발주요청</option>
            <option value="po">발주서</option>
          </select>
        </label>
        <label class="field field--inline">
          <span class="field__label">검색</span>
          <input id="docs-q" type="search" maxlength="100" autocomplete="off" spellcheck="false">
        </label>
        <label class="field field--inline">
          <span class="field__label">기간</span>
          <input id="docs-from" type="date">
        </label>
        <label class="field field--inline">
          <span class="field__label">~</span>
          <input id="docs-to" type="date">
        </label>
      </div>
    </div>

    <div class="table-wrap">
      <table class="files">
        <thead>
          <tr>
            <th>일자 · 번호</th><th>구분</th><th>거래처</th><th>장소</th>
            <th>담당자</th><th class="p-num">품목</th><th class="p-num">금액</th><th>상태</th>
          </tr>
        </thead>
        <tbody id="docs-body"></tbody>
      </table>
    </div>
    <p id="docs-empty" class="muted" hidden>표시할 전표가 없습니다.</p>

    <div class="table-foot">
      <span id="docs-page-info" class="muted"></span>
      <div class="pager">
        <button id="docs-prev" class="btn btn--quiet btn--small" type="button">이전</button>
        <button id="docs-next" class="btn btn--quiet btn--small" type="button">다음</button>
      </div>
    </div>
  </section>

  <section class="panel" id="docs-cycle" hidden>
    <div class="panel__head">
      <h2 class="panel__title">요청 → 발주 → 입고 사이클</h2>
      <div class="panel__tools">
        <label class="field field--inline">
          <span class="field__label">기간</span>
          <input id="cycle-from" type="date">
        </label>
        <label class="field field--inline">
          <span class="field__label">~</span>
          <input id="cycle-to" type="date">
        </label>
        <button id="cycle-run" class="btn btn--small" type="button">다시 보기</button>
      </div>
    </div>
    <div class="table-wrap">
      <table class="files">
        <thead>
          <tr>
            <th>발주요청</th><th>요청자 · 장소</th><th>품목</th>
            <th class="p-num">요청</th><th>발주서</th><th class="p-num">발주</th>
            <th class="p-num">미발주</th><th>입고</th><th class="p-num">미입고</th><th>상태</th>
          </tr>
        </thead>
        <tbody id="cycle-body"></tbody>
      </table>
    </div>
    <p id="cycle-info" class="muted"></p>
    <p class="muted">「입고」·「미입고」는 <strong>재고 원장의 합</strong>입니다(21단계부터) — 화면이 계산하지 않고
      서버가 준 값을 그대로 적습니다. 보낸 발주서인데 아직 안 온 것이 있으면 「미입고」 배지가 섭니다.</p>
  </section>

<script src="/assets/purchase_docs.js?v=<?= (int) filemtime(__DIR__ . '/../../public/assets/purchase_docs.js') ?>"></script>
<?php require __DIR__ . '/shell_bottom.php'; ?>
