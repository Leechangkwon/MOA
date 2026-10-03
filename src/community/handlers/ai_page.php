<?php
declare(strict_types=1);
$pageTitle='문서 AI';$activeNav='community-ai';$pageKey='community-ai';
$pageHelp='community-ai';$pageCss=['community/community.css'];$shellModule='community';
$pagePalette=community_palette_version();
require __DIR__.'/../../views/shell_top.php';
?>
<header class="page-head"><div class="page-head__text"><h1 class="page-head__title">문서 AI</h1><p class="page-head__sub">허용한 자료에서 찾아보고, 초안을 확인한 뒤 문서에 넣으세요.</p></div></header>
<div id="community-ai-center"></div>
<?php require __DIR__.'/../../views/shell_bottom.php'; ?>
