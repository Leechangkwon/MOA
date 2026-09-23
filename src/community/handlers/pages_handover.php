<?php
declare(strict_types=1);

/**
 * src/community/handlers/pages_handover.php — POST /community/api/pages/{id}/handover
 *                                            (정책: community.write)
 *
 * 요구사항: 커뮤니티 `PRD.md` 4.1 「인계(F-28)」 · 11-1 ⑦, 5-A 지시서 「인계 규칙」 · 「결정」 1·2,
 *           `C:S-14`(지울 수 없는 기록) · `C:S-07`(감사).
 *
 * 인계 = 이 페이지와 **살아 있는 하위 전부**가 받는 사람의 개인 공간으로 옮겨 가는 것이다.
 *   · 새 공간에서는 **맨 위(뿌리)**로 들어간다 — 옛 부모는 옛 공간에 남는다.
 *   · 공개 범위·본문·판 이력·체크 기록·상태·작성자 이름은 **그대로** 따라간다(행을 옮길 뿐이다).
 *     `team` 범위는 이제 **새 주인의 팀**을 뜻한다(PRD 4.1 「소유자의 t-box 팀」).
 *   · 받는 사람에게 개인 공간이 아직 없으면 **만든다**(`community_create_personal_space` — 첫 방문의
 *     「시작하기」 페이지는 만들지 않는다. 넘겨받은 페이지가 곧 그 공간의 첫 페이지다).
 *   · 받는 사람이 그 페이지들에 받아 둔 공유 줄은 **지운다** — 이제 그 사람이 주인이다.
 *   · 옛 주인에게 남길 권한은 **요청이 반드시 고른다**(`keep` — 기본값 없음, 사용자 결정 2).
 *     `view`·`edit` 이면 뿌리 페이지에 공유 한 줄로 남는다(하위에도 미친다). `none` 이면 남기지 않는다.
 *   · 지워진 하위(휴지통)는 옮기지 않는다 — 지운 사람이 옛 공간의 휴지통에서 되살린다.
 *
 * 받는 값 (JSON, **허용 키 밖이 하나라도 있으면 400**)
 *   to    받는 사람의 계정명 (필수)
 *   keep  `none` · `view` · `edit` (**필수** — 없거나 다른 값이면 400)
 *
 * 검사 순서
 *   [1] 허용 키·값 → [2] 행+공간 → 404
 *   [권한 ✔] `decidePageHandover` — 소유자 `owner` · Admin `admin_all` · 자기 팀원 것의 Manager
 *            `team_scope` · 그 밖(편집 공유자 포함) 403 + 감사 `page.handover deny not_owner`
 *            팀 공간의 페이지는 400 `space_governed`(소유자가 「팀」이다 — 인계 대상 아님)
 *   [재인증] `admin_all` 로 열렸으면 세션이 **최근 15분 안에 비밀번호를 다시 확인**했어야 한다
 *            (`reauth_fresh`, t-box 16-1 의 같은 창). 아니면 403 `{next:"reauth"}` + 감사.
 *            소유자 본인·Manager 에게는 묻지 않는다 — 일상 업무를 15분마다 끊지 않는다.
 *   [3] 받는 사람 — 없음·비활성·Guest·지금 주인 → 400 `target_not_permitted` + 감사.
 *       같은 팀일 필요는 없다(사용자 결정 1).
 *   [4] 트랜잭션: (공간 만들기) → 가지 옮기기 → 뿌리로 올리기 → 옛 형제 줄 세우기 →
 *       받는 사람의 옛 공유 지우기 → (남길 권한) → `handovers` INSERT → 감사 `page.handover allow` → 200
 *
 * ★ Manager 는 **내용을 보지 않는다**(비열람) — 이 창구는 본문을 한 글자도 돌려주지 않는다
 *   (응답에는 제목과 수만 있다). 그래서 읽기 판정을 요구하지 않는다.
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params [0] = 페이지 public_id (UUID)
 */

