<?php
declare(strict_types=1);

/**
 * src/community/handlers/page_restore.php — POST /community/api/pages/{id}/restore
 *                                           (정책: community.write)
 *
 * 요구사항: `C:F-29`(휴지통 되살리기), `../../../../community/ARCHITECTURE.md` 16장,
 *           수락 기준 C:T-43.
 *
 * 논리 삭제를 되돌린다 — `deleted_at`·`deleted_by`·`purge_after` 를 비운다. 행을 새로 만들지
 * 않으므로 판 이력·체크 기록이 그대로 이어진다 (C:S-14).
 *
 * 함께 돌아오는 것: **같은 순간에 함께 내려간 하위 전부**다. 한 번의 삭제는 `UPDATE` 한
 * 문장이라 그 안의 행이 모두 같은 `deleted_at` 을 받는다 — 그 값이 같은 가지만 따라 올라온다.
 * 따로 지웠던 하위는 시각이 달라 그대로 휴지통에 남는다(따로 지운 것은 따로 되살린다).
 *
 * ★ **원래 자리로 돌아갈 수 없으면 뿌리로 올린다** (지시서). 400 으로 막지 않는다 — 「되살리려면
 *   부모를 먼저 되살리세요」는 사람이 할 일을 늘릴 뿐이고, 어디에도 매달리지 않은 페이지를
 *   만드는 것보다 뿌리에 놓는 편이 낫다. 대신 **응답과 감사에 그 사실을 적는다**
 *   (`moved_to_root: true`, 까닭은 응답의 `moved_reason`) — 조용히 옮기지 않는다.
 *   갈래 둘: ① 부모가 아직 휴지통에 있다 ② 부모가 그 사이 **좁아졌다**(4-B 트리 불변 규칙 —
 *   좁히기 검사는 살아 있는 하위만 보므로 지워진 가지는 옛 범위를 들고 남아 있다).
 *
 * 받는 값: **없다**(빈 JSON `{}`).
 *
 * 검사 순서
 *   [1] 빈 본문 → [2] 행+공간 → 404 → [3] 지워진 상태인가 → 아니면 404(되살릴 것이 없다)
 *   [권한 ✔] `decidePageRestore`(= `decidePageManage` 와 같은 자격 — 5-A, 편집 공유로는 안 된다) 실패 →
 *            **403** + 감사 `page.restore deny not_owner`
 *   [4] 트랜잭션: 가지 되살리기 → (필요하면) 뿌리로 올리기 → 감사 `page.restore allow` → 200
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params [0] = 페이지 public_id (UUID)
 */

$body = Http::jsonBody();
if ($body !== []) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}

$db  = Db::instance();
$me  = (int) $user['id'];
$pid = (string) $params[0];

// ─── [2] 행 + 공간 ────────────────────────────────────────────
$row = community_page_row($pid, (int) $user['id']);
if ($row === null) {
    Http::error(404, Http::MSG_NOT_FOUND);
}
$pageId    = (int) $row['id'];
$pageTitle = community_target_title((string) $row['title']);

// ─── [3] 지워진 상태인가 ──────────────────────────────────────
// 살아 있는 페이지에 되살리기를 부르면 되살릴 것이 없다. 「이미 살아 있다」를 알려 줄 이유도
// 없으므로 없는 것과 같이 404 다.
if ($row['deleted_at'] === null) {
    Http::error(404, Http::MSG_NOT_FOUND);
}

// ─── [권한 ✔] 되살릴 수 있나 ──────────────────────────────────
[$mayRestore, $restoreCode] = CommunityAuthz::decidePageRestore($user, $row);
if (!$mayRestore) {
    if ($restoreCode === CommunityAuthz::CODE_NOT_OWNER) {
        Audit::deny('page.restore', CommunityAuthz::CODE_NOT_OWNER, [
            'target_type' => 'page',
            'target_id'   => $pageId,
            'target_name' => $pageTitle,
            'detail'      => ['page_title' => $pageTitle],
        ]);
        Http::error(403, Http::MSG_FORBIDDEN);
    }
    error_log('[tbox] request_id=' . TBOX_REQUEST_ID
        . ' community page.restore deny code=' . $restoreCode . ' page_id=' . (string) $pageId);
    Http::error(403, Http::MSG_FORBIDDEN);
}

