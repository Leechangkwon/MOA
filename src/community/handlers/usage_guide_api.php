<?php
declare(strict_types=1);
// Session-only community.read route. Dispatcher enforces CSRF on PATCH.
require_once __DIR__ . '/../UsageGuide.php';
require_once __DIR__ . '/../GuideJourney.php';
header('Cache-Control: private, no-store');
if (!UsageGuide::ready()) { Http::error(503, '사용 안내를 준비 중이에요. 잠시 뒤 다시 열어 주세요.'); }
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // 사이트 Q:F-15 ③ (community ARCH 26.5) — 같은 응답에 길잡이 여정(journeys)과 자격(can)을 더한다(화면의 요청 수 +0).
    // 표가 없으면 둘 다 null(화면은 옛 투어만). 여정 행이 하나라도 있으면 첫 로그인 제안을 끈다(UsageGuide.php 무변경).
    $guide = UsageGuide::state((int) $user['id']);
    $journeys = null;
    $can = null;
    if (GuideJourney::ready()) {
        $journeyState = GuideJourney::state($user);
        if ($journeyState['rows'] > 0) { $guide['offer'] = false; }
        $journeys = $journeyState['journeys'];
        $can = GuideJourney::allowed($user);
    }
    Http::json(['guide'=>$guide, 'journeys'=>$journeys, 'can'=>$can, 'csrf_token'=>Auth::csrfToken()]);
    return;
}
try { $state = UsageGuide::save((int) $user['id'], Http::jsonBody(1024, 3)); }
catch (InvalidArgumentException $e) { Http::error(400, Http::MSG_BAD_REQUEST); }
Http::json(['guide'=>$state]);
