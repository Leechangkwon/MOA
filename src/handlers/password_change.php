<?php
declare(strict_types=1);

/**
 * src/handlers/password_change.php — POST /api/me/password (정책: authenticated)
 *
 * 요구사항: PRD 7.7("인증 · 비밀번호 변경 · 다른 기기 세션 강제 종료" — 성공·실패 모두 기록),
 *           S-09-4(Argon2id · 최소 10자), ARCH 7.4 `auth.password_change` 3행,
 *           7.5 `sessions_revoked`, SECURITY R-10 · R-33(시드 비밀번호 파일 수명)
 *
 * 대상은 언제나 **세션의 본인**이다. 요청 본문에 "누구의 비밀번호를" 는 받지 않는다 —
 * 받는 순간 그것이 남의 비밀번호를 바꾸는 통로가 된다 (PRD S-01).
 *
 * 받는 값 (JSON, 허용 키 두 개)
 *   current  현재 비밀번호 — 세션이 잠깐 새어 나가도 비밀번호까지 바뀌지 않게 하는 자물쇠
 *   new      새 비밀번호 — 10자 이상
 *
 * 응답
 *   200 `{ok, sessions_revoked, tokens_revoked}` — 바꾼 뒤 **이 세션만 남고** 다른 기기는 즉시 끊긴다.
 *       디버깅 묶음 S-5: 그 계정의 개인 프로그램 토큰도 전부 회수된다(`tokens_revoked` = 개수)
 *   400 현재 비밀번호 오류(`bad_credentials`) · 새 비밀번호 규칙 위반(`weak_password`) ·
 *       현재와 같은 값(`same_password`, 11단계 F-16)
 *
 * 11단계 (PRD F-16, ARCH 4.0): 이 라우트는 **최초 로그인 강제 절차 중에도 열려 있다**(관문 허용 목록).
 * 성공하면 `must_change_password` 해제와 `password_changed_at` 기록이 Auth::changePassword 안의
 * 같은 UPDATE 문장에서 함께 일어나고, 강제 절차 중의 변경이면 감사 detail 에 `forced: true` 가 붙는다 —
 * 플래그를 만지는 코드가 여러 곳에 흩어지지 않게 전부 Auth 한 곳에 둔다.
 *
 * 왜 현재 비밀번호 오류가 401 이 아니라 400 인가: 이 요청은 이미 인증을 통과했다.
 * 401 로 답하면 화면(app.js)이 "세션이 끊겼다" 로 보고 로그인 화면으로 보내 버린다.
 *
 * 13단계 (SECURITY R-48, PRD T-30) — 실패 응답은 사유가 무엇이든 `MSG_BAD_REQUEST` **하나**다.
 * 전에는 현재 비밀번호 오류만 `MSG_LOGIN_FAILED` 로 갈라져, 응답 문구만 보고 "현재 비밀번호를
 * 맞혔는지" 를 알 수 있는 오라클이 되었다(`/api/me/mfa/disable` 도 같았다). 사유는 감사 로그의
 * `reason`(bad_credentials·weak_password·same_password)에만 남는다 — 로그를 볼 수 있는 사람은
 * Admin 뿐이고, 요청을 보낸 사람은 어느 쪽이 틀렸는지 알 수 없다.
 * 현재 비밀번호를 틀리면 `Auth::countFailure` 가 실패로 세고 5회에 계정이 잠긴다(세션도 회수).
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

// 형태 검사(문자열인가·상한을 넘지 않았나)만 여기서 한다. 10자 규칙과 현재 비밀번호 확인은
// Auth::changePassword 한 곳에서 하고, 감사 기록도 거기서 남는다 — 규칙이 두 곳에 흩어지지 않게.
if (!is_string($current) || !is_string($new)
    || $current === '' || $new === ''
    || strlen($current) > Auth::MAX_PASSWORD_BYTES
    || strlen($new) > Auth::MAX_PASSWORD_BYTES
) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}

[$ok, $reason, $revoked, $tokensRevoked] = Auth::changePassword($user, $current, $new);
unset($current, $new, $body);   // 평문을 이 뒤 코드로 흘려보내지 않는다

if (!$ok) {
    // 문구 통일 (13단계, SECURITY R-48). 실패 집계·잠금·세션 회수는 Auth::changePassword 안에서
    // 이미 끝났다 — 규칙을 핸들러로 흘리지 않는다.
    unset($reason);
    Http::error(400, Http::MSG_BAD_REQUEST);
}

// S-5 — 회수한 개인 프로그램 토큰 수(개수만 — 토큰 번호·이름은 감사에). 화면이 결과 문장에 쓴다.
Http::json(['ok' => true, 'sessions_revoked' => $revoked, 'tokens_revoked' => $tokensRevoked]);
