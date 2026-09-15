<?php
declare(strict_types=1);

/**
 * src/handlers/fetch_hosts_list.php — GET /api/fetch-hosts
 *                                      (정책: role:Admin + 재인증 + CSRF, PRD F-43)
 *
 * 요구사항: PRD F-43 · 8장 `GET /api/fetch-hosts` 행 · 5장 「허용 사이트 관리 = Admin」 ·
 *           12.2 T-56, ARCH 5.22, SECURITY R-76
 *
 * 「URL 로 가져오기」가 갈 수 있는 곳의 **목록**을 준다. 화면(`/admin/fetch-hosts`)이 표를 그린다.
 *
 * ★ 읽기는 앱 롤 `tbox_app` 으로 한다 — 그 롤은 이 표에 SELECT·INSERT·UPDATE 를 갖고
 *   **DELETE 는 없다** (db/grants.sql). 허용에서 빼는 것은 `is_active=false` 이지 삭제가 아니다.
 *
 * ★ 이 목록은 **표시용**이다. 화면이 값을 고쳐도 무엇이 허용인지는 요청마다 서버가
 *   `Fetch::allowedHosts()` 로 다시 읽는다 (PRD S-01).
 *
 * 조회는 감사 로그를 남기지 않는다 (admin_permissions_get.php 와 같은 판단 — 행이 적고,
 * 이 화면에 온 것 자체가 `role:Admin` 정책과 재인증 관문을 통과했다는 뜻이다).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자 (Admin)
 * @var list<string>        $params 경로 변수 없음
 */

/**
 * 화면 아래에 그대로 적을 규칙 설명 — 화면이 문장을 지어내지 않게 **서버가 내려 준다**
 * (admin_permissions_get.php 의 `rules` 와 같은 방식).
 *
 * @var list<string> $fetchHostRules
 */
$fetchHostRules = [
    'https 로만 나갑니다. http 주소는 넣어도 거부됩니다.',
    '정확한 호스트 이름만 넣습니다 — 와일드카드(*)는 쓸 수 없고, 하위 이름은 저마다 따로 넣어야 합니다.',
    '사내망 주소(localhost·10.·192.168. 등)와 IP 로 적은 주소는 넣어도 서버가 거부합니다.',
    '지우는 단추가 없습니다. 그만 쓰려면 「허용」을 끄세요 — 언제부터 언제까지 열려 있었는지가 남아야 하기 때문입니다.',
    '저장에는 사유와 인증 앱의 6자리 코드가 필요하고, 코드는 한 번 쓰면 다시 쓸 수 없습니다.',
];

$sqlHosts = <<<'SQL'
    SELECT f.id,
           f.host,
           f.note,
           f.is_active,
           f.created_at,
           f.updated_at,
           u.username AS created_by_name
      FROM fetch_hosts f
      LEFT JOIN users u ON u.id = f.created_by
     ORDER BY f.host
    SQL;

$hosts = [];
foreach (Db::instance()->run($sqlHosts)->fetchAll() as $row) {
    $hosts[] = [
        'id'         => (int) $row['id'],
        'host'       => (string) $row['host'],
        'note'       => $row['note'] === null ? '' : (string) $row['note'],
        'is_active'  => $row['is_active'] === true,
        'created_at' => (string) $row['created_at'],
        'updated_at' => (string) $row['updated_at'],
        'created_by' => $row['created_by_name'] === null ? '' : (string) $row['created_by_name'],
    ];
}

Http::json([
    'hosts' => $hosts,
    'rules' => $fetchHostRules,
]);
