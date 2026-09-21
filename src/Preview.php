<?php
declare(strict_types=1);

/**
 * src/Preview.php — 미리보기 내용 추출 (PRD F-18, ARCHITECTURE 5.8, SECURITY R-41 · R-42)
 *
 * 이 파일은 **순수 함수 모음**이다. DB 도 HTTP 도 세션도 만지지 않는다 —
 * 받은 파일 경로(호출자가 `Storage::pathFor()` 로 만든 값)를 읽어 **글자만** 돌려준다.
 * 권한 판단은 여기 없다. 판단은 핸들러가 `Authz::decideRead()` 로 하고(ARCH 4.1, 5.8 [2]),
 * 이 클래스는 이미 허용된 파일의 내용을 꺼내는 일만 한다.
 *
 * 지키는 것
 *   1) **갈래는 DB `files.ext` 로만 정한다** (ARCH 5.8 [3]). 요청 파라미터·파일 내용·
 *      Content-Type 헤더로 형식을 고르지 않는다 — 그러면 `.zip` 을 `image` 라고 속여
 *      inline 전송을 유도할 수 있다.
 *   2) **상한을 상수 하나에 모은다** (R-42). "정상 업로드(100MB 이하)지만 파서를 오래 붙잡는"
 *      파일 — 항목 수백만 개짜리 zip, 수백 MB `document.xml`, 한 줄짜리 1GB csv — 이
 *      PHP 프로세스를 점유해 다른 사용자의 요청을 막는 것을 막는다.
 *   3) **zip 은 중앙 디렉터리와 지정한 멤버만** 읽는다. `extractTo`·`getStream`·`getFromIndex`
 *      를 쓰지 않는다 — 압축을 푸는 경로 자체를 만들지 않는다(zip slip·압축 폭탄).
 *   4) **XML 파서를 쓰지 않는다** (DOMDocument·SimpleXML·XMLReader·libxml_*). 정규식으로
 *      텍스트 노드만 모은다 — 외부 엔티티(XXE)·엔티티 확장(billion laughs)의 통로가
 *      원리적으로 존재하지 않는다.
 *   5) 돌려주는 문자열은 **UTF-8 로 정규화**하고 제어문자(탭·개행 제외)를 U+FFFD 로 바꾼다.
 *      화면은 이 값을 `textContent` 로만 넣는다(PRD S-03) — 태그·스크립트는 글자로만 보인다.
 *
 * 문자열 처리는 `iconv` 계열만 쓴다. 이 VM 에는 mbstring 확장이 없다(12-1 학습 기록).
 * `ZipArchive` 는 php8.1-zip (Ubuntu 22.04 배포판 패키지, deploy/DEPENDENCIES.md 3장).
 */
final class Preview
{
    /**
     * 확장자 → 갈래 (ARCH 5.8 「갈래와 상한」 표).
     * 여기 없는 확장자(hwp 등)는 전부 `none` — 기본 정보만 보여 준다.
     *
     * @var array<string,string>
     */
    public const KIND_BY_EXT = [
        'png'  => 'image',
        'jpg'  => 'image',
        'jpeg' => 'image',
        'gif'  => 'image',
        'pdf'  => 'pdf',
        'txt'  => 'text',
        'md'   => 'text',
        'csv'  => 'table',
        'zip'  => 'archive',
        'docx' => 'office',
        'pptx' => 'office',
        'xlsx' => 'office',
        'hwpx' => 'office',
    ];

    /** 텍스트·표에서 **읽어들이는** 최대 바이트 (파일 앞부분만, 1MiB) */
    public const TEXT_READ_BYTES = 1048576;

    /** 텍스트 응답의 최대 바이트 (64KiB) */
    public const TEXT_OUT_BYTES = 65536;

    /** 표 미리보기 최대 행 */
    public const TABLE_ROWS = 200;

    /** 표 미리보기 최대 열 */
    public const TABLE_COLS = 50;

    /** zip 항목 목록에 보여 주는 최대 개수 (전체 개수는 total_entries 로 따로 알린다) */
    public const ARCHIVE_ENTRIES = 200;

    /** office 문서에서 꺼내는 멤버 하나의 최대 압축 해제 크기 (8MiB) */
    public const OFFICE_MEMBER_BYTES = 8388608;

    /** office 발췌 최대 글자 수 (20,000자) */
    public const OFFICE_OUT_CHARS = 20000;

