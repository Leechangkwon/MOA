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

/* 카페 C1 (ARCH 25.5) — 쓰기 · 감사는 공용 함수 한 벌(`community_comment_hide_write`, routes.php). 신고함의 「숨기기」가 같은 함수를 부른다.
   이 창구의 입력 · 판정 · 응답은 그대로다. */
$db = Db::instance();
$db->begin();
try {
    $n = community_comment_hide_write($row, $comment, $on, (int) $user['id'], $code) ? 1 : 0;
    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    throw $e;
}
Http::json(['comment' => ['id' => (string) $params[0], 'hidden' => $on], 'changed' => $n > 0, 'granted_by' => $code]);
