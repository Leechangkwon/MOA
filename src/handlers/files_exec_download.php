<?php
declare(strict_types=1);

/**
 * src/handlers/files_exec_download.php — POST /api/files/{id}/exec-download (정책: file.read)
 *
 * ★ F-65 (2026-09-21, PRD F-65 ③, ARCH 5.29 「받기」 표, SECURITY R-94).
 *   「실행 파일이 든 압축본」(`files.exec_content = true`)을 받는 **유일한** 창구다.
 *   보통 다운로드(`GET …/download`)·미리보기 둘·공유 링크는 그 파일에 대해 전부 막혀 있고,
 *   여기만 열린다 — 대신 **요청마다** 비밀번호와 2단계 인증 코드를 다시 확인한다.
 *
 * 왜 POST 이고 왜 본문인가
 *   비밀번호를 GET 쿼리에 실으면 브라우저 히스토리·nginx access 로그·Referer 에 남는다.
 *   그래서 본문(JSON)으로 받고, 읽은 직후 지운다 (PRD 7.7).
 *   POST 이므로 라우터 [3] 의 `Http::requireCsrf()` 가 그대로 적용된다 (PRD S-09-3).
 *
 * 왜 세션 전용인가
 *   이 라우트는 `TBOX_API_TOKEN_ROUTES` 에 **없다**. 그래서 `X-API-Token` 헤더는 읽히지도
 *   않고, 토큰만 있는 요청은 401 「로그인이 필요합니다」 로 끝난다 — 프로그램은 2단계 코드를
 *   칠 수 없으므로 애초에 이 길을 쓸 수 없다 (API.md 9-1, ARCH 4.6).
 *
 * 왜 창(15분)을 열지 않는가
 *   `Auth::verifyCurrentPassword`·`Auth::verifyOtpOnce` 는 세션에 `reauth_at` 을 놓지 않는다.
 *   훔친 세션으로는 코드를 만들 수 없다는 것이 F-65 의 핵심이고, 한 번 확인한 창을 남기면
 *   그 창 안에서는 다시 훔친 세션이 통한다 (ARCH 5.29 ④, PRD F-65 ②).
 *
 * 순서 (ARCH 5.29 「받기」 표 마지막 행 — 5.3 과 같은 순서에 확인 두 단계를 끼운 것)
 *   [1] UUID 형태 → 아니면 404
 *   [2] 본문 허용 키(`password`·`otp` 둘) → 그 밖이면 400 `bad_request`
 *   [3] 파일 행 → 없으면 404
 *   [4] `Authz::decideRead` → 거부면 403 동일 문구 (여기까지 오면 받는 사람은 언제나 Admin).
 *       그 거부는 `file.download deny <판정 코드>` 로 남는다 — 아래 주석 참고
 *   [5] `exec_content` 가 거짓 → 400 `bad_request` (이 창구는 그 파일들만의 길이다)
 *   [6] 비밀번호 → 틀리면 400 통일 문구 + 실패 집계
 *   [7] 2단계 코드 → `mfa_not_enabled` 403 · `bad_code`·`code_reused` 400 + 실패 집계
 *   [8] `scan_status` 관문 (5.28 — pending 423 / infected 403)
 *   [9] 저장소 존재 + sha256 대조 (R-13)
 *   [10] `Audit::allow('file.download_exec')` → **전송**
 *
 * 전송은 `files_download.php` 와 **같은 함수** `Storage::stream()` 이다 — 헤더
 * (`application/octet-stream` + `Content-Disposition: attachment`)·`X-Accel`/readfile 판단이
 * 그 함수 안에 이미 한 벌로 있어, 이 파일에는 복사한 코드가 한 줄도 없다.
 *
 * 비밀번호·코드는 읽은 직후 `unset` 하고 응답·감사·app.log 어디에도 남기지 않는다 (PRD 7.7).
 * 감사에 남는 것은 `otp_verified: true` — 「서버가 확인했다」는 **사실**뿐이다 (ARCH 7.5).
 *
 * 요구사항: PRD F-65 ③ · S-01 · S-02 · S-07 · 7.7 · 8장 API 표 · T-77 (c),
 *          ARCH 5.29 · 5.3 · 5.28 · 4.0 · 7.4 · 7.5, SECURITY R-13 · R-45 · R-48 · R-94
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자 (요청 값이 아니다 — PRD S-01)
 * @var list<string>        $params $params[0] = public_id
 */

// ─── [1] UUID 형태 ────────────────────────────────────────────
$publicId = $params[0] ?? '';
if (!is_string($publicId) || !Storage::isValidUuid($publicId)) {
    Http::error(404, Http::MSG_NOT_FOUND);
}

