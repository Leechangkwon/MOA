<?php
declare(strict_types=1);

/**
 * src/handlers/access_grants.php — 사람 지정 권한 (G-0, t-box PRD F-73 ②, ARCH 5.36)
 *
 *   GET    /api/folders/{num}/grants          (정책 file.read)   이 항목의 권한 줄 — 직접 준 것 + 위 개인 폴더에서 이어받은 것
 *   POST   /api/folders/{num}/grants          (정책 file.update) 한 사람에게 보기·편집 주기  {user: 아이디 또는 이름, write: bool}
 *   DELETE /api/folders/{num}/grants/{num}    (정책 file.update) 그 사람에게 직접 준 권한 빼기(경로 둘째 = 사람 번호)
 *   파일도 같은 모양: /api/files/{id}/grants · /api/files/{id}/grants/{num}  ({id} = 파일 UUID)
 *
 * 자격 (판정은 전부 Authz — 이 파일은 역할을 보지 않는다)
 *   보기(GET)    그 항목을 읽을 수 있는 사람(decideRead — 주인·받은 사람·Admin 특권). 못 읽으면 403 동일 문구.
 *   주기·빼기    Authz::decideGrantManage — 주인(위 개인 폴더의 주인 포함) `owner` · 그 밖 Admin `admin_override`
 *               (★ 특권 — 15분 재인증 창 안에서만 · 감사 detail.granted_by) · 그 밖 403 `not_owner`.
 *   대상 항목은 **개인 등급**이어야 한다(아니면 400 `not_private` — 다른 등급은 표가 판정한다. 넓힌 뒤 남은 줄은 GET 에 보인다).
 * 받는 사람 (POST)
 *   아이디(정확히) 또는 이름(정확히 — 같은 이름이 여럿이면 400 「아이디로」). 켜져 있고 만료 전인 계정만 — 없음·꺼짐·만료·
 *   주인 자신은 **같은 400**(bad_user). 손님은 받을 수 있지만 **보기만**(편집이면 400 write_not_allowed — 5-A 규칙과 같다).
 *   이미 준 사람이면 보기/편집을 **바꾼다**(한 사람 한 줄 — 부분 UNIQUE). 같은 값이면 200 · 감사 없음(changed:false).
 * ★ 캐시 0 — 주고 빼면 다음 요청부터 판정(Authz::privateReach)이 곧바로 바뀐다.
 * ★ 없는 폴더 번호·못 보는 폴더는 같은 403(폴더 번호는 순차 정수 — 존재가 갈리지 않게). 파일은 UUID 라 없는 것 404 · 못 보는 것 403
 *   (다른 파일 창구와 같다).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params [0] = 폴더 번호 또는 파일 UUID · [1] = (DELETE) 사람 번호
 */

require_once __DIR__ . '/_access_common.php';
require_once __DIR__ . '/_folder_paths.php';

$kind   = strpos((string) constant('TBOX_ROUTE'), '/api/files/') !== false ? 'file' : 'folder';
$key    = (string) ($params[0] ?? '');
$method = Http::method();
$db     = Db::instance();

$item = tbox_access_item($kind, $key);
if ($item === null) {
    if ($kind === 'folder') {
        Http::error(403, Http::MSG_FORBIDDEN);
    }
    Http::error(404, Http::MSG_NOT_FOUND);
}
$itemId   = (int) $item['id'];
$isPriv   = (string) $item['access_level'] === Authz::LEVEL_PRIVATE;
$target   = [
    'target_type'  => $kind,
    'target_id'    => $itemId,
    'target_name'  => (string) $item['name'],
    'access_level' => (string) $item['access_level'],
];

// ─── [권한 ✔ 1] 읽을 수 있나 — 못 읽는 항목의 권한 줄은 보지도 고치지도 못한다 ───
[$mayRead, $readCode] = Authz::decideRead($user, $item);
if (!$mayRead) {
    if ($method !== 'GET') {
        Audit::deny($method === 'POST' ? 'grant.add' : 'grant.remove', 'not_owner', $target + ['detail' => ['item_kind' => $kind]]);
    }
    Http::error(403, Http::MSG_FORBIDDEN);
}

