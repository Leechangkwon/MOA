<?php
declare(strict_types=1);

/**
 * src/Wiki.php — 위키 글(`wiki/*.json`)을 읽고, 검사하고, **이스케이프해서** 그린다
 *                (14단계 F-21 · ARCHITECTURE 5.10 · SECURITY R-56 · PRD S-03)
 *
 * 무엇인가
 *   네 문서(PRD·ARCHITECTURE·SECURITY·STAGES)를 초보자 말로 다시 쓴 해설 글을 앱 안에서
 *   보여 주기 위한 렌더러다. 원문 마크다운을 파싱하지 않는다 — 마크다운 파서는 HTML 을
 *   통과시키는 통로가 되기 쉽고(저장형 XSS), 외부 라이브러리도 쓰지 않기 때문이다
 *   (의존성 0 원칙, PRD S-10-C).
 *
 * 이 파일이 지키는 세 가지
 *   ① **본문에 HTML 이 없다.** 글은 구조화된 JSON(문단·목록·표·코드·말풍선…)이고,
 *      이 파일이 태그를 만든다. 글에서 온 문자열은 예외 없이
 *      `htmlspecialchars(ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')` 를 거친다 (PRD S-03).
 *      인라인 표기는 백틱 한 가지뿐이고, 그 변환도 **이스케이프한 뒤**에 한다 —
 *      그래서 글에 `<script>` 가 들어 있어도 태그가 되지 않고 글자로 보인다.
 *   ② **모르는 것은 조용히 건너뛰지 않는다.** 모르는 블록 type·빠진 필드·범위 밖 값은
 *      예외이고, 부트스트랩의 예외 처리기가 500 으로 끝낸다 (ARCH 5.10). 조용히 건너뛰면
 *      글이 반쪽만 나오고도 아무도 모른다. 커밋 전에 `scripts/wiki_check.php` 로 전 글을
 *      검사하는 이유가 이것이다.
 *   ③ **파일 경로에 사용자 입력이 그대로 들어가지 않는다.** slug 는 라우터 자리표시자
 *      정규식을 통과한 뒤 여기서 한 번 더 `SLUG_RE` 로 검사하고, 만들어진 경로가
 *      `realpath` 로 `wiki/` 안인지 다시 본다 (Storage::pathFor 와 같은 방식, ARCH 5.10).
 *
 * 이 파일은 DB·세션·부트스트랩에 기대지 않는다 — `scripts/wiki_check.php`(CLI)가
 * 이 클래스 하나만 읽어서 전 글을 검사할 수 있게 하기 위해서다. 권한 판단도 하지 않는다:
 * "누가 볼 수 있나" 는 라우트 정책 `role:Admin` 과 `Authz::canReadWiki` 가 정한다 (PRD S-01).
 */
final class Wiki
{
    /** 글 이름(파일 이름의 몸통). 라우터 `{slug}` 자리표시자와 **같은 정규식**이다 (ARCH 5.10) */
    public const SLUG_RE = '/^[a-z0-9][a-z0-9-]{0,39}$/';

    /** 절 앵커(`sections[].id`). HTML id 로 나가므로 글자 범위를 좁게 묶는다 */
    public const ID_RE = '/^[a-z0-9-]{1,40}$/';

    /** `updated` 는 날짜 한 형식만 (YYYY-MM-DD) */
    public const DATE_RE = '/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/';

    /** 글 묶음 — 안내 글(guide) 과 단계 글(stage) 둘뿐이다 */
    public const GROUPS = ['guide', 'stage'];

    /** 단계 글의 번호 범위 (PRD 13장의 1~13단계) */
    public const STAGE_MIN = 1;
    public const STAGE_MAX = 27;   // PRD 13장 로드맵 끝(27단계)

    /** 본문 블록의 전체 목록. 여기 없는 type 은 예외다 (ARCH 5.10) */
    public const BLOCK_TYPES = ['p', 'list', 'steps', 'table', 'code', 'callout', 'terms', 'refs', 'link', 'checklist'];

    /**
     * `checklist` 블록의 칸 값 — **네 가지뿐**이다 (2026-09-13, 보안 시험 기록용).
     *
     * 키는 글(JSON)이 적고, 화면에 나가는 기호·문구·색은 **코드가 정한다**. 글이 문구를 지어내면
     * 「문제 없음」 같은 판정 표시가 글쓴이 마음대로가 되기 때문이다(감사 요약 문장을 Audit 상수
     * 표 하나에만 두는 것과 같은 이유, ARCH 7.4).
     *
     *   ok    실행해서 문제 없음을 확인 (증거는 같은 줄의 `how`·`code` 에)
     *   open  문제가 남아 있음 — 위험이 해소되지 않음
     *   todo  아직 실행하지 않음 (미검증. **안전하다는 뜻이 아니다**)
     *   na    그 차수의 범위 밖 (그 차수에 따로 실행한 기록이 없음)
     *
     * @var array<string,array{0:string,1:string}>  값 => [기호, 툴팁 문구]
     */
    public const CHECK_MARKS = [
        'ok'   => ['✓', '문제 없음 — 실행해서 확인'],
        'open' => ['!', '문제 남음 — 해소되지 않음'],
        'todo' => ['?', '미검증 — 아직 실행하지 않음'],
        'na'   => ['–', '해당 없음 — 그 차수에 실행 기록 없음'],
    ];

    /** 한 체크리스트가 담을 수 있는 차수(열)·항목(행)의 상한 */
    public const CHECK_MAX_ROUNDS = 8;
    public const CHECK_MAX_ITEMS  = 60;

    /** 말풍선 종류 6가지. 접두 글자(왜·어떻게·확인·위험·해보기·용어)는 app.css 가 붙인다 */
    public const CALLOUT_KINDS = ['why', 'how', 'check', 'risk', 'try', 'term'];

    /** 「원문 위치」가 가리킬 수 있는 문서 6가지 (ARCH 5.10) */
    public const REF_DOCS = ['PRD', 'ARCH', 'SECURITY', 'STAGES', 'README', 'CLAUDE'];

