<?php
declare(strict_types=1);

/**
 * src/purchase/Sheet.php — 엑셀(xlsx)·csv 를 **행 배열**로 바꾼다 (19단계, PRD F-29, ARCH 5.18 「가져오기」)
 *
 * `src/Preview.php` 와 같은 성질의 **순수 함수 모음**이다. DB 도 HTTP 도 세션도 만지지 않고,
 * 받은 파일 경로(호출자가 `Storage::pathFor()` 로 만든 값)를 읽어 **글자만** 돌려준다.
 * 권한 판단은 여기 없다 — 라우트 정책 `purchase.master.write` 와 재인증 관문이 이미 지났다.
 *
 * ── Preview.php 머리말의 다섯 규칙을 그대로 따른다 ──────────────────────────
 *   1) **갈래는 DB `files.ext` 로만 정한다.** 요청 파라미터·파일 내용·Content-Type 으로 고르지
 *      않는다 (그러면 `.zip` 을 xlsx 라고 속여 파서를 고를 수 있다).
 *   2) **상한을 상수에 모은다** (아래 SHEET_* · MEMBER_BYTES). 정상 업로드(100MB 이하)지만
 *      파서를 오래 붙잡는 파일이 PHP 프로세스를 점유하지 못하게 한다 (SECURITY R-42 · R-67).
 *   3) **zip 은 중앙 디렉터리와 지정한 멤버만** 읽는다. `extractTo`·`getStream`·`getFromIndex`
 *      를 쓰지 않는다 — 압축을 푸는 경로 자체를 만들지 않는다(zip slip·압축 폭탄).
 *      멤버는 `statName` 으로 **압축 해제 크기를 먼저 확인**하고 상한 안일 때만 `getFromName`
 *      한다 (`Preview::office` 와 **같은 순서**).
 *   4) **XML 파서를 쓰지 않는다** — DOMDocument·SimpleXML·XMLReader·libxml_* 가 이 파일에
 *      한 글자도 없다. 정규식으로 셀 값만 모으므로 외부 엔티티(XXE)·엔티티 확장
 *      (billion laughs)의 통로가 원리적으로 존재하지 않는다.
 *   5) 돌려주는 문자열은 `Preview::toUtf8` · `Preview::scrubControls` 를 지나 **UTF-8 로
 *      정규화**되고 제어문자가 U+FFFD 로 바뀐다. 두 함수를 여기 복제하지 않고 Preview 의
 *      것을 그대로 부른다 — 복제하면 언젠가 한쪽만 고쳐져 "미리보기와 가져오기가 다르게
 *      읽는" 상태가 된다(19단계에서 두 함수를 public 으로만 열었다. 로직 무변경).
 *
 * ── 셀 값을 다루는 규칙 ──────────────────────────────────────────────
 *   · **문자열로만** 읽는다. 수식(`<f>`)은 무시하고 계산된 값(`<v>`)만 본다 — 파일이 스스로
 *     계산을 요구하는 경로를 만들지 않는다.
 *   · 날짜·숫자 서식은 풀지 않는다. 엑셀의 날짜는 일련번호로, 숫자는 그대로 글자가 된다.
 *     기초정보 가져오기에 필요한 칸(코드·이름·규격·단위·수량·단가)은 전부 글자/정수라
 *     서식 해석이 필요 없고, 서식을 해석하는 코드는 styles.xml 을 읽는 새 표면을 늘린다.
 *   · **CSV 인젝션 차단은 내보내기 쪽 일이다** — `Sheet::csvCell()` 이 `=`·`+`·`-`·`@` 로
 *     시작하는 값 앞에 작은따옴표를 붙인다(오류 목록 내려받기가 이 함수를 쓴다).
 *     읽을 때는 아무것도 붙이지 않는다(원본을 바꾸면 판정이 달라진다).
 *
 * 문자열 처리는 `iconv` 계열만 쓴다 — 이 VM 에는 mbstring 확장이 없다.
 */
final class Sheet
{
    /** 읽어들이는 최대 행 수. 넘으면 truncated=true 로 끊는다 (이카운트 품목 1.35만 행 + 여유) */
    public const SHEET_ROWS = 20000;

