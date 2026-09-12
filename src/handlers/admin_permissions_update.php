<?php
declare(strict_types=1);

/**
 * src/handlers/admin_permissions_update.php — PATCH /api/admin/permissions
 *                                             (정책: role:Admin + 재인증 + CSRF, PRD F-25)
 *
 * 요구사항: PRD F-25(조건 ①~⑤) · 8장 `PATCH /api/admin/permissions` 행 · 10장 ·
 *           12.2 T-39 · 7.11 AL-13 · 14장 Q-12, ARCH 5.15 · 6.2-1 · 10장 · 7.4 · 7.5,
 *           SECURITY R-60 · R-43
 *
 * 이 파일은 저장소에서 `permissions` 표를 **바꾸는 유일한 곳**이다. 그래서 조건이 다섯 겹이다
 * (PRD F-25). 앞의 셋은 이 파일에 닿기 전에 이미 끝났다:
 *   ① Admin — 라우트 정책 `role:Admin` (라우터 [4])
 *   ① 재인증 — 15분 창 (라우터 [3-2], F-23)
 *     + CSRF (라우터 [3])
 * 나머지는 아래에서 한다:
 *   ② 요청마다 2단계 인증 코드 — `Totp::verifyStep` + `mfa_last_step` 1회성 (R-45)
 *   ③ 사유 필수 (1~500자)
 *   ④ 서버 불변 규칙 (a)~(e)
 *   ⑤ 쓰기는 **별도 DB 롤 `tbox_perm`**(`Db::perm()`)으로만 — 앱 롤 `tbox_app` 은 이 표에
 *      여전히 SELECT 만 갖는다. 그래서 다른 핸들러의 결함(SQL 인젝션 등)으로는 이 표를
 *      바꿀 수 없다는 성질이 그대로 남는다 (SECURITY R-60 · R-43, ARCH 10장).
 *      `Db::perm(` 이 이 파일 밖에 나오면 `scripts/security_lint.sh` 가 반려한다.
 *
 * 받는 값 (JSON, **허용 키 셋 밖의 키가 하나라도 있으면 400**)
 *   changes     [{role, access_level, can_read, can_upload}] 1~16개, 값은 **bool 만**
 *   reason_text 왜 바꾸는가 — 필수, 1~500자 (PRD S-07)
 *   otp         지금의 2단계 인증 코드 6자리 (요청마다, 1회성)
 *
 * 순서는 ARCH 5.15 ①~⑥ 그대로다. 순서가 곧 거부 사유의 순서이기도 하다:
 *   형식(`bad_request`) → 코드(`bad_code`·`code_reused`) → 불변 규칙(`invariant`) → 저장.
 *
 * ★ 요청이 보낸 `role`·`access_level` 은 **SQL 식별자·경로·문장에 들어가지 않는다**.
 *   허용 목록(Authz::isAssignableRole · Authz::LEVEL_RANK)을 정확히 통과한 값만
 *   `WHERE role = :role AND access_level = :lvl` 의 **바인딩 값**으로 쓰인다 (PRD S-04).
 *
 * ★ 2단계 인증 코드·비밀은 응답·감사 로그·app.log 어디에도 남지 않는다. 남는 것은
 *   "확인했다" 는 사실(`detail.otp_verified`)뿐이다 (ARCH 7.5, SECURITY R-05).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자 (Admin, 재인증 통과)
 * @var list<string>        $params 경로 변수 없음
 */

$userId = (int) $user['id'];
$db     = Db::instance();

/**
 * 감사 로그의 "무엇을" 칸 — 거부 행의 대상은 표 자체다(아직 어느 칸인지 정해지지 않았다).
 * 허용 행은 칸마다 따로 남기며 `target_name` 이 `역할|등급` 이 된다 (ARCH 5.15 ⑤).
 */
$auditTable = [
    'target_type' => 'system',
    'target_name' => 'permissions',
];

/**
 * 거부 한 건 — 기록하고, 필요하면 실패로 세고, 같은 문구(400)로 끝낸다.
 *
 * `$countFailure` 가 참인 것은 **코드 추측**(bad_code·code_reused)뿐이다 (13단계 R-48 규칙).
 * 형식 오류·불변 규칙 위반은 비밀값 추측이 아니므로 계정 잠금 카운터에 넣지 않는다 —
 * 넣으면 화면의 실수가 관리자 계정을 잠그는 길이 된다.
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
    Audit::deny('permission.change', $reason, $ctx);
    if ($countFailure) {
        Auth::countFailureAndRevoke($userId);
    }
    Http::error(400, Http::MSG_BAD_REQUEST);
};

/* ─── ① 형식 — 허용 키 셋, 값의 모양 (PRD F-25, R-07 과 같은 화이트리스트) ──────── */

