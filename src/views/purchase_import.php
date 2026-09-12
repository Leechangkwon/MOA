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
 * @var array<string,mixed> $user
 */

require __DIR__ . '/shell_top.php';
?>
    <link rel="stylesheet" href="/assets/purchase.css?v=<?= (int) filemtime(__DIR__ . '/../../public/assets/purchase.css') ?>">

    <header class="page-head">
      <div class="page-head__text">
        <h1 class="page-head__title">구매관리 › 기초정보 › 엑셀 가져오기</h1>
        <p class="page-head__sub">파일 올리기 → 열 맞추기 → 미리보기(행마다 판정) → 확정. 같은 양식은 매핑을 기억해 다음부터 두 단계로 끝납니다.</p>
      </div>
      <div class="page-head__actions">
        <a class="btn btn--quiet" href="/purchase/masters">기초정보</a>
      </div>
    </header>

  <p class="notice">
    <strong>권장 순서는 브랜드 → 지점 → 창고 → 거래처 → 품목</strong>입니다. 앞의 것이 없으면
    뒤의 것이 가리킬 곳이 없어 「거래처를 찾을 수 없습니다」 같은 오류가 됩니다.
    이카운트 창고 파일에는 지점 열이 없으므로, <strong>창고명에서 지점 이름을 뽑아</strong>
    지점을 먼저 만들 수 있습니다(2단계의 「창고명에서 지점 이름 뽑기」).
  </p>
  <p class="notice">
    엑셀의 <strong>첫 줄이 곧 열 이름이 아닐 수도 있습니다</strong> — 이카운트 내보내기처럼
    맨 위에 「회사명 : …」 같은 제목 줄이 붙은 파일이 많습니다. 서버가 앞 20줄을 보고
    <strong>열 이름이 적힌 줄을 스스로 고르고</strong>, 2단계의 「머리행」 칸에서 바꿀 수 있습니다.
    바꾸면 열 목록과 표본이 바로 다시 그려집니다(파일을 다시 올릴 필요가 없습니다).
  </p>
  <p class="notice">
    확정은 <strong>한 트랜잭션</strong>입니다 — 한 행이라도 저장에 실패하면 앞서 넣은 행까지 전부
    되돌아갑니다. <strong>오류 행은 저장하지 않습니다.</strong> 올린 원본 파일은
    <strong>「구매 / 가져오기」 폴더(관리자 전용)</strong>에 남고, 확정 기록에 파일의 sha256 과 행 수가 붙습니다.
    <code>.xlsm</code>·<code>.xls</code>·매크로 문서는 업로드 검사에서 거부됩니다.
  </p>

  <ol class="p-steps">
    <li class="p-step is-on" id="p-step-1">1 파일</li>
    <li class="p-step" id="p-step-2">2 열 맞추기</li>
    <li class="p-step" id="p-step-3">3 미리보기</li>
    <li class="p-step" id="p-step-4">4 확정</li>
  </ol>

  <section class="panel">
    <div class="panel__head"><h2 class="panel__title">1 파일</h2></div>
    <form id="p-import-form" class="form form--grid" autocomplete="off">
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
      <label class="field">
        <span class="field__label">파일 (xlsx · csv)</span>
        <input id="p-import-file" type="file" accept=".xlsx,.csv" required>
      </label>
      <button id="p-import-submit" class="btn" type="submit">올리기</button>
    </form>
    <p id="p-import-info" class="muted"></p>
  </section>

  <section class="panel" id="p-map-panel" hidden>
    <div class="panel__head">
      <h2 class="panel__title">2 열 맞추기 (엑셀 열 → 우리 칸)</h2>
      <div class="panel__tools">
        <!-- 머리행 고르기 (1차 보정 2026-09-13) — 이카운트 내보내기처럼 첫 줄이 제목인
             파일이 있다. 서버가 앞 20행을 보고 한 줄을 고르고, 사람이 바꿀 수 있다.
             바꾸면 **재업로드 없이** 열 목록·표본을 다시 그린다(head_rows 로). -->
        <label class="field field--inline">
          <span class="field__label">머리행 (열 이름이 적힌 줄)</span>
          <select id="p-header-row"></select>
        </label>
        <label class="field field--inline">
          <span class="field__label">저장된 매핑</span>
          <select id="p-map-saved"></select>
        </label>
      </div>
    </div>
    <p id="p-header-note" class="muted"></p>

    <!-- 2차 보정 2026-09-13 — 이카운트 창고 파일에는 **지점 열이 없고**, 지점 파일에는
         브랜드 열이 없다. 그 둘을 화면에서 정한다. 파일에 해당 열이 매핑돼 있으면
         파일 쪽이 이긴다(서버의 판정 규칙 ①). -->
    <div id="p-defaults" class="panel__tools" hidden>
      <label class="field field--inline" id="p-default-branch-field" hidden>
        <span class="field__label">기본 지점 (창고에 지점 열이 없을 때)</span>
        <select id="p-default-branch"><option value="">— 고르세요 —</option></select>
      </label>
      <label class="field field--inline" id="p-default-brand-field" hidden>
        <span class="field__label">기본 브랜드</span>
        <select id="p-default-brand"><option value="">— 고르세요 —</option></select>
      </label>
      <button id="p-derive-branches" class="btn btn--quiet" type="button" hidden>
        창고명에서 지점 이름 뽑기
      </button>
    </div>
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
    <div class="panel__foot">
      <label class="field field--inline">
        <span class="field__label">매핑 이름</span>
        <input id="p-map-name" type="text" maxlength="80" placeholder="이카운트 품목 v1">
      </label>
      <button id="p-map-save" class="btn btn--quiet" type="button">매핑 저장</button>
      <button id="p-map-preview" class="btn" type="button">미리보기</button>
    </div>
  </section>

  <section class="panel" id="p-preview-panel" hidden>
    <div class="panel__head">
      <h2 class="panel__title">3 미리보기</h2>
      <div class="panel__tools" id="p-preview-counts"></div>
    </div>
    <div class="table-wrap">
      <table class="files">
        <thead>
          <tr>
            <th scope="col">행</th>
            <th scope="col">판정</th>
            <!-- 2차 보정 — 창고가 **어느 지점으로** 들어가는지, 무엇을 보고 정했는지 -->
            <th scope="col">지점</th>
            <th scope="col">사유</th>
            <th scope="col">내용</th>
          </tr>
        </thead>
        <tbody id="p-preview-body"></tbody>
      </table>
    </div>
    <div class="panel__foot">
      <button id="p-errors-download" class="btn btn--quiet" type="button">오류 목록 내려받기</button>
      <button id="p-commit" class="btn" type="button">확정</button>
    </div>
  </section>

  <section class="panel" id="p-result-panel" hidden>
    <div class="panel__head"><h2 class="panel__title">4 확정 결과</h2></div>
    <p id="p-result" class="muted"></p>
  </section>

<script src="/assets/purchase.js?v=<?= (int) filemtime(__DIR__ . '/../../public/assets/purchase.js') ?>"></script>
<?php require __DIR__ . '/shell_bottom.php'; ?>
