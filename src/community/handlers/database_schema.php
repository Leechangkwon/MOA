<?php
declare(strict_types=1);

/**
 * src/community/handlers/database_schema.php — PATCH /community/api/databases/{id}/schema (정책: community.write)
 *
 * 요구사항: 커뮤니티 PRD F-27, 지시서 `stage6a-database-directive.md` 「창구」 · 결정 G-4(지운 속성의 값은 남긴다).
 *
 * 받는 값 (JSON — 아래 넷 중 **정확히 하나**, 허용 키 밖이면 400)
 *   add      {name, type, options?, target?}   속성 더하기 — key 는 서버가 만든다. 30개까지.
 *            관계(6-B)는 `target` = **같은 공간**의 DB(UUID) — 고치는 사람이 그 DB 페이지를 읽을 수 있어야(없는·못 보는 것 같은 400)
 *   rename   {key, name}              이름 바꾸기
 *   options  {key, options}           선택지 바꾸기(select·multi_select 만). 빠진 선택지를 쓰던 행 값은 그대로 남는다
 *   remove   {key}                    지우기 — 스키마에서만 뺀다. 행의 값은 칸에 남고 화면·창구에서 사라진다(G-4)
 *   use_as   "project" | "table"      Q:F-12 (WK-16 A · ARCH 23.5) 「프로젝트로 쓰기」 = `purpose='project'` + 섹션 칸(`section`)이 없으면
 *                                     더하기 + 기본 보기 넷 중 **같은 이름이 없는 것**만 더하기 + 색이 없으면 차례 색 — 다시 불러도 칸·보기가
 *                                     겹치지 않는다(멱등). 「표로 쓰기」 = `purpose=NULL` **만**(칸·보기·색은 그대로). 「내 할 일」은 바꾸지 않는다(400).
 *                                     칸·보기 모양은 서버 상수(`DbProps::PROJECT_*`)에서만 — 요청은 이름 하나만 고른다
 *   version  (선택) 읽은 때의 `updated_at` — 다르면 409(두 사람이 같은 스키마를 동시에 고칠 때)
 *
 * 자격: **DB 페이지의 `decidePageManage`**(소유자·팀 공간 팀원) — 편집 공유자는 행을 고칠 수 있지만 스키마는 못 바꾼다.
 * 막으면 403 + 감사 `database.schema deny not_owner`. 바뀌면 감사 `database.schema allow`(무엇을 — 상세).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params [0] = DB public_id (UUID)
 */

$db    = Db::instance();
$dbRow = community_db_row((string) $params[0]);
if ($dbRow === null) {
    Http::error(404, Http::MSG_NOT_FOUND);
}

$body = Http::jsonBody();
$ops  = array_values(array_intersect(array_keys($body), ['add', 'rename', 'options', 'remove', 'depends', 'use_as']));
if (array_diff(array_keys($body), ['add', 'rename', 'options', 'remove', 'depends', 'use_as', 'version']) !== [] || count($ops) !== 1
    || ($ops[0] === 'use_as' ? !in_array($body['use_as'], ['project', 'table'], true) : !is_array($body[$ops[0]]))) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}
$op  = $ops[0];
$arg = $body[$op];

$gate   = community_history_gate($user, (string) $dbRow['page_public_id']);
$dbPage = $gate['row'];
if ($dbPage['deleted_at'] !== null) {
    Http::error(404, Http::MSG_NOT_FOUND);
}
$pageTitle = community_target_title((string) $dbPage['title']);

[$mayManage, $manageCode] = CommunityAuthz::decidePageManage($user, $dbPage);
if (!$mayManage) {
    Audit::deny('database.schema', CommunityAuthz::CODE_NOT_OWNER, [
        'target_type' => 'page',
        'target_id'   => (int) $dbPage['id'],
        'target_name' => $pageTitle,
        'detail'      => ['page_title' => $pageTitle, 'op' => $op],
    ]);
    Http::error(403, Http::MSG_FORBIDDEN);
}

if (array_key_exists('version', $body) && (!is_string($body['version']) || $body['version'] !== (string) $dbRow['updated_at'])) {
    Http::json(['error' => '그 사이 다른 사람이 속성을 바꿨습니다. 새로 고친 뒤 다시 하세요', 'reason' => 'changed'], 409);
    exit;
}

