<?php
declare(strict_types=1);

/**
 * src/community/handlers/board_requests_list.php — GET /community/api/board-requests?status=open|created|rejected (정책: role:Admin + 재인증)
 *
 * 요구사항: 사이트 Q:F-12 카페 C1 · 커뮤니티 ARCHITECTURE 25.7. 판정은 라우트 정책 + `decideReportManage`(같은 관리자 판정을 한 번 더).
 * 목록 한 질의: 이름 · 쓰임 · 익명 여부 · 상태 · 요청자 · 처리자 · 메모 · 만든 게시판 번호.
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params
 */

[$may] = CommunityAuthz::decideReportManage($user);
if (!$may) {
    Http::error(403, Http::MSG_FORBIDDEN);
}
$status = Http::queryChoice('status', ['open', 'created', 'rejected'], 'open');

$sql = <<<'SQL'
    SELECT r.public_id, r.name, r.purpose, r.anonymous, r.status, r.created_at, r.decided_at, r.decision_note, r.board_id,
           q.username AS requester_name, q.display_name AS requester_display_name,
           d.username AS decider_name, d.display_name AS decider_display_name
      FROM community.board_requests r
      JOIN public.users q ON q.id = r.requester_id
      LEFT JOIN public.users d ON d.id = r.decided_by
     WHERE r.status = :status
     ORDER BY r.created_at DESC, r.id DESC
     LIMIT 100
    SQL;

$items = [];
foreach (Db::instance()->run($sql, [':status' => $status])->fetchAll() as $r) {
    $items[] = [
        'id'         => (string) $r['public_id'],
        'name'       => (string) $r['name'],
        'purpose'    => (string) $r['purpose'],
        'anonymous'  => $r['anonymous'] === true,
        'status'     => (string) $r['status'],
        'created_at' => (string) $r['created_at'],
        'decided_at' => $r['decided_at'] === null ? null : (string) $r['decided_at'],
        'note'       => $r['decision_note'] === null ? null : (string) $r['decision_note'],
        'board_id'   => $r['board_id'] === null ? null : (int) $r['board_id'],
        'requester'  => ['username' => (string) $r['requester_name'],
                         'display_name' => $r['requester_display_name'] === null ? null : (string) $r['requester_display_name']],
        'decider'    => $r['decider_name'] === null ? null : ['username' => (string) $r['decider_name'],
                         'display_name' => $r['decider_display_name'] === null ? null : (string) $r['decider_display_name']],
    ];
}
Http::json(['requests' => $items, 'status' => $status]);
