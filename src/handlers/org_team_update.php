<?php
declare(strict_types=1);

/**
 * src/handlers/org_team_update.php — PATCH /api/org/teams/{num} (정책: org.manage → Admin 만)
 *
 * 요구사항: PRD F-19 · 5.4 · 8장 `PATCH /api/org/teams/{num}` 행 · 12.2 T-27 · S-07,
 *           ARCH 5.9 표 셋째 행 · 7.4 `team.update|…` 5행 · 7.5 `before`·`after`·`reason_text`,
 *           SECURITY R-20 · R-44
 *
 * 받는 값 (JSON, **허용 키 목록 밖의 키가 하나라도 있으면 400**)
 *   name        새 이름 (선택) — trim 1~50자, 제어문자 없음, 다른 팀과 중복 불가(대소문자 무시)
 *   is_active   활성/비활성 (선택) — **bool 만**. false 는 "팀을 내린다" 는 뜻이다
 *   reason_text 왜 바꾸는가 — **필수**, 500자 (PRD S-07, R-20)
 *
 * 검사 순서는 ARCH 5.9 표 그대로다:
 *   정책(라우터) → 대상 존재(404) → 형식 → 비활성화 요청이면 **활성 구성원 0명 확인** →
 *   중복 → UPDATE(`updated_at = now()`) → `team.update allow` (before/after · 사유)
 *
 * 왜 구성원이 있으면 비활성화를 막나 (PRD F-19, SECURITY R-44)
 *   비활성 팀은 새 배정·새 업로드가 막힌 상태다. 사람이 남아 있는 채로 내리면 그 사람들은
 *   자기 팀 파일을 계속 보면서도 아무도 그 팀으로 옮겨 줄 수 없는 어정쩡한 상태가 된다.
 *   그래서 **먼저 재배정**하게 하고(400 `team_has_members`), 0명이 된 뒤에만 내린다.
 *
 * 이 파일이 하지 않는 것
 *   · 팀 행을 지우지 않는다 — 파일·계정·감사 기록이 팀을 참조한다 (ARCH 5.9).
 *   · 구성원 배정을 여기서 하지 않는다 — 기존 `PATCH /api/users/{uid}` 가 한다.
 *     한 규칙(자기 자신 금지·마지막 Admin 보호·세션 종료 T-22)이 두 곳으로 갈라지지 않게.
 *   · 역할 문자열을 비교하지 않는다 — "Admin 만" 은 라우트 정책 org.manage 가 판단했다.
 *   · `permissions` 표를 건드리지 않는다 (SECURITY R-43, PRD Q-12).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자 (Admin)
 * @var list<string>        $params [0] => teams.id (정수 문자열, 라우터가 형태를 이미 좁혔다)
 */

$teamId = isset($params[0]) && preg_match('/^[0-9]{1,9}$/', (string) $params[0]) === 1
    ? (int) $params[0]
    : 0;

$db = Db::instance();

// ─── [1] 대상 팀이 있나 (없으면 404) ────────────────────────────
$sqlFind = <<<'SQL'
    SELECT id, name, is_active
      FROM teams
     WHERE id = :id
    SQL;

$target = $teamId > 0 ? $db->run($sqlFind, [':id' => $teamId])->fetch() : false;
if (!is_array($target)) {
    // ARCH 7.4 `team.update|deny|not_found` — 문장에 대상 이름이 없다(없는 팀이므로).
    Audit::deny('team.update', 'not_found', ['target_type' => 'team']);
    Http::error(404, Http::MSG_NOT_FOUND);
}

$currentName   = (string) $target['name'];
$currentActive = $target['is_active'] === true;

$auditTarget = [
    'target_type' => 'team',
    'target_id'   => $teamId,
    'target_name' => $currentName,
];

/** 거부 기록 후 끝낸다. 상태 코드는 부르는 쪽이 정한다(형식 400 · 중복 409). */
$reject = static function (string $reason, int $status, string $message) use ($auditTarget): void {
    Audit::deny('team.update', $reason, $auditTarget);
    Http::error($status, $message);
};

// ─── [2] 허용 키 · 사유 · 값 형식 ───────────────────────────────
$allowedFields = ['name', 'is_active', 'reason_text'];
$body = Http::jsonBody();

if ($body === [] || array_diff(array_keys($body), $allowedFields) !== []) {
    $reject('bad_request', 400, Http::MSG_BAD_REQUEST);
}

$reason = $body['reason_text'] ?? null;
if (!is_string($reason) || preg_match('//u', $reason) !== 1) {
    $reject('bad_request', 400, Http::MSG_BAD_REQUEST);
}
$reason = trim((string) $reason);
if ($reason === '' || iconv_strlen($reason, 'UTF-8') > 500) {
    $reject('bad_request', 400, Http::MSG_BAD_REQUEST);
}

$newName = $currentName;
if (array_key_exists('name', $body)) {
    $raw = $body['name'];
    if (!is_string($raw) || preg_match('//u', $raw) !== 1) {
        $reject('bad_request', 400, Http::MSG_BAD_REQUEST);
    }
    $raw = trim((string) $raw);
    if ($raw === '' || iconv_strlen($raw, 'UTF-8') > 50 || preg_match('/\p{C}/u', $raw) === 1) {
        $reject('bad_request', 400, Http::MSG_BAD_REQUEST);
    }
    $newName = $raw;
}

$newActive = $currentActive;
if (array_key_exists('is_active', $body)) {
    if (!is_bool($body['is_active'])) {
        $reject('bad_request', 400, Http::MSG_BAD_REQUEST);
    }
    $newActive = (bool) $body['is_active'];
}

