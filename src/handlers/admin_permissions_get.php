<?php
declare(strict_types=1);

/**
 * src/handlers/admin_permissions_get.php — GET /api/admin/permissions
 *                                          (정책: role:Admin + 재인증, PRD F-25)
 *
 * 요구사항: PRD F-25 · 8장 `GET /api/admin/permissions` 행 · 10장 「권한 매트릭스 편집」 ·
 *           14장 Q-12, ARCH 5.15 · 6.2-1, SECURITY R-60
 *
 * `permissions` 16행 + **잠긴 칸 표시**(`locked`)를 준다. 화면은 이 값으로 체크상자를
 * 비활성화하고 이유를 툴팁에 적는다.
 *
 * ★ 읽기는 앱 롤 `tbox_app` 으로 한다 — 그 롤은 이 표에 SELECT 만 갖는다 (ARCH 10장).
 *   쓰기 자격(`tbox_perm`)이 붙는 곳은 `admin_permissions_update.php` 하나뿐이다.
 *
 * ★ `locked` 는 **표시용 값이 아니라 서버 규칙의 그림자**다: 화면이 이 값을 무시하고 잠긴
 *   칸을 보내도 PATCH 핸들러가 같은 규칙으로 400 `permission.change deny invariant` 를
 *   돌려준다 (PRD S-01 "화면은 편의, 막는 것은 서버").
 *
 * 조회는 감사 로그를 남기지 않는다 (org_permissions.php 와 같은 판단 — 16행이고, 이 화면에
 * 온 것 자체가 `role:Admin` 정책과 재인증 관문을 통과했다는 뜻이다).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자 (Admin)
 * @var list<string>        $params 경로 변수 없음
 */

/**
 * ARCH 5.15 불변 규칙 (a)·(c) — **값이 고정된 칸**과 그 이유.
 *
 * 키는 `역할|등급`, 값은 [고정된 can_read, 고정된 can_upload, 화면에 보일 이유].
 * ★ 이 표는 `admin_permissions_update.php` 의 같은 표와 **내용이 같아야 한다**.
 *   한쪽을 고치면 다른 쪽도 고친다(GET 은 화면 표시, PATCH 는 실제 거부).
 *   역할 문자열을 `if` 로 비교하지 않고 표로 두는 이유: 역할에 대한 판단은 Authz 한 곳에서만
 *   하고(ARCH 4.1), 여기서는 "어느 칸이 고정인가" 라는 **자료**만 다루기 위해서다.
 *
 * @var array<string,array{0:bool,1:bool,2:string}> $lockedCells
 */
$lockedCells = [
    'Admin|public'     => [true,  true,  '관리자 행은 바꿀 수 없습니다 (관리자가 스스로를 잠그지 못하게)'],
    'Admin|internal'   => [true,  true,  '관리자 행은 바꿀 수 없습니다 (관리자가 스스로를 잠그지 못하게)'],
    'Admin|team'       => [true,  true,  '관리자 행은 바꿀 수 없습니다 (관리자가 스스로를 잠그지 못하게)'],
    'Admin|admin-only' => [true,  true,  '관리자 행은 바꿀 수 없습니다 (관리자가 스스로를 잠그지 못하게)'],
    'Guest|admin-only' => [false, false, '손님에게 관리자 등급을 여는 칸은 바꿀 수 없습니다'],
];

/**
 * 화면 아래에 그대로 적을 규칙 설명 — 화면이 문장을 지어내지 않게 **서버가 내려 준다**
 * (org_permissions.php 의 `rules` 와 같은 방식). 문장은 ARCH 5.15 (a)~(e) 를 사람 말로 옮긴 것이다.
 *
 * @var list<string> $matrixRules
 */
$matrixRules = [
    '관리자(Admin) 행의 네 칸은 전부 켜진 채 고정입니다 — 관리자가 스스로를 잠그지 못하게 하기 위해서입니다.',
    '「올리기」를 켜려면 같은 칸의 「읽기」도 켜져 있어야 합니다 (읽지 못하는 등급에 올릴 수는 없습니다).',
    '손님(Guest)의 관리자(admin-only) 칸은 꺼진 채 고정입니다.',
    '바뀌는 칸이 하나도 없는 요청은 거부됩니다 — 사유만 남기는 기록을 만들지 않기 위해서입니다.',
    '변경에는 사유와 2단계 인증 코드가 필요하고, 칸마다 이전값 → 새값이 감사 로그에 남습니다.',
];

$sqlMatrix = <<<'SQL'
    SELECT role, access_level, can_read, can_upload
      FROM permissions
     ORDER BY role, access_level
    SQL;

$rows   = Db::instance()->run($sqlMatrix)->fetchAll();
$matrix = [];
foreach ($rows as $row) {
    $key    = (string) $row['role'] . '|' . (string) $row['access_level'];
    $locked = array_key_exists($key, $lockedCells);
    $matrix[] = [
        'role'          => (string) $row['role'],
        'access_level'  => (string) $row['access_level'],
        'can_read'      => $row['can_read'] === true,
        'can_upload'    => $row['can_upload'] === true,
        // 규칙 (a)·(c) 로 고정된 칸인가 — 화면은 이 값으로 체크상자를 disabled 로 둔다.
        'locked'        => $locked,
        'locked_reason' => $locked ? $lockedCells[$key][2] : null,
    ];
}

/*
 * 응답의 16행 키는 `matrix` 다 — `PATCH` 가 돌려주는 키와 **같은 이름**이라 화면이 한 가지
 * 처리로 받을 수 있다 (ARCH 5.15 ⑥ "응답 {ok, changed, matrix:16행}").
 * 12-3 의 읽기 전용 창구(`GET /api/org/permissions`)는 `permissions` 라는 자기 이름을 그대로
 * 쓴다 — 그 화면·계약을 17단계에서 건드리지 않았다 (PRD Q-12 이전의 읽기 전용 표).
 */
Http::json([
    'matrix' => $matrix,
    'rules'  => $matrixRules,
]);
