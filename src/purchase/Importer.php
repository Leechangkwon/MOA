<?php
declare(strict_types=1);

/**
 * src/purchase/Importer.php — 엑셀 가져오기의 **판정과 확정** (19단계, PRD F-29, SECURITY R-67)
 *
 * 파이프라인 (ARCH 5.18 「가져오기」)
 *   ① 업로드   `Upload::validate` → `files` 표·저장소 (핸들러 purchase_import_upload)
 *   ② 추출     `Sheet::rows`      → 행 배열                (src/purchase/Sheet.php)
 *   ③ 매핑     {엑셀 열 → 우리 칸}                          (p_import_mappings 에 저장 가능)
 *   ④ 판정     이 파일 `judge()`  → 행마다 insert|update|review|error
 *   ⑤ 확정     이 파일 `commit()` → **한 트랜잭션**, 실패 시 전부 롤백
 *
 * 왜 ④와 ⑤를 갈라 두나 (R-67)
 *   1.35만 행을 한 번에 넣는 기능은 잘못된 매핑 하나로 기초정보를 망칠 수 있다.
 *   사람이 **판정 결과를 먼저 보고** 확정하게 하고, 확정은 전부 아니면 전무로 만든다.
 *   판정과 확정은 같은 입력(파일 + 매핑)으로 같은 결과를 내는 **순수 계산**이라,
 *   확정 때 판정을 다시 돌려도 미리보기와 어긋나지 않는다(중간 상태를 표에 저장하지 않는
 *   이유이기도 하다 — 저장하면 그 사이에 기초정보가 바뀌어 둘이 갈라진다).
 *
 * 이 파일이 하지 **않는** 것
 *   · 권한 판단 — 라우트 정책 `purchase.master.write` + 재인증 관문이 이미 지났다 (ARCH 4.1).
 *   · 감사 기록 — 확정 결과만 돌려주고 `Audit::allow('purchase.import.commit')` 는 핸들러가
 *     **한 줄** 남긴다. 행마다 기록하지 않는다 (지시서 보충 8).
 *   · 파일 읽기 — 행 배열은 `Sheet` 가 준다. 여기서 경로를 만들지 않는다.
 */
final class Importer
{
    /** 가져올 수 있는 종류 (p_import_mappings.kind CHECK 와 같은 값) */
    public const KINDS = ['items', 'vendors', 'locations'];

    /** 한 번에 확정할 수 있는 최대 행 수 (Sheet::SHEET_ROWS 와 같은 값) */
    public const MAX_ROWS = Sheet::SHEET_ROWS;

    /**
     * 종류 → 매핑의 **오른쪽**(우리 칸)에 쓸 수 있는 이름. 여기 없는 이름이 매핑에 오면 400.
     * 화면의 셀렉트도 이 목록으로 채운다 — 목록이 두 곳에 있으면 어긋난다.
     *
     * @var array<string,list<string>>
     */
    public const TARGETS = [
        'items' => [
            'code', 'name', 'vendor', 'brand', 'spec', 'unit', 'pack_qty',
            'cat1', 'cat2', 'cat3', 'price_in', 'barcode',
            'stock_managed', 'safety_qty', 'lead_days', 'min_order_qty', 'branches',
        ],
        'vendors'   => ['code', 'name', 'biz_no'],
        'locations' => ['branch', 'name', 'kind', 'code', 'legacy_name'],
    ];

    /**
     * 종류 → **반드시 매핑돼 있어야 하는** 칸. 하나라도 빠지면 미리보기 자체가 400 이다
     * (행마다 오류를 내는 것보다, 매핑이 잘못됐다고 한 번에 말하는 편이 사람에게 낫다).
     *
     * @var array<string,list<string>>
     */
    public const REQUIRED = [
        'items'     => ['code', 'name', 'vendor'],
        'vendors'   => ['name'],
        'locations' => ['branch', 'name'],
    ];

    /** 판정 4종 (시안 ⑯ 의 「등록 · 갱신 · 확인 · 오류」) */
    public const STATUS_INSERT = 'insert';
    public const STATUS_UPDATE = 'update';
    public const STATUS_REVIEW = 'review';
    public const STATUS_ERROR  = 'error';

    /** 미리보기 응답에 실어 보내는 최대 행 수 (전체 개수는 counts 로 따로 알린다) */
    public const PREVIEW_ROWS = 200;

