<?php
declare(strict_types=1);

/**
 * src/handlers/folders_create.php — POST /api/folders (정책: file.upload → Guest 는 라우터에서 끝)
 *
 * ★ 18-1 폴더 만들기 (PRD F-26, 8장 `POST /api/folders` 행, ARCH 5.16 "검사 순서").
 *
 * 받는 값 (JSON, **허용 키 목록 밖의 키가 하나라도 있으면 400**)
 *   name         폴더 이름 — trim 뒤 1~50자(UTF-8 글자 수), 제어문자·`/`·`\` 금지
 *   access_level 폴더의 접근 등급 — 파일과 **같은 4종**. 필수다(화면이 기본값을 물려받아
 *                채워 주지만, 서버는 명시값을 요구한다 — 기본값을 서버가 지어내면 "어느 등급인지
 *                모르고 만든 폴더" 가 생긴다)
 *   team_id      team 등급일 때 필수
 *   parent_id    부모 폴더 (선택) — 없거나 null 이면 루트
 *
 * 검사 순서 (ARCH 5.16)
 *   [1] 허용 키 → [2] 이름 규칙 → [3] 등급·팀 형식
 *   [권한 ✔ A] `Authz::decideUploadAs` — 그 등급·팀으로 **올릴 수 있는 자격**과 같다.
 *              폴더를 만드는 것은 그 등급의 칸을 여는 일이라 업로드와 같은 표를 쓴다.
 *   [4] 부모 존재 → [권한 ✔ B] 부모를 `decideRead` 로 읽을 수 있나(못 읽는 폴더 안에 무언가를
 *       만들 수는 없다 — 없는 것과 같이 취급해 403 동일 문구)
 *   [불변 ①] `Authz::fitsUnderFolder` — 새 폴더가 부모보다 **넓으면** 400 `parent_narrower`
 *   [5] 깊이(parent.depth + 1 ≤ 5) → [6] 같은 부모 안 이름 중복(409) → [7] INSERT → 기록
 *
 * 왜 `/` 와 `\` 를 따로 막나
 *   폴더 이름은 **경로에 한 글자도 들어가지 않는다**(저장 경로는 ARCH 6.3 의 무작위 이름 그대로).
 *   그래도 막는 이유는 ① 나중에 누군가 "폴더 경로" 를 만들고 싶어질 때 이미 이름 안에 구분자가
 *   들어 있으면 그 순간 경로 조작이 되고 ② breadcrumb 를 `A / B` 로 읽는 사람이 폴더 하나를
 *   두 개로 착각하기 때문이다.
 *
 * 이 파일이 하지 않는 것
 *   · 역할 문자열을 비교하지 않는다 — 판정은 전부 Authz 함수다 (ARCH 4.1).
 *   · 파일을 건드리지 않는다. 새 폴더는 언제나 비어 있다.
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자 (요청 파라미터가 아니다 — PRD S-01)
 * @var list<string>        $params 경로 변수 없음
 */

/** 허용 키 — 여기 없는 키가 오면 요청 전체를 거부한다 (SECURITY R-07 대량 할당 차단) */
$allowedFields = ['name', 'parent_id', 'access_level', 'team_id'];

$body = Http::jsonBody();

/**
 * 거부 기록 후 끝낸다. 상태 코드·문구는 부르는 쪽이 정한다
 * (형식·깊이·불변 규칙 400 · 중복 409 · 권한 403).
 */
$reject = static function (
    string $reason,
    int $status,
    string $message,
    ?string $name,
    ?string $level = null,
    array $detail = []
): void {
    Audit::deny('folder.create', $reason, [
        'target_type'  => 'folder',
        'target_name'  => $name,
        'access_level' => $level,
        'detail'       => $detail,
    ]);
    Http::error($status, $message);
};

// ─── [1] 허용 키 검사 ───────────────────────────────────────────
if ($body === [] || array_diff(array_keys($body), $allowedFields) !== []) {
    $reject('bad_request', 400, Http::MSG_BAD_REQUEST, null);
}

