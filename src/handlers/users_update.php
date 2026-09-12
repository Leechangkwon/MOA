<?php
declare(strict_types=1);

/**
 * src/handlers/users_update.php — PATCH /api/users/{uid} (정책: user.manage → Admin 만, PRD 5.4)
 *
 * 요구사항: PRD F-10 · 3장(역할은 Admin 만 변경, 변경은 감사 로그에) · 5.4 · S-07("관리자 행동 사유"),
 *           8장 `/api/users` 행, ARCH 7.4 `user.role_change`·`user.deactivate`·`user.activate` 행,
 *           7.5 `before`/`after`·`target_role`·`target_active`·`sessions_revoked`·`reason_text`,
 *           SECURITY R-10(전 기기 로그아웃, T-22) · R-12(Guest 만료) · R-20(사유 필수)
 *
 * 받는 값 (JSON, **허용 키 목록 밖의 키가 하나라도 있으면 400** — R-07 과 같은 원칙)
 *   role         바꿀 역할 (Authz 허용 목록 정확 일치)
 *   team_id      바꿀 팀 (null 이면 팀 없음). Guest 는 팀을 가질 수 없다
 *   is_active    false = 비활성화(모든 세션 즉시 종료) · true = 재활성화
 *   expires_at   만료일. Guest 는 반드시 있어야 한다
 *   reason_text  **필수** 500자 — 사유 없는 관리자 행동은 400 (PRD S-07, SECURITY R-20)
 *
 * 이 핸들러가 반드시 막는 두 가지 (판단은 Authz::decideUserChange 안에서만 한다)
 *   · `self_change` — 자기 자신을 고치는 요청. 자기 역할 올리기·자기 비활성화가 전부 여기서 끝난다.
 *   · `last_admin`  — 마지막 활성 Admin 을 강등·비활성화하는 요청. 관리자 0명이 되면 아무도
 *                     계정을 되돌릴 수 없다.
 *
 * 역할·팀이 바뀌거나 계정을 끄면 **그 사용자의 세션을 전부 삭제**한다 (SECURITY R-10, PRD T-22).
 * 낡은 세션이 살아 있으면 "권한을 거뒀는데 그 기기에서는 그대로" 가 된다.
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자 (Admin)
 * @var list<string>        $params $params[0] = users.id (정수 문자열)
 */

// 19단계 (F-28) — 구매 모듈의 두 축. 둘 다 Admin 만 바꿀 수 있다(라우트 정책 user.manage)
// 그리고 self_change·last_admin 판정은 **기존 decideUserChange 를 그대로 지난다** —
// 모듈 칸 때문에 계정 관리의 금지 두 가지가 헐거워지지 않는다.
$allowedFields = ['role', 'team_id', 'is_active', 'expires_at', 'reason_text',
                  'branch_id', 'purchase_role'];

$uidRaw = $params[0] ?? '';
if (!is_string($uidRaw) || preg_match('/^[0-9]{1,9}$/', $uidRaw) !== 1) {
    Http::error(404, Http::MSG_NOT_FOUND);
}
$uid = (int) $uidRaw;

$body = Http::jsonBody();

$db = Db::instance();

// ─── [0] 대상 계정을 먼저 읽는다 (없으면 404 동일 문구) ──────────
$sqlFind = <<<'SQL'
    SELECT id, username, role, team_id, is_active, expires_at, branch_id, purchase_role
      FROM users
     WHERE id = :id
    SQL;

$target = $db->run($sqlFind, [':id' => $uid])->fetch();
if (!is_array($target)) {
    Http::error(404, Http::MSG_NOT_FOUND);
}

$targetName = (string) $target['username'];
$current = [
    'role'       => (string) $target['role'],
    'team_id'    => $target['team_id'] === null ? null : (int) $target['team_id'],
    'is_active'  => $target['is_active'] === true,
    'expires_at' => $target['expires_at'] === null ? null : (string) $target['expires_at'],
    // 19단계 (F-28) — 현재의 지점·모듈 역할. before 스냅샷에 그대로 들어간다.
    'branch_id'     => $target['branch_id'] === null ? null : (int) $target['branch_id'],
    'purchase_role' => $target['purchase_role'] === null ? null : (string) $target['purchase_role'],
];

