<?php
declare(strict_types=1);

/**
 * public/index.php — 모든 요청의 단일 입구 (ARCHITECTURE 3장 · 4장)
 *
 * nginx 는 index.php 하나만 PHP 로 넘긴다(`location ~ \.php$ { return 404; }`).
 * 따라서 아래 순서를 거치지 않고 애플리케이션 코드에 도달하는 경로가 존재하지 않는다.
 *
 *   [0] bootstrap.php        설정·DB·보안 헤더·오류 은닉
 *   [0-1] 라우트 표 자체 검사  ★ 정책이 없거나 모르는 정책이면 어떤 요청도 처리하지 않고 500
 *   [1] 라우트 매칭           없으면 404, 경로는 맞고 메서드만 다르면 405
 *   [2] Auth::requireLogin()  policy 가 'public' 이 아니면 필수 → 없으면 401
 *   [3] Http::requireCsrf()   상태를 바꾸는 요청의 토큰 검사 (PRD S-09-3)
 *   [4] Authz::authorize()    ★ 권한 판단. 실패 → Audit::deny + 403(동일 문구)
 *   [5] 핸들러 실행
 *
 * ★ 라우트마다 policy 를 반드시 적는다. "권한 검사를 잊었다"가 코드 리뷰가 아니라
 *   **부팅 실패**로 드러나게 하는 것이 이 파일의 핵심이다 (SECURITY R-04).
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

/**
 * 라우트 표 — [메서드, 경로 패턴, 핸들러 파일명, 정책]
 *
 * 경로 패턴: 정적 문자열 또는 `{id}` 자리표시자.
 *   `{id}` 는 UUID 만 받는다(`[0-9a-f-]{36}`) — 외부 노출 ID 는 UUID 이며(PRD S-09-5),
 *   순차 정수 ID 를 URL 로 받지 않으므로 열거 공격의 표면이 없다.
 * 정책 이름은 Authz::KNOWN_POLICIES 에 있는 것만 쓸 수 있다.
 *
 * 2단계 라우트: 로그인 화면·로그인 API 만 public 이고, 나머지는 전부 로그인이 필요하다.
 * `/` 는 1단계의 public 에서 authenticated 로 바뀌었다 — 로그인 없이는 아무것도 못 본다.
 */
const TBOX_ROUTES = [
    ['GET',  '/',            'home.php',       Authz::POLICY_AUTHENTICATED],
    ['GET',  '/login',       'login_page.php', Authz::POLICY_PUBLIC],
    ['POST', '/api/login',   'login.php',      Authz::POLICY_PUBLIC],
    ['POST', '/api/logout',  'logout.php',     Authz::POLICY_AUTHENTICATED],
    ['GET',  '/api/me',      'me.php',         Authz::POLICY_AUTHENTICATED],
    ['GET',  '/api/health',  'health.php',     Authz::POLICY_PUBLIC],
];

const TBOX_HANDLER_DIR = __DIR__ . '/../src/handlers/';

/** `{id}` 자리에 허용되는 문자 (UUID). 경로 변수는 이 형태만 핸들러에 전달된다. */
const TBOX_ID_PATTERN = '[0-9a-f-]{36}';

/** 미로그인 화면 요청을 보내는 곳. 코드 상수이며 요청 값이 섞이지 않는다 (ARCH 4.0). */
const TBOX_LOGIN_PATH = '/login';

/** 이미 로그인한 사용자가 로그인 화면에 왔을 때 보내는 곳 (src/handlers/login_page.php). */
const TBOX_HOME_PATH = '/';

/**
 * 라우트 표가 스스로 성립하는지 검사한다. 하나라도 어긋나면 요청을 처리하지 않는다.
 *
 * 검사 항목
 *   1) 항목이 4칸([메서드, 경로, 핸들러, 정책])인가
 *   2) 메서드가 허용 목록에 있나
 *   3) 경로가 '/' 로 시작하고, 허용 문자와 `{id}` 만 쓰나
 *   4) 정책이 Authz::KNOWN_POLICIES 에 있나        ← "정책 미등록 라우트는 부팅 실패"
 *   5) 핸들러 파일명이 안전한 형태이고, src/handlers/ 안에 실제로 존재하나
 *
 * @return list<string> 문제 목록. 빈 배열이면 정상.
 */
