<?php
declare(strict_types=1);
require_once __DIR__ . '/../MemberAssist.php';
// Route policy user.manage includes administrator reauthentication and mutation CSRF.
try {
    if (Http::method() === 'POST') {
        $body = Http::jsonBody(12000);
        $keys = array_keys($body); sort($keys);
        if (!in_array($keys, [['api_key', 'enabled', 'site_daily', 'user_daily'], ['api_key', 'enabled', 'image_enabled', 'image_site_daily', 'image_user_daily', 'site_daily', 'user_daily']], true)
            || !is_bool($body['enabled']) || !is_string($body['api_key'])
            || !is_int($body['user_daily']) || $body['user_daily'] < 1 || $body['user_daily'] > 500
            || !is_int($body['site_daily']) || $body['site_daily'] < 1 || $body['site_daily'] > 5000) {
            Http::error(400, Http::MSG_BAD_REQUEST);
        }
        if (array_key_exists('image_enabled', $body) && (!is_bool($body['image_enabled'])
            || !is_int($body['image_user_daily']) || $body['image_user_daily'] < 1 || $body['image_user_daily'] > 20
            || !is_int($body['image_site_daily']) || $body['image_site_daily'] < 1 || $body['image_site_daily'] > 200)) Http::error(400, Http::MSG_BAD_REQUEST);
        Member::rate('ai-settings', (string)$user['id'], 6);
        $key = trim($body['api_key']);
        $db = Db::instance();
        $db->begin();
        $db->run('SELECT id FROM member_ai_settings WHERE id=1 FOR UPDATE')->fetch();
        if ($key !== '') MemberAi::setKey($key);
        if ($body['enabled'] && MemberAi::key() === '') throw new MemberAiDenied('key_missing');
        $db->run('UPDATE member_ai_settings SET enabled=CAST(:enabled AS boolean),user_daily=:user_daily,site_daily=:site_daily,updated_at=now() WHERE id=1',
            [':enabled' => $body['enabled'] ? 'true' : 'false', ':user_daily' => $body['user_daily'], ':site_daily' => $body['site_daily']]);
        if (array_key_exists('image_enabled', $body)) {
            $db->run('UPDATE member_ai_settings SET image_enabled=CAST(:enabled AS boolean),image_user_daily=:user_daily,image_site_daily=:site_daily WHERE id=1',
                [':enabled' => $body['image_enabled'] ? 'true' : 'false', ':user_daily' => $body['image_user_daily'], ':site_daily' => $body['image_site_daily']]);
        }
        $db->commit();
        unset($key, $body);
        Audit::allow('member.ai.configure', ['target_type' => 'system', 'target_name' => 'Gemini 3.5 Flash']);
    }
    $config = MemberAi::config();
    $config['configured'] = MemberAi::key() !== '';
    $config['model'] = MemberAi::MODEL;
    $usage = MemberAi::usage((int)$user['id']);
    $config['site_used'] = (int)$usage['site_used'];
    $config['total_tokens'] = (int)$usage['total_tokens'];
    $config['image_site_used'] = (int)MemberAssist::imageUsage((int)$user['id'])['site_used'];
    Http::json($config);
} catch (MemberAiDenied $e) {
    Db::instance()->rollback();
    Http::error($e->status, MemberAi::MESSAGES[$e->reason] ?? MemberAi::MESSAGES['storage']);
} catch (Throwable $e) {
    Db::instance()->rollback();
    error_log('[moa] member.ai.configure failed: ' . get_class($e));
    Http::error(503, MemberAi::MESSAGES['storage']);
}
