<?php
declare(strict_types=1);

/**
 * src/views/purchase_recon.php — 거래처 월 대사 · 월 마감 (시안 Recon, PRD F-35)
 *
 * 값은 `purchase_recon.js` 가 `textContent` 로만 채운다 (PRD S-03).
 * 이 파일에는 동적 값이 하나도 없다 — 셸이 쓰는 `$user` 말고는 서버가 그릴 것이 없다.
 *
 * 마감 단추는 `POST /api/purchase/close`(정책 `purchase.close`, 재인증 대상)를 부른다.
 * 자격이 없으면 403 이고, 재인증 창이 닫혀 있으면 403 `next:reauth` 다 — **화면이 판단하지
 * 않는다** (PRD S-01).
 *
 * @var array<string,mixed> $user
 */

require __DIR__ . '/shell_top.php';
?>
    <link rel="stylesheet" href="/assets/purchase_recon.css?v=<?= (int) filemtime(__DIR__ . '/../../public/assets/purchase_recon.css') ?>">

    <header class="page-head">
      <div class="page-head__text">
        <h1 class="page-head__title">구매관리 › 현황 › 대사</h1>
        <p class="page-head__sub">우리 입고 기록과 거래처 명세서를 줄마다 맞춥니다. 마감하면 그 달 전표는 잠기고, 고칠 때는 사유와 기록이 남습니다.</p>
      </div>
      <div class="page-head__actions">
        <a class="btn btn--quiet" href="/purchase/usage">주간 사용량</a>
        <a class="btn btn--quiet" href="/purchase/stock">재고 현황</a>
      </div>
    </header>

  <p id="msg" class="form-msg" role="status" aria-live="polite"></p>

  <nav class="p-tabs" id="recon-tabs" aria-label="대사 화면">
    <button class="p-tab is-on" type="button" data-tab="recon">월 대사</button>
    <button class="p-tab" type="button" data-tab="close">월 마감</button>
    <button class="p-tab" type="button" data-tab="lab">기공 명세서</button>
  </nav>

  <!-- ── 월 대사 ────────────────────────────────────────── -->
  <section class="panel" id="recon-main">
    <div class="panel__head">
      <h2 class="panel__title">거래처 명세서</h2>
      <div class="panel__tools">
        <label class="field field--inline">
          <span class="field__label">거래처</span>
          <select id="rc-vendor"></select>
        </label>
        <label class="field field--inline">
          <span class="field__label">월</span>
          <input id="rc-month" type="month">
        </label>
        <label class="field field--inline">
          <span class="field__label">파일</span>
          <input id="rc-file" type="file" accept=".xlsx,.csv,.pdf">
        </label>
        <button id="rc-upload" class="btn btn--small" type="button">올리기</button>
        <button id="rc-reload" class="btn btn--quiet btn--small" type="button">목록 새로고침</button>
      </div>
    </div>

    <div class="table-wrap">
      <table class="files">
        <thead>
          <tr><th>거래처</th><th>월</th><th>파일</th><th class="p-num">줄</th><th class="p-num">차이</th>
              <th class="p-num">명세서 합계</th><th>올린 사람</th><th></th></tr>
        </thead>
        <tbody id="rc-list"></tbody>
      </table>
    </div>
    <p id="rc-empty" class="muted" hidden>올린 명세서가 없습니다.</p>

    <!-- 열 매핑 (확정 전) -->
    <div id="rc-map" hidden>
      <h3 class="panel__title">열 맞추기</h3>
      <p class="muted">명세서에서 읽는 열은 <strong>품목코드 · 품목명 · 수량 · 단가 · 금액</strong> 다섯뿐입니다 —
        담당자 연락처·계좌 열은 고를 수도, 저장될 수도 없습니다 (SECURITY R-69).</p>
      <div class="p-map" id="rc-map-fields"></div>
      <label class="field field--inline">
        <span class="field__label">머리행</span>
        <select id="rc-header-row"></select>
      </label>
      <div class="panel__tools">
        <button id="rc-preview" class="btn btn--small" type="button">맞춰 보기</button>
        <button id="rc-commit" class="btn btn--small" type="button">대사 확정</button>
      </div>
    </div>

    <!-- 대조 결과 -->
    <div id="rc-result" hidden>
      <div class="p-kpis" id="rc-kpis"></div>
      <label class="field field--inline">
        <input id="rc-only-diff" type="checkbox">
        <span class="field__label">차이만 보기</span>
      </label>
      <div class="table-wrap">
        <table class="files">
          <thead>
            <tr><th class="p-col-no">#</th><th>지점</th><th>품목</th>
                <th class="p-num">우리 수량</th><th class="p-num">우리 단가</th><th class="p-num">우리 금액</th>
                <th class="p-num">명세서 수량</th><th class="p-num">명세서 단가</th><th class="p-num">명세서 금액</th>
                <th class="p-num">차이</th><th>판정</th><th>비고</th></tr>
          </thead>
          <tbody id="rc-lines"></tbody>
        </table>
      </div>
      <p class="muted">「우리 금액」은 입고 확정 때 <strong>서버가 수량 × 단가로 계산해 둔 값</strong>입니다 —
        명세서 값이 우리 장부로 넘어오는 길은 없습니다 (SECURITY R-66).</p>
    </div>
  </section>

  <!-- ── 월 마감 ────────────────────────────────────────── -->
  <section class="panel" id="recon-close" hidden>
    <div class="panel__head">
      <h2 class="panel__title">월 마감</h2>
      <div class="panel__tools">
        <label class="field field--inline">
          <span class="field__label">월</span>
          <input id="cl-month" type="month">
        </label>
        <label class="field field--inline">
          <span class="field__label">지점</span>
          <select id="cl-branch"></select>
        </label>
        <label class="field field--inline">
          <span class="field__label">사유(선택)</span>
          <input id="cl-reason" type="text" maxlength="300" autocomplete="off">
        </label>
        <button id="cl-run" class="btn btn--small" type="button">이 달 마감</button>
      </div>
    </div>

    <div class="table-wrap">
      <table class="files">
        <thead><tr><th>월</th><th>범위</th><th>마감한 사람</th><th>마감 시각</th><th>사유</th></tr></thead>
        <tbody id="cl-list"></tbody>
      </table>
    </div>
    <p id="cl-empty" class="muted" hidden>마감한 달이 없습니다.</p>
    <p class="muted">마감은 <strong>되돌릴 수 없습니다</strong>. 그 달에 아직 확정되지 않은 전표가 있으면 마감되지 않습니다.
      마감 뒤의 수정·취소는 마감 권한과 <strong>사유</strong>가 있어야 하고, 감사 기록에 남아 경보(AL-14)가 봅니다.
      마감 단추는 비밀번호 재확인(재인증)을 지나야 동작합니다.</p>
  </section>

  <!-- ── 기공 명세서 ─────────────────────────────────────── -->
  <section class="panel" id="recon-lab" hidden>
    <div class="panel__head">
      <h2 class="panel__title">기공 명세서 → 입고 전표</h2>
      <div class="panel__tools">
        <label class="field field--inline">
          <span class="field__label">거래처</span>
          <select id="lb-vendor"></select>
        </label>
        <label class="field field--inline">
          <span class="field__label">장소</span>
          <select id="lb-location"></select>
        </label>
        <label class="field field--inline">
          <span class="field__label">날짜</span>
          <input id="lb-date" type="date">
        </label>
        <label class="field field--inline">
          <span class="field__label">품목 검색</span>
          <input id="lb-q" type="search" maxlength="100" autocomplete="off" spellcheck="false">
        </label>
        <button id="lb-search" class="btn btn--quiet btn--small" type="button">찾기</button>
      </div>
    </div>

    <div class="table-wrap">
      <table class="files">
        <thead><tr><th>품목</th><th>재고관리</th><th class="p-num">수량</th><th>비고</th><th></th></tr></thead>
        <tbody id="lb-body"></tbody>
      </table>
    </div>
    <p id="lb-empty" class="muted" hidden>담은 품목이 없습니다 — 위에서 품목을 찾아 담으세요.</p>
    <div class="panel__tools">
      <button id="lb-save" class="btn btn--small" type="button">입고 전표 만들기</button>
    </div>
    <p class="muted"><strong>재고관리를 하지 않는 품목만</strong> 담을 수 있습니다(기공료 등).
      만들어지는 입고 전표는 원장에 <code>+</code>·<code>−</code> 두 행을 남겨 현재고는 그대로이고,
      사용 통계와 월 대사에는 잡힙니다. 단가·금액은 서버가 품목 단가에서 계산합니다.</p>
  </section>

<script src="/assets/purchase_recon.js?v=<?= (int) filemtime(__DIR__ . '/../../public/assets/purchase_recon.js') ?>"></script>
<?php require __DIR__ . '/shell_bottom.php'; ?>
