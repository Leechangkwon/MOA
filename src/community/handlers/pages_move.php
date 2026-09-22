<?php
declare(strict_types=1);

/**
 * src/community/handlers/pages_move.php — PATCH /community/api/pages/{id}/move (정책: community.write)
 *
 * 요구사항: `C:F-17`(페이지 트리 — 이동·순서), `C:S-13`,
 *           `../../../../community/ARCHITECTURE.md` 13.2 · 13.3, 4-A 지시서 C:T-24 ~ C:T-26.
 *
 * 받는 값 (JSON, **허용 키 밖이 하나라도 있으면 400**)
 *   parent_id  새 부모 (UUID) 또는 `null`(뿌리로 올린다). **키가 없으면 400** — 「어디로」가
 *              빠진 이동은 뜻이 없다. 지금 부모를 그대로 두려면 같은 값을 다시 준다.
 *   position   형제 안에서의 자리(0부터). 없으면 맨 뒤. 범위를 벗어나면 **거부하지 않고**
 *              0..n 안으로 좁힌다 (t-box ARCH 5.1 — 목록 파라미터와 같은 결).
 *
 * 검사 순서
 *   [1] 허용 키 → [2] 행+공간 → 404 → [권한 ✔ A] `decidePageRead` → [권한 ✔ B] `decidePageChange`
 *   [3] 새 부모 찾기 — 없는 UUID 404 · 남의 것 403(+감사 `page.read deny not_owner`)
 *   [4] 트리 모양을 **재귀 CTE 한 번**으로 (`community_move_shape`)
 *   [권한 ✔ C] `CommunityAuthz::decidePageMove` — `cycle` · `depth_exceeded` ·
 *              **`wider_than_parent`**(4-B 트리 불변 규칙)가 여기서 갈린다
 *   [5] 트랜잭션: 옛 형제 줄 세우기 → 새 부모 아래 끼워 넣기 → 감사 `page.move allow` → 200
 *
 * ★ 순서는 **형제 안에서 0..n-1 로 다시 매긴다**(C:T-26). 값을 띄엄띄엄 두면 언젠가 두 페이지가
 *   같은 번호를 갖고, 그때부터 목록 차례가 `id` 순으로 조용히 바뀐다.
 * ★ 4-B — 옮기는 것도 트리 불변 규칙을 지킨다. 견주는 값은 옮길 페이지 하나가 아니라
 *   **옮겨 가는 가지에서 가장 넓은 범위**다: 자기는 맞고 자식이 더 넓으면, 옮긴 자리에서
 *   규칙이 깨진 트리가 만들어진다. 그 값은 `community_move_shape` 가 같은 CTE 에서 센다.
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params [0] = 페이지 public_id (UUID)
 */

$uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

$db  = Db::instance();
$me  = (int) $user['id'];
$pid = (string) $params[0];

// ─── [1] 허용 키 ──────────────────────────────────────────────
$body = Http::jsonBody();
if (array_diff(array_keys($body), ['parent_id', 'position']) !== []) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}
if (!array_key_exists('parent_id', $body)) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}

$rawParent = $body['parent_id'];
if ($rawParent !== null && (!is_string($rawParent) || preg_match($uuidRe, $rawParent) !== 1)) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}

$wantPosition = null;
if (array_key_exists('position', $body) && $body['position'] !== null) {
    if (!is_int($body['position']) || $body['position'] < 0 || $body['position'] > 100000) {
        Http::error(400, Http::MSG_BAD_REQUEST);
    }
    $wantPosition = $body['position'];
}

// ─── [2] 행 + 공간 ────────────────────────────────────────────
$row = community_page_row($pid);
if ($row === null) {
    Http::error(404, Http::MSG_NOT_FOUND);
}
$pageId    = (int) $row['id'];
$spaceId   = (int) $row['space_id'];
$pageTitle = community_target_title((string) $row['title']);

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
        . ' community page.read deny code=' . $readCode . ' (문장 없는 조합)');
    Http::error(403, Http::MSG_FORBIDDEN);
}

