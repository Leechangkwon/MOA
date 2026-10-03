<?php
declare(strict_types=1);
require_once __DIR__ . '/DbViews.php';

/**
 * src/community/DbProps.php — 데이터베이스(6-A) 속성의 **모양·값·거르기·정렬**. 순수 함수만 둔다(DB·요청 없음).
 *
 * 요구사항: 커뮤니티 PRD F-27 · 11장 6행 T-28(필터 인젝션), 지시서 `stage6a-database-directive.md` 「속성」·「거르기·정렬」.
 *
 * 스키마 한 줄 = `{key, name, type, options?}`
 *   key     서버가 만든다(`p` + 8 hex). 요청이 정하지 않는다 — 속성 이름이 SQL·JSON 경로에 섞이지 않게.
 *   name    화면 이름 1~50자(제어 문자 금지). 같은 DB 안에서 대소문자 무시 하나뿐. 기본 열 이름(제목·상태·소유자)은 못 쓴다
 *   type    TYPES 열 가지(+ 기본 열 셋: 제목 = 페이지 제목 · 상태 = pages.status · 소유자 = 공간 주인, 읽기만)
 *   options select·multi_select 의 선택지 1~50개(각 1~50자, 겹치면 안 됨)
 *
 * 값 = 행 페이지의 `properties` 객체 `{key: 값}`. 「값 없음」은 키가 없는 것이다(빈 글자·빈 목록은 지운다).
 *
 * ★ 거르기·정렬은 **허용 목록 → 고정 SQL 조각**이다(T-28). 속성 key 도 값도 전부 바인딩 값이고,
 *   SQL 글자에 들어가는 것은 이 파일에 적힌 조각뿐이다. 모르는 속성·연산자·형식은 InvalidArgumentException → 400.
 */
final class DbProps
{
    public const TYPES = ['text', 'number', 'checkbox', 'url', 'email', 'phone', 'date', 'select', 'multi_select', 'person', 'relation', 'file'];
    /** 스키마에 없는, 모든 DB 에 있는 기본 열 — 거르기·정렬에서 이 이름으로 부른다 */
    public const BUILTINS = ['title', 'status', 'owner', 'created', 'updated', 'due', 'verified'];
    public const RESERVED_NAMES = ['제목', '상태', '소유자', '만든 때', '고친 때', '기한', '검증'];

    public const MAX_PROPS   = 30;
    public const MAX_OPTIONS = 50;
    public const MAX_MULTI   = 20;
    public const TEXT_MAX    = 2000;
    public const NAME_MAX    = 50;
    public const MAX_ROWS    = 5000;
    public const MAX_FILTERS = 10;
    public const MAX_SORTS   = 3;
    /** 6-B */
    public const MAX_RELATION = 20;
    public const MAX_FILES    = 10;
    public const MAX_VIEWS    = 20;
    public const VIEW_TYPES   = ['table', 'board', 'list', 'form', 'calendar', 'timeline', 'chart'];
    private const UUID_RE     = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    private const KEY_RE  = '/^p[0-9a-f]{8}$/';
    private const DATE_RE = '/^(\d{4})-(\d{2})-(\d{2})(T([01]\d|2[0-3]):[0-5]\d)?$/';
    /** 정렬 머리 — ORDER BY 글자는 여기 한 줄에만 있다(security_lint S-04: 키워드와 변수를 한 줄에 두지 않는다) */
    public const ORDER_HEAD = ' ORDER BY ';

    /** 새 속성의 key — 서버가 만든다 */
    public static function newKey(): string
    {
        return 'p' . bin2hex(random_bytes(4));
    }

    /** 이 스키마에서 key 로 속성 한 줄을 찾는다(없으면 null) */
    public static function find(array $schema, string $key): ?array
    {
        foreach ($schema as $p) {
            if (is_array($p) && ($p['key'] ?? null) === $key) {
                return $p;
            }
        }
        return null;
    }

