<?php
declare(strict_types=1);

/**
 * src/community/handlers/pages_create.php — POST /community/api/pages (정책: community.write)
 *
 * 요구사항: `C:F-15`·`C:F-17` 최소(페이지 만들기), 기획 ③(새 페이지는 **비공개**로 시작),
 *           `../../../../community/ARCHITECTURE.md` 13.3 · 13.4 · 21.3.
 *
 * 받는 값 (JSON, **허용 키 목록 밖의 키가 하나라도 있으면 400** — 대량 할당 차단)
 *   title     제목 (선택). 없으면 「제목 없는 페이지」. trim 뒤 1~200자, 줄바꿈·제어문자 금지
 *   doc       첫 본문 (선택). 없으면 빈 문단 하나. 있으면 `Doc::validate` 를 그대로 지난다
 *   parent_id 부모 페이지 (선택, UUID). **그 공간 안**의 살아 있는 페이지여야 한다
 *   space_id  공간 번호 (선택, 4-B). 없으면 **내 개인 공간**. 팀 공간이면 그 팀원만
 *   database  `true` 면 **데이터베이스 페이지**로 만든다(6-A — `databases` 한 줄이 함께 생긴다). DB 페이지 아래에는 못 만든다
 *   properties 행의 속성 값(6-A, 선택). **부모가 DB 페이지일 때만** — 「DB 페이지 바로 아래 페이지 = 행」이다
 *   preset    Q:F-12 (ARCH 23.5) `"project"` 하나 — `database:true` 와 함께만. 서버 상수(`DbProps::PROJECT_*`)가 섹션 select 칸 ·
 *             기본 보기 넷 · 색(그 공간에서 내가 볼 수 있는 프로젝트 수 차례) · `purpose='project'` 를 채운다. 칸·보기 모양은 요청에서 받지 않는다
 *   my_tasks  Q:F-12 (ARCH 23.5) `true` — 「내 할 일」 빠른 추가. 함께 줄 수 있는 키는 `title` · `due`(Y-m-d 또는 null) 뿐이다.
 *             처음이면 내 개인 공간 최상위에 **비공개** 프로젝트 「내 할 일」(`purpose='my_tasks'`)을 만들어 `my_tasks_projects` 에 적고
 *             (두 요청이 겹치면 한쪽은 PK 충돌 → 자기 것을 되돌리고 다시 읽는다), 그 프로젝트의 **행**으로 만든다(상태 `todo` · 기한).
 *             프로젝트를 휴지통에 넣었거나 내 개인 공간 밖으로 옮겼으면 적어 둔 줄만 지우고 새로 만든다
 *   template_id 템플릿(7-B, 선택, UUID). 제목(주지 않으면)·아이콘·본문을 템플릿으로 채운다 — `doc`·`database` 와 함께 주면 400.
 *             DB 행 템플릿이면 부모는 **그 DB 페이지**다(주지 않으면 그리로, 다른 부모면 400) · 속성 값은 템플릿 값 위에 요청 값.
 *             ★ 템플릿은 **자리와 범위를 정하지 않는다** — 아래 규칙 그대로(부모가 없으면 내 개인 공간 · 비공개).
 *             남의 템플릿은 403 + `template.read deny`(`CommunityAuthz::decideTemplateRead`)
 *
 * ★ `visibility` 는 **허용 키가 아니다**(오면 400). 새 페이지의 범위는 요청이 정하지 않는다
 *   (4-B 지시서): 부모가 있으면 **부모와 같게**, 없으면 `private` 이다. 부모와 같게 시작해야
 *   트리 불변 규칙(자식 ≤ 부모)이 만들어지는 순간부터 성립하고, 뿌리는 가장 좁은 값에서
 *   시작해야 실수로 열린 페이지가 생기지 않는다. 넓히는 일은 `PATCH …/visibility` 한 곳이고
 *   거기에 규칙 검사와 감사가 붙어 있다.
 *   팀 공간의 페이지는 자기 범위가 없다(공간 등급을 따른다) — 칸은 `private` 고정 문자열로
 *   채우되 **판정은 공간으로** 한다 (`CommunityAuthz::decidePageRead` 의 `team` 갈래).
 *
 * ★ 프로그램 토큰으로 들어온 요청(Q:F-8 — 라우터 [2-T] 가 `TBOX_AUTH_METHOD` 를 세운다, ARCH 4.8)은 허용 키가
 *   **`title` · `doc` 둘뿐**이다. `parent_id`·`space_id`·`database`·`properties`·`template_id` 가 **있으면**(null 이어도)
 *   400 `token_scope_fields` — 그래서 토큰으로 만든 페이지는 언제나 **내 개인 공간 · 비공개 · 부모 없음**이다.
 *   나머지 검사(제목·본문·임베드·팔레트·개인 공간 없으면 409)와 응답 모양은 세션과 같다.
 *
 * 검사 순서
 *   [1] 허용 키 (토큰이면 [1-1] 다섯 키 거부) → [2] 제목 규칙 → [3] 본문 검사 → [4] 부모 → [5] 공간(부모의 공간, 없으면 내 개인 공간)
 *   → [권한 ✔] 부모가 있으면 부모 행의 `decidePageChange`(5-A — 편집 공유자도 된다), 없으면 공간 →
 *   [6] 트랜잭션(INSERT + 파생 표 + 감사 `page.create allow`) → 201
 *
 * 왜 형식 거부에 감사가 없나: 여기서 나오는 400 은 전부 **요청 형식**의 문제다(허용 키 밖·
 * 제목 길이·본문 스키마·깊이 초과). t-box 도 `Http::jsonBody()` 의 형식 오류를 감사에 남기지
 * 않는다 — 남기는 것은 「권한으로 막은 일」이다. 대상 공간은 요청이 고르지 못하고 **언제나
 * 세션 본인의 개인 공간**이다.
 * 단 하나 감사가 남는 자리는 **남의 페이지를 부모로 준 경우**다(4-A) — 그것은 형식이 아니라
 * 권한으로 막은 일이라 `page.read deny not_owner` 한 줄이 남는다.
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params 경로 변수 없음
 */

