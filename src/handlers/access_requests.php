<?php
declare(strict_types=1);

/**
 * src/handlers/access_requests.php — 권한 요청 (G-0, t-box PRD F-73 ④, ARCH 5.36)
 *
 *   POST   /api/access-requests          (authenticated)  요청 보내기  {file: UUID | folder: 번호, write: bool, message: ≤200자}
 *   GET    /api/access-requests?box=in   (authenticated)  내가 받은 요청(내가 주인인 항목 · 주인이 꺼진 항목은 Admin) — 기다리는 것 먼저
 *   GET    /api/access-requests?box=out  (authenticated)  내가 보낸 요청의 상태
 *   POST   /api/access-requests/{num}    (file.update)    호스트가 결정  {decision: approve_view | approve_write | reject}
 *   DELETE /api/access-requests/{num}    (authenticated)  보낸 사람이 기다리는 요청을 거둔다
 *
 * ★ 존재 노출 — **없는 항목과 못 보는 항목이 요청 화면에서 갈리지 않는다**(지시서 4장):
 *   보내기의 답은 없는 번호·지운 파일·개인이 아닌 항목·이미 기다리는 요청·새로 저장한 요청 모두 **같은 200 · 같은 글자**다
 *   (저장은 진짜 개인 항목일 때만 — 까닭은 감사 사유에만 남는다). 다른 답은 **이미 권한이 있을 때**(그 사람이 이미 그 항목을
 *   보는 경우라 새로 알려 주는 것이 없다)와, 항목과 상관없이 정해지는 것(형식 400 · 손님의 편집 요청 400 · 하루 한도 429)뿐이다.
 *   하루 한도는 **저장하지 않은 요청도 센다**(감사 행으로 센다) — 저장한 것만 세면 「몇 번 만에 한도에 닿나」로 존재가 갈린다.
 *   보낸 요청 목록(box=out)은 **지금 볼 수 있는 항목**의 기다리는 요청과, 주인이 이미 결정한 요청만 싣는다 — 못 보는 항목의
 *   기다리는 요청은 줄로 싣지 않는다(저장하지 않은 요청과 갈리지 않게). 결정(허락·거절)은 주인이라는 사람의 행동이라 알림으로 간다.
 * ★ 호스트 = 항목 주인(파일 = 올린 사람 · 폴더 = 만든 사람). 주인이 꺼졌거나 만료되면 켜진 Admin 전원(지시서 ★).
 *   Admin 이 주인 대신 허락할 때는 15분 재인증(권한 창구의 Admin 특권과 같다).
 * ★ 알림은 한 함수(tbox_access_notify) — 커뮤니티 알림 종에 `access_request`(호스트에게) · `access_decided`(요청자에게).
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params [0] = (결정·거두기) 요청 번호
 */

require_once __DIR__ . '/_access_common.php';
require_once __DIR__ . '/_folder_paths.php';

$db     = Db::instance();
$me     = (int) $user['id'];
$method = Http::method();
$reqNo  = isset($params[0]) && preg_match('/^[1-9][0-9]{0,8}$/', (string) $params[0]) === 1 ? (int) $params[0] : 0;

/** 요청 줄 + 항목·사람 이름 (목록·결정이 같은 칸을 읽는다) */
$sqlRequestCols = <<<'SQL'
    SELECT r.id, r.folder_id, r.file_id, r.requester_id, r.want_write, r.message, r.status,
           r.created_at, r.decided_at,
           rq.username AS rq_username, rq.display_name AS rq_display_name, rq.role AS rq_role,
           rq.is_active AND (rq.expires_at IS NULL OR rq.expires_at > now()) AS rq_active,
           f.public_id AS file_public_id
      FROM access_requests r
      JOIN users rq ON rq.id = r.requester_id
      LEFT JOIN files f ON f.id = r.file_id
    SQL;

/** 요청 줄의 항목(tbox_access_item 모양 — 지운 파일이면 null) */
$itemOf = static function (array $r): ?array {
    return $r['file_id'] !== null
        ? tbox_access_item_by_id('file', (int) $r['file_id'])
        : tbox_access_item_by_id('folder', (int) $r['folder_id']);
};