    /**
     * 속성 이름 — 앞뒤 공백을 떼고 1~50자, 제어 문자 없음. 틀리면 null.
     */
    public static function cleanName($raw): ?string
    {
        if (!is_string($raw) || preg_match('//u', $raw) !== 1) {
            return null;
        }
        $name = trim($raw);
        if ($name === '' || iconv_strlen($name, 'UTF-8') > self::NAME_MAX || preg_match('/\p{C}/u', $name) === 1) {
            return null;
        }
        return $name;
    }

    /** 이 이름을 이 스키마에 쓸 수 있나 — 기본 열 이름·이미 있는 이름(대소문자 무시, 자기 자신 제외) 금지 */
    public static function nameFree(array $schema, string $name, ?string $exceptKey = null): bool
    {
        $low = self::lower($name);
        foreach (self::RESERVED_NAMES as $r) {
            if (self::lower($r) === $low) {
                return false;
            }
        }
        foreach ($schema as $p) {
            if (is_array($p) && ($p['key'] ?? null) !== $exceptKey && self::lower((string) ($p['name'] ?? '')) === $low) {
                return false;
            }
        }
        return true;
    }

    /**
     * 선택지 목록 — 문자열 1~50개, 각 1~50자, 제어 문자 없음, 겹치지 않음. 틀리면 null.
     *
     * @return list<string>|null
     */
    public static function cleanOptions($raw): ?array
    {
        if (!is_array($raw) || !array_is_list($raw) || $raw === [] || count($raw) > self::MAX_OPTIONS) {
            return null;
        }
        $out  = [];
        $seen = [];
        foreach ($raw as $o) {
            $name = self::cleanName($o);
            if ($name === null || isset($seen[self::lower($name)])) {
                return null;
            }
            $seen[self::lower($name)] = true;
            $out[] = $name;
        }
        return $out;
    }