// ─── [권한 ✔ B] 고칠 수 있나 (옮기기도 고치기다) ───────────────
[$mayChange, $changeCode] = CommunityAuthz::decidePageChange($user, $row);
if (!$mayChange) {
    /* 볼 수는 있으나 고칠 수 없는 사람 — 지금은 Admin 이다. 그 사실은 바로 위
       `page.read allow admin_all` 행에 남지 않는다(여기서는 자기 페이지가 아닐 때만 읽기
       감사가 찍힌다). 이동 거부에 붙일 문장은 표에 `cycle`·`depth_exceeded` 둘뿐이므로,
       문장 없는 기록으로 요청을 500 으로 끝내는 대신 서버 로그에 남기고 403 으로 답한다. */
    error_log('[tbox] request_id=' . TBOX_REQUEST_ID
        . ' community page.move deny code=' . $changeCode . ' page_id=' . (string) $pageId);
    Http::error(403, Http::MSG_FORBIDDEN);
}

// ─── [3] 새 부모 ──────────────────────────────────────────────
$parentRow = null;
if ($rawParent !== null) {
    $sqlParent = <<<'SQL'
        SELECT p.id, p.space_id, p.title, p.deleted_at, p.visibility,
               s.kind          AS space_kind,
               s.owner_user_id AS space_owner_user_id,
               s.team_id       AS space_team_id
          FROM community.pages p
          JOIN community.spaces s ON s.id = p.space_id
         WHERE p.public_id = CAST(:pid AS uuid)
           AND p.deleted_at IS NULL
        SQL;
    $found = $db->run($sqlParent, [':pid' => $rawParent])->fetch();
    if (!is_array($found)) {
        // 아무 공간에도 없는 UUID → 「없다」 (4-A 규칙)
        Http::error(404, Http::MSG_NOT_FOUND);
    }
    if ((int) $found['space_id'] !== $spaceId) {
        // 있지만 남의 공간이다 → 403. 남의 개인 페이지에 닿으려 한 일을 남긴다.
        $otherTitle = community_target_title((string) $found['title']);
        Audit::deny('page.read', CommunityAuthz::CODE_NOT_OWNER, [
            'target_type' => 'page',
            'target_id'   => (int) $found['id'],
            'target_name' => $otherTitle,
            'detail'      => ['page_title' => $otherTitle],
        ]);
        Http::error(403, Http::MSG_FORBIDDEN);
    }
    $parentRow = $found;
}

// ─── [4] 트리 모양 — 재귀 CTE **한 번** ───────────────────────
$shape = community_move_shape($pageId, $parentRow === null ? null : (int) $parentRow['id']);
$row['subtree_height'] = $shape['subtree_height'];
// 4-B — 옮겨 가는 가지에서 가장 넓은 범위. 판정은 `fitsUnderParent` 한 곳에서 한다.
$row['subtree_widest'] = $shape['subtree_widest'];
if ($parentRow !== null) {
    $parentRow['depth']        = $shape['parent_depth'] ?? 1;
    $parentRow['ancestor_ids'] = $shape['parent_ancestors'];
}

// ─── [권한 ✔ C] 옮길 수 있나 ──────────────────────────────────
[$mayMove, $moveCode] = CommunityAuthz::decidePageMove($user, $row, $parentRow);
if (!$mayMove) {
    /* 규칙으로 막은 셋 — 같은 모양으로 400 + 감사다. 「권한이 없다」가 아니라 「트리가
       그렇게 되면 안 된다」이므로 403 이 아니라 400 이고, 사유 글자가 곧 화면 문구의 열쇠다. */
    $ruleCodes = [
        CommunityAuthz::CODE_CYCLE            => '자기 자신이나 자기 하위 페이지 아래로는 옮길 수 없습니다',
        CommunityAuthz::CODE_DEPTH_EXCEEDED   => '페이지는 8 단계까지만 겹칠 수 있습니다',
        CommunityAuthz::CODE_WIDER_THAN_PARENT => '옮기려는 페이지가 새 상위 페이지보다 넓게 열려 있습니다',
    ];
    if (isset($ruleCodes[$moveCode])) {
        $detail = ['page_title' => $pageTitle];
        if ($parentRow !== null) {
            $detail['parent_title'] = community_target_title((string) $parentRow['title']);
        }
        if ($moveCode === CommunityAuthz::CODE_WIDER_THAN_PARENT) {
            $detail['visibility_from'] = (string) $row['subtree_widest'];
            $detail['visibility_to']   = (string) ($parentRow['visibility'] ?? '');
        }
        Audit::deny('page.move', $moveCode, [
            'target_type' => 'page',
            'target_id'   => $pageId,
            'target_name' => $pageTitle,
            'detail'      => $detail,
        ]);
        Http::json(['error' => $ruleCodes[$moveCode], 'reason' => $moveCode], 400);
        exit;
    }
    if ($moveCode === CommunityAuthz::CODE_NOT_FOUND) {
        Http::error(404, Http::MSG_NOT_FOUND);
    }
    Http::error(403, Http::MSG_FORBIDDEN);
}

