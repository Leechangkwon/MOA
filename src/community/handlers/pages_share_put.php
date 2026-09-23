<?php
declare(strict_types=1);

/**
 * src/community/handlers/pages_share_put.php — PUT /community/api/pages/{id}/shares/{username}
 *                                              (정책: community.write)
 *
 * 요구사항: 5-A 지시서 「창구」 · 「판정 — 한 곳에서」, 커뮤니티 `PRD.md` 4.1 · NG-8(해제),
 *           `C:S-01`(권한은 서버가) · `C:S-07`(감사).
 *
 * 한 사람에게 이 페이지를 **보기(`view`) 또는 편집(`edit`)**으로 준다. 이미 준 사람이면 **등급을
 * 바꾼다**(같은 페이지·같은 사람은 한 줄뿐 — `UNIQUE (page_id, user_id)`). 공유는 하위에도 미친다.
 * 공개 범위(비공개·팀·사내)는 그대로 있고, 공유는 그 위에 **더하는** 권한이다.
 *
 * 받는 값 (JSON, **허용 키 밖이 하나라도 있으면 400**)
 *   level  `view` · `edit` **둘뿐**(필수)
 * 경로의 `{username}` — 받는 사람의 계정명(라우트가 `[a-z0-9_.-]{3,64}` 만 통과시킨다).
 *
 * 검사 순서
 *   [1] 허용 키·값 → [권한 ✔ A] `community_history_gate`(행 404 · 읽기 403 + 감사)
 *   [권한 ✔ B] **`decidePageManage`** — 소유자·팀 공간 팀원만. 편집 공유자는 **남에게 다시 열어
 *              주지 못한다** → 403 + 감사 `page.share deny not_owner` (C:T-53)
 *   [2] 팀 공간의 페이지는 공간 등급을 따른다 → 400 `space_governed` (공유 없음 — 지시서 밖)
 *   [3] 받는 사람 — 없음·비활성·주인 자신 → 400 `target_not_permitted` + 감사.
 *       Guest 는 **받을 수 있다** — 편집을 주어도 판정이 보기로만 연다(`decidePageChange`).
 *   [4] 트랜잭션: `INSERT … ON CONFLICT DO UPDATE` + 감사 `page.share allow` → 200
 *
 * ★ 없는 계정과 내려간 계정은 **같은 답**이다(「그런 계정이 있나」를 응답으로 알려 주지 않는다).
 *   무엇이었는지는 감사의 `share_user` 에 남는다.
 * ★ 같은 등급을 다시 보내면 200 이고 **감사도 줄도 늘지 않는다**(`changed: false`).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params [0] = 페이지 public_id (UUID), [1] = 받는 사람 계정명
 */

$db   = Db::instance();
$me   = (int) $user['id'];
$pid  = (string) $params[0];
$name = (string) $params[1];

// ─── [1] 허용 키·값 ───────────────────────────────────────────
$body = Http::jsonBody();
if (array_diff(array_keys($body), ['level']) !== []
    || !array_key_exists('level', $body)
    || !is_string($body['level'])
    || !in_array($body['level'], CommunityAuthz::SHARE_LEVELS, true)
) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}
$want = $body['level'];

// ─── [권한 ✔ A] 볼 수 있나 ────────────────────────────────────
$gate      = community_history_gate($user, $pid);
$row       = $gate['row'];
$pageId    = (int) $row['id'];
$pageTitle = community_target_title((string) $row['title']);

// ─── [권한 ✔ B] 관리할 수 있나 (5-A) ──────────────────────────
[$mayManage, $manageCode] = CommunityAuthz::decidePageManage($user, $row);
if (!$mayManage) {
    Audit::deny('page.share', CommunityAuthz::CODE_NOT_OWNER, [
        'target_type' => 'page',
        'target_id'   => $pageId,
        'target_name' => $pageTitle,
        'detail'      => ['page_title' => $pageTitle, 'share_user' => $name, 'share_level' => $want],
    ]);
    Http::error(403, Http::MSG_FORBIDDEN);
}

// ─── [2] 팀 공간의 페이지는 공유가 없다 ────────────────────────
if ((string) $row['space_kind'] !== 'personal') {
    Http::json([
        'error'  => '팀 공간의 페이지는 공간 등급을 따릅니다',
        'reason' => CommunityAuthz::CODE_SPACE_GOVERNED,
    ], 400);
    exit;
}

// ─── [3] 받는 사람 ────────────────────────────────────────────
$target = community_user_by_name($name);
[$targetOk] = CommunityAuthz::decideShareTarget($target, $row);
if (!$targetOk) {
    Audit::deny('page.share', CommunityAuthz::CODE_TARGET_NOT_PERMITTED, [
        'target_type' => 'page',
        'target_id'   => $pageId,
        'target_name' => $pageTitle,
        'detail'      => ['page_title' => $pageTitle, 'share_user' => $name, 'share_level' => $want],
    ]);
    Http::json([
        'error'  => '그 사람에게는 공유할 수 없습니다',
        'reason' => CommunityAuthz::CODE_TARGET_NOT_PERMITTED,
    ], 400);
    exit;
}
$targetId = (int) $target['id'];

// ─── [4] 저장 ─────────────────────────────────────────────────
$sqlPrev = <<<'SQL'
    SELECT level FROM community.page_shares
     WHERE page_id = CAST(:pid AS bigint) AND user_id = CAST(:uid AS bigint)
     FOR UPDATE
    SQL;
$sqlUpsert = <<<'SQL'
    INSERT INTO community.page_shares (page_id, user_id, level, granted_by)
    VALUES (CAST(:pid AS bigint), CAST(:uid AS bigint), :level, CAST(:by AS bigint))
    ON CONFLICT (page_id, user_id)
    DO UPDATE SET level = EXCLUDED.level, granted_by = EXCLUDED.granted_by
    SQL;

$db->begin();
try {
    $prevRow = $db->run($sqlPrev, [':pid' => $pageId, ':uid' => $targetId])->fetch();
    $prev    = is_array($prevRow) ? (string) $prevRow['level'] : null;
    $changed = $prev !== $want;

    if ($changed) {
        $db->run($sqlUpsert, [':pid' => $pageId, ':uid' => $targetId, ':level' => $want, ':by' => $me]);
        Audit::allow('page.share', [
            'target_type' => 'page',
            'target_id'   => $pageId,
            'target_name' => $pageTitle,
            'detail'      => [
                'granted_by'  => $manageCode,
                'page_title'  => $pageTitle,
                'share_user'  => (string) $target['username'],
                // 이전 등급(없으면 null — 새로 준 것) → 새 등급
                'share_from'  => $prev,
                'share_level' => $want,
            ],
        ]);
    }

    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    throw $e;   // bootstrap 예외 핸들러 → 500 + {"error":"서버 오류"}
}

Http::json([
    'share' => [
        'username' => (string) $target['username'],
        'role'     => (string) $target['role'],
        'level'    => $want,
    ],
    'from'    => $prev,
    'changed' => $changed,
]);