$schema = community_db_schema_of($dbRow['schema']);
$bad    = static function (string $message, string $reason): void {
    Http::json(['error' => $message, 'reason' => $reason], 400);
    exit;
};
$findIndex = static function (array $schema, $key): ?int {
    foreach ($schema as $i => $p) {
        if (is_string($key) && $p['key'] === $key) {
            return $i;
        }
    }
    return null;
};

/* ─── Q:F-12 「프로젝트로 쓰기」·「표로 쓰기」 (판정은 위 `decidePageManage` 그대로 — 스키마 변경과 같은 자격) ─── */
if ($op === 'use_as') {
    $sqlPurpose = <<<'SQL'
        SELECT d.purpose, d.color FROM community.databases d WHERE d.id = CAST(:id AS bigint)
        SQL;
    $cur = $db->run($sqlPurpose, [':id' => (int) $dbRow['id']])->fetch();
    if (!is_array($cur)) {
        Http::error(404, Http::MSG_NOT_FOUND);
    }
    if ($cur['purpose'] === 'my_tasks') {
        $bad('「내 할 일」은 프로젝트·표로 바꾸지 않습니다', 'my_tasks');
    }
    $views     = community_db_views_of($dbRow['views'], $schema);
    $toPurpose = $arg === 'project' ? 'project' : null;
    $color     = $cur['color'] === null ? null : (string) $cur['color'];
    $added     = ['prop' => false, 'views' => []];
    if ($toPurpose === 'project') {
        try {
            $shape = DbProps::projectPreset($schema, $views);
        } catch (DomainException $e) {
            $msg = ['name_taken' => '「섹션」 이름의 속성이 이미 있습니다. 그 속성 이름을 바꾼 뒤 다시 하세요',
                    'too_many_props' => '속성은 ' . DbProps::MAX_PROPS . '개까지 둡니다',
                    'too_many_views' => '보기는 ' . DbProps::MAX_VIEWS . '개까지 둡니다'][$e->getMessage()] ?? Http::MSG_BAD_REQUEST;
            $bad($msg, $e->getMessage());
        }
        $schema = $shape['schema'];
        $views  = $shape['views'];
        $added  = ['prop' => $shape['added_prop'], 'views' => $shape['added_views']];
        $color ??= community_project_color($user, (int) $dbPage['space_id']);
    }
    $shapeChanged = $added['prop'] || $added['views'] !== [];
    $changed      = $shapeChanged || $cur['purpose'] !== $toPurpose || $color !== ($cur['color'] === null ? null : (string) $cur['color']);
    $version      = (string) $dbRow['updated_at'];
    if ($changed) {
        // 읽은 스키마·보기가 그대로일 때만 쓴다(다른 스키마 고치기와 같은 낙관 잠금 — 사이에 고쳤으면 0행 → 409)
        $sqlUse = <<<'SQL'
            UPDATE community.databases
               SET purpose = :purpose, color = :color,
                   schema = CASE WHEN CAST(:shape AS boolean) THEN CAST(:schema AS jsonb) ELSE schema END,
                   views  = CASE WHEN CAST(:shape2 AS boolean) THEN CAST(:views AS jsonb) ELSE views END,
                   updated_at = CASE WHEN CAST(:shape3 AS boolean) THEN now() ELSE updated_at END
             WHERE id = CAST(:id AS bigint)
               AND updated_at = CAST(:seen AS timestamptz)
            RETURNING updated_at
            SQL;
        $db->begin();
        try {
            $done = $db->run($sqlUse, [
                ':purpose' => $toPurpose, ':color' => $color,
                ':shape' => $shapeChanged ? 'true' : 'false', ':shape2' => $shapeChanged ? 'true' : 'false',
                ':shape3' => $shapeChanged ? 'true' : 'false',
                ':schema' => json_encode(array_values($schema), JSON_UNESCAPED_UNICODE),
                ':views'  => json_encode(array_values($views), JSON_UNESCAPED_UNICODE),
                ':id' => (int) $dbRow['id'], ':seen' => (string) $dbRow['updated_at'],
            ])->fetch();
            if (!is_array($done)) {
                $db->rollback();
                Http::json(['error' => '그 사이 다른 사람이 속성을 바꿨습니다. 새로 고친 뒤 다시 하세요', 'reason' => 'changed'], 409);
                exit;
            }
            Audit::allow('database.schema', [
                'target_type' => 'page',
                'target_id'   => (int) $dbPage['id'],
                'target_name' => $pageTitle,
                'detail'      => ['page_title' => $pageTitle, 'granted_by' => $manageCode, 'op' => 'use_as', 'to' => (string) $arg,
                                  'added_prop' => $added['prop'], 'added_views' => $added['views']],
            ]);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            throw $e;
        }
        $version = (string) $done['updated_at'];
    }
    Http::json([
        'database' => [
            'id'      => (string) $dbRow['public_id'],
            'purpose' => $toPurpose,
            'color'   => $color,
            'schema'  => array_values($schema),
            'views'   => array_values($views),
            'version' => $version,
        ],
        'added'   => $added,
        'changed' => $changed,
    ]);
    exit;
}

