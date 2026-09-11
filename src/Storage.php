<?php
declare(strict_types=1);

/**
 * src/Storage.php — 실제 파일 저장·읽기 (ARCHITECTURE 6.3, PRD S-06)
 *
 * 이 파일이 저장소 경로를 만드는 **유일한 곳**이다. 밖에서는 경로 문자열을 조립하지 않는다.
 *
 * 지키는 규칙 (ARCH 6.3 표)
 *   1) 저장 이름은 서버가 만든 UUIDv4 + 확장자만. 사용자 파일명은 한 글자도 경로에 넣지 않는다.
 *      (원본 파일명은 DB `files.original_name` 에만 있고, 다운로드 헤더에서 인코딩되어 쓰인다)
 *   2) 경로는 STORAGE_DIR(.env 고정값) + UUID 앞 2글자/다음 2글자 하위 폴더로만 조립한다.
 *   3) 조립한 절대 경로가 realpath 로 STORAGE_DIR 안에 있는지 다시 확인한다(경로 탈출 방어, T-11).
 *   4) 디렉터리 0750 / 파일 0640, 소유자 tbox-php. 실행 권한 없음.
 *   5) 다운로드 전송 방식 — **PHP readfile() 확정** (6단계 결정 2026-09-11, ARCH 6.3 · SECURITY R-34).
 *      X-Accel-Redirect 는 쓰지 않는다. nginx(www-data)가 저장소를 읽을 필요가 없으므로
 *      install.sh 가 /var/lib/tbox-v2 · storage 의 그룹을 www-data → tbox-php 로 회수했다
 *      (같은 VM 의 취약판 v1 이 www-data 로 돌기 때문에 그룹 읽기 = v1 웹셸의 읽기였다).
 *      nginx `/protected/` `internal` 블록은 404 보장용으로만 남는다 (T-10).
 *   6) 다운로드 전 sha256 대조는 핸들러(files_download)가 이 클래스의 sha256() 으로 한다 (R-13).
 *
 *   7) purge()(30일 휴지통 실제 삭제) 는 9단계 배치 scripts/purge_trash.php 가 부른다.
 *      DB 행은 지우지 않고 files.purged_at 만 찍는다 — 감사 추적을 끊지 않기 위해서다 (ARCH 11.1).
 */
final class Storage
{
    /** stored_name 의 UUID 부분 (UUIDv4 만) */
    private const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    /** stored_name 전체 = UUIDv4 + '.' + 소문자·숫자 확장자 */
    private const STORED_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\.[a-z0-9]{1,10}$/';

    /** 확장자 허용 형태 (내용 화이트리스트는 Upload::ALLOWED_EXT — 6단계) */
    private const EXT_RE = '/^[a-z0-9]{1,10}$/';

    private const DIR_MODE  = 0750;
    private const FILE_MODE = 0640;

    /**
     * 저장소 루트. .env 의 STORAGE_DIR 고정값이며 요청 값이 섞이지 않는다.
     * 없거나 디렉터리가 아니면 예외 — "조용히 다른 곳에 저장"되는 경로를 만들지 않는다.
     */
    public static function baseDir(): string
    {
        $configured = Env::get('STORAGE_DIR');
        $real       = realpath($configured);
        if ($real === false || !is_dir($real)) {
            throw new RuntimeException('저장소 디렉터리를 찾을 수 없습니다');
        }
        return $real;
    }

    /**
     * 가져오기(F-14) 임시 디렉터리 — 저장소의 **형제** 디렉터리 `/var/lib/tbox-v2/tmp` 다 (7단계).
     *
     * 경로를 상수로 적지 않고 `STORAGE_DIR` 의 부모에서 만드는 이유: 설정이 가리키는 곳과
     * 다른 디스크에 임시파일이 생기면 putLocal() 의 rename() 이 파일시스템을 넘어야 하고,
     * open_basedir 밖으로 나갈 수도 있다. 디렉터리는 deploy/install.sh 가 0700 tbox-php 로 만든다
     * (php-fpm 의 open_basedir 에 /var/lib/tbox-v2 가 이미 들어 있다 — ARCH 9장).
     * 없으면 예외 — "조용히 /tmp 에 떨어지는" 경로를 만들지 않는다.
     */
    public static function tmpDir(): string
    {
        $real = realpath(dirname(self::baseDir()) . '/tmp');
        if ($real === false || !is_dir($real)) {
            throw new RuntimeException('임시 디렉터리를 찾을 수 없습니다');
        }
        return $real;
    }

