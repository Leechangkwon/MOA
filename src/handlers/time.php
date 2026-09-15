<?php
declare(strict_types=1);

/**
 * src/handlers/time.php — GET /api/time (정책: public)
 *
 * 서버의 지금 시각(유닉스 초)과 코드가 바뀌는 주기만 알려준다. 오프라인 2단계 코드 생성기가
 * 「서버 시각 − PC 시각」 차이를 구해 남은 초·코드를 서버 기준으로 맞추는 데 쓴다
 * (PRD F-42, ARCHITECTURE 5.21).
 *
 * 담는 값은 `now`·`period` 둘뿐이다. 버전·서버 이름·설정 값 같은 정찰 재료는 담지 않는다
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

Http::json(['now' => time(), 'period' => Totp::PERIOD]);
