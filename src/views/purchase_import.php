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
    <strong>창고 파일 하나로 브랜드·지점·창고를 한 번에</strong> 넣을 수 있습니다 —
    창고명이 「플란치과_강남점」처럼 <code>브랜드_지점</code> 꼴이면 2단계의 체크 둘을 켜 두세요.
    여러 지점의 창고가 섞여 있어도 <strong>각자의 지점으로</strong> 들어갑니다.
    「기본 지점」은 <strong>지점을 알아낼 수 없는 창고</strong>(예 「중앙창고」)에만 쓰입니다.
  </p>
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
    <li class="p-step is-on" id="p-step-1">1 올리기</li>
    <li class="p-step" id="p-step-2">2 열 맞추기</li>
    <li class="p-step" id="p-step-3">3 미리보기</li>
    <li class="p-step" id="p-step-4">4 결과</li>
  </ol>

  <!-- 6차 보정 2026-09-13 (사용자 결정 「순차적으로 진행하도록 페이지네이션」) —
       한 번에 **한 단계 패널만** 보인다. 끝난 단계는 한 줄 요약 + 「수정」 으로 접힌다. -->
  <div class="p-done" id="p-done-1" hidden>
    <span class="p-done__text" id="p-done-1-text"></span>
    <button class="btn btn--quiet btn--small" type="button" id="p-back-1">수정</button>
  </div>
  <div class="p-done" id="p-done-2" hidden>
    <span class="p-done__text" id="p-done-2-text"></span>
    <button class="btn btn--quiet btn--small" type="button" id="p-back-2">수정</button>
  </div>

  <section class="panel" id="p-file-panel">
    <div class="panel__head"><h2 class="panel__title">1 올리기</h2></div>
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
        <!-- 3차 보정 — 지점이 없으면 여기서 바로 만든다(기초정보 화면으로 건너가지 않는다) -->
        <button id="p-add-branch" class="btn btn--quiet btn--small" type="button">+ 새로 등록</button>
      </label>
      <label class="field field--inline" id="p-default-brand-field" hidden>
        <span class="field__label">기본 브랜드</span>
        <select id="p-default-brand"><option value="">— 고르세요 —</option></select>
        <button id="p-add-brand" class="btn btn--quiet btn--small" type="button">+ 새로 등록</button>
      </label>
      <button id="p-derive-branches" class="btn btn--quiet" type="button" hidden>
        창고명에서 지점 이름만 뽑기
      </button>
    </div>
    <!-- 5차 보정 2026-09-13 (사용자 결정 「창고명을 다수 올리는 건 여러 지점이 한 번에
         올라가는 것이다 — 브랜드도 지점도 생성이 필요하다」).
         창고 가져오기 **한 흐름**에서 없는 브랜드·지점을 함께 만든다. 무엇이 생기는지는
         미리보기의 「새로 만들 …」 요약에서 확인한 뒤 확정한다. -->
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
    <!-- 6차 보정 — **상단 고정 도구줄**. 표가 아무리 길어도 요약과 「확정」 이 늘 눈에 있다
         (사용자 관찰 「확정은 맨 아래에 나온다」). 폰 폭에서는 두 줄로 접힌다. -->
    <div class="p-toolbar" id="p-toolbar">
      <div class="panel__tools" id="p-preview-counts"></div>
      <div class="p-toolbar__actions">
        <button id="p-promote-all" class="btn btn--quiet btn--small" type="button" hidden>확인 필요 모두 승격</button>
        <button id="p-errors-download" class="btn btn--quiet btn--small" type="button" hidden>오류 행 내려받기</button>
        <button id="p-commit" class="btn" type="button">확정</button>
      </div>
    </div>

    <div class="panel__head">
      <h2 class="panel__title">3 미리보기</h2>
    </div>

    <!-- 3차 보정 2026-09-13 (사용자 결정 「접근한 페이지에서 팝업으로 등록」) —
         등록되지 않은 값 때문에 막힌 행들을 **값 기준으로 묶어** 보여 주고, 그 자리에서
         거래처·지점·분류·브랜드를 만들 수 있게 한다. 만들면 **자동으로 다시 판정**한다
         (파일을 다시 올리지 않는다). 서버 등록 창구는 기초정보와 같은 것을 쓴다. -->
    <details class="notice" id="p-missing-box" hidden>
      <summary id="p-missing-summary">등록되지 않은 값</summary>
      <div id="p-missing"></div>
    </details>

    <!-- 5차 보정 — 이번 확정으로 **새로 생길** 브랜드·지점. 이름 목록을 펼쳐 볼 수 있다. -->
    <details class="notice" id="p-tocreate-box" hidden>
      <summary id="p-tocreate-summary">새로 만들 브랜드·지점</summary>
      <div id="p-tocreate"></div>
    </details>
    <!-- 6차 보정 — 상태 필터·검색. 거르기는 **서버가** 한다(50행만 들고 거르면 「오류만 보기」가
         그 50행 안에서만 동작해 사람을 속인다). -->
    <div class="p-filters">
      <div class="p-chipbar" id="p-filter-chips"></div>
      <label class="field field--inline">
        <span class="field__label">찾기</span>
        <input id="p-row-q" type="search" maxlength="100" placeholder="코드·이름 일부">
      </label>
    </div>

    <div class="table-wrap">
      <table class="files">
        <thead>
          <tr>
            <!-- 3차 보정 — 행마다 「저장할까」 체크. 기본은 전체 켜짐이고, 끄면 그 행만 빠집니다. -->
            <th scope="col"><span class="sr-only">저장</span>저장</th>
            <th scope="col">행</th>
            <th scope="col">판정</th>
            <!-- 2차 보정 — 창고가 **어느 지점으로** 들어가는지, 무엇을 보고 정했는지
                 5차 보정 — 새로 만들 브랜드·지점이면 그 사실도 함께 -->
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
      <div class="pager">
        <button id="p-row-prev" class="btn btn--quiet btn--small" type="button">‹ 이전</button>
        <button id="p-row-next" class="btn btn--quiet btn--small" type="button">다음 ›</button>
      </div>
    </div>
    <p id="p-excluded-note" class="muted"></p>
  </section>

  <section class="panel" id="p-result-panel" hidden>
    <div class="panel__head"><h2 class="panel__title">4 결과</h2></div>
    <p id="p-result" class="muted"></p>
  </section>

<script src="/assets/purchase.js?v=<?= (int) filemtime(__DIR__ . '/../../public/assets/purchase.js') ?>"></script>
<?php require __DIR__ . '/shell_bottom.php'; ?>