    /**
     * office 원문을 모으는 동안의 안전판 — 발췌 상한의 넉넉한 배수(바이트).
     * 여기를 넘으면 남은 멤버를 더 읽지 않는다 (R-42: 슬라이드 수백 장짜리 pptx).
     */
    private const OFFICE_RAW_LIMIT = 262144;

    /**
     * 확장자로 갈래를 정한다. 값은 **DB `files.ext`** 만 넘긴다 (ARCH 5.8 [3]).
     * 목록 응답의 `preview_kind` 도 이 함수 하나로 만든다 — 화면은 확장자를 보지 않는다.
     */
    public static function kindFor(string $ext): string
    {
        $key = strtolower(trim($ext));
        return self::KIND_BY_EXT[$key] ?? 'none';
    }

    /**
     * 텍스트 원문 (txt · md) — 앞 1MiB 만 읽어 UTF-8 로 맞추고 64KiB 로 자른다.
     *
     * @return array{content:string, truncated:bool, encoding:string}
     */
    public static function text(string $path): array
    {
        [$raw, $more] = self::readHead($path, self::TEXT_READ_BYTES);
        [$utf8, $encoding] = self::toUtf8($raw);
        $utf8 = self::scrubControls($utf8);

        [$content, $cut] = self::cutBytes($utf8, self::TEXT_OUT_BYTES);

        return [
            'content'   => $content,
            'truncated' => $more || $cut,
            'encoding'  => $encoding,
        ];
    }

    /**
     * 표 (csv) — 앞 1MiB 를 줄 단위로 `str_getcsv`. 200행 × 50열 상한.
     *
     * 줄 나누기는 CRLF·LF·CR 를 모두 받는다. 따옴표 안에 개행이 든 칸은 줄이 나뉘지만,
     * 미리보기는 "무슨 내용인지 본다" 가 목적이라 원문을 그대로 글자로 보여 주면 충분하다
     * (원본이 필요하면 다운로드가 있다).
     *
     * @return array{rows: list<list<string>>, truncated: bool}
     */
    public static function table(string $path): array
    {
        [$raw, $more] = self::readHead($path, self::TEXT_READ_BYTES);
        [$utf8] = self::toUtf8($raw);
        $utf8 = self::scrubControls($utf8);

        $lines     = preg_split('/\r\n|\n|\r/', $utf8);
        $lines     = is_array($lines) ? $lines : [];
        $truncated = $more;
        $rows      = [];

        foreach ($lines as $index => $line) {
            if (count($rows) >= self::TABLE_ROWS) {
                $truncated = true;
                break;
            }
            // 마지막 줄이 빈 줄이면(파일 끝 개행) 행으로 세지 않는다.
            if ($line === '' && $index === count($lines) - 1) {
                continue;
            }
            $cells = str_getcsv($line);
            if (count($cells) > self::TABLE_COLS) {
                $cells     = array_slice($cells, 0, self::TABLE_COLS);
                $truncated = true;
            }
            $row = [];
            foreach ($cells as $cell) {
                $row[] = $cell === null ? '' : (string) $cell;
            }
            $rows[] = $row;
        }

        return ['rows' => $rows, 'truncated' => $truncated];
    }

    /**
     * zip 항목 목록 — **중앙 디렉터리만** 읽는다. 내용은 한 바이트도 풀지 않는다.
     *
     * `numFiles`·`statIndex` 외의 API 를 쓰지 않으므로, 압축 폭탄이든 zip slip 이든
     * "풀기" 라는 동작 자체가 이 경로에 없다.
     *
     * @return array{entries: list<array{name:string,size:int,compressed:int,is_dir:bool}>, total_entries:int, truncated:bool}
     */
    public static function archive(string $path): array
    {
        $zip    = self::openZip($path);
        $total  = $zip->numFiles;
        $limit  = min($total, self::ARCHIVE_ENTRIES);
        $items  = [];

        for ($i = 0; $i < $limit; $i++) {
            $stat = $zip->statIndex($i);
            if (!is_array($stat)) {
                continue;
            }
            $name = self::entryName((string) ($stat['name'] ?? ''));
            $items[] = [
                'name'       => $name,
                'size'       => (int) ($stat['size'] ?? 0),
                'compressed' => (int) ($stat['comp_size'] ?? 0),
                'is_dir'     => $name !== '' && substr($name, -1) === '/',
            ];
        }
        $zip->close();

        return [
            'entries'       => $items,
            'total_entries' => $total,
            'truncated'     => $total > $limit,
        ];
    }

