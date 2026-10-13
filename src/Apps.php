<?php
declare(strict_types=1);

/**
 * src/Apps.php — Qaredi 앱 목록 한 곳 (Q:F-1, ../docs/launcher/ARCHITECTURE.md 5장)
 *
 * 런처 타일(`/`)과 셸의 그리드 팝업(모든 t-box 화면)이 **같은 목록**을 본다.
 * 목록이 두 벌이면 언젠가 한쪽만 고쳐진다(25단계 CSS 다섯 벌의 교훈).
 * Q:F-12 (DESIGN 2.18) — 셸 맨 왼쪽 앱 아이콘 줄(자료실 · 업무 · 이야기)도 이 목록 한 벌을 순서 그대로 그린다.
 *
 * 진실은 저장소가 추적하는 `config/apps.json` 한 파일이다. 요청 값·DB·`.env` 가 아니다 —
 * 요청이 이 목록에 영향을 주는 통로가 한 군데도 없다.
 *
 * 모양이 어긋나면 **조용히 건너뛰지 않고 예외**를 던진다(→ 500). 위키의 `Wiki::validate` 와
 * 같은 태도다: 항목 하나가 소리 없이 사라져 「왜 타일이 안 보이지」로 남는 것보다,
 * 파일을 고친 사람이 곧바로 알아차리는 편이 낫다.
 */
final class Apps
{
    /** 목록 파일. 코드 상수이며 경로를 조립하지 않는다 (PRD S-06). */
    private const FILE = __DIR__ . '/../config/apps.json';

    /** 항목에 반드시 있어야 하는 키 */
    private const REQUIRED = ['key', 'name', 'desc', 'path', 'icon', 'order'];

    /** 있어도 되는 키 */
    private const OPTIONAL = ['status'];

    /** `status` 에 쓸 수 있는 값. 없으면 「쓸 수 있는 앱」이다. */
    private const STATUSES = ['soon'];

    /**
     * 아이콘 이름 → 24×24 선 아이콘의 path 데이터 (DESIGN.md 2.11).
     *
     * 런처는 **스크립트 0** 이라 셸처럼 `<template>` 을 JS 로 복제할 수 없다. 그래서
     * 서버가 인라인 SVG 를 직접 그리고, 그 path 데이터를 여기 한 벌만 둔다 —
     * 셸의 `<template id="tpl-icon-…">` 와 **같은 모양**이다. 값은 전부 고정 문자열이고
     * 요청 값이 한 글자도 섞이지 않는다.
     */
    public const ICON_PATHS = [
        'library' => 'M4 8V5a2 2 0 0 1 2-2h8l4 4v3M13 3v5h5M3 9h6l2 3h10l-2 8H5a2 2 0 0 1-2-2V9zM7 6h3',
        'community' => 'M13 15H8l-5 4V7a3 3 0 0 1 3-3h8a3 3 0 0 1 3 3v5a3 3 0 0 1-3 3zM8 19h7l5 3V11a3 3 0 0 0-1-2M7 8h6M7 11h4',
        // Q:F-12 (DESIGN 2.18 · WK-10) — 「업무」 앱: 체크 네모(둥근 네모 + 체크). 다른 아이콘과 같은 24×24 선 규격
        'work' => 'M7 3h10a4 4 0 0 1 4 4v10a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4V7a4 4 0 0 1 4-4zM8 12l3 3 5-6',
        'messages' => 'M5 4h14a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H8l-5 3V6a2 2 0 0 1 2-2zM7 9h10M7 13h6',
        'assistant' => 'M12 2l2.5 6.5L21 11l-6.5 2.5L12 20l-2.5-6.5L3 11l6.5-2.5L12 2zM20 17v5M17.5 19.5h5',
        // Q:F-12 U5-3(DESIGN 2.28 「모아 홈 바로가기」) — 「우리 공간」: 사람 셋(가운데 큰 사람 + 양옆 작은 사람). 다른 아이콘과 같은 24×24 선 규격 · 고정 path
        'spaces' => 'M12 4.5a3 3 0 1 1 0 6 3 3 0 1 1 0-6zM6.5 20v-.5a5.5 5.5 0 0 1 11 0v.5'
                  . 'M5.5 7.5a2 2 0 1 1 0 4 2 2 0 1 1 0-4zM2 18.5a3.5 3.5 0 0 1 4.3-3.4'
                  . 'M18.5 7.5a2 2 0 1 1 0 4 2 2 0 1 1 0-4zM22 18.5a3.5 3.5 0 0 0-4.3-3.4',
        // 셸의 tpl-icon-shield 와 같은 path
        'shield' => 'M12 2 4 5v6c0 5 3.4 9.4 8 11 4.6-1.6 8-6 8-11V5z',
        // 셸의 tpl-icon-grid 와 같은 path (3×3 사각형 — 앱 그리드)
        'grid'   => 'M3 3h5v5H3zM9.5 3h5v5h-5zM16 3h5v5h-5z'
                  . 'M3 9.5h5v5H3zM9.5 9.5h5v5h-5zM16 9.5h5v5h-5z'
                  . 'M3 16h5v5H3zM9.5 16h5v5h-5zM16 16h5v5h-5z',
    ];

