<?php
declare(strict_types=1);

/**
 * src/community/routes.php — 커뮤니티 모듈의 라우트 표와 관문
 *
 * 요구사항: 커뮤니티 `C:S-01`, `../../../community/ARCHITECTURE.md` 13.3 · 21.1 · 21.2,
 *           `../../docs/launcher/ARCHITECTURE.md` 2장(라우터 한 층).
 *
 * `public/index.php` 의 사이트 층이 `/community…` 를 만나면 이 파일을 부르고, 여기가
 * **t-box 와 같은 순서**로 관문을 돈다. t-box 의 라우트 표(`TBOX_ROUTES`)·판정·감사 문장은
 * 한 글자도 바뀌지 않았다 — 바뀐 것은 「표가 하나 더 있다」뿐이다.
 *
 *   [0-1] 라우트 표 자체 검사   정책이 `Authz::KNOWN_POLICIES` 밖이면 어떤 요청도 처리하지 않는다
 *   [1]   경로 매칭            없으면 404, 경로는 맞고 메서드만 다르면 405
 *   [2]   인증                 화면은 302 `/tbox/login?to=community`, `/api/*` 는 401 JSON
 *   [3]   CSRF                 상태를 바꾸는 요청의 토큰 검사 (t-box 와 **같은 토큰**)
 *   [3-1] 최초 로그인 강제 절차 절차가 남았으면 커뮤니티는 통째로 막는다(허용 목록 0)
 *   [4]   인가                 `Authz::authorize(policy, user)` — 실패 403 동일 문구 + 감사
 *   [4-1] 스키마 준비 확인      운영 DB 에 아직 표가 없으면 503 「커뮤니티 준비 중」
 *   [5]   핸들러 실행
 *
 * ★ 프로그램용 토큰(`X-API-Token`)은 커뮤니티에 **통하지 않는다.** 이 파일에는 그 헤더를 읽는
 *   코드가 한 줄도 없다 — 토큰 경로를 넓히는 일은 t-box 쪽 집합(`TBOX_API_TOKEN_ROUTES`)을
 *   고치는 별도의 판단이다 (SECURITY R-82·R-90·R-91).
 * ★ 재인증(`Authz::REAUTH_POLICIES`)은 커뮤니티 정책 둘에 걸리지 않는다. 그래도 관문을
 *   빼지 않고 t-box 와 같은 함수를 부른다 — 나중에 관리 성격의 커뮤니티 정책이 생기면
 *   목록에 이름을 넣는 것만으로 재인증이 따라붙는다.
 */

/*
 * 커뮤니티 클래스 셋. 오토로더를 쓰지 않는 것은 t-box `src/bootstrap.php` 와 같다 —
 * 클래스 이름이 파일 경로로 바뀌는 통로가 존재하지 않는다. 부트스트랩이 아니라 **여기서**
 * 부르는 이유는, t-box 요청 하나하나가 쓰지도 않는 파일을 읽지 않게 하려는 것이다.
 */
require_once __DIR__ . '/Doc.php';
require_once __DIR__ . '/DocRender.php';
require_once __DIR__ . '/CommunityAuthz.php';

/**
 * 라우트 표 — [메서드, **모듈 경로** 패턴, 핸들러 파일명, 정책]
 *
 * 경로는 `/community` 를 **뗀 뒤**의 모듈 경로다(t-box 표가 `/tbox` 를 뗀 경로인 것과 같다).
 * 자리표시자는 `public/index.php` 의 `TBOX_PATH_PATTERNS` 를 그대로 쓴다 — `{id}` 는
 * UUID 36자만 받으므로 순차 정수를 주소로 받는 자리가 없다(열거 방지, t-box S-09-5).
 *
 * 4-B 범위: 화면 둘 · API 열(4-A 의 여덟 + `GET /api/spaces` + `PATCH /api/pages/{id}/visibility`).
 */
const COMMUNITY_ROUTES = [
    // ── 화면 ─────────────────────────────────────────────────
    ['GET',    '/',                          'home_page.php',       'community.read'],
    ['GET',    '/p/{id}',                    'page_view.php',       'community.read'],

    // ── API ──────────────────────────────────────────────────
    // 개인 공간 — 없으면 만든다(「시작하기」 페이지와 함께). ARCH 13.3 의 설계 그대로다.
    ['GET',    '/api/me/space',              'space_me.php',        'community.read'],
    // 내가 볼 수 있는 공간들 — 내 개인 공간 + 내 팀 공간(없으면 그 자리에서 만든다, 4-B).
    ['GET',    '/api/spaces',                'spaces_list.php',     'community.read'],
    // 목록은 **한 공간 안**만 본다(`?space=`, 기본 내 개인 공간). 볼 수 없는 행은 SQL 이 거른다 (C:S-02).
    ['GET',    '/api/pages',                 'pages_list.php',      'community.read'],
    // 5-B 검색 (2026-09-23) — 공간을 가리지 않고 행 관문(목록 조건절)만, 관리자 특권 갈래는 끈다
    ['GET',    '/api/search',                'search.php',          'community.read'],
    ['POST',   '/api/pages',                 'pages_create.php',    'community.write'],
    ['GET',    '/api/pages/{id}',            'pages_get.php',       'community.read'],
    ['PATCH',  '/api/pages/{id}',            'pages_update.php',    'community.write'],
    // 트리에서 자리를 바꾼다 — 부모 갈아 끼우기와 형제 순서가 같은 창구다 (4-A).
    ['PATCH',  '/api/pages/{id}/move',       'pages_move.php',      'community.write'],
    // 공개 범위 바꾸기 — 소유자만. 트리 불변 규칙(부모보다 넓을 수 없다)은 여기서 갈린다 (4-B).
    ['PATCH',  '/api/pages/{id}/visibility',  'pages_visibility.php', 'community.write'],
    ['DELETE', '/api/pages/{id}',            'pages_delete.php',    'community.write'],
    ['POST',   '/api/pages/{id}/heartbeat',  'pages_heartbeat.php', 'community.write'],

    /* ── 이력 · 되돌리기 · 휴지통 (4-C, C:F-19 · C:F-20 · C:S-14) ──
     * 읽는 넷은 `community.read`, 바꾸는 둘은 `community.write` 다. 행 단위 판정은 각각
     * `decidePageRead`(이력 읽기) · `decidePageChange`(되돌리기) · `decidePageRestore`(되살리기)이고,
     * 정책 이름만으로 열리는 것은 하나도 없다 (ARCH 4.1).
     * `{num}` 은 `TBOX_PATH_PATTERNS` 의 **숫자 9자리까지**다 — 판 번호 자리에 글자가 오면
     * 라우트에 맞지 않아 404 이고, 핸들러가 받는 값은 이미 숫자 문자열이다. */
    ['GET',    '/api/pages/{id}/versions',               'versions_list.php',   'community.read'],
    ['GET',    '/api/pages/{id}/versions/{num}',         'version_get.php',     'community.read'],
    ['POST',   '/api/pages/{id}/versions/{num}/restore', 'version_restore.php', 'community.write'],
    ['GET',    '/api/pages/{id}/history',                'history.php',         'community.read'],
    ['GET',    '/api/me/trash',                          'trash_list.php',      'community.read'],
    ['POST',   '/api/pages/{id}/restore',                'page_restore.php',    'community.write'],

    /* ── 상태 칸 (4-D, C:F-37) ──
     * 바꾸는 창구는 `community.write` + 행 단위 `decidePageChange`(소유자·편집 공유자·팀원).
     * 5-A — **담당자 지정은 멈췄다**(사용자 결정: 담당자 = 소유자, 바꾸는 일은 인계다).
     * 그래서 `GET /api/assignees` 는 표에서 빠졌고, 이 창구는 `status` 한 칸만 받는다. */
    ['PATCH',  '/api/pages/{id}/properties',             'pages_properties.php', 'community.write'],

    /* ── 사람 지정 공유 · 인계 (5-A, PRD NG-8 해제 · F-28) ──
     * 목록은 `community.read` + 그 페이지의 `decidePageRead`, 바꾸는 셋은 `community.write` +
     * **`decidePageManage`**(소유자·팀 공간 팀원 — 편집 공유로는 안 된다). 예외 하나: 공유받은
     * 사람이 **자기 공유를 빼는 일**은 관리 자격 없이 된다(`pages_share_delete.php`).
     * 인계의 자격은 따로 있다(`decidePageHandover` — 소유자·자기 팀원 것의 Manager·Admin).
     * Admin 의 인계는 **재인증**이 필요하다 — 라우트 정책이 아니라 판정 결과(`admin_all`)에
     * 걸리므로 핸들러가 세션의 `reauth_fresh` 를 본다(소유자 본인의 인계를 15분마다 끊지 않는다).
     * `{username}` 은 `Auth::USERNAME_RE` 와 같은 글자 집합이다(`COMMUNITY_PATH_PATTERNS`). */
    ['GET',    '/api/pages/{id}/shares',                 'pages_shares_list.php',  'community.read'],
    ['PUT',    '/api/pages/{id}/shares/{username}',      'pages_share_put.php',    'community.write'],
    ['DELETE', '/api/pages/{id}/shares/{username}',      'pages_share_delete.php', 'community.read'],
    ['GET',    '/api/share-candidates',                  'share_candidates.php',   'community.read'],
    ['POST',   '/api/pages/{id}/handover',               'pages_handover.php',     'community.write'],
];

/**
 * 커뮤니티 라우트에만 있는 경로 자리표시자 (5-A).
 *
 * `{username}` — 계정명. `Auth::USERNAME_RE`(`[a-z0-9_.-]{3,64}`)와 **같은 글자 집합**이다.
 * t-box 표(`TBOX_PATH_PATTERNS`, `public/index.php`)를 고치지 않고 여기에 둔다 — 그 자리를
 * 쓰는 라우트가 커뮤니티에만 있다. 값은 핸들러에서 **바인딩 값**으로만 쓰이고(계정 찾기),
 * 파일 경로·SQL 조각이 되지 않는다. 형태가 다르면 라우트에 맞지 않아 404 다.
 */
const COMMUNITY_PATH_PATTERNS = [
    '{username}' => '[a-z0-9_.-]{3,64}',
];

/** 자리표시자 하나의 정규식 — t-box 표 먼저, 없으면 커뮤니티 표. 둘 다 없으면 `null`. */
function community_path_pattern(string $token): ?string
{
    return TBOX_PATH_PATTERNS[$token] ?? COMMUNITY_PATH_PATTERNS[$token] ?? null;
}

