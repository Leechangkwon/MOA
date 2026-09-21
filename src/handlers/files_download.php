<?php
declare(strict_types=1);

/**
 * src/handlers/files_download.php — GET /api/files/{id}/download (정책: file.read)
 *
 * ★ 4단계: 파일 행을 읽은 **직후**, 저장소에 손대기 **전에** `Authz::decideRead()` 로 판정한다
 *   (ARCH 5.3 "[권한 ✔] 스토리지 접근 전"). 거부면 감사 로그 deny + 403 동일 문구로 끝난다 —
 *   본문에는 파일명·레벨·존재 여부 어떤 것도 담지 않는다(PRD S-02 단건 조회, Q-6 결정).
 *   서버가 아는 값(대상·레벨·소유자·팀·해시)은 응답이 아니라 **감사 로그**에 남긴다(PRD S-07).
 *
 * 요구사항: PRD F-06, S-01 · S-02 · S-07, 8장 API 표, ARCH 5.3
 *
 * 그 밖에 지키는 것
 *   · 외부 ID 는 `public_id`(UUID) 뿐이다. 순차 정수 id 를 URL 로 받지 않는다 (PRD S-09-5)
 *   · 없는 파일·삭제된 파일·UUID 형태가 아닌 값은 전부 404 동일 문구
 *   · 파일 경로는 Storage 가 stored_name(UUID) 으로만 만든다 (PRD S-06, T-11)
 *   · 응답은 항상 첨부파일(application/octet-stream + attachment) — 업로드된 HTML·SVG 가
 *     같은 도메인에서 실행되는 저장형 XSS 를 막는다 (bootstrap 의 nosniff 와 함께, PRD S-03)
 *   · 다운로드 성공은 반드시 감사 로그에 남긴다. 단, 실제 파일 존재 확인을 **기록 전에** 해서
 *     "받지 못했는데 성공으로 기록" 되는 일을 막는다 (PRD S-07)
 *   · 6단계: 저장된 파일의 sha256 을 DB 값과 **대조한 뒤에만** allow 기록·전송한다
 *     (ARCH 5.3 [6], SECURITY R-13 — 저장소 파일 바꿔치기 탐지). 불일치는 500 + error 기록
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params $params[0] = public_id
 */

$publicId = $params[0] ?? '';

// 라우터는 `[0-9a-f-]{36}` 까지만 좁힌다. UUIDv4 형태 재검사는 여기서 한다
// (형태가 아니면 DB 캐스팅 오류가 아니라 404 로 끝낸다).
if (!is_string($publicId) || !Storage::isValidUuid($publicId)) {
    Http::error(404, Http::MSG_NOT_FOUND);
}

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
           f.exec_content
      FROM files f
     WHERE f.public_id = CAST(:pid AS uuid)
       AND f.deleted_at IS NULL
    SQL;

$file = Db::instance()->run($sqlFind, [':pid' => $publicId])->fetch();
if (!is_array($file)) {
    Http::error(404, Http::MSG_NOT_FOUND);
}

// ─── [권한 ✔] 읽기 판정 — 저장소에 손대기 전 (ARCH 5.3, PRD S-01) ───
// 판정과 근거 코드를 함께 받는다. 코드는 핸들러가 지어내지 않고 그대로 기록한다 (ARCH 4.1).
[$mayRead, $readCode] = Authz::decideRead($user, $file);

// 감사 로그의 "무엇을" 칸 — 어떤 내용의 파일이었나(sha256·크기), 누구 것이었나, 어느 팀 것이었나.
// 사용자 응답에는 한 글자도 나가지 않고 로그에만 남는다 (PRD S-07 기록 원칙).
$auditWhat = [
    'owner_id'    => (int) $file['uploaded_by'],
    'target_team' => $file['team_id'] === null ? null : (int) $file['team_id'],
    'sha256'      => (string) $file['sha256'],
    'size_bytes'  => (int) $file['size_bytes'],
];

if (!$mayRead) {
    Audit::deny('file.download', $readCode, [
        'target_type'  => 'file',
        'target_id'    => (int) $file['id'],
        'target_name'  => (string) $file['original_name'],
        'access_level' => (string) $file['access_level'],
        'detail'       => $auditWhat,
    ]);
    Http::error(403, Http::MSG_FORBIDDEN);
}

/* ─── [3-5] F-65 실행 파일이 든 압축본 관문 (ARCH 5.29 「받기」 표) ───
 *
 * 자리: `decideRead` **뒤** · `scan_status` 관문 **앞**. F-54 관문과 **같은 이유**로 이 자리다 —
 * 볼 자격이 없는 사람에게는 「그런 파일이 있다」조차 알려 주지 않아야 하고(그래서 권한 뒤),
 * 저장소에 손대기 전에 끝나야 한다(그래서 무결성 앞).
 *
 * 두 갈래다.
 *   · **토큰**(`TBOX_AUTH_METHOD` 정의됨) → 403 통일 문구. 프로그램은 2단계 코드를 칠 수
 *     없으므로 「다음에 무엇을 하라」가 없다 — 알려 줄 것도 없다 (API.md 9-1).
 *   · **세션** → 403 `{error: MSG_EXEC_ACK_REQUIRED, next:"exec_ack"}`. 여기까지 온 사람은
 *     admin-only 를 읽을 수 있는 Admin 이고, 받는 길(`POST …/exec-download`)이 따로 있다.
 */
