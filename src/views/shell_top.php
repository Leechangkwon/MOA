<?php
declare(strict_types=1);

/**
 * src/views/shell_top.php — 공통 셸의 윗부분 (PRD F-20 · 10장 「공통 셸」, ARCH 3장 `src/views/`)
 *
 * 화면 하나하나가 자기 머리말·내비·사용자 칩을 따로 들고 있으면, 언젠가 한 화면만 고쳐진다.
 * 그래서 셸을 한 벌만 두고 모든 화면이 이 파일과 `shell_bottom.php` 사이에 본문만 적는다.
 *
 * 받는 변수 (호출하는 핸들러가 include 전에 정의한다)
 *   · $user      array   라우터가 넘긴 현재 사용자 (DB 에서 읽은 값. 요청 값이 아니다)
 *   · $pageTitle string  <title> 에 들어갈 화면 이름
 *   · $activeNav string  지금 켜 둘 내비 항목 — files|settings|activity|admin
 *                        (trash·users·org·audit·wiki 는 17-1 에서 내비에서 빠졌지만, 그 화면들이
 *                         여전히 값을 넘기므로 허용 목록에는 남겨 둔다 — 맞는 항목이 없으면
 *                         아무 항목도 켜지지 않을 뿐이다)
 *   · $pageKey   string  <body data-page> — app.js 가 어떤 화면인지 고르는 열쇠
 *   · $pageCss   array   이 화면이 더 싣는 스타일시트 이름들 (25단계). **head 에서** 내보낸다 —
 *                        본문 한가운데에서 <link> 를 부르면 첫 그림이 스타일 없이 보였다가
 *                        바뀐다(FOUC). 값은 `public/assets/` 안의 **허용 목록**에 있는
 *                        파일 이름만 통과한다 — 경로를 조립하지 않으므로 `../` 가 섞일 자리가 없다.
 *   · $pageMsgTop bool   참이면 알림 줄(`#msg`)을 화면이 .page-head 바로 아래에 **한 번** 놓고
 *                        shell_bottom 은 그것을 내보내지 않는다 (id 중복 0 — 25단계).
 *   · $pageHelp  string  「사용 방법」 도움말의 키 (PRD F-38, ARCH 5.19). 쓰는 곳은
 *                        `shell_bottom.php` 하나이고 이 파일은 손대지 않는다.
 *
 * ★ HTML 에 들어가는 동적 값은 **사용자명·역할·pageTitle·activeNav·pageKey 다섯 개뿐**이고
 *   전부 htmlspecialchars(ENT_QUOTES, 'UTF-8') 를 거친다 (PRD S-03).
 *   activeNav·pageKey 는 허용 목록 밖 값이면 빈 문자열로 바꾼다 — 핸들러의 오타가
 *   속성값으로 새어 나가지 않게 하는 자리다.
 *
 * ★ 이 파일에 **역할 비교를 두지 않는다** (PRD S-01, ARCH 13장). 관리 항목은 `hidden` 인 채로
 *   나가고, `/api/me` 의 `can_admin` 이 참일 때만 app.js 가 연다. 개발자도구로 항목을 되살려도
 *   `/admin` 은 라우터 정책(`role:Admin`)과 재인증 관문이 다시 막는다 — 표시는 편의,
 *   근거는 라우트 정책이다.
 *
 * ★ 17-1 (PRD F-24, ARCH 5.14): 관리 그룹(계정 관리·조직·권한·감사 로그·휴지통·위키)을
 *   일반 셸에서 **없앴다**. 대신 Admin 에게만 「관리자 페이지」 한 항목이 보이고, 세부 화면은
 *   허브(`/admin`)의 카드로만 갔다.
 * ★ 26단계 (PRD F-40, 사용자 지적 2026-09-15): 그 결과 세부 화면에서 **돌아올 길이 뒤로가기뿐**
 *   이었다. 「관리」 묶음에 하위 6화면 링크를 되살린다 — 경로도 정책도 그대로이고, 달라진 것은
 *   내비에 보이느냐뿐이다. 여는 값도 여전히 `can_admin` 하나다(이 파일에 역할 비교 없음).
 * ★ 26단계 (PRD F-39): `$user['acting']` 이 참이면 본문 맨 위에 「역할로 보기」 띠를 그린다.
 *   보는 중에는 관리 묶음이 닫히므로 그 띠의 「원래대로」가 유일한 복귀 경로다 — 그래서 서버가 그린다.
 *
 * ★ 인라인 <script>·style 속성·on* 이벤트 속성을 쓰지 않는다 (CSP: script-src 'self', style-src 'self').
 *   아이콘은 shell_bottom.php 의 <template> 을 app.js 가 cloneNode 로 붙인다 (문자열 → HTML 변환 0).
 */

