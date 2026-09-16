<?php
declare(strict_types=1);

/**
 * src/handlers/api_tokens_issue.php — POST /api/users/{uid}/api-tokens
 *                                      (정책: user.manage → Admin 만 + 재인증 관문 [3-2])
 *
 * 요구사항: PRD F-59 ② · 8장(`/api/users/{uid}/api-tokens` POST 행) · S-07(관리자 행동 사유) ·
 *           S-09-11(비밀 미기록) · R-07(허용 키), ARCHITECTURE 4.6 · 6.2-5 · 7.4 `api_token.issue`
 *           4행 · 7.5 `token_id`·`token_name`, API.md 13장, SECURITY R-91
 *
 * 하는 일: 그 계정의 **개인 프로그램 토큰**을 하나 만든다. 토큰 원문(48자)은 이 응답에
 * **한 번만** 나가고 서버에는 sha256 만 남는다 — 다시 보여 줄 방법이 없고, 잃으면 회수하고
 * 새로 발급하는 것이 답이다(`sessions`·`share_links`·임시 비밀번호와 같은 방식).
 *
 * 받는 값 (JSON, 허용 키 **둘**, 둘 다 필수 — R-07 대량 할당 차단)
 *   name         라벨 「사무실 PC」 — 폴더 이름과 같은 규칙(trim 뒤 1~50자, 제어문자·`/`·`\` 금지)
 *   reason_text  발급 사유 — 필수 500자 (PRD S-07)
 *
 * 거부
 *   404  없는 계정 (계정 열거 재료를 주지 않는다 — 다른 관리 창구와 같은 문구)
 *   400  bad_request     허용 키 밖 · 이름 규칙 · 사유 없음
 *   400  target_inactive 대상이 비활성·만료 — 쓸 수 없는 계정에 자격을 만들지 않는다
 *   400  limit_exceeded  살아 있는 토큰이 이미 5개 (ARCH 4.6)
 *
 * ★ 자기 자신도 대상이 된다 — 관리자 자신의 PC 프로그램이 첫 용례다(ARCH 4.6).
 *   그래서 `Authz::decideUserChange` 의 `self_change` 를 **부르지 않는다**.
 * ★ 토큰 원문·hint 는 감사 로그·서버 로그 어디에도 넣지 않는다. 감사 detail 에 남는 것은
 *   `token_id`·`token_name`·`reason_text` 뿐이다 (ARCH 4.6 「감사」, 7.5).
 * ★ 역할 판단을 여기서 하지 않는다 — 라우트 정책 `user.manage` 가 Admin 을 본다 (PRD S-01).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자 (Admin)
 * @var list<string>        $params $params[0] = users.id (정수 문자열)
 */

/** 살아 있는(회수되지 않은) 토큰의 계정당 상한 (PRD F-59 ②, ARCH 4.6) */
const TBOX_API_TOKEN_MAX_LIVE = 5;

/** 발급하는 토큰의 길이 — scripts/api_token_rotate.sh 와 같은 48자 */
const TBOX_API_TOKEN_CHARS = 48;

$allowedFields = ['name', 'reason_text'];

$uidRaw = $params[0] ?? '';
if (!is_string($uidRaw) || preg_match('/^[0-9]{1,9}$/', $uidRaw) !== 1) {
    Http::error(404, Http::MSG_NOT_FOUND);
}
$uid = (int) $uidRaw;

$body = Http::jsonBody();
$db   = Db::instance();

// ─── [0] 대상 계정 (없으면 404 동일 문구) ────────────────────────
$sqlFind = <<<'SQL'
    SELECT id, username, is_active,
           (expires_at IS NOT NULL AND expires_at < now()) AS is_expired
      FROM users
     WHERE id = :id
    SQL;

$target = $db->run($sqlFind, [':id' => $uid])->fetch();
if (!is_array($target)) {
    Http::error(404, Http::MSG_NOT_FOUND);
}

$auditTarget = [
    'target_type' => 'user',
    'target_id'   => $uid,
    'target_name' => (string) $target['username'],
];

/** 거부 기록 후 400 으로 끝낸다 (문구는 언제나 통일 — 대상 정보를 담지 않는다) */
$reject = static function (string $reason) use ($auditTarget): void {
    Audit::deny('api_token.issue', $reason, $auditTarget);
    Http::error(400, Http::MSG_BAD_REQUEST);
};

// ─── [1] 허용 키 검사 (R-07 대량 할당 차단) ──────────────────────
if ($body === [] || array_diff(array_keys($body), $allowedFields) !== []) {
    $reject('bad_request');
}

