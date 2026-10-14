<?php
declare(strict_types=1);

/**
 * src/community/handlers/report_reveal.php — POST /community/api/reports/{id}/reveal (정책: role:Admin + 재인증)
 *
 * 요구사항: 사이트 Q:F-12 카페 C1 · 커뮤니티 ARCHITECTURE 25.5 「작성자 확인」 · cafe-plan CF-7.
 *
 * 받는 값 `{reason}` — 평문 20~500자(왜 확인하는가). 신고 id 로만 부른다 — 신고가 없는 익명 글을 확인하는 창구는 없다.
 * 판정 `CommunityAuthz::decideAnonReveal` = 관리자 + **그 신고의 대상이 익명게시판** + 15분 재인증 신선(라우트 정책이 먼저 막고
 * 판정이 세션의 `reauth_fresh` 를 한 번 더 본다).
 * 결과 200 `{author:{username, display_name}}` — **이 응답 하나에만**. 저장 0 · 쓴 사람 · 신고자에게 알림 0 ·
 * 감사 `anon.reveal` allow(대상 · 신고 id · 사유) / deny(이유 코드). 같은 관리자가 같은 대상을 다시 확인해도 매번 감사.
 * 응답은 캐시하지 않는다(Http::json 의 no-store).
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params [0] = 신고 UUID
 */

$me = (int) $user['id'];

$body = Http::jsonBody();
if (array_diff(array_keys($body), ['reason']) !== []) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}

$sqlReport = <<<'SQL'
    SELECT r.id, r.public_id, r.comment_id, p.public_id AS page_public_id, c.author_id AS comment_author_id
      FROM community.reports r
      JOIN community.pages p ON p.id = r.page_id
      LEFT JOIN community.comments c ON c.id = r.comment_id
     WHERE r.public_id = CAST(:rid AS uuid)
    SQL;
$report = Db::instance()->run($sqlReport, [':rid' => (string) $params[0]])->fetch();
if (!is_array($report)) {
    Http::error(404, Http::MSG_NOT_FOUND);
}
// 대상의 원래 행(가린 행이 아니다) — 관리자 몫
$row = community_page_row((string) $report['page_public_id'], $me);
if ($row === null) {
    Http::error(404, Http::MSG_NOT_FOUND);
}
$pageId    = (int) $row['id'];
$pageTitle = community_target_title((string) $row['title']);
$baseDetail = ['page_title' => $pageTitle, 'report_id' => (int) $report['id'],
               'comment_id' => $report['comment_id'] === null ? null : (int) $report['comment_id']];

$deny = static function (string $reason, int $status, string $message) use ($pageId, $pageTitle, $baseDetail): void {
    Audit::deny('anon.reveal', $reason, [
        'target_type' => 'page',
        'target_id'   => $pageId,
        'target_name' => $pageTitle,
        'detail'      => $baseDetail,
    ]);
    Http::json(['error' => $message, 'reason' => $reason], $status);
    exit;
};

// ─── [권한 ✔] 확인할 수 있나 ───────────────────────────────────
[$may, $code] = CommunityAuthz::decideAnonReveal($user, $row);
if (!$may) {
    if ($code === CommunityAuthz::CODE_REAUTH) {
        $deny($code, 403, Http::MSG_REAUTH_REQUIRED);
    }
    if ($code === CommunityAuthz::CODE_NOT_ANON_BOARD) {
        $deny($code, 400, '익명게시판의 글과 댓글만 쓴 사람을 확인할 수 있어요');
    }
    $deny(CommunityAuthz::CODE_ROLE_NOT_PERMITTED, 403, Http::MSG_FORBIDDEN);
}

// ─── 사유 20~500자 ────────────────────────────────────────────
$reasonText = community_clean_plain($body['reason'] ?? null, 500);
if ($reasonText === null || iconv_strlen($reasonText, 'UTF-8') < 20) {
    $deny('bad_reason', 400, '확인하는 이유를 20자 이상 적어 주세요');
}

// ─── 쓴 사람 — 댓글 신고면 그 댓글을 쓴 사람, 글 신고면 글쓴이(게시판 글) · 공간 주인(게시된 개인 페이지) ───
$authorId = $report['comment_id'] !== null
    ? (int) $report['comment_author_id']
    : ((string) $row['space_kind'] === 'board' ? (int) $row['author_id'] : (int) $row['space_owner_user_id']);
$author = community_user_by_id($authorId);
if ($author === null) {
    Http::error(404, Http::MSG_NOT_FOUND);
}

// 감사 먼저 — 기록에 실패하면 이름을 내보내지 않는다(Audit 이 예외 → 500)
Audit::allow('anon.reveal', [
    'target_type' => 'page',
    'target_id'   => $pageId,
    'target_name' => $pageTitle,
    'detail'      => $baseDetail + ['granted_by' => $code, 'reason' => $reasonText],
]);

Http::json(['author' => [
    'username'     => (string) $author['username'],
    'display_name' => ($author['display_name'] ?? null) === null ? null : (string) $author['display_name'],
]]);
