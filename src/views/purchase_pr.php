<?php
declare(strict_types=1);

/**
 * src/views/purchase_pr.php — 발주요청 화면의 마크업 (시안 ① Main · ② ItemPicker · ③ MyList)
 *
 * 공통 셸(`shell_top.php` · `shell_bottom.php`)을 그대로 쓴다.
 * 이 파일이 하는 일은 **본문 틀**을 놓는 것뿐이고, 표와 값은 `purchase_docs.js` 가
 * `/api/purchase/*` 응답을 `textContent` 로만 채운다 (PRD S-03).
 *
 * ★ 서버가 HTML 에 넣는 동적 값이 **하나도 없다**(셸의 사용자명·역할 제외).
 * ★ 인라인 <script>·style 속성·on* 이벤트 속성을 쓰지 않는다 (CSP: script-src 'self').
 *
 * @var array<string,mixed> $user
 */

require __DIR__ . '/shell_top.php';
?>
    <link rel="stylesheet" href="/assets/purchase_docs.css?v=<?= (int) filemtime(__DIR__ . '/../../public/assets/purchase_docs.css') ?>">

    <header class="page-head">
      <div class="page-head__text">
        <h1 class="page-head__title">구매관리 › 발주요청</h1>
        <p class="page-head__sub">필요한 것을 적어 두면 구매실이 거래처별로 묶어 발주합니다. <strong>재고는 움직이지 않습니다.</strong></p>
      </div>
      <div class="page-head__actions">
        <a class="btn btn--quiet" href="/purchase/po">발주서</a>
        <a class="btn btn--quiet" href="/purchase/docs">전표·사이클</a>
        <a class="btn btn--quiet" href="/purchase/masters">기초정보</a>
      </div>
    </header>

  <p class="notice">
    요청은 <strong>미확인</strong>일 때만 고칠 수 있습니다. 구매실이 어떤 줄을 발주서에 담으면
    그 줄은 <strong>잠깁니다</strong>. 잠긴 뒤에 더 적는 내역은 <strong>새 요청 전표</strong>로 자동 분리됩니다
    (그래야 무엇이 발주됐는지가 흐려지지 않습니다).
  </p>

  <nav class="p-tabs" id="pr-tabs" aria-label="발주요청 화면">
    <button class="p-tab is-on" type="button" data-tab="input">입력</button>
    <button class="p-tab" type="button" data-tab="docs">전표</button>
    <button class="p-tab" type="button" data-tab="status">현황</button>
  </nav>

  <!-- ── 입력 탭 ───────────────────────────────────────────── -->
  <section class="panel" id="pr-input">
    <div class="panel__head">
      <h2 class="panel__title">새 발주요청</h2>
      <div class="panel__tools">
        <button id="pr-pick" class="btn btn--small" type="button">품목 찾기</button>
        <button id="pr-reco" class="btn btn--quiet btn--small" type="button">추천 · 내 리스트</button>
      </div>
    </div>

    <form id="pr-form" class="form form--grid" autocomplete="off">
      <div class="form__fields">
        <label class="field">
          <span class="field__label">일자</span>
          <input id="pr-date" type="date" required>
        </label>
        <label class="field">
          <span class="field__label">지점</span>
          <select id="pr-branch"></select>
        </label>
        <label class="field">
          <span class="field__label">받을 장소</span>
          <select id="pr-location"></select>
        </label>
        <label class="field">
          <span class="field__label">거래처 (선택)</span>
          <select id="pr-vendor"></select>
        </label>
        <label class="field field--wide">
          <span class="field__label">비고</span>
          <input id="pr-memo" type="text" maxlength="500">
        </label>
      </div>

      <div class="table-wrap">
        <table class="files p-lines">
          <thead>
            <tr>
              <th class="p-col-no">#</th>
              <th>코드</th>
              <th>품목명 · 규격</th>
              <th>거래처</th>
              <th class="p-num">수량</th>
              <th class="p-num">단가</th>
              <th class="p-num">금액</th>
              <th>비고</th>
              <th></th>
            </tr>
          </thead>
          <tbody id="pr-line-body"></tbody>
        </table>
      </div>
      <p id="pr-line-empty" class="muted">품목을 담으면 여기에 줄이 생깁니다.</p>

      <div class="p-foot">
        <span id="pr-sum" class="muted"></span>
        <button id="pr-save" class="btn" type="submit">저장</button>
      </div>
      <p class="muted">
        단가와 금액은 <strong>서버가 품목 정보에서 계산</strong>합니다 — 화면에서 고칠 수 없고,
        고쳐 보내도 서버는 읽지 않습니다.
      </p>
    </form>
  </section>

  <!-- ── 전표 탭 ───────────────────────────────────────────── -->
  <section class="panel" id="pr-docs" hidden>
    <div class="panel__head">
      <h2 class="panel__title">발주요청 전표</h2>
      <div class="panel__tools">
        <label class="field field--inline">
          <span class="field__label">검색</span>
          <input id="pr-q" type="search" maxlength="100" autocomplete="off" spellcheck="false">
        </label>
        <label class="field field--inline">
          <span class="field__label">상태</span>
          <select id="pr-status">
            <option value="">전체(취소 제외)</option>
            <option value="unconfirmed">미확인</option>
            <option value="partial">일부 발주</option>
            <option value="done">완료</option>
            <option value="void">취소</option>
          </select>
        </label>
        <label class="check">
          <input id="pr-mine" type="checkbox">
          <span>내 요청만</span>
        </label>
      </div>
    </div>

    <div class="table-wrap">
      <table class="files">
        <thead>
          <tr>
            <th>일자 · 번호</th><th>지점</th><th>장소</th><th>거래처</th>
            <th>담당자</th><th class="p-num">품목</th><th class="p-num">금액</th><th>상태</th>
          </tr>
        </thead>
        <tbody id="pr-doc-body"></tbody>
      </table>
    </div>
    <p id="pr-doc-empty" class="muted" hidden>표시할 전표가 없습니다.</p>

    <div class="table-foot">
      <span id="pr-page-info" class="muted"></span>
      <div class="pager">
        <button id="pr-prev" class="btn btn--quiet btn--small" type="button">이전</button>
        <button id="pr-next" class="btn btn--quiet btn--small" type="button">다음</button>
      </div>
    </div>
  </section>

  <!-- 전표 상세 (전표 탭에서 한 줄을 누르면 열린다) -->
  <section class="panel" id="pr-detail" hidden>
    <div class="panel__head">
      <h2 class="panel__title" id="pr-detail-title">전표</h2>
      <div class="panel__tools">
        <button id="pr-detail-void" class="btn btn--danger btn--small" type="button">요청 취소</button>
        <button id="pr-detail-close" class="btn btn--quiet btn--small" type="button">닫기</button>
      </div>
    </div>
    <p id="pr-detail-sub" class="muted"></p>
    <div class="table-wrap">
      <table class="files">
        <thead>
          <tr>
            <th class="p-col-no">#</th><th>코드</th><th>품목명</th>
            <th class="p-num">요청</th><th class="p-num">발주</th><th class="p-num">잔량</th>
            <th class="p-num">금액</th><th>상태</th><th></th>
          </tr>
        </thead>
        <tbody id="pr-detail-body"></tbody>
      </table>
    </div>
    <p id="pr-detail-note" class="muted"></p>
  </section>

  <!-- ── 현황 탭 ───────────────────────────────────────────── -->
  <section class="panel" id="pr-status-panel" hidden>
    <div class="panel__head"><h2 class="panel__title">내 요청 현황 (사이클)</h2></div>
    <div class="table-wrap">
      <table class="files">
        <thead>
          <tr>
            <th>발주요청</th><th>요청자 · 장소</th><th>품목</th>
            <th class="p-num">요청</th><th>발주서</th><th class="p-num">발주</th>
            <th class="p-num">미발주</th><th>입고</th><th>상태</th>
          </tr>
        </thead>
        <tbody id="pr-cycle-body"></tbody>
      </table>
    </div>
    <p class="muted">입고·미입고 칸은 재고 원장의 합입니다(21단계부터). 보낸 발주서인데 아직 오지 않은 줄에는 「미입고」 배지가 섭니다.</p>
  </section>

  <!-- ── 품목 찾기 모달 (시안 ② ItemPicker) ─────────────────── -->
  <dialog class="modal" id="pick-modal">
    <form method="dialog" class="modal__form">
      <h2 class="modal__title">품목 찾기</h2>
      <div class="modal__body">
        <div class="p-pick-tools">
          <label class="field field--inline">
            <span class="field__label">검색</span>
            <input id="pick-q" type="search" maxlength="100" autocomplete="off" spellcheck="false">
          </label>
          <label class="field field--inline">
            <span class="field__label">거래처</span>
            <select id="pick-vendor"></select>
          </label>
          <button id="pick-search" class="btn btn--small" type="button">찾기</button>
        </div>
        <div class="table-wrap p-pick-wrap">
          <table class="files">
            <thead>
              <tr><th></th><th>코드</th><th>품목명 · 규격</th><th>거래처</th><th class="p-num">단가</th><th class="p-num">수량</th></tr>
            </thead>
            <tbody id="pick-body"></tbody>
          </table>
        </div>
        <p id="pick-info" class="muted"></p>
        <div class="pager">
          <button id="pick-prev" class="btn btn--quiet btn--small" type="button">이전</button>
          <button id="pick-next" class="btn btn--quiet btn--small" type="button">다음</button>
        </div>
      </div>
      <div class="modal__actions">
        <button class="btn btn--quiet" type="submit" value="cancel">닫기</button>
        <button id="pick-add" class="btn" type="button">고른 품목 담기</button>
      </div>
    </form>
  </dialog>

  <!-- ── 추천 · 내 리스트 모달 (시안 ③ MyList) ───────────────── -->
  <dialog class="modal" id="reco-modal">
    <form method="dialog" class="modal__form">
      <h2 class="modal__title">추천 · 내 발주 리스트</h2>
      <div class="modal__body">
        <div class="p-pick-tools">
          <label class="check">
            <input id="reco-branch" type="checkbox">
            <span>지점 전체 기준</span>
          </label>
          <span id="reco-note" class="muted"></span>
        </div>
        <h3 class="p-sub">자주 주문 (최근 90일)</h3>
        <div class="table-wrap p-pick-wrap">
          <table class="files">
            <thead><tr><th></th><th>품목</th><th class="p-num">횟수</th><th class="p-num">평균</th><th>마지막</th><th class="p-num">수량</th></tr></thead>
            <tbody id="reco-body"></tbody>
          </table>
        </div>

        <h3 class="p-sub">내 리스트</h3>
        <div class="p-pick-tools">
          <select id="reco-list"></select>
          <button id="reco-list-add" class="btn btn--quiet btn--small" type="button">전체 담기</button>
          <button id="reco-list-new" class="btn btn--quiet btn--small" type="button">지금 줄로 리스트 만들기</button>
        </div>
        <div class="table-wrap p-pick-wrap">
          <table class="files">
            <thead><tr><th></th><th>품목</th><th class="p-num">기본 수량</th></tr></thead>
            <tbody id="reco-list-body"></tbody>
          </table>
        </div>
      </div>
      <div class="modal__actions">
        <button class="btn btn--quiet" type="submit" value="cancel">닫기</button>
        <button id="reco-add" class="btn" type="button">고른 품목 담기</button>
      </div>
    </form>
  </dialog>

<script src="/assets/purchase_docs.js?v=<?= (int) filemtime(__DIR__ . '/../../public/assets/purchase_docs.js') ?>"></script>
<?php require __DIR__ . '/shell_bottom.php'; ?>
