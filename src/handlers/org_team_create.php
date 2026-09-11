<?php
declare(strict_types=1);

/**
 * src/handlers/org_team_create.php — POST /api/org/teams (정책: org.manage → Admin 만)
 *
 * 요구사항: PRD F-19 · 5.4 · 8장 `POST /api/org/teams` 행 · 12.2 T-27 · S-07("관리자 행동 사유"),
 *           ARCH 5.9 표 둘째 행 · 7.4 `team.create|…` 3행 · 7.5 `reason_text`,
 *           SECURITY R-20(관리자 행동에 사유) · R-44
 *
 * 받는 값 (JSON, **허용 키 목록 밖의 키가 하나라도 있으면 400**)
 *   name        팀 이름 — 앞뒤 공백을 떼고 1~50자, 제어문자 없음. DB CHECK(teams_name_len)가
 *               마지막 그물이다 (db/migrations/2026-09-12_teams_active.sql)
 *   reason_text 왜 이 팀을 만드는가 — **필수**, 500자 (PRD S-07, R-20)
 *
 * 검사 순서는 ARCH 5.9 표 그대로다: 정책(라우터) → 형식 → 중복(대소문자 무시) → INSERT → 기록.
 *
 * 왜 대소문자를 무시해 중복을 보나
 *   `개발팀` 과 `개발팀 `(공백)·`Dev` 와 `dev` 가 나란히 있으면 배정 화면에서 사람이 틀린 팀을
 *   고르게 된다. 팀은 권한 경계(team 레벨)라서 헷갈리는 이름 자체가 사고의 재료다.
 *   그래서 `lower(name)` 으로 비교하고 409 로 돌려보낸다 (PRD 8장 409 행).
 *
 * 이 파일이 하지 않는 것
 *   · 역할 문자열을 비교하지 않는다 — "Admin 만" 은 라우트 정책 org.manage 가 이미 판단했다.
 *   · 팀 행을 지우는 문장을 갖지 않는다 (ARCH 5.9 "팀은 지우지 않는다").
 *   · `permissions` 표를 쓰지 않는다 — 이 기능 어디에도 그 표를 바꾸는 문장이 없다 (R-43).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자 (Admin)
 * @var list<string>        $params 경로 변수 없음
 */

/** 허용 키 — 여기 없는 키가 오면 요청 전체를 거부한다 (users_create 와 같은 원칙) */
$allowedFields = ['name', 'reason_text'];

$body = Http::jsonBody();

/** 거부 기록 후 400 으로 끝낸다. 이름은 문자열일 때만, 길이를 잘라 남긴다. */
$rejectBadRequest = static function (array $body): void {
    $name = isset($body['name']) && is_string($body['name'])
        ? substr($body['name'], 0, 200)
        : null;
    Audit::deny('team.create', 'bad_request', [
        'target_type' => 'team',
        'target_name' => $name,
    ]);
    Http::error(400, Http::MSG_BAD_REQUEST);
};

// ─── [1] 허용 키 검사 ───────────────────────────────────────────
if ($body === [] || array_diff(array_keys($body), $allowedFields) !== []) {
    $rejectBadRequest($body);
}

// ─── [2] 이름 — trim 1~50자, 제어문자 없음 ──────────────────────
//        `\p{C}` 는 제어문자·서식문자·미배정 문자를 한꺼번에 막는다. 줄바꿈이나 보이지 않는
//        문자가 팀 이름에 섞이면 화면·감사 문장에서 다른 이름과 구별되지 않는다.
$name = $body['name'] ?? null;
if (!is_string($name) || preg_match('//u', $name) !== 1) {
    $rejectBadRequest($body);
}
$name = trim((string) $name);
if ($name === '' || iconv_strlen($name, 'UTF-8') > 50 || preg_match('/\p{C}/u', $name) === 1) {
    $rejectBadRequest($body);
}

// ─── [3] 사유 — 필수 500자 (PRD S-07 "관리자 행동 사유", R-20) ───
$reason = $body['reason_text'] ?? null;
if (!is_string($reason) || preg_match('//u', $reason) !== 1) {
    $rejectBadRequest($body);
}
$reason = trim((string) $reason);
if ($reason === '' || iconv_strlen($reason, 'UTF-8') > 500) {
    $rejectBadRequest($body);
}

$db = Db::instance();

/** 이미 있는 이름이다 — 409 로 끝낸다 (PRD 8장, ARCH 7.4 `team.create|deny|already_exists`) */
$rejectConflict = static function (string $name): void {
    Audit::deny('team.create', 'already_exists', [
        'target_type' => 'team',
        'target_name' => $name,
    ]);
    Http::error(409, Http::MSG_CONFLICT);
};

// ─── [4] 같은 이름이 이미 있나 (대소문자 무시) ───────────────────
$sqlExists = <<<'SQL'
    SELECT id FROM teams WHERE lower(name) = lower(:name)
    SQL;
if (is_array($db->run($sqlExists, [':name' => $name])->fetch())) {
    $rejectConflict($name);
}

// ─── [5] 저장 — 기록이 실패하면 팀도 남지 않는다 (PRD S-07) ──────
$sqlInsert = <<<'SQL'
    INSERT INTO teams (name) VALUES (:name)
    RETURNING id, name, is_active, created_at, updated_at
    SQL;

$db->begin();
try {
    $row = $db->run($sqlInsert, [':name' => $name])->fetch();
    if (!is_array($row)) {
        throw new RuntimeException('팀을 만들지 못했습니다');
    }
    $newId = (int) $row['id'];

    Audit::allow('team.create', [
        'target_type' => 'team',
        'target_id'   => $newId,
        'target_name' => $name,
        // ARCH 7.5 등록부의 reason_text — 왜 만들었나는 상세에만 남는다(문장에는 넣지 않는다).
        'detail'      => ['reason_text' => $reason],
    ]);
    $db->commit();
} catch (PDOException $e) {
    $db->rollback();
    // 23505 = unique_violation. 위 [4] 와 INSERT 사이에 다른 요청이 먼저 만든 경우다.
    if ($e->getCode() === '23505') {
        $rejectConflict($name);
    }
    throw $e;
} catch (Throwable $e) {
    $db->rollback();
    throw $e;
}

Http::json([
    'team' => [
        'id'            => $newId,
        'name'          => (string) $row['name'],
        'is_active'     => $row['is_active'] === true,
        'member_count'  => 0,
        'manager_count' => 0,
        'file_count'    => 0,
        'created_at'    => (string) $row['created_at'],
        'updated_at'    => (string) $row['updated_at'],
    ],
], 201);
