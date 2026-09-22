<?php
declare(strict_types=1);

/**
 * src/views/shell_bottom.php — 공통 셸의 아랫부분 (PRD F-20, ARCH 3장 `src/views/`)
 *
 * 본문을 닫고, 알림 줄 하나(`#msg`)와 **아이콘 <template> 모음**, app.js 를 붙인다.
 *
 * 아이콘을 왜 <template> 으로 두나
 *   CSP 가 `script-src 'self'` 라 인라인 스크립트를 쓸 수 없고, S-03 에 따라 inner/outer HTML 계열도
 *   쓰지 않는다. 그래서 SVG 마크업은 **서버가 만든 HTML** 안에 두고, app.js 는
 *   `template.content.cloneNode(true)` 로 복제해 붙이기만 한다 (문자열 → HTML 변환 0).
 *   이 파일의 SVG 에는 사용자 입력이 한 글자도 들어가지 않는다(전부 고정 문자열).
 *
 * 받는 변수
 *   · $pageMsgTop bool  (25단계) 참이면 **화면이** 알림 줄을 .page-head 바로 아래에 이미
 *                       놓았다는 뜻이라 여기서는 내보내지 않는다. 그 전에는 화면 여섯 개가
 *                       자기 `#msg` 를 따로 두어 한 문서에 같은 id 가 둘이었고(dup_id),
 *                       오류가 페이지 맨 아래에서만 뜨는 화면도 있었다.
 *                       기본값(없거나 false)은 옛 동작 그대로 — 다른 화면은 바뀌지 않는다.
 *   · $pageHelp   string 「사용 방법」 도움말의 **키** (PRD F-38, ARCH 5.19). 화면을 그리는
 *                       핸들러가 `$pageHelp = 'files';` 처럼 한 줄로 넘긴다. 키가
 *                       `src/help/screens.php` 배열에 있으면 아래에서 <dialog id="help-dialog">
 *                       를 **한 번** 그리고, 없거나 오타면 아무것도 그리지 않는다 —
 *                       도움말 키의 오타가 화면을 깨뜨리지 못하게 하는 자리다.
 */

/** 자산 주소의 정수 버전 — filemtime 값이며 이스케이프 대상이 없다 (11단계) */
$shellJsVersion = (int) filemtime(__DIR__ . '/../../public/assets/app.js');

/** 요청 값이 아니다 — 화면을 그리는 핸들러가 include 전에 정하는 참/거짓 하나다. */
$shellMsgTop = isset($pageMsgTop) && $pageMsgTop === true;

/**
 * 「사용 방법」 도움말 (PRD F-38, ARCH 5.19).
 *
 * 글은 `src/help/screens.php` 의 **고정 배열** 한 벌이고, 여기서는 핸들러가 넘긴 키로
 * 그 배열을 찾아보기만 한다. 요청 값·DB·파일 경로 조립이 한 군데도 없다 — 키는 파일 이름이
 * 아니라 **배열의 첨자**라서, 어떤 문자열이 와도 배열에 없으면 그냥 없는 것이다 (S-06 과 무관).
 * 배열에 없으면 `null` 로 두고 아래에서 아무것도 내보내지 않는다.
 */
$shellHelp = null;
if (isset($pageHelp) && is_string($pageHelp) && $pageHelp !== '') {
    $shellHelpAll = require __DIR__ . '/../help/screens.php';
    if (is_array($shellHelpAll) && isset($shellHelpAll[$pageHelp]) && is_array($shellHelpAll[$pageHelp])) {
        $shellHelp = $shellHelpAll[$pageHelp];
    }
}
?>
<?php if (!$shellMsgTop): ?>
    <p id="msg" class="msg" role="alert" aria-live="polite"></p>
<?php endif; ?>
  </main>
</div>

