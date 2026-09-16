<?php
declare(strict_types=1);

/**
 * src/handlers/api_tokens_revoke.php — DELETE /api/users/{uid}/api-tokens/{tid}
 *                                       (정책: user.manage → Admin 만 + 재인증 관문 [3-2])
 *
 * 요구사항: PRD F-59 ② · 8장(`/api/users/{uid}/api-tokens/{tid}` DELETE 행),
 *           ARCHITECTURE 4.6 · 6.2-5 · 7.4 `api_token.revoke` 3행 · 7.5 `token_id`·`token_name`,
 *           API.md 13장, SECURITY R-91
 *
 * 하는 일: 개인 토큰을 **회수**한다. 회수한 순간부터 그 토큰으로 온 요청은 401
 * 「API 토큰이 올바르지 않습니다」 다 — `Auth::requireApiToken` 이 `revoked_at IS NULL` 인
 * 행만 보기 때문이고, 「회수됐다」는 사실을 요청자에게 알리지 않는다(틀린 토큰과 같은 답).
 *
 * ★ 행을 **지우지 않는다**(UPDATE `revoked_at`·`revoked_by`). 「그 토큰이 언제부터 언제까지
 *   살아 있었나」 가 조사에 필요하고, 앱 계정에는 이 표의 DELETE 자격 자체가 없다(6.2-5).
 * ★ 토큰 값도 해시도 읽지 않는다 — 회수에 필요한 것은 번호뿐이다.
 *
 * 거부
 *   404  없는 계정 · 없는 토큰 · **그 계정의 토큰이 아님** (전부 같은 문구 — 남의 토큰 번호를
 *        찔러 보아도 존재 여부가 새지 않는다) + `api_token.revoke deny not_found`
 *   400  이미 회수된 토큰 + `api_token.revoke deny bad_request`
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자 (Admin)
 * @var list<string>        $params $params[0] = users.id · $params[1] = api_tokens.id
 */

$uidRaw = $params[0] ?? '';
$tidRaw = $params[1] ?? '';
if (!is_string($uidRaw) || preg_match('/^[0-9]{1,9}$/', $uidRaw) !== 1
    || !is_string($tidRaw) || preg_match('/^[0-9]{1,9}$/', $tidRaw) !== 1
) {
    Http::error(404, Http::MSG_NOT_FOUND);
}
$uid = (int) $uidRaw;
$tid = (int) $tidRaw;

$db = Db::instance();

// ─── [0] 대상 계정 ───────────────────────────────────────────────
$sqlUser = <<<'SQL'
    SELECT id, username FROM users WHERE id = :id
    SQL;
$target = $db->run($sqlUser, [':id' => $uid])->fetch();

$auditTarget = [
    'target_type' => 'user',
    'target_id'   => $uid,
    'target_name' => is_array($target) ? (string) $target['username'] : null,
];

if (!is_array($target)) {
    Audit::deny('api_token.revoke', 'not_found', $auditTarget);
    Http::error(404, Http::MSG_NOT_FOUND);
}

// ─── [1] 토큰 행 — **그 계정의 것만** (IDOR 차단) ────────────────
$sqlToken = <<<'SQL'
    SELECT id, name, revoked_at
      FROM api_tokens
     WHERE id = :tid AND user_id = :uid
    SQL;
$token = $db->run($sqlToken, [':tid' => $tid, ':uid' => $uid])->fetch();
if (!is_array($token)) {
    Audit::deny('api_token.revoke', 'not_found', $auditTarget);
    Http::error(404, Http::MSG_NOT_FOUND);
}

$tokenName = (string) $token['name'];
$auditDetail = ['token_id' => $tid, 'token_name' => $tokenName];

// ─── [2] 이미 회수된 토큰 ───────────────────────────────────────
if ($token['revoked_at'] !== null) {
    Audit::deny('api_token.revoke', 'bad_request', $auditTarget + ['detail' => $auditDetail]);
    Http::error(400, Http::MSG_BAD_REQUEST);
}

// ─── [3] 회수 + 기록 — 한 트랜잭션 ──────────────────────────────
//        `revoked_at IS NULL` 을 조건에 다시 넣어, 같은 순간에 들어온 두 요청 중
//        하나만 회수 행을 쓰게 한다(읽고 나서 쓰는 사이의 겹침).
$sqlRevoke = <<<'SQL'
    UPDATE api_tokens
       SET revoked_at = now(),
           revoked_by = :by
     WHERE id = :tid AND user_id = :uid AND revoked_at IS NULL
    SQL;

$db->begin();
try {
    $done = $db->run($sqlRevoke, [
        ':by'  => (int) $user['id'],
        ':tid' => $tid,
        ':uid' => $uid,
    ])->rowCount();

    if ($done !== 1) {
        // 사이에 다른 요청이 먼저 회수했다 — 「이미 회수됨」과 같은 답이다.
        $db->rollback();
        Audit::deny('api_token.revoke', 'bad_request', $auditTarget + ['detail' => $auditDetail]);
        Http::error(400, Http::MSG_BAD_REQUEST);
    }

    Audit::allow('api_token.revoke', $auditTarget + ['detail' => $auditDetail]);
    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollback();
    }
    throw $e;
}

Http::json(['ok' => true]);
