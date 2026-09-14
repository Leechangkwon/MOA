<?php
declare(strict_types=1);

/**
 * src/views/purchase_structure.php — 「브랜드·지점·창고」 한 페이지 (24단계, PRD F-37)
 *
 * 한 화면에서 ① 브랜드·지점을 만들고 ② 이카운트 창고 파일을 대기표로 올리고
 * ③ 미연결 창고를 지점에 연결하거나 ④ 제외하고 ⑤ 지점별 결과를 본다.
 *
 * 값은 전부 `purchase_structure.js` 가 `/api/purchase/structure` 응답을 받아
 * `textContent` 로만 채운다 (PRD S-03 — inner/outer HTML 계열을 쓰지 않는다).
 * 서버가 HTML 에 넣는 동적 값은 **하나도 없다**(셸의 사용자명·역할 제외).
 * 인라인 <script>·style 속성·on* 이벤트 속성을 쓰지 않는다 (CSP: script-src 'self').
 *
 * ★ 역할 비교가 한 글자도 없다 (PRD S-01). 이 화면에 닿은 것 자체가 라우트 정책
 *   `purchase.master.write` 와 재인증 관문을 지났다는 뜻이다.
 *
 * ★ 25단계 — 이 화면만 쓰던 `.st-*` 26종을 공통 클래스로 바꿨다(좌 트리 / 우 표 `.split`).
 *   파일 고르기는 `.filelabel`, 긴 표는 제 자리에서 구른다.
 *
 * @var array<string,mixed> $user
 */

require __DIR__ . '/shell_top.php';
?>
    <header class="page-head">
      <div class="page-head__text">
        <nav class="crumbs muted" aria-label="위치">구매관리 › 기초정보 › 브랜드·지점·창고</nav>
        <h1 class="page-head__title">브랜드 · 지점 · 창고</h1>
        <p class="page-head__sub">브랜드와 지점은 여기서 만들고, 창고는 이카운트 파일을 그대로 올린 뒤 지점에 연결합니다. 지점이 없는 창고는 제외하면 됩니다.</p>
      </div>
      <div class="page-head__actions">
        <button type="button" class="btn btn--quiet" data-help-open>
          <span class="icon" data-icon="help"></span><span>사용 방법</span>
        </button>
        <form id="st-upload-form" class="panel__tools">
          <label class="filelabel">
            <span class="btn btn--quiet">창고 파일 고르기</span>
            <input id="st-file" type="file" accept=".xlsx,.csv" required>
            <span class="filelabel__name">고른 파일 없음</span>
          </label>
          <button id="st-upload" class="btn" type="submit">올리기</button>
        </form>
      </div>
    </header>

  <p id="msg" class="msg" role="alert" aria-live="polite"></p>

  <div class="p-chipbar" id="st-counts"></div>

  <div class="split" id="st-split">
    <!-- ── 왼쪽: 브랜드 → 지점 → 연결된 창고 ───────────── -->
    <section class="panel" id="st-tree-panel">
      <div class="panel__head panel__head--row">
        <h2 class="panel__title">브랜드 → 지점</h2>
      </div>
      <div id="st-tree" class="p-tree p-scroll" data-allow-scroll></div>
      <p id="st-tree-empty" class="empty" hidden>브랜드가 아직 없습니다 — 아래에서 만들 수 있습니다.</p>
      <form id="st-brand-form" class="panel__tools" autocomplete="off">
        <label class="field field--inline">
          <span class="field__label">새 브랜드</span>
          <input id="st-brand-name" type="text" maxlength="60" required>
        </label>
        <button class="btn btn--quiet" type="submit">+ 브랜드</button>
      </form>
    </section>

    <!-- ── 오른쪽: 미연결 창고 ──────────────────────────── -->
    <section class="panel" id="st-unlinked-panel">
      <div class="panel__head panel__head--row">
        <h2 class="panel__title" id="st-unlinked-title">미연결 창고</h2>
        <div class="panel__tools">
          <label class="field field--inline">
            <span class="field__label">용도</span>
            <select id="st-kind"></select>
          </label>
          <button id="st-select-all" class="btn btn--quiet" type="button">전체 선택</button>
          <button id="st-link-suggest" class="btn btn--quiet" type="button">추천대로 연결</button>
        </div>
      </div>

      <div class="panel__tools">
        <label class="field field--inline">
          <span class="field__label">선택한 창고를</span>
          <select id="st-bulk-branch"></select>
        </label>
        <button id="st-link-bulk" class="btn" type="button">이 지점에 연결</button>
      </div>

      <div class="table-wrap p-scroll" data-allow-scroll>
        <table class="files">
          <thead>
            <tr>
              <th scope="col" class="p-col-check"><span class="sr-only">선택</span></th>
              <th scope="col">창고코드</th>
              <th scope="col">창고명</th>
              <th scope="col">구분</th>
              <th scope="col">사용</th>
              <th scope="col">지점</th>
              <th scope="col">제외</th>
            </tr>
          </thead>
          <tbody id="st-unlinked-body"></tbody>
        </table>
      </div>
      <p id="st-unlinked-empty" class="empty" hidden>모두 정리됨 — 미연결 창고가 없습니다.</p>
      <p class="help">이카운트 창고 내보내기를 그대로 올린 뒤 지점에 연결합니다.</p>
    </section>
  </div>

  <!-- ── 아래: 제외된 창고 ──────────────────────────────── -->
  <section class="panel" id="st-excluded-panel">
    <div class="panel__head panel__head--row">
      <h2 class="panel__title" id="st-excluded-title">제외된 창고</h2>
      <div class="panel__tools">
        <button id="st-excluded-toggle" class="btn btn--quiet" type="button" aria-expanded="false">펼치기</button>
      </div>
    </div>
    <div id="st-excluded-wrap" hidden>
      <div class="table-wrap">
        <table class="files">
          <thead>
            <tr>
              <th scope="col">창고코드</th>
              <th scope="col">창고명</th>
              <th scope="col">구분</th>
              <th scope="col">작업</th>
            </tr>
          </thead>
          <tbody id="st-excluded-body"></tbody>
        </table>
      </div>
      <p id="st-excluded-empty" class="empty" hidden>제외된 창고가 없습니다.</p>
    </div>
    <p class="help">편집하려면 비밀번호를 다시 확인합니다.</p>
  </section>

<script src="/assets/purchase_common.js?v=<?= (int) filemtime(__DIR__ . '/../../public/assets/purchase_common.js') ?>"></script>
<script src="/assets/purchase_structure.js?v=<?= (int) filemtime(__DIR__ . '/../../public/assets/purchase_structure.js') ?>"></script>
<?php require __DIR__ . '/shell_bottom.php'; ?>
