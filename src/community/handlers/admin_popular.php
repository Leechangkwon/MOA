<?php
declare(strict_types=1);

/**
 * src/community/handlers/admin_popular.php — GET /community/api/admin/popular (정책: role:Admin — 재인증 관문이 따라붙는다)
 *
 * 요구사항: 커뮤니티 PRD 11장 7행 「많이 읽힌 문서」(N-34) · 지시서 `stage7-finish-directive.md` 「7-D 확정」 L-1 · 결정 J-6.
 *
 *   지난 30일 조회(`page_views` — 사람 × 날 한 줄)가 많은 페이지 20개. **사내 전체에 열린 페이지만** 목록에 오른다 —
 *   비공개·팀 범위·팀 공간 페이지와, 사내 전체가 못 보게 된 숨긴 글은 제목이 목록에 나오지 않는다(J-6).
 *   열린 것의 뜻은 판정 함수(`decidePageRead`)와 같다:
 *     · 개인 공간의 기준 범위 `internal`(게시 중이면 게시 전 범위) — **숨긴 게시 페이지도** 원래 범위가 사내면 사내 전체가 읽으므로 오른다
 *     · 사내 전체가 읽는 등급(매트릭스)의 게시판에 게시되어 숨기지 않은 것 · 그런 게시판의 글(숨기지 않은 것)
 *     · 보관(비활성) 게시판도 읽기는 열려 있으므로(F-03 읽기 전용) 판정과 같이 넣는다(독립 검토 7-D1 정보)
 *   지운 것·영구 삭제된 것은 뺀다.
 *   **검토 계정(`qa_`·`claude_`)의 조회는 세지 않는다** — 시험이 부풀린 수가 섞이지 않게(F-71 판정 한 곳과 같은 접두).
 *   돌려주는 것은 제목·아이콘·공간 종류·조회 수·읽은 사람 수뿐이다 — 누가 읽었는지는 없다(PRD F-30 사람별 조회는 공지에만).
 *   관리자가 이 목록을 본 일은 감사 `page.popular allow` 로 남는다.
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 */

$popularDays  = 30;   // J-6 지난 30일
$popularLimit = 20;
/* 「사내 전체에 열린」 게시판 등급 — 쓰기 자격 있는 모든 역할이 읽는 등급을 매트릭스에 묻는다(코드에 박지 않는다, 검토 L-2).
   개인 공간의 `internal` 은 판정이 `canCommunityWrite`(쓰기 자격 있는 역할 전부)로 여는 범위라 그대로 둔다. */
$openLevels = implode(',', Authz::levelsReadByAllWriters());

$sql = <<<'SQL'
    SELECT p.public_id, p.title, p.icon, s.kind AS space_kind,
           count(*)                  AS views,
           count(DISTINCT v.user_id) AS readers
      FROM community.page_views v
      JOIN community.pages p   ON p.id = v.page_id
      JOIN community.spaces s  ON s.id = p.space_id
      JOIN public.users viewer ON viewer.id = v.user_id
      LEFT JOIN community.spaces pb ON pb.id = p.published_board_id
     WHERE v.day > current_date - CAST(:days AS int)
       AND p.deleted_at IS NULL
       AND p.purged_at IS NULL
       AND viewer.username NOT LIKE 'qa\_%'
       AND viewer.username NOT LIKE 'claude\_%'
       AND (
             (s.kind = 'personal'
              AND (p.visibility = 'internal' OR (p.visibility = 'published' AND p.published_from = 'internal')))
          OR (s.kind = 'personal' AND p.visibility = 'published' AND p.hidden_at IS NULL AND pb.kind = 'board'
              AND pb.access_level = ANY(string_to_array(CAST(:lv1 AS text), ',')))
          OR (s.kind = 'board' AND p.hidden_at IS NULL AND s.access_level = ANY(string_to_array(CAST(:lv2 AS text), ',')))
           )
     GROUP BY p.id, p.public_id, p.title, p.icon, s.kind
     ORDER BY count(*) DESC, count(DISTINCT v.user_id) DESC, p.id DESC
     LIMIT CAST(:lim AS int)
    SQL;

$out = [];
foreach (Db::instance()->run($sql, [':days' => $popularDays, ':lim' => $popularLimit, ':lv1' => $openLevels, ':lv2' => $openLevels])->fetchAll() as $r) {
    $out[] = [
        'id'         => (string) $r['public_id'],
        'title'      => (string) $r['title'],
        'icon'       => $r['icon'] === null ? null : (string) $r['icon'],
        'space_kind' => (string) $r['space_kind'],
        'views'      => (int) $r['views'],
        'readers'    => (int) $r['readers'],
        'url'        => Http::communityUrl('/p/' . (string) $r['public_id']),
    ];
}

Audit::allow('page.popular', [
    'target_type' => 'system',
    'target_name' => 'community.page_views',
    'detail'      => ['days' => $popularDays, 'rows' => count($out)],
]);

Http::json(['pages' => $out, 'days' => $popularDays, 'limit' => $popularLimit]);
