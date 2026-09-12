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
            'code', 'name', 'vendor', 'brand', 'spec', 'unit', 'pack_qty',
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

        /* ─── 지점·브랜드가 어디서 오는지 먼저 확인한다 (2차 보정) ───
         *
         * 행마다 오류를 내지 않고 **미리보기 자체를 400 으로 끝낸다**: 98행짜리 창고 파일에
         * "지점을 모르겠다" 를 98번 적어 보여 주는 것은 사람에게 쓸모가 없다. 한 번에
         * "기본 지점을 고르세요" 라고 말하는 편이 낫다(매핑이 모자랄 때와 같은 판단).
         */
        if ($kind === 'locations'
            && !array_key_exists('branch', $resolved) && $defaultBranch <= 0) {
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
                'items'     => self::judgeItem($data, $lookup, $seen),
                'vendors'   => self::judgeVendor($data, $lookup, $seen),
                'locations' => self::judgeLocation($data, $lookup, $seen, $defaultBranch),
                'branches'  => self::judgeBranch($data, $lookup, $seen, $defaultBrand),
                default     => ['status' => self::STATUS_ERROR, 'reason' => 'bad_kind', 'data' => $data],
            };

            $counts[$judged['status']]++;
            $judged['row_no'] = $rowNo;
            $out[] = $judged;
        }

        return ['counts' => $counts, 'rows' => $out, 'total' => count($prepared)];
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

        $current = $lookup['item'][$key] ?? null;
        if ($current === null) {
            return [
                'status' => $packDenFlag ? self::STATUS_REVIEW : self::STATUS_INSERT,
                'reason' => $packDenFlag ? 'pack_den_not_one' : null,
                'data'   => $d,
                'values' => $values,
                // 승격하면 **새로 등록**한다 — 대상 행이 없으므로 update 할 곳이 없다 (2차 보정).
                'promote_to' => self::STATUS_INSERT,
            ];
        }

        $diff = self::diff($current, $values);
        // 이름만 다르면 「확인 필요」 — 원본에 이름이 빠졌거나 표기가 흔들린 경우가 많아
        // 자동으로 덮어쓰면 기존 이름이 조용히 사라진다 (시안 ⑯ 19행).
        $status = (array_keys($diff) === ['name']) ? self::STATUS_REVIEW : self::STATUS_UPDATE;
        $reason = $status === self::STATUS_REVIEW ? 'name_differs' : null;
        if ($packDenFlag) {
            $status = self::STATUS_REVIEW;
            $reason = 'pack_den_not_one';   // 포장 수량이 더 급한 확인거리다
        }

        return [
            'status'    => $status,
            'reason'    => $reason,
            'data'      => $d,
            'values'    => $values,
            'diff'      => $diff,
            'target_id' => (int) $current['id'],
            'promote_to' => self::STATUS_UPDATE,
        ];
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
    private static function judgeLocation(array $d, array $lookup, array &$seen, int $defaultBranch = 0): array
    {
        $name = $d['name'] ?? '';
        if ($name === '') {
            return self::fail('required_missing', $d);
        }

        $branchId = null;
        $branchBy = '';

        // ① 파일의 지점 열
        $fromColumn = trim($d['branch'] ?? '');
        if ($fromColumn !== '') {
            $branchId = $lookup['branch'][strtolower($fromColumn)] ?? null;
            if ($branchId === null) {
                return self::fail('branch_not_found', $d);
            }
            $branchBy = 'column';
        }

        // ② 창고명 안에 지점 이름이 들어 있나
        if ($branchId === null) {
            $hits = [];
            foreach ($lookup['branch'] as $branchName => $id) {
                if ($branchName !== '' && strpos(strtolower($name), $branchName) !== false) {
                    $hits[$id] = true;
                }
            }
            if (count($hits) > 1) {
                // 「강남점」과 「강남점2호」가 둘 다 이름에 들어맞는 경우 — 사람이 고른다.
                $out = self::fail('branch_ambiguous', $d);
                $out['status']     = self::STATUS_REVIEW;
                $out['reason']     = 'branch_ambiguous';
                $out['promote_to'] = self::STATUS_INSERT;
                return $out;
            }
            if (count($hits) === 1) {
                $branchId = (int) array_keys($hits)[0];
                $branchBy = 'name';
            }
        }

        // ③ 화면의 기본 지점
        if ($branchId === null && $defaultBranch > 0) {
            $branchId = $defaultBranch;
            $branchBy = 'default';
        }
        if ($branchId === null) {
            // judge() 가 앞에서 막으므로 보통 여기 오지 않는다 — 마지막 그물이다.
            return self::fail('branch_not_found', $d);
        }

        $kindRaw = strtolower($d['kind'] ?? '');
        $kind    = in_array($kindRaw, Master::LOCATION_KINDS, true)
            ? $kindRaw
            : self::LOCATION_KIND_ALIAS[$kindRaw] ?? 'other';

        $values = [
            'branch_id'   => $branchId,
            'kind'        => $kind,
            'name'        => self::cut($name, 80),
            'code'        => self::orNull($d['code'] ?? ''),
            'legacy_name' => self::orNull($d['legacy_name'] ?? ''),
            'is_active'   => self::activeOr($d['active'] ?? '', true),
        ];

        $key = (string) $branchId . '|' . strtolower($name);
        if (isset($seen[$key])) {
            return self::fail('duplicate_in_file', $d);
        }
        $seen[$key] = true;

        $current = $lookup['location'][$key] ?? null;
        if ($current === null) {
            return ['status' => self::STATUS_INSERT, 'reason' => null, 'data' => $d,
                    'values' => $values, 'branch_by' => $branchBy, 'promote_to' => self::STATUS_INSERT];
        }
        return [
            'status'    => self::STATUS_UPDATE,
            'reason'    => null,
            'data'      => $d,
            'values'    => $values,
            'diff'      => self::diff($current, $values),
            'target_id' => (int) $current['id'],
            'branch_by' => $branchBy,
            'promote_to' => self::STATUS_UPDATE,
        ];
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
                return self::fail('brand_not_found', $d);
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
}
