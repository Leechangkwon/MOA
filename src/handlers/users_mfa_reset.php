<?php
declare(strict_types=1);

/**
 * src/handlers/users_mfa_reset.php — POST /api/users/{uid}/mfa/reset
 *                                     (정책: user.manage → Admin 만, PRD 5.4)
 *
 * 요구사항: PRD F-16 ⑤ · 8장(`/api/users/{uid}/mfa/reset` 행) · S-07("관리자 행동 사유"),
 *           ARCH 4.0 「최초 로그인 강제 절차」 · 7.4 `user.mfa_reset` 3행 ·
 *           7.5 `reason_text`·`sessions_revoked`, SECURITY R-05 · R-10 · R-20
 *
 * 왜 필요한가: 인증 앱이 든 휴대폰을 잃으면 **본인도 로그인할 수 없다**(켜 둔 계정은 코드가
 * 필요하므로). 그때 Admin 이 비밀을 지우고 다시 등록하게 한다.
 *
 *   · `mfa_secret = NULL`, `mfa_enabled = false` — 옛 비밀로 만든 코드는 그 순간 무효다
 *   · `mfa_setup_required = true`, `mfa_recovery_required = true` — 관리자가 지정한 복구 절차는
 *     초대 가입의 선택 설정과 구별해 다시 등록하게 한다. OTP 확인 성공만 복구 플래그를 내린다.
 *   · 대상의 **모든 세션을 끊는다** (R-10) — 코드 없이 들어가 있던 창을 남기지 않는다
 *
 * 받는 값 (JSON, 허용 키 **하나**): `reason_text` — 필수 500자. 없으면 400.
 * 자기 자신은 대상이 될 수 없다 (Authz::decideUserChange 의 `self_change` 재사용).
 * 비밀(secret)·코드는 응답·감사 로그 어디에도 나가지 않는다.
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자 (Admin)
 * @var list<string>        $params $params[0] = users.id (정수 문자열)
 */

$allowedFields = ['reason_text'];

$uidRaw = $params[0] ?? '';
if (!is_string($uidRaw) || preg_match('/^[0-9]{1,9}$/', $uidRaw) !== 1) {
    Http::error(404, Http::MSG_NOT_FOUND);
}
$uid = (int) $uidRaw;

$body = Http::jsonBody();
$db   = Db::instance();

// ─── [0] 대상 계정 (없으면 404 동일 문구) ────────────────────────
$sqlFind = <<<'SQL'
    SELECT id, username, role, is_active
      FROM users
     WHERE id = :id
    SQL;

$target = $db->run($sqlFind, [':id' => $uid])->fetch();
if (!is_array($target)) {
    Http::error(404, Http::MSG_NOT_FOUND);
}

$auditTarget = [
    'target_type' => 'user',
    'target_id'   => $uid,
    'target_name' => (string) $target['username'],
];

/** 거부 기록 후 400 으로 끝낸다 */
$reject = static function (string $reason) use ($auditTarget): void {
    Audit::deny('user.mfa_reset', $reason, $auditTarget);
    Http::error(400, Http::reasonMessage($reason));
};

// ─── [1] 허용 키 검사 ───────────────────────────────────────────
if ($body === [] || array_diff(array_keys($body), $allowedFields) !== []) {
    $reject('bad_request');
}

// ─── [2] 사유 — 필수 500자 (PRD S-07, SECURITY R-20) ─────────────
$reason = $body['reason_text'] ?? null;
if (!is_string($reason) || preg_match('//u', $reason) !== 1) {
    $reject('bad_request');
}
$reason = trim((string) $reason);
if ($reason === '' || iconv_strlen($reason, 'UTF-8') > 500) {
    $reject('bad_request');
}

// ─── [3] 자기 자신 금지 — 판단은 Authz 한 곳에서만 (PRD S-01) ─────
[$allowed, $code] = Authz::decideUserChange($user, $target, []);
if (!$allowed) {
    $reject($code);
}

// ─── [4] 초기화 — 비밀을 지우고 재등록을 강제한다 ────────────────
$sqlReset = <<<'SQL'
    UPDATE users
       SET mfa_secret         = NULL,
           mfa_enabled        = false,
           mfa_setup_required = true,
           mfa_recovery_required = true,
           updated_at         = now()
     WHERE id = :id
    SQL;

$db->begin();
try {
    $db->run($sqlReset, [':id' => $uid]);
    $revoked = Auth::revokeAllSessions($uid);
    // 디버깅 묶음 S-5 — 대상의 개인 프로그램 토큰도 전부 회수한다(같은 트랜잭션 · 토큰마다 감사 한 행,
    // `api_token.revoke allow mfa_reset`). 토큰 발급에는 2단계 인증이 필요했는데(F-66), 그 인증이 초기화되면
    // 그 전에 받은 토큰도 믿을 근거가 없다 (R-91).
    Auth::revokeAllApiTokens($uid, (string) $target['username'], (int) $user['id'], 'mfa_reset');
    // T:F-72 — 대상의 지문·얼굴 로그인 열쇠도 전부 해제한다(같은 트랜잭션 · 열쇠마다 감사 한 행 `auth.passkey_revoke allow mfa_reset`).
    //          잃어버린 휴대폰은 관리자에게 2단계 초기화를 요청하면 열쇠가 전부 꺼진다(지시서 1.1 「관리」).
    Auth::revokeAllPasskeys($uid, (string) $target['username'], (int) $user['id'], 'mfa_reset');

    Audit::allow('user.mfa_reset', $auditTarget + [
        'detail' => [
            'reason_text'      => $reason,
            'sessions_revoked' => $revoked,
            'target_role'      => (string) $target['role'],
            'target_active'    => $target['is_active'] === true,
        ],
    ]);
    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    throw $e;
}

Http::json(['ok' => true]);