$db  = Db::instance();
$me  = (int) $user['id'];
$pid = (string) $params[0];

/** 옛 주인에게 남길 권한 — 셋 중 하나를 **반드시** 고른다(기본값 없음). */
$keepValues = ['none', 'view', 'edit'];

// ─── [1] 허용 키·값 ───────────────────────────────────────────
$body = Http::jsonBody();
if (array_diff(array_keys($body), ['to', 'keep']) !== []) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}
$to   = $body['to'] ?? null;
$keep = $body['keep'] ?? null;
if (!is_string($to) || preg_match(Auth::USERNAME_RE, $to) !== 1) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}
// ★ 남길 권한을 보내지 않으면 400 이다 — 화면이 기본값을 두지 않는 것과 같은 규칙을 서버도 지킨다.
if (!is_string($keep) || !in_array($keep, $keepValues, true)) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}

// ─── [2] 행 + 공간 ────────────────────────────────────────────
$row = community_page_row($pid, $me);
if ($row === null) {
    Http::error(404, Http::MSG_NOT_FOUND);
}
$pageId    = (int) $row['id'];
$pageTitle = community_target_title((string) $row['title']);
$fromId    = $row['space_owner_user_id'] === null ? 0 : (int) $row['space_owner_user_id'];
$fromName  = (string) ($row['space_owner_name'] ?? '');

$denyDetail = ['page_title' => $pageTitle, 'to_user' => $to, 'kept_level' => $keep];

// ─── [권한 ✔] 넘길 수 있나 ────────────────────────────────────
[$may, $code] = CommunityAuthz::decidePageHandover($user, $row);
if (!$may) {
    if ($code === CommunityAuthz::CODE_NOT_FOUND) {
        Http::error(404, Http::MSG_NOT_FOUND);
    }
    if ($code === CommunityAuthz::CODE_SPACE_GOVERNED) {
        Audit::deny('page.handover', CommunityAuthz::CODE_SPACE_GOVERNED, [
            'target_type' => 'page',
            'target_id'   => $pageId,
            'target_name' => $pageTitle,
            'detail'      => $denyDetail,
        ]);
        Http::json([
            'error'  => '팀 공간의 페이지는 팀이 주인이라 넘길 수 없습니다',
            'reason' => CommunityAuthz::CODE_SPACE_GOVERNED,
        ], 400);
        exit;
    }
    if ($code === CommunityAuthz::CODE_NOT_OWNER) {
        Audit::deny('page.handover', CommunityAuthz::CODE_NOT_OWNER, [
            'target_type' => 'page',
            'target_id'   => $pageId,
            'target_name' => $pageTitle,
            'detail'      => $denyDetail,
        ]);
        Http::error(403, Http::MSG_FORBIDDEN);
    }
    error_log('[tbox] request_id=' . TBOX_REQUEST_ID
        . ' community page.handover deny code=' . $code . ' page_id=' . (string) $pageId);
    Http::error(403, Http::MSG_FORBIDDEN);
}

// ─── [재인증] Admin 이 남의 페이지를 넘길 때만 ─────────────────
if ($code === CommunityAuthz::CODE_ADMIN_ALL && ($user['reauth_fresh'] ?? false) !== true) {
    Audit::deny('page.handover', 'reauth_required', [
        'target_type' => 'page',
        'target_id'   => $pageId,
        'target_name' => $pageTitle,
        'detail'      => $denyDetail,
    ]);
    Http::json(['error' => Http::MSG_REAUTH_REQUIRED, 'next' => 'reauth'], 403);
    exit;
}