$allowedFields = ['title', 'doc', 'parent_id', 'space_id', 'database', 'properties', 'template_id', 'preset', 'my_tasks', 'due'];

/*
 * 본문 상한 (관문 ② 실측 2026-09-22) — `Http::jsonBody()` 의 기본 상한은 64KB 인데 문서 스키마는
 * 1MB 까지 받는다. 그대로 두면 **스키마는 받아 주는데 입구가 막는** 모순이 생겨 긴 노트 하나에
 * 저장이 멈춘다. 그래서 이 창구만 「스키마 상한 + 껍데기 여유」를 넘긴다 —
 * 여유분은 제목·판 번호·JSON 껍데기·이스케이프가 차지하는 몫이다.
 * 넘으면 지금과 같은 400 이고, 상한 자체를 없애지는 않는다(없으면 메모리가 요청을 따라간다).
 */
$maxBody = Doc::limit('doc_bytes') + 65536;

/*
 * 깊이 상한도 같은 이유로 넓힌다. `json_decode` 의 깊이는 「가장 깊은 값까지의 칸 수 + 1」이고,
 * 우리 문서에서 가장 깊은 자리는 `blocks[…]{children}` 를 `depth` 번 겹친 뒤의
 * `text[…].m.link.href` 다: 2 × depth + 7. 기본값 8 로 두면 **링크 마크 하나만 있어도**
 * 저장이 400 이 된다(관문 ② 실측).
 * 스키마 한도보다 **한 겹 더** 받는다(`depth + 1`). 입구는 메모리를 지키는 자리이고,
 * 「너무 깊다」는 **판단**은 검증기가 해야 하기 때문이다 — 한 겹 넘친 문서가 입구에서 잘리면
 * 사용자는 이유 없는 400 을 보지만, 검증기까지 닿으면 `blocks[…]: too_deep` 과 감사 한 줄이
 * 남는다. 두 겹 넘는 문서는 그래도 입구가 막는다(그쯤이면 메모리를 지키는 것이 먼저다).
 */
$maxDepth = 2 * (Doc::limit('depth') + 1) + 7;

$body = Http::jsonBody($maxBody, $maxDepth);
if (array_diff(array_keys($body), $allowedFields) !== []) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}

// ─── [1-1] 토큰 모드 (Q:F-8, ARCH 4.8) — 자리를 고르는 키는 받지 않는다 ─────────
//        판단 근거는 라우터가 세운 상수(요청 값이 아니다). 키가 **있기만** 해도(값이 null 이어도) 거부한다.
if (defined('TBOX_AUTH_METHOD') && constant('TBOX_AUTH_METHOD') === 'api_token'
    && array_intersect(array_keys($body), ['parent_id', 'space_id', 'database', 'properties', 'template_id', 'preset', 'my_tasks', 'due']) !== []
) {
    Http::json([
        'error'  => '프로그램 토큰으로는 제목과 본문만 보냅니다 — 새 페이지는 내 개인 공간에 비공개로 만들어집니다',
        'reason' => 'token_scope_fields',
    ], 400);
    exit;
}

// 6-A — DB 로 만들기(참·거짓만)
$makeDb = false;
if (array_key_exists('database', $body)) {
    if (!is_bool($body['database'])) {
        Http::error(400, Http::MSG_BAD_REQUEST);
    }
    $makeDb = $body['database'];
}

// Q:F-12 (ARCH 23.5) — 프로젝트 만들기는 preset 이름 하나만 받는다(칸·보기·색은 서버 상수). DB 로 만들 때만.
$preset = null;
if (array_key_exists('preset', $body)) {
    if ($body['preset'] !== 'project' || !$makeDb) {
        Http::error(400, Http::MSG_BAD_REQUEST);
    }
    $preset = 'project';
}
// Q:F-12 — 「내 할 일」 빠른 추가: 함께 오는 키는 제목·기한뿐이다(자리·범위·속성은 요청이 정하지 않는다)
$myTasks = false;
if (array_key_exists('my_tasks', $body)) {
    if ($body['my_tasks'] !== true || array_diff(array_keys($body), ['my_tasks', 'title', 'due']) !== []) {
        Http::error(400, Http::MSG_BAD_REQUEST);
    }
    $myTasks = true;
}
$wantDue = null;
if (array_key_exists('due', $body)) {
    if (!$myTasks || ($body['due'] !== null && DbViews::day($body['due']) === null)) {
        Http::error(400, Http::MSG_BAD_REQUEST);
    }
    $wantDue = $body['due'];
}

