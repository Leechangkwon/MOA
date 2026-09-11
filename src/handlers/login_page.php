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
<!-- 자산 주소에 파일이 마지막으로 바뀐 시각(정수)을 붙인다 — 화면 코드를 고쳐도
     브라우저가 옛 파일을 계속 쓰는 일을 막는다 (11단계. 출력은 정수뿐이라 이스케이프 대상 없음). -->
<link rel="stylesheet" href="/assets/app.css?v=<?= (int) filemtime(__DIR__ . '/../../public/assets/app.css') ?>">
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
    <!-- 2단계 인증 코드 칸 — **순차 2단계 로그인** (ARCH 4.0, SECURITY R-05).
         처음에는 hidden 이다. ① 아이디·비밀번호만 보내고 ② 서버가 401 + mfa_required 로 답하면
         app.js 가 이 칸을 펼쳐 포커스를 준다. 2단계 인증을 켜지 않은 사람은 이 칸을 볼 일이 없다.
         칸을 처음부터 보여 주지 않는 이유는 헷갈림을 줄이기 위해서이고,
         **누가 2단계 인증을 쓰는지는 비밀번호를 맞힌 사람에게만** 알려 준다(계정 정보 노출 방지).
         칸을 개발자도구로 되살려 코드를 넣어도 서버가 다시 검사한다 — 화면은 판단하지 않는다. -->
    <label class="field" id="otp-field" hidden>
      <span class="field__label">인증 앱의 6자리 코드</span>
      <input id="otp" name="otp" type="text" inputmode="numeric"
             autocomplete="one-time-code" pattern="[0-9]{6}"
             maxlength="6" spellcheck="false">
    </label>
    <button id="login-submit" class="btn" type="submit">로그인</button>
  </form>

  <p id="msg" class="msg" role="alert" aria-live="polite"></p>
</main>
<script src="/assets/app.js?v=<?= (int) filemtime(__DIR__ . '/../../public/assets/app.js') ?>"></script>
</body>
</html>