// ─── [3] 받는 사람 ────────────────────────────────────────────
$target = community_user_by_name($to);
[$targetOk] = CommunityAuthz::decideHandoverTarget($target, $row);
/* ★ Manager 는 팀원 페이지를 **내용을 보지 않고** 넘길 뿐이다(PRD 11-1 ⑦ 확정). 자기 자신에게 넘기면
   소유자가 되어 내용을 읽게 되므로 「비열람」이 인계 한 번으로 풀린다 — 문서 정리 중 코드를 읽다가 찾았다
   (2026-09-23, SECURITY C:R-05 ②). 팀 권한(`team_scope`)으로 넘길 때 받는 사람이 자기면 거절한다.
   소유자 본인은 이미 「지금 주인」으로 막히고, Admin 은 원래 내용을 볼 수 있어 해당 없다. */
if ($targetOk && $code === CommunityAuthz::CODE_TEAM_SCOPE && (int) $target['id'] === (int) $user['id']) {
    $targetOk = false;
}
if (!$targetOk) {
    Audit::deny('page.handover', CommunityAuthz::CODE_TARGET_NOT_PERMITTED, [
        'target_type' => 'page',
        'target_id'   => $pageId,
        'target_name' => $pageTitle,
        'detail'      => $denyDetail,
    ]);
    Http::json([
        'error'  => '그 사람에게는 넘길 수 없습니다 (활성 계정이고 글을 쓸 수 있는 역할이어야 합니다)',
        'reason' => CommunityAuthz::CODE_TARGET_NOT_PERMITTED,
    ], 400);
    exit;
}
$toId   = (int) $target['id'];
$toName = (string) $target['username'];

// ─── [4] 옮기기 ───────────────────────────────────────────────
$ids = community_subtree_live_ids($pageId);
if ($ids === []) {
    Http::error(404, Http::MSG_NOT_FOUND);
}
// `bigint[]` 한 값으로 **바인딩**한다 — 개수만큼 자리표시자를 만들지 않는다. 값은 전부 정수다.
$idArray = '{' . implode(',', array_map('intval', $ids)) . '}';

$sqlMove = <<<'SQL'
    UPDATE community.pages
       SET space_id = CAST(:space AS bigint),
           updated_at = now()
     WHERE id = ANY(CAST(:ids AS bigint[]))
       AND deleted_at IS NULL
    SQL;
$sqlRoot = <<<'SQL'
    UPDATE community.pages
       SET parent_page_id = NULL,
           position = CAST(:pos AS int)
     WHERE id = CAST(:pid AS bigint)
    SQL;
$sqlOldSiblings = <<<'SQL'
    SELECT p.id
      FROM community.pages p
     WHERE p.space_id = CAST(:space AS bigint)
       AND p.deleted_at IS NULL
       AND p.parent_page_id IS NOT DISTINCT FROM CAST(:parent AS bigint)
     ORDER BY p.position, p.id
     LIMIT 2000
    SQL;
$sqlPosition = <<<'SQL'
    UPDATE community.pages SET position = CAST(:pos AS int) WHERE id = CAST(:pid AS bigint)
    SQL;
$sqlDropTargetShares = <<<'SQL'
    DELETE FROM community.page_shares
     WHERE user_id = CAST(:uid AS bigint)
       AND page_id = ANY(CAST(:ids AS bigint[]))
    SQL;
$sqlKeep = <<<'SQL'
    INSERT INTO community.page_shares (page_id, user_id, level, granted_by)
    VALUES (CAST(:pid AS bigint), CAST(:uid AS bigint), :level, CAST(:by AS bigint))
    ON CONFLICT (page_id, user_id)
    DO UPDATE SET level = EXCLUDED.level, granted_by = EXCLUDED.granted_by
    SQL;
$sqlHandover = <<<'SQL'
    INSERT INTO community.handovers
        (root_page_id, from_user_id, to_user_id, by_user_id, page_count, kept_level)
    VALUES (CAST(:root AS bigint), CAST(:from AS bigint), CAST(:to AS bigint), CAST(:by AS bigint),
            CAST(:n AS int), :kept)
    RETURNING id, at
    SQL;

