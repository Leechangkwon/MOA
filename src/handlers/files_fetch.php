<?php
declare(strict_types=1);

/**
 * src/handlers/files_fetch.php — POST /api/files/fetch (정책: file.fetch)
 *
 * ★ 7단계: URL 로 파일 가져오기 (PRD F-14 · S-05 ①~⑬, ARCH 5.6 흐름 그대로).
 *
 * 옛 t-box 의 `fetch_url.php` 는 받은 주소를 그대로 여는 한 줄이었고, 그것이 SSRF 였다.
 * 이번 구현의 요지는 세 가지다.
 *   ① 갈 수 있는 곳을 미리 정해 둔다 — 허용 호스트 목록 정확 일치 (Fetch::check)
 *   ② 검사한 곳과 실제 가는 곳이 같다 — 검사한 IP 를 CURLOPT_RESOLVE 로 고정 (Fetch::download)
 *   ③ 가져온 것을 업로드와 똑같이 검사한다 — **같은** Upload::validatePath()
 *
 * 흐름 (ARCH 5.6)
 *   [0] 라우터: 로그인 → CSRF → 정책 file.fetch(Manager 이상). Employee·Guest 는 여기 오지 못한다
 *   [1] 본문 형태 — 허용 키 4개(url·description·access_level·team_id) 외에는 읽지도 않는다 (R-07)
 *   [2] 속도 제한 — 사용자별 1시간 10회 (S-05 ⑪). allow·deny 를 **모두** 세므로 거부를 반복해도 막힌다
 *   [3] 등급·팀 판정 — 업로드와 같은 Authz::decideUploadAs (403)
 *   [4] URL 검사 — Fetch::check (S-05 ①②③④⑤)
 *   [5] 내려받기 — Fetch::download (S-05 ⑥⑦⑧), 임시파일은 어떤 경로로 끝나도 지운다
 *   [6] 파일 검증 — Upload::validatePath + 일일 총량 (S-05 ⑨)
 *   [6-2] 민감정보 검출 — 업로드(5.2)와 **같은 함수**를 같은 자리에서 (15-3, F-22, ARCH 5.13)
 *   [7] 저장 — files_upload.php [5] 와 같은 트랜잭션 (INSERT → putLocal → Audit → COMMIT)
 *
 * 실패 응답은 **언제나 {"error":"가져올 수 없습니다"} 하나**이고 상태 코드만 400/403/429 로 갈린다
 * (PRD S-05 ⑩). 외부 응답의 본문·헤더·상태코드·오류 문구는 사용자에게 한 글자도 나가지 않는다 —
 * 사유는 감사 로그와 error_log 에만 남는다. 로그에도 **URL 전체가 아니라 host 만** 남긴다 (ARCH 7.5).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자 (요청 값이 아니다 — PRD S-01)
 * @var list<string>        $params 경로 변수 없음
 */

/** 감사 로그에 남길 출처 — 정규화된 host(모르면 '?')와 고정한 IP (ARCH 7.5 source_host·source_ip) */
$sourceHost = '?';
$sourceIp   = null;

/*
 * F-43 — 클라우드 공개 링크 변환 (PRD F-43, ARCH 5.22).
 *   $provider   CloudLink 가 판정한 공급자 (google·microsoft·null)
 *   $sourceUrl  감사 로그에 남길 「원 주소 → 바꾼 주소」. 변환이 없었으면 원 주소 하나뿐이다
 *   $hops       리다이렉트로 지나온 호스트 목록 (Fetch::download 가 채운다)
 */
require_once __DIR__ . '/../CloudLink.php';

$provider  = null;
$sourceUrl = '';
$hops      = [];
/* F-46 — 리다이렉트 단계 중 **쿠키를 실은 단계 수**. 쿠키 값은 어디에도 남기지 않는다
   (SECURITY R-77 보충). 0 이면 한 단계도 쿠키 없이 왔다는 뜻이다. */
$cookieHops = 0;

/** 감사 로그 detail 에 넣을 주소 — 512바이트로 자른다(로그 한 줄이 주소로 채워지지 않게) */
$shortUrl = static function (string $url): string {
    return strlen($url) > 512 ? substr($url, 0, 512) : $url;
};

