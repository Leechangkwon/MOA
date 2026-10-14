<?php
declare(strict_types=1);

/**
 * src/community/handlers/cafe_page.php — GET /community/cafe (정책: community.read · 사이트 H3)
 *
 * 요구사항: DESIGN.md 2.29 「「우리 공간」 = 카페」 줄(2026-10-14 사용자 지적 「여기 내 공간은 비활성화하고, 바로 카페로 넘어가지게 하자」) ·
 *           2.29 카페 홈 ①②(게시판 카드 줄 · 새 글 · 인기 글) · 「카페 셸」.
 *
 * 「우리 공간」으로 가는 모든 길(앱 판 타일 · 홈 바로가기 · 카페 사이드바 「카페 홈」 · 셸 브랜드)이 여기로 온다. 카페 셸(`$shellArea = 'cafe'` —
 * `<body data-area="cafe">` · 카페 사이드바) 안에 카페 홈 뼈대만 그린다 — 머리 h1 「우리 공간」 + 「게시판 만들어 주세요」(hidden — 창구가 준
 * can_request_board 가 참일 때만 cafe.js 가 연다). 내용은 `assets/community/cafe.js`(initHome — 옛 space-page.js 의 그리기를 옮긴 한 벌)가
 * 창구 `GET /community/api/cafe`(읽을 수 있는 카페 게시판만 — 창구의 SQL WHERE)의 답으로 createElement · textContent 로 그린다.
 * ★ 이 화면은 판단하지 않는다 · HTML 에 나가는 동적 값 0(셸의 값 밖). 「내 공간」(회사 · 모임 공간) 묶음은 그리지 않는다 — 옛 `/tbox/spaces?space=<id>` 는 그대로 열린다.
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params
 */

$pageTitle   = '우리 공간';
$activeNav   = 'community-cafe';
$pageKey     = 'community-cafe';
$pageHelp    = 'community-board';
$pageCss     = ['community/community.css'];
$shellModule = 'community';
$shellArea   = 'cafe';
$pagePalette = community_palette_version();
require __DIR__ . '/../../views/shell_top.php';
?>
    <section class="cafe-home" id="cafe-home" aria-labelledby="cafe-home-title">
      <header><h1 id="cafe-home-title">우리 공간</h1><button type="button" class="btn btn--quiet" id="cafe-request" aria-haspopup="dialog" hidden>게시판 만들어 주세요</button></header>
      <p id="cafe-status" class="help" role="status" aria-live="polite">카페를 불러오는 중입니다.</p>
      <div id="cafe-boards" class="cafe-boards"></div>
      <div id="cafe-feed" class="cafe-feed" hidden>
        <section aria-labelledby="cafe-recent-title"><h2 id="cafe-recent-title">새 글</h2><ul id="cafe-recent"></ul><p id="cafe-recent-empty" class="help" hidden>아직 글이 없어요 — 첫 글을 써 보세요</p></section>
        <section aria-labelledby="cafe-popular-title"><h2 id="cafe-popular-title">인기 글</h2><ul id="cafe-popular"></ul><p id="cafe-popular-empty" class="help" hidden>아직 글이 없어요 — 첫 글을 써 보세요</p></section>
      </div>
    </section>
    <p id="msg" class="msg" role="alert" aria-live="polite"></p>
<?php
require __DIR__ . '/../../views/shell_bottom.php';
