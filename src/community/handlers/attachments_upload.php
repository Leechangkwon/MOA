<?php
declare(strict_types=1);

/**
 * src/community/handlers/attachments_upload.php — POST /community/api/pages/{id}/attachments (multipart · 정책: community.write · CSRF)
 *
 * 요구사항: 사이트 Q:F-12 U3-1 · WK-25 · WK-27, 커뮤니티 ARCHITECTURE 24.1 ~ 24.6, 기획 work-ux-plan.md 15.4 C,
 *           지시서 docs/qf12-u3-directive.md 「U3-1 보안 바닥」.
 *
 * 문서(페이지) 하나에 딸린 파일을 올린다. 자료실(`public.files`)과 **섞지 않는다** — 자료실 목록 · 검색 · 공유 링크 · 등급에 나오지 않는다.
 *
 * 검사 순서 (자료실 업로드 `_upload_common.php` 와 같은 순서 · 같은 함수 — 규칙을 두 벌로 쓰지 않는다)
 *   [1] 문서 행(보는 사람 기준) → 없으면 404
 *   [권한 ✔] `CommunityAuthz::decideAttachmentUpload` = 그 문서의 고치기 판정(+ 쓰기 자격 역할). 못 고치면
 *            읽을 수는 있는 사람 403 · 읽지도 못하는 사람 404(존재 노출 0). 저장소 · 파일 내용에 손대기 **전**이다.
 *   [2] 파일 필드 · 원래 이름 정리(basename · UTF-8 · 제어문자 · 255자 — DB 에만 들어가고 경로에는 한 글자도 안 쓴다)
 *   [3] `Upload::validate` — 허용 확장자 · 이중 확장자 · MIME 짝 · 100MB · F-53 속 검사(압축 폭탄 · 매크로 · PDF 능동 내용 · HWP)
 *   [4] 일일 총량 `Upload::checkDailyQuota` — 자료실 + 첨부 **합산**(합산 SQL 은 그 함수 한 곳)
 *   [5] 민감정보 `Sensitive::scan` — 문서 넓이가 「사내 · 게시됨」(`community_attachment_wide`)이면 거부 `sensitive_level_required`,
 *       「비공개 · 팀」이면 저장하고 `sensitive_flags` 에 패턴 **이름만**(원문 · 개수 원문 0 — 감사 detail 은 자료실과 같은 이름 → 건수)
 *   [6] 트랜잭션: 문서 행 잠금(`COMMUNITY_PAGE_LOCK_SQL` — 넓히기 · 게시와 한 줄로 선다) → 잠근 뒤 판정 · 넓이 **다시** →
 *       INSERT → 디스크로 옮기기(`STORAGE_DIR/attachments/<uuid 앞 2>/<uuid>.<ext>` 0640 — 이름은 서버 난수) → 감사 allow → COMMIT.
 *       하나라도 실패하면 되돌리고 옮긴 파일도 지운다.
 *   응답 201 {id, name, size, image, scan_status} — 검사를 켜 두었으면 scan_status 'pending'(받기 · raw 는 clean 이 된 뒤에만).
 *
 * 거부는 전부 `attachment.upload deny refused`(detail.cause = 사유 코드 원문 · 문서 제목) 한 행을 남긴다.
 * 400 의 문장은 자료실과 같은 표(`Http::reasonMessage` — F-68)이고 응답에 사유 코드(`reason`)를 함께 싣는다.
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자 — uploaded_by 는 이 값만 쓴다(요청의 사용자 id · role 0)
 * @var list<string>        $params [0] = 문서 public_id
 */

require_once __DIR__ . '/../../Sensitive.php';

$me  = (int) $user['id'];
$pid = (string) $params[0];

// ─── [1] 문서 행 ─────────────────────────────────────────────
$row = community_page_row($pid, $me);
if ($row === null) {
    Http::error(404, Http::MSG_NOT_FOUND);
}
$pageId    = (int) $row['id'];
$pageTitle = community_target_title((string) $row['title']);

/**
 * 거부 — 감사 한 행을 남기고 응답한 뒤 끝낸다(돌아오지 않는다). 열린 트랜잭션이 있으면 **먼저** 되돌린다
 * (되돌리지 않고 끝내면 감사 행도 함께 사라진다).
 *
 * @param array<string,mixed> $detail
 */
