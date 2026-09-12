<?php
declare(strict_types=1);

/**
 * src/handlers/activity_list.php — GET /api/me/activity?page= (정책: authenticated)
 *
 * ★ 15-1: 내 활동 보기 (PRD F-12, 8장 API 표, ARCH 5.11).
 *
 * 이 핸들러가 지키는 것
 *   · **대상은 언제나 세션 본인**이다. 요청에서 대상을 받지 않는다 — 조건절의 `:me` 에는
 *     라우터가 넘긴 $user 의 id 만 들어간다. `?actor=`·`?user_id=` 같은 값은 읽지도 않으므로
 *     주소에 무엇을 붙여도 남의 행이 나오지 않는다 (PRD S-02, T-33).
 *   · 요청 파라미터는 `page` **하나**뿐이고 Http::queryInt 가 형태·범위를 좁힌다.
 *   · 돌려주는 칸은 여섯 개뿐이다. `ip`·`user_agent`·`detail` 은 **SELECT 자체에 넣지 않는다** —
 *     응답에 없으면 새지 않는다(본인 것이라도 최소 노출, ARCH 5.11).
 *   · 행동 목록은 코드에 적힌 고정 배열이고, SQL 에는 값이 아니라 **자리표시자 이름**만 이어 붙인다.
 *     값은 전부 바인딩된다 (PRD S-04).
 *   · **이 조회는 감사 로그에 남기지 않는다** (PRD F-12) — 자기 이력 열람은 보안 사건이 아니다.
 *     (관리자의 `/api/audit` 조회는 남는다. 그쪽은 남의 기록을 보는 행위다.)
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자 (요청 파라미터가 아니다 — PRD S-01)
 * @var list<string>        $params 경로 변수 없음
 */

$perPage = 20;

/**
 * 보여 줄 행동 목록 (PRD F-12 고정 목록).
 *
 * 코드에 적힌 상수 배열이며 요청이 고르지 못한다. 여기 없는 행동(예: `authz.route`·`csrf`·
 * `auth.gate`)은 본인 행이라도 이 화면에 나오지 않는다 — 「내 활동」은 "내가 한 일"을 보는
 * 곳이지 관리자용 조사 화면이 아니기 때문이다(그쪽은 F-09 감사 로그 화면이다).
 */
$activityActions = [
    'file.upload', 'file.download', 'file.preview', 'file.update',
    'file.delete', 'file.restore', 'file.fetch',
    'share.create', 'share.revoke',
    'auth.login', 'auth.logout', 'auth.password_change',
    'auth.mfa_enroll', 'auth.mfa_disable',
];

$page = Http::queryInt('page', 1, 1, 100000);

/*
 * 행동 목록을 `IN (:act0, :act1, …)` 로 바꾼다.
 * 이어 붙이는 것은 **자리표시자 이름**이고 값이 아니다 — 값은 아래 $bind 로만 들어간다.
 */
$actionPlaceholders = [];
$bind = [':me' => (int) $user['id']];
foreach ($activityActions as $i => $name) {
    $ph                  = ':act' . $i;
    $actionPlaceholders[] = $ph;
    $bind[$ph]            = $name;
}
$inList = implode(', ', $actionPlaceholders);

// ─── SQL 조각 (전부 코드에 적힌 고정 문자열) ───────────────────
// ip·user_agent·detail 은 어느 조각에도 없다 (ARCH 5.11).
$sqlRowsHead = <<<'SQL'
    SELECT happened_at,
           action,
           result,
           reason,
           target_name,
           summary
      FROM audit_logs
     WHERE actor_id = :me
       AND action IN (
    SQL;

$sqlRowsTail = <<<'SQL'
    )
     ORDER BY id DESC
     LIMIT :lim OFFSET :off
    SQL;

$sqlCountHead = <<<'SQL'
    SELECT count(*) AS total
      FROM audit_logs
     WHERE actor_id = :me
       AND action IN (
    SQL;

$sqlCountTail = <<<'SQL'
    )
    SQL;

$db = Db::instance();

$countRow = $db->run($sqlCountHead . $inList . $sqlCountTail, $bind)->fetch();
$total    = is_array($countRow) ? (int) $countRow['total'] : 0;

$rowsBind         = $bind;
$rowsBind[':lim'] = $perPage;
$rowsBind[':off'] = ($page - 1) * $perPage;

$rows = $db->run($sqlRowsHead . $inList . $sqlRowsTail, $rowsBind)->fetchAll();

$out = [];
foreach ($rows as $row) {
    $out[] = [
        'happened_at' => (string) $row['happened_at'],
        'action'      => (string) $row['action'],
        'result'      => (string) $row['result'],
        'reason'      => $row['reason'] === null ? null : (string) $row['reason'],
        'target_name' => $row['target_name'] === null ? null : (string) $row['target_name'],
        'summary'     => (string) $row['summary'],
    ];
}

Http::json([
    'rows'     => $out,
    'total'    => $total,
    'page'     => $page,
    'per_page' => $perPage,
]);