/**
 * 가져오기 실패 — 사유는 감사 로그에만, 사용자에게는 상태 코드만 다른 같은 문구.
 *
 * @param array<string,mixed> $detail
 */
$rejectFetch = static function (
    int $status,
    string $reason,
    ?string $name,
    ?string $level,
    array $detail = []
): void {
    Audit::deny('file.fetch', $reason, [
        'target_type'  => 'file',
        'target_name'  => $name,
        'access_level' => $level,
        'detail'       => $detail,
    ]);
    Http::error($status, Http::MSG_FETCH_FAILED);
};

// ─── [1] 본문 — 허용 키만 (대량 할당 방지, SECURITY R-07) ──────
$allowedFields = ['url', 'description', 'access_level', 'team_id', 'folder_id'];

$body = Http::jsonBody();
if ($body === [] || array_diff(array_keys($body), $allowedFields) !== []) {
    $rejectFetch(400, 'bad_request', null, null);
}

$rawUrl = $body['url'] ?? null;
if (!is_string($rawUrl) || trim($rawUrl) === '') {
    $rejectFetch(400, 'bad_request', null, null);
}
$requestedUrl = trim((string) $rawUrl);

// ─── [2] 속도 제한 — 사용자별 1시간 10회 (PRD S-05 ⑪) ──────────
// allow 와 deny 를 모두 센다: 거부당하는 요청을 반복하는 것이 곧 탐색 행위이므로
// "실패는 세지 않는다" 면 제한이 없는 것과 같다.
$sqlRate = <<<'SQL'
    SELECT count(*) AS tries
      FROM audit_logs
     WHERE actor_id = :uid
       AND action = 'file.fetch'
       AND happened_at > now() - interval '1 hour'
    SQL;
$rateRow  = Db::instance()->run($sqlRate, [':uid' => (int) $user['id']])->fetch();
$rateUsed = is_array($rateRow) ? (int) $rateRow['tries'] : 0;
if ($rateUsed >= 10) {
    $rejectFetch(429, 'rate_limited', null, null);
}

// ─── [3] 설명·접근 등급·팀 (업로드와 같은 규칙) ────────────────
$rawDescription = $body['description'] ?? '';
if (!is_string($rawDescription)) {
    $rejectFetch(400, 'bad_request', null, null);
}
$description = (string) $rawDescription;
if ($description !== '' && preg_match('//u', $description) !== 1) {
    $rejectFetch(400, 'bad_request', null, null);
}
$description = (string) preg_replace('/[\x00-\x1f\x7f]+/u', '', $description);
if (iconv_strlen($description, 'UTF-8') > 500) {
    $rejectFetch(400, 'bad_request', null, null);
}

$rawLevel = $body['access_level'] ?? '';
$level    = is_string($rawLevel) ? $rawLevel : '';
if (!in_array($level, ['public', 'internal', 'team', 'admin-only'], true)) {
    $rejectFetch(400, 'bad_request', null, null);
}

$teamId = null;
if ($level === 'team') {
    $rawTeam = $body['team_id'] ?? '';
    $teamRaw = is_int($rawTeam) ? (string) $rawTeam : (is_string($rawTeam) ? $rawTeam : '');
    if (preg_match('/^[1-9][0-9]{0,8}$/', $teamRaw) !== 1) {
        $rejectFetch(400, 'bad_request', null, $level);
    }
    // 12-3 (F-19, ARCH 5.9): **활성 팀만** 있는 것으로 본다 — 비활성 팀으로 가져오려는
    // 요청은 기존 거부 경로(bad_request → 400)로 끝난다.
    $sqlTeam = <<<'SQL'
        SELECT id FROM teams WHERE id = :id AND is_active
        SQL;
    $teamRow = Db::instance()->run($sqlTeam, [':id' => (int) $teamRaw])->fetch();
    if (!is_array($teamRow)) {
        $rejectFetch(400, 'bad_request', null, $level);
    }
    $teamId = (int) $teamRow['id'];
}

