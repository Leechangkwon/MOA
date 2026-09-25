<?php
declare(strict_types=1);

/**
 * src/handlers/push_subscriptions.php — GET·POST /api/admin/push/subscriptions (정책: role:Admin + 재인증, POST 는 CSRF)
 *
 * 요구사항: PRD F-70 · 지시서 docs/f70-push-alerts-directive.md D 표(받기 목록 · 받기 등록) · L-4 · L-6 ·
 *           ARCH 5.32 · 7.4 `alert.push_subscribe` · API.md 15장 · SECURITY R-99 ①
 *
 * GET  — **내** 살아 있는 받기 목록 `{id, host, device_hint, created_at, last_ok_at}`. 주소(endpoint)·기기 키·auth 는
 *        응답에 없다(D 표 「엔드포인트 원문 없음」).
 * POST — 받기 등록. 본문은 **허용 키 셋만**: `{"endpoint": "…", "keys": {"p256dh": "…", "auth": "…"}}`
 *        (브라우저 `PushSubscription.toJSON()` 의 `expirationTime` 같은 다른 키가 섞이면 400 — 화면 스크립트가 셋만 골라 보낸다).
 *   검사 순서
 *     ① 모양 — 키 셋 · 문자열 · endpoint ≤ 1,024바이트                                     → 400 bad_request
 *     ② 주소 — `WebPush::checkEndpoint`(= Fetch 의 pushEndpoint 한 벌): https · 443 · 사용자정보·IP 리터럴 없음 · 호스트 표기 → 400 bad_endpoint
 *              호스트가 PUSH_HOSTS **정확 일치**(fcm · apple · mozilla — L-4)                            → 400 host_not_allowed
 *              ★ DNS 를 묻지 않는다 — 웹 요청은 밖으로 나가지 않는다(원칙 1). 보낼 때 배치가 푼다.
 *     ③ 기기 키 — p256dh base64url → 65바이트 · 0x04 · **P-256 곡선 위의 점** · auth 16바이트 → 400 bad_key
 *     ④ 같은 주소의 살아 있는 받기 — 내 것이면 200 그대로(다시 누름), 남의 것이면           → 400 duplicate
 *     ⑤ 사람당 살아 있는 받기 3개까지(L-6) — 사람 행을 잠근 뒤 세서 겹친 두 요청도 넘지 못한다 → 400 too_many
 *   통과하면 INSERT(행은 지우지 않는다 — 해지는 revoked_at) + 감사 `alert.push_subscribe allow` detail `{host, device_hint}`.
 *
 * ★ 감사·응답·로그에 endpoint·p256dh·auth 원문을 **한 글자도** 넣지 않는다(지시서 금지 패턴). 거부 감사의 target 은
 *   정규화에 성공한 호스트뿐이다(host_not_allowed). 실패한 글자는 남기지 않는다(Fetch 의 check() 와 같은 원칙).
 * ★ 외부 요청 0 — 이 파일에 Fetch 호출이 없다(보내는 함수도 검사 함수도 직접 부르지 않는다. tests/f70.sh T3 grep).
 * ★ 역할 판단을 여기서 하지 않는다 — 라우트 정책 role:Admin 과 재인증 관문이 먼저 막았다 (PRD S-01).
 *   보낼 때마다 「아직 활성 Admin 인가」는 배치가 SQL 로 다시 본다(원칙 2).
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params
 */

require_once __DIR__ . '/../WebPush.php';

/** 사람당 살아 있는 받기 상한 (L-6) */
$maxDevices = 3;

$db     = Db::instance();
$userId = (int) $user['id'];

// 서버 쪽 반영 전(표 없음)이면 500 이 아니라 503 — 저장소 쪽만 먼저 반영된 상태
if (!WebPush::tablesReady($db)) {
    Http::error(503, WebPush::NOT_READY_MESSAGE);
}

/** 감사 「무엇을」 칸의 기본 — 받기 행이 생기기 전에는 번호가 없다 */
$auditBase = ['target_type' => 'push_subscription'];

if (Http::method() === 'GET') {
    $rows = $db->run(
        'SELECT id, host, device_hint, created_at, last_ok_at
           FROM alert_push_subscriptions
          WHERE user_id = :uid AND revoked_at IS NULL
          ORDER BY id',
        [':uid' => $userId]
    )->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'          => (int) $r['id'],
            'host'        => (string) $r['host'],
            'device_hint' => (string) $r['device_hint'],
            'created_at'  => (string) $r['created_at'],
            'last_ok_at'  => $r['last_ok_at'] === null ? null : (string) $r['last_ok_at'],
        ];
    }
    Http::json(['subscriptions' => $out, 'max_devices' => $maxDevices]);
    exit;
}

// ─── POST — 받기 등록 ─────────────────────────────────────────
$body = Http::jsonBody(4096, 4);

