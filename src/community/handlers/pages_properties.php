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
 * ★ Q:F-12 (WK-3 ② · WK-14 A, 커뮤니티 ARCH 23.3) — **담당을 소유자와 따로 다시 쓴다.** 허용 키에
 *   `assignee`(계정명 문자열 또는 `null` = 비우기)가 돌아왔다. 담당은 「맡은 사람」 표시일 뿐 볼·고칠 권한을 주지 않는다.
 *   순서: 행 잠금·읽기(요청자) → `decidePageAssign`(403 + 감사 `page.assign deny`) → 대상 찾기 → **대상 몫으로 다시 읽은
 *   행** → `decideAssignTarget`(400 「이 사람에게는 맡길 수 없어요」 하나 — 없는 계정·형식 틀림도 같은 문구, 이유 코드는
 *   감사에만) → 같은 값이면 변경 없음 200 → `UPDATE pages.assignee_id` + `page_property_events(prop='assignee', 계정명)` +
 *   감사 `page.assign allow` — 한 트랜잭션 → 커밋 뒤 알림 `assigned`(`community_notify` — 받는 사람 기준 다시 판정, 자기 자신 제외).
 *   거부 감사는 **트랜잭션을 되돌린 뒤** 쓴다(같은 연결이라 되돌리면 감사 행도 사라진다).
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
// 허용 키 — `status`·`resolved`(5-C)·`verify`·`kind`·`due` + Q:F-12 의 `assignee`(5-A 에서 빠졌다가 소유자와 따로 돌아왔다).
if (array_diff(array_keys($body), ['status', 'resolved', 'verify', 'kind', 'due', 'assignee']) !== []) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}
$hasStatus   = array_key_exists('status', $body);
$hasResolved = array_key_exists('resolved', $body);
// 아무 칸도 주지 않은 요청은 「무엇을 바꾸라는 것인가」가 없다.
$hasVerify = array_key_exists('verify', $body);
$hasKind = array_key_exists('kind', $body);
$hasDue = array_key_exists('due', $body);
$hasAssignee = array_key_exists('assignee', $body);
/* Q:F-12 — 담당은 계정명(`Auth::USERNAME_RE`) 또는 `null`(비우기). 모양이 틀린 값은 어떤 계정도 아니므로 「없는 계정」과
   **같은 문구**로 400 이다(있는 계정인지·맡길 수 있는 사람인지를 응답이 가르지 않는다 — 감사 없음, 형식 거부). */
