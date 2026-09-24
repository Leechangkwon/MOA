<?php
declare(strict_types=1);

/**
 * src/community/handlers/draft_key.php — GET /community/api/me/draft-key (정책: community.read)
 *
 * 요구사항: 사이트 PRD Q:F-7 B1-2 「쓰던 글 임시저장 · 불러오기」(결정 W-1~W-4), 지시서 `../docs/qf4-quick-fixes-directive.md`
 *           라운드 B 「B1-2」 새 창구 표, 커뮤니티 ARCHITECTURE 「임시저장」 절, SECURITY C:R-10.
 *
 * 하는 일: 이 브라우저에 **암호화해 남기는** 쓰던 글(`public/assets/community/drafts.js`)의 열쇠를 **본인에게만** 준다.
 *   값 = HMAC-SHA256(APP_SECRET, "qaredi-draft-key:v1:" . <진짜 user_id>) 32바이트 → base64url(끝 `=` 없음).
 *   · **새 비밀을 만들지 않는다** — CSRF 가 쓰는 `APP_SECRET` 에 용도 접두만 달리해 파생한다(CSRF 는 `csrf:` 접두 —
 *     입력이 겹치지 않는다). 그래서 운영 `/etc` 변경·sudo 가 없다. `APP_SECRET` 을 바꾸면 모든 임시저장이 풀리지
 *     않게 되고 화면이 조용히 지운다(수용 — CSRF 도 그때 무효).
 *   · **진짜 사용자** — `$user['id']` 는 「역할로 보기」 중에도 users 행의 id 다(Auth::currentUser 가 바꾸는 것은
 *     role·team_id 뿐). 요청 값은 한 글자도 섞이지 않는다 — 누구의 열쇠인지는 세션이 정한다.
 *   · 열쇠는 사람마다 하나이고 **바뀌지 않는다**(같은 사람이 다시 로그인해야 풀 수 있게 — W-3 ⓑ). 다른 사람·
 *     로그인하지 않은 사람은 받을 수 없다(401 은 라우터 [2] 가 이 파일보다 먼저 낸다).
 *
 * 비밀 처리
 *   · 열쇠·`APP_SECRET` 은 **응답 본문 밖으로 나가지 않는다** — 감사 detail·`app.log`·오류 응답·예외 메시지 어디에도 없다.
 *   · 이 파일은 예외를 삼키지 않는다(잡으면 메시지를 다루게 된다) — 무엇이 터지든 부트스트랩의 일반 500 `{"error":"서버 오류"}`.
 *   · `APP_SECRET` 이 비었거나 32바이트 미만이면 **503** + `app.log` 한 줄(「draft key unavailable」 — 값·길이 없음).
 *     화면은 임시저장을 끈다(평문으로 대신 쓰지 않는다).
 *
 * 응답: `{"v":1,"key":"…"}` · `Cache-Control: no-store`(Http::json) · ETag·Last-Modified 없음.
 *   GET 이라 CSRF 없음(값을 바꾸지 않는다). 교차 출처 읽기는 CORS 헤더가 없고 쿠키가 `SameSite=Lax` 라 막힌다.
 *
 * 감사: **남기지 않는다** — 본인 열쇠를 받는 것은 `GET /api/me`(CSRF 토큰을 받는 창구)와 같은 급이고, 편집·댓글을
 *   시작할 때마다 불려 기록이 소음이 된다. 거부(401)는 기존 인증 흐름 그대로 남는다.
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params 경로 변수 없음
 */

/** 파생 입력의 용도 접두 — CSRF(`csrf:`)와 겹치지 않는다. 판(v1)을 올리면 모든 옛 임시저장이 풀리지 않게 된다. */
$draftKeyPurpose = 'qaredi-draft-key:v1:';

/** 이보다 짧은 비밀로는 열쇠를 만들지 않는다(바이트) */
$draftKeyMinSecretBytes = 32;

$draftSecret = Env::getOrEmpty('APP_SECRET');
if (strlen($draftSecret) < $draftKeyMinSecretBytes) {
    // 값·길이를 적지 않는다 — 「설정을 봐야 한다」만 남긴다.
    error_log('[tbox] request_id=' . TBOX_REQUEST_ID . ' community draft key unavailable');
    Http::error(503, '지금은 쓰던 글을 이 브라우저에 남길 수 없습니다');
}

$draftRaw = hash_hmac('sha256', $draftKeyPurpose . (string) (int) $user['id'], $draftSecret, true);
unset($draftSecret);

Http::json([
    'v'   => 1,
    'key' => rtrim(strtr(base64_encode($draftRaw), '+/', '-_'), '='),
]);
