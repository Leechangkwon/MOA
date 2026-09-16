<?php
declare(strict_types=1);

/**
 * src/handlers/files_update.php — PATCH /api/files/{id} (정책: file.update)
 *
 * ★ 5단계: 설명·접근 등급 변경 (PRD F-08, 5.3, 8장 PATCH 행, ARCH 5.7 흐름 그대로).
 *
 * 이 핸들러가 막는 것 세 가지
 *   [2] **대량 할당(mass assignment, SECURITY R-07)** — 요청 JSON 을 순회하며 컬럼에 대응시키지
 *       않는다. 바꿀 수 있는 항목은 `description`·`access_level`·`team_id`·`folder_id`(18-1)
 *       넷뿐이고, 그 밖의 키가 하나라도 있으면 읽지도 않고 400 이다. `uploaded_by`·`sha256`·
 *       `stored_name` 같은 컬럼은 요청으로 바뀌는 통로 자체가 없다 — UPDATE 문은 아래 고정 조각
 *       4개뿐이다.
 *   [권한 ✔ 1·2] 남의 파일 수정 — decideRead(볼 수 없으면 존재 여부도 알려 주지 않는다)
 *       → decideEdit(소유자·팀 규칙, PRD 5.3)
 *   [권한 ✔ 3] 등급 하향으로 노출 범위를 넓히는 변경 — decideLevelChange
 *       (낮추는 변경은 Admin 만: PRD 4장·5.3, SECURITY R-20. 새 등급·팀 지정 자격은 업로드와 같다)
 *   [4-3] **민감정보가 든 파일을 public·internal 로 낮추는 변경** (15-3, PRD F-22, ARCH 5.13).
 *       판단 근거는 DB 의 `files.sensitive_flags` 뿐이다 — 파일을 다시 읽지 않는다.
 *       Admin 이라도 막힌다: 여기서 막는 것은 "누가" 가 아니라 **"무엇을 어디까지 넓히나"** 이고,
 *       그래서 decideLevelChange(역할 판정) 를 통과한 **뒤에** 본다.
 *   [4-5] **폴더 불변 규칙 ①** (18-1, PRD F-26, ARCH 5.16) — 파일은 자기가 든 폴더보다 넓을 수
 *       없다(`Authz::fitsUnderFolder`). 폴더를 옮기는 요청과 등급을 바꾸는 요청 모두 여기를
 *       지난다. 대상 폴더를 **읽을 수 있나**([4-3])도 함께 본다 — 못 읽는 폴더로는 못 옮긴다.
 *
 * 그 밖에 지키는 것
 *   · CSRF: 라우터 [3] 의 Http::requireCsrf() 가 X-CSRF-Token 헤더를 검사한다 (PRD S-09-3)
 *   · 본문은 JSON 만 받는다 (Http::jsonBody) — 폼 인코딩은 400. 크로스사이트에서 자동 전송할 수
 *     있는 형식을 상태 변경 API 가 받지 않게 한다 (ARCH 4.0)
 *   · 값은 전부 바인딩한다. SQL 문자열에 값을 붙이지 않는다 (PRD S-04)
 *   · 실패 응답은 대상 정보를 담지 않는 동일 문구뿐이다. 사유는 감사 로그에만 (PRD S-09-5)
 *   · 등급·팀이 바뀌면 `file.level_change` 로, 설명만 바뀌면 `file.update` 로 기록하고
 *     before/after·changed 를 남긴다 (PRD S-07 "접근 레벨 변경: 이전값 → 새값", ARCH 7.5)
 *   · 설명 **내용**은 로그에 넣지 않는다 — 사용자 자유 텍스트다 (PRD 7.7 취지)
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자 (요청 파라미터가 아니다 — PRD S-01)
 * @var list<string>        $params $params[0] = public_id
 */

$publicId = $params[0] ?? '';

// 라우터는 `[0-9a-f-]{36}` 까지만 좁힌다. UUIDv4 형태 재검사는 여기서 한다.
if (!is_string($publicId) || !Storage::isValidUuid($publicId)) {
    Http::error(404, Http::MSG_NOT_FOUND);
}

