<?php
declare(strict_types=1);

/**
 * src/community/handlers/reports_create.php — POST /community/api/reports (정책: authenticated)
 *
 * 요구사항: 사이트 Q:F-12 카페 C1 · 커뮤니티 ARCHITECTURE 25.5 「신고」 · cafe-plan CF-7.
 *
 * 받는 값 (JSON, 허용 키 밖이면 400)
 *   page_id      대상 글 UUID (필수)
 *   comment_id   댓글 신고면 그 댓글 UUID (선택 — 같은 글의 댓글이어야 한다)
 *   reason_kind  abuse · defamation · privacy · spam · other (필수)
 *   detail       설명 (선택 — 평문 1~500자, 제어 · 서식 문자 400)
 *
 * 검사 순서 [1] 모양 → [권한 ✔] `CommunityAuthz::decideReportCreate`(손님 403 · 못 보는 대상 404 · 카페 글 아님 400 · 자기 것 400)
 *   → [2] 트랜잭션: 신고한 사람 단위 잠금(같은 사람의 신고를 한 줄로) → 하루(24시간) 20건 상한 429 + 감사 deny → 같은 대상 열린 신고 409
 *   → INSERT → 감사 `report.create`(익명 대상이면 25.6 표식) → 201 {report:{id}}.
 * 신고자는 관리자만 본다(신고함). 응답에 대상 쓴 사람 · 다른 신고 수를 싣지 않는다.
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params
 */

const COMMUNITY_REPORT_DAILY_MAX = 20;
const COMMUNITY_REPORT_REASONS   = ['abuse', 'defamation', 'privacy', 'spam', 'other'];
if (!defined('COMMUNITY_ADVISORY_LOCK_SQL')) {
    define('COMMUNITY_ADVISORY_LOCK_SQL', <<<'SQL'
        SELECT pg_advisory_xact_lock(CAST(:ns AS integer), CAST(:uid AS integer))
        SQL);
}

$db = Db::instance();
$me = (int) $user['id'];

// ─── [1] 모양 ─────────────────────────────────────────────────
$body = Http::jsonBody();
if (array_diff(array_keys($body), ['page_id', 'comment_id', 'reason_kind', 'detail']) !== []) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}
$uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
$pagePid = $body['page_id'] ?? null;
$commentPid = $body['comment_id'] ?? null;
if (!is_string($pagePid) || preg_match($uuidRe, $pagePid) !== 1
    || ($commentPid !== null && (!is_string($commentPid) || preg_match($uuidRe, $commentPid) !== 1))) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}
$reasonKind = $body['reason_kind'] ?? null;
if (!is_string($reasonKind) || !in_array($reasonKind, COMMUNITY_REPORT_REASONS, true)) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}
$detail = null;
if (array_key_exists('detail', $body) && $body['detail'] !== null && $body['detail'] !== '') {
    $detail = community_clean_plain($body['detail'], 500);
    if ($detail === null) {
        Http::error(400, Http::MSG_BAD_REQUEST);
    }
}

// ─── [권한 ✔] 신고할 수 있나 — 못 보는 대상은 없는 것과 같은 404 ───
$row = community_page_row($pagePid, $me);
if ($row === null) {
    Http::error(404, Http::MSG_NOT_FOUND);
}
$comment = null;
if ($commentPid !== null) {
    $comment = community_comment_row($commentPid);
    if ($comment === null) {
        Http::error(404, Http::MSG_NOT_FOUND);
    }
}
[$may, $code] = CommunityAuthz::decideReportCreate($user, $row, $comment);
if (!$may) {
    error_log('[tbox] request_id=' . TBOX_REQUEST_ID . ' community report.create deny code=' . $code);
    if ($code === CommunityAuthz::CODE_ROLE_NOT_PERMITTED) {
        Http::error(403, Http::MSG_FORBIDDEN);
    }
    if ($code === CommunityAuthz::CODE_NOT_CAFE) {
        Http::json(['error' => '카페 게시판의 글과 댓글만 신고할 수 있어요', 'reason' => $code], 400);
        exit;
    }
    if ($code === CommunityAuthz::CODE_OWN_TARGET) {
        Http::json(['error' => '내가 쓴 글이나 댓글은 신고할 수 없어요', 'reason' => $code], 400);
        exit;
    }
    Http::error(404, Http::MSG_NOT_FOUND);
}

