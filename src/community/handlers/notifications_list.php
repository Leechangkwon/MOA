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
           c.public_id AS comment_public_id, a.username AS actor_name, a.display_name AS actor_display_name,
           -- G-0 (t-box F-73) — 권한 요청 알림 두 종류의 요청 줄(페이지 없이)
           ar.id AS ar_id, ar.status AS ar_status, ar.folder_id AS ar_folder_id, ar.file_id AS ar_file_id,
           arf.public_id AS ar_file_public_id
      FROM community.notifications n
      LEFT JOIN community.pages p    ON p.id = n.page_id
      LEFT JOIN community.comments c ON c.id = n.comment_id
      LEFT JOIN public.users a       ON a.id = n.actor_id
      LEFT JOIN public.access_requests ar ON ar.id = n.access_request_id
      LEFT JOIN public.files arf     ON arf.id = ar.file_id
     WHERE n.user_id = CAST(:me AS bigint)
     ORDER BY n.created_at DESC, n.id DESC
     LIMIT 50
    SQL;

/**
 * G-0 — 권한 요청 알림의 `access` 칸. 항목 이름·주소는 **지금 볼 수 있을 때만**(t-box 목록과 같은 판정 — canSeeInList).
 * 주인(access_request)은 자기 항목이라 보이고, 요청자(access_decided)는 허락된 뒤에야 보인다(거절이면 이름 없이 「볼 수 없는 항목」).
 * 누르면 가는 곳: 받은 요청 목록(`/tbox/?requests=in`) · 허락되어 보이는 항목(`/tbox/?folder=` · `/tbox/?file=`) · 보낸 요청(`?requests=out`).
 *
 * @param array<string,mixed> $n
 * @param array<string,mixed> $user
 * @return array<string,mixed>|null
 */
$accessOf = static function (array $n, array $user): ?array {
    if (!in_array((string) $n['kind'], ['access_request', 'access_decided'], true)) {
        return null;
    }
    if ($n['ar_id'] === null) {
        return ['status' => null, 'item' => null, 'href' => null];   // 요청이 지워졌다(빈 폴더를 지웠을 때)
    }
    $kind = $n['ar_file_id'] !== null ? 'file' : 'folder';
    $sqlItem = $kind === 'file'
        ? "SELECT f.id, f.original_name AS name, f.access_level, f.team_id, f.uploaded_by, 'file' AS item_kind FROM public.files f WHERE f.id = CAST(:id AS bigint) AND f.deleted_at IS NULL"
        : "SELECT d.id, d.name, d.access_level, d.team_id, d.created_by, 'folder' AS item_kind FROM public.folders d WHERE d.id = CAST(:id AS bigint)";
    $row = Db::instance()->run($sqlItem, [':id' => (int) ($kind === 'file' ? $n['ar_file_id'] : $n['ar_folder_id'])])->fetch();
    $visible = is_array($row) && Authz::canSeeInList($user, $row);
    $itemHref = $kind === 'file'
        ? Http::url('/') . '?file=' . rawurlencode((string) $n['ar_file_public_id'])
        : Http::url('/') . '?folder=' . (int) $n['ar_folder_id'];
    $href = (string) $n['kind'] === 'access_request'
        ? Http::url('/') . '?requests=in'
        : ($visible ? $itemHref : Http::url('/') . '?requests=out');
    return [
        'status' => (string) $n['ar_status'],
        'item'   => $visible ? ['kind' => $kind, 'name' => (string) $row['name']] : null,
        'href'   => $href,
    ];
};

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
        // G-0 — 권한 요청 알림이면 {status, item:{kind,name}|null, href} · 아니면 null
        'access'     => $accessOf($n, $user),
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