// ─── GET — 권한 줄 ───────────────────────────────────────────
if ($method === 'GET') {
    [$mayManage] = $isPriv ? Authz::decideGrantManage($user, $item) : [false, ''];
    Http::json([
        'item'       => [
            'kind'         => $kind,
            'id'           => $kind === 'file' ? (string) $item['public_id'] : $itemId,
            'name'         => (string) $item['name'],
            'access_level' => (string) $item['access_level'],
        ],
        // 개인이 아니면 줄이 남아 있어도 효력이 없다(다른 등급은 권한 표가 판정 — 다시 좁히면 되살아난다)
        'effective'  => $isPriv,
        'can_manage' => $mayManage,
        'is_owner'   => $isPriv && $readCode === Authz::CODE_OWNER,
        'grants'     => tbox_access_grant_rows($item, tbox_folder_map($user)),
    ]);
    return;
}

// ─── POST · DELETE — 주인(또는 Admin 특권)만 ────────────────────
$action = $method === 'POST' ? 'grant.add' : 'grant.remove';
if (!$isPriv) {
    // 주기·빼기 모두 개인 항목에서만 — 넓힌 뒤 남은 줄은 효력이 없고, 다시 좁히면 주인이 그때 고친다(지시서 ★ 줄을 남긴다)
    Audit::deny($action, 'not_private', $target + ['detail' => ['item_kind' => $kind]]);
    Http::error(400, TBOX_ACCESS_MSG_NOT_PRIVATE);
}
[$mayManage, $manageCode] = Authz::decideGrantManage($user, $item);
if (!$mayManage) {
    Audit::deny($action, $manageCode, $target + ['detail' => ['item_kind' => $kind]]);
    Http::error(403, Http::MSG_FORBIDDEN);
}
/* Admin 특권으로 남의 개인 항목 권한을 고칠 때만 15분 재인증(share_create 의 S-8 와 같은 모양 — 관문 [3-2] 와 같은 응답). */
if (Authz::grantManageNeedsReauth($manageCode) && ($user['reauth_fresh'] ?? false) !== true) {
    Audit::deny('auth.gate', 'reauth_required', [
        'target_type' => 'user',
        'target_id'   => (int) $user['id'],
        'target_name' => (string) $user['username'],
        'detail'      => ['next' => 'reauth', 'policy' => 'file.update'],
    ]);
    Http::json(['error' => Http::MSG_REAUTH_REQUIRED, 'next' => 'reauth'], 403);
    exit;
}

