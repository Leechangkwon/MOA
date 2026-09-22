<?php
declare(strict_types=1);

/**
 * src/community/handlers/pages_list.php — GET /community/api/pages (정책: community.read)
 *
 * 요구사항: `C:F-17` 최소(목록), `C:S-02`(못 보는 것은 응답에 없다), `C:S-04`,
 *           `../../../../community/ARCHITECTURE.md` 13.3 · 21.3.
 *
 * 쿼리 (전부 선택 — 없거나 이상하면 **거부하지 않고 안전한 값으로 좁힌다**, t-box ARCH 5.1)
 *   q      제목·본문 찾기 (≤ 100자). `ILIKE` 는 `#` 로 이스케이프한 뒤 **바인딩**한다
 *   parent 하위 페이지만 (UUID). 없으면 공간의 모든 페이지를 평평하게 보인다(3단계는 트리가 없다)
 *   page   1부터. 한 쪽 20건
 *
 * ★ 범위는 **SQL 이 정한다** (C:S-02). `WHERE p.space_id = 내 개인 공간` 이므로 남의 공간은
 *   목록 질의에 아예 들어오지 않는다 — 애플리케이션이 나중에 걸러 내는 코드가 없다.
 *   내 공간이 아직 없으면 빈 목록이다(`GET /api/me/space` 가 만들기 전에 이 창구를 불렀을 때).
 *
 * ★ SQL 문장은 **고정 문자열 두 개**다. 「찾기를 켰나」·「부모를 골랐나」는 SQL 조각을 이어
 *   붙이지 않고 **바인딩한 참/거짓**으로 끈다 — 문자열을 조립하는 자리 자체를 없앤다 (PRD S-04).
 *
 * 목록 조회는 감사에 남기지 않는다 (t-box 파일 목록과 같은 취급).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params 경로 변수 없음
 */

$db = Db::instance();
$me = (int) $user['id'];

$sqlSpace = <<<'SQL'
    SELECT id FROM community.spaces
     WHERE kind = 'personal' AND owner_user_id = CAST(:uid AS bigint)
    SQL;

$spaceRow = $db->run($sqlSpace, [':uid' => $me])->fetch();
if (!is_array($spaceRow)) {
    Http::json(['pages' => [], 'total' => 0, 'page' => 1, 'per_page' => 20, 'q' => '']);
    exit;
}
$spaceId = (int) $spaceRow['id'];

$perPage = 20;
$pageNo  = Http::queryInt('page', 1, 1, 100000);

$q = Http::queryText('q', 100);
// `%`·`_`·`#` 은 ILIKE 의 뜻 있는 글자다. `#` 로 이스케이프하고 값은 바인딩한다 (PRD S-04).
$like = $q === '' ? '' : '%' . str_replace(['#', '%', '_'], ['##', '#%', '#_'], $q) . '%';

/*
 * 부모 고르기. UUID 형태가 맞고 **내 공간 안에** 있을 때만 그 부모로 좁힌다.
 * 형태가 틀렸거나 내 공간에 없는 값이면 「그런 하위 목록은 비어 있다」로 다룬다 —
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

$bind = [
    ':space'  => $spaceId,
    // 찾기를 껐나 — 참이면 아래 OR 의 첫 항이 언제나 참이라 뒤의 ILIKE 두 개가 무의미해진다.
    ':qoff'   => $q === '' ? 'true' : 'false',
    ':like1'  => $like,
    ':like2'  => $like,
    // 부모를 안 골랐나 — 참이면 공간 전체를 본다.
    ':pall'   => $parentPicked ? 'false' : 'true',
    ':parent' => $parentId,
];

$sqlCount = <<<'SQL'
    SELECT count(*) AS n
      FROM community.pages p
     WHERE p.space_id = CAST(:space AS bigint)
       AND p.deleted_at IS NULL
       AND (CAST(:qoff AS boolean)
            OR p.title ILIKE :like1 ESCAPE '#'
            OR p.plain_text ILIKE :like2 ESCAPE '#')
       AND (CAST(:pall AS boolean)
            OR p.parent_page_id IS NOT DISTINCT FROM CAST(:parent AS bigint))
    SQL;

$countRow = $db->run($sqlCount, $bind)->fetch();
$total    = is_array($countRow) ? (int) $countRow['n'] : 0;

$sqlList = <<<'SQL'
    SELECT p.public_id, p.title, p.icon, p.block_count, p.doc_version,
           p.created_at, p.updated_at, p.visibility,
           parent.public_id AS parent_public_id,
           left(p.plain_text, 160) AS excerpt
      FROM community.pages p
      LEFT JOIN community.pages parent ON parent.id = p.parent_page_id
     WHERE p.space_id = CAST(:space AS bigint)
       AND p.deleted_at IS NULL
       AND (CAST(:qoff AS boolean)
            OR p.title ILIKE :like1 ESCAPE '#'
            OR p.plain_text ILIKE :like2 ESCAPE '#')
       AND (CAST(:pall AS boolean)
            OR p.parent_page_id IS NOT DISTINCT FROM CAST(:parent AS bigint))
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
        'parent_id'   => $r['parent_public_id'] === null ? null : (string) $r['parent_public_id'],
        'created_at'  => (string) $r['created_at'],
        'updated_at'  => (string) $r['updated_at'],
        // 내 개인 공간의 페이지이므로 언제나 참이다. 값을 서버가 내려 주는 이유는 화면이
        // 소유·역할을 스스로 따지지 않게 하려는 것이다 (DESIGN 원칙 1).
        'can_edit'    => true,
    ];
}

Http::json([
    'pages'    => $pages,
    'total'    => $total,
    'page'     => $pageNo,
    'per_page' => $perPage,
    'q'        => $q,
]);
