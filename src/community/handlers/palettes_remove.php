<?php
declare(strict_types=1);

/**
 * src/community/handlers/palettes_remove.php — DELETE /community/api/palettes/{num} (정책: role:Admin — 재인증)
 *
 * 요구사항: `C:F-33` · 지시서 7-D2 P-1 · P-10 · P-13 (7-C `embed_domains_remove.php` 와 같은 모양).
 *   빼기는 `removed_at`(줄은 남는다 — 누가 언제 넣고 뺐나 · 앱 계정에 DELETE 권한 없음). 그 이름을 쓴 **옛 글**은 저장을 막지 않고
 *   (이미 든 이름은 통과 — P-9), 색 없이 그려진다(CSS 에 규칙이 없어진다 — 렌더러는 목록을 모른다, P-10).
 *   없는 줄·이미 뺀 줄은 같은 404. 감사 `palette.remove allow`(detail name·light·dark). 응답 `{removed:true, name, version}`.
 *   표가 없거나 앱 계정이 못 읽는 창(반영 순서가 어긋남)은 500 이 아니라 503 `palette_unavailable`(감사 0 — `community_palette_unavailable`).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params [0] 줄 번호
 */

$sql = <<<'SQL'
    UPDATE community.palettes
       SET removed_at = now(), removed_by = CAST(:me AS bigint)
     WHERE id = CAST(:id AS bigint) AND removed_at IS NULL
    RETURNING id, name, light, dark
    SQL;
$db = Db::instance();
$auditing = false;   // 감사 쓰기 전 문장의 예외만 503 후보(아래 catch)
$db->begin();
try {
    $gone = $db->run($sql, [':me' => (int) $user['id'], ':id' => (int) $params[0]])->fetch();
    if (!is_array($gone)) {
        $db->rollback();
        Http::error(404, Http::MSG_NOT_FOUND);
    }
    $auditing = true;
    Audit::allow('palette.remove', [
        'target_type' => 'palette',
        'target_id'   => (int) $gone['id'],
        'target_name' => (string) $gone['name'],
        'detail'      => ['name' => (string) $gone['name'], 'light' => (string) $gone['light'], 'dark' => (string) $gone['dark']],
    ]);
    $db->commit();
} catch (PDOException $e) {
    $db->rollback();
    if (!$auditing) {
        community_palette_unavailable($e);   // 표 없음·권한 없음이면 503 으로 닫고 끝난다 — 그 밖은 아래에서 그대로 던진다
    }
    throw $e;
} catch (Throwable $e) {
    $db->rollback();
    throw $e;
}
Http::json(['removed' => true, 'name' => (string) $gone['name'], 'version' => community_palette_version(true)]);