    /**
     * 차례(`wiki/index.json`)에서 글 목록을 담을 수 있는 열쇠 이름들.
     *
     * 차례 파일의 바깥 모양은 ARCH 5.10 이 글 파일만큼 못박아 두지 않았다(글 구조가 계약이다).
     * 그래서 **흔한 모양을 모두 받아들인다**: 최상위가 배열이거나, 아래 열쇠 중 하나(또는 여럿)에
     * 항목 배열이 들어 있으면 된다. 항목은 `{slug,…}` 객체이거나 slug 문자열 하나여도 된다.
     * 받아들이는 모양을 넓히는 것은 **표시**에만 관한 일이고, 각 항목의 slug 는 언제나
     * SLUG_RE 를 통과해야 한다 — 검사를 느슨하게 하는 것이 아니다.
     */
    public const INDEX_LIST_KEYS = [
        'pages', 'entries', 'items', 'articles', 'list', 'docs',
        // 묶음별로 나눠 적은 모양(`{"guide":[…],"stage":[…]}`)도 받는다. 이때는 **열쇠 이름이
        // 곧 묶음**이라 항목에 group 이 없어도 안내/단계를 가릴 수 있다.
        'guide', 'guides', 'stage', 'stages',
    ];

    /** 위 열쇠 중 묶음을 뜻하는 것들 → group 값 */
    private const INDEX_KEY_GROUP = [
        'guide'  => 'guide',
        'guides' => 'guide',
        'stage'  => 'stage',
        'stages' => 'stage',
    ];

    /** 이 화면들의 주소. 링크를 만들 때 slug 는 SLUG_RE 를 통과한 값만 붙인다 */
    private const BASE_PATH = '/wiki';

    /* ─── 경로 ───────────────────────────────────────────────── */

    /**
     * 글이 있는 디렉터리의 **실제 경로**. 저장소 루트의 `wiki/` 이며 `public/` 밖이라
     * URL 로 직접 닿지 않는다 (ARCH 3장·5.10).
     *
     * @throws RuntimeException 디렉터리가 없을 때
     */
    public static function dir(): string
    {
        $dir = realpath(dirname(__DIR__) . '/wiki');
        if ($dir === false || !is_dir($dir)) {
            throw new RuntimeException('위키 디렉터리를 찾을 수 없습니다');
        }
        return $dir;
    }

    /**
     * slug 하나로 글 파일의 실제 경로를 만든다. **사용자 입력이 경로에 들어가는 유일한 자리**다.
     *
     *   [1] SLUG_RE 정확 일치 — `.`·`/`·대문자·%인코딩이 한 글자도 통과하지 못한다.
     *       (라우터 `{slug}` 자리표시자가 이미 같은 검사를 하지만, 이 클래스를 CLI 에서도
     *        쓰므로 여기서 다시 본다. 검사는 값을 쓰는 쪽에 있어야 한다.)
     *   [2] 만들어진 경로를 realpath 로 풀어 `wiki/` 안인지 확인 — 심볼릭 링크로 밖을
     *       가리키는 파일이 있어도 여기서 걸린다 (Storage::pathFor 와 같은 방식).
     *
     * 파일이 없으면 null 을 돌려준다(호출한 쪽이 404 로 끝낸다).
     *
     * @throws InvalidArgumentException slug 형태가 아닐 때 (코드의 버그이지 사용자의 요청이 아니다)
     * @throws RuntimeException         경로가 wiki/ 밖을 가리킬 때
     */
    public static function pathFor(string $slug): ?string
    {
        if (preg_match(self::SLUG_RE, $slug) !== 1) {
            throw new InvalidArgumentException('허용되지 않는 글 이름입니다');
        }
        $base = self::dir();
        $path = $base . '/' . $slug . '.json';

        if (!is_file($path)) {
            return null;
        }
        $real = realpath($path);
        if ($real === false || strpos($real, $base . '/') !== 0) {
            throw new RuntimeException('위키 디렉터리 밖의 경로입니다');
        }
        return $real;
    }

    /** 그 slug 의 글 파일이 있나 (차례에서 "준비 중" 을 가려내는 데 쓴다) */
    public static function pageExists(string $slug): bool
    {
        if (preg_match(self::SLUG_RE, $slug) !== 1) {
            return false;
        }
        return self::pathFor($slug) !== null;
    }

    /* ─── 읽기 ───────────────────────────────────────────────── */

    /**
     * 차례(`wiki/index.json`)를 읽는다. **파일이 없으면 null** — 아직 글을 쓰지 않은 상태는
     * 오류가 아니다(화면이 "아직 글이 없습니다" 를 보여 준다). 파일이 있는데 모양이 틀렸으면 예외다.
     *
     * @throws RuntimeException
     */
    public static function loadIndex(): ?array
    {
        $path = self::dir() . '/index.json';
        if (!is_file($path)) {
            return null;
        }
        $index = self::readJsonObject($path);

        $problems = self::validateIndex($index);
        if ($problems !== []) {
            throw new RuntimeException('index.json 구조 오류: ' . implode(' | ', $problems));
        }
        return $index;
    }

    /**
     * 글 한 편을 읽는다. **파일이 없으면 null**(호출한 쪽이 404 동일 문구로 끝낸다),
     * 구조가 틀렸으면 예외다 — 조용히 건너뛰지 않는다 (ARCH 5.10).
     *
     * 차례에 없는 slug 여도 파일이 있으면 읽는다. 차례는 링크·목록·이전/다음에만 쓴다.
     *
     * @throws RuntimeException
     */
    public static function loadPage(string $slug): ?array
    {
        $path = self::pathFor($slug);
        if ($path === null) {
            return null;
        }
        $page = self::readJsonObject($path);

        $problems = self::validate($page);
        if (($page['slug'] ?? null) !== $slug) {
            $problems[] = 'slug: 파일 이름(' . $slug . ')과 글 안의 slug 가 다르다';
        }
        if ($problems !== []) {
            throw new RuntimeException($slug . '.json 구조 오류: ' . implode(' | ', $problems));
        }
        return $page;
    }

    /**
     * `wiki/*.json` 파일 이름(slug)을 전부 모은다 — CLI 검사용. index.json 은 뺀다.
     *
     * @return list<string>
     */
    public static function allSlugs(): array
    {
        $found = glob(self::dir() . '/*.json');
        $slugs = [];
        foreach ($found === false ? [] : $found as $path) {
            $name = basename($path, '.json');
            if ($name !== 'index' && preg_match(self::SLUG_RE, $name) === 1) {
                $slugs[] = $name;
            }
        }
        sort($slugs);
        return $slugs;
    }