/**
 * 커뮤니티 라우트 패턴 → 정규식 (t-box `tbox_pattern_to_regex()` 와 같은 방식, 자리표시자 표만 넓다).
 * 표에 없는 자리표시자는 「무엇과도 맞지 않는다」(`(?!)`)로 바뀐다 — 검사를 건너뛴 경로가
 * 「무엇이든 매칭」이 되지 않게 한다.
 */
function community_pattern_to_regex(string $path): string
{
    $regex = '';
    $rest  = $path;
    while ($rest !== '') {
        if (preg_match('/\{[a-z]+\}/', $rest, $m, PREG_OFFSET_CAPTURE) !== 1) {
            $regex .= preg_quote($rest, '#');
            break;
        }
        $token  = $m[0][0];
        $at     = (int) $m[0][1];
        $regex .= preg_quote(substr($rest, 0, $at), '#');
        $regex .= '(' . (community_path_pattern($token) ?? '(?!)') . ')';
        $rest   = substr($rest, $at + strlen($token));
    }
    return '#^' . $regex . '$#';
}

/**
 * 상태 다섯 값 (4-D, C:F-37). **NULL(없음)이 기본**이고 그것은 이 목록에 없다 —
 * 「없음」은 값이 아니라 값이 없는 상태이므로 허용 목록에 넣지 않는다.
 * 화면 글자는 `public/assets/community/editor.js` 의 `STATUS_LABELS` 가 들고 있다.
 */
const COMMUNITY_STATUSES = ['todo', 'doing', 'review', 'done', 'hold'];

/** 핸들러 디렉터리. 코드 상수이며 요청 값이 경로에 한 글자도 들어가지 않는다. */
const COMMUNITY_HANDLER_DIR = __DIR__ . '/handlers/';

/** 미로그인 화면 요청을 보내는 곳 — t-box 로그인 화면 + 돌아올 곳 꼬리표 (ARCH 21.2). */
const COMMUNITY_LOGIN_TO = 'community';

/**
 * 커뮤니티 라우트 표가 스스로 성립하는지 검사한다 (t-box `tbox_validate_routes()` 와 같은 검사).
 *
 * @return list<string> 문제 목록. 빈 배열이면 정상.
 */
function community_validate_routes(): array
{
    $problems = [];
    $methods  = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];
    $seen     = [];

    foreach (COMMUNITY_ROUTES as $i => $route) {
        $where = 'community route#' . $i;

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
            foreach ($found[0] as $token) {
                if (community_path_pattern($token) === null) {
                    $problems[] = $where . ': 모르는 경로 자리표시자 ' . $token;
                }
            }
        }
        if (!is_string($policy) || !in_array($policy, Authz::KNOWN_POLICIES, true)) {
            $problems[] = $where . ': 정책이 Authz::KNOWN_POLICIES 에 없다 (policy=' . var_export($policy, true) . ')';
        }
        if (!is_string($handler) || preg_match('/^[a-z0-9_]+\.php$/', $handler) !== 1) {
            $problems[] = $where . ': 핸들러 파일명이 허용 형태가 아니다';
        } elseif (!is_file(COMMUNITY_HANDLER_DIR . $handler)) {
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
 * 운영 DB 에 커뮤니티 스키마가 이미 적용됐나 (완료 기준 6).
 *
 * 마이그레이션은 **사용자**가 돌린다(Opus·Fable 은 운영 DB 에 DDL 을 실행하지 않는다).
 * 그 사이에 누가 `/community/` 를 열면 표가 없어 500 이 되는데, 500 은 「서버가 고장났다」는
 * 뜻이라 사실과 다르다. 아직 켜지지 않았을 뿐이므로 **503 + 안내**로 답한다.
 *
 * 판정은 읽기 질의 하나(`to_regclass`)다 — 요청 값이 섞이지 않는 고정 문자열이고,
 * 없으면 NULL 을 돌려줄 뿐 예외를 던지지 않는다.
 */
function community_schema_ready(): bool
{
    $sql = <<<'SQL'
        SELECT to_regclass('community.pages') IS NOT NULL AS ready
        SQL;
    try {
        $row = Db::instance()->run($sql)->fetch();
    } catch (Throwable $e) {
        return false;
    }
    return is_array($row) && ($row['ready'] ?? false) === true;
}

/**
 * 「커뮤니티 준비 중」 응답. `/api/*` 는 JSON, 화면은 같은 뜻의 HTML 한 장이다.
 * 화면 쪽은 셸을 쓰지 않는 독립 화면이고(로그인 화면과 같은 층), 새 CSS 클래스를 만들지
 * 않는다 — `.app`·`.main`·`.page-head`·`.notice` 는 DESIGN.md 2장에 이미 있는 구성 요소다.
 */
function community_not_ready(bool $isApi): void
{
    if ($isApi) {
        Http::error(503, '커뮤니티는 아직 준비 중입니다');
    }

    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
    }
    $css = htmlspecialchars(Http::url('/assets/app.css'), ENT_QUOTES, 'UTF-8');
    echo '<!doctype html>' . "\n"
       . '<html lang="ko"' . Http::themeAttr() . '>' . "\n"
       . '<head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>커뮤니티 · Qaredi</title>'
       . '<link rel="stylesheet" href="' . $css . '"></head>' . "\n"
       . '<body><div class="app"><main class="main">'
       . '<header class="page-head"><div class="page-head__text">'
       . '<h1 class="page-head__title">커뮤니티</h1>'
       . '<p class="page-head__sub">개인 업무 공간</p></div></header>'
       . '<div class="notice" role="status">'
       . '<p class="notice__title">아직 준비 중입니다</p>'
       . '<p>잠시 뒤에 다시 열어 보세요. 계속 이 화면이면 관리자에게 알려 주세요.</p>'
       . '<p><a href="' . htmlspecialchars(Http::url('/'), ENT_QUOTES, 'UTF-8') . '">t-box 로 가기</a>'
       . ' · <a href="/">Qaredi 홈</a></p>'
       . '</div></main></div></body></html>' . "\n";
    exit;
}

/* ═══════════════════════════════════════════════════════════════
 * 핸들러가 함께 쓰는 것 두 가지
 *
 * 왜 여기인가: 아래 둘은 핸들러 여럿이 **글자 그대로 같아야** 하는 코드다. 페이지를 읽는
 * SQL 이 핸들러마다 조금씩 다르면 판정에 필요한 칸이 하나 빠진 핸들러가 생기고, 파생 표를
 * 다시 쓰는 코드가 세 벌이면 언젠가 한 벌만 고쳐진다. 새 파일을 만들지 않고 모듈의 입구
 * 파일에 둔다 — 모든 커뮤니티 요청이 이미 이 파일을 지난다.
 * ═══════════════════════════════════════════════════════════════ */

/**
 * 공유 등급을 세는 **SQL 조각 하나** (5-A) — 판정과 목록이 **글자 그대로 같은 조각**을 쓴다.
 *
 * 돌려주는 것은 스칼라 부질의다: 페이지 `$pageExpr`(바깥 질의의 열, 예: `p.id`) **와 그 조상**에
 * 바인딩 `$userParam` 의 사람에게 준 공유 중 **가장 강한 등급**의 번호(`edit`=2 · `view`=1),
 * 없으면 NULL.
 *   · 조상은 **같은 공간 안**에서만, **살아 있는 것**만 따라 오른다(지워진 조상 너머의 공유는
 *     효력이 없다 — 경로 줄 `community_breadcrumb()` 가 멈추는 자리와 같다). 깊이는 16 에서
 *     멈춘다(트리 한도 8 의 두 배 — 자료가 어긋나도 질의가 끝없이 돌지 않는다).
 *   · 공유받은 계정이 **비활성**이면 그 줄은 효력 0 이다(`su.is_active = true`).
 *
 * `$pageExpr`·`$userParam` 은 **코드에 적힌 리터럴**만 온다(부르는 곳 — 이 파일의
 * `community_page_row`·`community_visible_pages_where`, 그리고 `pages_list.php` 의 「공유받은 페이지」). 요청 값이 한
 * 글자도 이 조각에 들어가지 않는다 — 들어가는 값은 전부 바인딩이다 (PRD S-04).
 */
const COMMUNITY_SHARE_RANK_SQL = <<<'SQL'
(SELECT max(CASE sh.level WHEN 'edit' THEN 2 WHEN 'view' THEN 1 END)
   FROM (WITH RECURSIVE up(id, parent_page_id, space_id, lvl) AS (
             SELECT a.id, a.parent_page_id, a.space_id, 1
               FROM community.pages a
              WHERE a.id = @PAGE@
             UNION ALL
             SELECT b.id, b.parent_page_id, b.space_id, up.lvl + 1
               FROM community.pages b
               JOIN up ON b.id = up.parent_page_id
              WHERE b.space_id = up.space_id
                AND b.deleted_at IS NULL
                AND up.lvl < 16
         )
         SELECT id FROM up) chain
   JOIN community.page_shares sh ON sh.page_id = chain.id
   JOIN public.users su ON su.id = sh.user_id AND su.is_active = true
  WHERE sh.user_id = CAST(@USER@ AS bigint))
SQL;

function community_share_rank_sql(string $pageExpr, string $userParam): string
{
    // 코드 리터럴만 받는다는 약속을 코드로도 지킨다 — 모양이 다르면 부르는 쪽의 실수다.
    if (preg_match('/^[a-z]+\.id$/', $pageExpr) !== 1 || preg_match('/^:[a-z_]+$/', $userParam) !== 1) {
        throw new RuntimeException('community_share_rank_sql: 허용되지 않는 식별자');
    }
    // 조각은 위 nowdoc 상수 **한 벌**이고, 바뀌는 것은 별칭·자리표시자 이름 두 칸뿐이다.
    return strtr(COMMUNITY_SHARE_RANK_SQL, ['@PAGE@' => $pageExpr, '@USER@' => $userParam]);
}

/** 공유 등급 번호 → 이름. 모르는 번호는 `null`(등급 없음)이다 — 모르면 막는다. */
function community_share_level_name($rank): ?string
{
    if ($rank === null) {
        return null;
    }
    $n = (int) $rank;
    return $n === 2 ? 'edit' : ($n === 1 ? 'view' : null);
}

