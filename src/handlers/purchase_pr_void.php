<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_pr_void.php — POST /api/purchase/pr/{num}/void
 *   (정책: purchase.request.write)
 *
 * ★ 20단계 발주요청 **취소** (PRD F-30, ARCH 5.18).
 *
 * 취소는 **삭제가 아니다.** `status = 'void'` 로 바꾸고 줄도 함께 취소 표시한다.
 * `p_docs`·`p_doc_lines` 에는 앱 계정의 DELETE 권한 자체가 없다 (db/grants.sql) —
 * 지운 전표는 잔량 계산에서 조용히 사라져 "발주 이력을 흔적 없이 바꾸는" 길이 되기 때문이다.
 *
 * 한 줄이라도 발주서에 담겼으면(`locked_at`) 400 `line_locked` 다 — 이미 거래처로 나간
 * 주문의 출처를 없앨 수 없다. 그런 경우는 **발주서 쪽을 취소**해야 한다.
 *
 * 본문 { reason?: "…" } — 사유는 감사 로그의 `reason_text` 로만 남는다.
 *
 * 지점 격리 (SECURITY R-65): `Authz::purchaseScopeWhere($user, 'd')` — 범위 밖은 404.
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

if ($body !== [] && array_diff(array_keys($body), ['reason']) !== []) {
    Audit::deny('purchase.request.void', 'bad_request', [
        'target_type' => 'purchase_doc',
        'target_id'   => $docId,
        'target_name' => (string) $docId,
    ]);
    Http::error(400, Http::MSG_BAD_REQUEST);
}
$reason = $body['reason'] ?? null;
if ($reason !== null && !is_string($reason)) {
    Audit::deny('purchase.request.void', 'bad_request', [
        'target_type' => 'purchase_doc',
        'target_id'   => $docId,
        'target_name' => (string) $docId,
    ]);
    Http::error(400, Http::MSG_BAD_REQUEST);
}
if (is_string($reason) && (preg_match('//u', $reason) !== 1 || iconv_strlen($reason, 'UTF-8') > 300)) {
    Audit::deny('purchase.request.void', 'bad_request', [
        'target_type' => 'purchase_doc',
        'target_id'   => $docId,
        'target_name' => (string) $docId,
    ]);
    Http::error(400, Http::MSG_BAD_REQUEST);
}

$scope = Authz::purchaseScopeWhere($user, 'd');

$sqlDoc = <<<'SQL'
    SELECT d.id, d.doc_no, d.status, d.branch_id, d.closed_month
      FROM p_docs d
     WHERE d.id = CAST(:id AS bigint) AND d.doc_type = :type AND
    SQL;

$doc = $db->run(
    $sqlDoc . ' ' . $scope['sql'],
    [':id' => $docId, ':type' => Doc::TYPE_PR] + $scope['params']
)->fetch();
if (!is_array($doc)) {
    Http::error(404, Http::MSG_NOT_FOUND);
}
$docNo = (string) $doc['doc_no'];

$reject = static function (string $why) use ($docId, $docNo, $doc): void {
    Audit::deny('purchase.request.void', $why, [
        'target_type' => 'purchase_doc',
        'target_id'   => $docId,
        'target_name' => $docNo,
        'detail'      => ['doc_no' => $docNo, 'doc_type' => 'pr', 'branch_id' => (int) $doc['branch_id']],
    ]);
    Http::error(400, Http::MSG_BAD_REQUEST);
};

if ((string) $doc['status'] === Doc::ST_VOID) {
    $reject('status_invalid');
}
if ($doc['closed_month'] !== null) {
    $reject('status_invalid');
}

$sqlLines = <<<'SQL'
    SELECT id, locked_at, status
      FROM p_doc_lines
     WHERE doc_id = CAST(:doc AS bigint)
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

$db->begin();
try {
    $rows = $db->run($sqlLines, [':doc' => $docId])->fetchAll();
    foreach ($rows as $r) {
        if ($r['locked_at'] !== null && (string) $r['status'] !== Doc::LINE_VOID) {
            $db->rollback();
            $reject('line_locked');
        }
    }
    $db->run($sqlVoidLines, [':doc' => $docId]);
    $db->run($sqlVoidDoc, [':id' => $docId]);

    Audit::allow('purchase.request.void', [
        'target_type' => 'purchase_doc',
        'target_id'   => $docId,
        'target_name' => $docNo,
        // 사유는 `detail.reason_text` 에만 남는다 (ARCH 7.5 등록부 — Audit::write 가 받는
        // 최상위 키는 target_type·target_id·target_name·access_level·detail 뿐이다).
        'detail'      => [
            'doc_no'      => $docNo,
            'doc_type'    => Doc::TYPE_PR,
            'branch_id'   => (int) $doc['branch_id'],
            'lines'       => count($rows),
            'reason_text' => is_string($reason) ? $reason : null,
        ],
    ]);
    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    throw $e;
}

Http::json(['id' => $docId, 'doc_no' => $docNo, 'status' => Doc::ST_VOID]);
