<?php
declare(strict_types=1);

/**
 * src/handlers/logout.php — POST /api/logout (정책: authenticated)
 *
 * 요구사항: PRD F-02, S-09-3(CSRF), SECURITY R-10
 *
 * 라우터가 이미 [2] requireLogin → [3] requireCsrf 를 통과시켰다.
 * 로그아웃의 본체는 서버측 sessions 행 삭제다 — 쿠키를 지우는 것만으로는
 * 훔쳐 간 토큰이 계속 유효하기 때문이다.
 *
 * @var array<string,mixed>|null $user
 * @var list<string>             $params
 */

Auth::logout();

Http::json(['ok' => true]);
