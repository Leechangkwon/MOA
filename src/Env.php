<?php
declare(strict_types=1);

/**
 * src/Env.php — .env 파서 (PRD S-09-6)
 *
 * 라이브러리를 쓰지 않는다. 형식은 아주 좁게만 인정한다:
 *   KEY=VALUE        (KEY 는 A-Z0-9_)
 *   # 로 시작하는 줄, 빈 줄은 무시
 *   VALUE 를 감싼 " 또는 ' 한 쌍은 제거
 * 값 안의 변수 치환·명령 실행 같은 기능은 일부러 넣지 않는다(설정 파일이 코드가 되면 위험).
 *
 * 누락된 키를 get() 하면 예외 → 부팅 실패. "설정이 없는데 기본값으로 조용히 동작"을 막는다.
 */
final class Env
{
    /** @var array<string,string> */
    private static array $vars = [];
    private static bool $loaded = false;

    public static function load(string $path): void
    {
        if (self::$loaded) {
            return;
        }
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('설정 파일(.env)을 읽을 수 없습니다');
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new RuntimeException('설정 파일(.env)을 읽을 수 없습니다');
        }
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($line, 0, $pos));
            $val = trim(substr($line, $pos + 1));
            if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
                continue;
            }
            $len = strlen($val);
            if ($len >= 2) {
                $first = $val[0];
                $last  = $val[$len - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $val = substr($val, 1, $len - 2);
                }
            }
            self::$vars[$key] = $val;
        }
        self::$loaded = true;
    }

    /** 없으면 예외 (부팅 실패) */
    public static function get(string $key): string
    {
        if (!array_key_exists($key, self::$vars)) {
            throw new RuntimeException('필수 설정 값이 없습니다: ' . $key);
        }
        return self::$vars[$key];
    }

    /** 빈 값이 정상인 키(예: FETCH_ALLOWED_HOSTS)용 — 키 자체는 있어야 한다 */
    public static function getOrEmpty(string $key): string
    {
        return self::$vars[$key] ?? '';
    }

    public static function getInt(string $key): int
    {
        $raw = self::get($key);
        if (!preg_match('/^[0-9]+$/', $raw)) {
            throw new RuntimeException('설정 값이 정수가 아닙니다: ' . $key);
        }
        return (int) $raw;
    }
}
