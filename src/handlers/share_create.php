<?php
declare(strict_types=1);

/**
 * src/handlers/share_create.php — POST /api/files/{id}/share (정책: file.update)
 *
 * ★ 15-2: 만료되는 공유 링크 만들기 (PRD F-13 · Q-5 기본안, 8장 API 표, ARCH 5.12 「만들기」).
 *
 * 왜 정책이 `file.update` 인가 — 링크를 만들 수 있는 사람은 **그 파일을 고칠 수 있는 사람**이다
 * (PRD F-13). 라우터는 "고칠 수 있는 종류의 역할인가" 까지만 보고(Guest 는 여기서 끝),
 * 그 파일에 대한 판단은 아래에서 decideRead → decideEdit 로 다시 한다 (ARCH 5.7 과 같은 순서).
 *
 * 이 핸들러가 막는 것
 *   [1] **대량 할당(R-07)** — 허용 키는 `expires_hours`·`max_downloads`·`reason_text` 셋뿐이다.
 *       그 밖의 키가 하나라도 있으면 대상을 읽기도 전에 400 이다. `file_id`·`created_by`·
 *       `token_hash` 같은 컬럼은 요청으로 바뀌는 통로 자체가 없다.
 *   [권한 ✔ 1·2] 볼 수 없는 파일(decideRead) · 남의 파일(decideEdit)에 링크를 만드는 것.
 *       `admin-only` 파일의 링크는 결과적으로 Admin 만 만들 수 있다 — decideEdit 가 그렇게 귀결된다.
 *   [5] 무한·무제한 링크 — 만료 1~168시간(최대 7일)·횟수 1~10 을 앱이 보고, DB CHECK 가 한 번 더 본다.
 *
 * ★ 토큰은 `random_bytes(32)` 를 base64url 로 옮긴 43자다. DB 에는 **sha256 만** 넣는다
 *   (세션과 같은 방식, ARCH 6.2-2). 원문은 이 응답에만 존재하고 서버 어디에도 남지 않는다 —
 *   감사 로그의 detail 에도 토큰도 그 해시도 넣지 않는다 (PRD S-07).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자 (요청 값이 아니다 — PRD S-01)
 * @var list<string>        $params $params[0] = public_id
 */

$publicId = $params[0] ?? '';

// 라우터는 `[0-9a-f-]{36}` 까지만 좁힌다. UUIDv4 형태 재검사는 여기서 한다.
if (!is_string($publicId) || !Storage::isValidUuid($publicId)) {
    Http::error(404, Http::MSG_NOT_FOUND);
}

$db = Db::instance();

// ─── [1] 허용 키 화이트리스트 (SECURITY R-07, ARCH 5.12) ───────
// 파일 행을 **읽기 전에** 본문 형태부터 본다 — files_update.php [2] 와 같은 순서다.
// 그래서 이 거부 기록에는 대상이 없다(문장 틀에 {target} 이 없는 이유).
$allowedFields = ['expires_hours', 'max_downloads', 'reason_text'];

$body = Http::jsonBody();
if (array_diff(array_keys($body), $allowedFields) !== []) {
    Audit::deny('share.create', 'bad_request', [
        'target_type' => 'file',
        'target_name' => null,
    ]);
    Http::error(400, Http::MSG_BAD_REQUEST);
}

// ─── [2] 파일 행 조회 ──────────────────────────────────────────
$sqlFind = <<<'SQL'
    SELECT f.id,
           f.original_name,
           f.access_level,
           f.team_id,
           f.uploaded_by,
           f.sha256,
           f.size_bytes,
           f.exec_content
      FROM files f
     WHERE f.public_id = CAST(:pid AS uuid)
       AND f.deleted_at IS NULL
    SQL;

$file = $db->run($sqlFind, [':pid' => $publicId])->fetch();
if (!is_array($file)) {
    Http::error(404, Http::MSG_NOT_FOUND);
}

$fileId   = (int) $file['id'];
$fileName = (string) $file['original_name'];

// 감사 로그의 "무엇을" 칸 (ARCH 7.5). 사용자 응답에는 한 글자도 나가지 않는다.
$auditWhat = [
    'owner_id'    => (int) $file['uploaded_by'],
    'target_team' => $file['team_id'] === null ? null : (int) $file['team_id'],
    'sha256'      => (string) $file['sha256'],
    'size_bytes'  => (int) $file['size_bytes'],
];
$auditTarget = [
    'target_type'  => 'file',
    'target_id'    => $fileId,
    'target_name'  => $fileName,
    'access_level' => (string) $file['access_level'],
];

// ─── [권한 ✔ 1] 볼 수 없는 파일은 링크 판정까지 가지 않는다 ───
[$mayRead, $readCode] = Authz::decideRead($user, $file);
if (!$mayRead) {
    Audit::deny('share.create', $readCode, $auditTarget + ['detail' => $auditWhat]);
    Http::error(403, Http::MSG_FORBIDDEN);
}

// ─── [권한 ✔ 2] 소유자·팀 규칙 — 고칠 수 있는 사람만 (PRD F-13, 5.3) ───
[$mayEdit, $editCode] = Authz::decideEdit($user, $file);
if (!$mayEdit) {
    Audit::deny('share.create', $editCode, $auditTarget + ['detail' => $auditWhat]);
    Http::error(403, Http::MSG_FORBIDDEN);
}

/* ─── [권한 ✔ 3] F-65 실행 파일이 든 압축본은 링크로 나가지 않는다 (ARCH 5.29 「받기」 표) ───
 *
 * 공유 링크는 **로그인하지 않은 사람이 OTP 없이 받는 길**이다. 그 길을 열면 F-65 의 전제
 * (받는 사람은 언제나 Admin 이고 요청마다 2단계 코드를 친다)가 통째로 무너진다.
 * 자리는 `decideEdit` **뒤** — 그 파일을 고칠 수 없는 사람에게는 이 파일이 어떤 종류인지
 * 알려 주지 않는다(403 이 먼저 끝낸다). 응답은 400 `exec_content` 문장이다 (F-68, ARCH 5.30).
 */