/* ─── [2] 본문 허용 키 화이트리스트 (SECURITY R-07, share_create.php [1] 과 같은 자리) ───
 * 파일 행을 **읽기 전에** 본문 형태부터 본다. 그래서 이 거부 기록에는 대상이 없다
 * (`file.download_exec|deny|bad_request` 문장 틀에 {target} 이 없는 이유).
 */
$allowedFields = ['password', 'otp'];

$body = Http::jsonBody();
if (array_diff(array_keys($body), $allowedFields) !== []) {
    Audit::deny('file.download_exec', 'bad_request', [
        'target_type' => 'file',
        'target_name' => null,
    ]);
    Http::error(400, Http::MSG_BAD_REQUEST);
}

// 값을 지역 변수로 옮기고 **본문 배열을 통째로 지운다** — 그 뒤의 어떤 코드도 두 값을 볼 수 없다.
$rawPassword = $body['password'] ?? '';
$rawOtp      = $body['otp'] ?? '';
unset($body);
if (!is_string($rawPassword) || !is_string($rawOtp)) {
    unset($rawPassword, $rawOtp);
    Audit::deny('file.download_exec', 'bad_request', [
        'target_type' => 'file',
        'target_name' => null,
    ]);
    Http::error(400, Http::MSG_BAD_REQUEST);
}

// ─── [3] 파일 행 ──────────────────────────────────────────────
$sqlFind = <<<'SQL'
    SELECT f.id,
           f.original_name,
           f.stored_name,
           f.mime,
           f.access_level,
           f.size_bytes,
           f.team_id,
           f.uploaded_by,
           f.sha256,
           f.scan_status,
           f.exec_content,
           'file' AS item_kind   -- G-0 종류 표식(개인 등급 판정 — Authz::decideRead [P])
      FROM files f
     WHERE f.public_id = CAST(:pid AS uuid)
       AND f.deleted_at IS NULL
    SQL;

$file = Db::instance()->run($sqlFind, [':pid' => $publicId])->fetch();
if (!is_array($file)) {
    unset($rawPassword, $rawOtp);
    Http::error(404, Http::MSG_NOT_FOUND);
}

// 감사 로그의 "무엇을" 칸 (ARCH 7.5) — 응답에는 한 글자도 나가지 않는다 (PRD S-07).
$auditWhat = [
    'owner_id'    => (int) $file['uploaded_by'],
    'target_team' => $file['team_id'] === null ? null : (int) $file['team_id'],
    'sha256'      => (string) $file['sha256'],
    'size_bytes'  => (int) $file['size_bytes'],
];
$auditTarget = [
    'target_type'  => 'file',
    'target_id'    => (int) $file['id'],
    'target_name'  => (string) $file['original_name'],
    'access_level' => (string) $file['access_level'],
];

// ─── [4] [권한 ✔] 읽기 판정 — 확인 절차보다 **앞**이다 (ARCH 5.29) ───
// 볼 자격이 없는 사람에게는 비밀번호를 물어볼 일조차 없다(물어보는 것 자체가
// "그런 파일이 있다" 는 신호다). 통과하면 그 사람은 언제나 Admin 이다(admin-only).
[$mayRead, $readCode] = Authz::decideRead($user, $file);
if (!$mayRead) {
    unset($rawPassword, $rawOtp);
    /* 감사 action 은 이 갈래만 `file.download` 다 — ARCH 7.4 의 `file.download_exec` 표에
     * `role_not_permitted`·`team_mismatch` 행이 없고, 있는 `bad_request` 문장은
     * 「허용되지 않는 형식·실행 파일이 아닌 파일」 을 뜻해 권한 거부와 뜻이 다르다.
     * 없는 사유를 지어내는 대신 뜻이 같은 기존 문장을 쓴다. **어느 창구였나는
     * `detail.route`(라우터가 자동으로 붙인다, ARCH 7.5)가 답한다.** */
    Audit::deny('file.download', $readCode, $auditTarget + ['detail' => $auditWhat]);
    Http::error(403, Http::MSG_FORBIDDEN);
}

/* ─── [5] 이 창구는 「실행 파일이 든 압축본」 전용이다 ─────────
 * 보통 파일을 여기로 받으려는 요청은 400 이다 — 창구를 하나로 합치면 「어떤 파일에는
 * 확인이 없다」 가 되고, 그 갈래를 고르는 것은 요청이 된다. 응답은 통일 문구다.
 */
if ($file['exec_content'] !== true) {
    unset($rawPassword, $rawOtp);
    Audit::deny('file.download_exec', 'bad_request', $auditTarget + ['detail' => $auditWhat]);
    Http::error(400, Http::MSG_BAD_REQUEST);
}

