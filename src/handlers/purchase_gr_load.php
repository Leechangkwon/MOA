<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_gr_load.php — GET /api/purchase/gr/load  (정책: purchase.stock.write)
 *
 * ★ 21단계 **발주서 불러오기 → 입고** (PRD F-33, 시안 ⑥ GR).
 *
 *   ?po=<발주서 id>   그 발주서만
 *   ?vendor=&branch=&location=   걸개 (지점 격리 조건과 AND 로 걸린다)
 *   ?overdue=1        보낸 날 + 품목 조달기간이 지난 줄만
 *   ?q=               발주서 번호·품목 검색
 *   ?group=vendor,doc 묶는 기준 1~3개. **적은 순서가 트리의 층**이다 (F-63)
 *
 * 응답 두 가지 — 섞이지 않는다 (F-63, PRD 12.2 T-75 (b))
 *   `?group=` 이 없거나 **아는 값이 하나도 없으면** 21단계와 똑같은 `orders`(발주서별 묶음)를
 *   낸다. 계약이 바뀌지 않는다는 뜻이고, 그래서 21단계 화면·시험이 그대로 돈다.
 *   아는 값이 있으면 `nodes` 트리를 낸다. 한 응답에 두 모양을 함께 담지 않는다.
 *
 * `group` 문자열은 **SQL 에 닿지 않는다** — 코드에 적힌 여섯(`TBOX_GR_LOAD_GROUPS`)의
 * 열쇠가 될 뿐이고 모르는 값은 조용히 버린다(`purchase_pr_load.php` 와 같은 방식).
 * **묶기는 조건을 건 뒤에 돈다** — 걸개·지점 격리·상한을 지난 줄만 트리에 들어간다.
 *
 * 돌려주는 것은 **미입고 잔량이 남은 발주서 줄**뿐이다 — 뷰 `p_backorder` 가 그 정의다:
 *     미입고 = 발주 줄 qty − Σ(그 줄을 출처로 하는 입고 줄 qty, 취소 제외) > 0
 * 그리고 **「보낸」 발주서만**(`sent_at IS NOT NULL`) 나온다. 아직 거래처에 나가지 않은
 * 문서의 물건이 도착할 수는 없으므로, 화면 이전 단계에서 없앤다.
 * (그래도 입고 API 는 FOR UPDATE 뒤 잔량을 다시 본다 — 화면은 근거가 아니다.)
 *
 * 지점 격리 (SECURITY R-65)
 *   `Authz::purchaseScopeWhere($user, 'd')` 를 발주서(`p_docs`)에 건다. stock 역할은
 *   자기 지점 발주서만 보이고, `?branch=` 에 남의 지점을 적어도 조건은 바뀌지 않는다.
 *
 * 조회는 감사 로그를 남기지 않는다.
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params 경로 변수 없음
 */

require_once __DIR__ . '/../purchase/Stock.php';
/* 트리를 만드는 코드는 **한 벌**이다 — 발주요청 불러오기(`purchase_pr_load.php`)도 같은
   함수를 부른다 (F-63). 순수 함수이고 자료를 읽지 않는다. */
require_once __DIR__ . '/../purchase/Grouping.php';

/** 한 번에 돌려주는 줄 수 상한 (화면은 발주서별로 묶어 보여 준다) */
const TBOX_GR_LOAD_MAX = 300;

/**
 * 묶는 기준 — 코드에 적힌 **여섯** 개만 (F-63). 요청 문자열은 이 표의 열쇠가 될 뿐이다.
 * 여섯 모두 `p_backorder` + 이미 걸려 있는 조인이 가진 값이라 **새 조회가 없다**.
 */
const TBOX_GR_LOAD_GROUPS = ['vendor', 'location', 'manager', 'branch', 'category', 'doc'];

$db = Db::instance();

$poId     = Http::queryInt('po', 0, 1, 999999999);
$vendor   = Http::queryInt('vendor', 0, 1, 999999999);
$branch   = Http::queryInt('branch', 0, 1, 999999999);
$location = Http::queryInt('location', 0, 1, 999999999);
$overdue  = Http::queryChoice('overdue', ['0', '1'], '0') === '1';
$q        = Http::queryText('q', 100);

