<?php
declare(strict_types=1);
/**
 * src/handlers/invite_requests_list.php — GET /api/invite-requests (정책 user.manage — 재인증 관문 [3-2] 자동 · 사이트 H2 · 기획 3장)
 *
 * 「사용자 관리」 의 「초대 요청」 표. 열린 것 먼저 · 새것 먼저 100건. 확인 코드 해시 · IP 는 내보내지 않는다.
 * 반려 메모는 관리자만 본다(이 창구가 user.manage 다). 글자는 화면이 textContent 로만 그린다(member.js).
 */
$db = Db::instance();
$rows = $db->run(<<<'SQL'
    SELECT r.id, r.name, r.org, r.reason, r.status, r.created_at, r.decided_at, r.team_id, t.name AS team_name,
           r.decision_note, r.issued_count, u.username AS decided_by_name,
           COALESCE(i.used_count >= i.max_uses, false) AS joined
      FROM invite_requests r
      LEFT JOIN teams t ON t.id = r.team_id
      LEFT JOIN users u ON u.id = r.decided_by
      LEFT JOIN member_invites i ON i.id = r.invite_id
     ORDER BY (r.status = 'open') DESC, r.id DESC
     LIMIT 100
    SQL)->fetchAll();
$open = (int) $db->run("SELECT count(*) FROM invite_requests WHERE status = 'open'")->fetchColumn();
$out = [];
foreach ($rows as $r) {
    $out[] = ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'org' => (string) $r['org'], 'reason' => (string) $r['reason'],
        'status' => (string) $r['status'], 'created_at' => (string) $r['created_at'],
        'decided_at' => $r['decided_at'] === null ? null : (string) $r['decided_at'],
        'team_id' => $r['team_id'] === null ? null : (int) $r['team_id'], 'team_name' => $r['team_name'] === null ? null : (string) $r['team_name'],
        'decision_note' => $r['decision_note'] === null ? null : (string) $r['decision_note'], 'issued_count' => (int) $r['issued_count'],
        'decided_by' => $r['decided_by_name'] === null ? null : (string) $r['decided_by_name'], 'joined' => $r['joined'] === true];
}
Http::json(['requests' => $out, 'open_count' => $open]);
