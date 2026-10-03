<?php
declare(strict_types=1);
require_once __DIR__ . '/../Design.php';

/**
 * src/handlers/login_page.php — GET /login (정책: public)
 *
 * 요구사항: PRD 10장(로그인 화면 — 실패 사유를 구체적으로 알려주지 않음), F-20(디자인), S-09-9(CSP)
 *
 * 이 화면에는 출력할 동적 값이 없다. 서버가 사용자 입력을 한 글자도 HTML 에 넣지 않으므로
 * 이 파일에는 이스케이프할 대상 자체가 없다 (PRD S-03).
 * 인라인 <script>·style 속성·인라인 이벤트(onclick 등)를 쓰지 않는다 —
 * CSP 가 `script-src 'self'`, `style-src 'self'` 이므로 /assets/ 밖의 코드는 실행되지 않는다.
 * 오류 문구는 서버 응답을 app.js 가 textContent 로 넣는다.
 *
 * 12단계(F-20)에서 디자인만 바뀌었다 — 좌측 브랜드 패널 + 우측 카드.
 * **id·name·hidden 규칙은 그대로**이고 로그인 흐름(순차 2단계)도 한 글자도 바뀌지 않았다.
 * 아이콘은 아래 <template> 을 app.js 가 cloneNode 로 붙인다(문자열 → HTML 변환 0). 이모지를 쓰지 않는다.
 *
 * @var array<string,mixed>|null $user   public 라우트이므로 라우터는 null 을 넘긴다
 * @var list<string>             $params 경로 변수 없음
 */

/**
 * 로그인 뒤 어디로 보낼까 — `?to=` (커뮤니티 ARCHITECTURE.md 21.2 · Q:F-7 B1-1).
 *
 * 커뮤니티 화면을 미로그인으로 열면 `/tbox/login?to=community` 로 오는데, 로그인을 마치고
 * t-box 홈으로 떨어지면 「가려던 곳」을 잃는다. 그 한 가지를 고치는 값이다.
 *
 * ★ 옛 키 둘(`tbox`·`community`)은 그대로 받는다. 그 밖의 값은 **`Http::returnPath()` 를 통과한 정규형**만 받는다
 *   (Q:F-7 B1-1 — 허용 목록 경로 · 길이·디코딩·위험 글자 규칙은 그 함수 한 곳). 통과하지 못하면 **무시**한다
 *   (속성 자체를 그리지 않는다 — 로그인 뒤 첫 화면). 화면(app.js loginDestination)이 같은 규칙으로 한 번 더 거른다 —
 *   속성을 개발자도구로 고쳐도 목록 밖으로는 못 간다. 값은 htmlspecialchars 를 거쳐 속성에만 들어간다.
 */
$loginToAllowed = ['tbox', 'community'];
$loginToRaw     = $_GET['to'] ?? 'community';
$loginTo        = '';
$loginReturn    = null;   // 허용 목록 경로의 정규형(있으면)
if (is_string($loginToRaw) && in_array($loginToRaw, $loginToAllowed, true)) {
    $loginTo = $loginToRaw;
} elseif (is_string($loginToRaw) && $loginToRaw !== '') {
    $loginReturn = Http::returnPath($loginToRaw);
}

// 이미 로그인한 사용자를 로그인 화면에 두지 않는다 (ARCH 4.0). 보던 곳(정규형)이 있으면 그리로, 없으면 코드 상수(첫 화면).
if (Auth::currentUser() !== null) {
    if ($loginReturn !== null) {
        Http::redirectReturn($loginReturn);
    }
    Http::redirect($loginTo === 'community' ? Http::communityUrl('/') : Http::url(TBOX_HOME_PATH));
}

if (!headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
}

/** 자산 주소의 정수 버전 — filemtime 값이며 이스케이프 대상이 없다 (11단계) */
$loginCssVersion = (int) filemtime(__DIR__ . '/../../public/assets/app.css');
$loginJsVersion  = (int) filemtime(__DIR__ . '/../../public/assets/app.js');
/* T:F-72 — 지문·얼굴 로그인 화면 동작(public/assets/passkey.js)과 홈 화면 「Qaredi」 앱 아이콘의 정수 버전 */
$loginPkVersion   = (int) filemtime(__DIR__ . '/../../public/assets/passkey.js');
$loginIconVersion = (int) filemtime(__DIR__ . '/../../public/assets/qaredi-icon-192.png');

