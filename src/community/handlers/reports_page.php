<?php
declare(strict_types=1);

/**
 * src/community/handlers/reports_page.php — GET /community/reports (정책: role:Admin — 15분 재인증이 저절로 따라붙는다)
 *
 * 요구사항: 사이트 Q:F-12 카페 C2 · DESIGN.md 2.29 「신고함(관리자)」 · 2.4 표 · 2.21 칸 · 2.7 모달 · 6장,
 *           커뮤니티 ARCHITECTURE 25.5(창구 `GET /api/reports` · `POST /api/reports/{id}/resolve` · `POST /api/reports/{id}/reveal`).
 *
 * 뼈대만 그린다 — 탭(열림 · 숨김 · 반려) · 표(대상 · 사유 · 신고자 · 신고 수 · 시각 · 상태) · 오른쪽 칸(`aside.task-pane` — 2.21 칸 한 벌).
 * 목록 · 처리 · 작성자 확인은 `assets/community/cafe.js`(initReports)가 창구의 답으로 그린다.
 * ★ **이 화면은 판단하지 않는다** — 들어오는 문은 라우트 정책(role:Admin + 재인증)이 지키고, 창구가 요청마다 `decideReportManage` ·
 *   `decideAnonReveal` 로 다시 판정한다. HTML 에 나가는 동적 값 0(셸의 다섯 값 밖). 셸은 「우리 공간」 모드(`$shellArea = 'cafe'`).
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params
 */

$pageTitle   = '신고함';
$activeNav   = 'community-reports';
$pageKey     = 'community-reports';
$pageHelp    = 'community-board';
$pageCss     = ['community/community.css'];
$shellModule = 'community';
$shellArea   = 'cafe';
$pagePalette = community_palette_version();
require __DIR__ . '/../../views/shell_top.php';
?>
    <header class="page-head">
      <div class="page-head__text">
        <h1 class="page-head__title">신고함</h1>
        <p class="page-head__sub">카페 글 · 댓글에 들어온 신고를 처리해요. 익명게시판의 쓴 사람은 이름표로만 보이고, 「작성자 확인」은 감사 기록에 남아요.</p>
      </div>
    </header>

    <p id="msg" class="msg" role="alert" aria-live="polite"></p>

    <!-- 탭 — cafe.js 가 `.db-viewbar` · `.db-type`(2.21 활동 탭과 같은 모양)으로 채운다 -->
    <div id="reports-tabs"></div>

    <div class="table-wrap">
      <table class="files">
        <thead>
          <tr><th>대상</th><th>사유</th><th>신고자</th><th>신고 수</th><th>시각</th><th>상태</th></tr>
        </thead>
        <tbody id="reports-body"></tbody>
      </table>
    </div>
    <p id="reports-empty" class="muted" hidden>열린 신고가 없습니다.</p>

    <!-- 행을 누르면 오른쪽 칸(2.21 `aside.task-pane` 한 벌) — 대상 미리보기 · 쓴 사람(익명은 이름표) · 신고 목록 · 메모(필수) · 숨기기 · 반려 · 작성자 확인 -->
    <aside id="report-pane" class="task-pane" aria-label="신고 처리" hidden></aside>
<?php
$pageMsgTop = true;
require __DIR__ . '/../../views/shell_bottom.php';
