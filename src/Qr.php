<?php
declare(strict_types=1);

/**
 * src/Qr.php — QR 코드 모듈 행렬 (ISO/IEC 18004) — F-51, ARCHITECTURE 5.25
 *
 * 왜 직접 만드나
 *   외부 라이브러리를 넣지 않는다(PRD S-10-C 공급망 최소화). 2단계 인증 등록 화면에 필요한 것은
 *   `otpauth://` 문자열 하나를 담은 작은 QR 뿐이라, 패키지 하나를 통째로 들여오는 것보다
 *   규격대로 쓴 한 파일을 읽을 수 있게 두는 편이 낫다. `src/Totp.php` 와 같은 결이다.
 *
 * 규격 (인증 앱이 읽는 가장 흔한 조합)
 *   · 모드: 바이트(8비트) 하나만. 모드 지시자 `0100`, 글자 수 지시자 8비트(버전 1~9)·16비트(버전 10)
 *   · 오류정정: M (약 15% 복원)
 *   · 버전: 1~10 중 들어가는 가장 작은 것 (21×21 ~ 57×57)
 *   · 리드-솔로몬: GF(2^8), 원시다항식 0x11D
 *   · 마스크: 8종 중 벌점(N1=3·N2=3·N3=40·N4=10)이 가장 낮은 것. 시험용으로 강제할 수도 있다
 *   · 형식 정보: BCH(15,5), 생성 0x537, XOR 0x5412 / 버전 정보(버전 7 이상): BCH(18,6), 생성 0x1F25
 *
 * 이 파일이 하지 않는 것
 *   · 그림을 만들지 않는다. 결과는 `'0'`(밝음)/`'1'`(어두움) 문자열 배열일 뿐이고, 그리는 것은
 *     화면(`public/assets/app.js` 의 canvas `fillRect`)의 몫이다 — 이미지 확장·`data:` 주소가 없다
 *   · 파일을 읽거나 쓰지 않는다. 바깥으로 나가지 않는다. 다른 파일을 끌어오지 않는다
 *   · 로그를 남기지 않는다. 받은 문자열도 만든 행렬도 어디에도 적지 않는다 (PRD 7.7)
 *   · 받은 문자열을 해석하지 않는다. 바이트 열로만 다룬다(UTF-8 이든 아니든 그대로)
 */
final class Qr
{
    /** 이 파일이 다루는 버전 범위 — 등록용 QR 에 10보다 큰 것은 필요 없다 */
    private const MIN_VERSION = 1;
    private const MAX_VERSION = 10;

    /**
     * 버전별 표 (오류정정 M).
     *   bytes     바이트 모드로 담을 수 있는 글자 수
     *   data      데이터 코드워드 수
     *   ec        블록당 오류정정 코드워드 수
     *   blocks    블록 구조 [[블록 수, 블록당 데이터 코드워드], …] — 짧은 블록이 먼저다
     *   align     정렬 패턴 중심 좌표 (모서리 세 곳은 찾기 패턴과 겹쳐 빠진다)
     *   remainder 배치 뒤 남는 비트 수 (0 으로 채운다)
     */
    private const SPEC = [
        1  => ['bytes' => 14,  'data' => 16,  'ec' => 10, 'blocks' => [[1, 16]],           'align' => [],             'remainder' => 0],
        2  => ['bytes' => 26,  'data' => 28,  'ec' => 16, 'blocks' => [[1, 28]],           'align' => [6, 18],        'remainder' => 7],
        3  => ['bytes' => 42,  'data' => 44,  'ec' => 26, 'blocks' => [[1, 44]],           'align' => [6, 22],        'remainder' => 7],
        4  => ['bytes' => 62,  'data' => 64,  'ec' => 18, 'blocks' => [[2, 32]],           'align' => [6, 26],        'remainder' => 7],
        5  => ['bytes' => 84,  'data' => 86,  'ec' => 24, 'blocks' => [[2, 43]],           'align' => [6, 30],        'remainder' => 7],
        6  => ['bytes' => 106, 'data' => 108, 'ec' => 16, 'blocks' => [[4, 27]],           'align' => [6, 34],        'remainder' => 7],
        7  => ['bytes' => 122, 'data' => 124, 'ec' => 18, 'blocks' => [[4, 31]],           'align' => [6, 22, 38],    'remainder' => 0],
        8  => ['bytes' => 152, 'data' => 154, 'ec' => 22, 'blocks' => [[2, 38], [2, 39]],  'align' => [6, 24, 42],    'remainder' => 0],
        9  => ['bytes' => 180, 'data' => 182, 'ec' => 22, 'blocks' => [[3, 36], [2, 37]],  'align' => [6, 26, 46],    'remainder' => 0],
        10 => ['bytes' => 213, 'data' => 216, 'ec' => 26, 'blocks' => [[4, 43], [1, 44]],  'align' => [6, 28, 50],    'remainder' => 0],
    ];

