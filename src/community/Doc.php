<?php
declare(strict_types=1);

/**
 * src/community/Doc.php — 블록 본문(문서 JSON)의 검증기와 파생값 추출
 *
 * 요구사항: 커뮤니티 `C:F-15`·`C:S-11`, `../community/ARCHITECTURE.md` 12.2~12.4, 수락 기준 C:T-14.
 *
 * 이 파일이 지키는 약속 세 가지
 *   ① **본문은 블록 JSON 만 저장한다.** HTML 문자열을 받지도, 만들지도 않는다.
 *   ② **모르는 것은 400 이다.** 모르는 블록 타입·모르는 키·허용 목록 밖 값·모르는 색·모르는
 *      링크 스킴은 조용히 바꾸거나 지우지 않고 오류 목록에 담아 돌려준다. 「고쳐서 저장」은
 *      사용자가 쓴 내용을 서버가 말없이 바꾸는 일이고, 그 순간 저장된 것과 화면이 갈라진다.
 *   ③ **허용 목록은 파일 하나다.** `community/doc-schema.json`(사본: `public/assets/community/`)
 *      을 검증기·렌더러 두 벌·에디터가 함께 읽는다. 목록이 여러 벌이면 언젠가 한쪽만 고쳐진다.
 *
 * 쓰는 곳
 *   · `src/community/handlers/pages_update.php` — 저장 전 검사(실패 400 + `errors[]`)
 *   · `src/community/handlers/pages_create.php` — 새 페이지의 첫 본문
 *   · `src/community/DocRender.php` — 서버 렌더러가 같은 허용 목록을 읽는다
 *
 * 하지 않는 것
 *   · DB 를 읽지 않는다(멘션 대상 확인·첨부 등급 검사는 5단계 블록과 함께 들어온다).
 *   · 값을 고치지 않는다. `validate()` 는 판단만 하고 입력을 돌려주지 않는다.
 */
final class Doc
{
    /** 허용 목록 파일(사본이 아니라 앱이 읽는 자리). 코드 상수이며 경로를 조립하지 않는다. */
    private const SCHEMA_FILE = __DIR__ . '/../../public/assets/community/doc-schema.json';

    /** 한 번의 검사에서 담아 돌려주는 오류의 최대 개수 — 응답이 문서만큼 커지지 않게 한다. */
    private const MAX_ERRORS = 50;

    /** 파생 `plain_text` 의 상한 (검색·발췌용이라 본문 전체를 그대로 두 벌 갖지 않는다) */
    private const PLAIN_TEXT_MAX = 200000;

    /** @var array<string,mixed>|null 한 요청 안에서 파일을 두 번 읽지 않는다 */
    private static ?array $schema = null;

    /**
     * 허용 목록 전체. 모양이 어긋나면 예외다 — 조용히 비어 있는 목록으로 되돌아가지 않는다
     * (`Apps::all()`·`Wiki::validate` 와 같은 태도).
     *
     * @return array<string,mixed>
     */
    public static function schema(): array
    {
        if (self::$schema !== null) {
            return self::$schema;
        }
        $raw = @file_get_contents(self::SCHEMA_FILE);
        if ($raw === false) {
            throw new RuntimeException('문서 허용 목록 파일을 읽을 수 없습니다');
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('문서 허용 목록이 객체가 아닙니다');
        }
        foreach (['schema', 'limits', 'colors', 'marks', 'link_schemes', 'blocks', 'stage', 'stage_marks', 'languages'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new RuntimeException('문서 허용 목록에 키가 없습니다 — ' . $key);
            }
        }
        self::$schema = $data;
        return $data;
    }

    /**
     * 허용 목록의 상한 한 칸 (`limits`). 없는 이름이면 예외다 — 0 으로 조용히 되돌아가면
     * 「상한이 없는 것」이 되어 버린다.
     */
    public static function limit(string $key): int
    {
        $limits = self::schema()['limits'];
        if (!array_key_exists($key, $limits)) {
            throw new RuntimeException('문서 허용 목록에 상한이 없습니다 — ' . $key);
        }
        return (int) $limits[$key];
    }

