<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_recon_list.php — GET /api/purchase/recon  (정책: purchase.read)
 *
 * ★ 22단계 **명세서 목록** (PRD F-35, 시안 Recon 의 왼쪽 목록).
 *
 *   ?vendor=  거래처만   ?month=YYYY-MM  한 달만   ?page=  1부터
 *
 * 한 행 = 명세서 한 장이고, 칸은 전부 서버가 센 값이다(줄 수·차이 건수·명세서 합계).
 * 「우리 합계」는 여기서 내지 않는다 — 명세서를 여는 순간 `…/preview` 가 그때의 전표로
 * 다시 계산한다(저장된 합계와 원장이 어긋날 자리를 만들지 않는다).
 *
 * 지점 격리 예외 — 명세서는 **거래처**에 묶이고 지점에 묶이지 않는다(한 장에 여러 지점
 *   입고가 섞이는 것이 정상 — 시안 Recon 의 「지점」 열). 이 목록이 보여 주는 것은 "어느
 *   거래처의 몇 월 명세서를 언제 올렸나" 이고, 금액은 **명세서 파일에 적힌 값**이다.
 *   지점 격리가 필요한 곳(우리 입고 줄)은 preview·get 이 `purchaseScopeWhere` 로 건다.
 *
 * 조회는 감사 로그를 남기지 않는다.
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params 경로 변수 없음
 */

require_once __DIR__ . '/../purchase/Recon.php';

const TBOX_RECON_PER_PAGE = 30;

$db = Db::instance();

$vendorId = Http::queryInt('vendor', 0, 1, 999999999);
$page     = Http::queryInt('page', 1, 1, 500);
$monthRaw = Http::queryText('month', 7);
$month    = preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/', $monthRaw) === 1 ? $monthRaw : '';

$where = ['TRUE'];
$bind  = [];
if ($vendorId > 0) {
    $where[]         = 's.vendor_id = CAST(:vendor AS bigint)';
    $bind[':vendor'] = $vendorId;
}
if ($month !== '') {
    $where[]        = 's.month = CAST(:month AS char(7))';
    $bind[':month'] = $month;
}

// lint:no-branch-scope 명세서는 거래처에 묶인다 — 한 장에 여러 지점 입고가 섞이는 것이 정상이다.
$sqlHead = <<<'SQL'
    SELECT s.id, s.vendor_id, s.month, s.status, s.file_id,
           CAST(s.total_amount AS text) AS total_amount,
           s.created_at,
           v.name AS vendor_name,
           f.original_name,
           u.username AS created_by_name,
           (SELECT count(*) FROM p_recon_lines rl WHERE rl.statement_id = s.id) AS line_count,
           (SELECT count(*) FROM p_recon_lines rl
             WHERE rl.statement_id = s.id AND rl.diff_type <> 'none')            AS diff_count
      FROM p_recon_statements s
      JOIN p_vendors v ON v.id = s.vendor_id
      JOIN files     f ON f.id = s.file_id
      JOIN users     u ON u.id = s.created_by
     WHERE
    SQL;

// lint:no-branch-scope 같은 목록의 건수 — 위 조회와 같은 범위다.
$sqlCount = <<<'SQL'
    SELECT count(*) AS n
      FROM p_recon_statements s
     WHERE
    SQL;

$sqlTail = <<<'SQL'
     ORDER BY s.month DESC, s.id DESC
     LIMIT :lim OFFSET :off
    SQL;

$condition = implode(' AND ', $where);

$total    = 0;
$countRow = $db->run($sqlCount . ' ' . $condition, $bind)->fetch();
if (is_array($countRow)) {
    $total = (int) $countRow['n'];
}

$bind[':lim'] = TBOX_RECON_PER_PAGE;
$bind[':off'] = ($page - 1) * TBOX_RECON_PER_PAGE;

$out = [];
foreach ($db->run($sqlHead . ' ' . $condition . ' ' . $sqlTail, $bind)->fetchAll() as $row) {
    $out[] = [
        'id'           => (int) $row['id'],
        'vendor_id'    => (int) $row['vendor_id'],
        'vendor_name'  => (string) $row['vendor_name'],
        'month'        => (string) $row['month'],
        'status'       => (string) $row['status'],
        'file_name'    => (string) $row['original_name'],
        'total_amount' => $row['total_amount'] === null ? null : (string) $row['total_amount'],
        'lines'        => (int) $row['line_count'],
        'diffs'        => (int) $row['diff_count'],
        'committed'    => ((int) $row['line_count']) > 0,
        'created_by'   => (string) $row['created_by_name'],
        'created_at'   => (string) $row['created_at'],
    ];
}

Http::json([
    'statements' => $out,
    'total'      => $total,
    'page'       => $page,
    'per_page'   => TBOX_RECON_PER_PAGE,
]);
