<?php
declare(strict_types=1);

/**
 * src/views/purchase_import.php — 엑셀 가져오기 화면 (시안 ⑯ ExcelImport, PRD F-29)
 *
 * 네 걸음: ① 파일 → ② 열 맞추기 → ③ 미리보기(행마다 판정) → ④ 확정.
 * 각 걸음의 결과는 `purchase.js` 가 API 응답을 `textContent` 로 그린다 (PRD S-03).
 *
 * 이 화면에 닿은 것 자체가 `purchase.master.write` 와 **재인증 관문**을 지났다는 뜻이다.
 *
 * ★ 25단계 — 상단에 쌓여 있던 호박색 안내 상자 **일곱 개**를 리드문 한 줄 +
 *   접는 설명(`<details class="help">`) 하나로 줄였다. 폼보다 경고색이 먼저 보이지 않게.
 *   걸음 표시는 공통 `.chip` 넷이고, 파일 고르기는 `.filelabel` 이다.
 *
 * @var array<string,mixed> $user
 */

require __DIR__ . '/shell_top.php';
?>
    <header class="page-head">
      <div class="page-head__text">
        <nav class="crumbs muted" aria-label="위치">구매관리 › 기초정보 › 엑셀 가져오기</nav>
        <h1 class="page-head__title">엑셀 가져오기</h1>
        <p class="page-head__sub">파일 올리기 → 열 맞추기 → 미리보기(행마다 판정) → 확정. 같은 양식은 매핑을 기억해 다음부터 두 걸음으로 끝납니다.</p>
      </div>
      <div class="page-head__actions">
        <button type="button" class="btn btn--quiet" data-help-open>
          <span class="icon" data-icon="help"></span><span>사용 방법</span>
        </button>
      </div>
    </header>

  <p id="msg" class="msg" role="alert" aria-live="polite"></p>

  <div class="p-toolbar">
    <ol class="p-steps">
      <li class="chip is-on" id="p-step-1">1 올리기</li>
      <li class="chip" id="p-step-2">2 열 맞추기</li>
      <li class="chip" id="p-step-3">3 미리보기</li>
      <li class="chip" id="p-step-4">4 결과</li>
    </ol>
    <span id="p-import-info" class="muted"></span>
  </div>

  <!-- 끝난 걸음의 한 줄 요약 + 「수정」 (한 번에 한 걸음만 펼친다) -->
  <div class="p-done" id="p-done-1" hidden>
    <span class="p-done__text" id="p-done-1-text"></span>
    <button class="btn btn--quiet btn--small" type="button" id="p-back-1">수정</button>
  </div>
  <div class="p-done" id="p-done-2" hidden>
    <span class="p-done__text" id="p-done-2-text"></span>
    <button class="btn btn--quiet btn--small" type="button" id="p-back-2">수정</button>
  </div>

  <section class="panel" id="p-file-panel">
    <div class="panel__head panel__head--row">
      <h2 class="panel__title">1 올리기</h2>
    </div>
    <form id="p-import-form" class="form" autocomplete="off">
      <div class="grid-form">
        <label class="field">
          <span class="field__label">가져올 종류</span>
          <select id="p-import-kind">
            <!-- 권장 순서: 브랜드 → 지점 → 창고(장소) → 거래처 → 품목.
                 앞의 것이 없으면 뒤의 것이 가리킬 곳이 없어 오류가 됩니다. -->
            <option value="branches">지점</option>
            <option value="locations">창고 · 장소</option>
            <option value="vendors">거래처</option>
            <option value="items" selected>품목</option>
          </select>
        </label>
        <div class="field">
          <span class="field__label">파일 (xlsx · csv)</span>
          <label class="filelabel">
            <span class="btn btn--quiet">파일 고르기</span>
            <input id="p-import-file" type="file" accept=".xlsx,.csv" required>
            <span class="filelabel__name">고른 파일 없음</span>
          </label>
        </div>
      </div>
      <div class="form-actions">
        <button id="p-import-submit" class="btn" type="submit">올리기</button>
      </div>
    </form>

    <p class="help">권장 순서는 브랜드 → 지점 → 창고 → 거래처 → 품목입니다. 오류 행은 저장하지 않습니다.</p>
  </section>

  <section class="panel" id="p-map-panel" hidden>
    <div class="panel__head panel__head--row">
      <h2 class="panel__title">2 열 맞추기 (엑셀 열 → 우리 칸)</h2>
      <div class="panel__tools">
        <!-- 머리행 고르기 — 이카운트 내보내기처럼 첫 줄이 제목인 파일이 있다.
             바꾸면 **재업로드 없이** 열 목록·표본을 다시 그린다(head_rows 로). -->
        <label class="field field--inline">
          <span class="field__label">머리행</span>
          <select id="p-header-row"></select>
        </label>
        <label class="field field--inline">
          <span class="field__label">저장된 매핑</span>
          <select id="p-map-saved"></select>
        </label>
      </div>
    </div>
    <p id="p-header-note" class="muted"></p>

    <!-- 이카운트 창고 파일에는 **지점 열이 없고**, 지점 파일에는 브랜드 열이 없다.
         그 둘을 화면에서 정한다. 파일에 해당 열이 매핑돼 있으면 파일 쪽이 이긴다. -->
    <div id="p-defaults" class="panel__tools" hidden>
      <label class="field field--inline" id="p-default-branch-field" hidden>
        <span class="field__label">기본 지점</span>
        <span class="field__row">
          <select id="p-default-branch"><option value="">— 고르세요 —</option></select>
          <button id="p-add-branch" class="btn btn--quiet" type="button">+ 새로 등록</button>
        </span>
      </label>
      <label class="field field--inline" id="p-default-brand-field" hidden>
        <span class="field__label">기본 브랜드</span>
        <span class="field__row">
          <select id="p-default-brand"><option value="">— 고르세요 —</option></select>
          <button id="p-add-brand" class="btn btn--quiet" type="button">+ 새로 등록</button>
        </span>
      </label>
      <button id="p-derive-branches" class="btn btn--quiet" type="button" hidden>
        창고명에서 지점 이름만 뽑기
      </button>
    </div>
    <div id="p-create-missing" class="panel__tools" hidden>
      <label class="check">
        <input id="p-create-branches" type="checkbox">
        <span>창고명에서 <strong>지점</strong>도 함께 만들기 (「플란치과_강남점」 → 지점 「강남점」)</span>
      </label>
      <label class="check">
        <input id="p-create-brands" type="checkbox">
        <span><strong>브랜드</strong>도 함께 만들기 (앞 조각 「플란치과」)</span>
      </label>
    </div>
    <!-- 품목 파일의 짝 (9차 보정 2026-09-14) — 이카운트 품목 내보내기는 분류 157개·미등록
         거래처 십여 개를 함께 들고 온다. 이 둘을 먼저 만들지 않으면 8,100행 중 한 행도
         들어가지 않는다. 기본은 **켬** — 그것이 「자동 마이그레이션」의 기본 상태다. -->
    <div id="p-create-items" class="panel__tools" hidden>
      <label class="check">
        <input id="p-create-categories" type="checkbox" checked>
        <span>파일의 <strong>분류</strong>를 함께 등록</span>
      </label>
      <label class="check">
        <input id="p-create-vendors" type="checkbox" checked>
        <span>없는 <strong>거래처</strong>를 함께 등록</span>
      </label>
    </div>
    <p id="p-create-items-help" class="help" hidden>이카운트 파일의 대·중·소분류와 새 거래처를 확정과 같은 순간에 만듭니다.
      거래처 이름이 비어 있는 행과, 같은 이름의 거래처가 둘 이상인 행은 만들지 않고 확인을 기다립니다.</p>
    <p id="p-defaults-note" class="muted"></p>

    <div class="table-wrap">
      <table class="files">
        <thead>
          <tr>
            <th scope="col">엑셀 열</th>
            <th scope="col">표본 값</th>
            <th scope="col">우리 칸</th>
          </tr>
        </thead>
        <tbody id="p-map-body"></tbody>
      </table>
    </div>
    <div class="table-foot">
      <label class="field field--inline">
        <span class="field__label">매핑 이름</span>
        <input id="p-map-name" type="text" maxlength="80" placeholder="이카운트 품목 v1">
      </label>
      <div class="pager">
        <button id="p-map-save" class="btn btn--quiet" type="button">매핑 저장</button>
        <button id="p-map-preview" class="btn" type="button">미리보기</button>
      </div>
    </div>
  </section>

  <!-- 3 미리보기 — 2 걸음부터 좌측에 「열 맞추기 요약」이 남는다 (시안 ⑯) -->
  <div class="split" id="p-preview-split" hidden>
    <aside class="panel" id="p-summary-panel" aria-label="열 맞추기 요약">
      <div class="panel__head panel__head--row">
        <h2 class="panel__title">열 맞추기 요약</h2>
      </div>
      <dl class="p-facts" id="p-summary"></dl>
      
    </aside>

    <section class="panel" id="p-preview-panel">
      <div class="panel__head panel__head--row">
        <h2 class="panel__title">3 미리보기</h2>
        <div class="panel__tools" id="p-preview-counts"></div>
      </div>

      <!-- 저장된 매핑이 그대로 맞아 2 걸음을 건너뛴 경우의 안내 (9차 보정).
           건너뛴 것을 숨기지 않는다 — 무엇이 적용됐는지 말하고, 바로 열어 볼 길을 준다. -->
      <p id="p-auto-note" class="muted" hidden>
        <span id="p-auto-text"></span>
        <button id="p-show-mapping" class="btn btn--quiet btn--small" type="button">열 맞추기 보기</button>
      </p>

      <div class="p-toolbar" id="p-toolbar">
        <div class="p-filters">
          <div class="p-chipbar" id="p-filter-chips"></div>
          <label class="field field--inline">
            <span class="field__label">찾기</span>
            <input id="p-row-q" type="search" maxlength="100" placeholder="코드·이름 일부">
          </label>
        </div>
        <div class="p-toolbar__actions">
          <button id="p-promote-all" class="btn btn--quiet" type="button" hidden>확인 필요 모두 승격</button>
          <button id="p-errors-download" class="btn btn--quiet" type="button" hidden>오류 행 내려받기</button>
          <button id="p-commit" class="btn" type="button">확정</button>
        </div>
      </div>

      <!-- 등록되지 않은 값 때문에 막힌 행들을 값 기준으로 묶어 보여 주고, 그 자리에서
           거래처·지점·분류·브랜드를 만들 수 있게 한다(만들면 자동으로 다시 판정한다). -->
      <details class="help" id="p-missing-box" hidden>
        <summary id="p-missing-summary">등록되지 않은 값</summary>
        <div id="p-missing"></div>
      </details>
      <!-- 확정으로 **새로 생길 것**(브랜드·지점 / 분류·거래처). 개수는 배지로 위에 먼저 보이고,
           이름은 여기 접어 둔다 — 157개짜리 목록이 확정 단추를 아래로 밀지 않게. -->
      <details class="help" id="p-tocreate-box" hidden>
        <summary id="p-tocreate-summary">새로 만들 기초정보</summary>
        <div id="p-tocreate"></div>
      </details>

      <div class="table-wrap">
        <table class="files">
          <thead>
            <tr>
              <th scope="col" class="p-col-check"><span class="sr-only">저장할 행 고르기</span></th>
              <th scope="col">행</th>
              <th scope="col">판정</th>
              <th scope="col">브랜드</th>
              <th scope="col">지점</th>
              <th scope="col">사유</th>
              <th scope="col">내용</th>
            </tr>
          </thead>
          <tbody id="p-preview-body"></tbody>
        </table>
      </div>
      <div class="table-foot">
        <span id="p-row-info" class="muted"></span>
        <span id="p-excluded-note" class="muted"></span>
        <div class="pager">
          <button id="p-row-prev" class="btn btn--quiet btn--small" type="button">‹ 이전</button>
          <button id="p-row-next" class="btn btn--quiet btn--small" type="button">다음 ›</button>
        </div>
      </div>
    </section>
  </div>

  <section class="panel" id="p-result-panel" hidden>
    <div class="panel__head panel__head--row">
      <h2 class="panel__title">4 결과</h2>
    </div>
    <p id="p-result" class="muted"></p>
  </section>

<script src="/assets/purchase_common.js?v=<?= (int) filemtime(__DIR__ . '/../../public/assets/purchase_common.js') ?>"></script>
<script src="/assets/purchase.js?v=<?= (int) filemtime(__DIR__ . '/../../public/assets/purchase.js') ?>"></script>
<?php require __DIR__ . '/shell_bottom.php'; ?>