/**
 * 페이지 한 건 + 그 공간을 **한 번에** 읽는다 (ARCH 13.2 가 판정에 요구하는 칸을 전부 싣는다).
 *
 * 지워진 페이지도 돌려준다 — 「없음(404)」과 「지워짐」을 가르는 것은 판정 함수의 몫이다.
 *
 * 5-A — `$viewerId` 를 받는다. 그 사람 몫의 **가장 강한 공유 등급**(이 페이지 + 조상, 활성 계정만)을
 * `share_level` 에, 누구 몫으로 센 값인지를 `share_user_id` 에 싣는다. 판정 함수는 두 값이
 * 지금 묻는 사람과 맞을 때만 그 등급을 쓴다(`CommunityAuthz::shareLevelFor`). 주지 않으면(0)
 * 공유 갈래는 통째로 꺼진다 — 잊은 호출은 **좁은 쪽**으로 틀린다.
 *
 * @return array<string,mixed>|null 없으면 null
 */
function community_page_row(string $publicId, int $viewerId = 0): ?array
{
    $shareRank = community_share_rank_sql('p.id', ':viewer');
    $sql = <<<SQL
        SELECT p.id, p.public_id, p.space_id, p.parent_page_id, p.position,
               p.title, p.icon, p.font, p.small_text, p.full_width, p.visibility,
               p.doc, p.doc_version, p.block_count,
               p.author_id, p.last_edited_by, p.created_at, p.updated_at,
               p.deleted_at, p.deleted_by,
               s.kind          AS space_kind,
               s.owner_user_id AS space_owner_user_id,
               s.access_level  AS space_access_level,
               s.team_id       AS space_team_id,
               s.name          AS space_name,
               -- 4-B: 개인 공간 **주인의 t-box 팀**. `visibility='team'` 판정이 이 값과
               -- 보는 사람의 팀을 견준다(PRD 4.1 「소유자의 t-box 팀」). 팀 판정을 위해
               -- t-box 사용자 표를 읽는 자리는 여기 하나다 — 핸들러가 따로 묻지 않는다.
               owner.team_id   AS space_owner_team_id,
               -- 5-A: 속성 줄의 「소유자」 — 공간 주인의 계정명·활성 여부. 계정 id 는 응답에 나가지 않는다.
               owner.username  AS space_owner_name,
               owner.is_active AS space_owner_active,
               -- 4-C: 지금 판을 **마지막으로 쓴 사람의 이름**. 이력 목록의 맨 윗줄(지금 판)이
               -- 이 값을 그대로 보인다 — 화면이 계정 id 를 이름으로 바꾸는 일이 없게 한다.
               editor.username AS last_edited_by_name,
               -- 4-D: 상태 칸 + (멈춘) 담당자 칸의 옛 값. 5-A 부터 담당자는 **읽기만** 한다.
               p.status, p.assignee_id,
               assignee.username  AS assignee_name,
               assignee.role      AS assignee_role,
               assignee.is_active AS assignee_active,
               -- 5-A: 이 사람 몫의 가장 강한 공유 등급(번호). 아래에서 이름으로 바꾼다.
               {$shareRank} AS share_rank
          FROM community.pages p
          JOIN community.spaces s ON s.id = p.space_id
          LEFT JOIN public.users owner    ON owner.id    = s.owner_user_id
          LEFT JOIN public.users editor   ON editor.id   = p.last_edited_by
          LEFT JOIN public.users assignee ON assignee.id = p.assignee_id
         WHERE p.public_id = CAST(:pid AS uuid)
        SQL;

    $row = Db::instance()->run($sql, [':pid' => $publicId, ':viewer' => $viewerId])->fetch();
    if (!is_array($row)) {
        return null;
    }
    $row['share_level']   = community_share_level_name($row['share_rank']);
    $row['share_user_id'] = $viewerId > 0 ? $viewerId : null;
    unset($row['share_rank']);
    return $row;
}

/**
 * 파생 표(`page_blocks`·`page_links`)를 **다시 쓴다** (ARCH 12.1 · 13.4).
 *
 * 지우고 다시 넣는다 — 파생값은 `doc` 에서 언제든 다시 만들 수 있고, 차이를 계산하는 코드를
 * 두면 그 코드가 틀렸을 때 조용히 어긋난 색인이 남는다. 반드시 **부르는 쪽의 트랜잭션 안**에서
 * 부른다(본문 저장과 색인이 갈라지지 않게).
 *
 * @param array<string,mixed> $extract `Doc::extract()` 의 결과
 */
function community_rewrite_derived(int $pageId, array $extract): void
{
    $db = Db::instance();

    $sqlDelBlocks = <<<'SQL'
        DELETE FROM community.page_blocks WHERE page_id = CAST(:pid AS bigint)
        SQL;
    $sqlDelLinks = <<<'SQL'
        DELETE FROM community.page_links WHERE from_page_id = CAST(:pid AS bigint)
        SQL;
    $sqlInsBlock = <<<'SQL'
        INSERT INTO community.page_blocks (page_id, block_id, block_type, ord, excerpt)
        VALUES (CAST(:pid AS bigint), CAST(:bid AS uuid), :btype, CAST(:ord AS int), :excerpt)
        SQL;
    $sqlInsLink = <<<'SQL'
        INSERT INTO community.page_links (from_page_id, from_block_id, to_page_id)
        VALUES (CAST(:pid AS bigint), CAST(:bid AS uuid), CAST(:to AS uuid))
        SQL;

    $db->run($sqlDelBlocks, [':pid' => $pageId]);
    $db->run($sqlDelLinks, [':pid' => $pageId]);

    foreach ($extract['blocks'] as $b) {
        $db->run($sqlInsBlock, [
            ':pid'     => $pageId,
            ':bid'     => $b['block_id'],
            ':btype'   => $b['block_type'],
            ':ord'     => $b['ord'],
            ':excerpt' => $b['excerpt'],
        ]);
    }

    // 같은 블록이 같은 페이지를 두 번 가리키면 기본키가 겹친다 — 여기서 한 번 접는다.
    $seen = [];
    foreach ($extract['links'] as $l) {
        $key = $l['from_block_id'] . '|' . $l['to_page_id'];
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $db->run($sqlInsLink, [
            ':pid' => $pageId,
            ':bid' => $l['from_block_id'],
            ':to'  => $l['to_page_id'],
        ]);
    }
}

/**
 * 감사 문장의 `{target}` 에 쓰는 페이지 제목 — **30자**로 자른다 (ARCH 21.3 「{target} = 페이지 제목 30자」).
 */
function community_target_title(string $title): string
{
    return iconv_strlen($title, 'UTF-8') > 30
        ? (string) iconv_substr($title, 0, 30, 'UTF-8')
        : $title;
}

/* ══════════════════════════════════════════════════════════════════
 * 트리 질의 (4-A) — 전부 **재귀 CTE 한 번**이다
 *
 * 규칙 하나: 트리를 따라가는 일은 **SQL 이 한다.** PHP 가 부모를 한 줄씩 다시 물으면
 * 깊이만큼 질의가 늘고(N+1), 그 사이에 다른 요청이 트리를 바꾸면 화면이 반쯤 옛 트리를
 * 그린다. 아래 세 함수는 각각 SQL 문장 **하나**만 실행한다.
 * ══════════════════════════════════════════════════════════════════ */

/**
 * 뿌리까지의 경로 (breadcrumb). 맨 앞이 뿌리, **맨 뒤가 자기 자신**이다.
 *
 * 왜 자기를 넣나: 화면의 경로 줄이 「일지 › 9월 › 오늘」처럼 끝까지 보여야 하고, 길이가
 * 곧 깊이다(C:T-22 가 1·2·3 을 본다). 끊긴 조상(지워진 페이지)은 CTE 의 `deleted_at IS NULL`
 * 이 걸러 내므로 거기서 멈춘다 — 지워진 부모 너머는 보이지 않는다.
 *
 * ★ 5-A — **볼 수 있는 조상까지만** 보인다. 공유는 한 페이지(와 그 아래)에만 준다: 비공개
 *   부모 아래의 페이지 하나를 공유받은 사람에게 경로 줄이 부모 제목을 보여 주면, 공유하지 않은
 *   페이지의 제목이 새어 나간다. 그래서 `$u` 를 주면 조상마다 **목록과 같은 조건절**
 *   (`community_visible_pages_where`)로 보이는지를 함께 묻고, 자기에서 위로 올라가다 처음
 *   보이지 않는 조상에서 **자른다**(그 위는 보이더라도 잇지 않는다 — 끊긴 경로를 만들지 않는다).
 *
 * @param  array<string,mixed> $u 보는 사람
 * @return list<array{id:string,title:string,icon:?string}>
 */
function community_breadcrumb(int $pageId, int $spaceId, array $u): array
{
    [$where, $visBind] = community_visible_pages_where($u);

    $sql = <<<SQL
        WITH RECURSIVE up AS (
            SELECT p.id, p.parent_page_id, p.public_id, p.title, p.icon, 0 AS lvl
              FROM community.pages p
             WHERE p.id = CAST(:pid AS bigint)
               AND p.space_id = CAST(:space AS bigint)
               AND p.deleted_at IS NULL
            UNION ALL
            SELECT parent.id, parent.parent_page_id, parent.public_id, parent.title, parent.icon, up.lvl + 1
              FROM community.pages parent
              JOIN up ON parent.id = up.parent_page_id
             WHERE parent.space_id = CAST(:space2 AS bigint)
               AND parent.deleted_at IS NULL
        )
        SELECT up.public_id, up.title, up.icon, up.lvl,
               EXISTS (SELECT 1 FROM community.pages p
                         JOIN community.spaces s ON s.id = p.space_id
                         LEFT JOIN public.users owner ON owner.id = s.owner_user_id
                        WHERE p.id = up.id
                          AND {$where}) AS visible
          FROM up ORDER BY up.lvl ASC
        SQL;

    $rows = Db::instance()->run($sql, array_merge(
        [':pid' => $pageId, ':space' => $spaceId, ':space2' => $spaceId],
        $visBind
    ))->fetchAll();

    // 자기(lvl 0)에서 위로 — 처음 보이지 않는 조상에서 멈춘다. 자기 자신은 이미 읽기 판정을 지났다.
    $chain = [];
    foreach ($rows as $r) {
        if ((int) $r['lvl'] > 0 && $r['visible'] !== true) {
            break;
        }
        $chain[] = [
            'id'    => (string) $r['public_id'],
            'title' => (string) $r['title'],
            'icon'  => $r['icon'] === null ? null : (string) $r['icon'],
        ];
    }
    return array_reverse($chain);
}

