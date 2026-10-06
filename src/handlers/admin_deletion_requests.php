<?php
declare(strict_types=1);

/**
 * src/handlers/admin_deletion_requests.php — GET /api/admin/deletion-requests · POST /api/admin/deletion-requests/{num}
 *   (정책: role:Admin + 재인증 — 사이트 Q:F-13 지시서 P2 · 기획 ../docs/privacy-terms-plan.md 5.1 「탈퇴」 · 5.2 「탈퇴」)
 *
 * `role:Admin` 은 `Authz::REAUTH_POLICIES` 에 있어 **재인증 관문 [3-2]** 가 자동으로 따라붙는다(15분 창 밖이면 403 next:reauth —
 * 화면은 공용 재인증 창을 띄우고 같은 요청을 다시 보낸다). 이 파일에는 역할 비교가 없다 — 자기 자신·마지막 관리자 판정은
 * `Authz::decideUserChange`(계정 비활성화와 같은 판정 한 곳).
 *
 * GET  → 목록 `{requests:[…]}` — 대기 · 처리됨(파기 대기) 전부와 90일 안에 파기된 것. 줄마다 아이디·이름·요청 때·상태·처리한 사람·
 *        처리 때·파기 예정(처리 + 30일)·파기 때 + **남은 자료 수**(개인 공간 문서 · 사진 기록 · 여행 계획 — 인계할 것 확인용, 대기·처리됨만).
 *        목록 조회는 감사하지 않는다(admin_shares.php 와 같은 원칙).
 * POST → 본문 `{"confirm": true}` 한 칸만. 대기 중인 요청 하나를 **처리**한다(한 트랜잭션):
 *          계정 비활성 · 비밀번호를 아무도 모르는 값으로(Argon2id 해시 — 쓸 수 없는 비밀번호) · 2단계 비밀값 지움 ·
 *          세션 전부 끊기(Auth::revokeAllSessions) · 지문·얼굴 열쇠 해제(Auth::revokeAllPasskeys 'deactivate' — 열쇠마다 감사) ·
 *          개인 프로그램 토큰 회수 · 로그인 링크 · 일회용 문제 · ChatGPT 연결 지움 · 휴대폰 경보 받기 해지 · 요청 줄 processed
 *          (처리 때는 DB 트리거가 now() 로 고정한다). 감사 `account.delete_process|allow`(건수만).
 *        거부: 없는 번호 404 `not_found` · 대기 아님 409 `not_pending` · 자기 자신 400 `self_change` · 마지막 관리자 400 `last_admin`.
 *        행(줄)의 삭제는 여기서 하지 않는다 — 앱 계정은 그 표들에 DELETE 권한이 없다(소유자 함수 + cron, scripts/privacy_purge.php).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자 (Admin)
 * @var list<string>        $params POST 는 [0] = 요청 번호
 */

require_once __DIR__ . '/../community/Memories.php';

$drDb = Db::instance();

