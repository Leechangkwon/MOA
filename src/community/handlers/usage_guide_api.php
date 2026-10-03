<?php
declare(strict_types=1);
// Session-only community.read route. Dispatcher enforces CSRF on PATCH.
require_once __DIR__ . '/../UsageGuide.php';
header('Cache-Control: private, no-store');
if (!UsageGuide::ready()) { Http::error(503, '사용 안내를 준비 중이에요. 잠시 뒤 다시 열어 주세요.'); }
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    Http::json(['guide'=>UsageGuide::state((int) $user['id']), 'csrf_token'=>Auth::csrfToken()]);
    return;
}
try { $state = UsageGuide::save((int) $user['id'], Http::jsonBody(1024, 3)); }
catch (InvalidArgumentException $e) { Http::error(400, Http::MSG_BAD_REQUEST); }
Http::json(['guide'=>$state]);