/**
 * 바로 아래 자식들 — 제목·아이콘·**그 아래 하위 수**까지 한 질의로.
 *
 * `LEFT JOIN` + `GROUP BY` 로 손자 수를 함께 센다. 자식마다 「하위가 있나」를 다시 묻지
 * 않으려는 것이다(N+1 금지).
 *
 * ★ 4-B — **볼 수 있는 자식만** 돌려준다. 자식은 부모보다 넓을 수 없을 뿐 **좁을 수는
 *   있다**(팀 페이지 아래의 비공개 페이지). 그래서 「부모를 볼 수 있으면 자식도 볼 수
 *   있다」가 성립하지 않고, 거르는 자리는 **SQL `WHERE`** 다 (C:S-02 · C:T-37).
 *
 * @param  array<string,mixed> $u 보는 사람
 * @return list<array{id:string,title:string,icon:?string,position:int,child_count:int,updated_at:string}>
 */
function community_children(int $pageId, array $u): array
{
    [$visible, $visBind] = community_visible_pages_where($u);

    // 별칭을 맞춘다 — 조건절은 `p`(페이지)·`s`(공간)·`owner`(공간 주인)를 전제로 쓰였다.
    $sql = <<<SQL
        SELECT p.public_id, p.title, p.icon, p.position, p.updated_at,
               count(g.id) AS child_count
          FROM community.pages p
          JOIN community.spaces s ON s.id = p.space_id
          LEFT JOIN public.users owner ON owner.id = s.owner_user_id
          LEFT JOIN community.pages g
                 ON g.parent_page_id = p.id AND g.deleted_at IS NULL
         WHERE p.parent_page_id = CAST(:pid AS bigint)
           AND p.deleted_at IS NULL
           AND {$visible}
         GROUP BY p.id, p.public_id, p.title, p.icon, p.position, p.updated_at
         ORDER BY p.position, p.id
         LIMIT 500
        SQL;

    $out = [];
    foreach (Db::instance()->run($sql, array_merge([':pid' => $pageId], $visBind))->fetchAll() as $r) {
        $out[] = [
            'id'          => (string) $r['public_id'],
            'title'       => (string) $r['title'],
            'icon'        => $r['icon'] === null ? null : (string) $r['icon'],
            'position'    => (int) $r['position'],
            'child_count' => (int) $r['child_count'],
            'updated_at'  => (string) $r['updated_at'],
        ];
    }
    return $out;
}

/**
 * 그 페이지의 깊이 — **뿌리를 1** 로 센 값 (4-A `pages_create.php` 가 쓴다).
 * 행이 없거나 지워졌으면 0 이다(부르는 쪽이 이미 행을 확인한 뒤에 부른다).
 */
function community_page_depth(int $pageId): int
{
    $sql = <<<'SQL'
        WITH RECURSIVE up AS (
            SELECT p.id, p.parent_page_id, 1 AS lvl FROM community.pages p
             WHERE p.id = CAST(:pid AS bigint) AND p.deleted_at IS NULL
            UNION ALL
            SELECT parent.id, parent.parent_page_id, u.lvl + 1 FROM community.pages parent
              JOIN up u ON parent.id = u.parent_page_id
             WHERE parent.deleted_at IS NULL
        )
        SELECT max(lvl) AS depth FROM up
        SQL;

    $row = Db::instance()->run($sql, [':pid' => $pageId])->fetch();
    return is_array($row) && $row['depth'] !== null ? (int) $row['depth'] : 0;
}

/**
 * 형제 맨 뒤 자리 번호. 새 페이지는 언제나 형제의 **끝**에 붙는다.
 * 부모가 없으면(뿌리) 같은 공간의 뿌리 페이지들 사이에서 센다.
 */
function community_next_position(int $spaceId, ?int $parentId): int
{
    $sql = <<<'SQL'
        SELECT coalesce(max(p.position), -1) + 1 AS next
          FROM community.pages p
         WHERE p.space_id = CAST(:space AS bigint)
           AND p.deleted_at IS NULL
           AND p.parent_page_id IS NOT DISTINCT FROM CAST(:parent AS bigint)
        SQL;

    $row = Db::instance()->run($sql, [':space' => $spaceId, ':parent' => $parentId])->fetch();
    return is_array($row) ? (int) $row['next'] : 0;
}

/**
 * 트리 옮기기에 필요한 값을 **한 문장**으로 모은다 (4-A `pages_move.php`).
 *
 * 돌려주는 것
 *   `subtree_height`   옮길 페이지를 1 로 센 자기 가지의 높이 (잎이면 1)
 *   `subtree_widest`   옮겨 가는 가지 전체에서 **가장 넓은 공개 범위** (4-B). 자기만이면 자기 값
 *   `parent_depth`     새 부모의 깊이 (뿌리를 1 로 센 값). 부모가 없으면 `null`
 *   `parent_ancestors` 새 부모 자신과 그 위 조상들의 `pages.id` 목록. 부모가 없으면 빈 배열
 *
 * 두 갈래(아래로·위로)를 CTE 둘로 나란히 돌리고 마지막에 한 행으로 모은다 — 요청 하나에
 * 트리 질의는 **이 한 번**이다.
 *
 * ★ 「가장 넓은 범위」를 SQL 이 센다 (4-B). PHP 가 가지를 훑어 최대값을 구하면 같은 순서표
 *   (private<team<internal<published)가 두 벌이 된다. 여기서는 순서만 SQL 에 적고, 그 값을
 *   **이름으로 되돌려** 판정은 `CommunityAuthz::fitsUnderParent` 한 곳에서 한다.
 *
 * @return array{subtree_height:int,subtree_widest:string,parent_depth:?int,parent_ancestors:list<int>}
 */
function community_move_shape(int $pageId, ?int $parentId): array
{
    $sql = <<<'SQL'
        WITH RECURSIVE down AS (
            SELECT p.id, p.visibility, 1 AS lvl FROM community.pages p
             WHERE p.id = CAST(:pid AS bigint) AND p.deleted_at IS NULL
            UNION ALL
            SELECT c.id, c.visibility, d.lvl + 1 FROM community.pages c
              JOIN down d ON c.parent_page_id = d.id
             WHERE c.deleted_at IS NULL
        ), up AS (
            SELECT p.id, p.parent_page_id, 1 AS lvl FROM community.pages p
             WHERE p.id = CAST(:parent AS bigint) AND p.deleted_at IS NULL
            UNION ALL
            SELECT parent.id, parent.parent_page_id, u.lvl + 1 FROM community.pages parent
              JOIN up u ON parent.id = u.parent_page_id
             WHERE parent.deleted_at IS NULL
        )
        SELECT (SELECT max(lvl) FROM down)      AS subtree_height,
               (SELECT max(CASE visibility WHEN 'private' THEN 0 WHEN 'team' THEN 1
                                           WHEN 'internal' THEN 2 WHEN 'published' THEN 3 END)
                  FROM down)                    AS subtree_widest_rank,
               (SELECT max(lvl) FROM up)        AS parent_depth,
               (SELECT string_agg(CAST(id AS text), ',') FROM up) AS parent_ancestors
        SQL;

    $row = Db::instance()->run($sql, [':pid' => $pageId, ':parent' => $parentId])->fetch();

    $ancestors = [];
    if (is_array($row) && $row['parent_ancestors'] !== null && (string) $row['parent_ancestors'] !== '') {
        foreach (explode(',', (string) $row['parent_ancestors']) as $id) {
            $ancestors[] = (int) $id;
        }
    }

    // 번호를 이름으로 되돌린다. 모르는 번호는 **가장 넓은 값**으로 본다 — 모르면 막는 쪽이다.
    $names  = ['private', 'team', 'internal', 'published'];
    $rank   = is_array($row) && $row['subtree_widest_rank'] !== null ? (int) $row['subtree_widest_rank'] : 0;
    $widest = $names[$rank] ?? 'published';

    return [
        'subtree_height'   => is_array($row) && $row['subtree_height'] !== null ? (int) $row['subtree_height'] : 1,
        'subtree_widest'   => $widest,
        'parent_depth'     => is_array($row) && $row['parent_depth'] !== null ? (int) $row['parent_depth'] : null,
        'parent_ancestors' => $ancestors,
    ];
}

/* ══════════════════════════════════════════════════════════════════
 * 공간 (4-B) — 개인 공간 · 팀 공간
 *
 * 규칙 하나: **목록은 SQL `WHERE` 로 거른다.** 아래 `community_visible_pages_where()` 가
 * 그 조건절이고, `CommunityAuthz::decidePageRead()` 와 **같은 판정**을 SQL 로 적은 것이다.
 * 두 벌이 어긋나면 화면이 볼 수 없는 제목을 내려주게 되므로, 고칠 때는 **둘을 함께** 고친다
 * (C:T-37 이 psql 로 센 수와 API 가 준 수를 대조한다).
 * ══════════════════════════════════════════════════════════════════ */

/**
 * 공간 한 건 + (개인 공간이면) 주인의 팀. 없으면 `null`.
 *
 * @return array<string,mixed>|null
 */
function community_space_row(int $spaceId): ?array
{
    $sql = <<<'SQL'
        SELECT s.id, s.kind, s.name, s.description, s.access_level, s.team_id,
               s.owner_user_id, s.is_active, s.created_at,
               owner.team_id AS owner_team_id,
               t.name        AS team_name,
               t.is_active   AS team_is_active
          FROM community.spaces s
          LEFT JOIN public.users owner ON owner.id = s.owner_user_id
          LEFT JOIN public.teams t     ON t.id = s.team_id
         WHERE s.id = CAST(:sid AS bigint)
        SQL;

    $row = Db::instance()->run($sql, [':sid' => $spaceId])->fetch();
    return is_array($row) ? $row : null;
}

/**
 * 내 개인 공간. 아직 없으면 `null`(`GET /api/me/space` 가 만든다).
 *
 * @return array<string,mixed>|null
 */
function community_my_personal_space(int $userId): ?array
{
    $sql = <<<'SQL'
        SELECT s.id, s.kind, s.name, s.description, s.access_level, s.team_id,
               s.owner_user_id, s.is_active, s.created_at,
               owner.team_id AS owner_team_id,
               NULL::text    AS team_name,
               NULL::boolean AS team_is_active
          FROM community.spaces s
          LEFT JOIN public.users owner ON owner.id = s.owner_user_id
         WHERE s.kind = 'personal' AND s.owner_user_id = CAST(:uid AS bigint)
        SQL;

    $row = Db::instance()->run($sql, [':uid' => $userId])->fetch();
    return is_array($row) ? $row : null;
}

