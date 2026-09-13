<?php
declare(strict_types=1);

/**
 * src/views/purchase_weekly.php — 주간 사용량 (시안 Weekly, PRD F-34)
 *
 * 값은 `purchase_recon.js` 가 `textContent` 로만 채운다 (PRD S-03).
 * 이 파일에는 동적 값이 하나도 없다 — 셸이 쓰는 `$user` 말고는 서버가 그릴 것이 없다.
 *
 * @var array<string,mixed> $user
 */

require __DIR__ . '/shell_top.php';
?>
    <link rel="stylesheet" href="/assets/purchase_recon.css?v=<?= (int) filemtime(__DIR__ . '/../../public/assets/purchase_recon.css') ?>">

    <header class="page-head">
      <div class="page-head__text">
        <h1 class="page-head__title">구매관리 › 현황 › 주간 사용량</h1>
        <p class="page-head__sub">「사용」 전표와 재고관리를 하지 않는 품목의 입고를 품목 분류로 모아 주(ISO, 월요일 시작) 단위로 셉니다.</p>
      </div>
      <div class="page-head__actions">
        <a class="btn btn--quiet" href="/purchase/stock">재고 현황</a>
        <a class="btn btn--quiet" href="/purchase/recon">월 대사</a>
      </div>
    </header>

  <p id="msg" class="form-msg" role="status" aria-live="polite"></p>

  <section class="panel" id="weekly-panel">
    <div class="panel__head">
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
        <button id="wk-run" class="btn btn--small" type="button">다시 보기</button>
      </div>
    </div>

    <div class="p-kpis" id="wk-kpis"></div>

    <div class="table-wrap">
      <table class="files">
        <thead><tr id="wk-head"></tr></thead>
        <tbody id="wk-body"></tbody>
      </table>
    </div>
    <p id="wk-empty" class="muted" hidden>이 기간에 센 사용량이 없습니다.</p>
    <p id="wk-cut" class="muted" hidden>표가 상한에서 끊겼습니다 — 기간을 좁히거나 분류로 걸러 보세요.</p>

    <p class="muted">집계 규칙: 사용 = 「사용」 전표가 원장에 남긴 음수 행 + <strong>재고관리를 하지 않는 품목</strong>의
      입고 양수 행. 이동은 사용이 아니며(장소만 바뀝니다), 실사 차이도 사용이 아닙니다.
      취소된 전표는 원장의 반대 부호 행으로 저절로 빠집니다.</p>
  </section>

<script src="/assets/purchase_recon.js?v=<?= (int) filemtime(__DIR__ . '/../../public/assets/purchase_recon.js') ?>"></script>
<?php require __DIR__ . '/shell_bottom.php'; ?>
