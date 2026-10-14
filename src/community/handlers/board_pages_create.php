<?php
declare(strict_types=1);

/**
 * src/community/handlers/board_pages_create.php — POST /community/api/boards/{num}/pages (정책: community.write)
 *
 * 요구사항: 커뮤니티 PRD 4장(「글 쓰기 — 일반 게시판 · 공지 게시판」) · F-05 · F-18,
 *           사용자 결정 2026-09-23 ① 「게시판 안에서 바로 **새 글**을 쓴다」, 지시서 5-C 「데이터」·「창구」.
 *
 * 받는 값 (JSON, **허용 키 밖이 하나라도 있으면 400**)
 *   title  제목 (선택) — 없으면 「제목 없는 글」. 앞뒤 공백을 뗀 1~200자, 제어 문자 없음
 *   tags   태그 (선택) — `PUT …/tags` 와 같은 규칙(10개 · 30자 · 제어 문자 없음 · 대소문자 무시 한 번)
 * 본문은 받지 않는다 — 새 글은 빈 문단 하나로 만들어지고, 화면이 곧바로 편집 모드로 연다
 * (본문 저장은 한 창구 `PATCH /api/pages/{id}` 뿐이다 — 검증·409·판 이력이 거기 모여 있다).
 *
 * 검사 순서
 *   [1] 허용 키·값 → [2] 게시판 — 없으면 **403 동일 문구**(번호 열거 방지, PRD F-04)
 *   [권한 ✔] `CommunityAuthz::decideBoardWrite` — 못 읽는 게시판 403 · Guest 403(라우트가 먼저) ·
 *            공지 게시판의 비관리자 403 + 감사 `page.create deny role_not_permitted` · 비활성 **400** `board_inactive`
 *   [3] 트랜잭션 — 게시판 공간에 페이지 INSERT(쓴 사람 = `author_id`) + 파생 표 + 태그 + 감사 `page.create allow` → 201
 *
 * 게시판 글에는 공개 범위가 없다 — `visibility` 칸은 `private` 고정 문자열이고 판정에 쓰이지 않는다
 * (게시판 등급을 따른다 — `CommunityAuthz::decidePageRead` [2]). 하위 페이지도 없다(한 줄 목록).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params [0] = 게시판 번호
 */

$db      = Db::instance();
$me      = (int) $user['id'];
$boardId = (int) $params[0];

// ─── [1] 허용 키·값 ───────────────────────────────────────────
$body = Http::jsonBody();
if (array_diff(array_keys($body), ['title', 'tags']) !== []) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}
$title = '제목 없는 글';
if (array_key_exists('title', $body)) {
    $raw = $body['title'];
    if (!is_string($raw) || preg_match('//u', $raw) !== 1) {
        Http::error(400, Http::MSG_BAD_REQUEST);
    }
    $title = trim($raw);
    if ($title === '' || iconv_strlen($title, 'UTF-8') > 200 || preg_match('/\p{C}/u', $title) === 1) {
        Http::error(400, Http::MSG_BAD_REQUEST);
    }
}
$tags = [];
if (array_key_exists('tags', $body)) {
    $tags = community_parse_tags($body['tags']);
    if ($tags === null) {
        Http::error(400, Http::MSG_BAD_REQUEST);
    }
}

// ─── [2] 게시판 ───────────────────────────────────────────────
$board = community_board_row($boardId);
if ($board === null) {
    Http::error(403, Http::MSG_FORBIDDEN);
}

// ─── [권한 ✔] 새 글을 쓸 수 있나 ───────────────────────────────
[$mayWrite, $writeCode] = CommunityAuthz::decideBoardWrite($user, $board);
if (!$mayWrite) {
    if ($writeCode === CommunityAuthz::CODE_BOARD_INACTIVE) {
        Http::json(['error' => '보관 중인 게시판에는 글을 쓸 수 없습니다', 'reason' => $writeCode], 400);
        exit;
    }
    /* 못 읽는 게시판(`role_not_permitted`·`team_mismatch`)과 공지 게시판의 비관리자가 여기서 끝난다.
       어느 쪽이든 「이 게시판에 쓰려 했다」는 사실을 남긴다 — {target} 은 게시판 이름이다. */
    $reason = $writeCode === 'team_mismatch' ? 'team_mismatch' : CommunityAuthz::CODE_ROLE_NOT_PERMITTED;
    Audit::deny('page.create', $reason, [
        'target_type'  => 'space',
        'target_id'    => $boardId,
        'target_name'  => (string) $board['name'],
        'access_level' => (string) $board['access_level'],
        'detail'       => ['board_id' => $boardId],
    ]);
    Http::error(403, Http::MSG_FORBIDDEN);
}