<?php if ($shellHelp !== null): ?>
<!-- ─── 「사용 방법」 도움말 (PRD F-38, ARCH 5.19) ──────────────────────────
     .page-head__actions 의 「사용 방법」 단추(`data-help-open`)가 이것을 연다.
     내용은 `src/help/screens.php` 의 고정 글이고, **모든 문자열이 htmlspecialchars 를
     거친다** (PRD S-03). 이 자리에는 HTML 조각이 들어오지 않는다 — 배열에 담긴 것은
     순수 문자열뿐이라, 글에 `<` 가 섞여 있어도 글자로만 나온다.
     본문(`<main>`) 밖에 둔다: 닫혀 있는 <dialog> 가 화면 문구 검사에 섞이지 않게. -->
<dialog id="help-dialog" class="modal help-dialog" aria-labelledby="help-title">
  <div class="modal__head">
    <h2 id="help-title" class="modal__title"><?= htmlspecialchars((string) ($shellHelp['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?></h2>
    <button class="btn btn--icon btn--plain" type="button" data-help-close aria-label="닫기">
      <span class="icon" data-icon="x"></span>
    </button>
  </div>
  <div class="modal__body">
<?php foreach ((array) ($shellHelp['sections'] ?? []) as $shellHelpSection): ?>
<?php   $shellHelpH = is_array($shellHelpSection) ? (string) ($shellHelpSection['h'] ?? '') : ''; ?>
<?php   if ($shellHelpH !== ''): ?>
    <h3 class="help-dialog__title"><?= htmlspecialchars($shellHelpH, ENT_QUOTES, 'UTF-8') ?></h3>
<?php   endif; ?>
    <ul class="help-dialog__list">
<?php   foreach ((array) ($shellHelpSection['items'] ?? []) as $shellHelpItem): ?>
      <li><?= htmlspecialchars((string) $shellHelpItem, ENT_QUOTES, 'UTF-8') ?></li>
<?php   endforeach; ?>
    </ul>
<?php endforeach; ?>
  </div>
  <div class="modal__foot">
    <button class="btn btn--quiet" type="button" data-help-close>닫기</button>
  </div>
</dialog>
<?php endif; ?>

<?php
/**
 * 「변경 이력」 (PRD F-47, ARCH 5.23).
 *
 * 「사용 방법」과 달리 **조건이 없다** — 셸을 그리는 모든 화면에 사이드바 버전 단추가 있으므로
 * 이 모달도 언제나 그린다. 도움말 키처럼 화면마다 달라지는 값이 없기 때문이다.
 *
 * 값은 저장소 루트 `CHANGELOG.json` 에서 온 **형식 검사를 통과한 문자열**뿐이고,
 * 여기서 전부 htmlspecialchars 를 거친다 (PRD S-03). 요청 값·DB·경로 조립이 한 군데도 없다.
 * 본문(`<main>`) 밖에 둔다 — 닫혀 있는 <dialog> 가 화면 문구 검사에 섞이지 않게.
 */
require_once __DIR__ . '/../Changelog.php';
$shellChangelog = Changelog::entries();
?>
<dialog id="changelog-dialog" class="modal changelog-dialog" aria-labelledby="changelog-title">
  <div class="modal__head">
    <h2 id="changelog-title" class="modal__title">변경 이력</h2>
    <button class="btn btn--icon btn--plain" type="button" data-changelog-close aria-label="닫기">
      <span class="icon" data-icon="x"></span>
    </button>
  </div>
  <div class="modal__body">
<?php if ($shellChangelog === []): ?>
    <p class="changelog__empty">기록이 없습니다.</p>
<?php else: ?>
<?php foreach ($shellChangelog as $shellClEntry): ?>
    <section class="changelog__entry">
      <h3 class="changelog__ver">
        <span class="changelog__num">v<?= htmlspecialchars($shellClEntry['version'], ENT_QUOTES, 'UTF-8') ?></span>
        <span class="badge"><?= htmlspecialchars(Changelog::levelLabel($shellClEntry['level']), ENT_QUOTES, 'UTF-8') ?></span>
        <time class="changelog__at"><?= htmlspecialchars($shellClEntry['released_at'], ENT_QUOTES, 'UTF-8') ?></time>
      </h3>
      <p class="changelog__title"><?= htmlspecialchars($shellClEntry['title'], ENT_QUOTES, 'UTF-8') ?></p>
      <ul class="changelog__list">
<?php   foreach ($shellClEntry['changes'] as $shellClChange): ?>
        <li><?= htmlspecialchars($shellClChange, ENT_QUOTES, 'UTF-8') ?></li>
<?php   endforeach; ?>
      </ul>
    </section>
<?php endforeach; ?>
<?php endif; ?>
  </div>
  <div class="modal__foot">
    <button class="btn btn--quiet" type="button" data-changelog-close>닫기</button>
  </div>
</dialog>

<!-- ─── 아이콘 모음. app.js 가 [data-icon="이름"] 자리에 clone 해서 넣는다 ───
     이모지를 쓰지 않는다(글꼴에 따라 모양·의미가 달라진다). 모든 아이콘은
     aria-hidden 이고, 뜻은 옆의 글자나 버튼의 title·aria-label 이 전한다. -->
<template id="tpl-icon-shield"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 2 4 5v6c0 5 3.4 9.4 8 11 4.6-1.6 8-6 8-11V5z"/></svg></template>
<template id="tpl-icon-files"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg></template>
<template id="tpl-icon-trash"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12M9 7V4h6v3"/></svg></template>
<template id="tpl-icon-settings"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/></svg></template>
<template id="tpl-icon-users"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8"/></svg></template>
<template id="tpl-icon-org"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg></template>
<!-- Qaredi C — 앱 그리드 단추 (ARCH-launcher 6장, DESIGN.md 2.11). org 아이콘과 같은 규격의 사각형 9개 -->
<template id="tpl-icon-grid"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="5" height="5" rx="1.2"/><rect x="9.5" y="3" width="5" height="5" rx="1.2"/><rect x="16" y="3" width="5" height="5" rx="1.2"/><rect x="3" y="9.5" width="5" height="5" rx="1.2"/><rect x="9.5" y="9.5" width="5" height="5" rx="1.2"/><rect x="16" y="9.5" width="5" height="5" rx="1.2"/><rect x="3" y="16" width="5" height="5" rx="1.2"/><rect x="9.5" y="16" width="5" height="5" rx="1.2"/><rect x="16" y="16" width="5" height="5" rx="1.2"/></svg></template>
<template id="tpl-icon-log"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg></template>
<template id="tpl-icon-logout"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg></template>
<template id="tpl-icon-menu"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg></template>
<template id="tpl-icon-upload"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 16V4M6 10l6-6 6 6M4 20h16"/></svg></template>
<template id="tpl-icon-link"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 14a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1 1M14 10a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1-1"/></svg></template>
<template id="tpl-icon-download"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 4v12M6 10l6 6 6-6M4 20h16"/></svg></template>
<template id="tpl-icon-edit"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg></template>
<template id="tpl-icon-eye"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg></template>
<template id="tpl-icon-restore"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/></svg></template>
<template id="tpl-icon-check"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12 5 5L20 7"/></svg></template>
<template id="tpl-icon-lock"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg></template>
<template id="tpl-icon-plus"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg></template>
<template id="tpl-icon-x"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg></template>
<template id="tpl-icon-help"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M9.6 9.3a2.5 2.5 0 0 1 4.9.7c0 1.7-2.5 2-2.5 3.6"/><path d="M12 17.2h.01"/></svg></template>
<template id="tpl-icon-key"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="8" cy="15" r="4"/><path d="m10.8 12.2 8.2-8.2M17 6l2 2M14 9l2 2"/></svg></template>
<!-- D-5 해/달 (DESIGN.md 2.11 · 2.12) — 둘 다 단추 안에 놓이고 CSS 가 하나만 보인다 -->
<template id="tpl-icon-sun"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></svg></template>
<template id="tpl-icon-moon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/></svg></template>

<script src="<?= Http::url('/assets/app.js') ?>?v=<?= $shellJsVersion ?>"></script>
</body>
</html>