$wantAssignee = null;
if ($hasAssignee && $body['assignee'] !== null) {
    if (!is_string($body['assignee']) || preg_match(Auth::USERNAME_RE, $body['assignee']) !== 1) {
        Http::json(['error' => COMMUNITY_ASSIGN_REFUSED], 400);
        exit;
    }
    $wantAssignee = $body['assignee'];
}
if ($hasDue && $body['due'] !== null) {
    $d = is_string($body['due']) ? DateTimeImmutable::createFromFormat('!Y-m-d', $body['due']) : false;
    if (!$d || $d->format('Y-m-d') !== $body['due']) { Http::error(400, Http::MSG_BAD_REQUEST); }
}
if ($hasVerify && $body['verify'] !== null && (!is_int($body['verify']) || !in_array($body['verify'], [0,30,90,180], true))) { Http::error(400, Http::MSG_BAD_REQUEST); }
if ($hasKind && (!is_string($body['kind']) || !in_array($body['kind'], ['page','procedure','dashboard'], true))) { Http::error(400, Http::MSG_BAD_REQUEST); }
if (!$hasStatus && !$hasResolved && !$hasVerify && !$hasKind && !$hasDue && !$hasAssignee) {
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

// Serialize property mutations before reading values or checking current permissions.
$db->begin();
$locked = $db->run('SELECT id FROM community.pages WHERE public_id=CAST(:pid AS uuid) AND deleted_at IS NULL FOR UPDATE'
, [':pid' => $pid])->fetch();
if (!is_array($locked)) { $db->rollback(); Http::error(404, Http::MSG_NOT_FOUND); }
$freshUser = community_user_by_id($me);
if ($freshUser === null || !$freshUser['is_active'] || ($freshUser['expires_at'] !== null && strtotime($freshUser['expires_at']) <= time())) { $db->rollback(); Http::error(403, Http::MSG_FORBIDDEN); }
// Role preview may narrow the current role; never replace it with the database's administrator role.
if ($freshUser['role'] !== ($user['real_role'] ?? $user['role'])) { $db->rollback(); Http::error(403, Http::MSG_FORBIDDEN); }
$user = array_merge($user, ['team_id' => $freshUser['team_id'], 'is_active' => $freshUser['is_active']]);

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
        // 거부 감사는 되돌린 **뒤** 쓴다 — 열린 트랜잭션 안에서 쓰면 요청 끝 되돌림에 감사 행도 사라진다(RV-A2).
        $db->rollback();
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

// ─── [권한 ✔ 담당] 정할 수 있나 · 그 사람이 될 수 있나 (Q:F-12, ARCH 23.3) ──────
/* 판정은 `CommunityAuthz` 두 함수뿐이다 — 여기서 팀·소유를 견주지 않는다. 지금 담당이 누구인지는 판정 재료가 아니다
   (맡은 사람이라서 다시 맡길 수 있는 갈래 0). 거부 감사는 되돌린 뒤 쓴다 — 감사 행은 같은 연결의 같은 트랜잭션에 실린다. */
$assignTarget = null;
$assignCode   = '';
if ($hasAssignee) {
    [$mayAssign, $assignCode] = CommunityAuthz::decidePageAssign($user, $row);
    if (!$mayAssign) {
        $db->rollback();
        Audit::deny('page.assign', $assignCode, [
            'target_type' => 'page',
            'target_id'   => $pageId,
            'target_name' => $pageTitle,
            'detail'      => ['page_title' => $pageTitle, 'to' => $wantAssignee],
        ]);
        Http::error(403, Http::MSG_FORBIDDEN);
    }
    if ($wantAssignee !== null) {
        $assignTarget = community_user_by_name($wantAssignee);
        // 대상 몫으로 **다시 읽은** 행 — 공유 등급이 그 사람 몫이어야 「고칠 수 있나」가 맞다(없는 계정이면 판정이 `inactive`).
        $rowForTarget = $assignTarget === null ? $row : community_page_row($pid, (int) $assignTarget['id']);
        [$targetOk, $targetCode] = CommunityAuthz::decideAssignTarget($assignTarget, $rowForTarget ?? $row);
        if (!$targetOk) {
            $db->rollback();
            Audit::deny('page.assign', $targetCode, [
                'target_type' => 'page',
                'target_id'   => $pageId,
                'target_name' => $pageTitle,
                'detail'      => ['page_title' => $pageTitle, 'to' => $wantAssignee],
            ]);
            Http::json(['error' => COMMUNITY_ASSIGN_REFUSED], 400);
            exit;
        }
    }
}

// ─── [권한 ✔ B] 고칠 수 있나 (C:T-51) ─────────────────────────
/* 볼 수 있는 것과 고칠 수 있는 것은 다른 물음이다. 사내로 열어 둔 페이지의 상태를 아무나
   바꿀 수 있으면, 그 상태는 더 이상 주인의 기록이 아니다 (PRD 4.1 「누가 고치나」). */
[$mayChange, $changeCode] = ($hasStatus || $hasVerify || $hasKind || $hasDue)
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
    } elseif (!$hasStatus && !$hasVerify && !$hasKind && !$hasDue) {
        $changeCode = $resolveCode;
    }
}