function tbox_validate_routes(): array
{
    $problems = [];
    $methods  = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];
    $seen     = [];

    foreach (TBOX_ROUTES as $i => $route) {
        $where = 'route#' . $i;

        if (!is_array($route) || count($route) !== 4) {
            $problems[] = $where . ': 항목이 [메서드, 경로, 핸들러, 정책] 4칸이 아니다';
            continue;
        }
        [$method, $path, $handler, $policy] = $route;

        if (!is_string($method) || !in_array($method, $methods, true)) {
            $problems[] = $where . ': 알 수 없는 메서드';
        }
        if (!is_string($path) || $path === '' || $path[0] !== '/'
            || preg_match('#^(/|(/(\{id\}|[a-z0-9_.-]+))+)$#', $path) !== 1) {
            $problems[] = $where . ': 경로 패턴이 허용 형태가 아니다';
        }
        if (!is_string($policy) || !in_array($policy, Authz::KNOWN_POLICIES, true)) {
            $problems[] = $where . ': 정책이 Authz::KNOWN_POLICIES 에 없다 (policy=' . var_export($policy, true) . ')';
        }
        if (!is_string($handler) || preg_match('/^[a-z0-9_]+\.php$/', $handler) !== 1) {
            $problems[] = $where . ': 핸들러 파일명이 허용 형태가 아니다';
        } elseif (!is_file(TBOX_HANDLER_DIR . $handler)) {
            $problems[] = $where . ': 핸들러 파일이 없다 (' . $handler . ')';
        }

        $key = (is_string($method) ? $method : '?') . ' ' . (is_string($path) ? $path : '?');
        if (isset($seen[$key])) {
            $problems[] = $where . ': 같은 메서드+경로가 중복 등록됐다 (' . $key . ')';
        }
        $seen[$key] = true;
    }

    return $problems;
}

/**
 * 경로 패턴을 정규식으로 바꾼다. `{id}` 만 자리표시자로 인정한다.
 */
function tbox_pattern_to_regex(string $path): string
{
    $parts = explode('{id}', $path);
    $out   = [];
    foreach ($parts as $p) {
        $out[] = preg_quote($p, '#');
    }
    return '#^' . implode('(' . TBOX_ID_PATTERN . ')', $out) . '$#';
}

// ─── [0-1] 라우트 표 자체 검사 (정책 미등록 → 부팅 실패) ─────────
$routeProblems = tbox_validate_routes();
if ($routeProblems !== []) {
    error_log('[tbox] request_id=' . TBOX_REQUEST_ID
        . ' 라우트 표가 올바르지 않아 요청을 처리하지 않습니다: ' . implode(' | ', $routeProblems));
    Http::error(500, '서버 오류');
}

// ─── [1] 라우트 매칭 ───────────────────────────────────────────
$reqMethod = Http::method();
$reqPath   = Http::path();

$matched       = null;    // [핸들러, 정책, 경로변수]
$pathMatched   = false;   // 경로는 맞았는데 메서드가 달랐다 → 405
$allowedMethods = [];

foreach (TBOX_ROUTES as $route) {
    [$method, $path, $handler, $policy] = $route;
    if (preg_match(tbox_pattern_to_regex($path), $reqPath, $m) !== 1) {
        continue;
    }
    $pathMatched      = true;
    $allowedMethods[] = $method;
    if ($method !== $reqMethod) {
        continue;
    }
    array_shift($m);
    $matched = [$handler, $policy, array_values($m)];
    break;
}

if ($matched === null) {
    if ($pathMatched) {
        if (!headers_sent()) {
            header('Allow: ' . implode(', ', array_unique($allowedMethods)));
        }
        Http::error(405, '허용되지 않는 메서드입니다');
    }
    Http::error(404, Http::MSG_NOT_FOUND);
}

[$handlerFile, $policy, $params] = $matched;

// ─── [2] 인증 · [3] CSRF · [4] 인가 ────────────────────────────
$user = null;
if ($policy !== Authz::POLICY_PUBLIC) {
    // 미로그인 처리 (ARCH 4.0): API 는 401 JSON + Audit::deny, 화면 경로는 302 /login.
    // 화면 경로에 감사 로그를 남기지 않는 이유 — 세션이 만료된 뒤의 첫 방문은 공격이 아니고,
    // 그런 요청까지 남기면 실제 공격 흔적이 묻힌다 (PRD S-07 취지).
    if (Auth::currentUser() === null && strpos($reqPath, '/api/') !== 0) {
        Http::redirect(TBOX_LOGIN_PATH);
    }
    $user = Auth::requireLogin();          // 실패 시 401 + Audit::deny (Auth 안에서)
    Http::requireCsrf();                   // 상태 변경 요청의 토큰 검사 (PRD S-09-3)

    if (!Authz::authorize($policy, $user)) {
        Audit::deny('authz.route', 'role_not_permitted', [
            'target_type' => 'system',
            'target_name' => $reqMethod . ' ' . $reqPath,
            'detail'      => ['policy' => $policy],
        ]);
        Http::error(403, Http::MSG_FORBIDDEN);   // 대상 정보를 담지 않는 동일 문구 (PRD S-09-5)
    }
}

// ─── [5] 핸들러 실행 ───────────────────────────────────────────
// 핸들러 파일명은 라우트 표에 적힌 고정 문자열이며, 위 검사에서 형태와 존재를 확인했다.
// 사용자 입력은 경로에 한 글자도 들어가지 않는다.
(static function (?array $user, array $params, string $file): void {
    require $file;
})($user, $params, TBOX_HANDLER_DIR . $handlerFile);
