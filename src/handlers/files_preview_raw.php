<?php
declare(strict_types=1);

/**
 * src/handlers/files_preview_raw.php — GET /api/files/{id}/preview/raw (정책: file.read)
 *
 * ★ 12-2 (PRD F-18, ARCH 5.8 [5-raw], SECURITY R-41): **이미지·PDF 만** 원본을 inline 으로
 *   내려 준다. 다른 갈래는 400 `preview_not_available` 이며, 그 시도도 감사 로그에 남는다.
 *
 *   권한 검사는 다운로드(5.3)·미리보기 JSON(5.8)과 **같은 코드 경로**다 —
 *   같은 `Authz::decideRead`, 같은 403 문구, 같은 sha256 대조. 아래 [1]~[4] 는
 *   files_download.php 의 같은 자리 코드를 그대로 옮긴 것이다(SELECT 에 `ext` 만 더했다).
 *
 *   갈래는 **DB `files.ext`** 로만 정한다 (ARCH 5.8 [3]). 요청 파라미터로 형식을 고를 수 있으면
 *   `.zip` 을 `image` 라고 속여 inline 전송을 유도할 수 있다.
 *
 *   응답 헤더(Storage::streamInline)에 `Content-Security-Policy: sandbox; default-src 'none'` ·
 *   `X-Content-Type-Options: nosniff` · `Content-Disposition: inline` · `Cache-Control: no-store` ·
 *   `Referrer-Policy: no-referrer` 가 붙는다. 다운로드의 "항상 첨부" 규칙(F-06)은 그대로이고,
 *   inline 은 **별도 경로**로만 존재한다.
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
           f.sha256
      FROM files f
     WHERE f.public_id = CAST(:pid AS uuid)
       AND f.deleted_at IS NULL
    SQL;

$file = Db::instance()->run($sqlFind, [':pid' => $publicId])->fetch();
if (!is_array($file)) {
    Http::error(404, Http::MSG_NOT_FOUND);
}

// ─── [2] [권한 ✔] 읽기 판정 — 저장소에 손대기 전 (ARCH 5.8 [2], PRD S-01) ───
[$mayRead, $readCode] = Authz::decideRead($user, $file);

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

// ─── [3] 갈래 결정 — inline 은 image·pdf 두 갈래뿐이다 (R-41) ───────
$ext  = (string) $file['ext'];
$kind = Preview::kindFor($ext);

if ($kind !== 'image' && $kind !== 'pdf') {
    Audit::deny('file.preview', 'preview_not_available', [
        'target_type'  => 'file',
        'target_id'    => (int) $file['id'],
        'target_name'  => (string) $file['original_name'],
        'access_level' => (string) $file['access_level'],
        'detail'       => array_merge($auditWhat, ['preview_kind' => $kind, 'part' => 'raw']),
    ]);
    Http::error(400, Http::MSG_BAD_REQUEST);
}

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

// ─── [5] 기록한 뒤에 흘린다 (다운로드와 같은 순서) ──────────────────
Audit::allow('file.preview', [
    'target_type'  => 'file',
    'target_id'    => (int) $file['id'],
    'target_name'  => (string) $file['original_name'],
    'access_level' => (string) $file['access_level'],
    'detail'       => array_merge(
        ['granted_by' => $readCode],
        $auditWhat,
        ['preview_kind' => $kind, 'part' => 'raw']
    ),
]);

Storage::streamInline($storedName, (string) $file['original_name'], (string) $file['mime']);