$oldSpaceId  = (int) $row['space_id'];
$oldParentId = $row['parent_page_id'] === null ? null : (int) $row['parent_page_id'];
$keptLevel   = $keep === 'none' ? null : $keep;

$db->begin();
try {
    // ① 받는 사람의 개인 공간 — 없으면 만든다(감사 `space.create allow handover`).
    $space = community_my_personal_space($toId);
    if ($space === null) {
        $made = community_create_personal_space($toId, $toName, $me);
        if ($made !== null) {
            Audit::allow('space.create', [
                'target_type' => 'space',
                'target_id'   => $made['id'],
                'target_name' => $made['name'],
                'detail'      => ['granted_by' => $code, 'page_title' => $pageTitle, 'to_user' => $toName],
            ], 'handover');
        }
        $space = community_my_personal_space($toId);
        if ($space === null) {
            throw new RuntimeException('받는 사람의 개인 공간을 열지 못했습니다');
        }
    }
    $newSpaceId = (int) $space['id'];

    // ② 가지 전체를 새 공간으로. 뿌리는 새 공간 뿌리들의 **맨 뒤**에 놓는다.
    $moved = $db->run($sqlMove, [':space' => $newSpaceId, ':ids' => $idArray])->rowCount();
    if ($moved < 1) {
        $db->rollback();
        Http::error(404, Http::MSG_NOT_FOUND);
    }
    $db->run($sqlRoot, [
        // 뿌리는 아직 옛 부모를 가리키므로 이 셈에 들지 않는다 — 나온 값이 곧 맨 뒤 자리다.
        ':pos' => community_next_position($newSpaceId, null),
        ':pid' => $pageId,
    ]);

    // ③ 떠나온 자리의 형제를 0..n-1 로 다시 매긴다(옮기기 `pages_move.php` 와 같은 규칙).
    foreach ($db->run($sqlOldSiblings, [':space' => $oldSpaceId, ':parent' => $oldParentId])->fetchAll() as $i => $sib) {
        $db->run($sqlPosition, [':pos' => $i, ':pid' => (int) $sib['id']]);
    }

    // ④ 받는 사람이 받아 두었던 공유 — 이제 주인이므로 지운다.
    $db->run($sqlDropTargetShares, [':uid' => $toId, ':ids' => $idArray]);

    // ⑤ 옛 주인에게 남길 권한 (모달에서 고른 값 — 기본값 없음).
    if ($keptLevel !== null && $fromId > 0) {
        $db->run($sqlKeep, [':pid' => $pageId, ':uid' => $fromId, ':level' => $keptLevel, ':by' => $me]);
    }

    // ⑥ 지울 수 없는 기록 (C:S-14 — 앱 계정은 INSERT·SELECT 만).
    $db->run($sqlHandover, [
        ':root' => $pageId,
        ':from' => $fromId,
        ':to'   => $toId,
        ':by'   => $me,
        ':n'    => $moved,
        ':kept' => $keptLevel,
    ])->fetch();

    Audit::allow('page.handover', [
        'target_type' => 'page',
        'target_id'   => $pageId,
        'target_name' => $pageTitle,
        'detail'      => [
            'granted_by' => $code,
            'page_title' => $pageTitle,
            'from_user'  => $fromName,
            'to_user'    => $toName,
            'page_count' => $moved,
            'kept_level' => $keptLevel,
        ],
    ], $code === CommunityAuthz::CODE_OWNER ? null : $code);

    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    throw $e;   // bootstrap 예외 핸들러 → 500 + {"error":"서버 오류"}
}

Http::json([
    'page'       => ['id' => $pid, 'title' => (string) $row['title']],
    'from'       => $fromName,
    'to'         => $toName,
    // 5-A″ — 받는 사람의 이름(없으면 null). 화면이 「이름 (아이디)」로 잇는다.
    'to_display_name' => $target['display_name'] === null ? null : (string) $target['display_name'],
    'page_count' => $moved,
    'kept'       => $keptLevel,
]);
