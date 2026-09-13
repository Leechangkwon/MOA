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
 *   허브(`/admin`)의 카드로만 간다. 기존 경로(`/users`·`/org`·`/audit`·`/trash`·`/wiki`)는
 *   그대로 살아 있다(문서·위키가 가리킨다) — 없어진 것은 **내비 항목**이지 경로가 아니다.
 *
 * ★ 인라인 <script>·style 속성·on* 이벤트 속성을 쓰지 않는다 (CSP: script-src 'self', style-src 'self').
 *   아이콘은 shell_bottom.php 의 <template> 을 app.js 가 cloneNode 로 붙인다 (문자열 → HTML 변환 0).
 */

/** 내비·본문 키의 허용 목록. 여기 없는 값은 빈 문자열이 된다. */
$shellKeys = ['files', 'trash', 'settings', 'activity', 'users', 'org', 'audit', 'wiki', 'admin',
              // 19단계 구매·재고 모듈 (PRD F-28, ARCH 5.18)
              'purchase',
              /* 20단계 전표 화면 — 한 묶음 안의 항목을 따로 켜기 위한 키다. 이 파일은
                 `$_SERVER['REQUEST_URI']` 를 읽지 않는다 — 어느 항목을 켤지는 **핸들러가
                 넘긴 `$activeNav`** 만 정하고, 여기 목록은 그 값을 받아 줄 뿐이다.
                 목록에 없는 값은 빈 문자열이 되어 아무 항목도 켜지지 않는다. */
              'purchase-pr', 'purchase-po', 'purchase-docs', 'purchase-import'];

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

    <!-- 19단계 구매·재고 모듈 (PRD F-28, ARCH 5.18) — 기본은 hidden 이고
         `/api/me` 의 can_purchase 가 참일 때만 app.js 가 연다. **이 파일에는 역할 비교가 없다.**
         항목을 개발자도구로 되살려 /purchase 나 /api/purchase/* 를 직접 불러도 라우트 정책
         purchase.read 가 다시 막는다 — 표시는 편의, 근거는 라우트 정책이다 (PRD S-01, T-42). -->
    <div id="nav-purchase-group" hidden>
      <p class="nav-title">구매관리</p>
      <nav class="nav" aria-label="구매관리">
        <!-- 20단계 전표 화면 (PRD F-31~F-33). 항목마다 기본 hidden 이고 app.js 가
             `/api/me` 의 can_purchase 로 함께 연다 — 역할별로 더 감추지 않는다.
             어느 전표를 읽고 쓸 수 있는지는 라우트 정책·`Authz` 가 매 요청 다시 정한다. -->
        <a id="nav-purchase-pr" class="<?= $shellNavClass('purchase-pr') ?>" href="/purchase/pr" hidden>
          <span class="icon" data-icon="edit"></span><span>발주요청</span>
        </a>
        <a id="nav-purchase-po" class="<?= $shellNavClass('purchase-po') ?>" href="/purchase/po" hidden>
          <span class="icon" data-icon="check"></span><span>발주서</span>
        </a>
        <a id="nav-purchase-docs" class="<?= $shellNavClass('purchase-docs') ?>" href="/purchase/docs" hidden>
          <span class="icon" data-icon="log"></span><span>전표</span>
        </a>
        <a id="nav-purchase" class="<?= $shellNavClass('purchase') ?>" href="/purchase/masters" hidden>
          <span class="icon" data-icon="files"></span><span>기초정보</span>
        </a>
        <!-- 「가져오기」만 can_purchase_master 로 연다(있는 필드일 때) — 라우트 정책도
             purchase.master.write 라 항목을 되살려도 서버가 막는다 (PRD S-01). -->
        <a id="nav-purchase-import" class="<?= $shellNavClass('purchase-import') ?>" href="/purchase/import" hidden>
          <span class="icon" data-icon="upload"></span><span>가져오기</span>
        </a>
      </nav>
    </div>

    <!-- 17-1 관리자 영역 (PRD F-24, ARCH 5.14) — 일반 셸에 남은 관리 항목은 **이 하나뿐**이다.
         기본은 hidden 이고, `/api/me` 의 can_admin 이 참일 때만 app.js 가 연다.
         계정 관리·조직·권한·감사 로그·휴지통·위키는 허브(/admin)의 카드로 들어간다. -->
    <div id="nav-admin-group" hidden>
      <p class="nav-title">관리</p>
      <nav class="nav" aria-label="관리">
        <a id="nav-admin" class="<?= $shellNavClass('admin') ?>" href="/admin" hidden>
          <span class="icon" data-icon="lock"></span><span>관리자 페이지</span>
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
