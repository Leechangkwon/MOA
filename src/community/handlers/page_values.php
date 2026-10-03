<?php
declare(strict_types=1);

/**
 * src/community/handlers/page_values.php — PATCH /community/api/pages/{id}/values (정책: community.write)
 *
 * 요구사항: 커뮤니티 PRD F-27 · C:S-14(지울 수 없는 기록), 지시서 `stage6a-database-directive.md` 「창구」.
 *
 * 받는 값 (JSON) — `{values: {key: 값|null}}` 하나(1~30개). 허용 키 밖이면 400. null 은 「값 지우기」.
 *   값의 모양은 `DbProps::cleanValue` · 사람 값은 **그 행을 볼 수 있는 사람**만(없는 사람과 같은 400).
 *
 * 자격: 그 행의 `decidePageRead` → `decidePageChange`(소유자·편집 공유자·팀원). 막으면 403 + 감사 `page.values deny`.
 * 행이 아닌 페이지는 400 `not_row`.
 *
 * ★ 키 단위로 합친다(`properties || 바뀐 키 - 지운 키`) — 두 사람이 **다른 칸**을 동시에 고쳐도 서로 지우지 않는다.
 *   같은 칸이면 나중 것이 이긴다(그 칸의 전·후는 처리 이력에 둘 다 남는다).
 * ★ 바뀐 칸마다 `page_property_events`(prop `db:<key>`, 사람이 읽는 전·후 값) 한 줄 — 지울 수 없다.
 * ★ `updated_at` 은 밀지 않는다(상태 칸과 같은 판단 — 값 바꾸기는 본문 고치기가 아니다).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params [0] = 페이지 public_id (UUID)
 */

$db  = Db::instance();
$me  = (int) $user['id'];
$pid = (string) $params[0];

$body = Http::jsonBody();
if (array_keys($body) !== ['values'] || !is_array($body['values']) || $body['values'] === []
    || array_is_list($body['values']) || count($body['values']) > DbProps::MAX_PROPS) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}

$gate = community_history_gate($user, $pid);
$row  = $gate['row'];
if ($row['deleted_at'] !== null) {
    Http::error(404, Http::MSG_NOT_FOUND);
}
if ($row['database_id'] === null) {
    Http::json(['error' => '데이터베이스의 행만 속성 값이 있습니다', 'reason' => 'not_row'], 400);
    exit;
}
if (($row['external_key']??null)!==null) { Http::json(['error'=>'외부에서 가져온 값은 원본에서 고칩니다','reason'=>'external_readonly'],400);exit; }
$pageId    = (int) $row['id'];
$pageTitle = community_target_title((string) $row['title']);

[$mayChange, $changeCode] = CommunityAuthz::decidePageChange($user, $row);
if (!$mayChange) {
    Audit::deny('page.values', CommunityAuthz::CODE_NOT_OWNER, [
        'target_type' => 'page',
        'target_id'   => $pageId,
        'target_name' => $pageTitle,
        'detail'      => ['page_title' => $pageTitle],
    ]);
    Http::error(403, Http::MSG_FORBIDDEN);
}

$schema = community_db_schema_of($row['db_schema']);
$set    = [];
$del    = [];
$props  = [];
$dueSet=false; $dueValue=null;
foreach ($body['values'] as $k => $v) {
    if ($k==='due') {
        $raw=is_array($v) ? ($v['start'] ?? null) : $v;
        if ($raw!==null && DbViews::day($raw)===null) { Http::json(['error'=>'기한 날짜가 올바르지 않습니다','reason'=>'bad_value'],400); exit; }
        $dueSet=true; $dueValue=$raw; continue;
    }
    $prop = DbProps::find($schema, (string) $k);
    if ($prop === null) {
        Http::json(['error' => '없는 속성입니다', 'reason' => 'unknown_prop'], 400);
        exit;
    }
    [$ok, $clean] = DbProps::cleanValue($prop, $v);
    if (!$ok) {
        Http::json(['error' => '「' . (string) $prop['name'] . '」 값의 모양이 맞지 않습니다', 'reason' => 'bad_value'], 400);
        exit;
    }
    if ($clean !== null && (string) $prop['type'] === 'person' && !community_db_person_ok((string) $clean, $pid)) {
        Http::json(['error' => '이 행을 볼 수 없는 사람은 넣을 수 없습니다', 'reason' => 'person_not_permitted'], 400);
        exit;
    }
    // 6-B 관계·파일 — 넣는 사람이 볼 수 있어야 하고(관계: 대상 DB 의 행) 파일은 행을 읽는 범위보다 좁지 않아야(5-E) — 같은 400
    if ($clean !== null && in_array((string) $prop['type'], ['relation', 'file'], true)) {
        $curVals = json_decode((string) ($row['properties'] ?? '{}'), true) ?: [];
        if (!community_db_refs_ok($prop, $clean, $curVals[(string) $k] ?? null, CommunityAuthz::pageAudiences($row), $user)) {
            Http::json(['error' => '넣을 수 없는 행이나 파일이 있습니다. 내가 볼 수 있고, 이 행을 읽는 사람도 볼 수 있는 것만 넣습니다', 'reason' => 'bad_ref'], 400);
            exit;
        }
    }
    $props[(string) $k] = $prop;
    if ($clean === null) {
        $del[] = (string) $k;
    } else {
        $set[(string) $k] = $clean;
    }
}

