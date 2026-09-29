<?php
declare(strict_types=1);

/**
 * src/handlers/me_session_link.php — POST /api/me/session-link (정책: session.open · 토큰 전용 · 개인 토큰만)
 *
 * 요구사항: 사이트 Q:F-9 · t-box PRD F-75 ③ · ARCHITECTURE 4.9 · API.md 20장 · SECURITY R-113 ·
 *           지시서 docs/qf9-pc-login-directive.md 조각 S 설계 3
 *
 * 하는 일: 「이 PC 로그인 전용」 토큰(범위 `session.open`)을 쥔 프로그램(AI 비서 tbox-buddy)에게 **30초 · 1회용** 로그인
 * 링크 하나를 준다. 비서는 그 주소를 그대로 브라우저로 열고 버린다 — 브라우저가 `GET /session/open?code=…` 를 열면
 * 세션이 생긴다(session_open_page.php · Auth::openSessionFromLink).
 *
 * 여기 닿기 전에 라우터가 끝낸 것([2-T] · [2-S] · [4]):
 *   · `X-API-Token` 헤더 필수(없으면 401 — 세션 쿠키로 되돌아가지 않는다) · 개인 토큰만(공용 403) · 틀림·회수 401 · 만료 401 ·
 *     계정 비활성·만료·절차 중 403(Auth::requireApiToken)
 *   · 범위가 `session.open` 이 아니면 403 token_scope(Authz::tokenScopeAllows)
 *   · 계정이 지금도 자격이 있나 — 2단계 켜짐 · Guest 아님(Authz::authorize('session.open') = canOpenSessionByToken)
 *
 * 순서
 *   ⓪ 한 토큰 1분 5회 — 받은 링크 + IP 불일치 거부를 함께 센다(검토 RV-QF9-4 · IP 확인보다 앞) → 넘으면 429
 *   ① **지금 요청의 IP**(Http::clientIp — REMOTE_ADDR, nginx 가 믿는 프록시에 대해서만 복원)가 그 토큰의 `bound_ip` 인가 —
 *      비교는 DB 가 한다(INET = INET, IPv6 표기 차이 없음). 아니면 **403** `{"error":…, "code":"ip_mismatch"}` +
 *      `session.link deny ip_mismatch`(요청 IP 는 감사 행의 ip 칸 · token_id 는 autoDetail). 비서는 이 답을 보고 「이 PC 다시 등록」을 안내한다.
 *      10분 안 3회면 경보 AL-24(scripts/alerts.php).
 *   ② 한 토큰 1분 5회 — 넘으면 **429** + `session.link deny rate_limited`(세는 재료는 session_links 의 최근 60초 행 — DB 시계)
 *   ③ 코드 = random_bytes(32) 의 base64url 43자 → session_links 에 **sha256 만**(code_hash) · token_id · user_id · ip · 30초 만료 +
 *      `session.link allow` — 한 트랜잭션. 하루 지난 링크 행은 같은 자리에서 지운다(일회용 값 — 정리).
 *   ④ `201 {"url": APP_URL + "/tbox/session/open?code=<코드>", "expires_in": 30}` — 코드 원문이 나가는 곳은 이 응답 **한 줄**뿐이다.
 *
 * ★ 코드 원문은 저장·로그·감사 어디에도 남지 않는다(DB 에는 해시 · 감사 detail 에는 token_id 뿐).
 * ★ 주소의 호스트는 `.env APP_URL` 한 값에서만 만든다 — 요청 머리(Host·X-Forwarded-*)로 만들지 않는다(열린 리다이렉트·피싱 링크 0).
 * ★ 역할 판단 0 · 요청 본문을 읽지 않는다(받을 값이 없다).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 토큰 주인 (Auth::requireApiToken)
 * @var list<string>        $params 경로 변수 없음
 */

$db      = Db::instance();
$userId  = (int) $user['id'];
$tokenId = defined('TBOX_API_TOKEN_ID') ? (int) constant('TBOX_API_TOKEN_ID') : 0;

$auditTarget = [
    'target_type' => 'user',
    'target_id'   => $userId,
    'target_name' => (string) $user['username'],
];

/** 이 창구의 403 문장 — 비서가 사람에게 그대로 보여 줄 수 있는 말(무엇을 하면 되나) */
const TBOX_SESSION_LINK_MSG_IP = '이 토큰이 묶인 IP 가 아닌 곳에서 요청했습니다 — 이 PC 를 다시 등록하세요(내 계정 → 프로그램 토큰)';
const TBOX_SESSION_LINK_MSG_RATE = '로그인 링크를 너무 자주 요청했습니다 — 잠시 뒤 다시 시도하세요';

