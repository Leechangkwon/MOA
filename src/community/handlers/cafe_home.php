<?php
declare(strict_types=1);

/**
 * src/community/handlers/cafe_home.php — GET /community/api/cafe (정책: authenticated)
 *
 * 요구사항: 사이트 Q:F-12 카페 C1 · 커뮤니티 ARCHITECTURE 25.7 「카페 홈 창구」 · cafe-plan CF-5.
 *
 * 돌려주는 것 — **질의 셋 고정**(행 수와 무관 · N+1 0) · 읽을 수 있는 카페 게시판만(SQL `WHERE` — `community_board_read_sql`,
 * `CommunityAuthz::decideBoardRead` 와 한 줄씩 짝):
 *   boards   카페(`area='cafe'`) 게시판 — 번호 · 이름 · 설명 · 익명 여부 · 활성 · 글 수 · 24시간 새 글 수
 *   recent   카페 게시판 글 최근 10 — 지운 · 숨긴 것 0 · 게시판 글 + 카페 게시판에 게시된 개인 페이지 · `writer` 는 익명이면 이름표
 *   popular  30일 안 글 중 7일 조회(`page_views`) 많은 순 5
 * 글이 보이는 조건은 게시판 등급 갈래(`decidePageRead` [2] · [4a])와 같다 — 게시판을 읽을 수 있고 숨기지 않은 글.
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params
 */

$db = Db::instance();
$me = (int) $user['id'];

// ─── 질의 1 — 카페 게시판 ───────────────────────────────────────
[$readB, $bindB] = community_board_read_sql('b', 1, $user);
$sqlBoards = <<<SQL
    SELECT b.id, b.name, b.description, b.board_kind, b.is_active, b.access_level,
           (SELECT count(*) FROM community.pages p
             WHERE p.deleted_at IS NULL AND p.hidden_at IS NULL
               AND (p.space_id = b.id OR (p.visibility = 'published' AND p.published_board_id = b.id))) AS post_count,
           (SELECT count(*) FROM community.pages p
             WHERE p.deleted_at IS NULL AND p.hidden_at IS NULL
               AND ((p.space_id = b.id AND p.created_at > now() - interval '24 hours')
                    OR (p.visibility = 'published' AND p.published_board_id = b.id AND p.published_at > now() - interval '24 hours'))) AS new_count
      FROM community.spaces b
     WHERE b.kind = 'board' AND b.area = 'cafe'
       AND {$readB}
     ORDER BY b.is_active DESC, b.id
     LIMIT 50
    SQL;
$boards = [];
foreach ($db->run($sqlBoards, $bindB)->fetchAll() as $b) {
    $boards[] = [
        'id'          => (int) $b['id'],
        'name'        => (string) $b['name'],
        'description' => (string) $b['description'],
        'anonymous'   => (string) $b['board_kind'] === 'anonymous',
        'board_kind'  => (string) $b['board_kind'],
        'is_active'   => $b['is_active'] === true,
        'post_count'  => (int) $b['post_count'],
        'new_count'   => (int) $b['new_count'],
    ];
}

/**
 * 카페 글 질의 — 최근 · 인기 둘이 같은 모양(보이는 조건 · 쓴 사람 · 익명 재료)을 쓴다. 조각은 코드가 고른 고정 문자열이고 값은 바인딩.
 *
 * @return array{0:string,1:array<string,mixed>}
 */
