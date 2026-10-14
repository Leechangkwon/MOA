<?php
declare(strict_types=1);

/**
 * src/community/handlers/board_request_decide.php — POST /community/api/board-requests/{id}/decide (정책: role:Admin + 재인증)
 *
 * 요구사항: 사이트 Q:F-12 카페 C1 · 커뮤니티 ARCHITECTURE 25.7.
 *
 * 받는 값 `{action:'create'|'reject', note?, access_level?}`
 *   create  게시판 만들기의 **공용 함수**(`community_board_create` — boards_create.php 와 같은 검사 · 같은 감사 `board.create`)로
 *           카페(`area='cafe'`) 게시판을 만든다 — 이름 = 요청 이름 · 설명 = 쓰임(한 줄 200자) · 종류 = 익명 요청이면 anonymous, 아니면 discussion ·
 *           등급 = access_level(public · internal · admin-only, 기본 internal — 팀 등급은 팀을 고를 자리가 없어 400). 메모는 선택(500자).
 *   reject  메모 **필수**(1~500자).
 * 이미 처리한 요청은 409. 요청자에게 알림 `board_request_done`(페이지 없음). 감사 `board_request.decide`.
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params [0] = 요청 UUID
 */

$db = Db::instance();
$me = (int) $user['id'];

[$may, $code] = CommunityAuthz::decideReportManage($user);
if (!$may) {
    Http::error(403, Http::MSG_FORBIDDEN);
}

$body = Http::jsonBody();
if (array_diff(array_keys($body), ['action', 'note', 'access_level']) !== []) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}
$action = $body['action'] ?? null;
if (!is_string($action) || !in_array($action, ['create', 'reject'], true)) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}
$note = null;
if (array_key_exists('note', $body) && $body['note'] !== null && $body['note'] !== '') {
    $note = community_clean_plain($body['note'], 500);
    if ($note === null) {
        Http::error(400, Http::MSG_BAD_REQUEST);
    }
}
if ($action === 'reject' && $note === null) {
    Http::json(['error' => '반려하는 이유를 적어 주세요(500자까지)', 'reason' => 'note_required'], 400);
    exit;
}
$level = $body['access_level'] ?? 'internal';
if (!is_string($level) || !in_array($level, ['public', 'internal', 'admin-only'], true) || ($action === 'reject' && array_key_exists('access_level', $body))) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}

$sqlRequest = <<<'SQL'
    SELECT id, public_id, requester_id, name, purpose, anonymous, status
      FROM community.board_requests WHERE public_id = CAST(:rid AS uuid)
     FOR UPDATE
    SQL;

$db->begin();
try {
    $req = $db->run($sqlRequest, [':rid' => (string) $params[0]])->fetch();
    if (!is_array($req)) {
        $db->rollback();
        Http::error(404, Http::MSG_NOT_FOUND);
    }
    if ((string) $req['status'] !== 'open') {
        $db->rollback();
        Http::json(['error' => '이미 처리한 요청이에요', 'reason' => 'already_decided'], 409);
        exit;
    }
    $boardId = null;
    if ($action === 'create') {
        $oneLine = (string) preg_replace('/\s+/u', ' ', (string) $req['purpose']);
        $description = iconv_strlen($oneLine, 'UTF-8') > 200 ? (string) iconv_substr($oneLine, 0, 200, 'UTF-8') : $oneLine;
        $boardId = community_board_create($user, [
            'name'         => (string) $req['name'],
            'description'  => trim($description),
            'access_level' => $level,
            'board_kind'   => $req['anonymous'] === true ? 'anonymous' : 'discussion',
            'area'         => 'cafe',
        ]);
    }
    $sqlDecide = <<<'SQL'
        UPDATE community.board_requests
           SET status = :status, decided_by = CAST(:me AS bigint), decision_note = :note,
               board_id = CAST(:bid AS bigint), decided_at = now()
         WHERE id = CAST(:id AS bigint) AND status = 'open'
        SQL;
    $db->run($sqlDecide, [
        ':status' => $action === 'create' ? 'created' : 'rejected',
        ':me' => $me, ':note' => $note, ':bid' => $boardId, ':id' => (int) $req['id'],
    ]);
    $db->run(
        "INSERT INTO community.notifications (user_id, kind, actor_id) VALUES (CAST(:uid AS bigint), 'board_request_done', NULL)",
        [':uid' => (int) $req['requester_id']]
    );
    Audit::allow('board_request.decide', [
        'target_type' => 'space',
        'target_id'   => $boardId,
        'target_name' => (string) $req['name'],
        'detail'      => ['granted_by' => $code, 'request_id' => (int) $req['id'], 'decision' => $action, 'board_id' => $boardId],
    ]);
    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    throw $e;
}

Http::json(['request' => ['id' => (string) $req['public_id'], 'status' => $action === 'create' ? 'created' : 'rejected', 'board_id' => $boardId]]);