// ─── [2] 이름 — trim 1~50자, 제어문자·`/`·`\` 금지 ──────────────
//        `\p{C}` 는 제어문자·서식문자·미배정 문자를 한꺼번에 막는다(팀 이름과 같은 규칙).
$rawName = $body['name'] ?? null;
if (!is_string($rawName) || preg_match('//u', $rawName) !== 1) {
    $reject('bad_request', 400, Http::MSG_BAD_REQUEST, null);
}
$name = trim((string) $rawName);
if ($name === ''
    || iconv_strlen($name, 'UTF-8') > 50
    || preg_match('/\p{C}/u', $name) === 1
    || strpos($name, '/') !== false
    || strpos($name, '\\') !== false
) {
    $reject('bad_request', 400, Http::MSG_BAD_REQUEST, $name === '' ? null : $name);
}

$db = Db::instance();

// ─── [3] 등급·팀 (파일 업로드와 같은 규칙) ─────────────────────
$rawLevel = $body['access_level'] ?? '';
if (!in_array($rawLevel, ['public', 'internal', 'team', 'admin-only', 'private'], true)) {
    $reject('bad_request', 400, Http::MSG_BAD_REQUEST, $name);
}
$level = (string) $rawLevel;

$sqlTeam = <<<'SQL'
    SELECT id FROM teams WHERE id = CAST(:id AS bigint) AND is_active
    SQL;

$teamId = null;
if ($level === 'team') {
    // team 등급은 team_id 필수 (DB CHECK folders_team_requires_team_id 로도 강제)
    $rawTeam  = $body['team_id'] ?? '';
    $teamText = is_int($rawTeam) ? (string) $rawTeam : (is_string($rawTeam) ? $rawTeam : '');
    if (preg_match('/^[1-9][0-9]{0,8}$/', $teamText) !== 1) {
        $reject('bad_request', 400, Http::MSG_BAD_REQUEST, $name, $level);
    }
    // 12-3 과 같게 **활성 팀만** 있는 것으로 본다.
    $teamRow = $db->run($sqlTeam, [':id' => (int) $teamText])->fetch();
    if (!is_array($teamRow)) {
        $reject('bad_request', 400, Http::MSG_BAD_REQUEST, $name, $level);
    }
    $teamId = (int) $teamRow['id'];
}

// ─── [권한 ✔ A] 이 등급·팀으로 만들 수 있나 (파일 업로드와 같은 표) ───
[$mayCreate, $createCode] = Authz::decideUploadAs($user, $level, $teamId);
if (!$mayCreate) {
    $reject($createCode, 403, Http::MSG_FORBIDDEN, $name, $level, ['target_team' => $teamId]);
}

// ─── [4] 부모 — 없거나 null 이면 루트. 있으면 실제로 있고 읽을 수 있어야 한다 ───
$parentId = null;
if (array_key_exists('parent_id', $body) && $body['parent_id'] !== null) {
    $rawParent  = $body['parent_id'];
    $parentText = is_int($rawParent) ? (string) $rawParent : (is_string($rawParent) ? $rawParent : '');
    if (preg_match('/^[1-9][0-9]{0,8}$/', $parentText) !== 1) {
        $reject('bad_request', 400, Http::MSG_BAD_REQUEST, $name, $level);
    }
    $parentId = (int) $parentText;
}

$sqlParent = <<<'SQL'
    SELECT id, depth, access_level, team_id,
           'folder' AS item_kind   -- G-0 종류 표식(개인 폴더 넣기 판정 — decideFolderPut)
      FROM folders
     WHERE id = CAST(:id AS bigint)
    SQL;

$depth = 1;
if ($parentId !== null) {
    $parentRow = $db->run($sqlParent, [':id' => $parentId])->fetch();
    if (!is_array($parentRow)) {
        // 없는 부모 — 형식 문제로 다룬다(대상이 있는지 없는지를 따로 알려 주지 않는다)
        $reject('bad_request', 400, Http::MSG_BAD_REQUEST, $name, $level);
    }

    // ─── [권한 ✔ B] 부모를 읽을 수 있나 (파일과 같은 함수) ───
    // 못 읽는 폴더 안에 무언가를 만들 수는 없다. 403 동일 문구이고 사유는 로그에만 남는다.
    // G-0 — 개인 폴더 안에는 그 주인·「편집」 권한자만 만든다(decideFolderPut — 개인이 아니면 decideRead 그대로).
    [$mayReadParent, $parentCode] = Authz::decideFolderPut($user, $parentRow);
    if (!$mayReadParent) {
        $reject($parentCode, 403, Http::MSG_FORBIDDEN, $name, $level, ['parent_id' => $parentId]);
    }

    // ─── [불변 ①] 새 폴더가 부모보다 넓으면 안 된다 (ARCH 5.16) ───
    [$fits, $fitCode] = Authz::fitsUnderFolder($level, $teamId, $parentRow);
    if (!$fits) {
        $reject($fitCode, 400, Http::reasonMessage($fitCode), $name, $level, [
            'parent_id'   => $parentId,
            'target_team' => $teamId,
        ]);
    }

    $depth = (int) $parentRow['depth'] + 1;
}

