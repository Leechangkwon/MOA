<?php
declare(strict_types=1);

/**
 * src/Storage.php — 실제 파일 저장·읽기 (ARCHITECTURE 6.3, PRD S-06)
 *
 * ★ 1단계에서는 **골격만** 둔다. 본문은 6단계(업로드 하드닝)에서 채운다.
 *   지금 로직을 넣지 않는 이유: 검증되지 않은 파일 경로 코드가 저장소에 먼저 들어오면
 *   "일단 돌아가니까" 그대로 남을 위험이 있다. 호출하면 예외로 즉시 실패한다.
 *
 * 6단계에서 지킬 규칙 (ARCH 6.3 표)
 *   1) 저장 이름은 서버가 만든 UUID + 화이트리스트 확장자만. 사용자 파일명은 한 글자도 경로에 넣지 않는다.
 *   2) 경로는 STORAGE_DIR(.env 고정값) + UUID 앞 2글자/다음 2글자 하위 폴더로만 조립한다.
 *   3) 조립한 절대 경로가 realpath 로 STORAGE_DIR 안에 있는지 다시 확인한다(경로 탈출 방어, T-11).
 *   4) 디렉터리 0750 / 파일 0640, 소유자 tbox-php. 실행 권한 없음.
 *   5) 다운로드는 nginx X-Accel-Redirect(/protected/) 로 넘긴다 — 권한 검사를 통과한 뒤에만.
 */
final class Storage
{
    private const NOT_IMPLEMENTED = '아직 구현되지 않았습니다';

    /** UUID 로 만든 stored_name 을 저장소 안 절대 경로로 바꾼다 (사용자 입력 불가) */
    public static function pathFor(string $storedName): string
    {
        throw new RuntimeException(self::NOT_IMPLEMENTED);
    }

    /** 업로드 임시 파일을 저장소로 옮긴다. 성공 시 stored_name 반환 */
    public static function put(string $tmpPath, string $ext): string
    {
        throw new RuntimeException(self::NOT_IMPLEMENTED);
    }

    /** 권한 검사를 통과한 파일을 nginx 내부 경로로 넘긴다 (X-Accel-Redirect) */
    public static function stream(string $storedName, string $originalName, string $mime): void
    {
        throw new RuntimeException(self::NOT_IMPLEMENTED);
    }

    /** 30일 지난 논리 삭제 파일의 실제 바이트 제거 (배치, tbox_owner 권한) */
    public static function purge(string $storedName): void
    {
        throw new RuntimeException(self::NOT_IMPLEMENTED);
    }

    /** 저장된 파일의 SHA-256 (정합성 검사, PRD S-08) */
    public static function sha256(string $storedName): string
    {
        throw new RuntimeException(self::NOT_IMPLEMENTED);
    }
}
