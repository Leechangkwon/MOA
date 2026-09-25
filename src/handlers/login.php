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
// 2단계 인증 코드 — 순차 로그인의 **2단계에서만** 온다 (ARCH 4.0, SECURITY R-05).
// 1단계(아이디·비밀번호만)에서 이 계정에 TOTP 가 켜져 있으면 서버가 401 + mfa_required 로 답하고,
// 화면은 그때 비로소 코드 칸을 펼쳐 세 값을 다시 보낸다. **필요한지는 서버가 정한다.**
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
} catch (AuthMfaRequiredException $e) {
    // 비밀번호는 맞았고 코드만 더 필요하다 — 세션은 발급하지 않았고 실패로도 세지 않았다.
    // mfa_required 는 **비밀번호를 맞힌 사람에게만** 나가는 유일한 추가 정보다.
    // (비밀번호가 틀리면 아래 401 동일 문구뿐이라 2단계 인증 사용 여부를 알 수 없다.)
    Http::json(['error' => Http::MSG_MFA_REQUIRED, 'mfa_required' => true], 401);
    exit;
} catch (AuthTempPasswordExpiredException $e) {
    // 보안 넷 ④ (SECURITY R-39) — 비밀번호는 맞았지만 발급 72시간이 지난 임시 비밀번호다. 세션은 없다.
    // mfa_required 와 같은 자리의 정보(비밀번호가 맞은 사람에게만)이고, 할 일(관리자에게 재발급 요청)을 문장으로 알린다.
    // 응답 모양은 {"error": …} 하나 — 화면은 서버 문장을 그대로 띄운다(app.js errorMessage).
    Http::error(401, Auth::MSG_TEMP_PASSWORD_EXPIRED);
}

if ($result === null) {
    Http::error(401, Http::MSG_LOGIN_FAILED);
}

/*
 * 11단계 (PRD F-16 ②, ARCH 4.0) — 세션은 발급됐지만 **아직 다 끝난 것이 아닐 수 있다**.
 * `next` 는 "다음에 무엇을 해야 하나" 한 값뿐이고(`password_change`·`mfa_setup`·null),
 * 계산은 Auth::nextStep 한 곳에서만 한다 — /api/me 도 같은 값을 돌려준다.
 * 이 값은 **편의**다: 화면이 무시하고 다른 기능을 불러도 라우터 관문이 403 으로 막는다.
 *
 * F-52 (2026-09-16, ARCH 5.26) — 두 값을 더 준다.
 *   mfa_required     이 계정의 **실제 역할**에 2단계 인증이 필수인가 (Admin)
 *   mfa_recommended  필수는 아닌데 아직 안 켠 상태인가 — 홈의 권장 카드가 이 값만 본다
 * 둘 다 **상태**이지 권한이 아니다. 판정은 Auth 안에서만 하고(화면·핸들러가 역할 문자열을
 * 직접 비교하지 않는다), 화면이 이 값을 무시해도 관문은 서버가 막는다 (PRD S-01).
 */
Http::json([
    'ok'              => true,
    'next'            => Auth::nextStep($result),
    'mfa_required'    => Auth::mfaRequired($result),
    'mfa_recommended' => Auth::mfaRecommended($result),
]);
