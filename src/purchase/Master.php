<?php
declare(strict_types=1);

/**
 * src/purchase/Master.php — 기초정보 6종의 **공통 저장 로직** (19단계, PRD F-28, ARCH 5.18)
 *
 * 왜 한 파일인가
 *   브랜드·지점·장소·거래처·분류·인가량은 화면도 표도 다르지만 규칙이 **똑같다**:
 *   ① 허용 키 목록 밖의 키가 하나라도 오면 400(대량 할당 차단, SECURITY R-07)
 *   ② 값은 전부 바인딩, 컬럼 이름은 코드에 적힌 고정 문자열 (PRD S-04)
 *   ③ **삭제가 없다** — 그만 쓰는 것은 `is_active = false` (지시서 보충 4, db/grants.sql)
 *   ④ 바뀐 것은 before/after 스냅샷으로 감사 로그에 남는다 (PRD F-28 "변경은 before/after 감사")
 *   여섯 벌로 흩어 두면 언젠가 한 벌만 고쳐지고, 그 한 벌이 곧 우회 경로다.
 *
 * 이 파일이 하지 **않는** 것
 *   · 권한 판단 — 라우트 정책(`purchase.read` · `purchase.master.write`)과 재인증 관문이 이미
 *     지났고, 지점 격리는 `Authz::purchaseScopeWhere` 가 조회 SQL 에서 한다 (ARCH 4.1).
 *     이 파일에는 역할·purchase_role·branch_id 를 보는 코드가 한 글자도 없다.
 *   · 감사 기록 — 스냅샷만 돌려주고 `Audit::allow` 는 핸들러가 부른다(기록 실패 = 요청 실패를
 *     트랜잭션과 함께 다루는 곳이 핸들러이기 때문).
 *   · HTTP 응답 — 실패는 예외로 알리고 예외 메시지는 **감사 로그용 사유 코드**다
 *     (`Upload` 와 같은 방식). 사용자에게 보이는 것은 400 동일 문구 하나뿐이다.
 */
final class Master
{
    /**
     * 종류 → 표 이름. 값은 **코드에 적힌 고정 문자열**이며 요청 값이 이 자리에 닿지 않는다.
     * 라우트 표(public/index.php)가 경로마다 종류를 정하고, 핸들러는 그 고정 키만 넘긴다.
     *
     * @var array<string,string>
     */
    public const TABLES = [
        'brands'     => 'p_brands',
        'branches'   => 'p_branches',
        'locations'  => 'p_locations',
        'vendors'    => 'p_vendors',
        'categories' => 'p_categories',
        'par-levels' => 'p_par_levels',
    ];

    /**
     * 종류 → **만들기/고치기에 받을 수 있는 키**. 여기 없는 키가 오면 요청 전체를 400 으로
     * 거부한다(부분 무시가 아니다 — 무시하면 "보냈는데 안 바뀐" 상태를 사람이 모른다).
     * `is_active` 는 고치기에서만 받는다(아래 updatableKeys).
     *
     * @var array<string,list<string>>
     */
    private const FIELDS = [
        // 브랜드는 **회사 정보**를 겸한다 (사용자 Q-18 확정 2026-09-13, ARCH 5.18):
        // 20단계 발주서 PDF 의 머리 정보가 이 다섯 칸에서 나온다. 회사 정보이므로 개인정보가
        // 아니고, 바뀐 값이 감사 detail.before/after 에 그대로 남아도 된다.
        // 엑셀 가져오기 대상이 아니다 — 화면에서만 고친다(Importer::TARGETS 에 없다).
        'brands'     => ['name', 'biz_no', 'ceo_name', 'address', 'tel', 'logo_file_id'],
        'branches'   => ['brand_id', 'name', 'warehouse_location_id', 'central_location_id'],
        'locations'  => ['branch_id', 'kind', 'name', 'code', 'legacy_name'],
        'vendors'    => ['code', 'name', 'biz_no'],
        'categories' => ['parent_id', 'name', 'level'],
        'par-levels' => ['location_id', 'item_id', 'qty', 'effective_from'],
    ];