// ─── 묶는 기준 (F-63) — 아는 값만 남긴다. 하나도 없으면 21단계의 `orders` 를 낸다 ──
$groups = [];
foreach (explode(',', Http::queryText('group', 60)) as $g) {
    $g = trim($g);
    if ($g !== '' && in_array($g, TBOX_GR_LOAD_GROUPS, true) && !in_array($g, $groups, true)) {
        $groups[] = $g;
    }
}
$groups = array_slice($groups, 0, Grouping::MAX_LEVELS);

$scope = Authz::purchaseScopeWhere($user, 'd');

$where = [$scope['sql']];
$bind  = $scope['params'];

if ($poId > 0) {
    $where[]     = 'bo.po_doc_id = CAST(:po AS bigint)';
    $bind[':po'] = $poId;
}
if ($vendor > 0) {
    $where[]         = 'bo.vendor_id = CAST(:vendor AS bigint)';
    $bind[':vendor'] = $vendor;
}
if ($branch > 0) {
    $where[]         = 'bo.branch_id = CAST(:branch AS bigint)';
    $bind[':branch'] = $branch;
}
if ($location > 0) {
    $where[]      = 'bo.to_location_id = CAST(:loc AS bigint)';
    $bind[':loc'] = $location;
}
if ($overdue) {
    // 「납기 지남」 — 머리 정보의 납기가 아니라 **보낸 날 + 품목 조달기간**으로 본다.
    // 납기는 buyer 가 고칠 수 있는 자유 값이라 판정의 근거로 쓰지 않는다.
    $where[] = "bo.sent_at + (i.lead_days || ' days')::interval < now()";
}
if ($q !== '') {
    $bind[':q'] = '%' . str_replace(['#', '%', '_'], ['##', '#%', '#_'], $q) . '%';
    $where[]    = "(bo.po_doc_no ILIKE :q ESCAPE '#' OR i.name ILIKE :q ESCAPE '#'"
                . " OR i.code ILIKE :q ESCAPE '#')";
}

/**
 * 미입고 뷰(`p_backorder`) + 전표·품목·장소 이름. 지점 격리는 `p_docs d` 에 건다 —
 * 뷰에는 권한 조건이 없고, 조건을 거는 곳은 언제나 Authz 한 곳이다 (ARCH 4.1).
 */
$sqlHead = <<<'SQL'
    SELECT bo.po_line_id, bo.po_doc_id, bo.po_doc_no, CAST(bo.po_date AS text) AS po_date,
           CAST(bo.sent_at AS text) AS sent_at,
           bo.branch_id, bo.to_location_id, bo.vendor_id, bo.item_id,
           CAST(bo.ordered_qty AS text)   AS ordered_qty,
           CAST(bo.received_qty AS text)  AS received_qty,
           CAST(bo.remaining_qty AS text) AS remaining_qty,
           CAST(bo.unit_price AS text)    AS unit_price,
           i.code, i.name AS item_name, i.spec, i.unit, i.lead_days, i.stock_managed,
           i.cat1_id, c1.name AS cat1_name,
           bo.manager_id,
           v.name AS vendor_name, br.name AS branch_name, loc.name AS location_name,
           mgr.username AS manager_name,
           (date_part('day', now() - bo.sent_at))::int AS days_elapsed
      FROM p_backorder bo
      JOIN p_docs      d   ON d.id = bo.po_doc_id
      JOIN p_items     i   ON i.id = bo.item_id
      LEFT JOIN p_vendors    v   ON v.id  = bo.vendor_id
      LEFT JOIN p_branches   br  ON br.id = bo.branch_id
      LEFT JOIN p_locations  loc ON loc.id = bo.to_location_id
      LEFT JOIN users        mgr ON mgr.id = bo.manager_id
      LEFT JOIN p_categories c1  ON c1.id = i.cat1_id
     WHERE
    SQL;

$bind[':lim'] = TBOX_GR_LOAD_MAX;

$rows = $db->run(
    $sqlHead . ' ' . implode(' AND ', $where)
        . ' ORDER BY bo.sent_at ASC, bo.po_doc_id ASC, bo.po_line_id ASC LIMIT :lim',
    $bind
)->fetchAll();