    /** 한 요청 안에서 파일을 두 번 읽지 않는다 (요청마다 새로 읽으므로 캐시가 낡지 않는다) */
    private static ?array $cache = null;

    /**
     * 앱 목록을 `order` 오름차순으로 돌려준다.
     *
     * @return list<array{key:string,name:string,desc:string,path:string,icon:string,order:int,status?:string}>
     */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $raw = @file_get_contents(self::FILE);
        if ($raw === false) {
            throw new RuntimeException('앱 목록 파일을 읽을 수 없습니다');
        }

        $list = json_decode($raw, true);
        if (!is_array($list) || $list === [] || array_keys($list) !== range(0, count($list) - 1)) {
            throw new RuntimeException('앱 목록이 배열이 아닙니다');
        }

        $seen = [];
        $apps = [];
        foreach ($list as $i => $item) {
            $apps[] = self::validate($item, 'apps[' . $i . ']', $seen);
        }

        usort($apps, static function (array $a, array $b): int {
            return $a['order'] <=> $b['order'];
        });

        self::$cache = $apps;
        return $apps;
    }

    /**
     * 항목 하나의 모양 검사. 어긋나면 예외다 — 조용히 건너뛰지 않는다.
     *
     * @param  mixed                $item
     * @param  array<string,true>   $seen  이미 나온 key (참조로 누적)
     * @return array<string,mixed>
     */
    private static function validate($item, string $where, array &$seen): array
    {
        if (!is_array($item)) {
            throw new RuntimeException($where . ': 항목이 객체가 아닙니다');
        }

        foreach (array_keys($item) as $k) {
            if (!in_array($k, self::REQUIRED, true) && !in_array($k, self::OPTIONAL, true)) {
                throw new RuntimeException($where . ': 모르는 키 ' . (string) $k);
            }
        }
        foreach (self::REQUIRED as $k) {
            if (!array_key_exists($k, $item)) {
                throw new RuntimeException($where . ': 키가 없습니다 — ' . $k);
            }
        }

        if (!is_string($item['key']) || preg_match('/^[a-z][a-z0-9-]{0,31}$/', $item['key']) !== 1) {
            throw new RuntimeException($where . ': key 형태가 아닙니다');
        }
        if (isset($seen[$item['key']])) {
            throw new RuntimeException($where . ': key 가 중복입니다 — ' . $item['key']);
        }
        $seen[$item['key']] = true;

        foreach (['name', 'desc'] as $k) {
            if (!is_string($item[$k]) || $item[$k] === '') {
                throw new RuntimeException($where . ': ' . $k . ' 가 비어 있습니다');
            }
        }

        // 타일의 링크가 되는 값이다. `/` 로 시작해야 하고 `//`(스킴 상대 주소)는 안 된다 —
        // 목록 파일이 바뀌어도 남의 출처로 가는 링크가 생기지 않는다.
        if (!is_string($item['path']) || $item['path'] === '' || $item['path'][0] !== '/'
            || strncmp($item['path'], '//', 2) === 0
            || preg_match('#^/[a-z0-9/_.-]*$#', $item['path']) !== 1
        ) {
            throw new RuntimeException($where . ': path 가 허용 형태가 아닙니다');
        }

        if (!is_string($item['icon']) || !array_key_exists($item['icon'], self::ICON_PATHS)) {
            throw new RuntimeException($where . ': 모르는 아이콘입니다');
        }

        if (!is_int($item['order'])) {
            throw new RuntimeException($where . ': order 가 정수가 아닙니다');
        }

        if (array_key_exists('status', $item)
            && (!is_string($item['status']) || !in_array($item['status'], self::STATUSES, true))
        ) {
            throw new RuntimeException($where . ': 모르는 status 입니다');
        }

        return $item;
    }
}