    /** 읽어들이는 최대 열 수 */
    public const SHEET_COLS = 60;

    /** zip 멤버 하나의 최대 **압축 해제** 크기 (16MiB). statName 으로 먼저 확인한다 */
    public const MEMBER_BYTES = 16777216;

    /** csv 에서 읽어들이는 최대 바이트 (16MiB — 멤버 상한과 같은 값으로 맞춘다) */
    public const CSV_READ_BYTES = 16777216;

    /** 이 클래스가 다루는 확장자 (Upload::ALLOWED_EXT 의 부분집합) */
    public const SHEET_EXT = ['xlsx', 'csv'];

    /**
     * 파일 하나를 행 배열로 바꾼다.
     *
     * @param string $path 호출자가 Storage::pathFor() 로 만든 경로 (사용자 입력이 섞이지 않는다)
     * @param string $ext  DB `files.ext` 값 — 요청이 정하지 않는다 (규칙 1)
     * @return array{rows: list<list<string>>, truncated: bool}
     *         rows[0] 은 머리행이다(비어 있을 수 있다). 호출자가 판단한다.
     */
    public static function rows(string $path, string $ext): array
    {
        $ext = strtolower(trim($ext));
        if ($ext === 'csv') {
            return self::fromCsv($path);
        }
        if ($ext === 'xlsx') {
            return self::fromXlsx($path);
        }
        // 여기 오는 경로는 없다 — 업로드 단계에서 확장자를 이미 좁혔다. 그래도 조용히
        // 빈 표를 돌려주지 않는다: 형식을 모르는 채 "0행" 이라고 답하면 사람이 속는다.
        throw new RuntimeException('sheet_unsupported_ext');
    }

    /**
     * csv — 앞 16MiB 를 읽어 UTF-8 로 맞추고 줄 단위 `str_getcsv`.
     * 인코딩 판정·제어문자 처리는 `Preview` 의 함수를 그대로 쓴다 (규칙 5).
     *
     * @return array{rows: list<list<string>>, truncated: bool}
     */
    private static function fromCsv(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('sheet_unreadable');
        }
        $size = filesize($path);
        $fh   = fopen($path, 'rb');
        if ($fh === false) {
            throw new RuntimeException('sheet_unreadable');
        }
        $raw = fread($fh, self::CSV_READ_BYTES);
        fclose($fh);
        if ($raw === false) {
            throw new RuntimeException('sheet_unreadable');
        }
        $truncated = $size !== false && $size > self::CSV_READ_BYTES;

        [$utf8] = Preview::toUtf8($raw);
        $utf8   = Preview::scrubControls($utf8);

        $lines = preg_split('/\r\n|\n|\r/', $utf8);
        $lines = is_array($lines) ? $lines : [];

        $rows = [];
        foreach ($lines as $index => $line) {
            if (count($rows) >= self::SHEET_ROWS) {
                $truncated = true;
                break;
            }
            // 파일 끝 개행이 만든 마지막 빈 줄은 행으로 세지 않는다.
            if ($line === '' && $index === count($lines) - 1) {
                continue;
            }
            $cells = str_getcsv($line);
            if (count($cells) > self::SHEET_COLS) {
                $cells     = array_slice($cells, 0, self::SHEET_COLS);
                $truncated = true;
            }
            $row = [];
            foreach ($cells as $cell) {
                $row[] = $cell === null ? '' : trim((string) $cell);
            }
            $rows[] = $row;
        }