    /** 가져오기 원본을 보관하는 폴더 — 이름은 **코드 상수**이며 요청 값이 섞이지 않는다 */
    public const FOLDER_PARENT = '구매';
    public const FOLDER_CHILD  = '가져오기';

    /**
     * 가져오기 파일 한 건을 찾는다 — **「구매/가져오기」 폴더 안의 xlsx·csv 만** (SECURITY R-69).
     *
     * 왜 폴더로 좁히나
     *   `{id}` 는 `files.public_id` 이고, 이 라우트에 닿는 사람(buyer)은 본체에서 Admin 이 아닐
     *   수 있다. 보관 폴더의 파일은 `admin-only` 이므로, 폴더를 확인하지 않으면 아무 UUID 나
     *   넣어 **남의 admin-only 문서를 표로 뽑아 가는 창구**가 된다. 그래서 경로를 폴더 하나로
     *   묶고, 폴더가 다르거나 없는 id 는 **똑같이 null**(→ 404)이다 — 있고 없고를 알려 주지
     *   않는다 (PRD S-09-5).
     *
     * 미리보기와 확정 **두 핸들러가 같은 함수**를 쓴다. 두 벌로 두면 언젠가 한쪽만 고쳐지고,
     * 그 한쪽이 곧 우회 경로다 (Upload::validatePath 를 두 입구가 함께 쓰는 것과 같은 이유).
     *
     * @return array<string,mixed>|null
     */
    public static function findFile(mixed $publicId): ?array
    {
        if (!is_string($publicId) || preg_match('/^[0-9a-f-]{36}$/', $publicId) !== 1) {
            return null;
        }
        $row = Db::instance()->run(self::SQL_IMPORT_FILE, [':pid' => $publicId])->fetch();
        if (!is_array($row)) {
            return null;
        }
        if (!in_array((string) $row['ext'], Sheet::SHEET_EXT, true)) {
            return null;
        }
        return $row;
    }

    /**
     * 보관 폴더 안의 살아 있는 파일 한 건. 폴더 두 단(`구매` → `가져오기`)을 이름으로 따라간다 —
     * 이름은 위 상수이고 요청 값이 아니다.
     */
    private const SQL_IMPORT_FILE = <<<'SQL'
        SELECT f.id, f.public_id, f.original_name, f.stored_name, f.ext, f.sha256, f.size_bytes
          FROM files f
          JOIN folders child  ON child.id = f.folder_id
          JOIN folders parent ON parent.id = child.parent_id
         WHERE f.public_id = CAST(:pid AS uuid)
           AND f.deleted_at IS NULL
           AND child.name = '가져오기'
           AND parent.name = '구매'
           AND parent.parent_id IS NULL
        SQL;

    /**
     * 매핑이 성립하는가 — 열 이름은 머리행에 있고, 우리 칸 이름은 TARGETS 안이며,
     * REQUIRED 가 전부 채워져 있나. 아니면 예외(사유 코드).
     *
     * @param list<string>         $header  머리행 (rows[0])
     * @param array<string,string> $mapping {엑셀 열 이름 → 우리 칸 이름}
     * @return array<string,int>   {우리 칸 이름 → 열 번호}
     */
    public static function resolve(string $kind, array $header, array $mapping): array
    {
        if (!in_array($kind, self::KINDS, true)) {
            throw new RuntimeException('bad_kind');
        }

        // 머리행의 이름 → 열 번호. 같은 이름이 둘이면 **앞의 것**을 쓴다.
        $columns = [];
        foreach ($header as $index => $label) {
            $key = trim((string) $label);
            if ($key !== '' && !array_key_exists($key, $columns)) {
                $columns[$key] = (int) $index;
            }
        }

        $resolved = [];
        foreach ($mapping as $column => $target) {
            if (!is_string($column) || !is_string($target)) {
                throw new RuntimeException('bad_mapping');
            }
            if ($target === '' || $target === 'skip') {
                continue;   // 「건너뜀」 — 시안 ⑯ 의 마지막 줄
            }
            if (!in_array($target, self::TARGETS[$kind], true)) {
                throw new RuntimeException('bad_mapping');
            }
            if (!array_key_exists($column, $columns)) {
                throw new RuntimeException('bad_mapping');   // 그 이름의 열이 파일에 없다
            }
            $resolved[$target] = $columns[$column];
        }

        foreach (self::REQUIRED[$kind] as $need) {
            if (!array_key_exists($need, $resolved)) {
                throw new RuntimeException('mapping_incomplete');
            }
        }

        return $resolved;
    }

