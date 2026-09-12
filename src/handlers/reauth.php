<?php
declare(strict_types=1);

/**
 * src/handlers/reauth.php — POST /api/me/reauth (정책: authenticated)
 *
 * 요구사항: PRD F-23 · 8장 `/api/me/reauth` 행 · 10장 「재인증」 · T-36,
 *           ARCH 4.0 「관리 기능 재인증 — sudo 모드」 · 7.4 `auth.reauth` 두 행,
 *           SECURITY R-48(실패 집계·문구 통일) · R-59(재인증의 한계와 새 표면)
 *
 * 무엇을 하나: 관리 영역(계정·조직·감사 로그·휴지통·위키)에 들어가기 전에 **현재 비밀번호**를
 * 다시 확인하고, 맞으면 지금 이 세션의 창을 15분 연다(`sessions.reauth_at = now()`).
 * 실제로 막고 여는 것은 라우터 관문 [3-2] 이고(public/index.php), 이 창구는 그 관문이 보는
 * 값을 놓을 뿐이다.
 *
 * 대상은 언제나 **세션의 본인**이다. 본문에 계정·세션·창 길이를 받지 않는다 —
 * 받는 순간 그것이 남의 창을 여는 통로이거나 창을 무한히 늘리는 손잡이가 된다 (PRD S-01).
 *
 * 받는 값 (JSON, 허용 키 하나)
 *   password  현재 비밀번호
 *
 * 응답
 *   200 `{ok:true, reauth_until}`  — 이 시각까지 관리 영역이 열린다(화면 안내용 값)
 *   400 `MSG_BAD_REQUEST`          — 형식 오류·비밀번호 오류가 **같은 문구**다 (13단계 규칙, R-48).
 *                                    사유는 감사 로그의 reason(bad_credentials)에만 남는다.
 *   401                            — 세션이 없으면 라우터가 먼저 끝낸다 (쿠키 없이 부르면 401)
 *   423                            — 5회째 실패로 계정이 잠기면 그 다음 요청부터
 *
 * 왜 400 이고 401 이 아닌가: 이 요청은 이미 인증을 통과했다. 401 로 답하면 화면(app.js)이
 * "세션이 끊겼다" 로 보고 로그인 화면으로 보내 버린다 (`/api/me/password` 와 같은 판단).
 *
 * 2단계 인증 코드는 다시 묻지 않는다 (PRD F-23) — 로그인의 순차 2단계에서 이미 거쳤다.
 * 그래서 이 창구는 "비밀번호를 아는 공격자" 는 막지 못한다. 그 한계는 SECURITY R-59 에 적혀
 * 있고, 겹으로 두는 것은 ① 실패 5회 잠금·세션 회수(Auth::countFailureAndRevoke) ②
 * nginx `tbox_login` 존(30r/m, burst 10) ③ 창 15분 고정(연장 없음)이다.
 *
 * 강제 절차(F-16) 중에는 이 창구에 닿을 일이 없다 — 관리 라우트 자체가 관문 [3-1] 에서
 * 먼저 막히기 때문이다. 그래서 [3-1] 허용 목록에 이 경로를 넣지 않는다.
 *
 * 비밀번호는 응답·감사 로그·app.log 어디에도 남지 않는다 (PRD 7.7 민감정보 금지).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자 (DB 에서 읽은 값)
 * @var list<string>        $params 경로 변수 없음
 */

$allowedFields = ['password'];

$body = Http::jsonBody();

if ($body === [] || array_diff(array_keys($body), $allowedFields) !== []) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}

$password = $body['password'] ?? null;

// 형태 검사(문자열인가·상한을 넘지 않았나)만 여기서 한다. 확인·실패 집계·감사 기록은
// Auth::reauth 한 곳에서 하고 규칙을 핸들러로 흘리지 않는다 (password_change 와 같은 모양).
if (!is_string($password) || $password === '' || strlen($password) > Auth::MAX_PASSWORD_BYTES) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}

[$ok, $reauthUntil] = Auth::reauth($user, $password);
unset($password, $body);   // 평문을 이 뒤 코드로 흘려보내지 않는다

if (!$ok) {
    // 문구 통일 (13단계, SECURITY R-48). 실패 집계·잠금·세션 회수는 Auth::reauth 안에서 끝났다.
    Http::error(400, Http::MSG_BAD_REQUEST);
}

Http::json(['ok' => true, 'reauth_until' => $reauthUntil]);