    /**
     * 새 저장 이름을 만든다. 이름을 만드는 곳은 여기 하나뿐이다.
     * $ext 는 Upload::checkExtension() 을 통과한 값이지만, 여기서도 형태를 다시 검사한다.
     */
    public static function newStoredName(string $ext): string
    {
        if (preg_match(self::EXT_RE, $ext) !== 1) {
            throw new RuntimeException('허용되지 않는 확장자 형태입니다');
        }
        return tbox_uuid_v4() . '.' . $ext;
    }

    /**
     * stored_name 을 저장소 안 절대 경로로 바꾼다 (사용자 입력 불가).
     *
     * 이중 방어
     *   ① stored_name 이 "UUIDv4.확장자" 형태인지 정규식 검사 — `..`·`/` 는 통과할 수 없다.
     *   ② 상위 폴더가 이미 있으면 realpath 결과가 baseDir 안인지 다시 확인.
     */
    public static function pathFor(string $storedName): string
    {
        if (preg_match(self::STORED_RE, $storedName) !== 1) {
            throw new RuntimeException('허용되지 않는 저장 이름입니다');
        }
        $base = self::baseDir();
        $dir  = $base . '/' . substr($storedName, 0, 2) . '/' . substr($storedName, 2, 2);

        if (is_dir($dir)) {
            $realDir = realpath($dir);
            if ($realDir === false || strpos($realDir . '/', $base . '/') !== 0) {
                throw new RuntimeException('저장소 밖의 경로입니다');
            }
            $dir = $realDir;
        }
        return $dir . '/' . $storedName;
    }

