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
<script defer src="<?= Http::url('/assets/space-page.js') ?>?v=<?= filemtime(__DIR__.'/../../public/assets/space-page.js') ?>"></script>
</head><body><main class="space-layout" id="space-app" data-base="<?= $e(rtrim(Http::url('/'),'/')) ?>">
<nav class="toolbar" aria-label="모아 홈"><a class="btn btn--quiet" href="/">내 홈으로</a><a class="btn btn--quiet" href="<?= Http::url('/') ?>">자료실 열기</a></nav>
<header><p class="help">함께하는 모든 순간, 모아.</p><h1 id="space-title">우리 공간</h1><p>회사의 일과 모임의 활동을 각 공간에서 이어가세요.</p></header>
<div class="toolbar"><label class="field"><span>참여 중인 공간</span><select class="input" id="space-picker" disabled></select></label><button type="button" class="btn" id="space-create">새 공간 만들기</button><button type="button" class="btn btn--quiet" id="space-join">초대로 참여하기</button><button type="button" class="btn btn--quiet" id="space-refresh">새로 불러오기</button></div>
<p id="space-status" class="help" role="status" aria-live="polite">공간을 불러오는 중입니다.</p><div id="space-controls" class="toolbar"></div><div id="space-cards" class="space-cards"></div>
</main><?php require __DIR__.'/design_controls.php'; ?><dialog id="space-dialog" class="space-dialog" aria-label="공간 편집"></dialog></body></html>
