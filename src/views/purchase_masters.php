<?php
declare(strict_types=1);

/**
 * src/views/purchase_masters.php — 기초정보 화면의 마크업 (시안 ⑭ Masters, PRD F-28)
 *
 * 공통 셸(`shell_top.php` · `shell_bottom.php`)을 그대로 쓴다 — 상단바·좌측 내비·사용자 칩이
 * 다른 화면과 같은 한 벌이다. 이 파일이 하는 일은 **본문 틀**을 놓는 것뿐이고,
 * 표와 폼은 `purchase.js` 가 `/api/purchase/*` 응답을 `textContent` 로만 채운다 (PRD S-03).
 *
 * ★ 서버가 HTML 에 넣는 동적 값이 **하나도 없다** (셸의 사용자명·역할 제외).
 * ★ 인라인 <script>·style 속성·on* 이벤트 속성을 쓰지 않는다 (CSP: script-src 'self').
 *
 * ★ 25단계 — 상단의 호박색 안내 상자를 표 아래 얇은 설명 줄(`.help`)로 옮겼다.
 *   「만들기」 단추는 그리드 칸을 가득 채우지 않고 `.form-actions` 안 오른쪽에 선다.
 *   머리말의 「엑셀 가져오기」 링크도 뺐다 — 화면 사이를 옮겨 다니는 일은 **사이드바 한 곳**이
 *   맡는다(내비가 두 벌이 되지 않게). 그 화면은 사이드바 「기초정보 › 가져오기」에 있다.
 *
 * @var array<string,mixed> $user
 */

require __DIR__ . '/shell_top.php';
?>
    <header class="page-head">
      <div class="page-head__text">
        <nav class="crumbs muted" aria-label="위치">구매관리 › 기초정보</nav>
        <h1 class="page-head__title">기초정보</h1>
        <p class="page-head__sub">브랜드 → 지점 → 장소(용도), 거래처, 분류, 품목, 지점별 인가량. 전표는 여기 없는 값을 쓸 수 없습니다.</p>
      </div>
    </header>

  <p id="msg" class="msg" role="alert" aria-live="polite"></p>

  <nav class="tabs" id="p-tabs" role="tablist" aria-label="기초정보 종류">
    <button class="tab is-on" type="button" role="tab" aria-selected="true" data-kind="brands">브랜드</button>
    <button class="tab" type="button" role="tab" aria-selected="false" data-kind="branches">지점</button>
    <button class="tab" type="button" role="tab" aria-selected="false" data-kind="locations">장소</button>
    <button class="tab" type="button" role="tab" aria-selected="false" data-kind="vendors">거래처</button>
    <button class="tab" type="button" role="tab" aria-selected="false" data-kind="categories">분류</button>
    <button class="tab" type="button" role="tab" aria-selected="false" data-kind="par-levels">인가량</button>
  </nav>

  <section class="panel">
    <div class="panel__head panel__head--row">
      <h2 class="panel__title" id="p-list-title">브랜드</h2>
      <div class="panel__tools">
        <label class="field field--inline">
          <span class="field__label">이름 검색</span>
          <input id="p-q" type="search" maxlength="100" autocomplete="off" spellcheck="false">
        </label>
        <label class="check">
          <input id="p-inactive" type="checkbox">
          <span>중단 포함</span>
        </label>
      </div>
    </div>

    <div class="table-wrap">
      <table class="files">
        <thead><tr id="p-head"></tr></thead>
        <tbody id="p-body"></tbody>
      </table>
    </div>
    <p id="p-empty" class="empty" hidden>표시할 값이 없습니다.</p>

    <div class="table-foot">
      <span id="p-page-info" class="muted"></span>
      <div class="pager">
        <button id="p-prev" class="btn btn--quiet btn--small" type="button">이전</button>
        <button id="p-next" class="btn btn--quiet btn--small" type="button">다음</button>
      </div>
    </div>
    <p class="help">
      기초정보는 <strong>지우지 않습니다</strong>. 그만 쓰는 것은 「중단」이며, 중단된 값은 새 전표에 쓸 수 없지만 지난 전표에는 그대로 남습니다.
      바뀐 값은 이전·이후가 감사 로그에 남습니다. <strong>장소·인가량은 자기 지점 것만</strong> 보입니다(구매팀·관리자는 전 지점) — 걸러 내는 곳은 서버입니다.
    </p>
  </section>

  <!-- 만들기 폼. 종류마다 필요한 칸이 달라 purchase.js 가 칸을 만들어 넣는다.
       기본은 hidden 이고 `/api/purchase/*` 의 can_edit 이 참일 때만 열린다 — 표시는 편의이고
       막는 것은 라우트 정책 purchase.master.write 와 재인증 관문이다 (PRD S-01). -->
  <section class="panel" id="p-create-panel" hidden>
    <div class="panel__head panel__head--row">
      <h2 class="panel__title" id="p-create-title">새로 만들기</h2>
    </div>
    <form id="p-create-form" class="form" autocomplete="off">
      <div id="p-create-fields" class="grid-form"></div>
      <div class="form-actions">
        <button id="p-create-submit" class="btn" type="submit">만들기</button>
      </div>
    </form>
    <p class="help">
      기초정보 편집에는 <strong>비밀번호 재확인</strong>이 필요합니다(15분). 확인 창이 뜨면
      다시 로그인하는 것이 아니라 지금 계정의 비밀번호를 한 번 더 넣는 것입니다.
    </p>
  </section>

<script src="/assets/purchase_common.js?v=<?= (int) filemtime(__DIR__ . '/../../public/assets/purchase_common.js') ?>"></script>
<script src="/assets/purchase.js?v=<?= (int) filemtime(__DIR__ . '/../../public/assets/purchase.js') ?>"></script>
<?php require __DIR__ . '/shell_bottom.php'; ?>
