<?php
declare(strict_types=1);

/**
 * src/community/handlers/search.php — GET /community/api/search?q= (정책: community.read)
 *
 * 요구사항: 커뮤니티 PRD F-26 중 **검색**(5-B, 사용자 결정 2026-09-23 「검색은 앞당긴다」),
 *           `C:S-02`(못 보는 것은 응답에 없다) · `C:S-04`(SQL 바인딩), ARCHITECTURE 21.12.
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
 * 응답 — 최대 20건. 제목에 맞은 것이 먼저, 그다음 최근 고친 순.
 *   {results: [{id, title, icon, space: {kind, name}, path: [{id, title}], snippet, in_title}], q}
 *   `snippet` 은 본문에서 찾은 자리 앞뒤 글자(평문). 화면이 `textContent` 로만 넣는다.
 *   `path` 는 경로 줄과 같은 함수(`community_breadcrumb`) — 볼 수 있는 조상까지만.
 *
 * @var array<string,mixed> $user 라우터가 넘긴 현재 사용자
 */

$db = Db::instance();

$q = trim(Http::queryText('q', 100));
if ($q === '') {
    Http::json(['results' => [], 'q' => '']);
    exit;
}

// `%`·`_`·`#` 은 ILIKE 의 뜻 있는 글자다. `#` 로 이스케이프하고 값은 바인딩한다 (PRD S-04).
$like = '%' . str_replace(['#', '%', '_'], ['##', '#%', '#_'], $q) . '%';

/* ★ 위 머리글 — 검색은 관리자 특권 갈래를 쓰지 않는다. 5-C 부터 같은 일을 한 함수가 한다:
   관리자 갈래(`admin_all`)와 게시판 관리자 갈래(`board_moderator` — 숨긴 글)를 함께 끈다. */
[$visible, $visBind] = community_search_pages_where($user);

$sql = <<<SQL
    SELECT p.id, p.public_id, p.title, p.icon, p.space_id,
           s.kind AS space_kind, s.name AS space_name,
           (p.title ILIKE :like1 ESCAPE '#') AS in_title,
           left(p.plain_text, 20000) AS body
      FROM community.pages p
      JOIN community.spaces s ON s.id = p.space_id
      LEFT JOIN public.users owner ON owner.id = s.owner_user_id
     WHERE p.deleted_at IS NULL
       AND {$visible}
       -- 5-C — 숨긴 게시판 글은 검색에도 없다(쓴 사람에게도 — 숨긴 글은 주소로만 연다)
       AND NOT (s.kind = 'board' AND p.hidden_at IS NOT NULL)
       AND (p.title ILIKE :like2 ESCAPE '#' OR p.plain_text ILIKE :like3 ESCAPE '#')
     ORDER BY (p.title ILIKE :like4 ESCAPE '#') DESC, p.updated_at DESC, p.id DESC
     LIMIT 20
    SQL;

$rows = $db->run($sql, array_merge([
    ':like1' => $like, ':like2' => $like, ':like3' => $like, ':like4' => $like,
], $visBind))->fetchAll();

/** 본문에서 찾은 자리 앞뒤 — 평문 그대로, 줄바꿈은 빈칸으로. 못 찾으면 본문 첫머리.
 *  mbstring 이 이 서버에 없다(2026-09-23 실측 — `mb_stripos` 500) — `/u` 정규식이 글자 단위로 센다. */
$snippetOf = static function (string $body, string $needle): string {
    $flat = trim(preg_replace('/\s+/u', ' ', $body) ?? '');
    $pat  = '/(.{0,40})(' . preg_quote($needle, '/') . ')(.{0,80})/isu';
    if (preg_match($pat, $flat, $m, PREG_OFFSET_CAPTURE) === 1) {
        $head = $m[1][1] > 0 ? '…' : '';
        $tail = ($m[3][1] + strlen($m[3][0])) < strlen($flat) ? '…' : '';
        return $head . $m[1][0] . $m[2][0] . $m[3][0] . $tail;
    }
    return preg_match('/^.{0,90}/su', $flat, $m) === 1 ? $m[0] . (strlen($m[0]) < strlen($flat) ? '…' : '') : '';
};

$results = [];
foreach ($rows as $r) {
    $path = [];
    foreach (community_breadcrumb((int) $r['id'], (int) $r['space_id'], $user) as $crumb) {
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
        'snippet'  => $snippetOf((string) $r['body'], $q),
        'in_title' => $r['in_title'] === true || $r['in_title'] === 't',
    ];
}

Http::json(['results' => $results, 'q' => $q]);