    /**
     * 머리행을 보고 **매핑 후보**를 만든다 (업로드 1단계의 편의).
     * 열 이름이 우리 칸 이름과 정확히 같거나, 아래 별명 표에 있으면 이어 준다.
     * 추측이 틀려도 사람이 2단계에서 고친다 — 서버는 이 값을 판정에 쓰지 않는다.
     *
     * @param list<string> $header
     * @return array<string,string> {엑셀 열 이름 → 우리 칸 이름}
     */
    public static function guessMapping(string $kind, array $header): array
    {
        $alias = self::ALIASES[$kind] ?? [];
        $out   = [];
        foreach ($header as $label) {
            $key = trim((string) $label);
            if ($key === '') {
                continue;
            }
            $lower = strtolower($key);
            if (in_array($lower, self::TARGETS[$kind], true)) {
                $out[$key] = $lower;
            } elseif (isset($alias[$key])) {
                $out[$key] = $alias[$key];
            }
        }
        return $out;
    }

    /**
     * 이카운트 엑셀의 흔한 열 이름 → 우리 칸 (시안 ⑯ 의 「열 맞추기」 예시).
     * **추측을 돕는 표일 뿐** 판정에 쓰이지 않는다.
     *
     * @var array<string,array<string,string>>
     */
    private const ALIASES = [
        'items' => [
            '품목코드' => 'code', '품목명' => 'name', '구매처명' => 'vendor', '거래처' => 'vendor',
            '거래처명' => 'vendor', '규격' => 'spec', '단위' => 'unit', '대분류' => 'cat1',
            '중분류' => 'cat2', '소분류' => 'cat3', '입고단가' => 'price_in', '바코드' => 'barcode',
            '포장수량' => 'pack_qty', '안전재고' => 'safety_qty', '조달기간' => 'lead_days',
            '최소구매단위' => 'min_order_qty', '브랜드' => 'brand', '사용지점' => 'branches',
        ],
        'vendors' => [
            '거래처코드' => 'code', '거래처명' => 'name', '구매처명' => 'name', '사업자번호' => 'biz_no',
        ],
        'locations' => [
            '지점' => 'branch', '지점명' => 'branch', '장소' => 'name', '장소명' => 'name',
            '용도' => 'kind', '장소코드' => 'code', '이전이름' => 'legacy_name',
        ],
    ];

    /**
     * 행마다 판정한다 — DB 를 **읽기만** 한다. 아무것도 쓰지 않는다.
     *
     * @param list<list<string>>  $rows     Sheet::rows 의 결과 (rows[0] 은 머리행)
     * @param array<string,int>   $resolved resolve() 의 결과
     * @return array{counts:array<string,int>, rows:list<array<string,mixed>>, total:int}
     */
    public static function judge(string $kind, array $rows, array $resolved): array
    {
        $body = array_slice($rows, 1);   // 머리행 제외
        if (count($body) > self::MAX_ROWS) {
            throw new RuntimeException('too_many_rows');
        }

        $lookup = self::lookups($kind);
        $counts = [self::STATUS_INSERT => 0, self::STATUS_UPDATE => 0,
                   self::STATUS_REVIEW => 0, self::STATUS_ERROR => 0];
        $seen   = [];     // 파일 안 중복 검사용 열쇠 모음
        $out    = [];

        foreach ($body as $offset => $row) {
            $rowNo = $offset + 2;   // 엑셀에서 보이는 줄 번호 (1 = 머리행)
            $data  = self::pick($row, $resolved);

            $judged = match ($kind) {
                'items'     => self::judgeItem($data, $lookup, $seen),
                'vendors'   => self::judgeVendor($data, $lookup, $seen),
                'locations' => self::judgeLocation($data, $lookup, $seen),
                default     => ['status' => self::STATUS_ERROR, 'reason' => 'bad_kind', 'data' => $data],
            };

            $counts[$judged['status']]++;
            $judged['row_no'] = $rowNo;
            $out[] = $judged;
        }

        return ['counts' => $counts, 'rows' => $out, 'total' => count($body)];
    }

