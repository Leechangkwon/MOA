<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_backorder.php — GET /api/purchase/backorder  (정책: purchase.read)
 *
 * ★ 21단계 **미입고 현황** (PRD F-34 R6, 시안 ⑪ Backorder).
 *
 *   ?vendor=    거래처        ?branch=  지점        ?po=  발주서
 *   ?overdue=1  납기 지남만 (보낸 날 + 품목 조달기간이 지났다)
 *   ?days=N     보낸 지 N일 이상만 (시안의 「7일 이상」)
 *   ?q=         발주서 번호·품목 검색
 *
 * 미입고 = **「보낸」 발주서 줄의 수량 − 그 줄을 출처로 하는 입고 줄 수량의 합** (뷰 `p_backorder`).
 * 발주서를 취소하면 뷰에서 사라진다 — 그리고 그 취소는 감사에 사유와 함께 남아 있다.
 *
 * 응답은 **거래처별로 묶어** 준다(시안의 기본 묶기). 묶음마다 건수·품목 수·공급가액 소계가
 * 붙고, 줄마다 「보낸 지 며칠」이 들어간다 — 독촉 문구를 화면이 만들 수 있게.
 *
 * 지점 격리 (SECURITY R-65)
 *   `Authz::purchaseScopeWhere($user, 'd')` 를 발주서에 건다. requester·stock 은 **자기 지점
 *   발주서의 미입고만** 보이고, `?branch=` 로 남의 지점을 적어도 조건은 바뀌지 않는다.
 *
 * 조회는 감사 로그를 남기지 않는다.
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params 경로 변수 없음
 */

require_once __DIR__ . '/../purchase/Stock.php';

const TBOX_BACKORDER_MAX = 500;

$db = Db::instance();

$vendor  = Http::queryInt('vendor', 0, 1, 999999999);
$branch  = Http::queryInt('branch', 0, 1, 999999999);
$poId    = Http::queryInt('po', 0, 1, 999999999);
$days    = Http::queryInt('days', 0, 1, 3650);
$overdue = Http::queryChoice('overdue', ['0', '1'], '0') === '1';
$q       = Http::queryText('q', 100);

$scope = Authz::purchaseScopeWhere($user, 'd');

$where = [$scope['sql']];
$bind  = $scope['params'];

if ($vendor > 0) {
    $where[]         = 'bo.vendor_id = CAST(:vendor AS bigint)';
    $bind[':vendor'] = $vendor;
}
if ($branch > 0) {
    $where[]         = 'bo.branch_id = CAST(:branch AS bigint)';
    $bind[':branch'] = $branch;
}
if ($poId > 0) {
    $where[]     = 'bo.po_doc_id = CAST(:po AS bigint)';
    $bind[':po'] = $poId;
}
if ($overdue) {
    // 납기 판정의 근거는 **보낸 날 + 품목 조달기간**이다. 머리 정보의 납기는 buyer 가 고칠 수
    // 있는 자유 값이라 판정에 쓰지 않는다(고쳐서 「지나지 않은 것」으로 만들 수 없게).
    $where[] = "bo.sent_at + (i.lead_days || ' days')::interval < now()";
}
if ($days > 0) {
    $where[]       = "bo.sent_at < now() - (CAST(:days AS int) || ' days')::interval";
    $bind[':days'] = $days;
}
if ($q !== '') {
    $bind[':q'] = '%' . str_replace(['#', '%', '_'], ['##', '#%', '#_'], $q) . '%';
    $where[]    = "(bo.po_doc_no ILIKE :q ESCAPE '#' OR i.name ILIKE :q ESCAPE '#'"
                . " OR i.code ILIKE :q ESCAPE '#')";
}

$sqlHead = <<<'SQL'
    SELECT bo.po_line_id, bo.po_doc_id, bo.po_doc_no, CAST(bo.po_date AS text) AS po_date,
           CAST(bo.sent_at AS text) AS sent_at,
           (date_part('day', now() - bo.sent_at))::int AS days_elapsed,
           CAST(bo.sent_at + (i.lead_days || ' days')::interval AS date)::text AS due_date,
           (bo.sent_at + (i.lead_days || ' days')::interval < now()) AS is_overdue,
           bo.branch_id, bo.vendor_id, bo.to_location_id, bo.item_id,
           CAST(bo.ordered_qty AS text)   AS ordered_qty,
           CAST(bo.received_qty AS text)  AS received_qty,
           CAST(bo.remaining_qty AS text) AS remaining_qty,
           CAST(COALESCE(bo.unit_price, 0) * bo.remaining_qty AS text) AS remaining_amount,
           i.code, i.name AS item_name, i.spec, i.unit, i.lead_days,
           v.name AS vendor_name, br.name AS branch_name, loc.name AS location_name,
           mgr.username AS manager_name
      FROM p_backorder bo
      JOIN p_docs      d   ON d.id = bo.po_doc_id
      JOIN p_items     i   ON i.id = bo.item_id
      LEFT JOIN p_vendors   v   ON v.id  = bo.vendor_id
      LEFT JOIN p_branches  br  ON br.id = bo.branch_id
      LEFT JOIN p_locations loc ON loc.id = bo.to_location_id
      LEFT JOIN users       mgr ON mgr.id = bo.manager_id
     WHERE
    SQL;

