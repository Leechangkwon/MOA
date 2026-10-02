<?php
declare(strict_types=1);
/** Session, CSRF and community.read/write are enforced by the community dispatcher. */
Memories::requireReady();
$method = $_SERVER['REQUEST_METHOD'];
$route = TBOX_ROUTE;
$prefix = Http::communityUrl('/api/memories/media/');
if ($route === 'GET /community/api/memories') {
    Http::json(Memories::feed($user)); exit;
}
if ($route === 'POST /community/api/memories') {
    Http::json(Memories::save($user, null), 201); exit;
}
if ($route === 'POST /community/api/memories/media') {
    Http::json(Memories::upload($user), 201); exit;
}
if ($route === 'GET /community/api/memories/media/{id}') {
    Memories::stream($params[0], $user);
}
if ($route === 'POST /community/api/memories/links') {
    $body = Http::jsonBody();
    if (array_diff(array_keys($body), ['post_id'])) { Http::error(400, Http::MSG_BAD_REQUEST); }
    $postId = $body['post_id'] ?? null;
    Db::instance()->begin();
    $shareLock = 'SELECT id FROM public.users WHERE id=:owner FOR UPDATE';
    Db::instance()->run($shareLock, [':owner' => (int) $user['id']]);

    if ($postId !== null) {
        if (!Memories::uuid($postId)) { Http::error(400, Http::MSG_BAD_REQUEST); }
        $postLock = 'SELECT id FROM community.memory_posts WHERE id=CAST(:id AS uuid) FOR UPDATE';
        Db::instance()->run($postLock, [':id' => $postId]);
        $post = Memories::row($postId, $user, null, true);
        if ($post['visibility'] !== 'shared') { Http::error(409, '먼저 이 기록의 공개 범위를 ‘링크 공유’로 바꿔 주세요.'); }
    }
    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $sql = <<<'SQL'
        INSERT INTO community.memory_links(token_hash,owner_user_id,post_id)
        VALUES(:hash,:owner,CAST(:post AS uuid)) RETURNING expires_at
        SQL;
    $expires = Db::instance()->run($sql, [':hash' => hash('sha256', $token), ':owner' => (int) $user['id'], ':post' => $postId])->fetchColumn();
    Memories::audit('share');
    Db::instance()->commit();
    Http::json(['path' => Http::communityUrl('/memories/s/' . $token), 'expires_at' => $expires], 201); exit;
}
if ($route === 'DELETE /community/api/memories/links') {
    Db::instance()->begin();
    $shareLock = 'SELECT id FROM public.users WHERE id=:owner FOR UPDATE';
    Db::instance()->run($shareLock, [':owner' => (int) $user['id']]);
    Db::instance()->run('UPDATE community.memory_links SET revoked_at=now() WHERE owner_user_id=:owner AND revoked_at IS NULL',
                [':owner' => (int) $user['id']]);
    Memories::audit('unshare');
    Db::instance()->commit();
    Http::json(['ok' => true]); exit;
}
$id = $params[0];
if (!Memories::uuid($id)) { Http::error(404, Http::MSG_NOT_FOUND); }
$isWrite = $method !== 'GET';
$isOwnerWrite = in_array($route, ['PATCH /community/api/memories/{id}', 'DELETE /community/api/memories/{id}'], true);
if ($route === 'PATCH /community/api/memories/{id}') {
    Http::json(Memories::save($user, $id)); exit;
}
if ($isWrite) {
    Db::instance()->begin();
    $actorLock = 'SELECT id FROM public.users WHERE id=:actor FOR UPDATE';
    Db::instance()->run($actorLock, [':actor' => (int) $user['id']]);
    Db::instance()->run('SELECT id FROM community.memory_posts WHERE id=CAST(:id AS uuid) FOR UPDATE',
                [':id' => $id]);
}
$post = Memories::row($id, $user, null, $isOwnerWrite);
if ($route === 'DELETE /community/api/memories/{id}') {
    Db::instance()->run('UPDATE community.memory_posts SET deleted_at=now() WHERE id=CAST(:id AS uuid)',
                [':id' => $id]);
    Memories::audit('delete'); Db::instance()->commit(); Http::json(['ok' => true]); exit;
}
if (str_ends_with($route, '/like')) {
    if ($method === 'POST') {
        $sql = 'INSERT INTO community.memory_likes(post_id,user_id) VALUES(CAST(:post AS uuid),:actor) ON CONFLICT DO NOTHING';
    } else {
        $sql = 'DELETE FROM community.memory_likes WHERE post_id=CAST(:post AS uuid) AND user_id=:actor';
    }
    Db::instance()->run($sql, [':post' => $id, ':actor' => (int) $user['id']]);
    Db::instance()->commit(); Http::json(['ok' => true]); exit;
}
if (str_ends_with($route, '/comments')) {
    if ($method === 'POST') {
        $body = Http::jsonBody(10000);
        if (array_keys($body) !== ['body']) { Http::error(400, Http::MSG_BAD_REQUEST); }
        $text = Memories::text($body['body'], 1000);
        if ($text === '') { Http::error(400, '댓글을 적어 주세요.'); }
        $rateSql = "SELECT count(*) FROM community.memory_comments WHERE user_id=:actor AND created_at>now()-interval '1 hour'";
        if ((int) Db::instance()->run($rateSql, [':actor' => (int) $user['id']])->fetchColumn() >= 100) {
            Db::instance()->rollback(); Http::error(429, '댓글을 잠시 쉬었다가 남겨 주세요.');
        }
        $sql = 'INSERT INTO community.memory_comments(id,post_id,user_id,body) VALUES(CAST(:id AS uuid),CAST(:post AS uuid),:actor,:body)';
        Db::instance()->run($sql, [':id' => tbox_uuid_v4(), ':post' => $id, ':actor' => (int) $user['id'], ':body' => $text]);
        Memories::audit('comment'); Db::instance()->commit();
    }
    $sql = <<<'SQL'
        SELECT c.id,c.body,c.created_at,u.username,u.display_name FROM community.memory_comments c
        JOIN public.users u ON u.id=c.user_id WHERE c.post_id=CAST(:post AS uuid)
        ORDER BY c.created_at DESC,c.id DESC LIMIT 100
        SQL;
    Http::json(['comments' => array_reverse(Db::instance()->run($sql, [':post' => $id])->fetchAll())]); exit;
}
Http::json(['post' => Memories::output($post, $user, $prefix)]);
