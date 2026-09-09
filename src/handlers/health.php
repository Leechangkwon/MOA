<?php
declare(strict_types=1);

/**
 * src/handlers/health.php — GET /api/health (정책: public)
 *
 * 살아 있는지만 알려준다. DB 버전·PHP 버전·서버 이름·설정 값 같은 정보는 담지 않는다.
 * 정찰(reconnaissance)에 쓸 수 있는 정보를 인증 없이 내주지 않는 것이 목적이다 (PRD S-09-5 취지).
 *
 * @var array<string,mixed>|null $user   이 라우트는 public 이므로 항상 null
 * @var list<string>             $params 경로 변수 없음
 */

Http::json(['ok' => true]);