$db = Db::instance();

// ─── [2] 허용 필드 화이트리스트 (SECURITY R-07, ARCH 5.7 [2]) ───
// 파일 행을 **읽기 전에** 본문 형태부터 본다. 모르는 키가 섞인 요청은 대상이 무엇이든 거부다.
// 그래서 이 거부 기록에는 대상이 없다 — 문장 틀의 {target} 은 '?' 로 남는다 (ARCH 7.4).
// F-58 — **토큰 요청은 허용 키가 더 좁다** (ARCH 4.5, SECURITY R-90).
//   `X-API-Token` 으로 들어온 요청(라우터 [2-T] 가 TBOX_AUTH_METHOD 를 세운다)에는 폴더 이동
//   (`folder_id`)만 열고, 설명·등급·팀은 허용 목록에서 뺀다 — 등급 변경은 사람의 결정이라
//   프로그램 창구로 우회시키지 않는다. 아래 판정·감사·응답은 두 경로가 같은 코드다.
$allowedFields = defined('TBOX_AUTH_METHOD')
    ? ['folder_id']
    : ['description', 'access_level', 'team_id', 'folder_id'];

$body = Http::jsonBody();
if ($body === [] || array_diff(array_keys($body), $allowedFields) !== []) {
    Audit::deny('file.update', 'bad_request', [
        'target_type' => 'file',
        'target_name' => null,
    ]);
    Http::error(400, Http::MSG_BAD_REQUEST);
}

// ─── [3] 파일 행 조회 ──────────────────────────────────────────
$sqlFind = <<<'SQL'
    SELECT f.id,
           f.original_name,
           f.access_level,
           f.team_id,
           f.folder_id,
           f.uploaded_by,
           f.description,
           f.sha256,
           f.size_bytes,
           cardinality(f.sensitive_flags) > 0 AS has_sensitive
      FROM files f
     WHERE f.public_id = CAST(:pid AS uuid)
       AND f.deleted_at IS NULL
    SQL;

$file = $db->run($sqlFind, [':pid' => $publicId])->fetch();
if (!is_array($file)) {
    Http::error(404, Http::MSG_NOT_FOUND);
}

$fileId             = (int) $file['id'];
$currentName        = (string) $file['original_name'];
$currentLevel       = (string) $file['access_level'];
$currentTeamId      = $file['team_id'] === null ? null : (int) $file['team_id'];
$currentDescription = (string) $file['description'];
$currentFolderId    = $file['folder_id'] === null ? null : (int) $file['folder_id'];

// 감사 로그의 "무엇을" 칸 (ARCH 7.5). 사용자 응답에는 한 글자도 나가지 않는다.
$auditWhat = [
    'owner_id'    => (int) $file['uploaded_by'],
    'target_team' => $currentTeamId,
    'sha256'      => (string) $file['sha256'],
    'size_bytes'  => (int) $file['size_bytes'],
];
$auditTarget = [
    'target_type'  => 'file',
    'target_id'    => $fileId,
    'target_name'  => $currentName,
    'access_level' => $currentLevel,
];

// ─── [권한 ✔ 1] 볼 수 없는 파일은 수정 판정까지 가지 않는다 ───
[$mayRead, $readCode] = Authz::decideRead($user, $file);
if (!$mayRead) {
    Audit::deny('file.update', $readCode, $auditTarget + ['detail' => $auditWhat]);
    Http::error(403, Http::MSG_FORBIDDEN);
}

// ─── [권한 ✔ 2] 소유자·팀 규칙 (PRD 5.3) ──────────────────────
[$mayEdit, $editCode] = Authz::decideEdit($user, $file);
if (!$mayEdit) {
    Audit::deny('file.update', $editCode, $auditTarget + ['detail' => $auditWhat]);
    Http::error(403, Http::MSG_FORBIDDEN);
}

