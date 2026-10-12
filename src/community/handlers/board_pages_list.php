<?php
declare(strict_types=1);

/**
 * src/community/handlers/board_pages_list.php — GET /community/api/boards/{num}/pages (정책: community.read)
 *
 * 요구사항: 커뮤니티 PRD 4장 · F-04 · F-18(「게시판 목록·검색에 보인다」) · F-26(태그·조회수) · S-02 · S-04,
 *           `../../../../community/stage5c-board-publish-directive.md` 「창구」·「화면」.
 *
 * 쿼리 (전부 선택 — 이상하면 거부하지 않고 안전한 값으로 좁힌다, t-box ARCH 5.1)
 *   q     제목·본문 찾기(100자까지) — `ILIKE` 는 `#` 로 이스케이프한 뒤 **바인딩**
 *   tag   이 태그가 붙은 글만(30자까지, 대소문자 무시 — DB `lower()`)
 *   sort  `new`(기본, 올린 시각) · `updated`(고친 시각) · `views`(조회수) — **허용 목록 → 고정 SQL 조각**
 *   page  1부터, 한 쪽 20건
 *
 * 관문 둘 (C:S-02)
 *   [게시판] 없거나 **읽을 수 없는** 게시판은 **403 동일 문구**다(PRD F-04 — 번호 열거 방지. 404 로
 *            가르면 「그 번호의 게시판이 있다」가 샌다).
 *   [행]     `community_visible_pages_where()` — `decidePageRead` 를 옮긴 조건절. 게시판을 읽을 수 있으면
 *            숨기지 않은 글은 전부 이 조건을 통과한다. 그래도 붙이는 까닭은 판정과 목록을 **한 벌**로
 *            두기 위해서다(규칙이 바뀌는 날 목록만 넓어지는 일이 없게).
 *   + **숨긴 글은 목록에서 빠진다**(`hidden_at IS NULL`) — 쓴 사람·관리자에게도. 숨긴 글은 주소로만 연다.
 *
 * 목록에 오는 것 = 이 게시판의 **새 글**(게시판 공간의 페이지) + 이 게시판에 **게시된 개인 페이지**.
 * 항목: id · 제목 · 아이콘 · 종류(`post`·`published`) · 쓴 사람(아이디·이름 — 게시된 페이지는 주인) ·
 *       올린 시각(새 글은 만든 시각, 게시된 페이지는 게시 시각) · 고친 시각 · 태그 · 조회수 · 해결됨 · 발췌.
 * 누가 봤는지는 내보내지 않는다(조회수는 수만 — 공지 읽음 확인은 5-D).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params [0] = 게시판 번호
 */

require_once __DIR__ . '/../../SearchGuard.php';
$db      = Db::instance();
$boardId = (int) $params[0];

$board = community_board_row($boardId);
if ($board === null) {
    Http::error(403, Http::MSG_FORBIDDEN);
}
[$mayRead, $readCode] = CommunityAuthz::decideBoardRead($user, $board);
if (!$mayRead) {
    error_log('[tbox] request_id=' . TBOX_REQUEST_ID
        . ' community board list deny code=' . $readCode . ' board_id=' . (string) $boardId);
    Http::error(403, Http::MSG_FORBIDDEN);
}

$perPage = 20;
$pageNo  = Http::queryInt('page', 1, 1, 100000);

$q    = trim(Http::queryText('q', 100));
if ($q !== '' && !SearchGuard::longEnough($q)) {
    Http::json(['board' => community_board_summary($board, $user), 'pages' => [], 'total' => 0, 'page' => $pageNo, 'per_page' => $perPage, 'search_status' => 'short', 'notice' => SearchGuard::SHORT]);
    return;
}
$like = $q === '' ? '' : '%' . str_replace(['#', '%', '_'], ['##', '#%', '#_'], $q) . '%';
$tag  = (string) preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', Http::queryText('tag', COMMUNITY_TAG_MAX_CHARS));

// 정렬 — 허용 목록의 **고정 SQL 조각**만. 요청 값은 표의 열쇠로만 쓰이고 SQL 에 들어가지 않는다 (PRD S-04).
$sorts = [
    'new'     => ' ORDER BY posted_at DESC, p.id DESC',
    'updated' => ' ORDER BY p.updated_at DESC, p.id DESC',
    'views'   => ' ORDER BY views DESC, posted_at DESC, p.id DESC',
];
$sortKey = Http::queryText('sort', 10);
if (!array_key_exists($sortKey, $sorts)) {
    $sortKey = 'new';
}
$orderBy = $sorts[$sortKey];

[$visible, $visBind] = community_visible_pages_where($user);

