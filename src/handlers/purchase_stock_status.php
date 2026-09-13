<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_stock_status.php — GET /api/purchase/stock  (정책: purchase.read)
 *
 * ★ 21단계 **장소별 재고 현황** (PRD F-34, 시안 ⑩ Status).
 *
 *   ?location=  한 장소만        ?branch=  한 지점만
 *   ?cat1=      대분류            ?q=       품목 검색
 *   ?short=1    과부족이 음수인 것만 (시안의 「부족만」)
 *   ?page=      1부터
 *
 * 한 행 = **(장소 × 품목)** 이고 칸은 전부 서버 계산값이다:
 *   현재고    원장의 합 (뷰 `p_stock_balance`) — 컬럼이 아니다 (ARCH 5.18 불변 규칙 2)
 *   인가량    `p_par_levels` 중 오늘 유효한 마지막 것
 *   과부족    현재고 − 인가량
 *   미입고    뷰 `p_backorder` 에서 이 장소로 올 잔량의 합
 *   주평균 사용  최근 4주(28일) 사용량 ÷ 4. 사용량은 **사용(IS) 전표의 음수 행**이고,
 *              재고관리를 하지 않는 품목만 **입고(GR) 의 양수 행**을 사용량으로 본다
 *              (그 품목은 IS 전표를 쓰지 않는다 — ARCH 5.18 「재고관리 안 하는 품목」)
 *   발주 제안  **23단계 값이다. 오늘은 `null`** — 0 을 지어내지 않는다
 *
 * 지점 격리 (SECURITY R-65)
 *   `Authz::purchaseScopeWhere($user, 'l')` 를 **장소**(`p_locations`)에 건다. 재고는 장소에
 *   붙어 있으므로 장소를 좁히면 재고도 좁는다. `?branch=` 에 남의 지점을 적어도 조건은
 *   바뀌지 않는다 — 그 값은 **내 범위 안에서 더 좁히는** 데만 쓰인다 (PRD S-01, T-42).
 *
 * 조회는 감사 로그를 남기지 않는다.
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params 경로 변수 없음
 */

require_once __DIR__ . '/../purchase/Stock.php';

const TBOX_STOCK_PER_PAGE = 50;

$db = Db::instance();

$locationId = Http::queryInt('location', 0, 1, 999999999);
$branchId   = Http::queryInt('branch', 0, 1, 999999999);
$cat1       = Http::queryInt('cat1', 0, 1, 999999999);
$page       = Http::queryInt('page', 1, 1, 500);
$short      = Http::queryChoice('short', ['0', '1'], '0') === '1';
$q          = Http::queryText('q', 100);

$scopeL = Authz::purchaseScopeWhere($user, 'l');

$locWhere = ['l.is_active', $scopeL['sql']];
$bind     = $scopeL['params'];
if ($locationId > 0) {
    $locWhere[]   = 'l.id = CAST(:loc AS bigint)';
    $bind[':loc'] = $locationId;
}
if ($branchId > 0) {
    $locWhere[]      = 'l.branch_id = CAST(:branch AS bigint)';
    $bind[':branch'] = $branchId;
}

$itemWhere = ['i.is_active'];
if ($cat1 > 0) {
    $itemWhere[]   = 'i.cat1_id = CAST(:cat1 AS bigint)';
    $bind[':cat1'] = $cat1;
}
if ($q !== '') {
    $bind[':q']  = '%' . str_replace(['#', '%', '_'], ['##', '#%', '#_'], $q) . '%';
    $itemWhere[] = "(i.name ILIKE :q ESCAPE '#' OR i.code ILIKE :q ESCAPE '#')";
}

/**
 * 보이는 장소 → 그 장소에서 「셀 것」(재고 행 ∪ 인가량 행) → 칸 계산.
 * 값은 전부 바인딩이고, 조각(`$scopeL['sql']`)에는 자리표시자 이름만 있다 (PRD S-04).
 */
