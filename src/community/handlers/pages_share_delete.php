<?php
declare(strict_types=1);

/**
 * src/community/handlers/pages_share_delete.php — DELETE /community/api/pages/{id}/shares/{username}
 *                                                 (정책: community.read — 아래 ★)
 *
 * 요구사항: 5-A 지시서 「창구」 — 「Manage (또는 **자기 자신의 공유 빼기**)」.
 *
 * 이 페이지에 **직접** 준 공유 한 줄을 뺀다. 조상에서 물려받은 공유는 그 조상에서 빼야 한다 —
 * 이 페이지에는 그 줄이 없으므로 404 다(「무엇을 뺐나」가 늘 한 줄로 분명하다).
 * 빼는 그 순간부터 판정이 거짓이 된다 — 다음 요청의 `community_page_row()` 가 다시 센다.
 *
 * 누가 뺄 수 있나
 *   · 관리할 수 있는 사람(`decidePageManage` — 소유자·팀 공간 팀원) — 누구의 줄이든
 *   · **공유받은 본인** — 자기 줄만. 받고 싶지 않은 공유를 스스로 거둘 수 있어야 한다.
 *   그 밖(편집 공유자가 남의 줄을 빼려는 것 포함) → 403 + 감사 `page.share deny not_owner`
 *
 * ★ 라우트 정책이 `community.read` 인 이유: Guest 도 **보기 공유**를 받을 수 있고, 받은 사람은
 *   자기 줄을 뺄 수 있어야 한다. `community.write` 로 두면 Guest 는 라우터에서 끝난다.
 *   권한은 여기서 행 단위로 다시 판단하고(위 두 갈래), CSRF 는 라우터가 DELETE 에 이미 요구한다.
 *
 * 받는 값: **없다**(빈 JSON `{}` 또는 몸통 없음). 키가 하나라도 있으면 400.
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params [0] = 페이지 public_id (UUID), [1] = 뺄 사람 계정명
 */

$db   = Db::instance();
$pid  = (string) $params[0];
$name = (string) $params[1];

// 몸통이 있으면 JSON 이어야 하고 키가 없어야 한다(허용 키 0).
$rawLen = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($rawLen > 0 && Http::jsonBody() !== []) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}

// ─── [권한 ✔ A] 볼 수 있나 ────────────────────────────────────
$gate      = community_history_gate($user, $pid);
$row       = $gate['row'];
$pageId    = (int) $row['id'];
$pageTitle = community_target_title((string) $row['title']);

// ─── [권한 ✔ B] 관리자이거나 자기 줄인가 ───────────────────────
$self = $name === (string) $user['username'];
[$mayManage, $manageCode] = CommunityAuthz::decidePageManage($user, $row);
if (!$mayManage && !$self) {
    Audit::deny('page.share', CommunityAuthz::CODE_NOT_OWNER, [
        'target_type' => 'page',
        'target_id'   => $pageId,
        'target_name' => $pageTitle,
        'detail'      => ['page_title' => $pageTitle, 'share_user' => $name],
    ]);
    Http::error(403, Http::MSG_FORBIDDEN);
}
$grantedBy = $mayManage ? $manageCode : CommunityAuthz::CODE_SHARED;

$target = community_user_by_name($name);
if ($target === null) {
    Http::error(404, Http::MSG_NOT_FOUND);
}

$sqlDelete = <<<'SQL'
    DELETE FROM community.page_shares
     WHERE page_id = CAST(:pid AS bigint) AND user_id = CAST(:uid AS bigint)
    RETURNING level
    SQL;

$db->begin();
try {
    $gone = $db->run($sqlDelete, [':pid' => $pageId, ':uid' => (int) $target['id']])->fetch();
    if (!is_array($gone)) {
        // 이 페이지에 직접 준 줄이 없다(물려받은 공유이거나 이미 뺐다) — 뺄 것이 없다.
        $db->rollback();
        Http::error(404, Http::MSG_NOT_FOUND);
    }

    Audit::allow('page.share', [
        'target_type' => 'page',
        'target_id'   => $pageId,
        'target_name' => $pageTitle,
        'detail'      => [
            'granted_by'  => $grantedBy,
            'page_title'  => $pageTitle,
            'share_user'  => (string) $target['username'],
            'share_from'  => (string) $gone['level'],
            // 뺐다 — 새 등급이 없다.
            'share_level' => null,
        ],
    ]);

    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    throw $e;   // bootstrap 예외 핸들러 → 500 + {"error":"서버 오류"}
}

Http::json([
    'removed' => ['username' => (string) $target['username'], 'level' => (string) $gone['level']],
]);