$propName = '';
switch ($op) {
    case 'add':
        if (array_diff(array_keys($arg), ['name', 'type', 'options', 'target', 'depends']) !== []) {
            Http::error(400, Http::MSG_BAD_REQUEST);
        }
        if (count($schema) >= DbProps::MAX_PROPS) {
            $bad('속성은 ' . DbProps::MAX_PROPS . '개까지 둡니다', 'too_many_props');
        }
        $name = DbProps::cleanName($arg['name'] ?? null);
        if ($name === null) {
            $bad('속성 이름은 1~' . DbProps::NAME_MAX . '자입니다', 'bad_name');
        }
        if (!DbProps::nameFree($schema, $name)) {
            $bad('같은 이름의 속성이 이미 있습니다', 'name_taken');
        }
        $type = $arg['type'] ?? null;
        if (!is_string($type) || !in_array($type, DbProps::TYPES, true)) {
            $bad('모르는 속성 종류입니다', 'bad_type');
        }
        $new = ['key' => DbProps::newKey(), 'name' => $name, 'type' => $type];
        if (in_array($type, ['select', 'multi_select'], true)) {
            $opts = DbProps::cleanOptions($arg['options'] ?? null);
            if ($opts === null) {
                $bad('선택지는 1~' . DbProps::MAX_OPTIONS . '개, 각 1~' . DbProps::NAME_MAX . '자이고 겹치면 안 됩니다', 'bad_options');
            }
            $new['options'] = $opts;
        } elseif (array_key_exists('options', $arg)) {
            $bad('선택지는 선택·다중 선택 속성에만 있습니다', 'bad_options');
        }
        if ($type === 'relation') {
            $target = $arg['target'] ?? null;
            $tDb    = is_string($target) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $target) === 1
                ? community_db_row($target) : null;
            $tPage  = $tDb === null ? null : community_page_row((string) $tDb['page_public_id'], (int) $user['id']);
            if ($tPage === null || $tPage['deleted_at'] !== null || (int) $tPage['space_id'] !== (int) $dbPage['space_id']
                || !CommunityAuthz::decidePageRead($user, $tPage)[0]) {
                $bad('관계는 같은 공간의, 내가 볼 수 있는 데이터베이스와만 맺습니다', 'bad_target');
            }
            $new['target'] = (string) $tDb['public_id'];
        } elseif (array_key_exists('target', $arg)) {
            $bad('대상은 관계 속성에만 있습니다', 'bad_target');
        }
        if (array_key_exists('depends',$arg)) {
            if ($type!=='relation' || !is_bool($arg['depends']) || $new['target']!==$dbRow['public_id']) { $bad('선후행 관계는 같은 데이터베이스만 가리킵니다','bad_target'); }
            $new['depends']=$arg['depends'];
        }
        $schema[] = $new;
        $propName = $name;
        break;

    case 'depends':
        if (array_diff(array_keys($arg),['key','depends'])!==[] || !is_bool($arg['depends'] ?? null)) { $bad('선후행 관계 설정이 맞지 않습니다','bad_value'); }
        $i=$findIndex($schema,$arg['key'] ?? null);
        if ($i===null || $schema[$i]['type']!=='relation' || ($schema[$i]['target'] ?? '')!==$dbRow['public_id']) { $bad('같은 데이터베이스 관계만 가능합니다','bad_target'); }
        $schema[$i]['depends']=$arg['depends']; $propName=$schema[$i]['name'];
        break;
    case 'rename':
        if (array_diff(array_keys($arg), ['key', 'name']) !== []) {
            Http::error(400, Http::MSG_BAD_REQUEST);
        }
        $i = $findIndex($schema, $arg['key'] ?? null);
        if ($i === null) {
            $bad('없는 속성입니다', 'unknown_prop');
        }
        $name = DbProps::cleanName($arg['name'] ?? null);
        if ($name === null) {
            $bad('속성 이름은 1~' . DbProps::NAME_MAX . '자입니다', 'bad_name');
        }
        if (!DbProps::nameFree($schema, $name, (string) $schema[$i]['key'])) {
            $bad('같은 이름의 속성이 이미 있습니다', 'name_taken');
        }
        $propName = (string) $schema[$i]['name'] . ' → ' . $name;
        $schema[$i]['name'] = $name;
        break;

    case 'options':
        if (array_diff(array_keys($arg), ['key', 'options']) !== []) {
            Http::error(400, Http::MSG_BAD_REQUEST);
        }
        $i = $findIndex($schema, $arg['key'] ?? null);
        if ($i === null) {
            $bad('없는 속성입니다', 'unknown_prop');
        }
        if (!in_array($schema[$i]['type'], ['select', 'multi_select'], true)) {
            $bad('선택지는 선택·다중 선택 속성에만 있습니다', 'bad_options');
        }
        $opts = DbProps::cleanOptions($arg['options'] ?? null);
        if ($opts === null) {
            $bad('선택지는 1~' . DbProps::MAX_OPTIONS . '개, 각 1~' . DbProps::NAME_MAX . '자이고 겹치면 안 됩니다', 'bad_options');
        }
        $schema[$i]['options'] = $opts;
        $propName = (string) $schema[$i]['name'];
        break;

    case 'remove':
        if (array_diff(array_keys($arg), ['key']) !== []) {
            Http::error(400, Http::MSG_BAD_REQUEST);
        }
        $i = $findIndex($schema, $arg['key'] ?? null);
        if ($i === null) {
            $bad('없는 속성입니다', 'unknown_prop');
        }
        $propName = (string) $schema[$i]['name'];
        array_splice($schema, $i, 1);
        break;
}

