<?php
declare(strict_types=1);

/**
 * src/handlers/home.php — GET / (정책: authenticated)
 *
 * 1단계에는 public 이었지만 2단계부터 로그인이 필요하다. 로그인하지 않은 브라우저는
 * 라우터가 302 /login 으로 보낸다 (ARCH 4.0).
 *
 * 사용자명·역할은 DB 에서 읽은 값(요청에 실려 온 값이 아니다 — PRD S-01)이며,
 * HTML 로 나갈 때 htmlspecialchars(ENT_QUOTES) 를 반드시 거친다 (PRD S-03).
 * JS 는 textContent 만 쓴다 (ARCH 13장 체크리스트).
 * 파일 목록·업로드·다운로드는 3단계에서 붙는다.
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params 경로 변수 없음
 */

$displayName = htmlspecialchars((string) $user['username'], ENT_QUOTES, 'UTF-8');
$displayRole = htmlspecialchars((string) $user['role'], ENT_QUOTES, 'UTF-8');

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
<title>t-box (재구축)</title>
<link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<main class="card">
  <header class="topbar">
    <div>
      <h1>t-box (재구축) — 2단계</h1>
      <p class="lead">
        <strong><?= $displayName ?></strong> 님으로 로그인했습니다 ·
        역할 <code><?= $displayRole ?></code>
      </p>
    </div>
    <button id="logout" class="btn btn--quiet" type="button">로그아웃</button>
  </header>

  <ul class="checks">
    <li>DB 세션 · 유휴 30분 / 최대 8시간 · 로그인 시 세션 재발급</li>
    <li>실패 5회 → 10분 계정 잠금 · 로그인 IP 속도 제한</li>
    <li>상태를 바꾸는 요청은 <code>X-CSRF-Token</code> 필수</li>
    <li>파일 목록 · 업로드 · 다운로드는 3단계에서 붙습니다</li>
  </ul>

  <p id="msg" class="msg" role="alert" aria-live="polite"></p>
</main>
<script src="/assets/app.js"></script>
</body>
</html>