/** 내비·본문 키의 허용 목록. 여기 없는 값은 빈 문자열이 된다. */
$shellKeys = ['files', 'trash', 'settings', 'activity', 'users', 'org', 'audit', 'wiki', 'admin'];

$shellUser   = isset($user) && is_array($user) ? $user : [];
$shellName   = htmlspecialchars((string) ($shellUser['username'] ?? ''), ENT_QUOTES, 'UTF-8');

/*
 * F-47 버전 (PRD F-47, ARCH 5.23) — 사이드바 아래에 보이는 `v1.0.0` 한 줄.
 *
 * 부트스트랩의 require 목록에 새 줄을 더하지 않고 쓰는 곳에서 한 번 읽어 온다
 * (`Wiki.php`·`Sensitive.php` 와 같은 방식). 값은 저장소 루트 `VERSION` 한 줄이며
 * 요청·DB 와 무관하고, 아래에서 htmlspecialchars 를 거쳐 글자로만 나간다 (PRD S-03).
 * **로그인 전 화면(`login_page.php`)은 이 셸을 쓰지 않으므로 버전이 나가지 않는다** (S-09).
 */
require_once __DIR__ . '/../Changelog.php';
$shellVersion = htmlspecialchars(Changelog::version(), ENT_QUOTES, 'UTF-8');

/*
 * 26단계 「역할로 보기」 (PRD F-39, ARCH 5.20) — 셸이 **서버에서** 띠를 그린다.
 *
 * 왜 app.js 가 아니라 서버인가: 보는 중에는 사이드바의 관리 묶음이 닫힌다(유효 역할이 Admin 이
 * 아니므로 can_admin 이 거짓이다). 그러면 원래대로 돌아올 길이 이 띠뿐인데, 그 유일한 길을
 * 스크립트가 못 그리는 상황(오류·차단)에 잃으면 사용자는 30분을 기다려야 한다.
 *
 * ★ 여기에도 **역할 비교는 없다.** `acting` 은 Auth::currentUser 가 판정해 준 불린이고,
 *   이 파일은 그 값이 참일 때 글자를 그릴 뿐이다. 값은 전부 htmlspecialchars 를 거친다 (PRD S-03).
 */
$shellActing = ($shellUser['acting'] ?? false) === true;

/** 사용자 칩에 보이는 역할 — 언제나 **유효 역할**이고, 보는 중이면 그 사실을 함께 적는다 */
$shellRoleText = (string) ($shellUser['role'] ?? '');
if ($shellActing) {
    $shellRoleText .= ' (보기)';
}
$shellRole = htmlspecialchars($shellRoleText, ENT_QUOTES, 'UTF-8');

/*
 * 띠에 쓸 이름들. 보는 중일 때만 읽는다(평소 요청에는 쿼리가 늘지 않는다).
 * 값은 세션이 들고 있는 유효 team_id 이고 요청이 정하지 않는다 (PRD S-01).
 */
$shellActParts  = [];
$shellActActual = '';
if ($shellActing) {
    $shellActParts[] = (string) ($shellUser['role'] ?? '');

    $shellActTeamId = $shellUser['team_id'] ?? null;
    if ($shellActTeamId !== null && $shellActTeamId !== '') {
        $shellActRow = Db::instance()->run(
            'SELECT name FROM teams WHERE id = :id',
            [':id' => (int) $shellActTeamId]
        )->fetch();
        if (is_array($shellActRow)) {
            $shellActParts[] = (string) $shellActRow['name'];
        }
    }

    $shellActActual = htmlspecialchars(
        (string) ($shellUser['username'] ?? '') . '(' . (string) ($shellUser['real_role'] ?? '') . ')',
        ENT_QUOTES,
        'UTF-8'
    );
}
$shellActWhat = htmlspecialchars(implode(' · ', array_filter($shellActParts, static function ($v) {
    return $v !== '';
})), ENT_QUOTES, 'UTF-8');
$shellTitle  = htmlspecialchars(isset($pageTitle) && is_string($pageTitle) ? $pageTitle : '', ENT_QUOTES, 'UTF-8');
$shellActive = isset($activeNav) && is_string($activeNav) && in_array($activeNav, $shellKeys, true) ? $activeNav : '';
$shellPage   = isset($pageKey) && is_string($pageKey) && in_array($pageKey, $shellKeys, true) ? $pageKey : '';
$shellActive = htmlspecialchars($shellActive, ENT_QUOTES, 'UTF-8');
$shellPage   = htmlspecialchars($shellPage, ENT_QUOTES, 'UTF-8');

