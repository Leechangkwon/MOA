<?php
declare(strict_types=1);

/**
 * src/community/handlers/board_tags.php — GET /community/api/boards/{num}/tags?q= (정책: community.read)
 *
 * 요구사항: 사용자 결정 2026-09-23 ② 「태그는 자유 입력 + **자동완성**(그 게시판에서 이미 쓰인 태그를 추천)」,
 *           지시서 5-C 「태그 — 자동완성은 그 게시판에서 **볼 수 있는** 페이지의 태그만, 최대 10」.
 *
 * ★ 남의 비공개 페이지 태그가 새지 않는다 — 태그를 모으는 페이지 범위가 **게시판 목록과 같은 조건**이다:
 *   이 게시판의 새 글 + 이 게시판에 게시된 페이지, 숨기지 않은 것, 그리고 행 관문
 *   `community_visible_pages_where()`. 게시판 밖(개인 공간)의 태그는 모으지 않는다.
 * 게시판이 없거나 읽을 수 없으면 **403 동일 문구**(번호 열거 방지).
 *
 * `q` — 앞뒤 공백을 뗀 30자까지. 비면 그 게시판에서 **많이 쓰인** 태그 10개, 있으면 그 글자가 든 태그
 * (대소문자 무시 `ILIKE`, `#` 이스케이프 · 바인딩). 같은 태그(대소문자 무시)는 한 줄로 묶고, 가장 많이
 * 쓰인 글자 모양을 보인다.
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params [0] = 게시판 번호
 */

$db      = Db::instance();
$boardId = (int) $params[0];

$board = community_board_row($boardId);
if ($board === null) {
    Http::error(403, Http::MSG_FORBIDDEN);
}
[$mayRead] = CommunityAuthz::decideBoardRead($user, $board);
if (!$mayRead) {
    Http::error(403, Http::MSG_FORBIDDEN);
}

$q    = (string) preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', Http::queryText('q', COMMUNITY_TAG_MAX_CHARS));
$like = $q === '' ? '' : '%' . str_replace(['#', '%', '_'], ['##', '#%', '#_'], $q) . '%';

[$visible, $visBind] = community_visible_pages_where($user);

$sql = <<<SQL
    SELECT t.tag_norm,
           (array_agg(t.tag ORDER BY t.tag))[1] AS tag,
           count(*) AS n
      FROM community.page_tags t
      JOIN community.pages p ON p.id = t.page_id
      JOIN community.spaces s ON s.id = p.space_id
      LEFT JOIN public.users owner ON owner.id = s.owner_user_id
     WHERE p.deleted_at IS NULL
       AND p.hidden_at IS NULL
       AND (p.space_id = CAST(:b1 AS bigint)
            OR (p.visibility = 'published' AND p.published_board_id = CAST(:b2 AS bigint)))
       AND {$visible}
       AND (CAST(:qoff AS boolean) OR t.tag ILIKE :like ESCAPE '#')
     GROUP BY t.tag_norm
     ORDER BY count(*) DESC, t.tag_norm
     LIMIT 10
    SQL;

$tags = [];
foreach ($db->run($sql, array_merge([
    ':b1'   => $boardId,
    ':b2'   => $boardId,
    ':qoff' => $q === '' ? 'true' : 'false',
    ':like' => $like,
], $visBind))->fetchAll() as $r) {
    $tags[] = ['tag' => (string) $r['tag'], 'count' => (int) $r['n']];
}

Http::json(['tags' => $tags, 'q' => $q]);
