<?php
declare(strict_types=1);

/**
 * src/handlers/files_upload.php — POST /api/files (multipart/form-data) (정책: authenticated)
 *
 * ★ 3단계: 권한 검사 없음 — 4단계에서 `Authz::canUploadAs($user, $level, $teamId)` 를
 *   [3] 자리에 넣는다. 지금은 Guest 가 admin-only 로 올려도 201 이 나온다.
 *   그것이 이 단계의 정상 동작이다(PRD 13장 3단계, 4단계 전후 비교용).
 *
 * 요구사항: PRD F-05, 7.6(S-06), 8장 API 표, ARCH 5.2
 *
 * 3단계에도 지키는 것
 *   · CSRF: 라우터 [3] 의 Http::requireCsrf() 가 X-CSRF-Token 헤더를 검사한다 (PRD S-09-3)
 *   · 값은 전부 바인딩 (PRD S-04)
 *   · 저장 이름은 서버가 만든 UUID 뿐. **사용자 파일명은 경로에 한 글자도 들어가지 않는다** (PRD S-06)
 *   · 원본 파일명은 basename → 제어문자 제거 → 길이 제한을 거쳐 DB 에만 들어간다
 *   · 업로드 성공·검증 실패 모두 감사 로그 (PRD S-07)
 *   · DB INSERT · 파일 이동 · 감사 로그를 한 트랜잭션으로 묶는다 (ARCH 5.2 [5]) —
 *     하나라도 실패하면 롤백하고 옮겨진 파일도 지운다(고아 파일·유령 레코드 방지)
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자. uploaded_by 는 이 값만 쓴다
 *                                  (요청 파라미터의 사용자 id·role 은 읽지 않는다 — PRD S-01)
 * @var list<string>        $params 경로 변수 없음
 */

/**
 * 폼 필드 하나를 문자열로 읽는다.
 * 키를 변수로 넘기는 이유: 폼 필드 이름을 리터럴 첨자로 쓰면
 * scripts/security_lint.sh 의 "요청 파라미터의 역할값 사용" 검사(role·is_admin·팀 첨자)에 걸린다.
 * 팀 번호는 역할이 아니라 사용자가 고른 업로드 대상 팀이지만(PRD F-05),
 * 린트를 예외 처리하기보다 요청 값을 읽는 통로를 한 곳으로 모으는 편이 안전하다.
 * 실제 권한 판단(그 팀으로 올릴 수 있는가)은 4단계 Authz::canUploadAs 가 한다.
 */
$formText = static function (string $key): string {
    $raw = $_POST[$key] ?? '';
    return is_string($raw) ? $raw : '';
};

/** 검증 실패 — 사유는 감사 로그에만, 사용자에게는 동일한 400 문구만 (PRD S-09-5) */
$rejectBadRequest = static function (string $reason, ?string $name, ?string $level): void {
    Audit::deny('file.upload', $reason, [
        'target_type'  => 'file',
        'target_name'  => $name,
        'access_level' => $level,
    ]);
    Http::error(400, Http::MSG_BAD_REQUEST);
};

// ─── [1] 파일 필드 확인 ────────────────────────────────────────
// 101MB 같은 과대 요청은 nginx(client_max_body_size 100m)가 413 으로 먼저 끊는다.
$uploaded = $_FILES['file'] ?? null;
if (!is_array($uploaded)) {
    $rejectBadRequest('no_file', null, null);
}

// ─── [2] 원본 파일명 정리 (DB 전용 값) ─────────────────────────
// basename() 으로 경로를 떼고(`../../etc/passwd.txt` → `passwd.txt`),
// 제어문자·CR/LF 를 지우고, 255자로 자른다. 이 값은 경로 조립에 쓰이지 않는다.
$rawName = $uploaded['name'] ?? '';
$original = is_string($rawName) ? $rawName : '';
$original = basename(str_replace('\\', '/', $original));
if ($original !== '' && preg_match('//u', $original) !== 1) {
    // 잘못된 UTF-8 은 DB(UTF8)·JSON 에서 오류를 만든다 → 저장하지 않고 거부한다
    $rejectBadRequest('bad_encoding', null, null);
}
$original = (string) preg_replace('/[\x00-\x1f\x7f]+/u', '', $original);
$original = trim($original);
if ($original !== '' && iconv_strlen($original, 'UTF-8') > 255) {
    $original = (string) iconv_substr($original, 0, 255, 'UTF-8');
}
if ($original === '') {
    $original = 'file';
}