if (!$mayChange) {
    $db->rollback();   // 위 담당 거부와 같은 모양 — 되돌린 뒤 감사(RV-A2)
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
           resolved = CAST(:resolved AS boolean),
           page_kind = :kind, due_date = CAST(:due AS date),
           verified_by = CASE WHEN CAST(:vset AS boolean) THEN CAST(:vby AS bigint) ELSE verified_by END,
           verified_at = CASE WHEN CAST(:vset AS boolean) THEN CASE WHEN CAST(:vby AS bigint) IS NULL THEN NULL ELSE now() END ELSE verified_at END,
           verify_expires_at = CASE WHEN CAST(:vset AS boolean) THEN CASE WHEN CAST(:days AS integer) > 0 THEN now() + CAST(:days AS integer) * interval '1 day' ELSE NULL END ELSE verify_expires_at END
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

try {
    $n = $db->run($sqlSet, [
        ':status'   => $toStatus,
        ':resolved' => $toResolved ? 'true' : 'false',
        ':pid'      => $pageId,
        ':due' => $hasDue ? $body['due'] : $row['due_date'],
        ':kind' => $hasKind ? $body['kind'] : $row['page_kind'],
        ':vset' => $hasVerify ? 'true' : 'false',
        ':vby' => $hasVerify && $body['verify'] !== null ? $me : null,
        ':days' => $hasVerify ? $body['verify'] : null,
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
        if ($toStatus === 'review') {
            $db->run("INSERT INTO community.automation_queue(automation_id,page_id,event_id) SELECT a.id,:page,e.id FROM community.automations a JOIN community.page_property_events e ON e.page_id=:event_page AND e.prop='status' WHERE a.kind='on_status' AND a.is_enabled AND a.deleted_at IS NULL AND (a.scope_page_id=:scope_page OR a.scope_space_id=:space) AND e.id=(SELECT max(id) FROM community.page_property_events WHERE page_id=:last_page AND prop='status') ON CONFLICT DO NOTHING"
, [':page'=>$pageId,':event_page'=>$pageId,':scope_page'=>$pageId,':space'=>(int)$row['space_id'],':last_page'=>$pageId]);
        }
    }
    // 5-C 「해결됨」 — 값은 사람이 읽는 글자 둘(`resolved`·`open`)로 남긴다.
    if ($toResolved !== $fromResolved) {
        $fromText = $fromResolved ? 'resolved' : 'open';
        $toText   = $toResolved ? 'resolved' : 'open';
        community_insert_property_event($pageId, 'resolved', $fromText, $toText, $me);
        $auditChange('resolved', $fromText, $toText);
        $changed[] = 'resolved';
    }

    if ($hasKind && $body['kind'] !== $row['page_kind']) {
        community_insert_property_event($pageId, 'kind', $row['page_kind'], $body['kind'], $me);
        $auditChange('kind', $row['page_kind'], $body['kind']);
        $changed[] = 'kind';
    }
    if ($hasVerify && ($body['verify'] !== null || $row['verified_at'] !== null)) {
        $newRow = community_page_row($pid, $me);
        $verifiedValue = static fn(array $p): ?string => $p['verified_at'] === null ? null : ($p['verify_expires_at'] === null ? 'none' : substr($p['verify_expires_at'], 0, 10));
        $from = $verifiedValue($row); $to = $verifiedValue($newRow);
        community_insert_property_event($pageId, 'verified', $from, $to, $me);
        $auditChange('verified', $from, $to);
        $changed[] = 'verified';
    }
    if ($hasDue && $body['due'] !== $row['due_date']) {
        community_insert_property_event($pageId, 'due', $row['due_date'], $body['due'], $me);
        $auditChange('due', $row['due_date'], $body['due']); $changed[] = 'due';
    }
    /* Q:F-12 담당 — 같은 사람이면 변경 없음(기록 0). 바뀌었으면 칸 + 지울 수 없는 줄(값은 계정명) + 감사 한 줄. */
    $notifyAssignee = null;
    if ($hasAssignee) {
        $oldAssigneeId = $row['assignee_id'] === null ? null : (int) $row['assignee_id'];
        $newAssigneeId = $assignTarget === null ? null : (int) $assignTarget['id'];
        if ($oldAssigneeId !== $newAssigneeId) {
            $sqlAssign = <<<'SQL'
                UPDATE community.pages SET assignee_id = CAST(:aid AS bigint)
                 WHERE id = CAST(:pid AS bigint) AND deleted_at IS NULL
                SQL;
            $db->run($sqlAssign, [':aid' => $newAssigneeId, ':pid' => $pageId]);
            $fromAssignee = $row['assignee_name'] === null ? null : (string) $row['assignee_name'];
            $toAssignee   = $assignTarget === null ? null : (string) $assignTarget['username'];
            community_insert_property_event($pageId, 'assignee', $fromAssignee, $toAssignee, $me);
            Audit::allow('page.assign', [
                'target_type' => 'page',
                'target_id'   => $pageId,
                'target_name' => $pageTitle,
                'detail'      => [
                    'granted_by' => $assignCode,
                    'page_title' => $pageTitle,
                    'from'       => $fromAssignee,
                    'to'         => $toAssignee,
                ],
            ]);
            $changed[] = 'assignee';
            $notifyAssignee = $newAssigneeId;
        }
    }
    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    throw $e;   // bootstrap 예외 핸들러 → 500 + {"error":"서버 오류"}
}

/* Q:F-12 — 맡긴 사람에게 알림 하나(커밋 뒤). `community_notify` 가 받는 사람 몫으로 다시 읽어 다시 판정하고, 자기 자신은
   건너뛴다. 옛 담당·비운 일에는 알리지 않는다(ARCH 23.9). 알림이 실패해도 맡긴 일은 이미 기록됐다. */
if ($notifyAssignee !== null) {
    try {
        community_notify($notifyAssignee, 'assigned', $row, null, $me);
    } catch (Throwable $e) {
        error_log('[tbox] request_id=' . TBOX_REQUEST_ID . ' community assigned notification failed: ' . get_class($e));
    }
}

$after = community_page_row($pid, $me);
Http::json([
    'page' => [
        'id'       => $pid,
        'status'   => $toStatus,
        'resolved' => $toResolved,
        // Q:F-12 — 지금 담당(pages_get 의 `assignee` 와 같은 모양). 없으면 null.
        'assignee' => $after === null || $after['assignee_name'] === null ? null : [
            'username'  => (string) $after['assignee_name'],
            'role'      => (string) $after['assignee_role'],
            'is_active' => $after['assignee_active'] === true,
        ],
    ],
    // 무엇이 실제로 바뀌었나 — 화면이 「바뀐 것이 없습니다」를 스스로 판단하지 않게 한다.
    'changed' => $changed,
    'verify' => community_verify_info($after ?? community_page_row($pid, $me)),
    'page_kind' => $hasKind ? $body['kind'] : $row['page_kind'],
]);
