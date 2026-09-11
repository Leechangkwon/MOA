<?php
declare(strict_types=1);

/**
 * src/handlers/org_teams.php — GET /api/org/teams (정책: org.manage → Admin 만, PRD 5.4)
 *
 * 요구사항: PRD F-19 · 5.4 · 8장 `/api/org/teams` 행 · 10장 「조직·권한」,
 *           ARCH 5.9 표 첫 행, SECURITY R-44
 *
 * 조직·권한 화면의 좌측 팀 카드를 채운다. 팀마다 세 숫자를 함께 준다.
 *   · member_count  활성 계정 수 (`users.is_active`) — 비활성화 가능 여부의 근거가 되는 숫자다
 *   · manager_count 그중 Manager 수 — "이 팀에 책임자가 있나" 를 한눈에 보기 위한 **표시용** 값
 *   · file_count    살아 있는 파일 수 (`files.deleted_at IS NULL`)
 *
 * 숫자는 전부 **SQL 집계**로 만든다. 팀 수만큼 질의를 반복하지 않고(N+1), 애플리케이션에서
 * 세지 않는다 — 세는 일은 DB 가 제일 잘한다 (ARCH 5.1 "권한 조건은 SQL WHERE" 와 같은 결).
 *
 * SQL 의 `role = 'Manager'` 는 **역할 판단이 아니라 표시용 집계 조건**이다. 누가 무엇을 할 수
 * 있는지는 이 파일이 한 글자도 정하지 않는다 — 이 화면에 들어왔다는 사실 자체가 라우트 정책
 * `org.manage`(Authz::canManageOrg) 를 통과했다는 뜻이다 (PRD S-01, ARCH 4.1).
 *
 * 비활성 팀도 **함께** 돌려준다(카드에 배지로 구분). 팀은 지우지 않으므로 목록에서 사라지는
 * 팀은 없고, `ORDER BY is_active DESC` 로 활성 팀이 위에 온다 (ARCH 5.9).
 *
 * 조회량이 작고 민감하지 않아 감사 로그는 남기지 않는다 (PRD S-07 취지 — `GET /api/teams`,
 * `GET /api/users` 와 같은 기준. 기록하는 것은 **바꾸는** 행동이다).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자 (Admin)
 * @var list<string>        $params 경로 변수 없음
 */

/*
 * 바인딩할 값이 없는 고정 SQL 이다(요청 값이 한 글자도 들어가지 않는다).
 * 집계는 팀별로 미리 묶은 뒤 LEFT JOIN 한다 — 구성원이 0명인 팀도 행이 사라지지 않게.
 */
$sqlTeams = <<<'SQL'
    SELECT t.id,
           t.name,
           t.is_active,
           t.created_at,
           t.updated_at,
           COALESCE(m.member_count, 0)  AS member_count,
           COALESCE(m.manager_count, 0) AS manager_count,
           COALESCE(f.file_count, 0)    AS file_count
      FROM teams t
      LEFT JOIN (
               SELECT team_id,
                      count(*)                                  AS member_count,
                      count(*) FILTER (WHERE role = 'Manager')  AS manager_count
                 FROM users
                WHERE is_active AND team_id IS NOT NULL
                GROUP BY team_id
           ) m ON m.team_id = t.id
      LEFT JOIN (
               SELECT team_id,
                      count(*) AS file_count
                 FROM files
                WHERE deleted_at IS NULL AND team_id IS NOT NULL
                GROUP BY team_id
           ) f ON f.team_id = t.id
     ORDER BY t.is_active DESC, t.id
    SQL;

$rows  = Db::instance()->run($sqlTeams)->fetchAll();
$teams = [];
foreach ($rows as $row) {
    $teams[] = [
        'id'            => (int) $row['id'],
        'name'          => (string) $row['name'],
        'is_active'     => $row['is_active'] === true,
        'member_count'  => (int) $row['member_count'],
        'manager_count' => (int) $row['manager_count'],
        'file_count'    => (int) $row['file_count'],
        'created_at'    => (string) $row['created_at'],
        'updated_at'    => (string) $row['updated_at'],
    ];
}

Http::json(['teams' => $teams]);
