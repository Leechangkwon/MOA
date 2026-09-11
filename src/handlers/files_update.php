<?php
declare(strict_types=1);

/**
 * src/handlers/files_update.php — PATCH /api/files/{id} (정책: file.update)
 *
 * ★ 5단계: 설명·접근 등급 변경 (PRD F-08, 5.3, 8장 PATCH 행, ARCH 5.7 흐름 그대로).
 *
 * 이 핸들러가 막는 것 세 가지
 *   [2] **대량 할당(mass assignment, SECURITY R-07)** — 요청 JSON 을 순회하며 컬럼에 대응시키지
 *       않는다. 바꿀 수 있는 항목은 `description`·`access_level`·`team_id` 셋뿐이고,
 *       그 밖의 키가 하나라도 있으면 읽지도 않고 400 이다. `uploaded_by`·`sha256`·`stored_name`
 *       같은 컬럼은 요청으로 바뀌는 통로 자체가 없다 — UPDATE 문은 아래 고정 조각 3개뿐이다.
 *   [권한 ✔ 1·2] 남의 파일 수정 — decideRead(볼 수 없으면 존재 여부도 알려 주지 않는다)
 *       → decideEdit(소유자·팀 규칙, PRD 5.3)
 *   [권한 ✔ 3] 등급 하향으로 노출 범위를 넓히는 변경 — decideLevelChange
 *       (낮추는 변경은 Admin 만: PRD 4장·5.3, SECURITY R-20. 새 등급·팀 지정 자격은 업로드와 같다)
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
$allowedFields = ['description', 'access_level', 'team_id'];

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
           f.uploaded_by,
           f.description,
           f.sha256,
           f.size_bytes
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
$sqlTeam = <<<'SQL'
    SELECT id FROM teams WHERE id = :id
    SQL;

$newTeamId = null;
if ($newLevel === 'team') {
    if (array_key_exists('team_id', $body)) {
        $rawTeam  = $body['team_id'];
        $teamText = is_int($rawTeam) ? (string) $rawTeam : (is_string($rawTeam) ? $rawTeam : '');
        if (preg_match('/^[1-9][0-9]{0,8}$/', $teamText) !== 1) {
            $reject('bad_team');
        }
        $teamRow = $db->run($sqlTeam, [':id' => (int) $teamText])->fetch();
        if (!is_array($teamRow)) {
            $reject('bad_team');
        }
        $newTeamId = (int) $teamRow['id'];
    } else {
        // 요청이 팀을 건드리지 않았다면 지금 팀을 그대로 둔다. 지금도 없으면 team 등급이 될 수 없다.
        $newTeamId = $currentTeamId;
        if ($newTeamId === null) {
            $reject('bad_team');
        }
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
if ($changed === []) {
    $reject('no_change');
}

$descriptionChanged = in_array('description', $changed, true);
$levelChanged       = in_array('access_level', $changed, true) || in_array('team_id', $changed, true);

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
} else {
    $sqlApply = $sqlSetDescription;
    $applyBind = [
        ':description' => $newDescription,
        ':id'          => $fileId,
    ];
}

$db->begin();
try {
    $affected = $db->run($sqlApply, $applyBind)->rowCount();

    if ($affected !== 1) {
        // 조회와 수정 사이에 다른 요청이 먼저 지운 경우 — 없는 것과 같이 취급한다
        $db->rollback();
        Http::error(404, Http::MSG_NOT_FOUND);
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
                'owner_id'    => (int) $file['uploaded_by'],
                'target_team' => $newTeamId,
                'sha256'      => (string) $file['sha256'],
                'size_bytes'  => (int) $file['size_bytes'],
            ],
        ]);
    } else {
        Audit::allow('file.update', $auditTarget + [
            'detail' => array_merge(
                ['granted_by' => $editCode, 'changed' => $changed],
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
