<?php
declare(strict_types=1);

/**
 * src/handlers/files_delete.php — DELETE /api/files/{id} (정책: authenticated)
 *
 * ★ 3단계: 권한 검사 없음 — 4단계에서 파일 행을 읽은 **직후**, UPDATE 전에
 *   `Authz::canRead($user, $file)` → `Authz::canDelete($user, $file)` 를 넣는다
 *   (실패 → Audit::deny + 403 동일 문구). 지금은 로그인만 했으면 남의 파일도 지워진다.
 *   이것이 기존 t-box 취약점 A("버튼만 숨기고 API 는 누구나")의 재현 상태다
 *   (PRD 13장 3단계, 4단계 T-1 비교용).
 *
 * 요구사항: PRD F-07, 8장 API 표, ARCH 5.4
 *
 * 3단계에도 지키는 것
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
           f.uploaded_by
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

// ★ 4단계에서 이 자리에 Authz::canRead → Authz::canDelete 가 들어간다.

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

    Audit::allow('file.delete', [
        'target_type'  => 'file',
        'target_id'    => $fileId,
        'target_name'  => (string) $file['original_name'],
        'access_level' => (string) $file['access_level'],
        'detail'       => ['mode' => 'soft_delete'],
    ]);

    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    throw $e;   // bootstrap 예외 핸들러 → 500
}

Http::json(['ok' => true]);
