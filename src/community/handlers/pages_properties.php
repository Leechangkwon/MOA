<?php
declare(strict_types=1);

/**
 * src/community/handlers/pages_properties.php — PATCH /community/api/pages/{id}/properties
 *                                               (정책: community.write)
 *
 * 요구사항: `C:F-37`(상태·담당자 칸), `C:S-14`(지울 수 없는 기록), 커뮤니티 `PRD.md` F-37 · 4.1,
 *           `../../../../community/ARCHITECTURE.md` 13.1 · 13.2,
 *           수락 기준 C:T-46 ~ C:T-49 · C:T-51.
 *
 * 받는 값 (JSON, **허용 키 밖이 하나라도 있으면 400**, 둘 중 **하나 이상** 필수)
 *   status    `todo`·`doing`·`review`·`done`·`hold` **다섯** 또는 `null`(없음)
 *   resolved  `true`·`false` — 질문답변 게시판의 「해결됨」(5-C). 그 게시판에 걸린 페이지(게시판 글 ·
 *             게시된 개인 페이지)이고 **쓴 사람**(게시판 글의 작성자 · 개인 페이지의 주인)만이다.
 *             질문답변이 아닌 페이지는 400 `not_qa`, 쓴 사람이 아니면 403 + 감사 `page.property deny`.
 *
 * ★ 5-A — **담당자 지정을 멈췄다**(사용자 결정 2026-09-23: 담당자 = 그 문서의 소유자, 바꾸는
 *   일은 인계다 → `POST …/handover`). `assignee` 키는 이제 허용 키가 아니므로 400 이다.
 *   `pages.assignee_id` 칸은 지우지 않았고(열 삭제 0), 이미 든 값과 `page_property_events` 의
 *   담당자 줄은 「처리 이력」에 그대로 남는다 — 이 창구가 새 담당자 줄을 만들지 않을 뿐이다.
 *
 * 검사 순서
 *   [1] 허용 키·값 → [2] 행+공간 → 404
 *   [권한 ✔ A] `decidePageRead` — 못 보는 페이지는 여기서 끝난다(403·404)
 *   [권한 ✔ B] `decidePageChange` — **고칠 수 있는 사람만**이다(소유자 · 편집 공유자 · 팀 공간
 *              팀원). 사내로 열어 둔 페이지라도, 보기 공유를 받았어도 남이 상태를 바꾸지 못한다 (C:T-51)
 *   [4] 트랜잭션: `pages` 갱신 + 바뀌었으면 `page_property_events` INSERT + 감사 → 200
 *
 * ★ **값이 그대로면 기록하지 않는다.** 같은 값으로 다시 누르면 200 이지만
 *   `page_property_events` 는 늘지 않는다 — 기록은 「바뀐 일」의 장부다 (C:T-46 이 세는 값).
 * ★ `updated_at`·`last_edited_by` 는 **건드리지 않는다.** 상태를 바꾸는 것은 본문을 고치는
 *   일이 아니고, 그 둘을 밀면 「최근 고친 순」 목록이 내용과 상관없이 흔들린다. 누가 언제
 *   바꿨나는 `page_property_events` 가 들고 있다.
 * ★ 형식 거부(허용 키 밖·모르는 값)에는 감사가 없다. 감사는 **권한·규칙으로 막은 일**에
 *   남긴다 (`pages_visibility.php` 와 같은 원칙).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params [0] = 페이지 public_id (UUID)
 */

$db  = Db::instance();
$me  = (int) $user['id'];
$pid = (string) $params[0];

// ─── [1] 허용 키·값 ───────────────────────────────────────────
$body = Http::jsonBody();
// 허용 키는 `status`·`resolved`(5-C) 둘이다. `assignee` 는 5-A 에서 빠졌다 — 오면 400 (DB 불변).
if (array_diff(array_keys($body), ['status', 'resolved']) !== []) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}
$hasStatus   = array_key_exists('status', $body);
$hasResolved = array_key_exists('resolved', $body);
// 아무 칸도 주지 않은 요청은 「무엇을 바꾸라는 것인가」가 없다.
if (!$hasStatus && !$hasResolved) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}

$wantStatus = null;
if ($hasStatus) {
    $raw = $body['status'];
    if ($raw !== null) {
        // 허용 목록 **정확 일치**. 목록 밖(`urgent` 같은 것)은 여기서 400 이다 (C:T-47).
        if (!is_string($raw) || !in_array($raw, COMMUNITY_STATUSES, true)) {
            Http::error(400, Http::MSG_BAD_REQUEST);
        }
        $wantStatus = $raw;
    }
}
$wantResolved = null;
if ($hasResolved) {
    if (!is_bool($body['resolved'])) {
        Http::error(400, Http::MSG_BAD_REQUEST);
    }
    $wantResolved = $body['resolved'];
}

// ─── [2] 행 + 공간 ────────────────────────────────────────────
$row = community_page_row($pid, (int) $user['id']);
if ($row === null) {
    Http::error(404, Http::MSG_NOT_FOUND);
}
$pageId    = (int) $row['id'];
$pageTitle = community_target_title((string) $row['title']);

$fromStatus = $row['status'] === null ? null : (string) $row['status'];