// ─── [5] 옮기기 ───────────────────────────────────────────────
$oldParentId = $row['parent_page_id'] === null ? null : (int) $row['parent_page_id'];
$newParentId = $parentRow === null ? null : (int) $parentRow['id'];

/* 형제 목록 — 새 부모 아래의 **나를 뺀** 살아 있는 페이지들. 이 순서에 나를 끼워 넣고
   0..n-1 로 다시 매긴다. 목록은 SQL 이 좁힌다(같은 공간·안 지워진 것). */
$sqlSiblings = <<<'SQL'
    SELECT p.id
      FROM community.pages p
     WHERE p.space_id = CAST(:space AS bigint)
       AND p.deleted_at IS NULL
       AND p.parent_page_id IS NOT DISTINCT FROM CAST(:parent AS bigint)
       AND p.id <> CAST(:me AS bigint)
     ORDER BY p.position, p.id
     LIMIT 2000
    SQL;

$sqlPosition = <<<'SQL'
    UPDATE community.pages
       SET position = CAST(:pos AS int)
     WHERE id = CAST(:pid AS bigint)
    SQL;

$sqlMove = <<<'SQL'
    UPDATE community.pages
       SET parent_page_id = CAST(:parent AS bigint),
           position = CAST(:pos AS int),
           updated_at = now(),
           last_edited_by = CAST(:me AS bigint)
     WHERE id = CAST(:pid AS bigint)
    SQL;

$db->begin();
try {
    $siblings = [];
    foreach ($db->run($sqlSiblings, [':space' => $spaceId, ':parent' => $newParentId, ':me' => $pageId])->fetchAll() as $s) {
        $siblings[] = (int) $s['id'];
    }

    $at = $wantPosition === null ? count($siblings) : $wantPosition;
    if ($at > count($siblings)) {
        $at = count($siblings);
    }

    array_splice($siblings, $at, 0, [$pageId]);

    foreach ($siblings as $index => $sibId) {
        if ($sibId === $pageId) {
            $db->run($sqlMove, [':parent' => $newParentId, ':pos' => $index, ':me' => $me, ':pid' => $pageId]);
            continue;
        }
        $db->run($sqlPosition, [':pos' => $index, ':pid' => $sibId]);
    }

    /* 떠나온 자리도 0..n-1 로 다시 매긴다 — 빈 번호를 남기면 다음 이동이 그 틈을 두고
       계산하게 되고, 「순서가 0 부터 이어진다」는 약속이 곧 깨진다. */
    $leftBehind = 0;
    if ($oldParentId !== $newParentId) {
        $olds = $db->run($sqlSiblings, [':space' => $spaceId, ':parent' => $oldParentId, ':me' => $pageId])->fetchAll();
        foreach ($olds as $index => $old) {
            $db->run($sqlPosition, [':pos' => $index, ':pid' => (int) $old['id']]);
        }
        $leftBehind = count($olds);
    }

    $detail = [
        'granted_by' => $changeCode,
        'page_title' => $pageTitle,
        // 떠나온 자리에서 번호를 다시 매긴 형제 수. ARCH 7.5 등록부의 `cascaded`(함께 바뀐
        // 항목 수)와 뜻이 같다 — 같은 뜻에 새 이름을 만들지 않는다.
        'cascaded'   => $leftBehind,
    ];
    // 어디 아래로 갔나. 뿌리로 올렸으면 이 칸이 없다(부모가 없다는 뜻이다).
    $detail['parent_title'] = $parentRow === null
        ? null
        : community_target_title((string) $parentRow['title']);
    if ($detail['parent_title'] === null) {
        unset($detail['parent_title']);
    }

    // ★ 세 번째 인자(사유)를 주지 않는다 — 문장 표의 열쇠가 `page.move|allow` 한 줄이기
    //   때문이다. 허용 사유는 `detail.granted_by` 로 남는다 (`page.create` 와 같은 모양).
    Audit::allow('page.move', [
        'target_type' => 'page',
        'target_id'   => $pageId,
        'target_name' => $pageTitle,
        'detail'      => $detail,
    ]);

    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    throw $e;   // bootstrap 예외 핸들러 → 500 + {"error":"서버 오류"}
}

Http::json([
    'page' => [
        'id'        => $pid,
        'parent_id' => $rawParent,
        'position'  => $at,
    ],
    'breadcrumb' => community_breadcrumb($pageId, $spaceId),
]);
