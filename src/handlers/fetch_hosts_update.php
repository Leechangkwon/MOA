<?php
declare(strict_types=1);

/**
 * src/handlers/fetch_hosts_update.php — POST /api/fetch-hosts
 *                                        (정책: role:Admin + 재인증 + CSRF, PRD F-43)
 *
 * 요구사항: PRD F-43 · 8장 `POST /api/fetch-hosts` 행 · 5장 · 12.2 T-56 · 7.11 AL-18,
 *           ARCH 5.22 · 7.4 · 7.5, SECURITY R-76 · R-05(재인증) · R-45(코드 1회성)
 *
 * 이 파일은 저장소에서 `fetch_hosts` 표를 **바꾸는 유일한 곳**이다. 허용 호스트 한 줄은
 * "서버가 어디로 나갈 수 있나" 를 바꾸는 값(PRD S-05)이라, 권한 매트릭스(F-25)와 **같은 급**의
 * 조건을 건다. 앞의 셋은 이 파일에 닿기 전에 이미 끝났다:
 *   ① Admin       — 라우트 정책 `role:Admin` (라우터 [4])
 *   ① 재인증 15분 — `role:Admin` 이 `Authz::REAUTH_POLICIES` 에 있어 관문 [3-2] 가 자동으로 붙는다 (F-23)
 *   ① CSRF        — 라우터 [3]
 * 나머지는 아래에서 한다:
 *   ② 요청마다 2단계 인증 코드 — `Totp::verifyStep` + `mfa_last_step` 1회성 (R-45)
 *   ③ 사유 필수 (1~500자)
 *   ④ 호스트 형식 규칙 — 소문자 · 점 하나 이상 · 와일드카드 금지 · IP 리터럴/localhost 금지 ·
 *                        스킴(`https://`)이나 경로가 섞인 값 금지
 *   ⑤ 한 트랜잭션 · 칸마다 감사 로그 · 경보 AL-18
 *
 * ★ **DELETE 가 없다.** 그만 쓰려면 `is_active=false` 다. 허용에서 뺀 호스트도 "언제부터
 *   언제까지 열려 있었나" 가 조사에 필요하고, 앱 롤에는 이 표의 DELETE 권한 자체가 없다
 *   (db/grants.sql — `\dp fetch_hosts` 가 `tbox_app=arw`).
 *
 * ★ 권한 매트릭스와 달리 **별도 DB 롤(`Db::perm`)을 쓰지 않는다.** 그 롤은 `permissions`
 *   표 하나를 위한 것이고(SECURITY R-60), 여기에 자격을 더하면 그 롤이 닿는 표가 늘어난다.
 *   대신 이 표의 방어는 "앱 롤에 DELETE 가 없다 + 창구가 하나 + 코드·사유·재인증" 이다.
 *
 * ★ 요청이 보낸 호스트 문자열은 **SQL 식별자·경로·문장에 들어가지 않는다**. 위 ④ 를 정확히
 *   통과한 값만 `WHERE host = :host` 의 **바인딩 값**으로 쓰인다 (PRD S-04).
 *
 * ★ 2단계 인증 코드는 응답·감사 로그·app.log 어디에도 남지 않는다. 남는 것은 "확인했다" 는
 *   사실(`detail.otp_verified`)뿐이다 (ARCH 7.5, SECURITY R-05).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자 (Admin, 재인증 통과)
 * @var list<string>        $params 경로 변수 없음
 */

$userId = (int) $user['id'];
$db     = Db::instance();

/** 한 번에 바꿀 수 있는 호스트 수 — 실수로 목록 전체를 갈아엎지 못하게 하는 상한 */
const TBOX_FETCH_HOST_MAX_CHANGES = 20;

/**
 * 호스트 표기 규칙 (④).
 *   · 낱말은 영숫자로 시작하고 끝난다(붙임표가 가장자리에 오지 않는다).
 *   · 점이 **최소 하나** — `localhost` 같은 한 낱말 이름은 이 정규식에서 끝난다.
 *   · 대문자·공백·`*`·`:`·`/` 는 애초에 맞지 않으므로 `https://a.com/b` 도 여기서 걸린다.
 * DB 의 CHECK(`fetch_hosts_host_fmt`)는 같은 뜻의 **마지막 그물**이다 — 둘 다 있어야 한다.
 */
const TBOX_FETCH_HOST_RE = '/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/';

