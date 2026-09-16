<?php
declare(strict_types=1);

/**
 * src/handlers/files_preview.php — GET /api/files/{id}/preview (정책: file.read)
 *
 * ★ 12-2 (PRD F-18, ARCH 5.8): "내려받지 않고 무슨 파일인지 본다".
 *   권한은 **다운로드(5.3)와 한 글자도 다르지 않다** — 같은 `Authz::decideRead`, 같은 403 문구,
 *   같은 sha256 대조. 다른 것은 **응답의 모양**뿐이다. 아래 [1]~[4] 는 files_download.php 의
 *   같은 자리 코드를 그대로 옮긴 것이다(SELECT 에 `ext` 만 더했다).
 *
 * 순서 (ARCH 5.8 표)
 *   [1] UUID 형태 확인 → files 조회(deleted_at IS NULL) → 없으면 404 동일 문구
 *   [2] Authz::decideRead → 거부면 `file.preview deny` + 403 동일 문구 (본문에 파일 정보 0)
 *   [3] Preview::kindFor(DB ext) 로 갈래 결정 — **요청 파라미터로 형식을 고르지 않는다**
 *   [4] Storage::assertExists + sha256 대조 → 어긋나면 error hash_mismatch + 500 (R-13)
 *   [5] 갈래별 본문을 만들어 JSON. image·pdf 는 본문 대신 raw 경로(src)
 *   [6] `file.preview allow` 기록 (detail.preview_kind, part=meta)
 *
 * 추출 실패(손상 zip·지원 밖 구조)는 `error parse_failed` 로 남기고 **200 + kind:none** 으로
 * 응답한다 — 화면에는 정보 카드만 나온다. "왜 못 읽었는지" 를 자세히 알려 주지 않는다.
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params $params[0] = public_id
 */

$publicId = $params[0] ?? '';

// ─── [1] 대상 찾기 (files_download.php 와 같은 코드) ───────────────
if (!is_string($publicId) || !Storage::isValidUuid($publicId)) {
    Http::error(404, Http::MSG_NOT_FOUND);
}

$sqlFind = <<<'SQL'
    SELECT f.id,
           f.original_name,
           f.stored_name,
           f.mime,
           f.ext,
           f.access_level,
           f.size_bytes,
           f.team_id,
           f.uploaded_by,
           f.sha256,
           f.scan_status,
           f.created_at,
           t.name     AS team_name,
           u.username AS uploader
      FROM files f
      JOIN users u ON u.id = f.uploaded_by
      LEFT JOIN teams t ON t.id = f.team_id
     WHERE f.public_id = CAST(:pid AS uuid)
       AND f.deleted_at IS NULL
    SQL;

$file = Db::instance()->run($sqlFind, [':pid' => $publicId])->fetch();
if (!is_array($file)) {
    Http::error(404, Http::MSG_NOT_FOUND);
}

// ─── [2] [권한 ✔] 읽기 판정 — 저장소에 손대기 전 (ARCH 5.8 [2], PRD S-01) ───
[$mayRead, $readCode] = Authz::decideRead($user, $file);

// 감사 로그의 "무엇을" 칸. 사용자 응답에는 한 글자도 나가지 않는다 (PRD S-07).
$auditWhat = [
    'owner_id'    => (int) $file['uploaded_by'],
    'target_team' => $file['team_id'] === null ? null : (int) $file['team_id'],
    'sha256'      => (string) $file['sha256'],
    'size_bytes'  => (int) $file['size_bytes'],
];

if (!$mayRead) {
    Audit::deny('file.preview', $readCode, [
        'target_type'  => 'file',
        'target_id'    => (int) $file['id'],
        'target_name'  => (string) $file['original_name'],
        'access_level' => (string) $file['access_level'],
        'detail'       => $auditWhat,
    ]);
    Http::error(403, Http::MSG_FORBIDDEN);
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
    Audit::deny('file.preview', $scanReason, [
        'target_type'  => 'file',
        'target_id'    => (int) $file['id'],
        'target_name'  => (string) $file['original_name'],
        'access_level' => (string) $file['access_level'],
        'detail'       => $auditWhat + ['scan_status' => $scan],
    ]);
    // F-57: 격리(`infected`)는 **영구** 거부라 403, 검사 중(`pending`)은 곧 풀리는
    // **일시** 상태라 423 + `Retry-After`. 네 창구가 이 두 줄로 똑같다 (ARCH 5.28).
    if ($scan === 'infected') {
        Http::error(403, Http::MSG_SCAN_INFECTED);
    }
    Http::locked(Http::SCAN_RETRY_AFTER_SECONDS, Http::MSG_SCAN_PENDING);
}

// ─── [3] 갈래 결정 — **DB `files.ext`** 만 본다 (ARCH 5.8 [3]) ─────
$ext  = (string) $file['ext'];
$kind = Preview::kindFor($ext);

$storedName = (string) $file['stored_name'];

