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
 *
 * 변경 내용 목록은 **관리자에게만** (사용자 결정 2026-09-29 — 「상세 패치 내용은 관리자만, 일반 사용자는 제목만.
 * 로그인 구조 같은 민감한 내용이 다 보인다」). 일반 사용자에게는 버전·등급·날짜·제목 한 줄만 그린다.
 *
 * ★ 역할 비교는 여기에도 없다. 값은 `/api/me` 의 `can_admin` 을 만드는 **그 함수**(`Authz::canAdmin`)를 그대로 부른
 *   결과다 — shell_top.php 의 `$shellCommunityWrite` 와 같은 원칙(PRD S-01). 「보는 중」이면 유효 역할이 Admin 이
 *   아니므로 목록이 숨는 것이 맞다.
 * ★ 숨김은 CSS·스크립트가 아니라 **서버가 아예 내보내지 않는 것**이다 — 일반 사용자 셸에는 `Changelog::headlines()`
 *   (변경 문장을 뺀 모양)만 건네므로, 개발자도구로 무엇을 되살려도 HTML 안에 문장이 없다.
 *   CHANGELOG.json 은 한 벌 그대로다(진실은 하나).
 */
require_once __DIR__ . '/../Changelog.php';
$shellChangelogFull = $shellUser !== [] && Authz::canAdmin($shellUser);
$shellChangelog = $shellChangelogFull ? Changelog::entries() : Changelog::headlines();
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
<?php   if ($shellChangelogFull && isset($shellClEntry['changes'])): ?>
      <ul class="changelog__list">
<?php     foreach ($shellClEntry['changes'] as $shellClChange): ?>
        <li><?= htmlspecialchars($shellClChange, ENT_QUOTES, 'UTF-8') ?></li>
<?php     endforeach; ?>
      </ul>
<?php   endif; ?>
    </section>
<?php endforeach; ?>
<?php endif; ?>
  </div>
  <div class="modal__foot">
    <button class="btn btn--quiet" type="button" data-changelog-close>닫기</button>
  </div>
</dialog>

<?php require __DIR__ . '/icon_templates.php'; ?>

<script id="app-script" data-performance-version="<?= substr(hash_file('sha256', __DIR__ . '/../../public/assets/performance-diagnostics.js'), 0, 12) ?>" data-files-version="<?= substr(hash_file('sha256', __DIR__ . '/../../public/assets/files-page.js'), 0, 12) ?>" src="<?= Http::url('/assets/app.js') ?>?v=<?= $shellJsVersion ?>"></script>
<?php if (isset($shellPwa) && $shellPwa === 'alerts'): ?>
<!-- F-70 휴대폰 경보 화면의 동작 (public/assets/alerts-app.js) — app.js 뒤에 싣는다(그쪽이 내놓는 qarediMe·qarediHandleReauth 를 쓴다).
     `$shellPwa` 는 shell_top.php 가 허용 목록 한 값으로 좁힌 것이다. -->
<script src="<?= Http::url('/assets/alerts-app.js') ?>?v=<?= (int) filemtime(__DIR__ . '/../../public/assets/alerts-app.js') ?>"></script>
<?php endif; ?>
<?php
/* 사이트 Q:F-11 (지시서 B-1 · DESIGN 2.17) — 떠 있는 메신저 창 + 스크립트 셋(member-ai · member-assist · messenger 모듈). 로그인 셸 전부(두 모드).
   조건은 shell_top 의 `$shellMessenger`(쓰기 자격 · 역할로 보기 아님) 하나 — 단추와 창이 함께 나가고 함께 빠진다. app.js 뒤라 `window.qarediMe` 를 쓴다. */
if (isset($shellMessenger) && $shellMessenger === true) {
    $messengerUser = $shellUser;
    require __DIR__ . '/messenger_window.php';
}
?>
<?php if (!empty($shellUser) && !$shellMessenger): require __DIR__.'/messenger_unavailable.php'; endif; ?>
<?php if (isset($shellCommunityWrite)): require __DIR__ . '/document_trash.php'; endif; ?>
<?php require __DIR__ . '/usage_guide.php'; ?>
<?php include __DIR__ . '/design_controls.php'; ?>
</body>
</html>
