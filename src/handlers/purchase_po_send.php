<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_po_send.php — POST /api/purchase/po/{num}/send  (정책: purchase.send)
 *
 * ★ 20단계 **송부 전 검토 → 「보냄」** (Q-18④ 확정, ARCH 5.18 「송부 전 검토」).
 *
 * 누가: `purchase.send` = **viewer · buyer · Admin** (판정은 `Authz::purchaseAuthorize` 한 곳).
 *       requester·stock 은 라우터에서 403 이고 그 시도가 `authz.route deny` 로 남는다.
 *
 * 규칙
 *   · 첫 「보냄」 — `sent_at`·`sent_by` 를 놓는다. 본문은 비어도 된다.
 *   · 두 번째 「보냄」 — 기본은 400 `already_sent`. **사유(`reason`)를 적으면** 재송부로
 *     통과하고 `sent_at` 이 새로 놓이며 사유가 감사 로그(`detail.reason_text`)에 남는다.
 *     "한 번만" 을 지키되, 거래처가 못 받았다는 현실을 사유와 기록으로 다룬다.
 *   · 취소된 전표·마감된 달은 보낼 수 없다(400 `status_invalid`).
 *   · 줄이 하나도 없는 발주서는 보낼 수 없다(400 `status_invalid`) — 빈 문서를 보낸 흔적이
 *     남으면 나중에 대사가 불가능하다.
 *
 * 이 창구는 **메일을 보내지 않는다.** 외부 요청 코드를 모듈에 만들지 않는 것이 규칙이고
 * (PRD S-05), 여기서 하는 일은 "보냈다고 표시하고 그 사실을 남기는 것" 이다.
 * 실제 전달은 사람이 인쇄 화면(`/purchase/po/{id}/print`)에서 PDF 로 저장해 보낸다.
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
$docId = (int) $idRaw;

$db   = Db::instance();
$body = Http::jsonBody();

$docNoForLog = '';
$reject = static function (string $reason) use ($docId, &$docNoForLog): void {
    Audit::deny('purchase.order.send', $reason, [
        'target_type' => 'purchase_doc',
        'target_id'   => $docId,
        'target_name' => $docNoForLog !== '' ? $docNoForLog : (string) $docId,
        'detail'      => ['doc_type' => 'po', 'doc_no' => $docNoForLog],
    ]);
    Http::error(400, Http::MSG_BAD_REQUEST);
};

if ($body !== [] && array_diff(array_keys($body), ['reason']) !== []) {
    $reject('bad_request');
}
$reason = $body['reason'] ?? null;
if ($reason !== null && !is_string($reason)) {
    $reject('bad_request');
}
if (is_string($reason) && (preg_match('//u', $reason) !== 1 || iconv_strlen($reason, 'UTF-8') > 300)) {
    $reject('bad_request');
}
$reason = ($reason === '' ? null : $reason);

$scope = Authz::purchaseScopeWhere($user, 'd');

$sqlDoc = <<<'SQL'
    SELECT d.id, d.doc_no, d.status, d.branch_id, d.closed_month, d.sent_at,
           ven.name AS vendor_name,
           (SELECT count(*) FROM p_doc_lines x WHERE x.doc_id = d.id AND x.status <> 'void') AS line_count,
           (SELECT COALESCE(sum(x.amount), 0) FROM p_doc_lines x
             WHERE x.doc_id = d.id AND x.status <> 'void') AS amount
      FROM p_docs d
      LEFT JOIN p_vendors ven ON ven.id = d.vendor_id
     WHERE d.id = CAST(:id AS bigint) AND d.doc_type = :type AND
    SQL;

$doc = $db->run(
    $sqlDoc . ' ' . $scope['sql'],
    [':id' => $docId, ':type' => Doc::TYPE_PO] + $scope['params']
)->fetch();
if (!is_array($doc)) {
    Http::error(404, Http::MSG_NOT_FOUND);
}
$docNoForLog = (string) $doc['doc_no'];

if ((string) $doc['status'] === Doc::ST_VOID) {
    $reject('status_invalid');
}
if ($doc['closed_month'] !== null) {
    $reject('status_invalid');
}
if ((int) $doc['line_count'] < 1) {
    $reject('status_invalid');
}

$resend = $doc['sent_at'] !== null;
if ($resend && $reason === null) {
    $reject('already_sent');
}

$sqlSend = <<<'SQL'
    UPDATE p_docs SET sent_at = now(), sent_by = CAST(:by AS bigint), updated_at = now()
     WHERE id = CAST(:id AS bigint) AND status <> 'void'
    SQL;

$db->begin();
try {
    $db->run($sqlSend, [':by' => (int) $user['id'], ':id' => $docId]);

    Audit::allow('purchase.order.send', [
        'target_type' => 'purchase_doc',
        'target_id'   => $docId,
        'target_name' => $docNoForLog,
        'detail'      => [
            'doc_no'      => $docNoForLog,
            'doc_type'    => Doc::TYPE_PO,
            'branch_id'   => (int) $doc['branch_id'],
            'lines'       => (int) $doc['line_count'],
            'amount'      => (string) $doc['amount'],
            // 어디로 보낸 문서인가 — 거래처 **이름**이며 연락처가 아니다 (ARCH 7.5).
            'sent_to'     => $doc['vendor_name'] === null ? null : (string) $doc['vendor_name'],
            'resent'      => $resend,
            'reason_text' => $reason,
        ],
    ]);
    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    throw $e;
}

$sqlAfter = <<<'SQL'
    SELECT d.sent_at, u.username AS sent_by_name
      FROM p_docs d
      LEFT JOIN users u ON u.id = d.sent_by
     WHERE d.id = CAST(:id AS bigint)
    SQL;

$after = $db->run($sqlAfter, [':id' => $docId])->fetch();

Http::json([
    'id'           => $docId,
    'doc_no'       => $docNoForLog,
    'sent_at'      => is_array($after) ? (string) $after['sent_at'] : null,
    'sent_by_name' => is_array($after) && $after['sent_by_name'] !== null
        ? (string) $after['sent_by_name'] : null,
    'resent'       => $resend,
]);
