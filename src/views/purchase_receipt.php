<?php
declare(strict_types=1);

/**
 * src/views/purchase_receipt.php — 입고 화면 (시안 ⑥ GR, PRD F-33 · F-34 · F-45)
 *
 * 탭 셋이다 — **발주요청·발주서 화면과 같은 뼈대**다 (F-45).
 *   입력  물건이 왔을 때 적는 곳. 「발주서 불러오기」 카드 + 입고 줄 카드 (21단계 그대로).
 *   전표  이미 만든 입고 전표를 찾아 보고, 필요하면 취소하는 곳.
 *   현황  미입고 현황. 재고 화면의 「미입고」 탭과 **같은 API·같은 렌더러**를 쓴다.
 *
 * 값은 `purchase_stock.js` 가 `textContent` 로만 채운다 (PRD S-03). 이 파일에는 동적 값이 없다.
 *
 * ★ 단가·금액 입력 칸이 **없다**. 저장 본문에는 수량·로트·유효기간·비고만 실리고,
 *   단가는 서버가 발주서 줄에서 읽는다 (SECURITY R-66, 린트 규칙 (d)).
 *
 * ★ 25단계 — 필터는 카드 머리의 한 줄(`.panel__head--row` + `.panel__tools`)로 모았고,
 *   입고일·비고는 표 **위**의 머리 칸(`.grid-form`)이 되었다. 알림 줄은 화면에 하나뿐이다.
 *
 * ★ 사유는 `window.prompt` 가 아니라 모달로 받는다 — 26단계 계정 화면의 사유 모달과
 *   같은 형식(`.modal--form` + 제목·안내·사유 칸·취소/확인)이다.
 *
 * @var array<string,mixed> $user
 */

