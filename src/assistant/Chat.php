<?php
declare(strict_types=1);
namespace QarediAssistant;

require_once dirname(__DIR__) . '/MemberAi.php';

/** A personal-token adapter; generation, consent and quotas remain in MemberAi. */
final class Chat
{
    public static function requestId($value): string
    {
        if (!is_string($value) || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $value) !== 1) Request::invalid();
        return $value;
    }

    public static function identity(int $uid): array
    {
        return ['provider' => 'gemini', 'model' => \MemberAi::MODEL, 'user_id' => $uid];
    }

    public static function status(int $uid): array
    {
        $config = \MemberAi::config();
        $usage = \MemberAi::usage($uid);
        $consent = \Legal::aiAgreed($uid);
        return self::identity($uid) + [
            'ready' => (bool)$config['enabled'] && \MemberAi::key() !== '' && $consent,
            'consent_required' => !$consent, 'consent_url' => \Http::url('/consent'),
            'remaining' => max(0, (int)$config['user_daily'] - (int)$usage['user_used']),
            'user_daily' => (int)$config['user_daily'], 'max_text_chars' => 4000,
        ];
    }

    public static function result(int $uid, string $request): array
    {
        $row = \Db::instance()->run('SELECT * FROM member_ai_turns WHERE user_id=:uid AND request_id=CAST(:request AS uuid)',
            [':uid' => $uid, ':request' => $request])->fetch();
        if (!$row) throw new \MemberAiDenied('not_found', 404);
        $turn = \MemberAi::publicTurn($row);
        $turn['error_code'] = $turn['state'] === 'failed' ? ($row['error_code'] ?? 'interrupted') : null;
        return self::identity($uid) + ['turn' => $turn];
    }

    public static function send(int $uid, array $body): string
    {
        $keys = array_keys($body); sort($keys);
        if ($keys !== ['request_id', 'text', 'thread_id']) Request::invalid();
        $request = self::requestId($body['request_id']);
        $text = trim(Request::text($body['text'], 1, 4000));
        if ($text === '') Request::invalid();
        $thread = $body['thread_id'] === null ? null : Request::integer($body['thread_id'], 1, 999999999);
        \Member::rate('ai-user', (string)$uid, 4);
        \Member::rate('ai-site', 'shared', 12);
        ignore_user_abort(true);
        \MemberAi::send($uid, $thread, $text, $request);
        return $request;
    }
}
