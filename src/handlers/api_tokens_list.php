<?php
declare(strict_types=1);

/**
 * src/handlers/api_tokens_list.php — GET /api/users/{uid}/api-tokens
 *                                     (정책: user.manage → Admin 만 + 재인증 관문 [3-2])
 *
 * 요구사항: PRD F-59 ② · 8장(`/api/users/{uid}/api-tokens` GET 행) · S-09-11(비밀 미기록),
 *           ARCHITECTURE 4.6 「발급·회수는 관리자의 세션 행동이다」 · 6.2-5, API.md 13장,
 *           SECURITY R-91
 *
 * 하는 일: 그 계정의 개인 프로그램 토큰을 **이력까지** 보여 준다. 회수된 행도 남는다 —
 * 「그 토큰이 언제부터 언제까지 살아 있었나」 가 조사에 필요하기 때문이다(ARCH 6.2-5).
 *
 * F-66 (2026-09-22, ARCH 4.7 「관리자 창구」): 행에 `scope`·`expires_at` 이 늘었다. 이 창구는
 * **목록**이고, 발급은 본인 창구(`POST /api/me/api-tokens`)로 옮겼다 — 관리자가 남을 대신해
 * 자격을 만드는 길은 없다(R-91 의 핵심 완화). 회수는 그대로 관리자도 할 수 있다.
 *
 * ★ 응답에 **토큰 값도 해시도 없다.** 나가는 것은 번호·라벨·앞 6자(hint)·범위·시각들뿐이다.
 *   원문은 발급 201 응답 한 곳에서만 나가고 서버에는 sha256 만 있다(되돌릴 방법이 없다).
 * ★ 감사 로그를 남기지 않는다 — 조회다(파일 목록·`GET /api/me` 와 같은 선, PRD S-07).
 * ★ 역할 판단을 여기서 하지 않는다. 라우트 정책 `user.manage` 가 Admin 을 보고,
 *   재인증 창은 관문 [3-2] 가 본다 (PRD S-01, ARCH 4.1).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자 (Admin)
 * @var list<string>        $params $params[0] = users.id (정수 문자열)
 */

$uidRaw = $params[0] ?? '';
if (!is_string($uidRaw) || preg_match('/^[0-9]{1,9}$/', $uidRaw) !== 1) {
    Http::error(404, Http::MSG_NOT_FOUND);
}
$uid = (int) $uidRaw;

$db = Db::instance();

// ─── [0] 대상 계정 (없으면 404 동일 문구 — 계정 열거 재료를 주지 않는다) ───
$sqlUser = <<<'SQL'
    SELECT id FROM users WHERE id = :id
    SQL;
if (!is_array($db->run($sqlUser, [':id' => $uid])->fetch())) {
    Http::error(404, Http::MSG_NOT_FOUND);
}

// ─── [1] 목록 — id 오름차순(발급 순서) ───────────────────────────
//        token_hash 는 **고르지도 않는다**: 뽑지 않은 값은 실수로 응답에 실릴 수 없다.
$sqlList = <<<'SQL'
    SELECT id, name, hint, scope, created_at, last_used_at, revoked_at, expires_at,
           host(bound_ip) AS bound_ip
      FROM api_tokens
     WHERE user_id = :uid
     ORDER BY id
    SQL;

$rows   = $db->run($sqlList, [':uid' => $uid])->fetchAll();
$tokens = [];
foreach ($rows as $row) {
    $tokens[] = [
        'id'           => (int) $row['id'],
        'name'         => (string) $row['name'],
        // CHAR(6) 이라 DB 가 공백을 채워 줄 수 있다 — 화면에 그대로 보이므로 잘라 낸다.
        'hint'         => rtrim((string) $row['hint']),
        // F-66 (ARCH 4.7 「관리자 창구」) — 범위·만료. 관리자는 남의 토큰이 무엇을 할 수
        // 있고 언제까지 사는지를 봐야 회수를 판단할 수 있다. 발급은 본인만 한다.
        'scope'        => (string) $row['scope'],
        // Q:F-10 — 같은 범위를 원자 배열로(화면이 문자열을 쪼개지 않게 · ARCH 4.10). 판정에는 쓰지 않는다.
        'scopes'       => Authz::scopeAtoms((string) $row['scope']),
        'created_at'   => (string) $row['created_at'],
        'expires_at'   => (string) $row['expires_at'],
        'last_used_at' => $row['last_used_at'] === null ? null : (string) $row['last_used_at'],
        'revoked_at'   => $row['revoked_at'] === null ? null : (string) $row['revoked_at'],
        // Q:F-9 (ARCH 4.9) — 「이 PC 로그인 전용」 토큰이 묶인 공인 IP(발급 때 서버가 본 값). 다른 범위는 null.
        'bound_ip'     => $row['bound_ip'] === null ? null : (string) $row['bound_ip'],
    ];
}

Http::json(['tokens' => $tokens]);
