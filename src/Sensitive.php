<?php
declare(strict_types=1);

/**
 * src/Sensitive.php — 민감정보 패턴 검출 (15-3, PRD F-22, ARCHITECTURE 5.13, SECURITY R-58)
 *
 * 업로드(5.2)와 URL 가져오기(5.6)가 **같은 자리**(형식 검사 뒤·저장 전)에서 이 파일의
 * `scan()` 하나를 부른다. 두 입구가 같은 함수를 쓰므로 한쪽만 빠지는 일이 없다.
 *
 * ★ 이 파일의 가장 중요한 규칙: **검출한 값을 절대 돌려주지 않는다.**
 *   돌려주는 것은 `['rrn' => 건수, 'card' => 건수, 'keyword' => 건수]` 중 0 이 아닌 것뿐이다.
 *   주민등록번호·카드번호 원문이 응답·감사 로그·예외 메시지 어디에도 들어가지 않는다
 *   (PRD S-07 "민감정보 금지"). 그래서 이 클래스에는 매칭 문자열을 담는 반환 경로 자체가 없다.
 *
 * ★ 글자를 뽑는 일은 **미리보기(Preview)** 가 이미 하고 있다 — 새 파서를 만들지 않고 그대로 쓴다.
 *   상한(1MiB 읽기·64KiB 출력·200×50 표·8MiB 멤버·20,000자 발췌)도 미리보기 상수 그대로다.
 *   그래서 압축 폭탄·거대 문서로 이 검사를 무겁게 만들 수 없다 (R-42 와 같은 방어).
 *
 * ★ 한계 (SECURITY R-58, PRD F-22 "한계"):
 *   이미지·PDF·암호가 걸린 zip·스캔해 찍은 문서는 **검사하지 못한다**(글자를 뽑을 수 없다).
 *   우연히 검증 숫자·Luhn 이 맞는 숫자열은 오탐이다. 그러므로 「민감」 배지는 참고이고,
 *   등급 판단의 책임은 **올리는 사람**에게 있다 — 화면에도 같은 문구를 둔다.
 *
 * 이 파일은 bootstrap.php 에 넣지 않는다(모든 요청이 지고 가야 할 코드가 아니다).
 * 필요한 핸들러(files_upload·files_fetch)가 require_once 한다 — 14단계 Wiki.php 와 같은 방식.
 */
final class Sensitive
{
    /**
     * 키워드 목록 (PRD F-22 ③). **코드 상수**이며 설정·DB·요청으로 바뀌지 않는다.
     *
     * 목록을 밖에서 바꿀 수 있으면 "검출기를 끄는" 경로가 생긴다 — 그래서 상수다.
     * 늘리려면 이 파일을 고치고 PRD F-22 를 함께 고친다.
     *
     * @var list<string>
     */
    public const KEYWORDS = ['급여', '연봉', '주민번호', '계좌번호', '여권번호'];

    /** 검사하는 미리보기 갈래 (ARCH 5.13 "무엇을"). 그 밖은 검사하지 않는다 */
    private const SCANNED_KINDS = ['text', 'table', 'office'];

    /** 주민등록번호 **후보** 형태. 통과 여부는 checkDigit 이 따로 본다 */
    private const RRN_RE = '/\b\d{6}-?\d{7}\b/';

    /** 카드번호 **후보** 형태 (13~19자리, 사이에 공백·붙임표 허용). 통과 여부는 Luhn 이 본다 */
    private const CARD_RE = '/\b(?:\d[ -]?){13,19}\b/';

    /** 주민등록번호 검증 숫자의 가중치 (앞 12자리에 차례로 곱한다) */
    private const RRN_WEIGHTS = [2, 3, 4, 5, 6, 7, 8, 9, 2, 3, 4, 5];

    /**
     * 파일 하나를 검사한다.
     *
     * @param string $path 서버가 만든 **임시 파일 경로**. 사용자 입력이 한 글자도 들어가지 않은
     *                     값만 넘어온다($_FILES 의 tmp_name, Storage::tmpDir() 의 tempnam).
     *                     이 클래스는 경로를 만들지도, 이어 붙이지도 않는다 (PRD S-06).
     * @param string $ext  DB 에 들어갈 **검증된 확장자**(Upload 가 화이트리스트로 확정한 값).
     *
     * @return array<string,int> 0 이 아닌 것만 담긴 `['rrn'=>n,'card'=>n,'keyword'=>n]`.
     *                           검사 대상이 아니거나 글자를 못 뽑았으면 빈 배열.
     */
    public static function scan(string $path, string $ext): array
    {
        $text = self::extract($path, $ext);
        if ($text === '') {
            return [];
        }

        $counts = [
            'rrn'     => self::countRrn($text),
            'card'    => self::countCard($text),
            'keyword' => self::countKeywords($text),
        ];

        // 0 인 항목은 아예 내리지 않는다 — 감사 로그의 detail 이 "무엇이 걸렸나" 만 담게 한다.
        return array_filter($counts, static fn (int $n): bool => $n > 0);
    }

