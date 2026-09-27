<?php
declare(strict_types=1);

/**
 * src/handlers/me_passkeys_register.php — POST /api/me/passkeys (정책: authenticated + CSRF) · T:F-72 지시서 1.4 「등록」 1~7
 *
 * 본문(허용 키만 — 화면 `passkey.js` 가 만든다):
 *   { name, id, rawId, type:"public-key", clientDataJSON, attestationObject, transports?, authenticatorAttachment? }
 * 순서
 *   1) 모양 — 허용 키 · base64url 엄격 · clientDataJSON ≤ 2KB · attestationObject ≤ 4KB(해독 상한 — 실제로는 3KB, WebPush::b64uDecode
 *      의 4,096글자 상한) · rawId 16~1023 · id = rawId · 이름 1~40자 · transports 는 허용 낱말만(모르는 낱말이면 거부) ·
 *      authenticatorAttachment 가 오면 `platform`(옵션과 같은 것만 — 휴대폰인지는 보지 않는다, PK-D1)
 *   2) clientDataJSON — type `webauthn.create` · 챌린지 **소비**(register · 이 세션 · 이 사람 · 한 번) · origin 정확 일치 · crossOrigin
 *   3~5) attestationObject — `Passkey::verifyAttestation`(fmt none · attStmt 빈 맵 · 남는 바이트 0 · rpIdHash · UP·UV·AT · COSE → SPKI)
 *        · 열쇠 id(authData 안) = rawId
 *   7) 한 트랜잭션 — 사용자 행 잠금(FOR UPDATE) → 살아 있는 열쇠 < 5 → INSERT(열쇠 id 겹치면 `passkey_duplicate`) → 감사 allow
 * 감사 detail 은 등록부 키만: credential_no(행 번호) · credential_label(이름) · device_hint · backed_up — 열쇠 id 원문·공개 키 0.
 * 경보 AL-23 은 이 allow 한 행을 보고 즉시 알린다(scripts/alerts.php).
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params
 */

require_once __DIR__ . '/../Passkey.php';

$allowed = ['name', 'id', 'rawId', 'type', 'clientDataJSON', 'attestationObject', 'transports', 'authenticatorAttachment'];

$body   = Http::jsonBody(16384, 3);
$db     = Db::instance();
$userId = (int) $user['id'];

$auditTarget = [
    'target_type' => 'user',
    'target_id'   => $userId,
    'target_name' => (string) $user['username'],
];
$reject = static function (string $reason, string $message) use ($auditTarget): void {
    Audit::deny('auth.passkey_register', $reason, $auditTarget);
    Http::error(400, $message);
};

if (!Passkey::available()) {
    Http::error(503, Http::MSG_PASSKEY_UNAVAILABLE);
}

// ─── 1) 모양 ────────────────────────────────────────────────────
$name       = Passkey::cleanName($body['name'] ?? null);
$transports = $body['transports'] ?? [];
$trOk = is_array($transports) && array_is_list($transports) && count($transports) <= count(Passkey::TRANSPORTS);
if ($trOk) {
    foreach ($transports as $t) {
        if (!is_string($t) || !in_array($t, Passkey::TRANSPORTS, true)) {
            $trOk = false;   // 허용 낱말만(모르는 낱말이면 거부 — 지시서 1.4 등록 1)
        }
    }
    $trOk = $trOk && count(array_unique($transports)) === count($transports);
}
if ($body === [] || array_diff(array_keys($body), $allowed) !== [] || $name === null || !$trOk
    || ($body['type'] ?? null) !== 'public-key'
    || !is_string($body['id'] ?? null) || !is_string($body['rawId'] ?? null) || $body['id'] !== $body['rawId']
    || (array_key_exists('authenticatorAttachment', $body) && $body['authenticatorAttachment'] !== null
        && $body['authenticatorAttachment'] !== 'platform')
) {
    $reject('bad_request', Http::MSG_BAD_REQUEST);
}
try {
    $rawId      = Passkey::field($body, 'rawId', Passkey::MAX_CREDENTIAL_ID, Passkey::MIN_CREDENTIAL_ID);
    $clientData = Passkey::field($body, 'clientDataJSON', Passkey::MAX_CLIENT_DATA);
    $attObj     = Passkey::field($body, 'attestationObject', Passkey::MAX_CBOR);
} catch (PasskeyError $e) {
    $reject('bad_request', Http::MSG_BAD_REQUEST);
}
unset($body);

