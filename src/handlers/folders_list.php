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
 * breadcrumb 의 조상들은 **보이는 것만** 싣는다(G-0). 18-1 에는 불변 규칙 ①(하위 등급 ≥ 부모 등급)과 매트릭스 단조로
 * 「읽을 수 있는 폴더의 조상은 언제나 읽을 수 있다」가 성립해 거르지 않았지만, 사람 지정 권한(개인 등급)은 개인 폴더
 * 하나에만 들어온 사람을 만든다 — 그 위 남의 개인 폴더 이름이 새지 않게 `_folder_paths.php` 가 목록 조건절로 거른다.
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
    SELECT id, parent_id, name, depth, access_level, team_id, created_by,
           'folder' AS item_kind   -- G-0 종류 표식(개인 등급 판정 — decideRead [P])
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
           t.name AS team_name,
           -- G-0 — 개인 폴더에 **직접** 준 권한 수(「개인 · 2명」 표시). 개인이 아니면 NULL
           CASE WHEN d.access_level = 'private'
                THEN (SELECT count(*) FROM access_grants g WHERE g.folder_id = d.id) END AS grant_count
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
 * 않는다 — 이 값이 곧 `GET /api/files?folder=` 의 total 이다 (PRD S-02).
 *
 * 규모 D2a A4 — 폴더마다 **상한 101 까지만** 센다(LATERAL … LIMIT 101 · 같은 권한 조건). 응답 `file_count` 는 100 을 넘지 않고
 * 넘으면 `file_count_capped: true`(화면 「100+」 — DESIGN). 세는 폴더는 위에서 고른 **보이는 하위 폴더**뿐이다(옛 질의는 이 층의
 * 모든 폴더를 셌지만 응답에 나가는 것은 보이는 폴더의 수뿐 — 나가는 값은 같다). 번호는 위 질의가 DB 에서 읽은 정수다. */
$sqlFileCounts = <<<'SQL'
    SELECT d.id AS fid, c.n
      FROM public.folders d
      CROSS JOIN LATERAL (
            SELECT count(*) AS n
              FROM (SELECT 1
                      FROM files f
                     WHERE f.folder_id = d.id
                       AND
    SQL;

$sqlFileCountsTail = <<<'SQL'
                     ORDER BY f.created_at DESC, f.id DESC   -- 셈에는 뜻 없음 — 폴더 색인(files_folder_created_idx)을 앞에서부터 101개까지만 읽게
                     LIMIT 101) capped
      ) c
     WHERE d.id = ANY (string_to_array(CAST(:fids AS text), ',')::bigint[])
    SQL;

$fileBind[':fids'] = implode(',', array_map(static fn (array $r): int => (int) $r['id'], $rows));

$counts = [];
if ($rows !== []) {
    /* 규모 D2a A4 — 이 문장만 JIT 없이(같은 트랜잭션 안에서만 — SET LOCAL). 권한 조건절 · 폴더 수만큼의 LATERAL 로 계획기 비용이
     * JIT 문턱을 넘겨 합성 L 에서 컴파일만 48ms 였다(실행 60ms). 결과는 같다 — 서버 설정은 바꾸지 않는다. */
    $db->begin();
    try {
        $db->run('SET LOCAL jit = off');
        $countRows = $db->run($sqlFileCounts . ' ' . $visibleFiles . ' ' . $sqlFileCountsTail, $fileBind)->fetchAll();
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
    foreach ($countRows as $c) {
        $counts[(int) $c['fid']] = (int) $c['n'];
    }
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
        // 규모 D2a A4 — 100 까지만(넘으면 file_count_capped — 화면 「100+」)
        'file_count'   => min($counts[$fid] ?? 0, 100),
        'file_count_capped' => ($counts[$fid] ?? 0) > 100,
        // G-0 — 개인 폴더에 직접 준 권한 수(개인이 아니면 null)
        'grants'       => $row['grant_count'] === null ? null : (int) $row['grant_count'],
    ];
}

// ─── breadcrumb (루트 → 현재) ─────────────────────────────────
// G-0 — 조상은 **보이는 것만**(`_folder_paths.php` — 목록 조건절 그대로). 사람 지정 권한으로 개인 폴더 하나에만 들어온
// 사람에게 그 위 남의 개인 폴더 이름이 새지 않게. 지금 연 폴더 자신은 언제나 넣는다(위 [권한 ✔ 1] 을 지났다).
require_once __DIR__ . '/_folder_paths.php';
$breadcrumb = [];
$current    = null;
if ($parent !== 0) {
    $breadcrumb = tbox_folder_breadcrumb(tbox_folder_map($user), $parent);
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