/* 지운 사람만 되살린다 (지시서 「내가 지운 페이지」). 자격(`decidePageRestore`)을 지나도
   남이 지운 것을 꺼내지는 않는다 — 팀 공간에서 팀원 여럿이 서로의 삭제를 되돌리면 「누가
   지웠나」의 기록이 뜻을 잃는다. 그 사실은 위 감사 행과 같은 무게이므로 같은 줄을 쓴다. */
if ($row['deleted_by'] === null || (int) $row['deleted_by'] !== $me) {
    Audit::deny('page.restore', CommunityAuthz::CODE_NOT_OWNER, [
        'target_type' => 'page',
        'target_id'   => $pageId,
        'target_name' => $pageTitle,
        'detail'      => ['page_title' => $pageTitle],
    ]);
    Http::error(403, Http::MSG_FORBIDDEN);
}

// ─── 원래 자리로 돌아갈 수 있나 ───────────────────────────────
/* 두 가지를 본다.
 *   ① 부모가 아직 휴지통에 있나            (`parent_deleted`)
 *   ② 부모가 그 사이 **좁아졌나**          (`parent_narrower`, 4-B 트리 불변 규칙)
 * ②는 지운 뒤에 생길 수 있는 일이다 — 하위를 지운 다음 상위를 좁히면, 좁히기 검사
 * (`community_descendants_wider`)는 **살아 있는 하위만** 보므로 지워진 가지는 옛 범위를
 * 그대로 들고 휴지통에 남는다. 그 가지를 그 자리에 되살리면 「자식이 부모보다 넓다」가
 * 생긴다. 400 으로 막지 않고 **뿌리로 올린다** — 되살리기를 거절하면 사람은 상위를
 * 먼저 넓혔다가 도로 좁히는 일을 해야 하고, 그 사이에 오히려 더 넓게 열린다.
 * 뿌리에는 견줄 상대가 없으므로 범위는 그대로 두고, **그렇게 했다고 응답에 적는다**.
 */
$movedToRoot  = false;
$movedReason  = null;
if ($row['parent_page_id'] !== null) {
    $sqlParent = <<<'SQL'
        SELECT p.id, p.visibility, p.published_from
          FROM community.pages p
         WHERE p.id = CAST(:pid AS bigint)
           AND p.space_id = CAST(:space AS bigint)
           AND p.deleted_at IS NULL
        SQL;
    $parent = $db->run($sqlParent, [
        ':pid'   => (int) $row['parent_page_id'],
        ':space' => (int) $row['space_id'],
    ])->fetch();

    if (!is_array($parent)) {
        $movedToRoot = true;
        $movedReason = 'parent_deleted';
    } elseif ((string) $row['space_kind'] === 'personal') {
        /* 되살아날 가지에서 **가장 넓은 범위**를 SQL 이 센다(4-B `community_move_shape` 와 같은
           방식). 팀 공간의 페이지에는 자기 범위가 없으므로 이 비교를 하지 않는다. */
        $sqlWidest = <<<'SQL'
            WITH RECURSIVE down AS (
                -- 5-C: 넓이는 기준 범위(게시 중이면 게시 전 범위)로 센다 — `community_move_shape` 와 같다.
                SELECT p.id, (CASE WHEN p.visibility = 'published' THEN p.published_from ELSE p.visibility END) AS visibility,
                       p.deleted_at
                  FROM community.pages p
                 WHERE p.id = CAST(:pid AS bigint) AND p.deleted_at IS NOT NULL
                UNION ALL
                SELECT c.id, (CASE WHEN c.visibility = 'published' THEN c.published_from ELSE c.visibility END),
                       c.deleted_at
                  FROM community.pages c
                  JOIN down d ON c.parent_page_id = d.id
                 WHERE c.deleted_at = d.deleted_at
            )
            SELECT max(CASE visibility WHEN 'private' THEN 0 WHEN 'team' THEN 1
                                       WHEN 'internal' THEN 2 WHEN 'published' THEN 3 END) AS widest_rank
              FROM down
            SQL;
        $widestRow = $db->run($sqlWidest, [':pid' => $pageId])->fetch();
        // 번호를 이름으로 되돌린다. 모르는 번호는 **가장 넓은 값**으로 본다 — 모르면 막는 쪽이다.
        $names  = ['private', 'team', 'internal', 'published'];
        $rank   = is_array($widestRow) && $widestRow['widest_rank'] !== null ? (int) $widestRow['widest_rank'] : 0;
        $widest = $names[$rank] ?? 'published';

        if (!CommunityAuthz::fitsUnderParent($widest, (string) (CommunityAuthz::baseVisibility($parent) ?? ''))) {
            $movedToRoot = true;
            $movedReason = 'parent_narrower';
        }
    }
}