/**
 * 사용자 칩의 동그란 머리글자. **이스케이프 전 원본**에서 한 글자를 떼고 그 뒤에 이스케이프한다 —
 * 이스케이프한 문자열을 자르면 `&amp;` 같은 엔티티가 중간에서 잘릴 수 있다.
 * 값은 여전히 사용자명 하나에서 나온다(새 동적 값이 아니다).
 */
$shellRawName = (string) ($shellUser['username'] ?? '');
$shellInitial = preg_match('/^./u', $shellRawName, $shellFirst) === 1 ? $shellFirst[0] : '';
if ($shellInitial === '') {
    $shellInitial = '?';
} elseif (preg_match('/^[a-z]$/', $shellInitial) === 1) {
    // 영문 소문자 한 글자만 대문자로. mbstring 확장에 기대지 않는다(설치 대상 늘리기 금지).
    $shellInitial = strtoupper($shellInitial);
}
$shellInitial = htmlspecialchars($shellInitial, ENT_QUOTES, 'UTF-8');

/* DESIGN.md 2.12 — 화면 밝기 취향. 쿠키 값은 허용 목록 두 개만 통과하고 그 밖은 속성 자체를 안 그린다.
   (그러면 CSS 가 OS 설정 prefers-color-scheme 을 따른다.) 값은 어차피 상수 두 개라 이스케이프할 것이
   없지만 규칙대로 htmlspecialchars 를 거친다 (PRD S-03). */
$themeCookie = $_COOKIE['tbox_theme'] ?? '';
$themeAttr   = in_array($themeCookie, ['dark', 'light'], true)
    ? ' data-theme="' . htmlspecialchars($themeCookie, ENT_QUOTES, 'UTF-8') . '"'
    : '';

/** 자산 주소에 파일이 마지막으로 바뀐 시각(정수)을 붙여 옛 파일이 남지 않게 한다 (11단계) */
$shellCssVersion = (int) filemtime(__DIR__ . '/../../public/assets/app.css');

/**
 * 25단계 — 화면이 더 싣는 스타일시트. **허용 목록 안의 이름만** 통과한다.
 * 핸들러의 오타나 어떤 요청 값도 `<link href>` 로 새어 나가지 못한다 (PRD S-03).
 * 지금은 더 싣는 스타일시트가 없다 — 목록이 비어 있으므로 `$pageCss` 는 어떤 값이든 버려진다.
 * 구조를 남겨 두는 이유는, 화면별 CSS 가 다시 생길 때 이 허용 목록을 거치게 하기 위해서다.
 */
$shellCssAllowed = [];
$shellPageCss    = [];
if (isset($pageCss) && is_array($pageCss)) {
    foreach ($pageCss as $shellCssName) {
        if (is_string($shellCssName) && in_array($shellCssName, $shellCssAllowed, true)
            && !in_array($shellCssName, $shellPageCss, true)
        ) {
            $shellPageCss[] = $shellCssName;
        }
    }
}

/** 지금 켜 둘 항목에만 is-active 를 붙인다. 값은 위 허용 목록을 통과한 문자열이다. */
$shellNavClass = static function (string $key) use ($shellActive): string {
    return $key === $shellActive ? 'nav__item is-active' : 'nav__item';
};

