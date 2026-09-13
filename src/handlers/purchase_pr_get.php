<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_pr_get.php — GET /api/purchase/pr/{num}  (정책: purchase.read)
 *
 * ★ 20단계 발주요청 상세 (PRD F-30). 줄마다 **잔량**(요청 − 담김)과 잠금 여부가 함께 나간다.
 *
 * 지점 격리 (SECURITY R-65)
 *   `Authz::purchaseScopeWhere($user, 'd')` 를 상세 SQL 의 WHERE 에 붙인다.
 *   내 범위 밖 전표는 **404** 다 — "보이지 않는 것" 과 "없는 것" 을 구별해 주지 않는다
 *   (PRD S-09-5, 품목 상세와 같은 규칙).
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
           d.memo, d.closed_month,
           d.split_from_id, sp.doc_no AS split_from_no,
           d.created_at, d.updated_at
      FROM p_docs d
      LEFT JOIN p_branches  br  ON br.id  = d.branch_id
      LEFT JOIN p_vendors   ven ON ven.id = d.vendor_id
      LEFT JOIN p_locations loc ON loc.id = d.to_location_id
      LEFT JOIN users       mgr ON mgr.id = d.manager_id
      LEFT JOIN p_docs      sp  ON sp.id  = d.split_from_id
     WHERE d.id = CAST(:id AS bigint) AND d.doc_type = :type AND
    SQL;

$db  = Db::instance();
$row = $db->run($sqlDoc . ' ' . $scope['sql'], [':id' => $id, ':type' => Doc::TYPE_PR] + $scope['params'])->fetch();
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
        'split_from_id'  => $row['split_from_id'] === null ? null : (int) $row['split_from_id'],
        'split_from_no'  => $row['split_from_no'] === null ? null : (string) $row['split_from_no'],
        'created_at'     => (string) $row['created_at'],
        'updated_at'     => (string) $row['updated_at'],
    ],
    'lines'  => $lines,
    'totals' => Doc::totals($lines),
    // 화면 편의 값. 실제 차단은 라우트 정책과 줄의 `locked_at` 판정이 한다 (PRD S-01).
    'can_write' => Authz::purchaseAuthorize('purchase.request.write', $user),
]);
