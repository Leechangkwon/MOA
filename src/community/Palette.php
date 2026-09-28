<?php
declare(strict_types=1);

/**
 * src/community/Palette.php — 커뮤니티 7-D2 관리자 팔레트의 **순수 계산** (C:F-33 · 결정 L-2 · 지시서 1.1 P-2~P-7 · 1.4)
 *
 * 하는 일(전부 입력 → 출력 · DB·요청·파일을 읽지 않는다)
 *   · 이름·값 모양 — 이름은 `Doc::isPaletteName`(검증기와 같은 규칙) + 예약 이름(기본 9색 · `none`) · 값은 `#rrggbb` 소문자
 *   · 배경값 — 등록값과 화면 바탕을 sRGB 채널마다 **정수 선형 보간**(라이트 12 : 88 · 다크 24 : 76 — DESIGN 2.14 「라이트 12%·다크 24%」).
 *     브라우저 `color-mix()` 에 기대지 않는다(옛 폰). 시험(`tests/community/stage7d2.sh` C:T-205)이 파이썬으로 같은 공식을 계산해 글자로 대조한다.
 *   · 버전 — 살아 있는 줄(이름순 `name|light|dark` 를 줄바꿈으로 이음)의 sha256 앞 12자리. 셸의 `?v=` 와 CSS 창구의 `ETag` 가 같은 값(P-7 — 내용에서)
 *   · CSS 글자 — `:root` 변수 + 다크 두 곳(같은 값 — DESIGN 2.12) + 클래스 `.pal-<이름>`·`.pal-<이름>-bg` (1.4 모양)
 *
 * 누가 부르나: `src/community/routes.php`(`community_palette_*`) · 핸들러 `palettes_*.php` · `palette_css.php`.
 * ★ 판정 로직 없음 — 누가 고칠 수 있나는 라우트 정책(`role:Admin` + 재인증)이 정한다.
 * ★ 사용자 값이 CSS 에 그대로 들어가는 자리가 없다 — `css()` 는 모양 검사를 지난 이름·값만 이어 붙이고, 지나지 못한 줄은 건너뛴다.
 */
final class Palette
{
    /** 살아 있는 줄의 상한 (P-16 · L-2) */
    public const MAX_LIVE = 12;

    /**
     * 배경을 섞을 화면 바탕 — `public/assets/app.css` 의 `--bg`(라이트 `#ffffff` · 다크 zinc-950 `#09090b`, DESIGN 1.1 · 2.12).
     * 화면에 그리는 색이 아니라 **계산의 재료**다. 시험이 app.css 두 다크 블록의 `--bg` 와 이 값이 같은지 본다(C:T-205).
     */
    public const LIGHT_BG = '#ffffff';
    public const DARK_BG  = '#09090b';

    /** 섞는 비율(등록값 몫, 백분율) — P-4 */
    public const LIGHT_WEIGHT = 12;
    public const DARK_WEIGHT  = 24;

    /**
     * 예약 이름 — 기본 9색 이름(허용 목록 파일 `doc-schema.json` 의 `colors` 에서 읽는다 — 목록이 두 곳에 있지 않게)과 `none`.
     * 편집기 색 줄에 「red」가 둘 보이지 않게 하는 좁은 쪽 기본안(P-2). DB CHECK 에는 넣지 않는다(1.2).
     *
     * @return list<string>
     */
    public static function reserved(): array
    {
        $out = [];
        foreach (Doc::schema()['colors'] as $c) {
            $out[] = (string) $c;
        }
        $out[] = 'none';
        return $out;
    }

    /**
     * 등록할 이름의 문제 — null 이면 통과. 이름은 **바꾸지 않고** 본다(대문자·한글·빈칸은 고쳐 주지 않고 `bad_name`).
     *
     * @param mixed $name
     */
    public static function nameProblem($name): ?string
    {
        if (!is_string($name) || !Doc::isPaletteName($name)) {
            return 'bad_name';
        }
        if (in_array($name, self::reserved(), true)) {
            return 'reserved';
        }
        return null;
    }

    /**
     * 받은 색 값 → 저장할 값. 앞뒤 빈칸을 떼고 소문자로 맞춘 뒤 `#rrggbb` 이면 그 값, 아니면 null (P-3 — 7-C 호스트와 같은 태도).
     * `#05a`(3자리) · `blue`(이름) · `rgb(…)` · `0055aa`(# 없음) · `#0055aag` 는 null.
     *
     * @param mixed $value
     */
    public static function normColor($value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $c = strtolower(trim($value));
        return self::isColor($c) ? $c : null;
    }

    /** 저장된 값의 모양 — `#` + 소문자 16진 6자리 (그릴 때 한 번 더 보는 자리) */
    public static function isColor(string $value): bool
    {
        return preg_match('/^#[0-9a-f]{6}$/D', $value) === 1;
    }

    /**
     * 줄 하나가 그릴 수 있는 모양인가 — 이름(모양 · 예약 아님) · 라이트 · 다크.
     *
     * @param array<string,mixed> $row
     */
    public static function rowOk(array $row): bool
    {
        return isset($row['name'], $row['light'], $row['dark'])
            && is_string($row['name']) && is_string($row['light']) && is_string($row['dark'])
            && self::nameProblem($row['name']) === null
            && self::isColor($row['light']) && self::isColor($row['dark']);
    }

