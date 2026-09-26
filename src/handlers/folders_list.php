<?php
declare(strict_types=1);

/**
 * src/handlers/folders_list.php — GET /api/folders?parent=<id> (정책: file.read)
 *
 * ★ 18-1 폴더 트리 (PRD F-26, 8장 `GET /api/folders` 행, ARCH 5.16).
 *
 * **폴더도 권한을 갖는다**(사용자 결정 2026-09-13). 그래서 이 창구는 두 가지를 한다:
 *   [권한 ✔ 1] 펼치려는 **부모 폴더 자체**를 읽을 수 있나 — `Authz::decideRead`(파일과 **같은
 *              함수**다. 폴더 행에도 access_level·team_id 가 있으므로 같은 코드가 판정한다).
 *              못 읽으면 403 동일 문구 + `folder.read deny`. 루트(parent=0)는 검사 대상이
 *              아니다 — 루트는 행이 아니라 "부모가 없음" 이라는 상태이고, 그 안에서 보이는
 *              것은 아래 [권한 ✔ 2] 가 이미 거른다.
 *   [권한 ✔ 2] 자식 폴더는 **조건절**(SQL 의 걸러내는 자리)에서 거른다 —
 *              `Authz::visibleWhere($user, 'd')` 가 그 조건을 돌려준다.
 *              못 읽는 폴더는 응답에서 지우는 것이 아니라 **DB 가 애초에 돌려주지 않는다**
 *              (PRD S-02). 목록(5.1)과 같은 조건을 같은 함수에서 얻어 쓰므로 파일과 폴더의
 *              판정이 어긋날 수 없다.
 *
 * `file_count` 는 "이 폴더 안에서 **호출자가 볼 수 있는** 파일 수" 다 — 세는 SQL 에도
 * `visibleFilesWhere` 를 붙인다. `GET /api/files?folder=` 의 `total` 과 같은 값이다.
 * `child_count` 도 같다 — "**호출자가 볼 수 있는** 하위 폴더 수"(디버깅 묶음 S-7, 2026-09-26 보안 점검 낮음 11).
 * 전에는 하위를 조건 없이 세어, 못 보는 admin-only 하위 폴더가 있다는 사실(과 개수)이 숫자로 새었다.
 * 지금은 그 층을 펼쳤을 때(`?parent=<id>`) 돌아오는 폴더 수와 같다.
 *
 * breadcrumb 의 조상들은 따로 검사하지 않는다: 불변 규칙 ①(하위 등급 ≥ 부모 등급)이 지켜지는
 * 한 **읽을 수 있는 폴더의 조상은 언제나 읽을 수 있다**(조상은 자기보다 넓거나 같은 등급이고,
 * 매트릭스는 등급 순서에 단조다 — ARCH 5.16, F-25 불변 규칙 (f)).
 *
 * 한 층씩만 받는다(전체 트리를 한 번에 내리지 않는다) — 폴더 개수에 상한이 없어 깊은 트리를
 * 통째로 내리면 응답이 얼마든지 커질 수 있기 때문이다.
 *
 * 조회 **허용**은 감사 로그에 남기지 않는다(양이 많은 목록 조회, ARCH 5.1 마지막 줄).
 * 못 읽는 폴더를 열어 보려 한 **거부**는 남긴다 (PRD 8장 이 행의 `folder.read` deny 만).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자 (요청 파라미터가 아니다)
 * @var list<string>        $params 경로 변수 없음
 */

// 0 = 루트. 형태가 아니거나 범위를 벗어나면 0(루트)으로 좁힌다 — 거부하지 않는다 (ARCH 5.1 [2]).
$parent = Http::queryInt('parent', 0, 0, 999999999);

$db = Db::instance();

$sqlFolder = <<<'SQL'
    SELECT id, parent_id, name, depth, access_level, team_id, created_by
      FROM folders
     WHERE id = CAST(:id AS bigint)
    SQL;

