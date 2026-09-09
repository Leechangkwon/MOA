<?php
declare(strict_types=1);

/**
 * src/Upload.php — 업로드 검증 (PRD S-06, ARCHITECTURE 5.2)
 *
 * ★ 1단계에서는 **골격만** 둔다. 본문은 6단계(업로드 하드닝)에서 채운다.
 *   호출하면 예외로 즉시 실패한다 — 검증을 건너뛴 업로드가 동작하는 상태를 만들지 않는다.
 *
 * 6단계에서 지킬 규칙 (PRD 7.6)
 *   1) 크기: 100MB 초과 거부 (nginx client_max_body_size + php post_max_size + 앱 검사, 3중)
 *   2) 확장자: 허용 목록만. 이중 확장자(shell.php.pdf)는 마지막 것만 보지 않고 전체를 검사
 *   3) MIME: finfo 로 판별한 실제 MIME 과 확장자가 짝이 맞아야 한다 (T-8 위장 업로드 차단)
 *   4) 이름: 저장 이름은 서버가 만든 UUID (Storage::put). 원본 이름은 DB 에만
 *   5) 결과는 성공/실패 모두 감사 로그 (PRD S-07)
 *   6) F-14(URL 가져오기)로 받은 파일도 **같은 함수**를 통과해야 한다 (PRD S-05 ⑩)
 */
final class Upload
{
    private const NOT_IMPLEMENTED = '아직 구현되지 않았습니다';

    /** 허용 확장자 — 6단계에서 PRD 7.6 표에 맞춰 채운다 */
    public const ALLOWED_EXT = [];

    public const MAX_BYTES = 104857600;   // 100MB (PRD S-06)

    /**
     * $_FILES 한 건을 검증한다. 통과하면 ['ext'=>..,'mime'=>..,'size'=>..,'sha256'=>..] 반환,
     * 실패하면 예외(사유는 감사 로그용 코드 문자열).
     *
     * @param array<string,mixed> $file $_FILES['file'] 한 건
     * @return array<string,mixed>
     */
    public static function validate(array $file): array
    {
        throw new RuntimeException(self::NOT_IMPLEMENTED);
    }

    /** 확장자 화이트리스트 검사 (이중 확장자 포함) */
    public static function checkExtension(string $originalName): string
    {
        throw new RuntimeException(self::NOT_IMPLEMENTED);
    }

    /** finfo 로 판별한 실제 MIME 이 확장자와 짝이 맞는지 (T-8) */
    public static function checkMime(string $tmpPath, string $ext): string
    {
        throw new RuntimeException(self::NOT_IMPLEMENTED);
    }
}
