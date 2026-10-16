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
community_expiry_notifications($me);

$sqlCount = <<<'SQL'
    SELECT count(*) AS n FROM community.notifications WHERE user_id = CAST(:me AS bigint) AND read_at IS NULL AND retracted_at IS NULL
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
           -- 카페 C1 (ARCH 25.4 · 25.5) — 익명게시판 이름표의 재료(글 번호 · 쓴 사람 · 알림을 만든 사람) · 관리자 숨김 알림의 메모(신고 행에서)
           n.page_id, n.actor_id, p.author_id AS page_author_id,
           CASE WHEN n.kind = 'moderated' THEN (
               SELECT r.resolution_note FROM community.reports r
                WHERE r.page_id = n.page_id AND r.comment_id IS NOT DISTINCT FROM n.comment_id AND r.status = 'hidden'
                ORDER BY r.resolved_at DESC, r.id DESC LIMIT 1) END AS moderation_note,
           -- G-0 (t-box F-73) — 권한 요청 알림 두 종류의 요청 줄(페이지 없이)
           ar.id AS ar_id, ar.status AS ar_status, ar.folder_id AS ar_folder_id, ar.file_id AS ar_file_id,
           arf.public_id AS ar_file_public_id
      FROM community.notifications n
      LEFT JOIN community.pages p    ON p.id = n.page_id
      LEFT JOIN community.comments c ON c.id = n.comment_id
      LEFT JOIN public.users a       ON a.id = n.actor_id
      LEFT JOIN public.access_requests ar ON ar.id = n.access_request_id
      LEFT JOIN public.files arf     ON arf.id = ar.file_id
     WHERE n.user_id = CAST(:me AS bigint) AND n.retracted_at IS NULL
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

$fetched = $db->run($sql, [':me' => $me])->fetchAll();

/* 카페 C1 (ARCH 25.4) — 익명게시판 글의 알림이면 actor 를 이름표로(actor_id 기준). 이름표 지도는 목록 전체에 **질의 하나**
   (글 번호 · 사람 번호 쌍을 한 번에 — N+1 0). 이름표 규칙은 community_anon_label 하나. */
$aliasPages = [];
$aliasUsers = [];
foreach ($fetched as $n) {
    if ($n['page_id'] !== null && $n['actor_id'] !== null) {
        $aliasPages[(int) $n['page_id']]  = true;
        $aliasUsers[(int) $n['actor_id']] = true;
    }
}
$aliasNo = [];
if ($aliasPages !== []) {
    $sqlAlias = <<<'SQL'
        SELECT page_id, user_id, alias_no FROM community.anon_aliases
         WHERE page_id = ANY (string_to_array(CAST(:pages AS text), ',')::bigint[])
           AND user_id = ANY (string_to_array(CAST(:users AS text), ',')::bigint[])
        SQL;
    $aliasBind = [':pages' => implode(',', array_keys($aliasPages)), ':users' => implode(',', array_keys($aliasUsers))];
    foreach ($db->run($sqlAlias, $aliasBind)->fetchAll() as $a) {
        $aliasNo[(int) $a['page_id'] . ':' . (int) $a['user_id']] = (int) $a['alias_no'];
    }
}

/** 알림을 만든 사람 — 익명게시판 글이면 이름표(관리자 숨김 알림은 「관리자」) · 설문 익명이면 null · 그 밖은 아이디 · 이름. */
$actorOf = static function (array $n, ?array $row) use ($user, $me, $aliasNo): ?array {
    if ($row !== null && community_page_is_anon($row)) {
        if ((string) $n['kind'] === 'moderated') {
            return ['username' => null, 'display_name' => '관리자', 'anon' => true, 'is_me' => false];
        }
        if ($n['actor_id'] === null) {
            return null;
        }
        $actorId = (int) $n['actor_id'];
        $key = (int) $n['page_id'] . ':' . $actorId;
        return ['username' => null,
                'display_name' => community_anon_label($actorId === (int) $n['page_author_id'], $aliasNo[$key] ?? null),
                'anon' => true, 'is_me' => $actorId === $me];
    }
    if ((string) $n['kind'] === 'moderated') {
        return ['username' => null, 'display_name' => '관리자', 'anon' => true, 'is_me' => false];
    }
    if ($n['actor_name'] === null || ($n['page_public_id'] !== null && ($row === null || community_form_identity_hidden($user, $row)))) {
        return null;
    }
    return [
        'username'     => (string) $n['actor_name'],
        'display_name' => $n['actor_display_name'] === null ? null : (string) $n['actor_display_name'],
    ];
};

/* 규모 D2a A9 — 알림이 가리키는 글 행을 **한 번에** 읽는다(글마다 `community_page_row()` 를 부르던 N+1 → 질의 하나).
   같은 SQL 한 벌(`community_page_row_sql` — 본문 칸만 뺀 행)이고 공유 등급은 내 몫이다. 판정은 아래에서 줄마다 같은 함수로 한다. */
$rowsByPage = community_page_rows_nodoc(array_values(array_filter(array_map(
    static fn (array $n): ?string => $n['page_public_id'] === null ? null : (string) $n['page_public_id'], $fetched))), $me);

$items = [];
foreach ($fetched as $n) {
    $page = null;
    $row = null;
    if ($n['page_public_id'] !== null) {
        $row = $rowsByPage[(string) $n['page_public_id']] ?? null;
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
        // Rule status is safe without retaining any inaccessible document title.
        'automation_status' => $n['page_public_id'] === null && in_array($n['kind'], ['automation_denied', 'automation_disabled'], true),
        'comment_id' => $page !== null && $n['comment_public_id'] !== null ? (string) $n['comment_public_id'] : null,
        'actor'      => $actorOf($n, $row),
    ] + ((string) $n['kind'] === 'moderated'
        // 「관리자가 숨겼어요 — <메모>」 — 메모는 신고 행에서(ARCH 25.5). 그 글을 지금 볼 수 있을 때만.
        ? ['moderation_note' => $page !== null && $n['moderation_note'] !== null ? (string) $n['moderation_note'] : null]
        : []);
}
Http::json(['notifications' => $items, 'unread' => $unread]);
