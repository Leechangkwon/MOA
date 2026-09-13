<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_pr_update.php — PATCH /api/purchase/pr/{num}
 *   (정책: purchase.request.write → requester·buyer·Admin)
 *
 * ★ 20단계 **요청 수정 창** (Q-18① 확정, ARCH 5.18 「요청 수정 창」, PRD F-30).
 *
 * 규칙 셋
 *   ① 줄 수정·취소는 `status = 'unconfirmed' AND locked_at IS NULL` 인 줄만.
 *      구매실이 그 줄을 발주서에 담는 순간 `locked_at` 이 박히고, 그 뒤에는 400 `line_locked` 다.
 *   ② 전표 머리(일자·거래처·장소·비고)는 전표가 **미확인**일 때만 고칠 수 있다.
 *      한 줄이라도 담겼으면 400 `status_invalid` — 발주가 걸린 전표의 조건을 바꾸지 않는다.
 *   ③ **줄 추가**는 전표에 잠긴 줄이 하나라도 있으면 같은 전표에 붙이지 않고
 *      **새 요청 전표**(`split_from_id` = 이 전표)를 만들어 담고, 응답에 `new_doc_no` 를 준다.
 *      발주서를 만드는 중에 원본 전표가 커지면 무엇이 발주됐는지가 흐려진다(누락·병목 방지).
 *
 * 본문
 *   { doc_date?, vendor_id?, to_location_id?, memo?,
 *     lines?: [ { id, qty?, remark?, remove? } … ],
 *     add_lines?: [ { item_id, qty, remark? } … ] }
 *
 * 지점 격리 (SECURITY R-65): 전표 조회에 `Authz::purchaseScopeWhere($user, 'd')`.
 *   내 범위 밖 전표는 404 다 — 남의 지점 요청을 id 로 찍어 고칠 수 없다.
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params $params[0] = p_docs.id
 */

require_once __DIR__ . '/../purchase/Doc.php';

const TBOX_PR_PATCH_KEYS    = ['doc_date', 'vendor_id', 'to_location_id', 'memo', 'lines', 'add_lines'];
const TBOX_PR_PATCH_LINE    = ['id', 'qty', 'remark', 'remove'];
const TBOX_PR_PATCH_ADD     = ['item_id', 'qty', 'remark'];
const TBOX_PR_HEADER_FIELDS = ['doc_date', 'vendor_id', 'to_location_id', 'memo'];

$idRaw = $params[0] ?? '';
if (!is_string($idRaw) || preg_match('/^[0-9]{1,9}$/', $idRaw) !== 1) {
    Http::error(404, Http::MSG_NOT_FOUND);
}
$docId = (int) $idRaw;

$db   = Db::instance();
$body = Http::jsonBody();

$docNoForLog = '';
$reject = static function (string $reason, int $status = 400) use ($docId, &$docNoForLog): void {
    Audit::deny('purchase.request.update', $reason, [
        'target_type' => 'purchase_doc',
        'target_id'   => $docId,
        'target_name' => $docNoForLog !== '' ? $docNoForLog : (string) $docId,
        'detail'      => ['doc_type' => 'pr', 'doc_no' => $docNoForLog],
    ]);
    Http::error($status, Http::MSG_BAD_REQUEST);
};

if ($body === [] || array_diff(array_keys($body), TBOX_PR_PATCH_KEYS) !== []) {
    $reject('bad_request');
}

// ─── 전표 읽기 (지점 격리) ───────────────────────────────────
$scopeD  = Authz::purchaseScopeWhere($user, 'd');
$scopeL  = Authz::purchaseScopeWhere($user, 'l');
$scopeIb = Authz::purchaseScopeWhere($user, 'ib');

$sqlDoc = <<<'SQL'
    SELECT d.id, d.doc_no, d.status, d.branch_id, d.closed_month, d.manager_id,
           CAST(d.doc_date AS text) AS doc_date, d.vendor_id, d.to_location_id, d.memo
      FROM p_docs d
     WHERE d.id = CAST(:id AS bigint) AND d.doc_type = :type AND
    SQL;

