<?php
declare(strict_types=1);

/**
 * src/Upload.php — 업로드 검증 (PRD S-06, ARCHITECTURE 5.2)
 *
 * ★ 6단계 업로드 하드닝. 3단계의 "형태만 보는" 최소 버전을 PRD 7.6 표대로 잠갔다.
 *
 * 여기서 하는 것
 *   · $_FILES 한 건의 형태·오류 코드 확인 (is_uploaded_file 포함)
 *   · 크기: 1 byte ~ MAX_BYTES(정확히 104,857,600 = 100MB). 초과(`>`)만 거부 —
 *     nginx(client_max_body_size 101m)·php(post_max_size)에 이어 **정확한 경계는 여기서** 판정한다
 *     (ARCH 8장 6단계 결정: 100m 이면 multipart 오버헤드 때문에 정확히 100MB 파일이 413 이 됐다)
 *   · 확장자 **화이트리스트** ALLOWED_EXT (PRD 7.6) — 없거나 목록 밖이면 `ext_not_allowed` (T-7)
 *   · **이중 확장자** — 마지막이 아닌 세그먼트가 BLOCKED_EXT 에 있으면 `double_extension`
 *     (예 `shell.php.txt`, `.htaccess.txt`). 블랙리스트는 어디까지나 이 보조 검사에만 쓴다.
 *   · 확장자 ↔ 실제 내용(finfo MIME) 짝 검사 — 어긋나면 `mime_mismatch` (T-8)
 *   · 사용자별 일일 총량 2GB — checkDailyQuota() (PRD 7.6)
 *   · sha256 계산 (무결성, PRD S-06 · S-08, R-13)
 *
 * 여기서 하지 **않는** 것
 *   · 악성코드 스캔(ClamAV) — PRD Q-3 결정(2026-09-11) 으로 과제 범위 밖. `files.scan_status` 컬럼과
 *     다운로드 차단 자리(ARCH 5.3 [4])만 남겨 둔다. R-02 는 ◐ 유지
 *   · 권한 판단 — 레벨·팀 판정은 Authz 가 한다 (ARCH 4.1). 이 파일은 역할을 보지 않는다
 *   · F-14(URL 가져오기)는 7단계에서 이 validate() 를 **그대로 재사용**한다 (PRD S-05 ⑨)
 *
 * 실패는 예외로 알린다. 예외 메시지는 **감사 로그용 사유 코드**이며 사용자에게 보이지 않는다
 * (핸들러가 Audit::deny 의 reason 으로만 쓰고, 응답은 Http::MSG_BAD_REQUEST 하나다).
 */
final class Upload
{
    /** 허용 확장자 화이트리스트 — PRD 7.6 S-06 표 그대로 */
    public const ALLOWED_EXT = [
        'pdf', 'docx', 'xlsx', 'pptx', 'hwp', 'hwpx',
        'txt', 'csv', 'md',
        'png', 'jpg', 'jpeg', 'gif', 'zip',
    ];

    /**
     * 이중 확장자 **보조** 검사용 블랙리스트 (PRD 7.6 "차단 확장자 (예시)").
     * 주 방어는 위 화이트리스트다. 이 목록은 `shell.php.txt` 처럼 마지막 확장자는 허용이지만
     * 중간에 실행 형식을 숨긴 이름을 잡는 데만 쓴다 — 목록에 없는 것을 통과시키는 근거가 아니다.
     */
    public const BLOCKED_EXT = [
        'php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps',
        'html', 'htm', 'xhtml', 'shtml', 'svg', 'js', 'mjs',
        'jsp', 'jspx', 'asp', 'aspx',
        'exe', 'sh', 'bash', 'bat', 'cmd', 'com', 'dll', 'so', 'msi', 'scr', 'pif',
        'vbs', 'ps1', 'py', 'pl', 'rb', 'cgi', 'htaccess',
    ];

