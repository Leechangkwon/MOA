<?php
declare(strict_types=1);

/**
 * src/SearchText.php — 검색 색인·검색어 토큰 한 벌 (사이트 PRD Q:F-2 · 지시서 docs/qf2-unified-search-directive.md 2.1·2.2)
 *
 * 한국어는 거의 모든 낱말에 조사·어미가 붙는다. `to_tsvector('simple', …)` 의 낱말 단위 색인은 「휴가」로
 * 「휴가를」을 찾지 못한다(지시서 0장 실측). 그래서 **한글만** 「두 글자 조각 + 한 글자」로 잘게 나눠
 * 기존 `search_tsv`(GIN)에 넣는다. 찾을 때는 검색어도 같은 규칙으로 나눠 조각이 **다 있는** 행만 색인으로
 * 추리고, 원문 일치는 부르는 쪽이 `ILIKE` 로 다시 확인한다(조각은 다 있는데 떨어져 있는 거짓 일치를 지운다).
 *
 * 규칙 (지시서 2.1)
 *   ① 한글 자모 조합 — 맥에서 온 글(NFD: 초성·중성·종성이 따로 든 것)을 **산술식으로** 음절로 합친다.
 *      `intl`·`mbstring` 이 없는 서버다(0장) — 한글만 다룬다. 검색어·본문 둘 다 이 함수를 거친다.
 *   ② 나누기 — 한글 음절(U+AC00~D7A3) 덩어리만 뽑는다. 그 밖의 글자·숫자는 **벡터에 넣지 않는다**
 *      (J-10 ⓑ — 영문·숫자는 찾을 때 ILIKE 로만 확인하므로 색인이 필요 없다).
 *   ③ 한글 덩어리 — 길이 1 → 그 글자, 길이 n ≥ 2 → 두 글자 조각 n-1 개 + 한 글자 n 개(J-1).
 *      예 「휴가를」 → 휴가 가를 휴 가 를
 *   ⑤ 합치기 — 중복 제거 · 상한 20,000 토큰(넘으면 자르고 한 줄 기록 — 본문은 그대로 저장) · 공백으로 잇는다.
 *      부르는 SQL 은 `strip(to_tsvector('simple', :tsv))` — 위치 정보 없이(정렬에 ts_rank 를 쓰지 않는다).
 *
 * ★ 이 파일이 만든 글자가 SQL 로 가는 자리는 **바인딩 값**뿐이다(PRD S-04). tsquery 글자도 한글 음절만으로
 *   된 토큰을 ` & ` 로 이은 것이라 연산자 글자(`& | ! ( ) : * \ '`)가 들어갈 자리가 없다(지시서 2.2 ②).
 * ★ 2차(J-7 — 초성 검색 · 한/영 자판 오타)는 이 파일에 함수를 더해 붙인다(같은 토큰 규칙을 두 벌로 두지 않는다).
 *
 * 한글 음절은 UTF-8 에서 **늘 3바이트**다(U+AC00~D7A3 = EA B0 80 ~ ED 9E A3). 그래서 덩어리를 바이트 단위로
 * 잘라도 글자가 깨지지 않는다 — 100KB 본문도 글자마다 정규식을 돌리지 않고 substr 로 끝난다(4장 목표 20ms).
 */
final class SearchText
{
    /** 한 벡터의 토큰 상한 (지시서 2.1 ⑤) */
    public const MAX_TOKENS = 20000;

    /** 한글 음절 덩어리 */
    private const HANGUL_RUN = '/[\x{AC00}-\x{D7A3}]+/u';