// ─── [5] 깊이 — parent.depth + 1 ≤ 5 (PRD F-26) ────────────────
//        DB CHECK(folders_depth_range) 가 마지막 그물이지만, 앱이 먼저 보고 사유를 기록한다.
if ($depth > 5) {
    $reject('depth_exceeded', 400, Http::reasonMessage('depth_exceeded'), $name, $level, ['parent_id' => $parentId]);
}

// ─── [6] 같은 부모 안 같은 이름 (409) ───────────────────────────
//        DB 의 부분 UNIQUE 인덱스(folders_parent_name_uidx · folders_root_name_uidx)와
//        **같은 비교**다 — 대소문자를 구분한다. 앱과 DB 가 다른 규칙을 보면 한쪽만 통과하는
//        요청이 생긴다(팀 이름은 반대로 양쪽 모두 lower() 비교다).
$sqlExists = <<<'SQL'
    SELECT id FROM folders
     WHERE parent_id IS NOT DISTINCT FROM CAST(:parent AS bigint)
       AND name = :name
    SQL;

if (is_array($db->run($sqlExists, [':parent' => $parentId, ':name' => $name])->fetch())) {
    $reject('conflict', 409, Http::MSG_CONFLICT, $name, $level);
}

// ─── [7] 저장 — 기록이 실패하면 폴더도 남지 않는다 (PRD S-07) ───
$sqlInsert = <<<'SQL'
    INSERT INTO folders (parent_id, name, depth, access_level, team_id, created_by)
    VALUES (CAST(:parent AS bigint), :name, CAST(:depth AS int),
            :level, CAST(:team AS bigint), :created_by)
    RETURNING id, created_at
    SQL;

$db->begin();
try {
    $row = $db->run($sqlInsert, [
        ':parent'     => $parentId,
        ':name'       => $name,
        ':depth'      => $depth,
        ':level'      => $level,
        ':team'       => $teamId,
        ':created_by' => (int) $user['id'],
    ])->fetch();

    if (!is_array($row)) {
        throw new RuntimeException('폴더를 만들지 못했습니다');
    }
    $newId = (int) $row['id'];

    Audit::allow('folder.create', [
        'target_type'  => 'folder',
        'target_id'    => $newId,
        'target_name'  => $name,
        'access_level' => $level,
        // ARCH 7.5 등록부 — 어디에 만들었나(parent_id)·몇 단계인가(depth)·왜 허용됐나
        'detail'       => [
            'granted_by'  => $createCode,   // Authz 가 돌려준 허용 코드 그대로 (ARCH 4.1)
            'folder_id'   => $newId,
            'parent_id'   => $parentId,
            'depth'       => $depth,
            'owner_id'    => (int) $user['id'],
            'target_team' => $teamId,
        ],
    ]);
    $db->commit();
} catch (PDOException $e) {
    $db->rollback();
    // 23505 = unique_violation. 위 [6] 과 INSERT 사이에 다른 요청이 같은 이름을 먼저 만든 경우다.
    if ($e->getCode() === '23505') {
        $reject('conflict', 409, Http::MSG_CONFLICT, $name, $level);
    }
    throw $e;
} catch (Throwable $e) {
    $db->rollback();
    throw $e;   // bootstrap 예외 핸들러 → 500 + {"error":"서버 오류"}
}

Http::json([
    'folder' => [
        'id'           => $newId,
        'parent_id'    => $parentId,
        'name'         => $name,
        'depth'        => $depth,
        'access_level' => $level,
        'team_id'      => $teamId,
        'child_count'  => 0,
        'file_count'   => 0,
        'created_at'   => (string) $row['created_at'],
    ],
], 201);