    /**
     * 확정 — **한 트랜잭션**. 오류 행은 저장하지 않고, 중간에 하나라도 실패하면 전부 롤백된다.
     * 트랜잭션은 호출자(핸들러)가 연다 — 감사 기록까지 같은 트랜잭션에 묶기 위해서다.
     *
     * @param list<array<string,mixed>> $judged  judge() 의 rows
     * @param list<int>                 $promote review → update 로 승격할 row_no 목록
     * @return array{inserted:int, updated:int, skipped:int}
     */
    public static function commit(string $kind, array $judged, array $promote, int $actorId): array
    {
        $inserted = 0;
        $updated  = 0;
        $skipped  = 0;

        foreach ($judged as $row) {
            $status = (string) $row['status'];
            if ($status === self::STATUS_REVIEW && in_array((int) $row['row_no'], $promote, true)) {
                $status = self::STATUS_UPDATE;
            }
            if ($status === self::STATUS_ERROR || $status === self::STATUS_REVIEW) {
                $skipped++;
                continue;
            }

            $values = is_array($row['values'] ?? null) ? $row['values'] : [];
            if ($status === self::STATUS_INSERT) {
                self::insertRow($kind, $values, $actorId);
                $inserted++;
                continue;
            }

            // update — 바뀐 것이 없으면 쓰지 않는다(쓸데없는 updated_at 갱신을 만들지 않는다)
            if (($row['diff'] ?? []) === []) {
                $skipped++;
                continue;
            }
            self::updateRow($kind, (int) $row['target_id'], $values);
            $updated++;
        }

        return ['inserted' => $inserted, 'updated' => $updated, 'skipped' => $skipped];
    }

    /* ───────────────────────── 내부 ───────────────────────── */

    /**
     * 한 행에서 매핑된 칸만 뽑는다.
     *
     * @param list<string>      $row
     * @param array<string,int> $resolved
     * @return array<string,string>
     */
    private static function pick(array $row, array $resolved): array
    {
        $out = [];
        foreach ($resolved as $target => $index) {
            $out[$target] = trim((string) ($row[$index] ?? ''));
        }
        return $out;
    }

    /**
     * 판정에 필요한 기초정보를 **한 번에** 읽어 메모리에 둔다.
     * 1.35만 행마다 조회를 날리면 그것만으로 몇 분이 걸린다(그리고 DB 를 점유한다).
     * 기초정보는 수천 건 규모라 한 번에 읽어도 가볍다.
     *
     * @return array<string,array<string,mixed>>
     */
    private static function lookups(string $kind): array
    {
        $db  = Db::instance();
        $out = [];

        if ($kind === 'items' || $kind === 'vendors') {
            $out['vendor_by_code'] = [];
            $out['vendor_by_name'] = [];
            foreach ($db->run(self::SQL_VENDORS)->fetchAll() as $row) {
                $id = (int) $row['id'];
                if ($row['code'] !== null && $row['code'] !== '') {
                    $out['vendor_by_code'][strtolower((string) $row['code'])] = $id;
                }
                $out['vendor_by_name'][strtolower((string) $row['name'])] = $id;
            }
        }

        if ($kind === 'items') {
            $out['category'] = [];   // "level|부모id|이름" → id
            foreach ($db->run(self::SQL_CATEGORIES)->fetchAll() as $row) {
                $parent = $row['parent_id'] === null ? '0' : (string) (int) $row['parent_id'];
                $key    = (string) (int) $row['level'] . '|' . $parent . '|' . strtolower((string) $row['name']);
                $out['category'][$key] = (int) $row['id'];
            }
            $out['brand'] = [];
            $out['branch'] = [];
            foreach ($db->run(self::SQL_BRANDS)->fetchAll() as $row) {
                $out['brand'][strtolower((string) $row['name'])] = (int) $row['id'];
            }
            foreach ($db->run(self::SQL_BRANCHES)->fetchAll() as $row) {
                $out['branch'][strtolower((string) $row['name'])] = (int) $row['id'];
            }
            $out['item'] = [];       // "거래처id|코드(소문자)" → 현재 행
            foreach ($db->run(self::SQL_ITEMS)->fetchAll() as $row) {
                $out['item'][(string) (int) $row['vendor_id'] . '|' . strtolower((string) $row['code'])] = $row;
            }
        }

        if ($kind === 'locations') {
            $out['branch'] = [];
            foreach ($db->run(self::SQL_BRANCHES)->fetchAll() as $row) {
                $out['branch'][strtolower((string) $row['name'])] = (int) $row['id'];
            }
            $out['location'] = [];   // "지점id|이름(소문자)" → 현재 행
            foreach ($db->run(self::SQL_LOCATIONS)->fetchAll() as $row) {
                $out['location'][(string) (int) $row['branch_id'] . '|' . strtolower((string) $row['name'])] = $row;
            }
        }

        return $out;
    }