    /**
     * office 문서 텍스트 발췌 (docx · pptx · xlsx · hwpx).
     *
     * 멤버는 **이름으로 지정한 것만** 꺼낸다(ARCH 5.8 표):
     *   docx  `word/document.xml`            — `<w:t>`  · 단락 `</w:p>`
     *   pptx  `ppt/slides/slideN.xml` (번호순) — `<a:t>`  · 단락 `</a:p>`
     *   xlsx  `xl/sharedStrings.xml`         — `<t>` (문자열 목록 발췌)
     *   hwpx  `Contents/sectionN.xml` (번호순) — `<hp:t>` · 단락 `</hp:p>`
     *
     * 멤버 하나는 `statName` 의 압축 해제 크기가 8MiB 이하일 때만 읽는다.
     * 열 수 없거나(손상) 멤버가 하나도 없으면 예외 — 핸들러가 `error parse_failed` 로 남기고
     * 화면에는 정보 카드만 보여 준다.
     *
     * @return array{excerpt:string, truncated:bool, parts:int}
     */
    public static function office(string $path, string $ext): array
    {
        $ext  = strtolower(trim($ext));
        $zip  = self::openZip($path);

        try {
            $members = self::officeMembers($zip, $ext);
            if ($members === []) {
                throw new RuntimeException('미리보기에 쓸 수 있는 내용이 없습니다');
            }

            $text      = '';
            $parts     = 0;
            $truncated = false;

            foreach ($members as $member) {
                if (strlen($text) >= self::OFFICE_RAW_LIMIT) {
                    $truncated = true;
                    break;
                }
                $stat = $zip->statName($member);
                if (!is_array($stat)) {
                    continue;
                }
                if ((int) ($stat['size'] ?? 0) > self::OFFICE_MEMBER_BYTES) {
                    $truncated = true;
                    continue;   // 너무 큰 멤버는 읽지 않는다 (R-42)
                }
                $xml = $zip->getFromName($member);
                if (!is_string($xml) || $xml === '') {
                    continue;
                }
                $piece = self::xmlText($xml, $ext);
                if ($piece === '') {
                    continue;
                }
                $text .= ($text === '' ? '' : "\n") . $piece;
                $parts++;
            }
        } finally {
            $zip->close();
        }

        if ($parts === 0) {
            throw new RuntimeException('문서에서 글자를 찾지 못했습니다');
        }

        [$utf8] = self::toUtf8($text);
        $utf8 = self::scrubControls($utf8);
        $utf8 = self::squeeze($utf8);

        $length = iconv_strlen($utf8, 'UTF-8');
        if ($length === false) {
            throw new RuntimeException('발췌 문자열을 셀 수 없습니다');
        }
        if ($length > self::OFFICE_OUT_CHARS) {
            $cut = iconv_substr($utf8, 0, self::OFFICE_OUT_CHARS, 'UTF-8');
            $utf8 = is_string($cut) ? $cut : $utf8;
            $truncated = true;
        }

        return ['excerpt' => $utf8, 'truncated' => $truncated, 'parts' => $parts];
    }

    // ───────────────────────── 내부 도우미 ─────────────────────────