// ─── [3] 저장 ─────────────────────────────────────────────────
$doc     = ['schema' => 1, 'blocks' => [['id' => Doc::uuid4(), 'type' => 'paragraph', 'text' => []]]];
$extract = Doc::extract($doc);
$docJson = json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($docJson === false) {
    throw new RuntimeException('본문을 만들지 못했습니다');
}

$sqlInsert = <<<'SQL'
    INSERT INTO community.pages
        (space_id, parent_page_id, title, doc, doc_version, plain_text, search_tsv, block_count,
         author_id, last_edited_by, visibility, position)
    VALUES
        (CAST(:space AS bigint), NULL, :title, CAST(:doc AS jsonb), 1, :plain,
         strip(to_tsvector('simple', :tsv)), CAST(:blocks AS int),
         CAST(:author AS bigint), CAST(:editor AS bigint), 'private', 0)
    RETURNING id, public_id, created_at
    SQL;
$sqlTag = <<<'SQL'
    INSERT INTO community.page_tags (page_id, tag, tag_norm, created_by)
    VALUES (CAST(:pid AS bigint), :tag, lower(:tag2), CAST(:by AS bigint))
    ON CONFLICT (page_id, tag_norm) DO NOTHING
    SQL;

$db->begin();
try {
    $row = $db->run($sqlInsert, [
        ':space'  => $boardId,
        ':title'  => $title,
        ':doc'    => $docJson,
        ':plain'  => $extract['plain_text'],
        ':tsv'    => community_search_text($title, $extract['plain_text'], $tags),   // Q:F-2 — 태그 이름도(J-4)
        ':blocks' => count($extract['blocks']),
        ':author' => $me,
        ':editor' => $me,
    ])->fetch();
    if (!is_array($row)) {
        throw new RuntimeException('글을 만들지 못했습니다');
    }
    $pageId = (int) $row['id'];
    community_rewrite_derived($pageId, $extract);
    foreach ($tags as $t) {
        $db->run($sqlTag, [':pid' => $pageId, ':tag' => $t, ':tag2' => $t, ':by' => $me]);
    }
    /* 카페 C1 (ARCH 25.4) — 익명게시판 글이면 글쓴이 별칭 0(「글쓴이」). 익명 여부는 `community_page_is_anon` 하나 —
       새 글의 행은 아직 없으므로 같은 칸(게시판 글 · 게시판 종류)만 든 행으로 묻는다. */
    $anonRow = ['space_kind' => 'board', 'space_board_kind' => (string) $board['board_kind']];
    if (community_page_is_anon($anonRow)) {
        community_anon_assign_alias($pageId, $me, $me);
    }

    Audit::allow('page.create', [
        'target_type'  => 'page',
        'target_id'    => $pageId,
        'target_name'  => community_target_title($title),
        'access_level' => (string) $board['access_level'],
        'detail'       => community_anon_audit_detail($anonRow, [
            // 무엇을 근거로 만들었나 — 게시판 등급(`board`). 어느 게시판인지도 함께.
            'granted_by'  => $writeCode,
            'page_title'  => community_target_title($title),
            'board_id'    => $boardId,
            'board_name'  => (string) $board['name'],
            'doc_version' => 1,
            'block_count' => count($extract['blocks']),
        ]),
    ]);
    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    throw $e;   // bootstrap 예외 핸들러 → 500 + {"error":"서버 오류"}
}

Http::json([
    'page' => [
        'id'         => (string) $row['public_id'],
        'title'      => $title,
        'board'      => ['id' => $boardId, 'name' => (string) $board['name']],
        'tags'       => community_page_tags($pageId),
        'created_at' => (string) $row['created_at'],
        'url'        => Http::communityUrl('/p/' . (string) $row['public_id']),
    ],
], 201);