/* ─── [3-2] 폴더 (18-1, PRD F-26, ARCH 5.16 "파일 쪽 변경") ───
 *
 * 업로드(files_upload [3-3][3-4])와 **같은 규칙·같은 함수**다: 폴더가 있고, 읽을 수 있고,
 * 가져온 파일이 그 폴더보다 넓지 않아야 한다. 한쪽 입구만 검사가 빠지는 일이 없도록
 * 두 핸들러가 같은 자리에서 같은 Authz 함수를 부른다 (ARCH 5.6 [8] 과 같은 원칙).
 * 실패 응답은 다른 fetch 실패와 똑같은 문구 하나뿐이고 사유는 감사 로그에만 남는다 (S-05 ⑩).
 */
$sqlFolder = <<<'SQL'
    SELECT id, name, access_level, team_id
      FROM folders
     WHERE id = CAST(:id AS bigint)
    SQL;

$folderId  = null;
$folderRow = null;
if (array_key_exists('folder_id', $body) && $body['folder_id'] !== null) {
    $rawFolder  = $body['folder_id'];
    $folderText = is_int($rawFolder) ? (string) $rawFolder : (is_string($rawFolder) ? $rawFolder : '');
    if (preg_match('/^[1-9][0-9]{0,8}$/', $folderText) !== 1) {
        $rejectFetch(400, 'bad_folder', null, $level);
    }
    $folderRow = Db::instance()->run($sqlFolder, [':id' => (int) $folderText])->fetch();
    if (!is_array($folderRow)) {
        $rejectFetch(400, 'bad_folder', null, $level);
    }
    if (!Authz::decideRead($user, $folderRow)[0]) {
        $rejectFetch(400, 'bad_folder', null, $level);
    }
    [$fits, $fitCode] = Authz::fitsUnderFolder($level, $teamId, $folderRow);
    if (!$fits) {
        $rejectFetch(400, $fitCode, null, $level, ['folder_id' => (int) $folderRow['id']]);
    }
    $folderId = (int) $folderRow['id'];
}

// ─── [3-2] 권한 ✔ — 이 등급(+팀)으로 올릴 수 있나 (업로드와 같은 판정) ───
// 파일명을 아직 모르므로 문장 틀의 {target} 은 '?' 로 남는다 — 아직 밖으로 나가지 않았기 때문이다.
[$mayUpload, $uploadCode] = Authz::decideUploadAs($user, $level, $teamId);
if (!$mayUpload) {
    $rejectFetch(403, $uploadCode, null, $level, ['target_team' => $teamId]);
}

/* ─── [3-3] 클라우드 공개 링크 → 내려받기 주소 (F-43, ARCH 5.22) ─────
 *
 * 사람이 손에 쥐는 주소는 **보는 주소**다(`…/file/d/<ID>/view`). 그대로 가져오면 파일이 아니라
 * 웹 페이지가 저장되므로, 공급자가 정해 둔 내려받기 주소로 바꾼 뒤 검사에 넘긴다.
 *
 * ★ 변환은 **관문이 아니다**. 바꾼 주소도 아래 [4] 의 Fetch::check 를 똑같이 지난다 —
 *   관리자가 `drive.google.com` 을 허용 목록에 넣지 않았다면 변환해도 host_not_allowed 다.
 * ★ 회사 테넌트(SharePoint) 문서처럼 **로그인이 필요한 주소**는 여기서 끝낸다. 서버는
 *   로그인할 수 없고(계정 연결 기능을 두지 않기로 했다 — 사용자 결정 2026-09-15),
 *   로그인 화면을 파일로 저장하면 "로그인 페이지가 첨부된 문서" 가 쌓인다.
 *   이 거부만은 **다른 가져오기 실패와 문구가 다르다**: 요청자가 고쳐서 다시 할 수 있는
 *   일(공유 설정을 바꾸거나 내려받아 올리기)이고, 내부 상태를 한 글자도 알려 주지 않는다
 *   (민감정보 거부와 같은 판단, Http::MSG_SENSITIVE_LEVEL_REQUIRED).
 */
$link      = CloudLink::normalize($requestedUrl);
$provider  = $link['provider'];
$safeInput = $link['url'];
$sourceUrl = $safeInput === $requestedUrl
    ? $shortUrl($requestedUrl)
    : $shortUrl($requestedUrl . ' → ' . $safeInput);

