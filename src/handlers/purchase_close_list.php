<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_close_list.php — GET /api/purchase/close  (정책: purchase.read)
 *
 * ★ 22단계 **마감 목록** (PRD F-35, ARCH 5.18 「마감」).
 *
 *   ?month=YYYY-MM  한 달만   ?branch=  한 지점만(전사 행은 언제나 함께 나온다)
 *
 * 한 행 = 마감 한 번. 전사 마감은 `branch_id = null` 이고, 그 달은 모든 지점이 잠긴다 —
 * 그래서 지점으로 좁혀 보더라도 전사 행은 **빼지 않는다**(빼면 화면이 "안 잠겼다" 로 보인다).
 *
 * 지점 격리 (SECURITY R-65): `Authz::purchaseScopeWhere($user, 'b')`.
 *   requester·stock 은 자기 지점 마감과 전사 마감만 보인다.
 *
 * 조회는 감사 로그를 남기지 않는다.
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params 경로 변수 없음
 */

$db = Db::instance();

$monthRaw = Http::queryText('month', 7);
$month    = preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/', $monthRaw) === 1 ? $monthRaw : '';
$branchId = Http::queryInt('branch', 0, 1, 999999999);

$scopeB = Authz::purchaseScopeWhere($user, 'b');

$where = ['(c.branch_id IS NULL OR ' . $scopeB['sql'] . ')'];
$bind  = $scopeB['params'];
if ($month !== '') {
    $where[]        = 'c.month = CAST(:month AS char(7))';
    $bind[':month'] = $month;
}
if ($branchId > 0) {
    $where[]         = '(c.branch_id IS NULL OR c.branch_id = CAST(:branch AS bigint))';
    $bind[':branch'] = $branchId;
}

/** `b` 별칭은 위 조각이 쓰는 이름이다 — 지점 자신이라 `id AS branch_id` 를 함께 내준다. */
$sqlHead = <<<'SQL'
    SELECT c.id, c.branch_id, c.month, c.closed_at, c.reason_text,
           u.username AS closed_by_name,
           b.name     AS branch_name
      FROM p_month_close c
      LEFT JOIN (SELECT p.*, p.id AS branch_id FROM p_branches p) b ON b.id = c.branch_id
      JOIN users u ON u.id = c.closed_by
     WHERE
    SQL;

$sqlTail = <<<'SQL'
     ORDER BY c.month DESC, c.branch_id NULLS FIRST
     LIMIT 200
    SQL;

$out = [];
foreach ($db->run($sqlHead . ' ' . implode(' AND ', $where) . ' ' . $sqlTail, $bind)->fetchAll() as $row) {
    $out[] = [
        'id'          => (int) $row['id'],
        'branch_id'   => $row['branch_id'] === null ? null : (int) $row['branch_id'],
        'branch_name' => $row['branch_name'] === null ? '전사' : (string) $row['branch_name'],
        'month'       => (string) $row['month'],
        'closed_at'   => (string) $row['closed_at'],
        'closed_by'   => (string) $row['closed_by_name'],
        'reason_text' => $row['reason_text'] === null ? null : (string) $row['reason_text'],
    ];
}

Http::json(['closes' => $out, 'total' => count($out)]);
