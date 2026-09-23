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
 *
 * ★ `visibility` 는 **허용 키가 아니다**(오면 400). 새 페이지의 범위는 요청이 정하지 않는다
 *   (4-B 지시서): 부모가 있으면 **부모와 같게**, 없으면 `private` 이다. 부모와 같게 시작해야
 *   트리 불변 규칙(자식 ≤ 부모)이 만들어지는 순간부터 성립하고, 뿌리는 가장 좁은 값에서
 *   시작해야 실수로 열린 페이지가 생기지 않는다. 넓히는 일은 `PATCH …/visibility` 한 곳이고
 *   거기에 규칙 검사와 감사가 붙어 있다.
 *   팀 공간의 페이지는 자기 범위가 없다(공간 등급을 따른다) — 칸은 `private` 고정 문자열로
 *   채우되 **판정은 공간으로** 한다 (`CommunityAuthz::decidePageRead` 의 `team` 갈래).
 *
 * 검사 순서
 *   [1] 허용 키 → [2] 제목 규칙 → [3] 본문 검사 → [4] 부모 → [5] 공간(부모의 공간, 없으면 내 개인 공간)
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

$allowedFields = ['title', 'doc', 'parent_id', 'space_id'];

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

// ─── [2] 제목 ──────────────────────────────────────────────────
$title = '제목 없는 페이지';
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

$extract = Doc::extract($doc);
$docJson = json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($docJson === false) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}

$db = Db::instance();
$me = (int) $user['id'];

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
        /* 남의 개인 공간·다른 팀의 공간이다 (C:T-36 「다른 팀은 403」). 만들기 거부에 붙일
           감사 문장이 표에 없으므로(문장 없는 기록은 요청을 500 으로 끝낸다) 서버 로그에
           남기고 403 동일 문구로 답한다 — `pages_delete.php` 와 같은 자리다. */
        error_log('[tbox] request_id=' . TBOX_REQUEST_ID
            . ' community page.create deny code=' . $spaceCode . ' space_id=' . (string) $spaceId);
        Http::error(403, Http::MSG_FORBIDDEN);
    }
    $createCode = $spaceCode;
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

// ─── [6] 저장 — 기록이 실패하면 페이지도 남지 않는다 (PRD S-07) ───
$sqlInsert = <<<'SQL'
    INSERT INTO community.pages
        (space_id, parent_page_id, title, doc, doc_version, plain_text, search_tsv, block_count,
         author_id, last_edited_by, visibility, position)
    VALUES
        (CAST(:space AS bigint), CAST(:parent AS bigint), :title, CAST(:doc AS jsonb), 1, :plain,
         to_tsvector('simple', :tsv), CAST(:blocks AS int),
         CAST(:author AS bigint), CAST(:editor AS bigint), :vis, CAST(:pos AS int))
    RETURNING id, public_id, created_at, updated_at
    SQL;

$db->begin();
try {
    $row = $db->run($sqlInsert, [
        ':space'  => $spaceId,
        ':parent' => $parentId,
        ':pos'    => $position,
        ':vis'    => $visibility,
        ':title'  => $title,
        ':doc'    => $docJson,
        ':plain'  => $extract['plain_text'],
        ':tsv'    => $title . ' ' . $extract['plain_text'],
        ':blocks' => count($extract['blocks']),
        ':author' => $me,
        ':editor' => $me,
    ])->fetch();
    if (!is_array($row)) {
        throw new RuntimeException('페이지를 만들지 못했습니다');
    }
    $pageId = (int) $row['id'];

    community_rewrite_derived($pageId, $extract);

    Audit::allow('page.create', [
        'target_type' => 'page',
        'target_id'   => $pageId,
        'target_name' => community_target_title($title),
        'detail'      => [
            // 무엇을 근거로 만들었나 — `owner`·`team_member`·`shared_edit`(편집 공유자의 하위 페이지, 5-A)
            'granted_by'  => $createCode,
            'page_title'  => community_target_title($title),
            'doc_version' => 1,
            'block_count' => count($extract['blocks']),
        ],
    ]);

    $db->commit();
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
    ],
], 201);
