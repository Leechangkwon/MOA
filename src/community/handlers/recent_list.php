<?php
declare(strict_types=1);

/**
 * src/community/handlers/recent_list.php — GET /community/api/me/recent (정책: community.read — 개발 대기열 6 · Q:F-3a 라운드 A)
 *
 * 내가 최근에 연 페이지(최대 50) — 사이드바 「최근」 5개 + 「더 보기」 창(결정 M-1). **지금 볼 수 있는 것만.**
 *
 * 요구사항: 지시서 `/var/www/qaredi/docs/qf3a-favorites-recent-bulk-directive.md` 1.2 · 결정 M-3 · 3장 QF3A-T5, 커뮤니티 C:S-02 ·
 *           커뮤니티 ARCH 21.24.
 *
 * ★ 새 판정 코드 0 — 줄은 `community.page_recent`(읽기 판정을 지난 뒤에만 쓰인 것 — `pages_get.php`)이고, 내보낼 때 다시
 *   **검색과 같은 조건절**(`community_search_pages_where` — 관리자 특권 갈래 끔) + 지운 페이지 제외 + 숨긴 게시판 글 제외를 붙인다.
 *   그 사이 못 보게 된 페이지는 목록 SQL 이 돌려주지 않는다(QF3A-T5 「못 보게 된 페이지 빠짐」).
 * ★ 대상 인자가 없다 — 세션의 본인 줄뿐이다. 전체 개수 칸도 없다. 이 창구는 아무것도 쓰지 않고 감사도 남기지 않는다(목록 조회).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params 경로 변수 없음
 */

[$visible, $bind] = community_search_pages_where($user);
$bind[':rec_me'] = (int) $user['id'];

$sqlHead = <<<'SQL'
    SELECT p.public_id, p.title, p.icon, s.kind AS space_kind, s.name AS space_name, r.last_viewed_at,
           p.author_id, s.owner_user_id AS space_owner_user_id, s.team_id AS space_team_id,
           s.is_active AS space_is_active
      FROM community.page_recent r
      JOIN community.pages p ON p.id = r.page_id
      JOIN community.spaces s ON s.id = p.space_id
      LEFT JOIN public.users owner ON owner.id = s.owner_user_id
     WHERE r.user_id = CAST(:rec_me AS bigint)
       AND p.deleted_at IS NULL
       AND NOT (s.kind = 'board' AND p.hidden_at IS NOT NULL)
       AND
    SQL;
$sqlTail = ' ORDER BY r.last_viewed_at DESC, p.id DESC LIMIT 50';

$pages = [];
foreach (Db::instance()->run($sqlHead . ' ' . $visible . $sqlTail, $bind)->fetchAll() as $r) {
    $pages[] = [
        'id'             => (string) $r['public_id'],
        'title'          => (string) $r['title'],
        'icon'           => $r['icon'] === null ? null : (string) $r['icon'],
        'space'          => ['kind' => (string) $r['space_kind'], 'name' => (string) $r['space_name']],
        'can_delete'     => Authz::canCommunityWrite($user) && CommunityAuthz::decidePageManage($user, $r)[0],
        // 마지막으로 연 때(5분 단위로만 밀린다 — 쓰기를 줄이려고). 서버 값 그대로
        'last_viewed_at' => (string) $r['last_viewed_at'],
    ];
}

Http::json(['pages' => $pages, 'limit' => COMMUNITY_RECENT_MAX]);
