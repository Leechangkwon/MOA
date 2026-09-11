<?php
declare(strict_types=1);

/**
 * src/handlers/files_restore.php — POST /api/files/{id}/restore (정책: role:Admin)
 *
 * 요구사항: PRD F-11 · 5.4(삭제된 파일 복구는 Admin) · S-07(파일 삭제/복구 기록), 8장 restore 행,
 *           ARCH 7.4 `file.restore|allow`·`file.restore|deny|not_found_in_trash`, 7.5 `mode`
 *
 * 복구할 수 있는 것은 **휴지통에 있는 파일**뿐이다:
 *   `deleted_at IS NOT NULL`  — 지워진 적이 있고
 *   `purged_at  IS NULL`      — 30일 배치가 아직 실제 바이트를 지우지 않았다 (9단계)
 * 둘 중 하나라도 어긋나면(살아 있는 파일·없는 ID·이미 영구 삭제된 파일) 전부 **404 동일 문구**다 —
 * "있지만 복구할 수 없다" 와 "없다" 가 응답으로 갈라지면 그 차이가 곧 정보다 (PRD S-09-5).
 *
 * 그 밖에 지키는 것
 *   · CSRF: 라우터 [3] 의 Http::requireCsrf() 가 이미 검사했다 (PRD S-09-3)
 *   · 역할 판단은 라우트 정책 `role:Admin`(Authz) 이 한다 — 이 파일에 역할 비교가 없다
 *   · UPDATE 는 `deleted_at`·`deleted_by` 두 칸만 되돌린다. 파일 내용·소유자·등급은 건드리지 않는다
 *   · 성공·거부 모두 감사 로그 (PRD S-07 "파일 · 삭제 / 복구")
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params $params[0] = public_id
 */

$publicId = $params[0] ?? '';

/** 감사 로그의 "무엇을" 칸 — 대상을 특정할 수 없을 때 쓰는 기본값 */
$unknownTarget = [
    'target_type' => 'file',
    'target_name' => null,
];

if (!is_string($publicId) || !Storage::isValidUuid($publicId)) {
    Audit::deny('file.restore', 'not_found_in_trash', $unknownTarget);
    Http::error(404, Http::MSG_NOT_FOUND);
}

$sqlFind = <<<'SQL'
    SELECT f.id,
           f.original_name,
           f.access_level,
           f.team_id,
           f.uploaded_by,
           f.sha256,
           f.size_bytes
      FROM files f
     WHERE f.public_id  = CAST(:pid AS uuid)
       AND f.deleted_at IS NOT NULL
       AND f.purged_at  IS NULL
    SQL;

$sqlRestore = <<<'SQL'
    UPDATE files
       SET deleted_at = NULL,
           deleted_by = NULL
     WHERE id = :id
       AND deleted_at IS NOT NULL
       AND purged_at  IS NULL
    SQL;

$db   = Db::instance();
$file = $db->run($sqlFind, [':pid' => $publicId])->fetch();

if (!is_array($file)) {
    // 없는 ID · 살아 있는 파일 · 이미 영구 삭제된 파일이 모두 여기로 온다 (같은 응답).
    Audit::deny('file.restore', 'not_found_in_trash', $unknownTarget);
    Http::error(404, Http::MSG_NOT_FOUND);
}

$auditWhat = [
    'mode'        => 'restore',
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

$db->begin();
try {
    $affected = $db->run($sqlRestore, [':id' => (int) $file['id']])->rowCount();
    if ($affected !== 1) {
        // 조회와 UPDATE 사이에 다른 요청이 먼저 복구했다 — 없는 것과 같이 취급한다
        $db->rollback();
        Http::error(404, Http::MSG_NOT_FOUND);
    }

    Audit::allow('file.restore', $auditTarget + ['detail' => $auditWhat]);
    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    throw $e;   // bootstrap 예외 핸들러 → 500
}

Http::json(['ok' => true]);
