<?php
declare(strict_types=1);

/**
 * src/views/purchase_stock.php — 현황: 장소별 재고 · 수불부 · 미입고 (시안 ⑩ Status · ⑪ Backorder)
 *
 * 값은 `purchase_stock.js` 가 `textContent` 로만 채운다 (PRD S-03).
 * 이 파일에는 동적 값이 하나도 없다 — 셸이 쓰는 `$user` 말고는 서버가 그릴 것이 없다.
 *
 * ★ 25단계 — 수불부 탭에서 **「품목 id」 숫자 입력 칸을 없앴다**. DB 내부 id 를 사람이
 *   타이핑하게 하는 칸은 화면이 아니라 개발 잔재다. 이제 장소별 재고 표의 **현재고 숫자를
 *   누르면** 그 품목·그 장소의 수불부가 열리고, 무엇을 보고 있는지는 탭 머리에 적힌다.
 *
 * @var array<string,mixed> $user
 */

require __DIR__ . '/shell_top.php';
?>
    <header class="page-head">
      <div class="page-head__text">
        <nav class="crumbs muted" aria-label="위치">구매관리 › 현황 › 재고</nav>
        <h1 class="page-head__title">재고 · 미입고</h1>
        <p class="page-head__sub">현재고는 전표를 더하고 뺀 값입니다 — 수불부에서 왜 그 값인지 되짚을 수 있습니다.</p>
      </div>
      <div class="page-head__actions">
        <button type="button" class="btn btn--quiet" data-help-open>
          <span class="icon" data-icon="help"></span><span>사용 방법</span>
        </button>
        <button id="st-apply" class="btn" type="button">선택 제안을 발주요청으로</button>
      </div>
    </header>

  <p id="msg" class="msg" role="alert" aria-live="polite"></p>

  <nav class="tabs" id="stock-tabs" role="tablist" aria-label="현황 화면">
    <button class="tab is-on" type="button" role="tab" aria-selected="true" data-tab="status">장소별 재고</button>
    <button class="tab" type="button" role="tab" aria-selected="false" data-tab="ledger">수불부</button>
    <button class="tab" type="button" role="tab" aria-selected="false" data-tab="backorder">미입고</button>
  </nav>

  <!-- ── 장소별 재고 ────────────────────────────────────── -->
  <section class="panel" id="stock-status">
    <div class="panel__head panel__head--row">
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
        <label class="check">
          <input id="st-short" type="checkbox">
          <span>부족만</span>
        </label>
        <label class="check">
          <input id="st-suggest-only" type="checkbox">
          <span>제안 있는 것만</span>
        </label>
        <button id="st-reload" class="btn btn--quiet" type="button">다시 보기</button>
      </div>
    </div>

    <div class="p-kpis" id="st-kpis"></div>

    <div class="table-wrap">
      <table class="files">
        <thead>
          <tr>
            <th scope="col">품목</th><th scope="col">장소</th>
            <th scope="col" class="num">현재고</th><th scope="col" class="num">인가량</th><th scope="col" class="num">과부족</th>
            <th scope="col" class="num">미입고</th><th scope="col" class="num">주평균 사용</th>
            <th scope="col" class="num">발주 제안</th><th scope="col">담기</th>
            <th scope="col"><span class="sr-only">수불부</span></th>
          </tr>
        </thead>
        <tbody id="st-body"></tbody>
      </table>
    </div>
    <p id="st-empty" class="empty" hidden>표시할 재고가 없습니다.</p>

    <div class="table-foot">
      <span id="st-page-info" class="muted"></span>
      <span id="st-pick-info" class="muted"></span>
      <div class="pager">
        <button id="st-prev" class="btn btn--quiet btn--small" type="button">이전</button>
        <button id="st-next" class="btn btn--quiet btn--small" type="button">다음</button>
      </div>
    </div>
    <p class="help">발주 제안은 인가량·안전재고·현재고·미입고·조달기간으로 계산한 값입니다. 수량은 최소구매단위의 배수로 고칠 수 있고, 「담기」는 장소별로 발주요청 한 건을 만듭니다.</p>
  </section>

  <!-- ── 수불부 ─────────────────────────────────────────── -->
  <section class="panel" id="stock-ledger" hidden>
    <div class="panel__head panel__head--row">
      <h2 class="panel__title">수불부 — 재고 원장</h2>
      <div class="panel__tools">
        <label class="field field--inline">
          <span class="field__label">장소</span>
          <select id="lg-location"></select>
        </label>
        <label class="field field--inline">
          <span class="field__label">기간</span>
          <input id="lg-from" type="date">
        </label>
        <label class="field field--inline">
          <span class="field__label">~</span>
          <input id="lg-to" type="date">
        </label>
        <button id="lg-run" class="btn btn--quiet" type="button">다시 보기</button>
      </div>
    </div>

    <!-- 고른 품목. 값(품목 id)은 재고 표에서 누른 줄이 정한다 — 사람이 숫자를 치지 않는다. -->
    <p class="p-chipbar" id="lg-item-box" hidden>
      <span class="chip"><span id="lg-item-name"></span></span>
      <button id="lg-item-clear" class="btn btn--quiet btn--small" type="button">품목 조건 지우기</button>
      <input id="lg-item" type="hidden">
    </p>

    <div class="table-wrap">
      <table class="files">
        <thead>
          <tr>
            <th scope="col">일시</th><th scope="col">전표</th><th scope="col">품목</th><th scope="col">장소</th>
            <th scope="col" class="num">증감</th><th scope="col" class="num">잔액</th><th scope="col">로트 · 유효기간</th><th scope="col">담당</th>
          </tr>
        </thead>
        <tbody id="lg-body"></tbody>
      </table>
    </div>
    <div class="table-foot">
      <span id="lg-page-info" class="muted"></span>
      <span id="lg-info" class="muted"></span>
      <div class="pager">
        <button id="lg-prev" class="btn btn--quiet btn--small" type="button">이전</button>
        <button id="lg-next" class="btn btn--quiet btn--small" type="button">다음</button>
      </div>
    </div>
  </section>

  <!-- ── 미입고 ─────────────────────────────────────────── -->
  <section class="panel" id="stock-backorder" hidden>
    <div class="panel__head panel__head--row">
      <h2 class="panel__title">미입고 현황</h2>
      <div class="panel__tools">
        <label class="field field--inline">
          <span class="field__label">거래처</span>
          <select id="bo-vendor"></select>
        </label>
        <label class="field field--inline">
          <span class="field__label">검색</span>
          <input id="bo-q" type="search" maxlength="100" autocomplete="off" spellcheck="false">
        </label>
        <label class="check">
          <input id="bo-overdue" type="checkbox">
          <span>납기 지남만</span>
        </label>
        <button id="bo-run" class="btn btn--quiet" type="button">다시 보기</button>
      </div>
    </div>

    <div class="p-kpis" id="bo-kpis"></div>
    <div id="bo-groups"></div>
    <p id="bo-empty" class="empty" hidden>미입고가 없습니다.</p>
    <p class="help">미입고 = 발주 수량 − 입고 수량.</p>
  </section>

<script src="/assets/purchase_common.js?v=<?= (int) filemtime(__DIR__ . '/../../public/assets/purchase_common.js') ?>"></script>
<script src="/assets/purchase_stock.js?v=<?= (int) filemtime(__DIR__ . '/../../public/assets/purchase_stock.js') ?>"></script>
<?php require __DIR__ . '/shell_bottom.php'; ?>
