<?php
declare(strict_types=1);

/**
 * src/community/handlers/board_view.php — GET /community/b/{num} (정책: community.read)
 *
 * 요구사항: 커뮤니티 PRD 4장 · F-18 · F-26 · F-35 · 9장, 지시서 5-C 「화면」,
 *           `DESIGN.md` 2.2 배지 · 2.4 표 · 2.7 모달 · 2.9 셸 · 6장 체크리스트.
 *
 * 게시판 화면 — 머리(이름·등급 배지·종류·설명·「새 글」) · 도구줄(찾기·태그 거르개·정렬) · 목록 표
 * (제목·쓴 사람·올린 시각·태그·조회수, 「해결됨」·「게시됨」 배지) · 쪽 넘기기.
 * 관리자에게는 머리에 「게시판 관리」(만들기·이름·설명·활성 — 재인증) 창이 더 있다.
 *
 * ★ **이 화면은 판단하지 않는다** (PRD S-01). 게시판을 볼 수 있는지·쓸 수 있는지·관리할 수 있는지는
 *   창구(`GET /api/boards/{num}/pages` 의 `board.can_write`·`can_moderate`, `GET /api/boards` 의
 *   `can_manage`)가 매 요청 판단하고, 화면은 그 답을 그린다. 여기서는 게시판을 읽지도 않는다 —
 *   못 보는 게시판의 이름이 HTML 에 한 글자도 실리지 않는다.
 * ★ HTML 로 나가는 동적 값은 **게시판 번호 하나**이고, 라우터의 `{num}`(숫자 9자리까지)을
 *   지난 값을 정수로 다시 바꿔 넣는다 (PRD S-03).
 * ★ 새 클래스 0 — `.page-head`·`.toolbar`·`.table-wrap table.files`·`.table-foot`·`.pager`·`.badge`·
 *   `.modal--form`·`.field`·`.list` 는 DESIGN 2장의 구성 요소다.
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params [0] = 게시판 번호
 */

$viewBoardId = (int) $params[0];

