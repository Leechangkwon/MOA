<?php
declare(strict_types=1);

/**
 * src/community/handlers/today_page.php — GET /community/today (정책: community.read)
 *
 * Q:F-12 (WK-8 ② · 커뮤니티 ARCHITECTURE 23.4 · 23.8) — 「오늘」 화면은 「내 작업」으로 옮겼다. 옛 주소·즐겨찾기는
 * **308** 로 `/community/my-tasks` 에 넘긴다. 대상은 고정 문자열 하나다 — 요청의 경로·쿼리·머리는 Location 에 닿지 않는다.
 * 로그인 관문은 라우터가 먼저 돈다(미로그인 302 로그인 화면 — 전과 같다). 요약 창구 `GET /api/briefing` 은 그대로다.
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params 경로 변수 없음
 */

Http::redirectPermanent(Http::communityUrl('/my-tasks'));