// ─── [3] 바뀌는 것이 하나도 없으면 거부 ─────────────────────────
//        "사유만 적은 요청" 이 감사 로그에 변경으로 남지 않게 한다 (files_update 의 no_change 와 같은 결).
$nameChanged   = $newName !== $currentName;
$activeChanged = $newActive !== $currentActive;
if (!$nameChanged && !$activeChanged) {
    $reject('bad_request', 400, Http::MSG_BAD_REQUEST);
}

// ─── [4] 비활성화 요청이면 활성 구성원이 0명이어야 한다 (R-44) ───
if ($activeChanged && $newActive === false) {
    $sqlMembers = <<<'SQL'
        SELECT count(*) AS n FROM users WHERE team_id = :id AND is_active
        SQL;
    $countRow = $db->run($sqlMembers, [':id' => $teamId])->fetch();
    if (!is_array($countRow) || (int) $countRow['n'] !== 0) {
        $reject('team_has_members', 400, Http::MSG_BAD_REQUEST);
    }
}

// ─── [5] 이름 중복 (자기 자신 제외, 대소문자 무시) ──────────────
if ($nameChanged) {
    $sqlExists = <<<'SQL'
        SELECT id FROM teams WHERE lower(name) = lower(:name) AND id <> :id
        SQL;
    if (is_array($db->run($sqlExists, [':name' => $newName, ':id' => $teamId])->fetch())) {
        $reject('already_exists', 409, Http::MSG_CONFLICT);
    }
}

/*
 * ─── [6] 저장 ─────────────────────────────────────────────────
 * 바뀌는 칸에 따라 **미리 적어 둔 문장 세 개** 중 하나를 고른다. SQL 을 문자열로 이어 붙이지
 * 않는다 — 조각을 붙이기 시작하면 언젠가 값이 섞여 들어간다 (PRD S-04, 금지 패턴).
 * `updated_at` 은 언제나 now() 로 갱신한다(화면의 「마지막 변경」).
 */
$sqlSetName = <<<'SQL'
    UPDATE teams SET name = :name, updated_at = now()
     WHERE id = :id
    RETURNING id, name, is_active, created_at, updated_at
    SQL;
$sqlSetActive = <<<'SQL'
    UPDATE teams SET is_active = CAST(:active AS boolean), updated_at = now()
     WHERE id = :id
    RETURNING id, name, is_active, created_at, updated_at
    SQL;
$sqlSetBoth = <<<'SQL'
    UPDATE teams SET name = :name, is_active = CAST(:active AS boolean), updated_at = now()
     WHERE id = :id
    RETURNING id, name, is_active, created_at, updated_at
    SQL;

/*
 * 참·거짓은 `'true'`·`'false'` 문자열로 넘기고 SQL 쪽에서 CAST 한다 (users_update 와 같은 방식).
 * PHP 의 false 를 그대로 바인딩하면 드라이버가 빈 문자열로 보내 PostgreSQL 이 거절한다
 * (`invalid input syntax for type boolean: ""` — 2026-09-12 12-3 시험에서 실측).
 */
$activeText = $newActive ? 'true' : 'false';

if ($nameChanged && $activeChanged) {
    $sql  = $sqlSetBoth;
    $args = [':name' => $newName, ':active' => $activeText, ':id' => $teamId];
} elseif ($nameChanged) {
    $sql  = $sqlSetName;
    $args = [':name' => $newName, ':id' => $teamId];
} else {
    $sql  = $sqlSetActive;
    $args = [':active' => $activeText, ':id' => $teamId];
}

/*
 * 이전값 → 새값 은 **바뀐 칸만** 담는다 (ARCH 5.9 "detail.before/after = 바뀐 필드만").
 * 안 바뀐 칸까지 넣으면 로그를 읽는 사람이 무엇이 실제로 바뀌었는지 다시 대조해야 한다.
 */
$before = [];
$after  = [];
if ($nameChanged) {
    $before['name'] = $currentName;
    $after['name']  = $newName;
}
if ($activeChanged) {
    $before['is_active'] = $currentActive;
    $after['is_active']  = $newActive;
}

$db->begin();
try {
    $row = $db->run($sql, $args)->fetch();
    if (!is_array($row)) {
        throw new RuntimeException('팀을 바꾸지 못했습니다');
    }

    Audit::allow('team.update', [
        'target_type' => 'team',
        'target_id'   => $teamId,
        // 바뀐 뒤의 이름으로 남긴다 — 이전 이름은 detail.before 에 그대로 있다.
        'target_name' => (string) $row['name'],
        'detail'      => [
            'before'      => $before,
            'after'       => $after,
            'reason_text' => $reason,
        ],
    ]);
    $db->commit();
} catch (PDOException $e) {
    $db->rollback();
    // 23505 = unique_violation. 위 [5] 와 저장 사이에 다른 요청이 같은 이름을 먼저 쓴 경우다.
    if ($e->getCode() === '23505') {
        $reject('already_exists', 409, Http::MSG_CONFLICT);
    }
    throw $e;
} catch (Throwable $e) {
    $db->rollback();
    throw $e;
}

Http::json([
    'team' => [
        'id'         => (int) $row['id'],
        'name'       => (string) $row['name'],
        'is_active'  => $row['is_active'] === true,
        'created_at' => (string) $row['created_at'],
        'updated_at' => (string) $row['updated_at'],
    ],
]);
