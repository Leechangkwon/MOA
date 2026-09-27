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
 *   display_name 사람이 알아보는 **이름** (5-A″). `null`·빈 글자면 이름을 지운다. 규칙은
 *                `tbox_clean_display_name()` 한 벌(앞뒤 공백 제거 뒤 1~40자, 제어·서식 문자 거부 → 400)
 *   username     **로그인 아이디** (d74, 2026-09-27 — SECURITY R-06 완화). 규칙은 계정 만들기와 **같은 함수**
 *                (`_username_common.php` — 형식 400 bad_request · 이미 있음 400 already_exists · 검토 접두 400 reserved_prefix).
 *                `reason_text`(와 `display_name`) 하고만 함께 보낸다 — 역할·팀·활성·만료와 섞으면 400(감사 행이 늘 `user.update` 가 되게).
 *                지금과 같은 이름이면 「바뀐 것 없음」이다. 막는 것 둘이 더 있다: 지금 이름이 검토 계정(`qa_`·`claude_`)이면
 *                400 review_account · 지금 이름이 공용 프로그램 토큰(`.env` API_TOKEN_USER — 글자로 계정을 찾는다)의 귀속 아이디면
 *                409 shared_token_user(바꾸는 순간 그 토큰이 끊긴다 — 귀속 줄을 먼저 새 아이디로: 결정 d76 ②).
 *
 * 5-A″ 이름 — 감사 처리 (2026-09-23 지시서 「감사 user.update 에 바뀐 칸 이름만」)
 *   · 이름**만** 바꾸면 action 은 새 줄 `user.update`(「이름을 바꿨습니다」)다. 다른 칸과 함께 바뀌면
 *     그 칸의 action(role_change·activate·deactivate·expiry_change)이 그대로이고, 어느 쪽이든
 *     detail 의 `changed_fields` 에 **바뀐 칸 이름**이 모두 적힌다(`display_name` 포함).
 *   ★ **이름의 값은 감사에 남기지 않는다** — `before`/`after` 에도 없다. 판단 근거:
 *     ① 기존 규칙(ARCH 7.5)은 before/after 를 **권한에 닿는 칸**(역할·팀·활성·만료)에만 둔다 —
 *        그 값이 있어야 「누가 누구의 권한을 어떻게 바꿨나」를 재구성할 수 있기 때문이다.
 *        이름은 권한에 닿지 않는다(판정은 id·역할·팀만 본다).
 *     ② 감사 표는 append-only 다(PRD S-07). 사람의 이름은 개인정보라, 한 번 적히면 본인이
 *        고쳐 달라고 해도 지울 수 없다. 지금 값은 계정 표에 있고, 「언제 누가 바꿨나」는 이 줄이 남긴다.
 *   · 자기 자신의 이름도 이 창구로는 못 바꾼다(`self_change` — 역할과 같은 규칙). 본인은 「내 계정」에서
 *     **보기만** 한다(지시서). Employee·Manager 는 정책 `user.manage` 가 403 으로 먼저 막는다.
 *
 * 이 핸들러가 반드시 막는 두 가지 (판단은 Authz::decideUserChange 안에서만 한다)
 *   · `self_change` — 자기 자신을 고치는 요청. 자기 역할 올리기·자기 비활성화가 전부 여기서 끝난다.
 *   · `last_admin`  — 마지막 활성 Admin 을 강등·비활성화하는 요청. 관리자 0명이 되면 아무도
 *                     계정을 되돌릴 수 없다.
 *
 * 역할·팀이 바뀌거나 계정을 끄면 **그 사용자의 세션을 전부 삭제**한다 (SECURITY R-10, PRD T-22).
 * 낡은 세션이 살아 있으면 "권한을 거뒀는데 그 기기에서는 그대로" 가 된다.
 *
 * d74 아이디 — 감사 처리 (이름 칸 5-A″ 관례 그대로 · 구현 기록 docs/d74-username-change-implementation.md)
 *   · action 은 `user.update`, `changed_fields` 에 `username`. **아이디의 값은 새것도 옛것도 detail 에 넣지 않는다** —
 *     이 행의 `target_name` 이 바꾸기 **전** 아이디이고(행 target 규칙 그대로), 새 아이디는 감사 어디에도 없다
 *     (d74 의 목적이 새 아이디를 모르게 하는 것이다 — 누구든 계정 번호 `target_id` 로 지금 행을 찾는다).
 *   · 세션·개인 토큰·2단계 비밀·잠금 칸은 전부 계정 **번호**에 묶여 있어 그대로다 — 세션을 끊지 않는다
 *     (권한이 바뀐 것이 아니다. 이름 칸과 같다).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자 (Admin)
 * @var list<string>        $params $params[0] = users.id (정수 문자열)
 */

