<?php
declare(strict_types=1);

/**
 * src/community/handlers/embed_domains_add.php — POST /community/api/embed-domains (정책: role:Admin — 재인증 관문이 따라붙는다)
 *
 * 요구사항: `C:F-32` · T-33 · 지시서 「7-C 확정」 K-1·K-2.
 *   받는 값 `{host}` — 호스트 이름 **하나**(https 는 붙이지 않는다). 앞뒤 빈칸을 떼고 소문자로 바꾼 뒤 `Doc::isEmbedHost` 모양이어야 한다
 *   (IP·포트·경로·와일드카드·사용자 정보·비 ASCII 는 400 — 국제 도메인은 punycode `xn--` 로).
 *   살아 있는 것 20개까지(400 `too_many_domains`) · 이미 있으면 400 `exists`. 감사 `embed.domain_add allow`.
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 */

$body = Http::jsonBody();
if (array_diff(array_keys($body), ['host']) !== [] || !is_string($body['host'] ?? null)) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}
$host = strtolower(trim((string) $body['host']));
if (!Doc::isEmbedHost($host)) {
    Http::json(['error' => '호스트 이름만 적습니다 — 예: www.youtube.com (https·경로·포트·IP·와일드카드 없이, 국제 도메인은 xn-- 형태)', 'reason' => 'bad_host'], 400);
    exit;
}

$sqlLock = <<<'SQL'
    SELECT pg_advisory_xact_lock(hashtext('community.embed_domains'))
    SQL;
$sqlCount = <<<'SQL'
    SELECT count(*) AS n, count(*) FILTER (WHERE e.host = :host) AS same
      FROM community.embed_domains e WHERE e.removed_at IS NULL
    SQL;
$sqlInsert = <<<'SQL'
    INSERT INTO community.embed_domains (host, added_by) VALUES (:host, CAST(:me AS bigint)) RETURNING id, added_at
    SQL;

$db = Db::instance();
$db->begin();
try {
    $db->run($sqlLock);
    $c = $db->run($sqlCount, [':host' => $host])->fetch();
    if ((int) ($c['same'] ?? 0) > 0) {
        $db->rollback();
        Http::json(['error' => '이미 허용 목록에 있습니다', 'reason' => 'exists'], 400);
        exit;
    }
    if ((int) ($c['n'] ?? 0) >= COMMUNITY_EMBED_MAX_DOMAINS) {
        $db->rollback();
        Http::json(['error' => '허용 목록은 ' . COMMUNITY_EMBED_MAX_DOMAINS . '개까지입니다', 'reason' => 'too_many_domains'], 400);
        exit;
    }
    $new = $db->run($sqlInsert, [':host' => $host, ':me' => (int) $user['id']])->fetch();
    Audit::allow('embed.domain_add', [
        'target_type' => 'embed_domain',
        'target_id'   => (int) $new['id'],
        'target_name' => $host,
        'detail'      => ['host' => $host],
    ]);
    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    throw $e;
}
Http::json(['domain' => ['id' => (int) $new['id'], 'host' => $host, 'added_at' => (string) $new['added_at']]], 201);
