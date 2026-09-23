<?php
declare(strict_types=1);

/**
 * src/community/handlers/embed_domains_list.php — GET /community/api/embed-domains (정책: community.read)
 *
 * 요구사항: `C:F-32` · 지시서 「7-C 확정」 K-1·K-5. 허용 목록은 **비밀이 아니다** — 쓰는 사람이 무엇을 넣을 수 있는지
 * 알아야 한다(편집기의 임베드 창이 이 목록을 보여 준다). 누가 넣었는지는 관리할 수 있는 사람에게만.
 *   `frames` — 지금 사이트가 틀을 여는가(코드 상수). 거짓이면 모든 임베드가 「허용 대기」로 보인다.
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 */

$canManage = Authz::canAdmin($user);
$sql = <<<'SQL'
    SELECT e.id, e.host, e.added_at, u.username AS added_by_name, u.display_name AS added_by_display_name
      FROM community.embed_domains e
      JOIN public.users u ON u.id = e.added_by
     WHERE e.removed_at IS NULL
     ORDER BY e.host
     LIMIT 100
    SQL;
$out = [];
foreach (Db::instance()->run($sql)->fetchAll() as $r) {
    $one = ['host' => (string) $r['host']];
    if ($canManage) {
        $one['id']       = (int) $r['id'];
        $one['added_at'] = (string) $r['added_at'];
        $one['added_by'] = ['username' => (string) $r['added_by_name'],
                            'display_name' => $r['added_by_display_name'] === null ? null : (string) $r['added_by_display_name']];
    }
    $out[] = $one;
}
Http::json([
    'domains'    => $out,
    'limit'      => COMMUNITY_EMBED_MAX_DOMAINS,
    'frames'     => COMMUNITY_EMBED_FRAMES,
    'can_manage' => $canManage,
]);