$allowedFields = ['role', 'team_id', 'is_active', 'expires_at', 'reason_text', 'display_name', 'username'];

require_once __DIR__ . '/_username_common.php';   // d74 — 아이디 규칙 한 벌(계정 만들기와 같은 함수)

$uidRaw = $params[0] ?? '';
if (!is_string($uidRaw) || preg_match('/^[0-9]{1,9}$/', $uidRaw) !== 1) {
    Http::error(404, Http::MSG_NOT_FOUND);
}
$uid = (int) $uidRaw;

$body = Http::jsonBody();

$db = Db::instance();

// ─── [0] 대상 계정을 먼저 읽는다 (없으면 404 동일 문구) ──────────
$sqlFind = <<<'SQL'
    SELECT id, username, role, team_id, is_active, expires_at, display_name
      FROM users
     WHERE id = :id
    SQL;

$target = $db->run($sqlFind, [':id' => $uid])->fetch();
if (!is_array($target)) {
    Http::error(404, Http::MSG_NOT_FOUND);
}

$targetName = (string) $target['username'];
// 5-A″ — 이름은 `$current` 에 넣지 않는다: `$current` 는 감사 `before` 로 그대로 나가고, 이름의 값은 감사에 남기지 않는다(머리글 ★).
$currentDisplayName = $target['display_name'] === null ? null : (string) $target['display_name'];
$current = [
    'role'       => (string) $target['role'],
    'team_id'    => $target['team_id'] === null ? null : (int) $target['team_id'],
    'is_active'  => $target['is_active'] === true,
    'expires_at' => $target['expires_at'] === null ? null : (string) $target['expires_at'],
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
} elseif ((array_key_exists('display_name', $body) || array_key_exists('username', $body))
    && array_intersect(array_keys($body), ['role', 'team_id', 'is_active', 'expires_at']) === []
) {
    // 5-A″ — 이름만 바꾸려는 요청. 「역할 변경을 …」 문장이 남지 않게 따로 둔다. d74 — 아이디도 같은 갈래.
    $denyAction = 'user.update';
}

/**
 * 권한 거부(`self_change`·`last_admin`)를 남길 action 은 따로 둔다.
 * ARCH 7.4 에 그 두 사유는 `user.role_change`·`user.deactivate` 아래에만 있고
 * `user.activate|deny|self_change`·`…|last_admin` 행은 없다 — 없는 조합을 기록하면
 * 기록 실패 = 500 이 된다. 그래서 "켜기" 의도라도 권한 거부는 role_change 로 남긴다.
 * (실제로 그 조합은 "켜면서 역할도 바꾸는" 요청에서만 생긴다.)
 */
$authzDenyAction = $denyAction === 'user.deactivate' ? 'user.deactivate' : 'user.role_change';
if ($denyAction === 'user.update') {
    // 이름만 바꾸는 요청의 권한 거부는 self_change 하나뿐이다(last_admin 은 역할·활성에서만 생긴다).
    $authzDenyAction = 'user.update';
}

$auditTarget = [
    'target_type' => 'user',
    'target_id'   => $uid,
    'target_name' => $targetName,
];