/**
 * 감사 로그의 "무엇을" 칸 — 거부 행의 대상은 표 자체다(아직 어느 호스트인지 정해지지 않았다).
 * 허용 행은 호스트마다 따로 남기며 `target_name` 이 그 호스트 이름이 된다.
 */
$auditTable = [
    'target_type' => 'system',
    'target_name' => 'fetch_hosts',
];

/**
 * 거부 한 건 — 기록하고, 필요하면 실패로 세고, 400 으로 끝낸다.
 * 문장은 `Http::reasonMessage()` 표에서 온다 (F-68, ARCH 5.30) — `bad_code`·`code_reused` 는
 * `REASON_UNIFORM` 이 지켜 「잘못된 요청입니다」 그대로다(오라클 차단, R-48·R-45).
 * `$countFailure` 가 참인 것은 **코드 추측**(bad_code·code_reused)뿐이다 (13단계 R-48 규칙).
 *
 * @param array<string,mixed> $detail
 */
$reject = static function (string $reason, bool $countFailure = false, array $detail = [])
    use ($auditTable, $userId): void {
    $ctx = $auditTable;
    if ($detail !== []) {
        $ctx['detail'] = $detail;
    }
    // 기록을 먼저 한다 — 잠금으로 세션이 끊기면 감사 로그의 actor 를 읽을 수 없다.
    Audit::deny('fetch_host.change', $reason, $ctx);
    if ($countFailure) {
        Auth::countFailureAndRevoke($userId);
    }
    Http::error(400, Http::reasonMessage($reason));
};

/* ─── ① 형식 — 허용 키 셋, 값의 모양 (R-07 과 같은 화이트리스트) ──────────────────── */

$allowedFields = ['changes', 'reason_text', 'otp'];

$body = Http::jsonBody();
if ($body === [] || array_diff(array_keys($body), $allowedFields) !== []) {
    $reject('bad_request');
}
// 셋 다 필수다(선택 항목이 없다).
if (!array_key_exists('changes', $body) || !array_key_exists('reason_text', $body)
    || !array_key_exists('otp', $body)
) {
    $reject('bad_request');
}

$reasonRaw = $body['reason_text'];
if (!is_string($reasonRaw) || preg_match('//u', $reasonRaw) !== 1) {
    $reject('bad_request');
}
$reason = trim((string) $reasonRaw);
if ($reason === '' || iconv_strlen($reason, 'UTF-8') > 500) {
    $reject('bad_request');
}

$otpRaw = $body['otp'];
if (!is_string($otpRaw) || preg_match(Totp::CODE_RE, trim($otpRaw)) !== 1) {
    $reject('bad_request');
}
$otp = trim((string) $otpRaw);

/**
 * `changes` — 1~20개. 각 항목은 `host`·`is_active` 필수, `note` 는 선택이다.
 * 같은 호스트가 두 번 오면 거부한다(뒤엣것이 앞엣것을 덮는 모호한 요청을 남기지 않는다).
 *
 * @var array<string,array{host:string,active:bool,note:?string}> $wanted
 */
$changesRaw = $body['changes'];
if (!is_array($changesRaw) || $changesRaw === []
    || count($changesRaw) > TBOX_FETCH_HOST_MAX_CHANGES
    || array_keys($changesRaw) !== range(0, count($changesRaw) - 1)
) {
    $reject('bad_request');
}

$entryFields = ['host', 'is_active', 'note'];
$wanted      = [];
foreach ($changesRaw as $entry) {
    if (!is_array($entry)
        || array_diff(['host', 'is_active'], array_keys($entry)) !== []
        || array_diff(array_keys($entry), $entryFields) !== []
    ) {
        $reject('bad_request');
    }
    if (!is_string($entry['host']) || !is_bool($entry['is_active'])) {
        $reject('bad_request');
    }

    $note = null;
    if (array_key_exists('note', $entry)) {
        if (!is_string($entry['note']) || preg_match('//u', $entry['note']) !== 1) {
            $reject('bad_request');
        }
        $note = trim((string) preg_replace('/[\x00-\x1f\x7f]+/u', '', $entry['note']));
        if (iconv_strlen($note, 'UTF-8') > 200) {
            $reject('bad_request');
        }
    }

    // ④ 호스트 정규화 — 소문자·trim 은 **저장 전에** 한다(대문자로 보낸 값이 소문자로 남는다).
    $host = strtolower(trim((string) $entry['host']));
    if (array_key_exists($host, $wanted)) {
        $reject('bad_request');      // 같은 호스트를 두 번 보냈다
    }
    $wanted[$host] = ['host' => $host, 'active' => (bool) $entry['is_active'], 'note' => $note];
}

