<?php
declare(strict_types=1);

/**
 * src/views/purchase_receipt.php — 구매(입고) 입력 (시안 ⑥ GR, PRD F-33)
 *
 * 위는 「발주서 불러오기」, 아래는 입고 줄이다. 값은 `purchase_stock.js` 가
 * `textContent` 로만 채운다 (PRD S-03). 이 파일에는 동적 값이 없다.
 *
 * ★ 단가·금액 입력 칸이 **없다**. 저장 본문에는 수량·로트·유효기간·비고만 실리고,
 *   단가는 서버가 발주서 줄에서 읽는다 (SECURITY R-66, 린트 규칙 (d)).
 *
 * ★ 25단계 — 필터는 카드 머리의 한 줄(`.panel__head--row` + `.panel__tools`)로 모았고,
 *   입고일·비고는 표 **위**의 머리 칸(`.grid-form`)이 되었다. 알림 줄은 화면에 하나뿐이다.
 *
 * @var array<string,mixed> $user
 */

require __DIR__ . '/shell_top.php';
?>
    <header class="page-head">
      <div class="page-head__text">
        <nav class="crumbs muted" aria-label="위치">구매관리 › 전표 › 입고</nav>
        <h1 class="page-head__title">입고 입력</h1>
        <p class="page-head__sub">물건이 도착하면 발주서를 불러와 <strong>실제 온 수량만</strong> 적습니다.
          저장하면 입고 장소의 재고가 늘고 발주서의 미입고가 줄어듭니다.</p>
      </div>
    </header>

  <p id="msg" class="msg" role="alert" aria-live="polite"></p>

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
    <p class="help">「보냄」 표시가 된 발주서만 나옵니다 — 아직 거래처에 나가지 않은 문서의 물건은 도착할 수 없습니다.</p>
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
    <p class="help">취소하면 원장에 <strong>반대 부호 행</strong>이 새로 들어가고 미입고가 되돌아옵니다 — 행을 지우지 않습니다.</p>
  </section>

<script src="/assets/purchase_common.js?v=<?= (int) filemtime(__DIR__ . '/../../public/assets/purchase_common.js') ?>"></script>
<script src="/assets/purchase_stock.js?v=<?= (int) filemtime(__DIR__ . '/../../public/assets/purchase_stock.js') ?>"></script>
<?php require __DIR__ . '/shell_bottom.php'; ?>
