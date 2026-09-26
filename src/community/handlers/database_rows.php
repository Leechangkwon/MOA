<?php
declare(strict_types=1);

/**
 * src/community/handlers/database_rows.php — GET /community/api/databases/{id}/rows (정책: community.read)
 *
 * 요구사항: 커뮤니티 PRD F-27 · T-28(필터 인젝션), 지시서 `stage6a-database-directive.md` 「창구」·「거르기·정렬」.
 *
 * 받는 값 (쿼리)
 *   filter  JSON `{"and":[{"prop","op","value"}]}` (≤10, 선택) — `DbProps::compileFilter` 허용 목록
 *   sort    JSON `[{"prop","dir"}]` (≤3, 선택) — `DbProps::compileSort`
 *   page    쪽 번호(1부터, 한 쪽 100행)
 *   view    저장한 뷰 id(6-B, 선택) — 뷰의 거르기에 요청 거르기를 AND 로 더하고, 정렬은 요청이 있으면 요청
 *
 * 관문: **DB 페이지를 볼 수 있어야** 한다(`community_history_gate` — 못 보면 403·404, 감사는 그 관문이).
 * 행은 **목록 조건절 안에서만** 센다 — 못 보는 행은 목록·수·거르기 결과 어디에도 없다(T-29 의 전제).
 * 모르는 거르기·정렬 → 400 `bad_filter`(입력 오류라 감사 없음).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params [0] = DB public_id (UUID)
 */

$dbRow = community_db_row((string) $params[0]);
if ($dbRow === null) {
    Http::error(404, Http::MSG_NOT_FOUND);
}
$gate   = community_history_gate($user, (string) $dbRow['page_public_id']);
$dbPage = $gate['row'];
if ($dbPage['deleted_at'] !== null) {
    Http::error(404, Http::MSG_NOT_FOUND);
}
$schema = community_db_schema_of($dbRow['schema']);

$decode = static function (string $name) {
    $raw = Http::queryText($name, 4000);
    if ($raw === '') {
        return null;
    }
    $v = json_decode($raw, true, 8);
    if (!is_array($v)) {
        Http::json(['error' => '거르기·정렬 모양이 맞지 않습니다', 'reason' => 'bad_filter'], 400);
        exit;
    }
    return $v;
};

$views  = community_db_views_of($dbRow['views'], $schema);
$viewId = Http::queryText('view', 20);
$view   = null;
if ($viewId !== '') {
    foreach ($views as $v) {
        if ($v['id'] === $viewId) {
            $view = $v;
        }
    }
    if ($view === null) {
        Http::json(['error' => '없는 뷰입니다', 'reason' => 'bad_filter'], 400);
        exit;
    }
}
try {
    [$useFilter, $useSort]    = community_db_effective($view, $decode('filter'), $decode('sort'));
    [$filterSql, $filterBind] = DbProps::compileFilter($useFilter, $schema);
    [$orderSql, $orderBind]   = DbProps::compileSort($useSort, $schema);
} catch (InvalidArgumentException $e) {
    Http::json(['error' => '거르기·정렬 모양이 맞지 않습니다', 'reason' => 'bad_filter'], 400);
    exit;
}

$pageNo  = Http::queryInt('page', 1, 1, 100000);
$perPage = 100;
[$visible, $visBind] = community_visible_pages_where($user);

$bind = array_merge([':db' => (int) $dbRow['id']], $visBind, $filterBind);

$sqlCount = <<<SQL
    SELECT count(*) AS n
      FROM community.pages p
      JOIN community.spaces s ON s.id = p.space_id
      LEFT JOIN public.users owner ON owner.id = s.owner_user_id
     WHERE p.database_id = CAST(:db AS bigint)
       AND p.deleted_at IS NULL
       AND {$visible}
       AND {$filterSql}
    SQL;
$countRow = Db::instance()->run($sqlCount, $bind)->fetch();
$total    = is_array($countRow) ? (int) $countRow['n'] : 0;

$sqlList = <<<SQL
    SELECT p.public_id, p.title, p.icon, p.status, p.properties, p.created_at, p.updated_at,
           owner.username AS owner_name, owner.display_name AS owner_display_name
      FROM community.pages p
      JOIN community.spaces s ON s.id = p.space_id
      LEFT JOIN public.users owner ON owner.id = s.owner_user_id
     WHERE p.database_id = CAST(:db AS bigint)
       AND p.deleted_at IS NULL
       AND {$visible}
       AND {$filterSql}
    {$orderSql}
     LIMIT CAST(:lim AS int) OFFSET CAST(:off AS int)
    SQL;
$listBind = array_merge($bind, $orderBind, [':lim' => $perPage, ':off' => ($pageNo - 1) * $perPage]);

$rows = [];
foreach (Db::instance()->run($sqlList, $listBind)->fetchAll() as $r) {
    $rows[] = [
        'id'         => (string) $r['public_id'],
        'title'      => (string) $r['title'],
        'icon'       => $r['icon'] === null ? null : (string) $r['icon'],
        'status'     => $r['status'] === null ? null : (string) $r['status'],
        'owner'      => $r['owner_name'] === null ? null : [
            'username'     => (string) $r['owner_name'],
            'display_name' => $r['owner_display_name'] === null ? null : (string) $r['owner_display_name'],
        ],
        'values'     => community_db_values_out($schema, $r['properties']),
        'created_at' => (string) $r['created_at'],
        'updated_at' => (string) $r['updated_at'],
    ];
}

// 6-B — 관계·파일 값의 제목은 **보는 사람 권한으로** 거른 지도로만(못 보는 행·파일은 지도에 없다)
[$relTitles, $fileTitles] = community_db_ref_titles($schema, array_map(static fn (array $r) => $r['values'], $rows), $user);

// 화면 편의 값 — 행마다 판정하지 않고 DB 페이지의 판정을 보인다(행 값 창구가 행마다 다시 막는다).
Http::json([
    'views'           => $views,
    'view'            => $view === null ? null : $view['id'],
    'relation_titles' => $relTitles === [] ? new stdClass() : $relTitles,
    'file_titles'     => $fileTitles === [] ? new stdClass() : $fileTitles,
    'database' => [
        'id'      => (string) $dbRow['public_id'],
        'page_id' => (string) $dbRow['page_public_id'],
        'schema'  => $schema,
    ],
    'rows'              => $rows,
    'total'             => $total,
    'page'              => $pageNo,
    'per_page'          => $perPage,
    // 디버깅 묶음 S2 — 손님은 쓰기 창구(`community.write`)에 닿지 못하므로 창구 값도 같은 함수와 AND(pages_get.php 와 같다)
    'can_add_rows'      => Authz::canCommunityWrite($user) && CommunityAuthz::decidePageChange($user, $dbPage)[0],
    'can_edit_values'   => Authz::canCommunityWrite($user) && CommunityAuthz::decidePageChange($user, $dbPage)[0],
    'can_manage_schema' => Authz::canCommunityWrite($user) && CommunityAuthz::decidePageManage($user, $dbPage)[0],
]);
