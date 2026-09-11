<?php
declare(strict_types=1);

/**
 * src/handlers/users_create.php — POST /api/users (정책: user.manage → Admin 만, PRD 5.4)
 *
 * 요구사항: PRD F-10 · 3장(1인 1계정, 역할은 Admin 만 변경) · 5.4 · S-07("관리자 행동 사유") ·
 *           S-09-4(Argon2id, 최소 10자), 8장 `/api/users` 행,
 *           ARCH 7.4 `user.create|allow`·`deny|bad_request`·`deny|already_exists`, 7.5 `reason_text`,
 *           SECURITY R-12(Guest 만료) · R-20(관리자 행동에 사유)
 *
 * 받는 값 (JSON, **허용 키 목록 밖의 키가 하나라도 있으면 400**)
 *   username    아이디 — Auth::USERNAME_RE 와 **같은 규칙**(소문자·숫자·`_.-`, 3~64자)
 *   role        Admin | Manager | Employee | Guest — **Authz 의 허용 목록에 정확히 있는 값만**.
 *               요청이 보낸 문자열을 그대로 컬럼에 넣지 않는다 (금지 패턴)
 *   team_id     팀 번호 (없으면 null). Guest 는 팀을 가질 수 없다 (PRD 3장)
 *   expires_at  만료일 `YYYY-MM-DD` 또는 `YYYY-MM-DDTHH:MM`. **Guest 는 필수** (SECURITY R-12).
 *               DB CHECK(guest_requires_expires_at)가 마지막 그물이다
 *   reason_text 왜 이 계정을 만드는가 — **필수**, 500자 (PRD S-07 "관리자 행동 사유")
 *
 * 11단계 (PRD F-16 ①, ARCH 4.0) — **비밀번호를 받지 않는다.**
 *   Admin 이 비밀번호를 정하면 그 값을 Admin 이 계속 알고 있는 상태가 이어진다(SECURITY R-39).
 *   그래서 서버가 임시 비밀번호를 만들고(Auth::generateTempPassword), 201 응답에 `temp_password`
 *   로 **딱 한 번** 돌려주며, 만든 계정에는 `must_change_password`·`mfa_setup_required` 를 켠다 —
 *   대상은 첫 로그인에서 비밀번호를 바꾸고 2단계 인증을 등록해야 다른 기능을 쓸 수 있다.
 *   `password` 키가 오면 허용 키 목록 밖이므로 400 이다(옛 방식으로 되돌아갈 통로를 남기지 않는다).
 *
 * 절대 하지 않는 것
 *   · 비밀번호를 응답(생성 201 의 `temp_password` 제외)·감사 로그·app.log 에 남기지 않는다.
 *     `detail` 에는 값이 아니라 **사실**만 남는다 — `temp_password_issued: true` (ARCH 7.5)
 *     (Audit::scrub 이 다시 한 번 막지만, 애초에 넣지 않는 것이 규칙이다).
 *   · 요청의 역할 문자열을 검사 없이 쓰지 않는다.
 *   · 역할 비교를 이 파일에서 하지 않는다 — 전부 Authz 함수를 부른다.
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자 (Admin)
 * @var list<string>        $params 경로 변수 없음
 */

/** 허용 키 — 여기 없는 키가 오면 요청 전체를 거부한다 (SECURITY R-07 과 같은 원칙) */
$allowedFields = ['username', 'role', 'team_id', 'expires_at', 'reason_text'];

$body = Http::jsonBody();

/** 거부 기록 후 400 으로 끝낸다. 대상 이름은 아이디가 문자열일 때만 남긴다. */
$rejectBadRequest = static function (array $body): void {
    $name = isset($body['username']) && is_string($body['username'])
        ? substr($body['username'], 0, 64)
        : null;
    Audit::deny('user.create', 'bad_request', [
        'target_type' => 'user',
        'target_name' => $name,
    ]);
    Http::error(400, Http::MSG_BAD_REQUEST);
};

// ─── [1] 허용 키 검사 ───────────────────────────────────────────
if ($body === [] || array_diff(array_keys($body), $allowedFields) !== []) {
    $rejectBadRequest($body);
}

// ─── [2] 아이디 ─────────────────────────────────────────────────
$username = $body['username'] ?? null;
if (!is_string($username) || preg_match(Auth::USERNAME_RE, $username) !== 1) {
    $rejectBadRequest($body);
}
$username = (string) $username;

// ─── [3] 비밀번호는 받지 않는다 (11단계 F-16 ①) ─────────────────
//        임시 비밀번호는 아래 [10] 에서 서버가 만든다. `password` 키가 오면
//        위 [1] 허용 키 검사에서 이미 400 으로 끝났다.

// ─── [4] 역할 — 허용 목록 정확 일치 (Authz 안에서만 판단) ────────
$role = $body['role'] ?? null;
if (!is_string($role) || !Authz::isAssignableRole($role)) {
    $rejectBadRequest($body);
}
$role = (string) $role;

// ─── [5] 팀 — 정수 형태만, 실제로 있는 팀만. Guest 는 팀을 가질 수 없다 ───
$teamId = null;
if (array_key_exists('team_id', $body) && $body['team_id'] !== null && $body['team_id'] !== '') {
    $raw = $body['team_id'];
    if (!is_int($raw) && !(is_string($raw) && preg_match('/^[0-9]{1,9}$/', $raw) === 1)) {
        $rejectBadRequest($body);
    }
    $teamId = (int) $raw;
}
if ($teamId !== null && !Authz::allowsTeam($role)) {
    $rejectBadRequest($body);
}