if ($method === 'DELETE') {
    $uid = isset($params[1]) && preg_match('/^[1-9][0-9]{0,8}$/', (string) $params[1]) === 1 ? (int) $params[1] : 0;
    $sqlDel = $kind === 'file'
        ? 'DELETE FROM access_grants WHERE file_id = CAST(:id AS bigint) AND user_id = CAST(:uid AS bigint) RETURNING can_write'
        : 'DELETE FROM access_grants WHERE folder_id = CAST(:id AS bigint) AND user_id = CAST(:uid AS bigint) RETURNING can_write';
    $sqlWho = <<<'SQL'
        SELECT username FROM users WHERE id = CAST(:uid AS bigint)
        SQL;
    $db->begin();
    try {
        $gone = $uid > 0 ? $db->run($sqlDel, [':id' => $itemId, ':uid' => $uid])->fetch() : false;
        if (!is_array($gone)) {
            $db->rollback();
            Audit::deny($action, 'not_found', $target + ['detail' => ['item_kind' => $kind]]);
            Http::error(404, Http::MSG_NOT_FOUND);
        }
        $who = $db->run($sqlWho, [':uid' => $uid])->fetch();
        Audit::allow($action, $target + ['detail' => [
            'granted_by' => $manageCode,
            'item_kind'  => $kind,
            'grantee'    => is_array($who) ? (string) $who['username'] : null,
            'can_write'  => $gone['can_write'] === true,
        ]]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
    Http::json(['ok' => true, 'removed' => true]);
    return;
}

// POST — 한 사람에게 주기
$body = Http::jsonBody();
if (array_diff(array_keys($body), ['user', 'write']) !== []
    || !isset($body['user']) || !is_string($body['user'])
    || (array_key_exists('write', $body) && !is_bool($body['write']))
) {
    Audit::deny($action, 'bad_request', $target + ['detail' => ['item_kind' => $kind]]);
    Http::error(400, Http::MSG_BAD_REQUEST);
}
$wantWrite = ($body['write'] ?? false) === true;
[$found, $grantee] = tbox_access_find_user((string) $body['user']);
if ($found === 'ambiguous') {
    Audit::deny($action, 'bad_user', $target + ['detail' => ['item_kind' => $kind, 'why' => 'ambiguous']]);
    Http::error(400, TBOX_ACCESS_MSG_AMBIGUOUS);
}
// 주인 자신(항목을 올린·만든 사람)에게는 줄 뜻이 없다 — 없는 계정과 같은 답
if ($found !== 'ok' || $grantee === null || (int) $grantee['id'] === (int) $item['owner_id']) {
    Audit::deny($action, 'bad_user', $target + ['detail' => ['item_kind' => $kind]]);
    Http::error(400, TBOX_ACCESS_MSG_BAD_USER);
}
if ($wantWrite && !Authz::mayReceiveWriteGrant($grantee)) {
    Audit::deny($action, 'write_not_allowed', $target + ['detail' => ['item_kind' => $kind, 'grantee' => (string) $grantee['username']]]);
    Http::error(400, TBOX_ACCESS_MSG_NO_WRITE);
}

$sqlCur = $kind === 'file'
    ? 'SELECT can_write FROM access_grants WHERE file_id = CAST(:id AS bigint) AND user_id = CAST(:uid AS bigint) FOR UPDATE'
    : 'SELECT can_write FROM access_grants WHERE folder_id = CAST(:id AS bigint) AND user_id = CAST(:uid AS bigint) FOR UPDATE';
$sqlIns = $kind === 'file'
    ? <<<'SQL'
        INSERT INTO access_grants (file_id, user_id, can_write, granted_by)
        VALUES (CAST(:id AS bigint), CAST(:uid AS bigint), CAST(:w AS boolean), CAST(:by AS bigint))
        ON CONFLICT (file_id, user_id) WHERE file_id IS NOT NULL
        DO UPDATE SET can_write = EXCLUDED.can_write, granted_by = EXCLUDED.granted_by, granted_at = now()
        SQL
    : <<<'SQL'
        INSERT INTO access_grants (folder_id, user_id, can_write, granted_by)
        VALUES (CAST(:id AS bigint), CAST(:uid AS bigint), CAST(:w AS boolean), CAST(:by AS bigint))
        ON CONFLICT (folder_id, user_id) WHERE folder_id IS NOT NULL
        DO UPDATE SET can_write = EXCLUDED.can_write, granted_by = EXCLUDED.granted_by, granted_at = now()
        SQL;

$changed = true;
$db->begin();
try {
    $cur = $db->run($sqlCur, [':id' => $itemId, ':uid' => (int) $grantee['id']])->fetch();
    if (is_array($cur) && ($cur['can_write'] === true) === $wantWrite) {
        $changed = false;   // 같은 값 — 줄도 감사도 늘지 않는다
    } else {
        $db->run($sqlIns, [
            ':id' => $itemId, ':uid' => (int) $grantee['id'], ':w' => $wantWrite ? 'true' : 'false', ':by' => (int) $user['id'],
        ]);
        Audit::allow($action, $target + ['detail' => [
            'granted_by' => $manageCode,
            'item_kind'  => $kind,
            'grantee'    => (string) $grantee['username'],
            'can_write'  => $wantWrite,
            'before'     => is_array($cur) ? ['can_write' => $cur['can_write'] === true] : null,
        ]]);
    }
    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    throw $e;
}

Http::json([
    'ok'      => true,
    'changed' => $changed,
    'grant'   => [
        'user'         => (int) $grantee['id'],
        'username'     => (string) $grantee['username'],
        'display_name' => $grantee['display_name'] === null ? null : (string) $grantee['display_name'],
        'write'        => $wantWrite,
    ],
]);
