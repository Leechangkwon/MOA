<?php
declare(strict_types=1);

/**
 * src/Upload.php — 업로드 검증 (PRD S-06, ARCHITECTURE 5.2)
 *
 * ★ 3단계는 **최소 버전**이다. 여기서 하는 것과 하지 않는 것을 분명히 적어 둔다.
 *
 * 3단계에서 하는 것
 *   · $_FILES 한 건의 형태·오류 코드 확인 (is_uploaded_file 포함)
 *   · 크기: 1 byte ~ MAX_BYTES(100MB). nginx(client_max_body_size)·php(post_max_size)에 이어 3중 검사
 *   · 확장자 "형태" 정리: 마지막 '.' 뒤를 소문자로, [a-z0-9]{1,10} 이 아니면 'bin'
 *   · MIME: finfo 로 판별한 실제 MIME 문자열 기록
 *   · sha256 계산 (무결성, PRD S-08)
 *
 * 3단계에서 **하지 않는** 것 (6단계 업로드 하드닝에서 추가)
 *   · 확장자 화이트리스트 (ALLOWED_EXT 는 아직 빈 배열) — T-7 (.php 업로드 차단)
 *   · 확장자 ↔ MIME 짝 검사 — T-8 (shell.php → .pdf 위장 차단)
 *   · 이중 확장자(shell.php.pdf) 전체 검사
 *   · 사용자별 일일 총량(2GB) 제한
 *   · F-14(URL 가져오기)로 받은 파일도 이 함수를 통과하게 만드는 것 (7단계, PRD S-05 ⑩)
 *
 * 실패는 예외로 알린다. 예외 메시지는 **감사 로그용 사유 코드**이며 사용자에게 보이지 않는다
 * (핸들러가 Audit::deny 의 reason 으로만 쓰고, 응답은 Http::MSG_BAD_REQUEST 하나다).
 */
final class Upload
{
    /** 허용 확장자 — 6단계에서 PRD 7.6 표에 맞춰 채운다. 빈 배열 = 아직 검사하지 않음 */
    public const ALLOWED_EXT = [];

    public const MAX_BYTES = 104857600;   // 100MB (PRD S-06)

    /** 확장자 허용 "형태" (내용 화이트리스트가 아니다) */
    private const EXT_RE = '/^[a-z0-9]{1,10}$/';

    /** finfo 결과로 인정하는 MIME 형태. 어긋나면 octet-stream 으로 기록한다 */
    private const MIME_RE = '#^[A-Za-z0-9!\#$&^_.+-]+/[A-Za-z0-9!\#$&^_.+-]+$#';

    private const DEFAULT_MIME = 'application/octet-stream';
    private const DEFAULT_EXT  = 'bin';

    /**
     * $_FILES 한 건을 검증한다. 통과하면 ['ext','mime','size','sha256'] 반환.
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

        // 크기는 클라이언트가 보낸 $file['size'] 를 믿지 않고 실제 파일에서 읽는다.
        $size = filesize($tmp);
        if ($size === false) {
            throw new RuntimeException('size_unreadable');
        }
        if ($size < 1) {
            throw new RuntimeException('empty_file');
        }
        if ($size > self::MAX_BYTES) {
            throw new RuntimeException('too_large');
        }

        $name = $file['name'] ?? null;
        $ext  = self::checkExtension(is_string($name) ? $name : '');
        $mime = self::checkMime($tmp, $ext);

        $sha256 = hash_file('sha256', $tmp);
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
     * 저장에 쓸 확장자를 정한다.
     *
     * 3단계: 마지막 '.' 뒤만 보고 형태(소문자·숫자 1~10자)를 확인한다.
     *   형태가 아니면(확장자 없음·너무 김·특수문자·경로문자) 'bin'.
     * 6단계: ALLOWED_EXT 화이트리스트 + 이중 확장자 전체 검사가 여기 추가된다 (T-7, T-8).
     *
     * 이 값은 저장 이름(UUID.ext)에만 쓰이고, 사용자 파일명은 경로에 들어가지 않는다.
     */
    public static function checkExtension(string $originalName): string
    {
        $base = basename(str_replace('\\', '/', $originalName));
        $pos  = strrpos($base, '.');
        if ($pos === false || $pos === strlen($base) - 1) {
            return self::DEFAULT_EXT;
        }
        $ext = strtolower(substr($base, $pos + 1));
        return preg_match(self::EXT_RE, $ext) === 1 ? $ext : self::DEFAULT_EXT;
    }

    /**
     * finfo 로 판별한 실제 MIME 을 반환한다 (DB `files.mime` 에 기록용).
     *
     * 3단계에는 확장자와의 짝 검사가 없다 — 위장 업로드(T-8) 차단은 6단계다.
     * $ext 는 그 6단계 검사에서 쓰일 인자이므로 지금은 받기만 한다.
     */
    public static function checkMime(string $tmpPath, string $ext): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return self::DEFAULT_MIME;
        }
        $mime = finfo_file($finfo, $tmpPath);
        finfo_close($finfo);

        if (!is_string($mime) || $mime === '' || strlen($mime) > 255
            || preg_match(self::MIME_RE, $mime) !== 1) {
            return self::DEFAULT_MIME;
        }
        return strtolower($mime);
    }
}
