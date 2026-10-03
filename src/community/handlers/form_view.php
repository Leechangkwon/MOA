<?php
declare(strict_types=1);
$gate=community_form_gate($user,(string)$params[0],(string)$params[1]);
$pageTitle=$gate['view']['name']; $activeNav='community'; $pageKey='community-form';
$pageHelp='community-form'; $pageCss=['community/community.css']; $shellModule='community';
$pagePalette=community_palette_version();
require __DIR__.'/../../views/shell_top.php';
?>
<header class="page-head"><div class="page-head__text"><h1 class="page-head__title"><?= htmlspecialchars($pageTitle,ENT_QUOTES,'UTF-8') ?></h1></div></header>
<section class="panel"><form id="community-response-form" class="form" data-database="<?= htmlspecialchars((string)$params[0],ENT_QUOTES,'UTF-8') ?>" data-view="<?= htmlspecialchars((string)$params[1],ENT_QUOTES,'UTF-8') ?>">
<p id="response-intro" class="muted"></p><p id="response-privacy" class="muted"></p>
<div id="response-questions"></div><p id="response-status" class="msg" role="status" aria-live="polite"></p>
<button id="response-submit" class="btn btn--primary" type="submit">응답 보내기</button>
</form></section>
<script defer src="<?= Http::url('/assets/community/forms.js') ?>?v=<?= (int)filemtime(__DIR__.'/../../../public/assets/community/forms.js') ?>"></script>
<?php require __DIR__.'/../../views/shell_bottom.php'; ?>
