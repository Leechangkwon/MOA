<?php
declare(strict_types=1);

/**
 * src/community/handlers/notifications_list.php — GET /community/api/notifications[?unread=1] (정책: community.read)
 *
 * 요구사항: 커뮤니티 PRD F-22 — 종 아이콘 알림(30초 폴링). **내 알림만**(SQL `user_id = 나` — 요청이 누구 것을 고르지 못한다).
 *
 * ★ 알림을 **읽을 때 다시 판정한다** — 알림이 만들어진 뒤 공유가 빠졌거나 페이지가 지워졌으면, 그 줄은 제목 없이
 *   「볼 수 없는 페이지」로만 나간다(주소도 없다). 알림함이 옛 권한으로 제목을 계속 보여 주지 않게.
 * 최근 50줄 + 안 읽은 수. `unread=1` 이면 안 읽은 수만(폴링이 가볍게 — 목록을 매번 판정하지 않는다).
 *
 * @var array<string,mixed> $user 라우터가 넘긴 현재 사용자
 */

$db = Db::instance();
$me = (int) $user['id'];

$sqlCount = <<<'SQL'
    SELECT count(*) AS n FROM community.notifications WHERE user_id = CAST(:me AS bigint) AND read_at IS NULL
    SQL;
$countRow = $db->run($sqlCount, [':me' => $me])->fetch();
$unread   = is_array($countRow) ? (int) $countRow['n'] : 0;

if (Http::queryText('unread', 2) === '1') {
    Http::json(['unread' => $unread]);
    exit;
}

$sql = <<<'SQL'
    SELECT n.id, n.kind, n.created_at, n.read_at, p.public_id AS page_public_id,
           c.public_id AS comment_public_id, a.username AS actor_name, a.display_name AS actor_display_name
      FROM community.notifications n
      LEFT JOIN community.pages p    ON p.id = n.page_id
      LEFT JOIN community.comments c ON c.id = n.comment_id
      LEFT JOIN public.users a       ON a.id = n.actor_id
     WHERE n.user_id = CAST(:me AS bigint)
     ORDER BY n.created_at DESC, n.id DESC
     LIMIT 50
    SQL;

$items = [];
foreach ($db->run($sql, [':me' => $me])->fetchAll() as $n) {
    $page = null;
    if ($n['page_public_id'] !== null) {
        $row = community_page_row((string) $n['page_public_id'], $me);
        if ($row !== null) {
            /* 만들 때와 **같은 기준**(`decideMentionTarget` — 관리자 특권·숨긴 글 관리자 갈래로만 보는 것은 아니다)으로
               다시 판정한다. 목록에서 더 넓게 보면 공유가 빠진 뒤에도 관리자 알림함에 제목이 남는다(독립 검토 2026-09-23). */
            [$ok] = CommunityAuthz::decideMentionTarget([
                'id' => $me, 'role' => (string) $user['role'], 'team_id' => $user['team_id'], 'is_active' => true,
            ], $row);
            if ($ok) {
                $page = ['id' => (string) $n['page_public_id'], 'title' => (string) $row['title']];
            }
        }
    }
    $items[] = [
        'id'         => (int) $n['id'],
        'kind'       => (string) $n['kind'],
        'at'         => (string) $n['created_at'],
        'read'       => $n['read_at'] !== null,
        // 지금도 볼 수 있을 때만 제목·주소 — 아니면 null(화면은 「볼 수 없는 페이지」)
        'page'       => $page,
        'comment_id' => $page !== null && $n['comment_public_id'] !== null ? (string) $n['comment_public_id'] : null,
        'actor'      => $n['actor_name'] === null ? null : [
            'username'     => (string) $n['actor_name'],
            'display_name' => $n['actor_display_name'] === null ? null : (string) $n['actor_display_name'],
        ],
    ];
}
Http::json(['notifications' => $items, 'unread' => $unread]);