if ($link['needs_login'] === true) {
    Audit::deny('file.fetch', 'needs_login', [
        'target_type'  => 'file',
        'target_name'  => null,
        'access_level' => $level,
        'detail'       => [
            'source_url'  => $sourceUrl,
            'provider'    => $provider,
            'needs_login' => true,
        ],
    ]);
    Http::error(
        400,
        '이 문서는 로그인이 필요합니다. 「링크가 있는 모든 사용자」로 공개된 문서만 가져올 수 있습니다 — 공유 설정을 바꾸거나 내려받아 올리세요.'
    );
}

// ─── [4] URL 검사 (PRD S-05 ①②③④⑤) ─────────────────────────
try {
    $checked    = Fetch::check($safeInput);
    $sourceHost = $checked['host'];
    $sourceIp   = $checked['ip'];
    $safeUrl    = $checked['url'];
} catch (FetchDenied $e) {
    if ($e->host !== null) {
        $sourceHost = $e->host;
    }
    $rejectFetch(400, $e->reason, null, $level, [
        'source_host' => $sourceHost,
        'source_url'  => $sourceUrl,
        'provider'    => $provider,
    ]);
    return;
} catch (RuntimeException $e) {
    // 설정 오류(FETCH_ALLOWED_HOSTS 의 와일드카드·공백)도 "아무 호스트도 허용되지 않는" 상태다.
    // 조용히 통과시키지 않고 host_not_allowed 로 기록한다 (SECURITY 1.6 "와일드카드 금지").
    error_log('[tbox] request_id=' . TBOX_REQUEST_ID . ' file.fetch 설정 오류: ' . $e->getMessage());
    $rejectFetch(400, 'host_not_allowed', null, $level, [
        'source_host' => $sourceHost,
        'source_url'  => $sourceUrl,
        'provider'    => $provider,
    ]);
    return;
}

// ─── [5] 임시파일로 내려받기 (PRD S-05 ⑥⑦⑧) ─────────────────
$tmpPath = tempnam(Storage::tmpDir(), 'fetch-');
if (!is_string($tmpPath) || $tmpPath === '') {
    throw new RuntimeException('임시 파일을 만들 수 없습니다');
}

/**
 * 임시파일은 성공·실패 어느 쪽으로 끝나도 반드시 지운다.
 * Http::error() 는 exit 로 끝나고 exit 는 finally 를 실행하지 않으므로,
 * 마지막 그물로 종료 함수에도 같은 정리를 걸어 둔다(둘 다 있어야 빠짐이 없다).
 */
$cleanTmp = static function () use ($tmpPath): void {
    if (is_file($tmpPath)) {
        @unlink($tmpPath);
    }
};
register_shutdown_function($cleanTmp);

try {
    // F-43 — 리다이렉트는 여기서 **최대 3번**, 단계마다 Fetch::check 를 처음부터 다시 받는다.
    // $provider 가 있으면(드라이브·OneDrive) HTML 응답을 needs_login 으로 거른다 (ARCH 5.22).
    $result     = Fetch::download($safeUrl, $sourceHost, $sourceIp, $tmpPath, $provider);
    $hops       = $result['hops'] ?? [$sourceHost];
    $cookieHops = (int) ($result['cookie_hops'] ?? 0);
    // 마지막으로 실제 받은 곳 — 감사 로그의 source_host 는 "어디서 받았나" 여야 한다.
    $sourceHost = (string) ($result['host'] ?? $sourceHost);
    $sourceIp   = (string) ($result['ip'] ?? $sourceIp);
} catch (FetchDenied $e) {
    $cleanTmp();
    if ($e->host !== null) {
        $sourceHost = $e->host;
    }
    if ($e->reason === 'needs_login') {
        // 위 [3-3] 과 같은 거부다 — 링크는 공개가 아니었고, 그것을 200 응답(HTML)으로 알았다.
        Audit::deny('file.fetch', 'needs_login', [
            'target_type'  => 'file',
            'target_name'  => null,
            'access_level' => $level,
            'detail'       => [
                'source_host' => $sourceHost,
                'source_url'  => $sourceUrl,
                'provider'    => $provider,
                'needs_login' => true,
                'http_status' => $e->httpStatus,
            ],
        ]);
        Http::error(
            400,
            '이 문서는 로그인이 필요합니다. 「링크가 있는 모든 사용자」로 공개된 문서만 가져올 수 있습니다 — 공유 설정을 바꾸거나 내려받아 올리세요.'
        );
    }
    $rejectFetch(400, $e->reason, null, $level, [
        'source_host' => $sourceHost,
        'source_ip'   => $sourceIp,
        'http_status' => $e->httpStatus,
        'source_url'  => $sourceUrl,
        'provider'    => $provider,
    ]);
    return;
}

