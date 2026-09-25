<?php
declare(strict_types=1);

/**
 * src/community/handlers/version_restore.php — POST /community/api/pages/{id}/versions/{num}/restore
 *                                              (정책: community.write)
 *
 * 요구사항: `C:F-19`(되돌리기)·`C:S-14`(기록 불변), 커뮤니티 `PRD.md` F-19 · S-14,
 *           `../../../../community/ARCHITECTURE.md` 16장, 수락 기준 C:T-39 · C:T-42 · C:T-45.
 *
 * ★ **되돌리기는 과거를 고치지 않는다.** 지난 판의 `doc` 을 **새 판으로 복사**할 뿐이다:
 *     ① 지금 판을 스냅샷으로 남기고(`summary = 'restore from v<번호>'`)
 *     ② 지난 판의 내용·제목을 `pages` 에 놓고 `doc_version` 을 **+1** 한다
 *   `page_versions` 의 과거 행은 **개수도 내용도 그대로**다 — 앱 계정에 UPDATE·DELETE 가
 *   아예 없으므로(`db/community/grants.sql`) 이 코드로도 고칠 수 없다 (C:T-39 · C:T-40).
 *
 * 받는 값: **없다**(빈 JSON `{}`). 무엇을 되돌릴지는 주소의 판 번호가 정한다.
 *
 * 검사 순서
 *   [1] 빈 본문 → [2] 행+공간 → 404 → [권한 ✔ A] `decidePageRead` (`community_history_gate`)
 *   [권한 ✔ B] `decidePageChange` 실패 → **403** + 감사 `page.restore_version deny not_owner`
 *   [3] 그 판이 있나 → 없으면 **404** (지금 판을 되돌리라고 하면 400 `already_current`)
 *   [4] 그 판이 **지금 규칙으로도 성립하나** → 아니면 400 `invalid_doc`
 *   [5] 트랜잭션: 스냅샷 → `pages` 갱신(낙관적 잠금) → 파생 표 다시 쓰기 → 체크 변화 →
 *       감사 `page.restore_version allow` → 200
 *
 * ★ 파생 표(`page_blocks`·`page_links`)를 **되돌린 내용으로 다시 쓴다** (C:T-45). 파생값이
 *   본문과 어긋나면 찾기·역링크가 조용히 옛 내용을 가리킨다.
 * ★ 체크 상자가 되돌리기로 풀리거나 켜졌으면 그것도 `checklist_events` 에 남는다 (C:F-20) —
 *   저장과 **같은 함수**(`community_check_changes`)를 쓴다. 「누가 언제 껐나」에 빈칸을
 *   만들지 않으려는 것이다.
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params [0] = 페이지 public_id (UUID) · [1] = 되돌릴 판 번호(숫자)
 */

$body = Http::jsonBody();
if ($body !== []) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}

$db   = Db::instance();
$me   = (int) $user['id'];
$pid  = (string) $params[0];
$want = (int) $params[1];          // 라우터의 `{num}` 이 숫자만 통과시켰다

$gate      = community_history_gate($user, $pid);
$row       = $gate['row'];
$pageId    = (int) $row['id'];
$pageTitle = community_target_title((string) $row['title']);
$currentVersion = (int) $row['doc_version'];

// ─── [권한 ✔ B] 고칠 수 있나 ──────────────────────────────────
if (!$gate['may_change']) {
    if ($gate['change_code'] === CommunityAuthz::CODE_NOT_OWNER) {
        Audit::deny('page.restore_version', CommunityAuthz::CODE_NOT_OWNER, [
            'target_type' => 'page',
            'target_id'   => $pageId,
            'target_name' => $pageTitle,
            'detail'      => ['page_title' => $pageTitle, 'restored_from' => $want],
        ]);
        Http::error(403, Http::MSG_FORBIDDEN);
    }
    // 문장 틀이 없는 조합(팀원이 아닌 팀 공간 등)은 서버 로그에만 — 판정은 이미 끝났다.
    error_log('[tbox] request_id=' . TBOX_REQUEST_ID
        . ' community page.restore_version deny code=' . $gate['change_code']);
    Http::error(403, Http::MSG_FORBIDDEN);
}