/**
 * 이 사람이 **그 공간을 열어 볼 수 있나** (목록 창구의 공간 관문, C:T-35 · C:T-36).
 *
 * 페이지 한 건의 판정(`decidePageRead`)과 다른 물음이다 — 여기서는 「그 그릇을 통째로
 * 훑어도 되나」를 묻는다. 남의 개인 공간은 **통째로 닫혀 있다**: 그 안에 사내로 열어 둔
 * 페이지가 있더라도, 남의 공간 목차를 넘겨보는 일은 공개 범위가 허락한 일이 아니다.
 *
 * @param  array<string,mixed> $u
 * @param  array<string,mixed> $space `community_space_row()` 의 행
 * @return array{0:bool,1:string} [허용 여부, 코드]
 */
function community_space_gate(array $u, array $space): array
{
    $kind = (string) ($space['kind'] ?? '');

    if ($kind === 'personal') {
        $owner = $space['owner_user_id'] ?? null;
        if ($owner !== null && (int) $owner === (int) $u['id']) {
            return [true, CommunityAuthz::CODE_OWNER];
        }
        return [false, CommunityAuthz::CODE_NOT_OWNER];
    }

    if ($kind === 'team') {
        // 판정을 두 벌로 적지 않으려고 페이지 판정 함수를 그대로 부른다 — 팀 공간의
        // 페이지 판정은 「그 팀원인가」 하나뿐이라 공간 판정과 같은 물음이다.
        return CommunityAuthz::decidePageRead($u, [
            'deleted_at'          => null,
            'space_kind'          => 'team',
            'space_team_id'       => $space['team_id'] ?? null,
            'space_owner_user_id' => null,
            'visibility'          => 'private',
        ]);
    }

    // 게시판 공간(`board`)은 5단계다.
    return [false, CommunityAuthz::CODE_ROLE_NOT_PERMITTED];
}

/**
 * 목록 질의에 붙일 **행 단위 조건절** (C:S-02 「못 보는 것은 응답에 없다」, C:T-37).
 *
 * 돌려주는 조건절은 별칭 셋을 전제로 한다 — 부르는 SQL 은 반드시
 * `FROM community.pages p JOIN community.spaces s ON s.id = p.space_id
 *  LEFT JOIN public.users owner ON owner.id = s.owner_user_id` 여야 한다.
 * 별칭은 SQL 식별자 자리라 바인딩할 수 없으므로 **코드에 적힌 리터럴 셋**으로 고정한다
 * (t-box `Authz::visibleWhere` 의 허용 목록과 같은 이유).
 *
 * 조건은 `CommunityAuthz::decidePageRead` 를 한 줄씩 옮긴 것이다:
 *   내 개인 공간 전부 / **나에게 준 공유(이 페이지·조상, 활성 계정, 세 범위만 — 5-A)** /
 *   `internal` + 쓰기 자격 / `team` + 주인과 같은 팀 / Admin / 내 팀 공간.
 * `published` 는 어느 갈래에도 없다 — 5단계까지는 목록에도 없다.
 *
 * @param  array<string,mixed> $u
 * @return array{0:string,1:array<string,mixed>}
 */
function community_visible_pages_where(array $u): array
{
    // 5-A 공유 갈래 — `community_page_row()` 가 판정에 싣는 값과 **같은 조각**이다.
    $shared = community_share_rank_sql('p.id', ':vis_share');
    $sql = <<<SQL
(
      (s.kind = 'personal' AND s.owner_user_id = CAST(:vis_me AS bigint))
   OR (s.kind = 'personal' AND p.visibility IN ('private','team','internal')
       AND {$shared} IS NOT NULL)
   OR (s.kind = 'personal' AND p.visibility = 'internal' AND CAST(:vis_write AS boolean))
   OR (s.kind = 'personal' AND p.visibility = 'team'
       AND owner.team_id IS NOT NULL AND owner.team_id = CAST(:vis_team AS bigint))
   OR (s.kind = 'personal' AND p.visibility IN ('private','team','internal')
       AND CAST(:vis_admin AS boolean))
   OR (s.kind = 'team' AND s.team_id IS NOT NULL AND s.team_id = CAST(:vis_team AS bigint))
   OR (s.kind = 'team' AND CAST(:vis_admin AS boolean))
)
SQL;

    return [$sql, [
        ':vis_me'    => (int) $u['id'],
        ':vis_share' => (int) $u['id'],
        // 「쓰기 자격이 있는 역할인가」·「Admin 인가」의 판단은 `Authz` 가 한다 — 이 파일에
        // 역할 문자열 비교가 생기지 않게 참·거짓만 받아 바인딩한다 (ARCH 4.1).
        ':vis_write' => Authz::canCommunityWrite($u) ? 'true' : 'false',
        ':vis_admin' => Authz::canAdmin($u) ? 'true' : 'false',
        // 팀이 없으면 NULL 이고, NULL 과의 `=` 는 참이 되지 않는다(팀 갈래가 통째로 꺼진다).
        ':vis_team'  => $u['team_id'] === null ? null : (int) $u['team_id'],
    ]];
}

/**
 * 이 페이지 아래에서 **범위가 새 값과 다른** 페이지 전부 (「하위 페이지까지 함께」, 2026-09-23 정정).
 *
 * 전에는 cascade 가 「더 넓은 하위를 좁히기」에만 쓰여, 넓힐 때 켜도 하위가 그대로였다
 * (사용자 지적 「하위까지 함께를 체크해도 적용되지 않음」). 이제 켜면 넓히든 좁히든 하위 전부가
 * 같은 값이 된다 — 전부 부모와 같으므로 「자식은 부모보다 넓을 수 없다」도 그대로 지켜진다.
 *
 * @return list<array{id:int,public_id:string,title:string,visibility:string}>
 */
function community_descendants_differ(int $pageId, string $newVisibility): array
{
    $sql = <<<'SQL'
        WITH RECURSIVE down AS (
            SELECT p.id FROM community.pages p
             WHERE p.id = CAST(:pid AS bigint) AND p.deleted_at IS NULL
            UNION ALL
            SELECT c.id FROM community.pages c
              JOIN down d ON c.parent_page_id = d.id
             WHERE c.deleted_at IS NULL
        )
        SELECT p.id, p.public_id, p.title, p.visibility
          FROM community.pages p
          JOIN down ON down.id = p.id
         WHERE p.id <> CAST(:pid2 AS bigint)
           AND p.deleted_at IS NULL
           AND p.visibility <> :vis
         ORDER BY p.id
         LIMIT 2000
        SQL;

    if (CommunityAuthz::visibilityRank($newVisibility) === null) {
        return [];
    }

    $out = [];
    foreach (Db::instance()->run($sql, [':pid' => $pageId, ':pid2' => $pageId, ':vis' => $newVisibility])->fetchAll() as $r) {
        $out[] = [
            'id'         => (int) $r['id'],
            'public_id'  => (string) $r['public_id'],
            'title'      => (string) $r['title'],
            'visibility' => (string) $r['visibility'],
        ];
    }
    return $out;
}

/**
 * 이 페이지 **아래에서 더 넓은** 페이지들 (4-B `children_wider` · `cascade`).
 *
 * 재귀 CTE **한 번**으로 후손 전부를 모으고, 새 범위보다 넓은 것만 돌려준다.
 * 자기 자신은 포함하지 않는다.
 *
 * @return list<array{id:int,public_id:string,title:string,visibility:string}>
 */
function community_descendants_wider(int $pageId, string $newVisibility): array
{
    $sql = <<<'SQL'
        WITH RECURSIVE down AS (
            SELECT p.id FROM community.pages p
             WHERE p.id = CAST(:pid AS bigint) AND p.deleted_at IS NULL
            UNION ALL
            SELECT c.id FROM community.pages c
              JOIN down d ON c.parent_page_id = d.id
             WHERE c.deleted_at IS NULL
        )
        SELECT p.id, p.public_id, p.title, p.visibility
          FROM community.pages p
          JOIN down ON down.id = p.id
         WHERE p.id <> CAST(:pid2 AS bigint)
           AND p.deleted_at IS NULL
           AND (CASE p.visibility WHEN 'private' THEN 0 WHEN 'team' THEN 1
                                  WHEN 'internal' THEN 2 WHEN 'published' THEN 3 END)
               > CAST(:rank AS int)
         ORDER BY p.id
         LIMIT 2000
        SQL;

    $rank = CommunityAuthz::visibilityRank($newVisibility);
    if ($rank === null) {
        return [];
    }

    $out = [];
    foreach (Db::instance()->run($sql, [':pid' => $pageId, ':pid2' => $pageId, ':rank' => $rank])->fetchAll() as $r) {
        $out[] = [
            'id'         => (int) $r['id'],
            'public_id'  => (string) $r['public_id'],
            'title'      => (string) $r['title'],
            'visibility' => (string) $r['visibility'],
        ];
    }
    return $out;
}

/* ══════════════════════════════════════════════════════════════════
 * 기록 (4-C) — 판 스냅샷 · 5분 합치기 · 체크 변화
 *
 * 규칙 하나: **기록은 지울 수 없다** (C:S-14). 앱 계정에는 `page_versions`·`checklist_events`
 * 에 UPDATE·DELETE 가 없고(`db/community/grants.sql`), 아래 함수들도 INSERT 와 SELECT 만 쓴다.
 * 되돌리기는 과거를 고치지 않는다 — 지금 판을 스냅샷으로 남기고 **새 판을 하나 더** 만든다.
 *
 * 시각에 대하여 — `page_versions.edited_at` 은 **그 판이 스냅샷으로 남은 시각**이다
 * (3단계부터 `DEFAULT now()` 로 채워 온 값 그대로다). 5분 합치기가 보는 값도 이것이고,
 * 이력 목록의 「시각」도 이것이다 — 「이 판이 기록에 남은 때」라는 한 가지 뜻이다.
 * 마이그레이션 `2026-09-23_community_version_squash.sql` 이 더하는 `created_at` 은 같은 뜻을
 * **자기 이름으로** 들고 있는 칸이고, 4-C 런타임은 그 칸을 읽지도 쓰지도 않는다(기본값이
 * 스스로 채운다) — 그래서 이 코드는 마이그레이션 **전후 모두 그대로 동작한다**.
 * ══════════════════════════════════════════════════════════════════ */