    private const SQL_VENDORS = <<<'SQL'
        SELECT id, code, name FROM p_vendors WHERE is_active
        SQL;

    private const SQL_CATEGORIES = <<<'SQL'
        SELECT id, parent_id, name, level FROM p_categories WHERE is_active
        SQL;

    private const SQL_BRANDS = <<<'SQL'
        SELECT id, name FROM p_brands WHERE is_active
        SQL;

    private const SQL_BRANCHES = <<<'SQL'
        SELECT id, name FROM p_branches WHERE is_active
        SQL;

    private const SQL_ITEMS = <<<'SQL'
        SELECT id, vendor_id, code, name, spec, unit, pack_qty, barcode,
               cat1_id, cat2_id, cat3_id, stock_managed, safety_qty, lead_days,
               min_order_qty, CAST(price_in AS text) AS price_in, brand_id
          FROM p_items
        SQL;

    private const SQL_LOCATIONS = <<<'SQL'
        SELECT id, branch_id, kind, name, code, legacy_name FROM p_locations
        SQL;

    /**
     * 품목 한 행 (지시서 보충 7).
     *   · 거래처를 코드 또는 이름으로 찾는다 → 없으면 error `vendor_not_found`
     *   · 단위가 비면 error `unit_missing`
     *   · 분류 이름이 있는데 그 단계에 없으면 error `category_not_found` (조용히 만들지 않는다)
     *   · 같은 (거래처, 코드)가 **이 파일 안에** 또 있으면 error `duplicate_in_file`
     *   · DB 에 이미 있으면 update(달라진 칸만), 이름만 다르면 review
     *   · 없으면 insert
     *
     * @param array<string,string>              $d
     * @param array<string,array<string,mixed>> $lookup
     * @param array<string,bool>                $seen
     * @return array<string,mixed>
     */
    private static function judgeItem(array $d, array $lookup, array &$seen): array
    {
        $code = $d['code'] ?? '';
        $name = $d['name'] ?? '';
        if ($code === '' || $name === '') {
            return self::fail('required_missing', $d);
        }

        $vendorText = $d['vendor'] ?? '';
        $vendorId   = $lookup['vendor_by_code'][strtolower($vendorText)]
                   ?? $lookup['vendor_by_name'][strtolower($vendorText)]
                   ?? null;
        if ($vendorId === null) {
            return self::fail('vendor_not_found', $d);
        }

        $unit = $d['unit'] ?? '';
        if ($unit === '') {
            return self::fail('unit_missing', $d);
        }

        // 분류 3단 — 위에서부터 이름으로 찾아 내려간다. 빈 칸은 "없음" 이고 오류가 아니다.
        $catIds = [null, null, null];
        $parent = 0;
        for ($level = 1; $level <= 3; $level++) {
            $label = $d['cat' . $level] ?? '';
            if ($label === '') {
                break;   // 중간이 비면 그 아래도 보지 않는다
            }
            $key = (string) $level . '|' . (string) $parent . '|' . strtolower($label);
            $id  = $lookup['category'][$key] ?? null;
            if ($id === null) {
                return self::fail('category_not_found', $d);
            }
            $catIds[$level - 1] = $id;
            $parent = $id;
        }

        $values = [
            'vendor_id'     => $vendorId,
            'code'          => self::cut($code, 60),
            'name'          => self::cut($name, 200),
            'spec'          => self::orNull($d['spec'] ?? ''),
            'unit'          => self::cut($unit, 40),
            'pack_qty'      => self::intOr($d['pack_qty'] ?? '', 1, 1, 9999999),
            'cat1_id'       => $catIds[0],
            'cat2_id'       => $catIds[1],
            'cat3_id'       => $catIds[2],
            'stock_managed' => self::boolOr($d['stock_managed'] ?? '', true),
            'safety_qty'    => self::intOr($d['safety_qty'] ?? '', 0, 0, 9999999),
            'lead_days'     => self::intOr($d['lead_days'] ?? '', 0, 0, 3650),
            'min_order_qty' => self::intOr($d['min_order_qty'] ?? '', 1, 1, 9999999),
            'price_in'      => self::moneyOrNull($d['price_in'] ?? ''),
            'barcode'       => self::orNull($d['barcode'] ?? ''),
            'brand_id'      => $lookup['brand'][strtolower($d['brand'] ?? '')] ?? null,
        ];

        $key = (string) $vendorId . '|' . strtolower($code);
        if (isset($seen[$key])) {
            return self::fail('duplicate_in_file', $d);
        }
        $seen[$key] = true;

        $current = $lookup['item'][$key] ?? null;
        if ($current === null) {
            return ['status' => self::STATUS_INSERT, 'reason' => null, 'data' => $d, 'values' => $values];
        }

        $diff = self::diff($current, $values);
        // 이름만 다르면 「확인 필요」 — 원본에 이름이 빠졌거나 표기가 흔들린 경우가 많아
        // 자동으로 덮어쓰면 기존 이름이 조용히 사라진다 (시안 ⑯ 19행).
        $status = (array_keys($diff) === ['name']) ? self::STATUS_REVIEW : self::STATUS_UPDATE;

        return [
            'status'    => $status,
            'reason'    => $status === self::STATUS_REVIEW ? 'name_differs' : null,
            'data'      => $d,
            'values'    => $values,
            'diff'      => $diff,
            'target_id' => (int) $current['id'],
        ];
    }