/* DESIGN.md 2.12 — 화면 밝기 취향. 규칙은 셸·런처와 같으므로 Http::themeAttr() 한 벌을 쓴다
   (ARCH-launcher 3장). 로그인 전 화면에도 같은 규칙을 둔다 — 취향 쿠키는 비밀이 아니므로
   S-09(버전 비노출)와 무관하다. */
$themeAttr = Http::themeAttr();

/* `<body data-to>` 에 넣을 값 — 옛 키 또는 정규형 경로(둘 중 하나). 보던 곳이 커뮤니티 경로면 왼쪽 판도 커뮤니티로
   (판 글자는 아래 고정 표에서만 고른다 — 요청 값이 판을 고르는 데 쓰이는 것은 키 둘뿐이다). */
$loginDataTo = $loginReturn !== null ? $loginReturn : $loginTo;
$loginPane   = $loginTo;
if ($loginReturn !== null && strpos($loginReturn, COMMUNITY_BASE . '/') === 0) {
    $loginPane = 'community';
}

/* 들어가려는 앱에 맞춘 왼쪽 판 (사용자 지적 2026-09-23 「t-box·커뮤니티 둘 다 눌렀을 때 전부 t-box 로
   접근된다」). 로그인은 사이트 하나(계정·세션 공유)라 한 곳이 맞지만, 화면이 늘 「t-box」라고만 적어
   커뮤니티 타일을 누른 사람도 t-box 로 가는 줄 알았다. 글자는 **고정 표**에서만 고른다 — 요청 값은
   위 허용 목록을 지난 키뿐이다. */
$loginApps = [
    'tbox'      => ['name' => '모아 자료실',  'into' => '자료실로',  'sub' => '사내 파일 공유',                  'icon' => 'shield'],
    'community' => ['name' => '커뮤니티', 'into' => '커뮤니티로', 'sub' => '함께라서 더 좋은 일상', 'icon' => 'grid'],
];
$loginApp = $loginApps[$loginPane === '' ? 'tbox' : $loginPane];
?>
<!doctype html>
<html lang="ko"<?= $themeAttr ?><?= Design::attrs() ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<title>로그인 · <?= htmlspecialchars($loginApp['name'], ENT_QUOTES, 'UTF-8') ?> · 모아</title>
<link rel="stylesheet" href="<?= Http::url('/assets/app.css') ?>?v=<?= $loginCssVersion ?>">
<!-- T:F-72 라운드 C · 결정 PK-D5 — 홈 화면 「Qaredi」 앱(모든 사람). 로그인 화면이 앱 범위(`/`) 안이라 지문 창이 앱 안에서 뜬다.
     매니페스트는 public 라우트(데이터 0) · 서비스 워커 없음. 값은 전부 고정 글자다. -->