// ─── [권한 ✔ 1] 펼치려는 폴더를 읽을 수 있나 ───────────────────
// 없는 폴더와 못 읽는 폴더는 **같은 403 동일 문구**로 끝낸다 — 폴더 id 는 순차 정수라
// 404/403 이 갈리면 "몇 번까지 폴더가 있나" 를 세어 볼 수 있는 신호가 된다 (PRD S-09-5).
// 사유(not_found / role_not_permitted / team_mismatch)는 감사 로그에만 남는다.
if ($parent !== 0) {
    $row = $db->run($sqlFolder, [':id' => $parent])->fetch();
    if (!is_array($row)) {
        Audit::deny('folder.read', 'not_found', ['target_type' => 'folder']);
        Http::error(403, Http::MSG_FORBIDDEN);
    }
    [$mayRead, $readCode] = Authz::decideRead($user, $row);
    if (!$mayRead) {
        Audit::deny('folder.read', $readCode, [
            'target_type'  => 'folder',
            'target_id'    => (int) $row['id'],
            'target_name'  => (string) $row['name'],
            'access_level' => (string) $row['access_level'],
            'detail'       => [
                'folder_id'   => (int) $row['id'],
                'parent_id'   => $row['parent_id'] === null ? null : (int) $row['parent_id'],
                'target_team' => $row['team_id'] === null ? null : (int) $row['team_id'],
            ],
        ]);
        Http::error(403, Http::MSG_FORBIDDEN);
    }
}

// ─── 권한 조건 두 벌 (PRD S-02) ───────────────────────────────
// 같은 함수에서 별칭만 달리 받는다 — 조건 문자열을 이 파일에 복사하지 않는다 (ARCH 5.16).
[$visibleFolders, $folderBind] = Authz::visibleWhere($user, 'd');
[$visibleFiles,   $fileBind]   = Authz::visibleWhere($user, 'f');

/* 바인딩 이름이 겹치므로(둘 다 :lvl0.. · :own_team) 한 문장 안에 두 조건을 함께 쓸 수 없다.
 * 그래서 자식 목록과 파일 수를 **두 문장**으로 나눈다 — 이름을 바꿔 붙이려면 조건 문자열을
 * 손대야 하고, 그 순간 판정이 이 파일로 새어 나온다. */
$sqlChildren = <<<'SQL'
    SELECT d.id,
           d.name,
           d.depth,
           d.access_level,
           d.team_id,
           t.name AS team_name
      FROM folders d
      LEFT JOIN teams t ON t.id = d.team_id
     WHERE d.parent_id IS NOT DISTINCT FROM CAST(:parent AS bigint)
       AND
    SQL;

$sqlChildrenTail = <<<'SQL'
     ORDER BY d.name ASC, d.id ASC
     LIMIT 500
    SQL;

$folderBind[':parent'] = $parent === 0 ? null : $parent;

$rows = $db->run(
    $sqlChildren . ' ' . $visibleFolders . ' ' . $sqlChildrenTail,
    $folderBind
)->fetchAll();

/* 이 층 폴더들 안의 **볼 수 있는** 파일 수를 한 문장으로 센다(폴더마다 따로 묻지 않는다).
 * 조건은 목록(5.1)과 같은 `visibleWhere($user, 'f')` 이므로, 안 보이는 파일은 애초에 세지
 * 않는다 — 이 값이 곧 `GET /api/files?folder=` 의 total 이다 (PRD S-02). */
$sqlFileCounts = <<<'SQL'
    SELECT f.folder_id AS fid, count(*) AS n
      FROM files f
      JOIN folders d ON d.id = f.folder_id
     WHERE d.parent_id IS NOT DISTINCT FROM CAST(:parent AS bigint)
       AND
    SQL;

$sqlFileCountsTail = <<<'SQL'
     GROUP BY f.folder_id
    SQL;

$fileBind[':parent'] = $parent === 0 ? null : $parent;

$counts = [];
foreach ($db->run($sqlFileCounts . ' ' . $visibleFiles . ' ' . $sqlFileCountsTail, $fileBind)->fetchAll() as $c) {
    $counts[(int) $c['fid']] = (int) $c['n'];
}