    /**
     * 거래처 한 행 — 코드가 있으면 코드로, 없으면 이름으로 맞춘다.
     *
     * @param array<string,string>              $d
     * @param array<string,array<string,mixed>> $lookup
     * @param array<string,bool>                $seen
     * @return array<string,mixed>
     */
    private static function judgeVendor(array $d, array $lookup, array &$seen): array
    {
        $name = $d['name'] ?? '';
        if ($name === '') {
            return self::fail('required_missing', $d);
        }
        $code = $d['code'] ?? '';

        $values = [
            'code'   => self::orNull($code),
            'name'   => self::cut($name, 120),
            'biz_no' => self::orNull($d['biz_no'] ?? ''),
        ];

        $key = $code !== '' ? 'c:' . strtolower($code) : 'n:' . strtolower($name);
        if (isset($seen[$key])) {
            return self::fail('duplicate_in_file', $d);
        }
        $seen[$key] = true;

        $id = $code !== ''
            ? ($lookup['vendor_by_code'][strtolower($code)] ?? null)
            : ($lookup['vendor_by_name'][strtolower($name)] ?? null);

        if ($id === null) {
            return ['status' => self::STATUS_INSERT, 'reason' => null, 'data' => $d, 'values' => $values];
        }
        return [
            'status'    => self::STATUS_UPDATE,
            'reason'    => null,
            'data'      => $d,
            'values'    => $values,
            'diff'      => ['name' => $values['name']],
            'target_id' => $id,
        ];
    }

    /**
     * 장소 한 행 — (지점, 이름) 으로 맞춘다. 지점은 **이름으로 찾고 없으면 오류**다
     * (지점을 가져오기가 만들지 않는다 — 지점은 사람이 정하는 마스터다).
     *
     * @param array<string,string>              $d
     * @param array<string,array<string,mixed>> $lookup
     * @param array<string,bool>                $seen
     * @return array<string,mixed>
     */
    private static function judgeLocation(array $d, array $lookup, array &$seen): array
    {
        $name = $d['name'] ?? '';
        if ($name === '') {
            return self::fail('required_missing', $d);
        }
        $branchId = $lookup['branch'][strtolower($d['branch'] ?? '')] ?? null;
        if ($branchId === null) {
            return self::fail('branch_not_found', $d);
        }

        $kindRaw = strtolower($d['kind'] ?? '');
        $kind    = in_array($kindRaw, Master::LOCATION_KINDS, true) ? $kindRaw : 'other';

        $values = [
            'branch_id'   => $branchId,
            'kind'        => $kind,
            'name'        => self::cut($name, 80),
            'code'        => self::orNull($d['code'] ?? ''),
            'legacy_name' => self::orNull($d['legacy_name'] ?? ''),
        ];

        $key = (string) $branchId . '|' . strtolower($name);
        if (isset($seen[$key])) {
            return self::fail('duplicate_in_file', $d);
        }
        $seen[$key] = true;

        $current = $lookup['location'][$key] ?? null;
        if ($current === null) {
            return ['status' => self::STATUS_INSERT, 'reason' => null, 'data' => $d, 'values' => $values];
        }
        return [
            'status'    => self::STATUS_UPDATE,
            'reason'    => null,
            'data'      => $d,
            'values'    => $values,
            'diff'      => self::diff($current, $values),
            'target_id' => (int) $current['id'],
        ];
    }

    /** @return array<string,mixed> */
    private static function fail(string $reason, array $data): array
    {
        return ['status' => self::STATUS_ERROR, 'reason' => $reason, 'data' => $data];
    }

