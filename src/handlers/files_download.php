<?php
declare(strict_types=1);

/**
 * src/handlers/files_download.php — GET /api/files/{id}/download (정책: authenticated)
 *
 * ★ 3단계: 권한 검사 없음 — 4단계에서 파일 행을 읽은 **직후**, 저장소에 손대기 **전에**
 *   `Authz::canRead($user, $file)` 를 넣는다(실패 → Audit::deny + 403 동일 문구).
 *   지금은 로그인만 했으면 어떤 파일이든 받아진다. 그것이 이 단계의 정상 동작이다
 *   (PRD 13장 3단계 / 기존 t-box 취약점 재현 상태).
 *
 * 요구사항: PRD F-06, 8장 API 표, ARCH 5.3
 *
 * 3단계에도 지키는 것
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
           f.size_bytes
      FROM files f
     WHERE f.public_id = CAST(:pid AS uuid)
       AND f.deleted_at IS NULL
    SQL;

$file = Db::instance()->run($sqlFind, [':pid' => $publicId])->fetch();
if (!is_array($file)) {
    Http::error(404, Http::MSG_NOT_FOUND);
}

// ★ 4단계에서 이 자리에 Authz::canRead($user, $file) 가 들어간다.

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
    'detail'       => ['size_bytes' => (int) $file['size_bytes']],
]);

Storage::stream($storedName, (string) $file['original_name'], (string) $file['mime']);
