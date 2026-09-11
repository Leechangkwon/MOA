<?php
declare(strict_types=1);

/**
 * src/handlers/me.php — GET /api/me (정책: authenticated)
 *
 * 요구사항: PRD 8장, S-09-3(CSRF 토큰 전달 통로), ARCH 4.0
 *
 * 화면이 필요한 최소한만 내려 준다.
 *   · username / role / team_id — 화면 표시용. 권한 판단의 근거가 아니다.
 *     역할을 내려 주지만 허용 여부는 매 요청 서버 Authz 가 다시 판단한다 (PRD S-01).
 *   · csrf_token — 상태 변경 요청의 X-CSRF-Token 헤더에 실을 값.
 *     세션 쿠키(HttpOnly)와 달리 JS 가 읽어야 하므로 본문으로 내려간다.
 *     이 값은 세션에 결속되어 있어 다른 세션에서는 쓸 수 없다.
 *   · upload_levels — 업로드 폼의 <select> 를 채울 레벨 목록 (4단계, PRD 8장·10장).
 *     화면이 고정 목록을 들고 있으면 역할 판단이 화면에 생긴다 — 그래서 서버가 내려 준다.
 *     이것은 **선택지일 뿐 권한이 아니다**: 목록에 없는 값을 보내도 서버가 다시 검사한다
 *     (Authz::decideUploadAs, PRD S-01).
 *
 * 내부 id 는 내지 않는다 — 외부에 노출할 필요가 없는 값이다 (PRD S-09-5 취지).
 * 응답은 Cache-Control: no-store 로 나간다 (Http::json).
 *
 * @var array<string,mixed>      $user   라우터가 넘긴 현재 사용자
 * @var list<string>             $params 경로 변수 없음
 */

Http::json([
    'username'   => (string) $user['username'],
    'role'       => (string) $user['role'],
    'team_id'    => $user['team_id'],
    'csrf_token' => Auth::csrfToken(),
    'upload_levels' => Authz::uploadableLevels($user),
    // 7단계 — "URL 로 가져오기" 패널을 그릴지 여부 (F-14, Manager 이상).
    // 화면은 이 값만 본다. 역할 문자열을 보고 화면이 스스로 판단하지 않는다 (PRD S-01).
    // 감추는 것은 편의일 뿐이고, POST /api/files/fetch 는 라우터 정책 file.fetch 가 다시 막는다.
    'can_fetch'     => Authz::canFetch($user),
]);