// ─── [1-2] 템플릿 (7-B) — 본문을 채울 틀. 판정을 먼저 지나야 제목·본문을 읽는다 ─────────
$tpl       = null;
$tplDbPage = null;
if (array_key_exists('template_id', $body) && $body['template_id'] !== null) {
    $rawTpl = $body['template_id'];
    if (!is_string($rawTpl)
        || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $rawTpl) !== 1
        || array_key_exists('doc', $body) || $makeDb
    ) {
        Http::error(400, Http::MSG_BAD_REQUEST);
    }
    $tpl = community_template_row($rawTpl);
    if ($tpl === null || $tpl['deleted_at'] !== null) {
        Http::error(404, Http::MSG_NOT_FOUND);
    }
    $tplDbPage = community_template_db_page($tpl, (int) $user['id']);
    [$mayTpl, $tplCode] = CommunityAuthz::decideTemplateRead($user, $tpl, $tplDbPage);
    if (!$mayTpl) {
        if ($tplCode === CommunityAuthz::CODE_NOT_FOUND) {
            Http::error(404, Http::MSG_NOT_FOUND);
        }
        community_template_refuse($tpl, $tplCode);
    }
    // DB 행 템플릿 — 부모는 그 DB 페이지 하나다
    if ((string) $tpl['scope'] === 'database') {
        $givenParent = $body['parent_id'] ?? null;
        $givenSpace  = $body['space_id'] ?? null;
        if (($givenParent !== null && $givenParent !== (string) $tplDbPage['public_id'])
            || ($givenSpace !== null && (string) $givenSpace !== (string) $tplDbPage['space_id'])
        ) {
            // 다른 부모·다른 공간 — 판정 전에 모양으로 끊는다(공간 불일치 403 이 「남의 페이지」 감사로 남지 않게 — 독립 검토 L-2)
            Http::json(['error' => '이 템플릿은 그 데이터베이스의 행으로만 만듭니다', 'reason' => 'template_db_mismatch'], 400);
            exit;
        }
        $body['parent_id'] = (string) $tplDbPage['public_id'];
    }
}

// ─── [2] 제목 ──────────────────────────────────────────────────
$title = $makeDb ? '제목 없는 데이터베이스' : ($tpl !== null ? (string) $tpl['title'] : '제목 없는 페이지');
if (array_key_exists('title', $body)) {
    $rawTitle = $body['title'];
    if (!is_string($rawTitle) || preg_match('//u', $rawTitle) !== 1) {
        Http::error(400, Http::MSG_BAD_REQUEST);
    }
    $title = trim($rawTitle);
    if ($title === ''
        || iconv_strlen($title, 'UTF-8') > 200
        || preg_match('/\p{C}/u', $title) === 1
    ) {
        Http::error(400, Http::MSG_BAD_REQUEST);
    }
}

// ─── [3] 본문 — 없으면 빈 문단 하나로 시작한다 ─────────────────
$doc = ['schema' => 1, 'blocks' => [['id' => Doc::uuid4(), 'type' => 'paragraph', 'text' => []]]];
if ($tpl !== null) {
    /* 7-B — 블록 id 는 **페이지마다 새로**(`Doc::freshIds`). 템플릿이 저장된 뒤 규칙이 바뀌었을 수 있으니
       지금 단계의 검사를 다시 지난다 — 어긋나면 400(템플릿을 고쳐 저장해야 한다). */
    $tplDoc = is_string($tpl['doc']) ? json_decode((string) $tpl['doc'], true) : $tpl['doc'];
    $doc    = Doc::freshIds(is_array($tplDoc) ? $tplDoc : []);
    if (!Doc::validate($doc, COMMUNITY_DOC_STAGE)['ok']) {
        Http::json(['error' => '이 템플릿은 지금 규칙에 맞지 않아 쓸 수 없습니다', 'reason' => 'template_outdated'], 400);
        exit;
    }
}
if (array_key_exists('doc', $body)) {
    if (!is_array($body['doc'])) {
        Http::error(400, Http::MSG_BAD_REQUEST);
    }
    $doc   = $body['doc'];
    $check = Doc::validate($doc, COMMUNITY_DOC_STAGE);
    if (!$check['ok']) {
        // 어느 자리가 왜 어긋났는지 그대로 돌려준다 — 에디터가 고칠 수 있는 말이다.
        Http::json(['error' => Http::MSG_BAD_REQUEST, 'errors' => $check['errors']], 400);
        exit;
    }
}