$doc = $db->run(
    $sqlDoc . ' ' . $scopeD['sql'],
    [':id' => $docId, ':type' => Doc::TYPE_PR] + $scopeD['params']
)->fetch();
if (!is_array($doc)) {
    Http::error(404, Http::MSG_NOT_FOUND);
}
$docNoForLog = (string) $doc['doc_no'];

if ((string) $doc['status'] === Doc::ST_VOID) {
    $reject('status_invalid');
}
// ─── 22단계 마감 검사 (ARCH 5.18 「마감」, PRD F-35) ───────────
// 이 창구에는 **사유 칸이 없다**(요청 수정의 허용 키는 TBOX_PR_PATCH_KEYS 여섯뿐이다).
// 사유가 없으면 `closeGuard` 는 자격과 무관하게 blocked 이므로, 마감된 달의 요청은
// 언제나 400 `month_closed` 다 — 고치려면 마감을 연 사람이 사유를 남기는 다른 창구
// (발주서 수정·취소)로 가야 한다. 22단계에서 사유 칸을 새로 열지 않았다.
if (Doc::closeGuard($db, $user, (int) $doc['branch_id'], (string) $doc['doc_date'], $doc['closed_month'], null)
    !== Doc::CLOSE_OPEN
) {
    $reject('month_closed');
}

// ─── 값 좁히기 ───────────────────────────────────────────────
$asId = static function ($v): ?int {
    if ($v === null || $v === '') {
        return null;
    }
    if (!is_int($v) && !(is_string($v) && preg_match('/^[0-9]{1,9}$/', $v) === 1)) {
        return -1;
    }
    $n = (int) $v;
    return $n >= 1 ? $n : -1;
};
$asQty = static function ($v): ?string {
    if (is_int($v) || is_float($v)) {
        $v = (string) $v;
    }
    if (!is_string($v) || preg_match('/^[0-9]{1,10}(\.[0-9]{1,3})?$/', $v) !== 1) {
        return null;
    }
    return $v;
};
$asText = static function ($v, int $max): bool {
    if ($v === null) {
        return true;
    }
    if (!is_string($v)) {
        return false;
    }
    return preg_match('//u', $v) === 1 && iconv_strlen($v, 'UTF-8') <= $max;
};

$touchesHeader = array_intersect(array_keys($body), TBOX_PR_HEADER_FIELDS) !== [];
if ($touchesHeader && (string) $doc['status'] !== Doc::ST_UNCONFIRMED) {
    $reject('status_invalid');
}

$newDate   = $doc['doc_date'];
$newVendor = $doc['vendor_id'] === null ? null : (int) $doc['vendor_id'];
$newLoc    = $doc['to_location_id'] === null ? null : (int) $doc['to_location_id'];
$newMemo   = $doc['memo'];

if (array_key_exists('doc_date', $body)) {
    $v = $body['doc_date'];
    if (!is_string($v) || preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $v) !== 1) {
        $reject('bad_request');
    }
    $newDate = $v;
}
if (array_key_exists('vendor_id', $body)) {
    $v = $asId($body['vendor_id']);
    if ($v === -1) {
        $reject('bad_request');
    }
    if ($v !== null && !Doc::vendorOk($db, $v)) {
        $reject('bad_reference');
    }
    $newVendor = $v;
}
if (array_key_exists('to_location_id', $body)) {
    $v = $asId($body['to_location_id']);
    if ($v === -1) {
        $reject('bad_request');
    }
    if ($v !== null && !Doc::locationOk($db, $scopeL, $v, (int) $doc['branch_id'])) {
        $reject('bad_reference');
    }
    $newLoc = $v;
}
if (array_key_exists('memo', $body)) {
    if (!$asText($body['memo'], 500)) {
        $reject('bad_request');
    }
    $newMemo = $body['memo'] === '' ? null : $body['memo'];
}

