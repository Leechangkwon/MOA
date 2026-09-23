<?php
declare(strict_types=1);

/**
 * src/community/handlers/comment_delete.php — DELETE /community/api/comments/{id} (정책: community.write)
 *
 * 요구사항: 커뮤니티 PRD F-21 — 지우기는 **쓴 사람만**, **논리 삭제**(`deleted_at` — 앱 계정에 DELETE 권한이 없다).
 * 답글이 달린 첫 댓글을 지우면 목록에 「지운 댓글」 자리가 남고 답글은 그대로 보인다(`comments_list.php`).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params [0] = 댓글 public_id (UUID)
 */

$rawLen = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($rawLen > 0 && Http::jsonBody() !== []) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}

['comment' => $comment, 'gate' => $gate] = community_comment_gate($user, (string) $params[0]);
$row       = $gate['row'];
$pageTitle = community_target_title((string) $row['title']);

[$may, $code] = CommunityAuthz::decideCommentChange($user, $comment);
if (!$may) {
    Audit::deny('comment.delete', CommunityAuthz::CODE_NOT_AUTHOR, [
        'target_type' => 'page',
        'target_id'   => (int) $row['id'],
        'target_name' => $pageTitle,
        'detail'      => ['page_title' => $pageTitle, 'comment_id' => (int) $comment['id']],
    ]);
    Http::error(403, Http::MSG_FORBIDDEN);
}

$sql = <<<'SQL'
    UPDATE community.comments SET deleted_at = now(), deleted_by = CAST(:me AS bigint)
     WHERE id = CAST(:cid AS bigint) AND deleted_at IS NULL
    SQL;
$db = Db::instance();
$db->begin();
try {
    if ($db->run($sql, [':me' => (int) $user['id'], ':cid' => (int) $comment['id']])->rowCount() < 1) {
        $db->rollback();
        Http::error(404, Http::MSG_NOT_FOUND);
    }
    Audit::allow('comment.delete', [
        'target_type' => 'page',
        'target_id'   => (int) $row['id'],
        'target_name' => $pageTitle,
        'detail'      => ['granted_by' => $code, 'page_title' => $pageTitle, 'comment_id' => (int) $comment['id']],
    ]);
    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    throw $e;
}
Http::json(['comment' => ['id' => (string) $params[0], 'deleted' => true]]);
