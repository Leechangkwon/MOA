<?php
declare(strict_types=1);

/**
 * src/community/handlers/comments_create.php — POST /community/api/pages/{id}/comments (정책: community.write)
 *
 * 요구사항: 커뮤니티 PRD F-21 · F-22, 지시서 5-D 「데이터」·「판정」, 결정 D-1(평문) · D-2(공지 게시판도 댓글).
 *
 * 받는 값 (JSON, **허용 키 밖이면 400**)
 *   body       본문 (필수) — 평문, 1~2,000자, 줄바꿈 말고 제어·서식 문자 400
 *   parent_id  답글이면 첫 댓글의 UUID — **같은 페이지**의 살아 있는 **첫 댓글**이어야 한다(답글의 답글 400)
 *   block_id   블록 앵커 — 이 페이지 본문에 **지금 있는** 블록 id(`page_blocks`)여야 한다
 *   quote      고른 문구 인용 — 평문 1~300자, `block_id` 와 함께만
 *
 * 검사 순서 [1] 허용 키·값 → [권한 ✔ A] 페이지 읽기(`community_history_gate`) → [권한 ✔ B] `decideCommentCreate`
 *   (Guest 403 · 보관 게시판 400) → [2] 부모·블록 → [3] 트랜잭션: INSERT + 알림(멘션·답글·내 페이지) + 감사 → 201
 *
 * 알림은 **받는 사람이 그 페이지를 볼 수 있을 때만** 만든다(`community_notify` → `decideMentionTarget`).
 * 볼 수 없는 사람을 `@` 해도 조용히 알림이 없다 — 「그 사람이 이 페이지를 볼 수 있나」를 응답으로 알려 주지 않는다.
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params [0] = 페이지 public_id (UUID)
 */

$db = Db::instance();
$me = (int) $user['id'];

$body = Http::jsonBody();
if (array_diff(array_keys($body), ['body', 'parent_id', 'block_id', 'quote']) !== []) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}
$text = community_clean_plain($body['body'] ?? null, COMMUNITY_COMMENT_MAX_CHARS);
if ($text === null) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}
$uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
$parentPid = $body['parent_id'] ?? null;
$blockId   = $body['block_id'] ?? null;
$quote     = null;
if ($parentPid !== null && (!is_string($parentPid) || preg_match($uuidRe, $parentPid) !== 1)) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}
if ($blockId !== null && (!is_string($blockId) || preg_match($uuidRe, $blockId) !== 1)) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}
if (array_key_exists('quote', $body) && $body['quote'] !== null) {
    $quote = community_clean_plain($body['quote'], COMMUNITY_QUOTE_MAX_CHARS);
    if ($quote === null || $blockId === null) {
        Http::error(400, Http::MSG_BAD_REQUEST);
    }
}

// ─── [권한 ✔ A] 페이지를 볼 수 있나 ─────────────────────────────
$gate      = community_history_gate($user, (string) $params[0]);
$row       = $gate['row'];
$pageId    = (int) $row['id'];
$pageTitle = community_target_title((string) $row['title']);

// ─── [권한 ✔ B] 댓글을 쓸 수 있나 ───────────────────────────────
[$may, $code] = CommunityAuthz::decideCommentCreate($user, $row);
if (!$may) {
    if ($code === CommunityAuthz::CODE_BOARD_INACTIVE) {
        Http::json(['error' => '보관 중인 게시판입니다. 읽기만 됩니다', 'reason' => $code], 400);
        exit;
    }
    Http::error(403, Http::MSG_FORBIDDEN);
}

// ─── [2] 부모 · 블록 ──────────────────────────────────────────
$parent = null;
if ($parentPid !== null) {
    $parent = community_comment_row($parentPid);
    if ($parent === null || $parent['deleted_at'] !== null || (int) $parent['page_id'] !== $pageId) {
        Http::error(400, Http::MSG_BAD_REQUEST);
    }
    // 게시판 독자에게 보이지 않는 게시 전 댓글에는 답글을 달 수 없다 — 없는 것과 같은 400(독립 검토 2026-09-23).
    if ($gate['since'] !== null && strtotime((string) $parent['created_at']) < strtotime((string) $gate['since'])) {
        Http::error(400, Http::MSG_BAD_REQUEST);
    }
    if ($parent['parent_comment_id'] !== null) {
        Http::json(['error' => '답글에는 답글을 달 수 없습니다', 'reason' => 'reply_depth'], 400);
        exit;
    }
}
if ($blockId !== null) {
    $sqlBlock = <<<'SQL'
        SELECT 1 FROM community.page_blocks WHERE page_id = CAST(:pid AS bigint) AND block_id = CAST(:bid AS uuid)
        SQL;
    if (!is_array($db->run($sqlBlock, [':pid' => $pageId, ':bid' => $blockId])->fetch())) {
        Http::error(400, Http::MSG_BAD_REQUEST);
    }
}