/* ─── ② 2단계 인증 코드 — 맞나, 그리고 이미 쓴 코드는 아닌가 (R-45, RFC 6238 §5.2) ── */

$sqlMfa = <<<'SQL'
    SELECT mfa_enabled, mfa_secret, mfa_last_step FROM users WHERE id = :id
    SQL;

$mfaRow = $db->run($sqlMfa, [':id' => $userId])->fetch();
if (!is_array($mfaRow) || $mfaRow['mfa_enabled'] !== true || !is_string($mfaRow['mfa_secret'])) {
    // 2단계 인증이 꺼진 계정으로는 이 창구를 쓸 수 없다. 코드 추측이 아니므로 실패로 세지 않는다.
    $reject('bad_request');
}

$step = Totp::verifyStep((string) $mfaRow['mfa_secret'], $otp);
if ($step === null) {
    $reject('bad_code', true);
}
$lastStep = $mfaRow['mfa_last_step'] === null ? null : (int) $mfaRow['mfa_last_step'];
if (Auth::isMfaStepUsed($lastStep, (int) $step)) {
    $reject('code_reused', true);
}
/* 맞은 코드는 **여기서 바로** 1회성 기준선을 올린다 — 뒤의 형식 검사에서 거부되더라도 그
 * 코드는 다시 쓸 수 없다 (F-25 와 같은 판단, R-45). 30초 뒤 다음 코드로 다시 시도하면 된다. */
Auth::markMfaStep($userId, (int) $step);
unset($otp, $otpRaw, $body, $changesRaw);

/* ─── ④ 호스트 표기 규칙 — 서버가 판단한다 ─────────────────────────────────────── */

foreach ($wanted as $host => $cell) {
    if ($host === '' || strlen($host) > 253 || preg_match(TBOX_FETCH_HOST_RE, $host) !== 1) {
        // 와일드카드(`*.a.com`)·스킴(`https://a.com`)·경로·공백·한 낱말 이름이 전부 여기서 끝난다.
        $reject('invariant', false, ['rule' => 'format']);
    }
    if (filter_var($host, FILTER_VALIDATE_IP) !== false
        || ctype_digit(str_replace('.', '', $host))      // 10진 IP(2130706433)·점 표기 IP
        || strpos($host, '0x') === 0                      // 16진 표기
    ) {
        // IP 로 적은 주소는 넣지 못한다. 넣어도 Fetch::check 가 막지만, **목록에 들어가는 것
        // 자체**를 막는다 — 목록은 사람이 읽고 판단하는 자료이기도 하다.
        $reject('invariant', false, ['rule' => 'ip_literal']);
    }
}

/* ─── 지금 표를 읽어 이전값을 만든다 ──────────────────────────────────────────── */

$sqlCurrent = <<<'SQL'
    SELECT host, note, is_active FROM fetch_hosts
    SQL;

$current = [];
foreach ($db->run($sqlCurrent)->fetchAll() as $row) {
    $current[(string) $row['host']] = [
        'host'   => (string) $row['host'],
        'note'   => $row['note'] === null ? '' : (string) $row['note'],
        'active' => $row['is_active'] === true,
    ];
}

/** 실제로 값이 바뀌는 것만 고른다. @var list<array<string,mixed>> $changed */
$changed = [];
foreach ($wanted as $host => $cell) {
    $before = $current[$host] ?? null;
    $note   = $cell['note'];

    if ($before === null) {
        $changed[] = [
            'host'   => $host,
            'new'    => true,
            'before' => null,
            'after'  => ['is_active' => $cell['active'], 'note' => $note ?? ''],
            'note'   => $note ?? '',
        ];
        continue;
    }
    // note 를 보내지 않았으면 지금 값을 그대로 둔다(빈 문자열로 지우지 않는다).
    $afterNote = $note === null ? $before['note'] : $note;
    if ($before['active'] === $cell['active'] && $before['note'] === $afterNote) {
        continue;   // 바뀌는 것이 없다
    }
    $changed[] = [
        'host'   => $host,
        'new'    => false,
        'before' => ['is_active' => $before['active'], 'note' => $before['note']],
        'after'  => ['is_active' => $cell['active'], 'note' => $afterNote],
        'note'   => $afterNote,
    ];
}

