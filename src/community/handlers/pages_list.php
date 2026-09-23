<?php
declare(strict_types=1);

/**
 * src/community/handlers/pages_list.php — GET /community/api/pages (정책: community.read)
 *
 * 요구사항: `C:F-17`(공간·트리), `C:F-18`(공개 범위), `C:S-02`(못 보는 것은 응답에 없다),
 *           `C:S-04`, `../../../../community/ARCHITECTURE.md` 13.3 · 21.3,
 *           수락 기준 C:T-35(목록 격리) · C:T-36(팀 공간) · C:T-37(판정 위치).
 *
 * 쿼리 (전부 선택 — 없거나 이상하면 **거부하지 않고 안전한 값으로 좁힌다**, t-box ARCH 5.1)
 *   space  공간 번호. 없으면 **내 개인 공간**
 *   tree   `1` 이면 그 공간의 살아 있는 페이지를 한 질의로 전부(트리는 화면이 만든다)
 *   q      제목·본문 찾기 (≤ 100자). `ILIKE` 는 `#` 로 이스케이프한 뒤 **바인딩**한다
 *   parent 하위 페이지만 (UUID)
 *   page   1부터. 한 쪽 20건
 *   status 상태로 좁히기 (4-D) — 다섯 값 또는 `none`(상태 없음). 모르는 값이면 **빈 목록**이다
 *   shared `me` 면 **공유받은 페이지**(5-A — 4-D 의 `assignee=me` 자리). 이때만 공간을 가리지 않는다 — 아래 ★
 *
 * ★ 범위는 **SQL 이 정한다** (C:S-02 · C:T-37). 관문이 둘이다:
 *     [공간] `community_space_gate()` — 남의 개인 공간은 통째로 403, 남의 팀 공간도 403.
 *            그 안에 사내로 열어 둔 페이지가 있더라도 **남의 공간 목차를 넘겨보는 일**은
 *            공개 범위가 허락한 일이 아니다 (C:T-35).
 *     [행]   `community_visible_pages_where()` — `decidePageRead` 를 그대로 옮긴 조건절.
 *            같은 공간 안에서도 볼 수 없는 행(넓은 페이지 아래의 비공개 하위 등)은
 *            **질의 결과에 아예 들어오지 않는다**. 응답을 받은 뒤 PHP 가 거르는 코드가 없다.
 *
 * ★ SQL 문장은 **고정 문자열**이다. 「찾기를 켰나」·「부모를 골랐나」는 SQL 조각을 이어
 *   붙이지 않고 **바인딩한 참/거짓**으로 끈다 — 문자열을 조립하는 자리 자체를 없앤다 (PRD S-04).
 *   문장에 끼워 넣는 조각은 조건절 상수 하나뿐이고, 그 안의 별칭 `p`·`s`·`owner` 도 코드에
 *   적힌 리터럴이다. 요청 값은 한 글자도 SQL 에 들어가지 않는다.
 *
 * ★ `?shared=me` 는 **공간 관문을 지나지 않는다** (5-A). 「공유받은 페이지」는 언제나 남의
 *   개인 공간에 있고, 공간을 하나씩 골라 가며 찾게 하면 그 묶음은 쓸 수 없다. 대신 **행 관문은
 *   그대로**다 — `community_visible_pages_where()` 가 붙으므로 공유 줄이 있어도 효력이 없는 것
 *   (내 계정이 비활성·`published` 범위)은 결과에 없다. 「남의 공간 목차를 넘겨본다」(C:T-35 가
 *   막는 일)와 다르다: 여기 들어오는 행은 **그 주인이 이름을 대고 나에게 준 페이지**뿐이다
 *   (공유 줄이 **직접** 달린 페이지 — 그 아래 하위는 그 페이지를 열어 하위 목록으로 간다).
 * ★ 4-D 의 `?assignee=me` 는 없어졌다(담당자 지정을 멈췄다). 그 꼬리표는 이제 뜻이 없어
 *   **무시된다** — 다른 모르는 꼬리표와 같이 기본 목록(내 개인 공간)이 나간다.
 *
 * 목록 조회는 감사에 남기지 않는다 (t-box 파일 목록과 같은 취급).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params 경로 변수 없음
 */

