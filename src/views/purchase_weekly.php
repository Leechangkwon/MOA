<?php
declare(strict_types=1);

/**
 * src/views/purchase_weekly.php — 주간 사용량 (시안 Weekly, PRD F-34)
 *
 * 값은 `purchase_recon.js` 가 `textContent` 로만 채운다 (PRD S-03).
 * 이 파일에는 동적 값이 하나도 없다 — 셸이 쓰는 `$user` 말고는 서버가 그릴 것이 없다.
 *
 * ★ 25단계 — 제목 옆의 기간은 JS 가 서버 응답에서 받아 적고, 머리말의 「‹ 전주 / 다음 주 ›」는
 *   기간을 7일 밀어 **같은 조회 로직**을 다시 부른다(새 API 없음). 상한 안내는 표 아래 한 줄이다.
 *
 * @var array<string,mixed> $user
 */

require __DIR__ . '/shell_top.php';
?>
    <header class="page-head">
      <div class="page-head__text">
        <nav class="crumbs muted" aria-label="위치">구매관리 › 현황 › 주간 사용량</nav>
        <h1 class="page-head__title">주간 사용량 보고 <span id="wk-range" class="muted"></span></h1>
        <p class="page-head__sub">「사용」 전표와 재고관리를 하지 않는 품목의 입고를 품목 분류로 모아 주(ISO, 월요일 시작) 단위로 셉니다.</p>
      </div>
      <div class="page-head__actions">
        <button id="wk-prev" class="btn btn--quiet" type="button">‹ 전주</button>
        <button id="wk-next" class="btn btn--quiet" type="button">다음 주 ›</button>
      </div>
    </header>

  <p id="msg" class="msg" role="alert" aria-live="polite"></p>

  <section class="panel" id="weekly-panel">
    <div class="panel__head panel__head--row">
      <h2 class="panel__title">주간 사용량</h2>
      <div class="panel__tools">
        <label class="field field--inline">
          <span class="field__label">시작</span>
          <input id="wk-from" type="date">
        </label>
        <label class="field field--inline">
          <span class="field__label">끝</span>
          <input id="wk-to" type="date">
        </label>
        <label class="field field--inline">
          <span class="field__label">지점</span>
          <select id="wk-branch"></select>
        </label>
        <label class="field field--inline">
          <span class="field__label">행</span>
          <select id="wk-group">
            <option value="cat1">대분류</option>
            <option value="cat2">중분류</option>
            <option value="cat3">소분류</option>
            <option value="item">품목</option>
          </select>
        </label>
        <button id="wk-run" class="btn btn--quiet" type="button">다시 보기</button>
      </div>
    </div>

    <div class="p-kpis" id="wk-kpis"></div>

    <div class="table-wrap">
      <table class="files">
        <thead><tr id="wk-head"></tr></thead>
        <tbody id="wk-body"></tbody>
      </table>
    </div>
    <p id="wk-empty" class="empty" hidden>이 기간에 센 사용량이 없습니다.</p>

    <div class="table-foot" id="wk-foot" hidden>
      <span id="wk-cut" class="muted">표가 상한에서 끊겼습니다 — 기간을 좁히거나 분류로 걸러 보세요.</span>
    </div>

    <p class="help">사용 = 「사용」 전표 + 재고관리를 하지 않는 품목의 입고. 이동·실사 차이는 사용이 아닙니다.</p>
  </section>

<script src="/assets/purchase_common.js?v=<?= (int) filemtime(__DIR__ . '/../../public/assets/purchase_common.js') ?>"></script>
<script src="/assets/purchase_recon.js?v=<?= (int) filemtime(__DIR__ . '/../../public/assets/purchase_recon.js') ?>"></script>
<?php require __DIR__ . '/shell_bottom.php'; ?>
