<?php
declare(strict_types=1);

/**
 * src/views/purchase_po.php — 발주서 화면의 마크업 (시안 ④ ImportModal · ⑤ PO)
 *
 * 틀만 놓는다. 불러오기 트리·발주서 줄·머리 수정은 `purchase_docs.js` 가
 * `textContent` 로만 그린다 (PRD S-03). 인라인 스크립트·on* 속성은 쓰지 않는다.
 *
 * ★ 25단계 — 발주요청 화면과 **같은 뼈대**다: 머리 카드가 위, 줄 카드가 아래 전체 폭,
 *   저장 단추는 카드 밖 오른쪽. 불러오기 모달은 시안 ④ 대로 **좌 트리 / 우 줄 표** 2단이다.
 *   이카운트 양식의 머리 7칸은 상세 패널 안 `<details>` 로 접어 두어 화면을 점령하지 않는다.
 *
 * @var array<string,mixed> $user
 */

require __DIR__ . '/shell_top.php';
?>
    <header class="page-head">
      <div class="page-head__text">
        <nav class="crumbs muted" aria-label="위치">구매관리 › 전표 › 발주서</nav>
        <h1 class="page-head__title">발주서</h1>
        <p class="page-head__sub">거래처 하나에 보내는 문서입니다. 줄마다 <strong>어느 발주요청에서 왔는지</strong>가 붙고, 저장하면 그 요청의 잔량이 줄어듭니다.</p>
      </div>
      <div class="page-head__actions">
        <button id="po-goto-docs" class="btn btn--quiet" type="button">
          <span class="icon" data-icon="log"></span><span>전표 보기</span>
        </button>
      </div>
    </header>

  <p id="msg" class="msg" role="alert" aria-live="polite"></p>

  <nav class="tabs" id="po-tabs" role="tablist" aria-label="발주서 화면">
    <button class="tab is-on" type="button" role="tab" aria-selected="true" data-tab="input">입력</button>
    <button class="tab" type="button" role="tab" aria-selected="false" data-tab="docs">전표</button>
  </nav>

  <!-- ── 입력 탭 — 머리 카드(위) + 줄 카드(아래) ─────────────── -->
  <form id="po-input" class="form" autocomplete="off">
    <section class="panel">
      <div class="panel__head panel__head--row">
        <h2 class="panel__title">새 발주서</h2>
      </div>
      <div class="grid-form">
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
        <label class="field field--full">
          <span class="field__label">참조 · 비고</span>
          <input id="po-memo" type="text" maxlength="500">
        </label>
      </div>
    </section>

    <section class="panel">
      <div class="panel__head panel__head--row">
        <h2 class="panel__title">발주 줄</h2>
        <div class="panel__tools">
          <button id="po-load" class="btn btn--quiet" type="button">발주요청 불러오기</button>
        </div>
      </div>

      <div class="table-wrap">
        <table class="files p-lines">
          <thead>
            <tr>
              <th scope="col" class="p-col-no">#</th>
              <th scope="col">코드</th>
              <th scope="col">품목명 · 규격</th>
              <th scope="col">출처 요청</th>
              <th scope="col" class="num">잔량</th>
              <th scope="col" class="num">담을 수량</th>
              <th scope="col" class="num">단가</th>
              <th scope="col" class="num">공급가액</th>
              <th scope="col"><span class="sr-only">줄 빼기</span></th>
            </tr>
          </thead>
          <tbody id="po-line-body"></tbody>
        </table>
      </div>
      <p id="po-line-empty" class="empty">「발주요청 불러오기」로 줄을 담습니다.</p>

      <div class="table-foot">
        <span id="po-sum" class="muted"></span>
        <span class="muted">공급가액(부가세 별도) <b id="po-total" class="p-total">0</b></span>
      </div>
      <p class="help">발주서는 발주요청의 미발주 잔량에서 만듭니다. 보낸 뒤 고치려면 사유가 필요합니다.</p>
    </section>

    <div class="form-actions">
      <button id="po-save" class="btn" type="submit">발주서 만들기</button>
    </div>
  </form>

  <!-- ── 전표 탭 ───────────────────────────────────────────── -->
  <section class="panel" id="po-docs" hidden>
    <div class="panel__head panel__head--row">
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
            <th scope="col">일자 · 번호</th><th scope="col">거래처</th><th scope="col">장소</th><th scope="col">담당자</th>
            <th scope="col" class="num">품목</th><th scope="col" class="num">금액</th><th scope="col">상태</th><th scope="col">보냄</th>
          </tr>
        </thead>
        <tbody id="po-doc-body"></tbody>
      </table>
    </div>
    <p id="po-doc-empty" class="empty" hidden>표시할 발주서가 없습니다.</p>

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
    <div class="panel__head panel__head--row">
      <h2 class="panel__title" id="po-detail-title">발주서</h2>
      <div class="panel__tools">
        <!-- 전표를 고르기 전에는 **아예 없다** — 자기 자신으로 가는 더미 링크를 두지 않는다 -->
        <a id="po-print" class="btn btn--quiet" target="_blank" rel="noopener" hidden>인쇄 · PDF</a>
        <button id="po-send" class="btn btn--quiet" type="button">보냄</button>
        <button id="po-void" class="btn btn--danger" type="button">발주 취소</button>
        <button id="po-detail-close" class="btn btn--quiet" type="button">닫기</button>
      </div>
    </div>
    <p id="po-detail-sub" class="muted"></p>

    <div class="table-wrap">
      <table class="files">
        <thead>
          <tr>
            <th scope="col" class="p-col-no">#</th><th scope="col">코드</th><th scope="col">품목명</th><th scope="col">출처 요청</th>
            <th scope="col" class="num">수량</th><th scope="col" class="num">단가</th><th scope="col" class="num">공급가액</th>
          </tr>
        </thead>
        <tbody id="po-detail-body"></tbody>
      </table>
    </div>
    <p id="po-detail-totals" class="p-totals"></p>

    <!-- 이카운트 발주서 양식의 머리 7칸. 자주 쓰지 않으므로 접어 둔다 (시안 ⑤). -->
    <details class="help" id="po-head-box">
      <summary>인쇄 양식 머리 고치기 (수신 · 참조 · TEL · 납품창고 · 납기일자 · 비고)</summary>
      <form id="po-head-form" class="form" autocomplete="off">
        <div class="grid-form">
          <label class="field"><span class="field__label">수신</span><input id="poh-receiver" type="text" maxlength="200"></label>
          <label class="field"><span class="field__label">참조</span><input id="poh-cc" type="text" maxlength="200"></label>
          <label class="field"><span class="field__label">TEL</span><input id="poh-tel" type="text" maxlength="200"></label>
          <label class="field"><span class="field__label">납품창고</span><input id="poh-delivery" type="text" maxlength="200"></label>
          <label class="field"><span class="field__label">납기일자</span><input id="poh-due" type="date"></label>
          <label class="field field--full"><span class="field__label">비고</span><input id="poh-memo" type="text" maxlength="200"></label>
          <label class="field field--full" id="poh-reason-field" hidden>
            <span class="field__label">수정 사유 (보낸 뒤에는 필수)</span>
            <input id="poh-reason" type="text" maxlength="300">
          </label>
        </div>
        <div class="table-foot">
          <span class="muted"></span>
          <button id="poh-save" class="btn btn--quiet" type="submit">머리 수정 저장</button>
        </div>
      </form>
    </details>
  </section>

  <!-- ── 불러오기 모달 (시안 ④ ImportModal) — 좌 트리 / 우 줄 표 ── -->
  <dialog class="modal modal--stack modal--wide" id="load-modal" aria-labelledby="load-title">
    <div class="modal__head">
      <h2 class="modal__title" id="load-title">발주요청 불러오기</h2>
      <span class="modal__meta">미발주 잔량이 남은 줄만 나옵니다</span>
      <button class="btn btn--icon" type="button" data-modal-close aria-label="닫기" title="닫기">
        <span class="icon" data-icon="x"></span>
      </button>
    </div>
    <div class="modal__body">
      <div class="panel__tools">
        <span class="field__label">묶는 기준 (누른 순서가 층)</span>
        <span class="p-chipbar" id="load-groups">
          <button class="chip" type="button" data-group="vendor">거래처</button>
          <button class="chip" type="button" data-group="location">장소</button>
          <button class="chip" type="button" data-group="manager">담당자</button>
          <button class="chip" type="button" data-group="branch">지점</button>
          <button class="chip" type="button" data-group="category">분류</button>
        </span>
      </div>
      <div class="panel__tools">
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
        <button id="load-run" class="btn btn--quiet" type="button">불러오기</button>
      </div>

      <div class="split" id="load-split">
        <section class="panel">
          <div class="panel__head panel__head--row">
            <h3 class="panel__title">묶음</h3>
          </div>
          <div id="load-tree" class="p-tree p-scroll" data-allow-scroll></div>
          <p id="load-tree-empty" class="empty" hidden>미발주 잔량이 남은 줄이 없습니다.</p>
        </section>

        <section class="panel">
          <div class="panel__head panel__head--row">
            <h3 class="panel__title" id="load-lines-title">줄</h3>
          </div>
          <div class="table-wrap p-pick-wrap">
            <table class="files">
              <thead>
                <tr>
                  <th scope="col" class="p-col-check"><span class="sr-only">선택</span></th>
                  <th scope="col">코드</th><th scope="col">품목명 · 규격</th>
                  <th scope="col">출처 요청</th><th scope="col" class="num">잔량</th><th scope="col" class="num">담을 수량</th>
                </tr>
              </thead>
              <tbody id="load-lines-body"></tbody>
            </table>
          </div>
          <p id="load-lines-empty" class="empty">왼쪽에서 묶음을 고르세요.</p>
        </section>
      </div>
    </div>
    <div class="modal__foot">
      <span id="load-info" class="muted"></span>
      <button id="load-add" class="btn" type="button">담기</button>
    </div>
  </dialog>

<script src="/assets/purchase_common.js?v=<?= (int) filemtime(__DIR__ . '/../../public/assets/purchase_common.js') ?>"></script>
<script src="/assets/purchase_docs.js?v=<?= (int) filemtime(__DIR__ . '/../../public/assets/purchase_docs.js') ?>"></script>
<?php require __DIR__ . '/shell_bottom.php'; ?>