/** 거부 기록 후 400 으로 끝낸다 */
$reject = static function (string $action, string $reason) use ($auditTarget): void {
    Audit::deny($action, $reason, $auditTarget);
    Http::error(400, Http::reasonMessage($reason));
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

// 5-A″ 이름 — 규칙은 bootstrap 의 한 함수(`tbox_clean_display_name`). 없으면 그대로 둔다.
$newDisplayName = $currentDisplayName;
if (array_key_exists('display_name', $body)) {
    [$nameOk, $cleanName] = tbox_clean_display_name($body['display_name']);
    if (!$nameOk) {
        $reject($denyAction, 'bad_request');
    }
    $newDisplayName = $cleanName;
}

// d74 아이디 — 형식은 계정 만들기와 같은 함수. 권한 칸(역할·팀·활성·만료)과는 섞지 않는다(감사 행이 늘 user.update).
//   이미 있음·검토 접두·검토 계정·공용 토큰은 DB·설정을 보므로 권한 판단([5]) 뒤 [5b] 에서 본다.
$newUsername = $targetName;
if (array_key_exists('username', $body)) {
    if (array_intersect(array_keys($body), ['role', 'team_id', 'is_active', 'expires_at']) !== []) {
        $reject($denyAction, 'bad_request');
    }
    if (!tbox_username_format_ok($body['username'])) {
        // 사유 코드는 형식 오류 그대로(bad_request) · 문구만 아이디 규칙을 말한다(화면이 고쳐 다시 보낼 수 있게 — 요청 값은 넣지 않는다)
        Audit::deny('user.update', 'bad_request', $auditTarget);
        Http::error(400, tbox_username_message('bad_format'));
    }
    $newUsername = (string) $body['username'];
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
$nameChanged   = $newDisplayName !== $currentDisplayName;
$usernameChanged = $newUsername !== $targetName;   // d74 — 같은 이름이면 바뀐 것 없음
// 만료일은 문자열 형식이 DB 출력과 달라 단순 비교가 통하지 않는다 —
// "요청에 expires_at 이 있었나" 로만 변경 여부를 본다.
if (!$roleChanged && !$teamChanged && !$activeChanged && !$expiresChanged && !$nameChanged && !$usernameChanged) {
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
    Http::error(400, Http::reasonMessage($changeCode));
}

// ─── [5b] d74 아이디 — 바꿀 수 있는 이름인가 (권한 판단 **뒤** — 권한 없는 사람에게 이름 사정을 먼저 알려 주지 않는다) ───
//   감사 행은 늘 `user.update`, 대상은 바꾸려는 계정(지금 이름)이다. **시도한 새 이름은 감사에 남기지 않는다** — 새 아이디
//   후보(오타 포함)가 기록에 쌓이면 d74 의 목적(새 아이디를 모르게)이 샌다. 문구는 코드 상수(_username_common.php).
if ($usernameChanged) {
    if (tbox_username_locked_review($targetName)) {
        Audit::deny('user.update', 'review_account', $auditTarget);
        Http::error(400, tbox_username_message('review_account'));
    }
    if (tbox_username_touches_shared_token($targetName)) {   // 지금 이름만(결정 d76 ② — _username_common.php ⑤)
        Audit::deny('user.update', 'shared_token_user', $auditTarget);
        Http::error(409, tbox_username_message('shared_token_user'));
    }
    $usernameWhy = tbox_username_unavailable($db, $newUsername);
    if ($usernameWhy === 'already_exists') {
        Audit::deny('user.update', 'already_exists', $auditTarget);
        Http::error(400, tbox_username_message('already_exists'));
    }
    if ($usernameWhy === 'reserved_prefix') {
        Audit::deny('user.update', 'reserved_prefix', $auditTarget);
        Http::error(400, tbox_username_message('reserved_prefix'));
    }
}

// ─── [6] 적용 ───────────────────────────────────────────────────
// 고정 UPDATE 한 문장. 컬럼 이름은 코드에 적혀 있고 값은 전부 바인딩된다 (PRD S-04).
$sqlUpdate = <<<'SQL'
    UPDATE users
       SET role       = :role,
           team_id    = :team_id,
           is_active  = CAST(:is_active AS boolean),
           expires_at = CAST(:expires_at AS timestamptz),
           display_name = :display_name,
           username   = :username,
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
} elseif (($nameChanged || $usernameChanged) && !$expiresChanged && !$roleChanged && !$teamChanged) {
    // 5-A″ — 바뀐 것이 이름뿐이다 (머리글 「감사 처리」). d74 — 아이디도 같은 행(권한 칸과 섞이지 않는다 — [3])
    $action = 'user.update';
} elseif ($expiresChanged && !$roleChanged && !$teamChanged) {
    $action = 'user.expiry_change';
}

/* 5-A″ — 바뀐 **칸 이름**(값이 아니다). 이름의 값은 감사 어디에도 없다(머리글 ★). */
$changedFields = [];
foreach ([
    'role'         => $roleChanged,
    'team_id'      => $teamChanged,
    'is_active'    => $activeChanged,
    'expires_at'   => $expiresChanged,
    'display_name' => $nameChanged,
    'username'     => $usernameChanged,   // d74 — 칸 이름만(값은 새것도 옛것도 남기지 않는다 — 머리글)
] as $field => $did) {
    if ($did) {
        $changedFields[] = $field;
    }
}

$db->begin();
try {
    $db->run($sqlUpdate, [
        ':role'       => $newRole,
        ':team_id'    => $newTeam,
        ':is_active'  => $newActive ? 'true' : 'false',
        ':expires_at' => $newExpires,
        ':display_name' => $newDisplayName,
        ':username'   => $newUsername,
        ':id'         => $uid,
    ]);

    /* 권한이 바뀌었거나 계정을 껐으면 그 사용자의 세션을 전부 끊는다 (SECURITY R-10, T-22). */
    $revoked = 0;
    if ($roleChanged || $teamChanged || ($activeChanged && !$newActive)) {
        $revoked = Auth::revokeAllSessions($uid);
        /* T:F-72 (지시서 1.6 「자동 해제」) — 역할 변경·비활성화면 그 사람의 지문·얼굴 로그인 열쇠도 전부 해제한다(같은 트랜잭션 ·
           열쇠마다 감사 한 행). 역할: 직원일 때 비밀번호만으로 등록한 열쇠가 관리자 열쇠로 넘어가지 않게. 팀 변경은 해제하지 않는다. */
        if ($activeChanged && !$newActive) {
            Auth::revokeAllPasskeys($uid, $targetName, (int) $user['id'], 'deactivate');
        } elseif ($roleChanged) {
            Auth::revokeAllPasskeys($uid, $targetName, (int) $user['id'], 'role_change');
        }
    }

    Audit::allow($action, $auditTarget + [
        'detail' => [
            'before' => $current,
            'after'  => [
                'role'       => $newRole,
                'team_id'    => $newTeam,
                'is_active'  => $newActive,
                'expires_at' => $newExpires,
            ],
            'target_role'      => $newRole,
            'target_active'    => $newActive,
            'sessions_revoked' => $revoked,
            'reason_text'      => $reason,
            'changed_fields'   => $changedFields,
        ],
    ]);

    $db->commit();
} catch (PDOException $e) {
    $db->rollback();
    // d74 — 23505 = unique_violation. [5b] 와 UPDATE 사이에 다른 요청이 같은 아이디를 먼저 가져간 경우다(계정 만들기와 같은 그물).
    if ($usernameChanged && $e->getCode() === '23505') {
        Audit::deny('user.update', 'already_exists', $auditTarget);
        Http::error(400, tbox_username_message('already_exists'));
    }
    throw $e;   // bootstrap 예외 핸들러 → 500
} catch (Throwable $e) {
    $db->rollback();
    throw $e;   // bootstrap 예외 핸들러 → 500
}

Http::json([
    'id'               => $uid,
    'username'         => $newUsername,   // d74 — 바꾼 뒤 이름(요청한 관리자에게만 — 이 창구는 Admin + 재인증)
    'display_name'     => $newDisplayName,
    'role'             => $newRole,
    'team_id'          => $newTeam,
    'is_active'        => $newActive,
    'expires_at'       => $newExpires,
    'sessions_revoked' => $revoked,
]);
