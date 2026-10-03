<?php
declare(strict_types=1);
$pageTitle='공유·연동 설정';$activeNav='community-home';$pageKey='community-home';$pageHelp='community-integrations';$pageCss=['community/community.css'];$shellModule='community';$pagePalette=community_palette_version();
require __DIR__.'/../../views/shell_top.php';
?>
<header class="page-head"><div class="page-head__text"><h1 class="page-head__title">공유·연동 설정</h1><p class="page-head__sub">모음과 권한 그룹을 정리하고, 공개 중인 페이지와 알림 연결을 관리합니다.</p></div></header>
<div id="integrations-settings" data-admin="<?= Authz::authorize('role:Admin',$user)?'1':'0' ?>"></div>
<?php require __DIR__.'/../../views/shell_bottom.php'; ?>