    /**
     * 파일 앞부분만 읽는다. 경로는 호출자가 `Storage::pathFor()` 로 만든 값이며
     * 여기서 문자열을 이어 붙이지 않는다 (PRD S-06).
     *
     * @return array{0:string, 1:bool} [읽은 바이트, 뒤에 더 있나]
     */
    private static function readHead(string $path, int $max): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('미리보기할 파일을 열 수 없습니다');
        }
        $size = filesize($path);
        $fh   = fopen($path, 'rb');
        if ($fh === false) {
            throw new RuntimeException('미리보기할 파일을 열 수 없습니다');
        }
        $raw = fread($fh, $max);
        fclose($fh);
        if ($raw === false) {
            throw new RuntimeException('미리보기할 파일을 읽을 수 없습니다');
        }
        $more = $size !== false && $size > $max;
        return [$raw, $more];
    }

    /**
     * UTF-8 로 맞춘다 — ① 그대로 UTF-8 인가(끝이 잘린 문자 3바이트까지는 떼어 본다)
     * ② 아니면 CP949 로 보고 변환 ③ 둘 다 아니면 `unknown` 으로 보고 UTF-8 이 아닌 바이트를
     * 치환문자(U+FFFD)로 바꾼다.
     *
     * @return array{0:string, 1:string} [문자열, encoding]
     *
     * ★ 19단계에서 `private` → `public` 으로만 바꿨다(그 호출자는 2026-09-21 에 없어졌다).
     *   로직은 한 글자도 바뀌지 않았다 — 같은 코드를 두 벌로 두면 언젠가 한쪽만 고쳐지므로,
     *   csv 인코딩 판정의 자리는 앞으로도 이 한 곳이다.
     */
    public static function toUtf8(string $raw): array
    {
        if ($raw === '') {
            return ['', 'UTF-8'];
        }

        // ① UTF-8 그대로. 앞 1MiB 만 읽었으므로 끝의 한 글자가 잘렸을 수 있다 → 최대 3바이트 떼 본다.
        $probe = $raw;
        for ($i = 0; $i < 4 && $probe !== ''; $i++) {
            if (preg_match('//u', $probe) === 1) {
                return [$probe, 'UTF-8'];
            }
            $probe = substr($probe, 0, strlen($probe) - 1);
        }

        // ② CP949 (EUC-KR 확장) — 사내 문서에서 가장 흔한 비 UTF-8 인코딩
        $converted = @iconv('CP949', 'UTF-8//IGNORE', $raw);
        if (is_string($converted) && $converted !== '' && preg_match('//u', $converted) === 1) {
            return [$converted, 'CP949'];
        }

        // ③ 알 수 없음 — UTF-8 로 읽히지 않는 바이트를 치환문자로 바꾼다
        $safe = preg_replace('/[^\x09\x0a\x0d\x20-\x7e]/', "\u{FFFD}", $raw);
        return [is_string($safe) ? $safe : '', 'unknown'];
    }

    /**
     * 제어문자(NUL 등)를 치환문자로 바꾼다. 탭·개행·복귀는 남긴다.
     * UTF-8 의 여러 바이트 문자는 모든 바이트가 0x80 이상이라 바이트 단위 치환이 안전하다.
     *
     * ★ 19단계(F-29)에서 `private` → `public` 으로만 바꿨다(toUtf8 과 같은 이유). 로직 무변경.
     */
    public static function scrubControls(string $value): string
    {
        $out = preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', "\u{FFFD}", $value);
        return is_string($out) ? $out : '';
    }

    /**
     * 바이트 상한으로 자르되 UTF-8 글자 경계를 깨지 않는다.
     *
     * @return array{0:string, 1:bool} [자른 문자열, 잘렸나]
     */
    private static function cutBytes(string $value, int $max): array
    {
        if (strlen($value) <= $max) {
            return [$value, false];
        }
        $cut = substr($value, 0, $max);
        for ($i = 0; $i < 4 && $cut !== ''; $i++) {
            if (preg_match('//u', $cut) === 1) {
                break;
            }
            $cut = substr($cut, 0, strlen($cut) - 1);
        }
        return [$cut, true];
    }

    /** 공백·빈 줄 정리 — 태그 사이 공백이 겹쳐 생긴 여백을 줄인다 */
    private static function squeeze(string $value): string
    {
        $out = preg_replace('/[ \t]+/', ' ', $value);
        $out = is_string($out) ? $out : $value;
        $out = preg_replace('/ *\n *(\n *)+/', "\n\n", $out);
        $out = is_string($out) ? $out : $value;
        $out = preg_replace('/ *\n */', "\n", $out);
        return is_string($out) ? trim($out) : trim($value);
    }

    /** zip 항목 이름 — UTF-8 이 아니면 CP949 로 보고 바꾼다(옛 압축 프로그램) */
    private static function entryName(string $name): string
    {
        if ($name === '') {
            return '';
        }
        if (preg_match('//u', $name) !== 1) {
            $converted = @iconv('CP949', 'UTF-8//IGNORE', $name);
            $name = is_string($converted) ? $converted : '';
        }
        [$safe] = self::cutBytes(self::scrubControls($name), 500);
        return $safe;
    }

    /**
     * RDONLY 로만 연다. 실패는 예외 → 핸들러가 `error parse_failed` 로 남긴다.
     *
     * ★ 19단계(F-29)에서 `private` → `public` 으로만 바꿨다. 로직 무변경.
     *   xlsx 가져오기도 **같은 방식으로만** zip 을 열어야 한다 — RDONLY, extractTo 없음,
     *   중앙 디렉터리 → 멤버 크기 확인 → 상한 안에서만 읽기(머리말 규칙 3).
     */
    public static function openZip(string $path): ZipArchive
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('zip 확장이 없습니다');
        }
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('미리보기할 파일을 열 수 없습니다');
        }
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('압축 파일을 열 수 없습니다');
        }
        return $zip;
    }

    /**
     * 갈래별로 꺼낼 멤버 이름 목록. 이름은 **코드에 적힌 고정 문자열**이거나
     * 중앙 디렉터리에서 정규식으로 고른 번호 있는 이름뿐이다.
     *
     * @return list<string>
     */
    private static function officeMembers(ZipArchive $zip, string $ext): array
    {
        if ($ext === 'docx') {
            return $zip->statName('word/document.xml') === false ? [] : ['word/document.xml'];
        }
        if ($ext === 'xlsx') {
            return $zip->statName('xl/sharedStrings.xml') === false ? [] : ['xl/sharedStrings.xml'];
        }
        if ($ext === 'pptx') {
            return self::numberedMembers($zip, '#^ppt/slides/slide(\d+)\.xml$#');
        }
        if ($ext === 'hwpx') {
            return self::numberedMembers($zip, '#^Contents/section(\d+)\.xml$#');
        }
        return [];
    }

    /**
     * 중앙 디렉터리에서 `…N.xml` 형태의 멤버를 골라 **번호 오름차순**으로 돌려준다.
     * 이름만 읽고(`getNameIndex`) 내용은 여기서 풀지 않는다.
     *
     * @return list<string>
     */
    private static function numberedMembers(ZipArchive $zip, string $pattern): array
    {
        $found = [];
        $count = $zip->numFiles;
        for ($i = 0; $i < $count; $i++) {
            $name = $zip->getNameIndex($i);
            if (!is_string($name)) {
                continue;
            }
            if (preg_match($pattern, $name, $m) === 1) {
                $found[(int) $m[1]] = $name;
            }
        }
        ksort($found, SORT_NUMERIC);
        return array_values($found);
    }

    /**
     * XML 에서 **텍스트 노드만** 정규식으로 모은다 (XML 파서 금지 — ARCH 5.8, R-42).
     *
     * 텍스트 태그와 단락 끝 태그를 한 번의 스캔으로 함께 찾는다. 텍스트 사이는 공백 하나,
     * 단락 끝은 개행이다. 엔티티는 마지막에 한 번 푼다 — 태그는 이미 버려졌으므로
     * 풀린 결과가 다시 태그로 해석되는 일은 없다(그리고 화면은 textContent 로만 넣는다).
     */
    private static function xmlText(string $xml, string $ext): string
    {
        if ($ext === 'docx') {
            $pattern = '#<w:t(?:\s[^>]*)?>(.*?)</w:t>|</w:p>#s';
            $break   = '</w:p>';
        } elseif ($ext === 'pptx') {
            $pattern = '#<a:t(?:\s[^>]*)?>(.*?)</a:t>|</a:p>#s';
            $break   = '</a:p>';
        } elseif ($ext === 'hwpx') {
            $pattern = '#<hp:t(?:\s[^>]*)?>(.*?)</hp:t>|</hp:p>#s';
            $break   = '</hp:p>';
        } elseif ($ext === 'xlsx') {
            $pattern = '#<t(?:\s[^>]*)?>(.*?)</t>#s';
            $break   = '';
        } else {
            return '';
        }

        if (preg_match_all($pattern, $xml, $matches, PREG_SET_ORDER) === false) {
            return '';
        }

        $out  = '';
        $last = '';
        foreach ($matches as $m) {
            if ($break !== '' && $m[0] === $break) {
                if ($out !== '' && $last !== "\n") {
                    $out .= "\n";
                    $last = "\n";
                }
                continue;
            }
            $piece = isset($m[1]) ? (string) $m[1] : '';
            if ($piece === '') {
                continue;
            }
            if ($out !== '' && $last !== "\n") {
                $out .= ' ';
            }
            $out .= $piece;
            $last = 'text';
            if (strlen($out) >= self::OFFICE_RAW_LIMIT) {
                break;   // 멤버 하나가 지나치게 길면 여기서 멈춘다 (R-42)
            }
        }

        return html_entity_decode($out, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