    /** 채움 코드워드 — 규격이 정한 두 값을 번갈아 넣는다 */
    private const PAD = [0xEC, 0x11];

    /** GF(2^8) 지수/로그 표 (처음 쓸 때 한 번 만든다) */
    private static ?array $gfExp = null;
    private static ?array $gfLog = null;

    /**
     * 문자열 하나를 QR 모듈 행렬로 만든다.
     *
     * @param  string   $text 담을 바이트 열 (해석하지 않는다)
     * @param  int|null $mask null 이면 벌점이 가장 낮은 마스크, 0~7 이면 그 마스크를 강제한다(시험용)
     * @return array{version:int, size:int, rows:list<string>}
     *         rows 는 '0'(밝음)/'1'(어두움) 문자열 배열, 여백(quiet zone) 없음
     *
     * @throws InvalidArgumentException 마스크가 0~7 밖일 때
     * @throws RuntimeException         버전 10 에도 들어가지 않을 때
     */
    public static function encode(string $text, ?int $mask = null): array
    {
        if ($mask !== null && ($mask < 0 || $mask > 7)) {
            throw new InvalidArgumentException('QR 마스크는 0~7 이어야 합니다');
        }

        $version = self::pickVersion(strlen($text));
        $spec    = self::SPEC[$version];
        $size    = 17 + 4 * $version;

        $bits = self::finalBits($text, $version);

        // 기능 패턴을 놓고, 데이터가 들어갈 수 없는 자리(형식·버전 정보 포함)를 미리 예약한다.
        [$modules, $fixed] = self::functionPatterns($version, $size);
        self::placeData($modules, $fixed, $size, $bits);

        if ($mask !== null) {
            return ['version' => $version, 'size' => $size, 'rows' => self::rows(self::applyMask($modules, $fixed, $size, $mask), $size)];
        }

        $best      = null;
        $bestScore = 0;
        for ($m = 0; $m <= 7; $m++) {
            $candidate = self::applyMask($modules, $fixed, $size, $m);
            $score     = self::penalty($candidate, $size);
            if ($best === null || $score < $bestScore) {
                $best      = $candidate;
                $bestScore = $score;
            }
        }

        return ['version' => $version, 'size' => $size, 'rows' => self::rows($best, $size)];
    }

    // ── 버전·비트열 ────────────────────────────────────────────────

    /** 들어가는 가장 작은 버전. 없으면 던진다 (핸들러는 이때 qr:null 로 답한다) */
    private static function pickVersion(int $length): int
    {
        for ($v = self::MIN_VERSION; $v <= self::MAX_VERSION; $v++) {
            if ($length <= self::SPEC[$v]['bytes']) {
                return $v;
            }
        }
        throw new RuntimeException('QR 로 만들기에 너무 깁니다');
    }

    /**
     * 모드·글자 수·데이터·종결자·채움 → 블록 나눔 → 리드-솔로몬 → 인터리빙 → 나머지 비트.
     * 결과는 '0'/'1' 로 된 비트 문자열이다.
     */
    private static function finalBits(string $text, int $version): string
    {
        $spec      = self::SPEC[$version];
        $length    = strlen($text);
        $countBits = $version >= 10 ? 16 : 8;
        $capacity  = $spec['data'] * 8;

        $bits = '0100' . self::bin($length, $countBits);
        for ($i = 0; $i < $length; $i++) {
            $bits .= self::bin(ord($text[$i]), 8);
        }

        // 종결자는 최대 4비트, 남은 자리만큼만
        $bits .= str_repeat('0', min(4, $capacity - strlen($bits)));
        // 바이트 경계까지 0 으로
        if (strlen($bits) % 8 !== 0) {
            $bits .= str_repeat('0', 8 - (strlen($bits) % 8));
        }
        // 남은 자리는 채움 코드워드 두 개를 번갈아
        for ($i = 0; strlen($bits) < $capacity; $i++) {
            $bits .= self::bin(self::PAD[$i % 2], 8);
        }

        $codewords = [];
        for ($i = 0; $i < $capacity; $i += 8) {
            $codewords[] = bindec(substr($bits, $i, 8));
        }

        // 블록으로 나누고 블록마다 오류정정 코드워드를 붙인다
        $dataBlocks = [];
        $ecBlocks   = [];
        $offset     = 0;
        foreach ($spec['blocks'] as [$count, $perBlock]) {
            for ($b = 0; $b < $count; $b++) {
                $block        = array_slice($codewords, $offset, $perBlock);
                $offset      += $perBlock;
                $dataBlocks[] = $block;
                $ecBlocks[]   = self::errorCorrection($block, $spec['ec']);
            }
        }

        // 인터리빙 — 데이터 먼저(짧은 블록은 자기 길이까지만), 그 뒤 오류정정
        $out     = '';
        $longest = 0;
        foreach ($dataBlocks as $block) {
            $longest = max($longest, count($block));
        }
        for ($i = 0; $i < $longest; $i++) {
            foreach ($dataBlocks as $block) {
                if ($i < count($block)) {
                    $out .= self::bin($block[$i], 8);
                }
            }
        }
        for ($i = 0; $i < $spec['ec']; $i++) {
            foreach ($ecBlocks as $block) {
                $out .= self::bin($block[$i], 8);
            }
        }

        return $out . str_repeat('0', $spec['remainder']);
    }

