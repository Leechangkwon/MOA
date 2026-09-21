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
 *   · **F-53 속 검사** (ARCH 5.27) — 파일을 **풀지 않고** 이름·크기 표·바이트만 본다:
 *     · zip·hwpx·OOXML 의 중앙 디렉터리 이름 — 차단 확장자 항목·경로 탈출·중첩 zip·압축 폭탄
 *       (`zip_unreadable`·`zip_bad_path`·`zip_blocked_entry`·`nested_zip`·`zip_bomb`)
 *     · OOXML 매크로 — 항목 이름이 `vbaProject.bin` 또는 `vbaData.xml` 로 끝나면 거부 (`macro_found`)
 *     · PDF 능동 내용 — 앞 4MB + 뒤 4MB 에서 `/JavaScript`·`/OpenAction`·`/Launch` … (`pdf_active_content`)
 *     · HWP OLE 위장 — OLE 머리 + `FileHeader` 항목 이름 + `HWP Document File` 서명 (`hwp_not_hwp`)
 *
 * 여기서 하지 **않는** 것
 *   · 악성코드 스캔(ClamAV) — **F-54 배치 몫**이다(ARCH 5.28: `pending` → `scripts/scan_pending.php`
 *     → `clean`/`infected`). 이 파일은 소켓·`exec` 를 부르지 않는다. `files.scan_status` 컬럼과
 *     다운로드 차단 자리(ARCH 5.3 [4])가 그 짝이다
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
     * 새 파일 행의 `scan_status` — F-54 ① (PRD F-54, ARCHITECTURE 5.28).
     *
     * `SCAN_ENABLED=1` 이면 `'pending'`(= 아직 아무도 내려받지 못한다. 1분마다 도는
     * `scripts/scan_pending.php` 가 `clamscan` 으로 검사해 `'clean'`/`'infected'` 로 바꾼다),
     * 그 밖에는 `'clean'`(지금까지의 동작 그대로).
     *
     * 이 파일은 ClamAV 를 **부르지 않는다** — 소켓도 `exec` 도 없다. 스캐너를 부르는 곳은
     * 앱 밖 배치 하나뿐이고(ARCH 5.28 「왜 앱 밖 배치인가」), 이 함수가 하는 일은
     * "새 행을 어떤 상태로 저장할 것인가" 를 **한 곳에서** 정하는 것뿐이다.
     * INSERT 는 두 곳(`_upload_common`·`files_fetch`)이고, 두 곳이 각자 판단하면 언젠가
     * 한 곳만 빠진다 — 그 한 곳이 검사 없는 입구가 된다.
     */
    public static function initialScanStatus(): string
    {
        return Env::getOrEmpty('SCAN_ENABLED') === '1' ? 'pending' : 'clean';
    }

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
        self::inspectContent($tmpPath, $ext, (int) $size);   // F-53 — 속 검사 (ARCH 5.27). 실패는 사유 코드 예외

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

    // ─────────────────────────────────────────────────────────────────────────────
    //  F-53 「속」 검사 — PRD F-53 · ARCHITECTURE 5.27 · SECURITY R-02 · R-86
    //
    //  원칙 하나: **파일을 풀지 않는다.** 추출은 그 자체가 공격면이다(경로 탈출·폭탄·심볼릭
    //  링크). 그래서 여기서 쓰는 것은 zip 중앙 디렉터리의 **이름과 크기 표**, 그리고 원본
    //  바이트 몇 MB 뿐이다 — 임시 파일도, 외부 호출도, 항목 내용 읽기도 없다.
    //  잡지 못하는 것(암호 zip 속·zip 으로 한 번 싼 PDF·제로데이)은 F-54 ClamAV 몫이다.
    // ─────────────────────────────────────────────────────────────────────────────

    /** zip 컨테이너로 보는 확장자 — 중앙 디렉터리를 훑는 대상 (ARCH 5.27 표 1·2·3행) */
    private const ZIP_LIKE_EXT = ['zip', 'hwpx', 'docx', 'xlsx', 'pptx'];

    /** 풀었을 때의 총 크기 상한 (1GB) 과 압축비 상한 — 넘으면 `zip_bomb` (PRD F-53 ①) */
    private const ZIP_TOTAL_MAX  = 1073741824;
    private const ZIP_RATIO_MAX  = 100;

    /**
     * 압축비 규칙의 **바닥** — 푼 뒤의 합계가 32MB 도 되지 않으면 압축비가 아무리 높아도 넘긴다.
     *
     * 왜 바닥을 두나: 5KB zip 이 500KB 짜리 반복 텍스트(로그·CSV)로 풀리는 것은 흔한 **정상**
     * 파일인데, 압축비만 보면 100:1 을 넘어 오탐이 된다. 압축 폭탄이 위험한 까닭은 푸는 쪽의
     * 디스크·메모리를 먹는 데 있으므로 **수십 MB 이상으로 커질 때만** 위험하다.
     * 합계 1GB 규칙과 항목 10,000 규칙은 이 바닥과 무관하게 그대로 돈다.
     */
    private const ZIP_RATIO_FLOOR = 33554432;

    /** 중앙 디렉터리 항목 수 상한 — 항목 수 폭탄도 폭탄이다 (PRD F-53 ①) */
    private const ZIP_ENTRY_MAX  = 10000;

    /** PDF 에서 「열리면 실행되는」 이름 객체. 뒤에 구분자가 와야 센다(`/AAPL` 같은 오탐 방지) */
    private const PDF_ACTIVE_RE = '#/(JavaScript|JS|OpenAction|AA|Launch|EmbeddedFile|RichMedia)(?=[\s<(\[/])#';

    /** PDF 는 앞뒤로 이만큼씩만 읽는다 (100MB PDF 를 통째로 메모리에 올리지 않는다) */
    private const PDF_WINDOW = 4194304;

    /** 조각 경계에 걸친 열쇳말을 놓치지 않게 앞 조각 끝에서 이어 붙이는 길이 */
    private const SCAN_OVERLAP = 64;

    /** OLE(복합 문서) 머리 8바이트 — HWP 5.x·구형 MS Office 가 함께 쓰는 컨테이너 서명 */
    private const OLE_SIGNATURE = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";

    /**
     * 확장자로 갈래를 타 「속」을 본다. 통과하면 아무것도 돌려주지 않고, 걸리면 사유 코드 예외.
     *
     * 갈래에 없는 확장자(txt·csv·md·png·jpg·jpeg·gif)는 **아무것도 하지 않는다** —
     * 이 형식들은 컨테이너가 아니라서 「속」이라 할 것이 없고, 겉 검사(확장자·MIME 짝)가 끝이다.
     */
    private static function inspectContent(string $tmpPath, string $ext, int $fileSize): void
    {
        if (in_array($ext, self::ZIP_LIKE_EXT, true)) {
            self::inspectZip($tmpPath, $ext, $fileSize);
            return;
        }

        if ($ext === 'pdf') {
            self::inspectPdf($tmpPath, $fileSize);
            return;
        }

        if ($ext === 'hwp') {
            // 대상은 MIME_BY_EXT 의 hwp 칸 중 **OLE 계열**(`application/cdfv2`·
            // `application/x-ole-storage`)로 판정된 파일이다. checkMime 의 결과를 인자로 다시
            // 받지 않고 머리 8바이트로 직접 가리는 이유: 그 두 MIME 은 libmagic 이 「OLE 머리를
            // 가진 파일」에 붙이는 값이라 머리 바이트와 뜻이 같고, 짝 검사를 두 번 하지 않아도 된다.
            // 머리가 OLE 가 아니면 finfo 가 x-hwp 계열로 본 파일이므로 이 검사의 대상이 아니다.
            $head = @file_get_contents($tmpPath, false, null, 0, 8);
            if (is_string($head) && $head === self::OLE_SIGNATURE) {
                self::inspectHwpOle($tmpPath);
            }
        }
    }

    /**
     * zip·hwpx·OOXML 의 **중앙 디렉터리만** 훑는다 (ARCH 5.27 표 1~3행).
     *
     * 보는 것은 항목의 `name` 과 `size`(푼 뒤의 원본 크기) 두 칸뿐이다. `getFromName` 이나
     * `extractTo` 는 부르지 않는다 — 항목 **내용**을 한 바이트도 읽지 않는다는 뜻이다.
     *
     * 사유 코드: `zip_unreadable` · `zip_bad_path` · `zip_blocked_entry` · `nested_zip` · `zip_bomb`
     * (OOXML 이면 `macro_found` 도 여기서 난다 — ②).
     */
    private static function inspectZip(string $tmpPath, string $ext, int $fileSize): void
    {
        $isOoxml = isset(self::OOXML_MARKERS[$ext]);

        $zip = new ZipArchive();
        if ($zip->open($tmpPath, ZipArchive::RDONLY) !== true) {
            // zip·hwpx 는 열려야 정상이고, OOXML 은 checkMime(ooxmlMime)이 이미 한 번 열었다.
            // 여기서 실패하면 중앙 디렉터리가 깨진 것이므로 「모르는 파일」로 보고 닫는다.
            throw new RuntimeException('zip_unreadable');
        }

        try {
            $count = $zip->numFiles;
            if ($count > self::ZIP_ENTRY_MAX) {
                throw new RuntimeException('zip_bomb');
            }

            $total = 0;
            for ($i = 0; $i < $count; $i++) {
                $stat = $zip->statIndex($i);
                if (!is_array($stat)) {
                    throw new RuntimeException('zip_unreadable');
                }

                $name  = isset($stat['name']) ? (string) $stat['name'] : '';
                $bytes = isset($stat['size']) ? (int) $stat['size'] : 0;
                if ($bytes > 0) {
                    $total += $bytes;
                }

                // 경로는 `\` 를 `/` 로 맞춰 본다 — 윈도 도구가 만든 zip 이 `a\..\b` 로 적는다.
                $norm = str_replace('\\', '/', $name);
                if ($norm === '') {
                    continue;
                }
                if ($norm[0] === '/' || (isset($norm[1]) && $norm[1] === ':')) {
                    // 절대 경로·드라이브 문자(`C:`) — 푸는 쪽을 덮어쓰려는 이름이다
                    throw new RuntimeException('zip_bad_path');
                }
                foreach (explode('/', $norm) as $segment) {
                    if ($segment === '..') {
                        throw new RuntimeException('zip_bad_path');
                    }
                }

                if (substr($norm, -1) === '/') {
                    continue;   // 디렉터리 항목은 이름 검사만 받고 끝난다
                }

                $lower = strtolower($norm);
                $slash = strrpos($lower, '/');
                $leaf  = $slash === false ? $lower : substr($lower, $slash + 1);
                $dot   = strrpos($leaf, '.');
                $entryExt = $dot === false ? '' : substr($leaf, $dot + 1);

                if ($entryExt !== '' && in_array($entryExt, self::BLOCKED_EXT, true)) {
                    throw new RuntimeException('zip_blocked_entry');
                }

                if ($entryExt === 'zip') {
                    // ★ 중첩은 **이름만** 본다. 속 zip 을 열면 그때부터 「푸는 것」이고, 재귀
                    //   깊이만큼 폭탄에 노출된다. 그래서 PRD 의 「3단 이상」을 이름 기준으로
                    //   구현한다 — `a.zip.zip.zip` 처럼 `.zip` 이 3개 이상이면 거부.
                    //   실제 중첩 깊이는 풀지 않고는 알 수 없고, 그 자리는 ClamAV 의
                    //   `--max-recursion`(F-54) 몫이다.
                    if (substr_count($leaf, '.zip') >= 3) {
                        throw new RuntimeException('nested_zip');
                    }
                    // 겉 파일보다 50배 큰 zip 이 안에 들어 있으면 한 겹 더 풀리는 폭탄으로 본다
                    if ($fileSize > 0 && $bytes > $fileSize * 50) {
                        throw new RuntimeException('nested_zip');
                    }
                }

                // ② 오피스 매크로 — 이름만 본다. `[Content_Types].xml` 을 열어 macroEnabled 를
                //    찾는 것도 「항목 내용 읽기」이므로 하지 않는다. docm/xlsm/pptm 은 애초에
                //    ALLOWED_EXT 밖이라, docx 가 매크로 부분을 품고 있으면 그것이 곧 위장이다.
                if ($isOoxml
                    && (str_ends_with($lower, 'vbaproject.bin') || str_ends_with($lower, '/vbadata.xml'))) {
                    throw new RuntimeException('macro_found');
                }
            }

            // 압축 폭탄 — 푼 뒤의 총 크기, 그리고 압축비
            if ($total > self::ZIP_TOTAL_MAX) {
                throw new RuntimeException('zip_bomb');
            }
            if ($total > self::ZIP_RATIO_FLOOR
                && $fileSize > 0 && $total > $fileSize * self::ZIP_RATIO_MAX) {
                throw new RuntimeException('zip_bomb');
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * ③ PDF 능동 내용 — 앞 4MB 와 뒤 4MB 만 바이트로 훑는다 (ARCH 5.27 표 4행).
     *
     * 파일이 8MB 이하이면 한 번에 전부 본다. 그보다 크면 두 조각을 따로 보되, 조각 경계에
     * 걸친 열쇳말을 놓치지 않게 앞 조각의 끝 64바이트를 뒤 조각 앞에 이어 붙인다.
     *
     * 열쇳말은 PDF **이름 객체**라 대소문자를 구분한다(`/JavaScript` ≠ `/javascript`).
     * 짧은 `/JS`·`/AA` 는 뒤에 구분자가 오는 경우만 센다 — 오탐 규칙은 PDF_ACTIVE_RE 에 있다.
     *
     * 한계(SECURITY R-86): 정상 PDF 의 `/OpenAction`(첫 페이지로 이동)도 거부된다. 사유는
     * 감사 `file.upload deny pdf_active_content` 로 남아 관리자가 확인할 수 있다.
     */
    private static function inspectPdf(string $tmpPath, int $fileSize): void
    {
        $handle = @fopen($tmpPath, 'rb');
        if ($handle === false) {
            // 방금 filesize() 로 읽은 파일이라 사실상 나지 않는다. 나면 닫는 쪽으로 끝낸다.
            throw new RuntimeException('size_unreadable');
        }

        try {
            $window = self::PDF_WINDOW;

            if ($fileSize <= $window * 2) {
                if (self::hasPdfActiveKey(self::readSlice($handle, 0, $fileSize))) {
                    throw new RuntimeException('pdf_active_content');
                }
                return;
            }

            $head = self::readSlice($handle, 0, $window);
            if (self::hasPdfActiveKey($head)) {
                throw new RuntimeException('pdf_active_content');
            }

            $tail = substr($head, -self::SCAN_OVERLAP) . self::readSlice($handle, $fileSize - $window, $window);
            if (self::hasPdfActiveKey($tail)) {
                throw new RuntimeException('pdf_active_content');
            }
        } finally {
            fclose($handle);
        }
    }

    /** PDF 능동 내용 열쇳말이 조각 안에 있나 (바이트 그대로, 대소문자 구분) */
    private static function hasPdfActiveKey(string $chunk): bool
    {
        return $chunk !== '' && preg_match(self::PDF_ACTIVE_RE, $chunk) === 1;
    }

    /**
     * ④ HWP OLE 위장 — 휴리스틱으로 「한글 문서인 척하는 OLE」를 가린다 (ARCH 5.27 표 5행).
     *
     *   · 머리 8바이트가 OLE 서명인가
     *   · 어딘가에 OLE 디렉터리 항목 이름 `FileHeader`(UTF-16LE)가 있는가
     *   · 어딘가에 HWP 5.x 의 ASCII 서명 `HWP Document File` 이 있는가
     *   셋을 다 갖춰야 통과한다.
     *
     * OLE 디렉터리·미니 FAT 를 제대로 해석하지 않는 이유: 이 검사의 목적은 `.doc`·`.xls`·`.msi`
     * 같은 **다른 OLE 문서를 `.hwp` 이름으로 바꿔 올린 것**을 잡는 것이고(6단계 검토가
     * 남긴 R-02 관찰), 그 둘은 이 두 문자열을 갖고 있지 않다. 두 문자열을 일부러 심은 OLE 를
     * 만드는 공격자는 어차피 F-54 ClamAV 몫이지 이름·서명 검사의 몫이 아니다.
     * 파서를 직접 쓰면 그 파서가 새 공격면이 된다 — 풀지 않는다는 원칙과도 어긋난다.
     */
    private static function inspectHwpOle(string $tmpPath): void
    {
        $handle = @fopen($tmpPath, 'rb');
        if ($handle === false) {
            throw new RuntimeException('size_unreadable');
        }

        try {
            if (self::readSlice($handle, 0, 8) !== self::OLE_SIGNATURE) {
                throw new RuntimeException('hwp_not_hwp');
            }

            $dirEntry  = "F\x00i\x00l\x00e\x00H\x00e\x00a\x00d\x00e\x00r\x00";
            $signature = 'HWP Document File';
            $foundDir  = false;
            $foundSig  = false;

            if (fseek($handle, 0) !== 0) {
                throw new RuntimeException('hwp_not_hwp');
            }
            $carry = '';
            while (!feof($handle)) {
                $chunk = fread($handle, 1048576);
                if (!is_string($chunk) || $chunk === '') {
                    break;
                }
                $slice = $carry . $chunk;
                if (!$foundDir && strpos($slice, $dirEntry) !== false) {
                    $foundDir = true;
                }
                if (!$foundSig && strpos($slice, $signature) !== false) {
                    $foundSig = true;
                }
                if ($foundDir && $foundSig) {
                    return;
                }
                $carry = substr($slice, -self::SCAN_OVERLAP);
            }

            throw new RuntimeException('hwp_not_hwp');
        } finally {
            fclose($handle);
        }
    }

    /**
     * 열린 파일에서 한 조각을 읽는다 — fread 가 요청보다 적게 주는 경우까지 모아서 돌려준다.
     * 경로가 아니라 **이미 열린 핸들**을 받는다(사용자 입력이 경로에 들어갈 자리가 없다).
     *
     * @param resource $handle
     */
    private static function readSlice($handle, int $offset, int $length): string
    {
        if ($length <= 0 || $offset < 0 || fseek($handle, $offset) !== 0) {
            return '';
        }

        $buffer = '';
        $left   = $length;
        while ($left > 0 && !feof($handle)) {
            $part = fread($handle, $left > 1048576 ? 1048576 : $left);
            if (!is_string($part) || $part === '') {
                break;
            }
            $buffer .= $part;
            $left   -= strlen($part);
        }
        return $buffer;
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