<link rel="manifest" href="<?= Http::url('/app.webmanifest') ?>">
<link rel="apple-touch-icon" href="<?= Http::url('/assets/qaredi-icon-192.png') ?>?v=<?= $loginIconVersion ?>">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Qaredi">
<?= Design::assets() ?>
</head>
<body data-page="login" data-base="<?= TBOX_BASE ?>" data-community-base="<?= COMMUNITY_BASE ?>"<?= $loginDataTo === '' ? '' : ' data-to="' . htmlspecialchars($loginDataTo, ENT_QUOTES, 'UTF-8') . '"' ?>>
<div class="login">
  <button type="button" class="btn btn--quiet q-design-open q-login-design" data-design-open aria-haspopup="dialog" aria-controls="q-design-dialog"><span class="q-design-dot" aria-hidden="true"></span><span data-design-name>Astra</span> · 테마</button>
  <!-- D-5 (DESIGN.md 2.12) — 로그인 화면에는 셸이 없으므로 같은 단추를 오른쪽 위 구석에 둔다.
       자리는 CSS(.login .theme-toggle)가 정하고, 해·달 중 무엇을 보일지도 CSS 가 정한다. -->
  <button type="button" class="btn btn--icon btn--plain theme-toggle" data-theme-toggle
          aria-label="어두운 화면으로">
    <span class="icon icon--moon" data-icon="moon"></span><span class="icon icon--sun" data-icon="sun"></span>
  </button>

  <section class="login__brand">
    <div class="login__brand-top">
      <span class="login__mark"><span class="icon" data-icon="<?= $loginApp['icon'] ?>"></span></span>
      <span class="brand__text">
        <span class="login__brand-name"><?= htmlspecialchars($loginApp['name'], ENT_QUOTES, 'UTF-8') ?></span>
        <span class="login__brand-sub q-wordmark">모아</span>
      </span>
    </div>
    <div>
      <h2 class="login__lead"><?= htmlspecialchars($loginApp['sub'], ENT_QUOTES, 'UTF-8') ?></h2>
      <p class="login__brand-sub">소소한 이야기부터 함께 쓰는 자료까지. 모아에서 우리의 일상을 이어가세요.</p>
    </div>

    <!-- Qaredi C — 로그인 화면에는 「Qaredi 홈」 텍스트 링크 하나만 (ARCH-launcher 6장).
         런처는 로그인 전에도 열리므로 이 링크가 막다른 화면을 만들지 않는다. 주소는
         사이트 루트라 `/tbox` 접두를 타지 않는다(이 화면에서 접두 없는 링크는 이것뿐이다). -->
    <p class="login__foot"><a href="/">모아 홈</a> · 거부되거나 잘 안 되면 관리자에게 문의하세요.</p>
  </section>

  <section class="login__pane">
    <section class="panel panel--narrow">
      <form id="login-form" class="form" method="post" action="<?= Http::url('/api/login') ?>" autocomplete="off">
        <div class="page-head__text">
          <h1 class="page-head__title">로그인</h1>
          <p class="page-head__sub">아이디와 비밀번호를 입력하세요. 로그인하면 <b><?= htmlspecialchars($loginApp['into'], ENT_QUOTES, 'UTF-8') ?></b> 들어갑니다.</p>
        </div>

        <!-- T:F-72 휴대폰 지문·얼굴로 로그인 (지시서 1.2 · 결정 PK-D1 · PK-D2). 처음에는 둘 다 hidden 이고 passkey.js 가
             **휴대폰 + 지문·얼굴 확인 인증기가 있을 때만** 켠다(PC 는 지금 폼 그대로 — 화면 편의일 뿐 서버는 가리지 않는다).
             「이 기기 표시」가 있으면 검정 「지문·얼굴로 로그인」 + 아래 「비밀번호로 로그인」(누르면 칸이 펼쳐짐) + 곧바로 지문 창,
             없으면 이 단추가 테두리(.btn--quiet)로 폼 위에 있고 아이디 칸에 자동 완성(conditional mediation)이 걸린다.
             아이디 칸의 `autocomplete="username webauthn"` · 비밀번호 칸의 `current-password` 가 자동 완성의 필수 조건이다
             (폼의 autocomplete="off" 보다 칸의 값이 이긴다 — PK-T10). -->
        <button id="passkey-login-btn" class="btn btn--block btn--quiet" type="button" hidden>
          <span class="icon" data-icon="fingerprint"></span>지문·얼굴로 로그인
        </button>
        <button id="passkey-password-open" class="btn btn--block btn--quiet" type="button" hidden>비밀번호로 로그인</button>

        <details id="passkey-recovery" class="help" hidden>
          <summary>지문 로그인이 안 되나요?</summary>
          <p>「사용 가능한 로그인 정보 없음」은 휴대폰의 인증 서비스가 이 사이트의 패스키를 찾지 못했다는 뜻입니다. Samsung Pass와 Google 비밀번호 관리자에 저장된 패스키는 다를 수 있습니다.</p>
          <ol>
            <li>패스키를 등록했던 앱이나 브라우저에서 다시 시도하세요.</li>
            <li>계속 안 되면 아래에서 비밀번호로 로그인하세요.</li>
            <li>「내 계정 → 지문·얼굴 로그인 → 이 휴대폰 등록하기」에서 새 패스키를 추가하고, 등록 창의 저장 서비스를 확인하세요. 기존 등록은 먼저 해제하지 않아도 됩니다.</li>
          </ol>
        </details>

        <label class="field">
          <span class="field__label">아이디</span>
          <input id="username" name="username" type="text" inputmode="latin"
                 autocapitalize="none" autocorrect="off" spellcheck="false"
                 autocomplete="username webauthn" maxlength="64" required autofocus>
        </label>
        <label class="field">
          <span class="field__label">비밀번호</span>
          <input id="password" name="password" type="password"
                 autocomplete="current-password" maxlength="1024" required>
        </label>

        <!-- 2단계 인증 코드 칸 — **순차 2단계 로그인** (ARCH 4.0, SECURITY R-05).
             처음에는 hidden 이다. ① 아이디·비밀번호만 보내고 ② 서버가 401 + mfa_required 로 답하면
             app.js 가 이 칸을 펼쳐 포커스를 준다. 2단계 인증을 켜지 않은 사람은 이 칸을 볼 일이 없다.
             칸을 처음부터 보여 주지 않는 이유는 헷갈림을 줄이기 위해서이고,
             **누가 2단계 인증을 쓰는지는 비밀번호를 맞힌 사람에게만** 알려 준다(계정 정보 노출 방지).
             칸을 개발자도구로 되살려 코드를 넣어도 서버가 다시 검사한다 — 화면은 판단하지 않는다.
             칸은 **하나**다(6칸으로 쪼개지 않는다 — 동작이 달라진다). -->
        <label class="field otp-box" id="otp-field" hidden>
          <span class="field__label"><span class="icon" data-icon="lock"></span>인증 앱의 6자리 코드</span>
          <input id="otp" name="otp" type="text" inputmode="numeric"
                 autocomplete="one-time-code" pattern="[0-9]{6}"
                 maxlength="6" spellcheck="false">

        </label>

        <button id="login-submit" class="btn btn--block" type="submit">로그인</button>
        <p id="msg" class="msg" role="alert" aria-live="polite"></p>
      </form>
      <p class="moa-login-join">처음 오셨나요? <a href="<?= Http::url('/join') ?>">초대 링크로 회원가입 →</a></p>
    </section>
  </section>