// ─── [4] 실제 바이트 확인 + sha256 대조 (다운로드와 같은 코드, R-13) ───
try {
    Storage::assertExists($storedName);
} catch (Throwable $e) {
    Audit::error('file.preview', 'file_missing', [
        'target_type'  => 'file',
        'target_id'    => (int) $file['id'],
        'target_name'  => (string) $file['original_name'],
        'access_level' => (string) $file['access_level'],
    ]);
    throw $e;   // bootstrap 예외 핸들러 → 500 + {"error":"서버 오류"}
}

$actualHash = Storage::sha256($storedName);
if (!hash_equals((string) $file['sha256'], $actualHash)) {
    Audit::error('file.preview', 'hash_mismatch', [
        'target_type'  => 'file',
        'target_id'    => (int) $file['id'],
        'target_name'  => (string) $file['original_name'],
        'access_level' => (string) $file['access_level'],
        'detail'       => $auditWhat,
    ]);
    Http::error(500, '서버 오류');
}

// ─── [5] 갈래별 본문 ───────────────────────────────────────────────
// 응답의 공통 칸. sha256 은 앞 12자만 — 화면에서 "같은 파일인지" 확인하는 용도이고
// 전체 해시를 굳이 내보내지 않는다(전체 값은 감사 로그에 있다).
$body = [
    'kind'         => $kind,
    'name'         => (string) $file['original_name'],
    'size_bytes'   => (int) $file['size_bytes'],
    'mime'         => (string) $file['mime'],
    'ext'          => $ext,
    'access_level' => (string) $file['access_level'],
    'team_name'    => $file['team_name'] === null ? null : (string) $file['team_name'],
    'uploaded_by'  => (string) $file['uploader'],
    'created_at'   => (string) $file['created_at'],
    'sha256_short' => substr((string) $file['sha256'], 0, 12),
];

$parseFailed = false;

try {
    if ($kind === 'image' || $kind === 'pdf') {
        // 원본은 별도 경로로만 나간다 (CSP sandbox·nosniff·inline — ARCH 5.8 [5-raw], R-41).
        $body['src'] = '/api/files/' . $publicId . '/preview/raw';
    } elseif ($kind === 'text') {
        $text = Preview::text(Storage::pathFor($storedName));
        $body['content']   = $text['content'];
        $body['truncated'] = $text['truncated'];
        $body['encoding']  = $text['encoding'];
    } elseif ($kind === 'table') {
        $table = Preview::table(Storage::pathFor($storedName));
        $body['rows']      = $table['rows'];
        $body['truncated'] = $table['truncated'];
    } elseif ($kind === 'archive') {
        $archive = Preview::archive(Storage::pathFor($storedName));
        $body['entries']       = $archive['entries'];
        $body['total_entries'] = $archive['total_entries'];
        $body['truncated']     = $archive['truncated'];
    } elseif ($kind === 'office') {
        $office = Preview::office(Storage::pathFor($storedName), $ext);
        $body['excerpt']   = $office['excerpt'];
        $body['truncated'] = $office['truncated'];
        $body['parts']     = $office['parts'];
    } else {
        $body['reason'] = 'unsupported';
    }
} catch (Throwable $e) {
    // 손상 파일·지원 범위 밖 구조. 서버 오류가 아니라 "읽어 내지 못했다" 이므로
    // 기록은 error parse_failed, 응답은 200 + 정보 카드다 (ARCH 5.8, R-42).
    error_log('[tbox] request_id=' . TBOX_REQUEST_ID . ' preview parse_failed: ' . $e->getMessage());
    $parseFailed = true;
    $kind        = 'none';
    $body        = [
        'kind'         => 'none',
        'name'         => (string) $file['original_name'],
        'size_bytes'   => (int) $file['size_bytes'],
        'mime'         => (string) $file['mime'],
        'ext'          => $ext,
        'access_level' => (string) $file['access_level'],
        'team_name'    => $file['team_name'] === null ? null : (string) $file['team_name'],
        'uploaded_by'  => (string) $file['uploader'],
        'created_at'   => (string) $file['created_at'],
        'sha256_short' => substr((string) $file['sha256'], 0, 12),
        'reason'       => 'parse_failed',
    ];
}

// ─── [6] 감사 기록 — 다운로드와 같은 무게 (PRD S-07) ────────────────
$auditCtx = [
    'target_type'  => 'file',
    'target_id'    => (int) $file['id'],
    'target_name'  => (string) $file['original_name'],
    'access_level' => (string) $file['access_level'],
];

if ($parseFailed) {
    $auditCtx['detail'] = array_merge($auditWhat, ['preview_kind' => Preview::kindFor($ext), 'part' => 'meta']);
    Audit::error('file.preview', 'parse_failed', $auditCtx);
} else {
    // 허용에도 근거를 남긴다 — granted_by 가 없으면 "왜 허용됐나" 를 조사할 수 없다.
    // 파일 **내용**은 detail 에도 summary 에도 넣지 않는다 (PRD 7.7 민감정보 금지).
    $auditCtx['detail'] = array_merge(
        ['granted_by' => $readCode],
        $auditWhat,
        ['preview_kind' => $kind, 'part' => 'meta']
    );
    Audit::allow('file.preview', $auditCtx);
}

Http::json($body);
