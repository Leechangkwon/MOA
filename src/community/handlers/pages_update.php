<?php
declare(strict_types=1);

/**
 * src/community/handlers/pages_update.php — PATCH /community/api/pages/{id} (정책: community.write)
 *
 * 요구사항: `C:F-15`·`C:F-16`(낙관적 잠금)·`C:F-20`(체크 이력)·`C:S-11`·`C:S-14`,
 *           `../../../../community/ARCHITECTURE.md` 13.4, 수락 기준 C:T-14 · C:T-16 · C:T-17.
 *
 * 받는 값 (JSON, **허용 키 목록 밖의 키가 하나라도 있으면 400**)
 *   doc_version 필수. 내가 읽었을 때의 판 번호
 *   title       선택. trim 뒤 1~200자
 *   doc         선택. 블록 JSON
 *   (title·doc 중 **하나 이상**은 있어야 한다)
 *
 * 검사 순서 (ARCH 13.4 그대로)
 *   [1] 허용 키 → [2] 행+공간 → **404**
 *   [권한 ✔ A] `decidePageRead` 실패 403 (+ 남의 페이지면 감사 `page.read deny not_owner`)
 *   [권한 ✔ B] `decidePageChange` 실패 403 (+ 감사 `page.update deny not_owner`)
 *   [3] `doc_version` 이 서버 값과 다르면 **409** `{error, current_version, doc}`
 *       + 감사 `page.update deny version_conflict`
 *   [4] `Doc::validate` 실패 **400** `{error, errors}` + 감사 `page.update deny invalid_doc`
 *   [5-0] **5분 합치기** (4-C) — 직전 스냅샷이 같은 사람이고 5분 안이면 새 스냅샷을 만들지 않는다
 *   [5] 트랜잭션: (합치지 않을 때) `page_versions` 에 **이전 판** 남기기 → `pages` 갱신 →
 *       파생 표 다시 쓰기 → 체크 변화 → `checklist_events` → 감사 `page.update allow` → 200 `{doc_version}`
 *
 * ★ 판 번호는 **DB 가 올린다**(`doc_version = doc_version + 1`)고 쓰지 않고, 읽은 값 + 1 을
 *   `WHERE doc_version = 읽은 값` 과 함께 쓴다 — 두 요청이 같은 순간에 오면 둘째가 0행을
 *   고치게 되고, 그것을 409 로 돌려준다. 「먼저 읽고 나중에 쓴다」 사이의 틈을 DB 가 막는다.
 * ★ `page_versions` 는 앱 계정에 UPDATE·DELETE 가 없다 (C:S-14) — 한 번 남은 판은
 *   이 코드로도 지워지지 않는다.
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params [0] = 페이지 public_id (UUID)
 */

$allowedFields = ['title', 'doc', 'doc_version'];

$db  = Db::instance();
$me  = (int) $user['id'];
$pid = (string) $params[0];

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
if (!array_key_exists('doc_version', $body)) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}
if (!array_key_exists('title', $body) && !array_key_exists('doc', $body)) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}

// ─── [2] 행 + 공간 ────────────────────────────────────────────
$row = community_page_row($pid, (int) $user['id']);
if ($row === null) {
    Http::error(404, Http::MSG_NOT_FOUND);
}
$pageId    = (int) $row['id'];
$pageTitle = community_target_title((string) $row['title']);

/** 거부 기록 후 끝낸다 — 상태·문구는 부르는 쪽이 정한다. */
$reject = static function (string $reason, int $status, array $payload, array $detail) use ($row, $pageTitle): void {
    Audit::deny('page.update', $reason, [
        'target_type' => 'page',
        'target_id'   => (int) $row['id'],
        'target_name' => $pageTitle,
        'detail'      => array_merge(['page_title' => $pageTitle], $detail),
    ]);
    Http::json($payload, $status);
    exit;
};

// ─── [권한 ✔ A] 볼 수 있나 ────────────────────────────────────
[$mayRead, $readCode] = CommunityAuthz::decidePageRead($user, $row);
if (!$mayRead) {
    if ($readCode === CommunityAuthz::CODE_NOT_FOUND) {
        Http::error(404, Http::MSG_NOT_FOUND);
    }
    if ($readCode === CommunityAuthz::CODE_NOT_OWNER) {
        Audit::deny('page.read', CommunityAuthz::CODE_NOT_OWNER, [
            'target_type' => 'page',
            'target_id'   => $pageId,
            'target_name' => $pageTitle,
            'detail'      => ['page_title' => $pageTitle],
        ]);
        Http::error(403, Http::MSG_FORBIDDEN);
    }
    error_log('[tbox] request_id=' . TBOX_REQUEST_ID
        . ' community page.read deny code=' . $readCode . ' (3단계에 없는 조합)');
    Http::error(403, Http::MSG_FORBIDDEN);
}

