<?php
declare(strict_types=1);

/**
 * src/community/handlers/databases_list.php — GET /community/api/databases?space= (정책: community.read)
 *
 * 요구사항: 지시서 `stage6b-database-views-directive.md` 「관계」 · 결정 H-4 — 관계 속성의 대상 고르기.
 * 그 공간에서 **내가 볼 수 있는** DB 페이지만(목록 조건절 안), 이름·id 뿐. 공간 번호 없거나 틀리면 빈 목록.
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 */

$space = Http::queryInt('space', 0, 0, 999999999);
[$visible, $visBind] = community_visible_pages_where($user);
$sql = <<<SQL
    SELECT d.public_id, p.title
      FROM community.databases d
      JOIN community.pages p ON p.id = d.page_id
      JOIN community.spaces s ON s.id = p.space_id
      LEFT JOIN public.users owner ON owner.id = s.owner_user_id
     WHERE (CAST(:space AS bigint)=0 OR p.space_id = CAST(:space AS bigint))
       AND p.deleted_at IS NULL
       AND {$visible}
     ORDER BY p.title, p.id
     LIMIT 100
    SQL;
$out = [];
foreach (Db::instance()->run($sql, array_merge([':space' => $space], $visBind))->fetchAll() as $r) {
    $out[] = ['id' => (string) $r['public_id'], 'title' => (string) $r['title']];
}
Http::json(['databases' => $out]);
