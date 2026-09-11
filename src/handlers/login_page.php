<?php
declare(strict_types=1);

/**
 * src/handlers/login_page.php — GET /login (정책: public)
 *
 * 요구사항: PRD 10장(로그인 화면 — 실패 사유를 구체적으로 알려주지 않음), S-09-9(CSP)
 *
 * 이 화면에는 출력할 동적 값이 없다. 서버가 사용자 입력을 한 글자도 HTML 에 넣지 않으므로
 * 이 파일에는 이스케이프할 대상 자체가 없다 (PRD S-03).
 * 인라인 <script>·style 속성·인라인 이벤트(onclick 등)를 쓰지 않는다 —
 * CSP 가 `script-src 'self'`, `style-src 'self'` 이므로 /assets/ 밖의 코드는 실행되지 않는다.
 * 오류 문구는 서버 응답을 app.js 가 textContent 로 넣는다.
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
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>로그인 · t-box</title>
<link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<main class="card card--narrow">
  <h1>t-box 로그인</h1>
  <p class="lead">사내 파일 공유 서비스입니다. 사내망 · VPN 에서만 접속됩니다.</p>

  <form id="login-form" class="form" method="post" action="/api/login" autocomplete="off">
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
    <!-- 10단계 (SECURITY R-05) — 2단계 인증 코드. **필요한지 아닌지는 서버가 정한다.**
         칸은 언제나 보이고, 값이 있으면 그대로 실어 보낸다. 켜 두지 않은 계정에는 쓰이지 않고,
         켠 계정에 코드가 없거나 틀리면 서버가 401 **동일 문구**로 막는다 —
         "이 계정은 2단계 인증을 쓴다" 를 로그인 전에 알려 주지 않기 위해서다. -->
    <label class="field">
      <span class="field__label">2단계 인증 코드 (선택 · 6자리)</span>
      <input id="otp" name="otp" type="text" inputmode="numeric"
             autocomplete="one-time-code" pattern="[0-9]{6}"
             maxlength="6" spellcheck="false">
    </label>
    <button id="login-submit" class="btn" type="submit">로그인</button>
  </form>

  <p id="msg" class="msg" role="alert" aria-live="polite"></p>
</main>
<script src="/assets/app.js"></script>
</body>
</html>
