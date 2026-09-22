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
 *   parent_id 부모 페이지 (선택, UUID). **내 공간 안**의 살아 있는 페이지여야 한다
 *
 * ★ `visibility` 는 **허용 키가 아니다**(오면 400). 3단계의 페이지는 언제나 `private` 이고,
 *   공개 범위는 4단계에 화면·판정·감사와 함께 들어온다. 값을 미리 받아 두면 「지금은 무시되는
 *   칸」이 생기고, 그 칸을 믿은 화면이 나중에 어긋난다.
 *
 * 검사 순서
 *   [1] 허용 키 → [2] 제목 규칙 → [3] 본문 검사 → [4] 내 개인 공간 → [5] 부모 →
 *   [6] 트랜잭션(INSERT + 파생 표 + 감사 `page.create allow`) → 201
 *
 * 왜 거부에 감사가 없나: 여기서 나오는 400 은 전부 **요청 형식**의 문제다(허용 키 밖·제목
 * 길이·본문 스키마). t-box 도 `Http::jsonBody()` 의 형식 오류를 감사에 남기지 않는다 —
 * 남기는 것은 「권한으로 막은 일」이다. 그 판정(남의 공간에 만들기)은 이 창구에 없다:
 * 대상 공간을 요청이 고르지 못하고 **언제나 세션 본인의 개인 공간**이기 때문이다.
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params 경로 변수 없음
 */

$allowedFields = ['title', 'doc', 'parent_id'];

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

// ─── [4] 내 개인 공간 ──────────────────────────────────────────
// 요청은 공간을 고르지 못한다. 대상은 언제나 세션 본인의 개인 공간이다 (C:S-02).
$sqlSpace = <<<'SQL'
    SELECT id FROM community.spaces
     WHERE kind = 'personal' AND owner_user_id = CAST(:uid AS bigint)
    SQL;
$spaceRow = $db->run($sqlSpace, [':uid' => $me])->fetch();
if (!is_array($spaceRow)) {
    // 개인 공간은 `GET /api/me/space` 가 만든다. 아직 없다면 화면이 그 창구를 먼저 부르면 된다.
    Http::error(409, '개인 공간을 먼저 엽니다');
}
$spaceId = (int) $spaceRow['id'];

// ─── [5] 부모 ─────────────────────────────────────────────────
$parentId = null;
if (array_key_exists('parent_id', $body) && $body['parent_id'] !== null) {
    $rawParent = $body['parent_id'];
    if (!is_string($rawParent)
        || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $rawParent) !== 1
    ) {
        Http::error(400, Http::MSG_BAD_REQUEST);
    }
    $sqlParent = <<<'SQL'
        SELECT id FROM community.pages
         WHERE public_id = CAST(:pid AS uuid)
           AND space_id = CAST(:space AS bigint)
           AND deleted_at IS NULL
        SQL;
    $parentRow = $db->run($sqlParent, [':pid' => $rawParent, ':space' => $spaceId])->fetch();
    if (!is_array($parentRow)) {
        // 내 공간에 없는 부모 — 있는지 없는지를 따로 알려 주지 않고 형식 문제로 다룬다.
        Http::error(400, Http::MSG_BAD_REQUEST);
    }
    $parentId = (int) $parentRow['id'];
}

// ─── [6] 저장 — 기록이 실패하면 페이지도 남지 않는다 (PRD S-07) ───
$sqlInsert = <<<'SQL'
    INSERT INTO community.pages
        (space_id, parent_page_id, title, doc, doc_version, plain_text, search_tsv, block_count,
         author_id, last_edited_by, visibility, position)
    VALUES
        (CAST(:space AS bigint), CAST(:parent AS bigint), :title, CAST(:doc AS jsonb), 1, :plain,
         to_tsvector('simple', :tsv), CAST(:blocks AS int),
         CAST(:author AS bigint), CAST(:editor AS bigint), 'private', 0)
    RETURNING id, public_id, created_at, updated_at
    SQL;

$db->begin();
try {
    $row = $db->run($sqlInsert, [
        ':space'  => $spaceId,
        ':parent' => $parentId,
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
            'granted_by'  => CommunityAuthz::CODE_OWNER,
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
        'visibility'  => 'private',
        'created_at'  => (string) $row['created_at'],
        'updated_at'  => (string) $row['updated_at'],
        'url'         => Http::communityUrl('/p/' . (string) $row['public_id']),
    ],
], 201);
