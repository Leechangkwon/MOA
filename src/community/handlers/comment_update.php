<?php
declare(strict_types=1);

/**
 * src/community/handlers/comment_update.php — PATCH /community/api/comments/{id} (정책: community.write)
 *
 * 요구사항: 커뮤니티 PRD F-21, 지시서 5-D 「판정」 — 고치기는 **쓴 사람만**(결정 D-4: 관리자는 숨김만).
 * 받는 값: `{body}` 하나(평문 1~2,000자). 숨긴 댓글은 고치지 못한다(숨긴 뒤 내용을 바꿔 되살리는 길을 막는다).
 * 고쳐도 새 멘션 알림은 만들지 않는다 — 고칠 때마다 알림이 다시 가면 한 댓글로 여러 번 부를 수 있다.
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params [0] = 댓글 public_id (UUID)
 */

$body = Http::jsonBody();
if (array_keys($body) !== ['body']) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}
$text = community_clean_plain($body['body'], COMMUNITY_COMMENT_MAX_CHARS);
if ($text === null) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}

['comment' => $comment, 'gate' => $gate] = community_comment_gate($user, (string) $params[0]);
$row       = $gate['row'];
$pageTitle = community_target_title((string) $row['title']);

[$may, $code] = CommunityAuthz::decideCommentChange($user, $comment);
if (!$may || $comment['hidden_at'] !== null) {
    Audit::deny('comment.update', CommunityAuthz::CODE_NOT_AUTHOR, [
        'target_type' => 'page',
        'target_id'   => (int) $row['id'],
        'target_name' => $pageTitle,
        'detail'      => ['page_title' => $pageTitle, 'comment_id' => (int) $comment['id']],
    ]);
    Http::error(403, Http::MSG_FORBIDDEN);
}

$sql = <<<'SQL'
    UPDATE community.comments SET body = :body, updated_at = now(), search_tsv = strip(to_tsvector('simple', :tsv))
     WHERE id = CAST(:cid AS bigint) AND deleted_at IS NULL AND hidden_at IS NULL
    SQL;
$db = Db::instance();
$db->begin();
try {
    // Q:F-2 — 고친 본문으로 댓글 검색 색인도 다시(SearchText)
    $tsv = SearchText::indexText([$text], 'comment ' . (int) $comment['id']);
    if ($db->run($sql, [':body' => $text, ':tsv' => $tsv, ':cid' => (int) $comment['id']])->rowCount() < 1) {
        $db->rollback();
        Http::error(404, Http::MSG_NOT_FOUND);
    }
    Audit::allow('comment.update', [
        'target_type' => 'page',
        'target_id'   => (int) $row['id'],
        'target_name' => $pageTitle,
        // 카페 C1 (ARCH 25.6) — 익명게시판 글이면 감사 화면이 행위자를 가리는 표식
        'detail'      => community_anon_audit_detail($row, ['granted_by' => $code, 'page_title' => $pageTitle, 'comment_id' => (int) $comment['id']]),
    ]);
    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    throw $e;
}
Http::json(['comment' => ['id' => (string) $params[0]]]);
