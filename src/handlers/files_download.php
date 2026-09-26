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
 * ★ Q:F-3a 라운드 C (2026-09-26) — 행 읽기 · 세 관문(권한 → F-65 → F-54) · 무결성 두 단계는
 *   `_download_common.php` 로 **글자 그대로 옮겼다**. zip 으로 받기(`files_zip.php`)가 같은 함수를 부른다 —
 *   받기 입구가 둘이어도 판정·추가 거부·감사 행은 한 벌이다(새 판정 0). 이 파일에 남은 것은
 *   「거부 사유 → 이 창구의 응답」 표와 allow 기록·전송뿐이고, 응답·감사 행은 전과 같다.
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

require_once __DIR__ . '/_download_common.php';

$publicId = $params[0] ?? '';

// 라우터는 `[0-9a-f-]{36}` 까지만 좁힌다. UUIDv4 형태 재검사는 여기서 한다
// (형태가 아니면 DB 캐스팅 오류가 아니라 404 로 끝낸다).
if (!is_string($publicId) || !Storage::isValidUuid($publicId)) {
    Http::error(404, Http::MSG_NOT_FOUND);
}

$file = tbox_download_find($publicId);
if ($file === null) {
    Http::error(404, Http::MSG_NOT_FOUND);
}

/* ─── [권한 ✔] → [3-5] F-65 → [4] F-54 — 세 관문은 한 벌(_download_common.php) ───
 *
 * 거부면 관문이 `file.download deny <사유>` 를 이미 남겼다. 여기서는 사유마다 **이 창구의 응답**만 고른다.
 *   role_not_permitted · team_mismatch   403 MSG_FORBIDDEN (IDOR 와 같은 문구 — 존재 여부를 알리지 않는다)
 *   exec_content_token                   403 MSG_FORBIDDEN — 프로그램은 2단계 코드를 칠 수 없으므로 「다음에 무엇을 하라」가 없다 (API.md 9-1)
 *   exec_ack_required                    403 `{error: MSG_EXEC_ACK_REQUIRED, next:"exec_ack"}` — 여기까지 온 사람은 admin-only 를
 *                                        읽을 수 있는 Admin 이고, 받는 길(`POST …/exec-download`)이 따로 있다 (ARCH 5.29)
 *   scan_infected                        403 MSG_SCAN_INFECTED — 격리는 **영구** 거부
 *   scan_pending                         423 + `Retry-After` — 검사 중은 곧 풀리는 **일시** 상태 (F-57, ARCH 5.28)
 * 표에 없는 사유가 오면(있어서는 안 된다) 403 통일 문구로 닫는다.
 */
[$denyReason, $readCode] = tbox_download_gate($user, $file);
if ($denyReason !== null) {
    if ($denyReason === 'exec_ack_required') {
        Http::json(['error' => Http::MSG_EXEC_ACK_REQUIRED, 'next' => 'exec_ack'], 403);
        exit;
    }
    if ($denyReason === 'scan_infected') {
        Http::error(403, Http::MSG_SCAN_INFECTED);
    }
    if ($denyReason === 'scan_pending') {
        Http::locked(Http::SCAN_RETRY_AFTER_SECONDS, Http::MSG_SCAN_PENDING);
    }
    Http::error(403, Http::MSG_FORBIDDEN);
}

// 실제 바이트 확인 → sha256 대조 → 감사 로그 → 전송 (순서를 바꾸지 않는다)
[$integrityFail, $missingError] = tbox_download_integrity($file);
if ($integrityFail === 'file_missing' && $missingError !== null) {
    throw $missingError;   // bootstrap 예외 핸들러 → 500 + {"error":"서버 오류"} (전과 같다)
}
if ($integrityFail !== null) {
    Http::error(500, '서버 오류');   // hash_mismatch — 응답은 bootstrap 예외 경로와 같은 500 (R-13)
}

Audit::allow('file.download', [
    'target_type'  => 'file',
    'target_id'    => (int) $file['id'],
    'target_name'  => (string) $file['original_name'],
    'access_level' => (string) $file['access_level'],
    // 허용에도 근거를 남긴다 — granted_by 가 없으면 "왜 허용됐나" 를 조사할 수 없다 (PRD S-07)
    'detail'       => array_merge(['granted_by' => $readCode], tbox_download_audit_what($file)),
]);

Storage::stream((string) $file['stored_name'], (string) $file['original_name'], (string) $file['mime']);
