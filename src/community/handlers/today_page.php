<?php
declare(strict_types=1);

$pageTitle = '오늘';
$activeNav = 'community-today';
$pageKey = 'community-today';
$pageHelp = 'community-today';
$pageCss = ['community/community.css'];
$shellModule = 'community';
$pagePalette = community_palette_version();
require __DIR__ . '/../../views/shell_top.php';
?>
<header class="page-head">
  <div class="page-head__text">
    <h1 class="page-head__title">오늘</h1>
    <p class="page-head__sub">먼저 할 일과 어제 놓친 일을 한눈에 확인하세요.</p>
  </div>
  <div class="page-head__actions">
    <button type="button" class="btn btn--quiet" data-help-open>사용 방법</button>
    <button type="button" id="briefing-refresh" class="btn btn--quiet">새로고침</button>
  </div>
</header>
<p id="briefing-msg" class="msg" role="alert"></p>
<p id="briefing-updated" class="muted" role="status" aria-live="polite">할 일을 불러오는 중입니다.</p>
<section class="panel" aria-labelledby="briefing-top-title">
  <div class="panel__head"><h2 id="briefing-top-title" class="panel__title">오늘의 최우선</h2><p class="panel__sub">기한과 검토 상태를 기준으로 최대 3개</p></div>
  <ul id="briefing-top" class="list" aria-label="오늘의 최우선"></ul>
</section>
<section class="panel" aria-labelledby="briefing-missed-title">
  <div class="panel__head"><h2 id="briefing-missed-title" class="panel__title">어제 놓친 것</h2><p class="panel__sub">놓친 기한·댓글·공지 중 최대 2개</p></div>
  <ul id="briefing-missed" class="list" aria-label="어제 놓친 것"></ul>
</section>
<details id="briefing-others-box" class="panel">
  <summary class="panel__head"><span id="briefing-others-title" class="panel__title">그 밖에</span></summary>
  <ul id="briefing-others" class="list" aria-label="그 밖의 할 일"></ul>
</details>
<?php require __DIR__ . '/../../views/shell_bottom.php'; ?>