    /**
     * 확장자 ↔ finfo(FILEINFO_MIME_TYPE) 실측값 짝 표.
     * 값은 전부 소문자다 — checkMime() 이 finfo 결과를 strtolower 한 뒤 비교한다.
     *
     * `application/octet-stream` 은 **어떤 확장자에도 넣지 않는다**. 내용을 알 수 없다는 뜻이라
     * 한 번 허용하면 위장 파일 검사가 통째로 무력화된다(finfo 실패 시에도 자동으로 거부된다).
     * `text/x-php`·`text/html` 같은 값은 어느 칸에도 없으므로 자동으로 mime_mismatch 다 (T-8).
     *
     * 실측 근거 (2026-09-11, PHP 8.1.2 / file-5.41, 6단계 시험 표):
     *   pdf → application/pdf · docx/xlsx/pptx → 각 OOXML 타입 · hwpx → application/zip
     *   txt/md → text/plain · csv → text/csv · png/jpg/gif → image/* · zip → application/zip
     *   OLE 컨테이너(HWP 5.x 계열) → 이 VM 의 libmagic 은 `application/cdfv2` 를 돌려준다.
     *   실제 HWP 본문("HWP Document File" 스트림)이 있으면 `application/x-hwp` 다.
     */
    private const MIME_BY_EXT = [
        'pdf'  => ['application/pdf'],
        'docx' => [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ],
        'xlsx' => [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ],
        'pptx' => [
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        ],
        'hwp'  => [
            'application/x-hwp',
            'application/haansofthwp',
            'application/vnd.hancom.hwp',
            'application/x-ole-storage',
            'application/cdfv2',
        ],
        'hwpx' => ['application/hwp+zip', 'application/zip'],
        'txt'  => ['text/plain'],
        'csv'  => ['text/csv', 'text/plain'],
        'md'   => ['text/markdown', 'text/plain'],
        'png'  => ['image/png'],
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'gif'  => ['image/gif'],
        'zip'  => ['application/zip'],
    ];

    /** 단일 파일 최대 크기 — 정확히 100MB. 이 값을 **넘을 때만** 거부한다 (PRD S-06) */
    public const MAX_BYTES = 104857600;

    /** 사용자별 24시간 업로드 총량 — 2GB (PRD 7.6). .env 키를 늘리지 않고 상수로 둔다 */
    public const DAILY_QUOTA_BYTES = 2147483648;

    /** finfo 결과로 인정하는 MIME "형태". 어긋나면 octet-stream 으로 보고 짝 검사에서 거부된다 */
    private const MIME_RE = '#^[A-Za-z0-9!\#$&^_.+-]+/[A-Za-z0-9!\#$&^_.+-]+$#';

    private const DEFAULT_MIME = 'application/octet-stream';

    /**
     * $_FILES 한 건을 검증한다. 통과하면 ['ext','mime','size','sha256'] 반환.
     *
     * 순서(ARCH 5.2 [3]): 오류코드 → uploaded_file → 크기 → 확장자(이중 포함) → MIME 짝 → sha256.
     * 앞의 두 가지(HTTP 업로드 고유 검사)만 여기서 하고, 나머지 **내용 규칙은
     * validatePath() 한 곳**에 있다 — URL 가져오기(F-14)가 같은 규칙을 쓰기 위해서다 (PRD S-05 ⑨).
     * 일일 총량(checkDailyQuota)은 권한·검증을 통과한 뒤 핸들러가 INSERT 직전에 부른다.
     *
     * @param array<string,mixed> $file $_FILES['file'] 한 건
     * @return array{ext:string,mime:string,size:int,sha256:string}
     */
    public static function validate(array $file): array
    {
        $error = $file['error'] ?? null;
        if (!is_int($error)) {
            // 배열로 온 경우(file[] 다중 업로드) 등 형태 자체가 다른 요청
            throw new RuntimeException('upload_error');
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException(match ($error) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'too_large',
                UPLOAD_ERR_NO_FILE                        => 'no_file',
                default                                   => 'upload_error',
            });
        }

        $tmp = $file['tmp_name'] ?? null;
        if (!is_string($tmp) || $tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('not_uploaded_file');
        }

        $name = $file['name'] ?? null;

