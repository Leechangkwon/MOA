<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_weekly.php — GET /api/purchase/usage/weekly  (정책: purchase.read)
 *
 * ★ 22단계 **주간 사용량** (PRD F-34, 시안 Weekly, 완료 기준 T-47 (a)).
 *
 *   ?from=YYYY-MM-DD  ?to=YYYY-MM-DD   기간 (기본: 오늘이 든 주를 포함한 최근 4주)
 *   ?branch=          한 지점만        ?cat1= ?cat2=  분류로 좁히기
 *   ?group=cat1|cat2|cat3|item          행의 층 (기본 cat1 = 대분류)
 *
 * 무엇을 세나: `Usage::weekly()` 주석에 한 곳으로 적어 두었다 — 사용(IS) 전표의 원장 음수 행 +
 * 재고관리를 하지 않는 품목의 입고(GR) 양수 행. 이동·실사는 사용이 아니다.
 *
 * 지점 격리 (SECURITY R-65)
 *   `Authz::purchaseScopeWhere($user, 'l')` 를 **장소**에 건다. requester 는 자기 지점 장소만
 *   보이므로 표의 지점 열도 자기 지점 하나뿐이다(`?branch=` 에 남의 지점을 적어도 마찬가지).
 *   화면에 그릴 **지점 목록**도 같은 조건을 지난 장소에서 뽑는다 — 목록에 이름만 새는 길을
 *   따로 만들지 않는다.
 *
 * 조회는 감사 로그를 남기지 않는다 (품목 찾기·재고 현황과 같은 판단).
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params 경로 변수 없음
 */

require_once __DIR__ . '/../purchase/Usage.php';

$db = Db::instance();

$scopeL = Authz::purchaseScopeWhere($user, 'l');

/** 기본 기간 — 이번 주를 포함한 4주 (화면이 아무것도 고르지 않아도 뜻이 있는 표가 나오게) */
$defaultTo   = date('Y-m-d');
$defaultFrom = date('Y-m-d', strtotime('monday this week -3 weeks'));

$dateOr = static function (string $key, string $fallback): string {
    $raw = Http::queryText($key, 10);
    if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $raw) !== 1) {
        return $fallback;
    }
    // 달력에 없는 날(2026-02-31)은 기본값으로 돌린다 — 형식만 맞는 값을 믿지 않는다.
    [$y, $m, $d] = array_map('intval', explode('-', $raw));
    return checkdate($m, $d, $y) ? $raw : $fallback;
};

$from = $dateOr('from', $defaultFrom);
$to   = $dateOr('to', $defaultTo);
if (strcmp($from, $to) > 0) {
    [$from, $to] = [$to, $from];
}

$branchId = Http::queryInt('branch', 0, 1, 999999999);
$cat1     = Http::queryInt('cat1', 0, 1, 999999999);
$cat2     = Http::queryInt('cat2', 0, 1, 999999999);
$group    = Http::queryChoice('group', Usage::GROUPS, 'cat1');

$result = Usage::weekly($db, $scopeL, [
    'from'      => $from,
    'to'        => $to,
    'group'     => $group,
    'branch_id' => $branchId > 0 ? $branchId : null,
    'cat1'      => $cat1 > 0 ? $cat1 : null,
    'cat2'      => $cat2 > 0 ? $cat2 : null,
]);

/**
 * 표의 **열(지점)** — 내 범위 안에 장소가 있는 지점만. 조건은 위와 같은 `purchaseScopeWhere`
 * 조각이라 목록과 값이 어긋날 수 없다.
 */
$sqlBranches = <<<'SQL'
    SELECT DISTINCT b.id, b.name
      FROM p_branches b
      JOIN p_locations l ON l.branch_id = b.id
     WHERE b.is_active AND l.is_active AND
    SQL;

$sqlBranchTail = <<<'SQL'
     ORDER BY b.name ASC
     LIMIT 100
    SQL;

$branches = [];
foreach ($db->run($sqlBranches . ' ' . $scopeL['sql'] . ' ' . $sqlBranchTail, $scopeL['params'])->fetchAll() as $row) {
    $branches[] = ['id' => (int) $row['id'], 'name' => (string) $row['name']];
}

Http::json([
    'from'      => $from,
    'to'        => $to,
    'group'     => $group,
    'group_label' => Usage::GROUP_LABELS[$group] ?? $group,
    'weeks'     => $result['weeks'],
    'branches'  => $branches,
    'cells'     => $result['cells'],
    'truncated' => $result['truncated'],
    'max_weeks' => Usage::MAX_WEEKS,
    'max_rows'  => Usage::MAX_ROWS,
]);
