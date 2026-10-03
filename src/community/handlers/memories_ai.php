<?php
declare(strict_types=1);
require_once __DIR__ . '/../MemoryAi.php';
Memories::requireReady();
Member::requireMessenger($user);
try {
    if (Http::method() === 'GET') { Http::json(MemoryAi::status()); exit; }
    $body = MemoryAi::input(Http::jsonBody(10000));
    $result = MemoryAi::generate($user, $params[0], $body);
    Http::json($result, $result['state'] === 'pending' ? 202 : 200);
} catch (MemoryAiError $e) {
    Http::error($e->status, $e->getMessage());
} catch (MemberAiDenied $e) {
    Http::error($e->status, MemberAi::MESSAGES[$e->reason] ?? MemberAi::MESSAGES['storage']);
} catch (CommunityAiError $e) {
    Http::error($e->httpStatus, '계정 또는 글의 상태가 바뀌었어요. 새로고침 후 다시 시도해 주세요.');
} catch (Throwable $e) {
    Db::instance()->rollback();
    error_log('[community] memory.ai failed: ' . get_class($e));
    Http::error(503, 'Gemini 글쓰기를 준비하지 못했어요. 잠시 뒤 다시 시도해 주세요.');
}
