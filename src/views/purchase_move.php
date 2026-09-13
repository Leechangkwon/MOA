<?php
declare(strict_types=1);

/**
 * src/views/purchase_move.php — 이동 · 사용 · 실사 (시안 ⑦ MoveUse, PRD F-33 · Q-18③)
 *
 * 탭 셋이 한 화면에 있고, 값은 `purchase_stock.js` 가 `textContent` 로만 채운다 (PRD S-03).
 * 이 파일에는 동적 값이 없다.
 *
 * @var array<string,mixed> $user
 */

require __DIR__ . '/shell_top.php';
?>
    <link rel="stylesheet" href="/assets/purchase_stock.css?v=<?= (int) filemtime(__DIR__ . '/../../public/assets/purchase_stock.css') ?>">

    <header class="page-head">
      <div class="page-head__text">
        <h1 class="page-head__title">구매관리 › 이동 · 사용 · 실사</h1>
        <p class="page-head__sub">창고 → 중앙장 → 수술방으로 옮기는 「이동」, 수술방에서 쓴 「사용」,
          실제 세어 맞추는 「실사」. 셋 다 재고 원장에 한 줄씩 남습니다.</p>
      </div>
      <div class="page-head__actions">
        <a class="btn btn--quiet" href="/purchase/stock">현황</a>
        <a class="btn btn--quiet" href="/purchase/receipt">구매(입고)</a>
      </div>
    </header>

  <p id="msg" class="form-msg" role="status" aria-live="polite"></p>

  <nav class="p-tabs" id="move-tabs" aria-label="이동·사용·실사">
    <button class="p-tab is-on" type="button" data-tab="mv">이동</button>
    <button class="p-tab" type="button" data-tab="is">사용</button>
    <button class="p-tab" type="button" data-tab="adj">실사</button>
  </nav>

  <!-- ── 이동 ───────────────────────────────────────────── -->
  <section class="panel" id="move-mv">
    <div class="panel__head">
      <h2 class="panel__title">이동</h2>
      <div class="panel__tools">
        <label class="field field--inline">
          <span class="field__label">일자</span>
          <input id="mv-date" type="date">
        </label>
        <label class="field field--inline">
          <span class="field__label">보내는 장소</span>
          <select id="mv-from"></select>
        </label>
        <label class="field field--inline">
          <span class="field__label">받는 장소</span>
          <select id="mv-to"></select>
        </label>
        <button id="mv-suggest" class="btn btn--quiet btn--small" type="button">중앙장 부족분 자동 채우기</button>
      </div>
    </div>

    <div class="table-wrap">
      <table class="files">
        <thead>
          <tr>
            <th class="p-col-no">#</th><th>품목</th>
            <th class="p-num">보내는 곳 재고</th><th class="p-num">받는 곳 재고 / 인가</th>
            <th class="p-num">이동 수량</th><th class="p-num">이동 후 (보냄 / 받음)</th>
            <th>비고</th><th></th>
          </tr>
        </thead>
        <tbody id="mv-body"></tbody>
      </table>
    </div>
    <p id="mv-empty" class="muted">「자동 채우기」를 누르면 받는 장소의 인가량 − 현재고가 줄로 들어옵니다 (수량은 고칠 수 있습니다).</p>
    <div class="p-formfoot">
      <span id="mv-sum" class="muted"></span>
      <button id="mv-save" class="btn" type="button">이동 확정</button>
    </div>
    <p class="muted">음수가 되는 수량은 <strong>저장되지 않습니다</strong> — 서버가 장소를 잠그고 합을 다시 세어 막습니다.</p>
  </section>

  <!-- ── 사용 ───────────────────────────────────────────── -->
  <section class="panel" id="move-is" hidden>
    <div class="panel__head">
      <h2 class="panel__title">사용 (출고)</h2>
      <div class="panel__tools">
        <label class="field field--inline">
          <span class="field__label">일자</span>
          <input id="is-date" type="date">
        </label>
        <label class="field field--inline">
          <span class="field__label">쓴 장소</span>
          <select id="is-from"></select>
        </label>
        <button id="is-load" class="btn btn--quiet btn--small" type="button">그 장소 재고 불러오기</button>
      </div>
    </div>

    <div class="table-wrap">
      <table class="files">
        <thead>
          <tr>
            <th class="p-col-no">#</th><th>품목</th><th class="p-num">현재고</th>
            <th class="p-num">사용 수량</th><th class="p-num">사용 후</th><th>비고</th><th></th>
          </tr>
        </thead>
        <tbody id="is-body"></tbody>
      </table>
    </div>
    <p id="is-empty" class="muted">장소를 고르고 「재고 불러오기」를 누르세요.</p>
    <div class="p-formfoot">
      <span id="is-sum" class="muted"></span>
      <button id="is-save" class="btn" type="button">사용 확정</button>
    </div>
  </section>

  <!-- ── 실사 ───────────────────────────────────────────── -->
  <section class="panel" id="move-adj" hidden>
    <div class="panel__head">
      <h2 class="panel__title">실사</h2>
      <div class="panel__tools">
        <label class="field field--inline">
          <span class="field__label">일자</span>
          <input id="adj-date" type="date">
        </label>
        <label class="field field--inline">
          <span class="field__label">장소</span>
          <select id="adj-location"></select>
        </label>
        <label class="field field--inline">
          <span class="field__label">주기</span>
          <select id="adj-cycle">
            <option value="daily">일 — 불출·부족재고 구매용</option>
            <option value="weekly">주 — 창고 재고 정합성</option>
            <option value="monthly">월 — 높은 수준의 정합성</option>
          </select>
        </label>
        <button id="adj-start" class="btn btn--quiet btn--small" type="button">실사 시작 (장부 불러오기)</button>
      </div>
    </div>

    <div class="table-wrap">
      <table class="files">
        <thead>
          <tr>
            <th class="p-col-no">#</th><th>품목</th><th class="p-num">장부</th>
            <th class="p-num">실제</th><th class="p-num">차이</th><th class="p-num">인가량</th><th>비고</th>
          </tr>
        </thead>
        <tbody id="adj-body"></tbody>
      </table>
    </div>
    <p id="adj-empty" class="muted">장소와 주기를 고르고 「실사 시작」을 누르세요.</p>
    <div class="p-formfoot">
      <span id="adj-sum" class="muted"></span>
      <button id="adj-save" class="btn" type="button">실사 확정</button>
    </div>
    <p class="muted">차이가 <strong>0 이 아닌 줄만</strong> 원장에 한 행으로 남습니다.
      장부를 불러온 뒤 다른 전표가 재고를 바꿨다면 저장이 거부됩니다(다시 불러오세요).</p>
  </section>

  <section class="panel" id="mv-result" hidden>
    <div class="panel__head">
      <h2 class="panel__title">저장한 전표</h2>
      <div class="panel__tools">
        <button id="mv-void" class="btn btn--quiet btn--small" type="button">이 전표 취소</button>
      </div>
    </div>
    <p id="mv-result-head" class="muted"></p>
    <div class="table-wrap">
      <table class="files">
        <thead>
          <tr><th class="p-col-no">#</th><th>품목</th><th class="p-num">수량</th>
              <th class="p-num">실사 실제</th><th>상태</th></tr>
        </thead>
        <tbody id="mv-result-body"></tbody>
      </table>
    </div>
  </section>

<script src="/assets/purchase_stock.js?v=<?= (int) filemtime(__DIR__ . '/../../public/assets/purchase_stock.js') ?>"></script>
<?php require __DIR__ . '/shell_bottom.php'; ?>