if (!community_buttons_ok($doc, [], '', $user)) { Http::json(['error'=>'페이지를 만든 뒤 버튼을 붙입니다.', 'reason'=>'button_not_allowed'],400); exit; }
$extract = Doc::extract($doc);
/* 5-E — 첫 본문에 t-box 파일이 있으면 붙이는 것과 같은 규칙이다. 새 페이지의 범위는 아래에서 정해지므로(부모와 같게·비공개)
   여기서는 **가장 좁은 쪽**(비공개 — 범위 검사 없음)이 아니라 부모의 범위로 견줄 수 없어, 파일이 든 첫 본문은 받지 않는다. */
if ($extract['files'] !== []) {
    Http::json(['error' => '파일은 페이지를 만든 뒤 붙입니다', 'reason' => 'file_ref'], 400);
    exit;
}
/* 7-C — 첫 본문의 임베드는 모두 새것이다 — 허용 목록 안이어야 한다(K-3). 템플릿 본문은 **담을 때** 이미 견주었으므로
   그 뒤에 호스트가 빠져도 막지 않는다(옛 임베드와 같다 — 렌더러가 「허용 목록 밖」 카드로 그린다, 독립 검토 7-C L-1). */
if ($tpl === null && !community_embeds_ok($extract['embeds'])) {
    Http::json(['error' => COMMUNITY_EMBED_REFUSED, 'reason' => 'embed_not_allowed'], 400);
    exit;
}
/* 7-D2 — 첫 본문의 팔레트 이름도 모두 새것이다 — 살아 있는 목록 안이어야 한다(P-9). 템플릿 본문은 **담을 때** 견주었으므로
   그 뒤에 뺀 이름이 있어도 막지 않는다(K-7 — 옛 글과 같다 · 색 없이 그려진다). */
if ($tpl === null && !community_palettes_ok($extract['palette_names'])) {
    Http::json(['error' => COMMUNITY_PALETTE_REFUSED, 'reason' => 'palette_not_found'], 400);
    exit;
}
$docJson = json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($docJson === false) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}

$db = Db::instance();
$me = (int) $user['id'];

// ─── [3-1] Q:F-12 「내 할 일」 — 프로젝트를 찾거나(없으면 만들고) 그 DB 페이지를 부모로 둔다 ─────────
/* 만든 뒤의 행 만들기는 아래 [4]~[6] 의 보통 길 그대로다(부모 행의 `decidePageChange` · 행 한도 · 감사) — 판정 두 벌 0.
   프로젝트 만들기 판정도 「내 개인 공간 최상위에 만들기」와 같은 `decidePageChange`(공간 주인)다. */
