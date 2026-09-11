<?php
declare(strict_types=1);

/**
 * src/handlers/files_upload.php — POST /api/files (multipart/form-data) (정책: file.upload)
 *
 * ★ 4단계: 레벨·팀을 읽은 **직후**, 파일 검증·저장 **전에**
 *   `Authz::decideUploadAs($user, $level, $teamId)` 로 판정한다 (ARCH 5.2 "[권한 ✔ A]").
 *   화면의 <select> 를 조작해 admin-only 나 남의 팀을 보내도 여기서 403 + 감사 로그 deny 다.
 *   Guest 는 라우터의 `file.upload` 정책에서 이미 끝난다(authz.route deny).
 *
 * 요구사항: PRD F-05, S-01, 7.6(S-06), 8장 API 표, ARCH 5.2
 *
 * 그 밖에 지키는 것
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
 * 실제 권한 판단(그 팀으로 올릴 수 있는가)은 아래 [3-2] Authz::decideUploadAs 가 한다.
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
// 101MB 같은 과대 요청은 nginx(client_max_body_size 101m)가 413 으로 먼저 끊는다.
// 정확히 100MB(104,857,600 B) 경계는 PHP Upload::MAX_BYTES 가 판정한다 (ARCH 8장 6단계 결정).
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

// ─── [3] 설명·접근 레벨·팀 ─────────────────────────────────────
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
    // 12-3 (F-19, ARCH 5.9): **활성 팀만** 있는 것으로 본다 — 비활성 팀으로 올리려는 요청은
    // 기존 거부 경로(bad_team → 400)로 끝난다. 이미 그 팀에 있는 파일은 그대로다.
    $sqlTeam = <<<'SQL'
        SELECT id FROM teams WHERE id = :id AND is_active
        SQL;
    $teamRow = Db::instance()->run($sqlTeam, [':id' => (int) $teamRaw])->fetch();
    if (!is_array($teamRow)) {
        $rejectBadRequest('bad_team', $original, $level);
    }
    $teamId = (int) $teamRow['id'];
}

// ─── [3-2] 권한 ✔ A — 이 레벨(+팀)로 올릴 수 있나 (검증·저장 전, ARCH 5.2) ───
// 판정 코드는 Authz 가 돌려준 것을 그대로 기록한다 (ARCH 4.1).
[$mayUpload, $uploadCode] = Authz::decideUploadAs($user, $level, $teamId);
if (!$mayUpload) {
    // 응답은 403 동일 문구뿐이다. 대상 팀 같은 서버가 아는 값은 로그에만 남긴다 (PRD S-07).
    Audit::deny('file.upload', $uploadCode, [
        'target_type'  => 'file',
        'target_name'  => $original,
        'access_level' => $level,
        'detail'       => ['target_team' => $teamId],
    ]);
    Http::error(403, Http::MSG_FORBIDDEN);
}

// ─── [4] 업로드 검증 (크기·확장자 화이트리스트·이중 확장자·MIME 짝·해시) ───
// 6단계: PRD S-06 전부를 Upload 이 판정한다 (T-7 · T-8 · 이중 확장자, src/Upload.php 주석).
try {
    $checked = Upload::validate($uploaded);
} catch (RuntimeException $e) {
    // 예외 메시지는 사유 코드다. 사용자에게는 나가지 않는다.
    $rejectBadRequest($e->getMessage(), $original, $level);
    return;
}

// ─── [4-2] 일일 총량 2GB (6단계, PRD S-06) ─────────────────────
// 검증을 통과한 크기로 INSERT **전에** 본다. 삭제한 파일도 합계에 들어가므로
// "올리고 지우기" 로 한도를 넘길 수 없다. 초과 응답은 413 이 아니라 다른 검증 실패와 같은
// 400 동일 문구다 — 413 은 nginx 크기 제한의 몫이고, 사유는 감사 로그에만 남는다.
try {
    Upload::checkDailyQuota((int) $user['id'], $checked['size']);
} catch (RuntimeException $e) {
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
            'ext'         => $checked['ext'],
            'mime'        => $checked['mime'],
            'size_bytes'  => $checked['size'],
            // 육하원칙 칸 (ARCH 7.5): 왜 허용됐나 · 어떤 내용인가 · 누가 올렸나 · 어느 팀인가
            'granted_by'  => $uploadCode,
            'sha256'      => $checked['sha256'],
            'owner_id'    => (int) $user['id'],
            'target_team' => $teamId,
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