/**
 * 이 페이지의 **직전 스냅샷** 한 줄 + DB 의 지금 시각 (5분 합치기 판정의 재료).
 *
 * 시각 둘(`snapshot_at`·`now_at`)을 **같은 질의**에서 가져온다 — 웹 서버와 DB 의 시계가
 * 어긋나 있어도 판정이 흔들리지 않게 하려는 것이다. 질의는 `UNIQUE(page_id, version)` 이
 * 만든 btree 를 뒤로 훑는다(`Index Scan Backward`) — 같은 열 짝의 인덱스를 새로 만들지 않았다.
 *
 * @return array{version:int,edited_by:int,snapshot_at:string,now_at:string}|null 스냅샷이 없으면 null
 */
function community_last_snapshot(int $pageId): ?array
{
    $sql = <<<'SQL'
        SELECT v.version, v.edited_by, v.edited_at AS snapshot_at, now() AS now_at
          FROM community.page_versions v
         WHERE v.page_id = CAST(:pid AS bigint)
         ORDER BY v.version DESC
         LIMIT 1
        SQL;

    $row = Db::instance()->run($sql, [':pid' => $pageId])->fetch();
    if (!is_array($row)) {
        return null;
    }
    return [
        'version'     => (int) $row['version'],
        'edited_by'   => (int) $row['edited_by'],
        'snapshot_at' => (string) $row['snapshot_at'],
        'now_at'      => (string) $row['now_at'],
    ];
}

/**
 * **5분 합치기** — 이번 저장에서 스냅샷을 새로 만들지 말아야 하나 (C:F-19, C:T-38).
 *
 * 규칙 한 줄: 직전 스냅샷이 **같은 사람**이고 **5분 안**에 남은 것이면 새 스냅샷을 만들지
 * 않는다. 그 사이의 변화는 다음 스냅샷에 합쳐진다. 판 번호(`doc_version`)는 합치든 말든
 * **매번 오른다** — 낙관적 잠금이 그 번호에 걸려 있기 때문이다(번호를 멈추면 두 창이 서로를
 * 덮어쓴다).
 *
 * 왜 이렇게 나눴나: 이 함수는 **DB 를 읽지 않는다.** 재료(직전 스냅샷 한 줄)는 부르는 쪽이
 * 가져오고 여기서는 판단만 한다 — 그래야 규칙 하나를 `php -r` 한 줄로 시험할 수 있다
 * (`tests/community/stage4c.sh` 의 단위 확인, C:T-38).
 *
 * 모르면 **합치지 않는다**(스냅샷을 남긴다). 기록을 잃는 쪽보다 하나 더 남는 쪽이 낫다.
 *
 * @param array{edited_by?:mixed,snapshot_at?:mixed,now_at?:mixed}|null $last `community_last_snapshot()`
 * @param int $editorId 이번에 스냅샷으로 남을 **내용을 쓴 사람**(`pages.last_edited_by`)
 * @param int $window   합치는 창 (초). 기본 300 = 5분
 */
function community_squash_snapshot(?array $last, int $editorId, int $window = 300): bool
{
    if ($last === null || !isset($last['edited_by'], $last['snapshot_at'], $last['now_at'])) {
        return false;
    }
    if ((int) $last['edited_by'] !== $editorId) {
        return false;          // 사람이 다르면 언제나 새 스냅샷이다
    }

    $then = strtotime((string) $last['snapshot_at']);
    $now  = strtotime((string) $last['now_at']);
    if ($then === false || $now === false) {
        return false;          // 시각을 읽지 못하면 합치지 않는다
    }
    if ($then > $now) {
        return false;          // 앞뒤가 뒤집힌 값 — 모르는 상태다
    }
    return $then > $now - $window;
}

/**
 * 지금의 페이지 내용을 **스냅샷으로 남긴다** (`page_versions` INSERT 하나).
 *
 * 남는 판 번호는 **지금 판**(`doc_version`)이다 — 부르는 쪽이 곧바로 `doc_version + 1` 로
 * 올리므로, 이 행은 「방금 교체된 판」의 사본이 된다. 반드시 부르는 쪽의 트랜잭션 안에서
 * 부른다(본문 갱신과 기록이 갈라지지 않게).
 *
 * 시각 칸(`edited_at`, 그리고 마이그레이션 뒤의 `created_at`)은 **적지 않는다** — DB 의
 * `DEFAULT now()` 가 채운다. 「스냅샷이 남은 시각」은 DB 가 아는 사실이고, 웹 서버의 시계를
 * 그 자리에 적어 넣을 이유가 없다.
 *
 * @param array<string,mixed> $row     `community_page_row()` 의 행 (갱신 **전**의 값)
 * @param string|null         $summary 이 판이 어떻게 끝났나. 되돌리기면 `restore from v<번호>`
 */
function community_insert_snapshot(array $row, ?string $summary = null): void
{
    $sql = <<<'SQL'
        INSERT INTO community.page_versions (page_id, version, title, doc, edited_by, summary)
        VALUES (CAST(:pid AS bigint), CAST(:version AS int), :title, CAST(:doc AS jsonb),
                CAST(:by AS bigint), :summary)
        SQL;

    Db::instance()->run($sql, [
        ':pid'     => (int) $row['id'],
        ':version' => (int) $row['doc_version'],
        ':title'   => (string) $row['title'],
        ':doc'     => (string) $row['doc'],
        // 그 판의 **내용을 쓴 사람**이다(지금 저장 단추를 누른 사람이 아니다).
        ':by'      => (int) $row['last_edited_by'],
        ':summary' => $summary,
    ]);
}

/**
 * 체크 상자의 **변화**만 골라낸다 (C:F-20, C:T-41).
 *
 * 이전 문서의 `to_do` 상태와 견준다. 없던 블록이 **켜진 채로** 생겼으면 그것도 「켰다」로
 * 남긴다 — 지울 수 없는 기록이므로 「어느 순간 켜져 있었다」는 사실이 빠지지 않아야 한다.
 * 지워진 블록은 남기지 않는다(꺼진 것이 아니라 사라진 것이다).
 *
 * 저장(`pages_update`)과 되돌리기(`version_restore`)가 **같은 함수**를 쓴다 — 되돌리기로
 * 체크가 풀린 것도 체크 이력에 남아야 하고, 규칙이 두 벌이 되면 한쪽만 고쳐진다.
 *
 * @param  array<string,mixed> $prevDoc 바뀌기 전 문서
 * @param  array<string,mixed> $nextDoc 바뀐 뒤 문서
 * @return list<array{block_id:string,checked:bool}>
 */
function community_check_changes(array $prevDoc, array $nextDoc): array
{
    $before = [];
    foreach (Doc::extract($prevDoc)['todos'] as $t) {
        $before[$t['block_id']] = $t['checked'];
    }

    $changes = [];
    foreach (Doc::extract($nextDoc)['todos'] as $t) {
        $was = $before[$t['block_id']] ?? false;
        if ($was !== $t['checked']) {
            $changes[] = ['block_id' => $t['block_id'], 'checked' => $t['checked']];
        }
    }
    return $changes;
}

/**
 * 체크 변화를 `checklist_events` 에 **덧붙인다**(INSERT 만 — 고치지도 지우지도 않는다).
 * 부르는 쪽의 트랜잭션 안에서 부른다.
 *
 * @param list<array{block_id:string,checked:bool}> $changes
 */
function community_insert_check_events(int $pageId, array $changes, int $byUserId): void
{
    if ($changes === []) {
        return;
    }
    $sql = <<<'SQL'
        INSERT INTO community.checklist_events (page_id, block_id, checked, by_user_id)
        VALUES (CAST(:pid AS bigint), CAST(:bid AS uuid), CAST(:checked AS boolean), CAST(:by AS bigint))
        SQL;

    $db = Db::instance();
    foreach ($changes as $c) {
        $db->run($sql, [
            ':pid'     => $pageId,
            ':bid'     => $c['block_id'],
            ':checked' => $c['checked'] ? 'true' : 'false',
            ':by'      => $byUserId,
        ]);
    }
}

/**
 * 이력 창구의 **공통 관문** (4-C) — 페이지 한 건을 읽고 「볼 수 있나」까지 끝낸다.
 *
 * 왜 여기인가: `versions_list`·`version_get`·`history`·`version_restore` 넷이 **글자 그대로
 * 같은** 스무 줄로 시작한다. 그 스무 줄이 네 벌이면 언젠가 한 벌만 고쳐지고, 그 한 벌이
 * 남의 이력을 열어 준다 (C:T-42). 새 파일을 만들지 않고 모듈의 입구 파일에 둔다 —
 * `community_page_row()`·`community_rewrite_derived()` 와 같은 자리다.
 *
 * 하는 일
 *   [1] 행 + 공간 → 없으면 **404**
 *   [권한 ✔] `decidePageRead` — 지워짐 404 · 남의 개인 페이지 **403 + 감사 `page.read deny not_owner`**
 *            · 관리자·같은 팀이라서 열렸으면 그 근거를 감사에 남긴다(`pages_get.php` 와 같은 규칙)
 *   [2] 「고칠 수 있나」도 함께 판단해 돌려준다 — 화면의 「되돌리기」 단추가 그 값을 따른다
 *
 * 실패하면 **돌아오지 않는다**(응답을 내보내고 끝난다).
 *
 * @param  array<string,mixed> $u
 * @return array{row:array<string,mixed>,read_code:string,may_change:bool,change_code:string}
 */
function community_history_gate(array $u, string $publicId): array
{
    $row = community_page_row($publicId, (int) $u['id']);
    if ($row === null) {
        Http::error(404, Http::MSG_NOT_FOUND);
    }
    $pageTitle = community_target_title((string) $row['title']);

    [$mayRead, $readCode] = CommunityAuthz::decidePageRead($u, $row);
    if (!$mayRead) {
        if ($readCode === CommunityAuthz::CODE_NOT_FOUND) {
            Http::error(404, Http::MSG_NOT_FOUND);
        }
        if ($readCode === CommunityAuthz::CODE_NOT_OWNER) {
            Audit::deny('page.read', CommunityAuthz::CODE_NOT_OWNER, [
                'target_type' => 'page',
                'target_id'   => (int) $row['id'],
                'target_name' => $pageTitle,
                'detail'      => ['page_title' => $pageTitle],
            ]);
            Http::error(403, Http::MSG_FORBIDDEN);
        }
        /* 문장 틀이 없는 조합(게시판 공간·`published`·팀원이 아닌 팀 공간)이다. 판정은 이미
           「막는다」로 끝났고, 문장 없는 기록은 요청을 500 으로 끝내므로 서버 로그에만 남긴다
           (`pages_get.php` 와 같은 처리). */
        error_log('[tbox] request_id=' . TBOX_REQUEST_ID
            . ' community page.read deny code=' . $readCode . ' (문장 없는 조합, 이력)');
        Http::error(403, Http::MSG_FORBIDDEN);
    }

    /* 남의 개인 페이지를 **무엇을 근거로** 열었나 (PRD 4.1, S-07). 이력도 그 페이지의 내용에
       딸린 기록이므로 본문을 여는 것과 같은 무게로 남긴다. 자기 페이지·사내 공개는 남기지
       않는다 — 그것까지 남기면 진짜 사건이 묻힌다. */
    if ($readCode === CommunityAuthz::CODE_ADMIN_ALL || $readCode === CommunityAuthz::CODE_SAME_TEAM) {
        Audit::allow('page.read', [
            'target_type' => 'page',
            'target_id'   => (int) $row['id'],
            'target_name' => $pageTitle,
            'detail'      => ['granted_by' => $readCode, 'page_title' => $pageTitle],
        ], $readCode);
    }

    [$mayChange, $changeCode] = CommunityAuthz::decidePageChange($u, $row);

    return [
        'row'         => $row,
        'read_code'   => $readCode,
        'may_change'  => $mayChange,
        'change_code' => $changeCode,
    ];
}