$original = $result['filename'] ?? 'download';
if (iconv_strlen($original, 'UTF-8') > 255) {
    $original = (string) iconv_substr($original, 0, 255, 'UTF-8');
}

// ─── [6] 가져온 파일 검증 — 수동 업로드와 같은 함수 (PRD S-05 ⑨) ───
// 확장자 화이트리스트·이중 확장자·MIME 짝·크기 전부 여기서 판정된다.
// 세부 사유(ext_not_allowed·mime_mismatch·quota_exceeded …)는 ARCH 7.5 에 키가 없으므로
// 감사 로그 reason 은 invalid_file 하나로 두고 세부는 error_log 에만 남긴다.
try {
    $checkedFile = Upload::validatePath($tmpPath, $original);
    Upload::checkDailyQuota((int) $user['id'], $checkedFile['size']);
} catch (RuntimeException $e) {
    error_log(sprintf(
        '[tbox] request_id=%s file.fetch invalid_file reason=%s host=%s',
        TBOX_REQUEST_ID,
        $e->getMessage(),
        $sourceHost
    ));
    $cleanTmp();
    $rejectFetch(400, 'invalid_file', $original, $level, [
        'source_host' => $sourceHost,
        'source_ip'   => $sourceIp,
    ]);
    return;
}

/* ─── [6-2] 민감정보 패턴 검출 (15-3, PRD F-22, ARCH 5.13, SECURITY R-58) ───
 *
 * 업로드(files_upload.php [4-3])와 **같은 함수를 같은 자리에서** 부른다 — 검사 뒤·저장 전.
 * URL 로 가져오는 길이 "검사를 건너뛰는 두 번째 입구" 가 되지 않게 하려는 것이며,
 * Upload::validatePath 를 재사용하는 것과 같은 이유다 (PRD S-05 ⑨).
 *
 * 돌려받는 것은 패턴 이름과 건수뿐이고, 검출된 값은 어디에도 남지 않는다 (PRD S-07).
 */
require_once __DIR__ . '/../Sensitive.php';

$sensitive      = Sensitive::scan($tmpPath, $checkedFile['ext']);
$sensitiveFlags = array_keys($sensitive);

if ($sensitive !== [] && in_array($level, ['public', 'internal'], true)) {
    $cleanTmp();
    Audit::deny('file.upload', 'sensitive_level_required', [
        'target_type'  => 'file',
        'target_name'  => $original,
        'access_level' => $level,
        'detail'       => [
            'sensitive'   => $sensitive,
            'target_team' => $teamId,
            'source_host' => $sourceHost,
        ],
    ]);
    /*
     * ★ 행동 이름은 `file.fetch` 가 아니라 **`file.upload`** 다 (ARCH 5.13, 7.4 표의 행).
     *   거부의 이유가 "가져오기" 가 아니라 "이 내용을 이 등급으로 **올리는 것**" 이어서,
     *   두 입구의 같은 거부가 감사 로그에서 한 이름으로 모여야 조사하기 쉽기 때문이다.
     * ★ 응답도 MSG_FETCH_FAILED 가 아니라 민감정보 문구다 — 다른 가져오기 실패와 달리
     *   이 거부는 **요청자가 고쳐서 다시 할 수 있는 일**(등급을 올려 다시 요청)이고,
     *   외부 주소·내부 상태를 한 글자도 알려 주지 않는다.
     */
    Http::error(400, Http::MSG_SENSITIVE_LEVEL_REQUIRED);
}

// ─── [7] 저장 — files_upload.php [5] 와 같은 트랜잭션 ──────────
$storedName = Storage::newStoredName($checkedFile['ext']);

