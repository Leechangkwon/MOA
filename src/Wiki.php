<?php
declare(strict_types=1);

/**
 * src/Wiki.php — 위키 글(`wiki/<책>/*.json`)을 읽고, 검사하고, **이스케이프해서** 그린다
 *                (14단계 F-21 · ARCHITECTURE 5.10 · SECURITY R-56 · PRD S-03)
 *
 * 무엇인가
 *   네 문서(PRD·ARCHITECTURE·SECURITY·STAGES)를 초보자 말로 다시 쓴 해설 글을 앱 안에서
 *   보여 주기 위한 렌더러다. 원문 마크다운을 파싱하지 않는다 — 마크다운 파서는 HTML 을
 *   통과시키는 통로가 되기 쉽고(저장형 XSS), 외부 라이브러리도 쓰지 않기 때문이다
 *   (의존성 0 원칙, PRD S-10-C).
 *
 * 책 셋 (2026-09-23, 사용자 지시 「위키 접속 시 Qaredi · T-box · Community 셋을 보여 주고 골라 들어가게」)
 *   글은 **책**(`BOOKS`)으로 나뉜다 — `qaredi`(사이트 공통) · `tbox`(파일 공유) · `community`(커뮤니티).
 *   파일은 `wiki/<책>/<slug>.json`, 차례는 `wiki/<책>/index.json` 이다. 책 이름은 **고정 목록과
 *   정확히 같은지**로만 받고(`bookKey`), 경로에는 요청 문자열이 아니라 목록에 적힌 키를 쓴다.
 *   단계 번호의 모양은 책마다 다르다(`validStage`) — t-box 는 정수 1~28, 커뮤니티는 `3`·`3-B`·
 *   `4-A`·`4+`(그 단계 뒤 보정), 사이트는 `Q-0`. 전에는 t-box 정수만 받아서 커뮤니티 단계가
 *   들어갈 자리가 없었다(사이트 STAGES 2장 2026-09-23 「이건 왜 위키에 기록이 안 되니」).
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
 *   ③ **파일 경로에 사용자 입력이 그대로 들어가지 않는다.** 책 이름은 고정 목록(`BOOKS`)의
 *      키와 정확히 같아야 하고 경로에는 **목록 쪽 문자열**이 들어간다. slug 는 라우터 자리표시자
 *      정규식을 통과한 뒤 여기서 한 번 더 `SLUG_RE` 로 검사한다. 책 디렉터리와 글 파일 둘 다
 *      `realpath` 로 풀어 `wiki/` · `wiki/<책>/` 안인지 다시 본다 (Storage::pathFor 와 같은 방식, ARCH 5.10).
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

    /** 글 묶음 — 안내 글(guide) 과 단계 글(stage) 둘뿐이다 (책마다 같다) */
    public const GROUPS = ['guide', 'stage'];

    /**
     * 책의 **고정 목록** — 순서가 곧 책 고르기 화면의 순서다 (2026-09-23).
     *
     * 키는 디렉터리 이름이자 주소 한 칸(`/wiki/{book}`)이다. 라우터 자리표시자 `{book}` 의 정규식
     * (`public/index.php` TBOX_PATH_PATTERNS)이 이 키 셋과 **같은 글자**여야 한다 — 늘리려면 둘을
     * 함께 고친다. 그러지 않으면 새 책은 라우트에 맞지 않아 404 로 남는다(열리는 쪽이 아니라
     * 닫히는 쪽으로 어긋난다).
     *
     * 이름·설명·묶음 부제는 화면 문구다. 글(JSON)이 아니라 코드가 정한다 — 책의 이름이 글쓴이
     * 마음대로 바뀌지 않게(checklist 표시를 코드 상수로 둔 것과 같은 이유).
     *
     * @var array<string,array{name:string,desc:string,guide_sub:string,stage_sub:string,icon:string}>
     */
    public const BOOKS = [
        'qaredi' => [
            'name'      => 'Qaredi',
            'desc'      => '사이트 전체 — 이름·런처·폴더 구조, 두 앱의 관계와 통합 기록',
            'guide_sub' => '사이트가 무엇이고 어떻게 나뉘어 있는지부터 봅니다.',
            'stage_sub' => '기획(Q-0)과 통합(Q-1)을 차례로 적었습니다.',
            'icon'      => 'Q',
        ],
        'tbox' => [
            'name'      => 't-box',
            'desc'      => '사내 파일 공유 — 단계마다 무엇을 만들고 무엇을 막았나',
            'guide_sub' => '이 서비스가 무엇이고 어떤 말을 쓰는지부터 봅니다.',
            'stage_sub' => '1단계부터 차례로 무엇을 만들고 무엇을 막았는지 적었습니다.',
            'icon'      => 'T',
        ],
        'community' => [
            'name'      => '커뮤니티',
            'desc'      => '개인 업무 공간 — 블록 문서·공개 범위·이력·상태',
            'guide_sub' => '커뮤니티가 무엇이고 누가 무엇을 볼 수 있는지부터 봅니다.',
            'stage_sub' => '3단계부터 차례로 무엇을 만들고 무엇을 막았는지 적었습니다.',
            'icon'      => 'C',
        ],
    ];

    /** 글 이름으로 쓸 수 없는 slug — 책 디렉터리 안의 차례 파일(`index.json`) 이름 */
    public const RESERVED_SLUGS = ['index'];

    /** 옛 주소 `/wiki/{slug}` 가 가리키던 책 — 책이 나뉘기 전의 글은 전부 t-box 것이었다 */
    public const LEGACY_BOOK = 'tbox';

    /**
     * t-box 단계 글의 번호 범위 — 정수. `STAGE_MAX` 는 t-box PRD 13장 로드맵 끝과 같게 둔다
     * (CLAUDE.md 9장). 28단계 = `/tbox/` base-path · 런처 · 옛 주소 308.
     */
    public const STAGE_MIN = 1;
    public const STAGE_MAX = 28;   // t-box PRD 13장 로드맵 끝(28단계)

    /**
     * 커뮤니티 단계 번호 — 문자열. `3` · `3-B` · `4-A` 처럼 **숫자 + 선택 조각 글자 하나**, 또는
     * `4+` 처럼 **숫자 + 「+」**(그 단계가 끝난 뒤의 보정 묶음 — t-box STAGES 0장 표의 `3+` 행과
     * 같은 표기). `post-4` 같은 낱말 모양을 받지 않은 이유: 정렬 규칙이 하나로 서지 않고(낱말마다
     * 자리를 따로 정해야 한다) 표기가 늘어날수록 오타가 조용히 새 번호가 된다.
     */
    public const COMMUNITY_STAGE_RE = '/^[0-9]{1,2}(-[A-Z]|\+)?$/';

    /** 사이트(Qaredi) 단계 번호 — 문자열 `Q-0` · `Q-1` … (사이트 STAGES 0장 표의 표기 그대로) */
    public const QAREDI_STAGE_RE = '/^Q-[0-9]{1,2}$/';

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

    /**
     * 「원문 위치」가 가리킬 수 있는 문서 (ARCH 5.10). 처음 여섯에 둘을 더했다(2026-09-23) —
     * 커뮤니티·사이트 글은 단계 지시서(`DIRECTIVE`)와 통합 계획(`PLAN`)을 근거로 드는 일이 많다.
     * 어느 모듈의 문서인지는 `where` 에 경로로 적는다(예: `community/PRD.md 4.1`).
     */
    public const REF_DOCS = ['PRD', 'ARCH', 'SECURITY', 'STAGES', 'README', 'CLAUDE', 'DIRECTIVE', 'PLAN'];

    /**
     * 차례(`wiki/<책>/index.json`)에서 글 목록을 담을 수 있는 열쇠 이름들.
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

    /** 이 화면들의 주소. 링크를 만들 때 책은 BOOKS 의 키, slug 는 SLUG_RE 를 통과한 값만 붙인다 */
    private const BASE_PATH = '/wiki';

    /* ─── 책 ─────────────────────────────────────────────────── */

    /**
     * 요청에서 온 책 이름을 **고정 목록의 키**로 바꾼다. 목록에 없으면 null.
     *
     * 돌려주는 값은 요청 문자열이 아니라 `BOOKS` 에 적힌 문자열이다 — 둘이 글자로 같더라도
     * 경로를 만드는 쪽에는 언제나 코드 상수가 들어간다(오픈 리다이렉트 표를 `TBOX_REAUTH_PATHS`
     * 로 묶은 것과 같은 방식). 대소문자·앞뒤 공백·`.` 은 같지 않으므로 전부 null 이다.
     */
    public static function bookKey(string $book): ?string
    {
        foreach (array_keys(self::BOOKS) as $key) {
            if ($key === $book) {
                return $key;
            }
        }
        return null;
    }

    /** 고정 목록에 있는 책인가 */
    public static function isBook(string $book): bool
    {
        return self::bookKey($book) !== null;
    }

    /** 책의 화면 이름 (모르는 책이면 빈 문자열 — 호출한 쪽이 이미 bookKey 로 걸렀다) */
    public static function bookName(string $book): string
    {
        $key = self::bookKey($book);
        return $key === null ? '' : self::BOOKS[$key]['name'];
    }

    /* ─── 경로 ───────────────────────────────────────────────── */

    /**
     * 위키 전체의 뿌리 디렉터리 **실제 경로**. 저장소 루트의 `wiki/` 이며 `public/` 밖이라
     * URL 로 직접 닿지 않는다 (ARCH 3장·5.10). 글은 이 아래 책 디렉터리에만 있다.
     *
     * @throws RuntimeException 디렉터리가 없을 때
     */
    public static function rootDir(): string
    {
        $dir = realpath(dirname(__DIR__) . '/wiki');
        if ($dir === false || !is_dir($dir)) {
            throw new RuntimeException('위키 디렉터리를 찾을 수 없습니다');
        }
        return $dir;
    }

    /**
     * 책 하나의 디렉터리 **실제 경로** (`wiki/<책>/`).
     *
     *   [1] 책 이름은 고정 목록 비교(`bookKey`)만 통과한다 — 경로에는 목록의 키가 들어간다.
     *   [2] 만들어진 경로를 realpath 로 풀어 **정확히** `wiki/<책>` 인지 확인한다 — 책 디렉터리를
     *       심볼릭 링크로 바꿔 밖을 가리키게 해도 여기서 걸린다(글 파일에 하는 검사를 한 층 위에도).
     *
     * @throws InvalidArgumentException 목록에 없는 책 (코드의 버그이지 사용자의 요청이 아니다 — 라우터가 먼저 거른다)
     * @throws RuntimeException         디렉터리가 없거나 wiki/ 밖을 가리킬 때
     */
    public static function dir(string $book): string
    {
        $key = self::bookKey($book);
        if ($key === null) {
            throw new InvalidArgumentException('허용되지 않는 책입니다');
        }
        $root = self::rootDir();
        $want = $root . '/' . $key;
        $real = realpath($want);
        if ($real === false || !is_dir($real)) {
            throw new RuntimeException('위키 책 디렉터리를 찾을 수 없습니다');
        }
        if ($real !== $want) {
            throw new RuntimeException('위키 디렉터리 밖의 경로입니다');
        }
        return $real;
    }

    /**
     * 책 + slug 로 글 파일의 실제 경로를 만든다. **사용자 입력이 경로에 들어가는 유일한 자리**다.
     *
     *   [1] 책은 `dir()` 이 고정 목록 + realpath 로 본다.
     *   [2] SLUG_RE 정확 일치 — `.`·`/`·대문자·%인코딩이 한 글자도 통과하지 못한다.
     *       (라우터 `{slug}` 자리표시자가 이미 같은 검사를 하지만, 이 클래스를 CLI 에서도
     *        쓰므로 여기서 다시 본다. 검사는 값을 쓰는 쪽에 있어야 한다.)
     *   [3] 만들어진 경로를 realpath 로 풀어 `wiki/<책>/` 안인지 확인 — 심볼릭 링크로 밖을
     *       가리키는 파일이 있어도 여기서 걸린다 (Storage::pathFor 와 같은 방식).
     *
     * 파일이 없으면 null 을 돌려준다(호출한 쪽이 404 로 끝낸다).
     *
     * @throws InvalidArgumentException 책·slug 형태가 아닐 때 (코드의 버그이지 사용자의 요청이 아니다)
     * @throws RuntimeException         경로가 wiki/<책>/ 밖을 가리킬 때
     */
    public static function pathFor(string $book, string $slug): ?string
    {
        if (preg_match(self::SLUG_RE, $slug) !== 1) {
            throw new InvalidArgumentException('허용되지 않는 글 이름입니다');
        }
        // `index` 는 차례 파일의 이름이다 — 글로 읽으면 구조 오류로 500 이 된다(2026-09-23 시험이 잡음).
        // 글이 아니므로 「없는 글」과 같이 null(→ 404 동일 문구).
        if (in_array($slug, self::RESERVED_SLUGS, true)) {
            return null;
        }
        $base = self::dir($book);
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

    /** 그 책에 그 slug 의 글 파일이 있나 (차례에서 "준비 중" 을 가려내는 데 쓴다) */
    public static function pageExists(string $book, string $slug): bool
    {
        if (!self::isBook($book) || preg_match(self::SLUG_RE, $slug) !== 1) {
            return false;
        }
        return self::pathFor($book, $slug) !== null;
    }

    /* ─── 단계 번호 (책마다 모양이 다르다) ───────────────────────── */

    /**
     * 그 책의 단계 번호로 맞는 값인가.
     *   tbox       정수 STAGE_MIN~STAGE_MAX
     *   community  문자열 COMMUNITY_STAGE_RE (`3` · `3-B` · `4-A` · `4+`)
     *   qaredi     문자열 QAREDI_STAGE_RE (`Q-0`)
     * 책마다 **한 가지 자료형**만 받는다 — t-box 에 `"27"`(문자열)을, 커뮤니티에 `3`(정수)을 적으면
     * 틀린 것이다. 섞어 받으면 정렬·이웃 글·빠진 글 검사가 같은 단계를 두 번 세게 된다.
     *
     * @param mixed $stage
     */
    public static function validStage(string $book, $stage): bool
    {
        switch (self::bookKey($book)) {
            case 'tbox':
                return is_int($stage) && $stage >= self::STAGE_MIN && $stage <= self::STAGE_MAX;
            case 'community':
                return is_string($stage) && preg_match(self::COMMUNITY_STAGE_RE, $stage) === 1;
            case 'qaredi':
                return is_string($stage) && preg_match(self::QAREDI_STAGE_RE, $stage) === 1;
        }
        return false;
    }

    /** 검사 문제 목록에 적을 「이 책의 단계 번호는 이런 모양」 설명 */
    public static function stageRule(string $book): string
    {
        switch (self::bookKey($book)) {
            case 'tbox':
                return self::STAGE_MIN . '~' . self::STAGE_MAX . ' 범위의 정수여야 한다';
            case 'community':
                return '문자열 `3`·`3-B`·`4-A`·`4+` 모양이어야 한다 (' . self::COMMUNITY_STAGE_RE . ')';
            case 'qaredi':
                return '문자열 `Q-0` 모양이어야 한다 (' . self::QAREDI_STAGE_RE . ')';
        }
        return '모르는 책이다';
    }

    /**
     * 정렬 열쇠 — 세 칸짜리 정수 배열이라 `<=>` 로 칸마다 견준다.
     *   [숫자, 조각, 0] : `4` → [4,0,0] · `4-A` → [4,1,0] · `4-D` → [4,4,0] · `4+` → [4,27,0]
     * 그래서 커뮤니티는 3 → 3-B → 4-A → … → 4-D → 4+ 순이다(「+」는 그 번호의 조각들 뒤).
     * 맞지 않는 값(null 포함)은 맨 뒤.
     *
     * @param mixed $stage
     * @return array{0:int,1:int,2:int}
     */
    public static function stageSortKey(string $book, $stage): array
    {
        if (!self::validStage($book, $stage)) {
            return [PHP_INT_MAX, 0, 0];
        }
        if (is_int($stage)) {
            return [$stage, 0, 0];
        }
        $s = (string) $stage;
        if (strncmp($s, 'Q-', 2) === 0) {
            return [(int) substr($s, 2), 0, 0];
        }
        if (substr($s, -1) === '+') {
            return [(int) substr($s, 0, -1), 27, 0];
        }
        $dash = strpos($s, '-');
        if ($dash === false) {
            return [(int) $s, 0, 0];
        }
        return [(int) substr($s, 0, $dash), ord($s[$dash + 1]) - ord('A') + 1, 0];
    }

    /**
     * 화면에 나가는 단계 이름 — `27단계` · `4-A단계` · `4단계 뒤` · `Q-0`.
     * 이 값도 esc() 를 거쳐 나간다(모양은 validStage 가 이미 좁혔다).
     *
     * @param mixed $stage
     */
    public static function stageLabel(string $book, $stage): string
    {
        if (!self::validStage($book, $stage)) {
            return '단계';
        }
        $s = (string) $stage;
        if (self::bookKey($book) === 'qaredi') {
            return $s;
        }
        if (substr($s, -1) === '+') {
            return substr($s, 0, -1) . '단계 뒤';
        }
        return $s . '단계';
    }

    /* ─── 읽기 ───────────────────────────────────────────────── */

    /**
     * 책의 차례(`wiki/<책>/index.json`)를 읽는다. **파일이 없으면 null** — 아직 글을 쓰지 않은
     * 상태는 오류가 아니다(화면이 "아직 글이 없습니다" 를 보여 준다). 파일이 있는데 모양이
     * 틀렸으면 예외다.
     *
     * @throws RuntimeException
     */
    public static function loadIndex(string $book): ?array
    {
        $path = self::dir($book) . '/index.json';
        if (!is_file($path)) {
            return null;
        }
        $index = self::readJsonObject($path);

        $problems = self::validateIndex($index, $book);
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
    public static function loadPage(string $book, string $slug): ?array
    {
        $path = self::pathFor($book, $slug);
        if ($path === null) {
            return null;
        }
        $page = self::readJsonObject($path);

        $problems = self::validate($page, $book);
        if (($page['slug'] ?? null) !== $slug) {
            $problems[] = 'slug: 파일 이름(' . $slug . ')과 글 안의 slug 가 다르다';
        }
        if ($problems !== []) {
            throw new RuntimeException($slug . '.json 구조 오류: ' . implode(' | ', $problems));
        }
        return $page;
    }

    /**
     * 책 하나의 `wiki/<책>/*.json` 파일 이름(slug)을 전부 모은다 — CLI 검사용. index.json 은 뺀다.
     *
     * @return list<string>
     */
    public static function allSlugs(string $book): array
    {
        $found = glob(self::dir($book) . '/*.json');
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
     * 단계 번호의 모양은 **책마다** 다르므로 어느 책의 글인지 함께 받는다(`validStage`).
     *
     * @return list<string>
     */
    public static function validate(array $page, string $book): array
    {
        $p = [];

        if (!self::isBook($book)) {
            return ['(책): 모르는 책이다'];
        }

        if (!self::isFilledString($page['slug'] ?? null) || preg_match(self::SLUG_RE, (string) $page['slug']) !== 1) {
            $p[] = 'slug: 없거나 형태가 아니다 (^[a-z0-9][a-z0-9-]{0,39}$)';
        } elseif (in_array((string) $page['slug'], self::RESERVED_SLUGS, true)) {
            $p[] = 'slug: ' . implode('·', self::RESERVED_SLUGS) . ' 는 차례 파일 이름이라 글 이름으로 쓸 수 없다';
        } elseif (self::isBook((string) $page['slug'])) {
            // `/wiki/tbox` 는 책 차례 주소다. 책 이름과 같은 slug 는 옛 주소 308 과 겹쳐 닿을 수 없다.
            $p[] = 'slug: 책 이름(' . implode('·', array_keys(self::BOOKS)) . ')과 같은 이름은 쓸 수 없다';
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
            if (!self::validStage($book, $page['stage'] ?? null)) {
                $p[] = 'stage: ' . self::stageRule($book);
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
                foreach (self::validateBlock($block, $at . '.blocks[' . $j . ']', $book) as $one) {
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
    private static function validateBlock($block, string $at, string $book): array
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
                // (선택) 다른 책의 글로 가는 링크. 없으면 **같은 책**이다. 값은 고정 목록 안이어야 한다.
                if (array_key_exists('book', $block)
                    && (!is_string($block['book']) || !self::isBook($block['book']))) {
                    $p[] = $at . '.book: ' . implode('·', array_keys(self::BOOKS)) . ' 중 하나여야 한다';
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
    public static function validateIndex(array $index, string $book): array
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
                && !self::validStage($book, $row['stage'])) {
                $p[] = $at . '.stage: ' . self::stageRule($book);
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
     * `stage` 는 책의 모양(`validStage`)을 통과한 값만 남긴다 — t-box 는 정수, 나머지는 문자열.
     *
     * @return list<array<string,mixed>>
     */
    public static function entries(array $index, string $book): array
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

            $stage = isset($r['stage']) && self::validStage($book, $r['stage']) ? $r['stage'] : null;
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
                'exists'  => self::pageExists($book, $slug),
            ];
        }

        return $out;
    }

    /** 단계 글을 단계 번호 순으로 (차례 화면·이전/다음이 같은 규칙을 쓴다) */
    private static function sortStages(array &$stages, string $book): void
    {
        usort($stages, static function (array $a, array $b) use ($book): int {
            return self::stageSortKey($book, $a['stage']) <=> self::stageSortKey($book, $b['stage']);
        });
    }

    /**
     * 차례에 있는 slug 인가. `link` 블록이 링크가 될지 글자가 될지를 가르는 값이다 (ARCH 5.10).
     * 차례 항목의 slug 만 보므로 책은 필요 없다 — 어느 책의 차례인지는 넘기는 쪽이 정한다.
     */
    public static function hasEntry(array $index, string $slug): bool
    {
        $rows = self::rawIndexRows($index);
        foreach ($rows ?? [] as $pair) {
            $row = $pair[0];
            if ((is_string($row) ? $row : (is_array($row) ? ($row['slug'] ?? null) : null)) === $slug) {
                return true;
            }
        }
        return false;
    }

    /**
     * 차례 화면·글 화면에 나가는 주소. 책은 BOOKS 의 키, slug 는 SLUG_RE 를 통과한 값만 붙는다.
     * 둘 다 맞지 않으면 책 고르기(`/wiki`)로 — 요청에서 온 글자가 주소에 들어갈 자리를 만들지 않는다.
     */
    private static function href(string $book, ?string $slug = null): string
    {
        $key = self::bookKey($book);
        if ($key === null) {
            return Http::url(self::BASE_PATH);
        }
        if ($slug === null || preg_match(self::SLUG_RE, $slug) !== 1) {
            return Http::url(self::BASE_PATH . '/' . $key);
        }
        return Http::url(self::BASE_PATH . '/' . $key . '/' . $slug);
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
     * 책 고르기 화면(`/wiki`)의 본문 — 책 셋의 타일(이름·한 줄 설명·글 수).
     *
     * 모양은 런처(`src/views/launcher.php`)·관리자 허브와 **같은 부품**이다: `.card-grid` >
     * `a.card.card--link` > `.card__icon` + `.card__body`(`.card__title`·`.card__desc`·`.card__meta`).
     * 화면 전용 클래스를 만들지 않는다(DESIGN 원칙 6). 스크립트 없이 링크만으로 동작한다.
     *
     * 글 수는 차례에 적혀 있고 **파일도 있는** 글이다(「준비 중」은 세지 않는다). 차례 파일이
     * 없는 책은 0편으로 보이고, 들어가면 "아직 글이 없습니다" 가 나온다. 차례가 깨진 책은
     * 조용히 0 으로 두지 않는다 — loadIndex 가 예외를 던져 500 이 된다(ARCH 5.10 원칙 ②).
     */
    public static function renderBooks(): string
    {
        $h   = ['<section class="card-grid">'];
        foreach (self::BOOKS as $key => $book) {
            $index = self::loadIndex($key);
            $count = 0;
            foreach ($index === null ? [] : self::entries($index, $key) as $entry) {
                if ($entry['exists'] === true) {
                    $count++;
                }
            }
            $h[] = '<a class="card card--link" href="' . self::esc(self::href($key)) . '">'
                . '<span class="card__icon">' . self::esc($book['icon']) . '</span>'
                . '<span class="card__body">'
                . '<strong class="card__title">' . self::esc($book['name']) . '</strong>'
                . '<span class="card__desc">' . self::esc($book['desc']) . '</span>'
                . '<span class="card__meta">글 ' . self::esc((string) $count) . '편</span>'
                . '</span></a>';
        }
        $h[] = '</section>';

        return implode("\n", $h);
    }

    /**
     * 책 하나의 차례 화면(`/wiki/{book}`) 본문을 만든다. 안내 글 목록 + 단계 카드.
     *
     * 글 파일이 아직 없는 항목은 링크 대신 「준비 중」으로 그린다 — 눌러서 404 를 만나는 것보다
     * 낫고, 글을 쓰는 사람과 화면을 만드는 사람이 동시에 일할 수 있다.
     */
    public static function renderIndex(string $book, array $index): string
    {
        $key     = self::bookKey($book) ?? self::LEGACY_BOOK;
        $entries = self::entries($index, $key);
        $guides  = [];
        $stages  = [];
        foreach ($entries as $entry) {
            if ($entry['group'] === 'stage') {
                $stages[] = $entry;
            } else {
                $guides[] = $entry;
            }
        }
        self::sortStages($stages, $key);

        $h = [];

        $h[] = '<section class="panel wiki-group">';
        $h[] = '<div class="panel__head">';
        $h[] = '<h2 class="panel__title">안내</h2>';
        $h[] = '<p class="panel__sub">' . self::esc(self::BOOKS[$key]['guide_sub']) . '</p>';
        $h[] = '</div>';
        if ($guides === []) {
            $h[] = '<p class="muted">안내 글이 아직 없습니다.</p>';
        } else {
            $h[] = '<ul class="wiki-guides">';
            foreach ($guides as $entry) {
                $h[] = '<li class="wiki-guide">' . self::entryLink($key, $entry) . self::entryMeta($entry) . '</li>';
            }
            $h[] = '</ul>';
        }
        $h[] = '</section>';

        $h[] = '<section class="panel wiki-group">';
        $h[] = '<div class="panel__head">';
        $h[] = '<h2 class="panel__title">단계</h2>';
        $h[] = '<p class="panel__sub">' . self::esc(self::BOOKS[$key]['stage_sub']) . '</p>';
        $h[] = '</div>';
        if ($stages === []) {
            $h[] = '<p class="muted">단계 글이 아직 없습니다.</p>';
        } else {
            $h[] = '<div class="card-grid">';
            foreach ($stages as $entry) {
                $h[] = self::stageCard($key, $entry);
            }
            $h[] = '</div>';
        }
        $h[] = '</section>';

        return implode("\n", $h);
    }

    /**
     * 글 화면(`/wiki/{book}/{slug}`)의 본문을 만든다.
     * 제목·요약 → 절 차례(앵커) → 절마다 블록 → 이전/다음 글.
     *
     * $index 는 **같은 책**의 차례이고 링크 판단(`link.slug` 가 차례에 있나)·이전/다음에만 쓴다.
     * 차례가 없어도(빈 배열) 글은 그려진다.
     */
    public static function renderPage(string $book, array $page, array $index): string
    {
        $h = [];

        $key   = self::bookKey($book) ?? self::LEGACY_BOOK;
        $group = (string) ($page['group'] ?? 'guide');
        $stage = isset($page['stage']) && self::validStage($key, $page['stage']) ? $page['stage'] : null;

        $h[] = '<article class="wiki-article">';

        // ─ 머리말 — 경로는 「위키 › 책 차례」 두 칸이다. 둘 다 코드가 만든 주소다.
        $h[] = '<header class="wiki-head">';
        $h[] = '<p class="wiki-crumb"><a href="' . self::esc(Http::url(self::BASE_PATH)) . '">위키</a>'
            . ' › <a href="' . self::esc(self::href($key)) . '">' . self::esc(self::BOOKS[$key]['name']) . ' 차례</a></p>';
        $h[] = '<h1 class="wiki-title">' . self::esc((string) ($page['title'] ?? '')) . '</h1>';
        if (isset($page['summary']) && is_string($page['summary']) && $page['summary'] !== '') {
            $h[] = '<p class="wiki-summary">' . self::inline($page['summary']) . '</p>';
        }
        $h[] = '<p class="wiki-meta">';
        $h[] = '<span class="badge badge--off">' . self::esc(self::BOOKS[$key]['name']) . '</span>';
        $h[] = $stage !== null
            ? '<span class="badge badge--internal">' . self::esc(self::stageLabel($key, $stage)) . '</span>'
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
                $h[] = self::renderBlock($key, is_array($block) ? $block : [], $index);
            }
            $h[] = '</section>';
        }

        // ─ 이전/다음 (차례 순)
        $h[] = self::renderNeighbors($key, $index, (string) ($page['slug'] ?? ''), $group);

        $h[] = '</article>';

        return implode("\n", $h);
    }

    /**
     * 블록 하나를 그린다. 여기 오는 글은 validate() 를 통과한 것이지만,
     * 그리는 쪽도 값의 모양을 다시 본다 — 검사와 출력 사이에 빈틈을 두지 않는다.
     */
    private static function renderBlock(string $book, array $block, array $index): string
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
                // 가는 책 — 적혀 있지 않으면 같은 책. 다른 책이면 **그 책의 차례**로 판단한다.
                $target      = isset($block['book']) && is_string($block['book']) ? self::bookKey($block['book']) : $book;
                $targetIndex = $target === null ? [] : ($target === $book ? $index : (self::loadIndex($target) ?? []));
                // 차례에 있는 글만 링크가 된다. 없으면 **글자만** 남는다 (ARCH 5.10).
                if ($target !== null && preg_match(self::SLUG_RE, $slug) === 1 && self::hasEntry($targetIndex, $slug)) {
                    return '<p class="wiki-link"><a href="' . self::esc(self::href($target, $slug)) . '">'
                        . self::esc($label) . '</a></p>';
                }
                return '<p class="wiki-link wiki-link--plain">' . self::esc($label) . '</p>';
        }

        // validate() 를 통과한 글에는 올 수 없는 자리다. 그래도 조용히 빈칸을 두지 않는다.
        throw new RuntimeException('모르는 블록 종류입니다');
    }

    /** 이전/다음 글 — 같은 책 안에서. 단계 글은 단계 번호 순(`stageSortKey`), 안내 글은 차례에 적힌 순서 */
    private static function renderNeighbors(string $book, array $index, string $slug, string $group): string
    {
        $siblings = [];
        foreach (self::entries($index, $book) as $entry) {
            if ($entry['group'] === $group && $entry['exists']) {
                $siblings[] = $entry;
            }
        }
        if ($group === 'stage') {
            self::sortStages($siblings, $book);
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
            : '<a class="wiki-nav__item" href="' . self::esc(self::href($book, (string) $prev['slug'])) . '">'
                . '<span class="wiki-nav__dir">← 이전</span>'
                . '<span class="wiki-nav__title">' . self::esc((string) $prev['title']) . '</span></a>';
        $h[] = $next === null
            ? '<span class="wiki-nav__gap"></span>'
            : '<a class="wiki-nav__item wiki-nav__item--next" href="' . self::esc(self::href($book, (string) $next['slug'])) . '">'
                . '<span class="wiki-nav__dir">다음 →</span>'
                . '<span class="wiki-nav__title">' . self::esc((string) $next['title']) . '</span></a>';
        $h[] = '</nav>';

        return implode('', $h);
    }

    /* ─── 작은 도우미 ────────────────────────────────────────── */

    /** 차례 항목 한 줄의 제목(링크 또는 글자) */
    private static function entryLink(string $book, array $entry): string
    {
        $title = self::esc((string) $entry['title']);
        if ($entry['exists'] !== true) {
            return '<span class="wiki-guide__title wiki-guide__title--soon">' . $title
                . ' <span class="badge badge--off">준비 중</span></span>';
        }
        return '<a class="wiki-guide__title" href="' . self::esc(self::href($book, (string) $entry['slug'])) . '">'
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
    private static function stageCard(string $book, array $entry): string
    {
        // 아이콘 칸에는 번호만(`27` · `4-A` · `4+` · `Q-0`) — 「단계」 말은 제목에 이미 있다.
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
        return '<a class="card card--link" href="' . self::esc(self::href($book, (string) $entry['slug'])) . '">'
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