/**
 * 스냅샷의 `summary` 에서 **되돌린 판 번호**를 읽는다 (4-C).
 *
 * 저장 형식은 `restore from v<번호>` 한 가지다. 이 값은 **판 N 이 끝난 사연**이므로, 화면에
 * 보일 때는 **그 다음 판(N+1)**의 줄에 「판 k 에서 되돌림」으로 붙는다 — 되돌리기로 만들어진
 * 것은 다음 판이기 때문이다(`versions_list.php`·`history.php` 가 그렇게 옮겨 붙인다).
 *
 * 모르는 글자는 `null` 이다 — 지어내지 않는다.
 */
function community_restore_source(?string $summary): ?int
{
    if ($summary === null || preg_match('/^restore from v([0-9]{1,9})$/', $summary, $m) !== 1) {
        return null;
    }
    return (int) $m[1];
}

/* ══════════════════════════════════════════════════════════════════
 * 상태 (4-D, C:F-37) · 공유와 인계 (5-A)
 *
 * 규칙 셋.
 *   ① **기록은 지울 수 없다** (C:S-14) — `page_property_events`·`handovers` 에는 앱 계정에
 *      INSERT 와 SELECT 만 있다(`db/community/grants.sql`). 아래 함수도 그 둘만 쓴다.
 *      `page_shares` 는 **바뀌는 값**이라 SIUD 이고, 바뀐 일은 감사(`page.share`)가 남긴다.
 *   ② 역할 문자열은 이 파일에 없다 — 「쓰기 자격 있는 역할」은 `community_role_sets()` 가
 *      역할마다 `Authz` 에 물어 만든다(4-D 교훈).
 *   ③ 4-D 의 담당자 후보 질의(`community_assignee_where`·`community_assignee_candidates`)는
 *      지정을 멈추면서 지웠다. 담당자 칸의 옛 값과 기록은 **읽기만** 한다.
 * ══════════════════════════════════════════════════════════════════ */

/**
 * 역할 이름마다 `Authz` 에 물어 만든 두 묶음 — 「커뮤니티에 쓸 수 있는 역할」·「관리자 역할」.
 *
 * ★ 이 파일에는 역할 문자열(`'Admin'` 같은 것)이 **한 글자도 없다** (ARCH 4.1, CLAUDE 4장
 *   금지 패턴 「역할 판단을 Authz 밖에서 직접 하는 코드」). 역할 **이름**은 DB 에 있는 것을
 *   그대로 읽고, 「그 역할이 쓸 수 있나·관리자인가」의 판단은 전부 `Authz` 가 한다.
 *
 * @return array{write:list<string>,admin:list<string>}
 */
function community_role_sets(): array
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }

    $sql = <<<'SQL'
        SELECT DISTINCT u.role FROM public.users u WHERE u.is_active = true ORDER BY u.role
        SQL;

    $write = [];
    $admin = [];
    foreach (Db::instance()->run($sql)->fetchAll() as $r) {
        $role = (string) $r['role'];
        // 판단은 Authz 가 한다 — 여기서는 답만 받아 적는다.
        if (Authz::canCommunityWrite(['role' => $role])) {
            $write[] = $role;
        }
        if (Authz::canAdmin(['role' => $role])) {
            $admin[] = $role;
        }
    }

    $cache = ['write' => $write, 'admin' => $admin];
    return $cache;
}

/**
 * 공유·인계 받는 사람 **후보**를 찾는다 (5-A `GET /api/share-candidates`).
 * 돌려주는 것은 **계정명과 역할**뿐이다(팀·활성 여부·만료일 같은 것은 고르는 데 필요 없다).
 *
 *   · 활성 계정만 — 비활성 계정에게 준 공유는 효력이 없고, 넘길 수도 없다.
 *   · `$writeOnly` 가 참이면(인계) **쓰기 자격 있는 역할만** — Guest 는 넘겨받을 수 없다.
 *     역할 목록은 `community_role_sets()` 가 `Authz` 에 물어 만든 것이고, `IN (…)` 의 자리표시자
 *     이름은 **개수로 만든 코드 문자열**(`:cw0` …)이다 — 요청 값이 SQL 에 들어가지 않는다.
 *   · `$excludeId` — 후보에서 뺄 한 사람(그 페이지의 주인. 주인에게 공유·인계는 뜻이 없다).
 *
 * @return list<array{username:string,role:string}>
 */
function community_share_candidates(string $q, int $limit, ?int $excludeId, bool $writeOnly): array
{
    $bind  = [];
    $names = [];
    foreach (community_role_sets()['write'] as $i => $role) {
        $name        = ':cw' . (string) $i;
        $names[]     = $name;
        $bind[$name] = $role;
    }
    $roleSql = $names === [] ? 'false' : 'u.role IN (' . implode(', ', $names) . ')';

    // `%`·`_`·`#` 은 ILIKE 의 뜻 있는 글자다. `#` 로 이스케이프하고 값은 바인딩한다 (PRD S-04).
    $like = $q === '' ? '' : '%' . str_replace(['#', '%', '_'], ['##', '#%', '#_'], $q) . '%';

    $sql = <<<SQL
        SELECT u.username, u.role
          FROM public.users u
         WHERE u.is_active = true
           AND (CAST(:qoff AS boolean) OR u.username ILIKE :like ESCAPE '#')
           AND u.id IS DISTINCT FROM CAST(:exclude AS bigint)
           AND (NOT CAST(:write_only AS boolean) OR {$roleSql})
         ORDER BY u.username
         LIMIT CAST(:lim AS int)
        SQL;

    $out = [];
    foreach (Db::instance()->run($sql, array_merge($bind, [
        ':qoff'       => $q === '' ? 'true' : 'false',
        ':like'       => $like,
        ':exclude'    => $excludeId,
        ':write_only' => $writeOnly ? 'true' : 'false',
        ':lim'        => $limit,
    ]))->fetchAll() as $r) {
        $out[] = ['username' => (string) $r['username'], 'role' => (string) $r['role']];
    }
    return $out;
}

/**
 * 계정명 하나로 사람을 찾는다 (5-A 공유·인계 대상). 없으면 `null`.
 *
 * 비활성 계정도 **행을 돌려준다** — 「없는 사람」과 「내려간 계정」을 가르는 것은 판정 함수
 * (`CommunityAuthz::decideShareTarget`·`decideHandoverTarget`)의 몫이다.
 *
 * @return array<string,mixed>|null
 */
function community_user_by_name(string $username): ?array
{
    $sql = <<<'SQL'
        SELECT u.id, u.username, u.role, u.team_id, u.is_active
          FROM public.users u
         WHERE u.username = :name
        SQL;

    $row = Db::instance()->run($sql, [':name' => $username])->fetch();
    return is_array($row) ? $row : null;
}

/**
 * 한 페이지의 공유 줄 — **이 페이지에 직접 준 것**과 **조상에게서 물려받은 것** (5-A).
 *
 * 조상 쪽은 `community_share_rank_sql()` 과 같은 길(같은 공간·살아 있는 조상)로 오른다.
 * 비활성 계정의 줄도 돌려준다 — 화면이 「(내려간 계정)」이라고 보이고 뺄 수 있어야 한다.
 * 효력이 없다는 사실은 판정이 이미 알고 있다(`su.is_active = true`).
 *
 * @return list<array{username:string,role:string,is_active:bool,level:string,granted_by:?string,
 *                    created_at:string,inherited:bool,from_id:?string,from_title:?string}>
 */
function community_page_shares(int $pageId): array
{
    $sql = <<<'SQL'
        WITH RECURSIVE up(id, parent_page_id, space_id, lvl) AS (
            SELECT a.id, a.parent_page_id, a.space_id, 0
              FROM community.pages a WHERE a.id = CAST(:pid AS bigint)
            UNION ALL
            SELECT b.id, b.parent_page_id, b.space_id, up.lvl + 1
              FROM community.pages b JOIN up ON b.id = up.parent_page_id
             WHERE b.space_id = up.space_id AND b.deleted_at IS NULL AND up.lvl < 16
        )
        SELECT u.username, u.role, u.is_active, sh.level, gb.username AS granted_by_name,
               sh.created_at, up.lvl, src.public_id AS from_id, src.title AS from_title
          FROM up
          JOIN community.page_shares sh ON sh.page_id = up.id
          JOIN public.users u ON u.id = sh.user_id
          JOIN community.pages src ON src.id = up.id
          LEFT JOIN public.users gb ON gb.id = sh.granted_by
         ORDER BY up.lvl ASC, u.username ASC
         LIMIT 500
        SQL;

    $out = [];
    foreach (Db::instance()->run($sql, [':pid' => $pageId])->fetchAll() as $r) {
        $inherited = (int) $r['lvl'] > 0;
        $out[] = [
            'username'   => (string) $r['username'],
            'role'       => (string) $r['role'],
            'is_active'  => $r['is_active'] === true,
            'level'      => (string) $r['level'],
            'granted_by' => $r['granted_by_name'] === null ? null : (string) $r['granted_by_name'],
            'created_at' => (string) $r['created_at'],
            'inherited'  => $inherited,
            'from_id'    => $inherited ? (string) $r['from_id'] : null,
            'from_title' => $inherited ? (string) $r['from_title'] : null,
        ];
    }
    return $out;
}