$sqlBase = <<<'SQL'
    WITH loc AS (
        SELECT l.id, l.name, l.kind, l.branch_id
          FROM p_locations l
         WHERE
    SQL;

$sqlBase2 = <<<'SQL'
    ),
    par AS (
        SELECT DISTINCT ON (p.location_id, p.item_id) p.location_id, p.item_id, p.qty
          FROM p_par_levels p
          JOIN loc ON loc.id = p.location_id
         WHERE p.is_active AND p.effective_from <= CURRENT_DATE
         ORDER BY p.location_id, p.item_id, p.effective_from DESC, p.id DESC
    ),
    bal AS (
        SELECT b.location_id, b.item_id, b.qty
          FROM p_stock_balance b
          JOIN loc ON loc.id = b.location_id
    ),
    keys AS (
        SELECT location_id, item_id FROM par
        UNION
        SELECT location_id, item_id FROM bal
    ),
    rows AS (
        SELECT k.location_id, k.item_id,
               loc.name AS location_name, loc.branch_id, loc.kind,
               i.code, i.name AS item_name, i.spec, i.unit, i.stock_managed,
               i.cat1_id, i.safety_qty, i.lead_days, i.min_order_qty,
               COALESCE(bal.qty, 0) AS on_hand,
               par.qty               AS par_qty,
               COALESCE(bal.qty, 0) - COALESCE(par.qty, 0) AS gap,
               COALESCE((SELECT sum(bo.remaining_qty) FROM p_backorder bo
                          WHERE bo.to_location_id = k.location_id AND bo.item_id = k.item_id), 0)
                 AS incoming,
               COALESCE((SELECT sum(CASE
                             WHEN dd.doc_type = 'is' THEN -m.qty_delta
                             WHEN dd.doc_type = 'gr' AND NOT i.stock_managed AND m.qty_delta > 0
                                  THEN m.qty_delta
                             ELSE 0 END)
                           FROM p_stock_moves m
                           JOIN p_doc_lines ml ON ml.id = m.doc_line_id
                           JOIN p_docs      dd ON dd.id = ml.doc_id
                          WHERE m.location_id = k.location_id AND m.item_id = k.item_id
                            AND m.moved_at > now() - interval '28 days'), 0) / 4
                 AS weekly_use
          FROM keys k
          JOIN loc      ON loc.id = k.location_id
          JOIN p_items i ON i.id = k.item_id
          LEFT JOIN par ON par.location_id = k.location_id AND par.item_id = k.item_id
          LEFT JOIN bal ON bal.location_id = k.location_id AND bal.item_id = k.item_id
         WHERE
    SQL;

$sqlRowsWhere = implode(' AND ', $itemWhere) . ($short ? ' AND COALESCE(bal.qty,0) - COALESCE(par.qty,0) < 0' : '');

$sqlHead = $sqlBase . ' ' . implode(' AND ', $locWhere) . ' ' . $sqlBase2 . ' ' . $sqlRowsWhere . ' )';

/** 건수 꼬리 — 값이 한 글자도 없는 고정 문자열이다 (PRD S-04). */
$sqlCountTail = <<<'SQL'
     SELECT count(*) AS n FROM rows
    SQL;

$total    = 0;
$countRow = $db->run($sqlHead . $sqlCountTail, $bind)->fetch();
if (is_array($countRow)) {
    $total = (int) $countRow['n'];
}

$bind[':lim'] = TBOX_STOCK_PER_PAGE;
$bind[':off'] = ($page - 1) * TBOX_STOCK_PER_PAGE;

$rows = $db->run(
    $sqlHead . <<<'SQL'
     SELECT location_id, location_name, branch_id, kind, item_id, code, item_name, spec, unit,
            stock_managed, cat1_id, safety_qty, lead_days, min_order_qty,
            CAST(on_hand AS text)    AS on_hand,
            par_qty,
            CAST(gap AS text)        AS gap,
            CAST(incoming AS text)   AS incoming,
            CAST(round(weekly_use, 2) AS text) AS weekly_use
       FROM rows
      ORDER BY location_name ASC, item_name ASC, item_id ASC
      LIMIT :lim OFFSET :off
    SQL,
    $bind
)->fetchAll();

$out = [];
foreach ($rows as $row) {
    $out[] = [
        'location_id'   => (int) $row['location_id'],
        'location_name' => (string) $row['location_name'],
        'branch_id'     => (int) $row['branch_id'],
        'kind'          => (string) $row['kind'],
        'item_id'       => (int) $row['item_id'],
        'code'          => (string) $row['code'],
        'name'          => (string) $row['item_name'],
        'spec'          => $row['spec'] === null ? null : (string) $row['spec'],
        'unit'          => $row['unit'] === null ? null : (string) $row['unit'],
        'stock_managed' => $row['stock_managed'] === true,
        'on_hand'       => (string) $row['on_hand'],
        'par_qty'       => $row['par_qty'] === null ? null : (int) $row['par_qty'],
        'gap'           => (string) $row['gap'],
        'incoming'      => (string) $row['incoming'],
        'weekly_use'    => (string) $row['weekly_use'],
        'safety_qty'    => (int) $row['safety_qty'],
        'lead_days'     => (int) $row['lead_days'],
        'min_order_qty' => (int) $row['min_order_qty'],
        // ★ 발주 제안은 **23단계**다. 자리만 두고 값을 지어내지 않는다 (PRD 로드맵).
        'suggest_qty'   => null,
    ];
}

Http::json([
    'items'    => $out,
    'total'    => $total,
    'page'     => $page,
    'per_page' => TBOX_STOCK_PER_PAGE,
]);