// ─── 2) clientDataJSON — type → 챌린지 소비(한 번) → origin ──────────
try {
    $cd = Passkey::parseClientData($clientData, 'webauthn.create');
} catch (PasskeyError $e) {
    $reject('passkey_invalid', Http::MSG_PASSKEY_REGISTER_FAILED);
}
if (!Passkey::consumeSession($db, $cd['challenge'], 'register', $userId, (string) $user['session_id'])) {
    $reject('passkey_challenge', Http::MSG_PASSKEY_REGISTER_FAILED);
}
if (!Passkey::originOk($cd['origin'])) {
    $reject('passkey_invalid', Http::MSG_PASSKEY_REGISTER_FAILED);
}

// ─── 3~5) attestationObject ─────────────────────────────────────
try {
    $att = Passkey::verifyAttestation($attObj);
} catch (PasskeyError $e) {
    $reject('passkey_invalid', Http::MSG_PASSKEY_REGISTER_FAILED);
}
if (!hash_equals($att['credential_id'], $rawId)) {
    $reject('passkey_invalid', Http::MSG_PASSKEY_REGISTER_FAILED);
}
unset($clientData, $attObj);

$hint   = WebPush::deviceHint(Http::userAgent());
$handle = Passkey::userHandleFor($db, $userId);

// ─── 7) 한 트랜잭션 — 사용자 행 잠금 → 5개 → INSERT(겹치면 없음) → 감사 ─────
$sqlLock = 'SELECT id FROM users WHERE id = :id FOR UPDATE';
$sqlLive = <<<'SQL'
    SELECT count(*) AS n
      FROM passkeys
     WHERE user_id = :uid AND revoked_at IS NULL
       AND COALESCE(last_used_at, created_at) >= now() - make_interval(days => CAST(:idle AS integer))
    SQL;
$sqlInsert = <<<'SQL'
    INSERT INTO passkeys (user_id, credential_id, public_key, alg, sign_count, user_handle,
                          backup_eligible, backed_up, transports, aaguid, name, device_hint)
    VALUES (:uid, decode(:cid, 'hex'), decode(:pk, 'hex'), :alg, :sc, decode(:uh, 'hex'),
            CAST(:be AS boolean), CAST(:bs AS boolean), CAST(:tr AS text[]), CAST(:aaguid AS uuid), :name, :hint)
    ON CONFLICT (credential_id) DO NOTHING
    RETURNING id
    SQL;

$outcome = null;
$db->begin();
try {
    $db->run($sqlLock, [':id' => $userId]);
    $live = $db->run($sqlLive, [':uid' => $userId, ':idle' => Passkey::IDLE_DAYS])->fetch();
    if (!is_array($live) || (int) $live['n'] >= Passkey::MAX_PER_USER) {
        $outcome = 'limit_exceeded';
    } else {
        $row = $db->run($sqlInsert, [
            ':uid'    => $userId,
            ':cid'    => bin2hex($att['credential_id']),
            ':pk'     => bin2hex($att['public_key']),
            ':alg'    => Passkey::ALG_ES256,
            ':sc'     => $att['sign_count'],
            ':uh'     => bin2hex($handle),
            ':be'     => $att['backup_eligible'] ? 'true' : 'false',
            ':bs'     => $att['backed_up'] ? 'true' : 'false',
            ':tr'     => '{' . implode(',', $transports) . '}',
            ':aaguid' => $att['aaguid'],
            ':name'   => $name,
            ':hint'   => $hint,
        ])->fetch();
        if (!is_array($row)) {
            $outcome = 'passkey_duplicate';
        } else {
            $credentialNo = (int) $row['id'];
            Audit::allow('auth.passkey_register', $auditTarget + [
                'detail' => [
                    'credential_no'    => $credentialNo,
                    'credential_label' => $name,
                    'device_hint'      => $hint,
                    'backed_up'        => $att['backed_up'],
                ],
            ]);
            $outcome = 'ok';
        }
    }
    if ($outcome === 'ok') {
        $db->commit();
    } else {
        $db->rollback();
    }
} catch (Throwable $e) {
    $db->rollback();
    throw $e;
}

if ($outcome === 'limit_exceeded') {
    $reject('limit_exceeded', Http::MSG_PASSKEY_LIMIT);
}
if ($outcome === 'passkey_duplicate') {
    $reject('passkey_duplicate', Http::MSG_PASSKEY_DUPLICATE);
}

Http::json([
    'ok'      => true,
    'passkey' => [
        'id'          => $credentialNo,
        'name'        => $name,
        'device_hint' => $hint,
        'synced'      => $att['backed_up'],
    ],
], 201);
