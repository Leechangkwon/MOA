<?php
declare(strict_types=1);

/**
 * src/handlers/files_restore.php — POST /api/files/{id}/restore (정책: authenticated → 여기서 판정)
 *
 * 요구사항: PRD F-11 · 5.4 각주 ¹(Admin 은 모든 파일, 올린 사람은 자기가 지운 자기 파일) · S-07(파일
 *           삭제/복구 기록), 8장 restore 행, ARCH 7.4 `file.restore|allow`(granted_by)·
 *           `file.restore|deny|not_found_in_trash`, 7.5 `mode` — Q:F-4 U-2b (2026-09-24)
 *
 * 복구할 수 있는 것은 **휴지통에 있는 파일**뿐이다:
 *   `deleted_at IS NOT NULL`  — 지워진 적이 있고
 *   `purged_at  IS NULL`      — 30일 배치가 아직 실제 바이트를 지우지 않았다 (9단계)
 * 둘 중 하나라도 어긋나면(살아 있는 파일·없는 ID·이미 영구 삭제된 파일) 전부 **404 동일 문구**다 —
 * "있지만 복구할 수 없다" 와 "없다" 가 응답으로 갈라지면 그 차이가 곧 정보다 (PRD S-09-5).
 *
 * 누가 되살릴 수 있나 (Q:F-4 U-2b — 판정은 Authz 한 곳, 이 파일에 역할 비교가 없다)
 *   [권한 ✔ 1] `Authz::restorableFilesWhere` — 찾는 SQL 에 **처음부터** 붙인다. Admin 이면 조건 없음,
 *              올릴 수 있는 역할이면 `uploaded_by = 나 AND deleted_by = 나`, 그 밖(Guest)은 0건.
 *              남이 올렸거나 남이 지운 파일은 여기서 행 자체가 안 나온다 → 없는 파일과 **같은 404·같은 기록**.
 *   [재인증]   범위가 `admin_all`(Admin 전체)이면 행을 찾기 **전에** 15분 재인증을 본다 — 전에 라우터
 *              관문 [3-2] 가 핸들러보다 먼저 막던 순서와 같다(재인증 없는 Admin 은 UUID 가 있든 없든 403).
 *              본인 복구(`owner`)는 묻지 않는다.
 *   [권한 ✔ 2] `Authz::decideRestore` — 찾은 행을 한 번 더 판정해 허용 코드(`admin_all`·`owner`)를
 *              받는다. 그 코드가 감사 `detail.granted_by` 다. 거부(두 겹이 어긋나는 일)도 같은 404·같은 기록.
 *   UPDATE 는 **판정한 그 삭제**(같은 `deleted_by`)일 때만 되돌린다 — 조회와 UPDATE 사이에 누가
 *   되살리고 다른 사람이 다시 지웠다면 판정 근거가 달라졌으므로 없는 것과 같이 404 다.
 *
 * 왜 남의 파일도 `not_found_in_trash` 인가: 「내 활동」(F-12)이 본인 행의 `target_name`·`summary` 를
 * 보여 준다. 남의 파일일 때만 이름이나 다른 사유를 남기면, 시도한 사람이 자기 활동 화면에서 그 UUID 의
 * 파일이 휴지통에 **있다**는 것을 읽는다 (ARCH 7.4). 그래서 대상 칸도 없는 파일과 같다($unknownTarget).
 *
 * 그 밖에 지키는 것
 *   · CSRF: 라우터 [3] 의 Http::requireCsrf() 가 이미 검사했다 (PRD S-09-3)
 *   · UPDATE 는 `deleted_at`·`deleted_by` 두 칸만 되돌린다. 파일 내용·소유자·등급은 건드리지 않는다
 *   · 성공·거부 모두 감사 로그 (PRD S-07 "파일 · 삭제 / 복구")
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params $params[0] = public_id
 */

$publicId = $params[0] ?? '';

// ─── [권한 ✔ 1] 복구 범위 — 찾는 SQL 의 조건과 재인증 여부가 이 한 번의 판정에서 나온다 ───
[$scopeWhere, $scopeParams, $scopeCode] = Authz::restorableFilesWhere($user);

