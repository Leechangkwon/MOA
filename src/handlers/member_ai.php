<?php
declare(strict_types=1);
require_once __DIR__ . '/../MemberAi.php';
Member::requireMessenger($user);
$uid = (int)$user['id'];
try {
    if (Http::method() === 'GET') {
        $thread = Http::queryText('thread', 10);
        $before = Http::queryText('before', 20);
        if (($thread !== '' && (!ctype_digit($thread) || strlen($thread) > 9))
            || ($before !== '' && (!ctype_digit($before) || strlen($before) > 18))) {
            Http::error(400, Http::MSG_BAD_REQUEST);
        }
        Http::json(MemberAi::history($uid, $thread === '' ? null : (int)$thread, $before ?: '9223372036854775807'));
        exit;
    }
    $body = Http::jsonBody(24000);
    $keys = array_keys($body); sort($keys);
    if ($keys !== ['request_id', 'text', 'thread_id'] || !is_string($body['text'])
        || preg_match('//u', $body['text']) !== 1 || !is_string($body['request_id'])
        || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $body['request_id']) !== 1
        || ($body['thread_id'] !== null && (!is_int($body['thread_id']) || $body['thread_id'] < 1 || $body['thread_id'] > 999999999))) {
        Http::error(400, Http::MSG_BAD_REQUEST);
    }
    $text = trim($body['text']);
    if ($text === '' || iconv_strlen($text, 'UTF-8') > 4000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text)) {
        Http::error(400, '질문을 1~4,000자로 입력해 주세요.');
    }
    Member::rate('ai-user', (string)$uid, 4);
    Member::rate('ai-site', 'shared', 12);
    // A disconnected tab must not abandon a paid request halfway through persistence.
    ignore_user_abort(true);
    $turn = MemberAi::send($uid, $body['thread_id'], $text, $body['request_id']);
    Http::json(['turn' => $turn], $turn['state'] === 'pending' ? 202 : 200);
} catch (MemberAiDenied $e) {
    Http::error($e->status, MemberAi::MESSAGES[$e->reason] ?? MemberAi::MESSAGES['storage']);
} catch (Throwable $e) {
    Db::instance()->rollback();
    // Do not log SQL exceptions or provider text: either can contain message text.
    error_log('[moa] member.ai failed: ' . get_class($e));
    Http::error(503, MemberAi::MESSAGES['storage']);
}
