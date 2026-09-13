<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_po_void.php — POST /api/purchase/po/{num}/void  (정책: purchase.buy.write)
 *
 * ★ 20단계 발주서 **취소** (PRD F-32, Q-18④).
 *
 * 취소는 삭제가 아니다 — `status = 'void'` 로 바꾸고 줄도 취소 표시한다.
 * 그러면 잔량 계산(`Doc::SQL_TAKEN` 이 `status <> 'void'` 만 센다)에서 이 발주서의 수량이
 * 빠지므로 **요청의 잔량이 되돌아온다**. 그래서 취소한 뒤에는 출처 요청 줄의 잠금·상태와
 * 요청 전표의 상태를 **다시 계산**한다 — 담긴 것이 하나도 없게 되면 `locked_at` 을 지워
 * 요청자의 수정 창이 다시 열린다.
 *
 * 「보냄」 뒤 취소는 `reason` 이 필수다 (Q-18④). 그 기록을 `scripts/alerts.php` 의
 * **AL-14** 가 보고 경보를 올린다.
 *
 * 본문 { reason?: "…" }
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
    Audit::deny('purchase.order.void', $reason, [
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
           CAST(d.doc_date AS text) AS doc_date
      FROM p_docs d
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
// ─── 22단계 마감 검사 (ARCH 5.18 「마감」, PRD F-35) ───────────
// 마감된 달의 전표는 `purchase.close` 를 가진 사람이 **사유를 적어야** 지날 수 있다.
// 판정은 `Doc::closeGuard` 한 곳이고(역할은 그 안에서 Authz 가 본다), 지나간 요청은
// 감사 detail 에 `after_close` 로 남아 경보 AL-14 가 본다.
$closeState = Doc::closeGuard(
    $db,
    $user,
    (int) $doc['branch_id'],
    isset($doc['doc_date']) ? (string) $doc['doc_date'] : null,
    $doc['closed_month'],
    $reason
);
if ($closeState === Doc::CLOSE_BLOCKED) {
    $reject('month_closed');
}
$afterSent = $doc['sent_at'] !== null;
if ($afterSent && $reason === null) {
    $reject('reason_required');
}

$sqlOwnLines = <<<'SQL'
    SELECT id, src_line_id
      FROM p_doc_lines
     WHERE doc_id = CAST(:doc AS bigint) AND status <> 'void'
     ORDER BY id ASC
    FOR UPDATE
    SQL;

$sqlVoidLines = <<<'SQL'
    UPDATE p_doc_lines SET status = 'void', updated_at = now()
     WHERE doc_id = CAST(:doc AS bigint) AND status <> 'void'
    SQL;

$sqlVoidDoc = <<<'SQL'
    UPDATE p_docs SET status = 'void', updated_at = now()
     WHERE id = CAST(:id AS bigint)
    SQL;

$sqlSrcLine = <<<'SQL'
    SELECT l.id, l.doc_id, CAST(l.qty AS text) AS qty
      FROM p_doc_lines l
     WHERE l.id = CAST(:id AS bigint)
    FOR UPDATE
    SQL;

$db->begin();
try {
    $own = $db->run($sqlOwnLines, [':doc' => $docId])->fetchAll();

    $db->run($sqlVoidLines, [':doc' => $docId]);
    $db->run($sqlVoidDoc, [':id' => $docId]);

    // 출처 요청 줄의 잠금·상태를 되돌리고, 그 요청 전표의 상태를 다시 계산한다.
    $srcDocs = [];
    foreach ($own as $line) {
        if ($line['src_line_id'] === null) {
            continue;   // 요청 없이 구매팀이 직접 넣은 줄 — 되돌릴 출처가 없다
        }
        $srcId = (int) $line['src_line_id'];
        $srcRow = $db->run($sqlSrcLine, [':id' => $srcId])->fetch();
        if (!is_array($srcRow)) {
            continue;
        }
        Doc::retouchRequestLine($db, $srcId, (string) $srcRow['qty'], Doc::takenQty($db, $srcId));
        $srcDocs[(int) $srcRow['doc_id']] = true;
    }
    foreach (array_keys($srcDocs) as $srcDocId) {
        Doc::recalcRequestStatus($db, (int) $srcDocId);
    }

    Audit::allow('purchase.order.void', [
        'target_type' => 'purchase_doc',
        'target_id'   => $docId,
        'target_name' => $docNoForLog,
        'detail'      => [
            'doc_no'      => $docNoForLog,
            'doc_type'    => Doc::TYPE_PO,
            'branch_id'   => (int) $doc['branch_id'],
            'lines'       => count($own),
            'after_sent'  => $afterSent,
            'reason_text' => $reason,
            'after_close' => $closeState === Doc::CLOSE_AFTER,
        ],
    ]);
    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    throw $e;
}

Http::json(['id' => $docId, 'doc_no' => $docNoForLog, 'status' => Doc::ST_VOID]);
