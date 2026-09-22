<?php
declare(strict_types=1);

/**
 * src/handlers/mfa_enroll.php — POST /api/me/mfa/enroll (정책: authenticated)
 *
 * 요구사항: SECURITY R-05(Admin 계정 탈취 — 비밀번호 단일 요소), PRD S-09-4 주변, ARCH 7.4 `auth.mfa_enroll`
 *
 * 2단계 인증 켜기는 **두 걸음**이다.
 *   ① enroll  — 서버가 새 비밀(TOTP secret)을 만들어 저장하되 `mfa_enabled` 는 **false 로 둔다**.
 *               응답으로 비밀과 otpauth 문자열을 딱 한 번 돌려준다.
 *   ② confirm — 사용자가 인증 앱에 넣고 만든 코드가 맞아야 `mfa_enabled = true` 가 된다
 *               (src/handlers/mfa_confirm.php).
 * 두 걸음으로 나누는 이유: ①만으로 켜 버리면, 인증 앱에 제대로 등록하지 못한 사람이
 * 그 순간부터 **자기 계정에 영영 못 들어간다**. 코드가 실제로 맞는 것을 확인한 뒤에만 켠다.
 *
 * 비밀이 밖으로 나가는 곳은 **이 응답 한 곳뿐**이다.
 *   · 감사 로그에 남기지 않는다 (Audit::FORBIDDEN_DETAIL_KEYS 의 `secret`·`mfa` 가 다시 한 번 막는다)
 *   · app.log·오류 문구에도 넣지 않는다
 *   · 응답은 `Cache-Control: no-store`(Http::json) 로 나간다. QR 은 `src/Qr.php`(외부 의존 0)가
 *     만든 모듈 행렬을 화면이 canvas 에 그린다 — 이미지 파일·외부 요청 없음 (F-51, ARCH 5.25).
 *     비밀 키·`otpauth` 주소는 전과 같이 글자로도 함께 보여 준다(카메라를 못 쓰는 경우)
 *
 * 이미 켜져 있는 계정은 다시 enroll 할 수 없다(400). 켜진 상태에서 비밀을 갈아 끼우면
 * 옛 비밀로 만든 코드가 갑자기 안 맞게 되어 잠기기 때문이다. 끄고(disable) 다시 켠다.
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자 (대상은 언제나 본인)
 * @var list<string>        $params 경로 변수 없음
 */

// 본문은 받지 않는다. 그래도 Content-Type 은 JSON 이어야 한다(폼 전송 차단, ARCH 4.0).
$body = Http::jsonBody();
if ($body !== []) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}

$userId = (int) $user['id'];
$db     = Db::instance();

/** 감사 로그의 "무엇을" 칸 — 대상은 언제나 본인이다 */
$auditTarget = [
    'target_type' => 'user',
    'target_id'   => $userId,
    'target_name' => (string) $user['username'],
];

$sqlState = <<<'SQL'
    SELECT mfa_enabled FROM users WHERE id = :id
    SQL;

$row = $db->run($sqlState, [':id' => $userId])->fetch();
if (!is_array($row)) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}
if ($row['mfa_enabled'] === true) {
    // 이미 켜져 있다 — 비밀을 갈아 끼우면 옛 코드가 안 맞게 되어 계정이 잠긴다.
    // 끄고(disable) 다시 켠다. 거부도 기록한다 (ARCH 7.4 `auth.mfa_enroll|deny|already_enabled`).
    Audit::deny('auth.mfa_enroll', 'already_enabled', $auditTarget);
    Http::error(400, Http::reasonMessage('already_enabled'));
}

$secret = Totp::generateSecret();

$sqlSave = <<<'SQL'
    UPDATE users
       SET mfa_secret  = :secret,
           mfa_enabled = false,
           updated_at  = now()
     WHERE id = :id
    SQL;

$db->run($sqlSave, [':secret' => $secret, ':id' => $userId]);

// 확정(confirm) 전이므로 감사 로그를 남기지 않는다 — 켜진 사실(`auth.mfa_enroll|allow`)은
// mfa_confirm.php 가 남긴다. 여기서 남기면 "켰다" 는 기록만 있고 실제로는 꺼져 있을 수 있다.
$otpauth = Totp::otpauthUri((string) $user['username'], $secret);

// QR 은 **편의**다 — 만들지 못하면 `null` 로 내리고 텍스트 등록은 그대로 된다 (PRD F-51 ⑤).
// 잡은 예외의 문구도 비밀도 로그·응답에 남기지 않는다.
try {
    $qr = Qr::encode($otpauth);
} catch (RuntimeException $e) {
    $qr = null;
}

Http::json([
    'secret'  => $secret,
    'otpauth' => $otpauth,
    'digits'  => Totp::DIGITS,
    'period'  => Totp::PERIOD,
    'qr'      => $qr,
]);
