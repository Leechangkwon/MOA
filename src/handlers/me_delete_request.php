<?php
declare(strict_types=1);

/**
 * src/handlers/me_delete_request.php — GET · POST /api/me/delete-request (사이트 Q:F-13 지시서 P2 · 기획 ../docs/privacy-terms-plan.md 5.1 · 5.2 「탈퇴」)
 *
 * 정책 authenticated · 대상은 언제나 **세션의 본인**(본문에 사람 칸이 없다). POST 는 라우터 [3] CSRF 를 지났다. 토큰 창구 집합 밖(사람의 세션만).
 *
 * GET  → 지금 열린 요청 하나 `{request: {id, status, requested_at, processed_at}|null}` (대기 · 처리됨만 — 파기된 계정은 로그인할 수 없다)
 * POST → 본문 `{"confirm": true}` 한 칸만(그 밖 키·값 400). **재인증 15분 창**이 닫혀 있으면 관문 [3-2] 와 같은 모양
 *        403 `{error, next:"reauth"}` + 감사 `auth.gate|deny|reauth_required`(정책은 authenticated 라 라우터 관문이 묻지 않는다 —
 *        files_restore.php 와 같은 방식으로 핸들러가 묻는다). 이미 열린 요청이 있으면 409 + 감사 `account.delete_request|deny|already_requested`.
 *        성공 201 `{request}` + 감사 `account.delete_request|allow`(detail.request_no). 사람마다 잠금(users 행 FOR UPDATE) 뒤에 판단한다 —
 *        두 번 누름이 두 줄이 되지 않게(부분 UNIQUE 도 같은 일을 한다).
 *
 * 요청만으로는 아무것도 지우지 않는다 — 관리자가 처리하면(admin_deletion_requests.php) 그때 비활성 · 로그인 수단 삭제,
 * 30일 뒤 scripts/privacy_purge.php 가 익명화·개인 공간 삭제를 한다.
 */

$deleteUid = (int) $user['id'];
$deleteDb  = Db::instance();
$deleteOpenSql = <<<'SQL'
    SELECT id, status, requested_at, processed_at
      FROM account_deletion_requests
     WHERE user_id = :uid AND status IN ('pending', 'processed')
     ORDER BY id DESC
     LIMIT 1
    SQL;

/** 응답 모양 — 날짜는 DB 글자 그대로(화면이 setWhen 으로 그린다) */
$deleteShape = static function ($row): ?array {
    if (!is_array($row)) {
        return null;
    }
    return [
        'id'           => (int) $row['id'],
        'status'       => (string) $row['status'],
        'requested_at' => (string) $row['requested_at'],
        'processed_at' => $row['processed_at'] === null ? null : (string) $row['processed_at'],
    ];
};

if (Http::method() === 'GET') {
    Http::json(['request' => $deleteShape($deleteDb->run($deleteOpenSql, [':uid' => $deleteUid])->fetch())]);
    exit;
}

$deleteBody = Http::jsonBody(256);
if (array_keys($deleteBody) !== ['confirm'] || $deleteBody['confirm'] !== true) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}

$deleteSelf = [
    'target_type' => 'user',
    'target_id'   => $deleteUid,
    'target_name' => (string) $user['username'],
];

// ─── [재인증] 탈퇴 요청은 되돌리기 어려운 일 — 15분 안에 비밀번호를 다시 확인한 세션만 (관문 [3-2] 와 같은 문장·같은 응답 모양) ───
if (($user['reauth_fresh'] ?? false) !== true) {
    Audit::deny('auth.gate', 'reauth_required', $deleteSelf + [
        // route 패턴은 Audit 이 TBOX_ROUTE 에서 자동으로 넣는다 (ARCH 7.5 route).
        'detail' => ['next' => 'reauth', 'policy' => Authz::POLICY_AUTHENTICATED],
    ]);
    Http::json(['error' => '탈퇴를 요청하려면 비밀번호를 다시 확인해야 합니다', 'next' => 'reauth'], 403);
    exit;
}

$deleteDb->begin();
try {
    $deleteLockSql = 'SELECT id FROM users WHERE id = :uid FOR UPDATE';
    $deleteDb->run($deleteLockSql, [':uid' => $deleteUid]);
    if (is_array($deleteDb->run($deleteOpenSql, [':uid' => $deleteUid])->fetch())) {
        $deleteDb->rollback();
        Audit::deny('account.delete_request', 'already_requested', $deleteSelf);
        Http::error(409, '이미 탈퇴를 요청했습니다. 관리자가 처리할 때까지 기다려 주세요.');
    }
    $deleteRow = $deleteDb->run(
        'INSERT INTO account_deletion_requests (user_id) VALUES (:uid) RETURNING id, status, requested_at, processed_at',
        [':uid' => $deleteUid]
    )->fetch();
    Audit::allow('account.delete_request', $deleteSelf + ['detail' => ['request_no' => (int) $deleteRow['id']]]);
    $deleteDb->commit();
} catch (Throwable $deleteError) {
    if ($deleteDb->inTransaction()) {
        $deleteDb->rollback();
    }
    throw $deleteError;
}

Http::json(['request' => $deleteShape($deleteRow)], 201);