$db = Db::instance();
$me = (int) $user['id'];

/* ─── [0] 상태 거르개 (4-D) ─────────────────────────────────────
 *
 * SQL 조각을 이어 붙이지 않는다 — 「걸렀나」·「없음을 골랐나」를 **바인딩한 참/거짓**으로
 * 끈다 (`q`·`parent` 와 같은 방식, PRD S-04). 모르는 값은 세 갈래 모두 거짓이 되어
 * 어떤 행과도 맞지 않는다: 이상한 값에 목록이 **넓어지는** 일이 없다.
 */
$statusRaw   = Http::queryText('status', 12);
$statusOff   = $statusRaw === '';
$statusNone  = $statusRaw === 'none';
$statusValue = in_array($statusRaw, COMMUNITY_STATUSES, true) ? $statusRaw : '';

$statusWhere = <<<'SQL'
       AND (CAST(:soff AS boolean)
            OR (CAST(:snone AS boolean) AND p.status IS NULL)
            OR p.status = :svalue)
SQL;
$statusBind = [
    ':soff'   => $statusOff ? 'true' : 'false',
    ':snone'  => $statusNone ? 'true' : 'false',
    // 빈 문자열은 CHECK 가 막는 값이라 어떤 행의 `status` 와도 같지 않다.
    ':svalue' => $statusValue,
];

/* ─── [0-1] 「공유받은 페이지」 (5-A — 4-D 「내가 담당인 페이지」 자리) ──────
 *
 * 공간을 가리지 않는 **하나의 질의**다. 거르개는 전부 SQL `WHERE` 이고, 응답을 받은 뒤
 * PHP 가 줄을 빼는 코드가 없다 (C:S-02). 「고칠 수 있나」만 행마다 판정 함수로 답한다 —
 * 그 값은 **단추를 열지 말지**를 고르는 화면 편의 값이고 목록의 범위를 정하지 않는다.
 * 행마다 판정에 필요한 공유 등급은 **같은 SQL 조각**(`community_share_rank_sql`)이 센다.
 */