    /** 정수를 고정 길이 이진 문자열로 */
    private static function bin(int $value, int $width): string
    {
        return str_pad(decbin($value), $width, '0', STR_PAD_LEFT);
    }

    // ── 리드-솔로몬 (GF(2^8), 원시다항식 0x11D) ────────────────────

    private static function gfInit(): void
    {
        if (self::$gfExp !== null) {
            return;
        }
        $exp = array_fill(0, 512, 0);
        $log = array_fill(0, 256, 0);
        $x   = 1;
        for ($i = 0; $i < 255; $i++) {
            $exp[$i] = $x;
            $log[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11D;
            }
        }
        for ($i = 255; $i < 512; $i++) {
            $exp[$i] = $exp[$i - 255];
        }
        self::$gfExp = $exp;
        self::$gfLog = $log;
    }

    private static function gfMul(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }
        self::gfInit();
        return self::$gfExp[(self::$gfLog[$a] + self::$gfLog[$b]) % 255];
    }

    /** 생성다항식 — (x - α^0)(x - α^1)…(x - α^{n-1}) 의 계수(내림차순) */
    private static function generatorPoly(int $degree): array
    {
        self::gfInit();
        $poly = [1];
        for ($i = 0; $i < $degree; $i++) {
            $next = array_fill(0, count($poly) + 1, 0);
            foreach ($poly as $k => $coefficient) {
                $next[$k]     ^= self::gfMul($coefficient, 1);
                $next[$k + 1] ^= self::gfMul($coefficient, self::$gfExp[$i]);
            }
            $poly = $next;
        }
        return $poly;
    }

    /** 한 블록의 오류정정 코드워드 $count 개 (다항식 나머지) */
    private static function errorCorrection(array $block, int $count): array
    {
        $gen       = self::generatorPoly($count);
        $remainder = array_merge(array_values($block), array_fill(0, $count, 0));
        $dataLen   = count($block);
        for ($i = 0; $i < $dataLen; $i++) {
            $factor = $remainder[$i];
            if ($factor === 0) {
                continue;
            }
            for ($j = 0; $j <= $count; $j++) {
                $remainder[$i + $j] ^= self::gfMul($gen[$j], $factor);
            }
        }
        return array_slice($remainder, $dataLen, $count);
    }

    // ── 행렬 ───────────────────────────────────────────────────────

    /**
     * 기능 패턴(찾기·구분자·타이밍·정렬·고정 어두운 모듈)과 버전 정보를 놓고,
     * 데이터가 들어갈 수 없는 자리를 $fixed 로 표시한다. 형식 정보 자리도 예약만 해 둔다.
     *
     * @return array{0: array<int, array<int, int>>, 1: array<int, array<int, bool>>}
     */
    private static function functionPatterns(int $version, int $size): array
    {
        $modules = array_fill(0, $size, array_fill(0, $size, 0));
        $fixed   = array_fill(0, $size, array_fill(0, $size, false));

        // 찾기 패턴 7×7 과 그 둘레의 구분자
        foreach ([[0, 0], [0, $size - 7], [$size - 7, 0]] as [$r0, $c0]) {
            for ($r = -1; $r <= 7; $r++) {
                for ($c = -1; $c <= 7; $c++) {
                    $rr = $r0 + $r;
                    $cc = $c0 + $c;
                    if ($rr < 0 || $rr >= $size || $cc < 0 || $cc >= $size) {
                        continue;
                    }
                    $dark = (($r >= 0 && $r <= 6) && ($c === 0 || $c === 6))
                         || (($c >= 0 && $c <= 6) && ($r === 0 || $r === 6))
                         || ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4);
                    $modules[$rr][$cc] = $dark ? 1 : 0;
                    $fixed[$rr][$cc]   = true;
                }
            }
        }

        // 타이밍 패턴 — 6번 행·열
        for ($i = 8; $i < $size - 8; $i++) {
            $bit = ($i % 2 === 0) ? 1 : 0;
            $modules[6][$i] = $bit;
            $fixed[6][$i]   = true;
            $modules[$i][6] = $bit;
            $fixed[$i][6]   = true;
        }

        // 정렬 패턴 5×5 — 찾기 패턴과 겹치는 세 자리는 건너뛴다
        $centers = self::SPEC[$version]['align'];
        foreach ($centers as $rc) {
            foreach ($centers as $cc) {
                if (($rc === 6 && $cc === 6)
                    || ($rc === 6 && $cc === $size - 7)
                    || ($rc === $size - 7 && $cc === 6)) {
                    continue;
                }
                for ($r = -2; $r <= 2; $r++) {
                    for ($c = -2; $c <= 2; $c++) {
                        $dark = (abs($r) === 2 || abs($c) === 2 || ($r === 0 && $c === 0));
                        $modules[$rc + $r][$cc + $c] = $dark ? 1 : 0;
                        $fixed[$rc + $r][$cc + $c]   = true;
                    }
                }
            }
        }

        // 늘 어두운 모듈 (4v+9, 8)
        $modules[$size - 8][8] = 1;
        $fixed[$size - 8][8]   = true;

        // 형식 정보 자리 — 값은 마스크가 정해진 뒤에 넣는다. 지금은 예약만.
        for ($i = 0; $i <= 8; $i++) {
            $fixed[8][$i] = true;
            $fixed[$i][8] = true;
        }
        for ($i = 0; $i < 8; $i++) {
            $fixed[8][$size - 1 - $i] = true;
        }
        for ($i = 0; $i < 7; $i++) {
            $fixed[$size - 1 - $i][8] = true;
        }

        // 버전 정보 (버전 7 이상) — 좌하·우상 6×3
        if ($version >= 7) {
            $bits = self::versionBits($version);
            for ($i = 0; $i < 18; $i++) {
                $bit = ($bits >> $i) & 1;
                $r   = intdiv($i, 3);
                $c   = $i % 3;
                $modules[$r][$size - 11 + $c] = $bit;
                $fixed[$r][$size - 11 + $c]   = true;
                $modules[$size - 11 + $c][$r] = $bit;
                $fixed[$size - 11 + $c][$r]   = true;
            }
        }

        return [$modules, $fixed];
    }

    /** 데이터 비트를 오른쪽 아래에서 2열씩 지그재그로 놓는다 (6번 열은 건너뛴다) */
    private static function placeData(array &$modules, array $fixed, int $size, string $bits): void
    {
        $length = strlen($bits);
        $index  = 0;
        $row    = $size - 1;
        $step   = -1;

        for ($c = $size - 1; $c > 0; $c -= 2) {
            $col = ($c <= 6) ? $c - 1 : $c;
            while (true) {
                foreach ([$col, $col - 1] as $cc) {
                    if ($fixed[$row][$cc]) {
                        continue;
                    }
                    $modules[$row][$cc] = ($index < $length) ? (int) $bits[$index] : 0;
                    $index++;
                }
                $row += $step;
                if ($row < 0 || $row >= $size) {
                    $row -= $step;
                    $step = -$step;
                    break;
                }
            }
        }
    }

    /** 마스크 조건 — 참이면 그 모듈을 뒤집는다 */
    private static function maskBit(int $mask, int $i, int $j): bool
    {
        switch ($mask) {
            case 0: return ($i + $j) % 2 === 0;
            case 1: return $i % 2 === 0;
            case 2: return $j % 3 === 0;
            case 3: return ($i + $j) % 3 === 0;
            case 4: return (intdiv($i, 2) + intdiv($j, 3)) % 2 === 0;
            case 5: return (($i * $j) % 2) + (($i * $j) % 3) === 0;
            case 6: return ((($i * $j) % 2) + (($i * $j) % 3)) % 2 === 0;
            default: return (((($i + $j) % 2) + (($i * $j) % 3)) % 2) === 0;
        }
    }

    /** 데이터 모듈에 마스크를 씌우고 형식 정보를 넣은 새 행렬 */
    private static function applyMask(array $modules, array $fixed, int $size, int $mask): array
    {
        for ($i = 0; $i < $size; $i++) {
            for ($j = 0; $j < $size; $j++) {
                if (!$fixed[$i][$j] && self::maskBit($mask, $i, $j)) {
                    $modules[$i][$j] ^= 1;
                }
            }
        }

        $bits = self::formatBits($mask);
        for ($i = 0; $i < 15; $i++) {
            $bit = ($bits >> $i) & 1;
            // 세로 — 8번 열
            if ($i < 6) {
                $modules[$i][8] = $bit;
            } elseif ($i < 8) {
                $modules[$i + 1][8] = $bit;
            } else {
                $modules[$size - 15 + $i][8] = $bit;
            }
            // 가로 — 8번 행
            if ($i < 8) {
                $modules[8][$size - 1 - $i] = $bit;
            } elseif ($i === 8) {
                $modules[8][7] = $bit;
            } else {
                $modules[8][14 - $i] = $bit;
            }
        }
        $modules[$size - 8][8] = 1;

        return $modules;
    }

    /** 형식 정보 15비트 — 오류정정 M(00) + 마스크 3비트, BCH(15,5) + 0x5412 */
    private static function formatBits(int $mask): int
    {
        $data      = $mask;              // (M = 00) << 3 | mask
        $remainder = $data;
        for ($i = 0; $i < 10; $i++) {
            $remainder <<= 1;
            if (($remainder >> 10) & 1) {
                $remainder ^= 0x537;
            }
        }
        return (($data << 10) | $remainder) ^ 0x5412;
    }

    /** 버전 정보 18비트 — 버전 6비트 + BCH(18,6) */
    private static function versionBits(int $version): int
    {
        $remainder = $version;
        for ($i = 0; $i < 12; $i++) {
            $remainder <<= 1;
            if (($remainder >> 12) & 1) {
                $remainder ^= 0x1F25;
            }
        }
        return ($version << 12) | $remainder;
    }

    // ── 마스크 고르기 (벌점) ───────────────────────────────────────

    /** 규격의 네 가지 벌점 합 — 낮을수록 좋다 */
    private static function penalty(array $m, int $size): int
    {
        $score = 0;

        // N1 — 같은 색이 5개 이상 이어지면 3 + (길이 - 5)
        for ($i = 0; $i < $size; $i++) {
            for ($dir = 0; $dir < 2; $dir++) {
                $run  = 1;
                $prev = $dir === 0 ? $m[$i][0] : $m[0][$i];
                for ($j = 1; $j < $size; $j++) {
                    $cell = $dir === 0 ? $m[$i][$j] : $m[$j][$i];
                    if ($cell === $prev) {
                        $run++;
                        continue;
                    }
                    if ($run >= 5) {
                        $score += 3 + ($run - 5);
                    }
                    $prev = $cell;
                    $run  = 1;
                }
                if ($run >= 5) {
                    $score += 3 + ($run - 5);
                }
            }
        }

        // N2 — 같은 색 2×2 덩어리마다 3
        for ($i = 0; $i < $size - 1; $i++) {
            for ($j = 0; $j < $size - 1; $j++) {
                $cell = $m[$i][$j];
                if ($cell === $m[$i][$j + 1] && $cell === $m[$i + 1][$j] && $cell === $m[$i + 1][$j + 1]) {
                    $score += 3;
                }
            }
        }

        // N3 — 1:1:3:1:1 뒤(앞)에 밝은 4칸이 붙은 꼴마다 40
        $needles = ['10111010000', '00001011101'];
        for ($i = 0; $i < $size; $i++) {
            $rowText = '';
            $colText = '';
            for ($j = 0; $j < $size; $j++) {
                $rowText .= (string) $m[$i][$j];
                $colText .= (string) $m[$j][$i];
            }
            foreach ($needles as $needle) {
                $score += 40 * substr_count($rowText, $needle);
                $score += 40 * substr_count($colText, $needle);
            }
        }

        // N4 — 어두운 비율이 50% 에서 5%p 멀어질 때마다 10
        $dark = 0;
        for ($i = 0; $i < $size; $i++) {
            $dark += array_sum($m[$i]);
        }
        $ratio  = (int) floor(abs(($dark * 100) / ($size * $size) - 50) / 5);
        $score += $ratio * 10;

        return $score;
    }

    /** 행렬을 '0'/'1' 문자열 배열로 */
    private static function rows(array $m, int $size): array
    {
        $rows = [];
        for ($i = 0; $i < $size; $i++) {
            $rows[] = implode('', $m[$i]);
        }
        return $rows;
    }
}
