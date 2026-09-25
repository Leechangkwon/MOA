<?php
declare(strict_types=1);

/**
 * src/handlers/time.php — GET /api/time (정책: public)
 *
 * 서버의 지금 시각(유닉스 초 · 밀리초)과 코드가 바뀌는 주기만 알려준다. 오프라인 2단계 코드 생성기가
 * 「서버 시각 − PC 시각」 차이를 구해 남은 초·코드를 서버 기준으로 맞추는 데 쓴다
 * (PRD F-42, ARCHITECTURE 5.21).
 *
 * 담는 값은 `now`(정수 초)·`now_ms`(정수 밀리초)·`period` 셋뿐이다. `now_ms` 는 보안 넷(2026-09-26, SECURITY R-79)이
 * 더했다 — 정수 초만 주면 생성기의 보정값이 0~1초 뒤처져 스텝 경계 직후 1~2초 동안 이전 스텝 코드를 보였고,
 * 서버 창이 0 이라 그 코드는 401 이었다. `now` 는 그대로 둔다(= `now_ms` 를 1000 으로 나눈 몫 — 이미 내려받아 둔
 * 옛 생성기 사본은 `now` 만 읽는다). 서버의 코드 판정(Totp 창 0)은 바뀌지 않는다.
 * 버전·서버 이름·설정 값 같은 정찰 재료는 담지 않는다
 * (health 와 같은 원칙 — PRD S-09-5 취지). 로그인·CSRF·감사 기록이 없다: 읽기만 하고
 * 대상이 없으며, 시각은 응답 헤더 `Date` 로도 이미 나가는 값이다.
 *
 * CORS 를 **이 창구에만** 연다. 생성기 파일은 PC 에 내려받아 `file://` 로 여는 것이 전제라
 * 출처가 달라지는데, 여기서 나가는 것은 시각 하나뿐이라 다른 출처가 읽어도 잃을 것이 없다.
 * 다른 라우트에는 이 헤더가 없다 — 세션 쿠키로 읽히는 응답에 붙으면 곧 정보 유출이다.
 *
 * @var array<string,mixed>|null $user   이 라우트는 public 이므로 항상 null
 * @var list<string>             $params 경로 변수 없음
 */

if (!headers_sent()) {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET');
}

// 한 번 읽은 시각에서 둘 다 만든다 — `now` 와 `now_ms` 가 서로 다른 순간을 가리키지 않게.
$nowMs = (int) floor(microtime(true) * 1000);
Http::json(['now' => intdiv($nowMs, 1000), 'now_ms' => $nowMs, 'period' => Totp::PERIOD]);