if (Http::queryText('shared', 8) === 'me') {
    [$visShared, $visSharedBind] = community_visible_pages_where($user);
    $rankShared = community_share_rank_sql('p.id', ':me_rank');

    $perPageShared = 20;
    $pageNoShared  = Http::queryInt('page', 1, 1, 100000);

    $sqlSharedCount = <<<SQL
        SELECT count(*) AS n
          FROM community.pages p
          JOIN community.spaces s ON s.id = p.space_id
          LEFT JOIN public.users owner ON owner.id = s.owner_user_id
          JOIN community.page_shares sh ON sh.page_id = p.id AND sh.user_id = CAST(:me AS bigint)
         WHERE p.deleted_at IS NULL
           AND {$visShared}
        {$statusWhere}
        SQL;

    $sqlShared = <<<SQL
        SELECT p.public_id, p.title, p.icon, p.block_count, p.doc_version,
               p.created_at, p.updated_at, p.visibility, p.status, p.published_from,
               s.id AS space_id, s.kind AS space_kind, s.name AS space_name,
               s.owner_user_id AS space_owner_user_id, s.team_id AS space_team_id,
               owner.username AS owner_name,
               owner.display_name AS owner_display_name,
               parent.public_id AS parent_public_id,
               left(p.plain_text, 160) AS excerpt,
               sh.level AS direct_level,
               {$rankShared} AS share_rank
          FROM community.pages p
          JOIN community.spaces s ON s.id = p.space_id
          LEFT JOIN public.users owner ON owner.id = s.owner_user_id
          JOIN community.page_shares sh ON sh.page_id = p.id AND sh.user_id = CAST(:me AS bigint)
          LEFT JOIN community.pages parent ON parent.id = p.parent_page_id
         WHERE p.deleted_at IS NULL
           AND {$visShared}
        {$statusWhere}
         ORDER BY p.updated_at DESC, p.id DESC
         LIMIT CAST(:lim AS int) OFFSET CAST(:off AS int)
        SQL;

    $sharedBind = array_merge([':me' => $me], $visSharedBind, $statusBind);

    $sharedCountRow = $db->run($sqlSharedCount, $sharedBind)->fetch();
    $sharedTotal    = is_array($sharedCountRow) ? (int) $sharedCountRow['n'] : 0;

    $sharedBind[':lim']     = $perPageShared;
    $sharedBind[':off']     = ($pageNoShared - 1) * $perPageShared;
    $sharedBind[':me_rank'] = $me;

    $sharedPages = [];
    foreach ($db->run($sqlShared, $sharedBind)->fetchAll() as $r) {
        $level = community_share_level_name($r['share_rank']);
        // 행마다 「고칠 수 있나」 — 공간·등급이 제각각이라 한 번으로 끝나지 않는다.
        [$canEditRow] = CommunityAuthz::decidePageChange($user, [
            'deleted_at'          => null,
            'visibility'          => (string) $r['visibility'],
            'published_from'      => $r['published_from'],
            'space_kind'          => (string) $r['space_kind'],
            'space_owner_user_id' => $r['space_owner_user_id'],
            'space_team_id'       => $r['space_team_id'],
            'share_level'         => $level,
            'share_user_id'       => $me,
        ]);
        $sharedPages[] = [
            'id'          => (string) $r['public_id'],
            'title'       => (string) $r['title'],
            'icon'        => $r['icon'] === null ? null : (string) $r['icon'],
            'excerpt'     => (string) $r['excerpt'],
            'block_count' => (int) $r['block_count'],
            'doc_version' => (int) $r['doc_version'],
            'visibility'  => (string) $r['visibility'],
            'status'      => $r['status'] === null ? null : (string) $r['status'],
            // 이 페이지에 직접 준 등급과, 조상까지 따진 가장 강한 등급(판정이 쓰는 값).
            'share_level' => $level,
            'direct_level' => (string) $r['direct_level'],
            'owner'       => $r['owner_name'] === null ? null : [
                'username'     => (string) $r['owner_name'],
                // 5-A″ — 이름은 따로(없으면 null)
                'display_name' => $r['owner_display_name'] === null ? null : (string) $r['owner_display_name'],
            ],
            'parent_id'   => $r['parent_public_id'] === null ? null : (string) $r['parent_public_id'],
            'created_at'  => (string) $r['created_at'],
            'updated_at'  => (string) $r['updated_at'],
            'can_edit'    => $canEditRow,
            // 어느 공간의 페이지인가 — 언제나 남의 공간이므로 화면이 그것을 말해야 한다.
            'space'       => [
                'id'   => (int) $r['space_id'],
                'kind' => (string) $r['space_kind'],
                'name' => (string) $r['space_name'],
            ],
        ];
    }

    Http::json([
        'pages'    => $sharedPages,
        'total'    => $sharedTotal,
        'page'     => $pageNoShared,
        'per_page' => $perPageShared,
        'q'        => '',
        // 공간을 가리지 않는 목록이다 — 「지금 보고 있는 공간」이 없다.
        'space'    => null,
        'shared'   => 'me',
        'status'   => $statusRaw,
    ]);
    exit;
}

/* ─── [1] 어느 공간인가 (4-B) ───────────────────────────────── */

$spaceRaw = Http::queryText('space', 20);