// ─── [3] 저장 + 알림 + 감사 ───────────────────────────────────
$sqlInsert = <<<'SQL'
    INSERT INTO community.comments (page_id, parent_comment_id, block_id, quote, body, author_id)
    VALUES (CAST(:pid AS bigint), CAST(:parent AS bigint), CAST(:bid AS uuid), :quote, :body, CAST(:me AS bigint))
    RETURNING id, public_id, created_at
    SQL;

$notified = [];
$db->begin();
try {
    $c = $db->run($sqlInsert, [
        ':pid'    => $pageId,
        ':parent' => $parent === null ? null : (int) $parent['id'],
        ':bid'    => $blockId,
        ':quote'  => $quote,
        ':body'   => $text,
        ':me'     => $me,
    ])->fetch();
    if (!is_array($c)) {
        throw new RuntimeException('댓글을 남기지 못했습니다');
    }
    $commentId = (int) $c['id'];

    Audit::allow('comment.create', [
        'target_type' => 'page',
        'target_id'   => $pageId,
        'target_name' => $pageTitle,
        'detail'      => [
            'granted_by'  => $code,
            'page_title'  => $pageTitle,
            'comment_id'  => $commentId,
            'reply'       => $parent !== null,
            'anchored'    => $blockId !== null,
        ],
    ]);
    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    throw $e;
}

/* 알림은 **댓글이 남은 뒤** 따로 만든다 — 알림 하나가 실패해도 댓글이 되돌려지지 않게(독립 검토 2026-09-23).
   실패는 서버 로그에만 남는다(알림은 곁가지다). */
try {
    // 멘션 — 볼 수 있는 활성 사용자(Guest 제외)에게만. 한 사람에게 알림은 한 번(멘션이 답글·내 페이지보다 앞선다).
    foreach (community_mentions_in($text) as $name) {
        $target = community_user_by_name($name);
        if ($target !== null && !isset($notified[(int) $target['id']])
            && community_notify((int) $target['id'], 'mention', $row, $commentId, $me)) {
            $notified[(int) $target['id']] = 'mention';
        }
    }
    // 답글 — 첫 댓글을 쓴 사람에게
    if ($parent !== null && !isset($notified[(int) $parent['author_id']])
        && community_notify((int) $parent['author_id'], 'reply', $row, $commentId, $me)) {
        $notified[(int) $parent['author_id']] = 'reply';
    }
    // 내 페이지에 댓글 — 개인 공간 주인 · 게시판 글의 쓴 사람(팀 공간은 주인이 팀이라 없다)
    $pageOwner = (string) $row['space_kind'] === 'board'
        ? (int) $row['author_id']
        : ((string) $row['space_kind'] === 'personal' ? (int) $row['space_owner_user_id'] : 0);
    if ($pageOwner > 0 && !isset($notified[$pageOwner])
        && community_notify($pageOwner, 'comment_on_my_page', $row, $commentId, $me)) {
        $notified[$pageOwner] = 'comment_on_my_page';
    }

} catch (Throwable $e) {
    error_log('[tbox] request_id=' . TBOX_REQUEST_ID . ' community comment notify failed: ' . get_class($e));
}

Http::json([
    'comment' => [
        'id'         => (string) $c['public_id'],
        'parent_id'  => $parentPid,
        'created_at' => (string) $c['created_at'],
    ],
    // 알림을 누구에게·몇 명에게 보냈는지는 응답에 싣지 않는다 — 「그 사람이 이 페이지를 볼 수 있나」가
    // 수로도 새지 않게(감사 상세에만 수가 남는다).
], 201);
