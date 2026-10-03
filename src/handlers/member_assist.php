<?php
declare(strict_types=1);
require_once __DIR__ . '/../MemberAssist.php';
Member::requireMessenger($user);
$uid = (int)$user['id'];
$uuid = static fn($s) => is_string($s) && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $s) === 1;
try {
    if (Http::method() === 'GET') {
        $peer = Http::queryText('peer', 10); $request = Http::queryText('request', 36);
        if (!ctype_digit($peer) || (int)$peer < 1 || (int)$peer > 999999999 || ($request !== '' && !$uuid($request))) Http::error(400, Http::MSG_BAD_REQUEST);
        Member::peer((int)$peer, $user);
        Http::json(MemberAssist::listing($uid, (int)$peer, $request === '' ? null : $request)); exit;
    }
    $body = Http::jsonBody(26000); $keys = array_keys($body); sort($keys);
    if ($keys !== ['action', 'aspect', 'language', 'peer_id', 'request_id', 'source_id', 'text']
        || !is_string($body['action']) || !in_array($body['action'], ['polish', 'spelling', 'translate', 'image'], true)
        || !is_string($body['language']) || !isset(MemberAssist::LANGUAGES[$body['language']])
        || !is_string($body['aspect']) || !in_array($body['aspect'], ['1:1', '16:9', '9:16'], true)
        || !is_int($body['peer_id']) || $body['peer_id'] < 1 || $body['peer_id'] > 999999999
        || !$uuid($body['request_id']) || !is_string($body['text']) || preg_match('//u', $body['text']) !== 1
        || ($body['source_id'] !== null && (!is_int($body['source_id']) || $body['source_id'] < 1))) {
        Http::error(400, Http::MSG_BAD_REQUEST);
    }
    Member::peer($body['peer_id'], $user);
    if ($body['source_id'] !== null) {
        if ($body['action'] !== 'translate') Http::error(400, Http::MSG_BAD_REQUEST);
        $source = Db::instance()->run(<<<'SQL'
            SELECT body FROM member_messages WHERE id=:id AND
              ((sender_id=:me1 AND recipient_id=:peer1) OR (sender_id=:peer2 AND recipient_id=:me2))
            SQL,
            [':id' => $body['source_id'], ':me1' => $uid, ':me2' => $uid, ':peer1' => $body['peer_id'], ':peer2' => $body['peer_id']])->fetch();
        if (!$source) Http::error(404, '번역할 메시지를 찾을 수 없습니다.');
        $body['text'] = $source['body'];
    }
    $body['text'] = trim($body['text']);
    if ($body['text'] === '' || iconv_strlen($body['text'], 'UTF-8') > 4000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $body['text'])) Http::error(400, '내용을 1~4,000자로 입력해 주세요.');
    Member::rate('ai-user', (string)$uid, 4); Member::rate('ai-site', 'shared', 12);
    ignore_user_abort(true);
    $job = MemberAssist::run($uid, $body);
    Http::json(['job' => $job], $job['state'] === 'pending' ? 202 : 200);
} catch (MemberAiDenied $e) {
    Http::error($e->status, MemberAssist::error($e->reason));
} catch (Throwable $e) {
    Db::instance()->rollback(); error_log('[moa] member.ai.assist failed: ' . get_class($e));
    Http::error(503, MemberAi::MESSAGES['storage']);
}