    /**
     * 값 하나를 검사하고 저장할 모양으로 바꾼다.
     *
     * @return array{0:bool,1:mixed} [맞나, 저장할 값(null = 키를 지운다)]
     */
    public static function cleanValue(array $prop, $value): array
    {
        if ($value === null) {
            return [true, null];
        }
        $type = (string) ($prop['type'] ?? '');
        switch ($type) {
            case 'text':
                if (!is_string($value) || preg_match('//u', $value) !== 1
                    || iconv_strlen($value, 'UTF-8') > self::TEXT_MAX
                    // 줄바꿈만 허용하고 나머지 제어·서식 문자는 거부
                    || preg_match('/[^\P{C}\n]/u', $value) === 1) {
                    return [false, null];
                }
                return [true, trim($value) === '' ? null : $value];

            case 'number':
                if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || abs((float) $value) > 1e15) {
                    return [false, null];
                }
                return [true, $value];

            case 'checkbox':
                return is_bool($value) ? [true, $value] : [false, null];

            case 'url':
                if (!is_string($value) || strlen($value) > 2000
                    || preg_match('#^https?://[^\s/?\#]+[^\s]*$#i', $value) !== 1
                    || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
                    return [false, null];
                }
                return [true, $value];

            case 'email':
                if (!is_string($value) || strlen($value) > 254 || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                    return [false, null];
                }
                return [true, $value];

            case 'phone':
                if (!is_string($value) || preg_match('/^[0-9+\-() .]{1,30}$/', $value) !== 1 || preg_match('/\d/', $value) !== 1) {
                    return [false, null];
                }
                return [true, $value];

            case 'date':
                if (!is_array($value) || array_diff(array_keys($value), ['start', 'end']) !== [] || !isset($value['start'])) {
                    return [false, null];
                }
                $start = self::cleanDate($value['start']);
                $end   = array_key_exists('end', $value) && $value['end'] !== null ? self::cleanDate($value['end']) : null;
                if ($start === null || (array_key_exists('end', $value) && $value['end'] !== null && $end === null)
                    || ($end !== null && strcmp($end, $start) < 0)) {
                    return [false, null];
                }
                return [true, $end === null ? ['start' => $start] : ['start' => $start, 'end' => $end]];

            case 'select':
                if (!is_string($value) || !in_array($value, (array) ($prop['options'] ?? []), true)) {
                    return [false, null];
                }
                return [true, $value];

            case 'multi_select':
                if (!is_array($value) || !array_is_list($value) || count($value) > self::MAX_MULTI) {
                    return [false, null];
                }
                $opts = (array) ($prop['options'] ?? []);
                $out  = [];
                foreach ($value as $v) {
                    if (!is_string($v) || !in_array($v, $opts, true) || in_array($v, $out, true)) {
                        return [false, null];
                    }
                    $out[] = $v;
                }
                return [true, $out === [] ? null : $out];

            case 'relation':
                // 행 id(UUID) 목록 ≤20 — 그 행이 대상 DB 의 행이고 고치는 사람이 볼 수 있는지는 핸들러가 본다(6-B)
                if (!is_array($value) || !array_is_list($value) || count($value) > self::MAX_RELATION) {
                    return [false, null];
                }
                $out = [];
                foreach ($value as $v) {
                    if (!is_string($v) || preg_match(self::UUID_RE, $v) !== 1 || in_array($v, $out, true)) {
                        return [false, null];
                    }
                    $out[] = $v;
                }
                return [true, $out === [] ? null : $out];

            case 'file':
                // `tbox:UUID` 목록 ≤10 — 볼 수 있고 행을 읽는 범위보다 좁지 않은지는 핸들러가 5-E 규칙으로 본다
                if (!is_array($value) || !array_is_list($value) || count($value) > self::MAX_FILES) {
                    return [false, null];
                }
                $out = [];
                foreach ($value as $v) {
                    if (!is_string($v) || strncmp($v, 'tbox:', 5) !== 0 || preg_match(self::UUID_RE, substr($v, 5)) !== 1
                        || in_array($v, $out, true)) {
                        return [false, null];
                    }
                    $out[] = $v;
                }
                return [true, $out === [] ? null : $out];

            case 'person':
                // 계정명 모양만 본다 — 그 사람이 있고 이 행을 볼 수 있는지는 핸들러가 판정한다(4-D 담당자와 같은 규칙).
                if (!is_string($value) || preg_match('/^[A-Za-z0-9_.\-]{1,64}$/', $value) !== 1) {
                    return [false, null];
                }
                return [true, $value];
        }
        return [false, null];
    }

    /** 처리 이력·감사에 남길 사람 말 — 긴 글은 100자에서 자른다 */
    public static function display(array $prop, $value): ?string
    {
        if ($value === null) {
            return null;
        }
        switch ((string) ($prop['type'] ?? '')) {
            case 'checkbox':
                return $value === true ? '예' : '아니오';
            case 'date':
                return (string) ($value['start'] ?? '') . (isset($value['end']) ? ' ~ ' . (string) $value['end'] : '');
            case 'multi_select':
                return implode(', ', array_map('strval', (array) $value));
            case 'relation':
                // 행 제목·id 는 이력에 남기지 않는다(나중에 못 보게 된 사람에게 새지 않게) — 개수만
                return '행 ' . count((array) $value) . '개';
            case 'file':
                return '파일 ' . count((array) $value) . '개';
            case 'number':
                return is_float($value) ? rtrim(rtrim(sprintf('%.6F', $value), '0'), '.') : (string) $value;
        }
        $s = (string) $value;
        return iconv_strlen($s, 'UTF-8') > 100 ? iconv_substr($s, 0, 100, 'UTF-8') . '…' : $s;
    }

    /* ───────────── 거르기 (T-28) ───────────── */

    /** 종류별 허용 연산자 */
    private const OPS = [
        'text'         => ['contains', 'not_contains', 'eq', 'empty', 'not_empty'],
        'url'          => ['contains', 'eq', 'empty', 'not_empty'],
        'email'        => ['contains', 'eq', 'empty', 'not_empty'],
        'phone'        => ['contains', 'eq', 'empty', 'not_empty'],
        'number'       => ['eq', 'ne', 'lt', 'le', 'gt', 'ge', 'empty', 'not_empty'],
        'checkbox'     => ['is'],
        'date'         => ['on', 'before', 'after', 'empty', 'not_empty'],
        'select'       => ['eq', 'ne', 'empty', 'not_empty'],
        'multi_select' => ['has', 'not_has', 'empty', 'not_empty'],
        'person'       => ['eq', 'empty', 'not_empty'],
        'relation'     => ['has', 'empty', 'not_empty'],
        'file'         => ['empty', 'not_empty'],
        // 기본 열
        'title'        => ['contains', 'not_contains', 'eq'],
        'status'       => ['eq', 'ne', 'empty', 'not_empty'],
        'owner'        => ['eq'],
        'created'      => ['before', 'after', 'on'],
        'updated'      => ['before', 'after', 'on'],
        'due'          => ['before', 'after', 'on', 'empty', 'not_empty'],
        'verified'     => ['eq', 'ne'],
    ];

    /**
     * 고정 조각. `{k}` 는 속성 key 자리표시자, `{v}` 는 값 자리표시자로 바뀐다 — 둘 다 **바인딩 이름**이다.
     * 값 비교는 언제나 CAST 로 형을 고정한다(서버 prepare 에서 형 추론이 흔들리지 않게).
     */
    private const FRAG = [
        'due.before' => "p.due_date < CAST({v} AS date)",
        'due.after' => "p.due_date > CAST({v} AS date)",
        'due.on' => "p.due_date = CAST({v} AS date)",
        'due.empty' => "p.due_date IS NULL",
        'due.not_empty' => "p.due_date IS NOT NULL",
        'verified.eq' => "(CASE WHEN p.verified_at IS NULL THEN 'none' WHEN p.verify_expires_at <= now() THEN 'expired' ELSE 'verified' END) = CAST({v} AS text)",
        'verified.ne' => "(CASE WHEN p.verified_at IS NULL THEN 'none' WHEN p.verify_expires_at <= now() THEN 'expired' ELSE 'verified' END) <> CAST({v} AS text)",
        'text.contains'      => "strpos(lower(coalesce(p.properties->>CAST({k} AS text), '')), lower(CAST({v} AS text))) > 0",
        'text.not_contains'  => "strpos(lower(coalesce(p.properties->>CAST({k} AS text), '')), lower(CAST({v} AS text))) = 0",
        'text.eq'            => "p.properties->>CAST({k} AS text) = CAST({v} AS text)",
        'any.empty'          => "(p.properties->CAST({k} AS text)) IS NULL",
        'any.not_empty'      => "(p.properties->CAST({k} AS text)) IS NOT NULL",
        'number.eq'          => "(CASE WHEN jsonb_typeof(p.properties->CAST({k} AS text)) = 'number' THEN (p.properties->>CAST({k} AS text))::numeric END) = CAST({v} AS numeric)",
        'number.ne'          => "(CASE WHEN jsonb_typeof(p.properties->CAST({k} AS text)) = 'number' THEN (p.properties->>CAST({k} AS text))::numeric END) IS DISTINCT FROM CAST({v} AS numeric)",
        'number.lt'          => "(CASE WHEN jsonb_typeof(p.properties->CAST({k} AS text)) = 'number' THEN (p.properties->>CAST({k} AS text))::numeric END) < CAST({v} AS numeric)",
        'number.le'          => "(CASE WHEN jsonb_typeof(p.properties->CAST({k} AS text)) = 'number' THEN (p.properties->>CAST({k} AS text))::numeric END) <= CAST({v} AS numeric)",
        'number.gt'          => "(CASE WHEN jsonb_typeof(p.properties->CAST({k} AS text)) = 'number' THEN (p.properties->>CAST({k} AS text))::numeric END) > CAST({v} AS numeric)",
        'number.ge'          => "(CASE WHEN jsonb_typeof(p.properties->CAST({k} AS text)) = 'number' THEN (p.properties->>CAST({k} AS text))::numeric END) >= CAST({v} AS numeric)",
        'checkbox.is'        => "coalesce(p.properties->>CAST({k} AS text), 'false') = CAST({v} AS text)",
        'date.on'            => "left(p.properties->CAST({k} AS text)->>'start', 10) = CAST({v} AS text)",
        'date.before'        => "left(p.properties->CAST({k} AS text)->>'start', 10) < CAST({v} AS text)",
        'date.after'         => "left(p.properties->CAST({k} AS text)->>'start', 10) > CAST({v} AS text)",
        'select.eq'          => "p.properties->>CAST({k} AS text) = CAST({v} AS text)",
        'select.ne'          => "coalesce(p.properties->>CAST({k} AS text), '') <> CAST({v} AS text)",
        'multi_select.has'   => "jsonb_exists(coalesce(p.properties->CAST({k} AS text), '[]'::jsonb), CAST({v} AS text))",
        'multi_select.not_has' => "NOT jsonb_exists(coalesce(p.properties->CAST({k} AS text), '[]'::jsonb), CAST({v} AS text))",
        'relation.has'       => "jsonb_exists(coalesce(p.properties->CAST({k} AS text), '[]'::jsonb), CAST({v} AS text))",
        'person.eq'          => "p.properties->>CAST({k} AS text) = CAST({v} AS text)",
        'title.contains'     => "strpos(lower(p.title), lower(CAST({v} AS text))) > 0",
        'title.not_contains' => "strpos(lower(p.title), lower(CAST({v} AS text))) = 0",
        'title.eq'           => "p.title = CAST({v} AS text)",
        'status.eq'          => "p.status = CAST({v} AS text)",
        'status.ne'          => "p.status IS DISTINCT FROM CAST({v} AS text)",
        'status.empty'       => "p.status IS NULL",
        'status.not_empty'   => "p.status IS NOT NULL",
        'owner.eq'           => "owner.username = CAST({v} AS text)",
        'created.before'     => "p.created_at < CAST({v} AS date)",
        'created.after'      => "p.created_at >= CAST({v} AS date) + 1",
        'created.on'         => "CAST(p.created_at AS date) = CAST({v} AS date)",
        'updated.before'     => "p.updated_at < CAST({v} AS date)",
        'updated.after'      => "p.updated_at >= CAST({v} AS date) + 1",
        'updated.on'         => "CAST(p.updated_at AS date) = CAST({v} AS date)",
    ];

    /**
     * 거르기 JSON → SQL 조건 한 줄(`AND` 로 이어 붙인 것) + 바인딩.
     * 모양: `{"and":[{"prop":…, "op":…, "value":…}]}` 한 겹, 조건 ≤ 10. 빈 것은 `TRUE`.
     *
     * @return array{0:string,1:array<string,mixed>}
     * @throws InvalidArgumentException 모르는 모양·속성·연산자·값
     */
    public static function compileFilter($filter, array $schema): array
    {
        if ($filter === null || $filter === []) {
            return ['TRUE', []];
        }
        if (!is_array($filter) || array_keys($filter) !== ['and'] || !is_array($filter['and'])
            || !array_is_list($filter['and']) || count($filter['and']) > self::MAX_FILTERS) {
            throw new InvalidArgumentException('filter shape');
        }
        $parts = [];
        $bind  = [];
        foreach ($filter['and'] as $i => $c) {
            if (!is_array($c) || array_diff(array_keys($c), ['prop', 'op', 'value']) !== []
                || !is_string($c['prop'] ?? null) || !is_string($c['op'] ?? null)) {
                throw new InvalidArgumentException('condition shape');
            }
            [$kind, $prop] = self::kindOf($c['prop'], $schema);
            $op = $c['op'];
            if (!in_array($op, self::OPS[$kind] ?? [], true)) {
                throw new InvalidArgumentException('op');
            }
            $fragKey = isset(self::FRAG[$kind . '.' . $op]) ? $kind . '.' . $op
                : (in_array($op, ['empty', 'not_empty'], true) ? 'any.' . $op
                : (in_array($kind, ['url', 'email', 'phone'], true) ? 'text.' . $op : null));
            if ($fragKey === null) {
                throw new InvalidArgumentException('op');
            }
            $kName = ':fk' . $i;
            $vName = ':fv' . $i;
            $frag  = strtr(self::FRAG[$fragKey], ['{k}' => $kName, '{v}' => $vName]);
            if (strpos($frag, $kName) !== false) {
                $bind[$kName] = (string) $c['prop'];
            }
            if (strpos($frag, $vName) !== false) {
                $bind[$vName] = self::filterValue($kind, $op, $prop, $c['value'] ?? null);
            }
            $parts[] = '(' . $frag . ')';
        }
        return [$parts === [] ? 'TRUE' : implode(' AND ', $parts), $bind];
    }

    /** 거르기 값 — 종류마다 모양이 맞아야 한다. 바인딩에 넣을 글자로 돌려준다 */
    private static function filterValue(string $kind, string $op, ?array $prop, $v): string
    {
        switch ($kind) {
            case 'number':
                if ((!is_int($v) && !is_float($v)) || !is_finite((float) $v)) {
                    throw new InvalidArgumentException('value');
                }
                return (string) $v;
            case 'checkbox':
                if (!is_bool($v)) {
                    throw new InvalidArgumentException('value');
                }
                return $v ? 'true' : 'false';
            case 'date':
            case 'due':
            case 'created':
            case 'updated':
                $d = is_string($v) ? self::cleanDate($v) : null;
                if ($d === null || strlen($d) !== 10) {
                    throw new InvalidArgumentException('value');
                }
                return $d;
            case 'verified':
                if (!is_string($v) || !in_array($v, ['none', 'expired', 'verified'], true)) { throw new InvalidArgumentException('value'); }
                return $v;
            case 'status':
                if (!is_string($v) || !in_array($v, COMMUNITY_STATUSES, true)) {
                    throw new InvalidArgumentException('value');
                }
                return $v;
            case 'relation':
                if (!is_string($v) || preg_match(self::UUID_RE, $v) !== 1) {
                    throw new InvalidArgumentException('value');
                }
                return $v;
            case 'select':
            case 'multi_select':
                if (!is_string($v) || !in_array($v, (array) ($prop['options'] ?? []), true)) {
                    throw new InvalidArgumentException('value');
                }
                return $v;
        }
        // 글자 종류(제목·글·주소·메일·전화·사람)
        if (!is_string($v) || $v === '' || iconv_strlen($v, 'UTF-8') > 200 || preg_match('/\p{C}/u', $v) === 1) {
            throw new InvalidArgumentException('value');
        }
        return $v;
    }

    /* ───────────── 정렬 ───────────── */

    private const SORT_FRAG = [
        'due' => 'p.due_date',
        'verified' => "CASE WHEN p.verified_at IS NULL THEN 'none' WHEN p.verify_expires_at <= now() THEN 'expired' ELSE 'verified' END",
        'text'     => "lower(p.properties->>CAST({k} AS text))",
        'number'   => "(CASE WHEN jsonb_typeof(p.properties->CAST({k} AS text)) = 'number' THEN (p.properties->>CAST({k} AS text))::numeric END)",
        'checkbox' => "coalesce(p.properties->>CAST({k} AS text), 'false')",
        'date'     => "(p.properties->CAST({k} AS text)->>'start')",
        'title'    => "lower(p.title)",
        'status'   => "array_position(ARRAY['todo','doing','review','done','hold']::text[], p.status)",
        'owner'    => "owner.username",
        'created'  => "p.created_at",
        'updated'  => "p.updated_at",
    ];

    /**
     * 정렬 JSON `[{"prop":…, "dir":"asc"|"desc"}]`(≤3) → `ORDER BY …` 조각 + 바인딩. 빈 것은 행 순서(position).
     *
     * @return array{0:string,1:array<string,mixed>}
     */
    public static function compileSort($sort, array $schema): array
    {
        $parts = [];
        $bind  = [];
        if ($sort !== null && $sort !== []) {
            if (!is_array($sort) || !array_is_list($sort) || count($sort) > self::MAX_SORTS) {
                throw new InvalidArgumentException('sort shape');
            }
            foreach ($sort as $i => $s) {
                if (!is_array($s) || array_diff(array_keys($s), ['prop', 'dir']) !== [] || !is_string($s['prop'] ?? null)
                    || !in_array($s['dir'] ?? 'asc', ['asc', 'desc'], true)) {
                    throw new InvalidArgumentException('sort');
                }
                [$kind] = self::kindOf($s['prop'], $schema);
                $fragKind = isset(self::SORT_FRAG[$kind]) ? $kind
                    : (in_array($kind, ['url', 'email', 'phone', 'select', 'person', 'multi_select'], true) ? 'text' : null);
                if ($fragKind === null) {
                    throw new InvalidArgumentException('sort');
                }
                $kName = ':sk' . $i;
                $frag  = str_replace('{k}', $kName, self::SORT_FRAG[$fragKind]);
                if (strpos($frag, $kName) !== false) {
                    $bind[$kName] = (string) $s['prop'];
                }
                $parts[] = $frag . (($s['dir'] ?? 'asc') === 'desc' ? ' DESC NULLS LAST' : ' ASC NULLS LAST');
            }
        }
        $parts[] = 'p.position';
        $parts[] = 'p.id';
        return [self::ORDER_HEAD . implode(', ', $parts), $bind];
    }

    /**
     * 거르기·정렬이 부른 이름 → [종류, 속성 한 줄|null]. 기본 열이거나 스키마의 key 여야 한다.
     *
     * @return array{0:string,1:?array}
     */
    private static function kindOf(string $name, array $schema): array
    {
        if (in_array($name, self::BUILTINS, true)) {
            return [$name, null];
        }
        if (preg_match(self::KEY_RE, $name) !== 1) {
            throw new InvalidArgumentException('prop');
        }
        $p = self::find($schema, $name);
        if ($p === null) {
            throw new InvalidArgumentException('prop');
        }
        return [(string) $p['type'], $p];
    }

    /** 스키마 한 줄이 이 파일이 아는 모양인가 (저장 전 마지막 확인) */
    public static function propShapeOk($p): bool
    {
        if (!is_array($p) || preg_match(self::KEY_RE, (string) ($p['key'] ?? '')) !== 1
            || self::cleanName($p['name'] ?? null) === null || !in_array($p['type'] ?? null, self::TYPES, true)) {
            return false;
        }
        $needsOptions = in_array($p['type'], ['select', 'multi_select'], true);
        if ($needsOptions !== array_key_exists('options', $p)) {
            return false;
        }
        // 6-B 관계는 대상 DB(UUID)를 가진다 — 다른 종류는 가지지 않는다
        $isRel = $p['type'] === 'relation';
        if ($isRel !== array_key_exists('target', $p) || ($isRel && preg_match(self::UUID_RE, (string) $p['target']) !== 1)) {
            return false;
        }
        return !$needsOptions || self::cleanOptions($p['options']) !== null;
    }

    /* ───────────── 6-B 뷰 ───────────── */

    public static function newViewId(): string
    {
        return 'v' . bin2hex(random_bytes(4));
    }

    /**
     * 뷰 한 줄 `{name, type, filter?, sort?, hidden?}` 을 검사한다 — 거르기·정렬은 **지금 스키마로 컴파일이 되는지**까지 본다.
     * 틀리면 null. `id` 는 부르는 쪽이 붙인다.
     *
     * @return array<string,mixed>|null
     */
    public static function cleanView($raw, array $schema): ?array
    {
        if (!is_array($raw) || array_diff(array_keys($raw), DbViews::viewFields((string) ($raw['type'] ?? ''))) !== []) {
            return null;
        }
        $name = self::cleanName($raw['name'] ?? null);
        if ($name === null || !in_array($raw['type'] ?? null, self::VIEW_TYPES, true)) {
            return null;
        }
        $extra = DbViews::cleanViewOptions($raw, $schema);
        if ($extra === null) { return null; }
        $out = ['name' => $name, 'type' => $raw['type']] + $extra;
        try {
            /* 검사를 지난 모양으로 **다시 만들어** 저장한다(독립 검토 6-B M-1) — 값이 필요 없는 연산자(empty 등)에 딸려 온
               `value` 나 모르는 덩어리가 뷰에 쌓여 모든 독자의 응답을 부풀리지 않게. 값은 filterValue 가 받은 스칼라뿐이다. */
            if (isset($raw['filter']) && $raw['filter'] !== null && $raw['filter'] !== []) {
                self::compileFilter($raw['filter'], $schema);
                $conds = [];
                foreach ($raw['filter']['and'] as $c) {
                    $one = ['prop' => (string) $c['prop'], 'op' => (string) $c['op']];
                    if (!in_array($c['op'], ['empty', 'not_empty'], true) && array_key_exists('value', $c) && is_scalar($c['value'])) {
                        $one['value'] = $c['value'];
                    }
                    $conds[] = $one;
                }
                $out['filter'] = ['and' => $conds];
            }
            if (isset($raw['sort']) && $raw['sort'] !== null && $raw['sort'] !== []) {
                self::compileSort($raw['sort'], $schema);
                $out['sort'] = array_map(static fn (array $s): array => ['prop' => (string) $s['prop'], 'dir' => (string) ($s['dir'] ?? 'asc')], $raw['sort']);
            }
        } catch (InvalidArgumentException $e) {
            return null;
        }
        if (isset($raw['hidden'])) {
            if (!is_array($raw['hidden']) || !array_is_list($raw['hidden']) || count($raw['hidden']) > self::MAX_PROPS) {
                return null;
            }
            foreach ($raw['hidden'] as $k) {
                if (!is_string($k) || self::find($schema, $k) === null) {
                    return null;
                }
            }
            $out['hidden'] = array_values(array_unique($raw['hidden']));
        }
        // 뷰 하나는 16KB 까지(이름 50자 · 조건 10 · 정렬 3 · 숨김 30 이면 넉넉하다)
        if (strlen((string) json_encode($out, JSON_UNESCAPED_UNICODE)) > 16384) {
            return null;
        }
        return $out;
    }

    /* ───────────── 6-B 계산 (T-29 — 보이는 행만) ───────────── */

    /** 계산 조각 — `{k}` 속성 key 바인딩, `{a}` 결과 이름(숫자 번호로만 만든다) */
    private const AGG = [
        'filled'  => "count(p.properties->CAST({k} AS text)) AS {a}_filled",
        'sum'     => "sum(CASE WHEN jsonb_typeof(p.properties->CAST({k} AS text)) = 'number' THEN (p.properties->>CAST({k} AS text))::numeric END) AS {a}_sum",
        'avg'     => "avg(CASE WHEN jsonb_typeof(p.properties->CAST({k} AS text)) = 'number' THEN (p.properties->>CAST({k} AS text))::numeric END) AS {a}_avg",
        'checked' => "count(*) FILTER (WHERE p.properties->>CAST({k} AS text) = 'true') AS {a}_checked",
        'next'    => "min(left(p.properties->CAST({k} AS text)->>'start', 10)) FILTER (WHERE left(p.properties->CAST({k} AS text)->>'start', 10) >= CAST(:today AS text)) AS {a}_next",
    ];

    /**
     * 스키마 → 계산 SELECT 목록 조각 + 바인딩 + 결과 읽는 법. 결과는 **수**뿐이다(행 제목·값 목록 없음).
     *
     * @return array{0:string,1:array<string,mixed>,2:list<array{key:string,alias:string,kinds:list<string>}>}
     */
    public static function compileSummary(array $schema): array
    {
        $parts = [];
        $bind  = [];
        $meta  = [];
        foreach (array_values($schema) as $i => $p) {
            $kinds = ['filled'];
            if ($p['type'] === 'number') {
                $kinds[] = 'sum';
                $kinds[] = 'avg';
            } elseif ($p['type'] === 'checkbox') {
                $kinds = ['checked'];
            } elseif ($p['type'] === 'date') {
                $kinds[] = 'next';
            }
            $kName = ':ak' . $i;
            $alias = 'a' . $i;
            foreach ($kinds as $kind) {
                $parts[] = strtr(self::AGG[$kind], ['{k}' => $kName, '{a}' => $alias]);
            }
            $bind[$kName] = (string) $p['key'];
            $meta[]       = ['key' => (string) $p['key'], 'alias' => $alias, 'kinds' => $kinds];
        }
        return [$parts === [] ? '' : ', ' . implode(', ', $parts), $bind, $meta];
    }

    /** `YYYY-MM-DD` 또는 `YYYY-MM-DDTHH:MM` — 달력에 있는 날만 */
    private static function cleanDate($v): ?string
    {
        if (!is_string($v) || preg_match(self::DATE_RE, $v, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        return $v;
    }

    private static function lower(string $s): string
    {
        // mbstring 이 없다 — 한글은 대소문자가 없으므로 ASCII 만 낮춘다
        return strtolower($s);
    }
}