if ($spaceRaw === '') {
    // 기본값 — 내 개인 공간. 아직 없으면 빈 목록이다(`GET /api/me/space` 가 만들기 전).
    $space = community_my_personal_space($me);
    if ($space === null) {
        Http::json([
            'pages' => [], 'total' => 0, 'page' => 1, 'per_page' => 20, 'q' => '', 'space' => null,
        ]);
        exit;
    }
} else {
    // 공간 번호는 **양의 정수**만. 형태가 아니면 「그런 공간은 없다」로 답한다.
    if (preg_match('/^[1-9][0-9]{0,17}$/', $spaceRaw) !== 1) {
        Http::error(404, Http::MSG_NOT_FOUND);
    }
    $space = community_space_row((int) $spaceRaw);
    if ($space === null) {
        Http::error(404, Http::MSG_NOT_FOUND);
    }
}

$spaceId = (int) $space['id'];

/* ─── [공간 관문] 이 그릇을 열어 볼 수 있나 (C:T-35 · C:T-36) ─── */
[$mayOpen, $spaceCode] = community_space_gate($user, $space);
if (!$mayOpen) {
    /* 403 동일 문구다. 목록 조회는 t-box 파일 목록과 같이 감사 대상이 아니므로(그 창구에
       붙는 감사 문장이 없다) 서버 로그에만 남긴다. 어느 공간을 열려 했는지는 그 줄에 있다. */
    error_log('[tbox] request_id=' . TBOX_REQUEST_ID
        . ' community space list deny code=' . $spaceCode . ' space_id=' . (string) $spaceId);
    Http::error(403, Http::MSG_FORBIDDEN);
}

/* 이 공간에서 내가 페이지를 만들고 고칠 수 있나 — 공간 하나당 **한 번만** 판단한다.
   행마다 다시 묻지 않는 이유: 「고칠 수 있나」는 개인 공간이면 주인인가, 팀 공간이면
   팀원인가뿐이고 둘 다 행이 아니라 공간의 성질이다 (같은 함수 `decidePageChange`). */
[$canWrite] = CommunityAuthz::decidePageChange($user, [
    'deleted_at'          => null,
    'space_kind'          => (string) $space['kind'],
    'space_owner_user_id' => $space['owner_user_id'] ?? null,
    'space_team_id'       => $space['team_id'] ?? null,
]);

$spaceSummary = [
    'id'        => $spaceId,
    'kind'      => (string) $space['kind'],
    'name'      => (string) $space['name'],
    'team_id'   => $space['team_id'] === null ? null : (int) $space['team_id'],
    'can_write' => $canWrite,
];

/* ─── [행 관문] 볼 수 있는 행 조건절 ────────────────────────── */
[$visible, $visBind] = community_visible_pages_where($user);

/* ─── `?tree=1` — 트리 화면용 (4-A) ─────────────────────────────
 *
 * 그 공간의 **볼 수 있는** 페이지를 한 질의로 전부 돌려준다(id·parent·position·title·icon·
 * visibility). 트리를 만드는 일은 화면이 한다 — 서버가 겹겹이 중첩된 JSON 을 만들면 같은
 * 트리를 두 벌(여기와 화면)로 짜게 되고, 언젠가 한쪽만 고쳐진다.
 *
 * ★ 부모를 따라 한 층씩 묻지 않는다(N+1 금지). 조건절 한 줄이 범위를 정한다 (C:S-02).
 * ★ 평평한 목록 응답(아래)은 **그대로** 둔다 — 찾기·쪽 넘기기는 그 길을 계속 쓴다.
 */
