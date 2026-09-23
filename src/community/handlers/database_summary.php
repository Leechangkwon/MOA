<?php
declare(strict_types=1);

/**
 * src/community/handlers/database_summary.php — GET /community/api/databases/{id}/summary (정책: community.read)
 *
 * 요구사항: 커뮤니티 PRD F-27(고정 계산 4종) · T-29, 지시서 `stage6b-database-views-directive.md` 「계산」 · 결정 H-3.
 *
 * 받는 값: `view`·`filter` — 행 목록 창구와 같다. 돌려주는 것은 **수**뿐이다(행 제목·값 목록 없음):
 *   전체 행 수 · 상태 「완료」 수(진행률) · 속성마다 채운 수 · 숫자 합계·평균 · 체크 수 · 날짜의 가장 가까운 앞날(D-day 는 화면이 센다).
 * ★ 행 목록과 **같은 조건절**(목록 조건절 + 거르기) 안에서만 센다 — 못 보는 행의 값이 합계로 새지 않는다(T-29).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params [0] = DB public_id (UUID)
 */

$dbRow = community_db_row((string) $params[0]);
if ($dbRow === null) {
    Http::error(404, Http::MSG_NOT_FOUND);
}
$gate = community_history_gate($user, (string) $dbRow['page_public_id']);
if ($gate['row']['deleted_at'] !== null) {
    Http::error(404, Http::MSG_NOT_FOUND);
}
$schema = community_db_schema_of($dbRow['schema']);
$views  = community_db_views_of($dbRow['views'], $schema);
$viewId = Http::queryText('view', 20);
$view   = null;
foreach ($views as $v) {
    if ($viewId !== '' && $v['id'] === $viewId) {
        $view = $v;
    }
}
if ($viewId !== '' && $view === null) {
    Http::json(['error' => '없는 뷰입니다', 'reason' => 'bad_filter'], 400);
    exit;
}
$rawFilter = Http::queryText('filter', 4000);
$reqFilter = $rawFilter === '' ? null : json_decode($rawFilter, true, 8);
if ($rawFilter !== '' && !is_array($reqFilter)) {
    Http::json(['error' => '거르기 모양이 맞지 않습니다', 'reason' => 'bad_filter'], 400);
    exit;
}
try {
    [$useFilter] = community_db_effective($view, $reqFilter, null);
    [$filterSql, $filterBind] = DbProps::compileFilter($useFilter, $schema);
} catch (InvalidArgumentException $e) {
    Http::json(['error' => '거르기 모양이 맞지 않습니다', 'reason' => 'bad_filter'], 400);
    exit;
}
[$aggList, $aggBind, $meta] = DbProps::compileSummary($schema);
[$visible, $visBind] = community_visible_pages_where($user);

$sql = <<<SQL
    SELECT
           count(*) AS total,
           count(*) FILTER (WHERE p.status = 'done') AS done
           {$aggList}
      FROM community.pages p
      JOIN community.spaces s ON s.id = p.space_id
      LEFT JOIN public.users owner ON owner.id = s.owner_user_id
     WHERE p.database_id = CAST(:db AS bigint)
       AND p.deleted_at IS NULL
       AND {$visible}
       AND {$filterSql}
    SQL;
$bind = array_merge([':db' => (int) $dbRow['id'], ':today' => date('Y-m-d')], $visBind, $filterBind, $aggBind);
if (strpos($aggList, ':today') === false) {
    unset($bind[':today']);
}
$r = Db::instance()->run($sql, $bind)->fetch();

$props = [];
foreach ($meta as $m) {
    $one = [];
    foreach ($m['kinds'] as $kind) {
        $v = $r[$m['alias'] . '_' . $kind] ?? null;
        $one[$kind] = $v === null ? null : ($kind === 'next' ? (string) $v : ($kind === 'avg' || $kind === 'sum' ? (float) $v : (int) $v));
    }
    $props[$m['key']] = $one;
}

Http::json([
    'total' => (int) ($r['total'] ?? 0),
    'done'  => (int) ($r['done'] ?? 0),
    'today' => date('Y-m-d'),
    'props' => $props === [] ? new stdClass() : $props,
]);