$schemaJson = json_encode(array_values($schema), JSON_UNESCAPED_UNICODE);
// 읽은 스키마가 그대로일 때만 쓴다 — 사이에 다른 요청이 고쳤으면 0행 → 409
$sqlSet = <<<'SQL'
    UPDATE community.databases
       SET schema = CAST(:schema AS jsonb), updated_at = now()
     WHERE id = CAST(:id AS bigint)
       AND updated_at = CAST(:seen AS timestamptz)
    RETURNING updated_at
    SQL;

$db->begin();
try {
    $lock=<<<'SQL'
        SELECT id FROM community.databases WHERE id=CAST(:db AS bigint) FOR UPDATE
        SQL;
    $db->run($lock,[':db'=>(int)$dbRow['id']]);
    foreach ($schema as $prop) {
        if (($prop['depends'] ?? false) && !community_dependency_ok((int)$dbRow['id'],$prop['key'])) {
            $db->rollback(); $bad('기존 관계가 순환하거나 깊이 한도를 넘습니다','dependency_cycle');
        }
    }
    $done = $db->run($sqlSet, [':schema' => $schemaJson, ':id' => (int) $dbRow['id'], ':seen' => (string) $dbRow['updated_at']])->fetch();
    if (!is_array($done)) {
        $db->rollback();
        Http::json(['error' => '그 사이 다른 사람이 속성을 바꿨습니다. 새로 고친 뒤 다시 하세요', 'reason' => 'changed'], 409);
        exit;
    }
    Audit::allow('database.schema', [
        'target_type' => 'page',
        'target_id'   => (int) $dbPage['id'],
        'target_name' => $pageTitle,
        'detail'      => ['page_title' => $pageTitle, 'granted_by' => $manageCode, 'op' => $op, 'prop_name' => $propName],
    ]);
    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    throw $e;
}

Http::json([
    'database' => [
        'id'      => (string) $dbRow['public_id'],
        'schema'  => array_values($schema),
        'version' => (string) $done['updated_at'],
    ],
]);
