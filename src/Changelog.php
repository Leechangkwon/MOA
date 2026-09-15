<?php
declare(strict_types=1);

/**
 * src/Changelog.php — 지금 돌고 있는 버전과 변경 이력 (PRD F-47, ARCHITECTURE 5.23)
 *
 * 진실은 저장소 루트의 파일 두 개뿐이다.
 *   · `VERSION`        한 줄 `X.Y.Z` — 지금 버전
 *   · `CHANGELOG.json` 배열 한 벌 — 최신이 첫 항목
 * 두 파일을 만드는 것은 `scripts/release.sh` 하나이고, 이 클래스는 **읽기만** 한다.
 *
 * 왜 DB 가 아닌가
 *   버전은 "어떤 코드가 돌고 있나" 이지 "어떤 자료가 들어 있나" 가 아니다. 코드와 함께
 *   커밋·태그로 움직여야 배포본과 어긋나지 않는다. 그래서 표도 새 라우트도 만들지 않았다 —
 *   화면은 이미 통과한 요청의 부산물로 이 값을 같이 받아 간다 (「사용 방법」 5.19 와 같은 방식).
 *
 * 경로에 사용자 입력이 한 글자도 들어가지 않는다
 *   두 경로는 아래 상수 두 개가 전부다. 이름을 요청·환경변수로 바꿀 수 있는 통로가 없으므로
 *   경로 조립(S-06)이 성립할 자리 자체가 없다.
 *
 * 형식이 틀리면 버린다
 *   손으로 고쳐진 파일·중간에 끊긴 파일이 화면을 깨뜨리면 안 된다. 항목 하나가 틀리면
 *   그 항목만 버리고 `error_log` 한 줄을 남긴다. 파일이 없거나 JSON 이 깨졌으면 빈 배열이고,
 *   `VERSION` 이 형식에 맞지 않으면 `0.0.0` 이다 — 화면은 언제나 그려진다.
 *
 * 값을 그리는 쪽(`shell_top.php`·`shell_bottom.php`)이 전부 htmlspecialchars 를 거친다 (PRD S-03).
 */
final class Changelog
{
    /** 지금 버전 한 줄 (저장소 루트). 고정 상수 — 요청 값이 섞일 자리가 없다 */
    private const VERSION_PATH = __DIR__ . '/../VERSION';

    /** 변경 이력 한 벌 (저장소 루트). 역시 고정 상수 */
    private const ENTRIES_PATH = __DIR__ . '/../CHANGELOG.json';

    /** 읽을 수 없을 때 화면에 내보내는 값. 「버전을 모른다」를 뜻하는 자리표다 */
    private const UNKNOWN_VERSION = '0.0.0';

    /** 등급의 한국어 이름. 화면 라벨 표일 뿐이고 권한·판정과 무관하다 */
    private const LEVEL_LABELS = [
        'major' => '대규모',
        'minor' => '중규모',
        'patch' => '수정',
    ];

    /** 같은 요청 안에서만 사는 캐시. 요청이 끝나면 사라진다(정적 속성의 수명이 곧 요청) */
    private static ?string $version = null;

    /** @var array<int, array{version:string, level:string, released_at:string, title:string, changes:array<int,string>}>|null */
    private static ?array $entries = null;

    /**
     * 지금 버전 `X.Y.Z`. 파일이 없거나 형식이 틀리면 `0.0.0` 과 서버 로그 한 줄.
     */
    public static function version(): string
    {
        if (self::$version !== null) {
            return self::$version;
        }

        $raw = @file_get_contents(self::VERSION_PATH);
        if ($raw === false) {
            error_log('[tbox] changelog: VERSION 파일을 읽지 못했다');
            self::$version = self::UNKNOWN_VERSION;
            return self::$version;
        }

        $value = trim($raw);
        if (!self::isVersion($value)) {
            error_log('[tbox] changelog: VERSION 형식이 X.Y.Z 가 아니다');
            $value = self::UNKNOWN_VERSION;
        }

        self::$version = $value;
        return self::$version;
    }

    /**
     * 변경 이력 — 형식이 맞는 항목만, 파일에 적힌 차례대로(최신이 첫 항목).
     *
     * @return array<int, array{version:string, level:string, released_at:string, title:string, changes:array<int,string>}>
     */
    public static function entries(): array
    {
        if (self::$entries !== null) {
            return self::$entries;
        }

        self::$entries = [];

        $raw = @file_get_contents(self::ENTRIES_PATH);
        if ($raw === false) {
            error_log('[tbox] changelog: CHANGELOG.json 파일을 읽지 못했다');
            return self::$entries;
        }

        $list = json_decode($raw, true);
        if (!is_array($list)) {
            error_log('[tbox] changelog: CHANGELOG.json 이 배열이 아니다');
            return self::$entries;
        }

        $dropped = 0;
        foreach ($list as $item) {
            $entry = self::normalize($item);
            if ($entry === null) {
                $dropped++;
                continue;
            }
            self::$entries[] = $entry;
        }

        if ($dropped > 0) {
            error_log('[tbox] changelog: 형식이 틀린 항목 ' . $dropped . '개를 버렸다');
        }

        return self::$entries;
    }

    /**
     * 등급의 한국어 이름. 표에 없는 값이면 빈 문자열 — 화면에 낯선 영어가 그대로 나가지 않게 한다.
     */
    public static function levelLabel(string $level): string
    {
        return self::LEVEL_LABELS[$level] ?? '';
    }

    /** `X.Y.Z` 인가 */
    private static function isVersion(string $value): bool
    {
        return preg_match('/^\d+\.\d+\.\d+$/', $value) === 1;
    }

    /**
     * 항목 하나를 검사해 **필요한 다섯 칸만** 남긴 배열로 돌려준다. 하나라도 틀리면 null.
     * 파일에 있는 다른 칸은 화면으로 나가지 않는다 — 나중에 누가 무엇을 적어 두더라도
     * 그리는 쪽이 받는 것은 여기서 정한 다섯 칸뿐이다.
     *
     * @param  mixed $item
     * @return array{version:string, level:string, released_at:string, title:string, changes:array<int,string>}|null
     */
    private static function normalize($item): ?array
    {
        if (!is_array($item)) {
            return null;
        }

        $version    = isset($item['version']) && is_string($item['version']) ? $item['version'] : '';
        $level      = isset($item['level']) && is_string($item['level']) ? $item['level'] : '';
        $releasedAt = isset($item['released_at']) && is_string($item['released_at']) ? $item['released_at'] : '';
        $title      = isset($item['title']) && is_string($item['title']) ? $item['title'] : '';

        if (!self::isVersion($version)) {
            return null;
        }
        if (!isset(self::LEVEL_LABELS[$level])) {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $releasedAt) !== 1) {
            return null;
        }
        if ($title === '') {
            return null;
        }
        if (!isset($item['changes']) || !is_array($item['changes']) || $item['changes'] === []) {
            return null;
        }

        $changes = [];
        foreach ($item['changes'] as $change) {
            if (!is_string($change) || $change === '') {
                return null;
            }
            $changes[] = $change;
        }

        return [
            'version'     => $version,
            'level'       => $level,
            'released_at' => $releasedAt,
            'title'       => $title,
            'changes'     => $changes,
        ];
    }
}