if ($file['exec_content'] === true) {
    Audit::deny('share.create', 'exec_content', $auditTarget + ['detail' => $auditWhat]);
    Http::error(400, Http::reasonMessage('exec_content'));
}

// ─── [3] 값 검증 — 사유는 `bad_request` 하나라 문구도 「잘못된 요청입니다」 그대로다 ───
$reject = static function () use ($auditTarget, $auditWhat): void {
    Audit::deny('share.create', 'bad_request', $auditTarget + ['detail' => $auditWhat]);
    Http::error(400, Http::MSG_BAD_REQUEST);
};

/** 정수 항목 하나 — 문자열로 와도 받되 형태·범위를 벗어나면 거부한다(조용히 고치지 않는다) */
$intField = static function ($raw, int $min, int $max, int $default) use ($reject): int {
    if ($raw === null) {
        return $default;
    }
    $text = is_int($raw) ? (string) $raw : (is_string($raw) ? trim($raw) : '');
    if (preg_match('/^[0-9]{1,3}$/', $text) !== 1) {
        $reject();
    }
    $value = (int) $text;
    if ($value < $min || $value > $max) {
        $reject();
    }
    return $value;
};

// 만료 1~168시간(기본 24) · 횟수 1~10(기본 1) — PRD F-13, ARCH 5.12
$expiresHours = $intField($body['expires_hours'] ?? null, 1, 168, 24);
$maxDownloads = $intField($body['max_downloads'] ?? null, 1, 10, 1);

// 사유 — 선택. 500자·UTF-8·제어문자 제거 (다른 사유 입력과 같은 규칙)
$reasonText = null;
if (array_key_exists('reason_text', $body)) {
    $rawReason = $body['reason_text'];
    if (!is_string($rawReason)) {
        $reject();
    }
    if ($rawReason !== '' && preg_match('//u', $rawReason) !== 1) {
        $reject();
    }
    $clean = (string) preg_replace('/[\x00-\x1f\x7f]+/u', '', $rawReason);
    $clean = trim($clean);
    if (iconv_strlen($clean, 'UTF-8') > 500) {
        $reject();
    }
    $reasonText = $clean === '' ? null : $clean;
}

/*
 * ─── [4] 토큰 ────────────────────────────────────────────────
 * 32바이트 난수 → base64url(43자). 패딩(`=`)은 떼므로 라우터 자리표시자
 * `[A-Za-z0-9_-]{43}` 와 정확히 맞는다.
 *
 * DB 에는 sha256 만 넣는다. 원문은 아래 응답 한 번에만 나가고 그 뒤에는 누구도 알 수 없다 —
 * DB 가 통째로 유출돼도 링크를 되살릴 수 없다(세션 토큰과 같은 설계, ARCH 6.2-2).
 */
$shareToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
$tokenHash  = hash('sha256', $shareToken);

/*
 * 만료 시각은 **DB 가 계산한다**(`now() + make_interval(hours => :hours)`).
 * 앱 시계와 DB 시계가 조금만 어긋나도 `expires_at <= created_at + 7 days` CHECK 가
 * 168시간 경계에서 실패하기 때문이다. 같은 INSERT 안의 now() 는 하나뿐이라 항상 정확히 N시간이다.
 */
$sqlInsert = <<<'SQL'
    INSERT INTO share_links
        (token_hash, file_id, created_by, expires_at, max_downloads, reason_text)
    VALUES
        (:token_hash, :file_id, :created_by,
         now() + make_interval(hours => :hours), :max_downloads, :reason_text)
    RETURNING id, expires_at
    SQL;

$db->begin();
try {
    $row = $db->run($sqlInsert, [
        ':token_hash'    => $tokenHash,
        ':file_id'       => $fileId,
        ':created_by'    => (int) $user['id'],
        ':hours'         => $expiresHours,
        ':max_downloads' => $maxDownloads,
        ':reason_text'   => $reasonText,
    ])->fetch();

    if (!is_array($row)) {
        throw new RuntimeException('공유 링크 레코드를 만들 수 없습니다');
    }

    $shareId  = (int) $row['id'];
    $expires  = (string) $row['expires_at'];

    // 감사: 토큰도 그 해시도 넣지 않는다. 남기는 것은 "무엇을 얼마나 열어 줬나" 다.
    Audit::allow('share.create', $auditTarget + [
        'detail' => array_merge(
            [
                'granted_by'    => $editCode,   // Authz 가 돌려준 허용 코드 그대로 (ARCH 4.1)
                'share_id'      => $shareId,
                'expires_at'    => $expires,
                'max_downloads' => $maxDownloads,
                'reason_text'   => $reasonText,
            ],
            $auditWhat
        ),
    ]);

    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    throw $e;   // bootstrap 예외 핸들러 → 500 + {"error":"서버 오류"}
}

/*
 * ★ `url` 은 **이 응답에만** 존재한다 (PRD F-13). 다시 볼 수 없다는 안내는 화면이 한다.
 *   APP_URL 은 .env 의 고정 설정이며 요청 헤더(Host)에서 오지 않는다 — 요청이 정한 주소를
 *   그대로 링크로 돌려주면 그것이 곧 호스트 헤더 오염 경로다.
 */
Http::json([
    'id'            => $shareId,
    'url'           => Env::get('APP_URL') . '/s/' . $shareToken,
    'expires_at'    => $expires,
    'max_downloads' => $maxDownloads,
], 201);
