<?php
declare(strict_types=1);

/**
 * src/community/handlers/pages_unpublish.php — DELETE /community/api/pages/{id}/publish (정책: community.write)
 *
 * 요구사항: 커뮤니티 PRD 4.1 「게시 — 게시판 규칙의 Manager·Admin 은 **게시 해제·숨김**만」 · F-18,
 *           지시서 5-C 「게시 해제 = visibility 를 게시 전 값으로(그 값을 기록해 둔다)」.
 *
 * 자격 (`CommunityAuthz::decideUnpublish`) — 개인 공간의 **주인** · 게시한 게시판의 **관리자**
 * (팀 게시판의 그 팀 Manager · Admin). 그 밖(편집 공유자·게시판을 보는 사원)은 403 + 감사.
 *
 * 해제하면 `visibility` 가 `published_from`(게시 전 범위)으로 **돌아가고** 게시 칸·숨김 칸이 비워진다.
 * 게시판 등급으로만 보던 사람은 그 자리에서 403 이 된다(원래 범위 복원 — 완료 기준).
 * 「언제 해제했나」는 처리 이력(`page_property_events`, prop `publish`)과 감사에 남는다.
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params [0] = 페이지 public_id (UUID)
 */

$db  = Db::instance();
$me  = (int) $user['id'];
$pid = (string) $params[0];

// 몸통이 있으면 JSON 이어야 하고 키가 없어야 한다(허용 키 0).
$rawLen = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($rawLen > 0 && Http::jsonBody() !== []) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}

// ─── 행 + 볼 수 있나 (404·403·감사는 공통 관문 한 벌) ───────────
$gate      = community_history_gate($user, $pid);
$row       = $gate['row'];
$pageId    = (int) $row['id'];
$pageTitle = community_target_title((string) $row['title']);

if ((string) $row['visibility'] !== 'published') {
    Http::json(['error' => '게시 중인 페이지가 아닙니다', 'reason' => 'not_published'], 400);
    exit;
}

[$may, $code] = CommunityAuthz::decideUnpublish($user, $row);
if (!$may) {
    Audit::deny('page.unpublish', CommunityAuthz::CODE_NOT_OWNER, [
        'target_type' => 'page',
        'target_id'   => $pageId,
        'target_name' => $pageTitle,
        'detail'      => ['page_title' => $pageTitle, 'board_id' => (int) $row['published_board_id']],
    ]);
    Http::error(403, Http::MSG_FORBIDDEN);
}

$backTo    = CommunityAuthz::baseVisibility($row) ?? 'private';
$boardName = (string) ($row['pub_name'] ?? '');

$sqlSet = <<<'SQL'
    UPDATE community.pages
       SET visibility = :back,
           published_from = NULL,
           published_board_id = NULL
           -- ★ 숨김은 남긴다 — 해제 → 재게시로 숨김을 우회하지 못하게(독립 검토 2026-09-23)
     WHERE id = CAST(:pid AS bigint)
       AND deleted_at IS NULL
       AND visibility = 'published'
    SQL;

$db->begin();
try {
    $n = $db->run($sqlSet, [':back' => $backTo, ':pid' => $pageId])->rowCount();
    if ($n < 1) {
        $db->rollback();
        Http::json(['error' => '게시 중인 페이지가 아닙니다', 'reason' => 'not_published'], 400);
        exit;
    }
    community_insert_property_event($pageId, 'publish', $boardName, null, $me);
    Audit::allow('page.unpublish', [
        'target_type'  => 'page',
        'target_id'    => $pageId,
        'target_name'  => $pageTitle,
        'access_level' => (string) ($row['pub_access_level'] ?? ''),
        'detail'       => [
            // `owner`(쓴 사람) · `admin_all` · `team_scope`(게시판 관리자)
            'granted_by'    => $code,
            'page_title'    => $pageTitle,
            'board_id'      => (int) $row['published_board_id'],
            'board_name'    => $boardName,
            'visibility_to' => $backTo,
        ],
    ]);
    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    throw $e;
}

Http::json(['page' => ['id' => $pid, 'visibility' => $backTo], 'granted_by' => $code]);
