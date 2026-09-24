<?php
declare(strict_types=1);

/**
 * src/handlers/files_trash.php — GET /api/files/trash (정책: authenticated → 여기서 범위 판정)
 *
 * 요구사항: PRD F-11 · 5.4 "삭제된 파일 복구(30일 내)" 각주 ¹, 8장, ARCH 5.1(목록 패턴) · 7.4
 *           Q:F-4 U-2b (2026-09-24) — Admin 은 전체, 그 밖은 「내 휴지통」(본인이 올리고 본인이 지운 파일)
 *
 * 휴지통 = **논리 삭제됐고(`deleted_at IS NOT NULL`) 아직 실제 바이트가 남아 있는
 * (`purged_at IS NULL`) 파일**이다. 30일이 지나 배치(scripts/purge_trash.php)가 바이트를 지운
 * 행은 `purged_at` 이 채워져 있고, 복구해도 내려받을 파일이 없으므로 목록에 담지 않는다 (9단계).
 *
 * 지키는 것
 *   · **누구의 행을 내려 주나는 SQL WHERE 가 정한다** (PRD S-02 — 화면에서 거르지 않는다).
 *     조건은 `Authz::restorableFilesWhere` 한 곳에서 받는다: Admin 이면 `TRUE`(전체), 올릴 수 있는
 *     역할이면 `f.uploaded_by = 나 AND f.deleted_by = 나`, 그 밖(Guest)은 `FALSE`(0건).
 *     이 파일에는 역할 비교가 없다 — 범위 코드(`admin_all`)만 견준다.
 *     휴지통에는 admin-only 파일도 섞여 있으므로 "볼 수 있는 파일만" 조건(visibleFilesWhere)을
 *     쓰지 않는다 — Admin 은 역할 자체로, 그 밖은 **본인이 올리고 본인이 지운 행**으로 좁힌 것이
 *     그 자리를 대신한다(내가 올린 파일의 이름·설명은 이미 내가 적은 값이다).
 *   · 재인증: 범위가 `admin_all`(전체)이면 전과 같이 최근 15분 안의 비밀번호 확인이 필요하다
 *     (PRD F-23 — 라우트 정책이 `authenticated` 가 되어 관문 [3-2] 대신 여기서 같은 모양으로 묻는다).
 *     본인 휴지통(`owner`)은 묻지 않는다 — 본인 자료다(커뮤니티 휴지통과 같다).
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

// ─── [권한 ✔] 복구 범위 — 목록의 WHERE 와 재인증 여부가 이 한 번의 판정에서 나온다 ───
[$scopeWhere, $scopeParams, $scopeCode] = Authz::restorableFilesWhere($user);

// ─── [재인증] 전체 휴지통(Admin)만 — 관문 [3-2] 와 같은 문장·같은 응답 모양 ───
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
       AND
    SQL;

$sqlListTail = <<<'SQL'
     ORDER BY f.deleted_at DESC, f.id DESC
     LIMIT :lim OFFSET :off
    SQL;

$sqlCount = <<<'SQL'
    SELECT count(*) AS total
      FROM files f
     WHERE f.deleted_at IS NOT NULL
       AND f.purged_at  IS NULL
       AND
    SQL;

/*
 * 이어 붙이는 것은 **Authz 가 코드에 적어 둔 조건 조각**(`TRUE` · `FALSE` · 자리표시자 두 개짜리 조각)
 * 뿐이고 요청 값은 한 글자도 없다 — 값(내 id)은 전부 $scopeParams 로 바인딩된다 (PRD S-04,
 * visibleFilesWhere 를 쓰는 files_list.php 와 같은 방식).
 */
$db = Db::instance();

$countRow = $db->run($sqlCount . ' ' . $scopeWhere, $scopeParams)->fetch();
$total    = is_array($countRow) ? (int) $countRow['total'] : 0;

$rows = $db->run($sqlList . ' ' . $scopeWhere . "\n" . $sqlListTail, $scopeParams + [
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
