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
    /**
     * 가져올 수 있는 종류.
     *
     * ★ `branches`(지점)는 2차 보정 2026-09-13 에 더했다 — 이카운트 창고 파일에는 지점 열이
     *   없고 창고명이 「플란치과_강남점」 꼴이라, **지점을 먼저 만들 길**이 없으면 창고를
     *   한 건도 넣을 수 없었다. 지점은 수가 적어 손으로 만들어도 되지만, 창고 파일에서
     *   이름을 뽑아 한 번에 만드는 길(아래 DERIVE_LOCATION_NAME)이 훨씬 빠르다.
     *
     * ※ `p_import_mappings.kind` 의 CHECK 는 `items|vendors|locations` 세 값이다 —
     *   지점 파생 등록은 **한 번 쓰고 마는 흐름**이라 매핑을 저장할 일이 없다.
     *   그래서 표의 CHECK 를 넓히지 않았다(저장 창구가 `branches` 를 막는 것이 맞다).
     */
    public const KINDS = ['items', 'vendors', 'locations', 'branches'];

    /** 매핑을 **저장**할 수 있는 종류 (p_import_mappings.kind CHECK 와 같은 값) */
    public const SAVABLE_KINDS = ['items', 'vendors', 'locations'];

    /**
     * 매핑과 **함께 저장할 수 있는 옵션**의 이름 (9차 보정 2026-09-14).
     *
     * 미리보기·확정이 받는 옵션 키와 **같은 목록**이다 — 저장 창구가 더 넓으면 판정이 모르는
     * 값을 되살려 보내게 되고, 더 좁으면 「같은 양식은 두 걸음으로 끝난다」 가 깨진다.
     * 값 타입은 저장 창구(`purchase_import_mappings.php`)가 하나씩 본다:
     * `create_*` 는 참/거짓, `derive` 는 정해진 문자열, `default_*` 는 양의 정수 id.
     *
     * @var list<string>
     */
    public const OPTION_KEYS = [
        'create_brands', 'create_branches', 'create_categories', 'create_vendors',
        'derive', 'default_branch', 'default_brand',
    ];

    /**
     * 창고명에서 지점 이름을 뽑아 `branches` 로 판정하는 모드 (2차 보정).
     * 화면의 「창고명에서 지점 이름 뽑기」 단추가 `derive=location_name` 으로 부른다.
     */
    public const DERIVE_LOCATION_NAME = 'location_name';

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
            'code', 'name', 'vendor',
            // 3차 보정 — 같은 이름의 거래처가 여럿일 때 **어느 거래처인지 못 정하는** 문제의
            // 해법(SECURITY R-72). 파일에 거래처 코드 열이 있으면 이 칸에 이어 주면 된다.
            'vendor_code',
            'brand', 'spec', 'unit', 'pack_qty',
            // 이카운트는 포장 수량을 **분자/분모 두 칸**으로 내보낸다(「당수량(분자)」·「당수량(분모)」).
            // 분모가 1 이면 분자가 곧 낱개 수이고, 1 이 아니면 사람이 확인해야 한다(아래 judgeItem).
            'pack_den',
            'cat1', 'cat2', 'cat3', 'price_in', 'barcode',
            'stock_managed', 'safety_qty', 'lead_days', 'min_order_qty', 'branches',
        ],
        // 2차 보정 — 이카운트 거래처 파일의 「대표자명」·「전화」·「사용구분」을 받는다.
        'vendors'   => ['code', 'name', 'biz_no', 'ceo_name', 'tel', 'active'],
        // `branch` 는 이제 **선택**이다 — 이카운트 창고 파일에는 지점 열이 없다.
        // 지점은 창고명에서 찾거나(branch_by=name) 화면의 「기본 지점」 이 정한다(default).
        'locations' => ['branch', 'name', 'kind', 'code', 'legacy_name', 'active'],
        'branches'  => ['name', 'brand', 'code', 'active'],
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
        // 2차 보정 — `branch` 를 뺐다. 이카운트 창고 파일에 지점 열이 아예 없어서,
        // 필수로 두면 그 파일은 **영원히 가져올 수 없다**. 지점이 어디서 오는지는
        // judgeLocation 의 세 규칙(열 → 창고명 → 기본 지점)이 정하고, 셋 다 없으면
        // 미리보기가 `default_branch_required` 로 400 이다(행마다 오류를 내지 않는다).
        'locations' => ['name'],
        'branches'  => ['name'],
    ];

    /** 판정 4종 (시안 ⑯ 의 「등록 · 갱신 · 확인 · 오류」) */
    public const STATUS_INSERT = 'insert';
    public const STATUS_UPDATE = 'update';
    public const STATUS_REVIEW = 'review';
    public const STATUS_ERROR  = 'error';

    /** 미리보기 응답에 실어 보내는 최대 행 수 (전체 개수는 counts 로 따로 알린다) */
    public const PREVIEW_ROWS = 200;

    /**
     * 행 목록 한 쪽의 크기 (6차 보정 2026-09-13, 사용자 결정).
     * 8,100행을 한 화면에 쏟으면 확정 단추가 스크롤 저 아래로 밀린다 — 한 쪽씩 넘겨 본다.
     */
    public const PREVIEW_PAGE = 50;

    /**
     * 「새로 만들 분류·거래처」 이름을 미리보기 응답에 **몇 개까지** 실을까 (9차 보정).
     * 실제 이카운트 품목 파일은 분류 이름이 157개다 — 전부 실으면 요약이 목록이 되고,
     * 사람은 개수만 보고 확정을 누른다. 수는 정확히 세고(`new_categories`), 이름은 맛보기다.
     */
    public const CREATE_PREVIEW_NAMES = 20;

    /**
     * 머리행을 찾을 때 훑는 **앞부분 행 수** (1차 보정 2026-09-13).
     *
     * 왜 필요한가 — 이카운트 품목 내보내기(ESA009M.xlsx)의 0행은 제목 한 칸
     * (`회사명 : …`)이고 **진짜 머리행은 1행**이다. 0행을 머리행으로 잡으면 열 목록이
     * 한 칸뿐이라 사람이 매핑을 채울 수 없고, 그 상태로 미리보기를 누르면
     * `mapping_incomplete` 400 만 돌아온다(2026-09-13 05:37 실측 사고).
     *
     * 20행으로 잡은 이유: 회계·ERP 내보내기의 머리말은 보통 1~5행이고, 20행을 넘겨
     * 찾는 것은 "이 파일은 표가 아니다" 라는 뜻이라 사람이 봐야 한다.
     */
    public const HEAD_ROWS = 20;

    /** 화면에 미리 보여 줄 앞부분의 최대 열 수 (Sheet 의 상한과 같다) */
    public const HEAD_COLS = Sheet::SHEET_COLS;

    /** 머리행으로 인정하는 최소 조건 (아래 detectHeaderRow 의 두 관문) */
    private const HEAD_MIN_HITS  = 2;   // 종류별 동의어에 맞는 칸이 이만큼은 있어야 한다
    private const HEAD_MIN_CELLS = 3;   // 동의어가 안 맞을 때의 대안 — 채워진 칸 수

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
            /* 같은 칸에 두 열이 이어지면 **앞의 열**이 이긴다 (2차 보정).
             * 이카운트 거래처 파일은 「전화」와 「모바일」 을 둘 다 주는데, 둘 다 `tel` 로
             * 가면 뒤엣것이 앞엣것을 덮어써 **대표번호 대신 휴대폰이 남는다**.
             * 머리행 순서대로 도는 매핑에서 앞의 것을 남기면 「전화」가 우선한다. */
            if (!array_key_exists($target, $resolved)) {
                $resolved[$target] = $columns[$column];
            }
        }

        foreach (self::REQUIRED[$kind] as $need) {
            if (!array_key_exists($need, $resolved)) {
                throw new RuntimeException('mapping_incomplete');
            }
        }

        return $resolved;
    }

    /**
     * 파생 모드(`derive=location_name`)에서 쓸 매핑으로 **추려 준다** (2차 보정 2026-09-13).
     *
     * 화면은 창고(`locations`) 매핑을 그대로 들고 있다 — 「구분→kind」·「사용→active」 처럼
     * 지점(`branches`)에는 없는 칸이 섞여 있어, 그대로 resolve 하면 `bad_mapping` 400 이다.
     * 사람에게는 **같은 화면에서 단추 하나를 누른 것**이므로, 매핑을 다시 고르게 하는 대신
     * 지점에 있는 칸만 남긴다. 없는 칸을 조용히 **버리는** 것이지 지어내지 않는다.
     *
     * @param array<string,string> $mapping {엑셀 열 이름 → 우리 칸 이름}
     * @return array<string,string>
     */
    public static function mappingForDerive(array $mapping): array
    {
        $allowed = self::TARGETS['branches'];
        $out     = [];
        foreach ($mapping as $column => $target) {
            if (!is_string($column) || !is_string($target)) {
                continue;
            }
            if ($target === '' || $target === 'skip' || in_array($target, $allowed, true)) {
                $out[$column] = $target;
            }
        }
        return $out;
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
            } elseif (in_array($key, self::SKIP_ALIASES, true)) {
                // 우리 칸이 없는 열이라는 것을 **명시**한다 (빠뜨린 것과 구별된다).
                $out[$key] = 'skip';
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
            // 이카운트 품목 내보내기의 실제 열 이름 (1차 보정 2026-09-13, files #252 실측).
            // 포장 낱개 수가 분자/분모 두 칸으로 나뉘어 있다 — 분모는 아래 judgeItem 이 본다.
            '당수량(분자)' => 'pack_qty', '당수량(분모)' => 'pack_den',
            // 3차 보정 — 품목 파일에 거래처 코드가 함께 있으면 이름 대신 코드로 붙인다.
            '구매처코드' => 'vendor_code', '거래처코드' => 'vendor_code',
        ],
        'vendors' => [
            '거래처코드' => 'code', '거래처명' => 'name', '구매처명' => 'name', '사업자번호' => 'biz_no',
            // 2차 보정 — 이카운트 거래처 내보내기(ESA001M)의 실제 열 이름.
            // 「전화」와 「모바일」이 둘 다 오면 **전화**를 쓴다(아래 resolve 가 앞의 것을 남긴다).
            '대표자명' => 'ceo_name', '전화' => 'tel', '모바일' => 'tel',
            '사용구분' => 'active', '거래처그룹1명' => 'skip', '검색창내용' => 'skip',
            '이체정보' => 'skip',
        ],
        'locations' => [
            '지점' => 'branch', '지점명' => 'branch', '장소' => 'name', '장소명' => 'name',
            '용도' => 'kind', '장소코드' => 'code', '이전이름' => 'legacy_name',
            // 2차 보정 — 이카운트 창고 내보내기(ESA005M)의 실제 열 이름.
            '창고코드' => 'code', '창고명' => 'name', '구분' => 'kind', '사용' => 'active',
            '생산공정명' => 'skip', '외주거래처명' => 'skip', '추가사업장명' => 'skip',
        ],
        'branches' => [
            '지점명' => 'name', '지점' => 'name', '브랜드' => 'brand', '지점코드' => 'code',
            '창고명' => 'name', '창고코드' => 'code', '사용' => 'active',
        ],
    ];

    /**
     * **건너뜀이 기본값**인 열 이름 (1차 보정 2026-09-13).
     *
     * 우리 칸에 대응이 없는 열은 어차피 매핑에서 빠지지만, 이 목록의 이름은 화면에
     * `건너뜀` 으로 **명시해 둔다** — 사람이 "이 열은 왜 비어 있지" 하고 다시 보지 않게.
     * 종류와 무관하게 같은 뜻이라 한 목록에 모은다.
     *
     * @var list<string>
     */
    private const SKIP_ALIASES = ['최초작성자', '회사명', '최종수정자', '비고', '이미지'];

    /** 「사용 / 사용구분」 칸이 **참**으로 읽히는 값 (그 밖은 거짓) */
    private const ACTIVE_TRUE = ['yes', 'y', 'true', '1', 'o', '사용', '사용함', '예'];

    /**
     * **머리행이 몇 번째 행인가** 를 서버가 정한다 (1차 보정 2026-09-13).
     *
     * 실제 이카운트 파일(ESA009M.xlsx, 8,102행)의 모양:
     *   0행  ["회사명 : 주식회사 …"]                      ← 제목 한 칸
     *   1행  ["구매처명","대분류",…,"품목코드","품목명",…]  ← **진짜 머리행**
     *   2행~ 데이터
     * 0행을 머리행으로 잡으면 열이 하나뿐이라 매핑을 채울 수 없다. 그래서 **앞 20행을
     * 훑어** 머리행처럼 보이는 행을 고른다.
     *
     * 고르는 규칙 (순서대로)
     *   ① 종류별 동의어(ALIASES·TARGETS)에 맞는 칸이 **2개 이상**인 행 중,
     *      비어 있지 않은 칸이 **가장 많은** 행. 같으면 **앞의 행**(머리말이 아래로 갈수록
     *      데이터에 가까워지므로, 같은 점수면 위쪽이 머리행일 가능성이 높다).
     *   ② 그런 행이 없으면 — 비어 있지 않은 칸이 **3개 이상**인 **첫** 행.
     *   ③ 그것도 없으면 0 (판단을 포기하고 사람에게 넘긴다. 화면이 셀렉트로 고치게 한다).
     *
     * **추측일 뿐이다.** 최종 결정권은 사람에게 있고(화면의 「머리행」 셀렉트), 미리보기·확정은
     * 요청이 보낸 `header_row` 를 그대로 쓴다. 서버가 다시 감지하는 것은 값이 없을 때뿐이다.
     *
     * @param list<list<string>> $rows Sheet::rows 의 결과
     */
    public static function detectHeaderRow(string $kind, array $rows): int
    {
        if (!in_array($kind, self::KINDS, true) || $rows === []) {
            return 0;
        }

        $bestRow   = -1;
        $bestCells = -1;
        $fallback  = -1;

        $limit = min(count($rows), self::HEAD_ROWS);
        for ($i = 0; $i < $limit; $i++) {
            $row = is_array($rows[$i]) ? $rows[$i] : [];

            $cells = 0;
            foreach ($row as $cell) {
                if (trim((string) $cell) !== '') {
                    $cells++;
                }
            }
            if ($cells === 0) {
                continue;
            }

            // ① 이 행을 머리행으로 봤을 때 우리 칸에 이어지는 열이 몇 개인가
            $hits = count(self::guessMapping($kind, $row));
            if ($hits >= self::HEAD_MIN_HITS && $cells > $bestCells) {
                $bestRow   = $i;
                $bestCells = $cells;
            }

            // ② 대안 — 채워진 칸이 어느 정도 있는 첫 행
            if ($fallback < 0 && $cells >= self::HEAD_MIN_CELLS) {
                $fallback = $i;
            }
        }

        if ($bestRow >= 0) {
            return $bestRow;
        }
        return $fallback >= 0 ? $fallback : 0;
    }

    /**
     * 요청이 보낸 머리행 번호를 좁힌다 — 0~19 정수만. 그 밖(음수·20 이상·숫자 아님)은 예외.
     * 없으면(null) 서버가 다시 감지한다 — 화면이 값을 안 보내도 동작이 달라지지 않게.
     *
     * @param list<list<string>> $rows
     */
    public static function headerRowFrom(mixed $raw, string $kind, array $rows): int
    {
        if ($raw === null) {
            return self::detectHeaderRow($kind, $rows);
        }
        $text = is_int($raw) ? (string) $raw : (is_string($raw) ? trim($raw) : '');
        if (preg_match('/^[0-9]{1,2}$/', $text) !== 1) {
            throw new RuntimeException('bad_header_row');
        }
        $value = (int) $text;
        if ($value < 0 || $value >= self::HEAD_ROWS) {
            throw new RuntimeException('bad_header_row');
        }
        return $value;
    }

    /**
     * 화면이 **재업로드 없이** 머리행을 바꿔 볼 수 있게, 앞 20행 × 60열을 그대로 돌려준다.
     * 값은 이미 `Sheet` 가 UTF-8 로 씻어 500자로 자른 문자열이고, 화면은 `textContent` 로만 넣는다.
     *
     * @param list<list<string>> $rows
     * @return list<list<string>>
     */
    public static function headRows(array $rows): array
    {
        $out   = [];
        $limit = min(count($rows), self::HEAD_ROWS);
        for ($i = 0; $i < $limit; $i++) {
            $row  = is_array($rows[$i]) ? $rows[$i] : [];
            $cut  = array_slice($row, 0, self::HEAD_COLS);
            $line = [];
            foreach ($cut as $cell) {
                $line[] = (string) $cell;
            }
            $out[] = $line;
        }
        return $out;
    }

    /**
     * 행마다 판정한다 — DB 를 **읽기만** 한다. 아무것도 쓰지 않는다.
     *
     * @param list<list<string>>  $rows      Sheet::rows 의 결과 (전체 행)
     * @param array<string,int>   $resolved  resolve() 의 결과
     * @param int                 $headerRow 머리행의 위치(0기준). 그 **다음 행부터** 데이터다
     * @param array<string,mixed> $options   2차 보정 — 화면이 정한 기본값들:
     *        `default_branch`(?int) 지점 열이 없을 때 쓸 지점 ·
     *        `default_brand`(?int)  브랜드 열이 없을 때 쓸 브랜드 ·
     *        `derive`(?string)      `location_name` 이면 창고명에서 지점 이름을 뽑는다
     * @return array{counts:array<string,int>, rows:list<array<string,mixed>>, total:int}
     */
    public static function judge(string $kind, array $rows, array $resolved, int $headerRow = 0, array $options = []): array
    {
        // 머리행 **위**의 제목 줄(이카운트의 `회사명 : …`)도 함께 건너뛴다 — 데이터는
        // 머리행 다음부터다. 그래서 자르는 자리는 1 이 아니라 `머리행 + 1` 이다.
        $body = array_slice($rows, $headerRow + 1);
        if (count($body) > self::MAX_ROWS) {
            throw new RuntimeException('too_many_rows');
        }

        $lookup = self::lookups($kind);
        $counts = [self::STATUS_INSERT => 0, self::STATUS_UPDATE => 0,
                   self::STATUS_REVIEW => 0, self::STATUS_ERROR => 0];
        $seen   = [];     // 파일 안 중복 검사용 열쇠 모음
        $out    = [];

        $derive        = isset($options['derive']) && is_string($options['derive']) ? $options['derive'] : '';
        $defaultBranch = isset($options['default_branch']) ? (int) $options['default_branch'] : 0;
        $defaultBrand  = isset($options['default_brand']) ? (int) $options['default_brand'] : 0;
        /* 5차 보정 — 창고 가져오기 **한 흐름**에서 없는 브랜드·지점을 함께 만든다.
         * 화면의 체크박스 둘이며, 켜져 있으면 판정이 「만들 계획」을 세운다(아직 쓰지 않는다). */
        $createBrands   = isset($options['create_brands']) && $options['create_brands'] === true;
        $createBranches = isset($options['create_branches']) && $options['create_branches'] === true;
        /* 9차 보정 2026-09-14 — 품목 가져오기의 짝. 창고에만 있던 「함께 만들기」 를 품목에도 둔다.
         * `items` 가 아닌 종류에는 뜻이 없으므로 **여기서 끈다**(핸들러가 받아도 무시된다). */
        $createCategories = $kind === 'items' && ($options['create_categories'] ?? null) === true;
        $createVendors    = $kind === 'items' && ($options['create_vendors'] ?? null) === true;

        /* 「이번 확정으로 새로 만들 것」 계획 — 판정하며 쌓이고, 확정이 그대로 만든다.
         * 아직 id 가 없는 것은 **음수 임시 id**로 가리키고(nextTemp), 확정이 진짜 id 로 바꿔 넣는다.
         * 같은 이름은 lookup 에 함께 넣어 두므로 뒤의 행이 **그 임시 id 를 재사용**한다 —
         * 파일에 8,100행이 있어도 분류·거래처는 고유한 만큼만 생긴다. */
        $createPlan = ['vendors' => [], 'categories' => [], 'next_temp' => -1];

        /* ─── 지점·브랜드가 어디서 오는지 먼저 확인한다 (2차 보정) ───
         *
         * 행마다 오류를 내지 않고 **미리보기 자체를 400 으로 끝낸다**: 98행짜리 창고 파일에
         * "지점을 모르겠다" 를 98번 적어 보여 주는 것은 사람에게 쓸모가 없다. 한 번에
         * "기본 지점을 고르세요" 라고 말하는 편이 낫다(매핑이 모자랄 때와 같은 판단).
         */
        /* 5차 보정 — 「지점 만들기」 가 켜져 있으면 기본 지점이 없어도 된다.
         * 창고명에서 지점을 뽑아 **행마다 다른 지점**으로 만들 수 있기 때문이다
         * (그것이 "여러 지점의 창고를 한 지점으로밖에 못 넣는다" 는 문제의 해답이다).
         * 판별이 안 되는 행만 기본 지점을 쓰고, 그것도 없으면 그 행이 「확인 필요」다. */
        if ($kind === 'locations'
            && !array_key_exists('branch', $resolved) && $defaultBranch <= 0 && !$createBranches) {
            throw new RuntimeException('default_branch_required');
        }
        if ($kind === 'branches'
            && !array_key_exists('brand', $resolved) && $defaultBrand <= 0) {
            throw new RuntimeException('default_brand_required');
        }

        /* ─── 판정할 행을 먼저 고른다 ───
         *
         * 파생 모드(`derive=location_name`)에서는 **서로 다른 지점 이름만** 남긴다.
         * 창고 98행에서 지점 12개가 나온다면 사람이 볼 것은 12줄이지 98줄이 아니다
         * (지시 4 「중복 제거 목록을 화면에 보여 주고」).
         */
        $prepared   = [];
        $seenDerive = [];
        foreach ($body as $offset => $row) {
            // 엑셀에서 **사람이 보는** 줄 번호 (1부터). 머리행이 1행이면 데이터는 3행부터다.
            $rowNo = $offset + $headerRow + 2;
            $data  = self::pick($row, $resolved);

            if ($derive === self::DERIVE_LOCATION_NAME) {
                $derived = self::branchNameFrom((string) ($data['name'] ?? ''));
                if ($derived === '') {
                    continue;   // 뽑을 이름이 없는 줄은 아예 보여 주지 않는다
                }
                $dkey = self::nameKey($derived);
                if (isset($seenDerive[$dkey])) {
                    continue;   // 같은 지점이 여러 창고에 걸쳐 나온다 — 한 번만 센다
                }
                $seenDerive[$dkey] = true;
                $data['name']      = $derived;
                $data['derived_from'] = (string) (self::pick($row, $resolved)['name'] ?? '');
            }

            $prepared[] = [$rowNo, $data];
        }

        foreach ($prepared as $entry) {
            [$rowNo, $data] = $entry;

            $judged = match ($kind) {
                'items'     => self::judgeItem($data, $lookup, $seen, [
                    'create_categories' => $createCategories,
                    'create_vendors'    => $createVendors,
                ], $createPlan),
                'vendors'   => self::judgeVendor($data, $lookup, $seen),
                'locations' => self::judgeLocation($data, $lookup, $seen, $defaultBranch, [
                    'create_brands'   => $createBrands,
                    'create_branches' => $createBranches,
                    'default_brand'   => $defaultBrand,
                ]),
                'branches'  => self::judgeBranch($data, $lookup, $seen, $defaultBrand),
                default     => ['status' => self::STATUS_ERROR, 'reason' => 'bad_kind', 'data' => $data],
            };

            $counts[$judged['status']]++;
            $judged['row_no'] = $rowNo;
            $out[] = $judged;
        }

        /* 「이번 확정으로 **새로 생길 것**」 요약 (5차 보정).
         * 화면이 확정 전에 「새로 만들 브랜드 3 · 지점 5」 로 보여 준다 — 사람이 무엇이
         * 생기는지 모르고 누르는 일이 없게. 이름은 소문자 기준으로 한 번만 센다. */
        $toCreate = ['brands' => [], 'branches' => []];
        foreach ($out as $row) {
            $plan = $row['plan'] ?? null;
            if (!is_array($plan)) {
                continue;
            }
            if (!empty($plan['brand_name'])) {
                $toCreate['brands'][strtolower((string) $plan['brand_name'])] = (string) $plan['brand_name'];
            }
            if (!empty($plan['branch_name'])) {
                $key = strtolower((string) $plan['branch_name']);
                if (!isset($toCreate['branches'][$key])) {
                    $toCreate['branches'][$key] = [
                        'name'  => (string) $plan['branch_name'],
                        'brand' => $plan['brand_name'] ?? null,
                    ];
                }
            }
        }

        /* 9차 보정 — 품목 가져오기가 함께 만들 **분류·거래처**.
         * 이름 목록은 화면에 다 뿌리지 않는다(157개가 예사다) — 앞 20개만 보여 주고 수는 정확히
         * 센다. 확정이 만드는 것은 `plan` 전부이며, 이 목록은 그것을 사람에게 보여 주는 창이다. */
        $newCategories = [];
        foreach (array_slice($createPlan['categories'], 0, self::CREATE_PREVIEW_NAMES) as $cat) {
            $newCategories[] = [
                'level'       => (int) $cat['level'],
                'name'        => (string) $cat['name'],
                'parent_name' => $cat['parent_name'] === null ? null : (string) $cat['parent_name'],
            ];
        }
        $newVendors = [];
        foreach (array_slice($createPlan['vendors'], 0, self::CREATE_PREVIEW_NAMES) as $ven) {
            $newVendors[] = (string) $ven['name'];
        }

        return [
            'counts'    => $counts,
            'rows'      => $out,
            'total'     => count($prepared),
            // 확정이 그대로 쓰는 **계획 원본**(임시 id 포함). 응답에는 싣지 않는다.
            // ★ 이름이 `$plan` 이 아니라 `$createPlan` 인 이유: 바로 위 창고 요약 루프가
            //   행마다의 `$row['plan']` 을 `$plan` 에 담아 쓴다(5차 보정). 같은 이름을 쓰면
            //   그 루프가 이 계획을 덮어써 품목 계획이 통째로 사라진다.
            'plan'      => $createPlan,
            'to_create' => [
                'brands'     => array_values($toCreate['brands']),
                'branches'   => array_values($toCreate['branches']),
                'categories' => $newCategories,
                'vendors'    => $newVendors,
            ],
            'new_categories' => count($createPlan['categories']),
            'new_vendors'    => count($createPlan['vendors']),
        ];
    }

    /**
     * 확정 — **한 트랜잭션**. 오류 행은 저장하지 않고, 중간에 하나라도 실패하면 전부 롤백된다.
     * 트랜잭션은 호출자(핸들러)가 연다 — 감사 기록까지 같은 트랜잭션에 묶기 위해서다.
     *
     * @param list<array<string,mixed>> $judged  judge() 의 rows
     * @param list<int>                 $promote review → 원래 하려던 일로 승격할 row_no 목록
     * @param list<int>                 $exclude **저장하지 않을** row_no 목록 (3차 보정).
     *        화면의 행 체크박스를 끈 행들이다 — 창고명에서 뽑은 지점 후보 중 「임시」·「상담실」
     *        처럼 지점이 아닌 것을 빼는 용도가 첫 번째다. 판정을 바꾸지 않고 **쓰기만 건너뛴다**
     *        (미리보기에서 본 판정과 확정의 판정이 어긋나지 않게).
     * @param array<string,mixed>       $plan    judge() 가 세운 「확정 때 만들 것」 (9차 보정).
     *        `vendors`·`categories` 에 **음수 임시 id** 로 적혀 있고, 여기서 진짜 행을 만든 뒤
     *        품목 행의 `vendor_id`·`cat*_id` 를 그 id 로 바꿔 넣는다. 전부 **이 트랜잭션 안**이다.
     *
     *        ★ 계획은 **판정 전체 기준**이라 `exclude_rows` 로 뺀 행의 분류·거래처도 만들어진다.
     *          미리보기가 「새 분류 N · 새 거래처 N」 이라고 말한 그 수가 그대로 생기는 편이,
     *          체크 하나를 끌 때마다 생길 것이 달라지는 것보다 사람에게 예측 가능하다.
     *          (창고 쪽 `plan` 은 행마다 붙어 있어 반대 규칙이다 — 그쪽은 뺀 창고 때문에 지점이
     *          생기면 안 된다는 요구가 먼저였다.)
     * @return array{inserted:int, updated:int, skipped:int, excluded:int}
     */
    public static function commit(
        string $kind,
        array $judged,
        array $promote,
        int $actorId,
        array $exclude = [],
        array $plan = []
    ): array {
        $inserted = 0;
        $updated  = 0;
        $skipped  = 0;
        $excluded = 0;

        // row_no 로 빠르게 찾도록 뒤집어 둔다(행 수천 개 × 제외 목록 수백 개면 중첩 비교가 느리다)
        $excludeSet = [];
        foreach ($exclude as $no) {
            $excludeSet[(int) $no] = true;
        }

        /* ─── 5차 보정 — 브랜드 → 지점 → 창고 **순서로** 만든다 ───
         *
         * 사용자 관찰: "여러 지점의 창고를 한 지점으로밖에 등록하지 못한다."
         * 창고 한 번 가져오기로 **브랜드와 지점까지** 생기게 한다. 순서가 중요하다 —
         * 지점은 브랜드를 가리키고 창고는 지점을 가리키므로, 앞의 것이 먼저 있어야 한다.
         *
         * 이 전부가 **호출자가 연 한 트랜잭션 안**이다: 창고 한 줄이 실패하면 방금 만든
         * 브랜드·지점까지 함께 되돌아간다. "지점만 생기고 창고는 안 들어온" 반쪽 상태를
         * 만들지 않는다(F-29 의 한 트랜잭션 약속).
         *
         * 제외된 행(`exclude_rows`)의 계획은 **세우지 않는다** — 사람이 빼기로 한 창고
         * 때문에 지점이 생기면 안 된다.
         */
        $newBrandIds  = [];   // 소문자 이름 → 방금 만든 브랜드 id
        $newBranchIds = [];   // 소문자 이름 → 방금 만든 지점 id
        $brandsCreated   = 0;
        $branchesCreated = 0;

        /* ─── 9차 보정 — 품목 가져오기: **거래처 → 분류 → 품목** 순서로 만든다 ───
         *
         * 사용자 관찰(2026-09-14): 이카운트 품목 파일 8,100행 중 대부분이 「분류를 찾을 수
         * 없습니다」·「거래처를 찾을 수 없습니다」 오류였다. 분류 157개와 미등록 거래처 11개를
         * 손으로 먼저 만들지 않으면 한 행도 들어가지 않는다.
         *
         * 순서가 중요하다 — 품목은 거래처(NOT NULL)와 분류를 가리키므로 앞의 것이 먼저 있어야
         * 하고, 분류는 자기 부모를 가리키므로 1단 → 2단 → 3단이어야 한다.
         *
         * 이 전부가 **호출자가 연 한 트랜잭션 안**이다: 품목 한 줄이 실패하면 방금 만든 분류·
         * 거래처까지 함께 되돌아간다. "분류만 생기고 품목은 안 들어온" 반쪽 상태를 만들지 않는다
         * (F-29 의 한 트랜잭션 약속 · 창고 쪽 브랜드·지점과 같은 판단).
         */
        $tempMap           = [];   // 음수 임시 id → 방금 만든 진짜 id
        $categoriesCreated = 0;
        $vendorsCreated    = 0;

        if ($kind === 'items') {
            // ① 거래처 — 이름만, 코드는 NULL (파일에 거래처 코드 열이 없다)
            foreach (($plan['vendors'] ?? []) as $newVendor) {
                if (!is_array($newVendor)) {
                    continue;
                }
                $tempMap[(int) $newVendor['temp_id']] =
                    self::insertVendorName((string) $newVendor['name'], $actorId);
                $vendorsCreated++;
            }

            // ② 분류 — 1단 → 2단 → 3단. 부모가 방금 만든 것이면 위에서 받은 진짜 id 로 잇는다.
            for ($level = 1; $level <= 3; $level++) {
                foreach (($plan['categories'] ?? []) as $newCat) {
                    if (!is_array($newCat) || (int) $newCat['level'] !== $level) {
                        continue;
                    }
                    $parentRef = (int) ($newCat['parent'] ?? 0);
                    $parentId  = null;
                    if ($parentRef < 0) {
                        if (!isset($tempMap[$parentRef])) {
                            // 계획이 깨졌다 — 부모 없는 분류를 만들지 않고 전부 되돌린다.
                            throw new RuntimeException('insert_failed');
                        }
                        $parentId = $tempMap[$parentRef];
                    } elseif ($parentRef > 0) {
                        $parentId = $parentRef;
                    }
                    $tempMap[(int) $newCat['temp_id']] =
                        self::insertCategoryRow($level, $parentId, (string) $newCat['name'], $actorId);
                    $categoriesCreated++;
                }
            }
        }

        if ($kind === 'locations') {
            $plans = [];
            foreach ($judged as $row) {
                if (isset($excludeSet[(int) $row['row_no']])) {
                    continue;
                }
                $st = (string) $row['status'];
                if ($st === self::STATUS_ERROR || $st === self::STATUS_REVIEW) {
                    continue;   // 오류·확인 필요 행은 저장하지 않으므로 계획도 세우지 않는다
                }
                if (isset($row['plan']) && is_array($row['plan'])) {
                    $plans[] = $row['plan'];
                }
            }

            // ① 브랜드 — 같은 이름은 한 번만
            foreach ($plans as $plan) {
                $brandName = $plan['brand_name'] ?? null;
                if ($brandName === null) {
                    continue;
                }
                $key = strtolower((string) $brandName);
                if (isset($newBrandIds[$key])) {
                    continue;
                }
                $newBrandIds[$key] = self::insertBrand((string) $brandName, $actorId);
                $brandsCreated++;
            }

            // ② 지점 — 브랜드는 위에서 만든 것 또는 이미 있던 것
            foreach ($plans as $plan) {
                $branchName = $plan['branch_name'] ?? null;
                if ($branchName === null) {
                    continue;
                }
                $key = strtolower((string) $branchName);
                if (isset($newBranchIds[$key])) {
                    continue;
                }
                $brandId = $plan['brand_id'] ?? null;
                if ($brandId === null && !empty($plan['brand_name'])) {
                    $brandId = $newBrandIds[strtolower((string) $plan['brand_name'])] ?? null;
                }
                if ($brandId === null) {
                    // 판정이 막았어야 하는 상태다 — 여기까지 왔으면 전부 되돌린다.
                    throw new RuntimeException('default_brand_required');
                }
                $newBranchIds[$key] = self::insertBranchRow((string) $branchName, (int) $brandId, $actorId);
                $branchesCreated++;
            }
        }

        foreach ($judged as $row) {
            if (isset($excludeSet[(int) $row['row_no']])) {
                $excluded++;
                $skipped++;
                continue;
            }
            $status = (string) $row['status'];
            if ($status === self::STATUS_REVIEW && in_array((int) $row['row_no'], $promote, true)) {
                /* 「확인 필요」를 승격하면 **그 행이 원래 하려던 일**을 한다 (2차 보정).
                 * 전에는 무조건 `update` 로 바꿨는데, 같은 이름의 새 거래처처럼
                 * **대상 행이 없는** 확인거리는 update 할 곳이 없어 실패했다.
                 * 판정 함수가 `promote_to` 에 적어 둔 것을 그대로 따른다. */
                $status = (string) ($row['promote_to'] ?? self::STATUS_UPDATE);
            }
            if ($status === self::STATUS_ERROR || $status === self::STATUS_REVIEW) {
                $skipped++;
                continue;
            }

            $values = is_array($row['values'] ?? null) ? $row['values'] : [];

            /* 방금 만든 거래처·분류를 가리키는 품목 행이면 여기서 진짜 id 로 바꾼다 (9차 보정).
             * 판정 때는 아직 행이 없어 **음수 임시 id** 였다. */
            if ($kind === 'items' && $tempMap !== []) {
                foreach (['vendor_id', 'cat1_id', 'cat2_id', 'cat3_id'] as $column) {
                    $ref = $values[$column] ?? null;
                    if (is_int($ref) && $ref < 0) {
                        if (!isset($tempMap[$ref])) {
                            // 계획에 없던 임시 id — 반쪽 저장을 만들지 않고 전부 되돌린다.
                            throw new RuntimeException('insert_failed');
                        }
                        $values[$column] = $tempMap[$ref];
                    }
                }
            }

            /* 새로 만든 지점을 가리키는 창고 행이면 여기서 id 를 채운다 (5차 보정).
             * 판정 때는 아직 지점이 없어 `branch_id` 가 null 이었다. */
            if ($kind === 'locations' && ($values['branch_id'] ?? null) === null) {
                $planName = $row['plan']['branch_name'] ?? null;
                $filled   = $planName === null ? null : ($newBranchIds[strtolower((string) $planName)] ?? null);
                if ($filled === null) {
                    // 지점을 못 찾으면 저장하지 않는다 — 지점 없는 창고를 만들지 않는다.
                    $skipped++;
                    continue;
                }
                $values['branch_id'] = $filled;
            }

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

        return ['inserted' => $inserted, 'updated' => $updated,
                'skipped' => $skipped, 'excluded' => $excluded,
                // 5차 보정 — 창고 가져오기가 **덤으로 만든** 것들
                'brands_created' => $brandsCreated, 'branches_created' => $branchesCreated,
                // 9차 보정 — 품목 가져오기가 함께 만든 분류·거래처
                'categories_created' => $categoriesCreated, 'vendors_created' => $vendorsCreated];
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
            // 3차 보정 (R-72) — 같은 이름의 거래처가 **몇 개**인지. 이름으로만 붙이는 행은
            // 이 값이 2 이상이면 「확인 필요」다(어느 거래처인지 파일이 말해 주지 않는다).
            $out['vendor_name_count'] = [];
            foreach ($db->run(self::SQL_VENDORS)->fetchAll() as $row) {
                $id   = (int) $row['id'];
                $name = strtolower((string) $row['name']);
                if ($row['code'] !== null && $row['code'] !== '') {
                    $out['vendor_by_code'][strtolower((string) $row['code'])] = $id;
                }
                // 이름이 겹치면 **첫 거래처**가 남는다 — 승격했을 때 붙는 곳과 같게 한다.
                if (!array_key_exists($name, $out['vendor_by_name'])) {
                    $out['vendor_by_name'][$name] = $id;
                }
                $out['vendor_name_count'][$name] = ($out['vendor_name_count'][$name] ?? 0) + 1;
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

        if ($kind === 'locations' || $kind === 'branches') {
            $out['branch'] = [];
            // 2차 보정 — 지점 판정(창고명 부분 문자열 대조·중복 확인)에 행 전체가 필요하다.
            $out['branch_row'] = [];
            foreach ($db->run(self::SQL_BRANCHES)->fetchAll() as $row) {
                $key = strtolower((string) $row['name']);
                $out['branch'][$key]     = (int) $row['id'];
                $out['branch_row'][$key] = $row;
            }
        }

        if ($kind === 'branches') {
            $out['brand'] = [];
            foreach ($db->run(self::SQL_BRANDS)->fetchAll() as $row) {
                $out['brand'][strtolower((string) $row['name'])] = (int) $row['id'];
            }
        }

        if ($kind === 'locations') {
            $out['location'] = [];   // "지점id|이름(소문자)" → 현재 행
            foreach ($db->run(self::SQL_LOCATIONS)->fetchAll() as $row) {
                $out['location'][(string) (int) $row['branch_id'] . '|' . strtolower((string) $row['name'])] = $row;
            }
        }

        return $out;
    }

    // 2차 보정 — **중단된 거래처도 읽는다.** 이카운트에서 「사용구분 NO」 로 받아 중단해 둔
    // 거래처를 다시 가져올 때, 활성 행만 보면 "없다" 고 판단해 같은 코드를 또 넣으려다
    // UNIQUE(code) 에 부딪힌다. 정체(코드)로 찾을 때는 상태를 보지 않는 것이 맞다.
    private const SQL_VENDORS = <<<'SQL'
        SELECT id, code, name, biz_no, ceo_name, tel, is_active FROM p_vendors
        SQL;

    private const SQL_CATEGORIES = <<<'SQL'
        SELECT id, parent_id, name, level FROM p_categories WHERE is_active
        SQL;

    private const SQL_BRANDS = <<<'SQL'
        SELECT id, name FROM p_brands WHERE is_active
        SQL;

    private const SQL_BRANCHES = <<<'SQL'
        SELECT id, brand_id, name, code, is_active FROM p_branches WHERE is_active
        SQL;

    private const SQL_ITEMS = <<<'SQL'
        SELECT id, vendor_id, code, name, spec, unit, pack_qty, barcode,
               cat1_id, cat2_id, cat3_id, stock_managed, safety_qty, lead_days,
               min_order_qty, CAST(price_in AS text) AS price_in, brand_id
          FROM p_items
        SQL;

    private const SQL_LOCATIONS = <<<'SQL'
        SELECT id, branch_id, kind, name, code, legacy_name, is_active FROM p_locations
        SQL;

    /**
     * 품목 한 행 (지시서 보충 7 · 9차 보정 2026-09-14).
     *   · 거래처를 코드 또는 이름으로 찾는다 → 이름이 **비면** error `vendor_missing`,
     *     이름은 있는데 없으면 error `vendor_not_found` (옵션 `create_vendors` 가 켜져 있으면
     *     오류 대신 「확정 때 만들 거래처」로 계획한다), 같은 이름이 둘 이상이면 review
     *     `vendor_ambiguous` — **이 경우는 옵션과 무관하게 새로 만들지 않는다** (SECURITY R-72)
     *   · 단위가 비면 **오류가 아니다** — `unit` 을 NULL 로 둔다 (사용자 결정 2026-09-14)
     *   · 분류 이름이 있는데 그 단계에 없으면 error `category_not_found` (옵션
     *     `create_categories` 가 켜져 있으면 오류 대신 「확정 때 만들 분류」로 계획한다)
     *   · 같은 (거래처, 코드)가 **이 파일 안에** 또 있으면 error `duplicate_in_file`
     *   · DB 에 이미 있으면 update(달라진 칸만), 이름만 다르면 review
     *   · 없으면 insert
     *
     * ★ 왜 「빈 단위」가 오류가 아닌가 (2026-09-14 실측·사용자 결정)
     *   이카운트 품목 파일 8,100행 중 **2,678행의 단위가 빈칸**이다. 그것이 원본의 사실이고,
     *   우리가 「EA」나 「-」로 메우면 없던 정보를 지어내는 것이다. `p_items.unit` 은 NULL 허용이며
     *   `Doc.php`·`Stock.php`·화면 셋 다 이미 `unit === null` 을 다룬다.
     *
     * ★ 왜 거래처 빈칸만은 끝까지 오류인가
     *   `p_items.vendor_id` 는 NOT NULL 이다. 「미지정 거래처」 같은 가짜 행에 붙이면 그 순간
     *   발주서가 갈 곳을 잃고, 그 오류는 발주를 내보내기 전까지 드러나지 않는다.
     *
     * @param array<string,string>              $d
     * @param array<string,array<string,mixed>> $lookup  **참조** — 계획한 분류·거래처를 여기 넣어
     *        두어야 뒤의 행이 같은 것을 또 만들지 않는다
     * @param array<string,bool>                $seen
     * @param array<string,bool>                $opts   create_categories · create_vendors
     * @param array<string,mixed>               $plan   확정이 만들 것들 (참조로 쌓인다)
     * @return array<string,mixed>
     */
    private static function judgeItem(
        array $d,
        array &$lookup,
        array &$seen,
        array $opts = [],
        array &$plan = []
    ): array {
        $code = $d['code'] ?? '';
        $name = $d['name'] ?? '';
        if ($code === '' || $name === '') {
            return self::fail('required_missing', $d);
        }

        /* ─── 거래처 붙이기 (3차 보정 2026-09-13, SECURITY R-72) ───
         *
         * 2차 보정에서 거래처 이름의 유일 제약을 없앴다(같은 상호로 사업자를 나눈 거래처가
         * 실제로 있다). 그 결과 **이름만으로는 어느 거래처인지 정할 수 없는** 행이 생긴다.
         *   · 파일에 거래처 **코드** 열이 있으면 그것으로 정한다 — 모호할 일이 없다.
         *   · 코드가 없으면 이름으로 찾되, 같은 이름이 **둘 이상**이면 「확인 필요」로 세운다.
         *     조용히 첫 거래처에 붙이면 품목 수천 건이 엉뚱한 거래처로 묶이고, 그 오류는
         *     발주서를 내보내기 전까지 드러나지 않는다.
         */
        $createVendors    = ($opts['create_vendors'] ?? false) === true;
        $createCategories = ($opts['create_categories'] ?? false) === true;

        $vendorCodeText = trim($d['vendor_code'] ?? '');
        $vendorText     = trim($d['vendor'] ?? '');
        $vendorAmbiguous = 0;

        if ($vendorCodeText !== '') {
            $vendorId = $lookup['vendor_by_code'][strtolower($vendorCodeText)] ?? null;
            if ($vendorId === null) {
                /* 코드가 적혀 있는데 그 코드가 우리에게 없다 — **이름만 보고 만들지 않는다.**
                 * 코드가 그 거래처의 정체이고, 우리가 만드는 거래처에는 코드를 넣지 않는다
                 * (남의 코드 체계를 우리 표에 복제하는 것은 사람이 정할 일이다). */
                return self::fail('vendor_not_found', $d, ['kind' => 'vendors', 'value' => $vendorText]);
            }
        } elseif ($vendorText === '') {
            // 거래처 칸이 **비어 있다** — vendor_id 는 NOT NULL 이고 가짜 거래처를 만들지 않는다.
            return self::fail('vendor_missing', $d);
        } else {
            $byCode = $lookup['vendor_by_code'][strtolower($vendorText)] ?? null;
            $byName = $lookup['vendor_by_name'][strtolower($vendorText)] ?? null;
            $vendorId = $byCode ?? $byName;
            if ($vendorId === null) {
                if (!$createVendors) {
                    return self::fail('vendor_not_found', $d, ['kind' => 'vendors', 'value' => $vendorText]);
                }
                // 옵션이 켜져 있다 — 확정 때 **이름만** 있는 거래처로 만든다(코드 NULL).
                $vendorId = self::planVendor($vendorText, $lookup, $plan);
            } elseif ($byCode === null) {
                $vendorAmbiguous = (int) ($lookup['vendor_name_count'][strtolower($vendorText)] ?? 1);
            }
        }
        /* 같은 이름이 **이미 둘 이상** 있으면 옵션이 켜져 있어도 새로 만들지 않는다 (R-72) —
         * 위에서 이름으로 찾은 첫 거래처를 쥐고 review 로 세운다(아래 $flagReason). */

        // 단위 빈칸은 오류가 아니다 — 원본 그대로 NULL 로 둔다 (위 ★, 사용자 결정 2026-09-14).
        $unit = $d['unit'] ?? '';

        // 분류 3단 — 위에서부터 이름으로 찾아 내려간다. 빈 칸은 "없음" 이고 오류가 아니다.
        $catIds     = [null, null, null];
        $catNew     = [];
        $parent     = 0;
        $parentName = null;
        for ($level = 1; $level <= 3; $level++) {
            $label = $d['cat' . $level] ?? '';
            if ($label === '') {
                break;   // 중간이 비면 그 아래도 보지 않는다
            }
            $key = (string) $level . '|' . (string) $parent . '|' . strtolower($label);
            $id  = $lookup['category'][$key] ?? null;
            if ($id === null) {
                if (!$createCategories) {
                    // 3차 보정 — **몇 단계의 어떤 이름**이 없었는지 알려 준다. 화면이 그 값으로
                    // 분류 등록 모달을 채운다(부모까지 함께 넘겨 같은 자리에 만들게 한다).
                    return self::fail('category_not_found', $d, [
                        'kind'      => 'categories',
                        'value'     => $label,
                        'level'     => $level,
                        'parent_id' => $parent > 0 ? $parent : null,
                    ]);
                }
                // 옵션이 켜져 있다 — 확정 때 **이 자리에**(같은 부모 밑에) 만든다.
                $id = self::planCategory($level, $parent, $label, $parentName, $lookup, $plan);
            }
            if ($id < 0) {
                // 아직 없는 분류(임시 id)다 — 화면이 「분류 새로」 배지를 세운다.
                $catNew[] = ['level' => $level, 'name' => $label, 'parent_name' => $parentName];
            }
            $catIds[$level - 1] = $id;
            $parent     = $id;
            $parentName = $label;
        }

        /* 이 행이 「새로 만들 것」에 기대고 있나 — 미리보기 표의 작은 배지 재료다.
         * 값은 파일에 적힌 글자 그대로이고, 화면은 textContent 로만 넣는다. */
        $flags = [];
        if ($vendorId < 0) {
            $flags['vendor_new'] = $vendorText;
        }
        if ($catNew !== []) {
            $flags['category_new'] = $catNew;
        }

        $values = [
            'vendor_id'     => $vendorId,
            'code'          => self::cut($code, 60),
            'name'          => self::cut($name, 200),
            'spec'          => self::orNull($d['spec'] ?? ''),
            // 빈 단위는 **빈 값 그대로**(NULL) — 원본과 같게 (사용자 결정 2026-09-14).
            'unit'          => $unit === '' ? null : self::cut($unit, 40),
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

        /* ─── 포장 낱개 수의 분자/분모 (1차 보정 2026-09-13, 이카운트 실측) ───
         *
         * 이카운트는 「당수량(분자)」·「당수량(분모)」 두 칸으로 내보낸다.
         *   분모 = 1  →  분자가 곧 낱개 수다. 그대로 쓴다(아무 일도 일어나지 않는다).
         *   분모 ≠ 1  →  **사람이 확인해야 한다.** 분자가 분모로 나누어떨어지면 그 몫을
         *                 `pack_qty` 로 **제안**하고, 아니면 분자를 그대로 두되
         *                 어느 쪽이든 판정을 `review` 로 내린다.
         *
         * 왜 자동으로 확정하지 않나: 포장 낱개 수는 발주 수량·재고 계산의 분모다. 여기를
         * 잘못 넣으면 "2000낱개를 주문했는데 1봉이 왔다" 가 되고, 그 오류는 재고를 맞춰 보기
         * 전에는 드러나지 않는다. 값이 흔들리는 행은 **사람 눈을 한 번 거치게** 한다.
         */
        $packDenRaw  = trim((string) ($d['pack_den'] ?? ''));
        $packDenFlag = false;
        if ($packDenRaw !== '' && $packDenRaw !== '1') {
            $den = self::intOr($packDenRaw, 0, 1, 9999999);
            $num = self::intOr($d['pack_qty'] ?? '', 0, 1, 9999999);
            if ($den > 1 && $num > 0 && $num % $den === 0) {
                $values['pack_qty'] = intdiv($num, $den);
            }
            $packDenFlag = true;
        }

        $key = (string) $vendorId . '|' . strtolower($code);
        if (isset($seen[$key])) {
            return self::fail('duplicate_in_file', $d);
        }
        $seen[$key] = true;

        /* 확인거리 우선순위 (3차 보정): 거래처가 모호한 것이 포장 수량보다 급하다 —
         * 포장 수량이 틀리면 수량이 어긋나지만, 거래처가 틀리면 **다른 회사에 발주**한다. */
        $flagReason = null;
        if ($vendorAmbiguous > 1) {
            $flagReason = 'vendor_ambiguous';
        } elseif ($packDenFlag) {
            $flagReason = 'pack_den_not_one';
        }

        $current = $lookup['item'][$key] ?? null;
        if ($current === null) {
            $row = [
                'status' => $flagReason !== null ? self::STATUS_REVIEW : self::STATUS_INSERT,
                'reason' => $flagReason,
                'data'   => $d,
                'values' => $values,
                // 승격하면 **새로 등록**한다 — 대상 행이 없으므로 update 할 곳이 없다 (2차 보정).
                'promote_to' => self::STATUS_INSERT,
            ];
            if ($vendorAmbiguous > 1) {
                $row['vendor_count'] = $vendorAmbiguous;   // 화면이 「같은 이름 N개」 로 보여 준다
            }
            if ($flags !== []) {
                $row['flags'] = $flags;
            }
            return $row;
        }

        $diff = self::diff($current, $values);
        // 이름만 다르면 「확인 필요」 — 원본에 이름이 빠졌거나 표기가 흔들린 경우가 많아
        // 자동으로 덮어쓰면 기존 이름이 조용히 사라진다 (시안 ⑯ 19행).
        $status = (array_keys($diff) === ['name']) ? self::STATUS_REVIEW : self::STATUS_UPDATE;
        $reason = $status === self::STATUS_REVIEW ? 'name_differs' : null;
        if ($flagReason !== null) {
            $status = self::STATUS_REVIEW;
            $reason = $flagReason;   // 거래처 모호 > 포장 수량 > 이름 다름
        }

        $row = [
            'status'    => $status,
            'reason'    => $reason,
            'data'      => $d,
            'values'    => $values,
            'diff'      => $diff,
            'target_id' => (int) $current['id'],
            'promote_to' => self::STATUS_UPDATE,
        ];
        if ($vendorAmbiguous > 1) {
            $row['vendor_count'] = $vendorAmbiguous;
        }
        if ($flags !== []) {
            $row['flags'] = $flags;
        }
        return $row;
    }

    /**
     * 「확정 때 만들 거래처」 하나를 계획한다 (9차 보정). 이름만 있고 **코드는 NULL** 이다.
     *
     * 같은 이름은 한 번만 계획한다 — lookup 에 임시 id 를 함께 넣어 두므로 뒤의 행은 그것을
     * 그대로 쓴다(8,100행짜리 파일이 같은 거래처를 수백 번 가리켜도 한 건만 생긴다).
     *
     * @param array<string,array<string,mixed>> $lookup 참조
     * @param array<string,mixed>               $plan   참조
     * @return int 음수 임시 id (확정이 진짜 id 로 바꿔 넣는다)
     */
    private static function planVendor(string $name, array &$lookup, array &$plan): int
    {
        $key      = strtolower($name);
        $existing = $lookup['vendor_by_name'][$key] ?? null;
        if ($existing !== null) {
            return (int) $existing;
        }
        $temp = self::nextTemp($plan);
        $plan['vendors'][] = ['temp_id' => $temp, 'name' => self::cut($name, 120)];
        $lookup['vendor_by_name'][$key]    = $temp;
        $lookup['vendor_name_count'][$key] = 1;
        return $temp;
    }

    /**
     * 「확정 때 만들 분류」 하나를 계획한다 (9차 보정).
     *
     * 열쇠는 `단계|부모|이름(소문자)` 로 판정 lookup 과 **완전히 같다** — 그래서 같은 부모 밑의
     * 같은 이름은 한 번만 생기고, 부모가 다르면 같은 이름이라도 따로 생긴다(이카운트의 중분류
     * 「기타」 는 대분류마다 하나씩 있다). 부모는 이미 있는 분류의 id 이거나 **앞서 계획한
     * 임시 id**(음수)이며, 확정이 1단 → 2단 → 3단 차례로 만들며 진짜 id 로 이어 준다.
     *
     * @param int                               $parent 0 = 뿌리, 양수 = 기존 id, 음수 = 계획한 분류
     * @param array<string,array<string,mixed>> $lookup 참조
     * @param array<string,mixed>               $plan   참조
     * @return int 음수 임시 id
     */
    private static function planCategory(
        int $level,
        int $parent,
        string $name,
        ?string $parentName,
        array &$lookup,
        array &$plan
    ): int {
        $key = (string) $level . '|' . (string) $parent . '|' . strtolower($name);
        if (isset($lookup['category'][$key])) {
            return (int) $lookup['category'][$key];
        }
        $temp = self::nextTemp($plan);
        $plan['categories'][] = [
            'temp_id'     => $temp,
            'level'       => $level,
            'parent'      => $parent,
            'name'        => self::cut($name, 80),
            'parent_name' => $parentName,
        ];
        $lookup['category'][$key] = $temp;
        return $temp;
    }

    /** 다음 임시 id (-1, -2, …). 진짜 id 는 언제나 양수라 섞일 일이 없다. */
    private static function nextTemp(array &$plan): int
    {
        $next = (int) ($plan['next_temp'] ?? -1);
        $plan['next_temp'] = $next - 1;
        return $next;
    }

    /**
     * 거래처 한 행 (2차 보정 2026-09-13 — 실제 이카운트 파일 ESA001M 184행으로 다시 씀).
     *
     * **정체는 코드다.** 이름은 겹칠 수 있다(같은 상호로 사업자를 나눈 거래처가 실제로 있다).
     * 그래서 이름이 겹치는 행을 **막지 않고 「확인 필요」로 세워** 사람이 정하게 한다 —
     * 확정에서 `23505` 가 터져 184행이 통째로 롤백되던 것이 이 보정의 출발점이다.
     *
     * 판정
     *   이름 없음                          → error  `required_missing`
     *   같은 **코드**가 이 파일에 또 있음   → error  `duplicate_in_file`  (진짜 중복이다)
     *   같은 **이름**이 이 파일에 이미 있음 → review `duplicate_name_in_file` (승격하면 insert)
     *   코드가 DB 에 있음                   → update
     *   코드는 새것인데 **이름이 DB 에 있음**→ review `name_exists`        (승격하면 insert)
     *   코드가 없고 이름이 DB 에 있음       → update (옛 방식 그대로 — 코드 없는 거래처는 이름이 정체다)
     *   그 밖                               → insert
     *
     * 「사용구분」(YES/NO)은 `is_active` 로 들어간다 — 이카운트에서 안 쓰는 거래처를
     * 우리 쪽에서 **중단 상태로** 받는다(지우지 않는다, 지시서 보충 4).
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
        $code = trim($d['code'] ?? '');

        $values = [
            'code'      => self::orNull($code),
            'name'      => self::cut($name, 120),
            'biz_no'    => self::orNull($d['biz_no'] ?? ''),
            'ceo_name'  => self::orNull($d['ceo_name'] ?? ''),
            'tel'       => self::orNull($d['tel'] ?? ''),
            'is_active' => self::activeOr($d['active'] ?? '', true),
        ];

        // ① 같은 코드가 파일 안에 또 있으면 그것은 **진짜 중복**이다 (같은 거래처가 두 줄).
        if ($code !== '') {
            $ckey = 'c:' . strtolower($code);
            if (isset($seen[$ckey])) {
                return self::fail('duplicate_in_file', $d);
            }
            $seen[$ckey] = true;
        }

        // ② 같은 이름이 파일 안에 이미 있으면 「확인 필요」 — 첫 행만 조용히 들어간다.
        $nkey = 'n:' . self::nameKey($name);
        $nameSeenInFile = isset($seen[$nkey]);
        $seen[$nkey] = true;

        $byCode = $code === '' ? null : ($lookup['vendor_by_code'][strtolower($code)] ?? null);
        $byName = $lookup['vendor_by_name'][strtolower($name)] ?? null;

        // 코드가 DB 에 이미 있으면 그 거래처를 고치는 것이다 — 이름이 겹쳐도 헷갈릴 일이 없다.
        if ($byCode !== null) {
            return [
                'status'    => self::STATUS_UPDATE,
                'reason'    => null,
                'data'      => $d,
                'values'    => $values,
                'diff'      => ['name' => $values['name']],
                'target_id' => $byCode,
                'promote_to' => self::STATUS_UPDATE,
            ];
        }

        if ($nameSeenInFile) {
            return [
                'status' => self::STATUS_REVIEW,
                'reason' => 'duplicate_name_in_file',
                'data'   => $d,
                'values' => $values,
                // 승격하면 **새로 등록**한다 — 이름이 같을 뿐 다른 거래처라는 사람의 판단이다.
                'promote_to' => self::STATUS_INSERT,
            ];
        }

        if ($code === '') {
            // 코드가 없는 행은 옛 규칙 그대로 — 이름이 그 거래처의 정체다.
            if ($byName !== null) {
                return [
                    'status'    => self::STATUS_UPDATE,
                    'reason'    => null,
                    'data'      => $d,
                    'values'    => $values,
                    'diff'      => ['name' => $values['name']],
                    'target_id' => $byName,
                    'promote_to' => self::STATUS_UPDATE,
                ];
            }
            return ['status' => self::STATUS_INSERT, 'reason' => null, 'data' => $d, 'values' => $values,
                    'promote_to' => self::STATUS_INSERT];
        }

        if ($byName !== null) {
            // 새 코드인데 이름이 이미 있다 — 같은 회사의 다른 사업자인지 사람이 본다.
            return [
                'status' => self::STATUS_REVIEW,
                'reason' => 'name_exists',
                'data'   => $d,
                'values' => $values,
                'promote_to' => self::STATUS_INSERT,
            ];
        }

        return ['status' => self::STATUS_INSERT, 'reason' => null, 'data' => $d, 'values' => $values,
                'promote_to' => self::STATUS_INSERT];
    }

    /**
     * 장소(창고) 한 행 (2차 보정 2026-09-13 — 실제 이카운트 파일 ESA005M 98행으로 다시 씀).
     *
     * **지점이 어디서 오나 — 세 규칙을 순서대로** (지시 3)
     *   ① `branch` 열이 매핑돼 있고 값이 있으면 그 이름으로 찾는다      → `branch_by = column`
     *   ② 없으면 **창고명 안에 활성 지점 이름이 들어 있는지** 본다.
     *      「플란치과_강남점」 안에 「강남점」 이 있으면 그 지점이다      → `branch_by = name`
     *      둘 이상 맞으면 사람이 정해야 한다                             → review `branch_ambiguous`
     *   ③ 그래도 모르면 화면의 「기본 지점」                              → `branch_by = default`
     *
     * ②가 ③보다 앞인 이유: 파일이 이미 답을 갖고 있는데 화면의 기본값으로 덮어쓰면,
     * 여러 지점의 창고가 섞인 파일이 **전부 한 지점으로 들어간다**. 그 오류는 재고를
     * 맞춰 보기 전까지 드러나지 않는다.
     *
     * 「구분」(창고/기타)은 용도(kind)로, 「사용」(Yes/No)은 `is_active` 로 들어간다.
     *
     * @param array<string,string>              $d
     * @param array<string,array<string,mixed>> $lookup
     * @param array<string,bool>                $seen
     * @param int                               $defaultBranch 화면의 「기본 지점」 (0 = 없음)
     * @return array<string,mixed>
     */
    private static function judgeLocation(
        array $d,
        array $lookup,
        array &$seen,
        int $defaultBranch = 0,
        array $opts = []
    ): array {
        $name = $d['name'] ?? '';
        if ($name === '') {
            return self::fail('required_missing', $d);
        }

        $createBrands   = ($opts['create_brands'] ?? false) === true;
        $createBranches = ($opts['create_branches'] ?? false) === true;
        $defaultBrand   = (int) ($opts['default_brand'] ?? 0);

        $branchId = null;
        $branchBy = '';
        $brandBy  = '';
        $plan     = null;   // 「이 행 때문에 새로 만들 것」 (브랜드·지점)

        // ① 파일의 지점 열
        $fromColumn = trim($d['branch'] ?? '');
        if ($fromColumn !== '') {
            $branchId = $lookup['branch'][strtolower($fromColumn)] ?? null;
            if ($branchId === null) {
                return self::fail('branch_not_found', $d, ['kind' => 'branches', 'value' => $fromColumn]);
            }
            $branchBy = 'column';
        }

        /* ② 창고명을 브랜드 + 지점으로 갈라 본다 (5차 보정).
         *
         * 전에는 "창고명 안에 **이미 있는** 지점 이름이 들어 있나" 만 봤다. 그래서 지점이
         * 하나도 없는 상태에서 여러 지점의 창고를 올리면 **전부 기본 지점 하나로** 들어갔다
         * (사용자 관찰). 이제는 이름을 갈라 **없는 지점을 만들 계획**까지 세운다.
         */
        $parsed = ['brand' => null, 'branch' => null, 'detail' => null];
        if ($branchId === null) {
            $parsed = self::parseLocationName($name, $lookup['branch']);

            // ②-1 뽑은 지점 이름이 이미 있으면 그것을 쓴다 (새로 만들지 않는다)
            if ($parsed['branch'] !== null) {
                $existing = $lookup['branch'][strtolower($parsed['branch'])] ?? null;
                if ($existing !== null) {
                    $branchId = $existing;
                    $branchBy = 'name';
                }
            }

            // ②-2 없고, 「지점 만들기」 가 켜져 있으면 만들 계획을 세운다
            if ($branchId === null && $parsed['branch'] !== null && $createBranches) {
                $brandId   = null;
                $brandName = null;

                if ($parsed['brand'] !== null) {
                    $brandId = $lookup['brand'][strtolower($parsed['brand'])] ?? null;
                    if ($brandId !== null) {
                        $brandBy = 'name';
                    } elseif ($createBrands) {
                        $brandName = $parsed['brand'];   // 브랜드도 함께 만든다
                        $brandBy   = 'name_new';
                    }
                }
                if ($brandId === null && $brandName === null && $defaultBrand > 0) {
                    $brandId = $defaultBrand;
                    $brandBy = 'default';
                }
                if ($brandId === null && $brandName === null) {
                    /* 새 지점을 만들어야 하는데 붙일 브랜드가 없다. 행마다 오류를 내지 않고
                     * **한 번에** 말한다 — 「기본 브랜드」를 고르거나 「브랜드도 만들기」를 켜면 된다. */
                    throw new RuntimeException('default_brand_required');
                }

                $plan = [
                    'branch_name' => $parsed['branch'],
                    'brand_name'  => $brandName,   // null 이면 이미 있는 브랜드를 쓴다
                    'brand_id'    => $brandId,
                ];
                $branchBy = 'name_new';
            }
        }

        // ③ 화면의 기본 지점 — **판별이 안 된 행 전용**이다
        if ($branchId === null && $plan === null && $defaultBranch > 0) {
            $branchId = $defaultBranch;
            $branchBy = 'default';
        }

        // ④ 그래도 모르면 사람이 정해야 한다 (5차 보정 — 오류가 아니라 「확인 필요」다)
        if ($branchId === null && $plan === null) {
            $out = self::fail('branch_unresolved', $d);
            $out['status']     = self::STATUS_REVIEW;
            $out['reason']     = 'branch_unresolved';
            $out['promote_to'] = self::STATUS_INSERT;
            $out['branch_by']  = '';
            return $out;
        }

        /* ─── 용도(kind) — 열이 먼저, 상세가 보조 (7차 보정) ───
         *
         * 이카운트 「구분」 열은 거의 전부 「창고」로만 온다(실측). 그 값만 믿으면 수술방도
         * 중앙공급실도 전부 창고가 된다. 그래서 **열이 창고가 아닌 다른 종류를 명시하면
         * 그 열이 이기고**, 창고이거나 비었으면 장소 상세로 짐작한다.
         * 사람이 적은 값이 추측보다 앞선다 — 추측은 빈자리를 메울 뿐이다.
         */
        $kindRaw    = strtolower($d['kind'] ?? '');
        $fromColumn = in_array($kindRaw, Master::LOCATION_KINDS, true)
            ? $kindRaw
            : (self::LOCATION_KIND_ALIAS[$kindRaw] ?? null);
        $fromDetail = self::inferLocationKind($parsed['detail'] ?? null);

        if ($fromColumn !== null && $fromColumn !== 'warehouse') {
            $kind = $fromColumn;              // 열이 「중앙장」·「수술방」·「기타」 라고 말했다
        } elseif ($fromDetail !== null) {
            $kind = $fromDetail;              // 열은 「창고」(또는 없음) — 상세가 더 말해 준다
        } else {
            $kind = $fromColumn ?? 'other';
        }

        $values = [
            'branch_id'   => $branchId,   // 새로 만들 지점이면 null — 확정에서 채운다
            'kind'        => $kind,
            /* 창고 이름은 **파일의 원문 그대로** 둔다 (7차 보정, 사용자 결정).
             * 사용자가 이카운트 화면에서 보는 이름과 같아야 월 대사 때 눈으로 맞출 수 있다 —
             * 「플란치과_부평점_B1F_의약품창고」 를 「B1F_의약품창고」 로 줄여 두면
             * 두 화면을 나란히 놓고 볼 수 없다. 지점은 `branch_id` 가 따로 가리킨다. */
            'name'        => self::cut($name, 80),
            'code'        => self::orNull($d['code'] ?? ''),
            // 이전 이름 열이 없으면 **원문**을 그대로 남긴다 (이름이 80자에서 잘려도 원본이 남게).
            'legacy_name' => self::orNull($d['legacy_name'] ?? '') ?? self::cut($name, 200),
            'is_active'   => self::activeOr($d['active'] ?? '', true),
        ];

        /* 파일 안 중복 검사 — 새로 만들 지점은 아직 id 가 없으므로 **이름**으로 센다. */
        $key = ($branchId !== null ? (string) $branchId : 'new:' . strtolower((string) ($plan['branch_name'] ?? '')))
             . '|' . strtolower($name);
        if (isset($seen[$key])) {
            return self::fail('duplicate_in_file', $d);
        }
        $seen[$key] = true;

        $current = $branchId === null
            ? null
            : ($lookup['location'][(string) $branchId . '|' . strtolower($name)] ?? null);

        $row = [
            'status'     => self::STATUS_INSERT,
            'reason'     => null,
            'data'       => $d,
            'values'     => $values,
            'branch_by'  => $branchBy,
            'promote_to' => self::STATUS_INSERT,
        ];
        if ($brandBy !== '') {
            $row['brand_by'] = $brandBy;
        }
        /* 7차 보정 — **표시용** 장소 상세(`B1F_의약품창고`). 컬럼을 늘리지 않고 응답에만 싣는다.
         * 화면이 「이 창고가 지점의 어느 자리인가」 를 한눈에 보여 주는 데 쓴다. */
        if (!empty($parsed['detail'])) {
            $row['detail'] = (string) $parsed['detail'];
        }
        if ($plan !== null) {
            $row['plan'] = $plan;
            // 화면이 「새로 만들 지점」 을 이름으로 보여 줄 수 있게 함께 준다.
            $row['branch_name_new'] = $plan['branch_name'];
            $row['brand_name_new']  = $plan['brand_name'];
        }
        if ($current !== null) {
            $row['status']     = self::STATUS_UPDATE;
            $row['diff']       = self::diff($current, $values);
            $row['target_id']  = (int) $current['id'];
            $row['promote_to'] = self::STATUS_UPDATE;
        }
        return $row;
    }

    /**
     * 이카운트 「구분」 값 → 우리 용도. 목록 밖은 `other` 다 (판정을 지어내지 않는다).
     * @var array<string,string>
     */
    private const LOCATION_KIND_ALIAS = [
        '창고' => 'warehouse', '중앙장' => 'central', '수술방' => 'or', '기타' => 'other',
        'warehouse' => 'warehouse', 'central' => 'central', 'or' => 'or', 'other' => 'other',
    ];

    /**
     * 지점 한 행 (2차 보정 2026-09-13). 이름이 정체이고, 브랜드는 열 또는 화면의 기본 브랜드다.
     *
     * 창고 파일에서 **파생**해 만드는 경우(`derive=location_name`)도 이 함수를 그대로 지난다 —
     * judge() 가 창고명에서 뽑은 이름을 `name` 에 넣어 주고, 나머지 판정은 같다.
     *
     * @param array<string,string>              $d
     * @param array<string,array<string,mixed>> $lookup
     * @param array<string,bool>                $seen
     * @param int                               $defaultBrand 화면의 「기본 브랜드」 (0 = 없음)
     * @return array<string,mixed>
     */
    private static function judgeBranch(array $d, array $lookup, array &$seen, int $defaultBrand = 0): array
    {
        $name = $d['name'] ?? '';
        if ($name === '') {
            return self::fail('required_missing', $d);
        }

        $brandId   = null;
        $fromBrand = trim($d['brand'] ?? '');
        if ($fromBrand !== '') {
            $brandId = $lookup['brand'][strtolower($fromBrand)] ?? null;
            if ($brandId === null) {
                return self::fail('brand_not_found', $d, ['kind' => 'brands', 'value' => $fromBrand]);
            }
        }
        if ($brandId === null && $defaultBrand > 0) {
            $brandId = $defaultBrand;
        }
        if ($brandId === null) {
            return self::fail('brand_not_found', $d);
        }

        $values = [
            'brand_id'  => $brandId,
            'name'      => self::cut($name, 60),
            'code'      => self::orNull($d['code'] ?? ''),
            'is_active' => self::activeOr($d['active'] ?? '', true),
        ];

        $key = 'b:' . (string) $brandId . '|' . self::nameKey($name);
        if (isset($seen[$key])) {
            return self::fail('duplicate_in_file', $d);
        }
        $seen[$key] = true;

        $current = $lookup['branch_row'][strtolower($name)] ?? null;
        if ($current === null) {
            return ['status' => self::STATUS_INSERT, 'reason' => null, 'data' => $d,
                    'values' => $values, 'promote_to' => self::STATUS_INSERT];
        }
        // 이미 있는 지점은 **건드리지 않는다** — 지점은 사람이 만드는 마스터이고, 창고 파일에서
        // 뽑은 이름으로 기존 지점의 브랜드를 바꾸면 재고 범위가 통째로 움직인다.
        return [
            'status'    => self::STATUS_UPDATE,
            'reason'    => null,
            'data'      => $d,
            'values'    => $values,
            'diff'      => [],          // 빈 diff → 확정에서 건너뛴다(이미 있다는 뜻)
            'target_id' => (int) $current['id'],
            'promote_to' => self::STATUS_UPDATE,
        ];
    }

    /**
     * 지점 이름으로 인정하는 **어미** (5차 보정 2026-09-13).
     * 「강남점」·「○○의원」·「○○치과」·「○○병원」 처럼 **가게 이름으로 끝나는** 조각만
     * 지점으로 본다. 「임시」·「폐기」 같은 조각은 지점이 아니다 — 그런 행은 사람이 정한다.
     * @var list<string>
     */
    private const BRANCH_SUFFIX = ['점', '의원', '치과', '병원', '센터', '원'];

    /**
     * 창고명을 **브랜드 + 지점 + 장소 상세**로 가른다 (7차 보정 2026-09-13, 실파일 재측정).
     *
     * 5차 보정은 "마지막 조각 = 지점" 으로 봤다. 실제 이카운트 창고명 98개를 세어 보니
     * **86개가 세 조각 이상**이었다(`플란치과_강남점_15층 공용부` ·
     * `플란치과_부평점_B1F_의약품창고`). 마지막 조각(`15층 공용부`·`의약품창고`)은 지점이
     * 아니라 **장소 상세**여서, 93행이 「판별 불가」로 떨어졌다.
     *
     * 고친 규칙 — `_` 로만 나눈다(조각 안의 공백은 그대로 둔다: `13층 중앙공급실(구매팀)`).
     *   · 1조각        → 브랜드도 지점도 없다 (`상담실`·`중앙창고`·`라미트치과`).
     *   · 첫 조각      → **브랜드 후보**.
     *   · 2번째 조각부터 차례로 보아, **지점 어미로 끝나거나 이미 있는 지점 이름과 같은
     *     첫 조각** → 지점. 그 **뒤의 나머지 조각을 `_` 로 다시 이어** 장소 상세.
     *   · 지점 조각을 못 찾으면 판별 불가(화면의 「기본 지점」 으로 간다).
     *
     * **추측이다.** 결과는 미리보기에 브랜드·지점·상세로 나오고 사람이 확인한 뒤 저장된다.
     *
     * @param array<string,int> $branchNames 이미 있는 지점 (소문자 이름 → id)
     * @return array{brand:?string, branch:?string, detail:?string}
     */
    public static function parseLocationName(string $name, array $branchNames = []): array
    {
        $parts = preg_split('/_+/u', trim($name));
        $parts = is_array($parts) ? array_values(array_filter($parts, static fn ($v) => trim($v) !== '')) : [];
        if (count($parts) < 2) {
            return ['brand' => null, 'branch' => null, 'detail' => null];
        }

        $brand = self::cut(trim($parts[0]), 60);

        for ($i = 1; $i < count($parts); $i++) {
            $piece = trim($parts[$i]);
            if (!self::looksLikeBranch($piece, $branchNames)) {
                continue;
            }
            $rest = array_slice($parts, $i + 1);
            return [
                'brand'  => $brand,
                'branch' => self::cut($piece, 60),
                // 남은 조각은 **원래 구분자로 다시 이어** 보여 준다 (`B1F_의약품창고`).
                'detail' => $rest === [] ? null : self::cut(implode('_', $rest), 200),
            ];
        }

        // 지점 조각이 없다 — 브랜드만 알 수 있고 지점은 사람이 정한다.
        return ['brand' => $brand, 'branch' => null, 'detail' => self::cut(implode('_', array_slice($parts, 1)), 200)];
    }

    /** 이 조각이 지점 이름처럼 보이나 — 어미로 끝나거나 **이미 있는 지점**과 같으면 참 */
    private static function looksLikeBranch(string $piece, array $branchNames): bool
    {
        if ($piece === '') {
            return false;
        }
        if (isset($branchNames[strtolower($piece)])) {
            return true;
        }
        foreach (self::BRANCH_SUFFIX as $suffix) {
            if (preg_match('/' . $suffix . '$/u', $piece) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * 장소 상세로 **용도(kind)를 짐작한다** (7차 보정).
     *
     * 이카운트의 「구분」 열은 거의 전부 「창고」로만 와서(실측), 그 값만 보면 수술방도
     * 중앙공급실도 전부 `warehouse` 가 된다. 상세 이름이 더 많은 것을 말해 준다.
     * **구분 열이 창고가 아닌 다른 종류를 명시하면 그 열이 이긴다**(judgeLocation 참조) —
     * 사람이 적은 값이 추측보다 앞선다.
     *
     * @return ?string 짐작한 용도 (모르겠으면 null)
     */
    public static function inferLocationKind(?string $detail): ?string
    {
        $text = strtolower(trim((string) $detail));
        if ($text === '') {
            return null;
        }
        foreach (self::KIND_HINTS as $hint => $kind) {
            if (strpos($text, $hint) !== false) {
                return $kind;
            }
        }
        return null;
    }

    /**
     * 상세 이름의 **조각 → 용도**. 위에서부터 먼저 맞는 것을 쓴다(수술방이 창고보다 앞이다 —
     * 「수술방 창고」 는 수술방으로 보는 편이 재고 흐름에 맞는다).
     * @var array<string,string>
     */
    private const KIND_HINTS = [
        '수술방' => 'or',
        '진료'   => 'or',
        '중앙공급' => 'central',
        '구매팀' => 'central',
        '공급실' => 'warehouse',
        '의약품' => 'warehouse',
        '소모품' => 'warehouse',
        '창고'   => 'warehouse',
    ];

    /**
     * 창고명에서 **지점 이름**을 뽑는다 (지시 4).
     *
     * 「플란치과_강남점」 → 「강남점」 · 「라미트치과」 → 「라미트치과」.
     * `_` 와 공백으로 나눈 뒤 **「점」으로 끝나는 조각**을 먼저 찾고, 없으면 마지막 조각을 쓴다.
     * 추측이므로 사용자가 미리보기에서 목록을 보고 확인한 뒤에야 저장된다.
     */
    public static function branchNameFrom(string $locationName): string
    {
        $parts = preg_split('/[_\s]+/u', trim($locationName));
        $parts = is_array($parts) ? array_values(array_filter($parts, static fn ($v) => trim($v) !== '')) : [];
        if ($parts === []) {
            return '';
        }
        foreach (array_reverse($parts) as $part) {
            if (preg_match('/점$/u', $part) === 1) {
                return self::cut($part, 60);
            }
        }
        return self::cut($parts[count($parts) - 1], 60);
    }

    /** 이름 비교용 열쇠 — 대소문자·공백 무시 (지시 1: "대소문자·공백 무시") */
    private static function nameKey(string $name): string
    {
        $clean = preg_replace('/\s+/u', '', $name);
        return strtolower(is_string($clean) ? $clean : $name);
    }

    /** 「사용 / 사용구분」 칸 → 참/거짓. 빈 값이면 기본값(보통 사용 중) */
    private static function activeOr(string $value, bool $default): bool
    {
        $clean = strtolower(trim($value));
        if ($clean === '') {
            return $default;
        }
        return in_array($clean, self::ACTIVE_TRUE, true);
    }

    /**
     * 오류 행 하나.
     *
     * ★ 3차 보정 2026-09-13 — `$missing` 에 **무엇이 없어서 막혔는지**를 함께 담는다
     *   (`{kind, value, …}`). 화면은 이것을 보고 그 행 옆에 「거래처 등록」 단추를 세우고,
     *   누르면 그 이름이 채워진 등록 모달을 연다. 사용자가 파일을 닫고 기초정보 화면으로
     *   건너가 이름을 다시 타이핑할 필요가 없다(사용자 결정 2026-09-13 「접근한 페이지에서
     *   필요한 정보에 대한 팝업창을 띄워 등록」).
     *
     *   값은 **파일에 적힌 글자 그대로**이고, 화면은 그것을 `textContent` 로만 넣는다.
     *
     * @param array<string,mixed> $missing {kind: 등록할 종류, value: 파일에 적힌 이름, …}
     * @return array<string,mixed>
     */
    private static function fail(string $reason, array $data, array $missing = []): array
    {
        $out = ['status' => self::STATUS_ERROR, 'reason' => $reason, 'data' => $data];
        if ($missing !== []) {
            $out['missing'] = $missing;
        }
        return $out;
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
        INSERT INTO p_vendors (code, name, biz_no, ceo_name, tel, is_active, created_by)
        VALUES (:code, :name, :biz_no, :ceo_name, :tel, CAST(:is_active AS boolean), :created_by)
        SQL;

    private const SQL_UPDATE_VENDOR = <<<'SQL'
        UPDATE p_vendors
           SET code = :code, name = :name, biz_no = :biz_no,
               ceo_name = :ceo_name, tel = :tel,
               is_active = CAST(:is_active AS boolean), updated_at = now()
         WHERE id = CAST(:id AS bigint)
        SQL;

    private const SQL_INSERT_LOCATION = <<<'SQL'
        INSERT INTO p_locations (branch_id, kind, name, code, legacy_name, is_active, created_by)
        VALUES (CAST(:branch_id AS bigint), :kind, :name, :code, :legacy_name,
                CAST(:is_active AS boolean), :created_by)
        SQL;

    private const SQL_UPDATE_LOCATION = <<<'SQL'
        UPDATE p_locations
           SET branch_id = CAST(:branch_id AS bigint), kind = :kind, name = :name,
               code = :code, legacy_name = :legacy_name,
               is_active = CAST(:is_active AS boolean), updated_at = now()
         WHERE id = CAST(:id AS bigint)
        SQL;

    /* 지점 — **만들기만 한다.** 이미 있는 지점은 judgeBranch 가 빈 diff 로 돌려주어
     * 확정에서 건너뛰므로 UPDATE 문이 필요 없다(지점의 브랜드를 파일이 바꾸게 두지 않는다). */
    private const SQL_INSERT_BRANCH = <<<'SQL'
        INSERT INTO p_branches (brand_id, name, code, is_active, created_by)
        VALUES (CAST(:brand_id AS bigint), :name, :code, CAST(:is_active AS boolean), :created_by)
        SQL;

    private const SQL_INSERT_BRAND_ROW = <<<'SQL'
        INSERT INTO p_brands (name, created_by) VALUES (:name, :created_by) RETURNING id
        SQL;

    private const SQL_INSERT_BRANCH_ROW = <<<'SQL'
        INSERT INTO p_branches (brand_id, name, created_by)
        VALUES (CAST(:brand_id AS bigint), :name, :created_by)
        RETURNING id
        SQL;

    /** 창고 가져오기가 덤으로 만드는 브랜드 하나 (5차 보정). 같은 트랜잭션 안이다. */
    private static function insertBrand(string $name, int $actorId): int
    {
        $row = Db::instance()->run(self::SQL_INSERT_BRAND_ROW, [
            ':name' => self::cut($name, 60), ':created_by' => $actorId,
        ])->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('insert_failed');
        }
        return (int) $row['id'];
    }

    /* 품목 가져오기가 함께 만드는 거래처·분류 (9차 보정). 같은 트랜잭션 안이다.
     * 거래처는 **이름만** 넣는다 — 이카운트 품목 파일에는 거래처 코드 열이 아예 없고,
     * 코드 없는 거래처는 이름이 정체다(`p_vendors.code` 는 NULL 허용, UNIQUE 는 NOT NULL 일 때만). */
    private const SQL_INSERT_VENDOR_NAME = <<<'SQL'
        INSERT INTO p_vendors (code, name, created_by) VALUES (NULL, :name, :created_by) RETURNING id
        SQL;

    private const SQL_INSERT_CATEGORY_ROW = <<<'SQL'
        INSERT INTO p_categories (parent_id, name, level, created_by)
        VALUES (CAST(:parent_id AS bigint), :name, CAST(:level AS int), :created_by)
        RETURNING id
        SQL;

    /** 이름만 있는 거래처 하나 (코드 NULL). 손으로 만드는 창구와 **같은 표·같은 제약**이다. */
    private static function insertVendorName(string $name, int $actorId): int
    {
        $row = Db::instance()->run(self::SQL_INSERT_VENDOR_NAME, [
            ':name' => self::cut($name, 120), ':created_by' => $actorId,
        ])->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('insert_failed');
        }
        return (int) $row['id'];
    }

    /** 분류 하나. 1단은 부모가 NULL 이고 2·3단은 부모가 있어야 한다(DB CHECK 가 마지막 그물). */
    private static function insertCategoryRow(int $level, ?int $parentId, string $name, int $actorId): int
    {
        $row = Db::instance()->run(self::SQL_INSERT_CATEGORY_ROW, [
            ':parent_id'  => $parentId,
            ':name'       => self::cut($name, 80),
            ':level'      => $level,
            ':created_by' => $actorId,
        ])->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('insert_failed');
        }
        return (int) $row['id'];
    }

    /** 창고 가져오기가 덤으로 만드는 지점 하나 (5차 보정). 브랜드가 먼저 있어야 한다. */
    private static function insertBranchRow(string $name, int $brandId, int $actorId): int
    {
        $row = Db::instance()->run(self::SQL_INSERT_BRANCH_ROW, [
            ':brand_id' => $brandId, ':name' => self::cut($name, 60), ':created_by' => $actorId,
        ])->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('insert_failed');
        }
        return (int) $row['id'];
    }

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
                ':ceo_name' => $v['ceo_name'], ':tel' => $v['tel'],
                ':is_active' => ($v['is_active'] ?? true) ? 'true' : 'false',
                ':created_by' => $actorId,
            ]);
            return;
        }
        if ($kind === 'branches') {
            $db->run(self::SQL_INSERT_BRANCH, [
                ':brand_id' => $v['brand_id'], ':name' => $v['name'], ':code' => $v['code'],
                ':is_active' => ($v['is_active'] ?? true) ? 'true' : 'false',
                ':created_by' => $actorId,
            ]);
            return;
        }
        $db->run(self::SQL_INSERT_LOCATION, [
            ':branch_id' => $v['branch_id'], ':kind' => $v['kind'], ':name' => $v['name'],
            ':code' => $v['code'], ':legacy_name' => $v['legacy_name'],
            ':is_active' => ($v['is_active'] ?? true) ? 'true' : 'false',
            ':created_by' => $actorId,
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
                ':ceo_name' => $v['ceo_name'], ':tel' => $v['tel'],
                ':is_active' => ($v['is_active'] ?? true) ? 'true' : 'false',
            ]);
            return;
        }
        if ($kind === 'branches') {
            return;   // 이미 있는 지점은 고치지 않는다 (judgeBranch 의 빈 diff — 여기 오지 않는다)
        }
        $db->run(self::SQL_UPDATE_LOCATION, [
            ':id' => $id, ':branch_id' => $v['branch_id'], ':kind' => $v['kind'],
            ':name' => $v['name'], ':code' => $v['code'], ':legacy_name' => $v['legacy_name'],
            ':is_active' => ($v['is_active'] ?? true) ? 'true' : 'false',
        ]);
    }

    /* ══════════════════════════════════════════════════════════════════
     * 업로드 한 건을 「구매 / 가져오기」 폴더에 남긴다 — **두 입구가 함께 쓰는 한 함수**
     * (24단계 지시서 보충 1. PRD F-29 · F-37, SECURITY R-67 · R-69)
     *
     * 왜 한 함수인가
     *   19단계의 가져오기 업로드(`purchase_import_upload.php`)와 24단계의 창고 대기표
     *   업로드(`purchase_structure_upload.php`)는 **같은 관문**을 지나야 한다: 업로드 검사
     *   (`Upload::validate`) · 가져오기가 읽을 수 있는 확장자 · 민감정보 검출 · admin-only
     *   보관 폴더 · files 행 + 저장소 이동 · 실패 시 파일까지 되돌리기. 두 벌로 두면 언젠가
     *   한쪽만 고쳐지고, 그 한쪽이 곧 **두 번째 입구**다 (`Upload::validate` 를 본체 업로드와
     *   함께 쓰는 것과 같은 이유). 거부 사유 문자열도 한 곳에서만 나온다.
     *
     * 이 함수는 예외적으로 감사·HTTP 를 다룬다 — 거부는 `Audit::deny($auditAction, …)` +
     * 400 동일 문구이고, 성공은 `Audit::allow($auditAction, …)` 한 줄이다. 사유 코드가
     * 곧 감사 로그의 `reason` 이라 그 둘을 갈라 두면 "같은 관문" 이라는 성질이 깨진다.
     *
     * @param mixed $uploaded `$_FILES['file']` — 배열이 아니면 `no_file` 로 끝난다
     * @param array<string,mixed> $user     라우터가 넘긴 현재 사용자 (created_by 는 이 값만 쓴다)
     * @param string $auditAction `purchase.import.upload` · `purchase.structure.upload`
     * @param callable|null $inTransaction 트랜잭션 **안**에서 files 행과 저장이 끝난 뒤 불린다.
     *        인자는 아래 반환과 같은 배열이고, 돌려준 배열은 `allow` 의 detail 에 합쳐진다.
     *        예외를 던지면 files 행도 저장한 파일도 **전부 되돌아간다**. null 이면 19단계와
     *        똑같은 동작이다(가져오기 업로드는 표를 건드리지 않으므로 null 을 넘긴다).
     * @return array{file_id:int,public_id:string,original:string,ext:string,mime:string,size:int,sha256:string,stored_name:string,folder_id:int,sensitive:array<string,int>}
     */
    public static function storeUploaded(
        mixed $uploaded,
        array $user,
        string $auditAction,
        ?callable $inTransaction = null
    ): array {
        /** 거부 기록 후 끝낸다. 사유는 로그에만, 사용자에게는 동일 문구만 (PRD S-09-5). */
        $reject = static function (string $reason, ?string $name = null) use ($auditAction): void {
            $entry = [
                'target_type'  => 'file',
                'target_name'  => $name,
                'access_level' => 'admin-only',
            ];
            /* R-71 (2026-09-13) — 위장 파일로 거부할 때는 **무엇으로 감지됐는지**를 함께 남긴다.
             * files_upload.php 와 같은 방식이다. 값은 finfo 가 돌려준 MIME 문자열이며 비밀이 없다. */
            if ($reason === 'mime_mismatch' && Upload::$lastDetectedMime !== null) {
                $entry['detail'] = ['mime' => Upload::$lastDetectedMime];
            }
            Audit::deny($auditAction, $reason, $entry);
            Http::error(400, Http::MSG_BAD_REQUEST);
        };

        // ─── [1] 파일 필드 ───
        if (!is_array($uploaded)) {
            $reject('no_file');
            exit;   // $reject 안에서 끝나지만 정적 분석을 위해 남긴다
        }

        /** 원본 파일명 — basename → 제어문자 제거 → 길이 제한. **경로에는 한 글자도 쓰이지 않는다** */
        $rawName  = is_string($uploaded['name'] ?? null) ? (string) $uploaded['name'] : '';
        $original = basename(str_replace('\\', '/', $rawName));
        $original = (string) preg_replace('/[\x00-\x1f\x7f]/', '', $original);
        if ($original === '' || preg_match('//u', $original) !== 1) {
            $reject('bad_encoding');
        }
        if (iconv_strlen($original, 'UTF-8') > 255) {
            $cut      = iconv_substr($original, 0, 255, 'UTF-8');
            $original = is_string($cut) ? $cut : $original;
        }

        // ─── [2] 업로드 검사 — 본체와 **같은 함수**다 (PRD S-06) ───
        try {
            $checked = Upload::validate($uploaded);
        } catch (RuntimeException $e) {
            $reject($e->getMessage(), $original);
            exit;   // $reject 안에서 끝나지만 정적 분석을 위해 남긴다
        }

        // ─── [3] 가져오기가 읽을 수 있는 형식인가 (xlsx · csv) ───
        if (!in_array($checked['ext'], Sheet::SHEET_EXT, true)) {
            $reject('ext_not_allowed', $original);
        }

        $tmpPath = (string) $uploaded['tmp_name'];

        // ─── [4] 민감정보 검출 (PRD F-22, ARCH 5.13) — 본체 업로드와 같은 자리·같은 함수 ───
        // 보관 폴더가 admin-only 라 등급 때문에 막히는 일은 없다. 검출 사실만 남긴다.
        $sensitive      = Sensitive::scan($tmpPath, $checked['ext']);
        $sensitiveFlags = array_keys($sensitive);

        $db = Db::instance();

        /**
         * 폴더 하나를 확보한다 — 있으면 그 id, 없으면 만든다(만든 사실은 감사 `folder.create allow`
         * 로 남고 actor 는 요청자다). 이름은 이 클래스의 상수이고 요청 값이 아니다.
         */
        $ensureFolder = static function (?int $parentId, string $name, int $depth) use ($db, $user): int {
            $row = $db->run(self::SQL_FIND_FOLDER, [':parent' => $parentId, ':name' => $name])->fetch();
            if (is_array($row)) {
                return (int) $row['id'];
            }
            $made = $db->run(self::SQL_MAKE_FOLDER, [
                ':parent'     => $parentId,
                ':name'       => $name,
                ':depth'      => $depth,
                ':created_by' => (int) $user['id'],
            ])->fetch();
            if (!is_array($made)) {
                throw new RuntimeException('가져오기 폴더를 만들지 못했습니다');
            }
            $newId = (int) $made['id'];
            Audit::allow('folder.create', [
                'target_type'  => 'folder',
                'target_id'    => $newId,
                'target_name'  => $name,
                'access_level' => 'admin-only',
                'detail'       => [
                    'granted_by'  => Authz::CODE_ADMIN_ALL,
                    'folder_id'   => $newId,
                    'parent_id'   => $parentId,
                    'depth'       => $depth,
                    'owner_id'    => (int) $user['id'],
                    'target_team' => null,
                ],
            ]);
            return $newId;
        };

        $storedName = Storage::newStoredName($checked['ext']);

        // ─── [6] 트랜잭션: 폴더 확보 → files INSERT → 파일 이동 → (호출자의 일) → 감사 → COMMIT ───
        $moved = false;
        $db->begin();
        try {
            $parentId = $ensureFolder(null, self::FOLDER_PARENT, 1);
            $folderId = $ensureFolder($parentId, self::FOLDER_CHILD, 2);

            $row = $db->run(self::SQL_INSERT_FILE, [
                ':original_name' => $original,
                ':stored_name'   => $storedName,
                ':ext'           => $checked['ext'],
                ':mime'          => $checked['mime'],
                ':size_bytes'    => $checked['size'],
                ':sha256'        => $checked['sha256'],
                ':description'   => '구매 기초정보 가져오기 원본',
                ':uploaded_by'   => (int) $user['id'],
                ':flags'         => '{' . implode(',', $sensitiveFlags) . '}',
                ':folder_id'     => $folderId,
                // F-54 ① (ARCH 5.28) — 가져오기 원본도 같은 규칙으로 저장한다.
                ':scan_status'   => Upload::initialScanStatus(),
            ])->fetch();

            if (!is_array($row)) {
                throw new RuntimeException('가져오기 파일을 저장하지 못했습니다');
            }
            $stored = [
                'file_id'     => (int) $row['id'],
                'public_id'   => (string) $row['public_id'],
                'original'    => $original,
                'ext'         => $checked['ext'],
                'mime'        => $checked['mime'],
                'size'        => $checked['size'],
                'sha256'      => $checked['sha256'],
                'stored_name' => $storedName,
                'folder_id'   => $folderId,
                'sensitive'   => $sensitive,
            ];

            Storage::put($tmpPath, $storedName);
            $moved = true;

            // 호출자가 같은 트랜잭션 안에서 할 일(24단계 창고 대기표 upsert). 돌려준 값은 감사 detail 로.
            $extra = $inTransaction === null ? [] : $inTransaction($stored);
            if (!is_array($extra)) {
                $extra = [];
            }

            Audit::allow($auditAction, [
                'target_type'  => 'file',
                'target_id'    => $stored['file_id'],
                'target_name'  => $original,
                'access_level' => 'admin-only',
                'detail'       => [
                    'file_id'   => $stored['file_id'],
                    'sha256'    => $checked['sha256'],
                    'ext'       => $checked['ext'],
                    'folder_id' => $folderId,
                ] + $extra + ($sensitive === [] ? [] : ['sensitive' => $sensitive]),
            ]);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            if ($moved) {
                try {
                    Storage::removeUploaded($storedName);
                } catch (Throwable $ignored) {
                    error_log('[tbox] request_id=' . TBOX_REQUEST_ID . ' 가져오기 업로드 롤백 중 파일 삭제 실패');
                }
            }
            throw $e;
        }

        return $stored;
    }

    /** 보관 폴더 찾기·만들기·files INSERT — 고정 문장 셋 (요청 값은 바인딩으로만 들어간다) */
    private const SQL_FIND_FOLDER = <<<'SQL'
        SELECT id, depth, access_level, team_id
          FROM folders
         WHERE parent_id IS NOT DISTINCT FROM CAST(:parent AS bigint)
           AND name = :name
        SQL;

    private const SQL_MAKE_FOLDER = <<<'SQL'
        INSERT INTO folders (parent_id, name, depth, access_level, team_id, created_by)
        VALUES (CAST(:parent AS bigint), :name, CAST(:depth AS int), 'admin-only', NULL, :created_by)
        RETURNING id
        SQL;

    private const SQL_INSERT_FILE = <<<'SQL'
        INSERT INTO files
            (original_name, stored_name, ext, mime, size_bytes, sha256,
             description, access_level, team_id, uploaded_by, sensitive_flags, folder_id,
             scan_status)
        VALUES
            (:original_name, :stored_name, :ext, :mime, :size_bytes, :sha256,
             :description, 'admin-only', NULL, :uploaded_by,
             CAST(:flags AS text[]), CAST(:folder_id AS bigint),
             :scan_status)
        RETURNING id, public_id
        SQL;
}
