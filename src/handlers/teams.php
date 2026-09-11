<?php
declare(strict_types=1);

/**
 * src/handlers/teams.php — GET /api/teams (정책: authenticated)
 *
 * 업로드 폼의 "팀" 선택 상자를 채우기 위한 목록이다. 팀 이름은 비밀이 아니고,
 * 화면 하드코딩(팀이 늘면 화면이 틀려진다)을 피하려고 서버가 내려 준다.
 *
 * ★ 3단계: 권한 검사 없음 — 목록 자체가 로그인 사용자에게 공통이므로 4단계에서도
 *   행 필터는 붙지 않는다. 다만 "이 팀으로 올릴 수 있는가" 는 4단계의
 *   `Authz::canUploadAs($user, 'team', $teamId)` 가 업로드 시점에 판단한다 —
 *   화면에 보이는 것이 곧 허용이 아니다 (PRD S-01, 10장 "버튼 표시는 권한의 근거가 아님").
 *
 * 조회량이 작고 민감하지 않으므로 감사 로그는 남기지 않는다 (PRD S-07 취지).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params 경로 변수 없음
 */

/*
 * 12-3 (F-19, ARCH 5.9) — **활성 팀만** 돌려준다. 비활성 팀은 선택지에서 사라지고,
 * 그 팀으로 올리거나 배정하려는 요청은 각 핸들러가 400 으로 막는다. 목록에서 빠지는 것은
 * 편의이고 막는 것은 서버다 (PRD S-01). 이미 그 팀에 있는 파일·계정은 그대로다.
 */
$sqlTeams = <<<'SQL'
    SELECT id, name FROM teams WHERE is_active ORDER BY id
    SQL;

$rows  = Db::instance()->run($sqlTeams)->fetchAll();
$teams = [];
foreach ($rows as $row) {
    $teams[] = [
        'id'   => (int) $row['id'],
        'name' => (string) $row['name'],
    ];
}

Http::json(['teams' => $teams]);