$deny = static function (string $cause, int $status, ?string $name, array $detail = []) use ($pageId, $pageTitle): void {
    $db = Db::instance();
    if ($db->inTransaction()) {
        $db->rollback();
    }
    Audit::deny('attachment.upload', 'refused', [
        'target_type' => 'page_attachment',
        'target_name' => $name,
        'detail'      => array_merge(['page_title' => $pageTitle, 'page_id' => $pageId, 'cause' => $cause], $detail),
    ]);
    if ($status === 404) {
        Http::error(404, Http::MSG_NOT_FOUND);
    }
    if ($status === 403) {
        Http::error(403, Http::MSG_FORBIDDEN);
    }
    Http::json(['error' => Http::reasonMessage($cause), 'reason' => $cause], 400);
    exit;
};

/** 판정 거부의 상태 — 읽을 수는 있으면 403, 읽지도 못하면 404(문서가 있다는 사실도 알리지 않는다) */
$refuseStatus = static function (array $pageRow) use ($user): int {
    return CommunityAuthz::decidePageRead($user, $pageRow)[0] ? 403 : 404;
};

// ─── [권한 ✔] 그 문서를 고칠 수 있나 ───────────────────────────
[$mayUpload, $uploadCode] = CommunityAuthz::decideAttachmentUpload($user, $row);
if (!$mayUpload) {
    $deny($uploadCode, $refuseStatus($row), null);
}

// ─── [2] 파일 필드 · 원래 이름 (DB 전용 값 — 자료실과 같은 정리) ────
$uploaded = $_FILES['file'] ?? null;
if (!is_array($uploaded)) {
    $deny('no_file', 400, null);
}
$rawName  = $uploaded['name'] ?? '';
$original = is_string($rawName) ? $rawName : '';
$original = basename(str_replace('\\', '/', $original));
if ($original !== '' && preg_match('//u', $original) !== 1) {
    $deny('bad_encoding', 400, null);
}
$original = trim((string) preg_replace('/[\x00-\x1f\x7f]+/u', '', $original));
if ($original !== '' && iconv_strlen($original, 'UTF-8') > 255) {
    $original = (string) iconv_substr($original, 0, 255, 'UTF-8');
}
if ($original === '') {
    $original = 'file';
}

// ─── [3] 형식 검사 — 자료실과 같은 함수 ─────────────────────────
try {
    $checked = Upload::validate($uploaded);
} catch (RuntimeException $e) {
    // 예외 메시지는 사유 코드다(UploadBlockedEntries 도 `zip_blocked_entry` 하나 — 첨부에는 F-65 확인 갈래가 없다).
    $deny($e->getMessage(), 400, $original,
        $e->getMessage() === 'mime_mismatch' && Upload::$lastDetectedMime !== null ? ['mime' => Upload::$lastDetectedMime] : []);
    throw $e;   // 도달하지 않는다
}

// ─── [3-1] 익명게시판 그림 — 메타데이터(EXIF · GPS · 카메라)를 지운다 (사이트 Q:F-12 카페 C1 · ARCH 25.4) ───
/* 「추억」 사진과 **같은 함수**(`Memories::stripPhotoMetadata` — 무손실 조각 거르기, 안 되면 다시 인코딩)로 저장 전에 지운다.
   크기 · 해시는 지운 뒤의 바이트로 다시 잰다(총량 · 감사 · 행이 저장된 파일과 같게). 익명게시판 글이 아니면 전처럼 그대로. */
