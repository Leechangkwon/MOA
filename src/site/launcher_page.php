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
 *   · `config/apps.json` 의 앱 목록(이름·설명·경로·아이콘)을 타일로 그린다. **데이터 0** —
 *     파일·계정·버전·통계가 한 글자도 나가지 않는다 (사이트 PRD Q-B, SECURITY R-98).
 *   · 세션이 있으면 사용자 칩(표시 이름·역할)을 더한다. 세션은 **읽기만** 하고
 *     만들지 않는다 — `Set-Cookie` 가 이 응답에 없다 (Q:T-1).
 *   · `<script>` 가 **하나도 없다**. 그래서 다크 모드 토글 단추도 없고(DESIGN 2.12),
 *     테마는 쿠키와 OS 설정만 따른다. 로그아웃도 CSRF 헤더가 필요해 여기 두지 않는다
 *     (사이트 PRD 4장 정정 — 로그아웃은 각 앱 안에 있다).
 *
 * 쿠키가 없으면 DB 질의도 0 이다(`Auth::currentUser()` 가 쿠키부터 본다).
 */

// 부트스트랩의 require 목록에 새 줄을 더하지 않고 쓰는 곳에서 읽어 온다
// (`Changelog.php`·`Wiki.php`·`Sensitive.php` 와 같은 방식).
require_once __DIR__ . '/../Apps.php';

/** 세션은 읽기만 한다. 없으면 null 이고, 그 경우 화면에 사용자 칩이 없다. */
$launcherUser = Auth::currentUser();

/** 목록은 `src/Apps.php` 한 곳에서만 읽는다. 모양이 어긋나면 예외 → 500 (조용히 넘기지 않는다). */
$launcherApps = Apps::all();

if (!headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
    // 사용자 칩이 붙는 응답이라 공유 캐시에 남으면 안 된다.
    header('Cache-Control: no-store');
}

require __DIR__ . '/../views/launcher.php';