    /**
     * 실제 바이트가 있는지 확인한다. 다운로드 핸들러는 감사 로그(allow)를 남기기 **전에**
     * 이것을 부른다 — 파일이 없는데 "다운로드 성공" 이 기록되는 일을 막는다.
     */
    public static function assertExists(string $storedName): void
    {
        $path = self::pathFor($storedName);
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('저장된 파일을 찾을 수 없습니다');
        }
    }

    /**
     * 업로드 임시 파일을 저장소로 옮긴다. 성공 시 저장된 절대 경로를 반환한다.
     * 실패는 예외 — 호출자(files_upload.php)가 트랜잭션을 롤백한다.
     *
     * $storedName 은 newStoredName() 이 만든 값만 넘긴다.
     */
    public static function put(string $tmpPath, string $storedName): string
    {
        if (!is_uploaded_file($tmpPath)) {
            // HTTP 업로드로 들어온 임시 파일이 아니면 옮기지 않는다.
            throw new RuntimeException('업로드된 파일이 아닙니다');
        }
        $path = self::pathFor($storedName);
        $dir  = dirname($path);

        if (!is_dir($dir) && !mkdir($dir, self::DIR_MODE, true) && !is_dir($dir)) {
            throw new RuntimeException('저장 폴더를 만들 수 없습니다');
        }
        if (!move_uploaded_file($tmpPath, $path)) {
            throw new RuntimeException('파일을 저장소로 옮길 수 없습니다');
        }
        if (!chmod($path, self::FILE_MODE)) {
            throw new RuntimeException('파일 권한을 설정할 수 없습니다');
        }
        return $path;
    }

    /**
     * **가져온** 임시 파일을 저장소로 옮긴다 (7단계 F-14, PRD S-05 ⑨ 이후 저장 단계).
     *
     * put() 과 다른 점은 출처 검사 한 가지뿐이다. 가져온 파일은 HTTP 업로드가 아니므로
     * `is_uploaded_file()` 이 참일 수 없다 — 대신 **임시 디렉터리(tmpDir) 안의 실제 파일인지**
     * realpath 로 확인한다. realpath 는 심볼릭 링크를 풀어 주므로, 링크로 저장소 밖(예 /etc/passwd)을
     * 가리키는 파일을 넘겨도 여기서 걸린다. 그래서 이 함수에 넘길 수 있는 경로는
     * 사실상 tempnam(Storage::tmpDir(), …) 이 만든 것뿐이다.
     *
     * is_uploaded_file 검사를 그냥 지우고 put() 을 재사용하지 않는 이유: 그러면 **업로드 경로의
     * 보호까지 함께 풀린다.** 두 입구는 각자의 출처 검사를 갖고, 그다음 규칙(경로 조립·권한·검증)은
     * 공유한다.
     */
    public static function putLocal(string $tmpPath, string $storedName): string
    {
        $tmpDir = self::tmpDir();
        $real   = realpath($tmpPath);
        if ($real === false || !is_file($real)) {
            throw new RuntimeException('임시 파일을 찾을 수 없습니다');
        }
        if (strpos($real, $tmpDir . '/') !== 0) {
            throw new RuntimeException('임시 디렉터리 밖의 파일입니다');
        }

        $path = self::pathFor($storedName);
        $dir  = dirname($path);

        if (!is_dir($dir) && !mkdir($dir, self::DIR_MODE, true) && !is_dir($dir)) {
            throw new RuntimeException('저장 폴더를 만들 수 없습니다');
        }
        if (!rename($real, $path)) {
            throw new RuntimeException('파일을 저장소로 옮길 수 없습니다');
        }
        if (!chmod($path, self::FILE_MODE)) {
            throw new RuntimeException('파일 권한을 설정할 수 없습니다');
        }
        return $path;
    }

    /**
     * 옮겨 둔 파일을 되돌린다 (업로드 트랜잭션 롤백용).
     * purge()(30일 휴지통 실제 삭제)와는 다른 용도다 — 방금 이 요청이 만든 파일만 지운다.
     */
    public static function removeUploaded(string $storedName): void
    {
        $path = self::pathFor($storedName);
        if (is_file($path)) {
            unlink($path);
        }
    }

    /**
     * 권한 검사를 통과한 파일을 응답으로 내려 준다 (ARCH 5.3 [8]).
     *
     * 전송은 readfile() 로 PHP 가 직접 한다 — 6단계 확정이다(위 클래스 주석 5번, R-34).
     * $mime 은 DB 에 기록된 판별 결과이며 **응답 Content-Type 으로 쓰지 않는다** —
     * 업로드된 HTML·SVG 가 브라우저에서 렌더링되는 저장형 XSS 를 막기 위해
     * 항상 application/octet-stream + attachment 로 내린다 (bootstrap 의 nosniff 와 함께).
     */
    public static function stream(string $storedName, string $originalName, string $mime): void
    {
        $path = self::pathFor($storedName);
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('저장된 파일을 찾을 수 없습니다');
        }
        $size = filesize($path);
        if ($size === false) {
            throw new RuntimeException('파일 크기를 읽을 수 없습니다');
        }

        // 헤더에 들어가는 이름은 두 가지 모두 CR/LF·제어문자가 제거된 값이다(헤더 인젝션 방어).
        $header = self::headerName($originalName);

        if (!headers_sent()) {
            header('Content-Type: application/octet-stream');
            header('Content-Length: ' . $size);
            header('Content-Disposition: attachment; filename="' . self::asciiName($header)
                . '"; filename*=UTF-8\'\'' . rawurlencode($header));
            header('Cache-Control: no-store');
        }

        // 버퍼를 비우고(부분 출력이 섞이지 않게) 바로 전송한다. $path 는 위에서 만든 저장소 경로다.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        readfile($path);
        exit;
    }

    /**
     * 미리보기 원본을 **inline** 으로 내려 준다 (12-2 F-18, ARCH 5.8 [5-raw], SECURITY R-41).
     *
     * stream() 과 다른 것은 **헤더뿐**이다 — 경로 검사·버퍼 비우기·readfile·exit 는 같다.
     * stream() 은 한 글자도 고치지 않는다: 다운로드의 "항상 첨부" 규칙(PRD F-06, S-03)은
     * 그대로 두고, inline 은 **별도 경로**로만 존재한다.
     *
     * inline 이 위험한 이유와 그 대응 (R-41)
     *   · PDF 는 내장 스크립트·폼·외부 링크를 가질 수 있고, 이미지는 형식 재해석(sniffing)의
     *     표적이 된다. 같은 출처에서 렌더되면 사이트 쿠키·DOM 에 닿을 가능성이 생긴다.
     *   · 그래서 ① 형식은 **DB `mime`** (업로드 때 finfo 로 확정한 값)만 받고, 방어적으로
     *     여기서 다시 image/png·jpeg·gif·application/pdf 인지 확인한다 — 아니면 예외다.
     *     ② `Content-Security-Policy: sandbox; default-src 'none'; frame-ancestors 'self'` 를 붙인다.
     *     sandbox 가 붙은 응답을 브라우저는 **불투명 출처(opaque origin)** 로 다루므로, 문서 안
     *     스크립트가 t-box 출처·쿠키·DOM 에 닿을 수 없다. ③ `nosniff` 로 형식 재해석을 막고
     *     ④ `no-store` 로 캐시에 남기지 않으며 ⑤ `no-referrer` 로 주소를 흘리지 않는다.
     *
     *   · **프레임 허용은 이 응답 하나뿐이다** (12-2 보정, 2026-09-12 Fable 실측).
     *     사이트 기본값은 `frame-ancestors 'none'` + `X-Frame-Options: DENY` 라 **같은 출처의
     *     `<iframe>` 까지** 막혀, 미리보기 모달이 PDF 를 전혀 띄우지 못했다. 그래서 이 응답만
     *     `frame-ancestors 'self'` 로 좁혀 열고 `X-Frame-Options: SAMEORIGIN` 으로 덮어쓴다
     *     (`header()` 는 같은 이름을 **교체**하므로 bootstrap 의 DENY 가 사라진다).
     *     남의 사이트가 이 응답을 끼우는 것은 `frame-ancestors 'self'` 가 그대로 막고,
     *     끼운 문서 안에서 할 수 있는 일은 `sandbox` 가 전부 끈다. 다른 모든 경로(화면·API·
     *     다운로드)의 `frame-ancestors 'none'`·`DENY` 는 **그대로다**.
     *
     * $mime 은 `Upload::MIME_BY_EXT` 를 통과해 DB 에 기록된 값만 들어온다는 전제이지만,
     * 그 전제가 깨져도(DB 침해 등) 아래 정규식이 막는다.
     */
    public static function streamInline(string $storedName, string $originalName, string $mime): void
    {
        if (preg_match('#^(image/(png|jpeg|gif)|application/pdf)$#', $mime) !== 1) {
            throw new RuntimeException('미리보기로 보낼 수 없는 형식입니다');
        }

        $path = self::pathFor($storedName);
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('저장된 파일을 찾을 수 없습니다');
        }
        $size = filesize($path);
        if ($size === false) {
            throw new RuntimeException('파일 크기를 읽을 수 없습니다');
        }

        // 헤더에 들어가는 이름은 stream() 과 같은 두 함수를 거친 값이다(헤더 인젝션 방어).
        $header = self::headerName($originalName);

        if (!headers_sent()) {
            header('Content-Type: ' . $mime);
            header('Content-Length: ' . $size);
            header('Content-Disposition: inline; filename="' . self::asciiName($header)
                . '"; filename*=UTF-8\'\'' . rawurlencode($header));
            header("Content-Security-Policy: sandbox; default-src 'none'; frame-ancestors 'self'");
            // bootstrap(4번 블록)의 `X-Frame-Options: DENY` 를 **교체**한다 — 같은 출처의
            // 미리보기 모달이 이 응답을 <iframe sandbox> 로 끼울 수 있어야 하기 때문이다.
            header('X-Frame-Options: SAMEORIGIN');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store');
            header('Referrer-Policy: no-referrer');
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        readfile($path);
        exit;
    }

    /** 헤더에 쓸 원본 이름 — 제어문자·CR/LF·경로 구분자 제거 (다운로드 헤더 인젝션 방어) */
    private static function headerName(string $originalName): string
    {
        $name = str_replace('\\', '/', $originalName);
        $name = basename($name);
        $name = (string) preg_replace('/[\x00-\x1f\x7f]+/', '', $name);
        $name = trim($name);
        return $name === '' ? 'download' : $name;
    }

    /**
     * filename= 에 쓸 ASCII 대체명. [A-Za-z0-9._-] 외 문자는 '_' 로 바꾼다.
     * 한글만으로 된 이름은 알파벳·숫자가 하나도 남지 않으므로 'download' 로 대체한다
     * (filename* 쪽에 UTF-8 원본명이 그대로 들어가므로 최신 브라우저는 원래 이름으로 저장한다).
     */
    private static function asciiName(string $name): string
    {
        $ascii = (string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $name);
        $ascii = (string) preg_replace('/_+/', '_', $ascii);
        $ascii = trim($ascii, '_');
        if ($ascii === '' || preg_match('/[A-Za-z0-9]/', $ascii) !== 1) {
            return 'download';
        }
        if (strpos($ascii, '.') === 0) {
            $ascii = 'download' . $ascii;   // 앞이 '.' 이면 숨김파일처럼 보인다
        }
        return substr($ascii, 0, 100);
    }

    /**
     * 30일 지난 논리 삭제 파일의 실제 바이트 제거 (배치, 9단계 scripts/purge_trash.php).
     *
     * 경로는 pathFor() 하나로만 만든다 — 여기도 "UUIDv4.확장자" 형태 검사와 realpath 확인을
     * 그대로 통과해야 하므로, 배치가 DB 에서 읽은 stored_name 이 어떤 값이든 저장소 밖의
     * 파일을 지울 수 없다(DB 가 침해돼 stored_name 에 `../` 가 들어가도 예외로 끝난다).
     *
     * 파일이 이미 없으면 **성공으로 본다**. 배치가 중간에 죽었다 다시 돌거나, 사람이 먼저
     * 지웠거나, 업로드 롤백으로 바이트만 없는 행일 수 있다 — 그 상태의 목표는 "바이트가 없다"
     * 이고 이미 달성돼 있다. 여기서 예외를 던지면 그 행은 영원히 `purged_at` 이 안 찍혀
     * 매일 같은 실패를 반복한다.
     *
     * 지우지 못한 경우(권한·I/O)는 예외다 — 호출자가 `file.purge error purge_failed` 로 남긴다.
     */
    public static function purge(string $storedName): void
    {
        $path = self::pathFor($storedName);
        if (!file_exists($path)) {
            return;                      // 이미 없다 = 목표 달성
        }
        if (!is_file($path)) {
            throw new RuntimeException('저장소 경로가 일반 파일이 아닙니다');
        }
        if (!unlink($path)) {
            throw new RuntimeException('저장된 파일을 지울 수 없습니다');
        }
    }

    /** 저장된 파일의 SHA-256 (정합성 검사, PRD S-08) */
    public static function sha256(string $storedName): string
    {
        $hash = hash_file('sha256', self::pathFor($storedName));
        if ($hash === false) {
            throw new RuntimeException('파일 해시를 계산할 수 없습니다');
        }
        return $hash;
    }

    /** stored_name 이 허용 형태인지 (호출자 검증용) */
    public static function isValidStoredName(string $storedName): bool
    {
        return preg_match(self::STORED_RE, $storedName) === 1;
    }

    /** 외부 노출 ID(public_id) 가 UUIDv4 형태인지 — 핸들러가 경로 변수를 검사할 때 쓴다 */
    public static function isValidUuid(string $value): bool
    {
        return preg_match(self::UUID_RE, $value) === 1;
    }
}