$sqlLock = <<<'SQL'
    SELECT properties,due_date FROM community.pages
     WHERE id = CAST(:pid AS bigint) AND deleted_at IS NULL AND database_id = CAST(:db AS bigint)
     FOR UPDATE
    SQL;
$sqlSet = <<<'SQL'
    UPDATE community.pages
       SET properties = (coalesce(properties, '{}'::jsonb) || CAST(:set AS jsonb)) - CAST(:del AS text[]),
           due_date = CASE WHEN CAST(:due_set AS boolean) THEN CAST(:due AS date) ELSE due_date END
     WHERE id = CAST(:pid AS bigint)
    RETURNING properties
    SQL;

/** 값 → 키 순서를 맞춘 JSON 글자 (같은 값 판정용) */
$canon = static function ($v) use (&$canon): string {
    if (is_array($v) && !array_is_list($v)) {
        ksort($v);
    }
    if (is_array($v)) {
        return '[' . implode(',', array_map(static fn ($k, $x): string => json_encode($k) . ':' . $canon($x), array_keys($v), $v)) . ']';
    }
    return (string) json_encode($v, JSON_UNESCAPED_UNICODE);
};

$changed = [];
$db->begin();
try {
    $databaseLock=<<<'SQL'
        SELECT id,schema FROM community.databases WHERE id=CAST(:db AS bigint) FOR UPDATE
        SQL;
    $currentDatabase=$db->run($databaseLock,[':db'=>(int)$row['database_id']])->fetch();
    if (!is_array($currentDatabase) || community_db_schema_of($currentDatabase['schema'])!==$schema) {
        $db->rollback(); Http::json(['error'=>'속성이 변경되었습니다. 새로 고친 뒤 다시 시도하세요','reason'=>'changed'],409); exit;
    }
    foreach ($props as $key=>$prop) {
        if (($prop['depends'] ?? false) && !community_dependency_ok((int)$row['database_id'],$key,$pid,$set[$key] ?? [])) {
            $db->rollback(); Http::json(['error'=>'선후행 관계가 순환하거나 깊이 한도를 넘습니다','reason'=>'dependency_cycle'],400); exit;
        }
    }
    $cur = $db->run($sqlLock, [':pid' => $pageId, ':db' => (int) $row['database_id']])->fetch();
    if (!is_array($cur)) {
        // 사이에 지워졌거나 행이 아니게 됐다(옮기기)
        $db->rollback();
        Http::error(404, Http::MSG_NOT_FOUND);
    }
    $fresh=community_page_row($pid,$me);
    if ($fresh===null || !CommunityAuthz::decidePageChange($user,$fresh)[0]) { $db->rollback(); Http::error(403,Http::MSG_FORBIDDEN); }
    $before = json_decode((string) $cur['properties'], true) ?: [];
    $delArr = '{' . implode(',', array_map(static fn (string $k): string => '"' . $k . '"', $del)) . '}';   // key 는 p+8hex 뿐
    $after  = $db->run($sqlSet, [
        ':set' => json_encode($set === [] ? new stdClass() : $set, JSON_UNESCAPED_UNICODE),
        ':del' => $delArr,
        ':pid' => $pageId, ':due_set'=>$dueSet?'true':'false', ':due'=>$dueValue,
    ])->fetch();

    if ($dueSet && $cur['due_date']!==$dueValue) {
        community_insert_property_event($pageId,'due',$cur['due_date'],$dueValue,$me); $changed[]='기한';
    }
    foreach ($props as $k => $prop) {
        $from = array_key_exists($k, $before) ? $before[$k] : null;
        $to   = $set[$k] ?? null;
        // 같은 값이면 남기지 않는다 — 기록은 「바뀐 일」의 장부다. jsonb 는 객체 키 순서를 바꾸므로 키를 맞춘 JSON 으로 견준다
        if ($canon($from) === $canon($to)) {
            continue;
        }
        $fromText = DbProps::display($prop, $from);
        $toText   = DbProps::display($prop, $to);
        community_insert_property_event($pageId, 'db:' . $k, $fromText, $toText, $me);
        $changed[] = (string) $prop['name'];
    }
    if ($changed !== []) {
        Audit::allow('page.values', [
            'target_type' => 'page',
            'target_id'   => $pageId,
            'target_name' => $pageTitle,
            'detail'      => ['page_title' => $pageTitle, 'granted_by' => $changeCode, 'changed' => $changed],
        ]);
    }
    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    throw $e;
}

Http::json([
    'values'  => community_db_values_visible($schema, is_array($after) ? $after['properties'] : null, $user),
    'changed' => $changed,
]);
