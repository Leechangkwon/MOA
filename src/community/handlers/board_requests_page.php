<?php
declare(strict_types=1);

/**
 * src/community/handlers/board_requests_page.php — GET /community/board-requests (정책: role:Admin — 15분 재인증이 저절로 따라붙는다)
 *
 * 요구사항: 사이트 Q:F-12 카페 C2 · DESIGN.md 2.29 「게시판 요청」 · 2.4 표(행 동작 `td.actions`) · 2.7 모달 · 6장,
 *           커뮤니티 ARCHITECTURE 25.7(창구 `GET /api/board-requests` · `POST /api/board-requests/{id}/decide`).
 *
 * 뼈대만 그린다 — 탭(열림 · 만듦 · 반려) · 표(이름 · 쓰임 · 요청한 사람 · 시각 · 상태 · 행 동작 「만들기」 · 「반려」).
 * 목록과 결정은 `assets/community/cafe.js`(initBoardRequests)가 창구의 답으로 그린다 — 만들기는 창구가 게시판 만들기 공용 함수로(area=cafe).
 * ★ **이 화면은 판단하지 않는다** — 라우트 정책(role:Admin + 재인증)과 창구 `decideReportManage` 가 다시 막는다. HTML 에 나가는 동적 값 0.
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params
 */

$pageTitle   = '게시판 요청';
$activeNav   = 'community-board-requests';
$pageKey     = 'community-board-requests';
$pageHelp    = 'community-board';
$pageCss     = ['community/community.css'];
$shellModule = 'community';
$shellArea   = 'cafe';
$pagePalette = community_palette_version();
require __DIR__ . '/../../views/shell_top.php';
?>
    <header class="page-head">
      <div class="page-head__text">
        <h1 class="page-head__title">게시판 요청</h1>
        <p class="page-head__sub">직원이 보낸 「게시판 만들어 주세요」 요청이에요. 만들면 「우리 공간」 카페에 게시판이 생기고 요청한 사람에게 알림이 가요.</p>
      </div>
    </header>

    <p id="msg" class="msg" role="alert" aria-live="polite"></p>

    <div id="requests-tabs"></div>

    <div class="table-wrap">
      <table class="files">
        <thead>
          <tr><th>이름</th><th>쓰임</th><th>요청한 사람</th><th>시각</th><th>상태</th><th>동작</th></tr>
        </thead>
        <tbody id="requests-body"></tbody>
      </table>
    </div>
    <p id="requests-empty" class="muted" hidden>열린 요청이 없습니다.</p>
<?php
$pageMsgTop = true;
require __DIR__ . '/../../views/shell_bottom.php';