$edits = [];
if (array_key_exists('lines', $body)) {
    if (!is_array($body['lines'])) {
        $reject('bad_request');
    }
    foreach ($body['lines'] as $line) {
        if (!is_array($line) || array_diff(array_keys($line), TBOX_PR_PATCH_LINE) !== []) {
            $reject('bad_request');
        }
        $lineId = $asId($line['id'] ?? null);
        if ($lineId === null || $lineId === -1) {
            $reject('bad_request');
        }
        $remove = ($line['remove'] ?? null) === true;
        $qty    = null;
        if (array_key_exists('qty', $line)) {
            $qty = $asQty($line['qty']);
            if ($qty === null || Doc::milli($qty) <= 0) {
                $reject('bad_request');
            }
        }
        if (array_key_exists('remark', $line) && !$asText($line['remark'], 200)) {
            $reject('bad_request');
        }
        $edits[] = [
            'id'         => $lineId,
            'qty'        => $qty,
            'has_remark' => array_key_exists('remark', $line),
            'remark'     => ($line['remark'] ?? null) === '' ? null : ($line['remark'] ?? null),
            'remove'     => $remove,
        ];
    }
}

$adds = [];
if (array_key_exists('add_lines', $body)) {
    if (!is_array($body['add_lines']) || count($body['add_lines']) > Doc::MAX_LINES) {
        $reject('bad_request');
    }
    foreach ($body['add_lines'] as $line) {
        if (!is_array($line) || array_diff(array_keys($line), TBOX_PR_PATCH_ADD) !== []) {
            $reject('bad_request');
        }
        $itemId = $asId($line['item_id'] ?? null);
        $qty    = $asQty($line['qty'] ?? null);
        if ($itemId === null || $itemId === -1 || $qty === null || Doc::milli($qty) <= 0) {
            $reject('bad_request');
        }
        if (array_key_exists('remark', $line) && !$asText($line['remark'], 200)) {
            $reject('bad_request');
        }
        $adds[] = [
            'item_id' => $itemId,
            'qty'     => $qty,
            'remark'  => ($line['remark'] ?? null) === '' ? null : ($line['remark'] ?? null),
        ];
    }
}

if ($edits === [] && $adds === [] && !$touchesHeader) {
    $reject('no_change');
}

$items = [];
if ($adds !== []) {
    $items = Doc::loadItems($db, $scopeIb, array_column($adds, 'item_id'));
    foreach ($adds as $a) {
        if (!isset($items[$a['item_id']])) {
            $reject('bad_reference');
        }
    }
}

// ─── 트랜잭션 ────────────────────────────────────────────────
$sqlDocLines = <<<'SQL'
    SELECT id, locked_at, status, CAST(qty AS text) AS qty
      FROM p_doc_lines
     WHERE doc_id = CAST(:doc AS bigint)
     ORDER BY id ASC
    FOR UPDATE
    SQL;

$sqlUpdateHead = <<<'SQL'
    UPDATE p_docs
       SET doc_date = CAST(:d AS date), vendor_id = CAST(:v AS bigint),
           to_location_id = CAST(:l AS bigint), memo = :m, updated_at = now()
     WHERE id = CAST(:id AS bigint)
    SQL;

$sqlUpdateLine = <<<'SQL'
    UPDATE p_doc_lines
       SET qty = CAST(:qty AS numeric), amount = CAST(:amount AS numeric),
           remark = :remark, updated_at = now()
     WHERE id = CAST(:id AS bigint)
    SQL;

$sqlVoidLine = <<<'SQL'
    UPDATE p_doc_lines SET status = 'void', updated_at = now()
     WHERE id = CAST(:id AS bigint)
    SQL;

$sqlLinePrice = <<<'SQL'
    SELECT CAST(l.unit_price AS text) AS unit_price, l.remark
      FROM p_doc_lines l
     WHERE l.id = CAST(:id AS bigint)
    SQL;

$newDocId = null;
$newDocNo = null;

