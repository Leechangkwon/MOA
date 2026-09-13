<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_adj_start.php — GET /api/purchase/adj/start  (정책: purchase.stock.write)
 *
 * ★ 21단계 **실사 시작 — 장부 스냅샷** (PRD F-33 · Q-18③, 시안 ⑦ MoveUse 「실사」 탭).
 *
 *   ?location=<장소 id>   필수
 *   ?cycle=daily|weekly|monthly   주기(화면 표시용. 확정 때 다시 보낸다)
 *   ?q=                   품목 검색
 *
 * 그 장소의 **지금 장부 수량**(원장의 합)과 인가량을 품목별로 돌려준다. 화면은 이 값을
 * 「장부」 칸에 채우고 사람은 「실제」 칸만 적는다. 확정(`POST /api/purchase/adj`)에서
 * 서버가 스냅샷을 **다시 세어** 그 사이에 재고가 바뀌었으면 400 `stale_snapshot` 이다 —
 * 남이 옮긴 물건을 「차이」로 오해해 원장에 적는 일을 막는다.
 *
 * 목록에 넣는 품목: ① 그 장소에 재고 행이 있는 품목 ② 그 장소에 인가량이 잡힌 품목.
 * 재고관리를 하지 않는 품목(`stock_managed = false`)은 셀 재고가 없으므로 빠진다.
 *
 * 지점 격리 (SECURITY R-65): 장소가 `Authz::purchaseScopeWhere($user,'l')` 를 지난다.
 * 조회는 감사 로그를 남기지 않는다.
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params 경로 변수 없음
 */

require_once __DIR__ . '/../purchase/Stock.php';

$db = Db::instance();

$locationId = Http::queryInt('location', 0, 1, 999999999);
$cycle      = Http::queryChoice('cycle', Stock::CYCLES, 'daily');
$q          = Http::queryText('q', 100);

if ($locationId === 0) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}

$scopeL = Authz::purchaseScopeWhere($user, 'l');
$loc    = Stock::location($db, $scopeL, $locationId);
if ($loc === null) {
    // 범위 밖 장소는 **없는 것과 같게** 답한다 (id 열거 방지 — 20단계 전표 상세와 같은 원칙).
    Http::error(404, Http::MSG_NOT_FOUND);
}

$where = ['i.is_active', 'i.stock_managed'];
$bind  = [':loc' => $locationId, ':lim' => Stock::MAX_SNAPSHOT];
if ($q !== '') {
    $bind[':q'] = '%' . str_replace(['#', '%', '_'], ['##', '#%', '#_'], $q) . '%';
    $where[]    = "(i.name ILIKE :q ESCAPE '#' OR i.code ILIKE :q ESCAPE '#')";
}

/**
 * 이 장소에서 셀 것 = 재고가 있는 품목 ∪ 인가량이 잡힌 품목.
 * 인가량은 **오늘 유효한 마지막 것**(`effective_from <= CURRENT_DATE` 중 가장 최근)이다.
 */
$sql = <<<'SQL'
    WITH keys AS (
        SELECT b.item_id FROM p_stock_balance b WHERE b.location_id = CAST(:loc AS bigint)
        UNION
        SELECT p.item_id FROM p_par_levels p
         WHERE p.location_id = CAST(:loc AS bigint) AND p.is_active
           AND p.effective_from <= CURRENT_DATE
    ),
    par AS (
        SELECT DISTINCT ON (p.item_id) p.item_id, p.qty
          FROM p_par_levels p
         WHERE p.location_id = CAST(:loc AS bigint) AND p.is_active
           AND p.effective_from <= CURRENT_DATE
         ORDER BY p.item_id, p.effective_from DESC, p.id DESC
    )
    SELECT i.id AS item_id, i.code, i.name, i.spec, i.unit,
           CAST(COALESCE(b.qty, 0) AS text) AS book_qty,
           par.qty AS par_qty,
           CAST((SELECT max(m.moved_at) FROM p_stock_moves m
                  WHERE m.location_id = CAST(:loc AS bigint) AND m.item_id = i.id) AS text)
             AS last_moved_at
      FROM keys k
      JOIN p_items i ON i.id = k.item_id
      LEFT JOIN p_stock_balance b ON b.location_id = CAST(:loc AS bigint) AND b.item_id = i.id
      LEFT JOIN par ON par.item_id = i.id
     WHERE
    SQL;

$rows = $db->run(
    $sql . ' ' . implode(' AND ', $where) . ' ORDER BY i.name ASC, i.id ASC LIMIT :lim',
    $bind
)->fetchAll();

$out = [];
foreach ($rows as $row) {
    $out[] = [
        'item_id'       => (int) $row['item_id'],
        'code'          => (string) $row['code'],
        'name'          => (string) $row['name'],
        'spec'          => $row['spec'] === null ? null : (string) $row['spec'],
        'unit'          => $row['unit'] === null ? null : (string) $row['unit'],
        // 장부 수량 — 확정 때 이 값을 그대로 돌려보내면 서버가 다시 세어 견준다.
        'book_qty'      => (string) $row['book_qty'],
        'par_qty'       => $row['par_qty'] === null ? null : (int) $row['par_qty'],
        'last_moved_at' => $row['last_moved_at'] === null ? null : (string) $row['last_moved_at'],
    ];
}

Http::json([
    'location_id'   => $locationId,
    'location_name' => (string) $loc['name'],
    'branch_id'     => (int) $loc['branch_id'],
    'count_cycle'   => $cycle,
    'items'         => $out,
    'total'         => count($out),
    'truncated'     => count($out) >= Stock::MAX_SNAPSHOT,
]);
