<?php
declare(strict_types=1);

/**
 * src/community/handlers/comments_list.php — GET /community/api/pages/{id}/comments (정책: community.read)
 *
 * 요구사항: 커뮤니티 PRD F-21(댓글 2.0), 지시서 5-D 「판정」 — 댓글은 **페이지를 따른다**.
 *
 * 관문: `community_history_gate` — 그 페이지를 볼 수 없으면 403·404(감사는 그 관문이 남긴다).
 * 게시판 등급으로만 보는 사람에게는 **게시 뒤** 댓글만(5-C #9 와 같은 자르기 — `since`).
 *
 * 돌려주는 것 — 살아 있는 댓글 + 지웠지만 살아 있는 답글이 달린 첫 댓글(본문 없이 「지운 댓글」 자리).
 *   숨긴 댓글(결정 D-3)은 **본문 없이** 「숨긴 댓글」 자리만 — 쓴 사람과 숨길 수 있는 사람(관리자)에게만 본문.
 *   항목: id · parent_id · block_id · quote · body(또는 null) · hidden · deleted · 쓴 사람(아이디·이름) ·
 *         시각 · 고쳤나 · 해결됨 · 도움됐어요 수 · 내가 눌렀나 · can_edit · can_hide · can_resolve.
 * 본문은 평문 그대로 — 화면이 `textContent` + `white-space: pre-wrap` 으로만 그린다(PRD S-03).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params [0] = 페이지 public_id (UUID)
 */

$db   = Db::instance();
$me   = (int) $user['id'];
$gate = community_history_gate($user, (string) $params[0]);
$row  = $gate['row'];

[$canComment] = CommunityAuthz::decideCommentCreate($user, $row);
[$canHide]    = CommunityAuthz::decideCommentHide($user, $row);

$sql = <<<'SQL'
    SELECT c.id, c.public_id, c.parent_comment_id, par.public_id AS parent_public_id,
           c.block_id, c.quote, c.body, c.author_id, c.created_at, c.updated_at,
           c.resolved_at, c.hidden_at, c.deleted_at,
           u.username, u.display_name,
           (SELECT count(*) FROM community.comment_reactions r WHERE r.comment_id = c.id AND r.kind = 'helpful') AS helpful,
           EXISTS (SELECT 1 FROM community.comment_reactions r
                    WHERE r.comment_id = c.id AND r.kind = 'helpful' AND r.user_id = CAST(:me AS bigint)) AS mine
      FROM community.comments c
      JOIN public.users u ON u.id = c.author_id
      LEFT JOIN community.comments par ON par.id = c.parent_comment_id
     WHERE c.page_id = CAST(:pid AS bigint)
       AND c.created_at >= coalesce(CAST(:since AS timestamptz), '-infinity'::timestamptz)
       -- 부모가 잘린(게시 전) 답글도 뺀다 — 목록에 없는 부모 번호가 새지 않게(독립 검토)
       AND (par.id IS NULL OR par.created_at >= coalesce(CAST(:since2 AS timestamptz), '-infinity'::timestamptz))
       AND (c.deleted_at IS NULL
            OR EXISTS (SELECT 1 FROM community.comments k WHERE k.parent_comment_id = c.id AND k.deleted_at IS NULL))
     ORDER BY c.created_at, c.id
     LIMIT 500
    SQL;

$rows = $db->run($sql, [':me' => $me, ':pid' => (int) $row['id'], ':since' => $gate['since'], ':since2' => $gate['since']])->fetchAll();

/* 사이트 Q:F-12 카페 C1 (ARCH 25.4) — 익명게시판 글이면 쓴 사람을 이름표로(글쓴이 · 익명N). 이름표 지도는 **질의 하나**(N+1 0).
   판정(고치기 · 해결 · 숨김)은 위아래 그대로 원래 행(`author_id`)으로 한다 — 가린 값으로 판정하지 않는다. */
$anon     = community_page_is_anon($row);
$labelMap = $anon
    ? community_anon_label_map((int) $row['id'], (int) $row['author_id'], array_map(static fn (array $c): int => (int) $c['author_id'], $rows))
    : [];

$out = [];
foreach ($rows as $c) {
    $deleted  = $c['deleted_at'] !== null;
    $hidden   = $c['hidden_at'] !== null;
    $isAuthor = (int) $c['author_id'] === $me;
    [$canEdit]    = CommunityAuthz::decideCommentChange($user, $c);
    [$canResolve] = $c['parent_comment_id'] === null
        ? CommunityAuthz::decideCommentResolve($user, $row, $c) : [false, ''];
    // 본문을 보일지 — 지운 것은 누구에게도, 숨긴 것은 쓴 사람·숨길 수 있는 사람에게만.
    $showBody = !$deleted && (!$hidden || $isAuthor || $canHide);
    $out[] = [
        'id'          => (string) $c['public_id'],
        'parent_id'   => $c['parent_public_id'] === null ? null : (string) $c['parent_public_id'],
        // 숨긴 댓글은 「숨긴 댓글입니다」 한 줄만 — 앵커·쓴 사람·수도 본문을 볼 수 있는 사람에게만(결정 D-3)
        'block_id'    => !$showBody || $c['block_id'] === null ? null : (string) $c['block_id'],
        'quote'       => $showBody && $c['quote'] !== null ? (string) $c['quote'] : null,
        'body'        => $showBody ? (string) $c['body'] : null,
        'hidden'      => $hidden,
        'deleted'     => $deleted,
        'author'      => !$showBody ? null : ($anon
            ? community_anon_person((int) $row['id'], (int) $row['author_id'], (int) $c['author_id'], $me, $labelMap)
            : [
                'username'     => (string) $c['username'],
                'display_name' => $c['display_name'] === null ? null : (string) $c['display_name'],
            ]),
        'created_at'  => (string) $c['created_at'],
        'edited'      => !$deleted && (string) $c['updated_at'] !== (string) $c['created_at'],
        'resolved'    => $showBody && $c['resolved_at'] !== null,
        'helpful'     => $showBody ? (int) $c['helpful'] : 0,
        'helped'      => $showBody && $c['mine'] === true,
        'can_edit'    => $canEdit && !$hidden,
        'can_hide'    => $canHide && !$deleted,
        'can_resolve' => $canResolve,
    ];
}

Http::json([
    'comments'    => $out,
    'count'       => count(array_filter($out, static fn (array $c): bool => !$c['deleted'])),
    'can_comment' => $canComment,
]);
