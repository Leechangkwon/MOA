<?php
declare(strict_types=1);

/**
 * src/community/handlers/pages_hide.php — POST·DELETE /community/api/pages/{id}/hide (정책: community.write)
 *
 * 요구사항: 커뮤니티 PRD 4.1 「게시판 Manager·Admin 은 게시 해제·**숨김**만(내용 편집 불가)」 · F-18,
 *           지시서 5-C 「숨김 = 게시판 Manager·Admin 이 게시판 목록에서 내리는 것(원본 무변경)」.
 *
 *   POST   숨기기     — `hidden_at`·`hidden_by` 를 채운다.
 *   DELETE 숨김 풀기  — 둘을 비운다.
 *
 * 대상은 **게시판에 걸린 것**뿐이다 — 게시판 글, 또는 게시된 개인 페이지. 그 밖은 400 `space_governed`.
 * 자격은 `CommunityAuthz::decideHide` — 그 게시판의 관리자(팀 게시판의 그 팀 Manager · Admin).
 * 쓴 사람이라도 관리자가 아니면 403 이다(쓴 사람은 숨기지 않고 지우거나 게시를 푼다).
 *
 * 숨긴 글은 **게시판 목록·검색에서 빠진다.** 주소로는 원래 판정대로다 — 게시판 글은 쓴 사람과
 * 게시판 관리자만, 게시된 개인 페이지는 원래 범위(`published_from`)와 게시판 관리자만.
 * 원본(본문·제목·판)은 한 글자도 바뀌지 않는다. 「언제 숨겼나」는 처리 이력(prop `hide`)과 감사에 남는다.
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params [0] = 페이지 public_id (UUID)
 */

$db     = Db::instance();
$me     = (int) $user['id'];
$pid    = (string) $params[0];
$hiding = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
$action = $hiding ? 'page.hide' : 'page.unhide';

$rawLen = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($rawLen > 0 && Http::jsonBody() !== []) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}

// ─── 행 + 볼 수 있나 (404·403·감사는 공통 관문 한 벌) ───────────
$gate      = community_history_gate($user, $pid);
$row       = $gate['row'];
$pageId    = (int) $row['id'];
$pageTitle = community_target_title((string) $row['title']);

[$may, $code] = CommunityAuthz::decideHide($user, $row);
if (!$may) {
    if ($code === CommunityAuthz::CODE_SPACE_GOVERNED) {
        Http::json(['error' => '게시판에 걸린 글만 숨길 수 있습니다', 'reason' => $code], 400);
        exit;
    }
    Audit::deny($action, CommunityAuthz::CODE_NOT_OWNER, [
        'target_type' => 'page',
        'target_id'   => $pageId,
        'target_name' => $pageTitle,
        'detail'      => ['page_title' => $pageTitle],
    ]);
    Http::error(403, Http::MSG_FORBIDDEN);
}

$wasHidden  = $row['hidden_at'] !== null;

// 이미 그 상태면 기록하지 않는다(200, `changed: false`) — 기록은 「바뀐 일」의 장부다.
if ($wasHidden === $hiding) {
    Http::json(['page' => ['id' => $pid, 'hidden' => $hiding], 'changed' => false]);
    exit;
}

/* 카페 C1 (ARCH 25.5) — 쓰기 · 처리 이력 · 감사는 공용 함수 한 벌(`community_page_hide_write`, routes.php)이다.
   신고함의 「숨기기」(report_resolve.php)가 같은 함수를 부른다. 이 창구의 입력 · 판정 · 응답은 그대로다. */
$db->begin();
try {
    if (!community_page_hide_write($row, $hiding, $me, $code)) {
        $db->rollback();
        Http::json(['page' => ['id' => $pid, 'hidden' => $hiding], 'changed' => false]);
        exit;
    }
    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    throw $e;
}

Http::json(['page' => ['id' => $pid, 'hidden' => $hiding], 'changed' => true, 'granted_by' => $code]);