/* ─── [6] 비밀번호 — **이 요청에 실린 값**만 본다 (ARCH 5.29 ④와 같은 규칙) ───
 * 실패는 로그인 뒤 창구와 **같은 카운터**로 센다: 5회면 계정이 잠기고 그 순간 그 계정의
 * 세션이 전부 끊긴다 (SECURITY R-48). 기록을 **먼저** 남긴다 — 세션이 끊긴 뒤에는
 * actor 를 읽을 수 없다 (Auth::reauth 와 같은 순서).
 */
if (!Auth::verifyCurrentPassword($user, $rawPassword)) {
    unset($rawPassword, $rawOtp);
    Audit::deny('file.download_exec', 'bad_credentials', $auditTarget + ['detail' => $auditWhat]);
    Auth::countFailureAndRevoke((int) $user['id']);
    Http::error(400, Http::MSG_BAD_REQUEST);
}

/* ─── [7] 2단계 인증 코드 — 1회성 (R-45, RFC 6238 §5.2) ───────
 * 형식(6자리)은 여기서 먼저 보고, 맞고·재사용 판정은 Auth::verifyOtpOnce 한 곳에서 한다.
 * 형식이 아니어도 사유는 같은 `bad_code` 다 — 「형식이 틀렸나 값이 틀렸나」를 알려 주지
 * 않는다(코드 공간을 좁혀 주지 않는다).
 */
$otpResult = preg_match(Totp::CODE_RE, $rawOtp) === 1
    ? Auth::verifyOtpOnce($user, $rawOtp)
    : 'bad_code';
unset($rawPassword, $rawOtp);

if ($otpResult !== 'ok') {
    Audit::deny('file.download_exec', $otpResult, $auditTarget + ['detail' => $auditWhat]);
    if ($otpResult === 'mfa_not_enabled') {
        // 코드 추측이 아니므로 실패로 세지 않는다. Admin 은 F-52 로 2단계 인증이 필수라
        // 실제로는 닿지 않는 갈래이지만, 정책이 바뀌어도 코드가 지키도록 둔다 (ARCH 5.29).
        Http::error(403, Http::MSG_FORBIDDEN);
    }
    Auth::countFailureAndRevoke((int) $user['id']);
    Http::error(400, Http::MSG_BAD_REQUEST);
}

/* ─── [8] F-54 검사 상태 관문 (ARCH 5.28 · 5.3 [4]) ────────────
 * 자리는 다른 세 창구와 **같다**: 권한·확인 뒤, 저장소 접근 앞.
 * 사유·문구·상태 코드(423/403)도 한 글자도 같다.
 */
$scan = (string) ($file['scan_status'] ?? 'clean');
if ($scan !== 'clean') {
    $scanReason = $scan === 'infected' ? 'scan_infected' : 'scan_pending';
    Audit::deny('file.download', $scanReason, $auditTarget + [
        'detail' => $auditWhat + ['scan_status' => $scan],
    ]);
    if ($scan === 'infected') {
        Http::error(403, Http::MSG_SCAN_INFECTED);
    }
    Http::locked(Http::SCAN_RETRY_AFTER_SECONDS, Http::MSG_SCAN_PENDING);
}

$storedName = (string) $file['stored_name'];

// ─── [9] 실제 바이트 확인 → sha256 대조 → 감사 → 전송 (순서를 바꾸지 않는다) ───
try {
    Storage::assertExists($storedName);
} catch (Throwable $e) {
    Audit::error('file.download', 'file_missing', $auditTarget);
    throw $e;   // bootstrap 예외 핸들러 → 500 + {"error":"서버 오류"}
}

// 저장된 바이트가 업로드 당시의 기록과 같은지 **전송 전에** 확인한다 (ARCH 5.3 [6], R-13).
// 실행 파일이 든 묶음일수록 바꿔치기의 값어치가 크므로, 이 대조는 특히 건너뛰지 않는다.
if (!hash_equals((string) $file['sha256'], Storage::sha256($storedName))) {
    Audit::error('file.download', 'hash_mismatch', $auditTarget + ['detail' => $auditWhat]);
    Http::error(500, '서버 오류');
}

// ─── [10] 감사 → 전송 ─────────────────────────────────────────
// detail 은 5.3 의 키 그대로에 `otp_verified: true` 를 더한 것이다 (ARCH 5.29 「받기」 표).
// 비밀번호·코드 값은 어느 키에도 없다 — 위에서 이미 unset 됐다 (PRD 7.7).
Audit::allow('file.download_exec', $auditTarget + [
    'detail' => ['granted_by' => $readCode, 'otp_verified' => true] + $auditWhat,
]);

// 전송은 files_download.php 와 **같은 함수**다 (복사한 코드 없음).
Storage::stream($storedName, (string) $file['original_name'], (string) $file['mime']);