/**
 * 이 페이지와 **살아 있는 하위 전부**의 `pages.id` (5-A 인계 — 무엇이 옮겨 가나).
 * 재귀 CTE 한 번이고, 자기 자신이 맨 앞이다. 지워진 하위는 옮기지 않는다 — 옛 공간의
 * 휴지통에 남아, 지운 사람이 거기서 되살린다(되살리면 부모가 없어 뿌리로 올라간다, 4-C).
 *
 * @return list<int>
 */
function community_subtree_live_ids(int $pageId): array
{
    $sql = <<<'SQL'
        WITH RECURSIVE down AS (
            SELECT p.id, 0 AS lvl FROM community.pages p
             WHERE p.id = CAST(:pid AS bigint) AND p.deleted_at IS NULL
            UNION ALL
            SELECT c.id, d.lvl + 1 FROM community.pages c
              JOIN down d ON c.parent_page_id = d.id
             WHERE c.deleted_at IS NULL AND d.lvl < 16
        )
        SELECT id FROM down ORDER BY lvl, id LIMIT 5000
        SQL;

    $ids = [];
    foreach (Db::instance()->run($sql, [':pid' => $pageId])->fetchAll() as $r) {
        $ids[] = (int) $r['id'];
    }
    return $ids;
}

/**
 * 이 사람의 개인 공간 **그릇 한 줄**을 만든다 — 이미 있으면 아무것도 하지 않는다 (5-A 로 뽑아냈다).
 *
 * `GET /api/me/space`(첫 방문)와 인계(받는 사람에게 아직 공간이 없을 때)가 **같은 문장**을 쓴다 —
 * 공간 이름 규칙(계정명 40자 + 「 의 공간」)과 초기값이 두 벌이 되지 않게 하려는 것이다.
 * 동시에 두 요청이 만들려 하면 부분 UNIQUE 인덱스(`spaces_personal_owner_uidx`)가 한쪽을
 * 걸러 낸다(`ON CONFLICT … DO NOTHING`) — 그때는 `null` 을 돌려주고, 부르는 쪽이 다시 읽는다.
 * 반드시 부르는 쪽의 트랜잭션 안에서 부른다.
 *
 * @return array{id:int,name:string,kind:string,created_at:string}|null 새로 만들었으면 그 줄, 이미 있었으면 null
 */
function community_create_personal_space(int $ownerId, string $ownerName, int $byUserId): ?array
{
    // 표의 상한이 50자라 앞 40자만 쓴다 — 여기서 자르지 않으면 이름이 긴 계정이 DB 제약에 걸린다.
    if (iconv_strlen($ownerName, 'UTF-8') > 40) {
        $ownerName = (string) iconv_substr($ownerName, 0, 40, 'UTF-8');
    }
    $sql = <<<'SQL'
        INSERT INTO community.spaces (kind, owner_user_id, name, description, access_level, created_by)
        VALUES ('personal', CAST(:uid AS bigint), :name, :description, 'admin-only', CAST(:by AS bigint))
        ON CONFLICT (owner_user_id) WHERE kind = 'personal' DO NOTHING
        RETURNING id, name, kind, created_at
        SQL;

    $row = Db::instance()->run($sql, [
        ':uid'         => $ownerId,
        ':name'        => $ownerName . ' 의 공간',
        ':description' => '나만 보는 개인 업무 공간입니다.',
        ':by'          => $byUserId,
    ])->fetch();
    if (!is_array($row)) {
        return null;
    }
    return [
        'id'         => (int) $row['id'],
        'name'       => (string) $row['name'],
        'kind'       => (string) $row['kind'],
        'created_at' => (string) $row['created_at'],
    ];
}

/**
 * 상태가 바뀐 일을 **지울 수 없는 줄 하나**로 남긴다 (4-D, C:S-14). 5-A 부터 담당자 줄은 새로 생기지 않는다.
 *
 * 반드시 부르는 쪽의 트랜잭션 안에서 부른다 — `pages` 갱신과 기록이 갈라지지 않게.
 * `INSERT` 하나뿐이고, 앱 계정에는 이 표에 UPDATE·DELETE 가 없다.
 */
function community_insert_property_event(
    int $pageId,
    string $prop,
    ?string $from,
    ?string $to,
    int $byUserId
): void {
    $sql = <<<'SQL'
        INSERT INTO community.page_property_events (page_id, prop, from_value, to_value, by_user_id)
        VALUES (CAST(:pid AS bigint), :prop, :from_value, :to_value, CAST(:by AS bigint))
        SQL;

    Db::instance()->run($sql, [
        ':pid'        => $pageId,
        ':prop'       => $prop,
        ':from_value' => $from,
        ':to_value'   => $to,
        ':by'         => $byUserId,
    ]);
}

/**
 * 커뮤니티 요청 하나를 처리한다. 돌아오지 않는다(언제나 응답을 내보내고 끝난다).
 *
 * @param string $modulePath `/community` 를 뗀 경로 (`/`, `/api/pages`, `/p/<uuid>` …)
 * @param string $method     요청 메서드
 */
function community_dispatch(string $modulePath, string $method): void
{
    // ─── [0-1] 라우트 표 자체 검사 ─────────────────────────────
    $problems = community_validate_routes();
    if ($problems !== []) {
        error_log('[tbox] request_id=' . TBOX_REQUEST_ID
            . ' 커뮤니티 라우트 표가 올바르지 않아 요청을 처리하지 않습니다: ' . implode(' | ', $problems));
        Http::error(500, '서버 오류');
    }

    // ─── [1] 경로 매칭 ─────────────────────────────────────────
    $matched        = null;
    $pathMatched    = false;
    $allowedMethods = [];

    foreach (COMMUNITY_ROUTES as $route) {
        [$routeMethod, $path, $handler, $policy] = $route;
        if (preg_match(community_pattern_to_regex($path), $modulePath, $m) !== 1) {
            continue;
        }
        $pathMatched      = true;
        $allowedMethods[] = $routeMethod;
        if ($routeMethod !== $method) {
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

    /* 감사 로그의 `detail.route` — **전체 경로의 라우트 패턴**이다(`GET /community/api/pages/{id}`).
       실제 UUID 가 아니라 패턴을 적는 것은 t-box 와 같고, 접두를 붙이는 것은 런처 설계 2장의
       「감사에는 전체 경로를 그대로 적는다」 규칙이다. 인증 검사보다 먼저 정의한다 —
       401·403 으로 끝나는 행에도 경로가 남아야 한다. */
    define('TBOX_ROUTE', $method . ' ' . COMMUNITY_BASE . $routePattern);

    $isApi = strpos($modulePath, '/api/') === 0;

    // ─── [2] 인증 ─────────────────────────────────────────────
    // 화면 요청은 t-box 로그인 화면으로 보내고 돌아올 곳을 꼬리표로 남긴다 (ARCH 21.2).
    // 꼬리표 값은 **코드 상수**이고 요청에서 온 문자열이 아니다 — 오픈 리다이렉트가 생길 자리가 없다.
    if (Auth::currentUser() === null && !$isApi) {
        Http::redirect(Http::url(TBOX_LOGIN_PATH) . '?to=' . COMMUNITY_LOGIN_TO);
    }
    $user = Auth::requireLogin();          // 실패 시 401 + Audit::deny (Auth 안에서)

    // ─── [3] CSRF ─────────────────────────────────────────────
    Http::requireCsrf();

    // ─── [3-1] 최초 로그인 강제 절차 관문 ──────────────────────
    // 커뮤니티에는 허용 목록이 **없다**. 비밀번호 변경·2단계 인증 등록은 t-box 쪽 창구에서만
    // 할 수 있으므로, 절차가 남은 계정에게 커뮤니티는 통째로 닫혀 있다(화면은 t-box 홈으로).
    $gateNext = Auth::nextStep($user);
    if ($gateNext !== null) {
        Audit::deny('auth.gate', $gateNext === 'password_change' ? 'password_change_required' : 'mfa_setup_required', [
            'target_type' => 'user',
            'target_id'   => (int) $user['id'],
            'target_name' => (string) $user['username'],
            'detail'      => ['next' => $gateNext],
        ]);
        if (!$isApi) {
            Http::redirect(Http::url(TBOX_HOME_PATH));
        }
        Http::json([
            'error' => $gateNext === 'password_change'
                ? Http::MSG_PASSWORD_CHANGE_REQUIRED
                : Http::MSG_MFA_SETUP_REQUIRED,
            'next'  => $gateNext,
        ], 403);
        exit;
    }

    // ─── [4] 인가 ─────────────────────────────────────────────
    if (!Authz::authorize($policy, $user)) {
        Audit::deny('authz.route', 'role_not_permitted', [
            'target_type' => 'system',
            'target_name' => TBOX_ROUTE,
            'detail'      => ['policy' => $policy],
        ]);
        Http::error(403, Http::MSG_FORBIDDEN);
    }

    // 관리 성격의 커뮤니티 정책이 생기면 `Authz::REAUTH_POLICIES` 에 이름을 넣는 것만으로
    // 재인증이 따라붙는다. 지금 정책 둘은 그 목록에 없으므로 이 가지는 지나간다.
    if (Authz::requiresReauth($policy) && ($user['reauth_fresh'] ?? false) !== true) {
        Audit::deny('auth.gate', 'reauth_required', [
            'target_type' => 'user',
            'target_id'   => (int) $user['id'],
            'target_name' => (string) $user['username'],
            'detail'      => ['next' => 'reauth', 'policy' => $policy],
        ]);
        if (!$isApi) {
            Http::redirect(Http::url(TBOX_REAUTH_PATH));
        }
        Http::json(['error' => Http::MSG_REAUTH_REQUIRED, 'next' => 'reauth'], 403);
        exit;
    }

    // ─── [4-1] 스키마 준비 확인 ────────────────────────────────
    // 인가 **뒤**에 둔다 — 로그인하지 않은 사람에게 「준비 중」을 알려 줄 이유가 없고,
    // 미로그인 302·401 이 이 안내보다 앞서야 한다(완료 기준 6의 실측 순서).
    if (!community_schema_ready()) {
        community_not_ready($isApi);
    }

    // ─── [5] 핸들러 실행 ───────────────────────────────────────
    // 파일명은 라우트 표에 적힌 고정 문자열이고 위 검사에서 형태와 존재를 확인했다.
    (static function (array $user, array $params, string $file): void {
        require $file;
    })($user, $params, COMMUNITY_HANDLER_DIR . $handlerFile);
    exit;
}
