<?php
declare(strict_types=1);

/**
 * src/community/handlers/comment_helpful.php — POST·DELETE /community/api/comments/{id}/helpful (정책: community.write)
 *
 * 요구사항: 커뮤니티 PRD F-21 「도움됐어요」 — 그 페이지를 볼 수 있고 쓰기 자격 있는 사람이 한 번 누르고 뺀다.
 * 감사에 남기지 않는다(가벼운 표시 — 행이 곧 기록이다). 숨긴·지운 댓글에는 누를 수 없다(404).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params [0] = 댓글 public_id (UUID)
 */

$on = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
$rawLen = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($rawLen > 0 && Http::jsonBody() !== []) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}

['comment' => $comment] = community_comment_gate($user, (string) $params[0]);
if ($comment['hidden_at'] !== null) {
    Http::error(404, Http::MSG_NOT_FOUND);
}
// 쓰기 자격은 라우트 정책(community.write)이 이미 보았다 — Guest 는 여기 오지 못한다.

$sql = $on
    ? <<<'SQL'
        INSERT INTO community.comment_reactions (comment_id, user_id, kind)
        VALUES (CAST(:cid AS bigint), CAST(:me AS bigint), 'helpful') ON CONFLICT DO NOTHING
        SQL
    : <<<'SQL'
        DELETE FROM community.comment_reactions
         WHERE comment_id = CAST(:cid AS bigint) AND user_id = CAST(:me AS bigint) AND kind = 'helpful'
        SQL;
$db = Db::instance();
$db->run($sql, [':cid' => (int) $comment['id'], ':me' => (int) $user['id']]);

$sqlCount = <<<'SQL'
    SELECT count(*) AS n FROM community.comment_reactions WHERE comment_id = CAST(:cid AS bigint) AND kind = 'helpful'
    SQL;
$n = $db->run($sqlCount, [':cid' => (int) $comment['id']])->fetch();
Http::json(['comment' => ['id' => (string) $params[0], 'helped' => $on, 'helpful' => is_array($n) ? (int) $n['n'] : 0]]);
