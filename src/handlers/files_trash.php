<?php
declare(strict_types=1);

/**
 * src/handlers/files_trash.php — GET /api/files/trash (정책: role:Admin)
 *
 * 요구사항: PRD F-11 · 5.4 "삭제된 파일 복구(30일 내) — Admin", 8장, ARCH 5.1(목록 패턴)
 *
 * 휴지통 = **논리 삭제됐고(`deleted_at IS NOT NULL`) 아직 실제 바이트가 남아 있는
 * (`purged_at IS NULL`) 파일**이다. 30일이 지나 배치(scripts/purge_trash.php)가 바이트를 지운
 * 행은 `purged_at` 이 채워져 있고, 복구해도 내려받을 파일이 없으므로 목록에 담지 않는다 (9단계).
 *
 * 지키는 것
 *   · Admin 전용이다. 판단은 라우트 정책 `role:Admin`(Authz) 이 하고 이 파일에는 역할 비교가 없다.
 *     휴지통에는 admin-only 파일도 섞여 있으므로 "볼 수 있는 파일만" 조건(visibleFilesWhere)을
 *     쓰지 않는다 — 대신 **역할 자체를 Admin 으로 좁힌** 것이 그 자리를 대신한다 (PRD 5.4).
 *   · 값은 전부 바인딩하고 정렬은 고정이다(`deleted_at DESC`) — 사용자가 정렬 컬럼을 고르지 못한다.
 *   · 내부 `files.id` 는 내보내지 않는다. 외부 ID 는 `public_id`(UUID) 뿐이다 (PRD S-09-5).
 *   · 목록 조회는 감사 로그를 남기지 않는다 (PRD S-07 "양이 많은 읽기 행동은 기본 미기록").
 *     복구(`file.restore`)는 남는다.
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params 경로 변수 없음
 */

$perPage = 20;
$page    = Http::queryInt('page', 1, 1, 100000);

$sqlList = <<<'SQL'
    SELECT f.public_id,
           f.original_name,
           f.size_bytes,
           f.description,
           f.access_level,
           f.team_id,
           t.name     AS team_name,
           u.username AS uploader,
           d.username AS deleted_by_name,
           f.created_at,
           f.deleted_at
      FROM files f
      JOIN users u ON u.id = f.uploaded_by
      LEFT JOIN users d ON d.id = f.deleted_by
      LEFT JOIN teams t ON t.id = f.team_id
     WHERE f.deleted_at IS NOT NULL
       AND f.purged_at  IS NULL
     ORDER BY f.deleted_at DESC, f.id DESC
     LIMIT :lim OFFSET :off
    SQL;

$sqlCount = <<<'SQL'
    SELECT count(*) AS total
      FROM files f
     WHERE f.deleted_at IS NOT NULL
       AND f.purged_at  IS NULL
    SQL;

$db = Db::instance();

$countRow = $db->run($sqlCount)->fetch();
$total    = is_array($countRow) ? (int) $countRow['total'] : 0;

$rows = $db->run($sqlList, [
    ':lim' => $perPage,
    ':off' => ($page - 1) * $perPage,
])->fetchAll();

$files = [];
foreach ($rows as $row) {
    $files[] = [
        'id'           => (string) $row['public_id'],
        'name'         => (string) $row['original_name'],
        'size_bytes'   => (int) $row['size_bytes'],
        'description'  => (string) $row['description'],
        'access_level' => (string) $row['access_level'],
        'team_id'      => $row['team_id'] === null ? null : (int) $row['team_id'],
        'team_name'    => $row['team_name'] === null ? null : (string) $row['team_name'],
        'uploaded_by'  => (string) $row['uploader'],
        'deleted_by'   => $row['deleted_by_name'] === null ? null : (string) $row['deleted_by_name'],
        'created_at'   => (string) $row['created_at'],
        'deleted_at'   => (string) $row['deleted_at'],
    ];
}

Http::json([
    'files'    => $files,
    'total'    => $total,
    'page'     => $page,
    'per_page' => $perPage,
]);