    /**
     * 두 색을 sRGB 채널마다 정수로 섞는다 — 채널 = (값 × w + 바탕 × (100 − w) + 50) ÷ 100 (정수 나눗셈 · 반올림).
     * 두 값 모두 `#rrggbb` 소문자여야 한다(아니면 예외 — 조용히 다른 색을 내지 않는다).
     */
    public static function tint(string $color, string $base, int $weight): string
    {
        if (!self::isColor($color) || !self::isColor($base) || $weight < 0 || $weight > 100) {
            throw new InvalidArgumentException('팔레트 색 섞기 — 모양 밖 값');
        }
        $out = '#';
        for ($i = 1; $i <= 5; $i += 2) {
            $c = (int) hexdec(substr($color, $i, 2));
            $b = (int) hexdec(substr($base, $i, 2));
            $out .= sprintf('%02x', intdiv($c * $weight + $b * (100 - $weight) + 50, 100));
        }
        return $out;
    }

    /** 라이트 배경값 — 등록 라이트값 12 : 흰 바탕 88 */
    public static function bgLight(string $light): string
    {
        return self::tint($light, self::LIGHT_BG, self::LIGHT_WEIGHT);
    }

    /** 다크 배경값 — 등록 다크값 24 : 다크 바탕(zinc-950) 76 */
    public static function bgDark(string $dark): string
    {
        return self::tint($dark, self::DARK_BG, self::DARK_WEIGHT);
    }

    /**
     * 줄들을 이름순(바이트 순 — DB 정렬 규칙에 기대지 않는다)으로 세운다.
     *
     * @param  list<array{name:string,light:string,dark:string}> $rows
     * @return list<array{name:string,light:string,dark:string}>
     */
    public static function sorted(array $rows): array
    {
        usort($rows, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
        return array_values($rows);
    }

    /**
     * 버전 — 이름순 `name|light|dark` 를 줄바꿈으로 이은 글자의 sha256 앞 12자리. 줄이 없으면 빈 글자의 해시(`e3b0c44298fc`).
     *
     * @param list<array{name:string,light:string,dark:string}> $rows
     */
    public static function version(array $rows): string
    {
        $lines = [];
        foreach (self::sorted($rows) as $r) {
            $lines[] = $r['name'] . '|' . $r['light'] . '|' . $r['dark'];
        }
        return substr(hash('sha256', implode("\n", $lines)), 0, 12);
    }

    /** 셸·창구가 받는 버전 값의 모양 — 12자리 소문자 16진 (허용 목록 방식 — 아니면 링크를 그리지 않는다) */
    public static function isVersion(string $v): bool
    {
        return preg_match('/^[0-9a-f]{12}$/D', $v) === 1;
    }

    /**
     * 서버가 그리는 CSS 한 장 (지시서 1.4 모양 그대로 · 줄은 `\n`).
     *   /* qaredi palette v<버전> *\/
     *   :root{--pal-<n>:<라이트>;--pal-<n>-bg:<라이트 배경>;…}
     *   :root[data-theme="dark"]{--pal-<n>:<다크>;--pal-<n>-bg:<다크 배경>;…}
     *   @media (prefers-color-scheme: dark){:root:not([data-theme="light"]){…다크와 같은 값…}}
     *   .pal-<n>{color:var(--pal-<n>)}.pal-<n>-bg{background:var(--pal-<n>-bg)}…
     * 줄이 없으면 머리 주석 한 줄뿐이다. 모양 밖 줄은 건너뛴다(부르는 쪽이 이미 걸렀어도 한 겹 더).
     *
     * @param list<array{name:string,light:string,dark:string}> $rows
     */
    public static function css(array $rows, string $version): string
    {
        if (!self::isVersion($version)) {
            throw new InvalidArgumentException('팔레트 버전 — 모양 밖 값');
        }
        $out   = '/* qaredi palette v' . $version . " */\n";
        $light = [];
        $dark  = [];
        $rules = '';
        foreach (self::sorted($rows) as $r) {
            if (!self::rowOk($r)) {
                continue;
            }
            $n       = $r['name'];
            $light[] = '--pal-' . $n . ':' . $r['light'] . ';--pal-' . $n . '-bg:' . self::bgLight($r['light']);
            $dark[]  = '--pal-' . $n . ':' . $r['dark'] . ';--pal-' . $n . '-bg:' . self::bgDark($r['dark']);
            $rules  .= '.pal-' . $n . '{color:var(--pal-' . $n . ')}.pal-' . $n . '-bg{background:var(--pal-' . $n . '-bg)}';
        }
        if ($light === []) {
            return $out;
        }
        $d = implode(';', $dark);
        return $out
            . ':root{' . implode(';', $light) . "}\n"
            . ':root[data-theme="dark"]{' . $d . "}\n"
            . '@media (prefers-color-scheme: dark){:root:not([data-theme="light"]){' . $d . "}}\n"
            . $rules . "\n";
    }
}
