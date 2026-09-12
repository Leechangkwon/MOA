<?php
declare(strict_types=1);

/**
 * src/handlers/users_list.php — GET /api/users (정책: user.manage → Admin 만, PRD 5.4)
 *
 * 요구사항: PRD F-10 · 5.4(계정 생성·역할 변경은 Admin) · 3장(1인 1계정), 8장 `/api/users` 행,
 *           SECURITY R-12(Guest 만료) · R-05(2단계 인증)
 *
 * 계정 관리 화면(`/users`)이 그릴 목록이다. 지키는 것
 *   · **비밀번호 해시를 내보내지 않는다.** `password_hash` 는 SELECT 목록에 아예 없다 —
 *     "응답에서 지운다" 가 아니라 "가져오지 않는다" 다 (PRD S-02 와 같은 원칙).
 *   · **TOTP 비밀(`mfa_secret`)도 내보내지 않는다.** 켜졌는지(`mfa_enabled`)만 내려 준다 (R-05).
 *   · 정렬은 고정(`id ASC`)이고 값은 전부 바인딩한다 (PRD S-04).
 *   · 역할 판단은 라우트 정책 `user.manage`(Authz::canManageUsers)가 한다 — 이 파일에 역할 비교가 없다.
 *   · 목록 조회 자체는 감사 로그를 남기지 않는다 (PRD S-07 "양이 많은 읽기 행동은 기본 미기록").
 *     계정을 **바꾸는** 행동(user.create·role_change·deactivate·activate)은 전부 남는다.
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params 경로 변수 없음
 */

$perPage = 50;
$page    = Http::queryInt('page', 1, 1, 100000);

$sqlList = <<<'SQL'
    SELECT u.id,
           u.username,
           u.role,
           u.team_id,
           t.name      AS team_name,
           u.is_active,
           u.expires_at,
           u.mfa_enabled,
           u.failed_count,
           u.locked_until,
           u.created_at,
           u.updated_at,
           -- 19단계 구매·재고 모듈의 두 축 (PRD F-28, ARCH 5.18). **표시용**이며
           -- 권한 판정은 Authz::purchaseAuthorize / purchaseScopeWhere 가 요청마다 다시 한다.
           u.branch_id,
           u.purchase_role,
           pb.name AS branch_name,
           (u.expires_at IS NOT NULL AND u.expires_at < now()) AS is_expired
      FROM users u
      LEFT JOIN teams t ON t.id = u.team_id
      LEFT JOIN p_branches pb ON pb.id = u.branch_id
     ORDER BY u.id ASC
     LIMIT :lim OFFSET :off
    SQL;

$sqlCount = <<<'SQL'
    SELECT count(*) AS total FROM users
    SQL;

$db = Db::instance();

$countRow = $db->run($sqlCount)->fetch();
$total    = is_array($countRow) ? (int) $countRow['total'] : 0;

$rows = $db->run($sqlList, [
    ':lim' => $perPage,
    ':off' => ($page - 1) * $perPage,
])->fetchAll();

$users = [];
foreach ($rows as $row) {
    $users[] = [
        'id'          => (int) $row['id'],
        'username'    => (string) $row['username'],
        'role'        => (string) $row['role'],
        'team_id'     => $row['team_id'] === null ? null : (int) $row['team_id'],
        'team_name'   => $row['team_name'] === null ? null : (string) $row['team_name'],
        'is_active'   => $row['is_active'] === true,
        'expires_at'  => $row['expires_at'] === null ? null : (string) $row['expires_at'],
        'is_expired'  => $row['is_expired'] === true,
        'mfa_enabled' => $row['mfa_enabled'] === true,
        'is_locked'   => $row['locked_until'] !== null,
        'created_at'  => (string) $row['created_at'],
        'updated_at'  => (string) $row['updated_at'],
        // 화면이 "나 자신은 고칠 수 없다" 를 미리 알 수 있게 하는 **편의 값**이다.
        // 실제 거부는 users_update 가 Authz::decideUserChange 로 다시 판단한다 (PRD S-01).
        'is_self'     => (int) $row['id'] === (int) $user['id'],
        // 19단계 — 지점·모듈 역할 (F-28). 화면의 두 셀렉트를 채우는 값이다.
        'branch_id'     => $row['branch_id'] === null ? null : (int) $row['branch_id'],
        'branch_name'   => $row['branch_name'] === null ? null : (string) $row['branch_name'],
        'purchase_role' => $row['purchase_role'] === null ? null : (string) $row['purchase_role'],
    ];
}

Http::json([
    'users'    => $users,
    'total'    => $total,
    'page'     => $page,
    'per_page' => $perPage,
]);