// ─── [3] 그 판이 있나 ─────────────────────────────────────────
if ($want === $currentVersion) {
    // 되돌릴 것이 없다. 판을 하나 더 만들어 같은 내용을 두 번 적지 않는다.
    Http::json(['error' => '이미 지금 판입니다', 'reason' => 'already_current'], 400);
    exit;
}

$sqlVersion = <<<'SQL'
    SELECT v.version, v.title, v.doc
      FROM community.page_versions v
     WHERE v.page_id = CAST(:pid AS bigint)
       AND v.version = CAST(:ver AS int)
    SQL;

$old = $db->run($sqlVersion, [':pid' => $pageId, ':ver' => $want])->fetch();
if (!is_array($old)) {
    // 없는 번호이거나, 5분 합치기로 스냅샷이 남지 않은 번호다 — 둘 다 「우리에게 없다」.
    Http::error(404, Http::MSG_NOT_FOUND);
}

$oldDoc = json_decode((string) $old['doc'], true);
if (!is_array($oldDoc)) {
    throw new RuntimeException('저장된 본문을 읽을 수 없습니다');
}
$currentDoc = json_decode((string) $row['doc'], true);
if (!is_array($currentDoc)) {
    throw new RuntimeException('저장된 본문을 읽을 수 없습니다');
}

// ─── [4] 지금 규칙으로도 성립하나 ─────────────────────────────
/* 그 판이 저장되던 때의 규칙과 지금 규칙이 다를 수 있다(단계가 오르면 쓸 수 있는 블록이
   바뀐다). 모르면 막는다 — 검증기를 지나지 못하는 내용을 `pages` 에 넣으면 그 뒤의 저장이
   전부 400 이 되어 페이지가 잠긴다. 형식 거부이므로 감사는 남기지 않고(4-B `pages_visibility`
   와 같은 원칙) 서버 로그에 어긋난 자리만 남긴다. */
$check = Doc::validate($oldDoc, COMMUNITY_DOC_STAGE);
if (!$check['ok']) {
    $first = $check['errors'][0] ?? ['path' => '', 'reason' => '?'];
    error_log('[tbox] request_id=' . TBOX_REQUEST_ID
        . ' community page.restore_version invalid_doc page_id=' . (string) $pageId
        . ' version=' . (string) $want
        . ' at=' . (string) $first['path'] . ' : ' . (string) $first['reason']);
    Http::json([
        'error'  => '그 판은 지금 규칙으로 되살릴 수 없습니다',
        'reason' => 'invalid_doc',
        'errors' => $check['errors'],
    ], 400);
    exit;
}
/* 5-E — 되돌리는 판에 **지금 본문에 없는** 파일이 있으면 붙이는 것과 같다 — 같은 규칙으로 견준다. */
$restoreNewFiles = array_values(array_diff(Doc::extract($oldDoc)['files'], Doc::extract(json_decode((string) $row['doc'], true) ?: [])['files']));
if (!community_file_refs_ok($restoreNewFiles, CommunityAuthz::pageAudiences($row), $user)) {
    Audit::deny('page.restore_version', 'file_ref', [
        'target_type' => 'page',
        'target_id'   => $pageId,
        'target_name' => $pageTitle,
        'detail'      => ['page_title' => $pageTitle, 'restored_from' => $want],
    ]);
    Http::json(['error' => '그 판에는 지금 붙일 수 없는 파일이 있습니다', 'reason' => 'file_ref'], 400);
    exit;
}
/* 7-C — 되돌리는 판에 지금 본문에 없는 임베드가 있으면 넣는 것과 같다 — 허용 목록과 견준다(K-3). */
$restoreNewEmbeds = array_values(array_diff(Doc::extract($oldDoc)['embeds'], Doc::extract(json_decode((string) $row['doc'], true) ?: [])['embeds']));
if (!community_embeds_ok($restoreNewEmbeds)) {
    Audit::deny('page.restore_version', 'embed_not_allowed', [
        'target_type' => 'page',
        'target_id'   => $pageId,
        'target_name' => $pageTitle,
        'detail'      => ['page_title' => $pageTitle, 'restored_from' => $want],
    ]);
    Http::json(['error' => COMMUNITY_EMBED_REFUSED, 'reason' => 'embed_not_allowed'], 400);
    exit;
}

