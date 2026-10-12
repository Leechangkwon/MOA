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
 *   cursor 서버가 준 위치 문자열. page는 1~20까지만 호환, 두 값을 함께 주면400. 한 쪽20건.
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

require_once __DIR__ . '/../BoardCursor.php';
$perPage = 20;
$pageNo = 1;
if (array_key_exists('page', $_GET)) {
    $rawPage = $_GET['page'];
    if (!is_string($rawPage) || preg_match('/^[1-9][0-9]?$/D', $rawPage) !== 1 || (int) $rawPage > 20) {
        Http::error(400, Http::MSG_BAD_REQUEST);
    }
    $pageNo = (int) $rawPage;
}
$q = trim(Http::queryText('q', 100));
$tag = (string) preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', Http::queryText('tag', COMMUNITY_TAG_MAX_CHARS));
$sortKey = Http::queryChoice('sort', ['new','updated','views'], 'new');
if (array_key_exists('cursor', $_GET) && array_key_exists('page', $_GET)) { Http::error(400, Http::MSG_BAD_REQUEST); }
$cursor = BoardCursor::decode($_GET['cursor'] ?? null, $boardId, $sortKey, $q, $tag);
$empty = ['board'=>community_board_summary($board, $user), 'pages'=>[], 'per_page'=>$perPage,
          'next_cursor'=>null, 'prev_cursor'=>null, 'has_next'=>false, 'has_prev'=>false];
if ($q !== '' && !SearchGuard::longEnough($q)) {
    Http::json($empty + ['search_status'=>'short','notice'=>SearchGuard::SHORT]);
    return;
}
// 작은 옛 page 호환도 OFFSET 없이 커서를 최대20번 걷는다. q 전체 예산은 같은3초다.
$query = static function (callable $run) use ($user,$boardId,$sortKey,$q,$tag,$cursor,$pageNo,$perPage): array {
    $position = $cursor;
    for ($step = 1; $step <= $pageNo; $step++) {
        [$sql,$bind] = BoardCursor::sql($user,$boardId,$sortKey,$q,$tag,$position,$perPage);
        $rows = $run($sql,$bind)->fetchAll();
        $more = count($rows) > $perPage;
        if ($more) { array_pop($rows); }
        $previous = ($position['direction'] ?? 'next') === 'prev';
        if ($previous) { $rows = array_reverse($rows); }
        $hasPrev = $rows !== [] && ($previous ? $more : $position !== null);
        $hasNext = $rows !== [] && ($previous ? $position !== null : $more);
        $prev = $hasPrev ? BoardCursor::encode($boardId,$sortKey,$q,$tag,'prev',$rows[0]) : null;
        $next = $hasNext ? BoardCursor::encode($boardId,$sortKey,$q,$tag,'next',$rows[count($rows)-1]) : null;
        if ($step === $pageNo) { return [$rows,$prev,$next]; }
        if ($next === null) { return [[],null,null]; }
        $position = BoardCursor::decode($next,$boardId,$sortKey,$q,$tag);
    }
    return [[],null,null];
};
try {
    [$rows,$prevCursor,$nextCursor] = $q !== '' ? SearchGuard::execute((int) $user['id'], $query) : $query([$db, 'run']);
} catch (Throwable $e) {
    SearchGuard::limited($e, $empty);
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
    'next_cursor' => $nextCursor,
    'prev_cursor' => $prevCursor,
    'has_next' => $nextCursor !== null,
    'has_prev' => $prevCursor !== null,
    'per_page' => $perPage,
    'q'        => $q,
    'tag'      => $tag,
    'sort'     => $sortKey,
]);