    /**
     * 현재 행과 새 값의 **다른 칸만** 돌려준다 (감사 before/after 의 재료).
     * DB 는 정수·불리언을 문자열로 돌려줄 수 있으므로 문자열로 맞춰 비교한다.
     *
     * @param array<string,mixed> $current
     * @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    private static function diff(array $current, array $values): array
    {
        $out = [];
        foreach ($values as $key => $next) {
            if (!array_key_exists($key, $current)) {
                continue;
            }
            $a = self::asText($current[$key]);
            $b = self::asText($next);
            if ($a !== $b) {
                $out[$key] = $next;
            }
        }
        return $out;
    }

    private static function asText(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_float($value) || is_int($value)) {
            return (string) $value;
        }
        $text = trim((string) $value);
        // PostgreSQL 의 numeric 은 `23750.00` 으로 돌아온다 — 꼬리 0 을 떼고 비교한다.
        if (preg_match('/^-?[0-9]+\.[0-9]+$/', $text) === 1) {
            $text = rtrim(rtrim($text, '0'), '.');
        }
        return $text === 't' ? '1' : ($text === 'f' ? '0' : $text);
    }

    private static function cut(string $value, int $max): string
    {
        if (iconv_strlen($value, 'UTF-8') <= $max) {
            return $value;
        }
        $cut = iconv_substr($value, 0, $max, 'UTF-8');
        return is_string($cut) ? $cut : $value;
    }

    private static function orNull(string $value): ?string
    {
        $value = trim($value);
        return $value === '' ? null : self::cut($value, 200);
    }

    private static function intOr(string $value, int $default, int $min, int $max): int
    {
        // 엑셀은 `2`·`2.0`·`2,000` 같은 모양으로 숫자를 준다 — 앞의 정수부만 읽는다.
        $clean = str_replace(',', '', trim($value));
        if (preg_match('/^([0-9]{1,9})(\.0+)?$/', $clean, $m) !== 1) {
            return $default;
        }
        $n = (int) $m[1];
        return ($n < $min || $n > $max) ? $default : $n;
    }

    private static function boolOr(string $value, bool $default): bool
    {
        $clean = strtolower(trim($value));
        if ($clean === '') {
            return $default;
        }
        if (in_array($clean, ['y', 'yes', 'true', '1', 'o', '함', '한다', '관리'], true)) {
            return true;
        }
        if (in_array($clean, ['n', 'no', 'false', '0', 'x', '안함', '안 한다', '미관리'], true)) {
            return false;
        }
        return $default;
    }

    private static function moneyOrNull(string $value): ?string
    {
        $clean = str_replace([',', ' '], '', trim($value));
        if (preg_match('/^[0-9]{1,10}(\.[0-9]{1,2})?$/', $clean) !== 1) {
            return null;
        }
        return $clean;
    }

    /* ─── 쓰기 (commit 안에서만 불린다. 트랜잭션은 호출자가 연다) ─── */

    private const SQL_INSERT_ITEM = <<<'SQL'
        INSERT INTO p_items
            (brand_id, vendor_id, code, name, spec, unit, pack_qty,
             cat1_id, cat2_id, cat3_id, stock_managed, safety_qty, lead_days,
             min_order_qty, price_in, barcode, created_by)
        VALUES
            (CAST(:brand_id AS bigint), CAST(:vendor_id AS bigint), :code, :name, :spec, :unit,
             CAST(:pack_qty AS int), CAST(:cat1_id AS bigint), CAST(:cat2_id AS bigint),
             CAST(:cat3_id AS bigint), CAST(:stock_managed AS boolean), CAST(:safety_qty AS int),
             CAST(:lead_days AS int), CAST(:min_order_qty AS int),
             CAST(:price_in AS numeric), :barcode, :created_by)
        SQL;

    private const SQL_UPDATE_ITEM = <<<'SQL'
        UPDATE p_items
           SET brand_id = CAST(:brand_id AS bigint),
               name     = :name,
               spec     = :spec,
               unit     = :unit,
               pack_qty = CAST(:pack_qty AS int),
               cat1_id  = CAST(:cat1_id AS bigint),
               cat2_id  = CAST(:cat2_id AS bigint),
               cat3_id  = CAST(:cat3_id AS bigint),
               stock_managed = CAST(:stock_managed AS boolean),
               safety_qty    = CAST(:safety_qty AS int),
               lead_days     = CAST(:lead_days AS int),
               min_order_qty = CAST(:min_order_qty AS int),
               price_in      = CAST(:price_in AS numeric),
               barcode       = :barcode,
               updated_at    = now()
         WHERE id = CAST(:id AS bigint)
        SQL;

