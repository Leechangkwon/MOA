<?php
declare(strict_types=1);

/**
 * src/community/handlers/search.php — GET /community/api/search?q= (정책: community.read)
 *
 * 요구사항: 커뮤니티 PRD F-26 중 **검색**(5-B, 사용자 결정 2026-09-23 「검색은 앞당긴다」),
 *           `C:S-02`(못 보는 것은 응답에 없다) · `C:S-04`(SQL 바인딩), ARCHITECTURE 21.12,
 *           **사이트 PRD Q:F-2 통합 검색 + 한글 색인**(지시서 docs/qf2-unified-search-directive.md 2.2·2.3).
 *
 * Q:F-2 — 한글이 든 검색은 `search_tsv`(두 글자 조각 + 한 글자, `SearchText`)로 후보를 추리고 낱말마다
 *   ILIKE 로 원문 일치를 다시 확인한다. 한글이 없으면(영문·숫자만) 지금과 같은 ILIKE 전수(J-10 ⓑ).
 *   여러 낱말은 AND(낱말마다 제목·본문·태그 중 어디든). 글자·숫자가 하나도 없는 검색어는 빈 결과(J-11).
 *   SQL 은 `community_search_sql()` 한 벌(성능 시험 EXPLAIN 도 같은 문장), 경로는 `community_breadcrumbs_many()`
 *   한 문장(결과마다 부르던 N+1 을 없앴다). 댓글도 함께 찾는다(볼 수 있는 페이지의 살아 있는·숨기지 않은 댓글).
 *
 * 받는 값
 *   q  찾을 말 (앞뒤 공백을 뗀 뒤 1~100자). 비었으면 **빈 결과**(200) — 한 글자도 치지 않은 채
 *      「전부」가 나오는 자리를 만들지 않는다.
 *
 * ★ 범위는 **목록과 같은 조건절**이다(`community_visible_pages_where`). 공간은 가리지 않는다 —
 *   「공유받은 페이지」(`?shared=me`)와 같은 까닭이다: 검색은 여러 공간에 걸친다. 대신 행 관문은
 *   그대로라 볼 수 없는 페이지는 결과·개수·조각 어디에도 없다. 5-A″ 에서 팀 공간 공유가 조건절에
 *   들어오면 검색도 저절로 따라간다(규칙을 두 벌로 적지 않는다).
 *
 * ★ **관리자 특권 갈래는 끈다**(`:vis_admin` = false). 목록 조건절의 그 갈래를 그대로 쓰면 남의
 *   비공개 글이 검색 한 번에 한꺼번에 드러난다. 관리자가 남의 페이지를 보는 일은 주소를 하나씩
 *   열 때만이고, 그때마다 `page.read allow admin_all` 로 감사에 남는다(PRD 4.1).
 *
 * ★ 사내(`internal`)로 연 남의 페이지는 결과에 **나온다** — 사내 공개의 뜻이 그것이다. 남의 공간
 *   목차를 통째로 넘겨보는 일(C:T-35)은 여전히 막혀 있다: 검색어가 맞는 페이지만 나온다.
 *
 * 응답 — 페이지 최대 20건(제목에 다 맞은 것이 먼저, 그다음 최근 고친 순) + 댓글 최대 20건(최근 순).
 *   {results: [{id, title, icon, space: {kind, name}, path: [{id, title}], snippet, in_title}],
 *    comments: [{id, page: {id, title, icon, space: {kind, name}}, author: {username, display_name}, snippet, created_at}], q}
 *   `snippet` 은 본문에서 찾은 자리 앞뒤 글자(평문). 화면이 `textContent` 로만 넣는다.
 *   `path` 는 경로 줄과 같은 판정(`community_breadcrumbs_many`) — 볼 수 있는 조상까지만.
 *   전체 개수는 세지도 내려 주지도 않는다(지시서 2.6).
 *
 * @var array<string,mixed> $user 라우터가 넘긴 현재 사용자
 */

require_once __DIR__ . '/../../SearchGuard.php';
$db = Db::instance();

$q = trim(Http::queryText('q', 100));
if (!SearchGuard::longEnough($q)) {
    Http::json(['results' => [], 'comments' => [], 'q' => $q, 'search_status' => 'short', 'notice' => SearchGuard::SHORT]);
    return;
}
// J-11 — 글자·숫자가 하나도 없으면(「#」「%%」) 빈 결과. 빈 검색어도 빈 결과 — 한 글자도 치지 않은 채
// 「전부」가 나오는 자리를 만들지 않는다.
if ($q === '' || !SearchText::hasWordChar($q)) {
    Http::json(['results' => [], 'comments' => [], 'q' => $q]);
    exit;
}

// ① 조합(맥 NFD → 음절) 뒤 낱말로 — 같은 규칙으로 만든 색인과 맞춘다. 값은 전부 바인딩(S-04).
$terms = SearchText::queryTerms($q);
if ($terms === [] || count($terms) > 60) {
    Http::json(['results' => [], 'comments' => [], 'q' => $q]);
    exit;
}