$where = <<<SQL
     WHERE p.deleted_at IS NULL
       AND p.hidden_at IS NULL
       AND (p.space_id = CAST(:b1 AS bigint)
            OR (p.visibility = 'published' AND p.published_board_id = CAST(:b2 AS bigint)))
       AND {$visible}
       AND (CAST(:qoff AS boolean)
            OR p.title ILIKE :like1 ESCAPE '#'
            OR p.plain_text ILIKE :like2 ESCAPE '#')
       AND (CAST(:toff AS boolean)
            OR EXISTS (SELECT 1 FROM community.page_tags tf
                        WHERE tf.page_id = p.id AND tf.tag_norm = lower(:tag)))
SQL;

$bind = array_merge([
    ':b1'    => $boardId,
    ':b2'    => $boardId,
    ':qoff'  => $q === '' ? 'true' : 'false',
    ':like1' => $like,
    ':like2' => $like,
    ':toff'  => $tag === '' ? 'true' : 'false',
    ':tag'   => $tag,
], $visBind);

$sqlCount = <<<SQL
    SELECT count(*) AS n
      FROM community.pages p
      JOIN community.spaces s ON s.id = p.space_id
      LEFT JOIN public.users owner ON owner.id = s.owner_user_id
    {$where}
    SQL;

$sqlList = <<<SQL
    SELECT p.id, p.public_id, p.title, p.icon, p.visibility, p.created_at, p.updated_at, p.resolved,
           s.kind AS space_kind,
           (CASE WHEN p.visibility = 'published' THEN p.published_at ELSE p.created_at END) AS posted_at,
           (CASE WHEN s.kind = 'board' THEN au.username     ELSE owner.username     END) AS writer_name,
           (CASE WHEN s.kind = 'board' THEN au.display_name ELSE owner.display_name END) AS writer_display_name,
           left(p.plain_text, 160) AS excerpt,
           (SELECT json_agg(t.tag ORDER BY t.tag_norm) FROM community.page_tags t WHERE t.page_id = p.id) AS tags,
           (SELECT count(*) FROM community.page_views v WHERE v.page_id = p.id) AS views
      FROM community.pages p
      JOIN community.spaces s ON s.id = p.space_id
      LEFT JOIN public.users owner ON owner.id = s.owner_user_id
      LEFT JOIN public.users au    ON au.id    = p.author_id
    {$where}
    {$orderBy}
     LIMIT CAST(:lim AS int) OFFSET CAST(:off AS int)
    SQL;

$bind[':lim'] = $perPage;
$bind[':off'] = ($pageNo - 1) * $perPage;

$query = static function (callable $run) use ($sqlCount, $sqlList, $bind): array {
    $countBind = $bind;
    unset($countBind[':lim'], $countBind[':off']);
    $countRow = $run($sqlCount, $countBind)->fetch();
    return [is_array($countRow) ? (int) $countRow['n'] : 0, $run($sqlList, $bind)->fetchAll()];
};
try {
    [$total, $rows] = $q !== '' ? SearchGuard::execute((int) $user['id'], $query) : $query([$db, 'run']);
} catch (Throwable $e) {
    SearchGuard::limited($e, ['board' => community_board_summary($board, $user), 'pages' => [], 'total' => 0, 'page' => $pageNo, 'per_page' => $perPage]);
    return;
}
$items = [];
foreach ($rows as $r) {
    $tags = $r['tags'] === null ? [] : json_decode((string) $r['tags'], true);
    $items[] = [
        'id'         => (string) $r['public_id'],
        'title'      => (string) $r['title'],
        'icon'       => $r['icon'] === null ? null : (string) $r['icon'],
        // `post` = 게시판에서 바로 쓴 글 · `published` = 개인 페이지를 건 것(원본은 주인 공간에 있다)
        'kind'       => (string) $r['space_kind'] === 'board' ? 'post' : 'published',
        'writer'     => $r['writer_name'] === null ? null : [
            'username'     => (string) $r['writer_name'],
            'display_name' => $r['writer_display_name'] === null ? null : (string) $r['writer_display_name'],
        ],
        'posted_at'  => (string) $r['posted_at'],
        'updated_at' => (string) $r['updated_at'],
        'tags'       => is_array($tags) ? array_values(array_map('strval', $tags)) : [],
        'views'      => (int) $r['views'],
        'resolved'   => $r['resolved'] === true,
        'excerpt'    => (string) $r['excerpt'],
    ];
}

Http::json([
    'board'    => community_board_summary($board, $user),
    'pages'    => $items,
    'total'    => $total,
    'page'     => $pageNo,
    'per_page' => $perPage,
    'q'        => $q,
    'tag'      => $tag,
    'sort'     => $sortKey,
]);
