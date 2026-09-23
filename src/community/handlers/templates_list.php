<?php
declare(strict_types=1);

/**
 * src/community/handlers/templates_list.php — GET /community/api/templates[?database=] (정책: community.read)
 *
 * 요구사항: `C:F-31` 템플릿 갤러리 · 지시서 `stage7-finish-directive.md` 「7-B」 · 결정 J-3.
 *
 *   `?database=` 없음 → 내 템플릿 · 내 팀 템플릿 · 사이트 기본 (DB 행 템플릿은 여기 없다)
 *   `?database=<DB id>` → 그 DB 의 행 템플릿 — 그 DB 에 **행을 더할 수 있는 사람**만(못 보면 404, 보기만 하면 403)
 * ★ 남의 개인 템플릿·다른 팀 템플릿은 SQL 이 거르고, 한 줄씩 `decideTemplateRead` 를 한 번 더 지난다.
 *   계정 id·팀 id 는 응답에 없다. 본문은 `GET /api/templates/{id}` 가 준다(목록은 가볍게).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 */

$me     = (int) $user['id'];
$dbRaw  = Http::queryText('database', 36);
$dbPage = null;

$cols = <<<'SQL'
    SELECT t.id, t.public_id, t.scope, t.owner_user_id, t.team_id, t.database_id,
           t.name, t.title, t.icon, t.doc, t.properties, t.seed_key,
           t.created_by, t.created_at, t.deleted_at,
           u.username AS created_by_name, u.display_name AS created_by_display_name,
           tm.name AS team_name, d.public_id AS db_public_id, dp.public_id AS db_page_public_id
      FROM community.templates t
      LEFT JOIN public.users u        ON u.id  = t.created_by
      LEFT JOIN public.teams tm       ON tm.id = t.team_id
      LEFT JOIN community.databases d ON d.id  = t.database_id
      LEFT JOIN community.pages dp    ON dp.id = d.page_id
    SQL;

if ($dbRaw !== '') {
    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $dbRaw) !== 1) {
        Http::error(400, Http::MSG_BAD_REQUEST);
    }
    $dbInfo = community_db_row($dbRaw);
    $dbPage = $dbInfo === null ? null : community_page_row((string) $dbInfo['page_public_id'], $me);
    if ($dbPage === null || !CommunityAuthz::decidePageRead($user, $dbPage)[0]) {
        Http::error(404, Http::MSG_NOT_FOUND);
    }
    [$mayRows, $rowsCode] = CommunityAuthz::decidePageChange($user, $dbPage);
    if (!$mayRows) {
        $dbTitle = community_target_title((string) $dbPage['title']);
        Audit::deny('template.list', CommunityAuthz::CODE_NOT_OWNER, [
            'target_type' => 'page',
            'target_id'   => (int) $dbPage['id'],
            'target_name' => $dbTitle,
            'detail'      => ['page_title' => $dbTitle, 'scope' => 'database'],
        ]);
        Http::error(403, Http::MSG_FORBIDDEN);
    }
    $where = <<<'SQL'
         WHERE t.deleted_at IS NULL AND t.scope = 'database' AND t.database_id = CAST(:db AS bigint)
         ORDER BY t.created_at DESC, t.id DESC
         LIMIT 100
        SQL;
    $rows = Db::instance()->run($cols . $where, [':db' => (int) $dbInfo['id']])->fetchAll();
} else {
    $where = <<<'SQL'
         WHERE t.deleted_at IS NULL
           AND ((t.scope = 'personal' AND t.owner_user_id = CAST(:me AS bigint))
             OR (t.scope = 'team' AND t.team_id = CAST(:team AS bigint))
             OR (t.scope = 'site' AND CAST(:site AS int) = 1))
         ORDER BY CASE t.scope WHEN 'site' THEN 0 WHEN 'team' THEN 1 ELSE 2 END, t.created_at DESC, t.id DESC
         LIMIT 300
        SQL;
    $rows = Db::instance()->run($cols . $where, [
        ':me'   => $me,
        ':team' => isset($user['team_id']) && $user['team_id'] !== null ? (int) $user['team_id'] : null,
        ':site' => Authz::canCommunityWrite($user) ? 1 : 0,
    ])->fetchAll();
}

$out = [];
foreach ($rows as $tpl) {
    if (!CommunityAuthz::decideTemplateRead($user, $tpl, $dbPage)[0]) {
        continue;   // SQL 과 판정이 어긋나면 판정이 이긴다(닫힌 쪽)
    }
    $out[] = community_template_out($tpl, $user, $dbPage, false);
}

Http::json([
    'templates' => $out,
    'limits'    => COMMUNITY_TEMPLATE_LIMITS,
]);