    /** 장소의 용도 4종 (db/schema.sql p_locations kind CHECK 와 같은 값) */
    public const LOCATION_KINDS = ['warehouse', 'central', 'or', 'other'];

    /** 종류 이름이 우리가 아는 것인가 */
    public static function isKind(string $kind): bool
    {
        return array_key_exists($kind, self::TABLES);
    }

    /** @return list<string> 만들기에 받을 수 있는 키 */
    public static function createKeys(string $kind): array
    {
        return self::FIELDS[$kind] ?? [];
    }

    /** @return list<string> 고치기에 받을 수 있는 키 — 만들기 키 + is_active(중단·재개) */
    public static function updatableKeys(string $kind): array
    {
        return array_merge(self::FIELDS[$kind] ?? [], ['is_active']);
    }

    /* ══════════════════════════════════════════════════════════════════
     * 값 좁히기 — 형식이 맞지 않으면 예외(사유 코드)로 끝낸다.
     * 좁힌 결과는 **PHP 값**이고, SQL 에는 바인딩으로만 들어간다.
     * ══════════════════════════════════════════════════════════════════ */

    /**
     * 이름류 문자열 — trim 뒤 1..$max 글자, 제어·서식 문자 금지 (folders_create 와 같은 규칙).
     */
    public static function name(mixed $raw, int $max): string
    {
        if (!is_string($raw) || preg_match('//u', $raw) !== 1) {
            throw new RuntimeException('bad_request');
        }
        $value = trim($raw);
        if ($value === '' || iconv_strlen($value, 'UTF-8') > $max || preg_match('/\p{C}/u', $value) === 1) {
            throw new RuntimeException('bad_request');
        }
        return $value;
    }

