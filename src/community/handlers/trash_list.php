<?php
declare(strict_types=1);

/**
 * src/community/handlers/trash_list.php — GET /community/api/me/trash (정책: community.read)
 *
 * 요구사항: `C:F-29`(휴지통), `../../../../community/ARCHITECTURE.md` 16장,
 *           수락 기준 C:T-43 · C:T-44.
 *
 * **내가 지운 페이지**를 30일까지 보여 준다. 남이 지운 것은 내 휴지통에 없다 — 지운 사람이
 * 되살린다(`decidePageRestore` 가 같은 규칙으로 다시 막는다).
 *
 * 무엇이 한 줄인가: **지운 단위**다. 페이지를 지우면 하위가 함께 내려가는데(`pages_delete.php`),
 * 그 하위까지 줄로 늘어놓으면 한 번 지운 일이 여러 줄로 보인다. 그래서 목록에는
 * **부모가 살아 있거나 없는 것**(= 지우기의 뿌리)만 싣고, 함께 내려간 하위는 `descendants`
 * 숫자로 센다. 되살리면 그 하위가 함께 돌아온다.
 *
 * ★ 함께 내려간 하위를 무엇으로 아나 — **`deleted_at` 이 같은 값**이다. 한 번의 삭제는
 *   `UPDATE` 한 문장이라 그 안의 행이 모두 같은 `now()` 를 받는다. 따로 지웠던 하위는 시각이
 *   달라 함께 돌아오지 않는다(따로 지운 것은 따로 되살린다).
 *
 * ★ 목록은 **SQL `WHERE`** 로 걸러진다 (C:S-02). 조건절은 다른 목록과 **같은 문장**
 *   (`community_visible_pages_where`)이고, 거기에 「내가 지웠다」가 더해진다.
 * ★ 본문 글자는 나가지 않는다 (C:T-44) — 제목·시각·수뿐이다.
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params 경로 변수 없음
 */

[$visible, $visBind] = community_visible_pages_where($user);

$sql = <<<SQL
    WITH RECURSIVE roots AS (
        SELECT p.id, p.public_id, p.title, p.icon, p.visibility, p.deleted_at, p.purge_after,
               s.kind AS space_kind, s.name AS space_name,
               (p.parent_page_id IS NOT NULL) AS had_parent
          FROM community.pages p
          JOIN community.spaces s ON s.id = p.space_id
          LEFT JOIN public.users owner ON owner.id = s.owner_user_id
          LEFT JOIN community.pages par ON par.id = p.parent_page_id
         WHERE p.deleted_at IS NOT NULL
           AND p.deleted_by = CAST(:me AS bigint)
           AND p.deleted_at > now() - interval '30 days'
           AND (p.parent_page_id IS NULL OR par.deleted_at IS NULL)
           AND {$visible}
    ), down AS (
        SELECT r.id AS root_id, r.id, r.deleted_at FROM roots r
        UNION ALL
        SELECT d.root_id, c.id, d.deleted_at
          FROM community.pages c
          JOIN down d ON c.parent_page_id = d.id
         WHERE c.deleted_at = d.deleted_at
    )
    SELECT r.public_id, r.title, r.icon, r.visibility, r.deleted_at, r.purge_after,
           r.space_kind, r.space_name, r.had_parent,
           (SELECT count(*) - 1 FROM down d WHERE d.root_id = r.id) AS descendants
      FROM roots r
     ORDER BY r.deleted_at DESC, r.id DESC
     LIMIT 200
    SQL;

$pages = [];
foreach (Db::instance()->run($sql, array_merge([':me' => (int) $user['id']], $visBind))->fetchAll() as $r) {
    $pages[] = [
        'id'          => (string) $r['public_id'],
        'title'       => (string) $r['title'],
        'icon'        => $r['icon'] === null ? null : (string) $r['icon'],
        'visibility'  => (string) $r['visibility'],
        'deleted_at'  => (string) $r['deleted_at'],
        'purge_after' => $r['purge_after'] === null ? null : (string) $r['purge_after'],
        'space'       => ['kind' => (string) $r['space_kind'], 'name' => (string) $r['space_name']],
        // 함께 내려간 하위 수 (0 이면 그 페이지 하나만 지웠다는 뜻이다)
        'descendants' => (int) $r['descendants'],
        /* 지울 때 상위가 있었나. 상위가 그 사이 다시 지워졌다면 되살릴 때 **뿌리로 올라간다** —
           화면이 그 가능성을 미리 알려 줄 수 있게 싣는다(판정은 창구가 한다). */
        'had_parent'  => $r['had_parent'] === true,
    ];
}

Http::json([
    'pages' => $pages,
    'total' => count($pages),
    // 30일이 지나면 정리 배치가 지운다(7단계). 화면의 안내 문장이 이 값을 쓴다.
    'keep_days' => 30,
]);