/* S-7 — 이 층 폴더들의 **볼 수 있는** 하위 폴더 수를 한 문장으로 센다(폴더마다 따로 묻지 않는다).
 * 세는 쪽(하위)이 별칭 `d` 이고, 이 층의 폴더가 `p` 다 — 조건은 트리 목록과 **같은 함수**
 * `visibleWhere($user, 'd')` 에서 새로 받는다(바인딩 이름이 겹치므로 문장·바인딩을 따로 둔다 — 위 두 문장과 같은 이유).
 * 그래서 이 값은 그 폴더를 펼쳤을 때 돌아오는 폴더 수와 같다(상한 500 은 펼칠 때만). */
[$visibleChildren, $childBind] = Authz::visibleWhere($user, 'd');

$sqlChildCounts = <<<'SQL'
    SELECT d.parent_id AS pid, count(*) AS n
      FROM folders d
      JOIN folders p ON p.id = d.parent_id
     WHERE p.parent_id IS NOT DISTINCT FROM CAST(:parent AS bigint)
       AND
    SQL;

$sqlChildCountsTail = <<<'SQL'
     GROUP BY d.parent_id
    SQL;

$childBind[':parent'] = $parent === 0 ? null : $parent;

$childCounts = [];
foreach ($db->run($sqlChildCounts . ' ' . $visibleChildren . ' ' . $sqlChildCountsTail, $childBind)->fetchAll() as $c) {
    $childCounts[(int) $c['pid']] = (int) $c['n'];
}

$folders = [];
foreach ($rows as $row) {
    $fid = (int) $row['id'];
    $folders[] = [
        'id'           => $fid,
        'name'         => (string) $row['name'],
        'depth'        => (int) $row['depth'],
        'access_level' => (string) $row['access_level'],
        'team_id'      => $row['team_id'] === null ? null : (int) $row['team_id'],
        'team_name'    => $row['team_name'] === null ? null : (string) $row['team_name'],
        // 호출자가 볼 수 있는 하위 폴더 수 (S-7). 안 보이는 하위는 세지지 않는다 (PRD S-02).
        'child_count'  => $childCounts[$fid] ?? 0,
        // 호출자가 볼 수 있는 파일 수. 안 보이는 파일은 여기 세지지 않는다 (PRD S-02).
        'file_count'   => $counts[$fid] ?? 0,
    ];
}

// ─── breadcrumb (루트 → 현재) ─────────────────────────────────
// 재귀 CTE 로 부모를 따라 올라간 뒤 depth 오름차순으로 뒤집는다. hops 상한은 데이터가 어떤
// 이유로든 순환할 때 무한 재귀에 빠지지 않게 하는 안전장치다(깊이는 최대 5).
$sqlBreadcrumb = <<<'SQL'
    WITH RECURSIVE up AS (
        SELECT b.id, b.parent_id, b.name, b.depth, b.access_level, 1 AS hops
          FROM folders b
         WHERE b.id = CAST(:id AS bigint)
        UNION ALL
        SELECT p.id, p.parent_id, p.name, p.depth, p.access_level, up.hops + 1
          FROM folders p
          JOIN up ON p.id = up.parent_id
         WHERE up.hops < 10
    )
    SELECT up.id, up.name, up.access_level FROM up ORDER BY up.depth ASC
    SQL;

$breadcrumb = [];
$current    = null;
if ($parent !== 0) {
    foreach ($db->run($sqlBreadcrumb, [':id' => $parent])->fetchAll() as $row) {
        $breadcrumb[] = [
            'id'           => (int) $row['id'],
            'name'         => (string) $row['name'],
            'access_level' => (string) $row['access_level'],
        ];
    }
    if ($breadcrumb !== []) {
        $current = $breadcrumb[count($breadcrumb) - 1];
    }
}

Http::json([
    // 지금 펼친 위치. 루트는 id 0 · 이름 없음 · breadcrumb 빈 배열이다.
    'folder'  => [
        'id'           => $parent,
        'name'         => $current === null ? null : $current['name'],
        'access_level' => $current === null ? null : $current['access_level'],
        'breadcrumb'   => $breadcrumb,
    ],
    'folders' => $folders,
]);
