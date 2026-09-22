<?php
declare(strict_types=1);

/**
 * src/handlers/me_tokens_revoke.php — DELETE /api/me/api-tokens/{tid} (정책: authenticated)
 *
 * 요구사항: PRD F-66 ① · 8장(`/api/me/api-tokens/{tid}` DELETE 행) · T-78 (e),
 *           ARCHITECTURE 4.7 「본인 창구 셋」 · 6.2-5 · 7.4 `api_token.revoke` 3행 ·
 *           7.5 `token_id`·`token_name`, API.md 13장, SECURITY R-97 · R-91
 *
 * 하는 일: **내** 개인 토큰을 회수한다. 회수한 순간부터 그 토큰으로 온 요청은 401
 * 「API 토큰이 올바르지 않습니다」 다 — `Auth::requireApiToken` 이 `revoked_at IS NULL` 인
 * 행만 보기 때문이고, 「회수됐다」는 사실을 요청자에게 알리지 않는다(틀린 토큰과 같은 답.
 * **만료**는 문구가 다르다 — 시간의 결과라 숨길 이유가 없다, ARCH 4.7).
 *
 * ★ 비밀번호·2단계 코드를 묻지 않는다 (ARCH 4.7). 닫는 방향은 자격을 **줄이는** 일이고,
 *   세션을 훔친 사람이 남의 토큰을 회수해 봐야 얻는 것이 없다. 발급(여는 방향)만 묻는다.
 * ★ 대상은 언제나 **세션의 본인**이다(`WHERE user_id = :uid`). 남의 토큰 번호를 찔러 보면
 *   404 동일 문구다 — 그 번호가 존재하는지도 새지 않는다(IDOR 차단).
 * ★ 행을 **지우지 않는다**(UPDATE `revoked_at`·`revoked_by`). 앱 계정에는 이 표의 DELETE
 *   자격 자체가 없다(6.2-5).
 * ★ 토큰 값도 해시도 읽지 않는다 — 회수에 필요한 것은 번호뿐이다.
 * ★ 역할 판단을 여기서 하지 않는다. 라우트 정책 `authenticated` 가 로그인만 본다 (PRD S-01).
 *
 * 거부
 *   404  없는 토큰 · **내 토큰이 아님** (같은 문구) + `api_token.revoke deny not_found`
 *   400  이미 회수된 토큰 + `api_token.revoke deny bad_request`
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자 (대상은 언제나 본인)
 * @var list<string>        $params $params[0] = api_tokens.id
 */

$tidRaw = $params[0] ?? '';
if (!is_string($tidRaw) || preg_match('/^[0-9]{1,9}$/', $tidRaw) !== 1) {
    Http::error(404, Http::MSG_NOT_FOUND);
}
$tid    = (int) $tidRaw;
$userId = (int) $user['id'];

$db = Db::instance();

/** 감사 로그의 「무엇을」 칸 — 회수하는 사람도 대상도 본인이다 */
$auditTarget = [
    'target_type' => 'user',
    'target_id'   => $userId,
    'target_name' => (string) $user['username'],
];

// ─── [1] 토큰 행 — **내 것만** (IDOR 차단) ───────────────────────
$sqlToken = <<<'SQL'
    SELECT id, name, revoked_at
      FROM api_tokens
     WHERE id = :tid AND user_id = :uid
    SQL;
$token = $db->run($sqlToken, [':tid' => $tid, ':uid' => $userId])->fetch();
if (!is_array($token)) {
    Audit::deny('api_token.revoke', 'not_found', $auditTarget);
    Http::error(404, Http::MSG_NOT_FOUND);
}

$auditDetail = ['token_id' => $tid, 'token_name' => (string) $token['name']];

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
        // 회수한 사람도 본인이다 (ARCH 4.7).
        ':by'  => $userId,
        ':tid' => $tid,
        ':uid' => $userId,
    ])->rowCount();

    if ($done !== 1) {
        // 사이에 다른 창에서 먼저 회수했다 — 「이미 회수됨」과 같은 답이다.
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