$myTasksInfo = null;
if ($myTasks) {
    $readMyTasks = static function (int $uid): ?array {
        $sql = <<<'SQL'
            SELECT m.page_id, p.public_id, p.deleted_at, s.kind AS space_kind, s.owner_user_id, d.purpose
              FROM community.my_tasks_projects m
              JOIN community.pages p ON p.id = m.page_id
              JOIN community.spaces s ON s.id = p.space_id
              LEFT JOIN community.databases d ON d.page_id = p.id
             WHERE m.user_id = CAST(:uid AS bigint)
            SQL;
        $r = Db::instance()->run($sql, [':uid' => $uid])->fetch();
        return is_array($r) ? $r : null;
    };
    $usable = static fn (?array $r): bool => $r !== null && $r['deleted_at'] === null && (string) $r['space_kind'] === 'personal'
        && (int) $r['owner_user_id'] === $me && $r['purpose'] === 'my_tasks';

    $mt = $readMyTasks($me);
    if ($mt !== null && !$usable($mt)) {
        // 휴지통·인계·옮김 — 적어 둔 줄만 정리한다(그 페이지 자체는 건드리지 않는다). 다음 줄에서 새로 만든다.
        $sqlForget = <<<'SQL'
            DELETE FROM community.my_tasks_projects WHERE user_id = CAST(:uid AS bigint) AND page_id = CAST(:pid AS bigint)
            SQL;
        $db->run($sqlForget, [':uid' => $me, ':pid' => (int) $mt['page_id']]);
        $mt = null;
    }
    $mtCreated = false;
    if ($mt === null) {
        $mtSpace = community_my_personal_space($me);
        if ($mtSpace === null) {
            Http::error(409, '개인 공간을 먼저 엽니다');
        }
        [$mayMt, $mtCode] = CommunityAuthz::decidePageChange($user, [
            'deleted_at'          => null,
            'space_kind'          => (string) $mtSpace['kind'],
            'space_owner_user_id' => $mtSpace['owner_user_id'] ?? null,
            'space_team_id'       => $mtSpace['team_id'] ?? null,
        ]);
        if (!$mayMt) {
            Http::error(403, Http::MSG_FORBIDDEN);
        }
        $mtPreset = DbProps::projectPreset([], []);
        $mtColor  = community_project_color($user, (int) $mtSpace['id']);
        $mtDoc    = ['schema' => 1, 'blocks' => [['id' => Doc::uuid4(), 'type' => 'paragraph', 'text' => []]]];
        $mtExtract = Doc::extract($mtDoc);
        $db->begin();
        try {
            $mtRow = community_insert_page([
                'space_id' => (int) $mtSpace['id'], 'parent_id' => null,
                'position' => community_next_position((int) $mtSpace['id'], null), 'visibility' => 'private',
                'title' => '내 할 일', 'doc_json' => json_encode($mtDoc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'database_id' => null, 'properties' => null,
            ], $mtExtract, $user, $mtCode);
            community_sync_after_save($user, $mtRow, $mtDoc, $mtExtract, 1);
            $sqlMtDb = <<<'SQL'
                INSERT INTO community.databases (page_id, schema, views, created_by, purpose, color)
                VALUES (CAST(:pid AS bigint), CAST(:schema AS jsonb), CAST(:views AS jsonb), CAST(:me AS bigint), 'my_tasks', :color)
                SQL;
            $db->run($sqlMtDb, [
                ':pid' => (int) $mtRow['id'], ':me' => $me, ':color' => $mtColor,
                ':schema' => json_encode($mtPreset['schema'], JSON_UNESCAPED_UNICODE),
                ':views'  => json_encode($mtPreset['views'], JSON_UNESCAPED_UNICODE),
            ]);
            Audit::allow('database.create', [
                'target_type' => 'page',
                'target_id'   => (int) $mtRow['id'],
                'target_name' => '내 할 일',
                'detail'      => ['page_title' => '내 할 일', 'granted_by' => $mtCode],
            ]);
            // 사람마다 하나 — 겹친 요청이 먼저 적었으면(PK 충돌) 내 것은 통째로 되돌리고 그쪽 프로젝트를 쓴다
            $sqlClaim = <<<'SQL'
                INSERT INTO community.my_tasks_projects (user_id, page_id)
                VALUES (CAST(:uid AS bigint), CAST(:pid AS bigint))
                ON CONFLICT DO NOTHING
                RETURNING user_id
                SQL;
            $claimed = $db->run($sqlClaim, [':uid' => $me, ':pid' => (int) $mtRow['id']])->fetch();
            if (is_array($claimed)) {
                $db->commit();
                $mtCreated = true;
            } else {
                $db->rollback();
            }
        } catch (Throwable $e) {
            $db->rollback();
            throw $e;
        }
        $mt = $readMyTasks($me);
        if (!$usable($mt)) {
            Http::json(['error' => '「내 할 일」을 준비하지 못했습니다. 잠시 뒤 다시 하세요', 'reason' => 'my_tasks_busy'], 409);
            exit;
        }
    }
    $body['parent_id'] = (string) $mt['public_id'];
    $myTasksInfo = ['project_id' => (string) $mt['public_id'], 'created' => $mtCreated];
}

// ─── [4] 부모 (먼저 읽는다 — 5-A) ─────────────────────────────
/* 5-A — 하위 페이지 만들기는 **권한대로**다(사용자 결정 3): 편집 공유를 받은 사람은 그 페이지
   아래에 하위를 만들 수 있고, 보기 공유면 안 된다. 그래서 부모가 있으면 판정은 **부모 행**에
   대한 `decidePageChange`(소유자·편집 공유자·팀원)이고, 공간은 **부모의 공간**이다 — 만든
   페이지의 소유자는 그 공간의 주인이다(공유받은 사람의 공간으로 가지 않는다).
   부모가 없으면 전과 같다: 공간(기본 내 개인 공간)에 쓸 수 있는 사람인가를 묻는다. */
$parentRow = null;
if (array_key_exists('parent_id', $body) && $body['parent_id'] !== null) {
    $rawParent = $body['parent_id'];
    if (!is_string($rawParent)
        || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $rawParent) !== 1
    ) {
        Http::error(400, Http::MSG_BAD_REQUEST);
    }
    $parentRow = community_page_row($rawParent, $me);
    if ($parentRow === null || $parentRow['deleted_at'] !== null) {
        // 아무 공간에도 없는 UUID(또는 지워진 페이지) — 「없다」로 답한다 (4-A 규칙).
        Http::error(404, Http::MSG_NOT_FOUND);
    }
    /* 5-C — 게시판 글에는 하위 페이지가 없다(게시판은 한 줄 목록이다). 게시판에 새 글을 쓰는 창구는
       `POST /api/boards/{num}/pages` 하나다. */
    if ((string) $parentRow['space_kind'] === 'board') {
        Http::json(['error' => '게시판 글 아래에는 하위 페이지를 만들 수 없습니다', 'reason' => 'space_governed'], 400);
        exit;
    }
}

// ─── [5] 공간 (4-B) ────────────────────────────────────────────
/* 기본은 세션 본인의 개인 공간이다 — 부모를 주면 **부모의 공간**이다(5-A). `space_id` 를 주면
   그 공간이 되지만, **그 공간에 쓸 수 있는 사람인지는 서버가 판단한다**. 번호를 바꿔 넣어도
   남의 공간에는 한 줄도 들어가지 않는다. */