$pageId    = (int) $row['id'];
$pageTitle = community_target_title((string) $row['title']);
$commentId = $comment === null ? null : (int) $comment['id'];

// ─── [2] 저장 ─────────────────────────────────────────────────
$db->begin();
try {
    // 같은 사람의 신고를 한 줄로 세운다(상한 · 중복 확인과 INSERT 사이에 끼어들기 0) — 열쇠는 고정 이름공간 + 사람 번호.
    $db->run(COMMUNITY_ADVISORY_LOCK_SQL, [':ns' => 25005, ':uid' => $me]);
    $sqlCount = <<<'SQL'
        SELECT count(*) FROM community.reports
         WHERE reporter_id = CAST(:me AS bigint) AND created_at > now() - interval '24 hours'
        SQL;
    if ((int) $db->run($sqlCount, [':me' => $me])->fetchColumn() >= COMMUNITY_REPORT_DAILY_MAX) {
        $db->rollback();
        Audit::deny('report.create', 'rate_limited', [
            'target_type' => 'page',
            'target_id'   => $pageId,
            'target_name' => $pageTitle,
            'detail'      => ['page_title' => $pageTitle, 'limit' => COMMUNITY_REPORT_DAILY_MAX],
        ]);
        Http::json(['error' => '오늘은 더 신고할 수 없어요. 내일 다시 해 주세요', 'reason' => 'rate_limited'], 429);
        exit;
    }
    $sqlOpen = <<<'SQL'
        SELECT 1 FROM community.reports
         WHERE reporter_id = CAST(:me AS bigint) AND page_id = CAST(:pid AS bigint)
           AND comment_id IS NOT DISTINCT FROM CAST(:cid AS bigint) AND status = 'open'
        SQL;
    if (is_array($db->run($sqlOpen, [':me' => $me, ':pid' => $pageId, ':cid' => $commentId])->fetch())) {
        $db->rollback();
        Http::json(['error' => '이미 신고한 글이에요. 관리자가 확인하고 있어요', 'reason' => 'already_reported'], 409);
        exit;
    }
    $sqlInsert = <<<'SQL'
        INSERT INTO community.reports (page_id, comment_id, reporter_id, reason_kind, detail)
        VALUES (CAST(:pid AS bigint), CAST(:cid AS bigint), CAST(:me AS bigint), :kind, :detail)
        RETURNING id, public_id
        SQL;
    $ins = $db->run($sqlInsert, [
        ':pid' => $pageId, ':cid' => $commentId, ':me' => $me, ':kind' => $reasonKind, ':detail' => $detail,
    ])->fetch();
    if (!is_array($ins)) {
        throw new RuntimeException('신고를 남기지 못했습니다');
    }
    Audit::allow('report.create', [
        'target_type' => 'page',
        'target_id'   => $pageId,
        'target_name' => $pageTitle,
        // 설명 원문은 감사에 남기지 않는다(신고 행에만) — 종류 · 댓글 여부 · 번호만
        'detail'      => community_anon_audit_detail($row, [
            'granted_by'  => $code,
            'page_title'  => $pageTitle,
            'report_id'   => (int) $ins['id'],
            'reason_kind' => $reasonKind,
            'comment_id'  => $commentId,
        ]),
    ]);
    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    if ($e instanceof PDOException && $e->getCode() === '23505') {
        Http::json(['error' => '이미 신고한 글이에요. 관리자가 확인하고 있어요', 'reason' => 'already_reported'], 409);
        exit;
    }
    throw $e;
}

Http::json(['report' => ['id' => (string) $ins['public_id']]], 201);
