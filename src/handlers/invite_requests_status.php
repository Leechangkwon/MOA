<?php
declare(strict_types=1);
/**
 * src/handlers/invite_requests_status.php — POST /api/invite-requests/status (정책 public · 사이트 H2 · 기획 ../docs/invite-request-plan.md 2 ④ · 3장)
 *
 * 요청한 사람이 확인 주소(`/tbox/request-invite/status#code=…`)를 열면 화면이 `#` 뒤 코드를 **POST 본문**으로 보낸다(주소 쿼리 · 로그 0).
 * 확인 코드는 소지자 권한이다 — 그 요청의 초대 링크를 받는 열쇠. 막는 것:
 *   가입과 같은 CSRF · IP 마다 1분 20회(Member::rate) · 키는 `code` 하나 · 64자리 16진이 아니거나 없는 코드는 같은 404 ·
 *   찾기는 sha256 으로만(원문 저장 0).
 * 답 `{status}`: open(검토 중) · rejected(메모는 주지 않는다) · joined(이 요청의 초대로 가입을 마침) ·
 *   approved + invite_path — **이때** 초대를 만든다(Member::issueInvite — 1회용 · 7일 · 승인 때 고른 팀 · 이름표 「초대 요청 #번호」 ·
 *   만든 사람 = 승인한 관리자). 한 트랜잭션 · 요청 행 잠금 · 앞 초대는 revoked_at · 요청 하나에 세 번까지(넷째는 409).
 * 감사: invite_request.issue(대상 「초대 요청 #번호」 · 초대 번호 · 몇 번째). 초대 코드는 응답의 invite_path(`#` 뒤)에만.
 */
require_once __DIR__.'/../Member.php';
Member::requireJoinCsrf();
Member::rate('invite-request-status', Http::clientIp() ?? 'unknown', 20);
$body = Http::jsonBody(1024);
if (array_keys($body) !== ['code']) Http::error(400, Http::MSG_BAD_REQUEST);
$code = $body['code'];
unset($body);
$notFound = '요청 확인 주소가 올바르지 않아요.';
if (!is_string($code) || preg_match('/\A[a-f0-9]{64}\z/', $code) !== 1) Http::error(404, $notFound);
$hash = hash('sha256', $code);
unset($code);

$db = Db::instance();
$db->begin();
try {
    $req = $db->run(<<<'SQL'
        SELECT r.id, r.status, r.team_id, r.decided_by, r.invite_id, r.issued_count,
               i.used_count AS invite_used, i.max_uses AS invite_max
          FROM invite_requests r
          LEFT JOIN member_invites i ON i.id = r.invite_id
         WHERE r.status_hash = :hash
           FOR UPDATE OF r
        SQL, [':hash' => $hash])->fetch();
    if (!$req) {
        $db->rollback();
        Http::error(404, $notFound);
    }
    $id = (int) $req['id'];
    $target = ['target_type' => 'system', 'target_id' => $id, 'target_name' => '초대 요청 #' . $id];
    if ($req['status'] !== 'approved') {
        $db->commit();
        Http::json(['status' => $req['status'] === 'rejected' ? 'rejected' : 'open']);
        return;
    }
    if ($req['invite_id'] !== null && $req['invite_used'] !== null && (int) $req['invite_used'] >= (int) $req['invite_max']) {
        $db->commit();
        Http::json(['status' => 'joined']);
        return;
    }
    if ((int) $req['issued_count'] >= 3) {
        $db->rollback();
        Audit::deny('invite_request.issue', 'issue_limit', $target);
        Http::error(409, '초대 링크를 이미 세 번 받았어요 — 관리자에게 문의해 주세요.');
    }
    $team = $req['team_id'] === null ? null : (int) $req['team_id'];
    if (!Member::activeTeam($team)) {
        $db->rollback();
        Http::error(409, '초대할 팀을 지금 쓸 수 없어요 — 관리자에게 문의해 주세요.');
    }
    $revoked = false;
    if ($req['invite_id'] !== null) {
        $revoked = $db->run('UPDATE member_invites SET revoked_at=now() WHERE id=:id AND revoked_at IS NULL',
            [':id' => (int) $req['invite_id']])->rowCount() > 0;
    }
    $invite = Member::issueInvite((int) $req['decided_by'], '초대 요청 #' . $id, $team, 1, 7);
    $db->run('UPDATE invite_requests SET invite_id=:invite, issued_count=issued_count+1 WHERE id=:id AND status=\'approved\'',
        [':invite' => $invite['id'], ':id' => $id]);
    Audit::allow('invite_request.issue', $target + ['detail' => ['invite_id' => $invite['id'], 'issued_count' => (int) $req['issued_count'] + 1,
        'revoked_previous' => $revoked, 'target_team' => $team]]);
    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollback();
    throw $e;
}
Http::json(['status' => 'approved', 'invite_path' => $invite['path']]);