// ─── [재인증] 전체 범위(Admin)만 — 행을 찾기 전에 (관문 [3-2] 와 같은 문장·같은 응답 모양) ───
if ($scopeCode === Authz::CODE_ADMIN_ALL && ($user['reauth_fresh'] ?? false) !== true) {
    Audit::deny('auth.gate', 'reauth_required', [
        'target_type' => 'user',
        'target_id'   => (int) $user['id'],
        'target_name' => (string) $user['username'],
        // route 패턴은 Audit 이 TBOX_ROUTE 에서 자동으로 넣는다 (ARCH 7.5 route).
        'detail'      => ['next' => 'reauth', 'policy' => Authz::POLICY_AUTHENTICATED],
    ]);
    Http::json(['error' => Http::MSG_REAUTH_REQUIRED, 'next' => 'reauth'], 403);
    exit;
}

/** 감사 로그의 "무엇을" 칸 — 대상을 특정할 수 없을 때 쓰는 기본값 */
$unknownTarget = [
    'target_type' => 'file',
    'target_name' => null,
];

if (!is_string($publicId) || !Storage::isValidUuid($publicId)) {
    Audit::deny('file.restore', 'not_found_in_trash', $unknownTarget);
    Http::error(404, Http::MSG_NOT_FOUND);
}

// 아래 heredoc 은 `AND` 에서 끝나고, 바로 뒤에 위 범위 조건절(Authz 가 코드에 적어 둔 조각)이 붙는다.
// 이어 붙이는 것에 요청 값은 없다 — 값(UUID·내 id)은 전부 바인딩이다 (PRD S-04).
$sqlFind = <<<'SQL'
    SELECT f.id,
           f.original_name,
           f.access_level,
           f.team_id,
           f.uploaded_by,
           f.deleted_by,
           f.sha256,
           f.size_bytes
      FROM files f
     WHERE f.public_id  = CAST(:pid AS uuid)
       AND f.deleted_at IS NOT NULL
       AND f.purged_at  IS NULL
       AND
    SQL;

// `deleted_by` 가 판정할 때 읽은 값과 같을 때만 — 그사이 다른 사람이 되살리고 다시 지웠다면 0행(→ 404).
$sqlRestore = <<<'SQL'
    UPDATE files
       SET deleted_at = NULL,
           deleted_by = NULL
     WHERE id = :id
       AND deleted_at IS NOT NULL
       AND purged_at  IS NULL
       AND deleted_by IS NOT DISTINCT FROM CAST(:seen_deleted_by AS bigint)
    SQL;

$db   = Db::instance();
$file = $db->run($sqlFind . ' ' . $scopeWhere, $scopeParams + [':pid' => $publicId])->fetch();

if (!is_array($file)) {
    // 없는 ID · 살아 있는 파일 · 이미 영구 삭제된 파일 · **남이 올렸거나 남이 지운 파일**(본인 범위 밖)이
    // 모두 여기로 온다 — 같은 응답·같은 기록 (PRD 5.4 각주 ¹, ARCH 7.4).
    Audit::deny('file.restore', Authz::CODE_NOT_FOUND_IN_TRASH, $unknownTarget);
    Http::error(404, Http::MSG_NOT_FOUND);
}

// ─── [권한 ✔ 2] 찾은 행을 한 번 더 판정 — 허용 코드가 감사의 granted_by 가 된다 ───
[$mayRestore, $restoreCode] = Authz::decideRestore($user, $file);
if (!$mayRestore) {
    // 위 SQL 조건과 어긋나는 일은 없어야 하지만, 어긋나도 **없는 파일과 같은** 응답·기록이다.
    Audit::deny('file.restore', $restoreCode, $unknownTarget);
    Http::error(404, Http::MSG_NOT_FOUND);
}

$auditWhat = [
    'mode'        => 'restore',
    // 허용 근거는 Authz 가 돌려준 코드 그대로 (`admin_all` · `owner` — ARCH 4.1 · 7.4, PRD S-07 "허용에도 근거")
    'granted_by'  => $restoreCode,
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
    $affected = $db->run($sqlRestore, [
        ':id'              => (int) $file['id'],
        ':seen_deleted_by' => $file['deleted_by'] === null ? null : (int) $file['deleted_by'],
    ])->rowCount();
    if ($affected !== 1) {
        // 조회와 UPDATE 사이에 다른 요청이 먼저 복구했거나(다시 지워져 지운 사람이 바뀐 경우 포함)
        // — 없는 것과 같이 취급한다
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
