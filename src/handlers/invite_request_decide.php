<?php
declare(strict_types=1);
/**
 * src/handlers/invite_request_decide.php — POST /api/invite-requests/{num}/decide (정책 user.manage — 재인증 관문 [3-2] 자동 · 사이트 H2 · 기획 3장)
 *
 * 본문 `{action: "approve"|"reject", team_id?, note?}` — 승인은 팀을 고를 수 있고(활성 팀만 · 없으면 400) · 반려는 팀 0.
 * 메모(300자 · 관리자만 봄)는 요청 표에만 — 감사에는 「메모가 있나」 만. 열린 요청만 결정한다(이미 결정 409 · 없음 404).
 * 초대 링크는 여기서 만들지 않는다 — 요청한 사람이 확인 주소를 열 때 만든다(invite_requests_status.php · 만든 사람 = 이 관리자).
 */
require_once __DIR__.'/../Member.php';
$id = (int) $params[0];
$body = Http::jsonBody(4096);
if (array_diff(array_keys($body), ['action', 'team_id', 'note']) !== []) Http::error(400, Http::MSG_BAD_REQUEST);
$action = $body['action'] ?? null;
$team = $body['team_id'] ?? null;
$note = $body['note'] ?? null;
if ($action !== 'approve' && $action !== 'reject') Http::error(400, Http::MSG_BAD_REQUEST);
if ($team !== null && (!is_int($team) || $team < 1 || $action !== 'approve')) Http::error(400, Http::MSG_BAD_REQUEST);
if ($note !== null) {
    if (!is_string($note) || preg_match('//u', $note) !== 1) Http::error(400, Http::MSG_BAD_REQUEST);
    $note = str_replace("\r\n", "\n", $note);
    $bad = preg_match('/[^\P{C}\n]/u', $note) === 1;   // 제어 · 서식 문자는 자르기 전에 본다
    $note = trim($note);
    if ($note === '' && !$bad) {
        $note = null;
    } elseif ($bad || iconv_strlen($note, 'UTF-8') > 300) {
        Http::error(400, '메모는 300자까지 쓸 수 있어요.');
    }
}
if (!Member::activeTeam($team)) Http::error(400, '활성 팀을 골라 주세요.');
$db = Db::instance();
$db->begin();
try {
    $row = $db->run('SELECT id, status FROM invite_requests WHERE id=:id FOR UPDATE',
        [':id' => $id])->fetch();
    if (!$row) {
        $db->rollback();
        Http::error(404, '초대 요청을 찾을 수 없습니다.');
    }
    if ($row['status'] !== 'open') {
        $db->rollback();
        Http::error(409, '이미 처리한 초대 요청입니다.');
    }
    $status = $action === 'approve' ? 'approved' : 'rejected';
    $db->run("UPDATE invite_requests SET status=:status, team_id=:team, decided_by=:by, decision_note=:note, decided_at=now() WHERE id=:id AND status='open'",
        [':status' => $status, ':team' => $team, ':by' => (int) $user['id'], ':note' => $note, ':id' => $id]);
    Audit::allow('invite_request.decide', ['target_type' => 'system', 'target_id' => $id, 'target_name' => '초대 요청 #' . $id,
        'detail' => ['decision' => $action, 'target_team' => $team, 'note_set' => $note !== null]], $action);
    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollback();
    throw $e;
}
Http::json(['ok' => true, 'status' => $status]);
