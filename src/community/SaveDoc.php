<?php
declare(strict_types=1);

/** C:S-18: the sole PATCH/suggestion-accept document write path.
 * Nested Db transactions are supported: a caller may atomically add its own metadata.
 * Every rejection rolls back the whole unit; document permissions are re-read under lock.
 * Options are server-only: force_snapshot and suggestion_id, never request fields.
 */
function community_save_doc(array $user, string $publicId, array $body, array $options = []): array
{
    $allowedFields = ['title', 'doc', 'doc_version'];

    $db  = Db::instance();
    $me  = (int) $user['id'];
    $pid = $publicId;

    if (array_diff(array_keys($body), $allowedFields) !== []) {
        Http::error(400, Http::MSG_BAD_REQUEST);
    }
    if (!array_key_exists('doc_version', $body)) {
        Http::error(400, Http::MSG_BAD_REQUEST);
    }
    if (!array_key_exists('title', $body) && !array_key_exists('doc', $body)) {
        Http::error(400, Http::MSG_BAD_REQUEST);
    }

    $db->begin();
    try {
        // ─── [2] 행 + 공간 ────────────────────────────────────────────
        $row = community_page_row($pid, (int) $user['id']);
        if ($row === null) {
            Http::error(404, Http::MSG_NOT_FOUND);
        }
        $db->run(COMMUNITY_PAGE_LOCK_SQL, [':pid' => (int) $row['id']]);
        // Read current ownership, visibility, version and document after waiting for the lock.
        $row = community_page_row($pid, $me);
        if ($row === null) { Http::error(404, Http::MSG_NOT_FOUND); }
        $pageId    = (int) $row['id'];
        $pageTitle = community_target_title((string) $row['title']);

        /** 거부 기록 후 끝낸다 — 상태·문구는 부르는 쪽이 정한다. */
        $reject = static function (string $reason, int $status, array $payload, array $detail) use ($row, $pageTitle): void {
            Db::instance()->rollback();
            Audit::deny('page.update', $reason, [
                'target_type' => 'page',
                'target_id'   => (int) $row['id'],
                'target_name' => $pageTitle,
                'detail'      => array_merge(['page_title' => $pageTitle], $detail),
            ]);
            if (class_exists('RemoteMcp', false) && RemoteMcp::isToolCall()) {
                Http::error($status, (string) ($payload['error'] ?? Http::MSG_BAD_REQUEST));
            }
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
                    'error'  => community_file_refs_private_readable($newFiles, $user) ? COMMUNITY_PRIVATE_FILE_REFUSED : '붙일 수 없는 파일이 있습니다. 내가 볼 수 있고, 이 페이지를 읽는 사람도 모두 볼 수 있는 파일만 붙입니다',
                    'reason' => 'file_ref',
                ], []);
            }
            /* Q:F-12 U3-1 (커뮤니티 ARCH 24.5) — **새로 들어온** `att:` 첨부 참조는 **이 문서의** 첨부여야 한다(다른 문서 · 없는 id 는 같은 400).
               감사 사유는 위 첨부 갈래와 같은 `file_ref`(문장 「붙일 수 없는 파일」) · detail.cause 와 응답 `reason` 은 `bad_attachment_ref`. */
            $newAtts = array_values(array_diff(Doc::extract($doc)['atts'], Doc::extract($currentDoc)['atts']));
            if (!community_attachment_refs_ok($pageId, $newAtts)) {
                $reject('file_ref', 400, ['error' => COMMUNITY_ATTACHMENT_REF_REFUSED, 'reason' => 'bad_attachment_ref'], ['cause' => 'bad_attachment_ref']);
            }
            /* 7-C — **새로 넣은** 임베드만 허용 목록과 견준다(K-3 — 목록에서 빠진 호스트의 옛 임베드는 저장을 막지 않는다). */
            if (!community_buttons_ok($doc, $currentDoc, $pid, $user)) { $reject('button_not_allowed', 400, ['error'=>'이 페이지에 묶인 내 버튼 작업만 추가할 수 있습니다.', 'reason'=>'button_not_allowed'], []); }
            $newEmbeds = array_values(array_diff(Doc::extract($doc)['embeds'], Doc::extract($currentDoc)['embeds']));
            if (!community_embeds_ok($newEmbeds)) {
                $reject('embed_not_allowed', 400, ['error' => COMMUNITY_EMBED_REFUSED, 'reason' => 'embed_not_allowed'], []);
            }
            /* 7-D2 — **새로 넣은** 팔레트 이름만 살아 있는 목록과 견준다(P-9 — 뺀 이름이 이미 든 글도 다른 곳을 고쳐 저장할 수 있게).
               모양은 검증기가 이미 보았다(`Doc::isPaletteColor`). 표를 못 읽으면 목록이 비어 새 이름은 모두 400(닫힌 쪽 — P-17). */
            $newPalettes = array_values(array_diff(Doc::extract($doc)['palette_names'], Doc::extract($currentDoc)['palette_names']));
            if (!community_palettes_ok($newPalettes)) {
                $reject('palette_not_found', 400, ['error' => COMMUNITY_PALETTE_REFUSED, 'reason' => 'palette_not_found'], []);
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
        $suggestionSnapshotSql = "SELECT 1 FROM community.page_suggestions WHERE page_id=:pid AND applied_version=:version AND status='accepted'";
        $applied = $db->run($suggestionSnapshotSql, [':pid'=>$pageId, ':version'=>$currentVersion])->fetchColumn();
        $squashed = !($options['force_snapshot'] ?? false) && !$applied
            && community_squash_snapshot($lastSnapshot, (int) $row['last_edited_by']);

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
               -- 5-E — 첨부를 견준 범위가 그 사이 바뀌었으면(넓히기·게시) 저장하지 않는다 → 409 (독립 검토 L1)
               AND visibility = :vis_read
               AND coalesce(published_board_id, 0) = CAST(:pb_read AS bigint)
            SQL;

        $newVersion = $currentVersion + 1;

        // Q:F-2 — 태그를 읽기 전에 행을 잠근다(태그 고치기와 겹쳐 색인이 옛 태그로 남지 않게 — routes.php 상수 머리글)
        $db->run(COMMUNITY_PAGE_LOCK_SQL, [':pid' => $pageId]);
        $tagsNow = community_page_tags($pageId);
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
            // Q:F-2 — 색인 글자는 한 함수(제목 + 본문 + 태그 이름, 한글 두 글자 조각 + 한 글자)
            // 태그는 위에서 행을 잠근 뒤 읽었다($tagsNow — COMMUNITY_PAGE_LOCK_SQL)
            ':tsv'    => community_search_text($title, $extract['plain_text'], $tagsNow, 'page ' . $pageId),
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

        community_after_doc_save($user, $row, $doc, $extract, $newVersion);
        Audit::allow('page.update', [
            'target_type' => 'page',
            'target_id'   => $pageId,
            'target_name' => community_target_title($title),
            // 카페 C1 (ARCH 25.6) — 익명게시판 글이면 감사 화면이 행위자를 가리는 표식(community_anon_audit_detail)
            'detail'      => community_anon_audit_detail($row, array_merge(isset($options['suggestion_id']) ? ['suggestion_id'=>$options['suggestion_id']] : [], [
                'granted_by'  => $changeCode,
                'page_title'  => community_target_title($title),
                'doc_version' => $newVersion,
                'block_count' => count($extract['blocks']),
            ])),
        ]);

        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        if ($e instanceof InvalidArgumentException && $e->getMessage() === 'synced_source_owner') {
            Audit::deny('page.update', 'synced_source_owner', [
                'target_type' => 'page', 'target_id' => $pageId,
                'detail' => ['doc_version' => $currentVersion],
            ]);
            Http::error(400, Http::MSG_BAD_REQUEST);
        }
        throw $e;   // bootstrap 예외 핸들러 → 500 + {"error":"서버 오류"}
    }

    return [
        'buttons' => community_button_map($doc, $row, $user),
        'doc_version' => $newVersion,
        'title'       => $title,
        'block_count' => count($extract['blocks']),
    ];
}

/** Fixed transactional extension point for synchronized sources and search embeddings. */
function community_after_doc_save(array $user, array $row, array $doc, array $extract, int $newVersion): void
{
    // Fixed in-process hooks only: both run before the surrounding transaction commits.
    if (function_exists('community_sync_after_save')) {
        community_sync_after_save($user, $row, $doc, $extract, $newVersion);
    }
    if (function_exists('community_embedding_after_save')) {
        community_embedding_after_save($user, $row, $doc, $extract, $newVersion);
    }
}