/**
 * 거부를 어느 action 으로 남길까 — 요청이 말한 **의도**로 고른다 (ARCH 7.4 의 deny 행).
 *   is_active:false → `user.deactivate`  ·  is_active:true → `user.activate`(검토 보완 A)
 *   그 밖(역할·팀·만료·형식 오류) → `user.role_change`
 * 값이 bool 이 아닌 형식 오류는 "끄기/켜기 의도" 를 알 수 없으므로 role_change 로 남는다.
 */
$denyAction = 'user.role_change';
if (array_key_exists('is_active', $body) && $body['is_active'] === false) {
    $denyAction = 'user.deactivate';
} elseif (array_key_exists('is_active', $body) && $body['is_active'] === true) {
    $denyAction = 'user.activate';
}

/**
 * 권한 거부(`self_change`·`last_admin`)를 남길 action 은 따로 둔다.
 * ARCH 7.4 에 그 두 사유는 `user.role_change`·`user.deactivate` 아래에만 있고
 * `user.activate|deny|self_change`·`…|last_admin` 행은 없다 — 없는 조합을 기록하면
 * 기록 실패 = 500 이 된다. 그래서 "켜기" 의도라도 권한 거부는 role_change 로 남긴다.
 * (실제로 그 조합은 "켜면서 역할도 바꾸는" 요청에서만 생긴다.)
 */
$authzDenyAction = $denyAction === 'user.deactivate' ? 'user.deactivate' : 'user.role_change';

$auditTarget = [
    'target_type' => 'user',
    'target_id'   => $uid,
    'target_name' => $targetName,
];

/** 거부 기록 후 400 으로 끝낸다 */
$reject = static function (string $action, string $reason) use ($auditTarget): void {
    Audit::deny($action, $reason, $auditTarget);
    Http::error(400, Http::MSG_BAD_REQUEST);
};

// ─── [1] 허용 키 검사 ───────────────────────────────────────────
if ($body === [] || array_diff(array_keys($body), $allowedFields) !== []) {
    $reject($denyAction, 'bad_request');
}

// ─── [2] 사유 — 필수 500자 (PRD S-07 "관리자 행동 사유") ────────
$reason = $body['reason_text'] ?? null;
if (!is_string($reason) || preg_match('//u', $reason) !== 1) {
    $reject($denyAction, 'bad_request');
}
$reason = trim((string) $reason);
if ($reason === '' || iconv_strlen($reason, 'UTF-8') > 500) {
    $reject($denyAction, 'bad_request');
}

// ─── [3] 값 하나하나 좁히기 ─────────────────────────────────────
$newRole = $current['role'];
if (array_key_exists('role', $body)) {
    if (!is_string($body['role']) || !Authz::isAssignableRole($body['role'])) {
        $reject($denyAction, 'bad_request');
    }
    $newRole = (string) $body['role'];
}

$newTeam = $current['team_id'];
if (array_key_exists('team_id', $body)) {
    $raw = $body['team_id'];
    if ($raw === null || $raw === '') {
        $newTeam = null;
    } elseif (is_int($raw) || (is_string($raw) && preg_match('/^[0-9]{1,9}$/', $raw) === 1)) {
        $newTeam = (int) $raw;
    } else {
        $reject($denyAction, 'bad_request');
    }
}

$newActive = $current['is_active'];
if (array_key_exists('is_active', $body)) {
    if (!is_bool($body['is_active'])) {
        $reject($denyAction, 'bad_request');
    }
    $newActive = (bool) $body['is_active'];
}

/** `YYYY-MM-DD` 또는 `YYYY-MM-DDTHH:MM` 이고 달력에 실제로 있는 날이어야 한다 */
$validMoment = static function (string $raw): bool {
    if (preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})(?:T([0-9]{2}):([0-9]{2}))?$/', $raw, $m) !== 1) {
        return false;
    }
    if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        return false;
    }
    return !(isset($m[4]) && ((int) $m[4] > 23 || (int) $m[5] > 59));
};

$newExpires     = $current['expires_at'];
$expiresChanged = false;
if (array_key_exists('expires_at', $body)) {
    $raw = $body['expires_at'];
    if ($raw === null || $raw === '') {
        $newExpires = null;
    } elseif (is_string($raw) && $validMoment($raw)) {
        $newExpires = $raw;
    } else {
        $reject($denyAction, 'bad_request');
    }
    $expiresChanged = true;
}

