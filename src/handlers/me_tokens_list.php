<?php
declare(strict_types=1);

/**
 * src/handlers/me_tokens_list.php — GET /api/me/api-tokens (정책: authenticated)
 *
 * 요구사항: PRD F-66 ① ④ · 8장(`/api/me/api-tokens` GET 행) · T-78 (a) · S-09-11,
 *           ARCHITECTURE 4.7 「본인 창구 셋」 · 6.2-5, API.md 13장, SECURITY R-97 · R-91
 *
 * 하는 일: **내** 개인 프로그램 토큰을 이력까지 보여 준다. 회수된 행도 남는다 —
 * 「그 토큰이 언제부터 언제까지 살아 있었나」 가 조사에 필요하기 때문이다(ARCH 6.2-5).
 *
 * ★ 대상은 언제나 **세션의 본인**이다(`WHERE user_id = :uid`). 요청에 계정을 받지 않으므로
 *   남의 토큰 목록을 부를 길이 없다 — 관리자 목록은 별도 창구
 *   (`GET /api/users/{uid}/api-tokens`, 정책 `user.manage`)다.
 * ★ 응답에 **토큰 값도 해시도 없다.** 나가는 것은 번호·라벨·앞 6자(hint)·범위·시각들뿐이다.
 *   원문은 발급 201 응답 한 곳에서만 나가고 서버에는 sha256 만 있다(되돌릴 방법이 없다).
 *   `token_hash` 는 **고르지도 않는다**: 뽑지 않은 값은 실수로 응답에 실릴 수 없다.
 * ★ 감사 로그를 남기지 않는다 — 조회다(파일 목록·`GET /api/me` 와 같은 선, PRD S-07).
 * ★ 역할 판단을 여기서 하지 않는다. 로그인한 사람이면 누구나 자기 것을 보고,
 *   그 판단은 라우트 정책 `authenticated` 가 한다 (PRD S-01).
 *
 * `expires_soon` — 만료가 14일 안인가. **DB 시계**로 잰다(`now()`) — PHP 시계와 DB 시계를
 * 섞지 않고, 화면은 이 bool 하나만 보고 안내 줄을 켠다(날짜를 화면이 계산하지 않는다).
 * 이미 만료된 토큰도 `true` 다(14일 안에 든다) — 화면의 안내는 「바꿔 심으세요」 하나다.
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자 (대상은 언제나 본인)
 * @var list<string>        $params 경로 변수 없음
 */

$db = Db::instance();

// ─── 목록 — id 오름차순(발급 순서), **내 것만** ──────────────────
$sqlList = <<<'SQL'
    SELECT id, name, hint, reason_text, scope,
           created_at, last_used_at, revoked_at, expires_at,
           host(bound_ip) AS bound_ip,
           (expires_at < now() + interval '14 days') AS expires_soon
      FROM api_tokens
     WHERE user_id = :uid
     ORDER BY id
    SQL;

$rows   = $db->run($sqlList, [':uid' => (int) $user['id']])->fetchAll();
$tokens = [];
foreach ($rows as $row) {
    $tokens[] = [
        'id'           => (int) $row['id'],
        'name'         => (string) $row['name'],
        // CHAR(6) 이라 DB 가 공백을 채워 줄 수 있다 — 화면에 그대로 보이므로 잘라 낸다.
        'hint'         => rtrim((string) $row['hint']),
        // F-66 — 화면의 「용도」 열. DB 는 F-59 와 같은 reason_text 열이다.
        'purpose'      => $row['reason_text'] === null ? null : (string) $row['reason_text'],
        'scope'        => (string) $row['scope'],
        'created_at'   => (string) $row['created_at'],
        'expires_at'   => (string) $row['expires_at'],
        // 회수된 토큰에도 그대로 둔다 — 화면의 「상태」 열이 회수를 먼저 보인다.
        'expires_soon' => $row['expires_soon'] === true,
        'last_used_at' => $row['last_used_at'] === null ? null : (string) $row['last_used_at'],
        'revoked_at'   => $row['revoked_at'] === null ? null : (string) $row['revoked_at'],
        // Q:F-9 (ARCH 4.9) — 「이 PC 로그인 전용」 토큰이 묶인 공인 IP(발급 때 서버가 본 값). 다른 범위는 null.
        'bound_ip'     => $row['bound_ip'] === null ? null : (string) $row['bound_ip'],
    ];
}

Http::json(['tokens' => $tokens]);