// ─── [권한 ✔ B] 고칠 수 있나 ──────────────────────────────────
// 여기서 막히는 사람은 「볼 수는 있으나 고칠 수 없는 사람」 — 3단계에서는 Admin 이다.
[$mayChange, $changeCode] = CommunityAuthz::decidePageChange($user, $row);
if (!$mayChange) {
    if ($changeCode === CommunityAuthz::CODE_NOT_FOUND) {
        Http::error(404, Http::MSG_NOT_FOUND);
    }
    if ($changeCode === CommunityAuthz::CODE_NOT_OWNER) {
        $reject(CommunityAuthz::CODE_NOT_OWNER, 403, ['error' => Http::MSG_FORBIDDEN], []);
    }
    // 5-C — 남이 쓴 게시판 글(게시판 Manager·Admin 포함 — 내용은 쓴 사람만 고친다, PRD F-18).
    if ($changeCode === CommunityAuthz::CODE_NOT_AUTHOR) {
        $reject(CommunityAuthz::CODE_NOT_AUTHOR, 403, ['error' => Http::MSG_FORBIDDEN], []);
    }
    error_log('[tbox] request_id=' . TBOX_REQUEST_ID
        . ' community page.update deny code=' . $changeCode . ' (3단계에 없는 조합)');
    Http::error(403, Http::MSG_FORBIDDEN);
}

// ─── [3] 낙관적 잠금 ──────────────────────────────────────────
$rawVersion = $body['doc_version'];
$versionOk  = is_int($rawVersion)
    || (is_string($rawVersion) && preg_match('/^[0-9]{1,9}$/', $rawVersion) === 1);
if (!$versionOk) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}
$sentVersion    = (int) $rawVersion;
$currentVersion = (int) $row['doc_version'];

$currentDoc = json_decode((string) $row['doc'], true);
if (!is_array($currentDoc)) {
    throw new RuntimeException('저장된 본문을 읽을 수 없습니다');
}

if ($sentVersion !== $currentVersion) {
    // 화면이 「새로 고침 / 내 것으로 덮어쓰기」를 물어보려면 **지금 서버에 있는 것**이 필요하다.
    $reject('version_conflict', 409, [
        'error'           => '다른 곳에서 먼저 저장되었습니다',
        'current_version' => $currentVersion,
        'doc'             => $currentDoc,
        'title'           => (string) $row['title'],
    ], ['doc_version' => $currentVersion]);
}

// ─── 제목 ─────────────────────────────────────────────────────
$title = (string) $row['title'];
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

// ─── [4] 본문 검사 ────────────────────────────────────────────
$doc = $currentDoc;
if (array_key_exists('doc', $body)) {
    if (!is_array($body['doc'])) {
        Http::error(400, Http::MSG_BAD_REQUEST);
    }
    $doc   = $body['doc'];
    $check = Doc::validate($doc, COMMUNITY_DOC_STAGE);
    if (!$check['ok']) {
        /* 감사에는 **첫 어긋난 자리와 사유 코드만** 남긴다 — 본문의 글자는 한 글자도 넣지
           않는다(ARCH 12.3 「detail 에 사유 코드만」). 응답에는 자리 목록을 그대로 주어
           에디터가 무엇을 고쳐야 하는지 알 수 있게 한다. */
        $first = $check['errors'][0] ?? ['path' => '', 'reason' => '?'];
        $reject('invalid_doc', 400, [
            'error'  => Http::MSG_BAD_REQUEST,
            'errors' => $check['errors'],
        ], ['error_path' => (string) $first['path'] . ' : ' . (string) $first['reason']]);
    }
    /* 5-E 첨부 — **새로 붙인** t-box 파일만 견준다(이미 붙어 있던 것은 붙일 때·범위를 넓힐 때 견주었다 —
       편집 공유자가 자기가 못 보는 남의 첨부 때문에 저장하지 못하는 일이 없게). 없는·못 보는·좁은 파일은 같은 400. */
    $newFiles = array_values(array_diff(Doc::extract($doc)['files'], Doc::extract($currentDoc)['files']));
    if (!community_file_refs_ok($newFiles, CommunityAuthz::pageAudiences($row), $user)) {
        $reject('file_ref', 400, [
            'error'  => '붙일 수 없는 파일이 있습니다. 내가 볼 수 있고, 이 페이지를 읽는 사람도 모두 볼 수 있는 파일만 붙입니다',
            'reason' => 'file_ref',
        ], []);
    }
    /* 7-C — **새로 넣은** 임베드만 허용 목록과 견준다(K-3 — 목록에서 빠진 호스트의 옛 임베드는 저장을 막지 않는다). */
    $newEmbeds = array_values(array_diff(Doc::extract($doc)['embeds'], Doc::extract($currentDoc)['embeds']));
    if (!community_embeds_ok($newEmbeds)) {
        $reject('embed_not_allowed', 400, ['error' => COMMUNITY_EMBED_REFUSED, 'reason' => 'embed_not_allowed'], []);
    }
}

$extract = Doc::extract($doc);
$docJson = json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($docJson === false) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}

/* 체크 상자의 변화 (C:F-20). 규칙은 `community_check_changes()` **한 곳**에 있고 되돌리기도
   같은 함수를 쓴다 — 없던 블록이 체크된 채로 생겼으면 그것도 「켰다」로 남는다. */