    /**
     * ① 한글 자모(NFD) → 음절(NFC). 한글 밖 글자는 그대로 둔다.
     *   초성 U+1100~1112(19) · 중성 U+1161~1175(21) · 종성 U+11A8~11C2(27)
     *   음절 = 0xAC00 + ((초성 × 21) + 중성) × 28 + 종성(없으면 0)
     * 이미 조합된 받침 없는 음절 뒤에 종성 자모가 오는 섞인 모양(LV + T)도 합친다(유니코드 정준 조합과 같은 결과).
     */
    public static function compose(string $s): string
    {
        // 빠른 길 — 자모 영역(U+1100~11FF = E1 84 80 ~ E1 87 BF) 바이트가 없으면 할 일이 없다
        if (strpos($s, "\xE1\x84") === false && strpos($s, "\xE1\x85") === false
            && strpos($s, "\xE1\x86") === false && strpos($s, "\xE1\x87") === false) {
            return $s;
        }
        if (preg_match('//u', $s) !== 1) {
            return $s;   // 잘못된 UTF-8 은 손대지 않는다(부르는 쪽이 이미 걸렀다)
        }
        $out = preg_replace_callback(
            '/([\x{1100}-\x{1112}])([\x{1161}-\x{1175}])([\x{11A8}-\x{11C2}])?/u',
            static function (array $m): string {
                $l = self::cp($m[1]) - 0x1100;
                $v = self::cp($m[2]) - 0x1161;
                $t = isset($m[3]) && $m[3] !== '' ? self::cp($m[3]) - 0x11A7 : 0;
                return self::chr3(0xAC00 + ($l * 21 + $v) * 28 + $t);
            },
            $s
        );
        $out = is_string($out) ? $out : $s;
        $out2 = preg_replace_callback(
            '/([\x{AC00}-\x{D7A3}])([\x{11A8}-\x{11C2}])/u',
            static function (array $m): string {
                $syl = self::cp($m[1]);
                if ((($syl - 0xAC00) % 28) !== 0) {
                    return $m[0];   // 이미 받침이 있다 — 합치지 않는다
                }
                return self::chr3($syl + (self::cp($m[2]) - 0x11A7));
            },
            $out
        );
        return is_string($out2) ? $out2 : $out;
    }

    /**
     * 색인에 넣을 토큰(②③⑤). 여러 글(제목·본문·태그)을 받아 한 벌로 합친다.
     *
     * @param  list<string> $parts
     * @return array{tokens:list<string>,truncated:bool}
     */
    public static function indexTokens(array $parts): array
    {
        $set = [];
        $n = 0;
        $truncated = false;
        foreach ($parts as $part) {
            if (!is_string($part) || $part === '') {
                continue;
            }
            if (preg_match_all(self::HANGUL_RUN, self::compose($part), $m) < 1) {
                continue;
            }
            foreach ($m[0] as $run) {
                $len = intdiv(strlen($run), 3);
                for ($i = 0; $i < $len; $i++) {
                    $uni = substr($run, $i * 3, 3);
                    if (!isset($set[$uni])) {
                        if ($n >= self::MAX_TOKENS) { $truncated = true; break 3; }
                        $set[$uni] = true;
                        $n++;
                    }
                    if ($i + 1 < $len) {
                        $bi = substr($run, $i * 3, 6);
                        if (!isset($set[$bi])) {
                            if ($n >= self::MAX_TOKENS) { $truncated = true; break 3; }
                            $set[$bi] = true;
                            $n++;
                        }
                    }
                }
            }
        }
        return ['tokens' => array_map('strval', array_keys($set)), 'truncated' => $truncated];
    }

    /**
     * 색인 글자 — `strip(to_tsvector('simple', :값))` 의 값. 상한을 넘으면 `$where`(어느 행인지 — 숫자·고정 글자)를
     * 붙여 한 줄 기록한다(app.log). 본문 원문은 기록하지 않는다.
     *
     * @param list<string> $parts
     */
    public static function indexText(array $parts, string $where = ''): string
    {
        $r = self::indexTokens($parts);
        if ($r['truncated']) {
            error_log('[tbox] search_tsv 토큰 상한 ' . self::MAX_TOKENS . ' 에서 자름'
                . ($where === '' ? '' : ' (' . preg_replace('/[^A-Za-z0-9:_ -]/', '', $where) . ')'));
        }
        return implode(' ', $r['tokens']);
    }