// ─── [1] 한 토큰 1분 5회 (DB 시계) — **IP 확인보다 앞** (검토 RV-QF9-4) ─────────
//        세는 것: 이 토큰으로 받은 링크(session_links 최근 60초) + 이 토큰의 IP 불일치 거부(감사 session.link deny
//        ip_mismatch 최근 60초). 그래서 새어 나간 토큰을 다른 IP 에서 두드려도 1분에 다섯 번째 뒤로는 429 다.
//        429 자신(rate_limited 행)은 세지 않는다 — 두드림이 멈추면 60초 뒤 풀린다.
$sqlRate = <<<'SQL'
    SELECT (SELECT count(*) FROM session_links
             WHERE token_id = :id AND created_at > now() - interval '60 seconds')
         + (SELECT count(*) FROM audit_logs
             WHERE action = 'session.link' AND result = 'deny' AND reason = 'ip_mismatch'
               AND detail->>'token_id' = CAST(:id_text AS text)
               AND happened_at > now() - interval '60 seconds') AS n
    SQL;
$rate = $db->run($sqlRate, [':id' => $tokenId, ':id_text' => (string) $tokenId])->fetch();
if (!is_array($rate) || (int) $rate['n'] >= Auth::SESSION_LINK_PER_MINUTE) {
    Audit::deny('session.link', 'rate_limited', $auditTarget);
    Http::json(['error' => TBOX_SESSION_LINK_MSG_RATE, 'code' => 'rate_limited', 'retry_after' => 60], 429);
    exit;
}

// ─── [2] 지금 IP = 묶인 IP (비교는 DB — 판정 값은 서버가 본 IP 뿐) ─────
//        틀린 IP 의 시도는 링크를 만들지도 소비하지도 않는다(검토 RV-QF9-8) — 거부 행 하나와 위 [1] 의 셈에만 든다.
$sqlBound = <<<'SQL'
    SELECT (bound_ip = CAST(:cur_ip AS inet)) AS ip_ok
      FROM api_tokens
     WHERE id = :id
       AND user_id = :uid
       AND scope = 'session.open'
       AND revoked_at IS NULL
    SQL;
$bound = $tokenId > 0
    ? $db->run($sqlBound, [':cur_ip' => Http::clientIp(), ':id' => $tokenId, ':uid' => $userId])->fetch()
    : false;
// 행이 없는 갈래는 라우터 관문([2-T] 해시 조회 · [2-S] 범위)과 CHECK(session.open ⇔ bound_ip)가 있어 닿을 수 없다 —
// 닿더라도 **닫힌 쪽**(IP 불일치와 같은 답)으로 끝낸다.
if (!is_array($bound) || $bound['ip_ok'] !== true) {
    Audit::deny('session.link', 'ip_mismatch', $auditTarget);
    Http::json(['error' => TBOX_SESSION_LINK_MSG_IP, 'code' => 'ip_mismatch'], 403);
    exit;
}

// ─── [3] 주소의 앞부분 — .env APP_URL 한 값(scheme://host[:port], 경로·쿼리 없음)만 ───
$appUrl = rtrim(Env::getOrEmpty('APP_URL'), '/');
if (preg_match('#^https?://[A-Za-z0-9.-]{1,253}(:[0-9]{1,5})?$#', $appUrl) !== 1) {
    // 서버 설정 문제다(요청자가 고칠 수 없다). 감사 문장표에 없는 조합이라 기록하지 않고 서버 로그로만 본다(request_id).
    error_log('[tbox] request_id=' . TBOX_REQUEST_ID . ' session-link: APP_URL 이 scheme://host[:port] 모양이 아니다');
    Http::error(503, '서버 설정 문제로 로그인 링크를 만들 수 없습니다');
}

// ─── [4] 코드 — 예측 불가능한 32바이트(rand·uniqid 금지) → base64url 43자 ─────
$code = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
if (preg_match(Auth::SESSION_LINK_CODE_RE, $code) !== 1) {
    throw new RuntimeException('로그인 링크 코드 43자를 만들지 못했다');
}

$sqlInsert = <<<'SQL'
    INSERT INTO session_links (code_hash, token_id, user_id, ip, expires_at)
    VALUES (:hash, :token_id, :uid, CAST(:ip AS inet), now() + make_interval(secs => CAST(:ttl AS integer)))
    SQL;
$sqlPurge = <<<'SQL'
    DELETE FROM session_links WHERE expires_at < now() - interval '1 day'
    SQL;

$db->begin();
try {
    $db->run($sqlPurge);
    $db->run($sqlInsert, [
        ':hash'     => hash('sha256', $code),
        ':token_id' => $tokenId,
        ':uid'      => $userId,
        ':ip'       => Http::clientIp(),
        ':ttl'      => Auth::SESSION_LINK_TTL_SECONDS,
    ]);
    // detail 에 코드도 해시도 넣지 않는다 — token_id·auth=api_token 은 autoDetail 이 붙인다.
    Audit::allow('session.link', $auditTarget);
    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    throw $e;
}

// ★ 코드 원문이 나가는 곳은 여기 **한 줄**뿐이다 (API.md 20장).
Http::json([
    'url'        => $appUrl . Http::url('/session/open') . '?code=' . $code,
    'expires_in' => Auth::SESSION_LINK_TTL_SECONDS,
], 201);
