<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_pr_load.php — GET /api/purchase/pr/load  (정책: purchase.buy.write)
 *
 * ★ 20단계 **발주요청 불러오기** (PRD F-32 R3, 시안 ④ ImportModal, ARCH 5.18 「연결」).
 *
 *   ?group=vendor,location,manager   묶는 기준 1~3개. **적은 순서가 트리의 층**이다
 *   ?branch=&vendor=&manager=        추가 걸개(지점 격리 조건과 AND 로 걸린다)
 *   ?from=&to=                       요청 일자 범위
 *   ?q=                              전표 번호·품목 검색
 *
 * 돌려주는 것은 **미발주 잔량이 남은 요청 줄**뿐이다:
 *     잔량 = 요청 qty − Σ(자식 발주서 줄 qty, 취소 제외) > 0
 * 이미 전량 담긴 줄은 목록에 나타나지 않는다 — 같은 줄을 두 번 담는 실수를 화면 이전 단계에서 없앤다.
 * (그래도 담기 API 는 FOR UPDATE 뒤 잔량을 다시 본다 — 화면은 근거가 아니다.)
 *
 * 지점 격리 (SECURITY R-65)
 *   정책이 `purchase.buy.write` 라 여기 닿는 사람은 buyer·Admin(= 전 지점)뿐이지만,
 *   조건은 **그래도 SQL 에 붙인다**. 모듈 역할이 넓어지는 날 이 파일만 따로 고치지 않아도
 *   격리가 따라오게 하는 것이 목적이다(린트 규칙 (a)가 요구하는 것과 같은 이유).
 *
 * 조회는 감사 로그를 남기지 않는다.
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params 경로 변수 없음
 */

require_once __DIR__ . '/../purchase/Doc.php';
/* 묶기는 `Grouping` **한 벌**이다 — 입고 불러오기(`purchase_gr_load.php`)도 같은 함수를
   부른다 (F-63). 이 파일에서 옮겨 간 것은 재귀 묶기뿐이고, 줄에서 열쇠·이름을 뽑는
   규칙(`$keyOf`)은 줄 필드 이름이 화면마다 달라 **부르는 쪽에** 남는다. */
require_once __DIR__ . '/../purchase/Grouping.php';

/**
 * 묶는 기준 — 코드에 적힌 **여섯** 개만. 요청 문자열은 이 표의 열쇠가 될 뿐이다.
 * `doc`(전표)는 F-60 에서 더했다 — 줄 필드에 이미 `doc_id`·`doc_no`·`doc_date` 가 있어
 * **새 SQL 열도 새 조회도 없다**. 묶기만 한 층 더 할 수 있게 된 것이다.
 */
const TBOX_LOAD_GROUPS = ['vendor', 'location', 'manager', 'branch', 'category', 'doc'];
const TBOX_LOAD_MAX_GROUPS = 3;
/** 한 번에 돌려주는 줄 수 상한 (화면은 묶어서 보여 주므로 넉넉히, 그러나 무한하지 않게) */
const TBOX_LOAD_MAX_LINES = 500;

$db = Db::instance();

// ─── 묶는 기준 ──────────────────────────────────────────────
$groupRaw = Http::queryText('group', 60);
$groups   = [];
foreach (explode(',', $groupRaw) as $g) {
    $g = trim($g);
    if ($g !== '' && in_array($g, TBOX_LOAD_GROUPS, true) && !in_array($g, $groups, true)) {
        $groups[] = $g;
    }
}
if ($groups === []) {
    $groups = ['vendor'];   // 기본은 거래처 한 층 (발주서가 거래처 단위이므로)
}
$groups = array_slice($groups, 0, TBOX_LOAD_MAX_GROUPS);

// ─── 걸개 ───────────────────────────────────────────────────
$branch  = Http::queryInt('branch', 0, 1, 999999999);
$vendor  = Http::queryInt('vendor', 0, 1, 999999999);
$manager = Http::queryInt('manager', 0, 1, 999999999);
$from    = Http::queryText('from', 10);
$to      = Http::queryText('to', 10);
$q       = Http::queryText('q', 100);

$scope = Authz::purchaseScopeWhere($user, 'd');