$spaceGiven = array_key_exists('space_id', $body) && $body['space_id'] !== null;
if ($spaceGiven) {
    $rawSpace = $body['space_id'];
    if (is_int($rawSpace)) {
        $rawSpace = (string) $rawSpace;
    }
    if (!is_string($rawSpace) || preg_match('/^[1-9][0-9]{0,17}$/', $rawSpace) !== 1) {
        Http::error(400, Http::MSG_BAD_REQUEST);
    }
    $spaceRow = community_space_row((int) $rawSpace);
    if ($spaceRow === null) {
        Http::error(404, Http::MSG_NOT_FOUND);
    }
    // 5-C — 게시판 공간에는 이 창구로 쓰지 않는다(새 글은 `POST /api/boards/{num}/pages`).
    if ((string) $spaceRow['kind'] === 'board') {
        Http::json(['error' => '게시판에는 「새 글」로 씁니다', 'reason' => 'space_governed'], 400);
        exit;
    }
} elseif ($parentRow !== null) {
    $spaceRow = community_space_row((int) $parentRow['space_id']);
    if ($spaceRow === null) {
        Http::error(404, Http::MSG_NOT_FOUND);
    }
} else {
    $spaceRow = community_my_personal_space($me);
    if ($spaceRow === null) {
        // 개인 공간은 `GET /api/me/space` 가 만든다. 아직 없다면 화면이 그 창구를 먼저 부르면 된다.
        Http::error(409, '개인 공간을 먼저 엽니다');
    }
}
$spaceId = (int) $spaceRow['id'];

/** 남의 부모에 닿으려 한 일 — 403 + `page.read deny not_owner` (4-A 부터의 같은 줄). */
$refuseParent = static function (array $parent): void {
    $parentTitle = community_target_title((string) $parent['title']);
    Audit::deny('page.read', CommunityAuthz::CODE_NOT_OWNER, [
        'target_type' => 'page',
        'target_id'   => (int) $parent['id'],
        'target_name' => $parentTitle,
        'detail'      => ['page_title' => $parentTitle],
    ]);
    Http::error(403, Http::MSG_FORBIDDEN);
};

$parentId         = null;
$parentVisibility = null;
$createCode       = CommunityAuthz::CODE_OWNER;

if ($parentRow !== null) {
    if ((int) $parentRow['space_id'] !== $spaceId) {
        /* 공간 번호와 부모가 어긋난다 — **403**, 그리고 남의 페이지에 닿으려 한 일을 남긴다.
           「없다」로 답하지 않는 이유는 `pages_get.php` 와 같다 (ARCH 13.2). */
        $refuseParent($parentRow);
    }
    // ★ 부모 행에 대한 **내용 고치기** 판정 — 소유자 · 편집 공유자 · 팀 공간 팀원 (5-A).
    [$mayChild, $childCode] = CommunityAuthz::decidePageChange($user, $parentRow);
    if (!$mayChild) {
        $refuseParent($parentRow);
    }
    $createCode       = $childCode;
    $parentId         = (int) $parentRow['id'];
    // 5-C — 게시 중인 부모의 하위는 **기준 범위**(게시 전 범위)로 시작한다. 게시는 하위로 번지지 않는다.
    $parentVisibility = CommunityAuthz::baseVisibility($parentRow);

    // 깊이는 뿌리를 1 로 세어 8 까지. 부모가 8 이면 그 아래는 9 다 (4-A 규칙).
    if (community_page_depth($parentId) + 1 > CommunityAuthz::MAX_DEPTH) {
        Http::json([
            'error'  => '페이지는 8 단계까지만 겹칠 수 있습니다',
            'reason' => CommunityAuthz::CODE_DEPTH_EXCEEDED,
        ], 400);
        exit;
    }
} else {
    [$maySpaceWrite, $spaceCode] = CommunityAuthz::decidePageChange($user, [
        'deleted_at'          => null,
        'space_kind'          => (string) $spaceRow['kind'],
        'space_owner_user_id' => $spaceRow['owner_user_id'] ?? null,
        'space_team_id'       => $spaceRow['team_id'] ?? null,
    ]);
    if (!$maySpaceWrite) {
        /* 남의 개인 공간·다른 팀의 공간이다 (C:T-36 「다른 팀은 403」). 대기열 10번 A.1(2026-09-24):
           `not_owner`·`not_member` 는 감사로 남긴다 — `pages_delete.php` 와 같은 무게. 문장이 없는
           나머지 조합만 전처럼 서버 로그에 남긴다(문장 없는 기록은 요청을 500 으로 끝낸다). */
        if ($spaceCode === CommunityAuthz::CODE_NOT_OWNER || $spaceCode === CommunityAuthz::CODE_NOT_MEMBER) {
            Audit::deny('page.create', $spaceCode, [
                'target_type' => 'space',
                'target_id'   => (int) $spaceId,
                'target_name' => community_target_title((string) ($spaceRow['name'] ?? '')),
                'detail'      => ['space_kind' => (string) $spaceRow['kind']],
            ]);
            Http::error(403, Http::MSG_FORBIDDEN);
        }
        error_log('[tbox] request_id=' . TBOX_REQUEST_ID
            . ' community page.create deny code=' . $spaceCode . ' space_id=' . (string) $spaceId);
        Http::error(403, Http::MSG_FORBIDDEN);
    }
    $createCode = $spaceCode;
}

