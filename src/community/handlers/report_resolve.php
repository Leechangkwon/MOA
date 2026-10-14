<?php
declare(strict_types=1);

/**
 * src/community/handlers/report_resolve.php — POST /community/api/reports/{id}/resolve (정책: role:Admin + 재인증)
 *
 * 요구사항: 사이트 Q:F-12 카페 C1 · 커뮤니티 ARCHITECTURE 25.5 「처리」.
 *
 * 받는 값 `{action:'hide'|'reject', note}` — note 는 평문 1~500자 **필수**(없거나 비면 400).
 *   hide   대상(글 · 댓글)을 숨긴다 — 쓰기는 지금 숨김 창구와 **같은 공용 함수**(`community_page_hide_write` ·
 *          `community_comment_hide_write` — 같은 처리 이력 · 같은 감사). 같은 대상의 열린 신고는 모두 `hidden` ·
 *          쓴 사람에게 알림 `moderated`(actor 없음 — 화면은 「관리자가 숨겼어요 — <메모>」, 메모는 신고 행에서).
 *   reject 그 신고 하나만 `rejected`(메모).
 * 판정은 `CommunityAuthz::decideReportManage`(라우트 정책과 같은 뜻을 한 번 더). 이미 처리한 신고는 409.
 * 감사 `report.resolve`(동작 · 메모 길이 · 함께 닫힌 신고 수 — 메모 원문은 신고 행에만).
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params [0] = 신고 UUID
 */

$db = Db::instance();
$me = (int) $user['id'];

[$may, $code] = CommunityAuthz::decideReportManage($user);
if (!$may) {
    Http::error(403, Http::MSG_FORBIDDEN);
}

$body = Http::jsonBody();
if (array_diff(array_keys($body), ['action', 'note']) !== []) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}
$action = $body['action'] ?? null;
if (!is_string($action) || !in_array($action, ['hide', 'reject'], true)) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}
$note = community_clean_plain($body['note'] ?? null, 500);
if ($note === null) {
    Http::json(['error' => '처리 메모를 적어 주세요(500자까지)', 'reason' => 'note_required'], 400);
    exit;
}

$sqlReport = <<<'SQL'
    SELECT r.id, r.public_id, r.page_id, r.comment_id, r.status, p.public_id AS page_public_id, c.public_id AS comment_public_id
      FROM community.reports r
      JOIN community.pages p ON p.id = r.page_id
      LEFT JOIN community.comments c ON c.id = r.comment_id
     WHERE r.public_id = CAST(:rid AS uuid)
     FOR UPDATE OF r
    SQL;

$db->begin();
try {
    $report = $db->run($sqlReport, [':rid' => (string) $params[0]])->fetch();
    if (!is_array($report)) {
        $db->rollback();
        Http::error(404, Http::MSG_NOT_FOUND);
    }
    if ((string) $report['status'] !== 'open') {
        $db->rollback();
        Http::json(['error' => '이미 처리한 신고예요', 'reason' => 'already_resolved'], 409);
        exit;
    }
    // 대상의 **원래 행**(관리자 몫으로 읽은 행 — 가린 행이 아니다)
    $row = community_page_row((string) $report['page_public_id'], $me);
    if ($row === null) {
        $db->rollback();
        Http::error(404, Http::MSG_NOT_FOUND);
    }
    $pageTitle = community_target_title((string) $row['title']);
    $commentId = $report['comment_id'] === null ? null : (int) $report['comment_id'];
    $closed = 1;

    if ($action === 'hide') {
        $writer = null;
        if ($commentId !== null) {
            $comment = community_comment_row((string) $report['comment_public_id']);
            if ($comment === null) {
                throw new RuntimeException('신고된 댓글을 찾지 못했습니다');
            }
            community_comment_hide_write($row, $comment, true, $me, $code);
            $writer = (int) $comment['author_id'];
        } else {
            // 게시판에 걸린 글만 숨길 목록이 있다(카페 게시판 글만 신고가 들어오므로 언제나 있다 — 없으면 400)
            if (CommunityAuthz::boardOfRow($row) === null) {
                $db->rollback();
                Http::json(['error' => '게시판에 걸린 글만 숨길 수 있습니다', 'reason' => CommunityAuthz::CODE_SPACE_GOVERNED], 400);
                exit;
            }
            community_page_hide_write($row, true, $me, $code);
            $writer = (string) $row['space_kind'] === 'board' ? (int) $row['author_id'] : (int) $row['space_owner_user_id'];
        }
        $sqlClose = <<<'SQL'
            UPDATE community.reports
               SET status = 'hidden', resolved_by = CAST(:me AS bigint), resolution_note = :note, resolved_at = now()
             WHERE page_id = CAST(:pid AS bigint) AND comment_id IS NOT DISTINCT FROM CAST(:cid AS bigint) AND status = 'open'
            SQL;
        $closed = $db->run($sqlClose, [':me' => $me, ':note' => $note, ':pid' => (int) $report['page_id'], ':cid' => $commentId])->rowCount();
        // 쓴 사람에게 「관리자가 숨겼어요」 — actor 없음(누가 숨겼는지 · 누가 신고했는지 싣지 않는다). 볼 수 있을 때만(community_notify).
        if ($writer !== null && $writer > 0) {
            community_notify($writer, 'moderated', $row, $commentId, null);
        }
    } else {
        $sqlReject = <<<'SQL'
            UPDATE community.reports
               SET status = 'rejected', resolved_by = CAST(:me AS bigint), resolution_note = :note, resolved_at = now()
             WHERE id = CAST(:id AS bigint) AND status = 'open'
            SQL;
        $closed = $db->run($sqlReject, [':me' => $me, ':note' => $note, ':id' => (int) $report['id']])->rowCount();
    }

    Audit::allow('report.resolve', [
        'target_type' => 'page',
        'target_id'   => (int) $row['id'],
        'target_name' => $pageTitle,
        'detail'      => [
            'granted_by'  => $code,
            'page_title'  => $pageTitle,
            'report_id'   => (int) $report['id'],
            'resolution'  => $action,
            'comment_id'  => $commentId,
            'closed'      => $closed,
            'note_chars'  => iconv_strlen($note, 'UTF-8'),
        ],
    ]);
    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    throw $e;
}

Http::json(['report' => ['id' => (string) $report['public_id'], 'status' => $action === 'hide' ? 'hidden' : 'rejected'], 'closed' => $closed]);
