<?php
declare(strict_types=1);

/**
 * src/community/handlers/comment_resolve.php — POST·DELETE /community/api/comments/{id}/resolve (정책: community.write)
 *
 * 요구사항: 커뮤니티 PRD F-21 「해결됨」, 결정 D-5 기본안 — **스레드 첫 댓글**에만, 켜고 끄는 사람은 그 첫 댓글을
 * 쓴 사람 · 페이지를 관리하는 사람(`CommunityAuthz::decideCommentResolve`). 답글에 부르면 404(해결은 스레드 단위).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params [0] = 댓글 public_id (UUID)
 */

$on     = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
$action = $on ? 'comment.resolve' : 'comment.unresolve';

$rawLen = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($rawLen > 0 && Http::jsonBody() !== []) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}

['comment' => $comment, 'gate' => $gate] = community_comment_gate($user, (string) $params[0]);
$row       = $gate['row'];
$pageTitle = community_target_title((string) $row['title']);

[$may, $code] = CommunityAuthz::decideCommentResolve($user, $row, $comment);
if (!$may) {
    if ($code === CommunityAuthz::CODE_NOT_FOUND) {
        Http::error(404, Http::MSG_NOT_FOUND);
    }
    Audit::deny($action, CommunityAuthz::CODE_NOT_OWNER, [
        'target_type' => 'page',
        'target_id'   => (int) $row['id'],
        'target_name' => $pageTitle,
        'detail'      => ['page_title' => $pageTitle, 'comment_id' => (int) $comment['id']],
    ]);
    Http::error(403, Http::MSG_FORBIDDEN);
}
if (($comment['resolved_at'] !== null) === $on) {
    Http::json(['comment' => ['id' => (string) $params[0], 'resolved' => $on], 'changed' => false]);
    exit;
}

$sql = $on
    ? <<<'SQL'
        UPDATE community.comments SET resolved_at = now(), resolved_by = CAST(:me AS bigint)
         WHERE id = CAST(:cid AS bigint) AND deleted_at IS NULL AND resolved_at IS NULL
        SQL
    : <<<'SQL'
        UPDATE community.comments SET resolved_at = NULL, resolved_by = NULL
         WHERE id = CAST(:cid AS bigint) AND deleted_at IS NULL AND resolved_at IS NOT NULL
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
            // 카페 C1 (ARCH 25.6) — 익명게시판 글이면 감사 화면이 행위자를 가리는 표식
            'detail'      => community_anon_audit_detail($row, ['granted_by' => $code, 'page_title' => $pageTitle, 'comment_id' => (int) $comment['id']]),
        ]);
    }
    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    throw $e;
}
Http::json(['comment' => ['id' => (string) $params[0], 'resolved' => $on], 'changed' => $n > 0]);
