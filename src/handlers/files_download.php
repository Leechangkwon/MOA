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
           f.sha256
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

Audit::allow('file.download', [
    'target_type'  => 'file',
    'target_id'    => (int) $file['id'],
    'target_name'  => (string) $file['original_name'],
    'access_level' => (string) $file['access_level'],
    // 허용에도 근거를 남긴다 — granted_by 가 없으면 "왜 허용됐나" 를 조사할 수 없다 (PRD S-07)
    'detail'       => array_merge(['granted_by' => $readCode], $auditWhat),
]);

Storage::stream($storedName, (string) $file['original_name'], (string) $file['mime']);