// ─── [2] 이름 — 폴더 이름과 같은 규칙 (trim 1~50자, 제어문자·`/`·`\` 금지) ──
//        `\p{C}` 는 제어문자·서식문자·미배정 문자를 한꺼번에 막는다.
$rawName = $body['name'] ?? null;
if (!is_string($rawName) || preg_match('//u', $rawName) !== 1) {
    $reject('bad_request');
}
$name = trim((string) $rawName);
if ($name === ''
    || iconv_strlen($name, 'UTF-8') > 50
    || preg_match('/\p{C}/u', $name) === 1
    || strpos($name, '/') !== false
    || strpos($name, '\\') !== false
) {
    $reject('bad_request');
}

// ─── [3] 사유 — 필수 500자 (PRD S-07, SECURITY R-20) ─────────────
$rawReason = $body['reason_text'] ?? null;
if (!is_string($rawReason) || preg_match('//u', $rawReason) !== 1) {
    $reject('bad_request');
}
$reason = trim((string) $rawReason);
if ($reason === '' || iconv_strlen($reason, 'UTF-8') > 500) {
    $reject('bad_request');
}

// ─── [4] 대상이 쓸 수 있는 계정인가 ─────────────────────────────
//        비활성·만료된 계정에 자격을 만들지 않는다. (만들어 두어도 `requireApiToken` 이
//        매 요청 4조건으로 막지만, 만들지 않는 것이 먼저다 — ARCH 4.6.)
if ($target['is_active'] !== true || $target['is_expired'] === true) {
    $reject('target_inactive');
}

// ─── [5] 살아 있는 토큰 상한 ────────────────────────────────────
$sqlLive = <<<'SQL'
    SELECT count(*) AS n
      FROM api_tokens
     WHERE user_id = :uid AND revoked_at IS NULL
    SQL;
$live = $db->run($sqlLive, [':uid' => $uid])->fetch();
if (!is_array($live) || (int) $live['n'] >= TBOX_API_TOKEN_MAX_LIVE) {
    $reject('limit_exceeded');
}

// ─── [6] 토큰 만들기 — 예측 불가능한 난수만 (rand·uniqid 금지) ───
//        `scripts/api_token_rotate.sh` 와 같은 방식이다: 64바이트 난수를 base64 로 바꾼 뒤
//        헤더에 그대로 실을 수 있는 글자(`A-Za-z0-9`)만 남기고 앞 48자를 쓴다.
//        64바이트(≈86자)에서 48자를 채우지 못하는 일은 사실상 없지만, 못 채우면 **닫힌
//        실패**로 끝낸다(짧은 토큰을 발급하지 않는다). 500 은 일반 문구다(PRD S-09-7).
$plain = substr(
    (string) preg_replace('/[^A-Za-z0-9]/', '', base64_encode(random_bytes(64))),
    0,
    TBOX_API_TOKEN_CHARS
);
if (strlen($plain) !== TBOX_API_TOKEN_CHARS) {
    // 감사 행을 남기지 않는다: `api_token.issue|error|…` 는 ARCH 7.4 표에 없는 조합이라
    // 기록 자체가 예외가 된다. 원인은 서버 로그의 request_id 로만 본다 (PRD S-09-7).
    throw new RuntimeException('개인 토큰 48자를 만들지 못했다');
}

$hash = hash('sha256', $plain);
$hint = substr($plain, 0, 6);

// ─── [7] 저장 + 기록 — 한 트랜잭션 ──────────────────────────────
$sqlInsert = <<<'SQL'
    INSERT INTO api_tokens (user_id, name, token_hash, hint, created_by, reason_text)
    VALUES (:uid, :name, :hash, :hint, :by, :reason)
    RETURNING id
    SQL;

$db->begin();
try {
    $row = $db->run($sqlInsert, [
        ':uid'    => $uid,
        ':name'   => $name,
        ':hash'   => $hash,
        ':hint'   => $hint,
        ':by'     => (int) $user['id'],
        ':reason' => $reason,
    ])->fetch();
    if (!is_array($row)) {
        throw new RuntimeException('api_tokens INSERT 가 id 를 돌려주지 않았다');
    }
    $tokenId = (int) $row['id'];

    // detail 에 토큰 원문도 hint 도 해시도 넣지 않는다 (PRD S-09-11, ARCH 4.6).
    Audit::allow('api_token.issue', $auditTarget + [
        'detail' => [
            'token_id'    => $tokenId,
            'token_name'  => $name,
            'reason_text' => $reason,
        ],
    ]);
    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    throw $e;
}

// ★ `token` 이 나가는 곳은 여기 **한 줄**뿐이다 (API.md 13장).
Http::json([
    'id'    => $tokenId,
    'name'  => $name,
    'hint'  => $hint,
    'token' => $plain,
], 201);
