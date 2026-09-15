<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_gr_get.php — GET /api/purchase/gr/{num}  (정책: purchase.read)
 *
 * ★ F-45 입고 전표 상세 (PRD F-34 · F-45, 시안 ⑥ GR 「전표」 탭).
 *
 * 돌려주는 것
 *   · 전표 머리 — 20단계 `purchase_po_get.php` 와 같은 키에 `memo` 를 더한 것
 *   · 줄 — `Stock::lines()` 가 주는 모양 그대로(로트·유효기간·출처 발주서 `src_doc_no`)에
 *     **출처 발주서의 줄 번호**(`src_line_no`)를 얹는다. 화면이 「PO-…-16 3번 줄」 을 짚을 수 있게.
 *   · `totals.qty` — 취소되지 않은 줄 수량의 합. **금액이 없다**: 입고 전표가 세는 것은
 *     수량이고, 단가는 서버가 발주서 줄에서 읽어 이미 줄에 적혀 있다 (SECURITY R-66).
 *   · `can_void` — 「취소」 단추를 보일지. 참이 되는 조건 셋을 **모두** 만족할 때만이다:
 *       ① `purchase.stock.write` 자격이 있고 ② 아직 취소되지 않았고 ③ 마감된 달이 아니다.
 *     이것은 **화면 편의 값**이고 막는 것은 `purchase_gr_void.php` 다 (PRD S-01).
 *     마감된 달이라도 `purchase.close` 보유자가 사유를 적으면 지날 수 있으므로(ARCH 5.18
 *     「마감」), 여기서 false 가 곧 "절대 취소 불가" 는 아니다 — 단추만 감출 뿐이다.
 *
 * 지점 격리 (SECURITY R-65): `Authz::purchaseScopeWhere($user, 'd')`.
 * 범위 밖 전표는 **404** 다 — 403 은 "있긴 있다" 를 알려 주는 셈이라 쓰지 않는다
 * (`purchase_po_get.php` 와 같은 판단).
 *
 * 조회는 감사 로그를 남기지 않는다.
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params $params[0] = p_docs.id
 */

require_once __DIR__ . '/../purchase/Stock.php';

$idRaw = $params[0] ?? '';
if (!is_string($idRaw) || preg_match('/^[0-9]{1,9}$/', $idRaw) !== 1) {
    Http::error(404, Http::MSG_NOT_FOUND);
}
$id = (int) $idRaw;

$scope = Authz::purchaseScopeWhere($user, 'd');

$sqlDoc = <<<'SQL'
    SELECT d.id, d.doc_no, d.doc_type, CAST(d.doc_date AS text) AS doc_date, d.status,
           d.branch_id, br.name AS branch_name,
           d.vendor_id, ven.name AS vendor_name,
           d.to_location_id, loc.name AS location_name,
           d.manager_id, mgr.username AS manager_name,
           d.memo, d.closed_month,
           d.created_at, d.updated_at
      FROM p_docs d
      LEFT JOIN p_branches  br  ON br.id  = d.branch_id
      LEFT JOIN p_vendors   ven ON ven.id = d.vendor_id
      LEFT JOIN p_locations loc ON loc.id = d.to_location_id
      LEFT JOIN users       mgr ON mgr.id = d.manager_id
     WHERE d.id = CAST(:id AS bigint) AND d.doc_type = :type AND
    SQL;

/** 줄마다 「출처 발주서의 몇 번째 줄인가」 — 값은 전부 바인딩이다 */
$sqlSrcLines = <<<'SQL'
    SELECT src.id AS src_id, src.line_no AS src_line_no
      FROM p_doc_lines l
      JOIN p_doc_lines src ON src.id = l.src_line_id
     WHERE l.doc_id = CAST(:doc AS bigint)
    SQL;

$db  = Db::instance();
$row = $db->run(
    $sqlDoc . ' ' . $scope['sql'],
    [':id' => $id, ':type' => Stock::TYPE_GR] + $scope['params']
)->fetch();
if (!is_array($row)) {
    Http::error(404, Http::MSG_NOT_FOUND);
}

$srcLineNo = [];
foreach ($db->run($sqlSrcLines, [':doc' => $id])->fetchAll() as $src) {
    $srcLineNo[(int) $src['src_id']] = (int) $src['src_line_no'];
}

$lines   = Stock::lines($db, $id);
$qtyMilli = 0;
foreach ($lines as $index => $line) {
    $srcId = $line['src_line_id'];
    $lines[$index]['src_line_no'] = ($srcId !== null && isset($srcLineNo[$srcId]))
        ? $srcLineNo[$srcId]
        : null;
    if ((string) $line['status'] !== Doc::LINE_VOID) {
        $qtyMilli += Doc::milli((string) $line['qty']);
    }
}

$status = (string) $row['status'];
$closed = ($row['closed_month'] !== null && (string) $row['closed_month'] !== '')
    || Doc::monthClosed($db, (int) $row['branch_id'], substr((string) $row['doc_date'], 0, 7));

Http::json([
    'doc' => [
        'id'             => (int) $row['id'],
        'doc_no'         => (string) $row['doc_no'],
        'doc_type'       => (string) $row['doc_type'],
        'doc_date'       => (string) $row['doc_date'],
        'status'         => $status,
        'branch_id'      => (int) $row['branch_id'],
        'branch_name'    => $row['branch_name'] === null ? null : (string) $row['branch_name'],
        'vendor_id'      => $row['vendor_id'] === null ? null : (int) $row['vendor_id'],
        'vendor_name'    => $row['vendor_name'] === null ? null : (string) $row['vendor_name'],
        'to_location_id' => $row['to_location_id'] === null ? null : (int) $row['to_location_id'],
        'location_name'  => $row['location_name'] === null ? null : (string) $row['location_name'],
        'manager_id'     => (int) $row['manager_id'],
        'manager_name'   => $row['manager_name'] === null ? null : (string) $row['manager_name'],
        'memo'           => $row['memo'] === null ? null : (string) $row['memo'],
        'closed_month'   => $row['closed_month'] === null ? null : (string) $row['closed_month'],
        'created_at'     => (string) $row['created_at'],
        'updated_at'     => (string) $row['updated_at'],
        'voided_at'      => $status === Doc::ST_VOID ? (string) $row['updated_at'] : null,
    ],
    'lines'    => $lines,
    'totals'   => ['qty' => Doc::qtyText($qtyMilli)],
    'can_void' => Authz::purchaseAuthorize('purchase.stock.write', $user)
        && $status !== Doc::ST_VOID
        && !$closed,
]);