/* ★ 위 머리글 — 검색은 관리자 특권 갈래를 쓰지 않는다. 범위 조건절은 `community_search_pages_where`
   (관리자 갈래 `admin_all` · 게시판 관리자 갈래 `board_moderator` 를 함께 끈다) 그대로다 — 이 파일에도
   `community_search_sql()` 에도 새 판정이 없다. */
[$sqlPages, $bindPages] = community_search_sql($user, $terms, 'pages');
[$sqlComments, $bindComments] = community_search_sql($user, $terms, 'comments');
// 읽기만 하는 트랜잭션 하나 — 페이지 문장의 계획 설정(SET LOCAL)이 이 안에서만 걸리고 끝나면 풀린다(routes.php 상수 머리글)
try {
    [$rows, $crow] = SearchGuard::execute((int) $user['id'], static function (callable $run) use ($sqlPages, $bindPages, $sqlComments, $bindComments): array {
        $run(COMMUNITY_SEARCH_PAGES_PLAN_ON);
        $rows = $run($sqlPages, $bindPages)->fetchAll();
        $run(COMMUNITY_SEARCH_PAGES_PLAN_OFF);
        return [$rows, $run($sqlComments, $bindComments)->fetchAll()];
    });
} catch (Throwable $e) {
    SearchGuard::limited($e, ['results' => [], 'comments' => [], 'q' => $q]);
    return;
}

/** 본문에서 찾은 자리 앞뒤 — 평문 그대로, 줄바꿈은 빈칸으로. 낱말 중 먼저 찾은 것, 못 찾으면 본문 첫머리.
 *  mbstring 이 이 서버에 없다(2026-09-23 실측 — `mb_stripos` 500) — `/u` 정규식이 글자 단위로 센다.
 *  본문은 조합(SearchText::compose)한 뒤에 본다 — NFD 로 적힌 글에서도 조합한 검색어의 자리를 찾는다. */
$snippetOf = static function (string $body, array $terms, int $before, int $after): string {
    $flat = trim(preg_replace('/\s+/u', ' ', SearchText::compose($body)) ?? '');
    foreach ($terms as $t) {
        $pat = '/(.{0,' . $before . '})(' . preg_quote((string) $t['text'], '/') . ')(.{0,' . $after . '})/isu';
        if (preg_match($pat, $flat, $m, PREG_OFFSET_CAPTURE) === 1) {
            $head = $m[1][1] > 0 ? '…' : '';
            $tail = ($m[3][1] + strlen($m[3][0])) < strlen($flat) ? '…' : '';
            return $head . $m[1][0] . $m[2][0] . $m[3][0] . $tail;
        }
    }
    $n = $before + $after + 10;
    return preg_match('/^.{0,' . $n . '}/su', $flat, $m) === 1 ? $m[0] . (strlen($m[0]) < strlen($flat) ? '…' : '') : '';
};

// 경로 — 결과 20건을 한 문장으로(지시서 2.2 ⑥)
$paths = community_breadcrumbs_many(array_map(static fn (array $r): array => [
    'id' => (int) $r['id'], 'space_id' => (int) $r['space_id'],
], $rows), $user);

$results = [];
foreach ($rows as $r) {
    $path = [];
    foreach ($paths[(int) $r['id']] ?? [] as $crumb) {
        // 경로 줄의 마지막 칸은 이 페이지 자신이다 — 결과 제목과 겹치므로 뺀다
        if (($crumb['id'] ?? '') === (string) $r['public_id']) {
            continue;
        }
        $path[] = ['id' => (string) $crumb['id'], 'title' => (string) $crumb['title']];
    }
    $results[] = [
        'id'       => (string) $r['public_id'],
        'title'    => (string) $r['title'],
        'icon'     => $r['icon'] === null ? null : (string) $r['icon'],
        'space'    => ['kind' => (string) $r['space_kind'], 'name' => (string) $r['space_name']],
        'path'     => $path,
        'snippet'  => $snippetOf((string) $r['body'], $terms, 40, 80),
        'in_title' => $r['in_title'] === true || $r['in_title'] === 't',
    ];
}

$comments = [];
foreach ($crow as $c) {
    $comments[] = [
        'id'         => (string) $c['comment_id'],
        'page'       => [
            'id'    => (string) $c['public_id'],
            'title' => (string) $c['title'],
            'icon'  => $c['icon'] === null ? null : (string) $c['icon'],
            'space' => ['kind' => (string) $c['space_kind'], 'name' => (string) $c['space_name']],
        ],
        // 5-A″ — 창구는 이름과 아이디를 **따로** 준다(잇는 일은 화면의 personLabel 한 곳)
        'author'     => [
            'username'     => (string) $c['author_username'],
            'display_name' => $c['author_display_name'] === null ? null : (string) $c['author_display_name'],
        ],
        'snippet'    => $snippetOf((string) $c['body'], $terms, 30, 80),
        'created_at' => (string) $c['created_at'],
    ];
}

Http::json(['results' => $results, 'comments' => $comments, 'q' => $q]);