</div>

<!-- 아이콘 모음 — app.js 가 [data-icon] 자리에 clone 해서 넣는다 (문자열 → HTML 변환 0, CSP). -->
<template id="tpl-icon-shield"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 2 4 5v6c0 5 3.4 9.4 8 11 4.6-1.6 8-6 8-11V5z"/></svg></template>
<template id="tpl-icon-grid"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="5" height="5" rx="1.2"/><rect x="9.5" y="3" width="5" height="5" rx="1.2"/><rect x="16" y="3" width="5" height="5" rx="1.2"/><rect x="3" y="9.5" width="5" height="5" rx="1.2"/><rect x="9.5" y="9.5" width="5" height="5" rx="1.2"/><rect x="16" y="9.5" width="5" height="5" rx="1.2"/><rect x="3" y="16" width="5" height="5" rx="1.2"/><rect x="9.5" y="16" width="5" height="5" rx="1.2"/><rect x="16" y="16" width="5" height="5" rx="1.2"/></svg></template>
<template id="tpl-icon-check"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12 5 5L20 7"/></svg></template>
<!-- T:F-72 지문 모양(24×24 선 · lucide fingerprint 규격 — DESIGN 2.11) — 「지문·얼굴로 로그인」 단추 -->
<template id="tpl-icon-fingerprint"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 10a2 2 0 0 0-2 2c0 1.02-.1 2.51-.26 4"/><path d="M14 13.12c0 2.38 0 6.38-1 8.88"/><path d="M17.29 21.02c.12-.6.43-2.3.5-3.02"/><path d="M2 12a10 10 0 0 1 18-6"/><path d="M2 16h.01"/><path d="M21.8 16c.2-2 .131-5.354 0-6"/><path d="M5 19.5C5.5 18 6 15 6 12a6 6 0 0 1 .34-2"/><path d="M8.65 22c.21-.66.45-1.32.57-2"/><path d="M9 6.8a6 6 0 0 1 9 5.2v2"/></svg></template>
<template id="tpl-icon-lock"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg></template>
<!-- D-5 해/달 (DESIGN.md 2.11 · 2.12) — 셸의 <template> 모음과 같은 규격·같은 이름 -->
<template id="tpl-icon-sun"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></svg></template>
<template id="tpl-icon-moon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/></svg></template>

<script src="<?= Http::url('/assets/app.js') ?>?v=<?= $loginJsVersion ?>"></script>
<script src="<?= Http::url('/assets/passkey.js') ?>?v=<?= $loginPkVersion ?>"></script>
<?php include __DIR__ . '/../views/design_controls.php'; ?>
</body>
</html>