/* ═══ ?group= 이 왔다 → 묶음 트리 (F-63) ═══════════════════════
 *
 * 여기 오는 줄은 위에서 **조건을 이미 지난 것**이다 — 지점 격리·걸개·「보낸 발주서만」·
 * 잔량 0 제외(뷰 `p_backorder` 의 정의)·상한 `TBOX_GR_LOAD_MAX`. 묶기는 그 뒤에 돌고
 * 자료를 다시 읽지 않는다.
 */
if ($groups !== []) {
    $lines    = [];
    $totalQty = 0;
    foreach ($rows as $row) {
        $milli      = Doc::milli((string) $row['remaining_qty']);
        $totalQty  += $milli;
        $lines[]    = [
            // 담기에 쓰는 칸 — 21단계 `orders[].lines[]` 와 **같은 이름**이다.
            // 화면의 입고 줄이 이 이름을 그대로 읽는다(입고 확정 본문이 그것을 쓴다).
            'po_line_id'      => (int) $row['po_line_id'],
            'item_id'         => (int) $row['item_id'],
            'code'            => (string) $row['code'],
            'name'            => (string) $row['item_name'],
            'spec'            => $row['spec'] === null ? null : (string) $row['spec'],
            'unit'            => $row['unit'] === null ? null : (string) $row['unit'],
            'stock_managed'   => $row['stock_managed'] === true,
            'lead_days'       => (int) $row['lead_days'],
            'ordered_qty'     => (string) $row['ordered_qty'],
            'received_qty'    => (string) $row['received_qty'],
            'remaining'       => (string) $row['remaining_qty'],
            // 단가는 21단계와 같이 **보여 주기만** 한다 — 입고 확정 본문에 단가 칸이 아예
            // 없고, 서버가 발주서 줄에서 다시 읽는다 (SECURITY R-66).
            'unit_price'      => $row['unit_price'] === null ? null : (string) $row['unit_price'],
            // 트리에서는 「어느 발주서의 줄인가」를 묶음 머리가 아니라 **줄이** 들고 있어야
            // 한다(여러 전표를 한 번에 담을 수 있으므로).
            'po_doc_id'       => (int) $row['po_doc_id'],
            'po_doc_no'       => (string) $row['po_doc_no'],
            'po_date'         => (string) $row['po_date'],
            'sent_at'         => $row['sent_at'] === null ? null : (string) $row['sent_at'],
            'days_elapsed'    => (int) $row['days_elapsed'],
            // 묶는 기준이 읽는 칸
            'vendor_id'       => $row['vendor_id'] === null ? null : (int) $row['vendor_id'],
            'vendor_name'     => $row['vendor_name'] === null ? null : (string) $row['vendor_name'],
            'location_id'     => $row['to_location_id'] === null ? null : (int) $row['to_location_id'],
            'location_name'   => $row['location_name'] === null ? null : (string) $row['location_name'],
            'manager_id'      => $row['manager_id'] === null ? 0 : (int) $row['manager_id'],
            'manager_name'    => $row['manager_name'] === null ? null : (string) $row['manager_name'],
            'branch_id'       => (int) $row['branch_id'],
            'branch_name'     => $row['branch_name'] === null ? null : (string) $row['branch_name'],
            'cat1_id'         => $row['cat1_id'] === null ? null : (int) $row['cat1_id'],
            'cat1_name'       => $row['cat1_name'] === null ? null : (string) $row['cat1_name'],
            'remaining_milli' => $milli,
        ];
    }

    /**
     * 한 줄에서 묶음 열쇠·이름을 뽑는다 — 열쇠는 **정수**(0 = 미지정)다.
     * `purchase_pr_load.php` 의 같은 이름 클로저와 짝이고, 다른 것은 칸 이름뿐이다.
     *
     * @param array<string,mixed> $line
     * @return array{0:int,1:string}
     */
    $keyOf = static function (array $line, string $kind): array {
        switch ($kind) {
            case 'vendor':
                return $line['vendor_id'] === null
                    ? [0, '미지정']
                    : [(int) $line['vendor_id'], (string) ($line['vendor_name'] ?? '?')];
            case 'location':
                return $line['location_id'] === null
                    ? [0, '장소 미지정']
                    : [(int) $line['location_id'], (string) ($line['location_name'] ?? '?')];
            case 'manager':
                return [(int) $line['manager_id'], (string) ($line['manager_name'] ?? '?')];
            case 'branch':
                return [(int) $line['branch_id'], (string) ($line['branch_name'] ?? '?')];
            case 'doc':
                // 전표 — 열쇠는 발주서 id, 이름은 「PO 번호 · 일자」.
                return [(int) $line['po_doc_id'], $line['po_doc_no'] . ' · ' . $line['po_date']];
            default:   // category
                return $line['cat1_id'] === null
                    ? [0, '미분류']
                    : [(int) $line['cat1_id'], (string) $line['cat1_name']];
        }
    };

    /**
     * 한 줄의 수량(밀리 정수) — 입고 쪽 잔량 칸 이름이 `remaining_milli` 라는 것을
     * 아는 곳은 여기뿐이다. 층 소계는 `Grouping` 이 이 값을 더해 만든다.
     *
     * @param array<string,mixed> $line
     */
    $qtyOf = static function (array $line): int {
        return (int) $line['remaining_milli'];
    };

    Http::json([
        'group'      => $groups,
        'nodes'      => Grouping::tree($lines, $groups, $keyOf, $qtyOf),
        'line_count' => count($lines),
        'qty'        => Doc::qtyText($totalQty),
        // 상한에 닿았으면 화면이 "더 좁혀 보라" 고 안내한다 (조용히 자르지 않는다).
        'truncated'  => count($lines) >= TBOX_GR_LOAD_MAX,
    ]);
    return;
}