$allowedFields = ['changes', 'reason_text', 'otp'];

$body = Http::jsonBody();
if ($body === [] || array_diff(array_keys($body), $allowedFields) !== []) {
    $reject('bad_request');
}
// 셋 다 필수다(선택 항목이 없다) — 하나라도 없으면 형식 오류다.
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
 * `changes` — 1~16개의 칸. 각 항목은 **정확히 네 칸**이고 값은 bool 만이다.
 * 같은 칸이 두 번 오면 거부한다(뒤엣것이 앞엣것을 덮는 모호한 요청을 남기지 않는다).
 *
 * @var array<string,array{read:bool,upload:bool}> $wanted  "역할|등급" => 바라는 값
 */
$changesRaw = $body['changes'];
if (!is_array($changesRaw) || $changesRaw === [] || count($changesRaw) > 16
    || array_keys($changesRaw) !== range(0, count($changesRaw) - 1)
) {
    $reject('bad_request');
}

$cellFields = ['role', 'access_level', 'can_read', 'can_upload'];
$wanted     = [];
foreach ($changesRaw as $entry) {
    if (!is_array($entry) || array_diff($cellFields, array_keys($entry)) !== []
        || array_diff(array_keys($entry), $cellFields) !== []
    ) {
        $reject('bad_request');
    }
    if (!is_string($entry['role']) || !is_string($entry['access_level'])
        || !is_bool($entry['can_read']) || !is_bool($entry['can_upload'])
    ) {
        $reject('bad_request');
    }
    $key = $entry['role'] . '|' . $entry['access_level'];
    if (array_key_exists($key, $wanted)) {
        $reject('bad_request');      // 같은 칸을 두 번 보냈다
    }
    $wanted[$key] = [
        'role'   => (string) $entry['role'],
        'level'  => (string) $entry['access_level'],
        'read'   => (bool) $entry['can_read'],
        'upload' => (bool) $entry['can_upload'],
    ];
}

/* ─── ② 2단계 인증 코드 — 맞나, 그리고 이미 쓴 코드는 아닌가 (R-45, RFC 6238 §5.2) ── */

$sqlMfa = <<<'SQL'
    SELECT mfa_enabled, mfa_secret, mfa_last_step FROM users WHERE id = :id
    SQL;