$pageTitle   = '게시판';
$activeNav   = 'community-board';
$pageKey     = 'community-board';
$pageHelp    = 'community-board';
$pageCss     = ['community/community.css'];
$shellModule = 'community';
$pagePalette = community_palette_version();   // 7-D2 — 셸이 `<link id="palette-css" … ?v=>` 를 그린다(P-6·P-7)
require __DIR__ . '/../../views/shell_top.php';
?>
    <header class="page-head">
      <div class="page-head__text">
        <!-- 이름·설명은 창구가 준 값을 editor.js 가 textContent 로 넣는다. -->
        <h1 id="board-title" class="page-head__title">게시판</h1>
        <p id="board-sub" class="page-head__sub"></p>
      </div>
      <div class="page-head__actions">
        <button type="button" class="btn btn--quiet" data-help-open>
          <span class="icon" data-icon="help"></span><span>사용 방법</span>
        </button>
        <!-- 등급·종류·보관 배지 — 누를 수 없다(DESIGN 2.2). 색은 t-box 등급 배지 그대로다. -->
        <span id="board-level" class="badge" hidden></span>
        <span id="board-kind" class="badge" hidden></span>
        <span id="board-inactive" class="badge badge--off" hidden>보관</span>
        <!-- 「게시판 관리」 — 창구가 준 `can_manage` 가 참일 때만. 창구는 `role:Admin` + 재인증이 다시 막는다. -->
        <button id="board-manage" class="btn btn--quiet" type="button" aria-haspopup="dialog" hidden>
          <span class="icon" data-icon="settings"></span>게시판 관리
        </button>
        <button id="board-new" class="btn" type="button" aria-haspopup="dialog" hidden>
          <span class="icon" data-icon="plus"></span>새 글
        </button>
      </div>
    </header>

    <p id="msg" class="msg" role="alert" aria-live="polite"></p>

    <!-- 도구줄 — 찾기 · 태그 거르개 · 정렬. 거르는 일은 **서버가 SQL 로** 한다(`?q=&tag=&sort=`).
         태그 칸의 추천은 `<datalist>`(브라우저 기능) — 떠 있는 목록을 새로 만들지 않는다(DESIGN 2.8 ★). -->
    <div class="toolbar">
      <input id="board-search" type="search" placeholder="제목·내용으로 찾기" maxlength="100"
             autocomplete="off" spellcheck="false" aria-label="게시판에서 찾기">
      <input id="board-tag" type="search" placeholder="태그로 거르기" maxlength="30"
             autocomplete="off" spellcheck="false" list="board-tag-options" aria-label="태그로 거르기">
      <datalist id="board-tag-options"></datalist>
      <label class="muted" for="board-sort">정렬</label>
      <select id="board-sort" aria-label="정렬">
        <option value="new">최근 올린 순</option>
        <option value="updated">최근 고친 순</option>
        <option value="views">조회수 순</option>
      </select>
    </div>
    <!-- 많이 쓰인 태그 — 누르면 그 태그로 거른다. 단추는 editor.js 가 `.btn--small.btn--quiet` 로 만든다. -->
    <div id="board-tags" class="toolbar" hidden></div>

    <div class="table-wrap">
      <table class="files">
        <thead>
          <tr><th>제목</th><th>쓴 사람</th><th>올린 때</th><th>태그</th><th>조회</th></tr>
        </thead>
        <tbody id="board-body"></tbody>
      </table>
    </div>
    <p id="board-empty" class="muted" hidden>아직 글이 없습니다.</p>
    <div class="table-foot">
      <span id="board-page-info" class="muted" aria-live="polite"></span>
      <div class="pager">
        <button id="board-prev" class="btn btn--small btn--quiet" type="button" disabled>이전</button>
        <button id="board-next" class="btn btn--small btn--quiet" type="button" disabled>다음</button>
      </div>
    </div>

    <!-- 「새 글」 창 (DESIGN 2.7 `.modal--form`). 제목과 태그만 받고, 만든 뒤 그 글을 **편집 모드로** 연다 —
         본문은 페이지 화면의 에디터로 쓴다(저장 창구·검증·판 이력이 한 곳에 모여 있다). -->
    <dialog id="board-new-dialog" class="modal modal--form" aria-labelledby="board-new-title">
      <div class="modal__head">
        <h2 id="board-new-title" class="modal__title">새 글</h2>
        <button class="btn btn--icon btn--plain" type="button" data-board-new-close aria-label="닫기">
          <span class="icon" data-icon="x"></span>
        </button>
      </div>
      <div class="modal__body">
        <p id="board-new-msg" class="msg" role="alert" aria-live="polite"></p>
        <label class="field field--full">
          <span class="field__label">제목 (필수)</span>
          <input id="board-new-input" type="text" maxlength="200" autocomplete="off" spellcheck="false">
        </label>
        <label class="field field--full">
          <span class="field__label">태그</span>
          <input id="board-new-tags" type="text" maxlength="340" autocomplete="off" spellcheck="false"
                 list="board-tag-options" placeholder="쉼표로 나눠 적습니다 — 예: 안내, 일정">
        </label>
        <p class="help">태그는 10개까지, 하나에 30자까지입니다. 이 게시판에서 쓰인 태그가 추천됩니다.</p>
      </div>
      <div class="form-actions">
        <button class="btn btn--quiet" type="button" data-board-new-close>닫기</button>
        <button id="board-new-save" class="btn" type="button">만들고 쓰기</button>
      </div>
    </dialog>

    <!-- 「게시판 관리」 창 (관리자, DESIGN 2.7 `.modal--form.modal--stack` — 머리·바닥 고정, 본문만 스크롤).
         위: 게시판 목록(누르면 아래 칸이 그 게시판 고치기로 바뀐다) · 아래: 만들기/고치기 칸.
         등급·팀·종류는 **만들 때만** 고른다(PRD 4장 — 바꾸면 이미 쓴 글의 범위가 글쓴이 모르게 바뀐다).
         재인증이 필요하면 창구가 `next:"reauth"` 로 답하고, 셸의 재인증 창이 뜬 뒤 다시 보낸다. -->
    <dialog id="board-manage-dialog" class="modal modal--form modal--stack" aria-labelledby="board-manage-title">
      <div class="modal__head">
        <h2 id="board-manage-title" class="modal__title">게시판 관리</h2>
        <button class="btn btn--icon btn--plain" type="button" data-board-manage-close aria-label="닫기">
          <span class="icon" data-icon="x"></span>
        </button>
      </div>
      <div class="modal__body">
        <p id="board-manage-msg" class="msg" role="alert" aria-live="polite"></p>
        <ul id="board-manage-list" class="list list--scroll" aria-label="게시판 목록"></ul>
        <p id="board-manage-mode" class="field__label">새 게시판 만들기</p>
        <label class="field field--full">
          <span class="field__label">이름 (필수)</span>
          <input id="board-manage-name" type="text" maxlength="50" autocomplete="off" spellcheck="false">
        </label>
        <label class="field field--full">
          <span class="field__label">설명</span>
          <input id="board-manage-desc" type="text" maxlength="200" autocomplete="off" spellcheck="false">
        </label>
        <label class="field field--full" id="board-manage-level-field">
          <span class="field__label">등급 (만든 뒤에는 바꿀 수 없습니다)</span>
          <select id="board-manage-level">
            <option value="public">공개 — 손님까지 모두</option>
            <option value="internal" selected>사내 — 사내 구성원 모두</option>
            <option value="team">팀 — 고른 팀만</option>
            <option value="admin-only">관리자 — 관리자만</option>
          </select>
        </label>
        <label class="field field--full" id="board-manage-team-field" hidden>
          <span class="field__label">팀 (팀 등급이면 필수)</span>
          <select id="board-manage-team"></select>
        </label>
        <label class="field field--full" id="board-manage-kind-field">
          <span class="field__label">종류 (만든 뒤에는 바꿀 수 없습니다)</span>
          <select id="board-manage-kind">
            <option value="discussion" selected>토론 — 자유롭게 이야기합니다</option>
            <option value="qa">질문과 답변 — 쓴 사람이 「해결됨」을 켭니다</option>
            <option value="notice">공지 — 관리자만 씁니다</option>
          </select>
        </label>
        <label class="field field--full" id="board-manage-active-field" hidden>
          <span class="field__label">
            <input id="board-manage-active" type="checkbox"> 쓰는 중 (끄면 읽기 전용으로 보관합니다)
          </span>
        </label>
      </div>
      <div class="form-actions">
        <button id="board-manage-reset" class="btn btn--quiet" type="button" hidden>새로 만들기로</button>
        <button class="btn btn--quiet" type="button" data-board-manage-close>닫기</button>
        <button id="board-manage-save" class="btn" type="button">만들기</button>
      </div>
    </dialog>

    <!-- 게시판 번호를 전하는 자리. 값은 라우터가 숫자로만 통과시킨 뒤 정수로 다시 바꾼 것이다. -->
    <span id="board-meta" hidden data-board-id="<?= (int) $viewBoardId ?>"></span>
<?php
$pageMsgTop = true;
require __DIR__ . '/../../views/shell_bottom.php';
