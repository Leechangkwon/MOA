<?php
declare(strict_types=1);
require_once __DIR__.'/../Design.php';
$e=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
?>
<!doctype html><html lang="ko"<?= Http::themeAttr() ?><?= Design::attrs() ?><?= isset($user)&&($user['high_contrast']??false)?' data-contrast="high"':'' ?>>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>공간 · 모아</title>
<link rel="stylesheet" href="<?= Http::url('/assets/app.css') ?>?v=<?= filemtime(__DIR__.'/../../public/assets/app.css') ?>">
<?= Design::assets() ?>
<link rel="stylesheet" href="<?= Http::url('/assets/space-page.css') ?>?v=<?= filemtime(__DIR__.'/../../public/assets/space-page.css') ?>">
<link rel="stylesheet" href="<?= Http::url('/assets/dashboard-canvas.css') ?>?v=<?= filemtime(__DIR__.'/../../public/assets/dashboard-canvas.css') ?>">
<script defer src="<?= Http::url('/assets/dashboard-canvas.js') ?>?v=<?= filemtime(__DIR__.'/../../public/assets/dashboard-canvas.js') ?>"></script>
<link rel="stylesheet" href="<?= Http::url('/assets/dashboard-widgets.css') ?>?v=<?= filemtime(__DIR__.'/../../public/assets/dashboard-widgets.css') ?>"><script defer src="<?= Http::url('/assets/dashboard-widgets.js') ?>?v=<?= filemtime(__DIR__.'/../../public/assets/dashboard-widgets.js') ?>"></script>
<script defer src="<?= Http::url('/assets/space-page.js') ?>?v=<?= filemtime(__DIR__.'/../../public/assets/space-page.js') ?>"></script>
</head><body><main class="space-layout" id="space-app" data-base="<?= $e(rtrim(Http::url('/'),'/')) ?>" data-community-base="<?= $e(COMMUNITY_BASE) ?>">
<nav class="toolbar space-breadcrumb" aria-label="모아 홈"><a href="/">모아 홈으로</a><a href="<?= Http::url('/') ?>">자료실 열기</a></nav>
<?php /* 사이트 H3 (DESIGN 2.29 「「우리 공간」 = 카페」) — 카페 홈 · 「내 공간」 묶음은 이 화면에서 빠졌다(카페 = /community/cafe).
         이 화면은 `?space=<id>` 로 연 공간 화면만(product_spaces_page.php — 쿼리가 없으면 302 /community/cafe). 공간 창구 · 판정 · 자료는 그대로. */ ?>
<header><h1 id="space-title">공간</h1></header>
<div class="toolbar space-primary-tools"><label class="field"><span>참여 중인 공간</span><select class="input" id="space-picker" disabled></select></label><div id="space-controls" class="toolbar"><button type="button" class="btn" id="space-register" hidden>등록하기</button><button type="button" class="btn btn--quiet" id="space-menu" disabled>공간 메뉴</button></div></div>
<p id="space-status" class="help" role="status" aria-live="polite">공간을 불러오는 중입니다.</p><div id="space-cards" class="space-cards"></div>
</main><?php require __DIR__.'/design_controls.php'; ?><dialog id="space-dialog" class="space-dialog" aria-label="공간 편집"></dialog></body></html>
