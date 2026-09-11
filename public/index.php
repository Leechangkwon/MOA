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

    // 4단계 라우트 — 파일 관련 라우트는 역할·팀 정책을 쓴다 (PRD S-01, 13장 4단계).
    // 여기(라우터)의 정책은 "이 역할이 그 종류의 행위를 할 수 있는가"까지만 본다 —
    // 대상 파일을 아직 읽지 않았기 때문이다. 특정 파일에 대한 판단은 핸들러가 행을 읽은 뒤
    // Authz::decideRead / decideDelete / decideUploadAs 로 다시 한다 (ARCH 4.1, 5.1~5.4).
    //   file.read   : 목록·다운로드. 라우터 단계는 통과시키고 행 단위로 판정한다
    //                 (목록은 SQL WHERE 가, 다운로드는 decideRead 가 거른다 — PRD S-02)
    //   file.upload : 업로드할 수 있는 역할인가 (Guest 는 여기서 끝 — authz.route deny)
    //   file.delete : 삭제할 수 있는 역할인가 (Guest 는 여기서 끝)
    ['GET',    '/api/files',               'files_list.php',     'file.read'],
    ['POST',   '/api/files',               'files_upload.php',   'file.upload'],
    // 7단계 — URL 로 파일 가져오기 (PRD F-14, S-05, ARCH 5.6).
    //   file.fetch : Manager 이상만 (PRD 5.4). Employee·Guest 는 여기서 끝난다(authz.route deny).
    //   `{id}` 는 UUID 36자만 받으므로 `/api/files/fetch` 와 충돌하지 않지만,
    //   **의도를 드러내기 위해** `/api/files/{id}` 계열보다 위에 둔다.
    ['POST',   '/api/files/fetch',         'files_fetch.php',    'file.fetch'],
    ['GET',    '/api/files/{id}/download', 'files_download.php', 'file.read'],
    // 5단계 — 설명·접근 등급 변경 (PRD 8장 PATCH 행, F-08, ARCH 5.7).
    //   file.update : 고칠 수 있는 역할인가 (Guest 는 여기서 끝 — authz.route deny).
    //                 그 파일을 고칠 수 있는가는 핸들러가 decideRead → decideEdit 로 다시 판단한다.
    ['PATCH',  '/api/files/{id}',          'files_update.php',   'file.update'],
    ['DELETE', '/api/files/{id}',          'files_delete.php',   'file.delete'],
    ['GET',    '/api/teams',               'teams.php',          Authz::POLICY_AUTHENTICATED],

    // 8단계 — 감사 로그 조회 (PRD F-09, S-07, 5.4 Q-8, ARCH 7.6).
    //   audit.read : Admin 만 (Authz::canReadAudit). Manager·Employee·Guest 는 여기서 끝나고
    //                그 시도 자체가 `authz.route|deny` 로 남는다 (T-13).
    //   `/audit` 는 화면이고 `/api/audit` 는 데이터다. 둘 다 **같은 정책**을 쓴다 —
    //   화면만 숨기고 API 를 열어 두면 그것이 곧 우회 경로다 (PRD S-01).
    ['GET',    '/api/audit',               'audit_list.php',     'audit.read'],
    ['GET',    '/audit',                   'audit_page.php',     'audit.read'],

    // 10단계 — 계정 관리 (PRD F-10, 5.4, 8장 `/api/users` 행) · 휴지통 복구 (F-11) ·
    //          비밀번호 변경 · 2단계 인증 TOTP (SECURITY R-05 · R-10 · R-12 · R-20 · R-33).
    //   user.manage : Admin 만 (Authz::canManageUsers). 나머지 역할은 여기서 끝나고
    //                 그 시도 자체가 `authz.route|deny` 로 남는다.
    //   `/users` 화면과 `/api/users` 데이터는 **같은 정책**을 쓴다 — 화면만 숨기고 API 를
    //   열어 두면 그것이 곧 우회 경로다 (PRD S-01, 8단계 감사 로그와 같은 원칙).
    //   `{uid}` 는 순차 정수(users.id)라 UUID 인 `{id}` 와 형태가 다르다 → `{num}` 자리표시자.
    //   계정 id 는 파일과 달리 열거해도 새는 정보가 없고(목록 자체가 Admin 전용),
    //   URL 에 UUID 를 따로 두는 것보다 화면·로그가 단순하다.
    ['GET',    '/api/users',               'users_list.php',     'user.manage'],
    ['POST',   '/api/users',               'users_create.php',   'user.manage'],
    ['PATCH',  '/api/users/{num}',         'users_update.php',   'user.manage'],
    ['GET',    '/users',                   'users_page.php',     'user.manage'],

    // 본인 계정 관리 — 로그인한 사람이면 누구나 자기 것만 (PRD 7.7 "비밀번호 변경", R-05).
    // 대상은 언제나 **세션의 본인**이며 요청 본문에 대상 계정을 받지 않는다.
    ['POST',   '/api/me/password',         'password_change.php', Authz::POLICY_AUTHENTICATED],
    ['POST',   '/api/me/mfa/enroll',       'mfa_enroll.php',      Authz::POLICY_AUTHENTICATED],
    ['POST',   '/api/me/mfa/confirm',      'mfa_confirm.php',     Authz::POLICY_AUTHENTICATED],
    ['POST',   '/api/me/mfa/disable',      'mfa_disable.php',     Authz::POLICY_AUTHENTICATED],

    // 휴지통 — Admin 만 (PRD 5.4 "삭제된 파일 복구(30일 내)", F-11).
    // `/api/files/trash` 는 `{id}`(UUID 36자)와 형태가 달라 `/api/files/{id}` 계열과 충돌하지 않지만,
    // 의도를 드러내려고 위에 둔다(7단계 `/api/files/fetch` 와 같은 이유).
    ['GET',    '/api/files/trash',          'files_trash.php',   'role:Admin'],
    ['POST',   '/api/files/{id}/restore',   'files_restore.php', 'role:Admin'],
];

