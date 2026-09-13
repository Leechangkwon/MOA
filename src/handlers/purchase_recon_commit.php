<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_recon_commit.php — POST /api/purchase/recon/{num}/commit
 *   (정책: purchase.buy.write)
 *
 * ★ 22단계 거래처 월 대사 **3단계: 확정** (PRD F-35 · T-47 (b), 시안 Recon).
 *
 * 본문 { mapping: {...}, header_row?: int }  — 미리보기와 **같은 값**이다.
 *
 * 확정은 **한 트랜잭션**이다(19단계 가져오기 확정과 같은 규칙): 줄이 반쯤 들어간 대사표는
 * 합계도 차이 건수도 거짓말이 된다. 중간에 실패하면 전부 되돌린다.
 *
 * ★ 한 명세서는 **한 번만** 확정된다. 앱 계정에는 `p_recon_lines` 의 DELETE 권한이 없으므로
 *   (마이그레이션 GRANT) 다시 만들 수 없고, 다시 하려면 명세서를 **다시 올린다**(파일마다
 *   한 장 — `UNIQUE(vendor_id, month, file_id)`). 이미 줄이 있으면 400 `already_committed`.
 *
 * ★ R-69 — 들어가는 값은 품목 코드·품목명·수량·단가·금액과 판정(diff_type)·비고뿐이다.
 *   매핑에 다른 열 이름이 있으면 `Recon::cleanMapping` 이 요청 전체를 거부한다.
 *
 * ★ 「우리 금액」은 이 창구가 만들지 않는다 — `p_doc_lines.amount`(입고 확정 때 서버가
 *   수량 × 단가로 계산한 값)를 읽어 견줄 뿐이고, 명세서 값이 우리 장부로 흘러가는 경로는 없다
 *   (SECURITY R-66).
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params $params[0] = p_recon_statements.id
 */

require_once __DIR__ . '/../purchase/Recon.php';

$idRaw = $params[0] ?? '';
if (!is_string($idRaw) || preg_match('/^[0-9]{1,9}$/', $idRaw) !== 1) {
    Http::error(404, Http::MSG_NOT_FOUND);
}
$statementId = (int) $idRaw;

$db   = Db::instance();
$body = Http::jsonBody();

$reject = static function (string $reason) use ($statementId): void {
    Audit::deny('purchase.recon.commit', $reason, [
        'target_type' => 'purchase_recon',
        'target_id'   => $statementId,
        'target_name' => (string) $statementId,
        'detail'      => ['statement_id' => $statementId],
    ]);
    Http::error(400, Http::MSG_BAD_REQUEST);
};

if ($body === [] || array_diff(array_keys($body), ['mapping', 'header_row']) !== []) {
    $reject('bad_request');
}

$mapping = Recon::cleanMapping($body['mapping'] ?? null);
if ($mapping === null) {
    $reject('bad_mapping');
}

$headerRow = $body['header_row'] ?? 0;
if (is_string($headerRow) && preg_match('/^[0-9]{1,2}$/', $headerRow) === 1) {
    $headerRow = (int) $headerRow;
}
if (!is_int($headerRow) || $headerRow < 0 || $headerRow >= Recon::MAX_HEADER_ROW) {
    $reject('bad_header_row');
}

$statement = Recon::statement($db, $statementId);
if ($statement === null) {
    Http::error(404, Http::MSG_NOT_FOUND);
}
if (((int) $statement['line_count']) > 0) {
    $reject('already_committed');
}

try {
    $sheet = Recon::rows(Storage::pathFor((string) $statement['stored_name']), (string) $statement['ext']);
} catch (Throwable $e) {
    $reject('parse_failed');
    exit;
}
if ($sheet['truncated']) {
    // 반쯤 읽힌 파일로 대사를 확정하면 「우리만 있음」이 거짓으로 늘어난다 (가져오기와 같은 판단).
    $reject('truncated');
}

$theirs = Recon::parse($sheet['rows'], $mapping, $headerRow);
if ($theirs === []) {
    // 글자를 못 뽑은 PDF·매핑이 어긋난 엑셀. 빈 대사표를 만들지 않는다.
    $reject('no_rows');
}

$ours   = Recon::ourLines(
    $db,
    Authz::purchaseScopeWhere($user, 'd'),
    (int) $statement['vendor_id'],
    (string) $statement['month']
);
$result = Recon::match($theirs, $ours);

$sqlInsertLine = <<<'SQL'
    INSERT INTO p_recon_lines
        (statement_id, row_no, item_code, item_name, qty, unit_price, amount,
         matched_line_id, diff_type, note)
    VALUES
        (CAST(:st AS bigint), CAST(:no AS int), :code, :name,
         CAST(:qty AS numeric), CAST(:price AS numeric), CAST(:amount AS numeric),
         CAST(:matched AS bigint), :diff, :note)
    SQL;

$sqlUpdateStatement = <<<'SQL'
    UPDATE p_recon_statements
       SET mapping_json = CAST(:mapping AS jsonb),
           total_amount = CAST(:total AS numeric),
           updated_at   = now()
     WHERE id = CAST(:id AS bigint)
    SQL;

$db->begin();
try {
    foreach ($result['lines'] as $line) {
        $db->run($sqlInsertLine, [
            ':st'      => $statementId,
            ':no'      => (int) $line['row_no'],
            ':code'    => $line['item_code'] ?? null,
            ':name'    => (string) $line['item_name'],
            ':qty'     => (string) $line['qty'],
            ':price'   => $line['unit_price'] ?? null,
            ':amount'  => $line['amount'] ?? null,
            ':matched' => $line['matched_line_id'] ?? null,
            ':diff'    => (string) $line['diff_type'],
            ':note'    => $line['note'] ?? null,
        ]);
    }

    $db->run($sqlUpdateStatement, [
        ':mapping' => json_encode($mapping, JSON_UNESCAPED_UNICODE),
        ':total'   => $result['totals']['theirs'],
        ':id'      => $statementId,
    ]);

    Audit::allow('purchase.recon.commit', [
        'target_type' => 'purchase_recon',
        'target_id'   => $statementId,
        'target_name' => (string) $statement['original_name'],
        'detail'      => [
            'statement_id' => $statementId,
            'vendor_id'    => (int) $statement['vendor_id'],
            'month'        => (string) $statement['month'],
            'lines'        => count($result['lines']),
            'diff_counts'  => $result['diff_counts'],
            'amount'       => $result['totals']['theirs'],
        ],
    ]);
    $db->commit();
} catch (PDOException $e) {
    $db->rollback();
    if ($e->getCode() === '23505') {
        $reject('already_committed');
    }
    if ($e->getCode() === '23503') {
        $reject('bad_reference');
    }
    throw $e;
} catch (Throwable $e) {
    $db->rollback();
    throw $e;
}

Http::json([
    'statement_id' => $statementId,
    'vendor_id'    => (int) $statement['vendor_id'],
    'month'        => (string) $statement['month'],
    'lines'        => count($result['lines']),
    'totals'       => $result['totals'],
    'diff_counts'  => $result['diff_counts'],
], 201);