$db->begin();
try {
    $rows   = $db->run($sqlDocLines, [':doc' => $docId])->fetchAll();
    $byId   = [];
    $locked = false;
    foreach ($rows as $r) {
        $byId[(int) $r['id']] = $r;
        if ($r['locked_at'] !== null && (string) $r['status'] !== Doc::LINE_VOID) {
            $locked = true;
        }
    }

    // ① 줄 수정·취소 — 잠긴 줄은 손대지 못한다
    foreach ($edits as $e) {
        $row = $byId[$e['id']] ?? null;
        if ($row === null) {
            $db->rollback();
            $reject('bad_reference');
        }
        if ($row['locked_at'] !== null || (string) $row['status'] !== Doc::LINE_UNCONFIRMED) {
            $db->rollback();
            $reject('line_locked');
        }
        if ($e['remove']) {
            $db->run($sqlVoidLine, [':id' => $e['id']]);
            continue;
        }
        $cur   = $db->run($sqlLinePrice, [':id' => $e['id']])->fetch();
        $price = is_array($cur) && $cur['unit_price'] !== null ? (string) $cur['unit_price'] : null;
        $qty   = $e['qty'] ?? (string) $row['qty'];
        $mark  = $e['has_remark'] ? $e['remark'] : (is_array($cur) ? $cur['remark'] : null);
        $db->run($sqlUpdateLine, [
            ':qty'    => $qty,
            ':amount' => Doc::lineAmount($qty, $price),
            ':remark' => $mark,
            ':id'     => $e['id'],
        ]);
    }

    // ② 전표 머리
    if ($touchesHeader) {
        $db->run($sqlUpdateHead, [
            ':d'  => $newDate,
            ':v'  => $newVendor,
            ':l'  => $newLoc,
            ':m'  => $newMemo,
            ':id' => $docId,
        ]);
    }

    // ③ 줄 추가 — 잠긴 줄이 있으면 **새 전표**로 갈라 붙인다 (Q-18①)
    if ($adds !== []) {
        $targetDoc = $docId;
        if ($locked) {
            $newDocNo  = Doc::nextDocNo($db, Doc::TYPE_PR);
            $newDocId  = Doc::insertDoc($db, [
                'doc_type'       => Doc::TYPE_PR,
                'doc_no'         => $newDocNo,
                'doc_date'       => date('Y-m-d'),
                'branch_id'      => (int) $doc['branch_id'],
                'vendor_id'      => $newVendor,
                'to_location_id' => $newLoc,
                'manager_id'     => (int) $doc['manager_id'],
                'status'         => Doc::ST_UNCONFIRMED,
                'memo'           => $newMemo,
                'split_from_id'  => $docId,
                'created_by'     => (int) $user['id'],
            ]);
            $targetDoc = $newDocId;
        }
        $lineNo = Doc::nextLineNo($db, $targetDoc);
        foreach ($adds as $a) {
            $item  = $items[$a['item_id']];
            $price = $item['price_in'] === null ? null : (string) $item['price_in'];
            Doc::insertLine($db, $targetDoc, $lineNo, $a['item_id'], $a['qty'], $price, $a['remark'], null);
            $lineNo++;
        }
    }

    $status = Doc::recalcRequestStatus($db, $docId);

    Audit::allow('purchase.request.update', [
        'target_type' => 'purchase_doc',
        'target_id'   => $docId,
        'target_name' => $docNoForLog,
        'detail'      => [
            'doc_no'     => $docNoForLog,
            'doc_type'   => Doc::TYPE_PR,
            'branch_id'  => (int) $doc['branch_id'],
            'lines'      => count($edits),
            'added'      => count($adds),
            'split_to'   => $newDocNo,
            'before'     => [
                'doc_date' => (string) $doc['doc_date'],
                'vendor_id' => $doc['vendor_id'] === null ? null : (int) $doc['vendor_id'],
                'to_location_id' => $doc['to_location_id'] === null ? null : (int) $doc['to_location_id'],
                'memo' => $doc['memo'],
            ],
            'after'      => [
                'doc_date' => $newDate,
                'vendor_id' => $newVendor,
                'to_location_id' => $newLoc,
                'memo' => $newMemo,
            ],
        ],
    ]);
    $db->commit();
} catch (PDOException $e) {
    $db->rollback();
    if ($e->getCode() === '23503') {
        $reject('bad_reference');
    }
    throw $e;
} catch (Throwable $e) {
    $db->rollback();
    throw $e;
}

$lines = Doc::lines($db, $docId);
Http::json([
    'id'         => $docId,
    'doc_no'     => $docNoForLog,
    'status'     => $status,
    'lines'      => $lines,
    'totals'     => Doc::totals($lines),
    // 줄이 갈라져 새 전표로 갔으면 화면이 그 사실을 사람에게 알린다 (Q-18①).
    'new_doc_id' => $newDocId,
    'new_doc_no' => $newDocNo,
]);