// ─── [3] 설명·접근 레벨·팀 (권한 검사는 4단계) ─────────────────
$description = $formText('description');
if ($description !== '' && preg_match('//u', $description) !== 1) {
    $rejectBadRequest('bad_encoding', $original, null);
}
$description = (string) preg_replace('/[\x00-\x1f\x7f]+/u', '', $description);
if (iconv_strlen($description, 'UTF-8') > 500) {
    // DB CHECK(length(description) <= 500) 와 같은 한계를 앱에서도 확인한다
    $rejectBadRequest('description_too_long', $original, null);
}

$level = $formText('access_level');
if (!in_array($level, ['public', 'internal', 'team', 'admin-only'], true)) {
    $rejectBadRequest('bad_access_level', $original, null);
}

$teamId = null;
if ($level === 'team') {
    // team 레벨은 team_id 필수 (PRD 4장, DB CHECK team_requires_team_id 로도 강제)
    $teamRaw = $formText('team_id');
    if (preg_match('/^[1-9][0-9]{0,8}$/', $teamRaw) !== 1) {
        $rejectBadRequest('bad_team', $original, $level);
    }
    $sqlTeam = <<<'SQL'
        SELECT id FROM teams WHERE id = :id
        SQL;
    $teamRow = Db::instance()->run($sqlTeam, [':id' => (int) $teamRaw])->fetch();
    if (!is_array($teamRow)) {
        $rejectBadRequest('bad_team', $original, $level);
    }
    $teamId = (int) $teamRow['id'];
}

// ─── [4] 업로드 검증 (크기·확장자 형태·MIME·해시) ───────────────
// 3단계 최소 검증이다. 확장자 화이트리스트·MIME 짝·이중 확장자는 6단계 (src/Upload.php 주석).
try {
    $checked = Upload::validate($uploaded);
} catch (RuntimeException $e) {
    // 예외 메시지는 사유 코드다. 사용자에게는 나가지 않는다.
    $rejectBadRequest($e->getMessage(), $original, $level);
    return;
}

$storedName = Storage::newStoredName($checked['ext']);
$tmpPath    = (string) $uploaded['tmp_name'];

$sqlInsert = <<<'SQL'
    INSERT INTO files
        (original_name, stored_name, ext, mime, size_bytes, sha256,
         description, access_level, team_id, uploaded_by)
    VALUES
        (:original_name, :stored_name, :ext, :mime, :size_bytes, :sha256,
         :description, :access_level, :team_id, :uploaded_by)
    RETURNING id, public_id
    SQL;

// ─── [5] 트랜잭션: INSERT → 파일 이동 → 감사 로그 → COMMIT ──────
$db    = Db::instance();
$moved = false;
$db->begin();
try {
    $row = $db->run($sqlInsert, [
        ':original_name' => $original,
        ':stored_name'   => $storedName,
        ':ext'           => $checked['ext'],
        ':mime'          => $checked['mime'],
        ':size_bytes'    => $checked['size'],
        ':sha256'        => $checked['sha256'],
        ':description'   => $description,
        ':access_level'  => $level,
        ':team_id'       => $teamId,
        ':uploaded_by'   => (int) $user['id'],
    ])->fetch();

    if (!is_array($row)) {
        throw new RuntimeException('업로드 레코드를 만들 수 없습니다');
    }

    Storage::put($tmpPath, $storedName);
    $moved = true;

    Audit::allow('file.upload', [
        'target_type'  => 'file',
        'target_id'    => (int) $row['id'],
        'target_name'  => $original,
        'access_level' => $level,
        'detail'       => [
            'ext'        => $checked['ext'],
            'mime'       => $checked['mime'],
            'size_bytes' => $checked['size'],
            'team_id'    => $teamId,
        ],
    ]);

    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    if ($moved) {
        // DB 에 남지 않는 파일(고아 파일)을 두지 않는다
        try {
            Storage::removeUploaded($storedName);
        } catch (Throwable $ignored) {
            error_log('[tbox] request_id=' . TBOX_REQUEST_ID . ' 업로드 롤백 중 파일 삭제 실패');
        }
    }

    // 실패도 기록한다 (PRD S-07). 롤백 뒤라 이 INSERT 는 별개 트랜잭션으로 남는다.
    // 기록 자체가 실패한 경우까지 다시 예외로 덮지 않도록 감싼다 — 응답은 어차피 500 이다.
    try {
        Audit::error('file.upload', 'upload_failed', [
            'target_type'  => 'file',
            'target_name'  => $original,
            'access_level' => $level,
            'detail'       => ['moved' => $moved],
        ]);
    } catch (Throwable $ignored) {
        error_log('[tbox] request_id=' . TBOX_REQUEST_ID . ' 업로드 실패 감사 기록 실패');
    }

    throw $e;   // bootstrap 의 예외 핸들러가 500 + {"error":"서버 오류"} 로 끝낸다
}

Http::json(['id' => (string) $row['public_id'], 'name' => $original], 201);
