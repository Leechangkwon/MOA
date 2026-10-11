<?php
declare(strict_types=1);

/**
 * src/site/launcher_page.php — GET·HEAD `/` (Qaredi 런처, Q:F-1)
 *
 * 요구사항: 사이트 `../PRD.md` 4장 · 9장 Q:T-1 · `../docs/launcher/ARCHITECTURE.md` 5장.
 *
 * 라우터([1-0])가 관문 **앞에서** 직접 부른다 — 이 화면은 t-box 라우트 표에 들어가지 않는다.
 * t-box 모듈의 라우트·정책·핸들러는 한 글자도 바뀌지 않았다.
 *
 * 이 화면이 하는 일
 *   · **로그인한 사람에게만** `config/apps.json` 의 앱 목록(이름·설명·경로·아이콘)을 타일로 그린다.
 *     개인 위젯의 업무·자료 내용은 인증 후 각 읽기 전용 창구에서 가져온다. 서버 화면에는 제목·파일 본문을 넣지 않는다.
 *   · 로그인하지 않았으면 사이트 이름과 「로그인」 단추만 — 앱 이름·설명·경로는 0 이다(디버깅 묶음 S2 · 사용자 결정 d72 ① ·
 *     R-98 — 인터넷 공개 전제에서 「무엇이 있나」를 로그인 전에 알려 주지 않는다). 목록은 **여기서 비운다** — 뷰가 역할을 따지지 않는다.
 *   · 세션이 있으면 사용자 칩(표시 이름·역할)을 더한다. 세션은 **읽기만** 하고
 *     만들지 않는다 — 새 세션 `Set-Cookie` 가 이 응답에 없다 (Q:T-1). 예외는 하나 — 옛 이름 쿠키(`tbox_session`)가
 *     실려 오면 그것을 치우는 만료 머리(Max-Age=0)가 붙는다(`Auth::dropLegacyCookie`, S2).
 *   · 숨긴 위젯의 출처를 조회하지 않도록 업무 수를 미리 집계하지 않는다. 기존 테마·시작 도구는 유지한다.
 *
 * 쿠키가 없으면 DB 질의도 0 이다(`Auth::currentUser()` 가 쿠키부터 본다).
 */

// 부트스트랩의 require 목록에 새 줄을 더하지 않고 쓰는 곳에서 읽어 온다
// (`Changelog.php`·`Wiki.php`·`Sensitive.php` 와 같은 방식).
require_once __DIR__ . '/../Apps.php';

/** 세션은 읽기만 한다. 없으면 null 이고, 그 경우 화면에 사용자 칩이 없다. */
$launcherUser = Auth::currentUser();

/** 목록은 `src/Apps.php` 한 곳에서만 읽는다. 모양이 어긋나면 예외 → 500 (조용히 넘기지 않는다).
    로그인 전에는 읽기는 하되(모양 검사는 그대로) **내보내지 않는다** — R-98 · S2. */
$launcherApps = Apps::all();
if ($launcherUser === null) {
    $launcherApps = [];
}
/** 로그인 전 화면의 단추 하나 — 고정 경로(`Http::url` 이 모듈 접두를 붙인다). 요청 값 0. */
$launcherLoginUrl = Http::url('/login');

if (!headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
    // 사용자 칩이 붙는 응답이라 공유 캐시에 남으면 안 된다.
    header('Cache-Control: no-store');
}

require __DIR__ . '/../views/launcher.php';
