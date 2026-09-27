<?php
declare(strict_types=1);

/**
 * src/community/handlers/pages_publish.php — POST /community/api/pages/{id}/publish (정책: community.write)
 *
 * 요구사항: 커뮤니티 PRD 4.1 「게시 `published`」 · F-18, 사용자 결정 2026-09-23 ① 「개인 페이지를 게시판에
 *           **게시**(원본을 건다)할 수도 있다」, 지시서 5-C 「데이터 — 게시」.
 *
 * 받는 값 (JSON) — `board_id` 하나(정수, 필수). 허용 키 밖이면 400.
 *
 * 게시 = 개인 페이지의 `visibility='published'` + `published_board_id`. **원본은 주인 공간에 그대로**
 * 있고 게시판 목록에 같은 페이지가 걸린다(한 벌 — 고치면 게시판에도 그대로). 게시 전 범위는
 * `published_from` 에 적어 두고(해제하면 그 값으로 돌아간다), 판정은 게시판 등급과 원래 범위 중
 * 넓은 쪽이다(`CommunityAuthz::decidePageRead` [4a]). 하위 페이지는 따라가지 않는다.
 *
 * 검사 순서
 *   [1] 허용 키 → [2] 행 → 없거나 지워졌으면 404
 *   [권한 ✔ A] `decidePageRead` — 못 보는 페이지는 403(남의 비공개면 감사 `page.read deny not_owner`)
 *   [권한 ✔ B] `decidePublish` — 개인 공간의 **주인**만(편집 공유자 403 + 감사 `page.publish deny not_owner`).
 *              팀 공간·게시판 글은 400 `space_governed`
 *   [3] 이미 게시 중이면 400 `already_published`(다른 게시판으로 옮기려면 먼저 해제)
 *   [4] 게시판 — 없으면 **403 동일 문구**(번호 열거 방지)
 *   [권한 ✔ C] `decideBoardWrite` — 쓸 수 있는 게시판만(지시서 「게시할 수 있는 게시판 = 쓸 수 있는 게시판」).
 *              못 쓰면 403 + 감사 `page.publish deny role_not_permitted|team_mismatch`, 보관 중이면 400
 *   [5] 트랜잭션 — UPDATE + 처리 이력 `page_property_events(prop='publish')` + 감사 `page.publish allow` → 200
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params [0] = 페이지 public_id (UUID)
 */

$db  = Db::instance();
$me  = (int) $user['id'];
$pid = (string) $params[0];

// ─── [1] 허용 키 ──────────────────────────────────────────────
$body = Http::jsonBody();
if (array_diff(array_keys($body), ['board_id']) !== [] || !is_int($body['board_id'] ?? null) || $body['board_id'] < 1) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}
$boardId = (int) $body['board_id'];

// ─── [2] 행 + [권한 ✔ A] 볼 수 있나 ────────────────────────────
// 이력 창구의 공통 관문을 그대로 쓴다 — 404·403·감사(`not_owner`)가 한 벌이다.
$gate      = community_history_gate($user, $pid);
$row       = $gate['row'];
$pageId    = (int) $row['id'];
$pageTitle = community_target_title((string) $row['title']);

// ─── [1-1] 데이터베이스 (6-A, 결정 G-10) — DB 페이지와 그 행은 게시하지 않는다(게시판 등급과 행 범위가 엇갈린다) ───
if ($row['dbself_public_id'] !== null || $row['database_id'] !== null) {
    Http::json(['error' => '데이터베이스와 그 행은 게시판에 게시할 수 없습니다', 'reason' => 'database'], 400);
    exit;
}

// ─── [권한 ✔ B] 이 페이지를 게시할 수 있나 ─────────────────────
[$mayPublish, $publishCode] = CommunityAuthz::decidePublish($user, $row);
if (!$mayPublish) {
    if ($publishCode === CommunityAuthz::CODE_SPACE_GOVERNED) {
        Http::json(['error' => '개인 공간의 페이지만 게시할 수 있습니다', 'reason' => $publishCode], 400);
        exit;
    }
    Audit::deny('page.publish', CommunityAuthz::CODE_NOT_OWNER, [
        'target_type' => 'page',
        'target_id'   => $pageId,
        'target_name' => $pageTitle,
        'detail'      => ['page_title' => $pageTitle, 'board_id' => $boardId],
    ]);
    Http::error(403, Http::MSG_FORBIDDEN);
}

// ─── [3] 이미 게시 중인가 ─────────────────────────────────────
if ((string) $row['visibility'] === 'published') {
    Http::json(['error' => '이미 게시 중입니다. 다른 게시판에 걸려면 먼저 게시를 해제하세요', 'reason' => 'already_published'], 400);
    exit;
}
$from = CommunityAuthz::baseVisibility($row);
if ($from === null) {
    // 모르는 범위 — 되돌아갈 값을 적어 둘 수 없으므로 게시하지 않는다(모르면 막는다).
    Http::error(400, Http::MSG_BAD_REQUEST);
}

// ─── [4] 게시판 + [권한 ✔ C] 쓸 수 있는 게시판인가 ───────────────
$board = community_board_row($boardId);
if ($board === null) {
    Http::error(403, Http::MSG_FORBIDDEN);
}
[$mayWrite, $writeCode] = CommunityAuthz::decideBoardWrite($user, $board);
if (!$mayWrite) {
    if ($writeCode === CommunityAuthz::CODE_BOARD_INACTIVE) {
        Http::json(['error' => '보관 중인 게시판에는 게시할 수 없습니다', 'reason' => $writeCode], 400);
        exit;
    }
    Audit::deny('page.publish', $writeCode === 'team_mismatch' ? 'team_mismatch' : CommunityAuthz::CODE_ROLE_NOT_PERMITTED, [
        'target_type' => 'page',
        'target_id'   => $pageId,
        'target_name' => $pageTitle,
        'detail'      => ['page_title' => $pageTitle, 'board_id' => $boardId],
    ]);
    Http::error(403, Http::MSG_FORBIDDEN);
}