/* 6-A — (부모 판정을 **지난 뒤** — 독립 검토 M-1: 못 쓰는 사람이 DB 여부·속성 이름·행 수를 떠보지 못하게)
   부모가 DB 페이지면 새 페이지는 **행**이다. 값은 그 DB 의 스키마로 검사하고, 사람 값은 **부모를 볼 수 있는
   사람**만(새 행은 부모와 같은 범위로 시작한다 — 아래 [5] 뒤). DB 페이지 아래에 또 DB 는 만들지 않는다. */
$rowDbId  = null;
$rowProps = null;
$parentIsDb = $parentRow !== null && $parentRow['dbself_public_id'] !== null;
if ($parentIsDb && $makeDb) {
    Http::json(['error' => '데이터베이스의 행으로 데이터베이스를 만들 수 없습니다', 'reason' => 'db_in_db'], 400);
    exit;
}
if (array_key_exists('properties', $body) && !$parentIsDb) {
    Http::json(['error' => '속성 값은 데이터베이스의 행에만 있습니다', 'reason' => 'not_row'], 400);
    exit;
}
if ($parentIsDb) {
    $dbInfo = community_db_row((string) $parentRow['dbself_public_id']);
    if ($dbInfo === null) {
        Http::error(404, Http::MSG_NOT_FOUND);
    }
    $rowDbId  = (int) $dbInfo['id'];
    $schema   = community_db_schema_of($dbInfo['schema']);
    $rowProps = [];
    $rawProps = $body['properties'] ?? [];
    if (!is_array($rawProps) || ($rawProps !== [] && array_is_list($rawProps)) || count($rawProps) > DbProps::MAX_PROPS) {
        Http::error(400, Http::MSG_BAD_REQUEST);
    }
    /* 7-B — DB 행 템플릿의 값이 먼저, 요청 값이 그 위에. 템플릿 값은 **지금 스키마로 다시 거른다**
       (그 사이 지운 속성·바뀐 선택지는 조용히 빠진다 — 사람·관계·파일은 처음부터 담지 않았다). */
    if ($tpl !== null && (string) $tpl['scope'] === 'database' && (int) $tpl['database_id'] === $rowDbId) {
        $rawProps = array_merge(community_template_props($schema, $tpl['properties']), $rawProps);
    }
    foreach ($rawProps as $k => $v) {
        $prop = DbProps::find($schema, (string) $k);
        if ($prop === null) {
            Http::json(['error' => '없는 속성입니다', 'reason' => 'unknown_prop'], 400);
            exit;
        }
        [$okV, $clean] = DbProps::cleanValue($prop, $v);
        if (!$okV) {
            Http::json(['error' => '「' . (string) $prop['name'] . '」 값의 모양이 맞지 않습니다', 'reason' => 'bad_value'], 400);
            exit;
        }
        if ($clean !== null && (string) $prop['type'] === 'person'
            && !community_db_person_ok((string) $clean, (string) $parentRow['public_id'])) {
            Http::json(['error' => '이 데이터베이스를 볼 수 없는 사람은 넣을 수 없습니다', 'reason' => 'person_not_permitted'], 400);
            exit;
        }
        // 6-B 관계·파일 — 새 행은 부모(DB 페이지)와 같은 범위로 시작하므로 부모의 읽는 범위로 견준다
        if ($clean !== null && in_array((string) $prop['type'], ['relation', 'file'], true)
            && !community_db_refs_ok($prop, $clean, null, CommunityAuthz::pageAudiences($parentRow), $user)) {
            Http::json(['error' => '넣을 수 없는 행이나 파일이 있습니다', 'reason' => 'bad_ref'], 400);
            exit;
        }
        if ($clean !== null) {
            $rowProps[(string) $k] = $clean;
        }
    }
    if (community_db_row_count($rowDbId) >= DbProps::MAX_ROWS) {
        Http::json(['error' => '한 데이터베이스에는 행을 ' . DbProps::MAX_ROWS . '개까지 둡니다', 'reason' => 'too_many_rows'], 400);
        exit;
    }
}

/* 새 페이지는 형제의 **맨 뒤**에 붙는다 (4-A). 3단계는 언제나 0 이었다 — 형제가 없었기
   때문인데, 트리가 생긴 지금 0 을 그대로 쓰면 새로 만든 것이 형제 사이에 끼어든다. */
$position = community_next_position($spaceId, $parentId);

/* 새 페이지의 공개 범위 (4-B) — 부모가 있으면 **부모와 같게**, 없으면 `private`.
   요청이 정하지 않는다. 팀 공간이면 뿌리도 하위도 `private` 고정 문자열이고, 그 칸은
   판정에 쓰이지 않는다(팀 공간의 판정은 공간 하나로 끝난다). */
$visibility = 'private';
if ($parentVisibility !== null && in_array($parentVisibility, CommunityAuthz::VISIBILITIES, true)
    && (string) $spaceRow['kind'] === 'personal'
) {
    $visibility = $parentVisibility;
}