if ($changed === []) {
    // 바뀌는 것이 0개 — "사유만 적은 요청" 을 변경으로 남기지 않는다 (F-25 규칙 (e) 와 같다).
    $reject('invariant', false, ['rule' => 'no_change']);
}

/* ─── ⑤ 저장 + 감사 (ARCH 5.22) ───────────────────────────────────────────────
 *
 * 순서는 F-25 와 같다: 트랜잭션 시작 → 행마다 INSERT/UPDATE → 행마다 감사 기록 → COMMIT.
 * 감사 기록이 예외를 내면 ROLLBACK 이므로 **기록 없는 변경이 남지 않는다** (ARCH 4장 [6]).
 *
 * 참·거짓은 `'true'`·`'false'` 문자열로 넘기고 SQL 에서 CAST 한다 — PHP 의 false 를 그대로
 * 바인딩하면 드라이버가 빈 문자열로 보내 PostgreSQL 이 거절한다 (12-3 에서 실측).
 */
$sqlInsert = <<<'SQL'
    INSERT INTO fetch_hosts (host, note, is_active, created_by)
    VALUES (:host, :note, CAST(:active AS boolean), :uid)
    SQL;

$sqlUpdate = <<<'SQL'
    UPDATE fetch_hosts
       SET is_active  = CAST(:active AS boolean),
           note       = :note,
           updated_at = now()
     WHERE host = :host
    SQL;

$db->begin();
try {
    foreach ($changed as $cell) {
        if ($cell['new'] === true) {
            $st = $db->run($sqlInsert, [
                ':host'   => $cell['host'],
                ':note'   => $cell['note'] === '' ? null : $cell['note'],
                ':active' => $cell['after']['is_active'] ? 'true' : 'false',
                ':uid'    => $userId,
            ]);
        } else {
            $st = $db->run($sqlUpdate, [
                ':host'   => $cell['host'],
                ':note'   => $cell['note'] === '' ? null : $cell['note'],
                ':active' => $cell['after']['is_active'] ? 'true' : 'false',
            ]);
        }
        if ($st->rowCount() !== 1) {
            // 정확히 한 행이어야 한다(host 가 UNIQUE). 아니면 표가 예상과 다르다.
            throw new RuntimeException('허용 사이트 행을 바꾸지 못했습니다');
        }
    }

    foreach ($changed as $cell) {
        Audit::allow('fetch_host.change', [
            'target_type' => 'system',
            // 무엇을 — **호스트 이름**. 요청 값이 아니라 위 형식 검사를 통과한 값이다.
            'target_name' => $cell['host'],
            'detail'      => [
                'before'       => $cell['before'],
                'after'        => $cell['after'],
                'reason_text'  => $reason,
                // 코드 자체가 아니라 "확인했다" 는 사실만 남긴다 (ARCH 7.5).
                'otp_verified' => true,
            ],
        ]);
    }

    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    throw $e;
}

/* ─── 응답 — 바뀐 것 목록 + 표 재조회 (효과는 다음 요청부터) ─────────────────── */

$sqlHosts = <<<'SQL'
    SELECT f.id,
           f.host,
           f.note,
           f.is_active,
           f.created_at,
           f.updated_at,
           u.username AS created_by_name
      FROM fetch_hosts f
      LEFT JOIN users u ON u.id = f.created_by
     ORDER BY f.host
    SQL;

$hosts = [];
foreach ($db->run($sqlHosts)->fetchAll() as $row) {
    $hosts[] = [
        'id'         => (int) $row['id'],
        'host'       => (string) $row['host'],
        'note'       => $row['note'] === null ? '' : (string) $row['note'],
        'is_active'  => $row['is_active'] === true,
        'created_at' => (string) $row['created_at'],
        'updated_at' => (string) $row['updated_at'],
        'created_by' => $row['created_by_name'] === null ? '' : (string) $row['created_by_name'],
    ];
}

$changedOut = [];
foreach ($changed as $cell) {
    $changedOut[] = [
        'host'   => $cell['host'],
        'added'  => $cell['new'],
        'before' => $cell['before'],
        'after'  => $cell['after'],
    ];
}

Http::json(['ok' => true, 'changed' => $changedOut, 'hosts' => $hosts], 200);