if ($file['exec_content'] === true) {
    $execReason = defined('TBOX_AUTH_METHOD') ? 'exec_content_token' : 'exec_ack_required';
    Audit::deny('file.download', $execReason, [
        'target_type'  => 'file',
        'target_id'    => (int) $file['id'],
        'target_name'  => (string) $file['original_name'],
        'access_level' => (string) $file['access_level'],
        'detail'       => $auditWhat,
    ]);
    if ($execReason === 'exec_content_token') {
        Http::error(403, Http::MSG_FORBIDDEN);
    }
    Http::json(['error' => Http::MSG_EXEC_ACK_REQUIRED, 'next' => 'exec_ack'], 403);
    exit;
}

/* ─── [4] F-54 검사 상태 관문 (ARCH 5.3 [4] · 5.28, PRD F-54 ③) ─────
 *
 * 검사 전(`pending`)·격리(`infected`) 파일은 **아무도** 받지 못한다. 자리가 여기인 이유:
 *   · 권한 판정(`decideRead`)보다 **뒤** — 볼 자격이 없는 사람에게는 "그런 파일이 있다"
 *     조차 알려 주지 않아야 하고, 403 의 뜻(IDOR)이 섞이면 안 된다.
 *   · 저장소 접근(`assertExists`·sha256)보다 **앞** — 격리된 바이트는 이미 저장소에 없다.
 * 사유 코드는 상태에 따라 `scan_pending`·`scan_infected` 두 가지이고, 문장 틀은 Audit 표에 있다.
 */
$scan = (string) ($file['scan_status'] ?? 'clean');
if ($scan !== 'clean') {
    $scanReason = $scan === 'infected' ? 'scan_infected' : 'scan_pending';
    Audit::deny('file.download', $scanReason, [
        'target_type'  => 'file',
        'target_id'    => (int) $file['id'],
        'target_name'  => (string) $file['original_name'],
        'access_level' => (string) $file['access_level'],
        'detail'       => $auditWhat + ['scan_status' => $scan],
    ]);
    // F-57: 격리(`infected`)는 **영구** 거부라 403, 검사 중(`pending`)은 곧 풀리는
    // **일시** 상태라 423 + `Retry-After`. 다섯 창구(F-65 exec-download 포함)가 이 두 줄로 똑같다 (ARCH 5.28).
    if ($scan === 'infected') {
        Http::error(403, Http::MSG_SCAN_INFECTED);
    }
    Http::locked(Http::SCAN_RETRY_AFTER_SECONDS, Http::MSG_SCAN_PENDING);
}

$storedName = (string) $file['stored_name'];

// 실제 바이트 확인 → 감사 로그 → 전송 (순서를 바꾸지 않는다)
try {
    Storage::assertExists($storedName);
} catch (Throwable $e) {
    Audit::error('file.download', 'file_missing', [
        'target_type'  => 'file',
        'target_id'    => (int) $file['id'],
        'target_name'  => (string) $file['original_name'],
        'access_level' => (string) $file['access_level'],
    ]);
    throw $e;   // bootstrap 예외 핸들러 → 500 + {"error":"서버 오류"}
}

// ─── sha256 대조 (6단계 필수, ARCH 5.3 [6], SECURITY R-13) ───────
// 저장된 바이트가 업로드 당시의 기록과 같은지 **전송 전에** 확인한다. 서버가 뚫려 저장소 파일이
// 악성 문서로 바뀌어도 여기서 멈춘다. 다르면 allow 를 남기지 않고 error(hash_mismatch) 만 남기며
// (AL-6 경보 대상), 응답은 bootstrap 예외 경로와 같은 500 + {"error":"서버 오류"} 다 —
// "해시가 달라서 막았다" 는 사실조차 응답으로 알려 주지 않는다.
$actualHash = Storage::sha256($storedName);
if (!hash_equals((string) $file['sha256'], $actualHash)) {
    Audit::error('file.download', 'hash_mismatch', [
        'target_type'  => 'file',
        'target_id'    => (int) $file['id'],
        'target_name'  => (string) $file['original_name'],
        'access_level' => (string) $file['access_level'],
        'detail'       => $auditWhat,
    ]);
    Http::error(500, '서버 오류');
}

Audit::allow('file.download', [
    'target_type'  => 'file',
    'target_id'    => (int) $file['id'],
    'target_name'  => (string) $file['original_name'],
    'access_level' => (string) $file['access_level'],
    // 허용에도 근거를 남긴다 — granted_by 가 없으면 "왜 허용됐나" 를 조사할 수 없다 (PRD S-07)
    'detail'       => array_merge(['granted_by' => $readCode], $auditWhat),
]);

Storage::stream($storedName, (string) $file['original_name'], (string) $file['mime']);