/* ─── 19단계 — 지점·모듈 역할 (PRD F-28, ARCH 5.18) ───
 *
 * 값 검사는 Authz 의 허용 목록(`isAssignablePurchaseRole`)과 **활성 지점 확인**이 한다.
 * 요청이 보낸 문자열을 그대로 컬럼에 넣지 않는다 (role 과 같은 규칙, PRD S-01).
 * 빈 값·null 은 "모듈을 쓰지 않는 계정" 이며 유효한 상태다.
 */
$newPurchaseRole = $current['purchase_role'];
if (array_key_exists('purchase_role', $body)) {
    $raw = $body['purchase_role'];
    if ($raw === null || $raw === '') {
        $newPurchaseRole = null;
    } elseif (is_string($raw) && Authz::isAssignablePurchaseRole($raw)) {
        $newPurchaseRole = $raw;
    } else {
        $reject($denyAction, 'bad_request');
    }
}

$newBranch = $current['branch_id'];
if (array_key_exists('branch_id', $body)) {
    $raw = $body['branch_id'];
    if ($raw === null || $raw === '') {
        $newBranch = null;
    } else {
        $text = is_int($raw) ? (string) $raw : (is_string($raw) ? $raw : '');
        if (preg_match('/^[1-9][0-9]{0,8}$/', $text) !== 1) {
            $reject($denyAction, 'bad_request');
        }
        $newBranch = (int) $text;
    }
}

// 지점이 실제로 있고 사용 중인가 (팀과 같은 규칙: 이미 그 지점인 사람은 다시 검사하지 않는다)
if ($newBranch !== null && $newBranch !== $current['branch_id']) {
    $sqlBranch = <<<'SQL'
        SELECT 1 AS ok FROM p_branches WHERE id = CAST(:id AS bigint) AND is_active
        SQL;
    if (!is_array($db->run($sqlBranch, [':id' => $newBranch])->fetch())) {
        $reject($denyAction, 'bad_request');
    }
}

// 역할이 정하는 규칙 (판단은 Authz 안에서) — Guest 는 팀 없음 · 만료일 필수
if ($newTeam !== null && !Authz::allowsTeam($newRole)) {
    $reject($denyAction, 'bad_request');
}
if ($newExpires === null && Authz::requiresExpiry($newRole)) {
    $reject($denyAction, 'bad_request');
}

// 팀이 실제로 있나
//   12-3 (F-19, ARCH 5.9): **활성 팀만** 있는 것으로 본다 — 비활성 팀으로의 새 배정은
//   기존 거부 경로(bad_request → 400)로 끝난다. 이미 그 팀에 있는 사람을 **다른 필드만**
//   바꾸는 요청은 아래 조건(`!== $current['team_id']`) 덕에 팀을 다시 검사하지 않는다 —
//   팀이 비활성이 된 뒤에도 그 사람의 만료일·역할은 고칠 수 있어야 하기 때문이다.
if ($newTeam !== null && $newTeam !== $current['team_id']) {
    $sqlTeam = <<<'SQL'
        SELECT 1 AS ok FROM teams WHERE id = :id AND is_active
        SQL;
    if (!is_array($db->run($sqlTeam, [':id' => $newTeam])->fetch())) {
        $reject($denyAction, 'bad_request');
    }
}

// ─── [4] 정말 바뀌는 것이 있나 ──────────────────────────────────
$roleChanged = $newRole !== $current['role'];
$teamChanged = $newTeam !== $current['team_id'];
$activeChanged = $newActive !== $current['is_active'];
$branchChanged = $newBranch !== $current['branch_id'];
$purchaseRoleChanged = $newPurchaseRole !== $current['purchase_role'];
// 만료일은 문자열 형식이 DB 출력과 달라 단순 비교가 통하지 않는다 —
// "요청에 expires_at 이 있었나" 로만 변경 여부를 본다.
if (!$roleChanged && !$teamChanged && !$activeChanged && !$expiresChanged
    && !$branchChanged && !$purchaseRoleChanged) {
    $reject($denyAction, 'bad_request');
}

