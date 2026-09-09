<?php
declare(strict_types=1);

/**
 * src/handlers/home.php — GET / (정책: public)
 *
 * 1단계의 껍데기 화면. 사용자 입력을 출력하지 않는다 — 출력할 동적 값이 아직 없다.
 * 2단계부터 값을 출력할 때는 반드시 htmlspecialchars(ENT_QUOTES) 를 거친다 (PRD S-03).
 * JS 는 HTML 문자열을 그대로 넣는 API 를 쓰지 않고 textContent 만 쓴다 (ARCH 13장 체크리스트).
 *
 * @var array<string,mixed>|null $user
 * @var list<string>             $params
 */

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
  <h1>t-box (재구축) — 1단계</h1>
  <p class="lead">뼈대와 DB 가 준비됐습니다. 로그인은 2단계에서 붙습니다.</p>
  <ul class="checks">
    <li>단일 입구 라우터 · 라우트마다 권한 정책 필수</li>
    <li>소스 · 설정 · 업로드 파일은 웹 루트 밖</li>
    <li>상태 확인: <code>GET /api/health</code></li>
  </ul>
</main>
<script src="/assets/app.js"></script>
</body>
</html>