// ─── [권한 ✔ A] 볼 수 있나 ────────────────────────────────────
[$mayRead, $readCode] = CommunityAuthz::decidePageRead($user, $row);
if (!$mayRead) {
    if ($readCode === CommunityAuthz::CODE_NOT_FOUND) {
        Http::error(404, Http::MSG_NOT_FOUND);
    }
    if ($readCode === CommunityAuthz::CODE_NOT_OWNER) {
        Audit::deny('page.read', CommunityAuthz::CODE_NOT_OWNER, [
            'target_type' => 'page',
            'target_id'   => $pageId,
            'target_name' => $pageTitle,
            'detail'      => ['page_title' => $pageTitle],
        ]);
        Http::error(403, Http::MSG_FORBIDDEN);
    }
    error_log('[tbox] request_id=' . TBOX_REQUEST_ID
        . ' community page.read deny code=' . $readCode . ' (4-D 에 없는 조합)');
    Http::error(403, Http::MSG_FORBIDDEN);
}

// ─── [권한 ✔ B] 고칠 수 있나 (C:T-51) ─────────────────────────
/* 볼 수 있는 것과 고칠 수 있는 것은 다른 물음이다. 사내로 열어 둔 페이지의 상태를 아무나
   바꿀 수 있으면, 그 상태는 더 이상 주인의 기록이 아니다 (PRD 4.1 「누가 고치나」). */
[$mayChange, $changeCode] = $hasStatus
    ? CommunityAuthz::decidePageChange($user, $row)
    : [true, CommunityAuthz::CODE_OWNER];

/* 5-C 「해결됨」 — 질문답변 게시판에 걸린 페이지의 **쓴 사람**만. 쓴 사람 = 게시판 글이면 작성자,
   게시된 개인 페이지면 주인이고, 둘 다 `decidePageManage` 의 `owner` 다(편집 공유자·게시판 관리자는 아니다). */
if ($hasResolved) {
    $qaBoard = CommunityAuthz::boardOfRow($row);
    if ($qaBoard === null || (string) $qaBoard['board_kind'] !== 'qa') {
        Http::json(['error' => '질문과 답변 게시판의 글만 「해결됨」을 켤 수 있습니다', 'reason' => 'not_qa'], 400);
        exit;
    }
    [$mayResolve, $resolveCode] = CommunityAuthz::decidePageManage($user, $row);
    if (!$mayResolve || $resolveCode !== CommunityAuthz::CODE_OWNER) {
        $mayChange = false;
    } elseif (!$hasStatus) {
        $changeCode = $resolveCode;
    }
}

if (!$mayChange) {
    Audit::deny('page.property', CommunityAuthz::CODE_NOT_OWNER, [
        'target_type' => 'page',
        'target_id'   => $pageId,
        'target_name' => $pageTitle,
        'detail'      => ['page_title' => $pageTitle],
    ]);
    Http::error(403, Http::MSG_FORBIDDEN);
}

$toStatus     = $hasStatus ? $wantStatus : $fromStatus;
$fromResolved = ($row['resolved'] ?? false) === true;
$toResolved   = $hasResolved ? (bool) $wantResolved : $fromResolved;

// ─── [4] 저장 ─────────────────────────────────────────────────
$sqlSet = <<<'SQL'
    UPDATE community.pages
       SET status = :status,
           resolved = CAST(:resolved AS boolean)
     WHERE id = CAST(:pid AS bigint)
       AND deleted_at IS NULL
    SQL;

/** 감사 한 줄 — 「무엇을 무엇으로」가 문장 뒤의 상세에 남는다 (ARCH 7.5 등록부). */
$auditChange = static function (string $prop, ?string $from, ?string $to)
use ($pageId, $pageTitle, $changeCode): void {
    Audit::allow('page.property', [
        'target_type' => 'page',
        'target_id'   => $pageId,
        'target_name' => $pageTitle,
        'detail'      => [
            'granted_by' => $changeCode,
            'page_title' => $pageTitle,
            'prop'       => $prop,
            'from_value' => $from,
            'to_value'   => $to,
        ],
    ]);
};

$changed = [];

$db->begin();
try {
    $n = $db->run($sqlSet, [
        ':status'   => $toStatus,
        ':resolved' => $toResolved ? 'true' : 'false',
        ':pid'      => $pageId,
    ])->rowCount();
    if ($n < 1) {
        // 읽고 나서 여기 오기까지 사이에 다른 요청이 먼저 지웠다 — 없는 것과 같다.
        $db->rollback();
        Http::error(404, Http::MSG_NOT_FOUND);
    }

    if ($toStatus !== $fromStatus) {
        community_insert_property_event($pageId, 'status', $fromStatus, $toStatus, $me);
        $auditChange('status', $fromStatus, $toStatus);
        $changed[] = 'status';
    }
    // 5-C 「해결됨」 — 값은 사람이 읽는 글자 둘(`resolved`·`open`)로 남긴다.
    if ($toResolved !== $fromResolved) {
        $fromText = $fromResolved ? 'resolved' : 'open';
        $toText   = $toResolved ? 'resolved' : 'open';
        community_insert_property_event($pageId, 'resolved', $fromText, $toText, $me);
        $auditChange('resolved', $fromText, $toText);
        $changed[] = 'resolved';
    }

    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    throw $e;   // bootstrap 예외 핸들러 → 500 + {"error":"서버 오류"}
}

Http::json([
    'page' => [
        'id'       => $pid,
        'status'   => $toStatus,
        'resolved' => $toResolved,
    ],
    // 무엇이 실제로 바뀌었나 — 화면이 「바뀐 것이 없습니다」를 스스로 판단하지 않게 한다.
    'changed' => $changed,
]);