// ─── 발주서별로 묶는다 (시안 ⑥ — 한 전표가 한 묶음) ─────────
$orders = [];
$lineCount = 0;
foreach ($rows as $row) {
    $docId = (int) $row['po_doc_id'];
    if (!isset($orders[$docId])) {
        $orders[$docId] = [
            'po_doc_id'     => $docId,
            'po_doc_no'     => (string) $row['po_doc_no'],
            'po_date'       => (string) $row['po_date'],
            'sent_at'       => $row['sent_at'] === null ? null : (string) $row['sent_at'],
            'days_elapsed'  => (int) $row['days_elapsed'],
            'branch_id'     => (int) $row['branch_id'],
            'branch_name'   => $row['branch_name'] === null ? null : (string) $row['branch_name'],
            'location_id'   => $row['to_location_id'] === null ? null : (int) $row['to_location_id'],
            'location_name' => $row['location_name'] === null ? null : (string) $row['location_name'],
            'vendor_id'     => $row['vendor_id'] === null ? null : (int) $row['vendor_id'],
            'vendor_name'   => $row['vendor_name'] === null ? null : (string) $row['vendor_name'],
            'manager_name'  => $row['manager_name'] === null ? null : (string) $row['manager_name'],
            'lines'         => [],
        ];
    }
    $orders[$docId]['lines'][] = [
        'po_line_id'    => (int) $row['po_line_id'],
        'item_id'       => (int) $row['item_id'],
        'code'          => (string) $row['code'],
        'name'          => (string) $row['item_name'],
        'spec'          => $row['spec'] === null ? null : (string) $row['spec'],
        'unit'          => $row['unit'] === null ? null : (string) $row['unit'],
        'stock_managed' => $row['stock_managed'] === true,
        'lead_days'     => (int) $row['lead_days'],
        'ordered_qty'   => (string) $row['ordered_qty'],
        'received_qty'  => (string) $row['received_qty'],
        'remaining'     => (string) $row['remaining_qty'],
        // 단가는 **발주서 줄의 값**이다. 화면은 보여 주기만 하고, 입고 확정 때 서버가
        // 다시 읽는다 — 본문에 단가·금액을 실을 자리가 아예 없다 (SECURITY R-66).
        'unit_price'    => $row['unit_price'] === null ? null : (string) $row['unit_price'],
    ];
    $lineCount++;
}

Http::json([
    'orders'     => array_values($orders),
    'line_count' => $lineCount,
    'truncated'  => $lineCount >= TBOX_GR_LOAD_MAX,
]);