// ─── [4] 값 검증 — 업로드와 같은 규칙 ─────────────────────────
/** 검증 실패: 사유는 감사 로그에만, 사용자에게는 동일한 400 문구만 (PRD S-09-5) */
$reject = static function (string $reason) use ($auditTarget, $auditWhat): void {
    Audit::deny('file.update', $reason, $auditTarget + ['detail' => $auditWhat]);
    Http::error(400, Http::MSG_BAD_REQUEST);
};

// 설명 — 문자열·UTF-8·제어문자 제거·500자 (DB CHECK 와 같은 한계를 앱에서도 본다)
$newDescription = $currentDescription;
if (array_key_exists('description', $body)) {
    $rawDescription = $body['description'];
    if (!is_string($rawDescription)) {
        $reject('bad_request');
    }
    if ($rawDescription !== '' && preg_match('//u', $rawDescription) !== 1) {
        $reject('bad_encoding');
    }
    $newDescription = (string) preg_replace('/[\x00-\x1f\x7f]+/u', '', $rawDescription);
    if (iconv_strlen($newDescription, 'UTF-8') > 500) {
        $reject('description_too_long');
    }
}

// 접근 등급 — 4값 중 하나만
$newLevel = $currentLevel;
if (array_key_exists('access_level', $body)) {
    $rawLevel = $body['access_level'];
    if (!in_array($rawLevel, ['public', 'internal', 'team', 'admin-only'], true)) {
        $reject('bad_access_level');
    }
    $newLevel = (string) $rawLevel;
}

// 팀 — 새 등급이 team 일 때만 의미가 있다. team 이 아니면 요청에 무엇이 실려 있든 NULL 로 정규화한다
// (DB CHECK team_requires_team_id 와 같은 규칙. team 이 아닌 행에 팀이 남아 있지 않게 한다).
// 12-3 (F-19, ARCH 5.9): **활성 팀만** 있는 것으로 본다 — 비활성 팀으로 옮기려는 요청은
// 기존 거부 경로(bad_team → 400)로 끝난다. 검사는 **팀이 실제로 바뀔 때만** 한다:
// 이미 비활성 팀에 있는 파일의 설명만 고치는 PATCH 까지 막을 이유가 없기 때문이다
// (읽기 판정은 team_id 일치만 보고, 그 규칙은 12-3 에서 바뀌지 않았다 — ARCH 4.1).
$sqlTeam = <<<'SQL'
    SELECT id FROM teams WHERE id = :id AND is_active
    SQL;

$newTeamId = null;
if ($newLevel === 'team') {
    if (array_key_exists('team_id', $body)) {
        $rawTeam  = $body['team_id'];
        $teamText = is_int($rawTeam) ? (string) $rawTeam : (is_string($rawTeam) ? $rawTeam : '');
        if (preg_match('/^[1-9][0-9]{0,8}$/', $teamText) !== 1) {
            $reject('bad_team');
        }
        $wantTeamId = (int) $teamText;
        if ($wantTeamId === $currentTeamId) {
            // 팀을 그대로 다시 보낸 요청 — 바뀌는 것이 없으므로 활성 여부를 다시 묻지 않는다.
            $newTeamId = $currentTeamId;
        } else {
            $teamRow = $db->run($sqlTeam, [':id' => $wantTeamId])->fetch();
            if (!is_array($teamRow)) {
                $reject('bad_team');
            }
            $newTeamId = (int) $teamRow['id'];
        }
    } else {
        // 요청이 팀을 건드리지 않았다면 지금 팀을 그대로 둔다. 지금도 없으면 team 등급이 될 수 없다.
        $newTeamId = $currentTeamId;
        if ($newTeamId === null) {
            $reject('bad_team');
        }
    }
}

/* ─── [4-3] 폴더 이동 (18-1, PRD F-26, ARCH 5.16 "파일 쪽 변경") ───
 *
 * `folder_id` 는 **정수 또는 null**(루트로 빼기)이다. 폴더가 실제로 있고 **읽을 수 있어야**
 * 한다 — 못 읽는 폴더 안으로 파일을 밀어 넣을 수 있으면 그 폴더의 내용을 바꾸는 길이 된다.
 * 없는 폴더와 못 읽는 폴더는 **같은 400 동일 문구**이고 사유(`bad_folder`)는 로그에만 남는다.
 *
 * 불변 규칙 ①(폴더보다 넓을 수 없다)은 등급 판정이 끝난 뒤 [4-5] 에서 **최종 등급**으로 본다.
 */
