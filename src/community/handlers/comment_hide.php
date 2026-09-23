<?php
declare(strict_types=1);

/**
 * src/community/handlers/comment_hide.php — POST·DELETE /community/api/comments/{id}/hide (정책: community.write)
 *
 * 요구사항: 커뮤니티 PRD F-21 「Admin 숨김(보존·감사)」, 결정 D-4 기본안 — Admin + 그 게시판의 관리자
 * (`CommunityAuthz::decideCommentHide`). 숨겨도 본문은 DB 에 그대로다. 숨긴 댓글은 쓴 사람·숨길 수 있는 사람에게만
 * 본문이 보이고, 나머지에게는 「숨긴 댓글」 자리만(결정 D-3).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params [0] = 댓글 public_id (UUID)
 */

$on     = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
$action = $on ? 'comment.hide' : 'comment.unhide';
$rawLen = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($rawLen > 0 && Http::jsonBody() !== []) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}

['comment' => $comment, 'gate' => $gate] = community_comment_gate($user, (string) $params[0]);
$row       = $gate['row'];
$pageTitle = community_target_title((string) $row['title']);

[$may, $code] = CommunityAuthz::decideCommentHide($user, $row);
if (!$may) {
    Audit::deny($action, CommunityAuthz::CODE_NOT_OWNER, [
        'target_type' => 'page',
        'target_id'   => (int) $row['id'],
        'target_name' => $pageTitle,
        'detail'      => ['page_title' => $pageTitle, 'comment_id' => (int) $comment['id']],
    ]);
    Http::error(403, Http::MSG_FORBIDDEN);
}
if (($comment['hidden_at'] !== null) === $on) {
    Http::json(['comment' => ['id' => (string) $params[0], 'hidden' => $on], 'changed' => false]);
    exit;
}

$sql = $on
    ? <<<'SQL'
        UPDATE community.comments SET hidden_at = now(), hidden_by = CAST(:me AS bigint)
         WHERE id = CAST(:cid AS bigint) AND deleted_at IS NULL AND hidden_at IS NULL
        SQL
    : <<<'SQL'
        UPDATE community.comments SET hidden_at = NULL, hidden_by = NULL
         WHERE id = CAST(:cid AS bigint) AND deleted_at IS NULL AND hidden_at IS NOT NULL
        SQL;
$bind = $on ? [':me' => (int) $user['id'], ':cid' => (int) $comment['id']] : [':cid' => (int) $comment['id']];

$db = Db::instance();
$db->begin();
try {
    $n = $db->run($sql, $bind)->rowCount();
    if ($n > 0) {
        Audit::allow($action, [
            'target_type' => 'page',
            'target_id'   => (int) $row['id'],
            'target_name' => $pageTitle,
            'detail'      => ['granted_by' => $code, 'page_title' => $pageTitle, 'comment_id' => (int) $comment['id']],
        ]);
    }
    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    throw $e;
}
Http::json(['comment' => ['id' => (string) $params[0], 'hidden' => $on], 'changed' => $n > 0, 'granted_by' => $code]);
