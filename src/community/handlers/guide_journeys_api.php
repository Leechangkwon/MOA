<?php
declare(strict_types=1);
// Session-only community.read route. Dispatcher enforces CSRF on PATCH. Not a token route.
// PATCH /community/api/me/journeys — 과업 길잡이 진행 상태(사이트 Q:F-15 ③ · community ARCH 26.5 · 지시서 docs/qf15-s3-guide-directive.md C).
// 대상은 언제나 세션 사용자 · 본문은 GuideJourney::validate 가 키를 정확히 거른다 · 감사 0(표시 선호 — 옛 사용 안내 창구와 같음).
require_once __DIR__ . '/../GuideJourney.php';
header('Cache-Control: private, no-store');
if (!GuideJourney::ready()) { Http::error(503, '안내를 준비 중이에요. 잠시 뒤 다시 열어 주세요.'); }
try { $state = GuideJourney::save($user, Http::jsonBody(1024, 3)); }
catch (InvalidArgumentException $e) { Http::error(400, Http::MSG_BAD_REQUEST); }
catch (GuideJourneyRefused $e) { Http::error((int) $e->getCode(), $e->getMessage()); }
Http::json(['journeys'=>$state['journeys'], 'can'=>GuideJourney::allowed($user), 'csrf_token'=>Auth::csrfToken()]);
