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
 *   · $activeNav string  지금 켜 둘 내비 항목 — files|trash|settings|users|org|audit
 *   · $pageKey   string  <body data-page> — app.js 가 어떤 화면인지 고르는 열쇠
 *
 * ★ HTML 에 들어가는 동적 값은 **사용자명·역할·pageTitle·activeNav·pageKey 다섯 개뿐**이고
 *   전부 htmlspecialchars(ENT_QUOTES, 'UTF-8') 를 거친다 (PRD S-03).
 *   activeNav·pageKey 는 허용 목록 밖 값이면 빈 문자열로 바꾼다 — 핸들러의 오타가
 *   속성값으로 새어 나가지 않게 하는 자리다.
 *
 * ★ 이 파일에 **역할 비교를 두지 않는다** (PRD S-01, ARCH 13장). 내비 항목은 전부
 *   `hidden` 인 채로 나가고, `/api/me` 의 `can_view_trash`·`can_manage_users`·
 *   `can_view_audit` 이 참일 때만 app.js 가 연다. 개발자도구로 항목을 되살려도
 *   `/trash`·`/users`·`/audit` 은 라우터 정책이 다시 막는다 — 표시는 편의, 근거는 라우트 정책이다.
 *
 * ★ 인라인 <script>·style 속성·on* 이벤트 속성을 쓰지 않는다 (CSP: script-src 'self', style-src 'self').
 *   아이콘은 shell_bottom.php 의 <template> 을 app.js 가 cloneNode 로 붙인다 (문자열 → HTML 변환 0).
 */

/** 내비·본문 키의 허용 목록. 여기 없는 값은 빈 문자열이 된다. */
$shellKeys = ['files', 'trash', 'settings', 'users', 'org', 'audit'];

$shellUser   = isset($user) && is_array($user) ? $user : [];
$shellName   = htmlspecialchars((string) ($shellUser['username'] ?? ''), ENT_QUOTES, 'UTF-8');
$shellRole   = htmlspecialchars((string) ($shellUser['role'] ?? ''), ENT_QUOTES, 'UTF-8');
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

/** 자산 주소에 파일이 마지막으로 바뀐 시각(정수)을 붙여 옛 파일이 남지 않게 한다 (11단계) */
$shellCssVersion = (int) filemtime(__DIR__ . '/../../public/assets/app.css');

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
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $shellTitle ?> · t-box</title>
<link rel="stylesheet" href="/assets/app.css?v=<?= $shellCssVersion ?>">
</head>
<body data-page="<?= $shellPage ?>">
<a class="skip" href="#main">본문으로 건너뛰기</a>
<div class="app">
  <!-- 좁은 화면(960px 미만)에서만 보이는 상단 바. 메뉴 단추가 좌측 내비를 접었다 편다.
       넓은 화면에서는 CSS 가 이 줄을 감추고 내비를 늘 펼쳐 둔다. -->
  <header class="appbar">
    <button id="nav-toggle" class="btn btn--icon" type="button"
            aria-expanded="false" aria-controls="sidebar" aria-label="메뉴 열기" title="메뉴">
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
      <!-- 휴지통·계정 관리·조직·권한·감사 로그는 **기본이 hidden** 이다.
           서버가 /api/me 로 내려 준 can_* 가 참일 때만 app.js 가 연다 (PRD 10장 「공통 셸」). -->
      <a id="nav-trash" class="<?= $shellNavClass('trash') ?>" href="/trash" hidden>
        <span class="icon" data-icon="trash"></span><span>휴지통</span>
      </a>
    </nav>

    <p class="nav-title">설정</p>
    <nav class="nav" aria-label="설정">
      <a id="nav-settings" class="<?= $shellNavClass('settings') ?>" href="/settings">
        <span class="icon" data-icon="settings"></span><span>내 계정</span>
      </a>
    </nav>

    <div id="nav-admin-group" hidden>
      <p class="nav-title">관리</p>
      <nav class="nav" aria-label="관리">
        <a id="nav-users" class="<?= $shellNavClass('users') ?>" href="/users" hidden>
          <span class="icon" data-icon="users"></span><span>계정 관리</span>
        </a>
        <!-- 「조직·권한」은 12-3 에서 `can_manage_org` 로 연다. 지금은 언제나 hidden 이다. -->
        <a id="nav-org" class="<?= $shellNavClass('org') ?>" href="/org" hidden>
          <span class="icon" data-icon="org"></span><span>조직·권한</span>
        </a>
        <a id="nav-audit" class="<?= $shellNavClass('audit') ?>" href="/audit" hidden>
          <span class="icon" data-icon="log"></span><span>감사 로그</span>
        </a>
      </nav>
    </div>

    <div class="sidebar__spacer"></div>

    <div class="userchip">
      <span class="userchip__avatar" aria-hidden="true"><?= $shellInitial ?></span>
      <span class="userchip__text">
        <span class="userchip__name"><?= $shellName ?></span>
        <span class="userchip__role"><?= $shellRole ?></span>
      </span>
      <button id="logout" class="btn btn--icon btn--plain" type="button" title="로그아웃" aria-label="로그아웃">
        <span class="icon" data-icon="logout"></span>
      </button>
    </div>
  </aside>

  <main id="main" class="main">