$where   = [$scope['sql']];
$bind    = $scope['params'];
$where[] = "d.doc_type = 'pr'";
$where[] = "d.status <> 'void'";
$where[] = "l.status <> 'void'";
$where[] = 'i.is_active';
// 잔량이 남은 줄만. 조각에는 값이 없다 (Doc::SQL_TAKEN 은 고정 문자열).
$where[] = 'l.qty > ' . Doc::SQL_TAKEN;

if ($branch > 0) {
    $where[]         = 'd.branch_id = CAST(:branch AS bigint)';
    $bind[':branch'] = $branch;
}
if ($vendor > 0) {
    $where[]         = '(d.vendor_id = CAST(:vendor AS bigint) OR (d.vendor_id IS NULL'
                     . ' AND i.vendor_id = CAST(:vendor AS bigint)))';
    $bind[':vendor'] = $vendor;
}
if ($manager > 0) {
    $where[]          = 'd.manager_id = CAST(:manager AS bigint)';
    $bind[':manager'] = $manager;
}
if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $from) === 1) {
    $where[]       = 'd.doc_date >= CAST(:from AS date)';
    $bind[':from'] = $from;
}
if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $to) === 1) {
    $where[]     = 'd.doc_date <= CAST(:to AS date)';
    $bind[':to'] = $to;
}
if ($q !== '') {
    $bind[':q'] = '%' . str_replace(['#', '%', '_'], ['##', '#%', '#_'], $q) . '%';
    $where[]    = "(d.doc_no ILIKE :q ESCAPE '#' OR i.name ILIKE :q ESCAPE '#'"
                . " OR i.code ILIKE :q ESCAPE '#')";
}

$sqlHead = <<<'SQL'
    SELECT l.id AS line_id, l.line_no, CAST(l.qty AS text) AS qty, l.remark,
           d.id AS doc_id, d.doc_no, CAST(d.doc_date AS text) AS doc_date,
           d.branch_id, br.name AS branch_name,
           d.to_location_id, loc.name AS location_name,
           d.manager_id, mgr.username AS manager_name,
           d.vendor_id AS doc_vendor_id, dv.name AS doc_vendor_name,
           i.id AS item_id, i.code, i.name AS item_name, i.spec, i.unit,
           CAST(i.price_in AS text) AS price_in,
           i.vendor_id AS item_vendor_id, iv.name AS item_vendor_name,
           i.cat1_id, c1.name AS cat1_name,
    SQL;

$sqlTail = <<<'SQL'
    AS taken
      FROM p_doc_lines l
      JOIN p_docs      d   ON d.id = l.doc_id
      JOIN p_items     i   ON i.id = l.item_id
      JOIN p_vendors   iv  ON iv.id = i.vendor_id
      LEFT JOIN p_vendors   dv  ON dv.id = d.vendor_id
      LEFT JOIN p_branches  br  ON br.id = d.branch_id
      LEFT JOIN p_locations loc ON loc.id = d.to_location_id
      LEFT JOIN users       mgr ON mgr.id = d.manager_id
      LEFT JOIN p_categories c1 ON c1.id = i.cat1_id
     WHERE
    SQL;

$sqlOrder = ' ORDER BY d.doc_date ASC, d.id ASC, l.line_no ASC LIMIT :lim';

$bind[':lim'] = TBOX_LOAD_MAX_LINES;

$rows = $db->run(
    $sqlHead . ' ' . Doc::SQL_TAKEN . ' ' . $sqlTail . ' ' . implode(' AND ', $where) . $sqlOrder,
    $bind
)->fetchAll();

