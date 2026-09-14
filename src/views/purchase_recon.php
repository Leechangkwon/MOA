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
 * ★ 25단계 — 「열 맞추기」와 「대조 결과」를 **패널 두 장**으로 갈랐다(한 패널이 세 상태를
 *   오가지 않게). 파일 고르기는 브라우저 기본 단추 대신 `.filelabel` 이다.
 *
 * @var array<string,mixed> $user
 */

require __DIR__ . '/shell_top.php';
?>
    <header class="page-head">
      <div class="page-head__text">
        <nav class="crumbs muted" aria-label="위치">구매관리 › 현황 › 월 대사·마감</nav>
        <h1 class="page-head__title">월 대사 · 마감</h1>
        <p class="page-head__sub">우리 입고 기록과 거래처 명세서를 줄마다 맞춥니다. 마감하면 그 달 전표는 잠기고, 고칠 때는 사유와 기록이 남습니다.</p>
      </div>
      <div class="page-head__actions">
        <button type="button" class="btn btn--quiet" data-help-open>
          <span class="icon" data-icon="help"></span><span>사용 방법</span>
        </button>
      </div>
    </header>

  <p id="msg" class="msg" role="alert" aria-live="polite"></p>

  <nav class="tabs" id="recon-tabs" role="tablist" aria-label="대사 화면">
    <button class="tab is-on" type="button" role="tab" aria-selected="true" data-tab="recon">월 대사</button>
    <button class="tab" type="button" role="tab" aria-selected="false" data-tab="close">월 마감</button>
    <button class="tab" type="button" role="tab" aria-selected="false" data-tab="lab">기공 명세서</button>
  </nav>

  <!-- ── 월 대사 — 「명세서 목록 → 열 맞추기 → 대조 결과」 카드 세 장 ─── -->
  <div class="p-stack" id="recon-main">
    <section class="panel">
    <div class="panel__head panel__head--row">
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
        <label class="filelabel">
          <span class="btn btn--quiet">파일 고르기</span>
          <input id="rc-file" type="file" accept=".xlsx,.csv,.pdf">
          <span class="filelabel__name">고른 파일 없음</span>
        </label>
        <button id="rc-upload" class="btn btn--quiet" type="button">올리기</button>
        <button id="rc-reload" class="btn btn--quiet" type="button">다시 보기</button>
      </div>
    </div>

    <div class="table-wrap">
      <table class="files">
        <thead>
          <tr><th scope="col">거래처</th><th scope="col">월</th><th scope="col">파일</th><th scope="col" class="num">줄</th><th scope="col" class="num">차이</th>
              <th scope="col" class="num">명세서 합계</th><th scope="col">올린 사람</th>
              <th scope="col"><span class="sr-only">작업</span></th></tr>
        </thead>
        <tbody id="rc-list"></tbody>
      </table>
    </div>
    <p id="rc-empty" class="empty" hidden>올린 명세서가 없습니다.</p>
    </section>

  <!-- 열 맞추기 (확정 전) — 별도 패널 -->
  <section class="panel" id="rc-map" hidden>
    <div class="panel__head panel__head--row">
      <h2 class="panel__title">열 맞추기</h2>
      <div class="panel__tools">
        <label class="field field--inline">
          <span class="field__label">머리행</span>
          <select id="rc-header-row"></select>
        </label>
        <button id="rc-preview" class="btn btn--quiet" type="button">맞춰 보기</button>
        <button id="rc-commit" class="btn" type="button">대사 확정</button>
      </div>
    </div>
    <div class="p-map" id="rc-map-fields"></div>
    <p class="help">명세서에서 읽는 열은 품목코드 · 품목명 · 수량 · 단가 · 금액입니다.</p>
  </section>

  <!-- 대조 결과 — 별도 패널 -->
  <section class="panel" id="rc-result" hidden>
    <div class="panel__head panel__head--row">
      <h2 class="panel__title">대조 결과</h2>
      <div class="panel__tools">
        <label class="check">
          <input id="rc-only-diff" type="checkbox">
          <span>차이만 보기</span>
        </label>
      </div>
    </div>
    <div class="p-kpis" id="rc-kpis"></div>
    <div class="table-wrap">
      <table class="files">
        <thead>
          <tr><th scope="col" class="p-col-no">#</th><th scope="col">지점</th><th scope="col">품목</th>
              <th scope="col" class="num">우리 수량</th><th scope="col" class="num">우리 단가</th><th scope="col" class="num">우리 금액</th>
              <th scope="col" class="num">명세서 수량</th><th scope="col" class="num">명세서 단가</th><th scope="col" class="num">명세서 금액</th>
              <th scope="col" class="num">차이</th><th scope="col">판정</th><th scope="col">비고</th></tr>
        </thead>
        <tbody id="rc-lines"></tbody>
      </table>
    </div>
    <p class="help">「우리 금액」은 입고 때의 수량 × 단가입니다.</p>
    </section>
  </div>

  <!-- ── 월 마감 ────────────────────────────────────────── -->
  <section class="panel" id="recon-close" hidden>
    <div class="panel__head panel__head--row">
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
        <button id="cl-run" class="btn" type="button">이 달 마감</button>
      </div>
    </div>

    <div class="table-wrap">
      <table class="files">
        <thead><tr><th scope="col">월</th><th scope="col">범위</th><th scope="col">마감한 사람</th><th scope="col">마감 시각</th><th scope="col">사유</th></tr></thead>
        <tbody id="cl-list"></tbody>
      </table>
    </div>
    <p id="cl-empty" class="empty" hidden>마감한 달이 없습니다.</p>
    <p class="help">마감은 되돌릴 수 없습니다. 그 달에 아직 확정되지 않은 전표가 있으면 마감되지 않습니다. 마감 뒤의 수정·취소에는 사유가 필요합니다.</p>
  </section>

  <!-- ── 기공 명세서 ─────────────────────────────────────── -->
  <section class="panel" id="recon-lab" hidden>
    <div class="panel__head panel__head--row">
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
        <button id="lb-search" class="btn btn--quiet" type="button">찾기</button>
      </div>
    </div>

    <div class="table-wrap">
      <table class="files">
        <thead><tr><th scope="col">품목</th><th scope="col">재고관리</th><th scope="col" class="num">수량</th><th scope="col">비고</th>
          <th scope="col"><span class="sr-only">작업</span></th></tr></thead>
        <tbody id="lb-body"></tbody>
      </table>
    </div>
    <p id="lb-empty" class="empty" hidden>담은 품목이 없습니다 — 위에서 품목을 찾아 담으세요.</p>
    <div class="form-actions">
      <button id="lb-save" class="btn" type="button">입고 전표 만들기</button>
    </div>
    <p class="help">재고관리를 하지 않는 품목만 담을 수 있습니다(기공료 등). 현재고는 바뀌지 않고 사용 통계와 월 대사에만 잡힙니다.</p>
  </section>

<script src="/assets/purchase_common.js?v=<?= (int) filemtime(__DIR__ . '/../../public/assets/purchase_common.js') ?>"></script>
<script src="/assets/purchase_recon.js?v=<?= (int) filemtime(__DIR__ . '/../../public/assets/purchase_recon.js') ?>"></script>
<?php require __DIR__ . '/shell_bottom.php'; ?>