$extract = Doc::extract($oldDoc);
$docJson = json_encode($oldDoc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($docJson === false) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}

$title        = (string) $old['title'];
$newVersion   = $currentVersion + 1;
$checkChanges = community_check_changes($currentDoc, $oldDoc);

// ─── [5] 저장 ─────────────────────────────────────────────────
$sqlUpdate = <<<'SQL'
    UPDATE community.pages
       SET title = :title,
           doc = CAST(:doc AS jsonb),
           doc_version = CAST(:newv AS int),
           plain_text = :plain,
           search_tsv = strip(to_tsvector('simple', :tsv)),
           block_count = CAST(:blocks AS int),
           updated_at = now(),
           last_edited_by = CAST(:editor AS bigint)
     WHERE id = CAST(:pid AS bigint)
       AND doc_version = CAST(:oldv AS int)
       AND deleted_at IS NULL
    SQL;

$db->begin();
try {
    // Q:F-2 — 태그를 읽기 전에 행을 잠근다(태그 고치기와 겹쳐 색인이 옛 태그로 남지 않게 — routes.php 상수 머리글)
    $db->run(COMMUNITY_PAGE_LOCK_SQL, [':pid' => $pageId]);
    $tagsNow = community_page_tags($pageId);
    /* ① 지금 판을 스냅샷으로 남긴다. `summary` 는 **이 판이 어떻게 끝났나**이고, 화면은 그
       값을 다음 판의 줄에 「판 N 에서 되돌림」으로 붙인다 (`versions_list.php` 머리글 ★). */
    community_insert_snapshot($row, 'restore from v' . (string) $want);

    // ② 지난 판의 내용을 지금으로. 판 번호는 오른다 — 과거를 고치는 것이 아니다.
    $st = $db->run($sqlUpdate, [
        ':title'  => $title,
        ':doc'    => $docJson,
        ':newv'   => $newVersion,
        ':plain'  => $extract['plain_text'],
        // Q:F-2 — 색인 글자는 한 함수(제목 + 본문 + 태그 이름)
        // 태그는 위에서 행을 잠근 뒤 읽었다($tagsNow — COMMUNITY_PAGE_LOCK_SQL)
        ':tsv'    => community_search_text($title, $extract['plain_text'], $tagsNow, 'page ' . $pageId),
        ':blocks' => count($extract['blocks']),
        ':editor' => $me,
        ':pid'    => $pageId,
        ':oldv'   => $currentVersion,
    ]);

    if ($st->rowCount() !== 1) {
        /* 읽고 나서 여기 오기까지 사이에 다른 요청이 먼저 저장했다(또는 지웠다). 되돌리고
           409 로 답한다 — 되돌리기가 남의 저장을 소리 없이 덮어쓰지 않게 하는 자리다. */
        $db->rollback();
        $fresh = community_page_row($pid, (int) $user['id']);
        Http::json([
            'error'           => '다른 곳에서 먼저 저장되었습니다',
            'current_version' => $fresh === null ? $currentVersion : (int) $fresh['doc_version'],
        ], 409);
        exit;
    }

    community_rewrite_derived($pageId, $extract);            // C:T-45
    community_insert_check_events($pageId, $checkChanges, $me);

    Audit::allow('page.restore_version', [
        'target_type' => 'page',
        'target_id'   => $pageId,
        'target_name' => community_target_title($title),
        'detail'      => [
            'granted_by'    => $gate['change_code'],
            'page_title'    => community_target_title($title),
            'restored_from' => $want,
            'doc_version'   => $newVersion,
            'block_count'   => count($extract['blocks']),
        ],
    ]);

    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    throw $e;   // bootstrap 예외 핸들러 → 500 + {"error":"서버 오류"}
}

Http::json([
    'page' => [
        'id'          => $pid,
        'title'       => $title,
        'doc_version' => $newVersion,
        'block_count' => count($extract['blocks']),
    ],
    'restored_from' => $want,
]);