const TBOX_HANDLER_DIR = __DIR__ . '/../src/handlers/';

/**
 * 경로 자리표시자 → 허용 문자.
 *
 *   `{id}`  파일의 외부 ID (UUID 36자). 순차 정수를 URL 로 받지 않으므로 열거 표면이 없다 (PRD S-09-5).
 *   `{num}` 계정 id 같은 순차 정수 (10단계, 최대 9자리). UUID 와 형태가 겹치지 않는다.
 *
 * 자리표시자는 이 표에 있는 것만 쓸 수 있고, 표에 없는 `{…}` 가 경로에 있으면
 * tbox_validate_routes() 가 부팅을 막는다(정규식이 아니라 문자 그대로 매칭되어
 * 어떤 요청과도 맞지 않는 죽은 라우트가 되는 것을 방지).
 */
const TBOX_PATH_PATTERNS = [
    '{id}'  => '[0-9a-f-]{36}',
    '{num}' => '[0-9]{1,9}',
];

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
            || preg_match('#^(/|(/(\{[a-z]+\}|[a-z0-9_.-]+))+)$#', $path) !== 1) {
            $problems[] = $where . ': 경로 패턴이 허용 형태가 아니다';
        } elseif (preg_match_all('/\{[a-z]+\}/', $path, $found) > 0) {
            // 표에 없는 자리표시자(`{uid}` 같은 오타)는 정규식이 아니라 글자 그대로 매칭되어
            // 어떤 요청과도 맞지 않는 죽은 라우트가 된다. 그래서 부팅 시점에 막는다.
            foreach ($found[0] as $token) {
                if (!array_key_exists($token, TBOX_PATH_PATTERNS)) {
                    $problems[] = $where . ': 모르는 경로 자리표시자 ' . $token;
                }
            }
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
 * 경로 패턴을 정규식으로 바꾼다. TBOX_PATH_PATTERNS 에 있는 자리표시자만 인정한다.
 *
 * 패턴 문자열은 라우트 표에 적힌 고정 값이고 요청 값이 아니다. 그래도 자리표시자 **밖**의
 * 모든 조각은 preg_quote 를 거치므로, 경로에 정규식 메타문자가 들어가도 그대로 글자로 다뤄진다.
 * 자리표시자가 여러 개면 왼쪽부터 차례로 캡처 그룹이 되어 $params[0], $params[1] … 이 된다.
 */
function tbox_pattern_to_regex(string $path): string
{
    $regex = '';
    $rest  = $path;

    while ($rest !== '') {
        // 가장 먼저 나오는 자리표시자를 찾는다 (없으면 나머지를 통째로 글자로 처리)
        if (preg_match('/\{[a-z]+\}/', $rest, $m, PREG_OFFSET_CAPTURE) !== 1) {
            $regex .= preg_quote($rest, '#');
            break;
        }
        $token  = $m[0][0];
        $at     = (int) $m[0][1];
        $regex .= preg_quote(substr($rest, 0, $at), '#');
        // 표에 없는 자리표시자는 tbox_validate_routes() 가 부팅에서 막는다.
        // 그래도 여기서 다시 확인해, 검사를 건너뛴 경로가 "무엇이든 매칭" 이 되지 않게 한다.
        $regex .= '(' . (TBOX_PATH_PATTERNS[$token] ?? '(?!)') . ')';
        $rest   = substr($rest, $at + strlen($token));
    }

    return '#^' . $regex . '$#';
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
    $matched = [$handler, $policy, array_values($m), $path];
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

[$handlerFile, $policy, $params, $routePattern] = $matched;

/**
 * 이 요청이 어느 라우트였나 — 감사 로그의 `detail.route` (ARCH 7.5, PRD S-07 "요청 경로").
 *
 * 값은 **라우트 표의 패턴**(`DELETE /api/files/{id}`)이며 실제 UUID 가 아니다.
 * 실제 경로를 남기면 감사 로그가 파일 ID 목록이 되어, 로그를 읽을 수 있는 사람에게
 * 열거 재료를 주게 된다. 404/405 로 끝난 요청에는 라우트가 없으므로 정의되지 않는다.
 * 인증 검사보다 **먼저** 정의한다 — 401·403 으로 끝나는 행에도 경로가 남아야 한다.
 */
define('TBOX_ROUTE', $reqMethod . ' ' . $routePattern);

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
            // 실제 경로가 아니라 라우트 패턴을 남긴다 (ARCH 7.5 route, PRD S-07 "요청 경로")
            'target_name' => TBOX_ROUTE,
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