// ─── 줄 모양 다듬기 ─────────────────────────────────────────
$lines = [];
foreach ($rows as $row) {
    $want  = Doc::milli((string) $row['qty']);
    $taken = Doc::milli((string) $row['taken']);
    $lines[] = [
        'line_id'          => (int) $row['line_id'],
        'doc_id'           => (int) $row['doc_id'],
        'doc_no'           => (string) $row['doc_no'],
        'doc_date'         => (string) $row['doc_date'],
        'branch_id'        => (int) $row['branch_id'],
        'branch_name'      => $row['branch_name'] === null ? null : (string) $row['branch_name'],
        'location_id'      => $row['to_location_id'] === null ? null : (int) $row['to_location_id'],
        'location_name'    => $row['location_name'] === null ? null : (string) $row['location_name'],
        'manager_id'       => (int) $row['manager_id'],
        'manager_name'     => $row['manager_name'] === null ? null : (string) $row['manager_name'],
        'item_id'          => (int) $row['item_id'],
        'code'             => (string) $row['code'],
        'name'             => (string) $row['item_name'],
        'spec'             => $row['spec'] === null ? null : (string) $row['spec'],
        'unit'             => $row['unit'] === null ? null : (string) $row['unit'],
        'price_in'         => $row['price_in'] === null ? null : (string) $row['price_in'],
        // 거래처 두 가지: 요청이 적어 둔 것(없을 수 있다)과 품목의 기본 거래처.
        // 「미지정」 묶음에서 즉석 지정할 때 화면이 두 번째 값을 후보로 보여 준다 (지시서 보충 2).
        'doc_vendor_id'    => $row['doc_vendor_id'] === null ? null : (int) $row['doc_vendor_id'],
        'doc_vendor_name'  => $row['doc_vendor_name'] === null ? null : (string) $row['doc_vendor_name'],
        'item_vendor_id'   => (int) $row['item_vendor_id'],
        'item_vendor_name' => (string) $row['item_vendor_name'],
        'cat1_id'          => $row['cat1_id'] === null ? null : (int) $row['cat1_id'],
        'cat1_name'        => $row['cat1_name'] === null ? null : (string) $row['cat1_name'],
        'remark'           => $row['remark'] === null ? null : (string) $row['remark'],
        'qty'              => (string) $row['qty'],
        'taken_qty'        => Doc::qtyText($taken),
        'remaining'        => Doc::qtyText(max(0, $want - $taken)),
        'remaining_milli'  => max(0, $want - $taken),
    ];
}

/**
 * 한 줄에서 묶음 열쇠·이름을 뽑는다. 열쇠는 **정수**(0 = 미지정)이고 이름은 사람이 적은 문자열이다.
 *
 * @param array<string,mixed> $line
 * @return array{0:int,1:string}
 */
$keyOf = static function (array $line, string $kind): array {
    switch ($kind) {
        case 'vendor':
            // 요청이 거래처를 적었으면 그것, 아니면 「미지정」 — 즉석 지정 대상이다.
            return $line['doc_vendor_id'] === null
                ? [0, '미지정']
                : [(int) $line['doc_vendor_id'], (string) $line['doc_vendor_name']];
        case 'location':
            return $line['location_id'] === null
                ? [0, '장소 미지정']
                : [(int) $line['location_id'], (string) $line['location_name']];
        case 'manager':
            return [(int) $line['manager_id'], (string) ($line['manager_name'] ?? '?')];
        case 'branch':
            return [(int) $line['branch_id'], (string) ($line['branch_name'] ?? '?')];
        case 'doc':
            // 전표(F-60) — 열쇠는 요청 전표 id, 이름은 「PR 번호 · 일자」.
            return [(int) $line['doc_id'], $line['doc_no'] . ' · ' . $line['doc_date']];
        default:   // category
            return $line['cat1_id'] === null
                ? [0, '미분류']
                : [(int) $line['cat1_id'], (string) $line['cat1_name']];
    }
};

/**
 * 한 줄의 수량(밀리 정수). 층마다의 소계는 `Grouping` 이 이 값을 더해 만든다 —
 * 발주요청 쪽 잔량 칸 이름이 `remaining_milli` 라는 것을 아는 곳은 여기뿐이다.
 *
 * @param array<string,mixed> $line
 */
$qtyOf = static function (array $line): int {
    return (int) $line['remaining_milli'];
};

$totalQty = 0;
foreach ($lines as $l) {
    $totalQty += (int) $l['remaining_milli'];
}

Http::json([
    'group'      => $groups,
    'groups'     => Grouping::tree($lines, $groups, $keyOf, $qtyOf),
    'line_count' => count($lines),
    'qty'        => Doc::qtyText($totalQty),
    // 상한에 닿았으면 화면이 "더 좁혀 보라" 고 안내한다 (조용히 자르지 않는다).
    'truncated'  => count($lines) >= TBOX_LOAD_MAX_LINES,
]);