$sqlFolder = <<<'SQL'
    SELECT id, name, access_level, team_id
      FROM folders
     WHERE id = CAST(:id AS bigint)
    SQL;

$newFolderId = $currentFolderId;
$folderRow   = null;
if (array_key_exists('folder_id', $body)) {
    if ($body['folder_id'] === null) {
        $newFolderId = null;   // 루트로 빼기
    } else {
        $rawFolder  = $body['folder_id'];
        $folderText = is_int($rawFolder) ? (string) $rawFolder : (is_string($rawFolder) ? $rawFolder : '');
        if (preg_match('/^[1-9][0-9]{0,8}$/', $folderText) !== 1) {
            $reject('bad_folder');
        }
        $newFolderId = (int) $folderText;
    }
}
if ($newFolderId !== null) {
    $folderRow = $db->run($sqlFolder, [':id' => $newFolderId])->fetch();
    if (!is_array($folderRow)) {
        $reject('bad_folder');
    }
    // 대상 폴더를 읽을 수 있나 — 파일과 **같은 함수**다 (폴더도 등급을 갖는다, F-26).
    if (!Authz::decideRead($user, $folderRow)[0]) {
        $reject('bad_folder');
    }
}

// ─── [4-2] 바뀌는 값이 하나도 없으면 거부 ──────────────────────
$changed = [];
if ($newDescription !== $currentDescription) {
    $changed[] = 'description';
}
if ($newLevel !== $currentLevel) {
    $changed[] = 'access_level';
}
if ($newTeamId !== $currentTeamId) {
    $changed[] = 'team_id';
}
if ($newFolderId !== $currentFolderId) {
    $changed[] = 'folder_id';
}
if ($changed === []) {
    $reject('no_change');
}

$descriptionChanged = in_array('description', $changed, true);
$levelChanged       = in_array('access_level', $changed, true) || in_array('team_id', $changed, true);
$folderChanged      = in_array('folder_id', $changed, true);

// 감사 detail 의 "어느 폴더에서 어디로" (ARCH 7.5 folder_before·folder_after)
$folderBefore = $currentFolderId;
$folderAfter  = $newFolderId;

// ─── [권한 ✔ 3] 등급·팀이 바뀌면 그 변경 자체를 판정한다 ───────
// 기록의 {level}·access_level 은 **새 등급**이다 — "무엇으로 바꾸려 했나" 가 조사의 출발점이다.
$before     = ['access_level' => $currentLevel, 'team_id' => $currentTeamId];
$after      = ['access_level' => $newLevel,     'team_id' => $newTeamId];
$levelCode  = null;
if ($levelChanged) {
    [$mayChange, $levelCode] = Authz::decideLevelChange($user, $file, $newLevel, $newTeamId);
    if (!$mayChange) {
        Audit::deny('file.level_change', $levelCode, [
            'target_type'  => 'file',
            'target_id'    => $fileId,
            'target_name'  => $currentName,
            'access_level' => $newLevel,
            'detail'       => [
                'before'      => $before,
                'after'       => $after,
                'owner_id'    => (int) $file['uploaded_by'],
                'target_team' => $newTeamId,
                'sha256'      => (string) $file['sha256'],
                'size_bytes'  => (int) $file['size_bytes'],
            ],
        ]);
        Http::error(403, Http::MSG_FORBIDDEN);
    }
}