// ─── [4] 되살리기 ─────────────────────────────────────────────
/* 같은 순간에 내려간 가지를 통째로 올린다. 재귀 CTE **한 번**이고, 조건 `c.deleted_at =
   d.deleted_at` 이 「함께 지운 것」의 정의다. */
$sqlRestore = <<<'SQL'
    WITH RECURSIVE down AS (
        SELECT p.id, p.deleted_at
          FROM community.pages p
         WHERE p.id = CAST(:pid AS bigint) AND p.deleted_at IS NOT NULL
        UNION ALL
        SELECT c.id, d.deleted_at
          FROM community.pages c
          JOIN down d ON c.parent_page_id = d.id
         WHERE c.deleted_at = d.deleted_at
    )
    UPDATE community.pages
       SET deleted_at = NULL,
           deleted_by = NULL,
           purge_after = NULL,
           updated_at = now()
     WHERE id IN (SELECT id FROM down)
    SQL;

$sqlToRoot = <<<'SQL'
    UPDATE community.pages
       SET parent_page_id = NULL,
           position = CAST(:pos AS int),
           updated_at = now()
     WHERE id = CAST(:pid AS bigint)
       AND deleted_at IS NULL
    SQL;

$db->begin();
try {
    $restored = $db->run($sqlRestore, [':pid' => $pageId])->rowCount();
    if ($restored < 1) {
        // 읽고 나서 여기 오기까지 사이에 다른 요청이 먼저 되살렸다 — 없는 것과 같다.
        $db->rollback();
        Http::error(404, Http::MSG_NOT_FOUND);
    }

    if ($movedToRoot) {
        // 형제의 맨 뒤에 놓는다. 되살린 뒤에 세므로 자기 자리까지 셈에 든다(끝자리가 나온다).
        $db->run($sqlToRoot, [
            ':pos' => community_next_position((int) $row['space_id'], null),
            ':pid' => $pageId,
        ]);
        // 6-A — 부모(DB 페이지)가 없어져 뿌리로 돌아왔으면 행이 아니다
        community_db_sync_row($pageId, (int) $user['id']);
    }

    Audit::allow('page.restore', [
        'target_type' => 'page',
        'target_id'   => $pageId,
        'target_name' => $pageTitle,
        'detail'      => [
            'granted_by'    => $restoreCode,
            'page_title'    => $pageTitle,
            // 함께 돌아온 하위 수(자기 자신을 뺀 값) — `page.delete` 의 같은 키와 짝이다.
            'cascaded'      => $restored - 1,
            'moved_to_root' => $movedToRoot,
        ],
    ]);

    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    throw $e;   // bootstrap 예외 핸들러 → 500 + {"error":"서버 오류"}
}

Http::json([
    'page' => [
        'id'    => $pid,
        'title' => (string) $row['title'],
    ],
    // 자기 자신을 포함해 되살아난 행 수
    'restored'      => $restored,
    'moved_to_root' => $movedToRoot,
    /* 왜 올렸나 — `parent_deleted`(상위가 아직 휴지통에 있다) · `parent_narrower`(상위가 그
       사이 좁아졌다). **응답에만** 싣는다: 감사에는 「올렸다/아니다」가 남고, 그 까닭은
       같은 순간의 `page.visibility` 행과 `page.delete` 행에 이미 남아 있다 — 감사 등록부
       (ARCH 7.5)에 뜻이 겹치는 키를 늘리지 않는다. */
    'moved_reason'  => $movedReason,
]);
