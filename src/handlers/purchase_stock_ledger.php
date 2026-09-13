<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_stock_ledger.php — GET /api/purchase/ledger  (정책: purchase.read)
 *
 * ★ 21단계 **수불부** (PRD F-34, 시안 ⑩ Status 「수불부」).
 *
 *   ?location=  장소        ?item=  품목        ?from=&to=  기간(YYYY-MM-DD)
 *   ?page=      1부터
 *
 * 원장(`p_stock_moves`) 한 행이 한 줄이다 — 언제·어느 전표가·얼마나 늘리거나 줄였나.
 * 「현재고가 왜 그 값인가」 를 되짚는 화면이므로 **값을 가공하지 않는다**:
 * 장소·품목을 둘 다 고르면 누적 잔액(`balance`)을 시간순으로 함께 준다.
 *
 * 취소 행은 `reverses_move_id` 가 채워져 있고 부호가 반대다 — 지운 흔적이 아니라
 * **되돌린 기록**으로 보인다 (ARCH 5.18 「원장」).
 *
 * 지점 격리 (SECURITY R-65)
 *   `Authz::purchaseScopeWhere($user, 'l')` 를 **장소**에 건다. 원장 행은 장소에 매달려
 *   있으므로, 다른 지점 장소의 행은 애초에 SELECT 에 들어오지 않는다.
 *
 * 조회는 감사 로그를 남기지 않는다.
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params 경로 변수 없음
 */

require_once __DIR__ . '/../purchase/Stock.php';

const TBOX_LEDGER_PER_PAGE = 100;

$db = Db::instance();

$locationId = Http::queryInt('location', 0, 1, 999999999);
$itemId     = Http::queryInt('item', 0, 1, 999999999);
$page       = Http::queryInt('page', 1, 1, 500);
$from       = Http::queryText('from', 10);
$to         = Http::queryText('to', 10);

$scopeL = Authz::purchaseScopeWhere($user, 'l');

$where = [$scopeL['sql']];
$bind  = $scopeL['params'];

if ($locationId > 0) {
    $where[]      = 'm.location_id = CAST(:loc AS bigint)';
    $bind[':loc'] = $locationId;
}
if ($itemId > 0) {
    $where[]       = 'm.item_id = CAST(:item AS bigint)';
    $bind[':item'] = $itemId;
}
if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $from) === 1) {
    $where[]       = 'm.moved_at >= CAST(:from AS date)';
    $bind[':from'] = $from;
}
if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $to) === 1) {
    $where[]     = 'm.moved_at < CAST(:to AS date) + interval \'1 day\'';
    $bind[':to'] = $to;
}

$sqlFrom = <<<'SQL'
      FROM p_stock_moves m
      JOIN p_locations l ON l.id = m.location_id
      JOIN p_items     i ON i.id = m.item_id
      JOIN p_doc_lines dl ON dl.id = m.doc_line_id
      JOIN p_docs      d  ON d.id = dl.doc_id
      LEFT JOIN users  u  ON u.id = m.created_by
     WHERE
    SQL;

/** 건수 머리 — 값이 한 글자도 없는 고정 문자열이다 (PRD S-04). */
$sqlCountHead = <<<'SQL'
    SELECT count(*) AS n
    SQL;

$countRow = $db->run(
    $sqlCountHead . ' ' . $sqlFrom . ' ' . implode(' AND ', $where),
    $bind
)->fetch();
$total = is_array($countRow) ? (int) $countRow['n'] : 0;

$bind[':lim'] = TBOX_LEDGER_PER_PAGE;
$bind[':off'] = ($page - 1) * TBOX_LEDGER_PER_PAGE;

$sqlSelect = <<<'SQL'
    SELECT m.id, CAST(m.moved_at AS text) AS moved_at,
           CAST(m.qty_delta AS text) AS qty_delta, m.reverses_move_id,
           m.location_id, l.name AS location_name, l.branch_id,
           m.item_id, i.code, i.name AS item_name, i.unit,
           d.id AS doc_id, d.doc_no, d.doc_type, CAST(d.doc_date AS text) AS doc_date,
           d.status AS doc_status, dl.lot_no, CAST(dl.expiry_date AS text) AS expiry_date,
           u.username AS actor_name
    SQL;

$rows = $db->run(
    $sqlSelect . ' ' . $sqlFrom . ' ' . implode(' AND ', $where)
        . ' ORDER BY m.moved_at ASC, m.id ASC LIMIT :lim OFFSET :off',
    $bind
)->fetchAll();

/**
 * 누적 잔액은 **장소·품목을 둘 다 고른 때만** 뜻이 있다. 섞인 목록에 누적을 붙이면
 * 서로 다른 품목의 합이 되어 거짓말이 된다 — 그럴 때는 `null` 로 둔다.
 */
$running   = null;
$hasCursor = $locationId > 0 && $itemId > 0;
if ($hasCursor) {
    // 이 페이지의 **첫 행 앞까지**의 잔액을 한 번에 센다 — 페이지가 넘어가도 누적이 이어진다.
    // 원장은 추가만 되는 표라 id 순서가 곧 시간 순서다.
    $before = $db->run(
        'SELECT COALESCE(sum(qty_delta), 0)::text AS q FROM p_stock_moves'
            . ' WHERE location_id = CAST(:loc AS bigint) AND item_id = CAST(:item AS bigint)'
            . ' AND id < CAST(:first AS bigint)',
        [
            ':loc'   => $locationId,
            ':item'  => $itemId,
            ':first' => $rows === [] ? 0 : (int) $rows[0]['id'],
        ]
    )->fetch();
    $running = is_array($before) ? Doc::milli((string) $before['q']) : 0;
}

$out = [];
foreach ($rows as $row) {
    $delta = Doc::milli((string) $row['qty_delta']);
    if ($running !== null) {
        $running += $delta;
    }
    $out[] = [
        'id'            => (int) $row['id'],
        'moved_at'      => (string) $row['moved_at'],
        'qty_delta'     => (string) $row['qty_delta'],
        'balance'       => $running === null ? null : Doc::qtyText($running),
        'reverses'      => $row['reverses_move_id'] === null ? null : (int) $row['reverses_move_id'],
        'location_id'   => (int) $row['location_id'],
        'location_name' => (string) $row['location_name'],
        'branch_id'     => (int) $row['branch_id'],
        'item_id'       => (int) $row['item_id'],
        'code'          => (string) $row['code'],
        'name'          => (string) $row['item_name'],
        'unit'          => $row['unit'] === null ? null : (string) $row['unit'],
        'doc_id'        => (int) $row['doc_id'],
        'doc_no'        => (string) $row['doc_no'],
        'doc_type'      => (string) $row['doc_type'],
        'doc_date'      => (string) $row['doc_date'],
        'doc_status'    => (string) $row['doc_status'],
        'lot_no'        => $row['lot_no'] === null ? null : (string) $row['lot_no'],
        'expiry_date'   => $row['expiry_date'] === null ? null : (string) $row['expiry_date'],
        'actor_name'    => $row['actor_name'] === null ? null : (string) $row['actor_name'],
    ];
}

Http::json([
    'rows'     => $out,
    'total'    => $total,
    'page'     => $page,
    'per_page' => TBOX_LEDGER_PER_PAGE,
    'running'  => $hasCursor,
]);
