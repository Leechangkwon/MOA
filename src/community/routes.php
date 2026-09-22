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
    ['POST',   '/api/pages',                 'pages_create.php',    'community.write'],
    ['GET',    '/api/pages/{id}',            'pages_get.php',       'community.read'],
    ['PATCH',  '/api/pages/{id}',            'pages_update.php',    'community.write'],
    // 트리에서 자리를 바꾼다 — 부모 갈아 끼우기와 형제 순서가 같은 창구다 (4-A).
    ['PATCH',  '/api/pages/{id}/move',       'pages_move.php',      'community.write'],
    // 공개 범위 바꾸기 — 소유자만. 트리 불변 규칙(부모보다 넓을 수 없다)은 여기서 갈린다 (4-B).
    ['PATCH',  '/api/pages/{id}/visibility',  'pages_visibility.php', 'community.write'],
    ['DELETE', '/api/pages/{id}',            'pages_delete.php',    'community.write'],
    ['POST',   '/api/pages/{id}/heartbeat',  'pages_heartbeat.php', 'community.write'],
];

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
 * 페이지 한 건 + 그 공간을 **한 번에** 읽는다 (ARCH 13.2 가 판정에 요구하는 칸을 전부 싣는다).
 *
 * 지워진 페이지도 돌려준다 — 「없음(404)」과 「지워짐」을 가르는 것은 판정 함수의 몫이다.
 *
 * @return array<string,mixed>|null 없으면 null
 */
function community_page_row(string $publicId): ?array
{
    $sql = <<<'SQL'
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
               owner.team_id   AS space_owner_team_id
          FROM community.pages p
          JOIN community.spaces s ON s.id = p.space_id
          LEFT JOIN public.users owner ON owner.id = s.owner_user_id
         WHERE p.public_id = CAST(:pid AS uuid)
        SQL;

    $row = Db::instance()->run($sql, [':pid' => $publicId])->fetch();
    return is_array($row) ? $row : null;
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
 * 볼 수 있는 것만: 조상은 전부 **같은 개인 공간**의 행이라 페이지 자체와 판정이 같다.
 * 그래서 조상마다 다시 판정하지 않고 같은 공간인 것만 CTE 안에서 확인한다.
 *
 * @return list<array{id:string,title:string,icon:?string}>
 */
function community_breadcrumb(int $pageId, int $spaceId): array
{
    $sql = <<<'SQL'
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
        SELECT public_id, title, icon FROM up ORDER BY lvl DESC
        SQL;

    $out = [];
    foreach (Db::instance()->run($sql, [':pid' => $pageId, ':space' => $spaceId, ':space2' => $spaceId])->fetchAll() as $r) {
        $out[] = [
            'id'    => (string) $r['public_id'],
            'title' => (string) $r['title'],
            'icon'  => $r['icon'] === null ? null : (string) $r['icon'],
        ];
    }
    return $out;
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
 *   내 개인 공간 전부 / `internal` + 쓰기 자격 / `team` + 주인과 같은 팀 / Admin / 내 팀 공간.
 * `published` 는 어느 갈래에도 없다 — 5단계까지는 목록에도 없다.
 *
 * @param  array<string,mixed> $u
 * @return array{0:string,1:array<string,mixed>}
 */
function community_visible_pages_where(array $u): array
{
    $sql = <<<'SQL'
(
      (s.kind = 'personal' AND s.owner_user_id = CAST(:vis_me AS bigint))
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
        // 「쓰기 자격이 있는 역할인가」·「Admin 인가」의 판단은 `Authz` 가 한다 — 이 파일에
        // 역할 문자열 비교가 생기지 않게 참·거짓만 받아 바인딩한다 (ARCH 4.1).
        ':vis_write' => Authz::canCommunityWrite($u) ? 'true' : 'false',
        ':vis_admin' => Authz::canAdmin($u) ? 'true' : 'false',
        // 팀이 없으면 NULL 이고, NULL 과의 `=` 는 참이 되지 않는다(팀 갈래가 통째로 꺼진다).
        ':vis_team'  => $u['team_id'] === null ? null : (int) $u['team_id'],
    ]];
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
        if (preg_match(tbox_pattern_to_regex($path), $modulePath, $m) !== 1) {
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
