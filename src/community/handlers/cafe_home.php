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
/* 규모 D2a A1 · A2 — 글 수는 미리 센 값(`spaces.post_count` · 정의는 옛 하위 질의 그대로). 24시간 새 글은 두 갈래를 따로 센다 —
   게시판 글(pages_board_new_idx 범위) + 이 게시판에 게시된 페이지(pages_published_new_idx 범위). 한 글이 두 갈래에 다 걸리면
   뒤 갈래에서 빼서 한 번만 센다(옛 `OR` 하나와 같은 수). */
$sqlBoards = <<<SQL
    SELECT b.id, b.name, b.description, b.board_kind, b.is_active, b.access_level,
           b.post_count,
           (SELECT count(*) FROM community.pages p
             WHERE p.space_id = b.id AND p.deleted_at IS NULL AND p.hidden_at IS NULL
               AND p.created_at > now() - interval '24 hours')
         + (SELECT count(*) FROM community.pages p
             WHERE p.published_board_id = b.id AND p.visibility = 'published'
               AND p.deleted_at IS NULL AND p.hidden_at IS NULL
               AND p.published_at > now() - interval '24 hours'
               AND NOT (p.space_id = b.id AND p.created_at > now() - interval '24 hours')) AS new_count
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
 * 규모 D2a A1 — 옛 질의(카페 글 전체를 읽고 CASE 식으로 정렬 · 조인)와 **같은 행 · 같은 순서**를 두 갈래 `UNION ALL` 로 만든다:
 *   ① 게시판 글 — 읽을 수 있는 카페 게시판(`s`)마다 그 게시판 글(`p.space_id = s.id`) · 올린 시각 순(pages_board_new_idx)
 *   ② 게시된 페이지 — 읽을 수 있는 카페 게시판(`pb`)마다 그 게시판에 게시된 **개인 공간** 페이지 · 게시 시각 순(pages_published_new_idx)
 * 두 갈래는 글의 공간 종류(board · personal)로 갈려 겹치지 않는다(옛 `OR` 의 두 갈래 그대로). 최근은 갈래 · 게시판마다 10개만 읽고
 * 합쳐 10개(정렬 = 올린 · 게시 시각 DESC, 글 번호 DESC — 옛 `posted_at` 식 그대로). 인기는 30일 창 안의 글만 후보로 모은 뒤 7일 조회 순.
 * 쓴 사람(users) · 게시판 이름은 남은 행에만 붙인다. 정렬 · 조인 키에 CASE 식 0.
 *
 * @return array{0:string,1:array<string,mixed>}
 */
$postsSql = static function (array $user, int $n1, int $n2, bool $popular, int $me): array {
    [$readS, $bindS]   = community_board_read_sql('s', $n1, $user);
    [$readPb, $bindPb] = community_board_read_sql('pb', $n2, $user);
    $views  = $popular
        ? "(SELECT count(*) FROM community.page_views v WHERE v.page_id = c.id AND v.day > current_date - 7)"
        : '0';
    // 갈래마다: 인기는 30일 창(옛 `posted_at > now() - 30일` — 게시판 글은 올린 시각 · 게시된 페이지는 게시 시각) · 최근은 게시판마다 10개
    $windowS  = $popular ? " AND p.created_at > now() - interval '30 days'" : '';
    $windowPb = $popular ? " AND p.published_at > now() - interval '30 days'" : '';
    $perBoard = $popular ? '' : ' LIMIT 10';
    $order  = $popular ? 'views7 DESC, c.posted_at DESC, c.id DESC' : 'c.posted_at DESC, c.id DESC';
    $limit  = $popular ? 5 : 10;
    $meKey  = ':cafe_me' . $n1;
    $sql = <<<SQL
        SELECT p.public_id, p.title, p.icon,
               s.kind AS space_kind, s.board_kind AS space_board_kind, s.id AS space_id, s.name AS space_name,
               p.visibility, p.published_board_id, pb.kind AS pub_kind, pb.board_kind AS pub_board_kind, pb.name AS pub_name,
               c.posted_at,
               w.username AS writer_name, w.display_name AS writer_display_name,
               (w.id = CAST({$meKey} AS bigint)) AS writer_is_me,
               c.views7
          FROM (
                SELECT c.id, c.posted_at, c.writer_id,
                       {$views} AS views7
                  FROM (
                        SELECT x.id, x.posted_at, x.writer_id
                          FROM community.spaces s
                          CROSS JOIN LATERAL (
                                SELECT p.id, p.created_at AS posted_at, p.author_id AS writer_id
                                  FROM community.pages p
                                 WHERE p.space_id = s.id AND p.deleted_at IS NULL AND p.hidden_at IS NULL
                                       {$windowS}
                                 ORDER BY p.created_at DESC, p.id DESC
                                       {$perBoard}
                          ) x
                         WHERE s.kind = 'board' AND s.area = 'cafe'
                           AND {$readS}
                        UNION ALL
                        SELECT y.id, y.posted_at, y.writer_id
                          FROM community.spaces pb
                          CROSS JOIN LATERAL (
                                SELECT p.id, p.published_at AS posted_at, ps.owner_user_id AS writer_id
                                  FROM community.pages p
                                  JOIN community.spaces ps ON ps.id = p.space_id AND ps.kind = 'personal'
                                 WHERE p.published_board_id = pb.id AND p.visibility = 'published'
                                   AND p.deleted_at IS NULL AND p.hidden_at IS NULL
                                       {$windowPb}
                                 ORDER BY p.published_at DESC, p.id DESC
                                       {$perBoard}
                          ) y
                         WHERE pb.kind = 'board' AND pb.area = 'cafe'
                           AND {$readPb}
                  ) c
                 ORDER BY
                 {$order}
                 LIMIT
                 {$limit}
          ) c
          JOIN community.pages p ON p.id = c.id
          JOIN community.spaces s ON s.id = p.space_id
          LEFT JOIN community.spaces pb ON pb.id = p.published_board_id
          LEFT JOIN public.users w ON w.id = c.writer_id
         ORDER BY
         {$order}
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