$mfaRow = $db->run($sqlMfa, [':id' => $userId])->fetch();
if (!is_array($mfaRow) || $mfaRow['mfa_enabled'] !== true || !is_string($mfaRow['mfa_secret'])) {
    /* 2단계 인증이 꺼진 계정으로는 이 창구를 쓸 수 없다. 코드 추측이 아니므로 실패로 세지 않고
     * 형식 오류로 기록한다 — 11단계 강제 절차(F-16)가 모든 계정에 2단계 인증을 요구하므로
     * 정상 운영에서는 닿지 않는 갈래다. */
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
/* 맞은 코드는 **여기서 바로** 1회성 기준선을 올린다 — 뒤의 불변 규칙 검사에서 거부되더라도
 * 그 코드는 다시 쓸 수 없다. 거부된 요청의 코드를 살려 두면 "한 번 받은 코드로 여러 번
 * 시도" 가 되어 1회성의 뜻이 사라진다 (R-45). 30초 뒤 다음 코드로 다시 시도하면 된다. */
Auth::markMfaStep($userId, (int) $step);
unset($otp, $otpRaw, $body, $changesRaw);

/* ─── ③ 불변 규칙 (a)~(e) — 서버가 판단한다 (ARCH 5.15, PRD F-25 ④) ─────────────── */

/**
 * 규칙 (a)·(c) — **값이 고정된 칸**. 키는 `역할|등급`,
 * 값은 [고정 can_read, 고정 can_upload, 어긴 경우 기록할 규칙 글자].
 * ★ `admin_permissions_get.php` 의 같은 표와 내용이 같아야 한다(한쪽을 고치면 다른 쪽도).
 *   역할 문자열을 `if` 로 비교하지 않고 표로 두는 이유는 그쪽 주석과 같다 — 역할에 대한
 *   판단은 Authz 한 곳이고, 여기서 다루는 것은 "어느 칸이 고정인가" 라는 자료다 (ARCH 4.1).
 *
 * @var array<string,array{0:bool,1:bool,2:string}> $lockedCells
 */
$lockedCells = [
    'Admin|public'     => [true,  true,  'a'],
    'Admin|internal'   => [true,  true,  'a'],
    'Admin|team'       => [true,  true,  'a'],
    'Admin|admin-only' => [true,  true,  'a'],
    'Guest|admin-only' => [false, false, 'c'],
];

foreach ($wanted as $key => $cell) {
    // (d) 역할·등급은 4×4 안 — 허용 목록을 **정확히** 통과한 값만 아래 SQL 에 바인딩된다.
    //     역할 목록은 Authz 가 갖고 있다(역할 판단은 그 파일 하나, ARCH 4.1).
    if (!Authz::isAssignableRole($cell['role'])
        || !array_key_exists($cell['level'], Authz::LEVEL_RANK)
    ) {
        $reject('invariant', false, ['rule' => 'd']);
    }
    // (a)·(c) 고정 칸은 그 값 그대로여야 한다(바꾸려 하면 거부).
    if (array_key_exists($key, $lockedCells)
        && ($cell['read'] !== $lockedCells[$key][0] || $cell['upload'] !== $lockedCells[$key][1])
    ) {
        $reject('invariant', false, ['rule' => $lockedCells[$key][2]]);
    }
    // (b) 올릴 수 있으면 읽을 수 있어야 한다.
    if ($cell['upload'] && !$cell['read']) {
        $reject('invariant', false, ['rule' => 'b']);
    }
}

/* 현재 16행을 **앱 롤로** 읽어 이전값을 만든다 (읽기에는 tbox_perm 이 필요 없다). */
$sqlMatrix = <<<'SQL'
    SELECT role, access_level, can_read, can_upload
      FROM permissions
     ORDER BY role, access_level
    SQL;

$current = [];
foreach ($db->run($sqlMatrix)->fetchAll() as $row) {
    $current[(string) $row['role'] . '|' . (string) $row['access_level']] = [
        'read'   => $row['can_read'] === true,
        'upload' => $row['can_upload'] === true,
    ];
}
if (count($current) !== 16) {
    // 16행 고정이 깨졌다면 그것 자체가 사건이다 — 조용히 진행하지 않는다 (ARCH 6.2-1).
    throw new RuntimeException('권한 표가 16행이 아닙니다');
}

/* ─── (f) 읽기 등급의 **단조성** (18-1 추가 — PRD F-26, ARCH 5.16, SECURITY R-63) ──────
 *
 * 역할마다 `can_read` 는 등급 순서(`public` → `internal` → `team` → `admin-only`)로
 * **앞에서부터 연속**이어야 한다. 켜짐이 한 번 끊긴 뒤에 다시 켜져 있으면(예: `internal` 은
 * 꺼졌는데 `team` 은 켜짐) 위반이다.
 *
 * 왜 이 규칙이 필요한가 — 폴더 권한(F-26) 때문이다. 폴더와 파일이 모두 등급을 갖게 되면서
 * "폴더에 들어올 수 있는 사람만 그 안의 파일을 본다" 를 **쓰기 시점의 불변 규칙 ①**
 * (하위 등급 ≥ 부모 등급, `Authz::fitsUnderFolder`)로 보장하기로 했다. 그 보장이 성립하려면
 * "더 좁은 등급을 읽을 수 있는 사람은 더 넓은 등급도 읽을 수 있다" 가 참이어야 한다.
 * 이 표를 화면에서 편집할 수 있으므로(F-25), 편집으로 그 전제가 깨지면 목록 SQL 이 파일
 * 조건만 보는 지금 구조에서 **폴더는 못 보는데 그 안 파일은 보이는** 틈이 생긴다.
 * 그래서 전제 자체를 이 창구에서 지킨다 — 검사는 **바뀐 칸이 아니라 바뀐 뒤의 16행 전체**로 한다
 * (칸 하나만 봐서는 이웃 칸과의 연속성을 알 수 없다).
 *
 * 등급 순서는 `Authz::LEVEL_RANK` 의 선언 순서 그대로다 — 순서를 이 파일에 복사하지 않는다.
 */
$effective = $current;
foreach ($wanted as $key => $cell) {
    $effective[$key] = ['read' => $cell['read'], 'upload' => $cell['upload']];
}

$readByRole = [];
foreach ($effective as $key => $flags) {
    $parts = explode('|', $key, 2);
    if (count($parts) !== 2) {
        continue;
    }
    $readByRole[$parts[0]][$parts[1]] = $flags['read'];
}
foreach ($readByRole as $levelsOfRole) {
    $gapSeen = false;
    foreach (array_keys(Authz::LEVEL_RANK) as $levelName) {
        $on = $levelsOfRole[$levelName] ?? false;
        if ($on && $gapSeen) {
            $reject('invariant', false, ['rule' => 'f']);
        }
        if (!$on) {
            $gapSeen = true;
        }
    }
}

/** 실제로 값이 바뀌는 칸만 고른다 (e). @var list<array<string,mixed>> $changed */
$changed = [];
foreach ($wanted as $key => $cell) {
    if (!array_key_exists($key, $current)) {
        // 4×4 안이지만 표에 없는 조합 — 행을 새로 만들 권한이 없다(INSERT 없음, ARCH 10장).
        $reject('invariant', false, ['rule' => 'd']);
    }
    if ($current[$key]['read'] === $cell['read'] && $current[$key]['upload'] === $cell['upload']) {
        continue;
    }
    $changed[] = [
        'key'    => $key,
        'role'   => $cell['role'],
        'level'  => $cell['level'],
        'before' => ['can_read' => $current[$key]['read'], 'can_upload' => $current[$key]['upload']],
        'after'  => ['can_read' => $cell['read'],          'can_upload' => $cell['upload']],
    ];
}
if ($changed === []) {
    // (e) 바뀌는 칸이 0개 — "사유만 적은 요청" 을 변경으로 남기지 않는다.
    $reject('invariant', false, ['rule' => 'e']);
}

/* ─── ④⑤ 저장 + 감사 (ARCH 5.15 ④⑤) ──────────────────────────────────────────────
 *
 * 순서가 중요하다:
 *   perm 트랜잭션 시작 → 칸마다 UPDATE(rowCount 1 확인) → 칸마다 감사 기록(앱 연결, 자동 커밋)
 *   → perm COMMIT. 감사 기록이 예외를 내면 perm ROLLBACK 이므로 **기록 없는 변경이 남지 않는다**
 *   (ARCH 4장 [6] "기록 실패 → 처리도 롤백"). 반대 순서(커밋 먼저)면 기록이 실패한 변경이
 *   DB 에 남는다.
 *
 * 참·거짓은 `'true'`·`'false'` 문자열로 넘기고 SQL 에서 CAST 한다 — PHP 의 false 를 그대로
 * 바인딩하면 드라이버가 빈 문자열로 보내 PostgreSQL 이 거절한다 (12-3 에서 실측, org_team_update).
 */
$sqlUpdateCell = <<<'SQL'
    UPDATE permissions
       SET can_read   = CAST(:read AS boolean),
           can_upload = CAST(:upload AS boolean)
     WHERE role = :role AND access_level = :lvl
    SQL;

$perm = Db::perm();
$perm->begin();
try {
    foreach ($changed as $cell) {
        $st = $perm->run($sqlUpdateCell, [
            ':read'   => $cell['after']['can_read'] ? 'true' : 'false',
            ':upload' => $cell['after']['can_upload'] ? 'true' : 'false',
            ':role'   => $cell['role'],
            ':lvl'    => $cell['level'],
        ]);
        if ($st->rowCount() !== 1) {
            // 정확히 한 행이어야 한다(기본키가 role+access_level). 아니면 표가 예상과 다르다.
            throw new RuntimeException('권한 칸을 바꾸지 못했습니다');
        }
    }

    foreach ($changed as $cell) {
        Audit::allow('permission.change', [
            'target_type' => 'system',
            // 무엇을 — 바뀐 **칸 이름**(`역할|등급`). 요청 값이 아니라 위 허용 목록을 통과한 값이다.
            'target_name' => $cell['key'],
            'detail'      => [
                'before'       => $cell['before'],
                'after'        => $cell['after'],
                'reason_text'  => $reason,
                // 코드 자체가 아니라 "확인했다" 는 사실만 남긴다 (ARCH 7.5).
                'otp_verified' => true,
            ],
        ]);
    }

    $perm->commit();
} catch (Throwable $e) {
    $perm->rollback();
    throw $e;
}

/* ─── ⑥ 응답 — 바뀐 칸 목록 + 16행 재조회 (효과는 다음 요청부터, ARCH 5.15) ──────── */

$matrix = [];
foreach ($db->run($sqlMatrix)->fetchAll() as $row) {
    $matrix[] = [
        'role'         => (string) $row['role'],
        'access_level' => (string) $row['access_level'],
        'can_read'     => $row['can_read'] === true,
        'can_upload'   => $row['can_upload'] === true,
    ];
}

$changedOut = [];
foreach ($changed as $cell) {
    $changedOut[] = [
        'role'         => $cell['role'],
        'access_level' => $cell['level'],
        'before'       => $cell['before'],
        'after'        => $cell['after'],
    ];
}

Http::json([
    'ok'      => true,
    'changed' => $changedOut,
    // 키 이름은 ARCH 5.15 ⑥ 그대로 `matrix` 다 (GET 과 같은 이름 — 화면이 한 가지로 받는다).
    'matrix'  => $matrix,
]);
