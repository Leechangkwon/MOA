<?php
declare(strict_types=1);
require_once __DIR__ . '/../Design.php';

/**
 * src/site/not_found_page.php — 라우터가 「없는 주소」로 끝낸 요청의 응답 (디버깅 묶음 U3 · 시연 2026-09-26)
 *
 * 전에는 없는 주소를 브라우저로 열면(예: `/tbox/my-trash`) 화면 대신 `{"error":"찾을 수 없습니다"}` 글자가 보였다.
 * 이제 **브라우저 화면 요청**이면 사이트 모양의 「찾을 수 없는 페이지」 한 장과 첫 화면으로 가는 링크를 보이고,
 * 그 밖(창구 `/api/…` · 프로그램 · `fetch()` 의 기본 Accept)은 **전과 같은 404 JSON** 이다.
 *
 * 화면 요청의 판정 — 셋 다 참일 때만(요청 값은 이 판정에만 쓰이고 응답에 한 글자도 나가지 않는다):
 *   ① 모듈 경로가 `/api/` 로 시작하지 않는다(부르는 쪽이 넘긴다 — t-box·커뮤니티 라우터가 이미 쓰는 같은 갈림)
 *   ② 메서드가 GET·HEAD
 *   ③ `Accept` 에 `text/html` 이 있다(브라우저 주소창·링크 이동이 보내는 값 — `fetch()` 기본값 `*\/*` 은 아니다)
 *
 * ★ 새는 것이 없게 — 이 응답은 **세션을 읽지 않는다**(라우터가 인증보다 앞에서 부른다). 그래서 로그인 전·후, 계정과 역할이
 *   무엇이든 글자가 한 벌이고, 요청 주소·쿼리도 화면에 되비추지 않는다. 상태 코드는 그대로 404, `Cache-Control: no-store`,
 *   `Vary: Accept`(같은 주소가 Accept 로 두 모양이 된다). 있는데 권한이 없는 주소는 이 함수에 오지 않는다 — 라우터 [4] 인가가
 *   전과 같은 403 으로 끝낸다(그 갈래는 바뀌지 않았다 — 구현 기록 `docs/debug-0927-u3-implementation.md` 6장).
 * ★ 화면은 셸 없는 독립 화면이다(로그인·런처·「커뮤니티 준비 중」과 같은 층) — 새 CSS 클래스 0: `.app` · `.main` · `.page-head` ·
 *   `.panel.panel--narrow` · `.btn`. 인라인 스크립트·style 0, 스크립트 파일도 부르지 않는다. 동적 값은 자산 버전 숫자와 고정 경로뿐.
 */

/**
 * 없는 주소의 응답을 내보내고 끝낸다(돌아오지 않는다).
 *
 * @param bool $isApi 모듈 경로가 `/api/` 로 시작하나(부르는 라우터가 이미 쓰는 판정)
 */
function qaredi_not_found(bool $isApi): void
{
    $method = Http::method();
    $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
    $wantsHtml = !$isApi
        && ($method === 'GET' || $method === 'HEAD')
        && stripos($accept, 'text/html') !== false;

    if (!$wantsHtml) {
        if (!headers_sent()) {
            header('Vary: Accept');
        }
        Http::error(404, Http::MSG_NOT_FOUND);
    }

    if (!headers_sent()) {
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('Vary: Accept');
    }
    if ($method === 'HEAD') {
        exit;
    }
    $css = htmlspecialchars(Http::url('/assets/app.css'), ENT_QUOTES, 'UTF-8');
    $ver = (int) filemtime(__DIR__ . '/../../public/assets/app.css');
    echo '<!doctype html>' . "\n"
       . '<html lang="ko"' . Http::themeAttr() . Design::attrs() . '>' . "\n"
       . '<head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<meta name="color-scheme" content="light dark">'
       . '<meta name="robots" content="noindex">'
       . '<title>찾을 수 없는 페이지 · 모아</title>'
       . '<link rel="icon" type="image/svg+xml" href="' . htmlspecialchars(Http::url('/assets/moa-favicon.svg'), ENT_QUOTES, 'UTF-8') . '?v=' . (int) filemtime(__DIR__ . '/../../public/assets/moa-favicon.svg') . '">'
       . '<link rel="stylesheet" href="' . $css . '?v=' . $ver . '">' . Design::assets(false) . '</head>' . "\n"
       . '<body><div class="app"><main class="main" id="not-found">'
       . '<header class="page-head"><div class="page-head__text">'
       . '<h1 class="page-head__title">찾을 수 없는 페이지</h1>'
       . '<p class="page-head__sub">주소가 틀렸거나, 옮겨졌거나, 없어진 페이지입니다.</p></div></header>'
       . '<section class="panel panel--narrow">'
       . '<p>주소를 다시 확인하세요. 링크를 눌러 왔다면 그 링크가 옛 주소일 수 있습니다.</p>'
       . '<p><a class="btn" href="/">첫 화면으로</a></p>'
       . '</section></main></div></body></html>' . "\n";
    exit;
}