if (Http::method() === 'GET') {
    $drRows = $drDb->run(<<<'SQL'
        SELECT q.id, q.user_id, u.username, u.display_name, q.status, q.requested_at, q.processed_at,
               q.processed_at + interval '30 days' AS purge_after, q.purged_at, pb.username AS processed_by_name
          FROM account_deletion_requests q
          JOIN users u ON u.id = q.user_id
          LEFT JOIN users pb ON pb.id = q.processed_by
         WHERE q.status IN ('pending', 'processed') OR q.purged_at > now() - interval '90 days'
         ORDER BY (q.status = 'pending') DESC, q.requested_at DESC, q.id DESC
         LIMIT 200
        SQL)->fetchAll();

    // 남은 자료 수 — 표가 있는 설치에서만(사진 기록·여행 계획은 커뮤니티 마이그레이션이 만든다)
    $drHasMemories = Memories::ready() && (bool) $drDb->run("SELECT to_regclass('community.travel_plans') IS NOT NULL")->fetchColumn();
    $drCount = static function (string $sql, int $uid) use ($drDb): int {
        return (int) $drDb->run($sql, [':uid' => $uid])->fetchColumn();
    };
    $drMemoriesSql = 'SELECT count(*) FROM community.memory_posts WHERE owner_user_id = :uid AND deleted_at IS NULL';
    $drPlansSql    = 'SELECT count(*) FROM community.travel_plans WHERE owner_user_id = :uid AND deleted_at IS NULL';
    $drOut = [];
    foreach ($drRows as $drRow) {
        $drUid = (int) $drRow['user_id'];
        $drOpen = in_array($drRow['status'], ['pending', 'processed'], true);
        $drItem = [
            'id'                => (int) $drRow['id'],
            'user_id'           => $drUid,
            'username'          => (string) $drRow['username'],
            'display_name'      => $drRow['display_name'] === null ? null : (string) $drRow['display_name'],
            'status'            => (string) $drRow['status'],
            'requested_at'      => (string) $drRow['requested_at'],
            'processed_at'      => $drRow['processed_at'] === null ? null : (string) $drRow['processed_at'],
            'purge_after'       => $drRow['purge_after'] === null ? null : (string) $drRow['purge_after'],
            'purged_at'         => $drRow['purged_at'] === null ? null : (string) $drRow['purged_at'],
            'processed_by_name' => $drRow['processed_by_name'] === null ? null : (string) $drRow['processed_by_name'],
            'remaining'         => null,
        ];
        if ($drOpen) {
            $drItem['remaining'] = [
                'personal_pages' => $drCount(<<<'SQL'
                    SELECT count(*) FROM community.pages p JOIN community.spaces s ON s.id = p.space_id
                     WHERE s.kind = 'personal' AND s.owner_user_id = :uid AND p.deleted_at IS NULL AND p.purged_at IS NULL
                       AND p.visibility <> 'published'
                    SQL, $drUid),
                'memory_posts' => $drHasMemories ? $drCount($drMemoriesSql, $drUid) : 0,
                'travel_plans' => $drHasMemories ? $drCount($drPlansSql, $drUid) : 0,
            ];
        }
        $drOut[] = $drItem;
    }
    Http::json(['requests' => $drOut, 'purge_days' => 30]);
    exit;
}

// ─── POST — 처리 ───────────────────────────────────────────────
$drNo = (int) ($params[0] ?? 0);
$drBody = Http::jsonBody(256);
if ($drNo < 1 || array_keys($drBody) !== ['confirm'] || $drBody['confirm'] !== true) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}