require __DIR__ . '/shell_top.php';
?>
    <header class="page-head">
      <div class="page-head__text">
        <nav class="crumbs muted" aria-label="위치">구매관리 › 전표 › 입고</nav>
        <h1 class="page-head__title">입고</h1>
        <p class="page-head__sub">물건이 도착하면 발주서를 불러와 <strong>실제 온 수량만</strong> 적습니다.
          저장하면 입고 장소의 재고가 늘고 발주서의 미입고가 줄어듭니다.</p>
      </div>
      <div class="page-head__actions">
        <button type="button" class="btn btn--quiet" data-help-open>
          <span class="icon" data-icon="help"></span><span>사용 방법</span>
        </button>
      </div>
    </header>

  <p id="msg" class="msg" role="alert" aria-live="polite"></p>

  <nav class="tabs" id="gr-tabs" role="tablist" aria-label="입고 화면">
    <button class="tab is-on" type="button" role="tab" aria-selected="true" data-tab="input">입력</button>
    <button class="tab" type="button" role="tab" aria-selected="false" data-tab="docs">전표</button>
    <button class="tab" type="button" role="tab" aria-selected="false" data-tab="status">현황</button>
  </nav>

  <!-- ── 입력 탭 — 21단계의 두 카드 그대로(id 유지) + 저장 결과 카드 ── -->
  <div id="gr-input">
    <section class="panel" id="gr-load">
      <div class="panel__head panel__head--row">
        <h2 class="panel__title">발주서 불러오기 — 미입고 잔량만</h2>
        <div class="panel__tools">
          <label class="field field--inline">
            <span class="field__label">거래처</span>
            <select id="gr-vendor"></select>
          </label>
          <label class="field field--inline">
            <span class="field__label">검색</span>
            <input id="gr-q" type="search" maxlength="100" autocomplete="off" spellcheck="false">
          </label>
          <label class="check">
            <input id="gr-overdue" type="checkbox">
            <span>납기 지남만</span>
          </label>
          <button id="gr-load-run" class="btn btn--quiet" type="button">다시 보기</button>
        </div>
      </div>
      <div id="gr-orders"></div>
      <p id="gr-load-empty" class="empty" hidden>보낸 발주서 가운데 미입고가 남은 것이 없습니다.</p>
      <p class="help">「보냄」 표시가 된 발주서만 나옵니다.</p>
    </section>

    <section class="panel" id="gr-form">
      <div class="panel__head panel__head--row">
        <h2 class="panel__title">입고 줄</h2>
      </div>

      <div class="grid-form grid-form--2">
        <label class="field">
          <span class="field__label">입고일</span>
          <input id="gr-date" type="date">
        </label>
        <label class="field">
          <span class="field__label">비고</span>
          <input id="gr-memo" type="text" maxlength="500" autocomplete="off">
        </label>
      </div>

      <div class="table-wrap">
        <table class="files">
          <thead>
            <tr>
              <th scope="col" class="p-col-no">#</th><th scope="col">품목</th><th scope="col">출처 발주서</th>
              <th scope="col" class="num">발주</th><th scope="col" class="num">기입고</th>
              <th scope="col" class="num">이번 입고</th><th scope="col" class="num">남는 미입고</th>
              <th scope="col">로트</th><th scope="col">유효기간</th><th scope="col">비고</th>
              <th scope="col"><span class="sr-only">줄 빼기</span></th>
            </tr>
          </thead>
          <tbody id="gr-line-body"></tbody>
        </table>
      </div>
      <p id="gr-line-empty" class="empty">위 목록에서 「담기」를 눌러 입고할 줄을 고르세요.</p>

      <div class="table-foot" id="gr-foot" hidden>
        <span id="gr-sum" class="muted"></span>
      </div>
      <div class="form-actions">
        <button id="gr-save" class="btn" type="button">입고 확정</button>
      </div>
    </section>

    <section class="panel" id="gr-result" hidden>
      <div class="panel__head panel__head--row">
        <h2 class="panel__title">저장한 입고 전표</h2>
        <div class="panel__tools">
          <button id="gr-void" class="btn btn--danger" type="button">이 전표 취소</button>
        </div>
      </div>
      <p id="gr-result-head" class="muted"></p>
      <div class="table-wrap">
        <table class="files">
          <thead>
            <tr><th scope="col" class="p-col-no">#</th><th scope="col">품목</th><th scope="col" class="num">수량</th>
                <th scope="col">로트 · 유효기간</th><th scope="col">출처</th><th scope="col">상태</th></tr>
          </thead>
          <tbody id="gr-result-body"></tbody>
        </table>
      </div>
      <p class="help">취소하면 미입고가 되돌아옵니다.</p>
    </section>
  </div>

  <!-- ── 전표 탭 — 목록(위) + 상세(아래). 발주서 화면의 전표 탭과 같은 짜임이다 ── -->
  <div id="gr-docs" hidden>
    <section class="panel">
      <div class="panel__head panel__head--row">
        <h2 class="panel__title">입고 전표</h2>
        <div class="panel__tools">
          <label class="field field--inline">
            <span class="field__label">검색</span>
            <input id="gr-doc-q" type="search" maxlength="100" autocomplete="off" spellcheck="false">
          </label>
          <label class="field field--inline">
            <span class="field__label">거래처</span>
            <select id="gr-doc-vendor"></select>
          </label>
          <label class="field field--inline">
            <span class="field__label">기간</span>
            <input id="gr-doc-from" type="date">
          </label>
          <label class="field field--inline">
            <span class="field__label">~</span>
            <input id="gr-doc-to" type="date">
          </label>
          <label class="field field--inline">
            <span class="field__label">상태</span>
            <select id="gr-doc-status">
              <option value="">전체</option>
              <option value="done">확정</option>
              <option value="void">취소</option>
            </select>
          </label>
        </div>
      </div>

      <div class="table-wrap">
        <table class="files">
          <thead>
            <tr>
              <th scope="col">일자 · 번호</th><th scope="col">거래처</th><th scope="col">입고 장소</th>
              <th scope="col">담당자</th><th scope="col" class="num">품목</th><th scope="col" class="num">수량</th>
              <th scope="col">상태</th>
            </tr>
          </thead>
          <tbody id="gr-doc-body"></tbody>
        </table>
      </div>
      <p id="gr-doc-empty" class="empty" hidden>표시할 입고 전표가 없습니다.</p>

      <div class="table-foot">
        <span id="gr-doc-page-info" class="muted"></span>
        <div class="pager">
          <button id="gr-doc-prev" class="btn btn--quiet btn--small" type="button">이전</button>
          <button id="gr-doc-next" class="btn btn--quiet btn--small" type="button">다음</button>
        </div>
      </div>
      <p class="help">줄을 누르면 아래에 상세가 열립니다. 취소한 전표도 함께 나옵니다 — 취소는 삭제가 아닙니다.</p>
    </section>

    <section class="panel" id="gr-detail" hidden>
      <div class="panel__head panel__head--row">
        <h2 class="panel__title" id="gr-detail-title">입고 전표</h2>
        <div class="panel__tools">
          <button id="gr-detail-void" class="btn btn--danger" type="button">입고 취소</button>
          <button id="gr-detail-close" class="btn btn--quiet" type="button">닫기</button>
        </div>
      </div>
      <p id="gr-detail-sub" class="muted"></p>

      <div class="table-wrap">
        <table class="files">
          <thead>
            <tr>
              <th scope="col" class="p-col-no">#</th><th scope="col">품목</th><th scope="col">규격</th>
              <th scope="col" class="num">수량</th><th scope="col">로트</th><th scope="col">유효기간</th>
              <th scope="col">출처 발주서</th><th scope="col">비고</th>
            </tr>
          </thead>
          <tbody id="gr-detail-body"></tbody>
        </table>
      </div>
      <p id="gr-detail-totals" class="p-totals"></p>
      <p class="help">취소하면 원장에 반대 부호 행이 새로 들어가고 발주서의 미입고가 되돌아옵니다.</p>
    </section>
  </div>

  <!-- ── 현황 탭 — 미입고 현황. 재고 화면의 미입고 탭과 같은 API·같은 렌더러다 ── -->
  <section class="panel" id="gr-status" hidden>
    <div class="panel__head panel__head--row">
      <h2 class="panel__title">미입고 현황</h2>
      <div class="panel__tools">
        <label class="field field--inline">
          <span class="field__label">거래처</span>
          <select id="gr-bo-vendor"></select>
        </label>
        <label class="check">
          <input id="gr-bo-overdue" type="checkbox">
          <span>납기 지남만</span>
        </label>
        <button id="gr-bo-run" class="btn btn--quiet" type="button">다시 보기</button>
      </div>
    </div>

    <div class="p-kpis" id="gr-bo-kpis"></div>
    <div id="gr-backorder"></div>
    <p id="gr-bo-empty" class="empty" hidden>미입고가 없습니다.</p>
    <p class="help">미입고 = 발주 수량 − 입고 수량. 「보낸」 발주서만 셉니다.</p>
  </section>

  <!-- 사유만 받는 모달 — 입고 취소. 26단계 계정 화면의 사유 모달과 같은 형식이다.
       구매 화면에서 `window.prompt` 를 늘리지 않으려는 것이 이 모달의 이유다. -->
  <dialog id="gr-reason" class="modal modal--form" aria-labelledby="grr-title">
    <form id="gr-reason-form" class="form" autocomplete="off">
      <div class="modal__head">
        <h2 class="modal__title" id="grr-title">입고 취소</h2>
      </div>
      <div class="modal__body">
        <p id="grr-lead" class="muted"></p>
        <label class="field">
          <span class="field__label">사유 (300자 · 감사 로그에 남습니다)</span>
          <textarea id="grr-text" rows="3" maxlength="300"></textarea>
        </label>
        <p id="grr-msg" class="msg" role="alert" aria-live="polite"></p>
      </div>
      <div class="modal__foot">
        <button id="grr-cancel" class="btn btn--quiet" type="button">그만두기</button>
        <button id="grr-submit" class="btn btn--danger" type="submit">취소 확정</button>
      </div>
    </form>
  </dialog>

<script src="/assets/purchase_common.js?v=<?= (int) filemtime(__DIR__ . '/../../public/assets/purchase_common.js') ?>"></script>
<script src="/assets/purchase_stock.js?v=<?= (int) filemtime(__DIR__ . '/../../public/assets/purchase_stock.js') ?>"></script>
<?php require __DIR__ . '/shell_bottom.php'; ?>