$sqlInsert = <<<'SQL'
    INSERT INTO files
        (original_name, stored_name, ext, mime, size_bytes, sha256,
         description, access_level, team_id, uploaded_by, sensitive_flags, folder_id,
         scan_status)
    VALUES
        (:original_name, :stored_name, :ext, :mime, :size_bytes, :sha256,
         :description, :access_level, :team_id, :uploaded_by,
         CAST(:flags AS text[]), CAST(:folder_id AS bigint),
         :scan_status)
    RETURNING id, public_id
    SQL;

$db    = Db::instance();
$moved = false;
$db->begin();
try {
    $row = $db->run($sqlInsert, [
        ':original_name' => $original,
        ':stored_name'   => $storedName,
        ':ext'           => $checkedFile['ext'],
        ':mime'          => $checkedFile['mime'],
        ':size_bytes'    => $checkedFile['size'],
        ':sha256'        => $checkedFile['sha256'],
        ':description'   => $description,
        ':access_level'  => $level,
        ':team_id'       => $teamId,
        ':uploaded_by'   => (int) $user['id'],
        ':flags'         => '{' . implode(',', $sensitiveFlags) . '}',
        ':folder_id'     => $folderId,
        // F-54 ① (ARCH 5.28) — 가져온 파일도 수동 업로드와 **같은 상태**로 저장한다.
        ':scan_status'   => Upload::initialScanStatus(),
    ])->fetch();

    if (!is_array($row)) {
        throw new RuntimeException('가져온 파일 레코드를 만들 수 없습니다');
    }

    Storage::putLocal($tmpPath, $storedName);
    $moved = true;

    Audit::allow('file.fetch', [
        'target_type'  => 'file',
        'target_id'    => (int) $row['id'],
        'target_name'  => $original,
        'access_level' => $level,
        'detail'       => [
            'ext'         => $checkedFile['ext'],
            'mime'        => $checkedFile['mime'],
            'size_bytes'  => $checkedFile['size'],
            'granted_by'  => $uploadCode,
            'sha256'      => $checkedFile['sha256'],
            'owner_id'    => (int) $user['id'],
            'target_team' => $teamId,
            // 출처 — 마지막으로 받은 host 와 IP (ARCH 7.5 source_host)
            'source_host' => $sourceHost,
            'source_ip'   => $sourceIp,
            /* F-43 — 되짚기에 필요한 세 값 (ARCH 7.5 등록부).
               hops       지나온 호스트 목록. 「어디를 거쳐 왔나」가 리다이렉트 통제의 증거다
               source_url 사람이 넣은 주소와 서버가 바꾼 주소. 변환이 맞았는지를 로그만으로 본다
               provider   드라이브·OneDrive·SharePoint 링크였나 (google·microsoft·null)
               F-46 —
               cookie_hops 직전 응답의 쿠키를 **같은 호스트**로 실어 보낸 단계 수. 값은 남기지
                           않는다 — 「쿠키를 썼나·몇 번 썼나」만 조사에 필요하다 (R-77 보충) */
            'hops'        => $hops,
            'source_url'  => $sourceUrl,
            'provider'    => $provider,
            'cookie_hops' => $cookieHops,
        ] + ($sensitive === [] ? [] : ['sensitive' => $sensitive]),
    ]);

    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    if ($moved) {
        try {
            Storage::removeUploaded($storedName);
        } catch (Throwable $ignored) {
            error_log('[tbox] request_id=' . TBOX_REQUEST_ID . ' 가져오기 롤백 중 파일 삭제 실패');
        }
    }
    $cleanTmp();

    try {
        Audit::error('file.fetch', 'fetch_failed', [
            'target_type'  => 'file',
            'target_name'  => $original,
            'access_level' => $level,
            'detail'       => [
                'moved'       => $moved,
                'source_host' => $sourceHost,
                'source_ip'   => $sourceIp,
            ],
        ]);
    } catch (Throwable $ignored) {
        error_log('[tbox] request_id=' . TBOX_REQUEST_ID . ' 가져오기 실패 감사 기록 실패');
    }

    throw $e;   // bootstrap 의 예외 핸들러가 500 + {"error":"서버 오류"} 로 끝낸다
}

$cleanTmp();

Http::json(['id' => (string) $row['public_id'], 'name' => $original], 201);
