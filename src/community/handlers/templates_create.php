<?php
declare(strict_types=1);

/**
 * src/community/handlers/templates_create.php — POST /community/api/templates (정책: community.write)
 *
 * 요구사항: `C:F-31` 「템플릿으로 저장」 · 지시서 `stage7-finish-directive.md` 「7-B」 · 결정 J-3.
 *
 * 받는 값 (JSON, 허용 키 밖이면 400)
 *   page_id  원본 페이지 (UUID) — **볼 수 있는** 페이지여야 한다
 *   name     템플릿 이름 (1~60자, 줄바꿈·제어문자 금지)
 *   scope    `personal`(기본) · `team`(그 팀 공간의 페이지만) · `site`(Admin · 사내 전체에 열린 페이지만) ·
 *            `database`(DB 행만 — 그 DB 에 행을 더할 수 있는 사람)
 *
 * 담는 것: 제목 · 아이콘 · 본문(`Doc::templateBody` — 첨부 블록·하위 페이지 블록을 뺀다) ·
 *          DB 행이면 속성 값(사람·관계·파일 빼고 — `community_template_props`). 댓글·태그·공유·범위는 담지 않는다.
 * ★ 원칙 — 템플릿을 볼 사람 ⊆ 원본을 볼 수 있는 사람 (`CommunityAuthz::decideTemplateSave`).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 */

$body = Http::jsonBody();
if (array_diff(array_keys($body), ['page_id', 'name', 'scope']) !== []) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}
$pid = $body['page_id'] ?? null;
if (!is_string($pid) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $pid) !== 1) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}
$rawName = $body['name'] ?? null;
if (!is_string($rawName) || preg_match('//u', $rawName) !== 1) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}
$name = trim($rawName);
if ($name === '' || iconv_strlen($name, 'UTF-8') > 60 || preg_match('/\p{C}/u', $name) === 1) {
    Http::json(['error' => '템플릿 이름은 1~60자입니다', 'reason' => 'bad_name'], 400);
    exit;
}
$scope = $body['scope'] ?? 'personal';
if (!is_string($scope) || !in_array($scope, CommunityAuthz::TEMPLATE_SCOPES, true)) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}

$me  = (int) $user['id'];
$row = community_page_row($pid, $me);
if ($row === null || $row['deleted_at'] !== null || $row['purged_at'] !== null) {
    Http::error(404, Http::MSG_NOT_FOUND);
}
$pageTitle = community_target_title((string) $row['title']);

// [0] 원본을 볼 수 없다 — 남의 페이지에 닿으려 한 일(4-A 부터의 같은 줄)
if (!CommunityAuthz::decidePageRead($user, $row)[0]) {
    Audit::deny('page.read', CommunityAuthz::CODE_NOT_OWNER, [
        'target_type' => 'page',
        'target_id'   => (int) $row['id'],
        'target_name' => $pageTitle,
        'detail'      => ['page_title' => $pageTitle],
    ]);
    Http::error(403, Http::MSG_FORBIDDEN);
}

// DB 행 템플릿이면 그 행의 DB 페이지(판정 재료)
$dbPage = null;
$dbInfo = null;
if ($scope === 'database' && $row['database_id'] !== null && $row['db_public_id'] !== null) {
    $dbInfo = community_db_row((string) $row['db_public_id']);
    $dbPage = $dbInfo === null ? null : community_page_row((string) $dbInfo['page_public_id'], $me);
}

[$may, $code] = CommunityAuthz::decideTemplateSave($user, $row, $scope, $dbPage);
if (!$may) {
    $reasons400 = [
        'db_page'                          => '데이터베이스 페이지는 템플릿으로 저장하지 않습니다 (행을 행 템플릿으로 저장합니다)',
        CommunityAuthz::CODE_TEAM_SCOPE    => '팀 공간의 페이지만 팀 템플릿이 됩니다',
        'too_narrow'                       => $scope === 'site'
            ? '사내 전체에 열린 페이지만 사이트 기본 템플릿이 됩니다'
            : '데이터베이스보다 좁게 닫힌 행은 행 템플릿이 되지 않습니다',
        'not_row'                          => '데이터베이스의 행만 행 템플릿이 됩니다',
    ];
    if (isset($reasons400[$code])) {
        Http::json(['error' => $reasons400[$code], 'reason' => $code], 400);
        exit;
    }
    if ($code === CommunityAuthz::CODE_NOT_FOUND) {
        Http::error(404, Http::MSG_NOT_FOUND);
    }
    Audit::deny('template.create', in_array($code, [CommunityAuthz::CODE_NOT_MEMBER, CommunityAuthz::CODE_ROLE_NOT_PERMITTED], true)
        ? $code : CommunityAuthz::CODE_NOT_OWNER, [
        'target_type' => 'page',
        'target_id'   => (int) $row['id'],
        'target_name' => $pageTitle,
        'detail'      => ['page_title' => $pageTitle, 'scope' => $scope],
    ]);
    Http::error(403, Http::MSG_FORBIDDEN);
}