    /**
     * 검색어 → 낱말 목록(지시서 2.2 ①). 조합(①) 뒤 공백으로 나누고, 같은 낱말은 한 번만.
     * 낱말마다 한글 토큰(2글자 이상 덩어리는 두 글자 조각 전부, 1글자 덩어리는 그 글자)과
     * 「다시 확인이 필요한가」를 함께 준다.
     *   · 한글만 1~2글자인 낱말은 토큰이 곧 그 글자열이다 — 색인만으로 원문 일치와 같다(다시 확인 없음).
     *   · 한글 3글자 이상(조각이 떨어져 있을 수 있다) · 한글 밖 글자가 섞인 낱말은 **낱말 전체**를 ILIKE 로 확인.
     *
     * @return list<array{text:string,tokens:list<string>,recheck:bool,hangul:bool}>
     */
    public static function queryTerms(string $q): array
    {
        $q = self::compose($q);
        $out = [];
        $seen = [];
        foreach (preg_split('/\s+/u', trim($q), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $term) {
            if (isset($seen[$term])) {
                continue;
            }
            $seen[$term] = true;
            $tokens = [];
            $pureHangul = preg_match('/^[\x{AC00}-\x{D7A3}]+$/u', $term) === 1;
            if (preg_match_all(self::HANGUL_RUN, $term, $m) > 0) {
                foreach ($m[0] as $run) {
                    $len = intdiv(strlen($run), 3);
                    if ($len === 1) {
                        $tokens[] = $run;
                        continue;
                    }
                    for ($i = 0; $i + 1 < $len; $i++) {
                        $tokens[] = substr($run, $i * 3, 6);
                    }
                }
            }
            $out[] = [
                'text'    => $term,
                'tokens'  => array_values(array_unique($tokens)),
                'recheck' => !($pureHangul && intdiv(strlen($term), 3) <= 2),
                // 한글 음절만인 낱말 — 대소문자가 없으므로 다시 확인은 ILIKE 대신 글자열 찾기(strpos)로 같은 뜻이다(더 싸다)
                'hangul'  => $pureHangul,
            ];
        }
        return $out;
    }

    /**
     * tsquery 글자 — 모든 낱말의 한글 토큰을 ` & ` 로. 한글이 하나도 없으면 null(부르는 쪽은 ILIKE 만 쓴다 — J-10 ⓑ).
     * 토큰은 한글 음절만으로 되어 있다(②) — 연산자 글자가 섞일 수 없다. 값은 그래도 바인딩한다.
     *
     * @param list<array{text:string,tokens:list<string>,recheck:bool}> $terms
     */
    public static function tsquery(array $terms): ?string
    {
        $all = [];
        foreach ($terms as $t) {
            foreach ($t['tokens'] as $tok) {
                if (preg_match('/^[\x{AC00}-\x{D7A3}]{1,2}$/u', $tok) === 1) {
                    $all[$tok] = true;
                }
            }
        }
        return $all === [] ? null : implode(' & ', array_map('strval', array_keys($all)));
    }

    /** 검색어에 글자·숫자가 하나라도 있나 (J-11 — 없으면 빈 결과) */
    public static function hasWordChar(string $q): bool
    {
        return preg_match('/[\p{L}\p{N}]/u', $q) === 1;
    }

    /** ILIKE 값 — `%`·`_`·`#` 을 `#` 로 이스케이프(부르는 SQL 은 `ESCAPE '#'`) */
    public static function likePattern(string $term): string
    {
        return '%' . str_replace(['#', '%', '_'], ['##', '#%', '#_'], $term) . '%';
    }

    /** 3바이트 UTF-8 한 글자의 코드 포인트(자모·음절 영역만 부른다) */
    private static function cp(string $ch): int
    {
        return ((ord($ch[0]) & 0x0F) << 12) | ((ord($ch[1]) & 0x3F) << 6) | (ord($ch[2]) & 0x3F);
    }

    /** 코드 포인트(U+0800~FFFF) → 3바이트 UTF-8 */
    private static function chr3(int $cp): string
    {
        return chr(0xE0 | ($cp >> 12)) . chr(0x80 | (($cp >> 6) & 0x3F)) . chr(0x80 | ($cp & 0x3F));
    }
}