if (Http::queryText('tree', 4) === '1') {
    $sqlTree = <<<SQL
        SELECT p.public_id, p.title, p.icon, p.position, p.updated_at, p.visibility, p.status,
               parent.public_id AS parent_public_id,
               who.username AS assignee_name, who.role AS assignee_role
          FROM community.pages p
          JOIN community.spaces s ON s.id = p.space_id
          LEFT JOIN public.users owner ON owner.id = s.owner_user_id
          LEFT JOIN public.users who ON who.id = p.assignee_id
          LEFT JOIN community.pages parent
                 ON parent.id = p.parent_page_id AND parent.deleted_at IS NULL
         WHERE p.space_id = CAST(:space AS bigint)
           AND p.deleted_at IS NULL
           AND {$visible}
        {$statusWhere}
         ORDER BY p.position, p.id
         LIMIT 2000
        SQL;

    $treeBind = array_merge([':space' => $spaceId], $visBind, $statusBind);

    $tree = [];
    foreach ($db->run($sqlTree, $treeBind)->fetchAll() as $r) {
        $tree[] = [
            'id'         => (string) $r['public_id'],
            'parent_id'  => $r['parent_public_id'] === null ? null : (string) $r['parent_public_id'],
            'position'   => (int) $r['position'],
            'title'      => (string) $r['title'],
            'icon'       => $r['icon'] === null ? null : (string) $r['icon'],
            'visibility' => (string) $r['visibility'],
            // 4-D — 상태 배지·담당자. 둘 다 없음(null)이 기본이다.
            'status'     => $r['status'] === null ? null : (string) $r['status'],
            'assignee'   => $r['assignee_name'] === null ? null : [
                'username' => (string) $r['assignee_name'],
                'role'     => (string) $r['assignee_role'],
            ],
            'updated_at' => (string) $r['updated_at'],
            // 공간 하나당 한 번 판단한 값이다 (DESIGN 원칙 1 — 화면은 판단하지 않는다).
            'can_edit'   => $canWrite,
        ];
    }

    Http::json(['tree' => true, 'pages' => $tree, 'total' => count($tree),
                'space' => $spaceSummary, 'status' => $statusRaw]);
    exit;
}

$perPage = 20;
$pageNo  = Http::queryInt('page', 1, 1, 100000);

$q = Http::queryText('q', 100);
// `%`·`_`·`#` 은 ILIKE 의 뜻 있는 글자다. `#` 로 이스케이프하고 값은 바인딩한다 (PRD S-04).
$like = $q === '' ? '' : '%' . str_replace(['#', '%', '_'], ['##', '#%', '#_'], $q) . '%';

/*
 * 부모 고르기. UUID 형태가 맞고 **이 공간 안에** 있을 때만 그 부모로 좁힌다.
 * 형태가 틀렸거나 이 공간에 없는 값이면 「그런 하위 목록은 비어 있다」로 다룬다 —
 * 있는지 없는지를 응답으로 알려 주지 않는 쪽이 목록 창구에 맞다 (C:S-02).
 */
$parentRaw    = Http::queryText('parent', 36);
$parentPicked = $parentRaw !== '';
$parentId     = -1;   // 어떤 행과도 맞지 않는 값
if ($parentPicked
    && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $parentRaw) === 1
) {
    $sqlParent = <<<'SQL'
        SELECT id FROM community.pages
         WHERE public_id = CAST(:pid AS uuid)
           AND space_id = CAST(:space AS bigint)
           AND deleted_at IS NULL
        SQL;
    $parentRow = $db->run($sqlParent, [':pid' => $parentRaw, ':space' => $spaceId])->fetch();
    if (is_array($parentRow)) {
        $parentId = (int) $parentRow['id'];
    }
}

$bind = array_merge([
    ':space'  => $spaceId,
    // 찾기를 껐나 — 참이면 아래 OR 의 첫 항이 언제나 참이라 뒤의 ILIKE 두 개가 무의미해진다.
    ':qoff'   => $q === '' ? 'true' : 'false',
    ':like1'  => $like,
    ':like2'  => $like,
    // 부모를 안 골랐나 — 참이면 공간 전체를 본다.
    ':pall'   => $parentPicked ? 'false' : 'true',
    ':parent' => $parentId,
], $visBind, $statusBind);