$drDb->begin();
try {
    $drReqSql = 'SELECT id, user_id, status FROM account_deletion_requests WHERE id = :id FOR UPDATE';
    $drReq = $drDb->run($drReqSql, [':id' => $drNo])->fetch();
    if (!is_array($drReq)) {
        $drDb->rollback();
        Audit::deny('account.delete_process', 'not_found', ['target_type' => 'user']);
        Http::error(404, Http::MSG_NOT_FOUND);
    }
    $drUid = (int) $drReq['user_id'];
    $drTargetSql = 'SELECT id, username, role, is_active FROM users WHERE id = :id FOR UPDATE';
    $drTarget = $drDb->run($drTargetSql, [':id' => $drUid])->fetch();
    $drAudit = ['target_type' => 'user', 'target_id' => $drUid, 'target_name' => (string) $drTarget['username']];
    if ($drReq['status'] !== 'pending') {
        $drDb->rollback();
        Audit::deny('account.delete_process', 'not_pending', $drAudit);
        Http::error(409, '이미 처리한 탈퇴 요청입니다.');
    }
    [$drMay, $drCode] = Authz::decideUserChange($user, [
        'id'        => $drUid,
        'role'      => (string) $drTarget['role'],
        'is_active' => $drTarget['is_active'] === true,
    ], ['is_active' => false]);
    if (!$drMay) {
        $drDb->rollback();
        Audit::deny('account.delete_process', $drCode, $drAudit);
        Http::error(400, Http::reasonMessage($drCode));
    }

    // 쓸 수 없는 비밀번호 — 아무도 모르는 난수의 Argon2id 해시(평소와 같은 모양 · 같은 검증 시간). 난수는 변수에만 잠깐 있다
    $drHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_ARGON2ID);
    $drDb->run(<<<'SQL'
        UPDATE users
           SET is_active = false, password_hash = :hash,
               mfa_secret = NULL, mfa_enabled = false, mfa_last_step = NULL,
               must_change_password = false, mfa_setup_required = false, mfa_recovery_required = false,
               temp_password_issued_at = NULL, failed_count = 0, locked_until = NULL,
               updated_at = now()
         WHERE id = :id
        SQL, [':hash' => $drHash, ':id' => $drUid]);
    unset($drHash);

    $drSessions = Auth::revokeAllSessions($drUid);
    $drKeys = Auth::revokeAllPasskeys($drUid, (string) $drTarget['username'], (int) $user['id'], 'deactivate');
    $drTokens = $drDb->run(
        'UPDATE api_tokens SET revoked_at = now(), revoked_by = :by WHERE user_id = :uid AND revoked_at IS NULL',
        [':by' => (int) $user['id'], ':uid' => $drUid]
    )->rowCount();
    // 일회용 값·연결 — 앱 계정이 원래 지울 수 있는 표만(권한을 넓히지 않는다). SQL 은 고정 문장, 값은 바인딩
    $drUidOnly = [':uid' => $drUid];
    $drCleanup = [
        'DELETE FROM session_links WHERE user_id = :uid',
        'DELETE FROM passkey_challenges WHERE user_id = :uid',   // 패스키 표는 schema.sql·2026-10-06 마이그레이션 뒤 늘 있다
    ];
    if ((bool) $drDb->run("SELECT to_regclass('public.mcp_grants') IS NOT NULL")->fetchColumn()) {
        $drCleanup[] = 'DELETE FROM mcp_pending WHERE user_id = :uid';
        $drCleanup[] = 'DELETE FROM mcp_grants WHERE user_id = :uid';   // 코드·토큰은 ON DELETE CASCADE
    }
    foreach ($drCleanup as $drCleanupSql) {
        $drDb->run($drCleanupSql, $drUidOnly);
    }
    if ((bool) $drDb->run("SELECT to_regclass('public.alert_push_subscriptions') IS NOT NULL")->fetchColumn()) {
        $drDb->run(
            "UPDATE alert_push_subscriptions SET revoked_at = now(), revoked_reason = 'not_admin' WHERE user_id = :uid AND revoked_at IS NULL",
            [':uid' => $drUid]
        );
    }
    $drDone = $drDb->run(<<<'SQL'
        UPDATE account_deletion_requests
           SET status = 'processed', processed_by = :by, processed_at = now()
         WHERE id = :id AND status = 'pending'
        RETURNING id, status, requested_at, processed_at, processed_at + interval '30 days' AS purge_after
        SQL, [':by' => (int) $user['id'], ':id' => $drNo])->fetch();

    Audit::allow('account.delete_process', $drAudit + [
        'detail' => [
            'request_no'           => $drNo,
            'sessions_revoked'     => $drSessions,
            'device_keys_revoked'  => $drKeys,
            'program_keys_revoked' => $drTokens,
            'purge_after_days'     => 30,
        ],
    ]);
    $drDb->commit();
} catch (Throwable $drError) {
    if ($drDb->inTransaction()) {
        $drDb->rollback();
    }
    throw $drError;
}

Http::json(['request' => [
    'id'           => (int) $drDone['id'],
    'status'       => (string) $drDone['status'],
    'requested_at' => (string) $drDone['requested_at'],
    'processed_at' => (string) $drDone['processed_at'],
    'purge_after'  => (string) $drDone['purge_after'],
]]);
