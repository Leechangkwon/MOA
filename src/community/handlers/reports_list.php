<?php
declare(strict_types=1);

/**
 * src/community/handlers/reports_list.php — GET /community/api/reports?status=open|hidden|rejected (정책: role:Admin + 재인증)
 *
 * 요구사항: 사이트 Q:F-12 카페 C1 · 커뮤니티 ARCHITECTURE 25.5 「신고함」.
 *
 * 관문은 라우터가 먼저 끝낸다(`role:Admin` → `Authz::REAUTH_POLICIES` 의 15분 재인증). 판정은 같은 뜻의
 * `CommunityAuthz::decideReportManage` 로 한 번 더 본다(역할 문자열 비교 0).
 * 목록 한 질의(N+1 0): 신고자 이름 · 사유 · 설명 · 대상 제목 · 댓글 앞 200자 · 대상 쓴 사람(**익명 대상은 이름표**) ·
 * 같은 대상 신고 수 · 상태 · 처리자 · 메모. 익명 대상의 실제 이름은 작성자 확인(report_reveal.php) 한 길로만 나온다.
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params
 */

[$may] = CommunityAuthz::decideReportManage($user);
if (!$may) {
    Http::error(403, Http::MSG_FORBIDDEN);
}
$status = Http::queryChoice('status', ['open', 'hidden', 'rejected'], 'open');

$sql = <<<'SQL'
    SELECT r.public_id, r.reason_kind, r.detail, r.status, r.created_at, r.resolved_at, r.resolution_note,
           rep.username AS reporter_name, rep.display_name AS reporter_display_name,
           res.username AS resolver_name, res.display_name AS resolver_display_name,
           p.public_id AS page_public_id, p.title, p.author_id AS page_author_id,
           p.hidden_at AS page_hidden_at, p.deleted_at AS page_deleted_at,
           s.kind AS space_kind, s.board_kind AS space_board_kind, s.name AS space_name,
           p.visibility, p.published_board_id, pb.kind AS pub_kind, pb.board_kind AS pub_board_kind, pb.name AS pub_name,
           c.public_id AS comment_public_id, left(c.body, 200) AS comment_excerpt,
           c.hidden_at AS comment_hidden_at, c.deleted_at AS comment_deleted_at,
           t.target_author_id, tu.username AS target_username, tu.display_name AS target_display_name,
           aa.alias_no AS target_alias_no,
           (SELECT count(*) FROM community.reports r2
             WHERE r2.page_id = r.page_id AND r2.comment_id IS NOT DISTINCT FROM r.comment_id) AS same_target_count
      FROM community.reports r
      JOIN community.pages p ON p.id = r.page_id
      JOIN community.spaces s ON s.id = p.space_id
      LEFT JOIN community.spaces pb ON pb.id = p.published_board_id
      LEFT JOIN community.comments c ON c.id = r.comment_id
      JOIN public.users rep ON rep.id = r.reporter_id
      LEFT JOIN public.users res ON res.id = r.resolved_by
      CROSS JOIN LATERAL (
          SELECT CASE WHEN r.comment_id IS NOT NULL THEN c.author_id
                      WHEN s.kind = 'board' THEN p.author_id ELSE s.owner_user_id END AS target_author_id
      ) t
      LEFT JOIN public.users tu ON tu.id = t.target_author_id
      LEFT JOIN community.anon_aliases aa ON aa.page_id = p.id AND aa.user_id = t.target_author_id
     WHERE r.status = :status
     ORDER BY r.created_at DESC, r.id DESC
     LIMIT 100
    SQL;

$items = [];
foreach (Db::instance()->run($sql, [':status' => $status])->fetchAll() as $r) {
    $anon = community_page_is_anon($r);
    $targetAuthor = $r['target_author_id'] === null ? null : (int) $r['target_author_id'];
    $items[] = [
        'id'          => (string) $r['public_id'],
        'status'      => (string) $r['status'],
        'reason_kind' => (string) $r['reason_kind'],
        'detail'      => $r['detail'] === null ? null : (string) $r['detail'],
        'created_at'  => (string) $r['created_at'],
        'resolved_at' => $r['resolved_at'] === null ? null : (string) $r['resolved_at'],
        'note'        => $r['resolution_note'] === null ? null : (string) $r['resolution_note'],
        // 신고자는 관리자만 본다(이 창구가 관리자 전용이다)
        'reporter'    => ['username' => (string) $r['reporter_name'],
                          'display_name' => $r['reporter_display_name'] === null ? null : (string) $r['reporter_display_name']],
        'resolver'    => $r['resolver_name'] === null ? null : ['username' => (string) $r['resolver_name'],
                          'display_name' => $r['resolver_display_name'] === null ? null : (string) $r['resolver_display_name']],
        'target'      => [
            'kind'          => $r['comment_public_id'] === null ? 'page' : 'comment',
            'page_id'       => (string) $r['page_public_id'],
            'title'         => (string) $r['title'],
            'board'         => (string) $r['space_kind'] === 'board' ? (string) $r['space_name'] : ($r['pub_name'] === null ? null : (string) $r['pub_name']),
            'anonymous'     => $anon,
            'comment_id'    => $r['comment_public_id'] === null ? null : (string) $r['comment_public_id'],
            'excerpt'       => $r['comment_excerpt'] === null ? null : (string) $r['comment_excerpt'],
            'hidden'        => $r['comment_public_id'] === null ? $r['page_hidden_at'] !== null : $r['comment_hidden_at'] !== null,
            'deleted'       => $r['comment_public_id'] === null ? $r['page_deleted_at'] !== null : $r['comment_deleted_at'] !== null,
            // 대상 쓴 사람 — 익명 대상은 이름표(글쓴이 · 익명N), 실명 게시판은 아이디 · 이름
            'author'        => $targetAuthor === null ? null : ($anon
                ? ['username' => null,
                   'display_name' => community_anon_label($targetAuthor === (int) $r['page_author_id'],
                       $r['target_alias_no'] === null ? null : (int) $r['target_alias_no']),
                   'anon' => true, 'is_me' => $targetAuthor === (int) $user['id']]
                : ['username' => (string) $r['target_username'],
                   'display_name' => $r['target_display_name'] === null ? null : (string) $r['target_display_name']]),
            'can_reveal'    => $anon,
        ],
        'same_target_count' => (int) $r['same_target_count'],
    ];
}

Http::json(['reports' => $items, 'status' => $status]);
