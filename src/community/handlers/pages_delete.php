<?php
declare(strict_types=1);

/**
 * src/community/handlers/pages_delete.php — DELETE /community/api/pages/{id} (정책: community.write)
 *
 * 요구사항: `C:F-17` 최소, `C:F-29`(휴지통의 첫 걸음), 수락 기준 C:T-17,
 *           `../../../../community/ARCHITECTURE.md` 13.3 · 16장.
 *
 * 하는 일 — **논리 삭제**다. 행을 지우지 않고 `deleted_at`·`deleted_by`·`purge_after` 를 놓는다.
 *   · 판 이력(`page_versions`)·체크 기록(`checklist_events`)이 페이지를 참조하므로, 행을
 *     지우면 지울 수 없어야 할 기록이 함께 끊긴다 (C:S-14).
 *   · 하위 페이지도 **함께** 내린다(재귀 CTE) — 부모만 사라지고 자식이 목록에 남으면
 *     「어디에도 속하지 않은 페이지」가 생긴다.
 *   · 30일 뒤 정리는 7단계의 배치가 한다. 3단계에는 되살리기 화면이 없고, 되살리는 길은
 *     그때 함께 들어온다(그래서 지금은 **지워지지 않는다** — 보이지 않을 뿐이다).
 *
 * 검사 순서
 *   [1] 행+공간 → 404 → [권한 ✔ A] `decidePageRead` → [권한 ✔ B] `decidePageChange`
 *   [2] 트랜잭션: 하위까지 `deleted_at` → 감사 `page.delete allow` → 200
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params [0] = 페이지 public_id (UUID)
 */

$db  = Db::instance();
$me  = (int) $user['id'];
$pid = (string) $params[0];

$row = community_page_row($pid);
if ($row === null) {
    Http::error(404, Http::MSG_NOT_FOUND);
}
$pageId    = (int) $row['id'];
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
        . ' community page.read deny code=' . $readCode . ' (3단계에 없는 조합)');
    Http::error(403, Http::MSG_FORBIDDEN);
}

// ─── [권한 ✔ B] 고칠 수 있나 (지우기도 고치기다) ───────────────
[$mayChange, $changeCode] = CommunityAuthz::decidePageChange($user, $row);
if (!$mayChange) {
    /* 여기서 막히는 사람은 「볼 수는 있으나 고칠 수 없는 사람」이다 — 관리자, 또는 남의
       페이지를 팀·사내 범위로 보고 있는 사람.
       ★ 4-C 에서 **감사로 바꿨다**(3단계 주석의 약속 그대로). 남의 페이지를 지우려 한 시도는
         읽기·고치기 시도와 같은 무게인데 그때는 서버 로그에만 남아, 감사 화면에서는 보이지
         않았다. 문장 `page.delete|deny|not_owner` 를 `src/Audit.php` 에 더하고 여기서 쓴다. */
    if ($changeCode === CommunityAuthz::CODE_NOT_OWNER) {
        Audit::deny('page.delete', CommunityAuthz::CODE_NOT_OWNER, [
            'target_type' => 'page',
            'target_id'   => $pageId,
            'target_name' => $pageTitle,
            'detail'      => ['page_title' => $pageTitle],
        ]);
        Http::error(403, Http::MSG_FORBIDDEN);
    }
    // 아직 문장 틀이 없는 조합(팀원이 아닌 팀 공간 등)은 서버 로그에만 — 판정은 이미 끝났다.
    error_log('[tbox] request_id=' . TBOX_REQUEST_ID
        . ' community page.delete deny code=' . $changeCode . ' page_id=' . (string) $pageId);
    Http::error(403, Http::MSG_FORBIDDEN);
}

// ─── [2] 논리 삭제 (하위까지) ─────────────────────────────────
$sqlDelete = <<<'SQL'
    WITH RECURSIVE tree AS (
        SELECT id FROM community.pages
         WHERE id = CAST(:pid AS bigint) AND deleted_at IS NULL
        UNION ALL
        SELECT c.id FROM community.pages c
          JOIN tree t ON c.parent_page_id = t.id
         WHERE c.deleted_at IS NULL
    )
    UPDATE community.pages
       SET deleted_at = now(),
           deleted_by = CAST(:me AS bigint),
           purge_after = now() + interval '30 days',
           updated_at = now()
     WHERE id IN (SELECT id FROM tree)
    SQL;

$db->begin();
try {
    $st      = $db->run($sqlDelete, [':pid' => $pageId, ':me' => $me]);
    $removed = $st->rowCount();
    if ($removed < 1) {
        // 읽고 나서 여기 오기까지 사이에 다른 요청이 먼저 지웠다 — 없는 것과 같다.
        $db->rollback();
        Http::error(404, Http::MSG_NOT_FOUND);
    }

    Audit::allow('page.delete', [
        'target_type' => 'page',
        'target_id'   => $pageId,
        'target_name' => $pageTitle,
        'detail'      => [
            'granted_by'  => $changeCode,
            'page_title'  => $pageTitle,
            'doc_version' => (int) $row['doc_version'],
            /* 하위까지 몇 건이 함께 내려갔나 — **개수만** 남긴다(어떤 페이지인지는 남기지
               않는다). `cascaded` 는 t-box ARCH 7.5 등록부에 이미 있는 키이고 뜻이 같다
               (「함께 바뀐 항목 수」) — 같은 뜻에 새 이름을 만들지 않는다. */
            'cascaded'    => $removed,
        ],
    ]);

    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    throw $e;   // bootstrap 예외 핸들러 → 500 + {"error":"서버 오류"}
}

Http::json(['deleted' => $removed]);