    /** 선택 문자열 — 없거나 빈 값이면 null. 있으면 name() 과 같은 규칙 */
    public static function optionalText(mixed $raw, int $max): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        return self::name($raw, $max);
    }

    /** 양의 정수 id (문자열로 와도 받는다 — JSON 은 숫자를 문자열로 보내는 화면이 흔하다) */
    public static function id(mixed $raw): int
    {
        $text = is_int($raw) ? (string) $raw : (is_string($raw) ? $raw : '');
        if (preg_match('/^[1-9][0-9]{0,8}$/', $text) !== 1) {
            throw new RuntimeException('bad_request');
        }
        return (int) $text;
    }

    /** 선택 id — 없거나 null 이면 null */
    public static function optionalId(mixed $raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        return self::id($raw);
    }

    /** 0 이상 정수 (수량·안전재고 등) */
    public static function count(mixed $raw, int $min, int $max): int
    {
        $text = is_int($raw) ? (string) $raw : (is_string($raw) ? trim($raw) : '');
        if (preg_match('/^[0-9]{1,9}$/', $text) !== 1) {
            throw new RuntimeException('bad_request');
        }
        $value = (int) $text;
        if ($value < $min || $value > $max) {
            throw new RuntimeException('bad_request');
        }
        return $value;
    }

    /** `YYYY-MM-DD` 이고 달력에 실제로 있는 날 (users_update 의 validMoment 와 같은 규칙) */
    public static function date(mixed $raw): string
    {
        if (!is_string($raw)
            || preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/', $raw, $m) !== 1
            || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])
        ) {
            throw new RuntimeException('bad_request');
        }
        return $raw;
    }

    /** 허용 목록 정확 일치 (장소 용도 등) */
    public static function choice(mixed $raw, array $allowed): string
    {
        if (!is_string($raw) || !in_array($raw, $allowed, true)) {
            throw new RuntimeException('bad_request');
        }
        return $raw;
    }

    /** 참/거짓 — JSON 의 true/false 만 받는다(문자열 "false" 를 참으로 읽지 않기 위해) */
    public static function flag(mixed $raw): bool
    {
        if (!is_bool($raw)) {
            throw new RuntimeException('bad_request');
        }
        return $raw;
    }

    /**
     * 요청 본문을 종류별 **컬럼 값 배열**로 좁힌다.
     *
     * 돌려주는 배열의 키는 FIELDS 의 키와 같고, 값은 위 좁히기 함수를 통과한 PHP 값이다.
     * `$partial` 이 true(고치기)면 본문에 **있는 키만** 담는다 — 나머지는 현재 값을 유지한다.
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public static function normalize(string $kind, array $body, bool $partial): array
    {
        $has = static fn (string $key): bool => array_key_exists($key, $body);
        $out = [];

        $take = static function (string $key, callable $fn) use ($body, $has, $partial, &$out): void {
            if ($has($key)) {
                $out[$key] = $fn($body[$key]);
            } elseif (!$partial) {
                throw new RuntimeException('bad_request');   // 만들기에서 필수 칸이 비었다
            }
        };
        $optional = static function (string $key, callable $fn) use ($body, $has, &$out): void {
            if ($has($key)) {
                $out[$key] = $fn($body[$key]);
            }
        };

        switch ($kind) {
            case 'brands':
                $take('name', static fn (mixed $v): string => self::name($v, 60));
                $optional('biz_no', static fn (mixed $v): ?string => self::optionalText($v, 40));
                $optional('ceo_name', static fn (mixed $v): ?string => self::optionalText($v, 60));
                $optional('address', static fn (mixed $v): ?string => self::optionalText($v, 200));
                $optional('tel', static fn (mixed $v): ?string => self::optionalText($v, 40));
                $optional('logo_file_id', static fn (mixed $v): ?int => self::optionalId($v));
                break;

            case 'branches':
                $take('brand_id', static fn (mixed $v): int => self::id($v));
                $take('name', static fn (mixed $v): string => self::name($v, 60));
                $optional('warehouse_location_id', static fn (mixed $v): ?int => self::optionalId($v));
                $optional('central_location_id', static fn (mixed $v): ?int => self::optionalId($v));
                break;

            case 'locations':
                $take('branch_id', static fn (mixed $v): int => self::id($v));
                $take('kind', static fn (mixed $v): string => self::choice($v, self::LOCATION_KINDS));
                $take('name', static fn (mixed $v): string => self::name($v, 80));
                $optional('code', static fn (mixed $v): ?string => self::optionalText($v, 40));
                $optional('legacy_name', static fn (mixed $v): ?string => self::optionalText($v, 200));
                break;

            case 'vendors':
                $take('name', static fn (mixed $v): string => self::name($v, 120));
                $optional('code', static fn (mixed $v): ?string => self::optionalText($v, 40));
                $optional('biz_no', static fn (mixed $v): ?string => self::optionalText($v, 40));
                break;

            case 'categories':
                $take('name', static fn (mixed $v): string => self::name($v, 80));
                $take('level', static fn (mixed $v): int => self::count($v, 1, 3));
                $optional('parent_id', static fn (mixed $v): ?int => self::optionalId($v));
                break;

            case 'par-levels':
                $take('location_id', static fn (mixed $v): int => self::id($v));
                $take('item_id', static fn (mixed $v): int => self::id($v));
                $take('qty', static fn (mixed $v): int => self::count($v, 0, 999999));
                $optional('effective_from', static fn (mixed $v): string => self::date($v));
                break;

            default:
                throw new RuntimeException('bad_request');
        }

        return $out;
    }

    /* ══════════════════════════════════════════════════════════════════
     * 저장 — 종류마다 **고정 INSERT/UPDATE 한 문장**이다.
     * SET 절을 조건에 따라 이어 붙이지 않는다(문자열 조립을 만들지 않기 위해).
     * 고치기는 "현재 값 + 바뀐 값" 을 전부 넘기는 전체 UPDATE 다 (folders_update 와 같은 방식).
     * ══════════════════════════════════════════════════════════════════ */

    private const SQL_INSERT = [
        'brands' => <<<'SQL'
            INSERT INTO p_brands (name, biz_no, ceo_name, address, tel, logo_file_id, created_by)
            VALUES (:name, :biz_no, :ceo_name, :address, :tel,
                    CAST(:logo_file_id AS bigint), :created_by)
            RETURNING id
            SQL,
        'branches' => <<<'SQL'
            INSERT INTO p_branches (brand_id, name, warehouse_location_id, central_location_id, created_by)
            VALUES (CAST(:brand_id AS bigint), :name,
                    CAST(:warehouse_location_id AS bigint), CAST(:central_location_id AS bigint),
                    :created_by)
            RETURNING id
            SQL,
        'locations' => <<<'SQL'
            INSERT INTO p_locations (branch_id, kind, name, code, legacy_name, created_by)
            VALUES (CAST(:branch_id AS bigint), :kind, :name, :code, :legacy_name, :created_by)
            RETURNING id
            SQL,
        'vendors' => <<<'SQL'
            INSERT INTO p_vendors (code, name, biz_no, created_by)
            VALUES (:code, :name, :biz_no, :created_by)
            RETURNING id
            SQL,
        'categories' => <<<'SQL'
            INSERT INTO p_categories (parent_id, name, level, created_by)
            VALUES (CAST(:parent_id AS bigint), :name, CAST(:level AS int), :created_by)
            RETURNING id
            SQL,
        'par-levels' => <<<'SQL'
            INSERT INTO p_par_levels (location_id, item_id, qty, effective_from, created_by)
            VALUES (CAST(:location_id AS bigint), CAST(:item_id AS bigint), CAST(:qty AS int),
                    COALESCE(CAST(:effective_from AS date), CURRENT_DATE), :created_by)
            RETURNING id
            SQL,
    ];

    private const SQL_UPDATE = [
        'brands' => <<<'SQL'
            UPDATE p_brands
               SET name         = :name,
                   biz_no       = :biz_no,
                   ceo_name     = :ceo_name,
                   address      = :address,
                   tel          = :tel,
                   logo_file_id = CAST(:logo_file_id AS bigint),
                   is_active    = CAST(:is_active AS boolean),
                   updated_at   = now()
             WHERE id = CAST(:id AS bigint)
            SQL,
        'branches' => <<<'SQL'
            UPDATE p_branches
               SET brand_id = CAST(:brand_id AS bigint),
                   name     = :name,
                   warehouse_location_id = CAST(:warehouse_location_id AS bigint),
                   central_location_id   = CAST(:central_location_id AS bigint),
                   is_active = CAST(:is_active AS boolean),
                   updated_at = now()
             WHERE id = CAST(:id AS bigint)
            SQL,
        'locations' => <<<'SQL'
            UPDATE p_locations
               SET branch_id   = CAST(:branch_id AS bigint),
                   kind        = :kind,
                   name        = :name,
                   code        = :code,
                   legacy_name = :legacy_name,
                   is_active   = CAST(:is_active AS boolean),
                   updated_at  = now()
             WHERE id = CAST(:id AS bigint)
            SQL,
        'vendors' => <<<'SQL'
            UPDATE p_vendors
               SET code = :code, name = :name, biz_no = :biz_no,
                   is_active = CAST(:is_active AS boolean), updated_at = now()
             WHERE id = CAST(:id AS bigint)
            SQL,
        'categories' => <<<'SQL'
            UPDATE p_categories
               SET parent_id = CAST(:parent_id AS bigint),
                   name      = :name,
                   level     = CAST(:level AS int),
                   is_active = CAST(:is_active AS boolean),
                   updated_at = now()
             WHERE id = CAST(:id AS bigint)
            SQL,
        'par-levels' => <<<'SQL'
            UPDATE p_par_levels
               SET location_id    = CAST(:location_id AS bigint),
                   item_id        = CAST(:item_id AS bigint),
                   qty            = CAST(:qty AS int),
                   effective_from = CAST(:effective_from AS date),
                   is_active      = CAST(:is_active AS boolean),
                   updated_at     = now()
             WHERE id = CAST(:id AS bigint)
            SQL,
    ];

    /**
     * 한 행을 읽는다 (고치기 전 before 스냅샷 · 존재 확인).
     * 표 이름은 위 TABLES 의 고정 문자열이며 요청 값이 아니다.
     *
     * @return array<string,mixed>|null
     */
    public static function find(string $kind, int $id): ?array
    {
        $sql = self::SQL_FIND[$kind] ?? null;
        if ($sql === null) {
            throw new RuntimeException('bad_request');
        }
        $row = Db::instance()->run($sql, [':id' => $id])->fetch();
        return is_array($row) ? $row : null;
    }

    /** 종류별 한 행 조회 — 고정 문장(표 이름이 변수로 들어가지 않는다) */
    private const SQL_FIND = [
        'brands' => <<<'SQL'
            SELECT id, name, biz_no, ceo_name, address, tel, logo_file_id, is_active
              FROM p_brands WHERE id = CAST(:id AS bigint)
            SQL,
        'branches' => <<<'SQL'
            SELECT id, brand_id, name, warehouse_location_id, central_location_id, is_active
              FROM p_branches WHERE id = CAST(:id AS bigint)
            SQL,
        'locations' => <<<'SQL'
            SELECT id, branch_id, kind, name, code, legacy_name, is_active
              FROM p_locations WHERE id = CAST(:id AS bigint)
            SQL,
        'vendors' => <<<'SQL'
            SELECT id, code, name, biz_no, is_active FROM p_vendors WHERE id = CAST(:id AS bigint)
            SQL,
        'categories' => <<<'SQL'
            SELECT id, parent_id, name, level, is_active FROM p_categories WHERE id = CAST(:id AS bigint)
            SQL,
        'par-levels' => <<<'SQL'
            SELECT id, location_id, item_id, qty, CAST(effective_from AS text) AS effective_from, is_active
              FROM p_par_levels WHERE id = CAST(:id AS bigint)
            SQL,
    ];

    /**
     * 만들기 — 새 id 를 돌려준다. 트랜잭션은 **호출자(핸들러)가** 연다:
     * 감사 기록까지 한 트랜잭션에 묶여야 "기록 없이 성공" 이 생기지 않기 때문이다 (PRD S-07).
     *
     * @param array<string,mixed> $values normalize() 를 통과한 값
     */
    public static function insert(string $kind, array $values, int $actorId): int
    {
        $sql = self::SQL_INSERT[$kind] ?? null;
        if ($sql === null) {
            throw new RuntimeException('bad_request');
        }
        $bind = [':created_by' => $actorId];
        foreach (self::FIELDS[$kind] as $field) {
            $bind[':' . $field] = $values[$field] ?? null;
        }
        $row = Db::instance()->run($sql, $bind)->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('insert_failed');
        }
        return (int) $row['id'];
    }

    /**
     * 고치기 — 현재 행과 바뀐 값을 합쳐 **전체 UPDATE** 한 문장을 실행한다.
     *
     * @param array<string,mixed> $current find() 가 읽은 현재 행
     * @param array<string,mixed> $changes normalize(partial=true) 를 통과한 값
     * @param bool|null           $active  null 이면 현재 상태 유지, 아니면 중단(false)·재개(true)
     * @return array{before:array<string,mixed>,after:array<string,mixed>}
     *         **바뀐 칸만** 담은 두 스냅샷 (감사 로그 detail.before/after 로 그대로 간다)
     */
    public static function update(string $kind, int $id, array $current, array $changes, ?bool $active): array
    {
        $sql = self::SQL_UPDATE[$kind] ?? null;
        if ($sql === null) {
            throw new RuntimeException('bad_request');
        }

        $before = [];
        $after  = [];
        $bind   = [':id' => $id];

        foreach (self::FIELDS[$kind] as $field) {
            $now = $current[$field] ?? null;
            // DB 는 정수를 문자열로 돌려줄 수 있다 → 비교 전에 모양을 맞춘다.
            $nowValue = is_int($changes[$field] ?? null) && $now !== null ? (int) $now : $now;
            $next     = array_key_exists($field, $changes) ? $changes[$field] : $nowValue;
            $bind[':' . $field] = $next;
            if (array_key_exists($field, $changes) && $next !== $nowValue) {
                $before[$field] = $nowValue;
                $after[$field]  = $next;
            }
        }

        $nowActive  = ($current['is_active'] ?? true) === true;
        $nextActive = $active === null ? $nowActive : $active;
        $bind[':is_active'] = $nextActive ? 'true' : 'false';
        if ($nextActive !== $nowActive) {
            $before['is_active'] = $nowActive;
            $after['is_active']  = $nextActive;
        }

        Db::instance()->run($sql, $bind);

        return ['before' => $before, 'after' => $after];
    }
}