        return self::validatePath($tmp, is_string($name) ? $name : '');
    }

    /**
     * **파일 하나의 내용 규칙**을 검사한다 — 크기 → 확장자(이중 포함) → MIME 짝 → sha256.
     *
     * 검증 규칙 함수는 프로젝트에 **이 하나뿐**이다 (PRD S-05 ⑨ "수동 업로드와 동일한
     * Upload::validate"). 두 입구가 이것을 함께 쓴다.
     *   · 수동 업로드 : validate($_FILES 한 건) 이 HTTP 업로드 고유 검사(오류코드·is_uploaded_file)를
     *                   먼저 하고 이 함수를 부른다.
     *   · URL 가져오기: files_fetch.php 가 임시파일 경로와 정한 파일명으로 이 함수를 **직접** 부른다.
     *     가져온 파일은 HTTP 업로드가 아니므로 is_uploaded_file 이 참일 수 없다 —
     *     대신 "임시 디렉터리 안의 파일인가" 를 Storage::putLocal() 이 확인한다.
     *
     * 규칙을 한 함수에 둔 이유: 갈라 두면 언제 한쪽만 고쳐진다. 그때 **URL 가져오기가
     * 업로드 하드닝을 우회하는 두 번째 입구**가 된다 (ARCH 5.6 [8] 표).
     *
     * @return array{ext:string,mime:string,size:int,sha256:string}
     */
    public static function validatePath(string $tmpPath, string $originalName): array
    {
        if ($tmpPath === '' || !is_file($tmpPath)) {
            throw new RuntimeException('size_unreadable');
        }

        // 크기는 클라이언트가 보낸 $file['size'] 나 Content-Length 를 믿지 않고 실제 파일에서 읽는다.
        $size = filesize($tmpPath);
        if ($size === false) {
            throw new RuntimeException('size_unreadable');
        }
        if ($size < 1) {
            throw new RuntimeException('empty_file');
        }
        if ($size > self::MAX_BYTES) {
            // 정확히 MAX_BYTES 인 파일은 통과한다 (PRD S-06 "100MB", ARCH 8장 6단계 결정)
            throw new RuntimeException('too_large');
        }

        $ext  = self::checkExtension($originalName);
        $mime = self::checkMime($tmpPath, $ext);

        $sha256 = hash_file('sha256', $tmpPath);
        if ($sha256 === false) {
            throw new RuntimeException('hash_failed');
        }

        return [
            'ext'    => $ext,
            'mime'   => $mime,
            'size'   => (int) $size,
            'sha256' => $sha256,
        ];
    }

    /**
     * 저장에 쓸 확장자를 정한다 (6단계, T-7 · 이중 확장자).
     *
     *   1) 경로를 떼고(basename) 제어문자를 지운 뒤 '.' 로 나눈다.
     *   2) 세그먼트가 2개 이상이면 **마지막이 아닌** 세그먼트를 BLOCKED_EXT 와 대조한다.
     *      하나라도 걸리면 `double_extension` — `shell.php.txt`, `.htaccess.txt` 가 여기서 막힌다.
     *   3) 마지막 세그먼트가 ALLOWED_EXT 에 없으면 `ext_not_allowed` —
     *      `shell.php`, `evil.html`, `a.pdf.php`, 확장자 없는 이름이 여기서 막힌다.
     *
     * 3단계의 "형태가 아니면 'bin' 으로 대체" 는 폐기했다. 모르는 형식은 저장하지 않는다.
     * 반환값은 저장 이름(UUID.ext)에만 쓰이고, 사용자 파일명은 경로에 한 글자도 들어가지 않는다.
     */
    public static function checkExtension(string $originalName): string
    {
        $base = basename(str_replace('\\', '/', $originalName));
        // 널바이트·제어문자로 세그먼트를 쪼개 검사를 피하는 이름을 먼저 정리한다
        $base = (string) preg_replace('/[\x00-\x1f\x7f]+/', '', $base);

        $parts = explode('.', $base);
        $count = count($parts);
        if ($count < 2) {
            // 확장자가 아예 없다
            throw new RuntimeException('ext_not_allowed');
        }

        for ($i = 0; $i < $count - 1; $i++) {
            $segment = strtolower(trim($parts[$i]));
            if ($segment !== '' && in_array($segment, self::BLOCKED_EXT, true)) {
                throw new RuntimeException('double_extension');
            }
        }

        $ext = strtolower(trim($parts[$count - 1]));
        if (!in_array($ext, self::ALLOWED_EXT, true)) {
            throw new RuntimeException('ext_not_allowed');
        }
        return $ext;
    }

    /**
     * finfo 로 판별한 실제 MIME 이 확장자와 짝이 맞는지 본다 (T-8, PRD 7.6 "내용 검사").
     *
     * 짝이 맞으면 그 MIME 문자열(소문자)을 돌려주고 DB `files.mime` 에 기록된다.
     * 맞지 않으면 `mime_mismatch` — `shell.php` 를 `doc.pdf` 로 바꿔 올려도 여기서 끝난다.
     * finfo 자체가 실패하거나 형태가 이상하면 octet-stream 으로 보고, 그 값은 어느 칸에도
     * 없으므로 역시 거부된다(닫힌 방향으로 실패한다).
     */
    /** OOXML 확장자별 (반드시 있어야 하는 zip 항목 2개, 정식 MIME). 중앙 디렉터리의 이름만 본다. */
    private const OOXML_MARKERS = [
        'xlsx' => [['[Content_Types].xml', 'xl/workbook.xml'],       'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'docx' => [['[Content_Types].xml', 'word/document.xml'],     'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'pptx' => [['[Content_Types].xml', 'ppt/presentation.xml'],  'application/vnd.openxmlformats-officedocument.presentationml.presentation'],
    ];

    /** zip 중앙 디렉터리에 OOXML 필수 항목이 다 있으면 정식 MIME, 아니면 null. 항목 내용은 읽지 않는다. */
    private static function ooxmlMime(string $tmpPath, string $ext): ?string
    {
        [$needles, $mime] = self::OOXML_MARKERS[$ext];
        $zip = new ZipArchive();
        if ($zip->open($tmpPath, ZipArchive::RDONLY) !== true) {
            return null;
        }
        try {
            if ($zip->numFiles < 2 || $zip->numFiles > 10000) {
                return null;   // 항목 수가 비정상이면 OOXML 로 보지 않는다
            }
            foreach ($needles as $name) {
                if ($zip->locateName($name) === false) {
                    return null;
                }
            }
            return $mime;
        } finally {
            $zip->close();
        }
    }

    /** 마지막 checkMime 이 거부하며 감지한 MIME (진단용, 요청 하나 안에서만 의미 있음) */
    public static ?string $lastDetectedMime = null;

    public static function checkMime(string $tmpPath, string $ext): string
    {
        $allowed = self::MIME_BY_EXT[$ext] ?? null;
        if ($allowed === null) {
            // ALLOWED_EXT 에는 있는데 짝 표에 빠진 확장자 — 통과시키지 않는다
            throw new RuntimeException('mime_mismatch');
        }

        $mime  = self::DEFAULT_MIME;
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $detected = finfo_file($finfo, $tmpPath);
            finfo_close($finfo);
            if (is_string($detected) && $detected !== '' && strlen($detected) <= 255
                && preg_match(self::MIME_RE, $detected) === 1) {
                $mime = strtolower($detected);
            }
        }

        // OOXML(docx·xlsx·pptx) 은 finfo 가 첫 zip 항목만 보고 판정한다. 이카운트처럼 첫 항목이
        // [Content_Types].xml 이 아닌 파일은 application/zip 또는 octet-stream 으로 나온다(2026-09-13 실측:
        // 머리 50 4B 03 04 = 진짜 zip 인데 octet-stream). 그런 경우 zip **중앙 디렉터리만** 읽어
        // OOXML 필수 항목 두 개가 있는지 확인하고, 있으면 정식 OOXML MIME 으로 본다. 내용은 풀지 않는다(R-42).
        // 이 검사는 종전의 "application/zip 이면 통과" 보다 엄격하다 — 이제 zip 이어도 구조가 맞아야 한다.
        if (isset(self::OOXML_MARKERS[$ext]) && ($mime === self::DEFAULT_MIME || $mime === 'application/zip')) {
            $mime = self::ooxmlMime($tmpPath, $ext) ?? $mime;
        }

        if (!in_array($mime, $allowed, true)) {
            // 감지된 실제 형식을 남겨 핸들러가 deny detail 에 적게 한다 (R-71: 사유 없는 거부는 진단 불가).
            // 값은 finfo 가 돌려준 MIME 문자열(형태 검사 통과분) 또는 octet-stream 이라 비밀이 없다.
            // 앞 8바이트 16진수도 함께 — finfo 가 octet-stream 이라고만 할 때 형식을 가려내는 유일한 단서다.
            $head = @file_get_contents($tmpPath, false, null, 0, 8);
            self::$lastDetectedMime = $mime . ' head=' . (is_string($head) ? strtoupper(bin2hex($head)) : '?');
            throw new RuntimeException('mime_mismatch');
        }
        return $mime;
    }

    /**
     * 사용자별 일일(24시간) 업로드 총량 2GB 검사 (PRD 7.6 S-06).
     *
     * 논리 삭제된 파일도 합계에 넣는다 — 올리고 지우기를 반복해 한도를 우회하지 못하게 한다.
     * 한도를 넘으면 `quota_exceeded` 예외. 핸들러는 다른 검증 실패와 똑같이 400 + deny 로 끝낸다
     * (사유는 감사 로그에만, PRD 8장 표의 업로드 실패 코드는 400/403/413 이고 413 은 nginx 몫이다).
     */
    public static function checkDailyQuota(int $userId, int $newSize): void
    {
        $sql = <<<'SQL'
            SELECT COALESCE(SUM(size_bytes), 0) AS used
              FROM files
             WHERE uploaded_by = :uid
               AND created_at > now() - interval '24 hours'
            SQL;
        $row  = Db::instance()->run($sql, [':uid' => $userId])->fetch();
        $used = is_array($row) ? (int) $row['used'] : 0;

        if ($used + $newSize > self::DAILY_QUOTA_BYTES) {
            throw new RuntimeException('quota_exceeded');
        }
    }
}
