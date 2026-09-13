<?php
declare(strict_types=1);

/**
 * src/views/purchase_stock.php — 현황: 장소별 재고 · 수불부 · 미입고 (시안 ⑩ Status · ⑪ Backorder)
 *
 * 값은 `purchase_stock.js` 가 `textContent` 로만 채운다 (PRD S-03).
 * 이 파일에는 동적 값이 하나도 없다 — 셸이 쓰는 `$user` 말고는 서버가 그릴 것이 없다.
 *
 * @var array<string,mixed> $user
 */

require __DIR__ . '/shell_top.php';
?>
    <link rel="stylesheet" href="/assets/purchase_stock.css?v=<?= (int) filemtime(__DIR__ . '/../../public/assets/purchase_stock.css') ?>">

    <header class="page-head">
      <div class="page-head__text">
        <h1 class="page-head__title">구매관리 › 현황</h1>
        <p class="page-head__sub">현재고는 전표를 더하고 뺀 값입니다 — 수불부에서 왜 그 값인지 되짚을 수 있습니다.</p>
      </div>
      <div class="page-head__actions">
        <a class="btn btn--quiet" href="/purchase/receipt">구매(입고)</a>
        <a class="btn btn--quiet" href="/purchase/move">이동 · 사용 · 실사</a>
      </div>
    </header>

  <p id="msg" class="form-msg" role="status" aria-live="polite"></p>

  <nav class="p-tabs" id="stock-tabs" aria-label="현황 화면">
    <button class="p-tab is-on" type="button" data-tab="status">장소별 재고</button>
    <button class="p-tab" type="button" data-tab="ledger">수불부</button>
    <button class="p-tab" type="button" data-tab="backorder">미입고</button>
  </nav>

  <!-- ── 장소별 재고 ────────────────────────────────────── -->
  <section class="panel" id="stock-status">
    <div class="panel__head">
      <h2 class="panel__title">장소별 재고 · 발주 잔량</h2>
      <div class="panel__tools">
        <label class="field field--inline">
          <span class="field__label">지점</span>
          <select id="st-branch"></select>
        </label>
        <label class="field field--inline">
          <span class="field__label">장소</span>
          <select id="st-location"></select>
        </label>
        <label class="field field--inline">
          <span class="field__label">품목</span>
          <input id="st-q" type="search" maxlength="100" autocomplete="off" spellcheck="false">
        </label>
        <label class="field field--inline">
          <input id="st-short" type="checkbox">
          <span class="field__label">부족만</span>
        </label>
      </div>
    </div>

    <div class="p-kpis" id="st-kpis"></div>

    <div class="table-wrap">
      <table class="files">
        <thead>
          <tr>
            <th>품목</th><th>장소</th>
            <th class="p-num">현재고</th><th class="p-num">인가량</th><th class="p-num">과부족</th>
            <th class="p-num">미입고</th><th class="p-num">주평균 사용</th><th>발주 제안</th><th></th>
          </tr>
        </thead>
        <tbody id="st-body"></tbody>
      </table>
    </div>
    <p id="st-empty" class="muted" hidden>표시할 재고가 없습니다.</p>
    <p class="muted">발주 제안은 <strong>23단계</strong>에 채워집니다 — 값을 지어내지 않습니다.</p>

    <div class="table-foot">
      <span id="st-page-info" class="muted"></span>
      <div class="pager">
        <button id="st-prev" class="btn btn--quiet btn--small" type="button">이전</button>
        <button id="st-next" class="btn btn--quiet btn--small" type="button">다음</button>
      </div>
    </div>
  </section>

  <!-- ── 수불부 ─────────────────────────────────────────── -->
  <section class="panel" id="stock-ledger" hidden>
    <div class="panel__head">
      <h2 class="panel__title">수불부 — 재고 원장</h2>
      <div class="panel__tools">
        <label class="field field--inline">
          <span class="field__label">장소</span>
          <select id="lg-location"></select>
        </label>
        <label class="field field--inline">
          <span class="field__label">품목 id</span>
          <input id="lg-item" type="number" min="1" step="1" inputmode="numeric">
        </label>
        <label class="field field--inline">
          <span class="field__label">기간</span>
          <input id="lg-from" type="date">
        </label>
        <label class="field field--inline">
          <span class="field__label">~</span>
          <input id="lg-to" type="date">
        </label>
        <button id="lg-run" class="btn btn--small" type="button">다시 보기</button>
      </div>
    </div>

    <div class="table-wrap">
      <table class="files">
        <thead>
          <tr>
            <th>일시</th><th>전표</th><th>품목</th><th>장소</th>
            <th class="p-num">증감</th><th class="p-num">잔액</th><th>로트 · 유효기간</th><th>담당</th>
          </tr>
        </thead>
        <tbody id="lg-body"></tbody>
      </table>
    </div>
    <p id="lg-info" class="muted"></p>
    <div class="table-foot">
      <span id="lg-page-info" class="muted"></span>
      <div class="pager">
        <button id="lg-prev" class="btn btn--quiet btn--small" type="button">이전</button>
        <button id="lg-next" class="btn btn--quiet btn--small" type="button">다음</button>
      </div>
    </div>
  </section>

  <!-- ── 미입고 ─────────────────────────────────────────── -->
  <section class="panel" id="stock-backorder" hidden>
    <div class="panel__head">
      <h2 class="panel__title">미입고 현황</h2>
      <div class="panel__tools">
        <label class="field field--inline">
          <span class="field__label">거래처</span>
          <select id="bo-vendor"></select>
        </label>
        <label class="field field--inline">
          <input id="bo-overdue" type="checkbox">
          <span class="field__label">납기 지남만</span>
        </label>
        <label class="field field--inline">
          <span class="field__label">검색</span>
          <input id="bo-q" type="search" maxlength="100" autocomplete="off" spellcheck="false">
        </label>
        <button id="bo-run" class="btn btn--small" type="button">다시 보기</button>
      </div>
    </div>

    <div class="p-kpis" id="bo-kpis"></div>
    <div id="bo-groups"></div>
    <p id="bo-empty" class="muted" hidden>미입고가 없습니다.</p>
    <p class="muted">미입고 = 보낸 발주서 줄의 수량 − 그 줄을 출처로 하는 입고 줄 수량의 합입니다.
      발주서를 취소하면 여기서 사라지고 사유가 감사 기록에 남습니다.</p>
  </section>

<script src="/assets/purchase_stock.js?v=<?= (int) filemtime(__DIR__ . '/../../public/assets/purchase_stock.js') ?>"></script>
<?php require __DIR__ . '/shell_bottom.php'; ?>