    /**
     * JSON 파일 하나를 객체(연관 배열)로 읽는다. 깊이를 묶어 두어 이상한 파일이
     * 스택을 파먹지 못하게 한다. 실패는 예외다.
     *
     * @throws RuntimeException
     */
    private static function readJsonObject(string $path): array
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException(basename($path) . ' 을 읽을 수 없습니다');
        }
        try {
            $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(basename($path) . ' 의 JSON 형식이 올바르지 않습니다: ' . $e->getMessage());
        }
        if (!is_array($data)) {
            throw new RuntimeException(basename($path) . ' 의 최상위가 객체가 아닙니다');
        }
        return $data;
    }

    /* ─── 구조 검사 ──────────────────────────────────────────── */

    /**
     * 글 한 편의 구조를 검사한다. **문제 목록**을 돌려주며, 빈 배열이면 정상이다.
     * 위치는 `sections[0].blocks[2]` 처럼 사람이 찾아갈 수 있게 적는다.
     *
     * 모르는 열쇠(여기서 다루지 않는 여분의 필드)는 무시한다 — 글에 메모를 덧붙이는 것까지
     * 막지는 않는다. 막는 것은 **모르는 블록 type·빠진 필드·범위 밖 값** 셋이다 (ARCH 5.10).
     *
     * @return list<string>
     */
    public static function validate(array $page): array
    {
        $p = [];

        if (!self::isFilledString($page['slug'] ?? null) || preg_match(self::SLUG_RE, (string) $page['slug']) !== 1) {
            $p[] = 'slug: 없거나 형태가 아니다 (^[a-z0-9][a-z0-9-]{0,39}$)';
        }
        if (!self::isFilledString($page['title'] ?? null)) {
            $p[] = 'title: 비어 있지 않은 문자열이어야 한다';
        }
        if (array_key_exists('summary', $page) && !is_string($page['summary'])) {
            $p[] = 'summary: 문자열이어야 한다';
        }
        if (array_key_exists('updated', $page)
            && (!is_string($page['updated']) || preg_match(self::DATE_RE, $page['updated']) !== 1)) {
            $p[] = 'updated: YYYY-MM-DD 형식이어야 한다';
        }

        $group = $page['group'] ?? null;
        if (!is_string($group) || !in_array($group, self::GROUPS, true)) {
            $p[] = 'group: guide 또는 stage 여야 한다';
        } elseif ($group === 'stage') {
            $stage = $page['stage'] ?? null;
            if (!is_int($stage) || $stage < self::STAGE_MIN || $stage > self::STAGE_MAX) {
                $p[] = 'stage: ' . self::STAGE_MIN . '~' . self::STAGE_MAX . ' 범위의 정수여야 한다';
            }
        } elseif (array_key_exists('stage', $page) && $page['stage'] !== null) {
            $p[] = 'stage: group 이 guide 인 글에는 단계 번호를 두지 않는다';
        }

        $sections = $page['sections'] ?? null;
        if (!is_array($sections) || !array_is_list($sections) || $sections === []) {
            $p[] = 'sections: 비어 있지 않은 배열이어야 한다';
            return $p;
        }

        $seenIds = [];
        foreach ($sections as $i => $section) {
            $at = 'sections[' . $i . ']';
            if (!is_array($section) || array_is_list($section)) {
                $p[] = $at . ': 객체가 아니다';
                continue;
            }
            $id = $section['id'] ?? null;
            if (!self::isFilledString($id) || preg_match(self::ID_RE, (string) $id) !== 1) {
                $p[] = $at . '.id: 없거나 형태가 아니다 (^[a-z0-9-]{1,40}$)';
            } elseif (isset($seenIds[$id])) {
                $p[] = $at . '.id: 같은 id 가 이미 있다 (' . $id . ')';
            } else {
                $seenIds[(string) $id] = true;
            }
            if (!self::isFilledString($section['heading'] ?? null)) {
                $p[] = $at . '.heading: 비어 있지 않은 문자열이어야 한다';
            }

            $blocks = $section['blocks'] ?? null;
            if (!is_array($blocks) || !array_is_list($blocks) || $blocks === []) {
                $p[] = $at . '.blocks: 비어 있지 않은 배열이어야 한다';
                continue;
            }
            foreach ($blocks as $j => $block) {
                foreach (self::validateBlock($block, $at . '.blocks[' . $j . ']') as $one) {
                    $p[] = $one;
                }
            }
        }

        return $p;
    }

    /**
     * 블록 하나를 검사한다. **모르는 type 은 여기서 걸린다** — 새 표현을 쓰려면
     * ARCH 5.10 을 먼저 고치고 이 목록에 더한다.
     *
     * @param mixed $block
     * @return list<string>
     */
    private static function validateBlock($block, string $at): array
    {
        $p = [];

        if (!is_array($block) || array_is_list($block)) {
            return [$at . ': 객체가 아니다'];
        }
        $type = $block['type'] ?? null;
        if (!is_string($type) || !in_array($type, self::BLOCK_TYPES, true)) {
            return [$at . '.type: 모르는 블록 종류다 (' . self::describe($type) . ')'];
        }

        switch ($type) {
            case 'p':
            case 'code':
                if (!self::isFilledString($block['text'] ?? null)) {
                    $p[] = $at . '.text: 비어 있지 않은 문자열이어야 한다';
                }
                break;

            case 'list':
            case 'steps':
                $items = $block['items'] ?? null;
                if (!is_array($items) || !array_is_list($items) || $items === []) {
                    $p[] = $at . '.items: 비어 있지 않은 배열이어야 한다';
                    break;
                }
                foreach ($items as $k => $item) {
                    if (!self::isFilledString($item)) {
                        $p[] = $at . '.items[' . $k . ']: 비어 있지 않은 문자열이어야 한다';
                    }
                }
                break;

            case 'table':
                $header = $block['header'] ?? null;
                if (!is_array($header) || !array_is_list($header) || $header === []) {
                    $p[] = $at . '.header: 비어 있지 않은 배열이어야 한다';
                } else {
                    foreach ($header as $k => $cell) {
                        if (!is_string($cell)) {
                            $p[] = $at . '.header[' . $k . ']: 문자열이어야 한다';
                        }
                    }
                }
                $rows = $block['rows'] ?? null;
                if (!is_array($rows) || !array_is_list($rows)) {
                    $p[] = $at . '.rows: 배열이어야 한다';
                    break;
                }
                foreach ($rows as $r => $row) {
                    if (!is_array($row) || !array_is_list($row)) {
                        $p[] = $at . '.rows[' . $r . ']: 배열이어야 한다';
                        continue;
                    }
                    foreach ($row as $c => $cell) {
                        if (!is_string($cell)) {
                            $p[] = $at . '.rows[' . $r . '][' . $c . ']: 문자열이어야 한다';
                        }
                    }
                }
                break;

            case 'callout':
                $kind = $block['kind'] ?? null;
                if (!is_string($kind) || !in_array($kind, self::CALLOUT_KINDS, true)) {
                    $p[] = $at . '.kind: ' . implode('·', self::CALLOUT_KINDS) . ' 중 하나여야 한다';
                }
                if (array_key_exists('title', $block) && !is_string($block['title'])) {
                    $p[] = $at . '.title: 문자열이어야 한다';
                }
                if (!self::isFilledString($block['text'] ?? null)) {
                    $p[] = $at . '.text: 비어 있지 않은 문자열이어야 한다';
                }
                break;

            case 'terms':
                $items = $block['items'] ?? null;
                if (!is_array($items) || !array_is_list($items) || $items === []) {
                    $p[] = $at . '.items: 비어 있지 않은 배열이어야 한다';
                    break;
                }
                foreach ($items as $k => $item) {
                    $one = $at . '.items[' . $k . ']';
                    if (!is_array($item) || array_is_list($item)) {
                        $p[] = $one . ': 객체가 아니다';
                        continue;
                    }
                    if (!self::isFilledString($item['term'] ?? null)) {
                        $p[] = $one . '.term: 비어 있지 않은 문자열이어야 한다';
                    }
                    if (!self::isFilledString($item['easy'] ?? null)) {
                        $p[] = $one . '.easy: 비어 있지 않은 문자열이어야 한다';
                    }
                }
                break;

            case 'refs':
                $items = $block['items'] ?? null;
                if (!is_array($items) || !array_is_list($items) || $items === []) {
                    $p[] = $at . '.items: 비어 있지 않은 배열이어야 한다';
                    break;
                }
                foreach ($items as $k => $item) {
                    $one = $at . '.items[' . $k . ']';
                    if (!is_array($item) || array_is_list($item)) {
                        $p[] = $one . ': 객체가 아니다';
                        continue;
                    }
                    $doc = $item['doc'] ?? null;
                    if (!is_string($doc) || !in_array($doc, self::REF_DOCS, true)) {
                        $p[] = $one . '.doc: ' . implode('·', self::REF_DOCS) . ' 중 하나여야 한다';
                    }
                    if (!self::isFilledString($item['where'] ?? null)) {
                        $p[] = $one . '.where: 비어 있지 않은 문자열이어야 한다';
                    }
                    if (array_key_exists('what', $item) && !is_string($item['what'])) {
                        $p[] = $one . '.what: 문자열이어야 한다';
                    }
                }
                break;

            case 'checklist':
                /* 보안 시험 기록용 표 (2026-09-13). 행 = 검토 항목, 열 = 차수, 칸 = CHECK_MARKS.
                 * 모르는 값·개수 불일치는 **조용히 넘기지 않는다** — 판정 표가 반쪽이면
                 * 「문제 없음」 표시가 근거 없이 서 있게 되기 때문이다 (ARCH 5.10 원칙 ②). */
                $rounds = $block['rounds'] ?? null;
                if (!is_array($rounds) || !array_is_list($rounds) || $rounds === []
                    || count($rounds) > self::CHECK_MAX_ROUNDS
                ) {
                    $p[] = $at . '.rounds: 1~' . self::CHECK_MAX_ROUNDS . '개의 배열이어야 한다';
                    break;
                }
                foreach ($rounds as $k => $round) {
                    if (!self::isFilledString($round)) {
                        $p[] = $at . '.rounds[' . $k . ']: 비어 있지 않은 문자열이어야 한다';
                    }
                }
                $items = $block['items'] ?? null;
                if (!is_array($items) || !array_is_list($items) || $items === []
                    || count($items) > self::CHECK_MAX_ITEMS
                ) {
                    $p[] = $at . '.items: 1~' . self::CHECK_MAX_ITEMS . '개의 배열이어야 한다';
                    break;
                }
                foreach ($items as $i => $item) {
                    $iat = $at . '.items[' . $i . ']';
                    if (!is_array($item)) {
                        $p[] = $iat . ': 객체여야 한다';
                        continue;
                    }
                    if (!self::isFilledString($item['label'] ?? null)) {
                        $p[] = $iat . '.label: 비어 있지 않은 문자열이어야 한다';
                    }
                    $marks = $item['marks'] ?? null;
                    if (!is_array($marks) || !array_is_list($marks) || count($marks) !== count($rounds)) {
                        $p[] = $iat . '.marks: 차수(' . count($rounds) . ')와 같은 개수의 배열이어야 한다';
                    } else {
                        foreach ($marks as $m => $mark) {
                            if (!is_string($mark) || !array_key_exists($mark, self::CHECK_MARKS)) {
                                $p[] = $iat . '.marks[' . $m . ']: '
                                    . implode('·', array_keys(self::CHECK_MARKS)) . ' 중 하나여야 한다';
                            }
                        }
                    }
                    $how = $item['how'] ?? null;
                    if (!is_array($how) || !array_is_list($how) || $how === []) {
                        $p[] = $iat . '.how: 비어 있지 않은 배열이어야 한다 (어떻게 검토했나)';
                    } else {
                        foreach ($how as $h => $line) {
                            if (!self::isFilledString($line)) {
                                $p[] = $iat . '.how[' . $h . ']: 비어 있지 않은 문자열이어야 한다';
                            }
                        }
                    }
                    if (array_key_exists('code', $item) && !self::isFilledString($item['code'])) {
                        $p[] = $iat . '.code: (선택) 비어 있지 않은 문자열이어야 한다';
                    }
                }
                break;

            case 'link':
                $slug = $block['slug'] ?? null;
                if (!self::isFilledString($slug) || preg_match(self::SLUG_RE, (string) $slug) !== 1) {
                    $p[] = $at . '.slug: 없거나 형태가 아니다 (^[a-z0-9][a-z0-9-]{0,39}$)';
                }
                if (array_key_exists('label', $block) && !is_string($block['label'])) {
                    $p[] = $at . '.label: 문자열이어야 한다';
                }
                break;
        }

        return $p;
    }

    /**
     * 차례의 구조를 검사한다. 글 목록을 찾지 못하면(= 아는 열쇠가 하나도 없으면) 문제로 본다 —
     * 조용히 빈 차례를 그리면 "글이 안 보인다" 의 원인을 아무도 못 찾는다.
     *
     * @return list<string>
     */
    public static function validateIndex(array $index): array
    {
        $p = [];

        if (isset($index['title']) && !is_string($index['title'])) {
            $p[] = 'title: 문자열이어야 한다';
        }
        if (isset($index['summary']) && !is_string($index['summary'])) {
            $p[] = 'summary: 문자열이어야 한다';
        }

        $rows = self::rawIndexRows($index);
        if ($rows === null) {
            $p[] = '글 목록을 찾을 수 없다 (최상위 배열이거나 ' . implode('·', self::INDEX_LIST_KEYS) . ' 중 한 열쇠에 배열)';
            return $p;
        }

        $seen = [];
        foreach ($rows as $i => $pair) {
            $at   = 'entries[' . $i . ']';
            $row  = $pair[0];
            $slug = is_string($row) ? $row : (is_array($row) ? ($row['slug'] ?? null) : null);

            if (!self::isFilledString($slug) || preg_match(self::SLUG_RE, (string) $slug) !== 1) {
                $p[] = $at . '.slug: 없거나 형태가 아니다 (' . self::describe($slug) . ')';
                continue;
            }
            if (isset($seen[(string) $slug])) {
                $p[] = $at . '.slug: 차례에 두 번 나온다 (' . $slug . ')';
            }
            $seen[(string) $slug] = true;

            if (!is_array($row)) {
                continue;
            }
            foreach (['title', 'summary', 'updated'] as $key) {
                if (array_key_exists($key, $row) && !is_string($row[$key])) {
                    $p[] = $at . '.' . $key . ': 문자열이어야 한다';
                }
            }
            if (array_key_exists('updated', $row) && is_string($row['updated'])
                && $row['updated'] !== '' && preg_match(self::DATE_RE, $row['updated']) !== 1) {
                $p[] = $at . '.updated: YYYY-MM-DD 형식이어야 한다';
            }
            if (array_key_exists('group', $row)
                && (!is_string($row['group']) || !in_array($row['group'], self::GROUPS, true))) {
                $p[] = $at . '.group: guide 또는 stage 여야 한다';
            }
            if (array_key_exists('stage', $row) && $row['stage'] !== null
                && (!is_int($row['stage']) || $row['stage'] < self::STAGE_MIN || $row['stage'] > self::STAGE_MAX)) {
                $p[] = $at . '.stage: ' . self::STAGE_MIN . '~' . self::STAGE_MAX . ' 범위의 정수여야 한다';
            }
        }

        return $p;
    }

    /* ─── 차례 읽기 도우미 ───────────────────────────────────── */

    /**
     * 차례 파일에서 **날것 그대로의** 항목을 꺼낸다. 모양을 모르면 null.
     * 항목마다 `[값, 묶음 힌트]` 짝으로 돌려준다 — 묶음별 열쇠(`guide`·`stage`)로 적은
     * 차례에서는 열쇠 이름이 곧 묶음이기 때문이다(항목에 group 이 없어도 된다).
     *
     * @return list<array{0: mixed, 1: ?string}>|null
     */
    private static function rawIndexRows(array $index): ?array
    {
        if (array_is_list($index)) {
            $rows = [];
            foreach ($index as $row) {
                $rows[] = [$row, null];
            }
            return $rows;
        }
        $rows  = [];
        $found = false;
        foreach (self::INDEX_LIST_KEYS as $key) {
            if (isset($index[$key]) && is_array($index[$key]) && array_is_list($index[$key])) {
                $found = true;
                $hint  = self::INDEX_KEY_GROUP[$key] ?? null;
                foreach ($index[$key] as $row) {
                    $rows[] = [$row, $hint];
                }
            }
        }
        return $found ? $rows : null;
    }

    /**
     * 차례 항목을 화면이 쓰기 좋은 한 가지 모양으로 고른다.
     * 반환: slug·title·summary·group·stage·updated·exists 를 가진 배열의 목록.
     *
     * `group` 이 적혀 있지 않으면 `stage` 번호가 있는지, 없으면 slug 가 `stage-` 로
     * 시작하는지로 짐작한다 — 짐작은 **표시 순서**에만 영향을 주고 권한·경로와는 무관하다.
     *
     * @return list<array<string,mixed>>
     */
    public static function entries(array $index): array
    {
        $rows = self::rawIndexRows($index);
        $out  = [];

        foreach ($rows ?? [] as $pair) {
            [$row, $hint] = $pair;
            $slug = is_string($row) ? $row : (string) ($row['slug'] ?? '');
            if (preg_match(self::SLUG_RE, $slug) !== 1) {
                continue;   // validateIndex() 가 이미 문제로 올린 항목이다
            }
            $r = is_array($row) ? $row : [];

            $stage = isset($r['stage']) && is_int($r['stage']) ? $r['stage'] : null;
            $group = isset($r['group']) && is_string($r['group']) && in_array($r['group'], self::GROUPS, true)
                ? $r['group']
                : ($hint
                    ?? ($stage !== null || strncmp($slug, 'stage-', 6) === 0 ? 'stage' : 'guide'));

            $out[] = [
                'slug'    => $slug,
                'title'   => isset($r['title']) && is_string($r['title']) && $r['title'] !== '' ? $r['title'] : $slug,
                'summary' => isset($r['summary']) && is_string($r['summary']) ? $r['summary'] : '',
                'group'   => $group,
                'stage'   => $stage,
                'updated' => isset($r['updated']) && is_string($r['updated']) ? $r['updated'] : '',
                'exists'  => self::pageExists($slug),
            ];
        }

        return $out;
    }

    /**
     * 차례에 있는 slug 인가. `link` 블록이 링크가 될지 글자가 될지를 가르는 값이다 (ARCH 5.10).
     */
    public static function hasEntry(array $index, string $slug): bool
    {
        foreach (self::entries($index) as $entry) {
            if ($entry['slug'] === $slug) {
                return true;
            }
        }
        return false;
    }

    /* ─── 이스케이프 ─────────────────────────────────────────── */

    /**
     * 글에서 온 문자열을 HTML 에 넣을 수 있는 모양으로 바꾼다. **예외 없이 이것을 거친다.**
     * ENT_SUBSTITUTE 는 잘못된 UTF-8 바이트를 빈 문자열이 아니라 대체 문자로 바꾼다 —
     * 빈 문자열이 되면 `<scr` + `ipt>` 처럼 조각을 이어 붙이는 우회가 생길 수 있다.
     */
    public static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * 인라인 표기 — **백틱 한 가지뿐**이다. `코드` → <code>코드</code>.
     *
     * 순서가 핵심이다: ① 먼저 전부 이스케이프하고 ② 그 결과에서 백틱을 나눈다.
     * 백틱은 htmlspecialchars 가 건드리지 않는 글자라 ①을 거쳐도 그대로 남는다.
     * 반대로 하면(나눈 뒤 이스케이프) 글이 태그를 만들 여지가 생긴다.
     *
     * 백틱 개수가 홀수면 짝이 맞지 않는다 — 그때는 **글자 그대로** 둔다.
     * 앞쪽만 <code> 로 바꾸면 글쓴이가 의도하지 않은 자리에서 서식이 시작된다.
     */
    public static function inline(string $s): string
    {
        $escaped = self::esc($s);
        $parts   = explode('`', $escaped);

        // 조각 수 = 백틱 수 + 1. 조각이 짝수면 백틱이 홀수 → 짝이 맞지 않는다.
        if (count($parts) % 2 === 0) {
            return $escaped;
        }
        $out = '';
        foreach ($parts as $i => $part) {
            $out .= $i % 2 === 1 ? '<code>' . $part . '</code>' : $part;
        }
        return $out;
    }

    /* ─── 그리기 ─────────────────────────────────────────────── */

    /**
     * 차례 화면(`/wiki`)의 본문을 만든다. 안내 글 목록 + 단계 카드 1~13.
     *
     * 글 파일이 아직 없는 항목은 링크 대신 「준비 중」으로 그린다 — 눌러서 404 를 만나는 것보다
     * 낫고, 글을 쓰는 사람과 화면을 만드는 사람이 동시에 일할 수 있다.
     */
    public static function renderIndex(array $index): string
    {
        $entries = self::entries($index);
        $guides  = [];
        $stages  = [];
        foreach ($entries as $entry) {
            if ($entry['group'] === 'stage') {
                $stages[] = $entry;
            } else {
                $guides[] = $entry;
            }
        }
        usort($stages, static function (array $a, array $b): int {
            return ($a['stage'] ?? PHP_INT_MAX) <=> ($b['stage'] ?? PHP_INT_MAX);
        });

        $h = [];

        $h[] = '<section class="panel wiki-group">';
        $h[] = '<div class="panel__head">';
        $h[] = '<h2 class="panel__title">안내</h2>';
        $h[] = '<p class="panel__sub">이 서비스가 무엇이고 어떤 말을 쓰는지부터 봅니다.</p>';
        $h[] = '</div>';
        if ($guides === []) {
            $h[] = '<p class="muted">안내 글이 아직 없습니다.</p>';
        } else {
            $h[] = '<ul class="wiki-guides">';
            foreach ($guides as $entry) {
                $h[] = '<li class="wiki-guide">' . self::entryLink($entry) . self::entryMeta($entry) . '</li>';
            }
            $h[] = '</ul>';
        }
        $h[] = '</section>';

        $h[] = '<section class="panel wiki-group">';
        $h[] = '<div class="panel__head">';
        $h[] = '<h2 class="panel__title">단계</h2>';
        $h[] = '<p class="panel__sub">1단계부터 차례로 무엇을 만들고 무엇을 막았는지 적었습니다.</p>';
        $h[] = '</div>';
        if ($stages === []) {
            $h[] = '<p class="muted">단계 글이 아직 없습니다.</p>';
        } else {
            $h[] = '<div class="card-grid">';
            foreach ($stages as $entry) {
                $h[] = self::stageCard($entry);
            }
            $h[] = '</div>';
        }
        $h[] = '</section>';

        return implode("\n", $h);
    }

    /**
     * 글 화면(`/wiki/{slug}`)의 본문을 만든다.
     * 제목·요약 → 절 차례(앵커) → 절마다 블록 → 이전/다음 글.
     *
     * $index 는 링크 판단(`link.slug` 가 차례에 있나)·이전/다음에만 쓴다.
     * 차례가 없어도(빈 배열) 글은 그려진다.
     */
    public static function renderPage(array $page, array $index): string
    {
        $h = [];

        $group = (string) ($page['group'] ?? 'guide');
        $stage = isset($page['stage']) && is_int($page['stage']) ? $page['stage'] : null;

        $h[] = '<article class="wiki-article">';

        // ─ 머리말
        $h[] = '<header class="wiki-head">';
        $h[] = '<p class="wiki-crumb"><a href="' . Http::url(self::BASE_PATH) . '">← 위키 차례</a></p>';
        $h[] = '<h1 class="wiki-title">' . self::esc((string) ($page['title'] ?? '')) . '</h1>';
        if (isset($page['summary']) && is_string($page['summary']) && $page['summary'] !== '') {
            $h[] = '<p class="wiki-summary">' . self::inline($page['summary']) . '</p>';
        }
        $h[] = '<p class="wiki-meta">';
        $h[] = $stage !== null
            ? '<span class="badge badge--internal">' . self::esc((string) $stage) . '단계</span>'
            : '<span class="badge badge--off">' . ($group === 'stage' ? '단계' : '안내') . '</span>';
        if (isset($page['updated']) && is_string($page['updated']) && $page['updated'] !== '') {
            $h[] = '<span class="muted">고친 날 ' . self::esc($page['updated']) . '</span>';
        }
        $h[] = '</p>';
        $h[] = '</header>';

        $sections = is_array($page['sections'] ?? null) ? $page['sections'] : [];

        // ─ 절 차례 (앵커). id 는 ID_RE 를 통과한 값이고, 앞에 wiki- 를 붙여
        //   공통 셸·app.js 가 쓰는 id(msg·sidebar…)와 겹치지 않게 한다.
        if (count($sections) > 1) {
            $h[] = '<nav class="wiki-toc" aria-label="이 글의 차례">';
            $h[] = '<p class="wiki-toc__title">이 글의 차례</p>';
            $h[] = '<ol class="wiki-toc__list">';
            foreach ($sections as $section) {
                $anchor = self::anchor((string) ($section['id'] ?? ''));
                $h[]    = '<li><a href="#' . $anchor . '">'
                    . self::esc((string) ($section['heading'] ?? '')) . '</a></li>';
            }
            $h[] = '</ol>';
            $h[] = '</nav>';
        }

        // ─ 본문
        foreach ($sections as $section) {
            $anchor = self::anchor((string) ($section['id'] ?? ''));
            $h[]    = '<section class="wiki-section" id="' . $anchor . '">';
            $h[]    = '<h2 class="wiki-h2">' . self::esc((string) ($section['heading'] ?? '')) . '</h2>';
            foreach (is_array($section['blocks'] ?? null) ? $section['blocks'] : [] as $block) {
                $h[] = self::renderBlock(is_array($block) ? $block : [], $index);
            }
            $h[] = '</section>';
        }

        // ─ 이전/다음 (차례 순)
        $h[] = self::renderNeighbors($index, (string) ($page['slug'] ?? ''), $group);

        $h[] = '</article>';

        return implode("\n", $h);
    }

    /**
     * 블록 하나를 그린다. 여기 오는 글은 validate() 를 통과한 것이지만,
     * 그리는 쪽도 값의 모양을 다시 본다 — 검사와 출력 사이에 빈틈을 두지 않는다.
     */
    private static function renderBlock(array $block, array $index): string
    {
        $type = (string) ($block['type'] ?? '');

        switch ($type) {
            case 'p':
                return '<p class="wiki-p">' . self::inline((string) ($block['text'] ?? '')) . '</p>';

            case 'list':
            case 'steps':
                $tag = $type === 'steps' ? 'ol' : 'ul';
                $h   = ['<' . $tag . ' class="wiki-' . $type . '">'];
                foreach (self::stringList($block['items'] ?? null) as $item) {
                    $h[] = '<li>' . self::inline($item) . '</li>';
                }
                $h[] = '</' . $tag . '>';
                return implode('', $h);

            case 'table':
                $h = ['<div class="table-wrap"><table class="files"><thead><tr>'];
                foreach (self::stringList($block['header'] ?? null) as $cell) {
                    $h[] = '<th scope="col">' . self::inline($cell) . '</th>';
                }
                $h[] = '</tr></thead><tbody>';
                foreach (is_array($block['rows'] ?? null) ? $block['rows'] : [] as $row) {
                    $h[] = '<tr>';
                    foreach (self::stringList($row) as $cell) {
                        $h[] = '<td>' . self::inline($cell) . '</td>';
                    }
                    $h[] = '</tr>';
                }
                $h[] = '</tbody></table></div>';
                return implode('', $h);

            case 'code':
                // 코드 안에서는 백틱도 글자다 — inline() 이 아니라 esc() 만 쓴다.
                return '<pre class="wiki-code"><code>' . self::esc((string) ($block['text'] ?? '')) . '</code></pre>';

            case 'callout':
                $kind = (string) ($block['kind'] ?? '');
                if (!in_array($kind, self::CALLOUT_KINDS, true)) {
                    $kind = 'why';
                }
                // 접두 글자(왜·어떻게·확인…)는 app.css 가 클래스로 붙인다 (HTML 에 글자를 만들지 않는다).
                return '<aside class="wiki-callout wiki-callout--' . $kind . '">'
                    . '<p class="wiki-callout__title">' . self::esc((string) ($block['title'] ?? '')) . '</p>'
                    . '<p class="wiki-callout__text">' . self::inline((string) ($block['text'] ?? '')) . '</p>'
                    . '</aside>';

            case 'terms':
                $h = ['<dl class="wiki-terms">'];
                foreach (is_array($block['items'] ?? null) ? $block['items'] : [] as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $h[] = '<dt>' . self::esc((string) ($item['term'] ?? '')) . '</dt>';
                    $h[] = '<dd>' . self::inline((string) ($item['easy'] ?? '')) . '</dd>';
                }
                $h[] = '</dl>';
                return implode('', $h);

            case 'refs':
                /* 첫 열(문서)만 줄이 바뀌지 않게 .nowrap — 예전 참고 표 전용 규칙과 같은 뜻 (C-10). */
                $h = ['<div class="table-wrap"><table class="files"><thead><tr>'
                    . '<th scope="col" class="nowrap">문서</th><th scope="col">위치</th>'
                    . '<th scope="col">무엇이 적혀 있나</th>'
                    . '</tr></thead><tbody>'];
                foreach (is_array($block['items'] ?? null) ? $block['items'] : [] as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $h[] = '<tr><td class="nowrap"><strong>' . self::esc((string) ($item['doc'] ?? '')) . '</strong></td>'
                        . '<td>' . self::inline((string) ($item['where'] ?? '')) . '</td>'
                        . '<td>' . self::inline((string) ($item['what'] ?? '')) . '</td></tr>';
                }
                $h[] = '</tbody></table></div>';
                return implode('', $h);

            case 'checklist':
                /* 행 = 검토 항목, 열 = 차수. 한 줄이 <details> 라 **누르면 펼쳐져** 그 항목을
                 * 어떤 명령으로 어떻게 확인했는지가 나온다. 자바스크립트를 쓰지 않는다 —
                 * 브라우저 기본 기능이라 CSP(script-src 'self')와도 무관하고, 스크립트가
                 * 막혀도 내용이 사라지지 않는다.
                 * 기호·툴팁 문구는 CHECK_MARKS(코드 상수)에서만 나온다. 글은 키만 고른다. */
                $rounds = self::stringList($block['rounds'] ?? null);
                /* 차수 개수는 **클래스 이름**으로 내보낸다. 인라인 style 속성은 CSP(style-src 'self')가
                 * 막으므로 쓸 수 없고, 개수는 1~CHECK_MAX_ROUNDS 로 이미 좁혀져 있다. */
                $count = max(1, min(self::CHECK_MAX_ROUNDS, count($rounds)));
                $h = ['<div class="wiki-check wiki-check--' . $count . '">'];

                // 머리줄 — 어떤 차수가 있는지 (표의 열 이름)
                $h[] = '<p class="wiki-check__head"><span class="wiki-check__head-label">검토 항목</span>';
                foreach ($rounds as $round) {
                    $h[] = '<span class="wiki-check__head-round">' . self::esc($round) . '</span>';
                }
                $h[] = '</p>';

                foreach (is_array($block['items'] ?? null) ? $block['items'] : [] as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $marks = self::stringList($item['marks'] ?? null);
                    $h[]   = '<details class="wiki-check__item">';
                    $h[]   = '<summary class="wiki-check__row">';
                    $h[]   = '<span class="wiki-check__label">'
                        . self::esc((string) ($item['label'] ?? '')) . '</span>';
                    foreach ($rounds as $i => $round) {
                        $key  = $marks[$i] ?? 'na';
                        $mark = self::CHECK_MARKS[$key] ?? self::CHECK_MARKS['na'];
                        // title 은 esc 를 거친 코드 상수 문구 + 차수 이름이다 (글의 자유 문자열이 아니다).
                        $h[] = '<span class="wiki-check__mark wiki-check__mark--' . self::esc($key)
                            . '" title="' . self::esc($round . ': ' . $mark[1]) . '">'
                            . self::esc($mark[0]) . '</span>';
                    }
                    $h[] = '</summary>';
                    $h[] = '<div class="wiki-check__body">';
                    $h[] = '<ul class="wiki-check__how">';
                    foreach (self::stringList($item['how'] ?? null) as $line) {
                        $h[] = '<li>' . self::inline($line) . '</li>';
                    }
                    $h[] = '</ul>';
                    if (isset($item['code']) && is_string($item['code']) && $item['code'] !== '') {
                        // 명령·응답 원문. 코드 안에서는 백틱도 글자다 — esc() 만 쓴다(code 블록과 같다).
                        $h[] = '<pre class="wiki-code"><code>' . self::esc($item['code']) . '</code></pre>';
                    }
                    $h[] = '</div></details>';
                }

                $h[] = '</div>';
                return implode('', $h);

            case 'link':
                $slug  = (string) ($block['slug'] ?? '');
                $label = isset($block['label']) && is_string($block['label']) && $block['label'] !== ''
                    ? $block['label']
                    : $slug;
                // 차례에 있는 글만 링크가 된다. 없으면 **글자만** 남는다 (ARCH 5.10).
                if (preg_match(self::SLUG_RE, $slug) === 1 && self::hasEntry($index, $slug)) {
                    return '<p class="wiki-link"><a href="' . Http::url(self::BASE_PATH) . '/' . self::esc($slug) . '">'
                        . self::esc($label) . '</a></p>';
                }
                return '<p class="wiki-link wiki-link--plain">' . self::esc($label) . '</p>';
        }

        // validate() 를 통과한 글에는 올 수 없는 자리다. 그래도 조용히 빈칸을 두지 않는다.
        throw new RuntimeException('모르는 블록 종류입니다');
    }

    /** 이전/다음 글 — 단계 글은 단계 번호 순, 안내 글은 차례에 적힌 순서 */
    private static function renderNeighbors(array $index, string $slug, string $group): string
    {
        $siblings = [];
        foreach (self::entries($index) as $entry) {
            if ($entry['group'] === $group && $entry['exists']) {
                $siblings[] = $entry;
            }
        }
        if ($group === 'stage') {
            usort($siblings, static function (array $a, array $b): int {
                return ($a['stage'] ?? PHP_INT_MAX) <=> ($b['stage'] ?? PHP_INT_MAX);
            });
        }

        $at = null;
        foreach ($siblings as $i => $entry) {
            if ($entry['slug'] === $slug) {
                $at = $i;
                break;
            }
        }
        if ($at === null) {
            return '';
        }
        $prev = $siblings[$at - 1] ?? null;
        $next = $siblings[$at + 1] ?? null;
        if ($prev === null && $next === null) {
            return '';
        }

        $h   = ['<nav class="wiki-nav" aria-label="이전·다음 글">'];
        $h[] = $prev === null
            ? '<span class="wiki-nav__gap"></span>'
            : '<a class="wiki-nav__item" href="' . Http::url(self::BASE_PATH) . '/' . self::esc($prev['slug']) . '">'
                . '<span class="wiki-nav__dir">← 이전</span>'
                . '<span class="wiki-nav__title">' . self::esc((string) $prev['title']) . '</span></a>';
        $h[] = $next === null
            ? '<span class="wiki-nav__gap"></span>'
            : '<a class="wiki-nav__item wiki-nav__item--next" href="' . Http::url(self::BASE_PATH) . '/' . self::esc($next['slug']) . '">'
                . '<span class="wiki-nav__dir">다음 →</span>'
                . '<span class="wiki-nav__title">' . self::esc((string) $next['title']) . '</span></a>';
        $h[] = '</nav>';

        return implode('', $h);
    }

    /* ─── 작은 도우미 ────────────────────────────────────────── */

    /** 차례 항목 한 줄의 제목(링크 또는 글자) */
    private static function entryLink(array $entry): string
    {
        $title = self::esc((string) $entry['title']);
        if ($entry['exists'] !== true) {
            return '<span class="wiki-guide__title wiki-guide__title--soon">' . $title
                . ' <span class="badge badge--off">준비 중</span></span>';
        }
        return '<a class="wiki-guide__title" href="' . Http::url(self::BASE_PATH) . '/' . self::esc((string) $entry['slug']) . '">'
            . $title . '</a>';
    }

    /** 차례 항목 한 줄의 설명·날짜 */
    private static function entryMeta(array $entry): string
    {
        $h = [];
        if ((string) $entry['summary'] !== '') {
            $h[] = '<span class="wiki-guide__sub">' . self::inline((string) $entry['summary']) . '</span>';
        }
        if ((string) $entry['updated'] !== '') {
            $h[] = '<span class="wiki-guide__date muted">' . self::esc((string) $entry['updated']) . '</span>';
        }
        return implode('', $h);
    }

    /** 단계 카드 하나 */
    private static function stageCard(array $entry): string
    {
        $num  = $entry['stage'] === null ? '·' : self::esc((string) $entry['stage']);
        /* 「준비 중」 배지는 카드 끝이 아니라 제목 옆(.card__head)에 붙는다 (DESIGN 2.3, C-7). */
        $soon = $entry['exists'] !== true;
        $in   = '<span class="card__icon">' . $num . '</span>'
            . '<span class="card__body">'
            . '<span class="card__head">'
            . '<span class="card__title">' . self::esc((string) $entry['title']) . '</span>'
            . ($soon ? '<span class="badge badge--off">준비 중</span>' : '')
            . '</span>'
            . ((string) $entry['summary'] !== ''
                ? '<span class="card__desc">' . self::inline((string) $entry['summary']) . '</span>' : '')
            . ((string) $entry['updated'] !== ''
                ? '<span class="card__meta">고친 날 ' . self::esc((string) $entry['updated']) . '</span>' : '')
            . '</span>';

        if ($soon) {
            return '<span class="card card--link card--soon">' . $in . '</span>';
        }
        return '<a class="card card--link" href="' . Http::url(self::BASE_PATH) . '/' . self::esc((string) $entry['slug']) . '">'
            . $in . '</a>';
    }

    /**
     * 절 앵커. id 는 ID_RE 를 통과한 값이며, 공통 셸의 id 와 겹치지 않게 접두사를 붙인다.
     * 접두사를 붙여도 값은 여전히 글이 정한 id 하나에서 나온다.
     */
    private static function anchor(string $id): string
    {
        return 'wiki-' . self::esc(preg_match(self::ID_RE, $id) === 1 ? $id : 'section');
    }

    /**
     * 값이 문자열 배열이면 그대로, 아니면 빈 배열. 그리는 쪽의 마지막 그물이다.
     *
     * @param mixed $value
     * @return list<string>
     */
    private static function stringList($value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_string($item)) {
                $out[] = $item;
            }
        }
        return $out;
    }

    /** 비어 있지 않은 문자열인가 */
    private static function isFilledString(mixed $value): bool
    {
        return is_string($value) && $value !== '';
    }

    /** 문제 목록에 값을 적을 때 쓰는 짧은 설명 (값 자체를 길게 쏟지 않는다) */
    private static function describe(mixed $value): string
    {
        if (is_string($value)) {
            return strlen($value) > 40 ? 'string(' . strlen($value) . ')' : 'string "' . $value . '"';
        }
        return get_debug_type($value);
    }
}
