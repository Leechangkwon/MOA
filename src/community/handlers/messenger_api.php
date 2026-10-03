<?php
declare(strict_types=1);
/** The dispatcher requires a session, CSRF for mutations and community.write. */
Messenger::requireReady();
$db = Db::instance(); $uid = (int) $user['id']; $route = TBOX_ROUTE;
if ($route === 'GET /community/api/messenger') {
    $sql = <<<'SQL'
        WITH viewer AS (SELECT CAST(:uid AS bigint) AS id)
        SELECT c.id,u.username,u.display_name,c.updated_at,
          (SELECT m.body FROM community.messages m WHERE m.conversation_id=c.id ORDER BY m.id DESC LIMIT 1) AS preview,
          (SELECT count(*) FROM community.messages m WHERE m.conversation_id=c.id AND m.sender_id<>v.id
            AND m.id>CASE WHEN c.user_low=v.id THEN c.low_read ELSE c.high_read END) AS unread
        FROM community.conversations c CROSS JOIN viewer v
        JOIN public.users u ON u.id=CASE WHEN c.user_low=v.id THEN c.user_high ELSE c.user_low END
        WHERE v.id IN (c.user_low,c.user_high) AND u.is_active AND (u.expires_at IS NULL OR u.expires_at>now())
        ORDER BY c.updated_at DESC,c.id LIMIT 100
        SQL;
    Http::json(['conversations' => $db->run($sql, [':uid' => $uid])->fetchAll(), 'csrf_token' => Auth::csrfToken()]); exit;
}
if ($route === 'GET /community/api/messenger/members') {
    $q = Memories::text($_GET['q'] ?? '', 64);
    if (iconv_strlen($q, 'UTF-8') < 2) { Http::json(['members' => []]); exit; }
    $sql = <<<'SQL'
        SELECT id,username,display_name,role FROM public.users
        WHERE id<>:uid AND is_active AND (expires_at IS NULL OR expires_at>now())
          AND strpos(lower(username || ' ' || coalesce(display_name,'')),lower(:query))>0
        ORDER BY username LIMIT 20
        SQL;
    $rows = array_values(array_filter($db->run($sql, [':uid' => $uid, ':query' => $q])->fetchAll(), static fn ($r) => Authz::canCommunityWrite($r)));
    Http::json(['members' => array_map(static fn ($r) => ['username' => $r['username'], 'display_name' => $r['display_name']], $rows)]); exit;
}
if ($route === 'POST /community/api/messenger/conversations') {
    $body = Http::jsonBody(1000, 3);
    if (array_diff(array_keys($body), ['username'])) { Http::error(400, Http::MSG_BAD_REQUEST); }
    $peer = Messenger::member(Memories::text($body['username'] ?? null, 64), $user);
    $sql = <<<'SQL'
        INSERT INTO community.conversations(id,user_low,user_high) VALUES(CAST(:id AS uuid),:low,:high)
        ON CONFLICT(user_low,user_high) DO UPDATE SET user_low=EXCLUDED.user_low RETURNING id
        SQL;
    $id = $db->run($sql, [':id' => tbox_uuid_v4(), ':low' => min($uid, (int) $peer['id']), ':high' => max($uid, (int) $peer['id'])])->fetchColumn();
    Http::json(['id' => $id]); exit;
}
if ($route === 'GET /community/api/messenger/gpt') {
    Http::json(['enabled' => Messenger::gptEnabled(), 'turns' => Messenger::gptHistory($user), 'csrf_token' => Auth::csrfToken()]); exit;
}
if ($route === 'POST /community/api/messenger/gpt') { Http::json(['turn' => Messenger::gpt($user)]); exit; }
$id = $params[0];
$conversation = Messenger::conversation($id, $user);
if ($route === 'GET /community/api/messenger/conversations/{id}') {
    $before = filter_var($_GET['before'] ?? PHP_INT_MAX, FILTER_VALIDATE_INT);
    if ($before === false || $before < 1) { Http::error(400, Http::MSG_BAD_REQUEST); }
    $sql = 'SELECT id,sender_id,body,created_at FROM community.messages WHERE conversation_id=CAST(:id AS uuid) AND id<:before ORDER BY id DESC LIMIT 51';
    $rows = $db->run($sql, [':id' => $id, ':before' => $before])->fetchAll();
    $more = count($rows) > 50; $rows = array_reverse(array_slice($rows, 0, 50));
    Http::json(['messages' => array_map(static fn ($r) => Messenger::message($r, $uid), $rows), 'has_more' => $more,
        'peer' => ['username' => $conversation['username'], 'display_name' => $conversation['display_name']]]); exit;
}
if ($route === 'POST /community/api/messenger/conversations/{id}/read') {
    $body = Http::jsonBody(1000, 3);
    $last = filter_var($body['last_id'] ?? '', FILTER_VALIDATE_INT);
    if (array_diff(array_keys($body), ['last_id']) || $last === false || $last < 1) { Http::error(400, Http::MSG_BAD_REQUEST); }
    $sql = <<<'SQL'
        UPDATE community.conversations c SET
          low_read=CASE WHEN c.user_low=:low_viewer THEN greatest(c.low_read,CAST(:low_last AS bigint)) ELSE c.low_read END,
          high_read=CASE WHEN c.user_high=:high_viewer THEN greatest(c.high_read,CAST(:high_last AS bigint)) ELSE c.high_read END
        WHERE c.id=CAST(:id AS uuid) AND EXISTS(SELECT 1 FROM community.messages m WHERE m.conversation_id=c.id AND m.id=:last)
        SQL;
    $db->run($sql, [':low_viewer' => $uid, ':high_viewer' => $uid, ':low_last' => $last, ':high_last' => $last, ':id' => $id, ':last' => $last]);
    Http::json(['ok' => true]); exit;
}
if ($route === 'POST /community/api/messenger/conversations/{id}/messages') {
    $body = Messenger::payload(4000); $db->begin();
    $lock = 'SELECT id FROM public.users WHERE id=:uid FOR UPDATE';
    $db->run($lock, [':uid' => $uid]);
    Messenger::conversation($id, $user);
    $existing = 'SELECT id,conversation_id,sender_id,body,created_at FROM community.messages WHERE sender_id=:uid AND request_id=CAST(:request AS uuid)';
    $old = $db->run($existing, [':uid' => $uid, ':request' => $body['request_id']])->fetch();
    if ($old) {
        if ($old['conversation_id'] !== $id || $old['body'] !== $body['body']) { $db->rollback(); Http::error(409, '전송 내용을 확인하고 새로 보내 주세요.'); }
        $db->commit(); Http::json(['message' => Messenger::message($old, $uid)]); exit;
    }
    $rate = "SELECT count(*) FROM community.messages WHERE sender_id=:uid AND created_at>now()-interval '1 minute'";
    if ((int) $db->run($rate, [':uid' => $uid])->fetchColumn() >= 30) { $db->rollback(); Http::error(429, '메시지를 너무 빠르게 보냈어요. 잠시 뒤 다시 보내 주세요.'); }
    // Conversation lock keeps sequence allocation/commit in order for read cursors.
    $lock = 'SELECT id FROM community.conversations WHERE id=CAST(:id AS uuid) FOR UPDATE';
    $db->run($lock, [':id' => $id]);
    $sql = <<<'SQL'
        INSERT INTO community.messages(conversation_id,sender_id,request_id,body)
        VALUES(CAST(:conversation AS uuid),:uid,CAST(:request AS uuid),:body) RETURNING id,sender_id,body,created_at
        SQL;
    $row = $db->run($sql, [':conversation' => $id, ':uid' => $uid, ':request' => $body['request_id'], ':body' => $body['body']])->fetch();
    $sql = 'UPDATE community.conversations SET updated_at=now() WHERE id=CAST(:id AS uuid)';
    $db->run($sql, [':id' => $id]); Messenger::audit('send'); $db->commit();
    Http::json(['message' => Messenger::message($row, $uid)], 201); exit;
}
Http::error(404, Http::MSG_NOT_FOUND);
