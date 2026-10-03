<?php
declare(strict_types=1);

/**
 * src/community/handlers/database_views.php — PATCH /community/api/databases/{id}/views (정책: community.write)
 *
 * 요구사항: 커뮤니티 PRD F-27(뷰 표/보드/리스트), 지시서 `stage6b-database-views-directive.md` 「뷰」 · 결정 H-1.
 *
 * 받는 값 (JSON — 셋 중 **정확히 하나**, 허용 키 밖이면 400)
 *   add     {name, type, filter?, sort?, hidden?}    뷰 더하기(10개까지) — 거르기·정렬은 **지금 스키마로 컴파일되어야** 한다
 *   update  {id, name?, type?, filter?, sort?, hidden?}  뷰 고치기(준 칸만 바꾼다)
 *   remove  {id}
 *   version (선택) 읽은 때의 `updated_at` — 다르면 409
 *
 * 자격: DB 페이지의 `decidePageChange`(결정 H-1 — 뷰는 보는 방법이지 데이터가 아니다, 편집 공유자도 된다).
 * 막으면 403 + 감사 `database.views deny not_owner`. 바뀌면 감사 `database.views allow`.
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
$ops  = array_values(array_intersect(array_keys($body), ['add', 'update', 'remove']));
if (array_diff(array_keys($body), ['add', 'update', 'remove', 'version']) !== [] || count($ops) !== 1 || !is_array($body[$ops[0]])) {
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

[$mayChange, $changeCode] = CommunityAuthz::decidePageChange($user, $dbPage);
if (!$mayChange) {
    Audit::deny('database.views', CommunityAuthz::CODE_NOT_OWNER, [
        'target_type' => 'page',
        'target_id'   => (int) $dbPage['id'],
        'target_name' => $pageTitle,
        'detail'      => ['page_title' => $pageTitle, 'op' => $op],
    ]);
    Http::error(403, Http::MSG_FORBIDDEN);
}
if (array_key_exists('version', $body) && (!is_string($body['version']) || $body['version'] !== (string) $dbRow['updated_at'])) {
    Http::json(['error' => '그 사이 다른 사람이 바꿨습니다. 새로 고친 뒤 다시 하세요', 'reason' => 'changed'], 409);
    exit;
}

$schema = community_db_schema_of($dbRow['schema']);
$views  = community_db_views_of($dbRow['views'], $schema);
$bad    = static function (string $m): void {
    Http::json(['error' => $m, 'reason' => 'bad_view'], 400);
    exit;
};
$find = static function (array $views, $id): ?int {
    foreach ($views as $i => $v) {
        if (is_string($id) && $v['id'] === $id) {
            return $i;
        }
    }
    return null;
};

$targetView = null;
foreach ($views as $v) { if ($v['id'] === ($arg['id'] ?? null)) { $targetView = $v; break; } }
if ((($arg['type'] ?? null) === 'form' || ($targetView['type'] ?? null) === 'form')
    && !CommunityAuthz::decidePageManage($user,$dbPage)[0]) { Http::error(403,Http::MSG_FORBIDDEN); }
$viewName = '';
switch ($op) {
    case 'add':
        if (count($views) >= DbProps::MAX_VIEWS) {
            $bad('뷰는 ' . DbProps::MAX_VIEWS . '개까지 둡니다');
        }
        $clean = DbProps::cleanView($arg, $schema);
        if ($clean === null) {
            $bad('뷰의 이름·종류·거르기·정렬 모양이 맞지 않습니다');
        }
        foreach ($views as $v) {
            if (strtolower($v['name']) === strtolower($clean['name'])) {
                $bad('같은 이름의 뷰가 이미 있습니다');
            }
        }
        $views[]  = ['id' => DbProps::newViewId()] + $clean;
        $viewName = $clean['name'];
        break;
    case 'update':
        $i = $find($views, $arg['id'] ?? null);
        if ($i === null) {
            $bad('없는 뷰입니다');
        }
        $merged = array_diff_key($views[$i], ['id' => 1]);
        if (isset($arg['type']) && is_string($arg['type']) && $arg['type']!==$merged['type']) { $merged=array_intersect_key($merged,array_flip(DbViews::viewFields($arg['type']))); }
        foreach (DbViews::viewFields((string)($arg['type'] ?? $merged['type'])) as $k) {
            if (array_key_exists($k, $arg)) {
                if ($arg[$k] === null) {
                    unset($merged[$k]);
                } else {
                    $merged[$k] = $arg[$k];
                }
            }
        }
        if (array_diff(array_keys($arg), array_merge(['id'],DbViews::viewFields((string)($arg['type'] ?? $merged['type'])))) !== []) {
            Http::error(400, Http::MSG_BAD_REQUEST);
        }
        $clean = DbProps::cleanView($merged, $schema);
        if ($clean === null) {
            $bad('뷰의 이름·종류·거르기·정렬 모양이 맞지 않습니다');
        }
        foreach ($views as $j => $other) {
            if ($j !== $i && strtolower($other['name']) === strtolower($clean['name'])) {
                $bad('같은 이름의 뷰가 이미 있습니다');   // 독립 검토 6-B L-2
            }
        }
        $views[$i] = ['id' => $views[$i]['id']] + $clean;
        $viewName  = $clean['name'];
        break;
    case 'remove':
        $i = $find($views, $arg['id'] ?? null);
        if ($i === null || array_diff(array_keys($arg), ['id']) !== []) {
            $bad('없는 뷰입니다');
        }
        $viewName = $views[$i]['name'];
        array_splice($views, $i, 1);
        break;
}

foreach ($views as $candidate) {
    if (($candidate['type'] ?? '')==='form' && ($candidate['public'] ?? false)) {
        if (!community_form_public_allowed($dbPage,$user)) { Http::error(403,Http::MSG_FORBIDDEN); }
        foreach ($candidate['questions'] as $q) {
            $type=$q['prop']==='title' ? 'text' : (DbProps::find($schema,$q['prop'])['type'] ?? '');
            if (!in_array($type,['text','number','checkbox','date','select','multi_select'],true)) { $bad('공개 폼에 넣을 수 없는 질문입니다'); }
        }
    }
}
$sqlSet = <<<'SQL'
    UPDATE community.databases
       SET views = CAST(:views AS jsonb), updated_at = now()
     WHERE id = CAST(:id AS bigint)
       AND updated_at = CAST(:seen AS timestamptz)
    RETURNING updated_at
    SQL;
$db->begin();
try {
    $done = $db->run($sqlSet, [
        ':views' => json_encode(array_values($views), JSON_UNESCAPED_UNICODE),
        ':id'    => (int) $dbRow['id'],
        ':seen'  => (string) $dbRow['updated_at'],
    ])->fetch();
    if (!is_array($done)) {
        $db->rollback();
        Http::json(['error' => '그 사이 다른 사람이 바꿨습니다. 새로 고친 뒤 다시 하세요', 'reason' => 'changed'], 409);
        exit;
    }
    Audit::allow('database.views', [
        'target_type' => 'page',
        'target_id'   => (int) $dbPage['id'],
        'target_name' => $pageTitle,
        'detail'      => ['page_title' => $pageTitle, 'granted_by' => $changeCode, 'op' => $op, 'view_name' => $viewName],
    ]);
    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    throw $e;
}

Http::json(['views' => array_values($views), 'version' => (string) $done['updated_at']]);
