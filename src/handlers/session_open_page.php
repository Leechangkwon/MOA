<?php
declare(strict_types=1);

/**
 * src/handlers/session_open_page.php — GET /session/open?code=…[&to=…] (정책: public · 화면 주소)
 *
 * 요구사항: 사이트 Q:F-9 · t-box PRD F-75 ④ · ARCHITECTURE 4.9 · API.md 20장 · SECURITY R-113 ·
 *           지시서 docs/qf9-pc-login-directive.md 조각 S 설계 4
 *
 * 하는 일: AI 비서가 받은 1회용 링크를 브라우저가 연다 → `Auth::openSessionFromLink(code)` 가 세션을 만든다
 * (`sessions.origin='token'` — 유휴 10분 · 민감 동작 재인증). 판정·감사·쿠키는 전부 그 함수 안이고, 여기는 끝의 302 만 정한다.
 *
 *   성공 → 302 `/tbox/`. `?to=` 가 있으면 **기존 허용 목록 함수**(Http::returnPath — 로그인 화면의 「보던 곳으로」와 같은 표)가
 *          받아 준 같은 출처 경로로만(Http::redirectReturn). 목록 밖이면 조용히 `/tbox/`.
 *   실패 → 302 `/tbox/login`(고정 주소 — 사유를 주소에 싣지 않는다. 사유는 감사 행에만: link_used · link_expired ·
 *          token_invalid · ip_mismatch · user_disabled). 모양이 틀린 코드·없는 코드도 같은 302 다(감사 0 — Auth 주석).
 *
 * 왜 GET 인가: 비서는 주소를 브라우저에 넘길 뿐이고 브라우저는 주소를 GET 으로 연다. 부작용은 「그 코드의 주인으로 내 세션을
 * 만들기」 하나이며, 코드가 없는 제3자 사이트가 이 주소를 불러도 만들 세션이 없다(코드는 256비트 · 30초 · 1회 · 받은 IP 에서만).
 * 그래서 CSRF 토큰이 없다(로그인 전 창구라 세션도 없다). 로그인 CSRF(남의 코드로 내 브라우저에 로그인)는 그 코드를 쥔 사람이
 * **그 IP 에서** 30초 안에 해야 한다 — 같은 공인 IP 안의 일이라 R-113 에 남긴다.
 *
 * ★ 코드 원문은 이 파일에서 형태 확인과 함수 인자로만 지나간다 — 저장·로그·감사·응답 0(주소에서 이미 떠났다).
 *   (nginx 접근 로그는 앱 밖이라 쿼리가 남을 수 있다 — SECURITY 6장 · R-113. 코드는 30초·1회라 남은 값으로는 들어올 수 없다.)
 *
 * @var array<string,mixed>|null $user   public 라우트라 null (라우터가 인증 관문을 지나지 않았다)
 * @var list<string>             $params 경로 변수 없음
 */

$sessionCode = $_GET['code'] ?? null;

/* 검토 RV-QF9-1 — 이 브라우저에 **유효한 세션이 이미 있으면** 갈아타지 않는다. 링크는 소비하지 않고(30초 뒤 만료) 감사 한 행
   (`session.open deny already_logged_in`)만 남긴 뒤, 성공과 같은 곳(`/tbox/` 또는 허용 목록의 `to`)으로 보낸다 — 지금 세션 그대로다.
   「유효한」의 판정은 Auth::currentUser() 한 곳(만료·유휴·비활성·토큰 세션의 토큰 생존·IP 까지 — 일반 요청과 같은 조회). */
$alreadyIn = Auth::currentUser() !== null;
if ($alreadyIn) {
    if (is_string($sessionCode)) {
        Auth::refuseLinkWhileLoggedIn($sessionCode);
    }
    $opened = null;
} else {
    $opened = is_string($sessionCode) ? Auth::openSessionFromLink($sessionCode) : null;
}
unset($sessionCode);

if ($opened === null && !$alreadyIn) {
    Http::redirect(Http::url(TBOX_LOGIN_PATH));   // 고정 주소 — 요청 값이 Location 에 닿지 않는다
}

$sessionReturnRaw = $_GET['to'] ?? null;
// The assistant may return to the site home after authentication. Only the exact local root is accepted.
if ($sessionReturnRaw === '/') {
    Http::redirect('/');
}
$sessionReturn    = is_string($sessionReturnRaw) ? Http::returnPath($sessionReturnRaw) : null;
if ($sessionReturn !== null) {
    Http::redirectReturn($sessionReturn);          // 허용 목록의 정규형만 — 같은 출처 경로
}
Http::redirect(Http::url(TBOX_HOME_PATH));