// ─── [5] 권한 판단 — self_change · last_admin (Authz 안에서만) ───
$changes = [];
if ($roleChanged) {
    $changes['role'] = $newRole;
}
if ($activeChanged) {
    $changes['is_active'] = $newActive;
}

[$mayChange, $changeCode] = Authz::decideUserChange($user, [
    'id'        => $uid,
    'role'      => $current['role'],
    'is_active' => $current['is_active'],
], $changes);

if (!$mayChange) {
    Audit::deny($authzDenyAction, $changeCode, $auditTarget);
    Http::error(400, Http::MSG_BAD_REQUEST);
}

// ─── [6] 적용 ───────────────────────────────────────────────────
// 고정 UPDATE 한 문장. 컬럼 이름은 코드에 적혀 있고 값은 전부 바인딩된다 (PRD S-04).
$sqlUpdate = <<<'SQL'
    UPDATE users
       SET role       = :role,
           team_id    = :team_id,
           is_active  = CAST(:is_active AS boolean),
           expires_at = CAST(:expires_at AS timestamptz),
           branch_id     = CAST(:branch_id AS bigint),
           purchase_role = :purchase_role,
           updated_at = now()
     WHERE id = :id
    SQL;

// 어떤 행동으로 기록할까 (ARCH 7.4 행)
//   ① 끄기·켜기가 있으면 그것이 이 요청의 성격이다
//   ② 바뀐 것이 만료일뿐이면 `user.expiry_change` (검토 보완 C — "역할을 바꿨습니다" 라는
//      엉뚱한 문장이 남지 않게)
//   ③ 그 밖(역할·팀, 또는 그것들과 만료일이 함께)이면 `user.role_change`
$action = 'user.role_change';
if ($activeChanged) {
    $action = $newActive ? 'user.activate' : 'user.deactivate';
} elseif ($expiresChanged && !$roleChanged && !$teamChanged) {
    $action = 'user.expiry_change';
}

$db->begin();
try {
    $db->run($sqlUpdate, [
        ':role'       => $newRole,
        ':team_id'    => $newTeam,
        ':is_active'  => $newActive ? 'true' : 'false',
        ':expires_at' => $newExpires,
        ':branch_id'     => $newBranch,
        ':purchase_role' => $newPurchaseRole,
        ':id'         => $uid,
    ]);

    /* 권한이 바뀌었거나 계정을 껐으면 그 사용자의 세션을 전부 끊는다 (SECURITY R-10, T-22).
     *
     * 19단계 판단 — **지점·모듈 역할이 바뀌어도 세션을 끊지 않는다.** 본체 역할·팀과 달리
     * 이 두 값은 세션 조회에 실려 있지 않고, `Authz::purchaseFields` 가 **요청마다 DB 에서
     * 다시 읽는다**. 그래서 값을 바꾼 그 순간부터 낡은 세션에도 새 범위가 적용된다 —
     * "권한을 거뒀는데 그 기기에서는 그대로" 가 생길 자리가 없다. 세션을 끊으면 지점을
     * 옮기는 흔한 인사 이동마다 그 사람이 로그아웃되는 불편만 남는다.
     */
    $revoked = 0;
    if ($roleChanged || $teamChanged || ($activeChanged && !$newActive)) {
        $revoked = Auth::revokeAllSessions($uid);
    }

    Audit::allow($action, $auditTarget + [
        'detail' => [
            'before' => $current,
            'after'  => [
                'role'       => $newRole,
                'team_id'    => $newTeam,
                'is_active'  => $newActive,
                'expires_at' => $newExpires,
                'branch_id'     => $newBranch,
                'purchase_role' => $newPurchaseRole,
            ],
            'target_role'      => $newRole,
            'target_active'    => $newActive,
            'sessions_revoked' => $revoked,
            'reason_text'      => $reason,
        ],
    ]);

    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    throw $e;   // bootstrap 예외 핸들러 → 500
}

Http::json([
    'id'               => $uid,
    'username'         => $targetName,
    'role'             => $newRole,
    'team_id'          => $newTeam,
    'is_active'        => $newActive,
    'expires_at'       => $newExpires,
    'branch_id'        => $newBranch,
    'purchase_role'    => $newPurchaseRole,
    'sessions_revoked' => $revoked,
]);
