<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_lists_delete.php — DELETE /api/purchase/lists/{num}
 *   (정책: purchase.request.write)
 *
 * ★ 20단계 「내 발주 리스트」 **닫기** (PRD F-31).
 *
 * 메서드는 DELETE 지만 하는 일은 `is_active = false` 다 — `p_lists` 에도 앱 계정의 DELETE
 * 권한이 없다(db/grants.sql). 행이 남아야 "이 리스트로 담았던 요청" 을 나중에 되짚을 수 있다.
 *
 * 닫을 수 있는 사람은 **소유자 본인**뿐이다 (SECURITY R-68). 내 것이 아니면 404 다.
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params $params[0] = p_lists.id
 */

require_once __DIR__ . '/../purchase/Doc.php';

$idRaw = $params[0] ?? '';
if (!is_string($idRaw) || preg_match('/^[0-9]{1,9}$/', $idRaw) !== 1) {
    Http::error(404, Http::MSG_NOT_FOUND);
}
$listId = (int) $idRaw;

$db      = Db::instance();
$scopePl = Authz::purchaseScopeWhere($user, 'pl');

$sqlList = <<<'SQL'
    SELECT pl.id, pl.name, pl.branch_id
      FROM p_lists pl
     WHERE pl.id = CAST(:id AS bigint) AND pl.is_active
       AND pl.owner_id = CAST(:me AS bigint) AND
    SQL;

$list = $db->run(
    $sqlList . ' (' . $scopePl['sql'] . ' OR pl.branch_id IS NULL)',
    [':id' => $listId, ':me' => (int) $user['id']] + $scopePl['params']
)->fetch();
if (!is_array($list)) {
    Http::error(404, Http::MSG_NOT_FOUND);
}

$sqlClose = <<<'SQL'
    UPDATE p_lists SET is_active = false, updated_at = now()
     WHERE id = CAST(:id AS bigint)
    SQL;

$db->begin();
try {
    $db->run($sqlClose, [':id' => $listId]);
    Audit::allow('purchase.list.delete', [
        'target_type' => 'purchase_list',
        'target_id'   => $listId,
        'target_name' => (string) $list['name'],
        'detail'      => [
            'target_id' => $listId,
            'branch_id' => $list['branch_id'] === null ? null : (int) $list['branch_id'],
        ],
    ]);
    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    throw $e;
}

Http::json(['id' => $listId, 'closed' => true]);
