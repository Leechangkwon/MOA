<?php
declare(strict_types=1);

/**
 * src/handlers/me_passkeys_list.php — GET /api/me/passkeys (정책: authenticated) · T:F-72 지시서 1.1 「관리」 · 1.8
 *
 * **내 것만** — 대상은 세션의 본인이고(요청이 사람을 고르지 않는다) 해제된 열쇠는 나오지 않는다.
 * 줄마다: 행 번호 · 이름 · 기기 요약 · 등록한 날 · 마지막 사용 · 상태(`active` 쓰는 중 / `stale` 90일 안 써서 꺼짐 — 판정은 DB 시계) ·
 * 「계정에 동기화됨」(backed_up). 열쇠 id·공개 키·user_handle·AAGUID 는 응답에 없다(지시서 1.6 「기록 금지」).
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params
 */

require_once __DIR__ . '/../Passkey.php';

if (!Passkey::available()) {
    Http::json(['available' => false, 'passkeys' => [], 'max' => Passkey::MAX_PER_USER]);
    exit;
}

$sql = <<<'SQL'
    SELECT id, name, device_hint, backed_up, created_at, last_used_at,
           (COALESCE(last_used_at, created_at) < now() - make_interval(days => CAST(:idle AS integer))) AS is_stale
      FROM passkeys
     WHERE user_id = :uid AND revoked_at IS NULL
     ORDER BY id
    SQL;

$out = [];
foreach (Db::instance()->run($sql, [':uid' => (int) $user['id'], ':idle' => Passkey::IDLE_DAYS])->fetchAll() as $r) {
    $out[] = [
        'id'           => (int) $r['id'],
        'name'         => (string) $r['name'],
        'device_hint'  => (string) $r['device_hint'],
        'created_at'   => (string) $r['created_at'],
        'last_used_at' => $r['last_used_at'] === null ? null : (string) $r['last_used_at'],
        'state'        => $r['is_stale'] === true ? 'stale' : 'active',
        'synced'       => $r['backed_up'] === true,
    ];
}

Http::json(['available' => true, 'passkeys' => $out, 'max' => Passkey::MAX_PER_USER]);
