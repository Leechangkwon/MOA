<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_mv_suggest.php — GET /api/purchase/mv/suggest  (정책: purchase.stock.write)
 *
 * ★ 21단계 **「중앙장 부족분 자동 채우기」 제안** (PRD F-33, 시안 ⑦ MoveUse).
 *
 *   ?to=<받는 장소 id>     필수 — 채울 곳(보통 중앙장)
 *   ?from=<보내는 장소 id> 선택 — 꺼낼 곳(보통 지점 창고). 없으면 그 지점의 창고를 쓴다
 *
 * 제안 수량 = **받는 곳 인가량 − 받는 곳 현재고**, 단 **보내는 곳 현재고를 넘지 않는다**.
 * 인가량은 오늘 유효한 마지막 것(`effective_from <= CURRENT_DATE` 중 가장 최근)이다.
 *
 * ★ 이것은 **제안일 뿐**이다. 화면에서 수량을 고칠 수 있고, 확정은 일반 이동과 똑같이
 *   `POST /api/purchase/mv` 를 지난다 — 거기서 잠금·음수 검사를 다시 받는다.
 *   제안 API 가 재고를 움직이는 일은 없다(GET 이고, 원장 INSERT 가 없다).
 *
 * 지점 격리 (SECURITY R-65): 두 장소 모두 `Authz::purchaseScopeWhere($user,'l')` 를 지난다.
 * 조회는 감사 로그를 남기지 않는다.
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params 경로 변수 없음
 */

require_once __DIR__ . '/../purchase/Stock.php';

/** 한 번에 제안하는 줄 수 상한 */
const TBOX_MV_SUGGEST_MAX = 200;

$db = Db::instance();

$toId   = Http::queryInt('to', 0, 1, 999999999);
$fromId = Http::queryInt('from', 0, 1, 999999999);
if ($toId === 0) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}

$scopeL = Authz::purchaseScopeWhere($user, 'l');

$toLoc = Stock::location($db, $scopeL, $toId);
if ($toLoc === null) {
    Http::error(404, Http::MSG_NOT_FOUND);
}

if ($fromId === 0) {
    // 지정이 없으면 그 지점의 **창고**를 기본으로 본다 (p_branches.warehouse_location_id).
    $row = $db->run(
        'SELECT warehouse_location_id FROM p_branches WHERE id = CAST(:b AS bigint)',
        [':b' => (int) $toLoc['branch_id']]
    )->fetch();
    if (is_array($row) && $row['warehouse_location_id'] !== null) {
        $fromId = (int) $row['warehouse_location_id'];
    }
}
if ($fromId === 0 || $fromId === $toId) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}

$fromLoc = Stock::location($db, $scopeL, $fromId);
if ($fromLoc === null) {
    Http::error(404, Http::MSG_NOT_FOUND);
}

/**
 * 받는 곳의 인가량이 잡힌 품목 가운데 **모자란 것**만.
 * `LEAST(모자란 양, 보내는 곳 현재고)` 가 제안값이고, 0 이하는 제안하지 않는다.
 */
$sql = <<<'SQL'
    WITH par AS (
        SELECT DISTINCT ON (p.item_id) p.item_id, p.qty
          FROM p_par_levels p
         WHERE p.location_id = CAST(:to AS bigint) AND p.is_active
           AND p.effective_from <= CURRENT_DATE
         ORDER BY p.item_id, p.effective_from DESC, p.id DESC
    )
    SELECT i.id AS item_id, i.code, i.name, i.spec, i.unit,
           par.qty AS par_qty,
           CAST(COALESCE(tb.qty, 0) AS text) AS to_qty,
           CAST(COALESCE(fb.qty, 0) AS text) AS from_qty,
           CAST(LEAST(par.qty - COALESCE(tb.qty, 0), COALESCE(fb.qty, 0)) AS text) AS suggest_qty
      FROM par
      JOIN p_items i ON i.id = par.item_id
      LEFT JOIN p_stock_balance tb ON tb.location_id = CAST(:to AS bigint)   AND tb.item_id = i.id
      LEFT JOIN p_stock_balance fb ON fb.location_id = CAST(:from AS bigint) AND fb.item_id = i.id
     WHERE i.is_active AND i.stock_managed
       AND par.qty - COALESCE(tb.qty, 0) > 0
       AND COALESCE(fb.qty, 0) > 0
     ORDER BY i.name ASC, i.id ASC
     LIMIT :lim
    SQL;

$rows = $db->run($sql, [
    ':to'   => $toId,
    ':from' => $fromId,
    ':lim'  => TBOX_MV_SUGGEST_MAX,
])->fetchAll();

$out = [];
foreach ($rows as $row) {
    $out[] = [
        'item_id'     => (int) $row['item_id'],
        'code'        => (string) $row['code'],
        'name'        => (string) $row['name'],
        'spec'        => $row['spec'] === null ? null : (string) $row['spec'],
        'unit'        => $row['unit'] === null ? null : (string) $row['unit'],
        'par_qty'     => (int) $row['par_qty'],
        'to_qty'      => (string) $row['to_qty'],
        'from_qty'    => (string) $row['from_qty'],
        'suggest_qty' => (string) $row['suggest_qty'],
    ];
}

Http::json([
    'from_location_id'   => $fromId,
    'from_location_name' => (string) $fromLoc['name'],
    'to_location_id'     => $toId,
    'to_location_name'   => (string) $toLoc['name'],
    'items'              => $out,
    'total'              => count($out),
    'truncated'          => count($out) >= TBOX_MV_SUGGEST_MAX,
]);