    /**
     * 검사 대상이면 글자를 뽑고, 아니면 빈 문자열.
     *
     * 갈래 판정은 **Preview::kindFor(DB 확장자)** 하나로 한다 — 목록·미리보기와 같은 판정이다.
     * `image`·`pdf`·`archive`·`none` 은 글자를 뽑을 수 없어 검사하지 않는다(한계, R-58).
     *
     * 추출에 실패하면(손상된 zip·깨진 문서) **업로드를 막지 않고** 빈 문자열을 돌려준다.
     * 검출은 보조 장치이고, 파서 하나가 실패했다고 정상 업로드가 거부되면 안 된다 (ARCH 5.13).
     */
    private static function extract(string $path, string $ext): string
    {
        $kind = Preview::kindFor($ext);
        if (!in_array($kind, self::SCANNED_KINDS, true)) {
            return '';
        }

        try {
            if ($kind === 'text') {
                $out = Preview::text($path);
                return is_string($out['content'] ?? null) ? $out['content'] : '';
            }
            if ($kind === 'table') {
                $out  = Preview::table($path);
                $rows = is_array($out['rows'] ?? null) ? $out['rows'] : [];
                $lines = [];
                foreach ($rows as $cells) {
                    if (is_array($cells)) {
                        // 칸 사이를 붙임표가 아닌 문자로 잇는다 — 이웃한 칸의 숫자가 우연히
                        // 이어져 13자리 후보를 만드는 것을 막는다(오탐 줄이기).
                        $lines[] = implode(' , ', $cells);
                    }
                }
                return implode("\n", $lines);
            }
            // office (docx·pptx·xlsx·hwpx)
            $out = Preview::office($path, $ext);
            return is_string($out['excerpt'] ?? null) ? $out['excerpt'] : '';
        } catch (Throwable $ignored) {
            // parse_failed 와 같은 취급 — 검사하지 못했을 뿐이고 업로드는 막지 않는다.
            return '';
        }
    }

    /**
     * 주민등록번호 (PRD F-22 ①) — 형태가 맞는 후보 중 **뒷자리 첫 숫자 1~4** 이고
     * **검증 숫자**까지 맞는 것만 센다.
     *
     * 형태만 세면 전화번호·주문번호 같은 13자리 숫자가 전부 걸린다(오탐 폭증).
     * 그래서 두 관문을 더 두어 "정말 주민등록번호 꼴인 것" 만 남긴다.
     */
    private static function countRrn(string $text): int
    {
        $matched = preg_match_all(self::RRN_RE, $text, $found);
        if ($matched === false || $matched === 0) {
            return 0;
        }

        $hits = 0;
        foreach ($found[0] as $candidate) {
            $digits = str_replace('-', '', $candidate);
            if (strlen($digits) !== 13) {
                continue;
            }
            // 7번째 자리(성별·세기 코드)는 1~4 만 본다. 5~8(외국인 등록번호)·0·9 는 제외한다.
            $gender = (int) $digits[6];
            if ($gender < 1 || $gender > 4) {
                continue;
            }
            if (!self::rrnCheckDigit($digits)) {
                continue;
            }
            $hits++;
        }
        return $hits;
    }

    /** 앞 12자리 × 가중치 합 → (11 - 합%11) % 10 이 13번째 자리와 같은가 */
    private static function rrnCheckDigit(string $digits): bool
    {
        $sum = 0;
        foreach (self::RRN_WEIGHTS as $i => $weight) {
            $sum += ((int) $digits[$i]) * $weight;
        }
        $expected = (11 - ($sum % 11)) % 10;
        return $expected === (int) $digits[12];
    }

    /**
     * 신용카드 번호 (PRD F-22 ②) — 13~19자리 후보 중 **Luhn 통과**만 센다.
     * Luhn 을 통과하지 못하는 우연한 숫자열은 세지 않는다 (T-35 "우연 숫자 → 통과").
     */
    private static function countCard(string $text): int
    {
        $matched = preg_match_all(self::CARD_RE, $text, $found);
        if ($matched === false || $matched === 0) {
            return 0;
        }

        $hits = 0;
        foreach ($found[0] as $candidate) {
            $digits = preg_replace('/[^0-9]/', '', $candidate);
            $digits = is_string($digits) ? $digits : '';
            $len    = strlen($digits);
            if ($len < 13 || $len > 19) {
                continue;
            }
            if (!self::luhn($digits)) {
                continue;
            }
            $hits++;
        }
        return $hits;
    }

    /** Luhn 검사 — 오른쪽에서 두 번째 자리부터 한 칸 걸러 2배(9 초과면 -9), 합이 10의 배수 */
    private static function luhn(string $digits): bool
    {
        $sum    = 0;
        $double = false;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $n = (int) $digits[$i];
            if ($double) {
                $n *= 2;
                if ($n > 9) {
                    $n -= 9;
                }
            }
            $sum   += $n;
            $double = !$double;
        }
        return $sum % 10 === 0;
    }

    /**
     * 키워드 (PRD F-22 ③) — KEYWORDS 상수의 **부분 일치** 건수를 모두 더한다.
     * 값(어느 낱말이 몇 번)이 아니라 합계만 남으므로 문서 내용이 로그로 새지 않는다.
     */
    private static function countKeywords(string $text): int
    {
        $hits = 0;
        foreach (self::KEYWORDS as $word) {
            $hits += substr_count($text, $word);
        }
        return $hits;
    }
}
