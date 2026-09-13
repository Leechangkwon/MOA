<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_recon_get.php — GET /api/purchase/recon/{num}  (정책: purchase.read)
 *
 * ★ 22단계 **확정된 대사표 한 장** (PRD F-35, 시안 Recon).
 *
 * 확정 전(줄이 아직 없는 명세서)이면 저장된 매핑 후보와 파일 정보만 돌려준다 — 화면은
 * 그때 `…/preview` 로 대조 결과를 본다. 확정 뒤에는 **저장된 줄**을 그대로 보여 준다
 * (그 시점의 판정을 증거로 남기는 것이 대사표의 뜻이다).
 *
 * 지점 격리 (SECURITY R-65)
 *   확정된 줄에 붙은 **우리 입고 줄**은 `Authz::purchaseScopeWhere($user, 'd')` 를 지나
 *   붙인다 — 범위 밖 지점의 전표 번호·지점 이름은 응답에 나오지 않는다(줄 자체는 명세서
 *   쪽 값이라 남는다).
 *
 * 조회는 감사 로그를 남기지 않는다.
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

$db = Db::instance();

$statement = Recon::statement($db, $statementId);
if ($statement === null) {
    Http::error(404, Http::MSG_NOT_FOUND);
}

$scopeD = Authz::purchaseScopeWhere($user, 'd');

/** 저장된 줄 + (범위 안이면) 붙어 있는 우리 입고 줄의 전표 번호·지점 */
$sqlLinesHead = <<<'SQL'
    SELECT r.id, r.row_no, r.item_code, r.item_name,
           CAST(r.qty AS text)        AS qty,
           CAST(r.unit_price AS text) AS unit_price,
           CAST(r.amount AS text)     AS amount,
           r.matched_line_id, r.diff_type, r.note,
           CAST(l.qty AS text)        AS our_qty,
           CAST(l.unit_price AS text) AS our_unit_price,
           CAST(l.amount AS text)     AS our_amount,
           d.doc_no  AS our_doc_no,
           d.branch_id,
           b.name    AS branch_name
      FROM p_recon_lines r
      LEFT JOIN p_doc_lines l ON l.id = r.matched_line_id
      LEFT JOIN p_docs      d ON d.id = l.doc_id AND
    SQL;

$sqlLinesTail = <<<'SQL'
      LEFT JOIN p_branches  b ON b.id = d.branch_id
     WHERE r.statement_id = CAST(:st AS bigint)
     ORDER BY r.row_no ASC
     LIMIT 2000
    SQL;

$rows = $db->run(
    $sqlLinesHead . ' ' . $scopeD['sql'] . ' ' . $sqlLinesTail,
    [':st' => $statementId] + $scopeD['params']
)->fetchAll();

$lines  = [];
$counts = ['none' => 0, 'qty' => 0, 'price' => 0, 'missing_ours' => 0, 'missing_theirs' => 0];
$sumTheirs = 0.0;
$sumOurs   = 0.0;

foreach ($rows as $row) {
    $diff = (string) ($row['diff_type'] ?? 'none');
    if (isset($counts[$diff])) {
        $counts[$diff]++;
    }
    $theirAmount = $row['amount'] === null ? null : (float) $row['amount'];
    $ourAmount   = $row['our_amount'] === null ? null : (float) $row['our_amount'];
    if ($diff !== 'missing_theirs' && $theirAmount !== null) {
        $sumTheirs += $theirAmount;
    }
    if ($ourAmount !== null) {
        $sumOurs += $ourAmount;
    }
    $lines[] = [
        'id'             => (int) $row['id'],
        'row_no'         => (int) $row['row_no'],
        'item_code'      => $row['item_code'] === null ? null : (string) $row['item_code'],
        'item_name'      => (string) $row['item_name'],
        'qty'            => (string) $row['qty'],
        'unit_price'     => $row['unit_price'] === null ? null : (string) $row['unit_price'],
        'amount'         => $row['amount'] === null ? null : (string) $row['amount'],
        'diff_type'      => $diff,
        'note'           => $row['note'] === null ? null : (string) $row['note'],
        'our_qty'        => $row['our_qty'] === null ? null : (string) $row['our_qty'],
        'our_unit_price' => $row['our_unit_price'] === null ? null : (string) $row['our_unit_price'],
        'our_amount'     => $row['our_amount'] === null ? null : (string) $row['our_amount'],
        'our_doc_no'     => $row['our_doc_no'] === null ? null : (string) $row['our_doc_no'],
        'branch_id'      => $row['branch_id'] === null ? null : (int) $row['branch_id'],
        'branch_name'    => $row['branch_name'] === null ? null : (string) $row['branch_name'],
    ];
}

/*
 * 아직 확정하지 않은 명세서는 **열 맞추기**가 남아 있다 — 파일에서 머리행 후보와 앞 20줄을
 * 다시 읽어 함께 내려 준다(올린 직후가 아니라 목록에서 다시 열었을 때도 이어서 할 수 있게).
 * 확정된 명세서에는 필요 없다: 그때는 저장된 줄이 곧 결과다.
 * 읽기에 실패해도 응답을 깨뜨리지 않는다 — 줄 목록은 이미 위에서 만들어졌다.
 */
$columns   = [];
$headRows  = [];
$headerRow = 0;
if (((int) $statement['line_count']) === 0) {
    try {
        $sheet     = Recon::rows(Storage::pathFor((string) $statement['stored_name']), (string) $statement['ext']);
        $headerRow = Recon::detectHeaderRow($sheet['rows']);
        $columns   = $sheet['rows'][$headerRow] ?? [];
        $headRows  = array_slice($sheet['rows'], 0, Recon::MAX_HEADER_ROW);
    } catch (Throwable $e) {
        $columns = [];
    }
}

Http::json([
    'statement_id' => $statementId,
    'vendor_id'    => (int) $statement['vendor_id'],
    'vendor_name'  => (string) $statement['vendor_name'],
    'month'        => (string) $statement['month'],
    'status'       => (string) $statement['status'],
    'file_name'    => (string) $statement['original_name'],
    'file_ext'     => (string) $statement['ext'],
    'total_amount' => $statement['total_amount'] === null ? null : (string) $statement['total_amount'],
    'committed'    => ((int) $statement['line_count']) > 0,
    'mapping'      => Recon::mappingOf($statement['mapping_json']),
    'lines'        => $lines,
    'diff_counts'  => $counts,
    'totals'       => [
        'theirs' => number_format($sumTheirs, 2, '.', ''),
        'ours'   => number_format($sumOurs, 2, '.', ''),
        'diff'   => number_format($sumTheirs - $sumOurs, 2, '.', ''),
    ],
    'targets'      => Recon::TARGETS,
    'required'     => Recon::REQUIRED,
    // 확정 전에만 채워진다 (열 맞추기 재료)
    'header_row'   => $headerRow,
    'columns'      => $columns,
    'head_rows'    => $headRows,
]);