$bind[':lim'] = TBOX_BACKORDER_MAX;

$rows = $db->run(
    $sqlHead . ' ' . implode(' AND ', $where)
        . ' ORDER BY bo.sent_at ASC, bo.po_doc_id ASC, bo.po_line_id ASC LIMIT :lim',
    $bind
)->fetchAll();

// ─── 거래처별로 묶는다 (시안 ⑪ 「묶기: 거래처」) ─────────────
$groups      = [];
$lineCount   = 0;
$overdueCnt  = 0;
$amountTotal = 0.0;
$docSeen     = [];
foreach ($rows as $row) {
    $vid = $row['vendor_id'] === null ? 0 : (int) $row['vendor_id'];
    if (!isset($groups[$vid])) {
        $groups[$vid] = [
            'vendor_id'   => $vid === 0 ? null : $vid,
            'vendor_name' => $row['vendor_name'] === null ? '거래처 미지정' : (string) $row['vendor_name'],
            'doc_count'   => 0,
            'line_count'  => 0,
            'amount'      => 0.0,
            'docs'        => [],
            'lines'       => [],
        ];
    }
    $docNo = (string) $row['po_doc_no'];
    if (!isset($groups[$vid]['docs'][$docNo])) {
        $groups[$vid]['docs'][$docNo] = true;
        $groups[$vid]['doc_count']++;
    }
    $amount = (float) $row['remaining_amount'];
    $groups[$vid]['amount'] += $amount;
    $groups[$vid]['line_count']++;
    $amountTotal += $amount;
    $lineCount++;
    $docSeen[$docNo] = true;
    if ($row['is_overdue'] === true) {
        $overdueCnt++;
    }

    $groups[$vid]['lines'][] = [
        'po_line_id'    => (int) $row['po_line_id'],
        'po_doc_id'     => (int) $row['po_doc_id'],
        'po_doc_no'     => $docNo,
        'po_date'       => (string) $row['po_date'],
        'sent_at'       => $row['sent_at'] === null ? null : (string) $row['sent_at'],
        'due_date'      => $row['due_date'] === null ? null : (string) $row['due_date'],
        'days_elapsed'  => (int) $row['days_elapsed'],
        'is_overdue'    => $row['is_overdue'] === true,
        'branch_id'     => (int) $row['branch_id'],
        'branch_name'   => $row['branch_name'] === null ? null : (string) $row['branch_name'],
        'location_id'   => $row['to_location_id'] === null ? null : (int) $row['to_location_id'],
        'location_name' => $row['location_name'] === null ? null : (string) $row['location_name'],
        'manager_name'  => $row['manager_name'] === null ? null : (string) $row['manager_name'],
        'item_id'       => (int) $row['item_id'],
        'code'          => (string) $row['code'],
        'name'          => (string) $row['item_name'],
        'spec'          => $row['spec'] === null ? null : (string) $row['spec'],
        'unit'          => $row['unit'] === null ? null : (string) $row['unit'],
        'ordered_qty'   => (string) $row['ordered_qty'],
        'received_qty'  => (string) $row['received_qty'],
        'remaining'     => (string) $row['remaining_qty'],
        'amount'        => number_format($amount, 2, '.', ''),
        'partial'       => Doc::milli((string) $row['received_qty']) > 0,
    ];
}

$out = [];
foreach ($groups as $group) {
    unset($group['docs']);
    $group['amount'] = number_format((float) $group['amount'], 2, '.', '');
    $out[] = $group;
}

Http::json([
    'groups'      => $out,
    'doc_count'   => count($docSeen),
    'line_count'  => $lineCount,
    'overdue'     => $overdueCnt,
    'amount'      => number_format($amountTotal, 2, '.', ''),
    'truncated'   => $lineCount >= TBOX_BACKORDER_MAX,
]);