/* ─── [4-1] 첨부 (5-E) — 게시하면 게시판을 보는 사람이 모두 읽는다. 붙인 파일이 게시판 등급보다 좁으면 400 ───
   (그림이 게시판 독자에게 깨져 보이지 않게 — 결정 E-3. 없는·못 보는 파일과 같은 답) */
$pubFiles = Doc::extract(json_decode((string) $row['doc'], true) ?: [])['files'];
if (!community_file_refs_ok($pubFiles, CommunityAuthz::pageAudiences($row, $board + ['kind' => 'board']), $user)) {
    // 감사 detail 에 파일 id 는 넣지 않는다(결정 E-5 — 어느 파일인지가 새지 않게)
    Audit::deny('page.publish', 'file_narrower', [
        'target_type' => 'page',
        'target_id'   => $pageId,
        'target_name' => $pageTitle,
        'detail'      => ['page_title' => $pageTitle, 'board_id' => $boardId],
    ]);
    /* 디버깅 묶음 U3 — 문장만 쉬운 말로(시연 2026-09-26 「사내 그림이 든 페이지를 공개 게시하면 무엇을 하라는지 모르겠다」). 거부·상태 코드·사유 코드·감사는 그대로.
       문장에 드는 값은 **고른 게시판의 등급 이름 하나**(고정 표 넷 중 하나 — 게시하는 사람이 게시 창에서 이미 보는 배지 글자)뿐이다.
       어느 파일인지·그 파일의 등급은 싣지 않는다(결정 E-5 — 없는·못 보는 파일과 같은 답 · F-68 「문장에 대상 이름·개수 넣지 않기」). */
    $narrowLevelNames = ['public' => '공개', 'internal' => '사내', 'team' => '팀', 'admin-only' => '관리자'];
    $narrowLevel = $narrowLevelNames[(string) $board['access_level']] ?? null;
    Http::json([
        'error' => $narrowLevel === null
            ? '이 페이지에 이 게시판을 보는 사람이 볼 수 없는 그림이나 파일이 들어 있습니다. t-box 에서 그 파일의 등급을 올리거나, 페이지에서 빼고 다시 게시하세요'
            : '이 페이지에 「' . $narrowLevel . '」 게시판에 올릴 수 없는 그림이나 파일이 들어 있습니다. t-box 에서 그 파일의 등급을 「' . $narrowLevel . '」'
              . ($narrowLevel === '공개' ? '로' : ' 이상으로') . ' 올리거나, 페이지에서 빼고 다시 게시하세요',
        'reason' => 'file_narrower',
    ], 400);
    exit;
}

// ─── [5] 저장 ─────────────────────────────────────────────────
$sqlSet = <<<'SQL'
    UPDATE community.pages
       SET visibility = 'published',
           published_from = :from,
           published_board_id = CAST(:bid AS bigint),
           published_at = now(),
           published_by = CAST(:me AS bigint)
           -- ★ 숨김(`hidden_at`)은 건드리지 않는다 — 관리자가 숨긴 글을 해제 → 재게시로 되살리지 못하게
           --   (독립 검토 2026-09-23). 숨김은 게시판 관리자의 「숨김 풀기」로만 풀린다.
     WHERE id = CAST(:pid AS bigint)
       AND deleted_at IS NULL
       AND visibility <> 'published'
       -- 읽은 뒤 사이에 범위가 바뀌었으면(부모 cascade 등) 게시하지 않는다 — 옛 범위가 `published_from` 에
       -- 남으면 해제한 날 자식이 부모보다 넓어진다(독립 검토 2026-09-23).
       AND visibility = :from2
       -- 5-E — 첨부를 견준 그 판일 때만 게시한다(사이에 파일이 붙었으면 409 — 독립 검토 L1)
       AND doc_version = CAST(:dv AS int)
    RETURNING published_at
    SQL;

$db->begin();
try {
    $done = $db->run($sqlSet, [':from' => $from, ':from2' => $from, ':bid' => $boardId, ':me' => $me, ':pid' => $pageId, ':dv' => (int) $row['doc_version']])->fetch();
    if (!is_array($done)) {
        // 읽은 뒤 사이에 다른 요청이 먼저 게시했거나 지웠다.
        $db->rollback();
        Http::json(['error' => '그 사이 이 페이지가 바뀌었습니다. 새로 고친 뒤 다시 게시하세요', 'reason' => 'changed'], 409);
        exit;
    }
    // 처리 이력 — 「어느 게시판에 걸었나」를 사람이 읽는 글자로(게시판 이름). 지울 수 없는 줄이다.
    community_insert_property_event($pageId, 'publish', null, (string) $board['name'], $me);
    Audit::allow('page.publish', [
        'target_type'  => 'page',
        'target_id'    => $pageId,
        'target_name'  => $pageTitle,
        'access_level' => (string) $board['access_level'],
        'detail'       => [
            'granted_by'      => $publishCode,
            'page_title'      => $pageTitle,
            'board_id'        => $boardId,
            'board_name'      => (string) $board['name'],
            'visibility_from' => $from,
        ],
    ]);
    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    throw $e;
}

Http::json([
    'page' => [
        'id'             => $pid,
        'visibility'     => 'published',
        'published_from' => $from,
        'published_at'   => (string) $done['published_at'],
        'board'          => ['id' => $boardId, 'name' => (string) $board['name']],
    ],
]);
