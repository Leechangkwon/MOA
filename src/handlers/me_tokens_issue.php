<?php
declare(strict_types=1);

/**
 * src/handlers/me_tokens_issue.php — POST /api/me/api-tokens (정책: authenticated)
 *
 * 요구사항: PRD F-66 ① ② ③ ④ ⑤ · 8장(`/api/me/api-tokens` POST 행) · T-78 (a)(b) ·
 *           7.7(비밀 미기록) · S-01 · S-07 · S-09-11 · R-07(허용 키),
 *           ARCHITECTURE 4.7 「본인 창구 셋」 · 6.2-5 · 7.4 `api_token.issue` · 7.5 `token_scope`,
 *           5.30(인증 실패 문구 예외 목록), API.md 13장, SECURITY R-97 · R-91 · R-48 · R-45
 *
 * 하는 일: **본인의** 개인 프로그램 토큰을 하나 만든다. F-59 는 「관리자가 남의 것을」
 * 이었고(R-91), F-66 은 「본인이 · 비밀번호+2단계 코드를 치고 · 필요한 범위만 · 180일」 이다.
 * 관리자가 남을 대신해 자격을 만드는 길은 없어졌다(`api_tokens_issue.php` 삭제).
 *
 * 토큰 원문(48자)은 이 응답에 **한 번만** 나가고 서버에는 sha256 만 남는다 — 다시 보여 줄
 * 방법이 없고, 잃으면 회수하고 새로 발급하는 것이 답이다(`sessions`·`share_links` 와 같은 방식).
 *
 * 받는 값 (JSON, 허용 키 **다섯**, 전부 필수 — R-07 대량 할당 차단)
 *   name      라벨 「사무실 PC」 — 폴더 이름과 같은 규칙(trim 뒤 1~50자, 제어문자·`/`·`\` 금지)
 *   purpose   용도 — 필수 500자. DB 에는 F-59 와 같은 `reason_text` 열에 들어간다 (PRD S-07)
 *   scope     `read` · `read+upload` · `read+upload+delete` · `community.write`(Q:F-8 — 커뮤니티 글쓰기,
 *             파일 창구 없음 · 독립 값) · `session.open`(Q:F-9 — 「이 PC 로그인 전용」 · 지금 요청 IP 에 묶임 ·
 *             같은 사람의 앞 로그인 전용 토큰 자동 회수 · 2단계 켜진 비Guest 만) 중 **역할이 허용하는 것만**
 *             (`Authz::tokenScopesFor` — 목록 밖이면 400)
 *   password  현재 비밀번호 — 요청마다 확인한다(세션에 창을 남기지 않는다)
 *   otp       지금의 2단계 인증 코드 6자리
 *
 * 순서 (ARCH 4.7 — 뒤로 갈수록 앞 단계를 통과한 사람만 도달한다)
 *   ① 허용 키·형식 → ② `scope ∈ Authz::tokenScopesFor($user)` → ③ 2단계 켜짐 →
 *   ④ 비밀번호 → ⑤ 2단계 코드(1회성) → ⑥ 살아 있는 5개 → ⑦ INSERT + 기록 → 201
 *
 * 거부
 *   400  bad_request     허용 키 밖 · 이름 규칙 · 용도 없음 · **역할 밖 범위**(역할 힌트를 주지 않는다)
 *   403  mfa_not_enabled 2단계 인증이 꺼진 계정 — 실패로 **세지 않는다**(코드 추측이 아니다)
 *   400  bad_credentials 비밀번호 틀림 + 실패 집계(R-48)
 *   400  bad_code        코드 틀림 + 집계 · `code_reused` 이미 쓴 코드 + 집계 (R-45)
 *   400  limit_exceeded  살아 있는 토큰이 이미 5개 (ARCH 4.6)
 *
 * ★ 인증 실패 세 사유(`bad_credentials`·`bad_code`·`code_reused`)와 `bad_request` 의 응답
 *   문구는 전부 「잘못된 요청입니다」 하나다 — `Http::REASON_UNIFORM` 이 그렇게 떨어뜨린다
 *   (ARCH 5.30 예외 목록, R-48 오라클 차단). 사유는 감사 로그에만 남는다.
 * ★ 대상은 언제나 **세션의 본인**이다. 요청 본문에 대상 계정이 들어가지 않고, 화면이
 *   "누구의" 를 고르지 않는다 (PRD 8장 `/api/me/*` 행).
 * ★ 세션에 「확인됨」을 남기지 않는다 — `reauth_at` 을 놓지 않는다(F-65 와 같은 helper).
 *   15분 창을 열면 그 창이 곧 우회 경로가 된다 (PRD F-66 ②, ARCH 5.29 ④).
 * ★ 비밀번호·코드는 지역 변수로만 쓰이고 검증 직후 `unset` 한다. 응답·감사·로그 어디에도
 *   남지 않는다 (PRD 7.7, S-09-11). 토큰 원문·hint 도 감사에 넣지 않는다.
 * ★ 역할 판단을 여기서 하지 않는다 — 라우트 정책 `authenticated` 가 로그인만 보고,
 *   고를 수 있는 범위는 `Authz::tokenScopesFor` 한 곳이 정한다 (PRD S-01).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자 (대상은 언제나 본인)
 * @var list<string>        $params 경로 변수 없음
 */