// ─── [6] 만료일 — Guest 는 필수 (SECURITY R-12) ──────────────────
/** `YYYY-MM-DD` 또는 `YYYY-MM-DDTHH:MM` 이고 **달력에 실제로 있는 날**이어야 한다. */
$validMoment = static function (string $raw): bool {
    if (preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})(?:T([0-9]{2}):([0-9]{2}))?$/', $raw, $m) !== 1) {
        return false;
    }
    if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        return false;
    }
    return !(isset($m[4]) && ((int) $m[4] > 23 || (int) $m[5] > 59));
};

$expiresAt = null;
if (array_key_exists('expires_at', $body) && $body['expires_at'] !== null && $body['expires_at'] !== '') {
    if (!is_string($body['expires_at']) || !$validMoment($body['expires_at'])) {
        $rejectBadRequest($body);
    }
    $expiresAt = (string) $body['expires_at'];
}
if ($expiresAt === null && Authz::requiresExpiry($role)) {
    $rejectBadRequest($body);   // Guest 인데 만료일이 없다
}

// ─── [7] 사유 — 필수 500자 (PRD S-07 "관리자 행동 사유", R-20) ───
$reason = $body['reason_text'] ?? null;
if (!is_string($reason) || preg_match('//u', $reason) !== 1) {
    $rejectBadRequest($body);
}
$reason = trim((string) $reason);
if ($reason === '' || iconv_strlen($reason, 'UTF-8') > 500) {
    $rejectBadRequest($body);
}

$db = Db::instance();

// ─── [8] 팀이 실제로 있나 ──────────────────────────────────────
if ($teamId !== null) {
    $sqlTeam = <<<'SQL'
        SELECT 1 AS ok FROM teams WHERE id = :id
        SQL;
    if (!is_array($db->run($sqlTeam, [':id' => $teamId])->fetch())) {
        $rejectBadRequest($body);
    }
}

// ─── [9] 같은 아이디가 이미 있나 (PRD 3장 "1인 1계정") ───────────
$sqlExists = <<<'SQL'
    SELECT 1 AS ok FROM users WHERE username = :username
    SQL;
if (is_array($db->run($sqlExists, [':username' => $username])->fetch())) {
    Audit::deny('user.create', 'already_exists', [
        'target_type' => 'user',
        'target_name' => $username,
    ]);
    Http::error(400, Http::MSG_BAD_REQUEST);
}

// ─── [10] 저장 — 임시 비밀번호를 만들고 해시만 넣는다 (F-16 ①, PRD S-09-4, T-16) ───
//         두 플래그를 함께 켠다: 첫 로그인에 비밀번호 변경 → 2단계 인증 등록.
$temp = Auth::generateTempPassword();
$hash = password_hash($temp, PASSWORD_ARGON2ID);
if (!is_string($hash)) {
    throw new RuntimeException('비밀번호 해시를 만들 수 없습니다');
}

$sqlInsert = <<<'SQL'
    INSERT INTO users (username, password_hash, role, team_id, expires_at,
                       must_change_password, mfa_setup_required)
    VALUES (:username, :hash, :role, :team_id, CAST(:expires AS timestamptz),
            true, true)
    RETURNING id
    SQL;

$db->begin();
try {
    $row = $db->run($sqlInsert, [
        ':username' => $username,
        ':hash'     => $hash,
        ':role'     => $role,
        ':team_id'  => $teamId,
        ':expires'  => $expiresAt,
    ])->fetch();
    if (!is_array($row)) {
        throw new RuntimeException('계정을 만들지 못했습니다');
    }
    $newId = (int) $row['id'];

    Audit::allow('user.create', [
        'target_type' => 'user',
        'target_id'   => $newId,
        'target_name' => $username,
        'detail'      => [
            // ARCH 7.5 등록부: target_role · target_active · reason_text
            'target_role'   => $role,
            'target_active' => true,
            'target_team'   => $teamId,
            'expires_at'    => $expiresAt,
            'reason_text'   => $reason,
            // 값이 아니라 **사실**만 (ARCH 7.5 temp_password_issued, SECURITY R-39)
            'temp_password_issued' => true,
        ],
    ]);
    $db->commit();
} catch (PDOException $e) {
    $db->rollback();
    // 23505 = unique_violation. 위 [9] 와 조회 사이에 다른 요청이 먼저 만든 경우다.
    if ($e->getCode() === '23505') {
        Audit::deny('user.create', 'already_exists', [
            'target_type' => 'user',
            'target_name' => $username,
        ]);
        Http::error(400, Http::MSG_BAD_REQUEST);
    }
    throw $e;
} catch (Throwable $e) {
    $db->rollback();
    throw $e;
}

/*
 * 응답에 해시는 없다. 임시 비밀번호 평문은 **이 응답 한 번**만 나가고 어디에도 저장되지 않는다
 * (ARCH 4.0, SECURITY R-39). 화면은 이 값을 한 번 보여 주고 "다시 볼 수 없습니다" 라고 알린다.
 * 응답은 Cache-Control: no-store 로 나간다 (Http::json).
 */
Http::json([
    'id'            => $newId,
    'username'      => $username,
    'role'          => $role,
    'team_id'       => $teamId,
    'expires_at'    => $expiresAt,
    'is_active'     => true,
    'temp_password' => $temp,
], 201);
unset($temp, $hash, $body);   // 평문을 이 뒤 코드로 흘려보내지 않는다