// ═══ 보내기 ═════════════════════════════════════════════════════
if ($method === 'POST' && $reqNo === 0) {
    $body = Http::jsonBody();
    $keys = array_keys($body);
    $hasFile   = array_key_exists('file', $body);
    $hasFolder = array_key_exists('folder', $body);
    $kind = $hasFile ? 'file' : 'folder';
    $key  = '';
    if ($hasFile && is_string($body['file'])) {
        $key = (string) $body['file'];
    } elseif ($hasFolder && (is_int($body['folder']) || is_string($body['folder']))) {
        $key = (string) $body['folder'];
    }
    $message = tbox_access_clean_message($body['message'] ?? null);
    $bad = array_diff($keys, ['file', 'folder', 'write', 'message']) !== []
        || $hasFile === $hasFolder
        || ($kind === 'file' ? !Storage::isValidUuid($key) : preg_match('/^[1-9][0-9]{0,8}$/', $key) !== 1)
        || (array_key_exists('write', $body) && !is_bool($body['write']))
        || $message === null;
    $wantWrite = ($body['write'] ?? false) === true;
    if (!$bad && $wantWrite && !Authz::mayReceiveWriteGrant($user)) {
        Audit::deny('access.request', 'bad_request', ['target_type' => $kind, 'detail' => ['why' => 'guest_write']]);
        Http::error(400, TBOX_ACCESS_MSG_GUEST);
    }
    if ($bad) {
        Audit::deny('access.request', 'bad_request', ['target_type' => $kind]);
        Http::error(400, Http::MSG_BAD_REQUEST);
    }

    // 하루 한도 — 감사 행으로 센다(저장하지 않은 요청도 — 위 ★). 한도 거부도 한 줄 남는다.
    $sqlToday = <<<'SQL'
        SELECT count(*) AS n FROM audit_logs
         WHERE actor_id = CAST(:me AS bigint)
           AND action = 'access.request'
           AND happened_at > now() - interval '24 hours'
        SQL;
    $today = $db->run($sqlToday, [':me' => $me])->fetch();
    if (is_array($today) && (int) $today['n'] >= TBOX_ACCESS_REQUEST_DAILY_MAX) {
        Audit::deny('access.request', 'rate_limited', ['target_type' => $kind]);
        Http::json(['error' => TBOX_ACCESS_MSG_LIMIT], 429);
        exit;
    }

    $sent = static function (): void {
        Http::json(['ok' => true, 'sent' => true, 'message' => TBOX_ACCESS_MSG_SENT]);
        exit;
    };

    $item = tbox_access_item($kind, $key);
    if ($item === null) {
        Audit::deny('access.request', 'not_found', ['target_type' => $kind, 'detail' => ['item_kind' => $kind]]);
        $sent();
    }
    $target = [
        'target_type'  => $kind,
        'target_id'    => (int) $item['id'],
        'target_name'  => (string) $item['name'],
        'access_level' => (string) $item['access_level'],
    ];
    if ((string) $item['access_level'] !== Authz::LEVEL_PRIVATE) {
        // 개인이 아닌 항목(볼 수 있든 없든)은 요청을 받지 않는다 — 표가 판정하는 등급이다. 답은 같은 「보냈습니다」.
        Audit::deny('access.request', 'not_private', $target + ['detail' => ['item_kind' => $kind]]);
        $sent();
    }
    $reach = Authz::privateReach($user, $kind, (int) $item['id']);
    if ($reach === 'owner' || $reach === 'write' || ($reach === 'view' && !$wantWrite)) {
        Audit::deny('access.request', 'already', $target + ['detail' => ['item_kind' => $kind, 'want_write' => $wantWrite]]);
        Http::json(['ok' => true, 'already' => true, 'message' => TBOX_ACCESS_MSG_ALREADY]);
        exit;
    }

    $sqlIns = $kind === 'file'
        ? <<<'SQL'
            INSERT INTO access_requests (file_id, requester_id, want_write, message)
            VALUES (CAST(:id AS bigint), CAST(:me AS bigint), CAST(:w AS boolean), :msg)
            ON CONFLICT (file_id, requester_id) WHERE file_id IS NOT NULL AND status = 'pending' DO NOTHING
            RETURNING id
            SQL
        : <<<'SQL'
            INSERT INTO access_requests (folder_id, requester_id, want_write, message)
            VALUES (CAST(:id AS bigint), CAST(:me AS bigint), CAST(:w AS boolean), :msg)
            ON CONFLICT (folder_id, requester_id) WHERE folder_id IS NOT NULL AND status = 'pending' DO NOTHING
            RETURNING id
            SQL;
    $db->begin();
    try {
        $ins = $db->run($sqlIns, [
            ':id' => (int) $item['id'], ':me' => $me, ':w' => $wantWrite ? 'true' : 'false', ':msg' => $message,
        ])->fetch();
        if (!is_array($ins)) {
            $db->rollback();
            Audit::deny('access.request', 'duplicate', $target + ['detail' => ['item_kind' => $kind]]);
            $sent();
        }
        $newId = (int) $ins['id'];
        foreach (tbox_access_hosts($item) as $hostId) {
            tbox_access_notify($hostId, 'access_request', $newId, $me);
        }
        Audit::allow('access.request', $target + ['detail' => [
            'item_kind'   => $kind,
            'request_no'  => $newId,
            'want_write'  => $wantWrite,
            'message_len' => iconv_strlen($message, 'UTF-8'),
            'owner_id'    => (int) $item['owner_id'],
        ]]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
    $sent();
}

// ═══ 목록 ═══════════════════════════════════════════════════════
if ($method === 'GET') {
    $box = Http::queryChoice('box', ['in', 'out'], 'in');
    $folderMap = tbox_folder_map($user);
    if ($box === 'in') {
        // 내가 호스트인 요청 — 항목 주인이 나(켜져 있을 때) · 주인이 꺼진 항목은 Admin. 지운 파일의 요청은 뺀다.
        $sql = $sqlRequestCols . <<<'SQL'
              LEFT JOIN folders d ON d.id = r.folder_id
              JOIN users o ON o.id = coalesce(f.uploaded_by, d.created_by)
             WHERE (f.id IS NULL OR f.deleted_at IS NULL)
               AND (   (o.id = CAST(:me AS bigint) AND o.is_active AND (o.expires_at IS NULL OR o.expires_at > now()))
                    OR (CAST(:admin AS boolean) AND NOT (o.is_active AND (o.expires_at IS NULL OR o.expires_at > now()))))
               AND (r.status = 'pending' OR r.created_at > now() - interval '30 days')
             ORDER BY (r.status = 'pending') DESC, r.created_at DESC, r.id DESC
             LIMIT 100
            SQL;
        $rows = $db->run($sql, [':me' => $me, ':admin' => Authz::canAdmin($user) ? 'true' : 'false'])->fetchAll();
    } else {
        $sql = $sqlRequestCols . <<<'SQL'
             WHERE r.requester_id = CAST(:me AS bigint)
               AND (r.status = 'pending' OR r.created_at > now() - interval '30 days')
             ORDER BY (r.status = 'pending') DESC, r.created_at DESC, r.id DESC
             LIMIT 100
            SQL;
        $rows = $db->run($sql, [':me' => $me])->fetchAll();
    }
    $out = [];
    foreach ($rows as $r) {
        $item = $itemOf($r);
        // 이름은 **지금 볼 수 있을 때만**(호스트는 자기 항목이라 보인다 · 요청자는 허락된 뒤에야 보인다)
        $visible = $item !== null && Authz::canSeeInList($user, $item);
        if ($box === 'out' && (string) $r['status'] === 'pending' && !$visible) {
            continue;   // 못 보는 항목의 기다리는 요청은 싣지 않는다(위 ★ — 저장하지 않은 요청과 갈리지 않게)
        }
        if ($box === 'in' && $item === null) {
            continue;
        }
        $kind = $r['file_id'] !== null ? 'file' : 'folder';
        $out[] = [
            'id'         => (int) $r['id'],
            'status'     => (string) $r['status'],
            'want_write' => $r['want_write'] === true,
            // 메모는 받은 쪽에만(보낸 사람은 자기가 쓴 글을 안다 · 목록 모양을 줄인다)
            'message'    => $box === 'in' ? (string) $r['message'] : null,
            'created_at' => (string) $r['created_at'],
            'decided_at' => $r['decided_at'] === null ? null : (string) $r['decided_at'],
            'requester'  => $box === 'in' ? [
                'username'     => (string) $r['rq_username'],
                'display_name' => $r['rq_display_name'] === null ? null : (string) $r['rq_display_name'],
                'active'       => $r['rq_active'] === true,
                // 손님에게는 편집을 줄 수 없다(화면이 「허락(편집)」 단추를 끈다 — 창구가 다시 막는다)
                'can_write'    => Authz::mayReceiveWriteGrant(['role' => (string) $r['rq_role']]),
            ] : null,
            'item'       => [
                'kind'         => $kind,
                'id'           => $visible ? ($kind === 'file' ? (string) $r['file_public_id'] : (int) $r['folder_id']) : null,
                'name'         => $visible ? (string) $item['name'] : null,
                'access_level' => $visible ? (string) $item['access_level'] : null,
                'path'         => $visible && $kind === 'folder' ? tbox_folder_path_text($folderMap, (int) $r['folder_id']) : null,
            ],
        ];
    }
    Http::json(['box' => $box, 'requests' => $out, 'pending' => tbox_access_pending_count($user)]);
    return;
}

// ═══ 결정 · 거두기 — 요청 한 줄 ═════════════════════════════════
$sqlOne = $sqlRequestCols . <<<'SQL'
     WHERE r.id = CAST(:id AS bigint)
     FOR UPDATE OF r
    SQL;

$db->begin();
try {
    $r = $reqNo > 0 ? $db->run($sqlOne, [':id' => $reqNo])->fetch() : false;
    $item = is_array($r) ? $itemOf($r) : null;
    $kind = is_array($r) && $r['file_id'] !== null ? 'file' : 'folder';

    // ── 거두기 (보낸 사람 · 기다리는 요청만) ──
    if ($method === 'DELETE') {
        if (!is_array($r) || (int) $r['requester_id'] !== $me || (string) $r['status'] !== 'pending') {
            $db->rollback();
            Audit::deny('access.decide', 'not_found', ['target_type' => $kind]);
            Http::error(404, Http::MSG_NOT_FOUND);
        }
        $db->run("UPDATE access_requests SET status = 'cancelled', decided_by = CAST(:me AS bigint), decided_at = now() WHERE id = CAST(:id AS bigint)",
            [':me' => $me, ':id' => $reqNo]);
        Audit::allow('access.decide', [
            'target_type'  => $kind,
            'target_id'    => $item === null ? null : (int) $item['id'],
            // 보낸 사람이 거둘 때는 항목 이름을 남기지 않는다(그 사람이 못 보는 항목일 수 있다 — 감사는 번호만)
            'target_name'  => null,
            'detail'       => ['item_kind' => $kind, 'request_no' => $reqNo, 'decision' => 'cancel'],
        ]);
        $db->commit();
        Http::json(['ok' => true, 'status' => 'cancelled']);
        exit;
    }

    // ── 결정 (호스트만 — 아니면 없는 요청과 같은 404) ──
    if (!is_array($r) || $item === null || !tbox_access_is_host($user, $item)) {
        $db->rollback();
        Audit::deny('access.decide', 'not_found', ['target_type' => $kind]);
        Http::error(404, Http::MSG_NOT_FOUND);
    }
    $target = [
        'target_type'  => $kind,
        'target_id'    => (int) $item['id'],
        'target_name'  => (string) $item['name'],
        'access_level' => (string) $item['access_level'],
    ];
    $body = Http::jsonBody();
    $decision = $body['decision'] ?? null;
    if (array_diff(array_keys($body), ['decision']) !== []
        || !in_array($decision, ['approve_view', 'approve_write', 'reject'], true)
        || (string) $r['status'] !== 'pending'
    ) {
        $db->rollback();
        Audit::deny('access.decide', 'bad_request', $target + ['detail' => ['item_kind' => $kind, 'request_no' => $reqNo]]);
        Http::error(400, Http::MSG_BAD_REQUEST);
    }
    // 주인이 꺼져 Admin 이 대신 결정하면 15분 재인증(권한 창구의 Admin 특권과 같다)
    $onBehalf = ($item['owner_active'] ?? false) !== true;
    if ($onBehalf && ($user['reauth_fresh'] ?? false) !== true) {
        $db->rollback();
        Audit::deny('auth.gate', 'reauth_required', [
            'target_type' => 'user',
            'target_id'   => $me,
            'target_name' => (string) $user['username'],
            'detail'      => ['next' => 'reauth', 'policy' => 'file.update'],
        ]);
        Http::json(['error' => Http::MSG_REAUTH_REQUIRED, 'next' => 'reauth'], 403);
        exit;
    }
    $grantCode = $onBehalf ? Authz::CODE_ADMIN_OVERRIDE : Authz::CODE_OWNER;
    $requester = (int) $r['requester_id'];

    if ($decision !== 'reject') {
        $write = $decision === 'approve_write';
        if ((string) $item['access_level'] !== Authz::LEVEL_PRIVATE) {
            $db->rollback();
            Audit::deny('access.decide', 'not_private', $target + ['detail' => ['item_kind' => $kind, 'request_no' => $reqNo]]);
            Http::error(400, TBOX_ACCESS_MSG_GONE);
        }
        if ($r['rq_active'] !== true || ($write && !Authz::mayReceiveWriteGrant(['role' => (string) $r['rq_role']]))) {
            $db->rollback();
            Audit::deny('access.decide', 'bad_request', $target + ['detail' => ['item_kind' => $kind, 'request_no' => $reqNo]]);
            Http::error(400, $r['rq_active'] !== true ? TBOX_ACCESS_MSG_BAD_USER : TBOX_ACCESS_MSG_NO_WRITE);
        }
        // 허락 = 권한 줄 하나(이미 있으면 **넓히기만** — 편집을 가진 사람을 보기로 내리지 않는다) + 요청 approved
        $sqlGrant = $kind === 'file'
            ? <<<'SQL'
                INSERT INTO access_grants (file_id, user_id, can_write, granted_by)
                VALUES (CAST(:id AS bigint), CAST(:uid AS bigint), CAST(:w AS boolean), CAST(:by AS bigint))
                ON CONFLICT (file_id, user_id) WHERE file_id IS NOT NULL
                DO UPDATE SET can_write = access_grants.can_write OR EXCLUDED.can_write
                SQL
            : <<<'SQL'
                INSERT INTO access_grants (folder_id, user_id, can_write, granted_by)
                VALUES (CAST(:id AS bigint), CAST(:uid AS bigint), CAST(:w AS boolean), CAST(:by AS bigint))
                ON CONFLICT (folder_id, user_id) WHERE folder_id IS NOT NULL
                DO UPDATE SET can_write = access_grants.can_write OR EXCLUDED.can_write
                SQL;
        $db->run($sqlGrant, [':id' => (int) $item['id'], ':uid' => $requester, ':w' => $write ? 'true' : 'false', ':by' => $me]);
        Audit::allow('grant.add', $target + ['detail' => [
            'granted_by' => $grantCode,
            'item_kind'  => $kind,
            'grantee'    => (string) $r['rq_username'],
            'can_write'  => $write,
            'request_no' => $reqNo,
        ]]);
    }
    $newStatus = $decision === 'reject' ? 'rejected' : 'approved';
    $db->run('UPDATE access_requests SET status = :st, decided_by = CAST(:me AS bigint), decided_at = now() WHERE id = CAST(:id AS bigint)',
        [':st' => $newStatus, ':me' => $me, ':id' => $reqNo]);
    tbox_access_notify($requester, 'access_decided', $reqNo, $me);
    Audit::allow('access.decide', $target + ['detail' => [
        'granted_by' => $grantCode,
        'item_kind'  => $kind,
        'request_no' => $reqNo,
        'decision'   => $decision,
        'grantee'    => (string) $r['rq_username'],
    ]]);
    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    throw $e;
}
Http::json(['ok' => true, 'status' => $newStatus]);