$sqlCount = <<<SQL
    SELECT count(*) AS n
      FROM community.pages p
      JOIN community.spaces s ON s.id = p.space_id
      LEFT JOIN public.users owner ON owner.id = s.owner_user_id
     WHERE p.space_id = CAST(:space AS bigint)
       AND p.deleted_at IS NULL
       AND {$visible}
       AND (CAST(:qoff AS boolean)
            OR p.title ILIKE :like1 ESCAPE '#'
            OR p.plain_text ILIKE :like2 ESCAPE '#')
       AND (CAST(:pall AS boolean)
            OR p.parent_page_id IS NOT DISTINCT FROM CAST(:parent AS bigint))
    {$statusWhere}
    SQL;

$countRow = $db->run($sqlCount, $bind)->fetch();
$total    = is_array($countRow) ? (int) $countRow['n'] : 0;

$sqlList = <<<SQL
    SELECT p.public_id, p.title, p.icon, p.block_count, p.doc_version,
           p.created_at, p.updated_at, p.visibility, p.status,
           parent.public_id AS parent_public_id,
           who.username AS assignee_name, who.role AS assignee_role,
           left(p.plain_text, 160) AS excerpt
      FROM community.pages p
      JOIN community.spaces s ON s.id = p.space_id
      LEFT JOIN public.users owner ON owner.id = s.owner_user_id
      LEFT JOIN public.users who ON who.id = p.assignee_id
      LEFT JOIN community.pages parent ON parent.id = p.parent_page_id
     WHERE p.space_id = CAST(:space AS bigint)
       AND p.deleted_at IS NULL
       AND {$visible}
       AND (CAST(:qoff AS boolean)
            OR p.title ILIKE :like1 ESCAPE '#'
            OR p.plain_text ILIKE :like2 ESCAPE '#')
       AND (CAST(:pall AS boolean)
            OR p.parent_page_id IS NOT DISTINCT FROM CAST(:parent AS bigint))
    {$statusWhere}
     ORDER BY p.updated_at DESC, p.id DESC
     LIMIT CAST(:lim AS int) OFFSET CAST(:off AS int)
    SQL;

$bind[':lim'] = $perPage;
$bind[':off'] = ($pageNo - 1) * $perPage;

$rows = $db->run($sqlList, $bind)->fetchAll();

$pages = [];
foreach ($rows as $r) {
    $pages[] = [
        'id'          => (string) $r['public_id'],
        'title'       => (string) $r['title'],
        'icon'        => $r['icon'] === null ? null : (string) $r['icon'],
        'excerpt'     => (string) $r['excerpt'],
        'block_count' => (int) $r['block_count'],
        'doc_version' => (int) $r['doc_version'],
        'visibility'  => (string) $r['visibility'],
        // 4-D — 상태 배지·담당자. 둘 다 없음(null)이 기본이다.
        'status'      => $r['status'] === null ? null : (string) $r['status'],
        'assignee'    => $r['assignee_name'] === null ? null : [
            'username' => (string) $r['assignee_name'],
            'role'     => (string) $r['assignee_role'],
        ],
        'parent_id'   => $r['parent_public_id'] === null ? null : (string) $r['parent_public_id'],
        'created_at'  => (string) $r['created_at'],
        'updated_at'  => (string) $r['updated_at'],
        // 값을 서버가 내려 주는 이유는 화면이 소유·역할을 스스로 따지지 않게 하려는 것이다
        // (DESIGN 원칙 1). 단추를 되살려 창구를 불러도 서버가 같은 함수로 다시 막는다.
        'can_edit'    => $canWrite,
    ];
}

Http::json([
    'pages'    => $pages,
    'total'    => $total,
    'page'     => $pageNo,
    'per_page' => $perPage,
    'q'        => $q,
    'space'    => $spaceSummary,
    'status'   => $statusRaw,
]);