/* ─── [4-3] 민감정보가 든 파일의 등급 하향 거부 (15-3, PRD F-22, ARCH 5.13) ───
 *
 * `sensitive_flags` 가 비어 있지 않은 파일은 `public`·`internal` 로 바꿀 수 없다.
 * 업로드 때 그 등급으로 올리지 못하게 막았으니(files_upload [4-3]), 올린 뒤에 등급을
 * 낮춰 같은 결과를 만드는 길도 함께 막아야 규칙이 성립한다 (F-08 과 F-22 의 접점).
 *
 * 자리: decideLevelChange(역할·하향 판정) **뒤**다. 권한이 없는 사람에게는 권한 문제로,
 * 권한이 있는 사람에게는 내용 문제로 답하는 것이 조사할 때 덜 헷갈린다.
 *
 * 응답은 400(형식·내용 문제)이고 403(권한 문제)이 아니다 — Admin 도 같은 400 을 받는다.
 * 검출된 값은 어디에도 나오지 않는다: 기록도 응답도 "민감정보가 있다" 는 사실까지다.
 */
if ($levelChanged
    && in_array($newLevel, ['public', 'internal'], true)
    && ($file['has_sensitive'] ?? false) === true
) {
    Audit::deny('file.level_change', 'sensitive_level_required', [
        'target_type'  => 'file',
        'target_id'    => $fileId,
        'target_name'  => $currentName,
        'access_level' => $newLevel,      // 문장 틀의 {level} = 바꾸려던 등급
        'detail'       => [
            'before'      => $before,
            'after'       => $after,
            'owner_id'    => (int) $file['uploaded_by'],
            'target_team' => $newTeamId,
            'sha256'      => (string) $file['sha256'],
            'size_bytes'  => (int) $file['size_bytes'],
        ],
    ]);
    Http::error(400, Http::MSG_SENSITIVE_LEVEL_REQUIRED);
}

/* ─── [4-5] 불변 규칙 ① — 파일은 자기가 든 폴더보다 넓을 수 없다 ───
 *   (18-1, PRD F-26, ARCH 5.16. 판정은 Authz::fitsUnderFolder 한 곳이다.)
 *
 * **최종** 등급·팀으로 **최종** 폴더를 본다 — 폴더를 옮기는 요청과 등급을 바꾸는 요청이
 * 한 번에 올 수 있기 때문이다. 거부는 무엇이 원인인지에 따라 남는 자리가 갈린다:
 *   · 폴더가 바뀐 요청  → `file.update deny parent_narrower`  (파일을 좁은 폴더로 옮기려 함)
 *   · 등급만 바뀐 요청  → `file.level_change deny parent_narrower` (폴더보다 넓게 바꾸려 함)
 * 둘 다 400 이고 Admin 도 같은 400 이다 — 여기서 막는 것은 "누가" 가 아니라 "무엇이 어디에
 * 들어갈 수 있나" 이기 때문이다(민감정보 규칙 [4-3] 과 같은 성격).
 */
if ($folderRow !== null && ($folderChanged || $levelChanged)) {
    [$fits, $fitCode] = Authz::fitsUnderFolder($newLevel, $newTeamId, $folderRow);
    if (!$fits) {
        Audit::deny($folderChanged ? 'file.update' : 'file.level_change', $fitCode, [
            'target_type'  => 'file',
            'target_id'    => $fileId,
            'target_name'  => $currentName,
            'access_level' => $newLevel,
            'detail'       => [
                'folder_before' => $folderBefore,
                'folder_after'  => $folderAfter,
                'before'        => $before,
                'after'         => $after,
            ] + $auditWhat,
        ]);
        Http::error(400, Http::MSG_BAD_REQUEST);
    }
}

// ─── [5] 고정 SQL 조각 중 하나 + 바인딩 ────────────────────────
// 요청 키로 SQL 을 조립하지 않는다. 아래 세 문장은 코드에 적힌 고정 문자열이며,
// 그 밖의 컬럼은 어떤 요청으로도 이 핸들러에서 바뀌지 않는다 (SECURITY R-07).
$sqlSetDescription = <<<'SQL'
    UPDATE files
       SET description = :description
     WHERE id = :id
       AND deleted_at IS NULL
    SQL;

$sqlSetLevel = <<<'SQL'
    UPDATE files
       SET access_level = :access_level,
           team_id      = :team_id
     WHERE id = :id
       AND deleted_at IS NULL
    SQL;