// Q:F-12 — 프로젝트 preset 의 칸·보기·색(서버 상수 · 만드는 사람이 볼 수 있는 그 공간의 프로젝트 수 차례)
$presetShape = $preset === 'project' ? DbProps::projectPreset([], []) : null;
$presetColor = $preset === 'project' ? community_project_color($user, $spaceId) : null;

// ─── [6] 저장 — 기록이 실패하면 페이지도 남지 않는다 (PRD S-07) ───
$db->begin();
try {
    $row = community_insert_page([
        'space_id'=>$spaceId,'parent_id'=>$parentId,'position'=>$position,'visibility'=>$visibility,
        'title'=>$title,'doc_json'=>$docJson,'database_id'=>$rowDbId,'properties'=>$rowProps,
        'icon'=>$tpl !== null && $tpl['icon'] !== null ? (string)$tpl['icon'] : null,
    ], $extract, $user, $createCode, $tpl === null ? [] : [
        'template_name'=>community_target_title((string)$tpl['name']), 'template_scope'=>(string)$tpl['scope'],
    ]);
    $pageId = (int)$row['id'];
    community_sync_after_save($user,$row,$doc,$extract,1);
    // Q:F-12 「내 할 일」 행 — 할 일로 시작한다(상태 todo · 준 기한). 만들 때의 첫 값이라 변경 사건은 없다(DB 행의 첫 값과 같다).
    if ($myTasks) {
        $sqlTask = <<<'SQL'
            UPDATE community.pages SET status = 'todo', due_date = CAST(:due AS date) WHERE id = CAST(:pid AS bigint)
            SQL;
        $db->run($sqlTask, [':due' => $wantDue, ':pid' => $pageId]);
    }

    // 6-A — DB 페이지면 `databases` 한 줄(빈 스키마 — 기본 열 제목·상태·소유자는 늘 있다)
    $newDb = null;
    if ($makeDb && $presetShape !== null) {
        // Q:F-12 프로젝트 — 칸·보기·색·쓰임은 서버 상수에서만
        $sqlDb = <<<'SQL'
            INSERT INTO community.databases (page_id, schema, views, created_by, purpose, color)
            VALUES (CAST(:pid AS bigint), CAST(:schema AS jsonb), CAST(:views AS jsonb), CAST(:me AS bigint), 'project', :color)
            RETURNING public_id
            SQL;
        $newDb = $db->run($sqlDb, [
            ':pid' => $pageId, ':me' => $me, ':color' => $presetColor,
            ':schema' => json_encode($presetShape['schema'], JSON_UNESCAPED_UNICODE),
            ':views'  => json_encode($presetShape['views'], JSON_UNESCAPED_UNICODE),
        ])->fetch();
    } elseif ($makeDb) {
        $sqlDb = <<<'SQL'
            INSERT INTO community.databases (page_id, schema, created_by)
            VALUES (CAST(:pid AS bigint), '[]'::jsonb, CAST(:me AS bigint))
            RETURNING public_id
            SQL;
        $newDb = $db->run($sqlDb, [':pid' => $pageId, ':me' => $me])->fetch();
        Audit::allow('database.create', [
            'target_type' => 'page',
            'target_id'   => $pageId,
            'target_name' => community_target_title($title),
            'detail'      => ['page_title' => community_target_title($title), 'granted_by' => $createCode]
                + ($preset === null ? [] : ['preset' => $preset]),
        ]);
    }

    $db->commit();
} catch (DomainException $e) {
    $db->rollback();
    Http::json(['error'=>'행 한도를 넘었습니다','reason'=>$e->getMessage()],400); exit;
} catch (Throwable $e) {
    $db->rollback();
    throw $e;   // bootstrap 예외 핸들러 → 500 + {"error":"서버 오류"}
}

Http::json([
    'page' => [
        'id'          => (string) $row['public_id'],
        'title'       => $title,
        'doc_version' => 1,
        'block_count' => count($extract['blocks']),
        'visibility'  => $visibility,
        'space'       => [
            'id'   => $spaceId,
            'kind' => (string) $spaceRow['kind'],
            'name' => (string) $spaceRow['name'],
        ],
        // 트리(4-A) — 만든 자리를 화면이 바로 알 수 있게 함께 돌려준다.
        'parent_id'   => array_key_exists('parent_id', $body) && is_string($body['parent_id'] ?? null)
            ? (string) $body['parent_id'] : null,
        'position'    => $position,
        'created_at'  => (string) $row['created_at'],
        'updated_at'  => (string) $row['updated_at'],
        'url'         => Http::communityUrl('/p/' . (string) $row['public_id']),
        // 6-A — 만든 DB(없으면 null) · 행이면 그 값
        'database'    => is_array($newDb ?? null) ? ['id' => (string) $newDb['public_id']]
            + ($preset === null ? [] : ['purpose' => 'project', 'color' => $presetColor]) : null,
        'values'      => $rowProps === null ? null : ($rowProps === [] ? new stdClass() : $rowProps),
    ] + ($myTasks ? ['status' => 'todo', 'due' => $wantDue] : []),
] + ($myTasksInfo === null ? [] : ['my_tasks' => $myTasksInfo]), 201);