$postsSql = static function (array $user, int $n1, int $n2, bool $popular, int $me): array {
    [$readS, $bindS]   = community_board_read_sql('s', $n1, $user);
    [$readPb, $bindPb] = community_board_read_sql('pb', $n2, $user);
    $views  = $popular
        ? "(SELECT count(*) FROM community.page_views v WHERE v.page_id = p.id AND v.day > current_date - 7)"
        : '0';
    $window = $popular ? " AND (CASE WHEN s.kind = 'board' THEN p.created_at ELSE p.published_at END) > now() - interval '30 days'" : '';
    $order  = $popular ? 'views7 DESC, posted_at DESC, p.id DESC' : 'posted_at DESC, p.id DESC';
    $limit  = $popular ? 5 : 10;
    $meKey  = ':cafe_me' . $n1;
    $sql = <<<SQL
        SELECT p.public_id, p.title, p.icon,
               s.kind AS space_kind, s.board_kind AS space_board_kind, s.id AS space_id, s.name AS space_name,
               p.visibility, p.published_board_id, pb.kind AS pub_kind, pb.board_kind AS pub_board_kind, pb.name AS pub_name,
               CASE WHEN s.kind = 'board' THEN p.created_at ELSE p.published_at END AS posted_at,
               w.username AS writer_name, w.display_name AS writer_display_name,
               (w.id = CAST({$meKey} AS bigint)) AS writer_is_me,
               {$views} AS views7
          FROM community.pages p
          JOIN community.spaces s ON s.id = p.space_id
          LEFT JOIN community.spaces pb ON pb.id = p.published_board_id
          LEFT JOIN public.users w ON w.id = CASE WHEN s.kind = 'board' THEN p.author_id ELSE s.owner_user_id END
         WHERE p.deleted_at IS NULL AND p.hidden_at IS NULL
           AND ((s.kind = 'board' AND s.area = 'cafe' AND {$readS})
                OR (s.kind = 'personal' AND p.visibility = 'published' AND pb.kind = 'board' AND pb.area = 'cafe' AND {$readPb}))
           {$window}
         ORDER BY
         {$order}
         LIMIT
         {$limit}
        SQL;
    return [$sql, array_merge($bindS, $bindPb, [$meKey => $me])];
};

/** 글 한 줄 — 익명게시판 글이면 쓴 사람은 이름표(「글쓴이」 · 그 줄의 is_me) */
$postItem = static function (array $r): array {
    $anon = community_page_is_anon($r);
    $postedHere = (string) $r['space_kind'] === 'board';
    return [
        'id'        => (string) $r['public_id'],
        'title'     => (string) $r['title'],
        'icon'      => $r['icon'] === null ? null : (string) $r['icon'],
        'board'     => [
            'id'        => (int) ($postedHere ? $r['space_id'] : $r['published_board_id']),
            'name'      => (string) ($postedHere ? $r['space_name'] : $r['pub_name']),
            'anonymous' => $anon,
        ],
        'posted_at' => (string) $r['posted_at'],
        'writer'    => $anon
            ? ['username' => null, 'display_name' => community_anon_label(true, 0), 'anon' => true, 'is_me' => $r['writer_is_me'] === true]
            : ($r['writer_name'] === null ? null : [
                'username'     => (string) $r['writer_name'],
                'display_name' => $r['writer_display_name'] === null ? null : (string) $r['writer_display_name'],
            ]),
    ];
};

// ─── 질의 2 — 최근 10 ──────────────────────────────────────────
[$sqlRecent, $bindRecent] = $postsSql($user, 2, 3, false, $me);
$recent = array_map($postItem, $db->run($sqlRecent, $bindRecent)->fetchAll());

// ─── 질의 3 — 인기 5 (30일 안 · 7일 조회 순) ─────────────────────
[$sqlPopular, $bindPopular] = $postsSql($user, 4, 5, true, $me);
$popular = [];
foreach ($db->run($sqlPopular, $bindPopular)->fetchAll() as $r) {
    $popular[] = $postItem($r) + ['views7' => (int) $r['views7']];
}

Http::json([
    'boards'  => $boards,
    'recent'  => $recent,
    'popular' => $popular,
    // 「게시판 만들어 주세요」 단추를 보일지 — 창구(POST /api/board-requests)가 같은 함수로 다시 막는다
    'can_request_board' => CommunityAuthz::decideBoardRequest($user)[0],
]);