    /** 이 단계에서 쓸 수 있는 블록 타입 목록 (`stage` 표의 1~n 단계를 합친 것) */
    public static function typesForStage(int $stage): array
    {
        $out = [];
        foreach (self::schema()['stage'] as $k => $types) {
            if ((int) $k <= $stage && is_array($types)) {
                foreach ($types as $t) {
                    $out[] = (string) $t;
                }
            }
        }
        return $out;
    }

    /** 이 단계에서 쓸 수 있는 마크 이름 목록 */
    public static function marksForStage(int $stage): array
    {
        $out = [];
        foreach (self::schema()['stage_marks'] as $k => $marks) {
            if ((int) $k <= $stage && is_array($marks)) {
                foreach ($marks as $m) {
                    $out[] = (string) $m;
                }
            }
        }
        return $out;
    }

    /** 기본 색 18값(글자 9 + 배경 9). 팔레트 이름(`pal:…`)은 4단계에 표가 생긴 뒤에 열린다. */
    public static function colorValues(): array
    {
        $s      = self::schema();
        $suffix = (string) $s['color_suffix_bg'];
        $out    = [];
        foreach ($s['colors'] as $c) {
            $out[] = (string) $c;
            $out[] = (string) $c . $suffix;
        }
        return $out;
    }

    /**
     * 문서 하나를 검사한다 (ARCH 12.3).
     *
     * 검사 순서: 크기(바이트) → `schema` → 블록 배열 → 블록 수·깊이 →
     *            타입 허용·단계 → 키 집합 정확히 → 값 형식 → `id` UUIDv4 유일 →
     *            리치 텍스트·마크·색·링크 스킴 → 제어문자
     *
     * @param  array<string,mixed> $doc
     * @return array{ok:bool,errors:list<array{path:string,reason:string}>}
     */
    public static function validate(array $doc, int $stage): array
    {
        $s      = self::schema();
        $limits = $s['limits'];
        $errors = [];

        $add = static function (string $path, string $reason) use (&$errors): void {
            if (count($errors) < self::MAX_ERRORS) {
                $errors[] = ['path' => $path, 'reason' => $reason];
            }
        };

        // ─── [1] 크기 (바이트) ─────────────────────────────────────
        $encoded = json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            $add('', 'bad_encoding');
            return ['ok' => false, 'errors' => $errors];
        }
        if (strlen($encoded) > (int) $limits['doc_bytes']) {
            $add('', 'too_large');
            return ['ok' => false, 'errors' => $errors];
        }

        // ─── [2] 문서 겉모양 — 키는 `schema`·`blocks` 둘뿐이다 ────
        $docKeys = array_keys($doc);
        sort($docKeys);
        if ($docKeys !== ['blocks', 'schema']) {
            foreach (array_diff(array_keys($doc), ['schema', 'blocks']) as $extra) {
                $add((string) $extra, 'unknown_key');
            }
            foreach (array_diff(['schema', 'blocks'], array_keys($doc)) as $missing) {
                $add((string) $missing, 'missing_key');
            }
            return ['ok' => false, 'errors' => $errors];
        }
        if ($doc['schema'] !== (int) $s['schema']) {
            $add('schema', 'bad_schema');
            return ['ok' => false, 'errors' => $errors];
        }
        if (!self::isList($doc['blocks'])) {
            $add('blocks', 'not_array');
            return ['ok' => false, 'errors' => $errors];
        }

        // ─── [3] 재귀 한 벌 ────────────────────────────────────────
        $state = [
            'count'  => 0,
            'ids'    => [],
            'types'  => self::typesForStage($stage),
            'marks'  => self::marksForStage($stage),
            'colors' => self::colorValues(),
        ];
        self::walk($doc['blocks'], 'blocks', 1, $stage, $state, $add, $limits, $s);

