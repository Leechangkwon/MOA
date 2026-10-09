<?php
declare(strict_types=1);
use QarediAssistant\{Chat,Error,Provider,Request,Store};
require_once dirname(__DIR__) . '/assistant/bootstrap.php';
require_once dirname(__DIR__) . '/assistant/Chat.php';

try {
    Provider::ensureEnabled();
    Member::requireMessenger($user);
    $uid = (int)$user['id'];
    Request::keys($_GET, Http::method() === 'GET' ? ['request_id'] : []);
    if (Http::method() === 'GET' && !array_key_exists('request_id', $_GET)) {
        Http::json(Chat::status($uid));
    } else {
        $request = Http::method() === 'GET' ? Chat::requestId($_GET['request_id']) : Chat::send($uid, Request::body());
        // A token revoked during generation must not receive the response.
        $current = Store::freshUser($uid, (int)TBOX_API_TOKEN_ID);
        Member::requireMessenger($current);
        $result = Chat::result($uid, $request);
        Http::json($result, $result['turn']['state'] === 'pending' ? 202 : 200);
    }
} catch (Error $e) {
    Http::json(['error' => $e->getMessage(), 'code' => $e->kind], $e->http);
} catch (MemberAiDenied $e) {
    Http::json(['error' => MemberAi::MESSAGES[$e->reason] ?? MemberAi::MESSAGES['storage'], 'code' => $e->reason], $e->status);
} catch (Throwable $e) {
    Db::instance()->rollback();
    error_log('[assistant.chat] failure=' . get_class($e));
    Http::json(['error' => MemberAi::MESSAGES['storage'], 'code' => 'storage'], 503);
}
