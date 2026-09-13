<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_po_get.php — GET /api/purchase/po/{num}  (정책: purchase.read)
 *
 * ★ 20단계 발주서 상세 (PRD F-32, Q-18②·④).
 *
 * 돌려주는 것
 *   · 전표 머리와 줄(줄마다 **출처 요청 번호** `src_doc_no` — R4)
 *   · `header` — `p_docs.header_json` (서버가 채운 발주서 머리)
 *   · `totals` — 공급가액·부가세·합계. **저장하지 않고 매 응답 계산**한다 (Q-18② 마지막 줄)
 *   · `sent_at`·`sent_by_name` — 「보냄」 흔적 (Q-18④)
 *
 * 지점 격리 (SECURITY R-65): `Authz::purchaseScopeWhere($user, 'd')`. 범위 밖은 404.
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params $params[0] = p_docs.id
 */

require_once __DIR__ . '/../purchase/Doc.php';

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
           d.memo, d.closed_month, CAST(d.header_json AS text) AS header_json,
           d.sent_at, d.sent_by, sb.username AS sent_by_name,
           d.created_at, d.updated_at
      FROM p_docs d
      LEFT JOIN p_branches  br  ON br.id  = d.branch_id
      LEFT JOIN p_vendors   ven ON ven.id = d.vendor_id
      LEFT JOIN p_locations loc ON loc.id = d.to_location_id
      LEFT JOIN users       mgr ON mgr.id = d.manager_id
      LEFT JOIN users       sb  ON sb.id  = d.sent_by
     WHERE d.id = CAST(:id AS bigint) AND d.doc_type = :type AND
    SQL;

$db  = Db::instance();
$row = $db->run(
    $sqlDoc . ' ' . $scope['sql'],
    [':id' => $id, ':type' => Doc::TYPE_PO] + $scope['params']
)->fetch();
if (!is_array($row)) {
    Http::error(404, Http::MSG_NOT_FOUND);
}

$lines = Doc::lines($db, $id);

Http::json([
    'doc' => [
        'id'             => (int) $row['id'],
        'doc_no'         => (string) $row['doc_no'],
        'doc_type'       => (string) $row['doc_type'],
        'doc_date'       => (string) $row['doc_date'],
        'status'         => (string) $row['status'],
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
        'sent_at'        => $row['sent_at'] === null ? null : (string) $row['sent_at'],
        'sent_by_name'   => $row['sent_by_name'] === null ? null : (string) $row['sent_by_name'],
        'created_at'     => (string) $row['created_at'],
        'updated_at'     => (string) $row['updated_at'],
    ],
    'header'         => Doc::headerOf($row['header_json']),
    'header_fields'  => Doc::HEADER_EDITABLE,
    'lines'          => $lines,
    'totals'         => Doc::totals($lines),
    'can_write'      => Authz::purchaseAuthorize('purchase.buy.write', $user),
    'can_send'       => Authz::purchaseAuthorize('purchase.send', $user),
]);
