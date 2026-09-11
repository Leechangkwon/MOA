<?php
declare(strict_types=1);

/**
 * src/handlers/org_team_members.php — GET /api/org/teams/{num}/members
 *                                     (정책: org.manage → Admin 만, PRD 5.4)
 *
 * 요구사항: PRD F-19 · 8장 `/api/org/teams/{num}/members` 행 · 10장 「조직·권한」,
 *           ARCH 5.9 표 넷째 행, SECURITY R-44
 *
 * 선택한 팀의 구성원 표를 채운다. **비활성 계정도 함께** 돌려주고 `is_active` 로 구분한다 —
 * 팀을 내리려면 "활성 구성원 0명" 이어야 하는데(R-44), 화면에 활성 계정만 보이면 관리자가
 * 왜 거부됐는지 알 수 없기 때문이다.
 *
 * 이 화면은 계정을 **바꾸지 않는다**. 「다른 팀으로…」 는 기존 `PATCH /api/users/{uid}` 를
 * 부른다 — 자기 자신 금지·마지막 Admin 보호·세션 종료(T-22)가 두 곳으로 갈라지지 않게
 * 배정 API 를 새로 만들지 않았다 (ARCH 5.9).
 *
 * 역할 문자열을 비교하지 않는다. `ORDER BY role` 은 표를 읽기 좋게 묶는 **정렬**일 뿐이고,
 * 무엇을 할 수 있는지는 이 파일이 정하지 않는다 (PRD S-01, ARCH 4.1).
 * 비밀번호 해시·TOTP 비밀·만료일 같은 값은 돌려주지 않는다 — 이 화면에 필요 없다.
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자 (Admin)
 * @var list<string>        $params [0] => teams.id (정수 문자열, 라우터가 형태를 이미 좁혔다)
 */

$teamId = isset($params[0]) && preg_match('/^[0-9]{1,9}$/', (string) $params[0]) === 1
    ? (int) $params[0]
    : 0;

$db = Db::instance();

$sqlTeam = <<<'SQL'
    SELECT id, name, is_active
      FROM teams
     WHERE id = :id
    SQL;

$team = $teamId > 0 ? $db->run($sqlTeam, [':id' => $teamId])->fetch() : false;
if (!is_array($team)) {
    // 조회 실패는 감사 로그에 남기지 않는다 — 기록하는 것은 **바꾸는** 행동이다 (PRD S-07 취지).
    Http::error(404, Http::MSG_NOT_FOUND);
}

$sqlMembers = <<<'SQL'
    SELECT id, username, role, is_active
      FROM users
     WHERE team_id = :id
     ORDER BY role, username
    SQL;

$rows    = $db->run($sqlMembers, [':id' => $teamId])->fetchAll();
$members = [];
foreach ($rows as $row) {
    $members[] = [
        'id'        => (int) $row['id'],
        'username'  => (string) $row['username'],
        'role'      => (string) $row['role'],
        'is_active' => $row['is_active'] === true,
    ];
}

Http::json([
    'team' => [
        'id'        => (int) $team['id'],
        'name'      => (string) $team['name'],
        'is_active' => $team['is_active'] === true,
    ],
    'members' => $members,
]);