// 본문 — 첨부·하위 페이지를 빼고, 지금 단계의 검사를 다시 지난다(거른 뒤에도 문서여야 한다)
$srcDoc = is_string($row['doc']) ? json_decode((string) $row['doc'], true) : $row['doc'];
[$tplDoc, $dropped] = Doc::templateBody(is_array($srcDoc) ? $srcDoc : []);
$check = Doc::validate($tplDoc, COMMUNITY_DOC_STAGE);
if (!$check['ok'] || Doc::extract($tplDoc)['files'] !== []) {
    Http::json(['error' => '이 페이지의 본문은 템플릿으로 담을 수 없습니다', 'reason' => 'bad_doc'], 400);
    exit;
}
// 7-C — 템플릿에 담는 임베드는 **담을 때** 허용 목록과 견준다(쓸 때는 옛 임베드처럼 막지 않는다 — 독립 검토 7-C L-1)
if (!community_embeds_ok(Doc::extract($tplDoc)['embeds'])) {
    Http::json(['error' => COMMUNITY_EMBED_REFUSED, 'reason' => 'embed_not_allowed'], 400);
    exit;
}
$docJson = json_encode($tplDoc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
// 본문 상한은 페이지와 같은 기준(빈칸 없는 JSON 바이트)으로 **앱이 먼저** 잰다 — DB CHECK 에 닿아 500 이 되지 않게(독립 검토 L-1)
if ($docJson !== false && strlen($docJson) > Doc::limit('doc_bytes')) {
    Http::json(['error' => '본문이 너무 커서 템플릿으로 담을 수 없습니다', 'reason' => 'too_large'], 400);
    exit;
}
$props   = [];
if ($scope === 'database' && $dbInfo !== null) {
    $props = community_template_props(community_db_schema_of($dbInfo['schema']), $row['properties']);
}
$propsJson = json_encode($props === [] ? new stdClass() : $props, JSON_UNESCAPED_UNICODE);
if ($docJson === false || $propsJson === false) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}

$ownerId = $scope === 'personal' ? $me : null;
$teamId  = $scope === 'team' ? (int) $row['space_team_id'] : null;
$dbId    = $scope === 'database' ? (int) $row['database_id'] : null;
$limitKey = $ownerId ?? $teamId ?? $dbId;
$limit    = COMMUNITY_TEMPLATE_LIMITS[$scope];

$sqlLock = <<<'SQL'
    SELECT pg_advisory_xact_lock(hashtext(:k))
    SQL;
$sqlInsert = <<<'SQL'
    INSERT INTO community.templates
        (scope, owner_user_id, team_id, database_id, name, title, icon, doc, properties, created_by)
    VALUES
        (:scope, CAST(:owner AS bigint), CAST(:team AS bigint), CAST(:db AS bigint), :name, :title, :icon,
         CAST(:doc AS jsonb), CAST(:props AS jsonb), CAST(:me AS bigint))
    RETURNING public_id
    SQL;

$db = Db::instance();
$db->begin();
try {
    // 한도는 **잠근 뒤** 센다(동시에 저장해 한도를 넘지 않게). 잠글 행이 없는 범위(개인·사이트)도 있어 advisory lock 을 쓴다
    $db->run($sqlLock, [':k' => 'community.templates:' . $scope . ':' . (string) ($limitKey ?? 0)]);
    if (community_template_count($scope, $limitKey) >= $limit) {
        $db->rollback();
        Http::json(['error' => '이 범위에는 템플릿을 ' . $limit . '개까지 둡니다', 'reason' => 'too_many_templates'], 400);
        exit;
    }
    $new = $db->run($sqlInsert, [
        ':scope' => $scope,
        ':owner' => $ownerId,
        ':team'  => $teamId,
        ':db'    => $dbId,
        ':name'  => $name,
        ':title' => (string) $row['title'],
        ':icon'  => $row['icon'] === null || (string) $row['icon'] === '' ? null : (string) $row['icon'],
        ':doc'   => $docJson,
        ':props' => $propsJson,
        ':me'    => $me,
    ])->fetch();
    if (!is_array($new)) {
        throw new RuntimeException('템플릿을 만들지 못했습니다');
    }
    Audit::allow('template.create', [
        'target_type' => 'page',
        'target_id'   => (int) $row['id'],
        'target_name' => $pageTitle,
        'detail'      => [
            'page_title'     => $pageTitle,
            'template_name'  => community_target_title($name),
            'scope'          => $scope,
            'granted_by'     => $code,
            'dropped_blocks' => $dropped,
            'prop_count'     => count($props),
        ],
    ]);
    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    throw $e;
}

$tpl = community_template_row((string) $new['public_id']);
Http::json([
    'template'       => $tpl === null ? null : community_template_out($tpl, $user, $dbPage, false),
    'dropped_blocks' => $dropped,
], 201);