/** 거부 한 줄 — 감사(값 없이) + 같은 모양의 400. 문구는 대상 값을 담지 않는다. */
$reject = static function (string $reason, ?string $host = null) use ($auditBase, $maxDevices): void {
    Audit::deny('alert.push_subscribe', $reason, $auditBase + ($host === null ? [] : ['target_name' => $host]));
    $messages = [
        'bad_request'      => '받기 정보의 모양이 맞지 않습니다',
        'bad_endpoint'     => '받기 주소가 올바르지 않습니다',
        'host_not_allowed' => '이 브라우저의 푸시 서버는 쓸 수 없습니다 (안드로이드 Chrome · 아이폰 Safari · Firefox 만)',
        'bad_key'          => '받기 키가 올바르지 않습니다',
        'too_many'         => '기기는 한 사람 ' . $maxDevices . '대까지 등록할 수 있습니다 — 쓰지 않는 기기를 먼저 해지하세요',
        'duplicate'        => '이 브라우저의 받기는 다른 관리자 계정에 등록돼 있습니다 — 그 계정에서 해지한 뒤 다시 켜세요',
    ];
    Http::error(400, $messages[$reason] ?? Http::MSG_BAD_REQUEST);
};

// ① 모양 — 허용 키 셋만
$keys = $body['keys'] ?? null;
$bodyKeys = array_keys($body);
sort($bodyKeys);
if ($bodyKeys !== ['endpoint', 'keys']
    || !is_string($body['endpoint'] ?? null)
    || !is_array($keys)
) {
    $reject('bad_request');
}
$keyNames = array_keys($keys);
sort($keyNames);
if ($keyNames !== ['auth', 'p256dh'] || !is_string($keys['p256dh']) || !is_string($keys['auth'])) {
    $reject('bad_request');
}
$endpointRaw = (string) $body['endpoint'];
if ($endpointRaw === '' || strlen($endpointRaw) > 1024) {   // D 표 endpoint ≤ 1,024 (Fetch 의 PUSH_MAX_ENDPOINT 와 같은 값)
    $reject('bad_request');
}

// ② 주소 — https · 443 · 표기 · PUSH_HOSTS 정확 일치 (네트워크 없음 — WebPush::checkEndpoint 가 Fetch 의 같은 검사를 감싼다)
$checked = WebPush::checkEndpoint($endpointRaw);
if ($checked['ok'] !== true) {
    $reject((string) $checked['reason'], $checked['host']);
}
$endpoint = (string) $checked['url'];
$host     = (string) $checked['host'];

// ③ 기기 키 — 65바이트 · 0x04 · 곡선 위의 점 · auth 16바이트
try {
    $raw = WebPush::checkSubscriptionKeys((string) $keys['p256dh'], (string) $keys['auth']);
} catch (WebPushError $e) {
    $reject('bad_key', $host);
    exit;
}

$deviceHint = WebPush::deviceHint(Http::userAgent());

// ④⑤ — 사람 행을 잠그고(같은 사람의 겹친 요청을 줄 세운다) 같은 주소 · 개수를 본 뒤 넣는다. 한 트랜잭션.
/** 같은 사람의 겹친 요청을 줄 세우는 잠금 — 사람 행 하나(트랜잭션이 끝나면 풀린다) */
$sqlLockUser = <<<'SQL'
    SELECT id FROM users WHERE id = :uid FOR UPDATE
    SQL;

$db->begin();
try {
    $db->run($sqlLockUser, [':uid' => $userId]);

    $same = $db->run(
        'SELECT id, user_id, host, device_hint, created_at, last_ok_at
           FROM alert_push_subscriptions
          WHERE endpoint = :endpoint AND revoked_at IS NULL',
        [':endpoint' => $endpoint]
    )->fetch();
    if (is_array($same)) {
        $db->rollback();
        if ((int) $same['user_id'] !== $userId) {
            $reject('duplicate', $host);
        }
        // 내 받기를 다시 보냈다 — 새로 만들지 않고 그대로 알려 준다(감사 없음: 바뀐 것이 없다)
        Http::json(['ok' => true, 'already' => true, 'subscription' => [
            'id'          => (int) $same['id'],
            'host'        => (string) $same['host'],
            'device_hint' => (string) $same['device_hint'],
            'created_at'  => (string) $same['created_at'],
            'last_ok_at'  => $same['last_ok_at'] === null ? null : (string) $same['last_ok_at'],
        ]]);
        exit;
    }

    $alive = (int) $db->run(
        'SELECT count(*) AS n FROM alert_push_subscriptions WHERE user_id = :uid AND revoked_at IS NULL',
        [':uid' => $userId]
    )->fetch()['n'];
    if ($alive >= $maxDevices) {
        $db->rollback();
        $reject('too_many', $host);
    }

    $row = $db->run(
        'INSERT INTO alert_push_subscriptions (user_id, endpoint, host, p256dh, auth, device_hint)
         VALUES (:uid, :endpoint, :host, decode(:p256dh, \'hex\'), decode(:auth, \'hex\'), :hint)
         RETURNING id, created_at',
        [
            ':uid'      => $userId,
            ':endpoint' => $endpoint,
            ':host'     => $host,
            ':p256dh'   => bin2hex($raw['p256dh']),
            ':auth'     => bin2hex($raw['auth']),
            ':hint'     => $deviceHint,
        ]
    )->fetch();
    if (!is_array($row)) {
        throw new RuntimeException('받기를 저장하지 못했습니다');
    }

    Audit::allow('alert.push_subscribe', $auditBase + [
        'target_id'   => (int) $row['id'],
        'target_name' => $host,
        'detail'      => ['host' => $host, 'device_hint' => $deviceHint],
    ]);
    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollback();
    }
    throw $e;
}

Http::json(['ok' => true, 'subscription' => [
    'id'          => (int) $row['id'],
    'host'        => $host,
    'device_hint' => $deviceHint,
    'created_at'  => (string) $row['created_at'],
    'last_ok_at'  => null,
]], 201);