$checkChanges = community_check_changes($currentDoc, $doc);

/* ─── [5-0] 5분 합치기 (4-C, C:F-19 · C:T-38) ────────────────────
 *
 * 직전 스냅샷이 **같은 사람**이고 **5분 안**에 남은 것이면 새 스냅샷을 만들지 않는다 —
 * 한 번 앉아서 고치는 동안 1.5초마다 판이 하나씩 쌓이면 이력이 읽을 수 없는 것이 되기
 * 때문이다. 그 사이의 변화는 다음 스냅샷에 **합쳐진다**(잃는 것은 없다 — 지금 내용은
 * `pages` 에 그대로 있고, 다음 스냅샷이 그 전부를 담는다).
 *
 * 판 번호는 합치든 말든 **매번 오른다.** 낙관적 잠금이 그 번호에 걸려 있으므로 번호를
 * 멈추면 두 창이 서로를 조용히 덮어쓴다 (C:T-16).
 *
 * 견주는 사람은 **지금 판의 내용을 쓴 사람**(`last_edited_by`)이다 — 스냅샷으로 남을 내용의
 * 임자이지 지금 저장 단추를 누른 사람이 아니다. 판정은 DB 를 읽지 않는 함수 하나에 있고
 * (`community_squash_snapshot`), 재료만 여기서 가져온다.
 */
$lastSnapshot = community_last_snapshot($pageId);
$squashed     = community_squash_snapshot($lastSnapshot, (int) $row['last_edited_by']);

// ─── [5] 저장 ─────────────────────────────────────────────────
$sqlUpdate = <<<'SQL'
    UPDATE community.pages
       SET title = :title,
           doc = CAST(:doc AS jsonb),
           doc_version = CAST(:newv AS int),
           plain_text = :plain,
           search_tsv = to_tsvector('simple', :tsv),
           block_count = CAST(:blocks AS int),
           updated_at = now(),
           last_edited_by = CAST(:editor AS bigint)
     WHERE id = CAST(:pid AS bigint)
       AND doc_version = CAST(:oldv AS int)
       AND deleted_at IS NULL
       -- 5-E — 첨부를 견준 범위가 그 사이 바뀌었으면(넓히기·게시) 저장하지 않는다 → 409 (독립 검토 L1)
       AND visibility = :vis_read
       AND coalesce(published_board_id, 0) = CAST(:pb_read AS bigint)
    SQL;

$newVersion = $currentVersion + 1;

$db->begin();
try {
    /* 이전 판을 먼저 남긴다 — 뒤의 UPDATE 가 실패하면 함께 되돌아간다.
       5분 합치기에 걸리면 이 줄을 건너뛴다(그 판의 내용은 다음 스냅샷에 합쳐진다). */
    if (!$squashed) {
        community_insert_snapshot($row);
    }

    $st = $db->run($sqlUpdate, [
        ':title'  => $title,
        ':doc'    => $docJson,
        ':newv'   => $newVersion,
        ':plain'  => $extract['plain_text'],
        ':tsv'    => $title . ' ' . $extract['plain_text'],
        ':blocks' => count($extract['blocks']),
        ':editor' => $me,
        ':pid'    => $pageId,
        ':oldv'   => $currentVersion,
        ':vis_read' => (string) $row['visibility'],
        ':pb_read'  => $row['published_board_id'] === null ? 0 : (int) $row['published_board_id'],
    ]);

    if ($st->rowCount() !== 1) {
        /* 읽고 나서 여기 오기까지 사이에 다른 요청이 먼저 저장했다. 되돌리고 409 로 답한다 —
           위 [3] 과 같은 결론이고, 그 틈을 DB 가 막았다는 증거다. */
        $db->rollback();
        $fresh    = community_page_row($pid, (int) $user['id']);
        $freshDoc = $fresh === null ? $currentDoc : json_decode((string) $fresh['doc'], true);
        $reject('version_conflict', 409, [
            'error'           => '다른 곳에서 먼저 저장되었습니다',
            'current_version' => $fresh === null ? $currentVersion : (int) $fresh['doc_version'],
            'doc'             => is_array($freshDoc) ? $freshDoc : $currentDoc,
            'title'           => $fresh === null ? (string) $row['title'] : (string) $fresh['title'],
        ], ['doc_version' => $fresh === null ? $currentVersion : (int) $fresh['doc_version']]);
    }

    community_rewrite_derived($pageId, $extract);

    community_insert_check_events($pageId, $checkChanges, $me);

    Audit::allow('page.update', [
        'target_type' => 'page',
        'target_id'   => $pageId,
        'target_name' => community_target_title($title),
        'detail'      => [
            'granted_by'  => $changeCode,
            'page_title'  => community_target_title($title),
            'doc_version' => $newVersion,
            'block_count' => count($extract['blocks']),
        ],
    ]);

    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    throw $e;   // bootstrap 예외 핸들러 → 500 + {"error":"서버 오류"}
}

Http::json([
    'doc_version' => $newVersion,
    'title'       => $title,
    'block_count' => count($extract['blocks']),
]);
