<?php
declare(strict_types=1);

/**
 * src/views/purchase_docs.php — 전표·사이클 추적 화면 (시안 ⑧ Docs · ⑨ Cycle)
 *
 * 요청·발주서를 한 표에서 보고, 요청 줄 하나가 **어느 발주서·입고로 갔는지**를 본다.
 * 값은 `purchase_docs.js` 가 `textContent` 로만 채운다 (PRD S-03).
 *
 * ★ 25단계 — 시안 ⑧ 대로 **좌 표 / 우 「연결 전표」** 2단으로 되돌렸다.
 *   aside 가 쓰는 자료는 기존 `GET /api/purchase/cycle?pr=…|po=…` 하나뿐이다 (새 API 없음).
 *   사이클 표는 발주요청 화면의 「현황」 탭과 **같은 함수**가 그린다(열이 갈라지지 않게).
 *
 * @var array<string,mixed> $user
 */

require __DIR__ . '/shell_top.php';
?>
    <header class="page-head">
      <div class="page-head__text">
        <nav class="crumbs muted" aria-label="위치">구매관리 › 전표</nav>
        <h1 class="page-head__title">전표 · 사이클</h1>
        <p class="page-head__sub">요청 → 발주 → 입고. 한 줄을 누르면 오른쪽에 앞뒤로 연결된 전표가 보입니다.</p>
      </div>
    </header>

  <p id="msg" class="msg" role="alert" aria-live="polite"></p>

  <nav class="tabs" id="docs-tabs" role="tablist" aria-label="전표 화면">
    <button class="tab is-on" type="button" role="tab" aria-selected="true" data-tab="all">전표</button>
    <button class="tab" type="button" role="tab" aria-selected="false" data-tab="cycle">사이클 추적</button>
  </nav>

  <div class="split split--aside" id="docs-all">
    <section class="panel">
      <div class="panel__head panel__head--row">
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
          <!-- 기간 두 칸은 한 묶음(.field--range) — 줄이 접힐 때 함께 움직인다(검토 2026-09-14) -->
          <div class="field field--inline field--range" role="group" aria-label="기간">
            <span class="field__label">기간</span>
            <input id="docs-from" type="date" aria-label="기간 시작">
            <span class="muted">~</span>
            <input id="docs-to" type="date" aria-label="기간 끝">
          </div>
        </div>
      </div>

      <div class="table-wrap">
        <table class="files">
          <thead>
            <tr>
              <th scope="col">일자 · 번호</th><th scope="col">구분</th><th scope="col">거래처</th><th scope="col">장소</th>
              <th scope="col">담당자</th><th scope="col" class="num">품목</th><th scope="col" class="num">금액</th><th scope="col">상태</th>
            </tr>
          </thead>
          <tbody id="docs-body"></tbody>
        </table>
      </div>
      <p id="docs-empty" class="empty" hidden>표시할 전표가 없습니다.</p>

      <div class="table-foot">
        <span id="docs-page-info" class="muted"></span>
        <div class="pager">
          <button id="docs-prev" class="btn btn--quiet btn--small" type="button">이전</button>
          <button id="docs-next" class="btn btn--quiet btn--small" type="button">다음</button>
        </div>
      </div>
    </section>

    <!-- 연결 전표 — 고른 전표 한 건의 앞뒤 사슬 (요청 → 발주 → 입고) -->
    <aside class="panel" id="docs-flow-panel" aria-label="연결 전표">
      <div class="panel__head panel__head--row">
        <h2 class="panel__title">연결 전표</h2>
      </div>
      <ol class="p-flow" id="docs-flow"></ol>
      <p id="docs-flow-empty" class="empty">왼쪽에서 전표 한 줄을 누르세요.</p>
      <div class="table-wrap" id="docs-flow-items-wrap" hidden>
        <table class="files">
          <thead>
            <tr><th scope="col">품목</th><th scope="col" class="num">요청</th><th scope="col" class="num">발주</th><th scope="col" class="num">입고</th></tr>
          </thead>
          <tbody id="docs-flow-items"></tbody>
        </table>
      </div>
    </aside>
  </div>

  <section class="panel" id="docs-cycle" hidden>
    <div class="panel__head panel__head--row">
      <h2 class="panel__title">요청 → 발주 → 입고 사이클</h2>
      <div class="panel__tools">
        <!-- 기간 두 칸은 한 묶음(.field--range) — 줄이 접힐 때 함께 움직인다(검토 2026-09-14) -->
        <div class="field field--inline field--range" role="group" aria-label="기간">
          <span class="field__label">기간</span>
          <input id="cycle-from" type="date" aria-label="기간 시작">
          <span class="muted">~</span>
          <input id="cycle-to" type="date" aria-label="기간 끝">
        </div>
        <button id="cycle-run" class="btn btn--quiet" type="button">다시 보기</button>
      </div>
    </div>
    <div class="table-wrap">
      <table class="files">
        <thead><tr id="cycle-head"></tr></thead>
        <tbody id="cycle-body"></tbody>
      </table>
    </div>
    <div class="table-foot">
      <span id="cycle-info" class="muted"></span>
    </div>
    <p class="help">「입고」·「미입고」는 <strong>재고 원장의 합</strong>입니다 — 화면이 계산하지 않고 서버가 준 값을 그대로 적습니다.</p>
  </section>

<script src="/assets/purchase_common.js?v=<?= (int) filemtime(__DIR__ . '/../../public/assets/purchase_common.js') ?>"></script>
<script src="/assets/purchase_docs.js?v=<?= (int) filemtime(__DIR__ . '/../../public/assets/purchase_docs.js') ?>"></script>
<?php require __DIR__ . '/shell_bottom.php'; ?>
