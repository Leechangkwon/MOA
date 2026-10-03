<?php
declare(strict_types=1);

/**
 * src/handlers/files_delete.php — DELETE /api/files/{id} (정책: file.delete)
 *
 * ★ 4단계: 파일 행을 읽은 **직후**, UPDATE 전에 두 번 판정한다 (ARCH 5.4)
 *     [권한 ✔ 1] decideRead  — 볼 수 없는 파일은 삭제 시도도 403 (존재 여부조차 알려 주지 않는다)
 *     [권한 ✔ 2] decideDelete — 소유자·팀 규칙 (PRD 5.3)
 *   둘 다 실패는 감사 로그 deny + 403 동일 문구다. 이것이 기존 t-box 취약점 A
 *   ("버튼만 숨기고 API 는 누구나")를 막는 자리다 — 화면에서 버튼을 되살려도 여기서 걸린다.
 *
 * 요구사항: PRD F-07, S-01 · S-07, 8장 API 표, ARCH 5.4
 *
 * 그 밖에 지키는 것
 *   · CSRF: 라우터 [3] 의 Http::requireCsrf() 가 이미 검사했다 (PRD S-09-3)
 *   · 논리 삭제만 한다 — `deleted_at`·`deleted_by` 를 채우고 실제 바이트는 남긴다
 *     (30일 휴지통, F-07/F-11. 실제 삭제는 9단계 배치 scripts/purge_trash.php)
 *   · 앱 DB 계정에는 files 의 DELETE 권한이 아예 없다 (db/grants.sql)
 *   · 없는 파일·이미 삭제된 파일은 404 동일 문구
 *   · 성공은 감사 로그 (PRD S-07)
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자 (deleted_by 는 이 값만 쓴다)
 * @var list<string>        $params $params[0] = public_id
 */

$publicId = $params[0] ?? '';

if (!is_string($publicId) || !Storage::isValidUuid($publicId)) {
    Http::error(404, Http::MSG_NOT_FOUND);
}

$sqlFind = <<<'SQL'
    SELECT f.id,
           f.original_name,
           f.access_level,
           f.team_id,
           f.uploaded_by,
           f.sha256,
           f.size_bytes,
           'file' AS item_kind   -- G-0 종류 표식(개인 등급: decideRead [P] · decideDelete 주인만)
      FROM files f
     WHERE f.public_id = CAST(:pid AS uuid)
       AND f.deleted_at IS NULL
    SQL;

$sqlSoftDelete = <<<'SQL'
    UPDATE files
       SET deleted_at = now(),
           deleted_by = :uid
     WHERE id = :id
       AND deleted_at IS NULL
    SQL;

$db   = Db::instance();
$file = $db->run($sqlFind, [':pid' => $publicId])->fetch();
if (!is_array($file)) {
    Http::error(404, Http::MSG_NOT_FOUND);
}

// ─── [권한 ✔ 1·2] 읽기 → 삭제 판정 (UPDATE 전, ARCH 5.4) ───
// 감사 로그의 "무엇을" 칸. 사용자 응답에는 나가지 않고 로그에만 남는다 (PRD S-07).
$auditWhat = [
    'owner_id'    => (int) $file['uploaded_by'],
    'target_team' => $file['team_id'] === null ? null : (int) $file['team_id'],
    'sha256'      => (string) $file['sha256'],
    'size_bytes'  => (int) $file['size_bytes'],
];
$auditTarget = [
    'target_type'  => 'file',
    'target_id'    => (int) $file['id'],
    'target_name'  => (string) $file['original_name'],
    'access_level' => (string) $file['access_level'],
];

// [1] 볼 수 없는 파일이면 삭제 판정까지 가지 않는다 (사유: role_not_permitted 또는 team_mismatch)
[$mayRead, $readCode] = Authz::decideRead($user, $file);
if (!$mayRead) {
    Audit::deny('file.delete', $readCode, $auditTarget + ['detail' => $auditWhat]);
    Http::error(403, Http::MSG_FORBIDDEN);
}

// [2] 소유자·팀 규칙 (사유: not_owner · team_mismatch · role_not_permitted)
[$mayDelete, $deleteCode] = Authz::decideDelete($user, $file);
if (!$mayDelete) {
    Audit::deny('file.delete', $deleteCode, $auditTarget + ['detail' => $auditWhat]);
    Http::error(403, Http::MSG_FORBIDDEN);
}

$fileId = (int) $file['id'];

$db->begin();
try {
    $affected = $db->run($sqlSoftDelete, [
        ':uid' => (int) $user['id'],
        ':id'  => $fileId,
    ])->rowCount();

    if ($affected !== 1) {
        // 조회와 UPDATE 사이에 다른 요청이 먼저 지운 경우 — 없는 것과 같이 취급한다
        $db->rollback();
        Http::error(404, Http::MSG_NOT_FOUND);
    }

    Audit::allow('file.delete', $auditTarget + [
        // 허용 근거(granted_by)는 Authz 가 돌려준 코드 그대로 (ARCH 4.1, PRD S-07 "허용에도 근거")
        'detail' => array_merge(
            ['mode' => 'soft_delete', 'granted_by' => $deleteCode],
            $auditWhat
        ),
    ]);

    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    throw $e;   // bootstrap 예외 핸들러 → 500
}

Http::json(['ok' => true]);
