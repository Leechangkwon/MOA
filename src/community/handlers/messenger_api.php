<?php
declare(strict_types=1);
/**
 * 커뮤니티 메신저 창구 — 사이트 Q:F-11(사용자 결정 2026-10-06 · 지시서 B-4)부터 **휴면 GPT 두 갈래만** 남는다.
 *
 * 사람 대화 6개(`GET /api/messenger` · `/members` · `POST /conversations` · `GET /conversations/{id}` · `…/messages` · `…/read`)는
 * 라우트 표에서 지웠다(→ 404) — 대화는 모아 메신저 `/tbox/api/messages*` 하나로(떠 있는 창). 표 `community.conversations` ·
 * `community.messages` 는 그대로 둔다(삭제는 별도 사용자 결정). API.md 「커뮤니티 대화 창 창구 — 제거·휴면」.
 * GPT 두 갈래는 화면에서 부르는 곳이 없다. 동작은 전과 같다(키가 없으면 POST 503). 되살리거나 지우는 것은 별도 사용자 결정.
 * The dispatcher requires a session, CSRF for mutations and community.write.
 */
Messenger::requireReady();
$route = TBOX_ROUTE;
if ($route === 'GET /community/api/messenger/gpt') {
    Http::json(['enabled' => Messenger::gptEnabled(), 'turns' => Messenger::gptHistory($user), 'csrf_token' => Auth::csrfToken()]); exit;
}
if ($route === 'POST /community/api/messenger/gpt') { Http::json(['turn' => Messenger::gpt($user)]); exit; }
Http::error(404, Http::MSG_NOT_FOUND);
