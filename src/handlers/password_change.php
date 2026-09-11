<?php
declare(strict_types=1);

/**
 * src/handlers/password_change.php — POST /api/me/password (정책: authenticated)
 *
 * 요구사항: PRD 7.7("인증 · 비밀번호 변경 · 다른 기기 세션 강제 종료" — 성공·실패 모두 기록),
 *           S-09-4(Argon2id · 최소 12자), ARCH 7.4 `auth.password_change` 3행,
 *           7.5 `sessions_revoked`, SECURITY R-10 · R-33(시드 비밀번호 파일 수명)
 *
 * 대상은 언제나 **세션의 본인**이다. 요청 본문에 "누구의 비밀번호를" 는 받지 않는다 —
 * 받는 순간 그것이 남의 비밀번호를 바꾸는 통로가 된다 (PRD S-01).
 *
 * 받는 값 (JSON, 허용 키 두 개)
 *   current  현재 비밀번호 — 세션이 잠깐 새어 나가도 비밀번호까지 바뀌지 않게 하는 자물쇠
 *   new      새 비밀번호 — 12자 이상
 *
 * 응답
 *   200 `{ok, sessions_revoked}`   — 바꾼 뒤 **이 세션만 남고** 다른 기기는 즉시 끊긴다
 *   400 현재 비밀번호 오류(`bad_credentials`) · 새 비밀번호 규칙 위반(`weak_password`)
 *
 * 왜 현재 비밀번호 오류가 401 이 아니라 400 인가: 이 요청은 이미 인증을 통과했다.
 * 401 로 답하면 화면(app.js)이 "세션이 끊겼다" 로 보고 로그인 화면으로 보내 버린다.
 * 실패 문구는 로그인 실패와 **같은 문장**을 재사용한다 (새 문구를 만들지 않는다).
 *
 * 비밀번호는 옛것도 새것도 응답·감사 로그·app.log 어디에도 남지 않는다 (PRD 7.7 민감정보 금지).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params 경로 변수 없음
 */

$allowedFields = ['current', 'new'];

$body = Http::jsonBody();

if ($body === [] || array_diff(array_keys($body), $allowedFields) !== []) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}

$current = $body['current'] ?? null;
$new     = $body['new'] ?? null;

// 형태 검사(문자열인가·상한을 넘지 않았나)만 여기서 한다. 12자 규칙과 현재 비밀번호 확인은
// Auth::changePassword 한 곳에서 하고, 감사 기록도 거기서 남는다 — 규칙이 두 곳에 흩어지지 않게.
if (!is_string($current) || !is_string($new)
    || $current === '' || $new === ''
    || strlen($current) > Auth::MAX_PASSWORD_BYTES
    || strlen($new) > Auth::MAX_PASSWORD_BYTES
) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}

[$ok, $reason, $revoked] = Auth::changePassword($user, $current, $new);
unset($current, $new, $body);   // 평문을 이 뒤 코드로 흘려보내지 않는다

if (!$ok) {
    Http::error(400, $reason === 'bad_credentials' ? Http::MSG_LOGIN_FAILED : Http::MSG_BAD_REQUEST);
}

Http::json(['ok' => true, 'sessions_revoked' => $revoked]);