        return ['rows' => $rows, 'truncated' => $truncated];
    }

    /**
     * xlsx — ZipArchive + **정규식만**. 읽는 멤버는 딱 둘이다.
     *   `xl/sharedStrings.xml`       공유 문자열 목록 (없는 파일도 있다 — 그때는 빈 목록)
     *   `xl/worksheets/sheetN.xml`   **번호가 가장 작은** 시트 하나
     *
     * 시트 이름을 중앙 디렉터리에서 정규식으로 고르는 이유: `workbook.xml` 과 관계 파일
     * (`workbook.xml.rels`)을 따라가면 읽는 멤버가 둘 더 늘고, 그 둘도 XML 이라 같은 상한·
     * 같은 정규식 처리를 또 해야 한다. 실무 파일은 첫 시트가 `sheet1.xml` 이며,
     * 시트가 여러 장이면 **첫 장만** 가져온다는 것을 화면이 알린다.
     *
     * @return array{rows: list<list<string>>, truncated: bool}
     */
    private static function fromXlsx(string $path): array
    {
        // RDONLY 로만 연다 (Preview 와 같은 함수 — 여는 방식이 두 벌이 되지 않게)
        $zip = Preview::openZip($path);

        try {
            $sheetMember = self::firstSheetMember($zip);
            if ($sheetMember === null) {
                throw new RuntimeException('sheet_no_worksheet');
            }

            $truncated = false;

            // ① 공유 문자열 (없어도 된다 — inline string 만 쓴 파일이 있다)
            $shared = [];
            $sharedXml = self::member($zip, 'xl/sharedStrings.xml');
            if ($sharedXml === null && self::memberTooBig($zip, 'xl/sharedStrings.xml')) {
                $truncated = true;   // 너무 커서 읽지 않았다 → 문자열이 비어 보일 수 있다
            }
            if (is_string($sharedXml)) {
                $shared = self::sharedStrings($sharedXml);
            }

            // ② 시트 본문
            $sheetXml = self::member($zip, $sheetMember);
            if ($sheetXml === null) {
                // 상한을 넘었거나 빈 멤버다. 조용히 0행으로 넘기지 않는다.
                throw new RuntimeException('sheet_member_too_large');
            }
        } finally {
            $zip->close();
        }

        [$rows, $cut] = self::sheetRows($sheetXml, $shared);
        return ['rows' => $rows, 'truncated' => $truncated || $cut];
    }

    /**
     * 중앙 디렉터리에서 `xl/worksheets/sheetN.xml` 중 **번호가 가장 작은** 이름을 고른다.
     * 이름만 읽고(`getNameIndex`) 내용은 여기서 한 바이트도 풀지 않는다 (규칙 3).
     */
    private static function firstSheetMember(ZipArchive $zip): ?string
    {
        $found = [];
        $count = $zip->numFiles;
        for ($i = 0; $i < $count; $i++) {
            $name = $zip->getNameIndex($i);
            if (!is_string($name)) {
                continue;
            }
            if (preg_match('#^xl/worksheets/sheet(\d+)\.xml$#', $name, $m) === 1) {
                $found[(int) $m[1]] = $name;
            }
        }
        if ($found === []) {
            return null;
        }
        ksort($found, SORT_NUMERIC);
        $first = array_values($found)[0];
        return is_string($first) ? $first : null;
    }

    /**
     * 멤버 하나를 **상한 안에서만** 읽는다 (규칙 3 — statName 먼저, 그 다음 getFromName).
     * 없거나 상한을 넘거나 빈 멤버면 null.
     */
    private static function member(ZipArchive $zip, string $name): ?string
    {
        $stat = $zip->statName($name);
        if (!is_array($stat)) {
            return null;
        }
        if ((int) ($stat['size'] ?? 0) > self::MEMBER_BYTES) {
            return null;   // 압축 해제 크기가 상한을 넘으면 **읽지 않는다**
        }
        $xml = $zip->getFromName($name);
        return is_string($xml) && $xml !== '' ? $xml : null;
    }

    /** 그 멤버가 "있는데 상한을 넘어서" 읽지 못한 경우인가 (truncated 표시용) */
    private static function memberTooBig(ZipArchive $zip, string $name): bool
    {
        $stat = $zip->statName($name);
        return is_array($stat) && (int) ($stat['size'] ?? 0) > self::MEMBER_BYTES;
    }

    /**
     * `sharedStrings.xml` → 문자열 목록 (정규식만).
     *
     * 한 `<si>` 안에 서식이 갈린 조각(`<r><t>..</t></r>` 여럿)이 들어 있을 수 있으므로
     * `<si>` 단위로 자른 뒤 그 안의 `<t>` 를 모두 이어 붙인다. `<rPh>`(후리가나) 같은
     * 부속 태그도 `<t>` 를 갖지만, 한국어 파일에는 나오지 않고 나와도 뒤에 붙는 글자일 뿐이다.
     *
     * @return list<string>
     */
    private static function sharedStrings(string $xml): array
    {
        $out = [];
        if (preg_match_all('#<si\b[^>]*>(.*?)</si>#s', $xml, $items, PREG_SET_ORDER) === false) {
            return [];
        }
        foreach ($items as $item) {
            $inner = (string) ($item[1] ?? '');
            $text  = '';
            if (preg_match_all('#<t\b[^>]*>(.*?)</t>#s', $inner, $parts, PREG_SET_ORDER) !== false) {
                foreach ($parts as $part) {
                    $text .= (string) ($part[1] ?? '');
                }
            }
            $out[] = self::clean($text);
            if (count($out) >= self::SHEET_ROWS * self::SHEET_COLS) {
                break;   // 문자열 목록이 비정상적으로 길면 거기서 멈춘다 (R-42)
            }
        }
        return $out;
    }

    /**
     * 시트 XML → 행 배열 (정규식만).
     *
     * 셀은 `<c r="B7" t="s"><v>12</v></c>` 또는 `<c r="B7" t="inlineStr"><is><t>..</t></is></c>`
     * 꼴이다. `t` 속성이
     *   `s`         → `<v>` 는 sharedStrings 의 **번호**
     *   `inlineStr` → `<is>` 안의 `<t>` 가 글자
     *   그 밖(없음·`n`·`str`·`b`) → `<v>` 를 글자 그대로
     * 로 읽는다. `<f>`(수식)는 버린다 — 계산된 값 `<v>` 만 본다.
     *
     * 빈 칸은 아예 `<c>` 가 없을 수 있다(희소 저장). 그래서 `r` 속성의 **열 글자**로 자리를
     * 잡고 중간 빈 칸을 빈 문자열로 채운다. `r` 이 없는 셀은 바로 앞 칸의 다음 자리로 본다.
     *
     * @param list<string> $shared
     * @return array{0: list<list<string>>, 1: bool} [행 배열, 잘렸나]
     */
    private static function sheetRows(string $xml, array $shared): array
    {
        $rows      = [];
        $truncated = false;

        if (preg_match_all('#<row\b[^>]*>(.*?)</row>#s', $xml, $rowSets, PREG_SET_ORDER) === false) {
            return [[], false];
        }

        foreach ($rowSets as $rowSet) {
            if (count($rows) >= self::SHEET_ROWS) {
                $truncated = true;
                break;
            }
            $inner = (string) ($rowSet[1] ?? '');
            $cells = [];
            $next  = 0;   // 다음 셀이 들어갈 열 번호(0부터)

            if (preg_match_all('#<c\b([^>]*)(?:/>|>(.*?)</c>)#s', $inner, $cellSets, PREG_SET_ORDER) !== false) {
                foreach ($cellSets as $cellSet) {
                    $attrs = (string) ($cellSet[1] ?? '');
                    $body  = (string) ($cellSet[2] ?? '');

                    $col = self::columnIndex($attrs);
                    if ($col === null) {
                        $col = $next;
                    }
                    if ($col >= self::SHEET_COLS) {
                        $truncated = true;
                        continue;
                    }
                    // 앞의 빈 칸을 메운다
                    for ($i = count($cells); $i < $col; $i++) {
                        $cells[$i] = '';
                    }
                    $cells[$col] = self::cellValue($attrs, $body, $shared);
                    $next = $col + 1;
                }
            }

            ksort($cells, SORT_NUMERIC);
            $rows[] = array_values($cells);
        }

        // 뒤쪽의 **완전히 빈 행**은 버린다 — 엑셀은 편집 흔적만으로 빈 행을 남기고,
        // 그것을 오류 행으로 세면 미리보기가 "오류 수천 건" 이 되어 쓸모가 없어진다.
        while ($rows !== [] && self::isBlankRow($rows[count($rows) - 1])) {
            array_pop($rows);
        }

        return [$rows, $truncated];
    }

    /** 셀의 `r` 속성(`B7`)에서 **열 번호**(0부터)를 뽑는다. 없으면 null */
    private static function columnIndex(string $attrs): ?int
    {
        if (preg_match('#\br="([A-Z]{1,3})[0-9]{1,7}"#', $attrs, $m) !== 1) {
            return null;
        }
        $letters = $m[1];
        $index   = 0;
        $len     = strlen($letters);
        for ($i = 0; $i < $len; $i++) {
            $index = $index * 26 + (ord($letters[$i]) - 64);   // 'A' = 1
        }
        return $index - 1;
    }

    /**
     * 셀 하나의 값을 **문자열로** 읽는다 (수식 무시).
     *
     * @param list<string> $shared
     */
    private static function cellValue(string $attrs, string $body, array $shared): string
    {
        $type = preg_match('#\bt="([a-zA-Z]+)"#', $attrs, $m) === 1 ? $m[1] : '';

        if ($type === 'inlineStr') {
            $text = '';
            if (preg_match_all('#<t\b[^>]*>(.*?)</t>#s', $body, $parts, PREG_SET_ORDER) !== false) {
                foreach ($parts as $part) {
                    $text .= (string) ($part[1] ?? '');
                }
            }
            return self::clean($text);
        }

        // `<f>` 수식은 보지 않는다. 첫 `<v>` 만 읽는다.
        if (preg_match('#<v\b[^>]*>(.*?)</v>#s', $body, $vm) !== 1) {
            return '';
        }
        $raw = (string) $vm[1];

        if ($type === 's') {
            // sharedStrings 의 번호. 목록 밖이면 빈 값이다(파일이 망가진 경우).
            if (preg_match('/^[0-9]{1,9}$/', trim($raw)) !== 1) {
                return '';
            }
            $idx = (int) trim($raw);
            return $shared[$idx] ?? '';
        }

        return self::clean($raw);
    }

    /**
     * XML 조각 하나를 사람이 읽는 글자로 — 엔티티를 풀고 UTF-8 로 맞추고 제어문자를 씻는다.
     *
     * 엔티티는 **태그를 이미 버린 뒤에** 한 번만 푼다 (Preview::xmlText 와 같은 순서).
     * 풀린 결과가 다시 태그로 해석되는 일이 없고, 화면은 이 값을 `textContent` 로만 넣는다.
     */
    private static function clean(string $value): string
    {
        if ($value === '') {
            return '';
        }
        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
        [$utf8]  = Preview::toUtf8($decoded);
        $utf8    = Preview::scrubControls($utf8);
        $utf8    = trim($utf8);
        if (iconv_strlen($utf8, 'UTF-8') > 500) {
            $cut  = iconv_substr($utf8, 0, 500, 'UTF-8');
            $utf8 = is_string($cut) ? $cut : $utf8;
        }
        return $utf8;
    }

    /** @param list<string> $row */
    private static function isBlankRow(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim($cell) !== '') {
                return false;
            }
        }
        return true;
    }

    /**
     * **내보내기** 한 칸 — CSV 인젝션 차단 (PRD F-29 「오류 목록 내려받기」).
     *
     * 엑셀·번호스는 `=`·`+`·`-`·`@`(그리고 탭·캐리지리턴이 앞에 붙은 그 네 글자)로 시작하는
     * 칸을 **수식**으로 해석한다. 우리 DB 의 값이 그대로 남의 엑셀에서 실행되는 경로가
     * 되므로, 앞에 작은따옴표 하나를 붙여 글자로 고정한다. 그 뒤 일반 CSV 규칙(따옴표 감싸기,
     * 안의 따옴표는 두 번)을 적용한다.
     */
    public static function csvCell(string $value): string
    {
        $value = Preview::scrubControls($value);
        if ($value !== '' && preg_match('/^[\t\r]*[=+\-@]/', $value) === 1) {
            $value = "'" . $value;
        }
        return '"' . str_replace('"', '""', $value) . '"';
    }
}
