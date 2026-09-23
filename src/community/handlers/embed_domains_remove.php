<?php
declare(strict_types=1);

/**
 * src/community/handlers/embed_domains_remove.php — DELETE /community/api/embed-domains/{num} (정책: role:Admin — 재인증)
 *
 * 요구사항: 지시서 「7-C 확정」 K-1·K-3. 빼기는 `removed_at`(줄은 남는다 — 누가 언제 넣고 뺐나). 그 호스트의 **옛 임베드**는
 * 저장을 막지 않고(K-3), 렌더러가 「허용 목록 밖」 카드로 그린다(틀을 열지 않는다). 감사 `embed.domain_remove allow`.
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params [0] 줄 번호
 */

$sql = <<<'SQL'
    UPDATE community.embed_domains
       SET removed_at = now(), removed_by = CAST(:me AS bigint)
     WHERE id = CAST(:id AS bigint) AND removed_at IS NULL
    RETURNING id, host
    SQL;
$db = Db::instance();
$db->begin();
try {
    $gone = $db->run($sql, [':me' => (int) $user['id'], ':id' => (int) $params[0]])->fetch();
    if (!is_array($gone)) {
        $db->rollback();
        Http::error(404, Http::MSG_NOT_FOUND);
    }
    Audit::allow('embed.domain_remove', [
        'target_type' => 'embed_domain',
        'target_id'   => (int) $gone['id'],
        'target_name' => (string) $gone['host'],
        'detail'      => ['host' => (string) $gone['host']],
    ]);
    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    throw $e;
}
Http::json(['removed' => true, 'host' => (string) $gone['host']]);