        return ['ok' => $errors === [], 'errors' => $errors];
    }

    /**
     * 블록 배열 하나를 훑는다. 깊이·개수·id 유일성은 $state 로 이어진다.
     *
     * @param array<string,mixed> $state
     * @param array<string,mixed> $limits
     * @param array<string,mixed> $s
     */
    private static function walk(array $list, string $path, int $depth, int $stage, array &$state, callable $add, array $limits, array $s): void
    {
        if ($depth > (int) $limits['depth']) {
            $add($path, 'too_deep');
            return;
        }

        foreach ($list as $i => $block) {
            $p = $path . '[' . $i . ']';

            $state['count']++;
            if ($state['count'] > (int) $limits['blocks']) {
                $add($p, 'too_many_blocks');
                return;
            }

            if (!self::isObjectish($block)) {
                $add($p, 'not_object');
                continue;
            }

            // ── 타입 ──
            if (!isset($block['type']) || !is_string($block['type'])) {
                $add($p . '.type', 'missing_key');
                continue;
            }
            $type = $block['type'];
            if (!array_key_exists($type, $s['blocks'])) {
                $add($p . '.type', 'unknown_type');
                continue;
            }
            if (!in_array($type, $state['types'], true)) {
                $add($p . '.type', 'type_not_in_stage');
                continue;
            }

            $spec = $s['blocks'][$type];

            // ── 키 집합 정확히 (공통 키 + 그 타입의 키) ──
            $required = ['type', 'id'];
            $optional = ['color', 'align', 'size'];
            foreach ($spec as $field => $shape) {
                if (is_string($shape) && substr($shape, -1) === '?') {
                    $optional[] = (string) $field;
                } elseif ($field === 'children') {
                    $required[] = 'children';
                } else {
                    $required[] = (string) $field;
                }
            }
            foreach (array_keys($block) as $k) {
                $key = (string) $k;
                if (!in_array($key, $required, true) && !in_array($key, $optional, true)) {
                    $add($p . '.' . $key, 'unknown_key');
                }
            }
            foreach ($required as $key) {
                if (!array_key_exists($key, $block)) {
                    $add($p . '.' . $key, 'missing_key');
                }
            }

            // ── id — UUIDv4, 문서 안에서 유일 ──
            $id = $block['id'] ?? null;
            if (!is_string($id) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id) !== 1) {
                $add($p . '.id', 'bad_id');
            } elseif (isset($state['ids'][$id])) {
                $add($p . '.id', 'duplicate_id');
            } else {
                $state['ids'][$id] = true;
            }

            // ── 공통 꾸밈 세 가지 ──
            if (array_key_exists('color', $block) && $block['color'] !== null) {
                if (!is_string($block['color'])) {
                    $add($p . '.color', 'bad_type');
                } elseif (!in_array($block['color'], $state['colors'], true)) {
                    $add($p . '.color', 'not_in_palette');
                }
            }
            foreach (['align', 'size'] as $field) {
                if (array_key_exists($field, $block)) {
                    $allowed = $s['block_common'][$field];
                    if (!is_string($block[$field]) || !in_array($block[$field], $allowed, true)) {
                        $add($p . '.' . $field, 'bad_value');
                    }
                }
            }

            // ── 타입별 값 ──
            foreach ($spec as $field => $shape) {
                $name = (string) $field;
                if (!array_key_exists($name, $block)) {
                    continue;   // 없는 키는 위 required 검사가 이미 말했다
                }
                self::field($block[$name], $p . '.' . $name, (string) $field, $shape, $type, $depth, $stage, $state, $add, $limits, $s);
            }
        }
    }

    /**
     * 블록의 값 한 칸을 검사한다.
     *
     * @param mixed               $value
     * @param mixed               $shape  허용 목록 파일에 적힌 모양 표기
     * @param array<string,mixed> $state
     */
    private static function field($value, string $p, string $field, $shape, string $type, int $depth, int $stage, array &$state, callable $add, array $limits, array $s): void
    {
        // children — 가질 수 있는 블록만 가진다
        if ($field === 'children') {
            if (!in_array($type, $s['children_allowed'], true)) {
                $add($p, 'children_not_allowed');
                return;
            }
            if (!self::isList($value)) {
                $add($p, 'not_array');
                return;
            }
            self::walk($value, $p, $depth + 1, $stage, $state, $add, $limits, $s);
            return;
        }

        // level 처럼 값 목록이 그대로 적힌 칸
        if (is_array($shape)) {
            if (!in_array($value, $shape, true)) {
                $add($p, 'bad_value');
            }
            return;
        }

        $shape    = (string) $shape;
        $optional = substr($shape, -1) === '?';
        $base     = $optional ? substr($shape, 0, -1) : $shape;

        switch ($base) {
            case 'richtext':
                self::richtext($value, $p, $stage, $state, $add, $limits, $s);
                return;

            case 'bool':
                if (!is_bool($value)) {
                    $add($p, 'bad_type');
                }
                return;

            case 'int':
                if (!is_int($value) || $value < 0 || $value > 1000000) {
                    $add($p, 'bad_value');
                }
                return;

            case 'string':
                // 지금 이 모양을 쓰는 칸은 `code.code` 하나다 — 줄바꿈과 탭만 허용한다.
                if (!is_string($value)) {
                    $add($p, 'bad_type');
                    return;
                }
                if (preg_match('//u', $value) !== 1) {
                    $add($p, 'bad_encoding');
                    return;
                }
                if (iconv_strlen($value, 'UTF-8') > (int) $limits['code_chars']) {
                    $add($p, 'too_long');
                    return;
                }
                if (preg_match('/[^\P{Cc}\n\t]/u', $value) === 1) {
                    $add($p, 'control_char');
                }
                return;

            case 'lang':
                if (!is_string($value) || !in_array($value, $s['languages'], true)) {
                    $add($p, 'bad_value');
                }
                return;

            /*
             * `uuid` — 지금 이 모양을 쓰는 칸은 `child_page.page_id` 하나다 (4단계).
             *
             * ★ **형태만 본다.** 그 페이지가 실제로 있는지·볼 수 있는지는 **여기서 묻지 않는다.**
             *   검증기는 DB 를 읽지 않는 자리이고(ARCH 12.3), 읽기 판정은 페이지를 **그릴 때**
             *   핸들러가 한다(`pages_get.php` 의 `child_titles`). 그래서 남의 페이지 id 를 적어
             *   넣어도 저장은 400 이 아니고 화면에 「볼 수 없는 페이지」가 보인다 (C:T-28).
             *   저장 때 존재를 확인하면 그 400 자체가 「그 UUID 가 있다·없다」를 알려 주는
             *   탐지기가 된다 — 판정을 렌더 시점에 두는 편이 덜 새고 더 정직하다.
             */
            case 'uuid':
                if (!is_string($value)) {
                    $add($p, 'bad_type');
                    return;
                }
                if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value) !== 1) {
                    $add($p, 'bad_id');
                }
                return;

            case 'emoji|icon':
                if (!is_string($value)) {
                    $add($p, 'bad_type');
                    return;
                }
                if (preg_match('//u', $value) !== 1) {
                    $add($p, 'bad_encoding');
                    return;
                }
                $len = iconv_strlen($value, 'UTF-8');
                if ($len < 1 || $len > (int) $limits['icon_chars']) {
                    $add($p, 'too_long');
                    return;
                }
                if (preg_match('/\p{C}/u', $value) === 1) {
                    $add($p, 'control_char');
                }
                return;

            default:
                // 3단계에 없는 모양(`tbox-ref`·`uuid`·`url`·표의 행 배열 …)은 그 블록 타입이
                // 이 단계에 없다는 뜻이라 여기까지 오지 않는다. 그래도 닫힌 실패로 둔다.
                $add($p, 'bad_value');
                return;
        }
    }

    /**
     * 리치 텍스트 — `[{ "t": "문자열", "m": { 마크 } }]`. `m` 은 비어도 **있어야** 한다.
     *
     * @param mixed               $value
     * @param array<string,mixed> $state
     */
    private static function richtext($value, string $p, int $stage, array &$state, callable $add, array $limits, array $s): void
    {
        if (!self::isList($value)) {
            $add($p, 'not_array');
            return;
        }
        if (count($value) > (int) $limits['segments_per_text']) {
            $add($p, 'too_many_segments');
            return;
        }

        foreach ($value as $i => $seg) {
            $sp = $p . '[' . $i . ']';
            if (!self::isObjectish($seg)) {
                $add($sp, 'not_object');
                continue;
            }
            foreach (array_keys($seg) as $k) {
                if ($k !== 't' && $k !== 'm') {
                    $add($sp . '.' . (string) $k, 'unknown_key');
                }
            }
            if (!array_key_exists('t', $seg)) {
                $add($sp . '.t', 'missing_key');
            } elseif (!is_string($seg['t'])) {
                $add($sp . '.t', 'bad_type');
            } elseif (preg_match('//u', $seg['t']) !== 1) {
                $add($sp . '.t', 'bad_encoding');
            } elseif (iconv_strlen($seg['t'], 'UTF-8') > (int) $limits['segment_chars']) {
                $add($sp . '.t', 'too_long');
            } elseif (preg_match('/[^\P{Cc}\n]/u', $seg['t']) === 1) {
                $add($sp . '.t', 'control_char');
            }

            if (!array_key_exists('m', $seg)) {
                $add($sp . '.m', 'missing_key');
                continue;
            }
            if (!self::isObjectish($seg['m'])) {
                $add($sp . '.m', 'not_object');
                continue;
            }
            self::marks($seg['m'], $sp . '.m', $stage, $state, $add, $limits, $s);
        }
    }

    /**
     * 세그먼트의 마크 묶음.
     *
     * @param array<string,mixed> $marks
     * @param array<string,mixed> $state
     */
    private static function marks(array $marks, string $p, int $stage, array &$state, callable $add, array $limits, array $s): void
    {
        foreach ($marks as $name => $value) {
            $key = (string) $name;
            $mp  = $p . '.' . $key;

            if (!array_key_exists($key, $s['marks'])) {
                $add($mp, 'unknown_mark');
                continue;
            }
            if (!in_array($key, $state['marks'], true)) {
                $add($mp, 'mark_not_in_stage');
                continue;
            }

            if ($key === 'color') {
                if (!is_string($value)) {
                    $add($mp, 'bad_type');
                } elseif (!in_array($value, $state['colors'], true)) {
                    $add($mp, 'not_in_palette');
                }
                continue;
            }

            if ($key === 'link') {
                if (!self::isObjectish($value)) {
                    $add($mp, 'not_object');
                    continue;
                }
                foreach (array_keys($value) as $k) {
                    if ($k !== 'href') {
                        $add($mp . '.' . (string) $k, 'unknown_key');
                    }
                }
                if (!array_key_exists('href', $value)) {
                    $add($mp . '.href', 'missing_key');
                    continue;
                }
                self::href($value['href'], $mp . '.href', $add, $limits, $s);
                continue;
            }

            // 나머지(bold·italic·underline·strike·code)는 참·거짓이다
            if (!is_bool($value)) {
                $add($mp, 'bad_type');
            }
        }
    }

    /**
     * 링크 주소 — 스킴은 허용 목록 넷(`https`·`http`·`mailto`·`page`)뿐이다.
     * `javascript:`·`data:`·스킴 없는 상대 주소는 전부 여기서 끝난다.
     *
     * @param mixed $href
     */
    public static function href($href, string $p, callable $add, array $limits, array $s): void
    {
        if (!is_string($href)) {
            $add($p, 'bad_type');
            return;
        }
        if (preg_match('//u', $href) !== 1) {
            $add($p, 'bad_encoding');
            return;
        }
        if (strlen($href) > (int) $limits['href_chars']) {
            $add($p, 'too_long');
            return;
        }
        if (preg_match('/[\x00-\x1f\x7f]/', $href) === 1) {
            $add($p, 'control_char');
            return;
        }
        if (!self::schemeOk($href, $s)) {
            $add($p, 'bad_scheme');
        }
    }

    /**
     * 주소가 허용 스킴으로 시작하고 그 스킴의 형태에 맞는가.
     * 렌더러 두 벌이 그리기 직전에 **다시** 부르는 함수이기도 하다 (ARCH 12.4 「스킴 재검사」).
     *
     * @param array<string,mixed>|null $s
     */
    public static function schemeOk(string $href, ?array $s = null): bool
    {
        $s       = $s ?? self::schema();
        $schemes = $s['link_schemes'];

        if (in_array('https', $schemes, true) && preg_match('#^https://[^\s/?\#]+[^\s]*$#', $href) === 1) {
            return true;
        }
        if (in_array('http', $schemes, true) && preg_match('#^http://[^\s/?\#]+[^\s]*$#', $href) === 1) {
            return true;
        }
        if (in_array('mailto', $schemes, true) && preg_match('/^mailto:[^\s@]{1,128}@[^\s@]{1,128}$/', $href) === 1) {
            return true;
        }
        if (in_array('page', $schemes, true) && preg_match('/^page:[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $href) === 1) {
            return true;
        }
        return false;
    }

    /** 바깥으로 나가는 링크인가 (렌더러가 `target=_blank rel=noopener noreferrer` 를 붙이는 기준) */
    public static function isExternal(string $href): bool
    {
        return strncmp($href, 'https://', 8) === 0
            || strncmp($href, 'http://', 7) === 0
            || strncmp($href, 'mailto:', 7) === 0;
    }

    /**
     * 파생값 추출 (ARCH 12.1 · 12.3). **검사를 통과한 문서**에만 쓴다.
     *
     * `child_pages` 는 4단계에 생긴 칸이다 — 본문 안의 `child_page` 블록이 가리키는 페이지
     * UUID 들이다. **`links` 에 넣지 않는다**: `links` 는 「글자에 걸린 페이지 링크」(파생 표
     * `page_links`)이고, `child_page` 는 트리에 얹힌 **자식 페이지**라 쓰임이 다르다. 한 칸에
     * 섞으면 「본문에서 언급했을 뿐인 페이지」와 「하위 페이지」를 나중에 가를 수 없다.
     *
     * @param  array<string,mixed> $doc
     * @return array{plain_text:string,blocks:list<array{block_id:string,block_type:string,ord:int,excerpt:string}>,links:list<array{from_block_id:string,to_page_id:string}>,child_pages:list<string>,todos:list<array{block_id:string,checked:bool,text:string}>}
     */
    public static function extract(array $doc): array
    {
        $out = ['plain_text' => '', 'blocks' => [], 'links' => [], 'child_pages' => [], 'todos' => []];
        $ord = 0;
        $parts = [];
        self::collect(is_array($doc['blocks'] ?? null) ? $doc['blocks'] : [], $ord, $parts, $out);

        $plain = implode("\n", $parts);
        if (iconv_strlen($plain, 'UTF-8') > self::PLAIN_TEXT_MAX) {
            $plain = (string) iconv_substr($plain, 0, self::PLAIN_TEXT_MAX, 'UTF-8');
        }
        $out['plain_text'] = $plain;
        return $out;
    }

    /**
     * @param list<string>        $parts
     * @param array<string,mixed> $out
     */
    private static function collect(array $list, int &$ord, array &$parts, array &$out): void
    {
        $excerptMax = (int) self::schema()['limits']['excerpt_chars'];

        foreach ($list as $block) {
            if (!is_array($block) || !isset($block['type'], $block['id'])) {
                continue;
            }
            $type = (string) $block['type'];
            $id   = (string) $block['id'];
            $text = isset($block['text']) && is_array($block['text']) ? self::plain($block['text']) : '';

            if ($type === 'code' && isset($block['code']) && is_string($block['code'])) {
                $text = $block['code'];
            }
            if ($text !== '') {
                $parts[] = $text;
            }

            $excerpt = $text;
            if (iconv_strlen($excerpt, 'UTF-8') > $excerptMax) {
                $excerpt = (string) iconv_substr($excerpt, 0, $excerptMax, 'UTF-8');
            }

            $out['blocks'][] = [
                'block_id'   => $id,
                'block_type' => $type,
                'ord'        => $ord,
                'excerpt'    => $excerpt,
            ];
            $ord++;

            /* 4단계 — 본문에 얹힌 하위 페이지. 같은 페이지를 두 번 가리켜도 한 번만 센다
               (화면이 제목을 찾는 데 쓰는 목록이라 중복이 의미가 없다). */
            if ($type === 'child_page' && isset($block['page_id']) && is_string($block['page_id'])
                && !in_array($block['page_id'], $out['child_pages'], true)
            ) {
                $out['child_pages'][] = $block['page_id'];
            }

            if ($type === 'to_do') {
                $out['todos'][] = [
                    'block_id' => $id,
                    'checked'  => ($block['checked'] ?? false) === true,
                    'text'     => $excerpt,
                ];
            }

            if (isset($block['text']) && is_array($block['text'])) {
                foreach ($block['text'] as $seg) {
                    if (!is_array($seg) || !isset($seg['m']) || !is_array($seg['m'])) {
                        continue;
                    }
                    $link = $seg['m']['link'] ?? null;
                    if (is_array($link) && isset($link['href']) && is_string($link['href'])
                        && strncmp($link['href'], 'page:', 5) === 0
                    ) {
                        $out['links'][] = [
                            'from_block_id' => $id,
                            'to_page_id'    => substr($link['href'], 5),
                        ];
                    }
                }
            }

            if (isset($block['children']) && is_array($block['children'])) {
                self::collect($block['children'], $ord, $parts, $out);
            }
        }
    }

    /** 리치 텍스트를 평문 한 줄로 (마크는 버린다) */
    public static function plain(array $text): string
    {
        $out = '';
        foreach ($text as $seg) {
            if (is_array($seg) && isset($seg['t']) && is_string($seg['t'])) {
                $out .= $seg['t'];
            }
        }
        return $out;
    }

    /**
     * 「시작하기」 페이지의 본문 (기획 ③ — 첫 로그인 때 개인 공간과 함께 만든다).
     * 값은 전부 코드에 적힌 고정 문자열이고 요청 값이 한 글자도 섞이지 않는다.
     *
     * @return array<string,mixed>
     */
    public static function welcomeDoc(): array
    {
        $b = static function (string $type, array $extra = []) : array {
            return array_merge(['id' => self::uuid4(), 'type' => $type], $extra);
        };
        $t = static function (string $s, array $marks = []) : array {
            return [['t' => $s, 'm' => $marks]];
        };

        return [
            'schema' => 1,
            'blocks' => [
                $b('heading', ['level' => 1, 'text' => $t('시작하기')]),
                $b('paragraph', ['text' => $t('여기는 나만 볼 수 있는 개인 업무 공간입니다. 적은 내용은 자동으로 저장됩니다.')]),
                $b('heading', ['level' => 2, 'text' => $t('쓰는 방법')]),
                $b('bulleted', ['text' => $t('빈 줄에서 / 를 치면 넣을 수 있는 것들이 뜹니다 — 제목, 목록, 할 일, 인용, 강조 상자, 구분선, 코드.')]),
                $b('bulleted', ['text' => $t('글자를 끌어서 고르면 굵게·기울임·밑줄·취소선·색을 고르는 줄이 뜹니다.')]),
                $b('bulleted', ['text' => $t('# 뒤에 빈칸을 치면 큰 제목이, - 뒤에 빈칸을 치면 목록이 됩니다.')]),
                $b('bulleted', ['text' => $t('되돌리기는 Ctrl+Z, 다시 하기는 Ctrl+Shift+Z 입니다.')]),
                $b('heading', ['level' => 2, 'text' => $t('오늘 할 일')]),
                $b('to_do', ['checked' => false, 'text' => $t('이 줄을 눌러 체크해 보기')]),
                $b('to_do', ['checked' => false, 'text' => $t('새 페이지를 하나 만들어 보기')]),
                $b('callout', ['icon' => '💡', 'text' => $t('이 공간의 페이지는 나와 관리자만 볼 수 있습니다. 함께 보는 공간과 게시판은 다음에 열립니다.')]),
            ],
        ];
    }

    /** 블록 id 용 UUIDv4 (서버가 만드는 자리는 여기 하나다) */
    public static function uuid4(): string
    {
        $b    = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $h    = bin2hex($b);
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4)
             . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
    }

    /** 0..n-1 첨자를 가진 배열인가 (JSON 의 배열과 객체를 가른다) */
    private static function isList($v): bool
    {
        return is_array($v) && ($v === [] || array_keys($v) === range(0, count($v) - 1));
    }

    /**
     * JSON 의 **객체**로 볼 수 있나.
     *
     * `json_decode(..., true)` 는 `{}` 와 `[]` 를 **둘 다** 빈 PHP 배열로 준다. 그래서 「빈
     * 배열」은 빈 객체이기도 하다 — 마크가 하나도 없는 세그먼트 `{"t":"글","m":{}}` 가 그 자리다.
     * 빈 배열을 배열로만 보면 정상 문서가 `not_object` 로 거부된다(2026-09-22 실측).
     */
    private static function isObjectish($v): bool
    {
        return is_array($v) && ($v === [] || !self::isList($v));
    }
}