if (!headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
}
?>
<!doctype html>
<html lang="ko"<?= $themeAttr ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $shellTitle ?> · t-box</title>
<!-- 25단계 — 브라우저가 스스로 부르는 `/favicon.ico` 가 404 로 떨어져 모든 화면의 콘솔에
     오류 한 줄을 남겼다(시각 점검 도구가 세는 console_errors). 아이콘을 **문서 안에** 둔다:
     파일을 새로 만들지 않고, CSP 의 `img-src 'self' data:` 안에서 끝난다. 고정 문자열이라
     동적 값이 한 글자도 들어가지 않는다. -->
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath fill='%2314213d' d='M12 2 4 5v6c0 5 3.4 9.4 8 11 4.6-1.6 8-6 8-11V5z'/%3E%3C/svg%3E">
<link rel="stylesheet" href="/assets/app.css?v=<?= $shellCssVersion ?>">
<?php foreach ($shellPageCss as $shellCssName): ?>
<link rel="stylesheet" href="/assets/<?= $shellCssName ?>?v=<?= (int) filemtime(__DIR__ . '/../../public/assets/' . $shellCssName) ?>">
<?php endforeach; ?>
</head>
<body data-page="<?= $shellPage ?>">
<a class="skip" href="#main">본문으로 건너뛰기</a>
<div class="app">
  <!-- 좁은 화면(960px 미만)에서만 보이는 상단 바. 메뉴 단추가 좌측 내비를 접었다 편다.
       넓은 화면에서는 CSS 가 이 줄을 감추고 내비를 늘 펼쳐 둔다. -->
  <header class="appbar">
    <button id="nav-toggle" class="btn btn--icon" type="button"
            aria-expanded="false" aria-controls="sidebar" aria-label="메뉴 열기">
      <span class="icon" data-icon="menu"></span>
    </button>
    <span class="appbar__brand">t-box</span>
    <span class="appbar__page"><?= $shellTitle ?></span>
  </header>

  <aside id="sidebar" class="sidebar" hidden>
    <div class="brand">
      <span class="brand__mark"><span class="icon" data-icon="shield"></span></span>
      <span class="brand__text">
        <span class="brand__name">t-box</span>
        <span class="brand__sub">사내 파일 공유</span>
      </span>
    </div>

    <p class="nav-title">파일</p>
    <nav class="nav" aria-label="파일">
      <a id="nav-files" class="<?= $shellNavClass('files') ?>" href="/">
        <span class="icon" data-icon="files"></span><span>파일</span>
      </a>
    </nav>

    <p class="nav-title">설정</p>
    <nav class="nav" aria-label="설정">
      <a id="nav-settings" class="<?= $shellNavClass('settings') ?>" href="/settings">
        <span class="icon" data-icon="settings"></span><span>내 계정</span>
      </a>
      <!-- 「내 활동」은 15-1 에서 더한 항목이며 **모든 로그인 사용자에게 보인다** (PRD F-12).
           can_* 플래그로 여닫지 않는다 — 자기 이력은 역할과 무관하게 누구나 볼 수 있고,
           보이는 내용은 서버가 세션 본인으로 고정하기 때문이다 (ARCH 5.11). -->
      <a id="nav-activity" class="<?= $shellNavClass('activity') ?>" href="/activity">
        <span class="icon" data-icon="log"></span><span>내 활동</span>
      </a>
    </nav>

    <!-- 관리자 영역 (17-1 PRD F-24 · 26단계 F-40, ARCH 5.14).
         기본은 전부 hidden 이고, `/api/me` 의 can_admin 이 참일 때만 app.js 가 **함께** 연다.
         **이 파일에는 역할 비교가 없다** — 항목을 개발자도구로 되살려도 각 경로의 라우트 정책
         (user.manage·org.manage·audit.read·role:Admin)과 재인증 관문이 다시 막는다 (PRD S-01, T-38).

         ★ 26단계 (사용자 지적 2026-09-15 「계정·조직 화면에서 뒤로가기로만 돌아온다」):
           17-1 에서 이 묶음을 「관리자 페이지」 한 항목으로 줄였더니, 허브에서 카드로 들어간 뒤에는
           사이드바에 돌아올 길이 없었다. 하위 6화면을 다시 항목으로 둔다 — **경로·정책은 그대로**이고
           달라진 것은 내비에 보이느냐뿐이다. -->
    <div id="nav-admin-group" hidden>
      <p class="nav-title">관리</p>
      <nav class="nav" aria-label="관리">
        <a id="nav-admin" class="<?= $shellNavClass('admin') ?>" href="/admin" hidden>
          <span class="icon" data-icon="lock"></span><span>관리자 페이지</span>
        </a>
        <a id="nav-users" class="<?= $shellNavClass('users') ?>" href="/users" hidden>
          <span class="icon" data-icon="users"></span><span>계정 관리</span>
        </a>
        <a id="nav-org" class="<?= $shellNavClass('org') ?>" href="/org" hidden>
          <span class="icon" data-icon="org"></span><span>조직 · 권한</span>
        </a>
        <a id="nav-admin-permissions" class="nav__item" href="/admin/permissions" hidden>
          <span class="icon" data-icon="lock"></span><span>권한 매트릭스</span>
        </a>
        <!-- F-43 — URL 가져오기 허용 사이트 (PRD F-43, ARCH 5.22). 위 항목들과 **같은 값
             하나**(can_admin)로 함께 열린다. 항목을 개발자도구로 되살려도 /admin/fetch-hosts 는
             라우트 정책 role:Admin 과 재인증 관문이 다시 막는다 (PRD S-01, T-56). -->
        <a id="nav-admin-fetch-hosts" class="nav__item" href="/admin/fetch-hosts" hidden>
          <span class="icon" data-icon="link"></span><span>가져오기 허용 사이트</span>
        </a>
        <a id="nav-audit" class="<?= $shellNavClass('audit') ?>" href="/audit" hidden>
          <span class="icon" data-icon="log"></span><span>감사 로그</span>
        </a>
        <a id="nav-trash" class="<?= $shellNavClass('trash') ?>" href="/trash" hidden>
          <span class="icon" data-icon="trash"></span><span>휴지통</span>
        </a>
        <a id="nav-wiki" class="<?= $shellNavClass('wiki') ?>" href="/wiki" hidden>
          <span class="icon" data-icon="shield"></span><span>위키</span>
        </a>
      </nav>
    </div>

    <div class="sidebar__spacer"></div>

    <!-- F-47 「변경 이력」 여는 단추 (PRD F-47, ARCH 5.23).
         누르면 shell_bottom.php 가 그린 #changelog-dialog 가 열린다 — 요청도 권한 판단도 없고,
         내용은 이미 이 응답 안에 들어 있다. 「사용 방법」 단추(5.19)와 같은 뼈대다. -->
    <!-- D-5 (DESIGN.md 2.9 · 2.12) — 버전 표기와 해/달 단추를 한 줄(.sidebar__foot)에 둔다.
         해와 달을 **둘 다** 그려 두고 어느 것을 보일지는 CSS 가 정한다
         (:root[data-theme] · prefers-color-scheme) — app.js 는 아이콘을 갈아 끼우지 않는다. -->
    <div class="sidebar__foot">
      <button type="button" class="sidebar__version" data-changelog-open title="변경 이력"
              aria-haspopup="dialog" aria-controls="changelog-dialog">v<?= $shellVersion ?></button>
      <button type="button" class="btn btn--icon btn--plain theme-toggle" data-theme-toggle
              aria-label="어두운 화면으로">
        <span class="icon icon--moon" data-icon="moon"></span><span class="icon icon--sun" data-icon="sun"></span>
      </button>
    </div>

    <div class="userchip">
      <span class="userchip__avatar" aria-hidden="true"><?= $shellInitial ?></span>
      <span class="userchip__text">
        <span class="userchip__name"><?= $shellName ?></span>
        <span class="userchip__role"><?= $shellRole ?></span>
      </span>
      <button id="logout" class="btn btn--icon btn--plain" type="button" aria-label="로그아웃">
        <span class="icon" data-icon="logout"></span>
      </button>
    </div>
  </aside>

  <main id="main" class="main">
<?php if ($shellActing): ?>
    <!-- 26단계 「역할로 보기」 띠 (PRD F-39, ARCH 5.20).
         본문(.page-head)보다 **위**에 있어 어느 화면에서든 첫 줄에 보인다. 보는 중에는 사이드바의
         관리 묶음이 닫히므로 이 「원래대로」 단추가 유일한 복귀 경로다 — 그래서 서버가 그린다.
         값은 전부 위에서 htmlspecialchars 를 거쳤고, 이 줄에는 역할 비교도 인라인 style·script 도 없다. -->
    <div class="act-banner notice" role="status">
      <span>지금 <b><?= $shellActWhat ?></b> 으로 보는 중 — 실제 계정 <?= $shellActActual ?></span>
      <button type="button" data-act-stop class="btn btn--quiet btn--small">원래대로</button>
    </div>
<?php endif; ?>
