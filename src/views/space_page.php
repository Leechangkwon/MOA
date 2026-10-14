<?php
declare(strict_types=1);
require_once __DIR__.'/../Design.php';
$e=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
?>
<!doctype html><html lang="ko"<?= Http::themeAttr() ?><?= Design::attrs() ?><?= isset($user)&&($user['high_contrast']??false)?' data-contrast="high"':'' ?>>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>우리 공간 · 모아</title>
<link rel="stylesheet" href="<?= Http::url('/assets/app.css') ?>?v=<?= filemtime(__DIR__.'/../../public/assets/app.css') ?>">
<?= Design::assets() ?>
<link rel="stylesheet" href="<?= Http::url('/assets/space-page.css') ?>?v=<?= filemtime(__DIR__.'/../../public/assets/space-page.css') ?>">
<link rel="stylesheet" href="<?= Http::url('/assets/dashboard-canvas.css') ?>?v=<?= filemtime(__DIR__.'/../../public/assets/dashboard-canvas.css') ?>">
<script defer src="<?= Http::url('/assets/dashboard-canvas.js') ?>?v=<?= filemtime(__DIR__.'/../../public/assets/dashboard-canvas.js') ?>"></script>
<link rel="stylesheet" href="<?= Http::url('/assets/dashboard-widgets.css') ?>?v=<?= filemtime(__DIR__.'/../../public/assets/dashboard-widgets.css') ?>"><script defer src="<?= Http::url('/assets/dashboard-widgets.js') ?>?v=<?= filemtime(__DIR__.'/../../public/assets/dashboard-widgets.js') ?>"></script>
<script defer src="<?= Http::url('/assets/space-page.js') ?>?v=<?= filemtime(__DIR__.'/../../public/assets/space-page.js') ?>"></script>
</head><body><main class="space-layout" id="space-app" data-base="<?= $e(rtrim(Http::url('/'),'/')) ?>" data-community-base="<?= $e(COMMUNITY_BASE) ?>" data-cafe-js="<?= Http::url('/assets/community/cafe.js') ?>?v=<?= filemtime(__DIR__.'/../../public/assets/community/cafe.js') ?>">
<nav class="toolbar space-breadcrumb" aria-label="모아 홈"><a href="/">내 홈으로</a><a href="<?= Http::url('/') ?>">자료실 열기</a></nav>
<?php /* 카페 C2 (DESIGN 2.29 「「우리 공간」 화면」 · 기획 CF-5) — 맨 위 카페 홈(사내 전체 하나) · 그 아래 「내 공간」(회사 · 모임 공간 그대로).
         내용(게시판 카드 · 새 글 · 인기 글)은 space-page.js 가 `GET /community/api/cafe`(읽을 수 있는 카페 게시판만 — 창구의 SQL WHERE)의 답으로
         createElement · textContent 로 그린다. 「게시판 만들어 주세요」는 창구가 준 can_request_board 가 참일 때만 보인다(창구가 다시 막는다).
         고정 마크업 · 동적 값 0(위 data-* 셋은 코드 상수 · 파일 시각). */ ?>
<section class="cafe-home" id="cafe-home" aria-labelledby="cafe-home-title">
<header><h2 id="cafe-home-title">카페</h2><button type="button" class="btn btn--quiet" id="cafe-request" aria-haspopup="dialog" hidden>게시판 만들어 주세요</button></header>
<p id="cafe-status" class="help" role="status" aria-live="polite">카페를 불러오는 중입니다.</p>
<div id="cafe-boards" class="cafe-boards"></div>
<div id="cafe-feed" class="cafe-feed" hidden>
<section aria-labelledby="cafe-recent-title"><h3 id="cafe-recent-title">새 글</h3><ul id="cafe-recent"></ul><p id="cafe-recent-empty" class="help" hidden>아직 글이 없어요 — 첫 글을 써 보세요</p></section>
<section aria-labelledby="cafe-popular-title"><h3 id="cafe-popular-title">인기 글</h3><ul id="cafe-popular"></ul><p id="cafe-popular-empty" class="help" hidden>아직 글이 없어요 — 첫 글을 써 보세요</p></section>
</div>
</section>
<h2 id="space-mine-title">내 공간</h2>
<header><h1 id="space-title">우리 공간</h1></header>
<div class="toolbar space-primary-tools"><label class="field"><span>참여 중인 공간</span><select class="input" id="space-picker" disabled></select></label><div id="space-controls" class="toolbar"><button type="button" class="btn" id="space-register" hidden>등록하기</button><button type="button" class="btn btn--quiet" id="space-menu" disabled>공간 메뉴</button></div></div>
<p id="space-status" class="help" role="status" aria-live="polite">공간을 불러오는 중입니다.</p><div id="space-cards" class="space-cards"></div>
</main><?php require __DIR__.'/design_controls.php'; ?><dialog id="space-dialog" class="space-dialog" aria-label="공간 편집"></dialog></body></html>