$sqlSetBoth = <<<'SQL'
    UPDATE files
       SET description  = :description,
           access_level = :access_level,
           team_id      = :team_id
     WHERE id = :id
       AND deleted_at IS NULL
    SQL;

/* 폴더 이동은 **따로 선 문장 하나**다 (18-1). 설명·등급과 조합해 8가지 문장을 만들지 않고,
 * 바뀌는 것이 있으면 같은 트랜잭션 안에서 이 문장을 한 번 더 실행한다 — 고정 문장을 늘리는
 * 대신 조합을 없애는 쪽을 골랐다(SQL 을 이어 붙이지 않는다는 규칙은 그대로다, PRD S-04). */
$sqlSetFolder = <<<'SQL'
    UPDATE files
       SET folder_id = CAST(:folder_id AS bigint)
     WHERE id = :id
       AND deleted_at IS NULL
    SQL;

$sqlApply  = null;
$applyBind = [];
if ($descriptionChanged && $levelChanged) {
    $sqlApply = $sqlSetBoth;
    $applyBind = [
        ':description'  => $newDescription,
        ':access_level' => $newLevel,
        ':team_id'      => $newTeamId,
        ':id'           => $fileId,
    ];
} elseif ($levelChanged) {
    $sqlApply = $sqlSetLevel;
    $applyBind = [
        ':access_level' => $newLevel,
        ':team_id'      => $newTeamId,
        ':id'           => $fileId,
    ];
} elseif ($descriptionChanged) {
    $sqlApply = $sqlSetDescription;
    $applyBind = [
        ':description' => $newDescription,
        ':id'          => $fileId,
    ];
}

$db->begin();
try {
    if ($sqlApply !== null) {
        $affected = $db->run($sqlApply, $applyBind)->rowCount();
        if ($affected !== 1) {
            // 조회와 수정 사이에 다른 요청이 먼저 지운 경우 — 없는 것과 같이 취급한다
            $db->rollback();
            Http::error(404, Http::MSG_NOT_FOUND);
        }
    }

    if ($folderChanged) {
        $moved = $db->run($sqlSetFolder, [
            ':folder_id' => $newFolderId,
            ':id'        => $fileId,
        ])->rowCount();
        if ($moved !== 1) {
            $db->rollback();
            Http::error(404, Http::MSG_NOT_FOUND);
        }
    }

    if ($levelChanged) {
        Audit::allow('file.level_change', [
            'target_type'  => 'file',
            'target_id'    => $fileId,
            'target_name'  => $currentName,
            'access_level' => $newLevel,      // 문장 틀의 {level} = 바뀐 뒤 등급
            'detail'       => [
                'granted_by'  => $levelCode,  // Authz 가 돌려준 허용 코드 그대로 (ARCH 4.1)
                'before'      => $before,
                'after'       => $after,
                'changed'     => $changed,
                // 18-1 — 같은 요청에서 폴더까지 옮겼으면 어디에서 어디로 (ARCH 7.5)
                'folder_before' => $folderBefore,
                'folder_after'  => $folderAfter,
                'owner_id'    => (int) $file['uploaded_by'],
                'target_team' => $newTeamId,
                'sha256'      => (string) $file['sha256'],
                'size_bytes'  => (int) $file['size_bytes'],
            ],
        ]);
    } else {
        Audit::allow('file.update', $auditTarget + [
            'detail' => array_merge(
                [
                    'granted_by' => $editCode,
                    'changed'    => $changed,
                    // 18-1 (F-26) — 파일을 폴더로 옮긴 기록. 새 action 을 만들지 않고
                    // 기존 file.update allow 의 detail 에 남긴다 (ARCH 5.16 · 7.5).
                    'folder_before' => $folderBefore,
                    'folder_after'  => $folderAfter,
                ],
                $auditWhat
            ),
        ]);
    }

    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    throw $e;   // bootstrap 예외 핸들러 → 500 + {"error":"서버 오류"}
}

Http::json(['ok' => true]);