/** 살아 있는(회수되지 않은) 토큰의 계정당 상한 (PRD F-59 ② · F-66 ⑤, ARCH 4.6) */
const TBOX_API_TOKEN_MAX_LIVE = 5;

/** 발급하는 토큰의 길이 — scripts/api_token_rotate.sh 와 같은 48자 */
const TBOX_API_TOKEN_CHARS = 48;

$allowedFields = ['name', 'purpose', 'scope', 'password', 'otp'];

$body   = Http::jsonBody();
$db     = Db::instance();
$userId = (int) $user['id'];

/** 감사 로그의 「무엇을」 칸 — 발급자 = 대상 = 본인이다 (ARCH 4.7, AL-20) */
$auditTarget = [
    'target_type' => 'user',
    'target_id'   => $userId,
    'target_name' => (string) $user['username'],
];

/**
 * 400 거부 한 건 — 기록하고(사유는 갈라 둔다), 통일 문구로 끝낸다.
 * `$count` 가 참이면 실패로 센다(5회면 계정 잠금 + 세션 전부 끊김 — R-48).
 * 기록을 먼저 하는 이유: 잠금으로 세션이 끊기면 감사 로그의 actor 를 읽을 수 없다.
 */
$reject = static function (string $reason, bool $count = false) use ($auditTarget, $userId): void {
    Audit::deny('api_token.issue', $reason, $auditTarget);
    if ($count) {
        Auth::countFailureAndRevoke($userId);
    }
    // 네 사유(`bad_request`·`bad_credentials`·`bad_code`·`code_reused`)는 모두
    // REASON_UNIFORM 에 있어 「잘못된 요청입니다」 하나로 떨어진다 (ARCH 5.30).
    Http::error(400, Http::reasonMessage($reason));
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

// ─── [3] 용도 — 필수 500자 (PRD F-66 ⑤ · S-07). DB 는 F-59 와 같은 reason_text 열이다 ──
$rawPurpose = $body['purpose'] ?? null;
if (!is_string($rawPurpose) || preg_match('//u', $rawPurpose) !== 1) {
    $reject('bad_request');
}
$purpose = trim((string) $rawPurpose);
if ($purpose === '' || iconv_strlen($purpose, 'UTF-8') > 500) {
    $reject('bad_request');
}

// ─── [4] 범위 — **이 역할이 고를 수 있는 것만** (PRD F-66 ③, ARCH 4.7) ──
//        모르는 값도 역할 밖 값도 같은 `bad_request` 다 — 「그 범위는 당신 역할 밖」 이라는
//        힌트를 주지 않는다. 판단은 Authz 한 곳이고 여기에 역할 비교가 없다.
$rawScope = $body['scope'] ?? null;
if (!is_string($rawScope) || !in_array($rawScope, Authz::tokenScopesFor($user), true)) {
    $reject('bad_request');
}
$scope = $rawScope;

// ─── [4-1] Q:F-9 — 「이 PC 로그인 전용」(session.open)은 **지금 이 요청의 IP** 에 묶는다 (t-box F-75 ②, ARCH 4.9) ──
//        값은 Http::clientIp() 하나(REMOTE_ADDR — nginx 가 믿는 프록시에 대해서만 복원)이고 요청 머리·본문에서 읽지 않는다.
//        알 수 없으면(null) 발급하지 않는다 — 비밀번호·코드를 확인하기 **전**에 끝낸다(코드를 태우지 않는다 · 실패로 세지 않는다).
//        다른 범위의 토큰에는 IP 가 없다(DB CHECK `(scope = 'session.open') = (bound_ip IS NOT NULL)`).
$boundIp = null;
if ($scope === 'session.open') {
    $boundIp = Http::clientIp();
    if ($boundIp === null) {
        Audit::deny('api_token.issue', 'ip_unknown', $auditTarget);
        Http::error(400, '접속 IP 를 알 수 없어 발급하지 않습니다');
    }
}

// ─── [5] 비밀번호·코드의 **형식** (인증 실패와 사유가 다르다 — ARCH 5.29 helper 주석) ──
$password = $body['password'] ?? null;
$otp      = $body['otp'] ?? null;
if (!is_string($password) || $password === '' || strlen($password) > Auth::MAX_PASSWORD_BYTES
    || !is_string($otp) || preg_match(Totp::CODE_RE, trim($otp)) !== 1
) {
    $reject('bad_request');
}
$otp = trim($otp);

// ─── [6] 2단계 인증이 켜져 있나 (PRD F-66 ②, ARCH 4.7) ──────────
//        꺼진 계정은 발급할 수 없다 — 비밀번호 하나만으로는 「요청마다 두 가지」가 되지
//        않는다. 코드 추측이 아니므로 **실패로 세지 않는다**(집계는 ④⑤ 만).
//        판단 근거는 매 요청 DB 에서 읽은 세션 값이고 요청이 정하지 않는다 (PRD S-01).
if (($user['mfa_enabled'] ?? false) !== true) {
    Audit::deny('api_token.issue', 'mfa_not_enabled', $auditTarget);
    Http::error(403, Http::MSG_FORBIDDEN);
}

// ─── [7] 현재 비밀번호 — 요청마다 (F-65 와 같은 helper, 세션에 창을 남기지 않는다) ──
if (!Auth::verifyCurrentPassword($user, $password)) {
    $reject('bad_credentials', true);
}

// ─── [8] 2단계 인증 코드 — 1회성 (R-45, RFC 6238 §5.2) ──────────
//        helper 가 통과시키면 기준선(`mfa_last_step`)을 **바로** 올린다. 맞은 코드는
//        뒤에서 거부되더라도 다시 쓸 수 없다 — 그래야 1회성이 뜻을 갖는다.
$otpResult = Auth::verifyOtpOnce($user, $otp);
if ($otpResult === 'mfa_not_enabled') {
    // [6] 과 [8] 사이에 다른 창에서 2단계를 껐다 — [6] 과 같은 답이다(집계 없음).
    Audit::deny('api_token.issue', 'mfa_not_enabled', $auditTarget);
    Http::error(403, Http::MSG_FORBIDDEN);
}
if ($otpResult !== 'ok') {
    // `bad_code` · `code_reused` — 둘 다 집계한다 (R-48).
    $reject($otpResult, true);
}

// 확인이 끝났다 — 비밀은 여기서 사라진다 (PRD 7.7).
unset($password, $otp, $body);

// ─── [9] 살아 있는 토큰 상한 (PRD F-66 ⑤, ARCH 4.6) ─────────────
//        Q:F-9 — 「이 PC 로그인 전용」을 새로 받으면 앞의 로그인 전용 토큰은 아래 [11] 에서 회수된다(1대 규칙).
//        그래서 그 발급은 살아 있는 **로그인 전용 토큰을 빼고** 센다(n_other). 다른 범위의 발급은 전처럼 전부 센다.
$sqlLive = <<<'SQL'
    SELECT count(*) AS n,
           count(*) FILTER (WHERE scope <> 'session.open') AS n_other
      FROM api_tokens
     WHERE user_id = :uid AND revoked_at IS NULL
    SQL;
$live = $db->run($sqlLive, [':uid' => $userId])->fetch();
$liveCount = !is_array($live) ? TBOX_API_TOKEN_MAX_LIVE
    : ($scope === 'session.open' ? (int) $live['n_other'] : (int) $live['n']);
if ($liveCount >= TBOX_API_TOKEN_MAX_LIVE) {
    $reject('limit_exceeded');
}

// ─── [10] 토큰 만들기 — 예측 불가능한 난수만 (rand·uniqid 금지) ──
//        `scripts/api_token_rotate.sh` 와 같은 방식이다: 64바이트 난수를 base64 로 바꾼 뒤
//        헤더에 그대로 실을 수 있는 글자(`A-Za-z0-9`)만 남기고 앞 48자를 쓴다.
//        못 채우면 **닫힌 실패**로 끝낸다(짧은 토큰을 발급하지 않는다).
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

// ─── [11] 저장 + 기록 — 한 트랜잭션 ─────────────────────────────
//        만료는 **DB 시계**로 잰다(`now()`) — PHP 시계와 DB 시계를 섞지 않는다.
//        180 은 `Auth::API_TOKEN_TTL_DAYS` 한 곳에만 적혀 있고 바인딩으로 넘어간다.
$sqlInsert = <<<'SQL'
    INSERT INTO api_tokens (user_id, name, token_hash, hint, created_by, reason_text, scope, expires_at, bound_ip)
    VALUES (:uid, :name, :hash, :hint, :by, :purpose, :scope, now() + make_interval(days => :ttl), CAST(:bound_ip AS inet))
    RETURNING id, expires_at
    SQL;

// Q:F-9 — 1대 규칙: 같은 사람의 살아 있는 로그인 전용 토큰을 **전부** 회수한다(행은 지우지 않는다 — revoked_at·revoked_by).
//   회수된 토큰으로 연 세션도 다음 요청에서 끝난다(Auth::SQL_LOOKUP 이 그 토큰이 살아 있는지 본다).
$sqlReplace = <<<'SQL'
    UPDATE api_tokens
       SET revoked_at = now(),
           revoked_by = :by
     WHERE user_id = :uid
       AND scope = 'session.open'
       AND revoked_at IS NULL
    RETURNING id, name
    SQL;

/*
 * 검토 RV-QF9-2 — 로그인 전용 발급 둘이 겹치면 DB 의 부분 유일 인덱스(api_tokens_one_session_open)가 뒤 INSERT 를 23505 로 막는다.
 * 그때는 트랜잭션을 되돌리고 **한 번** 다시 한다(다시 할 때는 앞 발급의 새 행이 보여 회수된다). 그래도 겹치면 409.
 * 비밀번호·코드 확인은 이미 끝났다 — 다시 묻지 않는다(같은 요청 안). 되돌린 시도의 감사 행은 DB 에서는 함께 사라지고
 * 파일 사본(audit.log)에만 남을 수 있다(Audit 는 두 저장소에 쓴다 — 로그인 발급의 롤백과 같은 성질).
 */
$revokedPrevious = 0;
for ($issueTry = 1; ; $issueTry++) {
    $revokedPrevious = 0;
    $db->begin();
    try {
        if ($scope === 'session.open') {
            $replaced = $db->run($sqlReplace, [':by' => $userId, ':uid' => $userId])->fetchAll();
            foreach ($replaced as $old) {
                // 토큰 하나에 감사 한 행 — Auth::revokeAllApiTokens 와 같은 모양(번호·이름뿐 · 값·해시·hint 0)
                Audit::allow('api_token.revoke', $auditTarget + [
                    'detail' => [
                        'token_id'   => (int) $old['id'],
                        'token_name' => (string) $old['name'],
                    ],
                ], 'replaced');
            }
            $revokedPrevious = count($replaced);
        }
        $row = $db->run($sqlInsert, [
            ':uid'     => $userId,
            ':name'    => $name,
            ':hash'    => $hash,
            ':hint'    => $hint,
            // 발급한 사람도 본인이다 — F-59 는 여기가 관리자였다 (ARCH 4.7).
            ':by'      => $userId,
            ':purpose' => $purpose,
            ':scope'   => $scope,
            ':ttl'     => Auth::API_TOKEN_TTL_DAYS,
            // Q:F-9 — session.open 만 값이 있다(위 [4-1] — 서버가 본 IP). 나머지는 NULL.
            ':bound_ip' => $boundIp,
        ])->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('api_tokens INSERT 가 id 를 돌려주지 않았다');
        }
        $tokenId   = (int) $row['id'];
        $expiresAt = (string) $row['expires_at'];

        // detail 에 토큰 원문도 hint 도 해시도 비밀번호도 코드도 넣지 않는다
        // (PRD S-09-11 · 7.7, ARCH 4.7 · 7.5). `otp_verified` 는 「확인했다는 사실」뿐이다.
        $issueDetail = [
            'token_id'     => $tokenId,
            'token_name'   => $name,
            'token_scope'  => $scope,
            'reason_text'  => $purpose,
            'otp_verified' => true,
        ];
        if ($boundIp !== null) {
            // Q:F-9 — 묶인 IP(서버가 본 값 — 비밀이 아니다. 감사 행의 ip 칸과 같은 값). ARCH 7.5 `bound_ip`.
            $issueDetail['bound_ip'] = $boundIp;
        }
        Audit::allow('api_token.issue', $auditTarget + ['detail' => $issueDetail]);
        $db->commit();
    } catch (PDOException $e) {
        $db->rollback();
        $oneOpen = $scope === 'session.open' && (string) $e->getCode() === '23505'
            && strpos($e->getMessage(), 'api_tokens_one_session_open') !== false;
        if (!$oneOpen) {
            throw $e;
        }
        if ($issueTry >= 2) {
            Http::error(409, '다른 발급과 겹쳤습니다 — 잠시 뒤 다시 발급하세요');
        }
        continue;
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
    break;
}

// ★ `token` 이 나가는 곳은 여기 **한 줄**뿐이다 (API.md 13장, PRD 7.7 예외).
$issued = [
    'id'         => $tokenId,
    'name'       => $name,
    'hint'       => $hint,
    'scope'      => $scope,
    'expires_at' => $expiresAt,
    'token'      => $plain,
];
if ($scope === 'session.open') {
    // Q:F-9 — 사람이 확인하는 두 값: 어느 IP 에 묶였나 · 앞의 로그인 전용 토큰을 몇 개 회수했나 (API.md 20장).
    $issued['bound_ip']         = $boundIp;
    $issued['revoked_previous'] = $revokedPrevious;
}
Http::json($issued, 201);
