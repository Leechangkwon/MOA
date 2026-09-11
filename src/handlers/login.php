<?php
declare(strict_types=1);

/**
 * src/handlers/login.php — POST /api/login (정책: public)
 *
 * 요구사항: PRD F-01 · S-09-4 · 10장(실패 사유 비노출), ARCH 4.0
 *
 * 이 라우트만 public 이면서 상태를 바꾼다. 대신 세 가지로 막는다.
 *   1) JSON 본문만 받는다 — 크로스사이트 폼(<form>) 으로는 보낼 수 없는 형식이다 (+ SameSite=Lax)
 *   2) nginx IP 속도 제한 (deploy/sites/tbox-v2.conf 의 `location = /api/login`, 초과 429)
 *   3) 계정 단위 5회 잠금 (Auth::login)
 *
 * 응답에는 사용자 정보를 담지 않는다. 로그인 뒤 필요한 값은 GET /api/me 로 받아 간다.
 * 실패 문구는 아이디 없음·비밀번호 틀림·비활성 계정이 모두 같다 (PRD 10장).
 *
 * @var array<string,mixed>|null $user   이 라우트는 public 이므로 항상 null
 * @var list<string>             $params 경로 변수 없음
 */

$body = Http::jsonBody();

$username = $body['username'] ?? null;
$password = $body['password'] ?? null;
// 10단계 — 2단계 인증 코드(선택). 켜 두지 않은 계정에는 쓰이지 않는다 (SECURITY R-05).
// **필요한지 아닌지는 서버가 정한다** — 화면은 늘 칸을 보여 주고 값이 있으면 실어 보낼 뿐이다.
$otp = $body['otp'] ?? null;

// 형식 검사 — 여기서 걸리는 것은 "인증 실패" 가 아니라 "요청이 잘못된 것" 이므로 400 이다.
// (401 로 답하면 잘못 만든 클라이언트가 계정 잠금 카운트를 올릴 수 있다)
// otp 는 없어도 되고, 있으면 짧은 문자열이어야 한다. 값의 옳고 그름은 Auth 가 본다.
if (!is_string($username) || !is_string($password)
    || $username === '' || $password === ''
    || strlen($username) > 64 || strlen($password) > 1024
    || ($otp !== null && (!is_string($otp) || strlen($otp) > 16))) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}

try {
    $result = Auth::login($username, $password, $otp === null ? null : (string) $otp);
} catch (AuthLockedException $e) {
    Http::error(423, Http::MSG_LOGIN_LOCKED);
}

if ($result === null) {
    Http::error(401, Http::MSG_LOGIN_FAILED);
}

Http::json(['ok' => true]);
