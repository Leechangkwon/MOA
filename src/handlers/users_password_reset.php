<?php
declare(strict_types=1);

/**
 * src/handlers/users_password_reset.php — POST /api/users/{uid}/password/reset
 *                                          (정책: user.manage → Admin 만, PRD 5.4)
 *
 * 요구사항: PRD F-16 ⑤ · 8장(`/api/users/{uid}/password/reset` 행) · S-07("관리자 행동 사유"),
 *           ARCH 4.0 「최초 로그인 강제 절차」 · 7.4 `user.password_reset` 3행 ·
 *           7.5 `temp_password_issued`·`reason_text`·`sessions_revoked`,
 *           SECURITY R-10(전 기기 로그아웃) · R-20(사유 필수) · R-33 · R-39
 *
 * 하는 일 — 비밀번호를 "아는 사람" 을 바꾸지 않고 **없앤다**.
 *   ① 서버가 새 임시 비밀번호를 만든다 (Auth::generateTempPassword — random_int 16자)
 *   ② 해시만 저장하고 `must_change_password = true` 로 둔다 → 대상은 다음 로그인에 반드시 바꾼다
 *   ③ 대상의 **모든 세션을 끊는다** — 옛 비밀번호로 들어가 있던 창이 남아 있으면
 *      "재발급했는데 그 기기에서는 그대로" 가 된다 (R-10, T-22 와 같은 원칙)
 *   ④ 평문은 **이 응답 한 번**만 나간다. 감사 로그에는 `temp_password_issued: true` 만 남는다
 *      (ARCH 7.5 — "값은 절대 기록하지 않는다"). 전달 구간의 위험은 R-39 로 남아 있고,
 *      그래서 첫 로그인 변경 강제가 그 값의 수명을 끝낸다.
 *
 * 받는 값 (JSON, 허용 키 **하나**): `reason_text` — 필수 500자. 없으면 400.
 * 자기 자신은 대상이 될 수 없다 — 판단은 Authz::decideUserChange 의 `self_change` 를 그대로 쓴다
 * (역할·자기 자신 비교를 이 파일에서 직접 하지 않는다 — CLAUDE.md 2장 금지 패턴).
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

// ─── [0] 대상 계정 (없으면 404 동일 문구 — 있는지 없는지 알려 주지 않는다) ───
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
    Audit::deny('user.password_reset', $reason, $auditTarget);
    Http::error(400, Http::MSG_BAD_REQUEST);
};

// ─── [1] 허용 키 검사 (R-07 과 같은 원칙: 모르는 키가 하나라도 있으면 거부) ───
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

// ─── [4] 새 임시 비밀번호 — 서버가 만든다 (F-16 ①, ARCH 4.0) ─────
$temp = Auth::generateTempPassword();
$hash = password_hash($temp, PASSWORD_ARGON2ID);
if (!is_string($hash)) {
    throw new RuntimeException('비밀번호 해시를 만들 수 없습니다');
}

$sqlReset = <<<'SQL'
    UPDATE users
       SET password_hash        = :hash,
           must_change_password = true,
           updated_at           = now()
     WHERE id = :id
    SQL;

$db->begin();
try {
    $db->run($sqlReset, [':hash' => $hash, ':id' => $uid]);
    // 대상의 세션을 전부 끊는다 (SECURITY R-10). 재발급한 Admin 의 세션은 대상이 아니다.
    $revoked = Auth::revokeAllSessions($uid);

    Audit::allow('user.password_reset', $auditTarget + [
        'detail' => [
            'reason_text'          => $reason,
            // 값이 아니라 **사실**만 남긴다 (ARCH 7.5 temp_password_issued)
            'temp_password_issued' => true,
            'sessions_revoked'     => $revoked,
            'target_role'          => (string) $target['role'],
            'target_active'        => $target['is_active'] === true,
        ],
    ]);
    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    throw $e;
}

// 평문은 여기 한 번만 나간다. 응답은 Cache-Control: no-store 로 간다 (Http::json).
Http::json(['ok' => true, 'temp_password' => $temp]);
unset($temp, $hash, $body);
