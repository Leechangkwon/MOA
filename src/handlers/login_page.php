<?php
declare(strict_types=1);

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

// 이미 로그인한 사용자를 로그인 화면에 두지 않는다. 경로는 코드 상수다 (ARCH 4.0).
if (Auth::currentUser() !== null) {
    Http::redirect(TBOX_HOME_PATH);
}

if (!headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
}

/** 자산 주소의 정수 버전 — filemtime 값이며 이스케이프 대상이 없다 (11단계) */
$loginCssVersion = (int) filemtime(__DIR__ . '/../../public/assets/app.css');
$loginJsVersion  = (int) filemtime(__DIR__ . '/../../public/assets/app.js');

/* DESIGN.md 2.12 — 화면 밝기 취향. 쿠키 값은 허용 목록 두 개만 통과하고 그 밖은 속성 자체를 안 그린다.
   (그러면 CSS 가 OS 설정 prefers-color-scheme 을 따른다.) 값은 어차피 상수 두 개라 이스케이프할 것이
   없지만 규칙대로 htmlspecialchars 를 거친다 (PRD S-03).
   로그인 전 화면에도 같은 규칙을 둔다 — 취향 쿠키는 비밀이 아니므로 S-09(버전 비노출)와 무관하다. */
$themeCookie = $_COOKIE['tbox_theme'] ?? '';
$themeAttr   = in_array($themeCookie, ['dark', 'light'], true)
    ? ' data-theme="' . htmlspecialchars($themeCookie, ENT_QUOTES, 'UTF-8') . '"'
    : '';
?>
<!doctype html>
<html lang="ko"<?= $themeAttr ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>로그인 · t-box</title>
<link rel="stylesheet" href="/assets/app.css?v=<?= $loginCssVersion ?>">
</head>
<body data-page="login">
<div class="login">
  <!-- D-5 (DESIGN.md 2.12) — 로그인 화면에는 셸이 없으므로 같은 단추를 오른쪽 위 구석에 둔다.
       자리는 CSS(.login .theme-toggle)가 정하고, 해·달 중 무엇을 보일지도 CSS 가 정한다. -->
  <button type="button" class="btn btn--icon btn--plain theme-toggle" data-theme-toggle
          aria-label="어두운 화면으로">
    <span class="icon icon--moon" data-icon="moon"></span><span class="icon icon--sun" data-icon="sun"></span>
  </button>

  <section class="login__brand">
    <div class="login__brand-top">
      <span class="login__mark"><span class="icon" data-icon="shield"></span></span>
      <span class="brand__text">
        <span class="login__brand-name">t-box</span>
        <span class="login__brand-sub">사내 파일 공유 서비스</span>
      </span>
    </div>
    <div>
      <h2 class="login__lead">사내 파일 공유</h2>
    </div>
    <p class="login__foot">거부되거나 잘 안 되면 관리자에게 문의하세요.</p>
  </section>

  <section class="login__pane">
    <section class="panel panel--narrow">
      <form id="login-form" class="form" method="post" action="/api/login" autocomplete="off">
        <div class="page-head__text">
          <h1 class="page-head__title">로그인</h1>
          <p class="page-head__sub">아이디와 비밀번호를 입력하세요.</p>
        </div>

        <label class="field">
          <span class="field__label">아이디</span>
          <input id="username" name="username" type="text" inputmode="latin"
                 autocapitalize="none" autocorrect="off" spellcheck="false"
                 maxlength="64" required autofocus>
        </label>
        <label class="field">
          <span class="field__label">비밀번호</span>
          <input id="password" name="password" type="password"
                 maxlength="1024" required>
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
    </section>
  </section>
</div>

<!-- 아이콘 모음 — app.js 가 [data-icon] 자리에 clone 해서 넣는다 (문자열 → HTML 변환 0, CSP). -->
<template id="tpl-icon-shield"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 2 4 5v6c0 5 3.4 9.4 8 11 4.6-1.6 8-6 8-11V5z"/></svg></template>
<template id="tpl-icon-check"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12 5 5L20 7"/></svg></template>
<template id="tpl-icon-lock"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg></template>
<!-- D-5 해/달 (DESIGN.md 2.11 · 2.12) — 셸의 <template> 모음과 같은 규격·같은 이름 -->
<template id="tpl-icon-sun"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></svg></template>
<template id="tpl-icon-moon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/></svg></template>

<script src="/assets/app.js?v=<?= $loginJsVersion ?>"></script>
</body>
</html>