if (community_page_is_anon($row) && in_array($checked['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)) {
    try {
        Memories::stripPhotoMetadata((string) $uploaded['tmp_name'], $checked['mime']);
    } catch (RuntimeException $e) {
        $deny('image_metadata', 400, $original);
    }
    clearstatcache(true, (string) $uploaded['tmp_name']);
    $stripped = filesize((string) $uploaded['tmp_name']);
    $strippedHash = hash_file('sha256', (string) $uploaded['tmp_name']);
    if ($stripped === false || $stripped < 1 || $strippedHash === false) {
        $deny('image_metadata', 400, $original);
    }
    $checked['size']   = (int) $stripped;
    $checked['sha256'] = (string) $strippedHash;
}

// ─── [4] 일일 총량 — 자료실 + 첨부 합산 ─────────────────────────
try {
    Upload::checkDailyQuota($me, $checked['size']);
} catch (RuntimeException $e) {
    $deny($e->getMessage(), 400, $original, ['size_bytes' => $checked['size']]);
    throw $e;   // 도달하지 않는다
}

// ─── [5] 민감정보 — 이름 → 건수만(원문 0) ───────────────────────
$tmpPath   = (string) $uploaded['tmp_name'];
$sensitive = Sensitive::scan($tmpPath, $checked['ext']);
$flags     = array_keys($sensitive);
$refuseSensitive = static function () use ($deny, $tmpPath, $original, $sensitive): void {
    if (is_uploaded_file($tmpPath)) {
        @unlink($tmpPath);
    }
    $deny('sensitive_level_required', 400, $original, ['sensitive' => $sensitive]);
};
if ($sensitive !== [] && community_attachment_wide($row)) {
    $refuseSensitive();
}

// ─── [6] 트랜잭션: 잠금 → 다시 판정 → INSERT → 디스크 → 감사 → COMMIT ─────
$storedName = Storage::newStoredName($checked['ext']);
$scanStatus = Upload::initialScanStatus();
$sqlInsert  = <<<'SQL'
    INSERT INTO community.page_attachments
        (page_id, uploaded_by, original_name, ext, mime, size_bytes, sha256, stored_name, scan_status, sensitive_flags)
    VALUES
        (CAST(:page AS bigint), CAST(:by AS bigint), :name, :ext, :mime, CAST(:size AS bigint), :sha, :stored, :scan,
         CAST(:flags AS text[]))
    RETURNING id, public_id
    SQL;

$db    = Db::instance();
$moved = false;
$db->begin();
try {
    /* 문서 행을 잠그고 **잠근 뒤의 행**으로 판정 · 넓이를 다시 본다 — 그 사이 휴지통 · 인계 · 공유 회수 · 넓히기 · 게시가 있었으면
       트랜잭션 전과 같은 답으로 끝난다. 넓히기 · 게시 창구도 같은 행을 먼저 잠그므로 「민감 첨부가 든 사내 문서」가 겹침으로 생기지 않는다. */
    $db->run(COMMUNITY_PAGE_LOCK_SQL, [':pid' => $pageId]);
    $fresh = community_page_row($pid, $me);
    if ($fresh === null || (int) $fresh['id'] !== $pageId) {
        $deny(CommunityAuthz::CODE_NOT_FOUND, 404, $original);
    }
    [$stillMay, $stillCode] = CommunityAuthz::decideAttachmentUpload($user, $fresh);
    if (!$stillMay) {
        $deny($stillCode, $refuseStatus($fresh), $original);
    }
    if ($sensitive !== [] && community_attachment_wide($fresh)) {
        $refuseSensitive();
    }

    $ins = $db->run($sqlInsert, [
        ':page'   => $pageId,
        ':by'     => $me,
        ':name'   => $original,
        ':ext'    => $checked['ext'],
        ':mime'   => $checked['mime'],
        ':size'   => $checked['size'],
        ':sha'    => $checked['sha256'],
        ':stored' => $storedName,
        ':scan'   => $scanStatus,
        // 패턴 이름 배열(`{rrn,card}`) — 값은 Sensitive 의 고정 식별자뿐이지만 그래도 바인딩해 CAST 한다(자료실과 같다)
        ':flags'  => '{' . implode(',', $flags) . '}',
    ])->fetch();
    if (!is_array($ins)) {
        throw new RuntimeException('첨부 행을 만들 수 없습니다');
    }

    Storage::put($tmpPath, $storedName, Storage::AREA_ATTACHMENTS);
    $moved = true;

    Audit::allow('attachment.upload', [
        'target_type' => 'page_attachment',
        'target_id'   => (int) $ins['id'],
        'target_name' => $original,
        // 카페 C1 (ARCH 25.6) — 익명게시판 글이면 감사 화면이 행위자를 가리는 표식
        'detail'      => community_anon_audit_detail($fresh, [
            'granted_by'  => $stillCode,
            'page_title'  => $pageTitle,
            'page_id'     => $pageId,
            'ext'         => $checked['ext'],
            'mime'        => $checked['mime'],
            'size_bytes'  => $checked['size'],
            'sha256'      => $checked['sha256'],
            'scan_status' => $scanStatus,
        ] + ($sensitive === [] ? [] : ['sensitive' => $sensitive])),
    ]);

    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollback();
    }
    if ($moved) {
        try {
            Storage::removeUploaded($storedName, Storage::AREA_ATTACHMENTS);   // DB 에 없는 파일(고아)을 두지 않는다
        } catch (Throwable $ignored) {
            error_log('[tbox] request_id=' . TBOX_REQUEST_ID . ' 첨부 업로드 롤백 중 파일 삭제 실패');
        }
    }
    throw $e;   // bootstrap 예외 핸들러 → 500 + {"error":"서버 오류"}
}

Http::json([
    'id'          => (string) $ins['public_id'],
    'name'        => $original,
    'size'        => (int) $checked['size'],
    'image'       => in_array($checked['mime'], COMMUNITY_ATTACHMENT_IMAGE_MIMES, true),
    'scan_status' => $scanStatus,
], 201);