    private const SQL_INSERT_VENDOR = <<<'SQL'
        INSERT INTO p_vendors (code, name, biz_no, created_by)
        VALUES (:code, :name, :biz_no, :created_by)
        SQL;

    private const SQL_UPDATE_VENDOR = <<<'SQL'
        UPDATE p_vendors
           SET code = :code, name = :name, biz_no = :biz_no, updated_at = now()
         WHERE id = CAST(:id AS bigint)
        SQL;

    private const SQL_INSERT_LOCATION = <<<'SQL'
        INSERT INTO p_locations (branch_id, kind, name, code, legacy_name, created_by)
        VALUES (CAST(:branch_id AS bigint), :kind, :name, :code, :legacy_name, :created_by)
        SQL;

    private const SQL_UPDATE_LOCATION = <<<'SQL'
        UPDATE p_locations
           SET branch_id = CAST(:branch_id AS bigint), kind = :kind, name = :name,
               code = :code, legacy_name = :legacy_name, updated_at = now()
         WHERE id = CAST(:id AS bigint)
        SQL;

    /** @param array<string,mixed> $v */
    private static function insertRow(string $kind, array $v, int $actorId): void
    {
        $db = Db::instance();
        if ($kind === 'items') {
            $db->run(self::SQL_INSERT_ITEM, [
                ':brand_id'      => $v['brand_id'],
                ':vendor_id'     => $v['vendor_id'],
                ':code'          => $v['code'],
                ':name'          => $v['name'],
                ':spec'          => $v['spec'],
                ':unit'          => $v['unit'],
                ':pack_qty'      => $v['pack_qty'],
                ':cat1_id'       => $v['cat1_id'],
                ':cat2_id'       => $v['cat2_id'],
                ':cat3_id'       => $v['cat3_id'],
                ':stock_managed' => $v['stock_managed'] ? 'true' : 'false',
                ':safety_qty'    => $v['safety_qty'],
                ':lead_days'     => $v['lead_days'],
                ':min_order_qty' => $v['min_order_qty'],
                ':price_in'      => $v['price_in'],
                ':barcode'       => $v['barcode'],
                ':created_by'    => $actorId,
            ]);
            return;
        }
        if ($kind === 'vendors') {
            $db->run(self::SQL_INSERT_VENDOR, [
                ':code' => $v['code'], ':name' => $v['name'], ':biz_no' => $v['biz_no'],
                ':created_by' => $actorId,
            ]);
            return;
        }
        $db->run(self::SQL_INSERT_LOCATION, [
            ':branch_id' => $v['branch_id'], ':kind' => $v['kind'], ':name' => $v['name'],
            ':code' => $v['code'], ':legacy_name' => $v['legacy_name'], ':created_by' => $actorId,
        ]);
    }

    /** @param array<string,mixed> $v */
    private static function updateRow(string $kind, int $id, array $v): void
    {
        $db = Db::instance();
        if ($kind === 'items') {
            $db->run(self::SQL_UPDATE_ITEM, [
                ':id'            => $id,
                ':brand_id'      => $v['brand_id'],
                ':name'          => $v['name'],
                ':spec'          => $v['spec'],
                ':unit'          => $v['unit'],
                ':pack_qty'      => $v['pack_qty'],
                ':cat1_id'       => $v['cat1_id'],
                ':cat2_id'       => $v['cat2_id'],
                ':cat3_id'       => $v['cat3_id'],
                ':stock_managed' => $v['stock_managed'] ? 'true' : 'false',
                ':safety_qty'    => $v['safety_qty'],
                ':lead_days'     => $v['lead_days'],
                ':min_order_qty' => $v['min_order_qty'],
                ':price_in'      => $v['price_in'],
                ':barcode'       => $v['barcode'],
            ]);
            return;
        }
        if ($kind === 'vendors') {
            $db->run(self::SQL_UPDATE_VENDOR, [
                ':id' => $id, ':code' => $v['code'], ':name' => $v['name'], ':biz_no' => $v['biz_no'],
            ]);
            return;
        }
        $db->run(self::SQL_UPDATE_LOCATION, [
            ':id' => $id, ':branch_id' => $v['branch_id'], ':kind' => $v['kind'],
            ':name' => $v['name'], ':code' => $v['code'], ':legacy_name' => $v['legacy_name'],
        ]);
    }
}
