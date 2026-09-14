<?php
declare(strict_types=1);

/**
 * src/handlers/org_permissions.php — GET /api/org/permissions
 *                                    (정책: org.manage → Admin 만, PRD 5.4)
 *
 * 요구사항: PRD F-19 ③ · 5.1 · 5.2 · 5.3 · 8장 `/api/org/permissions` 행 · 14장 Q-12,
 *           ARCH 5.9 표 다섯째 행 · 6.2-1 · 10장, SECURITY R-43
 *
 * `permissions` 16행을 **그대로** 돌려준다(역할 × 접근 레벨의 읽기·업로드 플래그).
 * 소유자·팀 규칙(PRD 5.3)은 표가 아니라 코드(`Authz`)에 있으므로, 표만으로는 반쪽이다.
 * 그래서 5.3 을 요약한 문장 세 개를 **코드 상수**로 함께 내려 화면이 표 아래에 적는다.
 *
 * ★ 쓰기 경로가 없다 (PRD Q-12, SECURITY R-43).
 *   이 표를 화면에서 고칠 수 있게 만들면 앱 DB 계정에 그 표의 변경 권한이 필요해지고,
 *   그 순간 앱 어느 한 곳의 결함이 곧 **권한 상승** 경로가 된다. 그래서
 *     ① 이 파일에도 다른 어느 파일에도 이 표를 바꾸는 SQL 문장이 없고,
 *     ② DB 계정 `tbox_app` 은 이 표에 SELECT 만 갖는다 (db/grants.sql, ARCH 10장) —
 *   ②가 있기 때문에 ①을 실수로 어겨도 DB 가 막는다. 두 겹이 같은 것을 막는다.
 *   응답의 `editable:false` 는 화면이 「읽기 전용」 배지를 그리기 위한 **표시용 값**이며,
 *   그 값이 true 로 바뀌어도 편집 API 자체가 없다.
 *
 * 조회량이 작고(16행) 민감하지 않아 감사 로그는 남기지 않는다 (PRD S-07 취지).
 * 이 화면에 온 것 자체가 라우트 정책 org.manage 를 통과했다는 뜻이다.
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자 (Admin)
 * @var list<string>        $params 경로 변수 없음
 */

/**
 * PRD 5.3 요약 — 표(16행)에 담기지 않는 소유자·팀 규칙. 화면이 문장을 지어내지 않도록
 * 서버가 **코드 상수**로 내려 준다(화면이 역할별 규칙을 스스로 조립하면 그것이 곧
 * 화면에 생긴 권한 판단이다 — PRD S-01).
 *
 * @var list<string> $orgRules
 */
$orgRules = [
    'team 레벨의 ✓ 는 「같은 팀일 때」라는 뜻입니다. Admin 은 모든 팀을 봅니다.',
    '삭제·수정은 Admin 이 모든 파일, Manager 는 본인이 올린 파일과 자기 팀 파일, Employee 는 본인이 올린 파일만, Guest 는 할 수 없습니다.',
    '접근 레벨을 낮추는 변경(보는 사람이 늘어나는 변경)은 Admin 만 할 수 있습니다.',
];

$sqlMatrix = <<<'SQL'
    SELECT role, access_level, can_read, can_upload
      FROM permissions
     ORDER BY role, access_level
    SQL;

$rows        = Db::instance()->run($sqlMatrix)->fetchAll();
$permissions = [];
foreach ($rows as $row) {
    $permissions[] = [
        'role'         => (string) $row['role'],
        'access_level' => (string) $row['access_level'],
        'can_read'     => $row['can_read'] === true,
        'can_upload'   => $row['can_upload'] === true,
    ];
}

Http::json([
    'permissions' => $permissions,
    'rules'       => $orgRules,
    // 화면의 「읽기 전용」 배지·안내 문구용 표시값. 편집 API 는 존재하지 않는다 (Q-12, R-43).
    'editable'    => false,
]);
