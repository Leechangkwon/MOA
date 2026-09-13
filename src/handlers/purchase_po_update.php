<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_po_update.php — PATCH /api/purchase/po/{num}  (정책: purchase.buy.write)
 *
 * ★ 20단계 발주서 **머리 수정** (Q-18② 「발행 전 수정 가능」, Q-18④ 「보냄 뒤는 사유 필요」).
 *
 * 본문 { header?: {…허용 키만…}, memo?, reason? }
 *
 *   · `header` 에서 고칠 수 있는 키는 `Doc::HEADER_EDITABLE` 여섯 개뿐이다
 *     (`receiver`·`cc`·`tel`·`delivery_location`·`due_date`·`memo`). 목록 밖 키가 하나라도
 *     있으면 요청 전체를 거부한다 (SECURITY R-07). **금액 세 칸은 여기 없다** —
 *     공급가액·부가세·합계는 언제나 줄에서 다시 계산한다 (R-66).
 *   · 「보냄」 **전**이면 그냥 고친다.
 *   · 「보냄」 **뒤**면 `reason` 이 반드시 있어야 한다(없으면 400 `reason_required`).
 *     이미 거래처에 나간 문서를 바꾸는 일이므로 사유가 감사 로그에 남아야 하고,
 *     `scripts/alerts.php` 의 **AL-14** 가 그 기록을 보고 경보를 올린다.
 *   · 줄(품목·수량)은 이 창구로 고치지 않는다 — 담긴 잔량과 요청 상태가 함께 움직여야 하므로
 *     발주서를 **취소하고 다시 만드는** 것이 규칙이다(21단계 입고가 붙으면 더 그렇다).
 *
 * 지점 격리 (SECURITY R-65): `Authz::purchaseScopeWhere($user, 'd')`. 범위 밖은 404.
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params $params[0] = p_docs.id
 */

require_once __DIR__ . '/../purchase/Doc.php';

const TBOX_PO_PATCH_KEYS = ['header', 'memo', 'reason'];

$idRaw = $params[0] ?? '';
if (!is_string($idRaw) || preg_match('/^[0-9]{1,9}$/', $idRaw) !== 1) {
    Http::error(404, Http::MSG_NOT_FOUND);
}
$docId = (int) $idRaw;

$db   = Db::instance();
$body = Http::jsonBody();

$docNoForLog = '';
$reject = static function (string $reason) use ($docId, &$docNoForLog): void {
    Audit::deny('purchase.order.update', $reason, [
        'target_type' => 'purchase_doc',
        'target_id'   => $docId,
        'target_name' => $docNoForLog !== '' ? $docNoForLog : (string) $docId,
        'detail'      => ['doc_type' => 'po', 'doc_no' => $docNoForLog],
    ]);
    Http::error(400, Http::MSG_BAD_REQUEST);
};

if ($body === [] || array_diff(array_keys($body), TBOX_PO_PATCH_KEYS) !== []) {
    $reject('bad_request');
}

$scope = Authz::purchaseScopeWhere($user, 'd');

$sqlDoc = <<<'SQL'
    SELECT d.id, d.doc_no, d.status, d.branch_id, d.closed_month, d.memo,
           d.sent_at, CAST(d.doc_date AS text) AS doc_date,
           CAST(d.header_json AS text) AS header_json
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
$asText = static function ($v, int $max): bool {
    if ($v === null) {
        return true;
    }
    if (!is_string($v)) {
        return false;
    }
    return preg_match('//u', $v) === 1 && iconv_strlen($v, 'UTF-8') <= $max;
};

$reason = $body['reason'] ?? null;
if (!$asText($reason, 300)) {
    $reject('bad_request');
}
$reason = $reason === '' ? null : $reason;

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

// 「보냄」 뒤 수정은 사유가 필수다 (Q-18④, AL-14)
$afterSent = $doc['sent_at'] !== null;
if ($afterSent && ($reason === null || $reason === '')) {
    $reject('reason_required');
}

$header = Doc::headerOf($doc['header_json']);
$before = $header;
$touched = false;

if (array_key_exists('header', $body)) {
    if (!is_array($body['header'])) {
        $reject('bad_request');
    }
    if (array_diff(array_keys($body['header']), Doc::HEADER_EDITABLE) !== []) {
        $reject('bad_request');
    }
    foreach ($body['header'] as $key => $value) {
        if (!$asText($value, 200)) {
            $reject('bad_request');
        }
        if ($key === 'due_date' && is_string($value) && $value !== ''
            && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $value) !== 1
        ) {
            $reject('bad_request');
        }
        $header[$key] = ($value === '' ? null : $value);
        $touched      = true;
    }
}

$newMemo = $doc['memo'];
if (array_key_exists('memo', $body)) {
    if (!$asText($body['memo'], 500)) {
        $reject('bad_request');
    }
    $newMemo = $body['memo'] === '' ? null : $body['memo'];
    $touched = true;
}

if (!$touched) {
    $reject('no_change');
}

$sqlUpdate = <<<'SQL'
    UPDATE p_docs SET memo = :m, updated_at = now()
     WHERE id = CAST(:id AS bigint)
    SQL;

$db->begin();
try {
    $db->run($sqlUpdate, [':m' => $newMemo, ':id' => $docId]);
    Doc::saveHeader($db, $docId, $header);

    Audit::allow('purchase.order.update', [
        'target_type' => 'purchase_doc',
        'target_id'   => $docId,
        'target_name' => $docNoForLog,
        'detail'      => [
            'doc_no'      => $docNoForLog,
            'doc_type'    => Doc::TYPE_PO,
            'branch_id'   => (int) $doc['branch_id'],
            'after_sent'  => $afterSent,
            'reason_text' => $reason,
            'after_close' => $closeState === Doc::CLOSE_AFTER,
            'before'      => $before,
            'after'       => $header,
        ],
    ]);
    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    throw $e;
}

$lines = Doc::lines($db, $docId);
Http::json([
    'id'     => $docId,
    'doc_no' => $docNoForLog,
    'header' => $header,
    'memo'   => $newMemo,
    'totals' => Doc::totals($lines),
]);
